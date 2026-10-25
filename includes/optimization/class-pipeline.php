<?php
/**
 * Output-buffer pipeline orchestrator: the ONE front-end buffer.
 *
 * Runs CSS/JS minification, combination, critical CSS, defer JS and delay
 * JS (the optimisation group), then the page filters (LCP priority, lazy
 * loading) in a pinned order on the full page HTML.
 *
 * One buffer, never nested: on WordPress 6.9+ it is core's template
 * enhancement output buffer (the `wp_template_enhancement_output_buffer`
 * filter; core opens and closes the buffer). On older versions the
 * pipeline opens its own buffer at template_redirect, records the level
 * and closes it itself on `shutdown` (priority 0, before core's
 * wp_ob_end_flush_all). Until 1.21.16 LazyLoad and LCP Priority opened
 * two more buffers of their own (DECISIONS 2026-10-19).
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimization pipeline.
 *
 * Each optimization class exposes a filter(string $html): string method.
 * The pipeline collects readable filter labels and their callables, starts
 * one output buffer on template_redirect, and runs them in registration
 * order when the buffer flushes.
 *
 * @since 0.1.0
 */
class CachingOut_Optimization_Pipeline {

	/**
	 * Pinned positions of every filter in the chain, free and Pro alike.
	 *
	 * The order is a correctness property, not a preference: the asset
	 * strip must run before Minify so a stripped stylesheet never enters a
	 * Combine bundle (spec 25); Combine follows Minify; Optimize CSS
	 * delivery reads the combined output; Defer and Delay run last. Since
	 * 1.21.0 the free edition and the Pro add-on register their filters
	 * separately, so the position travels with the label instead of the
	 * registration order (spec 30).
	 *
	 * @since 1.21.0
	 * @var array<string, int>
	 */
	const POSITIONS = array(
		'asset_unload'   => 5,
		'minify_css'     => 10,
		'minify_js'      => 11,
		'combine'        => 20,
		'critical_css'   => 30,
		'google_fonts'   => 40,
		'font_self_host' => 41,
		'defer_js'       => 50,
		// Spec 33 (Pro): adds `defer` to the jQuery tags defer_js skipped.
		'jquery_defer'   => 55,
		'delay_js'       => 60,
		// Page filters (free), after every optimisation filter. LCP first:
		// it marks fetchpriority="high", then LazyLoad leaves that image
		// eager (the nesting order the two buffers had before 1.21.16).
		'lcp_priority'   => 70,
		'lazyload'       => 80,
	);

	/**
	 * The action and priority of the explicit close (own-buffer path).
	 *
	 * @since 1.21.16
	 */
	const CLOSE_HOOK     = 'shutdown';
	const CLOSE_PRIORITY = 0;

	/**
	 * Core's template enhancement output buffer filter (WordPress 6.9+).
	 *
	 * @since 1.21.16
	 */
	const CORE_BUFFER_FILTER = 'wp_template_enhancement_output_buffer';

	/**
	 * The ob level of the buffer this pipeline opened, 0 when none.
	 *
	 * @since 1.21.16
	 * @var int
	 */
	private $ob_level = 0;

	/**
	 * Whether the optimisation group is skipped on this request (a
	 * skip_reason() fired) while the page filters still run.
	 *
	 * @since 1.21.16
	 * @var bool
	 */
	private $optimization_skipped = false;

	/**
	 * Registered filters, sorted by position then registration order.
	 *
	 * Each entry is [ 'label' => string, 'callable' => callable, 'position' => int, 'seq' => int ].
	 *
	 * @since 0.1.0
	 * @var array<int, array{label: string, callable: callable, position: int, seq: int}>
	 */
	private $filters = array();

	/**
	 * Whether any optimization is active (avoids empty buffer).
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private $has_filters = false;

	/**
	 * Register a filter in the pipeline.
	 *
	 * Filters run by position (self::POSITIONS, else the given priority,
	 * default 100 = after every known filter), ties in registration order.
	 * Registering a label twice replaces the earlier callable, so an add-on
	 * can never double a step.
	 *
	 * @since 0.1.0
	 * @since 1.21.0 `$priority`; positions pinned by label.
	 *
	 * @param string   $label    Human-readable label (used for debug headers).
	 * @param callable $filter   Filter callback: function(string $html): string.
	 * @param int|null $priority Position for a label not in self::POSITIONS.
	 * @return void
	 */
	public function add_filter( $label, $filter, $priority = null ) {
		$this->register( $label, $filter, $priority, false );
	}

	/**
	 * Register a PAGE filter: one that runs whenever the buffer runs, even
	 * when skip_reason() has switched the optimisation group off for the
	 * request (a cart or checkout page keeps its lazy loading).
	 *
	 * @since 1.21.16
	 *
	 * @param string   $label    Label (position pinned in self::POSITIONS).
	 * @param callable $filter   Filter callback: function(string $html): string.
	 * @param int|null $priority Position for a label not in self::POSITIONS.
	 * @return void
	 */
	public function add_page_filter( $label, $filter, $priority = null ) {
		$this->register( $label, $filter, $priority, true );
	}

	/**
	 * Shared registration for both groups.
	 *
	 * @since 1.21.16
	 *
	 * @param string   $label    Label.
	 * @param callable $filter   Callback.
	 * @param int|null $priority Position for an unknown label.
	 * @param bool     $page     True for a page filter.
	 * @return void
	 */
	private function register( $label, $filter, $priority, $page ) {
		$label    = (string) $label;
		$position = isset( self::POSITIONS[ $label ] ) ? self::POSITIONS[ $label ] : ( null === $priority ? 100 : (int) $priority );

		foreach ( $this->filters as $i => $entry ) {
			if ( $entry['label'] === $label ) {
				unset( $this->filters[ $i ] );
			}
		}

		$this->filters[] = array(
			'label'    => $label,
			'callable' => $filter,
			'position' => $position,
			'seq'      => count( $this->filters ),
			'page'     => (bool) $page,
		);
		usort(
			$this->filters,
			static function ( $a, $b ) {
				if ( $a['position'] === $b['position'] ) {
					return $a['seq'] <=> $b['seq'];
				}
				return $a['position'] <=> $b['position'];
			}
		);
		$this->has_filters = true;
	}

	/**
	 * Registered filter labels in run order.
	 *
	 * Spec 25 pins Mechanism B (`asset_unload`) first with a test rather
	 * than a comment, so the order is observable.
	 *
	 * @since 0.1.18
	 * @return string[]
	 */
	public function labels(): array {
		return array_values(
			array_map(
				static function ( $entry ) {
					return (string) $entry['label'];
				},
				$this->filters
			)
		);
	}

	/**
	 * Whether any registered filter belongs to the page group.
	 *
	 * @since 1.21.16
	 * @return bool
	 */
	private function has_page_filters(): bool {
		foreach ( $this->filters as $entry ) {
			if ( ! empty( $entry['page'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Start the single output buffer on template_redirect.
	 *
	 * Checks the disable constant before buffering. With CACHINGOUT_DISABLE_OPTIMIZATION
	 * nothing runs, page filters included. A skip_reason() switches the
	 * optimisation group off for the request; the page filters still run.
	 *
	 * On WordPress 6.9+ no buffer of ours is opened: the pipeline filters
	 * core's template enhancement output buffer. Otherwise it opens one
	 * buffer, records its level and closes it on shutdown (end_buffering()).
	 *
	 * @since 0.1.0
	 * @since 1.21.16 Core buffer on 6.9+; explicit close; page filters.
	 * @return void
	 */
	public function start_buffering() {
		if ( defined( 'CACHINGOUT_DISABLE_OPTIMIZATION' ) && CACHINGOUT_DISABLE_OPTIMIZATION ) {
			return;
		}

		if ( ! $this->has_filters ) {
			return;
		}

		if ( ! $this->is_frontend() ) {
			return;
		}

		$this->optimization_skipped = false;

		$reason = self::skip_reason();
		if ( '' !== $reason ) {
			if ( defined( 'CACHINGOUT_DEBUG' ) && CACHINGOUT_DEBUG && ! headers_sent() ) {
				header( 'X-CachingOut-Optimization: skipped; ' . $reason );
			}
			if ( ! $this->has_page_filters() ) {
				return;
			}
			$this->optimization_skipped = true;
		}

		if ( $this->core_buffer_available() ) {
			// Core opens the buffer at wp_before_include_template (priority
			// 1000) because this filter is attached, and closes it itself.
			add_filter( self::CORE_BUFFER_FILTER, array( $this, 'filter' ), 10, 1 );
			return;
		}

		ob_start( array( $this, 'filter' ) );
		$this->ob_level = ob_get_level();
		add_action( self::CLOSE_HOOK, array( $this, 'end_buffering' ), self::CLOSE_PRIORITY );
	}

	/**
	 * Close the buffer start_buffering() opened (own-buffer path).
	 *
	 * Runs on shutdown at priority 0, before core's wp_ob_end_flush_all
	 * (priority 1). Flushing our level runs filter() through the ob
	 * callback exactly as the end-of-request flush did; a buffer another
	 * component opened above ours is flushed first, in stack order, which
	 * is what core's flush-all would do a moment later. A buffer that
	 * refuses to close (not removable) stops the loop: never spin.
	 *
	 * @since 1.21.16
	 * @return void
	 */
	public function end_buffering() {
		$level          = $this->ob_level;
		$this->ob_level = 0;
		while ( $level > 0 && ob_get_level() >= $level ) {
			if ( ! ob_end_flush() ) {
				break;
			}
		}
	}

	/**
	 * The level of the buffer this pipeline opened (0 = none, or core's).
	 *
	 * @since 1.21.16
	 * @return int
	 */
	public function buffer_level(): int {
		return $this->ob_level;
	}

	/**
	 * Whether core's template enhancement output buffer exists (WordPress
	 * 6.9+). Protected so a test can force either path.
	 *
	 * @since 1.21.16
	 * @return bool
	 */
	protected function core_buffer_available(): bool {
		return function_exists( 'wp_start_template_enhancement_output_buffer' );
	}

	/**
	 * Why file optimisation must not touch this request, or '' when it may.
	 *
	 * The one answer for every optimisation feature, inside the buffer
	 * (minify, combine, critical CSS, fonts, defer, jQuery defer, Delay JS)
	 * and outside it (jQuery footer runs at wp_print_scripts and asks here).
	 * Compat modules REGISTER a reason through the filter; no feature decides
	 * for itself - the same shape as the cacheability chokepoint's
	 * exclusions, for the same reason.
	 *
	 * Until 1.21.9 there was no such gate: the page cache bypassed the
	 * WooCommerce cart, checkout and account pages while Delay JS rewrote 54
	 * of the block checkout's 63 script tags, and nothing short of a
	 * must-use plugin could stop it (site team report, 2026-09-17).
	 *
	 * Call at `template_redirect` or later: registered reasons use
	 * conditional tags.
	 *
	 * @since 1.21.9
	 * @return string A short machine-readable reason, or '' to optimise.
	 */
	public static function skip_reason(): string {
		/**
		 * Filters whether file optimisation is skipped for the current request.
		 *
		 * Return a short reason (letters, digits, `-`, `_`, `:`) to skip every
		 * file-optimisation feature on this request; it is reported in the
		 * `X-CachingOut-Optimization: skipped; <reason>` debug header. Return
		 * '' to optimise. `true` is accepted and reported as `filter`.
		 *
		 * @since 1.21.9
		 *
		 * @param string $reason '' unless an earlier callback already skipped.
		 */
		$reason = apply_filters( 'cachingout_skip_optimization', '' );

		if ( true === $reason ) {
			return 'filter';
		}
		if ( ! is_string( $reason ) ) {
			return '';
		}

		return (string) preg_replace( '/[^A-Za-z0-9_:\-]/', '', $reason );
	}

	/**
	 * Single buffer callback — runs all registered filters in order.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html Full page HTML.
	 * @return string Transformed HTML.
	 */
	public function filter( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return $html;
		}

		$start   = microtime( true );
		$applied = 0;

		foreach ( $this->filters as $entry ) {
			if ( $this->optimization_skipped && empty( $entry['page'] ) ) {
				continue;
			}
			$html = (string) call_user_func( $entry['callable'], $html );
			++$applied;
		}

		$elapsed = microtime( true ) - $start;

		// Debug header. When the optimisation group was skipped the
		// "skipped; <reason>" header from start_buffering() stands.
		if ( ! $this->optimization_skipped && defined( 'CACHINGOUT_DEBUG' ) && CACHINGOUT_DEBUG && ! headers_sent() ) {
			// number_format(): a float cast is locale-dependent below PHP 8.0
			// (a Polish locale prints "12,3"). Never interpolate a float.
			header( 'X-CachingOut-Optimization: applied; ' . $applied . ' filters; ' . number_format( $elapsed * 1000, 1, '.', '' ) . 'ms' );
		}

		return $html;
	}

	/**
	 * Check whether the current request is a frontend HTML request.
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	private function is_frontend() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
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

		// Feeds are XML — running the HTML optimization regexes over
		// RSS/Atom corrupts them (audit N24). LazyLoad/embeds already check
		// is_feed(); this closes it for minify/fonts/defer and the rest of
		// the chain behind the single buffer.
		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return false;
		}

		return true;
	}
}