<?php
/**
 * Per-page asset unloading: the handle catalogue (spec 25).
 *
 * The fixed handle-to-marker map for well-known plugins. Pure data plus
 * lookup: a handle pattern names the plugin's assets, the markers are the
 * substrings that mean "this page uses the plugin", and `never_suggest`
 * flags an entry the scanner must never propose a rule for (Complianz,
 * OD-11: a consent banner is legally load-bearing on every page).
 *
 * Used by the Page Audit `plugin-assets-unused-on-page` finding in Phase 1
 * and by the suggestion engine in Phase 2.
 *
 * @package CachingOut
 * @since 0.1.18
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle catalogue.
 *
 * @since 0.1.18
 */
class CachingOut_Asset_Catalogue {

	/**
	 * Markers that mean "this page renders WooCommerce markup".
	 *
	 * Deliberately NOT the bare word "woocommerce": WooCommerce and most
	 * themes print it in inline styles and body classes on every page (a
	 * theme's customizer CSS for `.woocommerce ul.products`, the
	 * `woocommerce-no-js` class), which made every page read as a shop page
	 * and left nothing to suggest (orgahair.pl, 2026-09-08). These are the
	 * classes WooCommerce puts on shop, product, cart, checkout and account
	 * markup, on its blocks, and on an add-to-cart button or a mini-cart.
	 *
	 * @since 1.21.3
	 * @var string[]
	 */
	const WOOCOMMERCE_MARKERS = array(
		'woocommerce-page',
		'woocommerce-cart',
		'woocommerce-checkout',
		'woocommerce-account',
		'woocommerce-MyAccount',
		'single-product',
		'woocommerce-loop-product',
		'add_to_cart_button',
		'widget_shopping_cart',
		'wc-block-',
		'wp-block-woocommerce',
	);

	/**
	 * Catalogue entries: `handle pattern => [label, markers[], never_suggest, why]`.
	 *
	 * A pattern ending in `*` is a prefix match; otherwise it is exact.
	 * Markers are matched case-insensitively against SCRIPT-MASKED HTML
	 * (CachingOut_Script_Guard, BUG-020), so a class name quoted inside a
	 * JS template never counts as usage.
	 *
	 * @var array<string,array{label:string,markers:string[],never_suggest:bool,why:string}>
	 */
	const ENTRIES = array(
		'wc-blocks-style*'        => array(
			'label'         => 'WooCommerce Blocks',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Block styles are only needed where a WooCommerce block renders.',
		),
		'wc-block-*'              => array(
			'label'         => 'WooCommerce Blocks',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Block styles are only needed where a WooCommerce block renders.',
		),
		'woocommerce-general'     => array(
			'label'         => 'WooCommerce',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Shop styles are only needed on shop, product, cart, checkout and account pages.',
		),
		'woocommerce-layout'      => array(
			'label'         => 'WooCommerce',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Shop styles are only needed on shop, product, cart, checkout and account pages.',
		),
		'woocommerce-smallscreen' => array(
			'label'         => 'WooCommerce',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Shop styles are only needed on shop, product, cart, checkout and account pages.',
		),
		'wc-cart-fragments'       => array(
			'label'         => 'WooCommerce cart fragments',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'The cart-fragments script polls the server for the mini-cart; pages without one do not need it.',
		),
		'woocommerce'             => array(
			'label'         => 'WooCommerce',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'The front-end script drives shop, cart and checkout behaviour; pages without WooCommerce markup do not need it.',
		),
		'wc-add-to-cart'          => array(
			'label'         => 'WooCommerce',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Add-to-cart behaviour is only needed where a product or an add-to-cart button renders.',
		),
		'wc-jquery-blockui'       => array(
			'label'         => 'WooCommerce',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'The overlay helper is only needed where a cart, checkout or add-to-cart action can run.',
		),
		'wc-js-cookie'            => array(
			'label'         => 'WooCommerce',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'The cookie helper is only needed where a cart action can run.',
		),
		'sourcebuster-js'         => array(
			'label'         => 'WooCommerce order attribution',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Order attribution records where an order came from; pages that cannot start an order do not need it.',
		),
		'wc-order-attribution'    => array(
			'label'         => 'WooCommerce order attribution',
			'markers'       => self::WOOCOMMERCE_MARKERS,
			'never_suggest' => false,
			'why'           => 'Order attribution records where an order came from; pages that cannot start an order do not need it.',
		),
		'tss'                     => array(
			'label'         => 'Testimonial Slider and Showcase',
			'markers'       => array( 'tss-', 'testimonial-slider' ),
			'never_suggest' => false,
			'why'           => 'Slider styles and scripts are only needed on pages that show a testimonial slider or showcase.',
		),
		'tss-*'                   => array(
			'label'         => 'Testimonial Slider and Showcase',
			'markers'       => array( 'tss-', 'testimonial-slider' ),
			'never_suggest' => false,
			'why'           => 'Slider styles and scripts are only needed on pages that show a testimonial slider or showcase.',
		),
		'contact-form-7'          => array(
			'label'         => 'Contact Form 7',
			'markers'       => array( 'wpcf7' ),
			'never_suggest' => false,
			'why'           => 'Form styles and scripts are only needed on pages that render a form.',
		),
		'mailpoet_public*'        => array(
			'label'         => 'MailPoet',
			'markers'       => array( 'mailpoet_form', 'class="mailpoet' ),
			'never_suggest' => false,
			'why'           => 'Subscription-form assets are only needed where a form renders.',
		),
		'wpforms-full'            => array(
			'label'         => 'WPForms',
			'markers'       => array( 'wpforms-form', 'wpforms-container' ),
			'never_suggest' => false,
			'why'           => 'Form styles are only needed on pages that render a form.',
		),
		'gform_*'                 => array(
			'label'         => 'Gravity Forms',
			'markers'       => array( 'gform_wrapper' ),
			'never_suggest' => false,
			'why'           => 'Form styles are only needed on pages that render a form.',
		),
		'elementor-post-*'        => array(
			'label'         => 'Elementor',
			'markers'       => array( 'elementor-' ),
			'never_suggest' => false,
			'why'           => 'Per-post Elementor styles belong to pages built with Elementor.',
		),
		'elementor-frontend'      => array(
			'label'         => 'Elementor',
			'markers'       => array( 'elementor-' ),
			'never_suggest' => false,
			'why'           => 'The Elementor frontend bundle is only needed on pages built with Elementor.',
		),
		'jetpack_css'             => array(
			'label'         => 'Jetpack',
			'markers'       => array( 'sharedaddy', 'jp-' ),
			'never_suggest' => false,
			'why'           => 'Jetpack module styles are only needed where a module renders.',
		),
		'sharedaddy'              => array(
			'label'         => 'Jetpack sharing',
			'markers'       => array( 'sharedaddy', 'jp-' ),
			'never_suggest' => false,
			'why'           => 'Sharing buttons render on posts, not on every page.',
		),
		'rs-plugin-settings'      => array(
			'label'         => 'Slider Revolution',
			'markers'       => array( 'rev_slider' ),
			'never_suggest' => false,
			'why'           => 'Slider styles are only needed on pages with a slider.',
		),
		'rbtools'                 => array(
			'label'         => 'Slider Revolution',
			'markers'       => array( 'rev_slider' ),
			'never_suggest' => false,
			'why'           => 'Slider scripts are only needed on pages with a slider.',
		),
		'dashicons'               => array(
			'label'         => 'Dashicons',
			'markers'       => array( 'dashicons-', 'id="wpadminbar"' ),
			'never_suggest' => false,
			'why'           => 'Needed the moment a logged-in user with an admin bar arrives; safe for anonymous pages only because logged-in users always bypass the page cache.',
		),
		'cmplz-general'           => array(
			'label'         => 'Complianz',
			'markers'       => array( 'cmplz' ),
			'never_suggest' => true,
			'why'           => 'A consent banner is legally load-bearing and can appear on any page until consent is given; an unstyled banner is a compliance incident, not a layout bug.',
		),
		'cmplz-*'                 => array(
			'label'         => 'Complianz',
			'markers'       => array( 'cmplz' ),
			'never_suggest' => true,
			'why'           => 'A consent banner is legally load-bearing and can appear on any page until consent is given; an unstyled banner is a compliance incident, not a layout bug.',
		),
	);

	/**
	 * The catalogue after the `cachingout_asset_unload_catalogue` filter
	 * (add entries; malformed additions are ignored).
	 *
	 * @return array<string,array>
	 */
	public static function entries(): array {
		$entries  = self::ENTRIES;
		$filtered = function_exists( 'apply_filters' ) ? apply_filters( 'cachingout_asset_unload_catalogue', $entries ) : $entries;
		if ( ! is_array( $filtered ) ) {
			return $entries;
		}
		$out = array();
		foreach ( $filtered as $pattern => $entry ) {
			if ( ! is_string( $pattern ) || '' === $pattern || ! is_array( $entry ) || empty( $entry['markers'] ) || ! is_array( $entry['markers'] ) ) {
				continue;
			}
			$out[ $pattern ] = array(
				'label'         => isset( $entry['label'] ) ? (string) $entry['label'] : $pattern,
				'markers'       => array_values( array_filter( array_map( 'strval', $entry['markers'] ) ) ),
				'never_suggest' => ! empty( $entry['never_suggest'] ),
				'why'           => isset( $entry['why'] ) ? (string) $entry['why'] : '',
			);
		}
		// A filter can add or edit entries but cannot drop the refusals.
		foreach ( self::ENTRIES as $pattern => $entry ) {
			if ( $entry['never_suggest'] ) {
				$out[ $pattern ]                  = isset( $out[ $pattern ] ) ? $out[ $pattern ] : $entry;
				$out[ $pattern ]['never_suggest'] = true;
			}
		}
		return $out;
	}

	/**
	 * The catalogue entry for a handle, or null when it is not catalogued.
	 *
	 * @param string $handle Registered handle.
	 * @return array|null Entry plus its `pattern`.
	 */
	public static function lookup( string $handle ): ?array {
		$handle = strtolower( $handle );
		if ( '' === $handle ) {
			return null;
		}
		foreach ( self::entries() as $pattern => $entry ) {
			$p = strtolower( $pattern );
			if ( '*' === substr( $p, -1 ) ) {
				if ( 0 === strpos( $handle, substr( $p, 0, -1 ) ) ) {
					$entry['pattern'] = $pattern;
					return $entry;
				}
			} elseif ( $p === $handle ) {
				$entry['pattern'] = $pattern;
				return $entry;
			}
		}
		return null;
	}

	/**
	 * The part of a page markers may be searched in.
	 *
	 * Script bodies are masked (BUG-020: a class name quoted in a JS
	 * template is not usage), `<style>` and `<noscript>` bodies are removed
	 * (1.21.3: a theme's customizer CSS naming `.woocommerce ul.products`
	 * and WooCommerce's own noscript style put the marker on every page),
	 * and link/script tags go too, so an asset's own URL never counts.
	 * Shared by the suggestion engine and the Page Audit finding so the two
	 * can never disagree about what "in use" means.
	 *
	 * @since 1.21.3
	 *
	 * @param string $html Full page HTML.
	 * @return string
	 */
	public static function searchable_html( string $html ): string {
		if ( '' === $html ) {
			return '';
		}
		$masked = class_exists( 'CachingOut_Script_Guard' ) ? CachingOut_Script_Guard::mask( $html ) : $html;
		$masked = (string) preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $masked );
		$masked = (string) preg_replace( '/<noscript\b[^>]*>.*?<\/noscript>/is', '', $masked );
		return (string) preg_replace( '/<(?:link|script)\b[^>]*>/i', '', $masked );
	}

	/**
	 * Whether any of an entry's markers appears in the (script-masked) HTML.
	 *
	 * @param string $masked_html HTML with script bodies blanked.
	 * @param array  $entry       Catalogue entry.
	 * @return bool
	 */
	public static function in_use( string $masked_html, array $entry ): bool {
		foreach ( (array) ( isset( $entry['markers'] ) ? $entry['markers'] : array() ) as $marker ) {
			if ( '' !== $marker && false !== stripos( $masked_html, $marker ) ) {
				return true;
			}
		}
		return false;
	}
}
