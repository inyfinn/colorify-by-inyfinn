<?php
/**
 * Adresy IP odwiedzających i blokady (bezpieczeństwo: spam, boty, skanery). Opcjonalne i domyślnie WYŁĄCZONE: wtyczka działa
 * na wielu stronach, a zapis adresów jest decyzją właściciela konkretnej strony (opcja colorify_an_ip_log, Kokpit → Ruch na
 * stronie → Odwiedzający i blokady, albo `wp colorify-analytics ip on`).
 *
 * Tabela {prefix}colorify_ip: jeden wiersz na adres (pierwsze i ostatnie wejście, liczniki, ostatnia strona, skrócona
 * przeglądarka). Retencja domyślnie 90 dni (opcja colorify_an_ip_days), czyszczenie w cronie analityki. Ten zapis jest
 * niezależny od statystyk ruchu: wykluczony adres (opcja colorify_an_exclude_ips) nie wchodzi do odsłon, ale tu jest widoczny.
 *
 * Blokada: lista adresów i zakresów CIDR w opcji colorify_an_blocklist. Zablokowany adres dostaje 403 na stronie, w REST,
 * admin-ajax, XML-RPC i logowaniu (sprawdzenie na plugins_loaded, zanim WordPress cokolwiek zbuduje). Nie blokujemy:
 * zalogowanego administratora (manage_options), adresu pętli zwrotnej i samego serwera, wp-cron ani WP-CLI. Strony serwowane
 * z cache całych stron (LiteSpeed itp.) mogą dojść do zablokowanego adresu, zanim wtyczka coś zobaczy (ograniczenie).
 * Formularze liczy hook colorify_analytics_form_submit (do_action z rodzaju: 'zgloszenie', 'wycena', 'opinia').
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

final class Colorify_Analytics_IP {

	const SCHEMA   = 1;
	const MAX_ROWS = 50000;
	const BLOCKMAX = 1000;
	/** Progi „podejrzany”: wejść na minutę, prób nieistniejących adresów (skanery), wysłanych formularzy. */
	const FAST  = 30;
	const SCANS = 3;
	const FORMS  = 5;
	/** Obliczenia wyceny z jednego adresu (kalkulator woła je przy zmianie pakietu, więc próg jest wysoki). */
	const QUOTES = 40;

	public static function t(): string {
		return Colorify_Analytics::t( 'ip' );
	}

	/* ===================== Ustawienia ===================== */

	public static function enabled(): bool {
		return (bool) get_option( Colorify_Analytics::opt( 'ip_log' ), 0 );
	}

	public static function days(): int {
		return max( 1, min( 365, (int) get_option( Colorify_Analytics::opt( 'ip_days' ), 90 ) ) );
	}

	/** Włącza albo wyłącza zapis adresów i ustawia retencję (dni). Przy włączeniu tworzy tabelę. */
	public static function configure( bool $on, ?int $days = null ): void {
		if ( null !== $days ) {
			update_option( Colorify_Analytics::opt( 'ip_days' ), max( 1, min( 365, $days ) ), true );
		}
		update_option( Colorify_Analytics::opt( 'ip_log' ), $on ? 1 : 0, true );
		if ( $on ) {
			self::install();
		}
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $GLOBALS['wpdb']->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::t() . " (
ip varbinary(16) NOT NULL,
first_seen int(10) unsigned NOT NULL,
last_seen int(10) unsigned NOT NULL,
hits int(10) unsigned NOT NULL DEFAULT 0,
views int(10) unsigned NOT NULL DEFAULT 0,
bot_hits int(10) unsigned NOT NULL DEFAULT 0,
scans int(10) unsigned NOT NULL DEFAULT 0,
forms int(10) unsigned NOT NULL DEFAULT 0,
quotes int(10) unsigned NOT NULL DEFAULT 0,
win_start int(10) unsigned NOT NULL DEFAULT 0,
win_hits int(10) unsigned NOT NULL DEFAULT 0,
max_rate int(10) unsigned NOT NULL DEFAULT 0,
page varchar(191) NOT NULL DEFAULT '',
ua varchar(120) NOT NULL DEFAULT '',
PRIMARY KEY  (ip),
KEY last_seen (last_seen)
) $c;"
		);
		update_option( Colorify_Analytics::opt( 'ip_schema' ), self::SCHEMA, true );
	}

	public static function maybe_install(): void {
		if ( self::enabled() && (int) get_option( Colorify_Analytics::opt( 'ip_schema' ) ) !== self::SCHEMA ) {
			self::install();
		}
	}

	/* ===================== Zapis ===================== */

	/**
	 * Jedno żądanie strony (woła Colorify_Analytics::record, także dla botów i adresów wykluczonych ze statystyk).
	 *
	 * @param array  $r    żądanie jak w Colorify_Analytics::record().
	 * @param string $kind human|prefetch|bot|speculative.
	 */
	public static function log( array $r, string $kind ): void {
		if ( ! self::enabled() || '' === (string) $r['ip'] ) {
			return;
		}
		$scan = ! empty( $r['is_404'] ) && '' === Colorify_Analytics::norm_404( (string) $r['uri'] );
		self::touch(
			(string) $r['ip'],
			array(
				'hits'     => 1,
				'views'    => ( 'human' === $kind || 'prefetch' === $kind ) && empty( $r['is_404'] ) ? 1 : 0,
				'bot_hits' => 'bot' === $kind ? 1 : 0,
				'scans'    => $scan ? 1 : 0,
			),
			Colorify_Analytics::norm_path( (string) $r['uri'] ),
			(string) $r['ua'],
			(int) $r['time']
		);
	}

	/** Wysłany formularz (hook colorify_analytics_form_submit): 'wycena' liczy się osobno od zgłoszeń i opinii. */
	public static function form_submit( $kind = 'zgloszenie' ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$ip = Colorify_Analytics::client_ip();
		if ( '' === $ip ) {
			return;
		}
		self::touch( $ip, array( 'quotes' => 'wycena' === $kind ? 1 : 0, 'forms' => 'wycena' === $kind ? 0 : 1 ) );
	}

	/** Atomowy zapis (INSERT ... ON DUPLICATE KEY UPDATE), tempo liczone w oknie 60 s. */
	public static function touch( string $ip, array $inc, string $page = '', string $ua = '', ?int $now = null ): void {
		global $wpdb;
		$bin = Colorify_Analytics_Rules::ip_bin( $ip );
		if ( '' === $bin ) {
			return;
		}
		$now = $now ?? time();
		$inc = array_map( 'intval', $inc ) + array( 'hits' => 0, 'views' => 0, 'bot_hits' => 0, 'scans' => 0, 'forms' => 0, 'quotes' => 0 );
		$ua  = mb_substr( (string) preg_replace( '~[\x00-\x1F\x7F]+~', '', $ua ), 0, 120, 'UTF-8' );
		$h   = bin2hex( $bin );
		if ( ! self::has_room() ) { // tabela pełna: tylko adresy, które już znamy
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t() . ' SET last_seen = GREATEST(last_seen, %d), hits = hits + %d, views = views + %d, bot_hits = bot_hits + %d, scans = scans + %d, forms = forms + %d, quotes = quotes + %d WHERE ip = UNHEX(%s)', $now, $inc['hits'], $inc['views'], $inc['bot_hits'], $inc['scans'], $inc['forms'], $inc['quotes'], $h ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return;
		}
		// Kolejność przypisań ma znaczenie: win_hits czyta jeszcze stare win_start, max_rate czyta już nowe win_hits.
		$sql = $wpdb->prepare(
			'INSERT INTO ' . self::t() . ' (ip,first_seen,last_seen,hits,views,bot_hits,scans,forms,quotes,win_start,win_hits,max_rate,page,ua) VALUES (UNHEX(%s),%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%s,%s)
			ON DUPLICATE KEY UPDATE last_seen = GREATEST(last_seen, %d), hits = hits + %d, views = views + %d, bot_hits = bot_hits + %d, scans = scans + %d, forms = forms + %d, quotes = quotes + %d,
			win_hits = IF(%d - CAST(win_start AS SIGNED) > 60, %d, win_hits + %d), win_start = IF(%d - CAST(win_start AS SIGNED) > 60, %d, win_start), max_rate = GREATEST(max_rate, win_hits),
			page = IF(%s <> \'\', %s, page), ua = IF(%s <> \'\', %s, ua)',
			$h, $now, $now, $inc['hits'], $inc['views'], $inc['bot_hits'], $inc['scans'], $inc['forms'], $inc['quotes'], $now, $inc['hits'], $inc['hits'], $page, $ua,
			$now, $inc['hits'], $inc['views'], $inc['bot_hits'], $inc['scans'], $inc['forms'], $inc['quotes'],
			$now, $inc['hits'], $inc['hits'], $now, $now,
			$page, $page, $ua, $ua
		);
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			update_option( Colorify_Analytics::opt( 'lost' ), (int) get_option( Colorify_Analytics::opt( 'lost' ), 0 ) + 1, false );
			if ( false !== stripos( (string) $wpdb->last_error, "doesn't exist" ) ) {
				self::install();
			}
		}
	}

	/** Czy tabela przyjmie nowe adresy (limit 50 tys. wierszy; liczone raz na 5 minut). */
	private static function has_room(): bool {
		global $wpdb;
		$key = Colorify_Analytics::$ns . '_anq_ipcount';
		$n   = get_transient( $key );
		if ( false === $n ) {
			$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::t() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			set_transient( $key, $n, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $n < self::MAX_ROWS;
	}

	/** Czyszczenie po retencji (partiami po 1000), z crona analityki. @return int usunięte wiersze */
	public static function purge( int $batches = 10, ?int $now = null ): int {
		global $wpdb;
		$cut  = ( $now ?? time() ) - self::days() * DAY_IN_SECONDS;
		$gone = 0;
		if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t() ) ) ) {
			return 0;
		}
		for ( $i = 0; $i < $batches; $i++ ) {
			$d = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE last_seen < %d LIMIT 1000', $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$gone += $d;
			if ( $d < 1000 ) {
				break;
			}
		}
		return $gone;
	}

	/** Usuwa jeden adres z zapisu (żądanie właściciela, np. „zapomnij ten adres”). */
	public static function forget( string $ip ): bool {
		global $wpdb;
		$bin = Colorify_Analytics_Rules::ip_bin( $ip );
		return '' !== $bin && (bool) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::t() . ' WHERE ip = UNHEX(%s)', bin2hex( $bin ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ===================== Odczyt ===================== */

	/**
	 * Adresy z ostatnich dni retencji, od ostatnio widzianych. Każdy wiersz ma też flagi: bot, suspicious, reasons.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( int $limit = 300 ): array {
		global $wpdb;
		$out = array();
		if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t() ) ) ) {
			return $out;
		}
		$res = $wpdb->get_results( $wpdb->prepare( 'SELECT ip, first_seen, last_seen, hits, views, bot_hits, scans, forms, quotes, max_rate, page, ua FROM ' . self::t() . ' ORDER BY last_seen DESC LIMIT %d', max( 1, $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $res as $r ) {
			$r['ip'] = (string) inet_ntop( (string) $r['ip'] );
			foreach ( array( 'first_seen', 'last_seen', 'hits', 'views', 'bot_hits', 'scans', 'forms', 'quotes', 'max_rate' ) as $k ) {
				$r[ $k ] = (int) $r[ $k ];
			}
			$out[] = $r + self::flags( $r );
		}
		return $out;
	}

	/** @return array{total:int,bots:int,suspicious:int} liczby z całej tabeli (nie tylko pokazanych wierszy) */
	public static function counts(): array {
		global $wpdb;
		$z = array( 'total' => 0, 'bots' => 0, 'suspicious' => 0 );
		if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::t() ) ) ) {
			return $z;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS total, COALESCE(SUM(bot_hits > 0 AND bot_hits > views),0) AS bots, COALESCE(SUM(max_rate >= %d OR scans >= %d OR forms >= %d OR quotes >= %d),0) AS susp FROM ' . self::t(), self::FAST, self::SCANS, self::FORMS, self::QUOTES ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $r ? array( 'total' => (int) $r['total'], 'bots' => (int) $r['bots'], 'suspicious' => (int) $r['susp'] ) : $z;
	}

	/**
	 * Znaczniki adresu: bot (przeglądarka wygląda na robota albo skaner plików, i większość wejść to roboty) i podejrzany
	 * (ponad 30 wejść na minutę, kilka prób nieistniejących adresów albo wiele wysłanych formularzy). Bez JavaScriptu na
	 * stronie nie mamy sygnału „przeglądarka z JS”, więc go nie używamy.
	 *
	 * @return array{bot:bool,suspicious:bool,reasons:string[]}
	 */
	public static function flags( array $r ): array {
		$reasons = array();
		if ( (int) $r['max_rate'] >= self::FAST ) {
			/* translators: %d: wejść na minutę */
			$reasons[] = sprintf( __( 'ponad %d wejść na minutę', 'colorify-by-inyfinn' ), self::FAST );
		}
		if ( (int) $r['scans'] >= self::SCANS ) {
			$reasons[] = __( 'próby wejścia na nieistniejące adresy (skaner)', 'colorify-by-inyfinn' );
		}
		if ( (int) $r['forms'] >= self::FORMS || (int) $r['quotes'] >= self::QUOTES ) {
			$reasons[] = __( 'wiele wysłanych formularzy', 'colorify-by-inyfinn' );
		}
		return array(
			'bot'        => (int) $r['bot_hits'] > 0 && (int) $r['bot_hits'] > (int) $r['views'],
			'suspicious' => (bool) $reasons,
			'reasons'    => $reasons,
		);
	}

	/* ===================== Blokady ===================== */

	/** @return array<int,array{ip:string,reason:string,ts:int,by:string}> */
	public static function blocklist(): array {
		$l = get_option( Colorify_Analytics::opt( 'blocklist' ), array() );
		return is_array( $l ) ? array_values( array_filter( $l, static fn( $e ) => is_array( $e ) && ! empty( $e['ip'] ) ) ) : array();
	}

	/** Wpis blokady pasujący do adresu (pojedynczy adres albo zakres) albo null. */
	public static function blocked( string $ip, ?array $list = null ): ?array {
		foreach ( $list ?? self::blocklist() as $e ) {
			if ( Colorify_Analytics_Rules::ip_in_list( $ip, array( $e['ip'] ) ) ) {
				return $e;
			}
		}
		return null;
	}

	/** Adres albo zakres CIDR w postaci kanonicznej ('' gdy niepoprawny). */
	public static function normalize( string $input ): string {
		$input = trim( $input );
		$list  = Colorify_Analytics_Rules::ip_list( array( $input ) );
		if ( 1 !== count( $list ) ) {
			return '';
		}
		$p = explode( '/', $list[0], 2 );
		return Colorify_Analytics_Rules::ip_norm( $p[0] ) . ( isset( $p[1] ) ? '/' . (int) $p[1] : '' );
	}

	/**
	 * Dodaje blokadę. Odmawia, gdy wpis obejmowałby adres administratora, który to robi, pętlę zwrotną albo sam serwer,
	 * albo jest zbyt szeroki (IPv4 szerszy niż /16, IPv6 szerszy niż /32).
	 *
	 * @return array{0:bool,1:string} [ok, kod: ok|ip|self|server|wide|dup|full]
	 */
	public static function block( string $input, string $reason = '', string $by = '', string $admin_ip = '' ): array {
		$e = self::normalize( $input );
		if ( '' === $e ) {
			return array( false, 'ip' );
		}
		$p    = explode( '/', $e, 2 );
		$v6   = false !== strpos( $p[0], ':' );
		$bits = isset( $p[1] ) ? (int) $p[1] : ( $v6 ? 128 : 32 );
		if ( $bits < ( $v6 ? 32 : 16 ) ) {
			return array( false, 'wide' );
		}
		if ( '' !== $admin_ip && Colorify_Analytics_Rules::ip_in_list( $admin_ip, array( $e ) ) ) {
			return array( false, 'self' );
		}
		$srv = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		foreach ( array( '127.0.0.1', '::1', $srv ) as $safe ) {
			if ( '' !== $safe && Colorify_Analytics_Rules::ip_in_list( $safe, array( $e ) ) ) {
				return array( false, 'server' );
			}
		}
		$list = self::blocklist();
		foreach ( $list as $x ) {
			if ( $x['ip'] === $e ) {
				return array( false, 'dup' );
			}
		}
		if ( count( $list ) >= self::BLOCKMAX ) {
			return array( false, 'full' );
		}
		$list[] = array(
			'ip'     => $e,
			'reason' => mb_substr( sanitize_text_field( $reason ), 0, 200 ),
			'ts'     => time(),
			'by'     => mb_substr( sanitize_text_field( $by ), 0, 60 ),
		);
		update_option( Colorify_Analytics::opt( 'blocklist' ), $list, true );
		return array( true, 'ok' );
	}

	/** Zdejmuje blokadę (dokładnie ten wpis albo wpis obejmujący pojedynczy adres). */
	public static function unblock( string $input ): bool {
		$e    = self::normalize( $input );
		$list = self::blocklist();
		$new  = array_values( array_filter( $list, static fn( $x ) => '' === $e || $x['ip'] !== $e ) );
		if ( '' === $e || count( $new ) === count( $list ) ) {
			return false;
		}
		update_option( Colorify_Analytics::opt( 'blocklist' ), $new, true );
		return true;
	}

	/**
	 * Zablokowany adres dostaje 403, zanim WordPress cokolwiek zbuduje (strona, REST, admin-ajax, XML-RPC, logowanie).
	 * Wyjątki: zalogowany administrator, pętla zwrotna i serwer, wp-cron, WP-CLI.
	 */
	public static function enforce(): void {
		if ( 'cli' === PHP_SAPI || empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return;
		}
		$list = self::blocklist();
		if ( ! $list ) {
			return;
		}
		$ip = Colorify_Analytics::client_ip();
		if ( null === self::blocked( $ip, $list ) || 'wp-cron.php' === basename( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}
		$srv = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		if ( Colorify_Analytics_Rules::ip_in_list( $ip, array_filter( array( '127.0.0.1', '::1', $srv ) ) ) ) {
			return;
		}
		// Zalogowany administrator przechodzi. Sprawdzamy sam plik cookie „logged_in” (wp_validate_logged_in_cookie() w wp-admin
		// zawsze zwraca false), bo jeszcze nikt nie ustalił bieżącego użytkownika.
		if ( defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) && function_exists( 'wp_validate_auth_cookie' ) ) {
			$uid = (int) wp_validate_auth_cookie( (string) wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ), 'logged_in' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( $uid > 0 && user_can( $uid, 'manage_options' ) ) {
				return;
			}
		}
		if ( ! headers_sent() ) {
			http_response_code( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: no-store' );
		}
		echo 'Dostęp zablokowany.';
		exit;
	}
}

add_action( 'plugins_loaded', array( 'Colorify_Analytics_IP', 'enforce' ), 1 );
add_action( 'init', array( 'Colorify_Analytics_IP', 'maybe_install' ), 6 );
add_action( 'colorify_analytics_form_submit', array( 'Colorify_Analytics_IP', 'form_submit' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/colorify-analytics-ip-cli.php';
}
