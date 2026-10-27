<?php
/**
 * Moduł "Edytor treści": jedno źródło prawdy dla tekstów, zdjęć (z kadrem), filmów i list strony,
 * z panelem edycji z podglądem na żywo, historią, bramką wersji i WP-CLI.
 *
 * Motyw rejestruje schemat filtrem `colorify_tresci_rejestr` (docs/edytor-tresci.md). Bez schematu albo z modułem
 * wyłączonym w Ustawienia, Colorify (domyślnie) ten plik tylko definiuje funkcje i nie podpina żadnych hooków.
 *
 * Dane: opcja z rejestru (domyślnie colorify_tresci_dane) = tylko klucze różne od domyślnych ze schematu;
 * historia 10 wersji w opcji <opcja>_historia. Każdy zapis (panel, WP-CLI, AI) przechodzi przez
 * colorify_tresci_z_formularza() + colorify_tresci_zapisz().
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_TRESCI_OPTION = 'colorify_tresci';

/** Czy moduł jest włączony w Ustawienia, Colorify (domyślnie nie). */
function colorify_tresci_aktywny(): bool {
	return 'yes' === get_option( COLORIFY_TRESCI_OPTION, 'no' );
}

/** Jedno pole schematu (pomocnik dla motywów). */
function colorify_tresci_pole( $type, $label, $default, $hint = '', $x = array() ) {
	return array_merge( array( 'type' => $type, 'label' => $label, 'default' => $default, 'hint' => $hint ), $x );
}

/** Wbudowane typy list: kolumny key => array( typ, etykieta, extra ). Pierwsza kolumna jest wymagana (pusta = wiersz pominięty). */
function colorify_tresci_listy_domyslne() {
	return array(
		'faq'    => array(
			'q' => array( 'text', 'Pytanie' ),
			'a' => array( 'area', 'Odpowiedź', array( 'multi' => true, 'rows' => 4 ) ),
		),
		'ig'     => array(
			'img' => array( 'media', 'Zdjęcie', array( 'kind' => 'image', 'kadr' => array( 'r' => '4/5', 'rm' => '4/5', 'xy' => array( 50, 50 ) ) ) ),
			'url' => array( 'url', 'Dokąd prowadzi zdjęcie (link)' ),
			'alt' => array( 'text', 'Opis zdjęcia' ),
		),
		'punkty' => array(
			't' => array( 'area', 'Punkt', array( 'rows' => 2 ) ),
		),
		'menu'   => array(
			't' => array( 'text', 'Nazwa' ),
			'u' => array( 'url', 'Adres (link)' ),
		),
	);
}

/**
 * Rejestr motywu (filtr colorify_tresci_rejestr), czytany leniwie, raz. Klucze i domyślne:
 * opcja (nazwa opcji z danymi), slug (menu), cli (nazwa polecenia WP-CLI obok colorify-tresci), tytul, ikona, pozycja,
 * cap (uprawnienie), sekcje (schemat), listy (własne typy list), strony (id => url, nazwa, post, karta),
 * plik_url (callable: odwołanie do pliku motywu -> URL), gdzie (linki "resztę edytujesz tutaj"), lead, fonty (URL arkusza
 * czcionek dla panelu), przyciski_regex (klucze trafiające do grupy "Przyciski i linki").
 */
function colorify_tresci_rejestr(): array {
	static $r = null;
	if ( null === $r ) {
		$r  = (array) apply_filters( 'colorify_tresci_rejestr', array() );
		$r += array(
			'opcja'           => 'colorify_tresci_dane',
			'slug'            => 'colorify-tresci',
			'cli'             => '',
			'tytul'           => 'Treści strony',
			'ikona'           => 'dashicons-edit-page',
			'pozycja'         => 22,
			'cap'             => 'edit_pages',
			'sekcje'          => array(),
			'listy'           => array(),
			'strony'          => array(),
			'plik_url'        => null,
			'gdzie'           => array(),
			'lead'            => '',
			'fonty'           => '',
			'przyciski_regex' => 'przycisk|link|button|cta',
		);
		$r['listy'] = array_merge( colorify_tresci_listy_domyslne(), (array) $r['listy'] );
		if ( ! $r['strony'] ) {
			$r['strony'] = array( 'home' => array( 'url' => home_url( '/' ), 'nazwa' => 'Strona główna', 'post' => (int) get_option( 'page_on_front' ), 'karta' => '' ) );
		}
	}
	return $r;
}

/** Moduł włączony i motyw zarejestrował schemat: dopiero wtedy jest panel, podgląd i WP-CLI. */
function colorify_tresci_dostepny(): bool {
	return colorify_tresci_aktywny() && ! empty( colorify_tresci_rejestr()['sekcje'] );
}

function colorify_tresci_opcja_nazwa() {
	return (string) colorify_tresci_rejestr()['opcja'];
}
function colorify_tresci_slug() {
	return (string) colorify_tresci_rejestr()['slug'];
}
function colorify_tresci_cap() {
	return (string) colorify_tresci_rejestr()['cap'];
}
function colorify_tresci_strony() {
	return (array) colorify_tresci_rejestr()['strony'];
}
function colorify_tresci_schema() {
	return (array) colorify_tresci_rejestr()['sekcje'];
}
function colorify_tresci_listy() {
	return (array) colorify_tresci_rejestr()['listy'];
}
function colorify_tresci_jest_lista( $type ) {
	return isset( colorify_tresci_listy()[ $type ] );
}

/** Domyślny kadr pola zdjęcia: x, y w %, z = przybliżenie. */
function colorify_tresci_kadr_domyslny( $f ) {
	$xy = isset( $f['kadr']['xy'] ) ? $f['kadr']['xy'] : array( 50, 50 );
	return array( 'x' => (int) $xy[0], 'y' => (int) $xy[1], 'z' => 1.0 );
}

/** Płaska mapa klucz => pole (+ "section"), raz. Pole zdjęcia z 'kadr' dostaje wirtualne "<klucz>__kadr". */
function colorify_tresci_pola() {
	static $flat = null;
	if ( null === $flat ) {
		$flat = array();
		foreach ( colorify_tresci_schema() as $sid => $sec ) {
			foreach ( (array) $sec['fields'] as $k => $f ) {
				$f['section'] = $sid;
				$flat[ $k ]   = $f;
				if ( 'media' === $f['type'] && ! empty( $f['kadr'] ) ) {
					$flat[ $k . '__kadr' ] = array( 'type' => 'kadr', 'label' => $f['label'] . ': kadr', 'default' => colorify_tresci_kadr_domyslny( $f ), 'hint' => '', 'section' => $sid, 'dla' => $k, 'kadr' => $f['kadr'] );
				}
			}
		}
	}
	return $flat;
}

/* ---------------------------------------------------------------------------
 * Przełącznik w Ustawienia, Colorify (wiersz + zapis; zawsze dostępne, niezależnie od stanu modułu).
 * ------------------------------------------------------------------------- */

/** Zapis przełącznika z formularza ustawień; ukryte pole *_present mówi, że przełącznik był w formularzu. */
function colorify_tresci_save_from_input( array $input ): void {
	if ( empty( $input['colorify_tresci_present'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	update_option( COLORIFY_TRESCI_OPTION, ! empty( $input['colorify_tresci'] ) ? 'yes' : 'no' );
}

/** Wiersz "Edytor treści" na ekranie ustawień. */
function colorify_tresci_settings_row(): void {
	$n = count( colorify_tresci_schema() );
	echo '<div class="colorify-site colorify-style-row">';
	echo '<div class="colorify-site__text"><p class="colorify-site__title">' . esc_html__( 'Edytor treści', 'colorify-by-inyfinn' ) . '</p>';
	echo '<p class="colorify-site__hint">' . esc_html__( 'Panel "Treści strony": teksty, zdjęcia z kadrowaniem, filmy i listy strony w jednym miejscu, z podglądem na żywo, historią zmian i poleceniem WP-CLI dla agentów. Działa tylko z motywem, który rejestruje schemat treści filtrem colorify_tresci_rejestr.', 'colorify-by-inyfinn' ) . '</p>';
	echo '<p class="colorify-site__now">' . ( $n
		/* translators: %d: liczba sekcji */
		? esc_html( sprintf( _n( 'Motyw zarejestrował schemat: %d sekcja.', 'Motyw zarejestrował schemat: %d sekcji.', $n, 'colorify-by-inyfinn' ), $n ) )
		: esc_html__( 'Ten motyw nie rejestruje schematu, więc włączenie niczego nie zmieni.', 'colorify-by-inyfinn' ) ) . '</p></div>';
	echo '<label class="colorify-site__check"><input type="hidden" name="colorify_tresci_present" value="1"><input type="checkbox" name="colorify_tresci" value="1"' . checked( colorify_tresci_aktywny(), true, false ) . '> ' . esc_html__( 'Włącz panel Treści strony', 'colorify-by-inyfinn' ) . '</label>';
	echo '</div>';
}

/* ---------------------------------------------------------------------------
 * Live preview (panel iframe): ?colorify_tresci_podglad=1 for users who may edit.
 * Unsaved form values live in a transient per user; elements carry data-cft-key.
 * ------------------------------------------------------------------------- */

function colorify_tresci_podglad() {
	static $on = null;
	if ( null === $on ) {
		$on = ! is_admin() && isset( $_GET['colorify_tresci_podglad'] ) && is_user_logged_in() && current_user_can( colorify_tresci_cap() ) && colorify_tresci_dostepny(); // phpcs:ignore WordPress.Security.NonceVerification
	}
	return $on;
}

/** Prints data-cft-key="<key>" (or another data-* attribute) in preview mode, nothing on the public page. */
function colorify_tresci_podglad_attr( $key, $echo = true, $attr = 'data-cft-key' ) {
	$a = colorify_tresci_podglad() ? ' ' . $attr . '="' . esc_attr( $key ) . '"' : '';
	if ( $echo ) {
		echo $a; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	}
	return $a;
}

/* ---------------------------------------------------------------------------
 * Reading (front end)
 * ------------------------------------------------------------------------- */

/** The saved option itself (never the preview transient). */
function colorify_tresci_opcja() {
	$o = get_option( colorify_tresci_opcja_nazwa(), array() );
	return is_array( $o ) ? $o : array();
}

/** Values saved by the client (only keys that differ from the default); in preview mode the unsaved form. */
function colorify_tresci_stored() {
	if ( colorify_tresci_podglad() ) {
		$t = get_transient( 'colorify_tresci_podglad_' . get_current_user_id() );
		if ( is_array( $t ) ) {
			return $t;
		}
	}
	return colorify_tresci_opcja();
}

/** Version of a snapshot (default: the saved option): the gate "do not overwrite what you have not seen". */
function colorify_tresci_wersja( $snapshot = null ) {
	$s = null === $snapshot ? colorify_tresci_opcja() : (array) $snapshot;
	ksort( $s );
	return substr( md5( wp_json_encode( $s ) ), 0, 12 );
}

/** Keys that differ between two snapshots. */
function colorify_tresci_roznica( $a, $b ) {
	$a   = (array) $a;
	$b   = (array) $b;
	$out = array();
	foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $k ) {
		$x = array_key_exists( $k, $a ) ? $a[ $k ] : null;
		$y = array_key_exists( $k, $b ) ? $b[ $k ] : null;
		if ( $x != $y ) { // phpcs:ignore WordPress.PHP.StrictComparisons
			$out[] = $k;
		}
	}
	return $out;
}

/** Snapshot from the history with the given version, null when it is gone. */
function colorify_tresci_snapshot( $wersja ) {
	foreach ( array_reverse( colorify_tresci_historia() ) as $h ) {
		if ( is_array( $h['d'] ) && ( isset( $h['w'] ) ? $h['w'] : colorify_tresci_wersja( $h['d'] ) ) === $wersja ) {
			return $h['d'];
		}
	}
	return colorify_tresci_wersja( array() ) === $wersja ? array() : null; // the untouched site has no history entry yet
}

/** Value of a field: saved one when present, otherwise the default from the registry. */
function colorify_tresc( $key ) {
	$stored = colorify_tresci_stored();
	if ( array_key_exists( $key, $stored ) ) {
		return $stored[ $key ];
	}
	$pola = colorify_tresci_pola();
	return isset( $pola[ $key ] ) ? $pola[ $key ]['default'] : '';
}

/** Rows of a list field (stored or default), only rows with a first column. */
function colorify_tresc_lista( $key ) {
	$pola = colorify_tresci_pola();
	if ( ! isset( $pola[ $key ] ) || ! colorify_tresci_jest_lista( $pola[ $key ]['type'] ) ) {
		return array();
	}
	$cols = array_keys( colorify_tresci_listy()[ $pola[ $key ]['type'] ] );
	$out  = array();
	foreach ( (array) colorify_tresc( $key ) as $r ) {
		if ( is_array( $r ) && isset( $r[ $cols[0] ] ) && '' !== (string) $r[ $cols[0] ] ) {
			$out[] = $r;
		}
	}
	return $out;
}

/** URL pliku motywu z odwołania w schemacie (np. "img:plik.jpg"): liczy funkcja plik_url z rejestru; '' dla wszystkiego innego. */
function colorify_tresci_plik_url( $ref ) {
	$fn = colorify_tresci_rejestr()['plik_url'];
	return is_string( $ref ) && is_callable( $fn ) ? (string) call_user_func( $fn, $ref ) : '';
}

/** URL of an attachment ID or a theme file reference; '' when there is nothing (or the file was deleted). */
function colorify_tresci_ref_url( $ref ) {
	if ( is_numeric( $ref ) && (int) $ref > 0 ) {
		$url = wp_get_attachment_url( (int) $ref );
		return $url ? $url : '';
	}
	return colorify_tresci_plik_url( $ref );
}

/** Media field as an escaped URL: the chosen attachment, else the default theme file; '' when removed on purpose. */
function colorify_tresc_media( $key ) {
	$pola = colorify_tresci_pola();
	$val  = colorify_tresc( $key );
	if ( '' === $val || null === $val ) {
		return '';
	}
	$url = colorify_tresci_ref_url( $val );
	if ( '' === $url && isset( $pola[ $key ] ) ) {
		$url = colorify_tresci_plik_url( $pola[ $key ]['default'] ); // chosen file is gone: fall back to the default
	}
	return esc_url( $url );
}

/** Tags Kinga may use in paragraphs. */
function colorify_tresci_kses() {
	return array(
		'br'     => array( 'class' => true ),
		'em'     => array(),
		'strong' => array(),
		'span'   => array( 'class' => true ),
		'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
	);
}

/** Field value ready for HTML: plain text escaped, paragraphs through wp_kses. */
function colorify_tresc_html( $key ) {
	$pola = colorify_tresci_pola();
	$v    = (string) colorify_tresc( $key );
	if ( isset( $pola[ $key ] ) && 'area' === $pola[ $key ]['type'] && empty( $pola[ $key ]['czysty'] ) ) {
		return wp_kses( $v, colorify_tresci_kses() );
	}
	return esc_html( $v );
}

/** Prints the escaped text of a field (wrapped in a marked span in preview mode). */
function colorify_tresci_e( $key ) {
	$html = colorify_tresc_html( $key );
	if ( colorify_tresci_podglad() ) {
		$html = '<span' . colorify_tresci_podglad_attr( $key, false ) . '>' . $html . '</span>';
	}
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in colorify_tresc_html()
}

/** Prints <tag class="..."> with the field, nothing at all when the field is empty. */
function colorify_tresci_tag( $key, $tag, $class = '' ) {
	$html = colorify_tresc_html( $key );
	if ( '' === trim( $html ) ) {
		return;
	}
	printf(
		'<%1$s%2$s%4$s>%3$s</%1$s>',
		tag_escape( $tag ),
		'' !== $class ? ' class="' . esc_attr( $class ) . '"' : '',
		$html, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in colorify_tresc_html()
		colorify_tresci_podglad_attr( $key, false ) // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

/** Link field as an escaped URL. */
function colorify_tresc_url( $key ) {
	return esc_url( (string) colorify_tresc( $key ) );
}

/** True when a menu link points at the page being shown (home or a page by permalink). */
function colorify_tresci_link_aktywny( $url ) {
	$url = (string) $url;
	if ( false !== strpos( $url, '#' ) ) {
		return false; // anchors never mark a page as active
	}
	$u = untrailingslashit( $url );
	if ( is_front_page() ) {
		return $u === untrailingslashit( home_url( '/' ) );
	}
	return is_singular() && $u === untrailingslashit( get_permalink() );
}

/** FAQ answer: paragraphs separated by an empty line; the last one has no bottom margin. */
function colorify_tresci_faq_odp( $text ) {
	$paras = preg_split( '/\n{2,}/', trim( (string) $text ) );
	$out   = '';
	$last  = count( $paras ) - 1;
	foreach ( $paras as $i => $p ) {
		$out .= '<p' . ( $i === $last ? ' class="mb-0"' : '' ) . '>' . wp_kses( $p, colorify_tresci_kses() ) . '</p>';
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Crop (kadr): focus point x, y in % and zoom z (1 = none). Drawn with the same
 * CSS on the page and in the panel: object-position + scale around the focus
 * point, clipped back to the element's own box, so no wrapper is needed.
 * ------------------------------------------------------------------------- */

function colorify_kadr_liczba( $v ) {
	return rtrim( rtrim( number_format( (float) $v, 2, '.', '' ), '0' ), '.' );
}

/** Inline CSS for a crop array; '' when it equals $def (the CSS of the theme applies then). */
function colorify_kadr_css( $k, $def ) {
	if ( ! is_array( $k ) ) {
		return '';
	}
	$x = (int) $k['x'];
	$y = (int) $k['y'];
	$z = round( (float) $k['z'], 2 );
	if ( $z <= 1 && $x === (int) $def['x'] && $y === (int) $def['y'] ) {
		return '';
	}
	$css = 'object-position:' . $x . '% ' . $y . '%;';
	if ( $z > 1 ) {
		$f    = 1 - 1 / $z;
		$css .= 'transform:scale(' . colorify_kadr_liczba( $z ) . ');transform-origin:' . $x . '% ' . $y . '%;clip-path:inset('
			. colorify_kadr_liczba( $y * $f ) . '% ' . colorify_kadr_liczba( ( 100 - $x ) * $f ) . '% ' . colorify_kadr_liczba( ( 100 - $y ) * $f ) . '% ' . colorify_kadr_liczba( $x * $f ) . '%);';
	}
	return $css;
}

/** Crop of an image field (stored or default). */
function colorify_tresc_kadr( $key ) {
	$pola = colorify_tresci_pola();
	$def  = isset( $pola[ $key . '__kadr' ] ) ? $pola[ $key . '__kadr' ]['default'] : array( 'x' => 50, 'y' => 50, 'z' => 1 );
	$k    = colorify_tresc( $key . '__kadr' );
	return is_array( $k ) && isset( $k['x'], $k['y'], $k['z'] ) ? $k : $def;
}

/** style="" (+ data-cft-key in preview) for the <img>/<video> that shows an image field. */
function colorify_tresc_kadr_style( $key, $echo = true ) {
	$pola = colorify_tresci_pola();
	$def  = isset( $pola[ $key . '__kadr' ] ) ? $pola[ $key . '__kadr' ]['default'] : array( 'x' => 50, 'y' => 50, 'z' => 1 );
	$css  = colorify_kadr_css( colorify_tresc_kadr( $key ), $def );
	$a    = ( '' !== $css ? ' style="' . esc_attr( $css ) . '"' : '' ) . colorify_tresci_podglad_attr( $key, false );
	if ( $echo ) {
		echo $a; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	}
	return $a;
}

/** The same for a row of a list (e.g. an Instagram tile): $def from the column definition. */
function colorify_kadr_attr_wiersza( $row, $type, $preview_key, $echo = true ) {
	$cols = colorify_tresci_listy()[ $type ];
	$def  = array( 'x' => 50, 'y' => 50, 'z' => 1 );
	foreach ( $cols as $d ) {
		if ( 'media' === $d[0] && ! empty( $d[2]['kadr']['xy'] ) ) {
			$def = array( 'x' => $d[2]['kadr']['xy'][0], 'y' => $d[2]['kadr']['xy'][1], 'z' => 1 );
		}
	}
	$css = colorify_kadr_css( isset( $row['kadr'] ) ? $row['kadr'] : null, $def );
	$a   = ( '' !== $css ? ' style="' . esc_attr( $css ) . '"' : '' ) . colorify_tresci_podglad_attr( $preview_key, false );
	if ( $echo ) {
		echo $a; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	}
	return $a;
}

/** "x,y,z" or array -> crop array; null when empty or invalid. */
function colorify_tresci_kadr_z_tekstu( $v ) {
	if ( is_array( $v ) ) {
		$v = ( $v['x'] ?? '' ) . ',' . ( $v['y'] ?? '' ) . ',' . ( $v['z'] ?? '' );
	}
	$p = array_map( 'trim', explode( ',', (string) $v ) );
	if ( count( $p ) < 2 || ! is_numeric( $p[0] ) || ! is_numeric( $p[1] ) ) {
		return null;
	}
	$z = isset( $p[2] ) && is_numeric( $p[2] ) ? (float) $p[2] : 1;
	return array(
		'x' => max( 0, min( 100, (int) round( (float) $p[0] ) ) ),
		'y' => max( 0, min( 100, (int) round( (float) $p[1] ) ) ),
		'z' => max( 1, min( 2.5, round( $z, 2 ) ) ),
	);
}

/* ---------------------------------------------------------------------------
 * Panel format <-> stored format
 * Stored: light HTML (<br>, <em>, <strong>, <span class>, <a href>), spaces as typed;
 * the theme may glue short words with &nbsp; in its own output filter, so nobody has to type it.
 * The panel editor works on that HTML directly (Enter = <br>, &nbsp; shown as a space).
 * ------------------------------------------------------------------------- */

/** Default / stored value as it appears in a one-line input (or in WP-CLI). */
function colorify_tresci_do_edycji( $v, $type = 'area' ) {
	$v = (string) $v;
	if ( 'area' !== $type ) {
		return str_replace( "\xC2\xA0", ' ', html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
	$v = str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', $v );
	$v = preg_replace( '#<br\s*/?>#i', "\n", $v );
	return str_replace( array( '&lt;', '&gt;', '&amp;' ), array( '<', '>', '&' ), $v );
}

/** Stored paragraph as the HTML the panel editor starts with (&nbsp; as a space, blank line as <br><br>). */
function colorify_tresci_do_wysiwyg( $v ) {
	$v = str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', (string) $v );
	return str_replace( "\n", '<br>', preg_replace( '/\n{2,}/', "\n\n", $v ) );
}

/** Comparable form of a paragraph (whitespace, &nbsp; and <br> variants collapsed). */
function colorify_tresci_norm( $html ) {
	$h = str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', (string) $html );
	$h = preg_replace( '#<br\s*/?>#i', '<br>', $h );
	$h = preg_replace( '/\s+/u', ' ', $h );
	$h = preg_replace( '/\s*<br>\s*/', '<br>', $h );
	$h = preg_replace( '/\s*\n\s*/', "\n", $h );
	return trim( html_entity_decode( $h, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/** Panel/CLI input (HTML with <br> or plain newlines) to the stored form of a paragraph; paragraphs separated by an empty line when $multi. */
function colorify_tresci_z_edycji( $raw, $multi = false ) {
	$raw = trim( str_replace( "\r\n", "\n", (string) $raw ) );
	$raw = preg_replace( '/<(?![a-zA-Z\/])/', '&lt;', $raw ); // a lone "<" (e.g. "<3") is text, not a tag
	if ( $multi ) {
		$raw = preg_replace( '#(\s*<br\s*/?>\s*){2,}#i', "\n\n", $raw );
	}
	$paras = $multi ? preg_split( '/\n{2,}/', $raw ) : array( $raw );
	$out   = array();
	foreach ( $paras as $p ) {
		$p = wp_kses( str_replace( "\n", '<br>', trim( $p ) ), colorify_tresci_kses() );
		$p = preg_replace( '#^(\s*<br\s*/?>\s*)+|(\s*<br\s*/?>\s*)+$#i', '', $p ); // no empty lines at the ends
		if ( '' !== $p ) {
			$out[] = $p;
		}
	}
	return implode( "\n\n", $out );
}

/** Media value from the form: attachment ID (int), theme file reference, or ''. */
function colorify_tresci_media_z_formularza( $v ) {
	$v = is_scalar( $v ) ? trim( (string) $v ) : '';
	if ( ctype_digit( $v ) && (int) $v > 0 ) {
		return (int) $v;
	}
	return '' !== colorify_tresci_plik_url( $v ) ? $v : '';
}

/** Sanitized rows of a list from the form (or JSON from WP-CLI); rows without the first column are dropped. */
function colorify_tresci_lista_z_formularza( $type, $rows ) {
	$cols = colorify_tresci_listy()[ $type ];
	$out  = array();
	foreach ( (array) $rows as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$row = array();
		foreach ( $cols as $c => $d ) {
			$v = $r[ $c ] ?? '';
			if ( 'area' === $d[0] ) {
				$v = colorify_tresci_z_edycji( $v, ! empty( $d[2]['multi'] ) );
			} elseif ( 'url' === $d[0] ) {
				$v = esc_url_raw( trim( (string) $v ) );
			} elseif ( 'media' === $d[0] ) {
				$v = colorify_tresci_media_z_formularza( $v );
				if ( ! empty( $d[2]['kadr'] ) ) {
					$k   = colorify_tresci_kadr_z_tekstu( $r['kadr'] ?? '' );
					$def = array( 'x' => $d[2]['kadr']['xy'][0], 'y' => $d[2]['kadr']['xy'][1], 'z' => 1 );
					if ( $k && '' !== colorify_kadr_css( $k, $def ) ) {
						$row['kadr'] = $k;
					}
				}
			} else {
				$v = sanitize_text_field( (string) $v );
			}
			$row[ $c ] = $v;
		}
		if ( '' !== (string) $row[ array_key_first( $cols ) ] ) {
			$out[] = $row;
		}
	}
	return $out;
}

/** Normalizes a list so that defaults and posted rows compare equal. */
function colorify_tresci_lista_norm( $type, $list ) {
	$cols = colorify_tresci_listy()[ $type ];
	$out  = array();
	foreach ( (array) $list as $r ) {
		$n = array();
		foreach ( $cols as $c => $d ) {
			$v       = $r[ $c ] ?? '';
			$n[ $c ] = 'area' === $d[0] ? colorify_tresci_norm( $v ) : ( 'text' === $d[0] ? colorify_tresci_do_edycji( $v, 'text' ) : (string) $v );
		}
		if ( isset( $r['kadr'] ) ) {
			$n['kadr'] = implode( ',', (array) $r['kadr'] );
		}
		$out[] = $n;
	}
	return $out;
}

/**
 * Builds the new option value from posted values ($post = wp_unslash( $_POST['cft'] ), or key => value from WP-CLI).
 * Only keys that differ from the default are kept; keys not posted keep their stored value; unknown keys are ignored.
 */
function colorify_tresci_z_formularza( array $post, array $stored ) {
	$new = array();
	foreach ( colorify_tresci_pola() as $key => $f ) {
		if ( ! array_key_exists( $key, $post ) ) {
			if ( array_key_exists( $key, $stored ) ) {
				$new[ $key ] = $stored[ $key ]; // field not in the form: keep what was saved
			}
			continue;
		}
		$in   = $post[ $key ];
		$type = $f['type'];
		$def  = $f['default'];

		if ( 'media' === $type ) {
			$in = is_scalar( $in ) ? trim( (string) $in ) : '';
			if ( ctype_digit( $in ) && (int) $in > 0 && get_post( (int) $in ) ) {
				$new[ $key ] = absint( $in );
			} elseif ( '-' === $in && ! empty( $f['removable'] ) && '' !== $def ) {
				$new[ $key ] = ''; // photo removed on purpose
			}
			continue; // anything else = default
		}

		if ( 'kadr' === $type ) {
			$k = colorify_tresci_kadr_z_tekstu( $in );
			if ( $k && '' !== colorify_kadr_css( $k, $def ) ) {
				$new[ $key ] = $k;
			}
			continue;
		}

		if ( colorify_tresci_jest_lista( $type ) ) {
			if ( is_string( $in ) ) {
				$in = json_decode( $in, true ); // WP-CLI passes JSON
			}
			$rows = colorify_tresci_lista_z_formularza( $type, $in );
			if ( colorify_tresci_lista_norm( $type, $rows ) !== colorify_tresci_lista_norm( $type, $def ) ) {
				$new[ $key ] = $rows;
			}
			continue;
		}

		$in = is_scalar( $in ) ? str_replace( "\r\n", "\n", (string) $in ) : '';
		if ( 'area' === $type && empty( $f['czysty'] ) ) {
			$val  = colorify_tresci_z_edycji( $in );
			$same = colorify_tresci_norm( $val ) === colorify_tresci_norm( wp_kses( $def, colorify_tresci_kses() ) );
		} else {
			if ( 'url' === $type ) {
				$val = esc_url_raw( trim( $in ) );
			} elseif ( 'email' === $type ) {
				$val = sanitize_email( trim( $in ) );
			} else {
				$val = sanitize_text_field( $in );
			}
			$same = trim( $in ) === colorify_tresci_do_edycji( $def, 'text' );
		}
		if ( $same ) {
			continue; // untouched default: keep using the one from the theme
		}
		if ( '' === $val && ! empty( $f['req'] ) ) {
			continue; // required field emptied: back to the default
		}
		if ( '' === $val && '' !== trim( wp_strip_all_tags( $in ) ) ) {
			continue; // not a valid URL / e-mail: ignore instead of saving junk
		}
		$new[ $key ] = $val;
	}
	return $new;
}

/* ---------------------------------------------------------------------------
 * History: last 10 saved versions (colorify_tresci_historia), each a full snapshot.
 * ------------------------------------------------------------------------- */

function colorify_tresci_historia() {
	$h = get_option( colorify_tresci_opcja_nazwa() . '_historia', array() );
	return is_array( $h ) ? $h : array();
}

/**
 * Saves $new as the current content and records it ($autor: who, e.g. "AI/WP-CLI"). False when nothing changed.
 * $wersja_bazowa: version the writer saw (colorify_tresci_wersja()); when the option changed since then nothing is
 * written and a WP_Error 'konflikt' comes back with the keys changed in the meantime (data: 'obce', 'baza').
 */
function colorify_tresci_zapisz( array $new, $label, $autor = null, $wersja_bazowa = null ) {
	$old = colorify_tresci_opcja();
	if ( null !== $wersja_bazowa && $wersja_bazowa !== colorify_tresci_wersja( $old ) ) {
		$baza = colorify_tresci_snapshot( $wersja_bazowa );
		$obce = null === $baza ? array_keys( colorify_tresci_pola() ) : colorify_tresci_roznica( $baza, $old );
		return new WP_Error( 'konflikt', 'Treści zmieniły się po otwarciu (np. przez AI). Odśwież, żeby zobaczyć aktualną wersję.', array( 'obce' => $obce, 'baza' => $baza ) );
	}
	if ( $new == $old ) { // phpcs:ignore WordPress.PHP.StrictComparisons -- key order does not matter here
		return false;
	}
	$hist = colorify_tresci_historia();
	if ( ! $hist ) {
		$hist[] = array( 't' => time(), 'u' => '', 'l' => 'Stan początkowy (teksty z motywu)', 'd' => $old, 'w' => colorify_tresci_wersja( $old ) );
	}
	if ( null === $autor ) {
		$user  = wp_get_current_user();
		$autor = $user && $user->exists() ? $user->display_name : '';
	}
	$hist[] = array( 't' => time(), 'u' => (string) $autor, 'l' => $label, 'd' => $new, 'w' => colorify_tresci_wersja( $new ) );
	update_option( colorify_tresci_opcja_nazwa(), $new );
	update_option( colorify_tresci_opcja_nazwa() . '_historia', array_slice( $hist, -10 ), false );
	if ( get_current_user_id() ) {
		delete_transient( 'colorify_tresci_podglad_' . get_current_user_id() );
	}
	return true;
}

/* ---------------------------------------------------------------------------
 * Hooki tylko, gdy moduł jest włączony i motyw zarejestrował schemat (init: motyw zdążył podpiąć filtr rejestru).
 * ------------------------------------------------------------------------- */
add_action(
	'init',
	static function () {
		if ( ! colorify_tresci_dostepny() ) {
			return;
		}
		add_filter(
			'show_admin_bar',
			static function ( $show ) {
				return colorify_tresci_podglad() ? false : $show;
			},
			20
		);
		if ( is_admin() ) {
			require_once __DIR__ . '/colorify-tresci-screen.php';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/colorify-tresci-cli.php';
		}
	},
	5
);
