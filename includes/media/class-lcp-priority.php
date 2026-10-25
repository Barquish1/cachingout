<?php
/**
 * LCP image priority — automatic fetchpriority="high" + image preload.
 *
 * The Largest Contentful Paint element on most WordPress pages is a hero
 * image. Browsers fetch it at default priority behind stylesheets, fonts
 * and third-party scripts unless the markup says otherwise, and almost no
 * site owner can add `fetchpriority` / `<link rel="preload">` by hand. This
 * filter picks the most likely LCP image per page and adds both.
 *
 * A page filter of the single optimisation pipeline buffer (position 70,
 * before LazyLoad's 80, registered by CachingOut_Plugin::run()): it runs
 * before LazyLoad, which then leaves any `fetchpriority="high"` image
 * alone. Until 1.21.16 it opened an output buffer of its own. The WebP
 * cache variant rewrites the preload href alongside the <img> src.
 *
 * A wrong guess costs one wasted priority hint — never a broken page.
 *
 * @package CachingOut
 * @since   0.1.16
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LCP priority filter.
 *
 * @since 0.1.16
 */
class CachingOut_LCP_Priority {

	/**
	 * Substrings (in src or class) that mark an image as an unlikely LCP.
	 *
	 * @since 0.1.16
	 * @var string[]
	 */
	const UNLIKELY_PATTERNS = array(
		'logo',
		'icon',
		'avatar',
		'gravatar',
		'emoji',
		'sprite',
		'pixel',
		'spinner',
		'loader',
		'badge',
		'flag',
		'.svg',
		'.gif',
	);

	/**
	 * Images with declared dimensions below this area (px²) are skipped.
	 *
	 * @since 0.1.16
	 * @var int
	 */
	const MIN_AREA = 40000;

	/**
	 * Only the first N eligible images are considered — the LCP is above
	 * the fold by definition.
	 *
	 * @since 0.1.16
	 * @var int
	 */
	const CANDIDATE_LIMIT = 5;

	/**
	 * Plugin settings.
	 *
	 * @since 0.1.16
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.16
	 *
	 * @param CachingOut_Settings $settings Settings instance.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the output buffer when the feature is enabled.
	 *
	 * @since 0.1.16
	 * @return void
	 */
	public function run(): void {
		// Nothing to bind since 1.21.16: the HTML pass is a pipeline page
		// filter (CachingOut_Plugin::run()). Kept so callers need no change.
	}

	/**
	 * Whether the feature is switched on.
	 *
	 * @since 1.21.16
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'media', 'lcp_priority_enabled', true );
	}

	/**
	 * Pipeline page filter (position 70): hint the LCP image.
	 *
	 * @since 0.1.16
	 *
	 * @param string $html Full page HTML.
	 * @return string Transformed HTML.
	 */
	public function filter( string $html ): string {
		if ( '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		if ( ! $this->settings->get( 'media', 'lcp_priority_enabled', true ) ) {
			return $html;
		}

		$chosen = $this->find_lcp_candidate( $html );
		if ( null === $chosen ) {
			return $html;
		}

		$tag = $chosen['tag'];
		if ( ! preg_match( '/\bfetchpriority\s*=/i', $tag ) ) {
			$tag = $this->add_attribute( $tag, 'fetchpriority="high"' );
		}
		if ( $tag !== $chosen['tag'] ) {
			$html = substr_replace( $html, $tag, $chosen['offset'], strlen( $chosen['tag'] ) );
		}

		if ( ! $chosen['in_picture'] ) {
			$html = $this->inject_preload( $html, $chosen );
		}

		return $html;
	}

	/**
	 * Pick the most likely LCP <img> in the document.
	 *
	 * Rules, in order:
	 * 1. An image the author already marked fetchpriority="high" wins.
	 * 2. Otherwise, among the first CANDIDATE_LIMIT eligible images
	 *    (has a real src, not loading="lazy", not fetchpriority="low",
	 *    not excluded), skip unlikely ones (logo/icon/svg/…) and any with
	 *    declared dimensions under MIN_AREA, then take the largest by
	 *    declared area; images without dimensions rank below any sized one.
	 * Images inside <noscript> / <template> are ignored.
	 *
	 * @since 0.1.16
	 *
	 * @param string $html Full page HTML.
	 * @return array|null {tag, offset, src, srcset, sizes, in_picture, score} or null.
	 */
	public function find_lcp_candidate( string $html ) {
		// Blank out noscript/template/script bodies with spaces so offsets
		// stay aligned (BUG-020: <img> markup inside inline JS is not an image).
		$masked = preg_replace_callback(
			'/<(noscript|template|script)\b[^>]*>.*?<\/\1\s*>/si',
			static function ( $m ) {
				return str_repeat( ' ', strlen( $m[0] ) );
			},
			$html
		);
		if ( ! is_string( $masked ) ) {
			$masked = $html;
		}

		if ( ! preg_match_all( '/<img\s[^>]*>/si', $masked, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$exclusions = (array) $this->settings->get( 'media', 'lcp_priority_exclusions', array() );

		// Rule 1: explicit author hint.
		foreach ( $matches[0] as $match ) {
			list( $tag, $offset ) = $match;
			if ( preg_match( '/\bfetchpriority\s*=\s*["\']high["\']/i', $tag ) ) {
				$candidate = $this->describe( $tag, $offset, $masked );
				if ( null !== $candidate ) {
					return $candidate;
				}
			}
		}

		// Rule 2: heuristic over the first few eligible images.
		$best = null;
		$seen = 0;
		foreach ( $matches[0] as $match ) {
			list( $tag, $offset ) = $match;

			$src = $this->attr( $tag, 'src' );
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				continue;
			}
			if ( preg_match( '/\bloading\s*=\s*["\']lazy["\']/i', $tag ) ) {
				continue;
			}
			if ( preg_match( '/\bfetchpriority\s*=\s*["\']low["\']/i', $tag ) ) {
				continue;
			}
			if ( preg_match( '/\bdata-no-lcp\b/i', $tag ) || $this->matches_any( $tag, $exclusions ) ) {
				continue;
			}

			$seen++;

			$haystack = $src . ' ' . $this->attr( $tag, 'class' );
			$area     = $this->declared_area( $tag );
			$unlikely = $this->matches_any( $haystack, self::UNLIKELY_PATTERNS )
				|| ( $area > 0 && $area < self::MIN_AREA );

			if ( ! $unlikely ) {
				$score = $area > 0 ? $area : 1;
				if ( null === $best || $score > $best['score'] ) {
					$candidate = $this->describe( $tag, $offset, $masked );
					if ( null !== $candidate ) {
						$candidate['score'] = $score;
						$best               = $candidate;
					}
				}
			}

			if ( $seen >= self::CANDIDATE_LIMIT ) {
				break;
			}
		}

		return $best;
	}

	/**
	 * Build the candidate record for a tag.
	 *
	 * @since 0.1.16
	 *
	 * @param string $tag    The <img> tag.
	 * @param int    $offset Byte offset in the HTML.
	 * @param string $masked Masked HTML (for <picture> detection).
	 * @return array|null
	 */
	private function describe( string $tag, int $offset, string $masked ) {
		$src = $this->attr( $tag, 'src' );
		if ( '' === $src ) {
			return null;
		}

		$before       = substr( $masked, 0, $offset );
		$last_open    = strripos( $before, '<picture' );
		$last_close   = strripos( $before, '</picture' );
		$in_picture   = false !== $last_open && ( false === $last_close || $last_close < $last_open );

		return array(
			'tag'        => $tag,
			'offset'     => $offset,
			'src'        => $src,
			'srcset'     => $this->attr( $tag, 'srcset' ),
			'sizes'      => $this->attr( $tag, 'sizes' ),
			'in_picture' => $in_picture,
			'score'      => 0,
		);
	}

	/**
	 * Insert a high-priority image preload right after <head>.
	 *
	 * Skipped when the head already preloads that URL, or when there is no
	 * <head> to put it in.
	 *
	 * @since 0.1.16
	 *
	 * @param string $html      Full page HTML.
	 * @param array  $candidate From find_lcp_candidate().
	 * @return string
	 */
	private function inject_preload( string $html, array $candidate ): string {
		if ( ! preg_match( '/<head\b[^>]*>/i', $html, $head, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}

		$src = $candidate['src'];
		if ( preg_match( '/<link\b[^>]*\bas\s*=\s*["\']image["\'][^>]*\bhref\s*=\s*["\']' . preg_quote( $src, '/' ) . '["\']/i', $html )
			|| preg_match( '/<link\b[^>]*\bhref\s*=\s*["\']' . preg_quote( $src, '/' ) . '["\'][^>]*\bas\s*=\s*["\']image["\']/i', $html ) ) {
			return $html;
		}

		$link = '<link rel="preload" as="image" href="' . esc_url( $src ) . '"';
		if ( '' !== $candidate['srcset'] ) {
			$link .= ' imagesrcset="' . esc_attr( $candidate['srcset'] ) . '"';
			if ( '' !== $candidate['sizes'] ) {
				$link .= ' imagesizes="' . esc_attr( $candidate['sizes'] ) . '"';
			}
		}
		$link .= ' fetchpriority="high" data-cachingout-lcp="1">';

		$insert_at = $head[0][1] + strlen( $head[0][0] );

		return substr( $html, 0, $insert_at ) . "\n" . $link . substr( $html, $insert_at );
	}

	/**
	 * Read one attribute value from a tag.
	 *
	 * @since 0.1.16
	 *
	 * @param string $tag  Tag HTML.
	 * @param string $name Attribute name.
	 * @return string Value or ''.
	 */
	private function attr( string $tag, string $name ): string {
		// (?<![\w-]) so that "src" never matches inside data-src /
		// data-cachingout-src (a delayed script must not read as eager).
		if ( preg_match( '/(?<![\w-])' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
			return trim( $m[2] );
		}
		return '';
	}

	/**
	 * width × height from the tag's attributes, or 0 when either is missing
	 * or non-numeric.
	 *
	 * @since 0.1.16
	 *
	 * @param string $tag Tag HTML.
	 * @return int
	 */
	private function declared_area( string $tag ): int {
		$w = $this->attr( $tag, 'width' );
		$h = $this->attr( $tag, 'height' );
		if ( '' === $w || '' === $h || ! is_numeric( $w ) || ! is_numeric( $h ) ) {
			return 0;
		}
		return (int) ( (float) $w * (float) $h );
	}

	/**
	 * Case-insensitive substring match against a list.
	 *
	 * @since 0.1.16
	 *
	 * @param string   $haystack Text to search.
	 * @param string[] $patterns Substrings.
	 * @return bool
	 */
	private function matches_any( string $haystack, array $patterns ): bool {
		$haystack = mb_strtolower( $haystack );
		foreach ( $patterns as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern && false !== mb_strpos( $haystack, mb_strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Append an attribute to a tag, keeping `/>` self-closing form intact.
	 *
	 * @since 0.1.16
	 *
	 * @param string $tag       Tag HTML.
	 * @param string $attribute Attribute text, e.g. fetchpriority="high".
	 * @return string
	 */
	private function add_attribute( string $tag, string $attribute ): string {
		return (string) preg_replace_callback(
			'/\s*(\/?)>$/',
			static function ( $m ) use ( $attribute ) {
				return ' ' . $attribute . ( '' !== $m[1] ? ' />' : '>' );
			},
			$tag,
			1
		);
	}
}
