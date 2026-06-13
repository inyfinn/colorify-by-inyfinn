/**
 * Colorify admin — kompatybilność z pluginami zewnętrznymi (Elementor wp-pointer itd.)
 */
( function ( $ ) {
	'use strict';

	/* Elementor AI pointer: close() woła elementorCommon.ajax — na liście produktów często brak obiektu. */
	window.elementorCommon = window.elementorCommon || {
		ajax: {
			addRequest: function ( action, options ) {
				var deferred = $.Deferred();

				if (
					'introduction_viewed' === action &&
					typeof ajaxurl !== 'undefined' &&
					window.elementorCommonConfig &&
					window.elementorCommonConfig.ajax &&
					window.elementorCommonConfig.ajax.url
				) {
					$.post( window.elementorCommonConfig.ajax.url, {
						action: 'elementor_ajax',
						nonce: window.elementorCommonConfig.ajax.nonce,
						actions: JSON.stringify( {
							introduction_viewed: { data: ( options && options.data ) || {} },
						} ),
					} ).always( function () {
						deferred.resolve();
					} );
				} else {
					deferred.resolve();
				}

				return deferred.promise();
			},
		},
	};

	/* Fallback: jeśli callback close rzuci błąd, pointer zostaje widoczny mimo active=false. */
	$( document ).on( 'click', '.wp-pointer-buttons a.close', function () {
		var $pointer = $( this ).closest( '.wp-pointer' );

		window.setTimeout( function () {
			if ( $pointer.length && $pointer.is( ':visible' ) ) {
				$pointer.hide();
			}
		}, 50 );
	} );
}( window.jQuery ) );
