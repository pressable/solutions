<?php
/**
 * Test Mode Descriptor
 *
 * A loadtest run answers one of three different questions depending on the
 * flags it was given, and the numbers from one are not usable as the answer to
 * another. The summary is the artifact that gets pasted into an audit, so it
 * has to say which question it answered rather than leaving the operator to
 * remember which flags they typed.
 *
 * Three named modes, plus the unqualified case:
 *
 *   origin-cost         sequential + --cache-bust. The Phase 4 sizing input.
 *   cache-effectiveness sequential + --warm-cache. Compare against origin cost.
 *   overlap             --concurrency=N. Behaviour when requests share workers.
 *   uncontrolled        neither cache flag. A mix, and not an answer to either.
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

/**
 * MicroChaos Test Mode class
 */
class MicroChaos_Test_Mode {

    /**
     * Resolve the mode descriptor for a run.
     *
     * @param array<string, mixed> $config Parsed config
     * @return array{id: string, label: string, measures: string, sizing: string, sizing_ok: bool}
     */
    public static function resolve(array $config): array {
        $concurrency = (int) ($config['concurrency'] ?? 1);

        if ($concurrency > 1) {
            return [
                'id' => 'overlap',
                'label' => "Overlap ({$concurrency} processes launched together)",
                'measures' => 'How the site behaves when requests actually share workers.',
                'sizing' => 'Not a sizing input. Combined Throughput mixes queueing and '
                    . "{$concurrency} load generators into one figure.",
                'sizing_ok' => false,
            ];
        }

        $cache_bust = !empty($config['cache_bust']);
        $warm_cache = !empty($config['warm_cache']);

        // --cache-bust wins the label when both are set. The orchestrator already
        // warns that warming is pointless alongside it, and the run does measure
        // origin cost, so calling it anything else would be the bigger lie.
        if ($cache_bust) {
            return [
                'id' => 'origin-cost',
                'label' => 'Origin cost (sequential, cache-busted)',
                'measures' => 'What one uncached request costs the origin, one request at a time.',
                'sizing' => 'Throughput from this run is the Phase 4 sizing input.',
                'sizing_ok' => true,
            ];
        }

        if ($warm_cache) {
            return [
                'id' => 'cache-effectiveness',
                'label' => 'Cache effectiveness (sequential, warmed)',
                'measures' => 'What the cache serves once the URL is warm.',
                'sizing' => 'Not a sizing input. Compare it against a --cache-bust run to see '
                    . 'what the cache is buying the customer.',
                'sizing_ok' => false,
            ];
        }

        return [
            'id' => 'uncontrolled',
            'label' => 'Sequential, cache state not controlled',
            'measures' => 'A mix. Some requests may be served from cache and some not, '
                . 'and the split is not recorded.',
            'sizing' => 'Neither origin cost nor cache effectiveness. Add --cache-bust for '
                . 'sizing, or --warm-cache to measure the cache.',
            'sizing_ok' => false,
        ];
    }

    /**
     * Announce the mode before the run starts, so the operator sees what is
     * being measured while it happens rather than only in the summary.
     *
     * @param array{label: string, measures: string, sizing: string, sizing_ok: bool} $mode
     */
    public static function announce(array $mode): void {
        MicroChaos_Log::log("🧪 Test mode: {$mode['label']}");
        MicroChaos_Log::log("   Measures: {$mode['measures']}");

        if ($mode['sizing_ok']) {
            MicroChaos_Log::log("   Sizing:   {$mode['sizing']}");
            return;
        }

        MicroChaos_Log::warning("   Sizing:   {$mode['sizing']}");
    }

    /**
     * The one-line form carried in the summary block.
     *
     * @param array{label: string, sizing: string} $mode
     * @return string
     */
    public static function summary_line(array $mode): string {
        return "{$mode['label']} — {$mode['sizing']}";
    }
}
