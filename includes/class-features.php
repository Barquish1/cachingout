<?php
/**
 * The gating chokepoint: which edition owns each feature.
 *
 * ONE table, ONE question - "is this feature available on this site?" - and
 * every feature that Pro adds asks it here. No feature checks a licence
 * directly; the Pro add-on answers the `cachingout_pro_available` filter with
 * its own switched-on state and this class does the rest.
 *
 * Three editions:
 *   always - the safety layer. Never gated, never duplicated in Pro, applies
 *            with or without Pro, licensed or not. Listed so the table is the
 *            complete map, and so a test can assert nothing ever gates it.
 *   free   - the free edition's feature set. Always available.
 *   pro    - available only while the Pro add-on is active AND switched on.
 *
 * The table also names, per Pro feature, the settings keys it owns. When the
 * feature is unavailable those keys read as their defaults (see
 * CachingOut_Settings::mask_unavailable()); the stored values are never
 * touched, so deactivating Pro loses nothing and re-activating it restores
 * everything.
 *
 * No code shipped in the free plugin is gated by this table. A `pro` row's
 * code lives in the add-on and reaches the free plugin through hooks; the
 * row here only drives the comparison screen and the settings mask of keys
 * the free plugin never reads (FreeEditionTest pins that). Everything in
 * the free plugin works in full without the add-on (WordPress.org
 * guideline 5; DECISIONS 2026-10-03 and 2026-10-19).
 *
 * @package CachingOut
 * @since 1.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feature gating table and chokepoint.
 *
 * @since 1.21.0
 */
final class CachingOut_Features {

	const ALWAYS = 'always';
	const FREE   = 'free';
	const PRO    = 'pro';

	/**
	 * The table. Key => [ edition, label, settings it owns (section.key),
	 * where the gate is enforced ].
	 *
	 * "enforced" is documentation for the audit page: the code path that asks
	 * available() for this key. Keep it accurate when a consumer moves.
	 *
	 * @since 1.21.0
	 * @var array<string, array{edition: string, label: string, settings: string[], enforced: string}>
	 */
	const TABLE = array(
		// ---- always: the safety layer (identical with and without Pro) -----
		'logged_in_bypass'      => array(
			'edition'  => self::ALWAYS,
			'label'    => 'Logged-in visitors are never cached',
			'settings' => array(),
			'enforced' => 'CachingOut_Cacheability (fixed rule, no setting); the drop-in',
		),
		'ecommerce_bypass'      => array(
			'edition'  => self::ALWAYS,
			'label'    => 'Cart, checkout and account pages never cached',
			'settings' => array(),
			'enforced' => 'CachingOut_WooCommerce / CachingOut_EDD register into the chokepoint',
		),
		'session_cookie_bypass' => array(
			'edition'  => self::ALWAYS,
			'label'    => 'Session, auth and cart cookies bypass the cache (incl. PHPSESSID)',
			'settings' => array(),
			'enforced' => 'CachingOut_Cacheability::VARIATION_COOKIES / SESSION_COOKIES; the drop-in',
		),
		'admin_login_bypass'    => array(
			'edition'  => self::ALWAYS,
			'label'    => 'wp-admin and wp-login.php never cached',
			'settings' => array(),
			'enforced' => 'CachingOut_Cacheability; the drop-in',
		),
		'get_only'              => array(
			'edition'  => self::ALWAYS,
			'label'    => 'Only GET responses are cached',
			'settings' => array(),
			'enforced' => 'CachingOut_Cacheability; the drop-in',
		),
		'set_cookie_bypass'     => array(
			'edition'  => self::ALWAYS,
			'label'    => 'A response setting a session or auth cookie is never cached',
			'settings' => array(),
			'enforced' => 'CachingOut_Cacheability (response side); the drop-in',
		),
		'dropin_ownership'      => array(
			'edition'  => self::ALWAYS,
			'label'    => 'Drop-in ownership guard (never overwrite another plugin, name what was found)',
			'settings' => array(),
			'enforced' => 'CachingOut_Dropin_Owner; CachingOut_Activator::write_dropin()',
		),
		'kill_switch'           => array(
			'edition'  => self::ALWAYS,
			'label'    => 'CACHINGOUT_DISABLE_CACHE kill switch',
			'settings' => array(),
			'enforced' => 'cachingout.php, the drop-in, CachingOut_Cacheability',
		),
		'clean_uninstall'       => array(
			'edition'  => self::ALWAYS,
			'label'    => 'Uninstall removes the cache directory, the drop-in, WP_CACHE and the server-config block',
			'settings' => array(),
			'enforced' => 'CachingOut_Uninstaller (free); Pro removes only its own data',
		),

		// ---- free ---------------------------------------------------------
		'page_cache'            => array(
			'edition'  => self::FREE,
			'label'    => 'Page caching served before WordPress loads',
			'settings' => array( 'cache.enabled', 'cache.lifespan', 'cache.separate_mobile_cache', 'cache.cache_query_strings' ),
			'enforced' => 'free (never gated)',
		),
		'purge'                 => array(
			'edition'  => self::FREE,
			'label'    => 'Automatic purge on content change, archives included; admin-bar purge',
			'settings' => array(),
			'enforced' => 'free (never gated)',
		),
		'browser_cache'         => array(
			'edition'  => self::FREE,
			'label'    => 'Browser caching, GZIP and Brotli through the hardened server-config writer',
			'settings' => array( 'browser_cache.expires', 'browser_cache.gzip', 'browser_cache.brotli' ),
			'enforced' => 'free (never gated)',
		),
		'lazyload'              => array(
			'edition'  => self::FREE,
			'label'    => 'Lazy loading for images, iframes and video; missing width/height; hero-image priority',
			'settings' => array( 'media.lazyload_enabled', 'media.lazyload_images', 'media.lazyload_iframes', 'media.lazyload_backgrounds', 'media.lazyload_videos', 'media.lazyload_exclude_above_fold', 'media.lazyload_above_fold_count', 'media.lazyload_exclusions', 'media.lazyload_add_dimensions', 'media.lcp_priority_enabled', 'media.lcp_priority_exclusions' ),
			'enforced' => 'free (never gated)',
		),
		'minify'                => array(
			'edition'  => self::FREE,
			'label'    => 'Minify CSS and JS, with per-file inclusion/exclusion controls (off by default)',
			'settings' => array( 'file_optimization.minify_css', 'file_optimization.minify_js', 'file_optimization.excluded_css', 'file_optimization.excluded_js', 'file_optimization.excluded_inline_css', 'file_optimization.excluded_inline_js' ),
			'enforced' => 'free (never gated)',
		),
		'defer_js'              => array(
			'edition'  => self::FREE,
			'label'    => 'Defer JavaScript (off by default), same exclusion controls',
			'settings' => array( 'file_optimization.defer_js' ),
			'enforced' => 'free (never gated)',
		),
		'db_cleanup_manual'     => array(
			'edition'  => self::FREE,
			'label'    => 'Database cleanup by hand, with preview-first row counts',
			'settings' => array(),
			'enforced' => 'free (never gated); scheduling and two further operations are the add-on\'s, in the add-on',
		),
		'page_audit'            => array(
			'edition'  => self::FREE,
			'label'    => 'Page Audit, in full',
			'settings' => array( 'insights.enabled', 'insights.custom_pages', 'insights.mobile_audits' ),
			'enforced' => 'free (never gated)',
		),
		'filesystem_check'      => array(
			'edition'  => self::FREE,
			'label'    => 'Filesystem Check, in full',
			'settings' => array( 'cache.fs_check_enabled' ),
			'enforced' => 'free (never gated)',
		),
		'heartbeat_control'     => array(
			'edition'  => self::FREE,
			'label'    => 'Heartbeat control and DNS prefetch (not named in the split; kept free, see spec 30)',
			'settings' => array( 'heartbeat.heartbeat_frontend', 'heartbeat.heartbeat_admin', 'heartbeat.heartbeat_post_editor', 'heartbeat.dns_prefetch_domains' ),
			'enforced' => 'free (never gated)',
		),
		// Free since 1.21.16 (WordPress.org guideline 5, DECISIONS 2026-10-19):
		// the chokepoint reads the three lists on both paths; nothing masks them.
		'custom_exclusions'     => array(
			'edition'  => self::FREE,
			'label'    => 'Custom exclusion rules by URL, cookie and user agent',
			'settings' => array( 'cache.never_cache_urls', 'cache.never_cache_cookies', 'cache.never_cache_useragents' ),
			'enforced' => 'free (never gated): CachingOut_Cacheability steps 11d, 12 and 15',
		),

		// ---- pro ----------------------------------------------------------
		'critical_css'          => array(
			'edition'  => self::PRO,
			'label'    => 'Optimize CSS delivery',
			'settings' => array( 'file_optimization.optimize_css_delivery' ),
			'enforced' => 'CachingOut_Pro registers the pipeline filter; CachingOut_Settings::mask_unavailable()',
		),
		'combine'               => array(
			'edition'  => self::PRO,
			'label'    => 'Combine CSS and JS',
			'settings' => array( 'file_optimization.combine_css', 'file_optimization.combine_js' ),
			'enforced' => 'CachingOut_Pro registers the pipeline filter; mask_unavailable()',
		),
		'delay_js'              => array(
			'edition'  => self::PRO,
			'label'    => 'Delay JavaScript',
			'settings' => array( 'file_optimization.delay_js', 'file_optimization.delay_js_analytics', 'file_optimization.delay_js_timeout', 'file_optimization.delay_js_exclusions' ),
			'enforced' => 'CachingOut_Pro registers the pipeline filter; mask_unavailable()',
		),
		'jquery_footer'         => array(
			'edition'  => self::PRO,
			'label'    => 'Load jQuery in the footer',
			'settings' => array( 'file_optimization.jquery_footer' ),
			'enforced' => 'CachingOut_Pro wires CachingOut_JQuery_Footer on wp_print_scripts; mask_unavailable()',
		),
		'jquery_defer'          => array(
			'edition'  => self::PRO,
			'label'    => 'Defer jQuery (opt-in, after checking inline scripts)',
			'settings' => array( 'file_optimization.jquery_defer' ),
			'enforced' => 'CachingOut_Pro registers the jquery_defer pipeline filter at position 55; mask_unavailable()',
		),
		'google_fonts'          => array(
			'edition'  => self::PRO,
			'label'    => 'Google Fonts: combine requests and self-host',
			'settings' => array( 'file_optimization.google_fonts_optimize', 'file_optimization.font_self_host' ),
			'enforced' => 'CachingOut_Pro registers the pipeline filters and the refresh cron; mask_unavailable()',
		),
		'webp'                  => array(
			'edition'  => self::PRO,
			'label'    => 'WebP serving (where WebP copies already exist)',
			'settings' => array( 'media.webp_cache_enabled' ),
			'enforced' => 'CachingOut_Pro registers the cache-write hook; the drop-in reads the masked runtime snapshot',
		),
		'embeds'                => array(
			'edition'  => self::PRO,
			'label'    => 'YouTube click-to-load',
			'settings' => array( 'media.embeds_enabled' ),
			'enforced' => 'CachingOut_Pro runs CachingOut_Embeds; mask_unavailable()',
		),
		'asset_unloading'       => array(
			'edition'  => self::PRO,
			'label'    => 'Unused CSS/JS: rules, scan, preview, dependency check, never-unload list, suggestions, weekly scan',
			'settings' => array( 'file_optimization.asset_unloading', 'file_optimization.asset_unload_rules', 'file_optimization.asset_unload_html_strip', 'file_optimization.asset_unload_scan_pages', 'file_optimization.asset_unload_safe_mode', 'file_optimization.asset_unload_scan_weekly' ),
			'enforced' => 'CachingOut_Pro registers the unloader, the scan and the tab; mask_unavailable()',
		),
		'audit_log'             => array(
			'edition'  => self::PRO,
			'label'    => 'Activity change record with one-click revert',
			'settings' => array( 'cache.audit_enabled', 'cache.audit_retention_events', 'cache.audit_retention_days', 'cache.audit_ai_digest' ),
			'enforced' => 'CachingOut_Pro wires the audit store, listeners, Activity page and revert',
		),
		'ai_chat'               => array(
			'edition'  => self::PRO,
			'label'    => 'AI assistant (fair-use limited)',
			'settings' => array(),
			'enforced' => 'CachingOut_Pro registers the chat tab and AJAX handler; CachingOut_Chat_API::is_available()',
		),
		'insights_pagespeed'    => array(
			'edition'  => self::PRO,
			'label'    => 'Performance Insights: PageSpeed dial, baseline, re-test, history',
			'settings' => array( 'insights.api_key', 'insights.auto_audit', 'insights.auto_audit_frequency' ),
			'enforced' => 'CachingOut_Pro renders the panel and registers the PageSpeed AJAX handlers',
		),
		'preload'               => array(
			'edition'  => self::PRO,
			'label'    => 'Cache preloading',
			'settings' => array( 'preload.enabled', 'preload.sitemap_url', 'preload.batch_size', 'preload.request_timeout' ),
			'enforced' => 'CachingOut_Pro wires the crawler, its cron, the tab and the admin-bar item',
		),
		'db_scheduled_cleanup'  => array(
			'edition'  => self::PRO,
			'label'    => 'Database: scheduled cleanup, orphaned metadata, table optimisation',
			'settings' => array( 'database.revisions_schedule', 'database.auto_drafts_schedule', 'database.trashed_posts_schedule', 'database.spam_comments_schedule', 'database.trashed_comments_schedule', 'database.expired_transients_schedule', 'database.all_transients_schedule', 'database.optimize_tables_schedule', 'database.orphaned_meta_schedule', 'database.revisions_enabled', 'database.auto_drafts_enabled', 'database.trashed_posts_enabled', 'database.spam_comments_enabled', 'database.trashed_comments_enabled', 'database.expired_transients_enabled', 'database.all_transients_enabled', 'database.optimize_tables_enabled', 'database.orphaned_meta_enabled' ),
			'enforced' => 'CachingOut_Pro wires the scheduler and registers its two operations through CachingOut_DB_Cleanup::register_operation(); the Database tab',
		),
		'cdn'                   => array(
			'edition'  => self::PRO,
			'label'    => 'CDN rewriting',
			'settings' => array( 'cdn.enabled', 'cdn.cnames', 'cdn.https', 'cdn.css', 'cdn.js', 'cdn.images', 'cdn.fonts', 'cdn.video', 'cdn.exclusions' ),
			'enforced' => 'CachingOut_Pro runs the rewriter; mask_unavailable()',
		),
		'addons'                => array(
			'edition'  => self::PRO,
			'label'    => 'Cloudflare, Varnish and Sucuri purge add-ons',
			'settings' => array( 'addons.cloudflare_enabled', 'addons.cloudflare_email', 'addons.cloudflare_api_key', 'addons.cloudflare_zone_id', 'addons.varnish_enabled', 'addons.sucuri_enabled', 'addons.sucuri_api_key', 'addons.sucuri_api_secret' ),
			'enforced' => 'CachingOut_Pro registers the purge hooks; mask_unavailable()',
		),
		'wp_cli'                => array(
			'edition'  => self::PRO,
			'label'    => 'WP-CLI command set',
			'settings' => array(),
			'enforced' => 'CachingOut_Pro registers wp cachingout on cli_init',
		),
		'settings_transfer'     => array(
			'edition'  => self::PRO,
			'label'    => 'Settings export and import',
			'settings' => array(),
			'enforced' => 'CachingOut_Pro registers the Import/Export tab and its admin_post handlers',
		),
		'network_admin'         => array(
			'edition'  => self::PRO,
			'label'    => 'Multisite Network Admin management',
			'settings' => array(),
			'enforced' => 'CachingOut_Pro registers the Network Admin pages when is_multisite()',
		),
		'telemetry'             => array(
			'edition'  => self::PRO,
			'label'    => 'Opt-in anonymous usage telemetry (authenticates with the licence secret)',
			'settings' => array( 'insights.optin' ),
			'enforced' => 'CachingOut_Pro wires the sender; the free edition sends nothing',
		),
		'auto_config'           => array(
			'edition'  => self::PRO,
			'label'    => 'Site detection and Smart Config rules (setup wizard detection step)',
			'settings' => array(),
			'enforced' => 'CachingOut_Pro adds the wizard step and its AJAX handler',
		),
	);

	/**
	 * Memo of the Pro answer for this request (null = not asked yet).
	 *
	 * @since 1.21.0
	 * @var bool|null
	 */
	private static $pro_available = null;

	/**
	 * The edition that owns a feature, or null for an unknown key.
	 *
	 * @since 1.21.0
	 *
	 * @param string $feature Feature key.
	 * @return string|null 'always' | 'free' | 'pro' | null.
	 */
	public static function edition( string $feature ): ?string {
		return isset( self::TABLE[ $feature ] ) ? self::TABLE[ $feature ]['edition'] : null;
	}

	/**
	 * Whether a feature is available on this site right now.
	 *
	 * Unknown keys are NOT available (fail closed) and are reported through
	 * _doing_it_wrong() in debug builds so a typo cannot silently unlock a
	 * feature or silently hide one.
	 *
	 * @since 1.21.0
	 *
	 * @param string $feature Feature key from self::TABLE.
	 * @return bool
	 */
	public static function available( string $feature ): bool {
		$edition = self::edition( $feature );

		if ( null === $edition ) {
			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong( __METHOD__, esc_html( 'Unknown feature key: ' . $feature ), '1.21.0' );
			}
			return false;
		}

		if ( self::PRO !== $edition ) {
			return true;
		}

		return self::pro_available();
	}

	/**
	 * Whether the Pro add-on is active AND switched on.
	 *
	 * The Pro plugin answers the `cachingout_pro_available` filter with its own
	 * state (armed by a licence, or an install that predates the split). With
	 * no Pro plugin the filter has no callback and the default false stands.
	 * Tests may add a later callback to force either answer.
	 *
	 * @since 1.21.0
	 * @return bool
	 */
	public static function pro_available(): bool {
		if ( null === self::$pro_available ) {
			/**
			 * Filter whether Pro features are available.
			 *
			 * @since 1.21.0
			 *
			 * @param bool $available Default false: no Pro add-on.
			 */
			self::$pro_available = (bool) apply_filters( 'cachingout_pro_available', false );
		}

		return self::$pro_available;
	}

	/**
	 * Whether the Pro add-on is installed and active (whatever its state).
	 *
	 * Used by the admin to decide between "Pro is not switched on yet" and
	 * "Pro is not installed" wording. Never used to gate a feature.
	 *
	 * @since 1.21.0
	 * @return bool
	 */
	public static function pro_installed(): bool {
		return (bool) apply_filters( 'cachingout_pro_installed', false );
	}

	/**
	 * Forget the memoised Pro answer (tests; a licence validation mid-request).
	 *
	 * @since 1.21.0
	 * @return void
	 */
	public static function reset(): void {
		self::$pro_available = null;
	}

	/**
	 * Every feature key of one edition.
	 *
	 * @since 1.21.0
	 *
	 * @param string $edition 'always' | 'free' | 'pro'.
	 * @return string[]
	 */
	public static function keys( string $edition ): array {
		$keys = array();
		foreach ( self::TABLE as $key => $row ) {
			if ( $row['edition'] === $edition ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Settings keys (section.key) owned by every Pro feature that is NOT
	 * available right now. CachingOut_Settings resets these to their defaults
	 * in the effective view; the stored values are untouched.
	 *
	 * @since 1.21.0
	 * @return string[]
	 */
	public static function masked_settings(): array {
		if ( self::pro_available() ) {
			return array();
		}
		$masked = array();
		foreach ( self::TABLE as $row ) {
			if ( self::PRO === $row['edition'] ) {
				foreach ( $row['settings'] as $path ) {
					$masked[] = $path;
				}
			}
		}
		return $masked;
	}

	/**
	 * The feature that owns a settings key, or null.
	 *
	 * @since 1.21.0
	 *
	 * @param string $section Section.
	 * @param string $key     Key within the section.
	 * @return string|null Feature key.
	 */
	public static function feature_for_setting( string $section, string $key ): ?string {
		$path = $section . '.' . $key;
		foreach ( self::TABLE as $feature => $row ) {
			if ( in_array( $path, $row['settings'], true ) ) {
				return $feature;
			}
		}
		return null;
	}

	/**
	 * Human label for a feature key ('' for unknown).
	 *
	 * @since 1.21.0
	 *
	 * @param string $feature Feature key.
	 * @return string
	 */
	public static function label( string $feature ): string {
		return isset( self::TABLE[ $feature ] ) ? self::TABLE[ $feature ]['label'] : '';
	}

	/**
	 * The one link to Pro. No tracking parameters beyond the source tag.
	 *
	 * Until 1.21.10 this was `https://cachingout.com/pro/`, a page the site
	 * never had (its navigation is Home, Features, Pricing, How It Works,
	 * Docs, Blog; `/shop/` redirects to `/pricing/`). The pricing page is
	 * where Pro is described and bought, and it is the one address the
	 * plugin and the site have agreed to keep stable.
	 *
	 * @since 1.21.0
	 * @since 1.21.10 The pricing page, through CachingOut_Brand::pricing_url().
	 * @return string
	 */
	public static function pro_url(): string {
		if ( is_callable( array( 'CachingOut_Brand', 'pricing_url' ) ) ) {
			return CachingOut_Brand::pricing_url();
		}

		return 'https://cachingout.com/pricing/';
	}
}

if ( ! function_exists( 'cachingout_is_feature_available' ) ) {
	/**
	 * Procedural alias of the chokepoint (PRD Section 6).
	 *
	 * @since 1.21.0
	 *
	 * @param string $feature Feature key.
	 * @return bool
	 */
	function cachingout_is_feature_available( $feature ) {
		return CachingOut_Features::available( (string) $feature );
	}
}
