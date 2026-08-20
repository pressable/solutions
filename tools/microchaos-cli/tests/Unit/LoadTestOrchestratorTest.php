<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MicroChaos_LoadTest_Orchestrator
 *
 * Covers the parts reachable without WordPress: config normalization and URL
 * construction. execute() and run_test_loop() need a live site and are skipped.
 */
class LoadTestOrchestratorTest extends TestCase
{
    private function orchestrator(array $config = []): \MicroChaos_LoadTest_Orchestrator
    {
        return new \MicroChaos_LoadTest_Orchestrator($config);
    }

    private function config(\MicroChaos_LoadTest_Orchestrator $orchestrator): array
    {
        $property = new \ReflectionProperty($orchestrator, 'config');

        return $property->getValue($orchestrator);
    }

    private function bust(\MicroChaos_LoadTest_Orchestrator $orchestrator, string $url): string
    {
        $method = new \ReflectionMethod($orchestrator, 'apply_cache_buster');

        return $method->invoke($orchestrator, $url);
    }

    // =========================================================================
    // Config normalization
    // =========================================================================

    #[Test]
    public function concurrency_defaults_to_one(): void
    {
        $config = $this->config($this->orchestrator());

        $this->assertSame(\MicroChaos_Constants::DEFAULT_CONCURRENCY, $config['concurrency']);
        $this->assertNull($config['results_json']);
    }

    #[Test]
    public function cache_bust_defaults_to_off(): void
    {
        // Omitting the default here is what produces an undefined-index notice
        // on every run that doesn't pass the flag.
        $config = $this->config($this->orchestrator());

        $this->assertArrayHasKey('cache_bust', $config);
        $this->assertFalse($config['cache_bust']);
    }

    #[Test]
    public function cache_bust_is_carried_through_when_set(): void
    {
        $config = $this->config($this->orchestrator(['cache_bust' => true]));

        $this->assertTrue($config['cache_bust']);
    }

    // =========================================================================
    // Cache busting
    // =========================================================================

    #[Test]
    public function cache_buster_appends_a_parameter(): void
    {
        $url = $this->bust($this->orchestrator(), 'https://example.test/shop/');

        $this->assertStringStartsWith('https://example.test/shop/?mc_cb=', $url);
    }

    #[Test]
    public function cache_buster_joins_an_existing_query_string(): void
    {
        $url = $this->bust($this->orchestrator(), 'https://example.test/shop/?orderby=price');

        $this->assertStringContainsString('orderby=price', $url);
        $this->assertStringContainsString('&mc_cb=', $url);
        $this->assertStringNotContainsString('?mc_cb=', $url);
    }

    #[Test]
    public function cache_buster_is_different_on_every_call(): void
    {
        // A repeated value would be served from cache on the second hit, which
        // is the exact failure the flag exists to prevent.
        $orchestrator = $this->orchestrator();

        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $seen[] = $this->bust($orchestrator, 'https://example.test/');
        }

        $this->assertCount(50, array_unique($seen));
    }

    #[Test]
    public function cache_buster_preserves_the_original_url(): void
    {
        $url = $this->bust($this->orchestrator(), 'https://example.test/product/widget/');

        $this->assertStringStartsWith('https://example.test/product/widget/', $url);
    }
}
