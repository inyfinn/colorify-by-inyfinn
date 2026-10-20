<?php
/**
 * Panel: kreator uslugi z cena i podgladem karty, Ustawienia -> Uslugi, kopie wiadomosci z formularza.
 * Lista uslug i kategorie: includes/admin-lista.php. Import i eksport cen: includes/admin-import.php.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

require_once KW_USLUGI_KATALOG . 'includes/admin-lista.php'; // lista uslug, kategorie, porzadek ekranow
require_once KW_USLUGI_KATALOG . 'includes/admin-import.php'; // import i eksport cen w Ustawienia, Uslugi
require_once KW_USLUGI_KATALOG . 'includes/admin-biblioteka.php'; // biblioteka pozycji list i zestawow progow

/* ---------------------------------------------------------------------------
 * Wspolne dla ekranow uslug
 * ------------------------------------------------------------------------ */

/**
 * Dymek z wyjasnieniem: przycisk "?" i tekst obok (role="tooltip"). Jeden wzor na wszystkich ekranach uslug:
 * lista, kategorie, kreator, ustawienia. Wyglad: assets/kw-admin.css (.kwa-dymek), zachowanie: assets/kw-admin-dymki.js.
 * Stawiaj go OBOK etykiety pola, nie w srodku znacznika label ani legend (inaczej jego nazwa trafia do nazwy pola).
 *
 * @param string $id    Identyfikator dymka (litery, cyfry, lacznik), unikalny na ekranie.
 * @param string $tekst Wyjasnienie: czysty tekst; nowy wiersz = nowa linia w dymku.
 * @param string $czego Czego dotyczy (nazwa przycisku dla czytnikow ekranu), np. "Kolejność w zakładce".
 * @return string HTML.
 */
function kw_uslugi_dymek( $id, $tekst, $czego = '' ) {
	return sprintf(
		'<span class="kwa-dymek"><button type="button" class="kwa-dymek__btn" aria-label="%1$s" aria-describedby="%2$s" aria-expanded="false"><span aria-hidden="true">?</span></button><span class="kwa-dymek__tresc" role="tooltip" id="%2$s">%3$s</span></span>',
		esc_attr( '' !== $czego ? 'Wyjaśnienie: ' . $czego : 'Wyjaśnienie' ),
		esc_attr( kw_uslugi_dymek_id( $id ) ),
		nl2br( esc_html( $tekst ) )
	);
}

/**
 * Identyfikator tresci dymka (do aria-describedby na elemencie, ktory dymek opisuje).
 *
 * @param string $id Identyfikator podany do kw_uslugi_dymek() albo kw_uslugi_dymek_wokol().
 * @return string
 */
function kw_uslugi_dymek_id( $id ) {
	return 'kwa-dymek-' . sanitize_html_class( $id );
}

/**
 * Dymek pokazywany po najechaniu albo fokusie NA SAMYM ELEMENCIE (np. przelaczniku), bez osobnego przycisku "?".
 * Element dostaje od wolajacego aria-describedby="<kw_uslugi_dymek_id( $id )>".
 *
 * @param string $id      Identyfikator dymka, unikalny na ekranie.
 * @param string $element Gotowy, bezpieczny HTML elementu (przycisk, odnosnik): musi byc pierwszym dzieckiem otoczki.
 * @param string $tekst   Wyjasnienie: czysty tekst.
 * @return string HTML.
 */
function kw_uslugi_dymek_wokol( $id, $element, $tekst ) {
	return '<span class="kwa-dymek kwa-dymek--otoczka">' . $element . '<span class="kwa-dymek__tresc" role="tooltip" id="' . esc_attr( kw_uslugi_dymek_id( $id ) ) . '">' . nl2br( esc_html( $tekst ) ) . '</span></span>';
}

/**
 * Czy to ekran, na ktorym laduja sie style panelu uslug: uslugi, wiadomosci, kategorie uslug, Ustawienia -> Uslugi.
 *
 * @param WP_Screen|null $ekran Ekran.
 * @return bool
 */
function kw_uslugi_ekran_panelu( $ekran ) {
	return $ekran instanceof WP_Screen && ( in_array( $ekran->post_type, array( 'kw_usluga', 'kw_wiadomosc' ), true ) || 'kw_grupa' === $ekran->taxonomy || 'settings_page_kw-uslugi' === $ekran->id );
}

/* ---------------------------------------------------------------------------
 * Kreator uslugi
 * ------------------------------------------------------------------------ */

/**
 * Ekran uslugi bez pol rdzenia WordPressa: nazwe i dodatkowy opis ma kreator (widoczne etykiety, zwykle pole zamiast
 * edytora wizualnego). Wsparcie "title" i "editor" znika tylko w tym jednym zadaniu panelu; REST, lista uslug i zapis
 * (post_title, content) dzialaja bez zmian.
 */
function kw_uslugi_kreator_ekran() {
	$ekran = get_current_screen();
	if ( $ekran && 'kw_usluga' === $ekran->post_type ) {
		remove_post_type_support( 'kw_usluga', 'title' );
		remove_post_type_support( 'kw_usluga', 'editor' );
	}
}
add_action( 'load-post.php', 'kw_uslugi_kreator_ekran' );
add_action( 'load-post-new.php', 'kw_uslugi_kreator_ekran' );

/**
 * Domyslne skrzynki WordPressa zastepuje kreator (zajawka, kolejnosc, kategoria sa w nim); obok staje podglad karty.
 */
function kw_uslugi_bez_skrzynek() {
	foreach ( array( 'postexcerpt', 'pageparentdiv', 'postcustom', 'slugdiv' ) as $skrzynka ) {
		remove_meta_box( $skrzynka, 'kw_usluga', 'normal' );
		remove_meta_box( $skrzynka, 'kw_usluga', 'side' );
	}
	add_meta_box( 'kw_podglad', 'Podgląd karty w cenniku', 'kw_uslugi_kreator_podglad', 'kw_usluga', 'side', 'low' );
	add_meta_box( 'kw_wiadomosc_tresc', 'Wiadomość z formularza', 'kw_uslugi_wiadomosc_skrzynka', 'kw_wiadomosc', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'kw_uslugi_bez_skrzynek', 20 );

/**
 * Kafle wyboru typu ceny: klucz => array( przyklad, nazwa, jedno zdanie ). Klucze jak w kw_uslugi_typy_cen().
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function kw_uslugi_kreator_kafle() {
	return array(
		'stala'        => array( '50 zł', 'Stała kwota', 'Jedna cena.' ),
		'od'           => array( 'od 500 zł', 'Cena „od”', 'Najniższa możliwa kwota.' ),
		'przedzial'    => array( '30-60 zł', 'Przedział', 'Od najniższej do najwyższej.' ),
		'indywidualna' => array( 'do ustalenia', 'Wycena indywidualna', 'Bez kwoty na stronie.' ),
		'opis'         => array( 'wg umowy', 'Własny tekst', 'Zdanie zamiast kwoty.' ),
	);
}

/**
 * Typowe progi liczby dokumentow (przycisk "Wstaw typowe progi"). Ostatni prog nie ma gornej granicy.
 * Klucz '' = progi domyslne (KPiR, ryczalt); klucz = slug kategorii, ktora ma wlasne progi (ksiegi handlowe w "Spolkach").
 * ponytail: zestawy sa wpisane tutaj (cennik biura na 2026 rok); gdyby progi mialy sie zmieniac czesto, przeniesc do Ustawien.
 *
 * @return array<string,string[]>
 */
function kw_uslugi_kreator_typowe_progi() {
	return array(
		''       => array( '1-10', '11-20', '21-40', '41-80', '81-140', '141 i więcej' ),
		'spolki' => array( '1-10', '11-30', '31-60', '61-99', '100-150', '151-200', '201 i więcej' ),
	);
}

/**
 * Podpowiedz pod polem i miejsce na komunikat bledu (wypelnia go skrypt). Identyfikatory: <id>-pomoc, <id>-blad.
 *
 * @param string $id    Identyfikator pola.
 * @param string $tekst Podpowiedz (moze zawierac odnosnik; przechodzi przez wp_kses).
 * @return string HTML.
 */
function kw_uslugi_kreator_pomoc( $id, $tekst = '' ) {
	$html = '';
	if ( '' !== $tekst ) {
		$html .= '<p class="kw-pomoc" id="' . esc_attr( $id ) . '-pomoc">' . wp_kses( $tekst, array( 'a' => array( 'href' => true ), 'strong' => array() ) ) . '</p>';
	}
	return $html . '<p class="kw-blad" id="' . esc_attr( $id ) . '-blad" data-kw-blad hidden></p>';
}

/**
 * Przycisk z ikona (strzalka, usun) i nazwa dla czytnikow ekranu.
 *
 * @param string $atrybut Atrybut data-*, po ktorym skrypt rozpoznaje przycisk.
 * @param string $ikona   Ikona: gora, dol albo usun (maska SVG w kw-admin.css).
 * @param string $nazwa   Nazwa dostepna.
 * @return string HTML.
 */
function kw_uslugi_kreator_przycisk( $atrybut, $ikona, $nazwa ) {
	return '<button type="button" class="kw-ikona-btn" ' . esc_attr( $atrybut ) . ' aria-label="' . esc_attr( $nazwa ) . '" title="' . esc_attr( $nazwa ) . '"><span class="kw-ik kw-ik--' . esc_attr( $ikona ) . '" aria-hidden="true"></span></button>';
}

/**
 * Wiersz progu cenowego w kreatorze.
 *
 * @param int|string $nr   Numer wiersza (albo "__i__" we wzorze dla skryptu).
 * @param array      $prog Prog: etykieta, cena, od.
 * @return string HTML.
 */
function kw_uslugi_kreator_prog( $nr, array $prog = array() ) {
	$nazwa = 'kw_progi[' . $nr . ']';
	$id    = 'kw-prog-' . $nr;
	return sprintf(
		'<div class="kw-prog" data-kw-prog role="group" aria-label="Próg cenowy">'
		. '<div class="kw-prog__wybor"><input type="checkbox" data-kw-prog-wybor id="%5$s-w" aria-label="Zaznacz ten próg do zmiany ceny"></div>'
		. '<div class="kw-prog__zakres"><input type="text" name="%1$s[etykieta]" value="%2$s" maxlength="40" id="%5$s-e" aria-label="Zakres dokumentów, np. 11-20" placeholder="np. 11-20" aria-describedby="%5$s-blad"></div>'
		. '<div class="kw-prog__cena"><span class="kw-kwota"><input type="text" inputmode="decimal" name="%1$s[cena]" value="%3$s" maxlength="12" id="%5$s-c" aria-label="Cena progu w złotych" aria-describedby="%5$s-blad"><span class="kw-kwota__zl" aria-hidden="true">zł</span></span><span class="kw-prog__po" data-kw-prog-po hidden></span></div>'
		. '<div class="kw-prog__od"><label for="%5$s-o"><input type="checkbox" name="%1$s[od]" value="1" id="%5$s-o" %4$s> <span>„od”<span class="screen-reader-text"> przed ceną tego progu</span></span></label></div>'
		. '<div class="kw-prog__akcje">%6$s%7$s%8$s</div>'
		. '<p class="kw-blad" id="%5$s-blad" data-kw-blad hidden></p>'
		. '</div>',
		esc_attr( $nazwa ),
		esc_attr( $prog['etykieta'] ?? '' ),
		esc_attr( $prog['cena'] ?? '' ),
		checked( ! empty( $prog['od'] ), true, false ),
		esc_attr( $id ),
		kw_uslugi_kreator_przycisk( 'data-kw-prog-gora', 'gora', 'Przesuń próg wyżej' ),
		kw_uslugi_kreator_przycisk( 'data-kw-prog-dol', 'dol', 'Przesuń próg niżej' ),
		kw_uslugi_kreator_przycisk( 'data-kw-usun-prog', 'usun', 'Usuń próg' )
	);
}

/**
 * Zdanie o tym, gdzie widac usluge (znacznik nad podgladem i stan kroku "Widocznosc").
 *
 * @param string $status    Status wpisu.
 * @param bool   $cennik    Czy pokazywac w cenniku.
 * @param bool   $formularz Czy pokazywac w formularzu.
 * @return array{0:string,1:string} Tekst i odmiana znacznika (ok, uwaga albo pusta).
 */
function kw_uslugi_kreator_gdzie( $status, $cennik, $formularz ) {
	if ( 'publish' !== $status ) {
		return array( 'Szkic: nie widać jej na stronie', 'uwaga' );
	}
	if ( $cennik && $formularz ) {
		return array( 'Na stronie: w cenniku i formularzu', 'ok' );
	}
	if ( $cennik ) {
		return array( 'Na stronie: tylko w cenniku', 'ok' );
	}
	if ( $formularz ) {
		return array( 'Na stronie: tylko w formularzu', 'ok' );
	}
	return array( 'Ukryta: nie widać jej na stronie', 'uwaga' );
}

/**
 * Dane dla skryptu kreatora: dopiski jednostek, typowe progi, sasiedzi w zakladkach (miejsce w zakladce), biblioteka.
 *
 * @param WP_Post $post Wpis.
 * @return array<string,mixed>
 */
function kw_uslugi_kreator_dane( WP_Post $post ) {
	$jednostki = array();
	$jedn_pelne = array();
	foreach ( kw_uslugi_jednostki() as $klucz => $nazwy ) {
		$jednostki[ $klucz ]  = $nazwy[1];
		$jedn_pelne[ $klucz ] = $nazwy[0];
	}
	$kategorie = kw_uslugi_kategorie();
	$sasiedzi  = array();
	foreach ( kw_uslugi_pobierz() as $u ) {
		$slug       = (string) array_key_first( $u['grupy'] );
		$sasiedzi[] = array(
			'id' => (int) $u['id'],
			't'  => (string) $u['tytul'],
			'k'  => isset( $kategorie[ $slug ] ) ? (int) $kategorie[ $slug ]['id'] : 0,
			'n'  => (int) get_post_field( 'menu_order', $u['id'] ),
		);
	}
	$typowe     = kw_uslugi_kreator_typowe_progi();
	$typowe_kat = array();
	foreach ( $typowe as $slug => $lista ) {
		if ( '' !== $slug && isset( $kategorie[ $slug ] ) ) {
			$typowe_kat[ (int) $kategorie[ $slug ]['id'] ] = $lista;
		}
	}
	return array(
		'id'        => (int) $post->ID,
		'slug'      => (string) $post->post_name,
		'status'    => (string) $post->post_status,
		'ceny'      => (bool) kw_uslugi_opcje()['ceny'],
		'jednostki' => $jednostki,
		'typowe'    => $typowe[''],
		'typoweKat' => $typowe_kat, // ID kategorii => jej wlasne progi
		'sasiedzi'  => $sasiedzi,
		'jednostkiNazwy' => $jedn_pelne, // klucz => nazwa z listy (biblioteka: "Za co jest kwota")
		'biblioteka'     => kw_uslugi_bib_pobierz(),
		'bibToken'       => wp_create_nonce( 'kw_uslugi_bib' ),
		'ajax'           => admin_url( 'admin-ajax.php' ),
	);
}

/**
 * Ekran uslugi jako tablica kafli (podstawy, cena, widocznosc i miejsce, progi, co jest w cenie, dodatkowy opis): zwiniety
 * kafel pokazuje streszczenie, otwarty swoje pola. Nad kaflami pasek z nazwa, stanem i przyciskami Biblioteka i Zapisz.
 * Kazde pole ma widoczna etykiete, podpowiedz i miejsce na blad. Bez JavaScriptu wszystkie kafle sa otwarte, pola
 * zwykle (listy jako pola tekstowe, progi jako wiersze, kolejnosc jako liczba) i formularz zapisuje sie tak samo.
 *
 * @param WP_Post $post Wpis.
 */
function kw_uslugi_kreator( $post ) {
	if ( 'kw_usluga' !== $post->post_type ) {
		return;
	}
	$meta       = static function ( $klucz ) use ( $post ) {
		return get_post_meta( $post->ID, $klucz, true );
	};
	$typ        = kw_uslugi_czysc_typ_ceny( $meta( 'kw_cena_typ' ) );
	$jednostka  = kw_uslugi_czysc_jednostke( $meta( 'kw_cena_jednostka' ) );
	$jednostki  = kw_uslugi_jednostki();
	$wlasna     = '' !== $jednostka && ! isset( $jednostki[ $jednostka ] );
	$netto      = '0' !== (string) $meta( 'kw_cena_netto' );
	$progi      = kw_uslugi_czysc_progi( $meta( 'kw_progi' ) );
	$kategorie  = kw_uslugi_kategorie( true );
	$wybrane    = wp_get_object_terms( $post->ID, 'kw_grupa', array( 'fields' => 'slugs' ) );
	$wybrane    = is_array( $wybrane ) ? $wybrane : array();
	$wybrana    = (string) current( array_intersect( array_keys( $kategorie ), $wybrane ) ); // pierwsza w kolejnosci zakladek
	$ceny_wl    = (bool) kw_uslugi_opcje()['ceny'];
	$ustawienia = admin_url( 'options-general.php?page=kw-uslugi' );
	$kat_url    = admin_url( 'edit-tags.php?taxonomy=kw_grupa&post_type=kw_usluga' );
	// Lista: nazwa, znak pozycji, podpowiedz pod nazwa, tekst dymka "?", klucz podswietlenia w podgladzie.
	$listy      = array(
		'kw_w_cenie'      => array( 'W cenie', 'tak', 'Co klient dostaje w tej cenie. Najważniejsze wpisz na początku.', "Do czterech pozycji klient widzi od razu na karcie.\nGdy jest ich więcej: trzy pierwsze stoją na karcie pod napisem „W cenie między innymi”, a pozostałe pokazują się po kliknięciu „Progi cenowe i szczegóły”.\nDlatego najważniejsze pozycje wpisz jako pierwsze.", 'wcenie' ),
		'kw_dodatkowo'    => array( 'Dodatkowo płatne', 'plus', 'Co liczysz osobno, najlepiej od razu z kwotą.', "Rzeczy, za które klient dopłaca osobno, np. „kadry i płace (50 zł netto za pracownika)”.\nKlient widzi tę listę po rozwinięciu szczegółów karty, pod nagłówkiem „Dodatkowo płatne”.", 'dodatkowo' ),
		'kw_nie_obejmuje' => array( 'Nie obejmuje', 'nie', 'Czego ta usługa nie zawiera. Możesz zostawić puste.', "Czego w tej usłudze nie ma, żeby klient nie zakładał, że jest, np. „reprezentacja przed sądem”.\nWidać po rozwinięciu szczegółów karty. Pusta lista po prostu się nie pokazuje.", 'nie' ),
		'kw_czynniki'     => array( 'Od czego zależy cena', 'kropka', 'Co podnosi albo obniża cenę.', "Co sprawia, że cena końcowa jest inna niż cena „od”, np. „liczba dokumentów w miesiącu” albo „transakcje zagraniczne”.\nWidać po rozwinięciu szczegółów karty. Przy cenie „od” warto to uzupełnić.", 'czynniki' ),
	);
	// Wiersz etykiety pola: etykieta + dymek "?" obok (dymek poza znacznikiem label, zeby nie trafial do nazwy pola).
	$et         = static function ( $dla, $tekst, $dymek = '', $opc = false ) {
		printf(
			'<div class="kw-etykieta-rzad"><label for="%1$s">%2$s%3$s</label>%4$s</div>',
			esc_attr( $dla ),
			esc_html( $tekst ),
			$opc ? ' <span class="kw-etykieta__opc">(opcjonalnie)</span>' : '',
			'' !== $dymek ? kw_uslugi_dymek( 'k-' . $dla, $dymek, $tekst ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i esc_html.
		);
	};
	// To samo dla grupy pol (kafle, przelaczniki): etykieta grupy z identyfikatorem do aria-labelledby.
	$etg        = static function ( $id, $tekst, $dymek = '', $opc = false ) {
		printf(
			'<div class="kw-etykieta-rzad"><span class="kw-etykieta" id="%1$s">%2$s%3$s</span>%4$s</div>',
			esc_attr( $id ),
			esc_html( $tekst ),
			$opc ? ' <span class="kw-etykieta__opc">(opcjonalnie)</span>' : '',
			'' !== $dymek ? kw_uslugi_dymek( 'k-' . $id, $dymek, $tekst ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i esc_html.
		);
	};
	// Kafel tablicy: naglowek jest przyciskiem (tytul, znacznik stanu, streszczenie wypelniane przez skrypt), pod nim pola.
	// Bez JavaScriptu wszystkie kafle sa otwarte, a przycisk nic nie robi.
	$kafel      = static function ( $id, $tytul, $opis, $cel, $kol, $opc = false, $typy = '' ) {
		printf(
			'<section class="kf %5$s" id="kf-%1$s" data-kw-kafel="%1$s" data-kw-cel="%4$s"%7$s>'
			. '<h2 class="kf__h" id="kf-%1$s-h"><button type="button" class="kf__glowa" aria-expanded="true" aria-controls="kf-%1$s-tresc" data-kw-kafel-glowa>'
			. '<span class="kf__tyt">%2$s%6$s</span><span class="kf__tag" data-kw-stan hidden></span><span class="kf__chevron" aria-hidden="true"></span>'
			. '<span class="kf__stresz" data-kw-stresz>%3$s</span>'
			. '</button></h2>'
			. '<div class="kf__tresc" id="kf-%1$s-tresc">',
			esc_attr( $id ),
			esc_html( $tytul ),
			esc_html( $opis ),
			esc_attr( $cel ),
			esc_attr( $kol ),
			$opc ? ' <span class="kf__opc">(opcjonalnie)</span>' : '',
			'' !== $typy ? ' data-kw-typy="' . esc_attr( $typy ) . '"' : ''
		);
	};
	$tytul_paska = '' !== trim( $post->post_title ) ? $post->post_title : 'Nowa usługa';

	wp_nonce_field( 'kw_usluga_zapis', 'kw_usluga_nonce' );
	?>
<div class="kw-kreator" data-kw-kreator>
	<div class="kw-pasek-gora" data-kw-pasek-gora hidden>
		<p class="kw-pasek-gora__tyt"><strong data-kw-pasek-nazwa><?php echo esc_html( $tytul_paska ); ?></strong> <span class="kw-pasek-gora__gdzie" data-kw-pasek-gdzie></span></p>
		<p class="kw-pasek-gora__akcje"><button type="button" class="kw-btn" data-kw-bib-otworz aria-haspopup="dialog">Biblioteka</button> <button type="button" class="kw-btn kw-btn--glowny" data-kw-zapisz>Zapisz</button></p>
	</div>

	<details class="kw-jak" data-kw-jak open>
		<summary>Jak to działa</summary>
		<ol class="kw-jak__lista">
			<li>Jedna usługa to jedna karta w cenniku na stronie. <strong>Podgląd karty</strong> obok pokazuje ją tak, jak zobaczy klient, i zmienia się podczas pisania.</li>
			<li>Każdy kafel to jedna część usługi. Zwinięty pokazuje skrót, kliknięty otwiera swoje pola.</li>
			<li>Najedź na kafel albo pole: w podglądzie podświetli się miejsce, w którym widać je na karcie.</li>
			<li>Powtarzalne pozycje list i typowe progi bierzesz z <strong>Biblioteki</strong> (przycisk u góry); własną pozycję dodasz do niej znaczkiem obok.</li>
			<li>Na stronie nic się nie zmienia, dopóki nie klikniesz <strong>Zapisz</strong> (to samo co <strong>Aktualizuj</strong>, przy nowej usłudze: <strong>Opublikuj</strong>). Usługi nie trzeba usuwać, żeby ją schować: wystarczy odznaczyć oba pola w kaflu „Widoczność i miejsce”.</li>
		</ol>
	</details>

	<div class="kw-bledy" data-kw-bledy role="alert" tabindex="-1" hidden>
		<p class="kw-bledy__h" data-kw-bledy-h></p>
		<ul data-kw-bledy-lista></ul>
	</div>

	<div class="kf-tablica" data-kw-tablica>
		<?php $kafel( 'podstawy', 'Podstawy', 'Nazwa, jedno zdanie opisu i zakładka cennika. To klient widzi jako pierwsze.', 'tytul', 'c12' ); ?>
			<div class="kw-pole c12" data-kw-cel="tytul">
				<label for="title">Nazwa usługi</label>
				<input type="text" id="title" name="post_title" class="kw-pole__duze" maxlength="200" autocomplete="off" spellcheck="true" required value="<?php echo esc_attr( $post->post_title ); ?>" aria-describedby="title-pomoc title-blad">
				<?php echo kw_uslugi_kreator_pomoc( 'title', 'Tak, jak ma stać w cenniku, np. Księgi handlowe.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>
			<div class="kw-pole c6" data-kw-cel="zajawka">
				<label for="kw-zajawka">Krótki opis</label>
				<textarea id="kw-zajawka" name="excerpt" rows="2" aria-describedby="kw-zajawka-pomoc"><?php echo esc_textarea( $post->post_excerpt ); ?></textarea>
				<?php echo kw_uslugi_kreator_pomoc( 'kw-zajawka', 'Jedno zdanie: dla kogo jest ta usługa albo co obejmuje. Stoi na karcie pod nazwą.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>
			<div class="kw-pole c6" data-kw-cel="kategoria">
				<?php $et( 'kw-grupa', 'Kategoria', "Kategoria to zakładka cennika, w której klient znajdzie usługę: na przykład Spółki, JDG, Kadry i płace albo Usługi dodatkowe.\nTa sama nazwa grupuje usługi w polu „Usługa” formularza kontaktowego." ); ?>
				<input type="hidden" name="tax_input[kw_grupa][]" value="0">
				<select id="kw-grupa" name="tax_input[kw_grupa][]" aria-describedby="kw-grupa-pomoc">
					<option value="0">Bez kategorii</option>
					<?php foreach ( $kategorie as $slug => $kategoria ) : ?>
						<?php
						// Kategoria w archiwum stoi na liscie tylko wtedy, gdy usluga juz ja ma.
						if ( $kategoria['ukryta'] && $wybrana !== (string) $slug ) {
							continue;
						}
						?>
					<option value="<?php echo (int) $kategoria['id']; ?>" <?php selected( $wybrana, (string) $slug ); ?>><?php echo esc_html( $kategoria['nazwa'] . ( $kategoria['ukryta'] ? ' (archiwum)' : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php echo kw_uslugi_kreator_pomoc( 'kw-grupa', 'W tej zakładce cennika klient znajdzie usługę. Nową kategorię dodasz w menu Usługi, pozycja <a href="' . esc_url( $kat_url ) . '">Kategorie</a>.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>
		</div></section>

		<?php $kafel( 'cena', 'Cena', 'Wybierz, jak podajesz cenę. Pokażą się tylko pola, których ten sposób potrzebuje.', 'cena', 'c6' ); ?>
			<?php if ( ! $ceny_wl ) : ?>
			<p class="kw-uwaga c12">Ceny są teraz <strong>ukryte</strong> na całej stronie. Włączysz je w: <a href="<?php echo esc_url( $ustawienia ); ?>">Ustawienia, Usługi</a>.</p>
			<?php endif; ?>
			<div class="kw-pole c12" role="radiogroup" aria-labelledby="kw-typ-h" data-kw-cel="cena">
				<?php $etg( 'kw-typ-h', 'Jak podajesz cenę', "Stała kwota: klient widzi np. „50 zł netto / dokument”.\nCena „od”: „od 500 zł netto / mies.”, gdy kwota rośnie razem z zakresem pracy.\nPrzedział: „30-60 zł netto”.\nWycena indywidualna: zamiast kwoty stoi napis „Wycena indywidualna”.\nWłasny tekst: Twoje zdanie w miejscu ceny, np. „Równowartość jednej faktury miesięcznej”." ); ?>
				<div class="kw-typy">
					<?php foreach ( kw_uslugi_kreator_kafle() as $klucz => $kafel_typu ) : ?>
					<input type="radio" id="kw-typ-<?php echo esc_attr( $klucz ); ?>" name="kw_cena_typ" value="<?php echo esc_attr( $klucz ); ?>" <?php checked( $typ, $klucz ); ?>>
					<label class="kw-typ" for="kw-typ-<?php echo esc_attr( $klucz ); ?>">
						<span class="kw-typ__wzor" aria-hidden="true"><?php echo esc_html( $kafel_typu[0] ); ?></span>
						<span class="kw-typ__nazwa"><?php echo esc_html( $kafel_typu[1] ); ?></span>
						<span class="kw-typ__opis"><?php echo esc_html( $kafel_typu[2] ); ?></span>
					</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="kw-pole c3" data-kw-typy="stala od przedzial" data-kw-cel="cena">
				<label for="kw-cena-min">Kwota<span data-kw-typy="od przedzial"> najniższa</span></label>
				<span class="kw-kwota"><input type="text" inputmode="decimal" id="kw-cena-min" name="kw_cena_min" maxlength="12" value="<?php echo esc_attr( kw_uslugi_czysc_kwote( $meta( 'kw_cena_min' ) ) ); ?>" aria-describedby="kw-cena-min-pomoc kw-cena-min-blad"><span class="kw-kwota__zl" aria-hidden="true">zł</span></span>
				<?php echo kw_uslugi_kreator_pomoc( 'kw-cena-min', 'Sama liczba, np. 500.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>
			<div class="kw-pole c3" data-kw-typy="przedzial" data-kw-cel="cena">
				<label for="kw-cena-max">Kwota najwyższa</label>
				<span class="kw-kwota"><input type="text" inputmode="decimal" id="kw-cena-max" name="kw_cena_max" maxlength="12" value="<?php echo esc_attr( kw_uslugi_czysc_kwote( $meta( 'kw_cena_max' ) ) ); ?>" aria-describedby="kw-cena-max-pomoc kw-cena-max-blad"><span class="kw-kwota__zl" aria-hidden="true">zł</span></span>
				<?php echo kw_uslugi_kreator_pomoc( 'kw-cena-max', 'Np. 60 da „30-60 zł”.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>
			<div class="kw-pole c3" data-kw-typy="stala od przedzial" data-kw-cel="cena">
				<?php $et( 'kw-jednostka', 'Za co jest ta kwota', "Dopisek po kwocie na karcie.\nPrzykłady: „miesiąc” daje „od 500 zł netto / mies.”, „dokument” daje „50 zł netto / dokument”.\nGdy na liście nie ma właściwej nazwy, wybierz „inna” i wpisz własną." ); ?>
				<select id="kw-jednostka" name="kw_cena_jednostka" aria-describedby="kw-jednostka-wlasna-blad">
					<option value="">sama kwota, bez dopisku</option>
					<?php foreach ( $jednostki as $klucz => $nazwy ) : ?>
					<option value="<?php echo esc_attr( $klucz ); ?>" <?php selected( $jednostka, $klucz ); ?>><?php echo esc_html( $nazwy[0] ); ?></option>
					<?php endforeach; ?>
					<option value="_wlasna" <?php selected( $wlasna ); ?>>inna (wpisz poniżej)</option>
				</select>
				<input type="text" id="kw-jednostka-wlasna" name="kw_cena_jednostka_wlasna" maxlength="40" value="<?php echo esc_attr( $wlasna ? $jednostka : '' ); ?>" placeholder="np. wniosek" aria-label="Własna jednostka, np. wniosek" aria-describedby="kw-jednostka-wlasna-blad" data-kw-jednostka-wlasna>
				<?php echo kw_uslugi_kreator_pomoc( 'kw-jednostka-wlasna' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr. ?>
			</div>
			<div class="kw-pole c3" role="radiogroup" aria-labelledby="kw-netto-h" data-kw-typy="stala od przedzial" data-kw-cel="cena">
				<?php $etg( 'kw-netto-h', 'Kwota jest' ); ?>
				<div class="kw-segment">
					<input type="radio" id="kw-netto-1" name="kw_cena_netto" value="1" <?php checked( $netto ); ?>><label for="kw-netto-1">netto</label>
					<input type="radio" id="kw-netto-0" name="kw_cena_netto" value="0" <?php checked( ! $netto ); ?>><label for="kw-netto-0">brutto</label>
				</div>
			</div>

			<div class="kw-pole c6" data-kw-typy="opis" data-kw-cel="cena">
				<label for="kw-cena-opis">Tekst zamiast kwoty</label>
				<input type="text" id="kw-cena-opis" name="kw_cena_opis" maxlength="200" value="<?php echo esc_attr( kw_uslugi_czysc_opis_ceny( $meta( 'kw_cena_opis' ) ) ); ?>" placeholder="np. Równowartość ostatniej faktury miesięcznej" aria-describedby="kw-cena-opis-pomoc kw-cena-opis-blad">
				<?php echo kw_uslugi_kreator_pomoc( 'kw-cena-opis', 'Krótkie zdanie, które stanie na karcie w miejscu ceny.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>

			<p class="kw-wynik c12"><span class="kw-wynik__et">Na karcie w cenniku:</span> <strong data-kw-cena-tekst><?php echo esc_html( kw_uslugi_cena( kw_uslugi_z_posta( $post ) )['tekst'] ); ?></strong><span class="kw-wynik__uwaga" data-kw-cena-uwaga hidden></span></p>
		</div></section>

		<?php $kafel( 'progi', 'Progi cenowe', 'Cena zależna od liczby dokumentów w miesiącu. Tabela liczb albo oś z suwakami.', 'progi', 'c6', false, 'stala od przedzial' ); ?>
			<div class="kw-pole c12 kw-progi-pole" data-kw-cel="progi">
				<?php $etg( 'kw-progi-h', 'Progi cenowe: cena zależna od liczby dokumentów', "Próg to przedział liczby dokumentów w miesiącu i jego cena, np. 1-10 dokumentów: 500 zł, 11-30 dokumentów: 950 zł.\nKlient widzi tabelę progów po kliknięciu „Progi cenowe i szczegóły” na karcie.\nGdy usługa ma jedną cenę dla wszystkich, zostaw progi puste.", true ); ?>
				<p class="kw-pomoc" id="kw-progi-pomoc">Jeden wiersz to jeden próg: liczba dokumentów w miesiącu (np. 11-20, ostatni „201 i więcej”) i cena.</p>
				<div class="kw-progi" role="group" aria-labelledby="kw-progi-h" aria-describedby="kw-progi-pomoc" data-kw-progi-bezjs>
					<div class="kw-progi__glowa"><span class="kw-prog__wybor"></span><span>Dokumenty w miesiącu</span><span>Cena</span><span class="kw-progi__od-h">Cena „od”<?php echo kw_uslugi_dymek( 'k-prog-od', "Zaznacz przy ostatnim progu, który nie ma górnej granicy, np. „201 i więcej”.\nKlient zobaczy wtedy „od 3500 zł” zamiast „3500 zł”.", 'Cena „od” przy progu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i esc_html. ?></span><span></span></div>
					<div class="kw-progi__wiersze" data-kw-progi>
						<?php
						// Bez JavaScriptu: dwa puste wiersze na nowe progi (puste przy zapisie odpadaja). Skrypt czyta wiersze do
						// swojego modelu, zdejmuje je i sam wysyla progi w tych samych polach kw_progi[i][...].
						foreach ( array_merge( $progi, array( array(), array() ) ) as $nr => $prog ) {
							echo kw_uslugi_kreator_prog( $nr, $prog ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr.
						}
						?>
					</div>
				</div>
				<div data-kw-progi-app></div>
			</div>
		</div></section>

		<?php $kafel( 'zakres', 'Co jest w cenie', 'Krótkie listy. Klient porównuje zakres, nie tylko kwotę.', 'wcenie', 'c6' ); ?>
			<p class="kw-pomoc kw-wykazy__bezjs c12">W każdym polu jedna pozycja w wierszu. Puste pole nie pokaże się na stronie.</p>
			<div class="kw-wykazy listy c12">
				<?php foreach ( $listy as $klucz => $lista ) : ?>
				<div class="kw-pole kw-wykaz kw-wykaz--<?php echo esc_attr( $lista[1] ); ?>" data-kw-lista="<?php echo esc_attr( $klucz ); ?>" data-kw-nazwa="<?php echo esc_attr( $lista[0] ); ?>" data-kw-znak="<?php echo esc_attr( $lista[1] ); ?>" data-kw-cel="<?php echo esc_attr( $lista[4] ); ?>">
					<div class="kw-etykieta-rzad"><label for="<?php echo esc_attr( $klucz ); ?>" id="<?php echo esc_attr( $klucz ); ?>-et"><?php echo esc_html( $lista[0] ); ?></label><?php echo kw_uslugi_dymek( 'k-' . $klucz, $lista[3], $lista[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i esc_html. ?></div>
					<p class="kw-pomoc" id="<?php echo esc_attr( $klucz ); ?>-pomoc"><?php echo esc_html( $lista[2] ); ?></p>
					<textarea id="<?php echo esc_attr( $klucz ); ?>" name="<?php echo esc_attr( $klucz ); ?>" rows="6" aria-describedby="<?php echo esc_attr( $klucz ); ?>-pomoc"><?php echo esc_textarea( kw_uslugi_czysc_liste( $meta( $klucz ) ) ); ?></textarea>
				</div>
				<?php endforeach; ?>
			</div>
			<p class="kw-podpowiedz c12" data-kw-rada hidden></p>
		</div></section>

		<?php $kafel( 'widocznosc', 'Widoczność i miejsce', 'Gdzie usługa się pokazuje i na którym miejscu stoi w swojej zakładce.', 'widocznosc', 'c6' ); ?>
			<div class="kw-pole c6" role="group" aria-labelledby="kw-gdzie-h" data-kw-cel="widocznosc">
				<?php $etg( 'kw-gdzie-h', 'Gdzie klient widzi tę usługę' ); ?>
				<div class="kw-wybor-rzad"><label class="kw-wybor" for="kw-cennik-flaga"><input type="checkbox" id="kw-cennik-flaga" name="kw_w_cenniku" value="1" <?php checked( '0' !== (string) $meta( 'kw_w_cenniku' ) ); ?>> <span>W cenniku na stronie</span></label><?php echo kw_uslugi_dymek( 'k-cennik-flaga', "Zaznaczone: klienci widzą kartę tej usługi w cenniku na stronie.\nOdznaczone: karta znika z cennika, ale usługa zostaje tutaj w panelu i można ją włączyć z powrotem.", 'W cenniku na stronie' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i esc_html. ?></div>
				<div class="kw-wybor-rzad"><label class="kw-wybor" for="kw-formularz-flaga"><input type="checkbox" id="kw-formularz-flaga" name="kw_w_formularzu" value="1" <?php checked( '0' !== (string) $meta( 'kw_w_formularzu' ) ); ?>> <span>W polu „Usługa” formularza kontaktowego</span></label><?php echo kw_uslugi_dymek( 'k-formularz-flaga', "Zaznaczone: klient może wybrać tę usługę z listy w formularzu „Zapytaj o wycenę”.\nOdznaczone: nie ma jej na tej liście (karta w cenniku zależy od pola wyżej).", 'W polu Usługa formularza kontaktowego' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i esc_html. ?></div>
				<p class="kw-pomoc">Odznacz oba, żeby schować usługę bez usuwania jej.</p>
			</div>
			<div class="kw-pole c6" data-kw-cel="wyroznienie">
				<?php $et( 'kw-wyroznienie', 'Wyróżnienie', "Krótka etykieta nad nazwą usługi, np. „Dla spółek z o.o.”.\nKarta z wyróżnieniem dostaje złotą linię u góry i pełny złoty przycisk, więc odcina się od pozostałych.\nUżywaj oszczędnie: jedna albo dwie usługi w zakładce.", true ); ?>
				<input type="text" id="kw-wyroznienie" name="kw_wyroznienie" maxlength="40" value="<?php echo esc_attr( kw_uslugi_czysc_wyroznienie( $meta( 'kw_wyroznienie' ) ) ); ?>" placeholder="np. Dla spółek z o.o." aria-describedby="kw-wyroznienie-pomoc">
				<?php echo kw_uslugi_kreator_pomoc( 'kw-wyroznienie', 'Puste pole = karta bez wyróżnienia.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>
			<div class="kw-pole kw-pole--kolejnosc c6" data-kw-cel="kolejnosc">
				<?php $et( 'kw-kolejnosc', 'Kolejność w zakładce', "Karty w zakładce cennika stoją od najmniejszej liczby do największej.\nLiczby z odstępem (110, 120, 130) pozwalają później wstawić usługę pomiędzy, np. 115.\nZe skryptem zamiast liczby widzisz miejsce w zakładce i przesuwasz usługę strzałkami." ); ?>
				<input type="number" id="kw-kolejnosc" name="menu_order" step="1" value="<?php echo (int) $post->menu_order; ?>" aria-describedby="kw-kolejnosc-pomoc">
				<?php echo kw_uslugi_kreator_pomoc( 'kw-kolejnosc', 'Mniejsza liczba = wyżej w cenniku.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
				<div class="miejsce" data-kw-miejsce hidden></div>
			</div>
		</div></section>

		<?php $kafel( 'opis', 'Dodatkowy opis', 'Akapit na końcu szczegółów karty.', 'opis', 'c12', true ); ?>
			<div class="kw-pole c8" data-kw-cel="opis">
				<?php $et( 'kw-opis', 'Akapit w szczegółach usługi', "Dowolny tekst, np. warunki albo wyjaśnienie, czego potrzebujesz od klienta.\nKlient widzi go na końcu rozwiniętych szczegółów karty, pod listami." ); ?>
				<textarea id="kw-opis" name="content" rows="5" aria-describedby="kw-opis-pomoc"><?php echo esc_textarea( $post->post_content ); ?></textarea>
				<?php echo kw_uslugi_kreator_pomoc( 'kw-opis', 'Klient zobaczy go po rozwinięciu szczegółów, pod listami. Pusty wiersz zaczyna nowy akapit. Możesz zostawić puste.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr i wp_kses. ?>
			</div>
		</div></section>
	</div>

	<div class="kw-pasek" data-kw-pasek hidden>
		<p class="kw-pasek__cena"><span class="screen-reader-text">Cena na karcie: </span><strong data-kw-pasek-cena></strong></p>
		<button type="button" class="button" data-kw-pokaz-podglad>Podgląd</button>
		<button type="button" class="button button-primary" data-kw-zapisz>Zapisz</button>
	</div>
</div>
	<?php
}
add_action( 'edit_form_after_title', 'kw_uslugi_kreator' );

/**
 * Skrzynka obok kreatora: gdzie widac usluge i podglad karty cennika. Karte z bazy sklada PHP; skrypt przebudowuje
 * ja z pol formularza przy kazdej zmianie (bez zapisu).
 *
 * @param WP_Post $post Wpis.
 */
function kw_uslugi_kreator_podglad( $post ) {
	$gdzie = kw_uslugi_kreator_gdzie( $post->post_status, '0' !== (string) get_post_meta( $post->ID, 'kw_w_cenniku', true ), '0' !== (string) get_post_meta( $post->ID, 'kw_w_formularzu', true ) );
	?>
<div class="kw-podglad">
	<p class="kw-podglad__gdzie"><span class="kwa-znak<?php echo '' !== $gdzie[1] ? ' kwa-znak--' . esc_attr( $gdzie[1] ) : ''; ?>" data-kw-gdzie><?php echo esc_html( $gdzie[0] ); ?></span></p>
	<div class="kw-podglad__ramka">
		<div class="kw-cennik"><div class="kw-c__lista" role="list" data-kw-podglad><?php echo kw_uslugi_cennik_karta( kw_uslugi_z_posta( $post ), (bool) kw_uslugi_opcje()['ceny'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML zlozony w widok.php z esc_html/esc_attr. ?></div></div>
	</div>
	<p class="kw-podglad__wskazowka" data-kw-wskazowka hidden></p>
	<p class="kw-pomoc" data-kw-podglad-opis>Tak karta wygląda na telefonie. Pokazuje stan zapisany.</p>
	<p class="kw-podglad__link"><a href="<?php echo esc_url( home_url( '/#cennik' ) ); ?>" target="_blank" rel="noopener">Otwórz cennik na stronie<span class="screen-reader-text"> (nowa karta)</span></a></p>
</div>
	<?php
}

/**
 * Odnosnik wtyczki do kopiowania wpisow ("Duplicate This") po polsku na ekranach uslug.
 *
 * @param string $tlumaczenie Tekst po tlumaczeniu.
 * @param string $tekst       Tekst zrodlowy.
 * @param string $domena      Domena tlumaczenia.
 * @return string
 */
function kw_uslugi_kopia_nazwa( $tlumaczenie, $tekst, $domena ) {
	if ( 'duplicate-page' !== $domena || ! in_array( $tekst, array( 'Duplicate This', 'Duplicate this as ' ), true ) ) {
		return $tlumaczenie;
	}
	$ekran = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	// Tylko ekran edycji: na liscie nazwe odnosnika ustawia kw_uslugi_lista_akcje() ("Zrób kopię").
	if ( ! $ekran || 'kw_usluga' !== $ekran->post_type || 'post' !== $ekran->base ) {
		return $tlumaczenie;
	}
	return 'Duplicate This' === $tekst ? 'Zrób kopię tej usługi' : 'Zrób kopię jako ';
}
add_filter( 'gettext', 'kw_uslugi_kopia_nazwa', 10, 3 );

/**
 * Komunikaty po zapisie uslugi: po polsku i o usludze, nie o "wpisie".
 *
 * @param array $komunikaty Komunikaty typow tresci.
 * @return array
 */
function kw_uslugi_kreator_komunikaty( $komunikaty ) {
	$cennik                  = ' <a href="' . esc_url( home_url( '/#cennik' ) ) . '" target="_blank" rel="noopener">Zobacz cennik na stronie</a>';
	$zapisano                = 'Usługa zapisana. Strona pokazuje już aktualne dane.' . $cennik;
	$komunikaty['kw_usluga'] = array(
		0  => '',
		1  => $zapisano,
		2  => 'Usługa zapisana.',
		3  => 'Usługa zapisana.',
		4  => $zapisano,
		5  => false,
		6  => 'Usługa opublikowana: jest już na stronie.' . $cennik,
		7  => 'Usługa zapisana.',
		8  => 'Usługa czeka na przejrzenie.',
		9  => 'Publikacja usługi jest zaplanowana.',
		10 => 'Szkic zapisany. Usługi nie widać na stronie, dopóki jej nie opublikujesz.',
	);
	return $komunikaty;
}
add_filter( 'post_updated_messages', 'kw_uslugi_kreator_komunikaty' );

/**
 * Zapis pol kreatora (nazwe, krotki opis, dodatkowy opis, kolejnosc i kategorie zapisuje WordPress: to jego pola).
 * Kazda wartosc przechodzi przez sanityzacje swojego pola (kw_uslugi_pola), niezaleznie od tego, co wyslala przegladarka.
 *
 * @param int $post_id ID wpisu.
 */
function kw_uslugi_zapisz( $post_id ) {
	if ( ! isset( $_POST['kw_usluga_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kw_usluga_nonce'] ) ), 'kw_usluga_zapis' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$pola = kw_uslugi_pola();
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanityzacja nizej, funkcja przypisana do pola.
	$wejscie = static function ( $klucz ) {
		return isset( $_POST[ $klucz ] ) ? wp_unslash( $_POST[ $klucz ] ) : '';
	};
	// phpcs:enable
	$jednostka = $wejscie( 'kw_cena_jednostka' );
	$wartosci  = array(
		'kw_cena_jednostka' => '_wlasna' === $jednostka ? $wejscie( 'kw_cena_jednostka_wlasna' ) : $jednostka,
		// Netto / brutto: dwa pola wyboru z wartoscia 1 albo 0; brak pola w zadaniu = brutto (jak odznaczone pole wyboru).
		'kw_cena_netto'     => isset( $_POST['kw_cena_netto'] ) ? $wejscie( 'kw_cena_netto' ) : '0',
		// Pola wyboru: brak w zadaniu = odznaczone.
		'kw_w_cenniku'      => isset( $_POST['kw_w_cenniku'] ) ? '1' : '0',
		'kw_w_formularzu'   => isset( $_POST['kw_w_formularzu'] ) ? '1' : '0',
	);
	// Pola starszych shortcodow nie maja juz kontrolek w kreatorze: brak w zadaniu = zostaje wartosc z bazy.
	$starsze = array( 'kw_ikona', 'kw_na_stronie', 'kw_link' );
	foreach ( $pola as $klucz => $pole ) {
		if ( in_array( $klucz, $starsze, true ) && ! isset( $_POST[ $klucz ] ) ) {
			continue;
		}
		$wartosc = array_key_exists( $klucz, $wartosci ) ? $wartosci[ $klucz ] : $wejscie( $klucz );
		update_post_meta( $post_id, $klucz, wp_slash( call_user_func( $pole[0], $wartosc ) ) );
	}
	update_post_meta( $post_id, 'kw_progi', wp_slash( kw_uslugi_czysc_progi( $wejscie( 'kw_progi' ) ) ) );
}
add_action( 'save_post_kw_usluga', 'kw_uslugi_zapisz' );

/**
 * Style i skrypty panelu: tylko na ekranach uslug, kategorii, wiadomosci i w Ustawienia -> Uslugi.
 */
function kw_uslugi_admin_zasoby() {
	$ekran = get_current_screen();
	if ( ! kw_uslugi_ekran_panelu( $ekran ) ) {
		return;
	}
	wp_enqueue_style( 'kw-uslugi', plugins_url( 'assets/kw-uslugi.css', KW_USLUGI_PLIK ), array(), (string) filemtime( KW_USLUGI_KATALOG . 'assets/kw-uslugi.css' ) );
	wp_enqueue_style( 'kw-uslugi-admin', plugins_url( 'assets/kw-admin.css', KW_USLUGI_PLIK ), array( 'kw-uslugi' ), (string) filemtime( KW_USLUGI_KATALOG . 'assets/kw-admin.css' ) );

	wp_enqueue_script( 'kw-uslugi-dymki', plugins_url( 'assets/kw-admin-dymki.js', KW_USLUGI_PLIK ), array(), (string) filemtime( KW_USLUGI_KATALOG . 'assets/kw-admin-dymki.js' ), true );

	$post = get_post();
	if ( 'kw_usluga' === $ekran->post_type && 'post' === $ekran->base && $post instanceof WP_Post ) {
		// Ekran uslugi i podglad karty: IBM Plex Sans (400, 500, 600) i EB Garamond jak na stronie; na stronie decyduje motyw.
		wp_enqueue_style( 'kw-uslugi-czcionki', 'https://fonts.googleapis.com/css?family=IBM+Plex+Sans:400,500,600|EB+Garamond:600&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- arkusz Google Fonts.
		wp_enqueue_script( 'kw-uslugi-admin', plugins_url( 'assets/kw-admin.js', KW_USLUGI_PLIK ), array(), (string) filemtime( KW_USLUGI_KATALOG . 'assets/kw-admin.js' ), true );
		wp_add_inline_script( 'kw-uslugi-admin', 'window.kwKreatorDane = ' . wp_json_encode( kw_uslugi_kreator_dane( $post ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		// Autozapis WordPressa zna tylko tytul, tresc i zajawke po identyfikatorach rdzenia: przy szkicu nadpisalby
		// krotki opis pustym tekstem, a pol ceny i tak nie obejmuje. Kreator sam ostrzega o niezapisanych zmianach.
		wp_dequeue_script( 'autosave' );
	}
}
add_action( 'admin_enqueue_scripts', 'kw_uslugi_admin_zasoby' );

/* ---------------------------------------------------------------------------
 * Ustawienia -> Uslugi
 * ------------------------------------------------------------------------ */

/**
 * Sanityzacja opcji.
 *
 * @param mixed $wejscie Dane z formularza.
 * @return array
 */
function kw_uslugi_czysc_opcje( $wejscie ) {
	$stare   = kw_uslugi_opcje();
	$wejscie = is_array( $wejscie ) ? $wejscie : array();
	$email   = isset( $wejscie['email'] ) ? sanitize_email( $wejscie['email'] ) : '';
	$telefon = isset( $wejscie['telefon'] ) ? trim( (string) preg_replace( '/[^0-9+() -]/', '', (string) $wejscie['telefon'] ) ) : '';

	if ( ! is_email( $email ) ) {
		add_settings_error( 'kw_uslugi_opcje', 'kw_email', 'Adres e-mail odbiorcy jest niepoprawny. Został poprzedni adres.' );
		$email = $stare['email'];
	}
	if ( strlen( (string) preg_replace( '/\D/', '', $telefon ) ) < 7 ) {
		add_settings_error( 'kw_uslugi_opcje', 'kw_telefon', 'Numer telefonu jest niepoprawny. Został poprzedni numer.' );
		$telefon = $stare['telefon'];
	}
	return array(
		'ceny'     => empty( $wejscie['ceny'] ) ? 0 : 1,
		'email'    => $email,
		'telefon'  => mb_substr( $telefon, 0, 30 ),
		// Miesiace przechowywania wiadomosci: liczba od 0 do 120; brak pola w zadaniu = poprzednia wartosc, tekst zamiast liczby = 0 (nie usuwaj).
		'retencja' => isset( $wejscie['retencja'] ) && is_scalar( $wejscie['retencja'] ) ? max( 0, min( 120, (int) $wejscie['retencja'] ) ) : (int) $stare['retencja'],
	);
}

/**
 * Rejestracja opcji i strony ustawien.
 */
function kw_uslugi_ustawienia_init() {
	register_setting(
		'kw_uslugi',
		'kw_uslugi_opcje',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'kw_uslugi_czysc_opcje',
		)
	);
}
add_action( 'admin_init', 'kw_uslugi_ustawienia_init' );

/**
 * Pozycje menu: Ustawienia -> Uslugi oraz skrot w menu Uslugi.
 */
function kw_uslugi_menu() {
	add_options_page( 'Usługi i formularz', 'Usługi', 'manage_options', 'kw-uslugi', 'kw_uslugi_strona_ustawien' );
	add_submenu_page( 'edit.php?post_type=kw_usluga', 'Ustawienia', 'Ustawienia', 'manage_options', 'options-general.php?page=kw-uslugi' );
}
add_action( 'admin_menu', 'kw_uslugi_menu' );

/**
 * Menu "Poprawki" (osobna wtyczka Website Corrector by INYFINN, jeśli jest zainstalowana) staje bezpośrednio pod "Usługi"
 * (typ wpisu kw_usluga ma pozycję 21). Sam filtr, bez zależności między wtyczkami: gdy tamtej nie ma, nic się nie dzieje.
 *
 * @return float
 */
function kw_uslugi_pozycja_poprawek() {
	return 21.1;
}
add_filter( 'iwc_menu_position', 'kw_uslugi_pozycja_poprawek' );

/**
 * Strona ustawien.
 */
function kw_uslugi_strona_ustawien() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opcje = kw_uslugi_opcje();
	?>
<div class="wrap">
	<h1>Usługi i formularz</h1>
	<form method="post" action="options.php">
		<?php settings_fields( 'kw_uslugi' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Ceny</th>
				<td>
					<label for="kw-opcja-ceny"><input type="checkbox" id="kw-opcja-ceny" name="kw_uslugi_opcje[ceny]" value="1" <?php checked( $opcje['ceny'], 1 ); ?>> Pokazuj ceny na stronie</label>
					<p class="description">Wyłączone: ceny i progi wpisane przy usługach zostają zapisane, ale nikt ich nie widzi. Cennik pokazuje wtedy same usługi z zakresem.</p>
					<p class="description">Uwaga: znikają tylko pola ceny i progi. Kwoty wpisane ręcznie w listach usługi, np. „kadry i płace (50 zł netto za pracownika)”, zostają na stronie: przed wyłączeniem cen popraw takie pozycje w usługach.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="kw-opcja-email">E-mail odbiorcy formularza</label></th>
				<td>
					<input type="email" id="kw-opcja-email" name="kw_uslugi_opcje[email]" class="regular-text" value="<?php echo esc_attr( $opcje['email'] ); ?>" required>
					<p class="description">Na ten adres trafiają wiadomości z formularza. Ten sam adres widzi klient w podpowiedzi pod polem „Wiadomość” i w trybie awaryjnym.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="kw-opcja-telefon">Telefon awaryjny</label></th>
				<td>
					<input type="tel" id="kw-opcja-telefon" name="kw_uslugi_opcje[telefon]" class="regular-text" value="<?php echo esc_attr( $opcje['telefon'] ); ?>" required>
					<p class="description">Numer pokazywany klientowi pod polem „Wiadomość”.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="kw-opcja-retencja">Jak długo trzymać wiadomości od klientów</label></th>
				<td>
					<input type="number" id="kw-opcja-retencja" name="kw_uslugi_opcje[retencja]" class="small-text" min="0" max="120" step="1" value="<?php echo esc_attr( (string) $opcje['retencja'] ); ?>" aria-describedby="kw-opcja-retencja-opis kw-opcja-retencja-stan"> miesięcy
					<p class="description" id="kw-opcja-retencja-opis">Wiadomość z formularza znika z panelu na stałe (także z kosza), gdy minie tyle miesięcy od dnia, w którym przyszła. Polityka prywatności strony mówi o 12 miesiącach. Wiadomość oznaczona „Zachowaj (klient)” zostaje. 0 = niczego nie usuwaj.</p>
					<p class="description" id="kw-opcja-retencja-stan"><?php echo esc_html( kw_uslugi_retencja_stan_tekst() ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>

	<?php kw_uslugi_import_sekcja(); ?>

	<h2>Jak to działa</h2>
	<ul class="ul-disc">
		<li>Usługi, ceny i zakresy ustawiasz w menu <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=kw_usluga' ) ); ?>">Usługi</a>. Na stronie zmieniają się same, bez edycji stron.</li>
		<li>Zakładki cennika to <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=kw_grupa&post_type=kw_usluga' ) ); ?>">Kategorie</a>; ich kolejność ustawia pole „Kolejność zakładki”.</li>
		<li>Ceny wielu usług naraz zmienisz wyżej, w części „Import cen z cennika”.</li>
		<li>Kopia każdej wiadomości od klienta zostaje w menu Usługi, pozycja <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=kw_wiadomosc' ) ); ?>">Wiadomości z formularza</a>, także wtedy, gdy e-mail nie wyjdzie.</li>
		<li>Gdy wysyłka wiadomości się nie uda, klient nie traci tego, co napisał: treść trafia do schowka, a przycisk „Wyślij ponownie” otwiera jego program pocztowy.</li>
	</ul>

	<details class="kw-ust-kody">
		<summary>Dla osoby, która buduje stronę: kody do wstawienia</summary>
		<ul class="ul-disc">
			<li><code>[kw_cennik]</code>: cennik z zakładkami kategorii. <code>[kw_cennik grupa="jdg"]</code> pokazuje tylko jedną kategorię.</li>
			<li><code>[kw_progi slug="ryczalt-bez-vat"]</code>: progi cenowe jednej usługi jako prosta tabela (do wykresów i zestawień na stronie; liczby zawsze z cennika).</li>
			<li><code>[kw_formularz]</code>: formularz kontaktowy z polami „Usługa”, „Liczba dokumentów w miesiącu” i „Liczba pracowników”.</li>
			<li>Odnośnik <code>#cennik-jdg</code> (adres zakładki widać na ekranie edycji kategorii) otwiera zakładkę. Przycisk z odnośnikiem <code>#kontakt</code> i atrybutem <code>data-kw-usluga="kpir"</code> albo odnośnik <code>#kontakt-usluga-kpir</code> przenosi do formularza z wybraną usługą.</li>
			<li>Podgląd trybu awaryjnego formularza (tylko dla zalogowanego administratora): dopisz <code>?kw_test_awarii=1</code> do adresu strony z formularzem i wyślij formularz.</li>
			<li>Starsze kody <code>[kw_uslugi]</code> i <code>[kw_uslugi_lista]</code> nadal działają.</li>
		</ul>
	</details>
</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * Wiadomosci z formularza (kopie)
 * ------------------------------------------------------------------------ */

/**
 * Kolumny listy wiadomosci.
 *
 * @return array
 */
function kw_uslugi_wiadomosci_kolumny() {
	return array(
		'cb'          => '<input type="checkbox">',
		'title'       => 'Od kogo i w jakiej sprawie',
		'kw_email'    => 'E-mail',
		'kw_telefon'  => 'Telefon',
		'kw_wysylka'  => 'E-mail do biura',
		'date'        => 'Data',
		'kw_retencja' => 'Zniknie sama',
	);
}
add_filter( 'manage_kw_wiadomosc_posts_columns', 'kw_uslugi_wiadomosci_kolumny' );

/**
 * Znacznik stanu wysylki.
 *
 * @param int $post_id ID wiadomosci.
 * @return string HTML.
 */
function kw_uslugi_stan_wysylki( $post_id ) {
	return '1' === get_post_meta( $post_id, '_kw_wyslano', true )
		? '<span class="kw-stan kw-stan--ok">wysłany</span>'
		: '<span class="kw-stan kw-stan--blad">NIE wysłany</span>';
}

/**
 * Tresc kolumn listy wiadomosci.
 *
 * @param string $kolumna Kolumna.
 * @param int    $post_id ID wiadomosci.
 */
function kw_uslugi_wiadomosci_kolumna( $kolumna, $post_id ) {
	if ( 'kw_email' === $kolumna ) {
		$email = (string) get_post_meta( $post_id, '_kw_email', true );
		echo is_email( $email ) ? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' : esc_html( $email );
	} elseif ( 'kw_telefon' === $kolumna ) {
		echo esc_html( (string) get_post_meta( $post_id, '_kw_telefon', true ) );
	} elseif ( 'kw_wysylka' === $kolumna ) {
		echo kw_uslugi_stan_wysylki( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- staly HTML.
	} elseif ( 'kw_retencja' === $kolumna ) {
		echo esc_html( kw_uslugi_retencja_opis( $post_id ) );
	}
}
add_action( 'manage_kw_wiadomosc_posts_custom_column', 'kw_uslugi_wiadomosci_kolumna', 10, 2 );

/**
 * Wiadomosci nie edytuje sie w miejscu: zostaje otwarcie i kosz.
 *
 * @param array   $akcje Akcje wiersza.
 * @param WP_Post $post  Wpis.
 * @return array
 */
function kw_uslugi_wiadomosci_akcje( $akcje, $post ) {
	if ( 'kw_wiadomosc' === $post->post_type ) {
		// Zostaja tylko: otworz, kosz, przywroc, usun. Bez szybkiej edycji i bez "duplikuj" z innych wtyczek.
		$akcje = array_intersect_key( $akcje, array_flip( array( 'edit', 'trash', 'untrash', 'delete' ) ) );
		if ( isset( $akcje['edit'] ) ) {
			$akcje['edit'] = '<a href="' . esc_url( (string) get_edit_post_link( $post->ID ) ) . '">Otwórz</a>';
		}
		if ( current_user_can( 'manage_options' ) ) {
			$akcje['kw_zachowaj'] = kw_uslugi_zachowaj_odnosnik( $post->ID );
		}
	}
	return $akcje;
}
add_filter( 'post_row_actions', 'kw_uslugi_wiadomosci_akcje', 999, 2 );

/**
 * Skrzynka z trescia wiadomosci (tylko odczyt). Otwarcie oznacza wiadomosc jako przeczytana.
 *
 * @param WP_Post $post Wiadomosc.
 */
function kw_uslugi_wiadomosc_skrzynka( $post ) {
	$meta = static function ( $klucz ) use ( $post ) {
		return (string) get_post_meta( $post->ID, $klucz, true );
	};
	if ( '' === $meta( '_kw_przeczytane' ) ) {
		update_post_meta( $post->ID, '_kw_przeczytane', (string) time() );
	}
	$email = $meta( '_kw_email' );
	?>
<div class="kw-wiadomosc">
	<dl>
		<dt>Imię</dt><dd><?php echo esc_html( $meta( '_kw_imie' ) ); ?></dd>
		<dt>E-mail</dt><dd><?php echo is_email( $email ) ? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' : esc_html( $email ); ?></dd>
		<dt>Telefon</dt><dd><?php echo esc_html( '' !== $meta( '_kw_telefon' ) ? $meta( '_kw_telefon' ) : 'nie podano' ); ?></dd>
		<dt>Usługa</dt><dd><?php echo esc_html( $meta( '_kw_usluga' ) ); ?></dd>
		<?php foreach ( kw_uslugi_pola_wyceny() as $nazwa => $definicja ) : ?>
		<dt><?php echo esc_html( $definicja[0] ); ?></dt><dd><?php echo esc_html( '' !== $meta( '_kw_' . $nazwa ) ? $meta( '_kw_' . $nazwa ) : 'nie podano' ); ?></dd>
		<?php endforeach; ?>
		<dt>Data</dt><dd><?php echo esc_html( get_the_date( 'd.m.Y H:i', $post ) ); ?></dd>
		<dt>Strona</dt><dd><?php echo esc_html( $meta( '_kw_strona' ) ); ?></dd>
		<dt>E-mail do biura</dt><dd><?php echo kw_uslugi_stan_wysylki( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- staly HTML. ?><?php echo '' !== $meta( '_kw_blad' ) ? ' <span class="description">' . esc_html( $meta( '_kw_blad' ) ) . '</span>' : ''; ?></dd>
		<dt>Przechowywanie</dt><dd><?php echo esc_html( kw_uslugi_retencja_opis( $post->ID, true ) ); ?> <?php echo current_user_can( 'manage_options' ) ? kw_uslugi_zachowaj_odnosnik( $post->ID ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- odnosnik zlozony z esc_url i esc_html. ?></dd>
	</dl>
	<p class="kw-wiadomosc__tresc"><?php echo esc_html( $meta( '_kw_tresc' ) ); ?></p>
</div>
	<?php
}

/**
 * Jedno zdanie o przechowywaniu wiadomosci: kiedy zniknie sama albo ze jest zachowana.
 *
 * @param int  $post_id ID wiadomosci.
 * @param bool $pelne   Pelne zdanie (ekran wiadomosci) zamiast krotkiego (kolumna listy).
 * @return string
 */
function kw_uslugi_retencja_opis( $post_id, $pelne = false ) {
	if ( metadata_exists( 'post', (int) $post_id, '_kw_zachowaj' ) ) {
		return $pelne ? 'Zachowana (klient): nie zniknie sama.' : 'nie: zachowana (klient)';
	}
	$termin = kw_uslugi_retencja_termin( $post_id );
	if ( '' === $termin ) {
		return $pelne ? 'Automatyczne usuwanie jest wyłączone w Ustawieniach.' : 'nie: usuwanie wyłączone';
	}
	return $pelne ? 'Zniknie sama po ' . $termin . ' (tak mówi polityka prywatności).' : 'po ' . $termin;
}

/**
 * Odnosnik "Zachowaj (klient)" albo "Nie zachowuj" dla wiadomosci (admin-post.php, token na wpis i stan docelowy).
 *
 * @param int $post_id ID wiadomosci.
 * @return string HTML.
 */
function kw_uslugi_zachowaj_odnosnik( $post_id ) {
	$post_id = (int) $post_id;
	$stan    = metadata_exists( 'post', $post_id, '_kw_zachowaj' ) ? '0' : '1';
	$adres   = add_query_arg(
		array(
			'action'   => 'kw_uslugi_zachowaj',
			'id'       => $post_id,
			'stan'     => $stan,
			'_wpnonce' => wp_create_nonce( 'kw_zachowaj_' . $post_id . '_' . $stan ),
		),
		admin_url( 'admin-post.php' )
	);
	return '<a href="' . esc_url( $adres ) . '" title="' . esc_attr( '1' === $stan ? 'Ta wiadomość nie będzie usuwana automatycznie' : 'Wiadomość znów zniknie sama po ustawionym okresie' ) . '">' . esc_html( '1' === $stan ? 'Zachowaj (klient)' : 'Nie zachowuj' ) . '</a>';
}

/**
 * Klikniecie "Zachowaj (klient)" / "Nie zachowuj": zapis i powrot tam, skad przyszlo zadanie.
 */
function kw_uslugi_zachowaj_zadanie() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- token sprawdza kw_uslugi_zachowaj_ustaw().
	$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	$stan = isset( $_GET['stan'] ) ? sanitize_key( wp_unslash( $_GET['stan'] ) ) : '';
	$kod  = kw_uslugi_zachowaj_ustaw( $id, $stan, isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '' );
	// phpcs:enable
	$powrot = wp_get_referer();
	if ( ! $powrot || ( false === strpos( $powrot, 'kw_wiadomosc' ) && false === strpos( $powrot, 'post.php' ) ) ) {
		$powrot = admin_url( 'edit.php?post_type=kw_wiadomosc' );
	}
	wp_safe_redirect( add_query_arg( 'kw_zach', 'ok' === $kod ? ( '1' === $stan ? 'tak' : 'nie' ) : $kod, remove_query_arg( 'kw_zach', $powrot ) ) );
	exit;
}
add_action( 'admin_post_kw_uslugi_zachowaj', 'kw_uslugi_zachowaj_zadanie' );

/**
 * Komunikat po kliknieciu "Zachowaj (klient)" / "Nie zachowuj" (kod z adresu to klucz, nie tekst).
 */
function kw_uslugi_zachowaj_komunikat() {
	$ekran = get_current_screen();
	$kod   = isset( $_GET['kw_zach'] ) ? sanitize_key( wp_unslash( $_GET['kw_zach'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko odczyt kodu komunikatu.
	$teksty = array(
		'tak'   => array( 'success', 'Wiadomość jest zachowana: nie zniknie sama.' ),
		'nie'   => array( 'success', 'Wiadomość nie jest już zachowana: zniknie sama po ustawionym okresie.' ),
		'token' => array( 'error', 'Nic nie zmieniono: link wygasł. Odśwież stronę i spróbuj jeszcze raz.' ),
		'prawa' => array( 'error', 'Nic nie zmieniono: nie masz uprawnień do tej zmiany.' ),
		'brak'  => array( 'error', 'Nic nie zmieniono: nie znaleziono tej wiadomości.' ),
		'pole'  => array( 'error', 'Nic nie zmieniono: nieznana wartość.' ),
	);
	if ( ! $ekran || 'kw_wiadomosc' !== $ekran->post_type || ! isset( $teksty[ $kod ] ) ) {
		return;
	}
	printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $teksty[ $kod ][0] ), esc_html( $teksty[ $kod ][1] ) );
}
add_action( 'admin_notices', 'kw_uslugi_zachowaj_komunikat' );
add_filter(
	'removable_query_args',
	static function ( $parametry ) {
		$parametry[] = 'kw_zach';
		return $parametry;
	}
);

/**
 * Zdanie pod ustawieniem okresu: najstarsza wiadomosc (i kiedy zniknie) oraz ostatnie sprzatanie z dziennika.
 *
 * @return string
 */
function kw_uslugi_retencja_stan_tekst() {
	$najstarsza = kw_uslugi_retencja_wpisy( '', 1 );
	if ( $najstarsza ) {
		$termin = kw_uslugi_retencja_termin( $najstarsza[0] );
		$tekst  = 'Najstarsza wiadomość: ' . get_the_date( 'd.m.Y', $najstarsza[0] ) . ( '' !== $termin ? ' (zniknie po ' . $termin . ').' : ' (usuwanie wyłączone).' );
	} else {
		$tekst = 'Najstarsza wiadomość: nie ma teraz żadnej wiadomości do usunięcia.';
	}
	$dziennik = get_option( KW_USLUGI_RETENCJA_DZIENNIK );
	$wpisy    = is_array( $dziennik ) && ! empty( $dziennik['wpisy'] ) && is_array( $dziennik['wpisy'] ) ? $dziennik['wpisy'] : array();
	if ( $wpisy ) {
		$ostatni = end( $wpisy );
		$tekst  .= ' Ostatnie usuwanie: ' . wp_date( 'd.m.Y', (int) $ostatni['czas'] ) . ', usunięto: ' . (int) $ostatni['usuniete'] . '.';
	} else {
		$tekst .= ' Jak dotąd nic nie zostało usunięte automatycznie.';
	}
	return $tekst;
}

/**
 * Ostrzezenie dla administratora: sa wiadomosci, ktorych e-mail nie wyszedl, a nikt ich jeszcze nie otworzyl.
 */
function kw_uslugi_ostrzezenie() {
	$ekran = get_current_screen();
	if ( ! current_user_can( 'manage_options' ) || ! $ekran || ! ( 'dashboard' === $ekran->base || in_array( $ekran->post_type, array( 'kw_usluga', 'kw_wiadomosc' ), true ) ) ) {
		return;
	}
	// ponytail: liczenie przy kazdym wejsciu na te ekrany, bez pamieci podrecznej; przy tysiacach wiadomosci dodac licznik w opcji.
	$nieodebrane = get_posts(
		array(
			'post_type'      => 'kw_wiadomosc',
			'post_status'    => 'private',
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- maly zbior, tylko panel.
				array(
					'key'   => '_kw_wyslano',
					'value' => '0',
				),
				array(
					'key'     => '_kw_przeczytane',
					'compare' => 'NOT EXISTS',
				),
			),
		)
	);
	if ( ! $nieodebrane ) {
		return;
	}
	$ile = count( $nieodebrane );
	if ( 1 === $ile ) {
		$ile_tekst = 'wiadomość nie została wysłana e-mailem';
	} elseif ( $ile % 10 >= 2 && $ile % 10 <= 4 && ( $ile % 100 < 12 || $ile % 100 > 14 ) ) {
		$ile_tekst = 'wiadomości nie zostały wysłane e-mailem';
	} else {
		$ile_tekst = 'wiadomości nie zostało wysłanych e-mailem';
	}
	printf(
		'<div class="notice notice-error"><p><strong>Formularz kontaktowy:</strong> %1$d %2$s. Kopie są w panelu: <a href="%3$s">Wiadomości z formularza</a>.</p></div>',
		(int) $ile,
		esc_html( $ile_tekst ),
		esc_url( admin_url( 'edit.php?post_type=kw_wiadomosc' ) )
	);
}
add_action( 'admin_notices', 'kw_uslugi_ostrzezenie' );
