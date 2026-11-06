<?php
/**
 * "Check a page: why isn't it cached?" - the Cache tab tool (1.27.0).
 *
 * Asks the chokepoint for its verdict on one address as an anonymous
 * visitor (CachingOut_Cacheability::decide_for()) and shows the plain-English
 * explanation (CachingOut_Bypass_Explainer). Optionally fetches the page as
 * that visitor, with no cookies, to run the storage-stage rules too. Decides
 * nothing itself: every verdict comes from the chokepoint.
 *
 * @package CachingOut
 * @since 1.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page check tool controller.
 *
 * @since 1.27.0
 */
class CachingOut_Page_Check {

	/**
	 * The admin-post action.
	 *
	 * @var string
	 */
	const ACTION = 'cachingout_check_page';

	/**
	 * Nonce action and field name.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'cachingout_check_page';

	/**
	 * Transient prefix: the result travels to the redirected tab per user.
	 *
	 * @var string
	 */
	const TRANSIENT = 'cachingout_page_check_';

	/**
	 * Largest response body read when fetching the page (512 KB).
	 *
	 * @var int
	 */
	const MAX_BODY = 524288;

	/**
	 * The chokepoint.
	 *
	 * @var CachingOut_Cacheability
	 */
	private $cacheability;

	/**
	 * Constructor.
	 *
	 * @since 1.27.0
	 *
	 * @param CachingOut_Cacheability $cacheability The chokepoint, with every compat exclusion registered.
	 */
	public function __construct( CachingOut_Cacheability $cacheability ) {
		$this->cacheability = $cacheability;
	}

	/**
	 * Register the admin-post handler.
	 *
	 * @since 1.27.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The admin-post handler: capability, nonce, run, stash, redirect.
	 *
	 * @since 1.27.0
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'cachingout' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'cachingout_check_nonce' );

		$url   = isset( $_POST['check_url'] ) ? sanitize_text_field( wp_unslash( $_POST['check_url'] ) ) : '';
		$fetch = isset( $_POST['check_fetch'] );

		set_transient( self::TRANSIENT . get_current_user_id(), $this->run( $url, $fetch ), 120 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => CachingOut_Admin::PAGE_SLUG,
					'tab'        => 'cache',
					'page_check' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Check one address.
	 *
	 * The request stage always runs. When `$fetch` is true and the request
	 * stage passed, the page is fetched as an anonymous visitor (no cookies,
	 * 10 s, body capped at MAX_BODY, redirects not followed so a redirect
	 * reports its status) and the response stage runs on what came back.
	 * A foreign host is refused by decide_for() before any fetch.
	 *
	 * @since 1.27.0
	 *
	 * @param string $url   Path or absolute URL of this site.
	 * @param bool   $fetch Whether to fetch the page for the storage stage.
	 * @return array{url: string, decision: array, explanation: array|null, fetched: bool, fetch_error: string}
	 */
	public function run( string $url, bool $fetch ): array {
		$url      = trim( $url );
		$decision = $this->cacheability->decide_for( array( 'url' => '' === $url ? '/' : $url ) );
		$result   = array(
			'url'         => $url,
			'decision'    => $decision,
			'explanation' => null,
			'fetched'     => false,
			'fetch_error' => '',
		);

		if ( $fetch && 'ok' === $decision['stage'] ) {
			$response = wp_safe_remote_get(
				home_url( $decision['url'] ),
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'cookies'             => array(),
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter, applied to a loopback request.
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
					'user-agent'          => CachingOut_Cacheability::SIMULATED_USER_AGENT,
					'limit_response_size' => self::MAX_BODY,
				)
			);

			if ( is_wp_error( $response ) ) {
				$result['fetch_error'] = $response->get_error_message();
			} else {
				$result['fetched']  = true;
				$result['decision'] = $this->cacheability->decide_for(
					array(
						'url'      => $decision['url'],
						'response' => array(
							'status'  => (int) wp_remote_retrieve_response_code( $response ),
							'headers' => self::header_array( wp_remote_retrieve_headers( $response ) ),
							'html'    => (string) wp_remote_retrieve_body( $response ),
						),
					)
				);
			}
		}

		if ( '' !== $result['decision']['reason'] ) {
			$result['explanation'] = CachingOut_Bypass_Explainer::explain( $result['decision']['reason'] );
		}

		return $result;
	}

	/**
	 * Response headers as a plain name => value|array map.
	 *
	 * @since 1.27.0
	 *
	 * @param mixed $headers What wp_remote_retrieve_headers() returned.
	 * @return array
	 */
	private static function header_array( $headers ): array {
		return is_object( $headers ) && method_exists( $headers, 'getAll' ) ? (array) $headers->getAll() : ( is_array( $headers ) ? $headers : array() );
	}

	/**
	 * Render the tool: the form and, after a check, its result.
	 *
	 * Called from the Cache tab view. The result is read once from the
	 * current user's transient and deleted.
	 *
	 * @since 1.27.0
	 * @return void
	 */
	public static function render_section(): void {
		$result = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag set by our own redirect after a nonce-checked check; the result itself is read from the user's transient.
		if ( isset( $_GET['page_check'] ) ) {
			$key    = self::TRANSIENT . get_current_user_id();
			$stored = get_transient( $key );
			if ( is_array( $stored ) ) {
				$result = $stored;
				delete_transient( $key );
			}
		}
		?>
		<h2 class="rs-title-section" id="rs-page-check"><?php esc_html_e( 'Check a page: why isn\'t it cached?', 'cachingout' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rs-form">
			<?php wp_nonce_field( self::NONCE_ACTION, 'cachingout_check_nonce' ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<?php
			CachingOut_Admin::render_text(
				'check_url',
				__( 'Address to check', 'cachingout' ),
				null === $result ? '' : (string) $result['url'],
				'/about/',
				__( 'A path or a full address on this site. The check asks the same rules the cache uses, as a visitor who is not logged in and has no cookies.', 'cachingout' )
			);
			?>
			<div class="rs-field">
				<div class="rs-field__label"></div>
				<div class="rs-field__control">
					<label class="rs-check">
						<input type="checkbox" name="check_fetch" value="1" <?php checked( null !== $result && ( $result['fetched'] || '' !== $result['fetch_error'] ) ); ?>>
						<span class="rs-check__label"><?php esc_html_e( 'Also fetch the page to run the rules that apply after it is generated (status, headers, security tokens).', 'cachingout' ); ?></span>
					</label>
					<p class="rs-mt-2">
						<button type="submit" class="rs-button"><?php esc_html_e( 'Check this page', 'cachingout' ); ?></button>
					</p>
				</div>
			</div>
		</form>
		<?php
		if ( null !== $result ) {
			self::render_result( $result );
		}
	}

	/**
	 * Render one check result.
	 *
	 * @since 1.27.0
	 *
	 * @param array $result What run() returned.
	 * @return void
	 */
	public static function render_result( array $result ): void {
		$decision    = $result['decision'];
		$explanation = $result['explanation'];
		?>
		<div class="rs-card rs-card--full" aria-live="polite">
			<div class="rs-card__header">
				<h3 class="rs-card__title"><?php echo esc_html( null !== $explanation ? $explanation['title'] : ( $result['fetched'] ? __( 'This page can be cached and stored', 'cachingout' ) : __( 'This page can be served from cache', 'cachingout' ) ) ); ?></h3>
				<span class="rs-chip rs-chip--outline rs-chip--mono"><?php echo esc_html( '' === $decision['reason'] ? 'OK' : $decision['reason'] ); ?></span>
			</div>
			<div class="rs-card__body rs-stack">
				<p class="rs-text--muted rs-text--mono"><?php echo esc_html( $decision['url'] ); ?></p>
				<?php if ( null === $explanation ) : ?>
					<p><?php echo esc_html( $result['fetched'] ? __( 'Every rule passed: the request may be served from cache and the page the site returned may be written to it. The first anonymous visit stores it; the next is a HIT.', 'cachingout' ) : __( 'Every request-stage rule passed. Tick "Also fetch the page" to run the rules that apply after the page is generated.', 'cachingout' ) ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html( $explanation['meaning'] ); ?></p>
					<p class="rs-text--semibold">
						<?php
						if ( true === $explanation['correct'] ) {
							esc_html_e( 'This is correct behaviour.', 'cachingout' );
						} elseif ( false === $explanation['correct'] ) {
							esc_html_e( 'You can change this.', 'cachingout' );
						} else {
							esc_html_e( 'Whether to change this depends on your site.', 'cachingout' );
						}
						?>
					</p>
					<p><?php echo esc_html( $explanation['fix'] ); ?></p>
					<p>
						<a href="<?php echo esc_url( CachingOut_Brand::docs_url( $explanation['doc'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read more in the documentation', 'cachingout' ); ?></a>
					</p>
				<?php endif; ?>
				<?php if ( '' !== $result['fetch_error'] ) : ?>
					<p class="rs-text--warn">
						<?php
						printf(
							/* translators: %s: the HTTP error message. */
							esc_html__( 'The page could not be fetched, so only the request-stage rules were checked: %s', 'cachingout' ),
							esc_html( $result['fetch_error'] )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
