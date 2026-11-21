<?php
/**
 * WP-CLI: wp colorify-analytics ip status | on | off | days <n> | visitors [--limit=] [--bots] | block <adres> [--reason=] | unblock <adres> | blocked | forget <adres>
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/** Adresy IP odwiedzających i blokady (opcjonalne, domyślnie wyłączone). */
class Colorify_Analytics_IP_CLI {

	/**
	 * Stan: czy zapis adresów jest włączony, retencja, liczby i blokady.
	 */
	public function status() {
		$c = Colorify_Analytics_IP::counts();
		WP_CLI::line( 'Zapis adresów IP: ' . ( Colorify_Analytics_IP::enabled() ? 'WŁĄCZONY' : 'wyłączony' ) . ', retencja ' . Colorify_Analytics_IP::days() . ' dni' );
		WP_CLI::line( sprintf( 'W tabeli: %d adresów (boty %d, podejrzane %d); zablokowane wpisy: %d; wykluczone ze statystyk: %d', $c['total'], $c['bots'], $c['suspicious'], count( Colorify_Analytics_IP::blocklist() ), count( Colorify_Analytics::excluded_ips() ) ) );
	}

	/**
	 * Włącza zapis adresów IP odwiedzających (tworzy tabelę).
	 */
	public function on() {
		Colorify_Analytics_IP::configure( true );
		WP_CLI::success( 'Zapis adresów IP włączony, retencja ' . Colorify_Analytics_IP::days() . ' dni.' );
	}

	/**
	 * Wyłącza zapis nowych adresów (zapisane dane znikają po retencji).
	 */
	public function off() {
		Colorify_Analytics_IP::configure( false );
		WP_CLI::success( 'Zapis adresów IP wyłączony.' );
	}

	/**
	 * Ustawia retencję adresów w dniach (1 do 365, domyślnie 90).
	 *
	 * <dni>
	 * : Liczba dni.
	 */
	public function days( $args ) {
		Colorify_Analytics_IP::configure( Colorify_Analytics_IP::enabled(), (int) ( $args[0] ?? 90 ) );
		WP_CLI::success( 'Retencja: ' . Colorify_Analytics_IP::days() . ' dni.' );
	}

	/**
	 * Lista adresów od ostatnio widzianych.
	 *
	 * [--limit=<n>]
	 * : Ile wierszy (domyślnie 30).
	 *
	 * [--bots]
	 * : Tylko boty i podejrzane.
	 */
	public function visitors( $args, $assoc ) {
		$rows = array();
		foreach ( Colorify_Analytics_IP::rows( 500 ) as $r ) {
			if ( ! empty( $assoc['bots'] ) && ! $r['bot'] && ! $r['suspicious'] ) {
				continue;
			}
			$rows[] = array(
				'ip'      => $r['ip'],
				'ostatnio' => wp_date( 'Y-m-d H:i', $r['last_seen'] ),
				'wejsc'   => $r['hits'],
				'odslon'  => $r['views'],
				'formul'  => $r['forms'] + $r['quotes'],
				'znaczniki' => trim( ( $r['bot'] ? 'bot ' : '' ) . ( $r['suspicious'] ? 'podejrzany ' : '' ) . ( Colorify_Analytics_Rules::ip_in_list( $r['ip'], Colorify_Analytics::excluded_ips() ) ? 'wykluczony ' : '' ) . ( Colorify_Analytics_IP::blocked( $r['ip'] ) ? 'zablokowany' : '' ) ),
				'strona'  => $r['page'],
			);
			if ( count( $rows ) >= max( 1, (int) ( $assoc['limit'] ?? 30 ) ) ) {
				break;
			}
		}
		if ( ! $rows ) {
			WP_CLI::line( 'Brak adresów.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Blokuje adres albo zakres CIDR (403 na stronie, w REST, admin-ajax i logowaniu; zalogowany administrator przechodzi).
	 *
	 * <adres>
	 * : Adres IPv4/IPv6 albo zakres, np. 203.0.113.0/24.
	 *
	 * [--reason=<powod>]
	 * : Powód (do listy blokad).
	 */
	public function block( $args, $assoc ) {
		$user = wp_get_current_user();
		list( $ok, $code ) = Colorify_Analytics_IP::block( (string) ( $args[0] ?? '' ), (string) ( $assoc['reason'] ?? '' ), $user && $user->exists() ? $user->user_login : 'WP-CLI' );
		if ( ! $ok ) {
			WP_CLI::error( 'Nie zablokowano: ' . $code . ' (ip = niepoprawny adres, wide = zakres za szeroki, server = obejmuje serwer, dup = już jest, full = lista pełna).' );
		}
		WP_CLI::success( 'Zablokowano ' . Colorify_Analytics_IP::normalize( (string) $args[0] ) . '.' );
	}

	/**
	 * Zdejmuje blokadę (dokładnie ten wpis).
	 *
	 * <adres>
	 * : Adres albo zakres tak, jak na liście blokad.
	 */
	public function unblock( $args ) {
		if ( ! Colorify_Analytics_IP::unblock( (string) ( $args[0] ?? '' ) ) ) {
			WP_CLI::error( 'Takiego wpisu nie ma na liście blokad.' );
		}
		WP_CLI::success( 'Odblokowano.' );
	}

	/**
	 * Lista blokad.
	 */
	public function blocked() {
		$rows = array();
		foreach ( Colorify_Analytics_IP::blocklist() as $e ) {
			$rows[] = array( 'adres' => $e['ip'], 'powod' => $e['reason'], 'data' => wp_date( 'Y-m-d H:i', (int) $e['ts'] ), 'kto' => $e['by'] );
		}
		if ( ! $rows ) {
			WP_CLI::line( 'Lista blokad jest pusta.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	/**
	 * Usuwa jeden adres z zapisu odwiedzających.
	 *
	 * <adres>
	 * : Adres IP.
	 */
	public function forget( $args ) {
		WP_CLI::line( Colorify_Analytics_IP::forget( (string) ( $args[0] ?? '' ) ) ? 'Usunięto adres z zapisu.' : 'Tego adresu nie ma w zapisie.' );
	}
}

WP_CLI::add_command( 'colorify-analytics ip', 'Colorify_Analytics_IP_CLI' );
