<?php
/**
 * DNS prefetch/preconnect — inject link tags for external domains.
 *
 * Hooks `wp_head` (priority 1) to emit `<link rel="dns-prefetch">` and
 * `<link rel="preconnect">` tags for user-configured external domains.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DNS prefetch/preconnect.
 *
 * @since 0.1.1
 */
class CachingOut_DNS_Prefetch {

	/**
	 * Plugin settings instance.
	 *
	 * @since 0.1.1
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 0.1.1
	 *
	 * @param CachingOut_Settings $settings Plugin settings.
	 */
	public function __construct( CachingOut_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the wp_head hook.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_link_tags' ), 1 );
	}

	/**
	 * Output dns-prefetch and preconnect link tags.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function output_link_tags(): void {
		$domains = $this->get_domains();

		if ( empty( $domains ) ) {
			return;
		}

		foreach ( $domains as $domain ) {
			$href = '//' . $domain;
			echo "<link rel='dns-prefetch' href='" . esc_attr( $href ) . "'>\n";
			echo "<link rel='preconnect' href='" . esc_attr( $href ) . "'>\n";
		}
	}

	/**
	 * Get the sanitized list of domains from settings.
	 *
	 * @since 0.1.1
	 *
	 * @return array Array of domain strings.
	 */
	public function get_domains(): array {
		$raw = $this->settings->get( 'heartbeat', 'dns_prefetch_domains', array() );

		if ( ! is_array( $raw ) ) {
			$raw = explode( "\n", (string) $raw );
		}

		$domains = array();
		foreach ( $raw as $line ) {
			$domain = $this->sanitize_domain( $line );
			if ( '' !== $domain ) {
				$domains[] = $domain;
			}
		}

		return array_unique( $domains );
	}

	/**
	 * Sanitize a single domain: strip protocol, path, port, whitespace.
	 *
	 * @since 0.1.1
	 *
	 * @param string $input Raw domain input.
	 * @return string Sanitized domain, or empty string if invalid.
	 */
	private function sanitize_domain( string $input ): string {
		$input = trim( $input );

		if ( '' === $input ) {
			return '';
		}

		// Strip protocol.
		$input = preg_replace( '#^https?://#i', '', $input );

		// Strip path (keep only the host part).
		$parts = explode( '/', $input );
		$input = $parts[0];

		// Strip port.
		$parts = explode( ':', $input );
		$input = $parts[0];

		// Validate: must contain at least one dot and no spaces.
		if ( false === strpos( $input, '.' ) || preg_match( '/\s/', $input ) ) {
			return '';
		}

		return sanitize_text_field( $input );
	}
}