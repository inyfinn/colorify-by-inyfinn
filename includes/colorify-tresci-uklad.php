<?php
/**
 * Moduł "Edytor treści" (od 2.13.0): wygląd tekstu (tylko tokeny marki), układ stron (kolejność, ukrywanie sekcji),
 * nowe sekcje z biblioteki i akapity (dodaj, usuń, przesuń, duplikuj). Ładowany zawsze przez colorify-tresci.php
 * (same funkcje); hooki podpina tylko colorify_tresci_uklad_hooki(), gdy moduł jest dostępny.
 *
 * Dane żyją w TEJ SAMEJ opcji co treści (jedna bramka wersji, jedna historia, przywracanie obejmuje wszystko),
 * pod kluczami zastrzeżonymi (klucze pól nigdy nie zaczynają się od "__"):
 *   __style  = cel => array( 'b' => array( właściwość => token ), 'm' => array( ... ) )  b = komputer (i tablet), m = telefon <= 767 px
 *              cel: klucz pola ("omnie_naglowek"), akapit grupy ("omnie_akapity.p-x1y2z3"), pole nowej sekcji
 *              ("s-ab12cd34.naglowek", "s-ab12cd34.akapity.p-..."), sekcja ("#omnie", "#s-ab12cd34")
 *   __uklad  = strona => array( 'o' => kolejność id, 'h' => ukryte id )   (tylko gdy różni się od domyślnego)
 *   __sekcje = id => array( 'typ' => tekst|tekst_zdjecie|cytat|cta|odstep, 'strona' => id strony, 'v' => wartości pól )
 * Wartość użytkownika nigdy nie trafia do CSS wprost: zapisujemy token, do strony idzie wartość z mapy (allowlist).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Tokeny wyglądu (motyw może podmienić kolory i kroje: rejestr 'tokeny' => array( 'c' => ..., 'ff' => ... ))
 * ------------------------------------------------------------------------- */

/** Właściwości stylu: klucz => array( etykieta, właściwość CSS, poziom: 1 = od razu widoczna, 2 = "Więcej ustawień", 's' = sekcja ). */
function colorify_tresci_styl_wlasciwosci() {
	return array(
		'ff' => array( 'Krój pisma', 'font-family', 1 ),
		'fz' => array( 'Rozmiar', 'font-size', 1 ),
		'fw' => array( 'Grubość', 'font-weight', 1 ),
		'c'  => array( 'Kolor', 'color', 1 ),
		'ta' => array( 'Wyrównanie', 'text-align', 1 ),
		'mb' => array( 'Odstęp pod spodem', 'margin-bottom', 1 ),
		'lh' => array( 'Interlinia', 'line-height', 2 ),
		'ls' => array( 'Odstęp liter', 'letter-spacing', 2 ),
		'mt' => array( 'Odstęp nad', 'margin-top', 2 ),
		'mw' => array( 'Szerokość tekstu', 'max-width', 2 ),
		'tt' => array( 'Wielkie litery', 'text-transform', 2 ),
		'fs' => array( 'Kursywa', 'font-style', 2 ),
		'pt' => array( 'Odstęp nad sekcją', 'padding-top', 's' ),
		'pb' => array( 'Odstęp pod sekcją', 'padding-bottom', 's' ),
	);
}

/** Token => array( etykieta, wartość CSS ) dla każdej właściwości. 'lh' to liczba 1–2,2 (zakres, nie lista). */
function colorify_tresci_tokeny() {
	static $t = null;
	if ( null !== $t ) {
		return $t;
	}
	$odstep = array(
		'0'   => array( 'Brak', '0' ),
		'xs'  => array( 'Bardzo mały', '0.5rem' ),
		'sm'  => array( 'Mały', '1rem' ),
		'md'  => array( 'Średni', '1.5rem' ),
		'lg'  => array( 'Duży', '2.5rem' ),
		'xl'  => array( 'Bardzo duży', '5rem' ),
		'xxl' => array( 'Ogromny', '7.5rem' ),
	);
	$t = array(
		'ff' => array(
			'heading' => array( 'Nagłówkowy', 'var(--font-heading, Georgia, serif)' ),
			'body'    => array( 'Tekstowy', 'var(--font-body, sans-serif)' ),
		),
		// Rozmiary płynne (clamp): telefon dostaje mniejszą wartość sam, bez osobnego ustawienia.
		'fz' => array(
			'xs'  => array( 'XS', 'clamp(0.75rem, 0.72rem + 0.15vw, 0.875rem)' ),
			's'   => array( 'S', 'clamp(0.875rem, 0.84rem + 0.2vw, 1rem)' ),
			'm'   => array( 'M', 'clamp(1rem, 0.95rem + 0.3vw, 1.25rem)' ),
			'l'   => array( 'L', 'clamp(1.25rem, 1.1rem + 0.7vw, 1.75rem)' ),
			'xl'  => array( 'XL', 'clamp(1.75rem, 1.4rem + 1.6vw, 2.75rem)' ),
			'xxl' => array( 'XXL', 'clamp(2.25rem, 1.6rem + 3vw, 3.75rem)' ),
		),
		'fw' => array(
			'300' => array( 'Cienka', '300' ),
			'400' => array( 'Zwykła', '400' ),
			'500' => array( 'Średnia', '500' ),
			'600' => array( 'Gruba', '600' ),
		),
		'c'  => array(
			'braz'  => array( 'Brąz', '#301e0e' ),
			'tekst' => array( 'Szary tekst', '#56524e' ),
			'zloto' => array( 'Złoty', '#b8955a' ),
			'krem'  => array( 'Kremowy', '#fffcf4' ),
		),
		'ta' => array(
			'left'    => array( 'Do lewej', 'left' ),
			'center'  => array( 'Do środka', 'center' ),
			'right'   => array( 'Do prawej', 'right' ),
			'justify' => array( 'Do obu krawędzi', 'justify' ),
		),
		'mb' => $odstep,
		'mt' => $odstep,
		'ls' => array(
			'ciasno'  => array( 'Ciaśniej', '-0.02em' ),
			'normal'  => array( 'Normalnie', '0' ),
			'szerzej' => array( 'Szerzej', '0.06em' ),
			'szeroko' => array( 'Szeroko', '0.15em' ),
			'bardzo'  => array( 'Bardzo szeroko', '0.3em' ),
		),
		'mw' => array(
			'waska'    => array( 'Wąska', '32rem' ),
			'standard' => array( 'Standardowa', '42rem' ),
			'szeroka'  => array( 'Szeroka', '56rem' ),
			'pelna'    => array( 'Pełna', 'none' ),
		),
		'tt' => array(
			'none'  => array( 'Normalnie', 'none' ),
			'upper' => array( 'WIELKIE LITERY', 'uppercase' ),
		),
		'fs' => array(
			'normal' => array( 'Prosto', 'normal' ),
			'italic' => array( 'Kursywa', 'italic' ),
		),
		'pt' => $odstep,
		'pb' => $odstep,
	);
	$wlasne = (array) ( colorify_tresci_rejestr()['tokeny'] ?? array() );
	foreach ( $wlasne as $p => $lista ) {
		if ( isset( $t[ $p ] ) && is_array( $lista ) && $lista ) {
			$t[ $p ] = $lista; // motyw podaje własną listę (np. paletę marki), nie dopisuje do domyślnej
		}
	}
	return $t;
}

/** Wartość CSS tokenu albo '' (nieznany token = nic). Interlinia: liczba 1–2,2 w krokach 0,05. */
function colorify_tresci_styl_css( $p, $tok ) {
	if ( 'lh' === $p ) {
		if ( ! is_numeric( $tok ) ) {
			return '';
		}
		$v = round( max( 1, min( 2.2, (float) $tok ) ) * 20 ) / 20;
		return rtrim( rtrim( number_format( $v, 2, '.', '' ), '0' ), '.' );
	}
	$t = colorify_tresci_tokeny();
	return isset( $t[ $p ][ (string) $tok ] ) ? (string) $t[ $p ][ (string) $tok ][1] : '';
}

/* ---------------------------------------------------------------------------
 * Biblioteka nowych sekcji: pola każdego typu (typ, etykieta, domyślna, extra) i klasy z motywu
 * ------------------------------------------------------------------------- */

function colorify_tresci_typy_sekcji() {
	static $t = null;
	if ( null !== $t ) {
		return $t;
	}
	$kontakt = home_url( '/#kontakt' );
	$tlo     = function ( $def ) {
		return array( 'select', 'Tło sekcji', $def, array( 'opcje' => array( 'jasne' => 'Jasne, jak strona', 'kremowe' => 'Kremowe', 'ciemne' => 'Ciemne (brąz)' ) ) );
	};
	$akap    = function ( $tekst ) {
		return array( 'akapity', 'Akapity', array( array( 'id' => 'p-start01', 'w' => 'tekst', 't' => $tekst ) ) );
	};
	$t = array(
		'tekst'         => array(
			'nazwa' => 'Tekst',
			'opis'  => 'Nagłówek, akapity i przycisk',
			'ikona' => 'tekst',
			'pola'  => array(
				'nadtytul' => array( 'text', 'Mały napis nad nagłówkiem', '' ),
				'naglowek' => array( 'text', 'Nagłówek', 'Nowa sekcja' ),
				'akapity'  => $akap( 'Tu wpisz tekst tej sekcji. Kolejny akapit dodasz przyciskiem „Dodaj akapit”.' ),
				'przycisk' => array( 'text', 'Napis na przycisku (puste = bez przycisku)', '' ),
				'link'     => array( 'url', 'Dokąd prowadzi przycisk', $kontakt ),
				'uklad'    => array( 'select', 'Ułożenie tekstu', 'srodek', array( 'opcje' => array( 'srodek' => 'Na środku', 'lewo' => 'Do lewej', 'szeroko' => 'Na całą szerokość' ) ) ),
				'tlo'      => $tlo( 'jasne' ),
			),
		),
		'tekst_zdjecie' => array(
			'nazwa' => 'Tekst + zdjęcie',
			'opis'  => 'Tekst obok zdjęcia, jak na podstronie „O mnie”',
			'ikona' => 'zdjecie',
			'pola'  => array(
				'nadtytul' => array( 'text', 'Mały napis nad nagłówkiem', '' ),
				'naglowek' => array( 'text', 'Nagłówek', 'Nowa sekcja ze zdjęciem' ),
				'akapity'  => $akap( 'Tu wpisz tekst obok zdjęcia.' ),
				'zdjecie'  => array( 'media', 'Zdjęcie', '', array( 'kind' => 'image', 'kadr' => array( 'r' => '4/5', 'rm' => '4/5', 'xy' => array( 50, 50 ) ) ) ),
				'alt'      => array( 'text', 'Opis zdjęcia (dla Google i czytników ekranu)', '' ),
				'zdjecie_po' => array( 'select', 'Zdjęcie po której stronie', 'prawo', array( 'opcje' => array( 'prawo' => 'Po prawej', 'lewo' => 'Po lewej' ) ) ),
				'przycisk' => array( 'text', 'Napis na przycisku (puste = bez przycisku)', '' ),
				'link'     => array( 'url', 'Dokąd prowadzi przycisk', $kontakt ),
				'tlo'      => $tlo( 'jasne' ),
			),
		),
		'cytat'         => array(
			'nazwa' => 'Cytat',
			'opis'  => 'Duże zdanie, np. słowa pary',
			'ikona' => 'cytat',
			'pola'  => array(
				'cytat' => array( 'area', 'Cytat', 'Tu wpisz cytat, np. słowa pary po ślubie.', array( 'rows' => 3 ) ),
				'autor' => array( 'text', 'Kto to powiedział (puste = bez podpisu)', '' ),
				'tlo'   => $tlo( 'kremowe' ),
			),
		),
		'cta'           => array(
			'nazwa' => 'Przycisk z zachętą',
			'opis'  => 'Nagłówek, zdanie i wyraźny przycisk',
			'ikona' => 'cta',
			'pola'  => array(
				'naglowek' => array( 'text', 'Nagłówek', 'Sprawdźmy Wasz termin' ),
				'tekst'    => array( 'area', 'Zdanie pod nagłówkiem', 'Napiszcie do mnie, odpowiem najszybciej, jak się da.', array( 'rows' => 2 ) ),
				'przycisk' => array( 'text', 'Napis na przycisku', 'Napisz do mnie' ),
				'link'     => array( 'url', 'Dokąd prowadzi przycisk', $kontakt ),
				'tlo'      => $tlo( 'ciemne' ),
			),
		),
		'odstep'        => array(
			'nazwa' => 'Odstęp',
			'opis'  => 'Pusta przerwa między sekcjami',
			'ikona' => 'odstep',
			'pola'  => array(
				'wysokosc' => array( 'select', 'Wysokość', 'lg', array( 'opcje' => array( 'sm' => 'Mała', 'md' => 'Średnia', 'lg' => 'Duża', 'xl' => 'Bardzo duża' ) ) ),
				'telefon'  => array( 'bool', 'Ukryj na telefonie (tam pusta przerwa często przeszkadza)', 1 ),
			),
		),
	);
	return $t;
}

/**
 * Klasy HTML nowych sekcji: motyw podaje swoje (rejestr 'klasy'), żeby nowa sekcja wyglądała jak reszta strony.
 * Bez nich Colorify używa własnych klas cft-sek* z małym arkuszem (colorify_tresci_css_sekcji()).
 */
function colorify_tresci_klasy() {
	static $k = null;
	if ( null === $k ) {
		$k = (array) ( colorify_tresci_rejestr()['klasy'] ?? array() ) + array(
			'sekcja'        => 'cft-sek',
			'tlo_jasne'     => '',
			'tlo_kremowe'   => 'cft-sek--krem',
			'tlo_ciemne'    => 'cft-sek--ciemne',
			'kontener'      => 'cft-sek__in',
			'wiersz'        => 'cft-sek__wiersz',
			'kol_srodek'    => 'cft-sek__kol cft-sek__kol--srodek',
			'kol_lewo'      => 'cft-sek__kol',
			'kol_szeroko'   => 'cft-sek__kol cft-sek__kol--szeroko',
			'kol_tekst'     => 'cft-sek__kol',
			'kol_zdjecie'   => 'cft-sek__kol',
			'kol_tekst_l'   => 'cft-sek__kol',
			'kol_zdjecie_l' => 'cft-sek__kol cft-sek__kol--pierwsza',
			'ozdobnik'      => '',
			'nadtytul'      => 'cft-sek__nad',
			'naglowek'      => 'cft-sek__h',
			'naglowek_tz'   => 'cft-sek__h',
			'przycisk'      => 'cft-sek__btn',
			'przycisk_ciemne' => 'cft-sek__btn cft-sek__btn--jasny',
			'zdjecie'       => 'cft-sek__img',
			'cytat'         => 'cft-sek__cytat',
			'autor'         => 'cft-sek__autor',
			'cta_tekst'     => 'cft-sek__p',
			'warianty'      => array(
				'tekst' => array( 'Zwykły akapit', 'cft-sek__p' ),
				'lead'  => array( 'Większy, na początek', 'cft-sek__p cft-sek__p--lead' ),
				'punch' => array( 'Wyróżniony', 'cft-sek__p cft-sek__p--wyr' ),
			),
		);
	}
	return $k;
}

/* ---------------------------------------------------------------------------
 * Układ stron: sekcje szablonu z rejestru motywu ('uklad' => strona => id => array( label, render, stala, karta, kotwica_id ))
 * ------------------------------------------------------------------------- */

function colorify_tresci_uklad_def() {
	$u = (array) ( colorify_tresci_rejestr()['uklad'] ?? array() );
	foreach ( $u as $page => $sekcje ) {
		foreach ( (array) $sekcje as $id => $s ) {
			$u[ $page ][ $id ] = (array) $s + array( 'label' => $id, 'render' => null, 'stala' => false, 'karta' => $id, 'kotwica_id' => '' );
		}
	}
	return $u;
}

/** Nowe sekcje (zapisane albo z podglądu). */
function colorify_tresci_sekcje_nowe( $stored = null ) {
	$s = null === $stored ? colorify_tresci_stored() : (array) $stored;
	return isset( $s['__sekcje'] ) && is_array( $s['__sekcje'] ) ? $s['__sekcje'] : array();
}

/** Kolejność domyślna strony: sekcje szablonu, potem nowe sekcje w kolejności dodania. */
function colorify_tresci_uklad_domyslny( $page, $sekcje ) {
	$o = array_keys( (array) ( colorify_tresci_uklad_def()[ $page ] ?? array() ) );
	foreach ( (array) $sekcje as $id => $s ) {
		if ( ( $s['strona'] ?? '' ) === $page ) {
			$o[] = (string) $id;
		}
	}
	return $o;
}

/**
 * Znormalizowany układ strony: array( 'o' => kolejność, 'h' => ukryte ).
 * Nieznane id (usunięte z motywu) pomijamy, nowe sekcje szablonu wstawiamy za ich domyślnym poprzednikiem,
 * nowe sekcje bez miejsca idą na koniec; sekcje "stala" (np. pierwszy ekran) zawsze na początku i zawsze widoczne.
 */
function colorify_tresci_uklad_norm( $page, $zap, $sekcje ) {
	$def   = (array) ( colorify_tresci_uklad_def()[ $page ] ?? array() );
	$ids   = array_keys( $def );
	$nowe  = array();
	foreach ( (array) $sekcje as $id => $s ) {
		if ( ( $s['strona'] ?? '' ) === $page ) {
			$nowe[] = (string) $id;
		}
	}
	$o = array();
	foreach ( (array) ( $zap['o'] ?? array() ) as $id ) {
		$id = (string) $id;
		if ( ( isset( $def[ $id ] ) || in_array( $id, $nowe, true ) ) && ! in_array( $id, $o, true ) ) {
			$o[] = $id;
		}
	}
	foreach ( $ids as $i => $id ) {
		if ( ! in_array( $id, $o, true ) ) {
			$pos = $i ? array_search( $ids[ $i - 1 ], $o, true ) : false;
			array_splice( $o, false === $pos ? 0 : $pos + 1, 0, array( $id ) );
		}
	}
	foreach ( $nowe as $id ) {
		if ( ! in_array( $id, $o, true ) ) {
			$o[] = $id;
		}
	}
	$stale = array();
	foreach ( $def as $id => $s ) {
		if ( ! empty( $s['stala'] ) ) {
			$stale[] = $id;
		}
	}
	$o = array_merge( $stale, array_values( array_diff( $o, $stale ) ) );
	$h = array_values( array_diff( array_intersect( array_map( 'strval', (array) ( $zap['h'] ?? array() ) ), $o ), $stale ) );
	return array( 'o' => $o, 'h' => $h );
}

function colorify_tresci_uklad_strony( $page, $stored = null ) {
	$s = null === $stored ? colorify_tresci_stored() : (array) $stored;
	return colorify_tresci_uklad_norm( $page, $s['__uklad'][ $page ] ?? array(), colorify_tresci_sekcje_nowe( $s ) );
}

/* ---------------------------------------------------------------------------
 * Walidacja (jedna droga dla panelu, WP-CLI i agenta AI: wołana z colorify_tresci_z_formularza())
 * ------------------------------------------------------------------------- */

/** JSON (WP-CLI, ukryte pole panelu) albo tablica. */
function colorify_tresci_json( $v ) {
	if ( is_string( $v ) ) {
		$v = json_decode( $v, true );
	}
	return is_array( $v ) ? $v : array();
}

/** Style: tylko znane właściwości i tokeny; cele nowych sekcji, których już nie ma, wypadają. */
function colorify_tresci_style_z( $raw, $sekcje ) {
	$out = array();
	foreach ( colorify_tresci_json( $raw ) as $cel => $s ) {
		$cel = (string) $cel;
		if ( ! is_array( $s ) || ! preg_match( '/^#?[a-z0-9_-]+([.][a-z0-9_-]+){0,2}$/', $cel ) ) {
			continue;
		}
		if ( preg_match( '/^#?(s-[a-z0-9]{6,12})([.]|$)/', $cel, $m ) && ! isset( $sekcje[ $m[1] ] ) ) {
			continue; // style usuniętej sekcji
		}
		$sekcja = '#' === $cel[0];
		foreach ( array( 'b', 'm' ) as $d ) {
			foreach ( (array) ( $s[ $d ] ?? array() ) as $p => $tok ) {
				$meta = colorify_tresci_styl_wlasciwosci()[ $p ] ?? null;
				if ( ! $meta || ( 's' === $meta[2] ) !== $sekcja || ! is_scalar( $tok ) || '' === colorify_tresci_styl_css( $p, $tok ) ) {
					continue; // sekcja ma tylko odstępy sekcji, tekst tylko właściwości tekstu
				}
				$out[ $cel ][ $d ][ $p ] = 'lh' === $p ? colorify_tresci_styl_css( 'lh', $tok ) : (string) $tok;
			}
		}
	}
	ksort( $out );
	return $out;
}

/** Układ: znane strony, znane id; strona równa domyślnej nie jest zapisywana. */
function colorify_tresci_uklad_z( $raw, $sekcje ) {
	$raw = colorify_tresci_json( $raw );
	$out = array();
	foreach ( array_keys( colorify_tresci_uklad_def() ) as $page ) {
		if ( ! isset( $raw[ $page ] ) || ! is_array( $raw[ $page ] ) ) {
			continue;
		}
		$n = colorify_tresci_uklad_norm( $page, $raw[ $page ], $sekcje );
		if ( $n['o'] !== colorify_tresci_uklad_norm( $page, array(), $sekcje )['o'] || $n['h'] ) {
			$out[ $page ] = $n;
		}
	}
	return $out;
}

/** Jedna wartość pola nowej sekcji według definicji array( typ, etykieta, domyślna, extra ). */
function colorify_tresci_sekcja_pole_z( $d, $v ) {
	switch ( $d[0] ) {
		case 'text':
			return sanitize_text_field( is_scalar( $v ) ? (string) $v : '' );
		case 'area':
			return colorify_tresci_z_edycji( is_scalar( $v ) ? (string) $v : '' );
		case 'url':
			return esc_url_raw( trim( is_scalar( $v ) ? (string) $v : '' ) );
		case 'select':
			return is_scalar( $v ) && isset( $d[3]['opcje'][ (string) $v ] ) ? (string) $v : (string) $d[2];
		case 'bool':
			return empty( $v ) ? 0 : 1;
		case 'media':
			$v = is_scalar( $v ) ? trim( (string) $v ) : '';
			return ctype_digit( $v ) && (int) $v > 0 && get_post( (int) $v ) ? (int) $v : 0;
		case 'akapity':
			return colorify_tresci_akapity_z( $v, array( 'warianty' => colorify_tresci_klasy()['warianty'] ) );
	}
	return '';
}

/**
 * Nowe sekcje: z panelu cft[__i][<id>][<pole>] (+ typ, strona, usun), z WP-CLI JSON id => array( typ, strona, v ).
 * Id: "s-" + 6–12 znaków a-z0-9 (stabilne, nigdy numer pozycji). Najwyżej 40 sekcji.
 */
function colorify_tresci_sekcje_z( $raw ) {
	$typy   = colorify_tresci_typy_sekcji();
	$strony = array_keys( colorify_tresci_uklad_def() );
	$out    = array();
	foreach ( colorify_tresci_json( $raw ) as $id => $s ) {
		$id = (string) $id;
		if ( ! is_array( $s ) || ! preg_match( '/^s-[a-z0-9]{6,12}$/', $id ) || ! empty( $s['usun'] ) ) {
			continue;
		}
		$typ = (string) ( $s['typ'] ?? '' );
		$str = (string) ( $s['strona'] ?? '' );
		if ( ! isset( $typy[ $typ ] ) || ! in_array( $str, $strony, true ) ) {
			continue;
		}
		$src = isset( $s['v'] ) && is_array( $s['v'] ) ? $s['v'] : $s;
		$v   = array();
		foreach ( $typy[ $typ ]['pola'] as $p => $d ) {
			$v[ $p ] = colorify_tresci_sekcja_pole_z( $d, array_key_exists( $p, $src ) ? $src[ $p ] : $d[2] );
			if ( 'media' === $d[0] && ! empty( $d[3]['kadr'] ) ) {
				$k   = colorify_tresci_kadr_z_tekstu( $src[ $p . '__kadr' ] ?? '' );
				$def = array( 'x' => $d[3]['kadr']['xy'][0], 'y' => $d[3]['kadr']['xy'][1], 'z' => 1 );
				if ( $k && '' !== colorify_kadr_css( $k, $def ) ) {
					$v[ $p . '__kadr' ] = $k;
				}
			}
		}
		$out[ $id ] = array( 'typ' => $typ, 'strona' => $str, 'v' => $v );
		if ( count( $out ) >= 40 ) {
			break;
		}
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Akapity (pole typu 'akapity'): lista bloków array( id, w = wariant, t = tekst ).
 * Grupa w motywie może zbierać stare pola akapitów ('z_pol' => klucz => wariant): blok z id = klucz starego pola
 * bierze tekst z tego pola (panel, WP-CLI i stare klucze działają jak dotąd), nowe bloki mają id "p-…" i własny tekst.
 * ------------------------------------------------------------------------- */

function colorify_tresci_warianty( $f ) {
	return ! empty( $f['warianty'] ) ? (array) $f['warianty'] : colorify_tresci_klasy()['warianty'];
}

/** Domyślna lista bloków pola: z 'z_pol' (stare pola w kolejności) albo z 'default'. */
function colorify_tresci_akapity_domyslne( $f ) {
	if ( ! empty( $f['z_pol'] ) ) {
		$out = array();
		foreach ( (array) $f['z_pol'] as $k => $w ) {
			$out[] = array( 'id' => (string) $k, 'w' => (string) $w );
		}
		return $out;
	}
	return is_array( $f['default'] ?? null ) ? $f['default'] : array();
}

/** Bloki z formularza (klucz tablicy = id bloku, kolejność = kolejność w formularzu) albo z JSON listy. */
function colorify_tresci_akapity_z( $raw, $f ) {
	$z    = (array) ( $f['z_pol'] ?? array() );
	$war  = colorify_tresci_warianty( $f );
	$out  = array();
	$byly = array();
	foreach ( colorify_tresci_json( $raw ) as $k => $b ) {
		if ( ! is_array( $b ) ) {
			continue; // znacznik obecności pola ("_") i śmieci
		}
		$id = (string) ( $b['id'] ?? $k );
		if ( isset( $byly[ $id ] ) ) {
			continue;
		}
		$w = isset( $b['w'] ) && isset( $war[ (string) $b['w'] ] ) ? (string) $b['w'] : ( isset( $z[ $id ] ) ? (string) $z[ $id ] : (string) array_key_first( $war ) );
		if ( isset( $z[ $id ] ) ) {
			$out[] = array( 'id' => $id, 'w' => $w );
		} elseif ( preg_match( '/^p-[a-z0-9]{6,12}$/', $id ) ) {
			$t = colorify_tresci_z_edycji( is_scalar( $b['t'] ?? '' ) ? (string) ( $b['t'] ?? '' ) : '' );
			if ( '' === $t ) {
				continue; // pusty akapit nie zostaje
			}
			$out[] = array( 'id' => $id, 'w' => $w, 't' => $t );
		} else {
			continue;
		}
		$byly[ $id ] = true;
		if ( count( $out ) >= 40 ) {
			break;
		}
	}
	return $out;
}

/**
 * HTML akapitów: bloki złączone $sep (motyw podaje wcięcie, żeby HTML bez zmian był bajt w bajt taki jak dawniej).
 * $pk = prefiks celu stylu i klucza podglądu (pole grupy albo "s-…​.akapity"); $wartosc = lista bloków.
 */
function colorify_tresci_akapity_html( $pk, $wartosc, $f, $sep = '' ) {
	$z    = (array) ( $f['z_pol'] ?? array() );
	$war  = colorify_tresci_warianty( $f );
	$out  = array();
	foreach ( (array) $wartosc as $b ) {
		if ( ! is_array( $b ) || empty( $b['id'] ) ) {
			continue;
		}
		$w   = isset( $war[ $b['w'] ?? '' ] ) ? $b['w'] : array_key_first( $war );
		$cls = (string) $war[ $w ][1];
		if ( isset( $z[ $b['id'] ] ) ) {
			$out[] = colorify_tresci_tag_html( $b['id'], 'p', $cls ); // stare pole: ten sam HTML co ej_tag()
			continue;
		}
		$html = wp_kses( (string) ( $b['t'] ?? '' ), colorify_tresci_kses() );
		$cel  = $pk . '.' . $b['id'];
		$out[] = '' === trim( $html ) ? '' : '<p' . ( '' !== $cls ? ' class="' . esc_attr( $cls ) . '"' : '' ) . colorify_tresci_styl_attr( $cel ) . colorify_tresci_podglad_attr( $cel, false ) . '>' . $html . '</p>';
	}
	return implode( $sep, $out );
}

/** Drukuje akapity pola grupy (szablon motywu): colorify_tresci_akapity( 'omnie_akapity', $wciecie ). */
function colorify_tresci_akapity( $key, $sep = '' ) {
	$pola = colorify_tresci_pola();
	if ( ! isset( $pola[ $key ] ) ) {
		return;
	}
	echo colorify_tresci_akapity_html( $key, colorify_tresc( $key ), $pola[ $key ], $sep ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per block
}

/* ---------------------------------------------------------------------------
 * Styl na stronie: zmienne CSS inline (tylko ustawione), reguły w <head> tylko gdy coś jest ustawione
 * ------------------------------------------------------------------------- */

/** "--cft-fz:…;--cft-fz-m:…" dla celu; '' gdy nic nie ustawiono (HTML jak bez modułu). */
function colorify_tresci_styl_vars( $cel ) {
	$st = colorify_tresci_stored();
	$s  = $st['__style'][ $cel ] ?? null;
	if ( ! is_array( $s ) ) {
		return '';
	}
	$v = array();
	foreach ( array( 'b' => '', 'm' => '-m' ) as $d => $suf ) {
		foreach ( (array) ( $s[ $d ] ?? array() ) as $p => $tok ) {
			$css = colorify_tresci_styl_css( $p, $tok );
			if ( '' !== $css ) {
				$v[] = '--cft-' . $p . $suf . ':' . $css;
			}
		}
	}
	$b = (array) ( $s['b'] ?? array() );
	$m = (array) ( $s['m'] ?? array() );
	if ( 'justify' === ( $b['ta'] ?? '' ) && ! isset( $m['ta'] ) ) {
		$v[] = '--cft-ta-m:left'; // justowanie na wąskim ekranie robi dziury między słowami
	}
	if ( ( isset( $b['mw'] ) || isset( $m['mw'] ) ) && 'center' === ( $b['ta'] ?? '' ) ) {
		$v[] = '--cft-mi:auto'; // wąski blok wyśrodkowanego tekstu stoi na środku
	}
	if ( isset( $m['mw'] ) && 'center' === ( $m['ta'] ?? '' ) ) {
		$v[] = '--cft-mi-m:auto';
	}
	return implode( ';', $v );
}

function colorify_tresci_styl_attr( $cel ) {
	$v = colorify_tresci_styl_vars( $cel );
	return '' === $v ? '' : ' style="' . esc_attr( $v ) . '"';
}

/** Reguły: właściwość działa tylko na elemencie, który ma swoją zmienną (selektor po treści atrybutu style). */
function colorify_tresci_css_regul() {
	$p      = colorify_tresci_styl_wlasciwosci();
	$p['mi'] = array( '', 'margin-inline', 0 );
	$base   = '';
	$mob    = '';
	foreach ( $p as $k => $d ) {
		$base .= '[style*="--cft-' . $k . ':"]{' . $d[1] . ':var(--cft-' . $k . ')!important}';
		$mob  .= '[style*="--cft-' . $k . '-m:"]{' . $d[1] . ':var(--cft-' . $k . '-m)!important}';
	}
	return $base . '@media (max-width:767px){' . $mob . '}';
}

/** Arkusz nowych sekcji: odstęp zawsze, reszta tylko gdy motyw nie podał własnych klas. */
function colorify_tresci_css_sekcji() {
	$css = '.cft-odstep{height:var(--cft-h,2.5rem)}.cft-odstep--sm{--cft-h:1.5rem}.cft-odstep--md{--cft-h:3rem}.cft-odstep--lg{--cft-h:5rem}.cft-odstep--xl{--cft-h:8rem}@media (max-width:767px){.cft-odstep--bez-tel{display:none}}';
	if ( empty( colorify_tresci_rejestr()['klasy'] ) ) {
		$css .= '.cft-sek{padding:clamp(3rem,6vw,5rem) 0}.cft-sek--krem{background:rgba(0,0,0,.04)}.cft-sek--ciemne{background:#222;color:#fff}.cft-sek__in{width:min(100% - 2rem,72rem);margin:0 auto}'
			. '.cft-sek__wiersz{display:grid;gap:2rem;align-items:center}@media (min-width:992px){.cft-sek__wiersz{grid-template-columns:1fr 1fr}.cft-sek__kol--pierwsza{order:-1}}'
			. '.cft-sek__kol--srodek{max-width:42rem;margin:0 auto;text-align:center}.cft-sek__img{display:block;width:100%;aspect-ratio:4/5;object-fit:cover}'
			. '.cft-sek__btn{display:inline-block;margin-top:1rem;padding:.9rem 1.6rem;border:1px solid currentColor;text-decoration:none}.cft-sek__cytat{max-width:42rem;margin:0 auto;font-size:1.5rem;text-align:center}.cft-sek__autor{text-align:center}';
	}
	return $css;
}

/** <style> w <head>: tylko gdy są style albo nowe sekcje (bez zapisu HTML strony jest jak dawniej); w podglądzie zawsze. */
function colorify_tresci_css_head() {
	$st    = colorify_tresci_stored();
	$style = ! empty( $st['__style'] );
	$nowe  = ! empty( $st['__sekcje'] );
	if ( ! $style && ! $nowe && ! colorify_tresci_podglad() ) {
		return;
	}
	echo '<style id="cft-styl">' . colorify_tresci_css_regul() . ( $nowe || colorify_tresci_podglad() ? colorify_tresci_css_sekcji() : '' ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- built from the allowlist above
}

/* ---------------------------------------------------------------------------
 * Render strony
 * ------------------------------------------------------------------------- */

/**
 * Dopisuje do pierwszego znacznika <section>/<div> sekcji szablonu zmienne stylu i (w podglądzie) data-cft-sekcja.
 * Bez stylu i poza podglądem HTML sekcji wychodzi bez zmian.
 */
function colorify_tresci_dopisz_do_sekcji( $html, $vars, $id ) {
	if ( ! preg_match( '/<(section|div)( [^>]*)?>/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}
	$tag   = $m[0][0];
	$attrs = isset( $m[2] ) ? $m[2][0] : '';
	$extra = colorify_tresci_podglad_attr( $id, false, 'data-cft-sekcja' );
	if ( '' !== $vars ) {
		if ( false !== strpos( $attrs, ' style="' ) ) {
			$attrs = preg_replace( '/ style="/', ' style="' . esc_attr( $vars ) . ';', $attrs, 1 );
		} else {
			$attrs .= ' style="' . esc_attr( $vars ) . '"';
		}
	}
	$nowy = '<' . $m[1][0] . $attrs . $extra . '>';
	return substr_replace( $html, $nowy, $m[0][1], strlen( $tag ) );
}

/**
 * Drukuje stronę w kolejności z panelu: sekcje szablonu (callback z rejestru motywu) i nowe sekcje, bez ukrytych.
 * Motyw woła to zamiast wypisywać sekcje po kolei; bez zapisanego układu kolejność i HTML są jak w szablonie.
 */
function colorify_render_page( $page ) {
	$def  = (array) ( colorify_tresci_uklad_def()[ $page ] ?? array() );
	$u    = colorify_tresci_uklad_strony( $page );
	$nowe = colorify_tresci_sekcje_nowe();
	foreach ( $u['o'] as $id ) {
		if ( in_array( $id, $u['h'], true ) ) {
			continue;
		}
		if ( isset( $def[ $id ] ) ) {
			if ( ! is_callable( $def[ $id ]['render'] ) ) {
				continue;
			}
			$vars = colorify_tresci_styl_vars( '#' . $id );
			if ( '' === $vars && ! colorify_tresci_podglad() ) {
				call_user_func( $def[ $id ]['render'] );
				continue;
			}
			ob_start();
			call_user_func( $def[ $id ]['render'] );
			echo colorify_tresci_dopisz_do_sekcji( (string) ob_get_clean(), $vars, $id ); // phpcs:ignore WordPress.Security.EscapeOutput -- theme template output
		} elseif ( isset( $nowe[ $id ] ) ) {
			colorify_tresci_render_sekcja( $id, $nowe[ $id ] );
		}
	}
}

/** Pole tekstowe nowej sekcji jako element (nic, gdy puste): styl celu "<id>.<pole>", klucz podglądu ten sam. */
function colorify_tresci_sek_el( $id, $pole, $tag, $class, $html, $attrs = '' ) {
	if ( '' === trim( (string) $html ) ) {
		return '';
	}
	$cel = $id . '.' . $pole;
	return '<' . $tag . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . $attrs . colorify_tresci_styl_attr( $cel ) . colorify_tresci_podglad_attr( $cel, false ) . '>' . $html . '</' . $tag . '>';
}

/** Jedna nowa sekcja (typ z biblioteki). Motyw może podmienić renderer typu: rejestr 'typy' => typ => array( 'render' => callable( $id, $v ) ). */
function colorify_tresci_render_sekcja( $id, $s ) {
	$typ  = (string) ( $s['typ'] ?? '' );
	$typy = colorify_tresci_typy_sekcji();
	if ( ! isset( $typy[ $typ ] ) ) {
		return;
	}
	$v = (array) ( $s['v'] ?? array() );
	foreach ( $typy[ $typ ]['pola'] as $p => $d ) {
		if ( ! array_key_exists( $p, $v ) ) {
			$v[ $p ] = $d[2];
		}
	}
	$wlasny = colorify_tresci_rejestr()['typy'][ $typ ]['render'] ?? null;
	if ( is_callable( $wlasny ) ) {
		call_user_func( $wlasny, $id, $v );
		return;
	}
	$k      = colorify_tresci_klasy();
	$pv     = colorify_tresci_podglad_attr( $id, false, 'data-cft-sekcja' );
	$styl   = colorify_tresci_styl_attr( '#' . $id );
	if ( 'odstep' === $typ ) {
		echo '<div class="cft-odstep cft-odstep--' . esc_attr( $v['wysokosc'] ) . ( $v['telefon'] ? ' cft-odstep--bez-tel' : '' ) . '" aria-hidden="true"' . $styl . $pv . '></div>' . "\n";
		return;
	}
	$ciemne = 'ciemne' === ( $v['tlo'] ?? '' );
	$klasa  = trim( $k['sekcja'] . ' ' . ( $k[ 'tlo_' . ( $v['tlo'] ?? 'jasne' ) ] ?? '' ) . ' cft-nowa cft-nowa--' . $typ );
	$e      = function ( $p ) use ( $v ) {
		return esc_html( (string) ( $v[ $p ] ?? '' ) );
	};
	$area   = function ( $p ) use ( $v ) {
		return wp_kses( (string) ( $v[ $p ] ?? '' ), colorify_tresci_kses() );
	};
	$btn    = '' !== trim( (string) ( $v['przycisk'] ?? '' ) ) ? '<a class="' . esc_attr( $ciemne ? $k['przycisk_ciemne'] : $k['przycisk'] ) . '" href="' . esc_url( '' !== (string) ( $v['link'] ?? '' ) ? $v['link'] : home_url( '/' ) ) . '"' . colorify_tresci_styl_attr( $id . '.przycisk' ) . colorify_tresci_podglad_attr( $id . '.przycisk', false ) . '>' . $e( 'przycisk' ) . '</a>' : '';
	$naglowki = function ( $hcls ) use ( $id, $k, $e ) {
		return (string) $k['ozdobnik'] . colorify_tresci_sek_el( $id, 'nadtytul', 'p', $k['nadtytul'], $e( 'nadtytul' ) ) . colorify_tresci_sek_el( $id, 'naglowek', 'h2', $hcls, $e( 'naglowek' ) );
	};
	$f_akap = array( 'warianty' => $k['warianty'] );
	echo '<section class="' . esc_attr( $klasa ) . '" id="' . esc_attr( $id ) . '"' . $styl . $pv . '><div class="' . esc_attr( $k['kontener'] ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
	switch ( $typ ) {
		case 'tekst':
			echo '<div class="' . esc_attr( $k['wiersz'] ) . '"><div class="' . esc_attr( $k[ 'kol_' . $v['uklad'] ] ?? $k['kol_srodek'] ) . '">'
				. $naglowki( $k['naglowek'] ) . colorify_tresci_akapity_html( $id . '.akapity', $v['akapity'], $f_akap ) . $btn . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			break;
		case 'tekst_zdjecie':
			$img = '';
			if ( (int) $v['zdjecie'] > 0 ) {
				$typ_d = $typy['tekst_zdjecie']['pola']['zdjecie'][3]['kadr'];
				$css   = colorify_kadr_css( $v['zdjecie__kadr'] ?? null, array( 'x' => $typ_d['xy'][0], 'y' => $typ_d['xy'][1], 'z' => 1 ) );
				$atr   = array( 'class' => $k['zdjecie'], 'alt' => (string) $v['alt'], 'loading' => 'lazy' );
				if ( '' !== $css ) {
					$atr['style'] = $css;
				}
				if ( colorify_tresci_podglad() ) {
					$atr['data-cft-key'] = $id . '.zdjecie';
				}
				$img = wp_get_attachment_image( (int) $v['zdjecie'], 'large', false, $atr );
			}
			$lewo = 'lewo' === $v['zdjecie_po']; // nie 'strona': to nazwa strony sekcji (home, omnie) w danych i w formularzu
			$tekst = $naglowki( $k['naglowek_tz'] ) . colorify_tresci_akapity_html( $id . '.akapity', $v['akapity'], $f_akap ) . $btn;
			if ( '' === $img ) {
				echo '<div class="' . esc_attr( $k['wiersz'] ) . '"><div class="' . esc_attr( $k['kol_srodek'] ) . '">' . $tekst . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			}
			$kt = '<div class="' . esc_attr( $lewo ? $k['kol_tekst_l'] : $k['kol_tekst'] ) . '">' . $tekst . '</div>';
			$kz = '<div class="' . esc_attr( $lewo ? $k['kol_zdjecie_l'] : $k['kol_zdjecie'] ) . '">' . $img . '</div>';
			echo '<div class="' . esc_attr( $k['wiersz'] ) . '">' . ( $lewo ? $kz . $kt : $kt . $kz ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- DOM order = reading order; columns swap on wide screens via the theme classes
			break;
		case 'cytat':
			echo '<div class="' . esc_attr( $k['wiersz'] ) . '"><div class="' . esc_attr( $k['kol_srodek'] ) . '">' . (string) $k['ozdobnik'] . '<figure class="cft-nowa__cytat">'
				. colorify_tresci_sek_el( $id, 'cytat', 'blockquote', $k['cytat'], $area( 'cytat' ) )
				. colorify_tresci_sek_el( $id, 'autor', 'figcaption', $k['autor'], $e( 'autor' ) ) . '</figure></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			break;
		case 'cta':
			echo '<div class="' . esc_attr( $k['wiersz'] ) . '"><div class="' . esc_attr( $k['kol_srodek'] ) . '">' . (string) $k['ozdobnik']
				. colorify_tresci_sek_el( $id, 'naglowek', 'h2', $k['naglowek'], $e( 'naglowek' ) )
				. colorify_tresci_sek_el( $id, 'tekst', 'p', $k['cta_tekst'], $area( 'tekst' ) ) . $btn . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			break;
	}
	echo "</div></section>\n";
}

/** Hooki strony publicznej i podglądu (wołane z colorify-tresci.php, gdy moduł jest dostępny). */
function colorify_tresci_uklad_hooki() {
	add_action( 'wp_head', 'colorify_tresci_css_head', 20 );
}
