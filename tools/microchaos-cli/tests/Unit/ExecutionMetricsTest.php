<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for throughput metrics in MicroChaos_LoadTest_Orchestrator
 *
 * The distinction under test: wall-clock throughput moves with the operator's
 * pacing flags, the serial ceiling does not. Only the second one can honestly
 * be projected forward.
 */
class ExecutionMetricsTest extends TestCase
{
    /**
     * Build metrics for a run of $completed requests each taking $seconds,
     * stretched over $wall seconds of wall clock.
     *
     * @return array<string, mixed>
     */
    private function metricsFor(int $completed, float $seconds, float $wall): array
    {
        $orchestrator = new \MicroChaos_LoadTest_Orchestrator([]);

        $results = array_fill(0, $completed, ['time' => $seconds, 'code' => 200]);

        $method = new \ReflectionMethod($orchestrator, 'build_execution_metrics');

        return $method->invoke($orchestrator, 1000.0, 1000.0 + $wall, $completed, $results);
    }

    // =========================================================================
    // The two throughput figures
    // =========================================================================

    #[Test]
    public function serial_ceiling_is_the_inverse_of_response_time(): void
    {
        // 100 requests at 0.5s each: one at a time, the site sustains 2/s.
        $metrics = $this->metricsFor(100, 0.5, 200.0);

        $this->assertSame(2.0, $metrics['serial_ceiling_rps']);
    }

    #[Test]
    public function wall_clock_throughput_is_dragged_down_by_pacing(): void
    {
        // The same 100 requests at 0.5s (50s of work) spread over 200s because
        // of --delay sleeps: wall clock reports a quarter of the real rate.
        $metrics = $this->metricsFor(100, 0.5, 200.0);

        $this->assertSame(0.5, $metrics['throughput_rps']);
        $this->assertSame(2.0, $metrics['serial_ceiling_rps']);
    }

    #[Test]
    public function the_serial_ceiling_does_not_move_when_pacing_changes(): void
    {
        // This is the whole point: two runs against an identical site with
        // different --delay values must not report different capacity.
        $impatient = $this->metricsFor(100, 0.5, 60.0);
        $relaxed = $this->metricsFor(100, 0.5, 300.0);

        $this->assertSame($impatient['serial_ceiling_rps'], $relaxed['serial_ceiling_rps']);
        $this->assertNotSame($impatient['throughput_rps'], $relaxed['throughput_rps']);
    }

    #[Test]
    public function wall_clock_throughput_is_still_reported(): void
    {
        // It stays because it is the figure that pairs with a dashboard CPU
        // reading covering the same window.
        $metrics = $this->metricsFor(60, 1.0, 120.0);

        $this->assertSame(0.5, $metrics['throughput_rps']);
    }

    #[Test]
    public function a_slower_site_has_a_lower_ceiling(): void
    {
        $fast = $this->metricsFor(100, 0.25, 100.0);
        $slow = $this->metricsFor(100, 2.0, 400.0);

        $this->assertSame(4.0, $fast['serial_ceiling_rps']);
        $this->assertSame(0.5, $slow['serial_ceiling_rps']);
    }

    // =========================================================================
    // Pacing share
    // =========================================================================

    #[Test]
    public function pacing_share_reports_time_not_spent_on_responses(): void
    {
        // 50s of responses inside a 200s run: 75% of the run was pacing.
        $metrics = $this->metricsFor(100, 0.5, 200.0);

        $this->assertSame(75.0, $metrics['pacing_share_pct']);
    }

    #[Test]
    public function pacing_share_is_near_zero_when_nothing_sleeps(): void
    {
        $metrics = $this->metricsFor(100, 0.5, 50.0);

        $this->assertSame(0.0, $metrics['pacing_share_pct']);
    }

    #[Test]
    public function pacing_share_never_goes_negative(): void
    {
        // Recorded response times can exceed wall clock slightly through
        // rounding; a negative share would be nonsense to print.
        $metrics = $this->metricsFor(100, 0.5, 40.0);

        $this->assertGreaterThanOrEqual(0, $metrics['pacing_share_pct']);
    }

    // =========================================================================
    // Capacity projection
    // =========================================================================

    #[Test]
    public function capacity_is_projected_from_the_ceiling_not_wall_clock(): void
    {
        // 2 req/s ceiling → 7,200/hour. Projecting the 0.5 req/s wall-clock
        // figure instead would have reported 1,800 for the same site.
        $metrics = $this->metricsFor(100, 0.5, 200.0);

        $this->assertSame(7200, $metrics['capacity']['per_hour']);
    }

    #[Test]
    public function capacity_is_unaffected_by_the_operators_pacing(): void
    {
        $impatient = $this->metricsFor(100, 0.5, 60.0);
        $relaxed = $this->metricsFor(100, 0.5, 600.0);

        $this->assertSame($impatient['capacity'], $relaxed['capacity']);
    }

    #[Test]
    public function capacity_scales_consistently_across_periods(): void
    {
        $metrics = $this->metricsFor(100, 0.5, 200.0);

        $this->assertSame(24 * $metrics['capacity']['per_hour'], $metrics['capacity']['per_day']);
        $this->assertSame(30 * $metrics['capacity']['per_day'], $metrics['capacity']['per_month']);
    }

    // =========================================================================
    // Degenerate input
    // =========================================================================

    #[Test]
    public function a_run_with_no_results_reports_zero_rather_than_dividing(): void
    {
        $orchestrator = new \MicroChaos_LoadTest_Orchestrator([]);
        $method = new \ReflectionMethod($orchestrator, 'build_execution_metrics');

        $metrics = $method->invoke($orchestrator, 1000.0, 1060.0, 0, []);

        $this->assertSame(0.0, $metrics['serial_ceiling_rps']);
        $this->assertSame(0, $metrics['capacity']['per_hour']);
    }

    #[Test]
    public function throughput_fields_are_consistently_typed(): void
    {
        // These get serialized for --export and the monitoring integration, so
        // a field that is sometimes int and sometimes float is a trap.
        $empty = $this->metricsFor(0, 0.0, 60.0);
        $populated = $this->metricsFor(10, 0.5, 60.0);

        foreach (['serial_ceiling_rps', 'pacing_share_pct', 'response_time_total'] as $field) {
            $this->assertIsFloat($empty[$field], "{$field} on an empty run");
            $this->assertIsFloat($populated[$field], "{$field} on a populated run");
        }
    }

    #[Test]
    public function response_time_total_is_reported_for_transparency(): void
    {
        $metrics = $this->metricsFor(100, 0.5, 200.0);

        $this->assertSame(50.0, $metrics['response_time_total']);
    }
}
