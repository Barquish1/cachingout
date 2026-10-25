<?php
/**
 * Setup Wizard — Step 1: Welcome.
 *
 * @package CachingOut
 * @since 0.1.0
 *
 * @var array $settings Full settings array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<h2><?php esc_html_e( 'Welcome to Caching Out!', 'cachingout' ); ?></h2>
<p class="rs-text--lead">
	<?php esc_html_e( 'You\'re just a few steps away from a faster website. This wizard will help you configure the most important performance settings in under 2 minutes.', 'cachingout' ); ?>
</p>

<div class="rs-info-box">
	<p>
		<?php CachingOut_Icons::render( 'lightbulb' ); ?>
		<strong><?php esc_html_e( 'Pro tip:', 'cachingout' ); ?></strong>
		<?php esc_html_e( 'All settings can be changed later from the plugin settings page. The wizard applies safe defaults that work for most sites.', 'cachingout' ); ?>
	</p>
</div>

<div class="rs-wizard-features">
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'lightning-charge' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'Page Caching', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Serve static HTML files for dramatically faster page loads.', 'cachingout' ); ?></p>
		</div>
	</div>
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'globe2' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'Browser Caching', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Set optimal cache headers and enable GZIP/Brotli compression.', 'cachingout' ); ?></p>
		</div>
	</div>
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'image' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'Media Optimization', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Lazy load images and iframes, and give the hero image loading priority.', 'cachingout' ); ?></p>
		</div>
	</div>
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'file-earmark-code' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'File Optimization', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Minify CSS/JS, defer JavaScript, and optimize delivery.', 'cachingout' ); ?></p>
		</div>
	</div>
</div>

<p class="rs-text--muted rs-text--small">
	<?php esc_html_e( 'Click "Let\'s Go!" to start configuring your site. You can skip any step and come back later.', 'cachingout' ); ?>
</p>