<?php
/**
 * Plugin Name: MicroChaos CLI Load Tester
 * Description: Internal WP-CLI based WordPress load tester for staging environments where
 * external load testing is restricted (like Pressable).
 * Version: 4.2.1
 * Author: Phill
 * License: GPL-3.0-or-later
 *
 * The Version line above is the single source of truth for both the modular and
 * bundled distributions, which parse it rather than declaring their own copy.
 * Bump it here and nowhere else.
 */

// Bootstrap MicroChaos components

$microchaos_bootstrap = dirname(__FILE__) . '/microchaos/bootstrap.php';

if (file_exists($microchaos_bootstrap)) {
    require_once $microchaos_bootstrap;
} elseif (defined('WP_CLI') && WP_CLI) {
    // Fail loudly rather than silently. Until 4.2.1 a missing bootstrap fell
    // through to a legacy command class kept here for backward compatibility,
    // which still carried the pre-4.1.0 defects: wp_set_auth_cookie() that does
    // nothing under the CLI SAPI, 3xx scored as errors, a hardcoded 10s timeout,
    // and no --cache-bust or --concurrency. A broken install therefore produced
    // confident wrong numbers instead of an error. It is better to have no
    // command than a command that lies.
    \WP_CLI::error(
        'MicroChaos: microchaos/bootstrap.php is missing, so no commands were registered. '
        . 'Reinstall the plugin directory intact, or install the single-file build from dist/.'
    );
}
