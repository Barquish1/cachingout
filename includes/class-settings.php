<?php
/**
 * Settings manager: single versioned option array with sanitization and schema
 * migration between versions.
 *
 * Every other subsystem depends on this class for option access. It owns the
 * `cachingout_settings` option and is the only class that reads or writes it
 * directly.
 *
 * Since 1.21.0 the schema is extensible: the free edition declares its own
 * sections and keys in DEFAULTS, the Pro add-on registers the rest through the
 * `cachingout_settings_defaults` / `cachingout_settings_sanitizers` /
 * `cachingout_sanitize_section` hooks. Stored sections and keys the running
 * schema does not know are kept verbatim on every read and every save, so a
 * deactivated Pro loses nothing. The effective view (get_all/get) hides the
 * settings of Pro features that are not available right now, per the gating
 * table (CachingOut_Features); stored() is the raw view saves work from.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings manager.
 *
 * Stores all plugin settings in a single auto-loaded option
 * (`cachingout_settings`) keyed by section. Handles sanitization callbacks,
 * default values, and schema migration when the plugin version changes.
 *
 * @since 0.1.0
 */
class CachingOut_Settings {

	/**
	 * Option key for the single settings array.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const OPTION_KEY = 'cachingout_settings';

	/**
	 * Version of the settings schema currently on disk.
	 *
	 * Stored as a top-level key (`_version`) inside the option array. When the
	 * running plugin version doesn't match, the migration routine runs.
	 *
	 * @since 0.1.0
	 * @since 1.21.17 0.1.19: the ignored-query-parameter list gains its defaults.
	 * @var string
	 */
	const SCHEMA_VERSION = '0.1.19';

	/**
	 * Query parameters ignored for caching on every install (the default of
	 * `cache.cache_query_strings`).
	 *
	 * Pure tracking parameters: Facebook (`fbclid`), Instagram (`igsh`,
	 * `igshid`), Google Ads (`gclid`, `gbraid`, `wbraid`, `dclid`), TikTok
	 * (`ttclid`), Microsoft (`msclkid`), Twitter/X (`twclid`), Yandex
	 * (`yclid`), LinkedIn (`li_fat_id`), Mailchimp (`mc_cid`, `mc_eid`),
	 * Google Analytics (`_ga`, `_gl`), HubSpot (`_hsenc`, `_hsmi`) and every
	 * campaign tag (`utm_*`). The pixels read them client-side from the
	 * browser URL; the server never does, so the plain page's cached copy is
	 * the right answer. Before 1.21.17 the list was empty and every social
	 * click took the uncached path (orgahair.pl, 2026-10-21: 0.12 s for the
	 * plain URL, 2.3 s with `?fbclid=`). Shipped as a DEFAULT, not a built-in
	 * rule, so the Cache tab shows the list and an admin can remove a name a
	 * plugin on their site reads server-side. Names that change output on
	 * some sites (`ref`, `lang`, `s`, `p`, `page`, `add-to-cart`, `orderby`)
	 * are deliberately absent. A trailing `*` matches a name prefix (see
	 * CachingOut_Cacheability::query_param_is_ignored()).
	 *
	 * @since 1.21.17
	 * @var string[]
	 */
	const DEFAULT_IGNORED_QUERY_STRINGS = array(
		'fbclid',
		'igshid',
		'igsh',
		'gclid',
		'gbraid',
		'wbraid',
		'dclid',
		'ttclid',
		'msclkid',
		'twclid',
		'yclid',
		'li_fat_id',
		'mc_cid',
		'mc_eid',
		'_ga',
		'_gl',
		'_hsenc',
		'_hsmi',
		'utm_*',
	);

	/**
	 * Filename of the runtime settings snapshot inside the cache root.
	 *
	 * The advanced-cache.php drop-in runs before WordPress has set up the
	 * database, so get_option() is unavailable. This PHP file (returning the
	 * settings array) is exported on activation and on every settings save so
	 * the drop-in can read current settings without touching the database.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const RUNTIME_CONFIG_FILENAME = 'runtime-config.php';

	/**
	 * Per-site runtime snapshot filename pattern on multisite (spec 28).
	 *
	 * Every site exports `runtime-config-<blog_id>.php`; the main site also
	 * refreshes the shared `runtime-config.php`, which the drop-in reads
	 * first (it carries the site list used to detect the blog) and falls
	 * back to when a site has no file of its own. Single-site installs
	 * never write a per-blog file.
	 *
	 * @since 0.1.20
	 * @var string
	 */
	const RUNTIME_CONFIG_BLOG_FILENAME = 'runtime-config-%d.php';

	/**
	 * Default settings for every FREE section and key.
	 *
	 * Keys are section names; values are associative arrays of setting keys and
	 * their defaults. Sections correspond to admin tabs. The Pro add-on adds
	 * its sections (preload, database, cdn, addons) and its keys in the shared
	 * sections (cache, media, file_optimization, insights) through the
	 * `cachingout_settings_defaults` filter; see defaults().
	 *
	 * @since 0.1.0
	 * @since 1.21.0 Free keys only.
	 * @var array
	 */
	const DEFAULTS = array(
		'cache'             => array(
			'enabled'                => true,
			// Lifespan is HOURS (UI + stored). Convert to seconds at expiry time.
			// Default 10 hours. Max 8760 (1 year). 0 = never auto-expire.
			'lifespan'               => 10,
			'separate_mobile_cache'  => false,
			// Ignored query parameters (1.21.17: prefilled with the tracking
			// list; an empty list means every query string bypasses).
			'cache_query_strings'    => self::DEFAULT_IGNORED_QUERY_STRINGS,
			// Filesystem check: admin notice (0.1.15).
			'fs_check_enabled'       => true,
			// Custom exclusion lists, one entry per line in the UI; free since
			// 1.21.16 (DECISIONS 2026-10-19). Additions to the built-in safety
			// exclusions, which apply regardless.
			'never_cache_urls'       => array(),
			'never_cache_cookies'    => array(),
			'never_cache_useragents' => array(),
		),
		'browser_cache'     => array(
			// OD-4 fast-by-default (2026-08-31): ON for NEW installs, written
			// at activation through the safe BUG-014 writer (backup, sanity
			// check, foreign-marker guard). Deactivation still never touches
			// .htaccess (BUG-010). Existing installs keep their previous
			// effective values via the 0.1.17 migration stamp.
			'expires' => true,
			'gzip'    => true,
			'brotli'  => true,
		),
		'media'             => array(
			// OD-4 fast-by-default (2026-08-31): lazyload ON for NEW installs.
			// lazyload_backgrounds stays OFF (it broke CSS on orgahair);
			// existing installs keep their previous effective values via the
			// 0.1.17 migration stamp.
			'lazyload_enabled'            => true,
			'lazyload_images'             => true,
			'lazyload_iframes'            => true,
			'lazyload_backgrounds'        => false,
			// Videos: no source download until near the viewport; pause when
			// scrolled away (0.1.16). Sub-toggle of lazyload_enabled.
			'lazyload_videos'             => true,
			'lazyload_exclude_above_fold' => true,
			'lazyload_above_fold_count'   => 3,
			'lazyload_exclusions'         => array(),
			'lazyload_add_dimensions'     => true,
			// LCP image priority: fetchpriority="high" + preload on the likely
			// LCP image (0.1.16). A wrong guess only wastes a hint - on by default.
			'lcp_priority_enabled'        => true,
			'lcp_priority_exclusions'     => array(),
		),
		'file_optimization' => array(
			// Off by default: these can change how a page looks (PRD 5.12).
			'minify_css'          => false,
			'minify_js'           => false,
			'defer_js'            => false,
			// The per-file controls ship WITH minification: minification
			// without them breaks themes with inline dependencies.
			'excluded_css'        => array(),
			'excluded_js'         => array(),
			'excluded_inline_css' => array(),
			'excluded_inline_js'  => array(),
		),
		'heartbeat'         => array(
			'heartbeat_frontend'    => true,
			'heartbeat_admin'       => 60,
			'heartbeat_post_editor' => 30,
			'dns_prefetch_domains'  => array(),
		),
		'insights'          => array(
			'enabled'       => true,
			'custom_pages'  => array(),
			'mobile_audits' => true,
		),
	);

	/**
	 * Sanitization callbacks for the FREE sections.
	 *
	 * Each section has a static method on this class named
	 * `sanitize_<section>( $values )` that returns the sanitized array.
	 * Sections without a registered callback are sanitized generically. Pro
	 * registers its own through `cachingout_settings_sanitizers` and extends
	 * the shared sections through `cachingout_sanitize_section`.
	 *
	 * @since 0.1.0
	 * @var array
	 */
	const SANITIZERS = array(
		'cache'             => array( __CLASS__, 'sanitize_cache' ),
		'browser_cache'     => array( __CLASS__, 'sanitize_browser_cache' ),
		'media'             => array( __CLASS__, 'sanitize_media' ),
		'file_optimization' => array( __CLASS__, 'sanitize_file_optimization' ),
		'heartbeat'         => array( __CLASS__, 'sanitize_heartbeat' ),
		'insights'          => array( __CLASS__, 'sanitize_insights' ),
	);

	/**
	 * Memo of the filtered defaults for this request.
	 *
	 * @since 1.21.0
	 * @var array|null
	 */
	private static $defaults_memo = null;

	/**
	 * Cached RAW settings (stored merged with defaults, unknown keys kept).
	 *
	 * @since 0.1.0
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Constructor.
	 *
	 * When a preloaded settings array is supplied (the drop-in passes the
	 * runtime-config snapshot), it is merged with defaults and used for every
	 * read - no database access ever occurs on that instance.
	 *
	 * @since 0.1.0
	 *
	 * @param array|null $preloaded Optional. Raw settings array to use instead
	 *                              of reading the option from the database.
	 */
	public function __construct( ?array $preloaded = null ) {
		if ( null !== $preloaded ) {
			$this->settings = $this->merge_with_defaults( $preloaded );
		}
	}

	/**
	 * The complete schema defaults: the free DEFAULTS plus whatever the Pro
	 * add-on registers. Memoised per request.
	 *
	 * @since 1.21.0
	 * @return array
	 */
	public static function defaults(): array {
		if ( null === self::$defaults_memo ) {
			$defaults = self::DEFAULTS;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the settings schema defaults.
				 *
				 * The Pro add-on adds its sections and keys here. A callback
				 * must only ADD sections or keys; free defaults are never
				 * changed through this filter.
				 *
				 * @since 1.21.0
				 *
				 * @param array $defaults Section => key => default.
				 */
				$filtered = apply_filters( 'cachingout_settings_defaults', $defaults );
				if ( is_array( $filtered ) ) {
					foreach ( $filtered as $section => $keys ) {
						if ( ! is_array( $keys ) ) {
							continue;
						}
						$defaults[ $section ] = isset( $defaults[ $section ] ) && is_array( $defaults[ $section ] )
							? array_merge( $keys, $defaults[ $section ] )
							: $keys;
					}
				}
			}
			self::$defaults_memo = $defaults;
		}

		return self::$defaults_memo;
	}

	/**
	 * The section sanitizers: the free SANITIZERS plus Pro's.
	 *
	 * @since 1.21.0
	 * @return array<string, callable>
	 */
	public static function sanitizers(): array {
		$sanitizers = self::SANITIZERS;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the per-section sanitizer callbacks.
			 *
			 * @since 1.21.0
			 *
			 * @param array $sanitizers Section => callable.
			 */
			$filtered = apply_filters( 'cachingout_settings_sanitizers', $sanitizers );
			if ( is_array( $filtered ) ) {
				foreach ( $filtered as $section => $callback ) {
					if ( is_callable( $callback ) && ! isset( $sanitizers[ $section ] ) ) {
						$sanitizers[ $section ] = $callback;
					}
				}
			}
		}
		return $sanitizers;
	}

	/**
	 * Forget the memoised schema (tests; a Pro activation mid-request).
	 *
	 * @since 1.21.0
	 * @return void
	 */
	public static function reset_schema(): void {
		self::$defaults_memo = null;
	}

	/**
	 * The plugin's cache directory: the ONE place it is spelled.
	 *
	 * `wp-content/cache/cachingout`. A page-caching plugin's files live
	 * under wp-content (the WordPress.org "caching plugins" exception to
	 * the uploads-directory rule), beside the advanced-cache.php drop-in
	 * that serves them before WordPress loads; wp_upload_dir() does not
	 * exist at that moment, so the location is WP_CONTENT_DIR, here, once.
	 * The drop-in and uninstall.php repeat the literal because nothing is
	 * loaded when they run; everything else calls this.
	 *
	 * @since 1.21.16
	 * @return string Absolute path, no trailing separator.
	 */
	public static function cache_dir(): string {
		return WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'cachingout';
	}

	/**
	 * Absolute path to the runtime settings snapshot file.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function runtime_config_path(): string {
		return self::cache_dir() . DIRECTORY_SEPARATOR . self::RUNTIME_CONFIG_FILENAME;
	}

	/**
	 * Absolute path to one site's runtime snapshot on multisite (spec 28).
	 *
	 * Lives next to the shared file in the cache root, not in the site's
	 * own cache directory, so a purge of that directory never removes it.
	 *
	 * @since 0.1.20
	 *
	 * @param int $blog_id Blog id.
	 * @return string
	 */
	public static function runtime_config_path_for_blog( int $blog_id ): string {
		return dirname( self::runtime_config_path() ) . DIRECTORY_SEPARATOR
			. sprintf( self::RUNTIME_CONFIG_BLOG_FILENAME, $blog_id );
	}

	/**
	 * Whether a cache-root entry is a runtime snapshot (shared or per-blog).
	 *
	 * Used by the purge to leave snapshots in place: on multisite the other
	 * sites' snapshots cannot be regenerated from this site's request.
	 *
	 * @since 0.1.20
	 *
	 * @param string $filename Basename of the entry.
	 * @return bool
	 */
	public static function is_runtime_config_file( string $filename ): bool {
		if ( self::RUNTIME_CONFIG_FILENAME === $filename ) {
			return true;
		}
		return 1 === preg_match( '/^runtime-config-\d+\.php$/', $filename );
	}

	/**
	 * Export the current settings to the runtime snapshot file.
	 *
	 * Written atomically (temp file + rename) so the drop-in never reads a
	 * half-written file. Must only be called when WordPress is fully loaded.
	 *
	 * On multisite (spec 28) the current site's snapshot goes to its own
	 * `runtime-config-<blog_id>.php`. The shared `runtime-config.php` is
	 * refreshed by the main site, and created by any site when it is
	 * missing (the drop-in returns without it, since it carries the site
	 * list). A save on a subsite therefore never overwrites the main
	 * site's settings - the latent defect this replaces.
	 *
	 * @since 0.1.0
	 * @return bool True on success.
	 */
	public function export_runtime_config(): bool {
		$shared = self::runtime_config_path();
		$dir    = dirname( $shared );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$content = $this->runtime_config_content();
		if ( ! is_multisite() ) {
			return self::write_snapshot( $shared, $content );
		}

		$blog_id = (int) get_current_blog_id();
		$ok      = self::write_snapshot( self::runtime_config_path_for_blog( $blog_id ), $content );

		if ( is_main_site() || ! file_exists( $shared ) ) {
			$ok = self::write_snapshot( $shared, $content ) && $ok;
		}

		return $ok;
	}

	/**
	 * Write one snapshot file atomically (temp file + rename).
	 *
	 * @since 0.1.20
	 *
	 * @param string $path    Destination path.
	 * @param string $content PHP source to write.
	 * @return bool True on success.
	 */
	private static function write_snapshot( string $path, string $content ): bool {
		$tmp = $path . '.' . uniqid( 'tmp', true );
		if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the plugin's own temp file after a failed write.
			@unlink( $tmp );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace: temp file in the same directory, then rename(). WP_Filesystem::move() is not atomic.
		if ( ! @rename( $tmp, $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the plugin's own temp file after a failed rename.
			@unlink( $tmp );
			return false;
		}

		return true;
	}

	/**
	 * The PHP source of the runtime snapshot for the current site.
	 *
	 * Built from the EFFECTIVE view: a Pro-only exclusion or the WebP variant
	 * never reaches the drop-in while Pro is unavailable (spec 30).
	 *
	 * @since 0.1.20
	 * @return string
	 */
	private function runtime_config_content(): string {
		$all = $this->get_all();

		// Export ONLY the sections the drop-in reads (cacheability + variant
		// selection). The full array carried Cloudflare/Sucuri credentials
		// into a web-root file the drop-in never needed them in (audit S4).
		// Missing sections fall back to defaults when the drop-in constructs
		// its Settings instance, so the subset is safe.
		$settings = array(
			'cache' => isset( $all['cache'] ) ? $all['cache'] : array(),
			'media' => isset( $all['media'] ) ? $all['media'] : array(),
		);

		// Derived, never stored: the language cookies the chokepoint may
		// ignore because the URL already carries the language (1.21.2).
		$settings['cache']['url_carried_variation_cookies'] = self::url_carried_variation_cookies();

		// Derived, never stored: path prefixes another plugin asks the drop-in
		// to bypass (the Partner Engine's /go/ links, 1.21.16).
		$settings['cache']['derived_never_cache_prefixes'] = self::derived_never_cache_prefixes();

		// Derived, never stored: the image variants the site serves (spec 36).
		// Empty without an add-on, so the key never splits by browser.
		$settings['cache']['image_variants'] = self::image_variants();

		// Derived, never stored: query parameters another plugin asks the
		// drop-in to ignore, on top of the admin's list (1.21.17).
		$settings['cache']['derived_ignored_query_strings'] = self::derived_ignored_query_strings();

		// Where the plugin is installed, so the drop-in loads the cache
		// classes from the real plugin directory instead of assuming
		// wp-content/plugins/cachingout (a renamed folder, a custom
		// WP_PLUGIN_DIR; WordPress.org review round 1). WP_PLUGIN_DIR itself
		// is not defined yet when the drop-in runs.
		$settings['_plugin_dir'] = defined( 'CACHINGOUT_PATH' ) ? wp_normalize_path( CACHINGOUT_PATH ) : '';

		// Add multisite metadata so the drop-in can detect the current blog ID
		// before WordPress loads (see CachingOut_Cache_Key::detect_blog_id).
		$settings['_multisite'] = is_multisite();
		if ( $settings['_multisite'] && function_exists( 'get_sites' ) ) {
			$sites = get_sites( array( 'number' => 0 ) );
			$settings['_multisite_sites'] = array();
			foreach ( $sites as $site ) {
				$settings['_multisite_sites'][] = array(
					'blog_id' => (int) $site->blog_id,
					'url'     => get_site_url( $site->blog_id ),
				);
			}
			$settings['_multisite_main_site'] = (int) get_main_site_id();
		}

		return "<?php\n// Caching Out runtime settings snapshot. Auto-generated - do not edit.\n"
			. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Generates the PHP settings snapshot file; not debug output.
			. 'return ' . var_export( $settings, true ) . ";\n";
	}

	/**
	 * The variation cookies whose language the request URL already carries.
	 *
	 * Polylang and WPML set their language cookie on every WordPress-rendered
	 * response in every URL mode. In directory / domain mode the path (or
	 * host) differs per language, so the cookie adds no variation and the
	 * chokepoint may ignore it (CachingOut_Cacheability, step 11b). In
	 * same-URL mode, or when the mode cannot be read, the cookie stays a
	 * bypass. Exported into the runtime snapshot as
	 * `cache.url_carried_variation_cookies`; never stored in the option.
	 * Only ever called with WordPress loaded.
	 *
	 * @since 1.21.2
	 * @return string[] Cookie names, exact (no wildcards).
	 */
	public static function url_carried_variation_cookies(): array {
		$cookies = array();

		if ( class_exists( 'CachingOut_Polylang' ) && CachingOut_Polylang::language_is_in_url() ) {
			$cookies[] = 'pll_language';
		}

		if ( class_exists( 'CachingOut_WPML' ) && CachingOut_WPML::language_is_in_url() ) {
			$cookies[] = 'wp-wpml_current_language';
			$cookies[] = '_icl_current_language';
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the variation cookies the chokepoint ignores because the
			 * URL already carries what they vary on.
			 *
			 * Only names present in CachingOut_Cacheability::VARIATION_COOKIES
			 * have any effect. Adding a cookie here for a same-URL setup serves
			 * the wrong language from cache; the callback owns that risk.
			 *
			 * @since 1.21.2
			 *
			 * @param string[] $cookies Cookie names detected from the active
			 *                          multilingual plugin's URL mode.
			 */
			$filtered = apply_filters( 'cachingout_url_carried_variation_cookies', $cookies );
			if ( is_array( $filtered ) ) {
				$cookies = array();
				foreach ( $filtered as $name ) {
					if ( is_string( $name ) && '' !== $name ) {
						$cookies[] = $name;
					}
				}
			}
		}

		return array_values( array_unique( $cookies ) );
	}

	/**
	 * The image variants the site serves: a subset of 'avif', 'webp'.
	 *
	 * Filled through `cachingout_image_variants` (the Pro add-on answers it
	 * from its WebP and AVIF settings while it is switched on), so the free
	 * plugin never reads another edition's setting. Exported into the
	 * runtime snapshot as `cache.image_variants`, where the cache key reads
	 * it (CachingOut_Cache_Key::image_variant()); never stored in the
	 * option. Unknown values are dropped; the order is the key's preference
	 * order. Only ever called with WordPress loaded.
	 *
	 * @since 1.23.0
	 * @return string[]
	 */
	public static function image_variants(): array {
		if ( ! function_exists( 'apply_filters' ) || ! class_exists( 'CachingOut_Cache_Key' ) ) {
			return array();
		}

		/**
		 * Filter the image variants the page cache serves.
		 *
		 * Return the formats whose copies a plugin writes into variant cache
		 * files: any of 'avif', 'webp'. A format listed here makes the page
		 * cache store a separate copy of each page for browsers that accept
		 * it; list one only when variant files for it are produced.
		 *
		 * @since 1.23.0
		 *
		 * @param string[] $variants Default empty.
		 */
		$filtered = apply_filters( 'cachingout_image_variants', array() );
		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$variants = array();
		foreach ( CachingOut_Cache_Key::IMAGE_VARIANTS as $variant ) {
			if ( in_array( $variant, $filtered, true ) ) {
				$variants[] = $variant;
			}
		}
		return $variants;
	}

	/**
	 * Path prefixes the drop-in must never serve from or write to cache.
	 *
	 * Filled by other plugins through `cachingout_derived_never_cache_prefixes`
	 * (the Partner Engine registers its `/go/` link prefix), so the bypass
	 * holds before WordPress loads. Exported into the runtime snapshot as
	 * `cache.derived_never_cache_prefixes`; never stored in the option. The
	 * chokepoint reports a match as `BYPASS:exclusion:partner-engine`
	 * (CachingOut_Cacheability, step 3b). The snapshot is re-exported on
	 * every purge_all(), which runs on plugin activation and deactivation.
	 * Only ever called with WordPress loaded.
	 *
	 * @since 1.21.16
	 * @return string[] Leading path prefixes, each starting with '/' and
	 *                  longer than '/'.
	 */
	public static function derived_never_cache_prefixes(): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return array();
		}

		/**
		 * Filter the leading path prefixes the page cache always bypasses.
		 *
		 * Each entry is a path prefix such as '/go/'. Entries that are not a
		 * string starting with '/', that are '/' alone (which would switch
		 * the page cache off for the whole site), or that carry a query
		 * string or fragment are dropped.
		 *
		 * @since 1.21.16
		 *
		 * @param string[] $prefixes Leading path prefixes. Default empty.
		 */
		$filtered = apply_filters( 'cachingout_derived_never_cache_prefixes', array() );
		if ( ! is_array( $filtered ) ) {
			return array();
		}

		$prefixes = array();
		foreach ( $filtered as $prefix ) {
			if ( ! is_string( $prefix ) ) {
				continue;
			}
			$prefix = trim( $prefix );
			if ( strlen( $prefix ) < 2 || '/' !== $prefix[0] || strpbrk( $prefix, '?#' ) !== false ) {
				continue;
			}
			$prefixes[] = $prefix;
		}

		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Query parameters other plugins ask the page cache to ignore.
	 *
	 * Filled through `cachingout_ignored_query_strings`; exported into the
	 * runtime snapshot as `cache.derived_ignored_query_strings`, never stored
	 * in the option and never shown on the Cache tab (the admin's own list is
	 * `cache.cache_query_strings`). The chokepoint treats the two lists as
	 * one. The snapshot is rewritten on every settings save and on every
	 * plugin activation or deactivation; a plugin that registers its names
	 * at another moment calls `( new CachingOut_Settings() )->export_runtime_config()`
	 * once afterwards so the drop-in sees them before WordPress loads.
	 *
	 * @since 1.21.17
	 * @return string[] Sanitized, de-duplicated names (a trailing `*` is a prefix match).
	 */
	public static function derived_ignored_query_strings(): array {
		if ( ! function_exists( 'apply_filters' ) ) {
			return array();
		}

		/**
		 * Filter the query parameters the page cache ignores in addition to
		 * the admin's "Ignored query parameters" list.
		 *
		 * A request whose parameters are all ignored is served the plain
		 * URL's cached copy; the browser URL is untouched. Append the names
		 * your plugin puts on its own links and never reads server-side.
		 * A trailing `*` matches a name prefix (`utm_*`); a bare `*` is
		 * dropped. Entries that are not strings are dropped.
		 *
		 * @since 1.21.17
		 *
		 * @param string[] $names Parameter names. Default empty.
		 */
		$filtered = apply_filters( 'cachingout_ignored_query_strings', array() );

		return self::sanitize_ignored_query_strings( is_array( $filtered ) ? $filtered : array() );
	}

	/**
	 * Normalise a list of ignored query parameter names.
	 *
	 * Keeps non-empty trimmed strings, drops a bare `*` (it would ignore every
	 * parameter, the "cache everything" mistake the empty list exists to
	 * prevent) and de-duplicates.
	 *
	 * @since 1.21.17
	 *
	 * @param array $names Raw entries.
	 * @return string[]
	 */
	public static function sanitize_ignored_query_strings( array $names ): array {
		$clean = array();
		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			$name = trim( $name );
			if ( '' === $name || '*' === $name ) {
				continue;
			}
			$clean[] = $name;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The RAW settings: stored values merged with the schema defaults, with
	 * every stored section and key the schema does not know kept verbatim.
	 * This is the view saves and exports of the stored state work from.
	 *
	 * @since 1.21.0
	 * @return array
	 */
	public function stored(): array {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$this->settings = $this->merge_with_defaults( $stored );
		$this->maybe_migrate( $stored );

		return $this->settings;
	}

	/**
	 * Retrieve all settings, merged with defaults - the EFFECTIVE view.
	 *
	 * The settings of Pro features that are not available right now read as
	 * absent here (and therefore as their defaults through get()); the stored
	 * values are untouched. See CachingOut_Features::masked_settings().
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function get_all() {
		return self::mask_unavailable( $this->stored() );
	}

	/**
	 * Remove the settings of unavailable Pro features from a settings array.
	 *
	 * @since 1.21.0
	 *
	 * @param array $settings Settings (section => key => value).
	 * @return array
	 */
	public static function mask_unavailable( array $settings ): array {
		if ( ! class_exists( 'CachingOut_Features' ) ) {
			return $settings;
		}
		foreach ( CachingOut_Features::masked_settings() as $path ) {
			$parts = explode( '.', $path, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			list( $section, $key ) = $parts;
			if ( isset( $settings[ $section ] ) && is_array( $settings[ $section ] ) ) {
				unset( $settings[ $section ][ $key ] );
				// A section the free edition does not define (preload, cdn,
				// addons, database) disappears with its last key.
				if ( empty( $settings[ $section ] ) && ! isset( self::DEFAULTS[ $section ] ) ) {
					unset( $settings[ $section ] );
				}
			}
		}
		return $settings;
	}

	/**
	 * Retrieve a single setting value (effective view).
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section key (e.g. 'cache').
	 * @param string $key     Setting key within the section.
	 * @param mixed  $default Fallback value if the setting doesn't exist.
	 * @return mixed
	 */
	public function get( $section, $key, $default = null ) {
		$all = $this->get_all();

		if ( isset( $all[ $section ][ $key ] ) ) {
			return $all[ $section ][ $key ];
		}

		$defaults = self::defaults();
		if ( isset( $defaults[ $section ][ $key ] ) ) {
			return $defaults[ $section ][ $key ];
		}

		return $default;
	}

	/**
	 * Retrieve all settings for a single section (effective view).
	 *
	 * @since 0.1.1
	 *
	 * @param string $section Section key.
	 * @return array Section settings merged with defaults.
	 */
	public function get_section( string $section ): array {
		$all = $this->get_all();

		if ( isset( $all[ $section ] ) && is_array( $all[ $section ] ) ) {
			return $all[ $section ];
		}

		$defaults = self::defaults();
		if ( isset( $defaults[ $section ] ) ) {
			return $defaults[ $section ];
		}

		return array();
	}

	/**
	 * Save settings for one or more sections.
	 *
	 * Accepts a complete settings array or a partial section update. When
	 * `$section` is provided, only that section is updated. Works from the
	 * RAW stored view, so a masked Pro value is never written back as its
	 * default (no data loss while Pro is inactive).
	 *
	 * @since 0.1.0
	 *
	 * @param array       $values  Associative array of settings to save.
	 * @param string|null $section Optional. Section key to update. When null,
	 *                             replaces every KNOWN section; unknown stored
	 *                             sections are kept.
	 * @return bool True on successful update.
	 */
	public function save( $values, $section = null ) {
		$before  = $this->stored();
		$current = $before;
		$schema  = self::defaults();

		if ( null !== $section ) {
			$sanitized = $this->sanitize_section( $section, $values );
			// Merge with existing section values so keys not in the
			// input keep their current stored value rather than being
			// reset to defaults.
			$current[ $section ] = array_merge(
				isset( $current[ $section ] ) && is_array( $current[ $section ] ) ? $current[ $section ] : array(),
				$sanitized
			);
		} else {
			foreach ( $values as $sec => $vals ) {
				if ( isset( $schema[ $sec ] ) && is_array( $vals ) ) {
					$current[ $sec ] = array_merge(
						isset( $current[ $sec ] ) && is_array( $current[ $sec ] ) ? $current[ $sec ] : array(),
						$this->sanitize_section( $sec, $vals )
					);
				}
			}
		}

		$current['_version'] = self::SCHEMA_VERSION;

		// Refresh the in-memory cache to the intended state BEFORE writing:
		// update_option() fires `update_option_{key}` synchronously, and a
		// listener holding this same instance (CachingOut_Plugin::
		// on_settings_saved -> Browser_Cache::write) must see the new values.
		// BUG-014: with the refresh after the write, the browser-cache
		// writer read the stale cache, saw every toggle off, and silently
		// removed blocks that did not exist. (update_option also returns
		// false when the value is unchanged, so the refresh is unconditional.)
		$this->settings = $current;

		// First-ever save: update_option() on a missing row delegates to
		// add_option(), which fires add_option_{key} - NOT
		// update_option_{key} - so the browser-cache writer would never run
		// on a fresh install's first save. Create a hook-inert stub row
		// first so every save is a real update. (Pre-0.1.17 the migration
		// persisted from inside get_all() and masked this; that persist was
		// removed with OD-4 because it fired the hook chain mid-read.)
		if ( false === get_option( self::OPTION_KEY ) ) {
			add_option( self::OPTION_KEY, array(), '', false );
		}

		$updated = update_option( self::OPTION_KEY, $current, false );

		// Keep the drop-in's runtime snapshot in sync with every save.
		$this->export_runtime_config();

		/**
		 * Fires after settings are written (including no-op writes).
		 *
		 * Used by Phase 7a override telemetry. Listeners must never throw.
		 *
		 * @since 0.1.3
		 *
		 * @param array       $before  Settings snapshot before the save.
		 * @param array       $after   Settings snapshot after the save.
		 * @param string|null $section Section key when a partial save, else null.
		 */
		do_action( 'cachingout_settings_saved', $before, $current, $section );

		return $updated;
	}

	/**
	 * Drop the in-memory cache so the next read comes from the database.
	 *
	 * For listeners that hold a different Settings instance from the one
	 * that performed the save (BUG-014).
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function reset_cache() {
		$this->settings = null;
	}

	/**
	 * Delete the settings option entirely.
	 *
	 * Used during uninstall.
	 *
	 * @since 0.1.0
	 * @return bool True on successful deletion.
	 */
	public function delete() {
		$this->settings = null;
		return delete_option( self::OPTION_KEY );
	}

	/**
	 * Sanitize cache section settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values Raw input values.
	 * @return array Sanitized settings merged with defaults.
	 */
	public static function sanitize_cache( $values ) {
		$defaults  = self::DEFAULTS['cache'];
		$sanitized = array();

		// Only process keys actually present in the input so that partial
		// section updates don't overwrite other keys with defaults.
		if ( array_key_exists( 'enabled', $values ) ) {
			$sanitized['enabled'] = ! empty( $values['enabled'] );
		}

		if ( array_key_exists( 'lifespan', $values ) ) {
			// Hours: 0 = never expire; clamp to 8760 (1 year).
			$hours = absint( $values['lifespan'] );
			// Migrate legacy seconds values mistakenly stored as hours-scale.
			if ( $hours > 8760 ) {
				// e.g. 36000 seconds -> 10 hours.
				$hours = (int) round( $hours / HOUR_IN_SECONDS );
			}
			if ( $hours > 8760 ) {
				$hours = 8760;
			}
			$sanitized['lifespan'] = $hours;
		}

		if ( array_key_exists( 'separate_mobile_cache', $values ) ) {
			$sanitized['separate_mobile_cache'] = ! empty( $values['separate_mobile_cache'] );
		}

		if ( array_key_exists( 'cache_query_strings', $values ) ) {
			$sanitized['cache_query_strings'] = self::sanitize_ignored_query_strings(
				self::sanitize_textarea_lines( $values, 'cache_query_strings', $defaults )
			);
		}

		if ( array_key_exists( 'fs_check_enabled', $values ) ) {
			$sanitized['fs_check_enabled'] = ! empty( $values['fs_check_enabled'] );
		}

		foreach ( array( 'never_cache_urls', 'never_cache_cookies', 'never_cache_useragents' ) as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$sanitized[ $key ] = self::sanitize_textarea_lines( $values, $key, $defaults );
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize a textarea-style setting (one value per line).
	 *
	 * Public since 1.21.0 so the Pro add-on's section extensions use the
	 * same rule as the free sanitizers.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $values   Raw input values.
	 * @param string $key      Setting key within the values array.
	 * @param array  $defaults Default section values.
	 * @return array
	 */
	public static function sanitize_textarea_lines( $values, $key, $defaults ) {
		if ( ! isset( $values[ $key ] ) ) {
			return isset( $defaults[ $key ] ) ? $defaults[ $key ] : array();
		}

		if ( is_array( $values[ $key ] ) ) {
			return array_values(
				array_filter(
					array_map( 'sanitize_text_field', array_map( 'trim', $values[ $key ] ) )
				)
			);
		}

		$lines = explode( "\n", (string) $values[ $key ] );
		$lines = array_map( 'trim', $lines );
		$lines = array_map( 'sanitize_text_field', $lines );
		$lines = array_filter( $lines, function ( $line ) {
			return '' !== $line;
		} );

		return array_values( $lines );
	}

	/**
	 * Sanitize a single section's values using its registered callback, then
	 * let the Pro add-on sanitize the keys it adds to the same section.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section Section key.
	 * @param array  $values  Raw values for that section.
	 * @return array Sanitized values.
	 */
	private function sanitize_section( $section, $values ) {
		$values     = is_array( $values ) ? $values : array();
		$sanitizers = self::sanitizers();

		if ( isset( $sanitizers[ $section ] ) ) {
			$sanitized = call_user_func( $sanitizers[ $section ], $values );
		} else {
			// Generic sanitization for sections without a dedicated callback.
			$sanitized = array_map( 'sanitize_text_field', $values );
		}

		/**
		 * Filter a sanitized section so an add-on can sanitize the keys it
		 * registered in a shared section. A callback must only ADD keys that
		 * are present in $values; it never changes a free key.
		 *
		 * @since 1.21.0
		 *
		 * @param array  $sanitized Sanitized values so far.
		 * @param string $section   Section key.
		 * @param array  $values    Raw input values.
		 */
		$extended = apply_filters( 'cachingout_sanitize_section', $sanitized, $section, $values );

		return is_array( $extended ) ? $extended : $sanitized;
	}

	/**
	 * Merge stored settings with defaults, filling in any missing keys, and
	 * keep every stored section or key the schema does not know.
	 *
	 * @since 0.1.0
	 * @since 1.21.0 Unknown sections and keys are preserved verbatim.
	 *
	 * @param array $stored The raw stored option value.
	 * @return array Complete settings array.
	 */
	private function merge_with_defaults( $stored ) {
		$merged   = array();
		$defaults = self::defaults();

		foreach ( $defaults as $section => $section_defaults ) {
			if ( isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) ) {
				$merged[ $section ] = array_merge( $section_defaults, $stored[ $section ] );
			} else {
				$merged[ $section ] = $section_defaults;
			}
		}

		// Sections the running schema does not declare (a deactivated Pro's)
		// travel along untouched.
		foreach ( $stored as $section => $values ) {
			if ( '_version' === $section || isset( $merged[ $section ] ) ) {
				continue;
			}
			$merged[ $section ] = $values;
		}

		$merged['_version'] = isset( $stored['_version'] ) ? $stored['_version'] : '0.0.0';

		return $merged;
	}

	/**
	 * Sanitize browser_cache section settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values Raw input values.
	 * @return array Sanitized settings merged with defaults.
	 */
	public static function sanitize_browser_cache( $values ) {
		$sanitized = array();

		if ( array_key_exists( 'expires', $values ) ) {
			$sanitized['expires'] = ! empty( $values['expires'] );
		}

		if ( array_key_exists( 'gzip', $values ) ) {
			$sanitized['gzip'] = ! empty( $values['gzip'] );
		}

		if ( array_key_exists( 'brotli', $values ) ) {
			$sanitized['brotli'] = ! empty( $values['brotli'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize media section settings.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values Raw input values.
	 * @return array Sanitized settings merged with defaults.
	 */
	public static function sanitize_media( $values ) {
		$sanitized = array();

		if ( array_key_exists( 'lazyload_enabled', $values ) ) {
			$sanitized['lazyload_enabled'] = ! empty( $values['lazyload_enabled'] );
		}

		// Sub-toggles: images, iframes, backgrounds, videos, dimensions, LCP.
		$sub_toggles = array(
			'lazyload_images',
			'lazyload_iframes',
			'lazyload_backgrounds',
			'lazyload_videos',
			'lazyload_exclude_above_fold',
			'lazyload_add_dimensions',
			'lcp_priority_enabled',
		);
		foreach ( $sub_toggles as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$sanitized[ $key ] = ! empty( $values[ $key ] );
			}
		}

		// Above-fold count: integer, clamped 0-10 (0 effectively disables via count).
		if ( array_key_exists( 'lazyload_above_fold_count', $values ) ) {
			$sanitized['lazyload_above_fold_count'] = min( 10, max( 0, (int) ( $values['lazyload_above_fold_count'] ?? 3 ) ) );
		}

		// Exclusion patterns: newline-separated textarea (or an array).
		foreach ( array( 'lazyload_exclusions', 'lcp_priority_exclusions' ) as $list_key ) {
			if ( ! array_key_exists( $list_key, $values ) ) {
				continue;
			}
			$sanitized[ $list_key ] = self::sanitize_textarea_lines( $values, $list_key, self::DEFAULTS['media'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize file_optimization section settings (free keys).
	 *
	 * @since 0.1.0
	 *
	 * @param array $values Raw input values.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_file_optimization( $values ) {
		$sanitized = array();

		foreach ( array( 'minify_css', 'minify_js', 'defer_js' ) as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$sanitized[ $key ] = ! empty( $values[ $key ] );
			}
		}

		// String arrays: split on newlines, sanitize each line as URL text.
		foreach ( array( 'excluded_css', 'excluded_js', 'excluded_inline_css', 'excluded_inline_js' ) as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$sanitized[ $key ] = self::sanitize_textarea_lines( $values, $key, self::DEFAULTS['file_optimization'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize heartbeat section settings.
	 *
	 * @since 0.1.1
	 *
	 * @param array $values Raw input values.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_heartbeat( $values ) {
		$sanitized = array();

		if ( array_key_exists( 'heartbeat_frontend', $values ) ) {
			$sanitized['heartbeat_frontend'] = ! empty( $values['heartbeat_frontend'] );
		}

		if ( array_key_exists( 'heartbeat_admin', $values ) ) {
			$admin = absint( $values['heartbeat_admin'] );
			if ( $admin < 15 ) {
				$admin = 15;
			}
			if ( $admin > 120 ) {
				$admin = 120;
			}
			$sanitized['heartbeat_admin'] = $admin;
		}

		if ( array_key_exists( 'heartbeat_post_editor', $values ) ) {
			$post = absint( $values['heartbeat_post_editor'] );
			if ( $post < 15 ) {
				$post = 15;
			}
			if ( $post > 120 ) {
				$post = 120;
			}
			$sanitized['heartbeat_post_editor'] = $post;
		}

		// DNS prefetch domains: textarea, one domain per line.
		if ( array_key_exists( 'dns_prefetch_domains', $values ) ) {
			$sanitized['dns_prefetch_domains'] = self::sanitize_textarea_lines( $values, 'dns_prefetch_domains', self::DEFAULTS['heartbeat'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize insights section settings (free keys: the Page Audit's).
	 *
	 * @since 0.1.0
	 *
	 * @param array $values Raw input values.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_insights( $values ) {
		$sanitized = array();

		if ( array_key_exists( 'enabled', $values ) ) {
			$sanitized['enabled'] = ! empty( $values['enabled'] );
		}

		if ( array_key_exists( 'custom_pages', $values ) ) {
			$raw   = $values['custom_pages'];
			$lines = is_array( $raw ) ? $raw : explode( "\n", (string) $raw );
			$sanitized['custom_pages'] = array_values(
				array_filter(
					array_map( 'esc_url_raw', array_map( 'trim', $lines ) )
				)
			);
		}

		if ( array_key_exists( 'mobile_audits', $values ) ) {
			$sanitized['mobile_audits'] = ! empty( $values['mobile_audits'] );
		}

		return $sanitized;
	}

	/**
	 * Convert stored lifespan (hours) to seconds for file mtime comparisons.
	 *
	 * @since 0.1.11
	 *
	 * @param int $hours Lifespan in hours (0 = never expire).
	 * @return int Seconds, or 0 when never expire.
	 */
	public static function lifespan_to_seconds( $hours ): int {
		$hours = (int) $hours;
		if ( $hours <= 0 ) {
			return 0;
		}
		// Legacy: values that look like seconds (e.g. 36000).
		if ( $hours > 8760 ) {
			return $hours;
		}
		$hour = defined( 'HOUR_IN_SECONDS' ) ? (int) HOUR_IN_SECONDS : 3600;
		return $hours * $hour;
	}

	/**
	 * Run schema migration if the stored version doesn't match the current one.
	 *
	 * @since 0.1.0
	 *
	 * @param array $stored The raw stored option value.
	 * @return void
	 */
	private function maybe_migrate( $stored ) {
		$stored_version = isset( $stored['_version'] ) ? $stored['_version'] : '0.0.0';

		if ( version_compare( $stored_version, self::SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		// A genuinely FRESH install (no stored option at all) has nothing to
		// migrate: run on the in-memory defaults and skip every upgrade
		// gate. Without this, the gates below treated "empty" as
		// "pre-0.1.11 install" and stamped the fast defaults back off
		// (OD-4, 0.1.17). Deliberately NO update_option here: persisting
		// from inside get_all() fires the update_option_* hook chain
		// (on_settings_saved -> Browser_Cache::write) mid-read. Persistence
		// happens at activation (save_default_settings) or the first save.
		if ( ! is_array( $stored ) || array() === $stored ) {
			$this->settings['_version'] = self::SCHEMA_VERSION;
			return;
		}

		// 0.1.11: alpha safe-mode + lifespan hours fix (BUG-013 / lifespan).
		if ( version_compare( $stored_version, '0.1.11', '<' ) ) {
			// Lifespan: convert legacy seconds-scale values to hours.
			if ( isset( $this->settings['cache']['lifespan'] ) ) {
				$life = (int) $this->settings['cache']['lifespan'];
				if ( $life > 8760 ) {
					$hour = defined( 'HOUR_IN_SECONDS' ) ? (int) HOUR_IN_SECONDS : 3600;
					$life = (int) max( 1, round( $life / $hour ) );
				}
				if ( $life > 8760 ) {
					$life = 8760;
				}
				$this->settings['cache']['lifespan'] = $life;
			}

			// Force front-safe flags so leftover alpha options cannot strip CSS.
			// The Pro-owned keys are stamped too when present: a pre-0.1.11
			// value must not survive into an install that later gains Pro.
			if ( ! isset( $this->settings['file_optimization'] ) || ! is_array( $this->settings['file_optimization'] ) ) {
				$this->settings['file_optimization'] = self::DEFAULTS['file_optimization'];
			}
			foreach ( array(
				'minify_css',
				'minify_js',
				'combine_css',
				'combine_js',
				'optimize_css_delivery',
				'defer_js',
				'delay_js',
				'google_fonts_optimize',
				'font_self_host',
			) as $flag ) {
				$this->settings['file_optimization'][ $flag ] = false;
			}

			if ( ! isset( $this->settings['media'] ) || ! is_array( $this->settings['media'] ) ) {
				$this->settings['media'] = self::DEFAULTS['media'];
			}
			$this->settings['media']['lazyload_enabled']     = false;
			$this->settings['media']['lazyload_backgrounds'] = false;

			if ( isset( $this->settings['preload'] ) && is_array( $this->settings['preload'] ) ) {
				$this->settings['preload']['enabled'] = false;
			}

			if ( isset( $this->settings['cdn'] ) && is_array( $this->settings['cdn'] ) ) {
				$this->settings['cdn']['enabled'] = false;
			}
		}

		// 0.1.17 (OD-4 fast-by-default): the DEFAULTS for the browser-cache
		// toggles and the LazyLoad master flipped to true for NEW installs.
		// An existing install that never saved those keys previously ran with
		// them OFF (the old defaults) - stamp that effective value explicitly
		// so an update never changes live behaviour. Keys the user saved are
		// left exactly as saved.
		if ( version_compare( $stored_version, '0.1.17', '<' ) ) {
			if ( ! isset( $this->settings['browser_cache'] ) || ! is_array( $this->settings['browser_cache'] ) ) {
				$this->settings['browser_cache'] = self::DEFAULTS['browser_cache'];
			}
			foreach ( array( 'expires', 'gzip', 'brotli' ) as $bc_key ) {
				if ( ! isset( $stored['browser_cache'][ $bc_key ] ) ) {
					$this->settings['browser_cache'][ $bc_key ] = false;
				}
			}

			if ( ! isset( $this->settings['media'] ) || ! is_array( $this->settings['media'] ) ) {
				$this->settings['media'] = self::DEFAULTS['media'];
			}
			if ( ! isset( $stored['media']['lazyload_enabled'] ) ) {
				$this->settings['media']['lazyload_enabled'] = false;
			}
		}

		// 0.1.19 (1.21.17): the DEFAULT of cache.cache_query_strings is the
		// tracking-parameter list. An existing install whose list is empty
		// had the old default, not a choice (every social click bypassed the
		// cache), so it gets the new default. A non-empty list is the admin's
		// own and is kept exactly as saved; the Cache tab's "Restore default
		// parameters" adds the defaults back on request.
		if ( version_compare( $stored_version, '0.1.19', '<' ) ) {
			if ( ! isset( $this->settings['cache'] ) || ! is_array( $this->settings['cache'] ) ) {
				$this->settings['cache'] = self::DEFAULTS['cache'];
			}
			if ( empty( $stored['cache']['cache_query_strings'] ) || ! is_array( $stored['cache']['cache_query_strings'] ) ) {
				$this->settings['cache']['cache_query_strings'] = self::DEFAULT_IGNORED_QUERY_STRINGS;
			}
		}

		$this->settings['_version'] = self::SCHEMA_VERSION;
		update_option( self::OPTION_KEY, $this->settings, false );
		$this->export_runtime_config();
	}
}
