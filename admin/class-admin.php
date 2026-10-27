<?php
/**
 * Admin settings page — registration, tab routing, and form handling.
 *
 * Registers the top-level "Caching Out" admin menu and its children
 * (Settings, Setup Wizard, Filesystem Check, Insights, Pro), renders the
 * tabbed settings interface (assets/css/admin.css, spec 35), and processes
 * form submissions through CachingOut_Settings::save().
 *
 * Since 1.21.0 the tab list, the view files, the saved values, the menu
 * entries and the enqueued assets are all extensible, so the Pro add-on
 * registers its screens here without the free edition carrying any of its
 * code (spec 30).
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI controller.
 *
 * Each tab corresponds to a settings section and has its own view file in
 * admin/views/. View files receive the `$settings` array for their section.
 *
 * Styled by the plugin's own stylesheet since 1.22.0 (spec 35); icons are
 * inline SVG from CachingOut_Icons.
 *
 * @since 0.1.0
 */
class CachingOut_Admin {

	/**
	 * Settings instance.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Page slug used in menu registration and screen detection.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const PAGE_SLUG = 'cachingout';

	/**
	 * Icon for the top-level menu entry: the Caching Out mark as a data
	 * URI (CachingOut_Brand::menu_icon()). The admin-bar node inlines the
	 * same mark.
	 *
	 * @since 0.1.17
	 * @since 1.21.6 The brand mark replaces the performance dashicon.
	 * @return string
	 */
	public static function menu_icon() {
		return CachingOut_Brand::menu_icon();
	}

	/**
	 * Setup Wizard instance, when the plugin has one.
	 *
	 * @since 0.1.17
	 * @var CachingOut_Setup_Wizard|null
	 */
	private $wizard = null;

	/**
	 * Filesystem Check page controller (lazily created).
	 *
	 * @since 0.1.17
	 * @var CachingOut_Filesystem_Check_Page|null
	 */
	private $fs_check_page = null;

	/**
	 * The FREE edition's tabs, in display order.
	 *
	 * Each entry: id, label, section, icon, group, order (within its group;
	 * lower first, default 50). The `section` key must match a key in the
	 * settings schema. The Pro add-on appends its tabs through the
	 * `cachingout_admin_tabs` filter; see tabs().
	 *
	 * @since 0.1.0
	 * @since 1.21.0 Free tabs only.
	 * @var array
	 */
	const TABS = array(
		array(
			'id'      => 'cache',
			'label'   => 'Cache',
			'section' => 'cache',
			'icon'    => 'lightning-charge',
			'group'   => 'performance',
			'order'   => 10,
			'docs'    => 'page-caching',
		),
		array(
			'id'      => 'browser_cache',
			'label'   => 'Browser Cache',
			'section' => 'browser_cache',
			'icon'    => 'globe2',
			'group'   => 'performance',
			'order'   => 20,
			'docs'    => 'browser-caching',
		),
		array(
			'id'      => 'media',
			'label'   => 'Media',
			'section' => 'media',
			'icon'    => 'image',
			'group'   => 'optimization',
			'order'   => 10,
			'docs'    => 'media-optimization',
		),
		array(
			'id'      => 'file_optimization',
			'label'   => 'File Optimization',
			'section' => 'file_optimization',
			'icon'    => 'file-earmark-code',
			'group'   => 'optimization',
			'order'   => 20,
			'docs'    => 'file-optimization',
		),
		array(
			'id'      => 'database',
			'label'   => 'Database',
			'section' => 'database',
			'icon'    => 'database',
			'group'   => 'optimization',
			'order'   => 40,
			'docs'    => 'database-cleanup',
		),
		array(
			'id'      => 'heartbeat',
			'label'   => 'Heartbeat',
			'section' => 'heartbeat',
			'icon'    => 'activity',
			'group'   => 'delivery',
			'order'   => 30,
			'docs'    => 'settings-reference#heartbeat',
		),
		array(
			'id'      => 'insights',
			'label'   => 'Insights',
			'section' => 'insights',
			'icon'    => 'graph-up',
			'group'   => 'insights',
			'order'   => 10,
			'docs'    => 'insights',
		),
		array(
			// The one Pro comparison screen (spec 30). Rendered by
			// admin/views/view-pro.php; saves nothing.
			'id'      => 'pro',
			'label'   => 'Pro',
			'section' => '',
			'icon'    => 'stars',
			'group'   => 'account',
			'order'   => 90,
		),
	);

	/**
	 * Sidebar navigation groups, in display order.
	 *
	 * Values match the `group` key on each entry in tabs(). A tab whose
	 * group is not listed here is rendered in the trailing "Other" group,
	 * so adding a tab without a group never makes it unreachable.
	 *
	 * @since 0.1.13
	 * @var string[]
	 */
	const TAB_GROUPS = array( 'performance', 'optimization', 'delivery', 'insights', 'account' );

	/**
	 * Glyphs handed to the admin scripts as  (spec 35).
	 *
	 * @since 1.22.0
	 * @var string[]
	 */
	const SCRIPT_ICONS = array( 'arrow-repeat', 'chat-dots', 'check-circle', 'check-circle-fill', 'download', 'exclamation-triangle-fill', 'info-circle', 'info-circle-fill', 'lightbulb', 'magic', 'play-fill', 'robot', 'search', 'shield-check', 'x-lg' );

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
	 * Cleanup engine instance (for AJAX handlers).
	 *
	 * @since 0.1.0
	 * @var CachingOut_DB_Cleanup|null
	 */
	private $db_cleanup = null;

	/**
	 * Set the database cleanup engine (optional, for AJAX handlers).
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_DB_Cleanup $cleanup Cleanup engine instance.
	 * @return void
	 */
	public function set_db_cleanup( CachingOut_DB_Cleanup $cleanup ) {
		$this->db_cleanup = $cleanup;
	}

	/**
	 * The cleanup engine (the Pro add-on's scheduler saves through it).
	 *
	 * @since 1.21.0
	 * @return CachingOut_DB_Cleanup|null
	 */
	public function db_cleanup(): ?CachingOut_DB_Cleanup {
		return $this->db_cleanup;
	}

	/**
	 * Attach the Setup Wizard so its menu entry can be registered.
	 *
	 * @since 0.1.17
	 *
	 * @param CachingOut_Setup_Wizard $wizard Wizard instance.
	 * @return void
	 */
	public function set_wizard( CachingOut_Setup_Wizard $wizard ) {
		$this->wizard = $wizard;
	}

	/**
	 * The Filesystem Check page controller, created on first use.
	 *
	 * @since 0.1.17
	 * @return CachingOut_Filesystem_Check_Page|null Null when the class is not loaded.
	 */
	public function filesystem_check_page() {
		if ( null === $this->fs_check_page && class_exists( 'CachingOut_Filesystem_Check_Page' ) ) {
			$this->fs_check_page = new CachingOut_Filesystem_Check_Page( $this->settings );
		}

		return $this->fs_check_page;
	}

	/**
	 * Every registered tab: the free TABS plus whatever the Pro add-on adds.
	 *
	 * @since 1.21.0
	 * @return array[]
	 */
	public static function tabs(): array {
		/**
		 * Filter the settings tabs.
		 *
		 * The Pro add-on appends entries with the same shape as self::TABS
		 * (id, label, section, icon, group, order) and a `view` key holding
		 * the absolute path of the view file it renders.
		 *
		 * @since 1.21.0
		 *
		 * @param array[] $tabs Tab entries.
		 */
		$tabs = apply_filters( 'cachingout_admin_tabs', self::TABS );
		$out  = array();
		if ( is_array( $tabs ) ) {
			foreach ( $tabs as $tab ) {
				if ( is_array( $tab ) && ! empty( $tab['id'] ) ) {
					$out[] = $tab;
				}
			}
		}
		return $out;
	}

	/**
	 * One tab entry by id, or null.
	 *
	 * @since 1.21.0
	 *
	 * @param string $id Tab id.
	 * @return array|null
	 */
	public static function tab( string $id ): ?array {
		foreach ( self::tabs() as $tab ) {
			if ( $tab['id'] === $id ) {
				return $tab;
			}
		}
		return null;
	}

	/**
	 * Tabs that also get a direct entry in the admin sidebar menu.
	 *
	 * Keyed by tab id; the value is the translated menu label. Literal __()
	 * calls keep the strings extractable. The Pro add-on adds its own (the
	 * AI Assistant) through `cachingout_menu_tab_links`.
	 *
	 * @since 0.1.17
	 * @return array<string, string>
	 */
	public static function get_menu_tab_links() {
		$links = array(
			'insights' => __( 'Insights', 'cachingout' ),
			'pro'      => __( 'Pro', 'cachingout' ),
		);

		/**
		 * Filter the sidebar deep links (tab id => menu label).
		 *
		 * @since 1.21.0
		 *
		 * @param array<string, string> $links Deep links.
		 */
		$filtered = apply_filters( 'cachingout_menu_tab_links', $links );

		return is_array( $filtered ) ? $filtered : $links;
	}

	/**
	 * Menu slug that deep-links a sidebar entry straight to one settings tab.
	 *
	 * A submenu registered with a slug of this shape and no callback is
	 * output by WordPress as a plain link, so no page hook is involved.
	 *
	 * @since 0.1.17
	 *
	 * @param string $tab Tab id.
	 * @return string
	 */
	public static function tab_menu_slug( $tab ) {
		return 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab;
	}

	/**
	 * Register hooks: add the menu tree and handle form submissions.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ), 10, 2 );

		$fs_page = $this->filesystem_check_page();
		if ( $fs_page ) {
			$fs_page->register();
		}

		add_filter( 'plugin_action_links_' . CACHINGOUT_BASENAME, array( $this, 'plugin_action_links' ) );
		add_action( 'admin_post_cachingout_save_settings', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_post_cachingout_restore_query_strings', array( $this, 'handle_restore_query_strings' ) );
		add_action( 'wp_ajax_cachingout_db_preview', array( $this, 'ajax_db_preview' ) );
		add_action( 'wp_ajax_cachingout_db_run', array( $this, 'ajax_db_run' ) );
	}

	/**
	 * Add a Settings link on the Plugins screen next to Deactivate.
	 *
	 * Only registered while the plugin is active (this class is not loaded
	 * when the plugin is deactivated), so the link is absent when inactive.
	 *
	 * @since 0.1.12
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function plugin_action_links( array $links ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$settings_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Settings', 'cachingout' )
		);

		$wizard_url = admin_url( 'admin.php?page=' . \CachingOut_Setup_Wizard::PAGE_SLUG );

		$wizard_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $wizard_url ),
			esc_html__( 'Setup Wizard', 'cachingout' )
		);

		array_unshift( $links, $settings_link );
		array_unshift( $links, $wizard_link );

		return $links;
	}

	/**
	 * Add the top-level "Caching Out" menu and its children.
	 *
	 * Order, top to bottom: Settings (the parent page itself), Setup Wizard,
	 * Filesystem Check, the add-on's pages (`cachingout_admin_menu`), then
	 * one deep link per entry in self::get_menu_tab_links(). Settings stays
	 * first because WordPress points the parent entry at the first child.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function add_settings_page() {
		$hook_suffix = add_menu_page(
			__( 'Caching Out', 'cachingout' ),
			__( 'Caching Out', 'cachingout' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			self::menu_icon()
		);

		// Same slug as the parent: WordPress then skips its automatic
		// duplicate and this entry becomes the first child, labelled
		// "Settings" instead of repeating the plugin name.
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Caching Out Settings', 'cachingout' ),
			__( 'Settings', 'cachingout' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->wizard ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'Setup Wizard', 'cachingout' ),
				__( 'Setup Wizard', 'cachingout' ),
				'manage_options',
				\CachingOut_Setup_Wizard::PAGE_SLUG,
				array( $this->wizard, 'render_wizard' )
			);
		}

		$fs_page = $this->filesystem_check_page();
		if ( $fs_page ) {
			add_submenu_page(
				self::PAGE_SLUG,
				__( 'Filesystem Check', 'cachingout' ),
				__( 'Filesystem Check', 'cachingout' ),
				'manage_options',
				\CachingOut_Filesystem_Check_Page::PAGE_SLUG,
				array( $fs_page, 'render' )
			);
		}

		/**
		 * Fires while the Caching Out menu tree is being built, after the
		 * Filesystem Check entry and before the deep links. The Pro add-on
		 * registers its own pages (Activity) here with self::PAGE_SLUG as
		 * their parent.
		 *
		 * @since 1.21.0
		 *
		 * @param string $parent_slug The parent menu slug.
		 */
		do_action( 'cachingout_admin_menu', self::PAGE_SLUG );

		foreach ( self::get_menu_tab_links() as $tab_id => $label ) {
			add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$label,
				'manage_options',
				self::tab_menu_slug( $tab_id )
			);
		}

		if ( $hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * Highlight the deep-link menu entry for the tab being viewed.
	 *
	 * WordPress marks a child "current" by comparing its slug with the
	 * `page` query argument, which is always `cachingout` on the settings
	 * page, so the deep-link entries would never light up and Settings
	 * always would. Returning the deep-link slug from the `submenu_file`
	 * filter hands the highlight to the right entry.
	 *
	 * @since 0.1.17
	 *
	 * @param string|null $submenu_file Current submenu file (null when unset).
	 * @param string      $parent_file  Current parent menu file.
	 * @return string|null
	 */
	public function highlight_submenu( $submenu_file, $parent_file ) {
		global $plugin_page;

		if ( self::PAGE_SLUG !== $plugin_page ) {
			return $submenu_file;
		}

		$links = self::get_menu_tab_links();
		$tab   = $this->get_current_tab();

		if ( isset( $links[ $tab ] ) ) {
			return self::tab_menu_slug( $tab );
		}

		return $submenu_file;
	}

	/**
	 * Enqueue admin CSS and JS on our settings page only.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		// Bail if this isn't one of our pages. Every page in the tree carries
		// the slug in its hook: toplevel_page_cachingout,
		// caching-out_page_cachingout-filesystem-check, and so on.
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		self::enqueue_shell_assets();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'database' === $current_tab ) {
			wp_enqueue_script(
				'cachingout-database',
				CACHINGOUT_URL . 'admin/assets/js/database.js',
				array(),
				CACHINGOUT_VERSION,
				true
			);

			// The script's settings travel with the script (1.21.13). Until
			// then the Database view printed them in an inline script tag.
			wp_localize_script(
				'cachingout-database',
				'cachingOutDB',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'cachingout_db_cleanup' ),
				)
			);
		}

		if ( 'pro' === $current_tab ) {
			// The optional partner code on the Upgrade link (1.21.16): it
			// adds the code at click time only. No settings, no AJAX.
			wp_enqueue_script(
				'cachingout-partner-code',
				CACHINGOUT_URL . 'admin/assets/js/partner-code.js',
				array(),
				CACHINGOUT_VERSION,
				true
			);
		}

		if ( 'insights' === $current_tab ) {
			wp_enqueue_style(
				'cachingout-admin-insights',
				CACHINGOUT_URL . 'assets/css/admin-insights.css',
				array( 'cachingout-admin' ),
				CACHINGOUT_VERSION
			);

			wp_enqueue_script(
				'cachingout-admin-insights',
				CACHINGOUT_URL . 'assets/js/admin-insights.js',
				array( 'cachingout-admin' ),
				CACHINGOUT_VERSION,
				true
			);

			$insights_data = array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'cachingout_insights' ),
				// The Page Audit renders every finding in full. For a finding
				// whose fix belongs to a Pro feature this site does not have,
				// the card shows one sentence and one link instead of a fix
				// button that could not apply (spec 30). Nothing is hidden.
				'pro'          => array(
					'context'     => self::pro_pointer_context(),
					'linkLabel'   => __( 'See what Pro adds', 'cachingout' ),
					'url'         => self::pro_pointer_url(),
					'unavailable' => self::unavailable_pro_labels(),
				),
				// Pro fills this with its assistant deep link; empty in free,
				// and the audit card then shows no assistant button.
				'assistantUrl' => '',
			);

			/**
			 * Filter the data localized for the Insights scripts.
			 *
			 * The Pro add-on adds what its own Insights script needs (the
			 * assistant deep link, its i18n strings) without a second
			 * localize call clobbering this one.
			 *
			 * @since 1.21.0
			 *
			 * @param array $insights_data Localized data.
			 */
			$insights_data = apply_filters( 'cachingout_admin_insights_data', $insights_data );

			wp_localize_script(
				'cachingout-admin-insights',
				'cachingOutData',
				is_array( $insights_data ) ? $insights_data : array()
			);
		}

		/**
		 * Fires after the free edition's assets are enqueued on a Caching
		 * Out screen. The Pro add-on enqueues its tab assets here.
		 *
		 * @since 1.21.0
		 *
		 * @param string $current_tab The tab being rendered ('' on other pages).
		 * @param string $hook_suffix The admin page hook suffix.
		 */
		do_action( 'cachingout_admin_enqueue_assets', $current_tab, $hook_suffix );
	}

	/**
	 * Enqueue the page shell every Caching Out screen shares: admin.css and
	 * admin.js, nothing else (spec 35: no framework, no icon font). Static
	 * so the Network Admin pages (spec 28), which are registered on a
	 * different menu hook, render in the same shell as the per-site pages.
	 *
	 * @since 0.1.20
	 * @since 1.22.0 Bootstrap, Bootstrap Icons and the Bootstrap bundle removed.
	 * @return void
	 */
	public static function enqueue_shell_assets() {
		wp_enqueue_style(
			'cachingout-admin',
			CACHINGOUT_URL . 'assets/css/admin.css',
			array(),
			CACHINGOUT_VERSION
		);

		wp_enqueue_script(
			'cachingout-admin',
			CACHINGOUT_URL . 'assets/js/admin.js',
			array(),
			CACHINGOUT_VERSION,
			true
		);

		// Scripts that build their own elements take icon markup from here
		// (CachingOut_Icons::for_script); they list 'cachingout-admin' as a
		// dependency so the object exists before they run.
		wp_localize_script(
			'cachingout-admin',
			'cachingOutIcons',
			CachingOut_Icons::for_script( self::SCRIPT_ICONS )
		);
	}

	/**
	 * Glyph name for a tab's `icon` key.
	 *
	 * Tabs name their icon by glyph (`gear`). An add-on built before
	 * 1.22.0 may still pass the old icon-font class (`bi-gear`); the
	 * prefix is dropped so its icon keeps rendering.
	 *
	 * @since 1.22.0
	 *
	 * @param string $icon The tab's `icon` value.
	 * @return string
	 */
	public static function icon_name( $icon ) {
		$icon = trim( (string) $icon );
		if ( 0 === strpos( $icon, 'bi bi-' ) ) {
			$icon = substr( $icon, 6 );
		} elseif ( 0 === strpos( $icon, 'bi-' ) ) {
			$icon = substr( $icon, 3 );
		}
		return $icon;
	}

	/**
	 * Render the full settings page: tabs + current tab content.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cachingout' ) );
		}

		$current_tab  = $this->get_current_tab();
		$all_settings = $this->settings->get_all();

		?>
		<div class="cachingout-wrap" role="region" aria-label="<?php esc_attr_e( 'Caching Out Settings', 'cachingout' ); ?>">
			<a href="#rs-main-content" class="rs-skip-link screen-reader-text">
				<?php esc_html_e( 'Skip to main content', 'cachingout' ); ?>
			</a>

			<?php self::render_header(); ?>

			<!-- Layout: navigation rail + content panel -->
			<div class="rs-layout">

				<nav class="rs-nav" aria-label="<?php esc_attr_e( 'Settings sections', 'cachingout' ); ?>">
					<?php foreach ( $this->get_grouped_tabs() as $group ) : ?>
						<h2 class="rs-nav__group"><?php echo esc_html( $group['label'] ); ?></h2>
						<ul class="rs-nav__list">
							<?php foreach ( $group['tabs'] as $tab ) : ?>
								<?php $is_active = ( $current_tab === $tab['id'] ); ?>
								<li class="rs-nav__item">
									<a
										href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab['id'] ), admin_url( 'admin.php' ) ) ); ?>"
										class="rs-nav__link<?php echo $is_active ? ' rs-nav__link--active' : ''; ?>"
										<?php echo $is_active ? ' aria-current="page"' : ''; ?>
									>
										<?php if ( ! empty( $tab['icon'] ) ) : ?>
											<?php CachingOut_Icons::render( self::icon_name( $tab['icon'] ) ); ?>
										<?php endif; ?>
										<span><?php echo esc_html( $tab['label'] ); ?></span>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
				</nav>

				<div class="rs-main">
					<div class="rs-body" id="rs-main-content">
						<?php self::render_tab_docs_link( $current_tab ); ?>
						<?php
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag set by our own redirect after a nonce-checked save; it only chooses a sentence.
						if ( isset( $_GET['saved'] ) ) {
							self::render_success_notice( __( 'Settings saved.', 'cachingout' ) );
						}
						?>
						<?php $this->render_tab( $current_tab, $all_settings ); ?>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render the shared page header: brand, version badge, wizard button.
	 *
	 * Used by the settings page and by every other page in the Caching Out
	 * menu so they read as one product.
	 *
	 * @since 0.1.17
	 * @since 0.1.20 `$show_wizard`: Network Admin (spec 28) has no wizard of its own.
	 *
	 * @param bool $show_wizard Whether to show the Setup Wizard button.
	 * @return void
	 */
	public static function render_header( $show_wizard = true ) {
		?>
		<!-- Header -->
		<div class="rs-header">
			<div class="rs-header__brand">
				<span class="rs-header__mark" aria-hidden="true">
					<?php echo CachingOut_Brand::mark( CachingOut_Brand::ARC_ON_TILE, CachingOut_Brand::BAR_ON_TILE, 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG built from class constants. ?>
				</span>
				<div>
					<h1 class="rs-header__title">
						<?php esc_html_e( 'Caching Out', 'cachingout' ); ?>
					</h1>
					<p class="rs-header__subtitle">
						<?php esc_html_e( 'Performance Optimization', 'cachingout' ); ?>
					</p>
				</div>
				<div class="rs-header__meta">
					<span class="rs-chip rs-chip--outline rs-chip--mono">
						v<?php echo esc_html( CACHINGOUT_VERSION ); ?>
					</span>
					<?php
					/**
					 * Fires after the version chip in the shared header. The
					 * Pro add-on shows its own chip (installed / switched on).
					 *
					 * @since 1.21.0
					 */
					do_action( 'cachingout_admin_header_badges' );
					?>
				</div>
			</div>
			<div class="rs-header__actions">
				<a href="<?php echo esc_url( CachingOut_Brand::docs_url() ); ?>" class="rs-button rs-button--sm" target="_blank" rel="noopener noreferrer">
					<?php CachingOut_Icons::render( 'book' ); ?>
					<?php esc_html_e( 'Documentation', 'cachingout' ); ?>
				</a>
				<?php if ( $show_wizard ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cachingout-setup-wizard' ) ); ?>" class="rs-button rs-button--sm">
					<?php CachingOut_Icons::render( 'magic' ); ?>
					<?php esc_html_e( 'Setup Wizard', 'cachingout' ); ?>
				</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the "Documentation" link for a tab, when the tab declares one.
	 *
	 * A tab's `docs` key is a page slug in the public documentation (see
	 * CachingOut_Brand::docs_url()); Pro tabs declare their own through
	 * `cachingout_admin_tabs`. Tabs without the key print nothing.
	 *
	 * @since 1.21.6
	 *
	 * @param string $tab Tab id.
	 * @return void
	 */
	public static function render_tab_docs_link( $tab ) {
		$entry = self::tab( $tab );
		if ( ! $entry || empty( $entry['docs'] ) ) {
			return;
		}
		?>
		<p class="rs-tab-docs">
			<a href="<?php echo esc_url( CachingOut_Brand::docs_url( (string) $entry['docs'] ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php CachingOut_Icons::render( 'book' ); ?>
				<?php
				printf(
					/* translators: %s: settings tab label, e.g. "Cache". */
					esc_html__( 'Documentation: %s', 'cachingout' ),
					esc_html( $entry['label'] )
				);
				?>
			</a>
		</p>
		<?php
	}

	/**
	 * Group the registered tabs for sidebar rendering.
	 *
	 * Returns groups in the order declared by self::TAB_GROUPS, each with its
	 * translated label and its tabs by `order` (ties in declaration order).
	 * Empty groups are omitted. Any tab whose `group` is missing or
	 * unrecognised is collected into a trailing "Other" group so it can never
	 * become unreachable.
	 *
	 * @since 0.1.13
	 * @return array<int, array{label: string, tabs: array}>
	 */
	private function get_grouped_tabs() {
		$buckets = array();
		foreach ( self::TAB_GROUPS as $key ) {
			$buckets[ $key ] = array();
		}

		$orphans = array();

		foreach ( self::tabs() as $seq => $tab ) {
			$tab['_seq'] = $seq;
			$group       = isset( $tab['group'] ) ? $tab['group'] : '';

			if ( isset( $buckets[ $group ] ) ) {
				$buckets[ $group ][] = $tab;
			} else {
				$orphans[] = $tab;
			}
		}

		$sort = static function ( array $list ): array {
			usort(
				$list,
				static function ( $a, $b ) {
					$oa = isset( $a['order'] ) ? (int) $a['order'] : 50;
					$ob = isset( $b['order'] ) ? (int) $b['order'] : 50;
					if ( $oa === $ob ) {
						return $a['_seq'] <=> $b['_seq'];
					}
					return $oa <=> $ob;
				}
			);
			return $list;
		};

		$grouped = array();

		foreach ( self::TAB_GROUPS as $key ) {
			if ( empty( $buckets[ $key ] ) ) {
				continue;
			}

			$grouped[] = array(
				'label' => self::get_group_label( $key ),
				'tabs'  => $sort( $buckets[ $key ] ),
			);
		}

		if ( ! empty( $orphans ) ) {
			$grouped[] = array(
				'label' => self::get_group_label( '' ),
				'tabs'  => $sort( $orphans ),
			);
		}

		return $grouped;
	}

	/**
	 * Translated display label for a sidebar group key.
	 *
	 * Uses literal __() calls so wp-cli i18n can extract the strings into
	 * languages/cachingout.pot. A variable passed to __() is invisible to
	 * the extractor and would ship permanently untranslatable.
	 *
	 * @since 0.1.13
	 *
	 * @param string $key Group key from self::TAB_GROUPS.
	 * @return string Translated label.
	 */
	private static function get_group_label( $key ) {
		switch ( $key ) {
			case 'performance':
				return __( 'Performance', 'cachingout' );
			case 'optimization':
				return __( 'Optimization', 'cachingout' );
			case 'delivery':
				return __( 'Delivery', 'cachingout' );
			case 'insights':
				return __( 'Insights', 'cachingout' );
			case 'account':
				return __( 'Account', 'cachingout' );
			default:
				return __( 'Other', 'cachingout' );
		}
	}

	/**
	 * Render a single tab's content.
	 *
	 * A tab entry may carry an absolute `view` path (the Pro add-on's);
	 * otherwise the view is admin/views/view-<tab>.php in this plugin.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tab      Tab ID.
	 * @param array  $settings All settings.
	 * @return void
	 */
	private function render_tab( $tab, $settings ) {
		$entry     = self::tab( $tab );
		$view_file = ( $entry && ! empty( $entry['view'] ) )
			? (string) $entry['view']
			: CACHINGOUT_PATH . 'admin/views/view-' . $tab . '.php';

		if ( ! file_exists( $view_file ) ) {
			echo '<div class="rs-alert rs-alert--warning" role="alert"><div class="rs-alert__body">' . esc_html__( 'Settings view not found.', 'cachingout' ) . '</div></div>';
			return;
		}

		// The view receives $settings (the full array) and outputs the form.
		include $view_file;
	}

	/**
	 * Get the current active tab ID.
	 *
	 * Defaults to the first tab if no valid tab is requested.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	private function get_current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		foreach ( self::tabs() as $tab ) {
			if ( $tab['id'] === $requested ) {
				return $requested;
			}
		}

		return self::TABS[0]['id'];
	}

	/**
	 * Handle form submission from any settings tab.
	 *
	 * Verifies the nonce, checks capabilities, then saves the submitted
	 * settings through CachingOut_Settings::save().
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function handle_form_submission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cachingout' ) );
		}

		check_admin_referer( 'cachingout_save_settings', 'cachingout_nonce' );

		$tab = isset( $_POST['cachingout_tab'] ) ? sanitize_key( wp_unslash( $_POST['cachingout_tab'] ) ) : '';

		// Validate tab maps to a known section.
		$entry   = self::tab( $tab );
		$section = ( $entry && ! empty( $entry['section'] ) ) ? (string) $entry['section'] : null;

		if ( null === $section ) {
			wp_die( esc_html__( 'Invalid settings tab.', 'cachingout' ) );
		}

		// Build sanitized values from POST.
		$values = $this->extract_tab_values( $tab );

		/**
		 * Fires before a settings tab is saved. The Pro add-on's preview-first
		 * flow (spec 25) stores the pending values and redirects from here.
		 *
		 * @since 1.21.0
		 *
		 * @param string $tab    Tab id.
		 * @param array  $values Extracted values.
		 */
		do_action( 'cachingout_admin_before_save', $tab, $values );

		$this->settings->save( $values, $section );

		// Redirect back to the tab with a success message.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'tab'   => $tab,
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Put the default ignored query parameters back on the Cache tab's list.
	 *
	 * A nonced link beside the "Ignored query parameters" field (1.21.17).
	 * Adds every default the admin removed; keeps the names they added. A
	 * link rather than a second submit button so that pressing Enter in
	 * another field of the form still saves instead of restoring.
	 *
	 * @since 1.21.17
	 * @return void
	 */
	public function handle_restore_query_strings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cachingout' ) );
		}

		check_admin_referer( 'cachingout_restore_query_strings' );

		$current = $this->settings->get( 'cache', 'cache_query_strings', array() );
		$merged  = array_merge(
			CachingOut_Settings::DEFAULT_IGNORED_QUERY_STRINGS,
			is_array( $current ) ? $current : array()
		);

		$this->settings->save( array( 'cache_query_strings' => $merged ), 'cache' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE_SLUG,
					'tab'   => 'cache',
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Extract raw input values for a given tab from the POST data.
	 *
	 * Each tab's view file uses consistent field naming so that the
	 * sanitizer in CachingOut_Settings receives the expected keys. The Pro
	 * add-on adds the keys of its own tabs, and the keys it renders on the
	 * shared tabs, through `cachingout_admin_tab_values` (BUG-023: every
	 * rendered field must be whitelisted somewhere, or it cannot be saved).
	 *
	 * @since 0.1.0
	 *
	 * @param string $tab Tab ID.
	 * @return array Raw values for the section.
	 */
	private function extract_tab_values( $tab ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_form_submission().
		switch ( $tab ) {
			case 'cache':
				$values = array(
					'enabled'                => isset( $_POST['cache_enabled'] ),
					'lifespan'               => isset( $_POST['cache_lifespan'] ) ? absint( $_POST['cache_lifespan'] ) : 0,
					'separate_mobile_cache'  => isset( $_POST['separate_mobile_cache'] ),
					'cache_query_strings'    => isset( $_POST['cache_query_strings'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cache_query_strings'] ) ) : '',
					'fs_check_enabled'       => isset( $_POST['fs_check_enabled'] ),
					// Custom exclusion lists (free since 1.21.16).
					'never_cache_urls'       => isset( $_POST['never_cache_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['never_cache_urls'] ) ) : '',
					'never_cache_cookies'    => isset( $_POST['never_cache_cookies'] ) ? sanitize_textarea_field( wp_unslash( $_POST['never_cache_cookies'] ) ) : '',
					'never_cache_useragents' => isset( $_POST['never_cache_useragents'] ) ? sanitize_textarea_field( wp_unslash( $_POST['never_cache_useragents'] ) ) : '',
				);
				break;

			case 'browser_cache':
				$values = array(
					'expires' => isset( $_POST['browser_cache_expires'] ),
					'gzip'    => isset( $_POST['browser_cache_gzip'] ),
					'brotli'  => isset( $_POST['browser_cache_brotli'] ),
				);
				break;

			case 'media':
				// BUG-016 (0.1.16): the Media tab rendered nine fields but only
				// lazyload_enabled was ever saved. Every field on the tab now
				// round-trips; an unticked checkbox saves as false.
				$values = array(
					'lazyload_enabled'            => isset( $_POST['lazyload_enabled'] ),
					'lazyload_images'             => isset( $_POST['lazyload_images'] ),
					'lazyload_iframes'            => isset( $_POST['lazyload_iframes'] ),
					'lazyload_backgrounds'        => isset( $_POST['lazyload_backgrounds'] ),
					'lazyload_videos'             => isset( $_POST['lazyload_videos'] ),
					'lazyload_exclude_above_fold' => isset( $_POST['lazyload_exclude_above_fold'] ),
					'lazyload_above_fold_count'   => isset( $_POST['lazyload_above_fold_count'] ) ? absint( $_POST['lazyload_above_fold_count'] ) : 3,
					'lazyload_add_dimensions'     => isset( $_POST['lazyload_add_dimensions'] ),
					'lazyload_exclusions'         => isset( $_POST['lazyload_exclusions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lazyload_exclusions'] ) ) : '',
					'lcp_priority_enabled'        => isset( $_POST['lcp_priority_enabled'] ),
					'lcp_priority_exclusions'     => isset( $_POST['lcp_priority_exclusions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lcp_priority_exclusions'] ) ) : '',
				);
				break;

			case 'file_optimization':
				$values = array(
					'minify_css'          => isset( $_POST['minify_css'] ),
					'minify_js'           => isset( $_POST['minify_js'] ),
					'defer_js'            => isset( $_POST['defer_js'] ),
					'excluded_css'        => isset( $_POST['excluded_css'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_css'] ) ) : '',
					'excluded_js'         => isset( $_POST['excluded_js'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_js'] ) ) : '',
					'excluded_inline_css' => isset( $_POST['excluded_inline_css'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_inline_css'] ) ) : '',
					'excluded_inline_js'  => isset( $_POST['excluded_inline_js'] ) ? sanitize_textarea_field( wp_unslash( $_POST['excluded_inline_js'] ) ) : '',
				);
				break;

			case 'heartbeat':
				$values = array(
					'heartbeat_frontend'    => isset( $_POST['heartbeat_frontend'] ),
					'heartbeat_admin'       => isset( $_POST['heartbeat_admin'] ) ? absint( $_POST['heartbeat_admin'] ) : 60,
					'heartbeat_post_editor' => isset( $_POST['heartbeat_post_editor'] ) ? absint( $_POST['heartbeat_post_editor'] ) : 30,
					'dns_prefetch_domains'  => isset( $_POST['dns_prefetch_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dns_prefetch_domains'] ) ) : '',
				);
				break;

			case 'insights':
				$values = array(
					'enabled'       => isset( $_POST['insights_enabled'] ),
					'custom_pages'  => isset( $_POST['insights_custom_pages'] ) ? sanitize_textarea_field( wp_unslash( $_POST['insights_custom_pages'] ) ) : '',
					'mobile_audits' => isset( $_POST['insights_mobile_audits'] ),
				);
				break;

			default:
				// The Database tab saves nothing from its form (manual cleanup
				// runs through AJAX); Pro tabs fill in through the filter.
				$values = array();
				break;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		/**
		 * Filter the values extracted from a submitted settings tab.
		 *
		 * The Pro add-on adds the keys of the tabs it registers and the keys
		 * it renders on the shared tabs. It reads $_POST itself; the nonce
		 * was verified by handle_form_submission().
		 *
		 * @since 1.21.0
		 *
		 * @param array  $values Extracted values so far.
		 * @param string $tab    Tab id.
		 */
		$filtered = apply_filters( 'cachingout_admin_tab_values', $values, $tab );

		return is_array( $filtered ) ? $filtered : $values;
	}

	// ─────────────────────── DB AJAX handlers ──────────────────────────────

	/**
	 * AJAX: preview one or more database cleanup operations.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function ajax_db_preview() {
		$this->verify_db_ajax();

		$operations = $this->parse_db_ajax_operations();
		$results    = array();

		foreach ( $operations as $op ) {
			$results[ $op ] = $this->db_cleanup->preview( $op );
		}

		wp_send_json_success( array( 'operations' => $results ) );
	}

	/**
	 * AJAX: run one or more database cleanup operations.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function ajax_db_run() {
		$this->verify_db_ajax();

		$operations = $this->parse_db_ajax_operations();
		$results    = array();

		foreach ( $operations as $op ) {
			$results[ $op ] = $this->db_cleanup->run( $op );
		}

		wp_send_json_success( array( 'operations' => $results ) );
	}

	/**
	 * Gate: verify nonce and capability for database AJAX handlers.
	 *
	 * Public since 1.21.0 so the Pro add-on's schedule handler shares it.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function verify_db_ajax() {
		// Kill switch.
		if ( defined( 'CACHINGOUT_DISABLE_DATABASE' ) && CACHINGOUT_DISABLE_DATABASE ) {
			wp_send_json_error( array( 'message' => 'Database cleanup is disabled.' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}

		check_ajax_referer( 'cachingout_db_cleanup', 'nonce' );

		if ( ! $this->db_cleanup ) {
			wp_send_json_error( array( 'message' => 'Cleanup engine not initialized.' ) );
		}
	}

	/**
	 * Parse the operations parameter from a database AJAX request.
	 *
	 * An operation the engine does not know (one registered by an add-on
	 * that is not active, or a typo) is refused with a clear message; it
	 * is never silently skipped.
	 *
	 * @since 0.1.0
	 * @return string[]
	 */
	private function parse_db_ajax_operations() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_db_ajax() ran first.
		$raw = isset( $_POST['operations'] ) ? sanitize_text_field( wp_unslash( $_POST['operations'] ) ) : '';

		if ( empty( $raw ) ) {
			wp_send_json_error( array( 'message' => 'No operations specified.' ) );
		}

		$ops = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $raw ) )
			)
		);

		if ( empty( $ops ) ) {
			wp_send_json_error( array( 'message' => 'No operations specified.' ) );
		}

		foreach ( $ops as $op ) {
			if ( $this->db_cleanup && ! $this->db_cleanup->operation_available( $op ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: operation name */
							__( 'Unknown database operation: %s.', 'cachingout' ),
							$op
						),
					)
				);
			}
		}

		return $ops;
	}

	/**
	 * Render a toggle (checkbox) field with Bootstrap styling.
	 *
	 * Used by view files for consistent markup.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label text.
	 * @param bool   $checked Current value.
	 * @param string $help    Optional help text below the label.
	 * @return void
	 */
	public static function render_toggle( $name, $label, $checked, $help = '' ) {
		$help_id = $help ? $name . '_help' : '';
		?>
		<div class="rs-field">
			<div class="rs-field__label">
				<label for="<?php echo esc_attr( $name ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
			</div>
			<div class="rs-field__control">
				<?php
				self::render_switch_input(
					$name,
					$checked,
					$help_id
				);
				?>
				<?php if ( $help ) : ?>
					<p class="rs-help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the switch control itself (no row wrapper).
	 *
	 * The knob is drawn on a sibling <span>, NOT on the input's own
	 * pseudo-elements. WordPress core's forms.css owns
	 * `input[type=checkbox]::before` (it paints a checkmark there) and
	 * matches at a higher specificity than any class selector Bootstrap
	 * uses, so anything drawn on the input is in a permanent fight with
	 * core. Core has no rules for our span, so there is nothing to fight.
	 *
	 * The input stays a real checkbox: keyboard operation, Space to
	 * toggle, form submission and screen-reader semantics all come for
	 * free. It is transparent and stretched over the track, so it remains
	 * the hit target.
	 *
	 * @since 0.1.13
	 *
	 * @param string $name    Field name (also used as the id).
	 * @param bool   $checked Current value.
	 * @param string $help_id Optional id of the describing help text.
	 * @return void
	 */
	public static function render_switch_input( $name, $checked, $help_id = '' ) {
		?>
		<span class="rs-switch">
			<input
				type="checkbox"
				class="rs-switch__input"
				role="switch"
				id="<?php echo esc_attr( $name ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				value="1"
				<?php checked( $checked ); ?>
				<?php if ( $help_id ) : ?>aria-describedby="<?php echo esc_attr( $help_id ); ?>"<?php endif; ?>
			>
			<span class="rs-switch__track" aria-hidden="true"></span>
		</span>
		<?php
	}

	/**
	 * Render a textarea field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label text.
	 * @param array  $lines       Current values (array of strings).
	 * @param string $placeholder Placeholder text for the textarea.
	 * @param string $help        Optional help text.
	 * @return void
	 */
	public static function render_textarea( $name, $label, $lines, $placeholder = '', $help = '' ) {
		$value   = is_array( $lines ) ? implode( "\n", $lines ) : '';
		$help_id = $help ? $name . '_help' : '';
		?>
		<div class="rs-field">
			<div class="rs-field__label">
				<label for="<?php echo esc_attr( $name ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
			</div>
			<div class="rs-field__control">
				<textarea
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					class="rs-textarea rs-textarea--mono"
					rows="5"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					<?php if ( $help_id ) : ?>aria-describedby="<?php echo esc_attr( $help_id ); ?>"<?php endif; ?>
				><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( $help ) : ?>
					<p class="rs-help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a number input field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Field name.
	 * @param string $label Label text.
	 * @param int    $value Current value.
	 * @param int    $min   Minimum allowed value.
	 * @param int    $max   Maximum allowed value.
	 * @param string $help  Optional help text.
	 * @return void
	 */
	public static function render_number( $name, $label, $value, $min = 1, $max = 100, $help = '' ) {
		$help_id = $help ? $name . '_help' : '';
		?>
		<div class="rs-field">
			<div class="rs-field__label">
				<label for="<?php echo esc_attr( $name ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
			</div>
			<div class="rs-field__control">
				<input
					type="number"
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					min="<?php echo esc_attr( $min ); ?>"
					max="<?php echo esc_attr( $max ); ?>"
					class="rs-input rs-input--short rs-input--mono"
					<?php if ( $help_id ) : ?>aria-describedby="<?php echo esc_attr( $help_id ); ?>"<?php endif; ?>
				>
				<?php if ( $help ) : ?>
					<p class="rs-help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a text input field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name        Field name.
	 * @param string $label       Label text.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder text.
	 * @param string $help        Optional help text.
	 * @return void
	 */
	public static function render_text( $name, $label, $value, $placeholder = '', $help = '' ) {
		$help_id = $help ? $name . '_help' : '';
		?>
		<div class="rs-field">
			<div class="rs-field__label">
				<label for="<?php echo esc_attr( $name ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
			</div>
			<div class="rs-field__control">
				<input
					type="text"
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					class="rs-input"
					<?php if ( $help_id ) : ?>aria-describedby="<?php echo esc_attr( $help_id ); ?>"<?php endif; ?>
				>
				<?php if ( $help ) : ?>
					<p class="rs-help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a select dropdown.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     Field name.
	 * @param string $label    Label text.
	 * @param string $value    Current value.
	 * @param array  $options  Options as [ 'value' => 'Label' ].
	 * @param string $help     Optional help text.
	 * @return void
	 */
	public static function render_select( $name, $label, $value, $options, $help = '' ) {
		$help_id = $help ? $name . '_help' : '';
		?>
		<div class="rs-field">
			<div class="rs-field__label">
				<label for="<?php echo esc_attr( $name ); ?>">
					<?php echo esc_html( $label ); ?>
				</label>
			</div>
			<div class="rs-field__control">
				<select
					id="<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					class="rs-select rs-select--short"
					<?php if ( $help_id ) : ?>aria-describedby="<?php echo esc_attr( $help_id ); ?>"<?php endif; ?>
				>
					<?php foreach ( $options as $opt_value => $opt_label ) : ?>
						<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
							<?php echo esc_html( $opt_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $help ) : ?>
					<p class="rs-help" id="<?php echo esc_attr( $help_id ); ?>"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a success notice.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Notice message.
	 * @return void
	 */
	public static function render_success_notice( $message ) {
		?>
		<div class="rs-alert rs-alert--success" role="alert">
			<?php CachingOut_Icons::render( 'check-circle-fill', 'rs-alert__icon' ); ?>
			<div class="rs-alert__body"><?php echo esc_html( $message ); ?></div>
			<button type="button" class="rs-alert__close" aria-label="<?php esc_attr_e( 'Close', 'cachingout' ); ?>">
				<?php CachingOut_Icons::render( 'x-lg' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render an info box.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content HTML content.
	 * @param string $type    Alert type (info, success, warning, danger). Default: info.
	 * @return void
	 */
	public static function render_info_box( $content, $type = 'info' ) {
		$icon_map = array(
			'info'    => 'info-circle-fill',
			'success' => 'check-circle-fill',
			'warning' => 'exclamation-triangle-fill',
			'danger'  => 'x-octagon-fill',
		);
		if ( ! isset( $icon_map[ $type ] ) ) {
			$type = 'info';
		}
		?>
		<div class="rs-alert rs-alert--<?php echo esc_attr( $type ); ?>" role="alert">
			<?php CachingOut_Icons::render( $icon_map[ $type ], 'rs-alert__icon' ); ?>
			<div class="rs-alert__body"><?php echo wp_kses_post( $content ); ?></div>
		</div>
		<?php
	}

	/**
	 * Render the restrained "this is a Pro feature" line used by the Page
	 * Audit, the Filesystem Check and the shared tabs: the feature's name and
	 * ONE link, nothing hidden, nothing blurred, no banner (spec 30).
	 *
	 * Prints nothing while the feature is available.
	 *
	 * @since 1.21.0
	 *
	 * @param string $feature Feature key from CachingOut_Features::TABLE.
	 * @param string $context Optional. Sentence before the feature name.
	 * @return void
	 */
	public static function render_pro_pointer( string $feature, string $context = '' ) {
		if ( ! class_exists( 'CachingOut_Features' ) || CachingOut_Features::PRO !== CachingOut_Features::edition( $feature ) || CachingOut_Features::available( $feature ) ) {
			return;
		}
		$label = CachingOut_Features::label( $feature );
		if ( '' === $context ) {
			$context = self::pro_pointer_context();
		}
		?>
		<p class="rs-pro-pointer">
			<?php echo esc_html( $context ); ?>
			<strong><?php echo esc_html( $label ); ?></strong>
			<a href="<?php echo esc_url( self::pro_pointer_url() ); ?>"><?php esc_html_e( 'See what Pro adds', 'cachingout' ); ?></a>
		</p>
		<?php
	}

	/**
	 * The one sentence that introduces an unavailable Pro feature.
	 *
	 * Two states, both plain: Pro is not installed, or Pro is installed and
	 * waiting for the licence that switches it on. No urgency, no timer, no
	 * discount - the wording says what is true and stops (spec 30).
	 *
	 * @since 1.21.0
	 * @return string
	 */
	public static function pro_pointer_context(): string {
		if ( class_exists( 'CachingOut_Features' ) && CachingOut_Features::pro_installed() ) {
			return __( 'Part of Caching Out Pro, which is installed here and switches on with your licence:', 'cachingout' );
		}
		return __( 'Part of Caching Out Pro:', 'cachingout' );
	}

	/**
	 * The single destination a Pro pointer links to: the licence tab once Pro
	 * is installed (that is where the operator finishes the job), otherwise
	 * the one Pro comparison screen. Never more than one link.
	 *
	 * @since 1.21.0
	 * @return string
	 */
	public static function pro_pointer_url(): string {
		$tab = ( class_exists( 'CachingOut_Features' ) && CachingOut_Features::pro_installed() && null !== self::tab( 'license' ) )
			? 'license'
			: 'pro';

		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab );
	}

	/**
	 * Pro feature key => label, for every Pro feature this site does not
	 * have. Used by the Insights script to put one honest sentence on a
	 * finding whose fix is not available here.
	 *
	 * @since 1.21.0
	 * @return array<string, string>
	 */
	public static function unavailable_pro_labels(): array {
		$out = array();
		if ( ! class_exists( 'CachingOut_Features' ) ) {
			return $out;
		}
		foreach ( CachingOut_Features::keys( CachingOut_Features::PRO ) as $key ) {
			if ( ! CachingOut_Features::available( $key ) ) {
				$out[ $key ] = CachingOut_Features::label( $key );
			}
		}
		return $out;
	}
}
