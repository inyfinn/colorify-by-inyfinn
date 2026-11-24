<?php
/**
 * Moduł "Edytor produktu" (2.16.0): każdy tekst i element strony produktu WooCommerce jako karta, obok podgląd prawdziwej
 * strony z niezapisanymi zmianami. Zapis trafia do tych samych pól, z których strona czyta (WC_Product, meta produktu,
 * opcje strony): moduł nie trzyma własnej kopii treści.
 *
 * Karty WooCommerce (nazwa, zdjęcia, cena, tekst pod ceną, kategorie i tagi, opis, opinie) są wbudowane. Karty strony
 * (meta produktu, teksty wspólne z opcji) rejestruje wtyczka strony filtrem `colorify_produkt_rejestr`
 * (docs/edytor-produktu.md). Moduł jest domyślnie WYŁĄCZONY (Ustawienia, Colorify) i wymaga WooCommerce: bez tego
 * ten plik tylko definiuje funkcje i nie podpina żadnych hooków.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_PRODUKT_OPTION = 'colorify_produkt';

/** Czy moduł jest włączony w Ustawienia, Colorify (domyślnie nie). */
function colorify_produkt_aktywny(): bool {
	return 'yes' === get_option( COLORIFY_PRODUKT_OPTION, 'no' );
}

/** Moduł włączony i jest WooCommerce: dopiero wtedy jest ekran, podgląd i rewizje produktów. */
function colorify_produkt_dostepny(): bool {
	return colorify_produkt_aktywny() && function_exists( 'wc_get_product' );
}

/* ---------------------------------------------------------------------------
 * Przełącznik w Ustawienia, Colorify.
 * ------------------------------------------------------------------------- */

function colorify_produkt_save_from_input( array $input ): void {
	if ( empty( $input['colorify_produkt_present'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	update_option( COLORIFY_PRODUKT_OPTION, ! empty( $input['colorify_produkt'] ) ? 'yes' : 'no' );
}

function colorify_produkt_settings_row(): void {
	echo '<div class="colorify-site colorify-style-row">';
	echo '<div class="colorify-site__text"><p class="colorify-site__title">' . esc_html__( 'Edytor produktu z podglądem', 'colorify-by-inyfinn' ) . '</p>';
	echo '<p class="colorify-site__hint">' . esc_html__( 'Ekran "Produkty, Edytor z podglądem": każdy tekst i element strony produktu jako karta, obok prawdziwa strona produktu z niezapisanymi zmianami. Zapis trafia do tych samych pól co zwykły ekran edycji produktu. Włącza też historię zmian (rewizje) produktów.', 'colorify-by-inyfinn' ) . '</p>';
	echo '<p class="colorify-site__now">' . ( function_exists( 'wc_get_product' )
		? esc_html__( 'WooCommerce jest włączony.', 'colorify-by-inyfinn' )
		: esc_html__( 'Na tej stronie nie ma WooCommerce, więc włączenie niczego nie zmieni.', 'colorify-by-inyfinn' ) ) . '</p></div>';
	echo '<label class="colorify-site__check"><input type="hidden" name="colorify_produkt_present" value="1"><input type="checkbox" name="colorify_produkt" value="1"' . checked( colorify_produkt_aktywny(), true, false ) . '> ' . esc_html__( 'Włącz edytor produktu z podglądem', 'colorify-by-inyfinn' ) . '</label>';
	echo '</div>';
}

/* ---------------------------------------------------------------------------
 * Karty: wbudowane (WooCommerce) + rejestr strony.
 * ------------------------------------------------------------------------- */

/** Znaczniki dozwolone w polu typu 'lekki' (krótki tekst z pogrubieniem i odnośnikiem). */
function colorify_produkt_kses_lekki(): array {
	return array(
		'strong' => array(),
		'em'     => array(),
		'br'     => array(),
		'a'      => array(
			'href'  => true,
			'title' => true,
		),
	);
}

/** Rejestr strony (filtr colorify_produkt_rejestr; argumenty: rejestr, ID produktu), raz na produkt i żądanie. */
function colorify_produkt_rejestr( int $id ): array {
	static $cache = array();
	if ( ! isset( $cache[ $id ] ) ) {
		$r            = (array) apply_filters( 'colorify_produkt_rejestr', array(), $id );
		$cache[ $id ] = $r + array(
			'karty'     => array(),
			'selektory' => array(),
		);
	}
	return $cache[ $id ];
}

/**
 * Karty produktu w kolejności strony: id => karta. Karta: tytul, gdzie, kolejnosc, selektor (element w podglądzie),
 * zakladka (id zakładki WooCommerce), pola (klucz => pole), wspolne (teksty z opcji, osobny zapis), info (tekst).
 * Karta strony o id wbudowanej karty nadpisuje jej klucze (płytko).
 */
function colorify_produkt_karty( int $id ): array {
	$r   = colorify_produkt_rejestr( $id );
	$sel = (array) $r['selektory'] + array(
		'nazwa'     => '.product_title',
		'zdjecie'   => '.woocommerce-product-gallery',
		'galeria'   => '.flex-control-thumbs',
		'cena'      => '.summary .price',
		'krotki'    => '.woocommerce-product-details__short-description',
		'kategorie' => '.product_meta .posted_in',
		'tagi'      => '.product_meta .tagged_as',
		'opis'      => '#tab-description',
		'opinie'    => '#tab-reviews',
		'powiazane' => '.related.products',
	);
	$wb  = array(
		'nazwa'     => array(
			'tytul'     => 'Nazwa produktu',
			'gdzie'     => 'Duży tytuł na stronie produktu, nazwa na listach i w koszyku',
			'kolejnosc' => 10,
			'pola'      => array(
				'nazwa' => array(
					'typ'      => 'tytul',
					'etykieta' => 'Nazwa',
				),
			),
		),
		'zdjecie'   => array(
			'tytul'     => 'Zdjęcie główne',
			'gdzie'     => 'Duże zdjęcie produktu, miniatura na listach',
			'kolejnosc' => 20,
			'pola'      => array(
				'zdjecie' => array(
					'typ'      => 'obraz',
					'etykieta' => 'Zdjęcie',
				),
			),
		),
		'galeria'   => array(
			'tytul'     => 'Dodatkowe zdjęcia',
			'gdzie'     => 'Miniatury pod zdjęciem głównym',
			'kolejnosc' => 25,
			'pola'      => array(
				'galeria' => array(
					'typ'        => 'galeria',
					'etykieta'   => 'Zdjęcia w galerii',
					'podpowiedz' => 'Bez dodatkowych zdjęć strona pokazuje tylko zdjęcie główne.',
				),
			),
		),
		'cena'      => array(
			'tytul'     => 'Cena',
			'gdzie'     => 'Kwota pod nazwą produktu',
			'kolejnosc' => 30,
			'pola'      => array(
				'cena'       => array(
					'typ'      => 'kwota',
					'etykieta' => 'Cena',
				),
				'cena_promo' => array(
					'typ'        => 'kwota',
					'etykieta'   => 'Cena promocyjna',
					'podpowiedz' => 'Zostaw puste, gdy nie ma promocji. Z promocją strona pokazuje starą cenę przekreśloną.',
				),
			),
		),
		'krotki'    => array(
			'tytul'     => 'Tekst pod ceną',
			'gdzie'     => 'Krótki tekst pod ceną, nad przyciskiem',
			'kolejnosc' => 40,
			'pola'      => array(
				'krotki' => array(
					'typ'      => 'html',
					'etykieta' => 'Tekst pod ceną',
					'wiersze'  => 5,
				),
			),
		),
		'kategorie' => array(
			'tytul'     => 'Kategorie i tagi',
			'gdzie'     => 'Wiersze „Kategorie” i „Tagi” na stronie produktu, okruszki nad produktem',
			'kolejnosc' => 60,
			'pola'      => array(
				'kategorie' => array(
					'typ'         => 'termy',
					'etykieta'    => 'Kategorie',
					'taksonomia'  => 'product_cat',
					'podpowiedz'  => 'Kategoria decyduje, na których listach sklepu produkt się pokazuje.',
				),
				'tagi'      => array(
					'typ'        => 'termy',
					'etykieta'   => 'Tagi',
					'taksonomia' => 'product_tag',
				),
				'tagi_nowe' => array(
					'typ'        => 'tekst',
					'etykieta'   => 'Dodaj nowe tagi',
					'zrodlo'     => 'form',
					'podpowiedz' => 'Oddziel przecinkami. Nowe tagi pojawią się na stronie po zapisaniu.',
				),
			),
		),
		'opis'      => array(
			'tytul'     => 'Opis — zakładka »Opis«',
			'gdzie'     => 'Długi opis w pierwszej zakładce pod produktem',
			'kolejnosc' => 70,
			'zakladka'  => 'description',
			'pola'      => array(
				'opis' => array(
					'typ'      => 'html',
					'etykieta' => 'Opis',
					'wiersze'  => 16,
					'media'    => true,
				),
			),
		),
		'opinie'    => array(
			'tytul'     => 'Opinie — zakładka »Opinie«',
			'gdzie'     => 'Zakładka z opiniami klientów i formularzem opinii',
			'kolejnosc' => 80,
			'zakladka'  => 'reviews',
			'pola'      => array(
				'opinie' => array(
					'typ'        => 'bool',
					'etykieta'   => 'Pozwól klientom dodawać opinie do tego produktu',
					'tak'        => '1',
					'podpowiedz' => 'Wyłączone: zakładka „Opinie” znika ze strony tego produktu.',
				),
			),
		),
		'powiazane' => array(
			'tytul'     => 'Powiązane produkty',
			'gdzie'     => 'Rząd produktów pod zakładkami',
			'kolejnosc' => 90,
			'info'      => 'Sklep dobiera te produkty sam, z tych samych kategorii co ten produkt. Żeby pokazywały się inne, zmień kategorie w karcie „Kategorie i tagi”.',
		),
	);
	foreach ( array_keys( $wb ) as $kid ) {
		foreach ( array_keys( $wb[ $kid ]['pola'] ?? array() ) as $klucz ) {
			$wb[ $kid ]['pola'][ $klucz ] += array( 'zrodlo' => 'wc' );
		}
	}
	$karty = $wb;
	foreach ( (array) $r['karty'] as $kid => $k ) {
		$kid = sanitize_key( (string) $kid );
		if ( '' !== $kid && is_array( $k ) ) {
			$karty[ $kid ] = $k + ( $wb[ $kid ] ?? array() );
		}
	}
	$i = 0;
	foreach ( $karty as $kid => $k ) {
		$k += array(
			'tytul'     => $kid,
			'gdzie'     => '',
			'kolejnosc' => 50,
			'selektor'  => (string) ( $sel[ $kid ] ?? '' ),
			'zakladka'  => '',
			'pola'      => array(),
			'wspolne'   => array(),
			'info'      => '',
		);
		$pola = array();
		foreach ( (array) $k['pola'] as $klucz => $f ) {
			$pola[ (string) $klucz ] = colorify_produkt_pole_norm( (string) $klucz, (array) $f ) + array( 'selektor' => (string) ( $sel[ $klucz ] ?? '' ) );
		}
		$k['pola'] = $pola;
		$w         = (array) $k['wspolne'];
		if ( ! empty( $w['opcja'] ) && ! empty( $w['pola'] ) ) {
			$w   += array(
				'tytul'  => 'Tekst wspólny dla wielu produktów',
				'zasieg' => '',
				'cap'    => 'manage_woocommerce',
			);
			$wp_  = array();
			foreach ( (array) $w['pola'] as $klucz => $f ) {
				$wp_[ sanitize_key( (string) $klucz ) ] = colorify_produkt_pole_norm( (string) $klucz, (array) $f ) + array(
					'sciezka'      => array( (string) $klucz ),
					'tablica'      => false,
					'zostaw_puste' => false,
				);
			}
			$w['pola']    = $wp_;
			$k['wspolne'] = $w;
		} else {
			$k['wspolne'] = array();
		}
		$k['_i']       = $i++; // stabilne sortowanie przy równej kolejności
		$karty[ $kid ] = $k;
	}
	uasort(
		$karty,
		static function ( $a, $b ) {
			return array( (float) $a['kolejnosc'], $a['_i'] ) <=> array( (float) $b['kolejnosc'], $b['_i'] );
		}
	);
	return $karty;
}

/** Domyślne klucze pola. Typy: tytul, tekst, linie, lekki, html, bool, kwota, obraz, galeria, termy. */
function colorify_produkt_pole_norm( string $klucz, array $f ): array {
	return $f + array(
		'typ'        => 'tekst',
		'etykieta'   => $klucz,
		'podpowiedz' => '',
		'zrodlo'     => 'meta',
		'domyslne'   => '',
		'tak'        => 'yes',
		'kses'       => null,
		'wiersze'    => 3,
		'media'      => false,
		'taksonomia' => '',
	);
}

/** Płaska mapa pól produktu: klucz => pole (bez pól wspólnych). */
function colorify_produkt_pola( array $karty ): array {
	$pola = array();
	foreach ( $karty as $k ) {
		$pola += $k['pola'];
	}
	return $pola;
}

/* ---------------------------------------------------------------------------
 * Odczyt wartości z tych samych pól, z których czyta strona.
 * ------------------------------------------------------------------------- */

function colorify_produkt_nl( $v ): string {
	return str_replace( array( "\r\n", "\r" ), "\n", (string) $v );
}

function colorify_produkt_wc_odczyt( WC_Product $p, string $klucz ) {
	switch ( $klucz ) {
		case 'nazwa':
			return (string) $p->get_name( 'edit' );
		case 'zdjecie':
			return (int) $p->get_image_id( 'edit' );
		case 'galeria':
			return array_values( array_filter( array_map( 'intval', (array) $p->get_gallery_image_ids( 'edit' ) ) ) );
		case 'cena':
			return (string) $p->get_regular_price( 'edit' );
		case 'cena_promo':
			return (string) $p->get_sale_price( 'edit' );
		case 'krotki':
			return colorify_produkt_nl( $p->get_short_description( 'edit' ) );
		case 'opis':
			return colorify_produkt_nl( $p->get_description( 'edit' ) );
		case 'kategorie':
		case 'tagi':
			$ids = array_map( 'intval', (array) ( 'kategorie' === $klucz ? $p->get_category_ids( 'edit' ) : $p->get_tag_ids( 'edit' ) ) );
			sort( $ids );
			return $ids;
		case 'opinie':
			return $p->get_reviews_allowed( 'edit' ) ? '1' : '';
	}
	return '';
}

/** Wartości wszystkich pól produktu (klucz => wartość) prosto z bazy. Pola 'form' (np. nowe tagi) zawsze puste. */
function colorify_produkt_wartosci( WC_Product $p, array $karty ): array {
	$w = array();
	foreach ( colorify_produkt_pola( $karty ) as $klucz => $f ) {
		if ( 'wc' === $f['zrodlo'] ) {
			$w[ $klucz ] = colorify_produkt_wc_odczyt( $p, $klucz );
		} elseif ( 'meta' === $f['zrodlo'] ) {
			$w[ $klucz ] = colorify_produkt_nl( get_post_meta( $p->get_id(), $klucz, true ) );
		} else {
			$w[ $klucz ] = '';
		}
	}
	return $w;
}

/** Wersja zestawu wartości: bramka "nie nadpisuj, czego nie widziałeś". */
function colorify_produkt_wersja( array $wartosci ): string {
	return substr( md5( (string) wp_json_encode( $wartosci ) ), 0, 12 );
}

/** Wartość z opcji strony pod ścieżką kluczy ('' gdy brak); lista wierszy wraca jako tekst, jeden wiersz na linię. */
function colorify_produkt_sciezka_czytaj( $opcja, array $sciezka ): string {
	foreach ( $sciezka as $k ) {
		if ( ! is_array( $opcja ) || ! isset( $opcja[ $k ] ) ) {
			return '';
		}
		$opcja = $opcja[ $k ];
	}
	if ( is_array( $opcja ) ) {
		return implode( "\n", array_filter( array_map( 'strval', array_filter( $opcja, 'is_scalar' ) ), 'strlen' ) );
	}
	return is_scalar( $opcja ) ? colorify_produkt_nl( $opcja ) : '';
}

/** Ustawia wartość pod ścieżką; pusta wartość usuwa klucz (i puste gałęzie), chyba że $zostaw. */
function colorify_produkt_sciezka_ustaw( array $a, array $sciezka, $v, bool $zostaw = false ): array {
	$k = array_shift( $sciezka );
	if ( ! $sciezka ) {
		if ( ! $zostaw && ( '' === $v || array() === $v ) ) {
			unset( $a[ $k ] );
		} else {
			$a[ $k ] = $v;
		}
		return $a;
	}
	$pod = isset( $a[ $k ] ) && is_array( $a[ $k ] ) ? $a[ $k ] : array();
	$pod = colorify_produkt_sciezka_ustaw( $pod, $sciezka, $v, $zostaw );
	if ( $pod || $zostaw ) {
		$a[ $k ] = $pod;
	} else {
		unset( $a[ $k ] );
	}
	return $a;
}

/* ---------------------------------------------------------------------------
 * Walidacja: jedna dla podglądu i zapisu.
 * ------------------------------------------------------------------------- */

/** Wiersze tekstu bez pustych, przycięte. */
function colorify_produkt_linie( $v ): array {
	$out = array();
	foreach ( explode( "\n", colorify_produkt_nl( is_array( $v ) ? implode( "\n", array_filter( $v, 'is_scalar' ) ) : $v ) ) as $l ) {
		$l = trim( sanitize_text_field( $l ) );
		if ( '' !== $l ) {
			$out[] = $l;
		}
	}
	return $out;
}

/** Wartość pola z formularza (już po wp_unslash) w postaci, w jakiej leży w bazie. */
function colorify_produkt_czysc( array $f, $v ) {
	switch ( $f['typ'] ) {
		case 'bool':
			return ! empty( $v ) ? (string) $f['tak'] : '';
		case 'obraz':
			return is_scalar( $v ) ? absint( $v ) : 0;
		case 'galeria':
			$ids = array_filter( array_map( 'absint', is_array( $v ) ? $v : explode( ',', (string) $v ) ) );
			return array_values( array_unique( $ids ) );
		case 'termy':
			$ids = array();
			foreach ( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) as $tid ) {
				if ( term_exists( $tid, (string) $f['taksonomia'] ) ) {
					$ids[] = $tid;
				}
			}
			sort( $ids );
			return $ids;
		case 'kwota':
			$v = is_scalar( $v ) ? trim( (string) $v ) : '';
			if ( '' === $v ) {
				return '';
			}
			$n = wc_format_decimal( $v );
			return ( '' === $n || (float) $n < 0 ) ? '' : (string) $n;
		case 'linie':
			return implode( "\n", colorify_produkt_linie( $v ) );
		case 'lekki':
			return trim( wp_kses( colorify_produkt_nl( is_scalar( $v ) ? $v : '' ), is_array( $f['kses'] ) ? $f['kses'] : colorify_produkt_kses_lekki() ) );
		case 'html':
			$v = colorify_produkt_nl( is_scalar( $v ) ? $v : '' );
			if ( is_array( $f['kses'] ) ) {
				return trim( wp_kses( $v, $f['kses'] ) );
			}
			return current_user_can( 'unfiltered_html' ) ? $v : wp_kses_post( $v ); // jak rdzeń przy zapisie wpisu
		case 'tytul':
			$v = trim( is_scalar( $v ) ? (string) $v : '' );
			return current_user_can( 'unfiltered_html' ) ? $v : wp_kses( $v, 'data' ); // jak title_save_pre w rdzeniu
	}
	return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
}

/**
 * Wartości produktu z formularza: to, czego nie ma w $post, zostaje jak w bazie. Edytor WP (typ 'html') jest brany tylko,
 * gdy ktoś w nim pisał ($tkniete[klucz]): samo otwarcie edytora przepisuje HTML po swojemu i nie może zmienić treści.
 */
function colorify_produkt_z_formularza( WC_Product $p, array $karty, array $post, array $tkniete ): array {
	$nowe = colorify_produkt_wartosci( $p, $karty );
	foreach ( colorify_produkt_pola( $karty ) as $klucz => $f ) {
		if ( ! array_key_exists( $klucz, $post ) || ( 'html' === $f['typ'] && empty( $tkniete[ $klucz ] ) ) ) {
			continue;
		}
		$nowe[ $klucz ] = colorify_produkt_czysc( $f, $post[ $klucz ] );
	}
	return $nowe;
}

/* ---------------------------------------------------------------------------
 * Zapis: WC_Product (te same hooki i czyszczenie pamięci co zwykły ekran produktu), tylko zmienione pola.
 * ------------------------------------------------------------------------- */

/**
 * Zapisuje zmienione pola. Zwraca listę zmienionych kluczy (pusta = nic się nie zmieniło) albo WP_Error:
 * 'konflikt' (produkt zmienił się po otwarciu edytora; nic nie zapisano) albo 'cena'.
 */
function colorify_produkt_zapisz( int $id, array $nowe, $wersja_bazowa = null ) {
	$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
	if ( ! $p ) {
		return new WP_Error( 'brak', 'Nie ma takiego produktu.' );
	}
	$karty = colorify_produkt_karty( $id );
	$pola  = colorify_produkt_pola( $karty );
	$stare = colorify_produkt_wartosci( $p, $karty );
	if ( null !== $wersja_bazowa && colorify_produkt_wersja( $stare ) !== $wersja_bazowa ) {
		return new WP_Error( 'konflikt', 'Produkt zmienił się po otwarciu edytora. Nic nie zapisano.' );
	}
	if ( isset( $nowe['nazwa'] ) && '' === trim( wp_strip_all_tags( (string) $nowe['nazwa'] ) ) ) {
		$nowe['nazwa'] = $stare['nazwa']; // produkt bez nazwy psuje listy i koszyk
	}
	$cena  = (string) ( $nowe['cena'] ?? '' );
	$promo = (string) ( $nowe['cena_promo'] ?? '' );
	if ( '' !== $promo && ( '' === $cena || (float) $promo >= (float) $cena ) ) {
		return new WP_Error( 'cena', 'Cena promocyjna musi być niższa od zwykłej ceny. Nic nie zapisano.' );
	}
	// Nowe tagi z pola "Dodaj nowe tagi": powstają dopiero przy zapisie.
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) ( $nowe['tagi_nowe'] ?? '' ) ) ) ) as $nazwa ) {
		$t = term_exists( $nazwa, 'product_tag' );
		if ( ! $t ) {
			$t = wp_insert_term( $nazwa, 'product_tag' );
		}
		if ( is_array( $t ) && ! empty( $t['term_id'] ) ) {
			$nowe['tagi']   = (array) ( $nowe['tagi'] ?? array() );
			$nowe['tagi'][] = (int) $t['term_id'];
		}
	}
	if ( isset( $nowe['tagi'] ) ) {
		$nowe['tagi'] = array_values( array_unique( array_map( 'intval', (array) $nowe['tagi'] ) ) );
		sort( $nowe['tagi'] );
	}

	$zm = array();
	foreach ( $stare as $klucz => $bylo ) {
		if ( ! array_key_exists( $klucz, $nowe ) || 'form' === $pola[ $klucz ]['zrodlo'] || $nowe[ $klucz ] === $bylo ) {
			continue;
		}
		$v    = $nowe[ $klucz ];
		$zm[] = $klucz;
		if ( 'meta' === $pola[ $klucz ]['zrodlo'] ) {
			if ( '' === trim( (string) $v ) ) {
				$p->delete_meta_data( $klucz );
			} else {
				$p->update_meta_data( $klucz, $v );
			}
			continue;
		}
		switch ( $klucz ) {
			// shortcut: WC_Product::save() gubi odwrotne ukośniki w nazwie i opisach (wp_update_post bez wp_slash), tak samo
			// jak REST API WooCommerce; zmienić na własny wp_update_post( wp_slash() ), gdy treści zaczną ich potrzebować.
			case 'nazwa':
				$p->set_name( $v );
				break;
			case 'opis':
				$p->set_description( $v );
				break;
			case 'krotki':
				$p->set_short_description( $v );
				break;
			case 'cena':
				$p->set_regular_price( $v );
				break;
			case 'cena_promo':
				$p->set_sale_price( $v );
				break;
			case 'zdjecie':
				$p->set_image_id( $v ? $v : '' );
				break;
			case 'galeria':
				$p->set_gallery_image_ids( $v );
				break;
			case 'kategorie':
				$p->set_category_ids( $v );
				break;
			case 'tagi':
				$p->set_tag_ids( $v );
				break;
			case 'opinie':
				$p->set_reviews_allowed( '' !== $v );
				break;
		}
	}
	if ( $zm ) {
		$p->save();
	}
	if ( get_current_user_id() ) {
		delete_transient( colorify_produkt_podglad_klucz( $id ) );
	}
	return $zm;
}

/**
 * Zapis tekstów wspólnych jednej karty do opcji strony. $post: klucz pola => wartość z formularza.
 * Pusta wartość usuwa klucz z opcji (strona wraca do tekstu z kodu). Zwraca tablicę opcji po zmianie.
 */
function colorify_produkt_wspolne_scal( array $w, array $post, $opcja = null ): array {
	$opcja = is_array( $opcja ) ? $opcja : get_option( (string) $w['opcja'], array() );
	$opcja = is_array( $opcja ) ? $opcja : array();
	foreach ( $w['pola'] as $klucz => $f ) {
		if ( ! array_key_exists( $klucz, $post ) ) {
			continue;
		}
		$v = colorify_produkt_czysc( $f, $post[ $klucz ] );
		if ( 'html' === $f['typ'] && '' !== (string) $f['domyslne'] && colorify_produkt_bez_spacji( $v ) === colorify_produkt_bez_spacji( colorify_produkt_czysc( $f, $f['domyslne'] ) ) ) {
			$v = ''; // tekst z kodu wpisany do edytora i niezmieniony: nie robimy jego kopii w opcji
		}
		if ( ! empty( $f['tablica'] ) ) {
			$v = '' === $v ? array() : explode( "\n", (string) $v );
		}
		$opcja = colorify_produkt_sciezka_ustaw( $opcja, (array) $f['sciezka'], $v, ! empty( $f['zostaw_puste'] ) );
	}
	return $opcja;
}

function colorify_produkt_bez_spacji( $html ): string {
	return (string) preg_replace( '/\s+/u', '', (string) $html );
}

/* ---------------------------------------------------------------------------
 * Podgląd (ramka edytora): ?colorify_produkt_podglad=<id> dla osoby, która może edytować ten produkt.
 * Niezapisany formularz leży w transiencie na konto i produkt; strona czyta go przez filtry WooCommerce i WordPressa.
 * Transient niesie gotowe wartości (bez rejestru), więc strona w podglądzie nie wywołuje kodu rejestracji kart.
 * ------------------------------------------------------------------------- */

function colorify_produkt_podglad_klucz( int $id ): string {
	return 'colorify_produkt_podglad_' . get_current_user_id() . '_' . $id;
}

/** ID produktu w trybie podglądu albo 0. */
function colorify_produkt_podglad_id(): int {
	static $id = null;
	if ( null === $id ) {
		$id = 0;
		$g  = isset( $_GET['colorify_produkt_podglad'] ) ? absint( wp_unslash( $_GET['colorify_produkt_podglad'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $g && ! is_admin() && is_user_logged_in() && 'product' === get_post_type( $g ) && current_user_can( 'edit_post', $g ) ) {
			$id = $g;
		}
	}
	return $id;
}

/**
 * Dane podglądu z wartości formularza. $cpw: karta => ( klucz pola wspólnego => wartość ); teksty wspólne trafiają
 * do podglądu tylko od osoby, która może je zapisać.
 */
function colorify_produkt_podglad_zbuduj( array $karty, array $nowe, array $cpw ): array {
	$d = array(
		'wc'        => array(),
		'meta'      => array(),
		'opcje'     => array(),
		'znaczniki' => array(),
	);
	foreach ( colorify_produkt_pola( $karty ) as $klucz => $f ) {
		if ( 'wc' === $f['zrodlo'] ) {
			$d['wc'][ $klucz ] = $nowe[ $klucz ];
		} elseif ( 'meta' === $f['zrodlo'] ) {
			$d['meta'][ $klucz ] = $nowe[ $klucz ];
		}
		if ( '' !== (string) $f['selektor'] ) {
			$d['znaczniki'][ $klucz ] = (string) $f['selektor'];
		}
	}
	foreach ( $karty as $kid => $k ) {
		if ( '' !== (string) $k['selektor'] ) {
			$d['znaczniki'][ 'karta:' . $kid ] = (string) $k['selektor']; // po polach: element pola wygrywa z elementem karty
		}
	}
	foreach ( $karty as $kid => $k ) {
		$w = $k['wspolne'];
		if ( ! $w || empty( $cpw[ $kid ] ) || ! is_array( $cpw[ $kid ] ) || ! current_user_can( (string) $w['cap'] ) ) {
			continue;
		}
		$o                   = (string) $w['opcja'];
		$d['opcje'][ $o ] = colorify_produkt_wspolne_scal( $w, $cpw[ $kid ], $d['opcje'][ $o ] ?? null );
	}
	return $d;
}

/** Hooki strony w trybie podglądu. */
function colorify_produkt_podglad_hooki( int $id, array $d ): void {
	$wc   = (array) ( $d['wc'] ?? array() );
	$meta = (array) ( $d['meta'] ?? array() );

	add_filter( 'show_admin_bar', '__return_false', 20 );
	add_filter( 'wp_robots', 'wp_robots_no_robots' );
	add_action(
		'template_redirect',
		static function () {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		},
		0
	);

	// Zapytany wpis: szablony czytają $post->post_content i $post->post_excerpt wprost.
	add_action(
		'wp',
		static function () use ( $id, $wc ) {
			$q = get_queried_object();
			if ( ! $q instanceof WP_Post || (int) $q->ID !== $id ) {
				return;
			}
			foreach ( array( 'nazwa' => 'post_title', 'opis' => 'post_content', 'krotki' => 'post_excerpt' ) as $klucz => $pole ) {
				if ( isset( $wc[ $klucz ] ) ) {
					$q->$pole = (string) $wc[ $klucz ];
				}
			}
			if ( isset( $wc['opinie'] ) ) {
				$q->comment_status = '' !== $wc['opinie'] ? 'open' : 'closed';
			}
		}
	);
	if ( isset( $wc['nazwa'] ) ) {
		add_filter(
			'the_title',
			static function ( $t, $pid = 0 ) use ( $id, $wc ) {
				return (int) $pid === $id ? (string) $wc['nazwa'] : $t;
			},
			5,
			2
		);
	}
	if ( isset( $wc['opinie'] ) ) {
		add_filter(
			'comments_open',
			static function ( $open, $pid ) use ( $id, $wc ) {
				return (int) $pid === $id ? '' !== $wc['opinie'] : $open;
			},
			10,
			2
		);
	}

	// Obiekt WC_Product (czyta wpis z pamięci podręcznej, osobno od zapytanego wpisu).
	$wlasciwosci = array(
		'name'              => 'nazwa',
		'description'       => 'opis',
		'short_description' => 'krotki',
		'regular_price'     => 'cena',
		'sale_price'        => 'cena_promo',
		'image_id'          => 'zdjecie',
		'gallery_image_ids' => 'galeria',
		'category_ids'      => 'kategorie',
		'tag_ids'           => 'tagi',
	);
	foreach ( $wlasciwosci as $prop => $klucz ) {
		if ( ! isset( $wc[ $klucz ] ) ) {
			continue;
		}
		add_filter(
			'woocommerce_product_get_' . $prop,
			static function ( $v, $product ) use ( $id, $wc, $klucz ) {
				return $product instanceof WC_Product && $product->get_id() === $id ? $wc[ $klucz ] : $v;
			},
			5,
			2
		);
	}
	if ( isset( $wc['cena'] ) ) {
		add_filter(
			'woocommerce_product_get_price',
			static function ( $v, $product ) use ( $id, $wc ) {
				if ( ! $product instanceof WC_Product || $product->get_id() !== $id ) {
					return $v;
				}
				$promo = (string) ( $wc['cena_promo'] ?? '' );
				return '' !== $promo && (float) $promo < (float) $wc['cena'] ? $promo : $wc['cena'];
			},
			5,
			2
		);
	}
	if ( isset( $wc['opinie'] ) ) {
		add_filter(
			'woocommerce_product_get_reviews_allowed',
			static function ( $v, $product ) use ( $id, $wc ) {
				return $product instanceof WC_Product && $product->get_id() === $id ? '' !== $wc['opinie'] : $v;
			},
			5,
			2
		);
	}

	// Meta produktu (pola strony + zdjęcie główne).
	add_filter(
		'get_post_metadata',
		static function ( $check, $oid, $key, $single ) use ( $id, $meta, $wc ) {
			if ( (int) $oid !== $id || '' === (string) $key ) {
				return $check;
			}
			if ( '_thumbnail_id' === $key && isset( $wc['zdjecie'] ) ) {
				$v = $wc['zdjecie'] ? (string) $wc['zdjecie'] : '';
			} elseif ( array_key_exists( $key, $meta ) ) {
				$v = $meta[ $key ];
			} else {
				return $check;
			}
			return ( $single || '' !== $v ) ? array( $v ) : array();
		},
		10,
		4
	);

	// Kategorie i tagi: filtry tylko dla taksonomii, w której formularz ma inny zestaw niż baza (inaczej zostaje baza).
	$zmienione = array();
	foreach ( array( 'product_cat' => 'kategorie', 'product_tag' => 'tagi' ) as $tax => $klucz ) {
		if ( ! isset( $wc[ $klucz ] ) || ! taxonomy_exists( $tax ) ) {
			continue;
		}
		$chce = array_map( 'intval', (array) $wc[ $klucz ] );
		$jest = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids' ) );
		$jest = is_wp_error( $jest ) ? array() : array_map( 'intval', $jest );
		sort( $chce );
		sort( $jest );
		if ( $chce !== $jest ) {
			$zmienione[ $tax ] = $chce;
		}
	}
	if ( $zmienione ) {
		$lista = static function ( $tax, $po ) use ( $zmienione ) {
			$l = array();
			foreach ( $zmienione[ $tax ] as $tid ) {
				$t = get_term( $tid, $tax );
				if ( $t instanceof WP_Term ) {
					$l[] = $t;
				}
			}
			usort(
				$l,
				static function ( $x, $y ) use ( $po ) {
					return 'parent' === $po ? ( $x->parent <=> $y->parent ) : strcasecmp( $x->name, $y->name );
				}
			);
			return $l;
		};
		add_filter(
			'get_the_terms',
			static function ( $terms, $pid, $tax ) use ( $id, $zmienione, $lista ) {
				if ( (int) $pid !== $id || ! isset( $zmienione[ $tax ] ) ) {
					return $terms;
				}
				$l = $lista( $tax, 'name' );
				return $l ? $l : false;
			},
			10,
			3
		);
		add_filter(
			'woocommerce_get_product_terms',
			static function ( $terms, $pid, $tax, $args ) use ( $id, $zmienione, $lista ) {
				$pola = (string) ( $args['fields'] ?? 'all' );
				if ( (int) $pid !== $id || ! isset( $zmienione[ $tax ] ) || ! in_array( $pola, array( 'all', 'names', 'ids', 'slugs' ), true ) ) {
					return $terms;
				}
				$l = $lista( $tax, 'parent' === ( $args['orderby'] ?? '' ) ? 'parent' : 'name' );
				return 'all' === $pola ? $l : wp_list_pluck( $l, 'ids' === $pola ? 'term_id' : ( 'slugs' === $pola ? 'slug' : 'name' ) );
			},
			10,
			4
		);
	}

	// Teksty wspólne: opcja strony z niezapisanymi wartościami.
	foreach ( (array) ( $d['opcje'] ?? array() ) as $nazwa => $wartosc ) {
		add_filter(
			'pre_option_' . $nazwa,
			static function () use ( $wartosc ) {
				return $wartosc;
			}
		);
	}

	// Znaczniki dla panelu (klik w podglądzie otwiera kartę, fokus w panelu podświetla element) + zakładki WooCommerce.
	$znaczniki = (array) ( $d['znaczniki'] ?? array() );
	add_action(
		'wp_footer',
		static function () use ( $znaczniki ) {
			echo '<script>(function(){var m=' . wp_json_encode( (object) $znaczniki ) . ';Object.keys(m).forEach(function(k){try{[].forEach.call(document.querySelectorAll(m[k]),function(e){if(!e.hasAttribute("data-cft-key")){e.setAttribute("data-cft-key",k);}});}catch(x){}});'
				. 'window.addEventListener("cft:pokaz",function(ev){var e=document.querySelector(\'[data-cft-key="\'+ev.detail.key+\'"]\'),p=e&&e.closest(".woocommerce-Tabs-panel"),a=p&&document.querySelector(\'.wc-tabs a[href="#\'+p.id+\'"]\');if(a){a.click();ev.preventDefault();ev.detail.el=e;}});})();</script>' . "\n";
		},
		99
	);
}

/* ---------------------------------------------------------------------------
 * Hooki tylko, gdy moduł jest włączony i jest WooCommerce.
 * ------------------------------------------------------------------------- */
add_action(
	'init',
	static function () {
		if ( ! colorify_produkt_dostepny() ) {
			return;
		}
		add_post_type_support( 'product', 'revisions' ); // historia zmian nazwy, opisu i tekstu pod ceną (także ze zwykłego ekranu produktu)
		$id = colorify_produkt_podglad_id();
		if ( $id ) {
			$d = get_transient( colorify_produkt_podglad_klucz( $id ) );
			colorify_produkt_podglad_hooki( $id, is_array( $d ) ? $d : array() );
		}
		if ( is_admin() ) {
			require_once __DIR__ . '/colorify-produkt-screen.php';
		} elseif ( ! $id ) {
			add_action( 'admin_bar_menu', 'colorify_produkt_pasek_admina', 81 );
		}
	},
	20
);

/** Adres edytora dla produktu. */
function colorify_produkt_url( int $id = 0 ): string {
	$a = array(
		'post_type' => 'product',
		'page'      => 'colorify-produkt',
	);
	if ( $id ) {
		$a['id'] = $id;
	}
	return add_query_arg( $a, admin_url( 'edit.php' ) );
}

/** Pasek admina na stronie produktu: wejście do edytora. */
function colorify_produkt_pasek_admina( $bar ): void {
	if ( ! is_singular( 'product' ) || ! current_user_can( 'edit_post', get_queried_object_id() ) ) {
		return;
	}
	$bar->add_node(
		array(
			'id'    => 'colorify-produkt',
			'title' => 'Edytuj z podglądem',
			'href'  => colorify_produkt_url( (int) get_queried_object_id() ),
		)
	);
}
