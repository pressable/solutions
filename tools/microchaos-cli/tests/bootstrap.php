<?php
/**
 * PHPUnit Bootstrap for MicroChaos CLI
 *
 * Loads MicroChaos classes without WordPress runtime.
 * Provides minimal stubs for WordPress functions used by testable components.
 */

// Define constants to allow file loading (bypasses direct access guards)
define('WP_CLI', true);
define('ABSPATH', '/tmp/fake-wordpress/');
define('WP_CONTENT_DIR', ABSPATH . 'wp-content');

// Define path constants that bootstrap.php normally sets
define('MICROCHAOS_VERSION', '3.0.0-test');
define('MICROCHAOS_PATH', dirname(__DIR__) . '/microchaos');
define('MICROCHAOS_CORE_PATH', MICROCHAOS_PATH . '/core');

/**
 * WordPress Stubs
 *
 * Minimal implementations of WordPress functions used by testable components.
 * These are NOT meant to replicate WordPress behavior - just prevent fatal errors.
 */

// Transient stubs (used by Thresholds save/load)
function set_transient(string $key, mixed $value, int $expiry = 0): bool {
    return true; // Always succeeds in tests
}

function get_transient(string $key): mixed {
    return false; // Always returns "not found" in tests
}

/**
 * Simplified stand-in for add_query_arg()
 *
 * Covers the single key/value/url form the orchestrator uses, including the
 * fragment handling that makes naive concatenation wrong. It does not replace
 * an existing key the way core does.
 */
function add_query_arg(string $key, string $value, string $url): string {
    $fragment = '';
    $hash = strpos($url, '#');
    if (false !== $hash) {
        $fragment = substr($url, $hash);
        $url = substr($url, 0, $hash);
    }

    $separator = str_contains($url, '?') ? '&' : '?';

    return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value) . $fragment;
}

/**
 * Copy of wp_generate_uuid4() from wp-includes/functions.php
 */
function wp_generate_uuid4(): string {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

/**
 * Load MicroChaos Components
 *
 * Order matters - matches bootstrap.php loading sequence.
 *
 * Everything testable without a WordPress runtime is loaded here rather than
 * per-branch, so a new test file never has to touch this file and concurrent
 * work does not collide in it.
 */

// Core constants (other components may reference these)
require_once MICROCHAOS_CORE_PATH . '/constants.php';

// Interfaces (load before implementations)
require_once MICROCHAOS_CORE_PATH . '/interfaces/logger.php';
require_once MICROCHAOS_CORE_PATH . '/interfaces/baseline-storage.php';

// Logging infrastructure (needed for components that log)
require_once MICROCHAOS_CORE_PATH . '/log.php';
require_once MICROCHAOS_CORE_PATH . '/logging/null-logger.php';

// Storage (default baseline storage for the reporting engine and monitor)
require_once MICROCHAOS_CORE_PATH . '/storage/transient-baseline-storage.php';

// Components under test
require_once MICROCHAOS_CORE_PATH . '/thresholds.php';
require_once MICROCHAOS_CORE_PATH . '/cache-analyzer.php';
require_once MICROCHAOS_CORE_PATH . '/authentication-manager.php';
require_once MICROCHAOS_CORE_PATH . '/resource-monitor.php';
require_once MICROCHAOS_CORE_PATH . '/reporting-engine.php';
require_once MICROCHAOS_CORE_PATH . '/request-generator.php';
require_once MICROCHAOS_CORE_PATH . '/orchestrators/loadtest-orchestrator.php';

// Reporting engine: results aggregation is pure PHP, so summary classification
// can be tested by feeding it result rows directly.
require_once MICROCHAOS_CORE_PATH . '/storage/transient-baseline-storage.php';
require_once MICROCHAOS_CORE_PATH . '/reporting-engine.php';

/**
 * WordPress Stubs - Classes
 *
 * Simple stub classes for WordPress types used by testable components.
 */

/**
 * Stub for WP_Http_Cookie
 *
 * AuthManager's format_for_curl() expects objects with name/value properties.
 */
class WP_Http_Cookie {
    public string $name;
    public string $value;

    public function __construct(string $name, string $value) {
        $this->name = $name;
        $this->value = $value;
    }
}

/**
 * Stub for WP_Error
 *
 * Only the message is exercised: WordPress gives every transport failure the
 * same error code, so callers that need to tell one apart from another have to
 * read the message.
 */
class WP_Error {
    private string $code;
    private string $message;

    public function __construct(string $code = '', string $message = '') {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code(): string {
        return $this->code;
    }

    public function get_error_message(): string {
        return $this->message;
    }
}

/**
 * Initialize Test Logger
 *
 * Use Null_Logger so components can log without WP-CLI dependency.
 */
MicroChaos_Log::set_logger(new MicroChaos_Null_Logger());
