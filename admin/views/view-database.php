<?php
/**
 * Database optimization settings tab view (free edition).
 *
 * Manual cleanup only, preview first: a table of the free cleanup
 * operations with their pre-cleanup row counts and a Run button. Nothing is
 * ever deleted without the count being shown first (PRD 5.6).
 *
 * The Pro add-on adds its two extra operations and the scheduling controls
 * through the two action slots below (spec 30); with Pro absent nothing is
 * shown in their place.
 *
 * @package CachingOut
 * @since 0.1.0
 * @since 1.21.0 Free operations only; no schedule column.
 *
 * @var array $settings Full settings array from CachingOut_Settings::get_all().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$operations = array(
	'revisions'          => __( 'Post Revisions', 'cachingout' ),
	'auto_drafts'        => __( 'Auto Drafts', 'cachingout' ),
	'trashed_posts'      => __( 'Trashed Posts', 'cachingout' ),
	'spam_comments'      => __( 'Spam Comments', 'cachingout' ),
	'trashed_comments'   => __( 'Trashed Comments', 'cachingout' ),
	'expired_transients' => __( 'Expired Transients', 'cachingout' ),
	'all_transients'     => __( 'All Transients', 'cachingout' ),
);

$descriptions = array(
	'revisions'          => __( 'Deletes all post revisions. Revisions are copies of posts stored every time you save.', 'cachingout' ),
	'auto_drafts'        => __( 'Deletes auto-draft posts older than 7 days.', 'cachingout' ),
	'trashed_posts'      => __( 'Permanently deletes all posts currently in the trash, including their metadata.', 'cachingout' ),
	'spam_comments'      => __( 'Deletes all comments marked as spam.', 'cachingout' ),
	'trashed_comments'   => __( 'Permanently deletes all comments currently in the trash.', 'cachingout' ),
	'expired_transients' => __( 'Deletes transients that have passed their expiration time. Safe to run frequently.', 'cachingout' ),
	'all_transients'     => __( 'Deletes ALL transients (including unexpired ones). This may temporarily slow your site as caches rebuild.', 'cachingout' ),
);

// Build AJAX nonce.
$ajax_nonce = wp_create_nonce( 'cachingout_db_cleanup' );
?>

<div class="rs-database-wrap">
	<p class="rs-database-intro">
		<?php esc_html_e( 'Clean up your database to reclaim space and improve performance. Click "Preview" to see what would be removed, then "Run" to execute the operation. All cleanups are disabled by default — nothing is deleted until you enable and trigger it.', 'cachingout' ); ?>
	</p>

	<div class="rs-database-actions-top">
		<button type="button" class="rs-button rs-db-preview-all" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
			<?php esc_html_e( 'Preview All', 'cachingout' ); ?>
		</button>
	</div>

	<div class="rs-table-wrap">
	<table class="rs-table rs-database-table">
		<thead>
			<tr>
				<th class="rs-db-col-name"><?php esc_html_e( 'Operation', 'cachingout' ); ?></th>
				<th class="rs-db-col-count"><?php esc_html_e( 'Items to clean', 'cachingout' ); ?></th>
				<th class="rs-db-col-action"><?php esc_html_e( 'Action', 'cachingout' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $operations as $op_key => $op_label ) : ?>
				<tr class="rs-db-row" data-operation="<?php echo esc_attr( $op_key ); ?>">
					<td class="rs-db-col-name">
						<strong><?php echo esc_html( $op_label ); ?></strong>
						<p class="rs-db-desc"><?php echo esc_html( $descriptions[ $op_key ] ); ?></p>
					</td>
					<td class="rs-db-col-count">
						<span class="rs-db-count-value" data-op="<?php echo esc_attr( $op_key ); ?>">&mdash;</span>
						<span class="rs-db-estimate"></span>
					</td>
					<td class="rs-db-col-action">
						<button type="button" class="rs-button rs-button--sm rs-db-preview"data-op="<?php echo esc_attr( $op_key ); ?>" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
							<?php esc_html_e( 'Preview', 'cachingout' ); ?>
						</button>
						<button type="button" class="rs-button rs-button--primary rs-button--sm rs-db-run"data-op="<?php echo esc_attr( $op_key ); ?>" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>" disabled title="<?php esc_attr_e( 'Preview first: Run unlocks once this row shows how many rows it will remove.', 'cachingout' ); ?>">
							<?php esc_html_e( 'Run', 'cachingout' ); ?>
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php
			/**
			 * Fires inside the operations table body, after the free rows.
			 * The Pro add-on appends its own operation rows here, in the
			 * same three-column shape.
			 *
			 * @since 1.21.0
			 *
			 * @param array $settings Full settings array.
			 */
			do_action( 'cachingout_view_database_operations', $settings );
			?>
		</tbody>
	</table>
	</div>

	<?php
	/**
	 * Fires after the operations table. The Pro add-on renders the
	 * scheduled-cleanup controls here.
	 *
	 * @since 1.21.0
	 *
	 * @param array $settings Full settings array.
	 */
	do_action( 'cachingout_view_database_schedules', $settings );
	?>

	<div class="rs-database-actions-bottom">
		<button type="button" class="rs-button rs-button--primary rs-db-run-all" data-nonce="<?php echo esc_attr( $ajax_nonce ); ?>">
			<?php esc_html_e( 'Run All Previewed', 'cachingout' ); ?>
		</button>
	</div>

	<div class="rs-db-results" style="display:none;" aria-live="polite" role="status">
		<h3><?php esc_html_e( 'Results', 'cachingout' ); ?></h3>
		<div class="rs-db-results-inner"></div>
	</div>

	<p class="rs-db-disclaimer">
		<?php esc_html_e( 'Space saved is an estimate based on average row sizes. Actual savings may vary.', 'cachingout' ); ?>
	</p>
</div>
<?php
// The cachingOutDB settings object the tab's script reads is attached to that
// script in CachingOut_Admin::enqueue_assets() (wp_localize_script).