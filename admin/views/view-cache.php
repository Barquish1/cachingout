<?php
/**
 * Cache settings tab view (free edition).
 *
 * Renders the free cache section: enable/disable, lifespan, mobile cache
 * toggle, filesystem check and the ignored-query-parameter list.
 *
 * The Pro add-on renders the change record and the custom exclusion lists
 * into the two action slots below (spec 30); with Pro absent nothing is
 * shown in their place - no placeholder, no greyed field.
 *
 * @package CachingOut
 * @since 0.1.0
 * @since 1.21.0 Free fields only; Pro fields arrive through the view slots.
 *
 * @var array $settings Full settings array from CachingOut_Settings::get_all().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$cache = $settings['cache'];
?>

<?php CachingOut_Cache_Status::render( $settings ); ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-form">
	<?php wp_nonce_field( 'cachingout_save_settings', 'cachingout_nonce' ); ?>
	<input type="hidden" name="action" value="cachingout_save_settings">
	<input type="hidden" name="cachingout_tab" value="cache">

	<h2 class="rs-title-section"><?php esc_html_e( 'Page Cache', 'cachingout' ); ?></h2>

	<div>
		<?php
		CachingOut_Admin::render_toggle(
			'cache_enabled',
			__( 'Enable page caching', 'cachingout' ),
			$cache['enabled'],
			__( 'Serve static HTML files to anonymous visitors for dramatically faster page loads.', 'cachingout' )
		);

		CachingOut_Admin::render_number(
			'cache_lifespan',
			__( 'Cache lifespan', 'cachingout' ),
			$cache['lifespan'],
			0,
			8760,
			__( 'Hours before cached page files are regenerated. Set to 0 for no automatic expiry. Max 8760 (1 year).', 'cachingout' )

		);

		CachingOut_Admin::render_toggle(
			'separate_mobile_cache',
			__( 'Separate mobile cache', 'cachingout' ),
			$cache['separate_mobile_cache'],
			__( 'Generate separate cache files for mobile visitors. Enable only if your theme serves different markup on mobile.', 'cachingout' )
		);

		CachingOut_Admin::render_toggle(
			'fs_check_enabled',
			__( 'Filesystem check', 'cachingout' ),
			isset( $cache['fs_check_enabled'] ) ? $cache['fs_check_enabled'] : true,
			__( 'Verify that every file the plugin needs (drop-in, WP_CACHE, cache directory, settings snapshot) is present and healthy. Results appear in the admin notice after install or update and on the Filesystem Check page.', 'cachingout' )
		);

		/**
		 * Fires inside the Page Cache block, after the free fields. The Pro
		 * add-on renders the change-record controls here.
		 *
		 * @since 1.21.0
		 *
		 * @param array $settings Full settings array.
		 */
		do_action( 'cachingout_view_cache_after_lifespan', $settings );

		CachingOut_Admin::render_textarea(
			'cache_query_strings',
			__( 'Ignored query parameters', 'cachingout' ),
			$cache['cache_query_strings'],
			__( 'e.g. utm_source, fbclid', 'cachingout' ),
			__( 'Query parameters that do not change the page. A URL whose parameters are ALL on this list is served the same cached page as the plain URL; the address in the browser is left as it is, so tracking pixels still read it. Prefilled with the tracking parameters social networks, ad platforms and campaign links append (fbclid, igsh, gclid, ttclid, utm_* and others). A trailing * matches every name with that beginning. Remove a name if a plugin on your site reads that parameter on the server. Do not add a parameter that changes what the page shows - a URL with a parameter that is not listed is never served from cache. One per line.', 'cachingout' )
		);
		?>
		<div class="rs-field">
			<div class="rs-field__label"></div>
			<div class="rs-field__control">
				<a class="rs-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cachingout_restore_query_strings' ), 'cachingout_restore_query_strings' ) ); ?>">
					<?php esc_html_e( 'Restore default parameters', 'cachingout' ); ?>
				</a>
				<p class="rs-help"><?php esc_html_e( 'Adds back every default you removed and keeps the names you added. Saves at once.', 'cachingout' ); ?></p>
			</div>
		</div>
	</div>

	<h2 class="rs-title-section"><?php esc_html_e( 'Never Cache', 'cachingout' ); ?></h2>

	<div>
		<?php
		// Additions to the built-in safety exclusions (logged-in visitors,
		// session and auth cookies, cart / checkout / account, wp-admin,
		// wp-login, REST, AJAX, feeds and sitemaps), which apply regardless.
		CachingOut_Admin::render_textarea(
			'never_cache_urls',
			__( 'Never cache these URLs', 'cachingout' ),
			isset( $cache['never_cache_urls'] ) ? $cache['never_cache_urls'] : array(),
			__( 'e.g. /members/, /quote/*', 'cachingout' ),
			__( 'Exact paths or partial matches, * as a wildcard. One per line. The cart, checkout and account pages are excluded automatically for WooCommerce and EDD sites.', 'cachingout' )
		);

		CachingOut_Admin::render_textarea(
			'never_cache_cookies',
			__( 'Never cache when these cookies are present', 'cachingout' ),
			isset( $cache['never_cache_cookies'] ) ? $cache['never_cache_cookies'] : array(),
			__( 'e.g. my_ab_test, member_*', 'cachingout' ),
			__( 'One cookie name per line, * as a wildcard. A visitor carrying any of them always sees the live page.', 'cachingout' )
		);

		CachingOut_Admin::render_textarea(
			'never_cache_useragents',
			__( 'Never cache for these user agents', 'cachingout' ),
			isset( $cache['never_cache_useragents'] ) ? $cache['never_cache_useragents'] : array(),
			__( 'e.g. Googlebot, facebookexternalhit', 'cachingout' ),
			__( 'One user-agent substring per line, matched case-insensitively. Matching visitors always see the live page.', 'cachingout' )
		);
		?>
	</div>

	<?php
	/**
	 * Fires after the Never Cache block. The custom exclusion lists are
	 * rendered by the free plugin since 1.21.16; an add-on may append its
	 * own cache-tab fields here.
	 *
	 * @since 1.21.0
	 *
	 * @param array $settings Full settings array.
	 */
	do_action( 'cachingout_view_cache_never_cache', $settings );
	?>

	<p class="submit">
		<button type="submit" class="rs-button rs-button--primary"><?php esc_html_e( 'Save Changes', 'cachingout' ); ?></button>
	</p>
</form>