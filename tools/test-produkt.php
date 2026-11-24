<?php
/**
 * Test modułu "Edytor produktu" (docs/edytor-produktu.md): ścieżki opcji, wiersze, karty, zapis przez WC_Product
 * ("zapisane == wysłane"), nietknięty edytor nie zmienia treści, bramka wersji, cena promocyjna, dane podglądu.
 *
 * Uruchamiany wewnątrz WordPressa z WooCommerce, na KOPII produktu (szkic), którą sam tworzy i przenosi do kosza:
 *   CPE_TEST_SRC=<ID produktu> wp eval-file tools/test-produkt.php --user=<administrator>
 * Gdy wtyczka na stronie nie ma jeszcze modułu, wskaż plik: CPE_PLIK=/sciezka/colorify-produkt.php
 * Kod wyjścia 0 tylko gdy wszystko przeszło.
 *
 * @package ColorifyByInyfinn
 */

if ( ! function_exists( 'colorify_produkt_karty' ) && getenv( 'CPE_PLIK' ) ) {
	require_once getenv( 'CPE_PLIK' );
}
if ( ! function_exists( 'colorify_produkt_karty' ) || ! function_exists( 'wc_get_product' ) ) {
	fwrite( STDERR, "Uruchom wewnatrz WordPressa z WooCommerce i modulem Edytor produktu (wp eval-file).\n" );
	exit( 2 );
}

$fail = array();
$ok   = static function ( $cond, $msg ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail[] = $msg;
	}
};

// Ścieżki w opcji strony.
$a = colorify_produkt_sciezka_ustaw( array(), array( 'lab', 'bullets' ), array( 'a', 'b' ) );
$ok( array( 'lab' => array( 'bullets' => array( 'a', 'b' ) ) ) === $a, 'sciezka: ustaw' );
$ok( "a\nb" === colorify_produkt_sciezka_czytaj( $a, array( 'lab', 'bullets' ) ), 'sciezka: lista jako wiersze' );
$ok( array() === colorify_produkt_sciezka_ustaw( $a, array( 'lab', 'bullets' ), array() ), 'sciezka: pusta wartosc usuwa klucz i puste galezie' );
$b = colorify_produkt_sciezka_ustaw( array(), array( '_all', 'pasek', 1, 'title' ), '', true );
$ok( isset( $b['_all']['pasek'][1]['title'] ) && '' === $b['_all']['pasek'][1]['title'], 'sciezka: zostaw_puste trzyma pozycje' );
$ok( '' === colorify_produkt_sciezka_czytaj( $a, array( 'brak', 'x' ) ), 'sciezka: brak = pusty tekst' );
$ok( array( 'a', 'b' ) === colorify_produkt_linie( " a \r\n\r\n b " ), 'linie: bez pustych, przyciete' );

// Teksty wspólne: tablica wierszy, pusty tekst usuwa klucz, tekst z kodu nie jest kopiowany do opcji.
$w = array(
	'opcja' => 'cpe_test_nie_istnieje',
	'pola'  => array(
		'bullets' => colorify_produkt_pole_norm( 'bullets', array( 'typ' => 'linie' ) ) + array( 'sciezka' => array( 'v', 'bullets' ), 'tablica' => true, 'zostaw_puste' => false ),
		'html'    => colorify_produkt_pole_norm( 'html', array( 'typ' => 'html', 'domyslne' => "<ul>\n<li>A</li>\n</ul>", 'kses' => array( 'ul' => array(), 'li' => array() ) ) ) + array( 'sciezka' => array( '_all', 'html' ), 'tablica' => false, 'zostaw_puste' => false ),
	),
);
$o = colorify_produkt_wspolne_scal( $w, array( 'bullets' => "x\n\ny", 'html' => '<ul><li>A</li></ul>' ), array() );
$ok( array( 'v' => array( 'bullets' => array( 'x', 'y' ) ) ) === $o, 'wspolne: wiersze jako tablica, tekst z kodu bez kopii: ' . wp_json_encode( $o ) );
$ok( array() === colorify_produkt_wspolne_scal( $w, array( 'bullets' => '' ), $o ), 'wspolne: puste = usun klucz' );

$src = (int) getenv( 'CPE_TEST_SRC' );
$p0  = $src ? wc_get_product( $src ) : null;
if ( ! $p0 ) {
	echo "Bez CPE_TEST_SRC: tylko testy bez produktu.\n";
} else {
	include_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
	$dup = ( new WC_Admin_Duplicate_Product() )->product_duplicate( $p0 );
	$id  = (int) $dup->get_id();
	echo 'Kopia testowa: ' . $id . ' (' . $dup->get_status() . ")\n";
	try {
		$ok( 'draft' === $dup->get_status(), 'kopia jest szkicem' );
		$karty = colorify_produkt_karty( $id );
		echo 'Karty: ' . implode( ', ', array_keys( $karty ) ) . "\n";
		$p     = wc_get_product( $id );
		$stare = colorify_produkt_wartosci( $p, $karty );
		$wer   = colorify_produkt_wersja( $stare );
		$form  = array(
			'nazwa'      => $stare['nazwa'],
			'zdjecie'    => (string) $stare['zdjecie'],
			'galeria'    => implode( ',', $stare['galeria'] ),
			'cena'       => wc_format_localized_price( $stare['cena'] ),
			'cena_promo' => '',
			'krotki'     => 'tekst z edytora, ktorego nikt nie ruszal',
			'opis'       => '<p>tresc przepisana przez edytor, ktorego nikt nie ruszal</p>',
			'kategorie'  => array_merge( array( '0' ), array_map( 'strval', $stare['kategorie'] ) ),
			'tagi'       => array_merge( array( '0' ), array_map( 'strval', $stare['tagi'] ) ),
			'tagi_nowe'  => '',
			'opinie'     => '' !== $stare['opinie'] ? '1' : '0',
		);
		foreach ( colorify_produkt_pola( $karty ) as $klucz => $f ) {
			if ( 'meta' === $f['zrodlo'] ) {
				$form[ $klucz ] = 'bool' === $f['typ'] ? ( '' !== $stare[ $klucz ] ? '1' : '0' ) : $stare[ $klucz ];
			}
		}
		// 1. Formularz bez zmian, edytory WP nietknięte: nic się nie zapisuje, treść zostaje bajt w bajt.
		$nowe = colorify_produkt_z_formularza( $p, $karty, $form, array() );
		$ok( $nowe === $stare, 'formularz bez zmian = wartosci z bazy' );
		$ok( array() === colorify_produkt_zapisz( $id, $nowe, $wer ), 'bez zmian: nic nie zapisano' );
		$ok( get_post( $id )->post_content === $p->get_description( 'edit' ), 'nietkniety edytor nie zmienil opisu' );

		// 2. Zmiana nazwy, ceny, opisu i tekstu pod ceną: zapisane == wysłane.
		$form['nazwa']  = $stare['nazwa'] . ' TEST';
		$form['cena']   = '61,50';
		$form['opis']   = "<h2>Naglowek testowy</h2>\n\nAkapit <strong>pogrubiony</strong> z <a href=\"https://example.com/\">odnosnikiem</a>.\n\n<ul>\n \t<li>punkt 1</li>\n \t<li>punkt 2</li>\n</ul>";
		$form['krotki'] = 'Krotki tekst testowy pod cena.';
		$nowe           = colorify_produkt_z_formularza( $p, $karty, $form, array( 'opis' => '1', 'krotki' => '1' ) );
		$zm             = colorify_produkt_zapisz( $id, $nowe, $wer );
		echo 'Zmienione: ' . wp_json_encode( $zm ) . "\n";
		sort( $zm );
		$ok( array( 'cena', 'krotki', 'nazwa', 'opis' ) === $zm, 'zapis: dokladnie zmienione pola' );
		clean_post_cache( $id );
		$post = get_post( $id );
		$ok( $post->post_title === $form['nazwa'], 'nazwa: zapisane == wyslane' );
		$ok( $post->post_content === $form['opis'], 'opis: zapisane == wyslane; w bazie: ' . wp_json_encode( $post->post_content ) );
		$ok( $post->post_excerpt === $form['krotki'], 'tekst pod cena: zapisane == wyslane; w bazie: ' . wp_json_encode( $post->post_excerpt ) );
		$p2 = wc_get_product( $id );
		$ok( '61.50' === (string) $p2->get_regular_price( 'edit' ) && '61.50' === (string) $p2->get_price( 'edit' ), 'cena i _price: ' . $p2->get_regular_price( 'edit' ) . ' / ' . $p2->get_price( 'edit' ) );
		$ok( 'draft' === $post->post_status, 'status bez zmian' );

		// 3. Bramka: zapis na starej wersji jest zatrzymany.
		$r = colorify_produkt_zapisz( $id, $nowe, $wer );
		$ok( is_wp_error( $r ) && 'konflikt' === $r->get_error_code(), 'bramka wersji zatrzymuje stary formularz' );

		// 4. Cena promocyjna nie niższa od zwykłej: błąd, nic nie zapisano.
		$teraz              = colorify_produkt_wartosci( $p2, $karty );
		$zla                = $teraz;
		$zla['cena_promo']  = '70';
		$r                  = colorify_produkt_zapisz( $id, $zla, colorify_produkt_wersja( $teraz ) );
		$ok( is_wp_error( $r ) && 'cena' === $r->get_error_code(), 'cena promocyjna >= zwykla: blad' );
		$ok( '' === (string) wc_get_product( $id )->get_sale_price( 'edit' ), 'po bledzie ceny nic nie zapisano' );

		// 5. Meta strony (jeśli strona je rejestruje): pusty tekst usuwa meta.
		foreach ( colorify_produkt_pola( $karty ) as $klucz => $f ) {
			if ( 'meta' !== $f['zrodlo'] || 'linie' !== $f['typ'] ) {
				continue;
			}
			$m           = $teraz;
			$m[ $klucz ] = "punkt A\npunkt B";
			colorify_produkt_zapisz( $id, $m, colorify_produkt_wersja( $teraz ) );
			$ok( "punkt A\npunkt B" === get_post_meta( $id, $klucz, true ), 'meta ' . $klucz . ': zapisane == wyslane' );
			$teraz       = colorify_produkt_wartosci( wc_get_product( $id ), $karty );
			$m           = $teraz;
			$m[ $klucz ] = '';
			colorify_produkt_zapisz( $id, $m, colorify_produkt_wersja( $teraz ) );
			$ok( ! metadata_exists( 'post', $id, $klucz ), 'meta ' . $klucz . ': puste = usuniete' );
			$teraz = colorify_produkt_wartosci( wc_get_product( $id ), $karty );
			break;
		}

		// 6. Dane podglądu: wartości i znaczniki elementów.
		$d = colorify_produkt_podglad_zbuduj( $karty, $teraz, array() );
		$ok( $d['wc']['nazwa'] === $form['nazwa'] && isset( $d['znaczniki']['nazwa'], $d['znaczniki']['karta:opis'] ), 'podglad: wartosci i znaczniki' );
		echo 'Znaczniki: ' . wp_json_encode( $d['znaczniki'] ) . "\n";
	} finally {
		wp_trash_post( $id );
		echo 'Kopia ' . $id . ' po tescie: ' . get_post_status( $id ) . "\n";
	}
}

if ( $fail ) {
	fwrite( STDERR, "NIEPOWODZENIA (" . count( $fail ) . "):\n - " . implode( "\n - ", $fail ) . "\n" );
	exit( 1 );
}
echo "OK: test Edytora produktu\n";
