<?php
/**
 * Dane: typy tresci, kategorie, pola, model ceny, opcje, odczyt uslug, dane startowe, aktualizacja danych.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

/**
 * Opcje wtyczki z wartosciami domyslnymi.
 *
 * @return array{ceny:int,email:string,telefon:string}
 */
function kw_uslugi_opcje() {
	$domyslne = array(
		'ceny'    => 1,
		'email'   => 'karol.wieczorek1995@gmail.com',
		'telefon' => '+48 570 485 963',
		'retencja' => 12, // Po ilu miesiacach znika wiadomosc z formularza (0 = nie usuwaj).
	);
	$opcje    = get_option( 'kw_uslugi_opcje', array() );
	$opcje    = wp_parse_args( is_array( $opcje ) ? $opcje : array(), $domyslne );
	if ( ! is_email( $opcje['email'] ) ) {
		$opcje['email'] = $domyslne['email'];
	}
	if ( '' === trim( (string) $opcje['telefon'] ) ) {
		$opcje['telefon'] = $domyslne['telefon'];
	}
	$opcje['ceny']     = (int) (bool) $opcje['ceny'];
	$opcje['retencja'] = max( 0, min( 120, (int) $opcje['retencja'] ) );
	return $opcje;
}

/* ---------------------------------------------------------------------------
 * Slowniki
 * ------------------------------------------------------------------------ */

/**
 * Miejsca starszych shortcodow [kw_uslugi] i [kw_uslugi_lista] (wersja 1.0.0). Cennik ma wlasna flage kw_w_cenniku.
 *
 * @return array<string,string>
 */
function kw_uslugi_miejsca() {
	return array(
		'karta' => 'Karta w siatce i lista',
		'lista' => 'Tylko lista',
		'nie'   => 'Nigdzie',
	);
}

/**
 * Typy ceny: klucz => opis w panelu.
 *
 * @return array<string,string>
 */
function kw_uslugi_typy_cen() {
	return array(
		'stala'        => 'Stała kwota, np. 50 zł',
		'od'           => 'Cena „od”, np. od 500 zł',
		'przedzial'    => 'Przedział, np. 30-60 zł',
		'indywidualna' => 'Wycena indywidualna (bez kwoty)',
		'opis'         => 'Opis tekstowy (własne zdanie zamiast kwoty)',
	);
}

/**
 * Jednostki ceny: klucz => array( nazwa w panelu, dopisek na stronie ). Inna wartosc pola = jednostka wlasna.
 *
 * @return array<string,array{0:string,1:string}>
 */
function kw_uslugi_jednostki() {
	return array(
		'miesiac'           => array( 'miesiąc', 'mies.' ),
		'pracownik-miesiac' => array( 'pracownik / miesiąc', 'pracownik / mies.' ),
		'dokument'          => array( 'dokument', 'dokument' ),
		'deklaracja'        => array( 'deklaracja', 'deklaracja' ),
		'zestawienie'       => array( 'zestawienie', 'zestawienie' ),
		'raport'            => array( 'raport', 'raport' ),
		'rok'               => array( 'usługa roczna', 'usługa roczna' ),
	);
}

/**
 * Pola wyceny w formularzu: nazwa pola => array( etykieta, dozwolone wartosci ). Jedno zrodlo dla formularza,
 * walidacji po stronie serwera (biala lista), e-maila i kopii w panelu.
 *
 * @return array<string,array{0:string,1:array<string,string>}>
 */
function kw_uslugi_pola_wyceny() {
	return array(
		'dokumenty'  => array(
			'Liczba dokumentów w miesiącu',
			array(
				'do-10'     => 'do 10',
				'11-20'     => '11-20',
				'21-40'     => '21-40',
				'41-80'     => '41-80',
				'81-140'    => '81-140',
				'ponad-140' => 'powyżej 140',
				'nie-wiem'  => 'nie wiem',
			),
		),
		'pracownicy' => array(
			'Liczba pracowników',
			array(
				'brak'     => '0',
				'1-5'      => '1-5',
				'6-20'     => '6-20',
				'ponad-20' => 'powyżej 20',
			),
		),
	);
}

/**
 * Zestaw ikon: klasa Font Awesome => array( nazwa, szerokosc, wysokosc, sciezka ).
 *
 * @return array<string,array>
 */
function kw_uslugi_ikony() {
	static $ikony = null;
	if ( null === $ikony ) {
		$ikony = require KW_USLUGI_KATALOG . 'includes/ikony.php';
	}
	return $ikony;
}

/* ---------------------------------------------------------------------------
 * Sanityzacja pol
 * ------------------------------------------------------------------------ */

/**
 * Sanityzacja pola ikony: tylko klasa Font Awesome.
 *
 * @param mixed $wartosc Wartosc z formularza albo z REST.
 * @return string
 */
function kw_uslugi_czysc_ikone( $wartosc ) {
	$wartosc = is_string( $wartosc ) ? trim( $wartosc ) : '';
	return preg_match( '/^fa[srb]? fa-[a-z0-9-]{1,40}$/', $wartosc ) ? $wartosc : 'far fa-file-alt';
}

/**
 * Sanityzacja pola "gdzie pokazywac" starszych shortcodow.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_miejsce( $wartosc ) {
	return is_string( $wartosc ) && isset( kw_uslugi_miejsca()[ $wartosc ] ) ? $wartosc : 'karta';
}

/**
 * Sanityzacja przelacznika: '1' albo '0'.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_flage( $wartosc ) {
	return in_array( $wartosc, array( '0', 0, false, '', 'nie', 'false' ), true ) ? '0' : '1';
}

/**
 * Krotki tekst w jednym wierszu, bez znacznikow.
 *
 * @param mixed $wartosc Wartosc.
 * @param int   $dlugosc Maksymalna liczba znakow.
 * @return string
 */
function kw_uslugi_czysc_tekst_pola( $wartosc, $dlugosc = 200 ) {
	return mb_substr( sanitize_text_field( is_scalar( $wartosc ) ? (string) $wartosc : '' ), 0, $dlugosc );
}

/**
 * Sanityzacja starego pola ceny (wersja 1.0.0, krotki tekst). Zostaje tylko jako zrodlo migracji.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_cene( $wartosc ) {
	return kw_uslugi_czysc_tekst_pola( $wartosc, 40 );
}

/**
 * Sanityzacja typu ceny.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_typ_ceny( $wartosc ) {
	return is_string( $wartosc ) && isset( kw_uslugi_typy_cen()[ $wartosc ] ) ? $wartosc : 'indywidualna';
}

/**
 * Sanityzacja kwoty: "1 400,50 zł" => "1400.5". Pusta albo niepoprawna => ''.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_kwote( $wartosc ) {
	$wartosc = is_scalar( $wartosc ) ? (string) $wartosc : '';
	$wartosc = str_replace( ',', '.', (string) preg_replace( '/[\s\x{00A0}]|zł|zl|pln/iu', '', $wartosc ) );
	if ( ! preg_match( '/^\d{1,7}(\.\d{1,2})?$/', $wartosc ) ) {
		return '';
	}
	return rtrim( rtrim( number_format( (float) $wartosc, 2, '.', '' ), '0' ), '.' );
}

/**
 * Sanityzacja jednostki: klucz z listy albo wlasny krotki tekst.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_jednostke( $wartosc ) {
	return kw_uslugi_czysc_tekst_pola( $wartosc, 40 );
}

/**
 * Sanityzacja opisu ceny i etykiety wyroznienia.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_opis_ceny( $wartosc ) {
	return kw_uslugi_czysc_tekst_pola( $wartosc, 200 );
}

/**
 * Sanityzacja etykiety wyroznienia.
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_wyroznienie( $wartosc ) {
	return kw_uslugi_czysc_tekst_pola( $wartosc, 40 );
}

/**
 * Sanityzacja naglowka zakladki kategorii (jeden wiersz, do 120 znakow).
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_naglowek( $wartosc ) {
	return kw_uslugi_czysc_tekst_pola( $wartosc, 120 );
}

/**
 * Sanityzacja listy: jedna pozycja w wierszu, najwyzej 20 pozycji po 200 znakow, bez znacznikow.
 *
 * @param mixed $wartosc Tekst z wierszami albo tablica pozycji.
 * @return string Pozycje rozdzielone znakiem nowego wiersza.
 */
function kw_uslugi_czysc_liste( $wartosc ) {
	if ( is_array( $wartosc ) ) {
		$wartosc = implode( "\n", array_filter( $wartosc, 'is_scalar' ) );
	}
	$wiersze = array();
	foreach ( (array) preg_split( '/\R/u', is_scalar( $wartosc ) ? (string) $wartosc : '' ) as $wiersz ) {
		$wiersz = kw_uslugi_czysc_tekst_pola( $wiersz, 200 );
		if ( '' !== $wiersz ) {
			$wiersze[] = $wiersz;
		}
	}
	return implode( "\n", array_slice( $wiersze, 0, 20 ) );
}

/**
 * Sanityzacja progow: tablica wierszy array( etykieta, cena, od ). Wiersz bez etykiety albo bez ceny odpada.
 *
 * @param mixed $wartosc Tablica wierszy albo ten sam zapis w JSON.
 * @return array<int,array{etykieta:string,cena:string,od:int}>
 */
function kw_uslugi_czysc_progi( $wartosc ) {
	if ( is_string( $wartosc ) ) {
		$wartosc = json_decode( $wartosc, true );
	}
	$progi = array();
	foreach ( is_array( $wartosc ) ? $wartosc : array() as $prog ) {
		if ( ! is_array( $prog ) ) {
			continue;
		}
		$etykieta = kw_uslugi_czysc_tekst_pola( $prog['etykieta'] ?? '', 40 );
		$cena     = kw_uslugi_czysc_kwote( $prog['cena'] ?? '' );
		if ( '' !== $etykieta && '' !== $cena ) {
			$progi[] = array(
				'etykieta' => $etykieta,
				'cena'     => $cena,
				'od'       => (int) ( '1' === kw_uslugi_czysc_flage( $prog['od'] ?? '0' ) ),
			);
		}
	}
	return array_slice( $progi, 0, 12 );
}

/**
 * Sanityzacja odnosnika karty: sciezka w witrynie ("/zakres/#x"), kotwica ("#x") albo pelny adres http(s).
 *
 * @param mixed $wartosc Wartosc.
 * @return string
 */
function kw_uslugi_czysc_link( $wartosc ) {
	$wartosc = is_scalar( $wartosc ) ? trim( (string) $wartosc ) : '';
	if ( '' === $wartosc ) {
		return '';
	}
	if ( preg_match( '~^(/(?!/)|#)[^\s<>"\']*$~', $wartosc ) ) {
		return mb_substr( $wartosc, 0, 300 );
	}
	return mb_substr( esc_url_raw( $wartosc, array( 'http', 'https' ) ), 0, 300 );
}

/**
 * Pola uslugi: klucz meta => array( sanityzacja, wartosc domyslna, opis ).
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function kw_uslugi_pola() {
	return array(
		'kw_cena_typ'       => array( 'kw_uslugi_czysc_typ_ceny', 'indywidualna', 'Typ ceny: stala, od, przedzial, indywidualna albo opis.' ),
		'kw_cena_min'       => array( 'kw_uslugi_czysc_kwote', '', 'Kwota (dla typu "od" i "przedzial": kwota najnizsza), np. 500.' ),
		'kw_cena_max'       => array( 'kw_uslugi_czysc_kwote', '', 'Kwota najwyzsza (tylko typ "przedzial"), np. 60.' ),
		'kw_cena_opis'      => array( 'kw_uslugi_czysc_opis_ceny', '', 'Tekst zamiast kwoty (tylko typ "opis").' ),
		'kw_cena_jednostka' => array( 'kw_uslugi_czysc_jednostke', '', 'Jednostka: miesiac, pracownik-miesiac, dokument, deklaracja, zestawienie, raport, rok albo wlasny tekst.' ),
		'kw_cena_netto'     => array( 'kw_uslugi_czysc_flage', '1', 'Czy kwota jest netto: 1 albo 0 (brutto).' ),
		'kw_w_cenie'        => array( 'kw_uslugi_czysc_liste', '', 'Lista "W cenie": jedna pozycja w wierszu.' ),
		'kw_dodatkowo'      => array( 'kw_uslugi_czysc_liste', '', 'Lista "Dodatkowo platne": jedna pozycja w wierszu.' ),
		'kw_nie_obejmuje'   => array( 'kw_uslugi_czysc_liste', '', 'Lista "Nie obejmuje": jedna pozycja w wierszu.' ),
		'kw_czynniki'       => array( 'kw_uslugi_czysc_liste', '', 'Lista "Od czego zalezy cena": jedna pozycja w wierszu.' ),
		'kw_wyroznienie'    => array( 'kw_uslugi_czysc_wyroznienie', '', 'Etykieta wyroznienia karty. Puste = bez wyroznienia.' ),
		'kw_w_cenniku'      => array( 'kw_uslugi_czysc_flage', '1', 'Czy pokazywac w cenniku na stronie: 1 albo 0.' ),
		'kw_w_formularzu'   => array( 'kw_uslugi_czysc_flage', '1', 'Czy pokazywac w formularzu kontaktowym: 1 albo 0.' ),
		// Pola starszych shortcodow [kw_uslugi] i [kw_uslugi_lista].
		'kw_ikona'          => array( 'kw_uslugi_czysc_ikone', 'far fa-file-alt', 'Ikona: klasa Font Awesome, np. "far fa-building".' ),
		'kw_na_stronie'     => array( 'kw_uslugi_czysc_miejsce', 'karta', 'Starsze shortcody: karta, lista albo nie.' ),
		'kw_link'           => array( 'kw_uslugi_czysc_link', '', 'Starsza siatka: odnosnik karty. Puste = karta nie jest odnosnikiem.' ),
	);
}

/* ---------------------------------------------------------------------------
 * Rejestracja
 * ------------------------------------------------------------------------ */

/**
 * Rejestracja typow tresci, kategorii i pol.
 */
function kw_uslugi_rejestruj() {
	register_taxonomy(
		'kw_grupa',
		'kw_usluga',
		array(
			'labels'             => array(
				'name'          => 'Kategorie',
				'singular_name' => 'Kategoria',
				'menu_name'     => 'Kategorie',
				'all_items'     => 'Wszystkie kategorie',
				'edit_item'     => 'Edytuj kategorię',
				'view_item'     => 'Zobacz kategorię',
				'update_item'   => 'Zapisz kategorię',
				'add_new_item'  => 'Dodaj kategorię',
				'new_item_name' => 'Nazwa nowej kategorii',
				'search_items'  => 'Szukaj kategorii',
				'not_found'     => 'Brak kategorii.',
				'back_to_items' => 'Wróć do kategorii',
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false,
			'show_admin_column'  => true,
			'show_in_rest'       => true,
			'hierarchical'       => true,
			'rewrite'            => false,
			'meta_box_cb'        => false, // Kategorie wybiera sie w kreatorze uslugi.
		)
	);
	register_term_meta(
		'kw_grupa',
		'kw_kolejnosc',
		array(
			'type'              => 'integer',
			'single'            => true,
			'description'       => 'Kolejnosc zakladki w cenniku: mniejsza liczba = wczesniej.',
			'show_in_rest'      => true,
			'sanitize_callback' => 'absint',
			'auth_callback'     => static function () {
				return current_user_can( 'manage_categories' );
			},
		)
	);
	// Kategoria w archiwum: zostaje w bazie i przy uslugach, ale nie ma jej na liscie wyboru w kreatorze uslugi.
	// Na strone nie wplywa: o zakladkach cennika decyduja uslugi.
	register_term_meta(
		'kw_grupa',
		'kw_ukryta',
		array(
			'type'              => 'string',
			'single'            => true,
			'description'       => 'Kategoria w archiwum (1): nie pokazuje sie przy wyborze kategorii uslugi w panelu.',
			'show_in_rest'      => true,
			'sanitize_callback' => 'kw_uslugi_czysc_flage',
			'auth_callback'     => static function () {
				return current_user_can( 'manage_categories' );
			},
		)
	);

	// Naglowek panelu zakladki w cenniku (ukryty naglowek dla czytnikow ekranu i wyszukiwarek). Etykieta zakladki to nadal nazwa kategorii.
	register_term_meta(
		'kw_grupa',
		'kw_naglowek',
		array(
			'type'              => 'string',
			'single'            => true,
			'description'       => 'Naglowek zakladki dla wyszukiwarek, np. "Księgowość spółek z o.o.". Puste = nazwa kategorii.',
			'show_in_rest'      => true,
			'sanitize_callback' => 'kw_uslugi_czysc_naglowek',
			'auth_callback'     => static function () {
				return current_user_can( 'manage_categories' );
			},
		)
	);

	register_post_type(
		'kw_usluga',
		array(
			'labels'              => array(
				'name'               => 'Usługi',
				'singular_name'      => 'Usługa',
				'menu_name'          => 'Usługi',
				'all_items'          => 'Wszystkie usługi',
				'add_new'            => 'Dodaj usługę',
				'add_new_item'       => 'Dodaj usługę',
				'edit_item'          => 'Edytuj usługę',
				'new_item'           => 'Nowa usługa',
				'view_item'          => 'Zobacz usługę',
				'search_items'       => 'Szukaj usług',
				'not_found'          => 'Brak usług.',
				'not_found_in_trash' => 'Brak usług w koszu.',
			),
			'description'         => 'Usługi biura z cenami: pokazują się w cenniku na stronie i w formularzu kontaktowym.',
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => true,
			'menu_position'       => 21,
			'menu_icon'           => 'dashicons-portfolio',
			'has_archive'         => false,
			'rewrite'             => false,
			'hierarchical'        => false,
			// Uprawnienia jak dla stron: cennik zmienia redaktor albo administrator, nie autor wpisow.
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'editor', 'excerpt', 'page-attributes', 'custom-fields' ),
			'taxonomies'          => array( 'kw_grupa' ),
		)
	);

	// Kopie wiadomosci z formularza: tylko panel, tylko administrator, nic publicznie.
	$tylko_admin = array(
		'edit_post'              => 'manage_options',
		'read_post'              => 'manage_options',
		'delete_post'            => 'manage_options',
		'edit_posts'             => 'manage_options',
		'edit_others_posts'      => 'manage_options',
		'edit_private_posts'     => 'manage_options',
		'edit_published_posts'   => 'manage_options',
		'delete_posts'           => 'manage_options',
		'delete_others_posts'    => 'manage_options',
		'delete_private_posts'   => 'manage_options',
		'delete_published_posts' => 'manage_options',
		'publish_posts'          => 'manage_options',
		'read_private_posts'     => 'manage_options',
		'create_posts'           => 'do_not_allow',
	);
	register_post_type(
		'kw_wiadomosc',
		array(
			'labels'              => array(
				'name'               => 'Wiadomości z formularza',
				'singular_name'      => 'Wiadomość z formularza',
				'menu_name'          => 'Wiadomości z formularza',
				'all_items'          => 'Wiadomości z formularza',
				'edit_item'          => 'Wiadomość z formularza',
				'search_items'       => 'Szukaj wiadomości',
				'not_found'          => 'Brak wiadomości.',
				'not_found_in_trash' => 'Brak wiadomości w koszu.',
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=kw_usluga',
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'can_export'          => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => false,
			'capabilities'        => $tylko_admin,
			'map_meta_cap'        => false,
		)
	);

	// Pole wolno zmienic temu, kto moze edytowac TE usluge (nie: jakikolwiek wpis).
	$wolno = static function ( $dozwolone, $klucz, $post_id, $uzytkownik ) {
		return user_can( (int) $uzytkownik, 'edit_post', (int) $post_id );
	};
	foreach ( kw_uslugi_pola() as $klucz => $pole ) {
		register_post_meta(
			'kw_usluga',
			$klucz,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => $pole[1],
				'description'       => $pole[2],
				'show_in_rest'      => true,
				'sanitize_callback' => $pole[0],
				'auth_callback'     => $wolno,
			)
		);
	}
	register_post_meta(
		'kw_usluga',
		'kw_progi',
		array(
			'type'              => 'array',
			'single'            => true,
			'default'           => array(),
			'description'       => 'Progi cenowe: wiersze z etykieta progu, cena i znacznikiem "od".',
			'show_in_rest'      => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'etykieta' => array( 'type' => 'string' ),
							'cena'     => array( 'type' => 'string' ),
							'od'       => array( 'type' => 'integer' ),
						),
					),
				),
			),
			'sanitize_callback' => 'kw_uslugi_czysc_progi',
			'auth_callback'     => $wolno,
		)
	);
}
add_action( 'init', 'kw_uslugi_rejestruj' );

/**
 * Edytor klasyczny dla uslug: kreator z polami zamiast blokow.
 *
 * @param bool   $uzyj Czy edytor blokowy.
 * @param string $typ  Typ tresci.
 * @return bool
 */
function kw_uslugi_bez_blokow( $uzyj, $typ ) {
	return 'kw_usluga' === $typ ? false : $uzyj;
}
add_filter( 'use_block_editor_for_post_type', 'kw_uslugi_bez_blokow', 10, 2 );

/**
 * REST uslug (/wp/v2/kw_usluga) i ich kategorii (/wp/v2/kw_grupa) tylko dla osob, ktore moga je edytowac (panel, most MCP z haslem aplikacji).
 * Gosc czyta uslugi wylacznie ze strony: przez REST dostalby tez uslugi schowane ("Pokaż w cenniku" odznaczone)
 * i ceny mimo wylaczonej opcji "Pokazuj ceny".
 *
 * @param mixed           $wynik   Wynik wczesniejszych filtrow.
 * @param WP_REST_Server  $serwer  Serwer REST.
 * @param WP_REST_Request $zadanie Zadanie.
 * @return mixed
 */
function kw_uslugi_rest_tylko_redakcja( $wynik, $serwer, $zadanie ) {
	// Rdzen dopasowuje trasy bez rozrozniania wielkosci liter, wiec porownanie tez (inaczej /wp/v2/KW_USLUGA omijalo blokade).
	// Kategorie uslug (takze archiwalne) rowniez tylko dla redakcji.
	if ( preg_match( '~^/wp/v2/(kw_usluga|kw_grupa)(/|$)~i', (string) $zadanie->get_route() ) && ! current_user_can( 'edit_pages' ) ) {
		return new WP_Error( 'kw_rest_zabronione', 'Lista usług jest dostępna na stronie, w cenniku.', array( 'status' => rest_authorization_required_code() ) );
	}
	return $wynik;
}
add_filter( 'rest_pre_dispatch', 'kw_uslugi_rest_tylko_redakcja', 10, 3 );

/**
 * Kategorii nie da sie przywrocic z kosza, wiec nie da sie jej usunac na stale ani z panelu (akcja wiersza, zbiorcza,
 * ekran edycji), ani przez REST (takze most MCP). Zamiast tego jest archiwum (kw_ukryta). Funkcja stoi tutaj, a nie w plikach
 * panelu, bo te laduja sie tylko przy is_admin().
 *
 * @param string[] $uprawnienia Wymagane uprawnienia.
 * @param string   $cap         Sprawdzane uprawnienie.
 * @param int      $uzytkownik  ID uzytkownika.
 * @param array    $argumenty   Argumenty (ID kategorii).
 * @return string[]
 */
function kw_uslugi_kat_bez_usuwania( $uprawnienia, $cap, $uzytkownik, $argumenty ) {
	if ( 'delete_term' === $cap && isset( $argumenty[0] ) ) {
		$termin = get_term( (int) $argumenty[0] );
		if ( $termin instanceof WP_Term && 'kw_grupa' === $termin->taxonomy ) {
			return array( 'do_not_allow' );
		}
	}
	return $uprawnienia;
}
add_filter( 'map_meta_cap', 'kw_uslugi_kat_bez_usuwania', 10, 4 );

/* ---------------------------------------------------------------------------
 * Model ceny
 * ------------------------------------------------------------------------ */

/**
 * Kwota po polsku: "1400", "12 500" (twarda spacja od pieciu cyfr), "49,50".
 *
 * @param string $kwota Kwota po kw_uslugi_czysc_kwote().
 * @return string
 */
function kw_uslugi_kwota( $kwota ) {
	$liczba   = (float) $kwota;
	$grosze   = abs( $liczba - round( $liczba ) ) > 0.004;
	$tysiace  = $liczba >= 10000 ? "\u{00A0}" : '';
	return number_format( $liczba, $grosze ? 2 : 0, ',', $tysiace );
}

/**
 * Dopisek jednostki na stronie ("mies.", "dokument" albo tekst wlasny).
 *
 * @param string $jednostka Wartosc pola kw_cena_jednostka.
 * @return string
 */
function kw_uslugi_jednostka( $jednostka ) {
	$lista = kw_uslugi_jednostki();
	return isset( $lista[ $jednostka ] ) ? $lista[ $jednostka ][1] : $jednostka;
}

/**
 * Cena uslugi rozlozona na czesci do pokazania.
 *
 * @param array $u Usluga (wynik kw_uslugi_z_posta).
 * @return array{przed:string,kwota:string,po:string,rodzaj:string,tekst:string} rodzaj: kwota, tekst (wycena indywidualna) albo opis.
 */
function kw_uslugi_cena( array $u ) {
	$nb   = "\u{00A0}";
	$typ  = $u['cena_typ'];
	$min  = $u['cena_min'];
	$zloz = static function ( $przed, $kwota, $po, $rodzaj ) {
		return array(
			'przed'  => $przed,
			'kwota'  => $kwota,
			'po'     => $po,
			'rodzaj' => $rodzaj,
			'tekst'  => implode( ' ', array_filter( array( $przed, $kwota, $po ), 'strlen' ) ),
		);
	};

	if ( 'opis' === $typ && '' !== $u['cena_opis'] ) {
		return $zloz( '', $u['cena_opis'], '', 'opis' );
	}
	$liczbowa = in_array( $typ, array( 'stala', 'od', 'przedzial' ), true );
	// Kwota pusta, a usluga ma progi: bierzemy najnizszy prog ("od", gdy progow jest wiecej niz jeden).
	if ( $liczbowa && '' === $min && $u['progi'] ) {
		$min = kw_uslugi_czysc_kwote( min( array_map( 'floatval', array_column( $u['progi'], 'cena' ) ) ) );
		$typ = count( $u['progi'] ) > 1 ? 'od' : 'stala';
	}
	if ( ! $liczbowa || '' === $min ) {
		return $zloz( '', 'Wycena indywidualna', '', 'tekst' );
	}

	$kwota = kw_uslugi_kwota( $min );
	if ( 'przedzial' === $typ && '' !== $u['cena_max'] && (float) $u['cena_max'] > (float) $min ) {
		$kwota .= '-' . kw_uslugi_kwota( $u['cena_max'] );
	}
	$jednostka = kw_uslugi_jednostka( $u['jednostka'] );
	return $zloz(
		'od' === $typ ? 'od' : '',
		$kwota . $nb . 'zł',
		( $u['netto'] ? 'netto' : 'brutto' ) . ( '' !== $jednostka ? ' / ' . $jednostka : '' ),
		'kwota'
	);
}

/**
 * Katalog ofert schema.org (OfferCatalog z lista Offer) z uslug pokazywanych w cenniku. Motyw przypisuje wynik wprost
 * do pola hasOfferCatalog w JSON-LD, wiec funkcja oddaje gotowy wezel, a nie gola liste. Brak uslug => pusta tablica.
 * Kwoty wylacznie z danych uslug, te same co na kartach: stala => price, "od" => minPrice, przedzial => minPrice + maxPrice,
 * kwota pusta z progami => najnizszy prog jako minPrice. Wycena indywidualna, wlasny opis ceny i wylaczona opcja
 * "Pokazuj ceny" => oferta bez ceny.
 *
 * @return array<string,mixed> array( '@type' => 'OfferCatalog', 'name' => ..., 'itemListElement' => Offer[] ) albo array().
 */
function kw_uslugi_oferty_schema() {
	$ceny   = (bool) kw_uslugi_opcje()['ceny'];
	$oferty = array();
	foreach ( kw_uslugi_pobierz() as $u ) {
		if ( empty( $u['cennik'] ) || '' === trim( (string) $u['tytul'] ) ) {
			continue;
		}
		$usluga = array(
			'@type' => 'Service',
			'name'  => wp_strip_all_tags( $u['tytul'] ),
		);
		if ( '' !== trim( (string) $u['zajawka'] ) ) {
			$usluga['description'] = wp_strip_all_tags( $u['zajawka'] );
		}
		$oferta = array(
			'@type'       => 'Offer',
			'name'        => $usluga['name'],
			'itemOffered' => $usluga,
		);
		if ( $u['grupy'] ) {
			$oferta['category'] = (string) reset( $u['grupy'] );
		}
		$typ = $u['cena_typ'];
		$min = $u['cena_min'];
		if ( $ceny && in_array( $typ, array( 'stala', 'od', 'przedzial' ), true ) ) {
			if ( '' === $min && $u['progi'] ) {
				$min = kw_uslugi_czysc_kwote( min( array_map( 'floatval', array_column( $u['progi'], 'cena' ) ) ) );
				$typ = count( $u['progi'] ) > 1 ? 'od' : 'stala';
			}
			if ( '' !== $min ) {
				$spec = array(
					'@type'                 => 'UnitPriceSpecification',
					'priceCurrency'         => 'PLN',
					'valueAddedTaxIncluded' => ! $u['netto'],
				);
				$max  = 'przedzial' === $typ && '' !== $u['cena_max'] && (float) $u['cena_max'] > (float) $min ? $u['cena_max'] : '';
				if ( 'stala' === $typ || ( 'przedzial' === $typ && '' === $max ) ) {
					$oferta['price'] = $min;
					$spec['price']   = $min;
				} else {
					$spec['minPrice'] = $min;
					if ( '' !== $max ) {
						$spec['maxPrice'] = $max;
					}
				}
				$jednostka = kw_uslugi_jednostka( $u['jednostka'] );
				if ( '' !== $jednostka ) {
					$spec['unitText'] = $jednostka;
				}
				$oferta['priceCurrency']      = 'PLN';
				$oferta['priceSpecification'] = $spec;
			}
		}
		$oferty[] = $oferta;
	}
	if ( ! $oferty ) {
		return array();
	}
	return array(
		'@type'           => 'OfferCatalog',
		'name'            => 'Cennik usług',
		'itemListElement' => $oferty,
	);
}

/**
 * Stare pole tekstowe ceny (1.0.0) => pola modelu ceny.
 * "od 270 zł netto" => od / 270; "50 zł netto" => stala / 50; "30-60 zł" => przedzial; inny tekst => opis; puste => indywidualna.
 *
 * @param mixed $tekst Wartosc starego pola kw_cena_od.
 * @return array<string,string> Klucze meta => wartosci.
 */
function kw_uslugi_cena_z_tekstu( $tekst ) {
	$tekst = trim( is_scalar( $tekst ) ? (string) $tekst : '' );
	if ( '' === $tekst ) {
		return array( 'kw_cena_typ' => 'indywidualna' );
	}
	$liczba = '(\d[\d ]*(?:[.,]\d{1,2})?)';
	if ( preg_match( '/^(od\s+)?' . $liczba . '(?:\s*-\s*' . $liczba . ')?\s*(?:zł|zl|pln)?\s*(netto|brutto)?$/iu', $tekst, $m ) ) {
		$min = kw_uslugi_czysc_kwote( $m[2] );
		$max = kw_uslugi_czysc_kwote( $m[3] ?? '' );
		if ( '' !== $min ) {
			return array(
				'kw_cena_typ'   => '' !== $max ? 'przedzial' : ( '' !== $m[1] ? 'od' : 'stala' ),
				'kw_cena_min'   => $min,
				'kw_cena_max'   => $max,
				'kw_cena_netto' => 'brutto' === strtolower( $m[4] ?? '' ) ? '0' : '1',
			);
		}
	}
	return array(
		'kw_cena_typ'  => 'opis',
		'kw_cena_opis' => kw_uslugi_czysc_opis_ceny( $tekst ),
	);
}

/* ---------------------------------------------------------------------------
 * Odczyt
 * ------------------------------------------------------------------------ */

/**
 * Kategorie w kolejnosci zakladek cennika: slug => array( nazwa, kolejnosc, id, ukryta, naglowek ).
 * Kategoria bez numeru kolejnosci stoi na koncu. "ukryta" = kategoria w archiwum (tylko panel, patrz kw_ukryta).
 *
 * @param bool $odswiez Pomin pamiec podreczna tego zadania.
 * @return array<string,array{nazwa:string,kolejnosc:int,id:int,ukryta:bool,naglowek:string}>
 */
function kw_uslugi_kategorie( $odswiez = false ) {
	static $kategorie = null;
	if ( null !== $kategorie && ! $odswiez ) {
		return $kategorie;
	}
	$kategorie = array();
	$terminy   = get_terms(
		array(
			'taxonomy'   => 'kw_grupa',
			'hide_empty' => false,
		)
	);
	foreach ( is_array( $terminy ) ? $terminy : array() as $termin ) {
		$numer                      = get_term_meta( $termin->term_id, 'kw_kolejnosc', true );
		$kategorie[ $termin->slug ] = array(
			'nazwa'     => $termin->name,
			'kolejnosc' => '' === $numer || false === $numer ? PHP_INT_MAX : (int) $numer,
			'id'        => (int) $termin->term_id,
			'ukryta'    => '1' === (string) get_term_meta( $termin->term_id, 'kw_ukryta', true ),
			'naglowek'  => kw_uslugi_czysc_naglowek( get_term_meta( $termin->term_id, 'kw_naglowek', true ) ),
		);
	}
	uasort(
		$kategorie,
		static function ( $a, $b ) {
			return array( $a['kolejnosc'], $a['id'] ) <=> array( $b['kolejnosc'], $b['id'] );
		}
	);
	return $kategorie;
}

/**
 * Usluga jako tablica gotowa dla widokow.
 *
 * @param WP_Post $post Wpis typu kw_usluga.
 * @return array<string,mixed>
 */
function kw_uslugi_z_posta( WP_Post $post ) {
	$meta  = static function ( $klucz ) use ( $post ) {
		return get_post_meta( $post->ID, $klucz, true );
	};
	$lista = static function ( $klucz ) use ( $meta ) {
		$tekst = kw_uslugi_czysc_liste( $meta( $klucz ) );
		return '' === $tekst ? array() : explode( "\n", $tekst );
	};

	// Kategorie uslugi w kolejnosci zakladek; pierwsza decyduje o tym, gdzie usluga stoi w cenniku i formularzu.
	$ma      = array();
	$terminy = get_the_terms( $post, 'kw_grupa' );
	foreach ( is_array( $terminy ) ? $terminy : array() as $termin ) {
		$ma[ $termin->slug ] = $termin->name;
	}
	// Kolejnosc z zakladek, nazwy z wpisu; kategoria spoza pamieci podrecznej trafia na koniec.
	$grupy = array_replace( array_intersect_key( kw_uslugi_kategorie(), $ma ), $ma );

	$u         = array(
		'id'           => $post->ID,
		'slug'         => $post->post_name,
		'tytul'        => $post->post_title,
		'zajawka'      => $post->post_excerpt,
		'opis'         => $post->post_content,
		'grupy'        => $grupy,
		'cena_typ'     => kw_uslugi_czysc_typ_ceny( $meta( 'kw_cena_typ' ) ),
		'cena_min'     => kw_uslugi_czysc_kwote( $meta( 'kw_cena_min' ) ),
		'cena_max'     => kw_uslugi_czysc_kwote( $meta( 'kw_cena_max' ) ),
		'cena_opis'    => kw_uslugi_czysc_opis_ceny( $meta( 'kw_cena_opis' ) ),
		'jednostka'    => kw_uslugi_czysc_jednostke( $meta( 'kw_cena_jednostka' ) ),
		'netto'        => '0' !== (string) $meta( 'kw_cena_netto' ),
		'progi'        => kw_uslugi_czysc_progi( $meta( 'kw_progi' ) ),
		'w_cenie'      => $lista( 'kw_w_cenie' ),
		'dodatkowo'    => $lista( 'kw_dodatkowo' ),
		'nie_obejmuje' => $lista( 'kw_nie_obejmuje' ),
		'czynniki'     => $lista( 'kw_czynniki' ),
		'wyroznienie'  => kw_uslugi_czysc_wyroznienie( $meta( 'kw_wyroznienie' ) ),
		'cennik'       => '0' !== (string) $meta( 'kw_w_cenniku' ),
		'formularz'    => '0' !== (string) $meta( 'kw_w_formularzu' ),
		'ikona'        => kw_uslugi_czysc_ikone( $meta( 'kw_ikona' ) ),
		'miejsce'      => kw_uslugi_czysc_miejsce( $meta( 'kw_na_stronie' ) ),
		'link'         => kw_uslugi_czysc_link( $meta( 'kw_link' ) ),
	);
	// Starsze shortcody pokazuja cene jednym tekstem; "Wycena indywidualna" tam nie trafia (karty bez ceny zostaja bez ceny).
	$cena      = kw_uslugi_cena( $u );
	$u['cena'] = 'tekst' === $cena['rodzaj'] ? '' : $cena['tekst'];
	return $u;
}

/**
 * Opublikowane uslugi w kolejnosci (pole "Kolejnosc", potem nazwa).
 *
 * @param bool $odswiez Pomin pamiec podreczna tego zadania.
 * @return array<int,array<string,mixed>>
 */
function kw_uslugi_pobierz( $odswiez = false ) {
	static $uslugi = null;
	if ( null === $uslugi || $odswiez ) {
		kw_uslugi_kategorie( $odswiez );
		$uslugi = array_map(
			'kw_uslugi_z_posta',
			get_posts(
				array(
					'post_type'      => 'kw_usluga',
					'post_status'    => 'publish',
					'posts_per_page' => 200, // ponytail: sufit 200 uslug na jedno zapytanie; przy wiekszej liczbie dodac stronicowanie.
					'orderby'        => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
				)
			)
		);
	}
	/**
	 * Lista uslug dla widokow. Samotest dopisuje tu usluge-szkic, zeby sprawdzic cennik bez publikowania czegokolwiek.
	 *
	 * @param array $uslugi Uslugi.
	 */
	return (array) apply_filters( 'kw_uslugi_lista', $uslugi );
}

/**
 * Usluga, ktorej progi cenowe wolno pokazac na stronie: opublikowana, widoczna w cenniku, z co najmniej jednym progiem,
 * przy wlaczonej opcji "Pokazuj ceny". W kazdym innym przypadku null (kwoty ukrytej uslugi nie wychodza inna droga).
 *
 * @param string $slug Slug uslugi, np. "ryczalt-bez-vat".
 * @return array<string,mixed>|null Usluga jak z kw_uslugi_z_posta().
 */
function kw_uslugi_usluga_progow( $slug ) {
	$slug = sanitize_title( is_scalar( $slug ) ? (string) $slug : '' );
	if ( '' === $slug || ! kw_uslugi_opcje()['ceny'] ) {
		return null;
	}
	foreach ( kw_uslugi_pobierz() as $u ) {
		if ( $u['slug'] === $slug ) {
			return $u['cennik'] && $u['progi'] ? $u : null;
		}
	}
	return null;
}

/**
 * Progi cenowe jednej uslugi w kolejnosci z panelu: do wykresow i zestawien na stronie, zeby liczby szly z cennika,
 * a nie z tekstu wpisanego w strone. Pusta tablica: patrz kw_uslugi_usluga_progow().
 *
 * @param string $slug Slug uslugi, np. "ryczalt-bez-vat".
 * @return array<int,array{etykieta:string,kwota:float,od:bool}> "od" = prog bez gornej granicy ("od 3500 zł").
 */
function kw_uslugi_progi( $slug ) {
	$u     = kw_uslugi_usluga_progow( $slug );
	$progi = array();
	foreach ( $u ? $u['progi'] : array() as $prog ) {
		$progi[] = array(
			'etykieta' => (string) $prog['etykieta'],
			'kwota'    => (float) $prog['cena'],
			'od'       => (bool) $prog['od'],
		);
	}
	return $progi;
}

/**
 * Lista slugow z atrybutu shortcodu ("a, b,c").
 *
 * @param string $tekst Atrybut.
 * @return string[]
 */
function kw_uslugi_slugi( $tekst ) {
	return array_values( array_filter( array_map( 'sanitize_title', explode( ',', (string) $tekst ) ) ) );
}

/**
 * Filtr uslug po kategoriach.
 *
 * @param array    $uslugi Uslugi.
 * @param string[] $tylko  Slugi kategorii do pokazania (puste = wszystkie).
 * @param string[] $bez    Slugi kategorii do pominiecia.
 * @return array
 */
function kw_uslugi_filtruj( array $uslugi, array $tylko, array $bez ) {
	return array_values(
		array_filter(
			$uslugi,
			static function ( $u ) use ( $tylko, $bez ) {
				$slugi = array_keys( $u['grupy'] );
				if ( $tylko && ! array_intersect( $slugi, $tylko ) ) {
					return false;
				}
				return ! ( $bez && array_intersect( $slugi, $bez ) );
			}
		)
	);
}

/**
 * Uslugi pogrupowane w kolejnosci zakladek cennika.
 * Usluga z kilkoma kategoriami trafia do pierwszej, bez kategorii do klucza '' (na koncu).
 *
 * @param array $uslugi Uslugi.
 * @return array<string,array{nazwa:string,uslugi:array}>
 */
function kw_uslugi_grupuj( array $uslugi ) {
	$grupy = array();
	foreach ( $uslugi as $u ) {
		$slug = (string) array_key_first( $u['grupy'] );
		if ( ! isset( $grupy[ $slug ] ) ) {
			$grupy[ $slug ] = array(
				'nazwa'  => '' === $slug ? 'Pozostałe usługi' : $u['grupy'][ $slug ],
				'uslugi' => array(),
			);
		}
		$grupy[ $slug ]['uslugi'][] = $u;
	}
	$kolejnosc = array_flip( array_keys( kw_uslugi_kategorie() ) );
	uksort(
		$grupy,
		static function ( $a, $b ) use ( $kolejnosc ) {
			$miejsce = static function ( $slug ) use ( $kolejnosc ) {
				return '' === (string) $slug ? PHP_INT_MAX : ( $kolejnosc[ $slug ] ?? PHP_INT_MAX - 1 );
			};
			return $miejsce( $a ) <=> $miejsce( $b );
		}
	);
	return $grupy;
}

/* ---------------------------------------------------------------------------
 * Dane startowe i aktualizacja danych
 * ------------------------------------------------------------------------ */

/**
 * Wgrywa dane startowe cennika (includes/dane-startowe.php): kategorie z kolejnoscia i uslugi z cenami.
 * Usluge rozpoznaje po slugu. Istniejacej nie rusza, chyba ze $nadpisz.
 *
 * @param bool $nadpisz Nadpisz tytul, zajawke, kolejnosc, kategorie i pola ceny istniejacych uslug.
 * @return array{nowe:int,zmienione:int}
 */
function kw_uslugi_wgraj_dane( $nadpisz = false ) {
	$wynik = array(
		'nowe'      => 0,
		'zmienione' => 0,
	);
	foreach ( (array) require KW_USLUGI_KATALOG . 'includes/dane-startowe.php' as $slug_kat => $kat ) {
		$termin = term_exists( $slug_kat, 'kw_grupa' );
		$termin = $termin ? $termin : wp_insert_term( $kat['nazwa'], 'kw_grupa', array( 'slug' => $slug_kat ) );
		if ( is_wp_error( $termin ) ) {
			continue;
		}
		$termin_id = (int) $termin['term_id'];
		update_term_meta( $termin_id, 'kw_kolejnosc', (int) $kat['kolejnosc'] );

		$numer = 0;
		foreach ( $kat['uslugi'] as $slug => $u ) {
			++$numer;
			$stary = get_page_by_path( $slug, OBJECT, 'kw_usluga' );
			if ( $stary && ! $nadpisz ) {
				continue;
			}
			$wpis = array(
				'post_type'    => 'kw_usluga',
				'post_title'   => $u['tytul'],
				'post_name'    => $slug,
				'post_excerpt' => $u['zajawka'],
				'menu_order'   => (int) $kat['kolejnosc'] * 10 + $numer * 10, // kategoria 10: 110, 120...; jest miejsce na wstawki
			);
			if ( $stary ) {
				$wpis['ID'] = $stary->ID;
				$id         = wp_update_post( wp_slash( $wpis ), true );
			} else {
				$wpis['post_status'] = 'publish';
				$wpis['meta_input']  = array(
					'kw_ikona'      => $u['ikona'] ?? 'far fa-file-alt',
					'kw_na_stronie' => 'lista',
				);
				$id                  = wp_insert_post( wp_slash( $wpis ), true );
			}
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			$pola = array(
				'kw_cena_typ'       => $u['typ'],
				'kw_cena_min'       => $u['min'] ?? '',
				'kw_cena_max'       => $u['max'] ?? '',
				'kw_cena_opis'      => $u['opis_ceny'] ?? '',
				'kw_cena_jednostka' => $u['jednostka'] ?? '',
				'kw_cena_netto'     => '1',
				'kw_progi'          => $u['progi'] ?? array(),
				'kw_w_cenie'        => implode( "\n", $u['w_cenie'] ?? array() ),
				'kw_dodatkowo'      => implode( "\n", $u['dodatkowo'] ?? array() ),
				'kw_nie_obejmuje'   => implode( "\n", $u['nie_obejmuje'] ?? array() ),
				'kw_czynniki'       => implode( "\n", $u['czynniki'] ?? array() ),
				'kw_w_cenniku'      => '1',
				'kw_w_formularzu'   => '1',
			);
			foreach ( $pola as $klucz => $wartosc ) {
				update_post_meta( $id, $klucz, wp_slash( $wartosc ) );
			}
			wp_set_object_terms( $id, array( $termin_id ), 'kw_grupa' );
			++$wynik[ $stary ? 'zmienione' : 'nowe' ];
		}
	}
	return $wynik;
}

/**
 * Dane startowe: raz, tylko gdy nie ma zadnej uslugi (w dowolnym statusie, takze w koszu).
 *
 * @return int Liczba utworzonych uslug.
 */
function kw_uslugi_zasiej() {
	if ( get_option( 'kw_uslugi_zasiane' ) || array_sum( (array) wp_count_posts( 'kw_usluga' ) ) > 0 ) {
		return 0;
	}
	$wynik = kw_uslugi_wgraj_dane();
	update_option( 'kw_uslugi_zasiane', gmdate( 'Y-m-d H:i:s' ), false );
	return $wynik['nowe'];
}

/**
 * Aktualizacja danych po wgraniu nowszej wersji wtyczki. Kazdy krok wykonuje sie raz.
 */
function kw_uslugi_aktualizuj() {
	if ( version_compare( (string) get_option( 'kw_uslugi_wersja', '1.0.0' ), '1.1.0', '>=' ) ) {
		return;
	}
	// 1.1.0: stare pole tekstowe kw_cena_od => model ceny (stare pole zostaje w bazie nietkniete); ceny widoczne na stronie.
	$uslugi = get_posts(
		array(
			'post_type'      => 'kw_usluga',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $uslugi as $id ) {
		if ( metadata_exists( 'post', $id, 'kw_cena_typ' ) ) {
			continue;
		}
		foreach ( kw_uslugi_cena_z_tekstu( get_post_meta( $id, 'kw_cena_od', true ) ) as $klucz => $wartosc ) {
			update_post_meta( $id, $klucz, wp_slash( $wartosc ) );
		}
	}
	if ( kw_uslugi_migracja_wlacza_ceny( get_option( 'kw_uslugi_opcje' ) ) ) {
		$opcje         = kw_uslugi_opcje();
		$opcje['ceny'] = 1;
		update_option( 'kw_uslugi_opcje', $opcje, false );
	}
	update_option( 'kw_uslugi_wersja', KW_USLUGI_WERSJA, true );
}
add_action( 'init', 'kw_uslugi_aktualizuj', 20 );

/**
 * Czy aktualizacja do 1.1.0 ma wlaczyc ceny. Tylko wtedy, gdy nikt jeszcze nie zapisal ustawienia "Pokazuj ceny":
 * gdyby opcja z numerem wersji zniknela z bazy, ponowna aktualizacja nie odsloni cen, ktore wlasciciel wylaczyl.
 *
 * @param mixed $zapisane Surowa wartosc opcji kw_uslugi_opcje (false, gdy jej nie ma).
 * @return bool
 */
function kw_uslugi_migracja_wlacza_ceny( $zapisane ) {
	return ! is_array( $zapisane ) || ! array_key_exists( 'ceny', $zapisane );
}

/**
 * Aktywacja: typy tresci, opcje domyslne, dane startowe.
 */
function kw_uslugi_aktywacja() {
	kw_uslugi_rejestruj();
	if ( false === get_option( 'kw_uslugi_opcje' ) ) {
		add_option( 'kw_uslugi_opcje', kw_uslugi_opcje(), '', false );
	}
	if ( kw_uslugi_zasiej() ) {
		update_option( 'kw_uslugi_wersja', KW_USLUGI_WERSJA, true ); // swieza instalacja: nie ma czego aktualizowac
	}
}
