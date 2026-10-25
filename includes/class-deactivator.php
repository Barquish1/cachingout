<?php
/**
 * Plugin deactivator.
 *
 * BUG-010 (2026-08-21): Any .htaccess or multi-step cleanup on deactivate
 * took orgahair.com to a WordPress critical error even when wp-config was
 * left untouched. Until the exact server-config fault is isolated on a
 * staging host, deactivation does ONE thing only:
 *
 *   delete wp-content/advanced-cache.php (if it is ours)
 *
 * WordPress only loads the drop-in when that file exists, so WP_CACHE may
 * remain true harmlessly. .htaccess browser-cache blocks and cron cleanup
 * are deferred to uninstall / a future explicit tool — never deactivate.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin deactivator.
 *
 * @since 0.1.0
 */
class CachingOut_Deactivator {

	/**
	 * Run deactivation.
	 *
	 * Minimal by design (BUG-010). Must not touch wp-config.php or .htaccess.
	 *
	 * @since 0.1.0
	 * @return true
	 */
	public static function deactivate() {
		try {
			self::remove_dropin_only();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out deactivate: ' . $e->getMessage() );
			}
		}

		// Best-effort cron clear — never touches server config files.
		try {
			self::clear_our_crons_only();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out deactivate cron: ' . $e->getMessage() );
			}
		}

		return true;
	}

	/**
	 * Delete our advanced-cache.php drop-in only.
	 *
	 * @return void
	 */
	private static function remove_dropin_only(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}

		self::load_dropin_owner();
		if ( ! class_exists( 'CachingOut_Dropin_Owner' ) ) {
			return;
		}

		$path = \CachingOut_Dropin_Owner::dropin_path();
		if ( ! file_exists( $path ) ) {
			return;
		}

		// Only delete if the on-disk file still matches our stamped hash.
		// If it does not, another plugin has replaced it since activation —
		// leave it alone and log the fact.
		if ( ! \CachingOut_Dropin_Owner::is_ours() ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out deactivate: advanced-cache.php hash mismatch; left in place.' );
			}
			return;
		}

		// Forget the hash only once the file is gone. A drop-in that stays on
		// disk after a refused unlink must still be recognised as ours by the
		// next activation (and by uninstall); without the hash it would read
		// as foreign and be left in place for good.
		if ( ! static::unlink_dropin( $path ) ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out deactivate: advanced-cache.php could not be deleted; hash kept.' );
			}
			return;
		}

		delete_option( 'cachingout_dropin_hash' );
	}

	/**
	 * Delete the drop-in file.
	 *
	 * Overridable (late static binding) so a test can simulate a refused
	 * unlink without touching filesystem permissions.
	 *
	 * @since 1.21.1
	 *
	 * @param string $path Absolute path to advanced-cache.php.
	 * @return bool True when the file was deleted.
	 */
	protected static function unlink_dropin( string $path ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes the plugin's own advanced-cache.php drop-in, and only after its hash matched ours. The result decides whether the stored hash is kept.
		return (bool) @unlink( $path );
	}

	/**
	 * Load the drop-in ownership guard class if it is not already loaded.
	 *
	 * @return void
	 */
	private static function load_dropin_owner(): void {
		if ( class_exists( 'CachingOut_Dropin_Owner' ) ) {
			return;
		}

		if ( defined( 'CACHINGOUT_PATH' ) ) {
			require_once CACHINGOUT_PATH . 'includes/cache/class-dropin-owner.php';
		}
	}

	/**
	 * Clear cachingout_* cron events without loading other plugin classes.
	 *
	 * @return void
	 */
	private static function clear_our_crons_only(): void {
		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			return;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( array_keys( $hooks ) as $hook ) {
				if ( is_string( $hook ) && 0 === strpos( $hook, 'cachingout_' ) ) {
					wp_unschedule_event( (int) $timestamp, $hook );
				}
			}
		}

		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'cachingout_preload_queue' );
		}
	}
}
