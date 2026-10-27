<?php
/**
 * Cache key composer — converts request context into a deterministic cache
 * file path.
 *
 * The key includes every dimension the file layout actually stores:
 * host, scheme, path, device class, image variant (AVIF / WebP, spec 36)
 * and blog ID (multisite).
 * A missing dimension is a cross-user content leak. (The former logical
 * get_key() string with language/currency dimensions was dead code - the
 * filesystem layout never stored them; deleted per TODOS 19.)
 *
 * Stateless aside from the injected settings instance. Every method produces
 * the same output for the same constructor arguments.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-dimensional cache key composer.
 *
 * @since 0.1.0
 */
class CachingOut_Cache_Key {

	/**
	 * Image variants in preference order (spec 36): a request that accepts
	 * both is given AVIF. '' is the plain variant (original image URLs).
	 *
	 * @since 1.23.0
	 * @var string[]
	 */
	const IMAGE_VARIANTS = array( 'avif', 'webp' );

	/**
	 * Settings manager.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Request host (e.g. 'example.com').
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $host;

	/**
	 * Whether the request is over HTTPS.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $is_ssl;

	/**
	 * Request path without query string (e.g. '/blog/post-1/').
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $path;

	/**
	 * Query arguments to include in the key (already filtered against the
	 * allow-list).
	 *
	 * Keys only; values are ignored for key composition.
	 *
	 * @since 0.1.0
	 * @var array
	 */
	private $query_args;

	/**
	 * Whether the request is from a mobile device (per wp_is_mobile()).
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $is_mobile;


	/**
	 * Blog ID for multisite (1 on single site).
	 *
	 * @since 0.1.1
	 * @var int
	 */
	private $blog_id;

	/**
	 * Raw Accept header for image-variant detection.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $accept_header;

	/**
	 * A variant set explicitly (with_image_variant() / without_image_variant()),
	 * or null to derive it from the Accept header and the served list.
	 *
	 * @since 1.23.0
	 * @var string|null
	 */
	private $forced_variant = null;

	/**
	 * Cached device class to avoid recomputation.
	 *
	 * @since 0.1.0
	 * @var string|null
	 */
	private $device_class = null;


	/**
	 * Constructor.
	 *
	 * Accepts the full request context as explicit parameters so every code
	 * path is testable without mocking superglobals.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings $settings      Settings manager instance.
	 * @param string             $host           Request host (e.g. 'example.com').
	 * @param bool               $is_ssl         Whether the request is HTTPS.
	 * @param string             $path           Request path without query string.
	 * @param array              $query_args     Allow-listed query arg keys (empty array = none).
	 * @param bool               $is_mobile         Whether wp_is_mobile() is true.
	 * @param string             $accept_header     Raw Accept request header.
	 * @param mixed              $deprecated        Unused. Holds the removed
	 *                                              language dimension's slot so
	 *                                              STALE advanced-cache.php
	 *                                              drop-ins passing nine
	 *                                              positional args keep working
	 *                                              (their null must not land in
	 *                                              int blog_id).
	 * @param int                $blog_id           Blog ID for multisite (1 on single site).
	 */
	public function __construct(
		CachingOut_Settings $settings,
		string $host,
		bool $is_ssl,
		string $path,
		array $query_args = array(),
		bool $is_mobile = false,
		string $accept_header = '',
		$deprecated = null,
		int $blog_id = 1
	) {
		$this->settings          = $settings;
		$this->host              = $host;
		$this->is_ssl            = $is_ssl;
		$this->path              = $path;
		$this->query_args        = $query_args;
		$this->is_mobile         = $is_mobile;
		$this->accept_header     = $accept_header;
		$this->blog_id           = $blog_id;
	}


	/**
	 * Directory path within wp-content/cache/cachingout/ for this request.
	 *
	 * Layout: site-{blog_id}/<host>/<scheme>/<base64url-encoded-path>/
	 *
	 * The path component is base64url-encoded so it is safe as a directory
	 * name on every filesystem and can be decoded back to the original path
	 * for debugging.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $blog_id Optional blog ID override (defaults to instance's blog_id).
	 * @return string Absolute directory path (no trailing slash).
	 */
	public function get_cache_path( ?int $blog_id = null ): string {
		$bid = $blog_id ?? $this->blog_id;
		return self::cache_dir( $bid )
			. DIRECTORY_SEPARATOR . $this->sanitize_segment( $this->host )
			. DIRECTORY_SEPARATOR . ( $this->is_ssl ? 'https' : 'http' )
			. DIRECTORY_SEPARATOR . $this->encode_path( $this->path );
	}

	/**
	 * Full filesystem path to the cache file for this key's variant.
	 *
	 * The file name carries the device class and the image variant:
	 *   index.html, index-webp.html, index-avif.html (desktop)
	 *   index-mobile.html, index-mobile-webp.html, index-mobile-avif.html
	 *
	 * When separate mobile caching is disabled, all requests use the
	 * desktop names. This is the file a WRITE goes to; the serve path uses
	 * get_serve_file(), which falls back to the plain file.
	 *
	 * @since 0.1.0
	 * @since 1.23.0 AVIF; the variant comes from image_variant().
	 *
	 * @return string Absolute path to the cache HTML file.
	 */
	public function get_cache_file(): string {
		return $this->file_for_variant( $this->image_variant() );
	}

	/**
	 * The cache file to SERVE for this request.
	 *
	 * The visitor's variant file when it exists, otherwise the plain file:
	 * the plain markup (original image URLs) is correct for every browser,
	 * and a page none of whose images has a copy never gets a variant file
	 * (spec 36). The returned path may not exist; the caller checks.
	 *
	 * @since 1.23.0
	 * @return string Absolute path.
	 */
	public function get_serve_file(): string {
		$variant = $this->image_variant();
		if ( '' !== $variant ) {
			$file = $this->file_for_variant( $variant );
			if ( file_exists( $file ) ) {
				return $file;
			}
		}
		return $this->file_for_variant( '' );
	}

	/**
	 * Every cache file name a page can have (purge removes all of them).
	 *
	 * @since 1.23.0
	 * @return string[] Bare file names.
	 */
	public static function variant_filenames(): array {
		$names = array();
		foreach ( array( '', '-mobile' ) as $device ) {
			$names[] = 'index' . $device . '.html';
			foreach ( self::IMAGE_VARIANTS as $variant ) {
				$names[] = 'index' . $device . '-' . $variant . '.html';
			}
		}
		return $names;
	}

	/**
	 * Path of the cache file for one image variant of this key.
	 *
	 * @since 1.23.0
	 *
	 * @param string $variant '' | 'webp' | 'avif'.
	 * @return string
	 */
	private function file_for_variant( string $variant ): string {
		$filename = 'index';

		if ( 'mobile' === $this->get_device_class() ) {
			$filename .= '-mobile';
		}

		if ( '' !== $variant ) {
			$filename .= '-' . $variant;
		}

		return $this->get_cache_path( $this->blog_id ) . DIRECTORY_SEPARATOR . $filename . '.html';
	}

	/**
	 * The image variant of this key: '' (original images), 'webp' or 'avif'.
	 *
	 * A forced variant wins. Otherwise a format is chosen only when BOTH the
	 * request's Accept header names it AND the site serves it - the derived
	 * snapshot list `cache.image_variants`, which the Pro add-on fills
	 * through `cachingout_image_variants` (CachingOut_Settings::
	 * image_variants()). With nothing served, every request maps to the one
	 * plain file. AVIF is preferred over WebP.
	 *
	 * @since 1.23.0
	 * @return string
	 */
	public function image_variant(): string {
		if ( null !== $this->forced_variant ) {
			return $this->forced_variant;
		}

		$served = $this->settings->get( 'cache', 'image_variants', array() );
		if ( ! is_array( $served ) || empty( $served ) ) {
			return '';
		}

		foreach ( self::IMAGE_VARIANTS as $variant ) {
			if ( in_array( $variant, $served, true ) && false !== stripos( $this->accept_header, 'image/' . $variant ) ) {
				return $variant;
			}
		}

		return '';
	}

	/**
	 * Current device class for this request.
	 *
	 * Returns 'mobile' only when all of the following are true:
	 *   1. Separate mobile caching is enabled in settings.
	 *   2. wp_is_mobile() returned true for this request.
	 *
	 * Otherwise returns 'desktop' — this is the safe default that ensures
	 * all visitors get the same cache file by default.
	 *
	 * @since 0.1.0
	 *
	 * @return string 'desktop' or 'mobile'.
	 */
	public function get_device_class(): string {
		if ( null !== $this->device_class ) {
			return $this->device_class;
		}

		$separate_mobile = $this->settings->get( 'cache', 'separate_mobile_cache', false );

		if ( $separate_mobile && $this->is_mobile ) {
			$this->device_class = 'mobile';
		} else {
			$this->device_class = 'desktop';
		}

		return $this->device_class;
	}

	/**
	 * Whether this key is the WebP variant.
	 *
	 * Kept for the Pro add-on before 1.23.0, which uses it as its recursion
	 * guard. Since 1.23.0 it is true only when the site serves WebP and the
	 * request accepts it (or the key was forced to WebP), not whenever the
	 * Accept header names `image/webp`.
	 *
	 * @since 0.1.0
	 * @since 1.23.0 Delegates to image_variant().
	 *
	 * @return bool
	 */
	public function supports_webp(): bool {
		return 'webp' === $this->image_variant();
	}



	/**
	 * Get the cache filesystem root directory.
	 *
	 * On multisite, each subsite gets its own subdirectory:
	 *   wp-content/cache/cachingout/site-{blog_id}/
	 *
	 * On single site (blog_id = 1), the flat layout is used:
	 *   wp-content/cache/cachingout/
	 *
	 * @since 0.1.0
	 *
	 * @param int $blog_id Blog ID (1 on single site).
	 * @return string Absolute path to the cache root directory.
	 */
	public static function cache_dir( int $blog_id = 1 ): string {
		$base = CachingOut_Settings::cache_dir();
		if ( $blog_id > 1 ) {
			return $base . DIRECTORY_SEPARATOR . 'site-' . $blog_id;
		}
		return $base;
	}

	/**
	 * Detect the current blog ID in drop-in context (before WordPress loads).
	 *
	 * On multisite, the blog ID is derived from the request host + path by
	 * matching against the site domain/path stored in the runtime config.
	 * On single site, always returns 1.
	 *
	 * @since 0.1.1
	 *
	 * @param array $runtime_config The runtime settings snapshot.
	 * @return int Blog ID.
	 */
	public static function detect_blog_id( array $runtime_config ): int {
		if ( ! isset( $runtime_config['_multisite'] ) || ! $runtime_config['_multisite'] ) {
			return 1;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads; only compared with the network's site addresses, never echoed or stored.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads; only compared with the network's site addresses, never echoed or stored.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';

		$is_ssl = ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] )
			|| ( isset( $_SERVER['SERVER_PORT'] ) && '443' === $_SERVER['SERVER_PORT'] );
		$scheme = $is_ssl ? 'https' : 'http';
		$current_url = $scheme . '://' . $host . $uri;

		$sites = isset( $runtime_config['_multisite_sites'] )
			? $runtime_config['_multisite_sites']
			: array();

		// Longest matching site wins, on a path boundary (spec 28). On a
		// subdirectory network the main site's URL is a prefix of every
		// subsite's, so first-match sent every subsite to the main site's
		// cache directory and settings. Scheme is ignored: a site registered
		// as http served over https is still that site.
		$current   = strtolower( preg_replace( '#^https?://#i', '', $current_url ) );
		$best_id   = 0;
		$best_len  = -1;
		foreach ( $sites as $site ) {
			if ( ! isset( $site['url'], $site['blog_id'] ) ) {
				continue;
			}
			$site_url = strtolower( rtrim( preg_replace( '#^https?://#i', '', (string) $site['url'] ), '/' ) );
			if ( '' === $site_url ) {
				continue;
			}
			$len = strlen( $site_url );
			if ( $len <= $best_len ) {
				continue;
			}
			if ( $current === $site_url || 0 === strpos( $current, $site_url . '/' ) || 0 === strpos( $current, $site_url . '?' ) ) {
				$best_id  = (int) $site['blog_id'];
				$best_len = $len;
			}
		}
		if ( $best_id > 0 ) {
			return $best_id;
		}

		return isset( $runtime_config['_multisite_main_site'] )
			? (int) $runtime_config['_multisite_main_site']
			: 1;
	}

	/**
	 * Normalize a request path for use in the cache key.
	 *
	 * Ensures the path starts with '/', has a consistent trailing slash
	 * (trailing slash present for directories, absent for file extensions),
	 * and is lowercased for case-insensitive matching.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Raw request path.
	 * @return string Normalized path.
	 */
	private function normalize_path( string $path ): string {
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		$path = strtolower( $path );
		$path = urldecode( $path );
		$path = preg_replace( '#/+#', '/', $path );

		return $path;
	}


	/**
	 * Base64url-encode a path for use as a filesystem directory name.
	 *
	 * Uses the URL-safe variant (RFC 4648 §5) with padding stripped.
	 * The encoding is reversible, so the cache directory structure can be
	 * inspected to find the original URL path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Request path.
	 * @return string Base64url-encoded path.
	 */
	private function encode_path( string $path ): string {
		$normalized = $this->normalize_path( $path );
		$normalized = ltrim( $normalized, '/' );

		if ( '' === $normalized ) {
			$normalized = '__root__';
		}

		$encoded = base64_encode( $normalized );
		$encoded = strtr( $encoded, '+/', '-_' );
		$encoded = rtrim( $encoded, '=' );

		return $encoded;
	}

	/**
	 * Create a copy of this key forced to the WebP variant.
	 *
	 * Kept for the Pro add-on before 1.23.0. The file it names is served
	 * only when the site's served list includes WebP (image_variant()).
	 *
	 * @since 0.1.0
	 * @since 1.23.0 Forces the variant instead of faking the Accept header.
	 *
	 * @return self
	 */
	public function with_webp_support(): self {
		return $this->with_image_variant( 'webp' );
	}

	/**
	 * Create a copy of this key forced to one image variant.
	 *
	 * The variant producer (Pro) writes its rewritten HTML through such a
	 * key. An unknown variant is treated as the plain one.
	 *
	 * @since 1.23.0
	 *
	 * @param string $variant 'webp' | 'avif' | ''.
	 * @return self
	 */
	public function with_image_variant( string $variant ): self {
		$copy                 = new self(
			$this->settings,
			$this->host,
			$this->is_ssl,
			$this->path,
			$this->query_args,
			$this->is_mobile,
			$this->accept_header,
			null, // deprecated language slot
			$this->blog_id
		);
		$copy->forced_variant = in_array( $variant, self::IMAGE_VARIANTS, true ) ? $variant : '';
		return $copy;
	}

	/**
	 * Create a copy of this key forced to the plain variant.
	 *
	 * The drop-in writes WordPress's HTML through it: that markup carries the
	 * original image URLs and belongs in the plain file only, whoever the
	 * first visitor was (spec 36).
	 *
	 * @since 1.23.0
	 * @return self
	 */
	public function without_image_variant(): self {
		return $this->with_image_variant( '' );
	}

	/**
	 * Sanitize a string for use as a filesystem directory segment.
	 *
	 * Removes or replaces characters that are unsafe in directory names
	 * across platforms: null bytes, directory separators, colons.
	 *
	 * @since 0.1.0
	 *
	 * @param string $segment Raw segment string.
	 * @return string Filesystem-safe segment.
	 */
	private function sanitize_segment( string $segment ): string {
		$segment = str_replace( "\0", '', $segment );
		$segment = str_replace( array( '/', '\\', ':' ), '-', $segment );
		$segment = trim( $segment, " \t\n\r\0\x0B." );

		if ( '' === $segment ) {
			$segment = 'unknown-host';
		}

		return $segment;
	}
}