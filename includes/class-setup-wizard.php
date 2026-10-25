<?php
/**
 * Setup Wizard — step-by-step onboarding for new activations.
 *
 * Renders the guided first-run page. Its menu entry ("Setup Wizard" under
 * Caching Out) is registered by CachingOut_Admin, which owns the menu
 * tree. Modeled after the setup wizards in Rank Math SEO, WP Mail SMTP, and
 * WPForms.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup Wizard controller.
 *
 * Renders a multi-step wizard that configures the most impactful settings
 * in a guided flow. The wizard auto-redirects on first activation and can
 * be re-launched from the admin header or the Caching Out menu at any time.
 *
 * @since 0.1.0
 */
class CachingOut_Setup_Wizard {

	/**
	 * Settings instance.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Wizard page slug.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PAGE_SLUG = 'cachingout-setup-wizard';

	/**
	 * Option key that tracks whether the wizard has been completed.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const COMPLETED_OPTION = 'cachingout_setup_wizard_completed';

	/**
	 * The FREE edition's wizard steps, in order.
	 *
	 * Each step: [ 'id' => string, 'title' => string, 'description' => string,
	 * 'after' => string (optional: the step id an add-on step follows),
	 * 'view' => string (optional: absolute view path of an add-on step) ].
	 * The Pro add-on inserts its steps (Smart Config detection, the
	 * telemetry opt-in) through `cachingout_wizard_steps`; see steps().
	 *
	 * @since 0.1.0
	 * @since 1.21.0 Free steps only.
	 * @var array
	 */
	const STEPS = array(
		array(
			'id'          => 'welcome',
			'title'       => 'Welcome',
			'description' => 'Let\'s get your site running at peak performance.',
		),
		array(
			'id'          => 'caching',
			'title'       => 'Caching',
			'description' => 'Configure how pages are cached and served.',
		),
		array(
			'id'          => 'media',
			'title'       => 'Media',
			'description' => 'Optimize images, iframes, and lazy loading.',
		),
		array(
			'id'          => 'optimization',
			'title'       => 'File Optimization',
			'description' => 'Minify CSS and JavaScript.',
		),
		array(
			'id'          => 'finish',
			'title'       => 'Ready!',
			'description' => 'Your site is optimized and ready to go.',
		),
	);

	/**
	 * Every wizard step: the free STEPS with the add-on's steps inserted.
	 *
	 * An add-on entry carrying `after` is placed right after that step id
	 * (unknown ids append before the finish step); entries without it append
	 * before the finish step too, so "Ready!" always closes the wizard.
	 *
	 * @since 1.21.0
	 * @return array[]
	 */
	public static function steps(): array {
		/**
		 * Filter the wizard steps. Callbacks ADD entries; they must not
		 * remove or reorder the free steps.
		 *
		 * @since 1.21.0
		 *
		 * @param array[] $extra Add-on steps (empty by default).
		 */
		$extra = apply_filters( 'cachingout_wizard_steps', array() );
		$steps = self::STEPS;
		if ( ! is_array( $extra ) ) {
			return $steps;
		}
		foreach ( $extra as $step ) {
			if ( ! is_array( $step ) || empty( $step['id'] ) ) {
				continue;
			}
			$after = isset( $step['after'] ) ? (string) $step['after'] : '';
			$at    = null;
			foreach ( $steps as $i => $existing ) {
				if ( '' !== $after && $existing['id'] === $after ) {
					$at = $i + 1;
					break;
				}
			}
			if ( null === $at ) {
				$at = max( 0, count( $steps ) - 1 );
			}
			array_splice( $steps, $at, 0, array( $step ) );
		}
		return $steps;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings $settings Settings instance.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register hooks: activation redirect and the step-save handler.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_post_cachingout_wizard_save', array( $this, 'handle_wizard_save' ) );
	}

	/**
	 * Redirect to the wizard on first activation if not yet completed.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function maybe_redirect() {
		// Only redirect on activation.
		if ( ! get_transient( 'cachingout_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'cachingout_activation_redirect' );

		// Don't redirect if already completed or during bulk activation.
		if ( get_option( self::COMPLETED_OPTION, false ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Set the activation redirect transient.
	 *
	 * Called by CachingOut_Activator on plugin activation.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public static function set_activation_redirect() {
		set_transient( 'cachingout_activation_redirect', true, 30 );
	}

	/**
	 * Enqueue wizard assets.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function enqueue_assets() {
		// The same shell as the settings page: admin.css and admin.js,
		// nothing else (spec 35).
		CachingOut_Admin::enqueue_shell_assets();

		/**
		 * Fires after the wizard shell assets are enqueued. The Pro add-on
		 * enqueues the assets of the steps it adds here.
		 *
		 * @since 1.21.0
		 *
		 * @param string $step The step being rendered.
		 */
		do_action( 'cachingout_wizard_enqueue_assets', $this->get_current_step() );
	}

	/**
	 * Render the full wizard page.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_wizard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cachingout' ) );
		}

		$this->enqueue_assets();

		$current_step = $this->get_current_step();
		$step_index   = $this->get_step_index( $current_step );
		$all_settings = $this->settings->get_all();
		$steps        = self::steps();

		?>
		<div class="cachingout-wrap rs-wizard-wrap">
			<!-- Progress indicator -->
			<div class="rs-wizard-progress">
				<?php foreach ( $steps as $i => $step ) : ?>
					<?php
					$step_class = '';
					if ( $i < $step_index ) {
						$step_class = 'rs-wizard-step--completed';
					} elseif ( $i === $step_index ) {
						$step_class = 'rs-wizard-step--active';
					}
					?>
					<div class="rs-wizard-step <?php echo esc_attr( $step_class ); ?>">
						<span class="rs-wizard-step__number">
							<?php if ( $i < $step_index ) : ?>
								<?php CachingOut_Icons::render( 'check-lg' ); ?>
							<?php else : ?>
								<?php echo esc_html( $i + 1 ); ?>
							<?php endif; ?>
						</span>
						<span class="rs-wizard-step__label"><?php echo esc_html( $step['title'] ); ?></span>
					</div>
					<?php if ( $i < count( $steps ) - 1 ) : ?>
						<div class="rs-wizard-connector <?php echo $i < $step_index ? 'rs-wizard-connector--completed' : ''; ?>"></div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<!-- Card -->
			<div class="rs-wizard-card">
				<?php $this->render_step( $current_step, $all_settings ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a single wizard step.
	 *
	 * @since 0.1.0
	 *
	 * @param string $step     Step ID.
	 * @param array  $settings All settings.
	 * @return void
	 */
	private function render_step( $step, $settings ) {
		$steps      = self::steps();
		$step_index = $this->get_step_index( $step );
		$total      = count( $steps );
		$is_last    = ( $step_index === $total - 1 );

		$view_file = CACHINGOUT_PATH . 'admin/views/wizard/view-wizard-' . $step . '.php';
		foreach ( $steps as $entry ) {
			if ( $entry['id'] === $step && ! empty( $entry['view'] ) ) {
				$view_file = (string) $entry['view'];
			}
		}

		if ( ! file_exists( $view_file ) ) {
			echo '<div class="rs-alert rs-alert--warning" role="alert"><div class="rs-alert__body">' . esc_html__( 'Wizard step not found.', 'cachingout' ) . '</div></div>';
			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cachingout_wizard_save', 'cachingout_wizard_nonce' ); ?>
			<input type="hidden" name="action" value="cachingout_wizard_save">
			<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>">

			<div class="rs-wizard-content">
				<?php include $view_file; ?>
			</div>

			<div class="rs-wizard-actions">
				<div>
					<?php if ( $step_index > 0 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'step', $steps[ $step_index - 1 ]['id'], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>" class="rs-button">
							<?php CachingOut_Icons::render( 'arrow-left' ); ?>
							<?php esc_html_e( 'Back', 'cachingout' ); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cachingout' ) ); ?>" class="rs-button rs-button--ghost">
						<?php CachingOut_Icons::render( 'gear' ); ?>
						<?php esc_html_e( 'Full Settings', 'cachingout' ); ?>
					</a>
				</div>
				<div>
					<?php if ( $is_last ) : ?>
						<button type="submit" class="rs-button rs-button--primary rs-button--lg">
							<?php CachingOut_Icons::render( 'check-circle' ); ?>
							<?php esc_html_e( 'Finish Setup', 'cachingout' ); ?>
						</button>
					<?php else : ?>
						<button type="submit" class="rs-button rs-button--primary">
							<?php esc_html_e( 'Save & Continue', 'cachingout' ); ?>
							<?php CachingOut_Icons::render( 'arrow-right' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Handle wizard form submission.
	 *
	 * Saves settings for the current step and advances to the next.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function handle_wizard_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'cachingout' ) );
		}

		check_admin_referer( 'cachingout_wizard_save', 'cachingout_wizard_nonce' );

		$step       = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'welcome';
		$step_index = $this->get_step_index( $step );

		// Spec 26: name the surface, so the settings.change rows the saves
		// below produce read `wizard.<step>`, and mark the step itself.
		if ( class_exists( 'CachingOut_Audit' ) ) {
			try {
				CachingOut_Audit::set_request_source( 'wizard.' . $step );
				CachingOut_Audit::log( array( 'type' => 'wizard.step', 'target' => $step ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// Save settings based on the current step.
		$this->save_step_settings( $step );

		/**
		 * Fires after the free edition saved a wizard step. The Pro add-on
		 * saves the fields of its own steps, and the Pro fields it renders on
		 * the shared steps, here (the nonce was verified above).
		 *
		 * @since 1.21.0
		 *
		 * @param string              $step     Step id.
		 * @param CachingOut_Settings $settings Settings instance.
		 */
		do_action( 'cachingout_wizard_save_step', $step, $this->settings );

		// Determine next step.
		$steps      = self::steps();
		$next_index = $step_index + 1;

		if ( $next_index >= count( $steps ) ) {
			// Wizard complete.
			update_option( self::COMPLETED_OPTION, true, false );
			wp_safe_redirect( admin_url( 'admin.php?page=cachingout&tab=cache&wizard=done' ) );
		} else {
			$next_step = $steps[ $next_index ]['id'];
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . $next_step ) );
		}
		exit;
	}

	/**
	 * Save settings relevant to the current wizard step.
	 *
	 * @since 0.1.0
	 *
	 * @param string $step Step ID.
	 * @return void
	 */
	private function save_step_settings( $step ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_wizard_save().
		switch ( $step ) {
			case 'caching':
				$values = array(
					'enabled'               => isset( $_POST['cache_enabled'] ),
					'lifespan'              => isset( $_POST['cache_lifespan'] ) ? absint( $_POST['cache_lifespan'] ) : 0,
					'separate_mobile_cache' => isset( $_POST['separate_mobile_cache'] ),
				);
				$this->settings->save( $values, 'cache' );

				$browser_values = array(
					'expires' => isset( $_POST['browser_cache_expires'] ),
					'gzip'    => isset( $_POST['browser_cache_gzip'] ),
					'brotli'  => isset( $_POST['browser_cache_brotli'] ),
				);
				$this->settings->save( $browser_values, 'browser_cache' );
				break;

			case 'media':
				$values = array(
					'lazyload_enabled'     => isset( $_POST['lazyload_enabled'] ),
					'lazyload_images'      => isset( $_POST['lazyload_images'] ),
					'lazyload_iframes'     => isset( $_POST['lazyload_iframes'] ),
					'lazyload_backgrounds' => isset( $_POST['lazyload_backgrounds'] ),
				);
				$this->settings->save( $values, 'media' );
				break;

			case 'optimization':
				$values = array(
					'minify_css' => isset( $_POST['minify_css'] ),
					'minify_js'  => isset( $_POST['minify_js'] ),
					'defer_js'   => isset( $_POST['defer_js'] ),
				);
				$this->settings->save( $values, 'file_optimization' );
				break;

			default:
				// Welcome and finish save nothing; add-on steps save through
				// the cachingout_wizard_save_step action.
				break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Get the current step ID from the query string.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	private function get_current_step() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

		foreach ( self::steps() as $step ) {
			if ( $step['id'] === $requested ) {
				return $requested;
			}
		}

		return self::STEPS[0]['id'];
	}

	/**
	 * Get the numeric index of a step by its ID.
	 *
	 * @since 0.1.0
	 *
	 * @param string $step_id Step ID.
	 * @return int Zero-based index.
	 */
	private function get_step_index( $step_id ) {
		foreach ( self::steps() as $i => $step ) {
			if ( $step['id'] === $step_id ) {
				return $i;
			}
		}

		return 0;
	}
}