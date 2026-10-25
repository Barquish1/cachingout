<?php
/**
 * Minified-file store shared by the CSS and JS minifiers.
 *
 * 0.1.16 (audit N12/N13): minification used to INLINE every local file
 * into the page. That destroyed browser caching, broke defer/async on
 * scripts, and — for CSS — resolved relative url()/@import paths against
 * the page URL instead of the stylesheet, which on a theme whose main
 * stylesheet is an @import index wiped out every style on the site.
 *
 * Minified copies now live under wp-content/cache/cachingout/min/{css,js}/
 * and the original tag keeps every attribute — only its href/src changes.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves local asset URLs to guarded filesystem paths and stores their
 * minified copies under the plugin cache directory.
 *
 * @since 0.1.16
 */
class CachingOut_Minified_Files {

	/**
	 * Cache-relative subdirectory holding minified output.
	 *
	 * @since 0.1.16
	 * @var string
	 */
	const SUBDIR = 'min';

	/**
	 * Option holding the minified-CSS source manifest: `<file name> =>
	 * { source: <original href>, time }`.
	 *
	 * A minified copy carries a hashed name, so nothing downstream can tell
	 * what it was. Optimize CSS delivery runs after Minify CSS and protects
	 * stylesheets BY NAME (the built-in slider list, the user's Excluded
	 * CSS) or BY LOCATION (the active theme's directory), so without this
	 * record every protected stylesheet lost its protection the moment
	 * Minify CSS was on (orgahair.com, 2026-09-08: the theme's main sheet
	 * was deferred and the header rendered unstyled). Mirrors the Combine
	 * manifest (`cachingout_combined_sources`). CSS only: no JS consumer
	 * needs the origin of a minified script.
	 *
	 * @since 1.21.3
	 * @var string
	 */
	const MANIFEST_OPTION = 'cachingout_minified_sources';

	/**
	 * Cap on manifest entries (oldest dropped first).
	 *
	 * @since 1.21.3
	 * @var int
	 */
	const MANIFEST_MAX = 400;

	/**
	 * URL path fragment of every minified CSS file.
	 *
	 * @since 1.21.3
	 * @var string
	 */
	const MIN_CSS_URL_FRAGMENT = '/cache/cachingout/min/css/';

	/**
	 * Absolute base directory (trailing slash).
	 *
	 * @since 0.1.16
	 * @var string
	 */
	private $base_dir;

	/**
	 * Constructor.
	 *
	 * @since 0.1.16
	 */
	public function __construct() {
		$this->base_dir = CachingOut_Settings::cache_dir() . '/' . self::SUBDIR . '/';
	}

	/**
	 * Output directory for one asset type (trailing slash).
	 *
	 * @since 0.1.16
	 *
	 * @param string $type 'css' or 'js'.
	 * @return string
	 */
	public function get_dir( $type ) {
		return $this->base_dir . $type . '/';
	}

	/**
	 * Ensure the output directory for a type exists and is writable.
	 *
	 * @since 0.1.16
	 *
	 * @param string $type 'css' or 'js'.
	 * @return bool True when the directory can be written to.
	 */
	public function ensure_dir( $type ) {
		$dir = $this->get_dir( $type );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			$index_file = $dir . 'index.php';
			if ( is_dir( $dir ) && ! file_exists( $index_file ) ) {
				file_put_contents( $index_file, '<?php // Silence is golden.' );
			}
		}
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * Resolve a same-origin asset URL to a readable local file, or null.
	 *
	 * CachingOut_Local_File does the mapping and the same-origin, realpath
	 * and containment guards (one copy for the whole plugin since 1.21.16).
	 * Two guards are this class's own (audit N13):
	 * - the extension must equal $type: no reading .php or anything else;
	 * - files already inside the plugin cache directory are refused, so our
	 *   own combined/minified output is never re-processed.
	 *
	 * @since 0.1.16
	 *
	 * @param string $url  Absolute or site-relative URL.
	 * @param string $type 'css' or 'js'.
	 * @return string|null Absolute filesystem path, or null when refused.
	 */
	public function resolve_local_path( $url, $type ) {
		$url = (string) strtok( trim( (string) $url ), '?#' );
		if ( '' === $url ) {
			return null;
		}

		// Extension guard, on the URL path, before anything is touched.
		if ( strtolower( (string) pathinfo( $url, PATHINFO_EXTENSION ) ) !== $type ) {
			return null;
		}

		$real = CachingOut_Local_File::from_url( $url );
		if ( null === $real ) {
			return null;
		}

		// Never re-process our own generated files.
		$cache_real = realpath( CachingOut_Settings::cache_dir() );
		if ( false !== $cache_real ) {
			$cache_norm = rtrim( wp_normalize_path( $cache_real ), '/' ) . '/';
			if ( 0 === strpos( $real, $cache_norm ) ) {
				return null;
			}
		}

		return $real;
	}

	/**
	 * Deterministic output path for a source file.
	 *
	 * The name hashes the source path, its mtime and size, and build_id()
	 * — so an edited source or a new plugin build produces a fresh file and
	 * stale copies are orphans that purge ages out.
	 *
	 * @since 0.1.16
	 *
	 * @param string $source_path Absolute source path.
	 * @param string $type        'css' or 'js'.
	 * @return string Absolute output path.
	 */
	public function target_path( $source_path, $type ) {
		$mtime = (int) @filemtime( $source_path );
		$size  = (int) @filesize( $source_path );
		$hash  = md5( $source_path . '|' . $mtime . '|' . $size . '|' . self::build_id() );
		return $this->get_dir( $type ) . $hash . '.' . $type;
	}

	/**
	 * Identity of the code that generates output files.
	 *
	 * The plugin version plus the mtime and size of each generator class
	 * (size too: the build zip carries a fixed entry date, and a host that
	 * preserves it would otherwise see identical mtimes across builds). Two
	 * builds carrying the same version string (successive alpha uploads of
	 * 0.1.17, say) still produce different names, so a change to how CSS or
	 * JS is rewritten is never masked by a reused file (BUG-026: generated
	 * files are no longer swept on purge, so the name is the only thing
	 * that retires an old output).
	 *
	 * @since 0.1.17
	 * @return string
	 */
	public static function build_id() {
		static $id = null;
		if ( null !== $id ) {
			return $id;
		}
		$parts = array( defined( 'CACHINGOUT_VERSION' ) ? CACHINGOUT_VERSION : '0' );
		foreach ( array( 'class-minified-files.php', 'class-minify-css.php', 'class-minify-js.php' ) as $file ) {
			$parts[] = (int) @filemtime( __DIR__ . '/' . $file ) . ':' . (int) @filesize( __DIR__ . '/' . $file );
		}
		/**
		 * Filters the build fingerprint parts. Caching Out Pro appends its
		 * own generators (combine, font self-host) so their changes retire
		 * old outputs too (spec 30).
		 *
		 * @since 1.21.0
		 *
		 * @param string[] $parts Fingerprint parts.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'cachingout_minified_build_parts', $parts );
			if ( is_array( $filtered ) ) {
				$parts = array_values( array_map( 'strval', $filtered ) );
			}
		}
		$id = implode( '|', $parts );
		return $id;
	}

	/**
	 * Public URL for an output path produced by target_path().
	 *
	 * @since 0.1.16
	 *
	 * @param string $target_path Absolute output path.
	 * @param string $type        'css' or 'js'.
	 * @return string
	 */
	public function url_for( $target_path, $type ) {
		return content_url( '/cache/cachingout/' . self::SUBDIR . '/' . $type . '/' . basename( $target_path ) );
	}

	/**
	 * Seconds a generated file must go unused before a purge may remove it.
	 *
	 * Generated files (min/ and combined/) are content-fingerprinted, so a
	 * stale one is never wrong - only orphaned. A page rendered moments
	 * before a purge may still reference a file, so purge keeps anything
	 * used within this window and only ages out the rest (BUG-026).
	 *
	 * @since 0.1.17
	 * @var int
	 */
	const RETENTION = HOUR_IN_SECONDS;

	/**
	 * Minimum seconds between two mtime refreshes of a reused file.
	 *
	 * @since 0.1.17
	 * @var int
	 */
	const REFRESH_INTERVAL = 600;

	/**
	 * Mark a reused generated file as live by refreshing its mtime.
	 *
	 * Throttled to one touch per REFRESH_INTERVAL so a busy uncached page
	 * does not write to the file on every render.
	 *
	 * @since 0.1.17
	 *
	 * @param string $path Absolute path of an existing generated file.
	 * @return void
	 */
	public static function keep_fresh( $path ) {
		$mtime = @filemtime( $path );
		if ( false !== $mtime && ( time() - (int) $mtime ) > self::REFRESH_INTERVAL ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Refreshes the modification time of a plugin-generated file so it is not treated as stale.
			@touch( $path );
		}
	}

	/**
	 * Whether a generated output file exists; refreshes its mtime when it does.
	 *
	 * Writers call this instead of file_exists() so every reuse counts as
	 * activity for the purge-time age-out.
	 *
	 * @since 0.1.17
	 *
	 * @param string $target_path Absolute output path.
	 * @return bool
	 */
	public function exists( $target_path ) {
		if ( ! is_file( $target_path ) ) {
			return false;
		}
		self::keep_fresh( $target_path );
		return true;
	}

	/**
	 * Atomically write minified content to its output path.
	 *
	 * @since 0.1.16
	 *
	 * @param string $target_path Absolute output path.
	 * @param string $content     Minified content.
	 * @return bool True on success.
	 */
	public function write( $target_path, $content ) {
		$type = strtolower( (string) pathinfo( $target_path, PATHINFO_EXTENSION ) );
		if ( ! $this->ensure_dir( $type ) ) {
			return false;
		}
		$temp = $target_path . '.' . uniqid( '', true ) . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace: temp file in the same directory, then rename(). WP_Filesystem::move() is not atomic.
		if ( ! rename( $temp, $target_path ) ) {
			wp_delete_file( $temp );
			return false;
		}
		return true;
	}

	/**
	 * Whether a URL points at a minified CSS copy of ours.
	 *
	 * @since 1.21.3
	 *
	 * @param string $href Stylesheet URL.
	 * @return bool
	 */
	public static function is_minified_css_url( string $href ): bool {
		return false !== strpos( (string) strtok( trim( $href ), '?#' ), self::MIN_CSS_URL_FRAGMENT );
	}

	/**
	 * The original href a minified CSS file was made from, or null when the
	 * manifest has no entry (a file generated before 1.21.3, or pruned).
	 *
	 * @since 1.21.3
	 *
	 * @param string $href Minified file URL.
	 * @return string|null
	 */
	public static function source_for( string $href ): ?string {
		$name     = basename( (string) strtok( trim( $href ), '?#' ) );
		$manifest = get_option( self::MANIFEST_OPTION, array() );
		if ( '' === $name || ! is_array( $manifest ) || ! isset( $manifest[ $name ]['source'] ) || ! is_string( $manifest[ $name ]['source'] ) || '' === $manifest[ $name ]['source'] ) {
			return null;
		}
		return $manifest[ $name ]['source'];
	}

	/**
	 * Record the original href of a minified CSS file.
	 *
	 * No-op when the entry already holds the same source (a reused file
	 * costs no option write). Entries whose file is gone are dropped on the
	 * way, and the manifest is capped at MANIFEST_MAX entries.
	 *
	 * @since 1.21.3
	 *
	 * @param string $target_path Absolute output path (basename is the key).
	 * @param string $source_url  The href as it appeared in the page.
	 * @return void
	 */
	public static function record_source( string $target_path, string $source_url ): void {
		$name       = basename( $target_path );
		$source_url = trim( $source_url );
		if ( '' === $name || '' === $source_url ) {
			return;
		}
		$manifest = get_option( self::MANIFEST_OPTION, array() );
		if ( ! is_array( $manifest ) ) {
			$manifest = array();
		}
		if ( isset( $manifest[ $name ]['source'] ) && $manifest[ $name ]['source'] === $source_url ) {
			return;
		}

		$manifest          = self::prune_entries( $manifest );
		$manifest[ $name ] = array(
			'source' => $source_url,
			'time'   => time(),
		);
		if ( count( $manifest ) > self::MANIFEST_MAX ) {
			uasort(
				$manifest,
				static function ( $a, $b ) {
					return ( isset( $a['time'] ) ? (int) $a['time'] : 0 ) <=> ( isset( $b['time'] ) ? (int) $b['time'] : 0 );
				}
			);
			$manifest = array_slice( $manifest, count( $manifest ) - self::MANIFEST_MAX, null, true );
		}
		update_option( self::MANIFEST_OPTION, $manifest, false );
	}

	/**
	 * Drop manifest entries whose minified file no longer exists.
	 *
	 * Called by the purge-time age-out of generated files.
	 *
	 * @since 1.21.3
	 * @return void
	 */
	public static function prune_manifest(): void {
		$manifest = get_option( self::MANIFEST_OPTION, array() );
		if ( ! is_array( $manifest ) || empty( $manifest ) ) {
			return;
		}
		$pruned = self::prune_entries( $manifest );
		if ( $pruned !== $manifest ) {
			update_option( self::MANIFEST_OPTION, $pruned, false );
		}
	}

	/**
	 * Manifest without the entries whose file is gone.
	 *
	 * @since 1.21.3
	 *
	 * @param array $manifest Manifest entries.
	 * @return array
	 */
	private static function prune_entries( array $manifest ): array {
		$dir = CachingOut_Settings::cache_dir() . '/' . self::SUBDIR . '/css/';
		$out = array();
		foreach ( $manifest as $name => $entry ) {
			if ( is_string( $name ) && '' !== $name && is_array( $entry ) && is_file( $dir . $name ) ) {
				$out[ $name ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Replace the value of one attribute (href/src) in a tag, keeping
	 * every other attribute intact.
	 *
	 * @since 0.1.16
	 *
	 * @param string $tag       Original tag HTML.
	 * @param string $attribute 'href' or 'src'.
	 * @param string $value     New attribute value (will be esc_url'd).
	 * @return string
	 */
	public static function replace_attribute( $tag, $attribute, $value ) {
		$escaped = esc_url( $value );
		return preg_replace_callback(
			'/(\b' . preg_quote( $attribute, '/' ) . '\s*=\s*)(["\'])[^"\']*\2/i',
			static function ( $m ) use ( $escaped ) {
				return $m[1] . $m[2] . $escaped . $m[2];
			},
			$tag,
			1
		);
	}
}
