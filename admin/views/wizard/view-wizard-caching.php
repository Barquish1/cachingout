<?php
/**
 * Setup Wizard — Caching step.
 *
 * Uses CachingOut_Admin::render_toggle() so the switches match the
 * settings tabs (rs-switch sibling-track markup).
 *
 * @package CachingOut
 * @since 0.1.0
 *
 * @var array $settings Full settings array.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This view is included from a CachingOut_Admin method, so these are local variables, not globals.
$cache         = $settings['cache'];
$browser_cache = $settings['browser_cache'];
?>

<h2><?php esc_html_e( 'Configure Caching', 'cachingout' ); ?></h2>
<p class="rs-text--lead">
	<?php esc_html_e( 'Caching is the single most impactful performance optimization. These settings are pre-configured with safe defaults.', 'cachingout' ); ?>
</p>

<h3 class="rs-title-section">
	<?php CachingOut_Icons::render( 'lightning-charge' ); ?>
	<?php esc_html_e( 'Page Cache', 'cachingout' ); ?>
</h3>

<?php
CachingOut_Admin::render_toggle(
	'cache_enabled',
	__( 'Enable page caching', 'cachingout' ),
	! empty( $cache['enabled'] ),
	__( 'Serve static HTML files to anonymous visitors. This is the #1 performance improvement for most sites.', 'cachingout' )
);

CachingOut_Admin::render_number(
	'cache_lifespan',
	__( 'Cache lifespan', 'cachingout' ),
	isset( $cache['lifespan'] ) ? (int) $cache['lifespan'] : 10,
	0,
	8760,
	__( 'Hours before cached pages are regenerated. Set to 0 for no automatic expiry.', 'cachingout' )
);

CachingOut_Admin::render_toggle(
	'separate_mobile_cache',
	__( 'Separate mobile cache', 'cachingout' ),
	! empty( $cache['separate_mobile_cache'] ),
	__( 'Enable only if your theme serves different content on mobile devices.', 'cachingout' )
);
?>

<h3 class="rs-title-section">
	<?php CachingOut_Icons::render( 'globe2' ); ?>
	<?php esc_html_e( 'Browser Cache', 'cachingout' ); ?>
</h3>

<?php
CachingOut_Admin::render_toggle(
	'browser_cache_expires',
	__( 'Set far-future Expires headers', 'cachingout' ),
	! empty( $browser_cache['expires'] ),
	__( 'Instruct browsers to cache static assets (images, CSS, JavaScript) locally for up to one year. Speeds up return visits significantly.', 'cachingout' )
);

CachingOut_Admin::render_toggle(
	'browser_cache_gzip',
	__( 'Enable GZIP compression', 'cachingout' ),
	! empty( $browser_cache['gzip'] ),
	__( 'Compress text-based assets (HTML, CSS, JavaScript) before sending them to the browser. Reduces bandwidth usage and speeds up page loads.', 'cachingout' )
);

/**
 * Fires at the end of the wizard's Caching step. The Pro add-on renders the
 * preloading control here.
 *
 * @since 1.21.0
 *
 * @param array $settings Full settings array.
 */
do_action( 'cachingout_view_wizard_caching_fields', $settings );
