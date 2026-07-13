/* global ajaxurl, AinepayAdminOrder */
( function () {
	'use strict';

	var i18n = ( window.AinepayAdminOrder && AinepayAdminOrder.i18n ) || {};
	var btn  = document.getElementById( 'ainepay-cancel-order' );
	if ( ! btn ) {
		return;
	}
	btn.addEventListener( 'click', function () {
		if ( ! window.confirm( i18n.confirm || '' ) ) {
			return;
		}
		var out = document.getElementById( 'ainepay-cancel-result' );
		btn.disabled = true;
		out.textContent = i18n.working || '';
		var body = new URLSearchParams();
		body.append( 'action', 'ainepay_cancel_order' );
		body.append( 'order_id', btn.getAttribute( 'data-order' ) );
		body.append( 'nonce', btn.getAttribute( 'data-nonce' ) );
		fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				out.textContent = ( res && res.data && res.data.message ) ? res.data.message : ( i18n.done || '' );
				if ( res && res.data && res.data.reload ) {
					setTimeout( function () { window.location.reload(); }, 800 );
				} else {
					btn.disabled = false;
				}
			} )
			.catch( function () {
				out.textContent = i18n.failed || '';
				btn.disabled = false;
			} );
	} );
} )();
