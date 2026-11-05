<?php
/**
 * Easy Digital Downloads compatibility — registers automatic cache exclusions
 * and purge hooks for EDD stores.
 *
 * All caching decisions route through CachingOut_Cacheability via the
 * register_exclusion() API. This module never contains independent
 * cache-or-not conditionals.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD compat module.
 *
 * @since 0.1.0
 */
class CachingOut_EDD {

	/**
	 * Settings manager.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Cacheability chokepoint.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Cacheability
	 */
	private $cacheability;

	/**
	 * Purge manager.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Purge
	 */
	private $purge;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings     $settings     Settings manager instance.
	 * @param CachingOut_Cacheability $cacheability Cacheability chokepoint.
	 * @param CachingOut_Purge        $purge        Purge manager instance.
	 */
	public function __construct(
		CachingOut_Settings $settings,
		CachingOut_Cacheability $cacheability,
		CachingOut_Purge $purge
	) {
		$this->settings     = $settings;
		$this->cacheability = $cacheability;
		$this->purge        = $purge;
	}

	/**
	 * Register all EDD exclusions and hooks.
	 *
	 * Must be called at `init` priority 20 or later, after EDD has loaded
	 * its functions and page IDs are available via `edd_get_option()`.
	 *
	 * Does nothing when EDD is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'EDD' ) && ! function_exists( 'edd_get_option' ) ) {
			return;
		}

		$this->register_page_exclusions();
		$this->register_ajax_exclusions();
		$this->register_cookie_exclusions();
		$this->register_purge_hooks();
		$this->register_snapshot_prefixes();
	}

	/**
	 * The EDD option keys (inside `edd_settings`) that name store pages.
	 *
	 * @since 1.26.0
	 * @var string[]
	 */
	const PAGE_OPTIONS = array(
		'purchase_page',
		'purchase_history_page',
		'success_page',
		'failure_page',
	);

	/**
	 * Carry the store page paths into the runtime snapshot (1.26.0).
	 *
	 * As for WooCommerce: the register_exclusion() callbacks above never
	 * reach the drop-in, so the checkout, purchase-history, success and
	 * failure page paths are contributed through
	 * `cachingout_derived_never_cache_prefixes` as `prefix => 'edd'`, and
	 * the snapshot is re-exported when EDD's settings option or one of
	 * those pages is saved.
	 *
	 * @since 1.26.0
	 * @return void
	 */
	private function register_snapshot_prefixes(): void {
		add_filter( 'cachingout_derived_never_cache_prefixes', array( $this, 'add_never_cache_prefixes' ) );
		add_action( 'update_option_edd_settings', array( $this, 'export_runtime_config' ), 10, 0 );
		add_action( 'add_option_edd_settings', array( $this, 'export_runtime_config' ), 10, 0 );
		add_action( 'save_post_page', array( $this, 'on_page_saved' ), 10, 1 );
	}

	/**
	 * Append the EDD store page paths, keyed by path with the bypass source
	 * `edd`, for the runtime snapshot.
	 *
	 * @since 1.26.0
	 *
	 * @param mixed $prefixes Entries from earlier callbacks.
	 * @return array
	 */
	public function add_never_cache_prefixes( $prefixes ): array {
		$prefixes = is_array( $prefixes ) ? $prefixes : array();
		foreach ( $this->store_page_ids() as $id ) {
			$path = $this->get_page_path( $id );
			if ( '' !== $path ) {
				$prefixes[ $path ] = 'edd';
			}
		}
		return $prefixes;
	}

	/**
	 * Re-export the runtime snapshot when a store page itself is saved.
	 *
	 * @since 1.26.0
	 *
	 * @param int $post_id The saved page.
	 * @return void
	 */
	public function on_page_saved( $post_id ): void {
		if ( in_array( (int) $post_id, $this->store_page_ids(), true ) ) {
			$this->export_runtime_config();
		}
	}

	/**
	 * Rewrite the runtime snapshot so the drop-in reads the current paths.
	 *
	 * @since 1.26.0
	 * @return void
	 */
	public function export_runtime_config(): void {
		$this->settings->export_runtime_config();
	}

	/**
	 * The IDs of the EDD store pages (0 entries dropped).
	 *
	 * @since 1.26.0
	 * @return int[]
	 */
	private function store_page_ids(): array {
		if ( ! function_exists( 'edd_get_option' ) ) {
			return array();
		}
		$ids = array();
		foreach ( self::PAGE_OPTIONS as $option ) {
			$id = (int) edd_get_option( $option, 0 );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/*
	 * The EDD cart and session cookie names are built into the chokepoint
	 * (CachingOut_Cacheability::STORE_COOKIES, spec 30) so they hold in
	 * drop-in context in both editions. Until 1.21.0 this module appended
	 * them to `cache.never_cache_cookies`; that key is the Pro custom list
	 * now and the safety layer never depends on a stored value.
	 */

	/**
	 * Register URL-based exclusions for the real EDD page permalinks.
	 *
	 * Queries page IDs via the EDD options API and registers exclusion
	 * callbacks that match the resulting permalink paths.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_page_exclusions(): void {
		// Checkout page.
		$checkout_id = edd_get_option( 'purchase_page', 0 );
		if ( $checkout_id > 0 ) {
			$checkout_path = $this->get_page_path( (int) $checkout_id );
			if ( '' !== $checkout_path ) {
				$this->cacheability->register_exclusion(
					'edd_checkout',
					function ( string $url ) use ( $checkout_path ): bool {
						return $this->url_matches_path( $url, $checkout_path );
					},
					'edd-checkout'
				);
			}
		}

		// Purchase history page.
		$history_id = edd_get_option( 'purchase_history_page', 0 );
		if ( $history_id > 0 ) {
			$history_path = $this->get_page_path( (int) $history_id );
			if ( '' !== $history_path ) {
				$this->cacheability->register_exclusion(
					'edd_history',
					function ( string $url ) use ( $history_path ): bool {
						return $this->url_matches_path( $url, $history_path );
					},
					'edd-purchase-history'
				);
			}
		}

		// Success page.
		$success_id = edd_get_option( 'success_page', 0 );
		if ( $success_id > 0 ) {
			$success_path = $this->get_page_path( (int) $success_id );
			if ( '' !== $success_path ) {
				$this->cacheability->register_exclusion(
					'edd_success',
					function ( string $url ) use ( $success_path ): bool {
						return $this->url_matches_path( $url, $success_path );
					},
					'edd-purchase-confirmation'
				);
			}
		}

		// Failure / cancelled transaction page.
		$failure_id = edd_get_option( 'failure_page', 0 );
		if ( $failure_id > 0 ) {
			$failure_path = $this->get_page_path( (int) $failure_id );
			if ( '' !== $failure_path ) {
				$this->cacheability->register_exclusion(
					'edd_failure',
					function ( string $url ) use ( $failure_path ): bool {
						return $this->url_matches_path( $url, $failure_path );
					},
					'edd-purchase-cancelled'
				);
			}
		}
	}

	/**
	 * Register AJAX endpoint exclusions for EDD.
	 *
	 * EDD uses /wp-json/edd/ for its REST API and /edd-api/ for AJAX.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_ajax_exclusions(): void {
		// /wp-json/edd/ — EDD REST API.
		$this->cacheability->register_exclusion(
			'edd_rest_api',
			function ( string $url ): bool {
				$path = strtok( $url, '?' ) ?: $url;
				return 0 === strpos( $path, '/wp-json/edd/' );
			},
			'edd-rest-api'
		);

		// /edd-api/ — EDD AJAX endpoint (legacy, still used).
		$this->cacheability->register_exclusion(
			'edd_ajax',
			function ( string $url ): bool {
				$path = strtok( $url, '?' ) ?: $url;
				return 0 === strpos( $path, '/edd-api/' );
			},
			'edd-ajax'
		);
	}

	/**
	 * Register cookie-based exclusions for EDD cart and session cookies.
	 *
	 * These exclusions check the $_COOKIE superglobal directly at call time
	 * since registered exclusion callbacks only receive the URL parameter.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_cookie_exclusions(): void {
		// EDD items in cart cookie.
		$this->cacheability->register_exclusion(
			'edd_cart_cookie',
			function ( string $url ): bool {
				return isset( $_COOKIE['edd_items_in_cart'] );
			},
			'edd-cart-cookie'
		);

		// EDD session cookie — edd_session_<hash> or edd_guest_<hash>.
		$this->cacheability->register_exclusion(
			'edd_session_cookie',
			function ( string $url ): bool {
				if ( empty( $_COOKIE ) ) {
					return false;
				}

				$session_prefixes = array( 'edd_session_', 'edd_guest_' );
				foreach ( array_keys( $_COOKIE ) as $name ) {
					foreach ( $session_prefixes as $prefix ) {
						if ( 0 === strpos( $name, $prefix ) ) {
							return true;
						}
					}
				}

				return false;
			},
			'edd-session-cookie'
		);
	}

	/**
	 * Register purge hooks for EDD purchase events.
	 *
	 * When a purchase completes or payment status changes, the checkout,
	 * purchase history, and success/failure pages are purged.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_purge_hooks(): void {
		add_action( 'edd_complete_purchase', array( $this, 'purge_on_purchase' ), 10, 0 );
		add_action( 'edd_insert_payment', array( $this, 'purge_on_purchase' ), 10, 0 );
		add_action( 'edd_update_payment_status', array( $this, 'purge_on_purchase' ), 10, 0 );
	}

	/**
	 * Purge eCommerce-critical pages on EDD purchase events.
	 *
	 * Full-cache purge on purchase events. An order change can affect the
	 * checkout page (if it shows recent purchases), purchase history, and
	 * product listing pages.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function purge_on_purchase(): void {
		$this->purge->purge_all();
	}

	/**
	 * Get the URL path for a page ID, relative to the site home.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page_id WordPress page ID.
	 * @return string Path segment (e.g. '/checkout/'), or empty string on failure.
	 */
	private function get_page_path( int $page_id ): string {
		$permalink = get_permalink( $page_id );
		if ( false === $permalink || '' === $permalink ) {
			return '';
		}

		$home_url = home_url( '/' );
		$path     = str_replace( $home_url, '/', $permalink );

		// Ensure leading slash.
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		// Normalise to a trailing-slash form for consistent matching.
		return rtrim( $path, '/' ) . '/';
	}

	/**
	 * Check whether a request URL matches a registered page path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url       Request URL path (with query string stripped).
	 * @param string $page_path Registered page path with trailing slash.
	 * @return bool
	 */
	private function url_matches_path( string $url, string $page_path ): bool {
		$path = rtrim( strtok( $url, '?' ) ?: $url, '/' ) . '/';
		return 0 === strpos( $path, $page_path );
	}
}