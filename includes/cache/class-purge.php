<?php
/**
 * Purge event hooks — registers WordPress actions that trigger selective or
 * full cache purges on content changes.
 *
 * Every purge handler converts affected URLs to cache keys and delegates to
 * `CachingOut_Page_Cache::delete()` for single-URL purges or
 * `::purge_all()` for full-cache clears.
 *
 * @package CachingOut
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache purge event subscriber.
 *
 * @since 0.1.0
 */
class CachingOut_Purge {

	/**
	 * Settings manager.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Settings
	 */
	private $settings;

	/**
	 * Page cache I/O layer.
	 *
	 * @since 0.1.0
	 * @var CachingOut_Page_Cache
	 */
	private $page_cache;

	/**
	 * Whether a media-change purge is queued for this request.
	 *
	 * @since 1.21.7
	 * @var bool
	 */
	private $media_purge_pending = false;

	/**
	 * The shared runtime snapshot as last read by network_snapshot(), and
	 * the stat stamp it was read at (multisite only).
	 *
	 * @since 1.24.0
	 * @var array{stamp: string, config: array}|null
	 */
	private $snapshot_memo = null;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param CachingOut_Settings   $settings   Settings manager instance.
	 * @param CachingOut_Page_Cache $page_cache Page cache I/O instance.
	 */
	public function __construct(
		CachingOut_Settings $settings,
		CachingOut_Page_Cache $page_cache
	) {
		$this->settings   = $settings;
		$this->page_cache = $page_cache;
	}

	/**
	 * Register all purge action hooks.
	 *
	 * Called once on `init` by the Plugin bootstrapper. Every content-change
	 * event that can alter cached output is wired here.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// Post changes — purge the post and its archives.
		add_action( 'save_post', array( $this, 'purge_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'purge_post' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'purge_post' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'purge_post' ), 10, 1 );

		// Term changes — any listing page may be affected.
		add_action( 'created_term', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'edited_term', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'delete_term', array( $this, 'purge_all' ), 10, 0 );

		// Menu changes — nav menus appear on every page.
		add_action( 'wp_update_nav_menu', array( $this, 'purge_all' ), 10, 0 );

		// Theme switch — markup changes affect everything.
		add_action( 'switch_theme', array( $this, 'purge_all' ), 10, 0 );

		// Widget and customizer saves.
		add_action( 'update_option_sidebars_widgets', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'customize_save_after', array( $this, 'purge_all' ), 10, 0 );

		// Plugin activation/deactivation — any plugin can change output.
		add_action( 'activated_plugin', array( $this, 'purge_all' ), 10, 0 );
		add_action( 'deactivated_plugin', array( $this, 'purge_all' ), 10, 0 );

		// Comment changes — purge the commented post.
		add_action( 'wp_insert_comment', array( $this, 'purge_comment_post' ), 10, 1 );
		add_action( 'wp_set_comment_status', array( $this, 'purge_comment_post' ), 10, 1 );
		add_action( 'trashed_comment', array( $this, 'purge_comment_post' ), 10, 1 );
		add_action( 'untrashed_comment', array( $this, 'purge_comment_post' ), 10, 1 );
		add_action( 'deleted_comment', array( $this, 'purge_comment_post' ), 10, 1 );

		// WooCommerce product stock/price changes — purge the product page.
		if ( class_exists( 'WooCommerce' ) ) {
			$this->register_woocommerce_hooks();
		}

		// Media library changes. An image-optimisation plugin (Imagify,
		// ShortPixel, Smush, EWWW) re-encodes or resizes a file through
		// wp_update_attachment_metadata and never fires save_post, so a
		// cached page would keep serving the width/height CachingOut_LazyLoad
		// stamped from the previous file, and any page embedding the image
		// would keep the old URL (1.21.7).
		add_action( 'add_attachment', array( $this, 'purge_media' ), 10, 0 );
		add_action( 'edit_attachment', array( $this, 'purge_media' ), 10, 0 );
		add_action( 'delete_attachment', array( $this, 'purge_media' ), 10, 0 );

		// NOTE: wp_update_attachment_metadata is a FILTER, not an action. A
		// callback registered here must return the metadata it was given -
		// add_action() would discard it and blank every attachment's meta.
		add_filter( 'wp_update_attachment_metadata', array( $this, 'purge_media_metadata' ), 10, 2 );

		// Manual purge admin action.
		add_action( 'admin_post_cachingout_purge_all', array( $this, 'handle_manual_purge' ) );
	}

	/**
	 * Register the WooCommerce product purge hooks.
	 *
	 * Separate from register_hooks() so the hook wiring can be exercised
	 * without WooCommerce loaded: until 1.21.9 these three lines sat behind
	 * `class_exists( 'WooCommerce' )` and no test ever fired them.
	 *
	 * The two `*_set_stock` actions pass the product OBJECT; only
	 * `woocommerce_update_product` passes the ID first.
	 *
	 * @since 1.21.9
	 *
	 * @return void
	 */
	public function register_woocommerce_hooks(): void {
		add_action( 'woocommerce_product_set_stock', array( $this, 'purge_product' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'purge_product' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'purge_post' ), 10, 1 );
	}

	/**
	 * Purge a product's page when WooCommerce reports a stock change.
	 *
	 * `woocommerce_product_set_stock` and `woocommerce_variation_set_stock`
	 * fire with a WC_Product, not an ID. Up to 1.21.8 the int-typed
	 * purge_post() was hooked on them directly, so every product save that
	 * touched a stock prop - and every order that reduced stock - died with
	 * a TypeError inside WooCommerce's data store, leaving a half-written
	 * product (site team report, 2026-09-17).
	 *
	 * A variation has no page of its own: its parent product's page is the
	 * one that shows its stock, so that is what gets purged.
	 *
	 * Duck-typed on purpose: this class must load, and this method must be
	 * testable, without WooCommerce present. Anything that is neither a
	 * product-like object nor a positive ID is ignored - a purge hook must
	 * never be the reason somebody else's save fails.
	 *
	 * @since 1.21.9
	 *
	 * @param mixed $product WC_Product, or a product ID from a third-party caller.
	 * @return void
	 */
	public function purge_product( $product ): void {
		$post_id = 0;

		if ( is_object( $product ) && is_callable( array( $product, 'get_id' ) ) ) {
			$post_id = (int) $product->get_id();

			$is_variation = is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' );
			if ( $is_variation && is_callable( array( $product, 'get_parent_id' ) ) ) {
				$parent_id = (int) $product->get_parent_id();
				if ( $parent_id > 0 ) {
					$post_id = $parent_id;
				}
			}
		} elseif ( is_numeric( $product ) ) {
			$post_id = (int) $product;
		}

		if ( $post_id <= 0 ) {
			return;
		}

		$this->purge_post( $post_id );
	}

	/**
	 * Queue a full purge after a media library change.
	 *
	 * @since 1.21.7
	 *
	 * @return void
	 */
	public function purge_media(): void {
		$this->schedule_media_purge();
	}

	/**
	 * Queue a full purge when attachment metadata is written.
	 *
	 * Registered on the `wp_update_attachment_metadata` filter, so the
	 * metadata is returned exactly as received.
	 *
	 * @since 1.21.7
	 *
	 * @param mixed $data          Attachment metadata.
	 * @param int   $attachment_id Attachment ID (unused).
	 * @return mixed The metadata, untouched.
	 */
	public function purge_media_metadata( $data, $attachment_id = 0 ) {
		unset( $attachment_id );

		$this->schedule_media_purge();

		return $data;
	}

	/**
	 * Collapse every media change in one request into a single purge.
	 *
	 * A bulk optimisation run rewrites metadata for each image in the batch;
	 * purging per image would clear an already-empty cache dozens of times
	 * per request. The purge is deferred to `shutdown` and runs once.
	 *
	 * @since 1.21.7
	 *
	 * @return void
	 */
	public function schedule_media_purge(): void {
		if ( $this->media_purge_pending ) {
			return;
		}

		$this->media_purge_pending = true;

		add_action( 'shutdown', array( $this, 'run_media_purge' ), 100 );
	}

	/**
	 * Run the purge queued by schedule_media_purge().
	 *
	 * @since 1.21.7
	 *
	 * @return void
	 */
	public function run_media_purge(): void {
		if ( ! $this->media_purge_pending ) {
			return;
		}

		$this->media_purge_pending = false;

		$this->purge_all();
	}

	/**
	 * Purge a single URL from the cache.
	 *
	 * Parses the absolute URL, builds a `CachingOut_Cache_Key`, and deletes
	 * all variant cache files for it. The key is built without mobile/WebP
	 * variants so that `::delete()` sweeps all four variant files.
	 *
	 * When multilingual plugins (WPML/Polylang) are active, this method also
	 * sweeps all language-specific cache files for the URL so that saving
	 * a post in one language clears every language variant of that URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Absolute URL to purge.
	 * @return bool True if at least one cache entry was deleted (or did not exist).
	 */
	public function purge_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( false === $parts ) {
			return false;
		}

		$is_ssl  = isset( $parts['scheme'] ) && 'https' === $parts['scheme'];
		$host    = self::host_for_key( $parts, $is_ssl );
		$path    = isset( $parts['path'] ) ? $parts['path'] : '/';
		$queries = array();

		// If there is a query string, include it for key construction.
		// We include all query keys when purging — we want to delete all
		// cache files that could be related to this URL regardless of
		// the allow-list.
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			parse_str( $parts['query'], $queries );
			if ( ! is_array( $queries ) ) {
				$queries = array();
			}
		}

		if ( '' === $host ) {
			return false;
		}

		$key = new CachingOut_Cache_Key(
			$this->settings,
			$host,
			$is_ssl,
			$path,
			$queries,
			false,  // is_mobile — irrelevant for deletion (all variants swept)
			'',     // accept_header — irrelevant for deletion
			null,   // Deprecated language slot.
			$this->blog_id_for_url( $host, $is_ssl, $path, $parts )
		);

		$deleted = $this->page_cache->delete( $key );

		// Note: the pre-0.1.16 per-language sweep was deleted as dead code
		// (TODOS 19). Language was a dimension of the logical get_key()
		// string only - the FILE layout never stored it, so every language
		// key resolved to the same path and the loop re-deleted the same
		// file. Multilingual URL-mode sites vary by PATH, which this delete
		// already covers.

		/**
		 * Fires after a single URL has been purged from the local cache.
		 *
		 * Add-ons that support per-URL purging (Cloudflare, Varnish) should
		 * hook here to purge the same URL from their edge cache.
		 *
		 * @since 0.1.0
		 *
		 * @param string $url The absolute URL that was purged.
		 */
		do_action( 'cachingout_after_purge_url', $url );

		return $deleted;
	}


	/**
	 * The host segment a cache key needs for a parsed URL.
	 *
	 * The drop-in keys a page on the raw HTTP_HOST header, which carries the
	 * port whenever it is not the scheme's default (`127.0.0.1:8080`,
	 * wp-env's `localhost:8888`); CachingOut_Cache_Key turns the colon into
	 * `-`, so that page lives under `127.0.0.1-8080/`. Up to 1.23.0 this
	 * method's caller used the bare host, deleted nothing on any site with a
	 * port, and still reported success. A browser omits a default port from
	 * the Host header, so 80 (http) and 443 (https) are dropped here too:
	 * a portless URL produces exactly the host it always did.
	 *
	 * @since 1.24.0
	 *
	 * @param array $parts  Result of wp_parse_url().
	 * @param bool  $is_ssl Whether the URL's scheme is https.
	 * @return string Host, with `:port` appended for a non-default port; '' when the URL has no host.
	 */
	private static function host_for_key( array $parts, bool $is_ssl ): string {
		$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		if ( '' === $host || ! isset( $parts['port'] ) ) {
			return $host;
		}

		$port = (int) $parts['port'];
		if ( ( $is_ssl ? 443 : 80 ) === $port ) {
			return $host;
		}

		return $host . ':' . $port;
	}

	/**
	 * The blog whose cache directory holds a URL's page (multisite).
	 *
	 * The drop-in files a page under `site-<N>/` by matching HTTP_HOST +
	 * REQUEST_URI against the site list in the shared runtime snapshot
	 * (CachingOut_Cache_Key::detect_blog_id()). Up to 1.23.0 the purge never
	 * passed a blog id, so every subsite URL resolved to blog 1's directory
	 * and a post save (or any URL purge) on a subsite deleted nothing. The
	 * same snapshot and the same matcher are used here, so the purge looks
	 * exactly where the drop-in wrote, whichever site the purge runs on: a
	 * URL of another site (the image-compression `from` page, a main-site
	 * call) resolves to THAT site.
	 *
	 * Without a readable snapshot the drop-in serves and writes nothing, so
	 * any file left is resolved through WordPress: get_site_by_path() for the
	 * URL, else the current blog. Single site: always 1, nothing is read.
	 *
	 * @since 1.24.0
	 *
	 * @param string $host   Host as keyed (host_for_key(): with a non-default port).
	 * @param bool   $is_ssl Whether the URL's scheme is https.
	 * @param string $path   URL path.
	 * @param array  $parts  Result of wp_parse_url().
	 * @return int Blog id.
	 */
	private function blog_id_for_url( string $host, bool $is_ssl, string $path, array $parts ): int {
		if ( ! is_multisite() ) {
			return 1;
		}

		$snapshot = $this->network_snapshot();
		if ( null !== $snapshot ) {
			$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
			return CachingOut_Cache_Key::blog_id_for_url(
				( $is_ssl ? 'https' : 'http' ) . '://' . $host . $path . $query,
				$snapshot
			);
		}

		if ( function_exists( 'get_site_by_path' ) ) {
			$site = get_site_by_path( strtolower( $host ), '' === $path ? '/' : $path );
			if ( $site instanceof WP_Site ) {
				return (int) $site->blog_id;
			}
		}

		return (int) get_current_blog_id();
	}

	/**
	 * The shared runtime snapshot the drop-in detects the blog from.
	 *
	 * Read once per change of the file (inode, mtime and size), because a
	 * post save fans out to a dozen URL purges.
	 *
	 * @since 1.24.0
	 *
	 * @return array|null The snapshot, or null when absent or unreadable.
	 */
	private function network_snapshot(): ?array {
		$file = CachingOut_Settings::runtime_config_path();
		clearstatcache( true, $file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing snapshot is the normal single-site case; false is handled below.
		$stat = @stat( $file );
		if ( false === $stat ) {
			$this->snapshot_memo = null;
			return null;
		}

		$stamp = $stat['ino'] . ':' . $stat['mtime'] . ':' . $stat['size'];
		if ( null !== $this->snapshot_memo && $stamp === $this->snapshot_memo['stamp'] ) {
			return $this->snapshot_memo['config'];
		}

		$config = include $file;
		if ( ! is_array( $config ) ) {
			$this->snapshot_memo = null;
			return null;
		}

		$this->snapshot_memo = array(
			'stamp'  => $stamp,
			'config' => $config,
		);

		return $config;
	}

	/**
	 * Purge all cached pages for a post, including its archive pages.
	 *
	 * Deletes the post's own cache file(s) plus the cache files for every
	 * archive page the post appears on: category, tag, custom taxonomy,
	 * author, date archives, and the homepage (if it displays latest posts).
	 *
	 * Skips revisions, auto-drafts, and posts that are not published (or
	 * trashed/untrashed — the caller's context determines that).
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function purge_post( int $post_id ): void {
		static $running = array();

		// Prevent infinite loops from `save_post` firing recursively.
		if ( isset( $running[ $post_id ] ) ) {
			return;
		}
		$running[ $post_id ] = true;

		$post = get_post( $post_id );
		if ( ! $post ) {
			unset( $running[ $post_id ] );
			return;
		}

		// Skip revisions and auto-drafts.
		if ( 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
			unset( $running[ $post_id ] );
			return;
		}

		// Purge the post's own URL.
		$permalink = get_permalink( $post );
		if ( $permalink ) {
			$this->purge_url( $permalink );

			// Also purge the post without trailing slash (both variants).
			$untrail = untrailingslashit( $permalink );
			if ( $untrail !== $permalink ) {
				$this->purge_url( $untrail );
			}
		}

		// Purge archive pages this post belongs to.
		$this->purge_post_archives( $post );

		// Purge the homepage if it displays latest posts.
		if ( 'post' === $post->post_type ) {
			$home_url = home_url( '/' );
			$this->purge_url( $home_url );
		}

		/**
		 * Fires after a post and its related URLs were purged.
		 *
		 * The only new action spec 26 adds: the audit log folds the URL purges
		 * above into one row per post.
		 *
		 * @since 0.1.18
		 *
		 * @param int $post_id Post ID.
		 */
		do_action( 'cachingout_after_purge_post', $post_id );

		unset( $running[ $post_id ] );
	}

	/**
	 * Purge all cache files immediately.
	 *
	 * Hooked to events whose side effects can appear on any page:
	 * term changes, menu updates, theme switches, widget saves, and
	 * plugin activate/deactivate.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function purge_all(): void {
		$this->page_cache->purge_all();
		$this->clear_combined_files();

		/**
		 * Fires after a full cache purge.
		 *
		 * Subsystems that need to react to a purge (e.g. cache preloading)
		 * should hook here rather than trying to intercept every purge event.
		 *
		 * @since 0.1.0
		 */
		do_action( 'cachingout_after_purge_all' );
	}

	/**
	 * Purge the post that a comment belongs to.
	 *
	 * Callback for comment lifecycle hooks. Extracts the comment's post ID
	 * and delegates to `purge_post()`.
	 *
	 * @since 0.1.0
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function purge_comment_post( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment || empty( $comment->comment_post_ID ) ) {
			return;
		}

		$this->purge_post( (int) $comment->comment_post_ID );
	}

	/**
	 * Handle the manual "Purge All" admin action.
	 *
	 * Requires `manage_options` capability and a valid nonce
	 * (`cachingout_purge_all_nonce`). On success, purges everything and
	 * redirects back to the referring page with a success parameter.
	 * On failure, wp_die() with an error message.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle_manual_purge(): void {
		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'cachingout' ),
				esc_html__( 'Permission Denied', 'cachingout' ),
				array( 'response' => 403 )
			);
		}

		// Nonce verification.
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cachingout_purge_all' ) ) {
			wp_die(
				esc_html__( 'The link you followed has expired. Please try again.', 'cachingout' ),
				esc_html__( 'Invalid Request', 'cachingout' ),
				array( 'response' => 403 )
			);
		}

		// Route through the full purge so combined-file clearing, add-on
		// purges (Cloudflare/Varnish/Sucuri) and preload all fire.
		$this->purge_all();

		// Redirect back to the referring page.
		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}

		$redirect = add_query_arg( 'cachingout_purged', '1', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Age out orphaned combined and minified CSS/JS files.
	 *
	 * Generated files under combined/ and min/{css,js}/ are named by a
	 * content fingerprint (source path, mtime, size, plugin version), so a
	 * stale file is never wrong - it is simply no longer referenced. This
	 * removes files that no render has used for
	 * CachingOut_Minified_Files::RETENTION seconds, plus any abandoned
	 * `.tmp` write older than that.
	 *
	 * BUG-026 (orgahair.pl, 2026-09-01): this used to empty the directories
	 * on every purge. A visitor rendered between the page-cache purge and
	 * that sweep got a fresh page whose combined `<link>` pointed at a file
	 * the sweep then deleted; WordPress served its 404 page as the
	 * stylesheet until the next purge. Ageing out instead of sweeping means
	 * a file referenced by any live page is never removed.
	 *
	 * @since 0.1.0
	 * @since 0.1.17 Age-out instead of a full sweep.
	 *
	 * @return void
	 */
	private function clear_combined_files(): void {
		$base      = CachingOut_Settings::cache_dir() . '/';
		$retention = class_exists( 'CachingOut_Minified_Files' )
			? (int) CachingOut_Minified_Files::RETENTION
			: HOUR_IN_SECONDS;
		$cutoff    = time() - $retention;

		foreach ( array( 'combined/', 'min/css/', 'min/js/' ) as $sub ) {
			$dir = $base . $sub;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$files = glob( $dir . '*' );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				if ( ! is_file( $file ) || 'index.php' === basename( $file ) ) {
					continue;
				}
				$mtime = @filemtime( $file );
				if ( false !== $mtime && (int) $mtime < $cutoff ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Plugin-owned file under wp-content/cache/cachingout. Direct call: this code also runs from the advanced-cache.php drop-in and from cron, where WP_Filesystem is not available.
					@unlink( $file );
				}
			}
		}

		// Spec 29: the combined-sources manifest follows the files it describes.
		if ( class_exists( 'CachingOut_Combine' ) ) {
			CachingOut_Combine::prune_manifest();
		}
		// 1.21.3: so does the minified-sources manifest.
		if ( class_exists( 'CachingOut_Minified_Files' ) ) {
			CachingOut_Minified_Files::prune_manifest();
		}
	}

	/**
	 * Purge all archive pages a post belongs to.
	 *
	 * Walks through every public taxonomy associated with the post's type,
	 * gets the term archive URLs for each term assigned to the post, and
	 * purges each one. Also purges the author archive, date archives, and
	 * the post type's main archive page.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	private function purge_post_archives( WP_Post $post ): void {
		// Term archive pages for each public taxonomy assigned to this post type.
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		if ( is_array( $taxonomies ) ) {
			foreach ( $taxonomies as $taxonomy ) {
				if ( empty( $taxonomy->public ) ) {
					continue;
				}

				$terms = get_the_terms( $post->ID, $taxonomy->name );
				if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
					continue;
				}

				foreach ( $terms as $term ) {
					$term_link = get_term_link( $term, $taxonomy->name );
					if ( is_wp_error( $term_link ) ) {
						continue;
					}
					$this->purge_url( $term_link );
				}
			}
		}

		// Author archive.
		$author_url = get_author_posts_url( (int) $post->post_author );
		if ( $author_url ) {
			$this->purge_url( $author_url );
		}

		// Date archives: year, month, day.
		$timestamp = strtotime( $post->post_date );
		if ( false !== $timestamp ) {
			$year  = gmdate( 'Y', $timestamp );
			$month = gmdate( 'm', $timestamp );
			$day   = gmdate( 'd', $timestamp );

			$year_url  = get_year_link( $year );
			$month_url = get_month_link( $year, $month );
			$day_url   = get_day_link( $year, $month, $day );

			if ( $year_url ) {
				$this->purge_url( $year_url );
			}
			if ( $month_url ) {
				$this->purge_url( $month_url );
			}
			if ( $day_url ) {
				$this->purge_url( $day_url );
			}
		}

		// Post type archive page.
		$post_type_archive = get_post_type_archive_link( $post->post_type );
		if ( $post_type_archive ) {
			$this->purge_url( $post_type_archive );
		}
	}
}