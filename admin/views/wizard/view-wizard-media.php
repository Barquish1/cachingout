<?php
/**
 * Setup Wizard — Media step.
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
$media = $settings['media'];
?>

<h2><?php esc_html_e( 'Optimize Media', 'cachingout' ); ?></h2>
<p class="rs-text--lead">
	<?php esc_html_e( 'Images and iframes are often the heaviest elements on a page. These settings reduce their impact on load times.', 'cachingout' ); ?>
</p>

<h3 class="rs-title-section">
	<?php CachingOut_Icons::render( 'image' ); ?>
	<?php esc_html_e( 'LazyLoad', 'cachingout' ); ?>
</h3>

<?php
CachingOut_Admin::render_toggle(
	'lazyload_enabled',
	__( 'Enable LazyLoad', 'cachingout' ),
	! empty( $media['lazyload_enabled'] ),
	__( 'Defer loading of images and iframes until they are about to enter the viewport. Reduces initial page weight significantly.', 'cachingout' )
);

CachingOut_Admin::render_toggle(
	'lazyload_images',
	__( 'LazyLoad images', 'cachingout' ),
	! empty( $media['lazyload_images'] ),
	__( 'Apply lazy loading to all image tags.', 'cachingout' )
);

CachingOut_Admin::render_toggle(
	'lazyload_iframes',
	__( 'LazyLoad iframes', 'cachingout' ),
	! empty( $media['lazyload_iframes'] ),
	__( 'Apply lazy loading to YouTube, Vimeo, and other embedded iframes.', 'cachingout' )
);

CachingOut_Admin::render_toggle(
	'lazyload_backgrounds',
	__( 'LazyLoad CSS backgrounds', 'cachingout' ),
	! empty( $media['lazyload_backgrounds'] ),
	__( 'Lazy load background images set via inline CSS or style attributes.', 'cachingout' )
);

/**
 * Fires at the end of the wizard's Media step. The Pro add-on renders the
 * WebP serving control here.
 *
 * @since 1.21.0
 *
 * @param array $settings Full settings array.
 */
do_action( 'cachingout_view_wizard_media_fields', $settings );
