<?php
/**
 * Database cleanup engine: runs and previews cleanup operations on WordPress
 * core tables.
 *
 * @package CachingOut
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This class is the database cleanup tool: it counts and deletes rows in bulk, which no WordPress API does, and a cached count would be a wrong count.

/**
 * Database cleanup engine.
 *
 * One class that handles all PRD 5.6 cleanup operations. Each operation is
 * a private method named `_run_{operation}()` and `_preview_{operation}()`.
 *
 * @since 0.1.0
 */
class CachingOut_DB_Cleanup {

	/**
	 * Estimated average row sizes for space-saving calculations.
	 *
	 * @since 0.1.0
	 * @var array
	 */
	const ROW_BYTES = array(
		'post'    => 200,
		'comment' => 150,
		'option'  => 100,
		'meta'    => 50,
	);

	/**
	 * Operations this class implements.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	private $known_operations = array(
		'revisions',
		'auto_drafts',
		'trashed_posts',
		'spam_comments',
		'trashed_comments',
		'expired_transients',
		'all_transients',
	);

	/**
	 * Operations added through register_operation(), keyed by name.
	 *
	 * @since 1.21.13
	 * @var array<string, array{run: callable, preview: callable, available: callable|null}>
	 */
	private $registered_operations = array();

	// ────────────────────────────── Public API ──────────────────────────────

	/**
	 * Add a cleanup operation implemented outside this class.
	 *
	 * Both callbacks return the same shape as the built-in operations. A name
	 * this class already implements is refused, so a built-in operation can
	 * never be replaced.
	 *
	 * @since 1.21.13
	 *
	 * @param string        $operation Operation name (lowercase letters, digits, underscores).
	 * @param callable      $run       Runs the cleanup. Returns array{count: int, space_saved: int}.
	 * @param callable      $preview   Counts only, deletes nothing. Same return shape.
	 * @param callable|null $available Optional. Returns false while the operation must not run.
	 * @return bool True when the operation was added.
	 */
	public function register_operation( string $operation, callable $run, callable $preview, ?callable $available = null ): bool {
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $operation ) || in_array( $operation, $this->known_operations, true ) ) {
			return false;
		}

		$this->registered_operations[ $operation ] = array(
			'run'       => $run,
			'preview'   => $preview,
			'available' => $available,
		);

		return true;
	}

	/**
	 * Whether an operation may run on this site: an unknown operation never,
	 * a registered one only while its own availability callback allows it.
	 *
	 * @since 1.21.0
	 * @since 1.21.13 Covers registered operations.
	 *
	 * @param string $operation Operation name.
	 * @return bool
	 */
	public function operation_available( string $operation ): bool {
		if ( in_array( $operation, $this->known_operations, true ) ) {
			return true;
		}
		if ( ! isset( $this->registered_operations[ $operation ] ) ) {
			return false;
		}

		$available = $this->registered_operations[ $operation ]['available'];

		return null === $available || (bool) call_user_func( $available );
	}

	/**
	 * Run a cleanup operation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $operation Operation name.
	 * @return array{count: int, space_saved: int}
	 */
	public function run( string $operation ): array {
		return $this->dispatch( $operation, 'run' );
	}

	/**
	 * Preview a cleanup operation (COUNT only, no DELETE).
	 *
	 * @since 0.1.0
	 *
	 * @param string $operation Operation name.
	 * @return array{count: int, space_saved: int}
	 */
	public function preview( string $operation ): array {
		return $this->dispatch( $operation, 'preview' );
	}

	/**
	 * Run or preview one operation, built-in or registered.
	 *
	 * @since 1.21.13
	 *
	 * @param string $operation Operation name.
	 * @param string $mode      'run' or 'preview'.
	 * @return array{count: int, space_saved: int}
	 */
	private function dispatch( string $operation, string $mode ): array {
		$nothing = array(
			'count'       => 0,
			'space_saved' => 0,
		);

		if ( ! $this->operation_available( $operation ) ) {
			return $nothing;
		}

		if ( isset( $this->registered_operations[ $operation ] ) ) {
			$result = call_user_func( $this->registered_operations[ $operation ][ $mode ] );

			return array(
				'count'       => is_array( $result ) && isset( $result['count'] ) ? (int) $result['count'] : 0,
				'space_saved' => is_array( $result ) && isset( $result['space_saved'] ) ? (int) $result['space_saved'] : 0,
			);
		}

		$method = '_' . $mode . '_' . $operation;
		if ( ! method_exists( $this, $method ) ) {
			return $nothing;
		}

		return $this->$method();
	}

	/**
	 * Get the list of known operation names: the built-in ones, then any
	 * registered ones in the order they were added.
	 *
	 * @since 0.1.0
	 * @return string[]
	 */
	public function get_operations(): array {
		return array_merge( $this->known_operations, array_keys( $this->registered_operations ) );
	}

	// ──────────────────────────── Revisions ─────────────────────────────────

	/**
	 * Run: delete all post revisions.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _run_revisions(): array {
		global $wpdb;

		$count = $wpdb->query(
			"DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		return array(
			'count'       => (int) $count,
			'space_saved' => (int) $count * self::ROW_BYTES['post'],
		);
	}

	/**
	 * Preview: count post revisions.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _preview_revisions(): array {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'"
		);

		return array(
			'count'       => $count,
			'space_saved' => $count * self::ROW_BYTES['post'],
		);
	}

	// ──────────────────────────── Auto Drafts ───────────────────────────────

	/**
	 * Run: delete auto-draft posts older than 7 days.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _run_auto_drafts(): array {
		global $wpdb;

		$count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->posts}
				WHERE post_status = 'auto-draft'
				AND post_date < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);

		return array(
			'count'       => (int) $count,
			'space_saved' => (int) $count * self::ROW_BYTES['post'],
		);
	}

	/**
	 * Preview: count auto-draft posts older than 7 days.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _preview_auto_drafts(): array {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_status = 'auto-draft'
				AND post_date < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
			)
		);

		return array(
			'count'       => $count,
			'space_saved' => $count * self::ROW_BYTES['post'],
		);
	}

	// ──────────────────────────── Trashed Posts ─────────────────────────────

	/**
	 * Run: delete all trashed posts and their postmeta.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _run_trashed_posts(): array {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		$post_count  = count( $post_ids );
		$meta_count  = 0;
		$space_saved = $post_count * self::ROW_BYTES['post'];

		if ( $post_count > 0 ) {
			$ids_placeholders = implode( ',', array_fill( 0, $post_count, '%d' ) );

			// Count and delete postmeta first.
			$meta_count = (int) $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids_placeholders is only a list of %d placeholders, one per id; the ids themselves go through prepare().
					"DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids_placeholders)",
					...$post_ids
				)
			);

			// Delete the trashed posts.
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids_placeholders is only a list of %d placeholders, one per id; the ids themselves go through prepare().
					"DELETE FROM {$wpdb->posts} WHERE ID IN ($ids_placeholders)",
					...$post_ids
				)
			);

			$space_saved += $meta_count * self::ROW_BYTES['meta'];
		}

		return array(
			'count'       => $post_count + $meta_count,
			'space_saved' => $space_saved,
		);
	}

	/**
	 * Preview: count trashed posts and their postmeta.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _preview_trashed_posts(): array {
		global $wpdb;

		$post_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'"
		);

		$post_count  = count( $post_ids );
		$meta_count  = 0;
		$space_saved = $post_count * self::ROW_BYTES['post'];

		if ( $post_count > 0 ) {
			$ids_placeholders = implode( ',', array_fill( 0, $post_count, '%d' ) );
			$meta_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids_placeholders is only a list of %d placeholders, one per id; the ids themselves go through prepare().
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ($ids_placeholders)",
					...$post_ids
				)
			);
			$space_saved += $meta_count * self::ROW_BYTES['meta'];
		}

		return array(
			'count'       => $post_count + $meta_count,
			'space_saved' => $space_saved,
		);
	}

	// ──────────────────────────── Spam Comments ─────────────────────────────

	/**
	 * Run: delete spam comments.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _run_spam_comments(): array {
		global $wpdb;

		$count = $wpdb->query(
			"DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);

		return array(
			'count'       => (int) $count,
			'space_saved' => (int) $count * self::ROW_BYTES['comment'],
		);
	}

	/**
	 * Preview: count spam comments.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _preview_spam_comments(): array {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
		);

		return array(
			'count'       => $count,
			'space_saved' => $count * self::ROW_BYTES['comment'],
		);
	}

	// ──────────────────────────── Trashed Comments ──────────────────────────

	/**
	 * Run: delete trashed comments.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _run_trashed_comments(): array {
		global $wpdb;

		$count = $wpdb->query(
			"DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
		);

		return array(
			'count'       => (int) $count,
			'space_saved' => (int) $count * self::ROW_BYTES['comment'],
		);
	}

	/**
	 * Preview: count trashed comments.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _preview_trashed_comments(): array {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'"
		);

		return array(
			'count'       => $count,
			'space_saved' => $count * self::ROW_BYTES['comment'],
		);
	}

	// ────────────────────────── Expired Transients ──────────────────────────

	/**
	 * Run: delete expired transients (timeout rows + orphaned value rows).
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _run_expired_transients(): array {
		global $wpdb;

		$now = time();

		// Delete expired timeout rows.
		$count = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		// Delete orphaned transient value rows (timeout already gone).
		$count += $wpdb->query(
			"DELETE t FROM {$wpdb->options} t
			LEFT JOIN {$wpdb->options} timeout_rows
				ON timeout_rows.option_name = CONCAT('_transient_timeout_', SUBSTRING(t.option_name, 12))
			WHERE t.option_name LIKE '\_transient\_%'
			AND timeout_rows.option_id IS NULL"
		);

		return array(
			'count'       => (int) $count,
			'space_saved' => (int) $count * self::ROW_BYTES['option'],
		);
	}

	/**
	 * Preview: count expired transients and orphaned value rows.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _preview_expired_transients(): array {
		global $wpdb;

		$now = time();

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		$count += (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} t
			LEFT JOIN {$wpdb->options} timeout_rows
				ON timeout_rows.option_name = CONCAT('_transient_timeout_', SUBSTRING(t.option_name, 12))
			WHERE t.option_name LIKE '\_transient\_%'
			AND timeout_rows.option_id IS NULL"
		);

		return array(
			'count'       => $count,
			'space_saved' => $count * self::ROW_BYTES['option'],
		);
	}

	// ──────────────────────────── All Transients ────────────────────────────

	/**
	 * Run: delete ALL transients (timeout + value, site-wide and network).
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _run_all_transients(): array {
		global $wpdb;

		$count = $wpdb->query(
			"DELETE FROM {$wpdb->options}
			WHERE option_name LIKE '\_transient\_%'
			OR option_name LIKE '\_site\_transient\_%'"
		);

		return array(
			'count'       => (int) $count,
			'space_saved' => (int) $count * self::ROW_BYTES['option'],
		);
	}

	/**
	 * Preview: count all transients.
	 *
	 * @since 0.1.0
	 * @return array{count: int, space_saved: int}
	 */
	private function _preview_all_transients(): array {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options}
			WHERE option_name LIKE '\_transient\_%'
			OR option_name LIKE '\_site\_transient\_%'"
		);

		return array(
			'count'       => $count,
			'space_saved' => $count * self::ROW_BYTES['option'],
		);
	}
}