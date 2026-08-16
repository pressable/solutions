<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MicroChaos_Resource_Monitor
 *
 * Everything this class measures belongs to the load generator process, so the
 * tests are about labelling the data honestly and deriving the one figure that
 * is useful elsewhere: the CPU the generator took from the site's container.
 */
class ResourceMonitorTest extends TestCase
{
    private \MicroChaos_Resource_Monitor $monitor;

    protected function setUp(): void
    {
        $this->monitor = new \MicroChaos_Resource_Monitor();
    }

    /**
     * Replace the collected samples so CPU maths can be asserted exactly.
     *
     * @param array<int, array<string, float>> $samples
     */
    private function withSamples(array $samples): void
    {
        $property = new \ReflectionProperty($this->monitor, 'resource_results');
        $property->setValue($this->monitor, $samples);
    }

    // =========================================================================
    // Sampling
    // =========================================================================

    #[Test]
    public function every_sample_records_elapsed_time(): void
    {
        // Without a denominator the cumulative CPU counter can't become a rate,
        // so elapsed is recorded whether or not trend tracking is on.
        $sample = $this->monitor->log_resource_utilization();

        $this->assertArrayHasKey('elapsed', $sample);
        $this->assertGreaterThanOrEqual(0, $sample['elapsed']);
    }

    #[Test]
    public function sample_carries_generator_memory_and_cpu(): void
    {
        $sample = $this->monitor->log_resource_utilization();

        foreach (['memory_usage', 'peak_memory', 'user_time', 'system_time'] as $key) {
            $this->assertArrayHasKey($key, $sample);
        }
    }

    // =========================================================================
    // Generator CPU
    // =========================================================================

    #[Test]
    public function summary_derives_generator_cores_from_the_newest_sample(): void
    {
        // getrusage() is cumulative, so the last sample holds the totals:
        // 12s of CPU over 60s of wall clock is 0.2 cores.
        $this->withSamples([
            ['memory_usage' => 200.0, 'peak_memory' => 210.0, 'user_time' => 1.0, 'system_time' => 0.5, 'elapsed' => 10.0],
            ['memory_usage' => 205.0, 'peak_memory' => 215.0, 'user_time' => 9.0, 'system_time' => 3.0, 'elapsed' => 60.0],
        ]);

        $cpu = $this->monitor->generate_summary()['generator_cpu'];

        $this->assertEquals(12.0, $cpu['cpu_seconds']);
        $this->assertEquals(60.0, $cpu['elapsed']);
        $this->assertEquals(0.2, $cpu['cores']);
    }

    #[Test]
    public function generator_cores_are_not_an_average_of_the_samples(): void
    {
        // Averaging a monotonically rising counter is the mistake this replaces.
        // Mean user_time here is 5.0; the correct total is 9.0.
        $this->withSamples([
            ['memory_usage' => 200.0, 'peak_memory' => 200.0, 'user_time' => 1.0, 'system_time' => 0.0, 'elapsed' => 10.0],
            ['memory_usage' => 200.0, 'peak_memory' => 200.0, 'user_time' => 5.0, 'system_time' => 0.0, 'elapsed' => 20.0],
            ['memory_usage' => 200.0, 'peak_memory' => 200.0, 'user_time' => 9.0, 'system_time' => 0.0, 'elapsed' => 30.0],
        ]);

        $cpu = $this->monitor->generate_summary()['generator_cpu'];

        $this->assertEquals(9.0, $cpu['cpu_seconds']);
        $this->assertEquals(0.3, $cpu['cores']);
    }

    #[Test]
    public function a_busy_generator_is_visible_as_a_large_share(): void
    {
        // 30s of CPU over 60s is half a core — enough to matter against a
        // dashboard reading of ~1 core when Phase 4 divides RPS by it.
        $this->withSamples([
            ['memory_usage' => 200.0, 'peak_memory' => 200.0, 'user_time' => 25.0, 'system_time' => 5.0, 'elapsed' => 60.0],
        ]);

        $this->assertEquals(0.5, $this->monitor->generate_summary()['generator_cpu']['cores']);
    }

    #[Test]
    public function generator_cpu_is_null_when_no_time_has_passed(): void
    {
        // Guards the division rather than emitting INF.
        $this->withSamples([
            ['memory_usage' => 200.0, 'peak_memory' => 200.0, 'user_time' => 1.0, 'system_time' => 1.0, 'elapsed' => 0.0],
        ]);

        $this->assertNull($this->monitor->generate_summary()['generator_cpu']);
    }

    #[Test]
    public function summary_is_empty_before_anything_is_sampled(): void
    {
        $this->assertSame([], $this->monitor->generate_summary());
    }

    #[Test]
    public function summary_keeps_its_existing_shape(): void
    {
        // Saved baselines are generate_summary() output, so the additions have
        // to be additive or an old baseline stops comparing.
        $this->withSamples([
            ['memory_usage' => 200.0, 'peak_memory' => 210.0, 'user_time' => 1.0, 'system_time' => 0.5, 'elapsed' => 10.0],
        ]);

        $summary = $this->monitor->generate_summary();

        foreach (['samples', 'memory', 'peak_memory', 'user_time', 'system_time'] as $key) {
            $this->assertArrayHasKey($key, $summary);
        }
    }
}
