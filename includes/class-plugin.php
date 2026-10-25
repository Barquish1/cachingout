<?php
/**
 * Plugin container and bootstrapper (free edition).
 *
 * Instantiates and wires every free subsystem. Holds no business logic - it
 * is a dependency-injection container that creates objects and connects
 * them. The Pro add-on gets this instance at `plugins_loaded` priority 20
 * (after run() at 10) and extends it through the accessors and the hooks
 * documented in spec 30; nothing here knows a Pro class by name.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin container.
 *
 * One instance per request, created at `plugins_loaded`. Provides a single
 * access point to every subsystem so that no other class needs to know about
 * instantiation order or wiring.
 *
 * @since 0.1.0
 */
class CachingOut_Plugin {

	/**
	 * Single plugin instance.
	 *
	 * @since 0.1.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Settings manager.
	 *
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Cacheability chokepoint.
	 *
	 * @var CachingOut_Cacheability
	 */
	private $cacheability;

	/**
	 * Page cache - write/serve/delete cached HTML files.
	 *
	 * @var CachingOut_Page_Cache
	 */
	private $page_cache;

	/**
	 * Purge event hooks - deletes cached files on content changes.
	 *
	 * @var CachingOut_Purge
	 */
	private $purge;

	/**
	 * Expiry - cron-based sweep of expired cache files.
	 *
	 * @var CachingOut_Expiry
	 */
	private $expiry;

	/**
	 * WooCommerce compat - automatic eCommerce exclusions.
	 *
	 * @var CachingOut_WooCommerce
	 */
	private $woocommerce;

	/**
	 * EDD compat - automatic eCommerce exclusions.
	 *
	 * @var CachingOut_EDD
	 */
	private $edd;

	/**
	 * Page builder compat - builder edit/preview mode exclusions.
	 *
	 * @var CachingOut_Page_Builders
	 */
	private $page_builders;

	/**
	 * Browser cache - Expires headers, GZIP, and Brotli config.
	 *
	 * @var CachingOut_Browser_Cache
	 */
	private $browser_cache;

	/**
	 * LazyLoad - images, iframes, CSS background images.
	 *
	 * @var CachingOut_LazyLoad
	 */
	private $lazyload;

	/**
	 * LCP image priority filter.
	 *
	 * @var CachingOut_LCP_Priority
	 */
	private $lcp_priority;

	/**
	 * Admin UI controller.
	 *
	 * @var CachingOut_Admin
	 */
	private $admin;

	/**
	 * Admin-bar integration.
	 *
	 * @var CachingOut_Admin_Bar
	 */
	private $admin_bar;

	/**
	 * Shared optimization exclusions registry.
	 *
	 * @var CachingOut_Optimization_Exclusions
	 */
	private $opt_exclusions;

	/**
	 * Optimization pipeline orchestrator.
	 *
	 * @var CachingOut_Optimization_Pipeline
	 */
	private $opt_pipeline;

	/**
	 * Database cleanup engine (manual operations in the free edition).
	 *
	 * @var CachingOut_DB_Cleanup|null
	 */
	private $db_cleanup = null;

	/**
	 * WPML compat - purge hooks for translation events.
	 *
	 * @var CachingOut_WPML
	 */
	private $wpml;

	/**
	 * Polylang compat - purge hooks for translation events.
	 *
	 * @var CachingOut_Polylang
	 */
	private $polylang;

	/**
	 * Setup Wizard controller.
	 *
	 * @var CachingOut_Setup_Wizard
	 */
	private $setup_wizard;

	/**
	 * Insights tab orchestrator (the Page Audit in the free edition).
	 *
	 * @var CachingOut_Insights
	 */
	private $insights;

	/**
	 * Heartbeat control - throttle/disable WordPress heartbeat API.
	 *
	 * @var CachingOut_Heartbeat
	 */
	private $heartbeat;

	/**
	 * DNS prefetch/preconnect - inject link tags for external domains.
	 *
	 * @var CachingOut_DNS_Prefetch
	 */
	private $dns_prefetch;

	/**
	 * Object cache cooperation notice - admin info when Redis/Memcached active.
	 *
	 * @var CachingOut_Object_Cache_Notice
	 */
	private $object_cache_notice;

	/**
	 * Return the single plugin instance, creating it if necessary.
	 *
	 * @since 0.1.0
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor - use ::instance().
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->wire();

		/**
		 * Fires once the free container is wired and before run(). The Pro
		 * add-on (and tests) receive the instance here.
		 *
		 * @since 1.21.0
		 *
		 * @param CachingOut_Plugin $plugin The container.
		 */
		do_action( 'cachingout_loaded', $this );
	}

	/**
	 * Require class files.
	 *
	 * Each file is required exactly once, in dependency order. The settings
	 * class loads first because every other subsystem reads settings.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private function load_dependencies() {
		// BUG-012: only load a production vendor autoload. A full composer --dev
		// autoload requires phpunit/phpstan files; if those are absent the
		// require fatals on every request including wp-admin (0.1.9 outage).
		$autoload = CACHINGOUT_PATH . 'vendor/autoload.php';
		if ( file_exists( $autoload ) ) {
			try {
				require_once $autoload;
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
					error_log( 'Caching Out vendor autoload failed: ' . $e->getMessage() );
				}
			}
		}

		require_once CACHINGOUT_PATH . 'includes/class-brand.php';
		require_once CACHINGOUT_PATH . 'includes/class-icons.php';
		require_once CACHINGOUT_PATH . 'includes/class-cache-status.php';
		require_once CACHINGOUT_PATH . 'includes/class-features.php';
		require_once CACHINGOUT_PATH . 'includes/class-settings.php';
		require_once CACHINGOUT_PATH . 'includes/class-filesystem-check.php';
		require_once CACHINGOUT_PATH . 'includes/cache/class-rival-remnants.php';
		require_once CACHINGOUT_PATH . 'includes/cache/class-cacheability.php';
		require_once CACHINGOUT_PATH . 'includes/cache/class-cache-key.php';
		require_once CACHINGOUT_PATH . 'includes/cache/class-page-cache.php';
		require_once CACHINGOUT_PATH . 'includes/cache/class-purge.php';
		require_once CACHINGOUT_PATH . 'includes/cache/class-expiry.php';
		require_once CACHINGOUT_PATH . 'includes/compat/class-woocommerce.php';
		require_once CACHINGOUT_PATH . 'includes/compat/class-edd.php';
		require_once CACHINGOUT_PATH . 'includes/compat/class-page-builders.php';
		require_once CACHINGOUT_PATH . 'includes/cache/class-browser-cache.php';

		require_once CACHINGOUT_PATH . 'includes/class-script-guard.php';
		require_once CACHINGOUT_PATH . 'includes/class-local-file.php';
		require_once CACHINGOUT_PATH . 'includes/media/class-lazyload.php';
		require_once CACHINGOUT_PATH . 'includes/media/class-lcp-priority.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-exclusions.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-asset-catalogue.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-minified-files.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-minify-css.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-minify-js.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-defer-js.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-heartbeat.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-dns-prefetch.php';
		require_once CACHINGOUT_PATH . 'includes/optimization/class-pipeline.php';
		require_once CACHINGOUT_PATH . 'includes/class-object-cache-notice.php';
		require_once CACHINGOUT_PATH . 'includes/database/class-db-cleanup.php';
		require_once CACHINGOUT_PATH . 'includes/compat/class-wpml.php';
		require_once CACHINGOUT_PATH . 'includes/compat/class-polylang.php';
		require_once CACHINGOUT_PATH . 'admin/class-admin.php';
		require_once CACHINGOUT_PATH . 'admin/class-filesystem-check-page.php';
		require_once CACHINGOUT_PATH . 'includes/admin-bar/class-admin-bar.php';
		require_once CACHINGOUT_PATH . 'includes/class-setup-wizard.php';
		require_once CACHINGOUT_PATH . 'includes/insights/class-insights.php';
		require_once CACHINGOUT_PATH . 'includes/insights/class-page-audit.php';
	}

	/**
	 * Wire: create objects.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private function wire() {
		$this->settings      = new CachingOut_Settings();
		$this->cacheability  = new CachingOut_Cacheability( $this->settings );
		$this->page_cache    = new CachingOut_Page_Cache( $this->settings );
		$this->purge         = new CachingOut_Purge( $this->settings, $this->page_cache );
		$this->expiry        = new CachingOut_Expiry( $this->settings );
		$this->woocommerce   = new CachingOut_WooCommerce( $this->settings, $this->cacheability, $this->purge );
		$this->edd           = new CachingOut_EDD( $this->settings, $this->cacheability, $this->purge );
		$this->page_builders = new CachingOut_Page_Builders( $this->cacheability );
		$this->browser_cache = new CachingOut_Browser_Cache();
		$this->lazyload      = new CachingOut_LazyLoad( $this->settings );
		$this->lcp_priority  = new CachingOut_LCP_Priority( $this->settings );
		$this->admin         = new CachingOut_Admin( $this->settings );
		$this->admin_bar     = new CachingOut_Admin_Bar();

		// File optimization subsystem (free: minify + defer, with the shared
		// per-file exclusion controls). The pipeline pins every filter's
		// position by label, so the Pro add-on's filters (asset strip,
		// combine, critical CSS, fonts, delay) land in the right place
		// whenever they are registered.
		$this->opt_exclusions = new CachingOut_Optimization_Exclusions( $this->settings );
		$minify_css           = new CachingOut_Minify_CSS( $this->settings, $this->opt_exclusions );
		$minify_js            = new CachingOut_Minify_JS( $this->settings, $this->opt_exclusions );
		$defer_js             = new CachingOut_Defer_JS( $this->settings, $this->opt_exclusions );

		$this->opt_pipeline = new CachingOut_Optimization_Pipeline();
		$this->opt_pipeline->add_filter( 'minify_css', array( $minify_css, 'filter' ) );
		$this->opt_pipeline->add_filter( 'minify_js', array( $minify_js, 'filter' ) );
		$this->opt_pipeline->add_filter( 'defer_js', array( $defer_js, 'filter' ) );

		// Multilingual compat - purge hooks for WPML and Polylang.
		$this->wpml     = new CachingOut_WPML( $this->settings, $this->purge );
		$this->polylang = new CachingOut_Polylang( $this->settings, $this->purge );

		// Database cleanup (manual, preview-first). Scheduling is Pro.
		if ( ! ( defined( 'CACHINGOUT_DISABLE_DATABASE' ) && CACHINGOUT_DISABLE_DATABASE ) ) {
			$this->db_cleanup = new CachingOut_DB_Cleanup();
			$this->admin->set_db_cleanup( $this->db_cleanup );
		}

		// Setup Wizard. The admin controller owns the menu tree, so it needs
		// the wizard to register the "Setup Wizard" entry under Caching Out.
		$this->setup_wizard = new CachingOut_Setup_Wizard( $this->settings );
		$this->admin->set_wizard( $this->setup_wizard );

		// Insights tab: the Page Audit. The PageSpeed client and telemetry
		// are injected by the Pro add-on.
		$this->insights = new CachingOut_Insights( $this->settings );

		// Phase 3: heartbeat control, DNS prefetch, object cache notice.
		$this->heartbeat           = new CachingOut_Heartbeat( $this->settings );
		$this->dns_prefetch        = new CachingOut_DNS_Prefetch( $this->settings );
		$this->object_cache_notice = new CachingOut_Object_Cache_Notice();
	}

	/**
	 * Run: register hooks and begin operation.
	 *
	 * Called exactly once at `plugins_loaded` (priority 10). Subsystems that
	 * need later hooks (e.g. `init`) register them here; nothing runs before
	 * `run()` is called. The free edition is switched on from activation:
	 * there is no locked state here (spec 30).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function run() {
		// Register purge hooks - must be on `init` because some callbacks
		// (e.g. get_permalink()) are only available after setup_theme.
		add_action( 'init', array( $this->purge, 'register_hooks' ) );

		// Schedule expiry cron on activation/first run.
		add_action( 'init', array( $this->expiry, 'schedule' ) );

		// Bind the cron CALLBACKS. Scheduling an event without binding its
		// hook makes cron fire into a void - exactly how the expiry sweep and
		// preload continuation shipped dead (audit N5/N6). Every
		// wp_schedule_event() in this plugin must have a matching add_action()
		// here or in its own register() (DECISIONS 2026-08-28).
		add_action( CachingOut_Expiry::CRON_HOOK, array( $this->expiry, 'sweep' ) );

		// Register eCommerce compat exclusions at init priority 20 - after
		// WooCommerce/EDD have registered their post types and page IDs.
		add_action( 'init', array( $this->woocommerce, 'register' ), 20 );
		add_action( 'init', array( $this->edd, 'register' ), 20 );

		// Register page-builder compat exclusions at init priority 10.
		add_action( 'init', array( $this->page_builders, 'register' ), 10 );

		// Register multilingual compat purge hooks at init priority 25 - after
		// WPML/Polylang constants and functions are available.
		add_action( 'init', array( $this->wpml, 'register' ), 25 );
		add_action( 'init', array( $this->polylang, 'register' ), 25 );

		// Apply browser-cache rules when settings are saved, and tell the
		// admin when that write fails (BUG-014: errors used to be dropped).
		add_action( 'update_option_' . \CachingOut_Settings::OPTION_KEY, array( $this, 'on_settings_saved' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'browser_cache_notice' ) );

		// A Pro deactivation must leave the drop-in reading free-safe values:
		// re-export the runtime snapshot from the masked view (spec 30).
		add_action( 'deactivated_plugin', array( $this, 'on_plugin_deactivated' ), 10, 1 );
		// Names another plugin registers through
		// `cachingout_ignored_query_strings` reach the drop-in only through
		// the snapshot: rewrite it when any plugin is switched on (1.21.17).
		add_action( 'activated_plugin', array( $this, 'on_plugin_activated' ), 10, 1 );

		// Wire LazyLoad (JS enqueuing) and LCP priority. Their HTML passes
		// are page filters of the ONE front-end buffer, the pipeline's, at
		// positions 70 (LCP) and 80 (LazyLoad): the hint lands first, then
		// LazyLoad sees fetchpriority="high" and leaves that image eager.
		// Until 1.21.16 each opened a buffer of its own (DECISIONS 2026-10-19).
		$this->lazyload->run();
		$this->lcp_priority->run();
		if ( $this->lcp_priority->is_enabled() ) {
			$this->opt_pipeline->add_page_filter( 'lcp_priority', array( $this->lcp_priority, 'filter' ) );
		}
		if ( $this->lazyload->is_enabled() ) {
			$this->opt_pipeline->add_page_filter( 'lazyload', array( $this->lazyload, 'filter' ) );
		}

		// The single front-end output buffer (core's on WordPress 6.9+,
		// the pipeline's own, explicitly closed on shutdown, before that).
		add_action( 'template_redirect', array( $this->opt_pipeline, 'start_buffering' ) );

		// Register the admin settings page.
		$this->admin->register();

		// Register admin-bar menu items (Purge Cache; Preload with Pro).
		$this->admin_bar->register();

		// Register Setup Wizard.
		$this->setup_wizard->register();

		// Register the Page Audit AJAX handlers.
		$this->insights->register();

		// Phase 3: heartbeat control, DNS prefetch, object cache notice.
		$this->heartbeat->register();
		$this->dns_prefetch->register();
		$this->object_cache_notice->register();
	}

	/**
	 * Get the settings instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Get the cacheability instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_Cacheability
	 */
	public function cacheability() {
		return $this->cacheability;
	}

	/**
	 * Get the page cache instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_Page_Cache
	 */
	public function page_cache() {
		return $this->page_cache;
	}

	/**
	 * The optimization pipeline (tests pin the filter order).
	 *
	 * @since 0.1.18
	 * @return CachingOut_Optimization_Pipeline
	 */
	public function opt_pipeline(): CachingOut_Optimization_Pipeline {
		return $this->opt_pipeline;
	}

	/**
	 * The shared per-file exclusion registry (Pro's filters read it too).
	 *
	 * @since 1.21.0
	 * @return CachingOut_Optimization_Exclusions
	 */
	public function opt_exclusions(): CachingOut_Optimization_Exclusions {
		return $this->opt_exclusions;
	}

	/**
	 * The Insights tab orchestrator.
	 *
	 * @since 1.21.0
	 * @return CachingOut_Insights
	 */
	public function insights(): CachingOut_Insights {
		return $this->insights;
	}

	/**
	 * The database cleanup engine, null under CACHINGOUT_DISABLE_DATABASE.
	 *
	 * @since 1.21.0
	 * @return CachingOut_DB_Cleanup|null
	 */
	public function db_cleanup(): ?CachingOut_DB_Cleanup {
		return $this->db_cleanup;
	}

	/**
	 * The Setup Wizard.
	 *
	 * @since 1.21.0
	 * @return CachingOut_Setup_Wizard
	 */
	public function setup_wizard(): CachingOut_Setup_Wizard {
		return $this->setup_wizard;
	}

	/**
	 * The admin-bar integration.
	 *
	 * @since 1.21.0
	 * @return CachingOut_Admin_Bar
	 */
	public function admin_bar(): CachingOut_Admin_Bar {
		return $this->admin_bar;
	}

	/**
	 * Whether a settings save changed anything that alters cached HTML.
	 *
	 * Browser-cache toggles live in .htaccess (headers, not markup) and the
	 * database/licence/insights sections never touch output, so only the
	 * sections below invalidate cached pages. Within `cache`, the lifespan
	 * is applied at serve time and the filesystem-check flag is admin-only,
	 * so neither invalidates anything. A section absent from either side
	 * (the first persist writes the whole option over a stub row) compares
	 * as its defaults, and values compare loosely - the sanitizer's '1'
	 * against a default true is not a change.
	 *
	 * @since 0.1.17
	 *
	 * @param mixed $old_value Option value before the save.
	 * @param mixed $value     Option value after the save.
	 * @return bool
	 */
	public static function output_settings_changed( $old_value, $value ): bool {
		$old      = is_array( $old_value ) ? $old_value : array();
		$new      = is_array( $value ) ? $value : array();
		$defaults = class_exists( 'CachingOut_Settings' ) ? \CachingOut_Settings::defaults() : array();
		$ignore   = array( 'cache' => array( 'lifespan', 'fs_check_enabled' ) );
		foreach ( array( 'cache', 'media', 'file_optimization', 'cdn', 'heartbeat', 'dns_prefetch' ) as $section ) {
			$fallback = isset( $defaults[ $section ] ) ? $defaults[ $section ] : null;
			$a        = array_key_exists( $section, $old ) ? $old[ $section ] : $fallback;
			$b        = array_key_exists( $section, $new ) ? $new[ $section ] : $fallback;
			if ( isset( $ignore[ $section ] ) ) {
				foreach ( $ignore[ $section ] as $key ) {
					if ( is_array( $a ) ) {
						unset( $a[ $key ] );
					}
					if ( is_array( $b ) ) {
						unset( $b[ $key ] );
					}
				}
			}
			if ( $a != $b ) { // phpcs:ignore Universal.Operators.StrictComparisons -- loose on purpose, see docblock.
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the purge instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_Purge
	 */
	public function purge() {
		return $this->purge;
	}

	/**
	 * Get the expiry instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_Expiry
	 */
	public function expiry() {
		return $this->expiry;
	}

	/**
	 * Get the browser cache instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_Browser_Cache
	 */
	public function browser_cache() {
		return $this->browser_cache;
	}

	/**
	 * Get the lazyload instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_LazyLoad
	 */
	public function lazyload() {
		return $this->lazyload;
	}

	/**
	 * Get the admin instance.
	 *
	 * @since 0.1.0
	 * @return CachingOut_Admin
	 */
	public function admin() {
		return $this->admin;
	}

	/**
	 * Respond to settings changes - re-apply browser-cache rules.
	 *
	 * Called automatically when the cachingout_settings option is updated
	 * in the database (e.g. from the admin UI). Uses the new values to
	 * write/update/remove the browser-cache blocks in .htaccess.
	 *
	 * @since 0.1.0
	 *
	 * @param array $old_value The previous settings value.
	 * @param array $value     The new settings value.
	 * @return void
	 */
	public function on_settings_saved( $old_value, $value ) {
		// The purge below can fan out (runtime-config export, preload start)
		// into code that saves the option again; one save handles one save.
		static $running = false;
		if ( $running ) {
			return;
		}
		$running = true;

		try {
			// BUG-014: get_all() served the cached (pre-save) values here, so
			// the writer saw every toggle off and no block ever landed. Drop the
			// cache so the next read is the row update_option just wrote.
			$this->settings->reset_cache();

			$result = $this->browser_cache->write( $this->settings );

			if ( is_wp_error( $result ) ) {
				set_transient(
					self::BROWSER_CACHE_ERROR_TRANSIENT,
					$result->get_error_message(),
					10 * MINUTE_IN_SECONDS
				);
			} else {
				delete_transient( self::BROWSER_CACHE_ERROR_TRANSIENT );
			}

			// BUG-021 (orgahair.pl, 2026-09-01): a cached page is a snapshot of
			// the optimizations that were on when it was written. Turning minify
			// or delay-JS on left every cached page unminified until expiry
			// (10 h), and the page audit faithfully reported the stale copy.
			// Any change to a section that shapes the output purges the cache -
			// AFTER the .htaccess write, so the purge's side effects can never
			// cost the user their browser-cache rules.
			if ( $this->purge && self::output_settings_changed( $old_value, $value ) ) {
				$this->purge->purge_all();
			}

			/**
			 * Fires after the free edition reacted to a settings save. The
			 * Pro add-on syncs its schedules here (weekly scan, database).
			 *
			 * @since 1.21.0
			 *
			 * @param array $old_value The previous settings value.
			 * @param array $value     The new settings value.
			 */
			do_action( 'cachingout_after_settings_saved', $old_value, $value );
		} finally {
			$running = false;
		}
	}

	/**
	 * `activated_plugin`: rewrite the runtime snapshot so the query
	 * parameters the plugin registers through
	 * `cachingout_ignored_query_strings` (and the prefixes through
	 * `cachingout_derived_never_cache_prefixes`) reach the drop-in. The
	 * plugin's main file has been included by now, so callbacks it adds at
	 * file level are hooked; a plugin that registers them later (on
	 * `plugins_loaded` or `init`) calls export_runtime_config() itself.
	 *
	 * @since 1.21.17
	 *
	 * @param string $plugin Plugin basename being activated.
	 * @return void
	 */
	public function on_plugin_activated( $plugin ): void {
		unset( $plugin );
		$this->settings->export_runtime_config();
	}

	/**
	 * `deactivated_plugin`: when the Pro add-on is deactivated the runtime
	 * snapshot must be rewritten from the masked view so the drop-in stops
	 * applying Pro-only exclusions and variants. The stored settings are
	 * untouched (no data loss); the page cache is purged because cached
	 * pages may carry Pro output.
	 *
	 * @since 1.21.0
	 *
	 * @param string $plugin Plugin basename being deactivated.
	 * @return void
	 */
	public function on_plugin_deactivated( $plugin ): void {
		if ( 'cachingout-pro/cachingout-pro.php' !== $plugin ) {
			// Any other plugin: refresh the snapshot so the names it
			// registered through `cachingout_ignored_query_strings` are
			// re-read. Its callbacks are still hooked during this request,
			// so its names leave the snapshot at the next rewrite (the next
			// settings save or plugin change), never later than that.
			$this->settings->export_runtime_config();
			return;
		}
		if ( class_exists( 'CachingOut_Features' ) ) {
			// The Pro filter callbacks are still registered during this
			// request; answer "unavailable" from here on.
			add_filter( 'cachingout_pro_available', '__return_false', PHP_INT_MAX );
			CachingOut_Features::reset();
		}
		$this->settings->reset_cache();
		$this->settings->export_runtime_config();
		if ( $this->purge ) {
			$this->purge->purge_all();
		}
	}

	/**
	 * Transient holding the last browser-cache .htaccess write failure.
	 *
	 * @since 0.1.16
	 * @var string
	 */
	const BROWSER_CACHE_ERROR_TRANSIENT = 'cachingout_browser_cache_error';

	/**
	 * Show (once) why the last browser-cache .htaccess write failed.
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function browser_cache_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = get_transient( self::BROWSER_CACHE_ERROR_TRANSIENT );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( self::BROWSER_CACHE_ERROR_TRANSIENT );

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Caching Out could not update .htaccess for Browser Caching:', 'cachingout' ),
			esc_html( $message )
		);
	}

	/**
	 * Check whether a named feature is available on this site.
	 *
	 * Thin alias of the gating chokepoint (CachingOut_Features), kept so
	 * existing callers and the PRD's `cachingout_is_feature_available()`
	 * name keep working. No licence is consulted here.
	 *
	 * @since 0.1.0
	 * @since 1.21.0 Delegates to CachingOut_Features.
	 *
	 * @param string $feature Feature key (e.g. 'cdn', 'insights_pagespeed', 'delay_js').
	 * @return bool True when the feature is available.
	 */
	public function is_feature_available( string $feature ): bool {
		return CachingOut_Features::available( $feature );
	}
}
