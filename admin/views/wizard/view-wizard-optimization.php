<?php
/**
 * Setup Wizard — File Optimization step.
 *
 * Uses CachingOut_Admin::render_toggle() so the switches match the
 * settings tabs (rs-switch sibling-track markup).
 *
 * @package CachingOut
 * @since 0.1.0
 *
 * @var array $settings Full settings array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$fo = $settings['file_optimization'];
?>

<h2><?php esc_html_e( 'Optimize Files', 'cachingout' ); ?></h2>
<p class="rs-text--lead">
	<?php esc_html_e( 'Minify and defer CSS and JavaScript to cut render-blocking resources. Start conservatively - you can always enable more options later.', 'cachingout' ); ?>
</p>

<div class="rs-info-box">
	<p>
		<?php CachingOut_Icons::render( 'info-circle' ); ?>
		<strong><?php esc_html_e( 'Start small:', 'cachingout' ); ?></strong>
		<?php esc_html_e( 'We recommend enabling one optimization at a time and checking your site. Some themes and plugins may need exclusions.', 'cachingout' ); ?>
	</p>
</div>

<h3 class="rs-title-section">
	<?php CachingOut_Icons::render( 'file-earmark-code' ); ?>
	<?php esc_html_e( 'CSS Optimization', 'cachingout' ); ?>
</h3>

<?php
CachingOut_Admin::render_toggle(
	'minify_css',
	__( 'Minify CSS', 'cachingout' ),
	! empty( $fo['minify_css'] ),
	__( 'Remove whitespace and comments from CSS files to reduce their size.', 'cachingout' )
);
?>

<h3 class="rs-title-section">
	<?php CachingOut_Icons::render( 'filetype-js' ); ?>
	<?php esc_html_e( 'JavaScript Optimization', 'cachingout' ); ?>
</h3>

<?php
CachingOut_Admin::render_toggle(
	'minify_js',
	__( 'Minify JavaScript', 'cachingout' ),
	! empty( $fo['minify_js'] ),
	__( 'Remove whitespace and comments from JavaScript files.', 'cachingout' )
);

CachingOut_Admin::render_toggle(
	'defer_js',
	__( 'Defer JavaScript', 'cachingout' ),
	! empty( $fo['defer_js'] ),
	__( 'Add defer attribute to script tags so they don\'t block page rendering.', 'cachingout' )
);

/**
 * Fires at the end of the wizard's File Optimization step. The Pro add-on
 * renders the Delay JavaScript control here.
 *
 * @since 1.21.0
 *
 * @param array $settings Full settings array.
 */
do_action( 'cachingout_view_wizard_optimization_fields', $settings );
