<?php
/**
 * File optimization settings tab view (free edition).
 *
 * Renders toggles and textareas for CSS/JS minification, the per-file
 * inclusion/exclusion controls and Defer JavaScript.
 *
 * The Pro add-on renders CSS delivery, Google Fonts, file combination and
 * Delay JavaScript into the two action slots below (spec 30); with Pro
 * absent nothing is shown in their place.
 *
 * @package CachingOut
 * @since 0.1.0
 * @since 1.21.0 Free fields only; Pro fields arrive through the view slots.
 *
 * @var array $settings Full settings array from CachingOut_Settings::get_all().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$opt = $settings['file_optimization'];
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-form">
	<?php wp_nonce_field( 'cachingout_save_settings', 'cachingout_nonce' ); ?>
	<input type="hidden" name="action" value="cachingout_save_settings">
	<input type="hidden" name="cachingout_tab" value="file_optimization">

	<!-- CSS Minification -->
	<h2 class="rs-title-section"><?php esc_html_e( 'CSS Minification', 'cachingout' ); ?></h2>
	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'minify_css',
			__( 'Minify CSS', 'cachingout' ),
			$opt['minify_css'],
			__( 'Serve minified copies of your local stylesheets from the cache folder. Original files are never modified; relative image and font paths are rewritten so they keep working.', 'cachingout' )
		);
		CachingOut_Admin::render_textarea(
			'excluded_css',
			__( 'Excluded CSS files', 'cachingout' ),
			$opt['excluded_css'],
			__( '/wp-content/plugins/example/style.css', 'cachingout' ),
			__( 'CSS file URLs or URL patterns to exclude from all optimizations (one per line).', 'cachingout' )
		);
		CachingOut_Admin::render_textarea(
			'excluded_inline_css',
			__( 'Excluded inline CSS', 'cachingout' ),
			$opt['excluded_inline_css'],
			__( '.my-animation, @keyframes', 'cachingout' ),
			__( 'Substrings to match in inline <style> blocks to skip minification (one per line).', 'cachingout' )
		);
		?>
	</div>

	<!-- JavaScript Minification -->
	<h2 class="rs-title-section"><?php esc_html_e( 'JavaScript Minification', 'cachingout' ); ?></h2>
	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'minify_js',
			__( 'Minify JavaScript', 'cachingout' ),
			$opt['minify_js'],
			__( 'Serve minified copies of your local scripts from the cache folder. Original files are never modified; defer, async and module attributes are preserved.', 'cachingout' )
		);
		CachingOut_Admin::render_textarea(
			'excluded_js',
			__( 'Excluded JavaScript files', 'cachingout' ),
			$opt['excluded_js'],
			__( '/wp-content/plugins/example/script.js', 'cachingout' ),
			__( 'JavaScript file URLs or URL patterns to exclude from all optimizations (one per line).', 'cachingout' )
		);
		CachingOut_Admin::render_textarea(
			'excluded_inline_js',
			__( 'Excluded inline JavaScript', 'cachingout' ),
			$opt['excluded_inline_js'],
			__( 'document.write, jQuery(', 'cachingout' ),
			__( 'Substrings to match in inline <script> blocks to skip minification (one per line).', 'cachingout' )
		);
		?>
	</div>

	<?php
	/**
	 * Fires after the free minification blocks. The Pro add-on renders CSS
	 * delivery, Google Fonts and file combination here.
	 *
	 * @since 1.21.0
	 *
	 * @param array $settings Full settings array.
	 */
	do_action( 'cachingout_view_file_optimization_after_minify', $settings );
	?>

	<!-- JavaScript Loading -->
	<h2 class="rs-title-section"><?php esc_html_e( 'JavaScript Loading', 'cachingout' ); ?></h2>
	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'defer_js',
			__( 'Defer JavaScript', 'cachingout' ),
			$opt['defer_js'],
			__( 'Add the defer attribute to script tags so they do not block page rendering. jQuery, async scripts, and module scripts are excluded automatically.', 'cachingout' )
		);
		/**
		 * Fires inside the JavaScript Loading block, after Defer JavaScript.
		 * The Pro add-on renders Delay JavaScript and its sub-fields here.
		 *
		 * @since 1.21.0
		 *
		 * @param array $settings Full settings array.
		 */
		do_action( 'cachingout_view_file_optimization_after_defer_js', $settings );
		?>
	</div>

	<p class="submit">
		<button type="submit" class="rs-button rs-button--primary"><?php esc_html_e( 'Save Changes', 'cachingout' ); ?></button>
	</p>
</form>