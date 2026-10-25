<?php
/**
 * Filesystem Check page view.
 *
 * Rendered by CachingOut_Filesystem_Check_Page::render() inside the shared
 * page shell. Shows the read-only diagnostics and the Hide / Show control.
 *
 * @package CachingOut
 * @since 0.1.17
 *
 * @var bool   $fs_visible   Whether the check is currently shown.
 * @var array  $fs_results   CachingOut_Filesystem_Check::run() rows (empty when hidden).
 * @var string $fs_rerun_url Nonced URL that re-runs the activation filesystem writes.
 * @var string $fs_toggled   'shown' | 'hidden' | '' - set right after a toggle.
 * @var array  $fs_recent    Last ten audit rows (empty when hidden or unavailable).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$fs_badges = array(
	'ok'   => array( 'rs-chip--signal', __( 'OK', 'cachingout' ) ),
	'warn' => array( 'rs-chip--warning', __( 'Attention', 'cachingout' ) ),
	'fail' => array( 'rs-chip--error', __( 'Problem', 'cachingout' ) ),
);
?>

<?php if ( 'shown' === $fs_toggled ) : ?>
	<?php CachingOut_Admin::render_success_notice( __( 'Filesystem check is now shown.', 'cachingout' ) ); ?>
<?php elseif ( 'hidden' === $fs_toggled ) : ?>
	<?php CachingOut_Admin::render_success_notice( __( 'Filesystem check is now hidden.', 'cachingout' ) ); ?>
<?php endif; ?>

<div class="rs-cluster rs-cluster--between rs-cluster--start rs-cluster--loose rs-mb-4">
	<div>
		<h2 class="rs-mb-2"><?php esc_html_e( 'Filesystem Check', 'cachingout' ); ?></h2>
		<p class="rs-text--muted rs-mb-0">
			<?php esc_html_e( 'A read-only look at every file Caching Out needs in order to cache pages: the drop-in, the WP_CACHE constant, the cache directory and its guards, the bundled libraries and the background task wiring. Nothing here changes your site.', 'cachingout' ); ?>
		</p>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-shrink-0">
		<?php wp_nonce_field( CachingOut_Filesystem_Check_Page::NONCE_ACTION, 'cachingout_fs_nonce' ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( CachingOut_Filesystem_Check_Page::TOGGLE_ACTION ); ?>">
		<input type="hidden" name="fs_visible" value="<?php echo $fs_visible ? '0' : '1'; ?>">
		<?php if ( $fs_visible ) : ?>
			<button type="submit" class="rs-button rs-button--sm">
				<?php CachingOut_Icons::render( 'eye-slash' ); ?>
				<?php esc_html_e( 'Hide filesystem check', 'cachingout' ); ?>
			</button>
		<?php else : ?>
			<button type="submit" class="rs-button rs-button--primary rs-button--sm">
				<?php CachingOut_Icons::render( 'eye' ); ?>
				<?php esc_html_e( 'Show filesystem check', 'cachingout' ); ?>
			</button>
		<?php endif; ?>
	</form>
</div>

<?php if ( ! $fs_visible ) : ?>

	<?php
	CachingOut_Admin::render_info_box(
		esc_html__( 'The filesystem check is hidden. The activation notice stays out of your dashboard. A failed activation is still reported: hiding the check never hides real damage.', 'cachingout' )
	);
	?>

<?php else : ?>

	<div class="rs-table-wrap rs-mb-4">
		<table class="rs-table">
			<thead>
				<tr>
					<th scope="col" style="width: 25%;"><?php esc_html_e( 'Check', 'cachingout' ); ?></th>
					<th scope="col" style="width: 8rem;"><?php esc_html_e( 'Status', 'cachingout' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What was found', 'cachingout' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $fs_results as $fs_row ) : ?>
				<?php $fs_badge = isset( $fs_badges[ $fs_row['status'] ] ) ? $fs_badges[ $fs_row['status'] ] : $fs_badges['warn']; ?>
				<tr>
					<th scope="row" class="rs-text--semibold"><?php echo esc_html( $fs_row['label'] ); ?></th>
					<td>
						<span class="rs-chip <?php echo esc_attr( $fs_badge[0] ); ?>"><?php echo esc_html( $fs_badge[1] ); ?></span>
					</td>
					<td>
						<?php echo esc_html( $fs_row['detail'] ); ?>
						<?php if ( 'ok' !== $fs_row['status'] && '' !== $fs_row['fix'] ) : ?>
							<p class="rs-help rs-mt-2">
								<strong><?php esc_html_e( 'Suggested fix:', 'cachingout' ); ?></strong>
								<?php echo esc_html( $fs_row['fix'] ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<div class="rs-cluster">
		<a href="<?php echo esc_url( $fs_rerun_url ); ?>" class="rs-button rs-button--sm">
			<?php CachingOut_Icons::render( 'arrow-repeat' ); ?>
			<?php esc_html_e( 'Re-run filesystem check', 'cachingout' ); ?>
		</a>
		<span class="rs-help">
			<?php esc_html_e( 'Re-running repeats the activation writes (drop-in, WP_CACHE, cache directory) and refreshes the results above.', 'cachingout' ); ?>
		</span>
	</div>

	<?php
	/**
	 * Fires below the check table on the Filesystem Check page. The Pro
	 * add-on renders its "Recent changes" block (the last ten rows of the
	 * change record, with the links to the Activity page) here.
	 *
	 * @since 1.21.0
	 *
	 * @param array $fs_results The rows just rendered.
	 */
	do_action( 'cachingout_view_filesystem_check_after_rows', $fs_results );
	?>

<?php endif; ?>
