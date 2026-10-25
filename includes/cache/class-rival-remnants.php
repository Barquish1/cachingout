<?php
/**
 * Rival-cache remnant advisor - finds what another caching plugin left
 * behind after it was deactivated and deleted, and says so in plain words.
 *
 * Operator requirement (2026-09-01, from the orgahair.pl install): a removed
 * rival leaves things a non-expert will never find - empty marker pairs in
 * .htaccess ("# BEGIN LSCACHE" / "# END LSCACHE"), plugin folders under
 * wp-content with their own .htaccess (wp-content/litespeed/), stale cache
 * directories. None of it breaks the site, all of it confuses the next
 * diagnosis, and a LIVE leftover block can still steer Apache.
 *
 * READ-ONLY BY DESIGN. This class never deletes, moves or edits anything.
 * It DETECTS and ADVISES: exact path, what it is, whether it is safe to
 * delete. Consumers: CachingOut_Filesystem_Check (row "rival_remnants") and
 * the Page Audit (advice "rival-cache-remnants").
 *
 * An artefact only counts as a remnant when the plugin that made it is no
 * longer installed (its folder under wp-content/plugins is gone). While the
 * plugin is still installed the artefact is that plugin's business, and the
 * drop-in ownership guard already handles the active-rival case.
 *
 * @package CachingOut
 * @since 0.1.17
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only scanner for leftovers of removed cache plugins.
 *
 * @since 0.1.17
 */
class CachingOut_Rival_Remnants {

	/**
	 * What each rival leaves behind. Paths are relative to wp-content;
	 * markers are the X in "# BEGIN X" / "# END X".
	 *
	 * Sources: each plugin's own uninstall docs and the artefacts seen on the
	 * two alpha sites. Extend here when a new leftover is met in the wild.
	 *
	 * @since 0.1.17
	 * @var array<string, array{label: string, dirs: string[], files: string[], markers: string[]}>
	 */
	const CATALOGUE = array(
		'litespeed-cache'   => array(
			'label'   => 'LiteSpeed Cache',
			'dirs'    => array( 'litespeed' ),
			'files'   => array(),
			'markers' => array( 'LSCACHE', 'NON_LSCACHE' ),
		),
		'w3-total-cache'    => array(
			'label'   => 'W3 Total Cache',
			'dirs'    => array( 'w3tc-config', 'cache/page_enhanced', 'cache/minify', 'cache/db', 'cache/object', 'cache/tmp' ),
			'files'   => array(),
			'markers' => array( 'W3TC' ),
		),
		'wp-rocket'         => array(
			'label'   => 'WP Rocket',
			'dirs'    => array( 'wp-rocket-config', 'cache/wp-rocket', 'cache/busting', 'cache/critical-css', 'cache/used-css' ),
			'files'   => array(),
			'markers' => array( 'WP Rocket' ),
		),
		'wp-super-cache'    => array(
			'label'   => 'WP Super Cache',
			'dirs'    => array( 'cache/supercache', 'cache/blogs' ),
			'files'   => array( 'wp-cache-config.php' ),
			'markers' => array( 'WPSuperCache' ),
		),
		'wp-fastest-cache'  => array(
			'label'   => 'WP Fastest Cache',
			'dirs'    => array( 'cache/all', 'cache/wpfc-mobile-cache', 'cache/wpfc-minified', 'cache/wpfc-widget-cache' ),
			'files'   => array(),
			'markers' => array( 'WP Fastest Cache' ),
		),
		'breeze'            => array(
			'label'   => 'Breeze',
			'dirs'    => array( 'cache/breeze-minification', 'breeze-config' ),
			'files'   => array(),
			'markers' => array( 'Breeze' ),
		),
		'hummingbird-performance' => array(
			'label'   => 'Hummingbird',
			'dirs'    => array( 'wphb-cache', 'wphb-logs' ),
			'files'   => array(),
			'markers' => array(),
		),
		'cache-enabler'     => array(
			'label'   => 'Cache Enabler',
			'dirs'    => array( 'cache/cache-enabler', 'settings/cache-enabler' ),
			'files'   => array(),
			'markers' => array(),
		),
		'swift-performance-lite' => array(
			'label'   => 'Swift Performance',
			'dirs'    => array( 'cache/swift-performance' ),
			'files'   => array(),
			'markers' => array(),
		),
		'comet-cache'       => array(
			'label'   => 'Comet Cache',
			'dirs'    => array( 'cache/comet-cache' ),
			'files'   => array(),
			'markers' => array(),
		),
		'autoptimize'       => array(
			'label'   => 'Autoptimize',
			'dirs'    => array( 'cache/autoptimize' ),
			'files'   => array(),
			'markers' => array(),
		),
		'wp-optimize'       => array(
			'label'   => 'WP-Optimize',
			'dirs'    => array( 'cache/wpo-cache', 'wpo-cache' ),
			'files'   => array(),
			'markers' => array(),
		),
	);

	/**
	 * Absolute wp-content directory.
	 *
	 * @since 0.1.17
	 * @var string
	 */
	private $content_dir;

	/**
	 * Absolute path of the root .htaccess.
	 *
	 * @since 0.1.17
	 * @var string
	 */
	private $htaccess_path;

	/**
	 * Absolute plugins directory (installed-plugin test).
	 *
	 * @since 0.1.17
	 * @var string
	 */
	private $plugin_dir;

	/**
	 * Constructor. Every path is injectable so the scanner can be tested
	 * against a temporary tree.
	 *
	 * @since 0.1.17
	 *
	 * @param string|null $content_dir   wp-content directory. Default WP_CONTENT_DIR.
	 * @param string|null $htaccess_path Root .htaccess. Default ABSPATH/.htaccess.
	 * @param string|null $plugin_dir    Plugins directory. Default WP_PLUGIN_DIR.
	 */
	public function __construct( ?string $content_dir = null, ?string $htaccess_path = null, ?string $plugin_dir = null ) {
		// These are OTHER plugins' locations, so the WordPress constants for
		// the content and plugins directories are the right source (our own
		// path is CACHINGOUT_PATH, from plugin_dir_path( __FILE__ )). The
		// root .htaccess comes from the activator's one resolver.
		$this->content_dir   = rtrim( $content_dir ?? WP_CONTENT_DIR, '/\\' );
		$this->htaccess_path = $htaccess_path ?? ( class_exists( 'CachingOut_Activator' ) ? CachingOut_Activator::htaccess_path() : ABSPATH . '.htaccess' );
		$this->plugin_dir    = rtrim( $plugin_dir ?? WP_PLUGIN_DIR, '/\\' );
	}

	/**
	 * Scan for remnants of plugins that are no longer installed.
	 *
	 * Each row: {
	 *   owner:  string  Plugin slug from the catalogue.
	 *   label:  string  Plugin display name.
	 *   kind:   string  'dir' | 'file' | 'htaccess_block'.
	 *   path:   string  Absolute path, or ".htaccess" for a block.
	 *   marker: string  Block marker name ('' unless kind is htaccess_block).
	 *   live:   bool    True when an .htaccess block still carries directives.
	 *   note:   string  Plain-language description + whether it is safe to delete.
	 * }
	 *
	 * @since 0.1.17
	 * @return array[] Findings in catalogue order; empty when the site is clean.
	 */
	public function scan(): array {
		$rows     = array();
		$htaccess = $this->read_htaccess();

		foreach ( self::CATALOGUE as $slug => $spec ) {
			if ( $this->plugin_installed( $slug ) ) {
				continue;
			}

			foreach ( $spec['dirs'] as $rel ) {
				$abs = $this->content_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
				if ( is_dir( $abs ) ) {
					$rows[] = $this->row( $slug, $spec['label'], 'dir', $abs, '', false, sprintf(
						/* translators: 1: plugin name, 2: directory path */
						__( 'Folder left by %1$s at %2$s. It is no longer used by anything. Safe to delete with your file manager or FTP client.', 'cachingout' ),
						$spec['label'],
						$abs
					) );
				}
			}

			foreach ( $spec['files'] as $rel ) {
				$abs = $this->content_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
				if ( is_file( $abs ) ) {
					$rows[] = $this->row( $slug, $spec['label'], 'file', $abs, '', false, sprintf(
						/* translators: 1: plugin name, 2: file path */
						__( 'Configuration file left by %1$s at %2$s. Safe to delete.', 'cachingout' ),
						$spec['label'],
						$abs
					) );
				}
			}

			if ( '' === $htaccess ) {
				continue;
			}
			foreach ( $spec['markers'] as $marker ) {
				$block = $this->find_block( $htaccess, $marker );
				if ( null === $block ) {
					continue;
				}
				if ( $block['live'] ) {
					$note = sprintf(
						/* translators: 1: marker name, 2: plugin name */
						__( 'The "# BEGIN %1$s" block in .htaccess still contains rules from %2$s, which is no longer installed. Apache keeps applying them, and Caching Out will not write its own browser-caching rules while they are present. Remove the block (from "# BEGIN %1$s" to "# END %1$s") after taking a backup of .htaccess.', 'cachingout' ),
						$marker,
						$spec['label']
					);
				} else {
					$note = sprintf(
						/* translators: 1: marker name, 2: plugin name */
						__( 'An empty "# BEGIN %1$s" / "# END %1$s" pair in .htaccess, left by %2$s. It does nothing; deleting those two lines is safe and tidies the file.', 'cachingout' ),
						$marker,
						$spec['label']
					);
				}
				$rows[] = $this->row( $slug, $spec['label'], 'htaccess_block', $this->htaccess_path, $marker, $block['live'], $note );
			}
		}

		return $rows;
	}

	/**
	 * One line per finding, for the filesystem-check detail and the chat.
	 *
	 * @since 0.1.17
	 *
	 * @param array[] $rows Output of scan().
	 * @return string[]
	 */
	public function summary_lines( array $rows ): array {
		$lines = array();
		foreach ( $rows as $row ) {
			if ( 'htaccess_block' === $row['kind'] ) {
				$lines[] = sprintf( '%s: .htaccess block "# BEGIN %s" (%s)', $row['label'], $row['marker'], $row['live'] ? 'still has rules' : 'empty' );
			} else {
				$lines[] = sprintf( '%s: %s %s', $row['label'], 'dir' === $row['kind'] ? 'folder' : 'file', $row['path'] );
			}
		}
		return $lines;
	}

	/**
	 * Whether the rival plugin still has a folder under wp-content/plugins.
	 *
	 * @since 0.1.17
	 *
	 * @param string $slug Plugin slug.
	 * @return bool
	 */
	private function plugin_installed( string $slug ): bool {
		if ( is_dir( $this->plugin_dir . DIRECTORY_SEPARATOR . $slug ) ) {
			return true;
		}
		// A must-use copy counts as installed.
		return defined( 'WPMU_PLUGIN_DIR' ) && is_dir( rtrim( WPMU_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . $slug );
	}

	/**
	 * .htaccess content, or '' when absent or unreadable.
	 *
	 * @since 0.1.17
	 * @return string
	 */
	private function read_htaccess(): string {
		if ( ! is_file( $this->htaccess_path ) || ! is_readable( $this->htaccess_path ) ) {
			return '';
		}
		$content = file_get_contents( $this->htaccess_path );
		return false === $content ? '' : $content;
	}

	/**
	 * Locate a "# BEGIN X" ... "# END X" block.
	 *
	 * @since 0.1.17
	 *
	 * @param string $htaccess File content.
	 * @param string $marker   Marker name X.
	 * @return array{live: bool}|null Null when the marker is absent.
	 */
	private function find_block( string $htaccess, string $marker ): ?array {
		$begin = '# BEGIN ' . $marker;
		$end   = '# END ' . $marker;

		// Anchor to end-of-line so "LSCACHE" never matches inside "NON_LSCACHE"
		// and "WP Rocket" never matches a longer marker with the same prefix.
		if ( ! preg_match( '/^[ \t]*' . preg_quote( $begin, '/' ) . '[ \t]*$/m', $htaccess, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = $m[0][1] + strlen( $m[0][0] );

		if ( ! preg_match( '/^[ \t]*' . preg_quote( $end, '/' ) . '[ \t]*$/m', $htaccess, $e, PREG_OFFSET_CAPTURE, $start ) ) {
			// Unterminated: whatever follows is effectively live.
			return array( 'live' => true );
		}
		$body = substr( $htaccess, $start, $e[0][1] - $start );

		return array( 'live' => '' !== trim( $body ) );
	}

	/**
	 * Build one finding row.
	 *
	 * @since 0.1.17
	 *
	 * @param string $owner  Plugin slug.
	 * @param string $label  Plugin name.
	 * @param string $kind   'dir' | 'file' | 'htaccess_block'.
	 * @param string $path   Absolute path.
	 * @param string $marker Marker name or ''.
	 * @param bool   $live   Block still carries directives.
	 * @param string $note   Plain-language advice.
	 * @return array
	 */
	private function row( string $owner, string $label, string $kind, string $path, string $marker, bool $live, string $note ): array {
		return array(
			'owner'  => $owner,
			'label'  => $label,
			'kind'   => $kind,
			'path'   => $path,
			'marker' => $marker,
			'live'   => $live,
			'note'   => $note,
		);
	}
}
