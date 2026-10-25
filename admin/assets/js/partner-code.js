/**
 * Pro tab - optional partner code on the Upgrade link (Partner Engine
 * FR-ATT-07).
 *
 * The link's href is the bare pricing URL. Only when the Upgrade link is
 * clicked is a typed or pasted code added, as one query argument whose name
 * the page provides (data-ref-param, filter `cachingout_upgrade_ref_param`).
 * The rules match CachingOut_Brand::sanitize_partner_code(): trimmed, then
 * 1-64 characters of [A-Za-z0-9_-], otherwise nothing is added. Nothing is
 * fetched, stored or sent before the click; the href goes back to the bare
 * URL straight after it.
 *
 * @package CachingOut
 * @since 1.21.16
 */

( function ( doc ) {
	'use strict';

	var link = doc.getElementById( 'cachingout-upgrade-link' );
	var field = doc.getElementById( 'cachingout-partner-code' );
	var invalid = doc.getElementById( 'cachingout-partner-code-invalid' );

	if ( ! link || ! field ) {
		return;
	}

	var baseUrl = link.getAttribute( 'href' );
	var param = link.getAttribute( 'data-ref-param' ) || 'ref';
	var codePattern = /^[A-Za-z0-9_-]{1,64}$/;

	/**
	 * Show or hide the invalid-code message.
	 *
	 * @param {boolean} show True to show it.
	 */
	function setInvalid( show ) {
		field.setAttribute( 'aria-invalid', show ? 'true' : 'false' );
		field.classList.toggle( 'is-invalid', show );
		if ( invalid ) {
			invalid.hidden = ! show;
		}
	}

	/**
	 * Point the link at the URL for this click.
	 *
	 * @param {Event} event Click or auxclick.
	 */
	function onActivate( event ) {
		var code = field.value.trim();

		if ( '' === code ) {
			setInvalid( false );
			link.setAttribute( 'href', baseUrl );
			return;
		}

		if ( ! codePattern.test( code ) ) {
			event.preventDefault();
			setInvalid( true );
			field.focus();
			return;
		}

		setInvalid( false );

		var url = new URL( baseUrl );
		url.searchParams.set( param, code );
		link.setAttribute( 'href', url.toString() );

		// Click time only: the code does not stay in the page.
		window.setTimeout( function () {
			link.setAttribute( 'href', baseUrl );
		}, 0 );
	}

	link.addEventListener( 'click', onActivate );
	link.addEventListener( 'auxclick', onActivate );
	field.addEventListener( 'input', function () {
		setInvalid( false );
	} );
} )( document );
