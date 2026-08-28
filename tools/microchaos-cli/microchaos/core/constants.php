<?php
/**
 * MicroChaos Constants
 *
 * Centralized constants for MicroChaos CLI configuration values.
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

/**
 * MicroChaos Constants class
 */
class MicroChaos_Constants {
    /**
     * Default baseline storage TTL (30 days in seconds)
     */
    const BASELINE_TTL = 2592000; // 30 * 24 * 60 * 60

    /**
     * Default timeout for parallel test execution (10 minutes in seconds)
     */
    const DEFAULT_PARALLEL_TIMEOUT = 600;

    /**
     * Default per-request timeout in seconds
     *
     * Every abandoned request is a censored measurement: its real duration is
     * unknown and it leaves the timing distribution as an error. Set well above
     * any response you would consider acceptable, so the cutoff is a backstop
     * rather than something a struggling site hits routinely.
     */
    const DEFAULT_REQUEST_TIMEOUT = 30;

    /**
     * Default number of parallel workers
     */
    const DEFAULT_WORKERS = 3;

    /**
     * Default --concurrency. 1 is the sequential path; nothing fans out.
     */
    const DEFAULT_CONCURRENCY = 1;

    /**
     * Hard cap on --concurrency. Staging boxes are 5 workers; past 8 the
     * generators themselves start to be the thing under test.
     */
    const MAX_CONCURRENCY = 8;

    /**
     * Time conversion constants
     */
    const SECONDS_PER_MINUTE = 60;
    const SECONDS_PER_HOUR = 3600;
    const SECONDS_PER_DAY = 86400;

    /**
     * HTTP status codes (common)
     */
    const HTTP_OK = 200;
    const HTTP_NOT_FOUND = 404;
    const HTTP_SERVER_ERROR = 500;
}
