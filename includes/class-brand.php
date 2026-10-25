<?php
/**
 * The Caching Out mark and the public documentation URL.
 *
 * The mark is an arc that never closes (the request cycle the cache avoids)
 * and a straight bar leaving through the gap (the cached response). The
 * source geometry lives in docs/Brand/build-logo.py as two stroked paths;
 * the paths here are the same shapes as filled outlines, because WordPress
 * recolours admin-menu SVG icons by rewriting `fill` attributes and cannot
 * paint strokes. Nothing here is enqueued: every caller inlines the markup.
 *
 * @package CachingOut
 * @since   1.21.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand assets rendered inline: the mark, the admin-menu icon and the
 * documentation URL builder.
 *
 * @since 1.21.6
 */
final class CachingOut_Brand {

	/**
	 * Base URL of the public documentation. Every "Documentation" link in
	 * the plugin is built from it through docs_url().
	 *
	 * @since 1.21.6
	 * @var string
	 */
	const DOCS_URL = 'https://cachingout.com/docs/';

	/**
	 * The one place a licence is bought or renewed (site team, 2026-09-19:
	 * the site has no account or renewal page, `/shop/` redirects here, and
	 * a link rendered on the CUSTOMER'S site cannot assume a session on
	 * cachingout.com - so not `/my-account/`).
	 *
	 * @since 1.21.9
	 * @var string
	 */
	const PRICING_URL = 'https://cachingout.com/pricing/';

	/**
	 * The support address (operator, 2026-09-19). One constant so the
	 * License tab, the readme and the documentation cannot drift apart
	 * again: the published privacy page carried a placeholder address until
	 * 1.21.10.
	 *
	 * @since 1.21.10
	 * @var string
	 */
	const SUPPORT_EMAIL = 'support@cachingout.com';

	/**
	 * The arc as a filled ring segment with round caps, on the 24-unit grid.
	 *
	 * @since 1.21.6
	 * @var string
	 */
	const ARC_PATH = 'M17.306 6.547 A 8.150 8.150 0 1 0 17.306 17.453 A 0.950 0.950 0 0 0 15.894 16.182 A 6.250 6.250 0 1 1 15.894 7.818 A 0.950 0.950 0 0 0 17.306 6.547 Z';

	/**
	 * The bar as a filled stadium, on the 24-unit grid.
	 *
	 * @since 1.21.6
	 * @var string
	 */
	const BAR_PATH = 'M8.000 10.800 H 19.700 A 1.200 1.200 0 0 1 19.700 13.200 H 8.000 A 1.200 1.200 0 0 1 8.000 10.800 Z';

	/**
	 * The mark's visual bounds plus a little air: use as the viewBox where
	 * the mark must fill its box (menu and admin-bar icons).
	 *
	 * @since 1.21.6
	 * @var string
	 */
	const VIEWBOX_TIGHT = '2 2 20 20';

	/**
	 * Brand colours (docs/Brand/logo-rationale.md, "Colourways").
	 *
	 * @since 1.21.6
	 */
	const TILE        = '#080b0a'; // ink-950: the tile behind the mark.
	const ARC_ON_TILE = '#f2f5f4'; // ink-50.
	const BAR_ON_TILE = '#25bc88'; // signal-400.
	const ARC_LIGHT   = '#0e1413'; // ink-900, on light grounds.
	const BAR_LIGHT   = '#046345'; // signal-700, on light grounds.
	const MENU_BASE   = '#a7aaad'; // WordPress's base icon colour; repainted per scheme.

	/**
	 * The mark as inline SVG.
	 *
	 * @since 1.21.6
	 *
	 * @param string $arc     Fill for the arc (a colour or `currentColor`).
	 * @param string $bar     Fill for the bar.
	 * @param int    $size    Rendered width and height in pixels.
	 * @param string $viewbox SVG viewBox; the 24-unit grid by default.
	 * @param string $style   Optional inline style for the svg element.
	 * @return string SVG markup, decorative (`aria-hidden`).
	 */
	public static function mark( string $arc, string $bar, int $size = 24, string $viewbox = '0 0 24 24', string $style = '' ): string {
		$style_attr = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . esc_attr( $viewbox ) . '"'
			. ' width="' . (int) $size . '" height="' . (int) $size . '"'
			. ' aria-hidden="true" focusable="false"' . $style_attr . '>'
			. '<path fill="' . esc_attr( $arc ) . '" d="' . self::ARC_PATH . '"/>'
			. '<path fill="' . esc_attr( $bar ) . '" d="' . self::BAR_PATH . '"/>'
			. '</svg>';
	}

	/**
	 * The top-level admin-menu icon as a data URI.
	 *
	 * WordPress renders a `data:image/svg+xml;base64,` icon as an image and
	 * its svg-painter recolours every `fill` to the active admin colour
	 * scheme, which is why the paths are filled rather than stroked.
	 *
	 * @since 1.21.6
	 *
	 * @return string Value for the `$icon_url` argument of add_menu_page().
	 */
	public static function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . self::VIEWBOX_TIGHT . '">'
			. '<path fill="' . self::MENU_BASE . '" d="' . self::ARC_PATH . '"/>'
			. '<path fill="' . self::MENU_BASE . '" d="' . self::BAR_PATH . '"/>'
			. '</svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Menu icon data URI, the documented WordPress mechanism.
	}

	/**
	 * URL of a page in the public documentation.
	 *
	 * @since 1.21.6
	 *
	 * @param string $slug Page slug, e.g. `page-caching`; empty for the index.
	 *                     A `#fragment` suffix is kept as-is.
	 * @return string Absolute URL.
	 */
	public static function docs_url( string $slug = '' ): string {
		/**
		 * Filters the base URL of the public documentation.
		 *
		 * @since 1.21.6
		 *
		 * @param string $base Base URL, with a trailing slash.
		 */
		$base = (string) apply_filters( 'cachingout_docs_url', self::DOCS_URL );
		$base = trailingslashit( $base );

		$slug = trim( $slug, '/' );
		if ( '' === $slug ) {
			return $base;
		}

		$fragment = '';
		$hash     = strpos( $slug, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $slug, $hash );
			$slug     = rtrim( substr( $slug, 0, $hash ), '/' );
		}

		return $base . $slug . '/' . $fragment;
	}

	/**
	 * URL of the page where a licence is bought or renewed.
	 *
	 * @since 1.21.9
	 *
	 * @return string Absolute URL, with a trailing slash.
	 */
	public static function pricing_url(): string {
		/**
		 * Filters the URL of the pricing / renewal page.
		 *
		 * @since 1.21.9
		 *
		 * @param string $url Absolute URL.
		 */
		$url = (string) apply_filters( 'cachingout_pricing_url', self::PRICING_URL );

		return '' === trim( $url ) ? self::PRICING_URL : trailingslashit( $url );
	}

	/**
	 * Default name of the query argument that carries a partner code on the
	 * Upgrade link (Partner Engine FR-ATT-07).
	 *
	 * @since 1.21.16
	 * @var string
	 */
	const UPGRADE_REF_PARAM = 'ref';

	/**
	 * Name of the query argument that carries a partner code on the Upgrade
	 * link. Anything outside [A-Za-z0-9_-] falls back to the default.
	 *
	 * @since 1.21.16
	 *
	 * @return string Parameter name.
	 */
	public static function upgrade_ref_param(): string {
		/**
		 * Filters the query-argument name the Upgrade link uses for a partner code.
		 *
		 * @since 1.21.16
		 *
		 * @param string $param Parameter name. Default 'ref'.
		 */
		$param = apply_filters( 'cachingout_upgrade_ref_param', self::UPGRADE_REF_PARAM );

		if ( ! is_string( $param ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $param ) ) {
			return self::UPGRADE_REF_PARAM;
		}

		return $param;
	}

	/**
	 * A partner code as typed or pasted, accepted only when it is 1-64
	 * characters of [A-Za-z0-9_-] once surrounding whitespace is trimmed.
	 * Anything else answers '' (nothing is sent) rather than an altered code
	 * that could credit someone else.
	 *
	 * @since 1.21.16
	 *
	 * @param string $code Raw code.
	 * @return string The code, or '' when it is not acceptable.
	 */
	public static function sanitize_partner_code( string $code ): string {
		$code = trim( $code );

		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $code ) ? $code : '';
	}

	/**
	 * The Upgrade link: the direct pricing URL, with a partner code added as
	 * a query argument only when one is given and acceptable. No redirect
	 * or intermediary. The Pro screen renders the bare URL and its script
	 * adds the code at click time with the same rules
	 * (admin/assets/js/partner-code.js).
	 *
	 * @since 1.21.16
	 *
	 * @param string $partner_code Optional partner code, as typed.
	 * @return string Absolute URL.
	 */
	public static function upgrade_url( string $partner_code = '' ): string {
		$url  = trailingslashit( self::pricing_url() );
		$code = self::sanitize_partner_code( $partner_code );

		if ( '' === $code ) {
			return $url;
		}

		return add_query_arg( self::upgrade_ref_param(), $code, $url );
	}
}
