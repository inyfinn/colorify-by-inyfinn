<?php
/**
 * Moduł "Kreator e-maili": edytor szablonów maili wtyczek (temat, nagłówek, akapity, punkty, przycisk, podpis, drobny druk)
 * z chipami zmiennych, wersjami wielokrotnymi (np. 6 wersji na przycisk, losowanie robi wtyczka), podglądem na żywo,
 * testem na własny adres, włącz/wyłącz, "Przywróć domyślne", historią i bramką wersji.
 *
 * Wtyczka rejestruje szablony filtrem `colorify_maile_rejestr` (docs/kreator-maili.md) i zostaje dostawcą danych
 * (wartości zmiennych, dane rekordu, renderer HTML, wysyłka). Dane zostają w opcjach wtyczki (zero migracji).
 * Moduł jest domyślnie WYŁĄCZONY (Ustawienia, Colorify); bez włączenia i bez rejestru ten plik tylko definiuje funkcje.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_MAILE_OPTION = 'colorify_maile';

function colorify_maile_aktywny(): bool {
	return 'yes' === get_option( COLORIFY_MAILE_OPTION, 'no' );
}

/**
 * Rejestr (filtr colorify_maile_rejestr), raz na proces. Klucze główne: slug, tytul, cap, menu (rodzic, etykieta),
 * zastepuje (slug starego ekranu, który ma przekierować na moduł), test_adres, fonty, szablony.
 * Szablon: nazwa, opis, opcja, klucz, historia, wlacznik, pola, domyslne, wersje, pozycyjne, zmienne, dane, rekordy,
 * wartosci, wstaw, temat, html, wyslij (opis w docs/kreator-maili.md).
 */
function colorify_maile_rejestr(): array {
	static $r = null;
	if ( null === $r ) {
		$r  = (array) apply_filters( 'colorify_maile_rejestr', array() );
		$r += array(
			'slug'       => 'colorify-maile',
			'tytul'      => 'Wiadomości do par',
			'cap'        => 'manage_options',
			'menu'       => array( 'rodzic' => '', 'etykieta' => 'Wiadomości' ),
			'zastepuje'  => '',
			'test_adres' => '',
			'fonty'      => '',
			'szablony'   => array(),
		);
		$sz = array();
		foreach ( (array) $r['szablony'] as $id => $t ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $t ) || empty( $t['opcja'] ) || empty( $t['pola'] ) ) {
				continue;
			}
			$t += array(
				'nazwa'     => $id,
				'opis'      => '',
				'klucz'     => '',
				'historia'  => (string) $t['opcja'] . ( ! empty( $t['klucz'] ) ? '_' . $t['klucz'] : '' ) . '_hist',
				'wlacznik'  => '',
				'domyslne'  => array(),
				'wersje'    => 1,
				'pozycyjne' => false,
				'zmienne'   => array(),
				'dane'      => null,
				'rekordy'   => null,
				'wartosci'  => null,
				'wstaw'     => null,
				'temat'     => null,
				'html'      => null,
				'wyslij'    => null,
			);
			$t['wersje'] = max( 1, (int) $t['wersje'] );
			$sz[ $id ]   = $t;
		}
		$r['szablony'] = $sz;
	}
	return $r;
}

function colorify_maile_dostepny(): bool {
	return colorify_maile_aktywny() && ! empty( colorify_maile_rejestr()['szablony'] );
}

function colorify_maile_slug(): string {
	return (string) colorify_maile_rejestr()['slug'];
}

function colorify_maile_cap(): string {
	return (string) colorify_maile_rejestr()['cap'];
}

/** Adres ekranu modułu (szablon opcjonalnie). */
function colorify_maile_url( string $szablon = '', array $args = array() ): string {
	$r     = colorify_maile_rejestr();
	$baza  = '' !== (string) $r['menu']['rodzic'] ? admin_url( (string) $r['menu']['rodzic'] ) : admin_url( 'admin.php' );
	$baza  = add_query_arg( 'page', $r['slug'], $baza );
	if ( '' !== $szablon ) {
		$args['szablon'] = $szablon;
	}
	return add_query_arg( $args, $baza );
}

/** Szablon z rejestru albo null. */
function colorify_maile_szablon( string $id ) {
	return colorify_maile_rejestr()['szablony'][ $id ] ?? null;
}

/* ---------------------------------------------------------------------------
 * Dane: surowa wartość w opcji wtyczki (cała opcja albo jej klucz), znormalizowany szablon, zapis bez migracji.
 * Znormalizowany: array( 'wlaczony' => bool|null, 'wersje' => array( array( pole => string, ... ), ... ) ).
 * ------------------------------------------------------------------------- */

function colorify_maile_surowe( array $t ) {
	$o = get_option( $t['opcja'], null ); // null = opcji nie ma (get_option bez domyślnej zwraca false)
	if ( '' !== (string) $t['klucz'] ) {
		return is_array( $o ) && isset( $o[ $t['klucz'] ] ) ? $o[ $t['klucz'] ] : null;
	}
	return $o;
}

function colorify_maile_zapisz_surowe( array $t, $v ): void {
	if ( '' !== (string) $t['klucz'] ) {
		$o = get_option( $t['opcja'] );
		$o = is_array( $o ) ? $o : array();
		if ( null === $v ) {
			unset( $o[ $t['klucz'] ] );
		} else {
			$o[ $t['klucz'] ] = $v;
		}
		update_option( $t['opcja'], $o, false );
		return;
	}
	if ( null === $v ) {
		delete_option( $t['opcja'] );
	} else {
		update_option( $t['opcja'], $v, false );
	}
}

/** Jedna wersja (pozycyjna albo asocjacyjna) do mapy pole => string. */
function colorify_maile_wersja_norm( array $t, $w ): array {
	$out  = array();
	$pola = array_keys( (array) $t['pola'] );
	foreach ( $pola as $i => $k ) {
		$v = null;
		if ( is_array( $w ) ) {
			$v = $t['pozycyjne'] ? ( $w[ $i ] ?? null ) : ( $w[ $k ] ?? null );
		}
		$out[ $k ] = null === $v ? '' : (string) $v;
	}
	return $out;
}

/** Wersje z surowej wartości: lista wersji (wersje > 1) albo jedna wersja (mapa pól). */
function colorify_maile_norm( array $t, $surowe ): array {
	$n = array( 'wlaczony' => null, 'wersje' => array() );
	if ( $t['wersje'] > 1 ) {
		foreach ( is_array( $surowe ) ? $surowe : array() as $w ) {
			if ( is_array( $w ) ) {
				$n['wersje'][] = colorify_maile_wersja_norm( $t, $w );
			}
		}
	} elseif ( is_array( $surowe ) ) {
		$n['wersje'][] = colorify_maile_wersja_norm( $t, $surowe );
		if ( '' !== (string) $t['wlacznik'] ) {
			$n['wlaczony'] = ! empty( $surowe[ $t['wlacznik'] ] );
		}
	}
	return $n;
}

function colorify_maile_domyslne( array $t ): array {
	$n = colorify_maile_norm( $t, $t['domyslne'] );
	if ( '' !== (string) $t['wlacznik'] && null === $n['wlaczony'] ) {
		$n['wlaczony'] = true;
	}
	return $n;
}

/** Aktualny szablon: zapisany, a gdy pusty, domyślny. Zwraca też 'zapisany' => bool. */
function colorify_maile_odczyt( array $t ): array {
	$n = colorify_maile_norm( $t, colorify_maile_surowe( $t ) );
	$n = array_filter( array( 'wersje' => $n['wersje'] ) )
		? $n + array( 'zapisany' => true )
		: colorify_maile_domyslne( $t ) + array( 'zapisany' => false );
	if ( '' !== (string) $t['wlacznik'] && null === $n['wlaczony'] ) {
		$n['wlaczony'] = colorify_maile_domyslne( $t )['wlaczony'];
	}
	return $n;
}

/** Znormalizowany szablon do postaci, jaką wtyczka trzyma w opcji; klucze spoza pól zostają z $stare. */
function colorify_maile_do_surowego( array $t, array $n, $stare ) {
	$jedna = function ( array $w ) use ( $t, $stare ) {
		if ( $t['pozycyjne'] ) {
			return array_values( $w );
		}
		$baza = is_array( $stare ) && 1 === $t['wersje'] ? $stare : array();
		return array_merge( $baza, $w );
	};
	if ( $t['wersje'] > 1 ) {
		return array_map( $jedna, $n['wersje'] );
	}
	$out = $jedna( $n['wersje'][0] ?? array() );
	if ( '' !== (string) $t['wlacznik'] ) {
		$out[ $t['wlacznik'] ] = empty( $n['wlaczony'] ) ? 0 : 1;
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Sanitizacja z formularza. Typy pól: text, area (lekki HTML: b/strong/i/em/br/a), plain (czysty tekst wieloliniowy),
 * lista (jedna linia = jeden punkt), url, email.
 * ------------------------------------------------------------------------- */

function colorify_maile_kses(): array {
	return array( 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'br' => array(), 'a' => array( 'href' => true ) );
}

function colorify_maile_pole_z_formularza( string $typ, $v ): string {
	$v = is_scalar( $v ) ? str_replace( "\r\n", "\n", (string) $v ) : '';
	switch ( $typ ) {
		case 'area':
			return trim( wp_kses( $v, colorify_maile_kses() ) );
		case 'plain':
			return trim( sanitize_textarea_field( $v ) );
		case 'lista':
			$out = array();
			foreach ( explode( "\n", $v ) as $l ) {
				$l = sanitize_text_field( $l );
				if ( '' !== $l ) {
					$out[] = $l;
				}
			}
			return implode( "\n", $out );
		case 'url':
			return esc_url_raw( trim( $v ) );
		case 'email':
			return sanitize_email( trim( $v ) );
		default:
			return sanitize_text_field( $v );
	}
}

/**
 * Znormalizowany szablon z pól formularza: $post['m'][nr][pole], $post['m_wlaczony'].
 * Puste pole wymagane (req) bierze wartość domyślną; wersja bez żadnej treści jest pomijana.
 */
function colorify_maile_z_formularza( array $t, array $post ): array {
	$dom = colorify_maile_domyslne( $t );
	$n   = array( 'wlaczony' => '' !== (string) $t['wlacznik'] ? ! empty( $post['m_wlaczony'] ) : null, 'wersje' => array() );
	foreach ( (array) ( $post['m'] ?? array() ) as $w ) {
		if ( ! is_array( $w ) ) {
			continue;
		}
		$one   = array();
		$pusta = true;
		foreach ( (array) $t['pola'] as $k => $p ) {
			$one[ $k ] = colorify_maile_pole_z_formularza( (string) ( $p[0] ?? 'text' ), $w[ $k ] ?? '' );
			if ( '' !== $one[ $k ] ) {
				$pusta = false;
			}
		}
		if ( $pusta ) {
			continue;
		}
		foreach ( (array) $t['pola'] as $k => $p ) {
			if ( '' === $one[ $k ] && ! empty( $p['req'] ) ) {
				$one[ $k ] = (string) ( $dom['wersje'][0][ $k ] ?? '' );
			}
		}
		$n['wersje'][] = $one;
		if ( count( $n['wersje'] ) >= $t['wersje'] ) {
			break;
		}
	}
	return $n;
}

/* ---------------------------------------------------------------------------
 * Historia (8 wpisów: t, u, l, d = surowa wartość, w = wersja) i bramka wersji. Czyta też starszy format wtyczek (kto, dane).
 * ------------------------------------------------------------------------- */

function colorify_maile_wersja( array $t, $surowe = false ): string {
	$s = false === $surowe ? colorify_maile_surowe( $t ) : $surowe;
	return substr( md5( wp_json_encode( $s ) ), 0, 12 );
}

function colorify_maile_historia( array $t ): array {
	$h   = get_option( $t['historia'] );
	$out = array();
	foreach ( is_array( $h ) ? $h : array() as $e ) {
		if ( ! is_array( $e ) ) {
			continue;
		}
		$d     = array_key_exists( 'd', $e ) ? $e['d'] : ( $e['dane'] ?? null );
		$out[] = array( 't' => (int) ( $e['t'] ?? 0 ), 'u' => (string) ( $e['u'] ?? ( $e['kto'] ?? '' ) ), 'l' => (string) ( $e['l'] ?? 'Zapis' ), 'd' => $d, 'w' => (string) ( $e['w'] ?? colorify_maile_wersja( $t, $d ) ) );
	}
	return $out;
}

/**
 * Zapis: $surowe_nowe (null = przywróć domyślne). Bramka: $wersja_bazowa z chwili otwarcia; inna = WP_Error 'konflikt'.
 * Zwraca true, false (bez zmian) albo WP_Error.
 */
function colorify_maile_zapisz( array $t, $surowe_nowe, string $label, $wersja_bazowa = null, $autor = null ) {
	$stare = colorify_maile_surowe( $t );
	if ( null !== $wersja_bazowa && $wersja_bazowa !== colorify_maile_wersja( $t, $stare ) ) {
		return new WP_Error( 'konflikt', 'Ktoś zapisał ten szablon w międzyczasie. Odśwież stronę, żeby zobaczyć aktualną wersję.' );
	}
	if ( $surowe_nowe == $stare ) { // phpcs:ignore WordPress.PHP.StrictComparisons
		return false;
	}
	if ( null === $stare && null !== $surowe_nowe && $surowe_nowe == colorify_maile_do_surowego( $t, colorify_maile_domyslne( $t ), null ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons
		return false; // formularz równy domyślnym: wtyczka dalej czyta swoje domyślne, nic nie zapisujemy
	}
	$h = colorify_maile_historia( $t );
	if ( null === $autor ) {
		$u     = wp_get_current_user();
		$autor = $u && $u->exists() ? $u->display_name : '';
	}
	$h[] = array( 't' => time(), 'u' => (string) $autor, 'l' => $label, 'd' => $stare, 'w' => colorify_maile_wersja( $t, $stare ) );
	update_option( $t['historia'], array_slice( $h, -8 ), false );
	colorify_maile_zapisz_surowe( $t, $surowe_nowe );
	return true;
}

/* ---------------------------------------------------------------------------
 * Render: zmienne, temat, HTML (z wtyczki albo wbudowany), wysyłka (z wtyczki albo wp_mail).
 * ------------------------------------------------------------------------- */

/** Dane rekordu (0 = przykładowe) przez callable 'dane' wtyczki; bez niego pusta tablica. */
function colorify_maile_dane( array $t, int $id ): array {
	return is_callable( $t['dane'] ) ? (array) call_user_func( $t['dane'], $id ) : array();
}

function colorify_maile_wartosci( array $t, array $z ): array {
	$w = is_callable( $t['wartosci'] ) ? (array) call_user_func( $t['wartosci'], $z ) : array();
	foreach ( array_keys( (array) $t['zmienne'] ) as $k ) {
		$w += array( $k => '' );
	}
	return $w;
}

/** Wstawia {zmienne} (callable 'wstaw' wtyczki albo strtr). */
function colorify_maile_wstaw( array $t, string $tekst, array $w ): string {
	if ( is_callable( $t['wstaw'] ) ) {
		return (string) call_user_func( $t['wstaw'], $tekst, $w );
	}
	$map = array();
	foreach ( $w as $k => $v ) {
		$map[ '{' . $k . '}' ] = (string) $v;
	}
	return strtr( $tekst, $map );
}

function colorify_maile_temat( array $t, array $sz, array $z ): string {
	if ( is_callable( $t['temat'] ) ) {
		return (string) call_user_func( $t['temat'], $sz, $z );
	}
	return trim( colorify_maile_wstaw( $t, (string) ( $sz['temat'] ?? '' ), colorify_maile_wartosci( $t, $z ) ) );
}

/** Wbudowany HTML (dla szablonów bez renderera, np. maile z przycisków mailto): krem, złoto, brąz, 600 px. */
function colorify_maile_html_prosty( array $t, array $sz, array $z ): string {
	$w   = colorify_maile_wartosci( $t, $z );
	$e   = static function ( $s ) { return esc_html( (string) $s ); };
	$fs  = "font-family:Poppins,Arial,Helvetica,sans-serif;";
	$ff  = "font-family:'Gilda Display',Georgia,'Times New Roman',serif;";
	$ak  = '';
	foreach ( (array) $t['pola'] as $k => $p ) {
		$typ = (string) ( $p[0] ?? 'text' );
		$v   = colorify_maile_wstaw( $t, (string) ( $sz[ $k ] ?? '' ), $w );
		if ( 'temat' === $k || '' === trim( $v ) || in_array( $typ, array( 'url', 'email' ), true ) ) {
			continue;
		}
		if ( 'lista' === $typ ) {
			foreach ( explode( "\n", $v ) as $l ) {
				$ak .= '<p style="' . $fs . 'font-size:15px;line-height:1.6;color:#56524E;margin:0 0 8px;padding-left:22px;text-indent:-22px;"><span style="color:#B8955A;">&#9670;</span>&nbsp;&nbsp;' . $e( $l ) . '</p>';
			}
			continue;
		}
		$html = 'area' === $typ ? wp_kses( $v, colorify_maile_kses() ) : $e( $v );
		foreach ( preg_split( '/\n\s*\n/', trim( $html ) ) as $b ) {
			$ak .= '<p style="' . $fs . 'font-size:15px;line-height:1.75;color:#56524E;margin:0 0 16px;">' . nl2br( trim( $b ) ) . '</p>';
		}
	}
	$temat = colorify_maile_temat( $t, $sz, $z );
	return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e( $temat ) . '</title></head>'
		. '<body style="margin:0;padding:0;background:#F4EDDF;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4EDDF;"><tr><td align="center" style="padding:28px 12px;">'
		. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#FFFFFF;border:1px solid #E8DCC2;">'
		. '<tr><td height="4" style="height:4px;line-height:4px;font-size:0;background:#B8955A;">&nbsp;</td></tr>'
		. '<tr><td style="padding:34px 44px 10px;"><div style="' . $fs . 'font-size:11px;letter-spacing:1.6px;text-transform:uppercase;color:#B8955A;margin:0 0 10px;">Temat</div><h1 style="' . $ff . 'font-size:26px;line-height:1.25;font-weight:normal;color:#301E0E;margin:0 0 22px;">' . $e( $temat ) . '</h1>' . $ak . '</td></tr>'
		. '<tr><td height="4" style="height:4px;line-height:4px;font-size:0;background:#B8955A;">&nbsp;</td></tr>'
		. '</table></td></tr></table></body></html>';
}

function colorify_maile_html( array $t, array $sz, array $z ): string {
	if ( is_callable( $t['html'] ) ) {
		return (string) call_user_func( $t['html'], $sz, $z );
	}
	return colorify_maile_html_prosty( $t, $sz, $z );
}

/** Wysyłka testu: callable 'wyslij' wtyczki ('' = ok, inaczej opis błędu) albo wp_mail z HTML. */
function colorify_maile_wyslij( array $t, array $sz, array $z, string $adres ): string {
	if ( ! is_email( $adres ) ) {
		return 'niepoprawny adres e-mail';
	}
	if ( is_callable( $t['wyslij'] ) ) {
		return (string) call_user_func( $t['wyslij'], $sz, $z, $adres );
	}
	$typ = static function () { return 'text/html'; };
	add_filter( 'wp_mail_content_type', $typ );
	$ok = wp_mail( $adres, colorify_maile_temat( $t, $sz, $z ), colorify_maile_html( $t, $sz, $z ) );
	remove_filter( 'wp_mail_content_type', $typ );
	return $ok ? '' : 'wp_mail() zwróciło błąd (sprawdź konfigurację poczty)';
}

/* ---------------------------------------------------------------------------
 * Ustawienia, Colorify: przełącznik (zawsze) i hooki ekranu (tylko gdy moduł jest dostępny).
 * ------------------------------------------------------------------------- */

function colorify_maile_save_from_input( array $input ): void {
	if ( empty( $input['colorify_maile_present'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	update_option( COLORIFY_MAILE_OPTION, ! empty( $input['colorify_maile'] ) ? 'yes' : 'no' );
}

function colorify_maile_settings_row(): void {
	$n = count( colorify_maile_rejestr()['szablony'] );
	echo '<div class="colorify-site colorify-style-row">';
	echo '<div class="colorify-site__text"><p class="colorify-site__title">' . esc_html__( 'Kreator e-maili', 'colorify-by-inyfinn' ) . '</p>';
	echo '<p class="colorify-site__hint">' . esc_html__( 'Ekran "Wiadomości do par": szablony maili wtyczek (temat, nagłówek, akapity, punkty, przycisk, podpis) z chipami zmiennych, wersjami do losowania, podglądem na żywo, testem na własny adres, historią i bramką wersji. Działa tylko z wtyczką, która rejestruje szablony filtrem colorify_maile_rejestr.', 'colorify-by-inyfinn' ) . '</p>';
	echo '<p class="colorify-site__now">' . ( $n
		/* translators: %d: liczba szablonów */
		? esc_html( sprintf( _n( 'Zarejestrowany szablon: %d.', 'Zarejestrowane szablony: %d.', $n, 'colorify-by-inyfinn' ), $n ) )
		: esc_html__( 'Żadna wtyczka nie rejestruje szablonów, więc włączenie niczego nie zmieni.', 'colorify-by-inyfinn' ) ) . '</p></div>';
	echo '<label class="colorify-site__check"><input type="hidden" name="colorify_maile_present" value="1"><input type="checkbox" name="colorify_maile" value="1"' . checked( colorify_maile_aktywny(), true, false ) . '> ' . esc_html__( 'Włącz ekran Wiadomości do par', 'colorify-by-inyfinn' ) . '</label>';
	echo '</div>';
}

add_action(
	'init',
	static function () {
		if ( is_admin() && colorify_maile_dostepny() ) {
			require_once __DIR__ . '/colorify-maile-screen.php';
		}
	},
	5
);
