<?php
/**
 * Concurrency Runner
 *
 * Overlap is N independent WP-CLI processes launched at the same instant,
 * each running the existing sequential loadtest. One process cannot overlap
 * (`--burst` is pacing; fire_requests_async() is unused). This is the method
 * that produced real overlap on Pressable — not curl_multi in one process.
 *
 * --count / --duration are per process. --count=1 --concurrency=4 is a
 * four-request stampede. Combined wall-clock throughput is not a Phase 4 RPS.
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

/**
 * MicroChaos Concurrency Runner class
 */
class MicroChaos_Concurrency_Runner {

    /**
     * Clamp a requested process count to the supported range.
     *
     * @param int $requested Requested process count
     * @return int Clamped count, at least 1 and at most MAX_CONCURRENCY
     */
    public static function clamp(int $requested): int {
        if ($requested < 1) {
            return 1;
        }

        if ($requested > MicroChaos_Constants::MAX_CONCURRENCY) {
            return MicroChaos_Constants::MAX_CONCURRENCY;
        }

        return $requested;
    }

    /**
     * Build the assoc args one child process should receive.
     *
     * Forces concurrency=1 so a child cannot fan out again. Points the child
     * at a results JSON file so the parent can merge without reading interleaved
     * stdout. Drops warm-cache: the parent warms once, then the children hit.
     *
     * @param array<string, mixed> $assoc_args Parent CLI args
     * @param int $worker_id 1-based worker id
     * @param string $results_json Absolute path the child should write
     * @return array<string, mixed> Child assoc args
     */
    public static function child_assoc_args(array $assoc_args, int $worker_id, string $results_json): array {
        $child = $assoc_args;
        $child['concurrency'] = 1;
        $child['results-json'] = $results_json;
        $child['worker-id'] = $worker_id;
        unset($child['warm-cache']);

        return $child;
    }

    /**
     * Format assoc args as a shell-safe WP-CLI flag string.
     *
     * @param array<string, mixed> $assoc_args Flags to format
     * @return string Leading-space flag string, or empty
     */
    public static function format_assoc_args(array $assoc_args): string {
        $parts = [];

        foreach ($assoc_args as $key => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            if ($value === true) {
                $parts[] = '--' . $key;
                continue;
            }
            $parts[] = '--' . $key . '=' . escapeshellarg((string) $value);
        }

        return $parts === [] ? '' : ' ' . implode(' ', $parts);
    }

    /**
     * Prefix used to re-invoke WP-CLI as a child.
     *
     * Prefers the current argv[0] so a `wp` wrapper stays a `wp` wrapper.
     * Falls back to `wp` when argv is missing (tests).
     *
     * @return string Shell-escaped command prefix, no trailing space
     */
    public static function command_prefix(): string {
        $invoker = $GLOBALS['argv'][0] ?? 'wp';

        if (self::invoker_is_php_script($invoker)) {
            return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($invoker);
        }

        return escapeshellarg($invoker);
    }

    /**
     * Whether the invoker is a PHP script that must be launched via PHP_BINARY.
     *
     * @param string $invoker Path from argv[0]
     * @return bool
     */
    public static function invoker_is_php_script(string $invoker): bool {
        $base = strtolower(basename($invoker));

        return str_ends_with($base, '.php') || str_ends_with($base, '.phar');
    }

    /**
     * Fan out, wait, merge, and report. Returns the same shape as execute().
     *
     * @param array<string, mixed> $assoc_args Parent CLI args
     * @param array<string, mixed> $config Parsed config
     * @return array{completed: int, count: int, run_by_duration: bool, actual_minutes: float}
     */
    public function run(array $assoc_args, array $config): array {
        $n = self::clamp((int) $config['concurrency']);

        if ($n !== (int) $config['concurrency']) {
            MicroChaos_Log::warning(
                "⚠️ --concurrency={$config['concurrency']} exceeds the cap of "
                . MicroChaos_Constants::MAX_CONCURRENCY
                . "; running {$n} processes."
            );
        }

        MicroChaos_Log::log("🔀 Overlap run: {$n} sequential processes launched together.");
        MicroChaos_Log::log("   Combined Throughput is not a Phase 4 RPS — size on a sequential --cache-bust run.");

        if (!empty($config['warm_cache'])) {
            $this->warm_once($config);
        }

        $tmp = $this->make_temp_dir();
        $handles = $this->launch($assoc_args, $n, $tmp);
        $this->wait($handles);
        $payloads = $this->read_payloads($handles);

        $this->report_merged($payloads, $n);

        $completed = 0;
        $run_by_duration = false;
        foreach ($payloads as $payload) {
            $completed += (int) ($payload['completed'] ?? 0);
            $run_by_duration = $run_by_duration || !empty($payload['run_by_duration']);
        }

        return [
            'completed' => $completed,
            'count' => $config['count'] * $n,
            'run_by_duration' => $run_by_duration,
            'actual_minutes' => $this->merged_minutes($payloads),
        ];
    }

    /**
     * Fire one warm request per endpoint in the parent, before children launch.
     *
     * @param array<string, mixed> $config Parsed config
     */
    private function warm_once(array $config): void {
        MicroChaos_Log::log("🧤 Warming once in the parent, then launching children without --warm-cache.");

        $generator = new MicroChaos_Request_Generator([
            'collect_cache_headers' => !empty($config['collect_cache_headers']),
            'timeout' => $config['timeout'] ?? MicroChaos_Constants::DEFAULT_REQUEST_TIMEOUT,
        ]);
        $endpoints = $this->endpoints_for_warm($generator, $config);
        foreach ($endpoints as $endpoint_item) {
            $generator->fire_request($endpoint_item['url'], null, null, $config['method'] ?? 'GET', null);
            MicroChaos_Log::log("  Warmed {$endpoint_item['slug']}");
        }
    }

    /**
     * Resolve endpoints for the parent warm pass.
     *
     * @param MicroChaos_Request_Generator $generator Request generator
     * @param array<string, mixed> $config Parsed config
     * @return array<int, array{url: string, slug: string}>
     */
    private function endpoints_for_warm(MicroChaos_Request_Generator $generator, array $config): array {
        $orchestrator = new MicroChaos_LoadTest_Orchestrator($config);
        $method = new \ReflectionMethod($orchestrator, 'resolve_endpoints');

        return $method->invoke($orchestrator, $generator, $config);
    }

    /**
     * @return string Absolute temp directory
     */
    private function make_temp_dir(): string {
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/microchaos-' . uniqid('', true);
        if (!@mkdir($tmp, 0700) && !is_dir($tmp)) {
            MicroChaos_Log::error("Could not create temp dir for overlap workers: {$tmp}");
        }

        return $tmp;
    }

    /**
     * Launch N children. Returns handles the waiter understands.
     *
     * @param array<string, mixed> $assoc_args Parent CLI args
     * @param int $n Process count
     * @param string $tmp Temp directory
     * @return array<int, array{proc: resource|false, json: string, id: int, cmd: string}>
     */
    private function launch(array $assoc_args, int $n, string $tmp): array {
        if (!function_exists('proc_open')) {
            MicroChaos_Log::error(
                "proc_open is disabled, so --concurrency cannot launch workers. "
                . "Run the sequential test, or background N `wp microchaos loadtest --count=1` processes yourself."
            );
        }

        $globals = $this->runtime_globals();
        $prefix = self::command_prefix();
        $handles = [];

        for ($i = 1; $i <= $n; $i++) {
            $json = $tmp . '/worker-' . $i . '.json';
            $child = array_merge($globals, self::child_assoc_args($assoc_args, $i, $json));
            $cmd = $prefix . ' microchaos loadtest' . self::format_assoc_args($child);
            $out = $tmp . '/worker-' . $i . '.out';
            $err = $tmp . '/worker-' . $i . '.err';

            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $out, 'w'],
                2 => ['file', $err, 'w'],
            ];

            $proc = proc_open($cmd, $descriptors, $pipes, null, null);
            $handles[] = [
                'proc' => $proc,
                'json' => $json,
                'id' => $i,
                'cmd' => $cmd,
                'err' => $err,
            ];
        }

        return $handles;
    }

    /**
     * WP-CLI global flags the children must inherit or they hit the wrong site.
     *
     * @return array<string, mixed>
     */
    private function runtime_globals(): array {
        $globals = [];

        if (!class_exists('WP_CLI')) {
            return $globals;
        }

        $config = \WP_CLI::get_runner()->config;
        foreach (['path', 'url', 'user'] as $key) {
            if (!empty($config[$key])) {
                $globals[$key] = $config[$key];
            }
        }
        if (!empty($config['allow-root'])) {
            $globals['allow-root'] = true;
        }

        return $globals;
    }

    /**
     * Wait for every child, then terminate stragglers.
     *
     * @param array<int, array{proc: resource|false, json: string, id: int}> $handles
     */
    private function wait(array $handles): void {
        $deadline = time() + MicroChaos_Constants::DEFAULT_PARALLEL_TIMEOUT;

        while (time() < $deadline) {
            $alive = 0;
            foreach ($handles as $handle) {
                if ($handle['proc'] === false) {
                    continue;
                }
                $status = proc_get_status($handle['proc']);
                if (!empty($status['running'])) {
                    $alive++;
                }
            }
            if ($alive === 0) {
                break;
            }
            usleep(100000);
        }

        foreach ($handles as $handle) {
            if ($handle['proc'] === false) {
                continue;
            }
            $status = proc_get_status($handle['proc']);
            if (!empty($status['running'])) {
                proc_terminate($handle['proc']);
                MicroChaos_Log::warning("⚠️ Worker {$handle['id']} exceeded the overlap timeout and was terminated.");
            }
            proc_close($handle['proc']);
        }
    }

    /**
     * @param array<int, array{json: string, id: int, err?: string}> $handles
     * @return array<int, array<string, mixed>>
     */
    private function read_payloads(array $handles): array {
        $payloads = [];

        foreach ($handles as $handle) {
            if (!is_readable($handle['json'])) {
                $tail = '';
                if (!empty($handle['err']) && is_readable($handle['err'])) {
                    $tail = trim((string) file_get_contents($handle['err']));
                    if (strlen($tail) > 300) {
                        $tail = substr($tail, -300);
                    }
                }
                MicroChaos_Log::warning(
                    "⚠️ Worker {$handle['id']} produced no results file."
                    . ($tail !== '' ? " stderr: {$tail}" : '')
                );
                continue;
            }

            $decoded = json_decode((string) file_get_contents($handle['json']), true);
            if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) {
                MicroChaos_Log::warning("⚠️ Worker {$handle['id']} wrote unreadable results JSON.");
                continue;
            }

            $payloads[] = $decoded;
        }

        if ($payloads === []) {
            MicroChaos_Log::error("No overlap workers returned results. The sequential path is unchanged; re-run without --concurrency.");
        }

        return $payloads;
    }

    /**
     * Merge worker results and print one summary. No serial-ceiling projection:
     * those numbers describe one-at-a-time cost and are a lie under overlap.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @param int $n Process count
     */
    private function report_merged(array $payloads, int $n): void {
        $engine = new MicroChaos_Reporting_Engine();
        $starts = [];
        $ends = [];
        $completed = 0;

        foreach ($payloads as $payload) {
            $engine->add_results($payload['results']);
            $completed += (int) ($payload['completed'] ?? count($payload['results']));
            if (isset($payload['test_start_timestamp'])) {
                $starts[] = (float) $payload['test_start_timestamp'];
            }
            if (isset($payload['test_end_timestamp'])) {
                $ends[] = (float) $payload['test_end_timestamp'];
            }
        }

        $start = $starts === [] ? microtime(true) : min($starts);
        $end = $ends === [] ? $start : max($ends);
        $duration = max(0.0, $end - $start);
        $rps = $duration > 0 ? round($completed / $duration, 2) : 0.0;

        $metrics = [
            'started_at' => date('Y-m-d H:i:s', (int) $start),
            'started_at_iso' => date('c', (int) $start),
            'ended_at' => date('Y-m-d H:i:s', (int) $end),
            'ended_at_iso' => date('c', (int) $end),
            'duration_seconds' => round($duration, 2),
            'duration_formatted' => $this->format_duration($duration),
            'total_requests' => $completed,
            'throughput_rps' => $rps,
        ];

        MicroChaos_Log::log("📊 Overlap summary ({$n} processes)");
        MicroChaos_Log::log("   Do not size workers from this Throughput figure.");
        $engine->report_summary(null, null, null, $metrics);

        $this->report_merged_cache($payloads, $completed);
    }

    /**
     * Rebuild the cache-header tally from workers so a stampede still shows
     * HIT vs regen instead of losing it behind --results-json.
     *
     * @param array<int, array<string, mixed>> $payloads
     * @param int $completed Combined request count
     */
    private function report_merged_cache(array $payloads, int $completed): void {
        $analyzer = new MicroChaos_Cache_Analyzer();
        $has_cache = false;

        foreach ($payloads as $payload) {
            if (empty($payload['cache_headers']) || !is_array($payload['cache_headers'])) {
                continue;
            }
            foreach ($payload['cache_headers'] as $header => $values) {
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $value => $header_count) {
                    $has_cache = true;
                    $analyzer->collect_headers([$header => (string) $value], (int) $header_count);
                }
            }
        }

        if ($has_cache) {
            $analyzer->report_summary($completed);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     */
    private function merged_minutes(array $payloads): float {
        $starts = [];
        $ends = [];
        foreach ($payloads as $payload) {
            if (isset($payload['test_start_timestamp'])) {
                $starts[] = (float) $payload['test_start_timestamp'];
            }
            if (isset($payload['test_end_timestamp'])) {
                $ends[] = (float) $payload['test_end_timestamp'];
            }
        }
        if ($starts === [] || $ends === []) {
            return 0.0;
        }

        return round((max($ends) - min($starts)) / MicroChaos_Constants::SECONDS_PER_MINUTE, 1);
    }

    /**
     * @param float $seconds Duration in seconds
     */
    private function format_duration(float $seconds): string {
        $minutes = floor($seconds / 60);
        $secs = (int) round($seconds % 60);

        if ($minutes > 0) {
            return "{$minutes}m {$secs}s";
        }

        return "{$secs}s";
    }
}
