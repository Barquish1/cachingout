<?php
/**
 * Filesystem Check admin page - the "Hide / See" surface for the read-only
 * filesystem diagnostics.
 *
 * Lives in the Caching Out admin menu. Shows every CachingOut_Filesystem_Check
 * result with its suggested fix, and carries the single control that hides or
 * shows the check everywhere it appears (the activation notice, the AI
 * Assistant payload and this page's own table). The control writes the same
 * `cache.fs_check_enabled` setting the Cache tab toggle has used since 0.1.15,
 * so the two surfaces can never disagree.
 *
 * @package CachingOut
 * @since 0.1.17
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filesystem Check page controller.
 *
 * @since 0.1.17
 */
class CachingOut_Filesystem_Check_Page {

	/**
	 * Menu slug of this page (child of CachingOut_Admin::PAGE_SLUG).
	 *
	 * @since 0.1.17
	 * @var string
	 */
	const PAGE_SLUG = 'cachingout-filesystem-check';

	/**
	 * admin-post action that flips the visibility setting.
	 *
	 * @since 0.1.17
	 * @var string
	 */
	const TOGGLE_ACTION = 'cachingout_fs_check_visibility';

	/**
	 * Nonce action / field for the visibility form.
	 *
	 * @since 0.1.17
	 * @var string
	 */
	const NONCE_ACTION = 'cachingout_fs_check_visibility';

	/**
	 * Settings instance.
	 *
	 * @since 0.1.17
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.17
	 *
	 * @param CachingOut_Settings $settings Settings instance.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the admin-post handler for the visibility toggle.
	 *
	 * Menu registration is owned by CachingOut_Admin so the whole menu tree
	 * is declared in one place, in one order.
	 *
	 * @since 0.1.17
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::TOGGLE_ACTION, array( $this, 'handle_visibility_toggle' ) );
	}

	/**
	 * Absolute URL of this page.
	 *
	 * @since 0.1.17
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Whether the check is currently visible (the Cache tab's
	 * "Filesystem check" toggle).
	 *
	 * @since 0.1.17
	 * @return bool
	 */
	public function is_visible(): bool {
		return (bool) $this->settings->get( 'cache', 'fs_check_enabled', true );
	}

	/**
	 * Render the page.
	 *
	 * @since 0.1.17
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cachingout' ) );
		}

		$fs_visible = $this->is_visible();
		$fs_results = array();
		$fs_recent  = array();

		if ( $fs_visible && class_exists( 'CachingOut_Filesystem_Check' ) ) {
			$checker    = new CachingOut_Filesystem_Check( $this->settings );
			$fs_results = $checker->run();
		}

		// Spec 26: the two local diagnostics sit together - the last ten
		// changes next to the filesystem rows, with the same export link.
		if ( $fs_visible && class_exists( 'CachingOut_Audit' ) && class_exists( 'CachingOut_Activity_Page' ) ) {
			try {
				$fs_recent = CachingOut_Audit::default_instance()->query( array( 'limit' => 10 ) );
			} catch ( \Throwable $e ) {
				$fs_recent = array();
			}
		}

		// The activation-report "Re-run filesystem check" handler in
		// cachingout.php accepts this pair on any admin URL and redirects
		// back with both arguments removed.
		$fs_rerun_url = wp_nonce_url(
			add_query_arg( 'cachingout_fs_check', '1', self::url() ),
			'cachingout_fs_check'
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$fs_toggled = isset( $_GET['fs_toggled'] ) ? sanitize_key( wp_unslash( $_GET['fs_toggled'] ) ) : '';

		$view_file = CACHINGOUT_PATH . 'admin/views/view-filesystem_check.php';

		?>
		<div class="cachingout-wrap" role="region" aria-label="<?php esc_attr_e( 'Caching Out Filesystem Check', 'cachingout' ); ?>">
			<?php CachingOut_Admin::render_header(); ?>
			<div class="rs-body" id="rs-main-content">
				<?php
				if ( file_exists( $view_file ) ) {
					include $view_file;
				} else {
					echo '<div class="rs-alert rs-alert--warning" role="alert"><div class="rs-alert__body">' . esc_html__( 'Settings view not found.', 'cachingout' ) . '</div></div>';
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * admin-post: flip the visibility setting, then return to the page.
	 *
	 * Capability + nonce gated like every other settings write. Only the
	 * `fs_check_enabled` key is written; CachingOut_Settings::save() merges
	 * a partial section, so no other Cache setting is touched.
	 *
	 * @since 0.1.17
	 * @return void
	 */
	public function handle_visibility_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cachingout' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'cachingout_fs_nonce' );

		$visible = ! empty( $_POST['fs_visible'] );

		$this->settings->save( array( 'fs_check_enabled' => $visible ), 'cache' );

		wp_safe_redirect(
			add_query_arg( 'fs_toggled', $visible ? 'shown' : 'hidden', self::url() )
		);
		exit;
	}
}
