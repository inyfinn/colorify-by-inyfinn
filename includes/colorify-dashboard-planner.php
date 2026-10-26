<?php
/**
 * Trzy dodatkowe style kokpitu: Kalendarz zleceń, Rezerwacje, Portfolio.
 *
 * Kalendarz i Rezerwacje czytają dane przez dwa filtry, więc Colorify nie zna żadnej konkretnej wtyczki:
 *
 *   apply_filters( 'colorify_calendar_events', array(), $from_ymd, $to_ymd )
 *       lista [ 'date' => 'Y-m-d', 'title' => string, 'url' => string, 'type' => 'wesele'|'kontakt'|'oddanie'|'blokada'|string,
 *               'status' => string, 'amount' => int|null, 'meta' => string ]
 *   apply_filters( 'colorify_dashboard_kpis', array() )
 *       lista [ 'label' => string, 'value' => string, 'hint' => string, 'url' => string ]
 *
 * Bez danych kokpit pokazuje pusty kalendarz z podpowiedzią, nigdy błąd. Portfolio korzysta wyłącznie z mediów
 * i stron WordPressa. Trait należy do Colorify_Dashboard i używa jej pomocników (stat, list_card, promo...).
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

trait Colorify_Dashboard_Planner {

	/* ===================== Dane z filtrów ===================== */

	/** Czy jakakolwiek wtyczka podaje terminarz albo liczby kokpitowi. */
	private static function plan_has_provider(): bool {
		return (bool) ( has_filter( 'colorify_calendar_events' ) || has_filter( 'colorify_dashboard_kpis' ) );
	}

	/**
	 * Zlecenia z filtrów jako źródło "zapytań" dla stylu Zapytania; null, gdy nic ich nie podaje.
	 *
	 * @return array{count:int,next:?array,url:string}|null
	 */
	private static function plan_leads_source(): ?array {
		if ( ! self::plan_has_provider() ) {
			return null;
		}
		$ev   = array_values(
			array_filter(
				self::plan_events( wp_date( 'Y-m-d' ), wp_date( 'Y-m-d', time() + 180 * DAY_IN_SECONDS ) ),
				static function ( $e ) {
					return 'blokada' !== $e['type'] && 'anulowane' !== $e['status'];
				}
			)
		);
		$kpis = self::plan_kpis();
		$url  = $kpis && '' !== $kpis[0]['url'] ? $kpis[0]['url'] : ( $ev && '' !== $ev[0]['url'] ? $ev[0]['url'] : admin_url( 'index.php' ) );
		return array( 'count' => count( $ev ), 'next' => $ev ? $ev[0] : null, 'url' => $url );
	}

	/**
	 * Wydarzenia z filtra, tylko poprawne (data, tytuł), posortowane po dacie.
	 *
	 * @return array<int,array{date:string,title:string,url:string,type:string,status:string,amount:?int,meta:string}>
	 */
	private static function plan_events( string $from, string $to ): array {
		$raw = apply_filters( 'colorify_calendar_events', array(), $from, $to );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $e ) {
			if ( ! is_array( $e ) || empty( $e['date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $e['date'] ) ) {
				continue;
			}
			$date = (string) $e['date'];
			if ( $date < $from || $date > $to ) {
				continue;
			}
			$title = trim( wp_strip_all_tags( (string) ( $e['title'] ?? '' ) ) );
			$out[] = array(
				'date'   => $date,
				'title'  => '' !== $title ? $title : __( '(bez tytułu)', 'colorify-by-inyfinn' ),
				'url'    => isset( $e['url'] ) ? esc_url_raw( (string) $e['url'] ) : '',
				'type'   => sanitize_key( (string) ( $e['type'] ?? '' ) ),
				'status' => sanitize_key( (string) ( $e['status'] ?? '' ) ),
				'amount' => isset( $e['amount'] ) && is_numeric( $e['amount'] ) ? (int) $e['amount'] : null,
				'meta'   => trim( wp_strip_all_tags( (string) ( $e['meta'] ?? '' ) ) ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['date'], $a['title'] ) <=> array( $b['date'], $b['title'] );
			}
		);
		return $out;
	}

	/**
	 * Liczby z filtra.
	 *
	 * @return array<int,array{label:string,value:string,hint:string,url:string}>
	 */
	private static function plan_kpis(): array {
		$raw = apply_filters( 'colorify_dashboard_kpis', array() );
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $k ) {
			if ( ! is_array( $k ) || empty( $k['label'] ) ) {
				continue;
			}
			$out[] = array(
				'label' => wp_strip_all_tags( (string) $k['label'] ),
				'value' => wp_strip_all_tags( (string) ( $k['value'] ?? '' ) ),
				'hint'  => wp_strip_all_tags( (string) ( $k['hint'] ?? '' ) ),
				'url'   => isset( $k['url'] ) ? esc_url_raw( (string) $k['url'] ) : '',
			);
		}
		return array_slice( $out, 0, 8 );
	}

	/** Kwota z walutą: "zł" na polskiej stronie, w sklepie symbol WooCommerce. */
	private static function plan_money( float $v ): string {
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return self::money( $v );
		}
		return self::num( $v ) . "\u{00A0}" . ( 0 === strpos( (string) get_locale(), 'pl' ) ? 'zł' : '' );
	}

	/** @return array{0:string,1:string} etykieta, klasa plakietki */
	private static function plan_status( string $status ): array {
		$map = array(
			'nowe'         => array( __( 'Nowe', 'colorify-by-inyfinn' ), 'info' ),
			'wstepnie'     => array( __( 'Wstępnie', 'colorify-by-inyfinn' ), 'warn' ),
			'umowa'        => array( __( 'Umowa', 'colorify-by-inyfinn' ), 'info' ),
			'zadatek'      => array( __( 'Zadatek', 'colorify-by-inyfinn' ), 'up' ),
			'zrealizowane' => array( __( 'Zrealizowane', 'colorify-by-inyfinn' ), 'flat' ),
			'anulowane'    => array( __( 'Anulowane', 'colorify-by-inyfinn' ), 'down' ),
		);
		return $map[ $status ] ?? array( '' === $status ? '' : ucfirst( str_replace( array( '-', '_' ), ' ', $status ) ), 'flat' );
	}

	private static function plan_type_label( string $type ): string {
		$map = array(
			'wesele'  => __( 'Zlecenie', 'colorify-by-inyfinn' ),
			'kontakt' => __( 'Kontakt', 'colorify-by-inyfinn' ),
			'oddanie' => __( 'Oddanie materiałów', 'colorify-by-inyfinn' ),
			'blokada' => __( 'Zablokowane', 'colorify-by-inyfinn' ),
		);
		return $map[ $type ] ?? ( '' === $type ? __( 'Wydarzenie', 'colorify-by-inyfinn' ) : ucfirst( $type ) );
	}

	/** Klasa koloru chipa: nieznany rodzaj wygląda jak "kontakt". */
	private static function plan_type_class( string $type ): string {
		return in_array( $type, array( 'wesele', 'kontakt', 'oddanie', 'blokada' ), true ) ? $type : 'kontakt';
	}

	/** Liczby w pasku: z filtra, a bez nich policzone z wydarzeń miesiąca. @return string HTML */
	private static function plan_strip( array $kpis, array $month_events, int $days_in_month ): string {
		if ( ! $kpis && $month_events ) {
			$busy   = array();
			$sum    = 0;
			$count  = 0;
			foreach ( $month_events as $e ) {
				if ( 'blokada' === $e['type'] ) {
					continue;
				}
				++$count;
				$busy[ $e['date'] ] = 1;
				$sum               += max( 0, (int) $e['amount'] );
			}
			$kpis[] = array( 'label' => __( 'W tym miesiącu', 'colorify-by-inyfinn' ), 'value' => self::num( $count ), 'hint' => '', 'url' => '' );
			/* translators: %d: liczba dni w miesiącu */
			$kpis[] = array( 'label' => __( 'Zajęte dni', 'colorify-by-inyfinn' ), 'value' => self::num( count( $busy ) ), 'hint' => sprintf( __( 'z %d dni', 'colorify-by-inyfinn' ), $days_in_month ), 'url' => '' );
			if ( $sum > 0 ) {
				$kpis[] = array( 'label' => __( 'Wartość w miesiącu', 'colorify-by-inyfinn' ), 'value' => self::plan_money( (float) $sum ), 'hint' => '', 'url' => '' );
			}
		}
		if ( ! $kpis ) {
			return '';
		}
		$icons = array( 'calendar', 'cash', 'pin', 'clock', 'user', 'pages', 'mail', 'conv' );
		$out   = '<section class="cfd-card cfd-strip cfd-strip--n" style="--cfd-n:' . (int) min( 6, count( $kpis ) ) . '" aria-label="' . esc_attr__( 'Najważniejsze liczby', 'colorify-by-inyfinn' ) . '">';
		foreach ( $kpis as $i => $k ) {
			$out .= self::stat( $icons[ $i % count( $icons ) ], $k['label'], esc_html( $k['value'] ), '', $k['hint'], $k['url'] );
		}
		return $out . '</section>';
	}

	/** Miesiąc z adresu (?cfd_month=RRRR-MM) albo bieżący. */
	private static function plan_month(): DateTimeImmutable {
		$tz = wp_timezone();
		$m  = isset( $_GET['cfd_month'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['cfd_month'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( preg_match( '/^(20\d{2})-(0[1-9]|1[0-2])$/', $m ) ) {
			return new DateTimeImmutable( $m . '-01', $tz );
		}
		return new DateTimeImmutable( 'first day of this month', $tz );
	}

	/** Brak danych z wtyczki: podpowiedź zamiast błędu. */
	private static function plan_hint(): string {
		return self::promo(
			__( 'Podpowiedź', 'colorify-by-inyfinn' ),
			__( 'Terminarz jest pusty', 'colorify-by-inyfinn' ),
			array( __( 'Żadna wtyczka nie podaje jeszcze zleceń. Wtyczka rezerwacji lub zleceń przekazuje je kokpitowi filtrem colorify_calendar_events, a liczby filtrem colorify_dashboard_kpis.', 'colorify-by-inyfinn' ) ),
			'',
			'',
			true
		);
	}

	/* ===================== Motyw: Kalendarz zleceń ===================== */

	private static function calendar( int $days ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		global $wp_locale;
		$tz       = wp_timezone();
		$first    = self::plan_month();
		$today    = new DateTimeImmutable( 'today', $tz );
		$last     = $first->modify( 'last day of this month' );
		$start    = $first->modify( '-' . ( (int) $first->format( 'N' ) - 1 ) . ' days' );
		$end      = $last->modify( '+' . ( 7 - (int) $last->format( 'N' ) ) . ' days' );
		$events   = self::plan_events( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
		$by_day   = array();
		foreach ( $events as $e ) {
			$by_day[ $e['date'] ][] = $e;
		}
		$provider = self::plan_has_provider();
		$in_month = array_values( array_filter( $events, static function ( $e ) use ( $first, $last ) {
			return $e['date'] >= $first->format( 'Y-m-d' ) && $e['date'] <= $last->format( 'Y-m-d' );
		} ) );

		$base = admin_url( 'index.php' );
		$prev = $first->modify( '-1 month' )->format( 'Y-m' );
		$next = $first->modify( '+1 month' )->format( 'Y-m' );
		$out  = self::head( __( 'Terminarz zleceń', 'colorify-by-inyfinn' ), 30, false );
		$out .= self::plan_strip( self::plan_kpis(), $in_month, (int) $last->format( 'j' ) );

		// Siatka miesiąca.
		$cal  = '<section class="cfd-card cfc-cal" aria-label="' . esc_attr__( 'Kalendarz', 'colorify-by-inyfinn' ) . '">';
		$cal .= '<div class="cfc-bar"><h2 class="cfc-title">' . esc_html( wp_date( 'F Y', $first->getTimestamp() ) ) . '</h2><nav class="cfc-nav" aria-label="' . esc_attr__( 'Miesiąc', 'colorify-by-inyfinn' ) . '">'
			. '<a class="cfc-nav__a" href="' . esc_url( add_query_arg( 'cfd_month', $prev, $base ) ) . '" aria-label="' . esc_attr__( 'Poprzedni miesiąc', 'colorify-by-inyfinn' ) . '">&lsaquo;</a>'
			. '<a class="cfc-nav__a cfc-nav__today" href="' . esc_url( $base ) . '">' . esc_html__( 'Dziś', 'colorify-by-inyfinn' ) . '</a>'
			. '<a class="cfc-nav__a" href="' . esc_url( add_query_arg( 'cfd_month', $next, $base ) ) . '" aria-label="' . esc_attr__( 'Następny miesiąc', 'colorify-by-inyfinn' ) . '">&rsaquo;</a>'
			. '</nav></div>';
		$cal .= '<table class="cfc-grid"><thead><tr>';
		foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $wd ) {
			$cal .= '<th scope="col">' . esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $wd ) ) ) . '</th>';
		}
		$cal .= '</tr></thead><tbody>';
		for ( $d = $start; $d <= $end; $d = $d->modify( '+1 day' ) ) {
			if ( 1 === (int) $d->format( 'N' ) ) {
				$cal .= '<tr>';
			}
			$ymd   = $d->format( 'Y-m-d' );
			$cls   = ( $d->format( 'Y-m' ) !== $first->format( 'Y-m' ) ? ' is-out' : '' ) . ( $ymd === $today->format( 'Y-m-d' ) ? ' is-today' : '' ) . ( (int) $d->format( 'N' ) >= 6 ? ' is-weekend' : '' ) . ( ! empty( $by_day[ $ymd ] ) ? ' has-ev' : '' );
			$cal  .= '<td class="cfc-day' . $cls . '"' . ( $ymd === $today->format( 'Y-m-d' ) ? ' aria-current="date"' : '' ) . '><span class="cfc-num">' . esc_html( $d->format( 'j' ) ) . '</span>';
			$items = $by_day[ $ymd ] ?? array();
			foreach ( array_slice( $items, 0, 2 ) as $e ) {
				$cal .= self::plan_chip( $e );
			}
			if ( count( $items ) > 2 ) {
				/* translators: %d: liczba dalszych wydarzeń */
				$cal .= '<span class="cfc-more">' . esc_html( sprintf( __( '+%d więcej', 'colorify-by-inyfinn' ), count( $items ) - 2 ) ) . '</span>';
			}
			$cal .= '</td>';
			if ( 7 === (int) $d->format( 'N' ) ) {
				$cal .= '</tr>';
			}
		}
		$cal .= '</tbody></table>';
		$cal .= '<ul class="cfc-legend" aria-label="' . esc_attr__( 'Legenda', 'colorify-by-inyfinn' ) . '">';
		foreach ( array( 'wesele', 'kontakt', 'oddanie', 'blokada' ) as $t ) {
			$cal .= '<li><i class="cfc-dot cfc-dot--' . esc_attr( $t ) . '"></i>' . esc_html( self::plan_type_label( $t ) ) . '</li>';
		}
		$cal .= '</ul>';
		if ( $provider && ! $in_month ) {
			$cal .= '<p class="cfd-empty">' . esc_html__( 'W tym miesiącu nie ma nic w terminarzu.', 'colorify-by-inyfinn' ) . '</p>';
		}
		$cal .= '</section>';

		// Najbliższe wydarzenia (od dziś, 120 dni), bez blokad.
		$ahead = array_values( array_filter( self::plan_events( $today->format( 'Y-m-d' ), $today->modify( '+120 days' )->format( 'Y-m-d' ) ), static function ( $e ) {
			return 'blokada' !== $e['type'];
		} ) );
		$side  = self::plan_upcoming( __( 'Najbliższe', 'colorify-by-inyfinn' ), array_slice( $ahead, 0, 8 ), $provider ? __( 'Nic nie jest zaplanowane na najbliższe miesiące.', 'colorify-by-inyfinn' ) : '' );
		if ( ! $provider ) {
			$side = self::plan_hint();
		}
		return $out . '<div class="cfc">' . $cal . '<div class="cfd-stack">' . $side . '</div></div>';
	}

	/** Pojedyncze wydarzenie w komórce dnia. @param array<string,mixed> $e */
	private static function plan_chip( array $e ): string {
		$tip = trim( $e['title'] . ( '' !== $e['meta'] ? ' · ' . $e['meta'] : '' ) . ( null !== $e['amount'] && $e['amount'] > 0 ? ' · ' . self::plan_money( (float) $e['amount'] ) : '' ) );
		$cls = 'cfc-ev cfc-ev--' . self::plan_type_class( (string) $e['type'] );
		return '' !== $e['url']
			? '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $e['url'] ) . '" title="' . esc_attr( $tip ) . '">' . esc_html( $e['title'] ) . '</a>'
			: '<span class="' . esc_attr( $cls ) . '" title="' . esc_attr( $tip ) . '">' . esc_html( $e['title'] ) . '</span>';
	}

	/**
	 * Karta z listą nadchodzących wydarzeń (data jako plakietka, tytuł, opis, kwota i status).
	 *
	 * @param array<int,array<string,mixed>> $events Wydarzenia.
	 */
	private static function plan_upcoming( string $title, array $events, string $empty ): string {
		$out = '<section class="cfd-card"><div class="cfd-card__bar"><p class="cfd-card__title">' . esc_html( $title ) . '</p></div>';
		if ( ! $events ) {
			return $out . ( '' !== $empty ? '<p class="cfd-empty">' . esc_html( $empty ) . '</p>' : '' ) . '</section>';
		}
		$out .= '<ol class="cfd-rows cfc-up">';
		foreach ( $events as $e ) {
			$ts     = strtotime( $e['date'] . ' 12:00:00' );
			$status = self::plan_status( (string) $e['status'] );
			$sub    = trim( $e['meta'] );
			$aside  = ( null !== $e['amount'] && $e['amount'] > 0 ? '<span class="cfc-up__amt">' . esc_html( self::plan_money( (float) $e['amount'] ) ) . '</span>' : '' )
				. ( '' !== $status[0] ? '<span class="cfd-pill cfd-pill--' . esc_attr( $status[1] ) . '">' . esc_html( $status[0] ) . '</span>' : '' );
			$inner  = '<span class="cfc-date"><b>' . esc_html( wp_date( 'j', $ts ) ) . '</b><i>' . esc_html( wp_date( 'M', $ts ) ) . '</i></span>'
				. '<span class="cfc-up__main"><span class="cfd-rows__main">' . esc_html( $e['title'] ) . '</span>' . ( '' !== $sub ? '<span class="cfd-rows__sub">' . esc_html( $sub ) . '</span>' : '' ) . '</span>';
			$out   .= '<li>' . ( '' !== $e['url'] ? '<a href="' . esc_url( $e['url'] ) . '">' . $inner . '</a>' : '<span class="cfc-up__a">' . $inner . '</span>' )
				. ( '' !== $aside ? '<span class="cfc-up__aside">' . $aside . '</span>' : '' ) . '</li>';
		}
		return $out . '</ol></section>';
	}

	/* ===================== Motyw: Rezerwacje ===================== */

	private static function bookings( int $days ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$year0 = $today->modify( 'first day of january this year' );
		$all   = self::plan_events( $year0->format( 'Y-m-d' ), $year0->modify( '+2 years' )->modify( '-1 day' )->format( 'Y-m-d' ) );
		$work  = array_values( array_filter( $all, static function ( $e ) {
			return 'blokada' !== $e['type'] && 'kontakt' !== $e['type'];
		} ) );
		$ahead = array_values( array_filter( $work, static function ( $e ) use ( $today ) {
			return $e['date'] >= $today->format( 'Y-m-d' ) && 'anulowane' !== $e['status'];
		} ) );

		// Etapy: ile zleceń i na jaką kwotę w każdym statusie (bez anulowanych w sumie).
		$stages = array();
		foreach ( $work as $e ) {
			$k                    = '' !== $e['status'] ? $e['status'] : 'inne';
			$stages[ $k ]         = $stages[ $k ] ?? array( 'n' => 0, 'sum' => 0 );
			$stages[ $k ]['n']   += 1;
			$stages[ $k ]['sum'] += max( 0, (int) $e['amount'] );
		}
		$order = array( 'nowe', 'wstepnie', 'umowa', 'zadatek', 'zrealizowane', 'anulowane' );
		uksort(
			$stages,
			static function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a, $order, true );
				$ib = array_search( $b, $order, true );
				return ( false === $ia ? 99 : $ia ) <=> ( false === $ib ? 99 : $ib );
			}
		);

		$kpis = self::plan_kpis();
		if ( ! $kpis && $work ) {
			$earned = 0;
			foreach ( $work as $e ) {
				if ( in_array( $e['status'], array( 'umowa', 'zadatek', 'zrealizowane' ), true ) ) {
					$earned += max( 0, (int) $e['amount'] );
				}
			}
			$kpis[] = array( 'label' => __( 'Zlecenia w tym roku', 'colorify-by-inyfinn' ), 'value' => self::num( count( $work ) ), 'hint' => '', 'url' => '' );
			if ( $earned > 0 ) {
				$kpis[] = array( 'label' => __( 'Potwierdzona wartość', 'colorify-by-inyfinn' ), 'value' => self::plan_money( (float) $earned ), 'hint' => __( 'umowa, zadatek, zrealizowane', 'colorify-by-inyfinn' ), 'url' => '' );
			}
			if ( $ahead ) {
				$kpis[] = array( 'label' => __( 'Najbliższe zlecenie', 'colorify-by-inyfinn' ), 'value' => wp_date( 'j.m', strtotime( $ahead[0]['date'] . ' 12:00:00' ) ), 'hint' => $ahead[0]['title'], 'url' => $ahead[0]['url'] );
			}
		}

		$out  = self::head( __( 'Zlecenia i zarobki', 'colorify-by-inyfinn' ), 30, false );
		$out .= self::plan_strip( $kpis, array(), 0 );

		// Etapy zleceń.
		$max  = 1;
		foreach ( $stages as $s ) {
			$max = max( $max, $s['n'] );
		}
		$card = '<section class="cfd-card"><div class="cfd-card__bar"><p class="cfd-card__title">' . esc_html__( 'Etapy zleceń', 'colorify-by-inyfinn' ) . '</p></div>';
		if ( ! $stages ) {
			$card .= '<p class="cfd-empty">' . esc_html__( 'Zlecenia pojawią się tutaj, gdy wtyczka rezerwacji poda je kokpitowi.', 'colorify-by-inyfinn' ) . '</p>';
		} else {
			$card .= '<ul class="cfb-stages">';
			foreach ( $stages as $k => $s ) {
				$st    = self::plan_status( (string) $k );
				$card .= '<li><span class="cfb-stage__name"><span class="cfd-pill cfd-pill--' . esc_attr( $st[1] ) . '">' . esc_html( '' !== $st[0] ? $st[0] : __( 'Inne', 'colorify-by-inyfinn' ) ) . '</span></span>'
					. '<span class="cfb-stage__bar"><i style="--w:' . (int) round( $s['n'] / $max * 100 ) . '%"></i></span>'
					. '<span class="cfb-stage__n">' . esc_html( self::num( $s['n'] ) ) . '</span>'
					. '<span class="cfb-stage__sum">' . ( $s['sum'] > 0 ? esc_html( self::plan_money( (float) $s['sum'] ) ) : '' ) . '</span></li>';
			}
			$card .= '</ul>';
		}
		$card .= '</section>';

		$next  = $ahead ? self::promo( __( 'Najbliższe', 'colorify-by-inyfinn' ), $ahead[0]['title'], array_filter( array( wp_date( 'j F Y', strtotime( $ahead[0]['date'] . ' 12:00:00' ) ), $ahead[0]['meta'] ) ), '' !== $ahead[0]['url'] ? __( 'Otwórz zlecenie', 'colorify-by-inyfinn' ) : '', $ahead[0]['url'] )
			: ( self::plan_has_provider() ? self::promo( __( 'Najbliższe', 'colorify-by-inyfinn' ), __( 'Brak nadchodzących zleceń', 'colorify-by-inyfinn' ), array( __( 'Nowe zlecenia pojawią się tutaj.', 'colorify-by-inyfinn' ) ), '', '', true ) : self::plan_hint() );
		$out  .= '<div class="cfd-row">' . $card . $next . '</div>';
		$out  .= '<div class="cfd-row cfd-row--even">'
			. self::plan_upcoming( __( 'Kolejne zlecenia', 'colorify-by-inyfinn' ), array_slice( $ahead, 0, 6 ), self::plan_has_provider() ? __( 'Nic nie jest zaplanowane.', 'colorify-by-inyfinn' ) : '' )
			. '</div>';
		return $out;
	}

	/* ===================== Motyw: Portfolio ===================== */

	private static function portfolio( int $days ): string {
		list( $from, $to, $prevf ) = self::range( $days );
		$tcur  = self::traffic( $from, $to );
		$tpre  = self::traffic( $prevf, $from );
		$mime  = (array) wp_count_attachments();
		$imgs  = 0;
		$vids  = 0;
		foreach ( $mime as $type => $n ) {
			if ( 0 === strpos( (string) $type, 'image' ) ) {
				$imgs += (int) $n;
			} elseif ( 0 === strpos( (string) $type, 'video' ) ) {
				$vids += (int) $n;
			}
		}
		$pages = wp_count_posts( 'page' );

		/* translators: %d: liczba dni */
		$out  = self::head( sprintf( __( 'Ostatnie %d dni', 'colorify-by-inyfinn' ), $days ), $days );
		$out .= self::shortcuts(
			array(
				array( 'plus', __( 'Dodaj zdjęcia', 'colorify-by-inyfinn' ), admin_url( 'media-new.php' ) ),
				array( 'image', __( 'Biblioteka mediów', 'colorify-by-inyfinn' ), admin_url( 'upload.php' ) ),
				array( 'pages', __( 'Strony', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=page' ) ),
				array( 'globe', __( 'Zobacz stronę', 'colorify-by-inyfinn' ), home_url( '/' ) ),
			)
		);
		$out .= '<section class="cfd-card cfd-strip">';
		$out .= self::stat( 'image', __( 'Zdjęcia i grafiki', 'colorify-by-inyfinn' ), esc_html( self::num( $imgs ) ), '', '', admin_url( 'upload.php?attachment-filter=post_mime_type:image' ) );
		$out .= self::stat( 'film', __( 'Filmy', 'colorify-by-inyfinn' ), esc_html( self::num( $vids ) ), '', '', admin_url( 'upload.php?attachment-filter=post_mime_type:video' ) );
		$out .= self::stat( 'pages', __( 'Opublikowane strony', 'colorify-by-inyfinn' ), esc_html( self::num( (float) $pages->publish ) ), '', '', admin_url( 'edit.php?post_type=page' ) );
		$out .= self::stat( 'eye', __( 'Wejścia na stronę', 'colorify-by-inyfinn' ), esc_html( self::num( $tcur['views'] ) ), self::delta( $tcur['views'], $tpre['views'] ), '' === $tcur['first'] ? __( 'Licznik zaczyna od dziś', 'colorify-by-inyfinn' ) : '' );
		$out .= '</section>';

		// Ostatnio dodane zdjęcia.
		$media = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'numberposts' => 8, 'orderby' => 'date', 'order' => 'DESC' ) );
		$grid  = '<section class="cfd-card"><div class="cfd-card__bar"><p class="cfd-card__title">' . esc_html__( 'Ostatnio dodane', 'colorify-by-inyfinn' ) . '</p><a class="cfd-link" href="' . esc_url( admin_url( 'upload.php' ) ) . '">' . esc_html__( 'Wszystkie', 'colorify-by-inyfinn' ) . '</a></div>';
		if ( ! $media ) {
			$grid .= '<p class="cfd-empty">' . esc_html__( 'Biblioteka mediów jest pusta. Dodaj pierwsze zdjęcia.', 'colorify-by-inyfinn' ) . '</p>';
		} else {
			$grid .= '<ul class="cfp-grid">';
			foreach ( $media as $m ) {
				$alt   = trim( (string) get_post_meta( $m->ID, '_wp_attachment_image_alt', true ) );
				$label = '' !== $alt ? $alt : get_the_title( $m );
				$img   = wp_get_attachment_image( $m->ID, 'medium', false, array( 'class' => 'cfp-img', 'loading' => 'lazy', 'alt' => $label ) );
				if ( '' === $img ) {
					continue;
				}
				$grid .= '<li><a href="' . esc_url( (string) get_edit_post_link( $m->ID, 'raw' ) ) . '" title="' . esc_attr( $label ) . '">' . $img . '</a></li>';
			}
			$grid .= '</ul>';
		}
		$grid .= '</section>';

		$top  = array_slice( $tcur['pages'], 0, 1, true );
		$tid  = (int) key( $top );
		$aside = $tid
			? self::promo( __( 'Najczęściej oglądana', 'colorify-by-inyfinn' ), get_the_title( $tid ), array( self::views_label( (int) reset( $top ) ) ), __( 'Edytuj stronę', 'colorify-by-inyfinn' ), (string) get_edit_post_link( $tid, 'raw' ), true )
			: self::promo( __( 'Dodaj nowe prace', 'colorify-by-inyfinn' ), __( 'Pokaż, co ostatnio zrobiłaś', 'colorify-by-inyfinn' ), array( __( 'Nowe zdjęcia pojawią się obok. Licznik wejść zbiera dane od dziś.', 'colorify-by-inyfinn' ) ), __( 'Dodaj zdjęcia', 'colorify-by-inyfinn' ), admin_url( 'media-new.php' ), true );
		$out .= '<div class="cfd-row">' . $grid . $aside . '</div>';
		$out .= '<div class="cfd-row cfd-row--even">'
			. self::list_card( __( 'Popularne podstrony', 'colorify-by-inyfinn' ), self::top_pages_rows( $tcur['pages'] ), __( 'Licznik zbiera dane od dziś.', 'colorify-by-inyfinn' ), '', '', true )
			. self::list_card( __( 'Ostatnio zmieniane strony', 'colorify-by-inyfinn' ), self::recent_posts( 'page', array( 'publish', 'draft' ) ), __( 'Brak stron.', 'colorify-by-inyfinn' ), __( 'Wszystkie', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=page' ) )
			. '</div>';
		return $out;
	}

	/* ===================== Wygląd ===================== */

	/** Reguły trzech nowych styli (dopisywane po arkuszu głównym kokpitu). */
	public static function planner_css(): void {
		?>
<style id="colorify-dashboard-planner-css">
.cfd-strip--n{grid-template-columns:repeat(var(--cfd-n,4),minmax(0,1fr))}
.cfd-strip--n .cfd-stat__top{min-height:42px;align-items:flex-start}
.cfd-ico--calendar,.cfd-ico--pin{background:#e3eefc;color:#2a6fd6}.cfd-ico--image,.cfd-ico--film{background:#efe7fb;color:#7046c2}
.cfc{display:grid;grid-template-columns:minmax(0,1.9fr) minmax(0,1fr);gap:20px;align-items:start}
.cfc-cal{padding:22px 22px 18px}
.cfc-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.cfc-title{margin:0;padding:0;font-size:20px;line-height:1.2;font-weight:700;letter-spacing:-.01em;text-transform:capitalize;color:var(--t)}
.cfc-nav{display:inline-flex;gap:4px}
.cfc-nav__a{display:inline-flex;align-items:center;justify-content:center;min-width:40px;min-height:40px;padding:0 12px;border-radius:12px;background:color-mix(in srgb,var(--t) 5%,var(--s));color:var(--t);text-decoration:none;font-size:16px;font-weight:600}
.cfc-nav__a:hover{background:color-mix(in srgb,var(--a) 14%,var(--s));color:var(--a)}
.cfc-nav__today{font-size:13px}
.cfc-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:4px;margin:0 -4px;width:calc(100% + 8px)}
.cfc-grid th{padding:4px 6px;font-size:12px;font-weight:500;text-align:left;color:var(--m);text-transform:lowercase}
.cfc-day{height:116px;padding:6px 6px 4px;vertical-align:top;border-radius:12px;background:color-mix(in srgb,var(--t) 3.5%,var(--s));overflow:hidden}
.cfc-day.is-weekend{background:color-mix(in srgb,var(--t) 6%,var(--s))}
.cfc-day.is-out{background:transparent;opacity:.5}
.cfc-day.is-today{box-shadow:inset 0 0 0 1.5px var(--a)}
.cfc-num{display:block;margin-bottom:3px;font-size:12px;font-weight:600;color:var(--m)}
.is-today .cfc-num{display:inline-block;min-width:22px;margin:-1px 0 3px -2px;padding:1px 6px;border-radius:99px;background:var(--a);color:var(--on);text-align:center}
.cfc-ev{display:block;margin-top:3px;padding:2px 6px;border-radius:6px;font-size:11px;font-weight:600;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-decoration:none}
a.cfc-ev:hover{filter:brightness(.94)}
.cfc-ev--wesele{background:var(--a);color:var(--on)}
.cfc-ev--kontakt{background:color-mix(in srgb,var(--a) 16%,var(--s));color:var(--a)}
.cfc-ev--oddanie{background:var(--colorify-tag-warn-bg,#fdf1d8);color:var(--colorify-tag-warn-fg,#8a5a00)}
.cfc-ev--blokada{background:color-mix(in srgb,var(--t) 10%,transparent);color:var(--m)}
.cfc-more{display:block;margin-top:3px;font-size:11px;color:var(--m)}
.cfc-legend{display:flex;flex-wrap:wrap;gap:6px 16px;margin:14px 0 0;padding:0;list-style:none;font-size:12px;color:var(--m)}
.cfc-legend li{display:inline-flex;align-items:center;gap:6px}
.cfc-dot{width:10px;height:10px;border-radius:3px;display:inline-block}
.cfc-dot--wesele{background:var(--a)}.cfc-dot--kontakt{background:color-mix(in srgb,var(--a) 30%,var(--s))}.cfc-dot--oddanie{background:#e0b040}.cfc-dot--blokada{background:color-mix(in srgb,var(--t) 22%,transparent)}
.cfc-up li{align-items:flex-start}
.cfc-up a,.cfc-up__a{flex:1;min-width:0;display:flex;flex-direction:row;align-items:center;gap:12px;color:var(--t);text-decoration:none}
.cfc-up__main{display:flex;flex-direction:column;gap:2px;min-width:0}
.cfc-up a:hover .cfd-rows__main{color:var(--a)}
.cfc-date{flex:none;display:flex;flex-direction:column;align-items:center;justify-content:center;width:46px;height:50px;border-radius:12px;background:color-mix(in srgb,var(--a) 10%,var(--s));color:var(--a);line-height:1}
.cfc-date b{font-size:18px;font-weight:700}.cfc-date i{margin-top:3px;font-size:11px;font-style:normal;font-weight:600;text-transform:lowercase}
.cfc-up__aside{display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex:none;align-self:center}
.cfc-up__amt{font-size:13px;font-weight:600;font-variant-numeric:tabular-nums;color:var(--t)}
.cfb-stages{margin:6px 0 0;padding:0;list-style:none}
.cfb-stages li{display:grid;grid-template-columns:128px minmax(0,1fr) 36px minmax(70px,auto);align-items:center;gap:14px;padding:11px 0;border-top:1px solid var(--line);font-size:14px}
.cfb-stages li:first-child{border-top:0}
.cfb-stage__bar{height:8px;border-radius:99px;background:color-mix(in srgb,var(--t) 8%,transparent);overflow:hidden}
.cfb-stage__bar i{display:block;height:100%;width:var(--w);border-radius:99px;background:var(--a)}
.cfb-stage__n{font-weight:700;font-variant-numeric:tabular-nums;text-align:right}
.cfb-stage__sum{color:var(--m);font-variant-numeric:tabular-nums;text-align:right;white-space:nowrap}
.cfp-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:10px 0 0;padding:0;list-style:none}
.cfp-grid a{display:block;aspect-ratio:1;border-radius:14px;overflow:hidden;background:color-mix(in srgb,var(--t) 6%,var(--s))}
.cfp-img{display:block;width:100%;height:100%;object-fit:cover;transition:transform .2s ease}
.cfp-grid a:hover .cfp-img{transform:scale(1.04)}
@media (max-width:1100px){.cfc{grid-template-columns:minmax(0,1fr)}.cfd-strip--n{grid-template-columns:repeat(2,minmax(0,1fr))}.cfd-strip--n .cfd-stat:nth-child(odd){border-left:0}}
@media (max-width:700px){
.cfc-cal{padding:16px 12px 14px}
.cfc-title{font-size:17px}
.cfc-grid{border-spacing:2px;width:calc(100% + 4px);margin:0 -2px}
.cfc-grid th{padding:2px 2px;font-size:11px}
.cfc-day{height:auto;min-height:48px;padding:4px 3px;border-radius:9px;text-align:center}
.cfc-num{margin-bottom:2px;font-size:12px}
.cfc-ev{display:inline-block;width:8px;height:8px;margin:1px;padding:0;border-radius:50%;font-size:0;line-height:0;vertical-align:middle}
.cfc-ev--kontakt{background:color-mix(in srgb,var(--a) 45%,var(--s))}
.cfc-more{display:none}
.cfb-stages li{grid-template-columns:96px minmax(0,1fr) 28px;gap:10px}
.cfb-stage__sum{grid-column:2 / -1;text-align:left;font-size:12px}
.cfp-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
</style>
		<?php
	}
}
