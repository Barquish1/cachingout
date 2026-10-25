<?php
/**
 * Insights tab view (free edition).
 *
 * The free edition owns the Page Audit in full: it reads each tracked page
 * the way a visitor would, lists every finding, and applies the fixes that
 * are plugin settings. No API key, no account, no outbound request beyond
 * the same-host loopback the operator triggers by hand.
 *
 * The Pro add-on renders the PageSpeed panel (opt-in, API key, tracked-page
 * scores, history chart) and the recommendations list into the two action
 * slots below (spec 30). With Pro absent nothing is shown in their place -
 * no placeholder, no blurred panel.
 *
 * @package CachingOut
 * @since 0.1.0
 * @since 1.21.0 Page Audit only; the PageSpeed panel is a Pro partial.
 *
 * @var array $settings All plugin settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$insights = isset( $settings['insights'] ) ? $settings['insights'] : array();
?>

<div class="rs-card">
	<div class="rs-card__header">
		<h2 class="rs-card__title">
			<?php CachingOut_Icons::render( 'graph-up' ); ?>
			<?php esc_html_e( 'Performance Insights', 'cachingout' ); ?>
		</h2>
		<p class="rs-card__description">
			<?php esc_html_e( 'Audit your key pages and get plain-language findings: one click where a plugin setting solves it, plain advice where your theme or host does.', 'cachingout' ); ?>
		</p>
	</div>

	<div class="rs-card__body">
		<?php
		/**
		 * Fires at the top of the Insights tab body, before the Page Audit.
		 * The Pro add-on renders the PageSpeed panel (opt-in, API key,
		 * tracked-page scores and the score-history chart) here.
		 *
		 * @since 1.21.0
		 *
		 * @param array $settings Full settings array.
		 */
		do_action( 'cachingout_view_insights_before_page_audit', $settings );
		?>

		<!-- Page Audit (Spec 24): the plugin reads its own pages, no API key -->
		<div class="rs-insights-page-audit rs-mb-6" id="rs-insights-page-audit">
			<div class="rs-cluster rs-cluster--between rs-mb-2">
				<h3 class="rs-mb-0"><?php esc_html_e( 'Page Audit', 'cachingout' ); ?></h3>
				<button type="button" class="rs-button rs-button--sm" id="rs-run-page-audit">
					<?php CachingOut_Icons::render( 'search' ); ?> <?php esc_html_e( 'Audit pages now', 'cachingout' ); ?>
				</button>
			</div>
			<p class="rs-text--muted rs-text--small rs-mb-2">
				<?php esc_html_e( 'Caching Out reads each tracked page the way a visitor would and lists what to fix: one click where a plugin setting solves it, plain advice where your theme or host does. No API key needed.', 'cachingout' ); ?>
			</p>
			<div id="rs-page-audit-list">
				<div class="rs-text--muted rs-text--small"><?php esc_html_e( 'Loading...', 'cachingout' ); ?></div>
			</div>
		</div>

		<?php
		/**
		 * Fires after the Page Audit. The Pro add-on renders the PageSpeed
		 * recommendations list here.
		 *
		 * @since 1.21.0
		 *
		 * @param array $settings Full settings array.
		 */
		do_action( 'cachingout_view_insights_after_page_audit', $settings );
		?>
	</div>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="rs-insights-settings-form" style="display:none;">
	<?php wp_nonce_field( 'cachingout_save_settings', 'cachingout_nonce' ); ?>
	<input type="hidden" name="action" value="cachingout_save_settings">
	<input type="hidden" name="cachingout_tab" value="insights">
	<input type="hidden" name="insights_enabled" value="1">
	<input type="hidden" name="insights_mobile_audits" value="<?php echo ! empty( $insights['mobile_audits'] ) ? '1' : '0'; ?>">
	<?php
	/**
	 * Fires inside the hidden Insights settings form. The Pro add-on adds
	 * the hidden inputs for the keys it owns so a submit round-trips them.
	 *
	 * @since 1.21.0
	 *
	 * @param array $settings Full settings array.
	 */
	do_action( 'cachingout_view_insights_settings_form', $settings );
	?>
</form>
