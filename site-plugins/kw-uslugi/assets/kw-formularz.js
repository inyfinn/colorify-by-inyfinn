/**
 * Formularz kontaktowy [kw_formularz].
 * Stany: bezczynny, wysylanie, sukces, blad walidacji, AWARIA (panel nad formularzem: schowek + mailto).
 * Zasada trybu awaryjnego: nigdy nie twierdzimy, ze tresc jest skopiowana, dopoki zapis do schowka sie nie powiodl.
 */
( function () {
	'use strict';

	var LIMIT_CZASU = 15000; // ms: brak odpowiedzi serwera = awaria
	var MIN_CZAS = 3200;     // ms: serwer odrzuca wysylke szybsza niz 3 s od wydania tokenu
	var MAILTO_TRESC = 1800; // znaki: limit tresci w odnosniku mailto
	var MAILTO_ADRES = 2000; // znaki: limit calego odnosnika (starsze programy pocztowe ucinaja dluzsze)
	var POLA = [ 'dokumenty', 'pracownicy', 'imie', 'email', 'telefon', 'wiadomosc' ]; // pola z komunikatem bledu, w kolejnosci na stronie
	var WYCENA = [ 'dokumenty', 'pracownicy' ]; // pola wyceny: listy wyboru, opcjonalne

	function init( root ) {
		var form = root.querySelector( '.kw-f__form' );
		var pola = root.querySelector( '.kw-f__pola' );
		var btn = root.querySelector( '.kw-f__wyslij' );
		var btnTxt = btn.querySelector( '.kw-f__btn-txt' );
		var status = root.querySelector( '[data-kw-status]' );
		var panel = root.querySelector( '.kw-f__awaria' );
		var panelH = panel.querySelector( '.kw-f__awaria-h' );
		var mailtoLink = panel.querySelector( '[data-kw-mailto]' );
		var kopiujBtn = panel.querySelector( '[data-kw-kopiuj]' );
		var ponowBtn = panel.querySelector( '[data-kw-ponow]' );
		var kopiaInfo = panel.querySelector( '[data-kw-kopia-info]' );
		var kopiaPole = panel.querySelector( '[data-kw-tresc]' );
		var sukcesEl = root.querySelector( '.kw-f__sukces' );
		var cfg = root.dataset;
		var parametry = new URLSearchParams( window.location.search );
		var bezRuchu = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var stan = {
			wysylanie: false,
			ponowiono: false,
			dane: null,
			tresc: '',
			token: { nonce: cfg.nonce, t: cfg.t, s: cfg.s }
		};

		// Wstepny wybor uslugi z adresu: ?usluga=<slug>. Linki na stronie (data-kw-usluga, #kontakt-usluga-<slug>) obsluguje kw-cennik.js.
		var chciana = parametry.get( 'usluga' );
		if ( chciana ) {
			Array.prototype.some.call( form.elements.usluga.options, function ( o ) {
				if ( o.value === chciana ) {
					form.elements.usluga.value = chciana;
					return true;
				}
				return false;
			} );
		}

		/* ---------- dane i walidacja ---------- */

		function dane() {
			var wybor = form.elements.usluga;
			var opcja = wybor.options[ wybor.selectedIndex ];
			return {
				imie: form.elements.imie.value.trim(),
				email: form.elements.email.value.trim(),
				telefon: form.elements.telefon.value.trim(),
				usluga: wybor.value,
				uslugaNazwa: wybor.value && opcja ? opcja.textContent.trim() : cfg.ogolne,
				dokumenty: form.elements.dokumenty.value,
				pracownicy: form.elements.pracownicy.value,
				wiadomosc: form.elements.wiadomosc.value.trim()
			};
		}

		// Wiersz "Etykieta: wybrana wartosc" pola wyceny, taki sam jak w e-mailu z serwera.
		function wierszWyceny( nazwa ) {
			var el = form.elements[ nazwa ];
			var opcja = el.options[ el.selectedIndex ];
			var etykieta = root.querySelector( 'label[for="' + el.id + '"]' );
			return ( etykieta ? etykieta.textContent.trim() : nazwa ) + ': ' + ( el.value && opcja ? opcja.textContent.trim() : 'nie podano' );
		}

		function waliduj( d ) {
			var b = {};
			if ( ! d.imie ) {
				b.imie = 'Podaj imię.';
			}
			if ( ! d.email ) {
				b.email = 'Podaj adres e-mail.';
			} else if ( d.email.length > 254 || ! /^[^\s@,;<>"%?&=]+@[^\s@,;<>"%?&=.]+(\.[^\s@,;<>"%?&=.]+)+$/.test( d.email ) ) {
				b.email = 'Sprawdź adres e-mail, np. imie@domena.pl.';
			}
			if ( d.telefon && ( ! /^[0-9+()\-. ]+$/.test( d.telefon ) || d.telefon.replace( /\D/g, '' ).length < 7 ) ) {
				b.telefon = 'Sprawdź numer telefonu, np. 600 100 200.';
			}
			if ( ! d.wiadomosc ) {
				b.wiadomosc = 'Napisz wiadomość.';
			} else if ( d.wiadomosc.length < 10 ) {
				b.wiadomosc = 'Wiadomość jest za krótka: napisz co najmniej 10 znaków.';
			} else if ( d.wiadomosc.length > 5000 ) {
				b.wiadomosc = 'Wiadomość jest za długa (maksymalnie 5000 znaków).';
			}
			return b;
		}

		// Pokazuje komunikaty przy polach; zwraca pierwsze bledne pole albo null.
		function pokazBledy( b ) {
			var pierwsze = null;
			POLA.forEach( function ( nazwa ) {
				var el = form.elements[ nazwa ];
				var p = root.querySelector( '#' + el.id + '-blad' );
				var tekst = b && typeof b[ nazwa ] === 'string' ? b[ nazwa ] : '';
				p.textContent = tekst;
				p.hidden = ! tekst;
				if ( tekst ) {
					el.setAttribute( 'aria-invalid', 'true' );
					pierwsze = pierwsze || el;
				} else {
					el.removeAttribute( 'aria-invalid' );
				}
			} );
			return pierwsze;
		}

		function tekstWiadomosci( d ) {
			return [
				'Imię: ' + d.imie,
				'E-mail: ' + d.email,
				'Telefon: ' + ( d.telefon || 'nie podano' ),
				'Usługa: ' + d.uslugaNazwa
			].concat( WYCENA.map( wierszWyceny ), [
				'',
				'Wiadomość:',
				d.wiadomosc
			] ).join( '\n' );
		}

		/* ---------- mailto ---------- */

		// Ucina tekst bez rozrywania pary zastepczej (emoji), bo encodeURIComponent rzucilby wyjatek.
		function utnij( tekst, n ) {
			var t = tekst.slice( 0, n );
			var kod = t.charCodeAt( t.length - 1 );
			return kod >= 0xD800 && kod <= 0xDBFF ? t.slice( 0, -1 ) : t;
		}

		function mailto( d, tresc ) {
			var adres = encodeURIComponent( cfg.email ).replace( /%40/g, '@' );
			var temat = encodeURIComponent( cfg.temat + ': ' + d.uslugaNazwa );
			var dopisek = '\n\n[Wiadomość została skrócona. Pełną treść skopiuj z formularza na stronie.]';
			var skrocona = tresc.length > MAILTO_TRESC;
			var body = skrocona ? utnij( tresc, MAILTO_TRESC ) : tresc;
			function href() {
				return 'mailto:' + adres + '?subject=' + temat + '&body=' +
					encodeURIComponent( ( body + ( skrocona ? dopisek : '' ) ).replace( /\r?\n/g, '\r\n' ) );
			}
			var wynik = href();
			while ( wynik.length > MAILTO_ADRES && body.length > 100 ) {
				body = utnij( body, body.length - 50 );
				skrocona = true;
				wynik = href();
			}
			return wynik;
		}

		/* ---------- schowek ---------- */

		function ustawKopie( skopiowano ) {
			panel.classList.toggle( 'is-skopiowano', skopiowano );
			Array.prototype.forEach.call( panel.querySelectorAll( '[data-kw-kopia="tak"]' ), function ( el ) {
				el.hidden = ! skopiowano;
			} );
			kopiujBtn.textContent = skopiowano ? kopiujBtn.dataset.tekstTak : kopiujBtn.dataset.tekstNie;
		}

		// Zapis nowym API; zwraca Promise<boolean>. Bez aktywacji uzytkownika przegladarka moze odmowic.
		function schowekApi( tekst ) {
			if ( ! ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) ) {
				return Promise.resolve( false );
			}
			return navigator.clipboard.writeText( tekst ).then( function () {
				return true;
			}, function () {
				return false;
			} );
		}

		// Zapas dla klikniecia: zaznaczone pole z trescia + polecenie kopiowania.
		function schowekZPola() {
			try {
				kopiaPole.focus();
				kopiaPole.select();
				kopiaPole.setSelectionRange( 0, kopiaPole.value.length );
				return document.execCommand( 'copy' );
			} catch ( e ) {
				return false;
			}
		}

		kopiujBtn.addEventListener( 'click', function () {
			schowekApi( stan.tresc ).then( function ( ok ) {
				ok = ok || schowekZPola();
				ustawKopie( ok );
				if ( ok ) {
					kopiaInfo.textContent = 'Treść wiadomości jest już skopiowana, możesz ją wkleić.';
					kopiujBtn.focus();
				} else {
					kopiaInfo.textContent = 'Nie udało się skopiować automatycznie. Zaznacz treść poniżej i skopiuj ją ręcznie.';
					kopiaPole.focus();
					kopiaPole.select();
				}
			} );
		} );

		/* ---------- stany ---------- */

		function fokus( el ) {
			if ( ! el ) {
				return;
			}
			try {
				el.focus( { preventScroll: true } );
			} catch ( e ) {
				el.focus();
			}
			var r = el.getBoundingClientRect();
			if ( r.top < 90 || r.bottom > window.innerHeight - 20 ) {
				el.scrollIntoView( { block: 'center', behavior: bezRuchu ? 'auto' : 'smooth' } );
			}
		}

		function blokuj( tak ) {
			stan.wysylanie = tak;
			if ( tak ) {
				btn.focus(); // fokus zostaje na przycisku, zanim pola zostana zablokowane
			}
			pola.disabled = tak;
			form.setAttribute( 'aria-busy', tak ? 'true' : 'false' );
			btn.classList.toggle( 'is-wysylanie', tak );
			if ( tak ) {
				btn.setAttribute( 'aria-disabled', 'true' );
			} else {
				btn.removeAttribute( 'aria-disabled' );
			}
			btnTxt.textContent = tak ? btn.dataset.tekstWysylanie : btn.dataset.tekst;
			status.textContent = tak ? 'Wysyłanie wiadomości.' : '';
		}

		function ukryjAwarie() {
			panel.hidden = true;
			panel.classList.remove( 'is-widoczny' );
			kopiaInfo.textContent = '';
		}

		function pokazSukces() {
			// Panel podziekowania dostaje staly obszar (najwyzej 360 px): wysokosc zmienia sie raz, w chwili gdy formularz jest juz niewidoczny.
			root.style.minHeight = Math.min( root.offsetHeight, 360 ) + 'px';
			form.classList.add( 'is-znika' );
			window.setTimeout( function () {
				blokuj( false );
				form.hidden = true;
				form.classList.remove( 'is-znika' );
				form.reset();
				pokazBledy( {} );
				ukryjAwarie();
				sukcesEl.hidden = false;
				sukcesEl.classList.add( 'is-widoczny' );
				fokus( sukcesEl.querySelector( '.kw-f__sukces-h' ) );
			}, bezRuchu ? 0 : 200 );
		}

		function pokazAwarie() {
			blokuj( false );
			odswiezKopie();
			panel.hidden = false;
			panel.classList.remove( 'is-widoczny' );
			void panel.offsetWidth; // ponowne uruchomienie animacji przy kolejnej awarii
			panel.classList.add( 'is-widoczny' );
			fokus( panelH );
			// Proba od razu: po odpowiedzi asynchronicznej przegladarka moze odmowic (brak aktywacji uzytkownika).
			// Wtedy zostaje przycisk "Kliknij, aby skopiowac tresc" i nic nie twierdzimy.
			var tresc = stan.tresc;
			schowekApi( tresc ).then( function ( ok ) {
				if ( ok && tresc === stan.tresc && ! panel.hidden ) {
					ustawKopie( true );
				}
			} );
		}

		// Tresc w panelu awaryjnym (pole, mailto) zawsze z aktualnych pol; po zmianie nie jest juz "skopiowana".
		function odswiezKopie() {
			var d = dane();
			stan.dane = d;
			stan.tresc = tekstWiadomosci( d );
			kopiaPole.value = stan.tresc;
			mailtoLink.href = mailto( d, stan.tresc );
			ustawKopie( false );
			kopiaInfo.textContent = '';
		}

		/* ---------- wysylka ---------- */

		function czekaj( ms ) {
			return new Promise( function ( ok ) {
				window.setTimeout( ok, ms );
			} );
		}

		// Zadanie z limitem czasu; zwraca { status, json }.
		function zadanie( adres, tresc, zCiasteczkami ) {
			var kontroler = 'AbortController' in window ? new AbortController() : null;
			var zegar;
			var limit = new Promise( function ( _, odrzuc ) {
				zegar = window.setTimeout( function () {
					if ( kontroler ) {
						kontroler.abort();
					}
					odrzuc( new Error( 'limit czasu' ) );
				}, LIMIT_CZASU );
			} );
			var wynik = window.fetch( adres, {
				method: 'POST',
				credentials: zCiasteczkami ? 'same-origin' : 'omit',
				cache: 'no-store',
				headers: tresc ? { 'Content-Type': 'application/json', 'X-WP-Nonce': stan.token.nonce } : {},
				body: tresc ? JSON.stringify( tresc ) : undefined,
				signal: kontroler ? kontroler.signal : undefined
			} ).then( function ( odp ) {
				return odp.text().then( function ( tekst ) {
					var json = null;
					try {
						json = JSON.parse( tekst );
					} catch ( e ) {}
					return { status: odp.status, json: json };
				} );
			} );
			return Promise.race( [ wynik, limit ] ).then( function ( w ) {
				window.clearTimeout( zegar );
				return w;
			}, function ( e ) {
				window.clearTimeout( zegar );
				throw e;
			} );
		}

		function wyslijDane( zCiasteczkami ) {
			var d = stan.dane;
			var tresc = {
				imie: d.imie,
				email: d.email,
				telefon: d.telefon,
				usluga: d.usluga,
				dokumenty: d.dokumenty,
				pracownicy: d.pracownicy,
				wiadomosc: d.wiadomosc,
				kw_x: form.elements.kw_x.value,
				t: stan.token.t,
				s: stan.token.s
			};
			if ( parametry.get( 'kw_test_awarii' ) === '1' ) {
				tresc.kw_test_awarii = 1; // serwer honoruje to tylko u zalogowanego administratora
			}
			return zadanie( cfg.endpoint, tresc, zCiasteczkami );
		}

		// Token wygasl (strona z pamieci podrecznej, karta otwarta od wczoraj): pobierz swiezy jako gosc i ponow raz.
		function odswiezToken() {
			return zadanie( cfg.tokenUrl, null, false ).then( function ( w ) {
				if ( w.status !== 200 || ! w.json || ! w.json.nonce || ! w.json.s ) {
					throw new Error( 'token' );
				}
				stan.token = { nonce: w.json.nonce, t: w.json.t, s: w.json.s };
			} );
		}

		function obsluz( w ) {
			var j = w.json;
			if ( w.status >= 200 && w.status < 300 && j && j.ok === true ) {
				pokazSukces();
				return;
			}
			if ( w.status === 422 && j && j.kod === 'walidacja' && j.pola ) {
				blokuj( false );
				var pierwsze = pokazBledy( j.pola );
				status.textContent = 'Popraw zaznaczone pola.';
				fokus( pierwsze || btn );
				return;
			}
			if ( w.status === 403 && ! stan.ponowiono ) {
				stan.ponowiono = true;
				return odswiezToken().then( function () {
					return czekaj( MIN_CZAS );
				} ).then( function () {
					return wyslijDane( false );
				} ).then( obsluz );
			}
			throw new Error( 'awaria' );
		}

		function wyslij() {
			if ( stan.wysylanie ) {
				return;
			}
			var d = dane();
			var pierwsze = pokazBledy( waliduj( d ) );
			if ( pierwsze ) {
				status.textContent = 'Popraw zaznaczone pola.';
				fokus( pierwsze );
				return;
			}
			stan.dane = d;
			stan.tresc = tekstWiadomosci( d );
			stan.ponowiono = false;
			ukryjAwarie();
			blokuj( true );
			var proba;
			try {
				proba = wyslijDane( true );
			} catch ( e ) {
				proba = Promise.reject( e ); // np. przegladarka bez fetch
			}
			proba.then( obsluz ).catch( pokazAwarie );
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			wyslij();
		} );
		ponowBtn.addEventListener( 'click', wyslij );

		form.addEventListener( 'input', function ( e ) {
			var el = e.target;
			if ( el && el.name && el.getAttribute( 'aria-invalid' ) === 'true' ) {
				var p = root.querySelector( '#' + el.id + '-blad' );
				el.removeAttribute( 'aria-invalid' );
				if ( p ) {
					p.hidden = true;
					p.textContent = '';
				}
			}
			if ( ! panel.hidden ) {
				odswiezKopie();
			}
		} );
		form.addEventListener( 'change', function () {
			if ( ! panel.hidden ) {
				odswiezKopie();
			}
		} );
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-kw-formularz]' ), function ( root ) {
			if ( ! root.kwGotowy ) {
				root.kwGotowy = true;
				init( root );
			}
		} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
