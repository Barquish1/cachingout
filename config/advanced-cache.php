<?php
/**
 * WP Caching Out — Advanced Cache drop-in.
 *
 * Placed at WP_CONTENT_DIR . '/advanced-cache.php' by CachingOut_Activator.
 * Runs before WordPress loads. Serves cached pages directly from the filesystem
 * without booting WordPress.
 *
 * This file must function harmlessly if the plugin is inactive or deleted: it
 * checks for class files before including them and returns immediately if they
 * are absent. It never touches the database — settings come from the
 * runtime-config.php snapshot exported by CachingOut_Settings.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cachingout_dropin_plugin_dir' ) ) {
	/**
	 * Resolve the plugin directory the drop-in loads its classes from.
	 *
	 * The snapshot's `_plugin_dir` (the directory the plugin was activated
	 * from, written by CachingOut_Settings::runtime_config_content()) is
	 * the source, so a renamed plugin folder or a custom plugins directory
	 * is found. WP_PLUGIN_DIR is not defined yet when the drop-in runs
	 * (wp-settings.php includes advanced-cache.php before
	 * wp_plugin_directory_constants()), so it is only a fallback for a host
	 * that defines it in wp-config.php. A candidate counts only when it
	 * holds the chokepoint class. An empty string means "not found", and the
	 * drop-in then returns without serving anything. There is no guessed
	 * third candidate (decision 2026-10-19): the activator writes the
	 * snapshot before the drop-in, so this drop-in never meets a snapshot
	 * without `_plugin_dir`.
	 *
	 * Declared before the early returns below, so the unit test can call it
	 * under the CLI SAPI, where the drop-in itself returns at once.
	 *
	 * @param array       $config     The runtime snapshot.
	 * @param string|null $plugin_dir The plugins directory, or null to read
	 *                                WP_PLUGIN_DIR when it is defined.
	 * @return string The plugin directory with a trailing slash, or ''.
	 */
	function cachingout_dropin_plugin_dir( $config, $plugin_dir = null ) {
		$candidates = array();
		if ( is_array( $config ) && ! empty( $config['_plugin_dir'] ) && is_string( $config['_plugin_dir'] ) ) {
			$candidates[] = rtrim( $config['_plugin_dir'], '/\\' ) . '/';
		}
		if ( null === $plugin_dir && defined( 'WP_PLUGIN_DIR' ) ) {
			$plugin_dir = WP_PLUGIN_DIR;
		}
		if ( is_string( $plugin_dir ) && '' !== $plugin_dir ) {
			$candidates[] = rtrim( $plugin_dir, '/\\' ) . '/cachingout/';
		}
		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate . 'includes/cache/class-cacheability.php' ) ) {
				return $candidate;
			}
		}
		return '';
	}
}

// The kill switch. define( 'CACHINGOUT_DISABLE_CACHE', true ) in wp-config.php
// must disable all cache reading and writing, in every version, forever.
if ( defined( 'CACHINGOUT_DISABLE_CACHE' ) && CACHINGOUT_DISABLE_CACHE ) {
	return;
}

// WP_CACHE is set by the activator. If it is not true, do nothing.
if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
	return;
}

/*
 * Non-HTTP contexts must never be served from cache, and must never have their
 * output buffered into a cache file.
 *
 * WP-CLI, WP-Cron scripts, installers and the PHPUnit bootstrap all load
 * wp-settings.php — and therefore this drop-in — under the CLI SAPI, with no
 * REQUEST_METHOD and no real request to speak of. Serving a cache hit in that
 * context calls readfile() + exit and terminates the process mid-run, which
 * silently kills `wp` commands and test suites with no error to trace.
 */
if ( PHP_SAPI === 'cli' || 'cli-server' === PHP_SAPI ) {
	return;
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	return;
}
if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
	return;
}
if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
	return;
}

// Only GET requests are cached (or servable from cache).
if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}


/*
 * Load the runtime settings snapshot. Exported by CachingOut_Settings on
 * activation and on every settings save. If it is absent (plugin never
 * activated, or snapshot deleted), do nothing - WordPress handles the request
 * and the plugin regenerates the snapshot at its next opportunity.
 *
 * The cache directory is wp-content/cache/cachingout (the literal of
 * CachingOut_Settings::cache_dir(): nothing is loaded yet).
 */
$cachingout_config_file = WP_CONTENT_DIR . '/cache/cachingout/runtime-config.php';
if ( ! file_exists( $cachingout_config_file ) ) {
	return;
}

$cachingout_config = include $cachingout_config_file;
if ( ! is_array( $cachingout_config ) ) {
	return;
}

// Find the plugin directory: the snapshot's `_plugin_dir`, else WP_PLUGIN_DIR
// when wp-config.php defines it (cachingout_dropin_plugin_dir() above).
$cachingout_plugin_dir = cachingout_dropin_plugin_dir( $cachingout_config );

// Plugin not found - return harmlessly, WordPress serves the request.
if ( '' === $cachingout_plugin_dir ) {
	return;
}

/*
 * Load the minimum set of classes needed to serve a cached page.
 */
if ( ! class_exists( 'CachingOut_Settings' ) ) {
	require_once $cachingout_plugin_dir . 'includes/class-settings.php';
}
if ( ! class_exists( 'CachingOut_Cacheability' ) ) {
	require_once $cachingout_plugin_dir . 'includes/cache/class-cacheability.php';
}
if ( ! class_exists( 'CachingOut_Cache_Key' ) ) {
	require_once $cachingout_plugin_dir . 'includes/cache/class-cache-key.php';
}
if ( ! class_exists( 'CachingOut_Page_Cache' ) ) {
	require_once $cachingout_plugin_dir . 'includes/cache/class-page-cache.php';
}

// Define constants needed by the plugin classes we loaded above.
if ( ! defined( 'CACHINGOUT_PATH' ) ) {
	define( 'CACHINGOUT_PATH', $cachingout_plugin_dir );
}
if ( ! defined( 'CACHINGOUT_SLUG' ) ) {
	define( 'CACHINGOUT_SLUG', 'cachingout' );
}

// Detect the current blog ID for multisite (always 1 on single site). The
// shared snapshot carries the site list this needs.
$cachingout_blog_id = CachingOut_Cache_Key::detect_blog_id( $cachingout_config );

/*
 * Multisite (spec 28): each site exports its own snapshot,
 * runtime-config-<blog_id>.php, so a subsite is served with ITS settings,
 * not those of whichever site saved last. A site with no file of its own
 * falls back to the shared snapshot. Single-site installs never reach
 * this block, so their path is unchanged.
 */
if ( ! empty( $cachingout_config['_multisite'] ) ) {
	$cachingout_blog_config_file = WP_CONTENT_DIR . '/cache/cachingout/runtime-config-' . (int) $cachingout_blog_id . '.php';
	if ( file_exists( $cachingout_blog_config_file ) ) {
		$cachingout_blog_config = include $cachingout_blog_config_file;
		if ( is_array( $cachingout_blog_config ) ) {
			$cachingout_config = $cachingout_blog_config;
		}
	}
}

// Build settings from the snapshot — zero database access.
$cachingout_settings     = new CachingOut_Settings( $cachingout_config );
$cachingout_cacheability = new CachingOut_Cacheability( $cachingout_settings );

// Gather the real request context.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads, so wp_unslash() and sanitize_*() do not exist yet. Never echoed: the value goes to the cacheability rules and to CachingOut_Cache_Key, which normalises it.
$cachingout_method      = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads, so wp_unslash() and sanitize_*() do not exist yet. Never echoed: the value goes to the cacheability rules and to CachingOut_Cache_Key, which normalises it.
$cachingout_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$cachingout_cookies     = isset( $_COOKIE ) && is_array( $_COOKIE ) ? $_COOKIE : array();
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads, so wp_unslash() and sanitize_*() do not exist yet. Never echoed: the value goes to the cacheability rules and to CachingOut_Cache_Key, which normalises it.
$cachingout_user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads, so wp_unslash() and sanitize_*() do not exist yet. Never echoed: the value goes to the cacheability rules and to CachingOut_Cache_Key, which normalises it.
$cachingout_accept      = isset( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : '';

// The chokepoint decides. Full URL (path + query) so the query-string
// allow-list, URL exclusions, and cookie/UA checks all run against reality.
$cachingout_result = $cachingout_cacheability->request_is_cacheable(
	$cachingout_method,
	$cachingout_request_uri,
	$cachingout_cookies,
	$cachingout_user_agent
);

if ( true !== $cachingout_result ) {
	// Not cacheable — set debug header if enabled and let WordPress handle it.
	if ( defined( 'CACHINGOUT_DEBUG' ) && CACHINGOUT_DEBUG ) {
		header( 'X-CachingOut-Cache: ' . $cachingout_result, false );
	}
	return;
}

// Build the cache key from the real request.
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads, so wp_unslash() and sanitize_*() do not exist yet. Never echoed: the value goes to the cacheability rules and to CachingOut_Cache_Key, which normalises it.
$cachingout_host        = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read before WordPress loads, so wp_unslash() and sanitize_*() do not exist yet. Never echoed: the value goes to the cacheability rules and to CachingOut_Cache_Key, which normalises it.
$cachingout_is_ssl      = ( isset( $_SERVER['HTTPS'] ) && 'on' === strtolower( $_SERVER['HTTPS'] ) )
	|| ( isset( $_SERVER['SERVER_PORT'] ) && '443' === $_SERVER['SERVER_PORT'] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs in the advanced-cache.php drop-in, before wp_parse_url() exists.
$cachingout_parsed_url   = parse_url( $cachingout_request_uri );
$cachingout_path         = isset( $cachingout_parsed_url['path'] ) ? $cachingout_parsed_url['path'] : '/';
$cachingout_query_string = isset( $cachingout_parsed_url['query'] ) ? $cachingout_parsed_url['query'] : '';
$cachingout_query_args   = array();
if ( '' !== $cachingout_query_string ) {
	parse_str( $cachingout_query_string, $cachingout_query_args );
}

// Mobile detection: wp_is_mobile() is not available before WordPress loads.
// Minimal inline check matching the WordPress core implementation.
$cachingout_is_mobile = false;
if ( '' !== $cachingout_user_agent
	&& preg_match( '/(Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi)/i', $cachingout_user_agent ) ) {
	$cachingout_is_mobile = true;
}

// $cachingout_blog_id was detected above, before the settings were built.
$cachingout_cache_key = new CachingOut_Cache_Key(
	$cachingout_settings,
	$cachingout_host,
	$cachingout_is_ssl,
	$cachingout_path,
	$cachingout_query_args,
	$cachingout_is_mobile,
	$cachingout_accept,
	null, // deprecated language slot - kept so arg order matches stale drop-ins
	$cachingout_blog_id
);

// The visitor's image variant when it exists, else the plain file (spec 36).
$cachingout_cache_file = method_exists( $cachingout_cache_key, 'get_serve_file' )
	? $cachingout_cache_key->get_serve_file()
	: $cachingout_cache_key->get_cache_file();

// Cache hit path. serve() owns the whole hit sequence: it re-checks
// existence, deletes an expired file (lifespan is converted from hours by
// CachingOut_Settings::lifespan_to_seconds — no duplicated inline logic
// here), sends headers, and EXITS after a successful readfile. When serve()
// returns instead of exiting — file expired, or deleted between the check
// and the read — control falls through to the MISS path below so THIS
// request regenerates the cache. (An early `return` here used to skip the
// ob_start capture, so an expired page cost two misses before caching again,
// and a bare `exit` after a declined serve() produced a blank page.)
if ( file_exists( $cachingout_cache_file ) ) {
	$cachingout_page_cache = new CachingOut_Page_Cache( $cachingout_settings );
	$cachingout_page_cache->serve( $cachingout_cache_key );
}

// Cache miss — output buffering will capture the response for caching.
if ( defined( 'CACHINGOUT_DEBUG' ) && CACHINGOUT_DEBUG ) {
	header( 'X-CachingOut-Cache: MISS', false );
}

// Store context so the ob_start callback can rebuild the key after WordPress
// has generated the full page.
$GLOBALS['cachingout_ctx'] = array(
	'host'       => $cachingout_host,
	'is_ssl'     => $cachingout_is_ssl,
	'path'       => $cachingout_path,
	'query_args' => $cachingout_query_args,
	'is_mobile'  => $cachingout_is_mobile,
	'accept'     => $cachingout_accept,
	'blog_id'    => $cachingout_blog_id,
);

/*
 * Close the cache-miss buffer explicitly on shutdown (priority 0, before
 * core's wp_ob_end_flush_all at priority 1). plugin.php is loaded before
 * this drop-in (wp-settings.php), so add_action() exists here. Flushing
 * our level runs the write callback below; a buffer another component
 * opened above ours flushes first, in stack order; a buffer that refuses
 * to close stops the loop.
 */
if ( ! function_exists( 'cachingout_dropin_end_output_buffer' ) ) {
	/**
	 * Flush the drop-in's output buffer and every buffer above it.
	 *
	 * @since 1.21.16
	 * @return void
	 */
	function cachingout_dropin_end_output_buffer() {
		$level = isset( $GLOBALS['cachingout_ob_level'] ) ? (int) $GLOBALS['cachingout_ob_level'] : 0;
		unset( $GLOBALS['cachingout_ob_level'] );
		while ( $level > 0 && ob_get_level() >= $level ) {
			if ( ! ob_end_flush() ) {
				break;
			}
		}
	}
}

// Start output buffering with a callback that writes the cache file.
// The callback fires when the buffer is flushed: on shutdown by
// cachingout_dropin_end_output_buffer(), or by core's flush-all.
ob_start(
	static function ( $html ) {
		$ctx = isset( $GLOBALS['cachingout_ctx'] ) ? $GLOBALS['cachingout_ctx'] : null;
		if ( ! is_array( $ctx ) || empty( $html ) ) {
			return $html;
		}

		if (
			! class_exists( 'CachingOut_Cacheability' )
			|| ! class_exists( 'CachingOut_Cache_Key' )
			|| ! class_exists( 'CachingOut_Page_Cache' )
			|| ! class_exists( 'CachingOut_Settings' )
		) {
			return $html;
		}

		// WordPress is loaded by the time the buffer flushes; a fresh
		// Settings instance reads the live option.
		$settings     = new CachingOut_Settings();
		$cacheability = new CachingOut_Cacheability( $settings );

		// Gather response headers.
		$response_headers = array();
		foreach ( headers_list() as $header_line ) {
			$parts = explode( ':', $header_line, 2 );
			if ( 2 === count( $parts ) ) {
				$response_headers[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}

		// Determine status code.
		$status_code = 200;
		if ( function_exists( 'http_response_code' ) ) {
			$code = http_response_code();
			if ( is_int( $code ) && $code > 0 ) {
				$status_code = $code;
			}
		}

		// The chokepoint owns every cache-or-not decision, including the
		// nonce-in-HTML scan.
		$response_result = $cacheability->response_is_cacheable( $status_code, $response_headers, $html );
		if ( true !== $response_result ) {
			return $html;
		}

		// Reconstruct the cache key from the request context. The blog_id MUST
		// match the serve-side key: omitting it defaulted every write to the
		// blog-1 flat layout while the serve path read from site-{N}/, so
		// multisite subsites never got a cache hit (audit N17).
		$cache_key = new CachingOut_Cache_Key(
			$settings,
			$ctx['host'],
			$ctx['is_ssl'],
			$ctx['path'],
			$ctx['query_args'],
			$ctx['is_mobile'],
			isset( $ctx['accept'] ) ? $ctx['accept'] : '',
			null, // deprecated language slot - kept so arg order matches stale drop-ins
			isset( $ctx['blog_id'] ) ? (int) $ctx['blog_id'] : 1
		);

		// WordPress's HTML carries the original image URLs: it belongs in
		// the PLAIN file whoever the first visitor was. Variant files are
		// written from it by the add-on (spec 36). Before 1.23.0 a WebP
		// browser's first visit stored this markup as the WebP variant.
		if ( method_exists( $cache_key, 'without_image_variant' ) ) {
			$cache_key = $cache_key->without_image_variant();
		}

		// Append the human-verifiable cache signature comment to the cached
		// copy only (marketing promise: a comment a non-technical user can
		// verify with View Source on a cached page).
		$cached_html = $html;
		if ( false !== stripos( $cached_html, '</html>' ) ) {
			$cached_html .= "\n<!-- Caching Out - page generated and cached on " . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';
		}

		$page_cache = new CachingOut_Page_Cache( $settings );
		$page_cache->write( $cache_key, $cached_html );

		return $html;
	}
);
$GLOBALS['cachingout_ob_level'] = ob_get_level();
if ( function_exists( 'add_action' ) ) {
	add_action( 'shutdown', 'cachingout_dropin_end_output_buffer', 0 );
}

// We do NOT exit here. WordPress loads normally, generates the page, and
// the buffer callback writes the cache file when the buffer is closed.
