<?php
/**
 * Samotest importu i eksportu cen (includes/admin-import.php).
 * Dolaczany przez tests/samotest.php (tam jest $sprawdz). Zapisuje wylacznie do WLASNEGO szkicu testowego: kazdy zapis
 * przechodzi przez bramke, ktora odmawia, gdy zmiana dotyczy innej uslugi. Kopia prawdziwego ostatniego importu
 * (opcja) jest na czas testu odkladana i na koncu przywracana. Prawdziwe uslugi: suma kontrolna cen przed i po.
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

echo "--- import i eksport cen (admin-import.php) ---\n";
if ( ! function_exists( 'kw_uslugi_import_analiza' ) ) {
	require_once KW_USLUGI_KATALOG . 'includes/admin.php';
}
wp_set_current_user( isset( $admin ) ? (int) $admin : 1 );

$kw_i_statusy     = array( 'publish', 'draft', 'pending', 'private', 'future' );
$kw_i_kopia_przed = get_option( KW_USLUGI_IMPORT_KOPIA );
delete_option( KW_USLUGI_IMPORT_KOPIA ); // gdyby zapis testowy sie nie udal, "cofnij" nie moze trafic w prawdziwy import
$kw_i_suma = static function ( array $bez ) use ( $kw_i_statusy ) {
	$stan = array();
	foreach ( get_posts(
		array(
			'post_type'      => 'kw_usluga',
			'post_status'    => array_merge( $kw_i_statusy, array( 'trash' ) ),
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	) as $kw_i_p ) {
		if ( ! in_array( (int) $kw_i_p, $bez, true ) ) {
			$stan[ $kw_i_p ] = array( get_post_status( $kw_i_p ), kw_uslugi_import_stan_ceny( $kw_i_p ) );
		}
	}
	return md5( (string) wp_json_encode( $stan ) );
};
$kw_i_suma_przed = $kw_i_suma( array() );

$kw_i_id = (int) wp_insert_post(
	array(
		'post_type'   => 'kw_usluga',
		'post_status' => 'draft',
		'post_title'  => 'Samotest import',
	)
);
$kw_i_progi = array(
	array(
		'etykieta' => 'T1-10',
		'cena'     => '501',
		'od'       => 0,
	),
	array(
		'etykieta' => 'T11-30',
		'cena'     => '951',
		'od'       => 0,
	),
	array(
		'etykieta' => 'T31 i więcej',
		'cena'     => '1401.5',
		'od'       => 1,
	),
);
update_post_meta( $kw_i_id, 'kw_cena_typ', 'od' );
update_post_meta( $kw_i_id, 'kw_cena_min', '501' );
update_post_meta( $kw_i_id, 'kw_cena_jednostka', 'miesiac' );
update_post_meta( $kw_i_id, 'kw_cena_netto', '1' );
update_post_meta( $kw_i_id, 'kw_progi', $kw_i_progi );

// Bramki testu: zapis i cofniecie tylko wtedy, gdy dotycza wylacznie uslug testowych.
$kw_i_nowe     = array();
$kw_i_zastosuj = static function ( array $a ) use ( $kw_i_id ) {
	foreach ( (array) ( $a['zmiany'] ?? array() ) as $kw_i_z ) {
		if ( (int) $kw_i_z['id'] !== $kw_i_id ) {
			return new WP_Error( 'kw_test', 'zmiana poza usluga testowa: ' . (int) $kw_i_z['id'] );
		}
	}
	return kw_uslugi_import_zastosuj( $a );
};
$kw_i_cofnij   = static function () use ( $kw_i_id, &$kw_i_nowe ) {
	$kopia = get_option( KW_USLUGI_IMPORT_KOPIA );
	if ( ! is_array( $kopia ) || array_diff( array_map( 'intval', array_keys( (array) ( $kopia['uslugi'] ?? array() ) ) ), array( $kw_i_id ) ) || array_diff( array_map( 'intval', (array) ( $kopia['nowe'] ?? array() ) ), $kw_i_nowe ) ) {
		return new WP_Error( 'kw_test', 'kopia importu nie jest kopia testu' );
	}
	return kw_uslugi_import_cofnij();
};

/* ---- eksport i powrot tego samego pliku ---- */
$kw_i_csv  = kw_uslugi_import_csv();
$kw_i_ile  = count(
	get_posts(
		array(
			'post_type'      => 'kw_usluga',
			'post_status'    => $kw_i_statusy,
			'posts_per_page' => 500,
			'fields'         => 'ids',
		)
	)
);
$kw_i_a    = kw_uslugi_import_analiza( $kw_i_csv );
$kw_i_wier = '';
foreach ( explode( "\r\n", $kw_i_csv ) as $kw_i_linia ) {
	if ( 0 === strpos( $kw_i_linia, $kw_i_id . ';' ) ) {
		$kw_i_wier = $kw_i_linia;
	}
}
$sprawdz( 'eksport cen: UTF-8 ze znacznikiem BOM, srednik, naglowki po polsku, jeden wiersz na usluge (bez kosza)', 0 === strncmp( $kw_i_csv, "\xEF\xBB\xBF", 3 ) && 0 === strpos( substr( $kw_i_csv, 3 ), "ID;Usługa;Kategoria;Stan;Sposób podania ceny;Kwota;Kwota do;Za co jest ta kwota;Netto czy brutto;Tekst zamiast kwoty;Próg 1;Próg 2;" ) && $kw_i_ile + 1 === substr_count( $kw_i_csv, "\r\n" ), substr( $kw_i_csv, 3, 160 ) . ' | wierszy ' . substr_count( $kw_i_csv, "\r\n" ) . ' uslug ' . $kw_i_ile );
$sprawdz( 'eksport: wiersz uslugi slowami (cena od, miesiąc, netto), kwoty z przecinkiem, prog jako zakres=cena i zakres=od cena', $kw_i_id . ';Samotest import;;szkic;cena od;501;;miesiąc;netto;;T1-10=501;T11-30=951;T31 i więcej=od 1401,5;;;;;;;;;' === $kw_i_wier, $kw_i_wier );
$sprawdz( 'eksport wgrany z powrotem bez zmian: 0 zmian, 0 nowych, 0 bledow, kazda usluga rozpoznana', '' === $kw_i_a['blad'] && array() === $kw_i_a['bledy'] && array() === $kw_i_a['zmiany'] && array() === $kw_i_a['nowe'] && $kw_i_ile === $kw_i_a['bez_zmian'] && $kw_i_ile === $kw_i_a['wierszy'], wp_json_encode( array( $kw_i_a['blad'], array_slice( $kw_i_a['bledy'], 0, 3 ), array_slice( $kw_i_a['zmiany'], 0, 2 ), count( $kw_i_a['nowe'] ), $kw_i_a['bez_zmian'], $kw_i_ile ) ) );
if ( function_exists( 'iconv' ) ) {
	$kw_i_1250 = kw_uslugi_import_analiza( (string) iconv( 'UTF-8', 'WINDOWS-1250//TRANSLIT', substr( $kw_i_csv, 3 ) ) );
	$sprawdz( 'ten sam plik zapisany przez polskiego Excela (Windows-1250, bez BOM): polskie znaki odczytane, 0 zmian', '' === $kw_i_1250['blad'] && array() === $kw_i_1250['bledy'] && array() === $kw_i_1250['zmiany'] && array() === $kw_i_1250['nowe'] && $kw_i_ile === $kw_i_1250['bez_zmian'], wp_json_encode( array( $kw_i_1250['blad'], array_slice( $kw_i_1250['bledy'], 0, 2 ), count( $kw_i_1250['zmiany'] ), count( $kw_i_1250['nowe'] ) ) ) );
}

/* ---- jedna zmieniona cena = dokladnie jedna roznica ---- */
$kw_i_zm = kw_uslugi_import_analiza( str_replace( 'T11-30=951', 'T11-30=975', $kw_i_csv ) );
$sprawdz( 'jeden zmieniony prog w pliku: dokladnie jedna usluga i jedno pole (progi), reszta bez zmian', array() === $kw_i_zm['bledy'] && 1 === count( $kw_i_zm['zmiany'] ) && $kw_i_id === $kw_i_zm['zmiany'][0]['id'] && array( 'kw_progi' ) === array_keys( $kw_i_zm['zmiany'][0]['pola'] ) && '951' === $kw_i_zm['zmiany'][0]['pola']['kw_progi']['bylo'][1]['cena'] && '975' === $kw_i_zm['zmiany'][0]['pola']['kw_progi']['bedzie'][1]['cena'] && $kw_i_ile - 1 === $kw_i_zm['bez_zmian'] && array() === $kw_i_zm['nowe'], wp_json_encode( array( $kw_i_zm['bledy'], $kw_i_zm['zmiany'] ) ) );
$kw_i_maly = "ID;Usługa;Kwota\n{$kw_i_id};Samotest import;550\n";
$kw_i_m    = kw_uslugi_import_analiza( $kw_i_maly );
$sprawdz( 'plik z samymi kolumnami ID, Usługa, Kwota: zmienia sie tylko kwota (501 => 550), pozostale pola zostaja', array() === $kw_i_m['bledy'] && 1 === count( $kw_i_m['zmiany'] ) && array( 'kw_cena_min' => array( 'bylo' => '501', 'bedzie' => '550' ) ) === $kw_i_m['zmiany'][0]['pola'] && 1 === $kw_i_m['wierszy'], wp_json_encode( $kw_i_m ) );
$kw_i_tab = kw_uslugi_import_analiza( "Usługa\tKwota\tNetto czy brutto\nsamotest  IMPORT\t1 200,50 zł\tbrutto\n" );
$sprawdz( 'tabela wklejona z arkusza (tabulatory), usluga rozpoznana po nazwie bez ID: kwota "1 200,50 zł" => 1200.5, netto => brutto', array() === $kw_i_tab['bledy'] && 1 === count( $kw_i_tab['zmiany'] ) && $kw_i_id === $kw_i_tab['zmiany'][0]['id'] && array( 'kw_cena_min', 'kw_cena_netto' ) === array_keys( $kw_i_tab['zmiany'][0]['pola'] ) && '1200.5' === $kw_i_tab['zmiany'][0]['pola']['kw_cena_min']['bedzie'] && '0' === $kw_i_tab['zmiany'][0]['pola']['kw_cena_netto']['bedzie'], wp_json_encode( $kw_i_tab ) );
$sprawdz( 'analiza niczego nie zapisuje (cena w bazie nadal 501, kopii importu brak)', '501' === get_post_meta( $kw_i_id, 'kw_cena_min', true ) && false === get_option( KW_USLUGI_IMPORT_KOPIA ) );

/* ---- zapis, kopia, cofniecie ---- */
$kw_i_w = $kw_i_zastosuj( $kw_i_zm );
$kw_i_k = get_option( KW_USLUGI_IMPORT_KOPIA );
$sprawdz( 'zapis zmian: nowa cena progu w bazie (975), pozostale progi i pola bez zmian', ! is_wp_error( $kw_i_w ) && array( 'zmienione' => 1, 'nowe' => 0 ) === $kw_i_w && '975' === ( get_post_meta( $kw_i_id, 'kw_progi', true )[1]['cena'] ?? '' ) && '501' === ( get_post_meta( $kw_i_id, 'kw_progi', true )[0]['cena'] ?? '' ) && '501' === get_post_meta( $kw_i_id, 'kw_cena_min', true ), wp_json_encode( is_wp_error( $kw_i_w ) ? $kw_i_w->get_error_message() : get_post_meta( $kw_i_id, 'kw_progi', true ) ) );
$sprawdz( 'zapis odklada poprzednia wartosc do kopii (data, kto, pole przed i po)', is_array( $kw_i_k ) && ! empty( $kw_i_k['czas'] ) && get_current_user_id() === (int) $kw_i_k['kto'] && '951' === ( $kw_i_k['uslugi'][ $kw_i_id ]['kw_progi']['bylo'][1]['cena'] ?? '' ) && '975' === ( $kw_i_k['uslugi'][ $kw_i_id ]['kw_progi']['jest'][1]['cena'] ?? '' ), wp_json_encode( $kw_i_k ) );
$kw_i_skrot = kw_uslugi_import_analiza( str_replace( 'T11-30=951', 'T11-30=975', $kw_i_csv ) );
$sprawdz( 'podglad nieaktualny po zmianie w bazie: skrot zmian jest inny, a ten sam plik nie ma juz czego zmieniac', $kw_i_skrot['skrot'] !== $kw_i_zm['skrot'] && array() === $kw_i_skrot['zmiany'] );
$kw_i_c = $kw_i_cofnij();
$sprawdz( 'cofniecie importu: progi wracaja do stanu sprzed importu, kopia znika', ! is_wp_error( $kw_i_c ) && 1 === $kw_i_c['przywrocone'] && 0 === $kw_i_c['pominiete'] && kw_uslugi_czysc_progi( $kw_i_progi ) === kw_uslugi_czysc_progi( get_post_meta( $kw_i_id, 'kw_progi', true ) ) && false === get_option( KW_USLUGI_IMPORT_KOPIA ), wp_json_encode( is_wp_error( $kw_i_c ) ? $kw_i_c->get_error_message() : $kw_i_c ) );
$sprawdz( 'drugie cofniecie: nie ma czego cofac', is_wp_error( kw_uslugi_import_cofnij() ) );
$kw_i_zastosuj( kw_uslugi_import_analiza( $kw_i_maly ) );
update_post_meta( $kw_i_id, 'kw_cena_min', '600' ); // reczna zmiana juz po imporcie
$kw_i_c2 = $kw_i_cofnij();
$sprawdz( 'cofniecie nie nadpisuje pola zmienionego recznie po imporcie (600 zostaje)', ! is_wp_error( $kw_i_c2 ) && 0 === $kw_i_c2['przywrocone'] && 1 === $kw_i_c2['pominiete'] && '600' === get_post_meta( $kw_i_id, 'kw_cena_min', true ), wp_json_encode( is_wp_error( $kw_i_c2 ) ? $kw_i_c2->get_error_message() : $kw_i_c2 ) );
update_post_meta( $kw_i_id, 'kw_cena_min', '501' );

/* ---- nowa usluga = szkic ---- */
$kw_i_n = kw_uslugi_import_analiza( "Usługa;Kategoria;Sposób podania ceny;Kwota;Próg 1;Próg 2\nSamotest import nowa;;;300;1-5=300;6 i więcej=od 450\n" );
$sprawdz( 'nazwa, ktorej nie ma w panelu: nowa usluga w podgladzie (sposob ceny wynika z danych), bez zmian w istniejacych', array() === $kw_i_n['bledy'] && array() === $kw_i_n['zmiany'] && 1 === count( $kw_i_n['nowe'] ) && 'Samotest import nowa' === $kw_i_n['nowe'][0]['tytul'] && 'stala' === $kw_i_n['nowe'][0]['pola']['kw_cena_typ'] && '300' === $kw_i_n['nowe'][0]['pola']['kw_cena_min'] && 2 === count( $kw_i_n['nowe'][0]['pola']['kw_progi'] ) && 1 === $kw_i_n['nowe'][0]['pola']['kw_progi'][1]['od'], wp_json_encode( $kw_i_n ) );
$kw_i_wn   = $kw_i_zastosuj( $kw_i_n );
$kw_i_kn   = get_option( KW_USLUGI_IMPORT_KOPIA );
$kw_i_nowe = array_map( 'intval', (array) ( $kw_i_kn['nowe'] ?? array() ) );
$kw_i_nid  = (int) ( $kw_i_nowe[0] ?? 0 );
$sprawdz( 'zapis nowej uslugi: szkic (nie na stronie) z cena, progami i znacznikiem importu', ! is_wp_error( $kw_i_wn ) && 1 === $kw_i_wn['nowe'] && $kw_i_nid > 0 && 'draft' === get_post_status( $kw_i_nid ) && 'kw_usluga' === get_post_type( $kw_i_nid ) && 'Samotest import nowa' === get_post_field( 'post_title', $kw_i_nid ) && '300' === get_post_meta( $kw_i_nid, 'kw_cena_min', true ) && 2 === count( (array) get_post_meta( $kw_i_nid, 'kw_progi', true ) ) && '' !== (string) get_post_meta( $kw_i_nid, '_kw_import', true ), wp_json_encode( is_wp_error( $kw_i_wn ) ? $kw_i_wn->get_error_message() : $kw_i_kn ) );
$kw_i_cn = $kw_i_cofnij();
$sprawdz( 'cofniecie importu z nowa usluga: jej szkic trafia do kosza (odwracalnie)', ! is_wp_error( $kw_i_cn ) && 1 === $kw_i_cn['kosz'] && 'trash' === get_post_status( $kw_i_nid ), wp_json_encode( is_wp_error( $kw_i_cn ) ? $kw_i_cn->get_error_message() : $kw_i_cn ) );

/* ---- odmowy: formuly, zle naglowki, rozmiar, zle wartosci ---- */
$kw_i_bledy = static function ( $plik ) {
	$a = kw_uslugi_import_analiza( $plik );
	return array( $a, implode( ' | ', array_merge( array( $a['blad'] ), wp_list_pluck( $a['bledy'], 'tekst' ) ) ) );
};
$kw_i_formuly = array();
foreach ( array( '=HYPERLINK("http://zly.example";"kliknij")', '@SUM(1+1)', '+48 500', '-1' ) as $kw_i_f ) {
	list( $kw_i_fa, $kw_i_ft ) = $kw_i_bledy( "ID;Usługa;Sposób podania ceny;Tekst zamiast kwoty\n{$kw_i_id};Samotest import;własny tekst;" . '"' . str_replace( '"', '""', $kw_i_f ) . '"' . "\n" );
	if ( 1 !== count( $kw_i_fa['bledy'] ) || false === strpos( $kw_i_ft, 'formułę arkusza' ) || $kw_i_fa['zmiany'] || ! is_wp_error( $kw_i_zastosuj( $kw_i_fa ) ) ) {
		$kw_i_formuly[] = $kw_i_f . ' => ' . $kw_i_ft;
	}
}
$sprawdz( 'komorka zaczynajaca sie od = + - @ (formula arkusza): blad w wierszu, zapis odmawia, w bazie nic sie nie zmienia', array() === $kw_i_formuly && 'od' === get_post_meta( $kw_i_id, 'kw_cena_typ', true ) && '' === (string) get_post_meta( $kw_i_id, 'kw_cena_opis', true ), wp_json_encode( $kw_i_formuly ) );
list( $kw_i_ap ) = $kw_i_bledy( "ID;Sposób podania ceny;Tekst zamiast kwoty\n{$kw_i_id};własny tekst;'=10% obrotu\n" );
$sprawdz( 'apostrof przed znakiem = znaczy "to jest tekst": przyjete jako zwykly tekst, bez apostrofu', array() === $kw_i_ap['bledy'] && '=10% obrotu' === ( $kw_i_ap['zmiany'][0]['pola']['kw_cena_opis']['bedzie'] ?? '' ), wp_json_encode( $kw_i_ap ) );
$sprawdz( 'eksport zabezpiecza komorki: tekst od = + - @ dostaje apostrof, srednik i cudzyslow sa ujete w cudzyslow', "'=1+1" === kw_uslugi_import_csv_komorka( '=1+1' ) && "'@x" === kw_uslugi_import_csv_komorka( '@x' ) && "'-5" === kw_uslugi_import_csv_komorka( '-5' ) && "'+5" === kw_uslugi_import_csv_komorka( '+5' ) && '"a;b"' === kw_uslugi_import_csv_komorka( 'a;b' ) && '"x ""y"""' === kw_uslugi_import_csv_komorka( 'x "y"' ) && '500' === kw_uslugi_import_csv_komorka( '500' ) );
update_post_meta( $kw_i_id, 'kw_cena_typ', 'opis' );
update_post_meta( $kw_i_id, 'kw_cena_opis', '=cmd|x; "y"' );
$kw_i_csv2 = kw_uslugi_import_csv();
$kw_i_a2   = kw_uslugi_import_analiza( $kw_i_csv2 );
$sprawdz( 'usluga z tekstem od znaku "=": w eksporcie z apostrofem i w cudzyslowie, a plik wgrany z powrotem nadal nie zmienia niczego', false !== strpos( $kw_i_csv2, ';"\'=cmd|x; ""y""";' ) && array() === $kw_i_a2['bledy'] && array() === $kw_i_a2['zmiany'] && $kw_i_ile === $kw_i_a2['bez_zmian'], wp_json_encode( array( array_slice( $kw_i_a2['bledy'], 0, 2 ), array_slice( $kw_i_a2['zmiany'], 0, 1 ) ) ) );
update_post_meta( $kw_i_id, 'kw_cena_typ', 'od' );
update_post_meta( $kw_i_id, 'kw_cena_opis', '' );

list( $kw_i_h1, $kw_i_h1t ) = $kw_i_bledy( "ID;Usługa;Hasło <img src=x onerror=1>\n{$kw_i_id};Samotest import;x\n" );
list( $kw_i_h2, $kw_i_h2t ) = $kw_i_bledy( "Kwota;Kwota do\n1;2\n" );
list( $kw_i_h3, $kw_i_h3t ) = $kw_i_bledy( "ID;Usługa\n{$kw_i_id};Samotest import\n" );
list( $kw_i_h4, $kw_i_h4t ) = $kw_i_bledy( "ID;Kwota;Cena\n{$kw_i_id};1;2\n" );
$sprawdz( 'zle naglowki odrzucaja caly plik: nieznana kolumna, brak kolumny Usługa / ID, brak kolumn z cena, kolumna dwa razy', false !== strpos( $kw_i_h1['blad'], 'Nie rozpoznaję kolumny' ) && false !== strpos( $kw_i_h2['blad'], 'brakuje kolumny' ) && false !== strpos( $kw_i_h3['blad'], 'żadnej kolumny z ceną' ) && false !== strpos( $kw_i_h4['blad'], 'dwa razy' ) && is_wp_error( kw_uslugi_import_zastosuj( $kw_i_h1 ) ), $kw_i_h1t . ' / ' . $kw_i_h2t . ' / ' . $kw_i_h3t . ' / ' . $kw_i_h4t );
$kw_i_html = kw_uslugi_import_podglad_html( $kw_i_h1 );
$sprawdz( 'podglad: tekst z pliku przechodzi przez esc_html (znacznik z naglowka nieaktywny)', false === strpos( $kw_i_html, '<img' ) && false !== strpos( $kw_i_html, '&lt;img src=x onerror=1&gt;' ), $kw_i_html );
list( $kw_i_d1 )            = $kw_i_bledy( str_repeat( 'a', KW_USLUGI_IMPORT_MAX + 1 ) );
list( $kw_i_d2 )            = $kw_i_bledy( "ID;Kwota\n" . str_repeat( "1;1\n", KW_USLUGI_IMPORT_WIERSZE + 1 ) );
list( $kw_i_d3 )            = $kw_i_bledy( " \n\n" );
list( $kw_i_d4 )            = $kw_i_bledy( array( 'x' ) );
$sprawdz( 'za duzy plik, za duzo wierszy, pusty plik i nie-tekst: odmowa z komunikatem', false !== strpos( $kw_i_d1['blad'], 'za duży' ) && false !== strpos( $kw_i_d2['blad'], 'za dużo wierszy' ) && false !== strpos( $kw_i_d3['blad'], 'pusty' ) && '' !== $kw_i_d4['blad'], wp_json_encode( array( $kw_i_d1['blad'], $kw_i_d2['blad'], $kw_i_d3['blad'], $kw_i_d4['blad'] ) ) );

$kw_i_zle = array(
	'kwota nie jest liczba'           => array( "ID;Kwota\n{$kw_i_id};abc\n", 'nie jest liczbą' ),
	'nieznany sposob ceny'            => array( "ID;Sposób podania ceny\n{$kw_i_id};ryczałtem\n", 'Nie znam sposobu' ),
	'przedzial z kwota do nie wieksza' => array( "ID;Sposób podania ceny;Kwota;Kwota do\n{$kw_i_id};przedział;60;30\n", 'musi być większa' ),
	'wlasny tekst bez tekstu'         => array( "ID;Sposób podania ceny;Tekst zamiast kwoty\n{$kw_i_id};własny tekst;\n", 'wpisz go w kolumnie' ),
	'prog bez ceny'                   => array( "ID;Próg 1\n{$kw_i_id};1-10\n", 'zły zapis' ),
	'prog z cena tekstem'             => array( "ID;Próg 1\n{$kw_i_id};1-10=duzo\n", 'zły zapis' ),
	'netto inaczej'                   => array( "ID;Netto czy brutto\n{$kw_i_id};z VAT\n", 'netto albo brutto' ),
	'nieznane ID'                     => array( "ID;Kwota\n999999999;5\n", 'Nie ma usługi o ID' ),
	'wiersz bez nazwy i ID'           => array( "ID;Usługa;Kwota\n;;5\n", 'nie ma nazwy usługi ani ID' ),
	'ta sama usluga dwa razy'         => array( "ID;Kwota\n{$kw_i_id};5\n{$kw_i_id};6\n", 'jest już w wierszu 2' ),
	'nieznana kategoria nowej uslugi' => array( "Usługa;Kategoria;Kwota\nSamotest import inna;Nie ma takiej kategorii;5\n", 'Nie ma kategorii' ),
	'za dlugi tekst zamiast kwoty'    => array( "ID;Sposób podania ceny;Tekst zamiast kwoty\n{$kw_i_id};własny tekst;" . str_repeat( 'a', 201 ) . "\n", 'za długi' ),
);
$kw_i_nie = array();
foreach ( $kw_i_zle as $kw_i_co => $kw_i_para ) {
	list( $kw_i_za, $kw_i_zt ) = $kw_i_bledy( $kw_i_para[0] );
	if ( '' !== $kw_i_za['blad'] || ! $kw_i_za['bledy'] || false === strpos( $kw_i_zt, $kw_i_para[1] ) || ! is_wp_error( kw_uslugi_import_zastosuj( $kw_i_za ) ) ) {
		$kw_i_nie[] = $kw_i_co . ' => ' . $kw_i_zt;
	}
}
$sprawdz( 'zle wartosci w wierszu (12 przypadkow): komunikat po polsku przy wierszu, a blad blokuje zapis calego pliku', array() === $kw_i_nie, wp_json_encode( $kw_i_nie ) );
list( $kw_i_mix ) = $kw_i_bledy( "ID;Kwota\n{$kw_i_id};777\n999999999;5\n" );
$sprawdz( 'plik z jednym dobrym i jednym zlym wierszem: zapis odmawia w calosci (cena w bazie bez zmian)', 1 === count( $kw_i_mix['zmiany'] ) && 1 === count( $kw_i_mix['bledy'] ) && is_wp_error( $kw_i_zastosuj( $kw_i_mix ) ) && '501' === get_post_meta( $kw_i_id, 'kw_cena_min', true ) );

/* ---- uprawnienia i ekran ---- */
ob_start();
kw_uslugi_import_sekcja();
$kw_i_sekcja = (string) ob_get_clean();
$sprawdz( 'sekcja w Ustawieniach: pobranie cennika z tokenem, pole pliku, pole do wklejenia, token formularza, zdanie "niczego nie zapisuje"', false !== strpos( $kw_i_sekcja, 'action=kw_uslugi_eksport_cen' ) && false !== strpos( $kw_i_sekcja, '_wpnonce=' ) && false !== strpos( $kw_i_sekcja, 'name="kw_import_plik"' ) && false !== strpos( $kw_i_sekcja, 'name="kw_import_tekst"' ) && false !== strpos( $kw_i_sekcja, 'name="kw_import_nonce"' ) && false !== strpos( $kw_i_sekcja, 'Ten krok niczego nie zapisuje.' ) && false === strpos( $kw_i_sekcja, 'Cofnij ostatni import' ), substr( $kw_i_sekcja, 0, 300 ) );
$kw_i_pod = kw_uslugi_import_podglad_html( $kw_i_m );
$sprawdz( 'podglad zmian: tabela "Było / Będzie" z nazwa uslugi i polem, przycisk "Zastosuj zmiany (1)" z tokenem i skrotem podgladu', false !== strpos( $kw_i_pod, '<th scope="col">Było</th><th scope="col">Będzie</th>' ) && false !== strpos( $kw_i_pod, '>Samotest import</a>' ) && false !== strpos( $kw_i_pod, '<del>501 zł</del>' ) && false !== strpos( $kw_i_pod, '<strong>550 zł</strong>' ) && false !== strpos( $kw_i_pod, 'Zastosuj zmiany (1)' ) && false !== strpos( $kw_i_pod, 'name="kw_import_skrot" value="' . $kw_i_m['skrot'] . '"' ) && false !== strpos( $kw_i_pod, 'name="kw_import_nonce"' ), $kw_i_pod );
$sprawdz( 'podglad z bledami nie ma przycisku zapisu', false === strpos( kw_uslugi_import_podglad_html( $kw_i_mix ), 'Zastosuj zmiany' ) && false !== strpos( kw_uslugi_import_podglad_html( $kw_i_mix ), 'Nic nie zostanie zapisane' ) );
wp_set_current_user( 0 );
$kw_i_gosc = kw_uslugi_import_analiza( $kw_i_maly );
ob_start();
kw_uslugi_import_sekcja();
$kw_i_sekcja_gosc = (string) ob_get_clean();
$sprawdz( 'bez uprawnien: analiza oznacza wiersz bledem, zapis i cofniecie odmawiaja, sekcja sie nie drukuje, cena bez zmian', 1 === count( $kw_i_gosc['bledy'] ) && false !== strpos( $kw_i_gosc['bledy'][0]['tekst'], 'uprawnień' ) && is_wp_error( kw_uslugi_import_zastosuj( $kw_i_m ) ) && is_wp_error( kw_uslugi_import_cofnij() ) && '' === $kw_i_sekcja_gosc && '501' === get_post_meta( $kw_i_id, 'kw_cena_min', true ), wp_json_encode( $kw_i_gosc['bledy'] ) );
wp_set_current_user( isset( $admin ) ? (int) $admin : 1 );

/* ---- sprzatanie ---- */
wp_delete_post( $kw_i_id, true ); // wlasny szkic testowy
if ( $kw_i_nid && 'Samotest import nowa' === get_post_field( 'post_title', $kw_i_nid ) ) {
	wp_delete_post( $kw_i_nid, true ); // wlasny szkic utworzony przez import testowy
}
if ( false === $kw_i_kopia_przed ) {
	delete_option( KW_USLUGI_IMPORT_KOPIA );
} else {
	update_option( KW_USLUGI_IMPORT_KOPIA, $kw_i_kopia_przed, false );
}
$sprawdz( 'import: szkice testowe usuniete, kopia prawdziwego importu na miejscu, ceny prawdziwych uslug nietkniete (suma kontrolna)', null === get_post( $kw_i_id ) && ( ! $kw_i_nid || null === get_post( $kw_i_nid ) ) && $kw_i_kopia_przed === get_option( KW_USLUGI_IMPORT_KOPIA ) && $kw_i_suma_przed === $kw_i_suma( array() ) );
wp_set_current_user( 0 );
