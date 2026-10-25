<?php
/**
 * Page cache — writes and serves static HTML cache files.
 *
 * No cacheability decisions are made here. This class assumes the chokepoint
 * (`CachingOut_Cacheability`) has already cleared the request. It converts
 * cache keys into filesystem I/O: atomic writes, serving cached files with
 * headers, single-key deletion, and full-cache purges.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page cache reader and writer.
 *
 * @since 0.1.0
 */
class CachingOut_Page_Cache {

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
	 * Serve the cached file to the browser and exit.
	 *
	 * Called from advanced-cache.php when a cache hit is confirmed. Outputs
	 * the cached HTML with correct headers and terminates execution.
	 *
	 * If the cache file does not exist (e.g. it was purged between the
	 * existence check and the read), this method returns silently and lets
	 * WordPress generate the page normally — producing a MISS on the next
	 * cacheable request.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Cache_Key $key Cache key for the request.
	 * @return void
	 */
	public function serve( CachingOut_Cache_Key $key ): void {
		$file = $key->get_cache_file();

		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return;
		}

		// Lifespan is stored in hours; compare using seconds.
		$lifespan = CachingOut_Settings::lifespan_to_seconds(
			(int) $this->settings->get( 'cache', 'lifespan', 0 )
		);
		if ( $lifespan > 0 ) {
			$mtime = filemtime( $file );
			if ( false !== $mtime && ( time() - $mtime ) > $lifespan ) {

				// Expired — delete and let WordPress regenerate.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
				@unlink( $file );
				return;
			}
		}

		// Send headers.
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Length: ' . filesize( $file ) );

		$mtime = filemtime( $file );
		if ( false !== $mtime ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		}

		// Variant negotiation headers: tell intermediaries which request
		// headers this response varies on so a proxy/CDN never caches one
		// variant for all visitors.
		$vary = array( 'Cookie' );
		if ( $this->settings->get( 'cache', 'separate_mobile_cache', false ) ) {
			$vary[] = 'User-Agent';
		}
		if ( $this->settings->get( 'media', 'webp_cache_enabled', false ) ) {
			$vary[] = 'Accept';
		}
		header( 'Vary: ' . implode( ', ', $vary ) );
		header( 'Cache-Control: max-age=0, must-revalidate' );

		// Debug header.
		if ( defined( 'CACHINGOUT_DEBUG' ) && CACHINGOUT_DEBUG ) {
			header( 'X-CachingOut-Cache: HIT' );
		}

		// Output the cached file and stop.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Sends the cached page from the advanced-cache.php drop-in, before WordPress and WP_Filesystem are loaded.
		readfile( $file );
		exit;
	}

	/**
	 * Write response body to the cache file atomically.
	 *
	 * Writes to a temporary file in the same directory, then renames it to
	 * the final path. This guarantees that a half-written cache file is never
	 * served to a visitor.
	 *
	 * Creates the directory tree if it does not exist. On any failure, logs
	 * the error and cleans up the temporary file — no partial cache file is
	 * left behind.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Cache_Key $key  Cache key for the request.
	 * @param string              $html Full page HTML to cache.
	 * @return bool True on success, false on failure.
	 */
	public function write( CachingOut_Cache_Key $key, string $html ): bool {
		$dir  = $key->get_cache_path();
		$file = $key->get_cache_file();

		// Ensure the directory tree exists.
		if ( ! $this->ensure_directory( $dir ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
			error_log( 'CachingOut: Failed to create cache directory: ' . $dir );
			return false;
		}

		// Ensure guard files exist in the cache root.
		$this->ensure_guard_files();

		// Write to a temporary file in the same directory.
		$temp = $this->temp_file( $dir );

		if ( false === file_put_contents( $temp, $html, LOCK_EX ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
			error_log( 'CachingOut: Failed to write temp cache file: ' . $temp );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
			@unlink( $temp );
			return false;
		}

		// Atomic rename.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace: temp file in the same directory, then rename(). WP_Filesystem::move() is not atomic.
		if ( ! @rename( $temp, $file ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
			error_log( 'CachingOut: Failed to rename temp cache file: ' . $temp . ' -> ' . $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
			@unlink( $temp );
			return false;
		}

		/**
		 * Fires after a page cache file has been written.
		 *
		 * Subsystems (e.g. CachingOut_WebP) hook here to produce additional
		 * cache variants from the same HTML payload.
		 *
		 * @since 0.1.0
		 *
		 * @param CachingOut_Cache_Key $key  The cache key for the written file.
		 * @param string              $html The full page HTML that was cached.
		 */
		do_action( 'cachingout_after_cache_write', $key, $html );

		return true;
	}

	/**
	 * Delete all cache variant files for a given key.
	 *
	 * Removes every variant file (index.html, index-mobile.html,
	 * index-webp.html, index-mobile-webp.html) from the key's directory,
	 * then removes empty parent directories up to (but not including)
	 * the cache root.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Cache_Key $key Cache key for the page to delete.
	 * @return bool True if at least one file was deleted or the directory was
	 *              already absent; false on error that prevented deletion.
	 */
	public function delete( CachingOut_Cache_Key $key ): bool {
		$dir = $key->get_cache_path();

		if ( ! is_dir( $dir ) ) {
			// Nothing to delete — already clean.
			return true;
		}

		// Delete all .html variant files in the directory.
		$deleted = $this->delete_html_files( $dir );

		// Remove empty parent directories up to cache root.
		$this->remove_empty_parents( $dir );

		return $deleted;
	}

	/**
	 * Delete every cached page of THIS site and recreate its cache directory
	 * with the guard files (index.php, .htaccess).
	 *
	 * On single site that is the whole cache root. On multisite it is the
	 * current site's directory only (spec 28, 2026-09-05 finding): a theme
	 * switch, a settings save, a term edit or a manual purge on one site
	 * used to empty every site in the network, because this method swept
	 * them all. Sweeping the network is now purge_network(), which only a
	 * network-level action calls.
	 *
	 * @since 0.1.0
	 * @since 0.1.20 Current site only on multisite; see purge_network().
	 *
	 * @return bool True on success, false on failure.
	 */
	public function purge_all(): bool {
		if ( is_multisite() && function_exists( 'get_current_blog_id' ) ) {
			return $this->purge_site( (int) get_current_blog_id() );
		}

		return $this->purge_single_site( self::cache_dir() );
	}

	/**
	 * Purge ONE site's cache directory on multisite (spec 28 Network Admin
	 * per-site purge, and purge_all() under the hood). The other sites are
	 * untouched: purging the main site (blog 1), whose directory is the
	 * cache root, leaves the `site-<id>` subdirectories and every runtime
	 * snapshot in place (see delete_directory_contents()). Call under
	 * switch_to_blog() with a Settings instance built there, so the
	 * snapshot re-export writes that site's file.
	 *
	 * @since 0.1.20
	 *
	 * @param int $blog_id Blog id.
	 * @return bool True on success.
	 */
	public function purge_site( int $blog_id ): bool {
		return $this->purge_single_site( CachingOut_Cache_Key::cache_dir( max( 1, $blog_id ) ) );
	}

	/**
	 * Purge every site's cache directory in the network (spec 28). The one
	 * caller is the Network Admin "Purge every site" action; per-site
	 * events never call it. Single site: same as purge_all().
	 *
	 * @since 0.1.20
	 *
	 * @return bool True when every site purged.
	 */
	public function purge_network(): bool {
		if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			return $this->purge_single_site( self::cache_dir() );
		}

		$all_ok = true;
		foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $blog_id ) {
			if ( ! $this->purge_single_site( CachingOut_Cache_Key::cache_dir( (int) $blog_id ) ) ) {
				$all_ok = false;
			}
		}

		return $all_ok;
	}

	/**
	 * Purge a single site's cache directory.
	 *
	 * @since 0.1.1
	 *
	 * @param string $root Absolute path to the cache root for this site.
	 * @return bool True on success.
	 */
	private function purge_single_site( string $root ): bool {
		if ( ! is_dir( $root ) ) {
			return $this->ensure_directory( $root ) && $this->ensure_guard_files( $root );
		}

		// The main site's directory IS the cache root, which also holds the
		// other sites' `site-<id>` directories: keep those.
		$keep_site_dirs = is_multisite() && rtrim( $root, '/\\' ) === rtrim( self::cache_dir(), '/\\' );

		$result = $this->delete_directory_contents( $root, $keep_site_dirs );

		if ( ! $result ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
		@rmdir( $root );

		if ( ! $this->ensure_directory( $root ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
			error_log( 'CachingOut: Failed to recreate cache root directory after purge: ' . $root );
			return false;
		}

		$result = $this->ensure_guard_files( $root );

		// Re-export the runtime settings snapshot.
		if ( function_exists( 'get_option' ) && method_exists( $this->settings, 'export_runtime_config' ) ) {
			$this->settings->export_runtime_config();
		}

		return $result;
	}

	/**
	 * Get the cache filesystem root directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path to wp-content/cache/cachingout/.
	 */
	public static function cache_dir(): string {
		return CachingOut_Settings::cache_dir();
	}

	/**
	 * Ensure a directory exists, creating it recursively if needed.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Directory path.
	 * @return bool True when the directory exists and is writable.
	 */
	private function ensure_directory( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return wp_is_writable( $dir );
		}

		// wp_mkdir_p() returns false on failure; on success the directory exists.
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		return wp_is_writable( $dir );
	}

	/**
	 * Create guard files (index.php, .htaccess) in the cache root.
	 *
	 * These prevent directory listing and direct PHP execution. Only written
	 * if they do not already exist — they are never overwritten.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when guard files are in place.
	 */
	private function ensure_guard_files( ?string $root = null ): bool {
		$root = $root ?? self::cache_dir();

		// index.php — empty, blocks directory listing.
		$index = $root . DIRECTORY_SEPARATOR . 'index.php';
		if ( ! file_exists( $index ) ) {
			$written = @file_put_contents( $index, '<?php' . PHP_EOL . '// Silence is golden.' . PHP_EOL, LOCK_EX );
			if ( false === $written ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'CachingOut: Failed to write cache guard file: ' . $index );
				return false;
			}
		}

		// .htaccess — denies direct PHP execution.
		$htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules  = "# Deny direct access to cached PHP files." . PHP_EOL;
			$rules .= "<IfModule mod_php.c>" . PHP_EOL;
			$rules .= "    php_flag engine off" . PHP_EOL;
			$rules .= "</IfModule>" . PHP_EOL;
			$rules .= "<FilesMatch \"\.php$\">" . PHP_EOL;
			$rules .= "    Require all denied" . PHP_EOL;
			$rules .= "</FilesMatch>" . PHP_EOL;

			$written = @file_put_contents( $htaccess, $rules, LOCK_EX );
			if ( false === $written ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'CachingOut: Failed to write cache .htaccess guard file: ' . $htaccess );
				// index.php written successfully; .htaccess failure is non-fatal
				// on non-Apache servers.
			}
		}

		return true;
	}

	/**
	 * Generate a unique temporary filename in the given directory.
	 *
	 * Uses the WordPress wp_tempnam() when available (test environment),
	 * falling back to a random suffix with microtime for uniqueness.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Directory to create the temp file in.
	 * @return string Absolute path to a new temporary file.
	 */
	private function temp_file( string $dir ): string {
		// Use wp_tempnam() when available (WP is loaded).
		if ( function_exists( 'wp_tempnam' ) ) {
			$temp = wp_tempnam( 'cachingout_cache_', $dir );
			if ( false !== $temp ) {
				return $temp;
			}
		}

		// Fallback: manual unique filename.
		$suffix = bin2hex( random_bytes( 8 ) );
		return $dir . DIRECTORY_SEPARATOR . '.cachingout_' . $suffix . '.tmp';
	}

	/**
	 * Delete all .html files in a directory.
	 *
	 * Scans for variant cache files (index*.html) and removes them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Directory to scan.
	 * @return bool True when all files were deleted (or none existed).
	 */
	private function delete_html_files( string $dir ): bool {
		$deleted  = true;
		$variants = array( 'index.html', 'index-mobile.html', 'index-webp.html', 'index-mobile-webp.html' );

		foreach ( $variants as $filename ) {
			$file = $dir . DIRECTORY_SEPARATOR . $filename;
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
				if ( ! @unlink( $file ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
					error_log( 'CachingOut: Failed to delete cache file: ' . $file );
					$deleted = false;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Remove empty parent directories up to (but not including) the cache root.
	 *
	 * After deleting a cache entry's .html files, this cleans up the
	 * directory chain so the cache store doesn't accumulate empty dirs.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir The directory that held the deleted cache files.
	 * @return void
	 */
	private function remove_empty_parents( string $dir ): void {
		$root = self::cache_dir();
		$root = rtrim( $root, DIRECTORY_SEPARATOR );
		$current = rtrim( $dir, DIRECTORY_SEPARATOR );

		// Walk up from the cache-key directory to (but not including) the root.
		while ( $current !== $root && '' !== $current && is_dir( $current ) ) {
			// Only remove if the directory is empty.
			$contents = @scandir( $current );
			if ( false === $contents ) {
				break;
			}

			// Filter out . and ..
			$files = array_filter( $contents, function ( $entry ) {
				return '.' !== $entry && '..' !== $entry;
			} );

			if ( ! empty( $files ) ) {
				// Directory is not empty — stop walking.
				break;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
			@rmdir( $current );

			// Move up one level.
			$parent = dirname( $current );
			if ( $parent === $current ) {
				// Reached the filesystem root — safety stop.
				break;
			}
			$current = $parent;
		}
	}

	/**
	 * Recursively delete all files and subdirectories within a directory,
	 * without removing the directory itself.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir            Directory whose contents should be deleted.
	 * @param bool   $keep_site_dirs Leave top-level `site-<id>` directories
	 *                               (other sites' caches) untouched.
	 * @return bool True on success, false if any deletion failed.
	 */
	private function delete_directory_contents( string $dir, bool $keep_site_dirs = false ): bool {
		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$success  = true;
		$base     = rtrim( $dir, '/\\' );
		$iterator = new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS );
		if ( $keep_site_dirs ) {
			$iterator = new \RecursiveCallbackFilterIterator(
				$iterator,
				static function ( $current ) use ( $base ): bool {
					return ! ( $current->isDir()
						&& dirname( $current->getPathname() ) === $base
						&& 1 === preg_match( '/^site-\d+$/', $current->getFilename() ) );
				}
			);
		}
		$recursive = new \RecursiveIteratorIterator( $iterator, \RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $recursive as $entry ) {
			$path = $entry->getPathname();

			// Spec 28: runtime snapshots in the cache root survive a purge.
			// On multisite the other sites' runtime-config-<id>.php files
			// cannot be regenerated from this request; on single site the
			// re-export after the purge overwrites the kept file anyway.
			if ( ! $entry->isDir()
				&& dirname( $path ) === rtrim( $dir, '/\\' )
				&& CachingOut_Settings::is_runtime_config_file( $entry->getFilename() ) ) {
				continue;
			}

			if ( $entry->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
				if ( ! @rmdir( $path ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
					error_log( 'CachingOut: Failed to remove cache directory: ' . $path );
					$success = false;
				}
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
				if ( ! @unlink( $path ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
					error_log( 'CachingOut: Failed to delete cache file during purge: ' . $path );
					$success = false;
				}
			}
		}

		return $success;
	}
}