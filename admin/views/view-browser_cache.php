<?php
/**
 * Browser Cache settings tab view.
 *
 * Renders Expires headers, GZIP, and Brotli toggles.
 *
 * @package CachingOut
 * @since 0.1.0
 *
 * @var array $settings Full settings array from CachingOut_Settings::get_all().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$browser_cache = $settings['browser_cache'];
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-form">
	<?php wp_nonce_field( 'cachingout_save_settings', 'cachingout_nonce' ); ?>
	<input type="hidden" name="action" value="cachingout_save_settings">
	<input type="hidden" name="cachingout_tab" value="browser_cache">

	<h2 class="rs-title-section"><?php esc_html_e( 'Browser Caching', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'browser_cache_expires',
			__( 'Set far-future Expires headers', 'cachingout' ),
			$browser_cache['expires'],
			__( 'Instruct browsers to cache static assets (images, CSS, JavaScript) locally for up to one year. Speeds up return visits significantly.', 'cachingout' )
		);

		CachingOut_Admin::render_toggle(
			'browser_cache_gzip',
			__( 'Enable GZIP compression', 'cachingout' ),
			$browser_cache['gzip'],
			__( 'Compress text-based assets (HTML, CSS, JavaScript) before sending them to the browser. Reduces bandwidth usage and speeds up page loads.', 'cachingout' )
		);

		CachingOut_Admin::render_toggle(
			'browser_cache_brotli',
			__( 'Enable Brotli compression', 'cachingout' ),
			$browser_cache['brotli'],
			__( 'Brotli provides better compression than GZIP. Most modern browsers support it. When both are enabled, Brotli is preferred.', 'cachingout' )
		);
		?>
	</div>

	<div class="rs-info-box">
		<p><?php esc_html_e( 'These settings write rules to your .htaccess file (Apache). If you are using NGINX, you will need to add the equivalent rules manually — see the documentation for a ready-to-use snippet.', 'cachingout' ); ?></p>
	</div>

	<p class="submit">
		<button type="submit" class="rs-button rs-button--primary"><?php esc_html_e( 'Save Changes', 'cachingout' ); ?></button>
	</p>
</form>