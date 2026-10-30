<?php
/**
 * The one Pro comparison screen (spec 30).
 *
 * States: Pro not installed (comparison + one link), Pro installed but not
 * switched on (the honest words, plus where the licence goes), Pro switched
 * on (a short confirmation). No banners elsewhere, no timers, no urgency.
 *
 * @package CachingOut
 * @since 1.21.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cachingout_pro_installed = class_exists( 'CachingOut_Features' ) && CachingOut_Features::pro_installed();
$cachingout_pro_on        = class_exists( 'CachingOut_Features' ) && CachingOut_Features::pro_available();
?>
<div class="rs-card rs-card--raised rs-mb-6">
	<div class="rs-card__header">
		<h2 class="rs-card__title"><?php esc_html_e( 'Caching Out Pro', 'cachingout' ); ?></h2>
	</div>
	<div class="rs-card__body">
		<?php if ( $cachingout_pro_on ) : ?>
			<p class="rs-mb-0">
				<?php esc_html_e( 'Pro is installed and switched on. Every feature below is available on this site; the free edition keeps running underneath it.', 'cachingout' ); ?>
			</p>
		<?php elseif ( $cachingout_pro_installed ) : ?>
			<p>
				<?php esc_html_e( 'Pro is installed but not switched on. It scans and audits meanwhile; your licence switches its features on. Everything the free edition does keeps running in the meantime.', 'cachingout' ); ?>
			</p>
			<p class="rs-mb-0">
				<a class="rs-button rs-button--primary rs-button--sm" href="<?php echo esc_url( admin_url( 'admin.php?page=cachingout&tab=license' ) ); ?>"><?php esc_html_e( 'Enter your licence', 'cachingout' ); ?></a>
			</p>
		<?php else : ?>
			<p>
				<?php esc_html_e( 'The free edition is complete on its own. Pro is a separate add-on, installed alongside it, that adds the features in the right-hand column and email support from the team who built it.', 'cachingout' ); ?>
			</p>
			<?php
			// Partner Engine FR-ATT-07 (WordPress.org guidelines 7 and 12): an
			// optional partner code, blank by default, typed or pasted only.
			// The link below is the bare pricing URL; partner-code.js adds
			// the code as a query argument at click time only, so nothing
			// leaves the site until Upgrade is clicked.
			$cachingout_partner_text = apply_filters(
				'cachingout_partner_code_disclosure',
				__( 'Have a partner code? Your upgrade link will credit them. This is optional: leave the field blank and nothing is sent. The code is added only to the direct link to cachingout.com/pricing/ when you click Upgrade.', 'cachingout' )
			);
			?>
			<div class="rs-cluster rs-cluster--loose">
				<div>
					<a id="cachingout-upgrade-link" class="rs-button rs-button--primary rs-button--sm" href="<?php echo esc_url( CachingOut_Brand::upgrade_url() ); ?>" data-ref-param="<?php echo esc_attr( CachingOut_Brand::upgrade_ref_param() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Upgrade', 'cachingout' ); ?></a>
				</div>
				<div>
					<label for="cachingout-partner-code" class="rs-label rs-mb-2"><?php esc_html_e( 'Have a partner code?', 'cachingout' ); ?></label>
					<input type="text" id="cachingout-partner-code" class="rs-input rs-input--sm" value="" maxlength="64" pattern="[A-Za-z0-9_\-]{1,64}" autocomplete="off" spellcheck="false" aria-describedby="cachingout-partner-code-disclosure">
				</div>
			</div>
			<p id="cachingout-partner-code-disclosure" class="rs-help rs-mt-2"><?php echo esc_html( $cachingout_partner_text ); ?></p>
			<p id="cachingout-partner-code-invalid" class="rs-help rs-text--bad rs-mt-2" role="alert" hidden><?php esc_html_e( 'A partner code uses only letters, numbers, hyphens and underscores (up to 64). Correct it, or clear the field to continue without one.', 'cachingout' ); ?></p>
		<?php endif; ?>
	</div>
</div>

<div class="rs-grid rs-grid--2">
	<div>
		<div class="rs-card rs-card--raised rs-card--full">
			<div class="rs-card__header"><h3 class="rs-card__title"><?php esc_html_e( 'In the free edition', 'cachingout' ); ?></h3></div>
			<ul class="rs-list rs-list--flush">
				<li class="rs-list__item"><?php esc_html_e( 'Page caching, served before WordPress loads', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Automatic purge on content change, archives included; admin-bar purge; cache lifespan; atomic cache writes; separate mobile cache files', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Browser caching, GZIP and Brotli, written through the hardened server-config writer', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Lazy loading for images, iframes and video; missing width and height attributes; hero-image priority', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Minify CSS and JS, and defer JS, each off until you switch it on, with per-file inclusion and exclusion controls', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Database cleanup by hand, with the row counts shown first', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'The Page Audit and the Filesystem Check, in full', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'The complete safety layer: logged-in visitors, carts, checkouts, accounts and session cookies are never cached; wp-admin and the login screen are never cached; only ordinary page views are cached; the drop-in ownership guard; the kill switch; a clean uninstall', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Community support in the WordPress.org support forum', 'cachingout' ); ?></li>
			</ul>
		</div>
	</div>
	<div>
		<div class="rs-card rs-card--raised rs-card--full">
			<div class="rs-card__header"><h3 class="rs-card__title"><?php esc_html_e( 'Pro adds', 'cachingout' ); ?></h3></div>
			<ul class="rs-list rs-list--flush">
				<li class="rs-list__item"><?php esc_html_e( 'Optimize CSS delivery, combine CSS and JS, delay JS, Google Fonts (combine and self-host), WebP and AVIF serving where copies already exist, YouTube click-to-load', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Image compression: pick an image from the Page Audit or the Media Library, see it before and after, and keep the smaller file at the same address. One image at a time, on your own server, always restorable', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Unused CSS/JS: stop named plugin files loading on pages that do not need them, with a scan, a preview, a dependency check, a never-unload list, suggested rules from the built-in catalogue and an optional weekly scan', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Activity: a record of every change the plugin makes, with one-click revert', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Performance Insights: a PageSpeed dial, a baseline, re-tests and history', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Cache preloading, a step you switch on', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Custom exclusion rules by URL, cookie and user agent', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'Database: scheduled cleanup, orphaned metadata, table optimisation', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'CDN rewriting; Cloudflare, Varnish and Sucuri purge add-ons', 'cachingout' ); ?></li>
				<li class="rs-list__item"><?php esc_html_e( 'WP-CLI commands; settings export and import; Multisite Network Admin', 'cachingout' ); ?></li>
				<li class="rs-list__item">
					<div class="rs-list__body">
						<?php esc_html_e( 'Email support from the team who built it - plus an assistant that already knows your setup. It knows which plugins you run, which settings you have on and what the Page Audit found, so you never have to explain your site before asking a question. Every answer points at the documentation or the rule it came from.', 'cachingout' ); ?>
						<p class="rs-help rs-mt-2"><?php esc_html_e( 'The assistant is scoped to Caching Out and your site\'s configuration: 50 questions a day and 300 a month per licence. It is not a general-purpose chatbot; answers are AI-generated and can be wrong, so check anything consequential against the linked source.', 'cachingout' ); ?></p>
					</div>
				</li>
			</ul>
		</div>
	</div>
</div>

<p class="rs-help rs-mt-6">
	<?php
	esc_html_e( 'In both editions, anything that can change how a page looks stays off until you switch it on.', 'cachingout' );
	echo ' ';
	esc_html_e( 'Pro comes with a 14-day money-back guarantee.', 'cachingout' );
	?>
</p>
