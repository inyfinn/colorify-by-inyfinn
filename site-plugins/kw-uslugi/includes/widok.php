<?php
/**
 * Widok: shortcody [kw_cennik], [kw_formularz], starsze [kw_uslugi] i [kw_uslugi_lista] oraz ich zasoby.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tekst bezpieczny dla HTML, bez sierot: twarda spacja po jednoliterowych spojnikach
 * i przyimkach (a, i, o, u, w, z), a w naglowkach takze miedzy dwoma ostatnimi slowami.
 * Celowo bez filtrow the_title / wptexturize: te zamieniaja lacznik na polpauze.
 *
 * @param string $tekst    Tekst.
 * @param bool   $naglowek Czy to naglowek.
 * @return string HTML.
 */
function kw_uslugi_e( $tekst, $naglowek = false ) {
	$nbsp  = "\u{00A0}";
	$tekst = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $tekst ) ) );
	$tekst = (string) preg_replace( '/(?<![\p{L}\p{N}])([aiouwzAIOUWZ]) (?=\S)/u', '$1' . $nbsp, $tekst );
	$tekst = (string) preg_replace( '/(\d) (?=zł(?![\p{L}]))/u', '$1' . $nbsp, $tekst ); // "50 zł" nie rozdziela sie miedzy wierszami
	if ( $naglowek && preg_match( '/^(.*[^ ]) ([^ ]+)$/u', $tekst, $m ) ) {
		$przedostatnie = (string) preg_replace( '/^.* /u', '', $m[1] );
		// Laczymy tylko krotkie koncowki, zeby dlugi naglowek nie wyszedl poza waska karte.
		if ( mb_strlen( $przedostatnie . ' ' . $m[2] ) <= 24 ) {
			$tekst = $m[1] . $nbsp . $m[2];
		}
	}
	return str_replace( $nbsp, '&nbsp;', esc_html( $tekst ) );
}

/**
 * Ikona jako SVG inline (kolor i rozmiar bierze z tekstu: currentColor, 1em).
 *
 * @param string $klucz Klasa Font Awesome albo klucz wewnetrzny (np. _strzalka).
 * @return string HTML.
 */
function kw_uslugi_ikona( $klucz ) {
	$ikony = kw_uslugi_ikony();
	if ( isset( $ikony[ $klucz ] ) ) {
		list( , $szer, $wys, $sciezka ) = $ikony[ $klucz ];
		return sprintf(
			'<svg class="kw-ikona" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" style="width:%3$sem" aria-hidden="true" focusable="false"><path fill="currentColor" d="%4$s"/></svg>',
			$szer,
			$wys,
			esc_attr( rtrim( rtrim( number_format( $szer / $wys, 3, '.', '' ), '0' ), '.' ) ),
			esc_attr( $sciezka )
		);
	}
	// Klasa spoza zestawu (wpisana np. przez REST): znacznik Font Awesome, widoczny tam, gdzie motyw laduje te czcionke.
	return '<i class="' . esc_attr( kw_uslugi_czysc_ikone( $klucz ) ) . '" aria-hidden="true"></i>';
}

/**
 * Atrybut shortcodu "tak" / "nie".
 *
 * @param mixed $wartosc Wartosc.
 * @return bool
 */
function kw_uslugi_tak( $wartosc ) {
	return in_array( strtolower( trim( (string) $wartosc ) ), array( 'tak', '1', 'true', 'yes' ), true );
}

/**
 * Adres z atrybutu shortcodu: sciezka w witrynie ("/kontakt/"), kotwica ("#x") albo pelny adres.
 *
 * @param string $adres Adres.
 * @return string
 */
function kw_uslugi_adres( $adres ) {
	$adres = trim( (string) $adres );
	if ( '' !== $adres && '/' === $adres[0] && 0 !== strpos( $adres, '//' ) ) {
		$adres = home_url( $adres );
	}
	return $adres;
}

/**
 * Kotwica uslugi: nadawana tylko pierwszemu wystapieniu na stronie (id musi byc unikalne).
 *
 * @param string $slug Slug uslugi.
 * @return string Atrybut id albo pusty tekst.
 */
function kw_uslugi_kotwica( $slug ) {
	static $uzyte = array();
	if ( '' === $slug || isset( $uzyte[ $slug ] ) ) {
		return '';
	}
	$uzyte[ $slug ] = true;
	return ' id="' . esc_attr( 'usluga-' . $slug ) . '"';
}

/**
 * Link "Zapytaj o usluge" do formularza z wybrana usluga.
 *
 * @param array  $usluga      Usluga.
 * @param string $kontakt_url Adres strony z formularzem.
 * @param string $klasa       Klasa CSS.
 * @return string HTML.
 */
function kw_uslugi_link_zapytaj( array $usluga, $kontakt_url, $klasa ) {
	$adres = add_query_arg( 'usluga', rawurlencode( $usluga['slug'] ), kw_uslugi_adres( $kontakt_url ) ) . '#formularz';
	return sprintf(
		'<a class="%1$s" href="%2$s">Zapytaj o&nbsp;usługę<span class="kw-sr">: %3$s</span></a>',
		esc_attr( $klasa ),
		esc_url( $adres ),
		kw_uslugi_e( $usluga['tytul'] )
	);
}

/**
 * Podpowiedz dla redaktora, gdy shortcode nie ma czego pokazac (goscie nie widza nic).
 *
 * @return string HTML.
 */
function kw_uslugi_pusto() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}
	return '<p class="kw-pusto">Brak usług do pokazania. Sprawdź w menu „Usługi”, czy usługa jest opublikowana, ma kategorię i zaznaczone „Pokaż w cenniku na stronie”.</p>';
}

/**
 * Karta uslugi (uzywana na stronie i w podgladzie kreatora).
 *
 * @param array $usluga Usluga.
 * @param array $opcje  Opcje: ceny (bool), zapytaj (bool), kontakt_url (string), kotwica (bool).
 * @return string HTML.
 */
function kw_uslugi_karta( array $usluga, array $opcje = array() ) {
	$tytul = kw_uslugi_e( $usluga['tytul'], true );
	$link  = empty( $usluga['link'] ) ? '' : kw_uslugi_adres( $usluga['link'] );
	if ( '' !== $link ) {
		// Odnosnik w tytule, a jego pole klikniecia rozciagniete na cala karte (CSS ::after).
		$tytul = '<a class="kw-usluga__link" href="' . esc_url( $link ) . '">' . $tytul . '</a>';
	}
	$html  = '<div class="kw-usluga' . ( '' !== $link ? ' kw-usluga--link' : '' ) . '" role="listitem"' . ( empty( $opcje['kotwica'] ) ? '' : kw_uslugi_kotwica( $usluga['slug'] ) ) . '>';
	$html .= '<span class="kw-usluga__ikona">' . kw_uslugi_ikona( $usluga['ikona'] ) . '</span>';
	$html .= '<div class="kw-usluga__tresc">';
	$html .= '<h3 class="kw-usluga__tytul">' . $tytul . '</h3>';
	if ( '' !== trim( $usluga['zajawka'] ) ) {
		$html .= '<p class="kw-usluga__opis">' . kw_uslugi_e( $usluga['zajawka'] ) . '</p>';
	}
	if ( ! empty( $opcje['ceny'] ) && '' !== $usluga['cena'] ) {
		$html .= '<p class="kw-usluga__cena">' . esc_html( $usluga['cena'] ) . '</p>';
	}
	if ( ! empty( $opcje['zapytaj'] ) ) {
		$html .= '<p class="kw-usluga__akcja">' . kw_uslugi_link_zapytaj( $usluga, $opcje['kontakt_url'], 'kw-link' ) . '</p>';
	}
	return $html . '</div></div>';
}

/**
 * Shortcode [kw_uslugi]: siatka kart.
 *
 * @param array|string $atrybuty Atrybuty.
 * @return string HTML.
 */
function kw_uslugi_sc_siatka( $atrybuty ) {
	$a = shortcode_atts(
		array(
			'grupa'          => '',
			'bez'            => '',
			'kolumny'        => '4',
			'przycisk'       => 'nie',
			'przycisk_url'   => '/zakres-kompetencji/',
			'przycisk_tekst' => 'Zobacz pełną ofertę',
			'zapytaj'        => 'nie',
			'kontakt_url'    => '/kontakt/',
		),
		$atrybuty,
		'kw_uslugi'
	);

	$uslugi = array_filter(
		kw_uslugi_filtruj( kw_uslugi_pobierz(), kw_uslugi_slugi( $a['grupa'] ), kw_uslugi_slugi( $a['bez'] ) ),
		static function ( $u ) {
			return 'karta' === $u['miejsce'];
		}
	);
	if ( ! $uslugi ) {
		return kw_uslugi_pusto();
	}

	$opcje = array(
		'ceny'        => (bool) kw_uslugi_opcje()['ceny'],
		'zapytaj'     => kw_uslugi_tak( $a['zapytaj'] ),
		'kontakt_url' => $a['kontakt_url'],
		'kotwica'     => true,
	);
	$html  = '<div class="kw-uslugi kw-uslugi--k' . max( 1, min( 4, (int) $a['kolumny'] ) ) . '" role="list">';
	foreach ( $uslugi as $usluga ) {
		$html .= kw_uslugi_karta( $usluga, $opcje );
	}
	if ( kw_uslugi_tak( $a['przycisk'] ) ) {
		$html .= sprintf(
			'<div class="kw-usluga kw-usluga--wiecej" role="listitem"><a class="kw-usluga__wiecej" href="%1$s"><span>%2$s</span>%3$s</a></div>',
			esc_url( kw_uslugi_adres( $a['przycisk_url'] ) ),
			kw_uslugi_e( $a['przycisk_tekst'] ),
			kw_uslugi_ikona( '_strzalka' )
		);
	}
	return $html . '</div>';
}
add_shortcode( 'kw_uslugi', 'kw_uslugi_sc_siatka' );

/**
 * Shortcode [kw_uslugi_lista]: pelna lista pogrupowana.
 *
 * @param array|string $atrybuty Atrybuty.
 * @return string HTML.
 */
function kw_uslugi_sc_lista( $atrybuty ) {
	$a = shortcode_atts(
		array(
			'grupa'       => '',
			'bez'         => '',
			'zapytaj'     => 'nie',
			'kontakt_url' => '/kontakt/',
		),
		$atrybuty,
		'kw_uslugi_lista'
	);

	$uslugi = array_filter(
		kw_uslugi_filtruj( kw_uslugi_pobierz(), kw_uslugi_slugi( $a['grupa'] ), kw_uslugi_slugi( $a['bez'] ) ),
		static function ( $u ) {
			return 'nie' !== $u['miejsce'];
		}
	);
	if ( ! $uslugi ) {
		return kw_uslugi_pusto();
	}

	$ceny    = (bool) kw_uslugi_opcje()['ceny'];
	$zapytaj = kw_uslugi_tak( $a['zapytaj'] );
	$html    = '<div class="kw-lista">';
	foreach ( kw_uslugi_grupuj( $uslugi ) as $slug => $grupa ) {
		$html .= '<section class="kw-lista__grupa"' . ( '' === $slug ? '' : ' id="' . esc_attr( 'grupa-' . $slug ) . '"' ) . '>';
		$html .= '<h3 class="kw-lista__naglowek">' . kw_uslugi_e( $grupa['nazwa'], true ) . '</h3>';
		$html .= '<div class="kw-lista__pozycje" role="list">';
		foreach ( $grupa['uslugi'] as $usluga ) {
			$html .= '<div class="kw-lista__pozycja" role="listitem"' . kw_uslugi_kotwica( $usluga['slug'] ) . '>';
			$html .= '<span class="kw-lista__ikona">' . kw_uslugi_ikona( $usluga['ikona'] ) . '</span>';
			$html .= '<div class="kw-lista__tresc">';
			$html .= '<h4 class="kw-lista__tytul">' . kw_uslugi_e( $usluga['tytul'], true ) . '</h4>';
			if ( $ceny && '' !== $usluga['cena'] ) {
				$html .= '<p class="kw-lista__cena">' . esc_html( $usluga['cena'] ) . '</p>';
			}
			if ( '' !== trim( $usluga['zajawka'] ) ) {
				$html .= '<p class="kw-lista__opis">' . kw_uslugi_e( $usluga['zajawka'] ) . '</p>';
			}
			if ( '' !== trim( wp_strip_all_tags( $usluga['opis'] ) ) ) {
				$html .= '<details class="kw-lista__wiecej"><summary>Więcej o&nbsp;usłudze<span class="kw-sr">: ' . kw_uslugi_e( $usluga['tytul'] ) . '</span></summary>';
				$html .= '<div class="kw-lista__dlugi">' . wp_kses_post( wpautop( $usluga['opis'] ) ) . '</div></details>';
			}
			if ( $zapytaj ) {
				$html .= '<p class="kw-lista__akcja">' . kw_uslugi_link_zapytaj( $usluga, $a['kontakt_url'], 'kw-link' ) . '</p>';
			}
			$html .= '</div></div>';
		}
		$html .= '</div></section>';
	}
	return $html . '</div>';
}
add_shortcode( 'kw_uslugi_lista', 'kw_uslugi_sc_lista' );

/* ---------------------------------------------------------------------------
 * Cennik [kw_cennik]
 * ------------------------------------------------------------------------ */

/**
 * Tekst z gotowymi twardymi spacjami (np. kwota "500 zł") bezpieczny dla HTML.
 *
 * @param string $tekst Tekst.
 * @return string HTML.
 */
function kw_uslugi_nb( $tekst ) {
	return str_replace( "\u{00A0}", '&nbsp;', esc_html( (string) $tekst ) );
}

/**
 * Blok listy w szczegolach karty cennika.
 *
 * @param string   $naglowek Naglowek bloku.
 * @param string[] $pozycje  Pozycje.
 * @param string   $rodzaj   Znak pozycji: tak, plus, nie albo kropka.
 * @return string HTML (pusty, gdy brak pozycji).
 */
function kw_uslugi_cennik_blok( $naglowek, array $pozycje, $rodzaj ) {
	if ( ! $pozycje ) {
		return '';
	}
	// Etykieta bloku to akapit, nie naglowek: karta ma jeden naglowek (nazwa uslugi), konspekt strony zostaje czytelny.
	$html = '<div class="kw-c__blok"><p class="kw-c__blok-h">' . esc_html( $naglowek ) . '</p><ul class="kw-c__punkty kw-c__punkty--' . esc_attr( $rodzaj ) . '">';
	foreach ( $pozycje as $pozycja ) {
		$html .= '<li>' . kw_uslugi_e( $pozycja ) . '</li>';
	}
	return $html . '</ul></div>';
}

/**
 * Karta uslugi w cenniku (uzywana na stronie i w podgladzie kreatora).
 *
 * @param array $u    Usluga (wynik kw_uslugi_z_posta).
 * @param bool  $ceny Czy pokazywac ceny (opcja "Pokazuj ceny").
 * @return string HTML.
 */
function kw_uslugi_cennik_karta( array $u, $ceny = true ) {
	$cena  = $ceny ? kw_uslugi_cena( $u ) : null;
	$sr    = '<span class="kw-sr">: ' . kw_uslugi_e( $u['tytul'] ) . '</span>';
	$klasy = 'kw-c__karta' . ( '' !== $u['wyroznienie'] ? ' kw-c__karta--wyrozniona' : '' ) . ( $cena ? '' : ' kw-c__karta--bezceny' );

	$html = '<div class="' . $klasy . '" role="listitem"><div class="kw-c__glowa">';
	if ( '' !== $u['wyroznienie'] ) {
		$html .= '<p class="kw-c__znak">' . kw_uslugi_e( $u['wyroznienie'] ) . '</p>';
	}
	$html .= '<h4 class="kw-c__tytul">' . kw_uslugi_e( $u['tytul'], true ) . '</h4>';
	if ( '' !== trim( $u['zajawka'] ) ) {
		$html .= '<p class="kw-c__zajawka">' . kw_uslugi_e( $u['zajawka'] ) . '</p>';
	}
	$html .= '</div>';

	if ( $cena ) {
		$html .= '<p class="kw-c__cena kw-c__cena--' . esc_attr( $cena['rodzaj'] ) . '">';
		$html .= '' !== $cena['przed'] ? '<span class="kw-c__od">' . esc_html( $cena['przed'] ) . '</span> ' : '';
		$html .= '<span class="kw-c__kwota">' . kw_uslugi_nb( $cena['kwota'] ) . '</span>';
		$html .= '' !== $cena['po'] ? ' <span class="kw-c__jedn">' . esc_html( $cena['po'] ) . '</span>' : '';
		$html .= '</p>';
	}

	// "W cenie" na karcie: do czterech pozycji wszystkie; przy dluzszej liscie trzy pierwsze, a ciag dalszy w szczegolach
	// (zadna pozycja nie powtarza sie dwa razy).
	$na_karcie = count( $u['w_cenie'] ) > 4 ? array_slice( $u['w_cenie'], 0, 3 ) : $u['w_cenie'];
	$reszta    = array_slice( $u['w_cenie'], count( $na_karcie ) );
	if ( $na_karcie ) {
		$html .= '<div class="kw-c__skrot"><p class="kw-c__blok-h">' . ( $reszta ? 'W cenie między innymi' : 'W cenie' ) . '</p><ul class="kw-c__punkty kw-c__punkty--tak">';
		foreach ( $na_karcie as $pozycja ) {
			$html .= '<li>' . kw_uslugi_e( $pozycja ) . '</li>';
		}
		$html .= '</ul></div>';
	}

	$bloki = '';
	if ( $cena && $u['progi'] ) {
		// ponytail: naglowek kolumny zaklada progi liczone w dokumentach na miesiac (tak liczy cennik biura);
		// gdyby doszly progi innego rodzaju, dodac pole "naglowek progow" w usludze.
		$jednostka = kw_uslugi_jednostka( $u['jednostka'] );
		$kolumna   = 'Cena ' . ( $u['netto'] ? 'netto' : 'brutto' ) . ( '' !== $jednostka ? ' / ' . $jednostka : '' );
		$bloki    .= '<div class="kw-c__blok kw-c__blok--progi"><p class="kw-c__blok-h">Progi cenowe</p><table class="kw-c__progi">';
		$bloki    .= '<caption class="kw-sr">' . kw_uslugi_e( $u['tytul'] ) . ': cena według liczby dokumentów w&nbsp;miesiącu</caption>';
		$bloki    .= '<thead><tr><th scope="col">Dokumenty w&nbsp;miesiącu</th><th scope="col">' . esc_html( $kolumna ) . '</th></tr></thead><tbody>';
		foreach ( $u['progi'] as $prog ) {
			$bloki .= '<tr><th scope="row">' . esc_html( $prog['etykieta'] ) . '</th><td>' . ( $prog['od'] ? 'od ' : '' ) . kw_uslugi_nb( kw_uslugi_kwota( $prog['cena'] ) . "\u{00A0}zł" ) . '</td></tr>';
		}
		$bloki .= '</tbody></table></div>';
	}
	$bloki .= kw_uslugi_cennik_blok( 'W cenie także', $reszta, 'tak' );
	$bloki .= kw_uslugi_cennik_blok( 'Dodatkowo płatne', $u['dodatkowo'], 'plus' );
	$bloki .= kw_uslugi_cennik_blok( 'Nie obejmuje', $u['nie_obejmuje'], 'nie' );
	$bloki .= kw_uslugi_cennik_blok( 'Od czego zależy cena', $u['czynniki'], 'kropka' );
	if ( '' !== trim( wp_strip_all_tags( $u['opis'] ) ) ) {
		$bloki .= '<div class="kw-c__blok kw-c__blok--opis">' . wp_kses_post( wpautop( $u['opis'] ) ) . '</div>';
	}
	if ( '' !== $bloki ) {
		$html .= '<details class="kw-c__wiecej"><summary><span>' . ( $cena && $u['progi'] ? 'Progi cenowe i&nbsp;szczegóły' : 'Szczegóły usługi' ) . $sr . '</span></summary>';
		$html .= '<div class="kw-c__szczegoly">' . $bloki . '</div></details>';
	}

	// Kontrakt linkow: href="#kontakt" + data-kw-usluga="<slug>"; skrypt kw-cennik.js wybiera usluge w formularzu.
	$html .= '<p class="kw-c__akcja"><a class="kw-c__btn" href="#kontakt" data-kw-usluga="' . esc_attr( $u['slug'] ) . '">Zapytaj o&nbsp;wycenę' . $sr . '</a></p>';
	return $html . '</div>';
}

/**
 * Shortcode [kw_cennik]: zakladki kategorii, w kazdej karty uslug z cena, zakresem i progami.
 * Bez JavaScriptu widac pierwsza kategorie, pozostale otwiera kotwica #cennik-<slug> (CSS :target).
 *
 * @param array|string $atrybuty Atrybuty: grupa (tylko te kategorie), bez (pomin kategorie).
 * @return string HTML.
 */
function kw_uslugi_sc_cennik( $atrybuty ) {
	static $numer = 0;
	$a = shortcode_atts(
		array(
			'grupa' => '',
			'bez'   => '',
		),
		$atrybuty,
		'kw_cennik'
	);

	$uslugi = array_filter(
		kw_uslugi_filtruj( kw_uslugi_pobierz(), kw_uslugi_slugi( $a['grupa'] ), kw_uslugi_slugi( $a['bez'] ) ),
		static function ( $u ) {
			return $u['cennik'];
		}
	);
	if ( ! $uslugi ) {
		return kw_uslugi_pusto();
	}

	++$numer;
	wp_enqueue_script( 'kw-cennik' );

	$ceny     = (bool) kw_uslugi_opcje()['ceny'];
	$p        = 1 === $numer ? 'cennik' : 'cennik' . $numer; // panele pierwszego cennika na stronie: #cennik-<slug kategorii>
	$taby     = '';
	$panele   = '';
	$pierwsza = ' is-aktywna';
	$grupy    = kw_uslugi_grupuj( $uslugi );
	$kat      = kw_uslugi_kategorie();
	foreach ( $grupy as $slug => $grupa ) {
		// Naglowek panelu: pole "Naglowek zakladki dla wyszukiwarek" kategorii, a bez niego jej nazwa. Etykieta zakladki zostaje krotka.
		$naglowek = isset( $kat[ $slug ] ) && '' !== $kat[ $slug ]['naglowek'] ? $kat[ $slug ]['naglowek'] : $grupa['nazwa'];
		$slug    = '' === (string) $slug ? 'pozostale' : (string) $slug;
		$id      = $p . '-' . $slug;
		$taby   .= sprintf(
			'<a class="kw-c__tab%4$s" id="%1$s-tab" href="#%1$s" data-kw-tab="%2$s">%3$s</a>',
			esc_attr( $id ),
			esc_attr( $slug ),
			kw_uslugi_e( $grupa['nazwa'] ),
			$pierwsza
		);
		$panele .= '<section class="kw-c__panel' . $pierwsza . '" id="' . esc_attr( $id ) . '" aria-labelledby="' . esc_attr( $id ) . '-nazwa">';
		$panele .= '<h3 class="kw-c__kategoria" id="' . esc_attr( $id ) . '-nazwa">' . kw_uslugi_e( $naglowek ) . '</h3>';
		$panele .= '<div class="kw-c__lista" role="list">';
		foreach ( $grupa['uslugi'] as $usluga ) {
			$panele .= kw_uslugi_cennik_karta( $usluga, $ceny );
		}
		$panele  .= '</div></section>';
		$pierwsza = '';
	}

	$html = '<div class="kw-cennik" data-kw-cennik>';
	if ( count( $grupy ) > 1 ) {
		$html .= '<div class="kw-c__taby" data-etykieta="Kategorie usług">' . $taby . '</div>';
	}
	return $html . $panele . '</div>';
}
add_shortcode( 'kw_cennik', 'kw_uslugi_sc_cennik' );

/**
 * Shortcode [kw_progi slug="ryczalt-bez-vat"]: progi cenowe jednej uslugi jako zwykla tabela z klasami (bez stylu wtyczki:
 * wyglad nadaje strona, np. wykres "jak cena rosnie z liczba dokumentow"). Dane te same co w kw_uslugi_progi() i na karcie
 * cennika; liczby dla skryptu sa w data-kwota (kropka dziesietna) i data-od (1 = prog bez gornej granicy).
 * Pusty wynik: nie ma takiej uslugi, jest ukryta w cenniku, nie ma progow albo ceny sa wylaczone.
 *
 * @param array|string $atrybuty Atrybuty shortcodu.
 * @return string
 */
function kw_uslugi_sc_progi( $atrybuty ) {
	$atrybuty = shortcode_atts( array( 'slug' => '' ), $atrybuty, 'kw_progi' );
	$u        = kw_uslugi_usluga_progow( $atrybuty['slug'] );
	if ( ! $u ) {
		return '';
	}
	// ponytail: naglowek kolumny zaklada progi liczone w dokumentach na miesiac, tak jak karta cennika.
	$jednostka = kw_uslugi_jednostka( $u['jednostka'] );
	$kolumna   = 'Cena ' . ( $u['netto'] ? 'netto' : 'brutto' ) . ( '' !== $jednostka ? ' / ' . $jednostka : '' );
	$html      = '<table class="kw-progi-dane" data-kw-progi="' . esc_attr( $u['slug'] ) . '">';
	$html     .= '<caption class="kw-progi-dane__podpis">' . kw_uslugi_e( $u['tytul'] ) . ': cena według liczby dokumentów w&nbsp;miesiącu</caption>';
	$html     .= '<thead><tr><th scope="col">Dokumenty w&nbsp;miesiącu</th><th scope="col">' . esc_html( $kolumna ) . '</th></tr></thead><tbody>';
	foreach ( $u['progi'] as $prog ) {
		$html .= sprintf(
			'<tr class="kw-progi-dane__wiersz" data-kwota="%1$s" data-od="%2$d"><th scope="row" class="kw-progi-dane__zakres">%3$s</th><td class="kw-progi-dane__cena">%4$s</td></tr>',
			esc_attr( $prog['cena'] ),
			$prog['od'] ? 1 : 0,
			esc_html( $prog['etykieta'] ),
			( $prog['od'] ? 'od ' : '' ) . kw_uslugi_nb( kw_uslugi_kwota( $prog['cena'] ) . "\u{00A0}zł" )
		);
	}
	return $html . '</tbody></table>';
}
add_shortcode( 'kw_progi', 'kw_uslugi_sc_progi' );

/**
 * Numer telefonu jako adres tel: (tylko cyfry i plus).
 *
 * @param string $telefon Telefon.
 * @return string
 */
function kw_uslugi_tel( $telefon ) {
	return 'tel:' . preg_replace( '/[^0-9+]/', '', (string) $telefon );
}

/**
 * Shortcode [kw_formularz]: formularz kontaktowy z polem "Usluga" i trybem awaryjnym.
 *
 * @return string HTML.
 */
function kw_uslugi_sc_formularz() {
	static $numer = 0;
	++$numer;
	$p = 'kw-f' . $numer; // Przedrostek identyfikatorow: formularz moze wystapic na stronie wiecej niz raz.

	wp_enqueue_script( 'kw-formularz' );
	wp_enqueue_script( 'kw-cennik' );

	$opcje   = kw_uslugi_opcje();
	$email   = $opcje['email'];
	$telefon = $opcje['telefon'];
	$czas    = time();

	// Adres i telefon z opcji jako odnosniki (pod polem "Wiadomosc" i w komunikacie bez JavaScriptu).
	$email_link = '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
	$tel_link   = '<a href="' . esc_url( kw_uslugi_tel( $telefon ) ) . '">' . esc_html( $telefon ) . '</a>';

	// Pole wyboru uslugi: grupy jako optgroup, uslugi bez grupy luzem, na koncu "Inna / nie wiem".
	$wybor = '<option value="">Wybierz usługę (opcjonalnie)</option>';
	$widoczne = array_filter(
		kw_uslugi_pobierz(),
		static function ( $u ) {
			return $u['formularz'];
		}
	);
	foreach ( kw_uslugi_grupuj( $widoczne ) as $slug => $grupa ) {
		$pozycje = '';
		foreach ( $grupa['uslugi'] as $usluga ) {
			$pozycje .= '<option value="' . esc_attr( $usluga['slug'] ) . '">' . esc_html( $usluga['tytul'] ) . '</option>';
		}
		$wybor .= '' === $slug ? $pozycje : '<optgroup label="' . esc_attr( $grupa['nazwa'] ) . '">' . $pozycje . '</optgroup>';
	}
	$wybor .= '<option value="inna">Inna / nie wiem</option>';

	// Pola wyceny (opcjonalne): te same listy wartosci sprawdza serwer.
	$wycena = '';
	foreach ( kw_uslugi_pola_wyceny() as $nazwa => $definicja ) {
		$pozycje = '<option value="">Wybierz (opcjonalnie)</option>';
		foreach ( $definicja[1] as $klucz => $etykieta ) {
			$pozycje .= '<option value="' . esc_attr( $klucz ) . '">' . esc_html( $etykieta ) . '</option>';
		}
		$wycena .= sprintf(
			'<div class="kw-f__pole"><label class="kw-f__etykieta" for="%1$s">%2$s</label><select class="kw-f__kontrolka kw-f__wybor" id="%1$s" name="%3$s" aria-describedby="%1$s-blad">%4$s</select><p class="kw-f__blad" id="%1$s-blad" hidden></p></div>',
			esc_attr( $p . '-' . $nazwa ),
			esc_html( $definicja[0] ),
			esc_attr( $nazwa ),
			$pozycje
		);
	}

	$polityka = get_privacy_policy_url();

	ob_start();
	?>
<div class="kw-f" id="<?php echo esc_attr( 1 === $numer ? 'kw-formularz' : 'kw-formularz-' . $numer ); ?>" data-kw-formularz
	data-endpoint="<?php echo esc_url( rest_url( 'kw/v1/kontakt' ) ); ?>"
	data-token-url="<?php echo esc_url( rest_url( 'kw/v1/token' ) ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	data-t="<?php echo esc_attr( (string) $czas ); ?>"
	data-s="<?php echo esc_attr( kw_uslugi_podpis( $czas ) ); ?>"
	data-email="<?php echo esc_attr( $email ); ?>"
	data-temat="<?php echo esc_attr( KW_USLUGI_TEMAT ); ?>"
	data-ogolne="<?php echo esc_attr( KW_USLUGI_OGOLNE ); ?>">

	<div class="kw-f__awaria" role="group" aria-labelledby="<?php echo esc_attr( $p ); ?>-awaria-h" hidden>
		<div class="kw-f__awaria-glowa">
			<svg class="kw-f__znak" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="2"/><rect class="kw-f__znak-a" x="22.5" y="12" width="3" height="16" rx="1.5"/><circle class="kw-f__znak-b" cx="24" cy="34" r="2"/></svg>
			<p class="kw-f__awaria-h" id="<?php echo esc_attr( $p ); ?>-awaria-h" role="status" tabindex="-1">Mamy awarię, nie udało się wysłać wiadomości</p>
		</div>
		<p class="kw-f__awaria-p">Możesz wysłać ją ręcznie na adres <a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>. <span data-kw-kopia="tak" hidden>Treść wiadomości już dla Ciebie skopiowaliśmy, możesz ją wkleić.</span></p>
		<p class="kw-f__awaria-akcja"><a class="kw-f__btn" data-kw-mailto href="<?php echo esc_url( 'mailto:' . $email ); ?>">Wyślij ponownie</a></p>
		<p class="kw-f__awaria-p kw-f__awaria-p--maly">Jeśli nie otwiera Ci się okno z&nbsp;nową wiadomością, zaloguj się na swoją pocztę i&nbsp;wyślij nam e-mail. <span data-kw-kopia="tak" hidden>Treść wiadomości już dla Ciebie skopiowaliśmy, możesz ją wkleić.</span></p>
		<div class="kw-f__awaria-przyciski">
			<button type="button" class="kw-f__btn kw-f__btn--obrys" data-kw-kopiuj data-tekst-tak="Skopiuj treść ponownie" data-tekst-nie="Kliknij, aby skopiować treść">Kliknij, aby skopiować treść</button>
			<button type="button" class="kw-f__btn kw-f__btn--obrys" data-kw-ponow>Spróbuj wysłać z&nbsp;formularza jeszcze raz</button>
		</div>
		<p class="kw-f__awaria-info" data-kw-kopia-info role="status" aria-live="polite"></p>
		<label class="kw-f__etykieta" for="<?php echo esc_attr( $p ); ?>-kopia">Treść Twojej wiadomości</label>
		<textarea class="kw-f__kontrolka kw-f__kopia" id="<?php echo esc_attr( $p ); ?>-kopia" rows="6" readonly data-kw-tresc></textarea>
	</div>

	<form class="kw-f__form" novalidate>
		<fieldset class="kw-f__pola">
			<legend class="kw-sr">Formularz kontaktowy</legend>
			<p class="kw-f__legenda"><span class="kw-f__gw" aria-hidden="true">*</span> pola wymagane</p>

			<div class="kw-f__pole">
				<label class="kw-f__etykieta" for="<?php echo esc_attr( $p ); ?>-usluga">Usługa</label>
				<select class="kw-f__kontrolka kw-f__wybor" id="<?php echo esc_attr( $p ); ?>-usluga" name="usluga"><?php echo $wybor; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_attr/esc_html. ?></select>
			</div>

			<div class="kw-f__rzad"><?php echo $wycena; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_attr/esc_html. ?></div>

			<div class="kw-f__pole">
				<label class="kw-f__etykieta" for="<?php echo esc_attr( $p ); ?>-imie">Imię <span class="kw-f__gw" aria-hidden="true">*</span></label>
				<input class="kw-f__kontrolka" id="<?php echo esc_attr( $p ); ?>-imie" name="imie" type="text" autocomplete="given-name" maxlength="100" required aria-describedby="<?php echo esc_attr( $p ); ?>-imie-blad">
				<p class="kw-f__blad" id="<?php echo esc_attr( $p ); ?>-imie-blad" hidden></p>
			</div>

			<div class="kw-f__pole">
				<label class="kw-f__etykieta" for="<?php echo esc_attr( $p ); ?>-email">Adres e-mail <span class="kw-f__gw" aria-hidden="true">*</span></label>
				<input class="kw-f__kontrolka" id="<?php echo esc_attr( $p ); ?>-email" name="email" type="email" inputmode="email" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="254" required aria-describedby="<?php echo esc_attr( $p ); ?>-email-blad">
				<p class="kw-f__blad" id="<?php echo esc_attr( $p ); ?>-email-blad" hidden></p>
			</div>

			<div class="kw-f__pole">
				<label class="kw-f__etykieta" for="<?php echo esc_attr( $p ); ?>-telefon">Numer telefonu (opcjonalnie)</label>
				<input class="kw-f__kontrolka" id="<?php echo esc_attr( $p ); ?>-telefon" name="telefon" type="tel" inputmode="tel" autocomplete="tel" maxlength="30" aria-describedby="<?php echo esc_attr( $p ); ?>-telefon-blad">
				<p class="kw-f__blad" id="<?php echo esc_attr( $p ); ?>-telefon-blad" hidden></p>
			</div>

			<div class="kw-f__pole">
				<label class="kw-f__etykieta" for="<?php echo esc_attr( $p ); ?>-wiadomosc">Wiadomość <span class="kw-f__gw" aria-hidden="true">*</span></label>
				<textarea class="kw-f__kontrolka kw-f__tekst" id="<?php echo esc_attr( $p ); ?>-wiadomosc" name="wiadomosc" rows="7" minlength="10" maxlength="5000" required placeholder="Na przykład: spółka z o.o., około 30 faktur miesięcznie, dwóch pracowników." aria-describedby="<?php echo esc_attr( $p ); ?>-wiadomosc-blad"></textarea>
				<p class="kw-f__blad" id="<?php echo esc_attr( $p ); ?>-wiadomosc-blad" hidden></p>
				<p class="kw-f__pomoc">Wolisz napisać od razu? <?php echo $email_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_url/esc_html. ?> <span class="kw-f__lub">albo <?php echo $tel_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_url/esc_html. ?></span></p>
			</div>

			<div class="kw-f__x" aria-hidden="true">
				<label for="<?php echo esc_attr( $p ); ?>-x">Nie wypełniaj tego pola</label>
				<input id="<?php echo esc_attr( $p ); ?>-x" name="kw_x" type="text" tabindex="-1" autocomplete="off">
			</div>
		</fieldset>

		<div class="kw-f__stopka">
			<button type="submit" class="kw-f__btn kw-f__wyslij" data-tekst="Wyślij wiadomość" data-tekst-wysylanie="Wysyłanie..."><span class="kw-f__btn-txt">Wyślij wiadomość</span><span class="kw-f__krecik" aria-hidden="true"></span></button>
			<p class="kw-f__rodo">Administratorem danych jest Kancelaria Wieczorek sp.&nbsp;z&nbsp;o.o. Dane z&nbsp;formularza wykorzystamy wyłącznie do odpowiedzi na Twoją wiadomość.<?php if ( $polityka ) : ?> Szczegóły: <a href="<?php echo esc_url( $polityka ); ?>">polityka prywatności</a>.<?php endif; ?></p>
		</div>
		<p class="kw-sr" data-kw-status role="status" aria-live="polite"></p>
	</form>

	<div class="kw-f__sukces" hidden>
		<svg class="kw-f__ok" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle class="kw-f__ok-kolo" cx="24" cy="24" r="22" fill="none" stroke="currentColor" stroke-width="2"/><g transform="translate(13.5 24.5) rotate(45)"><rect class="kw-f__ok-a" x="0" y="-1.5" width="10" height="3" rx="1.5"/></g><g transform="translate(19.5 30.5) rotate(-45)"><rect class="kw-f__ok-b" x="0" y="-1.5" width="19" height="3" rx="1.5"/></g></svg>
		<p class="kw-f__sukces-h" role="status" tabindex="-1">Dziękujemy za wiadomość.</p>
		<p class="kw-f__sukces-p">Odpowiemy najszybciej, jak to możliwe.</p>
	</div>

	<noscript><p class="kw-f__noscript">Formularz wymaga włączonej obsługi JavaScript. Napisz na <?php echo $email_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_url/esc_html. ?> albo zadzwoń: <?php echo $tel_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_url/esc_html. ?></p></noscript>
</div>
	<?php
	return (string) ob_get_clean();
}
add_shortcode( 'kw_formularz', 'kw_uslugi_sc_formularz' );

/**
 * Zasoby na stronie: jeden maly arkusz zawsze, skrypt tylko razem z formularzem.
 */
function kw_uslugi_zasoby() {
	// ponytail: arkusz laduje sie na kazdej stronie, bo shortcode renderuje sie po <head> (inaczej karty mignelyby bez stylu).
	// Gdyby mial ciazyc: ladowac warunkowo po wykryciu "[kw_" w tresci i w _elementor_data.
	wp_enqueue_style(
		'kw-uslugi',
		plugins_url( 'assets/kw-uslugi.css', KW_USLUGI_PLIK ),
		array(),
		(string) filemtime( KW_USLUGI_KATALOG . 'assets/kw-uslugi.css' )
	);
	// kw-cennik.js: zakladki cennika i kontrakt linkow (#cennik-<kategoria>, data-kw-usluga, #kontakt-usluga-<slug>).
	// Laduje sie z cennikiem i z formularzem, bo linki moga stac w dowolnym miejscu strony.
	foreach ( array( 'kw-formularz', 'kw-cennik' ) as $skrypt ) {
		wp_register_script(
			$skrypt,
			plugins_url( 'assets/' . $skrypt . '.js', KW_USLUGI_PLIK ),
			array(),
			(string) filemtime( KW_USLUGI_KATALOG . 'assets/' . $skrypt . '.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'kw_uslugi_zasoby' );
