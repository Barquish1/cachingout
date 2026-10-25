<?php
/**
 * Heartbeat control — throttle or disable the WordPress heartbeat API.
 *
 * Hooks `wp_heartbeat_settings` to modify the heartbeat interval per location
 * (frontend, admin, post editor). Reduces AJAX requests and server load.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Heartbeat control.
 *
 * @since 0.1.1
 */
class CachingOut_Heartbeat {

	/**
	 * Plugin settings instance.
	 *
	 * @since 0.1.1
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.1
	 *
	 * @param CachingOut_Settings $settings Plugin settings.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the heartbeat filter.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_heartbeat_settings', array( $this, 'modify_heartbeat_settings' ), 10, 2 );

		// Audit N23: core clamps a 0 interval back to a minimum, so the
		// frontend "disable" silently no-opped. Actually disabling means
		// deregistering the script so it is never enqueued on the frontend.
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_dequeue_frontend' ), 100 );
	}

	/**
	 * Deregister the heartbeat script on the frontend when disabled.
	 *
	 * Filtering the interval to 0 never disabled anything because core
	 * clamps it (audit N23). Deregistering the script is the reliable
	 * disable; admin and post-editor screens are untouched.
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function maybe_dequeue_frontend(): void {
		$options = $this->settings->get_section( 'heartbeat' );

		if ( empty( $options['heartbeat_frontend'] ) ) {
			return;
		}

		wp_dequeue_script( 'heartbeat' );
		wp_deregister_script( 'heartbeat' );
	}

	/**
	 * Modify heartbeat settings based on plugin configuration.
	 *
	 * @since 0.1.1
	 *
	 * @param array  $settings  Current heartbeat settings.
	 * @param string $location  Where the heartbeat is running: 'frontend', 'admin', or 'post'.
	 * @return array Modified heartbeat settings.
	 */
	public function modify_heartbeat_settings( array $settings, string $location ): array {
		$options = $this->settings->get_section( 'heartbeat' );

		switch ( $location ) {
			case 'frontend':
				if ( ! empty( $options['heartbeat_frontend'] ) ) {
					// Disable heartbeat on frontend entirely.
					$settings['interval']        = 0;
					$settings['minimalInterval'] = 0;
				}
				break;

			case 'admin':
				$admin_interval = isset( $options['heartbeat_admin'] )
					? absint( $options['heartbeat_admin'] )
					: 60;
				if ( $admin_interval > 0 ) {
					$settings['interval']        = $admin_interval;
					$settings['minimalInterval'] = $admin_interval;
				}
				break;

			case 'post':
				$post_interval = isset( $options['heartbeat_post_editor'] )
					? absint( $options['heartbeat_post_editor'] )
					: 30;
				// Never disable entirely — autosave depends on heartbeat.
				if ( $post_interval < 15 ) {
					$post_interval = 15;
				}
				$settings['interval']        = $post_interval;
				$settings['minimalInterval'] = $post_interval;
				break;
		}

		return $settings;
	}
}