/* Kancelaria Wieczorek: lista uslug i lista kategorii. Tylko ulepszenia: bez JavaScriptu wszystko jest widoczne i dziala. */
( function ( $ ) {
	'use strict';

	var telefon = window.matchMedia ? window.matchMedia( '(max-width: 782px)' ) : { matches: false };

	/* ---------- lista uslug: na telefonie szukanie i dzialania masowe pod jednym przyciskiem ---------- */
	var formularz = document.getElementById( 'posts-filter' );
	if ( formularz && document.body.classList.contains( 'post-type-kw_usluga' ) && formularz.querySelector( '.search-box' ) ) {
		var szukaj = document.getElementById( 'post-search-input' );
		var aktywny = !! ( szukaj && szukaj.value );
		var przycisk = document.createElement( 'button' );
		przycisk.type = 'button';
		przycisk.className = 'button kw-filtry-przycisk';
		przycisk.setAttribute( 'aria-expanded', 'false' );
		var ustaw = function ( otwarte ) {
			formularz.classList.toggle( 'kw-filtry-zwiniete', ! otwarte );
			przycisk.setAttribute( 'aria-expanded', otwarte ? 'true' : 'false' );
			przycisk.textContent = ( otwarte ? 'Ukryj szukanie i działania masowe' : 'Szukaj i działania masowe' ) + ( aktywny && ! otwarte ? ' (włączone)' : '' );
		};
		przycisk.addEventListener( 'click', function () {
			ustaw( 'true' !== przycisk.getAttribute( 'aria-expanded' ) );
		} );
		formularz.insertBefore( przycisk, formularz.firstChild );
		ustaw( aktywny );
		// Zaznaczenie usluge pod dzialanie masowe otwiera pola, zeby bylo je widac.
		formularz.addEventListener( 'change', function ( zdarzenie ) {
			var cel = zdarzenie.target;
			if ( cel && 'checkbox' === cel.type && 'true' !== przycisk.getAttribute( 'aria-expanded' ) && cel.checked ) {
				ustaw( true );
			}
		} );
	}

	/* ---------- lista uslug: przeciaganie wierszy w obrebie zakladki cennika (strzalki zostaja jako druga droga) ---------- */
	var lista = $( '#the-list' );
	var dane = window.kwListaDane;
	if ( lista.length && dane && $.fn.sortable && lista.find( '.kw-uchwyt' ).length ) {
		var zakladka = function ( tr ) {
			var m = ( tr.className || '' ).match( /\bkw-zakladka-(\d+)\b/ );
			return m ? 'kw-zakladka-' + m[ 1 ] : '';
		};
		var odswiezGrupe = function ( klasa ) {
			var wiersze = lista.children( 'tr.' + klasa );
			wiersze.each( function ( i ) {
				$( this ).find( '.kw-strzalka' ).eq( 0 ).prop( 'disabled', i === 0 );
				$( this ).find( '.kw-strzalka' ).eq( 1 ).prop( 'disabled', i === wiersze.length - 1 );
			} );
			// kreska nad zakladka: zawsze nad pierwszym wierszem grupy (poza pierwszym wierszem listy)
			wiersze.removeClass( 'kw-grupa-start' );
			if ( wiersze.first().index() > 0 ) {
				wiersze.first().addClass( 'kw-grupa-start' );
			}
		if ( window.kwListaPasy ) {
				window.kwListaPasy();
			}
		};
		var komunikat = function ( tekst, blad ) {
			var n = $( '<div class="notice is-dismissible"><p></p></div>' ).addClass( blad ? 'notice-error' : 'notice-success' );
			n.find( 'p' ).text( tekst );
			$( '.kw-lista-zapis' ).remove();
			n.addClass( 'kw-lista-zapis' ).insertBefore( '#posts-filter' );
			if ( window.wp && wp.a11y ) {
				wp.a11y.speak( tekst );
			}
		};
		var start = null;
		lista.on( 'mousedown', '.kw-uchwyt', function () {
			var klasa = zakladka( $( this ).closest( 'tr' )[ 0 ] );
			lista.sortable( 'option', 'items', '> tr.' + klasa ).sortable( 'refresh' );
		} );
		lista.sortable( {
			items: '> tr.kw-brak-zakladki',
			handle: '.kw-uchwyt',
			axis: 'y',
			placeholder: 'kw-miejsce',
			forcePlaceholderSize: true,
			tolerance: 'pointer',
			helper: function ( e, tr ) {
				// komorki zachowuja szerokosc podczas przeciagania
				tr.children().each( function () {
					$( this ).width( $( this ).width() );
				} );
				return tr;
			},
			start: function ( e, ui ) {
				start = lista.children( 'tr' ).toArray(); // porzadek sprzed przeciagniecia, do cofniecia po bledzie zapisu
				ui.placeholder.html( '<td colspan="' + ui.item.children().length + '"></td>' );
			},
			stop: function ( e, ui ) {
				ui.item.children().css( 'width', '' );
			},
			update: function ( e, ui ) {
				var tr = ui.item;
				var klasa = zakladka( tr[ 0 ] );
				var ids = lista.children( 'tr.' + klasa ).map( function () {
					return parseInt( this.id.replace( 'post-', '' ), 10 );
				} ).get();
				var nazwa = $.trim( tr.find( '.row-title' ).first().text() );
				var cofnij = function () {
					lista.append( start );
					odswiezGrupe( klasa );
					komunikat( 'Nie udało się zapisać kolejności. Odśwież stronę i spróbuj jeszcze raz.', true );
				};
				odswiezGrupe( klasa );
				$.post( dane.ajax, { action: 'kw_uslugi_kolejnosc', token: dane.token, ids: ids } )
					.done( function ( odp ) {
						if ( odp && odp.success ) {
							tr.addClass( 'kw-zapisano' );
							setTimeout( function () {
								tr.removeClass( 'kw-zapisano' );
							}, 1500 );
							komunikat( 'Zapisano kolejność: „' + nazwa + '” stoi teraz na miejscu ' + ( ids.indexOf( parseInt( tr[ 0 ].id.replace( 'post-', '' ), 10 ) ) + 1 ) + ' w swojej zakładce cennika.' );
						} else {
							cofnij();
						}
					} )
					.fail( cofnij );
			}
		} );
	}

	/* ---------- lista kategorii ---------- */
	// Rdzen ustawia kursor w polu "Nazwa" formularza dodawania. Na telefonie tabela jest nad formularzem:
	// bez tego strona skoczylaby na dol i wysunela klawiature.
	if ( document.getElementById( 'tag-name' ) && telefon.matches ) {
		if ( document.activeElement && 'function' === typeof document.activeElement.blur ) {
			document.activeElement.blur();
		}
		window.scrollTo( 0, 0 );
	}
	// Dodanie kategorii bez przeladowania strony zostawia w polu "Kolejnosc zakladki" ten sam numer:
	// podpowiadamy kolejna dziesiatke i odznaczamy "Archiwum".
	$( document ).ajaxSuccess( function ( zdarzenie, xhr, opcje ) {
		if ( ! opcje || 'string' !== typeof opcje.data || -1 === opcje.data.indexOf( 'action=add-tag' ) ) {
			return;
		}
		var pole = document.getElementById( 'kw-kat-kolejnosc' );
		var archiwum = document.getElementById( 'kw-kat-ukryta' );
		if ( pole && '' !== pole.value ) {
			pole.value = String( ( parseInt( pole.value, 10 ) || 0 ) + 10 );
		}
		if ( archiwum ) {
			archiwum.checked = false;
		}
	} );
}( window.jQuery ) );

/* Sekcje zakladek: podpisany naglowek nad kazda zakladka i pasy (co drugi wiersz ciemniejszy) w jej obrebie. */
( function () {
	var lista = document.getElementById( 'the-list' );
	if ( ! lista || ! lista.querySelector( 'tr[class*="kw-zakladka-"]' ) ) {
		return;
	}
	var kolumny = document.querySelectorAll( '.wp-list-table thead tr > *' ).length || 7;
	var grupa = function ( tr ) {
		var m = ( tr.className || '' ).match( /kw-zakladka-(\d+)/ );
		return m ? m[ 1 ] : '';
	};
	var poprzednia = null;
	Array.prototype.slice.call( lista.children ).forEach( function ( tr ) {
		var g = grupa( tr );
		if ( ! g || g === poprzednia ) {
			return;
		}
		poprzednia = g;
		var kat = tr.querySelector( 'td.column-kw_kategoria' );
		var nazwa = kat ? kat.textContent.trim() : '';
		if ( ! nazwa ) {
			return;
		}
		var n = document.createElement( 'tr' );
		n.className = 'kw-sekcja' + ( lista.querySelector( 'tr.kw-sekcja' ) ? '' : ' kw-sekcja--pierwsza' );
		var td = document.createElement( 'td' );
		td.colSpan = kolumny;
		var h = document.createElement( 'h2' );
		h.className = 'kw-sekcja__tytul';
		h.textContent = nazwa;
		td.appendChild( h );
		n.appendChild( td );
		lista.insertBefore( n, tr );
	} );
	window.kwListaPasy = function () {
		var i = 0;
		var wiersze = Array.prototype.slice.call( lista.children );
		wiersze.forEach( function ( tr, k ) {
			if ( tr.classList.contains( 'kw-sekcja' ) ) {
				i = 0;
				return;
			}
			var nast = wiersze[ k + 1 ];
			tr.classList.toggle( 'kw-pas', i % 2 === 1 );
			tr.classList.toggle( 'kw-pierwszy', i === 0 ); // zaokraglone rogi karty sekcji
			tr.classList.toggle( 'kw-ostatni', ! nast || nast.classList.contains( 'kw-sekcja' ) );
			i++;
		} );
	};
	window.kwListaPasy();
}() );
