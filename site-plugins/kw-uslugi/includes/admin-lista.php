<?php
/**
 * Panel: lista uslug, kategorie (zakladki cennika) i porzadek ekranow uslug.
 *
 * Funkcje maja przedrostki kw_uslugi_lista_ (lista), kw_uslugi_kat_ (kategorie) albo sa starymi nazwami
 * (kw_uslugi_kolumny, kw_uslugi_kategoria_zapisz...), ktore zostaly po wycieciu z admin.php.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Porzadek ekranow: bez cudzych banerow, bez "Opcji ekranu" i "Pomocy"
 * ------------------------------------------------------------------------ */

/**
 * Czy to ekran uslug, kategorii uslug, wiadomosci z formularza, Ustawienia, Uslugi albo ekran wtyczki "Poprawki".
 * Na ekranach kategorii (edit-tags, term) decyduje taksonomia: adres z post_type=kw_usluga i inna taksonomia (np. category)
 * nie jest ekranem uslug, mimo ze WP_Screen bierze post_type z zadania.
 * (Komunikaty o zapisie ustawien drukuje rdzen przez settings_errors(), nie przez te akcje, wiec zostaja.)
 *
 * @param WP_Screen|null $ekran Ekran (domyslnie biezacy).
 * @return bool
 */
function kw_uslugi_lista_ekran( $ekran = null ) {
	$ekran = $ekran ? $ekran : ( function_exists( 'get_current_screen' ) ? get_current_screen() : null );
	if ( ! $ekran ) {
		return false;
	}
	if ( in_array( $ekran->base, array( 'edit-tags', 'term' ), true ) ) {
		return 'kw_grupa' === $ekran->taxonomy;
	}
	// Ekrany osobnej wtyczki "Poprawki" (Website Corrector by INYFINN): na tej stronie obowiązuje na nich ten sam porządek
	// komunikatów co na ekranach usług (reklamy znanych wtyczek znikają, ostrzeżenia rdzenia i bezpieczeństwa zostają).
	if ( 1 === preg_match( '/(^|_)page_iwc-(poprawki|ustawienia)$/', (string) $ekran->id ) ) {
		return true;
	}
	return in_array( $ekran->post_type, array( 'kw_usluga', 'kw_wiadomosc' ), true ) || 'settings_page_kw-uslugi' === $ekran->id;
}

/**
 * Wtyczki, ktorych komunikaty na ekranach uslug to reklamy i prosby o oceny (czarna lista, katalogi w wp-content/plugins).
 * Wszystko inne, takze nieznane nowe ostrzezenie, zostaje.
 *
 * @return string[]
 */
function kw_uslugi_lista_baner_wtyczki() {
	return (array) apply_filters(
		'kw_uslugi_lista_baner_wtyczki',
		array(
			'elementor',
			'elementor-pro',
			'essential-addons-for-elementor-lite',
			'essential-blocks',
			'wpforms-lite',
			'wpforms',
			'polylang',
			'polylang-pro',
			'inyfinn-cursor-bridge-mcp',
		)
	);
}

/**
 * Plik i nazwa funkcji, z ktorej pochodzi wywolanie podpiete pod akcje komunikatow (odbicie).
 *
 * @param callable|mixed $wywolanie Wywolanie z tablicy haczykow.
 * @return array{0:string,1:string} Sciezka pliku i nazwa ("funkcja" albo "Klasa::metoda"); puste, gdy nie da sie ustalic.
 */
function kw_uslugi_lista_baner_zrodlo( $wywolanie ) {
	$wynik = array( '', '' );
	try {
		if ( $wywolanie instanceof Closure ) {
			$wynik = array( (string) ( new ReflectionFunction( $wywolanie ) )->getFileName(), 'closure' );
		} elseif ( is_string( $wywolanie ) && false !== strpos( $wywolanie, '::' ) ) {
			list( $klasa, $metoda ) = explode( '::', $wywolanie, 2 );
			$wynik                  = array( (string) ( new ReflectionMethod( $klasa, $metoda ) )->getFileName(), $wywolanie );
		} elseif ( is_string( $wywolanie ) ) {
			$wynik = array( (string) ( new ReflectionFunction( $wywolanie ) )->getFileName(), $wywolanie );
		} elseif ( is_array( $wywolanie ) && 2 === count( $wywolanie ) ) {
			$klasa = is_object( $wywolanie[0] ) ? get_class( $wywolanie[0] ) : (string) $wywolanie[0];
			$wynik = array( (string) ( new ReflectionMethod( $klasa, (string) $wywolanie[1] ) )->getFileName(), $klasa . '::' . $wywolanie[1] );
		} elseif ( is_object( $wywolanie ) ) {
			$wynik = array( (string) ( new ReflectionMethod( get_class( $wywolanie ), '__invoke' ) )->getFileName(), get_class( $wywolanie ) . '::__invoke' );
		}
	} catch ( ReflectionException $e ) {
		$wynik = array( '', is_string( $wywolanie ) ? $wywolanie : '' ); // plik nieznany (np. funkcja jeszcze niezaladowana): zostaje, chyba ze nazwa jest na czarnej liscie
	}
	return (array) apply_filters( 'kw_uslugi_lista_baner_zrodlo', $wynik, $wywolanie );
}

/**
 * Czy komunikat o danym zrodle zdjac z ekranu uslug (czarna lista). Domyslnie NIE: zostaje.
 * Nigdy nie zdejmuje: funkcji rdzenia (wp-admin, wp-includes), znanych ostrzezen rdzenia i ostrzezen Wordfence o zaporze i skanerze.
 * Zdejmuje: komunikaty wtyczek z kw_uslugi_lista_baner_wtyczki() oraz baner "Instalacja Wordfence jest niekompletna".
 *
 * @param string $plik  Sciezka pliku funkcji.
 * @param string $nazwa Nazwa ("funkcja" albo "Klasa::metoda").
 * @return bool
 */
function kw_uslugi_lista_baner_do_zdjecia( $plik, $nazwa ) {
	$plik  = wp_normalize_path( (string) $plik );
	$nazwa = strtolower( (string) $nazwa );
	$nigdy = array(
		'update_nag',
		'maintenance_nag',
		'wp_recovery_mode_nag',
		'paused_plugins_notice',
		'paused_themes_notice',
		'deactivated_plugins_notice',
		'default_password_nag',
		'site_admin_notice',
		'wafreadonlynotice',
		'wafconfiginaccessiblenotice',
		'misconfiguredhowgetipsnotice',
	);
	$tylko_nazwa = false !== strpos( $nazwa, '::' ) ? substr( $nazwa, strpos( $nazwa, '::' ) + 2 ) : $nazwa;
	if ( 0 === strpos( $nazwa, 'kw_uslugi_' ) || in_array( $tylko_nazwa, $nigdy, true ) ) {
		return false;
	}
	foreach ( array( ABSPATH . 'wp-admin/', ABSPATH . 'wp-includes/' ) as $rdzen ) {
		if ( '' !== $plik && 0 === strpos( $plik, wp_normalize_path( $rdzen ) ) ) {
			return false;
		}
	}
	if ( 'wordfence::showonboardingbanner' === $nazwa ) {
		return true;
	}
	$korzen = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
	if ( '' !== $plik && 0 === strpos( $plik, $korzen ) ) {
		return in_array( strtok( substr( $plik, strlen( $korzen ) ), '/' ), kw_uslugi_lista_baner_wtyczki(), true );
	}
	return false;
}

/**
 * Z ekranow uslug znikaja reklamy i prosby o oceny znanych wtyczek (czarna lista, patrz kw_uslugi_lista_baner_do_zdjecia).
 * Zostaja: nasze komunikaty (funkcje kw_uslugi_*), komunikaty WordPressa o zapisie (drukuje je sam rdzen) oraz kazde
 * ostrzezenie, ktorego nie rozpoznajemy (rdzen, Wordfence o zaporze i skanerze, nowe wtyczki). Chowa tez "Opcje ekranu" i "Pomoc".
 * Dziala w in_admin_header: po tym, jak wtyczki dopiely swoje akcje, a przed wydrukiem komunikatow.
 * Akcja kw_uslugi_porzadek_decyzje dostaje liste decyzji (haczyk, nazwa, plik, zdjeta albo zostaje): do przegladu i samotestu.
 */
function kw_uslugi_porzadek_ekranu() {
	$ekran = get_current_screen();
	if ( ! kw_uslugi_lista_ekran( $ekran ) ) {
		return;
	}
	$decyzje = array();
	foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $haczyk ) {
		if ( empty( $GLOBALS['wp_filter'][ $haczyk ] ) ) {
			continue;
		}
		foreach ( $GLOBALS['wp_filter'][ $haczyk ]->callbacks as $priorytet => $funkcje ) {
			foreach ( $funkcje as $funkcja ) {
				$wywolanie     = $funkcja['function'];
				list( $plik, $nazwa ) = kw_uslugi_lista_baner_zrodlo( $wywolanie );
				$zdjac         = kw_uslugi_lista_baner_do_zdjecia( $plik, $nazwa );
				$decyzje[]     = array(
					'haczyk' => $haczyk,
					'nazwa'  => $nazwa,
					'plik'   => $plik,
					'wynik'  => $zdjac ? 'zdjeta' : 'zostaje',
				);
				if ( $zdjac ) {
					remove_action( $haczyk, $wywolanie, $priorytet );
				}
			}
		}
	}
	do_action( 'kw_uslugi_porzadek_decyzje', $decyzje );
	add_action( 'admin_notices', 'kw_uslugi_lista_komunikat' );
	$ekran->remove_help_tabs();
	$ekran->set_help_sidebar( '' );
	add_filter( 'screen_options_show_screen', '__return_false' );
}
add_action( 'in_admin_header', 'kw_uslugi_porzadek_ekranu', 99999 );

/**
 * Komunikat po akcji z listy (przelacznik widocznosci, przesuniecie, archiwum kategorii). Kod z adresu to klucz z listy
 * ponizej, a nie tekst: do strony nie trafia nic, czego nie zlozyl serwer. Po zmianie widocznosci i przesunieciu
 * komunikat ma przycisk "Cofnij" (to samo zabezpieczone zadanie, tylko w druga strone).
 */
function kw_uslugi_lista_komunikat() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- tylko odczyt kodu komunikatu, nic sie nie zapisuje.
	$kod = isset( $_GET['kw_info'] ) ? sanitize_key( wp_unslash( $_GET['kw_info'] ) ) : '';
	$id  = isset( $_GET['kw_id'] ) ? absint( $_GET['kw_id'] ) : 0;
	// phpcs:enable
	$komunikaty = array(
		'wl-cennik'       => array( 'success', '„%s” pokazuje się teraz w cenniku na stronie.' ),
		'wyl-cennik'      => array( 'success', '„%s” nie pokazuje się już w cenniku na stronie.' ),
		'wl-formularz'    => array( 'success', '„%s” pokazuje się teraz w polu „Usługa” formularza.' ),
		'wyl-formularz'   => array( 'success', '„%s” nie pokazuje się już w polu „Usługa” formularza.' ),
		'przesun-gora'    => array( 'success', '„%s” stoi teraz wyżej w zakładce cennika.' ),
		'przesun-dol'     => array( 'success', '„%s” stoi teraz niżej w zakładce cennika.' ),
		'kraniec'         => array( 'error', 'Nic nie zmieniono: ta usługa stoi już na brzegu zakładki.' ),
		'kat-archiwum'    => array( 'success', 'Kategoria „%s” trafiła do archiwum: nie ma jej już na liście wyboru przy usłudze. Strona się nie zmieniła.' ),
		'kat-przywrocona' => array( 'success', 'Kategoria „%s” wróciła z archiwum i znów jest na liście wyboru przy usłudze.' ),
		'token'           => array( 'error', 'Nic nie zmieniono: link wygasł. Odśwież listę i spróbuj jeszcze raz.' ),
		'prawa'           => array( 'error', 'Nic nie zmieniono: nie masz uprawnień do tej zmiany.' ),
		'pole'            => array( 'error', 'Nic nie zmieniono: nieznane pole.' ),
		'brak'            => array( 'error', 'Nic nie zmieniono: nie znaleziono tej pozycji.' ),
	);
	if ( ! isset( $komunikaty[ $kod ] ) ) {
		return;
	}
	list( $rodzaj, $tekst ) = $komunikaty[ $kod ];
	$nazwa                  = '';
	if ( 0 === strpos( $kod, 'kat-' ) ) {
		$termin = get_term( $id, 'kw_grupa' );
		$nazwa  = $termin instanceof WP_Term && current_user_can( 'manage_categories' ) ? $termin->name : '';
	} elseif ( $id && current_user_can( 'edit_post', $id ) ) {
		$nazwa = get_the_title( $id );
	}
	if ( false !== strpos( $tekst, '%s' ) && '' === $nazwa ) {
		return;
	}
	$cofnij = '';
	if ( $id && current_user_can( 'edit_post', $id ) ) {
		if ( preg_match( '/^(wl|wyl)-(cennik|formularz)$/', $kod, $m ) ) {
			$pole   = 'cennik' === $m[2] ? 'kw_w_cenniku' : 'kw_w_formularzu';
			$wracam = 'wl' === $m[1] ? '0' : '1'; // poprzedni stan to przeciwienstwo nowego
			$cofnij = kw_uslugi_lista_przycisk_cofnij( 'kw_przelacz', $id . '|' . $pole . '|' . $wracam . '|' . wp_create_nonce( 'kw_przelacz_' . $id . '_' . $pole . '_' . $wracam ) );
		} elseif ( preg_match( '/^przesun-(gora|dol)$/', $kod, $m ) ) {
			$wracam = 'gora' === $m[1] ? 'dol' : 'gora';
			$cofnij = kw_uslugi_lista_przycisk_cofnij( 'kw_przesun', $id . '|' . $wracam . '|' . wp_create_nonce( 'kw_przesun_' . $id . '_' . $wracam ) );
		}
	}
	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
		esc_attr( $rodzaj ),
		esc_html( sprintf( $tekst, $nazwa ) ),
		$cofnij // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- przycisk zlozony z esc_attr w kw_uslugi_lista_przycisk_cofnij().
	);
}

/**
 * Przycisk "Cofnij" w komunikacie: wysyla wspolny formularz zadan listy (kw_uslugi_lista_formularz) metoda POST.
 *
 * @param string $nazwa Nazwa pola: kw_przelacz albo kw_przesun.
 * @param string $wartosc Wartosc pola (czesci rozdzielone kreska pionowa, na koncu token).
 * @return string HTML.
 */
function kw_uslugi_lista_przycisk_cofnij( $nazwa, $wartosc ) {
	return ' <button type="submit" form="kw-przelacznik-formularz" name="' . esc_attr( $nazwa ) . '" value="' . esc_attr( $wartosc ) . '" class="button-link kw-cofnij">Cofnij</button>';
}

/**
 * Parametry komunikatu znikaja z paska adresu po wczytaniu (jak komunikaty rdzenia).
 *
 * @param string[] $parametry Parametry.
 * @return string[]
 */
function kw_uslugi_lista_parametry( $parametry ) {
	return array_merge( $parametry, array( 'kw_info', 'kw_id' ) );
}
add_filter( 'removable_query_args', 'kw_uslugi_lista_parametry' );

/* ---------------------------------------------------------------------------
 * Lista uslug
 * ------------------------------------------------------------------------ */

/**
 * Pola, ktore wolno przelaczac z listy (biala lista): klucz => [ nazwa, odmiana do podpowiedzi, kod komunikatu ].
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function kw_uslugi_lista_pola() {
	return array(
		'kw_w_cenniku'    => array( 'Cennik', 'w cenniku', 'cennik' ),
		'kw_w_formularzu' => array( 'Formularz', 'w formularzu', 'formularz' ),
	);
}

/**
 * Teksty przelacznikow widocznosci: naglowek kolumny, wyjasnienie naglowka i dymki stanu (wl = wlaczone, wyl = wylaczone).
 *
 * @return array<string,array<string,string>>
 */
function kw_uslugi_lista_opisy() {
	return array(
		'kw_w_cenniku'    => array(
			'naglowek' => 'W cenniku na stronie',
			'wyjasnij' => "Cennik to tabela z zakładkami na stronie internetowej biura.\nWłączone: klienci widzą tę usługę w cenniku.\nWyłączone: usługi tam nie ma, ale nie jest usunięta.",
			'gdzie'    => 'w cenniku na stronie',
			'wl'       => 'Włączone: klienci widzą tę usługę w cenniku na stronie. Kliknij, żeby ją ukryć. Usługa nie jest usuwana.',
			'wyl'      => 'Wyłączone: tej usługi nie ma w cenniku na stronie. Kliknij, żeby ją pokazać.',
		),
		'kw_w_formularzu' => array(
			'naglowek' => 'W polu Usługa formularza',
			'wyjasnij' => "Formularz kontaktowy na stronie ma pole „Usługa”, z którego klient wybiera, czego dotyczy jego zapytanie.\nWłączone: usługa jest na tej liście.\nWyłączone: nie ma jej tam.",
			'gdzie'    => 'w polu Usługa formularza',
			'wl'       => 'Włączone: klient może wybrać tę usługę w polu „Usługa” formularza kontaktowego. Kliknij, żeby ją schować. Usługa nie jest usuwana.',
			'wyl'      => 'Wyłączone: tej usługi nie ma na liście w polu „Usługa” formularza kontaktowego. Kliknij, żeby ją pokazać.',
		),
	);
}

/**
 * Wyjasnienie kolejnosci (dymek przy naglowku kolumny).
 *
 * @return string
 */
function kw_uslugi_lista_opis_kolejnosci() {
	return "Złap wiersz za uchwyt z kropkami i przeciągnij wyżej albo niżej: tak samo ułożą się karty w zakładce cennika na stronie.\nStrzałki przesuwają usługę o jedno miejsce (także z klawiatury).\nKażda kategoria (Spółki, JDG...) ma własną kolejność.";
}

/**
 * Kolumny listy uslug: nazwa, kategoria, cena, dwa przelaczniki widocznosci i kolejnosc. Bez daty.
 * Naglowki maja dymki z wyjasnieniem (przycisk "?").
 *
 * @param array $kolumny Kolumny.
 * @return array
 */
function kw_uslugi_kolumny( $kolumny ) {
	$opisy = kw_uslugi_lista_opisy();
	$nowe  = array(
		'cb'           => isset( $kolumny['cb'] ) ? $kolumny['cb'] : '<input type="checkbox">',
		'title'        => 'Nazwa',
		'kw_kategoria' => 'Kategoria',
		'kw_cena'      => 'Cena na stronie',
	);
	foreach ( $opisy as $pole => $opis ) {
		$nowe[ $pole ] = esc_html( $opis['naglowek'] ) . kw_uslugi_dymek( 'naglowek-' . $pole, $opis['wyjasnij'], $opis['naglowek'] );
	}
	$nowe['kw_kolejnosc'] = 'Kolejność na stronie' . kw_uslugi_dymek( 'naglowek-kolejnosc', kw_uslugi_lista_opis_kolejnosci(), 'Kolejność na stronie' );
	return $nowe;
}
add_filter( 'manage_kw_usluga_posts_columns', 'kw_uslugi_kolumny' );

/**
 * Adres listy uslug z parametrami.
 *
 * @param array $parametry Parametry zapytania.
 * @return string
 */
function kw_uslugi_lista_adres( array $parametry = array() ) {
	return add_query_arg( array_merge( array( 'post_type' => 'kw_usluga' ), $parametry ), admin_url( 'edit.php' ) );
}

/**
 * Wybrany widok listy: '' (opublikowane), 'all' (po wyszukiwaniu), 'draft', 'trash'.
 *
 * @return string
 */
function kw_uslugi_lista_widok() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt filtra listy.
	return isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
}

/**
 * Czy lista jest ulozona jak na stronie (bez wyszukiwania, bez sortowania po naglowku, widok opublikowanych):
 * tylko wtedy pokazujemy strzalki kolejnosci.
 *
 * @return bool
 */
function kw_uslugi_lista_uporzadkowana() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt filtra listy.
	return '' === kw_uslugi_lista_widok() && empty( $_GET['s'] ) && ! isset( $_GET['orderby'] );
}

/**
 * Przelacznik "w cenniku" / "w formularzu": przycisk-wlacznik (role="switch"), ktory wysyla zadanie POST przez wspolny
 * formularz wydrukowany poza tabela (atrybut form=, bez zagniezdzania formularzy; dziala bez JavaScriptu).
 * Przycisk niesie stan docelowy i token (wpis, pole, stan); dymek na nim zalezy od stanu i od tego, czy usluga jest opublikowana.
 *
 * @param int    $post_id ID uslugi.
 * @param string $pole    Pole z kw_uslugi_lista_pola().
 * @return string HTML.
 */
function kw_uslugi_lista_przelacznik( $post_id, $pole ) {
	$pola = kw_uslugi_lista_pola();
	if ( ! isset( $pola[ $pole ] ) ) {
		return '';
	}
	$opis  = kw_uslugi_lista_opisy()[ $pole ];
	$post  = get_post( $post_id );
	$jest  = '0' !== (string) get_post_meta( $post_id, $pole, true );
	$cel   = $jest ? '0' : '1'; // przycisk niesie stan docelowy: drugie klikniecie tego samego zadania nic nie zmienia
	$token = wp_create_nonce( 'kw_przelacz_' . (int) $post_id . '_' . $pole . '_' . $cel );
	$tekst = $opis[ $jest ? 'wl' : 'wyl' ];
	if ( $post && 'publish' !== $post->post_status ) {
		$tekst .= "\n" . ( 'draft' === $post->post_status ? 'To jest szkic: klienci nie widzą go wcale, dopóki go nie opublikujesz.' : 'Ta usługa nie jest opublikowana: klienci nie widzą jej wcale, dopóki jej nie opublikujesz.' );
	}
	$przycisk = sprintf(
		'<button type="submit" form="kw-przelacznik-formularz" name="kw_przelacz" value="%1$s" class="kw-sw %2$s" role="switch" aria-checked="%3$s" aria-describedby="%4$s"><span class="kw-sw__tor" aria-hidden="true"><span class="kw-sw__galka"></span></span><span class="kw-sw__stan">%5$s</span><span class="screen-reader-text">, %6$s: %7$s</span></button>',
		esc_attr( (int) $post_id . '|' . $pole . '|' . $cel . '|' . $token ),
		$jest ? 'kw-sw--wl' : 'kw-sw--wyl',
		$jest ? 'true' : 'false',
		esc_attr( kw_uslugi_dymek_id( 'sw-' . (int) $post_id . '-' . $pole ) ),
		$jest ? 'widać' : 'ukryta',
		esc_html( $opis['gdzie'] ),
		esc_html( get_the_title( $post_id ) )
	);
	return kw_uslugi_dymek_wokol( 'sw-' . (int) $post_id . '-' . $pole, $przycisk, $tekst );
}

/**
 * Etykieta komorki widoczna tylko na telefonie (tam naglowkow tabeli nie ma): nazwa kolumny i dymek "?".
 *
 * @param int    $post_id ID uslugi.
 * @param string $klucz   Klucz: pole widocznosci albo kolejnosc.
 * @return string HTML.
 */
function kw_uslugi_lista_etykieta( $post_id, $klucz ) {
	if ( 'kolejnosc' === $klucz ) {
		$nazwa   = 'Kolejność w zakładce';
		$tekst   = kw_uslugi_lista_opis_kolejnosci();
	} else {
		$opis  = kw_uslugi_lista_opisy()[ $klucz ];
		$nazwa = $opis['naglowek'];
		$tekst = $opis['wyjasnij'];
	}
	return '<span class="kw-sw-etykieta">' . esc_html( $nazwa ) . kw_uslugi_dymek( 'et-' . (int) $post_id . '-' . $klucz, $tekst, $nazwa ) . '</span>';
}

/**
 * Znacznik stanu wpisu inny niz "opublikowany" (zastepuje dopisek rdzenia przy tytule).
 *
 * @param string $stan Stan wpisu.
 * @return string HTML albo pusty tekst.
 */
function kw_uslugi_lista_stan( $stan ) {
	$nazwy = array(
		'draft'   => 'Szkic: nie widać na stronie',
		'pending' => 'Czeka na akceptację',
		'private' => 'Prywatna',
		'future'  => 'Zaplanowana',
	);
	return isset( $nazwy[ $stan ] ) ? '<span class="kwa-znak kwa-znak--uwaga">' . esc_html( $nazwy[ $stan ] ) . '</span>' : '';
}

/**
 * Stany uslug, ktore liczy kolejnosc w zakladce (domyslnie tylko opublikowane).
 *
 * @return string[]
 */
function kw_uslugi_lista_statusy() {
	return (array) apply_filters( 'kw_uslugi_lista_statusy_kolejnosci', array( 'publish' ) ); // filtr tylko dla samotestu (wlasne szkice)
}

/**
 * Opublikowane uslugi pogrupowane w zakladki cennika (stany z kw_uslugi_lista_statusy()), kazda zakladka w kolejnosci strony.
 *
 * @param bool $odswiez Pomin pamiec podreczna.
 * @return array<int,WP_Post[]> Numer zakladki (kw_uslugi_lista_grupa) => uslugi.
 */
function kw_uslugi_lista_zakladki( $odswiez = false ) {
	static $zakladki = null;
	if ( null === $zakladki || $odswiez ) {
		$zakladki = array();
		$posty    = get_posts(
			array(
				'post_type'      => 'kw_usluga',
				'post_status'    => kw_uslugi_lista_statusy(),
				'posts_per_page' => 200, // ponytail: sufit 200 uslug, jak na liscie.
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
				'no_found_rows'  => true,
			)
		);
		foreach ( $posty as $post ) {
			$zakladki[ kw_uslugi_lista_grupa( $post ) ][] = $post;
		}
	}
	return $zakladki;
}

/**
 * Strzalki kolejnosci w wierszu: dwa przyciski ("wyzej", "nizej") z nazwa dostepna, nieaktywne na krancach zakladki.
 * Tylko dla opublikowanej uslugi na liscie ulozonej jak na stronie.
 *
 * @param WP_Post $post Usluga.
 * @return string HTML (pusty, gdy strzalek nie ma).
 */
function kw_uslugi_lista_strzalki( WP_Post $post ) {
	if ( ! in_array( $post->post_status, kw_uslugi_lista_statusy(), true ) || ! kw_uslugi_lista_uporzadkowana() ) {
		return '';
	}
	$grupa = kw_uslugi_lista_zakladki()[ kw_uslugi_lista_grupa( $post ) ] ?? array();
	$ids   = wp_list_pluck( $grupa, 'ID' );
	$nr    = array_search( $post->ID, $ids, true );
	if ( false === $nr ) {
		return '';
	}
	$przycisk = static function ( $kierunek, $nazwa, $symbol, $aktywny ) use ( $post ) {
		return sprintf(
			'<button type="submit" form="kw-przelacznik-formularz" name="kw_przesun" value="%1$s" class="kw-strzalka" aria-label="%2$s"%3$s><span aria-hidden="true">%4$s</span></button>',
			esc_attr( $post->ID . '|' . $kierunek . '|' . wp_create_nonce( 'kw_przesun_' . $post->ID . '_' . $kierunek ) ),
			esc_attr( 'Przesuń „' . get_the_title( $post ) . '” ' . $nazwa . ' w zakładce' ),
			$aktywny ? '' : ' disabled',
			$symbol
		);
	};
	return '<span class="kw-strzalki">' . $przycisk( 'gora', 'wyżej', '▲', $nr > 0 ) . $przycisk( 'dol', 'niżej', '▼', $nr < count( $ids ) - 1 ) . '</span>';
}

/**
 * Tresc kolumn listy uslug.
 *
 * @param string $kolumna Kolumna.
 * @param int    $post_id ID wpisu.
 */
function kw_uslugi_kolumna( $kolumna, $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}
	if ( 'kw_kolejnosc' === $kolumna ) {
		echo kw_uslugi_lista_etykieta( $post_id, 'kolejnosc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_html i kw_uslugi_dymek().
		$strzalki = kw_uslugi_lista_strzalki( $post );
		// Uchwyt do przeciagania tylko tam, gdzie sa strzalki (lista ulozona jak na stronie); liczba menu_order zostaje w danych, nie na ekranie.
		$uchwyt = '' !== $strzalki ? '<span class="kw-uchwyt" title="Przeciągnij, żeby zmienić kolejność" aria-hidden="true"></span>' : '';
		echo '<div class="kw-kolejnosc">' . $uchwyt . $strzalki . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- przyciski zlozone z esc_attr.
	} elseif ( 'kw_cena' === $kolumna ) {
		echo '<strong>' . esc_html( kw_uslugi_cena( kw_uslugi_z_posta( $post ) )['tekst'] ) . '</strong>';
		echo kw_uslugi_lista_stan( $post->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stale teksty przez esc_html.
	} elseif ( 'kw_kategoria' === $kolumna ) {
		$terminy = get_the_terms( $post, 'kw_grupa' );
		if ( ! is_array( $terminy ) || ! $terminy ) {
			echo '<span class="description">brak</span>';
			return;
		}
		$widok  = kw_uslugi_lista_widok();
		$linki  = array();
		foreach ( $terminy as $termin ) {
			$linki[] = '<a href="' . esc_url( kw_uslugi_lista_adres( array_filter( array( 'kw_grupa' => $termin->slug, 'post_status' => in_array( $widok, array( 'draft', 'trash' ), true ) ? $widok : '' ) ) ) ) . '">' . esc_html( $termin->name ) . '</a>';
		}
		echo implode( ', ', $linki ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_url i esc_html.
	} elseif ( isset( kw_uslugi_lista_pola()[ $kolumna ] ) ) {
		echo kw_uslugi_lista_etykieta( $post_id, $kolumna ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_html i kw_uslugi_dymek().
		echo '<div class="kw-sw-komorka">' . kw_uslugi_lista_przelacznik( $post_id, $kolumna ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_attr, esc_html i kw_uslugi_dymek_wokol().
	}
}
add_action( 'manage_kw_usluga_posts_custom_column', 'kw_uslugi_kolumna', 10, 2 );

/**
 * Czy to glowne zapytanie listy uslug w panelu (a nie inne zapytanie tej samej strony).
 *
 * @param WP_Query $zapytanie Zapytanie.
 * @return bool
 */
function kw_uslugi_lista_zapytanie( $zapytanie ) {
	return is_admin() && $zapytanie->is_main_query() && 'kw_usluga' === $zapytanie->get( 'post_type' ) && isset( $GLOBALS['pagenow'] ) && 'edit.php' === $GLOBALS['pagenow'];
}

/**
 * Lista uslug: domyslnie tylko opublikowane (szkice maja wlasny widok), kolejnosc jak na stronie.
 * Wyszukiwanie bez wybranego widoku przeszukuje wszystko (formularz wysyla wtedy post_status=all).
 *
 * @param WP_Query $zapytanie Zapytanie.
 */
function kw_uslugi_lista_kolejnosc( $zapytanie ) {
	if ( ! kw_uslugi_lista_zapytanie( $zapytanie ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt sortowania z naglowka tabeli.
	if ( ! isset( $_GET['orderby'] ) ) {
		$zapytanie->set(
			'orderby',
			array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			)
		);
	}
	if ( '' === kw_uslugi_lista_widok() && '' === (string) $zapytanie->get( 's' ) ) {
		$zapytanie->set( 'post_status', array( 'publish', 'future', 'pending', 'private' ) );
	}
}
add_action( 'pre_get_posts', 'kw_uslugi_lista_kolejnosc' );

/**
 * Numer zakladki cennika, w ktorej stoi usluga (pierwsza z jej kategorii w kolejnosci zakladek; bez kategorii: na koncu).
 *
 * @param WP_Post $post Usluga.
 * @return int
 */
function kw_uslugi_lista_grupa( $post ) {
	$miejsce = array_flip( array_keys( kw_uslugi_kategorie() ) );
	$terminy = get_the_terms( $post, 'kw_grupa' );
	$numer   = PHP_INT_MAX;
	foreach ( is_array( $terminy ) ? $terminy : array() as $termin ) {
		$numer = min( $numer, $miejsce[ $termin->slug ] ?? PHP_INT_MAX - 1 );
	}
	return $numer;
}

/**
 * Uslugi w kolejnosci zakladek cennika, w zakladce wedlug pola Kolejnosc (tak jak na stronie).
 * Usluga z kilkoma kategoriami stoi w pierwszej (kw_uslugi_grupuj), bez kategorii na koncu.
 *
 * @param WP_Post[] $posty Wpisy.
 * @return WP_Post[]
 */
function kw_uslugi_lista_sortuj( array $posty ) {
	$klucz = static function ( $post ) {
		return array( kw_uslugi_lista_grupa( $post ), (int) $post->menu_order, $post->post_title );
	};
	usort(
		$posty,
		static function ( $a, $b ) use ( $klucz ) {
			return $klucz( $a ) <=> $klucz( $b );
		}
	);
	return $posty;
}

/**
 * Wyniki glownego zapytania listy ukladane wedlug zakladek cennika (gdy nikt nie kliknal naglowka kolumny).
 *
 * @param WP_Post[] $posty     Wpisy.
 * @param WP_Query  $zapytanie Zapytanie.
 * @return WP_Post[]
 */
function kw_uslugi_lista_uloz( $posty, $zapytanie ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt sortowania z naglowka tabeli.
	if ( ! is_array( $posty ) || count( $posty ) < 2 || ! reset( $posty ) instanceof WP_Post || isset( $_GET['orderby'] ) || ! kw_uslugi_lista_zapytanie( $zapytanie ) ) {
		return $posty;
	}
	$posty = kw_uslugi_lista_sortuj( $posty );
	// Pierwszy wiersz kazdej zakladki (poza pierwszym wierszem listy) dostaje klase kw-grupa-start: kreska nad grupa.
	// Tylko w widoku ulozonym jak na stronie, bez wyszukiwania.
	$GLOBALS['kw_lista_poczatki'] = array();
	if ( '' === (string) $zapytanie->get( 's' ) ) {
		$poprzednia = null;
		foreach ( $posty as $nr => $post ) {
			$grupa = kw_uslugi_lista_grupa( $post );
			if ( $nr > 0 && $grupa !== $poprzednia ) {
				$GLOBALS['kw_lista_poczatki'][ $post->ID ] = true;
			}
			$poprzednia = $grupa;
		}
	}
	return $posty;
}
add_filter( 'the_posts', 'kw_uslugi_lista_uloz', 10, 2 );

/**
 * Klasa wiersza tabeli: poczatek nowej zakladki cennika.
 *
 * @param string[] $klasy   Klasy.
 * @param string[] $dodatek Dodatkowe klasy.
 * @param int      $id      ID wpisu.
 * @return string[]
 */
function kw_uslugi_lista_klasy( $klasy, $dodatek, $id ) {
	if ( ! empty( $GLOBALS['kw_lista_poczatki'][ $id ] ) ) {
		$klasy[] = 'kw-grupa-start';
	}
	$post = get_post( $id );
	if ( $post && 'kw_usluga' === $post->post_type && is_admin() && in_array( $post->post_status, kw_uslugi_lista_statusy(), true ) && kw_uslugi_lista_uporzadkowana() ) {
		$klasy[] = 'kw-zakladka-' . kw_uslugi_lista_grupa( $post ); // przeciaganie tylko w obrebie tej samej zakladki
	}
	return $klasy;
}
add_filter( 'post_class', 'kw_uslugi_lista_klasy', 10, 3 );

/**
 * Wszystkie uslugi na jednej stronie (jest ich kilkadziesiat), zeby kolejnosc zakladek byla widoczna w calosci.
 *
 * @return int
 */
function kw_uslugi_lista_na_stronie() {
	return 200; // ponytail: sufit 200 uslug na liste; przy wiekszej liczbie ulozyc kolejnosc w SQL i wrocic do stronicowania.
}
add_filter( 'edit_kw_usluga_per_page', 'kw_uslugi_lista_na_stronie' );
add_filter( 'edit_kw_grupa_per_page', 'kw_uslugi_lista_na_stronie' );

/**
 * Odmiana: "1 usługa", "2 usługi" (2-4, poza 12-14), "5 usług".
 *
 * @param int $n Liczba.
 * @return string
 */
function kw_uslugi_lista_ile( $n ) {
	$n = (int) $n;
	if ( 1 === $n ) {
		return '1 usługa';
	}
	$reszta = $n % 10;
	$setki  = $n % 100;
	return $n . ( $reszta >= 2 && $reszta <= 4 && ( $setki < 12 || $setki > 14 ) ? ' usługi' : ' usług' );
}

/**
 * Najnizsza cena uslugi jako liczba: kwota, a gdy jej nie ma, najnizszy prog (tak jak na stronie, kw_uslugi_cena).
 * Wycena indywidualna i opis tekstowy nie maja ceny (null).
 *
 * @param array $u Usluga (wynik kw_uslugi_z_posta).
 * @return float|null
 */
function kw_uslugi_lista_cena_min( array $u ) {
	if ( ! in_array( $u['cena_typ'], array( 'stala', 'od', 'przedzial' ), true ) ) {
		return null;
	}
	if ( '' !== $u['cena_min'] ) {
		return (float) $u['cena_min'];
	}
	return $u['progi'] ? min( array_map( 'floatval', array_column( $u['progi'], 'cena' ) ) ) : null;
}

/**
 * Cena na kaflu kategorii: "od 100 zł / mies." (najnizsza z uslug miesiecznych; gdy ich nie ma, najnizsza ze wszystkich
 * z jej jednostka), a bez zadnej kwoty "wycena indywidualna".
 *
 * @param array $grupa Uslugi kategorii (wyniki kw_uslugi_z_posta).
 * @return string
 */
function kw_uslugi_lista_kafel_cena( array $grupa ) {
	$ceny      = array();
	$miesieczne = array();
	foreach ( $grupa as $u ) {
		$cena = kw_uslugi_lista_cena_min( $u );
		if ( null === $cena ) {
			continue;
		}
		$ceny[] = array( $cena, $u['jednostka'] );
		if ( 'miesiac' === $u['jednostka'] ) {
			$miesieczne[] = array( $cena, $u['jednostka'] );
		}
	}
	$wybor = $miesieczne ? $miesieczne : $ceny;
	if ( ! $wybor ) {
		return 'wycena indywidualna';
	}
	usort(
		$wybor,
		static function ( $a, $b ) {
			return $a[0] <=> $b[0];
		}
	);
	$jednostka = kw_uslugi_jednostka( $wybor[0][1] );
	return 'od' . "\u{00A0}" . kw_uslugi_kwota( $wybor[0][0] ) . "\u{00A0}zł" . ( '' !== $jednostka ? ' / ' . $jednostka : '' );
}

/**
 * Dane kafli nad lista: "Wszystkie" i jeden kafel na kategorie z zakladka w cenniku (w kolejnosci zakladek).
 * Liczba uslug to wszystkie opublikowane z ta kategoria (tyle zobaczysz po kliknieciu), cena: kw_uslugi_lista_kafel_cena().
 * "Wszystkie" nie ma ceny (cena pusta).
 *
 * @param array $uslugi    Opublikowane uslugi (kw_uslugi_pobierz).
 * @param array $kategorie Kategorie w kolejnosci zakladek (kw_uslugi_kategorie).
 * @param array $zakladki  Slugi kategorii z zakladka w cenniku (kw_uslugi_kat_zakladki).
 * @return array<int,array{slug:string,nazwa:string,ile:int,cena:string}>
 */
function kw_uslugi_lista_kafle_dane( array $uslugi, array $kategorie, array $zakladki ) {
	$kafel = static function ( $slug, $nazwa, array $grupa ) {
		return array(
			'slug'  => $slug,
			'nazwa' => $nazwa,
			'ile'   => count( $grupa ),
			'cena'  => '' === $slug ? '' : kw_uslugi_lista_kafel_cena( $grupa ),
		);
	};
	$dane = array( $kafel( '', 'Wszystkie', $uslugi ) );
	foreach ( $kategorie as $slug => $kategoria ) {
		if ( in_array( $slug, $zakladki, true ) ) {
			$grupa  = array_filter(
				$uslugi,
				static function ( $u ) use ( $slug ) {
					return isset( $u['grupy'][ $slug ] );
				}
			);
			$dane[] = $kafel( (string) $slug, $kategoria['nazwa'], $grupa );
		}
	}
	return $dane;
}

/**
 * Kafle kategorii nad lista: odnosniki z tym samym parametrem kw_grupa, co filtr listy.
 *
 * @param array  $dane    Wynik kw_uslugi_lista_kafle_dane().
 * @param string $wybrana Slug wybranej kategorii ('' = wszystkie).
 * @return string HTML.
 */
function kw_uslugi_lista_kafle_html( array $dane, $wybrana ) {
	$html = '<p class="kw-kafle-opis" id="kw-kafle-opis">Kliknij kategorię, żeby zobaczyć tylko jej usługi.</p><nav class="kw-kafle" aria-label="Kategorie usług" aria-describedby="kw-kafle-opis">';
	foreach ( $dane as $k ) {
		$html .= sprintf(
			'<a class="kw-kafel" href="%1$s"%2$s><span class="kw-kafel__nazwa">%3$s</span><span class="kw-kafel__ile">%4$s</span>%5$s%6$s</a>',
			esc_url( kw_uslugi_lista_adres( '' === $k['slug'] ? array() : array( 'kw_grupa' => $k['slug'] ) ) ),
			$k['slug'] === $wybrana ? ' aria-current="true"' : '',
			esc_html( $k['nazwa'] ),
			esc_html( kw_uslugi_lista_ile( $k['ile'] ) ),
			'' === $k['cena'] ? '' : '<span class="kw-kafel__cena">' . esc_html( $k['cena'] ) . '</span>',
			$k['slug'] === $wybrana ? '<span class="kw-kafel__wybrana">wybrana</span>' : ''
		);
	}
	return $html . '</nav>';
}

/**
 * Widoki nad tabela: "Opublikowane", "Szkice i archiwum", kosz. Drukuje tez zdanie o tym, co tu sie robi
 * (filtr dziala tuz przed wydrukiem listy widokow, wiec echo trafia nad nia).
 *
 * @param array $widoki Widoki rdzenia.
 * @return array
 */
function kw_uslugi_lista_widoki( $widoki ) {
	$licz   = wp_count_posts( 'kw_usluga', 'readable' );
	$na_str = (int) $licz->publish + (int) $licz->future + (int) $licz->pending + (int) $licz->private;
	$szkice = (int) $licz->draft;
	$wybrany = kw_uslugi_lista_widok();
	$widok  = static function ( $adres, $nazwa, $liczba, $aktualny ) {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
			esc_url( $adres ),
			$aktualny ? ' class="current" aria-current="page"' : '',
			esc_html( $nazwa ),
			(int) $liczba
		);
	};
	echo '<p class="kw-lista-wstep"><span class="kw-wstep-dlugi">Kliknij nazwę usługi, żeby zmienić jej cenę, progi i zakres. Przełączniki decydują, gdzie klient widzi usługę: w cenniku na stronie i w polu „Usługa” formularza kontaktowego. Wyłączona usługa nie jest usuwana, tylko ukryta.</span><span class="kw-wstep-krotki">Kliknij nazwę usługi, żeby zmienić cenę i zakres. Przełączniki decydują, gdzie klient widzi usługę.</span></p>';
	if ( in_array( $wybrany, array( '', 'all' ), true ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt filtra listy.
		$kategoria = isset( $_GET['kw_grupa'] ) ? sanitize_title( wp_unslash( $_GET['kw_grupa'] ) ) : '';
		echo kw_uslugi_lista_kafle_html( kw_uslugi_lista_kafle_dane( kw_uslugi_pobierz( true ), kw_uslugi_kategorie(), kw_uslugi_kat_zakladki() ), $kategoria ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone z esc_url i esc_html.
	}
	$nowe = array(
		'kw-opublikowane' => $widok( kw_uslugi_lista_adres(), 'Opublikowane', $na_str, in_array( $wybrany, array( '', 'all' ), true ) ),
		'kw-szkice'       => $widok( kw_uslugi_lista_adres( array( 'post_status' => 'draft' ) ), 'Szkice i archiwum', $szkice, 'draft' === $wybrany ),
	);
	if ( isset( $widoki['trash'] ) ) {
		$nowe['trash'] = $widoki['trash'];
	}
	return $nowe;
}
add_filter( 'views_edit-kw_usluga', 'kw_uslugi_lista_widoki' );

/**
 * Bez listy miesiecy (daty nie ma w tabeli).
 *
 * @param bool   $wylacz Stan.
 * @param string $typ    Typ wpisu.
 * @return bool
 */
function kw_uslugi_lista_bez_miesiecy( $wylacz, $typ ) {
	return 'kw_usluga' === $typ ? true : $wylacz;
}
add_filter( 'disable_months_dropdown', 'kw_uslugi_lista_bez_miesiecy', 10, 2 );

/**
 * Akcje wiersza: zostaje Edytuj, Kosz (i przywracanie) i kopia ("Zrób kopię"). Bez szybkiej edycji
 * (pokazuje pola, ktorych ksiegowy nie rozumie) i bez drugiego duplikatora.
 *
 * @param array   $akcje Akcje wiersza.
 * @param WP_Post $post  Wpis.
 * @return array
 */
function kw_uslugi_lista_akcje( $akcje, $post ) {
	if ( 'kw_usluga' === $post->post_type ) {
		$akcje = array_intersect_key( $akcje, array_flip( array( 'edit', 'trash', 'untrash', 'delete', 'duplicate' ) ) );
		if ( isset( $akcje['duplicate'] ) ) {
			// Wtyczka duplicate-page ma angielskie nazwy: po polsku, z tym samym odnosnikiem i tokenem.
			$akcje['duplicate'] = str_replace( array( 'Duplicate This', 'Duplicate this as draft' ), array( 'Zrób kopię', 'Utwórz kopię tej usługi jako szkic' ), $akcje['duplicate'] );
		}
	}
	return $akcje;
}
add_filter( 'post_row_actions', 'kw_uslugi_lista_akcje', 999, 2 );

/**
 * Masowe dzialania: bez zbiorczej edycji.
 *
 * @param array $akcje Dzialania.
 * @return array
 */
function kw_uslugi_lista_masowe( $akcje ) {
	unset( $akcje['edit'] );
	return $akcje;
}
add_filter( 'bulk_actions-edit-kw_usluga', 'kw_uslugi_lista_masowe' );

/**
 * Bez dopiskow "- Szkic" przy tytule: stan pokazuje nasz znacznik w kolumnie Widocznosc
 * (rdzen dokleja do tytulu pauze, a dopisek jest tylko dla szkicow).
 *
 * @param string[] $stany Stany.
 * @param WP_Post  $post  Wpis.
 * @return string[]
 */
function kw_uslugi_lista_stany( $stany, $post ) {
	return 'kw_usluga' === $post->post_type ? array() : $stany;
}
add_filter( 'display_post_states', 'kw_uslugi_lista_stany', 99, 2 );

/**
 * Przesuniecie uslugi o jedno miejsce w jej zakladce cennika (zamiana menu_order z sasiadem). Czysta funkcja (bez
 * przekierowania), do samotestu. Zakladka i kolejnosc jak na stronie: opublikowane uslugi z tej samej pierwszej kategorii,
 * wedlug menu_order, potem nazwy. Gdy obie liczby sa rowne, cala zakladka dostaje najpierw numery co 10 od najmniejszej dotychczasowej.
 * Kolejnosc kontroli: czy to opublikowana usluga, token (wpis i kierunek), uprawnienie do TEJ uslugi, kierunek z bialej listy,
 * sasiad (kraniec), uprawnienie do sasiada (i do calej zakladki przy przenumerowaniu).
 *
 * @param int    $id        ID uslugi.
 * @param string $kierunek  'gora' (wyzej) albo 'dol' (nizej).
 * @param string $token     Token z przycisku.
 * @return array{kod:string,kierunek:string} kod: ok, brak, token, prawa, pole, kraniec.
 */
function kw_uslugi_lista_przesun( $id, $kierunek, $token ) {
	$id    = (int) $id;
	$post  = $id ? get_post( $id ) : null;
	$wynik = static function ( $kod ) use ( $kierunek ) {
		return array(
			'kod'      => $kod,
			'kierunek' => $kierunek,
		);
	};
	if ( ! $post || 'kw_usluga' !== $post->post_type || ! in_array( $post->post_status, kw_uslugi_lista_statusy(), true ) ) {
		return $wynik( 'brak' );
	}
	if ( ! wp_verify_nonce( (string) $token, 'kw_przesun_' . $id . '_' . $kierunek ) ) {
		return $wynik( 'token' );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return $wynik( 'prawa' );
	}
	if ( ! in_array( $kierunek, array( 'gora', 'dol' ), true ) ) {
		return $wynik( 'pole' );
	}
	$grupa = kw_uslugi_lista_zakladki( true )[ kw_uslugi_lista_grupa( $post ) ] ?? array();
	$ids   = wp_list_pluck( $grupa, 'ID' );
	$nr    = array_search( $id, $ids, true );
	$obok  = false === $nr ? null : ( $grupa[ 'gora' === $kierunek ? $nr - 1 : $nr + 1 ] ?? null );
	if ( null === $obok || ( 'gora' === $kierunek && $nr < 1 ) ) {
		return $wynik( 'kraniec' );
	}
	$numery = array();
	if ( (int) $post->menu_order === (int) $obok->menu_order ) {
		$start = (int) min( wp_list_pluck( $grupa, 'menu_order' ) );
		foreach ( $grupa as $i => $u ) {
			$numery[ $u->ID ] = $start + 10 * $i;
		}
		// Po przenumerowaniu sasiedzi maja kolejne numery: zamiana ich numerow robi przesuniecie.
		list( $numery[ $id ], $numery[ $obok->ID ] ) = array( $numery[ $obok->ID ], $numery[ $id ] );
	} else {
		$numery = array(
			$id        => (int) $obok->menu_order,
			$obok->ID => (int) $post->menu_order,
		);
	}
	foreach ( array_keys( $numery ) as $zmieniana ) {
		$bylo = (int) get_post_field( 'menu_order', $zmieniana );
		if ( $bylo === $numery[ $zmieniana ] ) {
			unset( $numery[ $zmieniana ] );
		} elseif ( ! current_user_can( 'edit_post', $zmieniana ) ) {
			return $wynik( 'prawa' );
		}
	}
	global $wpdb;
	foreach ( $numery as $zmieniana => $numer ) {
		$wpdb->update( $wpdb->posts, array( 'menu_order' => (int) $numer ), array( 'ID' => (int) $zmieniana ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tylko kolejnosc; cache czyszczony nizej.
		clean_post_cache( (int) $zmieniana );
	}
	kw_uslugi_lista_zakladki( true );
	return $wynik( 'ok' );
}

/**
 * Ustawienie jednego z dwoch pol widocznosci na stan docelowy (nie "odwrocenie": to samo zadanie dwa razy
 * zostawia ta sama wartosc). Czysta funkcja (bez przekierowania), zeby dalo sie ja sprawdzic samotestem.
 * Kolejnosc kontroli: czy wpis jest usluga, token (obejmuje wpis, pole i stan), uprawnienie do TEJ uslugi, biala lista pol i stanow.
 *
 * @param int    $id    ID uslugi.
 * @param string $pole  Pole: kw_w_cenniku albo kw_w_formularzu.
 * @param string $stan  Stan docelowy: '1' (pokazuj) albo '0' (schowaj).
 * @param string $token Token z przycisku.
 * @return array{kod:string,stan:string} kod: ok, brak, token, prawa, pole; stan: nowa wartosc ('1' albo '0') po zapisie.
 */
function kw_uslugi_lista_przelacz( $id, $pole, $stan, $token ) {
	$id   = (int) $id;
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'kw_usluga' !== $post->post_type ) {
		return array(
			'kod'  => 'brak',
			'stan' => '',
		);
	}
	if ( ! wp_verify_nonce( (string) $token, 'kw_przelacz_' . $id . '_' . $pole . '_' . $stan ) ) {
		return array(
			'kod'  => 'token',
			'stan' => '',
		);
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return array(
			'kod'  => 'prawa',
			'stan' => '',
		);
	}
	if ( ! isset( kw_uslugi_lista_pola()[ $pole ] ) || ! in_array( $stan, array( '0', '1' ), true ) ) {
		return array(
			'kod'  => 'pole',
			'stan' => '',
		);
	}
	update_post_meta( $id, $pole, wp_slash( kw_uslugi_czysc_flage( $stan ) ) );
	return array(
		'kod'  => 'ok',
		'stan' => $stan,
	);
}

/**
 * Adres powrotu na liste uslug (ten sam widok i filtr), a gdy go nie ma: domyslna lista.
 *
 * @return string
 */
function kw_uslugi_lista_powrot() {
	$adres = wp_get_referer();
	if ( $adres && false !== strpos( $adres, 'edit.php' ) && false !== strpos( $adres, 'post_type=kw_usluga' ) ) {
		return remove_query_arg( array( 'kw_info', 'kw_id' ), $adres );
	}
	return kw_uslugi_lista_adres();
}

/**
 * Zadanie z listy (POST na admin-post.php, wspolny formularz kw-przelacznik-formularz): przelacznik widocznosci
 * (kw_przelacz = "ID|pole|stan|token") albo przesuniecie (kw_przesun = "ID|kierunek|token"). Potem powrot na liste z komunikatem.
 */
function kw_uslugi_lista_akcja() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- token sprawdzaja kw_uslugi_lista_przelacz() i kw_uslugi_lista_przesun().
	$czesci = static function ( $nazwa, $ile ) {
		$wartosc = isset( $_POST[ $nazwa ] ) && is_string( $_POST[ $nazwa ] ) ? explode( '|', sanitize_text_field( wp_unslash( $_POST[ $nazwa ] ) ), $ile ) : array();
		return count( $wartosc ) === $ile ? array_map( 'sanitize_key', $wartosc ) : array_fill( 0, $ile, '' );
	};
	// phpcs:enable
	$id  = 0;
	$kod = 'brak';
	if ( isset( $_POST['kw_przelacz'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		list( $id, $pole, $stan, $token ) = $czesci( 'kw_przelacz', 4 );
		$id                               = absint( $id );
		$wynik                            = kw_uslugi_lista_przelacz( $id, $pole, $stan, $token );
		$kod                              = 'ok' === $wynik['kod'] ? ( '1' === $wynik['stan'] ? 'wl-' : 'wyl-' ) . kw_uslugi_lista_pola()[ $pole ][2] : $wynik['kod'];
	} elseif ( isset( $_POST['kw_przesun'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		list( $id, $kierunek, $token ) = $czesci( 'kw_przesun', 3 );
		$id                            = absint( $id );
		$wynik                         = kw_uslugi_lista_przesun( $id, $kierunek, $token );
		$kod                           = 'ok' === $wynik['kod'] ? 'przesun-' . $kierunek : $wynik['kod'];
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'kw_info' => $kod,
				'kw_id'   => $id,
			),
			kw_uslugi_lista_powrot()
		) . '#post-' . $id
	);
	exit;
}
add_action( 'admin_post_kw_uslugi_lista_akcja', 'kw_uslugi_lista_akcja' );

/**
 * Wspolny formularz zadan listy: stoi poza tabela (tabela jest wewnatrz formularza filtrow), a przyciski
 * przelacznikow, strzalek i "Cofnij" odwoluja sie do niego atrybutem form=. Drukowany w stopce listy uslug.
 */
function kw_uslugi_lista_formularz() {
	$ekran = get_current_screen();
	if ( ! $ekran || 'edit-kw_usluga' !== $ekran->id ) {
		return;
	}
	$powrot = isset( $_SERVER['REQUEST_URI'] ) ? remove_query_arg( array( 'kw_info', 'kw_id' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : '';
	printf(
		'<form id="kw-przelacznik-formularz" method="post" action="%1$s" hidden><input type="hidden" name="action" value="kw_uslugi_lista_akcja"><input type="hidden" name="_wp_http_referer" value="%2$s"></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		esc_attr( $powrot )
	);
}
add_action( 'admin_footer', 'kw_uslugi_lista_formularz' );

/* ---------------------------------------------------------------------------
 * Kategorie: kolejnosc zakladek cennika, archiwum
 * ------------------------------------------------------------------------ */

/**
 * Nastepna wolna liczba kolejnosci zakladki: kolejna dziesiatka po najwiekszej uzytej (10, 20, 30, 40 => 50).
 *
 * @return int
 */
function kw_uslugi_kat_nastepny_numer() {
	$max = 0;
	foreach ( kw_uslugi_kategorie( true ) as $kategoria ) {
		if ( PHP_INT_MAX !== $kategoria['kolejnosc'] ) {
			$max = max( $max, (int) $kategoria['kolejnosc'] );
		}
	}
	return (int) ( floor( $max / 10 ) * 10 + 10 );
}

/**
 * Pola kategorii: kolejnosc zakladki, naglowek dla wyszukiwarek, archiwum i (przy edycji) adres zakladki.
 *
 * @param WP_Term|string $termin Kategoria przy edycji, nazwa taksonomii przy dodawaniu.
 */
function kw_uslugi_kategoria_pole( $termin ) {
	$edycja  = $termin instanceof WP_Term;
	$numer   = $edycja ? get_term_meta( $termin->term_id, 'kw_kolejnosc', true ) : kw_uslugi_kat_nastepny_numer();
	$ukryta  = $edycja && '1' === (string) get_term_meta( $termin->term_id, 'kw_ukryta', true );
	$pole    = '<input type="number" name="kw_kolejnosc" id="kw-kat-kolejnosc" class="small-text" min="0" step="1" value="' . esc_attr( (string) $numer ) . '" aria-describedby="kw-kat-kolejnosc-opis">'
		. wp_nonce_field( 'kw_kategoria_zapis', 'kw_kategoria_nonce', false, false )
		. '<p class="description" id="kw-kat-kolejnosc-opis">Mniejsza liczba = zakładka wcześniej w cenniku i grupa wyżej w formularzu (10, 20, 30...). Zostaw odstępy, łatwiej potem coś wstawić. Bez numeru kategoria stoi na końcu.</p>';
	$archiwum = '<label class="kw-kat-wybor" for="kw-kat-ukryta"><input type="checkbox" name="kw_ukryta" id="kw-kat-ukryta" value="1" ' . checked( $ukryta, true, false ) . ' aria-describedby="kw-kat-ukryta-opis"> Archiwum: nie pokazuj przy wyborze kategorii usługi</label>'
		. '<p class="description" id="kw-kat-ukryta-opis">Kategoria zostaje przy starych usługach, ale nie ma jej już na liście w kreatorze usługi. Strony nie zmienia: zakładki cennika zależą od usług.</p>';
	$naglowek = '<input type="text" name="kw_naglowek" id="kw-kat-naglowek" maxlength="120" value="' . esc_attr( $edycja ? kw_uslugi_czysc_naglowek( get_term_meta( $termin->term_id, 'kw_naglowek', true ) ) : '' ) . '" aria-describedby="kw-kat-naglowek-opis">'
		. '<p class="description" id="kw-kat-naglowek-opis">Pełniejsza nazwa tej części cennika, np. „Księgowość spółek z o.o.”. Czytają ją wyszukiwarki i czytniki ekranu; na zakładce zostaje krótka nazwa kategorii. Puste pole = nazwa kategorii.</p>';
	if ( $edycja ) {
		echo '<tr class="form-field kw-kat-pole"><th scope="row"><label for="kw-kat-kolejnosc">Kolejność zakładki</label></th><td>' . $pole . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_attr.
		echo '<tr class="form-field kw-kat-pole"><th scope="row"><label for="kw-kat-naglowek">Nagłówek zakładki dla wyszukiwarek</label></th><td>' . $naglowek . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_attr.
		echo '<tr class="form-field kw-kat-pole"><th scope="row">Archiwum</th><td>' . $archiwum . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stale teksty.
		echo '<tr class="form-field kw-kat-pole"><th scope="row">Adres zakładki na stronie</th><td><code>#cennik-' . esc_html( $termin->slug ) . '</code><p class="description">Odnośnik z takim końcem otwiera tę zakładkę cennika. Adres nie zmienia się, gdy zmienisz nazwę kategorii.</p></td></tr>';
	} else {
		echo '<div class="form-field"><label for="kw-kat-kolejnosc">Kolejność zakładki</label>' . $pole . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_attr.
		echo '<div class="form-field"><label for="kw-kat-naglowek">Nagłówek zakładki dla wyszukiwarek</label>' . $naglowek . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zlozone wyzej z esc_attr.
		echo '<div class="form-field">' . $archiwum . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stale teksty.
	}
}
add_action( 'kw_grupa_add_form_fields', 'kw_uslugi_kategoria_pole' );
add_action( 'kw_grupa_edit_form_fields', 'kw_uslugi_kategoria_pole' );

/**
 * Klasa ciala ekranu kategorii: malo kategorii (mniej niz 10) = wyszukiwarka nad tabela jest zbedna (chowa ja CSS).
 *
 * @param string $klasy Klasy ciala (panel: tekst).
 * @return string
 */
function kw_uslugi_kat_klasy_ciala( $klasy ) {
	$ekran = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $ekran && 'kw_grupa' === $ekran->taxonomy && count( kw_uslugi_kategorie() ) < 10 ) {
		$klasy .= ' kw-kat-malo';
	}
	return $klasy;
}
add_filter( 'admin_body_class', 'kw_uslugi_kat_klasy_ciala' );

/**
 * Zdanie nad formularzem dodawania kategorii.
 */
function kw_uslugi_kat_wstep() {
	echo '<p class="kw-lista-wstep">Kategoria to zakładka cennika na stronie i nagłówek grupy w formularzu. Nazwę i kolejność zmienisz po kliknięciu nazwy w tabeli.</p>';
}
add_action( 'kw_grupa_pre_add_form', 'kw_uslugi_kat_wstep' );

/**
 * Zapis kolejnosci, naglowka i archiwum kategorii (tylko z formularza panelu: wlasny token + uprawnienie).
 * Szybka edycja i inne zapisy bez tokenu nic nie zmieniaja.
 *
 * @param int $termin_id ID kategorii.
 */
function kw_uslugi_kategoria_zapisz( $termin_id ) {
	if ( ! isset( $_POST['kw_kolejnosc'], $_POST['kw_kategoria_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kw_kategoria_nonce'] ) ), 'kw_kategoria_zapis' ) || ! current_user_can( 'manage_categories' ) ) {
		return;
	}
	$wartosc = trim( sanitize_text_field( wp_unslash( $_POST['kw_kolejnosc'] ) ) );
	if ( '' === $wartosc ) {
		delete_term_meta( $termin_id, 'kw_kolejnosc' );
	} else {
		update_term_meta( $termin_id, 'kw_kolejnosc', absint( $wartosc ) );
	}
	// Naglowek zakladki: pole tekstowe; puste = wraca nazwa kategorii.
	if ( isset( $_POST['kw_naglowek'] ) ) {
		$naglowek = kw_uslugi_czysc_naglowek( wp_unslash( $_POST['kw_naglowek'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanityzuje kw_uslugi_czysc_naglowek.
		if ( '' === $naglowek ) {
			delete_term_meta( $termin_id, 'kw_naglowek' );
		} else {
			update_term_meta( $termin_id, 'kw_naglowek', wp_slash( $naglowek ) );
		}
	}
	// Pole wyboru: brak w zadaniu = odznaczone.
	if ( '1' === kw_uslugi_czysc_flage( isset( $_POST['kw_ukryta'] ) ? '1' : '0' ) ) {
		update_term_meta( $termin_id, 'kw_ukryta', '1' );
	} else {
		delete_term_meta( $termin_id, 'kw_ukryta' );
	}
}
add_action( 'created_kw_grupa', 'kw_uslugi_kategoria_zapisz' );
add_action( 'edited_kw_grupa', 'kw_uslugi_kategoria_zapisz' );

/**
 * Archiwum kategorii: ustawia albo zdejmuje kw_ukryta. Czysta funkcja (bez przekierowania), do samotestu.
 * Kolejnosc kontroli: czy kategoria istnieje, wartosc (tylko 0 albo 1), token, uprawnienia.
 *
 * @param int    $id    ID kategorii.
 * @param string $stan  '1' = do archiwum, '0' = przywroc.
 * @param string $token Token z odnosnika.
 * @return string Kod: ok, brak, pole, token, prawa.
 */
function kw_uslugi_kat_archiwum_ustaw( $id, $stan, $token ) {
	$id     = (int) $id;
	$termin = $id ? get_term( $id, 'kw_grupa' ) : null;
	if ( ! $termin instanceof WP_Term ) {
		return 'brak';
	}
	if ( ! in_array( $stan, array( '0', '1' ), true ) ) {
		return 'pole';
	}
	if ( ! wp_verify_nonce( (string) $token, 'kw_kat_arch_' . $id . '_' . $stan ) ) {
		return 'token';
	}
	if ( ! current_user_can( 'manage_categories' ) || ! current_user_can( 'edit_term', $id ) ) {
		return 'prawa';
	}
	if ( '1' === $stan ) {
		update_term_meta( $id, 'kw_ukryta', '1' );
	} else {
		delete_term_meta( $id, 'kw_ukryta' );
	}
	kw_uslugi_kategorie( true );
	return 'ok';
}

/**
 * Zadanie z listy kategorii: odnosnik admin-post.php?action=kw_uslugi_kat_archiwum, potem powrot z komunikatem.
 */
function kw_uslugi_kat_archiwum_zadanie() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- token sprawdza kw_uslugi_kat_archiwum_ustaw().
	$id    = isset( $_GET['termin'] ) ? absint( $_GET['termin'] ) : 0;
	$stan  = isset( $_GET['stan'] ) ? sanitize_key( wp_unslash( $_GET['stan'] ) ) : '';
	$token = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	// phpcs:enable
	$kod = kw_uslugi_kat_archiwum_ustaw( $id, $stan, $token );
	if ( 'ok' === $kod ) {
		$kod = '1' === $stan ? 'kat-archiwum' : 'kat-przywrocona';
	}
	wp_safe_redirect(
		add_query_arg(
			array(
				'taxonomy'  => 'kw_grupa',
				'post_type' => 'kw_usluga',
				'kw_info'   => $kod,
				'kw_id'     => $id,
			),
			admin_url( 'edit-tags.php' )
		)
	);
	exit;
}
add_action( 'admin_post_kw_uslugi_kat_archiwum', 'kw_uslugi_kat_archiwum_zadanie' );

// Blokada trwalego usuwania kategorii (kw_uslugi_kat_bez_usuwania) jest w includes/dane.php: musi dzialac takze poza panelem (REST).

/**
 * Kolumny listy kategorii: nazwa, liczba uslug, kolejnosc zakladki, stan. Bez pola wyboru (nie ma dzialan zbiorczych).
 *
 * @param array $kolumny Kolumny.
 * @return array
 */
function kw_uslugi_kategorie_kolumny( $kolumny ) {
	return array(
		'name'         => 'Nazwa',
		'posts'        => 'Usługi',
		'kw_kolejnosc' => 'Kolejność zakładki',
		'kw_stan'      => 'Stan',
	);
}
add_filter( 'manage_edit-kw_grupa_columns', 'kw_uslugi_kategorie_kolumny' );

/**
 * Stan kategorii: Archiwum, Zakladka w cenniku, Bez zakladki w cenniku (sa uslugi, ale nie tu), Pusta.
 *
 * @param WP_Term  $termin    Kategoria.
 * @param string[] $zakladki  Slugi kategorii, ktore maja zakladke w cenniku (kw_uslugi_kat_zakladki).
 * @return array{0:string,1:string} Klasa znacznika i tekst.
 */
function kw_uslugi_kat_stan( WP_Term $termin, array $zakladki ) {
	if ( '1' === (string) get_term_meta( $termin->term_id, 'kw_ukryta', true ) ) {
		return array( 'kwa-znak--bez-kropki', 'Archiwum' );
	}
	if ( in_array( $termin->slug, $zakladki, true ) ) {
		return array( 'kwa-znak--ok', 'Zakładka w cenniku' );
	}
	if ( (int) $termin->count > 0 ) {
		return array( 'kwa-znak--uwaga', 'Bez zakładki w cenniku' );
	}
	return array( 'kwa-znak--bez-kropki', 'Pusta' );
}

/**
 * Kategorie, ktore maja zakladke w cenniku: pierwsza kategoria uslugi pokazywanej w cenniku (tak jak sklada to strona).
 *
 * @param array|null $uslugi Opublikowane uslugi (domyslnie kw_uslugi_pobierz()).
 * @return string[]
 */
function kw_uslugi_kat_zakladki( $uslugi = null ) {
	$slugi = array();
	foreach ( null === $uslugi ? kw_uslugi_pobierz() : $uslugi as $usluga ) {
		if ( $usluga['cennik'] && $usluga['grupy'] ) {
			$slugi[ (string) array_key_first( $usluga['grupy'] ) ] = true;
		}
	}
	return array_keys( $slugi );
}

/**
 * Tresc kolumn listy kategorii.
 *
 * @param string $tresc     Tresc.
 * @param string $kolumna   Kolumna.
 * @param int    $termin_id ID kategorii.
 * @return string
 */
function kw_uslugi_kategorie_kolumna( $tresc, $kolumna, $termin_id ) {
	if ( 'kw_kolejnosc' === $kolumna ) {
		$wartosc = get_term_meta( $termin_id, 'kw_kolejnosc', true );
		return '' === $wartosc || false === $wartosc ? '<span class="description">brak (na końcu)</span>' : (string) (int) $wartosc;
	}
	if ( 'kw_stan' === $kolumna ) {
		static $zakladki = null;
		$termin          = get_term( $termin_id, 'kw_grupa' );
		if ( ! $termin instanceof WP_Term ) {
			return '';
		}
		$zakladki = null === $zakladki ? kw_uslugi_kat_zakladki() : $zakladki;
		$stan     = kw_uslugi_kat_stan( $termin, $zakladki );
		return '<span class="kwa-znak ' . esc_attr( $stan[0] ) . '">' . esc_html( $stan[1] ) . '</span>';
	}
	return $tresc;
}
add_filter( 'manage_kw_grupa_custom_column', 'kw_uslugi_kategorie_kolumna', 10, 3 );

/**
 * Akcje wiersza kategorii: Edytuj i "Do archiwum" / "Przywroc z archiwum". Bez szybkiej edycji i bez trwalego usuwania.
 *
 * @param array   $akcje  Akcje wiersza.
 * @param WP_Term $termin Kategoria.
 * @return array
 */
function kw_uslugi_kat_akcje( $akcje, $termin ) {
	$nowe = isset( $akcje['edit'] ) ? array( 'edit' => $akcje['edit'] ) : array();
	if ( current_user_can( 'manage_categories' ) && current_user_can( 'edit_term', $termin->term_id ) ) {
		$stan = '1' === (string) get_term_meta( $termin->term_id, 'kw_ukryta', true ) ? '0' : '1';
		$nowe['kw_archiwum'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url(
				add_query_arg(
					array(
						'action'   => 'kw_uslugi_kat_archiwum',
						'termin'   => (int) $termin->term_id,
						'stan'     => $stan,
						'_wpnonce' => wp_create_nonce( 'kw_kat_arch_' . (int) $termin->term_id . '_' . $stan ),
					),
					admin_url( 'admin-post.php' )
				)
			),
			'1' === $stan ? 'Do archiwum' : 'Przywróć z archiwum'
		);
	}
	return $nowe;
}
add_filter( 'kw_grupa_row_actions', 'kw_uslugi_kat_akcje', 999, 2 );

/**
 * Bez dzialan zbiorczych na liscie kategorii (jedyne z rdzenia to trwale usuniecie).
 *
 * @return array
 */
function kw_uslugi_kat_masowe() {
	return array();
}
add_filter( 'bulk_actions-edit-kw_grupa', 'kw_uslugi_kat_masowe' );

/**
 * Lista kategorii w kolejnosci zakladek cennika, archiwalne na koncu (chyba ze ktos kliknal naglowek kolumny).
 *
 * @param array $terminy Kategorie.
 * @param array $taksonomie Taksonomie zapytania.
 * @return array
 */
function kw_uslugi_kat_uloz( $terminy, $taksonomie ) {
	$ekran = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt sortowania z naglowka tabeli.
	if ( ! $ekran || 'edit-kw_grupa' !== $ekran->id || array( 'kw_grupa' ) !== (array) $taksonomie || isset( $_GET['orderby'] ) || ! is_array( $terminy ) || count( $terminy ) < 2 || ! reset( $terminy ) instanceof WP_Term ) {
		return $terminy;
	}
	$miejsce = array_flip( array_keys( kw_uslugi_kategorie() ) );
	usort(
		$terminy,
		static function ( $a, $b ) use ( $miejsce ) {
			$klucz = static function ( $t ) use ( $miejsce ) {
				return array( '1' === (string) get_term_meta( $t->term_id, 'kw_ukryta', true ) ? 1 : 0, $miejsce[ $t->slug ] ?? PHP_INT_MAX );
			};
			return $klucz( $a ) <=> $klucz( $b );
		}
	);
	return $terminy;
}
add_filter( 'get_terms', 'kw_uslugi_kat_uloz', 10, 2 );

/* ---------------------------------------------------------------------------
 * Arkusz i skrypt listy i kategorii
 * ------------------------------------------------------------------------ */

/**
 * Arkusz (i skrypt) tylko na liscie uslug, liscie kategorii i edycji kategorii. Zalezy od kw-admin.css (znaczniki .kwa-znak).
 */
function kw_uslugi_lista_zasoby() {
	$ekran = get_current_screen();
	if ( ! $ekran || ! ( 'edit-kw_usluga' === $ekran->id || 'kw_grupa' === $ekran->taxonomy ) ) {
		return;
	}
	wp_enqueue_style( 'kw-uslugi-admin-lista', plugins_url( 'assets/kw-admin-lista.css', KW_USLUGI_PLIK ), array( 'kw-uslugi-admin' ), (string) filemtime( KW_USLUGI_KATALOG . 'assets/kw-admin-lista.css' ) );
	if ( in_array( $ekran->id, array( 'edit-kw_grupa', 'edit-kw_usluga' ), true ) ) {
		wp_enqueue_script( 'kw-uslugi-admin-lista', plugins_url( 'assets/kw-admin-lista.js', KW_USLUGI_PLIK ), array( 'jquery', 'jquery-ui-sortable', 'wp-a11y' ), (string) filemtime( KW_USLUGI_KATALOG . 'assets/kw-admin-lista.js' ), true );
		wp_add_inline_script(
			'kw-uslugi-admin-lista',
			'window.kwListaDane = ' . wp_json_encode(
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'token' => wp_create_nonce( 'kw_kolejnosc_lista' ),
				)
			) . ';',
			'before'
		);
	}
}
add_action( 'admin_enqueue_scripts', 'kw_uslugi_lista_zasoby', 20 );

/**
 * Nowa kolejnosc calej zakladki po przeciagnieciu wiersza (lista uslug). Czysta funkcja, do samotestu.
 * Przyjmuje ID wszystkich opublikowanych uslug JEDNEJ zakladki w nowym porzadku; numeruje je co 10 od najmniejszego
 * dotychczasowego numeru zakladki. Odrzuca liste, ktora nie jest dokladnie ta zakladka (brak, nadmiar, inna zakladka).
 *
 * @param int[]  $ids   ID uslug w nowej kolejnosci.
 * @param string $token Token z listy.
 * @return string Kod: ok, token, brak, zakladka, prawa.
 */
function kw_uslugi_lista_ustaw_kolejnosc( array $ids, $token ) {
	if ( ! wp_verify_nonce( (string) $token, 'kw_kolejnosc_lista' ) ) {
		return 'token';
	}
	$ids      = array_values( array_unique( array_map( 'absint', $ids ) ) );
	$pierwszy = $ids ? get_post( $ids[0] ) : null;
	if ( ! $pierwszy || 'kw_usluga' !== $pierwszy->post_type || ! in_array( $pierwszy->post_status, kw_uslugi_lista_statusy(), true ) ) {
		return 'brak';
	}
	$grupa   = kw_uslugi_lista_zakladki( true )[ kw_uslugi_lista_grupa( $pierwszy ) ] ?? array();
	$wgrupie = array_map( 'intval', wp_list_pluck( $grupa, 'ID' ) );
	$a       = $wgrupie;
	$b       = $ids;
	sort( $a );
	sort( $b );
	if ( $a !== $b ) {
		return 'zakladka';
	}
	foreach ( $ids as $id ) {
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return 'prawa';
		}
	}
	$start = (int) min( wp_list_pluck( $grupa, 'menu_order' ) );
	global $wpdb;
	foreach ( $ids as $i => $id ) {
		$numer = $start + 10 * $i;
		if ( (int) get_post_field( 'menu_order', $id ) !== $numer ) {
			$wpdb->update( $wpdb->posts, array( 'menu_order' => $numer ), array( 'ID' => $id ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tylko kolejnosc; cache czyszczony nizej.
			clean_post_cache( $id );
		}
	}
	kw_uslugi_lista_zakladki( true );
	return 'ok';
}

/**
 * AJAX: zapis kolejnosci po przeciagnieciu (kw_uslugi_kolejnosc).
 */
function kw_uslugi_lista_kolejnosc_ajax() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- token sprawdza kw_uslugi_lista_ustaw_kolejnosc().
	$ids   = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array();
	$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
	// phpcs:enable
	$kod = kw_uslugi_lista_ustaw_kolejnosc( $ids, $token );
	if ( 'ok' === $kod ) {
		wp_send_json_success();
	}
	wp_send_json_error( array( 'kod' => $kod ), 'prawa' === $kod || 'token' === $kod ? 403 : 400 );
}
add_action( 'wp_ajax_kw_uslugi_kolejnosc', 'kw_uslugi_lista_kolejnosc_ajax' );
