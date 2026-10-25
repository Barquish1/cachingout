<?php
/**
 * Object cache cooperation notice — admin info when Redis/Memcached active.
 *
 * Detects when a persistent object cache drop-in is active and shows an
 * informational admin notice explaining that page caching and object caching
 * work at different layers and complement each other. Dismissible.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object cache cooperation notice.
 *
 * @since 0.1.1
 */
class CachingOut_Object_Cache_Notice {

	/**
	 * User meta key for dismissal state.
	 *
	 * @since 0.1.1
	 * @var string
	 */
	const DISMISS_META_KEY = 'cachingout_object_cache_notice_dismissed';

	/**
	 * Register hooks.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'wp_ajax_cachingout_dismiss_object_cache_notice', array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Show the notice if an external object cache is active and not dismissed.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function maybe_show_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		if ( $this->is_dismissed() ) {
			return;
		}

		// The dismiss handler is attached through the script API (1.21.13),
		// in the footer, where the notice element already exists.
		wp_register_script( 'cachingout-object-cache-notice', false, array(), CACHINGOUT_VERSION, true );
		wp_enqueue_script( 'cachingout-object-cache-notice' );
		wp_add_inline_script(
			'cachingout-object-cache-notice',
			sprintf(
				'(function(){var el=document.getElementById("cachingout-object-cache-notice");if(!el){return;}'
				. 'el.addEventListener("click",function(e){if(e.target.closest(".notice-dismiss")){'
				. 'fetch(%1$s,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},'
				. 'body:new URLSearchParams({action:"cachingout_dismiss_object_cache_notice",nonce:%2$s})});}});})();',
				wp_json_encode( admin_url( 'admin-ajax.php' ) ),
				wp_json_encode( wp_create_nonce( 'cachingout_dismiss_object_cache_notice' ) )
			)
		);

		?>
		<div class="notice notice-info is-dismissible" id="cachingout-object-cache-notice">
			<p>
				<strong><?php esc_html_e( 'Caching Out', 'cachingout' ); ?>:</strong>
				<?php
				esc_html_e(
					'A persistent object cache (Redis/Memcached) was detected on your site. This is great — object caching and page caching work at different layers and complement each other. Caching Out handles page caching, browser caching, and front-end optimization; your object cache speeds up database queries. No configuration is needed.',
					'cachingout'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the AJAX dismissal.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		check_ajax_referer( 'cachingout_dismiss_object_cache_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		update_user_meta( get_current_user_id(), self::DISMISS_META_KEY, 1 );

		wp_send_json_success();
	}

	/**
	 * Check if the current user has dismissed the notice.
	 *
	 * @since 0.1.1
	 *
	 * @return bool True if dismissed.
	 */
	private function is_dismissed(): bool {
		return (bool) get_user_meta( get_current_user_id(), self::DISMISS_META_KEY, true );
	}
}