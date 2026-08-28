<?php
/**
 * MicroChaos Bootstrap Loader
 *
 * Handles component loading and initialization for MicroChaos.
 * Implements hybrid mu-plugin architecture with bootstrap pattern.
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

if (!function_exists('microchaos_read_plugin_version')) {
    /**
     * Read the canonical version from the plugin header.
     *
     * The Version line in microchaos-cli.php is the single source of truth, so the
     * constant is derived from it rather than kept as a second copy that can drift.
     *
     * @param string $plugin_file Absolute path to the main plugin file.
     * @return string Version string, or 'unknown' if the header cannot be read.
     */
    function microchaos_read_plugin_version($plugin_file) {
        if (function_exists('get_file_data')) {
            $headers = get_file_data($plugin_file, ['Version' => 'Version']);

            if (!empty($headers['Version'])) {
                return $headers['Version'];
            }
        }

        return 'unknown';
    }
}

// Define constants
define('MICROCHAOS_PATH', dirname(__FILE__));
define('MICROCHAOS_CORE_PATH', MICROCHAOS_PATH . '/core');

if (!defined('MICROCHAOS_VERSION')) {
    define('MICROCHAOS_VERSION', microchaos_read_plugin_version(dirname(MICROCHAOS_PATH) . '/microchaos-cli.php'));
}

/**
 * Bootstrap class for MicroChaos
 */
class MicroChaos_Bootstrap {
    /**
     * Initialize the bootstrap process
     */
    public static function init() {
        // Load core components
        self::load_core_components();

        // Initialize components based on context
        if (defined('WP_CLI') && WP_CLI) {
            self::init_cli_components();
        }

        // Future: Admin components will be loaded here
        // if (is_admin()) {
        //     self::init_admin_components();
        // }
    }

    /**
     * Load core component files
     */
    private static function load_core_components() {
        // Load constants first
        require_once MICROCHAOS_CORE_PATH . '/constants.php';

        // Load the test-mode descriptor before anything that reports a run
        require_once MICROCHAOS_CORE_PATH . '/test-mode.php';

        // Load interfaces (order matters - logger before components that use it)
        require_once MICROCHAOS_CORE_PATH . '/interfaces/logger.php';
        require_once MICROCHAOS_CORE_PATH . '/interfaces/baseline-storage.php';

        // Load logging infrastructure (before any component that logs)
        require_once MICROCHAOS_CORE_PATH . '/log.php';
        require_once MICROCHAOS_CORE_PATH . '/logging/wp-cli-logger.php';
        require_once MICROCHAOS_CORE_PATH . '/logging/null-logger.php';

        // Load storage implementations
        require_once MICROCHAOS_CORE_PATH . '/storage/transient-baseline-storage.php';

        // Load authentication manager (before commands that use it)
        require_once MICROCHAOS_CORE_PATH . '/authentication-manager.php';

        // Load orchestrators (before commands that depend on them)
        require_once MICROCHAOS_CORE_PATH . '/orchestrators/loadtest-orchestrator.php';

        // Load core components
        $core_components = [
            'thresholds.php',
            'integration-logger.php',
            'request-generator.php',
            'cache-analyzer.php',
            'resource-monitor.php',
            'reporting-engine.php',
            'concurrency-runner.php',
            'commands.php',  // Must be last - depends on orchestrators
        ];

        foreach ($core_components as $component) {
            $file_path = MICROCHAOS_CORE_PATH . '/' . $component;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }

    /**
     * Initialize CLI components
     */
    private static function init_cli_components() {
        // Initialize the WP-CLI logger
        if (class_exists('MicroChaos_Log') && class_exists('MicroChaos_WP_CLI_Logger')) {
            MicroChaos_Log::set_logger(new MicroChaos_WP_CLI_Logger());
        }

        // Register WP-CLI commands
        if (class_exists('MicroChaos_Commands')) {
            MicroChaos_Commands::register();
        }
    }

    /**
     * Future: Initialize admin components
     */
    private static function init_admin_components() {
        // To be implemented in Phase 2
    }
}

// Initialize the bootstrap
MicroChaos_Bootstrap::init();
