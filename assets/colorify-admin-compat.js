/**
 * Colorify admin — global JS compat (pointers, modals, dismiss patterns).
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

	/**
	 * Force-hide overlay after dismiss click if plugin callback throws.
	 *
	 * @param {jQuery} $el Overlay root.
	 */
	function forceHideOverlay( $el ) {
		if ( ! $el || ! $el.length ) {
			return;
		}
		window.setTimeout( function () {
			if ( $el.is( ':visible' ) ) {
				$el.hide();
				$el.attr( 'aria-hidden', 'true' );
			}
		}, 50 );
	}

	/* WP Pointer — generic close link */
	$( document ).on( 'click', '.wp-pointer-buttons a.close', function () {
		forceHideOverlay( $( this ).closest( '.wp-pointer' ) );
	} );

	/* WooCommerce / WP admin notice dismiss (X button) */
	$( document ).on( 'click', '.notice-dismiss, .jitm-dismiss, .woocommerce-message .notice-dismiss', function () {
		forceHideOverlay( $( this ).closest( '.notice, .jitm-banner, .woocommerce-message, .updated, div.error' ) );
	} );

	/* Thickbox close */
	$( document ).on( 'click', '#TB_closeWindowButton, #TB_ImageOff', function () {
		forceHideOverlay( $( '#TB_window' ) );
		$( '#TB_overlay' ).hide();
	} );

	/* Gutenberg / components modal close */
	$( document ).on( 'click', '.components-modal__header button, .components-modal__content button[aria-label="Close"]', function () {
		forceHideOverlay( $( this ).closest( '.components-modal__frame, .components-modal__screen-overlay' ) );
	} );

	/* Escape key — hide stuck wp-pointer */
	$( document ).on( 'keydown', function ( e ) {
		if ( 27 !== e.which ) {
			return;
		}
		$( '.wp-pointer:visible' ).each( function () {
			forceHideOverlay( $( this ) );
		} );
	} );

	/* Redux framework — stuck promo banners without working dismiss */
	$( document ).on( 'click', '.redux-notice-dismiss, .rAds-close, .redux-qtip-close', function () {
		forceHideOverlay( $( this ).closest( '.redux-notice, .rAds, .redux-container .notice' ) );
	} );
}( window.jQuery ) );
