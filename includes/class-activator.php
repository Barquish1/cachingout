<?php
/**
 * Plugin activator — handles everything that must happen on plugin activation.
 *
 * Writes the advanced-cache.php drop-in, sets the WP_CACHE constant in
 * wp-config.php, removes any stale .htaccess rewrite-rules block, and schedules
 * the cache expiry cron.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin activator.
 *
 * All methods are static — this class is never instantiated. It is called once
 * from the activation hook in cachingout.php, and may also be called from
 * unit tests to verify each step independently.
 *
 * @since 0.1.0
 */
class CachingOut_Activator {

	/**
	 * Run the full activation sequence.
	 *
	 * Idempotent: running this on an already-activated install is safe. If the
	 * drop-in already exists it is overwritten; if WP_CACHE is already true it
	 * is left alone; if the .htaccess block already exists it is replaced.
	 *
	 * Aborts cleanly at each step if the filesystem is not writable — it never
	 * writes partial server config.
	 *
	 * @since 0.1.0
	 * @return true|\WP_Error True on success, WP_Error with a message on failure.
	 */
	public static function activate() {
		$report = self::activate_with_report();
		return ! empty( $report['ok'] ) ? true : new \WP_Error(
			'cachingout_activation_failed',
			isset( $report['summary'] ) ? $report['summary'] : 'Activation failed.'
		);
	}

	/**
	 * Run activation and return a structured report of every filesystem step.
	 *
	 * WordPress discards the activation-hook return value, so callers must
	 * persist this report (see cachingout.php) and show it in admin.
	 *
	 * @since 0.1.6
	 * @return array{
	 *   ok:bool,
	 *   show_notice:bool,
	 *   summary:string,
	 *   steps:array<string,array{ok:bool,message:string}>,
	 *   paths:array<string,string>
	 * }
	 */
	public static function activate_with_report(): array {
		$steps = array();
		$all_ok = true;

		// Read BEFORE cachingout.php overwrites it after this returns: it is
		// what tells activation, upgrade and a plain re-check apart (spec 26).
		$previous_version = (string) get_option( 'cachingout_installed_version', '' );

		// Spec 30 (1.21.0): the FREE edition switches on at activation. The
		// scan-first lock (spec 27) now belongs to the Pro add-on and gates
		// Pro features only; nothing here waits for a licence.
		$record = static function ( string $key, $result ) use ( &$steps, &$all_ok ) {
			if ( is_wp_error( $result ) ) {
				$steps[ $key ] = array(
					'ok'      => false,
					'message' => $result->get_error_message(),
				);
				$all_ok = false;
				return;
			}
			$steps[ $key ] = array(
				'ok'      => true,
				'message' => is_string( $result ) ? $result : 'ok',
			);
		};

		// 0. Change audit log table (spec 26). First, so the steps below can
		// be recorded on this run. The audit is a Pro subsystem since 1.21.0:
		// with Pro active the table is created here, otherwise the step
		// simply says so. Informational, never fatal.
		try {
			$audit_table = self::create_audit_table();
			if ( is_wp_error( $audit_table ) && 'cachingout_audit_unavailable' === $audit_table->get_error_code() ) {
				$record( 'create_audit_table', 'not part of the free edition' );
			} else {
				$record(
					'create_audit_table',
					is_wp_error( $audit_table ) ? 'using option fallback (' . $audit_table->get_error_message() . ')' : 'ok'
				);
			}
		} catch ( \Throwable $e ) {
			$record( 'create_audit_table', 'using option fallback (' . $e->getMessage() . ')' );
		}

		// OD-18: one-time backfill of the legacy Smart Config log. Not a
		// report step - it can never fail the activation.
		try {
			self::backfill_rules_log();
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		// The runtime snapshot BEFORE the drop-in (decision 2026-10-19): the
		// drop-in finds the plugin through the snapshot's `_plugin_dir` and has
		// no guessed fallback, so a snapshot written by an older version must
		// never meet the new drop-in, not even for the request that races this
		// upgrade. The authoritative export, with everything the steps below
		// settle, is still the last step and the one the report records.
		try {
			self::export_runtime_config();
		} catch ( \Throwable $e ) {
			unset( $e ); // The final export below reports a failure.
		}

		// 1. Drop-in.
		$record( 'write_dropin', self::write_dropin() );

		// Page-cache mode may have been set to 'blocked' by the ownership
		// gate inside write_dropin(). When blocked, never set WP_CACHE.
		self::load_dropin_owner();
		$blocked = ( 'blocked' === \CachingOut_Dropin_Owner::get_page_cache_mode() );

		// 2. WP_CACHE in wp-config.php.
		if ( $blocked ) {
			$record( 'set_wp_cache', 'skipped (foreign page-cache drop-in detected)' );
		} else {
			$record( 'set_wp_cache', self::set_wp_cache_constant( true ) );
		}

		// 3. Stale rewrite block cleanup (non-fatal).
		$ht_rm = self::remove_htaccess_rules();
		$record( 'remove_stale_rewrite', is_wp_error( $ht_rm ) ? $ht_rm : 'ok' );

		// 4. Cron (non-fatal).
		try {
			self::schedule_expiry_cron();
			$record( 'schedule_expiry_cron', 'ok' );
		} catch ( \Throwable $e ) {
			$record( 'schedule_expiry_cron', new \WP_Error( 'cron', $e->getMessage() ) );
		}

		// 5. Defaults + runtime config — BEFORE the browser-cache write so a
		// fresh install's fast defaults (OD-4, 0.1.17) are persisted and the
		// write below reads them rather than an empty option.
		try {
			self::save_default_settings();
			$record( 'save_default_settings', 'ok' );
		} catch ( \Throwable $e ) {
			$record( 'save_default_settings', new \WP_Error( 'settings', $e->getMessage() ) );
		}

		// 6. Browser-cache .htaccess blocks — written on activation since
		// 0.1.17 (OD-4 fast-by-default) through the safe writer BUG-014
		// built: sanity-checked output, foreign-marker guard, atomic replace.
		// Deactivation still never touches .htaccess (BUG-010 stands).
		// Non-fatal: a refused write surfaces in the activation report and
		// the plugin still works via the drop-in page cache.
		try {
			$ht_bc = self::write_browser_cache_rules();
			$record( 'write_browser_cache_rules', is_wp_error( $ht_bc ) ? $ht_bc : 'ok' );
		} catch ( \Throwable $e ) {
			$record( 'write_browser_cache_rules', new \WP_Error( 'browser_cache', $e->getMessage() ) );
		}

		// BUG-013: purge any stale/broken page-cache HTML from prior alphas.
		try {
			if ( ! class_exists( 'CachingOut_Page_Cache' ) ) {
				require_once CACHINGOUT_PATH . 'includes/cache/class-page-cache.php';
			}
			if ( ! class_exists( 'CachingOut_Settings' ) ) {
				require_once CACHINGOUT_PATH . 'includes/class-settings.php';
			}
			$pc = new \CachingOut_Page_Cache( new \CachingOut_Settings() );
			$pc->purge_all();
			$record( 'purge_stale_cache', 'ok' );
		} catch ( \Throwable $e ) {
			$record( 'purge_stale_cache', new \WP_Error( 'purge', $e->getMessage() ) );
		}


		try {
			self::export_runtime_config();
			$runtime_path = CachingOut_Settings::runtime_config_path();
			if ( file_exists( $runtime_path ) ) {
				$record( 'export_runtime_config', 'ok' );
			} else {
				$record(
					'export_runtime_config',
					new \WP_Error(
						'runtime_missing',
						'runtime-config.php was not created at ' . $runtime_path
					)
				);
			}
		} catch ( \Throwable $e ) {
			$record( 'export_runtime_config', new \WP_Error( 'runtime', $e->getMessage() ) );
		}

		/**
		 * Fires at the end of the activation report, before the wizard
		 * redirect is set. The Pro add-on runs its own activation steps here
		 * (licence cron, its state option) and may record them.
		 *
		 * @since 1.21.0
		 *
		 * @param callable $record    function( string $key, true|string|WP_Error $result ): void.
		 * @param string   $previous  Installed version before this run ('' on first activation).
		 */
		do_action( 'cachingout_activation_steps', $record, $previous_version );

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		if ( class_exists( 'CachingOut_Setup_Wizard' ) ) {
			CachingOut_Setup_Wizard::set_activation_redirect();
		}

		$dropin = WP_CONTENT_DIR . '/advanced-cache.php';
		$config = self::get_wp_config_path();
		$paths  = array(
			'advanced-cache.php' => $dropin,
			'wp-config.php'      => $config ? $config : '(not found)',
			'runtime-config.php' => CachingOut_Settings::runtime_config_path(),
			'cache dir'          => CachingOut_Settings::cache_dir(),
			'.htaccess'          => self::htaccess_path(),
		);

		$summary = $all_ok
			? 'All activation writes succeeded.'
			: 'One or more activation writes failed. See steps.';

		self::record_activation_audit( $previous_version, $steps, $all_ok );

		return array(
			'ok'          => $all_ok,
			'show_notice' => true,
			'summary'     => $summary,
			'steps'       => $steps,
			'paths'       => $paths,
			'version'     => defined( 'CACHINGOUT_VERSION' ) ? CACHINGOUT_VERSION : '',
			'time'        => gmdate( 'c' ),
		);
	}


	/**
	 * Network activation (spec 28 Phase 2): run the activation report on
	 * EVERY site in the network under switch_to_blog(), so each site gets
	 * its own defaults, state option (a fresh site starts locked, spec 27),
	 * audit table, runtime snapshot and report. The shared artefacts
	 * (drop-in, WP_CACHE, .htaccess) are idempotent, so writing them once
	 * per site is harmless. The audit default instance is dropped before
	 * each site so its rows land on that site's table, not the first one's.
	 *
	 * Called from the activation hook when `$network_wide` is true; safe to
	 * call on single site (returns an empty array).
	 *
	 * @since 0.1.20
	 * @return array<int,array> Per-site reports keyed by blog id.
	 */
	public static function activate_network(): array {
		$reports = array();
		if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
			return $reports;
		}

		$has_audit = class_exists( 'CachingOut_Audit' );

		foreach ( get_sites( array( 'number' => 0, 'fields' => 'ids' ) ) as $blog_id ) {
			$blog_id = (int) $blog_id;
			switch_to_blog( $blog_id );
			try {
				if ( $has_audit ) {
					\CachingOut_Audit::set_default( null );
				}
				$report                = self::activate_with_report();
				$report['show_notice'] = true;
				update_option( 'cachingout_activation_report', $report, false );
				if ( defined( 'CACHINGOUT_VERSION' ) ) {
					update_option( 'cachingout_installed_version', CACHINGOUT_VERSION, false );
				}
				$reports[ $blog_id ] = $report;
			} catch ( \Throwable $e ) {
				$reports[ $blog_id ] = array(
					'ok'          => false,
					'show_notice' => true,
					'summary'     => $e->getMessage(),
					'steps'       => array(),
					'paths'       => array(),
				);
			} finally {
				restore_current_blog();
			}
		}

		if ( $has_audit ) {
			// Forget the last site's instance; the next default_instance()
			// call rebuilds for the restored site.
			\CachingOut_Audit::set_default( null );
		}

		return $reports;
	}

	/**
	 * The change record API, when Caching Out Pro has loaded it.
	 *
	 * The audit classes ship with Pro (spec 30). The free edition never
	 * requires them by path: when they are not loaded, every audit helper
	 * in this class is a no-op and the steps still run.
	 *
	 * @since 0.1.18
	 * @return \CachingOut_Audit|null Null when the classes are unavailable.
	 */
	private static function audit(): ?\CachingOut_Audit {
		if ( ! class_exists( 'CachingOut_Audit_Store' ) || ! class_exists( 'CachingOut_Audit' ) ) {
			return null;
		}
		try {
			if ( ! class_exists( 'CachingOut_Settings' ) ) {
				require_once CACHINGOUT_PATH . 'includes/class-settings.php';
			}
			return \CachingOut_Audit::default_instance();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Create (or upgrade) the audit table. Runs on the activation hook AND
	 * the version-change re-check path, so an in-place zip upgrade gets the
	 * table without a reactivation.
	 *
	 * @since 0.1.18
	 * @return true|\WP_Error
	 */
	public static function create_audit_table() {
		$audit = self::audit();
		if ( null === $audit ) {
			return new \WP_Error( 'cachingout_audit_unavailable', 'Audit classes could not be loaded.' );
		}
		return $audit->store()->create_table();
	}

	/**
	 * Option that marks the OD-18 backfill as done (holds the timestamp).
	 *
	 * @since 0.1.18
	 * @var string
	 */
	const AUDIT_BACKFILL_OPTION = 'cachingout_audit_backfilled';

	/**
	 * OD-18: once per install, copy the legacy `cachingout_ai_rules_log`
	 * entries into the audit store as autoconfig.apply rows. Re-running is
	 * a no-op (the marker option gates it). The option is left in place:
	 * Telemetry_Hooks still reads it until 0.1.19.
	 *
	 * @since 0.1.18
	 * @return int Rows written (0 when already done or nothing to copy).
	 */
	public static function backfill_rules_log(): int {
		if ( get_option( self::AUDIT_BACKFILL_OPTION ) ) {
			return 0;
		}

		$audit = self::audit();
		if ( null === $audit || ! class_exists( 'CachingOut_Audit_Listeners' ) ) {
			return 0;
		}

		$raw = get_option( 'cachingout_ai_rules_log', '' );
		$log = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();

		$listeners = new \CachingOut_Audit_Listeners( $audit );
		$written   = is_array( $log ) ? $listeners->backfill_rules_log( $log ) : 0;

		update_option( self::AUDIT_BACKFILL_OPTION, time(), false );

		return $written;
	}

	/**
	 * Log this run as `activation`, `upgrade` (from -> to) or
	 * `filesystem_check.rerun`, plus one row per failed step. Written here,
	 * never from cachingout.php (which must never fatal). Never throws.
	 *
	 * @since 0.1.18
	 *
	 * @param string $previous_version Installed version before this run ('' on first activation).
	 * @param array  $steps            Step results.
	 * @param bool   $all_ok           Whether every step succeeded.
	 * @return void
	 */
	private static function record_activation_audit( string $previous_version, array $steps, bool $all_ok ): void {
		try {
			$audit = self::audit();
			if ( null === $audit ) {
				return;
			}
			$current = defined( 'CACHINGOUT_VERSION' ) ? (string) CACHINGOUT_VERSION : '';
			if ( '' === $previous_version ) {
				$type = 'activation';
			} elseif ( $previous_version !== $current ) {
				$type = 'upgrade';
			} else {
				$type = 'filesystem_check.rerun';
			}

			$audit->record(
				array(
					'type'    => $type,
					'source'  => 'activator',
					'target'  => 'plugin',
					'before'  => 'upgrade' === $type ? $previous_version : null,
					'after'   => $current,
					'result'  => $all_ok ? 'ok' : 'error',
					'message' => $all_ok ? '' : 'one or more activation steps failed',
				)
			);

			foreach ( $steps as $key => $info ) {
				if ( is_array( $info ) && empty( $info['ok'] ) ) {
					$audit->record(
						array(
							'type'    => $type,
							'source'  => 'activator',
							'target'  => 'step.' . (string) $key,
							'result'  => 'error',
							'message' => isset( $info['message'] ) ? (string) $info['message'] : '',
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Persist the default settings to the database on first activation.
	 *
	 * Uses update_option with the $autoload parameter set to null so WordPress
	 * uses its default autoload behaviour. If the option already exists (e.g.
	 * reactivation), it is left untouched — defaults only apply on a clean
	 * install.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function save_default_settings() {
		if ( ! class_exists( 'CachingOut_Settings' ) ) {
			require_once CACHINGOUT_PATH . 'includes/class-settings.php';
		}

		if ( false !== get_option( \CachingOut_Settings::OPTION_KEY ) ) {
			// Settings already exist — do not overwrite.
			return;
		}

		$defaults = \CachingOut_Settings::DEFAULTS;
		$defaults['_version'] = \CachingOut_Settings::SCHEMA_VERSION;

		add_option( \CachingOut_Settings::OPTION_KEY, $defaults );
	}

	/**
	 * Export the runtime settings snapshot for the advanced-cache drop-in.
	 *
	 * The drop-in runs before the database is available, so it reads a PHP
	 * settings snapshot from the cache root instead of calling get_option().
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function export_runtime_config(): void {
		if ( ! class_exists( 'CachingOut_Settings' ) ) {
			require_once CACHINGOUT_PATH . 'includes/class-settings.php';
		}

		$settings = new \CachingOut_Settings();
		$settings->export_runtime_config();
	}

	/**
	 * Copy the pre-written advanced-cache.php drop-in into WP_CONTENT_DIR.
	 *
	 * The source file lives at config/advanced-cache.php within the plugin.
	 * It is copied verbatim to wp-content/advanced-cache.php.
	 *
	 * @since 0.1.0
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function write_dropin() {
		$result = self::do_write_dropin();
		self::audit_dropin( $result );
		return $result;
	}

	/**
	 * Log a drop-in write as dropin.install / dropin.refused (spec 26).
	 *
	 * @since 0.1.18
	 *
	 * @param true|\WP_Error $result Result of do_write_dropin().
	 * @return void
	 */
	private static function audit_dropin( $result ): void {
		try {
			$audit = self::audit();
			if ( null === $audit ) {
				return;
			}
			if ( is_wp_error( $result ) ) {
				$refused = ( 'cachingout_dropin_foreign' === $result->get_error_code() );
				$audit->record(
					array(
						'type'    => $refused ? 'dropin.refused' : 'dropin.install',
						'source'  => 'activator',
						'target'  => 'advanced-cache.php',
						'result'  => $refused ? 'refused' : 'error',
						'message' => $result->get_error_code() . ': ' . $result->get_error_message(),
					)
				);
				return;
			}
			$audit->record(
				array(
					'type'   => 'dropin.install',
					'source' => 'activator',
					'target' => 'advanced-cache.php',
					'after'  => defined( 'CACHINGOUT_VERSION' ) ? (string) CACHINGOUT_VERSION : '',
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Copy the drop-in (unlogged body of write_dropin()).
	 *
	 * @since 0.1.18
	 * @return true|\WP_Error
	 */
	private static function do_write_dropin() {
		self::load_dropin_owner();

		$source      = CACHINGOUT_PATH . 'config/advanced-cache.php';
		$destination = \CachingOut_Dropin_Owner::dropin_path();

		if ( ! file_exists( $source ) ) {
			return new \WP_Error(
				'cachingout_missing_source',
				__( 'The advanced-cache.php template is missing from the plugin config directory.', 'cachingout' )
			);
		}

		// Ownership gate: never write over a drop-in we do not own.
		if ( file_exists( $destination ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$existing = @file_get_contents( $destination );
			$owner    = ( false === $existing )
				? 'foreign'
				: \CachingOut_Dropin_Owner::detect( $existing );

			if ( 'cachingout' !== $owner ) {
				\CachingOut_Dropin_Owner::set_page_cache_mode( 'blocked' );
				\CachingOut_Dropin_Owner::set_detected_owner( $owner );

				return new \WP_Error(
					'cachingout_dropin_foreign',
					sprintf(
						/* translators: %s: detected drop-in owner slug */
						__( 'An existing page-cache drop-in was detected (%s). Caching Out will not overwrite it. Page caching is blocked; all other modules remain active.', 'cachingout' ),
						$owner
					)
				);
			}
		}

		$content = file_get_contents( $source );
		if ( false === $content ) {
			return new \WP_Error(
				'cachingout_read_failed',
				__( 'Could not read the advanced-cache.php template.', 'cachingout' )
			);
		}

		// Write atomically: temp file, then rename.
		$tmp = $destination . '.' . uniqid( 'tmp', true ) . '.php';
		if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
			return new \WP_Error(
				'cachingout_write_failed',
				sprintf(
					/* translators: %s: destination file path */
					__( 'Could not write the advanced-cache.php drop-in to %s. Check filesystem permissions.', 'cachingout' ),
					$destination
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace: temp file in the same directory, then rename(). WP_Filesystem::move() is not atomic.
		if ( ! @rename( $tmp, $destination ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error(
				'cachingout_rename_failed',
				sprintf(
					/* translators: %s: destination file path */
					__( 'Could not finalize the advanced-cache.php drop-in at %s.', 'cachingout' ),
					$destination
				)
			);
		}

		// Stamp the written content and mark the page cache as owned.
		\CachingOut_Dropin_Owner::stamp( $content );
		\CachingOut_Dropin_Owner::set_page_cache_mode( 'owned' );

		return true;
	}

	/**
	 * Load the drop-in ownership guard class if it is not already loaded.
	 *
	 * @since 0.1.14
	 *
	 * @return void
	 */
	private static function load_dropin_owner(): void {
		if ( class_exists( 'CachingOut_Dropin_Owner' ) ) {
			return;
		}

		require_once CACHINGOUT_PATH . 'includes/cache/class-dropin-owner.php';
	}

	/**
	 * Set or remove the WP_CACHE constant in wp-config.php.
	 *
	 * When $enable is true, the constant is added with the value true.
	 * When false, it is removed. The operation is skipped if the constant
	 * is already in the desired state.
	 *
	 * Backs up wp-config.php before modifying it. Aborts cleanly if the
	 * file is not writable.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $enable Whether to add (true) or remove (false) the constant.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public static function set_wp_cache_constant( bool $enable ) {
		$result = self::write_wp_cache_constant( $enable );
		if ( null === $result ) {
			// Already in the desired state: nothing was written, nothing to log.
			return true;
		}
		self::audit_wpconfig( $enable, $result );
		return $result;
	}

	/**
	 * Log a wp-config.php write as wpconfig.write (spec 26). Never revertible:
	 * the constant is restored by deactivating / reactivating, not by a save.
	 *
	 * @since 0.1.18
	 *
	 * @param bool           $enable Whether WP_CACHE was being added or removed.
	 * @param true|\WP_Error $result Result of write_wp_cache_constant().
	 * @return void
	 */
	private static function audit_wpconfig( bool $enable, $result ): void {
		try {
			$audit = self::audit();
			if ( null === $audit ) {
				return;
			}
			$event = array(
				'type'   => 'wpconfig.write',
				'source' => 'activator',
				'target' => 'wp-config.php',
				'before' => $enable ? 'WP_CACHE absent' : 'WP_CACHE true',
				'after'  => $enable ? 'WP_CACHE true' : 'WP_CACHE absent',
			);
			if ( is_wp_error( $result ) ) {
				$event['result']  = 'error';
				$event['message'] = $result->get_error_code() . ': ' . $result->get_error_message();
				unset( $event['after'] );
			}
			$audit->record( $event );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The wp-config.php write behind set_wp_cache_constant().
	 *
	 * @since 0.1.18
	 *
	 * @param bool $enable Whether to add (true) or remove (false) the constant.
	 * @return true|\WP_Error|null True on success, WP_Error on failure, null
	 *                             when the constant was already in the desired
	 *                             state and nothing was written.
	 */
	private static function write_wp_cache_constant( bool $enable ) {
		$config_path = self::get_wp_config_path();

		if ( null === $config_path || ! file_exists( $config_path ) ) {
			return new \WP_Error(
				'cachingout_no_wp_config',
				__( 'Could not locate wp-config.php.', 'cachingout' )
			);
		}

		if ( ! wp_is_writable( $config_path ) ) {
			return new \WP_Error(
				'cachingout_wp_config_not_writable',
				sprintf(
					/* translators: 1: wp-config.php path, 2: WP_CACHE constant definition */
					__( 'wp-config.php (%1$s) is not writable. Please add %2$s manually.', 'cachingout' ),
					$config_path,
					$enable ? "define( 'WP_CACHE', true );" : "// Remove: define( 'WP_CACHE', true );"
				)
			);
		}

		$content = file_get_contents( $config_path );
		if ( false === $content ) {
			return new \WP_Error(
				'cachingout_wp_config_read_failed',
				__( 'Could not read wp-config.php.', 'cachingout' )
			);
		}

		// No backup copy on disk (1.21.16, DECISIONS 2026-10-19): a copy of
		// wp-config.php beside it is a credentials file wherever dot-files
		// are served. The original content stays in $content until the new
		// file is verified; replace_file_atomically() puts it back if the
		// write fails.

		// Detect if WP_CACHE is currently defined.
		$has_wp_cache = (bool) preg_match( "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,/", $content );

		if ( $enable && ! $has_wp_cache ) {
			// Add the constant after the opening <?php or after the first comment block.
			$pattern = '/(<\?php\s*(?:\/\/[^\n]*\n|\/\*.*?\*\/\s*|#[^\n]*\n|\s)*)/s';
			$result  = preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );
			if ( $result && isset( $matches[0] ) ) {
				$insert_pos  = $matches[0][1] + strlen( $matches[0][0] );
				$new_content = substr( $content, 0, $insert_pos )
					. "\n/** Enable WP Caching Out page cache. */\ndefine( 'WP_CACHE', true );\n"
					. substr( $content, $insert_pos );
			} else {
				// Fallback: prepend after <?php.
				$new_content = preg_replace(
					'/<\?php\s*/',
					"<?php\n/** Enable WP Caching Out page cache. */\ndefine( 'WP_CACHE', true );\n",
					$content,
					1
				);
			}
		} elseif ( ! $enable && $has_wp_cache ) {
			// Remove ONLY our marker comment + the WP_CACHE define on the next
			// line, then any remaining bare WP_CACHE define.
			//
			// BUG-008: the previous pattern used `/s` with `.*?WP Caching Out`
			// which let the engine start at the FIRST `/*` in the file (the
			// stock WordPress file header) and span across every comment and
			// define until our marker. On a typical wp-config.php that deleted
			// the file header; if WP_CACHE sat lower in the file it could wipe
			// DB credentials and leave a site that fatals on every request.
			// Markers are line-scoped — same defence as the .htaccess fix.
			$new_content = self::strip_wp_cache_constant( $content );
			if ( null === $new_content ) {
				return new \WP_Error(
					'cachingout_wp_config_strip_failed',
					__( 'Could not safely remove WP_CACHE from wp-config.php. File left unchanged.', 'cachingout' )
				);
			}
		} else {
			// Already in the desired state: no change needed.
			return null;
		}

		if ( ! is_string( $new_content ) || '' === $new_content ) {
			return new \WP_Error(
				'cachingout_wp_config_invalid_output',
				__( 'Refused to write wp-config.php: the result was empty. Your original file is unchanged.', 'cachingout' )
			);
		}

		// Refuse to write a gutted config.
		if ( ! self::wp_config_content_is_sane( $content, $new_content ) ) {
			return new \WP_Error(
				'cachingout_wp_config_invalid_output',
				__( 'Refused to write wp-config.php: the result failed the sanity check. Your original file is unchanged.', 'cachingout' )
			);
		}

		$written = self::replace_file_atomically( $config_path, $new_content, $content );
		if ( is_wp_error( $written ) ) {
			return new \WP_Error(
				'cachingout_wp_config_write_failed',
				sprintf(
					/* translators: 1: the writer's error message, 2: the WP_CACHE constant definition */
					__( 'Could not write to wp-config.php (%1$s). The file is unchanged; add or remove %2$s by hand.', 'cachingout' ),
					$written->get_error_message(),
					"define( 'WP_CACHE', true );"
				)
			);
		}

		return true;
	}

	/**
	 * Replace a file's content in one step, with no backup copy on disk.
	 *
	 * The new content goes to a temporary file in the same directory, is
	 * read back and compared, then rename()d over the original: the
	 * original is replaced whole or not at all, the guarantee a backup
	 * copy used to give (BUG-008, BUG-014) without a credentials file
	 * beside wp-config.php (WordPress.org review round 1, DECISIONS
	 * 2026-10-19). Where rename() cannot replace an existing file (some
	 * Windows hosts) the verified content is written directly and the
	 * original is written back if that fails.
	 *
	 * @since 1.21.16
	 *
	 * @param string $path     File to replace.
	 * @param string $content  New content.
	 * @param string $original The content the file holds now, written back
	 *                         if a direct write fails.
	 * @return true|\WP_Error
	 */
	private static function replace_file_atomically( string $path, string $content, string $original ) {
		$tmp = dirname( $path ) . '/.' . basename( $path ) . '.cachingout-' . bin2hex( random_bytes( 6 ) ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Server-config writer: the temp file beside the file being replaced, then an atomic rename(). WP_Filesystem has no atomic replace.
		if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the plugin's own temp file after a failed write.
			@unlink( $tmp );
			return new \WP_Error( 'cachingout_temp_write_failed', __( 'the temporary file could not be written', 'cachingout' ) );
		}
		if ( (string) @file_get_contents( $tmp ) !== $content ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the plugin's own temp file after a failed verification.
			@unlink( $tmp );
			return new \WP_Error( 'cachingout_temp_verify_failed', __( 'the temporary file did not read back as written', 'cachingout' ) );
		}

		$perms = @fileperms( $path );
		if ( false !== $perms ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Keeps the replaced file's own permission bits.
			@chmod( $tmp, $perms & 0777 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace: temp file in the same directory, then rename(). WP_Filesystem::move() is not atomic.
		if ( @rename( $tmp, $path ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the plugin's own temp file after a failed rename.
		@unlink( $tmp );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Fallback where rename() cannot replace an existing file; the content was verified above.
		if ( false !== @file_put_contents( $path, $content, LOCK_EX ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Puts the original content back after a failed direct write.
		@file_put_contents( $path, $original, LOCK_EX );
		return new \WP_Error( 'cachingout_replace_failed', __( 'the file could not be replaced', 'cachingout' ) );
	}

	/**
	 * Strip the WP_CACHE constant (and our marker comment) from wp-config content.
	 *
	 * Line-scoped only. Never uses the `/s` dotall flag across comment bodies.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content Full wp-config.php contents.
	 * @return string|null Updated contents, or null if a regex failed.
	 */
	public static function strip_wp_cache_constant( string $content ) {
		// Pure line walk — no multi-line regex. A line is dropped only when
		// the ENTIRE line (trimmed) is our marker comment or a WP_CACHE
		// define. Nothing else can be deleted. Used by uninstall / tools;
		// deactivate deliberately does not call this (BUG-008).
		$eol = ( false !== strpos( $content, "\r\n" ) ) ? "\r\n" : "\n";
		$lines = preg_split( '/\r\n|\n|\r/', $content );
		if ( ! is_array( $lines ) ) {
			return null;
		}

		$out = array();
		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// Our one-line marker comment only (entire line).
			if ( preg_match( '/^\/\*\*?[^*]*WP Caching Out[^*]*\*\/$/', $trimmed ) ) {
				continue;
			}

			// Entire line is define( 'WP_CACHE', true|false );
			if ( preg_match( '/^define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*(?:true|false)\s*\)\s*;$/', $trimmed ) ) {
				continue;
			}

			$out[] = $line;
		}

		return implode( $eol, $out );
	}


	/**
	 * Sanity-check wp-config.php content before it is written to disk.
	 *
	 * A bad write here is a full outage: every request fatals before WordPress
	 * boots, including wp-admin and recovery mode in the worst cases. BUG-008
	 * was exactly that class of failure on deactivate.
	 *
	 * Compares against the pre-change content so we never drop DB credentials
	 * or the wp-settings bootstrap line that were present before we touched
	 * the file.
	 *
	 * @since 0.1.4
	 *
	 * @param string $before Content before our edit.
	 * @param string $after  Candidate content to write.
	 * @return bool True when the content is safe to write.
	 */
	public static function wp_config_content_is_sane( string $before, string $after ): bool {
		if ( '' === trim( $after ) ) {
			return false;
		}

		// Must still look like a PHP entry point.
		if ( ! preg_match( '/<\?php\b/', $after ) ) {
			return false;
		}

		// Never drop the settings bootstrap if it was there.
		if ( false !== strpos( $before, 'wp-settings.php' )
			&& false === strpos( $after, 'wp-settings.php' )
		) {
			return false;
		}

		// Never drop DB credentials if they were present.
		foreach ( array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ) as $constant ) {
			$pattern = "/define\s*\(\s*['\"]" . preg_quote( $constant, '/' ) . "['\"]\s*,/";
			if ( preg_match( $pattern, $before ) && ! preg_match( $pattern, $after ) ) {
				return false;
			}
		}

		// Refuse pathological shrinkage (e.g. a greedy regex wiped the body).
		// Removing our two-line marker should cost well under 1 KB; anything
		// over 20% of a normal wp-config is a bug, not a cleanup.
		$before_len = strlen( $before );
		$after_len  = strlen( $after );
		if ( $before_len > 200 && $after_len < (int) ( $before_len * 0.8 ) ) {
			return false;
		}

		return true;
	}




	/**
	 * The root .htaccess: the one WordPress itself writes rewrite rules to.
	 *
	 * Core's get_home_path() (wp-admin/includes/file.php; its permalink
	 * writer uses it) resolves the site root when WordPress is installed
	 * in a subdirectory of it; ABSPATH is the fallback when the admin
	 * include is unavailable.
	 *
	 * @since 1.21.16
	 * @return string Absolute path of the root .htaccess.
	 */
	public static function htaccess_path(): string {
		if ( ! function_exists( 'get_home_path' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$root = function_exists( 'get_home_path' ) ? (string) get_home_path() : '';
		if ( '' === $root || ! is_dir( $root ) ) {
			$root = ABSPATH;
		}
		return rtrim( $root, '/\\' ) . '/.htaccess';
	}

	/**
	 * Remove the Apache rewrite-rules block from .htaccess.
	 *
	 * Used by the deactivator. Does not error if the block doesn't exist
	 * or the file isn't writable — silently returns success.
	 *
	 * @since 0.1.0
	 * @return true|\WP_Error True on success or if nothing to do, WP_Error only on critical failure.
	 */
	public static function remove_htaccess_rules() {
		$htaccess_path = self::htaccess_path();

		if ( ! file_exists( $htaccess_path ) || ! is_readable( $htaccess_path ) ) {
			return true;
		}

		if ( ! wp_is_writable( $htaccess_path ) ) {
			return true; // Not critical — skip silently.
		}

		$content = file_get_contents( $htaccess_path );
		if ( false === $content ) {
			return true;
		}

		// Check if our block exists.
		if ( false === strpos( $content, '# BEGIN WP Caching Out' ) ) {
			return true; // Nothing to remove.
		}

		$original = $content;

		// Remove our block. Markers anchored to end-of-line so that this
		// pattern cannot partially match the longer browser-cache markers
		// ("... Out Expires" / "... Out GZIP" / "... Out Brotli") and leave
		// an orphaned suffix word behind, which Apache rejects with a 500.
		$content = preg_replace(
			'/(?:\r\n|\n|\r)?[ \t]*#[ \t]*BEGIN[ \t]+WP[ \t]+Caching[ \t]+Out[ \t]*(?:\r\n|\n|\r).*?#[ \t]*END[ \t]+WP[ \t]+Caching[ \t]+Out[ \t]*(?:(?:\r\n|\n|\r)|$)/s',
			"\n",
			$content
		);

		// Also clean up any double-newlines left behind.
		$content = preg_replace( "/\n{3,}/", "\n\n", $content );

		// Refuse to write config that would take Apache down. Deactivation is
		// exactly where BUG-003 struck, so leave the file untouched rather
		// than risk a 500 on a site we are leaving.
		if ( ! self::htaccess_content_is_sane( $content ) ) {
			return new \WP_Error(
				'cachingout_htaccess_invalid_output',
				__( 'Refused to write .htaccess: the result contained an invalid directive. Your original file is unchanged.', 'cachingout' )
			);
		}

		$written = self::replace_file_atomically( $htaccess_path, $content, $original );
		if ( is_wp_error( $written ) ) {
			return new \WP_Error(
				'cachingout_htaccess_remove_failed',
				__( 'Could not remove the rewrite rules from .htaccess; the file is unchanged. Remove the "# BEGIN WP Caching Out" block by hand.', 'cachingout' )
			);
		}

		return true;
	}

	/**
	 * Remove the advanced-cache.php drop-in from WP_CONTENT_DIR.
	 *
	 * Only removes the file if it is ours (on-disk SHA-256 matches the
	 * stored `cachingout_dropin_hash`). Used by the deactivator.
	 *
	 * @since 0.1.0
	 * @return true|\WP_Error True on success or if nothing to do.
	 */
	public static function remove_dropin() {
		self::load_dropin_owner();

		$path = \CachingOut_Dropin_Owner::dropin_path();

		if ( ! file_exists( $path ) ) {
			return true;
		}

		// Fail closed: never remove a drop-in whose hash does not match ours.
		if ( ! \CachingOut_Dropin_Owner::is_ours() ) {
			if ( function_exists( 'error_log' ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Reports an operational failure to the site's error log; not debug output.
				error_log( 'Caching Out: advanced-cache.php hash mismatch; left in place.' );
			}
			return true;
		}

		if ( ! wp_is_writable( $path ) && ! wp_is_writable( dirname( $path ) ) ) {
			return new \WP_Error(
				'cachingout_dropin_not_writable',
				__( 'Could not remove advanced-cache.php. Please delete it manually.', 'cachingout' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes the plugin's own advanced-cache.php drop-in, and only after its hash matched ours. The result is reported to the caller.
		if ( ! @unlink( $path ) ) {
			return new \WP_Error(
				'cachingout_dropin_remove_failed',
				__( 'Could not remove advanced-cache.php. Please delete it manually.', 'cachingout' )
			);
		}

		delete_option( 'cachingout_dropin_hash' );

		return true;
	}

	/**
	 * Schedule the cache expiry cron event.
	 *
	 * Loads the Expiry class and calls its schedule() method.
	 * Safe to call even if the Expiry class is not yet loaded.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function schedule_expiry_cron(): void {
		if ( ! class_exists( 'CachingOut_Settings' ) ) {
			require_once CACHINGOUT_PATH . 'includes/class-settings.php';
		}

		if ( ! class_exists( 'CachingOut_Expiry' ) ) {
			require_once CACHINGOUT_PATH . 'includes/cache/class-expiry.php';
		}

		if ( ! class_exists( 'CachingOut_Page_Cache' ) ) {
			require_once CACHINGOUT_PATH . 'includes/cache/class-page-cache.php';
		}

		if ( class_exists( 'CachingOut_Expiry' ) ) {
			$settings = new CachingOut_Settings();
			$expiry   = new CachingOut_Expiry( $settings );
			$expiry->schedule();
		}
	}

	/**
	 * Sanity-check .htaccess content before it is written to disk.
	 *
	 * Apache refuses to serve a directory whose .htaccess contains a single
	 * unknown token, and it fails the ENTIRE site with HTTP 500 - including
	 * wp-admin and wp-login.php - with no PHP error logged anywhere. A bad
	 * write here is therefore a full outage with no self-service recovery
	 * path, which is exactly what BUG-003 caused on a live site.
	 *
	 * This is a deliberately narrow guard: it rejects a bare single-word line
	 * (e.g. an orphaned "Expires" left by a faulty marker strip). Every real
	 * Apache directive we emit carries at least one argument, and comments,
	 * block tags and blank lines are always allowed.
	 *
	 * @since 0.1.3
	 *
	 * @param string $content Candidate .htaccess content.
	 * @return bool True when the content is safe to write.
	 */
	public static function htaccess_content_is_sane( string $content ): bool {
		// BUG-019: split on real line endings only. PCRE's \R without the /u
		// flag also matches the single byte 0x85 (NEL), which is the second
		// byte of UTF-8 letters such as Polish "a-ogonek" (C4 85) - WordPress's
		// translated .htaccess comment ("...beda nadpisywane.") was split
		// mid-word and its tail rejected as an orphan directive, so every
		// Polish site refused the browser-cache write (orgahair.pl, 2026-09-01).
		foreach ( preg_split( '/(?:\r\n|\n|\r)/', $content ) as $line ) {
			$trimmed = trim( $line );

			// Blank lines, comments and block tags are always fine.
			if ( '' === $trimmed
				|| '#' === $trimmed[0]
				|| '<' === $trimmed[0]
			) {
				continue;
			}

			// A directive with no argument is almost certainly an orphaned
			// marker suffix rather than valid Apache config.
			if ( ! preg_match( '/\s/', $trimmed ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Write or remove a marker-delimited block in .htaccess.
	 *
	 * A generic helper used both by the rewrite-rules block (get_rewrite_rules)
	 * and the browser-cache blocks (expires, gzip, brotli). When $rules is
	 * passed, the block is inserted or replaced; when null, the block is
	 * removed.
	 *
	 * Every write is an atomic replace (replace_file_atomically()): the file
	 * is swapped whole or left as it was; no backup copy is written to disk.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $marker  Block marker name (e.g. 'WP Caching Out').
	 *                             Must match the text inside BEGIN/END comments.
	 * @param string|null $rules   Rules content to insert (with markers), or
	 *                             null to remove the block entirely.
	 * @return true|\WP_Error True on success.
	 */
	public static function update_htaccess_block( string $marker, ?string $rules ) {
		$path        = self::htaccess_path();
		$hash_before = self::htaccess_hash( $path );
		$block_before = self::htaccess_block( $path, $marker );

		$result = self::do_update_htaccess_block( $marker, $rules );

		self::audit_htaccess( $marker, $rules, $result, $hash_before, $block_before );
		return $result;
	}

	/**
	 * Log an .htaccess block change as htaccess.write / .remove / .refused
	 * (spec 26). A successful call that changed nothing on disk logs nothing.
	 *
	 * @since 0.1.18
	 *
	 * @param string         $marker       Block marker.
	 * @param string|null    $rules        Rules written, null when removing.
	 * @param true|\WP_Error $result       Result of do_update_htaccess_block().
	 * @param string         $hash_before  File hash before ('' when absent).
	 * @param string|null    $block_before Block text before, null when absent.
	 * @return void
	 */
	private static function audit_htaccess( string $marker, ?string $rules, $result, string $hash_before, ?string $block_before ): void {
		try {
			$audit = self::audit();
			if ( null === $audit ) {
				return;
			}
			if ( is_wp_error( $result ) ) {
				$audit->record(
					array(
						'type'    => 'htaccess.refused',
						'source'  => 'htaccess',
						'target'  => $marker,
						'result'  => 'refused',
						'message' => $result->get_error_code() . ': ' . $result->get_error_message(),
					)
				);
				return;
			}
			$path = self::htaccess_path();
			if ( self::htaccess_hash( $path ) === $hash_before ) {
				return;
			}
			$audit->record(
				array(
					'type'   => null === $rules ? 'htaccess.remove' : 'htaccess.write',
					'source' => 'htaccess',
					'target' => $marker,
					'before' => $block_before,
					'after'  => self::htaccess_block( $path, $marker ),
				)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Content hash of a file, '' when unreadable or absent.
	 *
	 * @since 0.1.18
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function htaccess_hash( string $path ): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$hash = is_readable( $path ) ? @md5_file( $path ) : false;
		return is_string( $hash ) ? $hash : '';
	}

	/**
	 * The BEGIN/END block for a marker, null when absent.
	 *
	 * @since 0.1.18
	 *
	 * @param string $path   File path.
	 * @param string $marker Block marker.
	 * @return string|null
	 */
	private static function htaccess_block( string $path, string $marker ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$content = @file_get_contents( $path );
		if ( ! is_string( $content ) ) {
			return null;
		}
		$quoted = preg_quote( $marker, '/' );
		if ( preg_match( '/^[ \t]*# BEGIN ' . $quoted . '[ \t]*\R.*?^[ \t]*# END ' . $quoted . '[ \t]*$/ms', $content, $m ) ) {
			return $m[0];
		}
		return null;
	}

	/**
	 * Add, replace or remove a marker block (unlogged body of update_htaccess_block()).
	 *
	 * @since 0.1.18
	 *
	 * @param string      $marker Block marker.
	 * @param string|null $rules  Rules to write, null to remove.
	 * @return true|\WP_Error
	 */
	private static function do_update_htaccess_block( string $marker, ?string $rules ) {
		$htaccess_path = self::htaccess_path();
		$root_dir      = dirname( $htaccess_path );

		// If there is no .htaccess and we are removing, that is success.
		if ( null === $rules && ! file_exists( $htaccess_path ) ) {
			return true;
		}

		// If we need to write and the file does not exist, try to create it.
		if ( ! file_exists( $htaccess_path ) ) {
			if ( ! wp_is_writable( $root_dir ) ) {
				return new \WP_Error(
					'cachingout_htaccess_not_writable',
					sprintf(
						/* translators: %s: the site root directory */
						__( 'The WordPress root directory (%s) is not writable. Cannot create .htaccess.', 'cachingout' ),
						$root_dir
					)
				);
			}
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Writes the plugin's marked block in the site's own .htaccess (backup first, restored on failure); not user data.
			$created = @file_put_contents( $htaccess_path, '', LOCK_EX );
			if ( false === $created ) {
				return new \WP_Error(
					'cachingout_htaccess_create_failed',
					__( 'Could not create .htaccess file.', 'cachingout' )
				);
			}
		}

		if ( ! wp_is_writable( $htaccess_path ) ) {
			return new \WP_Error(
				'cachingout_htaccess_not_writable',
				sprintf(
					/* translators: %s: .htaccess path */
					__( '.htaccess at %s is not writable. Please add the rules manually.', 'cachingout' ),
					$htaccess_path
				)
			);
		}

		$content = file_get_contents( $htaccess_path );
		if ( false === $content ) {
			return new \WP_Error(
				'cachingout_htaccess_read_failed',
				__( 'Could not read .htaccess.', 'cachingout' )
			);
		}

		// Foreign-marker guard (.clinerules 03): never inject a block while
		// another cache plugin's rules are live in this file. Removing OUR
		// block is always allowed. Checked before the backup so a refused
		// write leaves nothing behind. An empty leftover marker pair does not
		// block - see Dropin_Owner::htaccess_foreign_block_with_rules().
		if ( null !== $rules ) {
			if ( ! class_exists( 'CachingOut_Dropin_Owner' ) ) {
				require_once CACHINGOUT_PATH . 'includes/cache/class-dropin-owner.php';
			}
			$foreign = CachingOut_Dropin_Owner::htaccess_foreign_block_with_rules( $content );
			if ( null !== $foreign ) {
				return new \WP_Error(
					'cachingout_htaccess_foreign_marker',
					sprintf(
						/* translators: %s: the other plugin's marker line, e.g. "# BEGIN W3TC" */
						__( 'Refused to write .htaccess: another caching plugin\'s rules are present (%s). Remove that plugin and its block first, then save again. Your file is unchanged.', 'cachingout' ),
						$foreign
					)
				);
			}
		}

		// No backup copy on disk (1.21.16): the original content stays in
		// $original and the replace is atomic (replace_file_atomically()).
		$original = $content;

		$begin = '# BEGIN ' . $marker;
		$end   = '# END ' . $marker;

		// Remove any existing block with this marker.
		//
		// Both markers are anchored to end-of-line ((?:\R|$)). Without that
		// anchor, marker "WP Caching Out" also matches the prefix of
		// "WP Caching Out Expires" / "... GZIP" / "... Brotli", which strips
		// the block body but leaves the bare suffix word ("Expires") behind.
		// Apache then fails with "Invalid command 'Expires'" and every request
		// on the site returns HTTP 500. See DECISIONS 2026-08-21.
		$content = preg_replace(
			'/(?:\r\n|\n|\r)?[ \t]*' . preg_quote( $begin, '/' ) . '[ \t]*(?:\r\n|\n|\r).*?'
				. preg_quote( $end, '/' ) . '[ \t]*(?:(?:\r\n|\n|\r)|$)/s',
			"\n\n",
			$content
		);

		// If we are removing, we are done after stripping.
		if ( null === $rules ) {
			$content = preg_replace( "/\n{3,}/", "\n\n", $content );
			$content = trim( $content );

			// Refuse to write config that would take Apache down.
			if ( ! self::htaccess_content_is_sane( $content ) ) {
				return new \WP_Error(
					'cachingout_htaccess_invalid_output',
					__( 'Refused to write .htaccess: the result contained an invalid directive. Your original file is unchanged.', 'cachingout' )
				);
			}

			$written = self::replace_file_atomically( $htaccess_path, $content . "\n", $original );
			if ( is_wp_error( $written ) ) {
				return new \WP_Error(
					'cachingout_htaccess_write_failed',
					__( 'Could not write to .htaccess; the file is unchanged.', 'cachingout' )
				);
			}

			return true;
		}

		// Append the new block before the WordPress block or at the end.
		if ( false !== strpos( $content, '# BEGIN WordPress' ) ) {
			$content = str_replace( '# BEGIN WordPress', $rules . "\n\n# BEGIN WordPress", $content );
		} else {
			$content = rtrim( $content ) . "\n\n" . $rules . "\n";
		}

		// Refuse to write config that would take Apache down.
		if ( ! self::htaccess_content_is_sane( $content ) ) {
			return new \WP_Error(
				'cachingout_htaccess_invalid_output',
				__( 'Refused to write .htaccess: the result contained an invalid directive. Your original file is unchanged.', 'cachingout' )
			);
		}

		$written = self::replace_file_atomically( $htaccess_path, $content, $original );
		if ( is_wp_error( $written ) ) {
			return new \WP_Error(
				'cachingout_htaccess_write_failed',
				__( 'Could not write to .htaccess; the file is unchanged.', 'cachingout' )
			);
		}

		return true;
	}

	/**
	 * Write the browser-cache blocks (expires, gzip, brotli) to .htaccess.
	 *
	 * Reads the browser_cache settings defaults and writes all three blocks
	 * on first activation. Uses the shared update_htaccess_block() helper.
	 *
	 * @since 0.1.0
	 * @return true|\WP_Error
	 */
	public static function write_browser_cache_rules() {
		if ( ! class_exists( 'CachingOut_Settings' ) ) {
			require_once CACHINGOUT_PATH . 'includes/class-settings.php';
		}

		if ( ! class_exists( 'CachingOut_Browser_Cache' ) ) {
			require_once CACHINGOUT_PATH . 'includes/cache/class-browser-cache.php';
		}

		$browser_cache = new CachingOut_Browser_Cache();
		$settings      = new CachingOut_Settings();

		return $browser_cache->write( $settings );
	}

	/**
	 * Locate wp-config.php.
	 *
	 * WordPress doesn't provide a function for this before it loads, so we
	 * check the standard locations: one directory above ABSPATH, then ABSPATH
	 * itself.
	 *
	 * @since 0.1.0
	 * @return string|null Absolute path to wp-config.php, or null if not found.
	 */
	public static function get_wp_config_path(): ?string {
		// Standard location: one directory above ABSPATH.
		$path = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $path ) ) {
			return $path;
		}

		// Alternative: inside ABSPATH itself.
		$path = ABSPATH . 'wp-config.php';
		if ( file_exists( $path ) ) {
			return $path;
		}

		// Not found.
		return null;
	}
}