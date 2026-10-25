/**
 * Caching Out - lazy CSS background images.
 *
 * Readable source of ../lazyload-background.min.js, which CachingOut_LazyLoad
 * prints inline on the front end. Dependency-free.
 *
 * The plugin moves an element's background image into a
 * data-background-image attribute. When the element comes within 300px of the
 * viewport, the image is applied as its background and the attribute removed.
 */
( function () {
	var observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}

				var el = entry.target;
				var image = el.dataset.backgroundImage;

				if ( image ) {
					el.style.backgroundImage = 'url(' + image + ')';
					el.removeAttribute( 'data-background-image' );
					observer.unobserve( el );
				}
			} );
		},
		{ rootMargin: '300px' }
	);

	document.querySelectorAll( '[data-background-image]' ).forEach( function ( el ) {
		observer.observe( el );
	} );
} )();
