<?php
/**
 * Setup Wizard — Step 5: Finish.
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

<div class="rs-text--center">
	<div class="rs-mb-6">
		<div class="rs-icon-well">
			<?php CachingOut_Icons::render( 'check-circle-fill', 'rs-icon--xl rs-icon--accent' ); ?>
		</div>
	</div>

	<h2><?php esc_html_e( 'Your Site is Optimized!', 'cachingout' ); ?></h2>
	<p class="rs-text--lead">
		<?php esc_html_e( 'Caching Out is now configured with performance best practices. Here\'s what\'s active:', 'cachingout' ); ?>
	</p>
</div>

<div class="rs-wizard-features">
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'lightning-charge' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'Page Caching', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Static HTML files served to anonymous visitors, before WordPress loads.', 'cachingout' ); ?></p>
		</div>
	</div>
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'globe2' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'Browser Caching', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Optimal cache headers and GZIP compression enabled.', 'cachingout' ); ?></p>
		</div>
	</div>
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'image' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'Media Optimization', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Lazy loading active for images, iframes, and backgrounds.', 'cachingout' ); ?></p>
		</div>
	</div>
	<div class="rs-wizard-feature">
		<div class="rs-wizard-feature__icon">
			<?php CachingOut_Icons::render( 'file-earmark-code' ); ?>
		</div>
		<div class="rs-wizard-feature__text">
			<h4><?php esc_html_e( 'File Optimization', 'cachingout' ); ?></h4>
			<p><?php esc_html_e( 'Minified CSS and JavaScript, with per-file exclusions.', 'cachingout' ); ?></p>
		</div>
	</div>
</div>

<?php
/**
 * Fires on the wizard's final step, below the feature list. The Pro add-on
 * renders its own state block (installed but not switched on, and where the
 * licence key goes) here.
 *
 * @since 1.21.0
 *
 * @param array $settings Full settings array.
 */
do_action( 'cachingout_view_wizard_finish_fields', $settings );
?>

<div class="rs-info-box">
	<p>
		<?php CachingOut_Icons::render( 'gear' ); ?>
		<strong><?php esc_html_e( 'What\'s next?', 'cachingout' ); ?></strong>
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: URL to plugin settings page */
				__( 'Visit the <a href="%s">plugin settings</a> to fine-tune exclusions, enable more optimizations, and clean up your database. All settings are safe to adjust at any time.', 'cachingout' ),
				esc_url( admin_url( 'admin.php?page=cachingout' ) )
			),
			array( 'a' => array( 'href' => array() ) )
		);
		?>
	</p>
</div>

<p class="rs-text--muted rs-text--small rs-text--center rs-mb-0">
	<?php esc_html_e( 'Click "Finish Setup" to go to your settings dashboard.', 'cachingout' ); ?>
</p>