/**
 * Front end of the Interactive Transcript block: click a timestamp to seek, highlight the current line.
 */
( function () {
	function init( root ) {
		const media = root.querySelector( 'audio, video' );
		if ( ! media ) {
			return;
		}
		const segments = Array.from( root.querySelectorAll( '.audio-transcriber__segment' ) ).map( ( node ) => ( {
			node,
			start: parseFloat( node.dataset.start ),
			end: parseFloat( node.dataset.end ),
		} ) );

		root.addEventListener( 'click', ( event ) => {
			const button = event.target.closest( '.audio-transcriber__time' );
			if ( ! button ) {
				return;
			}
			media.currentTime = parseFloat( button.dataset.start );
			media.play();
		} );

		let current = null;
		media.addEventListener( 'timeupdate', () => {
			const time = media.currentTime;
			const active = segments.find( ( s ) => time >= s.start && time < s.end ) || null;
			if ( active === current ) {
				return;
			}
			if ( current ) {
				current.node.classList.remove( 'is-active' );
				current.node.removeAttribute( 'aria-current' );
			}
			if ( active ) {
				active.node.classList.add( 'is-active' );
				active.node.setAttribute( 'aria-current', 'true' );
				// Keep the active line visible inside the scrollable list, without scrolling the page.
				// The list is position: relative, so offsetTop is measured from the top of the list.
				const list = active.node.parentElement;
				const top = active.node.offsetTop;
				if ( top < list.scrollTop || top > list.scrollTop + list.clientHeight - active.node.offsetHeight ) {
					list.scrollTop = top;
				}
			}
			current = active;
		} );
	}

	function initAll() {
		document.querySelectorAll( '[data-audio-transcriber]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
