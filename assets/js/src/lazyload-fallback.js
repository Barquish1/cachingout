/**
 * Caching Out - loading="lazy" polyfill.
 *
 * Readable source of ../lazyload-fallback.min.js, which CachingOut_LazyLoad
 * prints inline on the front end. Dependency-free. Does nothing in a browser
 * that supports loading="lazy" natively.
 *
 * In a browser without native support, every lazy image and iframe has its
 * src / srcset / sizes moved to data attributes and is observed; when it comes
 * within 300px of the viewport the attributes are put back.
 */
( function () {
	if ( 'loading' in HTMLImageElement.prototype ) {
		return;
	}

	var observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( ! entry.isIntersecting ) {
					return;
				}

				var el = entry.target;

				if ( el.dataset.src ) {
					el.src = el.dataset.src;
					el.removeAttribute( 'data-src' );
				}
				if ( el.dataset.srcset ) {
					el.srcset = el.dataset.srcset;
					el.removeAttribute( 'data-srcset' );
				}
				if ( el.dataset.sizes ) {
					el.sizes = el.dataset.sizes;
					el.removeAttribute( 'data-sizes' );
				}

				el.removeAttribute( 'loading' );
				observer.unobserve( el );
			} );
		},
		{ rootMargin: '300px' }
	);

	document.querySelectorAll( 'img[loading="lazy"],iframe[loading="lazy"]' ).forEach( function ( el ) {
		if ( el.tagName === 'IMG' ) {
			if ( el.src ) {
				el.dataset.src = el.src;
				el.removeAttribute( 'src' );
			}
			if ( el.srcset ) {
				el.dataset.srcset = el.srcset;
				el.removeAttribute( 'srcset' );
			}
			if ( el.sizes ) {
				el.dataset.sizes = el.sizes;
				el.removeAttribute( 'sizes' );
			}
		}

		observer.observe( el );
	} );
} )();
