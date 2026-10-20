<?php
/**
 * Samotest wtyczki: model ceny, cennik, zapis kreatora, formularz (wszystkie galezie punktu kw/v1/kontakt).
 * Bez HTTP, bez prawdziwej poczty i bez publikowania czegokolwiek: usluga testowa jest szkicem dopisywanym do cennika filtrem.
 *
 * Uruchomienie na dzialajacej wtyczce:  wp eval-file wp-content/plugins/kw-uslugi/tests/samotest.php
 * Kopia przed wdrozeniem (wtyczka pominieta, test laduje pliki z katalogu, w ktorym lezy):
 *                                       wp eval-file <kopia>/tests/samotest.php --skip-plugins=kw-uslugi
 * Poczta jest przechwytywana filtrem pre_wp_mail, wpisy testowe i liczniki sa po sobie sprzatane.
 *
 * @package kw-uslugi
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
if ( ! function_exists( 'kw_uslugi_pobierz' ) ) {
	require dirname( __DIR__ ) . '/kw-uslugi.php';
	kw_uslugi_rejestruj();
	echo 'TRYB: kopia z katalogu ' . dirname( __DIR__ ) . " (wtyczka pominieta)\n";
}
echo 'wersja: ' . KW_USLUGI_WERSJA . "\n";

$kw_wynik  = array(
	'ok'  => 0,
	'zle' => 0,
);
$kw_poczta = array();
$kw_dziala = true; // false = udajemy awarie poczty
$kw_wpisy  = array();
$nb        = "\u{00A0}";
$kw_bib_na_start = get_option( 'kw_uslugi_biblioteka' ); // biblioteka sprzed testu: kreator moze ja zasiac, test ja przywraca

$sprawdz = static function ( $nazwa, $warunek, $szczegol = '' ) use ( &$kw_wynik ) {
	++$kw_wynik[ $warunek ? 'ok' : 'zle' ];
	echo ( $warunek ? 'OK   ' : 'BLAD ' ) . $nazwa . ( $warunek || '' === $szczegol ? '' : ' => ' . $szczegol ) . "\n";
};

add_filter(
	'pre_wp_mail',
	static function ( $wynik, $atrybuty ) use ( &$kw_poczta, &$kw_dziala ) {
		if ( $kw_dziala ) {
			$kw_poczta[] = $atrybuty;
			return true;
		}
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'Symulowana awaria poczty' ) );
		return false;
	},
	10,
	2
);
add_action(
	'wp_insert_post',
	static function ( $id, $post ) use ( &$kw_wpisy ) {
		if ( 'kw_wiadomosc' === $post->post_type ) {
			$kw_wpisy[ $id ] = true;
		}
	},
	10,
	2
);

/* ===========================================================================
 * 1. Model ceny i sanityzacja
 * ======================================================================== */

// Te sprawdzenia stoja na poczatku celowo: pliki panelu (includes/admin*.php) nie sa jeszcze dolaczone, czyli stan jak przy zadaniu REST.
echo "--- poza panelem: blokada usuwania kategorii ---\n";
$kw_bez_panelu = ! function_exists( 'kw_uslugi_kreator' ) && ! function_exists( 'kw_uslugi_porzadek_ekranu' );
$kw_admin_id   = (int) ( get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
)[0] ?? 1 );
// Wlasna kategoria testowa: gdyby blokada kiedys przestala dzialac, test skasuje tylko ja, nie prawdziwa zakladke cennika.
$kw_kat_test = wp_insert_term( 'Samotest blokada usuwania', 'kw_grupa', array( 'slug' => 'kw-samotest-blokada' ) );
$kw_kat_id   = is_wp_error( $kw_kat_test ) ? 0 : (int) $kw_kat_test['term_id'];
if ( $kw_bez_panelu && $kw_kat_id > 0 ) {
	$sprawdz( 'bez plikow panelu: administrator nie moze trwale usunac kategorii uslug (delete_term)', function_exists( 'kw_uslugi_kat_bez_usuwania' ) && ! user_can( $kw_admin_id, 'delete_term', $kw_kat_id ), wp_json_encode( array( $kw_kat_id, function_exists( 'kw_uslugi_kat_bez_usuwania' ) ) ) );
	$kw_tag_test = wp_insert_term( 'kw-samotest-tag', 'post_tag' );
	if ( ! is_wp_error( $kw_tag_test ) ) {
		$sprawdz( 'bez plikow panelu: blokada dotyczy tylko kategorii uslug (zwykly tag administrator nadal moze usunac)', user_can( $kw_admin_id, 'delete_term', (int) $kw_tag_test['term_id'] ) );
		wp_delete_term( (int) $kw_tag_test['term_id'], 'post_tag' ); // wlasny tag testowy
	}
	wp_set_current_user( $kw_admin_id );
	$kw_usun = new WP_REST_Request( 'DELETE', '/wp/v2/kw_grupa/' . $kw_kat_id );
	$kw_usun->set_param( 'force', true );
	$kw_odp = rest_do_request( $kw_usun );
	wp_set_current_user( 0 );
	$sprawdz( 'bez plikow panelu: DELETE /wp/v2/kw_grupa/<id>?force=true jako administrator => odmowa, kategoria zostaje', in_array( $kw_odp->get_status(), array( 401, 403 ), true ) && get_term( $kw_kat_id, 'kw_grupa' ) instanceof WP_Term, (string) $kw_odp->get_status() );
} else {
	echo "POMINIETO: test bez plikow panelu (panel jest juz zaladowany albo nie udalo sie utworzyc kategorii testowej)\n";
}
if ( $kw_kat_id > 0 && get_term( $kw_kat_id, 'kw_grupa' ) instanceof WP_Term ) {
	wp_delete_term( $kw_kat_id, 'kw_grupa' ); // wlasna kategoria testowa (usuniecie wprost, bez sprawdzania uprawnien)
}
kw_uslugi_kategorie( true );

echo "--- kwoty, progi, listy ---\n";
$sprawdz( 'kwota "1 400,50 zł" => 1400.5; "500.00" => 500; "49,9" => 49.9', '1400.5' === kw_uslugi_czysc_kwote( '1 400,50 zł' ) && '500' === kw_uslugi_czysc_kwote( '500.00' ) && '49.9' === kw_uslugi_czysc_kwote( '49,9' ), wp_json_encode( array( kw_uslugi_czysc_kwote( '1 400,50 zł' ), kw_uslugi_czysc_kwote( '500.00' ), kw_uslugi_czysc_kwote( '49,9' ) ) ) );
$sprawdz( 'kwota niepoprawna => pusta ("abc", "-5", "1e3", znacznik, tablica)', array( '', '', '', '', '' ) === array_map( 'kw_uslugi_czysc_kwote', array( 'abc', '-5', '1e3', '<script>1</script>', array( 5 ) ) ) );
$sprawdz( 'kwota do wyswietlenia: 1400 bez spacji, 12 500 z twarda spacja, grosze po przecinku', '1400' === kw_uslugi_kwota( '1400' ) && "12{$nb}500" === kw_uslugi_kwota( '12500' ) && '49,50' === kw_uslugi_kwota( '49.5' ), wp_json_encode( array( kw_uslugi_kwota( '1400' ), kw_uslugi_kwota( '12500' ), kw_uslugi_kwota( '49.5' ) ) ) );

$progi = kw_uslugi_czysc_progi(
	array(
		array(
			'etykieta' => '1-10',
			'cena'     => '500 zł',
		),
		array(
			'etykieta' => '',
			'cena'     => '5',
		),
		array(
			'etykieta' => 'bez ceny',
			'cena'     => 'abc',
		),
		'smiec',
		array(
			'etykieta' => '<b>201</b> i więcej',
			'cena'     => 3000,
			'od'       => '1',
		),
	)
);
$sprawdz(
	'progi: wiersz bez etykiety, bez ceny i smieci odpadaja; znaczniki z etykiety znikaja',
	array(
		array(
			'etykieta' => '1-10',
			'cena'     => '500',
			'od'       => 0,
		),
		array(
			'etykieta' => '201 i więcej',
			'cena'     => '3000',
			'od'       => 1,
		),
	) === $progi,
	wp_json_encode( $progi )
);
$sprawdz( 'progi z zapisu JSON (WP-CLI) => ta sama tablica; smieci => pusta', $progi === kw_uslugi_czysc_progi( wp_json_encode( $progi ) ) && array() === kw_uslugi_czysc_progi( 'nie json' ) && array() === kw_uslugi_czysc_progi( 7 ) );
$sprawdz( 'progi: najwyzej 12 wierszy', 12 === count( kw_uslugi_czysc_progi( array_fill( 0, 30, $progi[0] ) ) ) );

$lista = kw_uslugi_czysc_liste( " pierwsza \r\n\r\n<script>alert(1)</script>druga <b>x</b>\n" . str_repeat( "wiersz\n", 30 ) );
$sprawdz( 'lista: jedna pozycja w wierszu, puste wiersze i znaczniki odpadaja, najwyzej 20 pozycji', 0 === strpos( $lista, "pierwsza\ndruga x\nwiersz" ) && 20 === count( explode( "\n", $lista ) ) && false === strpos( $lista, '<' ), wp_json_encode( $lista ) );

echo "--- cena na stronie ---\n";
$wzor = array(
	'cena_typ'  => 'od',
	'cena_min'  => '500',
	'cena_max'  => '',
	'cena_opis' => '',
	'jednostka' => 'miesiac',
	'netto'     => true,
	'progi'     => array(),
);
$cena = static function ( array $zmiany ) use ( $wzor ) {
	return kw_uslugi_cena( array_merge( $wzor, $zmiany ) )['tekst'];
};
$sprawdz( 'od: "od 500 zł netto / mies."', "od 500{$nb}zł netto / mies." === $cena( array() ), $cena( array() ) );
$t = $cena(
	array(
		'cena_typ'  => 'stala',
		'cena_min'  => '50',
		'jednostka' => 'pracownik-miesiac',
	)
);
$sprawdz( 'stala: "50 zł netto / pracownik / mies."', "50{$nb}zł netto / pracownik / mies." === $t, $t );
$t = $cena(
	array(
		'cena_typ'  => 'przedzial',
		'cena_min'  => '30',
		'cena_max'  => '60',
		'jednostka' => 'deklaracja',
	)
);
$sprawdz( 'przedzial: "30-60 zł netto / deklaracja" (lacznik, nie polpauza)', "30-60{$nb}zł netto / deklaracja" === $t, $t );
$t = $cena(
	array(
		'cena_typ'  => 'przedzial',
		'cena_min'  => '60',
		'cena_max'  => '30',
		'jednostka' => '',
	)
);
$sprawdz( 'przedzial z gorna granica nizsza od dolnej => sama kwota, bez jednostki', "60{$nb}zł netto" === $t, $t );
$t = $cena(
	array(
		'cena_typ'  => 'stala',
		'cena_min'  => '12500.5',
		'jednostka' => 'wniosek',
		'netto'     => false,
	)
);
$sprawdz( 'brutto, jednostka wlasna, grosze: "12 500,50 zł brutto / wniosek"', "12{$nb}500,50{$nb}zł brutto / wniosek" === $t, $t );
$sprawdz( 'indywidualna => "Wycena indywidualna"; typ liczbowy bez kwoty i bez progow tez', 'Wycena indywidualna' === $cena( array( 'cena_typ' => 'indywidualna' ) ) && 'Wycena indywidualna' === $cena( array( 'cena_min' => '' ) ) );
$sprawdz(
	'opis => wlasny tekst; opis pusty => "Wycena indywidualna"',
	'Według umowy' === $cena(
		array(
			'cena_typ'  => 'opis',
			'cena_opis' => 'Według umowy',
		)
	) && 'Wycena indywidualna' === $cena( array( 'cena_typ' => 'opis' ) )
);
$t = $cena(
	array(
		'cena_min' => '',
		'progi'    => array(
			array(
				'etykieta' => '11-20',
				'cena'     => '330',
				'od'       => 0,
			),
			array(
				'etykieta' => '1-10',
				'cena'     => '270',
				'od'       => 0,
			),
		),
	)
);
$sprawdz( 'kwota pusta + progi => najnizszy prog jako cena "od"', "od 270{$nb}zł netto / mies." === $t, $t );

echo "--- migracja starego pola kw_cena_od ---\n";
$m = array_map(
	'kw_uslugi_cena_z_tekstu',
	array(
		'od'    => 'od 270 zł netto',
		'stala' => '50 zł netto',
		'przed' => '30-60 zł',
		'br'    => '1 200,50 zł brutto',
		'opis'  => 'wg umowy',
		'pusta' => '',
	)
);
$sprawdz( '"od 270 zł netto" => od / 270 / netto', array( 'od', '270', '1' ) === array( $m['od']['kw_cena_typ'], $m['od']['kw_cena_min'], $m['od']['kw_cena_netto'] ), wp_json_encode( $m['od'] ) );
$sprawdz( '"50 zł netto" => stala / 50', array( 'stala', '50' ) === array( $m['stala']['kw_cena_typ'], $m['stala']['kw_cena_min'] ), wp_json_encode( $m['stala'] ) );
$sprawdz( '"30-60 zł" => przedzial 30..60', array( 'przedzial', '30', '60' ) === array( $m['przed']['kw_cena_typ'], $m['przed']['kw_cena_min'], $m['przed']['kw_cena_max'] ), wp_json_encode( $m['przed'] ) );
$sprawdz( '"1 200,50 zł brutto" => stala / 1200.5 / brutto', array( 'stala', '1200.5', '0' ) === array( $m['br']['kw_cena_typ'], $m['br']['kw_cena_min'], $m['br']['kw_cena_netto'] ), wp_json_encode( $m['br'] ) );
$sprawdz( 'aktualizacja wlacza ceny tylko wtedy, gdy ustawienia "Pokazuj ceny" nikt jeszcze nie zapisal (zniknieta opcja wersji nie odsloni wylaczonych cen)', true === kw_uslugi_migracja_wlacza_ceny( false ) && true === kw_uslugi_migracja_wlacza_ceny( array( 'email' => 'biuro@example.com' ) ) && false === kw_uslugi_migracja_wlacza_ceny( array( 'ceny' => 0 ) ) && false === kw_uslugi_migracja_wlacza_ceny( array( 'ceny' => 1 ) ) );
$sprawdz( 'tekst bez kwoty => opis; puste => indywidualna', array( 'kw_cena_typ' => 'opis', 'kw_cena_opis' => 'wg umowy' ) === $m['opis'] && array( 'kw_cena_typ' => 'indywidualna' ) === $m['pusta'], wp_json_encode( array( $m['opis'], $m['pusta'] ) ) );

/* ===========================================================================
 * 2. Cennik: karta, zakladki, zmiana ceny widoczna od razu
 * ======================================================================== */

echo "--- karta cennika: tresc i escapowanie ---\n";
$zla  = array_merge(
	$wzor,
	array(
		'id'           => 0,
		'slug'         => 'x" onclick="alert(1)',
		'tytul'        => 'Usługa <script>alert(1)</script> testowa',
		'zajawka'      => 'Zajawka z <img src=x onerror=alert(2)> obrazkiem.',
		'opis'         => '<p>Opis</p><script>alert(3)</script>',
		'grupy'        => array(),
		'progi'        => array(
			array(
				'etykieta' => '1-10 <i>x</i>',
				'cena'     => '500',
				'od'       => 0,
			),
			array(
				'etykieta' => '11 i więcej',
				'cena'     => '700',
				'od'       => 1,
			),
		),
		'w_cenie'      => array( 'pozycja <b>pierwsza</b>', 'druga', 'trzecia', 'czwarta w szczegółach', 'piąta też' ),
		'dodatkowo'    => array( 'kadry i płace (50 zł netto za pracownika)' ),
		'nie_obejmuje' => array( 'reprezentacja przed sądem' ),
		'czynniki'     => array( 'liczba dokumentów w miesiącu' ),
		'wyroznienie'  => 'Dla <u>spółek</u>',
	)
);
$html = kw_uslugi_cennik_karta( $zla, true );
$sprawdz( 'karta: zadnego znacznika z danych (script, img, b, i, u) ani atrybutu wstrzyknietego przez slug', ! preg_match( '~<(script|img|b|i|u)[ >]~', $html ) && false === strpos( $html, '" onclick="' ) && false !== strpos( $html, 'data-kw-usluga="x&quot; onclick=&quot;alert(1)"' ), substr( $html, 0, 400 ) );
$sprawdz( 'karta: cena w trzech czesciach (od, kwota, netto / mies.)', false !== strpos( $html, '<span class="kw-c__od">od</span> <span class="kw-c__kwota">500&nbsp;zł</span> <span class="kw-c__jedn">netto / mies.</span>' ), $html );
$skrot = array();
preg_match( '~<div class="kw-c__skrot">.*?</div>~s', $html, $skrot );
$sprawdz( 'karta, 5 pozycji "W cenie": na wierzchu trzy pierwsze z dopiskiem "między innymi", reszta w szczegolach jako "W cenie także", nic sie nie powtarza', 3 === substr_count( $skrot[0] ?? '', '<li>' ) && false !== strpos( $skrot[0] ?? '', '>W cenie między innymi</p>' ) && false === strpos( $skrot[0] ?? '', 'czwarta' ) && (bool) preg_match( '~>W cenie także</p><ul[^>]*><li>czwarta w&nbsp;szczegółach</li><li>piąta też</li></ul>~', $html ) && 1 === substr_count( $html, 'druga' ), $skrot[0] ?? 'brak' );
$cztery = kw_uslugi_cennik_karta( array_merge( $zla, array( 'w_cenie' => array( 'a1', 'a2', 'a3', 'a4' ) ) ), true );
$sprawdz( 'karta, 4 pozycje "W cenie": wszystkie na wierzchu, bez dopisku i bez ciagu dalszego', 4 === substr_count( $cztery, '<li>a' ) && false !== strpos( $cztery, '>W cenie</p>' ) && false === strpos( $cztery, 'W cenie także' ) );
$sprawdz( 'karta: tabela progow (2 wiersze, drugi "od 700 zł"), naglowek kolumny z jednostka', 2 === substr_count( $html, '<th scope="row">' ) && false !== strpos( $html, '<td>od 700&nbsp;zł</td>' ) && false !== strpos( $html, '<th scope="col">Cena netto / mies.</th>' ), $html );
$sprawdz( 'karta: bloki Dodatkowo płatne, Nie obejmuje, Od czego zależy cena w <details>, karta wyrozniona', 1 === substr_count( $html, '<details' ) && false !== strpos( $html, '>Dodatkowo płatne</p>' ) && false !== strpos( $html, '>Nie obejmuje</p>' ) && false !== strpos( $html, '>Od czego zależy cena</p>' ) && false !== strpos( $html, 'kw-c__karta--wyrozniona' ) );
$sprawdz( 'karta: "50 zł" z twarda spacja (kwota nie rozdziela sie miedzy wierszami)', false !== strpos( $html, '(50&nbsp;zł netto za pracownika)' ) );
$bez = kw_uslugi_cennik_karta( $zla, false );
$sprawdz( 'ceny wylaczone: karta bez ceny i bez tabeli progow, zakres zostaje', false === strpos( $bez, 'kw-c__cena' ) && false === strpos( $bez, 'kw-c__progi' ) && false !== strpos( $bez, 'kw-c__skrot' ) && false !== strpos( $bez, '>W cenie także</p>' ) && false !== strpos( $bez, 'kw-c__karta--bezceny' ) );
$pusta = kw_uslugi_cennik_karta(
	array_merge(
		$zla,
		array(
			'cena_typ'     => 'indywidualna',
			'progi'        => array(),
			'w_cenie'      => array(),
			'dodatkowo'    => array(),
			'nie_obejmuje' => array(),
			'czynniki'     => array(),
			'opis'         => '',
			'wyroznienie'  => '',
		)
	),
	true
);
$sprawdz( 'usluga bez progow i list: "Wycena indywidualna", bez <details>, przycisk zostaje', false !== strpos( $pusta, 'Wycena indywidualna' ) && false === strpos( $pusta, '<details' ) && false !== strpos( $pusta, 'href="#kontakt"' ) );

echo "--- cennik na zywych danych + usluga-szkic (nic nie jest publikowane) ---\n";
$szkic = wp_insert_post(
	array(
		'post_type'    => 'kw_usluga',
		'post_status'  => 'draft',
		'post_title'   => 'Samotest usługa',
		'post_name'    => 'kw-samotest',
		'post_excerpt' => 'Zajawka samotestu.',
		'menu_order'   => 99999,
	)
);
update_post_meta( $szkic, 'kw_cena_typ', 'od' );
update_post_meta( $szkic, 'kw_cena_min', '123' );
update_post_meta( $szkic, 'kw_cena_jednostka', 'miesiac' );
$dopisz = static function ( $uslugi ) use ( $szkic ) {
	$uslugi[] = kw_uslugi_z_posta( get_post( $szkic ) );
	return $uslugi;
};
add_filter( 'kw_uslugi_lista', $dopisz );
// Test nie zalezy od tego, jak wlasciciel ustawil "Pokazuj ceny": na czas testu opcja jest podstawiona (bez zapisu do bazy).
$wlacz  = static function () {
	return array( 'ceny' => 1 );
};
$wylacz = static function () {
	return array( 'ceny' => 0 );
};
add_filter( 'pre_option_kw_uslugi_opcje', $wlacz );
$html = do_shortcode( '[kw_cennik]' );
$taby = array();
preg_match_all( '~data-kw-tab="([^"]+)"~', $html, $taby );
$sprawdz( 'usluga bez kategorii trafia do zakladki "Pozostałe usługi" na koncu', 'pozostale' === end( $taby[1] ) && false !== strpos( $html, 'id="cennik-pozostale"' ), implode( ',', $taby[1] ) );
$sprawdz( 'cena z bazy na karcie: "od 123 zł netto / mies."', false !== strpos( $html, '<span class="kw-c__kwota">123&nbsp;zł</span>' ) && false !== strpos( $html, 'data-kw-usluga="kw-samotest"' ) );
update_post_meta( $szkic, 'kw_cena_min', '777,50 zł' ); // tak zapisuje WP-CLI: wp post meta update <ID> kw_cena_min ...
$html2 = do_shortcode( '[kw_cennik]' );
$sprawdz( 'zmiana ceny (update_post_meta, jak WP-CLI) od razu w renderze: 777,50 zł zamiast 123 zł', false !== strpos( $html2, '<span class="kw-c__kwota">777,50&nbsp;zł</span>' ) && false === strpos( $html2, '>123&nbsp;zł<' ) && '777.5' === get_post_meta( $szkic, 'kw_cena_min', true ), (string) get_post_meta( $szkic, 'kw_cena_min', true ) );
// Progi dla wykresow na stronie: funkcja kw_uslugi_progi() i shortcode [kw_progi] biora dane z uslugi, jak karta cennika.
$kw_progi_przed = get_post_meta( $szkic, 'kw_progi', true );
$kw_ustaw_progi = static function ( array $progi ) use ( $szkic ) {
	update_post_meta( $szkic, 'kw_progi', wp_slash( kw_uslugi_czysc_progi( $progi ) ) );
};
$kw_ustaw_progi(
	array(
		array(
			'etykieta' => 'A&B "x"',
			'cena'     => '150',
		),
		array(
			'etykieta' => '11-20',
			'cena'     => '1200,50',
		),
		array(
			'etykieta' => '21 i więcej',
			'cena'     => '3500',
			'od'       => '1',
		),
	)
);
$kw_p  = kw_uslugi_progi( 'kw-samotest' );
$kw_sc = do_shortcode( '[kw_progi slug="kw-samotest"]' );
$sprawdz( 'kw_uslugi_progi( slug ): progi w kolejnosci z panelu, kwota jako liczba, "od" jako bool', array( array( 'etykieta' => 'A&B "x"', 'kwota' => 150.0, 'od' => false ), array( 'etykieta' => '11-20', 'kwota' => 1200.5, 'od' => false ), array( 'etykieta' => '21 i więcej', 'kwota' => 3500.0, 'od' => true ) ) === $kw_p, wp_json_encode( $kw_p ) );
$sprawdz( 'shortcode [kw_progi]: tabela z klasami, podpis i naglowki kolumn, kwoty jak na karcie, liczby dla wykresu w data-kwota i data-od, tekst escapowany', false !== strpos( $kw_sc, '<table class="kw-progi-dane" data-kw-progi="kw-samotest"><caption class="kw-progi-dane__podpis">' ) && false !== strpos( $kw_sc, '<th scope="col">Cena netto / mies.</th>' ) && false !== strpos( $kw_sc, '<tr class="kw-progi-dane__wiersz" data-kwota="1200.5" data-od="0"><th scope="row" class="kw-progi-dane__zakres">11-20</th><td class="kw-progi-dane__cena">1200,50&nbsp;zł</td></tr>' ) && false !== strpos( $kw_sc, 'data-kwota="3500" data-od="1"' ) && false !== strpos( $kw_sc, '<td class="kw-progi-dane__cena">od 3500&nbsp;zł</td>' ) && false !== strpos( $kw_sc, 'A&amp;B &quot;x&quot;' ) && false === strpos( $kw_sc, 'A&B' ) && 3 === substr_count( $kw_sc, '<tr class="kw-progi-dane__wiersz"' ) && false === strpos( $kw_sc, 'style=' ), $kw_sc );
$kw_ustaw_progi(
	array(
		array(
			'etykieta' => '1-10',
			'cena'     => '175',
		),
	)
);
$sprawdz( 'zmiana progu w usludze zmienia wynik funkcji i shortcodu', array( array( 'etykieta' => '1-10', 'kwota' => 175.0, 'od' => false ) ) === kw_uslugi_progi( 'kw-samotest' ) && false !== strpos( do_shortcode( '[kw_progi slug="kw-samotest"]' ), 'data-kwota="175" data-od="0"' ) && false === strpos( do_shortcode( '[kw_progi slug="kw-samotest"]' ), '1200' ), wp_json_encode( kw_uslugi_progi( 'kw-samotest' ) ) );
update_post_meta( $szkic, 'kw_w_cenniku', '0' );
$sprawdz( 'progi uslugi ukrytej w cenniku => pusty wynik funkcji i shortcodu', array() === kw_uslugi_progi( 'kw-samotest' ) && '' === do_shortcode( '[kw_progi slug="kw-samotest"]' ) );
update_post_meta( $szkic, 'kw_w_cenniku', '1' );
remove_filter( 'pre_option_kw_uslugi_opcje', $wlacz );
add_filter( 'pre_option_kw_uslugi_opcje', $wylacz );
$sprawdz( 'progi przy wylaczonym "Pokazuj ceny" => pusty wynik (kwoty nie wychodza inna droga)', array() === kw_uslugi_progi( 'kw-samotest' ) && '' === do_shortcode( '[kw_progi slug="kw-samotest"]' ) );
remove_filter( 'pre_option_kw_uslugi_opcje', $wylacz );
add_filter( 'pre_option_kw_uslugi_opcje', $wlacz );
$kw_ustaw_progi( array() );
$sprawdz( 'usluga bez progow, nieznany slug i brak sluga => pusty wynik', array() === kw_uslugi_progi( 'kw-samotest' ) && '' === do_shortcode( '[kw_progi slug="kw-samotest"]' ) && array() === kw_uslugi_progi( 'nie-ma-takiej' ) && '' === do_shortcode( '[kw_progi slug="nie-ma-takiej"]' ) && '' === do_shortcode( '[kw_progi]' ) && array() === kw_uslugi_progi( array( 'x' ) ) );
if ( '' === $kw_progi_przed ) {
	delete_post_meta( $szkic, 'kw_progi' );
} else {
	update_post_meta( $szkic, 'kw_progi', wp_slash( $kw_progi_przed ) );
}
// Oferty schema.org (motyw dolacza je do JSON-LD) biora ceny z tych samych danych co karty.
$oferta = static function () {
	foreach ( (array) ( kw_uslugi_oferty_schema()['itemListElement'] ?? array() ) as $o ) {
		if ( 'Samotest usługa' === $o['name'] ) {
			return $o;
		}
	}
	return null;
};
$katalog = kw_uslugi_oferty_schema();
$sprawdz( 'oferty schema.org: funkcja oddaje wezel OfferCatalog z lista ofert (motyw wklada go wprost do hasOfferCatalog)', 'OfferCatalog' === ( $katalog['@type'] ?? '' ) && is_array( $katalog['itemListElement'] ?? null ) && array_keys( $katalog['itemListElement'] ) === range( 0, count( $katalog['itemListElement'] ) - 1 ), wp_json_encode( array_keys( $katalog ) ) );
$o1 = $oferta();
$sprawdz( 'oferty schema.org: usluga z cennika => Offer z minPrice 777.5, PLN, unitText "mies.", kwota netto, bez pola price', is_array( $o1 ) && 'Offer' === $o1['@type'] && 'Service' === $o1['itemOffered']['@type'] && 'PLN' === ( $o1['priceCurrency'] ?? '' ) && '777.5' === ( $o1['priceSpecification']['minPrice'] ?? '' ) && 'mies.' === ( $o1['priceSpecification']['unitText'] ?? '' ) && false === ( $o1['priceSpecification']['valueAddedTaxIncluded'] ?? null ) && ! isset( $o1['price'] ), wp_json_encode( $o1 ) );
update_post_meta( $szkic, 'kw_cena_min', '800' );
$o2 = $oferta();
$sprawdz( 'oferty schema.org: zmiana ceny uslugi zmienia wynik funkcji (777.5 => 800)', '800' === ( $o2['priceSpecification']['minPrice'] ?? '' ), wp_json_encode( $o2 ) );
update_post_meta( $szkic, 'kw_cena_typ', 'stala' );
$o3 = $oferta();
$sprawdz( 'oferty schema.org: stala kwota => price 800 (w ofercie i w specyfikacji), bez minPrice', '800' === ( $o3['price'] ?? '' ) && '800' === ( $o3['priceSpecification']['price'] ?? '' ) && ! isset( $o3['priceSpecification']['minPrice'] ), wp_json_encode( $o3 ) );
update_post_meta( $szkic, 'kw_cena_typ', 'przedzial' );
update_post_meta( $szkic, 'kw_cena_max', '1200' );
$o3b = $oferta();
$sprawdz( 'oferty schema.org: przedzial => minPrice 800 i maxPrice 1200', '800' === ( $o3b['priceSpecification']['minPrice'] ?? '' ) && '1200' === ( $o3b['priceSpecification']['maxPrice'] ?? '' ) && ! isset( $o3b['price'] ), wp_json_encode( $o3b ) );
update_post_meta( $szkic, 'kw_cena_max', '' );
update_post_meta( $szkic, 'kw_cena_typ', 'indywidualna' );
$o4 = $oferta();
$sprawdz( 'oferty schema.org: wycena indywidualna => oferta z nazwa, bez zadnej ceny', is_array( $o4 ) && ! isset( $o4['price'] ) && ! isset( $o4['priceSpecification'] ) && ! isset( $o4['priceCurrency'] ), wp_json_encode( $o4 ) );
update_post_meta( $szkic, 'kw_cena_typ', 'od' );
update_post_meta( $szkic, 'kw_w_cenniku', '0' );
$sprawdz( 'oferty schema.org: usluga schowana z cennika nie ma oferty', null === $oferta() );
update_post_meta( $szkic, 'kw_w_cenniku', '1' );
$sprawdz( 'oferty schema.org: zadna oferta nie niesie znacznikow HTML ani pustej nazwy', ! array_filter( (array) ( kw_uslugi_oferty_schema()['itemListElement'] ?? array() ), static function ( $o ) { return '' === $o['name'] || false !== strpos( wp_json_encode( $o ), '<' ); } ) );
update_post_meta( $szkic, 'kw_cena_min', '777,50 zł' );
update_post_meta( $szkic, 'kw_progi', '[{"etykieta":"1-10","cena":"777.5"},{"etykieta":"11 i więcej","cena":"900","od":1}]' );
$sprawdz( 'progi zapisane jako JSON (WP-CLI --format=json albo tekst) pokazuja sie w tabeli', false !== strpos( do_shortcode( '[kw_cennik]' ), '<td>od 900&nbsp;zł</td>' ), wp_json_encode( get_post_meta( $szkic, 'kw_progi', true ) ) );
update_post_meta( $szkic, 'kw_w_cenniku', '0' );
$sprawdz( '"Pokaż w cenniku" odznaczone => uslugi nie ma w cenniku', false === strpos( do_shortcode( '[kw_cennik]' ), 'kw-samotest' ) );
update_post_meta( $szkic, 'kw_w_cenniku', '1' );

remove_filter( 'pre_option_kw_uslugi_opcje', $wlacz );
add_filter( 'pre_option_kw_uslugi_opcje', $wylacz );
$bez_cen = do_shortcode( '[kw_cennik]' );
$sprawdz( 'oferty schema.org: opcja "Pokazuj ceny" wylaczona => zadna oferta nie ma ceny', ! array_filter( (array) ( kw_uslugi_oferty_schema()['itemListElement'] ?? array() ), static function ( $o ) { return isset( $o['price'] ) || isset( $o['priceSpecification'] ); } ) );
$sprawdz( 'opcja "Pokazuj ceny" wylaczona => caly cennik bez cen i progow', false === strpos( $bez_cen, 'kw-c__cena' ) && false === strpos( $bez_cen, 'kw-c__progi' ) && false !== strpos( $bez_cen, 'kw-samotest' ) );
remove_filter( 'pre_option_kw_uslugi_opcje', $wylacz );
add_filter( 'pre_option_kw_uslugi_opcje', $wlacz );

// Sprawdzenia na zywych danych dotycza tylko zasad (kolejnosc, pierwsza zakladka), nie konkretnych cen ani nazw:
// te zmienia wlasciciel w panelu i test nie moze przez to zaczac zglaszac bledow.
$kategorie = kw_uslugi_kategorie( true );
$zakladki  = array_values( array_diff( $taby[1], array( 'pozostale' ) ) );
if ( count( $zakladki ) >= 2 ) {
	$numery = array();
	foreach ( $zakladki as $slug ) {
		$numery[] = $kategorie[ $slug ]['kolejnosc'] ?? -1;
	}
	$rosnaco = $numery;
	sort( $rosnaco );
	$sprawdz( 'zakladki stoja w kolejnosci pola "Kolejnosc zakladki" kategorii (' . implode( ', ', $zakladki ) . ')', $numery === $rosnaco && ! in_array( -1, $numery, true ), wp_json_encode( $numery ) );
	$sprawdz( 'pierwsza zakladka i jej panel aktywne w HTML (bez JavaScriptu widac pierwsza kategorie), pozostale panele nieaktywne', 1 === substr_count( $html, 'class="kw-c__tab is-aktywna"' ) && false !== strpos( $html, '<section class="kw-c__panel is-aktywna" id="cennik-' . $zakladki[0] . '"' ) && count( $taby[1] ) - 1 === substr_count( $html, '<section class="kw-c__panel" id="cennik-' ) );
	// Usluga przypisana do dwoch kategorii stoi tylko w tej, ktora jest wczesniej w kolejnosci zakladek.
	wp_set_object_terms( $szkic, array( $kategorie[ $zakladki[1] ]['id'], $kategorie[ $zakladki[0] ]['id'] ), 'kw_grupa' );
	$dwie = do_shortcode( '[kw_cennik]' );
	// Kolejny cennik w tym samym zadaniu dostaje przedrostek z numerem (cennik2-, cennik3-...), stad \d* we wzorcu.
	$od     = preg_match( '~<section[^>]*id="cennik\d*-' . preg_quote( $zakladki[0], '~' ) . '"~', $dwie, $m, PREG_OFFSET_CAPTURE ) ? (int) $m[0][1] : -1;
	$do     = (int) strpos( $dwie, '<section', $od + 10 );
	$karta  = (int) strpos( $dwie, 'data-kw-usluga="kw-samotest"' );
	$sprawdz( 'usluga w dwoch kategoriach stoi raz, w zakladce wczesniejszej kategorii', 1 === substr_count( $dwie, 'data-kw-usluga="kw-samotest"' ) && $od >= 0 && $karta > $od && $karta < $do, wp_json_encode( array( $od, $karta, $do ) ) );
	wp_set_object_terms( $szkic, array(), 'kw_grupa' );
} else {
	echo "POMINIETO: kolejnosc zakladek (w cenniku sa mniej niz dwie kategorie z uslugami)\n";
}
// Naglowek panelu zakladki: pole "Naglowek zakladki dla wyszukiwarek" kategorii; etykieta zakladki zostaje nazwa kategorii.
$kw_zakl = wp_insert_term( 'Samotest zakładka', 'kw_grupa', array( 'slug' => 'kw-samotest-zakladka' ) );
if ( ! is_wp_error( $kw_zakl ) ) {
	$kw_zakl_id = (int) $kw_zakl['term_id'];
	update_term_meta( $kw_zakl_id, 'kw_kolejnosc', 1 );
	wp_set_object_terms( $szkic, array( $kw_zakl_id ), 'kw_grupa' );
	kw_uslugi_kategorie( true );
	$bez_n = do_shortcode( '[kw_cennik]' );
	update_term_meta( $kw_zakl_id, 'kw_naglowek', 'Samotest nagłówek <b>dla wyszukiwarek</b>' );
	kw_uslugi_kategorie( true );
	$z_n = do_shortcode( '[kw_cennik]' );
	$sprawdz( 'naglowek zakladki: bez pola naglowek panelu = nazwa kategorii', 1 === preg_match( '~<h3 class="kw-c__kategoria" id="cennik\d*-kw-samotest-zakladka-nazwa">Samotest zakładka</h3>~', $bez_n ), substr( $bez_n, (int) strpos( $bez_n, 'kw-samotest-zakladka-nazwa' ) - 60, 200 ) );
	$sprawdz( 'naglowek zakladki: pole kategorii trafia do ukrytego naglowka panelu (bez znacznikow), etykieta zakladki zostaje krotka', 1 === preg_match( '~<h3 class="kw-c__kategoria" id="cennik\d*-kw-samotest-zakladka-nazwa">Samotest nagłówek dla wyszukiwarek</h3>~', $z_n ) && 1 === preg_match( '~data-kw-tab="kw-samotest-zakladka">Samotest zakładka</a>~', $z_n ), substr( $z_n, (int) strpos( $z_n, 'kw-samotest-zakladka-nazwa' ) - 60, 220 ) );
	$sprawdz( 'karta cennika: etykiety blokow sa akapitami, w cenniku nie ma naglowkow h5', false === strpos( $z_n, '<h5' ) && false !== strpos( $z_n, '<p class="kw-c__blok-h">' ) );
	wp_set_object_terms( $szkic, array(), 'kw_grupa' );
	wp_delete_term( $kw_zakl_id, 'kw_grupa' ); // wlasna kategoria testowa
	kw_uslugi_kategorie( true );
}
$formularz_html = do_shortcode( '[kw_formularz]' );
$sprawdz( 'formularz: komunikaty awarii i podziekowania to akapity z role="status" (bez naglowkow h3), fokus nadal mozliwy', false === strpos( $formularz_html, '<h3' ) && false !== strpos( $formularz_html, '<p class="kw-f__awaria-h"' ) && false !== strpos( $formularz_html, '<p class="kw-f__sukces-h" role="status" tabindex="-1">' ) );
add_filter( 'privacy_policy_url', '__return_empty_string', 99 );
$kw_bez_polityki = do_shortcode( '[kw_formularz]' );
remove_filter( 'privacy_policy_url', '__return_empty_string', 99 );
$kw_adres_polityki = static function () {
	return 'https://example.com/polityka-samotest/';
};
add_filter( 'privacy_policy_url', $kw_adres_polityki, 99 );
$kw_z_polityka = do_shortcode( '[kw_formularz]' );
remove_filter( 'privacy_policy_url', $kw_adres_polityki, 99 );
$kw_klauzula = 'Administratorem danych jest Kancelaria Wieczorek sp.&nbsp;z&nbsp;o.o. Dane z&nbsp;formularza wykorzystamy wyłącznie do odpowiedzi na Twoją wiadomość.';
$sprawdz( 'formularz: zdanie o administratorze danych i celu stoi takze bez opublikowanej polityki (wtedy bez odnosnika)', false !== strpos( $kw_bez_polityki, '<p class="kw-f__rodo">' . $kw_klauzula . '</p>' ) && false === strpos( $kw_bez_polityki, 'polityka prywatności' ), substr( $kw_bez_polityki, (int) strpos( $kw_bez_polityki, 'kw-f__rodo' ), 300 ) );
$sprawdz( 'formularz: gdy polityka jest opublikowana, klauzula konczy sie odnosnikiem do niej', false !== strpos( $kw_z_polityka, $kw_klauzula . ' Szczegóły: <a href="https://example.com/polityka-samotest/">polityka prywatności</a>.</p>' ), substr( $kw_z_polityka, (int) strpos( $kw_z_polityka, 'kw-f__rodo' ), 400 ) );
$jedna = do_shortcode( '[kw_cennik grupa="nie-ma-takiej"]' );
$sprawdz( 'nieznana kategoria w atrybucie => gosc nie widzi nic', '' === $jedna, $jedna );

echo "--- kreator: zapis pol (token, uprawnienia, sanityzacja) ---\n";
if ( function_exists( 'kw_uslugi_zapisz' ) || ( require_once KW_USLUGI_KATALOG . 'includes/admin.php' ) ) {
	$admin = (int) ( get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	)[0] ?? 1 );
	wp_set_current_user( $admin );
	$formularz = array(
		'kw_cena_typ'              => 'przedzial',
		'kw_cena_min'              => '30',
		'kw_cena_max'              => '60,00',
		'kw_cena_jednostka'        => '_wlasna',
		'kw_cena_jednostka_wlasna' => 'wniosek <b>x</b>',
		'kw_cena_netto'            => '1',
		'kw_progi'                 => array(
			array(
				'etykieta' => '1-10',
				'cena'     => '30',
			),
			array(
				'etykieta' => '',
				'cena'     => '',
			),
		),
		'kw_w_cenie'               => "pozycja 1\n<script>alert(1)</script>pozycja 2",
		'kw_wyroznienie'           => 'Nowość',
		'kw_w_cenniku'             => '1',
		// kw_w_formularzu celowo brak: pole wyboru odznaczone
		'kw_ikona'                 => 'far fa-flag',
		'kw_na_stronie'            => 'nie',
		'kw_link'                  => 'javascript:alert(1)',
	);
	$_POST     = array_merge( $formularz, array( 'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ) ) );
	kw_uslugi_zapisz( $szkic );
	$u = kw_uslugi_z_posta( get_post( $szkic ) );
	$sprawdz( 'zapis z tokenem i uprawnieniem: typ, kwoty, jednostka wlasna bez znacznikow, progi bez pustego wiersza', 'przedzial' === $u['cena_typ'] && '30' === $u['cena_min'] && '60' === $u['cena_max'] && 'wniosek x' === $u['jednostka'] && 1 === count( $u['progi'] ), wp_json_encode( array( $u['cena_typ'], $u['cena_min'], $u['cena_max'], $u['jednostka'], $u['progi'] ) ) );
	$sprawdz( 'zapis: lista bez znacznika script, wyroznienie, pole wyboru odznaczone = 0, odnosnik javascript: odrzucony', array( 'pozycja 1', 'pozycja 2' ) === $u['w_cenie'] && 'Nowość' === $u['wyroznienie'] && false === $u['formularz'] && true === $u['cennik'] && '' === $u['link'] && 'nie' === $u['miejsce'], wp_json_encode( array( $u['w_cenie'], $u['wyroznienie'], $u['formularz'], $u['cennik'], $u['link'] ) ) );
	$sprawdz( 'cena po zapisie: "30-60 zł netto / wniosek x"', "30-60{$nb}zł netto / wniosek x" === kw_uslugi_cena( $u )['tekst'], kw_uslugi_cena( $u )['tekst'] );

	$_POST = array_merge( $formularz, array( 'kw_cena_min' => '999', 'kw_usluga_nonce' => 'zlytoken' ) );
	kw_uslugi_zapisz( $szkic );
	$_POST = array_merge( $formularz, array( 'kw_cena_min' => '999' ) );
	kw_uslugi_zapisz( $szkic );
	$sprawdz( 'zly token albo brak tokenu => nic sie nie zapisuje', '30' === get_post_meta( $szkic, 'kw_cena_min', true ), (string) get_post_meta( $szkic, 'kw_cena_min', true ) );
	wp_set_current_user( 0 );
	$_POST = array_merge( $formularz, array( 'kw_cena_min' => '999', 'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ) ) );
	kw_uslugi_zapisz( $szkic );
	$sprawdz( 'poprawny token, ale uzytkownik bez prawa edycji => nic sie nie zapisuje', '30' === get_post_meta( $szkic, 'kw_cena_min', true ), (string) get_post_meta( $szkic, 'kw_cena_min', true ) );
	$_POST = array();

	$typ = get_post_type_object( 'kw_usluga' );
	$sprawdz( 'uslugi edytuje redaktor albo administrator (uprawnienia stron), nie autor wpisow', 'edit_pages' === $typ->cap->edit_posts && 'publish_pages' === $typ->cap->publish_posts );
	$sprawdz( 'pola uslugi przez REST: gosc nie moze zmieniac, administrator moze', ! current_user_can( 'edit_post_meta', $szkic, 'kw_cena_min' ) && user_can( $admin, 'edit_post_meta', $szkic, 'kw_cena_min' ) && user_can( $admin, 'edit_post_meta', $szkic, 'kw_progi' ) );

	// Netto / brutto to teraz dwa pola wyboru (1 albo 0); starsze pola (ikona, stara siatka, odnosnik) nie maja kontrolek.
	wp_set_current_user( $admin );
	update_post_meta( $szkic, 'kw_ikona', 'far fa-flag' );
	update_post_meta( $szkic, 'kw_na_stronie', 'nie' );
	update_post_meta( $szkic, 'kw_link', '/stary-odnosnik/' );
	$bez_starszych = array_diff_key( $formularz, array_flip( array( 'kw_ikona', 'kw_na_stronie', 'kw_link' ) ) );
	$_POST         = array_merge( $bez_starszych, array( 'kw_cena_netto' => '0', 'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ) ) );
	kw_uslugi_zapisz( $szkic );
	$u = kw_uslugi_z_posta( get_post( $szkic ) );
	$sprawdz( 'zapis: pole "brutto" (kw_cena_netto = 0) => cena brutto', false === $u['netto'] && "30-60{$nb}zł brutto / wniosek x" === kw_uslugi_cena( $u )['tekst'], kw_uslugi_cena( $u )['tekst'] );
	$sprawdz( 'zapis bez starszych pol w formularzu: ikona, miejsce i odnosnik zostaja takie, jak byly w bazie', 'far fa-flag' === $u['ikona'] && 'nie' === $u['miejsce'] && '/stary-odnosnik/' === $u['link'], wp_json_encode( array( $u['ikona'], $u['miejsce'], $u['link'] ) ) );
	$_POST = array_merge( $bez_starszych, array( 'kw_cena_netto' => '1', 'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ) ) );
	kw_uslugi_zapisz( $szkic );
	$bez_netto = array_diff_key( $bez_starszych, array( 'kw_cena_netto' => 1 ) );
	$netto_1   = get_post_meta( $szkic, 'kw_cena_netto', true );
	$_POST     = array_merge( $bez_netto, array( 'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ) ) );
	kw_uslugi_zapisz( $szkic );
	$sprawdz( 'zapis: pole "netto" => 1; brak pola w zadaniu => 0 (jak odznaczone pole wyboru)', '1' === $netto_1 && '0' === get_post_meta( $szkic, 'kw_cena_netto', true ), wp_json_encode( array( $netto_1, get_post_meta( $szkic, 'kw_cena_netto', true ) ) ) );
	$_POST = array_merge(
		$bez_starszych,
		array(
			'kw_cena_netto'   => array( 'x' ),
			'kw_cena_typ'     => array( 'od' ),
			'kw_w_cenie'      => array( array( 'a' ) ),
			'kw_progi'        => 'smiec',
			'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ),
		)
	);
	kw_uslugi_zapisz( $szkic );
	$u = kw_uslugi_z_posta( get_post( $szkic ) );
	$sprawdz( 'zapis: tablice w miejscu tekstu i smieci w progach nie psuja zapisu (typ => indywidualna, lista pusta, progi puste)', 'indywidualna' === $u['cena_typ'] && array() === $u['w_cenie'] && array() === $u['progi'], wp_json_encode( array( $u['cena_typ'], $u['w_cenie'], $u['progi'] ) ) );
	$_POST = array_merge( $bez_starszych, array( 'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ) ) );
	kw_uslugi_zapisz( $szkic );
	$_POST = array();

	// Kreator: wartosci z bazy w polach, escapowanie nazwy, kategoria z archiwum poza lista wyboru.
	wp_update_post(
		array(
			'ID'         => $szkic,
			'post_title' => 'Samotest "><script>alert(4)</script> usługa',
		)
	);
	$archiwum = wp_insert_term( 'Samotest archiwum', 'kw_grupa', array( 'slug' => 'kw-samotest-archiwum' ) );
	$arch_id  = is_wp_error( $archiwum ) ? 0 : (int) $archiwum['term_id'];
	update_term_meta( $arch_id, 'kw_ukryta', '1' );
	ob_start();
	kw_uslugi_kreator( get_post( $szkic ) );
	$kreator = (string) ob_get_clean();
	ob_start();
	kw_uslugi_kreator_podglad( get_post( $szkic ) );
	$podglad = (string) ob_get_clean();
	$sprawdz( 'kreator: pola wypelnione z bazy (kwota, wlasna jednostka, prog), token zapisu', false !== strpos( $kreator, 'name="kw_cena_min" maxlength="12" value="30"' ) && false !== strpos( $kreator, 'name="kw_cena_jednostka_wlasna" maxlength="40" value="wniosek x"' ) && false !== strpos( $kreator, 'name="kw_progi[0][etykieta]" value="1-10"' ) && false !== strpos( $kreator, 'name="kw_usluga_nonce"' ), substr( $kreator, 0, 300 ) );
	$sprawdz( 'kreator: nazwa uslugi w polu z etykieta, znaczniki z nazwy unieszkodliwione', false !== strpos( $kreator, '<label for="title">Nazwa usługi</label>' ) && false !== strpos( $kreator, 'name="post_title"' ) && false === strpos( $kreator, '<script>alert(4)' ) );
	$sprawdz( 'kreator: cztery listy, podpowiedz i miejsce na blad podpiete przez aria-describedby', 4 === substr_count( $kreator, 'data-kw-lista="' ) && false !== strpos( $kreator, 'aria-describedby="kw-cena-min-pomoc kw-cena-min-blad"' ) && false !== strpos( $kreator, 'id="kw-cena-min-pomoc"' ) && false !== strpos( $kreator, 'id="kw-cena-min-blad" data-kw-blad hidden' ) );
	$sprawdz( 'kreator: piec kafli typu ceny, wybrany ten z bazy', 5 === substr_count( $kreator, 'class="kw-typ"' ) && 1 === preg_match( '~id="kw-typ-przedzial" name="kw_cena_typ" value="przedzial"\s+checked~', $kreator ) );
	$kw_cele = array( 'tytul', 'zajawka', 'kategoria', 'cena', 'progi', 'wcenie', 'dodatkowo', 'nie', 'czynniki', 'widocznosc', 'kolejnosc', 'wyroznienie', 'opis' );
	$kw_brak = array();
	foreach ( $kw_cele as $kw_cel ) {
		if ( false === strpos( $kreator, 'data-kw-cel="' . $kw_cel . '"' ) ) {
			$kw_brak[] = $kw_cel;
		}
	}
	$sprawdz( 'kreator: kazde pole wskazuje swoje miejsce w podgladzie karty (13 celow data-kw-cel) i jest miejsce na podpowiedz podswietlenia', array() === $kw_brak && false !== strpos( $podglad, 'data-kw-wskazowka' ), wp_json_encode( $kw_brak ) );
	$sprawdz( 'kreator: dymki "?" przy polach: przycisk z nazwa, tresc w role="tooltip" podpieta przez aria-describedby, przycisk nie wysyla formularza', substr_count( $kreator, 'class="kwa-dymek__btn"' ) >= 12 && substr_count( $kreator, 'class="kwa-dymek__btn"' ) === substr_count( $kreator, '<button type="button" class="kwa-dymek__btn"' ) && false !== strpos( $kreator, 'aria-label="Wyjaśnienie: Jak podajesz cenę"' ) && 1 === preg_match( '~aria-describedby="(kwa-dymek-[a-z0-9_-]+)" aria-expanded="false"><span aria-hidden="true">\?</span></button><span class="kwa-dymek__tresc" role="tooltip" id="\1">~', $kreator ), (string) substr_count( $kreator, 'class="kwa-dymek__btn"' ) );
	$kw_jak = 1 === preg_match( '~<details class="kw-jak" data-kw-jak open>(.*?)</details>~s', $kreator, $kw_m ) ? $kw_m[1] : '';
	$sprawdz( 'kreator: instrukcja "Jak to działa" nad krokami: piec zdan, mowi o podgladzie, o Aktualizuj i o chowaniu bez usuwania', 5 === substr_count( $kw_jak, '<li>' ) && false !== strpos( $kw_jak, '<summary>Jak to działa</summary>' ) && false !== strpos( $kw_jak, 'Podgląd karty' ) && false !== strpos( $kw_jak, 'Aktualizuj' ) && false !== strpos( $kw_jak, 'nie trzeba usuwać' ), substr( $kw_jak, 0, 200 ) );
	if ( ! function_exists( 'settings_fields' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'submit_button' ) ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}
	ob_start();
	kw_uslugi_strona_ustawien();
	$kw_ustawienia = (string) ob_get_clean();
	$kw_przed_kodami = (string) strstr( $kw_ustawienia, '<details class="kw-ust-kody">', true );
	$sprawdz( 'Ustawienia: kody do wstawienia na strone sa w zwinietej czesci "Dla osoby, która buduje stronę", a nad nia nie ma zadnego kodu w nawiasach kwadratowych; jest sekcja importu cen', '' !== $kw_przed_kodami && false === strpos( $kw_przed_kodami, '[kw_' ) && false === strpos( $kw_przed_kodami, 'shortcod' ) && false !== strpos( $kw_przed_kodami, 'id="kw-import"' ) && false !== strpos( $kw_ustawienia, '<summary>Dla osoby, która buduje stronę: kody do wstawienia</summary>' ) && 3 === substr_count( (string) strstr( $kw_ustawienia, '<details class="kw-ust-kody">' ), '[kw_cennik' ) + substr_count( (string) strstr( $kw_ustawienia, '<details class="kw-ust-kody">' ), '[kw_formularz]' ), (string) strlen( $kw_przed_kodami ) );
	$sprawdz( 'menu "Poprawki" (osobna wtyczka) dostaje pozycje tuz pod "Usługi" (21 < pozycja < 22), bez zaleznosci miedzy wtyczkami', 21 < (float) apply_filters( 'iwc_menu_position', 26 ) && (float) apply_filters( 'iwc_menu_position', 26 ) < 22 && 21 === (int) get_post_type_object( 'kw_usluga' )->menu_position );
	$sprawdz( 'Ustawienia: przy "Pokazuj ceny" stoi uwaga, ze kwoty wpisane recznie w listach zostaja na stronie', false !== strpos( $kw_ustawienia, 'Kwoty wpisane ręcznie w listach usługi' ) && false !== strpos( $kw_ustawienia, 'zostają na stronie' ), (string) strlen( $kw_ustawienia ) );
	$sprawdz( 'kreator: kategoria z archiwum nie stoi na liscie wyboru', $arch_id > 0 && false === strpos( $kreator, 'Samotest archiwum' ) );
	wp_set_object_terms( $szkic, array( $arch_id ), 'kw_grupa' );
	kw_uslugi_kategorie( true );
	ob_start();
	kw_uslugi_kreator( get_post( $szkic ) );
	$kreator2 = (string) ob_get_clean();
	$sprawdz( 'kreator: kategoria z archiwum zostaje na liscie, gdy usluga juz ja ma (z dopiskiem)', false !== strpos( $kreator2, 'Samotest archiwum (archiwum)</option>' ) && 1 === preg_match( '~<option value="' . $arch_id . '"\s+selected~', $kreator2 ), substr( $kreator2, (int) strpos( $kreator2, 'id="kw-grupa"' ), 500 ) );
	wp_set_object_terms( $szkic, array(), 'kw_grupa' );
	wp_delete_term( $arch_id, 'kw_grupa' ); // wlasna kategoria testowa
	kw_uslugi_kategorie( true );
	$sprawdz( 'podglad obok kreatora: karta cennika z bazy i znacznik "Szkic", znaczniki z nazwy unieszkodliwione', false !== strpos( $podglad, 'data-kw-podglad' ) && false !== strpos( $podglad, 'kw-c__karta' ) && false !== strpos( $podglad, 'Szkic: nie widać jej na stronie' ) && false === strpos( $podglad, '<script>alert(4)' ) );
	$dane_js = (string) wp_json_encode( kw_uslugi_kreator_dane( get_post( $szkic ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	$sprawdz( 'dane dla skryptu kreatora: bez znakow < i > (nazwa uslugi nie zamknie znacznika script)', false === strpos( $dane_js, '<' ) && false === strpos( $dane_js, '>' ) && false !== strpos( $dane_js, '"typowe"' ), substr( $dane_js, 0, 200 ) );
	$kw_typowe  = kw_uslugi_kreator_typowe_progi();
	$kw_dane_js = kw_uslugi_kreator_dane( get_post( $szkic ) );
	$kw_spolki  = kw_uslugi_kategorie( true )['spolki']['id'] ?? 0;
	$sprawdz( 'typowe progi: zestaw domyslny (6 progow do "141 i więcej") i wlasny dla kategorii "spolki" (7 progow ksiag: 1-10, 11-30 ... 201 i więcej)', array( '1-10', '11-20', '21-40', '41-80', '81-140', '141 i więcej' ) === $kw_typowe[''] && array( '1-10', '11-30', '31-60', '61-99', '100-150', '151-200', '201 i więcej' ) === $kw_typowe['spolki'] && $kw_typowe[''] === $kw_dane_js['typowe'] && ( ! $kw_spolki || $kw_typowe['spolki'] === ( $kw_dane_js['typoweKat'][ $kw_spolki ] ?? null ) ), wp_json_encode( $kw_dane_js['typoweKat'] ) );
	$komunikaty = apply_filters( 'post_updated_messages', array() );
	$sprawdz( 'komunikaty po zapisie uslugi sa po polsku i mowia o usludze', isset( $komunikaty['kw_usluga'][1], $komunikaty['kw_usluga'][6], $komunikaty['kw_usluga'][10] ) && 0 === strpos( $komunikaty['kw_usluga'][1], 'Usługa zapisana' ) && 0 === strpos( $komunikaty['kw_usluga'][10], 'Szkic zapisany' ) );

	echo "--- kreator 2.0: progi od-do, biblioteka (token, uprawnienia, sanityzacja, limity) ---\n";
	// Progi z kafla: skrypt sklada etykiete z pol "od" i "do" jako "od-do" albo "od i więcej"; serwer zapisuje je bez zmian.
	$_POST = array_merge(
		$bez_starszych,
		array(
			'kw_cena_typ'     => 'od',
			'kw_progi'        => array(
				array(
					'etykieta' => '1-10',
					'cena'     => '100',
				),
				array(
					'etykieta' => '11-25',
					'cena'     => '150,50',
				),
				array(
					'etykieta' => '26 i więcej',
					'cena'     => '200',
					'od'       => '1',
				),
			),
			'kw_usluga_nonce' => wp_create_nonce( 'kw_usluga_zapis' ),
		)
	);
	kw_uslugi_zapisz( $szkic );
	$_POST    = array();
	$kw_pr    = kw_uslugi_czysc_progi( get_post_meta( $szkic, 'kw_progi', true ) );
	$kw_pr_ok = array(
		array(
			'etykieta' => '1-10',
			'cena'     => '100',
			'od'       => 0,
		),
		array(
			'etykieta' => '11-25',
			'cena'     => '150.5',
			'od'       => 0,
		),
		array(
			'etykieta' => '26 i więcej',
			'cena'     => '200',
			'od'       => 1,
		),
	);
	$sprawdz( 'zapis progow z pol od/do: etykiety "1-10", "11-25", "26 i więcej" i ceny wracaja z bazy bez zmian', $kw_pr_ok === $kw_pr, wp_json_encode( $kw_pr, JSON_UNESCAPED_UNICODE ) );
	$sprawdz( 'odczyt zakresu z etykiety (jak w skrypcie): "11-25", "26 i więcej", "1 - 10"; inny zapis => null', array( 11, 25 ) === kw_uslugi_bib_zakres( '11-25' ) && array( 26, null ) === kw_uslugi_bib_zakres( '26 i więcej' ) && array( 1, 10 ) === kw_uslugi_bib_zakres( '1 - 10' ) && null === kw_uslugi_bib_zakres( 'dużo' ) && null === kw_uslugi_bib_zakres( '201+' ) );
	ob_start();
	kw_uslugi_kreator( get_post( $szkic ) );
	$kreator3 = (string) ob_get_clean();
	$sprawdz( 'kreator 2.0: szesc kafli z przyciskiem w naglowku, pasek gorny z Biblioteka i Zapisz (bez JS ukryty), kafel progow tylko przy cenie liczbowej', 6 === substr_count( $kreator3, '<section class="kf ' ) && 6 === substr_count( $kreator3, 'data-kw-kafel-glowa' ) && 1 === preg_match( '~data-kw-pasek-gora hidden>~', $kreator3 ) && false !== strpos( $kreator3, 'data-kw-bib-otworz' ) && 1 === preg_match( '~id="kf-progi"[^>]*data-kw-typy="stala od przedzial"~', $kreator3 ) && false !== strpos( $kreator3, 'name="kw_progi[2][etykieta]" value="26 i więcej"' ), substr( $kreator3, 0, 300 ) );

	// Biblioteka: zasiew przy pierwszym uzyciu (pozycje z uslug, w tym ze szkicu testowego, i typowe progi), bez autoload.
	delete_option( KW_USLUGI_BIB_OPCJA );
	wp_cache_delete( 'alloptions', 'options' );
	$kw_bib = kw_uslugi_bib_pobierz();
	wp_cache_delete( 'alloptions', 'options' );
	$sprawdz( 'biblioteka: pierwsze uzycie zasiewa opcje (bez autoload): pozycje list z uslug, typowe progi (domyslne i spolki), wersja 1', false !== get_option( KW_USLUGI_BIB_OPCJA ) && ! array_key_exists( KW_USLUGI_BIB_OPCJA, wp_load_alloptions() ) && in_array( 'pozycja 1', $kw_bib['w_cenie'], true ) && in_array( 'typowe', wp_list_pluck( $kw_bib['progi'], 'id' ), true ) && in_array( 'typowe-spolki', wp_list_pluck( $kw_bib['progi'], 'id' ), true ) && array( array( 1, 10 ), array( 11, 20 ), array( 21, 40 ), array( 41, 80 ), array( 81, 140 ), array( 141, null ) ) === $kw_bib['progi'][0]['zakresy'] && null === $kw_bib['progi'][0]['ceny'] && 1 === $kw_bib['wersja'], wp_json_encode( $kw_bib, JSON_UNESCAPED_UNICODE ) );
	$kw_dane2 = kw_uslugi_kreator_dane( get_post( $szkic ) );
	$sprawdz( 'dane dla skryptu: biblioteka, token biblioteki i adres admin-ajax', isset( $kw_dane2['biblioteka']['w_cenie'], $kw_dane2['bibToken'], $kw_dane2['ajax'] ) && false !== strpos( $kw_dane2['ajax'], 'admin-ajax.php' ) && wp_verify_nonce( $kw_dane2['bibToken'], 'kw_uslugi_bib' ) );
	$sprawdz( 'biblioteka: akcje AJAX tylko dla zalogowanych (bez wp_ajax_nopriv_)', has_action( 'wp_ajax_kw_uslugi_bib_dodaj', 'kw_uslugi_bib_ajax_dodaj' ) && has_action( 'wp_ajax_kw_uslugi_bib_usun', 'kw_uslugi_bib_ajax_usun' ) && ! has_action( 'wp_ajax_nopriv_kw_uslugi_bib_dodaj' ) && ! has_action( 'wp_ajax_nopriv_kw_uslugi_bib_usun' ) );
	$kw_t = wp_create_nonce( 'kw_uslugi_bib' );
	$kw_w = kw_uslugi_bib_zmien( 'dodaj', 'w_cenie', 'Samotest pozycja', 'zlytoken' );
	$sprawdz( 'biblioteka: zly token => nic sie nie dopisuje', 'token' === $kw_w['kod'] && ! in_array( 'Samotest pozycja', kw_uslugi_bib_pobierz()['w_cenie'], true ), wp_json_encode( $kw_w ) );
	wp_set_current_user( 0 );
	$kw_w = kw_uslugi_bib_zmien( 'dodaj', 'w_cenie', 'Samotest pozycja', wp_create_nonce( 'kw_uslugi_bib' ) );
	$sprawdz( 'biblioteka: poprawny token, ale uzytkownik bez prawa edycji uslug => nic sie nie dopisuje', 'prawa' === $kw_w['kod'] && ! in_array( 'Samotest pozycja', kw_uslugi_bib_pobierz()['w_cenie'], true ), wp_json_encode( $kw_w ) );
	wp_set_current_user( $admin );
	$kw_w  = kw_uslugi_bib_zmien( 'dodaj', 'w_cenie', "  Samotest <script>alert(1)</script><b>pozycja</b>\n druga linia ", $kw_t );
	$kw_w2 = kw_uslugi_bib_zmien( 'dodaj', 'w_cenie', 'Samotest pozycja druga linia', $kw_t );
	$kw_l  = kw_uslugi_bib_pobierz()['w_cenie'];
	$sprawdz( 'biblioteka: dopisanie z sanityzacja (bez znacznikow, jeden wiersz), ta sama pozycja nie dopisuje sie drugi raz', 'ok' === $kw_w['kod'] && 'Samotest pozycja druga linia' === $kw_w['tekst'] && 'ok' === $kw_w2['kod'] && 1 === count( array_keys( $kw_l, 'Samotest pozycja druga linia', true ) ) && in_array( 'Samotest pozycja druga linia', $kw_w2['biblioteka']['w_cenie'], true ), wp_json_encode( array( $kw_w['tekst'] ?? '', $kw_l ), JSON_UNESCAPED_UNICODE ) );
	$kw_w = kw_uslugi_bib_zmien( 'dodaj', 'czynniki', str_repeat( 'ż', 250 ), $kw_t );
	$sprawdz( 'biblioteka: pozycja dluzsza niz 200 znakow skrocona do 200', 'ok' === $kw_w['kod'] && 200 === mb_strlen( $kw_w['tekst'] ), (string) mb_strlen( (string) ( $kw_w['tekst'] ?? '' ) ) );
	kw_uslugi_bib_zmien( 'usun', 'czynniki', str_repeat( 'ż', 200 ), $kw_t );
	$sprawdz( 'biblioteka: pusta pozycja, nieznana lista i nieznana akcja odrzucone', 'pusto' === kw_uslugi_bib_zmien( 'dodaj', 'w_cenie', " <b></b> \n", $kw_t )['kod'] && 'rodzaj' === kw_uslugi_bib_zmien( 'dodaj', 'kw_w_cenie', 'x', $kw_t )['kod'] && 'akcja' === kw_uslugi_bib_zmien( 'skasuj', 'w_cenie', 'x', $kw_t )['kod'] && 'pusto' === kw_uslugi_bib_zmien( 'dodaj', 'w_cenie', array( 'x' ), $kw_t )['kod'] );
	$kw_w = kw_uslugi_bib_zmien( 'usun', 'w_cenie', 'Samotest pozycja druga linia', $kw_t );
	$sprawdz( 'biblioteka: usuniecie pozycji', 'ok' === $kw_w['kod'] && ! in_array( 'Samotest pozycja druga linia', kw_uslugi_bib_pobierz()['w_cenie'], true ) );
	$kw_pelna              = kw_uslugi_bib_pobierz();
	$kw_pelna['dodatkowo'] = array_map(
		static function ( $i ) {
			return 'Samotest ' . $i;
		},
		range( 1, KW_USLUGI_BIB_POZYCJE + 5 )
	);
	kw_uslugi_bib_zapisz( $kw_pelna );
	$kw_w = kw_uslugi_bib_zmien( 'dodaj', 'dodatkowo', 'Samotest ponad limit', $kw_t );
	$sprawdz( 'biblioteka: lista ma najwyzej ' . KW_USLUGI_BIB_POZYCJE . ' pozycji, pelna lista nie przyjmuje nowej', KW_USLUGI_BIB_POZYCJE === count( kw_uslugi_bib_pobierz()['dodatkowo'] ) && 'pelna' === $kw_w['kod'], wp_json_encode( array( count( kw_uslugi_bib_pobierz()['dodatkowo'] ), $kw_w['kod'] ) ) );
	$kw_zestaw = (string) wp_json_encode(
		array(
			'id'      => 'podrobione',
			'nazwa'   => '<i>Samotest</i> zestaw',
			'zakresy' => array( array( 1, 10 ), array( '11', null ) ),
			'ceny'    => array( '100', '200,5 zł' ),
		)
	);
	$kw_w      = kw_uslugi_bib_zmien( 'dodaj', 'progi', $kw_zestaw, $kw_t );
	$kw_w2     = kw_uslugi_bib_zmien( 'dodaj', 'progi', $kw_zestaw, $kw_t );
	$kw_z      = array_values(
		array_filter(
			kw_uslugi_bib_pobierz()['progi'],
			static function ( $z ) use ( $kw_w ) {
				return $z['id'] === ( $kw_w['id'] ?? '' );
			}
		)
	);
	$sprawdz( 'biblioteka: zestaw progow z cenami (id nadaje serwer, nazwa bez znacznikow, kwoty jak w polu ceny), ten sam zestaw drugi raz sie nie dopisuje', 'ok' === $kw_w['kod'] && 'podrobione' !== $kw_w['id'] && $kw_w['id'] === $kw_w2['id'] && 1 === count( $kw_z ) && 'Samotest zestaw' === $kw_z[0]['nazwa'] && array( array( 1, 10 ), array( 11, null ) ) === $kw_z[0]['zakresy'] && array( '100', '200.5' ) === $kw_z[0]['ceny'], wp_json_encode( array( $kw_w['kod'], $kw_w['id'] ?? '', $kw_z ), JSON_UNESCAPED_UNICODE ) );
	$sprawdz( 'biblioteka: bledny zestaw (koniec przed poczatkiem, bez zakresow, nie JSON) odrzucony', 'pusto' === kw_uslugi_bib_zmien( 'dodaj', 'progi', '{"zakresy":[[10,5]]}', $kw_t )['kod'] && 'pusto' === kw_uslugi_bib_zmien( 'dodaj', 'progi', '{"zakresy":[]}', $kw_t )['kod'] && 'pusto' === kw_uslugi_bib_zmien( 'dodaj', 'progi', 'x', $kw_t )['kod'] );
	$kw_w = kw_uslugi_bib_zmien( 'usun', 'progi', $kw_w['id'] ?? '', $kw_t );
	$sprawdz( 'biblioteka: usuniecie zestawu progow po id', 'ok' === $kw_w['kod'] && ! in_array( $kw_w['id'], wp_list_pluck( kw_uslugi_bib_pobierz()['progi'], 'id' ), true ) );
	update_option( KW_USLUGI_BIB_OPCJA, '{"w_cenie":["a","a",{"x":1},"<b>b</b>"],"progi":[{"zakresy":[[5,1]]},{"id":"ok","zakresy":[[1,null]]}],"obce":1}', false );
	$kw_bib = kw_uslugi_bib_pobierz();
	$sprawdz( 'biblioteka: smieci w opcji (powtorzenia, obiekty, znaczniki, bledne zestawy, obce klucze) nie przechodza do skryptu', array( 'a', 'b' ) === $kw_bib['w_cenie'] && array() === $kw_bib['dodatkowo'] && 1 === count( $kw_bib['progi'] ) && 'ok' === $kw_bib['progi'][0]['id'] && ! isset( $kw_bib['obce'] ), wp_json_encode( $kw_bib, JSON_UNESCAPED_UNICODE ) );
	if ( false === $kw_bib_na_start ) {
		delete_option( KW_USLUGI_BIB_OPCJA );
	} else {
		update_option( KW_USLUGI_BIB_OPCJA, $kw_bib_na_start, false );
	}
	$sprawdz( 'biblioteka przywrocona do stanu sprzed testu', get_option( KW_USLUGI_BIB_OPCJA ) === $kw_bib_na_start );
	wp_set_current_user( 0 );
}
remove_filter( 'kw_uslugi_lista', $dopisz );
remove_filter( 'pre_option_kw_uslugi_opcje', $wlacz );
wp_delete_post( $szkic, true ); // wlasny szkic testowy, nigdy nieopublikowany
$sprawdz( 'szkic testowy usuniety, w cenniku nie ma po nim sladu', null === get_post( $szkic ) && false === strpos( do_shortcode( '[kw_cennik]' ), 'kw-samotest' ) );

/* ===========================================================================
 * 3. Formularz: punkt kw/v1/kontakt
 * ======================================================================== */

$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // adres z puli dokumentacyjnej
$kw_adresy_testowe      = array( '203.0.113.7', '203.0.113.8', '203.0.113.9', '203.0.113.10' );
foreach ( $kw_adresy_testowe as $ip ) {
	delete_transient( kw_uslugi_klucz_limitu( $ip ) );
}
// Licznik laczny formularza jest prawdziwy (wspolny dla calej strony): zapamietany, wyzerowany na czas testu, na koncu przywrocony.
$kw_lacznie_przed = get_transient( KW_USLUGI_KLUCZ_LACZNY );
delete_transient( KW_USLUGI_KLUCZ_LACZNY );
wp_set_current_user( 0 );

$dobre = static function ( array $zmiany = array() ) {
	$czas = time() - 10;
	return array_merge(
		array(
			'imie'      => 'Jan Testowy',
			'email'     => 'jan@example.com',
			'telefon'   => '600 100 200',
			'usluga'    => 'kpir',
			'wiadomosc' => 'Samotest ' . wp_generate_password( 8, false ) . ": proszę o wycenę.\nDruga linia.",
			'kw_x'      => '',
			't'         => $czas,
			's'         => kw_uslugi_podpis( $czas ),
		),
		$zmiany
	);
};
$wyslij = static function ( array $dane, $nonce = null, array $zapytanie = array(), $referer = null ) {
	$zadanie = new WP_REST_Request( 'POST', '/kw/v1/kontakt' );
	$zadanie->set_header( 'Content-Type', 'application/json' );
	$zadanie->set_header( 'Referer', null === $referer ? home_url( '/kontakt/?usluga=kpir' ) : $referer );
	if ( false !== $nonce ) {
		$zadanie->set_header( 'X-WP-Nonce', null === $nonce ? wp_create_nonce( 'wp_rest' ) : $nonce );
	}
	$zadanie->set_query_params( $zapytanie );
	$zadanie->set_body( wp_json_encode( $dane ) );
	$odp = rest_do_request( $zadanie );
	return array( $odp->get_status(), (array) $odp->get_data(), $odp->get_headers() );
};

echo "--- token i pulapki ---\n";
list( $s, $d ) = $wyslij( $dobre(), false );
$sprawdz( 'brak tokenu => 403', 403 === $s && 'kw_brak_tokenu' === ( $d['code'] ?? '' ), wp_json_encode( array( $s, $d ) ) );
list( $s, $d ) = $wyslij( $dobre(), 'zlytoken123' );
$sprawdz( 'zly token => 403', 403 === $s && 'kw_zly_token' === ( $d['code'] ?? '' ), wp_json_encode( array( $s, $d ) ) );
list( $s, $d ) = $wyslij( $dobre( array( 'kw_x' => 'http://spam.example' ) ) );
$sprawdz( 'pulapka na boty => 400 odrzucono', 400 === $s && false === $d['ok'] && 'odrzucono' === $d['kod'], wp_json_encode( array( $s, $d ) ) );
list( $s, $d ) = $wyslij( $dobre( array( 's' => str_repeat( 'a', 32 ) ) ) );
$sprawdz( 'podrobiony podpis czasu => 403 token', 403 === $s && 'token' === $d['kod'], wp_json_encode( array( $s, $d ) ) );
$teraz         = time();
list( $s, $d ) = $wyslij( $dobre( array( 't' => $teraz, 's' => kw_uslugi_podpis( $teraz ) ) ) );
$sprawdz( 'wysylka szybsza niz 3 s => 403 za_szybko', 403 === $s && 'za_szybko' === $d['kod'], wp_json_encode( array( $s, $d ) ) );
$stary         = time() - DAY_IN_SECONDS - 60;
list( $s, $d ) = $wyslij( $dobre( array( 't' => $stary, 's' => kw_uslugi_podpis( $stary ) ) ) );
$sprawdz( 'poprawny podpis, ale starszy niz doba => 403 token (para czas + podpis nie dziala bez konca)', 403 === $s && 'token' === $d['kod'], wp_json_encode( array( $s, $d ) ) );

echo "--- walidacja ---\n";
list( $s, $d ) = $wyslij( $dobre( array( 'imie' => ' ', 'email' => 'zly', 'wiadomosc' => 'krotka' ) ) );
$sprawdz( 'puste imie, zly e-mail, krotka wiadomosc => 422 z trzema polami', 422 === $s && 'walidacja' === $d['kod'] && array( 'imie', 'email', 'wiadomosc' ) === array_keys( $d['pola'] ), wp_json_encode( array( $s, $d ) ) );
list( $s, $d ) = $wyslij( $dobre( array( 'email' => "jan@example.com\r\nBcc: ofiara@example.org" ) ) );
$sprawdz( 'wstrzykniecie naglowka w polu e-mail => 422', 422 === $s && isset( $d['pola']['email'] ), wp_json_encode( array( $s, $d ) ) );
$kw_zle_adresy = array();
foreach ( array( 'a%40evil.example?subject=Pilne&body=tekst@example.com', 'jan?subject=x@example.com', 'jan&cc=x@example.com', 'jan=x@example.com', 'jan%0A@example.com' ) as $kw_zly ) {
	list( $s, $d ) = $wyslij( $dobre( array( 'email' => $kw_zly ) ) );
	if ( 422 !== $s || ! isset( $d['pola']['email'] ) ) {
		$kw_zle_adresy[] = $kw_zly . ' => ' . $s;
	}
}
$sprawdz( 'adres e-mail z parametrami odnosnika mailto (znaki % ? & =) => 422', array() === $kw_zle_adresy, wp_json_encode( $kw_zle_adresy ) );
list( $s, $d ) = $wyslij( $dobre( array( 'telefon' => '<script>' ) ) );
$sprawdz( 'telefon ze znacznikiem => 422', 422 === $s && isset( $d['pola']['telefon'] ), wp_json_encode( array( $s, $d ) ) );
list( $s, $d ) = $wyslij( $dobre( array( 'wiadomosc' => str_repeat( 'a', 5001 ) ) ) );
$sprawdz( 'wiadomosc ponad 5000 znakow => 422', 422 === $s && isset( $d['pola']['wiadomosc'] ), wp_json_encode( array( $s, $d ) ) );
list( $s, $d ) = $wyslij( $dobre( array( 'dokumenty' => "11-20\r\nBcc: x@example.org", 'pracownicy' => '<script>' ) ) );
$sprawdz( 'liczba dokumentow i pracownikow spoza bialej listy => 422 przy obu polach', 422 === $s && array( 'dokumenty', 'pracownicy' ) === array_keys( $d['pola'] ), wp_json_encode( array( $s, $d ) ) );
$sprawdz( 'odrzucone proby nie wyslaly poczty i nie zapisaly wpisow', 0 === count( $kw_poczta ) && 0 === count( $kw_wpisy ) );

echo "--- poprawna wysylka ---\n";
$trudna        = "Samotest: 5%de < 3 > 2 <script>alert(1)</script> \"cudzysłów\" i 'apostrof' \\ ukośnik.\nImię: Podszyty\nBcc: ofiara@example.org";
$pierwsza      = $dobre( array( 'wiadomosc' => $trudna, 'imie' => "Jan <b>Testowy</b>\r\nBcc: x@example.org" ) );
list( $s, $d ) = $wyslij( $pierwsza, null, array( 'kw_test_awarii' => '1' ) );
$sprawdz( 'poprawna wiadomosc (gosc, takze z ?kw_test_awarii=1) => 200 ok:true', 200 === $s && true === $d['ok'], wp_json_encode( array( $s, $d ) ) );
$mail = $kw_poczta[0] ?? array();
$sprawdz( 'e-mail idzie na adres z opcji', kw_uslugi_opcje()['email'] === ( $mail['to'] ?? '' ), wp_json_encode( $mail['to'] ?? null ) );
$sprawdz( 'temat: "Wiadomość ze strony: KPiR" (nazwa z bazy, bez tekstu goscia)', 'Wiadomość ze strony: KPiR' === ( $mail['subject'] ?? '' ), (string) ( $mail['subject'] ?? '' ) );
$sprawdz( 'naglowki: tylko Reply-To z adresem', array( 'Reply-To: jan@example.com' ) === ( $mail['headers'] ?? null ), wp_json_encode( $mail['headers'] ?? null ) );
$tresc = (string) ( $mail['message'] ?? '' );
$sprawdz( 'tresc ma imie, e-mail, telefon, usluge, wiadomosc, strone, date', false !== strpos( $tresc, 'E-mail: jan@example.com' ) && false !== strpos( $tresc, 'Telefon: 600 100 200' ) && false !== strpos( $tresc, 'Usługa: KPiR' ) && false !== strpos( $tresc, '5%de < 3 > 2 <script>alert(1)</script>' ) && false !== strpos( $tresc, 'Strona: ' . home_url( '/kontakt/' ) ) && (bool) preg_match( '/Data: \d\d\.\d\d\.\d{4} \d\d:\d\d/', $tresc ), $tresc );
$sprawdz( 'pola wyceny niewybrane => w tresci "nie podano"', false !== strpos( $tresc, "Usługa: KPiR\nLiczba dokumentów w miesiącu: nie podano\nLiczba pracowników: nie podano\n\nWiadomość:\n" ), $tresc );
$sprawdz( 'imie w tresci splaszczone do jednego wiersza (bez nowej linii od goscia)', false !== strpos( $tresc, 'Imię: Jan <b>Testowy</b> Bcc: x@example.org' . "\n" ), $tresc );
$id = (int) array_key_last( $kw_wpisy );
$sprawdz( 'kopia w panelu: wpis kw_wiadomosc, status private', $id && 'kw_wiadomosc' === get_post_type( $id ) && 'private' === get_post_status( $id ), (string) $id );
$sprawdz( 'kopia: tresc wierna (znaki specjalne nie gina), oznaczona jako wyslana', $trudna === get_post_meta( $id, '_kw_tresc', true ) && '1' === get_post_meta( $id, '_kw_wyslano', true ), wp_json_encode( get_post_meta( $id, '_kw_tresc', true ) ) );
$sprawdz( 'tytul wpisu bez znacznikow HTML', false === strpos( get_post_field( 'post_title', $id ), '<' ), get_post_field( 'post_title', $id ) );
$sprawdz( 'kopia ma wlasny losowy slug "w-" + 16 znakow (rdzen nie szuka wolnego sluga petla zapytan)', 1 === preg_match( '/^w-[a-z0-9]{16}$/', (string) get_post_field( 'post_name', $id ) ), (string) get_post_field( 'post_name', $id ) );

list( $s, $d ) = $wyslij( $pierwsza );
$sprawdz( 'ta sama wiadomosc ponowiona => ok:true bez drugiego e-maila i drugiego wpisu', 200 === $s && true === $d['ok'] && 1 === count( $kw_poczta ) && 1 === count( $kw_wpisy ), count( $kw_poczta ) . ' maili, ' . count( $kw_wpisy ) . ' wpisow' );

echo "--- awaria poczty ---\n";
$kw_dziala     = false;
$druga         = $dobre( array( 'usluga' => 'nie-ma-takiej', 'telefon' => '' ) );
list( $s, $d ) = $wyslij( $druga );
$id2           = (int) array_key_last( $kw_wpisy );
$sprawdz( 'poczta zawodzi => 200 ok:false kod wysylka (bez szczegolow bledu)', 200 === $s && array( 'ok' => false, 'kod' => 'wysylka' ) === $d, wp_json_encode( array( $s, $d ) ) );
$sprawdz( 'mimo awarii kopia jest w panelu, oznaczona jako NIE wyslana, z opisem bledu dla administratora', $id2 !== $id && '0' === get_post_meta( $id2, '_kw_wyslano', true ) && 'Symulowana awaria poczty' === get_post_meta( $id2, '_kw_blad', true ) && 'zapytanie ogólne' === get_post_meta( $id2, '_kw_usluga', true ), (string) $id2 );
$kw_dziala     = true;
list( $s, $d ) = $wyslij( $druga );
$sprawdz( 'ponowienie po naprawie poczty => ok:true, ten sam wpis oznaczony jako wyslany', 200 === $s && true === $d['ok'] && (int) array_key_last( $kw_wpisy ) === $id2 && '1' === get_post_meta( $id2, '_kw_wyslano', true ) && 2 === count( $kw_wpisy ), wp_json_encode( array( $s, $d, count( $kw_wpisy ) ) ) );
$sprawdz( 'nieznana usluga => temat "zapytanie ogólne"', 'Wiadomość ze strony: zapytanie ogólne' === $kw_poczta[1]['subject'], $kw_poczta[1]['subject'] );

echo "--- tryb testowy awarii ---\n";
$przed = array( count( $kw_poczta ), count( $kw_wpisy ) );
wp_set_current_user( 1 );
list( $s, $d ) = $wyslij( $dobre(), null, array( 'kw_test_awarii' => '1' ) );
$sprawdz( 'administrator + kw_test_awarii=1 => 200 ok:false kod wysylka test:true', 200 === $s && false === $d['ok'] && 'wysylka' === $d['kod'] && true === $d['test'], wp_json_encode( array( $s, $d ) ) );
$sprawdz( 'tryb testowy nic nie wysyla i nic nie zapisuje', array( count( $kw_poczta ), count( $kw_wpisy ) ) === $przed );
wp_set_current_user( 0 );

echo "--- limit 5 na godzine z jednego adresu IP ---\n";
$licznik = get_transient( kw_uslugi_klucz_limitu( '203.0.113.7' ) );
$sprawdz( 'licznik po 3 przyjetych wiadomosciach = 3', 3 === ( $licznik['n'] ?? 0 ), wp_json_encode( $licznik ) );
list( $s4 ) = $wyslij( $dobre( array( 'usluga' => 'inna' ) ) );
list( $s5 ) = $wyslij( $dobre( array( 'usluga' => '' ) ) );
list( $s, $d, $naglowki ) = $wyslij( $dobre() );
$sprawdz( 'czwarta i piata przechodza, szosta => 429 limit z Retry-After', 200 === $s4 && 200 === $s5 && 429 === $s && 'limit' === $d['kod'] && ! empty( $naglowki['Retry-After'] ), wp_json_encode( array( $s4, $s5, $s, $d, $naglowki ) ) );
$sprawdz( 'usluga "inna" => temat "Inna / nie wiem"', 'Wiadomość ze strony: Inna / nie wiem' === $kw_poczta[2]['subject'], $kw_poczta[2]['subject'] );
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
list( $s )              = $wyslij( $dobre() );
$sprawdz( 'inny adres IP ma wlasny licznik => 200', 200 === $s, (string) $s );
$kw_lacznie = get_transient( KW_USLUGI_KLUCZ_LACZNY );
$kw_suma    = 0;
foreach ( $kw_adresy_testowe as $ip ) {
	$kw_suma += (int) ( ( (array) get_transient( kw_uslugi_klucz_limitu( $ip ) ) )['n'] ?? 0 );
}
$sprawdz( 'licznik laczny (wszystkie adresy razem) liczy kazda przyjeta wiadomosc: 6 = suma licznikow adresow; odrzucone przez limit nie licza sie', 6 === $kw_suma && $kw_suma === (int) ( $kw_lacznie['n'] ?? -1 ), wp_json_encode( array( $kw_suma, $kw_lacznie ) ) );
set_transient(
	KW_USLUGI_KLUCZ_LACZNY,
	array(
		'n'  => KW_USLUGI_LIMIT_LACZNY,
		'do' => time() + 600,
	),
	600
);
$_SERVER['REMOTE_ADDR']   = '203.0.113.10'; // swiezy adres: jego wlasny limit jest wolny
$kw_ile                   = array( count( $kw_poczta ), count( $kw_wpisy ) );
list( $s, $d, $naglowki ) = $wyslij( $dobre() );
$sprawdz( 'limit laczny wyczerpany (40 na godzine) => 429 limit z Retry-After takze dla nowego adresu; nic nie wyslano, nic nie zapisano, licznik adresu nietkniety', 429 === $s && 'limit' === $d['kod'] && ! empty( $naglowki['Retry-After'] ) && array( count( $kw_poczta ), count( $kw_wpisy ) ) === $kw_ile && false === get_transient( kw_uslugi_klucz_limitu( '203.0.113.10' ) ) && 40 === KW_USLUGI_LIMIT_LACZNY, wp_json_encode( array( $s, $d, $naglowki ) ) );
set_transient( KW_USLUGI_KLUCZ_LACZNY, $kw_lacznie, 600 ); // dalsze testy wysylaja kolejne wiadomosci
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
list( $s ) = $wyslij( $dobre(), null, array(), untrailingslashit( home_url() ) . '.obcy.example/kontakt/' );
$obcy      = (int) array_key_last( $kw_wpisy );
$sprawdz( 'podrobiony Referer (obca domena o podobnym poczatku) => w kopii adres strony glownej', 200 === $s && home_url( '/' ) === get_post_meta( $obcy, '_kw_strona', true ), (string) get_post_meta( $obcy, '_kw_strona', true ) );

echo "--- pola wyceny w e-mailu, kopii i panelu ---\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$ile_maili              = count( $kw_poczta );
list( $s, $d )          = $wyslij( $dobre( array( 'dokumenty' => 'ponad-140', 'pracownicy' => 'brak' ) ) );
$wycena                 = (int) array_key_last( $kw_wpisy );
$tresc                  = (string) ( $kw_poczta[ $ile_maili ]['message'] ?? '' );
$sprawdz( 'wartosci z bialej listy => 200, w e-mailu etykiety z serwera ("powyżej 140", "0")', 200 === $s && true === $d['ok'] && false !== strpos( $tresc, "Liczba dokumentów w miesiącu: powyżej 140\nLiczba pracowników: 0\n" ), $tresc );
$sprawdz( 'kopia w panelu ma oba pola', 'powyżej 140' === get_post_meta( $wycena, '_kw_dokumenty', true ) && '0' === get_post_meta( $wycena, '_kw_pracownicy', true ), wp_json_encode( array( get_post_meta( $wycena, '_kw_dokumenty', true ), get_post_meta( $wycena, '_kw_pracownicy', true ) ) ) );

echo "--- widocznosc kopii i ceny ---\n";
$typ = get_post_type_object( 'kw_wiadomosc' );
$sprawdz( 'kw_wiadomosc: niepubliczny, poza wyszukiwarka, bez REST, bez adresow', ! $typ->public && ! $typ->publicly_queryable && $typ->exclude_from_search && ! $typ->show_in_rest && ! $typ->rewrite && ! $typ->query_var );
$trasy = array_keys( rest_get_server()->get_routes() );
$sprawdz( 'REST nie ma trasy dla kw_wiadomosc', ! preg_grep( '~kw_wiadomosc~', $trasy ) );
$sprawdz( 'gosc nie widzi kopii w zapytaniach', 0 === count( get_posts( array( 'post_type' => 'kw_wiadomosc', 'post_status' => array( 'publish', 'private' ), 'perm' => 'readable', 'suppress_filters' => false ) ) ) );
$sprawdz( 'gosc nie ma prawa czytac ani edytowac kopii', ! current_user_can( 'read_post', $id ) && ! current_user_can( 'edit_post', $id ) && ! current_user_can( 'edit_posts' ) );
$u1   = get_page_by_path( 'kpir', OBJECT, 'kw_usluga' );
$rest = static function ( $trasa ) {
	$odp = rest_do_request( new WP_REST_Request( 'GET', $trasa ) );
	return array( $odp->get_status(), (array) $odp->get_data() );
};
list( $s1 ) = $rest( '/wp/v2/kw_usluga' );
list( $s2 ) = $rest( '/wp/v2/kw_usluga/' . ( $u1 ? $u1->ID : 0 ) );
list( $s1w ) = $rest( '/wp/v2/KW_USLUGA' );
list( $s2w ) = $rest( '/wp/v2/Kw_Usluga/' . ( $u1 ? $u1->ID : 0 ) );
list( $s3k ) = $rest( '/wp/v2/kw_grupa' );
$kw_kat_zywa = current( kw_uslugi_kategorie( true ) );
list( $s4k ) = $rest( '/wp/v2/KW_GRUPA/' . (int) ( is_array( $kw_kat_zywa ) ? $kw_kat_zywa['id'] : 0 ) );
$sprawdz( 'REST: wielkosc liter w adresie nie omija blokady uslug (KW_USLUGA, Kw_Usluga/<id> => 401)', 401 === $s1w && 401 === $s2w, wp_json_encode( array( $s1w, $s2w ) ) );
$sprawdz( 'REST: gosc nie czyta kategorii uslug, takze archiwalnych (kw_grupa, KW_GRUPA/<id> => 401)', 401 === $s3k && 401 === $s4k, wp_json_encode( array( $s3k, $s4k ) ) );
$sprawdz( 'REST uslug: gosc nie czyta ani listy, ani pojedynczej uslugi (401); uslugi widzi tylko na stronie', 401 === $s1 && 401 === $s2, wp_json_encode( array( $s1, $s2 ) ) );
wp_set_current_user( 1 );
list( $s3, $d3 ) = $rest( '/wp/v2/kw_usluga/' . ( $u1 ? $u1->ID : 0 ) );
wp_set_current_user( 0 );
$sprawdz( 'REST uslug: administrator (panel, most MCP) czyta usluge z polami ceny i progami', 200 === $s3 && isset( $d3['meta']['kw_cena_typ'], $d3['meta']['kw_progi'], $d3['meta']['kw_w_cenniku'] ), wp_json_encode( array( $s3, array_keys( (array) ( $d3['meta'] ?? array() ) ) ) ) );
if ( is_admin() || function_exists( 'kw_uslugi_wiadomosc_skrzynka' ) || ( require_once KW_USLUGI_KATALOG . 'includes/admin.php' ) ) {
	wp_set_current_user( 1 );
	ob_start();
	kw_uslugi_wiadomosc_skrzynka( get_post( $id ) );
	$html = (string) ob_get_clean();
	ob_start();
	kw_uslugi_wiadomosc_skrzynka( get_post( $wycena ) );
	$html2 = (string) ob_get_clean();
	wp_set_current_user( 0 );
	$sprawdz( 'panel: tresc wiadomosci wyswietlana przez esc_html (znacznik script nieaktywny)', false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && false === strpos( $html, '<b>Testowy' ), substr( $html, 0, 600 ) );
	$sprawdz( 'panel: skrzynka wiadomosci pokazuje liczbe dokumentow i pracownikow', false !== strpos( $html2, '<dt>Liczba dokumentów w miesiącu</dt><dd>powyżej 140</dd>' ) && false !== strpos( $html2, '<dt>Liczba pracowników</dt><dd>0</dd>' ) && false !== strpos( $html, '<dt>Liczba pracowników</dt><dd>nie podano</dd>' ), substr( $html2, 0, 900 ) );
}

echo "--- narzedzia prywatnosci: eksport i usuwanie wiadomosci po adresie e-mail ---\n";
$kw_adres               = 'samotest-' . strtolower( wp_generate_password( 12, false ) ) . '@example.com'; // adres jednorazowy: usuwanie nie dotknie zadnej prawdziwej wiadomosci
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
list( $s )              = $wyslij( $dobre( array( 'email' => $kw_adres ) ) );
$kw_pryw                = (int) array_key_last( $kw_wpisy );
$kw_eksporterzy         = (array) apply_filters( 'wp_privacy_personal_data_exporters', array() );
$kw_usuwacze            = (array) apply_filters( 'wp_privacy_personal_data_erasers', array() );
$kw_eksport             = isset( $kw_eksporterzy['kw-uslugi']['callback'] ) ? (array) call_user_func( $kw_eksporterzy['kw-uslugi']['callback'], $kw_adres, 1 ) : array();
$kw_pola                = array();
foreach ( (array) ( $kw_eksport['data'][0]['data'] ?? array() ) as $kw_wiersz ) {
	$kw_pola[ $kw_wiersz['name'] ] = $kw_wiersz['value'];
}
$sprawdz( 'eksport danych osobowych (Narzedzia WordPressa): wiadomosc z tego adresu z imieniem, adresem, telefonem i trescia', 200 === $s && 1 === count( (array) ( $kw_eksport['data'] ?? array() ) ) && 'kw-wiadomosc-' . $kw_pryw === ( $kw_eksport['data'][0]['item_id'] ?? '' ) && true === ( $kw_eksport['done'] ?? null ) && $kw_adres === ( $kw_pola['E-mail'] ?? '' ) && 'Jan Testowy' === ( $kw_pola['Imię'] ?? '' ) && '600 100 200' === ( $kw_pola['Telefon'] ?? '' ) && isset( $kw_pola['Wiadomość'], $kw_pola['Data'] ), wp_json_encode( array( $s, $kw_eksport ) ) );
$kw_obcy = isset( $kw_eksporterzy['kw-uslugi']['callback'] ) ? (array) call_user_func( $kw_eksporterzy['kw-uslugi']['callback'], 'nikt-' . $kw_adres, 1 ) : array( 'data' => null );
$sprawdz( 'eksport dla innego adresu i dla pustego adresu jest pusty', array() === $kw_obcy['data'] && array() === kw_uslugi_prywatnosc_wpisy( '' ) && array() === kw_uslugi_prywatnosc_wpisy( ' ' ), wp_json_encode( $kw_obcy ) );
$kw_usun = isset( $kw_usuwacze['kw-uslugi']['callback'] ) ? (array) call_user_func( $kw_usuwacze['kw-uslugi']['callback'], $kw_adres, 1 ) : array();
$sprawdz( 'usuwanie danych osobowych: wiadomosc z tego adresu znika razem z polami, pozostale wiadomosci zostaja', true === ( $kw_usun['items_removed'] ?? null ) && false === ( $kw_usun['items_retained'] ?? null ) && true === ( $kw_usun['done'] ?? null ) && null === get_post( $kw_pryw ) && '' === (string) get_post_meta( $kw_pryw, '_kw_email', true ) && null !== get_post( $id ) && 'jan@example.com' === get_post_meta( $id, '_kw_email', true ), wp_json_encode( $kw_usun ) );
unset( $kw_wpisy[ $kw_pryw ] );

echo "--- okres przechowywania wiadomosci z formularza (retencja) ---\n";
// Sprzatanie dziala w tescie tylko na wlasnych wpisach (filtr kw_uslugi_retencja_ids); dziennik i harmonogram wracaja do stanu sprzed testu.
$kw_r_dziennik_przed = get_option( KW_USLUGI_RETENCJA_DZIENNIK );
$kw_r_policz         = static function () {
	return count(
		get_posts(
			array(
				'post_type'        => 'kw_wiadomosc',
				'post_status'      => array( 'private', 'publish', 'draft', 'pending', 'trash' ),
				'posts_per_page'   => 500,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		)
	);
};
$kw_r_bylo           = $kw_r_policz();
$kw_r_nowa           = static function ( $miesiecy_temu ) {
	$czas = strtotime( '-' . (int) $miesiecy_temu . ' months -2 days' );
	return (int) wp_insert_post(
		array(
			'post_type'     => 'kw_wiadomosc',
			'post_status'   => 'private',
			'post_title'    => 'Samotest retencji ' . (int) $miesiecy_temu,
			'post_date'     => wp_date( 'Y-m-d H:i:s', $czas ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $czas ),
			'meta_input'    => array(
				'_kw_email' => 'retencja-samotest@example.com',
				'_kw_tresc' => 'Samotest retencji',
			),
		)
	);
};
$kw_r_stara          = $kw_r_nowa( 13 );
$kw_r_kosz           = $kw_r_nowa( 14 );
$kw_r_zachowana      = $kw_r_nowa( 13 );
$kw_r_prawie         = $kw_r_nowa( 11 );
$kw_r_swieza         = $kw_r_nowa( 0 );
$kw_r_moje           = array( $kw_r_stara, $kw_r_kosz, $kw_r_zachowana, $kw_r_prawie, $kw_r_swieza );
wp_trash_post( $kw_r_kosz );
$kw_r_filtr = static function ( $ids ) use ( $kw_r_moje ) {
	return array_values( array_intersect( array_map( 'intval', (array) $ids ), $kw_r_moje ) );
};
add_filter( 'kw_uslugi_retencja_ids', $kw_r_filtr );
$kw_r_12 = static function () {
	return array( 'retencja' => 12 );
};
$kw_r_0  = static function () {
	return array( 'retencja' => 0 );
};

wp_set_current_user( 0 );
$kw_r_gosc = kw_uslugi_zachowaj_ustaw( $kw_r_zachowana, '1', wp_create_nonce( 'kw_zachowaj_' . $kw_r_zachowana . '_1' ) );
wp_set_current_user( 1 );
$kw_r_kody = array(
	$kw_r_gosc,
	kw_uslugi_zachowaj_ustaw( $kw_r_zachowana, '1', 'zlytoken' ),
	kw_uslugi_zachowaj_ustaw( 0, '1', wp_create_nonce( 'kw_zachowaj_0_1' ) ),
	kw_uslugi_zachowaj_ustaw( 1, '1', wp_create_nonce( 'kw_zachowaj_1_1' ) ), // wpis nr 1 to nie wiadomosc z formularza
	kw_uslugi_zachowaj_ustaw( $kw_r_zachowana, 'x', wp_create_nonce( 'kw_zachowaj_' . $kw_r_zachowana . '_x' ) ),
);
$sprawdz( '"Zachowaj (klient)": gosc => prawa, zly token => token, nie-wiadomosc => brak, nieznany stan => pole; znacznika nadal nie ma', array( 'prawa', 'token', 'brak', 'brak', 'pole' ) === $kw_r_kody && ! metadata_exists( 'post', $kw_r_zachowana, '_kw_zachowaj' ), wp_json_encode( $kw_r_kody ) );
$kw_r_ok1 = kw_uslugi_zachowaj_ustaw( $kw_r_swieza, '1', wp_create_nonce( 'kw_zachowaj_' . $kw_r_swieza . '_1' ) );
$kw_r_ma  = metadata_exists( 'post', $kw_r_swieza, '_kw_zachowaj' );
$kw_r_ok0 = kw_uslugi_zachowaj_ustaw( $kw_r_swieza, '0', wp_create_nonce( 'kw_zachowaj_' . $kw_r_swieza . '_0' ) );
$kw_r_ok2 = kw_uslugi_zachowaj_ustaw( $kw_r_zachowana, '1', wp_create_nonce( 'kw_zachowaj_' . $kw_r_zachowana . '_1' ) );
$sprawdz( '"Zachowaj (klient)": administrator oznacza i zdejmuje oznaczenie (znacznik we wpisie pojawia sie i znika)', array( 'ok', 'ok', 'ok' ) === array( $kw_r_ok1, $kw_r_ok0, $kw_r_ok2 ) && $kw_r_ma && ! metadata_exists( 'post', $kw_r_swieza, '_kw_zachowaj' ) && metadata_exists( 'post', $kw_r_zachowana, '_kw_zachowaj' ) );

add_filter( 'pre_option_kw_uslugi_opcje', $kw_r_0 );
$kw_r_granica0 = kw_uslugi_retencja_granica();
$kw_r_wynik0   = kw_uslugi_retencja_usun();
remove_filter( 'pre_option_kw_uslugi_opcje', $kw_r_0 );
$sprawdz( 'okres 0 miesiecy = nie usuwaj: brak granicy, sprzatanie niczego nie rusza (takze wpisu sprzed 14 miesiecy w koszu)', '' === $kw_r_granica0 && 0 === $kw_r_wynik0 && null !== get_post( $kw_r_stara ) && 'trash' === get_post_status( $kw_r_kosz ) );

add_filter( 'pre_option_kw_uslugi_opcje', $kw_r_12 );
$kw_r_granica = kw_uslugi_retencja_granica();
$kw_r_wynik   = kw_uslugi_retencja_usun();
$kw_r_dz      = get_option( KW_USLUGI_RETENCJA_DZIENNIK );
$kw_r_ost     = is_array( $kw_r_dz ) && ! empty( $kw_r_dz['wpisy'] ) ? end( $kw_r_dz['wpisy'] ) : array();
$sprawdz( 'okres 12 miesiecy: wiadomosc sprzed 13 miesiecy i wiadomosc sprzed 14 miesiecy z kosza znikaja na stale (razem z polami)', 2 === $kw_r_wynik && null === get_post( $kw_r_stara ) && null === get_post( $kw_r_kosz ) && '' === (string) get_post_meta( $kw_r_stara, '_kw_email', true ) && $kw_r_granica < wp_date( 'Y-m-d H:i:s', strtotime( '-11 months' ) ), wp_json_encode( array( $kw_r_wynik, $kw_r_granica ) ) );
$sprawdz( 'zostaja: wiadomosc sprzed 11 miesiecy, swieza oraz stara oznaczona "Zachowaj (klient)"', 'kw_wiadomosc' === get_post_type( $kw_r_prawie ) && 'kw_wiadomosc' === get_post_type( $kw_r_swieza ) && 'kw_wiadomosc' === get_post_type( $kw_r_zachowana ) );
$sprawdz( 'dziennik sprzatania: data przebiegu i wpis z liczba usunietych (2)', is_array( $kw_r_dz ) && abs( time() - (int) ( $kw_r_dz['ostatnio'] ?? 0 ) ) < 120 && 2 === (int) ( $kw_r_ost['usuniete'] ?? 0 ) && abs( time() - (int) ( $kw_r_ost['czas'] ?? 0 ) ) < 120, wp_json_encode( $kw_r_dz ) );
$kw_r_wpisow = count( (array) $kw_r_dz['wpisy'] );
$kw_r_drugi  = kw_uslugi_retencja_usun();
$kw_r_dz2    = get_option( KW_USLUGI_RETENCJA_DZIENNIK );
$sprawdz( 'drugi przebieg nie ma czego usuwac i nie dopisuje wpisu do dziennika', 0 === $kw_r_drugi && $kw_r_wpisow === count( (array) $kw_r_dz2['wpisy'] ) );
$sprawdz( 'termin znikniecia: swieza wiadomosc ma date (dzien.miesiac.rok, za 12 miesiecy), zachowana nie ma zadnej', 1 === preg_match( '/^\d\d\.\d\d\.\d{4}$/', kw_uslugi_retencja_termin( $kw_r_swieza ) ) && wp_date( 'd.m.Y', strtotime( '+12 months', get_post_timestamp( $kw_r_swieza ) ) ) === kw_uslugi_retencja_termin( $kw_r_swieza ) && '' === kw_uslugi_retencja_termin( $kw_r_zachowana ), kw_uslugi_retencja_termin( $kw_r_swieza ) );

ob_start();
kw_uslugi_wiadomosc_skrzynka( get_post( $kw_r_swieza ) );
$kw_r_html1 = (string) ob_get_clean();
ob_start();
kw_uslugi_wiadomosc_skrzynka( get_post( $kw_r_zachowana ) );
$kw_r_html2 = (string) ob_get_clean();
ob_start();
kw_uslugi_strona_ustawien();
$kw_r_ust   = (string) ob_get_clean();
$kw_r_akcje = kw_uslugi_wiadomosci_akcje( array( 'edit' => 'x', 'inline hide-if-no-js' => 'y' ), get_post( $kw_r_swieza ) );
$sprawdz( 'ekran wiadomosci: zdanie "Zniknie sama po <data>" z odnosnikiem "Zachowaj (klient)"; przy zachowanej "nie zniknie sama" i "Nie zachowuj"', false !== strpos( $kw_r_html1, 'Zniknie sama po ' . kw_uslugi_retencja_termin( $kw_r_swieza ) ) && false !== strpos( $kw_r_html1, '>Zachowaj (klient)</a>' ) && false !== strpos( $kw_r_html1, 'action=kw_uslugi_zachowaj' ) && false !== strpos( $kw_r_html2, 'Zachowana (klient): nie zniknie sama.' ) && false !== strpos( $kw_r_html2, '>Nie zachowuj</a>' ), substr( $kw_r_html1, -700 ) );
$sprawdz( 'lista wiadomosci: kolumna "Zniknie sama" i akcja wiersza "Zachowaj (klient)" z tokenem', 'Zniknie sama' === ( kw_uslugi_wiadomosci_kolumny()['kw_retencja'] ?? '' ) && isset( $kw_r_akcje['kw_zachowaj'] ) && false !== strpos( $kw_r_akcje['kw_zachowaj'], '_wpnonce=' ) && ! isset( $kw_r_akcje['inline hide-if-no-js'] ) && 'nie: zachowana (klient)' === kw_uslugi_retencja_opis( $kw_r_zachowana ), wp_json_encode( array_keys( $kw_r_akcje ) ) );
$sprawdz( 'Ustawienia: pole liczby miesiecy (12), zdanie objasnienia i licznik "Najstarsza wiadomość: <data>"', false !== strpos( $kw_r_ust, 'name="kw_uslugi_opcje[retencja]"' ) && 1 === preg_match( '/name="kw_uslugi_opcje\[retencja\]"[^>]*value="12"/', $kw_r_ust ) && false !== strpos( $kw_r_ust, '0 = niczego nie usuwaj.' ) && 1 === preg_match( '/Najstarsza wiadomość: \d\d\.\d\d\.\d{4} \(zniknie po \d\d\.\d\d\.\d{4}\)\./u', $kw_r_ust ) && false !== strpos( $kw_r_ust, 'Ostatnie usuwanie: ' . wp_date( 'd.m.Y' ) . ', usunięto: 2.' ), substr( $kw_r_ust, (int) strpos( $kw_r_ust, 'kw-opcja-retencja-stan' ), 300 ) );
remove_filter( 'pre_option_kw_uslugi_opcje', $kw_r_12 );
$kw_r_dobre = array(
	'ceny'    => 1,
	'email'   => 'biuro@example.com',
	'telefon' => '600 100 200',
);
$sprawdz( 'Ustawienia, zapis okresu: liczba od 0 do 120 (18 => 18, 500 => 120, -3 => 0, tekst => 0), brak pola = poprzednia wartosc', 18 === kw_uslugi_czysc_opcje( $kw_r_dobre + array( 'retencja' => '18' ) )['retencja'] && 120 === kw_uslugi_czysc_opcje( $kw_r_dobre + array( 'retencja' => '500' ) )['retencja'] && 0 === kw_uslugi_czysc_opcje( $kw_r_dobre + array( 'retencja' => '-3' ) )['retencja'] && 0 === kw_uslugi_czysc_opcje( $kw_r_dobre + array( 'retencja' => 'abc' ) )['retencja'] && kw_uslugi_opcje()['retencja'] === kw_uslugi_czysc_opcje( $kw_r_dobre )['retencja'], (string) kw_uslugi_opcje()['retencja'] );

kw_uslugi_retencja_plan();
$kw_r_plan1 = wp_next_scheduled( KW_USLUGI_RETENCJA_AKCJA );
$kw_r_co    = wp_get_schedule( KW_USLUGI_RETENCJA_AKCJA );
kw_uslugi_dezaktywacja();
$kw_r_plan2 = wp_next_scheduled( KW_USLUGI_RETENCJA_AKCJA );
kw_uslugi_retencja_plan(); // zadanie wraca do harmonogramu, jak przy kazdym starcie strony
$sprawdz( 'WP-Cron: zadanie sprzatania jest w harmonogramie raz na dobe, dezaktywacja wtyczki je zdejmuje, start strony planuje je ponownie', false !== $kw_r_plan1 && 'daily' === $kw_r_co && false === $kw_r_plan2 && false !== wp_next_scheduled( KW_USLUGI_RETENCJA_AKCJA ) && false !== has_action( KW_USLUGI_RETENCJA_AKCJA, 'kw_uslugi_retencja_usun' ), wp_json_encode( array( $kw_r_plan1, $kw_r_co, $kw_r_plan2 ) ) );

remove_filter( 'kw_uslugi_retencja_ids', $kw_r_filtr );
foreach ( $kw_r_moje as $kw_r_id ) {
	if ( 'kw_wiadomosc' === get_post_type( $kw_r_id ) ) {
		wp_delete_post( $kw_r_id, true ); // wlasne wpisy testowe
	}
	unset( $kw_wpisy[ $kw_r_id ] );
}
if ( false === $kw_r_dziennik_przed ) {
	delete_option( KW_USLUGI_RETENCJA_DZIENNIK );
} else {
	update_option( KW_USLUGI_RETENCJA_DZIENNIK, $kw_r_dziennik_przed, false );
}
$sprawdz( 'retencja: wpisy testowe usuniete, dziennik jak przed testem, liczba prawdziwych wiadomosci bez zmian', $kw_r_bylo === $kw_r_policz() && $kw_r_dziennik_przed === get_option( KW_USLUGI_RETENCJA_DZIENNIK ), $kw_r_bylo . ' / ' . $kw_r_policz() );
wp_set_current_user( 0 );

// Testy listy uslug i kategorii: osobny plik, bo includes/admin-lista.php ma wlasnego opiekuna.
if ( is_readable( __DIR__ . '/samotest-lista.php' ) ) {
	require __DIR__ . '/samotest-lista.php';
}
// Testy importu i eksportu cen (includes/admin-import.php).
if ( is_readable( __DIR__ . '/samotest-import.php' ) ) {
	require __DIR__ . '/samotest-import.php';
}

echo "--- sprzatanie ---\n";
foreach ( array_keys( $kw_wpisy ) as $wpis ) {
	wp_delete_post( $wpis, true );
}
foreach ( $kw_adresy_testowe as $ip ) {
	delete_transient( kw_uslugi_klucz_limitu( $ip ) );
}
// Licznik laczny wraca do stanu sprzed testu (wiadomosci testowe nie zjadaja limitu prawdziwym klientom).
if ( is_array( $kw_lacznie_przed ) && ! empty( $kw_lacznie_przed['do'] ) && $kw_lacznie_przed['do'] > time() ) {
	set_transient( KW_USLUGI_KLUCZ_LACZNY, $kw_lacznie_przed, max( 1, $kw_lacznie_przed['do'] - time() ) );
} else {
	delete_transient( KW_USLUGI_KLUCZ_LACZNY );
}
echo 'usunieto wpisow testowych: ' . count( $kw_wpisy ) . "\n";
echo "WYNIK: {$kw_wynik['ok']} OK, {$kw_wynik['zle']} BLEDOW\n";
