<?php
/**
 * WPML compatibility — registers purge hooks for WPML translation events.
 *
 * All caching decisions route through CachingOut_Cacheability and
 * CachingOut_Cache_Key. The cache-key language dimension (Phase 1) already
 * separates cache files per language via ICL_LANGUAGE_CODE. This module
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
 * WPML compat module.
 *
 * @since 0.1.0
 */
class CachingOut_WPML {

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
	 * Register all WPML translation hooks.
	 *
	 * Must be called at `init` priority 25 or later, after WPML has loaded
	 * its constants and functions. Does nothing when WPML is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return;
		}

		// Purge when a translation is saved or completed.
		add_action( 'icl_pro_translation_saved', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'icl_pro_translation_completed', array( $this, 'purge_all' ), 10, 0 );

		// Purge when language settings change (languages added/removed/changed).
		add_action( 'wpml_language_added', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'wpml_language_removed', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'wpml_language_updated', array( $this, 'purge_all' ), 10, 0 );

		// Purge when the active languages list changes.
		add_action( 'wpml_update_active_languages', array( $this, 'purge_all' ), 10, 0 );

		// A WPML settings save can change the URL mode, which decides whether
		// the drop-in may ignore the language cookies (1.21.2): refresh the
		// runtime snapshot and start the cache over.
		add_action( 'update_option_icl_sitepress_settings', array( $this, 'on_wpml_settings_saved' ), 10, 0 );
	}

	/**
	 * Whether WPML's URL mode already carries the language.
	 *
	 * Reads the `icl_sitepress_settings` option directly:
	 * `language_negotiation_type` 1 = directories, 2 = domains (the URL
	 * decides), 3 = language parameter (`?lang=`, a query string the
	 * allow-list already bypasses; the cookie keeps bypassing too). Missing
	 * option or key = false (fail closed).
	 *
	 * @since 1.21.2
	 * @return bool
	 */
	public static function language_is_in_url(): bool {
		$options = get_option( 'icl_sitepress_settings' );
		if ( ! is_array( $options ) || ! isset( $options['language_negotiation_type'] ) ) {
			return false;
		}
		return in_array( (int) $options['language_negotiation_type'], array( 1, 2 ), true );
	}

	/**
	 * `update_option_icl_sitepress_settings`: re-export the runtime snapshot
	 * (the URL mode may have changed) and purge.
	 *
	 * @since 1.21.2
	 * @return void
	 */
	public function on_wpml_settings_saved(): void {
		$this->settings->export_runtime_config();
		$this->purge_all();
	}

	/**
	 * Full cache purge on WPML events.
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