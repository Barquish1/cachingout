<?php
/**
 * LazyLoad — automatically add loading="lazy" to images/iframes and defer
 * CSS background-image loading via Intersection Observer.
 *
 * Runs as an output-buffer filter on the frontend only. Uses native
 * loading="lazy" (~92% browser support in 2026) with a small dependency-free
 * JS polyfill for older browsers.
 *
 * P1 extensions:
 * - Sub-toggles for images, iframes, CSS backgrounds (independent on/off)
 * - Above-the-fold exclusion (skip first N images to protect LCP)
 * - User-configured exclusion keyword/URL patterns
 * - Automatic width/height detection from image files for CLS reduction
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LazyLoad manager.
 *
 * Constructed once by CachingOut_Plugin. Wires output buffering and script
 * enqueuing. All HTML transformation is regex-based with clear exclusion rules.
 *
 * @since 0.1.0
 */
class CachingOut_LazyLoad {

	/**
	 * Settings instance.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Regex for <img> opening tags.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $img_regex;

	/**
	 * Regex for <iframe> opening tags.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $iframe_regex;

	/**
	 * Regex for elements with background-image in inline style.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private $bg_regex;

	/**
	 * Matches a whole <video>…</video> element (sources included).
	 *
	 * @since 0.1.16
	 * @var string
	 */
	private $video_regex;

	/**
	 * Running counter of images processed in the current filter pass.
	 *
	 * Used to enforce the above-the-fold exclusion: the first N images
	 * are skipped entirely.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private $image_index = 0;

	/**
	 * User-configured exclusion patterns, compiled once per request.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	private $user_exclusions = array();


	/**
	 * Companion rule emitted once when dimensions were injected.
	 *
	 * `width`/`height` attributes are presentational hints, so any author
	 * rule already beats them - but on an axis the theme leaves at `auto`
	 * the injected `height` becomes the used height and a CSS-sized image
	 * (a header logo scaled to fit a bar) renders at its intrinsic size
	 * instead. `height: auto` restores that axis while the UA-derived
	 * `aspect-ratio: attr(width) / attr(height)` keeps reserving the box,
	 * so CLS protection is unaffected.
	 *
	 * The rule sits in a cascade layer: unlayered author CSS wins over
	 * layered CSS at any specificity, so this can never override a theme.
	 * Browsers without `@layer` support drop the block and behave as
	 * before 1.21.7.
	 *
	 * @since 1.21.7
	 * @var string
	 */
	const DIMENSION_STYLE = '@layer cachingout{img[data-cachingout-dim]{height:auto}}';

	/**
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings $settings Settings instance.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings     = $settings;
		$this->img_regex    = '/<img\s[^>]*>/si';
		$this->iframe_regex = '/<iframe\s[^>]*>/si';
		$this->bg_regex     = '/(<[a-z][a-z0-9]*\s[^>]*?\bstyle\s*=\s*["\'][^"\']*?\bbackground-image\s*:\s*url\s*\([^)]+\)[^"\']*?["\'][^>]*>)/si';
		$this->video_regex  = '/<video\b[^>]*>.*?<\/video\s*>/si';
	}

	/**
	 * Whether lazy loading is switched on.
	 *
	 * @since 1.21.16
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'media', 'lazyload_enabled', true );
	}

	/**
	 * Register hooks.
	 *
	 * Called once from CachingOut_Plugin::run(). Wires script enqueuing to
	 * wp_enqueue_scripts. The HTML pass is a page filter of the single
	 * optimisation pipeline buffer since 1.21.16 (CachingOut_Plugin::run()
	 * registers it; before that this class opened a buffer of its own).
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function run(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// Pre-load user exclusion patterns for the filter pass.
		$this->user_exclusions = $this->settings->get( 'media', 'lazyload_exclusions', array() );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Pipeline page filter (position 80). Transforms the entire page HTML.
	 *
	 * Applies transformations in order:
	 * 1. Add loading="lazy" to <img> tags (with decoding="async").
	 * 2. Add loading="lazy" to <iframe> tags.
	 * 3. Defer background-image in inline styles.
	 *
	 * Each step is a single preg_replace_callback call. The image index
	 * counter is reset at the start so that above-the-fold exclusion is
	 * applied fresh for each page render.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html Full page HTML.
	 * @return string Transformed HTML.
	 */
	public function filter( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		// Reset the per-request image counter.
		$this->image_index = 0;

		// BUG-020: markup inside inline <script> bodies is not the document.
		return CachingOut_Script_Guard::protect( $html, function ( $html ) {
			// Apply image lazy-load (respects sub-toggles + LCP exclusion).
			$html = preg_replace_callback( $this->img_regex, array( $this, 'lazy_image' ), $html );

			// Apply iframe lazy-load (respects sub-toggles).
			if ( $this->settings->get( 'media', 'lazyload_iframes', true ) ) {
				$html = preg_replace_callback( $this->iframe_regex, array( $this, 'lazy_iframe' ), $html );
			}

			// Apply background-image defer (respects sub-toggles).
			if ( $this->settings->get( 'media', 'lazyload_backgrounds', true ) ) {
				$html = preg_replace_callback( $this->bg_regex, array( $this, 'lazy_background' ), $html );
			}

			// Videos: no source download until the element is near the viewport,
			// pause when scrolled away (0.1.16, respects sub-toggle).
			if ( $this->settings->get( 'media', 'lazyload_videos', true ) ) {
				$html = preg_replace_callback( $this->video_regex, array( $this, 'lazy_video' ), $html );
			}

			return $html;
		} );
	}

	/**
	 * Enqueue the companion stylesheet for injected width/height attributes.
	 *
	 * One cascade-layer rule, `img[data-cachingout-dim]{height:auto}`, so an
	 * added height can never become a CSS-sized image's real height. Printed
	 * through wp_add_inline_style() on a registered handle whenever the
	 * dimensions feature is on (until 1.21.16 it was written into the page
	 * HTML by the buffer pass; WordPress.org review round 1). The CSS is
	 * filterable; returning an empty string suppresses the style entirely.
	 *
	 * @since 1.21.7
	 * @since 1.21.16 Enqueued, no longer injected into the HTML.
	 * @return void
	 */
	public function enqueue_dimension_style(): void {
		if ( ! $this->settings->get( 'media', 'lazyload_add_dimensions', true ) ) {
			return;
		}

		/**
		 * Filter the companion CSS emitted alongside injected dimensions.
		 *
		 * @since 1.21.7
		 *
		 * @param string $css The cascade-layer rule. Empty string = no style.
		 */
		$css = (string) apply_filters( 'cachingout_dimension_style', self::DIMENSION_STYLE );

		if ( '' === trim( $css ) ) {
			return;
		}

		wp_register_style( 'cachingout-lazyload', false, array(), CACHINGOUT_VERSION );
		wp_enqueue_style( 'cachingout-lazyload' );
		wp_add_inline_style( 'cachingout-lazyload', $css );
	}

	/**
	 * Defer a <video> element's source until it is near the viewport.
	 *
	 * - `src` on the element and on every <source> becomes `data-src`;
	 * - `preload="none"` is forced so nothing downloads yet (the poster,
	 *   if any, is the placeholder);
	 * - `autoplay` is removed and remembered as `data-autoplay`, so the
	 *   front-end script starts playback only when the video is visible and
	 *   pauses it again when it leaves the viewport.
	 *
	 * Excluded (patterns / data-no-lazy) or already-processed videos are
	 * left alone.
	 *
	 * @since 0.1.16
	 *
	 * @param array $matches Regex match array. $matches[0] is the whole element.
	 * @return string Modified or original element.
	 */
	public function lazy_video( array $matches ): string {
		$element = $matches[0];

		if ( ! preg_match( '/<video\b[^>]*>/i', $element, $open ) ) {
			return $element;
		}
		$opening = $open[0];

		if ( false !== stripos( $opening, 'data-cachingout-video' ) || $this->is_excluded( $opening ) ) {
			return $element;
		}

		$new_opening = $opening;

		// src → data-src.
		$new_opening = preg_replace( '/\bsrc\s*=\s*(["\'])/i', 'data-src=$1', $new_opening, 1 );

		// preload="none" (replace or add).
		if ( preg_match( '/\bpreload\s*=\s*["\'][^"\']*["\']/i', $new_opening ) ) {
			$new_opening = preg_replace( '/\bpreload\s*=\s*["\'][^"\']*["\']/i', 'preload="none"', $new_opening, 1 );
		} else {
			$new_opening = substr_replace( $new_opening, ' preload="none"', -1, 0 );
		}

		// autoplay → data-autoplay (playback is started by the observer).
		if ( preg_match( '/\sautoplay(?:\s*=\s*["\'][^"\']*["\'])?(?=[\s\/>])/i', $new_opening ) ) {
			$new_opening = preg_replace( '/\sautoplay(?:\s*=\s*["\'][^"\']*["\'])?(?=[\s\/>])/i', ' data-autoplay="1"', $new_opening, 1 );
		}

		$new_opening = substr_replace( $new_opening, ' data-cachingout-video="1"', -1, 0 );

		$body = substr( $element, strlen( $opening ) );
		$body = preg_replace_callback(
			'/<source\b[^>]*>/i',
			static function ( $m ) {
				return preg_replace( '/\bsrc\s*=\s*(["\'])/i', 'data-src=$1', $m[0], 1 );
			},
			$body
		);

		return $new_opening . $body;
	}

	/**
	 * Add loading="lazy" and decoding="async" to an <img> tag.
	 *
	 * Only modifies the tag when:
	 * - The images sub-toggle is enabled.
	 * - It is not excluded (hardcoded rules + user patterns).
	 * - It is not within the above-the-fold exclusion count.
	 * - It does not already carry a `loading` attribute.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex match array. $matches[0] is the full tag.
	 * @return string Modified or original tag.
	 */
	public function lazy_image( array $matches ): string {
		$tag = $matches[0];

		// Sub-toggle: skip if images are disabled.
		if ( ! $this->settings->get( 'media', 'lazyload_images', true ) ) {
			return $tag;
		}

		// Increment the per-request image counter.
		$this->image_index++;

		// An image hinted as the LCP — by the theme or by
		// CachingOut_LCP_Priority, which runs just before this buffer —
		// must stay eager (0.1.16).
		if ( preg_match( '/\bfetchpriority\s*=\s*["\']high["\']/i', $tag ) ) {
			return $tag;
		}

		// Above-the-fold exclusion: skip first N images.
		if ( $this->is_above_fold_image() ) {
			return $tag;
		}

		// An explicit opt-out is checked BEFORE any rewrite. Until 1.21.7
		// add_dimensions() ran first, so skip-lazy / no-lazy / data-no-lazy
		// and the user exclusion patterns stopped lazy-loading but still let
		// width/height be injected - the documented escape hatch did not
		// cover the one transformation most likely to need it.
		if ( $this->is_opted_out( $tag ) ) {
			return $tag;
		}

		// Add width/height attributes from the image file if missing (CLS).
		$tag = $this->add_dimensions( $tag );

		// Standard exclusion checks (hardcoded + user patterns). After the
		// opt-out check above only the `loading=` rule can still match.
		if ( $this->is_excluded( $tag ) ) {
			return $tag;
		}

		// Do not add loading="lazy" if a loading attribute already exists.
		if ( false !== stripos( $tag, 'loading=' ) ) {
			return $tag;
		}

		// Add loading="lazy" and decoding="async" before the closing >.
		$tag = substr_replace( $tag, ' loading="lazy" decoding="async"', -1, 0 );

		return $tag;
	}

	/**
	 * Add loading="lazy" to an <iframe> tag.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex match array. $matches[0] is the full tag.
	 * @return string Modified or original tag.
	 */
	public function lazy_iframe( array $matches ): string {
		$tag = $matches[0];

		if ( $this->is_excluded( $tag ) ) {
			return $tag;
		}

		// YouTube players only accept pause commands over postMessage when
		// the embed was loaded with enablejsapi=1 (0.1.16 pause-offscreen).
		if ( $this->settings->get( 'media', 'lazyload_videos', true ) ) {
			$tag = $this->enable_youtube_jsapi( $tag );
		}

		if ( false !== stripos( $tag, 'loading=' ) ) {
			return $tag;
		}

		$tag = substr_replace( $tag, ' loading="lazy"', -1, 0 );

		return $tag;
	}

	/**
	 * Append enablejsapi=1 to a YouTube embed src that lacks it.
	 *
	 * @since 0.1.16
	 *
	 * @param string $tag <iframe> tag.
	 * @return string
	 */
	public function enable_youtube_jsapi( string $tag ): string {
		if ( ! preg_match( '/\bsrc\s*=\s*(["\'])(https?:\/\/(?:www\.)?youtube(?:-nocookie)?\.com\/embed\/[^"\']*)\1/i', $tag, $m ) ) {
			return $tag;
		}
		$src = $m[2];
		if ( false !== stripos( $src, 'enablejsapi=' ) ) {
			return $tag;
		}
		$new_src = $src . ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'enablejsapi=1';
		return str_replace( $m[0], 'src=' . $m[1] . esc_url( $new_src ) . $m[1], $tag );
	}

	/**
	 * Defer a background-image in an inline style attribute.
	 *
	 * Replaces `background-image: url(...)` with a 1×1 transparent
	 * data-URI placeholder and stores the original URL in
	 * `data-background-image` for the Intersection Observer JS.
	 *
	 * @since 0.1.0
	 *
	 * @param array $matches Regex match array. $matches[0] is the full opening tag.
	 * @return string Modified opening tag.
	 */
	public function lazy_background( array $matches ): string {
		$tag = $matches[0];

		// Extract the background-image URL.
		if ( ! preg_match( '/\bbackground-image\s*:\s*url\s*\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $tag, $url_match ) ) {
			return $tag;
		}

		$original_url = $url_match[1];

		// 1×1 transparent PNG data-URI placeholder.
		$placeholder = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

		// Replace background-image URL with placeholder.
		$tag = preg_replace(
			'/\bbackground-image\s*:\s*url\s*\([^)]+\)/i',
			'background-image: url(' . $placeholder . ')',
			$tag
		);

		// Store the original URL in a data attribute.
		$tag = substr_replace( $tag, ' data-background-image="' . esc_attr( $original_url ) . '"', -1, 0 );

		return $tag;
	}

	/**
	 * Determine whether a tag should be excluded from lazy loading.
	 *
	 * Exclusion rules (any one matching = exclude):
	 * 1. Tag already has a `loading=` attribute.
	 * 2. Tag has `data-skip-lazy` or `data-no-lazy` attribute.
	 * 3. Tag class contains `skip-lazy` or `no-lazy`.
	 * 4. Tag matches a user-configured exclusion pattern.
	 *
	 * @since 0.1.0
	 *
	 * @param string $tag The complete opening tag string.
	 * @return bool True when the tag should be skipped.
	 */
	public function is_excluded( string $tag ): bool {
		// Already has loading= — explicit author intent.
		if ( false !== stripos( $tag, 'loading=' ) ) {
			return true;
		}

		return $this->is_opted_out( $tag );
	}

	/**
	 * Whether the author or the site owner has opted this tag out entirely.
	 *
	 * These are the deliberate escape hatches - a marker attribute, a marker
	 * class, or a user-configured pattern - as opposed to an existing
	 * `loading=` attribute, which only says the author has already decided
	 * the loading strategy. Every rewrite this class performs must honour
	 * them, including width/height injection.
	 *
	 * @since 1.21.7
	 *
	 * @param string $tag The complete opening tag string.
	 * @return bool True when the tag must be left untouched.
	 */
	public function is_opted_out( string $tag ): bool {
		// data-skip-lazy / data-no-lazy attributes.
		if ( false !== stripos( $tag, 'data-skip-lazy' ) ) {
			return true;
		}
		if ( false !== stripos( $tag, 'data-no-lazy' ) ) {
			return true;
		}

		// skip-lazy / no-lazy classes.
		if ( false !== stripos( $tag, 'skip-lazy' ) ) {
			return true;
		}
		if ( false !== stripos( $tag, 'no-lazy' ) ) {
			return true;
		}

		// User-configured exclusion patterns: substring match on the tag.
		// Read from settings directly each call so tests without run() still work.
		$exclusions = $this->settings->get( 'media', 'lazyload_exclusions', array() );
		foreach ( $exclusions as $pattern ) {
			if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current image should be excluded by the above-the-fold rule.
	 *
	 * When `lazyload_exclude_above_fold` is enabled, images before the
	 * `lazyload_above_fold_count` threshold (counted per page) are skipped
	 * from lazy-loading so the browser can load them immediately for LCP.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when this image should be excluded.
	 */
	public function is_above_fold_image(): bool {
		if ( ! $this->settings->get( 'media', 'lazyload_exclude_above_fold', true ) ) {
			return false;
		}

		$count = $this->settings->get( 'media', 'lazyload_above_fold_count', 3 );

		return $this->image_index <= $count;
	}

	/**
	 * Add missing width/height attributes so the browser can reserve the box
	 * before the image loads (CLS).
	 *
	 * Runs only when `lazyload_add_dimensions` is enabled, the <img> carries
	 * neither width nor height, and the tag has not been opted out by the
	 * caller. Dimensions come from attachment metadata where WordPress knows
	 * the image, and from the file only as a fallback - see
	 * resolve_dimensions(). Every stamped tag is marked
	 * `data-cachingout-dim` so inject_dimension_style() can pair it with
	 * `height: auto`; without that companion rule an injected height becomes
	 * the used height on any axis the theme leaves at `auto`, which resizes
	 * CSS-scaled images such as header logos (1.21.7).
	 *
	 * @since 0.1.0
	 *
	 * @param string $tag The complete <img> opening tag.
	 * @return string The tag, possibly with width/height added.
	 */
	private function add_dimensions( string $tag ): string {
		if ( ! $this->settings->get( 'media', 'lazyload_add_dimensions', true ) ) {
			return $tag;
		}

		// Only add if both width and height are missing.
		$has_width  = false !== stripos( $tag, 'width=' );
		$has_height = false !== stripos( $tag, 'height=' );
		if ( $has_width || $has_height ) {
			// If either is present, don't interfere — partial dimensions are
			// likely intentional.
			return $tag;
		}

		// Extract the src attribute.
		if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $src_match ) ) {
			return $tag;
		}

		$size = $this->resolve_dimensions( $tag, $src_match[1] );
		if ( array() === $size ) {
			return $tag;
		}

		// The marker lets the companion stylesheet target exactly the images
		// we changed, and tells a support request what the plugin did.
		$tag = substr_replace(
			$tag,
			' width="' . $size[0] . '" height="' . $size[1] . '" data-cachingout-dim="1"',
			-1,
			0
		);

		return $tag;
	}

	/**
	 * Resolve the dimensions the browser will actually lay this image out at.
	 *
	 * WordPress attachment metadata is preferred over measuring the file.
	 * An image-optimisation plugin (Imagify, ShortPixel, Smush, EWWW) can
	 * re-encode or resize the file on disk, and a retina or converted file
	 * is routinely not the pixel size the page renders. Metadata describes
	 * the size WordPress registered for the exact file named in `src`.
	 *
	 * Measuring the file is the fallback for images outside the media
	 * library - theme and page-builder assets. That path refuses to guess
	 * when the tag carries a `srcset`: the browser may render any candidate,
	 * and an assumed aspect ratio is precisely what distorts a layout.
	 *
	 * @since 1.21.7
	 *
	 * @param string $tag The complete <img> opening tag.
	 * @param string $src The src attribute value.
	 * @return int[] array( width, height ), or an empty array if unresolved.
	 */
	private function resolve_dimensions( string $tag, string $src ): array {
		$from_meta = $this->dimensions_from_attachment( $tag, $src );
		if ( array() !== $from_meta ) {
			return $from_meta;
		}

		// No attachment to ask and more than one candidate file: the rendered
		// ratio is unknowable from here, so leave the tag alone.
		if ( preg_match( '/\bsrcset\s*=/i', $tag ) ) {
			return array();
		}

		$path = $this->url_to_path( $src );
		if ( '' === $path || ! file_exists( $path ) ) {
			return array();
		}

		$size = getimagesize( $path );
		if ( false === $size || empty( $size[0] ) || empty( $size[1] ) ) {
			return array();
		}

		return array( (int) $size[0], (int) $size[1] );
	}

	/**
	 * Look the dimensions up in the attachment metadata for this exact file.
	 *
	 * Identifies the attachment from the `wp-image-<id>` class WordPress
	 * writes on library images, then matches the basename in `src` against
	 * the full-size file and every registered intermediate size, so a
	 * thumbnail gets the thumbnail's dimensions rather than the original's.
	 *
	 * @since 1.21.7
	 *
	 * @param string $tag The complete <img> opening tag.
	 * @param string $src The src attribute value.
	 * @return int[] array( width, height ), or an empty array if unresolved.
	 */
	private function dimensions_from_attachment( string $tag, string $src ): array {
		if ( ! preg_match( '/\bwp-image-(\d+)\b/i', $tag, $id_match ) ) {
			return array();
		}

		$meta = wp_get_attachment_metadata( (int) $id_match[1] );
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$file = wp_basename( rawurldecode( (string) preg_replace( '/[?#].*$/', '', $src ) ) );
		if ( '' === $file ) {
			return array();
		}

		// The full-size file.
		if ( isset( $meta['file'] ) && wp_basename( $meta['file'] ) === $file
			&& ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return array( (int) $meta['width'], (int) $meta['height'] );
		}

		// A registered intermediate size.
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! isset( $size['file'] ) || $size['file'] !== $file ) {
					continue;
				}
				if ( empty( $size['width'] ) || empty( $size['height'] ) ) {
					continue;
				}
				return array( (int) $size['width'], (int) $size['height'] );
			}
		}

		return array();
	}

	/**
	 * Convert an image URL to a server filesystem path.
	 *
	 * Delegates to CachingOut_Local_File (the one URL-to-path mapping since
	 * 1.21.16): same-origin only, query string and percent-encoding handled,
	 * the file must exist inside the install.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Image URL from the src attribute.
	 * @return string Absolute filesystem path, or empty string on failure.
	 */
	public function url_to_path( string $url ): string {
		$path = CachingOut_Local_File::from_url( $url );
		return null === $path ? '' : $path;
	}

	/**
	 * Whether the current request is eligible for lazy-load transformation.
	 *
	 * Never transform output on admin pages, AJAX, REST API, feeds,
	 * cron, CLI, XML-RPC, or wp-login.php.
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	public function is_frontend(): bool {
		if ( is_admin() ) {
			return false;
		}
		if ( wp_doing_ajax() ) {
			return false;
		}
		if ( wp_doing_cron() ) {
			return false;
		}
		if ( is_feed() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		// wp-login.php check.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue the lazy-load JavaScript.
	 *
	 * Two scripts:
	 * 1. loading="lazy" polyfill — inline, dependency-free, for browsers
	 *    without native support.
	 * 2. Background-image Intersection Observer — inline, activates
	 *    background-image deferred by lazy_background().
	 *
	 * @since 0.1.0
	 * @return void
	 */
	public function enqueue_scripts(): void {
		$this->enqueue_dimension_style();

		// Register a minimal handle so we can use wp_add_inline_script.
		wp_register_script(
			'cachingout-lazyload',
			false,
			array(),
			CACHINGOUT_VERSION,
			true
		);
		wp_enqueue_script( 'cachingout-lazyload' );

		// Polyfill: loading="lazy" for browsers without native support.
		$polyfill = $this->inline_script( 'lazyload-fallback' );
		wp_add_inline_script( 'cachingout-lazyload', $polyfill );

		// Background-image lazy load via IntersectionObserver.
		$bg_script = $this->inline_script( 'lazyload-background' );
		wp_add_inline_script( 'cachingout-lazyload', $bg_script );

		// Videos: load the source when near the viewport, play (if it was an
		// autoplay video) while visible, pause when scrolled away. Embedded
		// YouTube/Vimeo players are paused when scrolled away via their
		// postMessage APIs; iframes added later (click-to-load facades) are
		// picked up by a MutationObserver.
		if ( $this->settings->get( 'media', 'lazyload_videos', true ) ) {
			$video_script = $this->inline_script( 'lazyload-video' );
			wp_add_inline_script( 'cachingout-lazyload', $video_script );
		}
	}

	/**
	 * Read one of the plugin's own front-end scripts for inlining.
	 *
	 * The scripts are small enough to print in the page, which saves a
	 * request each. They ship as files so their source can be read:
	 * assets/js/<name>.min.js is what is printed, assets/js/src/<name>.js
	 * is the same code, formatted and commented.
	 *
	 * @since 1.21.13
	 *
	 * @param string $name Script name, without path or extension.
	 * @return string The script, or '' when the file cannot be read.
	 */
	private function inline_script( string $name ): string {
		$path = CACHINGOUT_PATH . 'assets/js/' . $name . '.min.js';
		if ( ! is_readable( $path ) ) {
			return '';
		}

		return trim( (string) file_get_contents( $path ) );
	}
}