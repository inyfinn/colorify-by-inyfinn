/**
 * Colorify: każdy komunikat w panelu da się zamknąć, a zamknięcie zostaje zapamiętane dla konta.
 *
 * Odcisk komunikatu = skrót jego tekstu (cyfry zastąpione, żeby liczniki typu "3 aktualizacje" nie tworzyły nowych
 * odcisków). Ta sama funkcja jest w colorify-modern.php (ukrywanie przed wyświetleniem), muszą zostać zgodne.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.colorifyNotices;
	if ( ! cfg ) {
		return;
	}
	var SEL = '.notice, .update-nag, div.updated, div.error';

	function key( el ) {
		var t = ( el.innerText || el.textContent || '' ).replace( /\d+/g, '#' ).replace( /\s+/g, ' ' ).trim().slice( 0, 240 );
		var x = 5381;
		for ( var i = 0; i < t.length; i++ ) {
			x = ( ( x << 5 ) + x + t.charCodeAt( i ) ) >>> 0;
		}
		var y = 52711;
		for ( var j = t.length - 1; j >= 0; j-- ) {
			y = ( ( y << 5 ) + y + t.charCodeAt( j ) ) >>> 0;
		}
		return ( '00000000' + x.toString( 16 ) ).slice( -8 ) + ( '0000' + ( y & 65535 ).toString( 16 ) ).slice( -4 );
	}

	function save( k ) {
		var body = new URLSearchParams();
		body.set( 'action', 'colorify_dismiss_notice' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'key', k );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {} );
	}

	// Komunikaty, które nie należą do strony panelu (np. wewnątrz formularzy wtyczek), zostawiamy w spokoju.
	function eligible( el ) {
		if ( el.closest( '#colorify-match-result, .colorify-js-notice, .inline, .notice-alt, .acf-notice, form .notice-error' ) ) {
			return false;
		}
		return !! el.closest( '#wpbody-content' ) && ( el.innerText || '' ).trim().length > 0;
	}

	function close( el ) {
		var k = el.dataset.colorifyKey || key( el );
		el.style.transition = 'opacity .2s ease';
		el.style.opacity = '0';
		setTimeout( function () {
			el.style.display = 'none';
		}, 200 );
		save( k );
	}

	function decorate( el ) {
		if ( el.dataset.colorifyDecorated || ! eligible( el ) ) {
			return;
		}
		el.dataset.colorifyDecorated = '1';
		el.dataset.colorifyKey = el.dataset.colorifyKey || key( el );
		var own = el.querySelector( ':scope > .notice-dismiss' );
		if ( own ) {
			// Zamknięcie rdzenia WordPressa niczego nie zapamiętuje: dokładamy zapis.
			own.addEventListener( 'click', function () {
				save( el.dataset.colorifyKey );
			} );
			return;
		}
		el.classList.add( 'colorify-closable' );
		var b = d.createElement( 'button' );
		b.type = 'button';
		b.className = 'colorify-notice-close';
		b.setAttribute( 'aria-label', cfg.label );
		b.title = cfg.label;
		b.innerHTML = '<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
		b.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			close( el );
		} );
		el.appendChild( b );
	}

	function scan() {
		d.querySelectorAll( SEL ).forEach( decorate );
	}

	scan();
	// WordPress przenosi komunikaty pod nagłówek i dokleja .notice-dismiss po załadowaniu: drugi przebieg.
	setTimeout( scan, 600 );
	setTimeout( scan, 2000 );

	// Ustawienia, Colorify: "Pokaż ukryte komunikaty".
	var reset = d.getElementById( 'colorify-notices-reset' );
	if ( reset ) {
		reset.addEventListener( 'click', function () {
			var body = new URLSearchParams();
			body.set( 'action', 'colorify_dismiss_notice' );
			body.set( 'nonce', cfg.nonce );
			body.set( 'reset', '1' );
			reset.disabled = true;
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function () {
				w.location.reload();
			} );
		} );
	}
} )( window, document );
