<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MicroChaos_Concurrency_Runner
 *
 * Covers the parts reachable without launching processes: clamp, child-arg
 * rewriting, and flag formatting. run() needs a live WP-CLI and is skipped.
 */
class ConcurrencyRunnerTest extends TestCase
{
    #[Test]
    public function clamp_leaves_a_legal_value_alone(): void
    {
        $this->assertSame(4, \MicroChaos_Concurrency_Runner::clamp(4));
    }

    #[Test]
    public function clamp_floors_below_one_to_one(): void
    {
        $this->assertSame(1, \MicroChaos_Concurrency_Runner::clamp(0));
        $this->assertSame(1, \MicroChaos_Concurrency_Runner::clamp(-3));
    }

    #[Test]
    public function clamp_caps_at_max_concurrency(): void
    {
        $this->assertSame(
            \MicroChaos_Constants::MAX_CONCURRENCY,
            \MicroChaos_Concurrency_Runner::clamp(\MicroChaos_Constants::MAX_CONCURRENCY + 20)
        );
    }

    #[Test]
    public function child_args_force_concurrency_one_and_drop_warm_cache(): void
    {
        $child = \MicroChaos_Concurrency_Runner::child_assoc_args(
            [
                'endpoint' => 'home',
                'count' => 1,
                'concurrency' => 4,
                'warm-cache' => true,
                'cache-bust' => true,
            ],
            2,
            '/tmp/worker-2.json'
        );

        $this->assertSame(1, $child['concurrency']);
        $this->assertSame(2, $child['worker-id']);
        $this->assertSame('/tmp/worker-2.json', $child['results-json']);
        $this->assertArrayNotHasKey('warm-cache', $child);
        $this->assertTrue($child['cache-bust']);
        $this->assertSame(1, $child['count']);
    }

    #[Test]
    public function format_assoc_args_emits_flags_and_skips_false(): void
    {
        $formatted = \MicroChaos_Concurrency_Runner::format_assoc_args([
            'allow-root' => true,
            'count' => 1,
            'warm-cache' => false,
            'endpoint' => 'home',
        ]);

        $this->assertStringContainsString('--allow-root', $formatted);
        $this->assertStringContainsString('--count=', $formatted);
        $this->assertStringContainsString('--endpoint=', $formatted);
        $this->assertStringNotContainsString('warm-cache', $formatted);
    }

    #[Test]
    public function php_scripts_and_phars_are_launched_via_php_binary(): void
    {
        $this->assertTrue(\MicroChaos_Concurrency_Runner::invoker_is_php_script('wp-cli.phar'));
        $this->assertTrue(\MicroChaos_Concurrency_Runner::invoker_is_php_script('/usr/local/bin/wp-cli.php'));
        $this->assertFalse(\MicroChaos_Concurrency_Runner::invoker_is_php_script('wp'));
        $this->assertFalse(\MicroChaos_Concurrency_Runner::invoker_is_php_script('/usr/local/bin/wp'));
    }
}
