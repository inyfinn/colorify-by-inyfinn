<?php
/**
 * Import i eksport cen: Ustawienia, Uslugi, sekcja "Import cen z cennika".
 *
 * Plik (albo tabela wklejona z arkusza) to wejscie niezaufane:
 * - tresc jest tylko czytana jako tekst: nigdy nie jest wykonywana, dolaczana ani zapisywana na dysk;
 * - kazda komorka przechodzi te same sanityzatory co edytor uslugi (kw_uslugi_czysc_*), komorki wygladajace
 *   na formule arkusza sa odrzucane, a eksport zabezpiecza takie teksty apostrofem;
 * - najpierw zawsze podglad (co bylo, co bedzie), zapis dopiero po kliknieciu, z tokenem i uprawnieniem
 *   manage_options oraz edit_post dla kazdej uslugi; zapis sprawdza, czy podglad jest nadal aktualny;
 * - zmieniaja sie wylacznie pola ceny (typ, kwoty, jednostka, netto, tekst, progi); poprzednie wartosci
 *   zostaja w opcji i jednym przyciskiem wracaja ("Cofnij ostatni import").
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

const KW_USLUGI_IMPORT_MAX     = 200000; // Najwiekszy przyjmowany plik albo wklejony tekst, w bajtach.
const KW_USLUGI_IMPORT_WIERSZE = 300; // Najwiecej wierszy z uslugami.
const KW_USLUGI_IMPORT_KOPIA   = 'kw_uslugi_import_kopia'; // Opcja: wartosci sprzed ostatniego importu.

/* ---------------------------------------------------------------------------
 * Slowniki pliku
 * ------------------------------------------------------------------------ */

/**
 * Pola ceny w pliku: klucz meta => naglowek kolumny (progi maja osobne kolumny "Próg 1" ... "Próg 12").
 *
 * @return array<string,string>
 */
function kw_uslugi_import_pola() {
	return array(
		'kw_cena_typ'       => 'Sposób podania ceny',
		'kw_cena_min'       => 'Kwota',
		'kw_cena_max'       => 'Kwota do',
		'kw_cena_jednostka' => 'Za co jest ta kwota',
		'kw_cena_netto'     => 'Netto czy brutto',
		'kw_cena_opis'      => 'Tekst zamiast kwoty',
	);
}

/**
 * Sposoby podania ceny slowami (jak kafle w edytorze uslugi): klucz typu => nazwa w pliku.
 *
 * @return array<string,string>
 */
function kw_uslugi_import_typy() {
	return array(
		'stala'        => 'stała kwota',
		'od'           => 'cena od',
		'przedzial'    => 'przedział',
		'indywidualna' => 'wycena indywidualna',
		'opis'         => 'własny tekst',
	);
}

/**
 * Tekst do porownan bez wielkosci liter, polskich znakow i interpunkcji ("Sposób podania ceny:" => "sposob podania ceny").
 *
 * @param string $tekst Tekst.
 * @return string
 */
function kw_uslugi_import_klucz( $tekst ) {
	return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', remove_accents( mb_strtolower( (string) $tekst ) ) ) );
}

/**
 * Nazwa uslugi do dopasowania: bez wielkosci liter i nadmiarowych odstepow, ale z polskimi znakami i interpunkcja.
 *
 * @param string $nazwa Nazwa.
 * @return string
 */
function kw_uslugi_import_nazwa( $nazwa ) {
	return mb_strtolower( trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $nazwa ) ) );
}

/**
 * Kolumna pliku po naglowku: id, tytul, kategoria, stan, klucz pola ceny albo "prog1" ... "prog12". Nieznana => ''.
 *
 * @param string $naglowek Naglowek kolumny.
 * @return string
 */
function kw_uslugi_import_kolumna( $naglowek ) {
	$znane = array(
		'id'                  => 'id',
		'usluga'              => 'tytul',
		'nazwa'               => 'tytul',
		'nazwa uslugi'        => 'tytul',
		'kategoria'           => 'kategoria',
		'stan'                => 'stan',
		'sposob podania ceny' => 'kw_cena_typ',
		'typ ceny'            => 'kw_cena_typ',
		'kwota'               => 'kw_cena_min',
		'kwota od'            => 'kw_cena_min',
		'cena'                => 'kw_cena_min',
		'cena od'             => 'kw_cena_min',
		'kwota do'            => 'kw_cena_max',
		'cena do'             => 'kw_cena_max',
		'za co jest ta kwota' => 'kw_cena_jednostka',
		'jednostka'           => 'kw_cena_jednostka',
		'netto czy brutto'    => 'kw_cena_netto',
		'netto brutto'        => 'kw_cena_netto',
		'tekst zamiast kwoty' => 'kw_cena_opis',
	);
	$klucz = kw_uslugi_import_klucz( $naglowek );
	if ( isset( $znane[ $klucz ] ) ) {
		return $znane[ $klucz ];
	}
	if ( preg_match( '/^prog ?(\d{1,2})$/', $klucz, $m ) && (int) $m[1] >= 1 && (int) $m[1] <= 12 ) {
		return 'prog' . (int) $m[1];
	}
	return '';
}

/* ---------------------------------------------------------------------------
 * Czytanie pliku
 * ------------------------------------------------------------------------ */

/**
 * Surowa tresc pliku jako czysty tekst UTF-8: limit rozmiaru, kodowanie (UTF-8, UTF-16 z arkusza, Windows-1250
 * z polskiego Excela), bez znakow sterujacych, konce wierszy "\n".
 *
 * @param mixed $surowy Tresc pliku albo wklejony tekst.
 * @return string|WP_Error
 */
function kw_uslugi_import_tekst( $surowy ) {
	$tekst = is_string( $surowy ) ? $surowy : '';
	if ( strlen( $tekst ) > KW_USLUGI_IMPORT_MAX ) {
		return new WP_Error( 'kw_import_duzy', 'Plik jest za duży (limit to 200 KB). Cennik ma kilkadziesiąt wierszy: sprawdź, czy to właściwy plik.' );
	}
	if ( 0 === strncmp( $tekst, "\xEF\xBB\xBF", 3 ) ) {
		$tekst = substr( $tekst, 3 );
	} elseif ( 0 === strncmp( $tekst, "\xFF\xFE", 2 ) ) {
		$tekst = (string) mb_convert_encoding( substr( $tekst, 2 ), 'UTF-8', 'UTF-16LE' );
	} elseif ( 0 === strncmp( $tekst, "\xFE\xFF", 2 ) ) {
		$tekst = (string) mb_convert_encoding( substr( $tekst, 2 ), 'UTF-8', 'UTF-16BE' );
	}
	if ( ! mb_check_encoding( $tekst, 'UTF-8' ) ) {
		// Plik spoza UTF-8 to w praktyce "CSV (rozdzielany srednikami)" z polskiego Excela, czyli Windows-1250.
		$przeliczony = function_exists( 'iconv' ) ? @iconv( 'WINDOWS-1250', 'UTF-8', $tekst ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bajt spoza tabeli znakow daje false i komunikat ponizej.
		if ( ! is_string( $przeliczony ) || '' === $przeliczony ) {
			return new WP_Error( 'kw_import_kodowanie', 'Nie umiem odczytać polskich znaków w tym pliku. Zapisz go w arkuszu jako „CSV UTF-8” albo skopiuj tabelę i wklej ją w pole poniżej.' );
		}
		$tekst = $przeliczony;
	}
	$tekst = (string) preg_replace( '/[^\P{Cc}\t\n\r]/u', '', $tekst );
	$tekst = trim( str_replace( array( "\r\n", "\r" ), "\n", $tekst ), "\n" );
	if ( '' === trim( $tekst ) ) {
		return new WP_Error( 'kw_import_pusty', 'Plik jest pusty. Wybierz plik z cennikiem albo wklej tabelę z arkusza.' );
	}
	return $tekst;
}

/**
 * Tekst pliku rozlozony na wiersze i kolumny. Separator: tabulator (tabela wklejona z arkusza), srednik (CSV z polskiego
 * arkusza) albo przecinek. Pierwszy wiersz to naglowki; nieznany naglowek odrzuca caly plik.
 *
 * @param string $tekst Tekst z kw_uslugi_import_tekst().
 * @return array{blad:string,kolumny:string[],wiersze:array<int,array<string,string>>} Wiersze: numer wiersza w pliku => kolumna => komorka.
 */
function kw_uslugi_import_wiersze( $tekst ) {
	$wynik    = array(
		'blad'    => '',
		'kolumny' => array(),
		'wiersze' => array(),
	);
	$pierwszy = explode( "\n", $tekst, 2 )[0];
	$sep      = false !== strpos( $pierwszy, "\t" ) ? "\t" : ( false !== strpos( $pierwszy, ';' ) ? ';' : ',' );
	$uchwyt   = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- bufor w pamieci dla fgetcsv, nie plik na dysku.
	fwrite( $uchwyt, $tekst ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	rewind( $uchwyt );
	$surowe = array();
	$nr     = 0;
	while ( false !== ( $wiersz = fgetcsv( $uchwyt, 0, $sep, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		++$nr;
		if ( $nr > KW_USLUGI_IMPORT_WIERSZE + 1 ) {
			break;
		}
		$surowe[ $nr ] = array_map(
			static function ( $komorka ) {
				return trim( str_replace( "\u{00A0}", ' ', (string) $komorka ) );
			},
			(array) $wiersz
		);
	}
	fclose( $uchwyt ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	if ( $nr > KW_USLUGI_IMPORT_WIERSZE + 1 ) {
		$wynik['blad'] = 'Plik ma za dużo wierszy (limit to ' . KW_USLUGI_IMPORT_WIERSZE . ' usług). Sprawdź, czy to właściwy plik.';
		return $wynik;
	}

	$kolumny = array();
	foreach ( (array) ( $surowe[1] ?? array() ) as $i => $naglowek ) {
		if ( '' === $naglowek ) {
			continue;
		}
		$kolumna = kw_uslugi_import_kolumna( $naglowek );
		if ( '' === $kolumna ) {
			$wynik['blad'] = 'Nie rozpoznaję kolumny „' . mb_substr( $naglowek, 0, 60 ) . '” w pierwszym wierszu. Dozwolone kolumny: ID, Usługa, Kategoria, Stan, ' . implode( ', ', kw_uslugi_import_pola() ) . ', Próg 1 do Próg 12. Najprościej pobrać aktualny cennik przyciskiem powyżej i zmieniać kwoty w nim.';
			return $wynik;
		}
		if ( in_array( $kolumna, $kolumny, true ) ) {
			$wynik['blad'] = 'Kolumna „' . mb_substr( $naglowek, 0, 60 ) . '” występuje w pliku dwa razy. Zostaw jedną.';
			return $wynik;
		}
		$kolumny[ $i ] = $kolumna;
	}
	if ( ! array_intersect( array( 'id', 'tytul' ), $kolumny ) ) {
		$wynik['blad'] = 'W pierwszym wierszu brakuje kolumny „Usługa” (albo „ID”), więc nie wiem, której usługi dotyczy który wiersz. Pierwszy wiersz musi zawierać nazwy kolumn, tak jak w pliku pobranym z panelu.';
		return $wynik;
	}
	if ( ! array_diff( $kolumny, array( 'id', 'tytul', 'kategoria', 'stan' ) ) ) {
		$wynik['blad'] = 'Plik nie ma żadnej kolumny z ceną (np. „Kwota” albo „Próg 1”), więc nie ma czego importować.';
		return $wynik;
	}
	unset( $surowe[1] );
	foreach ( $surowe as $numer => $komorki ) {
		$wiersz = array();
		foreach ( $kolumny as $i => $kolumna ) {
			$wiersz[ $kolumna ] = (string) ( $komorki[ $i ] ?? '' );
		}
		if ( '' !== implode( '', $wiersz ) ) {
			$wynik['wiersze'][ $numer ] = $wiersz;
		}
	}
	if ( ! $wynik['wiersze'] ) {
		$wynik['blad'] = 'Plik ma tylko wiersz z nazwami kolumn: nie ma w nim żadnej usługi.';
		return $wynik;
	}
	$wynik['kolumny'] = array_values( $kolumny );
	return $wynik;
}

/**
 * Komorka jako zwykly tekst. Komorka zaczynajaca sie od "=", "+", "-" albo "@" wyglada na formule arkusza: null.
 * Apostrof przed takim znakiem (tak eksport zabezpiecza teksty) znaczy "to jest tekst" i jest zdejmowany.
 *
 * @param string $komorka Komorka.
 * @return string|null
 */
function kw_uslugi_import_komorka( $komorka ) {
	$komorka = (string) $komorka;
	if ( '' === $komorka ) {
		return '';
	}
	if ( "'" === $komorka[0] && isset( $komorka[1] ) && false !== strpos( '=+-@', $komorka[1] ) ) {
		return substr( $komorka, 1 );
	}
	return false !== strpos( '=+-@', $komorka[0] ) ? null : $komorka;
}

/**
 * Obecne pola ceny uslugi po sanityzacji (te same funkcje co edytor). Dla ID 0: wartosci nowej, pustej uslugi.
 *
 * @param int $id ID uslugi albo 0.
 * @return array<string,mixed>
 */
function kw_uslugi_import_stan_ceny( $id ) {
	$meta = static function ( $klucz ) use ( $id ) {
		return $id ? get_post_meta( $id, $klucz, true ) : '';
	};
	return array(
		'kw_cena_typ'       => kw_uslugi_czysc_typ_ceny( $meta( 'kw_cena_typ' ) ),
		'kw_cena_min'       => kw_uslugi_czysc_kwote( $meta( 'kw_cena_min' ) ),
		'kw_cena_max'       => kw_uslugi_czysc_kwote( $meta( 'kw_cena_max' ) ),
		'kw_cena_jednostka' => kw_uslugi_czysc_jednostke( $meta( 'kw_cena_jednostka' ) ),
		'kw_cena_netto'     => '0' !== (string) $meta( 'kw_cena_netto' ) ? '1' : '0',
		'kw_cena_opis'      => kw_uslugi_czysc_opis_ceny( $meta( 'kw_cena_opis' ) ),
		'kw_progi'          => kw_uslugi_czysc_progi( $meta( 'kw_progi' ) ),
	);
}

/**
 * Jedna komorka z cena: wartosc po sanityzacji albo komunikat bledu.
 *
 * @param string $klucz   Klucz pola (kw_uslugi_import_pola()).
 * @param string $komorka Komorka po kw_uslugi_import_komorka().
 * @param mixed  $obecna  Obecna wartosc pola (zostaje, gdy komorka jest pusta, a pole nie ma wartosci "pustej").
 * @return array{0:mixed,1:string} Wartosc i blad ('' = bez bledu).
 */
function kw_uslugi_import_czytaj( $klucz, $komorka, $obecna ) {
	$pokaz = '„' . mb_substr( $komorka, 0, 40 ) . '”';
	switch ( $klucz ) {
		case 'kw_cena_typ':
			if ( '' === $komorka ) {
				return array( $obecna, '' );
			}
			$mapa = array(
				'stala' => 'stala',
				'tekst' => 'opis',
			);
			foreach ( kw_uslugi_import_typy() as $typ => $nazwa ) {
				$mapa[ $typ ]                             = $typ;
				$mapa[ kw_uslugi_import_klucz( $nazwa ) ] = $typ;
			}
			$szukany = kw_uslugi_import_klucz( $komorka );
			return isset( $mapa[ $szukany ] ) ? array( $mapa[ $szukany ], '' ) : array( $obecna, 'Nie znam sposobu podania ceny ' . $pokaz . '. Wpisz: ' . implode( ', ', kw_uslugi_import_typy() ) . '.' );

		case 'kw_cena_min':
		case 'kw_cena_max':
			if ( '' === $komorka ) {
				return array( '', '' );
			}
			$kwota = kw_uslugi_czysc_kwote( $komorka );
			return '' !== $kwota ? array( $kwota, '' ) : array( $obecna, 'Kwota ' . $pokaz . ' nie jest liczbą. Wpisz samą liczbę, np. 500 albo 1200,50.' );

		case 'kw_cena_jednostka':
			if ( '' === $komorka ) {
				return array( '', '' );
			}
			$szukana = kw_uslugi_import_klucz( $komorka );
			foreach ( kw_uslugi_jednostki() as $jednostka => $nazwy ) {
				if ( in_array( $szukana, array( kw_uslugi_import_klucz( $jednostka ), kw_uslugi_import_klucz( $nazwy[0] ), kw_uslugi_import_klucz( $nazwy[1] ) ), true ) ) {
					return array( $jednostka, '' );
				}
			}
			return mb_strlen( $komorka ) > 40 ? array( $obecna, 'Tekst ' . $pokaz . ' w kolumnie „Za co jest ta kwota” jest za długi (najwyżej 40 znaków).' ) : array( kw_uslugi_czysc_jednostke( $komorka ), '' );

		case 'kw_cena_netto':
			if ( '' === $komorka ) {
				return array( $obecna, '' );
			}
			$szukane = kw_uslugi_import_klucz( $komorka );
			if ( in_array( $szukane, array( 'netto', 'brutto' ), true ) ) {
				return array( 'netto' === $szukane ? '1' : '0', '' );
			}
			return array( $obecna, 'W kolumnie „Netto czy brutto” wpisz netto albo brutto (jest ' . $pokaz . ').' );

		default: // kw_cena_opis.
			return mb_strlen( $komorka ) > 200 ? array( $obecna, 'Tekst zamiast kwoty jest za długi (najwyżej 200 znaków).' ) : array( kw_uslugi_czysc_opis_ceny( $komorka ), '' );
	}
}

/**
 * Jeden prog z komorki "zakres=cena" albo "zakres=od cena" (np. "1-10=500", "201 i więcej=od 3500").
 * Zakres i cena stoja w jednej komorce, zeby arkusz nie zamienil zakresu "1-10" na date.
 *
 * @param string $komorka Komorka.
 * @return array{0:array|null,1:string} Prog po kw_uslugi_czysc_progi() albo null i komunikat.
 */
function kw_uslugi_import_prog( $komorka ) {
	$blad    = 'Próg „' . mb_substr( $komorka, 0, 40 ) . '” ma zły zapis. Wpisz go jako zakres=cena, np. 1-10=500 albo 201 i więcej=od 3500.';
	$miejsce = strrpos( $komorka, '=' );
	if ( false === $miejsce ) {
		return array( null, $blad );
	}
	$etykieta = trim( substr( $komorka, 0, $miejsce ) );
	$cena     = trim( substr( $komorka, $miejsce + 1 ) );
	$od       = (bool) preg_match( '/^od(?![\p{L}])\s*/iu', $cena );
	$cena     = $od ? (string) preg_replace( '/^od\s*/iu', '', $cena ) : $cena;
	if ( '' === $etykieta || mb_strlen( $etykieta ) > 40 ) {
		return array( null, '' === $etykieta ? $blad : 'Zakres w progu „' . mb_substr( $komorka, 0, 40 ) . '” jest za długi (najwyżej 40 znaków).' );
	}
	$czyste = kw_uslugi_czysc_progi(
		array(
			array(
				'etykieta' => $etykieta,
				'cena'     => $cena,
				'od'       => $od ? '1' : '0',
			),
		)
	);
	return $czyste ? array( $czyste[0], '' ) : array( null, $blad );
}

/**
 * Czy pola ceny po zmianie tworza poprawna cene (te same warunki co edytor przy publikacji).
 *
 * @param array<string,mixed> $pola Pola ceny.
 * @return string[] Komunikaty bledow.
 */
function kw_uslugi_import_sprawdz( array $pola ) {
	$bledy = array();
	$typ   = $pola['kw_cena_typ'];
	if ( in_array( $typ, array( 'stala', 'od', 'przedzial' ), true ) && '' === $pola['kw_cena_min'] && ! $pola['kw_progi'] ) {
		$bledy[] = 'Brakuje kwoty: wpisz ją w kolumnie „Kwota” albo podaj progi.';
	}
	if ( 'przedzial' === $typ && '' !== $pola['kw_cena_min'] && ( '' === $pola['kw_cena_max'] || (float) $pola['kw_cena_max'] <= (float) $pola['kw_cena_min'] ) ) {
		$bledy[] = 'Przy przedziale „Kwota do” musi być większa niż „Kwota”.';
	}
	if ( 'opis' === $typ && '' === $pola['kw_cena_opis'] ) {
		$bledy[] = 'Przy własnym tekście wpisz go w kolumnie „Tekst zamiast kwoty”.';
	}
	return $bledy;
}

/* ---------------------------------------------------------------------------
 * Podglad zmian (niczego nie zapisuje)
 * ------------------------------------------------------------------------ */

/**
 * Porownanie pliku z cennikiem w panelu. Niczego nie zapisuje.
 * Usluge rozpoznaje po ID, a gdy ID jest puste, po dokladnej nazwie; nazwa, ktorej nie ma w panelu, to nowa usluga (szkic).
 * Kolumna, ktorej w pliku nie ma, oznacza "tego pola nie ruszaj". Bledy w jakimkolwiek wierszu blokuja caly zapis.
 *
 * @param mixed $surowy Tresc pliku albo wklejony tekst.
 * @return array{blad:string,bledy:array,uwagi:string[],zmiany:array,nowe:array,bez_zmian:int,wierszy:int,tekst:string,skrot:string}
 */
function kw_uslugi_import_analiza( $surowy ) {
	$a     = array(
		'blad'      => '',
		'bledy'     => array(),
		'uwagi'     => array(),
		'zmiany'    => array(),
		'nowe'      => array(),
		'bez_zmian' => 0,
		'wierszy'   => 0,
		'tekst'     => '',
		'skrot'     => '',
	);
	$tekst = kw_uslugi_import_tekst( $surowy );
	if ( is_wp_error( $tekst ) ) {
		$a['blad'] = $tekst->get_error_message();
		return $a;
	}
	$a['tekst'] = $tekst;
	$plik       = kw_uslugi_import_wiersze( $tekst );
	if ( '' !== $plik['blad'] ) {
		$a['blad'] = $plik['blad'];
		return $a;
	}
	$kolumny  = $plik['kolumny'];
	$progi_nr = array();
	foreach ( $kolumny as $kolumna ) {
		if ( 0 === strpos( $kolumna, 'prog' ) ) {
			$progi_nr[] = (int) substr( $kolumna, 4 );
		}
	}
	sort( $progi_nr );

	$po_id     = array();
	$po_nazwie = array();
	foreach ( get_posts(
		array(
			'post_type'      => 'kw_usluga',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 500, // ponytail: sufit 500 uslug.
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	) as $post ) {
		$po_id[ $post->ID ]                                            = $post;
		$po_nazwie[ kw_uslugi_import_nazwa( $post->post_title ) ][] = $post->ID;
	}
	$kategorie = array();
	foreach ( kw_uslugi_kategorie( true ) as $kategoria ) {
		if ( empty( $kategoria['ukryta'] ) ) {
			$kategorie[ kw_uslugi_import_nazwa( $kategoria['nazwa'] ) ] = array( (int) $kategoria['id'], $kategoria['nazwa'] );
		}
	}
	$typ_wpisu  = get_post_type_object( 'kw_usluga' );
	$wolno_nowe = $typ_wpisu && current_user_can( $typ_wpisu->cap->create_posts );
	$widziane   = array();

	foreach ( $plik['wiersze'] as $nr => $wiersz ) {
		++$a['wierszy'];
		$bledy   = array();
		$komorki = array();
		foreach ( $wiersz as $kolumna => $surowa ) {
			$komorka = kw_uslugi_import_komorka( $surowa );
			if ( null === $komorka ) {
				$bledy[] = 'Komórka „' . mb_substr( $surowa, 0, 40 ) . '” zaczyna się od znaku =, +, - albo @, czyli wygląda na formułę arkusza. Wpisz zwykły tekst albo liczbę.';
				$komorka = '';
			}
			$komorki[ $kolumna ] = $komorka;
		}
		$nazwa = mb_substr( sanitize_text_field( $komorki['tytul'] ?? '' ), 0, 200 );
		$post  = null;
		$kat   = array( 0, '' );
		if ( ! $bledy ) {
			$id = (string) ( $komorki['id'] ?? '' );
			if ( '' !== $id ) {
				if ( ctype_digit( $id ) && isset( $po_id[ (int) $id ] ) ) {
					$post = $po_id[ (int) $id ];
				} else {
					$bledy[] = 'Nie ma usługi o ID „' . mb_substr( $id, 0, 20 ) . '” (może jest w koszu). Jeśli to nowa usługa, zostaw kolumnę ID pustą.';
				}
			} elseif ( '' === $nazwa ) {
				$bledy[] = 'Wiersz nie ma nazwy usługi ani ID, więc nie wiem, czego dotyczy.';
			} else {
				$pasujace = $po_nazwie[ kw_uslugi_import_nazwa( $nazwa ) ] ?? array();
				if ( count( $pasujace ) > 1 ) {
					$bledy[] = 'Więcej niż jedna usługa ma tę nazwę. Wpisz ID tej, o którą chodzi (ID jest w pliku pobranym z panelu).';
				} elseif ( $pasujace ) {
					$post = $po_id[ $pasujace[0] ];
				}
			}
		}
		$kto = $post ? $post->post_title : $nazwa;
		if ( ! $bledy ) {
			$znak = $post ? 'id:' . $post->ID : 'nowa:' . kw_uslugi_import_nazwa( $nazwa );
			if ( isset( $widziane[ $znak ] ) ) {
				$bledy[] = 'Ta usługa jest już w wierszu ' . $widziane[ $znak ] . '. Zostaw jeden wiersz na usługę.';
			} else {
				$widziane[ $znak ] = $nr;
			}
			if ( $post ? ! current_user_can( 'edit_post', $post->ID ) : ! $wolno_nowe ) {
				$bledy[] = 'Nie masz uprawnień do zmiany tej usługi.';
			}
			$nazwa_kat = (string) ( $komorki['kategoria'] ?? '' );
			if ( ! $post && '' !== $nazwa_kat ) {
				if ( isset( $kategorie[ kw_uslugi_import_nazwa( $nazwa_kat ) ] ) ) {
					$kat = $kategorie[ kw_uslugi_import_nazwa( $nazwa_kat ) ];
				} else {
					$bledy[] = 'Nie ma kategorii „' . mb_substr( $nazwa_kat, 0, 60 ) . '”. Popraw nazwę albo najpierw dodaj kategorię w menu Usługi, pozycja Kategorie.';
				}
			}
		}
		if ( ! $bledy ) {
			$bylo   = kw_uslugi_import_stan_ceny( $post ? $post->ID : 0 );
			$bedzie = $bylo;
			foreach ( array_keys( kw_uslugi_import_pola() ) as $klucz ) {
				if ( ! in_array( $klucz, $kolumny, true ) ) {
					continue; // Kolumny nie ma w pliku: pole zostaje.
				}
				list( $bedzie[ $klucz ], $blad ) = kw_uslugi_import_czytaj( $klucz, $komorki[ $klucz ], $bylo[ $klucz ] );
				if ( '' !== $blad ) {
					$bledy[] = $blad;
				}
			}
			if ( $progi_nr ) {
				$bedzie['kw_progi'] = array();
				foreach ( $progi_nr as $n ) {
					if ( '' === $komorki[ 'prog' . $n ] ) {
						continue;
					}
					list( $prog, $blad ) = kw_uslugi_import_prog( $komorki[ 'prog' . $n ] );
					if ( $prog ) {
						$bedzie['kw_progi'][] = $prog;
					} else {
						$bledy[] = $blad;
					}
				}
			}
			// Nowa usluga bez podanego sposobu: wynika z tego, co wypelniono.
			if ( ! $post && '' === (string) ( $komorki['kw_cena_typ'] ?? '' ) ) {
				$bedzie['kw_cena_typ'] = '' !== $bedzie['kw_cena_opis'] ? 'opis' : ( '' !== $bedzie['kw_cena_max'] ? 'przedzial' : ( '' !== $bedzie['kw_cena_min'] ? 'stala' : ( $bedzie['kw_progi'] ? 'od' : 'indywidualna' ) ) );
			}
			$pola = array();
			foreach ( $bedzie as $klucz => $wartosc ) {
				if ( $wartosc !== $bylo[ $klucz ] ) {
					$pola[ $klucz ] = array(
						'bylo'   => $bylo[ $klucz ],
						'bedzie' => $wartosc,
					);
				}
			}
			if ( ! $bledy && $post && ! $pola ) {
				++$a['bez_zmian'];
				continue;
			}
			if ( ! $bledy ) {
				$bledy = kw_uslugi_import_sprawdz( $bedzie );
			}
		}
		if ( $bledy ) {
			foreach ( $bledy as $blad ) {
				$a['bledy'][] = array(
					'wiersz' => $nr,
					'usluga' => $kto,
					'tekst'  => $blad,
				);
			}
			continue;
		}
		if ( $post ) {
			if ( '' !== $nazwa && kw_uslugi_import_nazwa( $nazwa ) !== kw_uslugi_import_nazwa( $post->post_title ) ) {
				$a['uwagi'][] = sprintf( 'Wiersz %1$d: w pliku jest nazwa „%2$s”, a usługa o ID %3$d nazywa się „%4$s”. Zmienię jej ceny; nazwa zostaje bez zmian.', $nr, $nazwa, $post->ID, $post->post_title );
			}
			$a['zmiany'][] = array(
				'id'    => $post->ID,
				'tytul' => $post->post_title,
				'pola'  => $pola,
			);
		} else {
			$a['nowe'][] = array(
				'tytul'           => $nazwa,
				'kategoria'       => $kat[0],
				'kategoria_nazwa' => $kat[1],
				'pola'            => $bedzie,
			);
		}
	}
	$a['skrot'] = md5( (string) wp_json_encode( array( $a['zmiany'], $a['nowe'] ) ) );
	return $a;
}

/* ---------------------------------------------------------------------------
 * Zapis i cofniecie
 * ------------------------------------------------------------------------ */

/**
 * Pole ceny po sanityzacji funkcja przypisana do pola (jak w edytorze uslugi).
 *
 * @param string $klucz   Klucz meta.
 * @param mixed  $wartosc Wartosc.
 * @return mixed
 */
function kw_uslugi_import_czysc( $klucz, $wartosc ) {
	return 'kw_progi' === $klucz ? kw_uslugi_czysc_progi( $wartosc ) : call_user_func( kw_uslugi_pola()[ $klucz ][0], $wartosc );
}

/**
 * Zapis zmian z podgladu. Przed zapisem kazdego pola odklada jego poprzednia wartosc do opcji (jedna kopia: ostatni import).
 * Nowe uslugi powstaja jako szkice ze znacznikiem importu. Nic nie zapisuje, gdy podglad ma bledy albo brak uprawnien.
 *
 * @param array $analiza Wynik kw_uslugi_import_analiza().
 * @return array{zmienione:int,nowe:int}|WP_Error
 */
function kw_uslugi_import_zastosuj( array $analiza ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'kw_import_prawa', 'Nic nie zapisano: nie masz uprawnień do importu cen.' );
	}
	if ( '' !== (string) ( $analiza['blad'] ?? 'x' ) || ! empty( $analiza['bledy'] ) ) {
		return new WP_Error( 'kw_import_bledy', 'Nic nie zapisano: plik ma błędy.' );
	}
	$zmiany = (array) ( $analiza['zmiany'] ?? array() );
	$nowe   = (array) ( $analiza['nowe'] ?? array() );
	if ( ! $zmiany && ! $nowe ) {
		return new WP_Error( 'kw_import_nic', 'Nic nie zapisano: plik zgadza się z cennikiem w panelu.' );
	}
	$klucze = array_merge( array_keys( kw_uslugi_import_pola() ), array( 'kw_progi' ) );
	foreach ( $zmiany as $zmiana ) {
		if ( 'kw_usluga' !== get_post_type( (int) $zmiana['id'] ) || ! current_user_can( 'edit_post', (int) $zmiana['id'] ) || array_diff( array_keys( $zmiana['pola'] ), $klucze ) ) {
			return new WP_Error( 'kw_import_prawa', 'Nic nie zapisano: nie masz uprawnień do zmiany jednej z usług.' );
		}
	}
	$kopia = array(
		'czas'   => time(),
		'kto'    => get_current_user_id(),
		'uslugi' => array(),
		'nowe'   => array(),
	);
	foreach ( $zmiany as $zmiana ) {
		$id = (int) $zmiana['id'];
		foreach ( $zmiana['pola'] as $klucz => $pole ) {
			$wartosc                           = kw_uslugi_import_czysc( $klucz, $pole['bedzie'] );
			$kopia['uslugi'][ $id ][ $klucz ] = array(
				'bylo' => metadata_exists( 'post', $id, $klucz ) ? get_post_meta( $id, $klucz, true ) : null,
				'jest' => $wartosc,
			);
			update_post_meta( $id, $klucz, wp_slash( $wartosc ) );
		}
	}
	foreach ( $nowe as $nowa ) {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'   => 'kw_usluga',
					'post_status' => 'draft',
					'post_title'  => sanitize_text_field( $nowa['tytul'] ),
				)
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}
		if ( ! empty( $nowa['kategoria'] ) ) {
			wp_set_object_terms( $id, array( (int) $nowa['kategoria'] ), 'kw_grupa' );
		}
		foreach ( $klucze as $klucz ) {
			update_post_meta( $id, $klucz, wp_slash( kw_uslugi_import_czysc( $klucz, $nowa['pola'][ $klucz ] ?? '' ) ) );
		}
		update_post_meta( $id, '_kw_import', (string) $kopia['czas'] );
		$kopia['nowe'][] = (int) $id;
	}
	update_option( KW_USLUGI_IMPORT_KOPIA, $kopia, false );
	return array(
		'zmienione' => count( $kopia['uslugi'] ),
		'nowe'      => count( $kopia['nowe'] ),
	);
}

/**
 * Cofniecie ostatniego importu: pola ceny wracaja do wartosci sprzed importu. Pole, ktore ktos zmienil recznie juz po
 * imporcie (ma inna wartosc niz zapisal import), zostaje nietkniete. Szkice utworzone przez import trafiaja do kosza
 * (o ile nadal sa szkicami z jego znacznikiem). Kopia jest jednorazowa.
 *
 * @return array{przywrocone:int,pominiete:int,kosz:int}|WP_Error
 */
function kw_uslugi_import_cofnij() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'kw_import_prawa', 'Nic nie zmieniono: nie masz uprawnień do importu cen.' );
	}
	$kopia = get_option( KW_USLUGI_IMPORT_KOPIA );
	if ( ! is_array( $kopia ) || empty( $kopia['czas'] ) ) {
		return new WP_Error( 'kw_import_brak', 'Nie ma importu do cofnięcia.' );
	}
	$wynik  = array(
		'przywrocone' => 0,
		'pominiete'   => 0,
		'kosz'        => 0,
	);
	$klucze = array_merge( array_keys( kw_uslugi_import_pola() ), array( 'kw_progi' ) );
	foreach ( (array) ( $kopia['uslugi'] ?? array() ) as $id => $pola ) {
		$id      = (int) $id;
		$ruszone = false;
		if ( 'kw_usluga' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			++$wynik['pominiete'];
			continue;
		}
		foreach ( (array) $pola as $klucz => $pole ) {
			if ( ! in_array( $klucz, $klucze, true ) || ! is_array( $pole ) || ! array_key_exists( 'bylo', $pole ) ) {
				continue;
			}
			$teraz = metadata_exists( 'post', $id, $klucz ) ? get_post_meta( $id, $klucz, true ) : null;
			if ( maybe_serialize( $teraz ) !== maybe_serialize( $pole['jest'] ?? null ) ) {
				++$wynik['pominiete']; // Zmienione recznie po imporcie: zostaje.
				continue;
			}
			if ( null === $pole['bylo'] ) {
				delete_post_meta( $id, $klucz );
			} else {
				update_post_meta( $id, $klucz, wp_slash( $pole['bylo'] ) );
			}
			$ruszone = true;
		}
		$wynik['przywrocone'] += $ruszone ? 1 : 0;
	}
	foreach ( (array) ( $kopia['nowe'] ?? array() ) as $id ) {
		$id = (int) $id;
		// Bez kosza (EMPTY_TRASH_DAYS = 0) rdzen usuwalby wpis na stale: wtedy szkic zostaje.
		if ( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS && 'kw_usluga' === get_post_type( $id ) && 'draft' === get_post_status( $id ) && (string) $kopia['czas'] === (string) get_post_meta( $id, '_kw_import', true ) && current_user_can( 'delete_post', $id ) && wp_trash_post( $id ) ) {
			++$wynik['kosz'];
		}
	}
	delete_option( KW_USLUGI_IMPORT_KOPIA );
	return $wynik;
}

/* ---------------------------------------------------------------------------
 * Eksport
 * ------------------------------------------------------------------------ */

/**
 * Komorka pliku CSV: tekst zaczynajacy sie od "=", "+", "-", "@" dostaje apostrof (arkusz nie potraktuje go jak formuly),
 * a komorka ze srednikiem, cudzyslowem albo nowym wierszem jest ujeta w cudzyslow.
 *
 * @param mixed $tekst Tekst.
 * @return string
 */
function kw_uslugi_import_csv_komorka( $tekst ) {
	$tekst = (string) $tekst;
	if ( '' !== $tekst && false !== strpos( "=+-@\t\r", $tekst[0] ) ) {
		$tekst = "'" . $tekst;
	}
	return preg_match( '/[;"\r\n]|^\s|\s$/', $tekst ) ? '"' . str_replace( '"', '""', $tekst ) . '"' : $tekst;
}

/**
 * Aktualny cennik jako plik CSV dla arkusza: UTF-8 ze znacznikiem BOM, srednik, jeden wiersz na usluge, naglowki po polsku,
 * kwoty z przecinkiem. Uslugi w kolejnosci listy w panelu; z kosza nie ma. Ten sam plik wgrany z powrotem nie zmienia niczego.
 *
 * @return string
 */
function kw_uslugi_import_csv() {
	$posty = get_posts(
		array(
			'post_type'      => 'kw_usluga',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => 500,
			'orderby'        => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'  => true,
		)
	);
	if ( function_exists( 'kw_uslugi_lista_sortuj' ) ) {
		$posty = kw_uslugi_lista_sortuj( $posty );
	}
	$naglowek = array_merge( array( 'ID', 'Usługa', 'Kategoria', 'Stan' ), array_values( kw_uslugi_import_pola() ) );
	for ( $n = 1; $n <= 12; $n++ ) {
		$naglowek[] = 'Próg ' . $n;
	}
	$typy      = kw_uslugi_import_typy();
	$jednostki = kw_uslugi_jednostki();
	$stany     = array(
		'publish' => 'na stronie',
		'draft'   => 'szkic',
		'pending' => 'czeka na akceptację',
		'private' => 'prywatna',
		'future'  => 'zaplanowana',
	);
	$kwota     = static function ( $liczba ) {
		return str_replace( '.', ',', (string) $liczba );
	};
	$wiersze   = array( $naglowek );
	foreach ( $posty as $post ) {
		$cena   = kw_uslugi_import_stan_ceny( $post->ID );
		$grupy  = kw_uslugi_z_posta( $post )['grupy'];
		$wiersz = array(
			(string) $post->ID,
			$post->post_title,
			$grupy ? (string) reset( $grupy ) : '',
			$stany[ $post->post_status ] ?? $post->post_status,
			$typy[ $cena['kw_cena_typ'] ],
			$kwota( $cena['kw_cena_min'] ),
			$kwota( $cena['kw_cena_max'] ),
			isset( $jednostki[ $cena['kw_cena_jednostka'] ] ) ? $jednostki[ $cena['kw_cena_jednostka'] ][0] : $cena['kw_cena_jednostka'],
			'1' === $cena['kw_cena_netto'] ? 'netto' : 'brutto',
			$cena['kw_cena_opis'],
		);
		foreach ( $cena['kw_progi'] as $prog ) {
			$wiersz[] = $prog['etykieta'] . '=' . ( $prog['od'] ? 'od ' : '' ) . $kwota( $prog['cena'] );
		}
		$wiersze[] = array_pad( $wiersz, count( $naglowek ), '' );
	}
	$linie = array();
	foreach ( $wiersze as $wiersz ) {
		$linie[] = implode( ';', array_map( 'kw_uslugi_import_csv_komorka', $wiersz ) );
	}
	return "\xEF\xBB\xBF" . implode( "\r\n", $linie ) . "\r\n";
}

/**
 * Pobranie cennika (admin-post.php, GET z tokenem).
 */
function kw_uslugi_import_eksport_zadanie() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nie masz uprawnień do pobrania cennika.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'kw_uslugi_eksport_cen' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="cennik-uslug-' . wp_date( 'Y-m-d' ) . '.csv"' );
	header( 'X-Content-Type-Options: nosniff' );
	echo kw_uslugi_import_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plik CSV do pobrania (text/csv, nosniff), nie HTML; komorki zabezpiecza kw_uslugi_import_csv_komorka().
	exit;
}
add_action( 'admin_post_kw_uslugi_eksport_cen', 'kw_uslugi_import_eksport_zadanie' );

/* ---------------------------------------------------------------------------
 * Ekran: sekcja w Ustawienia, Uslugi
 * ------------------------------------------------------------------------ */

/**
 * Wynik biezacego zadania importu (podglad) dla sekcji na stronie ustawien.
 *
 * @param array|null $ustaw Nowy stan albo null (odczyt).
 * @return array|null
 */
function kw_uslugi_import_stan( $ustaw = null ) {
	static $stan = null;
	if ( null !== $ustaw ) {
		$stan = $ustaw;
	}
	return $stan;
}

/**
 * Tresc do importu z zadania: wgrany plik (czytany z katalogu tymczasowego PHP, nigdzie nie przenoszony) albo wklejony tekst.
 *
 * @return string|WP_Error
 */
function kw_uslugi_import_wejscie() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- token sprawdza kw_uslugi_import_obsluga(); tresc czysci kw_uslugi_import_tekst() i sanityzatory pol.
	$plik = isset( $_FILES['kw_import_plik'] ) && is_array( $_FILES['kw_import_plik'] ) ? $_FILES['kw_import_plik'] : array();
	$kod  = isset( $plik['error'] ) && is_int( $plik['error'] ) ? $plik['error'] : UPLOAD_ERR_NO_FILE;
	if ( UPLOAD_ERR_NO_FILE !== $kod ) {
		if ( in_array( $kod, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) || ( UPLOAD_ERR_OK === $kod && (int) $plik['size'] > KW_USLUGI_IMPORT_MAX ) ) {
			return kw_uslugi_import_tekst( str_repeat( ' ', KW_USLUGI_IMPORT_MAX + 1 ) ); // ten sam komunikat "za duzy"
		}
		if ( UPLOAD_ERR_OK !== $kod || ! is_string( $plik['tmp_name'] ?? null ) || ! is_uploaded_file( $plik['tmp_name'] ) ) {
			return new WP_Error( 'kw_import_plik', 'Nie udało się wgrać pliku. Spróbuj jeszcze raz albo wklej tabelę w pole poniżej.' );
		}
		return (string) file_get_contents( $plik['tmp_name'], false, null, 0, KW_USLUGI_IMPORT_MAX + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- odczyt wgranego pliku z katalogu tymczasowego.
	}
	$tekst = isset( $_POST['kw_import_tekst'] ) && is_string( $_POST['kw_import_tekst'] ) ? wp_unslash( $_POST['kw_import_tekst'] ) : '';
	// phpcs:enable
	return '' === trim( $tekst ) ? new WP_Error( 'kw_import_brak', 'Wybierz plik z cennikiem albo wklej tabelę z arkusza.' ) : $tekst;
}

/**
 * Obsluga formularzy importu na stronie ustawien (przed wydrukiem strony): podglad, zapis, cofniecie.
 * Kazdy krok wymaga uprawnienia manage_options i tokenu. Zapis i cofniecie koncza sie przekierowaniem z kodem wyniku.
 */
function kw_uslugi_import_obsluga() {
	if ( ! isset( $_POST['kw_import_krok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- token sprawdzany dwa wiersze nizej.
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nie masz uprawnień do importu cen.', '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'kw_uslugi_import', 'kw_import_nonce' );
	$krok   = sanitize_key( wp_unslash( $_POST['kw_import_krok'] ) );
	$powrot = admin_url( 'options-general.php?page=kw-uslugi' );
	if ( 'cofnij' === $krok ) {
		$wynik = kw_uslugi_import_cofnij();
		wp_safe_redirect(
			add_query_arg(
				is_wp_error( $wynik ) ? array( 'kw_import' => 'brak' ) : array(
					'kw_import' => 'cofnieto',
					'kw_z'      => $wynik['przywrocone'],
					'kw_n'      => $wynik['kosz'],
					'kw_p'      => $wynik['pominiete'],
				),
				$powrot
			) . '#kw-import'
		);
		exit;
	}
	$wejscie = kw_uslugi_import_wejscie();
	$analiza = kw_uslugi_import_analiza( is_wp_error( $wejscie ) ? '' : $wejscie );
	if ( is_wp_error( $wejscie ) ) {
		$analiza['blad'] = $wejscie->get_error_message();
	}
	if ( 'zastosuj' === $krok && '' === $analiza['blad'] && ! $analiza['bledy'] ) {
		$skrot = isset( $_POST['kw_import_skrot'] ) ? sanitize_key( wp_unslash( $_POST['kw_import_skrot'] ) ) : '';
		if ( hash_equals( $analiza['skrot'], $skrot ) ) {
			$wynik = kw_uslugi_import_zastosuj( $analiza );
			if ( ! is_wp_error( $wynik ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'kw_import' => 'ok',
							'kw_z'      => $wynik['zmienione'],
							'kw_n'      => $wynik['nowe'],
						),
						$powrot
					) . '#kw-import'
				);
				exit;
			}
			$analiza['blad'] = $wynik->get_error_message();
		} else {
			// Bramka "nie nadpisuj tego, czego nie widziales": cennik zmienil sie miedzy podgladem a kliknieciem.
			$analiza['uwagi'][] = 'Od chwili podglądu coś się zmieniło w usługach w panelu, więc nic nie zapisałem. Poniżej jest aktualny podgląd: sprawdź go i kliknij „Zastosuj zmiany” jeszcze raz.';
		}
	}
	kw_uslugi_import_stan( $analiza );
}
add_action( 'load-settings_page_kw-uslugi', 'kw_uslugi_import_obsluga' );

/**
 * Parametry wyniku importu znikaja z paska adresu po wczytaniu strony.
 *
 * @param string[] $parametry Parametry.
 * @return string[]
 */
function kw_uslugi_import_parametry( $parametry ) {
	return array_merge( $parametry, array( 'kw_import', 'kw_z', 'kw_n', 'kw_p' ) );
}
add_filter( 'removable_query_args', 'kw_uslugi_import_parametry' );

/**
 * Wartosc pola ceny slowami, tak jak widzi ja ksiegowy (do podgladu zmian). Progi: jeden wiersz na prog.
 *
 * @param string $klucz   Klucz pola.
 * @param mixed  $wartosc Wartosc po sanityzacji.
 * @return string[] Wiersze tekstu.
 */
function kw_uslugi_import_pokaz( $klucz, $wartosc ) {
	$jednostki = kw_uslugi_jednostki();
	switch ( $klucz ) {
		case 'kw_cena_typ':
			return array( kw_uslugi_import_typy()[ $wartosc ] ?? (string) $wartosc );
		case 'kw_cena_min':
		case 'kw_cena_max':
			return array( '' === $wartosc ? '(puste)' : kw_uslugi_kwota( $wartosc ) . ' zł' );
		case 'kw_cena_jednostka':
			return array( '' === $wartosc ? '(puste)' : ( isset( $jednostki[ $wartosc ] ) ? $jednostki[ $wartosc ][0] : (string) $wartosc ) );
		case 'kw_cena_netto':
			return array( '1' === $wartosc ? 'netto' : 'brutto' );
		case 'kw_progi':
			$wiersze = array();
			foreach ( (array) $wartosc as $prog ) {
				$wiersze[] = $prog['etykieta'] . ': ' . ( $prog['od'] ? 'od ' : '' ) . kw_uslugi_kwota( $prog['cena'] ) . ' zł';
			}
			return $wiersze ? $wiersze : array( '(brak progów)' );
		default:
			return array( '' === $wartosc ? '(puste)' : (string) $wartosc );
	}
}

/**
 * Wiersze wartosci jako HTML; wiersz, ktorego nie ma po drugiej stronie porownania, jest wyrozniony znacznikiem.
 *
 * @param string[] $wiersze  Wiersze tej strony.
 * @param string[] $drugie   Wiersze drugiej strony.
 * @param string   $znacznik Znacznik wyroznienia: del albo strong.
 * @return string
 */
function kw_uslugi_import_wiersze_html( array $wiersze, array $drugie, $znacznik ) {
	$html = array();
	foreach ( $wiersze as $wiersz ) {
		$html[] = in_array( $wiersz, $drugie, true ) ? esc_html( $wiersz ) : '<' . $znacznik . '>' . esc_html( $wiersz ) . '</' . $znacznik . '>';
	}
	return implode( '<br>', $html );
}

/**
 * Podglad zmian jako HTML: liczby, bledy, tabela "bylo / bedzie", nowe uslugi i przycisk zapisu.
 * Caly tekst pochodzacy z pliku przechodzi przez esc_html.
 *
 * @param array $a Wynik kw_uslugi_import_analiza().
 * @return string
 */
function kw_uslugi_import_podglad_html( array $a ) {
	$html = '<div class="kw-imp__podglad" id="kw-import-podglad"><h3>Podgląd zmian</h3>';
	if ( '' !== $a['blad'] ) {
		return $html . '<div class="notice notice-error inline"><p><strong>Nie mogę odczytać tego pliku.</strong> ' . esc_html( $a['blad'] ) . '</p></div></div>';
	}
	$html .= '<p class="kw-imp__liczby">';
	$html .= '<span class="kwa-znak kwa-znak--bez-kropki ' . ( $a['zmiany'] ? 'kwa-znak--zloto' : '' ) . '">Zmienione usługi: ' . count( $a['zmiany'] ) . '</span> ';
	$html .= '<span class="kwa-znak kwa-znak--bez-kropki ' . ( $a['nowe'] ? 'kwa-znak--zloto' : '' ) . '">Nowe usługi: ' . count( $a['nowe'] ) . '</span> ';
	$html .= '<span class="kwa-znak kwa-znak--bez-kropki">Bez zmian: ' . (int) $a['bez_zmian'] . '</span> ';
	$html .= '<span class="kwa-znak kwa-znak--bez-kropki ' . ( $a['bledy'] ? 'kwa-znak--blad' : 'kwa-znak--ok' ) . '">Błędy: ' . count( $a['bledy'] ) . '</span></p>';

	if ( $a['bledy'] ) {
		$html .= '<div class="notice notice-error inline"><p><strong>Nic nie zostanie zapisane, dopóki plik ma błędy.</strong> Popraw je w arkuszu i wgraj plik jeszcze raz.</p><ul class="kw-imp__bledy">';
		foreach ( $a['bledy'] as $blad ) {
			$html .= '<li><strong>Wiersz ' . (int) $blad['wiersz'] . ( '' !== $blad['usluga'] ? ' („' . esc_html( $blad['usluga'] ) . '”)' : '' ) . ':</strong> ' . esc_html( $blad['tekst'] ) . '</li>';
		}
		$html .= '</ul></div>';
	}
	foreach ( $a['uwagi'] as $uwaga ) {
		$html .= '<div class="notice notice-warning inline"><p>' . esc_html( $uwaga ) . '</p></div>';
	}

	$etykiety = kw_uslugi_import_pola() + array( 'kw_progi' => 'Progi cenowe' );
	if ( $a['zmiany'] ) {
		$html .= '<table class="widefat kw-imp__tabela"><caption class="screen-reader-text">Zmiany cen: usługa, pole, wartość dotychczasowa i nowa</caption><thead><tr><th scope="col">Usługa</th><th scope="col">Co się zmienia</th><th scope="col">Było</th><th scope="col">Będzie</th></tr></thead><tbody>';
		foreach ( $a['zmiany'] as $zmiana ) {
			$pierwszy = true;
			foreach ( $zmiana['pola'] as $klucz => $pole ) {
				$bylo   = kw_uslugi_import_pokaz( $klucz, $pole['bylo'] );
				$bedzie = kw_uslugi_import_pokaz( $klucz, $pole['bedzie'] );
				$html  .= '<tr' . ( $pierwszy ? ' class="kw-imp__pierwszy"' : '' ) . '>';
				if ( $pierwszy ) {
					$html .= '<th scope="rowgroup" rowspan="' . count( $zmiana['pola'] ) . '" class="kw-imp__usluga"><a href="' . esc_url( (string) get_edit_post_link( $zmiana['id'], 'raw' ) ) . '">' . esc_html( $zmiana['tytul'] ) . '</a></th>';
				}
				$html    .= '<td data-etykieta="Co się zmienia">' . esc_html( $etykiety[ $klucz ] ?? $klucz ) . '</td>';
				$html    .= '<td data-etykieta="Było" class="kw-imp__bylo">' . kw_uslugi_import_wiersze_html( $bylo, $bedzie, 'del' ) . '</td>';
				$html    .= '<td data-etykieta="Będzie" class="kw-imp__bedzie">' . kw_uslugi_import_wiersze_html( $bedzie, $bylo, 'strong' ) . '</td></tr>';
				$pierwszy = false;
			}
		}
		$html .= '</tbody></table>';
	}
	if ( $a['nowe'] ) {
		$html .= '<h4>Nowe usługi</h4><p class="description">Tych nazw nie ma w panelu, więc powstaną jako szkice: na stronie nie będzie ich widać, dopóki ich nie opublikujesz. Jeśli to literówka w nazwie istniejącej usługi, popraw nazwę w pliku albo wpisz jej ID.</p>';
		$html .= '<table class="widefat kw-imp__tabela"><thead><tr><th scope="col">Nowa usługa</th><th scope="col">Kategoria</th><th scope="col">Cena</th><th scope="col">Progi</th></tr></thead><tbody>';
		foreach ( $a['nowe'] as $nowa ) {
			$p     = $nowa['pola'];
			$cena  = kw_uslugi_cena(
				array(
					'cena_typ'  => $p['kw_cena_typ'],
					'cena_min'  => $p['kw_cena_min'],
					'cena_max'  => $p['kw_cena_max'],
					'cena_opis' => $p['kw_cena_opis'],
					'jednostka' => $p['kw_cena_jednostka'],
					'netto'     => '1' === $p['kw_cena_netto'],
					'progi'     => $p['kw_progi'],
				)
			);
			$html .= '<tr class="kw-imp__pierwszy"><th scope="row" class="kw-imp__usluga">' . esc_html( $nowa['tytul'] ) . '</th><td data-etykieta="Kategoria">' . esc_html( '' !== $nowa['kategoria_nazwa'] ? $nowa['kategoria_nazwa'] : '(bez kategorii)' ) . '</td><td data-etykieta="Cena">' . esc_html( $cena['tekst'] ) . '</td><td data-etykieta="Progi">' . kw_uslugi_import_wiersze_html( kw_uslugi_import_pokaz( 'kw_progi', $p['kw_progi'] ), array(), 'span' ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
	}

	$ile = count( $a['zmiany'] ) + count( $a['nowe'] );
	if ( ! $a['bledy'] && ! $ile ) {
		$html .= '<p><strong>Plik zgadza się z cennikiem w panelu: nie ma czego zmieniać.</strong></p>';
	}
	if ( ! $a['bledy'] && $ile ) {
		$html .= '<form method="post" action="' . esc_url( admin_url( 'options-general.php?page=kw-uslugi' ) ) . '#kw-import" class="kw-imp__zapis">';
		$html .= wp_nonce_field( 'kw_uslugi_import', 'kw_import_nonce', true, false );
		$html .= '<input type="hidden" name="kw_import_krok" value="zastosuj"><input type="hidden" name="kw_import_skrot" value="' . esc_attr( $a['skrot'] ) . '">';
		$html .= '<textarea name="kw_import_tekst" hidden aria-hidden="true">' . esc_textarea( $a['tekst'] ) . '</textarea>';
		$html .= '<button type="submit" class="button button-primary button-hero">Zastosuj zmiany (' . $ile . ')</button> ';
		$html .= '<a class="button button-hero" href="' . esc_url( admin_url( 'options-general.php?page=kw-uslugi' ) ) . '#kw-import">Anuluj</a>';
		$html .= '<p class="description">Dopiero ten przycisk zmienia ceny na stronie. Poprzednie ceny zostają zapamiętane: po imporcie pojawi się tu przycisk „Cofnij ostatni import”.</p></form>';
	}
	return $html . '</div>';
}

/**
 * Sekcja "Import cen z cennika" na stronie Ustawienia, Uslugi.
 */
function kw_uslugi_import_sekcja() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$strona = admin_url( 'options-general.php?page=kw-uslugi' );
	$stan   = kw_uslugi_import_stan();
	$kopia  = get_option( KW_USLUGI_IMPORT_KOPIA );
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- tylko odczyt kodu wyniku i liczb, nic sie nie zapisuje.
	$kod = isset( $_GET['kw_import'] ) ? sanitize_key( wp_unslash( $_GET['kw_import'] ) ) : '';
	$ile = static function ( $nazwa ) {
		return isset( $_GET[ $nazwa ] ) ? absint( $_GET[ $nazwa ] ) : 0;
	};
	// phpcs:enable
	?>
<div class="kw-imp" id="kw-import">
	<h2>Import cen z cennika</h2>
	<p class="kw-imp__wstep">Tu zmienisz ceny wielu usług naraz, bez otwierania każdej osobno. Najpierw zobaczysz, co się zmieni: na stronie nic się nie zmienia, dopóki nie klikniesz „Zastosuj zmiany”. Import zmienia tylko ceny i progi. Nazwy, opisy i listy usług zostają.</p>
	<?php if ( 'ok' === $kod ) : ?>
	<div class="notice notice-success inline"><p><strong>Ceny zapisane.</strong> Zmienione usługi: <?php echo (int) $ile( 'kw_z' ); ?>. Nowe szkice: <?php echo (int) $ile( 'kw_n' ); ?>. Strona pokazuje już nowe ceny. <a href="<?php echo esc_url( home_url( '/#cennik' ) ); ?>" target="_blank" rel="noopener">Zobacz cennik na stronie</a></p></div>
	<?php elseif ( 'cofnieto' === $kod ) : ?>
	<div class="notice notice-success inline"><p><strong>Import cofnięty.</strong> Usługi z przywróconymi cenami: <?php echo (int) $ile( 'kw_z' ); ?>. Szkice z importu przeniesione do kosza: <?php echo (int) $ile( 'kw_n' ); ?>.<?php echo esc_html( $ile( 'kw_p' ) ? ' Pola zmienione ręcznie już po imporcie zostały bez zmian: ' . (int) $ile( 'kw_p' ) . '.' : '' ); ?></p></div>
	<?php elseif ( 'brak' === $kod ) : ?>
	<div class="notice notice-warning inline"><p>Nie ma importu do cofnięcia.</p></div>
	<?php endif; ?>

	<?php if ( is_array( $kopia ) && ! empty( $kopia['czas'] ) ) : ?>
	<form method="post" action="<?php echo esc_url( $strona ); ?>#kw-import" class="kw-imp__ostatni">
		<?php wp_nonce_field( 'kw_uslugi_import', 'kw_import_nonce' ); ?>
		<input type="hidden" name="kw_import_krok" value="cofnij">
		<p><strong>Ostatni import:</strong> <?php echo esc_html( wp_date( 'd.m.Y, H:i', (int) $kopia['czas'] ) ); ?>. Zmienione usługi: <?php echo count( (array) ( $kopia['uslugi'] ?? array() ) ); ?>, nowe szkice: <?php echo count( (array) ( $kopia['nowe'] ?? array() ) ); ?>.</p>
		<p><button type="submit" class="button">Cofnij ostatni import</button></p>
		<p class="description">Przywraca ceny sprzed tego importu. Ceny, które ktoś zmienił ręcznie już po imporcie, zostają bez zmian. Szkice utworzone przez import trafiają do kosza.</p>
	</form>
	<?php endif; ?>

	<?php
	if ( is_array( $stan ) ) {
		echo kw_uslugi_import_podglad_html( $stan ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML zlozony z esc_html, esc_attr, esc_url i esc_textarea.
	}
	?>

	<ol class="kw-imp__kroki">
		<li>
			<strong>Pobierz aktualny cennik.</strong>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kw_uslugi_eksport_cen' ), 'kw_uslugi_eksport_cen' ) ); ?>">Pobierz cennik (plik CSV)</a></p>
			<p class="description">Plik otwiera Excel, LibreOffice i Arkusze Google. Jeden wiersz to jedna usługa.</p>
		</li>
		<li>
			<strong>Zmień kwoty w arkuszu i zapisz plik jako CSV.</strong>
			<p class="description">Kolumn „ID” i „Usługa” nie zmieniaj: po nich rozpoznaję usługę. Próg wpisz w jednej komórce jako zakres=cena, np. <code>1-10=500</code>, a ostatni próg bez górnej granicy jako <code>201 i więcej=od 3500</code>. Pusta komórka kwoty czyści kwotę. Kolumnę, której nie chcesz ruszać, możesz usunąć z pliku. Usługi, której nie ma w pliku, import nie zmienia.</p>
		</li>
		<li>
			<strong>Wgraj plik albo wklej tabelę i zobacz, co się zmieni.</strong>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $strona ); ?>#kw-import" class="kw-imp__formularz">
				<?php wp_nonce_field( 'kw_uslugi_import', 'kw_import_nonce' ); ?>
				<input type="hidden" name="kw_import_krok" value="podglad">
				<p><label for="kw-import-plik">Plik z cennikiem (CSV)</label><br><input type="file" id="kw-import-plik" name="kw_import_plik" accept=".csv,.txt,text/csv,text/plain"></p>
				<p><label for="kw-import-tekst">Albo skopiuj tabelę w arkuszu (razem z wierszem nazw kolumn) i wklej ją tutaj</label><br><textarea id="kw-import-tekst" name="kw_import_tekst" rows="5" class="large-text code" spellcheck="false" aria-describedby="kw-import-tekst-pomoc"></textarea><span class="description" id="kw-import-tekst-pomoc">Zaznacz komórki w arkuszu, skopiuj je i wklej w to pole. Gdy wybierzesz też plik, liczy się plik.</span></p>
				<p><button type="submit" class="button button-primary" aria-describedby="kw-import-uwaga">Pokaż, co się zmieni</button> <span class="description" id="kw-import-uwaga">Ten krok niczego nie zapisuje.</span></p>
			</form>
		</li>
	</ol>
</div>
	<?php
}
