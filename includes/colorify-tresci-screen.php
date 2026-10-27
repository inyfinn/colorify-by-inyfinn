<?php
/**
 * Moduł "Edytor treści", ekran wp-admin: teksty, zdjęcia (z kadrem), filmy i listy stron zarejestrowanych przez motyw,
 * z podglądem na żywo prawdziwej strony obok formularza. Ładowany tylko, gdy moduł jest włączony i jest schemat.
 * Model danych i sanitizacja: colorify-tresci.php; schemat: motyw (docs/edytor-tresci.md).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	$r = colorify_tresci_rejestr();
	add_menu_page( $r['tytul'], $r['tytul'], colorify_tresci_cap(), colorify_tresci_slug(), 'cft_ekran', $r['ikona'], (int) $r['pozycja'] );
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'toplevel_page_' . colorify_tresci_slug() !== $hook ) {
		return;
	}
	$r    = colorify_tresci_rejestr();
	$deps = array();
	wp_enqueue_media();
	if ( '' !== (string) $r['fonty'] ) {
		wp_enqueue_style( 'cft-fonty', esc_url_raw( (string) $r['fonty'] ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$deps[] = 'cft-fonty';
	}
	wp_enqueue_style( 'colorify-tresci', COLORIFY_PLUGIN_URL . 'assets/colorify-tresci.css', $deps, COLORIFY_PLUGIN_VERSION );
	wp_enqueue_script( 'colorify-tresci', COLORIFY_PLUGIN_URL . 'assets/colorify-tresci.js', array( 'media-editor' ), COLORIFY_PLUGIN_VERSION, true );
	$sekcje = array();
	foreach ( colorify_tresci_schema() as $sid => $sec ) {
		$sekcje[ $sid ] = array( 'strona' => $sec['strona'] ?? 'oba', 'kotwica' => $sec['kotwica'] ?? '' );
	}
	$pola = array();
	foreach ( colorify_tresci_pola() as $k => $f ) {
		$pola[ $k ] = array( 's' => $f['section'], 't' => $f['type'] );
	}
	$podglad = array();
	$adresy  = array();
	foreach ( colorify_tresci_strony() as $id => $st ) {
		$adresy[ $id ]  = (string) $st['url'];
		$podglad[ $id ] = add_query_arg( 'colorify_tresci_podglad', '1', (string) $st['url'] );
	}
	wp_localize_script( 'colorify-tresci', 'CFT', array(
		'ajax'   => admin_url( 'admin-ajax.php' ),
		'nonce'  => wp_create_nonce( 'colorify_tresci_podglad' ),
		'strony' => $podglad,
		'adresy' => $adresy,
		'sekcje' => $sekcje,
		'pola'   => $pola,
	) );
} );

/* ---------------------------------------------------------------------------
 * Saving, restoring (admin-post.php) and the preview transient (admin-ajax.php)
 * ------------------------------------------------------------------------- */

function cft_wroc( $msg ) {
	wp_safe_redirect( add_query_arg( array( 'page' => colorify_tresci_slug(), 'cft_msg' => $msg ), admin_url( 'admin.php' ) ) );
	exit;
}

/** Posted ej[...] values, unslashed (sanitized per field in colorify_tresci_z_formularza()). */
function cft_post_ej() {
	return isset( $_POST['cft'] ) && is_array( $_POST['cft'] ) ? wp_unslash( $_POST['cft'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
}

add_action( 'admin_post_colorify_tresci_zapis', function () {
	if ( ! current_user_can( colorify_tresci_cap() ) ) {
		wp_die( 'Brak uprawnień.', 403 );
	}
	check_admin_referer( 'colorify_tresci_zapis' );
	$wersja = isset( $_POST['cft_wersja'] ) ? sanitize_key( wp_unslash( $_POST['cft_wersja'] ) ) : null;
	$new    = colorify_tresci_z_formularza( cft_post_ej(), colorify_tresci_stored() );
	$r      = colorify_tresci_zapisz( $new, 'Zapis zmian', null, $wersja );
	if ( is_wp_error( $r ) ) {
		// Gate: the option changed after the panel was opened. Keep the form for a merge, show what changed.
		set_transient( 'colorify_tresci_konflikt_' . get_current_user_id(), array( 'form' => cft_post_ej(), 'baza' => $wersja, 'obce' => $r->get_error_data()['obce'] ), 15 * MINUTE_IN_SECONDS );
		cft_wroc( 'konflikt' );
	}
	cft_wroc( $r ? 'zapisano' : 'bez-zmian' );
} );

/** Merge after a conflict: the user's own changes go in, fields changed by someone else keep that newer value. */
add_action( 'admin_post_colorify_tresci_scal', function () {
	if ( ! current_user_can( colorify_tresci_cap() ) ) {
		wp_die( 'Brak uprawnień.', 403 );
	}
	check_admin_referer( 'colorify_tresci_scal' );
	$k = get_transient( 'colorify_tresci_konflikt_' . get_current_user_id() );
	delete_transient( 'colorify_tresci_konflikt_' . get_current_user_id() );
	$baza = is_array( $k ) ? colorify_tresci_snapshot( $k['baza'] ) : null;
	if ( null === $baza ) {
		cft_wroc( 'brak-wersji' );
	}
	$moje   = colorify_tresci_z_formularza( $k['form'], $baza );
	$merged = colorify_tresci_opcja();
	foreach ( colorify_tresci_roznica( $baza, $moje ) as $key ) {
		if ( in_array( $key, (array) $k['obce'], true ) ) {
			continue; // someone else changed it in the meantime: theirs stays
		}
		if ( array_key_exists( $key, $moje ) ) {
			$merged[ $key ] = $moje[ $key ];
		} else {
			unset( $merged[ $key ] );
		}
	}
	cft_wroc( colorify_tresci_zapisz( $merged, 'Zapis zmian (scalone z cudzymi)' ) ? 'zapisano' : 'bez-zmian' );
} );

/** Light check for the panel: has the option changed since it was opened? */
add_action( 'wp_ajax_colorify_tresci_wersja', function () {
	if ( ! current_user_can( colorify_tresci_cap() ) ) {
		wp_send_json_error( 'Brak uprawnień.', 403 );
	}
	wp_send_json_success( array( 'w' => colorify_tresci_wersja() ) );
} );

add_action( 'admin_post_colorify_tresci_przywroc', function () {
	if ( ! current_user_can( colorify_tresci_cap() ) ) {
		wp_die( 'Brak uprawnień.', 403 );
	}
	check_admin_referer( 'colorify_tresci_przywroc' );
	$ver = isset( $_POST['wersja'] ) ? sanitize_text_field( wp_unslash( $_POST['wersja'] ) ) : '';
	if ( 'domyslne' === $ver ) {
		cft_wroc( colorify_tresci_zapisz( array(), 'Przywrócono teksty i zdjęcia domyślne' ) ? 'domyslne' : 'bez-zmian' );
	}
	$hist = colorify_tresci_historia();
	$i    = (int) $ver;
	$t    = isset( $_POST['t'] ) ? (int) $_POST['t'] : 0;
	if ( ! isset( $hist[ $i ] ) || (int) $hist[ $i ]['t'] !== $t || ! is_array( $hist[ $i ]['d'] ) ) {
		cft_wroc( 'brak-wersji' );
	}
	$label = 'Przywrócono wersję z ' . wp_date( 'j.m.Y H:i', (int) $hist[ $i ]['t'] );
	cft_wroc( colorify_tresci_zapisz( $hist[ $i ]['d'], $label ) ? 'przywrocono' : 'bez-zmian' );
} );

/** Live preview: the whole form goes through the same sanitizer into a transient the page reads with ?colorify_tresci_podglad=1. */
add_action( 'wp_ajax_colorify_tresci_podglad', function () {
	if ( ! current_user_can( colorify_tresci_cap() ) || ! check_ajax_referer( 'colorify_tresci_podglad', 'nonce', false ) ) {
		wp_send_json_error( 'Brak uprawnień.', 403 );
	}
	$new = colorify_tresci_z_formularza( cft_post_ej(), colorify_tresci_stored() );
	set_transient( 'colorify_tresci_podglad_' . get_current_user_id(), $new, HOUR_IN_SECONDS );
	wp_send_json_success( array( 't' => time() ) );
} );

/* ---------------------------------------------------------------------------
 * Rendering helpers
 * ------------------------------------------------------------------------- */

/** Small "where on the page" hint under a field. */
function cft_podpowiedz( $text ) {
	if ( '' !== $text ) {
		echo '<p class="cft-podp">' . esc_html( $text ) . '</p>';
	}
}

/** Sharper source for the crop frame: the "large" size of an attachment, else the file itself. */
function cft_media_url_duzy( $val ) {
	if ( is_numeric( $val ) && (int) $val > 0 ) {
		$u = wp_get_attachment_image_url( (int) $val, 'large' );
		return $u ? $u : colorify_tresci_ref_url( $val );
	}
	return colorify_tresci_ref_url( $val );
}

/** "x,y,z" of a crop array. */
function cft_kadr_tekst( $k ) {
	return (int) $k['x'] . ',' . (int) $k['y'] . ',' . colorify_kadr_liczba( $k['z'] );
}

/**
 * Media block: preview (crop frame for images with a ratio), "choose", "remove", "restore default".
 * $kadr: array( name of the hidden crop input, crop meta r/rm/xy, current crop array|null ) or null.
 */
function cft_media( $name, $val, $f, $has_stored, $kadr = null, $key = '' ) {
	$kind      = isset( $f['kind'] ) ? $f['kind'] : 'image';
	$default   = colorify_tresci_plik_url( $f['default'] );
	$removable = ! empty( $f['removable'] ) && '' !== $f['default'];
	$list_item = ! empty( $f['lista'] ); // a tile of a list: no default, no "restore"
	$cur       = '';
	$post      = '';
	if ( $list_item ) {
		$cur  = cft_media_url_duzy( $val );
		$post = (string) $val;
	} elseif ( $has_stored && '' === $val ) {
		$post = '-'; // removed on purpose
	} elseif ( $has_stored ) {
		$cur = cft_media_url_duzy( $val );
		if ( '' === $cur ) {
			$cur = $default; // chosen file is gone: the page falls back to the default as well
		} else {
			$post = (string) $val;
		}
	} else {
		$cur = $default;
	}
	echo '<div class="cft-media' . ( $kadr ? ' cft-media--kadr' : '' ) . '" data-kind="' . esc_attr( $kind ) . '" data-default-url="' . esc_attr( $default ) . '" data-key="' . esc_attr( $key ) . '">';
	echo '<input type="hidden" class="cft-media__val" name="' . esc_attr( $name ) . '" value="' . esc_attr( $post ) . '">';
	if ( $kadr ) {
		$def = array( 'x' => $kadr[1]['xy'][0], 'y' => $kadr[1]['xy'][1], 'z' => 1 );
		$cur_k = $kadr[2] ? $kadr[2] : $def;
		$css   = colorify_kadr_css( $cur_k, $def );
		$css   = '' !== $css ? $css : 'object-position:' . (int) $def['x'] . '% ' . (int) $def['y'] . '%;';
		echo '<div class="cft-kadr" data-r="' . esc_attr( $kadr[1]['r'] ) . '" data-rm="' . esc_attr( $kadr[1]['rm'] ) . '" data-rl="' . esc_attr( ! empty( $kadr[1]['rl'] ) ? $kadr[1]['rl'] : $kadr[1]['r'] ) . '" data-def="' . esc_attr( cft_kadr_tekst( $def ) ) . '">';
		echo '<div class="cft-kadr__ramka" style="aspect-ratio:' . esc_attr( $kadr[1]['r'] ) . '">';
		if ( '' !== $cur ) {
			echo '<img src="' . esc_url( $cur ) . '" alt="" draggable="false" style="' . esc_attr( $css ) . '">';
			echo '<button type="button" class="cft-kadr__pin" style="left:' . (int) $cur_k['x'] . '%;top:' . (int) $cur_k['y'] . '%" aria-label="Punkt ostrości: przeciągnij myszą albo przesuń strzałkami"></button>';
		} else {
			echo '<span class="cft-media__brak">Brak, sekcja będzie bez tego elementu</span>';
		}
		echo '</div>';
		echo '<input type="hidden" class="cft-kadr__val" name="' . esc_attr( $kadr[0] ) . '" value="' . esc_attr( $kadr[2] ? cft_kadr_tekst( $kadr[2] ) : '' ) . '">';
		echo '<div class="cft-kadr__ster">';
		echo '<label class="cft-kadr__zoom"><span>Przybliżenie</span><input type="range" min="1" max="2.5" step="0.05" value="' . esc_attr( colorify_kadr_liczba( $cur_k['z'] ) ) . '" aria-label="Przybliżenie"><output>' . esc_html( str_replace( '.', ',', colorify_kadr_liczba( $cur_k['z'] ) ) ) . '×</output></label>';
		echo '<div class="cft-kadr__btns"><button type="button" class="cft-btn cft-btn--maly" data-kadr="srodek">Wyśrodkuj</button><button type="button" class="cft-btn cft-btn--link" data-kadr="domyslne">Domyślny kadr</button>';
		if ( $kadr[1]['r'] !== $kadr[1]['rm'] || ! empty( $kadr[1]['rl'] ) ) {
			echo '<span class="cft-przelacz cft-przelacz--maly" role="group" aria-label="Podgląd kadru"><button type="button" data-kadr-widok="r" aria-pressed="true">Komputer</button>';
			if ( ! empty( $kadr[1]['rl'] ) ) {
				echo '<button type="button" data-kadr-widok="rl" aria-pressed="false">Duży ekran</button>';
			}
			echo '<button type="button" data-kadr-widok="rm" aria-pressed="false">Telefon</button></span>';
		}
		echo '</div></div></div>';
	} else {
		echo '<div class="cft-media__pod">';
		if ( '' !== $cur ) {
			if ( 'video' === $kind ) {
				echo '<video src="' . esc_url( $cur ) . '#t=0.5" muted playsinline preload="metadata" controls></video>';
			} else {
				echo '<img src="' . esc_url( $cur ) . '" alt="">';
			}
		} else {
			echo '<span class="cft-media__brak">Brak, sekcja będzie bez tego elementu</span>';
		}
		echo '</div>';
	}
	echo '<div class="cft-media__akcje">';
	echo '<button type="button" class="cft-btn cft-btn--obrys cft-btn--maly" data-act="wybierz">' . ( 'video' === $kind ? 'Wybierz film' : 'Wybierz zdjęcie' ) . '</button>';
	if ( $removable ) {
		echo '<button type="button" class="cft-btn cft-btn--link" data-act="usun">Usuń</button>';
	}
	if ( ! $list_item && '' !== $f['default'] ) {
		echo '<button type="button" class="cft-btn cft-btn--link" data-act="domyslne">Przywróć domyślne</button>';
	}
	echo '</div></div>';
}

/** Paragraph editor: toolbar + contenteditable + hidden textarea that carries the HTML to the form. */
function cft_edytor( $id, $name, $val, $default, $rows, $multi = false, $key = '' ) {
	echo '<div class="cft-edytor" data-multi="' . ( $multi ? '1' : '0' ) . '">';
	echo '<div class="cft-tb" role="toolbar" aria-label="Formatowanie">';
	echo '<button type="button" data-cmd="bold" title="Pogrubienie" aria-label="Pogrubienie"><b>B</b></button>';
	echo '<button type="button" data-cmd="italic" title="Kursywa" aria-label="Kursywa"><i>I</i></button>';
	echo '<button type="button" data-cmd="br-lg" title="Nowa linia tylko na komputerze (na telefonie tekst płynie dalej)">↵ tylko komputer</button>';
	echo '<button type="button" data-cmd="keep" title="Zaznaczone słowa zostaną w jednej linii">w jednej linii</button>';
	echo '</div>';
	echo '<div class="cft-ed cft-in" contenteditable="true" id="' . esc_attr( $id ) . '-ed" role="textbox" aria-multiline="true" aria-labelledby="' . esc_attr( $id ) . '-l" data-key="' . esc_attr( $key ) . '" style="min-height:' . ( 24 * max( 2, (int) $rows ) + 22 ) . 'px"></div>';
	echo '<textarea class="cft-src" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" hidden data-default="' . esc_attr( $default ) . '">' . esc_textarea( $val ) . '</textarea>';
	echo '</div>';
}

/** One field of the registry. */
function cft_pole( $key, $f, $stored ) {
	$has  = array_key_exists( $key, $stored );
	$val  = $has ? $stored[ $key ] : $f['default'];
	$id   = 'cft-' . $key;
	$name = 'cft[' . $key . ']';
	$type = $f['type'];

	echo '<div class="cft-pole cft-pole--' . esc_attr( $type ) . '" data-pole="' . esc_attr( $key ) . '">';

	if ( 'media' === $type ) {
		echo '<span class="cft-etykieta" id="' . esc_attr( $id ) . '-l">' . esc_html( $f['label'] ) . '</span>';
		$kadr = null;
		if ( ! empty( $f['kadr'] ) ) {
			$kk   = $key . '__kadr';
			$kadr = array( 'cft[' . $kk . ']', $f['kadr'], array_key_exists( $kk, $stored ) ? $stored[ $kk ] : null );
		}
		cft_media( $name, $val, $f, $has, $kadr, $key );
		cft_podpowiedz( $f['hint'] );
		echo '</div>';
		return;
	}
	if ( colorify_tresci_jest_lista( $type ) ) {
		echo '<span class="cft-etykieta">' . esc_html( $f['label'] ) . '</span>';
		cft_podpowiedz( $f['hint'] );
		cft_lista( $key, $type, $val, $f['default'] );
		echo '</div>';
		return;
	}

	echo '<label class="cft-etykieta" id="' . esc_attr( $id ) . '-l" for="' . esc_attr( $id ) . ( 'area' === $type && empty( $f['czysty'] ) ? '-ed' : '' ) . '">' . esc_html( $f['label'] ) . '</label>';
	if ( 'area' === $type && empty( $f['czysty'] ) ) {
		cft_edytor( $id, $name, colorify_tresci_do_wysiwyg( $val ), colorify_tresci_do_wysiwyg( $f['default'] ), $f['rows'] ?? 3, false, $key );
		$default = colorify_tresci_do_wysiwyg( $f['default'] );
	} elseif ( 'area' === $type ) {
		$default = colorify_tresci_do_edycji( $f['default'], 'text' );
		echo '<textarea class="cft-in" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) ( $f['rows'] ?? 3 ) . '" data-key="' . esc_attr( $key ) . '" data-default="' . esc_attr( $default ) . '">' . esc_textarea( colorify_tresci_do_edycji( $val, 'text' ) ) . '</textarea>';
	} else {
		$edit    = colorify_tresci_do_edycji( $val, 'text' );
		$default = colorify_tresci_do_edycji( $f['default'], 'text' );
		$t       = 'url' === $type ? 'url' : ( 'email' === $type ? 'email' : 'text' );
		echo '<input class="cft-in" type="' . esc_attr( $t ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $edit ) . '" data-key="' . esc_attr( $key ) . '" data-default="' . esc_attr( $default ) . '">';
	}
	echo '<div class="cft-pod">';
	cft_podpowiedz( $f['hint'] );
	if ( '' !== $default ) {
		echo '<button type="button" class="cft-btn cft-btn--link cft-reset" data-target="' . esc_attr( $id ) . '">Przywróć domyślne</button>';
	}
	echo '</div></div>';
}

/** One row of a list; $i = index or __i__ in the template. Columns from colorify_tresci_listy(). */
function cft_wiersz( $key, $type, $i, $row ) {
	$n    = 'cft[' . $key . '][' . $i . ']';
	$cols = colorify_tresci_listy()[ $type ];
	echo '<div class="cft-wiersz cft-wiersz--' . esc_attr( $type ) . '" data-wiersz data-i="' . esc_attr( $i ) . '">';
	echo '<div class="cft-wiersz__gora"><span class="cft-nr">' . ( is_numeric( $i ) ? (int) $i + 1 : '' ) . '</span><div class="cft-ruch">';
	echo '<button type="button" class="cft-ikona" data-ruch="gora" aria-label="Przesuń wyżej"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>';
	echo '<button type="button" class="cft-ikona" data-ruch="dol" aria-label="Przesuń niżej"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>';
	echo '<button type="button" class="cft-ikona cft-ikona--usun" data-ruch="usun" aria-label="Usuń"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
	echo '</div></div>';
	foreach ( $cols as $c => $d ) {
		$v   = $row[ $c ] ?? '';
		$id  = 'cft-' . $key . '-' . $i . '-' . $c;
		$pk  = $key . '.' . $i . '.' . $c; // data-cft-key in the preview
		if ( 'media' === $d[0] ) {
			$kadr = null;
			if ( ! empty( $d[2]['kadr'] ) ) {
				$kadr = array( $n . '[kadr]', $d[2]['kadr'], isset( $row['kadr'] ) ? $row['kadr'] : null );
			}
			echo '<div class="cft-pole cft-pole--media"><span class="cft-etykieta">' . esc_html( $d[1] ) . '</span>';
			cft_media( $n . '[' . $c . ']', $v, array( 'kind' => 'image', 'default' => '', 'lista' => true ), true, $kadr, $pk );
			echo '</div>';
		} elseif ( 'area' === $d[0] ) {
			echo '<div class="cft-pole cft-pole--area"><label class="cft-etykieta" id="' . esc_attr( $id ) . '-l" for="' . esc_attr( $id ) . '-ed">' . esc_html( $d[1] ) . '</label>';
			cft_edytor( $id, $n . '[' . $c . ']', colorify_tresci_do_wysiwyg( $v ), '', $d[2]['rows'] ?? 3, ! empty( $d[2]['multi'] ), $pk );
			echo '</div>';
		} else {
			$t = 'url' === $d[0] ? 'url' : 'text';
			echo '<label class="cft-etykieta">' . esc_html( $d[1] ) . '<input class="cft-in" type="' . $t . '" name="' . esc_attr( $n . '[' . $c . ']' ) . '" value="' . esc_attr( 'text' === $d[0] ? colorify_tresci_do_edycji( $v, 'text' ) : $v ) . '" data-key="' . esc_attr( $pk ) . '"></label>';
		}
	}
	echo '</div>';
}

/** List editor: rows, add button, template for new rows, template with the default rows. */
function cft_lista( $key, $type, $val, $default ) {
	$pusty = array_fill_keys( array_keys( colorify_tresci_listy()[ $type ] ), '' );
	$nazwy = array( 'faq' => 'Dodaj pytanie', 'ig' => 'Dodaj zdjęcie', 'punkty' => 'Dodaj punkt', 'menu' => 'Dodaj pozycję' );
	echo '<div class="cft-lista" data-lista="' . esc_attr( $key ) . '">';
	echo '<div class="cft-wiersze">';
	foreach ( array_values( (array) $val ) as $i => $row ) {
		cft_wiersz( $key, $type, $i, $row );
	}
	echo '</div>';
	echo '<template class="cft-szablon">';
	cft_wiersz( $key, $type, '__i__', $pusty );
	echo '</template>';
	echo '<template class="cft-domyslne">';
	foreach ( array_values( (array) $default ) as $i => $row ) {
		cft_wiersz( $key, $type, $i, $row );
	}
	echo '</template>';
	echo '<div class="cft-lista__akcje">';
	echo '<button type="button" class="cft-btn cft-btn--obrys cft-btn--maly" data-dodaj>' . esc_html( $nazwy[ $type ] ?? 'Dodaj' ) . '</button>';
	echo '<button type="button" class="cft-btn cft-btn--link" data-lista-domyslna>Przywróć domyślną listę</button>';
	echo '</div></div>';
}

/**
 * Ikona kafelka "Resztę strony edytujesz tutaj": nazwa z zestawu niżej albo z zestawu kokpitu (Colorify_Dashboard),
 * albo gotowy <svg>; '' = strzałka. Linia 1,5 px, kolor z CSS (złoto).
 */
function cft_ikona( $ikona ) {
	$ikona = (string) $ikona;
	if ( 0 === strpos( ltrim( $ikona ), '<svg' ) ) {
		return wp_kses( $ikona, array( 'svg' => array( 'viewbox' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'aria-hidden' => true, 'class' => true ), 'path' => array( 'd' => true ), 'circle' => array( 'cx' => true, 'cy' => true, 'r' => true ), 'rect' => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true ), 'line' => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ), 'polyline' => array( 'points' => true ) ) );
	}
	$wlasne = array(
		'metka'    => '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5"/>',
		'serce'    => '<path d="M12 20s-7-4.4-9-8.5C1.5 8 4 5 7 5c2 0 3.5 1 5 3 1.5-2 3-3 5-3 3 0 5.5 3 4 6.5-2 4.1-9 8.5-9 8.5z"/>',
		'koperta'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
		'podpis'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M8 17c1.5-3 3-3 3.5-1 .5 1.5 1.5 1 2.5-1"/>',
		'ksiazka'  => '<path d="M4 4h6a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4z"/><path d="M20 4h-6a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h7z"/>',
		'tarcza'   => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
		'ciastko'  => '<path d="M12 3a9 9 0 1 0 9 9 3.5 3.5 0 0 1-4-4 3.5 3.5 0 0 1-5-5z"/><circle cx="8.5" cy="10.5" r="1"/><circle cx="12" cy="15.5" r="1"/><circle cx="15.5" cy="12.5" r="1"/><circle cx="9" cy="15" r=".6"/>',
		'dymek'    => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9h8M8 12h5"/>',
		'strzalka' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
	);
	$p = $wlasne[ $ikona ] ?? ( class_exists( 'Colorify_Dashboard' ) ? Colorify_Dashboard::ikona_sciezki( $ikona ) : '' );
	if ( '' === $p ) {
		$p = $wlasne['strzalka'];
	}
	return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Group of a field inside a card: teksty | media | przyciski | lista. */
function cft_grupa( $key, $f ) {
	if ( 'media' === $f['type'] ) {
		return 'media';
	}
	if ( colorify_tresci_jest_lista( $f['type'] ) ) {
		return 'lista';
	}
	if ( ! empty( $f['grupa'] ) ) {
		return (string) $f['grupa'];
	}
	return preg_match( '/' . colorify_tresci_rejestr()['przyciski_regex'] . '/', $key ) ? 'przyciski' : 'teksty';
}

/**
 * Tile in the card header: a small composition of the section from its current values (updated live).
 * Section key 'kafel' => array( 'typ' => hero|zdjecia|rolki|boksy|ciemny|cytat|linie|pasek|logo|seo|tekst,
 * 'img' => image field keys, 'txt' => text field key, 'lista' => list key (pasek), 'ile' => boxes ).
 * Without 'kafel': 'linie' with the first text field of the section.
 */
function cft_kafel( $sid ) {
	$sec = colorify_tresci_schema()[ $sid ];
	$k   = isset( $sec['kafel'] ) ? (array) $sec['kafel'] : array();
	if ( empty( $k['typ'] ) ) {
		$k['typ'] = 'linie';
	}
	if ( empty( $k['txt'] ) ) {
		foreach ( (array) $sec['fields'] as $key => $f ) {
			if ( in_array( $f['type'], array( 'text', 'area' ), true ) ) {
				$k['txt'] = $key;
				break;
			}
		}
	}
	$img = function ( $key, $class = '' ) {
		$u = colorify_tresc_media( $key );
		return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $u ) . '" alt="" data-kafel-img="' . esc_attr( $key ) . '"' . ( '' === $u ? ' hidden' : '' ) . '>';
	};
	$txt = function ( $key, $class = 'cft-kafel__h' ) {
		return '' === (string) $key ? '' : '<span class="' . esc_attr( $class ) . '" data-kafel-txt="' . esc_attr( $key ) . '">' . esc_html( wp_strip_all_tags( str_replace( '&nbsp;', ' ', (string) colorify_tresc( $key ) ) ) ) . '</span>';
	};
	$imgs  = '';
	foreach ( (array) ( $k['img'] ?? array() ) as $key ) {
		$imgs .= $img( $key );
	}
	$lines = '<span class="cft-kafel__l"></span><span class="cft-kafel__l"></span><span class="cft-kafel__l cft-kafel__l--k"></span>';
	$box   = str_repeat( '<span class="cft-kafel__box"></span>', max( 1, (int) ( $k['ile'] ?? 3 ) ) );
	$t     = (string) ( $k['txt'] ?? '' );
	echo '<span class="cft-kafel cft-kafel--' . esc_attr( $k['typ'] ) . ( 'boksy' === $k['typ'] && (int) ( $k['ile'] ?? 3 ) >= 4 ? ' cft-kafel--boksy-4' : '' ) . '" aria-hidden="true">';
	switch ( $k['typ'] ) {
		case 'hero':
			echo ( ! empty( $k['img'][0] ) ? $img( $k['img'][0], 'cft-kafel__bg' ) : '' ) . $txt( $t );
			break;
		case 'zdjecia':
		case 'rolki':
			echo $imgs;
			break;
		case 'boksy':
			echo $txt( $t ) . $box;
			break;
		case 'ciemny':
			echo $txt( $t ) . $lines;
			break;
		case 'cytat':
			echo '<span class="cft-kafel__q">„</span>' . $txt( $t );
			break;
		case 'pasek':
			foreach ( array_slice( colorify_tresc_lista( (string) ( $k['lista'] ?? '' ) ), 0, 5 ) as $i => $row ) {
				$col = (string) ( $k['kolumna'] ?? 'img' );
				echo '<img src="' . esc_url( colorify_tresci_ref_url( $row[ $col ] ?? '' ) ) . '" alt="" data-kafel-img="' . esc_attr( $k['lista'] . '.' . (int) $i . '.' . $col ) . '">';
			}
			break;
		case 'logo':
			echo '<span class="cft-kafel__dot"></span><span class="cft-kafel__dot"></span>' . $txt( $t ) . '<span class="cft-kafel__dot"></span><span class="cft-kafel__dot"></span>';
			break;
		case 'seo':
			echo $txt( $t, 'cft-kafel__h cft-kafel__h--seo' ) . $lines;
			break;
		case 'tekst':
			echo '<span class="cft-kafel__t">' . $txt( $t ) . $lines . '</span>' . $imgs;
			break;
		default:
			echo $txt( $t ) . $lines;
	}
	echo '</span>';
}

/** Names of the fields that differ between two snapshots (for the history list). */
function cft_zmienione( $a, $b ) {
	$pola = colorify_tresci_pola();
	$out  = array();
	foreach ( array_unique( array_merge( array_keys( (array) $a ), array_keys( (array) $b ) ) ) as $k ) {
		$x = array_key_exists( $k, (array) $a ) ? $a[ $k ] : null;
		$y = array_key_exists( $k, (array) $b ) ? $b[ $k ] : null;
		if ( $x != $y && isset( $pola[ $k ] ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons
			$sec   = colorify_tresci_schema()[ $pola[ $k ]['section'] ]['title'];
			$out[] = $sec . ': ' . mb_strtolower( $pola[ $k ]['label'] );
		}
	}
	return $out;
}

/* ---------------------------------------------------------------------------
 * The screen
 * ------------------------------------------------------------------------- */

function cft_ekran() {
	if ( ! current_user_can( colorify_tresci_cap() ) ) {
		wp_die( 'Brak uprawnień.', 403 );
	}
	$stored = colorify_tresci_stored();
	$msg    = isset( $_GET['cft_msg'] ) ? sanitize_key( wp_unslash( $_GET['cft_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$teksty = array(
		'zapisano'    => 'Zapisane. Zmiany są już na stronie.',
		'przywrocono' => 'Przywrócono wybraną wersję. Zmiany są już na stronie.',
		'domyslne'    => 'Przywrócono wszystkie teksty i zdjęcia domyślne.',
		'bez-zmian'   => 'Nic się nie zmieniło, więc nic nie zostało zapisane.',
		'brak-wersji' => 'Nie ma takiej wersji. Odśwież stronę i spróbuj jeszcze raz.',
		'konflikt'    => 'Nie zapisano: ktoś (np. AI) zmienił treści po otwarciu panelu. Odśwież, żeby zobaczyć aktualną wersję.',
	);
	$konflikt = 'konflikt' === $msg ? get_transient( 'colorify_tresci_konflikt_' . get_current_user_id() ) : null;
	$rej    = colorify_tresci_rejestr();
	$strony = colorify_tresci_strony();
	$home   = (string) reset( $strony )['url'];
	$grupy  = array( 'teksty' => 'Teksty', 'media' => 'Zdjęcia i filmy', 'przyciski' => 'Przyciski i linki', 'lista' => '' );
	$nazwy  = array( 'oba' => 'Każda podstrona' );
	foreach ( $strony as $sid_s => $st ) {
		$nazwy[ $sid_s ] = (string) $st['nazwa'];
	}
	$cli    = '' !== (string) $rej['cli'] ? (string) $rej['cli'] : 'colorify-tresci';
	?>
	<div class="wrap cft">
		<div class="cft-naglowek">
			<h1 class="cft-h1"><?php echo esc_html( $rej['tytul'] ); ?></h1>
			<p class="cft-lead"><?php echo esc_html( '' !== (string) $rej['lead'] ? $rej['lead'] : 'Teksty, zdjęcia, kadry i filmy strony. Po prawej widzisz na żywo, co zmieniasz; na stronie pojawi się to dopiero po „Zapisz zmiany”.' ); ?></p>
		</div>

		<?php if ( ! empty( $rej['gdzie'] ) ) : ?>
		<nav class="cft-gdzie" aria-label="Gdzie edytujesz resztę strony">
			<span class="cft-gdzie__t">Resztę strony edytujesz tutaj:</span>
			<div class="cft-gdzie__siatka">
			<?php foreach ( (array) $rej['gdzie'] as $g ) : ?>
				<a href="<?php echo esc_url( 0 === strpos( (string) $g[2], 'http' ) ? $g[2] : admin_url( (string) $g[2] ) ); ?>"><span class="cft-gdzie__i"><?php echo cft_ikona( $g[3] ?? '' ); // SVG z własnego zestawu albo po wp_kses ?></span><span class="cft-gdzie__o"><span class="cft-gdzie__n"><?php echo esc_html( $g[0] ); ?></span><small><?php echo esc_html( $g[1] ); ?></small></span></a>
			<?php endforeach; ?>
			</div>
		</nav>
		<?php endif; ?>

		<?php if ( isset( $teksty[ $msg ] ) ) : ?>
			<div class="cft-info <?php echo in_array( $msg, array( 'brak-wersji', 'konflikt' ), true ) ? 'cft-info--blad' : ''; ?>" role="status">
				<span><?php echo esc_html( $teksty[ $msg ] ); ?></span>
				<?php if ( 'konflikt' === $msg && is_array( $konflikt ) ) : ?>
					<?php
					$pola_k  = colorify_tresci_pola();
					$etyk    = array();
					foreach ( (array) $konflikt['obce'] as $k ) {
						if ( isset( $pola_k[ $k ] ) ) {
							$etyk[] = colorify_tresci_schema()[ $pola_k[ $k ]['section'] ]['title'] . ': ' . mb_strtolower( $pola_k[ $k ]['label'] );
						}
					}
					$snapshot_ok = null !== colorify_tresci_snapshot( $konflikt['baza'] );
					?>
					<span class="cft-info__lista">W międzyczasie zmieniono: <?php echo $etyk ? esc_html( implode( '; ', array_slice( $etyk, 0, 8 ) ) . ( count( $etyk ) > 8 ? ' i ' . ( count( $etyk ) - 8 ) . ' więcej' : '' ) ) : 'nie wiadomo, które pola (wersja sprzed otwarcia nie jest już w historii)'; ?>.</span>
					<span class="cft-info__linki">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . colorify_tresci_slug() ) ); ?>">Odśwież i odrzuć moje zmiany</a>
						<?php if ( $snapshot_ok ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cft-info__f">
							<input type="hidden" name="action" value="colorify_tresci_scal">
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'colorify_tresci_scal' ) ); ?>">
							<button type="submit" class="cft-btn cft-btn--obrys cft-btn--maly">Zapisz moje zmiany w polach, których nikt inny nie ruszał</button>
						</form>
						<?php endif; ?>
					</span>
				<?php elseif ( ! in_array( $msg, array( 'brak-wersji', 'konflikt' ), true ) ) : ?>
					<span class="cft-info__linki"><?php foreach ( $strony as $st ) : ?><a href="<?php echo esc_url( $st['url'] ); ?>" target="_blank" rel="noopener">Zobacz: <?php echo esc_html( $st['nazwa'] ); ?></a><?php endforeach; ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="cft-obce" id="cft-obce" role="alert" hidden>
			<span>Treści zmieniły się poza panelem (np. zmiana przez AI). Zapis bez odświeżenia zostanie zatrzymany.</span>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . colorify_tresci_slug() ) ); ?>">Odśwież, żeby zobaczyć aktualną wersję</a>
		</div>

		<details class="cft-wskazowki">
			<summary>Jak to działa</summary>
			<ul>
				<li>Każda karta to jedna część strony, w kolejności od góry. Kliknij nazwę, żeby ją rozwinąć. Podgląd po prawej sam przewija się do tej części, a kliknięcie w podglądzie otwiera właściwe pole.</li>
				<li>Zdjęcie: ramka pokazuje dokładnie tyle, ile widać na stronie. Przeciągnij punkt, który ma być najważniejszy, i dosuń przybliżenie. „Komputer / Telefon” pokazuje, jak kadr wypada na obu.</li>
				<li>W akapitach Enter robi nową linię; pogrubienie i kursywa po zaznaczeniu tekstu. Znaczki „↵ tylko komputer” i „w jednej linii” sterują łamaniem wiersza na stronie.</li>
				<li>Agent AI zmienia te same treści poleceniem „wp <?php echo esc_html( $cli ); ?>”, więc panel, agent i strona zawsze pokazują to samo. Gdy ktoś zapisze coś w międzyczasie, panel to zauważy i nie nadpisze cudzych zmian.</li>
				<li>Pomyłka? Na dole jest historia ostatnich 10 zapisów z przyciskiem „Przywróć”.</li>
			</ul>
		</details>

		<div class="cft-uklad">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cft-form" class="cft-form">
			<input type="hidden" name="action" value="colorify_tresci_zapis">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'colorify_tresci_zapis' ) ); ?>">
			<input type="hidden" name="cft_wersja" id="cft-wersja" value="<?php echo esc_attr( colorify_tresci_wersja() ); ?>">

			<?php
			$ostatnia = '';
			foreach ( colorify_tresci_schema() as $sid => $sec ) :
				$sec += array( 'strona' => 'oba', 'kotwica' => '', 'where' => '', 'link' => array() );
				$zm = 0;
				foreach ( array_keys( $sec['fields'] ) as $k ) {
					$zm += array_key_exists( $k, $stored ) ? 1 : 0;
					$zm += array_key_exists( $k . '__kadr', $stored ) ? 1 : 0;
				}
				if ( $sec['strona'] !== $ostatnia ) :
					$ostatnia = $sec['strona'];
					?>
					<h2 class="cft-h2 cft-h2--strona"><?php echo esc_html( $nazwy[ $ostatnia ] ?? $ostatnia ); ?></h2>
				<?php endif; ?>
				<details class="cft-karta" id="cft-karta-<?php echo esc_attr( $sid ); ?>" data-karta="<?php echo esc_attr( $sid ); ?>">
					<summary>
						<?php cft_kafel( $sid ); ?>
						<span class="cft-karta__o">
							<span class="cft-karta__t"><?php echo esc_html( $sec['title'] ); ?></span>
							<span class="cft-karta__g"><?php echo esc_html( $sec['where'] ); ?></span>
						</span>
						<?php if ( $zm ) : ?><span class="cft-karta__zm"><?php echo (int) $zm; ?> <?php echo 1 === $zm ? 'zmiana' : ( $zm < 5 ? 'zmiany' : 'zmian' ); ?></span><?php endif; ?>
					</summary>
					<div class="cft-karta__tresc">
						<?php if ( ! empty( $sec['link'] ) ) : ?>
							<a class="cft-skok" href="<?php echo esc_url( admin_url( $sec['link'][1] ) ); ?>"><?php echo esc_html( $sec['link'][0] ); ?> <span aria-hidden="true">→</span></a>
						<?php endif; ?>
						<?php
						$wg = array();
						foreach ( $sec['fields'] as $key => $f ) {
							$wg[ cft_grupa( $key, $f ) ][ $key ] = $f;
						}
						$ile = count( $wg );
						foreach ( $grupy as $gid => $gt ) :
							if ( empty( $wg[ $gid ] ) ) {
								continue;
							}
							?>
							<div class="cft-grupa cft-grupa--<?php echo esc_attr( $gid ); ?>">
								<?php if ( $ile > 1 && '' !== $gt ) : ?><h3 class="cft-grupa__t"><?php echo esc_html( $gt ); ?></h3><?php endif; ?>
								<?php foreach ( $wg[ $gid ] as $key => $f ) { cft_pole( $key, $f, $stored ); } ?>
							</div>
						<?php endforeach; ?>
					</div>
				</details>
			<?php endforeach; ?>

			<div class="cft-pasek">
				<button type="submit" class="cft-btn cft-btn--glowny">Zapisz zmiany</button>
				<span class="cft-stan" id="cft-stan" aria-live="polite"></span>
				<span class="cft-pasek__linki">
					<button type="button" class="cft-btn cft-btn--obrys cft-pasek__podglad" data-podglad-otworz aria-controls="cft-podglad" aria-expanded="false">Podgląd</button>
					<a class="cft-btn cft-btn--link" href="<?php echo esc_url( $home ); ?>" target="_blank" rel="noopener">Zobacz stronę</a>
				</span>
			</div>
		</form>

		<aside class="cft-podglad" id="cft-podglad" aria-label="Podgląd na żywo">
			<div class="cft-podglad__pasek">
				<span class="cft-przelacz" role="group" aria-label="Która strona"><?php $p1 = true; foreach ( $strony as $sid_s => $st ) : ?><button type="button" data-strona="<?php echo esc_attr( $sid_s ); ?>" aria-pressed="<?php echo $p1 ? 'true' : 'false'; ?>"><?php echo esc_html( $st['nazwa'] ); ?></button><?php $p1 = false; endforeach; ?></span>
				<span class="cft-przelacz" role="group" aria-label="Jaka szerokość ekranu"><button type="button" data-urz="komputer" aria-pressed="true" title="1440 px">Komputer</button><button type="button" data-urz="duzy" aria-pressed="false" title="1920 px">Duży ekran</button><button type="button" data-urz="telefon" aria-pressed="false" title="390 px">Telefon</button></span>
				<a class="cft-podglad__nowa" href="<?php echo esc_url( $home ); ?>" target="_blank" rel="noopener" title="Otwórz stronę w nowej karcie" aria-label="Otwórz stronę w nowej karcie"><span class="dashicons dashicons-external" aria-hidden="true"></span></a>
				<button type="button" class="cft-podglad__zamknij" data-podglad-zamknij aria-label="Zamknij podgląd"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
			</div>
			<div class="cft-podglad__ramka">
				<iframe title="Podgląd strony z niezapisanymi zmianami" src="about:blank" loading="lazy"></iframe>
				<p class="cft-podglad__info" aria-live="polite"></p>
			</div>
		</aside>
		</div>

		<h2 class="cft-h2">Historia zmian</h2>
		<p class="cft-lead">Ostatnie 10 zapisów. „Przywróć” wraca do wybranej wersji, a obecną dopisuje do historii, więc zawsze można wrócić.</p>
		<?php
		$hist = colorify_tresci_historia();
		if ( ! $hist ) :
			?>
			<p class="cft-brak">Jeszcze nic nie zapisano.</p>
		<?php else : ?>
			<ul class="cft-historia">
				<?php
				$juz_teraz = false;
				foreach ( array_reverse( $hist, true ) as $i => $h ) :
					$aktualna  = ! $juz_teraz && ( $h['d'] == $stored ); // phpcs:ignore WordPress.PHP.StrictComparisons -- only the newest matching entry is "current"
					$juz_teraz = $juz_teraz || $aktualna;
					$zm        = isset( $hist[ $i - 1 ] ) ? cft_zmienione( $hist[ $i - 1 ]['d'], $h['d'] ) : array();
					?>
					<li class="cft-historia__w<?php echo $aktualna ? ' is-teraz' : ''; ?>">
						<div class="cft-historia__tekst">
							<strong><?php echo esc_html( wp_date( 'j.m.Y, H:i', (int) $h['t'] ) ); ?></strong>
							<span><?php echo esc_html( $h['l'] . ( $h['u'] ? ', ' . $h['u'] : '' ) ); ?></span>
							<?php if ( $zm ) : ?>
								<small><?php echo esc_html( 'Zmienione: ' . implode( '; ', array_slice( $zm, 0, 3 ) ) . ( count( $zm ) > 3 ? ' i ' . ( count( $zm ) - 3 ) . ' więcej' : '' ) ); ?></small>
							<?php endif; ?>
						</div>
						<?php if ( $aktualna ) : ?>
							<span class="cft-teraz">Obecna wersja</span>
						<?php else : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cft-historia__f">
								<input type="hidden" name="action" value="colorify_tresci_przywroc">
								<input type="hidden" name="wersja" value="<?php echo (int) $i; ?>">
								<input type="hidden" name="t" value="<?php echo (int) $h['t']; ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'colorify_tresci_przywroc' ) ); ?>">
								<button type="submit" class="cft-btn cft-btn--obrys cft-btn--maly">Przywróć</button>
							</form>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cft-reset-all" data-potwierdz="Przywrócić wszystkie teksty, zdjęcia i filmy do tych, które były na początku? Obecna wersja zostanie w historii.">
			<input type="hidden" name="action" value="colorify_tresci_przywroc">
			<input type="hidden" name="wersja" value="domyslne">
			<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'colorify_tresci_przywroc' ) ); ?>">
			<button type="submit" class="cft-btn cft-btn--link">Przywróć wszystko do domyślnych</button>
		</form>
	</div>
	<?php
}

/* ---------------------------------------------------------------------------
 * "Edytuj stronę" for the registered pages opens this panel (the block editor would show an empty page).
 * ------------------------------------------------------------------------- */
function cft_strony_mapa() {
	$m = array();
	foreach ( colorify_tresci_strony() as $st ) {
		if ( ! empty( $st['post'] ) ) {
			$m[ (int) $st['post'] ] = admin_url( 'admin.php?page=' . colorify_tresci_slug() . ( ! empty( $st['karta'] ) ? '#cft-karta-' . $st['karta'] : '' ) );
		}
	}
	return $m;
}
add_filter( 'get_edit_post_link', function ( $link, $id ) {
	$m = cft_strony_mapa();
	return isset( $m[ (int) $id ] ) ? $m[ (int) $id ] : $link;
}, 10, 2 );
add_action( 'load-post.php', function () {
	$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$m  = cft_strony_mapa();
	if ( $id && isset( $m[ $id ] ) && ( ! isset( $_GET['action'] ) || 'edit' === $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( $m[ $id ] );
		exit;
	}
} );
