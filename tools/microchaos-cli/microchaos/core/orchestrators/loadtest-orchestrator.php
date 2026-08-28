<?php
/**
 * LoadTest Orchestrator
 *
 * Coordinates load test execution: component initialization, test loop,
 * metrics collection, and result reporting.
 *
 * Extracted from commands.php in Session 2.1 to improve testability
 * and separate WP-CLI command handling from test orchestration.
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

/**
 * MicroChaos LoadTest Orchestrator
 */
class MicroChaos_LoadTest_Orchestrator {

    /**
     * Test configuration
     *
     * @var array
     */
    private array $config;

    /**
     * Constructor
     *
     * @param array $config Test configuration from CLI options
     */
    public function __construct(array $config) {
        $this->config = $this->normalize_config($config);
    }

    /**
     * Normalize config with defaults
     *
     * @param array $config Raw config
     * @return array Normalized config with defaults
     */
    private function normalize_config(array $config): array {
        return array_merge([
            'endpoint' => null,
            'endpoints' => null,
            'count' => 100,
            'duration' => null,
            'burst' => 10,
            'delay' => 2,
            'method' => 'GET',
            'body' => null,
            'user_agent' => null,
            'warm_cache' => false,
            'cache_bust' => false,
            'flush_between' => false,
            'rampup' => false,
            'auth_user' => null,
            'multi_auth' => null,
            'custom_cookies' => null,
            'custom_headers' => null,
            'rotation_mode' => 'serial',
            'timeout' => MicroChaos_Constants::DEFAULT_REQUEST_TIMEOUT,
            'resource_logging' => false,
            'resource_trends' => false,
            'collect_cache_headers' => false,
            'auto_thresholds' => false,
            'threshold_profile' => 'default',
            'use_thresholds' => null,
            'monitoring_integration' => false,
            'monitoring_test_id' => null,
            'save_baseline' => null,
            'compare_baseline' => null,
            'log_path' => null,
            'concurrency' => MicroChaos_Constants::DEFAULT_CONCURRENCY,
            'results_json' => null,
            'worker_id' => null,
        ], $config);
    }

    /**
     * Execute the load test
     *
     * @return array Result data for final message
     */
    public function execute(): array {
        $config = $this->config;

        // Default endpoint if none specified
        if (!$config['endpoint'] && !$config['endpoints']) {
            $config['endpoint'] = 'home';
        }

        // Load custom thresholds if specified
        if ($config['use_thresholds']) {
            $loaded = MicroChaos_Thresholds::load_thresholds($config['use_thresholds']);
            if ($loaded) {
                MicroChaos_Log::log("🎯 Using custom thresholds from profile: {$config['use_thresholds']}");
            } else {
                MicroChaos_Log::warning("⚠️ Could not load thresholds profile: {$config['use_thresholds']}. Using defaults.");
            }
        }

        // Initialize components
        $request_generator = new MicroChaos_Request_Generator([
            'collect_cache_headers' => $config['collect_cache_headers'],
            'timeout' => $config['timeout'],
        ]);

        $resource_monitor = new MicroChaos_Resource_Monitor([
            'track_trends' => $config['resource_trends']
        ]);
        $cache_analyzer = new MicroChaos_Cache_Analyzer();
        $reporting_engine = new MicroChaos_Reporting_Engine();

        // Initialize integration logger
        $integration_logger = new MicroChaos_Integration_Logger([
            'enabled' => $config['monitoring_integration'],
            'test_id' => $config['monitoring_test_id']
        ]);

        // Resolve endpoints
        $endpoint_list = $this->resolve_endpoints($request_generator, $config);

        // Process body if it's a file reference
        $body = $config['body'];
        if ($body && strpos($body, 'file:') === 0) {
            $file_path = substr($body, 5);
            if (file_exists($file_path)) {
                $body = file_get_contents($file_path);
            } else {
                MicroChaos_Log::error("Body file not found: $file_path");
            }
        }

        // Set up authentication and cookies
        $cookies = $this->setup_authentication($config);

        // Process custom headers
        if ($config['custom_headers']) {
            $headers = [];
            $header_pairs = array_map('trim', explode(',', $config['custom_headers']));

            foreach ($header_pairs as $pair) {
                list($name, $value) = array_map('trim', explode('=', $pair, 2));
                $headers[$name] = $value;
            }

            $request_generator->set_custom_headers($headers);
            MicroChaos_Log::log("📝 Added " . count($header_pairs) . " custom " .
                          (count($header_pairs) === 1 ? "header" : "headers"));
        }

        // Set custom User-Agent if specified
        if ($config['user_agent']) {
            $request_generator->set_user_agent($config['user_agent']);
            MicroChaos_Log::log("🤖 Using custom User-Agent: {$config['user_agent']}");
        }

        // Log test start
        $this->log_test_start($config, $endpoint_list, $integration_logger);

        // Announce the measurement mode: the modes answer different questions and
        // their numbers are not interchangeable, so the log has to say which ran.
        // Fan-out children are skipped, because the parent labels the ensemble and
        // a child announcing itself as a sizing run would contradict it.
        $mode = MicroChaos_Test_Mode::resolve($config);
        if (empty($config['worker_id'])) {
            MicroChaos_Test_Mode::announce($mode);
        }

        // Warm cache if specified
        if ($config['warm_cache']) {
            if ($config['cache_bust']) {
                MicroChaos_Log::warning("--warm-cache does nothing alongside --cache-bust: the test requests use unique URLs, so nothing warmed here is ever reused.");
            }
            MicroChaos_Log::log("🧤 Warming cache...");
            foreach ($endpoint_list as $endpoint_item) {
                $request_generator->fire_request(
                    $endpoint_item['url'],
                    $config['log_path'],
                    $cookies,
                    $config['method'],
                    $body
                );
                MicroChaos_Log::log("  Warmed {$endpoint_item['slug']}");
            }
        }

        // Execute the main test loop
        $loop_result = $this->run_test_loop(
            $config,
            $endpoint_list,
            $request_generator,
            $resource_monitor,
            $cache_analyzer,
            $reporting_engine,
            $integration_logger,
            $cookies,
            $body
        );

        // Build execution metrics
        $execution_metrics = $this->build_execution_metrics(
            $loop_result['test_start_timestamp'],
            $loop_result['test_end_timestamp'],
            $loop_result['completed'],
            $reporting_engine->get_results()
        );
        $execution_metrics['mode'] = $mode;

        // Handle baseline comparison
        $perf_baseline = $config['compare_baseline']
            ? $reporting_engine->get_baseline($config['compare_baseline'])
            : null;
        $resource_baseline = ($config['compare_baseline'] && $config['resource_logging'])
            ? $resource_monitor->get_baseline($config['compare_baseline'])
            : null;

        // Generate summaries
        $summary = $reporting_engine->generate_summary();
        $resource_summary = $config['resource_logging']
            ? $resource_monitor->generate_summary()
            : null;

        // Auto-calibrate thresholds if requested
        $use_thresholds = $config['use_thresholds'];
        if ($config['auto_thresholds']) {
            $use_thresholds = $this->calibrate_thresholds(
                $summary,
                $resource_summary,
                $config['threshold_profile']
            );
        }

        if (!empty($config['results_json'])) {
            $this->write_results_json($config['results_json'], $reporting_engine, $loop_result, $cache_analyzer);
        }

        // A worker writing results-json is collected by the parent; printing
        // a second summary here would interleave with its siblings.
        if (empty($config['results_json'])) {
            $reporting_engine->report_summary($perf_baseline, null, $use_thresholds, $execution_metrics);
        }

        if (empty($config['results_json'])) {
            $this->report_timeout_censoring($reporting_engine, $request_generator->get_timeout());
        }

        if ($config['resource_logging'] && empty($config['results_json'])) {
            $resource_monitor->report_summary($resource_baseline, null, $use_thresholds);

            if ($config['resource_trends']) {
                $resource_monitor->report_trends();
            }
        }

        // Save baseline if specified
        if ($config['save_baseline'] && empty($config['results_json'])) {
            $reporting_engine->save_baseline($config['save_baseline']);
            if ($config['resource_logging']) {
                $resource_monitor->save_baseline($config['save_baseline']);
            }
            MicroChaos_Log::success("✅ Baseline '{$config['save_baseline']}' saved.");
        }

        // Report cache headers if enabled
        if ($config['collect_cache_headers'] && empty($config['results_json'])) {
            $cache_analyzer->report_summary($reporting_engine->get_request_count());
        }

        // Log test completion to integration logger
        if ($config['monitoring_integration']) {
            $final_summary = $reporting_engine->generate_summary();

            if ($config['collect_cache_headers']) {
                $cache_report = $cache_analyzer->generate_report($reporting_engine->get_request_count());
                $final_summary['cache'] = $cache_report;
            }

            $integration_logger->log_test_complete($final_summary, $resource_summary, $execution_metrics);
            MicroChaos_Log::log("🔌 Monitoring data logged to PHP error log (test ID: {$integration_logger->test_id})");
        }

        // Return result data for final success message
        return [
            'completed' => $loop_result['completed'],
            'count' => $config['count'],
            'run_by_duration' => $loop_result['run_by_duration'],
            'actual_minutes' => $loop_result['actual_minutes'],
        ];
    }

    /**
     * Write this process's raw results for a parent overlap run to merge.
     *
     * @param string $path Absolute path
     * @param MicroChaos_Reporting_Engine $reporting_engine
     * @param array<string, mixed> $loop_result
     * @param MicroChaos_Cache_Analyzer $cache_analyzer
     */
    private function write_results_json(string $path, MicroChaos_Reporting_Engine $reporting_engine, array $loop_result, MicroChaos_Cache_Analyzer $cache_analyzer): void {
        $payload = [
            'worker_id' => $this->config['worker_id'],
            'mode' => MicroChaos_Test_Mode::resolve($this->config),
            'results' => $reporting_engine->get_results(),
            'completed' => $loop_result['completed'],
            'run_by_duration' => $loop_result['run_by_duration'],
            'test_start_timestamp' => $loop_result['test_start_timestamp'],
            'test_end_timestamp' => $loop_result['test_end_timestamp'],
            'cache_headers' => $cache_analyzer->get_cache_headers(),
        ];

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            MicroChaos_Log::error("Could not write results JSON directory: {$dir}");
        }

        $json = function_exists('wp_json_encode')
            ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES)
            : json_encode($payload, JSON_UNESCAPED_SLASHES);

        $written = @file_put_contents($path, $json);
        if ($written === false) {
            MicroChaos_Log::error("Could not write results JSON: {$path}");
        }
    }

    /**
     * Warn that the timing distribution is right-censored, if any request timed out
     *
     * A timed-out request is not a slow measurement, it is an absent one: its
     * real duration is unknown and the recorded time is just the cutoff. That
     * makes the reported average and maximum look *better* as the site gets
     * worse, because the slowest responses leave the timing set and reappear as
     * errors. Worth saying out loud, since the summary otherwise presents
     * timings that are quietly conditional on the requests that finished.
     *
     * @param MicroChaos_Reporting_Engine $reporting_engine Completed results
     * @param int $timeout Configured timeout in seconds
     */
    private function report_timeout_censoring(MicroChaos_Reporting_Engine $reporting_engine, int $timeout): void {
        $timeouts = count(array_filter(
            $reporting_engine->get_results(),
            static fn($result) => MicroChaos_Request_Generator::STATUS_TIMEOUT === $result['code']
        ));

        if (0 === $timeouts) {
            return;
        }

        MicroChaos_Log::warning("⏱ {$timeouts} request(s) hit the {$timeout}s timeout and were abandoned.");
        MicroChaos_Log::warning("   Their real response times are unknown, so the timings above are the");
        MicroChaos_Log::warning("   requests that finished — average and max both read low. Re-run with a");
        MicroChaos_Log::warning("   higher --timeout to see the tail before drawing conclusions from it.");
    }

    /**
     * Resolve endpoints from config
     *
     * @param MicroChaos_Request_Generator $request_generator
     * @param array $config
     * @return array Endpoint list
     */
    private function resolve_endpoints(MicroChaos_Request_Generator $request_generator, array $config): array {
        $endpoint_list = [];

        if ($config['endpoints']) {
            $endpoint_items = array_map('trim', explode(',', $config['endpoints']));
            foreach ($endpoint_items as $item) {
                $url = $request_generator->resolve_endpoint($item);
                if ($url) {
                    $endpoint_list[] = [
                        'slug' => $item,
                        'url' => $url
                    ];
                } else {
                    MicroChaos_Log::warning("Invalid endpoint: $item. Skipping.");
                }
            }

            if (empty($endpoint_list)) {
                MicroChaos_Log::error("No valid endpoints to test.");
            }
        } elseif ($config['endpoint']) {
            $url = $request_generator->resolve_endpoint($config['endpoint']);
            if (!$url) {
                MicroChaos_Log::error("Invalid endpoint. Use 'home', 'shop', 'cart', 'checkout', or 'custom:/your/path'.");
            }
            $endpoint_list[] = [
                'slug' => $config['endpoint'],
                'url' => $url
            ];
        }

        return $endpoint_list;
    }

    /**
     * Set up authentication and process cookies
     *
     * @param array $config
     * @return array|null Cookies for requests
     */
    private function setup_authentication(array $config): ?array {
        $cookies = null;

        if ($config['multi_auth']) {
            $emails = array_map('trim', explode(',', $config['multi_auth']));
            $cookies = MicroChaos_Authentication_Manager::authenticate_users($emails);
            if (empty($cookies)) {
                MicroChaos_Log::warning("No valid multi-auth sessions. Continuing without authentication.");
            }
        } elseif ($config['auth_user']) {
            $cookies = MicroChaos_Authentication_Manager::authenticate_user($config['auth_user']);
            if ($cookies === null) {
                MicroChaos_Log::error("User with email {$config['auth_user']} not found.");
            }
        }

        // Process custom cookies if specified
        if ($config['custom_cookies']) {
            $custom_cookie_jar = [];
            $cookie_pairs = array_map('trim', explode(',', $config['custom_cookies']));

            foreach ($cookie_pairs as $pair) {
                list($name, $value) = array_map('trim', explode('=', $pair, 2));
                $cookie = new \WP_Http_Cookie([
                    'name' => $name,
                    'value' => $value,
                ]);
                $custom_cookie_jar[] = $cookie;
            }

            // Merge with auth cookies if present
            if ($cookies) {
                if (is_array($cookies) && isset($cookies[0]) && is_array($cookies[0])) {
                    // Handle multi-auth case
                    $cookies[0] = array_merge($cookies[0], $custom_cookie_jar);
                } else {
                    $cookies = array_merge($cookies, $custom_cookie_jar);
                }
            } else {
                $cookies = $custom_cookie_jar;
            }

            MicroChaos_Log::log("🍪 Added " . count($cookie_pairs) . " custom " .
                          (count($cookie_pairs) === 1 ? "cookie" : "cookies"));
        }

        return $cookies;
    }

    /**
     * Append a unique query parameter so the request cannot be served from cache
     *
     * Uses a UUID rather than a counter because the page and edge caches outlive
     * the process: a sequence restarting at 1 on every run would hit entries the
     * previous run created.
     *
     * @param string $url URL to make unique
     * @return string URL with a cache-busting parameter appended
     */
    private function apply_cache_buster(string $url): string {
        return add_query_arg('mc_cb', wp_generate_uuid4(), $url);
    }

    /**
     * Log test start information
     *
     * @param array $config
     * @param array $endpoint_list
     * @param MicroChaos_Integration_Logger $integration_logger
     */
    private function log_test_start(array $config, array $endpoint_list, MicroChaos_Integration_Logger $integration_logger): void {
        MicroChaos_Log::log("🚀 MicroChaos Load Test Started");

        if (count($endpoint_list) === 1) {
            MicroChaos_Log::log("-> URL: {$endpoint_list[0]['url']}");
        } else {
            MicroChaos_Log::log("-> URLs: " . count($endpoint_list) . " endpoints (" .
                          implode(', ', array_column($endpoint_list, 'slug')) . ") - Rotation mode: {$config['rotation_mode']}");
        }

        MicroChaos_Log::log("-> Method: {$config['method']}");

        if ($config['body']) {
            $body_preview = strlen($config['body']) > 50
                ? substr($config['body'], 0, 47) . '...'
                : $config['body'];
            MicroChaos_Log::log("-> Body: $body_preview");
        }

        if ($config['duration']) {
            $duration_word = $config['duration'] == 1 ? "minute" : "minutes";
            MicroChaos_Log::log("-> Duration: {$config['duration']} $duration_word | Burst: {$config['burst']} | Delay: {$config['delay']}s");
        } else {
            MicroChaos_Log::log("-> Total: {$config['count']} | Burst: {$config['burst']} | Delay: {$config['delay']}s");
        }

        if ($config['collect_cache_headers']) {
            MicroChaos_Log::log("-> Cache header tracking enabled");
        }

        if ($config['monitoring_integration']) {
            MicroChaos_Log::log("-> 🔌 Monitoring integration enabled (test ID: {$integration_logger->test_id})");

            $log_config = [
                'endpoint' => $config['endpoint'],
                'endpoints' => $config['endpoints'],
                'count' => $config['count'],
                'duration' => $config['duration'],
                'burst' => $config['burst'],
                'delay' => $config['delay'],
                'method' => $config['method'],
                'is_auth' => ($config['auth_user'] !== null || $config['multi_auth'] !== null),
                'cache_headers' => $config['collect_cache_headers'],
                'resource_logging' => $config['resource_logging'],
                'test_type' => $config['duration'] ? 'duration' : 'count'
            ];

            $integration_logger->log_test_start($log_config);
        }
    }

    /**
     * Run the main test loop
     *
     * @param array $config
     * @param array $endpoint_list
     * @param MicroChaos_Request_Generator $request_generator
     * @param MicroChaos_Resource_Monitor $resource_monitor
     * @param MicroChaos_Cache_Analyzer $cache_analyzer
     * @param MicroChaos_Reporting_Engine $reporting_engine
     * @param MicroChaos_Integration_Logger $integration_logger
     * @param array|null $cookies
     * @param string|null $body
     * @return array Loop result data
     */
    private function run_test_loop(
        array $config,
        array $endpoint_list,
        MicroChaos_Request_Generator $request_generator,
        MicroChaos_Resource_Monitor $resource_monitor,
        MicroChaos_Cache_Analyzer $cache_analyzer,
        MicroChaos_Reporting_Engine $reporting_engine,
        MicroChaos_Integration_Logger $integration_logger,
        ?array $cookies,
        ?string $body
    ): array {
        $completed = 0;
        $current_ramp = $config['rampup'] ? 1 : $config['burst'];
        $endpoint_index = 0;

        // Capture precise test start timestamp
        $test_start_timestamp = microtime(true);

        // Set up duration-based testing
        $start_time = time();
        $end_time = $config['duration'] ? $start_time + ($config['duration'] * 60) : null;
        $run_by_duration = ($config['duration'] !== null);

        while (true) {
            // Check exit condition
            if ($run_by_duration) {
                if (time() >= $end_time) {
                    break;
                }
            } else {
                if ($completed >= $config['count']) {
                    break;
                }
            }

            // Monitor resources if enabled
            if ($config['resource_logging']) {
                $resource_data = $resource_monitor->log_resource_utilization();

                if ($config['monitoring_integration']) {
                    $integration_logger->log_resource_snapshot($resource_data);
                }
            }

            // Calculate burst size
            if ($config['rampup']) {
                $current_ramp = min($current_ramp + 1, $config['burst']);
            }

            $current_burst = $run_by_duration
                ? $current_ramp
                : min($current_ramp, $config['burst'], $config['count'] - $completed);

            MicroChaos_Log::log("⚡ Burst of $current_burst requests");

            // Flush cache if specified
            if ($config['flush_between']) {
                MicroChaos_Log::log("♻️ Flushing cache before burst...");
                wp_cache_flush();
            }

            // Select URLs for this burst
            $burst_urls = [];
            for ($i = 0; $i < $current_burst; $i++) {
                if ($config['rotation_mode'] === 'random') {
                    $selected = $endpoint_list[array_rand($endpoint_list)];
                } else {
                    $selected = $endpoint_list[$endpoint_index % count($endpoint_list)];
                    $endpoint_index++;
                }
                $burst_urls[] = $config['cache_bust']
                    ? $this->apply_cache_buster($selected['url'])
                    : $selected['url'];
            }

            // Fire requests
            $results = [];
            foreach ($burst_urls as $url) {
                $result = $request_generator->fire_request(
                    $url,
                    $config['log_path'],
                    $cookies,
                    $config['method'],
                    $body
                );
                $results[] = $result;
            }

            // Add results to reporting engine
            $reporting_engine->add_results($results);

            // Log results to integration logger
            if ($config['monitoring_integration']) {
                foreach ($results as $result) {
                    $integration_logger->log_request($result);
                }
            }

            // Process cache headers. The generator has already tallied this burst
            // per header value, so forward the tally — collecting each distinct
            // value once would flatten the distribution to one hit per value.
            if ($config['collect_cache_headers']) {
                $cache_headers = $request_generator->get_cache_headers();
                foreach ($cache_headers as $header => $values) {
                    foreach ($values as $value => $header_count) {
                        $cache_analyzer->collect_headers([$header => $value], $header_count);
                    }
                }
                $request_generator->reset_cache_headers();
            }

            // Log burst completion
            if ($config['monitoring_integration']) {
                $burst_summary = [
                    'burst_size' => $current_burst,
                    'results' => $results,
                    'endpoints' => $burst_urls,
                    'completed_total' => $completed
                ];
                $integration_logger->log_burst_complete($completed / $current_burst, $current_burst, $burst_summary);
            }

            $completed += $current_burst;

            // Display elapsed time for duration mode
            if ($run_by_duration) {
                $elapsed_seconds = time() - $start_time;
                $elapsed_minutes = floor($elapsed_seconds / 60);
                $remaining_seconds = $elapsed_seconds % 60;
                $time_display = $elapsed_minutes . "m " . $remaining_seconds . "s";
                $percentage = min(round(($elapsed_seconds / ($config['duration'] * 60)) * 100), 100);
                MicroChaos_Log::log("⏲ Time elapsed: $time_display ($percentage% complete, $completed requests sent)");
            }

            // Delay between bursts
            $should_delay = $run_by_duration ? true : ($completed < $config['count']);

            if ($should_delay) {
                $random_delay = rand($config['delay'] * 50, $config['delay'] * 150) / 100;
                MicroChaos_Log::log("⏳ Sleeping for {$random_delay}s (randomized delay)");
                sleep((int)$random_delay);
            }
        }

        // Capture end timestamp
        $test_end_timestamp = microtime(true);

        // Calculate actual duration for result
        $actual_seconds = time() - $start_time;
        $actual_minutes = round($actual_seconds / 60, 1);

        return [
            'completed' => $completed,
            'test_start_timestamp' => $test_start_timestamp,
            'test_end_timestamp' => $test_end_timestamp,
            'run_by_duration' => $run_by_duration,
            'actual_minutes' => $actual_minutes,
        ];
    }

    /**
     * Build execution metrics
     *
     * Two throughput figures, because they answer different questions and only
     * one of them is a property of the site.
     *
     * Wall-clock RPS counts the --delay sleeps between bursts as though they
     * were work, so it moves when the operator changes pacing flags and two
     * runs against an identical site disagree. It is still the right figure to
     * pair with a dashboard CPU reading, which covers the same window.
     *
     * The serial ceiling divides by time actually spent waiting on responses.
     * That is what one request-at-a-time costs this site, independent of how
     * the run was paced, and it is the honest basis for projecting forward.
     *
     * @param float $start_timestamp Wall-clock start
     * @param float $end_timestamp Wall-clock end
     * @param int $completed Requests completed
     * @param array<int, array<string, mixed>> $results Result rows, for their recorded response times
     * @return array Execution metrics
     */
    private function build_execution_metrics(float $start_timestamp, float $end_timestamp, int $completed, array $results = []): array {
        $duration = $end_timestamp - $start_timestamp;
        $rps = $duration > 0 ? round($completed / $duration, 2) : 0;

        $response_time_total = (float) array_sum(array_column($results, 'time'));
        $serial_ceiling_rps = $response_time_total > 0
            ? round($completed / $response_time_total, 2)
            : 0.0;

        // How much of the run was pacing rather than requests. Makes the gap
        // between the two throughput figures self-explanatory. Clamped at zero
        // because rounding can put recorded response time marginally above the
        // wall clock, and a negative share would be nonsense to print.
        $pacing_share_pct = $duration > 0
            ? max(0.0, round((($duration - $response_time_total) / $duration) * 100, 1))
            : 0.0;

        return [
            'started_at' => date('Y-m-d H:i:s', (int)$start_timestamp),
            'started_at_iso' => date('c', (int)$start_timestamp),
            'ended_at' => date('Y-m-d H:i:s', (int)$end_timestamp),
            'ended_at_iso' => date('c', (int)$end_timestamp),
            'duration_seconds' => round($duration, 2),
            'duration_formatted' => $this->format_duration($duration),
            'total_requests' => $completed,
            'throughput_rps' => $rps,
            'serial_ceiling_rps' => $serial_ceiling_rps,
            'response_time_total' => round($response_time_total, 2),
            'pacing_share_pct' => $pacing_share_pct,
            // Projected from the serial ceiling, not wall-clock throughput, so
            // the figure describes the site rather than the chosen --delay.
            'capacity' => [
                'per_hour' => (int)($serial_ceiling_rps * MicroChaos_Constants::SECONDS_PER_HOUR),
                'per_day' => (int)($serial_ceiling_rps * MicroChaos_Constants::SECONDS_PER_DAY),
                'per_month' => (int)($serial_ceiling_rps * MicroChaos_Constants::SECONDS_PER_DAY * 30),
            ],
        ];
    }

    /**
     * Calibrate thresholds based on test results
     *
     * @param array $summary Performance summary
     * @param array|null $resource_summary Resource summary
     * @param string $profile Profile name
     * @return string Profile name to use
     */
    private function calibrate_thresholds(array $summary, ?array $resource_summary, string $profile): string {
        MicroChaos_Log::log("🔍 Auto-calibrating thresholds based on this test run...");

        $calibration_data = $summary;
        if ($resource_summary) {
            $calibration_data['memory'] = $resource_summary['memory'];
            $calibration_data['peak_memory'] = $resource_summary['peak_memory'];
        }

        $thresholds = MicroChaos_Thresholds::calibrate_thresholds($calibration_data, $profile);

        MicroChaos_Log::log("✅ Custom thresholds calibrated and saved as profile: {$profile}");
        MicroChaos_Log::log("   Response time: Good <= {$thresholds['response_time']['good']}s | Warning <= {$thresholds['response_time']['warn']}s | Critical > {$thresholds['response_time']['critical']}s");

        if (isset($thresholds['memory_usage'])) {
            MicroChaos_Log::log("   Memory usage: Good <= {$thresholds['memory_usage']['good']}% | Warning <= {$thresholds['memory_usage']['warn']}% | Critical > {$thresholds['memory_usage']['critical']}%");
        }

        MicroChaos_Log::log("   Error rate: Good <= {$thresholds['error_rate']['good']}% | Warning <= {$thresholds['error_rate']['warn']}% | Critical > {$thresholds['error_rate']['critical']}%");

        return $profile;
    }

    /**
     * Format duration in human-readable format
     *
     * @param float $seconds Duration in seconds
     * @return string Formatted duration (e.g., "5m 15s" or "45s")
     */
    private function format_duration(float $seconds): string {
        $minutes = floor($seconds / 60);
        $secs = round($seconds % 60);

        if ($minutes > 0) {
            return "{$minutes}m {$secs}s";
        }
        return "{$secs}s";
    }
}
