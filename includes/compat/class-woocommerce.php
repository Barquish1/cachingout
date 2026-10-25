<?php
/**
 * WooCommerce compatibility — registers automatic cache exclusions and purge
 * hooks for WooCommerce stores.
 *
 * All caching decisions route through CachingOut_Cacheability via the
 * register_exclusion() API. This module never contains independent
 * cache-or-not conditionals. Since 1.21.9 it likewise registers the store
 * pages into the optimisation pipeline's gate (`cachingout_skip_optimization`)
 * rather than any optimisation feature testing for WooCommerce itself.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce compat module.
 *
 * @since 0.1.0
 */
class CachingOut_WooCommerce {

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
	 * Register all WooCommerce exclusions and hooks.
	 *
	 * Must be called at `init` priority 20 or later, after WooCommerce has
	 * registered its post types and page IDs are available via WC functions.
	 *
	 * Does nothing when WooCommerce is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$this->register_page_exclusions();
		$this->register_ajax_exclusions();
		$this->register_cookie_exclusions();
		$this->register_purge_hooks();

		// 1.21.9: the store pages the cache already bypasses are also kept
		// out of file optimisation. Registered INTO the pipeline's gate, as
		// the exclusions above are registered into the chokepoint.
		add_filter( 'cachingout_skip_optimization', array( $this, 'skip_optimization_on_store_pages' ) );
	}

	/**
	 * Keep file optimisation off the cart, checkout and account pages.
	 *
	 * The block cart and checkout are a React application whose inline
	 * settings (`wc-settings-js-before`, `wp-i18n-js-after`, `*-js-extra`)
	 * run at parse time against scripts that must already have executed.
	 * Delaying, deferring, combining or re-minifying that graph leaves the
	 * checkout unmounted - nobody can buy anything. These pages are never
	 * cached, are mostly visited once, and are where a broken script costs
	 * the most, so nothing is optimised on them at all.
	 *
	 * Uses WooCommerce's own conditionals, so a renamed or translated page,
	 * an endpoint (`order-received`, `view-order`) and a shortcode page are
	 * all covered; the block checks catch a cart or checkout block placed on
	 * some other page. Store API calls never reach the pipeline (REST).
	 *
	 * @since 1.21.9
	 *
	 * @param mixed $reason Reason from an earlier callback, '' for none.
	 * @return mixed A `woocommerce-*` reason, or `$reason` unchanged.
	 */
	public function skip_optimization_on_store_pages( $reason ) {
		if ( true === $reason || ( is_string( $reason ) && '' !== $reason ) ) {
			return $reason;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'woocommerce-checkout';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'woocommerce-cart';
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return 'woocommerce-account';
		}

		if ( function_exists( 'has_block' ) && function_exists( 'is_singular' ) && is_singular() ) {
			if ( has_block( 'woocommerce/checkout' ) ) {
				return 'woocommerce-checkout';
			}
			if ( has_block( 'woocommerce/cart' ) ) {
				return 'woocommerce-cart';
			}
		}

		return $reason;
	}

	/*
	 * The WooCommerce cart and session cookie names are built into the
	 * chokepoint (CachingOut_Cacheability::STORE_COOKIES, spec 30) so they
	 * hold in drop-in context in both editions. Until 1.21.0 this module
	 * appended them to `cache.never_cache_cookies`; that key is the Pro
	 * custom list now and the safety layer never depends on a stored value.
	 */

	/**
	 * Register URL-based exclusions for the real WooCommerce page permalinks.
	 *
	 * Queries the page IDs via WC API and registers exclusion callbacks that
	 * match the resulting permalink paths. Also registers fallback exclusions
	 * for the well-known default paths.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_page_exclusions(): void {
		// Cart page.
		$cart_id = wc_get_page_id( 'cart' );
		if ( $cart_id > 0 ) {
			$cart_path = $this->get_page_path( $cart_id );
			if ( '' !== $cart_path ) {
				$this->cacheability->register_exclusion(
					'wc_cart',
					function ( string $url ) use ( $cart_path ): bool {
						return $this->url_matches_path( $url, $cart_path );
					},
					'woocommerce-cart'
				);
			}
		}

		// Checkout page.
		$checkout_id = wc_get_page_id( 'checkout' );
		if ( $checkout_id > 0 ) {
			$checkout_path = $this->get_page_path( $checkout_id );
			if ( '' !== $checkout_path ) {
				$this->cacheability->register_exclusion(
					'wc_checkout',
					function ( string $url ) use ( $checkout_path ): bool {
						return $this->url_matches_path( $url, $checkout_path );
					},
					'woocommerce-checkout'
				);
			}
		}

		// My Account page.
		$account_id = wc_get_page_id( 'myaccount' );
		if ( $account_id > 0 ) {
			$account_path = $this->get_page_path( $account_id );
			if ( '' !== $account_path ) {
				$this->cacheability->register_exclusion(
					'wc_account',
					function ( string $url ) use ( $account_path ): bool {
						return $this->url_matches_path( $url, $account_path );
					},
					'woocommerce-account'
				);
			}
		}

		// Shop page — intentionally NOT excluded.
		// Shop archives are prime cache targets. Cart/checkout/account pages
		// are excluded separately, and cart cookies bypass the cache, so
		// caching the shop listing is safe.
	}

	/**
	 * Register AJAX and REST API endpoint exclusions.
	 *
	 * WooCommerce uses /wc-ajax/ for cart fragments and other dynamic
	 * frontend data. The /wp-json/wc/store/ REST endpoint is already caught
	 * by the built-in /wp-json/ exclusion (step 6); this adds explicit
	 * coverage for /wc-ajax/ and /wc-analytics/ paths.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_ajax_exclusions(): void {
		// /wc-ajax/ — cart fragment and other AJAX callbacks.
		$this->cacheability->register_exclusion(
			'wc_ajax',
			function ( string $url ): bool {
				$path = strtok( $url, '?' ) ?: $url;
				return 0 === strpos( $path, '/wc-ajax/' );
			},
			'woocommerce-ajax'
		);

		// /wp-json/wc/store/ — WooCommerce Blocks Store API.
		$this->cacheability->register_exclusion(
			'wc_store_api',
			function ( string $url ): bool {
				$path = strtok( $url, '?' ) ?: $url;
				return 0 === strpos( $path, '/wp-json/wc/store/' );
			},
			'woocommerce-store-api'
		);

		// /wp-json/wc-analytics/ — WooCommerce Analytics REST API.
		$this->cacheability->register_exclusion(
			'wc_analytics',
			function ( string $url ): bool {
				$path = strtok( $url, '?' ) ?: $url;
				return 0 === strpos( $path, '/wp-json/wc-analytics/' );
			},
			'woocommerce-analytics'
		);
	}

	/**
	 * Register cookie-based exclusions for WooCommerce session and cart cookies.
	 *
	 * These exclusions check for the presence of WC cookies in the request.
	 * Since registered exclusion callbacks only receive the URL, these
	 * closures inspect the $_COOKIE superglobal directly at call time.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_cookie_exclusions(): void {
		// WC session cookie — wp_woocommerce_session_<hash>.
		$this->cacheability->register_exclusion(
			'wc_session_cookie',
			function ( string $url ): bool {
				if ( empty( $_COOKIE ) ) {
					return false;
				}

				foreach ( array_keys( $_COOKIE ) as $name ) {
					if ( 0 === strpos( $name, 'wp_woocommerce_session_' ) ) {
						return true;
					}
				}

				return false;
			},
			'woocommerce-session-cookie'
		);

		// WC items in cart cookie.
		$this->cacheability->register_exclusion(
			'wc_cart_cookie',
			function ( string $url ): bool {
				return isset( $_COOKIE['woocommerce_items_in_cart'] );
			},
			'woocommerce-cart-cookie'
		);

		// WC cart hash cookie.
		$this->cacheability->register_exclusion(
			'wc_cart_hash_cookie',
			function ( string $url ): bool {
				return isset( $_COOKIE['woocommerce_cart_hash'] );
			},
			'woocommerce-cart-hash-cookie'
		);
	}

	/**
	 * Register purge hooks for WooCommerce events.
	 *
	 * Two categories:
	 *
	 * 1. Order status changes → per-product purge of the purchased items
	 *    (audit N21). Cart/checkout/account are excluded from the cache by
	 *    this module anyway; a full purge bought nothing but a cold cache
	 *    on every order. Falls back to a full purge when the order cannot
	 *    be loaded.
	 *
	 * 2. Product stock/price changes → per-product purge. Only the affected
	 *    product page, shop page, and product category archives are purged,
	 *    preserving the rest of the cache.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function register_purge_hooks(): void {
		// Order status changes — per-product purge of the purchased items
		// (audit N21); full purge only as the can't-load-order fallback.
		$statuses = array(
			'pending',
			'processing',
			'on-hold',
			'completed',
			'cancelled',
			'refunded',
			'failed',
		);

		foreach ( $statuses as $status ) {
			add_action(
				"woocommerce_order_status_{$status}",
				array( $this, 'purge_on_order_status' ),
				10,
				1
			);
		}

		// Product stock changes — per-product purge.
		add_action( 'woocommerce_product_set_stock', array( $this, 'purge_product_from_stock_object' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'purge_variation_parent' ), 10, 1 );

		// Product update — per-product purge (covers price, title, description).
		add_action( 'woocommerce_update_product', array( $this, 'purge_product' ), 10, 1 );
	}

	/**
	 * Purge the pages an order transition actually invalidates (audit N21).
	 *
	 * Per-product purge: each purchased product's page, the shop page, and
	 * the product's category archives (stock / sales-count display). Cart,
	 * checkout and account pages are already excluded from the cache by
	 * this module, so the old full purge bought nothing but a cold cache
	 * on every single order.
	 *
	 * Falls back to a full purge when the order cannot be loaded — the
	 * pre-0.1.16 behaviour, kept as the safe default.
	 *
	 * @since 0.1.0
	 *
	 * @param int $order_id WooCommerce order ID (0 when fired without one).
	 * @return void
	 */
	public function purge_on_order_status( int $order_id = 0 ): void {
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );

			if ( $order && is_callable( array( $order, 'get_items' ) ) ) {
				$purged_any = false;

				foreach ( $order->get_items() as $item ) {
					if ( ! is_callable( array( $item, 'get_product_id' ) ) ) {
						continue;
					}

					$product_id = (int) $item->get_product_id();
					if ( $product_id > 0 ) {
						$this->purge_product( $product_id );
						$purged_any = true;
					}
				}

				if ( $purged_any ) {
					return;
				}
			}
		}

		// Unknown order shape — the pre-0.1.16 safe default.
		$this->purge->purge_all();
	}

	/**
	 * Purge a single product's cache when its stock changes.
	 *
	 * Receives a WC_Product instance from the woocommerce_product_set_stock hook.
	 *
	 * @since 0.1.1
	 *
	 * @param WC_Product $product The product whose stock changed.
	 * @return void
	 */
	public function purge_product_from_stock_object( $product ): void {
		if ( ! $product || ! is_callable( array( $product, 'get_id' ) ) ) {
			return;
		}
		$this->purge_product( $product->get_id() );
	}

	/**
	 * Purge the parent product's cache when a variation's stock changes.
	 *
	 * Receives a WC_Product_Variation instance. The parent product's page and
	 * category archives are the cache entries that need purging.
	 *
	 * @since 0.1.1
	 *
	 * @param WC_Product_Variation $variation The variation whose stock changed.
	 * @return void
	 */
	public function purge_variation_parent( $variation ): void {
		if ( ! $variation || ! is_callable( array( $variation, 'get_parent_id' ) ) ) {
			return;
		}
		$this->purge_product( $variation->get_parent_id() );
	}

	/**
	 * Purge a single product's cache: product page + shop page + category archives.
	 *
	 * Targeted purge that preserves the rest of the cache. Purges:
	 * - The product's permalink
	 * - The shop page (product listing)
	 * - Product category archive pages for the product's terms
	 *
	 * @since 0.1.1
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return void
	 */
	public function purge_product( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		// 1. Purge the product's permalink.
		$permalink = get_permalink( $product_id );
		if ( $permalink && '' !== $permalink ) {
			$this->purge->purge_url( $permalink );
		}

		// 2. Purge the shop page.
		$shop_page_id = wc_get_page_id( 'shop' );
		if ( $shop_page_id > 0 ) {
			$shop_permalink = get_permalink( $shop_page_id );
			if ( $shop_permalink && '' !== $shop_permalink ) {
				$this->purge->purge_url( $shop_permalink );
			}
		}

		// 3. Purge product category archive pages.
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term_id ) {
				$term_link = get_term_link( $term_id, 'product_cat' );
				if ( ! is_wp_error( $term_link ) ) {
					$this->purge->purge_url( $term_link );
				}
			}
		}
	}

	/**
	 * Get the URL path for a page ID, relative to the site home.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page_id WordPress page ID.
	 * @return string Path segment (e.g. '/cart/'), or empty string on failure.
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
	 * Matches when the URL starts with the page path. This covers the page
	 * itself plus any sub-paths (e.g. /my-account/orders/ matches
	 * /my-account/).
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