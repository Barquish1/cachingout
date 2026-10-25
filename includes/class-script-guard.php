<?php
/**
 * Keeps HTML rewriters and scanners out of <script> bodies.
 *
 * BUG-020 (orgahair.pl, 2026-09-01): themes build markup inside inline
 * JavaScript — `'<img src="' + esc(data.image) + '">'` — and every
 * regex-based pass over the page (LazyLoad, WebP, embeds, the LCP
 * candidate search, the page audit) treated those strings as real tags:
 * LazyLoad injected loading="lazy" into a JS template and the audit
 * reported `' + orgaEsc(data.imageLeft) + '` as an image with no
 * dimensions. Script bodies are not the document; nothing that rewrites
 * or measures tags may look inside them.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Script-body guard.
 *
 * @since 0.1.17
 */
final class CachingOut_Script_Guard {

	/**
	 * Matches a whole <script ...>...</script> element, including the
	 * tags. JSON, JSON-LD and template scripts are covered too — a
	 * rewriter has no business inside any of them.
	 *
	 * @var string
	 */
	const SCRIPT_REGEX = '/<script\b[^>]*>.*?<\/script\s*>/si';

	/**
	 * Run a rewriter over the HTML with every <script> element swapped
	 * out for an opaque placeholder, then put the scripts back untouched.
	 *
	 * The placeholders contain no `<`, so no tag regex can match inside
	 * them, and they are restored verbatim (including the original tags
	 * and attributes). If the callback loses a placeholder — it must not,
	 * but a rewriter that rebuilds the document could — the original HTML
	 * is returned unchanged rather than a page missing its scripts.
	 *
	 * @since 0.1.17
	 *
	 * @param string   $html     Full page HTML.
	 * @param callable $rewriter function( string $html ): string, applied to the masked HTML.
	 * @return string
	 */
	public static function protect( string $html, callable $rewriter ): string {
		if ( '' === $html || false === stripos( $html, '<script' ) ) {
			return (string) $rewriter( $html );
		}

		$scripts = array();
		$masked  = preg_replace_callback(
			self::SCRIPT_REGEX,
			static function ( $m ) use ( &$scripts ) {
				$key             = "\x00CO-SCRIPT-" . count( $scripts ) . "\x00";
				$scripts[ $key ] = $m[0];
				return $key;
			},
			$html
		);
		if ( ! is_string( $masked ) ) {
			// Backtrack limit or similar: leave the page alone rather than
			// rewrite it blind.
			return $html;
		}

		$out = (string) $rewriter( $masked );

		foreach ( $scripts as $key => $script ) {
			if ( false === strpos( $out, $key ) ) {
				return $html;
			}
		}

		return strtr( $out, $scripts );
	}

	/**
	 * Blank out every <script> element with spaces so a scanner sees the
	 * same offsets as the original document but no script content.
	 *
	 * For read-only passes (LCP candidate search, page audit) where the
	 * caller reports positions or extracts tags rather than rewriting.
	 *
	 * @since 0.1.17
	 *
	 * @param string $html Full page HTML.
	 * @return string Same length as $html.
	 */
	public static function mask( string $html ): string {
		if ( '' === $html || false === stripos( $html, '<script' ) ) {
			return $html;
		}
		$masked = preg_replace_callback(
			self::SCRIPT_REGEX,
			static function ( $m ) {
				return str_repeat( ' ', strlen( $m[0] ) );
			},
			$html
		);
		return is_string( $masked ) ? $masked : $html;
	}
}
