/* global wc, wp */
( function () {
	'use strict';

	var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
	var settings = ( wc.wcSettings && wc.wcSettings.getSetting )
		? wc.wcSettings.getSetting( 'ainepay_data', {} )
		: {};
	var createElement = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var decodeEntities = wp.htmlEntities.decodeEntities;
	var __ = wp.i18n.__;

	var label = decodeEntities( settings.title || __( 'AinePay', 'ainepay-for-woocommerce' ) );
	var coins = settings.coins || [];

	/**
	 * Content shown when the method is selected: description + coin selector.
	 * Pushes the chosen coin into checkout via setExtensionData / paymentMethodData.
	 */
	function Content( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var initial = coins.length ? coins[ 0 ].value : '';
		var state = useState( initial );
		var selected = state[ 0 ];
		var setSelected = state[ 1 ];

		var onCheckoutValidation = eventRegistration.onPaymentSetup;
		useEffect( function () {
			var unsubscribe = onCheckoutValidation( function () {
				if ( ! selected ) {
					return {
						type: emitResponse.responseTypes.ERROR,
						message: __( 'Please select a coin to pay with AinePay.', 'ainepay-for-woocommerce' )
					};
				}
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							ainepay_coin: selected
						}
					}
				};
			} );
			return unsubscribe;
		}, [ selected, onCheckoutValidation, emitResponse.responseTypes ] );

		var children = [];
		if ( settings.description ) {
			children.push(
				createElement( 'p', { key: 'desc' }, decodeEntities( settings.description ) )
			);
		}
		if ( coins.length ) {
			children.push(
				createElement(
					'select',
					{
						key: 'coin',
						className: 'ainepay-coin-select',
						value: selected,
						onChange: function ( e ) {
							setSelected( e.target.value );
						}
					},
					coins.map( function ( c ) {
						return createElement( 'option', { value: c.value, key: c.value }, decodeEntities( c.label ) );
					} )
				)
			);
		}
		return createElement( 'div', { className: 'ainepay-blocks-content' }, children );
	}

	function Label() {
		return createElement( 'span', null, label );
	}

	registerPaymentMethod( {
		name: 'ainepay',
		label: createElement( Label, null ),
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return coins.length > 0;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ]
		}
	} );
} )();
