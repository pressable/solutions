<?php

declare(strict_types=1);

namespace MicroChaos\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for timeout handling in MicroChaos_Request_Generator
 *
 * Firing a request needs a live transport, so these cover the two decisions
 * made around it: how long to wait, and how to label a request that never
 * came back.
 */
class RequestGeneratorTimeoutTest extends TestCase
{
    /**
     * Invoke one of the private failure classifiers.
     *
     * @param mixed $argument
     */
    private function classify(string $method, $argument): string
    {
        $reflection = new \ReflectionMethod('MicroChaos_Request_Generator', $method);

        return $reflection->invoke(null, $argument);
    }

    // =========================================================================
    // Timeout configuration
    // =========================================================================

    #[Test]
    public function timeout_defaults_to_the_shared_constant(): void
    {
        $generator = new \MicroChaos_Request_Generator();

        $this->assertSame(
            \MicroChaos_Constants::DEFAULT_REQUEST_TIMEOUT,
            $generator->get_timeout()
        );
    }

    #[Test]
    public function default_timeout_is_longer_than_the_old_hardcoded_ten_seconds(): void
    {
        // 10s cut off responses a struggling site genuinely produces, which is
        // the censoring this fix exists to reduce.
        $this->assertGreaterThan(10, \MicroChaos_Constants::DEFAULT_REQUEST_TIMEOUT);
    }

    #[Test]
    public function timeout_can_be_configured(): void
    {
        $generator = new \MicroChaos_Request_Generator(['timeout' => 60]);

        $this->assertSame(60, $generator->get_timeout());
    }

    #[Test]
    public function a_numeric_string_timeout_is_accepted(): void
    {
        // WP-CLI hands over argument values as strings.
        $generator = new \MicroChaos_Request_Generator(['timeout' => '45']);

        $this->assertSame(45, $generator->get_timeout());
    }

    #[Test]
    public function a_nonsensical_timeout_falls_back_to_one_second(): void
    {
        // Zero would mean "wait forever" to cURL, which is a worse failure than
        // a short wait: a hung endpoint would stall the run indefinitely.
        foreach ([0, -5] as $value) {
            $generator = new \MicroChaos_Request_Generator(['timeout' => $value]);
            $this->assertSame(1, $generator->get_timeout());
        }
    }

    #[Test]
    public function cache_header_collection_still_works_alongside_a_timeout(): void
    {
        $generator = new \MicroChaos_Request_Generator([
            'collect_cache_headers' => true,
            'timeout' => 20,
        ]);

        $this->assertSame(20, $generator->get_timeout());
        $this->assertSame([], $generator->get_cache_headers());
    }

    // =========================================================================
    // Failure classification
    // =========================================================================

    #[Test]
    public function a_curl_timeout_is_labelled_as_a_timeout(): void
    {
        $this->assertSame(
            \MicroChaos_Request_Generator::STATUS_TIMEOUT,
            $this->classify('classify_curl_failure', CURLE_OPERATION_TIMEOUTED)
        );
    }

    #[Test]
    public function other_curl_failures_stay_generic_errors(): void
    {
        // A refused connection or an unresolvable host is a different finding
        // from a slow response and must not be reported as one.
        foreach ([CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST] as $errno) {
            $this->assertSame(
                \MicroChaos_Request_Generator::STATUS_ERROR,
                $this->classify('classify_curl_failure', $errno)
            );
        }
    }

    #[Test]
    public function a_wp_error_mentioning_a_timeout_is_labelled_as_a_timeout(): void
    {
        // Both transports phrase it differently but share the substring.
        $messages = [
            'Operation timed out after 30001 milliseconds with 0 bytes received',
            'Connection timed out',
        ];

        foreach ($messages as $message) {
            $this->assertSame(
                \MicroChaos_Request_Generator::STATUS_TIMEOUT,
                $this->classify('classify_wp_error', new \WP_Error('http_request_failed', $message))
            );
        }
    }

    #[Test]
    public function other_wp_errors_stay_generic_errors(): void
    {
        $error = new \WP_Error('http_request_failed', 'Could not resolve host: example.test');

        $this->assertSame(
            \MicroChaos_Request_Generator::STATUS_ERROR,
            $this->classify('classify_wp_error', $error)
        );
    }

    #[Test]
    public function timeout_and_error_are_distinguishable_sentinels(): void
    {
        $this->assertNotSame(
            \MicroChaos_Request_Generator::STATUS_TIMEOUT,
            \MicroChaos_Request_Generator::STATUS_ERROR
        );
    }

    #[Test]
    public function neither_sentinel_is_numeric(): void
    {
        // The reporting engine decides success by numeric range, so both
        // sentinels have to fall outside it.
        $this->assertFalse(is_numeric(\MicroChaos_Request_Generator::STATUS_TIMEOUT));
        $this->assertFalse(is_numeric(\MicroChaos_Request_Generator::STATUS_ERROR));
    }
}
