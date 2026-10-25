<?php
/**
 * Insights dashboard orchestrator.
 *
 * Coordinates tracked pages, PageSpeed audits, telemetry sending, and
 * recommendation mapping. Hooks into admin for AJAX handlers and tab rendering.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Insights feature orchestrator.
 *
 * @since 0.1.0
 */
class CachingOut_Insights {

	/**
	 * Settings instance.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * PageSpeed API client.
	 *
	 * @since 0.1.0
	 * @var CachingOut_PageSpeed
	 */
	private $pagespeed;

	/**
	 * Telemetry sender.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Telemetry
	 */
	private $telemetry;

	/**
	 * Option key for audit history storage.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const HISTORY_OPTION = 'cachingout_insights_history';

	/**
	 * Maximum number of days to retain audit history.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	const MAX_HISTORY_DAYS = 90;

	/**
	 * Mapping of Lighthouse audit IDs to plugin settings and recommendation text.
	 *
	 * @since 0.1.0
	 * @var array
	 */
	/**
	 * Page audit engine (Spec 24), created on first use.
	 *
	 * @since 0.1.16
	 * @var CachingOut_Page_Audit|null
	 */
	private $page_audit = null;

	const RECOMMENDATION_MAP = array(
		// BUG-015: every 'setting' (and each 'also' companion) must be a real
		// master toggle that is OFF by default — the recommendation is hidden
		// once the primary is on, and apply_recommendation() sets the whole
		// group. Pinned by InsightsTest::test_recommendation_map_keys_exist.
		'render-blocking-resources'  => array(
			'setting'  => 'file_optimization.minify_css',
			'label'    => 'Reduce render-blocking CSS',
			'action'   => 'Enable "Minify CSS" (serves one consolidated, compressed copy of each stylesheet)',
		),
		'unminified-css'             => array(
			'setting'  => 'file_optimization.minify_css',
			'label'    => 'Minify CSS files',
			'action'   => 'Enable "Minify CSS"',
		),
		'unminified-javascript'      => array(
			'setting'  => 'file_optimization.minify_js',
			'label'    => 'Minify JavaScript files',
			'action'   => 'Enable "Minify JavaScript"',
		),
		'unused-javascript'          => array(
			'setting'  => 'file_optimization.delay_js',
			'label'    => 'Delay non-critical JavaScript',
			'action'   => 'Enable "Delay JavaScript"',
		),
		'uses-optimized-images'      => array(
			'setting'  => 'media.webp_cache_enabled',
			'label'    => 'Serve images in next-gen formats',
			'action'   => 'Enable "WebP Cache"',
		),
		'offscreen-images'           => array(
			'setting'  => 'media.lazyload_enabled',
			'also'     => array( 'media.lazyload_images' ),
			'label'    => 'LazyLoad below-the-fold images',
			'action'   => 'Enable "LazyLoad" for images',
		),
		'uses-text-compression'      => array(
			'setting'  => 'browser_cache.gzip',
			'also'     => array( 'browser_cache.brotli', 'browser_cache.expires' ),
			'label'    => 'Enable GZIP/Brotli compression',
			'action'   => 'Enable "Browser Caching" (GZIP, Brotli and Expires headers)',
		),
		'efficient-animated-content' => array(
			'setting'  => 'media.lazyload_enabled',
			'also'     => array( 'media.lazyload_images' ),
			'label'    => 'Replace GIFs with static images + LazyLoad',
			'action'   => 'Enable "LazyLoad" for images',
		),
		'total-byte-weight'          => array(
			'setting'  => 'cdn.enabled',
			'label'    => 'Enable CDN for static assets',
			'action'   => 'Enable "CDN"',
		),
		'server-response-time'       => array(
			'setting'  => 'cache.enabled',
			'label'    => 'Enable page caching',
			'action'   => 'Enable "Page Cache"',
		),
		'uses-long-cache-ttl'        => array(
			'setting'  => 'browser_cache.expires',
			'also'     => array( 'browser_cache.gzip', 'browser_cache.brotli' ),
			'label'    => 'Set far-future cache headers',
			'action'   => 'Enable "Browser Caching" (Expires headers, plus GZIP and Brotli)',
		),
	);

	/**
	 * Constructor.
	 *
	 * The PageSpeed client and the telemetry sender are Pro subsystems since
	 * 1.21.0 (spec 30). The free edition constructs this class without them
	 * and gets the Page Audit; the Pro add-on injects both through the
	 * setters and registers the PageSpeed handlers.
	 *
	 * @since 0.1.0
	 * @since 1.21.0 `$pagespeed` and `$telemetry` optional.
	 *
	 * @param CachingOut_Settings $settings  Settings instance.
	 * @param object|null         $pagespeed Optional CachingOut_PageSpeed.
	 * @param object|null         $telemetry Optional CachingOut_Telemetry.
	 */
	public function __construct(
		CachingOut_Settings $settings,
		$pagespeed = null,
		$telemetry = null
	) {
		$this->settings  = $settings;
		$this->pagespeed = $pagespeed;
		$this->telemetry = $telemetry;
	}

	/**
	 * Inject the PageSpeed client (Pro).
	 *
	 * @since 1.21.0
	 *
	 * @param object $pagespeed CachingOut_PageSpeed instance.
	 * @return void
	 */
	public function set_pagespeed( $pagespeed ): void {
		$this->pagespeed = $pagespeed;
	}

	/**
	 * Inject the telemetry sender (Pro).
	 *
	 * @since 1.21.0
	 *
	 * @param object $telemetry CachingOut_Telemetry instance.
	 * @return void
	 */
	public function set_telemetry( $telemetry ): void {
		$this->telemetry = $telemetry;
	}

	/**
	 * Whether a PageSpeed client is attached (Performance Insights available).
	 *
	 * @since 1.21.0
	 * @return bool
	 */
	public function has_pagespeed(): bool {
		return is_object( $this->pagespeed );
	}

	/**
	 * Register the free edition's AJAX handlers: the Page Audit and its
	 * fixes. The PageSpeed and telemetry handlers are registered by the Pro
	 * add-on through register_pagespeed_handlers() / register_optin_handler().
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_cachingout_run_page_audit', array( $this, 'ajax_run_page_audit' ) );
		add_action( 'wp_ajax_cachingout_get_page_audit', array( $this, 'ajax_get_page_audit' ) );
		add_action( 'wp_ajax_cachingout_apply_fix', array( $this, 'ajax_apply_fix' ) );
		add_action( 'wp_ajax_cachingout_apply_finding_action', array( $this, 'ajax_apply_finding_action' ) );
	}

	/**
	 * Register the PageSpeed handlers (Pro, after set_pagespeed()).
	 *
	 * @since 1.21.0
	 * @return void
	 */
	public function register_pagespeed_handlers(): void {
		if ( ! $this->has_pagespeed() ) {
			return;
		}
		add_action( 'wp_ajax_cachingout_run_audit', array( $this, 'ajax_run_audit' ) );
		add_action( 'wp_ajax_cachingout_run_all_audits', array( $this, 'ajax_run_all_audits' ) );
		add_action( 'wp_ajax_cachingout_get_history', array( $this, 'ajax_get_history' ) );
		add_action( 'wp_ajax_cachingout_get_recommendations', array( $this, 'ajax_get_recommendations' ) );
		add_action( 'wp_ajax_cachingout_apply_recommendation', array( $this, 'ajax_apply_recommendation' ) );
	}

	/**
	 * Formerly registered the telemetry opt-in handler. The handler lives in
	 * the Pro add-on since 1.21.16 (CachingOut_Telemetry_Hooks::ajax_optin();
	 * the free plugin stores no telemetry consent because it sends nothing).
	 * Kept as a no-op so a Pro add-on older than 1.21.16 still loads.
	 *
	 * @since 1.21.0
	 * @deprecated 1.21.16 No-op.
	 * @return void
	 */
	public function register_optin_handler(): void {
		// No-op since 1.21.16.
	}

	/**
	 * Get the list of pages to track for audits.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function get_tracked_pages(): array {
		$pages = array(
			'home' => array(
				'label' => __( 'Homepage', 'cachingout' ),
				'url'   => home_url( '/' ),
			),
		);

		// Latest published post.
		$posts = get_posts(
			array(
				'numberposts' => 1,
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		if ( ! empty( $posts ) ) {
			$pages['post'] = array(
				'label' => __( 'Latest Post', 'cachingout' ),
				'url'   => get_permalink( $posts[0] ),
			);
		}

		// A representative page (not the front page).
		$page_on_front = get_option( 'page_on_front' );
		$exclude       = $page_on_front ? array( (int) $page_on_front ) : array();
		$sample_pages  = get_posts(
			array(
				'numberposts'  => 1,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- One query on an admin screen; excludes at most the front page.
				'post__not_in' => $exclude,
			)
		);
		if ( ! empty( $sample_pages ) ) {
			$pages['page'] = array(
				'label' => __( 'Sample Page', 'cachingout' ),
				'url'   => get_permalink( $sample_pages[0] ),
			);
		}

		// WooCommerce product.
		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'limit'  => 1,
					'status' => 'publish',
				)
			);
			if ( ! empty( $products ) ) {
				$pages['product'] = array(
					'label' => __( 'Sample Product', 'cachingout' ),
					'url'   => get_permalink( $products[0]->get_id() ),
				);
			}
		}

		// Custom pages from settings.
		$custom_pages = $this->settings->get( 'insights', 'custom_pages', array() );
		foreach ( $custom_pages as $index => $custom_url ) {
			if ( ! empty( $custom_url ) ) {
				$pages[ 'custom_' . $index ] = array(
					'label' => sprintf(
						/* translators: %d: custom page index */
						__( 'Custom Page %d', 'cachingout' ),
						$index + 1
					),
					'url' => $custom_url,
				);
			}
		}

		return $pages;
	}

	/**
	 * Get cached audit results for a tracked page.
	 *
	 * @since 0.1.0
	 *
	 * @param string $page_slug Tracked page slug.
	 * @return array|null Cached result or null.
	 */
	public function get_audit_results( string $page_slug, string $strategy = 'desktop' ): ?array {
		$pages = $this->get_tracked_pages();
		if ( ! isset( $pages[ $page_slug ] ) || ! $this->has_pagespeed() ) {
			return null;
		}

		return $this->pagespeed->get_cached_result( $pages[ $page_slug ]['url'], $strategy );
	}

	/**
	 * Run a PageSpeed audit for a tracked page and store the result.
	 *
	 * @since 0.1.0
	 *
	 * @param string $page_slug Tracked page slug.
	 * @return array|WP_Error Audit result or error.
	 */
	public function run_audit( string $page_slug ) {
		$pages = $this->get_tracked_pages();
		if ( ! isset( $pages[ $page_slug ] ) ) {
			return new WP_Error(
				'cachingout_invalid_page',
				__( 'Invalid page slug.', 'cachingout' )
			);
		}

		if ( ! $this->has_pagespeed() ) {
			// Unreachable through AJAX (register_pagespeed_handlers() binds
			// nothing without a client); kept for direct callers.
			return new WP_Error(
				'cachingout_no_pagespeed_client',
				__( 'No PageSpeed client is configured on this site.', 'cachingout' )
			);
		}

		$url      = $pages[ $page_slug ]['url'];
		$result   = $this->pagespeed->audit( $url, 'desktop' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Also run mobile audit if enabled.
		$mobile_result = null;
		if ( $this->settings->get( 'insights', 'mobile_audits', true ) ) {
			$mobile_result = $this->pagespeed->audit( $url, 'mobile' );
		}

		// Store in history.
		$this->store_history( $page_slug, $result );

		// Send telemetry if opted in (Insights audit + AI CWV snapshot).
		if ( is_object( $this->telemetry ) ) {
			$this->telemetry->send_audit_result( $result );
			$this->telemetry->send_cwv_snapshot( $result, 'ad_hoc', 'desktop' );
		}

		$response = array(
			'desktop' => $result,
		);


		if ( null !== $mobile_result && ! is_wp_error( $mobile_result ) ) {
			$response['mobile'] = $mobile_result;
		}

		return $response;
	}

	/**
	 * Get plain-language recommendations based on current audit scores.
	 *
	 * @since 0.1.0
	 * @return array
	 */
	public function get_recommendations(): array {
		$recommendations = array();
		$pages           = $this->get_tracked_pages();

		foreach ( $pages as $slug => $page ) {
			// Worst score per audit across desktop and mobile: the mobile
			// lab is where real sites fail (BUG-015: desktop-only hid it).
			$audits = array();
			foreach ( array( 'desktop', 'mobile' ) as $strategy ) {
				$result = $this->get_audit_results( $slug, $strategy );
				if ( null === $result || ! isset( $result['audits'] ) || ! is_array( $result['audits'] ) ) {
					continue;
				}
				foreach ( $result['audits'] as $audit_id => $audit ) {
					if ( ! isset( $audit['score'] ) || null === $audit['score'] ) {
						continue;
					}
					if ( ! isset( $audits[ $audit_id ] ) || (float) $audit['score'] < (float) $audits[ $audit_id ]['score'] ) {
						$audits[ $audit_id ] = array(
							'score'    => (float) $audit['score'],
							'title'    => isset( $audit['title'] ) ? $audit['title'] : $audit_id,
							'strategy' => $strategy,
						);
					}
				}
			}

			foreach ( $audits as $audit_id => $audit ) {
				if ( ! isset( self::RECOMMENDATION_MAP[ $audit_id ] ) ) {
					continue;
				}

				// Only recommend if the audit score is below 0.9 (90%).
				if ( $audit['score'] >= 0.9 ) {
					continue;
				}

				$map = self::RECOMMENDATION_MAP[ $audit_id ];

				// A recommendation for a setting the plugin does not have is
				// worse than none (BUG-015): refuse it.
				if ( ! $this->setting_exists( $map['setting'] ) ) {
					continue;
				}

				list( $section, $key ) = explode( '.', $map['setting'], 2 );
				$current_value         = $this->settings->get( $section, $key, false );

				// Skip if already enabled.
				if ( $current_value ) {
					continue;
				}

				$recommendations[] = array(
					'audit_id'       => $audit_id,
					'audit_title'    => $audit['title'],
					'strategy'       => $audit['strategy'],
					'label'          => $map['label'],
					'action'         => $map['action'],
					'setting'        => $map['setting'],
					'also'           => isset( $map['also'] ) ? array_values( $map['also'] ) : array(),
					'current_value'  => $current_value,
					'page'           => $page['label'],
				);
			}
		}

		// Deduplicate by setting.
		$seen  = array();
		$unique = array();
		foreach ( $recommendations as $rec ) {
			if ( ! isset( $seen[ $rec['setting'] ] ) ) {
				$seen[ $rec['setting'] ] = true;
				$unique[]                = $rec;
			}
		}

		return $unique;
	}

	/**
	 * Get historical audit data for a tracked page.
	 *
	 * @since 0.1.0
	 *
	 * @param string $page_slug Tracked page slug.
	 * @param int    $days      Number of days of history to return.
	 * @return array
	 */
	public function get_historical_data( string $page_slug, int $days = 30 ): array {
		$history = get_option( self::HISTORY_OPTION, array() );

		if ( ! isset( $history[ $page_slug ] ) || ! is_array( $history[ $page_slug ] ) ) {
			return array();
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$data   = array();

		foreach ( $history[ $page_slug ] as $entry ) {
			$entry_time = strtotime( $entry['timestamp'] );
			if ( $entry_time < $cutoff ) {
				continue;
			}
			$data[] = $entry;
		}

		return $data;
	}

	/**
	 * Store an audit result in the history option.
	 *
	 * @since 0.1.0
	 *
	 * @param string $page_slug Tracked page slug.
	 * @param array  $result    Audit result.
	 * @return void
	 */
	private function store_history( string $page_slug, array $result ): void {
		$history = get_option( self::HISTORY_OPTION, array() );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		if ( ! isset( $history[ $page_slug ] ) ) {
			$history[ $page_slug ] = array();
		}

		$history[ $page_slug ][] = array(
			'timestamp' => $result['timestamp'],
			'strategy'  => $result['strategy'],
			'scores'    => $result['scores'],
		);

		// Prune entries older than MAX_HISTORY_DAYS.
		$cutoff = time() - ( self::MAX_HISTORY_DAYS * DAY_IN_SECONDS );
		foreach ( $history as $slug => &$entries ) {
			$entries = array_filter(
				$entries,
				function ( $entry ) use ( $cutoff ) {
					return strtotime( $entry['timestamp'] ) >= $cutoff;
				}
			);
			$entries = array_values( $entries );
		}
		unset( $entries );

		update_option( self::HISTORY_OPTION, $history, false );
	}

	// ─────────────────────── AJAX handlers ──────────────────────────────

	/**
	 * AJAX: run a single PageSpeed audit.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function ajax_run_audit() {
		$this->verify_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$page_slug = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : '';

		if ( empty( $page_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'No page specified.', 'cachingout' ) ) );
		}

		$result = $this->run_audit( $page_slug );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: run audits for all tracked pages.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function ajax_run_all_audits() {
		$this->verify_ajax();

		$pages   = $this->get_tracked_pages();
		$results = array();

		foreach ( $pages as $slug => $page ) {
			if ( $this->has_pagespeed() ) {
				$result = $this->run_audit( $slug );
				if ( ! is_wp_error( $result ) ) {
					$results[ $slug ] = $result;
				}
			}
			// Spec 24: the page audit needs no API key and runs in well under a
			// second per page, so it rides along with every full run.
			$this->page_audit()->run( $slug );
		}

		wp_send_json_success( array( 'results' => $results, 'page_audit' => $this->page_audit()->get_all_results() ) );
	}

	/**
	 * Page audit engine accessor.
	 *
	 * @since 0.1.16
	 * @return CachingOut_Page_Audit
	 */
	public function page_audit(): CachingOut_Page_Audit {
		if ( null === $this->page_audit ) {
			$this->page_audit = new CachingOut_Page_Audit( $this->settings, array( $this, 'get_tracked_pages' ) );
		}
		return $this->page_audit;
	}

	/**
	 * AJAX: run the page audit for one tracked page (or every page).
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function ajax_run_page_audit() {
		$this->verify_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$page_slug = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : '';
		$pages     = $this->get_tracked_pages();
		$slugs     = '' === $page_slug || 'all' === $page_slug ? array_keys( $pages ) : array( $page_slug );

		foreach ( $slugs as $slug ) {
			$result = $this->page_audit()->run( $slug );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
		}

		wp_send_json_success( array( 'page_audit' => $this->page_audit()->get_all_results() ) );
	}

	/**
	 * AJAX: stored page-audit results for every tracked page.
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function ajax_get_page_audit() {
		$this->verify_ajax();

		wp_send_json_success( array( 'page_audit' => $this->page_audit()->get_all_results() ) );
	}

	/**
	 * AJAX: apply a page-audit fix group.
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function ajax_apply_fix() {
		$this->verify_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$fix_id  = isset( $_POST['fix_id'] ) ? sanitize_key( wp_unslash( $_POST['fix_id'] ) ) : '';
		$applied = $this->apply_fix( $fix_id );
		if ( is_wp_error( $applied ) ) {
			wp_send_json_error( array( 'message' => $applied->get_error_message() ) );
		}

		wp_send_json_success( array( 'fix_id' => $fix_id, 'applied' => $applied ) );
	}

	/**
	 * Apply one page-audit fix group (Spec 24) through the validated path.
	 *
	 * @since 0.1.16
	 *
	 * @param string $fix_id Key of CachingOut_Page_Audit::FIX_GROUPS.
	 * @return string[]|WP_Error Paths set to true.
	 */
	public function apply_fix( string $fix_id ) {
		if ( ! isset( CachingOut_Page_Audit::FIX_GROUPS[ $fix_id ] ) ) {
			return new WP_Error( 'cachingout_unknown_fix', __( 'Unknown fix.', 'cachingout' ) );
		}
		return $this->apply_setting_paths( CachingOut_Page_Audit::FIX_GROUPS[ $fix_id ] );
	}

	/**
	 * AJAX: apply a page-audit finding action (spec 29).
	 *
	 * @since 0.1.21
	 * @return void
	 */
	public function ajax_apply_finding_action() {
		$this->verify_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$action_id = isset( $_POST['action_id'] ) ? sanitize_key( wp_unslash( $_POST['action_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$value     = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$applied   = $this->apply_finding_action( $action_id, $value );
		if ( is_wp_error( $applied ) ) {
			wp_send_json_error( array( 'message' => $applied->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'action_id' => $action_id,
				'value'     => $value,
				'applied'   => $applied,
			)
		);
	}

	/**
	 * Finding action ids the Insights tab may apply, and what they touch.
	 *
	 * @since 0.1.21
	 * @var array<string, string>
	 */
	const FINDING_ACTIONS = array(
		'excluded_css_add' => 'file_optimization.excluded_css',
	);

	/**
	 * Apply one finding action (spec 29): `excluded_css_add` appends a
	 * stylesheet file name to File Optimization > Excluded CSS through the
	 * validated Settings::save() path, so the change lands as a
	 * `settings.change` row with source `page_audit` on the Activity page
	 * and reverts there. Append-only; a value already present is a no-op.
	 *
	 * @since 0.1.21
	 *
	 * @param string $action_id Key of FINDING_ACTIONS.
	 * @param string $value     Stylesheet file name (no path, no spaces or quotes).
	 * @return array{added: bool, excluded_css: string[]}|WP_Error
	 */
	public function apply_finding_action( string $action_id, string $value ) {
		if ( ! isset( self::FINDING_ACTIONS[ $action_id ] ) ) {
			return new WP_Error( 'cachingout_unknown_action', __( 'Unknown action.', 'cachingout' ) );
		}

		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 200 || preg_match( '/[\s<>"\'\\\\\/]/', $value ) ) {
			return new WP_Error( 'cachingout_invalid_value', __( 'The stylesheet name is not valid.', 'cachingout' ) );
		}

		$current = array_values( array_map( 'strval', (array) $this->settings->get( 'file_optimization', 'excluded_css', array() ) ) );
		if ( in_array( $value, $current, true ) ) {
			return array(
				'added'        => false,
				'excluded_css' => $current,
			);
		}
		$current[] = $value;

		if ( class_exists( 'CachingOut_Audit' ) && '' === CachingOut_Audit::request_source() ) {
			CachingOut_Audit::set_request_source( 'page_audit' );
		}
		$this->settings->save( array( 'excluded_css' => $current ), 'file_optimization' );

		return array(
			'added'        => true,
			'excluded_css' => array_values( array_map( 'strval', (array) $this->settings->get( 'file_optimization', 'excluded_css', array() ) ) ),
		);
	}

	/**
	 * Set a group of "section.key" settings to true, one save per section,
	 * after checking every key exists in Settings::DEFAULTS. Callers must
	 * only pass paths from an allow-list (RECOMMENDATION_MAP / FIX_GROUPS).
	 *
	 * @since 0.1.16
	 *
	 * @param string[] $paths "section.key" paths.
	 * @return string[]|WP_Error The paths applied.
	 */
	private function apply_setting_paths( array $paths ) {
		$by_section = array();
		foreach ( $paths as $path ) {
			if ( ! $this->setting_exists( $path ) ) {
				return new WP_Error(
					'cachingout_invalid_setting',
					sprintf(
						/* translators: %s: setting path */
						__( 'Recommendation targets a setting this version does not have: %s', 'cachingout' ),
						$path
					)
				);
			}
			list( $section, $key )    = explode( '.', $path, 2 );
			$by_section[ $section ][] = $key;
		}

		foreach ( $by_section as $section => $keys ) {
			$this->settings->save( array_fill_keys( $keys, true ), $section );
		}

		return array_values( $paths );
	}

	/**
	 * AJAX: get historical data for chart rendering.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function ajax_get_history() {
		$this->verify_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$page_slug = isset( $_POST['page'] ) ? sanitize_key( wp_unslash( $_POST['page'] ) ) : 'home';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$days      = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;

		$data = $this->get_historical_data( $page_slug, $days );

		wp_send_json_success( array( 'history' => $data ) );
	}

	/**
	 * AJAX: apply a recommendation by toggling the corresponding setting.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function ajax_apply_recommendation() {
		$this->verify_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are checked by verify_ajax() at the top of this handler.
		$setting_path = isset( $_POST['setting'] ) ? sanitize_text_field( wp_unslash( $_POST['setting'] ) ) : '';

		if ( empty( $setting_path ) ) {
			wp_send_json_error( array( 'message' => __( 'No setting specified.', 'cachingout' ) ) );
		}

		$applied = $this->apply_recommendation( $setting_path );
		if ( is_wp_error( $applied ) ) {
			wp_send_json_error( array( 'message' => $applied->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'setting' => $setting_path,
				'applied' => $applied,
				'value'   => true,
			)
		);
	}

	/**
	 * AJAX: list current recommendations (server-computed from cached audits).
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function ajax_get_recommendations() {
		$this->verify_ajax();

		wp_send_json_success( array( 'recommendations' => $this->get_recommendations() ) );
	}

	/**
	 * Apply one recommendation: the primary setting plus its `also` group.
	 *
	 * Only settings that appear as a primary in RECOMMENDATION_MAP can be
	 * applied through this path, and every key is checked against
	 * Settings::DEFAULTS — the admin UI must never be a generic
	 * "set any option to true" endpoint.
	 *
	 * @since 0.1.16
	 *
	 * @param string $setting_path "section.key" of the recommendation's primary setting.
	 * @return string[]|WP_Error List of "section.key" paths set to true.
	 */
	public function apply_recommendation( string $setting_path ) {
		$entry = null;
		foreach ( self::RECOMMENDATION_MAP as $candidate ) {
			if ( $candidate['setting'] === $setting_path ) {
				$entry = $candidate;
				break;
			}
		}
		if ( null === $entry ) {
			return new WP_Error( 'cachingout_unknown_recommendation', __( 'Unknown recommendation.', 'cachingout' ) );
		}

		$paths = array_merge( array( $entry['setting'] ), isset( $entry['also'] ) ? $entry['also'] : array() );

		return $this->apply_setting_paths( $paths );
	}

	/**
	 * Whether "section.key" names a setting that exists in Settings::DEFAULTS.
	 *
	 * @since 0.1.16
	 *
	 * @param string $setting_path "section.key".
	 * @return bool
	 */
	public function setting_exists( string $setting_path ): bool {
		if ( false === strpos( $setting_path, '.' ) ) {
			return false;
		}
		list( $section, $key ) = explode( '.', $setting_path, 2 );
		$defaults              = CachingOut_Settings::defaults();
		return isset( $defaults[ $section ] )
			&& is_array( $defaults[ $section ] )
			&& array_key_exists( $key, $defaults[ $section ] );
	}

	/**
	 * Gate: verify nonce and capability for Insights AJAX handlers.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'cachingout' ) ) );
		}

		check_ajax_referer( 'cachingout_insights', 'nonce' );
	}
}