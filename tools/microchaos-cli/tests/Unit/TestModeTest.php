<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MicroChaos_Test_Mode
 *
 * The mode decides what a summary claims its numbers are good for, so the
 * assertions that matter are which mode a flag combination resolves to and
 * whether that mode is marked as a sizing input. Exactly one is.
 */
class TestModeTest extends TestCase
{
    #[Test]
    public function cache_bust_is_the_origin_cost_run(): void
    {
        $mode = \MicroChaos_Test_Mode::resolve(['cache_bust' => true]);

        $this->assertSame('origin-cost', $mode['id']);
        $this->assertTrue($mode['sizing_ok']);
    }

    #[Test]
    public function warm_cache_measures_the_cache_and_is_not_a_sizing_input(): void
    {
        $mode = \MicroChaos_Test_Mode::resolve(['warm_cache' => true]);

        $this->assertSame('cache-effectiveness', $mode['id']);
        $this->assertFalse($mode['sizing_ok']);
    }

    #[Test]
    public function concurrency_above_one_is_the_overlap_run(): void
    {
        $mode = \MicroChaos_Test_Mode::resolve(['concurrency' => 4, 'cache_bust' => true]);

        $this->assertSame('overlap', $mode['id']);
        $this->assertFalse($mode['sizing_ok']);
    }

    #[Test]
    public function overlap_names_the_process_count_so_the_summary_is_self_describing(): void
    {
        $mode = \MicroChaos_Test_Mode::resolve(['concurrency' => 4]);

        $this->assertStringContainsString('4 processes', $mode['label']);
    }

    #[Test]
    public function concurrency_of_one_is_not_an_overlap_run(): void
    {
        $mode = \MicroChaos_Test_Mode::resolve(['concurrency' => 1, 'cache_bust' => true]);

        $this->assertSame('origin-cost', $mode['id']);
    }

    #[Test]
    public function neither_cache_flag_is_reported_as_uncontrolled(): void
    {
        $mode = \MicroChaos_Test_Mode::resolve([]);

        $this->assertSame('uncontrolled', $mode['id']);
        $this->assertFalse($mode['sizing_ok']);
    }

    #[Test]
    public function cache_bust_wins_when_both_cache_flags_are_set(): void
    {
        $mode = \MicroChaos_Test_Mode::resolve(['cache_bust' => true, 'warm_cache' => true]);

        $this->assertSame('origin-cost', $mode['id']);
    }

    #[Test]
    public function every_mode_carries_a_label_and_a_sizing_statement(): void
    {
        $configs = [
            ['cache_bust' => true],
            ['warm_cache' => true],
            ['concurrency' => 3],
            [],
        ];

        foreach ($configs as $config) {
            $mode = \MicroChaos_Test_Mode::resolve($config);

            $this->assertNotSame('', $mode['label']);
            $this->assertNotSame('', $mode['measures']);
            $this->assertNotSame('', $mode['sizing']);
            $this->assertNotSame('', \MicroChaos_Test_Mode::summary_line($mode));
        }
    }
}
