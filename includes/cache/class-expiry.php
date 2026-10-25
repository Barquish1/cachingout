<?php
/**
 * Cron-based cache expiry — sweeps cache files older than the configured lifespan.
 *
 * A WP-Cron event runs at the configured interval (default: 0, never expire).
 * The sweep walks the cache directory recursively, deletes any .html file
 * whose mtime is older than the lifespan, and removes empty directories.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache expiry — cron-based sweep of expired cache files.
 *
 * @since 0.1.0
 */
class CachingOut_Expiry {

	/**
	 * Cron hook name for the expiry sweep.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const CRON_HOOK = 'cachingout_cache_expiry_sweep';

	/**
	 * Custom cron schedule name.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SCHEDULE_NAME = 'cachingout_every_lifespan';

	/**
	 * Settings manager.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 * @param CachingOut_Settings $settings Settings manager instance.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Schedule the cron event based on the configured lifespan.
	 *
	 * Registers a custom cron schedule at the lifespan interval and schedules
	 * the sweep hook. When the lifespan is 0 (never expire), no event is
	 * scheduled — this is the safe default.
	 *
	 * Called once on activation or when the lifespan setting changes.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function schedule(): void {
		// Stored as hours; cron interval uses seconds.
		$lifespan = CachingOut_Settings::lifespan_to_seconds(
			(int) $this->settings->get( 'cache', 'lifespan', 0 )
		);

		if ( $lifespan <= 0 ) {
			// Zero or negative lifespan means never expire — unschedule any
			// existing event and do not schedule a new one.
			$this->unschedule();
			return;
		}

		// Register the custom schedule if not already registered.
		if ( ! isset( wp_get_schedules()[ self::SCHEDULE_NAME ] ) ) {
			add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		}

		// Schedule the sweep event if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + $lifespan, self::SCHEDULE_NAME, self::CRON_HOOK );
		}
	}


	/**
	 * Add the custom cron schedule for the lifespan interval.
	 *
	 * @since 0.1.0
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	public function add_cron_schedule( array $schedules ): array {
		$lifespan = CachingOut_Settings::lifespan_to_seconds(
			(int) $this->settings->get( 'cache', 'lifespan', 0 )
		);


		$schedules[ self::SCHEDULE_NAME ] = array(
			'interval' => $lifespan,
			/* translators: %d: number of seconds */
			'display'  => sprintf( __( 'Every %d seconds (Caching Out expiry)', 'cachingout' ), $lifespan ),
		);

		return $schedules;
	}

	/**
	 * Run the expiry sweep.
	 *
	 * Walks `wp-content/cache/cachingout/` recursively, deletes every
	 * .html cache file whose mtime is older than the configured lifespan,
	 * then removes empty directories.
	 *
	 * When the lifespan is 0 (never expire), this is a no-op.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function sweep(): void {
		$lifespan = CachingOut_Settings::lifespan_to_seconds(
			(int) $this->settings->get( 'cache', 'lifespan', 0 )
		);

		if ( $lifespan <= 0 ) {
			return;
		}

		$root = CachingOut_Page_Cache::cache_dir();

		if ( ! is_dir( $root ) ) {
			return;
		}

		$now       = time();
		$cutoff    = $now - $lifespan;

		$deleted   = 0;
		$kept_dirs = array();

		// Walk the cache tree recursively.
		$iterator = new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS );
		$files    = new \RecursiveIteratorIterator( $iterator, \RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $files as $entry ) {
			$path = $entry->getPathname();

			// With CHILD_FIRST, directories are visited after their children.
			// Do not pre-add directories to kept_dirs — only directories that
			// contain surviving files must be preserved.
			if ( $entry->isDir() ) {
				continue;
			}

			// Only process .html files.
			if ( 'html' !== $entry->getExtension() ) {
				// Non-html files (guard files, etc.) — keep parent directory.
				$parent = dirname( $path );
				if ( ! in_array( $parent, $kept_dirs, true ) ) {
					$kept_dirs[] = $parent;
				}
				continue;
			}

			$mtime = $entry->getMTime();
			if ( false !== $mtime && $mtime < $cutoff ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
				if ( @unlink( $path ) ) {
					$deleted++;
				} else {
					// Could not delete — keep the parent directory.
					$parent = dirname( $path );
					if ( ! in_array( $parent, $kept_dirs, true ) ) {
						$kept_dirs[] = $parent;
					}
				}
			} elseif ( false !== $mtime ) {
				// File is still fresh — keep its parent directory.
				$parent = dirname( $path );
				if ( ! in_array( $parent, $kept_dirs, true ) ) {
					$kept_dirs[] = $parent;
				}
			}
		}

		// Remove empty directories that are not in the kept list.
		$this->remove_empty_dirs( $root, $kept_dirs );
	}

	/**
	 * Clear the scheduled cron event.
	 *
	 * Called on deactivation or when the lifespan is set to 0.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Get the cache filesystem root directory.
	 *
	 * Delegates to CachingOut_Page_Cache::cache_dir() for consistency.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to wp-content/cache/cachingout/.
	 */
	public static function cache_dir(): string {
		return CachingOut_Page_Cache::cache_dir();
	}

	/**
	 * Remove empty directories under the cache root, except those in the
	 * kept list and those containing kept files.
	 *
	 * Walks the tree bottom-up; directories that contain nothing after
	 * expired-file deletion are removed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root      Cache root directory.
	 * @param array  $kept_dirs Paths of directories that must not be removed.
	 * @return void
	 */
	private function remove_empty_dirs( string $root, array $kept_dirs ): void {
		$root = rtrim( $root, DIRECTORY_SEPARATOR );

		// Build a set of kept directory paths for fast lookup.
		$kept_set = array_flip( $kept_dirs );

		// Walk bottom-up.
		$iterator  = new \RecursiveDirectoryIterator( $root, \RecursiveDirectoryIterator::SKIP_DOTS );
		$recursive = new \RecursiveIteratorIterator( $iterator, \RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $recursive as $entry ) {
			if ( ! $entry->isDir() ) {
				continue;
			}

			$path = rtrim( $entry->getPathname(), DIRECTORY_SEPARATOR );

			// Never remove the cache root itself.
			if ( $path === $root ) {
				continue;
			}

			// Skip directories in the kept set.
			if ( isset( $kept_set[ $path ] ) ) {
				continue;
			}

			// Skip if a kept directory is a descendant of this path
			// (i.e. this directory has a kept child somewhere below it).
			$has_kept_descendant = false;
			foreach ( $kept_dirs as $kept ) {
				$kept_parent = dirname( $kept );
				while ( $kept_parent !== $root && '' !== $kept_parent && dirname( $kept_parent ) !== $kept_parent ) {
					$kept_parent = rtrim( $kept_parent, DIRECTORY_SEPARATOR );
					if ( $kept_parent === $path ) {
						$has_kept_descendant = true;
						break 2;
					}
					$kept_parent = dirname( $kept_parent );
				}
			}

			if ( $has_kept_descendant ) {
				continue;
			}

			// Check if the directory is actually empty.
			$contents = @scandir( $path );
			if ( false === $contents ) {
				continue;
			}

			$files = array_filter( $contents, function ( $entry ) {
				return '.' !== $entry && '..' !== $entry;
			} );

			if ( empty( $files ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
				@rmdir( $path );
			}
		}
	}
}