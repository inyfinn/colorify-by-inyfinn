<?php
/**
 * Test modułu "Edytor treści" (docs/edytor-tresci.md): kadr, parsowanie, normalizacja, sanitizacja formularza, bramka wersji.
 * Działa na schemacie testowym (filtr podmienia rejestr), nie dotyka opcji strony.
 *
 * Uruchamiany wewnątrz WordPressa (wtyczka musi być załadowana):
 *   wp eval-file tools/test-tresci.php
 * Kod wyjścia 0 tylko gdy wszystko przeszło.
 *
 * @package ColorifyByInyfinn
 */

if ( ! function_exists( 'colorify_kadr_css' ) ) {
	fwrite( STDERR, "Uruchom wewnatrz WordPressa z zaladowana wtyczka Colorify (wp eval-file).\n" );
	exit( 2 );
}

$fail = array();
// Rejestr jest cache'owany przy pierwszym użyciu: schemat testowy musi wejść PRZED pierwszym wywołaniem funkcji rejestru.
$wlasny = (bool) has_filter( 'colorify_tresci_rejestr' );
$ok   = static function ( $cond, $msg ) use ( &$fail ) {
	if ( ! $cond ) {
		$fail[] = $msg;
	}
};

// Kadr: domyślny = pusty CSS; zoom = scale + clip-path liczony z f = 1 - 1/z.
$def = array( 'x' => 50, 'y' => 40, 'z' => 1 );
$ok( '' === colorify_kadr_css( $def, $def ), 'kadr domyślny daje pusty CSS' );
$ok( 'object-position:30% 40%;' === colorify_kadr_css( array( 'x' => 30, 'y' => 40, 'z' => 1 ), $def ), 'kadr bez zoomu: tylko object-position' );
$css = colorify_kadr_css( array( 'x' => 30, 'y' => 20, 'z' => 1.6 ), $def );
$ok( 'object-position:30% 20%;transform:scale(1.6);transform-origin:30% 20%;clip-path:inset(7.5% 26.25% 30% 11.25%);' === $css, 'kadr z zoomem 1.6: ' . $css );
$k = colorify_tresci_kadr_z_tekstu( '130,-5,9' );
$ok( 100 === $k['x'] && 0 === $k['y'] && 2.5 === $k['z'], 'kadr z tekstu: clamp 0-100 i 1-2.5' );
$ok( null === colorify_tresci_kadr_z_tekstu( 'abc' ), 'kadr z tekstu: śmieci = null' );

// Normalizacja akapitów: &nbsp;, spacje i warianty <br> nie są różnicą.
$ok( colorify_tresci_norm( 'A&nbsp;b <br/> c' ) === colorify_tresci_norm( "A b<br>c" ), 'norm: nbsp, spacje, br' );
$ok( "Linia 1<br>Linia 2" === colorify_tresci_z_edycji( "Linia 1\nLinia 2" ), 'z_edycji: nowa linia = <br>' );
$ok( "Ak 1\n\nAk 2" === colorify_tresci_z_edycji( 'Ak 1<br><br>Ak 2', true ), 'z_edycji multi: <br><br> = akapit' );
$ok( '&lt;3 <em>x</em>a' === colorify_tresci_z_edycji( '<3 <em>x</em><script>a</script>' ), 'z_edycji: "<3" jako tekst, tag script wycięty (tekst zostaje, jak w wp_kses)' );

// Schemat testowy (nie rusza rejestru strony: osobna, lokalna mapa pól przez filtr tylko w tym procesie).
$schemat = array(
	'sekcja' => array(
		'title'  => 'Test',
		'fields' => array(
			'tytul'   => colorify_tresci_pole( 'text', 'Tytuł', 'Domyślny', '', array( 'req' => true ) ),
			'akapit'  => colorify_tresci_pole( 'area', 'Akapit', 'Tekst&nbsp;domyślny.<br>Druga.' ),
			'link'    => colorify_tresci_pole( 'url', 'Link', 'https://example.com/' ),
			'foto'    => colorify_tresci_pole( 'media', 'Zdjęcie', 'img:a.jpg', '', array( 'kind' => 'image', 'kadr' => array( 'r' => '4/3', 'rm' => '1/1', 'xy' => array( 50, 50 ) ), 'removable' => true ) ),
			'punkty'  => colorify_tresci_pole( 'punkty', 'Punkty', array( array( 't' => 'Jeden' ), array( 't' => 'Dwa' ) ) ),
		),
	),
);
$pola = array();
foreach ( $schemat['sekcja']['fields'] as $key => $f ) {
	$f['section'] = 'sekcja';
	$pola[ $key ] = $f;
}
$pola['foto__kadr'] = array( 'type' => 'kadr', 'label' => 'Zdjęcie: kadr', 'default' => colorify_tresci_kadr_domyslny( $pola['foto'] ), 'hint' => '', 'section' => 'sekcja', 'dla' => 'foto', 'kadr' => $pola['foto']['kadr'] );
// z_formularza na schemacie testowym (tylko gdy motyw nie ma własnego schematu; rejestr jest jeden na proces).
if ( ! $wlasny ) {
	add_filter( 'colorify_tresci_rejestr', static function ( $r ) use ( $schemat ) { return array( 'sekcje' => $schemat, 'plik_url' => static function ( $ref ) { return 'img:a.jpg' === $ref ? 'https://example.com/a.jpg' : ''; } ) + (array) $r; } );
	$ok( isset( colorify_tresci_listy()['punkty'] ), 'wbudowany typ listy punkty' );
	$new = colorify_tresci_z_formularza( array( 'tytul' => 'Domyślny', 'akapit' => "Tekst domyślny.\nDruga.", 'link' => 'javascript:alert(1)', 'foto' => '', 'foto__kadr' => '50,50,1', 'punkty' => array( array( 't' => 'Jeden' ), array( 't' => 'Dwa' ) ) ), array() );
	$ok( array() === $new, 'formularz równy domyślnym (i niebezpieczny URL) nie zapisuje nic: ' . wp_json_encode( $new ) );
	$new = colorify_tresci_z_formularza( array( 'tytul' => '', 'akapit' => 'Nowy <b>tekst</b>', 'foto' => '-', 'foto__kadr' => '30,20,1.5', 'punkty' => '[{"t":"Trzy"},{"t":""}]' ), array() );
	$ok( ! isset( $new['tytul'] ), 'puste pole wymagane = domyślne' );
	$ok( 'Nowy tekst' === ( $new['akapit'] ?? null ), 'akapit: niedozwolony <b> wycięty: ' . ( $new['akapit'] ?? 'brak' ) );
	$ok( '' === ( $new['foto'] ?? null ), 'zdjęcie usunięte celowo = pusty string' );
	$ok( array( 'x' => 30, 'y' => 20, 'z' => 1.5 ) == ( $new['foto__kadr'] ?? null ), 'kadr zapisany' ); // phpcs:ignore WordPress.PHP.StrictComparisons
	$ok( array( array( 't' => 'Trzy' ) ) === ( $new['punkty'] ?? null ), 'lista z JSON, pusty wiersz pominięty' );
	$ok( 'Domyślny' === colorify_tresc( 'tytul' ) && 'https://example.com/a.jpg' === colorify_tresc_media( 'foto' ), 'odczyt domyślnych i plik_url' );
} else {
	fwrite( STDOUT, "Strona ma własny schemat: pomijam test z_formularza na schemacie testowym.\n" );
}

// Bramka wersji: inna wersja bazowa = WP_Error bez zapisu.
$w  = colorify_tresci_wersja( array( 'a' => 1 ) );
$ok( 12 === strlen( $w ) && $w === colorify_tresci_wersja( array( 'a' => 1 ) ) && $w !== colorify_tresci_wersja( array( 'a' => 2 ) ), 'wersja: stabilny skrót' );
$ok( array( 'b' ) === colorify_tresci_roznica( array( 'a' => 1, 'b' => 1 ), array( 'a' => 1, 'b' => 2 ) ), 'roznica: tylko zmienione klucze' );
$r = colorify_tresci_zapisz( array( 'x' => 1 ), 'test', 'test', 'zla-wersja00' );
$ok( is_wp_error( $r ) && 'konflikt' === $r->get_error_code(), 'zapis ze złą wersją = konflikt, bez zapisu' );

// 2.13: wygląd tylko z allowlisty tokenów, normalizacja układu, akapity jako bloki.
$ok( 'clamp(1.75rem, 1.4rem + 1.6vw, 2.75rem)' === colorify_tresci_styl_css( 'fz', 'xl' ), 'token rozmiaru -> clamp' );
$ok( '' === colorify_tresci_styl_css( 'fz', '99px' ) && '' === colorify_tresci_styl_css( 'c', 'red;background:url(x)' ), 'wartość spoza allowlisty = nic' );
$ok( '1.55' === colorify_tresci_styl_css( 'lh', '1.53' ) && '2.2' === colorify_tresci_styl_css( 'lh', '9' ) && '' === colorify_tresci_styl_css( 'lh', 'x' ), 'interlinia: krok 0,05, zakres 1–2,2' );
$s = colorify_tresci_style_z(
	array(
		'pole_a'      => array( 'b' => array( 'fz' => 'xl', 'zle' => 'x', 'c' => 'red' ), 'm' => array( 'pt' => 'xl' ) ),
		'#sek'        => array( 'b' => array( 'pt' => 'lg', 'fz' => 'l' ) ),
		'x"><script>' => array( 'b' => array( 'fz' => 'l' ) ),
		'#s-abcdef12' => array( 'b' => array( 'pt' => 'lg' ) ),
	),
	array()
);
$ok( array( '#sek' => array( 'b' => array( 'pt' => 'lg' ) ), 'pole_a' => array( 'b' => array( 'fz' => 'xl' ) ) ) === $s, 'style_z: tylko znane cele, właściwości i tokeny; sekcja tylko odstępy; styl usuniętej sekcji wypada' );
$fa = array( 'z_pol' => array( 'stare1' => 'a' ), 'warianty' => array( 'a' => array( 'A', 'ka' ), 'b' => array( 'B', 'kb' ) ) );
$bl = colorify_tresci_akapity_z( array( '_' => '1', 'stare1' => array( 'w' => 'b' ), 'p-abc123' => array( 'w' => 'a', 't' => 'Tekst <script>x</script><em>k</em>' ), 'p-pusty1' => array( 't' => '' ), 'zle' => array( 't' => 'x' ) ), $fa );
$ok( 2 === count( $bl ) && array( 'id' => 'stare1', 'w' => 'b' ) === $bl[0] && 'p-abc123' === $bl[1]['id'] && false === strpos( $bl[1]['t'], '<script' ) && false !== strpos( $bl[1]['t'], '<em>k</em>' ), 'akapity: stare pole jako odwołanie, nowy z tekstem po kses, puste i obce id wypadają' );
if ( isset( colorify_tresci_uklad_def()['home']['hero'] ) ) { // motyw z rejestrem układu (np. elarisse)
	$dom = colorify_tresci_uklad_norm( 'home', array(), array() )['o'];
	$n   = colorify_tresci_uklad_norm( 'home', array( 'o' => array( 'faq', 'nieznana', 'hero', 'faq' ), 'h' => array( 'hero', 'faq' ) ), array() );
	$ok( 'hero' === $n['o'][0] && 'faq' === $n['o'][1] && count( $n['o'] ) === count( $dom ) && array( 'faq' ) === $n['h'], 'układ: stała na górze, nieznane i powtórzone id wypadają, brakujące wracają, stałej nie da się ukryć' );
}
// 2.14: przełączniki zmienia tylko właściciel (tu: bieżący użytkownik WP-CLI bez --user = nikt).
if ( ! colorify_jest_wlascicielem() ) {
	$przed = get_option( COLORIFY_TRESCI_ZAAW_OPTION, 'no' );
	update_option( COLORIFY_TRESCI_ZAAW_OPTION, 'test-nie-wlasciciel' );
	$po = get_option( COLORIFY_TRESCI_ZAAW_OPTION, 'no' );
	$ok( $przed === $po, 'przełącznik od nie-właściciela odrzucony' );
	if ( $przed !== $po ) {
		update_option( COLORIFY_TRESCI_ZAAW_OPTION, $przed );
	}
}
$ok( ! colorify_jest_wlascicielem( 0 ), 'właściciel: gość nie' );

if ( $fail ) {
	fwrite( STDERR, "NIEPOWODZENIA:\n - " . implode( "\n - ", $fail ) . "\n" );
	exit( 1 );
}
fwrite( STDOUT, "test-tresci: OK\n" );
exit( 0 );
