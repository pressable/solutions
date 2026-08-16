<?php
/**
 * Resource Monitor Component
 *
 * Tracks and reports on system resource usage during load testing.
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

/**
 * Resource Monitor class
 */
class MicroChaos_Resource_Monitor {
    /**
     * Resource results storage
     *
     * @var array<int, array<string, mixed>>
     */
    private array $resource_results = [];

    /**
     * Whether to track resource trends over time
     *
     * @var bool
     */
    private bool $track_trends = false;

    /**
     * Timestamp of monitoring start
     *
     * @var float
     */
    private float $start_time = 0;

    /**
     * Baseline storage implementation
     *
     * @var MicroChaos_Baseline_Storage
     */
    private $baseline_storage;

    /**
     * Constructor
     *
     * @param array $options Options for resource monitoring
     * @param MicroChaos_Baseline_Storage|null $storage Optional baseline storage (will create default if not provided)
     */
    public function __construct(array $options = [], ?MicroChaos_Baseline_Storage $storage = null) {
        $this->resource_results = [];
        $this->track_trends = isset($options['track_trends']) ? (bool)$options['track_trends'] : false;
        $this->start_time = microtime(true);
        $this->baseline_storage = $storage ?? new MicroChaos_Transient_Baseline_Storage('microchaos_resource_baseline');
    }

    /**
     * Log current resource utilization of the load generator process
     *
     * Everything here describes the WP-CLI process firing the requests, not the
     * PHP-FPM worker serving them. They are separate OS processes and nothing
     * here reaches across that boundary.
     *
     * Note that getrusage() returns CUMULATIVE CPU time for the process, so
     * user_time and system_time rise monotonically across samples. Only the
     * newest sample is meaningful on its own.
     *
     * @return array Current resource usage data
     */
    public function log_resource_utilization(): array {
        $memory_usage = round(memory_get_usage() / 1024 / 1024, 2);
        $peak_memory = round(memory_get_peak_usage() / 1024 / 1024, 2);
        $ru = getrusage();
        $user_time = round($ru['ru_utime.tv_sec'] + $ru['ru_utime.tv_usec'] / 1e6, 2);
        $system_time = round($ru['ru_stime.tv_sec'] + $ru['ru_stime.tv_usec'] / 1e6, 2);

        $now = microtime(true);
        $elapsed = round($now - $this->start_time, 4);

        if (class_exists('WP_CLI')) {
            MicroChaos_Log::log("🔍 Load generator: Memory {$memory_usage} MB, Peak {$peak_memory} MB, CPU {$user_time}s user + {$system_time}s system over {$elapsed}s");
        }

        $result = [
            'memory_usage' => $memory_usage,
            'peak_memory'  => $peak_memory,
            'user_time'    => $user_time,
            'system_time'  => $system_time,
            // Always recorded, not just for trends: turning the cumulative CPU
            // counter into a rate needs a denominator.
            'elapsed'      => $elapsed,
        ];

        if ($this->track_trends) {
            $result['timestamp'] = $now;
        }

        $this->resource_results[] = $result;
        return $result;
    }

    /**
     * Average CPU cores consumed by the load generator itself
     *
     * MicroChaos runs on the same container as the site under test, so the CPU
     * it burns is inside whatever per-site figure the observability dashboard
     * reports for the same window. Phase 4 divides RPS by that figure, so any
     * generator overhead left in it understates requests-per-worker and
     * oversizes the recommendation.
     *
     * getrusage() is cumulative, so the newest sample carries the total; divide
     * by elapsed wall time to get a rate comparable with a dashboard reading.
     *
     * @return array{cpu_seconds: float, elapsed: float, cores: float}|null Null when there is nothing to derive from
     */
    private function generator_cpu_usage(): ?array {
        if (empty($this->resource_results)) {
            return null;
        }

        $latest = $this->resource_results[array_key_last($this->resource_results)];
        $elapsed = $latest['elapsed'] ?? 0;

        if ($elapsed <= 0) {
            return null;
        }

        $cpu_seconds = $latest['user_time'] + $latest['system_time'];

        return [
            'cpu_seconds' => round($cpu_seconds, 2),
            'elapsed'     => round($elapsed, 2),
            'cores'       => round($cpu_seconds / $elapsed, 3),
        ];
    }

    /**
     * Get all resource utilization results
     *
     * @return array Resource utilization data
     */
    public function get_resource_results(): array {
        return $this->resource_results;
    }

    /**
     * Generate resource utilization summary report
     *
     * @return array Summary metrics
     */
    public function generate_summary(): array {
        if (empty($this->resource_results)) {
            return [];
        }

        $n = count($this->resource_results);

        // Memory usage stats
        $mem_usages = array_column($this->resource_results, 'memory_usage');
        sort($mem_usages);
        $avg_memory_usage = round(array_sum($mem_usages) / $n, 2);
        $median_memory_usage = round($mem_usages[floor($n / 2)], 2);

        // Peak memory stats
        $peak_memories = array_column($this->resource_results, 'peak_memory');
        sort($peak_memories);
        $avg_peak_memory = round(array_sum($peak_memories) / $n, 2);
        $median_peak_memory = round($peak_memories[floor($n / 2)], 2);

        // User CPU time stats
        $user_times = array_column($this->resource_results, 'user_time');
        sort($user_times);
        $avg_user_time = round(array_sum($user_times) / $n, 2);
        $median_user_time = round($user_times[floor($n / 2)], 2);

        // System CPU time stats
        $system_times = array_column($this->resource_results, 'system_time');
        sort($system_times);
        $avg_system_time = round(array_sum($system_times) / $n, 2);
        $median_system_time = round($system_times[floor($n / 2)], 2);

        return [
            'samples' => $n,
            'memory' => [
                'avg' => $avg_memory_usage,
                'median' => $median_memory_usage,
                'min' => round(min($mem_usages), 2),
                'max' => round(max($mem_usages), 2),
            ],
            'peak_memory' => [
                'avg' => $avg_peak_memory,
                'median' => $median_peak_memory,
                'min' => round(min($peak_memories), 2),
                'max' => round(max($peak_memories), 2),
            ],
            'user_time' => [
                'avg' => $avg_user_time,
                'median' => $median_user_time,
                'min' => round(min($user_times), 2),
                'max' => round(max($user_times), 2),
            ],
            'system_time' => [
                'avg' => $avg_system_time,
                'median' => $median_system_time,
                'min' => round(min($system_times), 2),
                'max' => round(max($system_times), 2),
            ],
            // The only figure here that is useful outside this process: what the
            // generator cost the container, so it can be taken back out of the
            // dashboard CPU reading Phase 4 sizes from.
            'generator_cpu' => $this->generator_cpu_usage(),
        ];
    }

    /**
     * Output resource summary to CLI
     * 
     * @param array|null $baseline Optional baseline data for comparison
     * @param array|null $provided_summary Optional pre-generated summary
     * @param string|null $threshold_profile Optional threshold profile to use for formatting
     */
    public function report_summary(?array $baseline = null, ?array $provided_summary = null, ?string $threshold_profile = null): void {
        $summary = $provided_summary ?: $this->generate_summary();

        if (empty($summary)) {
            return;
        }

        if (class_exists('WP_CLI')) {
            // Memory is deliberately uncoloured. format_value() scores it against
            // ini_get('memory_limit') for the CLI process, which is the wrong
            // limit for a web worker and is usually -1 here — in which case
            // get_php_memory_limit_mb() substitutes 128MB and every reading goes
            // red against a number that doesn't exist.
            MicroChaos_Log::log("📊 Load Generator Resource Usage (the WP-CLI process, NOT the site):");
            MicroChaos_Log::log("   Memory Usage: Avg: {$summary['memory']['avg']} MB, Median: {$summary['memory']['median']} MB, Min: {$summary['memory']['min']} MB, Max: {$summary['memory']['max']} MB");
            MicroChaos_Log::log("   Peak Memory: Avg: {$summary['peak_memory']['avg']} MB, Median: {$summary['peak_memory']['median']} MB, Min: {$summary['peak_memory']['min']} MB, Max: {$summary['peak_memory']['max']} MB");
            MicroChaos_Log::log("   ⚠ Worker RAM cannot be sized from these. They describe the process");
            MicroChaos_Log::log("     sending the requests; take per-worker memory from the dashboard.");

            $generator_cpu = $summary['generator_cpu'] ?? null;
            if ($generator_cpu) {
                MicroChaos_Log::log("   Generator CPU: {$generator_cpu['cores']} cores avg ({$generator_cpu['cpu_seconds']}s CPU over {$generator_cpu['elapsed']}s wall)");
                MicroChaos_Log::log("     This runs on the site's own container, so it is included in the");
                MicroChaos_Log::log("     dashboard's per-site CPU. Subtract it before dividing RPS by CPU,");
                MicroChaos_Log::log("     or the worker count comes out high.");
            }
            
            // Add comparison with baseline if provided
            if ($baseline) {
                if (isset($baseline['memory'])) {
                    $mem_avg_change = $baseline['memory']['avg'] > 0 
                        ? (($summary['memory']['avg'] - $baseline['memory']['avg']) / $baseline['memory']['avg']) * 100 
                        : 0;
                    $mem_avg_change = round($mem_avg_change, 1);
                    
                    $change_indicator = $mem_avg_change <= 0 ? '↓' : '↑';
                    $change_color = $mem_avg_change <= 0 ? "\033[32m" : "\033[31m";
                    
                    MicroChaos_Log::log("   Comparison to Baseline:");
                    MicroChaos_Log::log("   - Avg Memory: {$change_color}{$change_indicator}{$mem_avg_change}%\033[0m vs {$baseline['memory']['avg']} MB");
                    
                    $mem_max_change = $baseline['memory']['max'] > 0 
                        ? (($summary['memory']['max'] - $baseline['memory']['max']) / $baseline['memory']['max']) * 100 
                        : 0;
                    $mem_max_change = round($mem_max_change, 1);
                    
                    $change_indicator = $mem_max_change <= 0 ? '↓' : '↑';
                    $change_color = $mem_max_change <= 0 ? "\033[32m" : "\033[31m";
                    MicroChaos_Log::log("   - Max Memory: {$change_color}{$change_indicator}{$mem_max_change}%\033[0m vs {$baseline['memory']['max']} MB");
                }
            }
            
            // Add memory usage visualization
            if (count($this->resource_results) >= 5) {
                $chart_data = [
                    'Memory' => $summary['memory']['avg'],
                    'Peak' => $summary['peak_memory']['avg'],
                    'MaxMem' => $summary['memory']['max'],
                    'MaxPeak' => $summary['peak_memory']['max'],
                ];
                
                $chart = MicroChaos_Thresholds::generate_chart($chart_data, "Load Generator Memory (MB)");
                MicroChaos_Log::log($chart);
            }
        }
    }
    
    /**
     * Save current results as baseline
     *
     * @param string $name Optional name for the baseline
     * @return array Baseline data
     */
    public function save_baseline(string $name = 'default'): array {
        $baseline = $this->generate_summary();
        $this->baseline_storage->save($name, $baseline);
        return $baseline;
    }

    /**
     * Get saved baseline data
     *
     * @param string $name Baseline name
     * @return array<string, mixed>|null Baseline data or null if not found
     */
    public function get_baseline(string $name = 'default'): ?array {
        return $this->baseline_storage->get($name);
    }
    
    /**
     * Analyze resource usage trends
     *
     * @return array|null Trend analysis data
     */
    public function analyze_trends(): ?array {
        if (!$this->track_trends || count($this->resource_results) < 3) {
            return null;
        }
        
        // Sort results by elapsed time
        $sorted_results = $this->resource_results;
        usort($sorted_results, function($a, $b) {
            return $a['elapsed'] <=> $b['elapsed'];
        });
        
        // Calculate slopes for memory, peak memory, and CPU time
        $memory_slope = $this->calculate_trend_slope(array_column($sorted_results, 'elapsed'), 
                                                    array_column($sorted_results, 'memory_usage'));
        $peak_memory_slope = $this->calculate_trend_slope(array_column($sorted_results, 'elapsed'), 
                                                         array_column($sorted_results, 'peak_memory'));
        $user_time_slope = $this->calculate_trend_slope(array_column($sorted_results, 'elapsed'), 
                                                       array_column($sorted_results, 'user_time'));
        
        // Calculate percentage changes
        $first_memory = $sorted_results[0]['memory_usage'];
        $last_memory = end($sorted_results)['memory_usage'];
        $memory_change_pct = $first_memory > 0 ? (($last_memory - $first_memory) / $first_memory) * 100 : 0;
        
        $first_peak = $sorted_results[0]['peak_memory'];
        $last_peak = end($sorted_results)['peak_memory'];
        $peak_change_pct = $first_peak > 0 ? (($last_peak - $first_peak) / $first_peak) * 100 : 0;
        
        // Determine if we see an unbounded growth pattern
        $memory_growth_pattern = $this->determine_growth_pattern($sorted_results, 'memory_usage');
        $peak_memory_growth_pattern = $this->determine_growth_pattern($sorted_results, 'peak_memory');
        
        return [
            'memory_usage' => [
                'slope' => round($memory_slope, 4),
                'change_percent' => round($memory_change_pct, 1),
                'pattern' => $memory_growth_pattern
            ],
            'peak_memory' => [
                'slope' => round($peak_memory_slope, 4),
                'change_percent' => round($peak_change_pct, 1),
                'pattern' => $peak_memory_growth_pattern
            ],
            'user_time' => [
                'slope' => round($user_time_slope, 4)
            ],
            'data_points' => count($sorted_results),
            'time_span' => end($sorted_results)['elapsed'] - $sorted_results[0]['elapsed'],
            'potentially_unbounded' => ($memory_growth_pattern === 'continuous_growth' || $peak_memory_growth_pattern === 'continuous_growth')
        ];
    }
    
    /**
     * Calculate the slope of a trend line (simple linear regression)
     *
     * @param array<int, float> $x X values (time)
     * @param array<int, float> $y Y values (resource metric)
     * @return float Slope of trend line
     */
    private function calculate_trend_slope(array $x, array $y): float {
        $n = count($x);
        if ($n < 2) return 0;
        
        $sum_x = array_sum($x);
        $sum_y = array_sum($y);
        $sum_xx = 0;
        $sum_xy = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sum_xx += $x[$i] * $x[$i];
            $sum_xy += $x[$i] * $y[$i];
        }
        
        // Avoid division by zero
        $denominator = $n * $sum_xx - $sum_x * $sum_x;
        if ($denominator == 0) return 0;
        
        // Calculate slope (m) of the line y = mx + b
        return ($n * $sum_xy - $sum_x * $sum_y) / $denominator;
    }
    
    /**
     * Determine the growth pattern of a resource metric
     *
     * @param array<int, array<string, mixed>> $results Sorted resource results
     * @param string $metric Metric to analyze (memory_usage, peak_memory)
     * @return string Growth pattern description
     */
    private function determine_growth_pattern(array $results, string $metric): string {
        $n = count($results);
        if ($n < 5) return 'insufficient_data';
        
        // Split the data into segments
        $segments = 4; // Analyze in 4 segments
        $segment_size = floor($n / $segments);
        
        $segment_averages = [];
        for ($i = 0; $i < $segments; $i++) {
            $start = $i * $segment_size;
            $end = min($start + $segment_size - 1, $n - 1);
            
            $segment_data = array_slice($results, $start, $end - $start + 1);
            $segment_averages[] = array_sum(array_column($segment_data, $metric)) / count($segment_data);
        }
        
        // Check if each segment is higher than the previous
        $continuous_growth = true;
        for ($i = 1; $i < $segments; $i++) {
            if ($segment_averages[$i] <= $segment_averages[$i-1]) {
                $continuous_growth = false;
                break;
            }
        }
        
        if ($continuous_growth) {
            // Calculate growth rate between first and last segment
            $growth_pct = ($segment_averages[$segments-1] - $segment_averages[0]) / $segment_averages[0] * 100;
            
            if ($growth_pct > 50) {
                return 'continuous_growth';
            } else {
                return 'moderate_growth';
            }
        }
        
        // Check if it's stabilizing (last segment similar to previous)
        $last_diff_pct = abs(($segment_averages[$segments-1] - $segment_averages[$segments-2]) / $segment_averages[$segments-2] * 100);
        if ($last_diff_pct < 5) {
            return 'stabilizing';
        }
        
        // Check if it's fluctuating
        return 'fluctuating';
    }
    
    /**
     * Generate ASCII trend charts for resource metrics
     * 
     * @param int $width Chart width
     * @param int $height Chart height
     * @return string ASCII charts
     */
    public function generate_trend_charts(int $width = 60, int $height = 15): string {
        if (!$this->track_trends || count($this->resource_results) < 5) {
            return "Insufficient data for trend visualization (need at least 5 data points).";
        }
        
        // Sort results by elapsed time
        $sorted_results = $this->resource_results;
        usort($sorted_results, function($a, $b) {
            return $a['elapsed'] <=> $b['elapsed'];
        });
        
        // Extract data for charts
        $times = array_column($sorted_results, 'elapsed');
        $memories = array_column($sorted_results, 'memory_usage');
        $peak_memories = array_column($sorted_results, 'peak_memory');
        
        // Create memory chart
        $memory_chart = $this->create_ascii_chart(
            $times, 
            $memories, 
            "Memory Usage Trend (MB over time)", 
            $width, 
            $height
        );
        
        // Create peak memory chart
        $peak_chart = $this->create_ascii_chart(
            $times, 
            $peak_memories, 
            "Peak Memory Trend (MB over time)", 
            $width, 
            $height
        );
        
        return $memory_chart . "\n" . $peak_chart;
    }
    
    /**
     * Create ASCII chart for a metric
     *
     * @param array<int, float> $x X values (time)
     * @param array<int, float> $y Y values (resource metric)
     * @param string $title Chart title
     * @param int $width Chart width
     * @param int $height Chart height
     * @return string ASCII chart
     */
    private function create_ascii_chart(array $x, array $y, string $title, int $width, int $height): string {
        $n = count($x);
        if ($n < 2) return "";
        
        // Find min/max values
        $min_x = min($x);
        $max_x = max($x);
        $min_y = min($y);
        $max_y = max($y);
        
        // Ensure range is non-zero
        $x_range = $max_x - $min_x;
        if ($x_range == 0) $x_range = 1;
        
        $y_range = $max_y - $min_y;
        if ($y_range == 0) $y_range = 1;
        
        // Create chart canvas
        $output = "\n   $title:\n";
        $chart = [];
        for ($i = 0; $i < $height; $i++) {
            $chart[$i] = str_split(str_repeat(' ', $width));
        }
        
        // Plot data points
        for ($i = 0; $i < $n; $i++) {
            $x_pos = round(($x[$i] - $min_x) / $x_range * ($width - 1));
            $y_pos = $height - 1 - round(($y[$i] - $min_y) / $y_range * ($height - 1));
            
            // Ensure within bounds
            $x_pos = max(0, min($width - 1, $x_pos));
            $y_pos = max(0, min($height - 1, $y_pos));
            
            // Plot point
            $chart[$y_pos][$x_pos] = '•';
        }
        
        // Add trend line (linear regression)
        $slope = $this->calculate_trend_slope($x, $y);
        $y_mean = array_sum($y) / $n;
        $x_mean = array_sum($x) / $n;
        $intercept = $y_mean - $slope * $x_mean;
        
        for ($x_pos = 0; $x_pos < $width; $x_pos++) {
            $x_val = $min_x + ($x_pos / ($width - 1)) * $x_range;
            $y_val = $slope * $x_val + $intercept;
            $y_pos = $height - 1 - round(($y_val - $min_y) / $y_range * ($height - 1));
            
            // Ensure within bounds
            if ($y_pos >= 0 && $y_pos < $height) {
                // Use different character for trend line to distinguish from data points
                if ($chart[$y_pos][$x_pos] == ' ') {
                    $chart[$y_pos][$x_pos] = '-';
                }
            }
        }
        
        // Add axis labels
        $output .= "   " . str_repeat(' ', strlen((string)$max_y) + 2) . "┌" . str_repeat('─', $width) . "┐\n";
        for ($i = 0; $i < $height; $i++) {
            $label_y = round($max_y - ($i / ($height - 1)) * $y_range, 1);
            $output .= sprintf("   %'.3s │%s│\n", $label_y, implode('', $chart[$i]));
        }
        $output .= "   " . str_repeat(' ', strlen((string)$max_y) + 2) . "└" . str_repeat('─', $width) . "┘\n";
        
        // X-axis labels (start, middle, end)
        $output .= sprintf("   %'.3s %'.3s%'.3s\n", 
                       round($min_x, 1),
                       str_repeat(' ', intval($width/2)) . round($min_x + $x_range/2, 1),
                       str_repeat(' ', intval($width/2) - 2) . round($max_x, 1));
        
        return $output;
    }
    
    /**
     * Report trend analysis to CLI
     */
    public function report_trends(): void {
        if (!$this->track_trends || count($this->resource_results) < 3) {
            if (class_exists('WP_CLI')) {
                MicroChaos_Log::log("📈 Resource Trend Analysis: Insufficient data for trend analysis.");
            }
            return;
        }
        
        $trends = $this->analyze_trends();
        
        if (class_exists('WP_CLI')) {
            MicroChaos_Log::log("\n📈 Load Generator Trend Analysis (the WP-CLI process, NOT the site):");
            MicroChaos_Log::log("   Data Points: {$trends['data_points']} over {$trends['time_span']} seconds");
            
            // Memory usage trends
            $memory_change = $trends['memory_usage']['change_percent'];
            $memory_direction = $memory_change > 0 ? '↑' : '↓';
            $memory_color = $memory_change > 20 ? "\033[31m" : ($memory_change > 5 ? "\033[33m" : "\033[32m");
            MicroChaos_Log::log("   Memory Usage: {$memory_color}{$memory_direction}{$memory_change}%\033[0m over test duration");
            MicroChaos_Log::log("   Pattern: " . ucfirst(str_replace('_', ' ', $trends['memory_usage']['pattern'])));
            
            // Peak memory trends
            $peak_change = $trends['peak_memory']['change_percent'];
            $peak_direction = $peak_change > 0 ? '↑' : '↓';
            $peak_color = $peak_change > 20 ? "\033[31m" : ($peak_change > 5 ? "\033[33m" : "\033[32m");
            MicroChaos_Log::log("   Peak Memory: {$peak_color}{$peak_direction}{$peak_change}%\033[0m over test duration");
            MicroChaos_Log::log("   Pattern: " . ucfirst(str_replace('_', ' ', $trends['peak_memory']['pattern'])));
            
            // Growth here is the generator accumulating its own results array as
            // the run gets longer. It is a function of test duration, not site
            // health, so it must not be reported as a leak.
            if ($trends['potentially_unbounded']) {
                MicroChaos_Log::log("   Note: generator memory grows with run length because it accumulates its own results. Expected — not a signal about the site.");
            }
            
            // Generate visual trend charts
            $charts = $this->generate_trend_charts();
            MicroChaos_Log::log($charts);
        }
    }
}
