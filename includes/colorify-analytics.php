<?php
/**
 * Analityka Colorify: własne tabele, kolektor odsłon po stronie serwera, dzienny szacunek odwiedzających,
 * przekroje (źródło, urządzenie, godzina), 404, dziennik odsłon dla stron z cache, migracja starego licznika,
 * retencja 800 dni, WP-CLI, akapit do polityki prywatności.
 *
 * Zasady (rada 12.11.2026): bez ciasteczek i bez zapisu IP, pełnego UA, pełnych URL-i i parametrów (poza
 * utm_source/medium/campaign z listy znaków). Każdy zapis to atomowe INSERT ... ON DUPLICATE KEY UPDATE.
 * Jedno źródło odsłon na dzień: rejestr metod mówi, która metoda jest prawdą dla danego dnia, raporty czytają tylko ją.
 * API zapytań dla kokpitu: colorify-analytics-query.php. Opis dla interfejsu: docs/kokpit-analityka.md.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'COLORIFY_ANALYTICS_LOADED' ) ) {
	return;
}
define( 'COLORIFY_ANALYTICS_LOADED', true );

require_once __DIR__ . '/colorify-analytics-rules.php';

final class Colorify_Analytics {

	const SCHEMA    = 1;
	const METHOD    = 'php1';
	const LOG       = 'log1';
	const KEEP_DAYS = 800;
	const OTHER     = '*';
	const TABLES    = array( 'daily_totals', 'daily_pages', 'daily_breakdowns', 'hourly_totals', 'daily_errors', 'daily_seen' );
	/** Limity nowych kluczy na dzień; nadmiar trafia do „Inne” ('*'). Chronią tabele przed zalaniem dowolnymi Refererami i adresami. */
	const LIMITS = array(
		'path'          => 300,
		'source_domain' => 100,
		'utm'           => 50,
		'errors'        => 300,
	);
	/** Ścieżki 404, które sprawdzają skanery plików, nie ludzie: liczone jako boty. */
	const SCANNER_RE = '~\.(php|env|git|sql|bak|zip|gz|tar|ini|log|key|pem|ya?ml|asp|aspx|jsp|cgi)(/|$)|/wp-(content|includes|admin)/|/(cgi-bin|phpmyadmin|vendor)(/|$)|/\.~i';

	/** @var string Przestrzeń nazw tabel i opcji: 'colorify', testy: 'colorifytest'. */
	public static $ns = 'colorify';
	/** @var bool Testy wyłączają 5-minutowy cache zapytań. */
	public static $nocache = false;
	/** @var bool */
	private static $was_404 = false;

	/* ===================== Nazwy, rejestr metod ===================== */

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . self::$ns . '_' . $name;
	}

	public static function opt( string $name ): string {
		return self::$ns . '_an_' . $name;
	}

	/** Data przesunięta o $n dni (czysta arytmetyka dat, bez stref i zmian czasu). */
	public static function day_add( string $day, int $n ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $day . ' 00:00:00 UTC' ) + $n * DAY_IN_SECONDS );
	}

	/** Twarde spacje po jedno- i dwuliterowych słowach (teksty API, wyświetlane po esc_html). */
	public static function nbsp( string $t ): string {
		return (string) preg_replace( '~(?<=^|\s)(\p{L}{1,2}|np\.) ~u', "\$1\u{00A0}", $t );
	}

	/** @return array<string,array{label:string,dims:bool,visitors:bool,about:string}> */
	public static function methods_meta(): array {
		return array(
			'legacy'   => array(
				'label'    => __( 'Stary licznik (do wersji 2.14)', 'colorify-by-inyfinn' ),
				'dims'     => false,
				'visitors' => true,
				'about'    => self::nbsp( __( 'Odsłony i dzienni unikalni ze starego licznika w opcji WordPressa. Liczył też część botów, przy równoczesnych wejściach gubił zapisy i nie zapisywał źródeł, urządzeń ani godzin. Dni bez wejść nie mają zapisu.', 'colorify-by-inyfinn' ) ),
			),
			self::METHOD => array(
				'label'    => __( 'Licznik serwerowy', 'colorify-by-inyfinn' ),
				'dims'     => true,
				'visitors' => true,
				'about'    => self::nbsp( __( 'Odsłona = otwarcie strony przez przeglądarkę osoby niezalogowanej, bez botów, podglądów i adresów 404. Przy przeglądarkach z prefetchem (conservative) odsłona liczy się przy rozpoczęciu kliknięcia; anulowane kliknięcie może dać pojedynczą nadwyżkę. Odwiedzający dziennie to szacunek: ta sama sieć i przeglądarka liczy się raz na dzień.', 'colorify-by-inyfinn' ) ),
			),
			self::LOG    => array(
				'label'    => __( 'Dziennik odsłon (strona z cache)', 'colorify-by-inyfinn' ),
				'dims'     => true,
				'visitors' => false,
				'about'    => self::nbsp( __( 'Strona korzysta z cache całych stron, więc odsłonę zgłasza przeglądarka krótkim sygnałem po załadowaniu strony (bez IP i identyfikatora). Dlatego nie ma szacunku odwiedzających. Przeglądarki bez JavaScriptu i część blokerów sygnału nie wyślą.', 'colorify-by-inyfinn' ) ),
			),
		);
	}

	/**
	 * Rejestr: methods = [[method, from, to, started]] (ciągłe przedziały dni), cache = wynik detekcji,
	 * beacon = auto|on|off (dziennik odsłon), legacy_done = ostatni dzień starego licznika przeniesiony ostatecznie,
	 * legacy_final = przeniesione wszystkie dni, daily = dzień ostatniego przebiegu dziennego.
	 */
	public static function registry(): array {
		$r = get_option( self::opt( 'registry' ), array() );
		return ( is_array( $r ) ? $r : array() ) + array(
			'methods'      => array(),
			'legacy_final' => true,
			'cache'        => array(),
			'beacon'       => 'auto',
			'daily'        => '',
		);
	}

	public static function save_registry( array $r ): void {
		update_option( self::opt( 'registry' ), $r, true );
	}

	/** Metoda, która jest prawdą dla dnia ('' = przed początkiem danych). */
	public static function method_at( string $day ): string {
		$m = '';
		foreach ( self::registry()['methods'] as $row ) {
			if ( $row['from'] <= $day ) {
				$m = $row['method'];
			}
		}
		return $m;
	}

	/* ===================== Instalacja ===================== */

	public static function maybe_install(): void {
		if ( (int) get_option( self::opt( 'schema' ) ) !== self::SCHEMA ) {
			self::install();
		}
		if ( 'colorify' === self::$ns && ! wp_next_scheduled( 'colorify_an_cron' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'colorify_an_cron' );
		}
	}

	/** Tabele (dbDelta, także przy zmianie schematu), rejestr metod, migracja starego licznika. */
	public static function install( ?array $legacy = null ): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$m = 'method varchar(10) NOT NULL';
		dbDelta(
			array(
				'CREATE TABLE ' . self::t( 'daily_totals' ) . " (
day date NOT NULL,
$m,
views int(10) unsigned NOT NULL DEFAULT 0,
visitors int(10) unsigned NOT NULL DEFAULT 0,
bots int(10) unsigned NOT NULL DEFAULT 0,
prefetch int(10) unsigned NOT NULL DEFAULT 0,
spec int(10) unsigned NOT NULL DEFAULT 0,
state varchar(12) NOT NULL DEFAULT 'ok',
PRIMARY KEY  (day,method)
) $c;",
				'CREATE TABLE ' . self::t( 'daily_pages' ) . " (
day date NOT NULL,
$m,
page varchar(191) NOT NULL,
views int(10) unsigned NOT NULL DEFAULT 0,
entries int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (day,method,page),
KEY page (page,day)
) $c;",
				'CREATE TABLE ' . self::t( 'daily_breakdowns' ) . " (
day date NOT NULL,
$m,
page varchar(191) NOT NULL DEFAULT '',
dim varchar(16) NOT NULL,
val varchar(100) NOT NULL,
views int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (day,method,page,dim,val)
) $c;",
				'CREATE TABLE ' . self::t( 'hourly_totals' ) . " (
day date NOT NULL,
$m,
hour tinyint(3) unsigned NOT NULL,
views int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (day,method,hour)
) $c;",
				'CREATE TABLE ' . self::t( 'daily_errors' ) . " (
day date NOT NULL,
$m,
path varchar(191) NOT NULL,
ref varchar(191) NOT NULL DEFAULT '',
hits int(10) unsigned NOT NULL DEFAULT 0,
PRIMARY KEY  (day,method,path,ref)
) $c;",
				'CREATE TABLE ' . self::t( 'daily_seen' ) . " (
day date NOT NULL,
h binary(16) NOT NULL,
PRIMARY KEY  (day,h)
) $c;",
			)
		);
		update_option( self::opt( 'schema' ), self::SCHEMA, true );
		self::init_registry( $legacy );
		self::migrate_legacy( $legacy );
	}

	/**
	 * Pierwszy wpis rejestru. Jest stary licznik: on jest prawdą do dziś włącznie (pełny dzień), nowy od jutra.
	 * Brak starego: nowy od dziś (pierwszy dzień niepełny, stan 'partial').
	 */
	private static function init_registry( ?array $legacy = null ): void {
		$reg = self::registry();
		if ( $reg['methods'] ) {
			return;
		}
		$legacy = null === $legacy ? get_option( 'colorify_visits', array() ) : $legacy;
		$days   = is_array( $legacy ) ? array_filter( array_map( 'strval', array_keys( $legacy ) ), array( __CLASS__, 'is_day' ) ) : array();
		$today  = wp_date( 'Y-m-d' );
		$start  = array(
			'method'  => self::METHOD,
			'from'    => $today,
			'to'      => '',
			'started' => wp_date( 'Y-m-d H:i' ),
		);
		$reg['methods'] = array();
		if ( $days ) {
			$reg['methods'][] = array(
				'method'  => 'legacy',
				'from'    => min( $days ),
				'to'      => $today,
				'started' => '',
			);
			$start['from']    = self::day_add( $today, 1 );
		}
		$reg['methods'][]    = $start;
		$reg['legacy_final'] = ! $days;
		self::save_registry( $reg );
	}

	public static function is_day( string $d ): bool {
		return (bool) preg_match( '~^\d{4}-\d{2}-\d{2}$~', $d ) && checkdate( (int) substr( $d, 5, 2 ), (int) substr( $d, 8, 2 ), (int) substr( $d, 0, 4 ) );
	}

	/**
	 * Stary licznik (opcja colorify_visits, czytana, nigdy zmieniana) do daily_totals i daily_pages z metodą 'legacy'.
	 * Dzień instalacji dochodzi kolejnym przebiegiem (cron), gdy się skończy. Dni już ostateczne (zakończone w chwili importu,
	 * rejestr legacy_done) są pomijane, więc ponowna migracja nie cofa ręcznych korekt w tabelach.
	 *
	 * @return array{days:int,pages:int,skipped:int}
	 */
	public static function migrate_legacy( ?array $legacy = null ): array {
		global $wpdb;
		$legacy = null === $legacy ? get_option( 'colorify_visits', array() ) : $legacy;
		$reg    = self::registry();
		$to     = '';
		foreach ( $reg['methods'] as $row ) {
			if ( 'legacy' === $row['method'] ) {
				$to = $row['to'];
			}
		}
		$out  = array(
			'days'    => 0,
			'pages'   => 0,
			'skipped' => 0,
		);
		$done = (string) ( $reg['legacy_done'] ?? ( empty( $reg['legacy_final'] ) ? '' : $to ) );
		if ( '' === $to || ! is_array( $legacy ) ) {
			return $out;
		}
		foreach ( $legacy as $day => $row ) {
			$day = (string) $day;
			if ( ! self::is_day( $day ) || $day > $to || ! is_array( $row ) ) {
				continue;
			}
			if ( '' !== $done && $day <= $done ) {
				++$out['skipped'];
				continue;
			}
			$v = (int) ( $row['v'] ?? 0 );
			$u = (int) ( $row['u'] ?? 0 );
			self::q( $wpdb->prepare( 'INSERT INTO ' . self::t( 'daily_totals' ) . ' (day,method,views,visitors,state) VALUES (%s,%s,%d,%d,%s) ON DUPLICATE KEY UPDATE views = %d, visitors = %d', $day, 'legacy', $v, $u, 'legacy', $v, $u ) );
			++$out['days'];
			foreach ( (array) ( $row['p'] ?? array() ) as $id => $n ) {
				if ( (int) $id > 0 ) {
					self::q( $wpdb->prepare( 'INSERT INTO ' . self::t( 'daily_pages' ) . ' (day,method,page,views) VALUES (%s,%s,%s,%d) ON DUPLICATE KEY UPDATE views = %d', $day, 'legacy', (string) (int) $id, (int) $n, (int) $n ) );
					++$out['pages'];
				}
			}
		}
		$last = min( $to, self::day_add( wp_date( 'Y-m-d' ), -1 ) );
		if ( $last > $done ) {
			$reg                 = self::registry();
			$reg['legacy_done']  = $last;
			$reg['legacy_final'] = $last === $to;
			self::save_registry( $reg );
		}
		return $out;
	}

	/* ===================== Kolektor ===================== */

	/** Priorytet 0: zapamiętaj 404, zanim motyw podmieni je na zwykłą stronę. */
	public static function flag_404(): void {
		self::$was_404 = is_404();
	}

	/** Czy to żądanie w ogóle dotyczy analityki (strona publiczna, gość, nie podgląd, nie kanał, nie REST). */
	private static function in_scope(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sam odczyt obecności parametru.
		return ! ( is_user_logged_in() || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| is_feed() || is_robots() || is_trackback() || is_embed() || is_preview() || is_customize_preview()
			|| ( function_exists( 'is_favicon' ) && is_favicon() ) || '' !== (string) get_query_var( 'sitemap' )
			|| isset( $_GET['ej_podglad'] ) || isset( $_GET['colorify_tresci_podglad'] ) || isset( $_GET['preview'] ) );
		// phpcs:enable
	}

	private static function server( string $k, int $max = 1000 ): string {
		return isset( $_SERVER[ $k ] ) ? substr( (string) wp_unslash( $_SERVER[ $k ] ), 0, $max ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- oczyszczane przy klasyfikacji, do bazy trafiają tylko klasy i znormalizowane klucze.
	}

	/** template_redirect 99: po przekierowaniach kanonicznych, więc liczy adres, który naprawdę się wyświetlił. */
	public static function collect(): void {
		if ( '' !== self::server( 'HTTP_X_COLORIFY_PROBE' ) || ! self::in_scope() ) {
			return;
		}
		$req  = array(
			'method'      => strtoupper( self::server( 'REQUEST_METHOD', 10 ) ),
			'ua'          => self::server( 'HTTP_USER_AGENT', 512 ),
			'accept'      => self::server( 'HTTP_ACCEPT', 300 ),
			'sec_purpose' => self::server( 'HTTP_SEC_PURPOSE', 100 ),
			'purpose'     => self::server( 'HTTP_PURPOSE', 50 ) . ' ' . self::server( 'HTTP_X_PURPOSE', 50 ) . ' ' . self::server( 'HTTP_X_MOZ', 50 ),
			'ip'          => self::server( 'REMOTE_ADDR', 64 ),
			'referer'     => self::server( 'HTTP_REFERER', 1000 ),
			'uri'         => self::server( 'REQUEST_URI', 2000 ),
			'page'        => self::page_key(),
			'is_404'      => self::$was_404 || is_404(),
			'time'        => time(),
		);
		// Test współbieżności na żywej stronie: nagłówek z jednorazowym tokenem (opcja ustawiana z WP-CLI) kieruje zapis do tabel testowych.
		$test = self::server( 'HTTP_X_COLORIFY_ANALYTICS_TEST', 100 );
		if ( '' !== $test ) {
			$tok = (string) get_option( 'colorify_an_test_token', '' );
			if ( '' !== $tok && hash_equals( $tok, $test ) ) {
				self::$ns    = 'colorifytest';
				$req['test'] = true;
			}
		}
		self::record( $req );
	}

	/**
	 * Bot albo zautomatyzowane żądanie: 'bot'; wstępne pobranie bez pewności wejścia: 'speculative';
	 * prefetch przeglądarki (WordPress: speculation rules, conservative = przy rozpoczęciu kliknięcia): 'prefetch'; reszta: 'human'.
	 */
	public static function classify( array $r ): string {
		if ( 'HEAD' === $r['method'] || Colorify_Analytics_Rules::is_bot_ua( (string) $r['ua'] ) || false === stripos( (string) $r['accept'], 'text/html' ) ) {
			return 'bot';
		}
		$sp = strtolower( trim( (string) $r['sec_purpose'] ) );
		if ( '' !== $sp ) {
			// prefetch;prerender (prerender), prefetch;anonymous-client-ip (wyniki Google przez proxy): bez pewności wejścia.
			return 'prefetch' === $sp ? 'prefetch' : 'speculative';
		}
		return preg_match( '~prefetch|prerender|preview~i', (string) $r['purpose'] ) ? 'speculative' : 'human';
	}

	/**
	 * Zapis jednego żądania (kolektor i testy). Zwraca, jak zostało policzone: human|prefetch|bot|speculative|404|skip.
	 *
	 * @param array $r method, ua, accept, sec_purpose, purpose, ip, referer, uri, page, is_404, time, [host], [test].
	 */
	public static function record( array $r ): string {
		$r += array(
			'method'      => 'GET',
			'ua'          => '',
			'accept'      => '',
			'sec_purpose' => '',
			'purpose'     => '',
			'ip'          => '',
			'referer'     => '',
			'uri'         => '/',
			'page'        => '',
			'is_404'      => false,
			'time'        => time(),
			'host'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'test'        => false,
		);
		if ( 'GET' !== $r['method'] && 'HEAD' !== $r['method'] ) {
			return 'skip';
		}
		$day  = wp_date( 'Y-m-d', $r['time'] );
		$kind = $r['test'] ? 'human' : self::classify( $r );
		if ( 'bot' === $kind || 'speculative' === $kind ) {
			self::bump( self::METHOD, $day, 'bot' === $kind ? 'bots' : 'spec' );
			return $kind;
		}
		$ref_host = (string) wp_parse_url( $r['referer'], PHP_URL_HOST );
		if ( '' === $ref_host && 0 === stripos( $r['referer'], 'android-app://' ) ) {
			$ref_host = (string) strtok( substr( $r['referer'], 14 ), '/' );
		}
		$query = array();
		wp_parse_str( (string) wp_parse_url( $r['uri'], PHP_URL_QUERY ), $query );
		$click = '';
		foreach ( array_keys( Colorify_Analytics_Rules::CLICK_IDS ) as $c ) {
			if ( isset( $query[ $c ] ) ) {
				$click = $c;
				break;
			}
		}
		list( $cls, $dom ) = Colorify_Analytics_Rules::source( $ref_host, $r['host'], $click );
		if ( $r['is_404'] ) {
			$path = self::norm_404( $r['uri'] );
			if ( '' === $path ) {
				self::bump( self::METHOD, $day, 'bots' );
				return 'bot';
			}
			$ref = 'internal' === $cls ? 'internal:' . self::norm_path( (string) wp_parse_url( $r['referer'], PHP_URL_PATH ) ) : $dom;
			self::write_404( self::METHOD, $day, $path, $ref, 1 );
			return '404';
		}
		$utm = array();
		foreach ( array( 'source', 'medium', 'campaign' ) as $u ) {
			$v = Colorify_Analytics_Rules::utm_value( is_string( $query[ 'utm_' . $u ] ?? null ) ? $query[ 'utm_' . $u ] : '' );
			if ( '' !== $v ) {
				$utm[ 'utm_' . $u ] = $v;
			}
		}
		$page = '' !== $r['page'] ? $r['page'] : self::norm_path( $r['uri'] );
		self::write_view(
			self::METHOD,
			$day,
			(int) wp_date( 'G', $r['time'] ),
			$page,
			$cls,
			$dom,
			Colorify_Analytics_Rules::device( (string) $r['ua'] ),
			$utm,
			1,
			self::seen( $day, (string) $r['ip'], (string) $r['ua'] ),
			'prefetch' === $kind ? 1 : 0
		);
		return $kind;
	}

	/**
	 * Klucz strony: ID wpisu/strony (cyfry), '/?s' dla wyników wyszukiwania, znormalizowana ścieżka dla archiwów
	 * i innych adresów (bez parametrów, bez /page/N/).
	 */
	public static function page_key(): string {
		if ( is_search() ) {
			return '/?s';
		}
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 ) {
				return (string) $id;
			}
		}
		if ( is_home() && (int) get_option( 'page_for_posts' ) > 0 ) {
			return (string) (int) get_option( 'page_for_posts' );
		}
		return '';
	}

	/** Ścieżka względem katalogu strony: bez parametrów i paginacji, małe litery, z ukośnikiem na początku i końcu, do 191 znaków. */
	public static function norm_path( string $uri ): string {
		$p = self::clean_path( $uri );
		$p = (string) preg_replace( '~/(page/\d+|comment-page-\d+)/?$~i', '/', $p );
		$p = function_exists( 'mb_strtolower' ) ? mb_strtolower( $p, 'UTF-8' ) : strtolower( $p ); // WordPress nie ma zamiennika mb_strtolower.
		if ( '/' !== substr( $p, -1 ) && false === strpos( (string) strrchr( $p, '/' ), '.' ) ) {
			$p .= '/';
		}
		return mb_substr( $p, 0, 191, 'UTF-8' );
	}

	/** Ścieżka 404 (bez zmiany wielkości liter, do naprawy przekierowaniem) albo '' dla skanerów plików. */
	public static function norm_404( string $uri ): string {
		$p = self::clean_path( $uri );
		return preg_match( self::SCANNER_RE, $p ) ? '' : mb_substr( $p, 0, 191, 'UTF-8' );
	}

	private static function clean_path( string $uri ): string {
		$p    = (string) wp_parse_url( (string) preg_replace( '~^/{2,}~', '/', $uri ), PHP_URL_PATH ); // //a//b to ścieżka, nie host.
		$p    = wp_check_invalid_utf8( rawurldecode( $p ) );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $p, $home . '/' ) ) {
			$p = substr( $p, strlen( $home ) );
		}
		$p = (string) preg_replace( '~[\x00-\x1F\x7F]+~', '', $p );
		$p = (string) preg_replace( '~/{2,}~', '/', $p );
		return '/' . ltrim( $p, '/' );
	}

	/* ===================== Zapis (wspólny dla kolektora i dziennika) ===================== */

	/**
	 * Odsłona(y) z jednym zestawem cech: sumy dnia, strona, przekroje (cała strona i ta podstrona), godzina.
	 *
	 * @param array<string,string> $utm utm_source|utm_medium|utm_campaign => wartość.
	 */
	public static function write_view( string $method, string $day, int $hour, string $page, string $cls, string $dom, string $dev, array $utm, int $n, int $new_visitors, int $prefetch ): void {
		global $wpdb;
		$state = self::day_state( $method, $day );
		self::q( $wpdb->prepare( 'INSERT INTO ' . self::t( 'daily_totals' ) . ' (day,method,views,visitors,prefetch,state) VALUES (%s,%s,%d,%d,%d,%s) ON DUPLICATE KEY UPDATE views = views + %d, visitors = visitors + %d, prefetch = prefetch + %d', $day, $method, $n, $new_visitors, $prefetch, $state, $n, $new_visitors, $prefetch ) );

		if ( ! ctype_digit( $page ) ) {
			$page = self::capped( $method, 'daily_pages', 'page', $page, self::LIMITS['path'], $day, "page NOT REGEXP '^[0-9]+$'" );
		}
		$entry = in_array( $cls, array( 'search', 'social', 'referral' ), true ) ? $n : 0;
		self::q( $wpdb->prepare( 'INSERT INTO ' . self::t( 'daily_pages' ) . ' (day,method,page,views,entries) VALUES (%s,%s,%s,%d,%d) ON DUPLICATE KEY UPDATE views = views + %d, entries = entries + %d', $day, $method, $page, $n, $entry, $n, $entry ) );

		$rows = array( array( '', 'source_class', $cls ), array( '', 'device', $dev ), array( $page, 'source_class', $cls ), array( $page, 'device', $dev ) );
		if ( '' !== $dom ) {
			$dom    = self::capped( $method, 'daily_breakdowns', 'val', $dom, self::LIMITS['source_domain'], $day, "page = '' AND dim = 'source_domain'" );
			$rows[] = array( '', 'source_domain', $dom );
			$rows[] = array( $page, 'source_domain', $dom );
		}
		foreach ( $utm as $dim => $val ) {
			$rows[] = array( '', $dim, self::capped( $method, 'daily_breakdowns', 'val', $val, self::LIMITS['utm'], $day, $wpdb->prepare( "page = '' AND dim = %s", $dim ) ) );
		}
		$vals = array();
		foreach ( $rows as $row ) {
			$vals[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%d)', $day, $method, $row[0], $row[1], $row[2], $n );
		}
		self::q( 'INSERT INTO ' . self::t( 'daily_breakdowns' ) . ' (day,method,page,dim,val,views) VALUES ' . implode( ',', $vals ) . ' ON DUPLICATE KEY UPDATE views = views + VALUES(views)' );

		self::q( $wpdb->prepare( 'INSERT INTO ' . self::t( 'hourly_totals' ) . ' (day,method,hour,views) VALUES (%s,%s,%d,%d) ON DUPLICATE KEY UPDATE views = views + %d', $day, $method, $hour, $n, $n ) );
	}

	/** @param string $ref domena, 'internal:/ścieżka/' albo '' (bez odnośnika). */
	public static function write_404( string $method, string $day, string $path, string $ref, int $n ): void {
		global $wpdb;
		$capped = self::capped( $method, 'daily_errors', 'path', $path, self::LIMITS['errors'], $day );
		if ( self::OTHER === $capped ) {
			$path = self::OTHER;
			$ref  = '';
		}
		self::q( $wpdb->prepare( 'INSERT INTO ' . self::t( 'daily_errors' ) . ' (day,method,path,ref,hits) VALUES (%s,%s,%s,%s,%d) ON DUPLICATE KEY UPDATE hits = hits + %d', $day, $method, $path, mb_substr( $ref, 0, 191, 'UTF-8' ), $n, $n ) );
	}

	/** +1 w kolumnie licznika dnia (bots: boty i skanery, spec: pominięte wstępne pobrania). */
	private static function bump( string $method, string $day, string $col, int $n = 1 ): void {
		global $wpdb;
		$col = 'bots' === $col ? 'bots' : 'spec';
		self::q( $wpdb->prepare( 'INSERT INTO ' . self::t( 'daily_totals' ) . " (day,method,{$col},state) VALUES (%s,%s,%d,%s) ON DUPLICATE KEY UPDATE {$col} = {$col} + %d", $day, $method, $n, self::day_state( $method, $day ), $n ) );
	}

	/** Wartość albo '*' (Inne), gdy dzień ma już $limit innych wartości. ponytail: równoległe żądania mogą przekroczyć limit o kilka. */
	private static function capped( string $method, string $table, string $col, string $val, int $limit, string $day, string $extra = '1=1' ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM({$col} = %s), 0) AS has FROM " . self::t( $table ) . ' WHERE day = %s AND method = %s', $val, $day, $method ) . ' AND ' . $extra );
		return ( $row && ( (int) $row->has > 0 || (int) $row->n < $limit ) ) ? $val : self::OTHER;
	}

	/** Stan nowego wiersza dnia: partial = dzień uruchomienia metody, cache = wykryto cache całych stron przy liczniku PHP. */
	private static function day_state( string $method, string $day ): string {
		$reg = self::registry();
		if ( self::METHOD === $method && ! empty( $reg['cache']['detected'] ) ) {
			return 'cache';
		}
		foreach ( $reg['methods'] as $row ) {
			if ( $row['method'] === $method && '' !== $row['started'] && substr( $row['started'], 0, 10 ) === $day ) {
				return 'partial';
			}
		}
		return 'ok';
	}

	private static function q( string $sql ): void {
		global $wpdb;
		if ( false !== $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- składane z $wpdb->prepare().
			return;
		}
		// Utracony zapis: licznik dla karty jakości danych; brak tabel (np. po przywróceniu bazy) = instalacja przy następnym żądaniu.
		update_option( self::opt( 'lost' ), (int) get_option( self::opt( 'lost' ), 0 ) + 1, false );
		if ( false !== stripos( (string) $wpdb->last_error, "doesn't exist" ) ) {
			delete_option( self::opt( 'schema' ) );
		}
	}

	/* ===================== Szacunek odwiedzających ===================== */

	/**
	 * 1 = pierwszy raz dziś ta para IP + przeglądarka. Zapisujemy tylko 16 bajtów HMAC-SHA-256 z losowym kluczem dnia
	 * w daily_seen (bez strony, źródła i czasu); klucz i skróty z poprzednich dni są kasowane przy pierwszym żądaniu nowego dnia.
	 */
	private static function seen( string $day, string $ip, string $ua ): int {
		global $wpdb;
		if ( '' === $ip ) {
			return 0;
		}
		$h  = substr( hash_hmac( 'sha256', $ip . '|' . $ua, self::secret( $day ) ), 0, 32 );
		$ok = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'daily_seen' ) . ' (day,h) VALUES (%s, UNHEX(%s))', $day, $h ) );
		return ( false !== $ok && 1 === (int) $wpdb->rows_affected ) ? 1 : 0;
	}

	private static function secret( string $day ): string {
		global $wpdb;
		$name = self::opt( 'secret_' . str_replace( '-', '', $day ) );
		$sql  = $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name );
		$s    = (string) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' === $s ) {
			// Pierwsze żądanie dnia tworzy klucz. INSERT IGNORE: z dwóch równoczesnych wygrywa jedno, drugie czyta jego klucz.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, bin2hex( random_bytes( 32 ) ) ) );
			if ( 1 === (int) $wpdb->rows_affected ) {
				self::forget( $day );
			}
			$s = (string) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $s;
	}

	/** Kasuje klucze i skróty dni wcześniejszych niż $day. */
	public static function forget( string $day ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s", $wpdb->esc_like( self::opt( 'secret_' ) ) . '%', self::opt( 'secret_' . str_replace( '-', '', $day ) ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( 'daily_seen' ) . ' WHERE day < %s', $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ===================== Cron: znacznik dnia, sprzątanie, detekcja cache, dziennik ===================== */

	public static function cron(): void {
		global $wpdb;
		$today = wp_date( 'Y-m-d' );
		$reg   = self::registry();
		$live  = self::method_at( $today );
		// Wiersz dnia z zerami = „pomiar działał tego dnia” (dzień bez wejść to zero, nie brak danych).
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . self::t( 'daily_totals' ) . ' (day,method,state) VALUES (%s,%s,%s)', $today, '' !== $live && 'legacy' !== $live ? $live : self::METHOD, 'ok' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::forget( $today );
		self::aggregate_log();
		self::purge( false, 10 );
		if ( $reg['daily'] !== $today ) {
			if ( empty( $reg['legacy_final'] ) ) {
				self::migrate_legacy();
			}
			$reg          = self::registry();
			$reg['cache'] = self::detect_cache();
			$reg['daily'] = $today;
			self::save_registry( self::sync_method( $reg ) );
		}
	}

	/**
	 * Usuwa agregaty starsze niż 800 dni (partiami po 1000 wierszy) i skróty odwiedzających z poprzednich dni.
	 *
	 * @return array{cut:string,rows:array<string,int>} liczba wierszy do usunięcia (przed usunięciem).
	 */
	public static function purge( bool $dry = false, int $batches = 50, ?int $now = null ): array {
		global $wpdb;
		$now   = $now ?? time();
		$today = wp_date( 'Y-m-d', $now );
		$cut   = self::day_add( $today, -( self::KEEP_DAYS - 1 ) );
		$out   = array();
		foreach ( self::TABLES as $t ) {
			$lim       = 'daily_seen' === $t ? $today : $cut;
			$n         = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( $t ) . ' WHERE day < %s', $lim ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ $t ] = $n;
			for ( $i = 0; ! $dry && $n > 0 && $i < $batches; $i++ ) {
				$d = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t( $t ) . ' WHERE day < %s LIMIT 1000', $lim ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $d < 1 ) {
					break;
				}
				$n -= $d;
			}
		}
		return array(
			'cut'  => $cut,
			'rows' => $out,
		);
	}

	/** Cache całych stron: stała WP_CACHE, znane wtyczki, nagłówki odpowiedzi strony głównej (jedno żądanie dziennie). */
	public static function detect_cache(): array {
		$sig = array();
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			$sig[] = 'WP_CACHE';
		}
		foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
			if ( preg_match( '~^(litespeed-cache|wp-super-cache|w3-total-cache|wp-rocket|wp-fastest-cache|cache-enabler|breeze|sg-cachepress|hummingbird-performance|comet-cache|swift-performance|nitropack)/~', (string) $p ) ) {
				$sig[] = 'plugin:' . strtok( (string) $p, '/' );
			}
		}
		$res = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'headers'     => array(
					'Accept'           => 'text/html',
					'X-Colorify-Probe' => '1',
				),
			)
		);
		if ( ! is_wp_error( $res ) ) {
			foreach ( array( 'x-litespeed-cache', 'x-cache', 'cf-cache-status', 'x-varnish', 'x-proxy-cache', 'x-fastcgi-cache', 'x-sucuri-cache', 'x-cache-enabler', 'x-wp-super-cache' ) as $h ) {
				$v = (string) wp_remote_retrieve_header( $res, $h );
				if ( '' !== $v && ! ( 'cf-cache-status' === $h && preg_match( '~dynamic|bypass~i', $v ) ) ) {
					$sig[] = $h . ': ' . substr( $v, 0, 30 );
				}
			}
		}
		return array(
			'detected' => (bool) $sig,
			'signals'  => $sig,
			'checked'  => wp_date( 'Y-m-d H:i' ),
		);
	}

	/**
	 * Jedno źródło odsłon na instalację: przy wykrytym cache (i dzienniku nie wyłączonym) od jutra prawdą jest dziennik,
	 * bez cache: licznik PHP. Zmiana metody trafia do rejestru (widoczna w API jako adnotacja i w coverage()).
	 */
	public static function sync_method( array $reg ): array {
		$want = self::beacon_wanted( $reg ) ? self::LOG : self::METHOD;
		$last = count( $reg['methods'] ) - 1;
		if ( $last >= 0 && $reg['methods'][ $last ]['method'] !== $want ) {
			$today    = wp_date( 'Y-m-d' );
			$tomorrow = self::day_add( $today, 1 );
			if ( $reg['methods'][ $last ]['from'] > $today ) {
				// Zmiana jeszcze nie weszła: cofnij ją albo podmień.
				if ( $last > 0 && $reg['methods'][ $last - 1 ]['method'] === $want ) {
					array_pop( $reg['methods'] );
					$reg['methods'][ $last - 1 ]['to'] = '';
				} else {
					$reg['methods'][ $last ]['method'] = $want;
				}
			} else {
				$reg['methods'][ $last ]['to'] = $today;
				$reg['methods'][]              = array(
					'method'  => $want,
					'from'    => $tomorrow,
					'to'      => '',
					'started' => wp_date( 'Y-m-d H:i' ),
				);
			}
		}
		if ( 'colorify' === self::$ns ) {
			self::beacon_files( self::beacon_wanted( $reg ) );
		}
		return $reg;
	}

	/* ===================== Dziennik odsłon (strony z cache całych stron) ===================== */

	private static function beacon_wanted( array $reg ): bool {
		$b = (string) ( $reg['beacon'] ?? 'auto' );
		return 'off' !== $b && ( 'on' === $b || ! empty( $reg['cache']['detected'] ) ) && self::beacon_supported();
	}

	public static function log_dir(): string {
		return wp_upload_dir( null, false )['basedir'] . '/colorify-log';
	}

	/** Odbiornik (colorify-beacon.php) liczy katalog z własnego położenia: musi to być ten sam katalog, co uploads WordPressa. */
	public static function beacon_supported(): bool {
		$mine = realpath( dirname( __DIR__, 3 ) . '/uploads' );
		return false !== $mine && realpath( wp_upload_dir( null, false )['basedir'] ) === $mine;
	}

	/** Włącza odbiornik (on.php + ochrona katalogu) albo go wyłącza (zostają nieprzetworzone pliki do agregacji). */
	public static function beacon_files( bool $on ): void {
		$dir = self::log_dir();
		if ( ! $on ) {
			if ( is_file( $dir . '/on.php' ) ) {
				wp_delete_file( $dir . '/on.php' );
			}
			return;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$files = array(
			'.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
			'on.php'    => "<?php exit;\n",
		);
		foreach ( $files as $f => $body ) {
			if ( ! is_file( $dir . '/' . $f ) ) {
				file_put_contents( $dir . '/' . $f, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** Strona wysyła sygnał tylko w trybie dziennika (wykryty cache albo właściciel wymusił), poza tym skrypt się nie ładuje. */
	public static function beacon_active(): bool {
		$reg = self::registry();
		$b   = (string) $reg['beacon'];
		if ( 'off' === $b || ( 'on' !== $b && empty( $reg['cache']['detected'] ) ) ) {
			return false;
		}
		return self::beacon_supported();
	}

	/** wp_footer: kilkaset bajtów, bez ciasteczek i pamięci przeglądarki, jedno wysłanie na odsłonę, nie w prerenderze, bez ponowień. */
	public static function beacon_script(): void {
		if ( ! self::beacon_active() || ! self::in_scope() ) {
			return;
		}
		$is404 = self::$was_404 || is_404();
		$key   = $is404 ? '' : self::page_key();
		$cfg   = array(
			'u' => plugins_url( 'colorify-beacon.php', __FILE__ ),
			'k' => '/?s' === $key ? 's' : $key,
			't' => $is404 ? 'e' : 'v',
		);
		$js = '(function(){var d=document,l=location,c=' . wp_json_encode( $cfg ) . ',s=0;function g(){if(s||d.prerendering)return;s=1;var r="",q;try{r=d.referrer?new URL(d.referrer).hostname:""}catch(e){}'
			. 'q=c.u+"?t="+c.t+"&k="+encodeURIComponent(c.k||l.pathname)+"&r="+encodeURIComponent(r);'
			. 'var x=l.search.match(/[?&](fbclid|igshid|gclid|gbraid|wbraid|msclkid)=/);if(x)q+="&x="+x[1];'
			. 'l.search.replace(/[?&]utm_(source|medium|campaign)=([^&]{1,40})/g,function(a,b,v){q+="&u"+b[0]+"="+v});'
			. 'navigator.sendBeacon?navigator.sendBeacon(q):(new Image).src=q}'
			. 'd.prerendering?d.addEventListener("prerenderingchange",g,{once:true}):"complete"==d.readyState?g():(addEventListener("load",g),d.addEventListener("visibilitychange",function(){"hidden"==d.visibilityState&&g()}))})();';
		echo "<script id=\"colorify-an\">{$js}</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- stały skrypt + wp_json_encode.
	}

	/**
	 * Agreguje zamknięte pliki godzinowe dziennika (co najmniej 2 minuty po końcu godziny) do tabel z metodą 'log1'.
	 * Plik jest najpierw przenoszony (rename = jeden przebieg go dostaje), po zapisie zostaje jako done-*.php 48 godzin.
	 * ponytail: przerwanie w połowie agregacji zostawia work-*.php; nie jest liczony ponownie (wymaga ręcznego przejrzenia).
	 *
	 * @return array{files:int,views:int,bots:int,errors:int}
	 */
	public static function aggregate_log( ?int $now = null, string $dir = '' ): array {
		$now = $now ?? time();
		$dir = '' !== $dir ? $dir : self::log_dir();
		$out = array(
			'files'  => 0,
			'views'  => 0,
			'bots'   => 0,
			'errors' => 0,
		);
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$open = gmdate( 'YmdH', $now - 120 );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( (array) glob( $dir . '/log-*.php' ) as $file ) {
			$stamp = substr( basename( (string) $file, '.php' ), 4 );
			$work  = $dir . '/work-' . $stamp . '.php';
			if ( ! preg_match( '~^\d{10}$~', $stamp ) || $stamp >= $open || ! @rename( (string) $file, $work ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}
			$views  = array();
			$errors = array();
			$bots   = array();
			foreach ( (array) file( $work, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
				$f = explode( "\t", (string) $line );
				if ( ! ctype_digit( $f[0] ) || ! isset( $f[1] ) ) {
					continue;
				}
				$ts  = (int) $f[0];
				$day = wp_date( 'Y-m-d', $ts );
				if ( 'b' === $f[1] ) {
					$bots[ $day ] = ( $bots[ $day ] ?? 0 ) + 1;
					continue;
				}
				if ( count( $f ) < 9 ) {
					continue;
				}
				list( $cls, $dom ) = Colorify_Analytics_Rules::source( $f[3], $host, $f[4] );
				if ( 'e' === $f[1] ) {
					$path = self::norm_404( $f[2] );
					$ref  = 'internal' === $cls ? 'internal:' : $dom;
					if ( '' === $path ) {
						$bots[ $day ] = ( $bots[ $day ] ?? 0 ) + 1;
					} else {
						$k            = $day . "\t" . $path . "\t" . $ref;
						$errors[ $k ] = ( $errors[ $k ] ?? 0 ) + 1;
					}
					continue;
				}
				$page = ctype_digit( $f[2] ) ? $f[2] : ( 's' === $f[2] ? '/?s' : self::norm_path( $f[2] ) );
				$k    = implode( "\t", array( $day, wp_date( 'G', $ts ), $page, $cls, $dom, Colorify_Analytics_Rules::utm_value( $f[5] ), Colorify_Analytics_Rules::utm_value( $f[6] ), Colorify_Analytics_Rules::utm_value( $f[7] ), in_array( $f[8], array( 'mobile', 'tablet', 'desktop' ), true ) ? $f[8] : 'unknown' ) );
				$views[ $k ] = ( $views[ $k ] ?? 0 ) + 1;
			}
			foreach ( $views as $k => $n ) {
				list( $day, $hour, $page, $cls, $dom, $us, $um, $uc, $dev ) = explode( "\t", $k );
				self::write_view( self::LOG, $day, (int) $hour, $page, $cls, $dom, $dev, array_filter( array( 'utm_source' => $us, 'utm_medium' => $um, 'utm_campaign' => $uc ) ), $n, 0, 0 );
				$out['views'] += $n;
			}
			foreach ( $errors as $k => $n ) {
				list( $day, $path, $ref ) = explode( "\t", $k );
				self::write_404( self::LOG, $day, $path, $ref, $n );
				$out['errors'] += $n;
			}
			foreach ( $bots as $day => $n ) {
				self::bump( self::LOG, $day, 'bots', $n );
				$out['bots'] += $n;
			}
			@rename( $work, $dir . '/done-' . $stamp . '.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			++$out['files'];
		}
		foreach ( (array) glob( $dir . '/done-*.php' ) as $file ) {
			if ( filemtime( (string) $file ) < $now - 2 * DAY_IN_SECONDS ) {
				wp_delete_file( (string) $file );
			}
		}
		return $out;
	}

	/* ===================== Odinstalowanie ===================== */

	/** Tabele, opcje analityki, cache zapytań, zadanie cron i pliki dziennika. Dane innych modułów Colorify zostają. */
	public static function uninstall(): void {
		global $wpdb;
		foreach ( self::TABLES as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::t( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( self::opt( '' ) ) . '%', $wpdb->esc_like( '_transient_' . self::$ns . '_anq_' ) . '%', $wpdb->esc_like( '_transient_timeout_' . self::$ns . '_anq_' ) . '%' ) );
		if ( 'colorify' === self::$ns ) {
			wp_clear_scheduled_hook( 'colorify_an_cron' );
			$dir = self::log_dir();
			foreach ( array_merge( (array) glob( $dir . '/*.php' ), array( $dir . '/.htaccess' ) ) as $f ) {
				if ( is_file( (string) $f ) ) {
					wp_delete_file( (string) $f );
				}
			}
			if ( is_dir( $dir ) ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
	}
}

/**
 * Akapit do polityki prywatności (prosty język: co liczymy, czego nie zapisujemy, jak długo), HTML z twardymi spacjami.
 *
 * @param bool      $heading Z nagłówkiem h2 „Statystyki odwiedzin”.
 * @param bool|null $beacon  Ze zdaniem o dzienniku odsłon (domyślnie: gdy ta instalacja go używa).
 */
function colorify_analytics_privacy_text( bool $heading = true, ?bool $beacon = null ): string {
	$beacon = null === $beacon ? Colorify_Analytics::beacon_active() : $beacon;
	$out    = $heading ? '<h2>' . esc_html__( 'Statystyki odwiedzin', 'colorify-by-inyfinn' ) . "</h2>\n" : '';
	$out   .= '<p>Strona liczy odwiedziny własnym licznikiem działającym na&nbsp;jej serwerze, bez ciasteczek, skryptów śledzących i&nbsp;zewnętrznych narzędzi analitycznych.</p>' . "\n";
	$out   .= '<p><strong>Co&nbsp;jest liczone:</strong> ile razy otwarto każdą podstronę, skąd mniej więcej przyszło wejście (wyszukiwarka, media społecznościowe albo nazwa strony z&nbsp;linkiem), rodzaj urządzenia (telefon, tablet, komputer), godzina wejścia oraz adresy, pod którymi strona nie istnieje.</p>' . "\n";
	$out   .= '<p><strong>Co&nbsp;nie jest zapisywane:</strong> adres IP, pełna informacja o&nbsp;przeglądarce, pełne adresy stron, z&nbsp;których ktoś przyszedł, treść formularzy ani nic, z&nbsp;czego dałoby się odtworzyć, kto i&nbsp;co&nbsp;oglądał. Wejścia osób zalogowanych i&nbsp;robotów nie są&nbsp;liczone.</p>' . "\n";
	$out   .= '<p><strong>Szacunek liczby odwiedzających:</strong> żeby nie liczyć jednej osoby kilka razy w&nbsp;ciągu dnia, licznik zamienia adres IP i&nbsp;informację o&nbsp;przeglądarce w&nbsp;jednorazowy skrót, którego nie da&nbsp;się odwrócić. Klucz do&nbsp;skrótów zmienia się codziennie i&nbsp;jest kasowany po&nbsp;zakończeniu dnia, a&nbsp;same skróty najpóźniej po&nbsp;48&nbsp;godzinach. W&nbsp;statystykach zostaje tylko liczba.</p>' . "\n";
	if ( $beacon ) {
		$out .= '<p><strong>Sygnał odsłony:</strong> strona korzysta z&nbsp;pamięci podręcznej całych stron, więc po&nbsp;załadowaniu podstrony przeglądarka wysyła do&nbsp;serwera krótki sygnał: adres podstrony, nazwę strony, z&nbsp;której przyszło wejście, i&nbsp;rodzaj urządzenia. Sygnał nie zawiera adresu IP ani identyfikatora, a&nbsp;w&nbsp;przeglądarce nic nie jest zapisywane.</p>' . "\n";
	}
	$out .= '<p><strong>Jak długo:</strong> zbiorcze liczby (np.&nbsp;„strona główna: 40&nbsp;odsłon 12&nbsp;listopada”) są&nbsp;przechowywane przez 800&nbsp;dni, potem kasują się same.</p>' . "\n";
	$out .= '<p><strong>Podstawa:</strong> prawnie uzasadniony interes administratora strony (art.&nbsp;6 ust.&nbsp;1 lit.&nbsp;f&nbsp;RODO): wiedza, które treści są&nbsp;potrzebne i&nbsp;czy strona działa poprawnie.</p>' . "\n";
	return $out;
}

/** Ten sam akapit w przewodniku WordPressa „Polityka prywatności” (Ustawienia → Prywatność). */
function colorify_analytics_privacy_policy(): void {
	if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
		wp_add_privacy_policy_content( 'Colorify', colorify_analytics_privacy_text( false ) );
	}
}

add_action( 'init', array( 'Colorify_Analytics', 'maybe_install' ), 5 );
add_action( 'template_redirect', array( 'Colorify_Analytics', 'flag_404' ), 0 );
add_action( 'template_redirect', array( 'Colorify_Analytics', 'collect' ), 99 );
add_action( 'wp_footer', array( 'Colorify_Analytics', 'beacon_script' ), 99 );
add_action( 'colorify_an_cron', array( 'Colorify_Analytics', 'cron' ) );
add_action( 'admin_init', 'colorify_analytics_privacy_policy' );

require_once __DIR__ . '/colorify-analytics-query.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/colorify-analytics-cli.php';
}
