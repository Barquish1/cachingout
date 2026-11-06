<?php
/**
 * The plain-English template behind every `BYPASS:` reason the chokepoint
 * can emit (1.27.0, DECISIONS 2026-11-03).
 *
 * One entry per reason code. A key that ends in `:` is a prefix: the
 * parameter after it (a cookie name, a method, a Cache-Control directive, an
 * exclusion id) is substituted for `%s` in the text. The wording follows
 * `docs/troubleshooting.md` section 2, which stays the long-form reference.
 * BypassExplainerTest fails when a reason ships without an entry here.
 *
 * @package CachingOut
 * @since 1.27.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template catalogue for CachingOut_Bypass_Explainer.
 *
 * @since 1.27.0
 */
final class CachingOut_Bypass_Templates {

	/**
	 * Docs anchor of the full bypass table.
	 *
	 * @var string
	 */
	const DOC_TABLE = 'troubleshooting#2-every-bypass-reason';

	/**
	 * Every template, keyed by reason code or reason prefix.
	 *
	 * Each value: title, meaning, correct (true = right behaviour, false =
	 * a setting the admin can change, null = depends), fix, doc (a slug for
	 * CachingOut_Brand::docs_url()).
	 *
	 * @since 1.27.0
	 * @return array<string, array{title: string, meaning: string, correct: bool|null, fix: string, doc: string}>
	 */
	public static function all(): array {
		$nothing = __( 'Nothing. This is correct behaviour.', 'cachingout' );
		$store   = __( 'Nothing. Never cached, by design.', 'cachingout' );
		$t       = self::DOC_TABLE;

		return array(
			'BYPASS:foreign-host'              => self::t( __( 'Not this site', 'cachingout' ), __( 'The address belongs to another host, so this site cannot answer for it.', 'cachingout' ), null, __( 'Enter a path such as /about/ or a full address on this site.', 'cachingout' ), 'page-caching' ),
			'BYPASS:kill-switch'               => self::t( __( 'Kill switch on', 'cachingout' ), __( 'CACHINGOUT_DISABLE_CACHE is set to true in wp-config.php, so no page is read from or written to the cache.', 'cachingout' ), false, __( 'Intended while you investigate a problem. Remove that line to resume caching.', 'cachingout' ), 'troubleshooting#7-emergency-switch-caching-off-completely' ),
			'BYPASS:disabled'                  => self::t( __( 'Page caching is switched off', 'cachingout' ), __( 'The "Enable page caching" switch on the Cache tab is off.', 'cachingout' ), false, __( 'Switch it on and save.', 'cachingout' ), 'page-caching' ),
			/* translators: %s: the HTTP method, e.g. POST. */
			'BYPASS:method:'                   => self::t( __( 'Not a GET request', 'cachingout' ), __( 'The request used the %s method, for example a form submission. Only GET responses can be shared between visitors.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:ajax'                      => self::t( __( 'AJAX endpoint', 'cachingout' ), __( 'The request went to wp-admin/admin-ajax.php, which answers one visitor at a time.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:wp-admin'                  => self::t( __( 'Administration page', 'cachingout' ), __( 'The address is under /wp-admin. The dashboard is never cached.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:wp-login'                  => self::t( __( 'Login page', 'cachingout' ), __( 'The address is wp-login.php.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:wp-register'               => self::t( __( 'Registration page', 'cachingout' ), __( 'The address is wp-register.php.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:rest-api'                  => self::t( __( 'REST API', 'cachingout' ), __( 'The request went to a /wp-json/ endpoint. API responses are not pages.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:feed'                      => self::t( __( 'Feed', 'cachingout' ), __( 'The address is a feed: /feed/, /comments/feed/, or a feed segment anywhere in the address. A slug that merely starts with the word, like /feedback/, is not a feed.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:robots'                    => self::t( __( 'robots.txt', 'cachingout' ), __( 'The address is /robots.txt, which the web server and WordPress answer directly.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:sitemap'                   => self::t( __( 'XML sitemap', 'cachingout' ), __( 'The address is an XML sitemap.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:php-file'                  => self::t( __( 'PHP file', 'cachingout' ), __( 'The address ends in .php, for example xmlrpc.php. Those are scripts, not pages.', 'cachingout' ), true, $nothing, $t ),
			'BYPASS:static-asset'              => self::t( __( 'Static file', 'cachingout' ), __( 'The address ends in an image, stylesheet, script, font or media extension. Those are served by the web server, not the page cache.', 'cachingout' ), true, __( 'Nothing. Their caching is handled by Browser Caching.', 'cachingout' ), 'browser-caching' ),
			'BYPASS:logged-in'                 => self::t( __( 'Logged-in visitor', 'cachingout' ), __( 'The request carried a WordPress login cookie. A cached copy is shared between everyone who sees it, so a logged-in visitor is always served live.', 'cachingout' ), true, __( 'Nothing. Log out or use a private window to see what visitors get.', 'cachingout' ), $t ),
			/* translators: %s: a cookie name. */
			'BYPASS:variation-cookie:'         => self::t( __( 'Language or currency cookie', 'cachingout' ), __( 'The visitor carries the %s cookie, which changes what the same address shows, so a shared copy could be the wrong language or currency.', 'cachingout' ), null, __( 'When each language has its own address the cookie is ignored. When every language shares one address, give each its own, or accept that these visitors are served live.', 'cachingout' ), 'troubleshooting#language-cookies' ),
			/* translators: %s: a cookie name. */
			'BYPASS:session-cookie:'           => self::t( __( 'PHP session', 'cachingout' ), __( 'The visitor has a PHP session (%s). A session ties a page to one person, so it is never shared.', 'cachingout' ), null, __( 'If every page reports this, a plugin or theme starts a session for every visitor: find it (deactivate plugins one at a time) and ask its author for a setting that limits the session to the pages that need one.', 'cachingout' ), 'troubleshooting#every-page-bypasses-with-a-session-cookie' ),
			'BYPASS:password-cookie'           => self::t( __( 'Post password entered', 'cachingout' ), __( 'The visitor has entered a post password on this site (the wp-postpass_ cookie). Pages rendered for them can hold protected content, so they are always served live.', 'cachingout' ), true, $nothing, $t ),
			/* translators: %s: a cookie name. */
			'BYPASS:store-cookie:'             => self::t( __( 'Shopper with a cart', 'cachingout' ), __( 'The visitor has the %s cookie: a WooCommerce or Easy Digital Downloads cart or store session. A shopper with a cart is always served live.', 'cachingout' ), true, $nothing, $t ),
			/* translators: %s: a cookie name. */
			'BYPASS:cookie:'                   => self::t( __( 'Your own excluded cookie', 'cachingout' ), __( 'The visitor has the %s cookie, which is on your "Never cache when these cookies are present" list on the Cache tab.', 'cachingout' ), false, __( 'Adjust or remove that entry if it is too broad.', 'cachingout' ), 'page-caching' ),
			/* translators: %s: the pattern on the user's list that matched. */
			'BYPASS:user-agent:'               => self::t( __( 'Your own excluded user agent', 'cachingout' ), __( 'The browser identification matches "%s" on your "Never cache for these user agents" list on the Cache tab.', 'cachingout' ), false, __( 'Adjust or remove that entry.', 'cachingout' ), 'page-caching' ),
			'BYPASS:query-string'              => self::t( __( 'Query parameter not on the ignore list', 'cachingout' ), __( 'The address has a query parameter that is not on your "Ignored query parameters" list, for example ?ref=newsletter. A parameter that is not listed may change the page, so the request is served live.', 'cachingout' ), false, __( 'If the parameter is a tracking tag that does not change the page, add it to "Ignored query parameters" on the Cache tab; if a default is missing, use "Restore default parameters". Leave out any parameter that changes what the page shows.', 'cachingout' ), 'page-caching' ),
			'BYPASS:ignored-query-miss'        => self::t( __( 'Tracking parameters on a first visit', 'cachingout' ), __( 'Every parameter on the address is on the ignore list, so the visitor may be SERVED the plain page\'s copy, but this visit never CREATES that copy: a plugin reading the parameter server-side would otherwise put one visitor\'s version into the copy everyone gets.', 'cachingout' ), true, __( 'Nothing. The plain address, without parameters, fills the cache; visitors with tracking tags are served from it afterwards.', 'cachingout' ), 'page-caching' ),
			'BYPASS:woocommerce-cart'          => self::t( __( 'WooCommerce cart', 'cachingout' ), __( 'The address is a /cart/ page.', 'cachingout' ), true, $store, $t ),
			'BYPASS:woocommerce-checkout'      => self::t( __( 'WooCommerce checkout', 'cachingout' ), __( 'The address is a /checkout/ page.', 'cachingout' ), true, $store, $t ),
			'BYPASS:woocommerce-account'       => self::t( __( 'WooCommerce account', 'cachingout' ), __( 'The address is /my-account/ or a WooCommerce account endpoint such as /order-received/, /view-order/ or /downloads/.', 'cachingout' ), true, $store, $t ),
			'BYPASS:edd-purchase-history'      => self::t( __( 'EDD purchase history', 'cachingout' ), __( 'The address is an Easy Digital Downloads /purchase-history/ page.', 'cachingout' ), true, $store, $t ),
			'BYPASS:edd-purchase-confirmation' => self::t( __( 'EDD purchase confirmation', 'cachingout' ), __( 'The address is an Easy Digital Downloads /purchase-confirmation/ or /purchase-receipt/ page.', 'cachingout' ), true, $store, $t ),
			'BYPASS:excluded-path'             => self::t( __( 'Your own excluded address', 'cachingout' ), __( 'The address matches one of your "Never cache these URLs" patterns on the Cache tab.', 'cachingout' ), false, __( 'Adjust or remove that pattern if it is too broad.', 'cachingout' ), 'page-caching' ),
			'BYPASS:exclusion:woocommerce'     => self::t( __( 'WooCommerce store page', 'cachingout' ), __( 'The address is your store\'s cart, checkout or account page as configured in WooCommerce, wherever it lives: a cart page renamed to /basket/ is covered.', 'cachingout' ), true, __( 'Nothing. If a page is listed that is not a store page, check the page assignments in WooCommerce (Settings, Advanced).', 'cachingout' ), $t ),
			'BYPASS:exclusion:edd'             => self::t( __( 'Easy Digital Downloads store page', 'cachingout' ), __( 'The address is your store\'s checkout, purchase-history or purchase-confirmation page as configured in Easy Digital Downloads, wherever it lives.', 'cachingout' ), true, __( 'Nothing. If a page is listed that is not a store page, check the page assignments in EDD (Settings, General).', 'cachingout' ), $t ),
			'BYPASS:exclusion:partner-engine'  => self::t( __( 'Partner Engine link', 'cachingout' ), __( 'The address is under a link prefix the Partner Engine plugin registered (its /go/ links), which redirect per click.', 'cachingout' ), true, $nothing, $t ),
			/* translators: %s: the name of a compatibility module. */
			'BYPASS:exclusion:'                => self::t( __( 'Excluded by a compatibility module', 'cachingout' ), __( 'A rule registered by the "%s" compatibility module matched this address.', 'cachingout' ), null, __( 'Usually intended. The name says which module asked for it; check that module\'s own settings.', 'cachingout' ), 'compatibility' ),
			/* translators: %s: an HTTP status code, e.g. 404. */
			'BYPASS:status:'                   => self::t( __( 'Not a 200 response', 'cachingout' ), __( 'The page answered with status %s instead of 200 OK: a redirect, a missing page or a server error. Only successful pages are stored.', 'cachingout' ), null, __( 'If a page you expect to work reports a code here, fix the page. A redirect is normal and is never stored.', 'cachingout' ), $t ),
			'BYPASS:donotcachepage'            => self::t( __( 'A plugin asked for no caching', 'cachingout' ), __( 'A plugin or the theme asked, while the page was being generated, for this page not to be cached (it defined DONOTCACHEPAGE, the signal every caching plugin honours). Polylang does this on the front page in your default language when it detects the browser language; WooCommerce on its cart, checkout and account pages.', 'cachingout' ), null, __( 'The plugin that asked decides; Caching Out will not override it. For Polylang, switch off "Detect browser language" (Languages, Settings) to cache the front page, or keep the redirect and accept a live page. Otherwise find the plugin (deactivate plugins one at a time) and check its settings.', 'cachingout' ), 'troubleshooting#the-front-page-of-a-multilingual-site-is-never-cached' ),
			/* translators: %s: the header's value, e.g. private. */
			'BYPASS:cache-control:'            => self::t( __( 'The page said it is private', 'cachingout' ), __( 'The page itself sent Cache-Control: %s, which says it is for one visitor only. WordPress does this on password-protected posts; some plugins do it on per-visitor pages.', 'cachingout' ), null, __( 'Usually nothing. If every page reports it, a plugin or the theme sends the header everywhere: find it (deactivate plugins one at a time) and ask its maker.', 'cachingout' ), $t ),
			'BYPASS:set-cookie:auth'           => self::t( __( 'The page logged someone in', 'cachingout' ), __( 'The response set a WordPress login cookie.', 'cachingout' ), true, $nothing, $t ),
			/* translators: %s: a cookie name. */
			'BYPASS:set-cookie:session:'       => self::t( __( 'The page started a PHP session', 'cachingout' ), __( 'The response set the %s cookie, starting a PHP session that ties the page to one visitor.', 'cachingout' ), null, __( 'If every page reports this, find the plugin or theme that starts a session for every visitor and ask its author to limit it to the pages that need one.', 'cachingout' ), 'troubleshooting#every-page-bypasses-with-a-session-cookie' ),
			/* translators: %s: a cookie name. */
			'BYPASS:set-cookie:store:'         => self::t( __( 'The page opened a cart', 'cachingout' ), __( 'The response set the %s cookie, opening a cart or store session for this visitor.', 'cachingout' ), true, $nothing, $t ),
			/* translators: %s: a cookie name. */
			'BYPASS:set-cookie:'               => self::t( __( 'The page set one of your excluded cookies', 'cachingout' ), __( 'The response set the %s cookie, which is on your "Never cache when these cookies are present" list.', 'cachingout' ), false, __( 'Adjust or remove that entry if it is too broad.', 'cachingout' ), 'page-caching' ),
			'BYPASS:nonce'                     => self::t( __( 'Security token on the page', 'cachingout' ), __( 'The HTML contains a WordPress security token (nonce) that has to be fresh for each visitor. A cached token breaks form submissions.', 'cachingout' ), true, __( 'Usually a form on the page. Logout links in menus are already allowed for and do not cause this.', 'cachingout' ), $t ),
			'BYPASS:filter:cachingout_response_is_cacheable' => self::t( __( 'Code vetoed the write', 'cachingout' ), __( 'Another plugin, or code in your theme, used the cachingout_response_is_cacheable filter to stop this page being stored.', 'cachingout' ), null, __( 'The code that uses the filter decides. Search your plugins and theme for that filter name, or deactivate plugins one at a time.', 'cachingout' ), $t ),
		);
	}

	/**
	 * The template for a reason no entry covers. Never empty.
	 *
	 * @since 1.27.0
	 * @return array{title: string, meaning: string, correct: bool|null, fix: string, doc: string}
	 */
	public static function generic(): array {
		return self::t(
			__( 'Not cached', 'cachingout' ),
			/* translators: %s: a bypass reason code, e.g. BYPASS:nonce. */
			__( 'The page was not cached and the reason code is %s. Every code is listed in the troubleshooting guide.', 'cachingout' ),
			null,
			__( 'Look the code up in the troubleshooting guide, or quote it when asking for support.', 'cachingout' ),
			self::DOC_TABLE
		);
	}

	/**
	 * Shape one template.
	 *
	 * @since 1.27.0
	 *
	 * @param string    $title   Short title.
	 * @param string    $meaning What happened, in plain English.
	 * @param bool|null $correct Whether the bypass is right behaviour.
	 * @param string    $fix     What to do, or why nothing.
	 * @param string    $doc     Docs slug (CachingOut_Brand::docs_url()).
	 * @return array{title: string, meaning: string, correct: bool|null, fix: string, doc: string}
	 */
	private static function t( string $title, string $meaning, ?bool $correct, string $fix, string $doc ): array {
		return array(
			'title'   => $title,
			'meaning' => $meaning,
			'correct' => $correct,
			'fix'     => $fix,
			'doc'     => $doc,
		);
	}
}
