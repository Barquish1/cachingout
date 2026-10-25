<?php
/**
 * The cache status strip: four figures above the Cache tab's form.
 *
 * Read in the admin request only, from data the plugin already holds
 * (spec 35): the page-cache switch, the number of cached pages on disk,
 * the drop-in's ownership and the lifespan. No option is written, no hook
 * is fired, nothing runs for a visitor. The file count is cached in a
 * transient for a minute so a large cache never slows the settings page.
 *
 * @package CachingOut
 * @since 1.22.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status strip data and markup.
 *
 * @since 1.22.0
 */
class CachingOut_Cache_Status {

	/**
	 * Transient that holds the cached-page count for a minute.
	 *
	 * @var string
	 */
	const COUNT_TRANSIENT = 'cachingout_status_page_count';

	/**
	 * The four figures.
	 *
	 * Each entry: key, label, value, unit (may be ''), hint, tone (one of
	 * 'good', 'warn', 'bad', '' for neutral).
	 *
	 * @since 1.22.0
	 *
	 * @param array  $settings  Full settings array (CachingOut_Settings::get_all()).
	 * @param string $cache_dir Optional cache directory; defaults to the page cache's.
	 * @return array<int, array{key: string, label: string, value: string, unit: string, hint: string, tone: string}>
	 */
	public static function snapshot( array $settings, string $cache_dir = '' ): array {
		$cache    = isset( $settings['cache'] ) && is_array( $settings['cache'] ) ? $settings['cache'] : array();
		$enabled  = ! empty( $cache['enabled'] );
		$lifespan = isset( $cache['lifespan'] ) ? (int) $cache['lifespan'] : 0;
		$killed   = defined( 'CACHINGOUT_DISABLE_CACHE' ) && CACHINGOUT_DISABLE_CACHE;

		if ( $killed ) {
			$switch = array(
				'value' => __( 'Off', 'cachingout' ),
				'hint'  => __( 'Disabled by CACHINGOUT_DISABLE_CACHE in wp-config.php.', 'cachingout' ),
				'tone'  => 'warn',
			);
		} elseif ( $enabled ) {
			$switch = array(
				'value' => __( 'On', 'cachingout' ),
				'hint'  => __( 'Anonymous visitors get static files.', 'cachingout' ),
				'tone'  => 'good',
			);
		} else {
			$switch = array(
				'value' => __( 'Off', 'cachingout' ),
				'hint'  => __( 'Every request runs WordPress.', 'cachingout' ),
				'tone'  => 'bad',
			);
		}

		if ( '' === $cache_dir && class_exists( 'CachingOut_Page_Cache' ) ) {
			$cache_dir = CachingOut_Page_Cache::cache_dir();
		}
		$count = self::cached_page_count( $cache_dir );

		$dropin_path = class_exists( 'CachingOut_Dropin_Owner' ) ? CachingOut_Dropin_Owner::dropin_path() : '';
		if ( '' === $dropin_path || ! file_exists( $dropin_path ) ) {
			$dropin = array(
				'value' => __( 'Absent', 'cachingout' ),
				'hint'  => __( 'advanced-cache.php is not installed; cached hits are not served early.', 'cachingout' ),
				'tone'  => $enabled && ! $killed ? 'warn' : '',
			);
		} elseif ( CachingOut_Dropin_Owner::is_ours() ) {
			$dropin = array(
				'value' => __( 'Ours', 'cachingout' ),
				'hint'  => __( 'advanced-cache.php is the one this plugin wrote.', 'cachingout' ),
				'tone'  => 'good',
			);
		} else {
			$dropin = array(
				'value' => __( 'Foreign', 'cachingout' ),
				'hint'  => __( 'Another plugin owns advanced-cache.php; see the Filesystem Check.', 'cachingout' ),
				'tone'  => 'bad',
			);
		}

		if ( $lifespan > 0 ) {
			$life = array(
				'value' => (string) number_format_i18n( $lifespan ),
				'unit'  => _n( 'hour', 'hours', $lifespan, 'cachingout' ),
				'hint'  => __( 'A cached page is rebuilt after this long.', 'cachingout' ),
			);
		} else {
			$life = array(
				'value' => __( 'None', 'cachingout' ),
				'unit'  => '',
				'hint'  => __( 'Pages stay cached until purged.', 'cachingout' ),
			);
		}

		return array(
			array(
				'key'   => 'cache',
				'label' => __( 'Page cache', 'cachingout' ),
				'value' => $switch['value'],
				'unit'  => '',
				'hint'  => $switch['hint'],
				'tone'  => $switch['tone'],
			),
			array(
				'key'   => 'pages',
				'label' => __( 'Cached pages', 'cachingout' ),
				'value' => (string) number_format_i18n( $count ),
				'unit'  => '',
				'hint'  => __( 'Files under wp-content/cache/cachingout.', 'cachingout' ),
				'tone'  => '',
			),
			array(
				'key'   => 'dropin',
				'label' => __( 'Drop-in', 'cachingout' ),
				'value' => $dropin['value'],
				'unit'  => '',
				'hint'  => $dropin['hint'],
				'tone'  => $dropin['tone'],
			),
			array(
				'key'   => 'lifespan',
				'label' => __( 'Lifespan', 'cachingout' ),
				'value' => $life['value'],
				'unit'  => $life['unit'],
				'hint'  => $life['hint'],
				'tone'  => '',
			),
		);
	}

	/**
	 * Number of cached page files under the cache directory, from a
	 * one-minute transient when one is set.
	 *
	 * @since 1.22.0
	 *
	 * @param string $cache_dir Absolute directory path.
	 * @return int
	 */
	public static function cached_page_count( string $cache_dir ): int {
		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['dir'], $cached['count'] ) && $cached['dir'] === $cache_dir ) {
			return (int) $cached['count'];
		}

		$count = self::count_files( $cache_dir );
		set_transient( self::COUNT_TRANSIENT, array( 'dir' => $cache_dir, 'count' => $count ), MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Count the cached page files under a directory, walking it once.
	 *
	 * A page file is any regular file whose name contains `.html` (the
	 * page cache writes index.html, index-mobile.html and their gzipped
	 * twins); index.php guards and metadata files are not counted. The
	 * walk stops at 100,000 files: past that the figure is "many", and
	 * the walk's cost would be felt on the settings page.
	 *
	 * @since 1.22.0
	 *
	 * @param string $cache_dir Absolute directory path.
	 * @return int
	 */
	public static function count_files( string $cache_dir ): int {
		if ( '' === $cache_dir || ! is_dir( $cache_dir ) ) {
			return 0;
		}

		$count = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $cache_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && false !== strpos( $file->getFilename(), '.html' ) ) {
					++$count;
					if ( $count >= 100000 ) {
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			return $count;
		}

		return $count;
	}

	/**
	 * Forget the cached count (called after a purge so the strip is fresh).
	 *
	 * @since 1.22.0
	 * @return void
	 */
	public static function forget_count(): void {
		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Print the strip.
	 *
	 * @since 1.22.0
	 *
	 * @param array $settings Full settings array.
	 * @return void
	 */
	public static function render( array $settings ): void {
		$stats = self::snapshot( $settings );
		?>
		<div class="rs-stats" role="group" aria-label="<?php esc_attr_e( 'Cache status', 'cachingout' ); ?>">
			<?php foreach ( $stats as $stat ) : ?>
				<?php $tone = '' !== $stat['tone'] ? ' rs-stat--' . $stat['tone'] : ''; ?>
				<div class="rs-stat<?php echo esc_attr( $tone ); ?>" data-stat="<?php echo esc_attr( $stat['key'] ); ?>">
					<span class="rs-stat__label"><?php echo esc_html( $stat['label'] ); ?></span>
					<span class="rs-stat__value">
						<?php echo esc_html( $stat['value'] ); ?>
						<?php if ( '' !== $stat['unit'] ) : ?>
							<span class="rs-stat__unit"><?php echo esc_html( $stat['unit'] ); ?></span>
						<?php endif; ?>
					</span>
					<span class="rs-stat__hint"><?php echo esc_html( $stat['hint'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

// A purge empties the cache directory; the strip must not show the old count for a minute.
add_action( 'cachingout_after_purge_all', array( 'CachingOut_Cache_Status', 'forget_count' ) );
