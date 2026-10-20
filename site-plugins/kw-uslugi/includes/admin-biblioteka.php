<?php
/**
 * Biblioteka ekranu uslugi: gotowe pozycje list ("W cenie", "Dodatkowo platne", "Nie obejmuje", "Od czego zalezy cena")
 * i zestawy progow, wspolne dla wszystkich uslug. Opcja kw_uslugi_biblioteka (tekst JSON, bez autoload), dopisywanie
 * i usuwanie przez admin-ajax z ekranu edycji uslugi. Pierwsze uzycie zbiera pozycje z istniejacych uslug i typowe progi.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

const KW_USLUGI_BIB_OPCJA   = 'kw_uslugi_biblioteka';
const KW_USLUGI_BIB_POZYCJE = 300;  // Najwiecej pozycji na jednej liscie biblioteki.
const KW_USLUGI_BIB_ZESTAWY = 40;   // Najwiecej zestawow progow.
const KW_USLUGI_BIB_WEJSCIE = 4000; // Najdluzszy przyjmowany tekst z zadania (pozycja albo zestaw progow w JSON), w bajtach.

/**
 * Listy biblioteki: klucz w bibliotece => pole uslugi z ta sama lista.
 *
 * @return array<string,string>
 */
function kw_uslugi_bib_listy() {
	return array(
		'w_cenie'      => 'kw_w_cenie',
		'dodatkowo'    => 'kw_dodatkowo',
		'nie_obejmuje' => 'kw_nie_obejmuje',
		'czynniki'     => 'kw_czynniki',
	);
}

/**
 * Zakres z etykiety progu: "11-20" => array( 11, 20 ), "201 i więcej" => array( 201, null ). Inny zapis => null.
 *
 * @param string $etykieta Etykieta progu.
 * @return array{0:int,1:int|null}|null
 */
function kw_uslugi_bib_zakres( $etykieta ) {
	$etykieta = trim( (string) $etykieta );
	if ( preg_match( '/^(\d+)\s*-\s*(\d+)$/u', $etykieta, $m ) ) {
		return array( (int) $m[1], (int) $m[2] );
	}
	if ( preg_match( '/^(\d+)\s+i\s+więcej$/u', $etykieta, $m ) ) {
		return array( (int) $m[1], null );
	}
	return null;
}

/**
 * Sanityzacja zestawu progow: id, nazwa, zakresy array( od, do|null ) i ceny (albo null, gdy zestaw ich nie ma).
 *
 * @param mixed $zestaw Zestaw.
 * @return array{id:string,nazwa:string,zakresy:array,ceny:array|null}|null Null, gdy zestaw jest pusty albo bledny.
 */
function kw_uslugi_bib_czysc_zestaw( $zestaw ) {
	if ( ! is_array( $zestaw ) || ! isset( $zestaw['zakresy'] ) || ! is_array( $zestaw['zakresy'] ) ) {
		return null;
	}
	$zakresy = array();
	foreach ( array_slice( array_values( $zestaw['zakresy'] ), 0, 12 ) as $para ) {
		$para = is_array( $para ) ? array_values( $para ) : array();
		$od   = isset( $para[0] ) && is_numeric( $para[0] ) ? (int) $para[0] : -1;
		$do   = isset( $para[1] ) && '' !== $para[1] ? ( is_numeric( $para[1] ) ? (int) $para[1] : -1 ) : null;
		if ( $od < 0 || $od > 99999 || ( null !== $do && ( $do < $od || $do > 99999 ) ) ) {
			return null;
		}
		$zakresy[] = array( $od, $do );
	}
	if ( ! $zakresy ) {
		return null;
	}
	$ceny = null;
	if ( isset( $zestaw['ceny'] ) && is_array( $zestaw['ceny'] ) ) {
		$ceny = array();
		foreach ( array_keys( $zakresy ) as $i ) {
			$ceny[] = kw_uslugi_czysc_kwote( $zestaw['ceny'][ $i ] ?? '' );
		}
		if ( array() === array_filter( $ceny, 'strlen' ) ) {
			$ceny = null;
		}
	}
	$id = isset( $zestaw['id'] ) && is_string( $zestaw['id'] ) ? substr( sanitize_key( $zestaw['id'] ), 0, 40 ) : '';
	if ( '' === $id ) {
		// Ten sam zestaw (zakresy i ceny) ma zawsze ten sam identyfikator, wiec nie trafia do biblioteki drugi raz.
		$id = 'z' . substr( md5( (string) wp_json_encode( array( $zakresy, $ceny ) ) ), 0, 12 );
	}
	$nazwa = kw_uslugi_czysc_tekst_pola( $zestaw['nazwa'] ?? '', 80 );
	if ( '' === $nazwa ) {
		$nazwa = 'Zestaw progów (' . count( $zakresy ) . ')';
	}
	return array(
		'id'      => $id,
		'nazwa'   => $nazwa,
		'zakresy' => $zakresy,
		'ceny'    => $ceny,
	);
}

/**
 * Sanityzacja calej biblioteki: kazda pozycja jak pozycja listy uslugi (jeden wiersz, do 200 znakow, bez znacznikow),
 * bez powtorzen, z limitem pozycji i zestawow.
 *
 * @param mixed $dane Tablica albo tekst JSON.
 * @return array{w_cenie:string[],dodatkowo:string[],nie_obejmuje:string[],czynniki:string[],progi:array,wersja:int}
 */
function kw_uslugi_bib_czysc( $dane ) {
	if ( is_string( $dane ) ) {
		$dane = json_decode( $dane, true );
	}
	$dane = is_array( $dane ) ? $dane : array();
	$bib  = array();
	foreach ( array_keys( kw_uslugi_bib_listy() ) as $klucz ) {
		$lista = array();
		foreach ( is_array( $dane[ $klucz ] ?? null ) ? $dane[ $klucz ] : array() as $pozycja ) {
			$pozycja = is_scalar( $pozycja ) ? kw_uslugi_czysc_tekst_pola( $pozycja, 200 ) : '';
			if ( '' !== $pozycja && ! in_array( $pozycja, $lista, true ) ) {
				$lista[] = $pozycja;
			}
		}
		$bib[ $klucz ] = array_slice( $lista, 0, KW_USLUGI_BIB_POZYCJE );
	}
	$zestawy = array();
	foreach ( is_array( $dane['progi'] ?? null ) ? $dane['progi'] : array() as $zestaw ) {
		$zestaw = kw_uslugi_bib_czysc_zestaw( $zestaw );
		if ( $zestaw && ! isset( $zestawy[ $zestaw['id'] ] ) ) {
			$zestawy[ $zestaw['id'] ] = $zestaw;
		}
	}
	$bib['progi']  = array_slice( array_values( $zestawy ), 0, KW_USLUGI_BIB_ZESTAWY );
	$bib['wersja'] = 1;
	return $bib;
}

/**
 * Biblioteka na start: pozycje list ze wszystkich uslug (bez kosza) w kolejnosci cennika i typowe progi biura.
 *
 * @return array
 */
function kw_uslugi_bib_zasiej() {
	$bib = array( 'progi' => array() );
	$ids = get_posts(
		array(
			'post_type'      => 'kw_usluga',
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => 200, // ponytail: sufit jak w kw_uslugi_pobierz(); przy wiekszej liczbie uslug stronicowac.
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'fields'         => 'ids',
		)
	);
	foreach ( kw_uslugi_bib_listy() as $klucz => $pole ) {
		$bib[ $klucz ] = array();
		foreach ( $ids as $id ) {
			$tekst = kw_uslugi_czysc_liste( get_post_meta( $id, $pole, true ) );
			if ( '' !== $tekst ) {
				$bib[ $klucz ] = array_merge( $bib[ $klucz ], explode( "\n", $tekst ) );
			}
		}
	}
	$kategorie = kw_uslugi_kategorie();
	foreach ( kw_uslugi_kreator_typowe_progi() as $slug => $etykiety ) {
		$zakresy = array_filter( array_map( 'kw_uslugi_bib_zakres', $etykiety ) );
		if ( count( $zakresy ) !== count( $etykiety ) ) {
			continue; // etykieta w innym zapisie niz "11-20" / "201 i więcej": zestaw pominiety, zamiast zgadywac
		}
		$nazwa          = '' === $slug ? 'KPiR i ryczałt' : ( $kategorie[ $slug ]['nazwa'] ?? ucfirst( (string) $slug ) );
		$bib['progi'][] = array(
			'id'      => '' === $slug ? 'typowe' : 'typowe-' . $slug,
			'nazwa'   => $nazwa . ': ' . count( $zakresy ) . ' progów',
			'zakresy' => array_values( $zakresy ),
			'ceny'    => null,
		);
	}
	return kw_uslugi_bib_czysc( $bib );
}

/**
 * Biblioteka z bazy; przy pierwszym uzyciu zasiana i zapisana (bez autoload: potrzebna tylko na ekranie uslugi).
 *
 * @return array
 */
function kw_uslugi_bib_pobierz() {
	$zapis = get_option( KW_USLUGI_BIB_OPCJA );
	if ( false === $zapis ) {
		$bib = kw_uslugi_bib_zasiej();
		add_option( KW_USLUGI_BIB_OPCJA, (string) wp_json_encode( $bib, JSON_UNESCAPED_UNICODE ), '', false );
		return $bib;
	}
	return kw_uslugi_bib_czysc( $zapis );
}

/**
 * Zapis biblioteki (po sanityzacji).
 *
 * @param array $bib Biblioteka.
 */
function kw_uslugi_bib_zapisz( array $bib ) {
	update_option( KW_USLUGI_BIB_OPCJA, (string) wp_json_encode( kw_uslugi_bib_czysc( $bib ), JSON_UNESCAPED_UNICODE ), false );
}

/**
 * Dopisanie albo usuniecie pozycji biblioteki. Czysta funkcja (token i uprawnienia sprawdza sama), do samotestu.
 * Pozycja, ktora juz jest, nie dopisuje sie drugi raz; usuniecie pozycji, ktorej nie ma, nic nie zmienia.
 *
 * @param string $akcja   dodaj albo usun.
 * @param string $rodzaj  w_cenie, dodatkowo, nie_obejmuje, czynniki albo progi.
 * @param mixed  $wartosc Tekst pozycji. Progi: zestaw w JSON {nazwa, zakresy, ceny} (dodaj) albo jego id (usun).
 * @param string $token   Token z ekranu uslugi.
 * @return array{kod:string,biblioteka?:array,id?:string} Kod: ok, token, prawa, akcja, rodzaj, pusto, pelna.
 */
function kw_uslugi_bib_zmien( $akcja, $rodzaj, $wartosc, $token ) {
	if ( ! wp_verify_nonce( (string) $token, 'kw_uslugi_bib' ) ) {
		return array( 'kod' => 'token' );
	}
	$typ = get_post_type_object( 'kw_usluga' );
	if ( ! $typ || ! current_user_can( $typ->cap->edit_posts ) ) {
		return array( 'kod' => 'prawa' );
	}
	if ( ! in_array( $akcja, array( 'dodaj', 'usun' ), true ) ) {
		return array( 'kod' => 'akcja' );
	}
	$wartosc = is_scalar( $wartosc ) ? substr( (string) $wartosc, 0, KW_USLUGI_BIB_WEJSCIE ) : '';
	$bib     = kw_uslugi_bib_pobierz();
	$wynik   = array( 'kod' => 'ok' );
	if ( 'progi' === $rodzaj ) {
		if ( 'dodaj' === $akcja ) {
			$zestaw = json_decode( $wartosc, true );
			if ( is_array( $zestaw ) ) {
				unset( $zestaw['id'] ); // identyfikator nadaje serwer (z zakresow i cen)
			}
			$zestaw = kw_uslugi_bib_czysc_zestaw( $zestaw );
			if ( ! $zestaw ) {
				return array( 'kod' => 'pusto' );
			}
			if ( ! in_array( $zestaw['id'], wp_list_pluck( $bib['progi'], 'id' ), true ) ) {
				if ( count( $bib['progi'] ) >= KW_USLUGI_BIB_ZESTAWY ) {
					return array( 'kod' => 'pelna' );
				}
				$bib['progi'][] = $zestaw;
			}
			$wynik['id'] = $zestaw['id'];
		} else {
			$id           = sanitize_key( $wartosc );
			$bib['progi'] = array_values(
				array_filter(
					$bib['progi'],
					static function ( $z ) use ( $id ) {
						return $z['id'] !== $id;
					}
				)
			);
			$wynik['id']  = $id;
		}
	} elseif ( isset( kw_uslugi_bib_listy()[ $rodzaj ] ) ) {
		$tekst = kw_uslugi_czysc_tekst_pola( $wartosc, 200 );
		if ( '' === $tekst ) {
			return array( 'kod' => 'pusto' );
		}
		if ( 'usun' === $akcja ) {
			$bib[ $rodzaj ] = array_values( array_diff( $bib[ $rodzaj ], array( $tekst ) ) );
		} elseif ( ! in_array( $tekst, $bib[ $rodzaj ], true ) ) {
			if ( count( $bib[ $rodzaj ] ) >= KW_USLUGI_BIB_POZYCJE ) {
				return array( 'kod' => 'pelna' );
			}
			$bib[ $rodzaj ][] = $tekst;
		}
		$wynik['tekst'] = $tekst;
	} else {
		return array( 'kod' => 'rodzaj' );
	}
	kw_uslugi_bib_zapisz( $bib );
	$wynik['biblioteka'] = kw_uslugi_bib_pobierz();
	return $wynik;
}

/**
 * AJAX: wspolna obsluga dopisania i usuniecia (kw_uslugi_bib_dodaj, kw_uslugi_bib_usun).
 *
 * @param string $akcja dodaj albo usun.
 */
function kw_uslugi_bib_ajax( $akcja ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- token i sanityzacja w kw_uslugi_bib_zmien().
	$rodzaj  = isset( $_POST['rodzaj'] ) ? sanitize_key( wp_unslash( $_POST['rodzaj'] ) ) : '';
	$wartosc = isset( $_POST['wartosc'] ) && is_string( $_POST['wartosc'] ) ? wp_unslash( $_POST['wartosc'] ) : '';
	$token   = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
	// phpcs:enable
	$wynik = kw_uslugi_bib_zmien( $akcja, $rodzaj, $wartosc, $token );
	if ( 'ok' === $wynik['kod'] ) {
		wp_send_json_success( $wynik );
	}
	wp_send_json_error( array( 'kod' => $wynik['kod'] ), in_array( $wynik['kod'], array( 'token', 'prawa' ), true ) ? 403 : 400 );
}

/**
 * AJAX: dopisz pozycje albo zestaw progow do biblioteki.
 */
function kw_uslugi_bib_ajax_dodaj() {
	kw_uslugi_bib_ajax( 'dodaj' );
}
add_action( 'wp_ajax_kw_uslugi_bib_dodaj', 'kw_uslugi_bib_ajax_dodaj' );

/**
 * AJAX: usun pozycje albo zestaw progow z biblioteki.
 */
function kw_uslugi_bib_ajax_usun() {
	kw_uslugi_bib_ajax( 'usun' );
}
add_action( 'wp_ajax_kw_uslugi_bib_usun', 'kw_uslugi_bib_ajax_usun' );
