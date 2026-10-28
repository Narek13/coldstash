/**
 * Coldstash admin.
 *
 * The screens are server-rendered; this asks before "Restore all" and refreshes
 * the page while an archive or restore run is in flight, so the merchant does
 * not have to reload to see progress.
 */
(function () {
	'use strict';

	document.querySelectorAll( '[data-coldstash-confirm]' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			if ( ! window.confirm( link.getAttribute( 'data-coldstash-confirm' ) ) ) {
				event.preventDefault();
			}
		} );
	} );

	if ( ! document.querySelector( '.coldstash__badge--running' ) ) {
		return;
	}

	// Batches run back to back or about once a minute; reload often enough to
	// show progress without hammering the server.
	window.setTimeout( function () {
		window.location.assign( window.location.href.replace( /[?&](started|stopped|saved)=1/g, '' ) );
	}, 20000 );
})();
