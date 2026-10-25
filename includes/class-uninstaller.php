<?php
/**
 * Full uninstall scrub — runs only when the plugin is deleted from wp-admin.
 *
 * Deactivate stays minimal (drop-in + crons) per BUG-008 / BUG-010. Delete is
 * the only path that may edit wp-config.php / .htaccess and wipe options,
 * because the operator has explicitly chosen to remove the plugin.
 *
 * @package CachingOut
 * @since 0.1.12
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin uninstaller.
 *
 * All methods are static. Safe to call from uninstall.php or unit tests.
 * Every filesystem step uses the same backup + sanity guards as the activator.
 *
 * @since 0.1.12
 */
class CachingOut_Uninstaller {

	/**
	 * Known option keys owned by this plugin.
	 *
	 * @since 0.1.12
	 * @var string[]
	 */
	const OPTION_KEYS = array(
		'cachingout_settings',
		'cachingout_activation_report',
		'cachingout_installed_version',
		// 1.21.3: Minify CSS's minified-sources manifest.
		'cachingout_minified_sources',
		'cachingout_setup_wizard_completed',
		'cachingout_insights_history',
		'cachingout_dropin_hash',
		'cachingout_dropin_owner',
		'cachingout_page_cache_mode',
		// The licence, the preload queue, the Smart Config log, the change
		// record fallbacks and the switched-on state belong to Caching Out
		// Pro since 1.21.0 (spec 30) and are removed by its own uninstall.
	);

	/**
	 * Exact transient keys owned by this plugin.
	 *
	 * @since 0.1.12
	 * @var string[]
	 */
	const TRANSIENT_KEYS = array(
		'cachingout_activation_redirect',
		'cachingout_browser_cache_error',
	);

	/**
	 * Transient name prefixes (delete matching rows from options table).
	 *
	 * @since 0.1.12
	 * @var string[]
	 */
	const TRANSIENT_PREFIXES = array(
		'cachingout_page_audit_',
	);

	/**
	 * User-meta keys owned by this plugin.
	 *
	 * @since 0.1.12
	 * @var string[]
	 */
	const USER_META_KEYS = array(
		'cachingout_object_cache_notice_dismissed',
	);

	/**
	 * .htaccess marker names to remove (exact BEGIN/END text).
	 *
	 * Longer browser-cache markers first is unnecessary when each marker is
	 * passed to update_htaccess_block (EOL-anchored), but listed explicitly.
	 *
	 * @since 0.1.12
	 * @var string[]
	 */
	const HTACCESS_MARKERS = array(
		'WP Caching Out Expires',
		'WP Caching Out GZIP',
		'WP Caching Out Brotli',
		'WP Caching Out',
	);

	/**
	 * Run the full uninstall sequence.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function run(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			if ( is_array( $site_ids ) ) {
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					self::uninstall_current_site();
					restore_current_blog();
				}
			}
			// Network-scoped leftovers (none today; belt-and-braces).
			self::delete_options();
			self::delete_transients();
			self::clear_crons();
			return;
		}

		self::uninstall_current_site();
	}

	/**
	 * Scrub the current blog (or single site).
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function uninstall_current_site(): void {
		self::remove_dropin();
		self::delete_cache_directory();
		self::clear_crons();
		// Server-config edits first: they read the settings and the drop-in
		// hash, which the option sweep below removes.
		self::remove_htaccess_blocks();
		self::remove_wp_cache_constant();
		self::delete_options();
		self::delete_transients();
		self::delete_user_meta();
	}

	/**
	 * Drop the change audit log table (spec 26). The table belongs to
	 * Caching Out Pro since 1.21.0 (spec 30): its own uninstall drops it,
	 * and this helper acts only when the Pro store class is already loaded.
	 * It is no longer part of the free uninstall sequence.
	 *
	 * @since 0.1.18
	 * @return void
	 */
	public static function drop_audit_table(): void {
		try {
			if ( class_exists( 'CachingOut_Audit_Store' ) ) {
				( new \CachingOut_Audit_Store() )->drop();
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out uninstall audit table: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Delete our advanced-cache.php drop-in if present.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function remove_dropin(): void {
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
		if ( ! \CachingOut_Dropin_Owner::is_ours() ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out uninstall: advanced-cache.php hash mismatch; left in place.' );
			}
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes the plugin's own advanced-cache.php drop-in, and only after its hash matched ours. The result decides whether the stored hash is kept.
		if ( @unlink( $path ) ) {
			delete_option( 'cachingout_dropin_hash' );
		} elseif ( function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
			error_log( 'Caching Out uninstall: advanced-cache.php could not be deleted; hash kept.' );
		}
	}

	/**
	 * Recursively delete wp-content/cache/cachingout/.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function delete_cache_directory(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}

		// The literal of CachingOut_Settings::cache_dir(): uninstall.php
		// loads only this class.
		$dir = WP_CONTENT_DIR . '/cache/cachingout';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		self::rrmdir( $dir );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @since 0.1.12
	 *
	 * @param string $dir Absolute directory path.
	 * @return void
	 */
	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		try {
			$iterator = new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS );
			$files    = new \RecursiveIteratorIterator( $iterator, \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				$path = $file->getPathname();
				if ( $file->isDir() ) {
					@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the plugin's own cache directory tree on uninstall.
				} else {
					wp_delete_file( $path );
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Best-effort; continue uninstall.
		}

		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes the plugin's own cache directory on uninstall.
	}

	/**
	 * Clear every cron hook whose name starts with cachingout_.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function clear_crons(): void {
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
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_string( $hook ) || 0 !== strpos( $hook, 'cachingout_' ) ) {
					continue;
				}
				if ( ! is_array( $events ) ) {
					wp_unschedule_event( (int) $timestamp, $hook );
					continue;
				}
				foreach ( $events as $event ) {
					$args = ( is_array( $event ) && isset( $event['args'] ) && is_array( $event['args'] ) )
						? $event['args']
						: array();
					wp_unschedule_event( (int) $timestamp, $hook, $args );
				}
			}
		}
	}

	/**
	 * Delete known plugin options.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function delete_options(): void {
		foreach ( self::OPTION_KEYS as $key ) {
			delete_option( $key );
			if ( function_exists( 'delete_site_option' ) ) {
				delete_site_option( $key );
			}
		}
	}

	/**
	 * Delete known and prefixed transients.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function delete_transients(): void {
		foreach ( self::TRANSIENT_KEYS as $key ) {
			delete_transient( $key );
			if ( function_exists( 'delete_site_transient' ) ) {
				delete_site_transient( $key );
			}
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			$like_timeout = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
			$like_value   = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders used below.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$like_timeout,
					$like_value
				)
			);

			if ( is_multisite() && isset( $wpdb->sitemeta ) ) {
				$like_st_timeout = $wpdb->esc_like( '_site_transient_timeout_' . $prefix ) . '%';
				$like_st_value   = $wpdb->esc_like( '_site_transient_' . $prefix ) . '%';
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
						$like_st_timeout,
						$like_st_value
					)
				);
			}
		}
		// phpcs:enable
	}

	/**
	 * Delete plugin user-meta keys for all users.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function delete_user_meta(): void {
		foreach ( self::USER_META_KEYS as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	/**
	 * Remove all Caching Out marker blocks from .htaccess.
	 *
	 * Uses activator helpers (backup + sanity check). Failures are swallowed
	 * so a partial scrub still removes options and the drop-in.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function remove_htaccess_blocks(): void {
		self::load_activator();

		if ( ! class_exists( 'CachingOut_Activator' ) ) {
			return;
		}

		foreach ( self::HTACCESS_MARKERS as $marker ) {
			try {
				CachingOut_Activator::update_htaccess_block( $marker, null );
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
					error_log( 'Caching Out uninstall htaccess: ' . $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Strip our WP_CACHE define from wp-config.php via the safe line walker.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	public static function remove_wp_cache_constant(): void {
		self::load_activator();

		if ( ! class_exists( 'CachingOut_Activator' ) ) {
			return;
		}

		try {
			// false = remove. Activator backs up, strips line-scoped only, sanity-checks.
			CachingOut_Activator::set_wp_cache_constant( false );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out uninstall wp-config: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Load the activator class if needed.
	 *
	 * @since 0.1.12
	 * @return void
	 */
	private static function load_activator(): void {
		if ( class_exists( 'CachingOut_Activator' ) ) {
			return;
		}

		$path = self::plugin_path() . 'includes/class-activator.php';
		if ( is_string( $path ) && file_exists( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Load the drop-in ownership guard class if needed.
	 *
	 * @since 0.1.14
	 * @return void
	 */
	private static function load_dropin_owner(): void {
		if ( class_exists( 'CachingOut_Dropin_Owner' ) ) {
			return;
		}

		$path = self::plugin_path() . 'includes/cache/class-dropin-owner.php';
		if ( is_string( $path ) && file_exists( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Absolute path to the plugin root with trailing slash.
	 *
	 * @since 0.1.12
	 * @return string
	 */
	private static function plugin_path(): string {
		if ( defined( 'CACHINGOUT_PATH' ) ) {
			return CACHINGOUT_PATH;
		}

		return dirname( __DIR__ ) . '/';
	}
}
