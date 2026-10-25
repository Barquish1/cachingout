<?php
/**
 * Admin-bar integration — adds Purge Cache and Preload Cache items to the
 * WordPress toolbar for quick access.
 *
 * @package CachingOut
 * @since 0.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin-bar menu provider.
 *
 * Registers a "Caching Out" parent node in the WordPress admin bar with
 * "Purge Cache" and "Preload Cache" sub-items. Both actions route through
 * the existing admin-post handlers (CachingOut_Purge::handle_manual_purge
 * and CachingOut_Preload::handle_manual_preload), which perform capability
 * and nonce checks.
 *
 * @since 0.1.1
 */
class CachingOut_Admin_Bar {

	/**
	 * Register the admin-bar menu hook.
	 *
	 * @since 0.1.1
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_menu_items' ), 100 );
	}

	/**
	 * Add Caching Out items to the admin bar.
	 *
	 * Only shown to users with the `manage_options` capability.
	 *
	 * @since 0.1.1
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 * @return void
	 */
	public function add_menu_items( WP_Admin_Bar $admin_bar ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Default `_wpnonce` query arg — the handlers read $_GET['_wpnonce']
		// (class-purge.php / class-preload.php). Custom arg names here made
		// every admin-bar click fail nonce verification (audit N7).
		$purge_nonce_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=cachingout_purge_all' ),
			'cachingout_purge_all'
		);

		$preload_nonce_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=cachingout_preload' ),
			'cachingout_preload'
		);

		$admin_bar->add_node(
			array(
				'id'    => 'cachingout',
				'title' => '<span class="ab-icon" style="padding-top:6px;">'
					. CachingOut_Brand::mark( 'currentColor', 'currentColor', 20, CachingOut_Brand::VIEWBOX_TIGHT, 'display:block;' )
					. '</span>'
					. '<span class="ab-label">' . esc_html__( 'Caching Out', 'cachingout' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=cachingout' ),
				'meta'  => array(
					'title' => __( 'Caching Out — Performance', 'cachingout' ),
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => 'cachingout-purge',
				'parent' => 'cachingout',
				'title'  => __( 'Purge Cache', 'cachingout' ),
				'href'   => $purge_nonce_url,
				'meta'   => array(
					'title' => __( 'Clear all cached pages', 'cachingout' ),
				),
			)
		);

		// Preloading is a Pro feature (spec 30): the item exists only while
		// the add-on that handles admin_post_cachingout_preload is switched on.
		if ( class_exists( 'CachingOut_Features' ) && CachingOut_Features::available( 'preload' ) ) {
			$admin_bar->add_node(
				array(
					'id'     => 'cachingout-preload',
					'parent' => 'cachingout',
					'title'  => __( 'Preload Cache', 'cachingout' ),
					'href'   => $preload_nonce_url,
					'meta'   => array(
						'title' => __( 'Warm the cache by crawling the sitemap', 'cachingout' ),
					),
				)
			);
		}

		/**
		 * Fires after the free edition's admin-bar items are added.
		 *
		 * @since 1.21.0
		 *
		 * @param WP_Admin_Bar $admin_bar Admin bar instance.
		 */
		do_action( 'cachingout_admin_bar_items', $admin_bar );
	}
}