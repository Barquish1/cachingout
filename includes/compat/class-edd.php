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