/**
 * Caching Out - lazy videos.
 *
 * Readable source of ../lazyload-video.min.js, which CachingOut_LazyLoad
 * prints inline on the front end when "LazyLoad videos" is on.
 * Dependency-free.
 *
 * - A <video data-cachingout-video> gets its source when it comes within
 *   200px of the viewport. One that was an autoplay video plays while
 *   visible and is paused when scrolled away.
 * - Embedded YouTube and Vimeo players are paused when scrolled away,
 *   through their postMessage APIs. Players added later (a click-to-load
 *   facade) are picked up by a MutationObserver.
 */
( function () {
	if ( ! ( 'IntersectionObserver' in window ) ) {
		return;
	}

	function load( video ) {
		if ( video.dataset.cachingoutLoaded ) {
			return;
		}
		video.dataset.cachingoutLoaded = '1';

		if ( video.dataset.src ) {
			video.src = video.dataset.src;
			video.removeAttribute( 'data-src' );
		}
		video.querySelectorAll( 'source[data-src]' ).forEach( function ( source ) {
			source.src = source.dataset.src;
			source.removeAttribute( 'data-src' );
		} );

		video.preload = 'auto';
		try {
			video.load();
		} catch ( e ) {}
	}

	var videoObserver = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				var video = entry.target;

				if ( entry.isIntersecting ) {
					load( video );
					if ( video.dataset.autoplay === '1' || video.dataset.cachingoutWasPlaying === '1' ) {
						video.muted = video.muted || video.dataset.autoplay === '1';
						var playing = video.play();
						if ( playing && playing.catch ) {
							playing.catch( function () {} );
						}
					}
				} else if ( ! video.paused ) {
					video.dataset.cachingoutWasPlaying = '1';
					video.pause();
				} else {
					delete video.dataset.cachingoutWasPlaying;
				}
			} );
		},
		{ rootMargin: '200px' }
	);

	document.querySelectorAll( 'video[data-cachingout-video]' ).forEach( function ( video ) {
		videoObserver.observe( video );
	} );

	function isYouTube( frame ) {
		return /youtube(-nocookie)?\.com\/embed\//i.test( frame.src );
	}

	function isVimeo( frame ) {
		return /player\.vimeo\.com\//i.test( frame.src );
	}

	var frameObserver = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					return;
				}

				var frame = entry.target;
				try {
					if ( isYouTube( frame ) ) {
						frame.contentWindow.postMessage( JSON.stringify( { event: 'command', func: 'pauseVideo', args: '' } ), '*' );
					} else if ( isVimeo( frame ) ) {
						frame.contentWindow.postMessage( JSON.stringify( { method: 'pause' } ), '*' );
					}
				} catch ( x ) {}
			} );
		},
		{ threshold: 0 }
	);

	function watch( root ) {
		( root.querySelectorAll ? root.querySelectorAll( 'iframe' ) : [] ).forEach( function ( frame ) {
			if ( frame.dataset.cachingoutWatched ) {
				return;
			}
			if ( isYouTube( frame ) || isVimeo( frame ) ) {
				frame.dataset.cachingoutWatched = '1';
				frameObserver.observe( frame );
			}
		} );
	}

	watch( document );

	if ( 'MutationObserver' in window ) {
		new MutationObserver( function ( mutations ) {
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType === 1 ) {
						if ( node.tagName === 'IFRAME' ) {
							watch( {
								querySelectorAll: function () {
									return [ node ];
								},
							} );
						} else {
							watch( node );
						}
					}
				} );
			} );
		} ).observe( document.body, { childList: true, subtree: true } );
	}
} )();
