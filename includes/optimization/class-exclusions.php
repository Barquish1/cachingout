<?php
/**
 * Shared exclusion pattern matching for all optimization sub-modules.
 *
 * Maintains a registry of hardcoded and user-configured exclusion patterns.
 * Every optimization class that needs per-file exclusions queries this
 * single instance rather than re-implementing its own matching.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared exclusion registry.
 *
 * Reads the user-configured exclusion lists from the file_optimization
 * settings section. Optimization classes may also register hardcoded
 * patterns at construction (e.g. jQuery for defer/delay).
 *
 * @since 0.1.0
 */
class CachingOut_Optimization_Exclusions {

	/**
	 * Stylesheet URL fragments that must never be deferred.
	 *
	 * These libraries lay out a component that is already in the markup.
	 * Until their CSS applies the component renders unstyled - a carousel's
	 * slides stack vertically instead of sitting in a flex row - and then
	 * collapses to its real height the moment the stylesheet arrives. The
	 * page below it jumps, and the result is a large layout shift that no
	 * reserved height can absorb, because the box is too TALL beforehand,
	 * not too short.
	 *
	 * Measured on a live site: a Swiper carousel with five slides stacked
	 * roughly 2,000px tall before the deferred stylesheet landed, then
	 * collapsed to 592px - CLS 0.44 on its own.
	 *
	 * This is the CSS counterpart of the Delay JS dependency exclusions in
	 * CachingOut_Delay_JS. Registered here rather than by either consumer
	 * because BOTH Optimize CSS delivery and Combine CSS need them: a
	 * layout-critical sheet merged into a bundle would lose its identity and
	 * the bundle would then be deferred. Matched case-insensitively as
	 * substrings of the stylesheet URL, under the 'critical_css' type.
	 *
	 * @since 0.1.17
	 * @var string[]
	 */
	const LAYOUT_CRITICAL_PATTERNS = array(
		'swiper',
		'slick',
		'owl.carousel',
		'owl-carousel',
		'flickity',
		'splide',
		'glide.core',
		'glidejs',
		'tiny-slider',
		'tns.css',
		'flexslider',
		'lightslider',
		'keen-slider',
		'royalslider',
		'nivo-slider',
		// WordPress core block library (classic themes load it as one file):
		// it lays out every block on the page, so deferring it restacks
		// Columns, Cover and Buttons when it lands (spec 29 panel, 2026-09-05).
		'css/dist/block-library/style',
	);

	/**
	 * Hardcoded exclusion patterns added by optimization classes.
	 *
	 * @since 0.1.0
	 * @var array<string, array<string>>
	 */
	private $hardcoded_patterns = array();

	/**
	 * Plugin settings instance.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings $settings Settings instance.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;

		// Shared by Optimize CSS delivery and Combine CSS, so registered
		// centrally rather than by whichever consumer happens to be built
		// first.
		foreach ( self::LAYOUT_CRITICAL_PATTERNS as $pattern ) {
			$this->add_hardcoded_pattern( 'critical_css', $pattern );
		}
	}

	/**
	 * Add a hardcoded exclusion pattern.
	 *
	 * Called by optimization classes at construction to register built-in
	 * exclusions (e.g. jQuery, Google Analytics, Stripe).
	 *
	 * @since 0.1.0
	 *
	 * @param string $type    'js' or 'css' — which file type this pattern applies to.
	 * @param string $pattern Substring to match in the file URL (case-insensitive).
	 * @return void
	 */
	public function add_hardcoded_pattern( $type, $pattern ) {
		if ( ! isset( $this->hardcoded_patterns[ $type ] ) ) {
			$this->hardcoded_patterns[ $type ] = array();
		}
		$this->hardcoded_patterns[ $type ][] = $pattern;
	}

	/**
	 * Whether a file URL should be excluded from optimization.
	 *
	 * Matches against both hardcoded patterns (registered at construction)
	 * and user-configured patterns from the file_optimization settings
	 * section.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url  Absolute or relative file URL.
	 * @param string $type 'js', 'css', or a scoped consumer type
	 *                     ('delay_js', 'defer_js', 'critical_css') whose
	 *                     HARDCODED patterns are private to that consumer
	 *                     (audit N16). User lists stay shared by FILE TYPE:
	 *                     any type containing 'css' honours excluded_css,
	 *                     everything else honours excluded_js.
	 * @return bool True when the URL matches any exclusion.
	 */
	public function is_excluded( $url, $type ) {
		$url_lower = mb_strtolower( $url );

		// Check hardcoded patterns.
		if ( isset( $this->hardcoded_patterns[ $type ] ) ) {
			foreach ( $this->hardcoded_patterns[ $type ] as $pattern ) {
				if ( false !== mb_strpos( $url_lower, mb_strtolower( $pattern ) ) ) {
					return true;
				}
			}
		}

		// User-configured lists stay shared across every consumer of a file
		// type; only HARDCODED patterns are scoped per consumer type (audit
		// N16: delay/defer's built-in protections used to leak into
		// minify/combine decisions through the shared 'js' bucket).
		// Route the user list by FILE type, not by consumer. A scoped CSS
		// consumer such as 'critical_css' must read excluded_css; matching
		// only the bare string 'css' silently sent it to excluded_js.
		$user_key        = ( false !== mb_strpos( $type, 'css' ) ) ? 'excluded_css' : 'excluded_js';
		$user_exclusions = $this->settings->get( 'file_optimization', $user_key, array() );
		foreach ( $user_exclusions as $excluded ) {
			if ( false !== mb_strpos( $url_lower, mb_strtolower( $excluded ) ) ) {
				return true;
			}
		}

		return false;
	}

	// ------------------------------------------------------------------
	// Spec 29 level 2: theme stylesheet protection.
	// ------------------------------------------------------------------

	/**
	 * Why Optimize CSS delivery must leave a stylesheet render-blocking.
	 *
	 * Checked in this order: the built-in layout-critical list
	 * (LAYOUT_CRITICAL_PATTERNS and any other hardcoded 'critical_css'
	 * pattern, reason 'builtin'), the user's Excluded CSS setting, then the active theme's
	 * stylesheets. `is_excluded( $href, 'critical_css' )` is the first two
	 * of these and stays the gate Combine CSS uses; the theme rule is
	 * deliberately NOT a combine exclusion (a theme sheet may be bundled;
	 * the bundle inherits the protection through the Combine manifest).
	 *
	 * @since 0.1.21
	 *
	 * @param string $href Stylesheet URL as it appears in the page.
	 * @return string 'builtin', 'excluded', 'theme', or '' when it may be deferred.
	 */
	public function protection_reason( string $href ): string {
		// A served href may be a minified or combined copy whose hashed name
		// says nothing; match the names it stands for as well (1.21.3).
		$names = array();
		foreach ( self::original_hrefs( $href ) as $original ) {
			$names[] = mb_strtolower( $original );
		}

		if ( isset( $this->hardcoded_patterns['critical_css'] ) ) {
			foreach ( $this->hardcoded_patterns['critical_css'] as $pattern ) {
				$needle = mb_strtolower( $pattern );
				foreach ( $names as $name ) {
					if ( false !== mb_strpos( $name, $needle ) ) {
						return 'builtin';
					}
				}
			}
		}

		foreach ( $this->settings->get( 'file_optimization', 'excluded_css', array() ) as $excluded ) {
			if ( '' === (string) $excluded ) {
				continue;
			}
			$needle = mb_strtolower( (string) $excluded );
			foreach ( $names as $name ) {
				if ( false !== mb_strpos( $name, $needle ) ) {
					return 'excluded';
				}
			}
		}

		if ( $this->is_theme_stylesheet( $href ) ) {
			return 'theme';
		}

		return '';
	}

	/**
	 * The hrefs a served stylesheet stands for: itself, the original of a
	 * minified copy (Minify CSS manifest), and the sources of a combined
	 * file (Combine manifest), each of those resolved through the minify
	 * manifest in turn (Combine runs after Minify, so a bundle's sources may
	 * themselves be minified copies). A generated file with no manifest
	 * entry stands for itself only.
	 *
	 * @since 1.21.3
	 *
	 * @param string $href Stylesheet URL as it appears in the page.
	 * @return string[] Distinct hrefs, the served one first.
	 */
	public static function original_hrefs( string $href ): array {
		$out = array( $href );

		$candidates = array( $href );
		if ( class_exists( 'CachingOut_Combine' ) && CachingOut_Combine::is_combined_url( $href ) ) {
			foreach ( CachingOut_Combine::sources_for( $href ) as $source ) {
				$candidates[] = (string) $source;
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			if ( ! in_array( $candidate, $out, true ) ) {
				$out[] = $candidate;
			}
			if ( class_exists( 'CachingOut_Minified_Files' ) && CachingOut_Minified_Files::is_minified_css_url( $candidate ) ) {
				$source = CachingOut_Minified_Files::source_for( $candidate );
				if ( null !== $source && '' !== $source && ! in_array( $source, $out, true ) ) {
					$out[] = $source;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a stylesheet belongs to the active theme and lays out the page.
	 *
	 * True when the href resolves to a readable file under the child theme
	 * directory (`get_stylesheet_directory()`) or the parent theme directory
	 * (`get_template_directory()`), whatever its name or size: the panel
	 * (2026-09-05) showed a theme whose layout lives in three small files
	 * (Hello Elementor) shifting by 0.15 when they were deferred, so the
	 * 20 KB floor first proposed was dropped. A combined file
	 * (`cache/cachingout/combined/<hash>.css`) is a theme stylesheet when
	 * any of the sources recorded in the Combine manifest is; a combined
	 * file with no manifest entry (generated before 0.1.21) is not, and
	 * the level 1 coverage guard decides for it.
	 *
	 * Why: on orgahair.com (0.1.20) the theme's main stylesheet held every
	 * layout rule for the top of the page and was deferred behind a 3 KB
	 * heuristic critical CSS - CLS 1.0. The active theme's stylesheet is
	 * the one file that is near-certain to lay out markup already on the
	 * page, so it is protected by name as well as by measurement.
	 *
	 * @since 0.1.21
	 *
	 * @param string $href Stylesheet URL as it appears in the page.
	 * @return bool
	 */
	public function is_theme_stylesheet( string $href ): bool {
		$protected = $this->theme_stylesheet_check( $href, true );

		/**
		 * Filter whether a stylesheet is protected as a theme stylesheet.
		 *
		 * Return false to let Optimize CSS delivery defer the active theme's
		 * stylesheet (the level 1 coverage guard still applies), or true to
		 * protect a stylesheet served from outside the theme directory.
		 *
		 * @since 0.1.21
		 *
		 * @param bool   $protected Whether the theme rule protects this stylesheet.
		 * @param string $href      Stylesheet URL.
		 */
		return (bool) apply_filters( 'cachingout_critical_css_theme_protected', $protected, $href );
	}

	/**
	 * The unfiltered theme stylesheet test.
	 *
	 * A minified copy (`cache/cachingout/min/css/<hash>.css`) or a combined
	 * file stands for the stylesheets recorded in its manifest (see
	 * original_hrefs()); it is a theme stylesheet when any of those is.
	 * Generated files themselves live under the cache directory, never
	 * under a theme directory, so a copy with no manifest entry is not
	 * protected and the level 1 coverage guard decides for it.
	 *
	 * @param string $href           Stylesheet URL.
	 * @param bool   $follow_bundles Whether to resolve generated copies through the manifests.
	 * @return bool
	 */
	private function theme_stylesheet_check( string $href, bool $follow_bundles ): bool {
		if ( $follow_bundles ) {
			foreach ( self::original_hrefs( $href ) as $original ) {
				if ( $this->theme_stylesheet_check( $original, false ) ) {
					return true;
				}
			}
			return false;
		}

		$path = self::local_path( $href );
		if ( null === $path ) {
			return false;
		}

		$dirs = array();
		foreach ( array( get_stylesheet_directory(), get_template_directory() ) as $dir ) {
			$dir = (string) $dir;
			if ( '' === $dir ) {
				continue;
			}
			$real   = realpath( $dir );
			$dirs[] = rtrim( str_replace( '\\', '/', false !== $real ? $real : $dir ), '/' ) . '/';
		}

		$in_theme = false;
		foreach ( array_unique( $dirs ) as $dir ) {
			if ( 0 === strpos( $path, $dir ) ) {
				$in_theme = true;
				break;
			}
		}
		return $in_theme;
	}

	/**
	 * Resolve a same-origin stylesheet URL to a readable local file.
	 *
	 * Delegates to CachingOut_Local_File (the one URL-to-path mapping since
	 * 1.21.16): other hosts, missing files and paths escaping the install
	 * return null. Normalised with forward slashes.
	 *
	 * @since 0.1.21
	 *
	 * @param string $href Stylesheet URL.
	 * @return string|null Real path, or null.
	 */
	public static function local_path( string $href ): ?string {
		return CachingOut_Local_File::from_url( $href );
	}

	/**
	 * Whether an inline CSS block should be excluded from minification.
	 *
	 * Checks the user-configured excluded_inline_css patterns against the
	 * content of a <style> block.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The inline CSS content to check.
	 * @return bool True when the content matches any exclusion pattern.
	 */
	public function is_inline_css_excluded( $content ) {
		$exclusions = $this->settings->get( 'file_optimization', 'excluded_inline_css', array() );
		foreach ( $exclusions as $pattern ) {
			if ( false !== mb_strpos( mb_strtolower( $content ), mb_strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an inline JS block should be excluded from minification.
	 *
	 * Checks the user-configured excluded_inline_js patterns against the
	 * content of a <script> block.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content The inline script content to check.
	 * @return bool True when the content matches any exclusion pattern.
	 */
	public function is_inline_js_excluded( $content ) {
		$exclusions = $this->settings->get( 'file_optimization', 'excluded_inline_js', array() );
		foreach ( $exclusions as $pattern ) {
			if ( false !== mb_strpos( mb_strtolower( $content ), mb_strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the combined exclusion list for external JS files (used by defer/delay).
	 *
	 * Returns all patterns that should exclude a JS file from defer or
	 * delay: hardcoded patterns + user-configured excluded_js.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string> Array of case-insensitive match patterns.
	 */
	public function get_js_exclusions() {
		$hardcoded = isset( $this->hardcoded_patterns['js'] ) ? $this->hardcoded_patterns['js'] : array();
		$user      = $this->settings->get( 'file_optimization', 'excluded_js', array() );
		return array_merge( $hardcoded, $user );
	}

	/**
	 * Build the combined exclusion list for external CSS files.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string> Array of case-insensitive match patterns.
	 */
	public function get_css_exclusions() {
		$hardcoded = isset( $this->hardcoded_patterns['css'] ) ? $this->hardcoded_patterns['css'] : array();
		$user      = $this->settings->get( 'file_optimization', 'excluded_css', array() );
		return array_merge( $hardcoded, $user );
	}
}