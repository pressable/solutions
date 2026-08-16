<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for MicroChaos_Reporting_Engine
 *
 * Focused on how a response status becomes a success or an error, which is what
 * the headline error rate — and the threshold colouring fed from it — depends on.
 */
class ReportingEngineTest extends TestCase
{
    private \MicroChaos_Reporting_Engine $engine;

    protected function setUp(): void
    {
        $this->engine = new \MicroChaos_Reporting_Engine();
    }

    /**
     * Build result rows for a list of status codes.
     *
     * @param array<int, int|string> $codes
     * @return array<int, array<string, mixed>>
     */
    private function resultsFor(array $codes): array
    {
        return array_map(
            static fn($code) => ['time' => 0.5, 'code' => $code],
            $codes
        );
    }

    // =========================================================================
    // Success classification
    // =========================================================================

    #[Test]
    public function a_redirect_is_not_an_error(): void
    {
        // The case that motivated this: an empty cart answering 302 from the
        // checkout path is correct behaviour, and used to read as 100% failure.
        $this->engine->add_results($this->resultsFor([302, 302, 302]));

        $summary = $this->engine->generate_summary();

        $this->assertSame(3, $summary['success']);
        $this->assertSame(0, $summary['http_errors']);
        $this->assertSame(0.0, (float) $summary['error_rate']);
    }

    #[Test]
    public function every_2xx_and_3xx_counts_as_success(): void
    {
        $this->engine->add_results($this->resultsFor([200, 201, 204, 301, 302, 304, 307, 308]));

        $summary = $this->engine->generate_summary();

        $this->assertSame(8, $summary['success']);
        $this->assertSame(0, $summary['http_errors']);
    }

    #[Test]
    public function client_and_server_errors_still_count_as_errors(): void
    {
        $this->engine->add_results($this->resultsFor([400, 403, 404, 500, 502, 503]));

        $summary = $this->engine->generate_summary();

        $this->assertSame(0, $summary['success']);
        $this->assertSame(6, $summary['http_errors']);
        $this->assertSame(100.0, (float) $summary['error_rate']);
    }

    #[Test]
    public function the_error_sentinel_counts_as_an_error(): void
    {
        // A request that never completed reports 'ERROR' rather than a code.
        $this->engine->add_results($this->resultsFor([200, 'ERROR', 200, 'ERROR']));

        $summary = $this->engine->generate_summary();

        $this->assertSame(2, $summary['success']);
        $this->assertSame(2, $summary['http_errors']);
        $this->assertSame(50.0, (float) $summary['error_rate']);
    }

    #[Test]
    public function numeric_string_codes_are_classified_by_value(): void
    {
        // Transports differ on whether the code arrives as int or string; the
        // classification must not depend on which one showed up.
        $this->engine->add_results($this->resultsFor(['200', '302', '500']));

        $summary = $this->engine->generate_summary();

        $this->assertSame(2, $summary['success']);
        $this->assertSame(1, $summary['http_errors']);
    }

    #[Test]
    public function error_rate_reflects_only_the_genuine_failures(): void
    {
        // 8 good (six 200s, two 302s) and 2 bad out of 10.
        $this->engine->add_results($this->resultsFor([200, 200, 200, 200, 200, 200, 302, 302, 500, 404]));

        $summary = $this->engine->generate_summary();

        $this->assertSame(8, $summary['success']);
        $this->assertSame(20.0, (float) $summary['error_rate']);
    }

    // =========================================================================
    // Status code distribution
    // =========================================================================

    #[Test]
    public function summary_tallies_each_status_code(): void
    {
        $this->engine->add_results($this->resultsFor([200, 200, 200, 302, 500]));

        $codes = $this->engine->generate_summary()['status_codes'];

        $this->assertSame(3, $codes[200]);
        $this->assertSame(1, $codes[302]);
        $this->assertSame(1, $codes[500]);
    }

    #[Test]
    public function status_codes_are_ordered_most_frequent_first(): void
    {
        $this->engine->add_results($this->resultsFor([500, 302, 302, 200, 200, 200]));

        $codes = $this->engine->generate_summary()['status_codes'];

        $this->assertSame([200, 302, 500], array_keys($codes));
    }

    #[Test]
    public function failed_requests_are_tallied_under_their_own_label(): void
    {
        $this->engine->add_results($this->resultsFor([200, 'ERROR', 'ERROR']));

        $codes = $this->engine->generate_summary()['status_codes'];

        $this->assertSame(2, $codes['ERROR']);
    }

    #[Test]
    public function status_code_tally_accounts_for_every_request(): void
    {
        $this->engine->add_results($this->resultsFor([200, 302, 404, 'ERROR']));

        $summary = $this->engine->generate_summary();

        $this->assertSame($summary['count'], array_sum($summary['status_codes']));
    }

    // =========================================================================
    // Interaction with GraphQL errors
    // =========================================================================

    #[Test]
    public function a_redirect_does_not_disturb_graphql_error_accounting(): void
    {
        // A 200 carrying GraphQL errors is still a failed request; a 302 that
        // carries none is still a success.
        $this->engine->add_results([
            ['time' => 0.5, 'code' => 200, 'graphql_errors' => 0],
            ['time' => 0.5, 'code' => 200, 'graphql_errors' => 2],
            ['time' => 0.5, 'code' => 302, 'graphql_errors' => 0],
        ]);

        $summary = $this->engine->generate_summary();

        $this->assertSame(2, $summary['success']);
        $this->assertSame(0, $summary['http_errors']);
        $this->assertSame(1, $summary['graphql_error_requests']);
    }

    // =========================================================================
    // Empty state
    // =========================================================================

    #[Test]
    public function empty_summary_keeps_the_same_shape(): void
    {
        $summary = $this->engine->generate_summary();

        $this->assertSame(0, $summary['count']);
        $this->assertSame([], $summary['status_codes']);
    }
}
