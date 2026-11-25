<?php
/**
 * Moduł "Edytor produktu", ekran wp-admin (Produkty, Edytor z podglądem): karty z elementami strony produktu po lewej,
 * podgląd prawdziwej strony po prawej. Ten sam układ, klasy i skrypt co moduł "Edytor treści" (assets/colorify-tresci.*),
 * dodatki w assets/colorify-produkt.*. Ładowany tylko, gdy moduł jest włączony i jest WooCommerce.
 * Model danych, walidacja, zapis i filtry podglądu: colorify-produkt.php.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_menu',
	static function () {
		add_submenu_page( 'edit.php?post_type=product', 'Edytor produktu z podglądem', 'Edytor z podglądem', 'edit_products', 'colorify-produkt', 'cpe_ekran' );
	}
);

/** ID produktu z adresu, jeśli istnieje i wolno go edytować; inaczej 0. */
function cpe_id(): int {
	$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	return $id && 'product' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ? $id : 0;
}

/** Adres strony produktu (szkic: adres podglądu WordPressa). */
function cpe_adres( int $id ): string {
	return (string) ( 'publish' === get_post_status( $id ) ? get_permalink( $id ) : get_preview_post_link( $id ) );
}

/** Tablica z formularza, po wp_unslash (pola czyści colorify_produkt_czysc()). */
function cpe_post( string $klucz ): array {
	return isset( $_POST[ $klucz ] ) && is_array( $_POST[ $klucz ] ) ? wp_unslash( $_POST[ $klucz ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
}

/** Tekst interfejsu: escapowany, z twardą spacją po jedno- i dwuliterowych wyrazach (bez sierotek na końcu wiersza). */
function cpe_t( $tekst ): string {
	$t = esc_html( (string) $tekst );
	for ( $i = 0; $i < 2; $i++ ) { // dwa przebiegi: dwa krótkie wyrazy pod rząd („i w”)
		$t = (string) preg_replace( '/(^|[\s(„»])([A-Za-zÀ-ž]{1,2})\s+/u', '$1$2&nbsp;', $t );
	}
	return $t;
}

/** Ikony (Lucide, ISC), ten sam rysunek co w Edytorze treści. */
function cpe_ik( string $nazwa ): string {
	$p = array(
		'odswiez' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
		'lewo'    => '<path d="m15 18-6-6 6-6"/>',
		'prawo'   => '<path d="m9 18 6-6-6-6"/>',
		'kosz'    => '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
		'ekran'   => '<path d="M12 20h9"/><path d="M16.38 3.62a1 1 0 0 1 3 3L7.37 18.64a2 2 0 0 1-.86.5l-2.87.84a.5.5 0 0 1-.62-.62l.84-2.87a2 2 0 0 1 .5-.86z"/>',
		'zegar'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
		'ludzie'  => '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>',
		'paczka'  => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/>',
	);
	return '<svg class="cft-ik" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ( $p[ $nazwa ] ?? '' ) . '</svg>';
}

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'product_page_colorify-produkt' !== $hook ) {
			return;
		}
		$v = static function ( $f ) {
			return COLORIFY_PLUGIN_VERSION . '-' . (int) @filemtime( COLORIFY_PLUGIN_DIR . 'assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		};
		wp_enqueue_style( 'colorify-tresci', COLORIFY_PLUGIN_URL . 'assets/colorify-tresci.css', array(), $v( 'colorify-tresci.css' ) );
		wp_enqueue_style( 'colorify-produkt', COLORIFY_PLUGIN_URL . 'assets/colorify-produkt.css', array( 'colorify-tresci' ), $v( 'colorify-produkt.css' ) );
		$id = cpe_id();
		if ( ! $id ) {
			return;
		}
		wp_enqueue_media( array( 'post' => $id ) );
		wp_enqueue_editor();
		wp_enqueue_script( 'colorify-tresci', COLORIFY_PLUGIN_URL . 'assets/colorify-tresci.js', array( 'media-editor' ), $v( 'colorify-tresci.js' ), true );
		wp_enqueue_script( 'colorify-produkt', COLORIFY_PLUGIN_URL . 'assets/colorify-produkt.js', array( 'colorify-tresci' ), $v( 'colorify-produkt.js' ), true );

		$sekcje   = array();
		$pola     = array();
		$zakladki = array();
		foreach ( colorify_produkt_karty( $id ) as $kid => $k ) {
			// Zakładka WooCommerce bywa ukryta, więc podgląd przewija do paska zakładek, a zakładkę otwiera colorify-produkt.js.
			$sekcje[ $kid ]          = array(
				'strona'  => 'produkt',
				'kotwica' => '' !== (string) $k['zakladka'] ? '.woocommerce-tabs' : (string) $k['selektor'],
			);
			$pola[ 'karta:' . $kid ] = array(
				's' => $kid,
				't' => 'karta',
			);
			foreach ( $k['pola'] as $klucz => $f ) {
				$pola[ $klucz ] = array(
					's' => $kid,
					't' => $f['typ'],
				);
			}
			if ( '' !== (string) $k['zakladka'] ) {
				$zakladki[ $kid ] = (string) $k['zakladka'];
			}
		}
		$adres = cpe_adres( $id );
		wp_localize_script(
			'colorify-tresci',
			'CFT',
			array(
				'ajax'         => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'colorify_produkt_podglad_' . $id ),
				'strony'       => array( 'produkt' => add_query_arg( 'colorify_produkt_podglad', $id, $adres ) ),
				'adresy'       => array( 'produkt' => $adres ),
				'sekcje'       => $sekcje,
				'pola'         => $pola,
				'uid'          => get_current_user_id(),
				// Parametry wspólnego skryptu (domyślne wartości = zachowanie Edytora treści).
				'akcjaPodglad' => 'colorify_produkt_podglad',
				'akcjaWersja'  => 'colorify_produkt_wersja&id=' . $id,
				'zwloka'       => 2500, // hosting liczy żądania: podgląd odświeża się po 2,5 s przerwy w pisaniu
				'stop429'      => true, // po odpowiedzi 429 zostaje tylko przycisk „Odśwież podgląd”
				'kluczKart'    => 'cpeOtwarte',
			)
		);
		wp_localize_script(
			'colorify-produkt',
			'CPE',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'colorify_produkt_wspolne_' . $id ),
				'id'       => $id,
				'zakladki' => (object) $zakladki,
			)
		);
	}
);

/* ---------------------------------------------------------------------------
 * Zapis (admin-post.php), podgląd, wersja i teksty wspólne (admin-ajax.php)
 * ------------------------------------------------------------------------- */

function cpe_wroc( int $id, string $msg ): void {
	wp_safe_redirect( add_query_arg( 'cpe_msg', $msg, colorify_produkt_url( $id ) ) );
	exit;
}

/** ID produktu z żądania POST, sprawdzone uprawnieniem do edycji tego produktu; brak = koniec żądania. */
function cpe_id_post( bool $ajax ): int {
	$id = isset( $_POST['cpe_id'] ) ? absint( wp_unslash( $_POST['cpe_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- nonce sprawdza wywołujący
	if ( ! $id || 'product' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		if ( $ajax ) {
			wp_send_json_error( 'Brak uprawnień.', 403 );
		}
		wp_die( 'Brak uprawnień.', 403 );
	}
	return $id;
}

add_action(
	'admin_post_colorify_produkt_zapis',
	static function () {
		$id = cpe_id_post( false );
		check_admin_referer( 'colorify_produkt_zapis_' . $id );
		$p = wc_get_product( $id );
		if ( ! $p ) {
			wp_die( 'Nie ma takiego produktu.', 404 );
		}
		$karty  = colorify_produkt_karty( $id );
		$nowe   = colorify_produkt_z_formularza( $p, $karty, cpe_post( 'cpe' ), cpe_post( 'cpe_tkniete' ) );
		$wersja = isset( $_POST['cft_wersja'] ) ? sanitize_key( wp_unslash( $_POST['cft_wersja'] ) ) : null;
		$r      = colorify_produkt_zapisz( $id, $nowe, $wersja );
		if ( is_wp_error( $r ) ) {
			// Nic nie zapisano: formularz wraca z wartościami osoby, która pisała, i listą pól zmienionych w międzyczasie.
			$teraz = colorify_produkt_wartosci( $p, $karty );
			$baza  = get_transient( 'colorify_produkt_baza_' . get_current_user_id() . '_' . $id );
			$obce  = array();
			foreach ( is_array( $baza ) ? $teraz : array() as $klucz => $v ) {
				if ( array_key_exists( $klucz, $baza ) && $baza[ $klucz ] !== $v ) {
					$obce[] = $klucz;
				}
			}
			set_transient(
				'colorify_produkt_form_' . get_current_user_id() . '_' . $id,
				array(
					'nowe' => $nowe,
					'obce' => $obce,
				),
				15 * MINUTE_IN_SECONDS
			);
			cpe_wroc( $id, 'konflikt' === $r->get_error_code() ? 'konflikt' : 'cena' );
		}
		delete_transient( 'colorify_produkt_form_' . get_current_user_id() . '_' . $id );
		cpe_wroc( $id, $r ? 'zapisano' : 'bez-zmian' );
	}
);

/** Podgląd: cały formularz przez tę samą walidację co zapis do transientu, który czyta strona z ?colorify_produkt_podglad. */
add_action(
	'wp_ajax_colorify_produkt_podglad',
	static function () {
		$id = cpe_id_post( true );
		if ( ! check_ajax_referer( 'colorify_produkt_podglad_' . $id, 'nonce', false ) ) {
			wp_send_json_error( 'Brak uprawnień.', 403 );
		}
		$p = wc_get_product( $id );
		if ( ! $p ) {
			wp_send_json_error( 'Nie ma takiego produktu.', 404 );
		}
		$karty = colorify_produkt_karty( $id );
		$nowe  = colorify_produkt_z_formularza( $p, $karty, cpe_post( 'cpe' ), cpe_post( 'cpe_tkniete' ) );
		set_transient( colorify_produkt_podglad_klucz( $id ), colorify_produkt_podglad_zbuduj( $karty, $nowe, cpe_post( 'cpw' ) ), HOUR_IN_SECONDS );
		wp_send_json_success( array( 't' => time() ) );
	}
);

/** Lekkie sprawdzenie: czy produkt zmienił się od otwarcia (edytor z podglądem i zwykły ekran produktu). Odświeża blokadę wpisu. */
add_action(
	'wp_ajax_colorify_produkt_wersja',
	static function () {
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- tylko odczyt skrótu
		$p  = $id && 'product' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			wp_send_json_error( 'Brak uprawnień.', 403 );
		}
		if ( empty( $_GET['bez_blokady'] ) && ! wp_check_post_lock( $id ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_set_post_lock( $id );
		}
		wp_send_json_success( array( 'w' => colorify_produkt_wersja( colorify_produkt_wartosci( $p, colorify_produkt_karty( $id ) ) ) ) );
	}
);

/** Teksty wspólne jednej karty: osobny zapis do opcji strony (po potwierdzeniu w panelu). */
add_action(
	'wp_ajax_colorify_produkt_wspolne',
	static function () {
		$id = cpe_id_post( true );
		if ( ! check_ajax_referer( 'colorify_produkt_wspolne_' . $id, 'nonce', false ) ) {
			wp_send_json_error( 'Brak uprawnień.', 403 );
		}
		$kid = isset( $_POST['karta'] ) ? sanitize_key( wp_unslash( $_POST['karta'] ) ) : '';
		$w   = colorify_produkt_karty( $id )[ $kid ]['wspolne'] ?? array();
		if ( ! $w || ! current_user_can( (string) $w['cap'] ) ) {
			wp_send_json_error( 'Brak uprawnień.', 403 );
		}
		$cpw   = cpe_post( 'cpw' );
		$stara = get_option( (string) $w['opcja'], array() );
		$nowa  = colorify_produkt_wspolne_scal( $w, isset( $cpw[ $kid ] ) && is_array( $cpw[ $kid ] ) ? $cpw[ $kid ] : array() );
		if ( $nowa == $stara ) { // phpcs:ignore Universal.Operators.StrictComparisons -- kolejność kluczy bez znaczenia
			wp_send_json_success( array( 'zmiana' => false ) );
		}
		update_option( (string) $w['opcja'], $nowa );
		/** Strona może tu wyczyścić własną pamięć podręczną. */
		do_action( 'colorify_produkt_wspolne_zapisano', (string) $w['opcja'], $nowa, $kid );
		wp_send_json_success( array( 'zmiana' => true ) );
	}
);

/* ---------------------------------------------------------------------------
 * Pola
 * ------------------------------------------------------------------------- */

function cpe_podp( $tekst ): void {
	if ( '' !== (string) $tekst ) {
		echo '<p class="cft-podp">' . cpe_t( $tekst ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- cpe_t() escapuje
	}
}

/** Tekst bez znaczników do kafla karty i podpisu (jedna linia, do 60 znaków). */
function cpe_plain( $v ): string {
	if ( is_array( $v ) ) {
		$v = '';
	}
	$t = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $v ), ENT_QUOTES, 'UTF-8' ) ) );
	return mb_strlen( $t ) > 60 ? mb_substr( $t, 0, 57 ) . '…' : $t;
}

/** Miniatura załącznika w panelu. */
function cpe_miniatura( int $att ): string {
	$u = $att ? wp_get_attachment_image_url( $att, 'medium' ) : '';
	return $u ? (string) $u : '';
}

/**
 * Jedno pole. $o: name (nazwa w formularzu), id, klucz_podgladu ('' = pole bez elementu w podglądzie),
 * wspolne (pole tekstu wspólnego), wylaczone (brak uprawnień).
 */
function cpe_pole( string $klucz, array $f, $val, array $o ): void {
	$name = (string) $o['name'];
	$id   = (string) $o['id'];
	$typ  = (string) $f['typ'];
	$kp   = (string) ( $o['klucz_podgladu'] ?? '' );
	$dis  = ! empty( $o['wylaczone'] ) ? ' disabled' : '';
	// data-key: fokus podświetla element w podglądzie, klik w podglądzie otwiera pole. Podmiany w locie nie ma (poza nazwą):
	// podgląd pokazuje wynik prawdziwego szablonu po odświeżeniu.
	$key  = '' !== $kp ? ' data-key="' . esc_attr( $kp ) . '" data-cft-bez-podmiany' : '';
	$auto = ' data-cpe-auto';

	echo '<div class="cft-pole cft-pole--' . esc_attr( $typ ) . '" data-pole="' . esc_attr( $klucz ) . '">';
	switch ( $typ ) {
		case 'bool':
			echo '<label class="cft-check"><input type="hidden" name="' . esc_attr( $name ) . '" value="0"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" data-struktura' . checked( '' !== (string) $val, true, false ) . $dis . '> ' . cpe_t( $f['etykieta'] ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
			break;

		case 'obraz':
			$u = cpe_miniatura( (int) $val );
			echo '<span class="cft-etykieta" id="' . esc_attr( $id ) . '-l">' . cpe_t( $f['etykieta'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="cft-media cpe-media"' . ( '' !== $kp ? ' data-key="' . esc_attr( $kp ) . '" data-cft-bez-podmiany' : '' ) . '>';
			echo '<div class="cft-media__pod">' . ( $u ? '<img src="' . esc_url( $u ) . '" alt="">' : '<span class="cft-media__brak">Brak zdjęcia</span>' ) . '</div>';
			echo '<div class="cft-media__akcje"><button type="button" class="cft-btn cft-btn--obrys cft-btn--maly" data-cpe-media="wybierz">Wybierz zdjęcie</button>';
			echo '<button type="button" class="cft-btn cft-btn--link" data-cpe-media="usun"' . ( $u ? '' : ' hidden' ) . '>Usuń zdjęcie</button></div>';
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . (int) $val . '"></div>';
			break;

		case 'galeria':
			echo '<span class="cft-etykieta" id="' . esc_attr( $id ) . '-l">' . cpe_t( $f['etykieta'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="cpe-galeria"' . ( '' !== $kp ? ' data-key="' . esc_attr( $kp ) . '" data-cft-bez-podmiany' : '' ) . '>';
			echo '<ul class="cpe-galeria__lista">';
			foreach ( (array) $val as $att ) {
				cpe_galeria_el( (int) $att, cpe_miniatura( (int) $att ) );
			}
			echo '</ul><template class="cpe-galeria__szablon">';
			cpe_galeria_el( 0, '' );
			echo '</template>';
			echo '<button type="button" class="cft-btn cft-btn--obrys cft-btn--maly" data-cpe-gal="dodaj">Dodaj zdjęcia</button>';
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( implode( ',', array_map( 'intval', (array) $val ) ) ) . '"></div>';
			break;

		case 'termy':
			$tax   = (string) $f['taksonomia'];
			$termy = get_terms(
				array(
					'taxonomy'   => $tax,
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);
			$termy = is_wp_error( $termy ) ? array() : $termy;
			echo '<fieldset class="cpe-termy' . ( is_taxonomy_hierarchical( $tax ) ? ' cpe-termy--drzewo' : '' ) . '"' . $key . '><legend class="cft-etykieta">' . cpe_t( $f['etykieta'] ) . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="0">';
			if ( ! $termy ) {
				echo '<p class="cft-podp">Jeszcze nie ma żadnych.</p>';
			}
			echo '<div class="cpe-termy__lista">';
			foreach ( cpe_termy_drzewo( $termy ) as $wiersz ) {
				list( $t, $g ) = $wiersz;
				echo '<label class="cft-check cpe-term" style="--cpe-g:' . (int) $g . '"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . (int) $t->term_id . '" data-struktura' . checked( in_array( (int) $t->term_id, (array) $val, true ), true, false ) . $dis . '> <span>' . esc_html( $t->name ) . '</span></label>';
			}
			echo '</div></fieldset>';
			break;

		case 'html':
			$ed = (string) preg_replace( '/[^a-z0-9_]/', '_', strtolower( ! empty( $o['wspolne'] ) ? 'cpw_' . $o['wspolne'] . '_' . $klucz : 'cpe_' . $klucz ) );
			// Etykieta niesie data-key (nie opakowanie edytora): zdarzenia z pola tekstowego nie mogą trafić do podmiany w podglądzie.
			echo '<span class="cft-etykieta" id="' . esc_attr( $id ) . '-l"' . ( '' !== $kp ? ' data-key="' . esc_attr( $kp ) . '" data-cft-bez-podmiany tabindex="-1"' : '' ) . '>' . cpe_t( $f['etykieta'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="cpe-wp" data-cpe-wp="' . esc_attr( $ed ) . '">';
			wp_editor(
				(string) $val,
				$ed,
				array(
					'textarea_name' => $name,
					'textarea_rows' => max( 4, (int) $f['wiersze'] ),
					'media_buttons' => ! empty( $f['media'] ),
					'teeny'         => empty( $f['media'] ),
					'tinymce'       => $dis ? array( 'readonly' => 1 ) : true,
				)
			);
			echo '</div>';
			if ( empty( $o['wspolne'] ) ) {
				echo '<input type="hidden" name="cpe_tkniete[' . esc_attr( $klucz ) . ']" value="' . ( ! empty( $o['tkniete'] ) ? '1' : '0' ) . '" data-cpe-tkniete="' . esc_attr( $ed ) . '">';
			}
			break;

		case 'linie':
		case 'lekki':
			echo '<label class="cft-etykieta" for="' . esc_attr( $id ) . '">' . cpe_t( $f['etykieta'] ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( 'lekki' === $typ && ! $dis ) {
				echo '<div class="cft-tb" role="toolbar" aria-label="Formatowanie" data-cpe-tb="' . esc_attr( $id ) . '">';
				echo '<button type="button" data-cpe-znacznik="strong" aria-label="Pogrubienie" data-tip="Pogrubienie zaznaczonego tekstu"><b>B</b></button>';
				echo '<button type="button" data-cpe-znacznik="em" aria-label="Kursywa" data-tip="Kursywa zaznaczonego tekstu"><i>I</i></button>';
				echo '<button type="button" data-cpe-znacznik="a" data-tip="Zaznacz tekst, potem wklej adres">Odnośnik</button></div>';
			}
			echo '<textarea class="cft-in" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . max( 2, (int) $f['wiersze'] ) . '" placeholder="' . esc_attr( wp_strip_all_tags( (string) $f['domyslne'] ) ) . '"' . $key . $auto . $dis . '>' . esc_textarea( (string) $val ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- atrybuty złożone z esc_attr
			break;

		case 'kwota':
			echo '<label class="cft-etykieta" for="' . esc_attr( $id ) . '">' . cpe_t( $f['etykieta'] ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div class="cpe-kwota"><input class="cft-in" type="text" inputmode="decimal" autocomplete="off" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( '' !== (string) $val ? wc_format_localized_price( (string) $val ) : '' ) . '"' . $key . $auto . $dis . '><span class="cpe-kwota__w">' . esc_html( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ) . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			break;

		default: // tytul, tekst
			// Nazwa (typ 'tytul') jako jedyna podmienia się w podglądzie w trakcie pisania: to czysty tekst w tytule strony.
			$k = 'tytul' === $typ && '' !== $kp ? ' data-key="' . esc_attr( $kp ) . '"' : $key . $auto;
			echo '<label class="cft-etykieta" for="' . esc_attr( $id ) . '">' . cpe_t( $f['etykieta'] ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<input class="cft-in" type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $val ) . '" placeholder="' . esc_attr( wp_strip_all_tags( (string) $f['domyslne'] ) ) . '"' . $k . $dis . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	$podp = (string) $f['podpowiedz'];
	if ( '' === $podp && '' !== (string) $f['domyslne'] && in_array( $typ, array( 'linie', 'lekki', 'tekst' ), true ) ) {
		$podp = 'Puste pole = tekst domyślny (widać go na szaro w polu).';
	}
	cpe_podp( $podp );
	echo '</div>';
}

/** Element galerii (także w szablonie dla skryptu). */
function cpe_galeria_el( int $att, string $url ): void {
	echo '<li class="cpe-galeria__el" data-id="' . (int) $att . '"><img src="' . esc_url( $url ) . '" alt="">';
	echo '<span class="cpe-galeria__akcje">';
	echo '<button type="button" class="cft-ikona" data-cpe-gal="lewo" aria-label="Przesuń zdjęcie wcześniej" data-tip="Przesuń wcześniej">' . cpe_ik( 'lewo' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<button type="button" class="cft-ikona" data-cpe-gal="prawo" aria-label="Przesuń zdjęcie dalej" data-tip="Przesuń dalej">' . cpe_ik( 'prawo' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<button type="button" class="cft-ikona cft-ikona--usun" data-cpe-gal="usun" aria-label="Usuń zdjęcie z galerii" data-tip="Usuń z galerii">' . cpe_ik( 'kosz' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</span></li>';
}

/** Terminy w kolejności drzewa: lista par ( WP_Term, głębokość ). */
function cpe_termy_drzewo( array $termy, int $rodzic = 0, int $g = 0 ): array {
	$out = array();
	if ( 0 === $g ) {
		$ids = wp_list_pluck( $termy, 'term_id' );
		foreach ( $termy as $t ) {
			if ( $t->parent && ! in_array( $t->parent, $ids, true ) ) {
				$out[] = array( $t, 0 ); // osierocony (rodzic spoza listy): na górze, żeby nie zniknął
			}
		}
	}
	foreach ( $termy as $t ) {
		if ( (int) $t->parent === $rodzic ) {
			$out[] = array( $t, $g );
			$out   = array_merge( $out, cpe_termy_drzewo( $termy, (int) $t->term_id, $g + 1 ) );
		}
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * Karty
 * ------------------------------------------------------------------------- */

/** Kafel w nagłówku karty: miniatura zdjęcia albo początek tekstu (aktualizowany w trakcie pisania przez wspólny skrypt). */
function cpe_kafel( string $kid, array $k, array $wart, array $wspolne_wart ): void {
	$pierwsze = $k['pola'] ? (string) array_key_first( $k['pola'] ) : '';
	$f        = '' !== $pierwsze ? $k['pola'][ $pierwsze ] : null;
	if ( $f && in_array( $f['typ'], array( 'obraz', 'galeria' ), true ) ) {
		echo '<span class="cft-kafel cft-kafel--rolki" aria-hidden="true" data-cpe-kafel="' . esc_attr( $pierwsze ) . '">';
		foreach ( array_slice( (array) $wart[ $pierwsze ], 0, 3 ) as $att ) {
			$u = cpe_miniatura( (int) $att );
			if ( $u ) {
				echo '<img src="' . esc_url( $u ) . '" alt="">';
			}
		}
		echo '</span>';
		return;
	}
	$txt = '';
	$key = '';
	if ( $f && 'kwota' === $f['typ'] ) {
		$txt = '' !== (string) $wart[ $pierwsze ] ? wp_strip_all_tags( wc_price( (float) $wart[ $pierwsze ] ) ) : '';
	} elseif ( $f && 'termy' === $f['typ'] ) {
		$t   = (array) $wart[ $pierwsze ] ? get_term( (int) reset( $wart[ $pierwsze ] ), (string) $f['taksonomia'] ) : null;
		$txt = $t instanceof WP_Term ? $t->name : '';
	} elseif ( $f && 'bool' !== $f['typ'] ) {
		$txt = cpe_plain( '' !== (string) $wart[ $pierwsze ] ? $wart[ $pierwsze ] : $f['domyslne'] );
		$key = 'tytul' === $f['typ'] ? $pierwsze : '';
	} elseif ( ! $f && $wspolne_wart ) {
		$txt = cpe_plain( reset( $wspolne_wart ) );
	}
	$typ = in_array( (string) ( $k['kafel'] ?? '' ), array( 'ciemny', 'boksy' ), true ) ? (string) $k['kafel'] : 'linie';
	echo '<span class="cft-kafel cft-kafel--' . esc_attr( $typ ) . '" aria-hidden="true">';
	echo '<span class="cft-kafel__h"' . ( '' !== $key ? ' data-kafel-txt="' . esc_attr( $key ) . '"' : '' ) . '>' . esc_html( html_entity_decode( $txt, ENT_QUOTES, 'UTF-8' ) ) . '</span>';
	echo 'boksy' === $typ ? '<span class="cft-kafel__box"></span><span class="cft-kafel__box"></span><span class="cft-kafel__box"></span>' : '<span class="cft-kafel__l"></span><span class="cft-kafel__l"></span><span class="cft-kafel__l cft-kafel__l--k"></span>';
	echo '</span>';
}

/** Blok tekstów wspólnych karty: podpis zasięgu, pola, osobny przycisk zapisu. $sam: karta nie ma własnych pól produktu. */
function cpe_wspolne( string $kid, array $k, bool $sam, array $wartosci ): void {
	$w    = $k['wspolne'];
	$moze = current_user_can( (string) $w['cap'] );
	$znak = $sam && '' !== (string) $k['selektor'] ? ' data-key="karta:' . esc_attr( $kid ) . '" data-cft-bez-podmiany' : '';
	if ( $sam ) {
		echo '<div class="cft-grupa cpe-wspolne" data-cft-osobno data-cpe-blok="' . esc_attr( $kid ) . '">';
	} else {
		echo '<details class="cft-grupa cft-grupa--zw cpe-wspolne" data-cft-osobno data-cpe-blok="' . esc_attr( $kid ) . '">';
		echo '<summary class="cft-grupa__s"><span class="cft-grupa__t">' . cpe_t( $w['tytul'] ) . '</span><span class="cft-grupa__ile">wspólne</span></summary><div class="cft-grupa__tresc">'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '<p class="cpe-zasieg"' . $znak . '><strong>Tekst wspólny.</strong> ' . cpe_t( '' !== (string) $w['zasieg'] ? $w['zasieg'] : 'Zmiana dotyczy także innych produktów.' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	foreach ( $w['pola'] as $klucz => $f ) {
		cpe_pole(
			$klucz,
			$f,
			$wartosci[ $klucz ],
			array(
				'name'      => 'cpw[' . $kid . '][' . $klucz . ']',
				'id'        => 'cpw-' . $kid . '-' . $klucz,
				'wspolne'   => $kid,
				'wylaczone' => ! $moze,
			)
		);
	}
	echo '<div class="cpe-wspolne__akcje">';
	if ( $moze ) {
		echo '<button type="button" class="cft-btn cft-btn--obrys" data-cpe-wspolne="' . esc_attr( $kid ) . '" data-cpe-zasieg="' . esc_attr( (string) $w['zasieg'] ) . '">Zapisz tekst wspólny</button>';
		echo '<span class="cpe-wspolne__stan" role="status" aria-live="polite"></span>';
	} else {
		echo '<p class="cft-podp">Ten tekst jest wspólny dla wielu produktów. Może go zmienić tylko osoba zarządzająca sklepem.</p>';
	}
	echo '</div>';
	echo $sam ? '</div>' : '</div></details>';
}

function cpe_karta( string $kid, array $k, array $wart, array $tkniete ): void {
	$w_wart = array();
	if ( $k['wspolne'] ) {
		$opcja = get_option( (string) $k['wspolne']['opcja'], array() );
		foreach ( $k['wspolne']['pola'] as $klucz => $f ) {
			$v = colorify_produkt_sciezka_czytaj( $opcja, (array) $f['sciezka'] );
			// Edytor WP nie ma szarej podpowiedzi: pusty tekst wspólny pokazuje tekst z kodu, żeby było co poprawiać.
			$w_wart[ $klucz ] = ( '' === $v && 'html' === $f['typ'] ) ? (string) $f['domyslne'] : $v;
		}
	}
	$sam = ! $k['pola'];
	?>
	<details class="cft-karta" id="cft-karta-<?php echo esc_attr( $kid ); ?>" data-karta="<?php echo esc_attr( $kid ); ?>">
		<summary>
			<?php cpe_kafel( $kid, $k, $wart, $w_wart ); ?>
			<span class="cft-karta__o">
				<span class="cft-karta__t"><?php echo esc_html( $k['tytul'] ); ?></span>
				<span class="cft-karta__g"><?php echo cpe_t( $k['gdzie'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			</span>
			<?php if ( $sam && $k['wspolne'] ) : ?><span class="cft-karta__zm">wspólne</span><?php endif; ?>
		</summary>
		<div class="cft-karta__tresc">
			<?php if ( $k['pola'] ) : ?>
			<div class="cft-grupa<?php echo count( $k['pola'] ) > 1 && 'kwota' === reset( $k['pola'] )['typ'] ? ' cpe-grupa--ceny' : ''; ?>">
				<?php
				foreach ( $k['pola'] as $klucz => $f ) {
					cpe_pole(
						$klucz,
						$f,
						$wart[ $klucz ],
						array(
							'name'           => 'cpe[' . $klucz . ']',
							'id'             => 'cpe-' . $klucz,
							'klucz_podgladu' => '' !== (string) $f['selektor'] ? $klucz : '',
							'tkniete'        => ! empty( $tkniete[ $klucz ] ),
						)
					);
				}
				?>
			</div>
			<?php endif; ?>
			<?php if ( '' !== (string) $k['info'] ) : ?>
				<p class="cpe-info"<?php echo $sam && ! $k['wspolne'] && '' !== (string) $k['selektor'] ? ' data-key="karta:' . esc_attr( $kid ) . '" data-cft-bez-podmiany' : ''; ?>><?php echo cpe_t( $k['info'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<?php endif; ?>
			<?php
			if ( $k['wspolne'] ) {
				cpe_wspolne( $kid, $k, $sam, $w_wart );
			}
			?>
		</div>
	</details>
	<?php
}

/* ---------------------------------------------------------------------------
 * Ekran
 * ------------------------------------------------------------------------- */

function cpe_ekran(): void {
	if ( ! current_user_can( 'edit_products' ) ) {
		wp_die( 'Brak uprawnień.', 403 );
	}
	$id = cpe_id();
	$p  = $id ? wc_get_product( $id ) : null;
	if ( ! $p ) {
		cpe_lista();
		return;
	}
	$uid   = get_current_user_id();
	$karty = colorify_produkt_karty( $id );
	$baza  = colorify_produkt_wartosci( $p, $karty );
	$pola  = colorify_produkt_pola( $karty );
	$msg   = isset( $_GET['cpe_msg'] ) ? sanitize_key( wp_unslash( $_GET['cpe_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$blad  = in_array( $msg, array( 'konflikt', 'cena' ), true );
	$zach  = $blad ? get_transient( 'colorify_produkt_form_' . $uid . '_' . $id ) : null;
	$wart  = $baza;
	$tkn   = array();
	if ( is_array( $zach ) && isset( $zach['nowe'] ) && is_array( $zach['nowe'] ) ) {
		// Po nieudanym zapisie formularz pokazuje wartości osoby, która pisała; edytory WP z różnicą zapiszą się przy kolejnej próbie.
		foreach ( $baza as $klucz => $v ) {
			if ( array_key_exists( $klucz, $zach['nowe'] ) ) {
				$wart[ $klucz ] = $zach['nowe'][ $klucz ];
				$tkn[ $klucz ]  = 'html' === $pola[ $klucz ]['typ'] && $wart[ $klucz ] !== $v;
			}
		}
	}
	$obce = array();
	foreach ( is_array( $zach ) ? (array) ( $zach['obce'] ?? array() ) : array() as $klucz ) {
		if ( isset( $pola[ $klucz ] ) ) {
			$obce[] = (string) $pola[ $klucz ]['etykieta'];
		}
	}
	set_transient( 'colorify_produkt_baza_' . $uid . '_' . $id, $baza, DAY_IN_SECONDS ); // do listy pól zmienionych przez kogoś innego
	$inny = wp_check_post_lock( $id );
	if ( ! $inny ) {
		wp_set_post_lock( $id ); // zwykły ekran produktu pokaże innym „… teraz edytuje”
	}
	$adres  = cpe_adres( $id );
	$status = (string) $p->get_status();
	$teksty = array(
		'zapisano'  => 'Zapisane. Zmiany są już na stronie produktu.',
		'bez-zmian' => 'Nic się nie zmieniło, więc nic nie zostało zapisane.',
		'konflikt'  => 'Nie zapisano: ten produkt został w międzyczasie zmieniony w innym miejscu (na przykład w zwykłym edytorze produktu). Poniżej są Twoje zmiany. Sprawdź je i zapisz jeszcze raz.',
		'cena'      => 'Nie zapisano: cena promocyjna musi być niższa od zwykłej ceny. Popraw kartę „Cena” i zapisz jeszcze raz.',
	);
	$rew    = wp_get_post_revisions( $id, array( 'posts_per_page' => 1 ) );
	$rew    = $rew ? reset( $rew ) : null;
	?>
	<div class="wrap cft cpe">
		<div class="cft-naglowek">
			<a class="cpe-wroc" href="<?php echo esc_url( colorify_produkt_url() ); ?>"><span aria-hidden="true">&larr;</span> Wszystkie produkty</a>
			<h1 class="cft-h1" data-kafel-txt="nazwa"><?php echo esc_html( wp_strip_all_tags( $p->get_name( 'edit' ) ) ); ?></h1>
			<p class="cft-lead"><?php echo cpe_t( 'Każda karta to jeden element strony produktu, w kolejności od góry strony. Po prawej widzisz stronę ze swoimi zmianami. Klienci zobaczą je dopiero po „Zapisz zmiany”.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		</div>

		<?php if ( 'publish' !== $status ) : ?>
			<p class="cpe-uwaga" role="note"><strong>To szkic.</strong> <?php echo cpe_t( 'Klienci nie widzą tego produktu w sklepie, dopóki nie zostanie opublikowany na zwykłym ekranie produktu.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php endif; ?>
		<?php if ( $inny ) : ?>
			<p class="cpe-uwaga" role="alert"><strong><?php echo esc_html( get_userdata( $inny ) ? get_userdata( $inny )->display_name : 'Ktoś' ); ?> ma teraz otwarty ten produkt.</strong> <?php echo cpe_t( 'Żeby nie nadpisać sobie zmian, poczekaj, aż skończy, albo ustalcie, kto zapisuje.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php endif; ?>

		<?php if ( isset( $teksty[ $msg ] ) ) : ?>
			<div class="cft-info <?php echo $blad ? 'cft-info--blad' : ''; ?>" role="status">
				<span><?php echo cpe_t( $teksty[ $msg ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<?php if ( 'konflikt' === $msg && $obce ) : ?>
					<span class="cft-info__lista">W międzyczasie zmieniono: <?php echo esc_html( implode( '; ', array_slice( $obce, 0, 8 ) ) ); ?>.</span>
				<?php elseif ( 'zapisano' === $msg ) : ?>
					<span class="cft-info__linki"><a href="<?php echo esc_url( $adres ); ?>" target="_blank" rel="noopener">Zobacz stronę produktu</a></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="cft-obce" id="cft-obce" role="alert" hidden>
			<span>Ten produkt zmienił się poza tym edytorem (na przykład w zwykłym edytorze produktu). Zapis bez odświeżenia zostanie zatrzymany.</span>
			<a href="<?php echo esc_url( colorify_produkt_url( $id ) ); ?>">Odśwież, żeby zobaczyć aktualną wersję</a>
		</div>

		<div class="cft-uklad">
		<div class="cft-split" id="cft-split" role="separator" aria-orientation="vertical" aria-label="Szerokość panelu i podglądu" aria-controls="cft-form cft-podglad" tabindex="0"><span class="cft-split__linia" aria-hidden="true"></span><span class="cft-split__uchwyt" aria-hidden="true" data-dymek="Przeciągnij, żeby zmienić szerokość. Dwuklik przywraca podział."><i></i><i></i></span></div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cft-form" class="cft-form">
			<input type="hidden" name="action" value="colorify_produkt_zapis">
			<input type="hidden" name="cpe_id" value="<?php echo (int) $id; ?>">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'colorify_produkt_zapis_' . $id ) ); ?>">
			<input type="hidden" name="cft_wersja" id="cft-wersja" value="<?php echo esc_attr( colorify_produkt_wersja( $baza ) ); ?>">
			<?php // Wyzwalacze odświeżenia podglądu dla colorify-produkt.js (zmiana pola [data-struktura] przeładowuje ramkę); drugi nie brudzi formularza produktu. ?>
			<input type="hidden" id="cpe-auto" data-struktura>
			<span data-cft-osobno hidden><input type="hidden" id="cpe-auto-w" data-struktura></span>

			<h2 class="cft-h2 cft-h2--strona">Strona produktu, od góry</h2>
			<div class="cft-karty" data-strona="">
				<?php
				foreach ( $karty as $kid => $k ) {
					cpe_karta( (string) $kid, $k, $wart, $tkn );
				}
				?>
			</div>

			<nav class="cft-gdzie cpe-gdzie" aria-label="Pozostałe ustawienia produktu">
				<span class="cft-gdzie__t">Czego tu nie ma:</span>
				<div class="cft-gdzie__siatka">
					<a href="<?php echo esc_url( (string) get_edit_post_link( $id, 'raw' ) ); ?>"><span class="cft-gdzie__i"><?php echo cpe_ik( 'paczka' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="cft-gdzie__o"><span class="cft-gdzie__n">Zwykły ekran produktu</span><small>Magazyn, wysyłka, publikacja, SEO</small></span></a>
					<?php if ( $rew ) : ?>
					<a href="<?php echo esc_url( admin_url( 'revision.php?revision=' . (int) $rew->ID ) ); ?>"><span class="cft-gdzie__i"><?php echo cpe_ik( 'zegar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="cft-gdzie__o"><span class="cft-gdzie__n">Historia zmian</span><small>Wróć do wcześniejszej nazwy, opisu albo tekstu pod ceną</small></span></a>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'edit-comments.php?p=' . (int) $id ) ); ?>"><span class="cft-gdzie__i"><?php echo cpe_ik( 'ludzie' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span><span class="cft-gdzie__o"><span class="cft-gdzie__n">Opinie klientów</span><small>Przeczytaj, zatwierdź albo usuń opinie</small></span></a>
				</div>
			</nav>

			<div class="cft-pasek">
				<button type="submit" class="cft-btn cft-btn--glowny">Zapisz zmiany</button>
				<span class="cft-stan" id="cft-stan" aria-live="polite"></span>
				<span class="cft-pasek__linki">
					<button type="button" class="cft-btn cft-btn--obrys cft-pasek__podglad" data-podglad-otworz aria-controls="cft-podglad" aria-expanded="false">Podgląd</button>
					<a class="cft-btn cft-btn--link" href="<?php echo esc_url( $adres ); ?>" target="_blank" rel="noopener">Zobacz stronę</a>
				</span>
			</div>
		</form>

		<aside class="cft-podglad" id="cft-podglad" aria-label="Podgląd strony produktu">
			<div class="cft-podglad__pasek">
				<button type="button" class="cft-btn cft-btn--obrys cft-btn--maly cpe-odswiez" data-cpe-odswiez><?php echo cpe_ik( 'odswiez' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><span>Odśwież podgląd</span></button>
				<span class="cft-przelacz" role="group" aria-label="Jaka szerokość ekranu"><button type="button" data-urz="komputer" aria-pressed="true" title="1440 px">Komputer</button><button type="button" data-urz="duzy" aria-pressed="false" title="1920 px">Duży ekran</button><button type="button" data-urz="telefon" aria-pressed="false" title="390 px">Telefon</button></span>
				<a class="cft-podglad__nowa" href="<?php echo esc_url( $adres ); ?>" target="_blank" rel="noopener" title="Otwórz stronę produktu w nowej karcie" aria-label="Otwórz stronę produktu w nowej karcie"><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
				<button type="button" class="cft-podglad__zamknij" data-podglad-zamknij aria-label="Zamknij podgląd"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
			</div>
			<p class="cpe-limit" id="cpe-limit" role="status" hidden><?php echo cpe_t( 'Serwer ograniczył liczbę odświeżeń. Podgląd nie odświeża się już sam: kliknij „Odśwież podgląd”, gdy chcesz zobaczyć zmiany.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
			<div class="cft-podglad__ramka">
				<iframe title="Podgląd strony produktu z niezapisanymi zmianami" src="about:blank" loading="lazy"></iframe>
				<p class="cft-podglad__info" aria-live="polite"></p>
			</div>
		</aside>
		</div>
	</div>
	<?php
}

/** Wybór produktu (Produkty, Edytor z podglądem bez ID): wyszukiwarka i lista. */
function cpe_lista(): void {
	$s     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$str   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
	$args  = array(
		'limit'    => 60,
		'page'     => $str,
		'paginate' => true,
		'orderby'  => 'title',
		'order'    => 'ASC',
		'status'   => array( 'publish', 'draft', 'pending', 'private' ),
	);
	if ( '' !== $s ) {
		$args['s'] = $s;
	}
	$wynik = wc_get_products( $args );
	$nazwy = array(
		'draft'   => 'szkic',
		'pending' => 'czeka na przegląd',
		'private' => 'prywatny',
	);
	?>
	<div class="wrap cft cpe">
		<div class="cft-naglowek">
			<h1 class="cft-h1">Edytor produktu z podglądem</h1>
			<p class="cft-lead"><?php echo cpe_t( 'Wybierz produkt. Zobaczysz jego stronę i wszystkie teksty oraz zdjęcia, które można na niej zmienić.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		</div>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="cpe-szukaj" role="search">
			<input type="hidden" name="post_type" value="product">
			<input type="hidden" name="page" value="colorify-produkt">
			<label class="screen-reader-text" for="cpe-s">Szukaj produktu</label>
			<input class="cft-in" type="search" id="cpe-s" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="Szukaj po nazwie produktu">
			<button type="submit" class="cft-btn cft-btn--glowny">Szukaj</button>
			<?php if ( '' !== $s ) : ?><a class="cft-btn cft-btn--link" href="<?php echo esc_url( colorify_produkt_url() ); ?>">Pokaż wszystkie</a><?php endif; ?>
		</form>
		<?php if ( ! $wynik->products ) : ?>
			<p class="cft-brak"><?php echo '' !== $s ? 'Nie ma produktu o takiej nazwie.' : 'W sklepie nie ma jeszcze produktów.'; ?></p>
		<?php else : ?>
			<nav class="cft-gdzie" aria-label="Produkty">
				<div class="cft-gdzie__siatka cpe-produkty">
				<?php
				foreach ( $wynik->products as $p ) :
					if ( ! current_user_can( 'edit_post', $p->get_id() ) ) {
						continue;
					}
					$u    = cpe_miniatura( (int) $p->get_image_id() );
					$cena = '' !== (string) $p->get_price() ? wp_strip_all_tags( wc_price( (float) $p->get_price() ) ) : 'bez ceny';
					$st   = $nazwy[ $p->get_status() ] ?? '';
					?>
					<a href="<?php echo esc_url( colorify_produkt_url( $p->get_id() ) ); ?>">
						<span class="cft-gdzie__i cpe-produkty__i"><?php echo $u ? '<img src="' . esc_url( $u ) . '" alt="">' : cpe_ik( 'paczka' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
						<span class="cft-gdzie__o"><span class="cft-gdzie__n"><?php echo esc_html( wp_strip_all_tags( $p->get_name() ) ); ?></span><small><?php echo esc_html( html_entity_decode( $cena, ENT_QUOTES, 'UTF-8' ) . ( '' !== $st ? ' · ' . $st : '' ) ); ?></small></span>
					</a>
				<?php endforeach; ?>
				</div>
			</nav>
			<?php if ( $wynik->max_num_pages > 1 ) : ?>
				<p class="cpe-strony">
					<?php if ( $str > 1 ) : ?><a class="cft-btn cft-btn--obrys cft-btn--maly" href="<?php echo esc_url( add_query_arg( array( 's' => $s, 'paged' => $str - 1 ), colorify_produkt_url() ) ); ?>">Poprzednie</a><?php endif; ?>
					<span>Strona <?php echo (int) $str; ?> z <?php echo (int) $wynik->max_num_pages; ?></span>
					<?php if ( $str < $wynik->max_num_pages ) : ?><a class="cft-btn cft-btn--obrys cft-btn--maly" href="<?php echo esc_url( add_query_arg( array( 's' => $s, 'paged' => $str + 1 ), colorify_produkt_url() ) ); ?>">Następne</a><?php endif; ?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * Wejścia: lista produktów, zwykły ekran produktu (+ ostrzeżenie, gdy produkt zmienił się w edytorze z podglądem).
 * ------------------------------------------------------------------------- */

add_filter(
	'post_row_actions',
	static function ( $actions, $post ) {
		if ( $post instanceof WP_Post && 'product' === $post->post_type && 'trash' !== $post->post_status && current_user_can( 'edit_post', $post->ID ) ) {
			$actions['colorify_produkt'] = '<a href="' . esc_url( colorify_produkt_url( (int) $post->ID ) ) . '">Edytor z podglądem</a>';
		}
		return $actions;
	},
	20,
	2
);

add_action(
	'edit_form_after_title',
	static function ( $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || 'auto-draft' === $post->post_status ) {
			return;
		}
		echo '<p style="margin:14px 0 4px"><a class="button button-primary button-large" href="' . esc_url( colorify_produkt_url( (int) $post->ID ) ) . '">Edytuj z podglądem strony</a> <span class="description" style="margin-left:8px">Wszystkie teksty i zdjęcia ze strony produktu w jednym miejscu, obok widok strony.</span></p>';
	},
	1
);

add_action(
	'admin_footer-post.php',
	static function () {
		$post = get_post();
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		$p = wc_get_product( $post->ID );
		if ( ! $p ) {
			return;
		}
		$w = colorify_produkt_wersja( colorify_produkt_wartosci( $p, colorify_produkt_karty( (int) $post->ID ) ) );
		$u = add_query_arg(
			array(
				'action'      => 'colorify_produkt_wersja',
				'id'          => (int) $post->ID,
				'bez_blokady' => 1,
			),
			admin_url( 'admin-ajax.php' )
		);
		// Ta sama bramka w drugą stronę: zwykły ekran otwarty przed zapisem w edytorze z podglądem ma stare pola.
		echo '<script>(function(){var w0=' . wp_json_encode( $w ) . ',u=' . wp_json_encode( $u ) . ',t=0,n=null;function s(){if(document.hidden||n||Date.now()-t<30000){return;}t=Date.now();fetch(u,{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){if(!j||!j.success||j.data.w===w0||n){return;}n=document.createElement("div");n.className="notice notice-warning";n.setAttribute("role","alert");n.innerHTML="<p><strong>Ten produkt został zmieniony w innym miejscu (na przykład w edytorze z podglądem).</strong> Odśwież tę stronę, zanim cokolwiek zapiszesz. Inaczej stare pola z tego ekranu nadpiszą nowsze zmiany.</p>";var h=document.querySelector(".wp-header-end");if(h){h.parentNode.insertBefore(n,h.nextSibling);}}).catch(function(){});}window.addEventListener("focus",s);document.addEventListener("visibilitychange",s);})();</script>' . "\n";
	}
);
