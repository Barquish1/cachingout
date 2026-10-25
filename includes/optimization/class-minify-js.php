<?php
/**
 * JavaScript minification via MatthiasMullie\Minify.
 *
 * Runs as an output-buffer filter on frontend requests only. It does NOT
 * consult SCRIPT_DEBUG (an earlier docblock claimed it did): the toggle
 * alone decides, and a SCRIPT_DEBUG site's unminified core files are
 * minified like any other. Never touches JSON-LD blocks or inline scripts
 * containing document.write.
 *
 * 0.1.16 (audit N12): linked scripts are minified to FILES under
 * wp-content/cache/cachingout/min/js/ and the <script> keeps every
 * attribute — defer, async, type, id — with only its src swapped. They
 * are never inlined: inline scripts ignore defer/async, so inlining made
 * deferred scripts run immediately and out of order and destroyed
 * browser caching.
 *
 * 0.1.17 (BUG-018): sources that are ALREADY minified are left alone.
 * Re-minifying a build-tool bundle gains 0-2% and the regex-based
 * library mis-tokenises some large webpack bundles (Site Kit: a
 * `"https://..."` string was eaten as a line comment -> SyntaxError).
 * See looks_minified().
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use MatthiasMullie\Minify\JS;

/**
 * JavaScript minifier.
 *
 * Uses MatthiasMullie\Minify\JS for safe, parser-based minification
 * instead of regex-based whitespace and comment stripping.
 *
 * @since 0.1.0
 */
class CachingOut_Minify_JS {

	/**
	 * Average line length (bytes) above which a script is treated as
	 * already minified. Hand-written sources average 30-130; every
	 * build-tool bundle measured (WP core, Complianz, 60 Site Kit
	 * chunks) averages 300+ and most are a single multi-KB line.
	 *
	 * @since 0.1.17
	 * @var int
	 */
	const MINIFIED_LINE_LENGTH = 250;

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
	 * Output buffer callback — applies JS minification to the full HTML.
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
		if ( ! $this->settings->get( 'file_optimization', 'minify_js', false ) ) {
			return $html;
		}

		// Minify inline <script> blocks (excluding JSON-LD).
		$html = preg_replace_callback(
			'/<script\b([^>]*)>(.*?)<\/script>/si',
			array( $this, 'minify_inline' ),
			$html
		);

		// Minify local JS files referenced by <script src="..."> to cached files.
		// BUG-017b: (?<![\w-]) so that src never matches inside data-*-src.
		$html = preg_replace_callback(
			'/<script\s[^>]*?(?<![\w-])src\s*=\s*["\']([^"\']+\.js(?:\?[^"\']*)?)["\'][^>]*>(\s*<\/script>)?/si',
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
		return class_exists( JS::class );
	}

	/**
	 * Whether a script is already minified and should be served as-is.
	 *
	 * True when the file is named `*.min.js`, or when its average line
	 * length exceeds MINIFIED_LINE_LENGTH. Skipping such sources costs
	 * nothing (they are already stripped) and avoids the library's
	 * tokenizer bugs on dense bundles (BUG-018).
	 *
	 * @since 0.1.17
	 *
	 * @param string $path    File path or URL ('' for inline blocks).
	 * @param string $content Script source.
	 * @return bool
	 */
	public static function looks_minified( $path, $content ) {
		if ( '' !== (string) $path && preg_match( '/\.min\.js(?:[?#].*)?$/i', (string) $path ) ) {
			return true;
		}
		$content = (string) $content;
		$length  = strlen( $content );
		if ( 0 === $length ) {
			return false;
		}
		$lines = substr_count( $content, "\n" ) + 1;
		return ( $length / $lines ) > self::MINIFIED_LINE_LENGTH;
	}

	/**
	 * Minify a JavaScript string using MatthiasMullie\Minify\JS.
	 *
	 * @since 0.1.0
	 *
	 * @param string $js Raw JavaScript to minify.
	 * @return string Minified JavaScript (the input when the library is absent or fails).
	 */
	public function strip_js( $js ) {
		if ( '' === trim( $js ) ) {
			return '';
		}

		// BUG-011: ship zip must include vendor; if the library is absent
		// (old package, failed install), leave JS unminified — never fatal.
		if ( ! $this->has_library() ) {
			return $js;
		}

		try {
			$minifier = new JS( $js );
			return $minifier->minify();
		} catch ( \Throwable $e ) {
			// Parse failures or unexpected library errors must not take the
			// site down on shutdown/ob_end_flush.
			return $js;
		}
	}

	/**
	 * Callback for inline <script> blocks — minify or skip.
	 *
	 * Skips JSON-LD, non-JS types, document.write, and excluded patterns.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex matches: [0] = full tag, [1] = attributes, [2] = content.
	 * @return string Replacement HTML.
	 */
	private function minify_inline( $matches ) {
		$full_tag   = $matches[0];
		$attributes = isset( $matches[1] ) ? $matches[1] : '';
		$content    = isset( $matches[2] ) ? $matches[2] : '';

		// Skip JSON-LD blocks (structured data, not executable JS).
		if ( preg_match( '/\btype\s*=\s*["\']application\/ld\+json["\']/i', $attributes ) ) {
			return $full_tag;
		}

		// Skip scripts whose type is anything other than JavaScript/module.
		if ( preg_match( '/\btype\s*=\s*["\']([^"\']*)["\']/i', $attributes, $type_match ) ) {
			$type = strtolower( trim( $type_match[1] ) );
			if ( ! in_array( $type, array( '', 'text/javascript', 'application/javascript', 'module' ), true ) ) {
				return $full_tag;
			}
		}

		// Skip empty blocks.
		if ( '' === trim( $content ) ) {
			return $full_tag;
		}

		// Skip blocks containing document.write (template JS).
		if ( false !== stripos( $content, 'document.write' ) ) {
			return $full_tag;
		}

		// Skip excluded blocks.
		if ( $this->exclusions->is_inline_js_excluded( $content ) ) {
			return $full_tag;
		}

		// Skip blocks that are already minified (BUG-018).
		if ( self::looks_minified( '', $content ) ) {
			return $full_tag;
		}

		$minified = $this->strip_js( $content );

		// Reconstruct tag with minified content.
		$pos_end_open  = strpos( $full_tag, '>' );
		$pos_begin_end = strrpos( $full_tag, '</script>' );

		if ( false !== $pos_end_open && false !== $pos_begin_end ) {
			$tag_head = substr( $full_tag, 0, $pos_end_open + 1 );
			$tag_tail = substr( $full_tag, $pos_begin_end );
			return $tag_head . $minified . $tag_tail;
		}

		return $full_tag;
	}

	/**
	 * Callback for <script src="..."> — point it at a minified copy.
	 *
	 * The tag is returned unchanged whenever anything is not certain:
	 * cross-origin, excluded, unresolvable/unsafe path, non-JS type,
	 * document.write in the source, an already-minified source
	 * (BUG-018), missing library, or an unwritable cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex matches: [0] = full tag, [1] = src URL.
	 * @return string Replacement HTML.
	 */
	private function minify_linked( $matches ) {
		$tag = $matches[0];
		$url = isset( $matches[1] ) ? $matches[1] : '';

		if ( '' === $url || ! $this->has_library() ) {
			return $tag;
		}

		// Only JavaScript/module scripts.
		if ( preg_match( '/\btype\s*=\s*["\']([^"\']*)["\']/i', $tag, $type_match ) ) {
			$type = strtolower( trim( $type_match[1] ) );
			if ( ! in_array( $type, array( '', 'text/javascript', 'application/javascript', 'module' ), true ) ) {
				return $tag;
			}
		}

		if ( $this->exclusions->is_excluded( $url, 'js' ) ) {
			return $tag;
		}

		// Already-minified by name: nothing to gain, nothing to risk (BUG-018).
		if ( self::looks_minified( $url, '' ) ) {
			return $tag;
		}

		$source = $this->files->resolve_local_path( $url, 'js' );
		if ( null === $source ) {
			return $tag;
		}

		$target = $this->files->target_path( $source, 'js' );
		// exists() refreshes a reused file's mtime for the purge age-out (BUG-026).
		if ( ! $this->files->exists( $target ) ) {
			$content = file_get_contents( $source );
			if ( false === $content || '' === trim( $content ) ) {
				return $tag;
			}

			// Skip scripts containing document.write (template JS).
			if ( false !== stripos( $content, 'document.write' ) ) {
				return $tag;
			}

			// Already-minified by density: a build-tool bundle without a
			// .min suffix (BUG-018: Site Kit chunks came out broken).
			if ( self::looks_minified( '', $content ) ) {
				return $tag;
			}

			$minified = $this->strip_js( $content );
			if ( ! $this->files->write( $target, $minified ) ) {
				return $tag;
			}
		}

		return CachingOut_Minified_Files::replace_attribute(
			$tag,
			'src',
			$this->files->url_for( $target, 'js' )
		);
	}

}
