/**
 * Ekran uslugi w panelu: tablica kafli (akordeon) ze streszczeniami, progi cenowe jako tabela od-do albo os z suwakami,
 * zmiana cen progow hurtem, listy pozycji jako wiersze, miejsce w zakladce strzalkami, biblioteka pozycji i zestawow
 * progow, sprawdzanie pol przed zapisem i podglad karty cennika budowany na zywo z pol formularza.
 * Bez tego skryptu wszystkie pola sa widoczne (listy jako zwykle pola tekstowe, progi jako wiersze, kolejnosc jako
 * liczba) i formularz zapisuje sie tak samo: skrypt wysyla te same pola (kw_progi[i][...], kw_w_cenie, menu_order...).
 * Dane z pol trafiaja do strony wylacznie przez textContent i value, nigdy przez innerHTML (innerHTML tylko dla stalych
 * ikon SVG z tego pliku).
 */
( function () {
	'use strict';

	var k = document.querySelector( '[data-kw-kreator]' );
	if ( ! k ) {
		return;
	}
	var form = k.closest( 'form' );
	// Autozapis WordPressa jest na tym ekranie wylaczony (admin.php). Skrypt rdzenia w jednym miejscu (przejecie blokady
	// edycji przez inna osobe) wola go bez sprawdzenia: pusty zastepnik zapobiega bledowi.
	window.wp = window.wp || {};
	if ( ! window.wp.autosave ) {
		var nic = function () {};
		window.wp.autosave = { server: { triggerSave: nic, suspend: nic, resume: nic, tempBlockSave: nic, postChanged: function () { return false; } }, enableButtons: nic, disableButtons: nic };
	}
	var D = window.kwKreatorDane || {};
	var NB = ' ';
	var LICZBOWE = [ 'stala', 'od', 'przedzial' ];
	var LISTY = [ 'kw_w_cenie', 'kw_dodatkowo', 'kw_nie_obejmuje', 'kw_czynniki' ];

	function q( s, w ) {
		return ( w || k ).querySelector( s );
	}
	function qa( s, w ) {
		return Array.prototype.slice.call( ( w || k ).querySelectorAll( s ) );
	}
	function el( tag, klasa, tekst ) {
		var e = document.createElement( tag );
		if ( klasa ) {
			e.className = klasa;
		}
		if ( tekst !== undefined && tekst !== null ) {
			e.textContent = tekst;
		}
		return e;
	}
	function powiedz( tekst ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( tekst );
		}
	}
	// 1 pozycja, 2 pozycje, 5 pozycji.
	function ile( n, jedna, kilka, wiele ) {
		var d = n % 10;
		var s = n % 100;
		return n + ' ' + ( n === 1 ? jedna : ( d >= 2 && d <= 4 && ( s < 12 || s > 14 ) ? kilka : wiele ) );
	}
	function przycisk( atrybut, ikona, nazwa ) {
		var b = el( 'button', 'kw-ikona-btn' );
		b.type = 'button';
		b.setAttribute( atrybut, '' );
		b.setAttribute( 'aria-label', nazwa );
		b.title = nazwa;
		var s = el( 'span', 'kw-ik kw-ik--' + ikona );
		s.setAttribute( 'aria-hidden', 'true' );
		b.appendChild( s );
		return b;
	}

	/* ---------------------------------------------------------------------
	 * Te same reguly co w PHP (includes/dane.php, includes/widok.php). Zgodnosc pilnuje test porownania podgladu.
	 * ------------------------------------------------------------------ */

	function czyscTekst( v, max ) {
		return String( v === undefined || v === null ? '' : v ).replace( /<[^>]*>/g, '' ).replace( /\s+/g, ' ' ).trim().slice( 0, max || 200 );
	}
	// "1 400,50 zl" => "1400.5"; puste albo niepoprawne => ''.
	function czyscKwote( v ) {
		var s = String( v === undefined || v === null ? '' : v ).replace( /[\s ]|zł|zl|pln/gi, '' ).replace( /,/g, '.' );
		if ( ! /^\d{1,7}(\.\d{1,2})?$/.test( s ) ) {
			return '';
		}
		return parseFloat( s ).toFixed( 2 ).replace( /0+$/, '' ).replace( /\.$/, '' );
	}
	// "1400", "12 500" (twarda spacja od pieciu cyfr), "49,50".
	function kwota( kw ) {
		var l = parseFloat( kw );
		var grosze = Math.abs( l - Math.round( l ) ) > 0.004;
		var cz = l.toFixed( grosze ? 2 : 0 ).split( '.' );
		if ( l >= 10000 ) {
			cz[ 0 ] = cz[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, NB );
		}
		return cz[ 0 ] + ( cz[ 1 ] ? ',' + cz[ 1 ] : '' );
	}
	var RE_SPOJNIK = null;
	var RE_ZL = null;
	try {
		RE_SPOJNIK = new RegExp( '(?<![\\p{L}\\p{N}])([aiouwzAIOUWZ]) (?=\\S)', 'gu' );
		RE_ZL = new RegExp( '(\\d) (?=zł(?!\\p{L}))', 'gu' );
	} catch ( x ) {
		// Starsza przegladarka: podglad bez twardych spacji.
	}
	// Tekst bez sierot: twarda spacja po a, i, o, u, w, z; w naglowku takze miedzy dwoma ostatnimi krotkimi slowami.
	function e( tekst, naglowek ) {
		var t = czyscTekst( tekst, 100000 );
		if ( RE_SPOJNIK ) {
			t = t.replace( RE_SPOJNIK, '$1' + NB ).replace( RE_ZL, '$1' + NB );
		}
		if ( naglowek ) {
			var m = t.match( /^(.*[^ ]) ([^ ]+)$/ );
			if ( m ) {
				var przedostatnie = m[ 1 ].replace( /^.* /, '' );
				if ( ( przedostatnie + ' ' + m[ 2 ] ).length <= 24 ) {
					t = m[ 1 ] + NB + m[ 2 ];
				}
			}
		}
		return t;
	}
	function jednostka( j ) {
		return D.jednostki && Object.prototype.hasOwnProperty.call( D.jednostki, j ) ? D.jednostki[ j ] : j;
	}
	// Cena rozlozona na czesci (jak kw_uslugi_cena w PHP).
	function cena( u ) {
		var typ = u.typ;
		var min = u.min;
		if ( typ === 'opis' && u.cenaOpis !== '' ) {
			return { przed: '', kwota: u.cenaOpis, po: '', rodzaj: 'opis' };
		}
		var liczbowa = LICZBOWE.indexOf( typ ) >= 0;
		if ( liczbowa && min === '' && u.progi.length ) {
			min = czyscKwote( Math.min.apply( null, u.progi.map( function ( p ) {
				return parseFloat( p.cena );
			} ) ) );
			typ = u.progi.length > 1 ? 'od' : 'stala';
		}
		if ( ! liczbowa || min === '' ) {
			return { przed: '', kwota: 'Wycena indywidualna', po: '', rodzaj: 'tekst' };
		}
		var kw = kwota( min );
		if ( typ === 'przedzial' && u.max !== '' && parseFloat( u.max ) > parseFloat( min ) ) {
			kw += '-' + kwota( u.max );
		}
		var j = jednostka( u.jednostka );
		return {
			przed: typ === 'od' ? 'od' : '',
			kwota: kw + NB + 'zł',
			po: ( u.netto ? 'netto' : 'brutto' ) + ( j !== '' ? ' / ' + j : '' ),
			rodzaj: 'kwota'
		};
	}
	function cenaTekst( c ) {
		return [ c.przed, c.kwota, c.po ].filter( function ( x ) {
			return x !== '';
		} ).join( ' ' );
	}

	/* ---------------------------------------------------------------------
	 * Stan formularza
	 * ------------------------------------------------------------------ */

	function zaznaczone( nazwa ) {
		var p = q( '[name="' + nazwa + '"]:checked' );
		return p ? p.value : null;
	}
	// Zakres z etykiety progu: "11-20", "15", "201 i więcej", "201+", "od 201", "powyżej 200", "do 10". Nieczytelny: null.
	function zakresProgu( et ) {
		var s = String( et ).replace( /\s+/g, ' ' ).trim().toLowerCase();
		var m = s.match( /^(\d+) ?[-\u2013\u2014] ?(\d+)$/ );
		if ( m ) {
			return { od: +m[ 1 ], do: +m[ 2 ] };
		}
		m = s.match( /^(\d+) ?(\+|i więcej|i wiecej|i powyżej|lub więcej)$/ );
		if ( m ) {
			return { od: +m[ 1 ], do: Infinity };
		}
		m = s.match( /^(od|powyżej|ponad) (\d+)$/ );
		if ( m ) {
			return { od: +m[ 2 ] + ( m[ 1 ] === 'od' ? 0 : 1 ), do: Infinity };
		}
		m = s.match( /^do (\d+)$/ );
		if ( m ) {
			return { od: 1, do: +m[ 1 ] };
		}
		m = s.match( /^(\d+)$/ );
		return m ? { od: +m[ 1 ], do: +m[ 1 ] } : null;
	}

	/* ---------------------------------------------------------------------
	 * Model progow i list. Pola z serwera sa czytane raz, przy starcie; potem model jest zrodlem prawdy, a do formularza
	 * trafia przez pola ukryte o tych samych nazwach (kw_progi[i][etykieta|cena|od], textarea kw_w_cenie...).
	 * ------------------------------------------------------------------ */

	// Prog: od (liczba albo ''), do (liczba, '' = jeszcze nie wpisane, null = "i więcej"), cena (tekst jak w polu),
	// odCena (dopisek "od"), wyb (zaznaczony do zmiany ceny), et (etykieta z bazy, dopoki nikt nie zmienil zakresu: zapis
	// bez zmian wysyla dokladnie to, co bylo), zly (etykieta z bazy, ktorej nie da sie przeczytac jako zakresu).
	var M = { progi: [], listy: {} };
	var LIMIT_PROGOW = 12;
	var LIMIT_POZYCJI = 20;

	function etykieta( p ) {
		if ( p.et !== null ) {
			return p.et;
		}
		if ( p.od === '' || p.do === '' ) {
			return '';
		}
		return p.do === null ? p.od + ' i więcej' : p.od + '-' + p.do;
	}
	function nowyProgModelu( od, d, cenaProgu, odCena ) {
		return { od: od, do: d, cena: cenaProgu === undefined || cenaProgu === null ? '' : String( cenaProgu ), odCena: !! odCena, wyb: false, et: null, zly: false };
	}
	function wczytajProgi() {
		return qa( '[data-kw-prog]' ).map( function ( w ) {
			return {
				et: q( '[name$="[etykieta]"]', w ).value.trim(),
				cena: q( '[name$="[cena]"]', w ).value.trim(),
				od: q( '[name$="[od]"]', w ).checked
			};
		} ).filter( function ( x ) {
			return x.et !== '' || x.cena !== '';
		} ).slice( 0, LIMIT_PROGOW ).map( function ( x ) {
			var z = zakresProgu( x.et );
			var p = nowyProgModelu( z ? z.od : '', z ? ( z.do === Infinity ? null : z.do ) : '', x.cena, x.od );
			p.et = x.et;
			p.zly = ! z && x.et !== '';
			return p;
		} );
	}
	function lista( nazwa ) {
		return ( M.listy[ nazwa ] || [] ).map( function ( w ) {
			return czyscTekst( w, 200 );
		} ).filter( Boolean ).slice( 0, LIMIT_POZYCJI );
	}
	// Model => pola formularza. Wolane przy kazdym odswiezeniu (najwyzej 12 progow i 4 x 20 pozycji).
	var poleProgow = null;
	function zapiszDoFormularza() {
		if ( poleProgow ) {
			var f = document.createDocumentFragment();
			M.progi.forEach( function ( p, i ) {
				var dodajUkryte = function ( klucz, wartosc ) {
					var x = el( 'input' );
					x.type = 'hidden';
					x.name = 'kw_progi[' + i + '][' + klucz + ']';
					x.value = wartosc;
					f.appendChild( x );
				};
				dodajUkryte( 'etykieta', etykieta( p ) );
				dodajUkryte( 'cena', String( p.cena ) );
				if ( p.odCena ) {
					dodajUkryte( 'od', '1' );
				}
			} );
			poleProgow.replaceChildren( f );
		}
		LISTY.forEach( function ( nazwa ) {
			var ta = q( 'textarea[name="' + nazwa + '"]' );
			if ( ta && M.listy[ nazwa ] ) {
				ta.value = M.listy[ nazwa ].map( function ( w ) {
					return String( w ).replace( /\s+/g, ' ' ).trim();
				} ).filter( Boolean ).join( '\n' );
			}
		} );
	}
	function stan() {
		var wybor = q( '#kw-jednostka' );
		var jedn = wybor.value === '_wlasna' ? czyscTekst( q( '#kw-jednostka-wlasna' ).value, 40 ) : wybor.value;
		var progi = [];
		M.progi.forEach( function ( p ) {
			var et = czyscTekst( etykieta( p ), 40 );
			var c = czyscKwote( p.cena );
			if ( et !== '' && c !== '' ) {
				progi.push( { etykieta: et, cena: c, od: p.odCena } );
			}
		} );
		var kat = q( '#kw-grupa' );
		return {
			tytul: q( '#title' ).value.trim(),
			zajawka: q( '#kw-zajawka' ).value,
			opis: q( '#kw-opis' ).value,
			kategoria: kat.value,
			kategoriaNazwa: kat.value !== '0' && kat.selectedIndex >= 0 ? kat.options[ kat.selectedIndex ].text : '',
			typ: zaznaczone( 'kw_cena_typ' ) || 'indywidualna',
			min: czyscKwote( q( '#kw-cena-min' ).value ),
			max: czyscKwote( q( '#kw-cena-max' ).value ),
			cenaOpis: czyscTekst( q( '#kw-cena-opis' ).value, 200 ),
			jednostka: jedn,
			netto: zaznaczone( 'kw_cena_netto' ) !== '0',
			progi: progi.slice( 0, LIMIT_PROGOW ),
			wCenie: lista( 'kw_w_cenie' ),
			dodatkowo: lista( 'kw_dodatkowo' ),
			nieObejmuje: lista( 'kw_nie_obejmuje' ),
			czynniki: lista( 'kw_czynniki' ),
			wyroznienie: czyscTekst( q( '#kw-wyroznienie' ).value, 40 ),
			cennik: q( '#kw-cennik-flaga' ).checked,
			formularz: q( '#kw-formularz-flaga' ).checked,
			kolejnosc: parseInt( q( '#kw-kolejnosc' ).value, 10 ) || 0
		};
	}


	/* ---------------------------------------------------------------------
	 * Podglad karty: ta sama budowa co kw_uslugi_cennik_karta() w PHP
	 * ------------------------------------------------------------------ */

	function sr( tytul ) {
		return el( 'span', 'kw-sr', ': ' + e( tytul ) );
	}
	function punkty( pozycje, rodzaj ) {
		var ul = el( 'ul', 'kw-c__punkty kw-c__punkty--' + rodzaj );
		pozycje.forEach( function ( p ) {
			ul.appendChild( el( 'li', '', e( p ) ) );
		} );
		return ul;
	}
	function blok( naglowek, pozycje, rodzaj ) {
		if ( ! pozycje.length ) {
			return null;
		}
		var b = el( 'div', 'kw-c__blok' );
		b.appendChild( el( 'p', 'kw-c__blok-h', naglowek ) );
		b.appendChild( punkty( pozycje, rodzaj ) );
		return b;
	}
	function karta( u ) {
		var c = D.ceny ? cena( u ) : null;
		var kr = el( 'div', 'kw-c__karta' + ( u.wyroznienie !== '' ? ' kw-c__karta--wyrozniona' : '' ) + ( c ? '' : ' kw-c__karta--bezceny' ) );
		kr.setAttribute( 'role', 'listitem' );

		var glowa = el( 'div', 'kw-c__glowa' );
		if ( u.wyroznienie !== '' ) {
			glowa.appendChild( el( 'p', 'kw-c__znak', e( u.wyroznienie ) ) );
		}
		var tytul = el( 'h4', 'kw-c__tytul', e( u.tytul, true ) );
		if ( u.tytul === '' ) {
			// Tylko w panelu: pusta nazwa nie zostawia dziury w podgladzie.
			tytul.textContent = 'Nazwa usługi';
			tytul.classList.add( 'is-puste' );
		}
		glowa.appendChild( tytul );
		if ( u.zajawka.trim() !== '' ) {
			glowa.appendChild( el( 'p', 'kw-c__zajawka', e( u.zajawka ) ) );
		}
		kr.appendChild( glowa );

		if ( c ) {
			var p = el( 'p', 'kw-c__cena kw-c__cena--' + c.rodzaj );
			if ( c.przed !== '' ) {
				p.appendChild( el( 'span', 'kw-c__od', c.przed ) );
				p.appendChild( document.createTextNode( ' ' ) );
			}
			p.appendChild( el( 'span', 'kw-c__kwota', c.kwota ) );
			if ( c.po !== '' ) {
				p.appendChild( document.createTextNode( ' ' ) );
				p.appendChild( el( 'span', 'kw-c__jedn', c.po ) );
			}
			kr.appendChild( p );
		}

		// Do czterech pozycji "W cenie" wszystkie na karcie; przy dluzszej liscie trzy pierwsze, reszta w szczegolach.
		var naKarcie = u.wCenie.length > 4 ? u.wCenie.slice( 0, 3 ) : u.wCenie;
		var reszta = u.wCenie.slice( naKarcie.length );
		if ( naKarcie.length ) {
			var skrot = el( 'div', 'kw-c__skrot' );
			skrot.appendChild( el( 'p', 'kw-c__blok-h', reszta.length ? 'W cenie między innymi' : 'W cenie' ) );
			skrot.appendChild( punkty( naKarcie, 'tak' ) );
			kr.appendChild( skrot );
		}

		var bloki = [];
		var maProgi = !! ( c && u.progi.length );
		if ( maProgi ) {
			var j = jednostka( u.jednostka );
			var b = el( 'div', 'kw-c__blok kw-c__blok--progi' );
			b.appendChild( el( 'p', 'kw-c__blok-h', 'Progi cenowe' ) );
			var t = el( 'table', 'kw-c__progi' );
			t.appendChild( el( 'caption', 'kw-sr', e( u.tytul ) + ': cena według liczby dokumentów w' + NB + 'miesiącu' ) );
			var th = el( 'thead' );
			var tr = el( 'tr' );
			var k1 = el( 'th', '', 'Dokumenty w' + NB + 'miesiącu' );
			k1.setAttribute( 'scope', 'col' );
			var k2 = el( 'th', '', 'Cena ' + ( u.netto ? 'netto' : 'brutto' ) + ( j !== '' ? ' / ' + j : '' ) );
			k2.setAttribute( 'scope', 'col' );
			tr.appendChild( k1 );
			tr.appendChild( k2 );
			th.appendChild( tr );
			t.appendChild( th );
			var tb = el( 'tbody' );
			u.progi.forEach( function ( prog ) {
				var w = el( 'tr' );
				var n = el( 'th', '', prog.etykieta );
				n.setAttribute( 'scope', 'row' );
				w.appendChild( n );
				w.appendChild( el( 'td', '', ( prog.od ? 'od ' : '' ) + kwota( prog.cena ) + NB + 'zł' ) );
				tb.appendChild( w );
			} );
			t.appendChild( tb );
			b.appendChild( t );
			bloki.push( b );
		}
		[
			blok( 'W cenie także', reszta, 'tak' ),
			blok( 'Dodatkowo płatne', u.dodatkowo, 'plus' ),
			blok( 'Nie obejmuje', u.nieObejmuje, 'nie' ),
			blok( 'Od czego zależy cena', u.czynniki, 'kropka' )
		].forEach( function ( x ) {
			if ( x ) {
				bloki.push( x );
			}
		} );
		if ( czyscTekst( u.opis, 100000 ) !== '' ) {
			// Uproszczenie wobec wpautop + wp_kses_post: akapity z pustych wierszy, bez znacznikow HTML.
			var o = el( 'div', 'kw-c__blok kw-c__blok--opis' );
			u.opis.split( /\n\s*\n/ ).forEach( function ( akapit ) {
				if ( akapit.trim() !== '' ) {
					o.appendChild( el( 'p', '', akapit.trim() ) );
				}
			} );
			bloki.push( o );
		}
		if ( bloki.length ) {
			var det = el( 'details', 'kw-c__wiecej' );
			var sum = el( 'summary' );
			var span = el( 'span', '', maProgi ? 'Progi cenowe i' + NB + 'szczegóły' : 'Szczegóły usługi' );
			span.appendChild( sr( u.tytul ) );
			sum.appendChild( span );
			det.appendChild( sum );
			var szcz = el( 'div', 'kw-c__szczegoly' );
			bloki.forEach( function ( x ) {
				szcz.appendChild( x );
			} );
			det.appendChild( szcz );
			kr.appendChild( det );
		}

		var akcja = el( 'p', 'kw-c__akcja' );
		var a = el( 'a', 'kw-c__btn', 'Zapytaj o' + NB + 'wycenę' );
		a.setAttribute( 'href', '#kontakt' );
		a.setAttribute( 'data-kw-usluga', D.slug || '' );
		a.appendChild( sr( u.tytul ) );
		akcja.appendChild( a );
		kr.appendChild( akcja );
		return kr;
	}

	var podglad = document.querySelector( '[data-kw-podglad]' );
	window.kwKreator = { phpHtml: podglad ? podglad.innerHTML : '', stan: stan, karta: karta };

	/* Podswietlenie: ktore miejsce karty odpowiada polu, w ktorym jest kursor albo fokus ("co edytuje"). */
	var wskazany = '';
	var wskazowka = document.querySelector( '[data-kw-wskazowka]' );
	var NAZWY_CELOW = {
		tytul: 'Nazwa usługi',
		zajawka: 'Krótki opis',
		cena: 'Cena',
		progi: 'Progi cenowe (klient widzi je po kliknięciu „Progi cenowe i szczegóły”)',
		wcenie: 'W cenie',
		dodatkowo: 'Dodatkowo płatne (po rozwinięciu szczegółów)',
		nie: 'Nie obejmuje (po rozwinięciu szczegółów)',
		czynniki: 'Od czego zależy cena (po rozwinięciu szczegółów)',
		wyroznienie: 'Wyróżnienie',
		opis: 'Dodatkowy opis (na końcu szczegółów)'
	};
	function fragmenty( cel ) {
		if ( ! podglad ) {
			return [];
		}
		function blok( sel ) {
			var x = podglad.querySelector( sel );
			return x ? [ x.closest( '.kw-c__blok' ) || x ] : [];
		}
		switch ( cel ) {
			case 'tytul':
				return qa( '.kw-c__tytul', podglad );
			case 'zajawka':
				return qa( '.kw-c__zajawka', podglad );
			case 'cena':
				return qa( '.kw-c__cena', podglad );
			case 'progi':
				return qa( '.kw-c__blok--progi', podglad );
			case 'wcenie':
				return qa( '.kw-c__skrot', podglad ).concat( blok( '.kw-c__szczegoly .kw-c__punkty--tak' ) );
			case 'dodatkowo':
				return blok( '.kw-c__punkty--plus' );
			case 'nie':
				return blok( '.kw-c__punkty--nie' );
			case 'czynniki':
				return blok( '.kw-c__punkty--kropka' );
			case 'wyroznienie':
				return qa( '.kw-c__znak', podglad );
			case 'opis':
				return qa( '.kw-c__blok--opis', podglad );
		}
		return [];
	}
	function podswietl() {
		if ( ! podglad ) {
			return;
		}
		Array.prototype.forEach.call( document.querySelectorAll( '.kw-podglad .is-wskazany' ), function ( x ) {
			x.classList.remove( 'is-wskazany' );
		} );
		var tekst = '';
		if ( wskazany === 'widocznosc' ) {
			var cala = podglad.querySelector( '.kw-c__karta' );
			if ( cala ) {
				cala.classList.add( 'is-wskazany' );
			}
			tekst = 'Widoczność dotyczy całej karty: czy klient ją widzi w cenniku i w formularzu.';
		} else if ( wskazany === 'kategoria' ) {
			tekst = 'Kategorii nie ma na samej karcie: decyduje, w której zakładce cennika klient ją znajdzie.';
		} else if ( wskazany === 'kolejnosc' ) {
			tekst = 'Kolejności nie ma na samej karcie: decyduje, która z kolei jest ta karta w swojej zakładce.';
		} else if ( wskazany ) {
			var f = fragmenty( wskazany );
			f.forEach( function ( x ) {
				x.classList.add( 'is-wskazany' );
				var szczegoly = x.closest( 'details' );
				if ( szczegoly ) {
					szczegoly.open = true; // pole lezy w rozwijanych szczegolach karty: pokaz je
				}
			} );
			tekst = f.length ? 'Podświetlone: ' + NAZWY_CELOW[ wskazany ] + '.' : 'To pole jest teraz puste, więc na karcie go nie ma. Wpisz coś, a pojawi się tutaj.';
			// Dwie kolumny: skrzynka podgladu ma wlasne przewijanie; dosun podswietlony fragment (strony nie przewijamy).
			var boczna = document.getElementById( 'side-sortables' );
			if ( f.length && boczna && window.getComputedStyle( boczna ).position === 'sticky' ) {
				var r = f[ 0 ].getBoundingClientRect();
				var b = boczna.getBoundingClientRect();
				if ( r.top < b.top + 8 ) {
					boczna.scrollTop -= b.top + 8 - r.top;
				} else if ( r.bottom > b.bottom - 8 ) {
					boczna.scrollTop += r.bottom - b.bottom + 8;
				}
			}
		}
		if ( wskazowka ) {
			wskazowka.textContent = tekst;
			wskazowka.hidden = tekst === '';
		}
	}
	function wskaz( cel ) {
		if ( cel !== wskazany ) {
			wskazany = cel;
			podswietl();
		}
	}
	function celPola( x ) {
		var p = x && x.closest ? x.closest( '[data-kw-cel]' ) : null;
		return p ? p.getAttribute( 'data-kw-cel' ) : '';
	}
	function rysujPodglad( u ) {
		if ( ! podglad ) {
			return;
		}
		var stare = podglad.querySelector( 'details' );
		var otwarte = !! ( stare && stare.open );
		var nowa = karta( u );
		var det = nowa.querySelector( 'details' );
		if ( det && otwarte ) {
			det.open = true;
		}
		podglad.replaceChildren( nowa );
		podswietl(); // karta jest zbudowana od nowa: przywroc podswietlenie
	}
	if ( podglad ) {
		// Przycisk na karcie w panelu donikad nie prowadzi; rozwijanie szczegolow dziala.
		podglad.addEventListener( 'click', function ( ev ) {
			if ( ev.target.closest( 'a' ) ) {
				ev.preventDefault();
			}
		} );
		var opisPodgladu = document.querySelector( '[data-kw-podglad-opis]' );
		if ( opisPodgladu ) {
			opisPodgladu.textContent = 'Tak karta wygląda na telefonie. Zmienia się podczas pisania; na stronie zmiany pojawią się po zapisaniu. Kliknij w pole albo najedź na nie, a podświetlimy jego miejsce na karcie.';
		}
	}

	/* ---------------------------------------------------------------------
	 * Typ ceny i jednostka: pola zalezne od wyboru
	 * ------------------------------------------------------------------ */

	var wlasna = q( '[data-kw-jednostka-wlasna]' );
	function pokazPola() {
		var t = zaznaczone( 'kw_cena_typ' ) || 'indywidualna';
		qa( '[data-kw-typy]' ).forEach( function ( x ) {
			x.hidden = x.getAttribute( 'data-kw-typy' ).split( ' ' ).indexOf( t ) < 0;
		} );
		wlasna.hidden = q( '#kw-jednostka' ).value !== '_wlasna';
	}

	/* ---------------------------------------------------------------------
	 * Drobne elementy: ikony (stale SVG), przyciski z ikona, powiadomienie, liczby
	 * ------------------------------------------------------------------ */

	var IKONY = {
		tak: '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2.5 8.5l3.6 3.6 7.4-8.1"/>',
		plus: '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M8 3v10M3 8h10"/>',
		nie: '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M3 8h10"/>',
		kropka: '<circle cx="8" cy="8" r="2.5" fill="currentColor"/>',
		gora: '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M3.5 10.25 8 5.75l4.5 4.5"/>',
		dol: '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M3.5 5.75 8 10.25l4.5-4.5"/>',
		usun: '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M4 4l8 8M12 4l-8 8"/>',
		cofnij: '<path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M6 4 2.5 7.5 6 11M3 7.5h6.5a3 3 0 0 1 0 6H8"/>',
		ponow: '<path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" d="M10 4l3.5 3.5L10 11M13 7.5H6.5a3 3 0 0 0 0 6H8"/>',
		uchwyt: '<circle cx="6" cy="4" r="1.2" fill="currentColor"/><circle cx="10" cy="4" r="1.2" fill="currentColor"/><circle cx="6" cy="8" r="1.2" fill="currentColor"/><circle cx="10" cy="8" r="1.2" fill="currentColor"/><circle cx="6" cy="12" r="1.2" fill="currentColor"/><circle cx="10" cy="12" r="1.2" fill="currentColor"/>',
		chevron: '<path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M3.5 6 8 10.5 12.5 6"/>',
		biblioteka: '<path fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" d="M2.5 3h3.5v10H2.5zM7 3h3v10H7zM11 3.6l2.9-.6 2.1 9.8-2.9.6z"/>',
		slupki: '<rect x="1" y="9" width="3" height="6" fill="currentColor"/><rect x="5.5" y="6" width="3" height="9" fill="currentColor"/><rect x="10" y="3" width="3" height="12" fill="currentColor"/>'
	};
	var ZNAK_LISTY = { kw_w_cenie: 'tak', kw_dodatkowo: 'plus', kw_nie_obejmuje: 'nie', kw_czynniki: 'kropka' };
	function ikona( nazwa ) {
		var s = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		s.setAttribute( 'class', 'kw-i' );
		s.setAttribute( 'viewBox', '0 0 16 16' );
		s.setAttribute( 'aria-hidden', 'true' );
		s.setAttribute( 'focusable', 'false' );
		s.innerHTML = IKONY[ nazwa ]; // stala z tego pliku, nie dane
		return s;
	}
	// Przycisk z ikona: nazwa dla czytnikow w aria-label i title; tekst (opcjonalnie) widoczny obok ikony.
	function ikbtn( klasa, nazwaIkony, nazwa, tekst ) {
		var b = el( 'button', klasa );
		b.type = 'button';
		if ( nazwaIkony ) {
			b.appendChild( ikona( nazwaIkony ) );
		}
		if ( tekst ) {
			b.appendChild( document.createTextNode( ( nazwaIkony ? ' ' : '' ) + tekst ) );
		} else {
			b.setAttribute( 'aria-label', nazwa );
			b.title = nazwa;
		}
		return b;
	}
	var toastCzas = null;
	// Krotkie powiadomienie na dole ekranu. Z "cofnij": przycisk Cofnij (usuniecie bez okna potwierdzenia) i dluzszy czas.
	function powiadom( tekst, cofnij ) {
		var stary = document.querySelector( '.kw-toast' );
		if ( stary ) {
			stary.remove();
		}
		var t = el( 'div', 'kw-toast' );
		t.setAttribute( 'role', 'status' );
		t.appendChild( el( 'span', '', tekst ) );
		if ( cofnij ) {
			var b = el( 'button', 'kw-toast__cofnij', 'Cofnij' );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				t.remove();
				cofnij();
			} );
			t.appendChild( b );
		}
		document.body.appendChild( t );
		clearTimeout( toastCzas );
		toastCzas = setTimeout( function () {
			t.remove();
		}, cofnij ? 6000 : 2600 );
	}
	function zl( n ) {
		return kwota( n ) + NB + 'zł';
	}
	function slowoProg( n ) {
		return ile( n, 'próg', 'progi', 'progów' ).replace( /^\d+ /, '' );
	}
	var licznikId = 0;
	function nowyId( p ) {
		licznikId++;
		return 'kw-' + p + '-' + licznikId;
	}

	/* ---------------------------------------------------------------------
	 * Biblioteka: dane z serwera (kwKreatorDane.biblioteka), zapis przez admin-ajax
	 * ------------------------------------------------------------------ */

	var B = D.biblioteka || { w_cenie: [], dodatkowo: [], nie_obejmuje: [], czynniki: [], progi: [] };
	var BIB_KLUCZ = { kw_w_cenie: 'w_cenie', kw_dodatkowo: 'dodatkowo', kw_nie_obejmuje: 'nie_obejmuje', kw_czynniki: 'czynniki' };
	var BIB_NAZWY = { kw_w_cenie: 'W cenie', kw_dodatkowo: 'Dodatkowo płatne', kw_nie_obejmuje: 'Nie obejmuje', kw_czynniki: 'Od czego zależy cena', progi: 'Zestawy progów', jednostki: 'Za co jest kwota' };
	var dodaneDoBib = {}; // "pole|tekst" albo "progi|id": dodane w tej sesji ekranu (mozna cofnac jednym kliknieciem)
	var odswiezBiblioteke = function () {};
	function wBibliotece( pole, tekst ) {
		return ( B[ BIB_KLUCZ[ pole ] ] || [] ).indexOf( tekst ) >= 0;
	}
	// Dopisanie albo usuniecie w bibliotece. Sukces podmienia dane biblioteki na te z serwera.
	function bibZmien( akcja, rodzaj, wartosc ) {
		var dane = new window.FormData();
		dane.append( 'action', akcja === 'dodaj' ? 'kw_uslugi_bib_dodaj' : 'kw_uslugi_bib_usun' );
		dane.append( 'rodzaj', rodzaj );
		dane.append( 'wartosc', wartosc );
		dane.append( 'token', D.bibToken || '' );
		return window.fetch( D.ajax || window.ajaxurl, { method: 'POST', body: dane, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json().catch( function () {
				return { success: false };
			} );
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				var kod = j && j.data && j.data.kod;
				throw new Error( kod === 'pelna' ? 'Biblioteka jest pełna w tej grupie. Usuń coś z niej, zanim dodasz nową pozycję.' : ( kod === 'token' || kod === 'prawa' ? 'Brak uprawnień albo sesja wygasła. Odśwież stronę i spróbuj jeszcze raz.' : 'Nie udało się zapisać w bibliotece. Spróbuj jeszcze raz.' ) );
			}
			B = j.data.biblioteka;
			return j.data;
		} );
	}
	// Znaczek przy pozycji listy: "Dodaj do biblioteki", po kliknieciu "Cofnij" (usuwa z biblioteki), i tak w kolko.
	// Pozycja, ktora juz byla w bibliotece przed wejsciem na ekran, nie ma znaczka.
	function znaczekBib( pole, t ) {
		var w = czyscTekst( t, 200 );
		var klucz = pole + '|' + w;
		var jest = wBibliotece( pole, w );
		if ( ! w || ( jest && ! dodaneDoBib[ klucz ] ) ) {
			return el( 'span', 'lista__bib-puste' );
		}
		var b = jest ? ikbtn( 'ikbtn ikbtn--cofnij', 'cofnij', 'Cofnij: usuń z biblioteki: ' + w ) : ikbtn( 'ikbtn ikbtn--ok', 'tak', 'Dodaj do biblioteki: ' + w );
		b.addEventListener( 'click', function () {
			b.disabled = true;
			bibZmien( jest ? 'usun' : 'dodaj', BIB_KLUCZ[ pole ], w ).then( function () {
				if ( jest ) {
					delete dodaneDoBib[ klucz ];
				} else {
					dodaneDoBib[ klucz ] = true;
				}
				var nowy = znaczekBib( pole, w );
				b.replaceWith( nowy );
				if ( nowy.focus ) {
					nowy.focus();
				}
				odswiezBiblioteke();
				powiadom( ( jest ? 'Usunięto z biblioteki: ' : 'Dodano do biblioteki: ' ) + w );
				powiedz( jest ? 'Usunięto z biblioteki' : 'Dodano do biblioteki' );
			} ).catch( function ( x ) {
				b.disabled = false;
				powiadom( x.message );
			} );
		} );
		return b;
	}

	/* ---------------------------------------------------------------------
	 * Listy pozycji: wiersze (uchwyt, tekst rosnacy z trescia, znaczek biblioteki, usun) i dopisywanie z podpowiedziami
	 * ------------------------------------------------------------------ */

	var przerysujListy = {};
	function kafelListy( box ) {
		var pole = box.getAttribute( 'data-kw-lista' );
		var nazwa = box.getAttribute( 'data-kw-nazwa' );
		var ta = q( 'textarea', box );
		M.listy[ pole ] = ta.value.split( /\r\n|\r|\n/ ).map( function ( w ) {
			return w.replace( /\s+/g, ' ' ).trim();
		} ).filter( Boolean ).slice( 0, LIMIT_POZYCJI );
		box.classList.add( 'lista', 'is-js' );

		var glowa = el( 'div', 'lista__glowa' );
		var znak = ikona( ZNAK_LISTY[ pole ] || 'kropka' );
		glowa.appendChild( znak );
		glowa.appendChild( el( 'span', 'lista__nazwa', nazwa ) );
		var licznik = el( 'span', 'lista__licznik' );
		glowa.appendChild( licznik );
		var ul = el( 'ul', 'lista__pozycje' );
		ul.setAttribute( 'aria-label', nazwa );
		var dodaj = el( 'div', 'lista__dodaj' );
		var lid = nowyId( 'podpow' );
		var podpow = el( 'ul', 'podpow' );
		podpow.id = lid;
		podpow.setAttribute( 'role', 'listbox' );
		podpow.setAttribute( 'aria-label', 'Podpowiedzi z biblioteki' );
		podpow.hidden = true;
		var wej = el( 'input' );
		wej.type = 'text';
		wej.autocomplete = 'off';
		wej.maxLength = 200;
		wej.id = nowyId( 'nowa' );
		wej.setAttribute( 'role', 'combobox' );
		wej.setAttribute( 'aria-autocomplete', 'list' );
		wej.setAttribute( 'aria-expanded', 'false' );
		wej.setAttribute( 'aria-controls', lid );
		wej.setAttribute( 'aria-label', 'Nowa pozycja: ' + nazwa );
		wej.placeholder = 'Wpisz pozycję albo wybierz z podpowiedzi, Enter dodaje';
		wej.hidden = true;
		var dodajBtn = ikbtn( 'lista__dodaj-btn', 'plus', '', 'Dodaj pozycję' );
		dodajBtn.setAttribute( 'aria-label', 'Dodaj pozycję do listy: ' + nazwa );
		dodaj.appendChild( dodajBtn );
		dodaj.appendChild( wej );
		dodaj.appendChild( podpow );
		var stopka = el( 'p', 'lista__stopka' );
		box.appendChild( glowa );
		box.appendChild( ul );
		box.appendChild( dodaj );
		box.appendChild( stopka );

		var wybrany = -1;
		function zamknijPodpow() {
			podpow.hidden = true;
			wej.setAttribute( 'aria-expanded', 'false' );
			wej.removeAttribute( 'aria-activedescendant' );
			wybrany = -1;
		}
		function zaznaczPodpow() {
			qa( 'li', podpow ).forEach( function ( li, i ) {
				li.setAttribute( 'aria-selected', String( i === wybrany ) );
				if ( i === wybrany ) {
					wej.setAttribute( 'aria-activedescendant', li.id );
				}
			} );
		}
		function podpowiedz() {
			var fraza = wej.value.trim().toLowerCase();
			var jest = M.listy[ pole ];
			var kand = ( B[ BIB_KLUCZ[ pole ] ] || [] ).filter( function ( t ) {
				return jest.indexOf( t ) < 0 && ( ! fraza || t.toLowerCase().indexOf( fraza ) >= 0 );
			} ).slice( 0, 6 );
			podpow.replaceChildren();
			wybrany = -1;
			kand.forEach( function ( t, i ) {
				var li = el( 'li' );
				li.id = lid + '-' + i;
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );
				li.appendChild( ikona( 'biblioteka' ) );
				li.appendChild( el( 'span', '', t ) );
				li.appendChild( el( 'small', '', 'z biblioteki' ) );
				li.addEventListener( 'mousedown', function ( ev ) {
					ev.preventDefault(); // fokus zostaje w polu
					dodajPozycje( t, true );
				} );
				podpow.appendChild( li );
			} );
			podpow.hidden = ! kand.length;
			wej.setAttribute( 'aria-expanded', String( !! kand.length ) );
		}
		function dodajPozycje( t, zBiblioteki ) {
			var wiersze = String( t === undefined ? wej.value : t ).split( /\r\n|\r|\n/ ).map( function ( w ) {
				return w.replace( /\s+/g, ' ' ).trim();
			} ).filter( Boolean );
			if ( ! wiersze.length ) {
				wej.focus();
				return;
			}
			wiersze.slice( 0, LIMIT_POZYCJI - M.listy[ pole ].length ).forEach( function ( w ) {
				M.listy[ pole ].push( w );
			} );
			wej.value = '';
			zamknijPodpow();
			rysuj();
			zmiana();
			powiedz( 'Dodano do listy ' + nazwa + ': ' + wiersze[ 0 ] );
			if ( zBiblioteki ) {
				powiadom( 'Dodano: ' + wiersze[ 0 ] );
			}
			if ( M.listy[ pole ].length < LIMIT_POZYCJI ) {
				wej.focus();
				podpowiedz();
			}
		}
		dodajBtn.addEventListener( 'click', function () {
			dodajBtn.hidden = true;
			wej.hidden = false;
			wej.focus();
		} );
		wej.addEventListener( 'input', podpowiedz );
		wej.addEventListener( 'focus', podpowiedz );
		wej.addEventListener( 'blur', function () {
			setTimeout( zamknijPodpow, 150 );
			if ( ! wej.value.trim() ) {
				wej.hidden = true;
				dodajBtn.hidden = M.listy[ pole ].length >= LIMIT_POZYCJI;
			}
		} );
		wej.addEventListener( 'paste', function ( ev ) {
			// Wklejona lista (kilka wierszy) od razu trafia jako osobne pozycje.
			var tekst = ( ev.clipboardData || window.clipboardData ).getData( 'text' );
			if ( /[\r\n]/.test( tekst.trim() ) ) {
				ev.preventDefault();
				dodajPozycje( wej.value + tekst );
			}
		} );
		wej.addEventListener( 'keydown', function ( ev ) {
			var li = qa( 'li', podpow );
			if ( ev.key === 'ArrowDown' && li.length ) {
				ev.preventDefault();
				wybrany = ( wybrany + 1 ) % li.length;
				zaznaczPodpow();
			} else if ( ev.key === 'ArrowUp' && li.length ) {
				ev.preventDefault();
				wybrany = ( wybrany - 1 + li.length ) % li.length;
				zaznaczPodpow();
			} else if ( ev.key === 'Escape' ) {
				zamknijPodpow();
			} else if ( ev.key === 'Enter' ) {
				ev.preventDefault();
				if ( wybrany >= 0 && li[ wybrany ] ) {
					dodajPozycje( q( 'span', li[ wybrany ] ).textContent, true );
				} else {
					dodajPozycje();
				}
			}
		} );

		var ciagnie = -1;
		function rosnij( t ) {
			if ( ! ( window.CSS && CSS.supports && CSS.supports( 'field-sizing', 'content' ) ) ) {
				t.style.height = 'auto';
				t.style.height = t.scrollHeight + 'px';
			}
		}
		function przesun( i, j ) {
			var p = M.listy[ pole ];
			if ( j < 0 || j >= p.length ) {
				return;
			}
			var x = p.splice( i, 1 )[ 0 ];
			p.splice( j, 0, x );
			rysuj();
			zmiana();
		}
		function rysuj() {
			var poz = M.listy[ pole ];
			var n = poz.length;
			licznik.replaceChildren( document.createTextNode( String( n ) ) );
			if ( ! n ) {
				licznik.appendChild( el( 'small', '', ' · nie pokaże się na karcie' ) );
			}
			box.classList.toggle( 'lista--pusta', n === 0 );
			ul.hidden = n === 0;
			var naKarcie = pole === 'kw_w_cenie' ? ( n > 4 ? 3 : n ) : 0;
			stopka.hidden = pole !== 'kw_w_cenie' || n === 0;
			stopka.textContent = n > 4 ? 'Trzy pierwsze klient widzi na karcie od razu, resztę po rozwinięciu szczegółów.' : 'Wszystkie widać na karcie od razu (do czterech).';
			var pelno = n >= LIMIT_POZYCJI;
			if ( pelno ) {
				dodajBtn.hidden = true;
				wej.hidden = true;
			} else if ( wej.hidden ) {
				dodajBtn.hidden = false;
			}
			dodaj.setAttribute( 'data-pelna', pelno ? 'Lista jest pełna: 20 pozycji.' : '' );
			ul.replaceChildren();
			poz.forEach( function ( t, i ) {
				var li = el( 'li', 'lista__poz' + ( i < naKarcie ? ' is-na-karcie' : '' ) );
				var uchwyt = ikbtn( 'lista__uchwyt', 'uchwyt', 'Przesuń pozycję ' + ( i + 1 ) + ': strzałki w górę i w dół' );
				uchwyt.addEventListener( 'keydown', function ( ev ) {
					var d = { ArrowUp: -1, ArrowDown: 1 }[ ev.key ];
					if ( d ) {
						ev.preventDefault();
						przesun( i, i + d );
						var b = qa( '.lista__uchwyt', ul )[ i + d ];
						if ( b ) {
							b.focus();
						}
						powiedz( d < 0 ? 'Przesunięto wyżej' : 'Przesunięto niżej' );
					}
				} );
				// Przeciaganie tylko za uchwyt: w polu tekstowym mysz zaznacza tekst.
				uchwyt.addEventListener( 'pointerdown', function () {
					li.draggable = true;
				} );
				var tekst = el( 'textarea', 'lista__tekst' );
				tekst.autocomplete = 'off';
				tekst.rows = 1;
				tekst.maxLength = 200;
				tekst.value = t;
				tekst.setAttribute( 'aria-label', nazwa + ', pozycja ' + ( i + 1 ) + ( pole === 'kw_w_cenie' ? ( i < naKarcie ? ', widoczna na karcie' : ', widoczna po rozwinięciu szczegółów' ) : '' ) );
				tekst.addEventListener( 'input', function () {
					M.listy[ pole ][ i ] = tekst.value.replace( /\s+/g, ' ' );
					rosnij( tekst );
					var z = tekst.nextElementSibling;
					if ( z ) {
						z.replaceWith( znaczekBib( pole, tekst.value ) );
					}
					zmiana();
				} );
				tekst.addEventListener( 'keydown', function ( ev ) {
					if ( ev.key === 'Enter' ) {
						ev.preventDefault();
						tekst.blur();
					} else if ( ev.key === 'Backspace' && tekst.value === '' ) {
						ev.preventDefault();
						usun( i );
					}
				} );
				tekst.addEventListener( 'blur', function () {
					if ( M.listy[ pole ][ i ] !== undefined && ! M.listy[ pole ][ i ].trim() ) {
						M.listy[ pole ].splice( i, 1 );
						rysuj();
						zmiana();
					}
				} );
				var usunBtn = ikbtn( 'ikbtn ikbtn--usun', 'usun', 'Usuń pozycję: ' + t );
				usunBtn.addEventListener( 'click', function () {
					usun( i );
				} );
				li.appendChild( uchwyt );
				li.appendChild( tekst );
				li.appendChild( znaczekBib( pole, t ) );
				li.appendChild( usunBtn );
				li.addEventListener( 'dragstart', function ( ev ) {
					ciagnie = i;
					li.classList.add( 'is-ciagnie' );
					document.body.classList.add( 'kw-is-drag' );
					var kafel = box.closest( '.kf' );
					if ( kafel ) {
						kafel.classList.add( 'is-zrodlo' );
					}
					ev.dataTransfer.effectAllowed = 'move';
					ev.dataTransfer.setData( 'text/plain', t );
				} );
				li.addEventListener( 'dragend', function () {
					ciagnie = -1;
					li.draggable = false;
					document.body.classList.remove( 'kw-is-drag' );
					qa( '.is-zrodlo' ).forEach( function ( x ) {
						x.classList.remove( 'is-zrodlo' );
					} );
					qa( '.lista__poz', ul ).forEach( function ( x ) {
						x.classList.remove( 'is-ciagnie', 'is-nad' );
					} );
				} );
				li.addEventListener( 'dragover', function ( ev ) {
					if ( ciagnie >= 0 ) {
						ev.preventDefault();
						li.classList.toggle( 'is-nad', ciagnie !== i );
					}
				} );
				li.addEventListener( 'dragleave', function () {
					li.classList.remove( 'is-nad' );
				} );
				li.addEventListener( 'drop', function ( ev ) {
					ev.preventDefault();
					if ( ciagnie >= 0 && ciagnie !== i ) {
						var z = ciagnie;
						ciagnie = -1;
						przesun( z, i );
					}
				} );
				ul.appendChild( li );
				if ( pole === 'kw_w_cenie' && n > 4 && i === 2 ) {
					var podzial = el( 'li', 'lista__podzial', 'Dalsze po rozwinięciu szczegółów karty' );
					podzial.setAttribute( 'aria-hidden', 'true' );
					ul.appendChild( podzial );
				}
				window.requestAnimationFrame( function () {
					rosnij( tekst );
				} );
			} );
		}
		function usun( i ) {
			var byla = M.listy[ pole ].splice( i, 1 )[ 0 ];
			rysuj();
			zmiana();
			var cel = qa( '.lista__tekst', ul )[ Math.min( i, M.listy[ pole ].length - 1 ) ];
			( cel || dodajBtn ).focus();
			powiedz( 'Usunięto pozycję z listy: ' + nazwa );
			if ( byla && byla.trim() ) {
				// Bez okna potwierdzenia: pozycja wraca przyciskiem Cofnij w powiadomieniu.
				powiadom( 'Usunięto: ' + byla, function () {
					M.listy[ pole ].splice( Math.min( i, M.listy[ pole ].length ), 0, byla );
					rysuj();
					zmiana();
					powiedz( 'Przywrócono pozycję: ' + byla );
				} );
			}
		}
		przerysujListy[ pole ] = rysuj;
		rysuj();
	}

	/* ---------------------------------------------------------------------
	 * Progi cenowe: tabela liczb (od, do, cena) albo os z suwakami, narzedzie "Zmien ceny progow hurtem"
	 * ------------------------------------------------------------------ */

	var ui = { trybProgow: 'tabela' };
	try {
		// Na telefonie zawsze tabela (os jest ciasna); os tylko na szerszym ekranie, gdy ktos ja wybral.
		if ( window.localStorage.getItem( 'kwWidokProgow' ) === 'os' && window.innerWidth >= 783 ) {
			ui.trybProgow = 'os';
		}
	} catch ( x ) {}
	var zm = { znak: 1, wartosc: '', tryb: 'proc', podglad: null, status: '', token: 0 };
	var historia = { cofnij: [], ponow: [] };
	var polaProgow = []; // pola tabeli wiersza i: { od, do, cena } (do fokusu z listy bledow)
	var rysujProgi = function () {};
	var obserwatorzyProgow = []; // odswiezenie widoku progow po kazdej zmianie (bez przebudowy pol pod palcami)

	function migawka() {
		var min = q( '#kw-cena-min' );
		return {
			progi: M.progi.map( function ( p ) {
				return Object.assign( {}, p );
			} ),
			min: min ? min.value : null
		};
	}
	function przywroc( z ) {
		M.progi = z.progi;
		var min = q( '#kw-cena-min' );
		if ( min && z.min !== null ) {
			min.value = z.min;
		}
	}
	function liczbaPola( v ) {
		var s = String( v ).trim();
		return /^\d{1,5}$/.test( s ) ? parseInt( s, 10 ) : ( s === '' ? '' : NaN );
	}
	// Bledy progow: kazdy blokuje zapis (jak w 1.3.0); "rodzaj" decyduje tylko o kolorze komunikatu przy wierszu.
	function bledyProgow() {
		var b = [];
		var p = M.progi;
		function dodaj( i, poleProgu, tekst, rodzaj ) {
			b.push( { i: i, pole: poleProgu, tekst: tekst, rodzaj: rodzaj || 'blad' } );
		}
		p.forEach( function ( x, i ) {
			var nr = 'Próg ' + ( i + 1 ) + ': ';
			var ostatni = i === p.length - 1;
			var c = String( x.cena ).trim();
			var okZakres = true;
			if ( x.zly ) {
				dodaj( i, 'od', nr + 'nie rozumiem zakresu „' + x.et + '”. Wpisz liczby w polach Od i Do.' );
				okZakres = false;
			} else if ( x.od === '' || ! isFinite( x.od ) ) {
				dodaj( i, 'od', nr + 'wpisz, od ilu dokumentów zaczyna się ten próg (sama liczba).' );
				okZakres = false;
			} else if ( x.do === '' || ( x.do !== null && ! isFinite( x.do ) ) ) {
				dodaj( i, 'do', nr + ( ostatni ? 'wpisz, na ilu dokumentach kończy się próg, albo wybierz niżej „i więcej”.' : 'wpisz, na ilu dokumentach kończy się ten próg (sama liczba).' ) );
				okZakres = false;
			} else if ( x.do !== null && x.do < x.od ) {
				dodaj( i, 'do', nr + 'koniec (' + x.do + ') jest mniejszy niż początek (' + x.od + ').' );
				okZakres = false;
			} else if ( x.do === null && ! ostatni ) {
				dodaj( i, 'do', nr + '„i więcej” może mieć tylko ostatni próg.' );
				okZakres = false;
			}
			if ( c === '' ) {
				dodaj( i, 'cena', nr + 'dopisz cenę albo usuń ten wiersz.', 'uwaga' );
			} else if ( czyscKwote( c ) === '' ) {
				dodaj( i, 'cena', nr + 'cena to sama liczba, np. 700.' );
			}
			var pop = p[ i - 1 ];
			if ( i > 0 && okZakres && pop && ! pop.zly && isFinite( pop.do ) && pop.do !== null && pop.do !== '' ) {
				if ( x.od <= pop.do ) {
					dodaj( i, 'od', 'Nakładanie: próg ' + ( i + 1 ) + ' (' + etykieta( x ) + ') zaczyna się, zanim skończy się próg ' + i + ' (' + etykieta( pop ) + '). Klient nie wie, która cena obowiązuje.' );
				} else if ( x.od > pop.do + 1 ) {
					dodaj( i, 'od', 'Luka: liczby ' + ( pop.do + 1 ) + ( x.od - 1 > pop.do + 1 ? '-' + ( x.od - 1 ) : '' ) + ' nie mają ceny (między progiem ' + i + ' a ' + ( i + 1 ) + ').', 'uwaga' );
				}
			}
			if ( i > 0 && czyscKwote( c ) !== '' && pop && czyscKwote( pop.cena ) !== '' && parseFloat( czyscKwote( c ) ) < parseFloat( czyscKwote( pop.cena ) ) ) {
				dodaj( i, 'cena', nr + 'cena (' + czyscKwote( c ) + ' zł) jest niższa niż w poprzednim progu (' + czyscKwote( pop.cena ) + ' zł). Więcej dokumentów nie może kosztować mniej.' );
			}
		} );
		return b;
	}
	function zakresZmieniony( p ) {
		p.et = null;
		p.zly = false;
	}
	// Etykieta do wyswietlenia (tez dla wiersza z nieczytelnym zakresem z bazy).
	function opisZakresu( p ) {
		return etykieta( p ) || '?';
	}

	function stopkaOstatniego() {
		var ost = M.progi[ M.progi.length - 1 ];
		if ( ! ost ) {
			return null;
		}
		var w = el( 'div', 'pt__stopka' );
		w.appendChild( el( 'span', '', 'Ostatni próg' ) );
		var seg = el( 'div', 'kw-pigulki' );
		seg.setAttribute( 'role', 'group' );
		seg.setAttribute( 'aria-label', 'Jak kończy się ostatni próg' );
		[ [ 'do', 'kończy się liczbą' ], [ 'w', 'i więcej' ] ].forEach( function ( o ) {
			var b = el( 'button', '', o[ 1 ] );
			b.type = 'button';
			b.setAttribute( 'aria-pressed', String( ( ost.do === null ) === ( o[ 0 ] === 'w' ) ) );
			b.addEventListener( 'click', function () {
				if ( o[ 0 ] === 'w' && ost.do !== null ) {
					ost.do = null;
					ost.odCena = true;
				} else if ( o[ 0 ] === 'do' && ost.do === null ) {
					ost.do = ( isFinite( ost.od ) && ost.od !== '' ? ost.od : 1 ) + 49;
				} else {
					return;
				}
				zakresZmieniony( ost );
				rysujProgi();
				zmiana();
			} );
			seg.appendChild( b );
		} );
		w.appendChild( seg );
		var lab = el( 'label', 'kw-wybor kw-wybor--maly' );
		var cb = el( 'input' );
		cb.type = 'checkbox';
		cb.checked = ost.odCena;
		cb.addEventListener( 'change', function () {
			ost.odCena = cb.checked;
			zmiana();
		} );
		lab.appendChild( cb );
		lab.appendChild( el( 'span', '', 'cena „od” przy ostatnim progu' ) );
		w.appendChild( lab );
		obserwatorzyProgow.push( function () {
			cb.checked = ost.odCena;
		} );
		return w;
	}

	function rysujTabele() {
		var t = el( 'div', 'pt' );
		t.setAttribute( 'role', 'group' );
		t.setAttribute( 'aria-label', 'Progi cenowe: tabela' );
		var h = el( 'div', 'pt__rzad pt__h' );
		var zz = el( 'div', 'pt__zazn' );
		var wszystkie = el( 'input' );
		wszystkie.type = 'checkbox';
		wszystkie.setAttribute( 'aria-label', 'Zaznacz wszystkie progi do zmiany ceny' );
		wszystkie.addEventListener( 'change', function () {
			M.progi.forEach( function ( p ) {
				p.wyb = wszystkie.checked;
			} );
			zmianaWyboru();
		} );
		zz.appendChild( wszystkie );
		h.appendChild( zz );
		[ [ 'Od', '' ], [ 'Do', '' ], [ 'Cena', 'pt__cena-h' ], [ 'Cena „od”', 'pt__od-h' ] ].forEach( function ( x ) {
			h.appendChild( el( 'span', x[ 1 ], x[ 0 ] ) );
		} );
		h.appendChild( el( 'span', 'screen-reader-text', 'Akcje' ) );
		t.appendChild( h );
		polaProgow = [];
		M.progi.forEach( function ( p, i ) {
			var ostatni = i === M.progi.length - 1;
			var r = el( 'div', 'pt__rzad' );
			r.setAttribute( 'role', 'group' );
			r.setAttribute( 'aria-label', 'Próg ' + ( i + 1 ) );
			var bid = nowyId( 'prog-blad' );
			var zazn = el( 'input' );
			zazn.type = 'checkbox';
			zazn.checked = p.wyb;
			zazn.setAttribute( 'aria-label', 'Zaznacz próg ' + ( i + 1 ) + ' do zmiany ceny' );
			zazn.addEventListener( 'change', function () {
				p.wyb = zazn.checked;
				zmianaWyboru();
			} );
			var pole = function ( wartosc, etk, tryb ) {
				var x = el( 'input' );
				x.type = 'text';
				x.autocomplete = 'off';
				x.inputMode = tryb;
				x.maxLength = tryb === 'numeric' ? 5 : 12;
				x.value = wartosc;
				x.setAttribute( 'aria-label', etk );
				x.setAttribute( 'aria-describedby', bid );
				return x;
			};
			var od = pole( p.od === '' ? '' : String( p.od ), 'Próg ' + ( i + 1 ) + ': od ilu dokumentów', 'numeric' );
			var dol = pole( p.do === null || p.do === '' ? '' : String( p.do ), 'Próg ' + ( i + 1 ) + ': do ilu dokumentów', 'numeric' );
			var chip = el( 'span', 'pt__chip', 'i więcej' );
			var cenaI = pole( String( p.cena ), 'Próg ' + ( i + 1 ) + ': cena w złotych', 'decimal' );
			od.addEventListener( 'input', function () {
				p.od = liczbaPola( od.value );
				zakresZmieniony( p );
				zmiana();
			} );
			dol.addEventListener( 'input', function () {
				p.do = liczbaPola( dol.value );
				zakresZmieniony( p );
				zmiana();
			} );
			cenaI.addEventListener( 'input', function () {
				p.cena = cenaI.value;
				zmianaWyboru(); // podglad "przed i po" liczy sie od nowej ceny
				zmiana();
			} );
			var odL = el( 'label' );
			var odC = el( 'input' );
			odC.type = 'checkbox';
			odC.checked = p.odCena;
			odC.addEventListener( 'change', function () {
				p.odCena = odC.checked;
				zmiana();
			} );
			odL.appendChild( odC );
			odL.appendChild( el( 'span', '', '„od”' ) );
			odL.appendChild( el( 'span', 'screen-reader-text', ' przed ceną progu ' + ( i + 1 ) ) );
			var pod = el( 'div', 'pt__pod' );
			var blad = el( 'div', 'pt__blad' );
			blad.id = bid;
			var c1 = el( 'div', 'pt__zazn' );
			c1.appendChild( zazn );
			var c2 = el( 'div', 'pt__odp' );
			c2.setAttribute( 'data-et', 'Od' );
			c2.appendChild( od );
			var c3 = el( 'div', 'pt__do' );
			c3.setAttribute( 'data-et', 'Do' );
			c3.appendChild( dol );
			c3.appendChild( chip );
			var c4 = el( 'div', 'pt__cena' );
			c4.setAttribute( 'data-et', 'Cena' );
			var kw = el( 'span', 'kwota' );
			kw.appendChild( cenaI );
			var zlS = el( 'span', 'kwota__zl', 'zł' );
			zlS.setAttribute( 'aria-hidden', 'true' );
			kw.appendChild( zlS );
			c4.appendChild( kw );
			var c5 = el( 'div', 'pt__od' );
			c5.appendChild( odL );
			var c6 = el( 'div', 'pt__akcje' );
			var gora = ikbtn( 'ikbtn', 'gora', 'Przesuń próg ' + ( i + 1 ) + ' wyżej' );
			var dolB = ikbtn( 'ikbtn', 'dol', 'Przesuń próg ' + ( i + 1 ) + ' niżej' );
			var usunB = ikbtn( 'ikbtn ikbtn--usun', 'usun', 'Usuń próg ' + ( i + 1 ) );
			gora.disabled = i === 0;
			dolB.disabled = ostatni;
			var zamien = function ( j ) {
				var x = M.progi[ i ];
				M.progi[ i ] = M.progi[ j ];
				M.progi[ j ] = x;
				rysujProgi();
				zmiana();
				var cel = polaProgow[ j ];
				if ( cel ) {
					var bs = qa( '.pt__akcje .ikbtn', cel.rzad );
					var pref = j < i ? bs[ 0 ] : bs[ 1 ];
					( pref.disabled ? ( j < i ? bs[ 1 ] : bs[ 0 ] ) : pref ).focus();
				}
				powiedz( j < i ? 'Przesunięto wyżej' : 'Przesunięto niżej' );
			};
			gora.addEventListener( 'click', function () {
				zamien( i - 1 );
			} );
			dolB.addEventListener( 'click', function () {
				zamien( i + 1 );
			} );
			usunB.addEventListener( 'click', function () {
				var przed = migawka();
				var opis = opisZakresu( p );
				M.progi.splice( i, 1 );
				rysujProgi();
				zmiana();
				var cel = polaProgow[ Math.min( i, M.progi.length - 1 ) ];
				( cel ? cel.cena : q( '[data-kw-dodaj-prog]' ) ).focus();
				powiedz( 'Usunięto próg ' + opis );
				// Bez okna potwierdzenia: prog wraca przyciskiem Cofnij w powiadomieniu.
				powiadom( 'Usunięto próg ' + opis + '.', function () {
					przywroc( przed );
					rysujProgi();
					zmiana();
					powiedz( 'Przywrócono próg ' + opis );
				} );
			} );
			c6.appendChild( gora );
			c6.appendChild( dolB );
			c6.appendChild( usunB );
			[ c1, c2, c3, c4, c5, c6, pod, blad ].forEach( function ( x ) {
				r.appendChild( x );
			} );
			t.appendChild( r );
			polaProgow[ i ] = { rzad: r, od: od, do: dol, cena: cenaI };
			obserwatorzyProgow.push( function ( bp ) {
				zazn.checked = p.wyb;
				odC.checked = p.odCena;
				if ( document.activeElement !== cenaI ) {
					cenaI.value = String( p.cena );
				}
				var otwarty = p.do === null && ostatni;
				dol.hidden = otwarty;
				chip.hidden = ! otwarty;
				var moje = bp.filter( function ( x ) {
					return x.i === i;
				} );
				blad.replaceChildren();
				moje.forEach( function ( x ) {
					blad.appendChild( el( 'span', 'kw-tag kw-tag--l kw-tag--' + x.rodzaj, x.tekst ) );
				} );
				blad.hidden = ! moje.length;
				[ [ od, 'od' ], [ dol, 'do' ], [ cenaI, 'cena' ] ].forEach( function ( para ) {
					var zle = moje.some( function ( x ) {
						return x.pole === para[ 1 ] && x.rodzaj === 'blad';
					} );
					if ( zle ) {
						para[ 0 ].setAttribute( 'aria-invalid', 'true' );
					} else {
						para[ 0 ].removeAttribute( 'aria-invalid' );
					}
				} );
				var nowa = zm.podglad ? zm.podglad[ i ] : undefined;
				var c = parseFloat( czyscKwote( p.cena ) );
				pod.replaceChildren();
				if ( nowa !== undefined && isFinite( c ) ) {
					pod.appendChild( el( 's', '', zl( c ) ) );
					pod.appendChild( document.createTextNode( ' ' ) );
					pod.appendChild( el( 'span', 'cena-po ' + ( nowa >= c ? 'cena-po--plus' : 'cena-po--minus' ), zl( nowa ) ) );
				}
				pod.hidden = ! pod.firstChild;
			} );
		} );
		obserwatorzyProgow.push( function () {
			var n = M.progi.filter( function ( p ) {
				return p.wyb;
			} ).length;
			wszystkie.checked = n > 0 && n === M.progi.length;
			wszystkie.indeterminate = n > 0 && n < M.progi.length;
		} );
		var st = stopkaOstatniego();
		if ( st ) {
			t.appendChild( st );
		}
		return t;
	}

	// Os: punkt = koniec zakresu, liczba jedzie z punktem, cena nad odcinkiem (edytowalna). Os dziala, gdy zakresy ida po
	// kolei bez dziur i nakladek; inaczej pokazuje, co poprawic, i przycisk "Ulóż zakresy po kolei" (jak w 1.3.0).
	var WYKL = 0.6;
	function osDziala() {
		var p = M.progi;
		for ( var i = 0; i < p.length; i++ ) {
			var x = p[ i ];
			if ( x.zly || x.od === '' || ! isFinite( x.od ) || x.do === '' || ( x.do !== null && ( ! isFinite( x.do ) || x.do < x.od ) ) || ( x.do === null && i < p.length - 1 ) || ( i > 0 && x.od !== p[ i - 1 ].do + 1 ) ) {
				return false;
			}
		}
		return true;
	}
	function ulozPoKolei() {
		var od = M.progi.length && isFinite( M.progi[ 0 ].od ) && M.progi[ 0 ].od !== '' ? M.progi[ 0 ].od : 1;
		M.progi.forEach( function ( x, i ) {
			var ostatni = i === M.progi.length - 1;
			var d = ostatni && x.do === null ? null : ( isFinite( x.do ) && x.do !== '' && x.do !== null && x.do >= od ? x.do : od + 9 );
			if ( x.od !== od || x.do !== d ) {
				x.od = od;
				x.do = d;
				zakresZmieniony( x );
			}
			od = d === null ? od : d + 1;
		} );
	}
	function rysujOs() {
		var w = el( 'div', 'os' );
		if ( ! M.progi.length ) {
			w.appendChild( el( 'p', 'kw-pomoc', 'Dodaj pierwszy próg przyciskiem „Dodaj próg”.' ) );
			return w;
		}
		if ( ! osDziala() ) {
			w.appendChild( el( 'p', 'kw-pomoc', 'Oś działa, gdy zakresy idą po kolei, bez dziur i nakładek. Popraw je w tabeli (podpowiedź stoi przy wierszu) albo ułóż je automatycznie.' ) );
			var b = el( 'button', 'kw-btn kw-btn--maly', 'Ułóż zakresy po kolei' );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				ulozPoKolei();
				rysujProgi();
				zmiana();
				powiedz( 'Zakresy ułożone po kolei.' );
			} );
			w.appendChild( b );
			return w;
		}
		var polePl = el( 'div', 'os__pole' );
		var tor = el( 'div', 'os__tor' );
		polePl.appendChild( tor );
		var n = M.progi.length;
		var maxOs = function () {
			var ost = M.progi[ n - 1 ];
			var kon = ost.do === null ? ost.od + 60 : ost.do;
			return Math.max( 50, Math.ceil( kon * 1.15 / 50 ) * 50 );
		};
		var MX = maxOs();
		var poz = function ( v ) {
			return Math.pow( Math.max( 0, v ) / MX, WYKL ) * 100;
		};
		var wart = function ( pct ) {
			return Math.round( MX * Math.pow( Math.min( 1, Math.max( 0, pct ) ), 1 / WYKL ) );
		};
		var segi = [];
		var ceny = [];
		var uchwyty = [];
		var liczby = [];
		M.progi.forEach( function ( p, i ) {
			var seg = el( 'button', 'os__seg' );
			seg.type = 'button';
			seg.addEventListener( 'click', function () {
				p.wyb = ! p.wyb;
				zmianaWyboru();
			} );
			var cInp = el( 'input' );
			cInp.type = 'text';
			cInp.autocomplete = 'off';
			cInp.inputMode = 'decimal';
			cInp.maxLength = 12;
			cInp.value = String( p.cena );
			cInp.setAttribute( 'aria-label', 'Cena progu ' + ( i + 1 ) + ' w złotych' );
			cInp.addEventListener( 'input', function () {
				p.cena = cInp.value;
				zmianaWyboru();
				zmiana();
			} );
			var c = el( 'span', 'os__cena' );
			var kw = el( 'span', 'kwota' );
			kw.appendChild( cInp );
			var zlS = el( 'span', 'kwota__zl', 'zł' );
			zlS.setAttribute( 'aria-hidden', 'true' );
			kw.appendChild( zlS );
			c.appendChild( kw );
			c.appendChild( el( 'span', 'os__cena-pod' ) );
			c.appendChild( el( 'small' ) );
			segi.push( seg );
			ceny.push( { el: c, inp: cInp } );
			polePl.appendChild( seg );
			polePl.appendChild( c );
			if ( p.do !== null ) {
				var u = el( 'button', 'os__uchwyt' );
				u.type = 'button';
				u.setAttribute( 'role', 'slider' );
				u.setAttribute( 'aria-label', 'Koniec progu ' + ( i + 1 ) );
				var l = el( 'span', 'os__liczba' );
				uchwyty[ i ] = u;
				liczby[ i ] = l;
				polePl.appendChild( u );
				polePl.appendChild( l );
				var ustaw = function ( v ) {
					var nast = M.progi[ i + 1 ];
					var max = nast && nast.do !== null ? nast.do - 1 : MX - 1;
					v = Math.min( Math.max( v, p.od ), Math.max( p.od, max ) );
					if ( v === p.do ) {
						return;
					}
					p.do = v;
					zakresZmieniony( p );
					if ( nast ) {
						nast.od = v + 1;
						zakresZmieniony( nast );
					}
					zmiana();
				};
				u.addEventListener( 'pointerdown', function ( ev ) {
					u.setPointerCapture( ev.pointerId );
					u.setAttribute( 'data-ciagnie', '1' );
				} );
				u.addEventListener( 'pointermove', function ( ev ) {
					if ( u.hasAttribute( 'data-ciagnie' ) ) {
						var r = tor.getBoundingClientRect();
						ustaw( wart( ( ev.clientX - r.left ) / r.width ) );
					}
				} );
				[ 'pointerup', 'pointercancel' ].forEach( function ( typ ) {
					u.addEventListener( typ, function () {
						u.removeAttribute( 'data-ciagnie' );
					} );
				} );
				u.addEventListener( 'keydown', function ( ev ) {
					var d = { ArrowLeft: -1, ArrowDown: -1, ArrowRight: 1, ArrowUp: 1, PageDown: -10, PageUp: 10 }[ ev.key ];
					if ( d ) {
						ev.preventDefault();
						ustaw( p.do + d );
					}
				} );
			}
		} );
		var start = el( 'span', 'os__krancowa', String( M.progi[ 0 ].od ) );
		polePl.appendChild( start );
		w.appendChild( polePl );
		w.appendChild( el( 'p', 'kw-pomoc', 'Przeciągnij punkt albo użyj strzałek. Cenę wpisujesz nad odcinkiem; klik w odcinek zaznacza próg do zmiany ceny. Oś jest zagęszczona po prawej, żeby małe zakresy były czytelne.' ) );
		var st = stopkaOstatniego();
		if ( st ) {
			w.appendChild( st );
		}
		function ustawPozycje() {
			if ( ! w.isConnected || ! osDziala() || M.progi.length !== n ) {
				return;
			}
			MX = maxOs();
			var waska = w.clientWidth < 620;
			w.classList.toggle( 'os--waska', waska );
			start.style.left = poz( M.progi[ 0 ].od - 1 ) + '%';
			M.progi.forEach( function ( p, i ) {
				var a = poz( p.od - 1 );
				var b = p.do === null ? 100 : poz( p.do );
				ceny[ i ].el.classList.toggle( 'os__cena--gora', waska && i % 2 === 1 );
				if ( liczby[ i ] ) {
					liczby[ i ].classList.toggle( 'os__liczba--dol', waska && i % 2 === 1 );
				}
				segi[ i ].style.left = a + '%';
				segi[ i ].style.width = Math.max( 0.5, b - a ) + '%';
				segi[ i ].setAttribute( 'aria-pressed', String( p.wyb ) );
				segi[ i ].setAttribute( 'aria-label', 'Próg ' + ( i + 1 ) + ': ' + opisZakresu( p ) + ', ' + ( czyscKwote( p.cena ) === '' ? 'brak ceny' : zl( czyscKwote( p.cena ) ) ) + '. Zaznacz do zmiany ceny' );
				ceny[ i ].el.style.left = ( a + b ) / 2 + '%';
				if ( document.activeElement !== ceny[ i ].inp ) {
					ceny[ i ].inp.value = String( p.cena );
				}
				var nowa = zm.podglad ? zm.podglad[ i ] : undefined;
				var podC = q( '.os__cena-pod', ceny[ i ].el );
				podC.replaceChildren();
				if ( nowa !== undefined ) {
					podC.appendChild( el( 'span', 'cena-po ' + ( nowa >= parseFloat( czyscKwote( p.cena ) ) ? 'cena-po--plus' : 'cena-po--minus' ), ( p.odCena ? 'od ' : '' ) + zl( nowa ) ) );
				}
				var maly = q( 'small', ceny[ i ].el );
				maly.textContent = ( p.odCena ? 'od, ' : '' ) + opisZakresu( p );
				maly.hidden = nowa !== undefined;
				if ( uchwyty[ i ] ) {
					uchwyty[ i ].style.left = poz( p.do ) + '%';
					uchwyty[ i ].setAttribute( 'aria-valuenow', String( p.do ) );
					uchwyty[ i ].setAttribute( 'aria-valuemin', String( p.od ) );
					uchwyty[ i ].setAttribute( 'aria-valuemax', String( MX ) );
					uchwyty[ i ].setAttribute( 'aria-valuetext', p.do + ' dokumentów' );
					liczby[ i ].style.left = poz( p.do ) + '%';
					liczby[ i ].textContent = String( p.do );
				}
			} );
		}
		obserwatorzyProgow.push( ustawPozycje );
		window.requestAnimationFrame( ustawPozycje );
		return w;
	}

	// Narzedzie: Dodac / Odjac, liczba, Procent / Kwota w zl, X, Przelicz, Zmien ceny, Cofnij, Ponow. Podglad "przed i po"
	// liczy sie sam sekunde po wpisaniu; bezpiecznik: liczenie dluzsze niz 5 s przerywa podglad.
	function budujZmianeCen() {
		var hid = nowyId( 'zmiana' );
		var w = el( 'div', 'zmiana' );
		w.setAttribute( 'role', 'group' );
		w.setAttribute( 'aria-labelledby', hid );
		w.setAttribute( 'data-kw-progi-zmiana', '' );
		var h = el( 'p', 'zmiana__h', 'Zmień ceny progów hurtem' );
		h.id = hid;
		h.appendChild( el( 'small', '', 'Zaznacz progi w tabeli albo na osi. Podgląd liczy się sam, sekundę po wpisaniu; ceny zmienią się dopiero po „Zmień ceny”.' ) );
		var kier = el( 'div', 'kier' );
		kier.setAttribute( 'role', 'group' );
		kier.setAttribute( 'aria-label', 'Kierunek zmiany' );
		var bPlus = ikbtn( '', 'plus', '', 'Dodać' );
		bPlus.setAttribute( 'data-kier', 'plus' );
		var bMinus = ikbtn( '', 'nie', '', 'Odjąć' );
		bMinus.setAttribute( 'data-kier', 'minus' );
		kier.appendChild( bPlus );
		kier.appendChild( bMinus );
		var poleW = el( 'span', 'kwota zmiana__pole' );
		var inp = el( 'input' );
		inp.type = 'text';
		inp.autocomplete = 'off';
		inp.inputMode = 'decimal';
		inp.maxLength = 9;
		inp.placeholder = '10';
		inp.setAttribute( 'aria-label', 'O ile zmienić ceny' );
		inp.setAttribute( 'data-kw-zmiana-wartosc', '' );
		var jedn = el( 'span', 'kwota__zl', '%' );
		jedn.setAttribute( 'aria-hidden', 'true' );
		poleW.appendChild( inp );
		poleW.appendChild( jedn );
		var tryb = el( 'div', 'kw-pigulki' );
		tryb.setAttribute( 'role', 'group' );
		tryb.setAttribute( 'aria-label', 'Rodzaj zmiany' );
		var bProc = el( 'button', '', 'Procent' );
		var bZl = el( 'button', '', 'Kwota w zł' );
		bProc.type = 'button';
		bZl.type = 'button';
		tryb.appendChild( bProc );
		tryb.appendChild( bZl );
		var bX = ikbtn( 'ikbtn', 'usun', 'Wyczyść pole i podgląd' );
		var bPrzelicz = el( 'button', 'kw-btn', 'Przelicz' );
		bPrzelicz.type = 'button';
		var status = el( 'span', 'zmiana__status' );
		status.setAttribute( 'aria-live', 'polite' );
		var bZmien = el( 'button', 'kw-btn kw-btn--mocny', 'Zmień ceny' );
		bZmien.type = 'button';
		var bCofnij = ikbtn( 'kw-btn kw-btn--cichy', 'cofnij', '', 'Cofnij zmianę' );
		var bPonow = ikbtn( 'kw-btn kw-btn--cichy', 'ponow', '', 'Ponów zmianę' );
		var info = el( 'p', 'zmiana__info' );
		info.setAttribute( 'aria-live', 'polite' );
		var r1 = el( 'div', 'zmiana__rzad' );
		[ kier, poleW, tryb, bX, bPrzelicz, status ].forEach( function ( x ) {
			r1.appendChild( x );
		} );
		var r2 = el( 'div', 'zmiana__rzad' );
		[ bZmien, bCofnij, bPonow ].forEach( function ( x ) {
			r2.appendChild( x );
		} );
		w.appendChild( h );
		w.appendChild( r1 );
		w.appendChild( r2 );
		w.appendChild( info );

		var czas = null;
		function cele() {
			var z = [];
			M.progi.forEach( function ( p, i ) {
				if ( p.wyb ) {
					z.push( i );
				}
			} );
			return z.length ? z : M.progi.map( function ( p, i ) {
				return i;
			} );
		}
		function nowaCena( c, v ) {
			return Math.max( 0, Math.round( zm.tryb === 'proc' ? c * ( 1 + zm.znak * v / 100 ) : c + zm.znak * v ) );
		}
		function zaplanuj( ms ) {
			clearTimeout( czas );
			zm.token++;
			zm.status = zm.wartosc.trim() ? 'czeka' : '';
			if ( ! zm.wartosc.trim() ) {
				zm.podglad = null;
			}
			odswiezZmiane();
			if ( zm.wartosc.trim() ) {
				czas = setTimeout( przelicz, ms );
			}
		}
		function przelicz() {
			clearTimeout( czas );
			var v = parseFloat( String( zm.wartosc ).replace( ',', '.' ) );
			if ( ! isFinite( v ) || v < 0 ) {
				zm.podglad = null;
				zm.status = zm.wartosc.trim() ? 'zle' : '';
				odswiezZmiane();
				return;
			}
			var token = ++zm.token;
			var lista = cele();
			var wynik = {};
			var t0 = Date.now();
			var i = 0;
			zm.status = 'liczy';
			odswiezZmiane();
			( function krok() {
				if ( token !== zm.token ) {
					return;
				}
				if ( Date.now() - t0 > 5000 ) {
					zm.status = 'zaDlugo';
					zm.podglad = null;
					odswiezZmiane();
					return;
				}
				var koniec = Math.min( i + 4, lista.length );
				for ( ; i < koniec; i++ ) {
					var c = parseFloat( czyscKwote( M.progi[ lista[ i ] ].cena ) );
					if ( isFinite( c ) ) {
						wynik[ lista[ i ] ] = nowaCena( c, v );
					}
				}
				if ( i < lista.length ) {
					setTimeout( krok, 0 );
				} else {
					zm.podglad = Object.keys( wynik ).length ? wynik : null;
					zm.status = zm.podglad ? 'gotowe' : 'brak';
					odswiezZmiane();
				}
			}() );
		}
		function zastosuj() {
			if ( ! zm.podglad ) {
				if ( zm.wartosc.trim() ) {
					przelicz();
					powiadom( 'Najpierw policzę podgląd. Kliknij „Zmień ceny” jeszcze raz.' );
				} else {
					powiadom( 'Wpisz, o ile zmienić ceny.' );
					inp.focus();
				}
				return;
			}
			// Kwota najnizsza przepisana z progow idzie razem z nimi (jak w 1.3.0).
			var minPole = q( '#kw-cena-min' );
			var cenyProgow = function () {
				return M.progi.map( function ( p ) {
					return parseFloat( czyscKwote( p.cena ) );
				} ).filter( isFinite );
			};
			var minByl = cenyProgow().length ? Math.min.apply( null, cenyProgow() ) : NaN;
			var minZProgow = minPole && parseFloat( czyscKwote( minPole.value ) ) === minByl;
			historia.cofnij.push( migawka() );
			historia.ponow = [];
			var n = 0;
			Object.keys( zm.podglad ).forEach( function ( i ) {
				if ( M.progi[ i ] ) {
					M.progi[ i ].cena = String( zm.podglad[ i ] );
					n++;
				}
			} );
			if ( minZProgow ) {
				minPole.value = String( Math.min.apply( null, cenyProgow() ) );
			}
			zm.podglad = null;
			zm.wartosc = '';
			inp.value = '';
			zm.status = '';
			rysujProgi();
			zmiana();
			var opis = 'Zmieniono ceny: ' + ile( n, 'próg', 'progi', 'progów' ) + '. Zapiszą się razem z usługą; „Cofnij zmianę” przywraca poprzednie ceny.';
			powiadom( 'Zmieniono ceny: ' + ile( n, 'próg', 'progi', 'progów' ) + '.' );
			powiedz( opis );
		}
		function cofnijPonow( z, na ) {
			if ( ! z.length ) {
				return false;
			}
			na.push( migawka() );
			przywroc( z.pop() );
			zm.podglad = null;
			rysujProgi();
			zmiana();
			return true;
		}
		bPlus.addEventListener( 'click', function () {
			zm.znak = 1;
			zaplanuj( 0 );
		} );
		bMinus.addEventListener( 'click', function () {
			zm.znak = -1;
			zaplanuj( 0 );
		} );
		bProc.addEventListener( 'click', function () {
			zm.tryb = 'proc';
			zaplanuj( 0 );
		} );
		bZl.addEventListener( 'click', function () {
			zm.tryb = 'zl';
			zaplanuj( 0 );
		} );
		inp.addEventListener( 'input', function () {
			zm.wartosc = inp.value;
			zaplanuj( 1000 );
		} );
		inp.addEventListener( 'keydown', function ( ev ) {
			if ( ev.key === 'Enter' ) {
				ev.preventDefault(); // Enter w tym polu nie wysyla formularza uslugi
				przelicz();
			}
		} );
		bX.addEventListener( 'click', function () {
			zm.wartosc = '';
			inp.value = '';
			zm.podglad = null;
			zm.status = '';
			zm.token++;
			odswiezZmiane();
			inp.focus();
		} );
		bPrzelicz.addEventListener( 'click', przelicz );
		bZmien.addEventListener( 'click', zastosuj );
		bCofnij.addEventListener( 'click', function () {
			if ( cofnijPonow( historia.cofnij, historia.ponow ) ) {
				powiadom( 'Cofnięto ostatnią zmianę cen.' );
				powiedz( 'Cofnięto zmianę cen.' );
			}
		} );
		bPonow.addEventListener( 'click', function () {
			if ( cofnijPonow( historia.ponow, historia.cofnij ) ) {
				powiadom( 'Ponowiono zmianę cen.' );
				powiedz( 'Ponowiono zmianę cen.' );
			}
		} );
		function odswiezZmiane() {
			bPlus.setAttribute( 'aria-pressed', String( zm.znak > 0 ) );
			bMinus.setAttribute( 'aria-pressed', String( zm.znak < 0 ) );
			bProc.setAttribute( 'aria-pressed', String( zm.tryb === 'proc' ) );
			bZl.setAttribute( 'aria-pressed', String( zm.tryb === 'zl' ) );
			jedn.textContent = zm.tryb === 'proc' ? '%' : 'zł';
			var z = M.progi.filter( function ( p ) {
				return p.wyb;
			} ).length;
			var n = M.progi.length;
			w.hidden = ! n;
			info.textContent = ! n ? '' : ( z ? 'Zmiana obejmie ' + z + ' ' + ( z === 1 ? 'zaznaczony' : ( z % 10 >= 2 && z % 10 <= 4 && ( z % 100 < 12 || z % 100 > 14 ) ? 'zaznaczone' : 'zaznaczonych' ) ) + ' ' + slowoProg( z ) + '. Wynik zaokrąglamy do pełnych złotych.' : 'Nic nie zaznaczono: zmiana obejmie wszystkie ' + ile( n, 'próg', 'progi', 'progów' ) + '. Zaznacz kwadraty przy progach, żeby zmienić tylko część.' );
			bCofnij.disabled = ! historia.cofnij.length;
			bPonow.disabled = ! historia.ponow.length;
			bZmien.disabled = ! zm.podglad;
			status.className = 'zmiana__status' + ( { liczy: ' zmiana__status--liczy', gotowe: ' zmiana__status--ok', zaDlugo: ' zmiana__status--blad', zle: ' zmiana__status--blad', brak: ' zmiana__status--blad' }[ zm.status ] || '' );
			status.textContent = { czeka: 'Policzę za chwilę', liczy: 'Liczę podgląd', gotowe: 'Podgląd gotowy: sprawdź ceny i kliknij „Zmień ceny”.', zaDlugo: 'Obliczenia trwają za długo. Kliknij „Przelicz”, żeby policzyć jeszcze raz.', zle: 'Wpisz liczbę, np. 10 albo 12,5.', brak: 'Najpierw wpisz ceny progów.' }[ zm.status ] || '';
			obserwatorzyProgow.forEach( function ( f ) {
				f( bledyProgow() );
			} );
		}
		// Zmiana zaznaczenia albo cen przelicza podglad od nowa (jesli jest co liczyc).
		zmianaWyboru = function () {
			if ( zm.wartosc.trim() ) {
				zaplanuj( 300 );
			} else {
				odswiezZmiane();
			}
		};
		odswiezZmiane();
		return { el: w, odswiez: odswiezZmiane };
	}
	var zmianaWyboru = function () {};

	function budujProgi() {
		var app = q( '[data-kw-progi-app]' );
		var bezjs = q( '[data-kw-progi-bezjs]' );
		if ( ! app ) {
			return null;
		}
		M.progi = wczytajProgi();
		if ( bezjs ) {
			bezjs.remove(); // wiersze bez skryptu nie moga trafic do formularza obok pol z modelu
		}
		poleProgow = el( 'div' );
		poleProgow.hidden = true;
		poleProgow.setAttribute( 'data-kw-progi-pola', '' );
		var pasekP = el( 'div', 'progi__pasek' );
		pasekP.appendChild( el( 'p', 'kw-pomoc', 'Jeden próg to zakres liczby dokumentów w miesiącu i jego cena. Klient widzi tabelę po kliknięciu „Progi cenowe i szczegóły”.' ) );
		var tryb = el( 'div', 'kw-pigulki' );
		tryb.setAttribute( 'role', 'group' );
		tryb.setAttribute( 'aria-label', 'Sposób edycji progów' );
		var przyciskiTrybu = [ [ 'tabela', 'Tabela liczb' ], [ 'os', 'Oś z suwakami' ] ].map( function ( o ) {
			var b = el( 'button', '', o[ 1 ] );
			b.type = 'button';
			b.addEventListener( 'click', function () {
				ui.trybProgow = o[ 0 ];
				try {
					window.localStorage.setItem( 'kwWidokProgow', o[ 0 ] );
				} catch ( x ) {}
				rysujProgi();
			} );
			tryb.appendChild( b );
			return [ b, o[ 0 ] ];
		} );
		pasekP.appendChild( tryb );
		var cialo = el( 'div', 'progi__cialo' );
		var akcje = el( 'div', 'progi__akcje' );
		var bDodaj = ikbtn( 'kw-btn', 'plus', '', 'Dodaj próg' );
		bDodaj.setAttribute( 'data-kw-dodaj-prog', '' );
		var bTypowe = el( 'button', 'kw-btn', 'Wstaw typowe progi' );
		bTypowe.type = 'button';
		bTypowe.setAttribute( 'data-kw-typowe-progi', '' );
		var zestawBib = el( 'span', 'progi__bib' );
		akcje.appendChild( bDodaj );
		akcje.appendChild( bTypowe );
		akcje.appendChild( zestawBib );
		var limit = el( 'p', 'kw-pomoc', 'Najwięcej 12 progów.' );
		limit.hidden = true;
		var pusto = el( 'p', 'kw-pomoc', 'Ta usługa nie ma progów: klient widzi samą kwotę.' );
		var narzedzie = budujZmianeCen();
		var linia = el( 'div', 'kf__linia' );
		[ pasekP, cialo, pusto, akcje, limit, linia, narzedzie.el, poleProgow ].forEach( function ( x ) {
			app.appendChild( x );
		} );

		bDodaj.addEventListener( 'click', function () {
			if ( M.progi.length >= LIMIT_PROGOW ) {
				powiadom( 'Najwięcej 12 progów.' );
				return;
			}
			var ost = M.progi[ M.progi.length - 1 ];
			var nowy;
			if ( ! ost ) {
				nowy = nowyProgModelu( 1, 10, '', false );
			} else if ( ost.do === null && isFinite( ost.od ) && ost.od !== '' ) {
				// Ostatni byl "i więcej": dostaje koniec, nowy przejmuje "i więcej" i jego cene.
				ost.do = ost.od + 49;
				zakresZmieniony( ost );
				nowy = nowyProgModelu( ost.do + 1, null, ost.cena, ost.odCena );
				ost.odCena = false;
			} else {
				var start = isFinite( ost.do ) && ost.do !== '' && ost.do !== null ? ost.do + 1 : '';
				nowy = nowyProgModelu( start, start === '' ? '' : start + 49, '', false );
			}
			M.progi.push( nowy );
			if ( ui.trybProgow === 'os' && ! osDziala() ) {
				ui.trybProgow = 'tabela';
			}
			rysujProgi();
			zmiana();
			var cel = polaProgow[ M.progi.length - 1 ];
			if ( cel ) {
				cel.cena.focus();
			}
		} );
		bTypowe.addEventListener( 'click', function () {
			// Kategoria z wlasnymi progami (ksiegi handlowe w "Spolkach") dostaje swoj zestaw, pozostale domyslny.
			var typowe = ( D.typoweKat && D.typoweKat[ q( '#kw-grupa' ).value ] ) || D.typowe || [];
			var zakresy = typowe.map( zakresProgu ).filter( Boolean );
			if ( ! zakresy.length ) {
				return;
			}
			wstawZestaw( zakresy.map( function ( z ) {
				return [ z.od, z.do === Infinity ? null : z.do ];
			} ), null );
			powiadom( 'Wstawiono ' + ile( zakresy.length, 'typowy próg', 'typowe progi', 'typowych progów' ) + '. Ceny z poprzednich wierszy zostały; „Cofnij zmianę” przywraca poprzednie progi.' );
		} );

		function odswiezZestaw() {
			zestawBib.replaceChildren();
			if ( ! M.progi.length || ! osDziala() || M.progi.some( function ( p ) {
				return czyscKwote( p.cena ) === '';
			} ) ) {
				return;
			}
			var zakresy = M.progi.map( function ( p ) {
				return [ p.od, p.do ];
			} );
			var ceny = M.progi.map( function ( p ) {
				return czyscKwote( p.cena );
			} );
			var wB = ( B.progi || [] ).filter( function ( z ) {
				return JSON.stringify( z.zakresy ) === JSON.stringify( zakresy ) && JSON.stringify( z.ceny ) === JSON.stringify( ceny );
			} )[ 0 ];
			if ( wB && ! dodaneDoBib[ 'progi|' + wB.id ] ) {
				zestawBib.appendChild( el( 'span', 'kw-pomoc', 'Ten zestaw jest w bibliotece.' ) );
				return;
			}
			var b = wB ? ikbtn( 'ikbtn ikbtn--cofnij', 'cofnij', 'Cofnij: usuń ten zestaw progów z biblioteki' ) : ikbtn( 'ikbtn ikbtn--ok', 'tak', 'Dodaj ten zestaw progów (z cenami) do biblioteki' );
			b.addEventListener( 'click', function () {
				b.disabled = true;
				var dane = wB ? wB.id : JSON.stringify( { nazwa: 'Własny zestaw: ' + ile( zakresy.length, 'próg', 'progi', 'progów' ) + ' z cenami', zakresy: zakresy, ceny: ceny } );
				bibZmien( wB ? 'usun' : 'dodaj', 'progi', dane ).then( function ( wynik ) {
					if ( wB ) {
						delete dodaneDoBib[ 'progi|' + wB.id ];
					} else {
						dodaneDoBib[ 'progi|' + wynik.id ] = true;
					}
					odswiezZestaw();
					odswiezBiblioteke();
					powiadom( wB ? 'Usunięto zestaw z biblioteki.' : 'Dodano zestaw progów do biblioteki.' );
				} ).catch( function ( x ) {
					b.disabled = false;
					powiadom( x.message );
				} );
			} );
			zestawBib.appendChild( b );
			zestawBib.appendChild( el( 'span', 'kw-pomoc', wB ? 'Cofnij: zestaw z biblioteki' : 'Dodaj ten zestaw do biblioteki' ) );
		}

		var stalyProgi = function () {
			var n = M.progi.length;
			pusto.hidden = n > 0;
			cialo.hidden = n === 0;
			bDodaj.disabled = n >= LIMIT_PROGOW;
			limit.hidden = n < LIMIT_PROGOW;
			odswiezZestaw();
		};
		rysujProgi = function () {
			przyciskiTrybu.forEach( function ( para ) {
				para[ 0 ].setAttribute( 'aria-pressed', String( ui.trybProgow === para[ 1 ] ) );
			} );
			obserwatorzyProgow = [ stalyProgi ];
			cialo.replaceChildren( ui.trybProgow === 'os' ? rysujOs() : rysujTabele() );
			narzedzie.odswiez();
		};
		rysujProgi();
		return {
			odswiez: function () {
				// Os przebudowuje sie, gdy zmienia sie liczba progow albo zakresy przestaja isc po kolei.
				if ( ui.trybProgow === 'os' && cialo.firstChild && ( cialo.firstChild.querySelectorAll( '.os__seg' ).length !== M.progi.length || ! osDziala() ) ) {
					rysujProgi();
					return;
				}
				narzedzie.odswiez();
			}
		};
	}
	// Zestaw z biblioteki albo typowe progi: zastepuje progi (ceny po kolei z dotychczasowych wierszy, chyba ze zestaw ma
	// wlasne). Poprzednie progi mozna przywrocic przyciskiem "Cofnij zmianę".
	function wstawZestaw( zakresy, ceny ) {
		historia.cofnij.push( migawka() );
		historia.ponow = [];
		var stare = M.progi;
		M.progi = zakresy.slice( 0, LIMIT_PROGOW ).map( function ( z, i ) {
			var c = ceny && ceny[ i ] !== undefined && ceny[ i ] !== '' ? ceny[ i ] : ( stare[ i ] ? stare[ i ].cena : '' );
			return nowyProgModelu( z[ 0 ], z[ 1 ], c, z[ 1 ] === null );
		} );
		rysujProgi();
		zmiana();
	}


	/* ---------------------------------------------------------------------
	 * Biblioteka: panel wysuwany z prawej, wyszukiwarka, filtry, kafle; klik dodaje do uslugi
	 * ------------------------------------------------------------------ */

	var bib = null;
	function otworzBiblioteke( filtr ) {
		if ( ! bib ) {
			var panel = el( 'aside', 'kw-bib' );
			panel.setAttribute( 'role', 'dialog' );
			panel.setAttribute( 'aria-modal', 'true' );
			panel.setAttribute( 'aria-labelledby', 'kw-bib-h' );
			panel.hidden = true;
			var glowa = el( 'div', 'kw-bib__glowa' );
			var gt = el( 'div' );
			var h2 = el( 'h2', '', 'Biblioteka' );
			h2.id = 'kw-bib-h';
			gt.appendChild( h2 );
			gt.appendChild( el( 'p', '', 'Klik dodaje do tej usługi. Pozycje, które już są w usłudze, są wyszarzone.' ) );
			var zamknijB = ikbtn( 'ikbtn', 'usun', 'Zamknij bibliotekę' );
			glowa.appendChild( gt );
			glowa.appendChild( zamknijB );
			var szukaj = el( 'input' );
			szukaj.type = 'search';
			szukaj.placeholder = 'Szukaj, np. JPK, sprawozdanie, progi';
			szukaj.setAttribute( 'aria-label', 'Szukaj w bibliotece' );
			var sz = el( 'div', 'kw-bib__szukaj' );
			sz.appendChild( szukaj );
			var filtry = el( 'div', 'kw-bib__filtry' );
			filtry.setAttribute( 'role', 'group' );
			filtry.setAttribute( 'aria-label', 'Rodzaj' );
			var tresc = el( 'div', 'kw-bib__tresc' );
			panel.appendChild( glowa );
			panel.appendChild( sz );
			panel.appendChild( filtry );
			panel.appendChild( tresc );
			var tlo = el( 'div', 'kw-bib__tlo' );
			tlo.hidden = true;
			document.body.appendChild( tlo );
			document.body.appendChild( panel );
			bib = { panel: panel, tlo: tlo, szukaj: szukaj, filtr: 'wszystko', ostatni: null };
			var zamknij = function () {
				panel.classList.remove( 'is-otwarta' );
				tlo.hidden = true;
				setTimeout( function () {
					if ( ! panel.classList.contains( 'is-otwarta' ) ) {
						panel.hidden = true;
					}
				}, 260 );
				if ( bib.ostatni && bib.ostatni.isConnected ) {
					bib.ostatni.focus();
				}
			};
			bib.zamknij = zamknij;
			zamknijB.addEventListener( 'click', zamknij );
			tlo.addEventListener( 'click', zamknij );
			document.addEventListener( 'keydown', function ( ev ) {
				if ( ev.key === 'Escape' && panel.classList.contains( 'is-otwarta' ) ) {
					ev.preventDefault();
					zamknij();
				}
			} );
			panel.addEventListener( 'keydown', function ( ev ) {
				if ( ev.key === 'Tab' ) {
					// fokus zostaje w panelu (okno modalne)
					var f = qa( 'button:not(:disabled), input', panel ).filter( function ( x ) {
						return x.offsetParent !== null;
					} );
					if ( f.length && ev.shiftKey && document.activeElement === f[ 0 ] ) {
						ev.preventDefault();
						f[ f.length - 1 ].focus();
					} else if ( f.length && ! ev.shiftKey && document.activeElement === f[ f.length - 1 ] ) {
						ev.preventDefault();
						f[ 0 ].focus();
					}
				}
			} );
			szukaj.addEventListener( 'input', function () {
				if ( szukaj.value.trim() ) {
					bib.filtr = 'wszystko';
				}
				rysuj();
			} );
			[ 'wszystko', 'kw_w_cenie', 'kw_dodatkowo', 'kw_nie_obejmuje', 'kw_czynniki', 'progi', 'jednostki' ].forEach( function ( f ) {
				var b = el( 'button', 'kw-bib__filtr', f === 'wszystko' ? 'Wszystko' : BIB_NAZWY[ f ] );
				b.type = 'button';
				b.setAttribute( 'data-f', f );
				b.addEventListener( 'click', function () {
					bib.filtr = f;
					rysuj();
				} );
				filtry.appendChild( b );
			} );
			var mini = function ( nazwaIkony, tekst ) {
				var m = el( 'span', 'kw-bib__mini', tekst );
				if ( nazwaIkony ) {
					m.appendChild( ikona( nazwaIkony ) );
				}
				return m;
			};
			var kafelBib = function ( m, tytul, opis, wylaczony, akcja ) {
				var b = el( 'button', 'kw-bib__kafel' );
				b.type = 'button';
				b.disabled = wylaczony;
				b.appendChild( m );
				var t = el( 'span' );
				t.appendChild( el( 'b', '', tytul ) );
				t.appendChild( el( 'small', '', opis ) );
				b.appendChild( t );
				b.addEventListener( 'click', function () {
					var i = qa( '.kw-bib__kafel', tresc ).indexOf( b );
					akcja();
					rysuj();
					// fokus na tym samym miejscu listy (albo nastepnym aktywnym kaflu), zeby klawiatura nie gubila panelu
					var nast = qa( '.kw-bib__kafel', tresc ).slice( i ).filter( function ( x ) {
						return ! x.disabled;
					} )[ 0 ];
					( nast || szukaj ).focus();
				} );
				return b;
			};
			var rysuj = function () {
				qa( '.kw-bib__filtr', filtry ).forEach( function ( b ) {
					b.setAttribute( 'aria-pressed', String( b.getAttribute( 'data-f' ) === bib.filtr ) );
				} );
				var fraza = szukaj.value.trim().toLowerCase();
				var pasuje = function ( t ) {
					return ! fraza || t.toLowerCase().indexOf( fraza ) >= 0;
				};
				tresc.replaceChildren();
				var ileK = 0;
				var grupa = function ( klucz, kafle ) {
					if ( ! kafle.length ) {
						return;
					}
					ileK += kafle.length;
					var g = el( 'div', 'kw-bib__grupa' );
					g.appendChild( el( 'h3', '', BIB_NAZWY[ klucz ] ) );
					var siatka = el( 'div', 'kw-bib__kafle' );
					kafle.forEach( function ( x ) {
						siatka.appendChild( x );
					} );
					g.appendChild( siatka );
					tresc.appendChild( g );
				};
				LISTY.forEach( function ( pole ) {
					if ( bib.filtr !== 'wszystko' && bib.filtr !== pole ) {
						return;
					}
					var bok = q( '[data-kw-lista="' + pole + '"]' );
					grupa( pole, ( B[ BIB_KLUCZ[ pole ] ] || [] ).filter( pasuje ).map( function ( t ) {
						var jest = ( M.listy[ pole ] || [] ).indexOf( t ) >= 0;
						var pelna = ( M.listy[ pole ] || [] ).length >= LIMIT_POZYCJI;
						return kafelBib( mini( ZNAK_LISTY[ pole ] ), t, jest ? 'Już jest na liście' : ( pelna ? 'Lista jest pełna (20)' : BIB_NAZWY[ pole ] ), jest || pelna || ! bok, function () {
							M.listy[ pole ].push( t );
							przerysujListy[ pole ]();
							zmiana();
							powiadom( 'Dodano: ' + t );
							powiedz( 'Dodano do listy ' + BIB_NAZWY[ pole ] + ': ' + t );
						} );
					} ) );
				} );
				var liczbowa = LICZBOWE.indexOf( zaznaczone( 'kw_cena_typ' ) || '' ) >= 0;
				if ( bib.filtr === 'wszystko' || bib.filtr === 'progi' ) {
					grupa( 'progi', ( B.progi || [] ).filter( function ( z ) {
						return pasuje( z.nazwa + ' progi' );
					} ).map( function ( z ) {
						var opis = opisZestawu( z ) + ( liczbowa ? '' : '. Najpierw wybierz cenę liczbową w kaflu Cena' );
						return kafelBib( mini( 'slupki' ), z.nazwa, opis, ! liczbowa, function () {
							wstawZestaw( z.zakresy, z.ceny );
							powiadom( 'Wstawiono: ' + z.nazwa + ( z.ceny ? '.' : '. Ceny z poprzednich wierszy zostały.' ) );
							powiedz( 'Wstawiono progi: ' + z.nazwa );
						} );
					} ) );
				}
				if ( bib.filtr === 'wszystko' || bib.filtr === 'jednostki' ) {
					var wybor = q( '#kw-jednostka' );
					grupa( 'jednostki', Object.keys( D.jednostkiNazwy || {} ).filter( function ( kl ) {
						return pasuje( D.jednostkiNazwy[ kl ] );
					} ).map( function ( kl ) {
						var jest = wybor.value === kl;
						return kafelBib( mini( '', '/ ' + jednostka( kl ) ), D.jednostkiNazwy[ kl ], jest ? 'Ustawione' : 'na karcie: / ' + jednostka( kl ), jest || ! liczbowa, function () {
							wybor.value = kl;
							pokazPola();
							zmiana();
							powiadom( 'Ustawiono: za ' + D.jednostkiNazwy[ kl ] );
						} );
					} ) );
				}
				if ( ! ileK ) {
					tresc.appendChild( el( 'p', 'kw-bib__pusto', fraza ? 'Nic nie pasuje do „' + szukaj.value.trim() + '”. Wpisz pozycję ręcznie w liście; znaczkiem obok dodasz ją do biblioteki.' : 'Biblioteka jest tu pusta. Własne pozycje dodasz znaczkiem obok pozycji listy.' ) );
				}
			};
			bib.rysuj = rysuj;
			odswiezBiblioteke = function () {
				if ( bib && ! bib.panel.hidden ) {
					rysuj();
				}
			};
		}
		bib.filtr = filtr || 'wszystko';
		bib.szukaj.value = '';
		bib.rysuj();
		bib.ostatni = document.activeElement;
		bib.panel.hidden = false;
		bib.tlo.hidden = false;
		window.requestAnimationFrame( function () {
			bib.panel.classList.add( 'is-otwarta' );
		} );
		bib.szukaj.focus();
	}
	function opisZestawu( z ) {
		var et = function ( p ) {
			return p[ 1 ] === null ? p[ 0 ] + ' i więcej' : p[ 0 ] + '-' + p[ 1 ];
		};
		return et( z.zakresy[ 0 ] ) + ( z.zakresy.length > 1 ? ' … ' + et( z.zakresy[ z.zakresy.length - 1 ] ) : '' ) + ( z.ceny ? ', z cenami' : '' );
	}

	/* ---------------------------------------------------------------------
	 * Miejsce w zakladce: "2. miejsce z 4" i strzalki; menu_order liczony tutaj (srodek miedzy sasiadami)
	 * ------------------------------------------------------------------ */

	var kolejnoscWlasna = D.status !== 'auto-draft';
	function sasiedziZakladki( u ) {
		var inni = ( D.sasiedzi || [] ).filter( function ( s ) {
			return String( s.k ) === u.kategoria && s.id !== D.id;
		} );
		return inni.concat( [ { id: D.id, t: u.tytul || 'Ta usługa', n: u.kolejnosc, ta: true } ] ).sort( function ( a, b ) {
			return a.n - b.n || a.t.localeCompare( b.t, 'pl' );
		} );
	}
	function miejsceTekst( u ) {
		if ( u.kategoria === '0' ) {
			return 'Bez zakładki: wybierz ją w kaflu Podstawy';
		}
		var l = sasiedziZakladki( u );
		var i = l.findIndex( function ( s ) {
			return s.ta;
		} );
		return ( i + 1 ) + '. miejsce z ' + l.length + ' w zakładce ' + u.kategoriaNazwa;
	}
	function przesunMiejsce( d ) {
		var u = stan();
		var l = sasiedziZakladki( u );
		var i = l.findIndex( function ( s ) {
			return s.ta;
		} );
		var j = i + d;
		if ( j < 0 || j >= l.length ) {
			return;
		}
		var inni = l.filter( function ( s ) {
			return ! s.ta;
		} );
		var przed = inni[ j - 1 ];
		var po = inni[ j ];
		var nowa;
		if ( ! przed ) {
			nowa = po.n - 10;
		} else if ( ! po ) {
			nowa = przed.n + 10;
		} else if ( po.n - przed.n >= 2 ) {
			nowa = Math.floor( ( przed.n + po.n ) / 2 );
		} else {
			powiadom( 'Między tymi usługami nie ma wolnego numeru. Ułóż kolejność przeciąganiem na liście usług.' );
			return;
		}
		q( '#kw-kolejnosc' ).value = String( nowa );
		kolejnoscWlasna = true;
		zmiana();
		powiedz( 'Przesunięto: ' + ( j + 1 ) + '. miejsce' );
		window.requestAnimationFrame( function () {
			odswiez();
			var b = qa( '[data-kw-miejsce] .miejsce__akcje button' );
			var cel = b[ d < 0 ? 0 : 1 ];
			( cel && ! cel.disabled ? cel : b[ d < 0 ? 1 : 0 ] || q( '#kw-kolejnosc' ) ).focus();
		} );
	}
	function rysujMiejsce( u ) {
		var box = q( '[data-kw-miejsce]' );
		if ( ! box ) {
			return;
		}
		var nad = box.closest( '.kw-pole' );
		nad.classList.add( 'is-miejsce' );
		box.hidden = false;
		box.replaceChildren();
		box.appendChild( el( 'span', 'kw-etykieta', 'Miejsce w zakładce' ) );
		if ( u.kategoria === '0' ) {
			box.appendChild( el( 'p', 'kw-pomoc', 'Najpierw wybierz zakładkę cennika w kaflu Podstawy.' ) );
			return;
		}
		var l = sasiedziZakladki( u );
		var i = l.findIndex( function ( s ) {
			return s.ta;
		} );
		var h = el( 'p', 'miejsce__h' );
		h.appendChild( el( 'b', '', ( i + 1 ) + '. miejsce z ' + l.length ) );
		h.appendChild( document.createTextNode( ' w zakładce „' + u.kategoriaNazwa + '”' ) );
		box.appendChild( h );
		var ol = el( 'ol' );
		l.forEach( function ( s, n ) {
			var li = el( 'li', s.ta ? 'is-ta' : '' );
			li.appendChild( el( 'span', 'miejsce__nr', ( n + 1 ) + '.' ) );
			li.appendChild( el( 'span', '', s.t + ( s.ta ? ' (ta usługa)' : '' ) ) );
			if ( s.ta ) {
				var a = el( 'span', 'miejsce__akcje' );
				var g = ikbtn( 'ikbtn', 'gora', 'Przesuń tę usługę wyżej w zakładce' );
				var dl = ikbtn( 'ikbtn', 'dol', 'Przesuń tę usługę niżej w zakładce' );
				g.disabled = n === 0;
				dl.disabled = n === l.length - 1;
				g.addEventListener( 'click', function () {
					przesunMiejsce( -1 );
				} );
				dl.addEventListener( 'click', function () {
					przesunMiejsce( 1 );
				} );
				a.appendChild( g );
				a.appendChild( dl );
				li.appendChild( a );
			} else {
				li.appendChild( el( 'span' ) );
			}
			ol.appendChild( li );
		} );
		box.appendChild( ol );
		box.appendChild( el( 'p', 'kw-pomoc', 'Strzałki przesuwają tę usługę wyżej albo niżej. Tak samo stanie w cenniku po zapisaniu.' ) );
	}
	// Nowa usluga: po wyborze kategorii kolejnosc ustawia sie za ostatnia usluga tej zakladki (dopoki nikt jej nie ustawil).
	function podpowiedzKolejnosc() {
		if ( kolejnoscWlasna ) {
			return;
		}
		var kat = q( '#kw-grupa' ).value;
		var numery = ( D.sasiedzi || [] ).filter( function ( s ) {
			return String( s.k ) === kat;
		} ).map( function ( s ) {
			return s.n;
		} );
		if ( numery.length ) {
			q( '#kw-kolejnosc' ).value = String( Math.max.apply( null, numery ) + 10 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Kafle: akordeon (kilka otwartych naraz), streszczenia zwinietych, znacznik stanu
	 * ------------------------------------------------------------------ */

	var kafle = {};
	qa( '[data-kw-kafel]' ).forEach( function ( s ) {
		var id = s.getAttribute( 'data-kw-kafel' );
		var glowaK = q( '[data-kw-kafel-glowa]', s );
		var chevron = q( '.kf__chevron', s );
		if ( chevron && ! chevron.firstChild ) {
			chevron.appendChild( ikona( 'chevron' ) );
		}
		kafle[ id ] = { s: s, glowa: glowaK, tresc: q( '.kf__tresc', s ), stresz: q( '[data-kw-stresz]', s ), tag: q( '[data-kw-stan]', s ) };
	} );
	// Kilka kafli moze byc otwartych naraz (prosba usera 20.10.2026); otwarty = ostatnio otwarty.
	var otwarty = '';
	var otwarte = {};
	function ustawKafel( id, o ) {
		var x = kafle[ id ];
		if ( ! x ) {
			return;
		}
		otwarte[ id ] = o;
		x.s.classList.toggle( 'is-otwarty', o );
		x.glowa.setAttribute( 'aria-expanded', String( o ) );
		x.tresc.hidden = ! o;
		try {
			window.sessionStorage.setItem( 'kwKafel-' + D.id, Object.keys( otwarte ).filter( function ( k ) { return otwarte[ k ]; } ).join( ',' ) );
		} catch ( e ) {}
	}
	function otworzKafel( id, fokus ) {
		otwarty = kafle[ id ] ? id : '';
		if ( ! otwarty ) {
			return;
		}
		ustawKafel( otwarty, true );
		odswiezOs();
		if ( otwarty && fokus !== false ) {
			var x = kafle[ otwarty ];
			window.requestAnimationFrame( function () {
				var r = x.s.getBoundingClientRect();
				if ( r.top < 0 || r.top > window.innerHeight * 0.6 ) {
					x.s.scrollIntoView( { block: 'start', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
				}
				if ( fokus !== 'glowa' ) {
					var pierwsze = qa( 'input:not([type="hidden"]), select, textarea, button', x.tresc ).filter( function ( p ) {
						return p.offsetParent !== null && ! p.disabled;
					} )[ 0 ];
					( pierwsze || x.glowa ).focus( { preventScroll: true } );
				}
			} );
		}
	}
	function odswiezOs() {
		// Os liczy polozenie z szerokosci: po otwarciu kafla progow trzeba ja ustawic od nowa.
		if ( otwarte.progi && ui.trybProgow === 'os' ) {
			window.requestAnimationFrame( function () {
				obserwatorzyProgow.forEach( function ( f ) {
					f( bledyProgow() );
				} );
			} );
		}
	}
	function kafelPola( p ) {
		var s = p && p.closest ? p.closest( '[data-kw-kafel]' ) : null;
		return s ? s.getAttribute( 'data-kw-kafel' ) : '';
	}
	function linia( klasa, tekst ) {
		return el( 'span', 'kf__l' + ( klasa ? ' ' + klasa : '' ), tekst );
	}
	function streszczenia( u, c, b ) {
		var S = {};
		var licz = LICZBOWE.indexOf( u.typ ) >= 0;
		S.podstawy = [ u.tytul ? null : linia( 'kf__l--brak', 'Bez nazwy' ), u.zajawka.trim() ? linia( '', u.zajawka.trim() ) : linia( 'muted', 'Bez krótkiego opisu' ), linia( 'muted', u.kategoria !== '0' ? 'Zakładka ' + u.kategoriaNazwa : 'Bez zakładki cennika' ) ];
		var typNazwa = q( 'label[for="kw-typ-' + u.typ + '"] .kw-typ__nazwa' );
		S.cena = [ linia( 'kf__l--duza', cenaTekst( c ) ), linia( 'muted', ( typNazwa ? typNazwa.textContent : '' ) + ( licz ? ', ' + ( u.netto ? 'netto' : 'brutto' ) : '' ) ) ];
		var p = M.progi;
		if ( ! p.length ) {
			S.progi = [ linia( 'muted', 'Bez progów: klient widzi samą kwotę.' ) ];
		} else {
			var f = function ( x ) {
				var kw = czyscKwote( x.cena );
				return ( x.odCena ? 'od ' : '' ) + ( kw === '' ? '?' : zl( kw ) ) + ' za ' + opisZakresu( x );
			};
			S.progi = [ linia( 'kf__l--duza', ile( p.length, 'próg', 'progi', 'progów' ) ), linia( 'muted', f( p[ 0 ] ) + ( p.length > 1 ? ' … ' + f( p[ p.length - 1 ] ) : '' ) ) ];
		}
		var lz = el( 'span', 'kf__licz' );
		[ [ 'W cenie', u.wCenie ], [ 'Dodatkowo płatne', u.dodatkowo ], [ 'Nie obejmuje', u.nieObejmuje ], [ 'Od czego zależy cena', u.czynniki ] ].forEach( function ( x ) {
			var w = el( 'span' );
			w.appendChild( el( 'span', 'muted', x[ 0 ] ) );
			w.appendChild( el( 'b', '', String( x[ 1 ].length ) ) );
			lz.appendChild( w );
		} );
		S.zakres = [ lz ];
		var g = gdzie( u );
		S.widocznosc = [ el( 'span', 'kw-tag kw-tag--' + ( g[ 1 ] === 'ok' ? 'ok' : 'uwaga' ), g[ 0 ] ), linia( 'muted', miejsceTekst( u ) + ( u.wyroznienie ? ' · wyróżnienie „' + u.wyroznienie + '”' : '' ) ) ];
		var op = czyscTekst( u.opis, 100000 );
		S.opis = [ op ? linia( '', op.slice( 0, 160 ) + ( op.length > 160 ? '…' : '' ) ) : linia( 'muted', 'Pusty. Opcjonalny akapit na końcu szczegółów karty.' ) ];

		var zle = function ( kafel ) {
			return b.filter( function ( x ) {
				return x.kafel === kafel;
			} );
		};
		var bp = bledyProgow();
		var T = {
			podstawy: ! u.tytul ? [ 'blad', 'Brak nazwy' ] : ( u.kategoria === '0' ? [ 'uwaga', 'Bez zakładki' ] : null ),
			cena: zle( 'cena' ).length ? [ probaZapisu || zle( 'cena' ).some( function ( x ) {
				return ! x.brak;
			} ) ? 'blad' : 'uwaga', zle( 'cena' ).some( function ( x ) {
				return x.brak;
			} ) ? 'Do uzupełnienia' : 'Do poprawy' ] : null,
			progi: ! licz || ! bp.length ? null : ( bp.some( function ( x ) {
				return x.rodzaj === 'blad';
			} ) ? [ 'blad', bp.some( function ( x ) {
				return /^Nakładanie/.test( x.tekst );
			} ) ? 'Progi nakładają się' : 'Do poprawy' ] : [ 'uwaga', bp.some( function ( x ) {
				return /^Luka/.test( x.tekst );
			} ) ? 'Luka między progami' : 'Brak ceny w progu' ] ),
			zakres: u.wCenie.length ? null : [ 'uwaga', 'Lista „W cenie” pusta' ],
			widocznosc: u.cennik || u.formularz ? null : [ 'uwaga', 'Ukryta na stronie' ],
			opis: null
		};
		Object.keys( kafle ).forEach( function ( id ) {
			var x = kafle[ id ];
			if ( S[ id ] ) {
				x.stresz.replaceChildren();
				S[ id ].forEach( function ( n ) {
					if ( n ) {
						x.stresz.appendChild( n );
					}
				} );
			}
			var t = T[ id ];
			x.tag.hidden = ! t;
			x.tag.className = 'kf__tag kw-tag kw-tag--' + ( t ? t[ 0 ] : 'neutral' );
			x.tag.textContent = t ? t[ 1 ] : '';
			x.s.classList.toggle( 'is-blad', !! t && t[ 0 ] === 'blad' );
		} );
	}


	/* ---------------------------------------------------------------------
	 * Sprawdzanie pol
	 * ------------------------------------------------------------------ */

	var dotkniete = [];
	var probaZapisu = false;

	function bledy( u ) {
		var b = [];
		// brak = pole puste (szkic wolno zapisac mimo to); bez znacznika = wartosc bledna. kafel = gdzie lezy pole.
		function dodaj( pole, tekst, kafel, brak, fokus ) {
			b.push( { pole: pole, tekst: tekst, kafel: kafel, brak: !! brak, fokus: fokus } );
		}
		if ( u.tytul === '' ) {
			dodaj( q( '#title' ), 'Wpisz nazwę usługi.', 'podstawy' );
		}
		if ( LICZBOWE.indexOf( u.typ ) >= 0 ) {
			var min = q( '#kw-cena-min' );
			var max = q( '#kw-cena-max' );
			if ( min.value.trim() !== '' && u.min === '' ) {
				dodaj( min, 'Kwota: wpisz samą liczbę, np. 500 albo 49,50.', 'cena' );
			} else if ( u.min === '' && ! u.progi.length ) {
				dodaj( min, 'Wpisz kwotę albo dodaj progi. Usługa bez kwoty: wybierz „Wycena indywidualna”.', 'cena', true );
			}
			if ( u.typ === 'przedzial' ) {
				if ( max.value.trim() !== '' && u.max === '' ) {
					dodaj( max, 'Kwota najwyższa: wpisz samą liczbę, np. 60.', 'cena' );
				} else if ( u.max === '' ) {
					dodaj( max, 'Wpisz kwotę najwyższą albo wybierz „Cena od”.', 'cena', true );
				} else if ( u.min !== '' && parseFloat( u.max ) <= parseFloat( u.min ) ) {
					dodaj( max, 'Kwota najwyższa musi być większa od najniższej.', 'cena' );
				}
			}
			if ( q( '#kw-jednostka' ).value === '_wlasna' && u.jednostka === '' ) {
				dodaj( wlasna, 'Wpisz jednostkę albo wybierz ją z listy.', 'cena', true );
			}
			// Progi: zakresy po kolei bez dziur i nakladek, kazdy z cena, ceny nie maleja (komunikaty stoja tez przy wierszu).
			bledyProgow().forEach( function ( x ) {
				dodaj( null, x.tekst, 'progi', false, function () {
					fokusProgu( x.i, x.pole );
				} );
			} );
		} else if ( u.typ === 'opis' && u.cenaOpis === '' ) {
			dodaj( q( '#kw-cena-opis' ), 'Wpisz tekst, który stanie w miejscu ceny.', 'cena', true );
		}
		return b;
	}
	function fokusProgu( i, rodzaj ) {
		if ( ui.trybProgow !== 'tabela' && rodzaj !== 'cena' ) {
			ui.trybProgow = 'tabela';
			rysujProgi();
		}
		otworzKafel( 'progi', false );
		window.requestAnimationFrame( function () {
			var p = polaProgow[ i ];
			var cel = p ? p[ rodzaj ] || p.od : null;
			if ( ui.trybProgow === 'os' ) {
				cel = qa( '.os__cena input' )[ i ];
			}
			if ( cel ) {
				cel.scrollIntoView( { block: 'center' } );
				cel.focus( { preventScroll: true } );
			}
		} );
	}
	function poleBledu( pole ) {
		var ids = ( pole.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( function ( id ) {
			return /-blad$/.test( id );
		} );
		return ids.length ? document.getElementById( ids[ 0 ] ) : null;
	}
	// Pokazuje bledy przy polach, ktore uzytkownik juz opuscil (albo przy wszystkich po probie zapisu). Progi maja
	// komunikaty przy swoich wierszach (widok progow), tu tylko na liscie zbiorczej.
	function pokazBledy( lista ) {
		qa( '[data-kw-blad]' ).forEach( function ( x ) {
			x.hidden = true;
			x.textContent = '';
			var pole = q( '[aria-describedby~="' + x.id + '"]' );
			if ( pole ) {
				pole.removeAttribute( 'aria-invalid' );
			}
		} );
		lista.forEach( function ( b ) {
			if ( ! b.pole || ( ! probaZapisu && dotkniete.indexOf( b.pole ) < 0 ) ) {
				return;
			}
			b.pole.setAttribute( 'aria-invalid', 'true' );
			var p = poleBledu( b.pole );
			if ( p && p.hidden ) {
				p.textContent = b.tekst;
				p.hidden = false;
			}
		} );
		var zbiorczo = q( '[data-kw-bledy]' );
		if ( probaZapisu && lista.length ) {
			q( '[data-kw-bledy-h]' ).textContent = 'Popraw ' + ile( lista.length, 'pole', 'pola', 'pól' ) + ', zanim zapiszesz:';
			var ul = q( '[data-kw-bledy-lista]' );
			ul.replaceChildren();
			lista.forEach( function ( b ) {
				var li = el( 'li' );
				var a = el( 'a', '', b.tekst );
				a.href = b.pole ? '#' + b.pole.id : '#kf-' + b.kafel;
				a.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					if ( b.fokus ) {
						b.fokus();
						return;
					}
					otworzKafel( b.kafel, false );
					window.requestAnimationFrame( function () {
						b.pole.scrollIntoView( { block: 'center' } );
						b.pole.focus( { preventScroll: true } );
					} );
				} );
				li.appendChild( a );
				ul.appendChild( li );
			} );
			zbiorczo.hidden = false;
		} else {
			zbiorczo.hidden = true;
		}
	}

	/* ---------------------------------------------------------------------
	 * Odswiezenie calego ekranu po zmianie (raz na klatke)
	 * ------------------------------------------------------------------ */

	function gdzie( u ) {
		if ( D.status !== 'publish' ) {
			return [ 'Szkic: nie widać jej na stronie', 'uwaga' ];
		}
		if ( u.cennik && u.formularz ) {
			return [ 'W cenniku i w formularzu', 'ok' ];
		}
		if ( u.cennik ) {
			return [ 'Tylko w cenniku', 'ok' ];
		}
		if ( u.formularz ) {
			return [ 'Tylko w formularzu', 'ok' ];
		}
		return [ 'Ukryta: nie widać jej na stronie', 'uwaga' ];
	}
	var progiApp = null;
	function odswiez() {
		zapiszDoFormularza();
		var u = stan();
		var c = cena( u );
		var tekstCeny = cenaTekst( c );
		var b = bledy( u );

		q( '[data-kw-cena-tekst]' ).textContent = tekstCeny;
		var bezKwoty = LICZBOWE.indexOf( u.typ ) >= 0 && c.rodzaj === 'tekst';
		var uwaga = q( '[data-kw-cena-uwaga]' );
		uwaga.hidden = ! bezKwoty;
		uwaga.textContent = bezKwoty ? ' Brakuje kwoty: wpisz ją albo dodaj progi.' : '';
		q( '[data-kw-pasek-cena]' ).textContent = tekstCeny;
		var gora = q( '[data-kw-pasek-cena-gora]' );
		if ( gora ) {
			gora.textContent = tekstCeny;
		}
		var nazwa = q( '[data-kw-pasek-nazwa]' );
		if ( nazwa ) {
			nazwa.textContent = u.tytul || 'Nowa usługa';
			nazwa.classList.toggle( 'is-puste', ! u.tytul );
		}
		rysujPodglad( u );
		pokazBledy( b );
		rysujMiejsce( u );
		streszczenia( u, c, b );
		if ( progiApp ) {
			progiApp.odswiez();
		}
		var nad = document.querySelector( '[data-kw-gdzie]' );
		if ( nad ) {
			var g = gdzie( u );
			nad.className = 'kwa-znak' + ( g[ 1 ] ? ' kwa-znak--' + g[ 1 ] : '' );
			nad.textContent = g[ 0 ];
		}

		// Przy cenie "od" klient powinien wiedziec, co ja podnosi i co jest platne osobno.
		var rada = q( '[data-kw-rada]' );
		var brak = [];
		if ( c.przed === 'od' ) {
			if ( ! u.dodatkowo.length ) {
				brak.push( '„Dodatkowo płatne”' );
			}
			if ( ! u.czynniki.length ) {
				brak.push( '„Od czego zależy cena”' );
			}
		}
		rada.hidden = ! brak.length;
		rada.textContent = brak.length ? 'Przy cenie „od” klient chce wiedzieć, od czego zależy kwota końcowa. Uzupełnij: ' + brak.join( ' i ' ) + '.' : '';
	}
	var czeka = false;
	var brudny = false;
	function planuj() {
		if ( ! czeka ) {
			czeka = true;
			window.requestAnimationFrame( function () {
				czeka = false;
				odswiez();
			} );
		}
	}
	function zmiana() {
		brudny = true;
		planuj();
	}

	/* ---------------------------------------------------------------------
	 * Start i zdarzenia
	 * ------------------------------------------------------------------ */

	k.classList.add( 'is-js' );
	document.body.classList.add( 'kw-ekran-uslugi' );
	qa( '[data-kw-lista]' ).forEach( kafelListy );
	progiApp = budujProgi();
	pokazPola();
	var znakNad = document.querySelector( '.kw-podglad__gdzie' );
	if ( znakNad ) {
		znakNad.hidden = true; // stan widocznosci stoi raz: w kaflu "Widoczność i miejsce"
	}

	// Ramka "Jak to dziala": zwinieta zostaje zwinieta przy nastepnej usludze (pamiec przegladarki, bez zapisu na serwerze).
	var jak = q( '[data-kw-jak]' );
	if ( jak ) {
		try {
			if ( window.localStorage.getItem( 'kwJakToDziala2' ) === '0' ) {
				jak.open = false;
			}
			jak.addEventListener( 'toggle', function () {
				window.localStorage.setItem( 'kwJakToDziala2', jak.open ? '1' : '0' );
			} );
		} catch ( x ) {
			// pamiec przegladarki niedostepna: ramka po prostu zaczyna otwarta
		}
	}

	odswiez();
	brudny = false;

	// Kafle zaczynaja zwiniete; po zapisie (przeladowanie strony) wraca ostatnio otwarty kafel tej uslugi. Nowa usluga
	// zaczyna od Podstaw.
	var startKafel = D.status === 'auto-draft' ? 'podstawy' : '';
	try {
		startKafel = window.sessionStorage.getItem( 'kwKafel-' + D.id ) || startKafel;
	} catch ( x ) {}
	Object.keys( kafle ).forEach( function ( id ) {
		ustawKafel( id, false );
	} );
	startKafel.split( ',' ).forEach( function ( id ) {
		otworzKafel( id, false );
	} );
	Object.keys( kafle ).forEach( function ( id ) {
		kafle[ id ].glowa.addEventListener( 'click', function () {
			if ( otwarte[ id ] ) {
				ustawKafel( id, false );
			} else {
				otworzKafel( id, 'glowa' );
			}
		} );
	} );

	k.addEventListener( 'focusin', function ( ev ) {
		wskaz( celPola( ev.target ) );
	} );
	// Ruch myszy (nie samo przewiniecie strony pod nieruchomym kursorem) pokazuje pole albo kafel pod kursorem;
	// poza polami wraca podswietlenie pola, w ktorym jest fokus.
	k.addEventListener( 'mousemove', function ( ev ) {
		wskaz( celPola( ev.target ) || celPola( document.activeElement ) );
	} );
	k.addEventListener( 'mouseleave', function () {
		wskaz( celPola( document.activeElement ) );
	} );

	k.addEventListener( 'input', function ( ev ) {
		if ( ev.target.id === 'kw-kolejnosc' ) {
			kolejnoscWlasna = true;
		}
		zmiana();
	} );
	k.addEventListener( 'change', function ( ev ) {
		if ( ev.target.id === 'kw-grupa' ) {
			podpowiedzKolejnosc();
		}
		if ( ev.target.name === 'kw_cena_typ' || ev.target.id === 'kw-jednostka' ) {
			pokazPola();
			if ( ev.target.id === 'kw-jednostka' && ! wlasna.hidden ) {
				wlasna.focus();
			}
		}
		zmiana();
	} );
	// Chipy i przelacznik netto / brutto: pole wyboru jest schowane, a fokus widac na etykiecie obok. Przegladarka przewija
	// do schowanego pola (1 px), wiec dosuwamy cala etykiete.
	k.addEventListener( 'focusin', function ( ev ) {
		var t = ev.target;
		if ( t.type === 'radio' && t.nextElementSibling && t.parentNode.matches( '.kw-typy, .kw-segment' ) && t.matches( ':focus-visible' ) ) {
			t.nextElementSibling.scrollIntoView( { block: 'nearest' } );
		}
	} );
	k.addEventListener( 'focusout', function ( ev ) {
		if ( ev.target.matches && ev.target.matches( 'input, select, textarea' ) && dotkniete.indexOf( ev.target ) < 0 ) {
			dotkniete.push( ev.target );
			planuj(); // samo wyjscie z pola to nie zmiana
		}
	} );
	k.addEventListener( 'click', function ( ev ) {
		var b = ev.target.closest( 'button' );
		if ( ! b ) {
			return;
		}
		if ( b.hasAttribute( 'data-kw-zapisz' ) ) {
			var publikuj = document.getElementById( 'publish' );
			if ( publikuj ) {
				publikuj.click();
			}
		} else if ( b.hasAttribute( 'data-kw-bib-otworz' ) ) {
			otworzBiblioteke( 'wszystko' );
		} else if ( b.hasAttribute( 'data-kw-pokaz-podglad' ) ) {
			var skrzynka = document.getElementById( 'kw_podglad' );
			if ( skrzynka ) {
				skrzynka.classList.remove( 'closed' );
				skrzynka.setAttribute( 'tabindex', '-1' );
				skrzynka.scrollIntoView( { block: 'start', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
				skrzynka.focus( { preventScroll: true } );
			}
		}
	} );
	// Enter w polach kafli nie wysyla formularza (WordPress wyslalby go "Zapisz szkic" albo "Aktualizuj" bez sprawdzenia).
	k.addEventListener( 'keydown', function ( ev ) {
		if ( ev.key === 'Enter' && ev.target.matches && ev.target.matches( 'input[type="text"], input[type="number"], input[type="search"]' ) ) {
			ev.preventDefault();
		}
	} );

	// Pasek nad kaflami i pasek dolny (ekran w jednej kolumnie): ten sam przycisk co w pudelku zapisu WordPressa.
	var glowny = document.getElementById( 'publish' );
	var napisZapisz = glowny ? ( D.status === 'publish' ? 'Zapisz' : glowny.value || 'Zapisz' ) : 'Zapisz';
	qa( '[data-kw-zapisz]' ).forEach( function ( b ) {
		b.textContent = napisZapisz;
	} );
	var pasekGora = q( '[data-kw-pasek-gora]' );
	if ( pasekGora ) {
		var bibB = q( '[data-kw-bib-otworz]', pasekGora );
		bibB.insertBefore( ikona( 'biblioteka' ), bibB.firstChild );
		pasekGora.hidden = false;
	}
	var pasek = q( '[data-kw-pasek]' );
	if ( pasek && glowny ) {
		pasek.hidden = false;
	}

	if ( form ) {
		form.noValidate = true; // komunikaty po polsku przy polach zamiast dymkow przegladarki
		form.addEventListener( 'submit', function ( ev ) {
			// "Zapisz szkic": niedokonczona usluge wolno odlozyc; blokuja tylko brak nazwy i bledne wartosci (np. litery w kwocie).
			zapiszDoFormularza();
			var szkic = !! ( ev.submitter && ev.submitter.id === 'save-post' );
			var b = bledy( stan() ).filter( function ( x ) {
				return ! szkic || ! x.brak;
			} );
			if ( b.length || ! szkic ) {
				probaZapisu = true;
			}
			odswiez();
			if ( b.length ) {
				ev.preventDefault();
				ev.stopImmediatePropagation();
				otworzKafel( b[ 0 ].kafel, false );
				var zbiorczo = q( '[data-kw-bledy]' );
				zbiorczo.scrollIntoView( { block: 'center' } );
				zbiorczo.focus( { preventScroll: true } );
				powiedz( 'Nie zapisano. ' + q( '[data-kw-bledy-h]' ).textContent );
				return;
			}
			brudny = false;
		} );
	}
	// ponytail: ostrzezenie obejmuje tylko pola kreatora (autozapis WordPressa jest na tym ekranie wylaczony).
	window.addEventListener( 'beforeunload', function ( ev ) {
		if ( brudny ) {
			ev.preventDefault();
			ev.returnValue = '';
		}
	} );
	window.addEventListener( 'resize', function () {
		if ( ui.trybProgow === 'os' ) {
			obserwatorzyProgow.forEach( function ( f ) {
				f( bledyProgow() );
			} );
		}
	} );
	window.kwKreator = { phpHtml: window.kwKreator ? window.kwKreator.phpHtml : '', stan: stan, karta: karta, model: M, otworzKafel: otworzKafel, otworzBiblioteke: otworzBiblioteke };
} )();
