<?php
/**
 * Page builder compatibility — registers automatic cache exclusions for
 * builder edit/preview modes.
 *
 * All caching decisions route through CachingOut_Cacheability via the
 * register_exclusion() API. This module never contains independent
 * cache-or-not conditionals.
 *
 * @package CachingOut
 * @since 0.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page builder compat module.
 *
 * Registers query-arg exclusions for popular page builders so that edit and
 * preview modes are never served from cache, even when the user has added
 * query strings to the cache allow-list.
 *
 * @since 0.1.1
 */
class CachingOut_Page_Builders {

	/**
	 * Cacheability chokepoint.
	 *
	 * @since 0.1.1
	 * @var CachingOut_Cacheability
	 */
	private $cacheability;

	/**
	 * Constructor.
	 *
	 * @since 0.1.1
	 *
	 * @param CachingOut_Cacheability $cacheability Cacheability chokepoint.
	 */
	public function __construct( CachingOut_Cacheability $cacheability ) {
		$this->cacheability = $cacheability;
	}

	/**
	 * Register all page-builder exclusions.
	 *
	 * Must be called at `init` priority 10 or later.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_query_arg_exclusions();
	}

	/**
	 * Query arguments that mark a page-builder edit or preview request.
	 *
	 * @since 0.1.18 (extracted; the list is unchanged since 0.1.1)
	 * @var string[]
	 */
	const BUILDER_QUERY_ARGS = array(
		'elementor-preview',  // Elementor
		'fl_builder',         // Beaver Builder
		'et_fb',              // Divi
		'bricks',             // Bricks Builder
		'vc_editable',        // WPBakery / Visual Composer
		'ct_builder',         // Oxygen Builder
		'tve',                // Thrive Architect
		'preview',            // Generic preview (Gutenberg, etc.)
		'preview_id',         // Generic preview ID
		'preview_nonce',      // Generic preview nonce
	);

	/**
	 * Whether the current request is a builder edit / preview context
	 * (spec 25: asset unloading must never run there, because builders
	 * load frontend styles to render their previews).
	 *
	 * @since 0.1.18
	 * @return bool
	 */
	public static function is_edit_context(): bool {
		foreach ( self::BUILDER_QUERY_ARGS as $arg ) {
			if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
				return true;
			}
		}
		return false;
	}

	/**
	 * Register query-argument-based exclusions for builder preview modes.
	 *
	 * These query args indicate a page builder is in edit or preview mode.
	 * Even if the user has added query strings to the cache allow-list,
	 * these must always bypass the cache.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	private function register_query_arg_exclusions(): void {
		foreach ( self::BUILDER_QUERY_ARGS as $arg ) {
			$this->cacheability->register_exclusion(
				'builder_' . $arg,
				function ( string $url ) use ( $arg ): bool {
					$query_string = wp_parse_url( $url, PHP_URL_QUERY );
					if ( null === $query_string || false === $query_string || '' === $query_string ) {
						return false;
					}

					parse_str( $query_string, $query_args );
					return array_key_exists( $arg, $query_args );
				},
				'page-builder:' . $arg
			);
		}
	}
}