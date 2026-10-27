<?php
/**
 * Plugin Name:       Caching Out
 * Plugin URI:        https://cachingout.com/docs/
 * Description:       Page caching, browser caching, compression and lazy loading - on the moment you activate. Never cached for logged-in visitors or carts.
 * Version:           1.23.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Caching Out
 * Author URI:        https://cachingout.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cachingout
 * Domain Path:       /languages
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 *
 * CACHINGOUT_VERSION must match the Version header above and readme.txt's
 * stable tag. The release script enforces this.
 */
define( 'CACHINGOUT_VERSION', '1.23.0' );










define( 'CACHINGOUT_FILE', __FILE__ );
/*
 * The advanced-cache.php drop-in runs before any plugin and defines
 * CACHINGOUT_PATH and CACHINGOUT_SLUG for the classes it loads, so on every
 * front-end request they already exist by the time WordPress loads this
 * file. Guard them (1.21.6): a second define() logs a PHP warning per
 * request on hosts with error logging on.
 */
if ( ! defined( 'CACHINGOUT_PATH' ) ) {
	define( 'CACHINGOUT_PATH', plugin_dir_path( __FILE__ ) );
}
define( 'CACHINGOUT_URL', plugin_dir_url( __FILE__ ) );
define( 'CACHINGOUT_BASENAME', plugin_basename( __FILE__ ) );
if ( ! defined( 'CACHINGOUT_SLUG' ) ) {
	define( 'CACHINGOUT_SLUG', 'cachingout' );
}

/** Minimum supported environment. */
define( 'CACHINGOUT_MIN_PHP', '7.4' );
define( 'CACHINGOUT_MIN_WP', '6.4' );

/**
 * Global kill switch.
 *
 * Defining CACHINGOUT_DISABLE_CACHE as true in wp-config.php must always
 * disable all cache serving and writing, in every version, forever. This is the
 * documented mitigation for a bad cache release
 * (see .clinerules/03-caching-safety.md).
 */
if ( ! defined( 'CACHINGOUT_DISABLE_CACHE' ) ) {
	define( 'CACHINGOUT_DISABLE_CACHE', false );
}

/**
 * Check whether the environment meets the minimum requirements.
 *
 * No scalar/return type hints are used in THIS file: it is parsed by every PHP
 * version that can reach the plugins directory, including versions below our
 * documented floor. The version guard below is what protects the rest of the
 * codebase, so this file must never be the thing that fatals.
 *
 * @return bool True when the environment meets the minimum requirements.
 */
function cachingout_environment_supported() {
	return version_compare( PHP_VERSION, CACHINGOUT_MIN_PHP, '>=' )
		&& version_compare( get_bloginfo( 'version' ), CACHINGOUT_MIN_WP, '>=' );
}

/**
 * Bootstrap the plugin.
 *
 * Kept intentionally thin: this file declares constants and guards only. All
 * wiring belongs in CachingOut_Plugin (includes/class-plugin.php).
 *
 * @return void
 */
function cachingout_bootstrap() {
	if ( ! cachingout_environment_supported() ) {
		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					esc_html__( 'Caching Out requires PHP 7.4+ and WordPress 6.4+ and has not been loaded.', 'cachingout' )
				);
			}
		);
		return;
	}

	if ( ! class_exists( 'CachingOut_Plugin' ) ) {
		require_once CACHINGOUT_PATH . 'includes/class-plugin.php';
	}

	CachingOut_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'cachingout_bootstrap' );

/**
 * Activation / deactivation hooks.
 *
 * Activation applies the safe-by-default settings required by PRD 5.12.
 * WordPress ignores the return value of the activation callback, so every
 * step result is persisted to `cachingout_activation_report` and shown as
 * an admin notice — silent permission failures were leaving the plugin
 * "active" with no drop-in and no WP_CACHE (BUG-009).
 *
 * Deactivation must never take the site down (BUG-008 / BUG-010 / BUG-011).
 * It only deletes our advanced-cache.php drop-in. It does not edit
 * wp-config.php or .htaccess. The hook loads no activator and no other
 * subsystems — inline unlink only, then optional best-effort cron clear.
 */
register_activation_hook(
	__FILE__,
	static function ( $network_wide = false ) {
		require_once CACHINGOUT_PATH . 'includes/class-activator.php';

		// Spec 28 Phase 2: a network activation runs the report on every
		// site under switch_to_blog(); each site keeps its own report,
		// defaults and switch-on state. Any failure falls through to the
		// single-site path so activation never fatals.
		if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
			try {
				$reports = CachingOut_Activator::activate_network();
				if ( is_array( $reports ) && ! empty( $reports ) ) {
					return;
				}
			} catch ( \Throwable $e ) {
				if ( function_exists( 'error_log' ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
					error_log( 'Caching Out network activation: ' . $e->getMessage() );
				}
			}
		}

		$report = CachingOut_Activator::activate_with_report();
		$report['show_notice'] = true;
		update_option( 'cachingout_activation_report', $report, false );
		update_option( 'cachingout_installed_version', CACHINGOUT_VERSION, false );
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		// Inline drop-in removal — zero includes. Must never fatal.
		try {
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
				if ( is_string( $dropin ) && file_exists( $dropin ) ) {
					$contents = @file_get_contents( $dropin );
					$stored   = get_option( 'cachingout_dropin_hash', '' );

					// Only delete when the on-disk file matches our stamped
					// SHA-256. A read failure or missing hash is treated as
					// "not ours" — fail closed, never delete.
					$is_ours = ( false !== $contents )
						&& is_string( $stored )
						&& '' !== $stored
						&& hash_equals( $stored, hash( 'sha256', $contents ) );

					if ( $is_ours ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes the plugin's own advanced-cache.php drop-in, and only after its hash matched ours.
						@unlink( $dropin );
						delete_option( 'cachingout_dropin_hash' );
					}
				}
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out deactivate dropin: ' . $e->getMessage() );
			}
		}

		// Best-effort cron clear via deactivator if present; never required.
		try {
			$deact = CACHINGOUT_PATH . 'includes/class-deactivator.php';
			if ( file_exists( $deact ) ) {
				require_once $deact;
				if ( class_exists( 'CachingOut_Deactivator' ) ) {
					CachingOut_Deactivator::deactivate();
				}
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out deactivate: ' . $e->getMessage() );
			}
		}
	}
);


/**
 * After a zip overwrite the plugin stays Active and WordPress does NOT re-run
 * register_activation_hook. Detect a version change (or missing report) and
 * run the filesystem check once so the admin notice still appears (BUG-009).
 */
add_action(
	'admin_init',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Manual re-check button.
		if ( isset( $_GET['cachingout_fs_check'] ) && isset( $_GET['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cachingout_fs_check' )
		) {
			require_once CACHINGOUT_PATH . 'includes/class-activator.php';
			$report = CachingOut_Activator::activate_with_report();
			$report['show_notice'] = true;
			update_option( 'cachingout_activation_report', $report, false );
			update_option( 'cachingout_installed_version', CACHINGOUT_VERSION, false );
			wp_safe_redirect( remove_query_arg( array( 'cachingout_fs_check', '_wpnonce' ) ) );
			exit;
		}

		$installed = get_option( 'cachingout_installed_version', '' );
		$report    = get_option( 'cachingout_activation_report', null );
		$need      = ( $installed !== CACHINGOUT_VERSION )
			|| ! is_array( $report )
			|| empty( $report['steps'] );

		if ( ! $need ) {
			return;
		}

		require_once CACHINGOUT_PATH . 'includes/class-activator.php';
		$report = CachingOut_Activator::activate_with_report();
		$report['show_notice'] = true;
		update_option( 'cachingout_activation_report', $report, false );
		update_option( 'cachingout_installed_version', CACHINGOUT_VERSION, false );
	}
);

/**
 * Surface activation write failures in wp-admin (BUG-009).
 */
add_action(
	'admin_notices',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$report = get_option( 'cachingout_activation_report', null );
		if ( ! is_array( $report ) || empty( $report['show_notice'] ) ) {
			return;
		}

		// Filesystem-check notice toggle (0.1.15, Cache tab). Read the option
		// directly — this closure must never depend on the plugin having
		// booted. A FAILED report is always shown: suppressing a broken
		// install's only warning would hide real damage.
		$fs_settings   = get_option( 'cachingout_settings', array() );
		$fs_check_on   = ! isset( $fs_settings['cache']['fs_check_enabled'] )
			|| ! empty( $fs_settings['cache']['fs_check_enabled'] );
		if ( ! $fs_check_on && ! empty( $report['ok'] ) ) {
			return;
		}
		$ok = ! empty( $report['ok'] );
		$class = ( $ok ? 'notice notice-success is-dismissible' : 'notice notice-error' ) . ' cachingout-notice';
		$check_url = wp_nonce_url(
			add_query_arg( 'cachingout_fs_check', '1' ),
			'cachingout_fs_check'
		);
		echo '<div class="' . esc_attr( $class ) . '"><p class="cachingout-notice__title">Caching Out filesystem check<span class="cachingout-notice__version">v' . esc_html( CACHINGOUT_VERSION ) . '</span></p><ul>';
		if ( ! empty( $report['steps'] ) && is_array( $report['steps'] ) ) {
			foreach ( $report['steps'] as $step => $info ) {
				if ( ! is_array( $info ) ) {
					continue;
				}
				$status = ! empty( $info['ok'] ) ? 'OK' : 'FAILED';
				$msg    = isset( $info['message'] ) ? $info['message'] : '';
				echo '<li><code>' . esc_html( (string) $step ) . '</code>: ' . esc_html( $status );
				if ( $msg ) {
					echo ' — ' . esc_html( (string) $msg );
				}
				echo '</li>';
			}
		}
		if ( ! empty( $report['paths'] ) && is_array( $report['paths'] ) ) {
			echo '</ul><p>Paths checked:</p><ul>';
			foreach ( $report['paths'] as $label => $path ) {
				$exists = ( is_string( $path ) && $path !== '' && file_exists( $path ) ) ? 'exists' : 'MISSING';
				echo '<li>' . esc_html( (string) $label ) . ': <code>' . esc_html( (string) $path ) . '</code> (' . esc_html( $exists ) . ')</li>';
			}
		}
		echo '</ul>';
		if ( ! $ok ) {
			// BUG-019: the browser-cache .htaccess blocks are an optimisation
			// layer; the page cache runs on the drop-in + wp-config + runtime
			// config. Only say "page cache will not work" when one of THOSE
			// failed - a refused .htaccess write must not read as a dead cache.
			$core_failed = false;
			if ( ! empty( $report['steps'] ) && is_array( $report['steps'] ) ) {
				foreach ( $report['steps'] as $step => $info ) {
					if ( is_array( $info ) && empty( $info['ok'] ) && 'write_browser_cache_rules' !== $step ) {
						$core_failed = true;
					}
				}
			}
			if ( $core_failed ) {
				echo '<p>The plugin is active in WordPress, but one or more filesystem writes failed (usually host permissions on <code>wp-content/</code> or <code>wp-config.php</code>). Page cache will not work until those writes succeed.</p>';
			} else {
				echo '<p>The page cache is working. The Browser Caching rules (Expires / GZIP / Brotli) were NOT written to <code>.htaccess</code>; your original file is unchanged. Fix the cause shown above, then re-save the Browser Caching settings to retry.</p>';
			}
		}
		// Operator UX (2026-09-01): a new user should go straight on from
		// here, not back via Installed Plugins.
		echo '<p class="cachingout-notice__actions">'
			. '<a class="rs-button rs-button--primary" href="' . esc_url( admin_url( 'admin.php?page=cachingout-setup-wizard' ) ) . '">Open Setup Wizard</a>'
			. '<a class="rs-button" href="' . esc_url( admin_url( 'admin.php?page=cachingout' ) ) . '">Settings</a>'
			. '<a class="rs-button" href="' . esc_url( $check_url ) . '">Re-run filesystem check</a>'
			. '</p>';
		echo '</div>';
	}
);

/**
 * The notices' own stylesheet (assets/css/admin-notice.css, spec 35). The
 * notices in this file print on every admin screen, where the plugin's
 * admin.css is not loaded; this 3 KB sheet carries the tokens their
 * buttons and code chips need.
 */
add_action(
	'admin_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'cachingout-admin-notice', CACHINGOUT_URL . 'assets/css/admin-notice.css', array(), CACHINGOUT_VERSION );
	}
);

/**
 * Persistent admin notice when page caching is blocked by a foreign drop-in
 * (Spec 23 Part A). Names the plugin found and explains that every other
 * module remains active. Not dismissible — the state changes only when the
 * conflict is resolved.
 */
add_action(
	'admin_notices',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$guard = CACHINGOUT_PATH . 'includes/cache/class-dropin-owner.php';
		if ( file_exists( $guard ) ) {
			require_once $guard;
		}
		if ( ! class_exists( 'CachingOut_Dropin_Owner' ) ) {
			return;
		}

		if ( 'blocked' !== \CachingOut_Dropin_Owner::get_page_cache_mode() ) {
			return;
		}

		$owner = \CachingOut_Dropin_Owner::get_detected_owner_label();
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Caching Out — page caching paused', 'cachingout' ); ?></strong>
				<br />
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: detected page-cache plugin name */
						__( 'A page-cache drop-in owned by %s was detected, so Caching Out will not overwrite it. Page caching is off, but every other module (file optimisation, media, database cleanup and CDN) stays active.', 'cachingout' ),
						'<strong>' . esc_html( $owner ) . '</strong>'
					),
					array( 'strong' => array() )
				);
				?>
			</p>
		</div>
		<?php
	}
);



