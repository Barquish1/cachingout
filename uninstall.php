<?php
/**
 * Uninstall handler — runs when the plugin is deleted from the Plugins screen.
 *
 * WordPress only includes this file when the operator confirms Delete after
 * deactivation. It must not run on deactivate alone.
 *
 * @package CachingOut
 * @since 0.1.12
 */

// If uninstall.php is not called by WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Ensure ABSPATH is defined (WordPress always defines it before include).
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CACHINGOUT_PATH' ) ) {
	define( 'CACHINGOUT_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'CACHINGOUT_FILE' ) ) {
	define( 'CACHINGOUT_FILE', __FILE__ );
}

require_once CACHINGOUT_PATH . 'includes/class-uninstaller.php';

try {
	CachingOut_Uninstaller::run();
} catch ( \Throwable $e ) {
	if ( function_exists( 'error_log' ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
		error_log( 'Caching Out uninstall: ' . $e->getMessage() );
	}
}
