<?php
/**
 * Media settings tab view (free edition).
 *
 * Renders the Media section: LazyLoad toggles for images, iframes, CSS
 * background images, above-the-fold exclusion, dimension enforcement, LCP
 * image priority and user-configured exclusion patterns.
 *
 * The Pro add-on renders its Media controls into the action slot below
 * (spec 30); with Pro absent nothing is shown in their place.
 *
 * @package CachingOut
 * @since 0.1.0
 * @since 1.21.0 Free fields only; Pro fields arrive through the view slot.
 *
 * @var array $settings Full settings array from CachingOut_Settings::get_all().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$media = $settings['media'];
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-form">
	<?php wp_nonce_field( 'cachingout_save_settings', 'cachingout_nonce' ); ?>
	<input type="hidden" name="action" value="cachingout_save_settings">
	<input type="hidden" name="cachingout_tab" value="media">

	<h2 class="rs-title-section"><?php esc_html_e( 'LazyLoad', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'lazyload_enabled',
			__( 'Enable LazyLoad', 'cachingout' ),
			$media['lazyload_enabled'],
			__( 'Defer loading of images, iframes, and CSS background images until they are about to enter the viewport. Reduces initial page weight and improves Largest Contentful Paint (LCP).', 'cachingout' )
		);

		CachingOut_Admin::render_toggle(
			'lazyload_images',
			__( 'LazyLoad Images', 'cachingout' ),
			$media['lazyload_images'],
			__( 'Add loading="lazy" and decoding="async" to all images unless excluded.', 'cachingout' )
		);

		CachingOut_Admin::render_toggle(
			'lazyload_iframes',
			__( 'LazyLoad Iframes', 'cachingout' ),
			$media['lazyload_iframes'],
			__( 'Add loading="lazy" to all iframes (videos, maps, embeds) unless excluded.', 'cachingout' )
		);

		CachingOut_Admin::render_toggle(
			'lazyload_backgrounds',
			__( 'LazyLoad CSS Background Images', 'cachingout' ),
			$media['lazyload_backgrounds'],
			__( 'Defer inline CSS background-image loading until the element scrolls into view. Replaces images with a transparent placeholder until then.', 'cachingout' )
		);
		CachingOut_Admin::render_toggle(
			'lazyload_videos',
			__( 'LazyLoad Videos and Pause Off-screen', 'cachingout' ),
			$media['lazyload_videos'],
			__( 'Self-hosted videos do not download until they are about to scroll into view; autoplay videos start only while visible and pause when scrolled away. Embedded YouTube and Vimeo players are paused when scrolled out of view. Use the exclusion patterns or data-no-lazy for a hero video that must start immediately.', 'cachingout' )
		);
		?>
	</div>

	<h2 class="rs-title-section"><?php esc_html_e( 'Above-the-Fold Exclusion', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'lazyload_exclude_above_fold',
			__( 'Exclude Above-the-Fold Images', 'cachingout' ),
			$media['lazyload_exclude_above_fold'],
			__( 'Skip lazy-loading for the first images on the page. This ensures the Largest Contentful Paint element loads immediately, improving LCP scores. The browser still loads the rest on demand.', 'cachingout' )
		);

		CachingOut_Admin::render_number(
			'lazyload_above_fold_count',
			__( 'Number of Excluded Images', 'cachingout' ),
			$media['lazyload_above_fold_count'],
			0,
			10,
			__( 'How many images at the top of the page should load immediately without being lazy-loaded. Set to 0 to lazy-load every image.', 'cachingout' )
		);
		?>
	</div>

	<h2 class="rs-title-section"><?php esc_html_e( 'Image Dimensions', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'lazyload_add_dimensions',
			__( 'Add Missing Width/Height', 'cachingout' ),
			$media['lazyload_add_dimensions'],
			__( 'Read the actual image dimensions from the server and add width and height attributes to images that lack them. Prevents Cumulative Layout Shift (CLS) caused by images loading without reserved space.', 'cachingout' )
		);
		?>
	</div>

	<h2 class="rs-title-section"><?php esc_html_e( 'LCP Image Priority', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'lcp_priority_enabled',
			__( 'Prioritise the main above-the-fold image', 'cachingout' ),
			$media['lcp_priority_enabled'],
			__( 'Finds the image most likely to be the Largest Contentful Paint (usually the hero) and adds fetchpriority="high" plus a preload hint so the browser fetches it before stylesheets, fonts and scripts compete for bandwidth. Respects an existing fetchpriority attribute; never touches lazy-loaded images. A wrong guess only wastes one hint.', 'cachingout' )
		);
		CachingOut_Admin::render_textarea(
			'lcp_priority_exclusions',
			__( 'Never prioritise', 'cachingout' ),
			$media['lcp_priority_exclusions'],
			__( 'slider&#10;carousel', 'cachingout' ),
			__( 'One pattern per line. Images whose tag contains one of these strings are never chosen. You can also add data-no-lcp to an image in your theme.', 'cachingout' )
		);
		?>
	</div>

	<?php
	/**
	 * Fires after the free LazyLoad and LCP blocks. The Pro add-on renders
	 * the YouTube click-to-load and WebP serving controls here.
	 *
	 * @since 1.21.0
	 *
	 * @param array $settings Full settings array.
	 */
	do_action( 'cachingout_view_media_after_lazyload', $settings );
	?>

	<h2 class="rs-title-section"><?php esc_html_e( 'Exclusions', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_textarea(
			'lazyload_exclusions',
			__( 'Excluded Patterns', 'cachingout' ),
			$media['lazyload_exclusions'],
			__( 'logo&#10;hero&#10;avatar', 'cachingout' ),
			__( 'One pattern per line. Any image or iframe whose tag contains one of these strings will be excluded from lazy loading. Partial matches on filenames, classes, or URLs all work.', 'cachingout' )
		);
		?>
	</div>

	<div class="rs-note">
		<p><strong><?php esc_html_e( 'Automatic exclusions', 'cachingout' ); ?></strong></p>
		<p><?php esc_html_e( 'The following are always excluded, regardless of the patterns above:', 'cachingout' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'Images/iframes with a loading attribute already set (loading="eager" or loading="lazy")', 'cachingout' ); ?></li>
			<li><?php esc_html_e( 'Tags with data-skip-lazy or data-no-lazy attributes', 'cachingout' ); ?></li>
			<li><?php esc_html_e( 'Tags containing the skip-lazy or no-lazy CSS class', 'cachingout' ); ?></li>
		</ul>
	</div>

	<p class="submit">
		<button type="submit" class="rs-button rs-button--primary"><?php esc_html_e( 'Save Changes', 'cachingout' ); ?></button>
	</p>
</form>