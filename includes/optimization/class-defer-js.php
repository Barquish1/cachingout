<?php
/**
 * JavaScript defer attribute injection.
 *
 * Adds the defer attribute to <script src="..."> tags so they don't
 * block page rendering. Skips scripts that already have defer/async,
 * module scripts, inline scripts, and excluded scripts.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JS defer handler.
 *
 * Injects defer="defer" into <script src="..."> tags that lack async,
 * defer, or type="module". jQuery and jQuery-migrate are excluded by
 * default because deferring them breaks inline scripts that depend on
 * $ being defined synchronously.
 *
 * @since 0.1.0
 */
class CachingOut_Defer_JS {

	/**
	 * Plugin settings instance.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Shared exclusions registry.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Optimization_Exclusions
	 */
	private $exclusions;

	/**
	 * Constructor.
	 *
	 * Registers hardcoded jQuery / jQuery-migrate exclusions.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings               $settings   Settings instance.
	 * @param CachingOut_Optimization_Exclusions $exclusions Shared exclusions.
	 */
	public function __construct( CachingOut_Settings $settings, CachingOut_Optimization_Exclusions $exclusions ) {
		$this->settings   = $settings;
		$this->exclusions = $exclusions;

		// Hardcoded: deferring jQuery breaks inline $ usage.
		$this->exclusions->add_hardcoded_pattern( 'defer_js', '/jquery.js' );
		$this->exclusions->add_hardcoded_pattern( 'defer_js', '/jquery-migrate.min.js' );
		$this->exclusions->add_hardcoded_pattern( 'defer_js', '/jquery.min.js' );
	}


	/**
	 * Output buffer callback — adds defer to eligible script tags.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html Full page HTML.
	 * @return string Transformed HTML.
	 */
	public function filter( $html ) {
		if ( '' === $html ) {
			return $html;
		}

		// BUG-013: pipeline always invokes filter(); must re-check the toggle.
		if ( ! $this->settings->get( 'file_optimization', 'defer_js', false ) ) {
			return $html;
		}

		return preg_replace_callback(

			// BUG-017b: (?<![\w-]) so that src never matches inside data-*-src.
			'/<script\s[^>]*?(?<![\w-])src\s*=\s*["\'][^"\']+["\'][^>]*>/si',
			array( $this, 'transform_script' ),
			$html
		);
	}

	/**
	 * Transform a single <script src="..."> tag — add defer if eligible.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex match: [0] = full tag.
	 * @return string Transformed tag.
	 */
	private function transform_script( $matches ) {
		$tag = $matches[0];

		// Skip if already has defer or async.
		if ( preg_match( '/\b(?:defer|async)\b/i', $tag ) ) {
			return $tag;
		}

		// Skip type="module" scripts.
		if ( preg_match( '/\btype\s*=\s*["\']module["\']/i', $tag ) ) {
			return $tag;
		}

		// Extract src URL.
		if ( ! preg_match( '/(?<![\w-])src\s*=\s*["\']([^"\']+)["\']/i', $tag, $src_match ) ) {
			return $tag;
		}

		$src = $src_match[1];

		// Skip excluded scripts. Minify JS (pipeline 11) has already
		// rewritten the src of an unminified file to a hashed name, so a
		// user pattern such as "swiper" is also tested against the tag's id
		// (WordPress writes id="<handle>-js" and the minifier leaves it)
		// - 1.21.6, the same fix as Pro Delay JS.
		if ( $this->exclusions->is_excluded( $src, 'defer_js' ) ) {
			return $tag;
		}
		if ( preg_match( '/(?<![\w-])id\s*=\s*["\']([^"\']+)["\']/i', $tag, $id_match )
			&& $this->exclusions->is_excluded( $id_match[1], 'defer_js' ) ) {
			return $tag;
		}

		// Inject defer before the closing >.
		$tag = preg_replace( '/\s*\/?\s*>$/i', ' defer="defer"$0', $tag, 1 );

		return $tag;
	}

}