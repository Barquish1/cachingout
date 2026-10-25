<?php
/**
 * Browser caching and compression — far-future Expires headers and
 * GZIP/Brotli compression rules for Apache .htaccess, plus documented
 * NGINX snippets for manual inclusion.
 *
 * Writes/removes three marker-delimited blocks in .htaccess via the
 * shared CachingOut_Activator::update_htaccess_block() helper.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The write() method delegates filesystem work to CachingOut_Activator.
if ( ! class_exists( 'CachingOut_Activator' ) ) {
	require_once CACHINGOUT_PATH . 'includes/class-activator.php';
}

/**
 * Browser caching config generator.
 *
 * Stateless — rule blocks are generated on demand. Write/remove
 * delegates to CachingOut_Activator for filesystem work.
 *
 * @since 0.1.0
 */
class CachingOut_Browser_Cache {

	const MARKER_EXPIRES = 'WP Caching Out Expires';
	const MARKER_GZIP    = 'WP Caching Out GZIP';
	const MARKER_BROTLI  = 'WP Caching Out Brotli';

	/**
	 * Generate the Expires headers .htaccess block.
	 *
	 * Far-future ExpiresByType for images/CSS/JS/fonts (1 year),
	 * video/audio (1 month). Wrapped in mod_expires guard.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function get_expires_rules(): string {
		$rules  = '# BEGIN WP Caching Out Expires' . "\n";
		$rules .= '<IfModule mod_expires.c>' . "\n";
		$rules .= "ExpiresActive On\n";
		$rules .= "ExpiresByType image/jpeg \"access plus 1 year\"\n";
		$rules .= "ExpiresByType image/gif \"access plus 1 year\"\n";
		$rules .= "ExpiresByType image/png \"access plus 1 year\"\n";
		$rules .= "ExpiresByType image/webp \"access plus 1 year\"\n";
		$rules .= "ExpiresByType image/svg+xml \"access plus 1 year\"\n";
		$rules .= "ExpiresByType image/x-icon \"access plus 1 year\"\n";
		$rules .= "ExpiresByType image/avif \"access plus 1 year\"\n";
		$rules .= "ExpiresByType video/mp4 \"access plus 1 month\"\n";
		$rules .= "ExpiresByType video/webm \"access plus 1 month\"\n";
		$rules .= "ExpiresByType audio/mpeg \"access plus 1 month\"\n";
		$rules .= "ExpiresByType audio/ogg \"access plus 1 month\"\n";
		$rules .= "ExpiresByType text/css \"access plus 1 year\"\n";
		$rules .= "ExpiresByType text/javascript \"access plus 1 year\"\n";
		$rules .= "ExpiresByType application/javascript \"access plus 1 year\"\n";
		$rules .= "ExpiresByType font/woff2 \"access plus 1 year\"\n";
		$rules .= "ExpiresByType font/woff \"access plus 1 year\"\n";
		$rules .= "ExpiresByType application/font-woff \"access plus 1 year\"\n";
		$rules .= "ExpiresByType application/font-woff2 \"access plus 1 year\"\n";
		$rules .= "ExpiresByType application/vnd.ms-fontobject \"access plus 1 year\"\n";
		$rules .= "ExpiresByType application/x-font-ttf \"access plus 1 year\"\n";
		$rules .= '</IfModule>' . "\n";
		$rules .= '# END WP Caching Out Expires';
		return $rules;
	}

	/**
	 * Generate the GZIP compression .htaccess block.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function get_gzip_rules(): string {
		$rules  = '# BEGIN WP Caching Out GZIP' . "\n";
		$rules .= '<IfModule mod_deflate.c>' . "\n";
		$rules .= "AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/json application/xml text/xml application/rss+xml image/svg+xml application/x-font-ttf application/x-font-opentype application/vnd.ms-fontobject\n";
		$rules .= '<IfModule mod_setenvif.c>' . "\n";
		$rules .= "BrowserMatch ^Mozilla/4 gzip-only-text/html\n";
		$rules .= "BrowserMatch ^Mozilla/4\\.0[678] no-gzip\n";
		$rules .= "BrowserMatch \\bMSIE !no-gzip !gzip-only-text/html\n";
		$rules .= '</IfModule>' . "\n";
		$rules .= '</IfModule>' . "\n";
		$rules .= '# END WP Caching Out GZIP';
		return $rules;
	}

	/**
	 * Generate the Brotli compression .htaccess block.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function get_brotli_rules(): string {
		$rules  = '# BEGIN WP Caching Out Brotli' . "\n";
		$rules .= '<IfModule mod_brotli.c>' . "\n";
		$rules .= "AddOutputFilterByType BROTLI_COMPRESS text/html text/css text/javascript application/javascript application/json application/xml text/xml application/rss+xml image/svg+xml application/x-font-ttf application/x-font-opentype application/vnd.ms-fontobject\n";
		$rules .= '</IfModule>' . "\n";
		$rules .= '# END WP Caching Out Brotli';
		return $rules;
	}

	/**
	 * Generate a documented NGINX snippet.
	 *
	 * Never written to server config programmatically — displayed in
	 * the admin UI as a read-only block for manual inclusion.
	 *
	 * @since 0.1.0
	 * @return string
	 */
	public static function get_nginx_snippet(): string {
		$rules  = "# ---- WP Caching Out: Browser Caching & Compression ----\n";
		$rules .= "# Add this to your server block (or a location block).\n";
		$rules .= "#\n";
		$rules .= "# 1. Far-future Expires / Cache-Control for static assets.\n";
		$rules .= "location ~* \\.(css|js|png|jpg|jpeg|gif|webp|svg|woff|woff2|ttf|eot|ico|xml|mp4|webm|ogg)\$ {\n";
		$rules .= "    expires 1y;\n";
		$rules .= "    add_header Cache-Control \"public, immutable\";\n";
		$rules .= "}\n";
		$rules .= "\n";
		$rules .= "# 2. GZIP compression for text-based assets.\n";
		$rules .= "gzip on;\n";
		$rules .= "gzip_types text/html text/css text/javascript application/javascript application/json application/xml text/xml application/rss+xml image/svg+xml application/x-font-ttf application/x-font-opentype application/vnd.ms-fontobject;\n";
		$rules .= "\n";
		$rules .= "# 3. Brotli compression (requires brotli module).\n";
		$rules .= "# brotli on;\n";
		$rules .= "# brotli_types text/html text/css text/javascript application/javascript application/json application/xml text/xml application/rss+xml image/svg+xml;\n";
		$rules .= "# ---- End WP Caching Out ----";
		return $rules;
	}

	/**
	 * Write all enabled browser-cache blocks to .htaccess.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings $settings Settings instance.
	 * @return true|\WP_Error
	 */
	public function write( CachingOut_Settings $settings ) {
		$expires = $settings->get( 'browser_cache', 'expires', true );
		$gzip    = $settings->get( 'browser_cache', 'gzip', true );
		$brotli  = $settings->get( 'browser_cache', 'brotli', true );

		$result = CachingOut_Activator::update_htaccess_block(
			self::MARKER_EXPIRES,
			$expires ? self::get_expires_rules() : null
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = CachingOut_Activator::update_htaccess_block(
			self::MARKER_GZIP,
			$gzip ? self::get_gzip_rules() : null
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return CachingOut_Activator::update_htaccess_block(
			self::MARKER_BROTLI,
			$brotli ? self::get_brotli_rules() : null
		);
	}

	/**
	 * Remove all Caching Out browser-cache blocks from .htaccess.
	 *
	 * Used during deactivation. Removes all three blocks regardless of
	 * current settings.
	 *
	 * @since 0.1.0
	 * @return true|\WP_Error
	 */
	public function remove() {
		$errors = array();

		foreach ( array( self::MARKER_EXPIRES, self::MARKER_GZIP, self::MARKER_BROTLI ) as $marker ) {
			$result = CachingOut_Activator::update_htaccess_block( $marker, null );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
			}
		}

		if ( ! empty( $errors ) ) {
			$combined = new \WP_Error( 'cachingout_browser_cache_remove_partial' );
			foreach ( $errors as $error ) {
				$combined->add( $error->get_error_code(), $error->get_error_message() );
			}
			return $combined;
		}

		return true;
	}
}