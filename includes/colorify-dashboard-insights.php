<?php
/**
 * Kokpit: panel ruchu, szczegóły strony/metryki i moduły per widok (dane z colorify_analytics_*, bez nowych bibliotek).
 *
 * Podpięcie (jedna linia w pliku głównym albo w colorify-dashboard.php): require_once __DIR__ . '/includes/colorify-dashboard-insights.php';
 * Panel w widoku kokpitu: echo colorify_insights_traffic_panel();  (tryb: colorify_insights_module( 'company' ) itd. wg modułów niżej)
 * Podstrona szczegółów (bez pozycji w menu): admin.php?page=colorify-ruch[&metryka=views|visitors|pages][&strona=KLUCZ][&cfi_days=30|cfi_od=&cfi_do=]
 *
 * Wszystko działa łagodnie, gdy API analityki nie istnieje albo nie ma danych: stan "Zbieramy dane od dziś", nigdy zera z powietrza.
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_INSIGHTS_PAGE = 'colorify-ruch';

/* ====================================================================== Wspólne ====================================================================== */

function colorify_insights_available(): bool {
	return function_exists( 'colorify_analytics_totals' ) && function_exists( 'colorify_analytics_range' );
}

function colorify_insights_cap(): string {
	return (string) apply_filters( 'colorify_insights_cap', 'edit_pages' );
}

function colorify_insights_n( $v, int $dec = 0 ): string {
	// Polski panel: tysiące odstępem, ułamek przecinkiem, jak w reszcie kokpitu. Gdy tłumaczenie WordPressa nie podaje
	// separatorów, number_format_i18n() dawało zapis angielski (1,200 zł).
	return 0 === strpos( (string) determine_locale(), 'pl' ) ? number_format( (float) $v, $dec, ',', "\u{00A0}" ) : number_format_i18n( (float) $v, $dec );
}

function colorify_insights_date( string $ymd, string $fmt = 'j F Y' ): string {
	return wp_date( $fmt, strtotime( $ymd . ' 12:00:00' ) );
}

/** Zakres z adresu: cfi_days (7/30/90/365) albo cfi_od + cfi_do. */
function colorify_insights_range(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- parametry odczytu, tylko zakres dat.
	$od = isset( $_GET['cfi_od'] ) ? sanitize_text_field( wp_unslash( $_GET['cfi_od'] ) ) : '';
	$do = isset( $_GET['cfi_do'] ) ? sanitize_text_field( wp_unslash( $_GET['cfi_do'] ) ) : '';
	$d  = isset( $_GET['cfi_days'] ) ? absint( $_GET['cfi_days'] ) : 30;
	// phpcs:enable
	$args = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $od ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $do ) ? array( 'from' => $od, 'to' => $do ) : array( 'days' => in_array( $d, array( 7, 30, 90, 365 ), true ) ? $d : 30 );
	return colorify_analytics_range( $args );
}

/**
 * Zakres dla bloków, które liczy dopiero nowy licznik (źródła, urządzenia, godziny, wejścia, 404).
 * Gdy wybrany zakres kończy się, zanim nowy licznik ruszył, bierzemy dane od jego startu do dziś (do teraz), a podpis to mówi po ludzku.
 *
 * @return array{from:string,to:string,note:string,today:bool}
 */
function colorify_insights_dim_range( array $r ): array {
	$cov   = function_exists( 'colorify_analytics_coverage' ) ? colorify_analytics_coverage() : array();
	$since = (string) ( $cov['dims_since'] ?? '' );
	$today = wp_date( 'Y-m-d' );
	$out   = array( 'from' => $r['from'], 'to' => $r['to'], 'note' => '', 'today' => false, 'since' => $since );
	if ( '' === $since ) {
		return $out;
	}
	if ( $since > $r['to'] ) {
		if ( $since <= $today ) {
			$out['from']  = $since;
			$out['to']    = $today;
			$out['today'] = true;
			/* translators: %s: data, np. 13 listopada */
			$out['note']  = sprintf( __( 'Dziś do teraz. Liczymy od %s.', 'colorify-by-inyfinn' ), colorify_insights_date( $since, 'j F' ) );
		}
	} elseif ( $since > $r['from'] ) {
		$out['from'] = $since;
		/* translators: %s: data, np. 13 listopada */
		$out['note'] = sprintf( __( 'Liczymy od %s, wcześniej tego nie mierzyliśmy.', 'colorify-by-inyfinn' ), colorify_insights_date( $since, 'j F' ) );
	}
	return $out;
}

/** Krótka uwaga przy małej próbie (poniżej progu) zamiast procentów-wniosków. */
function colorify_insights_low_note( array $d ): string {
	return ! empty( $d['low_sample'] ) ? __( 'Mała próba: za mało wejść na wnioski, traktuj to orientacyjnie.', 'colorify-by-inyfinn' ) : '';
}

/** Adres bieżącego ekranu bez parametrów panelu, z dołożonymi $set (null = usuń). */
function colorify_insights_here( array $set = array() ): string {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : admin_url( 'index.php' );
	$base = remove_query_arg( array( 'cfi_days', 'cfi_od', 'cfi_do', 'cfi_krok', 'metryka', 'strona' ), $uri );
	foreach ( $set as $k => $v ) {
		$base = null === $v ? remove_query_arg( $k, $base ) : add_query_arg( $k, $v, $base );
	}
	return $base;
}

/** Adres podstrony szczegółów z zachowanym zakresem. */
function colorify_insights_detail_url( array $extra, array $r ): string {
	$q = array( 'page' => COLORIFY_INSIGHTS_PAGE );
	$q += $r['preset'] ? array( 'cfi_days' => $r['preset'] ) : array( 'cfi_od' => $r['from'], 'cfi_do' => $r['to'] );
	return add_query_arg( $extra + $q, admin_url( 'admin.php' ) );
}

function colorify_insights_reason( string $why ): string {
	$m = array(
		'partial_today'  => __( 'Porównanie wróci, gdy dzień się skończy.', 'colorify-by-inyfinn' ),
		'no_history'     => __( 'Poprzedni okres jest poza zakresem zapisów.', 'colorify-by-inyfinn' ),
		'gaps'           => __( 'W poprzednim okresie są dni bez zapisu.', 'colorify-by-inyfinn' ),
		'method_change'  => __( 'Zmieniła się metoda liczenia, więc nie porównujemy.', 'colorify-by-inyfinn' ),
	);
	return $m[ $why ] ?? __( 'Porównanie niedostępne.', 'colorify-by-inyfinn' );
}

function colorify_insights_delta( $cur, $prev, bool $allowed, string $why = '' ): string {
	if ( ! $allowed || null === $prev || $prev <= 0 || null === $cur ) {
		return '<span class="cfi-delta is-na" title="' . esc_attr( colorify_insights_reason( $why ) ) . '">' . esc_html__( 'brak porównania', 'colorify-by-inyfinn' ) . '</span>';
	}
	$p = ( $cur - $prev ) / $prev * 100;
	$c = $p > 0.5 ? 'is-up' : ( $p < -0.5 ? 'is-down' : '' );
	return '<span class="cfi-delta ' . $c . '">' . ( $p > 0.5 ? '▲ +' : ( $p < -0.5 ? '▼ ' : '● ' ) ) . esc_html( colorify_insights_n( $p, 0 ) ) . '%<span class="screen-reader-text"> ' . esc_html__( 'względem poprzedniego okresu', 'colorify-by-inyfinn' ) . '</span></span>';
}

function colorify_insights_state( string $text, string $title = '' ): string {
	return '<div class="cfi-state">' . ( '' !== $title ? '<strong>' . esc_html( $title ) . '</strong>' : '' ) . '<span>' . esc_html( $text ) . '</span></div>';
}

function colorify_insights_since_text( ?string $from ): string {
	/* translators: %s: data, np. 13 listopada */
	return $from ? sprintf( __( 'Zbieramy od %s.', 'colorify-by-inyfinn' ), colorify_insights_date( $from, 'j F' ) ) : __( 'Zbieramy dane od dziś.', 'colorify-by-inyfinn' );
}

function colorify_insights_card( string $title, string $body, string $class = '', string $aside = '', string $id = '' ): string {
	return '<section class="cfi-card ' . esc_attr( $class ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '><div class="cfi-card__bar"><h3 class="cfi-card__title">' . esc_html( $title ) . '</h3>' . $aside . '</div>' . $body . '</section>';
}

/** Kafel liczby: etykieta, wartość, zmiana, podpis, opcjonalny link do szczegółów. */
function colorify_insights_stat( string $label, string $value, string $delta = '', string $hint = '', string $href = '' ): string {
	$inner = '<span class="cfi-stat__label">' . esc_html( $label ) . '</span><span class="cfi-stat__row"><span class="cfi-stat__value">' . $value . '</span>' . $delta . '</span>' . ( '' !== $hint ? '<span class="cfi-stat__hint">' . esc_html( $hint ) . '</span>' : '' );
	return '' !== $href ? '<a class="cfi-stat cfi-stat--link" href="' . esc_url( $href ) . '">' . $inner . '</a>' : '<div class="cfi-stat">' . $inner . '</div>';
}

/* ====================================================================== Zasoby ====================================================================== */

function colorify_insights_assets(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$dir  = dirname( __DIR__ ) . '/assets/';
	$url  = ( defined( 'COLORIFY_PLUGIN_URL' ) ? COLORIFY_PLUGIN_URL : plugin_dir_url( dirname( __DIR__ ) . '/colorify-by-inyfinn.php' ) ) . 'assets/';
	wp_enqueue_style( 'colorify-insights', $url . 'colorify-insights.css', array(), (string) @filemtime( $dir . 'colorify-insights.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	wp_enqueue_script( 'colorify-insights', $url . 'colorify-insights.js', array(), (string) @filemtime( $dir . 'colorify-insights.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'index.php' === $hook || false !== strpos( (string) $hook, COLORIFY_INSIGHTS_PAGE ) ) {
			colorify_insights_assets();
		}
	}
);

add_action(
	'admin_menu',
	static function () {
		add_submenu_page( '', __( 'Ruch na stronie', 'colorify-by-inyfinn' ), '', colorify_insights_cap(), COLORIFY_INSIGHTS_PAGE, 'colorify_insights_render_page' );
	}
);

function colorify_insights_render_page(): void {
	colorify_insights_assets();
	echo '<div class="wrap"><div class="cfi cfd">';
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.NonceVerification.Recommended -- HTML składany z wartości po esc_*.
	if ( isset( $_GET['modul'] ) ) {
		echo colorify_insights_module( sanitize_key( wp_unslash( $_GET['modul'] ) ) ); // Podgląd modułu widoku (Firma, Zapytania...).
	} elseif ( isset( $_GET['strona'] ) ) {
		echo colorify_insights_page_detail( sanitize_text_field( wp_unslash( $_GET['strona'] ) ) );
	} elseif ( isset( $_GET['metryka'] ) ) {
		echo colorify_insights_metric_detail( sanitize_key( wp_unslash( $_GET['metryka'] ) ) );
	} else {
		echo colorify_insights_traffic_panel();
	}
	// phpcs:enable
	echo '</div></div>';
}

/* ====================================================================== Wykres odsłon (SVG) ====================================================================== */

function colorify_insights_nice_max( float $m ): float {
	if ( $m <= 4 ) {
		return 4;
	}
	$p = pow( 10, floor( log10( $m ) ) );
	foreach ( array( 1, 2, 2.5, 5, 10 ) as $s ) {
		if ( $m <= $s * $p ) {
			return $s * $p;
		}
	}
	return 10 * $p;
}

/**
 * Słupki dzienne/tygodniowe. $points: [{date,end?,value|null,label}] — null = brak zapisu (nie zero).
 *
 * @param array<int,array<string,mixed>> $points
 * @param array<int,array<string,mixed>> $notes  [{date,label}] — pionowe znaczniki (zmiana metody).
 */
function colorify_insights_bars( array $points, string $unit, array $notes = array(), string $aria = '' ): string {
	$n = count( $points );
	if ( ! $n ) {
		return colorify_insights_state( __( 'Brak punktów do pokazania.', 'colorify-by-inyfinn' ) );
	}
	// Oś w HTML (prawdziwe 11-12 px, na telefonie mniej etykiet), sam wykres w SVG o stałej wysokości 220 px.
	$W = 800; $H = 220; $T = 10; $B = 10;
	$ph   = $H - $T - $B;
	$vals = array_filter( array_column( $points, 'value' ), static fn( $v ) => null !== $v );
	$max  = colorify_insights_nice_max( $vals ? (float) max( $vals ) : 4 );
	$slot = $W / $n;
	$bw   = max( 2.0, min( 36.0, $slot * 0.72 ) );
	$svg  = '<svg class="cfi-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . esc_attr( $aria ) . '" preserveAspectRatio="none" data-n="' . (int) $n . '">';
	$ay   = '<div class="cfi-ax cfi-ax--y" aria-hidden="true">';
	for ( $i = 0; $i <= 4; $i++ ) {
		$y    = $T + $ph - $ph * $i / 4;
		$svg .= '<line class="cfi-grid" x1="0" x2="' . $W . '" y1="' . round( $y, 1 ) . '" y2="' . round( $y, 1 ) . '"/>';
		$ay  .= '<span style="top:' . round( $y, 1 ) . 'px">' . esc_html( colorify_insights_n( $max * $i / 4 ) ) . '</span>';
	}
	$ay  .= '</div>';
	$step = max( 1, (int) ceil( $n / 6 ) );
	$ax   = '<div class="cfi-ax cfi-ax--x" aria-hidden="true">';
	$top  = null;
	foreach ( $points as $i => $p ) {
		if ( null !== $p['value'] && ( null === $top || $p['value'] > $points[ $top ]['value'] ) ) {
			$top = $i;
		}
	}
	$k = 0;
	foreach ( $points as $i => $p ) {
		$x = $slot * $i + ( $slot - $bw ) / 2;
		if ( null === $p['value'] ) {
			$svg .= '<line class="cfi-gap" x1="' . round( $x, 1 ) . '" x2="' . round( $x + $bw, 1 ) . '" y1="' . ( $T + $ph - 1 ) . '" y2="' . ( $T + $ph - 1 ) . '"/>';
		} else {
			$h    = max( 2.0, $ph * $p['value'] / $max );
			$svg .= '<path class="cfi-bar' . ( $i === $top ? ' is-top' : '' ) . '" d="' . colorify_insights_bar_path( $x, $T + $ph, $bw, $h ) . '"/>';
		}
		if ( 0 === $i % $step ) {
			$ax .= '<span class="' . ( 0 === $k % 2 ? '' : 'is-alt' ) . '" style="left:' . round( ( $i + 0.5 ) / $n * 100, 2 ) . '%">' . esc_html( colorify_insights_date( (string) $p['date'], 'j.m' ) ) . '</span>';
			++$k;
		}
		$svg .= '<rect class="cfi-hit" x="' . round( $slot * $i, 1 ) . '" y="' . $T . '" width="' . round( $slot, 1 ) . '" height="' . $ph . '" data-tip="' . esc_attr( (string) $p['label'] ) . '"><title>' . esc_html( (string) $p['label'] ) . '</title></rect>';
	}
	foreach ( $notes as $a ) {
		foreach ( $points as $i => $p ) {
			if ( (string) $p['date'] === (string) ( $a['date'] ?? '' ) ) {
				$x    = round( $slot * $i, 1 );
				$svg .= '<line class="cfi-note" x1="' . $x . '" x2="' . $x . '" y1="' . $T . '" y2="' . ( $T + $ph ) . '"/>';
			}
		}
	}
	$svg .= '</svg>';
	$ax  .= '</div>';
	return '<div class="cfi-chartwrap">' . $ay . '<div class="cfi-plot">' . $svg . $ax . '</div><div class="cfi-tip" role="status" hidden></div></div>'
		. ( $notes ? '<p class="cfi-foot">' . esc_html( implode( ' · ', array_map( static fn( $a ) => colorify_insights_date( (string) $a['date'], 'j.m' ) . ': ' . (string) $a['label'], $notes ) ) ) . '</p>' : '' );
}

/** Słupek z zaokrąglonym tylko wierzchem (4 px), dołem na osi. */
function colorify_insights_bar_path( float $x, float $base, float $w, float $h ): string {
	$r = min( 4.0, $w / 2, $h );
	return sprintf( 'M%1$.1f %2$.1f V%3$.1f Q%1$.1f %4$.1f %5$.1f %4$.1f H%6$.1f Q%7$.1f %4$.1f %7$.1f %3$.1f V%2$.1f Z', $x, $base, $base - $h + $r, $base - $h, $x + $r, $x + $w - $r, $x + $w );
}

/** Punkty wykresu z colorify_analytics_series. */
function colorify_insights_points( array $s, string $metric = 'views' ): array {
	$out = array();
	foreach ( (array) ( $s['points'] ?? array() ) as $p ) {
		$weekly = 'week' === ( $s['step'] ?? 'day' );
		$v      = $weekly ? ( 'visitors' === $metric ? ( $p['visitors_sum'] ?? null ) : ( $p['views'] ?? null ) ) : ( $p[ $metric ] ?? null );
		$meas   = $weekly ? ( (int) ( $p['measured_days'] ?? 0 ) > 0 ) : ! empty( $p['measured'] );
		$v      = $meas ? $v : null;
		$what   = 'visitors' === $metric ? __( 'odwiedzających', 'colorify-by-inyfinn' ) : __( 'odsłon', 'colorify-by-inyfinn' );
		$when   = $weekly ? sprintf( /* translators: 1,2: daty */ __( 'tydzień %1$s–%2$s', 'colorify-by-inyfinn' ), colorify_insights_date( (string) $p['date'], 'j.m' ), colorify_insights_date( (string) ( $p['end'] ?? $p['date'] ), 'j.m' ) ) : colorify_insights_date( (string) $p['date'], 'D j F' );
		$out[]  = array(
			'date'  => $p['date'],
			'value' => null === $v ? null : (float) $v,
			'label' => $when . ': ' . ( null === $v ? __( 'brak zapisu', 'colorify-by-inyfinn' ) : colorify_insights_n( $v ) . ' ' . $what ),
		);
	}
	return $out;
}

/** Tabela jako alternatywa dla czytnika ekranu i do skopiowania. */
function colorify_insights_points_table( array $points, string $col ): string {
	$h = '<details class="cfi-tab"><summary>' . esc_html__( 'Pokaż jako tabelę', 'colorify-by-inyfinn' ) . '</summary><div class="cfi-scroll"><table class="cfi-table"><thead><tr><th>' . esc_html__( 'Okres', 'colorify-by-inyfinn' ) . '</th><th class="is-num">' . esc_html( $col ) . '</th></tr></thead><tbody>';
	foreach ( array_reverse( $points ) as $p ) {
		$h .= '<tr><td>' . esc_html( preg_replace( '/:.*$/u', '', (string) $p['label'] ) ) . '</td><td class="is-num">' . ( null === $p['value'] ? '<span class="cfi-muted">' . esc_html__( 'brak zapisu', 'colorify-by-inyfinn' ) . '</span>' : esc_html( colorify_insights_n( $p['value'] ) ) ) . '</td></tr>';
	}
	return $h . '</tbody></table></div></details>';
}

/* ====================================================================== Panel ruchu ====================================================================== */

function colorify_insights_range_bar( array $r ): string {
	$h = '<div class="cfi-range"><nav class="cfi-seg" aria-label="' . esc_attr__( 'Zakres', 'colorify-by-inyfinn' ) . '">';
	foreach ( array( 7 => '7 dni', 30 => '30 dni', 90 => '90 dni', 365 => 'Rok' ) as $d => $lab ) {
		$h .= '<a href="' . esc_url( colorify_insights_here( array( 'cfi_days' => $d, 'cfi_od' => null, 'cfi_do' => null ) ) ) . '"' . ( (int) $r['preset'] === $d ? ' aria-current="true"' : '' ) . '>' . esc_html( $lab ) . '</a>';
	}
	$h  .= '</nav><form class="cfi-dates" method="get" action="">';
	$qs  = wp_parse_url( colorify_insights_here(), PHP_URL_QUERY );
	parse_str( (string) $qs, $keep );
	unset( $keep['cfi_od'], $keep['cfi_do'], $keep['cfi_days'] );
	foreach ( $keep as $k => $v ) {
		$h .= is_scalar( $v ) ? '<input type="hidden" name="' . esc_attr( (string) $k ) . '" value="' . esc_attr( (string) $v ) . '">' : '';
	}
	$max = wp_date( 'Y-m-d' );
	$h  .= '<label><span class="screen-reader-text">' . esc_html__( 'Od', 'colorify-by-inyfinn' ) . '</span><input type="date" name="cfi_od" value="' . esc_attr( $r['from'] ) . '" max="' . esc_attr( $max ) . '"></label><span aria-hidden="true">–</span>'
		. '<label><span class="screen-reader-text">' . esc_html__( 'Do', 'colorify-by-inyfinn' ) . '</span><input type="date" name="cfi_do" value="' . esc_attr( $r['to'] ) . '" max="' . esc_attr( $max ) . '"></label>'
		. '<button type="submit" class="cfi-btn">' . esc_html__( 'Pokaż', 'colorify-by-inyfinn' ) . '</button></form></div>';
	return $h;
}

function colorify_insights_traffic_panel(): string {
	if ( ! colorify_insights_available() ) {
		return '<section class="cfi cfi-card">' . colorify_insights_state( __( 'Statystyki ruchu zaczną się pokazywać, gdy licznik zbierze pierwsze dane.', 'colorify-by-inyfinn' ), __( 'Zbieramy dane od dziś', 'colorify-by-inyfinn' ) ) . '</section>';
	}
	colorify_insights_assets();
	$r   = colorify_insights_range();
	$t   = colorify_analytics_totals( $r['from'], $r['to'] );
	$krok = isset( $_GET['cfi_krok'] ) && 'week' === $_GET['cfi_krok'] ? 'week' : ( isset( $_GET['cfi_krok'] ) && 'day' === $_GET['cfi_krok'] ? 'day' : ( $r['days'] > 120 ? 'week' : 'day' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$s   = colorify_analytics_series( $r['from'], $r['to'], $krok );
	$pg  = colorify_analytics_pages( $r['from'], $r['to'], array( 'per_page' => 500, 'sort' => 'views', 'order' => 'desc' ) );
	$allowed = ! empty( $t['compare_allowed'] );
	$why     = (string) ( $t['compare_reason'] ?? '' );

	$o  = '<div class="cfi-head"><div><h2 class="cfi-h2">' . esc_html__( 'Ruch na stronie', 'colorify-by-inyfinn' ) . '</h2><p class="cfi-sub">' . esc_html( colorify_insights_date( $r['from'], 'j.m.Y' ) . ' – ' . colorify_insights_date( $r['to'], 'j.m.Y' ) ) . ( $r['with_today'] ?? false ? ' ' . esc_html__( '(razem z dzisiejszym dniem)', 'colorify-by-inyfinn' ) : '' ) . '</p></div>' . colorify_insights_range_bar( $r ) . '</div>';
	if ( ! empty( $r['error'] ) ) {
		$o .= '<p class="cfi-notice" role="status">' . esc_html( (string) $r['error'] ) . '</p>';
	}

	$inq = colorify_insights_inquiries_count( $r['from'], $r['to'] );
	$o  .= '<section class="cfi-card cfi-strip" aria-label="' . esc_attr__( 'Najważniejsze liczby', 'colorify-by-inyfinn' ) . '">'
		. colorify_insights_stat( __( 'Odsłony', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $t['views'] ) ), colorify_insights_delta( $t['views'], $t['prev']['views'] ?? null, $allowed, $why ), sprintf( /* translators: %s: liczba */ __( '%s dziennie, ze zmierzonych dni', 'colorify-by-inyfinn' ), colorify_insights_n( (float) ( $t['views_per_day'] ?? 0 ), 1 ) ), colorify_insights_detail_url( array( 'metryka' => 'views' ), $r ) )
		. colorify_insights_stat( __( 'Odwiedzający dziennie · szacunek', 'colorify-by-inyfinn' ), null === $t['visitors_avg'] ? '—' : esc_html( colorify_insights_n( $t['visitors_avg'], 1 ) ), colorify_insights_delta( $t['visitors_avg'], $t['prev']['visitors_avg'] ?? null, $allowed, $why ), __( 'Średnia dzienna, nie suma osób', 'colorify-by-inyfinn' ), colorify_insights_detail_url( array( 'metryka' => 'visitors' ), $r ) )
		. colorify_insights_stat( __( 'Zapytania', 'colorify-by-inyfinn' ), null === $inq ? '—' : esc_html( colorify_insights_n( $inq ) ), '', null === $inq ? __( 'Pojawią się, gdy ktoś napisze', 'colorify-by-inyfinn' ) : __( 'W tym okresie', 'colorify-by-inyfinn' ) )
		. colorify_insights_stat( __( 'Strony z ruchem', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $pg['total_rows'] ?? 0 ) ), '', __( 'Pokaż wszystkie', 'colorify-by-inyfinn' ), '#cfi-strony' )
		. '</section>';

	/* wykres */
	$pts   = colorify_insights_points( $s, 'views' );
	$toggle = '<nav class="cfi-seg cfi-seg--s" aria-label="' . esc_attr__( 'Krok', 'colorify-by-inyfinn' ) . '"><a href="' . esc_url( colorify_insights_here( array( 'cfi_krok' => 'day', 'cfi_days' => $r['preset'] ?: null, 'cfi_od' => $r['preset'] ? null : $r['from'], 'cfi_do' => $r['preset'] ? null : $r['to'] ) ) ) . '"' . ( 'day' === $krok ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'Dni', 'colorify-by-inyfinn' ) . '</a><a href="' . esc_url( colorify_insights_here( array( 'cfi_krok' => 'week', 'cfi_days' => $r['preset'] ?: null, 'cfi_od' => $r['preset'] ? null : $r['from'], 'cfi_do' => $r['preset'] ? null : $r['to'] ) ) ) . '"' . ( 'week' === $krok ? ' aria-current="true"' : '' ) . '>' . esc_html__( 'Tygodnie', 'colorify-by-inyfinn' ) . '</a></nav>';
	$o    .= colorify_insights_card( __( 'Odsłony w czasie', 'colorify-by-inyfinn' ), ( $t['measured_days'] ?? 0 ) > 0 ? colorify_insights_bars( $pts, 'views', (array) ( $s['annotations'] ?? array() ), __( 'Odsłony strony w wybranym okresie', 'colorify-by-inyfinn' ) ) . colorify_insights_points_table( $pts, __( 'Odsłony', 'colorify-by-inyfinn' ) ) . ( $t['measured_days'] < $t['days'] ? '<p class="cfi-foot">' . esc_html( sprintf( /* translators: 1: zmierzone dni 2: dni */ __( 'Zapis jest z %1$d z %2$d dni. Dni bez zapisu zostawiamy puste, nie liczymy ich jako zero.', 'colorify-by-inyfinn' ), $t['measured_days'], $t['days'] ) ) . '</p>' : '' ) : colorify_insights_state( colorify_insights_since_text( null ) ), 'cfi-card--chart', $toggle );

	/* tabela stron */
	$o .= colorify_insights_pages_card( $pg, $r );

	/* źródła + urządzenia */
	$dr  = colorify_insights_dim_range( $r );
	$src = colorify_analytics_sources( $dr['from'], $dr['to'] );
	$dev = colorify_analytics_devices( $dr['from'], $dr['to'] );
	$o  .= '<div class="cfi-grid2">' . colorify_insights_sources_card( $src, $dr['note'] ) . colorify_insights_devices_card( $dev, $dr['note'] ) . '</div>';

	/* godziny + 404 */
	$o .= colorify_insights_hours_card( colorify_analytics_hours_grid( $dr['from'], $dr['to'] ), $dr['note'] );
	$o .= colorify_insights_404_card( colorify_analytics_errors404( $dr['from'], $dr['to'], 20 ), $r, $dr['note'] );
	$o .= colorify_insights_quality_card( colorify_analytics_coverage(), $t );
	return '<div class="cfi cfi-traffic">' . $o . '</div>';
}

function colorify_insights_pages_card( array $pg, array $r ): string {
	$rows = (array) ( $pg['rows'] ?? array() );
	if ( ! $rows ) {
		return colorify_insights_card( __( 'Wszystkie strony', 'colorify-by-inyfinn' ), colorify_insights_state( colorify_insights_since_text( null ) ), '', '', 'cfi-strony' );
	}
	$max = 1;
	foreach ( $rows as $x ) {
		$max = max( $max, (int) $x['views'] );
	}
	$allowed = ! empty( $pg['compare_allowed'] );
	// Wejścia z zewnątrz są mierzone dopiero od dims_since: przed tą datą "—" z dymkiem, nie zero.
	$dr       = colorify_insights_dim_range( $r );
	$dims_ok  = '' !== $dr['since'] && $dr['since'] <= $dr['to'];
	$dims_txt = '' !== $dr['since'] ? colorify_insights_since_text( $dr['since'] ) : colorify_insights_since_text( null );
	$ent      = array(); // klucz strony => wejścia z zewnątrz w zakresie nowego licznika (także samo dziś).
	if ( $dims_ok && function_exists( 'colorify_analytics_entry_pages' ) ) {
		foreach ( (array) ( colorify_analytics_entry_pages( $dr['from'], $dr['to'], 500 )['rows'] ?? array() ) as $er ) {
			$ent[ (string) $er['key'] ] = (int) $er['entries'];
		}
	}
	$b       = '<div class="cfi-tools"><label class="cfi-search"><span class="screen-reader-text">' . esc_html__( 'Szukaj strony', 'colorify-by-inyfinn' ) . '</span><input type="search" placeholder="' . esc_attr__( 'Szukaj strony lub adresu', 'colorify-by-inyfinn' ) . '" data-cfi-search></label><span class="cfi-count" data-cfi-count aria-live="polite"></span></div>'
		. '<div class="cfi-scroll"><table class="cfi-table cfi-sortable" data-cfi-table><thead><tr>'
		. '<th><button type="button" data-sort="t">' . esc_html__( 'Strona', 'colorify-by-inyfinn' ) . '</button></th>'
		. '<th class="is-num"><button type="button" data-sort="n" aria-sort="descending" class="is-sorted">' . esc_html__( 'Odsłony', 'colorify-by-inyfinn' ) . '</button></th>'
		. '<th class="is-num cfi-hide-s"><button type="button" data-sort="p">' . esc_html__( 'Udział', 'colorify-by-inyfinn' ) . '</button></th>'
		. '<th class="is-num cfi-hide-s"><button type="button" data-sort="e">' . esc_html__( 'Wejścia', 'colorify-by-inyfinn' ) . '</button></th>'
		. '<th class="is-num cfi-hide-s"><button type="button" data-sort="c">' . esc_html__( 'Zmiana', 'colorify-by-inyfinn' ) . '</button></th>'
		. '</tr></thead><tbody>';
	foreach ( $rows as $x ) {
		$path = (string) wp_parse_url( (string) $x['url'], PHP_URL_PATH );
		$qry  = (string) wp_parse_url( (string) $x['url'], PHP_URL_QUERY );
		$path = ( '/' === $path && '' !== $qry ? '/?' . $qry : $path ); // Strona bez ładnego adresu: pokaż prawdziwy adres, nie samo "/".
		$stt  = ctype_digit( (string) $x['key'] ) ? (string) get_post_status( (int) $x['key'] ) : '';
		$stl  = array( 'draft' => __( 'szkic', 'colorify-by-inyfinn' ), 'private' => __( 'prywatna', 'colorify-by-inyfinn' ), 'pending' => __( 'oczekuje', 'colorify-by-inyfinn' ), 'future' => __( 'zaplanowana', 'colorify-by-inyfinn' ), 'trash' => __( 'w koszu', 'colorify-by-inyfinn' ) )[ $stt ] ?? '';
		$chg  = $allowed && null !== $x['change'] ? (float) $x['change'] : null;
		$b   .= '<tr data-t="' . esc_attr( mb_strtolower( $x['title'] . ' ' . $path ) ) . '" data-n="' . (int) $x['views'] . '" data-p="' . (float) $x['share'] . '" data-e="' . (int) ( $ent[ (string) $x['key'] ] ?? 0 ) . '" data-c="' . ( null === $chg ? -999999 : $chg ) . '">'
			. '<td><a class="cfi-pg" href="' . esc_url( colorify_insights_detail_url( array( 'strona' => (string) $x['key'] ), $r ) ) . '"><span class="cfi-pg__t">' . esc_html( $x['title'] ) . '</span><span class="cfi-pg__u">' . esc_html( '' !== $path ? $path : (string) $x['key'] ) . ( '' !== $stl ? ' · ' . $stl : '' ) . '</span><span class="cfi-pg__m">' . esc_html( colorify_insights_n( $x['share'] * 100, 1 ) . '% ' . __( 'ruchu', 'colorify-by-inyfinn' ) . ( $dims_ok ? ' · ' . colorify_insights_n( $ent[ (string) $x['key'] ] ?? 0 ) . ' ' . __( 'wejść z zewnątrz', 'colorify-by-inyfinn' ) : '' ) . ( null !== $chg && (float) $x['prev_views'] > 0 ? ' · ' . ( $x['views'] >= $x['prev_views'] ? '+' : '' ) . colorify_insights_n( ( $x['views'] - $x['prev_views'] ) / $x['prev_views'] * 100, 0 ) . '%' : '' ) ) . '</span></a></td>'
			. '<td class="is-num"><span class="cfi-meter"><span class="cfi-meter__bar" style="width:' . round( $x['views'] / $max * 100, 1 ) . '%"></span><span class="cfi-meter__v">' . esc_html( colorify_insights_n( $x['views'] ) ) . '</span></span></td>'
			. '<td class="is-num cfi-hide-s">' . esc_html( colorify_insights_n( $x['share'] * 100, 1 ) ) . '%</td>'
			. '<td class="is-num cfi-hide-s">' . ( $dims_ok ? '<span' . ( '' !== $dr['note'] ? ' title="' . esc_attr( $dr['note'] ) . '"' : '' ) . '>' . esc_html( colorify_insights_n( $ent[ (string) $x['key'] ] ?? 0 ) ) . '</span>' : '<span class="cfi-muted" title="' . esc_attr( $dims_txt ) . '">—</span>' ) . '</td>'
			. '<td class="is-num cfi-hide-s">' . ( null === $chg ? '<span class="cfi-muted">—</span>' : colorify_insights_delta( (float) $x['views'], (float) $x['prev_views'], true ) ) . '</td></tr>';
	}
	$b .= '</tbody></table></div>';
	if ( ! $allowed ) {
		$b .= '<p class="cfi-foot">' . esc_html( colorify_insights_reason( (string) ( $pg['compare_reason'] ?? '' ) ) ) . '</p>';
	}
	if ( ! empty( $pg['low_sample'] ) ) {
		$b .= '<p class="cfi-foot">' . esc_html__( 'Mało danych (poniżej 50 odsłon): wnioski bierz ostrożnie.', 'colorify-by-inyfinn' ) . '</p>';
	}
	return colorify_insights_card( sprintf( /* translators: %s: liczba */ __( 'Wszystkie strony (%s)', 'colorify-by-inyfinn' ), colorify_insights_n( $pg['total_rows'] ?? count( $rows ) ) ), $b, '', '', 'cfi-strony' );
}

/** Poziome słupki z etykietą po lewej i liczbą po prawej. */
function colorify_insights_hbars( array $rows, bool $pct = true ): string {
	$max = 1;
	foreach ( $rows as $x ) {
		$max = max( $max, (int) $x[1] );
	}
	$h = '<ul class="cfi-hbars">';
	foreach ( $rows as $x ) {
		$h .= '<li><span class="cfi-hbars__l">' . esc_html( $x[0] ) . '</span><span class="cfi-hbars__track"><span style="width:' . round( $x[1] / $max * 100, 1 ) . '%"></span></span><span class="cfi-hbars__v">' . esc_html( colorify_insights_n( $x[1] ) ) . ' ' . ( $pct ? '<small>' . esc_html( colorify_insights_n( $x[2] * 100, 0 ) ) . '%</small>' : '' ) . '</span></li>';
	}
	return $h . '</ul>';
}

function colorify_insights_sources_card( array $s, string $note = '' ): string {
	if ( ! empty( $s['partial'] ) && ! (int) ( $s['external_views'] ?? 0 ) && ! array_sum( array_column( (array) $s['classes'], 'views' ) ) ) {
		return colorify_insights_card( __( 'Skąd przychodzą', 'colorify-by-inyfinn' ), colorify_insights_state( colorify_insights_since_text( $s['measured_from'] ?? null ), __( 'Źródła jeszcze się zbierają', 'colorify-by-inyfinn' ) ) );
	}
	$rows = array();
	foreach ( (array) $s['classes'] as $c ) {
		$rows[] = array( $c['label'], $c['views'], $c['share'] );
	}
	$low = colorify_insights_low_note( $s );
	$b   = colorify_insights_hbars( $rows, '' === $low );
	if ( ! empty( $s['domains'] ) ) {
		$b .= '<h4 class="cfi-h4">' . esc_html__( 'Najczęstsze strony odsyłające', 'colorify-by-inyfinn' ) . '</h4><ul class="cfi-list">';
		foreach ( array_slice( $s['domains'], 0, 6 ) as $d ) {
			$b .= '<li><span>' . esc_html( $d['label'] ?: $d['domain'] ) . '</span><b>' . esc_html( colorify_insights_n( $d['views'] ) ) . '</b></li>';
		}
		$b .= '</ul>';
	}
	$foot = trim( $note . ' ' . $low );
	if ( '' === $foot && ! empty( $s['partial'] ) ) {
		$foot = colorify_insights_since_text( $s['measured_from'] ?? null );
	}
	return colorify_insights_card( __( 'Skąd przychodzą', 'colorify-by-inyfinn' ), $b . ( '' !== $foot ? '<p class="cfi-foot">' . esc_html( $foot ) . '</p>' : '' ) );
}

function colorify_insights_devices_card( array $d, string $note = '' ): string {
	if ( empty( $d['views'] ) ) {
		return colorify_insights_card( __( 'Urządzenia', 'colorify-by-inyfinn' ), colorify_insights_state( colorify_insights_since_text( $d['measured_from'] ?? null ), __( 'Urządzenia jeszcze się zbierają', 'colorify-by-inyfinn' ) ) );
	}
	$rows = array();
	foreach ( (array) $d['devices'] as $x ) {
		$rows[] = array( $x['label'], $x['views'], $x['share'] );
	}
	$low  = colorify_insights_low_note( $d );
	$foot = trim( $note . ' ' . $low );
	if ( '' === $foot && ! empty( $d['partial'] ) ) {
		$foot = colorify_insights_since_text( $d['measured_from'] ?? null );
	}
	return colorify_insights_card( __( 'Urządzenia', 'colorify-by-inyfinn' ), colorify_insights_hbars( $rows, '' === $low ) . ( '' !== $foot ? '<p class="cfi-foot">' . esc_html( $foot ) . '</p>' : '' ) );
}

function colorify_insights_hours_card( array $g, string $note = '' ): string {
	if ( empty( $g['total'] ) ) {
		return colorify_insights_card( __( 'Kiedy ludzie wchodzą', 'colorify-by-inyfinn' ), colorify_insights_state( colorify_insights_since_text( $g['measured_from'] ?? null ), __( 'Godziny jeszcze się zbierają', 'colorify-by-inyfinn' ) ) );
	}
	$max = max( 1, (int) $g['max'] );
	$h   = '<div class="cfi-heat" role="table" aria-label="' . esc_attr__( 'Odsłony według dnia tygodnia i godziny', 'colorify-by-inyfinn' ) . '"><div class="cfi-heat__row cfi-heat__hours" aria-hidden="true"><span></span>';
	for ( $i = 0; $i < 24; $i++ ) {
		$h .= '<span>' . ( 0 === $i % 3 ? $i : '' ) . '</span>';
	}
	$h .= '</div>';
	foreach ( (array) $g['grid'] as $row ) {
		$h .= '<div class="cfi-heat__row" role="row"><span class="cfi-heat__d" role="rowheader">' . esc_html( $row['label'] ) . '</span>';
		foreach ( (array) $row['hours'] as $i => $v ) {
			$h .= '<span class="cfi-heat__c" role="cell" style="--v:' . round( $v / $max, 3 ) . '" title="' . esc_attr( $row['label'] . ' ' . sprintf( '%02d:00', $i ) . ': ' . colorify_insights_n( $v ) ) . '" data-tip="' . esc_attr( $row['label'] . ' ' . sprintf( '%02d:00', $i ) . ': ' . colorify_insights_n( $v ) . ' ' . __( 'odsłon', 'colorify-by-inyfinn' ) ) . '"></span>';
		}
		$h .= '</div>';
	}
	$h .= '</div><p class="cfi-foot">' . esc_html( trim( __( 'Im ciemniej, tym więcej wejść.', 'colorify-by-inyfinn' ) . ' ' . $note . ' ' . colorify_insights_low_note( $g ) ) ) . '</p>';
	return colorify_insights_card( __( 'Kiedy ludzie wchodzą', 'colorify-by-inyfinn' ), $h );
}

/** Podpowiedź naprawy 404: najbliższa istniejąca strona po slugu. */
function colorify_insights_404_fix( string $path ): array {
	$slug = trim( basename( untrailingslashit( $path ) ) );
	if ( '' === $slug ) {
		return array();
	}
	$best = null;
	$bp   = 0.0;
	foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids' ) ) as $id ) {
		$p = get_post( $id );
		similar_text( $slug, $p->post_name, $pc );
		if ( $pc > $bp ) {
			$bp   = $pc;
			$best = $p;
		}
	}
	return $best && $bp >= 55 ? array( 'title' => get_the_title( $best ), 'url' => get_permalink( $best ), 'score' => (int) $bp ) : array();
}

function colorify_insights_404_card( array $e, array $r, string $note = '' ): string {
	if ( empty( $e['rows'] ) ) {
		return colorify_insights_card( __( 'Strony, których nie ma (404)', 'colorify-by-inyfinn' ), colorify_insights_state( ! empty( $e['partial'] ) ? colorify_insights_since_text( $e['measured_from'] ?? null ) : __( 'W tym okresie nikt nie trafił w nieistniejący adres.', 'colorify-by-inyfinn' ), ! empty( $e['partial'] ) ? __( 'Błędy jeszcze się zbierają', 'colorify-by-inyfinn' ) : __( 'Brak błędów 404', 'colorify-by-inyfinn' ) ) );
	}
	$b = '<div class="cfi-scroll"><table class="cfi-table"><thead><tr><th>' . esc_html__( 'Adres', 'colorify-by-inyfinn' ) . '</th><th class="is-num">' . esc_html__( 'Wejść', 'colorify-by-inyfinn' ) . '</th><th class="cfi-hide-s">' . esc_html__( 'Skąd', 'colorify-by-inyfinn' ) . '</th><th>' . esc_html__( 'Naprawa', 'colorify-by-inyfinn' ) . '</th></tr></thead><tbody>';
	foreach ( (array) $e['rows'] as $x ) {
		$fix  = colorify_insights_404_fix( (string) $x['path'] );
		$ref  = ! empty( $x['refs'][0] ) ? $x['refs'][0] : null;
		$line = $fix ? 'Redirect 301 ' . $x['path'] . ' ' . $fix['url'] : '';
		$b   .= '<tr><td><code class="cfi-code">' . esc_html( $x['path'] ) . '</code><span class="cfi-sub2">' . esc_html( sprintf( /* translators: %s: data */ __( 'ostatnio %s', 'colorify-by-inyfinn' ), colorify_insights_date( (string) $x['last_day'], 'j.m' ) ) ) . '</span></td><td class="is-num">' . esc_html( colorify_insights_n( $x['hits'] ) ) . '</td><td class="cfi-hide-s">' . ( $ref ? esc_html( $ref['label'] ) : '<span class="cfi-muted">—</span>' ) . '</td><td>'
			. ( $fix ? '<span class="cfi-fix">' . esc_html__( 'Prawdopodobnie chodziło o', 'colorify-by-inyfinn' ) . ' <a href="' . esc_url( $fix['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $fix['title'] ) . '</a></span>' . ( colorify_insights_is_owner() ? '<button type="button" class="cfi-btn cfi-btn--s" data-cfi-copy="' . esc_attr( $line ) . '">' . esc_html__( 'Kopiuj przekierowanie 301', 'colorify-by-inyfinn' ) . '</button>' : '' ) : '<span class="cfi-muted">' . esc_html__( 'Brak podobnej strony. Utwórz ją albo zostaw.', 'colorify-by-inyfinn' ) . '</span>' )
			. '</td></tr>';
	}
	return colorify_insights_card( sprintf( /* translators: %s: liczba */ __( 'Strony, których nie ma (404): %s wejść', 'colorify-by-inyfinn' ), colorify_insights_n( $e['hits'] ?? 0 ) ), $b . '</tbody></table></div><p class="cfi-foot">' . esc_html( trim( __( 'Adresy, w które ktoś trafił, a strony tam nie ma. Wpisy z prób włamań i skanerów pomijamy.', 'colorify-by-inyfinn' ) . ' ' . $note ) ) . '</p>', '', '', 'cfi-404' );
}

function colorify_insights_quality_card( array $c, array $t ): string {
	$b = '<dl class="cfi-dl"><dt>' . esc_html__( 'Jak liczymy', 'colorify-by-inyfinn' ) . '</dt><dd>' . esc_html( (string) $c['method_label'] ) . '<details class="cfi-tab"><summary>' . esc_html__( 'Szczegóły metody', 'colorify-by-inyfinn' ) . '</summary><p class="cfi-foot">' . esc_html( (string) $c['method_about'] ) . '</p></details></dd>'
		. '<dt>' . esc_html__( 'Dane od', 'colorify-by-inyfinn' ) . '</dt><dd>' . esc_html( ! empty( $c['data_since'] ) ? colorify_insights_date( (string) $c['data_since'] ) : '—' ) . ( ! empty( $c['next_from'] ) ? ' ' . esc_html( sprintf( /* translators: %s: data */ __( '(nowa metoda od %s)', 'colorify-by-inyfinn' ), colorify_insights_date( (string) $c['next_from'], 'j F' ) ) ) : '' ) . '</dd>'
		. '<dt>' . esc_html__( 'Boty', 'colorify-by-inyfinn' ) . '</dt><dd>' . ( ! empty( $c['bots']['ready'] ) && null !== $c['bots']['share'] ? esc_html( colorify_insights_n( $c['bots']['share'] * 100, 1 ) ) . '% ' . esc_html__( 'wejść odfiltrowane', 'colorify-by-inyfinn' ) : esc_html__( 'Za wcześnie na udział botów (potrzeba 7 dni nowej metody).', 'colorify-by-inyfinn' ) ) . '</dd>';
	if ( ! empty( $c['gaps'] ) ) {
		$b .= '<dt>' . esc_html__( 'Dni bez zapisu', 'colorify-by-inyfinn' ) . '</dt><dd>' . esc_html( implode( ', ', array_map( static fn( $g ) => $g['from'] === $g['to'] ? colorify_insights_date( $g['from'], 'j.m' ) : colorify_insights_date( $g['from'], 'j.m' ) . '–' . colorify_insights_date( $g['to'], 'j.m' ), $c['gaps'] ) ) ) . '</dd>';
	}
	$b .= '<dt>' . esc_html__( 'Mało danych', 'colorify-by-inyfinn' ) . '</dt><dd>' . esc_html( sprintf( /* translators: %d: próg */ __( 'Poniżej %d odsłon nie wyciągamy wniosków ani procentów.', 'colorify-by-inyfinn' ), (int) ( $c['low_sample_below'] ?? 50 ) ) ) . ( ! empty( $t['low_sample'] ) ? ' <strong>' . esc_html__( 'W tym okresie jest ich mało.', 'colorify-by-inyfinn' ) . '</strong>' : '' ) . '</dd>'
		. ( ! empty( $c['engagement']['label'] ) ? '<dt>' . esc_html__( 'Czas na stronie', 'colorify-by-inyfinn' ) . '</dt><dd>' . esc_html( (string) $c['engagement']['label'] ) . '</dd>' : '' ) . '</dl>';
	return colorify_insights_card( __( 'Jakość danych', 'colorify-by-inyfinn' ), $b, 'cfi-card--quiet' );
}

/* ====================================================================== Szczegóły ====================================================================== */

function colorify_insights_back( string $url, string $label ): string {
	return '<a class="cfi-back" href="' . esc_url( $url ) . '"><span aria-hidden="true">←</span> ' . esc_html( $label ) . '</a>';
}

/** Szczegóły metryki: wykres + tabela dzień po dniu (odsłony, odwiedzający) albo pełna lista stron. */
function colorify_insights_metric_detail( string $m ): string {
	if ( ! colorify_insights_available() ) {
		return colorify_insights_state( colorify_insights_since_text( null ) );
	}
	$r   = colorify_insights_range();
	$out = colorify_insights_back( admin_url( 'index.php' ), __( 'Wstecz do kokpitu', 'colorify-by-inyfinn' ) );
	if ( 'pages' === $m ) {
		return $out . '<h2 class="cfi-h2">' . esc_html__( 'Strony z ruchem', 'colorify-by-inyfinn' ) . '</h2>' . colorify_insights_pages_card( colorify_analytics_pages( $r['from'], $r['to'], array( 'per_page' => 500 ) ), $r );
	}
	$m   = 'visitors' === $m ? 'visitors' : 'views';
	$t   = colorify_analytics_totals( $r['from'], $r['to'] );
	$s   = colorify_analytics_series( $r['from'], $r['to'], $r['days'] > 120 ? 'week' : 'day' );
	$pts = colorify_insights_points( $s, $m );
	$ttl = 'visitors' === $m ? __( 'Odwiedzający (szacunek dzienny)', 'colorify-by-inyfinn' ) : __( 'Odsłony', 'colorify-by-inyfinn' );
	$out .= '<div class="cfi-head"><div><h2 class="cfi-h2">' . esc_html( $ttl ) . '</h2><p class="cfi-sub">' . esc_html( colorify_insights_date( $r['from'], 'j.m.Y' ) . ' – ' . colorify_insights_date( $r['to'], 'j.m.Y' ) ) . '</p></div>' . colorify_insights_range_bar( $r ) . '</div>';
	$out .= '<section class="cfi-card cfi-strip cfi-strip--3">'
		. colorify_insights_stat( __( 'Razem', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( 'visitors' === $m ? $t['visitors_sum'] : $t['views'] ) ), '', 'visitors' === $m ? __( 'Suma dziennych szacunków (osoby mogą się powtarzać)', 'colorify-by-inyfinn' ) : '' )
		. colorify_insights_stat( __( 'Średnio dziennie', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( 'visitors' === $m ? (float) $t['visitors_avg'] : (float) $t['views_per_day'], 1 ) ), colorify_insights_delta( 'visitors' === $m ? $t['visitors_avg'] : $t['views'], 'visitors' === $m ? ( $t['prev']['visitors_avg'] ?? null ) : ( $t['prev']['views'] ?? null ), ! empty( $t['compare_allowed'] ), (string) $t['compare_reason'] ) )
		. colorify_insights_stat( __( 'Zmierzone dni', 'colorify-by-inyfinn' ), esc_html( $t['measured_days'] . ' / ' . $t['days'] ), '', __( 'Pozostałe dni bez zapisu', 'colorify-by-inyfinn' ) ) . '</section>';
	$out .= colorify_insights_card( $ttl, colorify_insights_bars( $pts, $m, (array) ( $s['annotations'] ?? array() ), $ttl ) . colorify_insights_points_table( $pts, $ttl ) );
	return '<div class="cfi cfi-detail">' . $out . '</div>';
}

/** Szczegóły jednej strony: liczby, wykres, źródła, urządzenia, przyciski Edytuj/Zobacz. */
function colorify_insights_page_detail( string $key ): string {
	if ( ! colorify_insights_available() ) {
		return colorify_insights_state( colorify_insights_since_text( null ) );
	}
	$r   = colorify_insights_range();
	$rep = colorify_analytics_page_report( $key, $r['from'], $r['to'] );
	$dr          = colorify_insights_dim_range( $r );
	$dims_s      = $dr['since'];
	$entries_ok  = '' !== $dims_s && $dims_s <= $dr['to'];
	if ( $entries_ok && function_exists( 'colorify_analytics_entry_pages' ) ) {
		foreach ( (array) ( colorify_analytics_entry_pages( $dr['from'], $dr['to'], 500 )['rows'] ?? array() ) as $er ) {
			if ( (string) $er['key'] === $key ) {
				$rep['entries'] = (int) $er['entries'];
			}
		}
	}
	$pg  = $rep['page'];
	$act = '';
	if ( ! empty( $pg['edit_url'] ) ) {
		$act .= '<a class="cfi-btn" href="' . esc_url( $pg['edit_url'] ) . '">' . esc_html__( 'Edytuj treść', 'colorify-by-inyfinn' ) . '</a>';
	}
	if ( ! empty( $pg['url'] ) ) {
		$act .= '<a class="cfi-btn cfi-btn--ghost" href="' . esc_url( $pg['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Zobacz stronę', 'colorify-by-inyfinn' ) . '</a>';
	}
	$out  = colorify_insights_back( colorify_insights_detail_url( array(), $r ), __( 'Wstecz do ruchu', 'colorify-by-inyfinn' ) );
	$out .= '<div class="cfi-head"><div><h2 class="cfi-h2">' . esc_html( $pg['title'] ) . '</h2><p class="cfi-sub">' . esc_html( (string) wp_parse_url( (string) $pg['url'], PHP_URL_PATH ) ) . ' · ' . esc_html( colorify_insights_date( $r['from'], 'j.m.Y' ) . ' – ' . colorify_insights_date( $r['to'], 'j.m.Y' ) ) . '</p></div><div class="cfi-actions">' . $act . '</div></div>';
	$out .= colorify_insights_range_bar( $r );
	$out .= '<section class="cfi-card cfi-strip cfi-strip--3">'
		. colorify_insights_stat( __( 'Odsłony', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $rep['views'] ) ), colorify_insights_delta( $rep['views'], $rep['prev_views'], ! empty( $rep['compare_allowed'] ), (string) $rep['compare_reason'] ) )
		. colorify_insights_stat( __( 'Wejścia z zewnątrz', 'colorify-by-inyfinn' ), $entries_ok ? esc_html( colorify_insights_n( $rep['entries'] ) ) : '—', '', $entries_ok ? ( '' !== $dr['note'] ? $dr['note'] : __( 'Ktoś zaczął od tej strony', 'colorify-by-inyfinn' ) ) : colorify_insights_since_text( $dims_s ?: null ) )
		. colorify_insights_stat( __( 'Udział w ruchu', 'colorify-by-inyfinn' ), null === $rep['share_of_site'] ? '—' : esc_html( colorify_insights_n( $rep['share_of_site'] * 100, 1 ) ) . '%' ) . '</section>';
	$pts  = array();
	foreach ( (array) $rep['series'] as $p ) {
		$v     = ! empty( $p['measured'] ) ? $p['views'] : null;
		$pts[] = array( 'date' => $p['date'], 'value' => null === $v ? null : (float) $v, 'label' => colorify_insights_date( (string) $p['date'], 'D j F' ) . ': ' . ( null === $v ? __( 'brak zapisu', 'colorify-by-inyfinn' ) : colorify_insights_n( $v ) . ' ' . __( 'odsłon', 'colorify-by-inyfinn' ) ) );
	}
	$out .= colorify_insights_card( __( 'Odsłony tej strony w czasie', 'colorify-by-inyfinn' ), colorify_insights_bars( $pts, 'views', (array) ( $rep['annotations'] ?? array() ), $pg['title'] ) . colorify_insights_points_table( $pts, __( 'Odsłony', 'colorify-by-inyfinn' ) ) );
	$out .= '<div class="cfi-grid2">' . colorify_insights_sources_card( colorify_analytics_sources( $dr['from'], $dr['to'], $key ), $dr['note'] ) . colorify_insights_devices_card( colorify_analytics_devices( $dr['from'], $dr['to'], $key ), $dr['note'] ) . '</div>';
	return '<div class="cfi cfi-detail">' . $out . '</div>';
}

/* ====================================================================== Moduły per widok ====================================================================== */

/**
 * Moduł do wstawienia w widok kokpitu: echo colorify_insights_module( 'company' | 'leads' | 'editorial' | 'portfolio' | 'sales' );
 * Każdy widok ma inną kompozycję pierwszego ekranu i dane z WordPressa (bez pomiaru), a gdzie danych nie ma, uczciwy stan.
 */
function colorify_insights_module( string $view ): string {
	colorify_insights_assets();
	$fn   = 'colorify_insights_mod_' . $view;
	$html = function_exists( $fn ) ? (string) $fn() : '';
	return '' !== $html ? '<div class="cfi cfi-mod cfi-mod--' . esc_attr( $view ) . '">' . $html . '</div>' : ''; // pusty moduł nie zostawia pustego opakowania
}

function colorify_insights_age( int $ts ): string {
	$d = max( 0, time() - $ts );
	if ( $d < 3600 ) {
		return __( 'przed chwilą', 'colorify-by-inyfinn' );
	}
	return $d < 172800 ? sprintf( /* translators: %d: godziny */ __( '%d godz.', 'colorify-by-inyfinn' ), (int) floor( $d / 3600 ) ) : sprintf( /* translators: %d: dni */ __( '%d dni', 'colorify-by-inyfinn' ), (int) floor( $d / 86400 ) );
}

/** Wiersz listy: tytuł (link) z podpisem po lewej, plakietka po prawej. */
function colorify_insights_row( string $title, string $url, string $sub = '', string $pill = '', string $tone = '' ): string {
	$t = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
	return '<li class="cfi-row"><span class="cfi-row__main"><b>' . $t . '</b>' . ( '' !== $sub ? '<small>' . esc_html( $sub ) . '</small>' : '' ) . '</span>' . ( '' !== $pill ? '<span class="cfi-pill ' . esc_attr( $tone ) . '">' . esc_html( $pill ) . '</span>' : '' ) . '</li>';
}

function colorify_insights_rows_card( string $title, array $rows, string $empty, string $href = '', string $link = '' ): string {
	$aside = '' !== $href ? '<a class="cfi-link" href="' . esc_url( $href ) . '">' . esc_html( $link ) . '</a>' : '';
	return colorify_insights_card( $title, $rows ? '<ul class="cfi-rows">' . implode( '', $rows ) . '</ul>' : colorify_insights_state( $empty ), '', $aside );
}

/* ---------- Firma: co trzeba zrobić, żeby strona działała ---------- */

function colorify_insights_last_backup(): ?int {
	$best = 0;
	foreach ( glob( WP_CONTENT_DIR . '/ai1wm-backups/*.wpress' ) ?: array() as $f ) {
		$best = max( $best, (int) filemtime( $f ) );
	}
	$ud = (int) get_option( 'updraft_last_backup_time', 0 );
	if ( ! $ud ) {
		$lb = get_option( 'updraft_last_backup' );
		$ud = is_array( $lb ) && ! empty( $lb['backup_time'] ) ? (int) $lb['backup_time'] : 0;
	}
	$best = max( $best, $ud );
	return $best ? $best : null;
}

function colorify_insights_mod_company(): string {
	if ( ! function_exists( 'get_plugin_updates' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$upd = array();
	foreach ( (array) get_core_updates() as $c ) {
		if ( isset( $c->response ) && 'upgrade' === $c->response ) {
			$upd[] = array( 0, 'WordPress ' . $c->current, admin_url( 'update-core.php' ), __( 'rdzeń systemu', 'colorify-by-inyfinn' ) );
		}
	}
	foreach ( (array) get_plugin_updates() as $p ) {
		$upd[] = array( 1, $p->Name, admin_url( 'update-core.php' ), $p->Version . ' → ' . ( $p->update->new_version ?? '' ) );
	}
	foreach ( (array) get_theme_updates() as $th ) {
		$upd[] = array( 2, $th->display( 'Name' ), admin_url( 'update-core.php' ), $th->display( 'Version' ) . ' → ' . ( $th->update['new_version'] ?? '' ) );
	}
	usort( $upd, static fn( $a, $b ) => $a[0] <=> $b[0] );
	$lab  = array( __( 'najpierw', 'colorify-by-inyfinn' ), __( 'wtyczka', 'colorify-by-inyfinn' ), __( 'motyw', 'colorify-by-inyfinn' ) );
	$tone = array( 'is-hi', 'is-mid', '' );
	$rows = array_map( static fn( $u ) => colorify_insights_row( $u[1], $u[2], $u[3], $lab[ $u[0] ], $tone[ $u[0] ] ), $upd );

	$health = json_decode( (string) get_transient( 'health-check-site-status-result' ), true );
	$bk     = colorify_insights_last_backup();
	$bage   = $bk ? ( time() - $bk ) / 86400 : 0;
	$tiles  = '<section class="cfi-card cfi-strip cfi-strip--3">'
		. colorify_insights_stat( __( 'Aktualizacje', 'colorify-by-inyfinn' ), esc_html( (string) count( $upd ) ), '', $upd ? __( 'Do zrobienia, kolejność wg ważności', 'colorify-by-inyfinn' ) : __( 'Wszystko aktualne', 'colorify-by-inyfinn' ), admin_url( 'update-core.php' ) )
		. colorify_insights_stat( __( 'Kondycja witryny', 'colorify-by-inyfinn' ), is_array( $health ) ? esc_html( (int) ( $health['critical'] ?? 0 ) . ' / ' . (int) ( $health['recommended'] ?? 0 ) ) : '—', '', is_array( $health ) ? __( 'Krytyczne / zalecane, z ostatniego testu', 'colorify-by-inyfinn' ) : __( 'Test jeszcze nie był robiony. Otwórz Kondycję witryny.', 'colorify-by-inyfinn' ), admin_url( 'site-health.php' ) )
		. colorify_insights_stat( __( 'Ostatnia kopia zapasowa', 'colorify-by-inyfinn' ), null === $bk ? '—' : esc_html( colorify_insights_age( $bk ) ), null === $bk ? '' : '<span class="cfi-delta ' . ( $bage > 14 ? 'is-down' : ( $bage > 7 ? '' : 'is-up' ) ) . '">' . esc_html( $bage > 14 ? __( 'za stara', 'colorify-by-inyfinn' ) : ( $bage > 7 ? __( 'warto odświeżyć', 'colorify-by-inyfinn' ) : __( 'świeża', 'colorify-by-inyfinn' ) ) ) . '</span>', null === $bk ? __( 'Nie znalazłem żadnej kopii zapasowej', 'colorify-by-inyfinn' ) : wp_date( 'j F Y, H:i', $bk ) )
		. '</section>';

	$left    = colorify_insights_rows_card( __( 'Aktualizacje do zrobienia', 'colorify-by-inyfinn' ), $rows, __( 'Nic nie czeka na aktualizację.', 'colorify-by-inyfinn' ), admin_url( 'update-core.php' ), __( 'Zaktualizuj', 'colorify-by-inyfinn' ) );
	$contact = function_exists( 'colorify_tresci_slug' ) ? admin_url( 'admin.php?page=' . colorify_tresci_slug() ) : '';
	$right   = '<div class="cfi-stack">'
		. colorify_insights_card( __( 'Dane kontaktowe firmy', 'colorify-by-inyfinn' ), '<p class="cfi-p">' . esc_html__( 'Telefon, e-mail, adres i godziny zmieniasz w jednym miejscu, a zmiana jest od razu na stronie.', 'colorify-by-inyfinn' ) . '</p>' . ( '' !== $contact ? '<a class="cfi-btn" href="' . esc_url( $contact ) . '">' . esc_html__( 'Zmień dane kontaktowe', 'colorify-by-inyfinn' ) . '</a>' : '<p class="cfi-foot">' . esc_html__( 'Dane kontaktowe zmienisz w edycji strony.', 'colorify-by-inyfinn' ) . '</p>' ) );
	$rows404 = array();
	$more    = '';
	if ( colorify_insights_available() && function_exists( 'colorify_analytics_errors404' ) ) {
		$r    = colorify_insights_range();
		$e    = colorify_analytics_errors404( $r['from'], $r['to'], 5 );
		$more = colorify_insights_detail_url( array(), $r ) . '#cfi-404';
		foreach ( (array) ( $e['rows'] ?? array() ) as $x ) {
			$ref       = ! empty( $x['refs'][0]['from'] ) ? (string) $x['refs'][0]['from'] : '';
			$rows404[] = colorify_insights_row( (string) $x['path'], $ref, sprintf( /* translators: %d: liczba */ _n( '%d wejście', '%d wejść', (int) $x['hits'], 'colorify-by-inyfinn' ), (int) $x['hits'] ), $ref ? __( 'popraw link', 'colorify-by-inyfinn' ) : '', 'is-mid' );
		}
	}
	$right .= colorify_insights_rows_card( __( 'Uszkodzone linki', 'colorify-by-inyfinn' ), $rows404, __( 'Nikt nie trafił w nieistniejący adres (albo jeszcze zbieramy dane).', 'colorify-by-inyfinn' ), $more, '' !== $more ? __( 'Wszystkie', 'colorify-by-inyfinn' ) : '' ) . '</div>';
	return $tiles . '<div class="cfi-split">' . $left . $right . '</div>';
}

/* ---------- Zapytania: kto czeka na odpowiedź, skąd przyszedł, ile kończy się zleceniem ---------- */

/** Czy zalogowany jest właścicielem wtyczki (tylko on widzi wskazówki techniczne). */
function colorify_insights_is_owner(): bool {
	return function_exists( 'colorify_jest_wlascicielem' ) && colorify_jest_wlascicielem();
}

/** Typ wpisu z zapisanymi zgłoszeniami formularza, jeśli taki jest (Flamingo, MetForm). */
function colorify_insights_forms_cpt(): string {
	foreach ( array( 'flamingo_inbound', 'metform-entry' ) as $pt ) {
		if ( post_type_exists( $pt ) ) {
			return $pt;
		}
	}
	return '';
}

/** Liczba zapytań w zakresie: z pluginu rezerwacji (filtr) albo z zapisanych zgłoszeń formularza; null, gdy nie ma skąd. */
function colorify_insights_inquiries_count( string $from, string $to ): ?int {
	$n = apply_filters( 'colorify_insights_inquiries', null, $from, $to );
	if ( null !== $n ) {
		return (int) $n;
	}
	$pt = colorify_insights_forms_cpt();
	if ( '' === $pt ) {
		return null;
	}
	$q = new WP_Query( array( 'post_type' => $pt, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'date_query' => array( array( 'after' => $from . ' 00:00:00', 'before' => $to . ' 23:59:59', 'inclusive' => true ) ) ) );
	return (int) $q->found_posts;
}

/** Lista spraw: [ lista, czy_znamy_odpowiedzi ] albo null. Najpierw dane wtyczki rezerwacji, potem zapisane zgłoszenia formularza. */
function colorify_insights_leads_data( string $from, string $to ): ?array {
	$l = apply_filters( 'colorify_insights_leads', null, $from, $to );
	if ( is_array( $l ) ) {
		return array( $l, true );
	}
	$pt = colorify_insights_forms_cpt();
	if ( '' === $pt ) {
		return null;
	}
	$out = array();
	foreach ( get_posts( array( 'post_type' => $pt, 'post_status' => 'any', 'numberposts' => 10, 'orderby' => 'date', 'order' => 'DESC' ) ) as $p ) {
		$out[] = array( 'title' => get_the_title( $p ) ?: __( 'Zapytanie z formularza', 'colorify-by-inyfinn' ), 'url' => (string) get_edit_post_link( $p->ID, 'raw' ), 'date' => $p->post_date, 'source' => '', 'answered' => false );
	}
	return array( $out, false );
}

function colorify_insights_mod_leads(): string {
	$r      = colorify_insights_available() ? colorify_insights_range() : array( 'from' => wp_date( 'Y-m-d', time() - 89 * DAY_IN_SECONDS ), 'to' => wp_date( 'Y-m-d' ) );
	$data   = colorify_insights_leads_data( $r['from'], $r['to'] );
	$funnel = apply_filters( 'colorify_insights_funnel', null, $r['from'], $r['to'] ); // ['steps'=>[['etykieta', liczba], ...]] albo null.
	$out    = '';
	if ( $data ) {
		list( $leads, $known ) = $data;
		$open = $known ? array_values( array_filter( $leads, static fn( $l ) => empty( $l['answered'] ) ) ) : $leads;
		usort( $open, static fn( $a, $b ) => $known ? strcmp( (string) $a['date'], (string) $b['date'] ) : strcmp( (string) $b['date'], (string) $a['date'] ) );
		$rows = array();
		foreach ( array_slice( $open, 0, 10 ) as $l ) {
			$ts     = (int) strtotime( (string) $l['date'] );
			$rows[] = colorify_insights_row( (string) $l['title'], (string) ( $l['url'] ?? '' ), ! empty( $l['source'] ) ? sprintf( /* translators: %s: źródło */ __( 'Źródło: %s', 'colorify-by-inyfinn' ), $l['source'] ) : '', $known ? sprintf( /* translators: %s: wiek */ __( 'czeka %s', 'colorify-by-inyfinn' ), colorify_insights_age( $ts ) ) : wp_date( 'j.m', $ts ), $known && ( time() - $ts ) > 2 * DAY_IN_SECONDS ? 'is-hi' : 'is-mid' );
		}
		$label = $known ? _n( 'sprawa czeka na odpowiedź', 'spraw czeka na odpowiedź', count( $open ), 'colorify-by-inyfinn' ) : _n( 'ostatnie zapytanie', 'ostatnich zapytań', count( $open ), 'colorify-by-inyfinn' );
		$sub   = $known && $open ? sprintf( /* translators: %s: wiek */ __( 'Najstarsza: %s', 'colorify-by-inyfinn' ), colorify_insights_age( (int) strtotime( (string) $open[0]['date'] ) ) ) : '';
		$empty = $known ? __( 'Na wszystkie zapytania odpowiedziano.', 'colorify-by-inyfinn' ) : __( 'Tu pojawią się zapytania z formularza, gdy ktoś napisze.', 'colorify-by-inyfinn' );
		$out  .= '<section class="cfi-card cfi-hero"><div class="cfi-hero__n"><span class="cfi-hero__v">' . esc_html( (string) count( $open ) ) . '</span><span class="cfi-hero__l">' . esc_html( $label ) . '</span>' . ( '' !== $sub ? '<span class="cfi-hero__s">' . esc_html( $sub ) . '</span>' : '' ) . '</div><ul class="cfi-rows cfi-hero__list">' . ( $rows ? implode( '', $rows ) : '<li class="cfi-row"><span class="cfi-row__main"><b>' . esc_html( $empty ) . '</b></span></li>' ) . '</ul></section>';
	} else {
		$out .= '<section class="cfi-card">' . colorify_insights_state( __( 'Tu pojawią się zapytania z formularza, gdy ktoś napisze.', 'colorify-by-inyfinn' ), __( 'Jeszcze nic tu nie ma', 'colorify-by-inyfinn' ) );
		if ( colorify_insights_is_owner() ) {
			$out .= '<details class="cfi-tab"><summary>' . esc_html__( 'Dla administratora', 'colorify-by-inyfinn' ) . '</summary><p class="cfi-foot">' . esc_html__( 'Moduł czyta zgłoszenia z wtyczki rezerwacji albo z zapisanych wpisów Flamingo (Contact Form 7) lub MetForm. Można też dostarczyć dane filtrem colorify_insights_leads (title, url, date, source, answered).', 'colorify-by-inyfinn' ) . '</p></details>';
		}
		$out .= '</section>';
	}
	$side = '';
	if ( is_array( $funnel ) && ! empty( $funnel['steps'] ) ) {
		$first = max( 1, (int) $funnel['steps'][0][1] );
		$rows  = array();
		foreach ( $funnel['steps'] as $s ) {
			$rows[] = array( (string) $s[0], (int) $s[1], (int) $s[1] / $first );
		}
		$side .= colorify_insights_card( __( 'Od zapytania do zlecenia', 'colorify-by-inyfinn' ), colorify_insights_hbars( $rows ) );
	}
	if ( colorify_insights_available() ) {
		$side .= colorify_insights_sources_card( colorify_analytics_sources( colorify_insights_dim_range( $r )['from'], colorify_insights_dim_range( $r )['to'] ), colorify_insights_dim_range( $r )['note'] );
	}
	return $out . ( '' !== $side ? '<div class="cfi-grid2">' . $side . '</div>' : '' );
}

/* ---------- Redakcja: co rośnie, co spada, co się spóźniło ---------- */

function colorify_insights_mod_editorial(): string {
	$late = get_posts( array( 'post_type' => 'post', 'post_status' => 'future', 'numberposts' => 10, 'date_query' => array( array( 'before' => current_time( 'mysql' ) ) ) ) );
	$lr   = array_map( static fn( $p ) => colorify_insights_row( get_the_title( $p ) ?: __( '(bez tytułu)', 'colorify-by-inyfinn' ), (string) get_edit_post_link( $p->ID, 'raw' ), sprintf( /* translators: %s: data */ __( 'Miało wyjść %s', 'colorify-by-inyfinn' ), wp_date( 'j.m H:i', strtotime( $p->post_date ) ) ), __( 'opóźniony', 'colorify-by-inyfinn' ), 'is-hi' ), $late );
	$up   = array();
	$down = array();
	$top  = array();
	$note = '';
	$r    = colorify_insights_available() ? colorify_insights_range() : array();
	if ( $r ) {
		$pg = colorify_analytics_pages( $r['from'], $r['to'], array( 'per_page' => 500 ) );
		foreach ( (array) $pg['rows'] as $x ) {
			if ( 'post' !== $x['type'] ) {
				continue;
			}
			$top[] = colorify_insights_row( $x['title'], colorify_insights_detail_url( array( 'strona' => (string) $x['key'] ), $r ), '', colorify_insights_n( $x['views'] ) . ' ' . __( 'odsł.', 'colorify-by-inyfinn' ) );
			if ( ! empty( $pg['compare_allowed'] ) && null !== $x['change'] ) {
				if ( $x['change'] >= 0 ) {
					$up[] = $x;
				} else {
					$down[] = $x;
				}
			}
		}
		if ( empty( $pg['compare_allowed'] ) ) {
			$note = colorify_insights_reason( (string) ( $pg['compare_reason'] ?? '' ) );
		}
	}
	$mk  = static fn( array $xs ) => array_map( static fn( $x ) => colorify_insights_row( $x['title'], colorify_insights_detail_url( array( 'strona' => (string) $x['key'] ), colorify_insights_range() ), colorify_insights_n( $x['prev_views'] ) . ' → ' . colorify_insights_n( $x['views'] ), ( $x['change'] > 0 ? '+' : '' ) . colorify_insights_n( $x['change'] ), $x['change'] >= 0 ? 'is-up' : 'is-down' ), array_slice( $xs, 0, 5 ) );
	$out = '<div class="cfi-grid2">';
	if ( $up || $down ) {
		usort( $up, static fn( $a, $b ) => $b['change'] <=> $a['change'] );
		usort( $down, static fn( $a, $b ) => $a['change'] <=> $b['change'] );
		$out .= colorify_insights_rows_card( __( 'Rosnące wpisy', 'colorify-by-inyfinn' ), $mk( $up ), __( 'Żaden wpis nie rośnie.', 'colorify-by-inyfinn' ) ) . colorify_insights_rows_card( __( 'Spadające wpisy', 'colorify-by-inyfinn' ), $mk( $down ), __( 'Żaden wpis nie spada.', 'colorify-by-inyfinn' ) );
	} else {
		$out .= colorify_insights_rows_card( __( 'Najczęściej czytane wpisy', 'colorify-by-inyfinn' ), array_slice( $top, 0, 8 ), colorify_insights_since_text( null ) );
		$out .= colorify_insights_card( __( 'Rosnące i spadające', 'colorify-by-inyfinn' ), colorify_insights_state( '' !== $note ? $note : __( 'Pojawi się, gdy będzie z czym porównać.', 'colorify-by-inyfinn' ), __( 'Jeszcze nie porównujemy', 'colorify-by-inyfinn' ) ) );
	}
	return $out . '</div>' . colorify_insights_rows_card( __( 'Opóźnione publikacje', 'colorify-by-inyfinn' ), $lr, __( 'Nic się nie spóźniło.', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_status=future&post_type=post' ), __( 'Zaplanowane', 'colorify-by-inyfinn' ) );
}

/* ---------- Portfolio: kompletność zdjęć (alt, opis, użycie) ---------- */

function colorify_insights_mod_portfolio(): string {
	global $wpdb;
	$base  = "FROM {$wpdb->posts} p WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%'";
	$noalt = "AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_wp_attachment_image_alt' AND m.meta_value<>'')";
	$all   = (int) $wpdb->get_var( "SELECT COUNT(*) $base" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $all ) {
		return colorify_insights_card( __( 'Kompletność prac', 'colorify-by-inyfinn' ), colorify_insights_state( __( 'W bibliotece nie ma jeszcze zdjęć.', 'colorify-by-inyfinn' ) ) );
	}
	$n_alt = (int) $wpdb->get_var( "SELECT COUNT(*) $base $noalt" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$n_dsc = (int) $wpdb->get_var( "SELECT COUNT(*) $base AND p.post_content='' AND p.post_excerpt=''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$n_un  = (int) $wpdb->get_var( "SELECT COUNT(*) $base AND p.post_parent=0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows  = array(
		array( __( 'Z opisem zdjęcia (alt)', 'colorify-by-inyfinn' ), $all - $n_alt, ( $all - $n_alt ) / $all ),
		array( __( 'Z opisem albo podpisem', 'colorify-by-inyfinn' ), $all - $n_dsc, ( $all - $n_dsc ) / $all ),
		array( __( 'Dodane do wpisu lub strony', 'colorify-by-inyfinn' ), $all - $n_un, ( $all - $n_un ) / $all ),
	);
	$list  = array();
	foreach ( $wpdb->get_results( "SELECT p.ID, p.post_title $base $noalt ORDER BY p.post_date DESC LIMIT 8" ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$list[] = colorify_insights_row( $a->post_title ?: __( '(bez nazwy)', 'colorify-by-inyfinn' ), (string) get_edit_post_link( (int) $a->ID, 'raw' ), '', __( 'dodaj opis', 'colorify-by-inyfinn' ), 'is-mid' );
	}
	return '<section class="cfi-card cfi-strip cfi-strip--3">'
		. colorify_insights_stat( __( 'Zdjęć w bibliotece', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $all ) ), '', '', admin_url( 'upload.php' ) )
		. colorify_insights_stat( __( 'Bez opisu zdjęcia', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $n_alt ) ), '', __( 'Opis pomaga Google i osobom niewidzącym', 'colorify-by-inyfinn' ) )
		. colorify_insights_stat( __( 'Nigdzie nie dodane', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $n_un ) ), '', __( 'Mogą być zbędne', 'colorify-by-inyfinn' ) ) . '</section>'
		. '<div class="cfi-grid2">' . colorify_insights_card( __( 'Kompletność zdjęć', 'colorify-by-inyfinn' ), colorify_insights_hbars( $rows ) ) . colorify_insights_rows_card( __( 'Do uzupełnienia', 'colorify-by-inyfinn' ), $list, __( 'Wszystkie zdjęcia mają opis.', 'colorify-by-inyfinn' ), admin_url( 'upload.php?mode=list' ), __( 'Biblioteka', 'colorify-by-inyfinn' ) ) . '</div>';
}

/* ---------- Sprzedaż: wyjątki zamówień i braki (tylko z WooCommerce) ---------- */

function colorify_insights_mod_sales(): string {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_products' ) ) {
		return '';
	}
	$ex   = array( 'on-hold' => __( 'Wstrzymane', 'colorify-by-inyfinn' ), 'failed' => __( 'Nieudane płatności', 'colorify-by-inyfinn' ), 'pending' => __( 'Oczekują na płatność', 'colorify-by-inyfinn' ) );
	$stat = '';
	foreach ( $ex as $k => $lab ) {
		$n     = function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( $k ) : 0;
		$stat .= colorify_insights_stat( $lab, esc_html( (string) $n ), '', $n ? __( 'Do sprawdzenia', 'colorify-by-inyfinn' ) : __( 'Czysto', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_status=wc-' . $k . '&post_type=shop_order' ) );
	}
	$rows = array();
	foreach ( wc_get_products( array( 'limit' => 8, 'status' => 'publish', 'stock_status' => 'outofstock', 'orderby' => 'popularity', 'order' => 'DESC' ) ) as $p ) {
		$rows[] = colorify_insights_row( $p->get_name(), (string) get_edit_post_link( $p->get_id(), 'raw' ), sprintf( /* translators: %d: sprzedaż */ __( 'Sprzedano dotąd: %d', 'colorify-by-inyfinn' ), (int) $p->get_total_sales() ), __( 'brak na stanie', 'colorify-by-inyfinn' ), 'is-hi' );
	}
	return '<section class="cfi-card cfi-strip cfi-strip--3">' . $stat . '</section>' . colorify_insights_rows_card( __( 'Braki wśród bestsellerów', 'colorify-by-inyfinn' ), $rows, __( 'Żaden produkt nie jest wyprzedany.', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=product' ), __( 'Produkty', 'colorify-by-inyfinn' ) );
}

/* ---------- Cena i decyzje: zainteresowanie osobno, prawdziwy lejek dopiero od zgłoszenia ---------- */

/**
 * Dane z wtyczki rezerwacji (filtr colorify_insights_pricing): since, note, quotes, sent, booked, by_package, by_price, by_distance,
 * by_availability, budget, budget_total, budget_by_package, budget_actions, by_month, reasons, reasons_summary, price_versions.
 * Zwraca null, gdy wtyczka nic nie podaje (moduł się wtedy nie pokazuje).
 */
function colorify_insights_pricing_data( string $from, string $to ): ?array {
	$d = apply_filters( 'colorify_insights_pricing', null, $from, $to );
	return is_array( $d ) ? $d : null;
}

/** Próg wiarygodności: 0 = poniżej 30 (tylko liczby), 1 = 30-99 (powtarzający się sygnał), 2 = 100 i więcej. */
function colorify_insights_tier( int $n ): int {
	return $n >= 100 ? 2 : ( $n >= 30 ? 1 : 0 );
}

/** "1 wycena", "3 wyceny", "12 wycen": polska odmiana bez pliku tłumaczeń, liczba z twardą spacją. */
function colorify_insights_pl( int $n, string $one, string $few, string $many ): string {
	$f = 1 === $n ? $one : ( ( $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 12 || $n % 100 > 14 ) ) ? $few : $many );
	return colorify_insights_n( $n ) . "\u{00A0}" . $f;
}

/**
 * Tekst do wyświetlenia: jednoliterowe wyrazy (a, i, o, u, w, z) trzymają się następnego słowa twardą spacją, a liczba
 * kończąca zdanie poprzedniego wyrazu (żeby "1." nie spadło samo do nowego wiersza).
 */
function colorify_insights_nb( string $text ): string {
	return (string) preg_replace( array( '/(^| )([aiouwzAIOUWZ]) /u', '/ (\d+[.)]*)$/u' ), array( '$1$2' . "\u{00A0}", "\u{00A0}" . '$1' ), $text );
}

/**
 * Tabela liczb: kolumny [klucz => nagłówek], wiersze jako tablice z 'label'. Brak wartości to kreska, zero to zero.
 * Klucz kończący się na "_html" niesie gotową komórkę (po esc_*), np. dwie miary jedna pod drugą.
 */
function colorify_insights_table( string $first, array $cols, array $rows ): string {
	$b = '<div class="cfi-scroll"><table class="cfi-table"><thead><tr><th>' . esc_html( $first ) . '</th>';
	foreach ( $cols as $h ) {
		$b .= '<th class="is-num">' . esc_html( $h ) . '</th>';
	}
	$b .= '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$b .= '<tr><td>' . esc_html( (string) $r['label'] ) . '</td>';
		foreach ( array_keys( $cols ) as $k ) {
			$v  = $r[ $k ] ?? null;
			$b .= '<td class="is-num">' . ( null === $v ? '<span class="cfi-muted">—</span>' : ( '_html' === substr( (string) $k, -5 ) ? $v : esc_html( colorify_insights_n( $v ) ) ) ) . '</td>';
		}
		$b .= '</tr>';
	}
	return $b . '</tbody></table></div>';
}

/** Legenda: próbka koloru, nazwa i (gdy podano sumy) liczba. Serii bez danych nie wymienia. @param array<string,string> $series klucz => nazwa */
function colorify_insights_legend( array $series, ?array $totals = null ): string {
	$h = '';
	foreach ( $series as $k => $lab ) {
		if ( null !== $totals && (int) ( $totals[ $k ] ?? 0 ) <= 0 ) {
			continue;
		}
		$h .= '<li><i class="cfi-sw is-' . esc_attr( $k ) . '"></i><span>' . esc_html( $lab ) . '</span>' . ( null !== $totals ? ' <b>' . esc_html( colorify_insights_n( $totals[ $k ] ) ) . '</b>' : '' ) . '</li>';
	}
	return '' !== $h ? '<ul class="cfi-legend">' . $h . '</ul>' : '';
}

/**
 * Poziome słupki skumulowane na wspólnej osi liczb (sam HTML i CSS). Segment pokazuje swoją liczbę, gdy się w nim mieści;
 * pełną nazwę i liczbę ma w podpowiedzi. Koniec wiersza to suma albo własny podpis ('end', gotowy HTML).
 *
 * @param array<int,array<string,mixed>> $rows   [ 'label' => nazwa, 'v' => [ klucz serii => liczba ], 'end' => HTML ].
 * @param array<string,string>           $series Klucz serii => nazwa; kolejność = kolejność segmentów.
 * @param string                         $cap    Podpis nad kolumną z końcami wierszy.
 */
function colorify_insights_stacked( array $rows, array $series, string $cap = '' ): string {
	$max = 1;
	foreach ( $rows as $r ) {
		$max = max( $max, array_sum( array_intersect_key( (array) $r['v'], $series ) ) );
	}
	$h = '<ul class="cfi-stk">' . ( '' !== $cap ? '<li class="cfi-stk__row is-cap"><span></span><span></span><span class="cfi-stk__v">' . esc_html( $cap ) . '</span></li>' : '' );
	foreach ( $rows as $r ) {
		$sum = 0;
		$seg = '';
		foreach ( $series as $k => $lab ) {
			$n = (int) ( $r['v'][ $k ] ?? 0 );
			if ( $n > 0 ) {
				$sum += $n;
				$seg .= '<span class="cfi-stk__s is-' . esc_attr( $k ) . '" style="flex-grow:' . $n . '" data-cfi-tip="' . esc_attr( $r['label'] . ': ' . mb_strtolower( $lab ) . ' ' . colorify_insights_n( $n ) ) . '"><b>' . esc_html( colorify_insights_n( $n ) ) . '</b></span>';
			}
		}
		$h .= '<li class="cfi-stk__row"><span class="cfi-stk__l">' . esc_html( (string) $r['label'] ) . '</span>'
			. '<span class="cfi-stk__track"><span class="cfi-stk__bar" style="width:' . esc_attr( (string) round( $sum / $max * 100, 1 ) ) . '%">' . $seg . '</span></span>'
			. '<span class="cfi-stk__v">' . ( $r['end'] ?? esc_html( colorify_insights_n( $sum ) ) ) . '</span></li>';
	}
	return $h . '</ul>';
}

/**
 * Słupki zgrupowane: w wierszu po jednym cienkim słupku na serię, wspólna oś liczb, liczba za słupkiem; zero nie ma słupka.
 * Wiersze bez żadnej liczby wypadają z wykresu (są w tabeli) i są wymienione pod spodem jednym zdaniem.
 *
 * @param array<int,array<string,mixed>> $rows   Wiersze z 'label' i liczbami pod kluczami serii.
 * @param array<string,string>           $series Klucz serii => nazwa.
 */
function colorify_insights_grouped( array $rows, array $series ): string {
	$max  = 1;
	$live = array();
	$none = array();
	$used = array();
	foreach ( $rows as $r ) {
		$any = 0;
		foreach ( $series as $k => $lab ) {
			$n    = (int) ( $r[ $k ] ?? 0 );
			$max  = max( $max, $n );
			$any += $n;
			if ( $n > 0 ) {
				$used[ $k ] = 1;
			}
		}
		if ( $any > 0 ) {
			$live[] = $r;
		} else {
			$none[] = (string) $r['label'];
		}
	}
	if ( ! $live ) {
		return '';
	}
	$series = array_intersect_key( $series, $used ); // serii bez danych nie rysujemy i nie wymieniamy w legendzie
	$h      = colorify_insights_legend( $series ) . '<ul class="cfi-grp">';
	foreach ( $live as $r ) {
		$bars = '';
		foreach ( $series as $k => $lab ) {
			$n = (int) ( $r[ $k ] ?? 0 );
			if ( $n <= 0 ) {
				continue; // brak słupka = zero; pełne liczby są w tabeli
			}
			$bars .= '<span class="cfi-grp__line" data-cfi-tip="' . esc_attr( $r['label'] . ': ' . mb_strtolower( $lab ) . ' ' . colorify_insights_n( $n ) ) . '"><span class="cfi-grp__b is-' . esc_attr( $k ) . '" style="--w:' . esc_attr( (string) round( $n / $max, 4 ) ) . '"></span><span class="cfi-grp__n">' . esc_html( colorify_insights_n( $n ) ) . '</span></span>';
		}
		$h .= '<li class="cfi-grp__row"><span class="cfi-stk__l">' . esc_html( (string) $r['label'] ) . '</span><span class="cfi-grp__bars">' . $bars . '</span></li>';
	}
	$h .= '</ul>';
	if ( $none ) {
		/* translators: %s: lista nazw */
		$h .= '<p class="cfi-foot">' . esc_html( colorify_insights_nb( sprintf( __( 'Bez danych w tym okresie: %s.', 'colorify-by-inyfinn' ), implode( ', ', $none ) ) ) ) . '</p>';
	}
	return $h;
}

/**
 * Karta z dwoma widokami tych samych danych: wykres (domyślnie) i tabela pod przełącznikiem w nagłówku. Wykres jest
 * obrazem dla oka (aria-hidden), a tabela zostaje w drzewie dostępności także wtedy, gdy na ekranie widać wykres.
 */
function colorify_insights_dual_card( string $title, string $chart, string $table, string $foot = '', string $class = '' ): string {
	$toggle = '<div class="cfi-seg cfi-seg--s cfi-vt" role="group" aria-label="' . esc_attr__( 'Pokaż jako', 'colorify-by-inyfinn' ) . '">'
		. '<button type="button" data-cfi-view="chart" aria-pressed="true">' . esc_html__( 'Wykres', 'colorify-by-inyfinn' ) . '</button>'
		. '<button type="button" data-cfi-view="table" aria-pressed="false">' . esc_html__( 'Tabela', 'colorify-by-inyfinn' ) . '</button></div>';
	$body   = '<div class="cfi-pane" data-cfi-pane="chart" aria-hidden="true">' . $chart . '</div>'
		. '<div class="cfi-pane screen-reader-text" data-cfi-pane="table">' . $table . '</div>'
		. ( '' !== $foot ? '<p class="cfi-foot">' . esc_html( colorify_insights_nb( $foot ) ) . '</p>' : '' );
	return colorify_insights_card( $title, $body, trim( 'cfi-dual ' . $class ), $toggle );
}

/** Odpowiedzi o budżecie: klucz => nazwa, w stałej kolejności od "mieści się" do "nie mamy budżetu" (od niej zależą kolory). */
function colorify_insights_budget_series( array $d ): array {
	$lab = array();
	foreach ( (array) ( $d['budget'] ?? array() ) as $b ) {
		$lab[ (string) $b['key'] ] = (string) $b['label'];
	}
	return array(
		'miesci'       => $lab['miesci'] ?? __( 'Mieści się', 'colorify-by-inyfinn' ),
		'troche'       => $lab['troche'] ?? __( 'Trochę przekracza', 'colorify-by-inyfinn' ),
		'zdecydowanie' => $lab['zdecydowanie'] ?? __( 'Zdecydowanie przekracza', 'colorify-by-inyfinn' ),
		'brak_budzetu' => $lab['brak_budzetu'] ?? __( 'Jeszcze nie mamy budżetu', 'colorify-by-inyfinn' ),
	);
}

/**
 * Pakiety z odpowiedziami o budżecie, od tego, przy którym najczęściej pada "przekracza".
 *
 * @return array<int,array{id:string,label:string,v:array<string,int>,sum:int,over:int}>
 */
function colorify_insights_budget_rows( array $d ): array {
	$keys = array( 'miesci' => 0, 'troche' => 0, 'zdecydowanie' => 0, 'brak_budzetu' => 0 );
	$rows = array();
	foreach ( (array) ( $d['budget_by_package'] ?? array() ) as $p ) {
		$v   = array_map( 'intval', array_intersect_key( (array) $p, $keys ) ) + $keys;
		$sum = array_sum( $v );
		if ( $sum > 0 ) {
			$rows[] = array( 'id' => (string) ( $p['id'] ?? '' ), 'label' => (string) ( $p['label'] ?? '' ), 'v' => $v, 'sum' => $sum, 'over' => $v['troche'] + $v['zdecydowanie'] );
		}
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			return array( $b['over'], $b['sum'] ) <=> array( $a['over'], $a['sum'] );
		}
	);
	return $rows;
}

/**
 * Jedno zdanie "co z tego wynika" i dopowiedzenie. Poniżej 30 odpowiedzi nie wyciąga wniosków, a przy większej próbie
 * mówi tylko to, co odpowiedziały pary (nigdy, że cena jest za wysoka).
 *
 * @return array{0:string,1:string} zdanie główne, dopowiedzenie
 */
function colorify_insights_pricing_headline( array $d ): array {
	$quotes = (int) ( $d['quotes'] ?? 0 );
	$total  = (int) ( $d['budget_total'] ?? 0 );
	$sent   = (int) ( $d['sent'] ?? 0 );
	$booked = (int) ( $d['booked'] ?? 0 );
	$since  = (string) ( $d['since'] ?? '' );
	$from   = '' !== $since ? colorify_insights_date( $since, 'j F' ) : '';
	/* translators: 1: liczba zgłoszeń, 2: liczba umów */
	$deals  = $sent > 0 ? sprintf( __( 'Zgłoszenia już widać: %1$s (umowa lub zadatek: %2$s).', 'colorify-by-inyfinn' ), colorify_insights_n( $sent ), colorify_insights_n( $booked ) ) : '';
	if ( colorify_insights_tier( $total ) < 1 ) {
		if ( $quotes <= 0 && $total <= 0 ) {
			/* translators: %s: data, np. 13 listopada */
			$main = '' !== $from ? sprintf( __( 'Jeszcze za mało danych: wyceny i odpowiedzi o budżecie zbieramy od %s. Pierwsze wnioski po ok. 30 wycenach.', 'colorify-by-inyfinn' ), $from ) : __( 'Jeszcze za mało danych. Pierwsze wnioski po ok. 30 wycenach.', 'colorify-by-inyfinn' );
			return array( $main, $deals );
		}
		if ( colorify_insights_tier( $quotes ) < 1 ) {
			/* translators: 1: wyceny, 2: odpowiedzi */
			return array( sprintf( __( 'Na razie %1$s i %2$s o budżecie: za mało na wnioski. Pierwsze po ok. 30 wycenach.', 'colorify-by-inyfinn' ), colorify_insights_pl( $quotes, 'wycena', 'wyceny', 'wycen' ), colorify_insights_pl( $total, 'odpowiedź', 'odpowiedzi', 'odpowiedzi' ) ), '' !== $from ? sprintf( /* translators: %s: data */ __( 'Zbieramy od %s.', 'colorify-by-inyfinn' ), $from ) : '' );
		}
		/* translators: 1: wyceny, 2: odpowiedzi */
		return array( sprintf( __( 'Jest już %1$s, ale tylko %2$s o budżecie. O cenie powiemy więcej po ok. 30 odpowiedziach.', 'colorify-by-inyfinn' ), colorify_insights_pl( $quotes, 'wycena', 'wyceny', 'wycen' ), colorify_insights_pl( $total, 'odpowiedź', 'odpowiedzi', 'odpowiedzi' ) ), '' );
	}
	$by = array( 'miesci' => 0, 'troche' => 0, 'zdecydowanie' => 0, 'brak_budzetu' => 0 );
	foreach ( (array) ( $d['budget'] ?? array() ) as $b ) {
		$by[ (string) $b['key'] ] = (int) $b['count'];
	}
	$over = $by['troche'] + $by['zdecydowanie'];
	$rows = colorify_insights_budget_rows( $d );
	$top  = $rows && $rows[0]['over'] > 0 && $rows[0]['sum'] >= 5 ? $rows[0] : null;
	$soft = 1 === colorify_insights_tier( $total ) ? __( 'To jeszcze mała próba, traktuj orientacyjnie.', 'colorify-by-inyfinn' ) : '';
	if ( $over / $total >= 0.4 ) {
		/* translators: 1: liczba odpowiedzi "przekracza", 2: wszystkich odpowiedzi */
		$main = sprintf( __( 'W %1$d z %2$d odpowiedzi pary piszą, że cena przekracza ich budżet.', 'colorify-by-inyfinn' ), $over, $total );
		if ( $top ) {
			/* translators: 1: nazwa pakietu, 2: liczba, 3: wszystkich przy tym pakiecie */
			$main .= ' ' . sprintf( __( 'Najczęściej przy pakiecie „%1$s” (%2$d z %3$d).', 'colorify-by-inyfinn' ), $top['label'], $top['over'], $top['sum'] );
		}
		return array( $main, $soft );
	}
	/* translators: 1: liczba odpowiedzi "mieści się", 2: wszystkich odpowiedzi */
	$main = sprintf( __( 'W %1$d z %2$d odpowiedzi pary piszą, że cena mieści się w ich budżecie.', 'colorify-by-inyfinn' ), $by['miesci'], $total );
	if ( $top && $top['over'] / $top['sum'] >= 0.5 ) {
		/* translators: 1: nazwa pakietu, 2: liczba, 3: wszystkich przy tym pakiecie */
		$main .= ' ' . sprintf( __( 'Wyjątek: przy pakiecie „%1$s” przekracza w %2$d z %3$d.', 'colorify-by-inyfinn' ), $top['label'], $top['over'], $top['sum'] );
	}
	return array( $main, $soft );
}

/** Główna karta: odpowiedzi o budżecie według pakietu (słupki skumulowane), z legendą niosącą sumy i tabelą pod przełącznikiem. */
function colorify_insights_pricing_budget_card( array $d ): string {
	$series = colorify_insights_budget_series( $d );
	$totals = array();
	foreach ( (array) ( $d['budget'] ?? array() ) as $b ) {
		$totals[ (string) $b['key'] ] = (int) $b['count'];
	}
	$rows = colorify_insights_budget_rows( $d );
	$have = array();
	foreach ( $rows as $i => $r ) {
		$have[ $r['id'] ] = 1;
		$rows[ $i ]['end'] = '<b>' . esc_html( colorify_insights_n( $r['over'] ) ) . '</b> <small>' . esc_html__( 'z', 'colorify-by-inyfinn' ) . "\u{00A0}" . esc_html( colorify_insights_n( $r['sum'] ) ) . '</small>';
	}
	$none = array();
	$tab  = array();
	foreach ( $rows as $r ) {
		$tab[] = array( 'label' => $r['label'] ) + $r['v'] + array( 'sum' => $r['sum'] );
	}
	foreach ( (array) ( $d['by_package'] ?? array() ) as $p ) {
		if ( ! isset( $have[ (string) ( $p['id'] ?? '' ) ] ) ) {
			$none[] = (string) $p['label'];
			$tab[]  = array( 'label' => (string) $p['label'], 'miesci' => 0, 'troche' => 0, 'zdecydowanie' => 0, 'brak_budzetu' => 0, 'sum' => 0 );
		}
	}
	$chart = colorify_insights_legend( $series, $totals ) . colorify_insights_stacked( $rows, $series, __( 'ponad budżet', 'colorify-by-inyfinn' ) );
	if ( $none ) {
		/* translators: %s: lista nazw pakietów */
		$chart .= '<p class="cfi-foot">' . esc_html( sprintf( __( 'Pozostałe pakiety: brak odpowiedzi (%s).', 'colorify-by-inyfinn' ), implode( ', ', $none ) ) ) . '</p>';
	}
	$acts = array();
	foreach ( (array) ( $d['budget_actions'] ?? array() ) as $a ) {
		if ( (int) ( $a['count'] ?? 0 ) > 0 ) {
			$acts[] = colorify_insights_pl( (int) $a['count'], 'raz', 'razy', 'razy' ) . ' ' . mb_strtolower( mb_substr( (string) $a['label'], 0, 1 ) ) . mb_substr( (string) $a['label'], 1 );
		}
	}
	/* translators: %s: lista działań z liczbami */
	$foot = ( $acts ? sprintf( __( 'Po odpowiedzi „przekracza”: %s (kliknięcia, nie osoby).', 'colorify-by-inyfinn' ), implode( ', ', $acts ) ) . ' ' : '' )
		. __( 'Odpowiedzi są anonimowe: wiemy, przy którym pakiecie padły, nie wiemy, kto ich udzielił.', 'colorify-by-inyfinn' );
	$table = colorify_insights_table( __( 'Pakiet', 'colorify-by-inyfinn' ), array_merge( $series, array( 'brak_budzetu' => __( 'Bez budżetu', 'colorify-by-inyfinn' ), 'sum' => __( 'Razem', 'colorify-by-inyfinn' ) ) ), $tab ); // krótszy nagłówek w tabeli, kolejność kolumn jak w legendzie
	return colorify_insights_dual_card( __( 'Odpowiedzi o budżecie według pakietu', 'colorify-by-inyfinn' ), $chart, $table, $foot, 'cfi-budget' );
}

/**
 * Karta w miejscu głównego wykresu, dopóki nie ma odpowiedzi o budżecie: szkic wykresu (oznaczony jako przykład, bez liczb)
 * i zdanie, czego się spodziewać. Szkic używa prawdziwych nazw odpowiedzi i ich kolorów, żeby legenda była już znajoma.
 */
function colorify_insights_pricing_preview_card( array $d ): string {
	$series = colorify_insights_budget_series( $d );
	$shape  = array( array( 5, 3, 1, 1 ), array( 3, 4, 3, 0 ), array( 4, 1, 0, 2 ) ); // kształt przykładu, nie dane
	$rows   = '';
	foreach ( $shape as $i => $s ) {
		$seg = '';
		foreach ( array_keys( $series ) as $j => $k ) {
			$seg .= $s[ $j ] > 0 ? '<span class="cfi-stk__s is-' . esc_attr( $k ) . '" style="flex-grow:' . (int) $s[ $j ] . '"></span>' : '';
		}
		/* translators: %s: litera */
		$rows .= '<li class="cfi-stk__row"><span class="cfi-stk__l">' . esc_html( sprintf( __( 'Pakiet %s', 'colorify-by-inyfinn' ), chr( 65 + $i ) ) ) . '</span><span class="cfi-stk__track"><span class="cfi-stk__bar" style="width:' . (int) ( array_sum( $s ) * 9 ) . '%">' . $seg . '</span></span><span class="cfi-stk__v"></span></li>';
	}
	$body = '<div class="cfi-preview" aria-hidden="true"><span class="cfi-preview__tag">' . esc_html__( 'Przykład', 'colorify-by-inyfinn' ) . '</span>' . colorify_insights_legend( $series ) . '<ul class="cfi-stk">' . $rows . '</ul></div>'
		. '<p class="cfi-p">' . esc_html( colorify_insights_nb( __( 'Gdy pary zaczną odpowiadać w kalkulatorze na pytanie o budżet, zobaczysz tu, przy którym pakiecie cena mieści się w budżecie, a przy którym go przekracza.', 'colorify-by-inyfinn' ) ) ) . '</p>'
		. '<p class="cfi-foot">' . esc_html( colorify_insights_nb( __( 'Zobaczysz też: w jakich przedziałach ceny i z jakiej odległości pary liczą wycenę, ile wycen kończy się zgłoszeniem i dlaczego pary rezygnują.', 'colorify-by-inyfinn' ) ) ) . '</p>';
	return colorify_insights_card( __( 'Co tu zobaczysz', 'colorify-by-inyfinn' ), $body, 'cfi-budget' );
}

/**
 * Karta przekroju (pakiet, cena, odległość, termin): słupki zgrupowane i ta sama treść jako tabela. Pusty tekst, gdy
 * w przekroju nie ma ani jednej liczby (tabeli samych zer nie pokazujemy).
 *
 * @param array<string,string> $series Klucz => nazwa serii na wykresie.
 * @param array<string,string> $cols   Klucz => nagłówek kolumny tabeli.
 */
function colorify_insights_pricing_cross( string $title, string $first, array $rows, array $series, array $cols, string $foot = '' ): string {
	$chart = colorify_insights_grouped( $rows, $series );
	return '' === $chart ? '' : colorify_insights_dual_card( $title, $chart, colorify_insights_table( $first, $cols, $rows ), $foot );
}

/** Rezygnacje: znane powody (co para napisała, a co jest przypuszczeniem), osobno liczba "powód nieznany"; bez danych jedno zdanie. */
function colorify_insights_pricing_reasons_card( array $d ): string {
	$sum     = (array) ( $d['reasons_summary'] ?? array() );
	$total   = (int) ( $sum['total'] ?? 0 );
	$unknown = (int) ( $sum['unknown'] ?? 0 );
	$title   = __( 'Rezygnacje', 'colorify-by-inyfinn' );
	$who     = __( 'Nie wiemy, kto zrezygnował przed wysłaniem zgłoszenia: do tego momentu para nie zostawia danych.', 'colorify-by-inyfinn' );
	if ( $total <= 0 ) {
		return colorify_insights_card( $title, '<p class="cfi-p">' . esc_html( colorify_insights_nb( __( 'W tym okresie żadne zlecenie nie skończyło się rezygnacją.', 'colorify-by-inyfinn' ) ) ) . '</p><p class="cfi-foot">' . esc_html( colorify_insights_nb( $who ) ) . '</p>' );
	}
	$series = array( 'stated' => __( 'Para napisała', 'colorify-by-inyfinn' ), 'guess' => __( 'Nasze przypuszczenie', 'colorify-by-inyfinn' ) );
	$rows   = array();
	$tab    = array();
	$tot    = array( 'stated' => 0, 'guess' => 0 );
	foreach ( (array) ( $d['reasons'] ?? array() ) as $x ) {
		if ( 'nieznany' === (string) ( $x['key'] ?? '' ) || (int) ( $x['count'] ?? 0 ) <= 0 ) {
			continue;
		}
		$v             = array( 'stated' => (int) ( $x['stated'] ?? 0 ), 'guess' => (int) ( $x['guess'] ?? 0 ) );
		$rows[]        = array( 'label' => (string) $x['label'], 'v' => $v, 'n' => array_sum( $v ) );
		$tab[]         = array( 'label' => (string) $x['label'] ) + $v + array( 'count' => (int) $x['count'] );
		$tot['stated'] += $v['stated'];
		$tot['guess']  += $v['guess'];
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			return $b['n'] <=> $a['n'];
		}
	);
	/* translators: %d: liczba rezygnacji z nieznanym powodem */
	$lead = '<p class="cfi-lead"><b>' . esc_html( colorify_insights_pl( $total, 'rezygnacja', 'rezygnacje', 'rezygnacji' ) ) . '</b> ' . esc_html( colorify_insights_nb( __( 'w tym okresie.', 'colorify-by-inyfinn' ) . ' ' . ( $unknown > 0 ? sprintf( __( 'Powód nieznany: %d.', 'colorify-by-inyfinn' ), $unknown ) : __( 'Przy każdej znamy powód.', 'colorify-by-inyfinn' ) ) ) ) . '</p>';
	if ( ! $rows ) {
		return colorify_insights_card( $title, $lead . '<p class="cfi-foot">' . esc_html( colorify_insights_nb( $who ) ) . '</p>' );
	}
	if ( $unknown > 0 ) {
		$tab[] = array( 'label' => __( 'Powód nieznany', 'colorify-by-inyfinn' ), 'stated' => null, 'guess' => null, 'count' => $unknown );
	}
	$table = colorify_insights_table( __( 'Powód', 'colorify-by-inyfinn' ), $series + array( 'count' => __( 'Razem', 'colorify-by-inyfinn' ) ), $tab );
	return colorify_insights_dual_card( $title, $lead . colorify_insights_legend( $series, $tot ) . colorify_insights_stacked( $rows, $series ), $table, $who );
}

/**
 * Moduł "Cena i decyzje". W widoku kokpitu ma odnośnik "Zobacz szczegóły"; na podstronie szczegółów
 * (admin.php?page=colorify-ruch&modul=pricing) ma wybór zakresu dat jak panel ruchu i dodatkowe karty.
 */
function colorify_insights_mod_pricing(): string {
	$r = colorify_insights_available() ? colorify_insights_range() : array( 'from' => wp_date( 'Y-m-d', time() - 89 * DAY_IN_SECONDS ), 'to' => wp_date( 'Y-m-d' ), 'preset' => 90 );
	$d = colorify_insights_pricing_data( $r['from'], $r['to'] );
	if ( ! $d ) {
		return '';
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko rozpoznanie ekranu.
	$full   = isset( $_GET['page'], $_GET['modul'] ) && COLORIFY_INSIGHTS_PAGE === $_GET['page'];
	$quotes = (int) ( $d['quotes'] ?? 0 );
	$sent   = (int) ( $d['sent'] ?? 0 );
	$btotal = (int) ( $d['budget_total'] ?? 0 );
	$rtotal = (int) ( $d['reasons_summary']['total'] ?? 0 );
	$since  = (string) ( $d['since'] ?? '' );

	// Nagłówek: tytuł i okres; w kokpicie odnośnik do szczegółów, na podstronie wybór zakresu.
	/* translators: %s: data, np. 13 listopada */
	$period = colorify_insights_date( $r['from'], 'j.m.Y' ) . ' – ' . colorify_insights_date( $r['to'], 'j.m.Y' ) . ( '' !== $since && $since > $r['from'] && ( $quotes > 0 || $btotal > 0 ) ? ' · ' . sprintf( __( 'wyceny liczymy od %s', 'colorify-by-inyfinn' ), colorify_insights_date( $since, 'j F' ) ) : '' );
	$side   = $full
		? ( colorify_insights_available() ? colorify_insights_range_bar( $r ) : '' )
		: '<a class="cfi-btn cfi-btn--ghost" href="' . esc_url( colorify_insights_detail_url( array( 'modul' => 'pricing' ), $r + array( 'preset' => 0 ) ) ) . '">' . esc_html__( 'Zobacz szczegóły', 'colorify-by-inyfinn' ) . '</a>';
	$out    = ( $full ? colorify_insights_back( admin_url( 'index.php' ), __( 'Kokpit', 'colorify-by-inyfinn' ) ) : '' )
		. '<div class="cfi-head"><div><h2 class="cfi-h2">' . esc_html__( 'Cena i decyzje', 'colorify-by-inyfinn' ) . '</h2><p class="cfi-sub">' . esc_html( $period ) . '</p></div>' . $side . '</div>';

	// 1. Jedno zdanie "co z tego wynika".
	$head = colorify_insights_pricing_headline( $d );
	$out .= '<p class="cfi-verdict">' . esc_html( colorify_insights_nb( $head[0] ) ) . ( '' !== $head[1] ? '<small>' . esc_html( colorify_insights_nb( $head[1] ) ) . '</small>' : '' ) . '</p>';

	// 2. Cztery liczby: zainteresowanie (wyceny, odpowiedzi), sprawy (zgłoszenia) i rezygnacje. Bez procentów między nimi.
	$views = null;
	$pages = (array) apply_filters( 'colorify_insights_pricing_pages', array() );
	if ( $pages && function_exists( 'colorify_analytics_page_report' ) ) {
		$views = 0;
		foreach ( $pages as $p ) {
			$views += (int) ( colorify_analytics_page_report( (string) $p, $r['from'], $r['to'] )['views'] ?? 0 );
		}
	}
	/* translators: %s: liczba odsłon z odmianą, np. 12 odsłon */
	$qhint = __( 'Obliczenia w kalkulatorze, nie osoby.', 'colorify-by-inyfinn' ) . ( null !== $views ? ' ' . sprintf( __( 'Strona z ofertą: %s.', 'colorify-by-inyfinn' ), colorify_insights_pl( $views, 'odsłona', 'odsłony', 'odsłon' ) ) : '' );
	$out  .= '<section class="cfi-card cfi-strip" aria-label="' . esc_attr__( 'Najważniejsze liczby', 'colorify-by-inyfinn' ) . '">'
		. colorify_insights_stat( __( 'Wyceny', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $quotes ) ), '', $qhint )
		. colorify_insights_stat( __( 'Odpowiedzi o budżecie', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $btotal ) ), '', __( 'Po obliczeniu wyceny', 'colorify-by-inyfinn' ) )
		. colorify_insights_stat( __( 'Zgłoszenia', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $sent ) ), '', __( 'Wysłane formularze', 'colorify-by-inyfinn' ) )
		. colorify_insights_stat( __( 'Rezygnacje', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $rtotal ) ), '', __( 'Zlecenia zakończone rezygnacją', 'colorify-by-inyfinn' ) )
		. '</section>';

	// 3. Główny wykres (albo zapowiedź, dopóki nie ma odpowiedzi). Dalej karty idą parami: pakiet obok budżetu,
	//    rezygnacje obok lejka, cena obok odległości.
	$cards = $btotal > 0 ? colorify_insights_pricing_budget_card( $d ) : colorify_insights_pricing_preview_card( $d );

	// 4. Przekroje: wykres albo tabela. Wyceny to obliczenia, zgłoszenia i umowy to sprawy, więc stoją obok siebie jako liczby.
	$s3   = array( 'quotes' => __( 'Wyceny', 'colorify-by-inyfinn' ), 'sent' => __( 'Zgłoszenia', 'colorify-by-inyfinn' ), 'booked' => __( 'Umowa lub zadatek', 'colorify-by-inyfinn' ) );
	$c3   = array( 'quotes' => __( 'Wyceny', 'colorify-by-inyfinn' ), 'sent' => __( 'Zgłoszenia', 'colorify-by-inyfinn' ), 'booked' => __( 'Umowa', 'colorify-by-inyfinn' ) );
	$pack = array();
	foreach ( (array) ( $d['by_package'] ?? array() ) as $p ) {
		$has = null !== ( $p['avg_service'] ?? null );
		$p['avg_html'] = $has ? esc_html( colorify_insights_n( $p['avg_service'] ) ) . "\u{00A0}zł<span class=\"cfi-sub2\">" . esc_html( sprintf( /* translators: %s: kwota */ __( 'dojazd %s zł', 'colorify-by-inyfinn' ), colorify_insights_n( (float) ( $p['avg_travel'] ?? 0 ) ) ) ) . '</span>' : null;
		$pack[]        = $p;
	}
	$cards .= colorify_insights_pricing_cross( __( 'Według pakietu', 'colorify-by-inyfinn' ), __( 'Pakiet', 'colorify-by-inyfinn' ), $pack, $s3, array( 'quotes' => $c3['quotes'], 'avg_html' => __( 'Śr. cena', 'colorify-by-inyfinn' ), 'sent' => $c3['sent'], 'booked' => $c3['booked'] ), $quotes > 0 ? trim( (string) ( $d['note'] ?? '' ) . ' ' . __( 'W tabeli koszt usługi i dojazdu stoją osobno, żeby było widać, co składa się na cenę.', 'colorify-by-inyfinn' ) ) : '' );

	// 5. Prawdziwy lejek: dopiero od wysłanego zgłoszenia (liczone po sprawie), a obok rezygnacje.
	$mid    = '';
	$funnel = apply_filters( 'colorify_insights_funnel', null, $r['from'], $r['to'] );
	$steps  = is_array( $funnel ) && ! empty( $funnel['steps'] ) ? $funnel['steps'] : array( array( __( 'Zgłoszenia', 'colorify-by-inyfinn' ), $sent ), array( __( 'Umowa lub zadatek', 'colorify-by-inyfinn' ), (int) ( $d['booked'] ?? 0 ) ) );
	$first  = (int) $steps[0][1];
	if ( $first > 0 ) {
		$rows = array();
		foreach ( $steps as $s ) {
			$rows[] = array( (string) $s[0], (int) $s[1], (int) $s[1] / $first );
		}
		$tier   = colorify_insights_tier( $first );
		$mid   .= colorify_insights_card( __( 'Od zgłoszenia do umowy', 'colorify-by-inyfinn' ), colorify_insights_hbars( $rows, $tier > 0 ) . '<p class="cfi-foot">' . esc_html( colorify_insights_nb( 0 === $tier ? __( 'Poniżej 30 zgłoszeń pokazujemy same liczby, bez procentów.', 'colorify-by-inyfinn' ) : __( 'Procent to część wszystkich zgłoszeń z tego okresu.', 'colorify-by-inyfinn' ) ) ) . '</p>' );
	}

	$cards .= $mid . colorify_insights_pricing_reasons_card( $d );

	$cards .= colorify_insights_pricing_cross( __( 'Według ceny', 'colorify-by-inyfinn' ), __( 'Cena', 'colorify-by-inyfinn' ), (array) ( $d['by_price'] ?? array() ), $s3, $c3 )
		. colorify_insights_pricing_cross( __( 'Według odległości', 'colorify-by-inyfinn' ), __( 'Dojazd', 'colorify-by-inyfinn' ), (array) ( $d['by_distance'] ?? array() ), $s3, $c3 )
		. colorify_insights_pricing_cross( __( 'Termin w wycenie', 'colorify-by-inyfinn' ), __( 'Termin', 'colorify-by-inyfinn' ), (array) ( $d['by_availability'] ?? array() ), array( 'quotes' => $s3['quotes'] ), array( 'quotes' => $c3['quotes'] ), __( 'Wycena na zajęty termin nic nie mówi o tym, czy cena odstrasza, więc nie wliczamy jej do oceny ceny.', 'colorify-by-inyfinn' ) );

	// 6. Tylko na podstronie szczegółów: zgłoszenia według miesiąca i zmiany cennika.
	if ( $full ) {
		$mon = array();
		foreach ( array_slice( (array) ( $d['by_month'] ?? array() ), -12 ) as $m ) {
			$mon[] = array( 'label' => colorify_insights_date( $m['month'] . '-01', 'F Y' ), 'sent' => (int) $m['sent'], 'booked' => (int) $m['booked'] );
		}
		$cards .= colorify_insights_pricing_cross( __( 'Zgłoszenia według miesiąca', 'colorify-by-inyfinn' ), __( 'Miesiąc zgłoszenia', 'colorify-by-inyfinn' ), $mon, array( 'sent' => $s3['sent'], 'booked' => $s3['booked'] ), array( 'sent' => $c3['sent'], 'booked' => $s3['booked'] ), __( 'Umowę przypisujemy do miesiąca, w którym przyszło zgłoszenie.', 'colorify-by-inyfinn' ) );
		$ver    = array_values( (array) ( $d['price_versions'] ?? array() ) );
		if ( count( $ver ) > 1 ) {
			$vr = array();
			foreach ( $ver as $i => $v ) {
				/* translators: %d: numer wersji */
				$vr[] = array( 'label' => sprintf( __( 'Cennik, wersja %d', 'colorify-by-inyfinn' ), $i + 1 ) . ( $i === count( $ver ) - 1 ? ' ' . __( '(obecna)', 'colorify-by-inyfinn' ) : '' ), 'quotes' => (int) $v['quotes'], 'od_html' => esc_html( ! empty( $v['from'] ) ? colorify_insights_date( (string) $v['from'], 'j.m.Y' ) : '—' ), 'do_html' => esc_html( ! empty( $v['to'] ) ? colorify_insights_date( (string) $v['to'], 'j.m.Y' ) : '—' ) );
			}
			$cards .= colorify_insights_card( __( 'Cennik zmienił się w tym okresie', 'colorify-by-inyfinn' ), colorify_insights_table( __( 'Wersja', 'colorify-by-inyfinn' ), array( 'quotes' => $c3['quotes'], 'od_html' => __( 'Od', 'colorify-by-inyfinn' ), 'do_html' => __( 'Do', 'colorify-by-inyfinn' ) ), $vr ) . '<p class="cfi-foot">' . esc_html( colorify_insights_nb( __( 'Wyceny z różnych wersji cennika porównuj osobno: ceny nie były te same.', 'colorify-by-inyfinn' ) ) ) . '</p>' );
		}
	}
	return $out . '<div class="cfi-g2">' . $cards . '</div>';
}

/** Ten sam moduł zwinięty (widok Kalendarz zleceń): nagłówek "Cena i decyzje" rozwija całość. */
function colorify_insights_mod_pricing_fold(): string {
	$m = colorify_insights_module( 'pricing' );
	return '' === $m ? '' : '<details class="cfd-fold" ontoggle="window.dispatchEvent(new Event(&quot;resize&quot;))"><summary class="cfd-fold__s"><span>' . esc_html__( 'Cena i decyzje', 'colorify-by-inyfinn' ) . '</span><svg class="cfd-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></summary>' . $m . '</details>';
}
