<?php
/**
 * Samotest listy uslug, kategorii i porzadku ekranow (includes/admin-lista.php).
 * Dolaczany przez tests/samotest.php (tam sa $sprawdz i $admin). Nic nie publikuje: wlasny szkic testowy,
 * wlasna kategoria i wlasna strona sa tworzone i usuwane tutaj.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

echo "--- lista uslug i kategorie (admin-lista.php) ---\n";
if ( ! function_exists( 'kw_uslugi_lista_przelacz' ) ) {
	require_once KW_USLUGI_KATALOG . 'includes/admin.php';
}
$kw_l_admin = isset( $admin ) ? (int) $admin : 1;
wp_set_current_user( $kw_l_admin );

/* ---- przelacznik widocznosci ---- */
$kw_l_id   = wp_insert_post(
	array(
		'post_type'   => 'kw_usluga',
		'post_status' => 'draft',
		'post_title'  => 'Samotest <i>lista</i>',
	)
);
$kw_l_page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'draft',
		'post_title'  => 'Samotest strona lista',
	)
);
foreach ( array( $kw_l_id, $kw_l_page ) as $kw_l_p ) {
	update_post_meta( $kw_l_p, 'kw_w_cenniku', '1' );
	update_post_meta( $kw_l_p, 'kw_w_formularzu', '1' );
	update_post_meta( $kw_l_p, 'kw_cena_min', '123' );
}
$kw_l_stan = static function ( $id ) {
	$meta = get_post_meta( $id );
	unset( $meta['_edit_lock'], $meta['_edit_last'] );
	ksort( $meta );
	$wpis = get_post( $id );
	return wp_json_encode( array( $meta, $wpis->post_title, $wpis->post_status, $wpis->post_type ) );
};
$kw_l_token = static function ( $id, $pole, $stan ) {
	return wp_create_nonce( 'kw_przelacz_' . $id . '_' . $pole . '_' . $stan );
};
$kw_l_przed = $kw_l_stan( $kw_l_id );

$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_cenniku', '0', '' );
$sprawdz( 'przelacznik: brak tokenu => nic sie nie zmienia', 'token' === $w['kod'] && $kw_l_przed === $kw_l_stan( $kw_l_id ), wp_json_encode( $w ) );
$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_cenniku', '0', 'zlytoken' );
$sprawdz( 'przelacznik: zly token => nic sie nie zmienia', 'token' === $w['kod'] && $kw_l_przed === $kw_l_stan( $kw_l_id ), wp_json_encode( $w ) );
$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_cenniku', '0', $kw_l_token( $kw_l_id, 'kw_w_formularzu', '0' ) );
$sprawdz( 'przelacznik: token od innego pola nie pasuje', 'token' === $w['kod'] && $kw_l_przed === $kw_l_stan( $kw_l_id ), wp_json_encode( $w ) );
$w = kw_uslugi_lista_przelacz( $kw_l_page, 'kw_w_cenniku', '0', $kw_l_token( $kw_l_page, 'kw_w_cenniku', '0' ) );
$sprawdz( 'przelacznik: wpis, ktory nie jest usluga (strona) => odmowa, nic sie nie zmienia', 'brak' === $w['kod'] && '1' === get_post_meta( $kw_l_page, 'kw_w_cenniku', true ), wp_json_encode( $w ) );

$kw_l_odmow = static function ( $uprawnienia, $cap ) {
	return 'edit_post' === $cap ? array( 'do_not_allow' ) : $uprawnienia;
};
add_filter( 'map_meta_cap', $kw_l_odmow, 10, 2 );
$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_cenniku', '0', $kw_l_token( $kw_l_id, 'kw_w_cenniku', '0' ) );
remove_filter( 'map_meta_cap', $kw_l_odmow, 10 );
$sprawdz( 'przelacznik: poprawny token, ale brak prawa edycji TEJ uslugi => nic sie nie zmienia', 'prawa' === $w['kod'] && $kw_l_przed === $kw_l_stan( $kw_l_id ), wp_json_encode( $w ) );

foreach ( array( 'kw_cena_min', 'post_title', '' ) as $kw_l_zle ) {
	$w = kw_uslugi_lista_przelacz( $kw_l_id, $kw_l_zle, '0', $kw_l_token( $kw_l_id, $kw_l_zle, '0' ) );
	$sprawdz( 'przelacznik: pole spoza bialej listy ("' . $kw_l_zle . '") => nic sie nie zmienia', 'pole' === $w['kod'] && $kw_l_przed === $kw_l_stan( $kw_l_id ), wp_json_encode( $w ) );
}

$kw_l_token_cennik = $kw_l_token( $kw_l_id, 'kw_w_cenniku', '0' );
wp_set_current_user( 0 );
$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_cenniku', '0', $kw_l_token_cennik );
wp_set_current_user( $kw_l_admin );
$sprawdz( 'przelacznik: gosc z tokenem administratora => nic sie nie zmienia', 'token' === $w['kod'] && $kw_l_przed === $kw_l_stan( $kw_l_id ), wp_json_encode( $w ) );

$w     = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_cenniku', '0', $kw_l_token_cennik );
$po    = get_post_meta( $kw_l_id );
$przed = json_decode( $kw_l_przed, true );
unset( $po['_edit_lock'], $po['_edit_last'], $po['kw_w_cenniku'] );
$przed_bez = $przed[0];
unset( $przed_bez['kw_w_cenniku'] );
ksort( $po );
$sprawdz( 'przelacznik: poprawne zadanie zmienia dokladnie jedno pole (kw_w_cenniku 1 -> 0)', 'ok' === $w['kod'] && '0' === $w['stan'] && '0' === get_post_meta( $kw_l_id, 'kw_w_cenniku', true ) && wp_json_encode( $po ) === wp_json_encode( $przed_bez ) && '1' === get_post_meta( $kw_l_id, 'kw_w_formularzu', true ), wp_json_encode( $w ) );
$kw_l_przed2 = $kw_l_stan( $kw_l_id );
$w           = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_formularzu', '0', $kw_l_token( $kw_l_id, 'kw_w_formularzu', '0' ) );
$sprawdz( 'przelacznik: drugie pole tez dziala, pierwsze zostaje', 'ok' === $w['kod'] && '0' === get_post_meta( $kw_l_id, 'kw_w_formularzu', true ) && '0' === get_post_meta( $kw_l_id, 'kw_w_cenniku', true ), wp_json_encode( $w ) );
// Idempotencja: odnosnik niesie stan docelowy, wiec to samo zadanie dwa razy zostawia te sama wartosc.
$kw_l_po_pierwszym = $kw_l_stan( $kw_l_id );
$w                 = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_formularzu', '0', $kw_l_token( $kw_l_id, 'kw_w_formularzu', '0' ) );
$sprawdz( 'przelacznik: to samo zadanie drugi raz (podwojne klikniecie) zostawia ta sama wartosc, nic wiecej sie nie zmienia', 'ok' === $w['kod'] && '0' === get_post_meta( $kw_l_id, 'kw_w_formularzu', true ) && $kw_l_po_pierwszym === $kw_l_stan( $kw_l_id ), wp_json_encode( $w ) );
$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_formularzu', '0', $kw_l_token( $kw_l_id, 'kw_w_formularzu', '1' ) );
$sprawdz( 'przelacznik: token na inny stan docelowy nie pasuje', 'token' === $w['kod'] && '0' === get_post_meta( $kw_l_id, 'kw_w_formularzu', true ), wp_json_encode( $w ) );
$w  = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_formularzu', '1', $kw_l_token( $kw_l_id, 'kw_w_formularzu', '1' ) );
$w2 = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_formularzu', '1', $kw_l_token( $kw_l_id, 'kw_w_formularzu', '1' ) );
$sprawdz( 'przelacznik: stan docelowy 1 (dwa razy) => 1', 'ok' === $w['kod'] && 'ok' === $w2['kod'] && '1' === get_post_meta( $kw_l_id, 'kw_w_formularzu', true ), wp_json_encode( array( $w, $w2 ) ) );
$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_formularzu', 'abc', $kw_l_token( $kw_l_id, 'kw_w_formularzu', 'abc' ) );
$sprawdz( 'przelacznik: stan docelowy spoza 0 i 1 => nic sie nie zmienia', 'pole' === $w['kod'] && '1' === get_post_meta( $kw_l_id, 'kw_w_formularzu', true ), wp_json_encode( $w ) );

// Zadanie z przycisku (POST na admin-post): przekierowanie przechwytuje wyjatek z filtru wp_redirect, wiec exit nie zatrzymuje testu.
$kw_l_przechwyt = static function ( $adres ) {
	throw new RuntimeException( $adres );
};
add_filter( 'wp_redirect', $kw_l_przechwyt );
$kw_l_zadanie = static function ( array $post ) {
	$stare = $_POST;
	$_POST = $post;
	try {
		kw_uslugi_lista_akcja();
		$adres = 'BRAK PRZEKIEROWANIA';
	} catch ( RuntimeException $e ) {
		$adres = $e->getMessage();
	}
	$_POST = $stare;
	return $adres;
};
$kw_l_przed3 = $kw_l_stan( $kw_l_id );
$adres       = $kw_l_zadanie( array( 'kw_przelacz' => $kw_l_id . '|kw_w_cenniku|1|zlytoken' ) );
$sprawdz( 'zadanie z przycisku (POST): zly token => powrot na liste z komunikatem "token", nic sie nie zmienia', false !== strpos( $adres, 'edit.php' ) && false !== strpos( $adres, 'kw_info=token' ) && $kw_l_przed3 === $kw_l_stan( $kw_l_id ), $adres );
$kw_l_wartosc = $kw_l_id . '|kw_w_cenniku|1|' . $kw_l_token( $kw_l_id, 'kw_w_cenniku', '1' );
$adres        = $kw_l_zadanie( array( 'kw_przelacz' => $kw_l_wartosc ) );
$adres2       = $kw_l_zadanie( array( 'kw_przelacz' => $kw_l_wartosc ) );
$sprawdz( 'zadanie z przycisku (POST): poprawne => zmienia pole, wraca na liste do wiersza (#post-ID) z komunikatem; powtorzone nic nie psuje', false !== strpos( $adres, 'kw_info=wl-cennik' ) && false !== strpos( $adres, '#post-' . $kw_l_id ) && false !== strpos( $adres2, 'kw_info=wl-cennik' ) && '1' === get_post_meta( $kw_l_id, 'kw_w_cenniku', true ), $adres );
$adres = $kw_l_zadanie( array() );
$sprawdz( 'zadanie bez pola kw_przelacz i kw_przesun => komunikat "brak", nic sie nie zmienia', false !== strpos( $adres, 'kw_info=brak' ) && $kw_l_stan( $kw_l_id ) === $kw_l_stan( $kw_l_id ), $adres );
remove_filter( 'wp_redirect', $kw_l_przechwyt );

$kw_l_html = kw_uslugi_lista_przelacznik( $kw_l_id, 'kw_w_cenniku' ) . kw_uslugi_lista_przelacznik( $kw_l_id, 'pole_obce' );
$sprawdz(
	'przelacznik: przycisk-wlacznik (role=switch, aria-checked, form=wspolny formularz, stan docelowy i token w wartosci), stan slowem "widać", nazwa uslugi dla czytnikow; pole obce bez znacznika',
	false !== strpos( $kw_l_html, 'role="switch"' ) && false !== strpos( $kw_l_html, 'aria-checked="true"' ) && false !== strpos( $kw_l_html, 'form="kw-przelacznik-formularz"' ) && false !== strpos( $kw_l_html, 'name="kw_przelacz"' ) && 1 === preg_match( '~value="' . $kw_l_id . '\|kw_w_cenniku\|0\|[a-f0-9]+"~', $kw_l_html ) && false !== strpos( $kw_l_html, '>widać<' ) && false !== strpos( $kw_l_html, 'w cenniku na stronie: Samotest &lt;i&gt;lista&lt;/i&gt;' ) && 1 === substr_count( $kw_l_html, '<button type="submit"' ),
	$kw_l_html
);
$kw_l_html_k = kw_uslugi_lista_przelacznik( $kw_l_id, 'kw_w_formularzu' );
$sprawdz(
	'przelacznik: dymek na przelaczniku (role=tooltip) opisuje stan i, dla szkicu, ze klienci go nie widza; aria-describedby wskazuje dymek',
	false !== strpos( $kw_l_html, 'Włączone: klienci widzą tę usługę w cenniku na stronie. Kliknij, żeby ją ukryć. Usługa nie jest usuwana.' ) && false !== strpos( $kw_l_html, 'To jest szkic: klienci nie widzą go wcale, dopóki go nie opublikujesz.' ) && 1 === preg_match( '~aria-describedby="(kwa-dymek-sw-' . $kw_l_id . '-kw_w_cenniku)".*role="tooltip" id="\1"~s', $kw_l_html ) && false !== strpos( $kw_l_html_k, 'kwa-dymek--otoczka' ),
	$kw_l_html_k
);
update_post_meta( $kw_l_id, 'kw_w_formularzu', '0' );
$kw_l_html_k = kw_uslugi_lista_przelacznik( $kw_l_id, 'kw_w_formularzu' );
update_post_meta( $kw_l_id, 'kw_w_formularzu', '1' );
$sprawdz( 'przelacznik: wylaczony pokazuje "ukryta", aria-checked=false i dymek "Wyłączone: tej usługi nie ma na liście w polu „Usługa” formularza kontaktowego. Kliknij, żeby ją pokazać."', false !== strpos( $kw_l_html_k, '>ukryta<' ) && false !== strpos( $kw_l_html_k, 'aria-checked="false"' ) && false !== strpos( $kw_l_html_k, 'Wyłączone: tej usługi nie ma na liście w polu „Usługa” formularza kontaktowego. Kliknij, żeby ją pokazać.' ), $kw_l_html_k );

ob_start();
kw_uslugi_kolumna( 'kw_w_cenniku', $kw_l_id );
$kw_l_komorka = (string) ob_get_clean();
$kw_l_naglowki = kw_uslugi_kolumny( array( 'cb' => 'x' ) );
$sprawdz(
	'kolumny widocznosci: komorka ma etykiete z "?" (dla telefonu) i przelacznik; naglowki "W cenniku na stronie" i "W polu Usługa formularza" maja dymki',
	false !== strpos( $kw_l_komorka, 'kw-sw-etykieta' ) && false !== strpos( $kw_l_komorka, 'role="switch"' ) && false !== strpos( $kw_l_naglowki['kw_w_cenniku'], 'W cenniku na stronie' ) && false !== strpos( $kw_l_naglowki['kw_w_cenniku'], 'role="tooltip"' ) && false !== strpos( $kw_l_naglowki['kw_w_formularzu'], 'W polu Usługa formularza' ) && false !== strpos( $kw_l_naglowki['kw_w_formularzu'], 'role="tooltip"' ) && false !== strpos( $kw_l_naglowki['kw_kolejnosc'], 'Kolejność na stronie' ) && false !== strpos( $kw_l_naglowki['kw_kolejnosc'], 'Strzałki przesuwają usługę o jedno miejsce' ),
	wp_json_encode( array_keys( $kw_l_naglowki ) )
);

$kw_l_akcje = array(
	'edit'                => '<a>e</a>',
	'inline hide-if-no-js' => '<button>q</button>',
	'trash'               => '<a>t</a>',
	'duplicate'           => '<a>d</a>',
	'eae_duplicate'       => '<a>x</a>',
);
$kw_l_kopia = kw_uslugi_lista_akcje( array( 'duplicate' => '<a href="x" title="Duplicate this as draft">Duplicate This</a>' ), get_post( $kw_l_id ) );
$sprawdz( 'akcja kopii po polsku ("Zrób kopię"), odnosnik bez zmian', false !== strpos( $kw_l_kopia['duplicate'], '>Zrób kopię<' ) && false !== strpos( $kw_l_kopia['duplicate'], 'href="x"' ) && false === strpos( $kw_l_kopia['duplicate'], 'Duplicate' ), $kw_l_kopia['duplicate'] );
$sprawdz( 'akcje wiersza uslugi: Edytuj, Kosz, kopia; bez szybkiej edycji i drugiego duplikatora', array( 'edit', 'trash', 'duplicate' ) === array_keys( kw_uslugi_lista_akcje( $kw_l_akcje, get_post( $kw_l_id ) ) ) && array_keys( $kw_l_akcje ) === array_keys( kw_uslugi_lista_akcje( $kw_l_akcje, get_post( $kw_l_page ) ) ) );
$sprawdz( 'masowe dzialania: bez zbiorczej edycji, kosz zostaje', array( 'trash' => 'Kosz' ) === kw_uslugi_lista_masowe( array( 'edit' => 'Edycja', 'trash' => 'Kosz' ) ) );
$sprawdz( 'bez dopisku "Szkic" przy tytule (rdzen dokleja do niego pauze)', array() === apply_filters( 'display_post_states', array( 'draft' => 'Szkic' ), get_post( $kw_l_id ) ) && array( 'draft' => 'Szkic' ) === apply_filters( 'display_post_states', array( 'draft' => 'Szkic' ), get_post( $kw_l_page ) ) );
$sprawdz( 'stan szkicu: wlasny znacznik, opublikowana bez znacznika', false !== strpos( kw_uslugi_lista_stan( 'draft' ), 'Szkic' ) && '' === kw_uslugi_lista_stan( 'publish' ) );
$sprawdz( 'kolumny uslug: nazwa, kategoria, cena, dwa przelaczniki widocznosci, kolejnosc; bez daty', array( 'cb', 'title', 'kw_kategoria', 'kw_cena', 'kw_w_cenniku', 'kw_w_formularzu', 'kw_kolejnosc' ) === array_keys( kw_uslugi_kolumny( array( 'cb' => 'x', 'title' => 'T', 'date' => 'D', 'taxonomy-kw_grupa' => 'K' ) ) ) );

/* ---- komunikat po akcji (kod z adresu to klucz, nie tekst) ---- */
$kw_l_get = $_GET;
$_GET     = array(
	'kw_info' => 'wl-cennik',
	'kw_id'   => (string) $kw_l_id,
);
ob_start();
kw_uslugi_lista_komunikat();
$kw_l_kom = (string) ob_get_clean();
$sprawdz( 'komunikat: nazwa uslugi przez esc_html (znaczniki w tytule nieaktywne)', false !== strpos( $kw_l_kom, 'notice-success' ) && false !== strpos( $kw_l_kom, '&lt;i&gt;lista&lt;/i&gt;' ) && false === strpos( $kw_l_kom, '<i>' ), $kw_l_kom );
$_GET = array( 'kw_info' => '<script>alert(1)</script>' );
ob_start();
kw_uslugi_lista_komunikat();
$kw_l_kom = (string) ob_get_clean();
$_GET = array( 'kw_info' => 'wl-cennik' ); // bez ID uslugi: nie ma czego pokazac
ob_start();
kw_uslugi_lista_komunikat();
$kw_l_kom2 = (string) ob_get_clean();
$_GET      = $kw_l_get;
$sprawdz( 'komunikat: nieznany kod albo brak uslugi => nic nie drukuje', '' === $kw_l_kom && '' === $kw_l_kom2 );

/* ---- kolejnosc listy: tak jak na stronie ---- */
$kw_l_opubl = get_posts(
	array(
		'post_type'      => 'kw_usluga',
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'orderby'        => 'ID',
		'order'          => 'DESC',
	)
);
if ( count( $kw_l_opubl ) >= 2 ) {
	$kw_l_oczekiwane = array();
	foreach ( kw_uslugi_grupuj( kw_uslugi_pobierz( true ) ) as $kw_l_grupa ) {
		foreach ( $kw_l_grupa['uslugi'] as $kw_l_u ) {
			$kw_l_oczekiwane[] = $kw_l_u['id'];
		}
	}
	$kw_l_wynik = wp_list_pluck( kw_uslugi_lista_sortuj( $kw_l_opubl ), 'ID' );
	$sprawdz( 'lista: kolejnosc jak w cenniku (zakladki kategorii, w zakladce pole Kolejnosc)', $kw_l_oczekiwane === $kw_l_wynik, wp_json_encode( array( $kw_l_oczekiwane, $kw_l_wynik ) ) );
} else {
	echo "POMINIETO: kolejnosc listy (mniej niz dwie opublikowane uslugi)\n";
}

/* ---- kategorie ---- */
$kw_l_term = wp_insert_term( 'Samotest kategoria lista', 'kw_grupa' );
$kw_l_tid  = (int) $kw_l_term['term_id'];
$kw_l_slug = get_term( $kw_l_tid )->slug;
update_term_meta( $kw_l_tid, 'kw_kolejnosc', 777 );
$kw_l_tstan = static function () use ( $kw_l_tid ) {
	return wp_json_encode( array( get_term_meta( $kw_l_tid ), get_term( $kw_l_tid )->name ) );
};
$kw_l_tprzed = $kw_l_tstan();
$kw_l_ttoken = static function ( $stan ) use ( $kw_l_tid ) {
	return wp_create_nonce( 'kw_kat_arch_' . $kw_l_tid . '_' . $stan );
};

$sprawdz( 'kategoria: kw_ukryta domyslnie nie ustawiona (kw_uslugi_kategorie widzi false)', false === kw_uslugi_kategorie( true )[ $kw_l_slug ]['ukryta'] );
$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '1', '' );
$sprawdz( 'archiwum: brak tokenu => nic sie nie zmienia', 'token' === $k && $kw_l_tprzed === $kw_l_tstan(), $k );
$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '1', 'zlytoken' );
$sprawdz( 'archiwum: zly token => nic sie nie zmienia', 'token' === $k && $kw_l_tprzed === $kw_l_tstan(), $k );
$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '1', $kw_l_ttoken( '0' ) );
$sprawdz( 'archiwum: token od przeciwnej akcji nie pasuje', 'token' === $k && $kw_l_tprzed === $kw_l_tstan(), $k );
$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '2', wp_create_nonce( 'kw_kat_arch_' . $kw_l_tid . '_2' ) );
$sprawdz( 'archiwum: wartosc spoza 0 i 1 => nic sie nie zmienia', 'pole' === $k && $kw_l_tprzed === $kw_l_tstan(), $k );
$k = kw_uslugi_kat_archiwum_ustaw( 999999999, '1', wp_create_nonce( 'kw_kat_arch_999999999_1' ) );
$sprawdz( 'archiwum: nieistniejaca kategoria => odmowa', 'brak' === $k, $k );
$kw_l_bez = static function ( $wszystkie ) {
	$wszystkie['manage_categories'] = false;
	return $wszystkie;
};
add_filter( 'user_has_cap', $kw_l_bez );
$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '1', $kw_l_ttoken( '1' ) );
remove_filter( 'user_has_cap', $kw_l_bez );
$sprawdz( 'archiwum: poprawny token, ale brak manage_categories => nic sie nie zmienia', 'prawa' === $k && $kw_l_tprzed === $kw_l_tstan(), $k );
$kw_l_t1 = $kw_l_ttoken( '1' );
wp_set_current_user( 0 );
$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '1', $kw_l_t1 );
wp_set_current_user( $kw_l_admin );
$sprawdz( 'archiwum: gosc z tokenem administratora => nic sie nie zmienia', 'token' === $k && $kw_l_tprzed === $kw_l_tstan(), $k );

$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '1', $kw_l_t1 );
$sprawdz( 'archiwum: poprawne zadanie ustawia kw_ukryta, reszta (nazwa, kolejnosc) bez zmian; widac w kw_uslugi_kategorie( true )', 'ok' === $k && '1' === get_term_meta( $kw_l_tid, 'kw_ukryta', true ) && '777' === get_term_meta( $kw_l_tid, 'kw_kolejnosc', true ) && 'Samotest kategoria lista' === get_term( $kw_l_tid )->name && true === kw_uslugi_kategorie( true )[ $kw_l_slug ]['ukryta'], $k );
$kw_l_term_obj = get_term( $kw_l_tid );
$sprawdz( 'stan kategorii: Archiwum / Zakladka w cenniku / Bez zakladki / Pusta', 'Archiwum' === kw_uslugi_kat_stan( $kw_l_term_obj, array( $kw_l_slug ) )[1] );
$k = kw_uslugi_kat_archiwum_ustaw( $kw_l_tid, '0', $kw_l_ttoken( '0' ) );
$sprawdz( 'archiwum: przywrocenie zdejmuje kw_ukryta (znika z bazy), reszta bez zmian', 'ok' === $k && ! metadata_exists( 'term', $kw_l_tid, 'kw_ukryta' ) && $kw_l_tprzed === $kw_l_tstan() && false === kw_uslugi_kategorie( true )[ $kw_l_slug ]['ukryta'], $k );
$kw_l_term_obj = get_term( $kw_l_tid );
$kw_l_pelna    = clone $kw_l_term_obj;
$kw_l_pelna->count = 3;
$sprawdz(
	'stan kategorii: Pusta / Bez zakladki w cenniku / Zakladka w cenniku',
	'Pusta' === kw_uslugi_kat_stan( $kw_l_term_obj, array() )[1] && 'Bez zakładki w cenniku' === kw_uslugi_kat_stan( $kw_l_pelna, array() )[1] && 'Zakładka w cenniku' === kw_uslugi_kat_stan( $kw_l_pelna, array( $kw_l_slug ) )[1]
);

// Zapis z formularza kategorii (ten sam token co kolejnosc).
$kw_l_post = $_POST;
$_POST     = array(
	'kw_kolejnosc' => '888',
	'kw_ukryta'    => '1',
);
kw_uslugi_kategoria_zapisz( $kw_l_tid );
$sprawdz( 'zapis kategorii: bez tokenu nic sie nie zmienia', $kw_l_tprzed === $kw_l_tstan() );
$_POST['kw_kategoria_nonce'] = 'zlytoken';
kw_uslugi_kategoria_zapisz( $kw_l_tid );
$sprawdz( 'zapis kategorii: zly token => nic sie nie zmienia', $kw_l_tprzed === $kw_l_tstan() );
$_POST['kw_kategoria_nonce'] = wp_create_nonce( 'kw_kategoria_zapis' );
add_filter( 'user_has_cap', $kw_l_bez );
kw_uslugi_kategoria_zapisz( $kw_l_tid );
remove_filter( 'user_has_cap', $kw_l_bez );
$sprawdz( 'zapis kategorii: poprawny token, ale brak manage_categories => nic sie nie zmienia', $kw_l_tprzed === $kw_l_tstan() );
kw_uslugi_kategoria_zapisz( $kw_l_tid );
$sprawdz( 'zapis kategorii: z tokenem zapisuje kolejnosc i kw_ukryta (widoczna w kw_uslugi_kategorie)', '888' === get_term_meta( $kw_l_tid, 'kw_kolejnosc', true ) && '1' === get_term_meta( $kw_l_tid, 'kw_ukryta', true ) && true === kw_uslugi_kategorie( true )[ $kw_l_slug ]['ukryta'] && 888 === kw_uslugi_kategorie( true )[ $kw_l_slug ]['kolejnosc'] );
unset( $_POST['kw_ukryta'] );
kw_uslugi_kategoria_zapisz( $kw_l_tid );
$sprawdz( 'zapis kategorii: odznaczone pole wyboru zdejmuje archiwum', ! metadata_exists( 'term', $kw_l_tid, 'kw_ukryta' ) && '888' === get_term_meta( $kw_l_tid, 'kw_kolejnosc', true ) );
$_POST['kw_naglowek'] = '  Nagłówek <b>testowy</b> kategorii  ';
kw_uslugi_kategoria_zapisz( $kw_l_tid );
$sprawdz( 'zapis kategorii: naglowek dla wyszukiwarek bez znacznikow, widoczny w kw_uslugi_kategorie', 'Nagłówek testowy kategorii' === get_term_meta( $kw_l_tid, 'kw_naglowek', true ) && 'Nagłówek testowy kategorii' === kw_uslugi_kategorie( true )[ $kw_l_slug ]['naglowek'], wp_json_encode( get_term_meta( $kw_l_tid, 'kw_naglowek', true ) ) );
$_POST['kw_naglowek'] = '';
kw_uslugi_kategoria_zapisz( $kw_l_tid );
$sprawdz( 'zapis kategorii: puste pole naglowka usuwa go z bazy (wraca nazwa kategorii)', ! metadata_exists( 'term', $kw_l_tid, 'kw_naglowek' ) && '' === kw_uslugi_kategorie( true )[ $kw_l_slug ]['naglowek'] );
$_POST = $kw_l_post;

$sprawdz( 'kolejnosc nowej kategorii: nastepna dziesiatka po najwiekszej (888 => 890)', 890 === kw_uslugi_kat_nastepny_numer(), (string) kw_uslugi_kat_nastepny_numer() );
ob_start();
kw_uslugi_kategoria_pole( 'kw_grupa' );
$kw_l_dodaj = (string) ob_get_clean();
ob_start();
kw_uslugi_kategoria_pole( get_term( $kw_l_tid ) );
$kw_l_edytuj = (string) ob_get_clean();
$sprawdz( 'formularz dodawania: podpowiedziany numer, pole archiwum, token', false !== strpos( $kw_l_dodaj, 'value="890"' ) && false !== strpos( $kw_l_dodaj, 'name="kw_ukryta"' ) && false !== strpos( $kw_l_dodaj, 'name="kw_kategoria_nonce"' ) );
$sprawdz( 'formularz edycji: numer z bazy, adres zakladki #cennik-<slug>, token', false !== strpos( $kw_l_edytuj, 'value="888"' ) && false !== strpos( $kw_l_edytuj, '#cennik-' . $kw_l_slug ) && false !== strpos( $kw_l_edytuj, 'name="kw_kategoria_nonce"' ) );

$kw_l_wiersz = kw_uslugi_kat_akcje(
	array(
		'edit'                 => '<a>e</a>',
		'inline hide-if-no-js' => '<button>q</button>',
		'delete'               => '<a>d</a>',
		'view'                 => '<a>v</a>',
	),
	get_term( $kw_l_tid )
);
$sprawdz( 'akcje wiersza kategorii: Edytuj i Do archiwum (z tokenem), bez szybkiej edycji i trwalego usuniecia', array( 'edit', 'kw_archiwum' ) === array_keys( $kw_l_wiersz ) && false !== strpos( $kw_l_wiersz['kw_archiwum'], 'action=kw_uslugi_kat_archiwum' ) && false !== strpos( $kw_l_wiersz['kw_archiwum'], '_wpnonce=' ) && false !== strpos( $kw_l_wiersz['kw_archiwum'], 'Do archiwum' ) );
$sprawdz( 'kolumny kategorii: nazwa, liczba uslug, kolejnosc zakladki, stan; bez kolumny "Kotwica zakladki"', array( 'name', 'posts', 'kw_kolejnosc', 'kw_stan' ) === array_keys( kw_uslugi_kategorie_kolumny( array( 'cb' => 'x', 'name' => 'N', 'description' => 'O', 'slug' => 'S', 'posts' => 'L' ) ) ) && array() === kw_uslugi_kat_masowe() );

// Trwale usuniecie z panelu jest wylaczone dla kategorii uslug (nie wraca z kosza), dla innych taksonomii nie.
$kw_l_tag = wp_insert_term( 'samotest-tag-lista', 'post_tag' );
$sprawdz( 'trwale usuwanie kategorii uslug z panelu wylaczone (delete_term), innych taksonomii nie', ! user_can( $kw_l_admin, 'delete_term', $kw_l_tid ) && ! is_wp_error( $kw_l_tag ) && user_can( $kw_l_admin, 'delete_term', $kw_l_tag['term_id'] ) );
if ( ! is_wp_error( $kw_l_tag ) ) {
	wp_delete_term( $kw_l_tag['term_id'], 'post_tag' );
}

/* ---- porzadek ekranow: znikaja tylko znane reklamy (czarna lista), nieznane ostrzezenia zostaja ---- */
$sprawdz(
	'czarna lista: reklamy znanych wtyczek (Elementor, Essential Addons i Blocks, WPForms, Polylang, Inyfinn Cursor Bridge) i baner "Instalacja Wordfence jest niekompletna" do zdjecia',
	kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/elementor/core/admin/notices.php', 'Elementor\\Core\\Admin\\Notices::admin_notices' )
	&& kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/elementor-pro/core/x.php', 'closure' )
	&& kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/essential-addons-for-elementor-lite/includes/Classes/Notice.php', 'Notice::display' )
	&& kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/essential-blocks/includes/Admin/Notice.php', 'closure' )
	&& kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/wpforms-lite/src/Admin/Notice.php', 'closure' )
	&& kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/polylang/admin/admin-notices.php', 'PLL_Admin_Notices::display_notices' )
	&& kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/inyfinn-cursor-bridge-mcp/admin.php', 'ic_bridge_notice' )
	&& kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/wordfence/lib/wordfenceClass.php', 'wordfence::showOnboardingBanner' )
);
$sprawdz(
	'czarna lista: funkcje rdzenia, ostrzezenia Wordfence o zaporze i skanerze, nieznane wtyczki, nasze i brak zrodla ZOSTAJA',
	! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-admin/includes/update.php', 'update_nag' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-admin/includes/update.php', 'maintenance_nag' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-includes/error-protection.php', 'wp_recovery_mode_nag' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-admin/includes/admin-filters.php', 'paused_plugins_notice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-admin/includes/misc.php', 'paused_themes_notice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-admin/includes/misc.php', 'deactivated_plugins_notice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-admin/includes/misc.php', 'default_password_nag' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-admin/includes/ms.php', 'site_admin_notice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/wordfence/lib/wordfenceClass.php', 'wordfence::wafReadOnlyNotice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/wordfence/lib/wordfenceClass.php', 'wordfence::wafConfigInaccessibleNotice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/wordfence/lib/wordfenceClass.php', 'wordfence::misconfiguredHowGetIPsNotice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/wordfence/lib/wordfenceClass.php', 'wordfence::wafStorageEngineFallbackNotice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/jakas-nowa-wtyczka/x.php', 'jakas_nowa_wtyczka_ostrzezenie' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( WP_PLUGIN_DIR . '/elementor/x.php', 'kw_uslugi_ostrzezenie' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( '', 'wp_admin_notice' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( '', '' )
	&& ! kw_uslugi_lista_baner_do_zdjecia( ABSPATH . 'wp-includes/formatting.php', 'wordfence::showOnboardingBanner_inna' )
);
if ( ! function_exists( 'kw_samotest_nieznane_ostrzezenie' ) ) {
	/**
	 * Nieznane ostrzezenie (sztucznie dopiete w samtescie): ma ZOSTAC.
	 */
	function kw_samotest_nieznane_ostrzezenie() {
		echo 'NIEZNANE OSTRZEZENIE';
	}
}
if ( ! class_exists( 'Kw_Samotest_Baner' ) ) {
	/**
	 * Klasa testowa: metoda jako wywolanie typu [obiekt, metoda].
	 */
	class Kw_Samotest_Baner {
		/**
		 * Komunikat testowy.
		 */
		public function pokaz() {
			echo 'BANER Z KLASY';
		}
	}
}
$kw_l_zrodlo = kw_uslugi_lista_baner_zrodlo( 'kw_samotest_nieznane_ostrzezenie' );
$kw_l_zrodlo2 = kw_uslugi_lista_baner_zrodlo( array( new Kw_Samotest_Baner(), 'pokaz' ) );
$kw_l_zrodlo3 = kw_uslugi_lista_baner_zrodlo( 'wp_admin_notice' );
$sprawdz(
	'zrodlo komunikatu przez odbicie: funkcja z pliku testu, metoda obiektu "Klasa::metoda", funkcja rdzenia z wp-includes',
	wp_normalize_path( __FILE__ ) === wp_normalize_path( $kw_l_zrodlo[0] ) && 'kw_samotest_nieznane_ostrzezenie' === $kw_l_zrodlo[1] && wp_normalize_path( __FILE__ ) === wp_normalize_path( $kw_l_zrodlo2[0] ) && 'Kw_Samotest_Baner::pokaz' === $kw_l_zrodlo2[1] && 0 === strpos( wp_normalize_path( $kw_l_zrodlo3[0] ), wp_normalize_path( ABSPATH . 'wp-includes/' ) ),
	wp_json_encode( array( $kw_l_zrodlo, $kw_l_zrodlo2, $kw_l_zrodlo3 ) )
);

if ( ! class_exists( 'WP_Screen' ) && is_readable( ABSPATH . 'wp-admin/includes/class-wp-screen.php' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
if ( ! function_exists( 'set_current_screen' ) && is_readable( ABSPATH . 'wp-admin/includes/screen.php' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
if ( function_exists( 'set_current_screen' ) && class_exists( 'WP_Screen' ) ) {
	$kw_l_zapas = array();
	foreach ( array( 'admin_notices', 'all_admin_notices' ) as $kw_l_haczyk ) {
		$kw_l_zapas[ $kw_l_haczyk ] = isset( $GLOBALS['wp_filter'][ $kw_l_haczyk ] ) ? clone $GLOBALS['wp_filter'][ $kw_l_haczyk ] : null;
	}
	$kw_l_ekran_przed = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
	$kw_l_reklama     = static function () {
		echo 'REKLAMA ELEMENTORA';
	};
	// Zrodlo reklamy podstawione filtrem: tak, jakby funkcja lezala w wp-content/plugins/elementor.
	$kw_l_zrodlo_filtr = static function ( $zrodlo, $wywolanie ) use ( $kw_l_reklama ) {
		return $wywolanie === $kw_l_reklama ? array( WP_PLUGIN_DIR . '/elementor/core/admin/notices.php', 'Elementor\\Core\\Admin\\Notices::admin_notices' ) : $zrodlo;
	};
	add_filter( 'kw_uslugi_lista_baner_zrodlo', $kw_l_zrodlo_filtr, 10, 2 );
	$kw_l_obj          = new Kw_Samotest_Baner();
	$kw_l_dodaj_banery = static function () use ( $kw_l_reklama, $kw_l_obj ) {
		add_action( 'admin_notices', $kw_l_reklama );
		add_action( 'all_admin_notices', $kw_l_reklama, 5 );
		add_action( 'admin_notices', 'wordfence::showOnboardingBanner', 7 ); // baner instalacji Wordfence: "klasa::metoda"
		add_action( 'admin_notices', 'wordfence::wafReadOnlyNotice', 8 );    // ostrzezenie o zaporze: zostaje
		add_action( 'admin_notices', 'update_nag', 3 );                      // rdzen: zostaje
		add_action( 'admin_notices', 'wp_admin_notice' );                    // funkcja z wp-includes: zostaje
		add_action( 'admin_notices', 'kw_samotest_nieznane_ostrzezenie' );   // nieznane ostrzezenie: zostaje
		add_action( 'admin_notices', array( $kw_l_obj, 'pokaz' ) ); // nieznana klasa: zostaje
		add_action( 'admin_notices', 'kw_uslugi_ostrzezenie' );
	};
	$kw_l_decyzje = array();
	$kw_l_podsluch = static function ( $decyzje ) use ( &$kw_l_decyzje ) {
		$kw_l_decyzje = $decyzje;
	};
	add_action( 'kw_uslugi_porzadek_decyzje', $kw_l_podsluch );
	$kw_l_zostaja = static function () {
		return false !== has_action( 'admin_notices', 'wordfence::wafReadOnlyNotice' ) && false !== has_action( 'admin_notices', 'update_nag' ) && false !== has_action( 'admin_notices', 'wp_admin_notice' ) && false !== has_action( 'admin_notices', 'kw_samotest_nieznane_ostrzezenie' ) && false !== has_action( 'admin_notices', 'kw_uslugi_ostrzezenie' );
	};

	set_current_screen( 'dashboard' );
	$kw_l_dodaj_banery();
	kw_uslugi_porzadek_ekranu();
	$sprawdz( 'porzadek ekranu: na Kokpicie nic nie jest ruszane (reklama, baner Wordfence i ostrzezenia zostaja)', false !== has_action( 'admin_notices', $kw_l_reklama ) && false !== has_action( 'all_admin_notices', $kw_l_reklama ) && false !== has_action( 'admin_notices', 'wordfence::showOnboardingBanner' ) && $kw_l_zostaja() );

	foreach ( array( 'admin_notices', 'all_admin_notices' ) as $kw_l_haczyk ) {
		unset( $GLOBALS['wp_filter'][ $kw_l_haczyk ] );
	}
	foreach ( array( 'edit-kw_usluga', 'edit-kw_wiadomosc', 'edit-kw_grupa' ) as $kw_l_ekran_id ) {
		$kw_l_dodaj_banery();
		set_current_screen( $kw_l_ekran_id );
		$kw_l_ekran = get_current_screen();
		$kw_l_ekran->add_help_tab(
			array(
				'id'      => 'kw-obca-pomoc',
				'title'   => 'Pomoc',
				'content' => 'x',
			)
		);
		kw_uslugi_porzadek_ekranu();
		$kw_l_wyniki = array_column( $kw_l_decyzje, 'wynik', 'nazwa' );
		$sprawdz(
			'porzadek ekranu (' . $kw_l_ekran_id . '): znika reklama znanej wtyczki i baner instalacji Wordfence; zostaja ostrzezenie Wordfence o zaporze, rdzen, nieznane ostrzezenie i nasze; pomoc i opcje ekranu schowane',
			false === has_action( 'admin_notices', $kw_l_reklama ) && false === has_action( 'all_admin_notices', $kw_l_reklama ) && false === has_action( 'admin_notices', 'wordfence::showOnboardingBanner' ) && $kw_l_zostaja() && false !== has_action( 'admin_notices', array( $kw_l_obj, 'pokaz' ) ) && false !== has_action( 'admin_notices', 'kw_uslugi_lista_komunikat' ) && array() === $kw_l_ekran->get_help_tabs() && false === $kw_l_ekran->show_screen_options() && 'zdjeta' === ( $kw_l_wyniki['wordfence::showOnboardingBanner'] ?? '' ) && 'zostaje' === ( $kw_l_wyniki['update_nag'] ?? '' ),
			wp_json_encode( $kw_l_wyniki )
		);
		remove_filter( 'screen_options_show_screen', '__return_false' );
		remove_action( 'admin_notices', 'kw_uslugi_lista_komunikat' );
		foreach ( array( 'admin_notices', 'all_admin_notices' ) as $kw_l_haczyk ) {
			unset( $GLOBALS['wp_filter'][ $kw_l_haczyk ] );
		}
	}

	// Ekran kategorii innej taksonomii z adresem post_type=kw_usluga (WP_Screen bierze post_type z zadania) nie jest ekranem uslug.
	$kw_l_req = $_REQUEST;
	$_REQUEST['post_type'] = 'kw_usluga';
	set_current_screen( 'edit-category' );
	$kw_l_obca_tax = kw_uslugi_lista_ekran();
	$kw_l_obca_post = get_current_screen()->post_type;
	$kw_l_dodaj_banery();
	kw_uslugi_porzadek_ekranu();
	$kw_l_bez_zmian = false !== has_action( 'admin_notices', $kw_l_reklama );
	foreach ( array( 'admin_notices', 'all_admin_notices' ) as $kw_l_haczyk ) {
		unset( $GLOBALS['wp_filter'][ $kw_l_haczyk ] );
	}
	set_current_screen( 'edit-kw_grupa' );
	$kw_l_nasza_tax = kw_uslugi_lista_ekran();
	$_REQUEST       = $kw_l_req;
	$sprawdz( 'ekran kategorii: edit-tags.php?taxonomy=category&post_type=kw_usluga NIE jest ekranem uslug (decyduje taksonomia kw_grupa), a edit-kw_grupa jest', false === $kw_l_obca_tax && true === $kw_l_nasza_tax && $kw_l_bez_zmian, wp_json_encode( array( $kw_l_obca_post, $kw_l_obca_tax, $kw_l_nasza_tax, $kw_l_bez_zmian ) ) );
	set_current_screen( 'settings_page_kw-uslugi' );
	$sprawdz( 'ekran Ustawienia, Uslugi jest ekranem uslug (porzadek banerow)', true === kw_uslugi_lista_ekran() );
	$kw_l_iwc = array();
	foreach ( array( 'toplevel_page_iwc-poprawki' => true, 'poprawki_page_iwc-ustawienia' => true, 'poprawki-3_page_iwc-ustawienia' => true, 'toplevel_page_iwc-poprawki-inne' => false, 'settings_page_inna-wtyczka' => false ) as $kw_l_ekran_id => $kw_l_ekran_ma ) {
		set_current_screen( $kw_l_ekran_id );
		if ( $kw_l_ekran_ma !== kw_uslugi_lista_ekran() ) {
			$kw_l_iwc[] = $kw_l_ekran_id;
		}
	}
	$sprawdz( 'ekrany osobnej wtyczki "Poprawki" (lista i ustawienia, takze z licznikiem w nazwie menu) maja ten sam porzadek banerow; podobne nazwy innych wtyczek nie', array() === $kw_l_iwc, wp_json_encode( $kw_l_iwc ) );
	set_current_screen( 'settings_page_kw-uslugi' ); // dalsze testy zakladaja ekran ustawiony wyzej

	remove_filter( 'kw_uslugi_lista_baner_zrodlo', $kw_l_zrodlo_filtr, 10 );
	remove_action( 'kw_uslugi_porzadek_decyzje', $kw_l_podsluch );

	/* ---- glowne zapytanie listy uslug: domyslnie opublikowane, szkice w osobnym widoku ---- */
	set_current_screen( 'edit-kw_usluga' );
	$kw_l_get_przed  = $_GET;
	ob_start();
	kw_uslugi_lista_formularz();
	$kw_l_form = (string) ob_get_clean();
	$sprawdz( 'wspolny formularz zadan listy: POST na admin-post.php, ukryty, z polem action i adresem powrotu (poza tabela, przyciski odwoluja sie atrybutem form)', false !== strpos( $kw_l_form, 'id="kw-przelacznik-formularz"' ) && false !== strpos( $kw_l_form, 'method="post"' ) && false !== strpos( $kw_l_form, 'admin-post.php' ) && false !== strpos( $kw_l_form, 'name="action" value="kw_uslugi_lista_akcja"' ) && false !== strpos( $kw_l_form, ' hidden' ), $kw_l_form );
	set_current_screen( 'dashboard' );
	ob_start();
	kw_uslugi_lista_formularz();
	$sprawdz( 'wspolny formularz zadan listy: tylko na liscie uslug', '' === (string) ob_get_clean() );
	set_current_screen( 'edit-kw_usluga' );
	$kw_l_pagenow    = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
	$kw_l_glowne     = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
	$GLOBALS['pagenow'] = 'edit.php';
	$kw_l_zapytanie  = static function ( array $get ) use ( &$kw_l_glowne ) {
		$_GET                     = $get;
		$q                        = new WP_Query();
		$GLOBALS['wp_the_query']  = $q;
		$q->query(
			array(
				'post_type'      => 'kw_usluga',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				's'              => isset( $get['s'] ) ? $get['s'] : '',
			) + ( isset( $get['post_status'] ) ? array( 'post_status' => $get['post_status'] ) : array() )
		);
		return $q->posts;
	};
	$kw_l_domyslne = $kw_l_zapytanie( array() );
	$kw_l_szkice   = $kw_l_zapytanie( array( 'post_status' => 'draft' ) );
	$kw_l_szuk     = $kw_l_zapytanie(
		array(
			's'           => 'Samotest',
			'post_status' => 'all',
		)
	);
	$kw_l_liczby   = wp_count_posts( 'kw_usluga', 'readable' );
	$sprawdz( 'lista: widok domyslny bez szkicow (nasz szkic testowy go nie zaklada), liczba = opublikowane', ! in_array( $kw_l_id, $kw_l_domyslne, true ) && count( $kw_l_domyslne ) === (int) $kw_l_liczby->publish + (int) $kw_l_liczby->future + (int) $kw_l_liczby->pending + (int) $kw_l_liczby->private, count( $kw_l_domyslne ) . ' / ' . $kw_l_liczby->publish );
	$sprawdz( 'lista: widok "Szkice i archiwum" pokazuje szkic, liczba = szkice', in_array( $kw_l_id, $kw_l_szkice, true ) && count( $kw_l_szkice ) === (int) $kw_l_liczby->draft );
	$sprawdz( 'lista: wyszukiwanie (post_status=all z formularza) przeszukuje tez szkice', in_array( $kw_l_id, $kw_l_szuk, true ) );
	ob_start();
	$kw_l_widoki = kw_uslugi_lista_widoki( array( 'trash' => '<a>Kosz</a>' ) );
	$kw_l_wstep  = (string) ob_get_clean();
	$sprawdz( 'widoki: Opublikowane, Szkice i archiwum, Kosz (z rdzenia)', array( 'kw-opublikowane', 'kw-szkice', 'trash' ) === array_keys( $kw_l_widoki ) && false !== strpos( $kw_l_wstep, 'kw-lista-wstep' ) );
	$GLOBALS['wp_the_query'] = $kw_l_glowne;
	$_GET                    = $kw_l_get_przed;
	$GLOBALS['pagenow']      = $kw_l_pagenow;

	// Przywrocenie stanu procesu.
	$GLOBALS['current_screen'] = $kw_l_ekran_przed;
	foreach ( $kw_l_zapas as $kw_l_haczyk => $kw_l_stary ) {
		if ( null === $kw_l_stary ) {
			unset( $GLOBALS['wp_filter'][ $kw_l_haczyk ] );
		} else {
			$GLOBALS['wp_filter'][ $kw_l_haczyk ] = $kw_l_stary;
		}
	}
} else {
	echo "POMINIETO: porzadek ekranow (brak WP_Screen w tym uruchomieniu)\n";
}

/* ---- kafle kategorii nad lista (zakladki cennika) ---- */
$sprawdz(
	'kafle: odmiana "usluga/uslugi/uslug" (1, 2, 4, 5, 12, 14, 22, 112)',
	array( '1 usługa', '2 usługi', '4 usługi', '5 usług', '12 usług', '14 usług', '22 usługi', '112 usług', '0 usług' ) === array_map( 'kw_uslugi_lista_ile', array( 1, 2, 4, 5, 12, 14, 22, 112, 0 ) )
);
$kw_l_baza = count( kw_uslugi_pobierz( true ) );
$kw_l_ka   = wp_insert_term( 'Samotest kafel A', 'kw_grupa' );
$kw_l_kb   = wp_insert_term( 'Samotest kafel B', 'kw_grupa' );
$kw_l_sa   = get_term( $kw_l_ka['term_id'] )->slug;
$kw_l_sb   = get_term( $kw_l_kb['term_id'] )->slug;
$kw_l_u    = array();
$kw_l_kc   = wp_insert_term( 'Samotest kafel C', 'kw_grupa' );
$kw_l_sc   = get_term( $kw_l_kc['term_id'] )->slug;
foreach ( array( 'A1' => $kw_l_sa, 'A2' => $kw_l_sa, 'B1' => $kw_l_sb, 'C1' => $kw_l_sc, 'C2' => $kw_l_sc ) as $kw_l_n => $kw_l_s ) {
	$kw_l_pid = wp_insert_post(
		array(
			'post_type'   => 'kw_usluga',
			'post_status' => 'draft',
			'post_title'  => 'Samotest kafel ' . $kw_l_n,
		)
	);
	wp_set_object_terms( $kw_l_pid, array( $kw_l_s ), 'kw_grupa' );
	update_post_meta( $kw_l_pid, 'kw_w_cenniku', '1' );
	$kw_l_u[ $kw_l_n ] = $kw_l_pid;
}
update_post_meta( $kw_l_u['A1'], 'kw_cena_typ', 'stala' );
update_post_meta( $kw_l_u['A1'], 'kw_cena_min', '200' );
update_post_meta( $kw_l_u['A1'], 'kw_cena_jednostka', 'miesiac' ); // miesieczna 200 zl wygrywa z tanszym progiem "za dokument" (120)
update_post_meta( $kw_l_u['A2'], 'kw_cena_typ', 'od' ); // bez kwoty: liczy sie najnizszy prog (120)
update_post_meta( $kw_l_u['A2'], 'kw_cena_jednostka', 'dokument' );
update_post_meta( $kw_l_u['C1'], 'kw_cena_typ', 'stala' ); // kategoria bez miesiecznych: najnizsza ze wszystkich z jednostka
update_post_meta( $kw_l_u['C1'], 'kw_cena_min', '50' );
update_post_meta( $kw_l_u['C1'], 'kw_cena_jednostka', 'dokument' );
update_post_meta( $kw_l_u['C2'], 'kw_cena_typ', 'stala' );
update_post_meta( $kw_l_u['C2'], 'kw_cena_min', '30' );
update_post_meta( $kw_l_u['C2'], 'kw_cena_jednostka', 'deklaracja' );
update_post_meta( $kw_l_u['A2'], 'kw_cena_min', '' );
update_post_meta(
	$kw_l_u['A2'],
	'kw_progi',
	array(
		array(
			'etykieta' => '1-10',
			'cena'     => '300',
			'od'       => 0,
		),
		array(
			'etykieta' => '11-20',
			'cena'     => '120',
			'od'       => 0,
		),
	)
);
update_post_meta( $kw_l_u['B1'], 'kw_cena_typ', 'indywidualna' );
$kw_l_dopisz = static function ( $uslugi ) use ( $kw_l_u ) {
	foreach ( $kw_l_u as $pid ) {
		$uslugi[] = kw_uslugi_z_posta( get_post( $pid ) );
	}
	return $uslugi;
};
add_filter( 'kw_uslugi_lista', $kw_l_dopisz );
$kw_l_wszystkie = kw_uslugi_pobierz( true );
$kw_l_kafle     = kw_uslugi_lista_kafle_dane( $kw_l_wszystkie, kw_uslugi_kategorie( true ), kw_uslugi_kat_zakladki( $kw_l_wszystkie ) );
remove_filter( 'kw_uslugi_lista', $kw_l_dopisz );
$kw_l_po_slugu = array();
foreach ( $kw_l_kafle as $kw_l_k ) {
	$kw_l_po_slugu[ $kw_l_k['slug'] ] = $kw_l_k;
}
$nb = "\u{00A0}";
$sprawdz( 'kafle: pierwszy to "Wszystkie" z liczba wszystkich opublikowanych uslug (+5 dopisanych szkicow), bez ceny', 'Wszystkie' === $kw_l_kafle[0]['nazwa'] && '' === $kw_l_kafle[0]['slug'] && $kw_l_baza + 5 === $kw_l_kafle[0]['ile'] && '' === $kw_l_kafle[0]['cena'], wp_json_encode( $kw_l_kafle[0] ) );
$sprawdz( 'kafle: "Wszystkie" w HTML nie ma wiersza ceny', 1 === preg_match( '~<span class="kw-kafel__nazwa">Wszystkie</span><span class="kw-kafel__ile">[^<]*</span>(<span class="kw-kafel__wybrana">wybrana</span>)?</a>~', kw_uslugi_lista_kafle_html( $kw_l_kafle, '' ) ) );
$sprawdz( 'kafle: kategoria z usluga miesieczna (200 zł) i tansza "za dokument" (od 120 zł): 2 uslugi, cena miesieczna "od 200 zł / mies."', isset( $kw_l_po_slugu[ $kw_l_sa ] ) && 2 === $kw_l_po_slugu[ $kw_l_sa ]['ile'] && 'od' . $nb . '200' . $nb . 'zł / mies.' === $kw_l_po_slugu[ $kw_l_sa ]['cena'], wp_json_encode( $kw_l_po_slugu[ $kw_l_sa ] ?? null ) );
$sprawdz( 'kafle: kategoria bez miesiecznych: najnizsza ze wszystkich z jednostka ("od 30 zł / deklaracja")', isset( $kw_l_po_slugu[ $kw_l_sc ] ) && 2 === $kw_l_po_slugu[ $kw_l_sc ]['ile'] && 'od' . $nb . '30' . $nb . 'zł / deklaracja' === $kw_l_po_slugu[ $kw_l_sc ]['cena'], wp_json_encode( $kw_l_po_slugu[ $kw_l_sc ] ?? null ) );
$sprawdz( 'kafle: kategoria bez kwot => "wycena indywidualna", 1 usluga', isset( $kw_l_po_slugu[ $kw_l_sb ] ) && 1 === $kw_l_po_slugu[ $kw_l_sb ]['ile'] && 'wycena indywidualna' === $kw_l_po_slugu[ $kw_l_sb ]['cena'], wp_json_encode( $kw_l_po_slugu[ $kw_l_sb ] ?? null ) );
$kw_l_slugi = array_column( $kw_l_kafle, 'slug' );
$sprawdz( 'kafle: kolejnosc zakladek (A przed B), kategorie bez zakladki w cenniku (archiwalne, puste) bez kafla', array_search( $kw_l_sa, $kw_l_slugi, true ) < array_search( $kw_l_sb, $kw_l_slugi, true ) && count( $kw_l_kafle ) === 1 + count( array_intersect( array_keys( kw_uslugi_kategorie() ), kw_uslugi_kat_zakladki( $kw_l_wszystkie ) ) ), implode( ',', $kw_l_slugi ) );
$kw_l_html = kw_uslugi_lista_kafle_html( $kw_l_kafle, $kw_l_sa );
$sprawdz( 'kafle: HTML ma jeden aria-current="true" (wybrana kategoria), odnosniki z kw_grupa, brak parametru dla "Wszystkie"', 1 === substr_count( $kw_l_html, 'aria-current="true"' ) && false !== strpos( $kw_l_html, 'kw_grupa=' . $kw_l_sa . '"' ) && 1 === preg_match( '~href="[^"]*edit\.php\?post_type=kw_usluga"~', $kw_l_html ), $kw_l_html );
$kw_l_html0 = kw_uslugi_lista_kafle_html( $kw_l_kafle, '' );
$sprawdz( 'kafle: bez wybranej kategorii aria-current="true" ma "Wszystkie"', 1 === substr_count( $kw_l_html0, 'aria-current="true"' ) && 1 === preg_match( '~aria-current="true"><span class="kw-kafel__nazwa">Wszystkie~', $kw_l_html0 ) );
$kw_l_obce = kw_uslugi_lista_kafle_html(
	array(
		array(
			'slug'  => 'x"><script>',
			'nazwa' => '<script>alert(1)</script>',
			'ile'   => 2,
			'cena'  => '<b>cena</b>',
		),
	),
	''
);
$sprawdz( 'kafle: nazwa, cena i adres przez esc_html i esc_url (znaczniki nieaktywne)', false === strpos( $kw_l_obce, '<script>' ) && false === strpos( $kw_l_obce, '<b>' ), $kw_l_obce );
foreach ( $kw_l_u as $kw_l_pid ) {
	wp_delete_post( $kw_l_pid, true );
}
wp_delete_term( $kw_l_ka['term_id'], 'kw_grupa' );
wp_delete_term( $kw_l_kb['term_id'], 'kw_grupa' );
wp_delete_term( $kw_l_kc['term_id'], 'kw_grupa' );
kw_uslugi_kategorie( true );
kw_uslugi_pobierz( true );

/* ---- komunikat z "Cofnij" (to samo zabezpieczone zadanie w druga strone) ---- */
$kw_l_get = $_GET;
$_GET     = array(
	'kw_info' => 'wl-cennik',
	'kw_id'   => (string) $kw_l_id,
);
ob_start();
kw_uslugi_lista_komunikat();
$kw_l_kom = (string) ob_get_clean();
$_GET     = $kw_l_get;
$kw_l_cofnij_ok = 1 === preg_match( '~name="kw_przelacz" value="' . $kw_l_id . '\|kw_w_cenniku\|0\|([a-f0-9]+)" class="button-link kw-cofnij">Cofnij</button>~', $kw_l_kom, $kw_l_m ) && false !== strpos( $kw_l_kom, 'form="kw-przelacznik-formularz"' );
$sprawdz( 'komunikat po wlaczeniu ma przycisk "Cofnij" z zadaniem odwrotnym (stan 0) i tokenem, wysylany wspolnym formularzem', $kw_l_cofnij_ok, $kw_l_kom );
update_post_meta( $kw_l_id, 'kw_w_cenniku', '1' );
$w = kw_uslugi_lista_przelacz( $kw_l_id, 'kw_w_cenniku', '0', $kw_l_cofnij_ok ? $kw_l_m[1] : '' );
$sprawdz( 'Cofnij: token z przycisku przywraca poprzedni stan (kw_w_cenniku 1 -> 0), tym samym mechanizmem', 'ok' === $w['kod'] && '0' === get_post_meta( $kw_l_id, 'kw_w_cenniku', true ), wp_json_encode( $w ) );
update_post_meta( $kw_l_id, 'kw_w_cenniku', '1' );

/* ---- strzalki kolejnosci: przesuwanie uslugi w zakladce (na wlasnych szkicach, filtr stanow) ---- */
$kw_l_kt   = wp_insert_term( 'Samotest zakladka przesuwania', 'kw_grupa' );
$kw_l_ktid = (int) $kw_l_kt['term_id'];
$kw_l_ktsl = get_term( $kw_l_ktid )->slug;
$kw_l_pp   = array();
foreach ( array( 1, 2, 3 ) as $kw_l_n ) {
	$kw_l_pp[ $kw_l_n ] = wp_insert_post(
		array(
			'post_type'   => 'kw_usluga',
			'post_status' => 'draft',
			'post_title'  => 'Samotest przesun ' . $kw_l_n,
			'menu_order'  => 10 * $kw_l_n,
		)
	);
	wp_set_object_terms( $kw_l_pp[ $kw_l_n ], array( $kw_l_ktsl ), 'kw_grupa' );
}
$kw_l_stany = static function () {
	return array( 'draft' );
};
add_filter( 'kw_uslugi_lista_statusy_kolejnosci', $kw_l_stany );
kw_uslugi_kategorie( true );
kw_uslugi_lista_zakladki( true );
$kw_l_kol = static function () use ( $kw_l_pp ) {
	clean_post_cache( $kw_l_pp[1] );
	clean_post_cache( $kw_l_pp[2] );
	clean_post_cache( $kw_l_pp[3] );
	return array( (int) get_post_field( 'menu_order', $kw_l_pp[1] ), (int) get_post_field( 'menu_order', $kw_l_pp[2] ), (int) get_post_field( 'menu_order', $kw_l_pp[3] ) );
};
$kw_l_tok = static function ( $id, $kier ) {
	return wp_create_nonce( 'kw_przesun_' . $id . '_' . $kier );
};
$kw_l_tytuly = static function () use ( $kw_l_pp ) {
	return array( get_the_title( $kw_l_pp[1] ), get_the_title( $kw_l_pp[2] ), get_the_title( $kw_l_pp[3] ), get_post_status( $kw_l_pp[2] ) );
};
$kw_l_tyt0 = $kw_l_tytuly();

$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', '' );
$sprawdz( 'przesuwanie: brak tokenu => nic sie nie zmienia', 'token' === $w['kod'] && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( $w ) );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_tok( $kw_l_pp[2], 'dol' ) );
$sprawdz( 'przesuwanie: token na inny kierunek nie pasuje', 'token' === $w['kod'] && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( $w ) );
wp_set_current_user( 0 );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_tok( $kw_l_pp[2], 'gora' ) );
wp_set_current_user( $kw_l_admin );
$sprawdz( 'przesuwanie: gosc => nic sie nie zmienia', in_array( $w['kod'], array( 'token', 'prawa' ), true ) && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( $w ) );
$kw_l_odmow2 = static function ( $uprawnienia, $cap ) {
	return 'edit_post' === $cap ? array( 'do_not_allow' ) : $uprawnienia;
};
add_filter( 'map_meta_cap', $kw_l_odmow2, 10, 2 );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_tok( $kw_l_pp[2], 'gora' ) );
remove_filter( 'map_meta_cap', $kw_l_odmow2, 10 );
$sprawdz( 'przesuwanie: poprawny token, ale brak prawa edycji => nic sie nie zmienia', 'prawa' === $w['kod'] && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( $w ) );
$kw_l_odmow3 = static function ( $uprawnienia, $cap, $uzytkownik, $arg ) use ( $kw_l_pp ) {
	return 'edit_post' === $cap && isset( $arg[0] ) && (int) $arg[0] === $kw_l_pp[1] ? array( 'do_not_allow' ) : $uprawnienia;
};
add_filter( 'map_meta_cap', $kw_l_odmow3, 10, 4 );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_tok( $kw_l_pp[2], 'gora' ) );
remove_filter( 'map_meta_cap', $kw_l_odmow3, 10 );
$sprawdz( 'przesuwanie: brak prawa do SASIADA (zmienianej uslugi) => nic sie nie zmienia', 'prawa' === $w['kod'] && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( $w ) );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'bok', $kw_l_tok( $kw_l_pp[2], 'bok' ) );
$sprawdz( 'przesuwanie: kierunek spoza bialej listy => nic sie nie zmienia', 'pole' === $w['kod'] && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( $w ) );
$w  = kw_uslugi_lista_przesun( $kw_l_pp[1], 'gora', $kw_l_tok( $kw_l_pp[1], 'gora' ) );
$w2 = kw_uslugi_lista_przesun( $kw_l_pp[3], 'dol', $kw_l_tok( $kw_l_pp[3], 'dol' ) );
$sprawdz( 'przesuwanie: krance zakladki (pierwsza w gore, ostatnia w dol) => "kraniec", nic sie nie zmienia', 'kraniec' === $w['kod'] && 'kraniec' === $w2['kod'] && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( array( $w, $w2 ) ) );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_tok( $kw_l_pp[2], 'gora' ) );
$sprawdz( 'przesuwanie: srodkowa wyzej => zamiana menu_order z sasiadem (20 i 10), trzecia bez zmian, tytuly i stan bez zmian', 'ok' === $w['kod'] && array( 20, 10, 30 ) === $kw_l_kol() && $kw_l_tyt0 === $kw_l_tytuly(), wp_json_encode( array( $w, $kw_l_kol() ) ) );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'dol', $kw_l_tok( $kw_l_pp[2], 'dol' ) );
$sprawdz( 'przesuwanie: ta sama usluga nizej => wraca na miejsce (10, 20, 30)', 'ok' === $w['kod'] && array( 10, 20, 30 ) === $kw_l_kol(), wp_json_encode( array( $w, $kw_l_kol() ) ) );
foreach ( $kw_l_pp as $kw_l_pid ) {
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'menu_order' => 50 ), array( 'ID' => $kw_l_pid ) ); // rowne liczby (wlasne szkice)
	clean_post_cache( $kw_l_pid );
}
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_tok( $kw_l_pp[2], 'gora' ) );
$sprawdz( 'przesuwanie: rowne liczby => cala zakladka przenumerowana co 10 od najmniejszej (50, 60, 70), potem zamiana: 2 przed 1', 'ok' === $w['kod'] && array( 60, 50, 70 ) === $kw_l_kol(), wp_json_encode( array( $w, $kw_l_kol() ) ) );

$kw_l_get = $_GET;
$_GET     = array();
$kw_l_s1  = kw_uslugi_lista_strzalki( get_post( $kw_l_pp[2] ) );
$kw_l_s0  = kw_uslugi_lista_strzalki( get_post( $kw_l_pp[1] ) );
kw_uslugi_lista_zakladki( true );
$kw_l_sp  = kw_uslugi_lista_strzalki( get_post( $kw_l_pp[1] ) ); // po przenumerowaniu srodkowa w zakladce
$kw_l_sk  = kw_uslugi_lista_strzalki( get_post( $kw_l_pp[3] ) );
$_GET     = array( 's' => 'x' );
$kw_l_sz  = kw_uslugi_lista_strzalki( get_post( $kw_l_pp[2] ) );
$_GET     = array( 'orderby' => 'title' );
$kw_l_so  = kw_uslugi_lista_strzalki( get_post( $kw_l_pp[2] ) );
$_GET     = $kw_l_get;
$sprawdz(
	'strzalki: dwa przyciski z nazwa dostepna ("Przesuń „…” wyżej w zakładce"), nieaktywne na krancach; bez strzalek przy wyszukiwaniu i sortowaniu po naglowku',
	2 === substr_count( $kw_l_sp, '<button' ) && false === strpos( $kw_l_sp, ' disabled' ) && false !== strpos( $kw_l_sp, 'aria-label="Przesuń „Samotest przesun 1” wyżej w zakładce"' ) && false !== strpos( $kw_l_sp, 'aria-label="Przesuń „Samotest przesun 1” niżej w zakładce"' ) && false !== strpos( $kw_l_sp, 'name="kw_przesun"' ) && false !== strpos( $kw_l_sp, 'form="kw-przelacznik-formularz"' ) && 1 === substr_count( $kw_l_sk, ' disabled' ) && '' === $kw_l_sz && '' === $kw_l_so,
	$kw_l_sp . ' || ' . $kw_l_sk
);
$kw_l_zadanie2 = static function ( array $post ) {
	$stare = $_POST;
	$_POST = $post;
	try {
		kw_uslugi_lista_akcja();
		$adres = 'BRAK PRZEKIEROWANIA';
	} catch ( RuntimeException $e ) {
		$adres = $e->getMessage();
	}
	$_POST = $stare;
	return $adres;
};
add_filter( 'wp_redirect', $kw_l_przechwyt );
$adres = $kw_l_zadanie2( array( 'kw_przesun' => $kw_l_pp[2] . '|dol|' . $kw_l_tok( $kw_l_pp[2], 'dol' ) ) );
remove_filter( 'wp_redirect', $kw_l_przechwyt );
$sprawdz( 'zadanie z przycisku strzalki (POST): przesuwa i wraca na liste z komunikatem "przesun-dol"', false !== strpos( $adres, 'kw_info=przesun-dol' ) && array( 50, 60, 70 ) === $kw_l_kol(), $adres . ' ' . wp_json_encode( $kw_l_kol() ) );

$_GET = array(
	'kw_info' => 'przesun-dol',
	'kw_id'   => (string) $kw_l_pp[2],
);
ob_start();
kw_uslugi_lista_komunikat();
$kw_l_kom = (string) ob_get_clean();
$_GET     = $kw_l_get;
$kw_l_cofnij_przesun = 1 === preg_match( '~name="kw_przesun" value="' . $kw_l_pp[2] . '\|gora\|([a-f0-9]+)" class="button-link kw-cofnij">Cofnij~', $kw_l_kom, $kw_l_m2 );
$w = $kw_l_cofnij_przesun ? kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_m2[1] ) : array( 'kod' => 'brak przycisku' );
$sprawdz( 'Cofnij po przesunieciu: przycisk niesie ruch w druga strone z tokenem i go cofa', $kw_l_cofnij_przesun && 'ok' === $w['kod'], $kw_l_kom );

remove_filter( 'kw_uslugi_lista_statusy_kolejnosci', $kw_l_stany );
$w = kw_uslugi_lista_przesun( $kw_l_pp[2], 'gora', $kw_l_tok( $kw_l_pp[2], 'gora' ) );
$sprawdz( 'przesuwanie: nieopublikowanej uslugi (szkic) nie rusza (kolejnosc dotyczy opublikowanych)', 'brak' === $w['kod'], wp_json_encode( $w ) );
foreach ( $kw_l_pp as $kw_l_pid ) {
	wp_delete_post( $kw_l_pid, true );
}
wp_delete_term( $kw_l_ktid, 'kw_grupa' );
kw_uslugi_kategorie( true );
kw_uslugi_lista_zakladki( true );

/* ---- kafle: zdanie nad nimi i znacznik wybranej ---- */
$kw_l_opis = kw_uslugi_lista_kafle_html( array( array( 'slug' => '', 'nazwa' => 'Wszystkie', 'ile' => 3, 'cena' => '' ), array( 'slug' => 'jdg', 'nazwa' => 'JDG', 'ile' => 2, 'cena' => 'od 1 zł' ) ), 'jdg' );
$sprawdz( 'kafle: zdanie "Kliknij kategorię, żeby zobaczyć tylko jej usługi." nad kaflami, wybrany kafel ma slowo "wybrana" i aria-current (stan nie tylko kolorem)', false !== strpos( $kw_l_opis, 'Kliknij kategorię, żeby zobaczyć tylko jej usługi.' ) && 1 === substr_count( $kw_l_opis, 'kw-kafel__wybrana' ) && 1 === preg_match( '~aria-current="true"><span class="kw-kafel__nazwa">JDG</span>.*wybrana~', $kw_l_opis ), $kw_l_opis );

/* ---- sprzatanie ---- */
wp_delete_post( $kw_l_id, true );
wp_delete_post( $kw_l_page, true );
wp_delete_term( $kw_l_tid, 'kw_grupa' );
kw_uslugi_kategorie( true );
$sprawdz( 'lista: testowy szkic, strona i kategoria usuniete', null === get_post( $kw_l_id ) && null === get_post( $kw_l_page ) && ! get_term( $kw_l_tid ) instanceof WP_Term );
