<?php
/**
 * Formularz kontaktowy: punkt REST kw/v1/kontakt (walidacja, limity, kopia w panelu, e-mail).
 *
 * Odpowiedzi: 200 {ok:true} | 200 {ok:false,kod:"wysylka"} (poczta zawiodla) | 422 {ok:false,kod:"walidacja",pola:{}}
 * | 400 {ok:false,kod:"odrzucono"} (pulapka na boty) | 403 (brak albo zly token) | 429 {ok:false,kod:"limit"} | 500 {ok:false,kod:"blad"}.
 * Gosc nigdy nie dostaje szczegolow bledu serwera.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

const KW_USLUGI_TEMAT    = 'Wiadomość ze strony';
const KW_USLUGI_OGOLNE   = 'zapytanie ogólne';
const KW_USLUGI_MIN_CZAS = 3; // Sekundy od wydania tokenu do wyslania: mniej = automat.
const KW_USLUGI_LIMIT    = 5; // Przyjetych wiadomosci z jednego adresu IP na godzine.
const KW_USLUGI_LIMIT_LACZNY = 40; // Przyjetych wiadomosci ze wszystkich adresow razem na godzine.
const KW_USLUGI_KLUCZ_LACZNY = 'kw_rl_lacznie';

/**
 * Podpis czasu wydania formularza (nie da sie go podrobic bez soli witryny).
 *
 * @param int $czas Znacznik czasu.
 * @return string
 */
function kw_uslugi_podpis( $czas ) {
	return substr( hash_hmac( 'sha256', 'kw_formularz|' . (int) $czas, wp_salt( 'nonce' ) ), 0, 32 );
}

/**
 * Klucz licznika wysylek dla adresu IP (adres nie jest zapisywany jawnie).
 *
 * @param string $ip Adres IP.
 * @return string
 */
function kw_uslugi_klucz_limitu( $ip ) {
	return 'kw_rl_' . md5( $ip . '|' . wp_salt( 'nonce' ) );
}

/**
 * Tekst od goscia jako czysty tekst: poprawne UTF-8, bez znakow sterujacych.
 * Znacznikow HTML nie wycinamy (tresc jest wszedzie wyswietlana przez esc_html i wysylana jako text/plain),
 * dzieki temu "<", ">" i "%" w wiadomosci klienta nie gina.
 *
 * @param string $tekst    Tekst.
 * @param bool   $wiersze  Czy zachowac podzial na wiersze.
 * @return string
 */
function kw_uslugi_czysc_tekst( $tekst, $wiersze = false ) {
	$tekst = wp_check_invalid_utf8( (string) $tekst );
	$tekst = str_replace( array( "\r\n", "\r" ), "\n", $tekst );
	if ( $wiersze ) {
		$tekst = (string) preg_replace( '/[^\P{Cc}\n\t]/u', '', $tekst );
		$tekst = (string) preg_replace( "/\n{4,}/", "\n\n\n", $tekst );
	} else {
		$tekst = (string) preg_replace( '/[\p{Cc}\s]+/u', ' ', $tekst );
	}
	return trim( $tekst );
}

/**
 * Odpowiedz JSON bez przechowywania w pamieci podrecznej.
 *
 * @param array $dane   Dane.
 * @param int   $status Kod HTTP.
 * @return WP_REST_Response
 */
function kw_uslugi_odp( array $dane, $status = 200 ) {
	$odpowiedz = new WP_REST_Response( $dane, $status );
	$odpowiedz->header( 'Cache-Control', 'no-store, max-age=0' );
	return $odpowiedz;
}

/**
 * Trasy REST.
 */
function kw_uslugi_rest_trasy() {
	register_rest_route(
		'kw/v1',
		'/kontakt',
		array(
			'methods'             => 'POST',
			'callback'            => 'kw_uslugi_rest_kontakt',
			'permission_callback' => 'kw_uslugi_rest_uprawnienia',
		)
	);
	// Swiezy token dla strony z pamieci podrecznej albo karty otwartej dluzej niz zyje nonce. POST, zeby nikt go nie buforowal.
	register_rest_route(
		'kw/v1',
		'/token',
		array(
			'methods'             => 'POST',
			'callback'            => 'kw_uslugi_rest_token',
			'permission_callback' => '__return_true', // Publiczne: wydaje tylko token formularza goscia.
		)
	);
}
add_action( 'rest_api_init', 'kw_uslugi_rest_trasy' );

/**
 * Formularz jest publiczny, ale wymaga tokenu wydanego przez strone (naglowek X-WP-Nonce).
 *
 * @param WP_REST_Request $zadanie Zadanie.
 * @return true|WP_Error
 */
function kw_uslugi_rest_uprawnienia( WP_REST_Request $zadanie ) {
	$nonce = $zadanie->get_header( 'x_wp_nonce' );
	if ( ! is_string( $nonce ) || '' === $nonce ) {
		return new WP_Error( 'kw_brak_tokenu', 'Brak tokenu formularza.', array( 'status' => 403 ) );
	}
	if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error( 'kw_zly_token', 'Token formularza wygasł.', array( 'status' => 403 ) );
	}
	return true;
}

/**
 * Wydaje token formularza.
 *
 * @return WP_REST_Response
 */
function kw_uslugi_rest_token() {
	$czas = time();
	return kw_uslugi_odp(
		array(
			'nonce' => wp_create_nonce( 'wp_rest' ),
			't'     => $czas,
			's'     => kw_uslugi_podpis( $czas ),
		)
	);
}

/**
 * Przyjmuje wiadomosc. Kazdy nieprzewidziany blad konczy sie ogolnym {ok:false}, bez szczegolow.
 *
 * @param WP_REST_Request $zadanie Zadanie.
 * @return WP_REST_Response
 */
function kw_uslugi_rest_kontakt( WP_REST_Request $zadanie ) {
	try {
		return kw_uslugi_obsluz_kontakt( $zadanie );
	} catch ( Throwable $blad ) {
		error_log( 'kw-uslugi: ' . $blad->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- jedyny slad bledu, gosc go nie widzi.
		return kw_uslugi_odp(
			array(
				'ok'  => false,
				'kod' => 'blad',
			),
			500
		);
	}
}

/**
 * Wlasciwa obsluga wiadomosci.
 *
 * @param WP_REST_Request $zadanie Zadanie.
 * @return WP_REST_Response
 */
function kw_uslugi_obsluz_kontakt( WP_REST_Request $zadanie ) {
	$dane = $zadanie->get_json_params();
	if ( ! is_array( $dane ) ) {
		$dane = (array) $zadanie->get_body_params();
	}
	$pole = static function ( $klucz ) use ( $dane ) {
		return isset( $dane[ $klucz ] ) && is_scalar( $dane[ $klucz ] ) ? (string) $dane[ $klucz ] : '';
	};
	$nie  = static function ( $kod, $status, array $wiecej = array() ) {
		return kw_uslugi_odp(
			array_merge(
				array(
					'ok'  => false,
					'kod' => $kod,
				),
				$wiecej
			),
			$status
		);
	};

	// 1. Pulapka na boty: pole niewidoczne dla ludzi musi zostac puste.
	if ( '' !== trim( $pole( 'kw_x' ) ) ) {
		return $nie( 'odrzucono', 400 );
	}

	// 2. Podpisany czas wydania formularza: bez niego albo za szybko = automat. Podpis starszy niz doba nie przechodzi
	// (raz zdobytej pary czas + podpis nie da sie uzywac bez konca); przegladarka pobiera wtedy swiezy token i ponawia.
	$czas = (int) $pole( 't' );
	if ( $czas <= 0 || $czas > time() + 60 || time() - $czas > DAY_IN_SECONDS || ! hash_equals( kw_uslugi_podpis( $czas ), $pole( 's' ) ) ) {
		return $nie( 'token', 403 );
	}
	if ( time() - $czas < KW_USLUGI_MIN_CZAS ) {
		return $nie( 'za_szybko', 403 );
	}

	// 3. Walidacja i sanityzacja.
	$imie      = kw_uslugi_czysc_tekst( $pole( 'imie' ) );
	$email     = trim( $pole( 'email' ) );
	$telefon   = kw_uslugi_czysc_tekst( $pole( 'telefon' ) );
	$wiadomosc = kw_uslugi_czysc_tekst( $pole( 'wiadomosc' ), true );
	$bledy     = array();

	if ( '' === $imie ) {
		$bledy['imie'] = 'Podaj imię.';
	} elseif ( mb_strlen( $imie ) > 100 ) {
		$bledy['imie'] = 'Imię jest za długie (maksymalnie 100 znaków).';
	}

	if ( '' === $email ) {
		$bledy['email'] = 'Podaj adres e-mail.';
	} elseif ( strlen( $email ) > 254 || preg_match( '/[\s,;<>"%?&=]/', $email ) || ! is_email( $email ) ) { // %?&= : adres nie moze niesc parametrow odnosnika mailto.
		$bledy['email'] = 'Sprawdź adres e-mail, np. imie@domena.pl.';
	}

	if ( '' !== $telefon && ( mb_strlen( $telefon ) > 30 || ! preg_match( '/^[0-9+()\-. ]+$/', $telefon ) || strlen( (string) preg_replace( '/\D/', '', $telefon ) ) < 7 ) ) {
		$bledy['telefon'] = 'Sprawdź numer telefonu, np. 600 100 200.';
	}

	$dlugosc = mb_strlen( $wiadomosc );
	if ( 0 === $dlugosc ) {
		$bledy['wiadomosc'] = 'Napisz wiadomość.';
	} elseif ( $dlugosc < 10 ) {
		$bledy['wiadomosc'] = 'Wiadomość jest za krótka: napisz co najmniej 10 znaków.';
	} elseif ( $dlugosc > 5000 ) {
		$bledy['wiadomosc'] = 'Wiadomość jest za długa (maksymalnie 5000 znaków).';
	}

	// Pola wyceny (opcjonalne): tylko wartosci z bialej listy; do e-maila i kopii trafia etykieta z serwera, nie tekst z przegladarki.
	$wycena = array();
	foreach ( kw_uslugi_pola_wyceny() as $nazwa => $definicja ) {
		$wartosc = trim( $pole( $nazwa ) );
		if ( '' === $wartosc ) {
			$wycena[ $nazwa ] = '';
		} elseif ( isset( $definicja[1][ $wartosc ] ) ) {
			$wycena[ $nazwa ] = $definicja[1][ $wartosc ];
		} else {
			$bledy[ $nazwa ] = 'Wybierz wartość z listy.';
		}
	}

	if ( $bledy ) {
		return $nie( 'walidacja', 422, array( 'pola' => $bledy ) );
	}

	// Usluga: nazwe bierzemy z bazy po slugu, nigdy z tekstu przyslanego przez przegladarke.
	$usluga = KW_USLUGI_OGOLNE;
	$slug   = sanitize_title( $pole( 'usluga' ) );
	if ( 'inna' === $slug ) {
		$usluga = 'Inna / nie wiem';
	} elseif ( '' !== $slug ) {
		foreach ( kw_uslugi_pobierz() as $u ) {
			if ( $u['slug'] === $slug && $u['formularz'] ) {
				$usluga = kw_uslugi_czysc_tekst( $u['tytul'] );
				break;
			}
		}
	}

	// 4. Tryb testowy awarii: tylko zalogowany administrator, nic nie zapisuje i nic nie wysyla.
	if ( $zadanie->get_param( 'kw_test_awarii' ) && current_user_can( 'manage_options' ) ) {
		return $nie( 'wysylka', 200, array( 'test' => true ) );
	}

	// 5. Ta sama wiadomosc ponowiona w ciagu godziny (np. po zerwanym polaczeniu): bez drugiej kopii i drugiego e-maila.
	$klucz_kopii = 'kw_dup_' . md5( strtolower( $email ) . "\n" . $wiadomosc );
	$id          = (int) get_transient( $klucz_kopii );
	if ( $id && 'kw_wiadomosc' === get_post_type( $id ) && 'private' === get_post_status( $id ) ) {
		if ( '1' === get_post_meta( $id, '_kw_wyslano', true ) ) {
			return kw_uslugi_odp( array( 'ok' => true ) );
		}
	} else {
		$id = 0;
	}

	// 6. Limity przyjetych wiadomosci: na adres IP oraz laczny dla wszystkich adresow (seria z wielu adresow
	// nie zaleje skrzynki ani bazy). Okno godzinne liczone od pierwszej wiadomosci. Najpierw oba sprawdzenia, potem oba zapisy.
	// ponytail: liczniki w transientach bez blokady, rownolegle zadania moga przepuscic o kilka wiadomosci za duzo
	// (najwyzej tyle, ile procesow PHP dziala naraz); blokada w bazie, gdyby to kiedys przeszkadzalo.
	$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ) : '';
	$teraz    = time();
	$limity   = array(
		kw_uslugi_klucz_limitu( $ip ) => KW_USLUGI_LIMIT,
		KW_USLUGI_KLUCZ_LACZNY        => KW_USLUGI_LIMIT_LACZNY,
	);
	$liczniki = array();
	foreach ( $limity as $klucz => $limit ) {
		$licznik = get_transient( $klucz );
		if ( ! is_array( $licznik ) || empty( $licznik['do'] ) || $licznik['do'] <= $teraz ) {
			$licznik = array(
				'n'  => 0,
				'do' => $teraz + HOUR_IN_SECONDS,
			);
		}
		if ( $licznik['n'] >= $limit ) {
			$odpowiedz = $nie( 'limit', 429 );
			$odpowiedz->header( 'Retry-After', (string) max( 1, $licznik['do'] - $teraz ) );
			return $odpowiedz;
		}
		$liczniki[ $klucz ] = $licznik;
	}
	foreach ( $liczniki as $klucz => $licznik ) {
		++$licznik['n'];
		set_transient( $klucz, $licznik, max( 1, $licznik['do'] - $teraz ) );
	}

	// 7. Kopia w panelu PRZED wysylka: nawet gdy poczta zawiedzie, wiadomosc zostaje.
	// Adres strony z naglowka Referer, ale tylko gdy to adres tej witryny (porownanie z ukosnikiem na koncu:
	// "https://witryna.pl.obcy.example" nie przejdzie); inaczej adres strony glownej.
	$strona = (string) $zadanie->get_header( 'referer' );
	$strona = 0 === strpos( $strona, home_url( '/' ) ) ? mb_substr( esc_url_raw( $strona ), 0, 300 ) : home_url( '/' );
	if ( ! $id ) {
		// Autor = administrator. Przy autorze 0 WordPress uznalby niezalogowanego goscia za autora wpisu prywatnego
		// (zapytania z perm=readable pokazuja autorowi jego prywatne wpisy).
		$admini = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		$id     = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => 'kw_wiadomosc',
					'post_status' => 'private',
					'post_author' => $admini ? (int) $admini[0] : 0,
					'post_title'  => sanitize_text_field( $imie . ': ' . $usluga ), // Tytul bez znacznikow; wierne dane sa w polach meta.
					'post_name'   => 'w-' . strtolower( wp_generate_password( 16, false ) ), // Wlasny losowy slug: rdzen nie szuka wolnego petla zapytan.
					'meta_input'  => array(
						'_kw_imie'    => $imie,
						'_kw_email'   => $email,
						'_kw_telefon' => $telefon,
						'_kw_usluga'     => $usluga,
						'_kw_dokumenty'  => $wycena['dokumenty'],
						'_kw_pracownicy' => $wycena['pracownicy'],
						'_kw_tresc'      => $wiadomosc,
						'_kw_strona'  => $strona,
						'_kw_wyslano' => '0',
					),
				)
			),
			true
		);
		$id = is_wp_error( $id ) ? 0 : (int) $id;
		if ( $id ) {
			set_transient( $klucz_kopii, $id, HOUR_IN_SECONDS );
		}
	}

	// 8. E-mail. Naglowki: tylko Reply-To z adresem po is_email (bez nazwy), temat bez tekstu od goscia.
	$tresc = "Imię: {$imie}\n"
		. "E-mail: {$email}\n"
		. 'Telefon: ' . ( '' !== $telefon ? $telefon : 'nie podano' ) . "\n"
		. "Usługa: {$usluga}\n";
	foreach ( kw_uslugi_pola_wyceny() as $nazwa => $definicja ) {
		$tresc .= $definicja[0] . ': ' . ( '' !== $wycena[ $nazwa ] ? $wycena[ $nazwa ] : 'nie podano' ) . "\n";
	}
	$tresc .= "\nWiadomość:\n{$wiadomosc}\n\n"
		. "-- \n"
		. "Strona: {$strona}\n"
		. 'Data: ' . wp_date( 'd.m.Y H:i' ) . "\n";

	$blad_poczty = '';
	$lap_blad    = static function ( $blad ) use ( &$blad_poczty ) {
		if ( is_wp_error( $blad ) ) {
			$blad_poczty = $blad->get_error_message();
		}
	};
	add_action( 'wp_mail_failed', $lap_blad );
	$wyslano = (bool) wp_mail( kw_uslugi_opcje()['email'], KW_USLUGI_TEMAT . ': ' . $usluga, $tresc, array( 'Reply-To: ' . $email ) );
	remove_action( 'wp_mail_failed', $lap_blad );

	if ( $id ) {
		update_post_meta( $id, '_kw_wyslano', $wyslano ? '1' : '0' );
		if ( ! $wyslano ) {
			update_post_meta( $id, '_kw_blad', wp_slash( mb_substr( $blad_poczty, 0, 500 ) ) );
		}
	}

	return $wyslano ? kw_uslugi_odp( array( 'ok' => true ) ) : $nie( 'wysylka', 200 );
}

/* ---------------------------------------------------------------------------
 * Narzedzia prywatnosci WordPressa (Narzedzia: Eksportuj / Usun dane osobowe)
 * ------------------------------------------------------------------------ */

/**
 * Identyfikatory wiadomosci z formularza wyslanych z podanego adresu e-mail (takze z kosza).
 *
 * @param string $email  Adres e-mail.
 * @param int    $strona Numer strony wynikow (od 1).
 * @return int[]
 */
function kw_uslugi_prywatnosc_wpisy( $email, $strona = 1 ) {
	$email = trim( (string) $email );
	if ( '' === $email ) {
		return array();
	}
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'kw_wiadomosc',
				'post_status'      => array( 'private', 'publish', 'draft', 'pending', 'trash' ),
				'posts_per_page'   => 50,
				'paged'            => max( 1, (int) $strona ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'meta_key'         => '_kw_email', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- narzedzie uruchamiane recznie przez administratora.
				'meta_value'       => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'suppress_filters' => true,
			)
		)
	);
}

/**
 * Eksport danych osobowych: wiadomosci z formularza wyslane z danego adresu.
 *
 * @param string $email  Adres e-mail.
 * @param int    $strona Numer strony.
 * @return array
 */
function kw_uslugi_prywatnosc_eksport( $email, $strona = 1 ) {
	$pola = array(
		'_kw_imie'       => 'Imię',
		'_kw_email'      => 'E-mail',
		'_kw_telefon'    => 'Telefon',
		'_kw_usluga'     => 'Usługa',
		'_kw_dokumenty'  => 'Liczba dokumentów w miesiącu',
		'_kw_pracownicy' => 'Liczba pracowników',
		'_kw_tresc'      => 'Wiadomość',
		'_kw_strona'     => 'Wysłano ze strony',
	);
	$ids  = kw_uslugi_prywatnosc_wpisy( $email, $strona );
	$dane = array();
	foreach ( $ids as $id ) {
		$wiersze = array(
			array(
				'name'  => 'Data',
				'value' => get_the_date( 'd.m.Y H:i', $id ),
			),
		);
		foreach ( $pola as $klucz => $nazwa ) {
			$wartosc = (string) get_post_meta( $id, $klucz, true );
			if ( '' !== $wartosc ) {
				$wiersze[] = array(
					'name'  => $nazwa,
					'value' => $wartosc, // Rdzen sam zabezpiecza wartosci w pliku eksportu.
				);
			}
		}
		$dane[] = array(
			'group_id'    => 'kw-wiadomosci',
			'group_label' => 'Wiadomości z formularza na stronie',
			'item_id'     => 'kw-wiadomosc-' . $id,
			'data'        => $wiersze,
		);
	}
	return array(
		'data' => $dane,
		'done' => count( $ids ) < 50,
	);
}

/**
 * Usuwanie danych osobowych: wiadomosci z formularza wyslane z danego adresu (trwale, razem z polami).
 * Uruchamia je administrator w Narzedzia: Usun dane osobowe, po potwierdzonej prosbie osoby.
 *
 * @param string $email Adres e-mail.
 * @return array
 */
function kw_uslugi_prywatnosc_usun( $email ) {
	// Zawsze pierwsza strona: usuniete wpisy znikaja z wynikow, wiec numer strony z rdzenia by je przeskakiwal.
	$ids      = kw_uslugi_prywatnosc_wpisy( $email, 1 );
	$usuniete = 0;
	$zostalo  = 0;
	foreach ( $ids as $id ) {
		if ( wp_delete_post( $id, true ) ) {
			++$usuniete;
		} else {
			++$zostalo;
		}
	}
	return array(
		'items_removed'  => $usuniete > 0,
		'items_retained' => $zostalo > 0,
		'messages'       => $zostalo ? array( 'Części wiadomości z formularza nie udało się usunąć.' ) : array(),
		'done'           => $zostalo > 0 || count( $ids ) < 50,
	);
}

add_filter(
	'wp_privacy_personal_data_exporters',
	static function ( $lista ) {
		$lista['kw-uslugi'] = array(
			'exporter_friendly_name' => 'Wiadomości z formularza (Usługi i cennik)',
			'callback'               => 'kw_uslugi_prywatnosc_eksport',
		);
		return $lista;
	}
);
add_filter(
	'wp_privacy_personal_data_erasers',
	static function ( $lista ) {
		$lista['kw-uslugi'] = array(
			'eraser_friendly_name' => 'Wiadomości z formularza (Usługi i cennik)',
			'callback'             => 'kw_uslugi_prywatnosc_usun',
		);
		return $lista;
	}
);

/* ---------------------------------------------------------------------------
 * Okres przechowywania wiadomosci z formularza (polityka prywatnosci: 12 miesiecy)
 * ------------------------------------------------------------------------ */

const KW_USLUGI_RETENCJA_AKCJA    = 'kw_uslugi_retencja'; // Zadanie WP-Cron, raz na dobe.
const KW_USLUGI_RETENCJA_DZIENNIK = 'kw_uslugi_retencja_dziennik'; // Opcja: kiedy sprzatano i ile usunieto (20 ostatnich wpisow).
const KW_USLUGI_RETENCJA_PARTIA   = 50; // Najwiecej wiadomosci usuwanych w jednym przebiegu.

/**
 * Granica przechowywania: wiadomosci z data wczesniejsza niz ta znikaja. Pusty tekst = usuwanie wylaczone (0 miesiecy).
 *
 * @param int|null $teraz Znacznik czasu (domyslnie teraz).
 * @return string Data i godzina w czasie witryny (jak post_date) albo ''.
 */
function kw_uslugi_retencja_granica( $teraz = null ) {
	$miesiace = (int) kw_uslugi_opcje()['retencja'];
	if ( $miesiace < 1 ) {
		return '';
	}
	return (string) wp_date( 'Y-m-d H:i:s', (int) strtotime( '-' . $miesiace . ' months', null === $teraz ? time() : (int) $teraz ) );
}

/**
 * Wiadomosci z formularza bez znacznika "Zachowaj (klient)", od najstarszej; z granica: tylko starsze niz granica.
 * W kazdym stanie, takze w koszu. Wylacznie typ wpisu kw_wiadomosc.
 *
 * @param string $granica Granica z kw_uslugi_retencja_granica() albo '' (bez granicy).
 * @param int    $ile     Ile najwyzej.
 * @return int[]
 */
function kw_uslugi_retencja_wpisy( $granica, $ile ) {
	$zapytanie = array(
		'post_type'        => 'kw_wiadomosc',
		'post_status'      => array( 'private', 'publish', 'draft', 'pending', 'trash' ),
		'posts_per_page'   => max( 1, (int) $ile ),
		'orderby'          => 'date',
		'order'            => 'ASC',
		'fields'           => 'ids',
		'no_found_rows'    => true,
		'suppress_filters' => true,
		'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- raz na dobe, kilkadziesiat wpisow.
			array(
				'key'     => '_kw_zachowaj',
				'compare' => 'NOT EXISTS',
			),
		),
	);
	if ( '' !== $granica ) {
		$zapytanie['date_query'] = array(
			array(
				'before'    => $granica,
				'inclusive' => false,
			),
		);
	}
	return array_map( 'intval', get_posts( $zapytanie ) );
}

/**
 * Sprzatanie: trwale usuwa wiadomosci z formularza starsze niz ustawiony okres (liczony od daty wiadomosci), takze z kosza.
 * Wiadomosc oznaczona "Zachowaj (klient)" zostaje. Jedna partia na przebieg; gdy partia byla pelna, kolejny przebieg
 * za 5 minut. Kazdy przebieg zapisuje date w dzienniku, a przebieg z usunieciami takze ich liczbe.
 *
 * @param int|null $teraz Znacznik czasu (domyslnie teraz; samotest podaje wlasny).
 * @return int Liczba usunietych wiadomosci.
 */
function kw_uslugi_retencja_usun( $teraz = null ) {
	$granica  = kw_uslugi_retencja_granica( $teraz );
	$usuniete = 0;
	if ( '' !== $granica ) {
		/**
		 * Wiadomosci do usuniecia w tym przebiegu. Samotest zaweza je do wlasnych wpisow testowych.
		 *
		 * @param int[] $ids ID wiadomosci.
		 */
		$ids = (array) apply_filters( 'kw_uslugi_retencja_ids', kw_uslugi_retencja_wpisy( $granica, KW_USLUGI_RETENCJA_PARTIA ) );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			// Jeszcze raz tuz przed usunieciem: wlasny typ wpisu i brak znacznika "zachowaj".
			if ( 'kw_wiadomosc' === get_post_type( $id ) && ! metadata_exists( 'post', $id, '_kw_zachowaj' ) && wp_delete_post( $id, true ) ) {
				++$usuniete;
			}
		}
	}
	$dziennik             = get_option( KW_USLUGI_RETENCJA_DZIENNIK );
	$dziennik             = is_array( $dziennik ) ? $dziennik : array();
	$dziennik['ostatnio'] = time();
	$dziennik['wpisy']    = isset( $dziennik['wpisy'] ) && is_array( $dziennik['wpisy'] ) ? $dziennik['wpisy'] : array();
	if ( $usuniete ) {
		$dziennik['wpisy'][] = array(
			'czas'     => time(),
			'usuniete' => $usuniete,
		);
		$dziennik['wpisy']   = array_slice( $dziennik['wpisy'], -20 );
	}
	update_option( KW_USLUGI_RETENCJA_DZIENNIK, $dziennik, false );
	if ( $usuniete >= KW_USLUGI_RETENCJA_PARTIA ) {
		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, KW_USLUGI_RETENCJA_AKCJA );
	}
	return $usuniete;
}
add_action( KW_USLUGI_RETENCJA_AKCJA, 'kw_uslugi_retencja_usun', 10, 0 );

/**
 * Zadanie sprzatania raz na dobe. Sprawdzane przy kazdym starcie, wiec dziala takze po aktualizacji bez ponownej aktywacji.
 */
function kw_uslugi_retencja_plan() {
	if ( ! wp_next_scheduled( KW_USLUGI_RETENCJA_AKCJA ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', KW_USLUGI_RETENCJA_AKCJA );
	}
}
add_action( 'init', 'kw_uslugi_retencja_plan', 30 );

/**
 * Dezaktywacja wtyczki: zadanie sprzatania znika z harmonogramu (dane i ustawienia zostaja).
 */
function kw_uslugi_dezaktywacja() {
	wp_clear_scheduled_hook( KW_USLUGI_RETENCJA_AKCJA );
}

/**
 * Kiedy wiadomosc zniknie sama. Pusty tekst: nigdy (usuwanie wylaczone albo wiadomosc jest zachowana).
 *
 * @param int $id ID wiadomosci.
 * @return string Data "d.m.Y" albo ''.
 */
function kw_uslugi_retencja_termin( $id ) {
	$miesiace = (int) kw_uslugi_opcje()['retencja'];
	$czas     = get_post_timestamp( (int) $id );
	if ( $miesiace < 1 || ! $czas || metadata_exists( 'post', (int) $id, '_kw_zachowaj' ) ) {
		return '';
	}
	return (string) wp_date( 'd.m.Y', (int) strtotime( '+' . $miesiace . ' months', $czas ) );
}

/**
 * Oznaczenie wiadomosci "Zachowaj (klient)" albo zdjecie go. Czysta funkcja (bez przekierowania), do samotestu.
 * Kolejnosc kontroli: czy to wiadomosc z formularza, token (wpis i stan), uprawnienie administratora, stan z bialej listy.
 *
 * @param int    $id    ID wiadomosci.
 * @param string $stan  '1' (zachowaj) albo '0' (nie zachowuj).
 * @param string $token Token z odnosnika.
 * @return string Kod: ok, brak, token, prawa, pole.
 */
function kw_uslugi_zachowaj_ustaw( $id, $stan, $token ) {
	$id = (int) $id;
	if ( ! $id || 'kw_wiadomosc' !== get_post_type( $id ) ) {
		return 'brak';
	}
	if ( ! wp_verify_nonce( (string) $token, 'kw_zachowaj_' . $id . '_' . $stan ) ) {
		return 'token';
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return 'prawa';
	}
	if ( ! in_array( $stan, array( '0', '1' ), true ) ) {
		return 'pole';
	}
	if ( '1' === $stan ) {
		update_post_meta( $id, '_kw_zachowaj', (string) time() );
	} else {
		delete_post_meta( $id, '_kw_zachowaj' );
	}
	return 'ok';
}
