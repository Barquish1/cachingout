<?php
/**
 * Heartbeat & DNS Prefetch settings tab view.
 *
 * Renders the heartbeat section of the settings page: frontend disable,
 * admin/post-editor throttle intervals, and DNS prefetch/preconnect domains.
 *
 * @package CachingOut
 * @since 0.1.1
 *
 * @var array $settings Full settings array from CachingOut_Settings::get_all().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$heartbeat = $settings['heartbeat'];
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-form">
	<?php wp_nonce_field( 'cachingout_save_settings', 'cachingout_nonce' ); ?>
	<input type="hidden" name="action" value="cachingout_save_settings">
	<input type="hidden" name="cachingout_tab" value="heartbeat">

	<h2 class="rs-title-section"><?php esc_html_e( 'Heartbeat Control', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'heartbeat_frontend',
			__( 'Disable heartbeat on frontend', 'cachingout' ),
			! empty( $heartbeat['heartbeat_frontend'] ),
			__( 'Stop the WordPress Heartbeat API from running on the frontend. This reduces AJAX requests and server load for anonymous visitors.', 'cachingout' )
		);

		CachingOut_Admin::render_number(
			'heartbeat_admin',
			__( 'Admin heartbeat interval (seconds)', 'cachingout' ),
			isset( $heartbeat['heartbeat_admin'] ) ? absint( $heartbeat['heartbeat_admin'] ) : 60,
			15,
			300,
			__( 'How often the heartbeat runs in the WordPress admin area. Default is 60 seconds. Lower values increase server load.', 'cachingout' )
		);

		CachingOut_Admin::render_number(
			'heartbeat_post_editor',
			__( 'Post editor heartbeat interval (seconds)', 'cachingout' ),
			isset( $heartbeat['heartbeat_post_editor'] ) ? absint( $heartbeat['heartbeat_post_editor'] ) : 30,
			15,
			300,
			__( 'How often the heartbeat runs while editing a post. Default is 30 seconds. This affects autosave frequency.', 'cachingout' )
		);
		?>
	</div>

	<h2 class="rs-title-section"><?php esc_html_e( 'DNS Prefetch', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_textarea(
			'dns_prefetch_domains',
			__( 'DNS prefetch domains', 'cachingout' ),
			isset( $heartbeat['dns_prefetch_domains'] ) ? $heartbeat['dns_prefetch_domains'] : array(),
			__( 'e.g. fonts.googleapis.com, www.google-analytics.com', 'cachingout' ),
			__( 'External domains to prefetch and preconnect. One domain per line (no protocol). Each domain will emit a dns-prefetch and preconnect link tag in the page head.', 'cachingout' )
		);
		?>
	</div>

	<p class="submit">
		<button type="submit" class="rs-button rs-button--primary"><?php esc_html_e( 'Save Changes', 'cachingout' ); ?></button>
	</p>
</form>