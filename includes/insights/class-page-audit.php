<?php
/**
 * Page Audit — find the speed fixes a site owner cannot see (Spec 24).
 *
 * Fetches each tracked page the way a visitor would and runs deterministic
 * checks over the HTML and response headers. Every finding is a Fix (a
 * plugin toggle, applied through the validated FIX_GROUPS allow-list), an
 * Advice (theme / site config, plain language) or a Pass. No external API,
 * no key, no rendering; findings are stored locally in a transient.
 *
 * @package CachingOut
 * @since   0.1.16
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page audit engine.
 *
 * @since 0.1.16
 */
class CachingOut_Page_Audit {

	/**
	 * Transient key prefix.
	 *
	 * @since 0.1.16
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'cachingout_page_audit_';

	/**
	 * Result lifetime.
	 *
	 * @since 0.1.16
	 * @var int
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Fix groups: fix_id => "section.key" paths applied together. Every path
	 * must exist in CachingOut_Settings::DEFAULTS (pinned by test). The first
	 * path is the primary — a fix finding is only emitted while it is off.
	 *
	 * @since 0.1.16
	 * @var array<string, string[]>
	 */
	const FIX_GROUPS = array(
		'lcp_priority'        => array( 'media.lcp_priority_enabled' ),
		'lazyload_images'     => array( 'media.lazyload_enabled', 'media.lazyload_images' ),
		'lazyload_dimensions' => array( 'media.lazyload_enabled', 'media.lazyload_add_dimensions' ),
		'lazyload_videos'     => array( 'media.lazyload_enabled', 'media.lazyload_videos' ),
		'embeds'              => array( 'media.embeds_enabled' ),
		'minify_css'          => array( 'file_optimization.minify_css' ),
		'minify_js'           => array( 'file_optimization.minify_js' ),
		'combine_css'         => array( 'file_optimization.combine_css' ),
		'delay_analytics'     => array( 'file_optimization.delay_js', 'file_optimization.delay_js_analytics' ),
		'browser_cache'       => array( 'browser_cache.gzip', 'browser_cache.brotli', 'browser_cache.expires' ),
		'page_cache'          => array( 'cache.enabled' ),
	);

	/**
	 * Human labels for fix buttons.
	 *
	 * @since 0.1.16
	 * @var array<string, string>
	 */
	const FIX_LABELS = array(
		'lcp_priority'        => 'Enable "LCP Image Priority"',
		'lazyload_images'     => 'Enable "LazyLoad" for images',
		'lazyload_dimensions' => 'Enable "LazyLoad" with automatic image dimensions',
		'lazyload_videos'     => 'Enable "LazyLoad Videos"',
		'embeds'              => 'Enable "Replace YouTube Embeds with Thumbnail"',
		'minify_css'          => 'Enable "Minify CSS"',
		'minify_js'           => 'Enable "Minify JavaScript"',
		'combine_css'         => 'Enable "Combine CSS"',
		'delay_analytics'     => 'Enable "Delay JavaScript" with "Also delay analytics"',
		'browser_cache'       => 'Enable "Browser Caching" (GZIP, Brotli, Expires)',
		'page_cache'          => 'Enable "Page Cache"',
	);

	/**
	 * Finding id => the CachingOut_Features key its fix belongs to.
	 *
	 * Every finding carries a `feature` key (spec 30) so the Insights view
	 * can say, in one sentence and with one link, that the fix is part of
	 * Caching Out Pro. The finding itself is always rendered in full: never
	 * hidden, never truncated, never blurred. Ids absent from this map fall
	 * back to their fix group's primary setting, then to `page_audit` (the
	 * free feature that owns the audit itself).
	 *
	 * @since 1.21.0
	 * @var array<string, string>
	 */
	const FINDING_FEATURES = array(
		// Free.
		'lcp-image-hint'                => 'lazyload',
		'lcp-image-weight'              => 'lazyload',
		'eager-below-fold-images'       => 'lazyload',
		'eager-below-fold-images-forced' => 'lazyload',
		'missing-image-dimensions'      => 'lazyload',
		'eager-videos'                  => 'lazyload',
		'excluded-video-inline-play'    => 'lazyload',
		'unminified-stylesheets'        => 'minify',
		'unminified-scripts'            => 'minify',
		'response-compression'          => 'browser_cache',
		'static-cache-headers'          => 'browser_cache',
		'page-cache'                    => 'page_cache',
		'session-cookie-on-homepage'    => 'session_cookie_bypass',
		'font-preloads'                 => 'page_audit',
		'rival-cache-remnants'          => 'page_audit',
		'fetch-failed'                  => 'page_audit',
		// Pro.
		'eager-embeds'                  => 'embeds',
		'duplicate-stylesheets'         => 'combine',
		'render-blocking-stylesheets'   => 'combine',
		'duplicate-tag-loaders'         => 'delay_js',
		'undelayed-tag-scripts'         => 'delay_js',
		'inline-tag-manager-snippet'    => 'delay_js',
		'delayed-library-inline-init'   => 'delay_js',
		'slider-stylesheet-deferred'    => 'critical_css',
		'layout-stylesheet-deferred'    => 'critical_css',
		'layout-stylesheet-kept'        => 'critical_css',
		'plugin-assets-unused-on-page'  => 'asset_unloading',
	);

	/**
	 * Delayed libraries whose initialisers commonly live in inline scripts:
	 * URL fragment => [ human name, regex that spots the initialiser in JS ].
	 *
	 * @since 0.1.17
	 * @var array<string, array{0: string, 1: string}>
	 */
	const DELAYED_LIBRARY_GLOBALS = array(
		'swiper'       => array( 'Swiper', '/\bnew\s+Swiper\s*\(/' ),
		'splide'       => array( 'Splide', '/\bnew\s+Splide\s*\(/' ),
		'slick'        => array( 'Slick', '/\.slick\s*\(/' ),
		'owl.carousel' => array( 'Owl Carousel', '/\.owlCarousel\s*\(/' ),
		'flickity'     => array( 'Flickity', '/\bnew\s+Flickity\s*\(|\.flickity\s*\(/' ),
		'glide'        => array( 'Glide', '/\bnew\s+Glide\s*\(/' ),
		'tiny-slider'  => array( 'Tiny Slider', '/\btns\s*\(/' ),
		'gsap'         => array( 'GSAP', '/\bgsap\s*\./' ),
		'lottie'       => array( 'Lottie', '/\b(?:lottie|bodymovin)\s*\./' ),
		'aos'          => array( 'AOS', '/\bAOS\s*\.\s*init\s*\(/' ),
		'isotope'      => array( 'Isotope', '/\.isotope\s*\(|\bnew\s+Isotope\s*\(/' ),
		'masonry'      => array( 'Masonry', '/\.masonry\s*\(|\bnew\s+Masonry\s*\(/' ),
		'fancybox'     => array( 'Fancybox', '/\.fancybox\s*\(|\bFancybox\s*\.\s*bind\s*\(/' ),
		'magnific'     => array( 'Magnific Popup', '/\.magnificPopup\s*\(/' ),
	);

	/**
	 * Plugin settings.
	 *
	 * @since 0.1.16
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Optional injected remnant scanner (see set_remnant_scanner()).
	 *
	 * @since 0.1.17
	 * @var CachingOut_Rival_Remnants|null
	 */
	private $remnant_scanner = null;

	/**
	 * Tracked-page provider (Insights).
	 *
	 * @since 0.1.16
	 * @var callable
	 */
	private $pages_provider;

	/**
	 * Constructor.
	 *
	 * @since 0.1.16
	 *
	 * @param CachingOut_Settings $settings       Settings.
	 * @param callable             $pages_provider Returns the tracked pages array (slug => [label, url]).
	 */
	public function __construct( CachingOut_Settings $settings, callable $pages_provider ) {
		$this->settings       = $settings;
		$this->pages_provider = $pages_provider;
	}

	// ── Storage / fetch ────────────────────────────────────────────────────

	/**
	 * Stored result for a page, or null.
	 *
	 * @since 0.1.16
	 *
	 * @param string $page_slug Tracked page slug.
	 * @return array|null
	 */
	public function get_result( string $page_slug ) {
		$result = get_transient( self::TRANSIENT_PREFIX . sanitize_key( $page_slug ) );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Stored results for every tracked page (pages without a result are
	 * present with null).
	 *
	 * @since 0.1.16
	 * @return array slug => [label, url, result|null]
	 */
	public function get_all_results(): array {
		$out = array();
		foreach ( (array) call_user_func( $this->pages_provider ) as $slug => $page ) {
			$out[ $slug ] = array(
				'label'  => $page['label'],
				'url'    => $page['url'],
				'result' => $this->get_result( $slug ),
			);
		}
		return $out;
	}

	/**
	 * Maximum length of the chat digest in bytes (Spec 24 Phase 2).
	 *
	 * @since 0.1.16
	 * @var int
	 */
	const SUMMARY_MAX = 2048;

	/**
	 * Bytes above which the likely LCP image is reported as heavy.
	 *
	 * @since 0.1.17
	 * @var int
	 */
	const LCP_IMAGE_MAX_BYTES = 51200;

	/**
	 * Deferred stylesheets checked for coverage per page (spec 29), the
	 * same bound as CachingOut_Critical_CSS::MAX_GUARDED_SHEETS.
	 *
	 * @since 0.1.21
	 * @var int
	 */
	const COVERAGE_MAX_SHEETS = 8;

	/**
	 * Plain-text digest of the stored findings for the AI chat
	 * (Spec 24 Phase 2).
	 *
	 * One line per non-pass finding per page ("slug: id [STATUS] title")
	 * plus a per-page pass count. Finding ids, titles and statuses only -
	 * never page content. Bounded to SUMMARY_MAX. Empty string when no
	 * page has a stored result, so the chat client can omit the field.
	 *
	 * @since 0.1.16
	 * @return string
	 */
	public function summary(): string {
		$lines = array();

		foreach ( $this->get_all_results() as $slug => $page ) {
			$result = $page['result'];
			if ( ! is_array( $result ) || empty( $result['findings'] ) ) {
				continue;
			}

			$passes = 0;
			foreach ( $result['findings'] as $f ) {
				if ( 'pass' === $f['status'] ) {
					$passes++;
					continue;
				}
				$label   = 'fix' === $f['status']
					? 'FIX via ' . ( '' !== $f['fix_label'] ? $f['fix_label'] : (string) $f['fix_id'] )
					: 'ADVICE/' . $f['severity'];
				$lines[] = $slug . ': ' . $f['id'] . ' [' . $label . '] ' . $f['title'];
			}
			$lines[] = $slug . ': ' . $passes . ' checks pass (audited ' . ( $result['timestamp'] ?? '?' ) . ')';
		}

		if ( array() === $lines ) {
			return '';
		}

		$out = implode( "\n", $lines );
		if ( strlen( $out ) > self::SUMMARY_MAX ) {
			$out = substr( $out, 0, self::SUMMARY_MAX );
		}

		return $out;
	}

	/**
	 * Fetch a tracked page and audit it. Never throws; fetch problems are
	 * a single `fetch-failed` advice finding.
	 *
	 * @since 0.1.16
	 *
	 * @param string $page_slug Tracked page slug.
	 * @return array|WP_Error Result, or WP_Error for an unknown slug.
	 */
	public function run( string $page_slug ) {
		$pages = (array) call_user_func( $this->pages_provider );
		if ( ! isset( $pages[ $page_slug ] ) ) {
			return new WP_Error( 'cachingout_invalid_page', __( 'Invalid page slug.', 'cachingout' ) );
		}
		$url = $pages[ $page_slug ]['url'];

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'cookies'     => array(),
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, applied to a loopback request.
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array(
					'Accept-Encoding' => 'gzip, br',
					'User-Agent'      => 'CachingOut-PageAudit/' . ( defined( 'CACHINGOUT_VERSION' ) ? CACHINGOUT_VERSION : '0' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = $this->failed_result( $url, $response->get_error_message() );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( 200 !== $code ) {
				$result = $this->failed_result( $url, sprintf( 'HTTP %d', $code ) );
			} elseif ( strlen( $body ) < 1024 ) {
				$result = $this->failed_result( $url, __( 'Response body was under 1 KB.', 'cachingout' ) );
			} else {
				$headers = $this->normalise_headers( wp_remote_retrieve_headers( $response ) );
				$result  = $this->analyse( $body, $headers, $url );
				$result['asset_headers'] = $this->probe_first_stylesheet( $body, $url );
				$result['findings']      = $this->apply_asset_headers( $result['findings'], $result['asset_headers'] );
				$result['findings']      = $this->sort_findings( $result['findings'] );
				$result['counts']        = $this->count( $result['findings'] );
			}
		}

		$result['page']      = $page_slug;
		$result['timestamp'] = gmdate( 'c' );
		set_transient( self::TRANSIENT_PREFIX . sanitize_key( $page_slug ), $result, self::TTL );

		return $result;
	}

	// ── Analysis (pure) ────────────────────────────────────────────────────

	/**
	 * Run every check over one page. Pure: no network, no side effects.
	 *
	 * @since 0.1.16
	 *
	 * @param string $html    Page HTML.
	 * @param array  $headers Lower-cased response headers (name => value).
	 * @param string $url     Page URL (for local/remote decisions).
	 * @return array {url, findings[], counts}
	 */
	public function analyse( string $html, array $headers, string $url ): array {
		$headers  = array_change_key_case( $headers, CASE_LOWER );
		$findings = array();
		$checks   = array(
			'check_lcp_image_hint',
			'check_eager_below_fold_images',
			'check_missing_image_dimensions',
			'check_eager_videos',
			'check_eager_embeds',
			'check_render_blocking_stylesheets',
			'check_unminified_scripts',
			'check_tag_manager_scripts',
			'check_inline_tag_manager_snippet',
			'check_slider_stylesheet_deferred',
			'check_layout_stylesheet_deferred',
			'check_layout_stylesheet_kept',
			'check_delayed_library_inline_init',
			'check_excluded_video_inline_play',
			'check_plugin_assets_unused_on_page',
			'check_rival_remnants',
			'check_response_compression',
			'check_font_preloads',
			'check_session_cookie',
			'check_page_cache',
		);

		foreach ( $checks as $check ) {
			try {
				$found = $this->$check( $html, $headers, $url );
			} catch ( \Throwable $e ) {
				$found = array();
			}
			foreach ( (array) $found as $finding ) {
				if ( is_array( $finding ) && isset( $finding['id'] ) ) {
					$findings[] = $this->normalise_finding( $finding );
				}
			}
		}

		/**
		 * Filter the extra Page Audit checks (1.21.5; the Pro add-on registers
		 * its own). Each entry is a callable
		 * `( string $html, array $headers, string $url ): array[]` returning
		 * findings shaped like the built-in ones (id, status, severity,
		 * title, detail, evidence, optional fix_id / action / feature). A
		 * callable that throws contributes nothing; every finding is
		 * normalised like a built-in one.
		 *
		 * @since 1.21.5
		 *
		 * @param callable[] $extra Extra checks, default none.
		 */
		$extra = apply_filters( 'cachingout_page_audit_checks', array() );
		foreach ( (array) $extra as $callback ) {
			if ( ! is_callable( $callback ) ) {
				continue;
			}
			try {
				$found = call_user_func( $callback, $html, $headers, $url );
			} catch ( \Throwable $e ) {
				$found = array();
			}
			foreach ( (array) $found as $finding ) {
				if ( is_array( $finding ) && isset( $finding['id'] ) ) {
					$findings[] = $this->normalise_finding( $finding );
				}
			}
		}

		/**
		 * Filter the Page Audit findings after every check has run (spec 38).
		 *
		 * Lets an add-on add a finding, or amend one (for example attach a
		 * link action to `lcp-image-weight`). Each returned entry is
		 * normalised again exactly like a built-in finding, so a malformed
		 * status, severity or action cannot reach the view; entries that are
		 * not arrays or carry no `id` are dropped; a non-array result keeps the
		 * findings as they were.
		 *
		 * @since 1.24.0
		 *
		 * @param array[] $findings Normalised findings, unsorted.
		 * @param string  $url      The audited URL.
		 */
		$filtered = apply_filters( 'cachingout_page_audit_findings', $findings, $url );
		if ( ! is_array( $filtered ) ) {
			$filtered = $findings; // A broken callback never wipes the audit.
		}
		$findings = array();
		foreach ( $filtered as $finding ) {
			if ( is_array( $finding ) && isset( $finding['id'] ) ) {
				$findings[] = $this->normalise_finding( $finding );
			}
		}

		$findings = $this->sort_findings( $findings );

		return array(
			'url'      => $url,
			'findings' => $findings,
			'counts'   => $this->count( $findings ),
		);
	}

	// ── Checks ─────────────────────────────────────────────────────────────

	/**
	 * The likely LCP image lacks fetchpriority="high".
	 */
	private function check_lcp_image_hint( string $html, array $headers, string $url ): array {
		if ( ! class_exists( 'CachingOut_LCP_Priority' ) ) {
			return array();
		}
		$candidate = ( new CachingOut_LCP_Priority( $this->settings ) )->find_lcp_candidate( $html );
		if ( null === $candidate ) {
			return array( $this->pass( 'lcp-image-hint', __( 'No large early image to prioritise', 'cachingout' ), __( 'This page has no obvious hero image, so there is nothing to hint.', 'cachingout' ), 'low' ) );
		}
		$evidence = array( $this->short( $candidate['src'] ) );
		$out      = array();
		if ( preg_match( '/\bfetchpriority\s*=\s*["\']high["\']/i', $candidate['tag'] ) ) {
			$preloaded = (bool) preg_match( '/<link\b[^>]*\bas\s*=\s*["\']image["\']/i', $html );
			$out[]     = $this->pass(
				'lcp-image-hint',
				__( 'Hero image is prioritised', 'cachingout' ),
				$preloaded
					? __( 'The likely Largest Contentful Paint image carries fetchpriority="high" and a preload hint.', 'cachingout' )
					: __( 'The likely Largest Contentful Paint image carries fetchpriority="high".', 'cachingout' ),
				'high',
				$evidence
			);
		} else {
			$out[] = $this->fix_or_advice(
				'lcp_priority',
				'lcp-image-hint',
				'high',
				__( 'Hero image has no priority hint', 'cachingout' ),
				__( 'The image most likely to be your Largest Contentful Paint is fetched at normal priority, behind stylesheets, fonts and scripts. A fetchpriority="high" attribute plus a preload hint moves it to the front of the queue.', 'cachingout' ),
				__( 'LCP Image Priority is on but did not hint this image — it may be excluded, inside <noscript>, or lazy-loaded by the theme. Ask your theme developer to add fetchpriority="high" to it, or adjust the "Never prioritise" list.', 'cachingout' ),
				$evidence
			);
		}

		// Weight: a priority hint cannot shorten the download itself.
		$bytes = $this->local_image_bytes( $candidate['src'] );
		if ( $bytes > self::LCP_IMAGE_MAX_BYTES ) {
			$weight = $this->advice(
				'lcp-image-weight',
				'medium',
				sprintf( /* translators: %s: size such as "86 KB" */ __( 'Hero image weighs %s', 'cachingout' ), size_format( $bytes ) ),
				$this->lcp_weight_detail(),
				array( $this->short( $candidate['src'] ) . ' — ' . size_format( $bytes ) )
			);
			// The full image URL (spec 38): the evidence carries a shortened
			// path only, and an add-on needs the whole src to find the image.
			$weight['subject'] = esc_url_raw( (string) $candidate['src'] );
			$out[]             = $weight;
		}
		return $out;
	}

	/**
	 * Detail text of the `lcp-image-weight` finding.
	 *
	 * Without Caching Out Pro switched on, one plain sentence says Pro can
	 * do the compression (spec 38, decision 4). Text only: no button, no
	 * disabled control, nothing locked in the free plugin.
	 *
	 * @since 1.24.0
	 *
	 * @return string
	 */
	private function lcp_weight_detail(): string {
		$detail = __( 'The likely Largest Contentful Paint image is heavier than it needs to be, and its download time lands directly in your LCP. Re-export it at a lower quality (WebP or AVIF at quality 60-75 is usually indistinguishable) and serve a smaller variant to phones through srcset. Aim for under 50 KB above the fold.', 'cachingout' );
		if ( class_exists( 'CachingOut_Features' ) && CachingOut_Features::pro_available() ) {
			return $detail;
		}
		return $detail . ' ' . __( 'Caching Out Pro can compress this image for you, on your own server, with a one-click restore.', 'cachingout' );
	}

	/**
	 * Size in bytes of a same-origin image file, or 0 when it cannot be
	 * resolved (remote, dynamic, unsupported type). No network.
	 *
	 * @since 0.1.17
	 *
	 * @param string $src Image URL.
	 * @return int
	 */
	private function local_image_bytes( string $src ): int {
		if ( ! class_exists( 'CachingOut_Minified_Files' ) || ! $this->is_local( $src ) ) {
			return 0;
		}
		$ext = strtolower( (string) pathinfo( (string) strtok( $src, '?#' ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) ) {
			return 0;
		}
		$path = ( new CachingOut_Minified_Files() )->resolve_local_path( $src, $ext );
		if ( null === $path ) {
			return 0;
		}
		$size = @filesize( $path );
		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Images after the first three without loading="lazy".
	 */
	private function check_eager_below_fold_images( string $html, array $headers, string $url ): array {
		$imgs = $this->tags( $html, 'img' );
		if ( count( $imgs ) <= 3 ) {
			return array();
		}
		$eager_default = array();
		$eager_forced  = array();
		foreach ( array_slice( $imgs, 3 ) as $tag ) {
			if ( preg_match( '/\bloading\s*=\s*["\']lazy["\']/i', $tag ) || preg_match( '/\bfetchpriority\s*=\s*["\']high["\']/i', $tag ) ) {
				continue;
			}
			$src = $this->attr( $tag, 'src' );
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				continue;
			}
			if ( preg_match( '/\bloading\s*=\s*["\']eager["\']/i', $tag ) ) {
				$eager_forced[] = $this->short( $src );
			} else {
				$eager_default[] = $this->short( $src );
			}
		}
		$out = array();
		if ( $eager_default ) {
			$out[] = $this->fix_or_advice(
				'lazyload_images',
				'eager-below-fold-images',
				'high',
				sprintf( /* translators: %d: count */ _n( '%d image below the fold loads immediately', '%d images below the fold load immediately', count( $eager_default ), 'cachingout' ), count( $eager_default ) ),
				__( 'Images the visitor cannot see yet compete with the hero image and stylesheets for bandwidth. Lazy loading defers them until they are about to scroll into view.', 'cachingout' ),
				__( 'LazyLoad is on but these images were not deferred — they are probably in the exclusion list or above the configured above-the-fold count.', 'cachingout' ),
				array_slice( $eager_default, 0, 8 )
			);
		} elseif ( count( $imgs ) > 3 ) {
			$out[] = $this->pass( 'eager-below-fold-images', __( 'Below-the-fold images are lazy-loaded', 'cachingout' ), __( 'Every image after the first three is deferred until needed.', 'cachingout' ), 'high' );
		}
		if ( $eager_forced ) {
			$out[] = $this->advice(
				'eager-below-fold-images-forced',
				'medium',
				sprintf( /* translators: %d: count */ _n( '%d below-the-fold image is marked loading="eager" by the theme', '%d below-the-fold images are marked loading="eager" by the theme', count( $eager_forced ), 'cachingout' ), count( $eager_forced ) ),
				__( 'The theme explicitly forces these to load immediately, so the plugin leaves them alone. If they are not visible on first paint, ask your theme developer to change loading="eager" to loading="lazy" on them.', 'cachingout' ),
				array_slice( $eager_forced, 0, 8 )
			);
		}
		return $out;
	}

	/**
	 * Images without width/height (layout shift).
	 */
	private function check_missing_image_dimensions( string $html, array $headers, string $url ): array {
		$missing = array();
		foreach ( $this->tags( $html, 'img' ) as $tag ) {
			$src = $this->attr( $tag, 'src' );
			if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
				continue;
			}
			if ( '' === $this->attr( $tag, 'width' ) || '' === $this->attr( $tag, 'height' ) ) {
				$missing[] = $this->short( $src );
			}
		}
		if ( ! $missing ) {
			return array( $this->pass( 'missing-image-dimensions', __( 'All images declare their size', 'cachingout' ), __( 'Width and height attributes let the browser reserve space, avoiding layout shift.', 'cachingout' ), 'medium' ) );
		}
		return array( $this->fix_or_advice(
			'lazyload_dimensions',
			'missing-image-dimensions',
			'medium',
			sprintf( /* translators: %d: count */ _n( '%d image has no width/height', '%d images have no width/height', count( $missing ), 'cachingout' ), count( $missing ) ),
			__( 'Without dimensions the page jumps as each image arrives (Cumulative Layout Shift). The plugin can read each local image once and add the attributes.', 'cachingout' ),
			__( 'Automatic dimensions are on but these images could not be measured — they are remote, dynamic, or excluded. Add width and height attributes in the theme or the image block.', 'cachingout' ),
			array_slice( $missing, 0, 8 )
		) );
	}

	/**
	 * <video> elements not handled by LazyLoad Videos.
	 */
	private function check_eager_videos( string $html, array $headers, string $url ): array {
		if ( ! preg_match_all( '/<video\b[^>]*>/i', $this->without_scripts( $html ), $m ) ) {
			return array();
		}
		$eager = array();
		foreach ( $m[0] as $tag ) {
			if ( false === stripos( $tag, 'data-cachingout-video' ) ) {
				$eager[] = $this->short( $this->attr( $tag, 'src' ) ?: $this->attr( $tag, 'poster' ) ?: 'video' );
			}
		}
		if ( ! $eager ) {
			return array( $this->pass( 'eager-videos', __( 'Videos load only when seen', 'cachingout' ), __( 'Video sources are deferred until they scroll into view and paused when scrolled away.', 'cachingout' ), 'high' ) );
		}
		return array( $this->fix_or_advice(
			'lazyload_videos',
			'eager-videos',
			'high',
			sprintf( /* translators: %d: count */ _n( '%d video downloads before it is seen', '%d videos download before they are seen', count( $eager ), 'cachingout' ), count( $eager ) ),
			__( 'A video source is often the heaviest request on a page. Deferring it until the player is near the viewport — and pausing it when scrolled away — saves that bandwidth for the content the visitor is looking at.', 'cachingout' ),
			__( 'LazyLoad Videos is on but these were not deferred — they are excluded (pattern or data-no-lazy).', 'cachingout' ),
			array_slice( $eager, 0, 6 )
		) );
	}

	/**
	 * YouTube iframes not replaced by the click-to-load facade.
	 */
	private function check_eager_embeds( string $html, array $headers, string $url ): array {
		if ( ! preg_match_all( '/<iframe\b[^>]*\bsrc\s*=\s*["\']https?:\/\/(?:www\.)?youtube(?:-nocookie)?\.com\/embed\/[^"\']*["\'][^>]*>/i', $this->without_scripts( $html ), $m ) ) {
			return array();
		}
		return array( $this->fix_or_advice(
			'embeds',
			'eager-embeds',
			'medium',
			sprintf( /* translators: %d: count */ _n( '%d YouTube player loads on every visit', '%d YouTube players load on every visit', count( $m[0] ), 'cachingout' ), count( $m[0] ) ),
			__( 'A YouTube iframe pulls in roughly 500 KB of player code whether or not anyone presses play. A thumbnail facade loads the real player only on click.', 'cachingout' ),
			__( 'The embed facade is on but these players were not replaced — the markup is not a standard youtube.com/embed iframe.', 'cachingout' ),
			array_map( array( $this, 'short' ), array_slice( $m[0], 0, 4 ) )
		) );
	}

	/**
	 * Render-blocking stylesheet count / minification.
	 */
	private function check_render_blocking_stylesheets( string $html, array $headers, string $url ): array {
		$links = array();
		foreach ( $this->tags( $html, 'link' ) as $tag ) {
			if ( preg_match( '/\brel\s*=\s*["\']stylesheet["\']/i', $tag ) && ! preg_match( '/\bmedia\s*=\s*["\']print["\']/i', $tag ) ) {
				$href = $this->attr( $tag, 'href' );
				if ( '' !== $href ) {
					$links[] = $href;
				}
			}
		}
		if ( ! $links ) {
			return array();
		}
		$out = array();

		// The same sheet enqueued twice (typically once with and once without
		// ?ver=) is downloaded and parsed twice, and each copy blocks paint.
		$seen = array();
		foreach ( $links as $href ) {
			$key          = strtolower( (string) preg_replace( '/^https?:/i', '', (string) strtok( $href, '?#' ) ) );
			$seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 1;
		}
		$duplicates = array();
		foreach ( $seen as $key => $n ) {
			if ( $n > 1 ) {
				$duplicates[] = $this->short( $key ) . ' × ' . $n;
			}
		}
		if ( $duplicates ) {
			$out[] = $this->advice(
				'duplicate-stylesheets',
				'medium',
				sprintf( /* translators: %d: count */ _n( '%d stylesheet is loaded more than once', '%d stylesheets are loaded more than once', count( $duplicates ), 'cachingout' ), count( $duplicates ) ),
				__( 'The same stylesheet URL appears several times on the page (usually once with and once without a ?ver= query), so the browser downloads and parses it every time, and each copy blocks rendering. Ask your theme or plugin developer to enqueue it once.', 'cachingout' ),
				array_slice( $duplicates, 0, 8 )
			);
		}

		$local      = array_values( array_filter( $links, array( $this, 'is_local' ) ) );
		$unminified = array();
		foreach ( $local as $href ) {
			if ( false === strpos( $href, '/cache/cachingout/' ) && ! preg_match( '/\.min\.css(\?|$)/i', $href ) ) {
				$unminified[] = $this->short( $href );
			}
		}
		if ( $unminified ) {
			$out[] = $this->fix_or_advice(
				'minify_css',
				'unminified-stylesheets',
				'high',
				sprintf( /* translators: %d: count */ _n( '%d stylesheet is served unminified', '%d stylesheets are served unminified', count( $unminified ), 'cachingout' ), count( $unminified ) ),
				__( 'Minify CSS serves one consolidated, whitespace-stripped copy of each local stylesheet (folding in @imports) from the cache folder, so the browser downloads less before it can paint.', 'cachingout' ),
				__( 'Minify CSS is on but these stylesheets were not rewritten — they are excluded, cross-origin, or generated after the page cache.', 'cachingout' ),
				array_slice( $unminified, 0, 8 )
			);
		} else {
			$out[] = $this->pass( 'unminified-stylesheets', __( 'Stylesheets are minified', 'cachingout' ), __( 'Local stylesheets are served as minified copies.', 'cachingout' ), 'high' );
		}
		if ( count( $local ) > 4 ) {
			$out[] = $this->fix_or_advice(
				'combine_css',
				'render-blocking-stylesheets',
				'medium',
				sprintf( /* translators: %d: count */ __( '%d render-blocking stylesheets on one page', 'cachingout' ), count( $local ) ),
				__( 'Each stylesheet is a separate round-trip the browser must finish before painting. On HTTP/1.1 hosts, combining consecutive local stylesheets into one file cuts that queue.', 'cachingout' ),
				__( 'Combine CSS is on but the sheets could not be merged — different media attributes or excluded files break the run. On an HTTP/2 host this is less important.', 'cachingout' ),
				array_map( array( $this, 'short' ), array_slice( $local, 0, 8 ) )
			);
		}
		return $out;
	}

	/**
	 * Whether a local script file is already minified by content (BUG-018:
	 * Minify JS deliberately leaves such files alone, so the audit must
	 * not keep reporting them). Unresolvable/unreadable files count as
	 * not minified.
	 *
	 * @since 0.1.17
	 *
	 * @param string $src Script URL.
	 * @return bool
	 */
	private function local_script_is_minified( string $src ): bool {
		if ( ! class_exists( 'CachingOut_Minified_Files' ) || ! class_exists( 'CachingOut_Minify_JS' ) ) {
			return false;
		}
		$path = ( new CachingOut_Minified_Files() )->resolve_local_path( $src, 'js' );
		if ( null === $path || ! is_readable( $path ) ) {
			return false;
		}
		$content = file_get_contents( $path );
		return false !== $content && CachingOut_Minify_JS::looks_minified( $path, $content );
	}

	/**
	 * Local scripts not minified.
	 */
	private function check_unminified_scripts( string $html, array $headers, string $url ): array {
		$unminified = array();
		$local      = 0;
		foreach ( $this->tags( $html, 'script' ) as $tag ) {
			$src = $this->attr( $tag, 'src' );
			if ( '' === $src ) {
				$src = $this->attr( $tag, 'data-cachingout-src' );
			}
			if ( '' === $src || ! $this->is_local( $src ) ) {
				continue;
			}
			$local++;
			if ( false === strpos( $src, '/cache/cachingout/' ) && ! preg_match( '/\.min\.js(\?|$)/i', $src ) && ! $this->local_script_is_minified( $src ) ) {
				$unminified[] = $this->short( $src );
			}
		}
		if ( 0 === $local ) {
			return array();
		}
		if ( ! $unminified ) {
			return array( $this->pass( 'unminified-scripts', __( 'Scripts are minified', 'cachingout' ), __( 'Local scripts are served as minified copies.', 'cachingout' ), 'medium' ) );
		}
		return array( $this->fix_or_advice(
			'minify_js',
			'unminified-scripts',
			'medium',
			sprintf( /* translators: %d: count */ _n( '%d script is served unminified', '%d scripts are served unminified', count( $unminified ), 'cachingout' ), count( $unminified ) ),
			__( 'Minify JavaScript serves a whitespace-stripped copy of each local script from the cache folder; defer/async attributes are preserved.', 'cachingout' ),
			__( 'Minify JavaScript is on but these scripts were not rewritten — they are excluded, already minified, or contain document.write.', 'cachingout' ),
			array_slice( $unminified, 0, 8 )
		) );
	}

	/**
	 * Google tag loaders: duplicates and un-delayed.
	 */
	private function check_tag_manager_scripts( string $html, array $headers, string $url ): array {
		$loaders  = array();
		$undelayed = 0;
		foreach ( $this->tags( $html, 'script' ) as $tag ) {
			$src     = $this->attr( $tag, 'src' );
			$delayed = false;
			if ( '' === $src ) {
				$src     = $this->attr( $tag, 'data-cachingout-src' );
				$delayed = '' !== $src;
			}
			if ( '' === $src || ! preg_match( '/googletagmanager\.com|google-analytics\.com/i', $src ) ) {
				continue;
			}
			$kind = preg_match( '/gtm\.js/i', $src ) ? 'gtm' : 'gtag';
			$loaders[ $kind ] = isset( $loaders[ $kind ] ) ? $loaders[ $kind ] + 1 : 1;
			if ( ! $delayed ) {
				$undelayed++;
			}
		}
		if ( ! $loaders ) {
			return array();
		}
		$out = array();
		if ( count( $loaders ) > 1 ) {
			$out[] = $this->advice(
				'duplicate-tag-loaders',
				'high',
				__( 'Google Analytics is loaded more than once', 'cachingout' ),
				__( 'Both a Tag Manager container (gtm.js) and a direct gtag loader are on the page — typically Site Kit and GTM both configured for GA4. Each is ~170 KB of JavaScript. Keep one: manage tags in GTM and turn off Analytics in Site Kit, or the reverse.', 'cachingout' ),
				array( sprintf( 'gtm.js × %d, gtag/js × %d', $loaders['gtm'] ?? 0, $loaders['gtag'] ?? 0 ) )
			);
		}
		if ( $undelayed > 0 ) {
			$out[] = $this->fix_or_advice(
				'delay_analytics',
				'undelayed-tag-scripts',
				'high',
				sprintf( /* translators: %d: count */ _n( '%d analytics script loads before the page is interactive', '%d analytics scripts load before the page is interactive', $undelayed, 'cachingout' ), $undelayed ),
				__( 'Tag-manager and analytics loaders are large and not needed for first paint. Delaying them until the first interaction (or a timeout) keeps them out of the critical path — the trade-off is that visits shorter than the timeout may not be recorded.', 'cachingout' ),
				__( 'Delay analytics is on but this loader was not delayed — it is in your exclusion list.', 'cachingout' ),
				array( sprintf( '%d loader(s) not delayed', $undelayed ) )
			);
		} else {
			$out[] = $this->pass( 'undelayed-tag-scripts', __( 'Analytics scripts are delayed', 'cachingout' ), __( 'Tag loaders wait for the first interaction or the timeout.', 'cachingout' ), 'high' );
		}
		return $out;
	}

	/**
	 * Inline GTM loader snippet (cannot be delayed).
	 */
	private function check_inline_tag_manager_snippet( string $html, array $headers, string $url ): array {
		if ( ! preg_match( '/\(function\s*\(\s*w\s*,\s*d\s*,\s*s\s*,\s*l\s*,\s*i\s*\)/i', $html ) ) {
			return array();
		}
		return array( $this->advice(
			'inline-tag-manager-snippet',
			'medium',
			__( 'Tag Manager is injected by an inline snippet', 'cachingout' ),
			__( 'The standard GTM snippet creates its script tag from inline code, which Delay JavaScript cannot intercept. If you want the container delayed, load it through a plugin that outputs a normal <script src> tag (e.g. Site Kit) instead of the pasted snippet.', 'cachingout' )
		) );
	}

	/**
	 * A slider/carousel stylesheet that Optimize CSS delivery deferred.
	 *
	 * TODOS 11c. BUG-027 keeps the libraries on
	 * Exclusions::LAYOUT_CRITICAL_PATTERNS render-blocking automatically, but
	 * that list is a denylist: an unlisted or renamed library is still
	 * deferred and still shifts the page, silently. This finding makes that
	 * discoverable by name. Advice only - the remedy is the Excluded CSS
	 * setting, not a toggle.
	 *
	 * @since 0.1.17
	 */
	private function check_slider_stylesheet_deferred( string $html, array $headers, string $url ): array {
		$deferred = array();
		foreach ( $this->tags( $html, 'link' ) as $tag ) {
			if ( ! preg_match( '/\brel\s*=\s*["\']preload["\']/i', $tag ) || ! preg_match( '/\bas\s*=\s*["\']style["\']/i', $tag ) ) {
				continue;
			}
			$href = $this->attr( $tag, 'href' );
			if ( '' !== $href && $this->looks_like_slider_asset( $href ) ) {
				$deferred[] = $this->short( $href );
			}
		}
		if ( ! $deferred ) {
			return array();
		}
		return array( $this->advice(
			'slider-stylesheet-deferred',
			'high',
			sprintf( /* translators: %d: count */ _n( '%d slider stylesheet is deferred and will shift the page', '%d slider stylesheets are deferred and will shift the page', count( $deferred ), 'cachingout' ), count( $deferred ) ),
			__( 'A slider or carousel stylesheet is loaded asynchronously (preload, then swapped in). Until it applies, the slides render as ordinary stacked blocks, then collapse into the slider - a large layout shift that Lighthouse scores heavily, and one that comes and goes between runs. Caching Out keeps the well-known slider stylesheets render-blocking automatically; this one is not on that list. Add its file name to File Optimization > "Excluded CSS" so Optimize CSS delivery leaves it alone.', 'cachingout' ),
			array_slice( $deferred, 0, 6 )
		) );
	}

	/**
	 * Spec 29: a deferred local stylesheet still lays out elements visible
	 * at the top of this page that the inline critical CSS does not cover.
	 *
	 * Recomputed from the sampled HTML and the local files, so it works on
	 * a cached page too: the inline `#cachingout-critical-css` block is
	 * the critical side, every `<link rel="preload" as="style">` the
	 * deferred side, and CachingOut_Critical_Coverage does the comparison
	 * the guard in Optimize CSS delivery does at render time. One finding
	 * per stylesheet, carrying a one-click action that appends the file
	 * name to Excluded CSS (recorded on the Activity page, revertible).
	 * Nothing is emitted when the guard did its job - the expected state.
	 * A stylesheet the plugin cannot read (another host) has no index and
	 * is not reported; a sheet the parser refuses (over 400 KB) is not
	 * reported either, since there is no evidence of layout rules.
	 *
	 * @since 0.1.21
	 */
	private function check_layout_stylesheet_deferred( string $html, array $headers, string $url ): array {
		if ( ! class_exists( 'CachingOut_Critical_Coverage' ) || ! class_exists( 'CachingOut_Optimization_Exclusions' ) ) {
			return array();
		}

		$deferred = array();
		foreach ( $this->tags( $html, 'link' ) as $tag ) {
			if ( ! preg_match( '/\brel\s*=\s*["\']preload["\']/i', $tag ) || ! preg_match( '/\bas\s*=\s*["\']style["\']/i', $tag ) ) {
				continue;
			}
			$href = $this->attr( $tag, 'href' );
			if ( '' === $href || isset( $deferred[ $href ] ) ) {
				continue;
			}
			$path = CachingOut_Optimization_Exclusions::local_path( $href );
			if ( null === $path ) {
				continue;
			}
			$deferred[ $href ] = $path;
			if ( count( $deferred ) >= self::COVERAGE_MAX_SHEETS ) {
				break;
			}
		}
		if ( ! $deferred ) {
			return array();
		}

		$critical_css = '';
		if ( preg_match( '/<style\b[^>]*\bid\s*=\s*["\']cachingout-critical-css["\'][^>]*>(.*?)<\/style\s*>/si', $html, $m ) ) {
			$critical_css = $m[1];
		}
		$visible        = CachingOut_Critical_Coverage::visible_names( $html );
		$critical_index = CachingOut_Critical_Coverage::index( $critical_css );

		$findings = array();
		foreach ( $deferred as $href => $path ) {
			$css = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $css || '' === $css ) {
				continue;
			}
			$report = CachingOut_Critical_Coverage::report( $visible, CachingOut_Critical_Coverage::index( $css ), $critical_index, $path );
			if ( $report['defer'] || 'uncovered' !== $report['reason'] ) {
				continue;
			}
			$file    = basename( (string) preg_replace( '/[?#].*$/s', '', $href ) );
			$finding = $this->advice(
				'layout-stylesheet-deferred',
				'high',
				sprintf( /* translators: %s: stylesheet file name */ __( '%s is deferred but lays out the top of this page', 'cachingout' ), $file ),
				__( 'This stylesheet is loaded asynchronously (preload, then swapped in), yet it holds the layout rules for elements that are visible before the first paint - listed below - and the inline critical CSS does not. The page renders without them and moves when the file arrives: a large layout shift. Optimize CSS delivery normally keeps such a stylesheet render-blocking on its own; this one slipped through (the coverage guard may be switched off by a filter, or the page was cached by an older version). Click "Keep it render-blocking" to add the file name to File Optimization > Excluded CSS; the change is recorded on the Activity page and can be reverted there. Purge the cache afterwards.', 'cachingout' ),
				array_merge( array( $this->short( $href ) ), array_slice( $report['uncovered'], 0, 6 ) )
			);
			$finding['action'] = array(
				'id'    => 'excluded_css_add',
				'value' => $file,
				'label' => __( 'Keep it render-blocking', 'cachingout' ),
			);
			$findings[] = $finding;
		}

		return $findings;
	}

	/**
	 * Spec 29: the stylesheets Optimize CSS delivery deliberately left
	 * render-blocking on this page, from the `X-CachingOut-CSS` debug
	 * header when the sample carried it, else - for the front page - from
	 * the `cachingout_css_kept` option the last uncached render wrote.
	 * Informational: this is the feature working as designed.
	 *
	 * @since 0.1.21
	 */
	private function check_layout_stylesheet_kept( string $html, array $headers, string $url ): array {
		$entries = array();
		$header  = isset( $headers['x-cachingout-css'] ) ? (string) $headers['x-cachingout-css'] : '';

		if ( '' !== $header ) {
			foreach ( explode( ';', $header ) as $part ) {
				$part = trim( $part );
				if ( 0 !== strpos( $part, 'kept=' ) ) {
					continue;
				}
				$bits      = explode( ':', substr( $part, 5 ), 3 );
				$entries[] = array(
					'file'   => trim( $bits[0] ),
					'reason' => isset( $bits[1] ) ? trim( $bits[1] ) : '',
					'names'  => isset( $bits[2] ) ? array_filter( array_map( 'trim', explode( ',', $bits[2] ) ) ) : array(),
				);
			}
		} elseif ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
			$stored = get_option( class_exists( 'CachingOut_Critical_CSS' ) ? CachingOut_Critical_CSS::KEPT_OPTION : 'cachingout_css_kept', array() );
			if ( is_array( $stored ) && ! empty( $stored['kept'] ) && is_array( $stored['kept'] ) ) {
				foreach ( $stored['kept'] as $entry ) {
					if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
						continue;
					}
					$entries[] = array(
						'file'   => (string) $entry['file'],
						'reason' => (string) ( $entry['reason'] ?? '' ),
						'names'  => array_map( 'strval', (array) ( $entry['names'] ?? array() ) ),
					);
				}
			}
		}

		if ( ! $entries ) {
			return array();
		}

		$labels   = array(
			'theme'     => __( 'theme stylesheet', 'cachingout' ),
			'builtin'   => __( 'on the built-in layout-critical list', 'cachingout' ),
			'excluded'  => __( 'listed in Excluded CSS', 'cachingout' ),
			'uncovered' => __( 'lays out visible elements the inline CSS does not cover', 'cachingout' ),
			'parse'     => __( 'could not be parsed, kept to be safe', 'cachingout' ),
		);
		$evidence = array();
		foreach ( array_slice( $entries, 0, 6 ) as $entry ) {
			$line = $entry['file'] . ': ' . ( $labels[ $entry['reason'] ] ?? $entry['reason'] );
			if ( $entry['names'] ) {
				$line .= ' (' . implode( ', ', array_slice( $entry['names'], 0, 6 ) ) . ')';
			}
			$evidence[] = $line;
		}

		return array( $this->advice(
			'layout-stylesheet-kept',
			'low',
			sprintf( /* translators: %d: count */ _n( '%d stylesheet is kept render-blocking because it lays out the top of the page', '%d stylesheets are kept render-blocking because they lay out the top of the page', count( $entries ), 'cachingout' ), count( $entries ) ),
			__( 'Optimize CSS delivery left these stylesheets render-blocking on purpose: the active theme\'s stylesheet, and any stylesheet whose rules lay out elements visible before the first paint that the inline critical CSS does not cover. Deferring them would make the page move as they arrive. Nothing to do. If you want one deferred anyway, the filters cachingout_critical_css_theme_protected and cachingout_critical_css_coverage_guard override the decision, at the cost of a layout shift.', 'cachingout' ),
			$evidence
		) );
	}

	/**
	 * Catalogued plugin assets whose usage markers are absent from the page
	 * (spec 25 discovery finding).
	 *
	 * WordPress stamps every enqueued tag with `id="<handle>-css|-js"`, so
	 * the handle is read from the markup; the catalogue maps it to the
	 * markers that mean "this page uses the plugin". Markers are searched in
	 * script-masked HTML (BUG-020) with every link/script tag removed, so
	 * neither a class name quoted in a JS template nor the asset's own
	 * URL counts as usage. Entries flagged never_suggest (Complianz, OD-11)
	 * are never reported. Advice only: the remedy is a rule on the Unused
	 * CSS/JS tab, previewed before it is saved.
	 *
	 * @since 0.1.18
	 */
	private function check_plugin_assets_unused_on_page( string $html, array $headers, string $url ): array {
		if ( ! class_exists( 'CachingOut_Asset_Catalogue' ) ) {
			require_once CACHINGOUT_PATH . 'includes/optimization/class-asset-catalogue.php';
		}
		$search = CachingOut_Asset_Catalogue::searchable_html( $html );

		$unused = array();
		$seen   = array();
		$bytes  = 0;
		foreach ( array( 'link' => array( 'href', '-css' ), 'script' => array( 'src', '-js' ) ) as $tag_name => $spec ) {
			foreach ( $this->tags( $html, $tag_name ) as $tag ) {
				$id = $this->attr( $tag, 'id' );
				if ( '' === $id || substr( $id, -strlen( $spec[1] ) ) !== $spec[1] ) {
					continue;
				}
				if ( 'link' === $tag_name && ! preg_match( '/\brel\s*=\s*["\'](?:stylesheet|preload)["\']/i', $tag ) ) {
					continue;
				}
				$handle = substr( $id, 0, -strlen( $spec[1] ) );
				if ( isset( $seen[ $handle ] ) ) {
					continue;
				}
				$entry = CachingOut_Asset_Catalogue::lookup( $handle );
				if ( null === $entry || $entry['never_suggest'] || CachingOut_Asset_Catalogue::in_use( $search, $entry ) ) {
					continue;
				}
				$seen[ $handle ] = true;
				$size            = $this->local_asset_bytes( $this->attr( $tag, $spec[0] ) );
				$bytes          += $size;
				$unused[]        = $handle . ' (' . $entry['label'] . ( $size > 0 ? ', ' . size_format( $size ) : '' ) . ')';
			}
		}
		if ( ! $unused ) {
			return array();
		}
		$count = count( $unused );
		return array( $this->advice(
			'plugin-assets-unused-on-page',
			'medium',
			$bytes > 0
				? sprintf( /* translators: 1: count, 2: size such as "48 KB" */ _n( '%1$d plugin file loads on this page but nothing on it uses it (%2$s)', '%1$d plugin files load on this page but nothing on it uses them (%2$s)', $count, 'cachingout' ), $count, size_format( $bytes ) )
				: sprintf( /* translators: %d: count */ _n( '%d plugin file loads on this page but nothing on it uses it', '%d plugin files load on this page but nothing on it uses them', $count, 'cachingout' ), $count ),
			__( 'WordPress plugins load their CSS and JavaScript on every page by default. On this page, the listed files come from plugins whose markup does not appear here, so the requests are typically wasted. The Unused CSS/JS tab under File Optimization lets you add a rule that stops a file from loading on pages that do not use it, with a dependency check and a preview before it is saved. This is one sampled page: check that the plugin is really unused on the pages a rule would cover.', 'cachingout' ),
			array_slice( $unused, 0, 8 )
		) );
	}

	/**
	 * Size in bytes of a same-origin CSS/JS file, or 0 when it cannot be
	 * resolved. No network.
	 *
	 * @since 0.1.18
	 *
	 * @param string $src Asset URL.
	 * @return int
	 */
	private function local_asset_bytes( string $src ): int {
		if ( '' === $src || ! class_exists( 'CachingOut_Minified_Files' ) || ! $this->is_local( $src ) ) {
			return 0;
		}
		$ext = strtolower( (string) pathinfo( (string) strtok( $src, '?#' ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'css', 'js' ), true ) ) {
			return 0;
		}
		$path = ( new CachingOut_Minified_Files() )->resolve_local_path( $src, $ext );
		if ( null === $path ) {
			return 0;
		}
		$size = @filesize( $path );
		return false === $size ? 0 : (int) $size;
	}

	/**
	 * A library that Delay JavaScript defers, initialised by an inline script
	 * that is NOT delayed.
	 *
	 * The orgahair.pl carousel: Delay JS held swiper-bundle.min.js back until
	 * interaction while the theme's inline "new Swiper(...)" ran at
	 * DOMContentLoaded, so the carousel was dead and a drag was a click.
	 *
	 * @since 0.1.17
	 */
	private function check_delayed_library_inline_init( string $html, array $headers, string $url ): array {
		$delayed = array();
		foreach ( $this->tags( $html, 'script' ) as $tag ) {
			$src = $this->attr( $tag, 'data-cachingout-src' );
			if ( '' === $src ) {
				continue;
			}
			$key = $this->delayed_library_key( $src );
			if ( null !== $key && ! isset( $delayed[ $key ] ) ) {
				$delayed[ $key ] = $this->short( $src );
			}
		}
		if ( ! $delayed ) {
			return array();
		}

		$inline = $this->inline_scripts( $html );
		$broken = array();
		foreach ( $delayed as $key => $src ) {
			list( $name, $regex ) = self::DELAYED_LIBRARY_GLOBALS[ $key ];
			foreach ( $inline as $body ) {
				if ( preg_match( $regex, $body ) ) {
					$broken[] = $name . ' <- ' . $src;
					break;
				}
			}
		}

		if ( ! $broken ) {
			return array( $this->pass(
				'delayed-library-inline-init',
				__( 'Delayed libraries have no undelayed inline initialisers', 'cachingout' ),
				__( 'Every library Delay JavaScript holds back is initialised by code that is delayed with it.', 'cachingout' ),
				'high'
			) );
		}
		return array( $this->advice(
			'delayed-library-inline-init',
			'high',
			sprintf( /* translators: %d: count */ _n( '%d delayed library is initialised by an inline script that is not delayed', '%d delayed libraries are initialised by inline scripts that are not delayed', count( $broken ), 'cachingout' ), count( $broken ) ),
			__( 'Delay JavaScript holds these libraries back until the visitor interacts, but an inline script on the page uses them at DOMContentLoaded - so the feature they power (a carousel, an animation, a lightbox) is dead until then. Either add the library to the "Delay JavaScript" exclusions so it loads normally, or, better, move the inline initialiser into an enqueued .js file that depends on the library: both are then delayed together and replayed in order.', 'cachingout' ),
			array_slice( $broken, 0, 6 )
		) );
	}

	/**
	 * A video excluded from LazyLoad (data-no-lazy) that an inline play()
	 * call starts anyway.
	 *
	 * The orgahair.pl promo video: excluding it so the theme's play() kept
	 * working pulled 2.3 MB into page load - preload="none" does not survive
	 * an explicit play(). The right route is the autoplay attribute.
	 *
	 * @since 0.1.17
	 */
	private function check_excluded_video_inline_play( string $html, array $headers, string $url ): array {
		if ( ! $this->settings->get( 'media', 'lazyload_enabled', false ) || ! $this->settings->get( 'media', 'lazyload_videos', false ) ) {
			return array();
		}
		if ( ! preg_match_all( '/<video\b[^>]*>/i', $this->without_scripts( $html ), $m ) ) {
			return array();
		}
		$excluded = array();
		foreach ( $m[0] as $tag ) {
			if ( preg_match( '/\sdata-(?:no|skip)-lazy\b/i', $tag ) ) {
				$excluded[] = $this->short( $this->attr( $tag, 'src' ) ?: $this->attr( $tag, 'poster' ) ?: 'video' );
			}
		}
		if ( ! $excluded ) {
			return array();
		}
		$played = false;
		foreach ( $this->inline_scripts( $html ) as $body ) {
			if ( preg_match( '/\.play\s*\(/', $body ) ) {
				$played = true;
				break;
			}
		}
		if ( ! $played ) {
			return array();
		}
		return array( $this->advice(
			'excluded-video-inline-play',
			'high',
			sprintf( /* translators: %d: count */ _n( '%d video is excluded from LazyLoad and started by an inline play() call', '%d videos are excluded from LazyLoad and started by an inline play() call', count( $excluded ), 'cachingout' ), count( $excluded ) ),
			__( 'The video carries data-no-lazy so the theme\'s own play() call keeps working - but an explicit play() forces the whole file to download during page load, even with preload="none". Remove data-no-lazy and put the autoplay attribute on the <video> instead: LazyLoad Videos turns autoplay into a deferred, muted start when the player scrolls into view, so the theme keeps its behaviour and the file leaves the critical path.', 'cachingout' ),
			array_slice( $excluded, 0, 6 )
		) );
	}

	/**
	 * Leftovers from removed cache plugins (TODOS 17), surfaced on local
	 * pages only - it is a site fact, not a page fact, but the audit is where
	 * people look.
	 *
	 * @since 0.1.17
	 */
	private function check_rival_remnants( string $html, array $headers, string $url ): array {
		if ( ! $this->is_local( $url ) ) {
			return array();
		}
		$scanner = $this->remnant_scanner ?: ( class_exists( 'CachingOut_Rival_Remnants' ) ? new CachingOut_Rival_Remnants() : null );
		if ( ! $scanner ) {
			return array();
		}
		$rows = $scanner->scan();
		if ( ! $rows ) {
			return array();
		}
		$live = (bool) array_filter( $rows, static function ( $row ) {
			return ! empty( $row['live'] );
		} );
		return array( $this->advice(
			'rival-cache-remnants',
			$live ? 'medium' : 'low',
			sprintf( /* translators: %d: count */ _n( '%d leftover from a removed cache plugin', '%d leftovers from removed cache plugins', count( $rows ), 'cachingout' ), count( $rows ) ),
			$live
				? __( 'A previous caching plugin is gone but an .htaccess block of its rules is still in place, so Apache keeps applying them and Caching Out will not write its own browser-caching rules until it is removed. The Filesystem Check page lists every leftover with its exact path and whether it is safe to delete. Caching Out never deletes another plugin\'s files.', 'cachingout' )
				: __( 'A previous caching plugin left folders, files or empty .htaccess markers behind. None of it is active; the Filesystem Check page lists each one with its exact path so you can delete it with your file manager. Caching Out never deletes another plugin\'s files.', 'cachingout' ),
			array_slice( $scanner->summary_lines( $rows ), 0, 6 )
		) );
	}

	/**
	 * Inject a remnant scanner (tests point it at a temporary tree).
	 *
	 * @since 0.1.17
	 *
	 * @param CachingOut_Rival_Remnants|null $scanner Scanner, or null for the default.
	 * @return void
	 */
	public function set_remnant_scanner( ?CachingOut_Rival_Remnants $scanner ): void {
		$this->remnant_scanner = $scanner;
	}

	/**
	 * Whether a stylesheet URL looks like a slider/carousel library asset.
	 *
	 * Deliberately broader than Exclusions::LAYOUT_CRITICAL_PATTERNS (what
	 * the plugin protects automatically): this is what the audit should still
	 * notice when an unlisted library slips through.
	 *
	 * @since 0.1.17
	 */
	private function looks_like_slider_asset( string $href ): bool {
		$base = strtolower( basename( (string) strtok( $href, '?#' ) ) );
		return (bool) preg_match( '/(?:^|[^a-z])(?:slider|carousel|swiper|slick|splide|flickity|glide|tns|lightslider|keen-slider|royalslider|nivo|flexslider|owl)(?:[^a-z]|$)/', $base );
	}

	/**
	 * Which DELAYED_LIBRARY_GLOBALS entry a delayed script URL belongs to.
	 *
	 * @since 0.1.17
	 * @return string|null Key into DELAYED_LIBRARY_GLOBALS, or null.
	 */
	private function delayed_library_key( string $src ): ?string {
		$base = strtolower( basename( (string) strtok( $src, '?#' ) ) );
		foreach ( array_keys( self::DELAYED_LIBRARY_GLOBALS ) as $key ) {
			if ( preg_match( '/(?:^|[^a-z])' . preg_quote( $key, '/' ) . '(?:[^a-z]|$)/', $base ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Bodies of the inline scripts that will actually run at load: no src,
	 * not delayed by Delay JavaScript, and a JavaScript (or absent) type.
	 *
	 * @since 0.1.17
	 * @return string[]
	 */
	private function inline_scripts( string $html ): array {
		if ( ! preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $html, $m, PREG_SET_ORDER ) ) {
			return array();
		}
		$out = array();
		foreach ( $m as $script ) {
			$attrs = $script[1];
			if ( preg_match( '/(?<![\w-])src\s*=/i', $attrs ) || false !== stripos( $attrs, 'cachingoutlazyloadscript' ) ) {
				continue;
			}
			$type = strtolower( $this->attr( '<script' . $attrs . '>', 'type' ) );
			if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
				continue;
			}
			if ( '' !== trim( $script[2] ) ) {
				$out[] = $script[2];
			}
		}
		return $out;
	}

	/**
	 * Document response compression.
	 */
	private function check_response_compression( string $html, array $headers, string $url ): array {
		$encoding = isset( $headers['content-encoding'] ) ? strtolower( (string) $headers['content-encoding'] ) : '';
		if ( preg_match( '/gzip|br|deflate|zstd/', $encoding ) ) {
			return array( $this->pass( 'response-compression', __( 'Pages are compressed', 'cachingout' ), sprintf( /* translators: %s: encoding */ __( 'The HTML is served with %s compression.', 'cachingout' ), $encoding ), 'high' ) );
		}
		return array( $this->fix_or_advice(
			'browser_cache',
			'response-compression',
			'high',
			__( 'Pages are not compressed', 'cachingout' ),
			__( 'The HTML arrived without gzip or Brotli encoding — typically 4–5× more bytes than necessary on every page view. Browser Caching writes the compression and cache-header rules to .htaccess.', 'cachingout' ),
			__( 'Browser Caching is on but the response is still uncompressed — the server may not run Apache mod_deflate/mod_brotli (NGINX needs the equivalent in its config), or a proxy strips the header. Ask your host to enable compression.', 'cachingout' )
		) );
	}

	/**
	 * Font preload count.
	 */
	private function check_font_preloads( string $html, array $headers, string $url ): array {
		$fonts = array();
		foreach ( $this->tags( $html, 'link' ) as $tag ) {
			if ( preg_match( '/\brel\s*=\s*["\']preload["\']/i', $tag ) && preg_match( '/\bas\s*=\s*["\']font["\']/i', $tag ) ) {
				$fonts[] = $this->short( $this->attr( $tag, 'href' ) );
			}
		}
		if ( count( $fonts ) <= 2 ) {
			return array();
		}
		return array( $this->advice(
			'font-preloads',
			'low',
			sprintf( /* translators: %d: count */ __( '%d fonts are preloaded', 'cachingout' ), count( $fonts ) ),
			__( 'Every preloaded font competes with the hero image and stylesheets at high priority. Preload only the one or two weights used above the fold; let the rest load normally with font-display: swap.', 'cachingout' ),
			$fonts
		) );
	}

	/**
	 * Page cache footer marker present.
	 */
	/**
	 * The sampled page starts a PHP session for an anonymous visitor.
	 *
	 * The audit fetch carries no cookies, so a `Set-Cookie: PHPSESSID` on the
	 * response means some plugin or theme calls session_start() on the front
	 * end for everyone. The chokepoint refuses to store such a page and
	 * serves every later request from that visitor live (0.1.20,
	 * `CachingOut_Cacheability::SESSION_COOKIES`), so the site has no page
	 * cache until the session is confined to the pages that need it. Advice
	 * only: the plugin will not switch the session off and the bypass has no
	 * setting.
	 *
	 * @since 0.1.20
	 */
	private function check_session_cookie( string $html, array $headers, string $url ): array {
		$set_cookie = isset( $headers['set-cookie'] ) ? (string) $headers['set-cookie'] : '';
		if ( '' === $set_cookie ) {
			return array();
		}
		$names = array();
		foreach ( class_exists( 'CachingOut_Cacheability' ) ? CachingOut_Cacheability::SESSION_COOKIES : array( 'PHPSESSID' ) as $cookie ) {
			if ( 1 === preg_match( '/(^|[\s,;])' . preg_quote( $cookie, '/' ) . '=/', $set_cookie ) ) {
				$names[] = $cookie;
			}
		}
		if ( array() === $names ) {
			return array();
		}
		return array( $this->advice(
			'session-cookie-on-homepage',
			'high',
			__( 'This page starts a PHP session for every visitor', 'cachingout' ),
			sprintf(
				/* translators: %s: cookie name such as PHPSESSID */
				__( 'The response sets a %s cookie, so a plugin or theme calls session_start() on the front end. A session ties a page to one visitor, so Caching Out never stores it and serves that visitor live from then on: this page has no page cache while that continues. Find the plugin that starts the session (deactivate plugins one at a time, or search their code for session_start) and ask its author to limit the session to the pages that need it, for example checkout. The debug header shows BYPASS:session-cookie for these visitors.', 'cachingout' ),
				implode( ', ', $names )
			),
			$names
		) );
	}

	private function check_page_cache( string $html, array $headers, string $url ): array {
		if ( false !== strpos( $html, '<!-- Caching Out - page generated' ) ) {
			return array( $this->pass( 'page-cache', __( 'Page is served from the page cache', 'cachingout' ), __( 'The static copy skips PHP and the database on every visit.', 'cachingout' ), 'high' ) );
		}
		return array( $this->fix_or_advice(
			'page_cache',
			'page-cache',
			'high',
			__( 'Page is not served from the page cache', 'cachingout' ),
			__( 'Every visit runs PHP and the database. The page cache stores a static copy after the first visit.', 'cachingout' ),
			__( 'The page cache is on but this page was generated live — it is excluded by a rule (query string, cookie, logged-in, or a plugin conflict), or the cache was just purged. Run the audit again after visiting the page once.', 'cachingout' )
		) );
	}

	// ── Asset header probe (network; optional) ─────────────────────────────

	/**
	 * HEAD-request the first local stylesheet to learn its cache headers.
	 *
	 * @since 0.1.16
	 *
	 * @param string $html Page HTML.
	 * @param string $url  Page URL.
	 * @return array Lower-cased headers, or empty.
	 */
	private function probe_first_stylesheet( string $html, string $url ): array {
		foreach ( $this->tags( $html, 'link' ) as $tag ) {
			if ( ! preg_match( '/\brel\s*=\s*["\']stylesheet["\']/i', $tag ) ) {
				continue;
			}
			$href = $this->attr( $tag, 'href' );
			if ( '' === $href || ! $this->is_local( $href ) ) {
				continue;
			}
			if ( 0 === strpos( $href, '//' ) ) {
				$href = 'https:' . $href;
			} elseif ( '/' === $href[0] ) {
				$href = rtrim( home_url(), '/' ) . $href;
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, applied to a loopback request.
			$response = wp_remote_head( $href, array( 'timeout' => 8, 'redirection' => 2, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
			if ( is_wp_error( $response ) ) {
				return array();
			}
			$headers        = $this->normalise_headers( wp_remote_retrieve_headers( $response ) );
			$headers['url'] = $href;
			return $headers;
		}
		return array();
	}

	/**
	 * Turn stylesheet cache headers into a finding.
	 *
	 * @since 0.1.16
	 *
	 * @param array $findings      Existing findings.
	 * @param array $asset_headers From probe_first_stylesheet().
	 * @return array
	 */
	public function apply_asset_headers( array $findings, array $asset_headers ): array {
		if ( ! $asset_headers ) {
			return $findings;
		}
		$cc      = isset( $asset_headers['cache-control'] ) ? (string) $asset_headers['cache-control'] : '';
		$expires = isset( $asset_headers['expires'] ) ? (string) $asset_headers['expires'] : '';
		$max_age = preg_match( '/max-age\s*=\s*(\d+)/i', $cc, $m ) ? (int) $m[1] : 0;
		$long    = $max_age >= DAY_IN_SECONDS || ( '' !== $expires && strtotime( $expires ) > time() + DAY_IN_SECONDS );
		$evidence = array( $this->short( $asset_headers['url'] ?? '' ) . ( '' !== $cc ? ' — cache-control: ' . $cc : ' — no cache-control' ) );

		if ( $long ) {
			$findings[] = $this->normalise_finding( $this->pass( 'static-cache-headers', __( 'Static files carry long cache headers', 'cachingout' ), __( 'Repeat visitors reuse stylesheets, scripts, images and fonts from their browser cache.', 'cachingout' ), 'high', $evidence ) );
		} else {
			$findings[] = $this->normalise_finding( $this->fix_or_advice(
				'browser_cache',
				'static-cache-headers',
				'high',
				__( 'Static files have no long-lived cache headers', 'cachingout' ),
				__( 'Without an Expires / Cache-Control header, every visit re-downloads stylesheets, scripts, images and fonts. Browser Caching writes far-future headers for static file types.', 'cachingout' ),
				__( 'Browser Caching is on but the stylesheet was served without a long max-age — the server may ignore .htaccess (NGINX) or a CDN/proxy overrides it. Ask your host to set far-future cache headers for static files.', 'cachingout' ),
				$evidence
			) );
		}
		return $findings;
	}

	// ── Finding builders ───────────────────────────────────────────────────

	/**
	 * A fix finding while the group's primary toggle is off; otherwise an
	 * advice finding explaining why the plugin's feature did not cover it.
	 */
	private function fix_or_advice( string $fix_id, string $id, string $severity, string $title, string $detail, string $already_on_detail, array $evidence = array() ): array {
		$paths = self::FIX_GROUPS[ $fix_id ] ?? array();
		if ( ! $paths ) {
			return $this->advice( $id, $severity, $title, $detail, $evidence );
		}
		list( $section, $key ) = explode( '.', $paths[0], 2 );
		$primary_on = (bool) $this->settings->get( $section, $key, false );

		if ( $primary_on ) {
			return $this->advice( $id, $severity, $title, $already_on_detail, $evidence );
		}
		return array(
			'id'        => $id,
			'status'    => 'fix',
			'severity'  => $severity,
			'title'     => $title,
			'detail'    => $detail,
			'fix_id'    => $fix_id,
			'fix_label' => self::FIX_LABELS[ $fix_id ] ?? $fix_id,
			'evidence'  => $evidence,
		);
	}

	private function advice( string $id, string $severity, string $title, string $detail, array $evidence = array() ): array {
		return array(
			'id'        => $id,
			'status'    => 'advice',
			'severity'  => $severity,
			'title'     => $title,
			'detail'    => $detail,
			'fix_id'    => null,
			'fix_label' => '',
			'evidence'  => $evidence,
		);
	}

	private function pass( string $id, string $title, string $detail, string $severity = 'low', array $evidence = array() ): array {
		return array(
			'id'        => $id,
			'status'    => 'pass',
			'severity'  => $severity,
			'title'     => $title,
			'detail'    => $detail,
			'fix_id'    => null,
			'fix_label' => '',
			'evidence'  => $evidence,
		);
	}

	private function failed_result( string $url, string $reason ): array {
		$finding = $this->normalise_finding( $this->advice(
			'fetch-failed',
			'low',
			__( 'The page could not be fetched for auditing', 'cachingout' ),
			sprintf( /* translators: %s: reason */ __( 'Reason: %s. Some hosts block a site from requesting itself; the PageSpeed audit still works. Try again later or check the URL.', 'cachingout' ), $reason ),
			array( $url )
		) );
		return array(
			'url'      => $url,
			'findings' => array( $finding ),
			'counts'   => $this->count( array( $finding ) ),
		);
	}

	private function normalise_finding( array $f ): array {
		$out = array(
			'id'        => (string) ( $f['id'] ?? '' ),
			'status'    => in_array( $f['status'] ?? '', array( 'fix', 'advice', 'pass' ), true ) ? $f['status'] : 'advice',
			'severity'  => in_array( $f['severity'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $f['severity'] : 'low',
			'title'     => (string) ( $f['title'] ?? '' ),
			'detail'    => (string) ( $f['detail'] ?? '' ),
			'fix_id'    => isset( $f['fix_id'] ) && is_string( $f['fix_id'] ) && '' !== $f['fix_id'] ? $f['fix_id'] : null,
			'fix_label' => (string) ( $f['fix_label'] ?? '' ),
			'evidence'  => array_values( array_filter( array_map( 'strval', (array) ( $f['evidence'] ?? array() ) ) ) ),
			'action'    => $this->normalise_action( $f['action'] ?? null ),
			'feature'   => $this->feature_for_finding( $f ),
		);
		// Optional `subject` (1.24.0, spec 38): the full URL of what the
		// finding is about. The key exists only when set, so every other
		// finding keeps the exact shape it had before.
		$subject = isset( $f['subject'] ) && is_string( $f['subject'] ) ? esc_url_raw( $f['subject'] ) : '';
		if ( '' !== $subject ) {
			$out['subject'] = $subject;
		}
		return $out;
	}

	/**
	 * The CachingOut_Features key a finding's fix belongs to (spec 30).
	 *
	 * Explicit map first, then the finding's fix group (its primary setting
	 * path names the owning feature), then `page_audit` - the free feature
	 * that owns the audit itself, so an unmapped finding never reads as a
	 * Pro upsell.
	 *
	 * @since 1.21.0
	 *
	 * @param array $f Raw finding.
	 * @return string Feature key.
	 */
	private function feature_for_finding( array $f ): string {
		$id = (string) ( $f['id'] ?? '' );
		if ( isset( self::FINDING_FEATURES[ $id ] ) ) {
			return self::FINDING_FEATURES[ $id ];
		}

		// A registered (Pro) check names its own feature (1.21.5); only a
		// key the gating table knows is accepted.
		$declared = isset( $f['feature'] ) && is_string( $f['feature'] ) ? $f['feature'] : '';
		if ( '' !== $declared && class_exists( 'CachingOut_Features' ) && null !== CachingOut_Features::edition( $declared ) ) {
			return $declared;
		}

		$fix_id = isset( $f['fix_id'] ) && is_string( $f['fix_id'] ) ? $f['fix_id'] : '';
		if ( '' !== $fix_id && isset( self::FIX_GROUPS[ $fix_id ][0] ) && class_exists( 'CachingOut_Features' ) ) {
			$path = self::FIX_GROUPS[ $fix_id ][0];
			if ( false !== strpos( $path, '.' ) ) {
				list( $section, $key ) = explode( '.', $path, 2 );
				$feature               = CachingOut_Features::feature_for_setting( $section, $key );
				if ( null !== $feature ) {
					return $feature;
				}
			}
		}

		return 'page_audit';
	}

	/**
	 * A finding's one-click action (spec 29): id (a key of
	 * CachingOut_Insights::FINDING_ACTIONS), value and button label, or
	 * null when the finding has none.
	 *
	 * Since 1.24.0 (spec 38) an action may instead carry a `url`: an admin
	 * URL of this site, rendered as a link button. A url action needs no
	 * value; an action with neither a valid url nor a value is dropped.
	 *
	 * @since 0.1.21
	 *
	 * @param mixed $action Raw action.
	 * @return array{id: string, value: string, label: string, url?: string}|null
	 */
	private function normalise_action( $action ): ?array {
		if ( ! is_array( $action ) || empty( $action['id'] ) ) {
			return null;
		}
		$url   = $this->admin_action_url( $action['url'] ?? '' );
		$value = isset( $action['value'] ) && is_scalar( $action['value'] ) ? (string) $action['value'] : '';
		if ( '' === $url && '' === $value ) {
			return null;
		}
		$out = array(
			'id'    => sanitize_key( (string) $action['id'] ),
			'value' => $value,
			'label' => (string) ( $action['label'] ?? '' ),
		);
		// The key exists only on link actions, so value actions keep the
		// exact shape they had before 1.24.0.
		if ( '' !== $url ) {
			$out['url'] = $url;
		}
		return $out;
	}

	/**
	 * A finding action's link target, kept only when it is an admin URL of
	 * this site (spec 38): it must start with admin_url() and survive
	 * wp_validate_redirect() unchanged.
	 *
	 * @since 1.24.0
	 *
	 * @param mixed $url Raw URL.
	 * @return string The URL (esc_url_raw), or '' when it is not allowed.
	 */
	private function admin_action_url( $url ): string {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		if ( 0 !== strpos( $url, admin_url() ) || wp_validate_redirect( $url, '' ) !== $url ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/**
	 * fix → advice → pass, then high → low.
	 */
	public function sort_findings( array $findings ): array {
		$status_rank   = array( 'fix' => 0, 'advice' => 1, 'pass' => 2 );
		$severity_rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
		usort(
			$findings,
			static function ( $a, $b ) use ( $status_rank, $severity_rank ) {
				$s = ( $status_rank[ $a['status'] ] ?? 9 ) <=> ( $status_rank[ $b['status'] ] ?? 9 );
				if ( 0 !== $s ) {
					return $s;
				}
				$v = ( $severity_rank[ $a['severity'] ] ?? 9 ) <=> ( $severity_rank[ $b['severity'] ] ?? 9 );
				return 0 !== $v ? $v : strcmp( $a['id'], $b['id'] );
			}
		);
		return array_values( $findings );
	}

	private function count( array $findings ): array {
		$counts = array( 'fix' => 0, 'advice' => 0, 'pass' => 0 );
		foreach ( $findings as $f ) {
			if ( isset( $counts[ $f['status'] ] ) ) {
				$counts[ $f['status'] ]++;
			}
		}
		return $counts;
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * All opening tags of a name, with <noscript>/<template> bodies ignored.
	 */
	/**
	 * HTML with every <script> element blanked out (BUG-020): markup built
	 * inside inline JavaScript is not on the page.
	 *
	 * @since 0.1.17
	 *
	 * @param string $html Full page HTML.
	 * @return string
	 */
	private function without_scripts( string $html ): string {
		if ( class_exists( 'CachingOut_Script_Guard' ) ) {
			return CachingOut_Script_Guard::mask( $html );
		}
		$masked = preg_replace( '/<script\b[^>]*>.*?<\/script\s*>/si', ' ', $html );
		return is_string( $masked ) ? $masked : $html;
	}

	private function tags( string $html, string $name ): array {
		// BUG-020: when looking for anything other than script elements
		// themselves, script bodies are opaque - a theme's inline JS that
		// builds '<img src="' + esc(x) + '">' is not an image on the page.
		$skip   = 'script' === strtolower( $name ) ? '(noscript|template)' : '(noscript|template|script)';
		$masked = preg_replace( '/<' . $skip . '\b[^>]*>.*?<\/\1\s*>/si', '', $html );
		if ( ! is_string( $masked ) ) {
			$masked = $html;
		}
		preg_match_all( '/<' . preg_quote( $name, '/' ) . '\b[^>]*>/i', $masked, $m );
		return $m[0];
	}

	private function attr( string $tag, string $name ): string {
		// (?<![\w-]) so that "src" never matches inside data-src /
		// data-cachingout-src (a delayed script must not read as eager).
		if ( preg_match( '/(?<![\w-])' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ) {
			return trim( $m[2] );
		}
		return '';
	}

	/**
	 * Same-origin (or relative) URL.
	 */
	public function is_local( string $url ): bool {
		$url = trim( $url );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return false;
		}
		if ( ! preg_match( '/^(?:https?:)?\/\//i', $url ) ) {
			return true;
		}
		$host = wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST );
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host && $site && strtolower( $host ) === strtolower( $site );
	}

	private function short( string $url ): string {
		$url = (string) preg_replace( '/^https?:\/\/[^\/]+/i', '', $url );
		$url = (string) strtok( $url, '?' );
		return strlen( $url ) > 90 ? '…' . substr( $url, -88 ) : $url;
	}

	private function normalise_headers( $headers ): array {
		$out = array();
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		foreach ( (array) $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return $out;
	}
}
