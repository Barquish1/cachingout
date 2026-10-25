<?php
/**
 * Drop-in ownership guard — the only place allowed to decide whether we may
 * write, overwrite or delete wp-content/advanced-cache.php.
 *
 * Invariant: never write, overwrite or delete a drop-in we do not own.
 * Unrecognised content is FOREIGN. Fail closed, always.
 *
 * @package CachingOut
 * @since 0.1.14
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drop-in ownership guard.
 *
 * All methods are static. No constructor — this class is a namespace for
 * ownership decisions, never instantiated.
 *
 * @since 0.1.14
 */
class CachingOut_Dropin_Owner {

	/**
	 * Known drop-in signatures — first 2KB of advanced-cache.php matched
	 * against these strings to determine ownership.
	 *
	 * The key is a human-readable slug; the value is a string that uniquely
	 * appears in that plugin's drop-in.
	 *
	 * @since 0.1.14
	 * @var array<string, string>
	 */
	const DROPIN_SIGNATURES = array(
		'cachingout'       => 'CachingOut',
		'w3-total-cache'    => 'W3 Total Cache',
		'wp-super-cache'    => 'WP Super Cache',
		'litespeed-cache'   => 'LiteSpeed_Cache',
		'wp-fastest-cache'  => 'WpFastestCache',
		'comet-cache'       => 'comet_cache',
		'breeze'            => 'Breeze',
		'swift-performance' => 'Swift_Performance',
		'hummingbird'       => 'WP Hummingbird',
		'cache-enabler'     => 'Cache_Enabler',
		'surge'             => 'Surge',
		'batcache'          => 'batcache',
		'wp-rocket'         => 'WP Rocket',
	);

	/**
	 * Foreign .htaccess markers that indicate another cache plugin owns
	 * the rewrite layer. If any of these appear in .htaccess, do not
	 * inject our block.
	 *
	 * @since 0.1.14
	 * @var string[]
	 */
	const FOREIGN_HTACCESS_MARKERS = array(
		'# BEGIN WPSuperCache',
		'# BEGIN W3TC',
		'# BEGIN LSCACHE',
		'# BEGIN WP Fastest Cache',
		'# BEGIN WP Rocket',
		'# BEGIN Breeze',
	);

	/**
	 * Option key for the SHA-256 hash of our written drop-in.
	 *
	 * @since 0.1.14
	 * @var string
	 */
	const HASH_OPTION = 'cachingout_dropin_hash';

	/**
	 * Option key for the page cache mode state.
	 *
	 * Values: 'owned' | 'deferred' | 'blocked'.
	 *
	 * @since 0.1.14
	 * @var string
	 */
	const MODE_OPTION = 'cachingout_page_cache_mode';

	/**
	 * Option key for the slug of the detected foreign drop-in owner.
	 *
	 * @since 0.1.14
	 * @var string
	 */
	const OWNER_OPTION = 'cachingout_dropin_owner';

	/**
	 * Detect who owns the given drop-in content.
	 *
	 * Reads the first 2KB and matches against DROPIN_SIGNATURES.
	 * Returns the slug of the owning plugin, or 'foreign' when
	 * unrecognised. Fail closed: an unrecognised drop-in IS foreign.
	 *
	 * @since 0.1.14
	 *
	 * @param string $content The drop-in file content (at least first 2KB).
	 * @return string Signature slug or 'foreign'.
	 */
	public static function detect( string $content ): string {
		$chunk = substr( $content, 0, 2048 );

		foreach ( self::DROPIN_SIGNATURES as $slug => $signature ) {
			if ( false !== strpos( $chunk, $signature ) ) {
				return $slug;
			}
		}

		return 'foreign';
	}

	/**
	 * Stamp the current on-disk drop-in hash into the options table.
	 *
	 * Called immediately after a successful write. Stores the SHA-256
	 * hash of the exact content written so is_ours() can later verify
	 * that the file has not been replaced by another plugin.
	 *
	 * @since 0.1.14
	 *
	 * @param string $content The complete content of the written drop-in.
	 * @return void
	 */
	public static function stamp( string $content ): void {
		update_option( self::HASH_OPTION, hash( 'sha256', $content ), false );
	}

	/**
	 * Determine whether the on-disk advanced-cache.php is still the file
	 * we wrote.
	 *
	 * Reads the on-disk file, computes SHA-256, and compares it to the
	 * stored `cachingout_dropin_hash`. Returns false if:
	 * - the file does not exist
	 * - the stored hash does not exist
	 * - the hashes do not match
	 *
	 * @since 0.1.14
	 *
	 * @return bool True when the on-disk file matches our stored hash.
	 */
	public static function is_ours(): bool {
		$dropin_path = self::dropin_path();

		if ( ! file_exists( $dropin_path ) ) {
			return false;
		}

		$stored_hash = get_option( self::HASH_OPTION, '' );
		if ( ! is_string( $stored_hash ) || '' === $stored_hash ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$on_disk = @file_get_contents( $dropin_path );
		if ( false === $on_disk ) {
			return false;
		}

		$disk_hash = hash( 'sha256', $on_disk );

		return hash_equals( $stored_hash, $disk_hash );
	}

	/**
	 * Scan .htaccess content for foreign cache-marker blocks.
	 *
	 * If any foreign marker is present, another plugin manages the
	 * rewrite layer and we must not inject our own block.
	 *
	 * @since 0.1.14
	 *
	 * @param string $htaccess_content Full .htaccess content.
	 * @return bool True when a foreign marker is present.
	 */
	public static function htaccess_has_foreign_markers( string $htaccess_content ): bool {
		foreach ( self::FOREIGN_HTACCESS_MARKERS as $marker ) {
			if ( false !== strpos( $htaccess_content, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The first foreign cache block that still carries directives, if any.
	 *
	 * This is the write-path guard (.clinerules 03: never inject a block
	 * while a foreign marker is present). An EMPTY marker pair - the
	 * leftover a rival plugin leaves after it is removed, e.g.
	 * "# BEGIN LSCACHE" directly followed by "# END LSCACHE" - is a remnant,
	 * not a live rival: it has no rules to fight ours, so it does not block
	 * the write (the remnant advisor, TODOS 17, is what reports it). A block
	 * with directives inside, or an opening marker with no closing line,
	 * does block.
	 *
	 * @since 0.1.17
	 *
	 * @param string $htaccess_content Full .htaccess content.
	 * @return string|null The offending marker line (e.g. "# BEGIN W3TC"), or null.
	 */
	public static function htaccess_foreign_block_with_rules( string $htaccess_content ): ?string {
		foreach ( self::FOREIGN_HTACCESS_MARKERS as $marker ) {
			$start = strpos( $htaccess_content, $marker );
			if ( false === $start ) {
				continue;
			}

			$end_marker = '# END ' . substr( $marker, strlen( '# BEGIN ' ) );
			$end        = strpos( $htaccess_content, $end_marker, $start );
			if ( false === $end ) {
				return $marker;
			}

			$body_start = $start + strlen( $marker );
			$body       = substr( $htaccess_content, $body_start, $end - $body_start );
			if ( '' !== trim( $body ) ) {
				return $marker;
			}
		}

		return null;
	}

	/**
	 * Get the current page-cache mode.
	 *
	 * Defaults to 'owned' when the option has never been set (fresh
	 * install where no blocking has been detected yet).
	 *
	 * @since 0.1.14
	 *
	 * @return string 'owned', 'deferred', or 'blocked'.
	 */
	public static function get_page_cache_mode(): string {
		$mode = get_option( self::MODE_OPTION, 'owned' );

		if ( ! in_array( $mode, array( 'owned', 'deferred', 'blocked' ), true ) ) {
			return 'owned';
		}

		return $mode;
	}

	/**
	 * Record the slug of the foreign drop-in owner that blocked activation.
	 *
	 * @since 0.1.14
	 *
	 * @param string $slug Detected signature slug.
	 * @return void
	 */
	public static function set_detected_owner( string $slug ): void {
		update_option( self::OWNER_OPTION, $slug, false );
	}

	/**
	 * Human-readable label for a detected foreign drop-in owner.
	 *
	 * Maps the signature slug to a friendly name, falling back to the slug
	 * itself (or a generic phrase for unrecognised content).
	 *
	 * @since 0.1.14
	 *
	 * @return string Label for display in the blocked admin notice.
	 */
	public static function get_detected_owner_label(): string {
		$slug = get_option( self::OWNER_OPTION, '' );
		if ( ! is_string( $slug ) || '' === $slug ) {
			return __( 'an unknown plugin', 'cachingout' );
		}

		$labels = array(
			'w3-total-cache'    => 'W3 Total Cache',
			'wp-super-cache'    => 'WP Super Cache',
			'litespeed-cache'   => 'LiteSpeed Cache',
			'wp-fastest-cache'  => 'WP Fastest Cache',
			'comet-cache'       => 'Comet Cache',
			'breeze'            => 'Breeze',
			'swift-performance' => 'Swift Performance',
			'hummingbird'       => 'WP Hummingbird',
			'cache-enabler'     => 'Cache Enabler',
			'surge'             => 'Surge',
			'batcache'          => 'Batcache',
			'wp-rocket'         => 'WP Rocket',
		);

		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}

		// 'foreign' or any unrecognised slug.
		return __( 'an unknown plugin', 'cachingout' );
	}

	/**
	 * Set the page-cache mode.
	 *
	 * @since 0.1.14
	 *
	 * @param string $mode 'owned', 'deferred', or 'blocked'.
	 * @return void
	 */
	public static function set_page_cache_mode( string $mode ): void {
		if ( ! in_array( $mode, array( 'owned', 'deferred', 'blocked' ), true ) ) {
			return;
		}

		update_option( self::MODE_OPTION, $mode, false );
	}

	/**
	 * Path to the drop-in file.
	 *
	 * Centralised so tests can override via a filter without patching
	 * every consumer. In production this is always
	 * WP_CONTENT_DIR . '/advanced-cache.php'.
	 *
	 * @since 0.1.14
	 *
	 * @return string Absolute path.
	 */
	public static function dropin_path(): string {
		/**
		 * Filter the advanced-cache.php drop-in path.
		 *
		 * Allows tests to redirect to a temp directory.
		 *
		 * @since 0.1.14
		 *
		 * @param string $path Default WP_CONTENT_DIR . '/advanced-cache.php'.
		 */
		return apply_filters(
			'cachingout_dropin_path',
			WP_CONTENT_DIR . '/advanced-cache.php'
		);
	}
}
