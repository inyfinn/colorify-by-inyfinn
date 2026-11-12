<?php
/**
 * Testy analityki Colorify na żywej stronie: wp eval-file tools/test-analytics.php
 * Pracuje na osobnych tabelach i opcjach (przestrzeń 'colorifytest'), sprząta po sobie także po błędzie.
 * Zakres: filtr botów (UA, HEAD, Accept, prefetch), urządzenia, normalizacja ścieżek i źródeł, UTM, HMAC i deduplikacja,
 * zakresy na granicach dni i stref, migracja starego licznika, jedno źródło na dzień, limity „Inne”, retencja,
 * dziennik odsłon (agregacja), przełączanie metody, API zapytań, akapit polityki prywatności, odinstalowanie.
 */

if ( ! defined( 'COLORIFY_ANALYTICS_LOADED' ) ) {
	require_once WP_PLUGIN_DIR . '/colorify-by-inyfinn/includes/colorify-analytics.php';
}

$GLOBALS['cft_n']    = 0;
$GLOBALS['cft_fail'] = 0;
function cft( $cond, string $name, $got = null ): void {
	++$GLOBALS['cft_n'];
	if ( $cond ) {
		WP_CLI::line( 'OK    ' . $name );
		return;
	}
	++$GLOBALS['cft_fail'];
	WP_CLI::line( 'BŁĄD  ' . $name . ( null !== $got ? '  => ' . wp_json_encode( $got, JSON_UNESCAPED_UNICODE ) : '' ) );
}

global $wpdb;
$A    = 'Colorify_Analytics';
$R    = 'Colorify_Analytics_Rules';
$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$tz   = wp_timezone();
$at   = static fn( string $local ) => ( new DateTimeImmutable( $local, $tz ) )->getTimestamp();
$CH   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
$HTML = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
$dir  = wp_upload_dir( null, false )['basedir'] . '/colorifytest-log';

Colorify_Analytics::$ns      = 'colorifytest';
Colorify_Analytics::$nocache = true;

try {
	Colorify_Analytics::install( array() );
	cft( 6 === count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'colorifytest_' ) . '%' ) ) ), 'instalacja: 6 tabel testowych' );

	/* ---------- filtr botów ---------- */
	$bots = array(
		'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
		'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0 Safari/537.36',
		'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
		'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
		'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
		'Mozilla/5.0 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot',
		'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ClaudeBot/1.0; +claudebot@anthropic.com',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 (compatible; Bytespider; spider-feedback@bytedance.com)',
		'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36',
		'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0 Mobile Safari/537.36 Chrome-Lighthouse',
		'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
		'WhatsApp/2.23.20.0 A',
		'curl/8.5.0',
		'Wget/1.21.3',
		'python-requests/2.31.0',
		'Go-http-client/2.0',
		'okhttp/4.12.0',
		'Mozilla/5.0',
		'',
	);
	$bad = array();
	foreach ( $bots as $ua ) {
		if ( 'bot' !== Colorify_Analytics::classify( array( 'method' => 'GET', 'ua' => $ua, 'accept' => $HTML, 'sec_purpose' => '', 'purpose' => '' ) ) ) {
			$bad[] = $ua;
		}
	}
	cft( ! $bad, 'boty: ' . count( $bots ) . ' UA rozpoznane jako boty', $bad );

	$humans = array(
		'chrome-win'     => $CH,
		'safari-iphone'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
		'firefox-and'    => 'Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0',
		'samsung-tablet' => 'Mozilla/5.0 (Linux; Android 13; SM-T870) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Safari/537.36',
		'edge-mac'       => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
		'fb-inapp'       => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/470.0.0.40.97;FBBV/600000000;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.5;FBSS/3;FBCR/;FBID/phone;FBLC/pl_PL;FBOP/5]',
		'instagram'      => 'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP2A.240805.005; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.81 Mobile Safari/537.36 Instagram 352.0.0.40.97 Android',
		'cubot'          => 'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
		'ipad'           => 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
		'opera'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 OPR/114.0.0.0',
	);
	$bad = array();
	foreach ( $humans as $k => $ua ) {
		if ( 'human' !== Colorify_Analytics::classify( array( 'method' => 'GET', 'ua' => $ua, 'accept' => $HTML, 'sec_purpose' => '', 'purpose' => '' ) ) ) {
			$bad[] = $k;
		}
	}
	cft( ! $bad, 'ludzie: ' . count( $humans ) . ' przeglądarek (w tym CUBOT, aplikacje FB/IG) jako ludzie', $bad );
	$base = array( 'method' => 'GET', 'ua' => $CH, 'accept' => $HTML, 'sec_purpose' => '', 'purpose' => '' );
	cft( 'bot' === Colorify_Analytics::classify( array( 'method' => 'HEAD' ) + $base ), 'HEAD = bot' );
	cft( 'bot' === Colorify_Analytics::classify( array( 'accept' => '*/*' ) + $base ), 'Accept bez text/html = bot' );
	cft( 'bot' === Colorify_Analytics::classify( array( 'accept' => '' ) + $base ), 'brak Accept = bot' );
	cft( 'prefetch' === Colorify_Analytics::classify( array( 'sec_purpose' => 'prefetch' ) + $base ), 'Sec-Purpose: prefetch = liczony prefetch' );
	cft( 'speculative' === Colorify_Analytics::classify( array( 'sec_purpose' => 'prefetch;prerender' ) + $base ), 'Sec-Purpose: prefetch;prerender = pominięty' );
	cft( 'speculative' === Colorify_Analytics::classify( array( 'sec_purpose' => 'prefetch;anonymous-client-ip' ) + $base ), 'Sec-Purpose: prefetch;anonymous-client-ip = pominięty' );
	cft( 'speculative' === Colorify_Analytics::classify( array( 'purpose' => 'prefetch  ' ) + $base ), 'Purpose: prefetch (bez Sec-Purpose) = pominięty' );
	cft( 'speculative' === Colorify_Analytics::classify( array( 'purpose' => '  prefetch' ) + $base ), 'X-Moz: prefetch = pominięty' );

	/* ---------- urządzenia ---------- */
	$dev = array(
		'mobile'  => array( $humans['safari-iphone'], $humans['firefox-and'], $humans['cubot'], $humans['instagram'] ),
		'tablet'  => array( $humans['samsung-tablet'], $humans['ipad'], 'Mozilla/5.0 (Android 13; Tablet; rv:132.0) Gecko/132.0 Firefox/132.0' ),
		'desktop' => array( $CH, $humans['edge-mac'], 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0' ),
		'unknown' => array( 'Mozilla/5.0 (PlayStation; PlayStation 5/2.26) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0 Safari/605.1.15' ),
	);
	$bad = array();
	foreach ( $dev as $want => $list ) {
		foreach ( $list as $ua ) {
			$got = Colorify_Analytics_Rules::device( $ua );
			if ( $got !== $want ) {
				$bad[] = $want . '<>' . $got . ': ' . substr( $ua, 0, 50 );
			}
		}
	}
	cft( ! $bad, 'urządzenia: telefon, tablet, komputer, nieustalone', $bad );

	/* ---------- ścieżki ---------- */
	$paths = array(
		'/Kategoria/Wesela/page/3/?utm_source=x&fbclid=abc' => '/kategoria/wesela/',
		'/blog/comment-page-2/'                             => '/blog/',
		'/2026/11'                                          => '/2026/11/',
		'//a//b'                                            => '/a/b/',
		'/%C5%9Alub/'                                       => '/ślub/',
		'/oferta/cennik.pdf?x=1'                            => '/oferta/cennik.pdf',
		'/'                                                 => '/',
	);
	$bad = array();
	foreach ( $paths as $in => $want ) {
		if ( Colorify_Analytics::norm_path( $in ) !== $want ) {
			$bad[ $in ] = Colorify_Analytics::norm_path( $in );
		}
	}
	cft( ! $bad, 'normalizacja ścieżek (bez parametrów, paginacji, wielkich liter)', $bad );
	cft( 191 === mb_strlen( Colorify_Analytics::norm_path( '/' . str_repeat( 'ą', 400 ) ) ), 'ścieżka przycięta do 191 znaków' );
	$e404 = array(
		'/Stara-Oferta?x=1'          => '/Stara-Oferta',
		'/wp-login.php'              => '',
		'/.env'                      => '',
		'/wp-content/uploads/x.jpg'  => '',
		'/cgi-bin/test'              => '',
		'/backup.sql.gz'             => '',
		'/realizacje/wesele-ani/'    => '/realizacje/wesele-ani/',
	);
	$bad = array();
	foreach ( $e404 as $in => $want ) {
		if ( Colorify_Analytics::norm_404( $in ) !== $want ) {
			$bad[ $in ] = Colorify_Analytics::norm_404( $in );
		}
	}
	cft( ! $bad, '404: ścieżka bez parametrów, skanery plików odrzucone', $bad );

	/* ---------- źródła ---------- */
	$src = array(
		array( 'www.google.pl', '', 'search', 'google.pl' ),
		array( 'l.facebook.com', '', 'social', 'facebook.com' ),
		array( 'lm.facebook.com', '', 'social', 'facebook.com' ),
		array( 't.co', '', 'social', 't.co' ),
		array( 'out.reddit.com', '', 'social', 'out.reddit.com' ),
		array( 'chatgpt.com', '', 'search', 'chatgpt.com' ),
		array( 'com.google.android.googlequicksearchbox', '', 'search', 'google.com' ),
		array( 'com.google.android.gm', '', 'referral', 'mail.google.com' ),
		array( 'weselnicy.pl', '', 'referral', 'weselnicy.pl' ),
		array( 'www.' . $site, '', 'internal', '' ),
		array( '', '', 'direct_unknown', '' ),
		array( '', 'fbclid', 'social', 'facebook.com' ),
		array( '', 'gclid', 'search', 'google.com' ),
		array( 'evil<host>', '', 'direct_unknown', '' ),
	);
	$bad = array();
	foreach ( $src as $s ) {
		$got = Colorify_Analytics_Rules::source( $s[0], $site, $s[1] );
		if ( $got !== array( $s[2], $s[3] ) ) {
			$bad[] = $s[0] . '/' . $s[1] . ' => ' . implode( ',', $got );
		}
	}
	cft( ! $bad, 'źródła: wyszukiwarki, social, aplikacje Androida, odnośniki, wewnętrzne, parametry kliknięć', $bad );
	cft( 'newsletter_listopad' === Colorify_Analytics_Rules::utm_value( 'Newsletter_Listopad' ) && '' === Colorify_Analytics_Rules::utm_value( 'a b' ) && '' === Colorify_Analytics_Rules::utm_value( str_repeat( 'a', 41 ) ) && '' === Colorify_Analytics_Rules::utm_value( '<x>' ), 'UTM: lista znaków i długość' );

	/* ---------- rejestr testowy: stary licznik do 30.06, licznik PHP od 01.07 ---------- */
	$reg            = Colorify_Analytics::registry();
	$reg['methods'] = array(
		array( 'method' => 'legacy', 'from' => '2026-06-01', 'to' => '2026-06-30', 'started' => '' ),
		array( 'method' => 'php1', 'from' => '2026-07-01', 'to' => '', 'started' => '2026-06-30 21:00' ),
	);
	$reg['legacy_final'] = false;
	Colorify_Analytics::save_registry( $reg );

	/* ---------- zapis odsłony: brak IP/UA/URL w tabelach, źródło bez ścieżki ---------- */
	$t1  = $at( '2026-07-01 10:15' );
	$req = array( 'ua' => $CH, 'accept' => $HTML, 'ip' => '203.0.113.7', 'uri' => '/o-mnie/?utm_source=Newsletter&utm_medium=email&fbclid=XYZ123', 'page' => '4', 'referer' => 'https://www.google.pl/search?q=kamerzysta+czestochowa', 'time' => $t1 );
	cft( 'human' === Colorify_Analytics::record( $req ), 'zapis: zwykła odsłona' );
	$dump = wp_json_encode( array_map( static fn( $t ) => $wpdb->get_results( 'SELECT * FROM ' . Colorify_Analytics::t( $t ), ARRAY_A ), array( 'daily_totals', 'daily_pages', 'daily_breakdowns', 'hourly_totals', 'daily_errors' ) ) );
	cft( false === strpos( $dump, '203.0.113' ) && false === strpos( $dump, 'kamerzysta' ) && false === stripos( $dump, 'Chrome' ) && false === strpos( $dump, 'XYZ123' ) && false === strpos( $dump, 'search?' ), 'w tabelach brak IP, UA, frazy, pełnego Referera i fbclid' );
	cft( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT views FROM ' . Colorify_Analytics::t( 'daily_breakdowns' ) . " WHERE day = %s AND page = '' AND dim = 'source_domain' AND val = 'google.pl'", '2026-07-01' ) ), 'źródło zapisane jako sama domena google.pl' );
	cft( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT views FROM ' . Colorify_Analytics::t( 'daily_breakdowns' ) . " WHERE day = %s AND dim = 'utm_source' AND val = 'newsletter'", '2026-07-01' ) ), 'utm_source zapisany po oczyszczeniu (newsletter)' );
	cft( '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT entries FROM ' . Colorify_Analytics::t( 'daily_pages' ) . " WHERE day = %s AND page = '4'", '2026-07-01' ) ), 'wejście z zewnątrz policzone przy stronie docelowej' );

	/* ---------- HMAC i deduplikacja ---------- */
	Colorify_Analytics::record( array( 'time' => $t1 + 60 ) + $req );
	Colorify_Analytics::record( array( 'time' => $t1 + 120, 'ua' => $humans['safari-iphone'] ) + $req );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT views, visitors FROM ' . Colorify_Analytics::t( 'daily_totals' ) . " WHERE day = %s AND method = 'php1'", '2026-07-01' ), ARRAY_A );
	cft( array( 'views' => '3', 'visitors' => '2' ) === $row, 'dedup: 3 odsłony, ten sam IP+UA raz, inna przeglądarka osobno', $row );
	cft( 16 === (int) $wpdb->get_var( 'SELECT MAX(LENGTH(h)) FROM ' . Colorify_Analytics::t( 'daily_seen' ) ), 'skrót w daily_seen ma 16 bajtów (bez strony, źródła, czasu)' );
	$s1 = (string) get_option( Colorify_Analytics::opt( 'secret_20260701' ) );
	Colorify_Analytics::record( array( 'time' => $t1 + DAY_IN_SECONDS ) + $req );
	$s2 = (string) get_option( Colorify_Analytics::opt( 'secret_20260702' ) );
	cft( 64 === strlen( $s1 ) && 64 === strlen( $s2 ) && $s1 !== $s2, 'klucz HMAC losowy i inny każdego dnia' );
	wp_cache_delete( Colorify_Analytics::opt( 'secret_20260701' ), 'options' );
	cft( false === get_option( Colorify_Analytics::opt( 'secret_20260701' ) ) && '0' === $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Colorify_Analytics::t( 'daily_seen' ) . ' WHERE day < %s', '2026-07-02' ) ), 'nowy dzień kasuje klucz i skróty poprzedniego dnia' );
	cft( '1' === $wpdb->get_var( "SELECT visitors FROM " . Colorify_Analytics::t( 'daily_totals' ) . " WHERE day = '2026-07-02' AND method = 'php1'" ), 'następnego dnia ta sama osoba liczy się od nowa (szacunek dzienny)' );

	/* ---------- prefetch, boty, 404 ---------- */
	Colorify_Analytics::record( array( 'time' => $t1 + 300, 'sec_purpose' => 'prefetch', 'ip' => '198.51.100.1' ) + $req );
	Colorify_Analytics::record( array( 'time' => $t1 + 300, 'sec_purpose' => 'prefetch;prerender' ) + $req );
	Colorify_Analytics::record( array( 'time' => $t1 + 300, 'ua' => $bots[0] ) + $req );
	Colorify_Analytics::record( array( 'time' => $t1 + 300, 'is_404' => true, 'uri' => '/wp-login.php' ) + $req );
	Colorify_Analytics::record( array( 'time' => $t1 + 300, 'is_404' => true, 'uri' => '/stara-oferta/', 'referer' => home_url( '/o-mnie/' ) ) + $req );
	$row = $wpdb->get_row( "SELECT views, prefetch, spec, bots FROM " . Colorify_Analytics::t( 'daily_totals' ) . " WHERE day = '2026-07-01' AND method = 'php1'", ARRAY_A );
	cft( array( 'views' => '4', 'prefetch' => '1', 'spec' => '1', 'bots' => '2' ) === $row, 'prefetch liczony (i osobno), prerender pominięty, bot i skaner w licznik botów', $row );
	$e = colorify_analytics_errors404( '2026-07-01', '2026-07-01' );
	cft( 1 === $e['total_rows'] && '/stara-oferta/' === $e['rows'][0]['path'] && 'internal' === $e['rows'][0]['refs'][0]['type'] && home_url( '/o-mnie/' ) === $e['rows'][0]['refs'][0]['from'], '404: ścieżka i strona z błędnym linkiem', $e['rows'] );

	/* ---------- granice dni i stref ---------- */
	$late = ( new DateTimeImmutable( '2026-07-01 23:59:30', $tz ) )->getTimestamp();
	Colorify_Analytics::record( array( 'time' => $late, 'ip' => '198.51.100.9' ) + $req );
	Colorify_Analytics::record( array( 'time' => $late + 60, 'ip' => '198.51.100.9' ) + $req );
	$h = $wpdb->get_results( "SELECT day, hour, views FROM " . Colorify_Analytics::t( 'hourly_totals' ) . " WHERE (day = '2026-07-01' AND hour = 23) OR (day = '2026-07-02' AND hour = 0)", ARRAY_A );
	cft( 2 === count( $h ), 'odsłona o 23:59:30 i 00:00:30 czasu WP w dwóch różnych dniach i godzinach', $h );
	$r = colorify_analytics_range( array( 'days' => 7, 'now' => strtotime( '2026-11-12 12:00:00 UTC' ) ) );
	cft( '2026-11-05' === $r['from'] && '2026-11-11' === $r['to'] && 7 === $r['days'] && '2026-10-29' === $r['prev_from'] && '2026-11-04' === $r['prev_to'], 'zakres 7 dni: pełne dni do wczoraj, poprzedni okres', $r );
	$r = colorify_analytics_range( array( 'days' => 7, 'today' => true, 'now' => strtotime( '2026-11-12 12:00:00 UTC' ) ) );
	cft( '2026-11-06' === $r['from'] && '2026-11-12' === $r['to'] && $r['with_today'], 'zakres 7 dni z dzisiaj', $r );
	$r = colorify_analytics_range( array( 'days' => 7, 'now' => strtotime( '2026-11-12 23:30:00 UTC' ) ) );
	cft( '2026-11-13' === $r['today'] && '2026-11-12' === $r['to'], 'po północy w Warszawie (23:30 UTC) dziś = 13.11', $r );
	$r = colorify_analytics_range( array( 'days' => 30, 'now' => strtotime( '2026-10-25 00:30:00 UTC' ) ) );
	cft( '2026-09-25' === $r['from'] && '2026-10-24' === $r['to'] && 30 === $r['days'], 'zakres 30 dni w dniu zmiany czasu', $r );
	$r = colorify_analytics_range( array( 'days' => 365, 'now' => strtotime( '2026-11-12 12:00:00 UTC' ) ) );
	cft( '2025-11-12' === $r['from'] && 365 === $r['days'], 'zakres 365 dni', $r );
	$r = colorify_analytics_range( array( 'from' => '2026-11-10', 'to' => '2026-11-01' ) );
	cft( '' !== $r['error'] && 30 === $r['preset'], 'własne daty odwrotnie: komunikat i 30 dni', $r );
	$r = colorify_analytics_range( array( 'from' => '2024-01-01', 'to' => '2026-11-10', 'now' => strtotime( '2026-11-12 12:00:00 UTC' ) ) );
	cft( 800 === $r['days'] && '' !== $r['error'], 'własne daty ponad 800 dni: przycięte', $r );
	$r = colorify_analytics_range( array( 'from' => '2026-11-01', 'to' => '2026-11-12', 'now' => strtotime( '2026-11-12 12:00:00 UTC' ) ) );
	cft( 0 === $r['preset'] && $r['with_today'] && 12 === $r['days'], 'własne daty do dziś = z dzisiaj', $r );

	/* ---------- migracja starego licznika, jedno źródło na dzień ---------- */
	$legacy = array(
		'2026-06-28' => array( 'v' => 5, 'u' => 2, 'p' => array( 4 => 3, 17 => 2 ) ),
		'2026-06-30' => array( 'v' => 1, 'u' => 1, 'p' => array( 17 => 1 ) ),
		'2026-07-02' => array( 'v' => 99, 'u' => 9, 'p' => array( 17 => 99 ) ),
		'zly-klucz'  => array( 'v' => 7 ),
	);
	Colorify_Analytics::migrate_legacy( $legacy );
	$m2 = Colorify_Analytics::migrate_legacy( $legacy );
	cft( 2 === $m2['days'] && 3 === $m2['pages'] && '2' === $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Colorify_Analytics::t( 'daily_totals' ) . " WHERE method = 'legacy'" ), 'migracja: 2 dni i 3 wiersze stron, powtórka bez dubli, dzień po końcu starego licznika pominięty', $m2 );
	Colorify_Analytics::record( array( 'time' => $at( '2026-06-28 12:00' ), 'ip' => '198.51.100.3' ) + $req );
	$s = colorify_analytics_series( '2026-06-27', '2026-07-02' );
	$v = array_map( static fn( $p ) => $p['views'], $s['points'] );
	cft( array( null, 5, null, 1, 5, 2 ) === $v, 'series: stary licznik do 30.06 (dzień z PHP 28.06 nie dosumowany), luki = null, od 01.07 licznik PHP', $v );
	cft( 'legacy' === $s['points'][1]['method'] && 'php1' === $s['points'][4]['method'] && ! $s['points'][0]['measured'], 'series: metoda przy każdym dniu, brak pomiaru oznaczony' );
	cft( 1 === count( $s['annotations'] ) && '2026-07-01' === $s['annotations'][0]['date'], 'series: adnotacja zmiany metody 01.07', $s['annotations'] );
	$w = colorify_analytics_series( '2026-06-22', '2026-07-05', 'week' );
	cft( 2 === count( $w['points'] ) && '2026-06-22' === $w['points'][0]['date'] && 5 === $w['points'][0]['views'] && $w['points'][0]['partial'], 'series tygodniowe: od poniedziałku, niepełny tydzień oznaczony', $w['points'] );
	$tot = colorify_analytics_totals( '2026-06-28', '2026-06-30' );
	cft( 6 === $tot['views'] && null === $tot['bots'] && array( 'legacy' ) === $tot['methods'] && 2 === $tot['measured_days'] && 1.5 === $tot['visitors_avg'] && 'no_history' === $tot['compare_reason'], 'totals: stary licznik bez botów, średnia dzienna z dni zmierzonych, bez historii do porównania', $tot );
	$tot = colorify_analytics_totals( '2026-06-29', '2026-06-29' );
	cft( false === $tot['compare_allowed'] && 'gaps' === $tot['compare_reason'] && 0 === $tot['measured_days'] && null === $tot['visitors_avg'], 'totals: dzień bez pomiaru = luka, nie zero', $tot );
	$tot = colorify_analytics_totals( '2026-07-01', '2026-07-01' );
	cft( false === $tot['compare_allowed'] && 'method_change' === $tot['compare_reason'], 'totals: porównanie przez zmianę metody niedozwolone', $tot );
	$tot = colorify_analytics_totals( '2026-07-02', '2026-07-02' );
	cft( true === $tot['compare_allowed'] && -3 === $tot['change'] && 5 === $tot['prev']['views'], 'totals: porównanie dozwolone przy pełnej historii tej samej metody, zmiana bezwzględna', $tot );

	/* ---------- API stron, przekrojów, godzin ---------- */
	$p = colorify_analytics_pages( '2026-07-01', '2026-07-02', array( 'search' => 'mnie' ) );
	cft( 1 === $p['total_rows'] && '4' === $p['rows'][0]['key'] && 'O mnie' === $p['rows'][0]['title'], 'pages: szukanie po tytule', $p );
	$p = colorify_analytics_pages( '2026-06-28', '2026-06-28', array( 'sort' => 'views' ) );
	cft( 2 === $p['total_rows'] && '4' === $p['rows'][0]['key'] && 0.6 === $p['rows'][0]['share'], 'pages: udział i sortowanie (stary licznik)', $p['rows'] );
	$src = colorify_analytics_sources( '2026-07-01', '2026-07-01' );
	cft( 'search' === $src['classes'][0]['class'] && $src['classes'][0]['views'] >= 4 && 'google.pl' === $src['domains'][0]['domain'] && ! $src['partial'], 'sources: klasy i domeny', $src['classes'] );
	cft( true === colorify_analytics_sources( '2026-06-28', '2026-07-01' )['partial'], 'sources: zakres sprzed przekrojów oznaczony partial' );
	$dv = colorify_analytics_devices( '2026-07-01', '2026-07-01' );
	cft( 'desktop' === $dv['devices'][2]['device'] && $dv['devices'][2]['views'] >= 3 && 1 === $dv['devices'][0]['views'], 'devices: komputer i telefon', $dv['devices'] );
	$g = colorify_analytics_hours_grid( '2026-07-01', '2026-07-01' );
	cft( $g['grid'][2]['hours'][10] >= 3 && 'śr.' === $g['grid'][2]['label'] && 1 === $g['grid'][2]['days'], 'hours_grid: środa 01.07, godzina 10 czasu WP', $g['grid'][2] );
	$ep = colorify_analytics_entry_pages( '2026-07-01', '2026-07-01' );
	cft( 1 === $ep['total_rows'] && '4' === $ep['rows'][0]['key'], 'entry_pages: strona z wejściem z zewnątrz', $ep );
	$pr = colorify_analytics_page_report( '4', '2026-07-01', '2026-07-02' );
	cft( $pr['views'] >= 5 && 2 === count( $pr['series'] ) && 'search' === $pr['sources']['classes'][0]['class'], 'page_report: seria, źródła i urządzenia strony', array( $pr['views'], $pr['series'] ) );

	/* ---------- limity „Inne” ---------- */
	$vals = array();
	for ( $i = 0; $i < 100; $i++ ) {
		$vals[] = $wpdb->prepare( "(%s,'php1','','source_domain',%s,1)", '2026-07-03', "d{$i}.example" );
	}
	$wpdb->query( 'INSERT INTO ' . Colorify_Analytics::t( 'daily_breakdowns' ) . ' (day,method,page,dim,val,views) VALUES ' . implode( ',', $vals ) );
	$t3 = $at( '2026-07-03 12:00' );
	Colorify_Analytics::record( array( 'time' => $t3, 'referer' => 'https://nowa-domena.pl/x' ) + $req );
	Colorify_Analytics::record( array( 'time' => $t3, 'referer' => 'https://d5.example/' ) + $req );
	$got = $wpdb->get_results( "SELECT val, views FROM " . Colorify_Analytics::t( 'daily_breakdowns' ) . " WHERE day = '2026-07-03' AND page = '' AND dim = 'source_domain' AND val IN ('*','d5.example','nowa-domena.pl') ORDER BY val", ARRAY_A );
	cft( array( array( 'val' => '*', 'views' => '1' ), array( 'val' => 'd5.example', 'views' => '2' ) ) === $got, 'limit 100 domen dziennie: nowa domena do „Inne”, znana dalej liczona', $got );
	$vals = array();
	for ( $i = 0; $i < 300; $i++ ) {
		$vals[] = $wpdb->prepare( "(%s,'php1',%s,1,0)", '2026-07-03', "/p{$i}/" );
	}
	$wpdb->query( 'INSERT INTO ' . Colorify_Analytics::t( 'daily_pages' ) . ' (day,method,page,views,entries) VALUES ' . implode( ',', $vals ) );
	Colorify_Analytics::record( array( 'time' => $t3, 'page' => '', 'uri' => '/archiwum-nowe/' ) + $req );
	Colorify_Analytics::record( array( 'time' => $t3, 'page' => '17', 'uri' => '/' ) + $req );
	cft( '1' === $wpdb->get_var( "SELECT views FROM " . Colorify_Analytics::t( 'daily_pages' ) . " WHERE day = '2026-07-03' AND page = '*'" ) && '1' === $wpdb->get_var( "SELECT views FROM " . Colorify_Analytics::t( 'daily_pages' ) . " WHERE day = '2026-07-03' AND page = '17'" ), 'limit 300 adresów dziennie: nowa ścieżka do „Inne”, ID wpisu zawsze osobno' );

	/* ---------- retencja ---------- */
	$now = strtotime( '2026-11-12 12:00:00 UTC' );
	$wpdb->query( 'INSERT INTO ' . Colorify_Analytics::t( 'daily_totals' ) . " (day,method,views) VALUES ('2024-09-03','php1',1),('2024-09-04','php1',1)" );
	$wpdb->query( 'INSERT INTO ' . Colorify_Analytics::t( 'daily_seen' ) . " (day,h) VALUES ('2026-11-11', UNHEX('00112233445566778899aabbccddeeff')),('2026-11-12', UNHEX('ffeeddccbbaa99887766554433221100'))" );
	$wpdb->query( 'DELETE FROM ' . Colorify_Analytics::t( 'daily_seen' ) . " WHERE day < '2026-11-11'" );
	$dry = Colorify_Analytics::purge( true, 50, $now );
	cft( '2024-09-04' === $dry['cut'] && 1 === $dry['rows']['daily_totals'] && 1 === $dry['rows']['daily_seen'] && '2' === $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Colorify_Analytics::t( 'daily_totals' ) . " WHERE day IN ('2024-09-03','2024-09-04')" ), 'purge --dry-run: liczy (800 dni do 2024-09-04, skróty sprzed dziś), nic nie usuwa', $dry );
	Colorify_Analytics::purge( false, 50, $now );
	cft( array( '2024-09-04' ) === $wpdb->get_col( 'SELECT day FROM ' . Colorify_Analytics::t( 'daily_totals' ) . " WHERE day < '2025-01-01'" ) && array( '2026-11-12' ) === $wpdb->get_col( 'SELECT day FROM ' . Colorify_Analytics::t( 'daily_seen' ) ), 'purge: usunięty dzień 801, zostaje 800; skróty tylko z dziś' );

	/* ---------- dziennik odsłon: agregacja pliku godzinowego ---------- */
	wp_mkdir_p( $dir );
	$ts   = $at( '2026-07-04 10:20' );
	$hour = gmdate( 'YmdH', $ts );
	$log  = "<?php exit; ?>\n";
	for ( $i = 0; $i < 3; $i++ ) {
		$log .= implode( "\t", array( $ts, 'v', '4', 'www.google.pl', '', 'newsletter', '', '', 'mobile' ) ) . "\n";
	}
	$log .= implode( "\t", array( $ts, 'v', '/kategoria/x/page/2/', '', 'fbclid', '', '', '', 'desktop' ) ) . "\n";
	$log .= implode( "\t", array( $ts, 'e', '/stara', '', '', '', '', '', 'desktop' ) ) . "\n";
	$log .= implode( "\t", array( $ts, 'e', '/.env', '', '', '', '', '', 'desktop' ) ) . "\n";
	$log .= $ts . "\tb\n" . "śmieci\tbez\tsensu\n";
	file_put_contents( $dir . '/log-' . $hour . '.php', $log );
	file_put_contents( $dir . '/log-' . gmdate( 'YmdH', $ts + 3600 ) . '.php', "<?php exit; ?>\n" . $ts . "\tb\n" );
	$agg = Colorify_Analytics::aggregate_log( $ts + 3600 + 60, $dir );
	cft( array( 'files' => 1, 'views' => 4, 'bots' => 2, 'errors' => 1 ) === $agg, 'dziennik: 4 odsłony, 1×404, bot + skaner; plik bieżącej godziny nietknięty', $agg );
	cft( is_file( $dir . '/done-' . $hour . '.php' ) && ! is_file( $dir . '/log-' . $hour . '.php' ) && is_file( $dir . '/log-' . gmdate( 'YmdH', $ts + 3600 ) . '.php' ), 'dziennik: przetworzony plik przeniesiony do done-*, otwarty czeka' );
	cft( array( 'files' => 0, 'views' => 0, 'bots' => 0, 'errors' => 0 ) === Colorify_Analytics::aggregate_log( $ts + 3600 + 60, $dir ), 'dziennik: ponowny przebieg nic nie dublikuje' );
	$row = $wpdb->get_row( "SELECT views, visitors, bots FROM " . Colorify_Analytics::t( 'daily_totals' ) . " WHERE day = '2026-07-04' AND method = 'log1'", ARRAY_A );
	$fb  = $wpdb->get_var( "SELECT views FROM " . Colorify_Analytics::t( 'daily_breakdowns' ) . " WHERE day = '2026-07-04' AND method = 'log1' AND page = '' AND dim = 'source_domain' AND val = 'facebook.com'" );
	cft( array( 'views' => '4', 'visitors' => '0', 'bots' => '2' ) === $row && '1' === $fb && '1' === $wpdb->get_var( "SELECT views FROM " . Colorify_Analytics::t( 'daily_pages' ) . " WHERE day = '2026-07-04' AND method = 'log1' AND page = '/kategoria/x/'" ), 'dziennik: metoda log1, bez szacunku odwiedzających, fbclid = facebook.com, ścieżka znormalizowana', $row );
	touch( $dir . '/done-' . $hour . '.php', $ts - 3 * DAY_IN_SECONDS );
	Colorify_Analytics::aggregate_log( $ts + 3600 + 60, $dir );
	cft( ! is_file( $dir . '/done-' . $hour . '.php' ), 'dziennik: przetworzony plik usuwany po 48 godzinach' );

	/* ---------- przełączanie metody (jedno źródło na instalację) ---------- */
	$base = array(
		'methods' => array( array( 'method' => 'php1', 'from' => '2026-01-01', 'to' => '', 'started' => '' ) ),
		'cache'   => array( 'detected' => true ),
		'beacon'  => 'auto',
	) + Colorify_Analytics::registry();
	$r1   = Colorify_Analytics::sync_method( $base );
	$tom  = Colorify_Analytics::day_add( wp_date( 'Y-m-d' ), 1 );
	cft( 2 === count( $r1['methods'] ) && 'log1' === $r1['methods'][1]['method'] && $tom === $r1['methods'][1]['from'] && wp_date( 'Y-m-d' ) === $r1['methods'][0]['to'], 'wykryty cache: dziennik prawdą od jutra', $r1['methods'] );
	$r1['cache'] = array( 'detected' => false );
	$r2          = Colorify_Analytics::sync_method( $r1 );
	cft( 1 === count( $r2['methods'] ) && '' === $r2['methods'][0]['to'], 'cache zniknął przed jutrem: zmiana cofnięta', $r2['methods'] );
	$off           = $base;
	$off['beacon'] = 'off';
	cft( 1 === count( Colorify_Analytics::sync_method( $off )['methods'] ), 'dziennik wyłączony przez właściciela: zostaje licznik PHP' );
	cft( Colorify_Analytics::beacon_supported(), 'odbiornik dziennika widzi ten sam katalog uploads co WordPress' );

	/* ---------- polityka prywatności ---------- */
	$txt = colorify_analytics_privacy_text( true, true ) . colorify_analytics_privacy_text( true, false );
	cft( false !== strpos( $txt, 'Statystyki odwiedzin' ) && false !== strpos( $txt, '800&nbsp;dni' ) && false !== strpos( $txt, '48&nbsp;godzinach' ) && false !== strpos( $txt, 'Sygnał odsłony' ), 'polityka: nagłówek, retencja, skróty, zdanie o dzienniku' );
	cft( ! preg_match( '~(^|[\s>(„])(a|i|o|u|w|z|na|do|po|od|ze|we|co|to|np\.) ~iu', wp_strip_all_tags( str_replace( '&nbsp;', "\u{00A0}", $txt ) ) ), 'polityka: twarde spacje po jedno- i dwuliterowych słowach' );
	cft( false === strpos( colorify_analytics_privacy_text( true, false ), 'Sygnał odsłony' ), 'polityka: bez dziennika = bez zdania o sygnale' );

	/* ---------- jakość danych ---------- */
	$c = colorify_analytics_coverage();
	cft( isset( $c['method'], $c['data_since'], $c['bots']['ready'], $c['prefetch']['counted'], $c['engagement']['label'] ) && '2026-06-01' === $c['data_since'] && $c['low_sample'], 'coverage: metoda, dane od, boty, prefetch, mała próba', array( $c['method'], $c['data_since'], $c['state'] ) );
	cft( false !== strpos( Colorify_Analytics::methods_meta()['php1']['about'], "w\u{00A0}" ) || false !== strpos( Colorify_Analytics::methods_meta()['php1']['about'], "z\u{00A0}" ), 'opis metody z twardymi spacjami' );
	cft( false !== strpos( Colorify_Analytics::methods_meta()['php1']['about'], 'anulowane kliknięcie' ), 'opis metody: zdanie o prefetchu' );
} catch ( Throwable $e ) {
	cft( false, 'wyjątek: ' . $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
} finally {
	Colorify_Analytics::uninstall();
	foreach ( (array) glob( $dir . '/*.php' ) as $f ) {
		unlink( $f );
	}
	if ( is_dir( $dir ) ) {
		rmdir( $dir );
	}
	$left = count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'colorifytest_' ) . '%' ) ) ) + (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'colorifytest%' OR option_name LIKE '\_transient\_colorifytest%'" );
	Colorify_Analytics::$ns      = 'colorify';
	Colorify_Analytics::$nocache = false;
	cft( 0 === $left && ! is_dir( $dir ), 'odinstalowanie: zero tabel, opcji i plików testowych' );
}

WP_CLI::line( '' );
if ( $GLOBALS['cft_fail'] ) {
	WP_CLI::error( $GLOBALS['cft_fail'] . ' z ' . $GLOBALS['cft_n'] . ' testów nie przeszło.' );
}
WP_CLI::success( 'Wszystkie ' . $GLOBALS['cft_n'] . ' testy OK.' );
