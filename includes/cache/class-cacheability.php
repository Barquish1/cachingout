<?php
/**
 * Cacheability chokepoint — the single place allowed to answer "may this be
 * cached / served from cache?".
 *
 * Every exclusion, from any module or built-in rule, flows through this class.
 * No other file may contain an independent cache-or-not conditional.
 *
 * This class requires 100% branch coverage in PHPUnit — higher than the 60%
 * project baseline. If you cannot test a branch, that branch should not exist.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cacheability chokepoint.
 *
 * Accepts context parameters explicitly so every code path is testable without
 * mocking superglobals. At runtime the caller passes $_SERVER / $_COOKIE values.
 *
 * @since 0.1.0
 */
class CachingOut_Cacheability {

	/**
	 * Settings manager, injected at construct time.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Registered exclusion rules from compat modules.
	 *
	 * Each entry is an associative array:
	 *   'id'       => string, unique rule identifier
	 *   'callback' => callable, receives (string $url) and returns bool
	 *   'reason'   => string, human-readable bypass reason
	 *
	 * @since 0.1.0
	 * @var array
	 */
	private $exclusions = array();

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
	 * Cookies that indicate same-URL content variation (language/currency).
	 *
	 * The on-disk cache layout does not include a language or currency
	 * dimension, so requests carrying these cookies bypass the cache entirely
	 * rather than risk serving the wrong variant.
	 *
	 * Distinct-URL language modes (directory / domain) are NOT exempt by
	 * themselves: Polylang and WPML set their language cookie on every
	 * WordPress-rendered response in every mode, so a visitor whose first
	 * load was a MISS would carry it for a year and never be served from
	 * cache again (found on orgahair.com, 2026-09-08). The settings export
	 * therefore lists the language cookies whose language the URL already
	 * carries (`cache.url_carried_variation_cookies`, see
	 * CachingOut_Settings::url_carried_variation_cookies()), and
	 * request_is_cacheable() skips those. An absent list means every
	 * variation cookie bypasses, so a stale snapshot fails closed.
	 *
	 * Wildcard suffix supported (see cookie_exists()).
	 *
	 * @since 0.1.0
	 * @since 1.21.2 URL-carried language cookies are skipped; `_icl_current_language` (WPML 3.x) added.
	 * @var array
	 */
	const VARIATION_COOKIES = array(
		'wp-wpml_current_language',
		'_icl_current_language',
		'pll_language',
		'wmc_current_currency',
		'woocs_current_currency',
	);

	/**
	 * Cookies that mark a per-visitor PHP session.
	 *
	 * A plugin that calls session_start() ties the page it renders to one
	 * visitor. A request carrying the session cookie bypasses the cache, and a
	 * response that starts a session is never written, so a session-bound page
	 * is never shared. Built in (not a never_cache_cookies default) so it holds
	 * in drop-in context and cannot be lost on an import or a reset. The
	 * static-analysis corpus showed session_start() in enough front-end code
	 * paths that a per-plugin exclusion rule was a no-op next to this.
	 *
	 * @since 0.1.20
	 * @var array
	 */
	const SESSION_COOKIES = array(
		'PHPSESSID',
	);

	/**
	 * Cookies that mark a visitor with a cart or a store session.
	 *
	 * Built in for the same reason as SESSION_COOKIES: PRD 5.9 makes the
	 * eCommerce bypass automatic, so it cannot depend on a stored setting
	 * (a reset, an import, or the custom cookie list being unavailable in
	 * the free edition must never turn it off). Holds in
	 * drop-in context. The WooCommerce and EDD compat modules register the
	 * same names as exclusion callbacks for the WordPress-loaded path;
	 * this list is what the drop-in sees before any plugin loads.
	 *
	 * @since 1.21.0
	 * @var array
	 */
	const STORE_COOKIES = array(
		'wp_woocommerce_session_*',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'edd_items_in_cart',
		'edd_session_*',
	);

	/**
	 * Whether a query parameter name is on an ignore list.
	 *
	 * Exact match, or a prefix match when the entry ends in `*` (`utm_*`
	 * covers `utm_source`, `utm_id` and the rest of the family). The name is
	 * compared as parse_str() delivers it: URL-decoded, so `%66bclid` is
	 * `fbclid`. `utm` without the star does not match `utm_source`. A bare
	 * `*` never matches: it would ignore every parameter, the "cache
	 * everything" mistake the empty list exists to prevent. Entries that are
	 * not non-empty strings are skipped.
	 *
	 * @since 1.21.17
	 *
	 * @param string $name    Parameter name (the key, never the value).
	 * @param array  $ignored Ignore-list entries.
	 * @return bool True when the parameter does not affect the cache lookup.
	 */
	public static function query_param_is_ignored( string $name, array $ignored ): bool {
		foreach ( $ignored as $entry ) {
			if ( ! is_string( $entry ) || '' === $entry ) {
				continue;
			}
			if ( $entry === $name ) {
				return true;
			}
			if ( '*' === substr( $entry, -1 ) ) {
				$prefix = substr( $entry, 0, -1 );
				if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The effective ignored-query-parameter list: the admin's list
	 * (`cache.cache_query_strings`, prefilled with the tracking defaults)
	 * plus the names other plugins registered, which the settings export
	 * carries as `cache.derived_ignored_query_strings` (see
	 * CachingOut_Settings::derived_ignored_query_strings()). A value that is
	 * not an array counts as empty, so a damaged snapshot fails closed.
	 *
	 * @since 1.21.17
	 * @return array
	 */
	private function ignored_query_strings(): array {
		$admin   = $this->settings->get( 'cache', 'cache_query_strings', array() );
		$derived = $this->settings->get( 'cache', 'derived_ignored_query_strings', array() );

		return array_merge(
			is_array( $admin ) ? $admin : array(),
			is_array( $derived ) ? $derived : array()
		);
	}

	/**
	 * Determine whether the current request may be served from cache.
	 *
	 * Checks every built-in exclusion, user-configured exclusion, and
	 * registered compat-module rule in a single ordered chain. The first
	 * matching exclusion returns a BYPASS reason code; a pass returns true.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method     HTTP request method. Default 'GET'.
	 * @param string $url        Request URL path (e.g. '/blog/post-1/').
	 * @param array  $cookies    Associative array of cookie name => value.
	 * @param string $user_agent Raw User-Agent header string.
	 * @return bool|string True when cacheable, or a bypass reason code (e.g. 'BYPASS:logged-in').
	 */
	public function request_is_cacheable(
		string $method = 'GET',
		string $url = '/',
		array $cookies = array(),
		string $user_agent = ''
	) {
		// 1. Kill switch — the documented mitigation for a bad release.
		if ( defined( 'CACHINGOUT_DISABLE_CACHE' ) && CACHINGOUT_DISABLE_CACHE ) {
			return 'BYPASS:kill-switch';
		}

		// 2. Master enable/disable toggle.
		if ( ! $this->settings->get( 'cache', 'enabled' ) ) {
			return 'BYPASS:disabled';
		}

		// 3. Only GET requests are servable from a static cache.
		if ( 'GET' !== strtoupper( $method ) ) {
			return 'BYPASS:method:' . strtoupper( $method );
		}

		// 3b. Never-cache path prefixes registered by another plugin (the
		// Partner Engine's /go/ links), exported into the snapshot as
		// `cache.derived_never_cache_prefixes` (see
		// CachingOut_Settings::derived_never_cache_prefixes()). Checked
		// before every other path rule so the reason is always the same.
		$never_prefixes = $this->settings->get( 'cache', 'derived_never_cache_prefixes', array() );
		if ( is_array( $never_prefixes ) && array() !== $never_prefixes ) {
			$path_only    = strtok( $url, '?' );
			$request_path = $path_only ? $path_only : $url;
			foreach ( $never_prefixes as $never_prefix ) {
				if ( is_string( $never_prefix ) && '' !== $never_prefix && $this->path_starts_with( $request_path, $never_prefix ) ) {
					return 'BYPASS:exclusion:partner-engine';
				}
			}
		}

		// 4. AJAX endpoint — must come before the /wp-admin prefix check.
		if ( $this->path_is( $url, '/wp-admin/admin-ajax.php' ) ) {
			return 'BYPASS:ajax';
		}

		// 5. Admin pages — never cached.
		if ( $this->path_starts_with( $url, '/wp-admin' ) ) {
			return 'BYPASS:wp-admin';
		}

		if ( $this->path_is( $url, '/wp-login.php' ) ) {
			return 'BYPASS:wp-login';
		}

		if ( $this->path_is( $url, '/wp-register.php' ) ) {
			return 'BYPASS:wp-register';
		}

		// 6. REST API.
		if ( $this->path_starts_with( $url, '/wp-json/' ) ) {
			return 'BYPASS:rest-api';
		}

		// 7. Feeds.
		if ( $this->path_starts_with( $url, '/feed/' ) || $this->path_starts_with( $url, '/comments/feed/' ) ) {
			return 'BYPASS:feed';
		}

		// 8. Sitemaps.
		if ( $this->path_is( $url, '/robots.txt' ) ) {
			return 'BYPASS:robots';
		}

		if ( $this->path_is_sitemap( $url ) ) {
			return 'BYPASS:sitemap';
		}

		// 9. PHP files — covers xmlrpc.php, wp-cron.php, etc.
		if ( $this->path_ends_with( $url, '.php' ) ) {
			return 'BYPASS:php-file';
		}

		// 10. Static-asset extensions — images, CSS, JS, fonts, media.
		// These are served directly by the web server, never by the
		// page-cache subsystem.  Extension-based heuristic: anything
		// ending in a known static-file extension is excluded.
		if ( $this->path_ends_with_asset_extension( $url ) ) {
			return 'BYPASS:static-asset';
		}

		// 11. Logged-in user / auth cookie check. Logged-in users always
		// bypass: the cache key carries no per-user or per-role segment in
		// drop-in context (WordPress — and thus role resolution — is not
		// loaded before wp-settings.php runs), so serving the shared anonymous
		// cache to a logged-in user would leak private content.
		$logged_in_cookie = $this->detect_auth_cookie( $cookies );
		if ( $logged_in_cookie ) {
			return 'BYPASS:logged-in';
		}

		// 11b. Content-variation cookies (same-URL language / currency modes).
		// A language cookie whose language the URL already carries (Polylang
		// or WPML in directory / domain mode) adds no variation: the path
		// differs per language. The export lists those cookies; anything not
		// listed bypasses, so a missing or malformed list fails closed.
		$url_carried = $this->settings->get( 'cache', 'url_carried_variation_cookies', array() );
		if ( ! is_array( $url_carried ) ) {
			$url_carried = array();
		}
		foreach ( self::VARIATION_COOKIES as $variation_cookie ) {
			if ( in_array( $variation_cookie, $url_carried, true ) ) {
				continue;
			}
			if ( $this->cookie_exists( $cookies, $variation_cookie ) ) {
				return 'BYPASS:variation-cookie:' . $variation_cookie;
			}
		}

		// 11c. PHP session cookies (built in, see SESSION_COOKIES).
		foreach ( self::SESSION_COOKIES as $session_cookie ) {
			if ( $this->cookie_exists( $cookies, $session_cookie ) ) {
				return 'BYPASS:session-cookie:' . $session_cookie;
			}
		}

		// 11c-2. Cart / store session cookies (built in, see STORE_COOKIES).
		foreach ( self::STORE_COOKIES as $store_cookie ) {
			if ( $this->cookie_exists( $cookies, $store_cookie ) ) {
				return 'BYPASS:store-cookie:' . $store_cookie;
			}
		}

		// 11d. Excluded cookies (the user's own list, Cache tab).
		$excluded_cookies = $this->settings->get( 'cache', 'never_cache_cookies', array() );
		if ( ! empty( $excluded_cookies ) ) {
			foreach ( $excluded_cookies as $cookie_name ) {
				if ( $this->cookie_exists( $cookies, $cookie_name ) ) {
					return 'BYPASS:cookie:' . $cookie_name;
				}
			}
		}

		// 12. Excluded user agents.
		$excluded_uas = $this->settings->get( 'cache', 'never_cache_useragents', array() );
		if ( ! empty( $excluded_uas ) && '' !== $user_agent ) {
			foreach ( $excluded_uas as $ua_pattern ) {
				if ( $this->user_agent_matches( $user_agent, $ua_pattern ) ) {
					return 'BYPASS:user-agent:' . $ua_pattern;
				}
			}
		}

		// 13. Query string: bypass when any parameter is not on the ignore
		// list. Ignored parameters (tracking tags: the DEFAULT list, the
		// admin's additions, and names other plugins register through
		// `cachingout_ignored_query_strings`) do not change the page, so the
		// request is looked up and written as the plain URL: the cache key
		// carries no query dimension (CachingOut_Cache_Key). One parameter
		// off the list bypasses the whole request. An empty list means every
		// query string bypasses. The browser URL is never touched.
		// Native parse_url/parse_str: this method runs in drop-in context where
		// WordPress helper functions do not exist.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs in the advanced-cache.php drop-in, before wp_parse_url() exists.
		$query_string = parse_url( $url, PHP_URL_QUERY );
		if ( null !== $query_string && false !== $query_string && '' !== $query_string ) {
			$ignored_params = $this->ignored_query_strings();

			parse_str( $query_string, $query_args );
			foreach ( array_keys( $query_args ) as $param ) {
				if ( ! self::query_param_is_ignored( (string) $param, $ignored_params ) ) {
					return 'BYPASS:query-string';
				}
			}
		}

		// 14. Built-in eCommerce path exclusions (WooCommerce, EDD).
		$builtin_path = $this->match_builtin_paths( $url );
		if ( null !== $builtin_path ) {
			return $builtin_path;
		}

		// 15. User-configured URL exclusion patterns.
		$never_cache_urls = $this->settings->get( 'cache', 'never_cache_urls', array() );
		if ( ! empty( $never_cache_urls ) ) {
			foreach ( $never_cache_urls as $pattern ) {
				if ( $this->url_matches_pattern( $url, $pattern ) ) {
					return 'BYPASS:excluded-path';
				}
			}
		}

		// 16. Registered compat-module exclusion callbacks.
		foreach ( $this->exclusions as $rule ) {
			if ( call_user_func( $rule['callback'], $url ) ) {
				return 'BYPASS:exclusion:' . $rule['id'];
			}
		}

		return true;
	}

	/**
	 * Determine whether a response may be written to the page cache.
	 *
	 * Called after WordPress has rendered the page (e.g. in an ob_start
	 * callback). Checks status code, response headers, and HTML content for
	 * conditions that disqualify the response from caching.
	 *
	 * A page that defines the DONOTCACHEPAGE constant (truthy) while it renders
	 * is never written: the convention themes and plugins use to mark a page
	 * as personal (BYPASS:donotcachepage).
	 *
	 * Filter `cachingout_response_is_cacheable`: every verdict reached after
	 * the kill switch passes through it as a bool, with the same context this
	 * method receives. A callback may VETO a write (return false), never force
	 * one: when the built-in verdict is a bypass, that bypass stands whatever
	 * the callback returns, so a filter cannot write a page that sets an auth
	 * cookie or carries a nonce. A veto reports
	 * BYPASS:filter:cachingout_response_is_cacheable. The kill switch returns
	 * before the filter and is never filterable.
	 *
	 *     apply_filters(
	 *         'cachingout_response_is_cacheable',
	 *         bool   $cacheable,        // True when the built-in rules allow the write.
	 *         int    $response_code,    // HTTP status code.
	 *         array  $response_headers, // Header name => value.
	 *         string $html              // Response body.
	 *     )
	 *
	 * Runs in the drop-in's output-buffer callback, which flushes after
	 * WordPress has loaded, so apply_filters() always exists here
	 * (wp-settings.php loads plugin.php before advanced-cache.php).
	 *
	 * @since 0.1.0
	 * @since 1.21.16 DONOTCACHEPAGE honoured; the `cachingout_response_is_cacheable` filter.
	 *
	 * @param int    $response_code    HTTP status code (e.g. 200, 301, 404).
	 * @param array  $response_headers Associative array of header name => value.
	 * @param string $html             Full response body HTML (for nonce scanning).
	 * @return bool|string True when storable, or a bypass reason code.
	 */
	public function response_is_cacheable(
		int $response_code,
		array $response_headers,
		string $html = ''
	) {
		// Kill switch must also block writes.
		if ( defined( 'CACHINGOUT_DISABLE_CACHE' ) && CACHINGOUT_DISABLE_CACHE ) {
			return 'BYPASS:kill-switch';
		}

		$verdict = $this->response_verdict( $response_code, $response_headers, $html );

		/**
		 * Filter whether a rendered response may be written to the page cache.
		 *
		 * May only veto: returning anything but true blocks the write; a
		 * bypass decided by the built-in rules is never overturned.
		 *
		 * @since 1.21.16
		 *
		 * @param bool   $cacheable        True when the built-in rules allow the write.
		 * @param int    $response_code    HTTP status code.
		 * @param array  $response_headers Header name => value.
		 * @param string $html             Response body HTML.
		 */
		$filtered = apply_filters( 'cachingout_response_is_cacheable', true === $verdict, $response_code, $response_headers, $html );

		if ( true !== $verdict ) {
			return $verdict;
		}

		if ( true !== $filtered ) {
			return 'BYPASS:filter:cachingout_response_is_cacheable';
		}

		return true;
	}

	/**
	 * The built-in write verdict, before the filter.
	 *
	 * @since 1.21.16
	 *
	 * @param int    $response_code    HTTP status code.
	 * @param array  $response_headers Associative array of header name => value.
	 * @param string $html             Full response body HTML.
	 * @return bool|string True when storable, or a bypass reason code.
	 */
	private function response_verdict( int $response_code, array $response_headers, string $html ) {
		// Only 200 responses are cached.
		if ( 200 !== $response_code ) {
			return 'BYPASS:status:' . $response_code;
		}

		// DONOTCACHEPAGE: the page asked not to be cached while it rendered.
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return 'BYPASS:donotcachepage';
		}

		// Responses setting an auth or excluded cookie are never written to cache.
		$cookie_bypass = $this->response_sets_forbidden_cookie( $response_headers );
		if ( $cookie_bypass ) {
			return $cookie_bypass;
		}

		// Nonce in HTML: cached nonces break form submissions.
		if ( '' !== $html && $this->html_contains_nonce( $html ) ) {
			return 'BYPASS:nonce';
		}

		return true;
	}

	/**
	 * Register an exclusion rule from a compat module.
	 *
	 * Compat modules (WooCommerce, EDD, WPML, etc.) call this to register
	 * their exclusions into the chokepoint. They never short-circuit caching
	 * themselves.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $id       Unique rule identifier (e.g. 'woocommerce_cart').
	 * @param callable $callback  Receives (string $url) and returns bool when the
	 *                            request should be excluded.
	 * @param string   $reason   Human-readable bypass reason for the debug header.
	 * @return void
	 */
	public function register_exclusion( string $id, callable $callback, string $reason ): void {
		$this->exclusions[] = array(
			'id'       => $id,
			'callback' => $callback,
			'reason'   => $reason,
		);
	}

	/**
	 * Remove a previously registered exclusion rule.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id The rule identifier passed to register_exclusion().
	 * @return void
	 */
	public function remove_exclusion( string $id ): void {
		foreach ( $this->exclusions as $index => $rule ) {
			if ( $id === $rule['id'] ) {
				unset( $this->exclusions[ $index ] );
				$this->exclusions = array_values( $this->exclusions );
				return;
			}
		}
	}

	/**
	 * Check whether a URL path starts with a given prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url    Full request path.
	 * @param string $prefix Prefix to check (trailing-slash aware).
	 * @return bool
	 */
	private function path_starts_with( string $url, string $prefix ): bool {
		return 0 === strpos( $url, $prefix );
	}

	/**
	 * Check whether a URL path is exactly a given path.
	 *
	 * Strips trailing slashes from the URL before comparison so '/wp-login.php'
	 * and '/wp-login.php/' both match.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url  Request path.
	 * @param string $path Expected exact path.
	 * @return bool
	 */
	private function path_is( string $url, string $path ): bool {
		// Strip query string from URL before comparison.
		$stripped = rtrim( strtok( $url, '?' ) ?: $url, '/' );
		return $stripped === $path;
	}

	/**
	 * Check whether a URL looks like a sitemap.
	 *
	 * Matches: /sitemap.xml, /sitemap_index.xml, /post-sitemap.xml,
	 *          /news-sitemap.xml, and any /sitemap(.*)\.xml variant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Request path.
	 * @return bool
	 */
	private function path_is_sitemap( string $url ): bool {
		$stripped = strtok( $url, '?' ) ?: $url;
		return 1 === preg_match( '#/(.*[-_])?sitemap.*\.xml$#i', $stripped );
	}

	/**
	 * Check whether a URL path ends with a given suffix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url    Request path (without query string).
	 * @param string $suffix Suffix including the dot (e.g. '.php').
	 * @return bool
	 */
	private function path_ends_with( string $url, string $suffix ): bool {
		$stripped = strtok( $url, '?' ) ?: $url;
		$length   = strlen( $suffix );
		return $length > 0 && substr( $stripped, -$length ) === $suffix;
	}

	/**
	 * Detect whether any of the given cookies is a WordPress auth cookie.
	 *
	 * WordPress auth cookies follow the pattern wordpress_logged_in_<hash>.
	 *
	 * @since 0.1.0
	 *
	 * @param array $cookies Cookie array (name => value).
	 * @return bool True when an auth cookie is present.
	 */
	private function detect_auth_cookie( array $cookies ): bool {
		// Primary check: wordpress_logged_in_<hash> cookies.
		foreach ( array_keys( $cookies ) as $name ) {
			if ( 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
		}

		// Also check AUTH_COOKIE constant and LOGGED_IN_COOKIE.
		if ( defined( 'AUTH_COOKIE' ) && isset( $cookies[ AUTH_COOKIE ] ) ) {
			return true;
		}

		if ( defined( 'LOGGED_IN_COOKIE' ) && isset( $cookies[ LOGGED_IN_COOKIE ] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether a specific cookie exists in the cookie array, supporting
	 * both exact-name match and prefix wildcards.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $cookies Cookie array (name => value).
	 * @param string $name    Cookie name or wildcard pattern (e.g. 'wp-settings-*').
	 * @return bool
	 */
	private function cookie_exists( array $cookies, string $name ): bool {
		// Exact match first.
		if ( array_key_exists( $name, $cookies ) ) {
			return true;
		}

		// Wildcard suffix: 'prefix-*' matches any cookie starting with 'prefix-'.
		if ( '*' === substr( $name, -1 ) ) {
			$prefix = rtrim( $name, '*' );
			foreach ( array_keys( $cookies ) as $cookie_name ) {
				if ( '' !== $prefix && 0 === strpos( $cookie_name, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether a user-agent string matches an exclusion pattern.
	 *
	 * Pattern is a substring match (case-insensitive).
	 *
	 * @since 0.1.0
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @param string $pattern    Substring to match (case-insensitive).
	 * @return bool
	 */
	private function user_agent_matches( string $user_agent, string $pattern ): bool {
		return false !== stripos( $user_agent, $pattern );
	}

	/**
	 * Match a URL against a user-configured exclusion pattern.
	 *
	 * Patterns support:
	 *   - Simple substring match (case-sensitive, path-aware).
	 *   - Wildcards: * matches any sequence of characters.
	 *   - Example: /category/freebies/* matches /category/freebies/ebook/.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url     Request URL path.
	 * @param string $pattern User-configured exclusion pattern.
	 * @return bool
	 */
	private function url_matches_pattern( string $url, string $pattern ): bool {
		// Strip query string from URL for pattern matching.
		$path_only = strtok( $url, '?' ) ?: $url;

		// Simple substring match (default, no wildcard).
		if ( false === strpos( $pattern, '*' ) ) {
			return false !== strpos( $path_only, $pattern );
		}

		// Wildcard: convert to regex.
		// preg_quote does NOT escape *, so we escape it manually first,
		// then replace the escaped asterisks with .* for the wildcard.
		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';

		return 1 === preg_match( $regex, $path_only );
	}

	/**
	 * Match a URL against built-in eCommerce path exclusions.
	 *
	 * Hard-coded well-known paths for WooCommerce and EDD. The compat modules
	 * will later refine these using actual page IDs via register_exclusion().
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Request URL path.
	 * @return string|null Bypass reason code, or null when no built-in path matches.
	 */
	private function match_builtin_paths( string $url ): ?string {
		$stripped = rtrim( strtok( $url, '?' ) ?: $url, '/' ) . '/';

		// WooCommerce well-known pages.
		if ( $this->path_starts_with( $stripped, '/cart/' ) ) {
			return 'BYPASS:woocommerce-cart';
		}

		if ( $this->path_starts_with( $stripped, '/checkout/' ) ) {
			return 'BYPASS:woocommerce-checkout';
		}

		if ( $this->path_starts_with( $stripped, '/my-account/' ) ) {
			return 'BYPASS:woocommerce-account';
		}

		// WooCommerce endpoints (often nested under my-account, but also direct).
		foreach ( array( '/order-received/', '/view-order/', '/edit-account/', '/edit-address/', '/lost-password/', '/payment-methods/', '/orders/', '/downloads/' ) as $wc_endpoint ) {
			if ( $this->path_starts_with( $stripped, $wc_endpoint ) ) {
				return 'BYPASS:woocommerce-account';
			}
		}

		// EDD well-known pages.
		if ( $this->path_starts_with( $stripped, '/purchase-history/' ) ) {
			return 'BYPASS:edd-purchase-history';
		}

		if ( $this->path_starts_with( $stripped, '/purchase-confirmation/' ) || $this->path_starts_with( $stripped, '/purchase-receipt/' ) ) {
			return 'BYPASS:edd-purchase-confirmation';
		}

		return null;
	}

	/**
	 * Check whether response headers set a forbidden (auth or excluded) cookie.
	 *
	 * Examines Set-Cookie headers and returns a bypass reason when a WordPress
	 * auth cookie is being set, or when a cookie name appears in the
	 * user-configured never_cache_cookies list.
	 *
	 * @since 0.1.0
	 *
	 * @param array $response_headers Associative array of header name => value.
	 * @return string|null Bypass reason, or null when no forbidden cookie is set.
	 */
	private function response_sets_forbidden_cookie( array $response_headers ): ?string {
		$set_cookie = $this->extract_header( $response_headers, 'Set-Cookie' );
		if ( '' === $set_cookie ) {
			return null;
		}

		// Extract cookie name from Set-Cookie header (before first '=').
		$cookie_name = strtok( $set_cookie, '=' );
		if ( false === $cookie_name || '' === $cookie_name ) {
			return null;
		}

		// WordPress auth cookies.
		if ( 0 === strpos( $cookie_name, 'wordpress_logged_in_' ) ) {
			return 'BYPASS:set-cookie:auth';
		}

		// A response that starts a PHP session is bound to one visitor.
		foreach ( self::SESSION_COOKIES as $session_cookie ) {
			if ( $this->cookie_name_matches( $cookie_name, $session_cookie ) ) {
				return 'BYPASS:set-cookie:session:' . $session_cookie;
			}
		}

		// A response that opens a cart or store session is bound to one visitor.
		foreach ( self::STORE_COOKIES as $store_cookie ) {
			if ( $this->cookie_name_matches( $cookie_name, $store_cookie ) ) {
				return 'BYPASS:set-cookie:store:' . $store_cookie;
			}
		}

		// Check user-configured excluded cookies.
		$excluded_cookies = $this->settings->get( 'cache', 'never_cache_cookies', array() );
		foreach ( $excluded_cookies as $excluded ) {
			if ( $this->cookie_name_matches( $cookie_name, $excluded ) ) {
				return 'BYPASS:set-cookie:' . $excluded;
			}
		}

		return null;
	}

	/**
	 * Extract a header value from the response headers array.
	 *
	 * Headers may be keyed with canonical casing ('Set-Cookie') or lowercase
	 * ('set-cookie'), or may use numeric keys with 'Header-Name: value' format.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $headers    Response headers array.
	 * @param string $header_name Header name to locate.
	 * @return string Header value, or empty string if not found.
	 */
	private function extract_header( array $headers, string $header_name ): string {
		// Direct key lookup (case-insensitive on the caller side; handle common variants).
		if ( isset( $headers[ $header_name ] ) ) {
			return (string) $headers[ $header_name ];
		}

		$lower = strtolower( $header_name );
		foreach ( $headers as $key => $value ) {
			if ( strtolower( $key ) === $lower ) {
				return (string) $value;
			}

			// Handle 'Header-Name: value' format on numeric keys.
			if ( is_int( $key ) && is_string( $value ) ) {
				$parts = explode( ':', $value, 2 );
				if ( count( $parts ) === 2 && strtolower( trim( $parts[0] ) ) === $lower ) {
					return trim( $parts[1] );
				}
			}
		}

		return '';
	}

	/**
	 * Check whether a cookie name matches an excluded-cookie entry.
	 *
	 * Supports exact match and wildcard suffix ('woocommerce_*').
	 *
	 * @since 0.1.0
	 *
	 * @param string $cookie_name Actual cookie name from Set-Cookie header.
	 * @param string $pattern     Exclusion pattern from settings.
	 * @return bool
	 */
	private function cookie_name_matches( string $cookie_name, string $pattern ): bool {
		if ( $cookie_name === $pattern ) {
			return true;
		}

		if ( '*' === substr( $pattern, -1 ) ) {
			$prefix = rtrim( $pattern, '*' );
			return '' !== $prefix && 0 === strpos( $cookie_name, $prefix );
		}

		return false;
	}

	/**
	 * Check whether a URL path ends with a known static-asset file extension.
	 *
	 * Static assets (images, CSS, JS, fonts, media) are never served by the
	 * page-cache subsystem — they should hit the web server's static-file
	 * handler, which uses the browser-caching headers we inject.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Request URL path.
	 * @return bool True when the path ends in a static-asset extension.
	 */
	private function path_ends_with_asset_extension( string $url ): bool {
		$stripped  = strtok( $url, '?' ) ?: $url;
		$lower     = strtolower( $stripped );
		$extensions = array(
			'.css', '.js', '.png', '.jpg', '.jpeg', '.gif',
			'.webp', '.svg', '.woff', '.woff2', '.ttf', '.eot',
			'.ico', '.xml', '.mp4', '.webm', '.ogg',
		);

		foreach ( $extensions as $ext ) {
			$len = strlen( $ext );
			if ( $len > 0 && substr( $lower, -$len ) === $ext ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether HTML contains an active WordPress nonce.
	 *
	 * Cached nonces break form submissions. The scan covers common nonce
	 * patterns: hidden input fields and wp_nonce_url() query parameters.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html Full page HTML.
	 * @return bool
	 */
	private function html_contains_nonce( string $html ): bool {
		// Hidden input field patterns.
		$patterns = array(
			'#<input[^>]*\bid=["\']?_wpnonce["\']?#i',
			'#<input[^>]*\bname=["\']?_wpnonce["\']?#i',
			'#<input[^>]*\bname=["\']?_wp_http_referer["\']?#i',
			'#name=["\']?woocommerce-login-nonce["\']?#i',
		);

		foreach ( $patterns as $pattern ) {
			if ( 1 === preg_match( $pattern, $html ) ) {
				return true;
			}
		}

		// wp_nonce_url adds ?_wpnonce=<hash> to URLs. Check for this in links
		// and forms, but carve out the logout-link nonce — every site with a
		// login/logout link in its menu would otherwise be uncacheable.
		// A logout link looks like: /wp-login.php?action=logout&_wpnonce=abc123
		if ( false !== strpos( $html, '_wpnonce=' ) ) {
			// Remove all logout-link nonce occurrences and re-check.
			// Pattern: action=logout followed by &_wpnonce=<hash> (hash is 10+ hex chars).
			$stripped = preg_replace(
				'#action=logout[^"\']*?&_wpnonce=[a-f0-9]{10,}#i',
				'action=logout',
				$html
			);

			if ( false !== strpos( $stripped, '_wpnonce=' ) ) {
				return true;
			}
		}

		return false;
	}
}