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
		$pola = colorify_tresci_pola();
		if ( ! isset( $pola[ $key ] ) ) {
			WP_CLI::error( "Nie ma pola „{$key}”. Lista: wp colorify-tresci list" );
		}
		return $pola[ $key ];
	}

	/** Value as the panel/CLI shows it: text decoded, lists and crops as JSON, media as ID or theme file. */
	private function pokaz( $key, $f, $v ) {
		if ( colorify_tresci_jest_lista( $f['type'] ) || 'kadr' === $f['type'] ) {
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
}
WP_CLI::add_command( 'colorify-tresci', 'Colorify_Tresci_CLI' );
if ( '' !== (string) colorify_tresci_rejestr()['cli'] && 'colorify-tresci' !== colorify_tresci_rejestr()['cli'] ) {
	WP_CLI::add_command( colorify_tresci_rejestr()['cli'], 'Colorify_Tresci_CLI' );
}
