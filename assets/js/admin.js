/**
 * Caching Out admin shell script.
 *
 * The one behaviour the Bootstrap bundle used to provide: a dismissible
 * alert. Nothing else; dependency-free (spec 35, 1.22.0).
 *
 * @package CachingOut
 * @since 1.22.0
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var close = event.target.closest( '.rs-alert__close' );
		if ( ! close ) {
			return;
		}
		var alert = close.closest( '.rs-alert' );
		if ( alert ) {
			alert.remove();
		}
	} );
}() );
