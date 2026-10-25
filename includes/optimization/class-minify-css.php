<?php
/**
 * CSS minification.
 *
 * Runs as an output-buffer filter on frontend requests only. It does NOT
 * consult SCRIPT_DEBUG (an earlier docblock claimed it did): the toggle
 * alone decides.
 *
 * 0.1.16 (audit N13): linked stylesheets are minified to FILES under
 * wp-content/cache/cachingout/min/css/ and the <link> keeps every
 * attribute with only its href swapped. They are never inlined: inlining
 * resolved relative url()/@import paths against the page URL, which on
 * orgahair.com (theme main.css = an @import index) removed every style
 * on the site. The bundled MatthiasMullie\Minify\CSS + path-converter
 * do the work: @imports are folded in and relative paths rewritten for
 * the new location. Without the library, files are left untouched —
 * the regex fallback only ever serves inline <style> blocks.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MatthiasMullie\Minify\CSS;

/**
 * CSS minifier.
 *
 * @since 0.1.0
 */
class CachingOut_Minify_CSS {

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
	 * Minified-file store.
	 *
	 * @since 0.1.16
	 * @var CachingOut_Minified_Files
	 */
	private $files;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings                $settings   Settings instance.
	 * @param CachingOut_Optimization_Exclusions $exclusions Shared exclusions.
	 * @param CachingOut_Minified_Files|null     $files      Optional store (tests).
	 */
	public function __construct( CachingOut_Settings $settings, CachingOut_Optimization_Exclusions $exclusions, CachingOut_Minified_Files $files = null ) {
		$this->settings   = $settings;
		$this->exclusions = $exclusions;
		$this->files      = $files ? $files : new CachingOut_Minified_Files();
	}


	/**
	 * Output buffer callback — applies CSS minification to the full HTML.
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
		if ( ! $this->settings->get( 'file_optimization', 'minify_css', false ) ) {
			return $html;
		}

		// Minify inline <style> blocks in place.
		$html = preg_replace_callback(
			'/<style\b[^>]*>(.*?)<\/style>/si',
			array( $this, 'minify_inline' ),
			$html
		);

		// Minify linked local stylesheets to cached files.
		$html = preg_replace_callback(
			'/<link\s[^>]*?\brel\s*=\s*["\']stylesheet["\'][^>]*?\/?>/si',
			array( $this, 'minify_linked' ),
			$html
		);

		return $html;
	}

	/**
	 * Whether the parser-based minifier is available.
	 *
	 * @since 0.1.16
	 * @return bool
	 */
	public function has_library() {
		return class_exists( CSS::class );
	}

	/**
	 * Minify a CSS string.
	 *
	 * Uses MatthiasMullie\Minify\CSS when bundled (strings, comments,
	 * calc() and custom properties are handled by a real tokenizer). Falls
	 * back to a conservative regex pass otherwise. Used for inline <style>
	 * blocks; linked files go through minify_file() so paths get rewritten.
	 *
	 * @since 0.1.0
	 *
	 * @param string $css Raw CSS to minify.
	 * @return string Minified CSS.
	 */
	public function strip_css( $css ) {
		if ( '' === trim( $css ) ) {
			return '';
		}

		if ( $this->has_library() ) {
			try {
				$minifier = new CSS();
				// No data-URI inlining of images/fonts: keeps output predictable.
				$minifier->setImportExtensions( array() );
				$minifier->add( $css );
				$result = $minifier->execute();
				if ( is_string( $result ) && '' !== $result ) {
					return $result;
				}
			} catch ( \Throwable $e ) {
				// Fall through to the regex pass; never fatal on shutdown.
				unset( $e );
			}
		}

		return $this->strip_css_fallback( $css );
	}

	/**
	 * Conservative regex minification (no library available).
	 *
	 * Deliberately narrower than the 0.1.0 pass: zero-unit shortening only
	 * touches LENGTH units in plain declarations (never durations, angles,
	 * hex colours, base64 payloads or anything inside a function such as
	 * calc()), and decimal values are left alone.
	 *
	 * @since 0.1.16
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	private function strip_css_fallback( $css ) {
		// Remove comments.
		$css = preg_replace( '/\/\*.*?\*\//s', '', $css );

		// Collapse whitespace.
		$css = preg_replace( '/\s+/', ' ', $css );

		// Remove whitespace around {}, ;, :, ,.
		$css = preg_replace( '/\s*{\s*/', '{', $css );
		$css = preg_replace( '/\s*}\s*/', '}', $css );
		$css = preg_replace( '/\s*;\s*/', ';', $css );
		$css = preg_replace( '/\s*:\s*/', ':', $css );
		$css = preg_replace( '/\s*,\s*/', ',', $css );

		// Remove last semicolon in each declaration block.
		$css = preg_replace( '/;}/', '}', $css );

		// 0<length-unit> → 0, only in a plain declaration value position.
		$css = preg_replace(
			'/(?<=[:\s,])0(?:px|em|rem|ex|ch|vw|vh|vmin|vmax|pt|pc|in|cm|mm|q)(?=[;\s}!,])/i',
			'0',
			$css
		);

		return trim( $css );
	}

	/**
	 * Minify a stylesheet FILE for delivery from a new location.
	 *
	 * Relative url()/@import references are rewritten to stay valid from
	 * $target_path; small @import'ed local files are folded into the
	 * output. Returns null when the library is missing or the file cannot
	 * be processed — callers then leave the original tag alone.
	 *
	 * @since 0.1.16
	 *
	 * @param string $source_path Absolute path of the stylesheet.
	 * @param string $target_path Absolute path the output will be served from.
	 * @return string|null Minified CSS, or null.
	 */
	public function minify_file( $source_path, $target_path ) {
		if ( ! $this->has_library() ) {
			return null;
		}

		try {
			$minifier = new CSS();
			$minifier->setImportExtensions( array() );
			$minifier->addFile( $source_path );
			$result = $minifier->execute( $target_path );
			return is_string( $result ) ? $this->ensure_font_display( $result ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Give every @font-face without a display policy `font-display: swap`.
	 *
	 * A theme font declared without font-display leaves text invisible
	 * until the file arrives (Merriweather.ttf on orgahair.pl blocked
	 * first text for ~115 ms of the mobile LCP). Swap paints fallback text
	 * immediately - the same policy Self-host fonts applies to Google
	 * Fonts (BUG-025). Disable with the
	 * `cachingout_minify_css_font_display` filter.
	 *
	 * @since 0.1.17
	 *
	 * @param string $css Minified CSS.
	 * @return string
	 */
	private function ensure_font_display( $css ) {
		if ( ! is_string( $css ) || false === stripos( $css, '@font-face' ) ) {
			return $css;
		}
		/**
		 * Whether Minify CSS adds font-display: swap to @font-face rules
		 * that declare none.
		 *
		 * @since 0.1.17
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'cachingout_minify_css_font_display', true ) ) {
			return $css;
		}
		return self::apply_font_display_swap( $css );
	}

	/**
	 * Add `font-display: swap` to every @font-face rule that declares no
	 * display policy. Rules that declare one are left alone.
	 *
	 * Free since 1.21.0 (spec 30): the minifier is a free feature, so the
	 * helper it needs lives here; the Pro self-host module calls it too.
	 *
	 * @since 1.21.0
	 *
	 * @param string $css CSS source.
	 * @return string
	 */
	public static function apply_font_display_swap( string $css ): string {
		$out = preg_replace_callback(
			'/@font-face\s*\{([^}]*)\}/i',
			static function ( $m ) {
				if ( false !== stripos( $m[1], 'font-display' ) ) {
					return $m[0];
				}
				$body = rtrim( $m[1] );
				if ( '' !== $body && ';' !== substr( $body, -1 ) ) {
					$body .= ';';
				}
				return '@font-face{' . $body . 'font-display:swap;}';
			},
			$css
		);
		return is_string( $out ) ? $out : $css;
	}

	/**
	 * Callback for inline <style> blocks — minify or skip.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex matches: [0] = full tag, [1] = CSS content.
	 * @return string Replacement HTML.
	 */
	private function minify_inline( $matches ) {
		$full_tag = $matches[0];
		$content  = isset( $matches[1] ) ? $matches[1] : '';

		if ( '' === trim( $content ) ) {
			return $full_tag;
		}

		if ( $this->exclusions->is_inline_css_excluded( $content ) ) {
			return $full_tag;
		}

		$minified = $this->ensure_font_display( $this->strip_css( $content ) );

		// Reconstruct the tag with the minified content.
		return str_replace( $content, $minified, $full_tag );
	}

	/**
	 * Callback for <link rel="stylesheet"> — point it at a minified copy.
	 *
	 * The tag is returned unchanged whenever anything is not certain:
	 * cross-origin, excluded, unresolvable/unsafe path, missing library,
	 * minifier failure, or an unwritable cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex matches; [0] = full tag.
	 * @return string Replacement HTML.
	 */
	private function minify_linked( $matches ) {
		$tag = $matches[0];

		if ( ! preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $url_match ) ) {
			return $tag;
		}
		$url = $url_match[1];

		if ( $this->exclusions->is_excluded( $url, 'css' ) ) {
			return $tag;
		}

		$source = $this->files->resolve_local_path( $url, 'css' );
		if ( null === $source ) {
			return $tag;
		}

		$target = $this->files->target_path( $source, 'css' );
		// exists() refreshes a reused file's mtime for the purge age-out (BUG-026).
		if ( ! $this->files->exists( $target ) ) {
			$minified = $this->minify_file( $source, $target );
			if ( null === $minified ) {
				return $tag;
			}
			if ( ! $this->files->write( $target, $minified ) ) {
				return $tag;
			}
		}

		// Remember what the hashed copy stands for, so the stylesheet keeps
		// its name-based and theme-directory protection downstream (1.21.3).
		CachingOut_Minified_Files::record_source( $target, $url );

		return CachingOut_Minified_Files::replace_attribute(
			$tag,
			'href',
			$this->files->url_for( $target, 'css' )
		);
	}

}
