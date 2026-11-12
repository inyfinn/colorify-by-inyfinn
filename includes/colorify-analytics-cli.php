<?php
/**
 * WP-CLI: wp colorify-analytics status | migrate | purge [--dry-run] | beacon <auto|on|off>
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/** Analityka Colorify: stan pomiaru, migracja starego licznika, sprzątanie, dziennik odsłon. */
class Colorify_Analytics_CLI {

	/**
	 * Stan: schemat, tabele, metoda i „dane od”, dziś, boty, cache, porównanie ze starym licznikiem (dni wspólne).
	 */
	public function status() {
		global $wpdb;
		Colorify_Analytics::maybe_install();
		$c   = colorify_analytics_coverage();
		$reg = Colorify_Analytics::registry();
		WP_CLI::line( 'Schemat: ' . (int) get_option( Colorify_Analytics::opt( 'schema' ) ) . ' (kod: ' . Colorify_Analytics::SCHEMA . ')' );
		foreach ( Colorify_Analytics::TABLES as $t ) {
			$name = Colorify_Analytics::t( $t );
			$n    = $wpdb->get_var( "SELECT COUNT(*) FROM {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			WP_CLI::line( sprintf( '  %-36s %s', $name, null === $n ? 'BRAK TABELI' : $n . ' wierszy' ) );
		}
		WP_CLI::line( 'Metoda dziś: ' . $c['method'] . ' (' . $c['method_label'] . '), od ' . $c['since'] . ( '' !== $c['next_method'] ? '; od ' . $c['next_from'] . ': ' . $c['next_method'] : '' ) );
		WP_CLI::line( 'Dane dostępne od: ' . $c['data_since'] . '; źródła, urządzenia i godziny od: ' . $c['dims_since'] );
		foreach ( $c['methods'] as $m ) {
			WP_CLI::line( sprintf( '  %-7s %s .. %s  (uruchomiona %s)', $m['method'], $m['from'], '' !== $m['to'] ? $m['to'] : 'dziś', '' !== $m['started'] ? $m['started'] : '-' ) );
		}
		WP_CLI::line( 'Stan: ' . $c['state'] . ' — ' . $c['state_label'] );
		$today = $wpdb->get_row( $wpdb->prepare( 'SELECT views, visitors, bots, prefetch, spec FROM ' . Colorify_Analytics::t( 'daily_totals' ) . ' WHERE day = %s AND method = %s', wp_date( 'Y-m-d' ), Colorify_Analytics::METHOD ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$today = (array) $today + array_fill_keys( array( 'views', 'visitors', 'bots', 'prefetch', 'spec' ), 0 );
		WP_CLI::line( sprintf( 'Dziś (licznik serwerowy): odsłony %d (w tym prefetch %d), odwiedzający-szacunek %d, boty %d, pominięte wstępne pobrania %d', $today['views'], $today['prefetch'], $today['visitors'], $today['bots'], $today['spec'] ) );
		WP_CLI::line( sprintf( 'Boty, ostatnie pełne dni (%d z 7): %d botów / %d odsłon ludzi = %.1f%%%s', $c['bots']['days'], $c['bots']['bots'], $c['bots']['views'], 100 * $c['bots']['share'], $c['bots']['ready'] ? '' : ' (za mało dni)' ) );
		WP_CLI::line( 'Utracone zapisy: ' . $c['lost_writes'] );
		WP_CLI::line( 'Cache całych stron: ' . ( $c['cache']['detected'] ? 'TAK (' . implode( ', ', $c['cache']['signals'] ) . ')' : 'nie wykryto' ) . ( '' !== $c['cache']['checked'] ? ', sprawdzono ' . $c['cache']['checked'] : ', jeszcze nie sprawdzano' ) );
		WP_CLI::line( 'Dziennik odsłon: ustawienie ' . $c['beacon']['setting'] . ', ' . ( $c['beacon']['active'] ? 'skrypt na stronie' : 'skrypt się nie ładuje' ) . ( $c['beacon']['supported'] ? '' : ', NIEOBSŁUGIWANY układ katalogów' ) );
		$next = wp_next_scheduled( 'colorify_an_cron' );
		WP_CLI::line( 'Cron colorify_an_cron: ' . ( $next ? wp_date( 'Y-m-d H:i', $next ) : 'BRAK' ) . '; ostatni przebieg dzienny: ' . ( '' !== $reg['daily'] ? $reg['daily'] : '-' ) );

		$old  = get_option( 'colorify_visits', array() );
		$rows = array();
		$new  = $wpdb->get_results( $wpdb->prepare( 'SELECT day, views, visitors, bots, prefetch, spec FROM ' . Colorify_Analytics::t( 'daily_totals' ) . ' WHERE method = %s ORDER BY day DESC LIMIT 14', Colorify_Analytics::METHOD ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array_reverse( (array) $new ) as $r ) {
			$o      = is_array( $old ) && isset( $old[ $r['day'] ] ) ? $old[ $r['day'] ] : array();
			$rows[] = array(
				'dzien'        => $r['day'],
				'stary_odslony' => (int) ( $o['v'] ?? 0 ),
				'stary_unikalni' => (int) ( $o['u'] ?? 0 ),
				'nowy_odslony'  => (int) $r['views'],
				'nowy_szacunek' => (int) $r['visitors'],
				'boty'          => (int) $r['bots'],
				'prefetch'      => (int) $r['prefetch'],
				'pominiete'     => (int) $r['spec'],
			);
		}
		if ( $rows ) {
			WP_CLI::line( 'Stary licznik vs nowy (dni z nowym licznikiem):' );
			WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
		}
	}

	/**
	 * Przenosi historię starego licznika (opcja colorify_visits, bez zmian w niej) do tabel z metodą legacy.
	 */
	public function migrate() {
		Colorify_Analytics::maybe_install();
		$r = Colorify_Analytics::migrate_legacy();
		WP_CLI::success( sprintf( 'Stary licznik: %d dni, %d wierszy stron (metoda legacy).', $r['days'], $r['pages'] ) );
	}

	/**
	 * Usuwa agregaty starsze niż 800 dni i skróty odwiedzających z poprzednich dni.
	 *
	 * [--dry-run]
	 * : Tylko policz, nic nie usuwaj.
	 */
	public function purge( $args, $assoc ) {
		$dry = ! empty( $assoc['dry-run'] );
		$r   = Colorify_Analytics::purge( $dry );
		WP_CLI::line( ( $dry ? 'Do usunięcia' : 'Usunięto' ) . ' (granica: dni przed ' . $r['cut'] . '; skróty: dni przed dziś):' );
		foreach ( $r['rows'] as $t => $n ) {
			WP_CLI::line( sprintf( '  %-18s %d', $t, $n ) );
		}
	}

	/**
	 * Dziennik odsłon dla stron z cache całych stron.
	 *
	 * <tryb>
	 * : auto (włącza się, gdy wykryto cache), on (zawsze), off (nigdy).
	 */
	public function beacon( $args ) {
		$mode = (string) ( $args[0] ?? '' );
		if ( ! in_array( $mode, array( 'auto', 'on', 'off' ), true ) ) {
			WP_CLI::error( 'Tryb: auto | on | off' );
		}
		$reg           = Colorify_Analytics::registry();
		$reg['beacon'] = $mode;
		Colorify_Analytics::save_registry( Colorify_Analytics::sync_method( $reg ) );
		WP_CLI::success( 'Dziennik odsłon: ' . $mode . ( Colorify_Analytics::beacon_active() ? ' (skrypt na stronie)' : ' (skrypt się nie ładuje)' ) );
	}
}

WP_CLI::add_command( 'colorify-analytics', 'Colorify_Analytics_CLI' );
