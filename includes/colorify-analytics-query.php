<?php
/**
 * API zapytań analityki Colorify dla kokpitu: czyste funkcje zwracające tablice (bez HTML), wyniki SQL w cache 5 minut.
 * Daty to 'Y-m-d' w strefie WordPressa, zakresy obustronnie domknięte. Każda funkcja czyta dla każdego dnia tylko
 * metodę, którą rejestr uznaje za prawdę tego dnia (nigdy suma metod). Uprawnienia sprawdza wywołujący.
 * Sygnatury i kształty wyników: docs/kokpit-analityka.md.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/** Próg „mała próba”: poniżej tej liczby wynik jest pokazywany, ale z ostrzeżeniem i bez wniosków. */
const COLORIFY_ANALYTICS_LOW_SAMPLE = 50;

/* ===================== Pomocnicze ===================== */

/** @return array<int,array{0:string,1:string,2:string}> [metoda, od, do] przecięte z zakresem. */
function colorify_an_segments( string $from, string $to ): array {
	$ms  = Colorify_Analytics::registry()['methods'];
	$out = array();
	foreach ( $ms as $i => $m ) {
		$a = max( $from, $m['from'] );
		$b = min( $to, isset( $ms[ $i + 1 ] ) ? Colorify_Analytics::day_add( $ms[ $i + 1 ]['from'], -1 ) : '9999-12-31' );
		if ( $a <= $b ) {
			$out[] = array( $m['method'], $a, $b );
		}
	}
	return $out;
}

/** Warunek SQL „dzień z zakresu i metoda prawdziwa dla tego dnia”. */
function colorify_an_where( string $from, string $to ): string {
	global $wpdb;
	$parts = array();
	foreach ( colorify_an_segments( $from, $to ) as $s ) {
		$parts[] = $wpdb->prepare( '(day BETWEEN %s AND %s AND method = %s)', $s[1], $s[2], $s[0] );
	}
	return $parts ? '(' . implode( ' OR ', $parts ) . ')' : '1=0';
}

/** Pierwszy dzień z przekrojami (źródła, urządzenia, godziny, wejścia z zewnątrz, 404); stary licznik ich nie miał. */
function colorify_an_dims_from(): string {
	$meta = Colorify_Analytics::methods_meta();
	foreach ( Colorify_Analytics::registry()['methods'] as $m ) {
		if ( ! empty( $meta[ $m['method'] ]['dims'] ) ) {
			return $m['from'];
		}
	}
	return '';
}

/** Wynik SQL w cache 5 minut (klucz: funkcja, argumenty, rejestr metod). */
function colorify_an_cached( string $what, array $args, callable $fn ) {
	if ( Colorify_Analytics::$nocache ) {
		return $fn();
	}
	$key = Colorify_Analytics::$ns . '_anq_' . md5( $what . '|' . Colorify_Analytics::SCHEMA . '|' . wp_json_encode( $args ) . '|' . wp_json_encode( Colorify_Analytics::registry()['methods'] ) );
	$hit = get_transient( $key );
	if ( false !== $hit ) {
		return $hit;
	}
	$val = $fn();
	set_transient( $key, $val, 5 * MINUTE_IN_SECONDS );
	return $val;
}

function colorify_an_days( string $from, string $to ): int {
	return (int) round( ( strtotime( $to . ' 00:00:00 UTC' ) - strtotime( $from . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
}

function colorify_an_share( int $part, int $all ): float {
	return $all > 0 ? round( $part / $all, 4 ) : 0.0;
}

/* ===================== Zakres ===================== */

/**
 * Zakres raportu: preset 7/30/90/365 (pełne dni do wczoraj, opcjonalnie z dzisiejszym niepełnym) albo własne daty.
 *
 * @param array $args days (7|30|90|365), today (bool), from, to ('Y-m-d'), now (znacznik czasu, testy).
 * @return array{from:string,to:string,days:int,preset:int,with_today:bool,today:string,prev_from:string,prev_to:string,error:string}
 */
function colorify_analytics_range( array $args = array() ): array {
	$now    = isset( $args['now'] ) ? (int) $args['now'] : time();
	$today  = wp_date( 'Y-m-d', $now );
	$with   = ! empty( $args['today'] );
	$preset = (int) ( $args['days'] ?? 30 );
	$from   = (string) ( $args['from'] ?? '' );
	$to     = (string) ( $args['to'] ?? '' );
	$err    = '';
	if ( '' !== $from || '' !== $to ) {
		if ( ! Colorify_Analytics::is_day( $from ) || ! Colorify_Analytics::is_day( $to ) || $from > $to || $to > $today ) {
			$err    = __( 'Niepoprawny zakres dat. Pokazuję ostatnie 30 dni.', 'colorify-by-inyfinn' );
			$preset = 30;
			$with   = false;
		} else {
			$preset = 0;
			$with   = $to === $today;
			if ( colorify_an_days( $from, $to ) > Colorify_Analytics::KEEP_DAYS ) {
				$from = Colorify_Analytics::day_add( $to, -( Colorify_Analytics::KEEP_DAYS - 1 ) );
				$err  = __( 'Zakres skrócony do 800 dni (tyle przechowujemy).', 'colorify-by-inyfinn' );
			}
		}
	}
	if ( 0 !== $preset ) {
		$preset = in_array( $preset, array( 7, 30, 90, 365 ), true ) ? $preset : 30;
		$to     = $with ? $today : Colorify_Analytics::day_add( $today, -1 );
		$from   = Colorify_Analytics::day_add( $to, -( $preset - 1 ) );
	}
	$days = colorify_an_days( $from, $to );
	return array(
		'from'       => $from,
		'to'         => $to,
		'days'       => $days,
		'preset'     => $preset,
		'with_today' => $with,
		'today'      => $today,
		'prev_from'  => Colorify_Analytics::day_add( $from, -$days ),
		'prev_to'    => Colorify_Analytics::day_add( $from, -1 ),
		'error'      => $err,
	);
}

/* ===================== Sumy ===================== */

/** @return array{views:int,visitors:int,bots:int,prefetch:int,spec:int,measured:int,methods:string[],days:array<string,array>} */
function colorify_an_sum( string $from, string $to ): array {
	return colorify_an_cached(
		'sum',
		array( $from, $to ),
		static function () use ( $from, $to ) {
			global $wpdb;
			$rows = $wpdb->get_results( 'SELECT day, method, views, visitors, bots, prefetch, spec, state FROM ' . Colorify_Analytics::t( 'daily_totals' ) . ' WHERE ' . colorify_an_where( $from, $to ) . ' ORDER BY day', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out  = array(
				'views'    => 0,
				'visitors' => 0,
				'bots'     => 0,
				'prefetch' => 0,
				'spec'     => 0,
				'measured' => 0,
				'methods'  => array(),
				'days'     => array(),
			);
			foreach ( (array) $rows as $r ) {
				foreach ( array( 'views', 'visitors', 'bots', 'prefetch', 'spec' ) as $k ) {
					$out[ $k ] += (int) $r[ $k ];
				}
				++$out['measured'];
				$out['methods'][ $r['method'] ] = true;
				$out['days'][ $r['day'] ]       = $r;
			}
			$out['methods'] = array_keys( $out['methods'] );
			return $out;
		}
	);
}

/** Czy porównanie z poprzednim okresem jest uczciwe. @return array{0:bool,1:string} [dozwolone, powód gdy nie] */
function colorify_an_comparable( array $cur, array $prev, int $days, string $to ): array {
	if ( $to >= wp_date( 'Y-m-d' ) ) {
		return array( false, 'partial_today' );
	}
	if ( $prev['measured'] < $days ) {
		return array( false, 'no_history' );
	}
	if ( $cur['measured'] < $days ) {
		return array( false, 'gaps' );
	}
	if ( 1 !== count( array_unique( array_merge( $cur['methods'], $prev['methods'] ) ) ) ) {
		return array( false, 'method_change' );
	}
	return array( true, '' );
}

/**
 * Główne liczby zakresu z poprzednim okresem tej samej długości.
 * visitors_avg = średnia dzienna szacunku przez zmierzone dni (dni z zerem wliczone); null, gdy metoda nie daje szacunku.
 */
function colorify_analytics_totals( string $from, string $to ): array {
	$days = colorify_an_days( $from, $to );
	$pf   = Colorify_Analytics::day_add( $from, -$days );
	$pt   = Colorify_Analytics::day_add( $from, -1 );
	$cur  = colorify_an_sum( $from, $to );
	$prev = colorify_an_sum( $pf, $pt );
	$meta = Colorify_Analytics::methods_meta();
	$vis  = static function ( array $s ) use ( $meta ) {
		foreach ( $s['methods'] as $m ) {
			if ( empty( $meta[ $m ]['visitors'] ) ) {
				return null;
			}
		}
		return $s['measured'] ? round( $s['visitors'] / $s['measured'], 1 ) : null;
	};
	$bots_known = $cur['methods'] && ! in_array( 'legacy', $cur['methods'], true );
	list( $ok, $why ) = colorify_an_comparable( $cur, $prev, $days, $to );
	return array(
		'from'                => $from,
		'to'                  => $to,
		'days'                => $days,
		'measured_days'       => $cur['measured'],
		'views'               => $cur['views'],
		'views_per_day'       => $cur['measured'] ? round( $cur['views'] / $cur['measured'], 1 ) : null,
		'visitors_avg'        => $vis( $cur ),
		'visitors_sum'        => null === $vis( $cur ) ? null : $cur['visitors'],
		'bots'                => $bots_known ? $cur['bots'] : null,
		'bots_share'          => $bots_known ? colorify_an_share( $cur['bots'], $cur['bots'] + $cur['views'] ) : null,
		'prefetch_views'      => $cur['prefetch'],
		'methods'             => $cur['methods'],
		'prev'                => array(
			'from'          => $pf,
			'to'            => $pt,
			'measured_days' => $prev['measured'],
			'views'         => $prev['views'],
			'visitors_avg'  => $vis( $prev ),
		),
		'compare_allowed'     => $ok,
		'compare_reason'      => $why,
		'change'              => $ok ? $cur['views'] - $prev['views'] : null,
		'change_pct'          => $ok && $prev['views'] >= COLORIFY_ANALYTICS_LOW_SAMPLE ? round( ( $cur['views'] - $prev['views'] ) / $prev['views'] * 100, 1 ) : null,
		'change_visitors_avg' => $ok && null !== $vis( $cur ) && null !== $vis( $prev ) ? round( $vis( $cur ) - $vis( $prev ), 1 ) : null,
		'low_sample'          => $cur['views'] < COLORIFY_ANALYTICS_LOW_SAMPLE,
	);
}

/**
 * Odsłony w czasie. Dzień bez zapisu = measured false i null (brak pomiaru to nie zero).
 *
 * @param string $step day|week (tydzień od poniedziałku; partial = niepełny albo przycięty zakresem).
 */
function colorify_analytics_series( string $from, string $to, string $step = 'day' ): array {
	$sum    = colorify_an_sum( $from, $to );
	$points = array();
	for ( $d = $from; $d <= $to; $d = Colorify_Analytics::day_add( $d, 1 ) ) {
		$r        = $sum['days'][ $d ] ?? null;
		$points[] = array(
			'date'     => $d,
			'views'    => $r ? (int) $r['views'] : null,
			'visitors' => $r && 'log1' !== $r['method'] ? (int) $r['visitors'] : null,
			'bots'     => $r && 'legacy' !== $r['method'] ? (int) $r['bots'] : null,
			'measured' => null !== $r,
			'method'   => $r ? $r['method'] : Colorify_Analytics::method_at( $d ),
			'state'    => $r ? $r['state'] : 'none',
		);
	}
	if ( 'week' === $step ) {
		$weeks = array();
		foreach ( $points as $p ) {
			$n   = (int) gmdate( 'N', (int) strtotime( $p['date'] . ' 00:00:00 UTC' ) );
			$mon = Colorify_Analytics::day_add( $p['date'], 1 - $n );
			if ( ! isset( $weeks[ $mon ] ) ) {
				$weeks[ $mon ] = array(
					'date'          => $mon,
					'end'           => Colorify_Analytics::day_add( $mon, 6 ),
					'views'         => 0,
					'visitors_sum'  => 0,
					'measured_days' => 0,
					'days'          => 0,
				);
			}
			++$weeks[ $mon ]['days'];
			if ( $p['measured'] ) {
				$weeks[ $mon ]['views']        += (int) $p['views'];
				$weeks[ $mon ]['visitors_sum'] += (int) $p['visitors'];
				++$weeks[ $mon ]['measured_days'];
			}
		}
		foreach ( $weeks as &$w ) {
			$w['partial'] = $w['measured_days'] < 7;
		}
		unset( $w );
		$points = array_values( $weeks );
	}
	$notes = array();
	$meta  = Colorify_Analytics::methods_meta();
	foreach ( Colorify_Analytics::registry()['methods'] as $i => $m ) {
		if ( $i > 0 && $m['from'] >= $from && $m['from'] <= $to ) {
			$notes[] = array(
				'date'  => $m['from'],
				'type'  => 'method',
				'label' => sprintf( /* translators: %s: nazwa metody */ __( 'Zmiana metody pomiaru: %s', 'colorify-by-inyfinn' ), $meta[ $m['method'] ]['label'] ?? $m['method'] ),
			);
		}
	}
	return array(
		'from'        => $from,
		'to'          => $to,
		'step'        => 'week' === $step ? 'week' : 'day',
		'points'      => $points,
		'annotations' => $notes,
		'max'         => max( array_merge( array( 0 ), array_map( static fn( $p ) => (int) $p['views'], $points ) ) ),
		'low_sample'  => $sum['views'] < COLORIFY_ANALYTICS_LOW_SAMPLE,
	);
}

/* ===================== Strony ===================== */

/**
 * Opis klucza strony do wyświetlenia (po cache: edit_url zależy od uprawnień oglądającego).
 *
 * @return array{key:string,title:string,url:string,edit_url:string,type:string,front:bool}
 */
function colorify_analytics_page_info( string $key ): array {
	$out = array(
		'key'      => $key,
		'title'    => $key,
		'url'      => '/' === substr( $key, 0, 1 ) ? home_url( $key ) : '',
		'edit_url' => '',
		'type'     => 'path',
		'front'    => '/' === $key,
	);
	if ( Colorify_Analytics::OTHER === $key ) {
		return array_merge( $out, array( 'title' => __( 'Inne adresy (ponad dzienny limit)', 'colorify-by-inyfinn' ), 'type' => 'other' ) );
	}
	if ( '/?s' === $key ) {
		return array_merge( $out, array( 'title' => __( 'Wyniki wyszukiwania', 'colorify-by-inyfinn' ), 'url' => home_url( '/?s=' ), 'type' => 'search' ) );
	}
	if ( ctype_digit( $key ) ) {
		$p = get_post( (int) $key );
		if ( ! $p ) {
			/* translators: %s: ID */
			return array_merge( $out, array( 'title' => sprintf( __( 'Usunięta strona (#%s)', 'colorify-by-inyfinn' ), $key ), 'url' => '', 'type' => 'deleted' ) );
		}
		$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES, 'UTF-8' ) );
		return array_merge(
			$out,
			array(
				'title'    => '' !== $title ? $title : __( '(bez tytułu)', 'colorify-by-inyfinn' ),
				'url'      => (string) get_permalink( $p ),
				'edit_url' => (string) get_edit_post_link( $p->ID, 'raw' ),
				'type'     => $p->post_type,
				'front'    => (int) get_option( 'page_on_front' ) === $p->ID,
			)
		);
	}
	return $out;
}

/** @return array<string,array{views:int,entries:int}> klucz strony => sumy w zakresie. */
function colorify_an_page_sums( string $from, string $to, string $only = '' ): array {
	return colorify_an_cached(
		'pages',
		array( $from, $to, $only ),
		static function () use ( $from, $to, $only ) {
			global $wpdb;
			$sql  = 'SELECT page, SUM(views) AS v, SUM(entries) AS e FROM ' . Colorify_Analytics::t( 'daily_pages' ) . ' WHERE ' . colorify_an_where( $from, $to );
			$sql .= '' !== $only ? $wpdb->prepare( ' AND page = %s', $only ) : '';
			$out  = array();
			foreach ( (array) $wpdb->get_results( $sql . ' GROUP BY page', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$out[ (string) $r['page'] ] = array(
					'views'   => (int) $r['v'],
					'entries' => (int) $r['e'],
				);
			}
			return $out;
		}
	);
}

/**
 * Wszystkie strony z odsłonami w zakresie: liczba, udział, zmiana bezwzględna; sortowanie, szukanie, stronicowanie.
 *
 * @param array $args sort (views|change|title|entries), order (asc|desc), search, page (od 1), per_page (1–500).
 */
function colorify_analytics_pages( string $from, string $to, array $args = array() ): array {
	$args += array(
		'sort'     => 'views',
		'order'    => 'desc',
		'search'   => '',
		'page'     => 1,
		'per_page' => 50,
	);
	$tot  = colorify_analytics_totals( $from, $to );
	$cur  = colorify_an_page_sums( $from, $to );
	$prev = colorify_an_page_sums( $tot['prev']['from'], $tot['prev']['to'] );
	$ok   = $tot['compare_allowed'];
	$all  = array_sum( array_column( $cur, 'views' ) );
	$keys = array_keys( $ok ? $cur + $prev : $cur );
	$rows = array();
	$q    = trim( (string) $args['search'] );
	foreach ( $keys as $k ) {
		$k    = (string) $k;
		$info = colorify_analytics_page_info( $k );
		if ( '' !== $q && false === mb_stripos( $info['title'] . ' ' . $k, $q ) ) {
			continue;
		}
		$v      = $cur[ $k ]['views'] ?? 0;
		$p      = $prev[ $k ]['views'] ?? 0;
		$rows[] = $info + array(
			'views'      => $v,
			'entries'    => $cur[ $k ]['entries'] ?? 0,
			'share'      => colorify_an_share( $v, $all ),
			'prev_views' => $ok ? $p : null,
			'change'     => $ok ? $v - $p : null,
		);
	}
	$sort = in_array( $args['sort'], array( 'views', 'change', 'title', 'entries' ), true ) ? $args['sort'] : 'views';
	$dir  = 'asc' === $args['order'] ? 1 : -1;
	usort(
		$rows,
		static function ( $a, $b ) use ( $sort, $dir ) {
			$c = 'title' === $sort ? strcoll( $a['title'], $b['title'] ) : ( (int) $a[ $sort ] <=> (int) $b[ $sort ] );
			return 0 !== $c ? $dir * $c : $b['views'] <=> $a['views'];
		}
	);
	$per   = max( 1, min( 500, (int) $args['per_page'] ) );
	$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
	$page  = max( 1, min( $pages, (int) $args['page'] ) );
	return array(
		'rows'            => array_slice( $rows, ( $page - 1 ) * $per, $per ),
		'total_rows'      => count( $rows ),
		'sum_views'       => $all,
		'page'            => $page,
		'per_page'        => $per,
		'pages'           => $pages,
		'compare_allowed' => $ok,
		'compare_reason'  => $tot['compare_reason'],
		'low_sample'      => $all < COLORIFY_ANALYTICS_LOW_SAMPLE,
	);
}

/** Raport jednej strony: odsłony dzień po dniu, wejścia z zewnątrz, źródła, urządzenia, udział w całej stronie. */
function colorify_analytics_page_report( string $key, string $from, string $to ): array {
	$tot    = colorify_analytics_totals( $from, $to );
	$sums   = colorify_an_page_sums( $from, $to, $key );
	$prev   = colorify_an_page_sums( $tot['prev']['from'], $tot['prev']['to'], $key );
	$daily  = colorify_an_cached(
		'page_days',
		array( $key, $from, $to ),
		static function () use ( $key, $from, $to ) {
			global $wpdb;
			$out = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT day, views FROM ' . Colorify_Analytics::t( 'daily_pages' ) . ' WHERE page = %s AND ', $key ) . colorify_an_where( $from, $to ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$out[ $r['day'] ] = (int) $r['views'];
			}
			return $out;
		}
	);
	$series = colorify_analytics_series( $from, $to );
	$points = array();
	foreach ( $series['points'] as $p ) {
		$points[] = array(
			'date'     => $p['date'],
			'views'    => $p['measured'] ? ( $daily[ $p['date'] ] ?? 0 ) : null,
			'measured' => $p['measured'],
		);
	}
	$v = $sums[ $key ]['views'] ?? 0;
	return array(
		'page'            => colorify_analytics_page_info( $key ),
		'from'            => $from,
		'to'              => $to,
		'views'           => $v,
		'entries'         => $sums[ $key ]['entries'] ?? 0,
		'share_of_site'   => colorify_an_share( $v, (int) $tot['views'] ),
		'prev_views'      => $tot['compare_allowed'] ? ( $prev[ $key ]['views'] ?? 0 ) : null,
		'change'          => $tot['compare_allowed'] ? $v - ( $prev[ $key ]['views'] ?? 0 ) : null,
		'compare_allowed' => $tot['compare_allowed'],
		'compare_reason'  => $tot['compare_reason'],
		'series'          => $points,
		'annotations'     => $series['annotations'],
		'sources'         => colorify_analytics_sources( $from, $to, $key ),
		'devices'         => colorify_analytics_devices( $from, $to, $key ),
		'low_sample'      => $v < COLORIFY_ANALYTICS_LOW_SAMPLE,
	);
}

/* ===================== Przekroje ===================== */

/** @return array<string,array<string,int>> wymiar => wartość => odsłony (page '' = cała strona). */
function colorify_an_dims( string $from, string $to, string $page, array $dims ): array {
	return colorify_an_cached(
		'dims',
		array( $from, $to, $page, $dims ),
		static function () use ( $from, $to, $page, $dims ) {
			global $wpdb;
			$in  = implode( ',', array_map( static fn( $d ) => $wpdb->prepare( '%s', $d ), $dims ) );
			$sql = $wpdb->prepare( 'SELECT dim, val, SUM(views) AS v FROM ' . Colorify_Analytics::t( 'daily_breakdowns' ) . ' WHERE page = %s', $page ) . " AND dim IN ($in) AND " . colorify_an_where( $from, $to ) . ' GROUP BY dim, val';
			$out = array_fill_keys( $dims, array() );
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$out[ $r['dim'] ][ (string) $r['val'] ] = (int) $r['v'];
			}
			foreach ( $out as &$vals ) {
				arsort( $vals );
			}
			return $out;
		}
	);
}

/** Wspólne pola przekrojów: od kiedy są dane i czy zakres zaczyna się wcześniej (stary licznik ich nie miał). */
function colorify_an_dims_meta( string $from ): array {
	$since = colorify_an_dims_from();
	return array(
		'measured_from' => $since,
		'partial'       => '' === $since || $from < $since,
	);
}

/**
 * Źródła wejść: klasy (wyszukiwarki, social, inne strony, bezpośrednie/nieustalone), domeny, UTM.
 * Przejścia wewnątrz strony podane osobno (internal), bez udziału.
 */
function colorify_analytics_sources( string $from, string $to, string $page = '' ): array {
	$d      = colorify_an_dims( $from, $to, $page, array( 'source_class', 'source_domain', 'utm_source', 'utm_medium', 'utm_campaign' ) );
	$labels = array(
		'search'         => __( 'Wyszukiwarki', 'colorify-by-inyfinn' ),
		'social'         => __( 'Media społecznościowe', 'colorify-by-inyfinn' ),
		'referral'       => __( 'Inne strony (odnośniki)', 'colorify-by-inyfinn' ),
		'direct_unknown' => __( 'Bezpośrednie / nieustalone', 'colorify-by-inyfinn' ),
	);
	$ext    = 0;
	foreach ( array_keys( $labels ) as $c ) {
		$ext += $d['source_class'][ $c ] ?? 0;
	}
	$classes = array();
	foreach ( $labels as $c => $label ) {
		$n         = $d['source_class'][ $c ] ?? 0;
		$classes[] = array(
			'class' => $c,
			'label' => $label,
			'views' => $n,
			'share' => colorify_an_share( $n, $ext ),
		);
	}
	$ref     = ( $d['source_class']['search'] ?? 0 ) + ( $d['source_class']['social'] ?? 0 ) + ( $d['source_class']['referral'] ?? 0 );
	$domains = array();
	foreach ( $d['source_domain'] as $dom => $n ) {
		$other     = Colorify_Analytics::OTHER === $dom;
		$domains[] = array(
			'domain' => $other ? '' : $dom,
			'label'  => $other ? __( 'Inne domeny (ponad dzienny limit)', 'colorify-by-inyfinn' ) : $dom,
			'class'  => $other ? 'referral' : Colorify_Analytics_Rules::domain_class( $dom ),
			'views'  => $n,
			'share'  => colorify_an_share( $n, $ref ),
		);
	}
	$utm = array();
	foreach ( array( 'source', 'medium', 'campaign' ) as $u ) {
		$utm[ $u ] = array();
		foreach ( $d[ 'utm_' . $u ] as $val => $n ) {
			$utm[ $u ][] = array(
				'value' => Colorify_Analytics::OTHER === $val ? __( 'Inne', 'colorify-by-inyfinn' ) : $val,
				'views' => $n,
			);
		}
	}
	return array(
		'from'           => $from,
		'to'             => $to,
		'page'           => $page,
		'classes'        => $classes,
		'domains'        => $domains,
		'utm'            => $utm,
		'external_views' => $ext,
		'internal_views' => $d['source_class']['internal'] ?? 0,
		'low_sample'     => $ext < COLORIFY_ANALYTICS_LOW_SAMPLE,
	) + colorify_an_dims_meta( $from );
}

/** Urządzenia: telefon, tablet, komputer, nieustalone (z UA w chwili żądania, UA nie jest zapisywany). */
function colorify_analytics_devices( string $from, string $to, string $page = '' ): array {
	$d      = colorify_an_dims( $from, $to, $page, array( 'device' ) )['device'];
	$labels = array(
		'mobile'  => __( 'Telefon', 'colorify-by-inyfinn' ),
		'tablet'  => __( 'Tablet', 'colorify-by-inyfinn' ),
		'desktop' => __( 'Komputer', 'colorify-by-inyfinn' ),
		'unknown' => __( 'Nieustalone', 'colorify-by-inyfinn' ),
	);
	$all    = array_sum( $d );
	$rows   = array();
	foreach ( $labels as $k => $label ) {
		$rows[] = array(
			'device' => $k,
			'label'  => $label,
			'views'  => $d[ $k ] ?? 0,
			'share'  => colorify_an_share( $d[ $k ] ?? 0, $all ),
		);
	}
	return array(
		'from'       => $from,
		'to'         => $to,
		'page'       => $page,
		'devices'    => $rows,
		'views'      => $all,
		'low_sample' => $all < COLORIFY_ANALYTICS_LOW_SAMPLE,
	) + colorify_an_dims_meta( $from );
}

/** Siatka dzień tygodnia × godzina w strefie WordPressa (pon. = 1). Liczby i udział, bez porad. */
function colorify_analytics_hours_grid( string $from, string $to ): array {
	$cells = colorify_an_cached(
		'hours',
		array( $from, $to ),
		static function () use ( $from, $to ) {
			global $wpdb;
			return (array) $wpdb->get_results( 'SELECT day, hour, SUM(views) AS v FROM ' . Colorify_Analytics::t( 'hourly_totals' ) . ' WHERE ' . colorify_an_where( $from, $to ) . ' GROUP BY day, hour', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	);
	$names   = array( 1 => 'pon.', 2 => 'wt.', 3 => 'śr.', 4 => 'czw.', 5 => 'pt.', 6 => 'sob.', 7 => 'niedz.' );
	$grid    = array();
	$by_hour = array_fill( 0, 24, 0 );
	foreach ( $names as $n => $label ) {
		$grid[ $n ] = array(
			'weekday' => $n,
			'label'   => $label,
			'hours'   => array_fill( 0, 24, 0 ),
			'total'   => 0,
			'days'    => 0,
		);
	}
	$total = 0;
	$max   = 0;
	foreach ( $cells as $c ) {
		$n                                    = (int) gmdate( 'N', (int) strtotime( $c['day'] . ' 00:00:00 UTC' ) );
		$h                                    = (int) $c['hour'];
		$grid[ $n ]['hours'][ $h ]           += (int) $c['v'];
		$grid[ $n ]['total']                 += (int) $c['v'];
		$by_hour[ $h ]                       += (int) $c['v'];
		$total                               += (int) $c['v'];
		$max                                  = max( $max, $grid[ $n ]['hours'][ $h ] );
	}
	$since = colorify_an_dims_from();
	for ( $d = max( $from, '' !== $since ? $since : $to ); $d <= $to; $d = Colorify_Analytics::day_add( $d, 1 ) ) {
		++$grid[ (int) gmdate( 'N', (int) strtotime( $d . ' 00:00:00 UTC' ) ) ]['days'];
	}
	return array(
		'from'       => $from,
		'to'         => $to,
		'timezone'   => wp_timezone_string(),
		'grid'       => array_values( $grid ),
		'by_hour'    => $by_hour,
		'max'        => $max,
		'total'      => $total,
		'low_sample' => $total < COLORIFY_ANALYTICS_LOW_SAMPLE,
	) + colorify_an_dims_meta( $from );
}

/** Strony z wejściami z zewnątrz (Referer z innej domeny albo parametr kliknięcia). To nie jest pełny raport stron wejścia sesji. */
function colorify_analytics_entry_pages( string $from, string $to, int $limit = 50 ): array {
	$sums = colorify_an_page_sums( $from, $to );
	$all  = array_sum( array_column( $sums, 'entries' ) );
	$rows = array();
	foreach ( $sums as $k => $s ) {
		if ( $s['entries'] > 0 ) {
			$rows[] = colorify_analytics_page_info( (string) $k ) + array(
				'entries' => $s['entries'],
				'views'   => $s['views'],
				'share'   => colorify_an_share( $s['entries'], $all ),
			);
		}
	}
	usort( $rows, static fn( $a, $b ) => $b['entries'] <=> $a['entries'] );
	return array(
		'from'       => $from,
		'to'         => $to,
		'rows'       => array_slice( $rows, 0, max( 1, $limit ) ),
		'total_rows' => count( $rows ),
		'entries'    => $all,
		'low_sample' => $all < COLORIFY_ANALYTICS_LOW_SAMPLE,
	) + colorify_an_dims_meta( $from );
}

/** Adresy 404 od ludzi (bez skanerów plików): liczba, ostatni dzień, skąd (domena, link na tej stronie, bez odnośnika). */
function colorify_analytics_errors404( string $from, string $to, int $limit = 100 ): array {
	$rows  = colorify_an_cached(
		'404',
		array( $from, $to ),
		static function () use ( $from, $to ) {
			global $wpdb;
			return (array) $wpdb->get_results( 'SELECT path, ref, SUM(hits) AS h, MAX(day) AS last FROM ' . Colorify_Analytics::t( 'daily_errors' ) . ' WHERE ' . colorify_an_where( $from, $to ) . ' GROUP BY path, ref', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	);
	$paths = array();
	foreach ( $rows as $r ) {
		$p = (string) $r['path'];
		if ( ! isset( $paths[ $p ] ) ) {
			$paths[ $p ] = array(
				'path'     => $p,
				'url'      => Colorify_Analytics::OTHER === $p ? '' : home_url( $p ),
				'label'    => Colorify_Analytics::OTHER === $p ? __( 'Inne adresy (ponad dzienny limit)', 'colorify-by-inyfinn' ) : $p,
				'hits'     => 0,
				'last_day' => '',
				'refs'     => array(),
			);
		}
		$ref  = (string) $r['ref'];
		$type = '' === $ref ? 'none' : ( 0 === strpos( $ref, 'internal:' ) ? 'internal' : 'external' );
		$from_path = 'internal' === $type ? substr( $ref, 9 ) : '';
		$paths[ $p ]['hits']    += (int) $r['h'];
		$paths[ $p ]['last_day'] = max( $paths[ $p ]['last_day'], (string) $r['last'] );
		$paths[ $p ]['refs'][]   = array(
			'type'  => $type,
			'ref'   => 'external' === $type ? $ref : '',
			'from'  => '' !== $from_path ? home_url( $from_path ) : '',
			'label' => 'none' === $type ? __( 'Bez odnośnika (zakładka, stary link, wpisany adres)', 'colorify-by-inyfinn' )
				: ( 'internal' === $type ? ( '' !== $from_path ? sprintf( /* translators: %s: ścieżka */ __( 'Link na tej stronie: %s', 'colorify-by-inyfinn' ), $from_path ) : __( 'Link na tej stronie', 'colorify-by-inyfinn' ) ) : $ref ),
			'hits'  => (int) $r['h'],
		);
	}
	foreach ( $paths as &$p ) {
		usort( $p['refs'], static fn( $a, $b ) => $b['hits'] <=> $a['hits'] );
	}
	unset( $p );
	usort( $paths, static fn( $a, $b ) => $b['hits'] <=> $a['hits'] );
	$hits = array_sum( array_column( $paths, 'hits' ) );
	return array(
		'from'       => $from,
		'to'         => $to,
		'rows'       => array_slice( $paths, 0, max( 1, $limit ) ),
		'total_rows' => count( $paths ),
		'hits'       => $hits,
		'low_sample' => $hits < COLORIFY_ANALYTICS_LOW_SAMPLE,
	) + colorify_an_dims_meta( $from );
}

/* ===================== Jakość danych ===================== */

/**
 * Karta jakości danych: metoda i od kiedy, historia metod, przerwy, udział botów (po 7 dniach), prefetch,
 * utracone zapisy, cache całych stron, stan pomiaru, próg małej próby.
 */
function colorify_analytics_coverage(): array {
	global $wpdb;
	$reg   = Colorify_Analytics::registry();
	$meta  = Colorify_Analytics::methods_meta();
	$today = wp_date( 'Y-m-d' );
	$yday  = Colorify_Analytics::day_add( $today, -1 );
	$ms    = $reg['methods'];
	$cur   = $ms ? $ms[ count( $ms ) - 1 ] : array( 'method' => '', 'from' => '' );
	$live  = Colorify_Analytics::method_at( $today );
	$since = $ms ? $ms[0]['from'] : '';

	$methods = array();
	foreach ( $ms as $i => $m ) {
		$methods[] = array(
			'method'  => $m['method'],
			'label'   => $meta[ $m['method'] ]['label'] ?? $m['method'],
			'about'   => $meta[ $m['method'] ]['about'] ?? '',
			'from'    => $m['from'],
			'to'      => isset( $ms[ $i + 1 ] ) ? Colorify_Analytics::day_add( $ms[ $i + 1 ]['from'], -1 ) : '',
			'started' => $m['started'],
		);
	}

	$gaps = array();
	if ( '' !== $since && $since <= $yday ) {
		$have = colorify_an_sum( $since, $yday )['days'];
		$open = '';
		for ( $d = $since; $d <= Colorify_Analytics::day_add( $yday, 1 ); $d = Colorify_Analytics::day_add( $d, 1 ) ) {
			$miss = $d <= $yday && ! isset( $have[ $d ] );
			if ( $miss && '' === $open ) {
				$open = $d;
			} elseif ( ! $miss && '' !== $open ) {
				$end    = Colorify_Analytics::day_add( $d, -1 );
				$gaps[] = array(
					'from'   => $open,
					'to'     => $end,
					'days'   => colorify_an_days( $open, $end ),
					'method' => Colorify_Analytics::method_at( $open ),
				);
				$open   = '';
			}
		}
	}

	// Boty i prefetch: ostatnie 7 pełnych dni licznika serwerowego (także gdy prawdą jest dziennik: PHP widzi boty).
	$b = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS days, COALESCE(SUM(views),0) AS views, COALESCE(SUM(bots),0) AS bots, COALESCE(SUM(prefetch),0) AS prefetch, COALESCE(SUM(spec),0) AS spec FROM ' . Colorify_Analytics::t( 'daily_totals' ) . ' WHERE method = %s AND day BETWEEN %s AND %s AND state <> %s', Colorify_Analytics::METHOD, Colorify_Analytics::day_add( $today, -7 ), $yday, 'partial' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$b = array_map( 'intval', (array) $b );

	$cache    = (array) $reg['cache'];
	$detected = ! empty( $cache['detected'] );
	if ( Colorify_Analytics::LOG === $live ) {
		$state = 'ok';
		$label = __( 'Pomiar pełny (dziennik odsłon)', 'colorify-by-inyfinn' );
	} elseif ( $detected && 'off' === $reg['beacon'] ) {
		$state = 'incomplete';
		$label = __( 'Pomiar niepełny: strona ma cache całych stron, a dziennik odsłon jest wyłączony', 'colorify-by-inyfinn' );
	} elseif ( $detected ) {
		$state = Colorify_Analytics::beacon_supported() ? 'starting' : 'incomplete';
		$label = 'starting' === $state ? __( 'Wykryto cache całych stron: dziennik odsłon zbiera dane, przejmie liczenie od jutra', 'colorify-by-inyfinn' ) : __( 'Pomiar niepełny: cache całych stron, a dziennik odsłon nie działa przy tym układzie katalogów', 'colorify-by-inyfinn' );
	} elseif ( '' === $cur['from'] || $cur['from'] > $yday ) {
		$state = 'starting';
		$label = __( 'Licznik dopiero zaczął: pierwszy pełny dzień jutro', 'colorify-by-inyfinn' );
	} else {
		$state = 'ok';
		$label = __( 'Pomiar pełny (licznik serwerowy)', 'colorify-by-inyfinn' );
	}

	$v30 = colorify_an_sum( Colorify_Analytics::day_add( $today, -30 ), $yday )['views'];
	return array(
		'method'           => $live,
		'method_label'     => $meta[ $live ]['label'] ?? '',
		'method_about'     => $meta[ $live ]['about'] ?? '',
		'next_method'      => $cur['method'] !== $live ? $cur['method'] : '',
		'next_from'        => $cur['method'] !== $live ? $cur['from'] : '',
		'since'            => $cur['method'] === $live ? $cur['from'] : ( $ms[ count( $ms ) - 2 ]['from'] ?? '' ),
		'data_since'       => $since,
		'dims_since'       => colorify_an_dims_from(),
		'methods'          => $methods,
		'gaps'             => $gaps,
		'bots'             => array(
			'days'  => $b['days'],
			'views' => $b['views'],
			'bots'  => $b['bots'],
			'share' => colorify_an_share( $b['bots'], $b['bots'] + $b['views'] ),
			'ready' => $b['days'] >= 7,
		),
		'prefetch'         => array(
			'counted' => $b['prefetch'],
			'skipped' => $b['spec'],
			'days'    => $b['days'],
		),
		'lost_writes'      => (int) get_option( Colorify_Analytics::opt( 'lost' ), 0 ),
		'cache'            => array(
			'detected' => $detected,
			'signals'  => (array) ( $cache['signals'] ?? array() ),
			'checked'  => (string) ( $cache['checked'] ?? '' ),
		),
		'beacon'           => array(
			'setting'   => $reg['beacon'],
			'active'    => Colorify_Analytics::beacon_active(),
			'supported' => Colorify_Analytics::beacon_supported(),
		),
		'state'            => $state,
		'state_label'      => Colorify_Analytics::nbsp( $label ),
		'views_30d'        => $v30,
		'low_sample'       => $v30 < COLORIFY_ANALYTICS_LOW_SAMPLE,
		'low_sample_below' => COLORIFY_ANALYTICS_LOW_SAMPLE,
		'engagement'       => array(
			'measured' => false,
			'unlock'   => 3000,
			/* translators: %d: odsłony z 30 dni */
			'label'    => Colorify_Analytics::nbsp( sprintf( __( 'Czasu na stronie nie mierzymy. Odblokuje się przy 3000 odsłon miesięcznie; teraz %d.', 'colorify-by-inyfinn' ), $v30 ) ),
		),
	);
}
