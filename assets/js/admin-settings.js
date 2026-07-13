/* global ajaxurl */
( function () {
	'use strict';

	var btn = document.getElementById( 'ainepay-test-connection' );
	if ( ! btn ) {
		return;
	}
	btn.addEventListener( 'click', function () {
		var out = document.getElementById( 'ainepay-test-result' );
		out.textContent = '…';
		var data = new FormData();
		data.append( 'action', 'ainepay_test_connection' );
		data.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
		fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				out.textContent = ( res && res.data && res.data.message ) ? res.data.message : 'Error';
			} )
			.catch( function () { out.textContent = 'Error'; } );
	} );
} )();
