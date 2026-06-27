/* global AinepayPay, jQuery */
( function ( $ ) {
	'use strict';

	function init() {
		var $root = $( '.ainepay-instructions' );
		if ( ! $root.length ) {
			return;
		}

		var expires = parseInt( $root.data( 'expires' ), 10 ) || 0;
		var isFinal = '1' === String( $root.data( 'final' ) );

		// The QR code is rendered server-side as inline SVG; JS only handles
		// copy, countdown, status polling and customer cancellation.
		bindCopy( $root );
		bindCancel( $root );

		if ( ! isFinal ) {
			startCountdown( $root, expires );
			startPolling( $root );
		}
	}

	function bindCancel( $root ) {
		$root.on( 'click', '.ainepay-cancel', function () {
			var confirmMsg = AinepayPay.i18n.cancelConfirm;
			if ( confirmMsg && ! window.confirm( confirmMsg ) ) {
				return;
			}
			var $btn = $( this );
			var $out = $root.find( '.ainepay-cancel-result' );
			$btn.prop( 'disabled', true );
			$out.text( AinepayPay.i18n.cancelWorking || '' );

			$.post( AinepayPay.ajaxUrl, {
				action: AinepayPay.cancelAction,
				nonce: AinepayPay.cancelNonce,
				order_id: $root.data( 'order-id' ),
				key: $root.data( 'order-key' )
			} ).done( function ( res ) {
				var msg = ( res && res.data && res.data.message ) || '';
				$out.text( msg );
				if ( res && res.success && res.data && res.data.reload ) {
					setTimeout( function () {
						window.location.reload();
					}, 1400 );
					return;
				}
				$btn.prop( 'disabled', false );
			} ).fail( function ( xhr ) {
				var msg = ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message )
					|| AinepayPay.i18n.cancelError || '';
				$out.text( msg );
				$btn.prop( 'disabled', false );
			} );
		} );
	}

	function bindCopy( $root ) {
		$root.on( 'click', '.ainepay-copy', function () {
			var text = $( this ).data( 'clipboard' );
			var $btn = $( this );
			var done = function () {
				var original = $btn.text();
				$btn.text( AinepayPay.i18n.copied );
				setTimeout( function () {
					$btn.text( original );
				}, 1500 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, done );
			} else {
				done();
			}
		} );
	}

	function startCountdown( $root, expiresMs ) {
		if ( ! expiresMs ) {
			return;
		}
		var $val = $root.find( '.ainepay-countdown-value' );
		var tick = function () {
			var remaining = expiresMs - Date.now();
			if ( remaining <= 0 ) {
				$val.text( '0:00' );
				$root.find( '.ainepay-status' ).text( AinepayPay.i18n.expired );
				// Window elapsed: hide the address so no further payment is made
				// to a stale window, even before the backend marks it expired.
				reachFinalState( $root, 'expired' );
				clearInterval( timer );
				return;
			}
			var total = Math.floor( remaining / 1000 );
			var m = Math.floor( total / 60 );
			var s = total % 60;
			$val.text( m + ':' + ( s < 10 ? '0' + s : s ) );
		};
		var timer = setInterval( tick, 1000 );
		tick();
	}

	// Reflect a final state immediately, before the page reloads: hide the
	// address/QR/countdown so a paid or expired order never shows a payable
	// address, and switch the status badge to match.
	function reachFinalState( $root, state ) {
		$root.find( '.ainepay-amount, .ainepay-qr, .ainepay-address-row, .ainepay-countdown' ).hide();
		$root
			.removeClass( 'ainepay-state-awaiting ainepay-state-paid ainepay-state-expired' )
			.addClass( 'ainepay-state-' + state );
		var $badge = $root.find( '.ainepay-badge' );
		$badge.removeClass( 'ainepay-badge-awaiting ainepay-badge-paid ainepay-badge-expired' )
			.addClass( 'ainepay-badge-' + state );
		if ( 'paid' === state && AinepayPay.i18n.badgePaid ) {
			$badge.text( AinepayPay.i18n.badgePaid );
		} else if ( 'expired' === state && AinepayPay.i18n.badgeExpired ) {
			$badge.text( AinepayPay.i18n.badgeExpired );
		}
	}

	function startPolling( $root ) {
		var interval = parseInt( AinepayPay.interval, 10 ) || 15000;
		var initialState = String( $root.data( 'state' ) );
		var poll = function () {
			$.post( AinepayPay.ajaxUrl, {
				action: AinepayPay.action,
				nonce: AinepayPay.nonce,
				order_id: $root.data( 'order-id' ),
				key: $root.data( 'order-key' )
			} ).done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					return;
				}
				// An unbacked "confirming" page must re-render as soon as the async
				// guard resolves it (to paid, or reverted back to awaiting), so reload
				// the moment the server state diverges from what was rendered.
				if ( 'verifying' === initialState && 'verifying' !== res.data.state ) {
					clearInterval( timer );
					window.location.reload();
					return;
				}
				if ( 'paid' === res.data.state ) {
					$root.find( '.ainepay-status' ).text( AinepayPay.i18n.paid );
					reachFinalState( $root, 'paid' );
				} else if ( 'expired' === res.data.state ) {
					$root.find( '.ainepay-status' ).text( AinepayPay.i18n.expired );
					reachFinalState( $root, 'expired' );
				}
				if ( res.data.final ) {
					clearInterval( timer );
					// Reload so the order details reflect the final state.
					setTimeout( function () {
						window.location.reload();
					}, 1200 );
				}
			} );
		};
		var timer = setInterval( poll, interval );
	}

	$( init );
} )( jQuery );
