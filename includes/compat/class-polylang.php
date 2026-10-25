<?php
/**
 * Polylang compatibility — registers purge hooks for Polylang translation
 * events.
 *
 * All caching decisions route through CachingOut_Cacheability and
 * CachingOut_Cache_Key. The cache-key language dimension (Phase 1) already
 * separates cache files per language via pll_current_language(). This module
 * ensures that when a translation is saved, the cache is purged.
 *
 * No cache exclusions are registered — multilingual pages ARE cacheable,
 * just with a language-specific cache key.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Polylang compat module.
 *
 * @since 0.1.0
 */
class CachingOut_Polylang {

	/**
	 * Settings manager.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Purge manager.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Purge
	 */
	private $purge;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings $settings Settings manager instance.
	 * @param CachingOut_Purge    $purge    Purge manager instance.
	 */
	public function __construct(
		CachingOut_Settings $settings,
		CachingOut_Purge $purge
	) {
		$this->settings = $settings;
		$this->purge    = $purge;
	}

	/**
	 * Register all Polylang translation hooks.
	 *
	 * Must be called at `init` priority 25 or later, after Polylang has loaded
	 * its functions. Does nothing when Polylang is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return;
		}

		// Purge when translations are saved for a post.
		add_action( 'pll_save_post_translations', array( $this, 'purge_all' ), 10, 0 );

		// Purge when languages are added, removed, or updated.
		add_action( 'pll_add_language', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'pll_delete_language', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'pll_update_language', array( $this, 'purge_all' ), 10, 0 );

		// Purge when the string translations are updated.
		add_action( 'pll_update_strings', array( $this, 'purge_all' ), 10, 0 );

		// A Polylang settings save can change the URL mode, which decides
		// whether the drop-in may ignore the pll_language cookie (1.21.2):
		// refresh the runtime snapshot and start the cache over.
		add_action( 'update_option_polylang', array( $this, 'on_polylang_settings_saved' ), 10, 0 );
	}

	/**
	 * Whether Polylang's URL mode already carries the language.
	 *
	 * Reads the `polylang` option directly (no Polylang function needed, so
	 * the answer is the same at activation, on a settings save and in tests):
	 * `force_lang` 0 = "the language is set from content" (same URL for every
	 * language: the cookie decides, keep bypassing), 1 = directory name,
	 * 2 = subdomain, 3 = separate domains (the URL decides). Missing option or
	 * key = false (fail closed).
	 *
	 * @since 1.21.2
	 * @return bool
	 */
	public static function language_is_in_url(): bool {
		$options = get_option( 'polylang' );
		if ( ! is_array( $options ) || ! isset( $options['force_lang'] ) ) {
			return false;
		}
		return (int) $options['force_lang'] >= 1;
	}

	/**
	 * `update_option_polylang`: re-export the runtime snapshot (the URL mode
	 * may have changed) and purge, since every language URL may have moved.
	 *
	 * @since 1.21.2
	 * @return void
	 */
	public function on_polylang_settings_saved(): void {
		$this->settings->export_runtime_config();
		$this->purge_all();
	}

	/**
	 * Full cache purge on Polylang events.
	 *
	 * Public so it can be used as a hook callback. Delegates to the purge
	 * manager, which sweeps all language-specific cache files via the
	 * extended `purge_url()` method.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function purge_all(): void {
		$this->purge->purge_all();
	}
}