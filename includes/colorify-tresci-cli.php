<?php
/**
 * WP-CLI: wp colorify-tresci list|get|set|reset|wersja (plus the alias from the theme registry, e.g. wp ej-tresci).
 * The same sanitizers, option and history as the panel. Loaded by colorify-tresci.php only under WP-CLI, when the module is on.
 *
 * @package ColorifyByInyfinn
 */

/**
 * Teksty, zdjęcia, kadry i listy strony (to samo, co panel „Treści strony” w Colorify).
 *
 * ## EXAMPLES
 *
 *     wp colorify-tresci list
 *     wp colorify-tresci list --zmienione
 *     wp colorify-tresci get sekcja_naglowek
 *     wp colorify-tresci set sekcja_naglowek "Nowy nagłówek"
 *     wp colorify-tresci set sekcja_foto__kadr 50,30,1.4
 *     wp colorify-tresci set faq '[{"q":"Pytanie?","a":"Odpowiedź."}]'
 *     wp colorify-tresci reset sekcja_naglowek
 *     wp colorify-tresci reset --all
 */
class Colorify_Tresci_CLI {

	private function pole( $key ) {
		if ( in_array( $key, array( '__style', '__uklad', '__sekcje' ), true ) ) {
			$this->wymagaj_zaaw();
			return array( 'type' => 'json', 'label' => $key, 'default' => array(), 'section' => '' ); // dane wyglądu, układu i nowych sekcji (JSON)
		}
		$pola = colorify_tresci_pola();
		if ( ! isset( $pola[ $key ] ) ) {
			WP_CLI::error( "Nie ma pola „{$key}”. Lista: wp colorify-tresci list" );
		}
		return $pola[ $key ];
	}

	/** Value as the panel/CLI shows it: text decoded, lists and crops as JSON, media as ID or theme file. */
	private function pokaz( $key, $f, $v ) {
		if ( colorify_tresci_jest_lista( $f['type'] ) || in_array( $f['type'], array( 'kadr', 'akapity', 'json' ), true ) ) {
			return wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		if ( 'media' === $f['type'] ) {
			return '' === (string) $v ? '(usunięte)' : (string) $v;
		}
		return 'area' === $f['type'] ? (string) $v : colorify_tresci_do_edycji( $v, 'text' );
	}

	/**
	 * Lista pól: klucz, sekcja, typ, wartość (skrót), czy zmienione względem domyślnej.
	 *
	 * [--zmienione]
	 * : Tylko pola z zapisaną własną wartością.
	 *
	 * [--format=<format>]
	 * : table, json, csv. Domyślnie table.
	 *
	 * @subcommand list
	 */
	public function lista( $args, $assoc ) {
		$stored = colorify_tresci_stored();
		$rows   = array();
		foreach ( colorify_tresci_pola() as $key => $f ) {
			$zm = array_key_exists( $key, $stored );
			if ( ! empty( $assoc['zmienione'] ) && ! $zm ) {
				continue;
			}
			$v      = $this->pokaz( $key, $f, colorify_tresc( $key ) );
			$rows[] = array(
				'klucz'     => $key,
				'sekcja'    => $f['section'],
				'typ'       => $f['type'],
				'etykieta'  => $f['label'],
				'zmienione' => $zm ? 'tak' : '',
				'wartosc'   => mb_strlen( $v ) > 70 ? mb_substr( $v, 0, 67 ) . '…' : $v,
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'klucz', 'sekcja', 'typ', 'etykieta', 'zmienione', 'wartosc' ) );
	}

	/**
	 * Aktualna wartość pola (zapisana albo domyślna).
	 *
	 * <klucz>
	 * : Klucz pola, np. sekcja_naglowek, sekcja_foto__kadr, faq.
	 *
	 * [--domyslna]
	 * : Pokaż wartość domyślną z motywu zamiast aktualnej.
	 */
	public function get( $args, $assoc ) {
		$f = $this->pole( $args[0] );
		WP_CLI::line( $this->pokaz( $args[0], $f, ! empty( $assoc['domyslna'] ) ? $f['default'] : colorify_tresc( $args[0] ) ) );
	}

	/**
	 * Zapisuje wartość pola tą samą drogą co panel (sanitizacja, historia z autorem „AI/WP-CLI”).
	 *
	 * <klucz>
	 * : Klucz pola.
	 *
	 * <wartosc>
	 * : Tekst (akapity: lekki HTML albo nowe linie), lista jako JSON, kadr "x,y,zoom" (np. 50,30,1.4), zdjęcie = ID załącznika ("-" usuwa, puste = domyślne).
	 *
	 * [--etykieta=<tekst>]
	 * : Opis w historii. Domyślnie „WP-CLI: <klucz>”.
	 *
	 * [--wersja=<wersja>]
	 * : Wersja z `wp colorify-tresci wersja` z chwili odczytu; gdy ktoś zapisał coś w międzyczasie, zapis jest odrzucony.
	 */
	public function set( $args, $assoc ) {
		$key = $args[0];
		$this->pole( $key );
		$val = $args[1] ?? '';
		$new = colorify_tresci_z_formularza( array( $key => $val ), colorify_tresci_stored() );
		$r   = colorify_tresci_zapisz( $new, $assoc['etykieta'] ?? ( 'WP-CLI: ' . $key ), 'AI/WP-CLI', $assoc['wersja'] ?? null );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() . ' Zmienione w międzyczasie: ' . implode( ', ', (array) $r->get_error_data()['obce'] ) . '. Aktualna wersja: ' . colorify_tresci_wersja() );
		}
		if ( ! $r ) {
			WP_CLI::warning( 'Bez zmian (wartość taka sama jak dotąd albo równa domyślnej).' );
		}
		WP_CLI::success( $key . ' = ' . $this->pokaz( $key, $this->pole( $key ), colorify_tresc( $key ) ) . ' (wersja ' . colorify_tresci_wersja() . ')' );
	}

	/**
	 * Wersja zapisanych treści (skrót). Podaj ją w `set --wersja=`, żeby nie nadpisać cudzych zmian.
	 */
	public function wersja() {
		WP_CLI::line( colorify_tresci_wersja() );
	}

	/**
	 * Przywraca wartość domyślną z motywu (jedno pole albo wszystkie).
	 *
	 * [<klucz>]
	 * : Klucz pola.
	 *
	 * [--all]
	 * : Wszystkie pola do domyślnych (obecna wersja zostaje w historii).
	 */
	public function reset( $args, $assoc ) {
		if ( ! empty( $assoc['all'] ) ) {
			colorify_tresci_zapisz( array(), 'WP-CLI: przywrócono wszystkie domyślne', 'AI/WP-CLI' );
			WP_CLI::success( 'Wszystkie pola domyślne.' );
			return;
		}
		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Podaj klucz albo --all.' );
		}
		$this->pole( $args[0] );
		$new = colorify_tresci_stored();
		unset( $new[ $args[0] ] );
		$ok = colorify_tresci_zapisz( $new, 'WP-CLI: przywrócono domyślne ' . $args[0], 'AI/WP-CLI' );
		WP_CLI::success( $args[0] . ( $ok ? ' przywrócone do domyślnej.' : ' już było domyślne.' ) );
	}

	/* ----- 2.13.0: wygląd, układ, nowe sekcje (ta sama walidacja i ten sam zapis co panel) ----- */

	/** Wygląd, układ i nowe sekcje działają tylko z włączoną „Zaawansowaną edycją” (przełącza właściciel Colorify). */
	private function wymagaj_zaaw() {
		if ( ! colorify_tresci_zaawansowana() ) {
			WP_CLI::error( 'Zaawansowana edycja jest wyłączona. Włącza ją właściciel Colorify: Ustawienia, Colorify, albo wp option update colorify_tresci_zaaw yes --user=<właściciel>.' );
		}
	}

	private function zapisz_post( array $post, $label, $assoc ) {
		$new = colorify_tresci_z_formularza( $post, colorify_tresci_opcja() );
		$r   = colorify_tresci_zapisz( $new, $assoc['etykieta'] ?? $label, 'AI/WP-CLI', $assoc['wersja'] ?? null );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() . ' Zmienione w międzyczasie: ' . implode( ', ', (array) $r->get_error_data()['obce'] ) . '. Aktualna wersja: ' . colorify_tresci_wersja() );
		}
		if ( ! $r ) {
			WP_CLI::warning( 'Bez zmian.' );
		}
		return $r;
	}

	private function json( $v ) {
		return wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Dozwolone właściwości wyglądu i ich tokeny (do `styl`).
	 */
	public function tokeny() {
		$rows = array();
		foreach ( colorify_tresci_styl_wlasciwosci() as $p => $d ) {
			$t      = 'lh' === $p ? array( '1–2.2 (liczba, krok 0.05)' ) : array();
			foreach ( colorify_tresci_tokeny()[ $p ] ?? array() as $k => $x ) {
				$t[] = $k . ' (' . $x[0] . ')';
			}
			$rows[] = array( 'wlasciwosc' => $p, 'nazwa' => $d[0], 'dla' => 's' === $d[2] ? 'sekcja (#id)' : 'tekst', 'tokeny' => implode( ', ', $t ) );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'wlasciwosc', 'nazwa', 'dla', 'tokeny' ) );
	}

	/**
	 * Wygląd tekstu albo odstępy sekcji: tylko tokeny marki (lista: wp colorify-tresci tokeny). Bez ustawień pokazuje obecne.
	 *
	 * <cel>
	 * : Klucz pola (omnie_naglowek), akapit grupy (omnie_akapity.p-abc12345), pole nowej sekcji (s-abc12345.naglowek) albo sekcja (#omnie, #s-abc12345).
	 *
	 * [<ustawienie>...]
	 * : wlasciwosc=token, np. fz=xl c=zloto ta=center; "wlasciwosc=" (puste) usuwa jedno ustawienie.
	 *
	 * [--telefon]
	 * : Ustawienia tylko dla telefonu (do 767 px); bez tego komputer i tablet.
	 *
	 * [--reset]
	 * : Usuń cały wygląd celu (komputer i telefon).
	 *
	 * [--wersja=<wersja>]
	 * : Wersja z `wersja`; gdy ktoś zapisał coś w międzyczasie, zapis jest odrzucony.
	 *
	 * ## EXAMPLES
	 *
	 *     wp colorify-tresci styl omnie_naglowek fz=xl c=zloto
	 *     wp colorify-tresci styl omnie_naglowek fz=l --telefon
	 *     wp colorify-tresci styl "#faq" pt=xl pb=lg
	 *     wp colorify-tresci styl omnie_naglowek --reset
	 */
	public function styl( $args, $assoc ) {
		$this->wymagaj_zaaw();
		$cel  = (string) array_shift( $args );
		$baza = explode( '.', ltrim( $cel, '#' ) )[0];
		$ids  = array();
		foreach ( colorify_tresci_uklad_def() as $def ) {
			$ids = array_merge( $ids, array_keys( $def ) );
		}
		if ( ! isset( colorify_tresci_pola()[ $baza ] ) && ! in_array( $baza, $ids, true ) && ! isset( colorify_tresci_sekcje_nowe( colorify_tresci_opcja() )[ $baza ] ) ) {
			WP_CLI::error( "Nie ma pola ani sekcji „{$baza}”. Pola: wp colorify-tresci list, sekcje: wp colorify-tresci uklad" );
		}
		$st = colorify_tresci_opcja()['__style'] ?? array();
		if ( ! $args && empty( $assoc['reset'] ) ) {
			WP_CLI::line( $this->json( $st[ $cel ] ?? (object) array() ) );
			return;
		}
		if ( ! empty( $assoc['reset'] ) ) {
			unset( $st[ $cel ] );
		}
		$d = ! empty( $assoc['telefon'] ) ? 'm' : 'b';
		foreach ( $args as $a ) {
			$x = array_pad( explode( '=', (string) $a, 2 ), 2, '' );
			if ( ! isset( colorify_tresci_styl_wlasciwosci()[ $x[0] ] ) ) {
				WP_CLI::error( "Nieznana właściwość „{$x[0]}”. Lista: wp colorify-tresci tokeny" );
			}
			if ( '' === $x[1] ) {
				unset( $st[ $cel ][ $d ][ $x[0] ] );
				continue;
			}
			if ( '' === colorify_tresci_styl_css( $x[0], $x[1] ) ) {
				WP_CLI::error( "Nieznany token „{$x[1]}” dla {$x[0]}. Lista: wp colorify-tresci tokeny" );
			}
			$st[ $cel ][ $d ][ $x[0] ] = $x[1];
		}
		$this->zapisz_post( array( '__style' => $st ), 'WP-CLI: wygląd ' . $cel, $assoc );
		WP_CLI::success( $cel . ' = ' . $this->json( colorify_tresci_opcja()['__style'][ $cel ] ?? (object) array() ) . ' (wersja ' . colorify_tresci_wersja() . ')' );
	}

	/**
	 * Kolejność i ukrycie sekcji strony. Bez opcji pokazuje układ.
	 *
	 * [<strona>]
	 * : Id strony z rejestru (np. home, omnie).
	 *
	 * [--kolejnosc=<id,id>]
	 * : Nowa kolejność (brakujące sekcje wstawia system na ich miejsca, sekcja stała zostaje na górze).
	 *
	 * [--ukryj=<id,id>]
	 * : Ukryj sekcje (dane zostają).
	 *
	 * [--pokaz=<id,id>]
	 * : Pokaż ukryte sekcje.
	 *
	 * [--wersja=<wersja>]
	 * : Bramka wersji jak w `set`.
	 */
	public function uklad( $args, $assoc ) {
		$this->wymagaj_zaaw();
		$def = colorify_tresci_uklad_def();
		if ( ! $def ) {
			WP_CLI::error( 'Motyw nie rejestruje układu stron (rejestr "uklad").' );
		}
		$page = $args[0] ?? '';
		if ( '' !== $page && ! isset( $def[ $page ] ) ) {
			WP_CLI::error( "Nie ma strony „{$page}”. Strony: " . implode( ', ', array_keys( $def ) ) );
		}
		$st = colorify_tresci_opcja();
		if ( '' !== $page && ( ! empty( $assoc['kolejnosc'] ) || ! empty( $assoc['ukryj'] ) || ! empty( $assoc['pokaz'] ) ) ) {
			$lista = function ( $k ) use ( $assoc ) {
				return array_filter( array_map( 'trim', explode( ',', (string) ( $assoc[ $k ] ?? '' ) ) ) );
			};
			$u = colorify_tresci_uklad_strony( $page, $st );
			if ( $lista( 'kolejnosc' ) ) {
				$u['o'] = array_values( $lista( 'kolejnosc' ) );
			}
			$u['h']  = array_values( array_diff( array_unique( array_merge( $u['h'], $lista( 'ukryj' ) ) ), $lista( 'pokaz' ) ) );
			$all     = $st['__uklad'] ?? array();
			$all[ $page ] = $u;
			$this->zapisz_post( array( '__uklad' => $all ), 'WP-CLI: układ ' . $page, $assoc );
			$st = colorify_tresci_opcja();
		}
		$nowe = colorify_tresci_sekcje_nowe( $st );
		$rows = array();
		foreach ( '' !== $page ? array( $page ) : array_keys( $def ) as $p ) {
			$u = colorify_tresci_uklad_strony( $p, $st );
			foreach ( $u['o'] as $i => $id ) {
				$nazwa = isset( $def[ $p ][ $id ] ) ? $def[ $p ][ $id ]['label'] : ( colorify_tresci_typy_sekcji()[ $nowe[ $id ]['typ'] ]['nazwa'] ?? '' ) . ': ' . ( $nowe[ $id ]['v']['naglowek'] ?? ( $nowe[ $id ]['v']['cytat'] ?? '' ) );
				$rows[] = array( 'strona' => $p, 'nr' => $i + 1, 'id' => $id, 'nazwa' => $nazwa, 'ukryta' => in_array( $id, $u['h'], true ) ? 'tak' : '', 'rodzaj' => isset( $def[ $p ][ $id ] ) ? ( ! empty( $def[ $p ][ $id ]['stala'] ) ? 'stała' : 'szablon' ) : 'nowa' );
			}
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'strona', 'nr', 'id', 'nazwa', 'ukryta', 'rodzaj' ) );
	}

	/**
	 * Nowe sekcje z biblioteki (tekst, tekst_zdjecie, cytat, cta, odstep).
	 *
	 * <akcja>
	 * : list | dodaj <typ> | set <id> <json pól> | usun <id>
	 *
	 * [<arg>...]
	 * : Typ (dodaj) albo id sekcji i JSON pól (set).
	 *
	 * [--strona=<strona>]
	 * : Strona nowej sekcji (dodaj). Domyślnie pierwsza z rejestru.
	 *
	 * [--po=<id>]
	 * : Wstaw pod tą sekcją (dodaj). Domyślnie na końcu strony.
	 *
	 * [--pola=<json>]
	 * : Wartości pól nowej sekcji, np. '{"naglowek":"Poznajmy się","akapity":[{"id":"p-abc123","w":"tekst","t":"Tekst."}]}'.
	 *
	 * [--wersja=<wersja>]
	 * : Bramka wersji jak w `set`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp colorify-tresci sekcja list
	 *     wp colorify-tresci sekcja dodaj tekst_zdjecie --strona=home --po=omnie --pola='{"naglowek":"Poznajmy się"}'
	 *     wp colorify-tresci sekcja set s-abc12345 '{"tlo":"kremowe"}'
	 *     wp colorify-tresci sekcja usun s-abc12345
	 */
	public function sekcja( $args, $assoc ) {
		$this->wymagaj_zaaw();
		$akcja = $args[0] ?? 'list';
		$sek   = colorify_tresci_sekcje_nowe( colorify_tresci_opcja() );
		$typy  = colorify_tresci_typy_sekcji();
		if ( 'list' === $akcja ) {
			$rows = array();
			foreach ( $sek as $id => $s ) {
				$rows[] = array( 'id' => $id, 'typ' => $s['typ'], 'strona' => $s['strona'], 'pola' => mb_substr( $this->json( $s['v'] ), 0, 90 ) );
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'typ', 'strona', 'pola' ) );
			WP_CLI::line( 'Typy: ' . implode( ', ', array_keys( $typy ) ) );
			return;
		}
		if ( 'dodaj' === $akcja ) {
			$typ    = (string) ( $args[1] ?? '' );
			$strony = array_keys( colorify_tresci_uklad_def() );
			$page   = (string) ( $assoc['strona'] ?? ( $strony[0] ?? '' ) );
			if ( ! isset( $typy[ $typ ] ) || ! in_array( $page, $strony, true ) ) {
				WP_CLI::error( 'Podaj typ (' . implode( ', ', array_keys( $typy ) ) . ') i --strona (' . implode( ', ', $strony ) . ').' );
			}
			$id         = 's-' . strtolower( wp_generate_password( 8, false, false ) );
			$sek[ $id ] = array( 'typ' => $typ, 'strona' => $page, 'v' => colorify_tresci_json( $assoc['pola'] ?? array() ) );
			$st         = colorify_tresci_opcja();
			$o          = colorify_tresci_uklad_norm( $page, $st['__uklad'][ $page ] ?? array(), $sek )['o'];
			$o          = array_values( array_diff( $o, array( $id ) ) );
			$po         = isset( $assoc['po'] ) ? array_search( (string) $assoc['po'], $o, true ) : false;
			array_splice( $o, false === $po ? count( $o ) : $po + 1, 0, array( $id ) );
			$all          = $st['__uklad'] ?? array();
			$all[ $page ] = array( 'o' => $o, 'h' => $st['__uklad'][ $page ]['h'] ?? array() );
			$this->zapisz_post( array( '__sekcje' => $sek, '__uklad' => $all ), 'WP-CLI: nowa sekcja ' . $typ, $assoc );
			WP_CLI::success( 'Dodano ' . $id . ' (' . $typ . ', ' . $page . '). Wersja ' . colorify_tresci_wersja() );
			return;
		}
		$id = (string) ( $args[1] ?? '' );
		if ( ! isset( $sek[ $id ] ) ) {
			WP_CLI::error( "Nie ma sekcji „{$id}”. Lista: wp colorify-tresci sekcja list" );
		}
		if ( 'set' === $akcja ) {
			$sek[ $id ]['v'] = colorify_tresci_json( $args[2] ?? '' ) + $sek[ $id ]['v'];
			$this->zapisz_post( array( '__sekcje' => $sek ), 'WP-CLI: sekcja ' . $id, $assoc );
			WP_CLI::success( $id . ' = ' . $this->json( colorify_tresci_sekcje_nowe( colorify_tresci_opcja() )[ $id ]['v'] ?? array() ) );
			return;
		}
		if ( 'usun' === $akcja ) {
			unset( $sek[ $id ] );
			$this->zapisz_post( array( '__sekcje' => $sek ), 'WP-CLI: usunięto sekcję ' . $id, $assoc );
			WP_CLI::success( 'Usunięto ' . $id . ' (wraca z historii: przywrócenie wersji w panelu).' );
			return;
		}
		WP_CLI::error( 'Akcja: list, dodaj, set albo usun.' );
	}
}
WP_CLI::add_command( 'colorify-tresci', 'Colorify_Tresci_CLI' );
if ( '' !== (string) colorify_tresci_rejestr()['cli'] && 'colorify-tresci' !== colorify_tresci_rejestr()['cli'] ) {
	WP_CLI::add_command( colorify_tresci_rejestr()['cli'], 'Colorify_Tresci_CLI' );
}
