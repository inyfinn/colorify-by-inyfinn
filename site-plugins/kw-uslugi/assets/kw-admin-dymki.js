/**
 * Dymki z wyjasnieniami na ekranach uslug (przycisk "?" + tekst obok, znacznik z kw_uslugi_dymek() w PHP).
 * Najechanie albo fokus pokazuje dymek, klikniecie (takze dotkniecie) przypina go, Escape i klikniecie obok zamykaja.
 * Dymek ustawia sie nad przyciskiem (gdy brak miejsca: pod nim) i nie wychodzi poza okno. Bez tego skryptu dymek pokazuje sam arkusz (hover, fokus).
 * Tresc dymka drukuje serwer; skrypt niczego do niej nie dopisuje.
 */
( function () {
	'use strict';
	var przypiety = null;

	function tresc( d ) {
		return d.querySelector( '.kwa-dymek__tresc' );
	}
	// Kotwica dymka: przycisk "?" albo (odmiana kwa-dymek--otoczka) pierwszy element w srodku, np. przelacznik.
	function przycisk( d ) {
		return d.querySelector( '.kwa-dymek__btn' ) || d.firstElementChild;
	}
	function ustaw( d ) {
		var t = tresc( d );
		var r = przycisk( d ).getBoundingClientRect();
		var okno = document.documentElement.clientWidth;
		t.style.left = '0px';
		t.style.top = '0px';
		var szer = t.offsetWidth;
		var wys = t.offsetHeight;
		var lewo = Math.max( 8, Math.min( r.left + r.width / 2 - szer / 2, okno - szer - 8 ) );
		// Nad przyciskiem (pole, ktore dymek objasnia, stoi zwykle pod etykieta i zostaje odsloniete);
		// gdy u gory brak miejsca (pasek administratora ma 46 px na telefonie), pod przyciskiem.
		var gora = r.top - 8 - wys >= 54 ? r.top - 8 - wys : Math.min( r.bottom + 8, Math.max( 8, window.innerHeight - wys - 8 ) );
		t.style.left = Math.round( lewo ) + 'px';
		t.style.top = Math.round( gora ) + 'px';
	}
	function pokaz( d ) {
		if ( ! d.classList.contains( 'is-otwarty' ) ) {
			d.classList.add( 'is-otwarty' );
		}
		ustaw( d );
	}
	function schowaj( d ) {
		d.classList.remove( 'is-otwarty' );
		if ( d.querySelector( '.kwa-dymek__btn' ) ) {
			d.querySelector( '.kwa-dymek__btn' ).setAttribute( 'aria-expanded', 'false' );
		}
		if ( przypiety === d ) {
			przypiety = null;
		}
	}
	function dymek( el ) {
		return el && el.closest ? el.closest( '.kwa-dymek' ) : null;
	}

	document.documentElement.classList.add( 'kwa-js' );

	// Naglowki tabel WordPress drukuje dwa razy (gora i dol): ten sam dymek dostaje wtedy ten sam identyfikator.
	// Kazdy kolejny dostaje wlasny, razem z odwolaniem na swoim przycisku.
	var widziane = {};
	Array.prototype.forEach.call( document.querySelectorAll( '.kwa-dymek' ), function ( d ) {
		var t = tresc( d );
		if ( ! t || ! t.id ) {
			return;
		}
		if ( widziane[ t.id ] ) {
			var stary = t.id;
			var nowy = stary + '-' + ( ++widziane[ stary ] );
			t.id = nowy;
			Array.prototype.forEach.call( d.querySelectorAll( '[aria-describedby]' ), function ( x ) {
				x.setAttribute( 'aria-describedby', x.getAttribute( 'aria-describedby' ).split( ' ' ).map( function ( id ) {
					return id === stary ? nowy : id;
				} ).join( ' ' ) );
			} );
		} else {
			widziane[ t.id ] = 1;
		}
	} );

	document.addEventListener( 'mouseover', function ( ev ) {
		var d = dymek( ev.target );
		if ( d ) {
			pokaz( d );
		}
	} );
	document.addEventListener( 'mouseout', function ( ev ) {
		var d = dymek( ev.target );
		if ( d && d !== przypiety && ! d.contains( ev.relatedTarget ) && ! d.contains( document.activeElement ) ) {
			schowaj( d );
		}
	} );
	document.addEventListener( 'focusin', function ( ev ) {
		var d = dymek( ev.target );
		if ( d ) {
			pokaz( d );
		}
	} );
	document.addEventListener( 'focusout', function ( ev ) {
		var d = dymek( ev.target );
		if ( d && d !== przypiety && ! d.contains( ev.relatedTarget ) ) {
			schowaj( d );
		}
	} );
	document.addEventListener( 'click', function ( ev ) {
		var b = ev.target.closest ? ev.target.closest( '.kwa-dymek__btn' ) : null;
		if ( b ) {
			// Przycisk "?" nigdy nie wysyla formularza ani nie przelacza pola obok.
			ev.preventDefault();
			ev.stopPropagation();
			var d = dymek( b );
			if ( przypiety === d ) {
				schowaj( d );
			} else {
				if ( przypiety ) {
					schowaj( przypiety );
				}
				przypiety = d;
				b.setAttribute( 'aria-expanded', 'true' );
				pokaz( d );
			}
			return;
		}
		if ( przypiety && ! przypiety.contains( ev.target ) ) {
			schowaj( przypiety );
		}
	}, true );
	document.addEventListener( 'keydown', function ( ev ) {
		if ( ev.key === 'Escape' ) {
			Array.prototype.forEach.call( document.querySelectorAll( '.kwa-dymek.is-otwarty' ), schowaj );
		}
	} );
	function przestaw() {
		Array.prototype.forEach.call( document.querySelectorAll( '.kwa-dymek.is-otwarty' ), ustaw );
	}
	window.addEventListener( 'scroll', przestaw, true );
	window.addEventListener( 'resize', przestaw );
} )();
