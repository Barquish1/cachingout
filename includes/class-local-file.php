<?php
/**
 * The one place a URL is mapped to a local file.
 *
 * WordPress has no function that turns a URL into the file it is served
 * from, so the mapping has to pair the URL roots WordPress knows with the
 * directory constants they are served from: `content_url()` with
 * WP_CONTENT_DIR, the uploads base URL with its base directory, `home_url()`
 * / `site_url()` with ABSPATH. Until 1.21.16 three classes each carried a
 * copy of that mapping (minified files, the stylesheet exclusions, LazyLoad's
 * image measuring); this class is the single copy, with the same-origin,
 * realpath and containment guards applied once (WordPress.org review round
 * 1, DECISIONS 2026-10-19).
 *
 * @package CachingOut
 * @since 1.21.16
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL-to-local-file resolver.
 *
 * @since 1.21.16
 */
final class CachingOut_Local_File {

	/**
	 * Resolve a same-origin URL to a readable local file, or null.
	 *
	 * Accepts absolute (`https://host/...`), protocol-relative (`//host/...`)
	 * and root-relative (`/wp-content/...`) URLs. Refuses another host, a
	 * missing or unreadable file, and any real path outside the roots the
	 * site serves from (ABSPATH, WP_CONTENT_DIR, the uploads directory). The
	 * query string and fragment are ignored; percent-encoding is decoded.
	 *
	 * @since 1.21.16
	 *
	 * @param string $url The URL as it appears in the HTML.
	 * @return string|null Real path with forward slashes, or null.
	 */
	public static function from_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return null;
		}
		if ( isset( $parsed['host'] ) && ! self::same_host( (string) $parsed['host'] ) ) {
			return null;
		}

		$url_path = isset( $parsed['path'] ) ? rawurldecode( (string) $parsed['path'] ) : '';
		if ( '' === $url_path || '/' !== $url_path[0] ) {
			return null;
		}

		$candidate = null;
		foreach ( self::roots() as $root ) {
			list( $url_prefix, $dir ) = $root;
			if ( '' === $url_prefix ) {
				// The site lives at the host root: every path is under ABSPATH.
				$candidate = $dir . $url_path;
				break;
			}
			if ( $url_path === $url_prefix || 0 === strpos( $url_path, $url_prefix . '/' ) ) {
				$candidate = $dir . substr( $url_path, strlen( $url_prefix ) );
				break;
			}
		}
		if ( null === $candidate ) {
			return null;
		}

		$real = realpath( $candidate );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return null;
		}
		$real = wp_normalize_path( $real );

		return self::is_contained( $real ) ? $real : null;
	}

	/**
	 * URL path prefix => directory pairs, most specific first.
	 *
	 * Uploads before wp-content (uploads may live elsewhere), wp-content
	 * before the site root (wp-content may be relocated), the site root last.
	 *
	 * @since 1.21.16
	 * @return array<int, array{0: string, 1: string}>
	 */
	private static function roots(): array {
		$pairs = array();

		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) && ! empty( $uploads['basedir'] ) ) {
			$pairs[] = array( self::url_path( (string) $uploads['baseurl'] ), self::dir( (string) $uploads['basedir'] ) );
		}

		$pairs[] = array( self::url_path( content_url() ), self::dir( WP_CONTENT_DIR ) );

		$site_prefix = self::url_path( site_url() );
		$home_prefix = self::url_path( home_url() );
		$pairs[]     = array( $site_prefix, self::dir( ABSPATH ) );
		if ( $home_prefix !== $site_prefix ) {
			// WordPress in a subdirectory, site at the root (or the reverse):
			// a root-relative URL may carry either prefix.
			$pairs[] = array( $home_prefix, self::dir( ABSPATH ) );
		}

		// Longest prefix first, so /wp-content/uploads wins over /wp-content
		// and both win over the site root.
		usort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b[0] ) <=> strlen( $a[0] );
			}
		);

		return $pairs;
	}

	/**
	 * The path component of a URL root, without a trailing slash ('' for
	 * the host root).
	 *
	 * @since 1.21.16
	 *
	 * @param string $url A root URL such as content_url().
	 * @return string
	 */
	private static function url_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return is_string( $path ) ? rtrim( $path, '/' ) : '';
	}

	/**
	 * A directory root with forward slashes and no trailing slash.
	 *
	 * @since 1.21.16
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private static function dir( string $dir ): string {
		return rtrim( wp_normalize_path( $dir ), '/' );
	}

	/**
	 * Whether a URL host is this site's.
	 *
	 * @since 1.21.16
	 *
	 * @param string $host Host from the URL.
	 * @return bool
	 */
	private static function same_host( string $host ): bool {
		$host = strtolower( $host );
		foreach ( array( home_url(), site_url(), content_url() ) as $root ) {
			$site_host = wp_parse_url( $root, PHP_URL_HOST );
			if ( is_string( $site_host ) && strtolower( $site_host ) === $host ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a real path sits inside a root the site serves from.
	 *
	 * @since 1.21.16
	 *
	 * @param string $real Normalised real path.
	 * @return bool
	 */
	private static function is_contained( string $real ): bool {
		$roots = array( ABSPATH, WP_CONTENT_DIR );

		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$roots[] = (string) $uploads['basedir'];
		}

		foreach ( $roots as $root ) {
			$root_real = realpath( $root );
			if ( false === $root_real ) {
				continue;
			}
			$root_real = rtrim( wp_normalize_path( $root_real ), '/' ) . '/';
			if ( 0 === strpos( $real, $root_real ) ) {
				return true;
			}
		}
		return false;
	}
}
