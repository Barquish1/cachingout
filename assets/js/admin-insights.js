/**
 * Insights dashboard JavaScript (free edition).
 *
 * Owns the Page Audit: run it, render every finding in full, apply the fixes
 * that are plugin settings. No external dependencies, no framework.
 *
 * The PageSpeed half (opt-in, score dial, history chart, recommendations)
 * lives in the Pro add-on's admin-insights-pro.js, which reuses the helpers
 * published on window.cachingOutInsights below (spec 30).
 *
 * @package CachingOut
 * @since 0.1.0
 * @since 1.21.0 Page Audit only.
 */

( function () {
	'use strict';

	var data = window.cachingOutData || {};
	var nonce = data.nonce || '';
	var ajaxUrl = data.ajaxUrl || '';
	var pro = data.pro || {};
	var proUnavailable = pro.unavailable || {};

	/**
	 * Inline SVG markup for a named glyph (localized by the shell script).
	 *
	 * @param {string} name Glyph name.
	 * @return {string} SVG markup or empty string.
	 */
	function rsIcon( name ) { return ( window.cachingOutIcons && window.cachingOutIcons[ name ] ) || ''; }

	/**
	 * Format an ISO 8601 timestamp to a short date string.
	 *
	 * @param {string} timestamp ISO 8601 string.
	 * @return {string} Formatted date or empty string.
	 */
	function formatDate( timestamp ) {
		if ( ! timestamp ) return '';
		var d = new Date( timestamp );
		if ( isNaN( d.getTime() ) ) return '';
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
	}

	/**
	 * Send an AJAX request to the Insights handlers.
	 *
	 * @param {string} action AJAX action name.
	 * @param {Object} body   Additional POST data.
	 * @return {Promise} Fetch promise resolving to the parsed JSON.
	 */
	function ajaxPost( action, body ) {
		var params = new URLSearchParams();
		params.append( 'action', action );
		params.append( 'nonce', nonce );

		Object.keys( body || {} ).forEach( function ( key ) {
			params.append( key, body[ key ] );
		} );

		return fetch( ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string.
	 */
	function escHtml( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	/**
	 * Escape an HTML attribute value.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string.
	 */
	function escAttr( str ) {
		return String( str ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	// -- Page Audit (Spec 24) --------------------------------------------

	var SEVERITY_BADGE = { high: ' rs-chip--error', medium: ' rs-chip--warning', low: '' };

	/**
	 * The deep link that hands one finding to the assistant, or '' when this
	 * site has no assistant (the free edition).
	 *
	 * @param {string} text Question text.
	 * @return {string} URL or empty string.
	 */
	function assistantUrl( text ) {
		if ( ! data.assistantUrl ) {
			return '';
		}
		var sep = data.assistantUrl.indexOf( '?' ) === -1 ? '?' : '&';
		return data.assistantUrl + sep + 'cachingout_q=' + encodeURIComponent( text );
	}

	/**
	 * One finding card. Always rendered in full: the title, the detail and
	 * the evidence are the same whatever this site has. Only the buttons
	 * differ - a fix that cannot apply here is replaced by one sentence
	 * naming the Pro feature and one link.
	 *
	 * @param {Object} f     Finding.
	 * @param {Array}  pages Page labels the finding was seen on.
	 * @return {string} HTML.
	 */
	function renderFinding( f, pages ) {
		var status = f.status;
		var chip = status === 'fix' ? ' rs-chip--signal' : ( status === 'advice' ? SEVERITY_BADGE[ f.severity ] || '' : ' rs-chip--signal' );
		var icon = status === 'fix' ? 'magic' : ( status === 'advice' ? 'lightbulb' : 'check-circle' );
		var proLabel = f.feature ? proUnavailable[ f.feature ] : '';
		var html = '<div class="rs-finding rs-finding--' + status + ' rs-card rs-card--muted rs-mb-2" data-id="' + escAttr( f.id ) + '">' +
			'<div class="rs-cluster rs-cluster--start rs-cluster--between rs-cluster--loose rs-card__body">' +
				'<div>' +
					'<span class="rs-chip' + chip + '">' + rsIcon( icon ) + ' ' + escHtml( status === 'fix' ? 'Fix available' : ( status === 'advice' ? f.severity : 'OK' ) ) + '</span>' +
					'<span class="rs-text--semibold">' + escHtml( f.title ) + '</span>' +
					'<div class="rs-text--muted rs-text--small rs-mt-2">' + escHtml( f.detail ) + '</div>' +
					( f.evidence && f.evidence.length ? '<div class="rs-text--small rs-text--mono rs-mt-2">' + f.evidence.map( escHtml ).join( '<br>' ) + '</div>' : '' ) +
					'<div class="rs-text--muted rs-text--small rs-mt-2">' + escHtml( 'Pages: ' + pages.join( ', ' ) ) + '</div>';

		if ( proLabel ) {
			html += '<p class="rs-pro-pointer rs-help rs-mt-2">' + escHtml( pro.context || '' ) + ' <strong>' + escHtml( proLabel ) + '</strong> ' +
				( pro.url ? '<a href="' + escAttr( pro.url ) + '">' + escHtml( pro.linkLabel || 'See what Pro adds' ) + '</a>' : '' ) +
				'</p>';
		}

		html += '</div><div class="rs-text--nowrap">';

		// A fix button is only offered where the fix can actually apply.
		if ( ! proLabel ) {
			if ( status === 'fix' && f.fix_id ) {
				html += '<button type="button" class="rs-button rs-button--primary rs-button--sm rs-apply-fix" data-fix="' + escAttr( f.fix_id ) + '" title="' + escAttr( f.fix_label || '' ) + '">' + rsIcon( 'magic' ) + ' Fix</button>';
			}
			if ( f.action && f.action.id && f.action.url ) {
				// Spec 38: a link action opens an admin screen of this site
				// (the server keeps only such URLs); no AJAX apply.
				// data-action-id lets an add-on enhance its own link (1.25.0).
				html += '<a class="rs-button rs-button--primary rs-button--sm rs-action-link" data-action-id="' + escAttr( f.action.id ) + '" href="' + escAttr( f.action.url ) + '">' + rsIcon( 'magic' ) + ' ' + escHtml( f.action.label || 'Open' ) + '</a>';
			} else if ( f.action && f.action.id ) {
				html += '<button type="button" class="rs-button rs-button--primary rs-button--sm rs-apply-action" data-action="' + escAttr( f.action.id ) + '" data-value="' + escAttr( f.action.value || '' ) + '">' + rsIcon( 'shield-check' ) + ' ' + escHtml( f.action.label || 'Apply' ) + '</button>';
			}
		}

		if ( status !== 'pass' ) {
			var ask = assistantUrl( f.title + ' - ' + f.detail + ( f.evidence && f.evidence.length ? ' (' + f.evidence.slice( 0, 3 ).join( ', ' ) + ')' : '' ) );
			if ( ask ) {
				html += ' <a class="rs-button rs-button--sm" href="' + escAttr( ask ) + '">' + rsIcon( 'chat-dots' ) + ' Ask the assistant</a>';
			}
		}

		html += '</div></div></div>';
		return html;
	}

	/**
	 * Render the whole Page Audit list from the stored per-page results.
	 *
	 * @param {Object} pageAudit Map of page slug to { label, result }.
	 * @return {void}
	 */
	function renderPageAudit( pageAudit ) {
		var list = document.getElementById( 'rs-page-audit-list' );
		if ( ! list ) {
			return;
		}
		// Group identical findings across pages: one card, all pages listed.
		var grouped = {};
		var order = [];
		var audited = 0;
		var stamp = '';
		Object.keys( pageAudit || {} ).forEach( function ( slug ) {
			var entry = pageAudit[ slug ];
			if ( ! entry || ! entry.result || ! entry.result.findings ) {
				return;
			}
			audited++;
			stamp = entry.result.timestamp || stamp;
			entry.result.findings.forEach( function ( f ) {
				var key = f.id + '|' + f.status;
				if ( ! grouped[ key ] ) {
					grouped[ key ] = { finding: f, pages: [] };
					order.push( key );
				}
				grouped[ key ].pages.push( entry.label );
			} );
		} );

		if ( ! audited ) {
			list.innerHTML = '<div class="rs-alert rs-alert--info rs-mb-0" role="status">' + rsIcon( 'info-circle' ) + '<div class="rs-alert__body">No page audit yet. Click "Audit pages now" - it takes a few seconds and needs no API key.</div></div>';
			return;
		}

		var rank = { fix: 0, advice: 1, pass: 2 };
		var sev = { high: 0, medium: 1, low: 2 };
		order.sort( function ( a, b ) {
			var fa = grouped[ a ].finding, fb = grouped[ b ].finding;
			return ( rank[ fa.status ] - rank[ fb.status ] ) || ( sev[ fa.severity ] - sev[ fb.severity ] ) || fa.id.localeCompare( fb.id );
		} );

		var fixes = [], advice = [], passes = [];
		order.forEach( function ( key ) {
			var g = grouped[ key ];
			var card = renderFinding( g.finding, g.pages );
			// An advice finding that carries an action is fixable inside the
			// plugin (1.25.0): it is listed with the fixes, not under "Outside
			// the plugin" - unless that action needs Pro and Pro is not here.
			var actionable = g.finding.status === 'advice' && g.finding.action && g.finding.action.id && ! ( g.finding.feature && proUnavailable[ g.finding.feature ] );
			( g.finding.status === 'fix' || actionable ? fixes : ( g.finding.status === 'advice' ? advice : passes ) ).push( card );
		} );

		var html = '';
		if ( fixes.length ) {
			html += '<h6 class="rs-mt-2">Fix with one click</h6>' + fixes.join( '' );
		}
		if ( advice.length ) {
			html += '<h6 class="rs-mt-4">Outside the plugin - for your theme or host</h6>' + advice.join( '' );
		}
		if ( ! fixes.length && ! advice.length ) {
			html += '<div class="rs-alert rs-alert--success rs-mb-2" role="status">' + rsIcon( 'check-circle' ) + '<div class="rs-alert__body">Nothing left to fix on the audited pages.</div></div>';
		}
		if ( passes.length ) {
			html += '<details class="rs-mt-4"><summary class="rs-text--muted rs-text--small">' + passes.length + ' check(s) already passing - what the plugin is doing for you</summary><div class="rs-mt-2">' + passes.join( '' ) + '</div></details>';
		}
		if ( stamp ) {
			html += '<div class="rs-text--muted rs-text--small rs-mt-2">Last audited: ' + escHtml( formatDate( stamp ) ) + '</div>';
		}
		list.innerHTML = html;

		list.querySelectorAll( '.rs-apply-fix' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var fix = btn.getAttribute( 'data-fix' );
				btn.disabled = true;
				btn.setAttribute( 'aria-busy', 'true' );
				btn.innerHTML = '<span class="rs-audit-spinner"></span> Applying...';
				ajaxPost( 'cachingout_apply_fix', { fix_id: fix } ).then( function ( r ) {
					btn.removeAttribute( 'aria-busy' );
					if ( r && r.success ) {
						btn.className = 'rs-button rs-button--sm';
						btn.innerHTML = rsIcon( 'check-circle' ) + ' Applied';
						var card = btn.closest( '.rs-finding' );
						if ( card ) {
							var note = document.createElement( 'div' );
							note.className = 'rs-text--muted rs-text--small rs-mt-2';
							note.textContent = 'Enabled: ' + ( ( r.data && r.data.applied ) || [] ).join( ', ' ) + '. Re-run the page audit after purging the cache to confirm.';
							card.querySelector( 'div' ).appendChild( note );
						}
					} else {
						btn.disabled = false;
						btn.innerHTML = rsIcon( 'magic' ) + ' Fix';
						alert( ( r && r.data && r.data.message ) || 'Could not apply this fix.' );
					}
				} );
			} );
		} );

		// Spec 29: finding actions (one-click Excluded CSS append), recorded
		// on the Activity page and revertible there.
		list.querySelectorAll( '.rs-apply-action' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var original = btn.innerHTML;
				btn.disabled = true;
				btn.setAttribute( 'aria-busy', 'true' );
				btn.innerHTML = '<span class="rs-audit-spinner"></span> Applying...';
				ajaxPost( 'cachingout_apply_finding_action', { action_id: btn.getAttribute( 'data-action' ), value: btn.getAttribute( 'data-value' ) } ).then( function ( r ) {
					btn.removeAttribute( 'aria-busy' );
					if ( r && r.success ) {
						btn.className = 'rs-button rs-button--sm';
						btn.innerHTML = rsIcon( 'check-circle' ) + ' Applied';
						var card = btn.closest( '.rs-finding' );
						if ( card ) {
							var note = document.createElement( 'div' );
							note.className = 'rs-text--muted rs-text--small rs-mt-2';
							var already = r.data && r.data.applied && r.data.applied.added === false;
							note.textContent = ( already ? 'Already listed in Excluded CSS.' : 'Added to File Optimization > Excluded CSS and recorded on the Activity page (revert there).' ) + ' Purge the cache and re-run the page audit to confirm.';
							card.querySelector( 'div' ).appendChild( note );
						}
					} else {
						btn.disabled = false;
						btn.innerHTML = original;
						alert( ( r && r.data && r.data.message ) || 'Could not apply this change.' );
					}
				} );
			} );
		} );
	}

	/**
	 * Load the stored Page Audit results and render them.
	 *
	 * @return {void}
	 */
	function loadPageAudit() {
		ajaxPost( 'cachingout_get_page_audit', {} ).then( function ( resp ) {
			renderPageAudit( resp && resp.success && resp.data ? resp.data.page_audit : {} );
		} );
	}

	var runPageAuditBtn = document.getElementById( 'rs-run-page-audit' );
	if ( runPageAuditBtn ) {
		runPageAuditBtn.addEventListener( 'click', function () {
			runPageAuditBtn.disabled = true;
			runPageAuditBtn.setAttribute( 'aria-busy', 'true' );
			runPageAuditBtn.innerHTML = '<span class="rs-audit-spinner"></span> Auditing...';
			ajaxPost( 'cachingout_run_page_audit', { page: 'all' } ).then( function ( resp ) {
				runPageAuditBtn.disabled = false;
				runPageAuditBtn.removeAttribute( 'aria-busy' );
				runPageAuditBtn.innerHTML = rsIcon( 'search' ) + ' Audit pages now';
				if ( resp && resp.success ) {
					renderPageAudit( resp.data.page_audit );
				} else {
					alert( ( resp && resp.data && resp.data.message ) || 'Page audit failed.' );
				}
			} );
		} );
	}

	if ( document.getElementById( 'rs-page-audit-list' ) ) {
		loadPageAudit();
	}

	// Published for the add-on's Insights script: the same request helper,
	// the same escaping, and the Page Audit renderer it refreshes after a
	// full run. Nothing here knows what the add-on does with them.
	window.cachingOutInsights = {
		ajaxPost: ajaxPost,
		escHtml: escHtml,
		escAttr: escAttr,
		formatDate: formatDate,
		renderPageAudit: renderPageAudit,
		loadPageAudit: loadPageAudit,
	};

} )();
