<?php
/**
 * Filesystem check — structured diagnostics over every file the plugin needs
 * to function without breaking the site.
 *
 * Grew out of the activation-report notice ("Caching Out filesystem check",
 * cachingout.php): the same idea, made toggleable, structured and readable by
 * anything that wants it, so "is everything okay, and if not what do I
 * change?" is answerable without a debug session.
 *
 * Free edition rows only. The Pro add-on appends the rows for the files and
 * records it owns through `cachingout_filesystem_check_rows` (spec 30).
 *
 * Read-only by design: this class never writes, repairs, or deletes anything.
 * Remedial action stays with the activation report's "Re-run filesystem
 * check" button (which re-runs the activator) and with the suggestions each
 * check carries.
 *
 * @package CachingOut
 * @since 0.1.15
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only filesystem/wiring diagnostics.
 *
 * @since 0.1.15
 */
class CachingOut_Filesystem_Check {

	/**
	 * Settings manager.
	 *
	 * @since 0.1.15
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Audit instance override for the Pro change-record row (tests). Null
	 * resolves the shared instance. Kept in the free class because the
	 * Filesystem Check is free and only the row is Pro (spec 30).
	 *
	 * @since 0.1.18
	 * @var object|null CachingOut_Audit when the Pro add-on is loaded.
	 */
	private $audit = null;

	/**
	 * Constructor.
	 *
	 * @since 0.1.15
	 *
	 * @param CachingOut_Settings $settings Settings manager instance.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Use a specific audit instance for the change-record row.
	 *
	 * @since 0.1.18
	 * @since 1.21.0 Untyped: the audit subsystem lives in the Pro add-on.
	 *
	 * @param object|null $audit CachingOut_Audit instance, or null for the shared one.
	 * @return void
	 */
	public function set_audit( $audit ): void {
		$this->audit = is_object( $audit ) ? $audit : null;
	}

	/**
	 * The audit override set by set_audit(), or null.
	 *
	 * Read by the Pro add-on's `cachingout_filesystem_check_rows` callback.
	 *
	 * @since 1.21.0
	 * @return object|null
	 */
	public function audit() {
		return $this->audit;
	}

	/**
	 * Whether the filesystem check is enabled in settings.
	 *
	 * @since 0.1.15
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'cache', 'fs_check_enabled', true );
	}

	/**
	 * Run every check and return structured results.
	 *
	 * Each result: {
	 *   id:     string  Stable check identifier.
	 *   label:  string  Human-readable check name.
	 *   status: string  'ok' | 'warn' | 'fail'.
	 *   detail: string  What was found.
	 *   fix:    string  Remedial suggestion ('' when status is ok).
	 * }
	 *
	 * @since 0.1.15
	 * @return array[] Results in display order.
	 */
	public function run(): array {
		$results = array();

		$results[] = $this->check_kill_switch();
		$results[] = $this->check_wp_cache_constant();
		$results[] = $this->check_dropin();
		$results[] = $this->check_runtime_config();
		$results[] = $this->check_cache_root();
		$results[] = $this->check_guard_files();
		$results[] = $this->check_vendor_autoload();
		$results[] = $this->check_cron_bindings();
		$results[] = $this->check_rival_remnants();

		/**
		 * Filter the Filesystem Check rows.
		 *
		 * The free edition reports only what the free edition installs. The
		 * Pro add-on appends the rows for the files and records it owns
		 * (Optimize CSS delivery, the change record). A callback must APPEND
		 * rows of the same shape (id, label, status, detail, fix); removing
		 * or rewriting a free row would hide real damage.
		 *
		 * @since 1.21.0
		 *
		 * @param array[]                      $results  Rows so far.
		 * @param CachingOut_Settings          $settings Settings instance.
		 * @param CachingOut_Filesystem_Check  $check    The check instance (audit() override).
		 */
		$filtered = apply_filters( 'cachingout_filesystem_check_rows', $results, $this->settings, $this );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_array' ) ) : $results;
	}

	/**
	 * Compact plain-text summary for the AI chat payload.
	 *
	 * One line per check: "id: STATUS - detail". Failing checks append the
	 * fix so the assistant can quote a concrete remedial step.
	 *
	 * @since 0.1.15
	 * @return string
	 */
	public function summary(): string {
		$lines = array();

		foreach ( $this->run() as $check ) {
			$line = $check['id'] . ': ' . strtoupper( $check['status'] ) . ' - ' . $check['detail'];
			if ( 'ok' !== $check['status'] && '' !== $check['fix'] ) {
				$line .= ' Fix: ' . $check['fix'];
			}
			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Whether every check passed.
	 *
	 * @since 0.1.15
	 * @return bool True when no check reports 'fail'.
	 */
	public function all_ok(): bool {
		foreach ( $this->run() as $check ) {
			if ( 'fail' === $check['status'] ) {
				return false;
			}
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Individual checks
	// -------------------------------------------------------------------------

	/**
	 * Kill switch state.
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_kill_switch(): array {
		$on = defined( 'CACHINGOUT_DISABLE_CACHE' ) && CACHINGOUT_DISABLE_CACHE;

		return $this->result(
			'kill_switch',
			__( 'Cache kill switch', 'cachingout' ),
			$on ? 'warn' : 'ok',
			$on
				? __( 'CACHINGOUT_DISABLE_CACHE is set in wp-config.php: all cache reading and writing is disabled.', 'cachingout' )
				: __( 'Kill switch is off; caching is allowed to run.', 'cachingout' ),
			$on
				? __( 'This is intentional protection. Remove the CACHINGOUT_DISABLE_CACHE line from wp-config.php to re-enable caching.', 'cachingout' )
				: ''
		);
	}

	/**
	 * Whether the install is locked: server files are deferred, so their
	 * absence is expected rather than a failure.
	 *
	 * The free edition is never locked - it switches on at activation and
	 * needs no licence (spec 30). The scan-first machine moved to the Pro
	 * add-on, which answers this filter for the state it owns.
	 *
	 * @since 0.1.20
	 * @since 1.21.0 Filterable; false in the free edition.
	 * @return bool
	 */
	private function is_locked(): bool {
		/**
		 * Filter whether this install defers its server-file writes.
		 *
		 * @since 1.21.0
		 *
		 * @param bool $locked Default false: the free edition is always on.
		 */
		return (bool) apply_filters( 'cachingout_install_locked', false );
	}

	/**
	 * WP_CACHE constant state.
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_wp_cache_constant(): array {
		$set = defined( 'WP_CACHE' ) && WP_CACHE;

		if ( ! $set && $this->is_locked() ) {
			return $this->result( 'wp_cache', __( 'WP_CACHE constant', 'cachingout' ), 'ok', __( 'Not written yet: the plugin is not switched on. It is written the moment a licence is validated.', 'cachingout' ), '' );
		}

		return $this->result(
			'wp_cache',
			__( 'WP_CACHE constant', 'cachingout' ),
			$set ? 'ok' : 'fail',
			$set
				? __( 'WP_CACHE is true in wp-config.php; WordPress loads the drop-in.', 'cachingout' )
				: __( 'WP_CACHE is not true, so WordPress never loads the page-cache drop-in. Pages are not cached.', 'cachingout' ),
			$set
				? ''
				: __( 'Use the "Re-run filesystem check" button on the Caching Out notice (it re-runs activation and writes the constant), or add define( \'WP_CACHE\', true ); to wp-config.php. If it keeps disappearing, your host may manage wp-config.php.', 'cachingout' )
		);
	}

	/**
	 * Drop-in presence and ownership.
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_dropin(): array {
		$id    = 'dropin';
		$label = __( 'advanced-cache.php drop-in', 'cachingout' );

		if ( ! class_exists( 'CachingOut_Dropin_Owner' ) ) {
			$guard = CACHINGOUT_PATH . 'includes/cache/class-dropin-owner.php';
			if ( file_exists( $guard ) ) {
				require_once $guard;
			}
		}

		if ( ! class_exists( 'CachingOut_Dropin_Owner' ) ) {
			return $this->result(
				$id,
				$label,
				'warn',
				__( 'Ownership guard class unavailable; cannot verify the drop-in.', 'cachingout' ),
				__( 'Reinstall the plugin from a fresh zip - a plugin file is missing.', 'cachingout' )
			);
		}

		$path = \CachingOut_Dropin_Owner::dropin_path();

		if ( 'blocked' === \CachingOut_Dropin_Owner::get_page_cache_mode() ) {
			return $this->result(
				$id,
				$label,
				'warn',
				sprintf(
					/* translators: %s: detected rival plugin name */
					__( 'Page caching is blocked: the drop-in belongs to %s.', 'cachingout' ),
					\CachingOut_Dropin_Owner::get_detected_owner_label()
				),
				__( 'Deactivate the other caching plugin, then deactivate and reactivate Caching Out so it can install its own drop-in. Every other Caching Out module keeps working meanwhile.', 'cachingout' )
			);
		}

		if ( ! file_exists( $path ) && $this->is_locked() ) {
			return $this->result( $id, $label, 'ok', __( 'Not installed yet: the plugin is not switched on. The drop-in is written the moment a licence is validated.', 'cachingout' ), '' );
		}

		if ( ! file_exists( $path ) ) {
			return $this->result(
				$id,
				$label,
				'fail',
				__( 'wp-content/advanced-cache.php does not exist. Pages are not cached.', 'cachingout' ),
				__( 'Click "Re-run filesystem check" on the Caching Out notice (it re-runs activation and rewrites the drop-in). If that fails, your host is blocking writes to wp-content/.', 'cachingout' )
			);
		}

		if ( ! \CachingOut_Dropin_Owner::is_ours() ) {
			return $this->result(
				$id,
				$label,
				'warn',
				__( 'A drop-in exists but its content does not match the copy Caching Out installed (another plugin may have replaced it, or it is from an older version).', 'cachingout' ),
				__( 'Click "Re-run filesystem check" to refresh it if it is ours, or check for another caching plugin. Caching Out never overwrites a file it does not own.', 'cachingout' )
			);
		}

		return $this->result(
			$id,
			$label,
			'ok',
			__( 'Drop-in installed and verified by its ownership stamp.', 'cachingout' ),
			''
		);
	}

	/**
	 * Runtime settings snapshot the drop-in reads.
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_runtime_config(): array {
		$id    = 'runtime_config';
		$label = __( 'Runtime settings snapshot', 'cachingout' );
		$path  = CachingOut_Settings::runtime_config_path();

		if ( ! file_exists( $path ) ) {
			return $this->result(
				$id,
				$label,
				'fail',
				__( 'runtime-config.php is missing, so the drop-in has no settings and serves nothing.', 'cachingout' ),
				__( 'Save any settings page once (the snapshot is re-exported on every save), or click "Re-run filesystem check".', 'cachingout' )
			);
		}

		$config = include $path;

		if ( ! is_array( $config ) || ! isset( $config['cache'] ) ) {
			return $this->result(
				$id,
				$label,
				'fail',
				__( 'runtime-config.php exists but is unreadable or malformed.', 'cachingout' ),
				__( 'Save any settings page once to regenerate it. If this repeats, check wp-content/cache/ permissions.', 'cachingout' )
			);
		}

		// Spec 28: on multisite this site is served from its own snapshot.
		if ( is_multisite() ) {
			$blog_id   = (int) get_current_blog_id();
			$blog_path = CachingOut_Settings::runtime_config_path_for_blog( $blog_id );
			if ( ! file_exists( $blog_path ) ) {
				return $this->result(
					$id,
					$label,
					'fail',
					sprintf(
						/* translators: %s: file name */
						__( '%s is missing, so this site is served with the shared snapshot instead of its own settings.', 'cachingout' ),
						basename( $blog_path )
					),
					__( 'Save any settings page on this site once (its snapshot is re-exported on every save).', 'cachingout' )
				);
			}
			$blog_config = include $blog_path;
			if ( ! is_array( $blog_config ) || ! isset( $blog_config['cache'] ) ) {
				return $this->result(
					$id,
					$label,
					'fail',
					sprintf(
						/* translators: %s: file name */
						__( '%s exists but is unreadable or malformed.', 'cachingout' ),
						basename( $blog_path )
					),
					__( 'Save any settings page on this site once to regenerate it.', 'cachingout' )
				);
			}
			return $this->result(
				$id,
				$label,
				'ok',
				sprintf(
					/* translators: %s: file name */
					__( 'Shared snapshot and this site\'s %s present and readable.', 'cachingout' ),
					basename( $blog_path )
				),
				''
			);
		}

		return $this->result(
			$id,
			$label,
			'ok',
			__( 'Snapshot present and readable.', 'cachingout' ),
			''
		);
	}

	/**
	 * Cache root directory existence and writability.
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_cache_root(): array {
		$id    = 'cache_root';
		$label = __( 'Cache directory', 'cachingout' );
		$root  = CachingOut_Page_Cache::cache_dir();

		if ( ! is_dir( $root ) ) {
			return $this->result(
				$id,
				$label,
				'fail',
				sprintf(
					/* translators: %s: directory path */
					__( 'Cache directory %s does not exist.', 'cachingout' ),
					$root
				),
				__( 'Click "Purge Cache" (which recreates the directory) or "Re-run filesystem check". If it fails, your host is blocking writes to wp-content/cache/.', 'cachingout' )
			);
		}

		if ( ! wp_is_writable( $root ) ) {
			return $this->result(
				$id,
				$label,
				'fail',
				__( 'Cache directory exists but is not writable; new pages cannot be cached.', 'cachingout' ),
				__( 'Fix the directory permissions on wp-content/cache/cachingout/ (your host can do this) so the web server can write to it.', 'cachingout' )
			);
		}

		return $this->result(
			$id,
			$label,
			'ok',
			__( 'Cache directory exists and is writable.', 'cachingout' ),
			''
		);
	}

	/**
	 * Guard files that stop direct access to the cache directory.
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_guard_files(): array {
		$id    = 'guard_files';
		$label = __( 'Cache directory guards', 'cachingout' );
		$root  = CachingOut_Page_Cache::cache_dir();

		if ( ! is_dir( $root ) ) {
			// The cache_root check already failed; do not double-report.
			return $this->result(
				$id,
				$label,
				'warn',
				__( 'Skipped: cache directory missing.', 'cachingout' ),
				__( 'Resolve the cache directory check first.', 'cachingout' )
			);
		}

		$missing = array();
		if ( ! file_exists( $root . DIRECTORY_SEPARATOR . 'index.php' ) ) {
			$missing[] = 'index.php';
		}
		if ( ! file_exists( $root . DIRECTORY_SEPARATOR . '.htaccess' ) ) {
			$missing[] = '.htaccess';
		}

		if ( ! empty( $missing ) ) {
			return $this->result(
				$id,
				$label,
				'warn',
				sprintf(
					/* translators: %s: comma-separated missing file names */
					__( 'Missing guard file(s) in the cache directory: %s.', 'cachingout' ),
					implode( ', ', $missing )
				),
				__( 'Click "Purge Cache" - guard files are recreated with the cache root. (.htaccess only matters on Apache.)', 'cachingout' )
			);
		}

		return $this->result(
			$id,
			$label,
			'ok',
			__( 'index.php and .htaccess guards present.', 'cachingout' ),
			''
		);
	}

	/**
	 * Production vendor autoload (BUG-011/BUG-012 class of failure).
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_vendor_autoload(): array {
		$id       = 'vendor_autoload';
		$label    = __( 'Bundled libraries', 'cachingout' );
		$autoload = CACHINGOUT_PATH . 'vendor/autoload.php';

		if ( ! file_exists( $autoload ) ) {
			return $this->result(
				$id,
				$label,
				'warn',
				__( 'vendor/autoload.php is missing: JavaScript minification is unavailable (pages still cache normally).', 'cachingout' ),
				__( 'Reinstall the plugin from the official zip - the bundled minifier library did not ship with this copy.', 'cachingout' )
			);
		}

		return $this->result(
			$id,
			$label,
			'ok',
			__( 'Bundled minifier library present.', 'cachingout' ),
			''
		);
	}

	/**
	 * Cron callbacks actually bound (the audit N5/N6/N8 failure class).
	 *
	 * A scheduled event with no listener fires into a void; this check makes
	 * that invisible failure visible.
	 *
	 * @since 0.1.15
	 * @return array
	 */
	private function check_cron_bindings(): array {
		$id    = 'cron_bindings';
		$label = __( 'Background task wiring', 'cachingout' );

		$hooks = array();
		if ( class_exists( 'CachingOut_Expiry' ) ) {
			$hooks[] = CachingOut_Expiry::CRON_HOOK;
		}

		/**
		 * Filter the scheduled-event hooks this check expects a handler for.
		 *
		 * The free edition schedules one: the cache expiry sweep. The Pro
		 * add-on appends the hooks it schedules (preload, licence
		 * validation, weekly asset scan, font refresh, database cleanup).
		 *
		 * @since 1.21.0
		 *
		 * @param string[] $hooks Cron hook names.
		 */
		$hooks = apply_filters( 'cachingout_filesystem_check_cron_hooks', $hooks );
		$hooks = is_array( $hooks ) ? array_unique( array_filter( array_map( 'strval', $hooks ) ) ) : array();

		$unbound = array();
		foreach ( $hooks as $hook ) {
			if ( false === has_action( $hook ) ) {
				$unbound[] = $hook;
			}
		}

		if ( ! empty( $unbound ) ) {
			return $this->result(
				$id,
				$label,
				'fail',
				sprintf(
					/* translators: %s: comma-separated cron hook names */
					__( 'Scheduled task(s) have no handler and will do nothing when they run: %s.', 'cachingout' ),
					implode( ', ', $unbound )
				),
				__( 'This indicates a partially loaded plugin - deactivate and reactivate Caching Out, and reinstall from a fresh zip if it persists.', 'cachingout' )
			);
		}

		return $this->result(
			$id,
			$label,
			'ok',
			__( 'Every scheduled background task has a handler.', 'cachingout' ),
			''
		);
	}

	/**
	 * Leftovers from cache plugins that are no longer installed (TODOS 17).
	 *
	 * Detect-and-advise only: the scanner never deletes. A LIVE leftover
	 * block in .htaccess is the one case that changes behaviour (the
	 * foreign-marker guard refuses our browser-caching write while it is
	 * there), so the fix text leads with it.
	 *
	 * @since 0.1.17
	 * @return array
	 */
	private function check_rival_remnants(): array {
		$id    = 'rival_remnants';
		$label = __( 'Leftovers from other cache plugins', 'cachingout' );

		if ( ! class_exists( 'CachingOut_Rival_Remnants' ) ) {
			$file = CACHINGOUT_PATH . 'includes/cache/class-rival-remnants.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
		if ( ! class_exists( 'CachingOut_Rival_Remnants' ) ) {
			return $this->result( $id, $label, 'ok', __( 'Scanner unavailable; skipped.', 'cachingout' ), '' );
		}

		$scanner = new CachingOut_Rival_Remnants();
		$rows    = $scanner->scan();

		if ( ! $rows ) {
			return $this->result( $id, $label, 'ok', __( 'No folders, files or .htaccess blocks from a removed cache plugin were found.', 'cachingout' ), '' );
		}

		$live = array_filter( $rows, static function ( $row ) {
			return ! empty( $row['live'] );
		} );

		$detail = sprintf(
			/* translators: %d: count */
			_n( '%d leftover from a removed cache plugin: ', '%d leftovers from removed cache plugins: ', count( $rows ), 'cachingout' ),
			count( $rows )
		) . implode( '; ', array_slice( $scanner->summary_lines( $rows ), 0, 8 ) ) . ( count( $rows ) > 8 ? ' ...' : '' );

		$fix = $live
			? __( 'An .htaccess block from a plugin that is no longer installed still carries rules; Apache keeps applying them and Caching Out will not write its own browser-caching rules until it is gone. Back up .htaccess, delete that block (BEGIN to END lines), then remove the leftover folders and files listed above with your file manager or FTP client. Caching Out never deletes another plugin\'s files for you.', 'cachingout' )
			: __( 'Nothing here is active; these are safe to delete with your file manager or FTP client once you have confirmed the plugin is uninstalled. Caching Out never deletes another plugin\'s files for you.', 'cachingout' );

		return $this->result( $id, $label, 'warn', $detail, $fix );
	}

	/**
	 * Build one result row.
	 *
	 * @since 0.1.15
	 *
	 * @param string $id     Stable identifier.
	 * @param string $label  Human-readable name.
	 * @param string $status 'ok' | 'warn' | 'fail'.
	 * @param string $detail What was found.
	 * @param string $fix    Remedial suggestion.
	 * @return array
	 */
	private function result( string $id, string $label, string $status, string $detail, string $fix ): array {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}
}
