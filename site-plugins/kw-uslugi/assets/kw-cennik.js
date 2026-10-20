/**
 * Cennik [kw_cennik] i kontrakt linkow strony.
 *
 * 1. Zakladki kategorii: role tablist / tab / tabpanel, strzalki w lewo i w prawo, Home, End, aria-selected.
 *    Bez tego skryptu widac pierwsza kategorie, a pozostale otwiera kotwica (CSS :target).
 * 2. #cennik-<slug kategorii> (link na stronie albo adres przy wejsciu): otwiera zakladke i przewija do #cennik.
 * 3. data-kw-usluga="<slug>" (na linku albo na jego opakowaniu) oraz #kontakt-usluga-<slug>:
 *    wybiera usluge w formularzu i przewija do #kontakt.
 *
 * Przewijanie: natywne scrollIntoView. Odstep na przyklejony naglowek daje motyw (html { scroll-padding-top }),
 * plynnosc tez (html { scroll-behavior }); przy prefers-reduced-motion przewijamy od razu.
 */
( function () {
	'use strict';

	var cenniki = [];
	var ruszyl = false; // uzytkownik sam zaczal przewijac: nie poprawiamy juz pozycji po zaladowaniu strony

	function przewin( el ) {
		if ( ! el ) {
			return;
		}
		var bezRuchu = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		try {
			el.scrollIntoView( bezRuchu ? { block: 'start', behavior: 'instant' } : { block: 'start' } );
		} catch ( e ) {
			el.scrollIntoView( true );
		}
	}

	function adres( hash, nowyWpis ) {
		try {
			window.history[ nowyWpis ? 'pushState' : 'replaceState' ]( null, '', hash );
		} catch ( e ) {}
	}

	/* ---------- zakladki ---------- */

	function initCennik( root ) {
		var lista = root.querySelector( '.kw-c__taby' );
		var taby = lista ? Array.prototype.slice.call( lista.querySelectorAll( '.kw-c__tab' ) ) : [];
		var c = { root: root, taby: taby, wybierz: wybierz };

		function panel( tab ) {
			return document.getElementById( tab.getAttribute( 'href' ).slice( 1 ) );
		}

		function wybierz( tab, fokus ) {
			taby.forEach( function ( t ) {
				var on = t === tab;
				t.classList.toggle( 'is-aktywna', on );
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
				panel( t ).classList.toggle( 'is-aktywna', on );
			} );
			if ( fokus ) {
				tab.focus();
			}
		}

		if ( ! taby.length ) {
			return c; // jedna kategoria: nie ma czego przelaczac
		}

		lista.setAttribute( 'role', 'tablist' );
		lista.setAttribute( 'aria-label', lista.getAttribute( 'data-etykieta' ) || '' );
		taby.forEach( function ( tab ) {
			var p = panel( tab );
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'aria-controls', p.id );
			p.setAttribute( 'role', 'tabpanel' );
			p.setAttribute( 'aria-labelledby', tab.id );
			p.tabIndex = 0;
		} );

		lista.addEventListener( 'keydown', function ( e ) {
			var i = taby.indexOf( document.activeElement );
			if ( i < 0 || e.altKey || e.ctrlKey || e.metaKey ) {
				return;
			}
			var cel = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: taby.length - 1 }[ e.key ];
			if ( e.key === ' ' || e.key === 'Spacebar' ) {
				cel = i; // odnosnik w roli zakladki: spacja dziala jak Enter
			}
			if ( cel === undefined ) {
				return;
			}
			e.preventDefault();
			var tab = taby[ ( cel + taby.length ) % taby.length ];
			wybierz( tab, true );
			adres( tab.getAttribute( 'href' ), false );
		} );

		// Stan poczatkowy: kategoria wskazana kotwica w adresie (pokazal ja juz CSS :target) albo pierwsza.
		var start = taby.filter( function ( t ) {
			return window.location.hash && t.getAttribute( 'href' ) === window.location.hash;
		} )[ 0 ] || root.querySelector( '.kw-c__tab.is-aktywna' ) || taby[ 0 ];
		root.classList.add( 'is-js' );
		wybierz( start );
		return c;
	}

	function znajdzZakladke( slug ) {
		for ( var i = 0; i < cenniki.length; i++ ) {
			for ( var j = 0; j < cenniki[ i ].taby.length; j++ ) {
				if ( cenniki[ i ].taby[ j ].getAttribute( 'data-kw-tab' ) === slug ) {
					return { c: cenniki[ i ], tab: cenniki[ i ].taby[ j ] };
				}
			}
		}
		return null;
	}

	function doKategorii( slug, bezPrzewijania ) {
		var z = znajdzZakladke( slug );
		if ( ! z ) {
			return false;
		}
		z.c.wybierz( z.tab );
		if ( ! bezPrzewijania ) {
			przewin( document.getElementById( 'cennik' ) || z.c.root );
		}
		return true;
	}

	/* ---------- usluga w formularzu ---------- */

	function doFormularza( slug ) {
		var formularz = document.querySelector( '[data-kw-formularz]' );
		var cel = document.getElementById( 'kontakt' ) || formularz;
		if ( ! cel ) {
			return false;
		}
		var pole = formularz ? formularz.querySelector( 'select[name="usluga"]' ) : null;
		var jest = pole && Array.prototype.some.call( pole.options, function ( o ) {
			return o.value === slug;
		} );
		if ( jest ) {
			pole.value = slug;
			pole.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		przewin( cel );
		if ( jest ) {
			try {
				pole.focus( { preventScroll: true } ); // widac, co zostalo wybrane; klawiatura zaczyna od tego pola
			} catch ( e ) {}
		}
		return true;
	}

	/* ---------- kotwice ---------- */

	function zKotwicy( hash ) {
		var h = ( hash || '' ).replace( /^#/, '' );
		try {
			h = decodeURIComponent( h );
		} catch ( e ) {}
		var m = /^kontakt-usluga-(.+)$/.exec( h );
		if ( m ) {
			return { usluga: m[ 1 ] };
		}
		m = /^cennik-(.+)$/.exec( h );
		return m ? { kategoria: m[ 1 ] } : null;
	}

	function zastosuj( cel ) {
		return cel.usluga ? doFormularza( cel.usluga ) : doKategorii( cel.kategoria );
	}

	function tenDokument( a ) {
		return a.host === window.location.host && a.pathname === window.location.pathname && a.search === window.location.search;
	}

	document.addEventListener( 'click', function ( e ) {
		if ( e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || ! e.target.closest ) {
			return;
		}
		var a = e.target.closest( 'a[href]' );
		if ( a && ! tenDokument( a ) ) {
			return; // odnosnik do innej strony: nie nasza sprawa
		}

		var nosnik = e.target.closest( '[data-kw-usluga]' );
		if ( nosnik ) {
			if ( doFormularza( nosnik.getAttribute( 'data-kw-usluga' ) ) ) {
				e.preventDefault();
				adres( '#kontakt', true );
			}
			return;
		}
		if ( ! a ) {
			return;
		}

		// Zakladka cennika: przelacza na miejscu, bez przewijania.
		if ( a.classList.contains( 'kw-c__tab' ) ) {
			for ( var i = 0; i < cenniki.length; i++ ) {
				if ( cenniki[ i ].taby.indexOf( a ) > -1 ) {
					e.preventDefault();
					cenniki[ i ].wybierz( a );
					adres( a.getAttribute( 'href' ), false );
					return;
				}
			}
			return;
		}

		var cel = zKotwicy( a.hash );
		if ( cel && zastosuj( cel ) ) {
			e.preventDefault();
			adres( cel.usluga ? '#kontakt' : a.hash, true );
		}
	} );

	// Kotwica zmieniona poza kliknieciem (wpisana w pasku adresu, Wstecz / Dalej). Przy Wstecz / Dalej przegladarka sama
	// przywraca miejsce, w ktorym uzytkownik byl: wtedy tylko otwieramy wlasciwa zakladke, bez przewijania.
	var cofanie = false;
	window.addEventListener( 'popstate', function () {
		cofanie = true;
		window.setTimeout( function () {
			cofanie = false;
		}, 0 );
	} );
	window.addEventListener( 'hashchange', function () {
		var cel = zKotwicy( window.location.hash );
		if ( cel && cofanie && cel.kategoria ) {
			doKategorii( cel.kategoria, true );
		} else if ( cel && ! cofanie ) {
			zastosuj( cel );
		}
	} );

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-kw-cennik]' ), function ( root ) {
			if ( ! root.kwGotowy ) {
				root.kwGotowy = true;
				cenniki.push( initCennik( root ) );
			}
		} );
		var cel = zKotwicy( window.location.hash );
		if ( ! cel || ! zastosuj( cel ) ) {
			return;
		}
		// Obrazy i czcionki doczytane po pierwszym przewinieciu przesuwaja uklad: po zaladowaniu strony poprawiamy pozycje,
		// chyba ze uzytkownik zdazyl juz sam przewinac.
		[ 'wheel', 'touchstart', 'keydown', 'mousedown' ].forEach( function ( typ ) {
			window.addEventListener( typ, function () {
				ruszyl = true;
			}, { passive: true, once: true } );
		} );
		if ( document.readyState !== 'complete' ) {
			window.addEventListener( 'load', function () {
				if ( ! ruszyl ) {
					zastosuj( cel );
				}
			}, { once: true } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
