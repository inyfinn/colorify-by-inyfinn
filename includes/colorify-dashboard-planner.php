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

	/** Czy to konto widzi dane zleceń (dane osobowe klientów). Filtr colorify_calendar_capability zmienia wymagane uprawnienie. */
	private static function plan_can_see(): bool {
		return current_user_can( (string) apply_filters( 'colorify_calendar_capability', 'edit_posts' ) );
	}

	/**
	 * Szczegóły wydarzenia: lista par etykieta/wartość (tekst bez HTML).
	 *
	 * @param mixed $raw Surowe 'details' z filtra.
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function plan_details( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) ( $row['label'] ?? ( $row[0] ?? '' ) ) ) );
			$value = trim( wp_strip_all_tags( (string) ( $row['value'] ?? ( $row[1] ?? '' ) ) ) );
			if ( '' !== $label && '' !== $value ) {
				$out[] = array( $label, mb_substr( $value, 0, 600 ) );
			}
		}
		return array_slice( $out, 0, 24 );
	}

	/**
	 * Statusy do wyboru w karcie: lista [ wartość, etykieta ] (albo [ 'value' => ..., 'label' => ... ]).
	 *
	 * @param mixed $raw Surowe 'status_options' z filtra.
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function plan_status_options( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$value = sanitize_key( (string) ( $row['value'] ?? ( $row[0] ?? '' ) ) );
			$label = trim( wp_strip_all_tags( (string) ( $row['label'] ?? ( $row[1] ?? '' ) ) ) );
			if ( '' !== $value && '' !== $label ) {
				$out[] = array( $value, mb_substr( $label, 0, 60 ) );
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Akcja AJAX zapisu statusu: [ 'action' => nazwa wp_ajax_*, 'nonce' => nonce tej akcji ]. Bez obu pól: null (status tylko do odczytu).
	 *
	 * @param mixed $raw Surowe 'status_action' z filtra.
	 * @return array{action:string,nonce:string}|null
	 */
	private static function plan_status_action( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$action = sanitize_key( (string) ( $raw['action'] ?? '' ) );
		$nonce  = preg_replace( '/[^a-zA-Z0-9]/', '', (string) ( $raw['nonce'] ?? '' ) );
		return '' !== $action && '' !== $nonce ? array( 'action' => $action, 'nonce' => $nonce ) : null;
	}

	/**
	 * Wydarzenia z filtra, tylko poprawne (data, tytuł), posortowane po dacie.
	 * Poza polami podstawowymi wtyczka może podać: id, phone, email, route_url, status_label, reminder_done, details,
	 * status_options i status_action (status zmieniany z karty szczegółów, zapis przez admin-ajax wtyczki).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function plan_events( string $from, string $to ): array {
		if ( ! self::plan_can_see() ) {
			return array();
		}
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
			$slab  = $e['status_label'] ?? ( $e['status_nazwa'] ?? '' );
			$out[] = array(
				'date'         => $date,
				'title'        => '' !== $title ? $title : __( '(bez tytułu)', 'colorify-by-inyfinn' ),
				'url'          => isset( $e['url'] ) ? esc_url_raw( (string) $e['url'] ) : '',
				'type'         => sanitize_key( (string) ( $e['type'] ?? '' ) ),
				'status'       => sanitize_key( (string) ( $e['status'] ?? '' ) ),
				'amount'       => isset( $e['amount'] ) && is_numeric( $e['amount'] ) ? (int) $e['amount'] : null,
				'meta'         => trim( wp_strip_all_tags( (string) ( $e['meta'] ?? '' ) ) ),
				'id'           => isset( $e['id'] ) ? (int) $e['id'] : 0,
				'phone'        => trim( wp_strip_all_tags( (string) ( $e['phone'] ?? '' ) ) ),
				'email'        => sanitize_email( (string) ( $e['email'] ?? '' ) ),
				'route'        => isset( $e['route_url'] ) ? esc_url_raw( (string) $e['route_url'] ) : '',
				'status_label' => trim( wp_strip_all_tags( (string) $slab ) ),
				'done'         => ! empty( $e['reminder_done'] ),
				'details'      => self::plan_details( $e['details'] ?? array() ),
				'status_opts'  => self::plan_status_options( $e['status_options'] ?? null ),
				'status_act'   => self::plan_status_action( $e['status_action'] ?? null ),
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
	 * Wszystkie wydarzenia z okna (rok wstecz, dwa lata do przodu) z numerem kolejnym 'i', raz na żądanie.
	 * Numer łączy wydarzenie w kalendarzu, na listach i w karcie szczegółów.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function plan_all(): array {
		static $all = null;
		if ( null === $all ) {
			$tz   = wp_timezone();
			$from = ( new DateTimeImmutable( 'first day of this month', $tz ) )->modify( '-13 months' )->format( 'Y-m-d' );
			$to   = ( new DateTimeImmutable( 'last day of this month', $tz ) )->modify( '+25 months' )->format( 'Y-m-d' );
			$all  = array_slice( self::plan_events( $from, $to ), 0, 600 );
			foreach ( $all as $i => $e ) {
				$all[ $i ]['i'] = $i;
			}
		}
		return $all;
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
			'przypomnienie' => __( 'Przypomnienie', 'colorify-by-inyfinn' ),
			'przygotowanie' => __( 'Przygotowanie', 'colorify-by-inyfinn' ),
		);
		return $map[ $type ] ?? ( '' === $type ? __( 'Wydarzenie', 'colorify-by-inyfinn' ) : ucfirst( $type ) );
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

	/** Rodzaj wydarzenia sprowadzony do jednego z pięciu znanych (nieznany to przypomnienie). */
	private static function plan_type_key( string $type ): string {
		return in_array( $type, array( 'wesele', 'kontakt', 'oddanie', 'blokada', 'przypomnienie', 'przygotowanie' ), true ) ? $type : 'przypomnienie';
	}

	/** Ikona rodzaju wydarzenia (nazwa w Colorify_Dashboard::icon). */
	private static function plan_type_icon( string $type ): string {
		$map = array( 'wesele' => 'ring', 'kontakt' => 'phone', 'oddanie' => 'box', 'blokada' => 'lock', 'przypomnienie' => 'bell', 'przygotowanie' => 'list' );
		return $map[ self::plan_type_key( $type ) ] ?? 'calendar';
	}

	/** Klasa koloru statusu: znany etap zlecenia albo "other". */
	private static function plan_status_key( string $status ): string {
		return in_array( $status, array( 'nowe', 'wstepnie', 'umowa', 'zadatek', 'zrealizowane', 'anulowane' ), true ) ? $status : 'other';
	}

	/** "dziś", "jutro", "za 5 dni", "3 dni temu". */
	private static function plan_rel( string $ymd, DateTimeImmutable $today ): string {
		$n = (int) $today->diff( new DateTimeImmutable( $ymd, wp_timezone() ) )->format( '%r%a' );
		if ( 0 === $n ) {
			return __( 'dziś', 'colorify-by-inyfinn' );
		}
		if ( 1 === $n ) {
			return __( 'jutro', 'colorify-by-inyfinn' );
		}
		if ( -1 === $n ) {
			return __( 'wczoraj', 'colorify-by-inyfinn' );
		}
		/* translators: %d: liczba dni */
		return $n > 0 ? sprintf( __( 'za %d dni', 'colorify-by-inyfinn' ), $n ) : sprintf( __( '%d dni temu', 'colorify-by-inyfinn' ), -$n );
	}

	/**
	 * Wydarzenie w postaci gotowej dla przeglądarki (karta szczegółów, kopiowanie).
	 *
	 * @param array<string,mixed> $e Wydarzenie z plan_all().
	 * @return array<string,mixed>
	 */
	private static function plan_payload( array $e, DateTimeImmutable $today ): array {
		$ts     = strtotime( $e['date'] . ' 12:00:00' );
		$type   = self::plan_type_key( (string) $e['type'] );
		$st     = self::plan_status( (string) $e['status'] );
		$slabel = '' !== $e['status_label'] ? $e['status_label'] : $st[0];
		$amount = ( null !== $e['amount'] && $e['amount'] > 0 ) ? self::plan_money( (float) $e['amount'] ) : '';
		$rows   = $e['details'];
		if ( ! $rows ) { // wtyczka nie podała szczegółów: pokazujemy to, co jest
			if ( '' !== $e['meta'] && $e['meta'] !== $e['phone'] && $e['meta'] !== $e['email'] ) {
				$rows[] = array( __( 'Szczegóły', 'colorify-by-inyfinn' ), $e['meta'] );
			}
			if ( '' !== $amount ) {
				$rows[] = array( __( 'Kwota', 'colorify-by-inyfinn' ), $amount );
			}
			if ( '' !== $slabel && 'blokada' !== $type ) {
				$rows[] = array( __( 'Status', 'colorify-by-inyfinn' ), $slabel );
			}
			if ( '' !== $e['phone'] ) {
				$rows[] = array( __( 'Telefon', 'colorify-by-inyfinn' ), $e['phone'] );
			}
			if ( '' !== $e['email'] ) {
				$rows[] = array( __( 'E-mail', 'colorify-by-inyfinn' ), $e['email'] );
			}
		}
		$long = wp_date( 'l, j F Y', $ts );
		// Tekst do wklejenia (Messenger, SMS): czysty, wielowierszowy, bez notatek wewnętrznych.
		$lines = array( self::plan_type_label( $type ) . ': ' . $e['title'], $long );
		foreach ( $rows as $r ) {
			if ( false === stripos( $r[0], 'notatk' ) ) {
				$lines[] = $r[0] . ': ' . $r[1];
			}
		}
		return array(
			'i'      => (int) $e['i'],
			'id'     => (int) $e['id'],
			'date'   => $e['date'],
			'dow'    => wp_date( 'l', $ts ),
			'long'   => $long,
			'rel'    => self::plan_rel( $e['date'], $today ),
			'title'  => $e['title'],
			'type'   => $type,
			'tl'     => self::plan_type_label( $type ),
			'st'     => self::plan_status_key( (string) $e['status'] ),
			'sv'     => (string) $e['status'],
			'sl'     => 'blokada' === $type ? '' : $slabel,
			'amount' => $amount,
			'url'    => $e['url'],
			'phone'  => $e['phone'],
			'email'  => $e['email'],
			'route'  => $e['route'],
			'rows'   => $rows,
			'done'   => (bool) $e['done'],
			'copy'   => implode( "\n", $lines ),
		);
	}

	/** Plakietka statusu zlecenia. */
	private static function plan_pill( array $e ): string {
		if ( 'blokada' === $e['type'] ) {
			return '';
		}
		$st    = self::plan_status( (string) $e['status'] );
		$label = '' !== $e['status_label'] ? $e['status_label'] : $st[0];
		$attr  = ! empty( $e['id'] ) ? ' data-cfb-pill="' . (int) $e['id'] . '"' : ''; // skrypt odświeża plakietkę po zmianie statusu w karcie
		return '' !== $label ? '<span class="cfd-pill cfd-pill--' . esc_attr( $st[1] ) . '"' . $attr . '>' . esc_html( $label ) . '</span>' : '';
	}

	/** Data jako plakietka (dzień i miesiąc). */
	private static function plan_date_badge( string $ymd ): string {
		$ts = strtotime( $ymd . ' 12:00:00' );
		return '<span class="cfb-date" aria-hidden="true"><b>' . esc_html( wp_date( 'j', $ts ) ) . '</b><i>' . esc_html( wp_date( 'M', $ts ) ) . '</i></span>';
	}

	/** Liczby w osobnych, równych kafelkach. Bez liczb z wtyczki: liczone z wydarzeń miesiąca. @return string HTML */
	private static function plan_strip( array $kpis, array $month_events, int $days_in_month ): string {
		if ( ! $kpis && $month_events ) {
			$busy  = array();
			$sum   = 0;
			$count = 0;
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
		$fallback = array( 'calendar', 'cash', 'pin', 'clock', 'user', 'pages', 'mail', 'conv' );
		$out      = '<section class="cfb-kpis" data-n="' . (int) min( 6, count( $kpis ) ) . '" style="--cfb-n:' . (int) min( 6, count( $kpis ) ) . '" aria-label="' . esc_attr__( 'Najważniejsze liczby', 'colorify-by-inyfinn' ) . '">';
		foreach ( $kpis as $i => $k ) {
			$l = mb_strtolower( $k['label'] );
			if ( preg_match( '/zarob|kwot|wartość|przychód|przych|zł/u', $l ) ) {
				$ico = 'cash';
			} elseif ( preg_match( '/kilometr|\bkm\b|dojazd/u', $l ) ) {
				$ico = 'pin';
			} elseif ( preg_match( '/najbliż|termin|wesel|dat[aey]/u', $l ) ) {
				$ico = 'calendar';
			} elseif ( preg_match( '/czek|oczek|odpowied/u', $l ) ) {
				$ico = 'clock';
			} elseif ( preg_match( '/zgłosz|zapyt|wiadom|mail/u', $l ) ) {
				$ico = 'mail';
			} else {
				$ico = $fallback[ $i % count( $fallback ) ];
			}
			$inner = '<span class="cfb-kpi__top">' . self::icon( $ico ) . '<span class="cfb-kpi__label">' . esc_html( $k['label'] ) . '</span></span>'
				. '<span class="cfb-kpi__value' . ( self::is_text_value( $k['value'] ) ? ' is-text' : '' ) . '">' . esc_html( $k['value'] ) . '</span>'
				. '<span class="cfb-kpi__hint">' . esc_html( $k['hint'] ) . '</span>';
			$out  .= '' !== $k['url'] ? '<a class="cfd-card cfb-kpi is-link" href="' . esc_url( $k['url'] ) . '">' . $inner . '</a>' : '<div class="cfd-card cfb-kpi">' . $inner . '</div>';
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

	/** Wiersz etykieta/wartość; wartość to przycisk kopiujący ją do schowka (ikonka pokazuje się przy najechaniu i fokusie). */
	private static function plan_kv( string $label, string $value ): string {
		$wide = mb_strlen( $value ) > 46 || false !== strpos( $value, "\n" );
		/* translators: %s: nazwa pola */
		$aria = sprintf( __( 'Skopiuj: %s', 'colorify-by-inyfinn' ), $label );
		return '<div class="cfb-kv' . ( $wide ? ' is-wide' : '' ) . '"><dt>' . esc_html( $label ) . '</dt><dd><button type="button" class="cfb-val" data-cfb-val="' . esc_attr( $value ) . '" aria-label="' . esc_attr( $aria ) . '"><span>' . esc_html( $value ) . '</span><i class="cfb-val__i" aria-hidden="true">' . self::icon_svg( 'copy' ) . '</i></button></dd></div>';
	}

	/**
	 * Kafel z listą: pierwsze $limit pozycji, reszta pod przyciskiem "Pokaż wszystkie" (bez przewijania w kafelku).
	 *
	 * @param array<int,string> $items Gotowe elementy <li>.
	 */
	private static function plan_list_tile( string $title, string $note, array $items, string $empty, string $class = '', int $limit = 6 ): string {
		$out = '<section class="cfd-card cfb-tile ' . esc_attr( $class ) . '"><div class="cfb-tile__bar"><h2 class="cfb-tile__title">' . esc_html( $title ) . '</h2>' . ( '' !== $note ? '<span class="cfb-tile__note">' . esc_html( $note ) . '</span>' : '' ) . '</div>';
		if ( ! $items ) {
			return $out . '<p class="cfb-empty">' . esc_html( $empty ) . '</p></section>';
		}
		$out .= '<ol class="cfb-li-list">';
		foreach ( $items as $i => $li ) {
			$out .= $i >= $limit ? str_replace( '<li>', '<li data-extra hidden>', $li ) : $li;
		}
		$out .= '</ol>';
		if ( count( $items ) > $limit ) {
			/* translators: %d: liczba pozycji */
			$all_label = sprintf( __( 'Pokaż wszystkie (%d)', 'colorify-by-inyfinn' ), count( $items ) );
			$out      .= '<button type="button" class="cfb-showall" data-cfb-showall aria-expanded="false" data-all="' . esc_attr( $all_label ) . '" data-less="' . esc_attr__( 'Pokaż mniej', 'colorify-by-inyfinn' ) . '">' . esc_html( $all_label ) . '</button>';
		}
		return $out . '</section>';
	}

	/** Para z imieniem i resztą tytułu przypomnienia ("Imiona: co zrobić"). @return array{0:string,1:string} */
	private static function plan_split_title( string $title ): array {
		$pos = strpos( $title, ': ' );
		return false === $pos ? array( $title, '' ) : array( substr( $title, 0, $pos ), substr( $title, $pos + 2 ) );
	}

	/** Jeden wiersz listy (data, para, rodzaj, status). Wiersz otwiera kartę szczegółów. @param array<string,mixed> $e */
	private static function plan_li( array $e, DateTimeImmutable $today ): string {
		$type = self::plan_type_key( (string) $e['type'] );
		$ts   = strtotime( $e['date'] . ' 12:00:00' );
		$who  = self::plan_split_title( (string) $e['title'] )[0];
		$sub  = esc_html( self::plan_type_label( $type ) . ' · ' . wp_date( 'l', $ts ) . ' · ' . self::plan_rel( $e['date'], $today ) );
		return '<li><button type="button" class="cfb-li" data-ev="' . (int) $e['i'] . '" aria-haspopup="dialog">'
			. self::plan_date_badge( $e['date'] )
			. '<span class="cfb-li__main"><strong class="cfb-li__title">' . esc_html( $who ) . '</strong><span class="cfb-li__sub"><span class="cfb-tico cfb-tico--' . esc_attr( $type ) . '">' . self::icon_svg( self::plan_type_icon( $type ) ) . '</span>' . $sub . '</span></span>'
			. self::plan_pill( $e ) . '</button></li>';
	}

	/** Duży kafel: najbliższe wesele (albo wydarzenie) ze wszystkimi szczegółami, bez przewijania. @param array<string,mixed>|null $next */
	private static function plan_next_tile( ?array $next, array $all, DateTimeImmutable $today ): string {
		if ( ! $next ) {
			return '<section class="cfd-card cfb-next"><div class="cfb-next__in is-empty"><h2 class="cfb-tile__title">' . esc_html__( 'Najbliższe wydarzenie', 'colorify-by-inyfinn' ) . '</h2><p class="cfb-empty">' . esc_html__( 'Nic nie jest zaplanowane. Nowe zlecenia pojawią się tutaj.', 'colorify-by-inyfinn' ) . '</p></div></section>';
		}
		$p     = self::plan_payload( $next, $today );
		$title = 'wesele' === $p['type'] ? __( 'Najbliższe wesele', 'colorify-by-inyfinn' ) : __( 'Najbliższe wydarzenie', 'colorify-by-inyfinn' );
		$ts    = strtotime( $next['date'] . ' 12:00:00' );
		$out   = '<section class="cfd-card cfb-next" aria-label="' . esc_attr( $title ) . '"><div class="cfb-next__in"><div class="cfb-next__side">';
		$out  .= '<div class="cfb-tile__bar"><h2 class="cfb-tile__title">' . esc_html( $title ) . '</h2>' . self::plan_pill( $next ) . '</div>';
		$out  .= '<div class="cfb-next__hero"><span class="cfb-next__day"><b>' . esc_html( wp_date( 'j', $ts ) ) . '</b><i>' . esc_html( wp_date( 'M', $ts ) ) . '</i></span>'
			. '<span class="cfb-next__who"><strong>' . esc_html( $p['title'] ) . '</strong><span>' . esc_html( $p['long'] . ' · ' . $p['rel'] ) . '</span></span></div>';
		$out  .= '<div class="cfb-actions">'
			. '<button type="button" class="cfb-btn is-main" data-cfb-copy="' . (int) $next['i'] . '">' . self::icon_svg( 'copy' ) . '<span>' . esc_html__( 'Skopiuj informacje', 'colorify-by-inyfinn' ) . '</span></button>'
			. ( '' !== $p['url'] ? '<a class="cfb-btn" href="' . esc_url( $p['url'] ) . '">' . self::icon_svg( 'ext' ) . '<span>' . esc_html__( 'Otwórz zlecenie', 'colorify-by-inyfinn' ) . '</span></a>' : '' )
			. ( '' !== $p['phone'] ? '<a class="cfb-btn" href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $p['phone'] ) ) . '">' . self::icon_svg( 'phone' ) . '<span>' . esc_html__( 'Zadzwoń', 'colorify-by-inyfinn' ) . '</span></a>' : '' )
			. ( '' !== $p['route'] ? '<a class="cfb-btn" href="' . esc_url( $p['route'] ) . '" target="_blank" rel="noopener noreferrer">' . self::icon_svg( 'route' ) . '<span>' . esc_html__( 'Trasa', 'colorify-by-inyfinn' ) . '</span></a>' : '' )
			. '</div>';
		// Przypomnienia związane z tym zleceniem.
		$rem = array();
		if ( $next['id'] > 0 ) {
			foreach ( $all as $e ) {
				if ( $e['id'] === $next['id'] && 'wesele' !== $e['type'] && 'blokada' !== $e['type'] && ! $e['done'] && $e['i'] !== $next['i'] ) {
					$rem[] = $e;
				}
			}
		}
		if ( $rem ) {
			$out .= '<div class="cfb-rem"><p class="cfb-rem__t">' . esc_html__( 'Przy tym zleceniu', 'colorify-by-inyfinn' ) . '</p><ol class="cfb-li-list is-compact">';
			foreach ( array_slice( $rem, 0, 5 ) as $e ) {
				$t     = self::plan_type_key( (string) $e['type'] );
				$late  = $e['date'] < $today->format( 'Y-m-d' );
				$label = self::plan_split_title( (string) $e['title'] );
				$out  .= '<li><button type="button" class="cfb-li" data-ev="' . (int) $e['i'] . '" aria-haspopup="dialog"><span class="cfb-tico cfb-tico--' . esc_attr( $t ) . '">' . self::icon_svg( self::plan_type_icon( $t ) ) . '</span><span class="cfb-li__main"><strong class="cfb-li__title">' . esc_html( '' !== $label[1] ? $label[1] : $label[0] ) . '</strong><span class="cfb-li__sub' . ( $late ? ' is-late' : '' ) . '">' . esc_html( wp_date( 'l, j.m', strtotime( $e['date'] . ' 12:00:00' ) ) . ' · ' . self::plan_rel( $e['date'], $today ) ) . '</span></span></button></li>';
			}
			$out .= '</ol></div>';
		}
		$out .= '</div>'; // .cfb-next__side
		if ( $p['rows'] ) {
			$out .= '<dl class="cfb-dl cfb-next__dl">';
			foreach ( $p['rows'] as $r ) {
				$out .= self::plan_kv( $r[0], $r[1] );
			}
			$out .= '</dl>';
		}
		return $out . '</div></section>';
	}

	/** Dane, ikony i teksty dla skryptu; karta szczegółów jest jedna na stronie. */
	private static function plan_boot( string $month ): string {
		global $wp_locale;
		$today   = new DateTimeImmutable( 'today', wp_timezone() );
		$payload = array();
		$sets    = array(); // zestawy statusów do wyboru (zwykle jeden na wtyczkę), wydarzenie wskazuje indeks 'sx'
		$set_idx = array();
		foreach ( self::plan_all() as $e ) {
			$p = self::plan_payload( $e, $today );
			if ( $e['status_opts'] && $e['status_act'] && $e['id'] > 0 && 'blokada' !== $p['type'] ) {
				$key = md5( (string) wp_json_encode( array( $e['status_opts'], $e['status_act'] ) ) );
				if ( ! isset( $set_idx[ $key ] ) ) {
					$set_idx[ $key ] = count( $sets );
					$sets[]          = array( 'opts' => $e['status_opts'], 'action' => $e['status_act']['action'], 'nonce' => $e['status_act']['nonce'] );
				}
				$p['sx'] = $set_idx[ $key ];
			}
			$payload[] = $p;
		}
		$icons = array();
		foreach ( array( 'ring', 'phone', 'box', 'lock', 'bell', 'copy', 'ext', 'route', 'prev', 'next', 'close', 'check', 'chev', 'calendar', 'list' ) as $n ) {
			$icons[ $n ] = self::icon_svg( $n );
		}
		$months = array();
		$short  = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[] = $wp_locale->get_month( $m );
			$short[]  = $wp_locale->get_month_abbrev( $wp_locale->get_month( $m ) );
		}
		$dows = array();
		foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $wd ) {
			$dows[] = $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $wd ) );
		}
		$cfg = array(
			'today'  => $today->format( 'Y-m-d' ),
			'month'  => $month,
			'events' => $payload,
			'icons'  => $icons,
			'months' => $months,
			'short'  => $short,
			'dows'   => $dows,
			'base'   => admin_url( 'index.php' ),
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'statusSets' => $sets,
			'i18n'   => array(
				'prevMonth' => __( 'Poprzedni miesiąc', 'colorify-by-inyfinn' ),
				'nextMonth' => __( 'Następny miesiąc', 'colorify-by-inyfinn' ),
				'prevYear'  => __( 'Poprzedni rok', 'colorify-by-inyfinn' ),
				'nextYear'  => __( 'Następny rok', 'colorify-by-inyfinn' ),
				'year'      => __( 'Rok', 'colorify-by-inyfinn' ),
				'today'     => __( 'Dziś', 'colorify-by-inyfinn' ),
				'more'      => __( '+%d więcej', 'colorify-by-inyfinn' ),
				'copy'      => __( 'Skopiuj informacje', 'colorify-by-inyfinn' ),
				'copied'    => __( 'Skopiowano', 'colorify-by-inyfinn' ),
				'copyFail'  => __( 'Nie udało się skopiować', 'colorify-by-inyfinn' ),
				'open'      => __( 'Otwórz zlecenie', 'colorify-by-inyfinn' ),
				'call'      => __( 'Zadzwoń', 'colorify-by-inyfinn' ),
				'route'     => __( 'Trasa', 'colorify-by-inyfinn' ),
				'prev'      => __( 'Poprzednie', 'colorify-by-inyfinn' ),
				'next'      => __( 'Następne', 'colorify-by-inyfinn' ),
				'close'     => __( 'Zamknij', 'colorify-by-inyfinn' ),
				'details'   => __( 'Szczegóły wydarzenia', 'colorify-by-inyfinn' ),
				'pickLabel' => __( 'Wybierz miesiąc i rok', 'colorify-by-inyfinn' ),
				'hasEv'     => __( 'wydarzeń: %d', 'colorify-by-inyfinn' ),
				'less'      => __( 'Zwiń', 'colorify-by-inyfinn' ),
				'noEvents'  => __( 'W tym miesiącu nie ma wydarzeń.', 'colorify-by-inyfinn' ),
				'copyValue' => __( 'Skopiuj: %s', 'colorify-by-inyfinn' ),
				'stChange'  => __( 'Zmień status', 'colorify-by-inyfinn' ),
				'stSaved'   => __( 'Zapisano status: %s', 'colorify-by-inyfinn' ),
				'stFail'    => __( 'Nie udało się zapisać statusu. Spróbuj jeszcze raz.', 'colorify-by-inyfinn' ),
			),
		);
		wp_add_inline_script( 'colorify-planner', 'window.colorifyPlanner=' . wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';', 'before' );
		return '<div class="cfb-card" id="cfb-card" role="dialog" aria-modal="false" aria-label="' . esc_attr__( 'Szczegóły wydarzenia', 'colorify-by-inyfinn' ) . '" tabindex="-1" hidden></div><div class="cfb-live screen-reader-text" aria-live="polite"></div>';
	}

	private static function calendar( int $days ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$tz       = wp_timezone();
		$first    = self::plan_month();
		$today    = new DateTimeImmutable( 'today', $tz );
		$tstr     = $today->format( 'Y-m-d' );
		$last     = $first->modify( 'last day of this month' );
		$all      = self::plan_all();
		$provider = self::plan_has_provider();
		$in_month = array_values( array_filter( $all, static function ( $e ) use ( $first, $last ) {
			return $e['date'] >= $first->format( 'Y-m-d' ) && $e['date'] <= $last->format( 'Y-m-d' );
		} ) );

		$head  = self::head( __( 'Terminarz zleceń', 'colorify-by-inyfinn' ), 30, false );
		$strip = self::plan_strip( self::plan_kpis(), $in_month, (int) $last->format( 'j' ) );

		if ( ! $provider ) {
			return '<div class="cfb">' . $head . $strip . self::plan_hint() . '</div>';
		}

		// Kalendarz (siatkę miesiąca rysuje skrypt: miesiące i lata przełączają się bez przeładowania).
		$types = array();
		$stats = array();
		foreach ( $all as $e ) {
			$types[ self::plan_type_key( (string) $e['type'] ) ] = 1;
			if ( 'blokada' !== $e['type'] && '' !== $e['status'] && 'anulowane' !== $e['status'] ) {
				$stats[ self::plan_status_key( (string) $e['status'] ) ] = '' !== $e['status_label'] ? $e['status_label'] : self::plan_status( (string) $e['status'] )[0];
			}
		}
		$legend = '<ul class="cfb-legend" aria-label="' . esc_attr__( 'Legenda', 'colorify-by-inyfinn' ) . '">';
		foreach ( array( 'wesele', 'kontakt', 'oddanie', 'przygotowanie', 'blokada' ) as $t ) {
			if ( 'przygotowanie' === $t && empty( $types['przygotowanie'] ) ) {
				continue;
			}
			$legend .= '<li><span class="cfb-tico cfb-tico--' . esc_attr( $t ) . '">' . self::icon_svg( self::plan_type_icon( $t ) ) . '</span>' . esc_html( self::plan_type_label( $t ) ) . '</li>';
		}
		foreach ( $stats as $k => $label ) {
			$legend .= '<li><i class="cfb-dot cfb-dot--' . esc_attr( $k ) . '"></i>' . esc_html( $label ) . '</li>';
		}
		$legend .= '</ul>';
		$cal  = '<section class="cfd-card cfb-cal" aria-label="' . esc_attr__( 'Kalendarz', 'colorify-by-inyfinn' ) . '" data-cfb-cal>'
			. '<div class="cfb-cal__bar"><div class="cfb-cal__head"><button type="button" class="cfb-title" data-cfb-pick aria-haspopup="dialog" aria-expanded="false"><span data-cfb-label>' . esc_html( wp_date( 'F Y', $first->getTimestamp() ) ) . '</span>' . self::icon_svg( 'chev' ) . '</button><div class="cfb-pick" data-cfb-picker role="dialog" aria-label="' . esc_attr__( 'Wybierz miesiąc i rok', 'colorify-by-inyfinn' ) . '" hidden></div></div>'
			. '<div class="cfb-nav" role="group" aria-label="' . esc_attr__( 'Miesiąc', 'colorify-by-inyfinn' ) . '"><button type="button" class="cfb-nav__a" data-cfb-nav="-1" aria-label="' . esc_attr__( 'Poprzedni miesiąc', 'colorify-by-inyfinn' ) . '">' . self::icon_svg( 'prev' ) . '</button><button type="button" class="cfb-nav__a cfb-nav__today" data-cfb-today>' . esc_html__( 'Dziś', 'colorify-by-inyfinn' ) . '</button><button type="button" class="cfb-nav__a" data-cfb-nav="1" aria-label="' . esc_attr__( 'Następny miesiąc', 'colorify-by-inyfinn' ) . '">' . self::icon_svg( 'next' ) . '</button></div></div>'
			. '<div class="cfb-grid-wrap" data-cfb-grid></div>' . $legend
			. '<p class="cfb-keys">' . esc_html__( 'Strzałki ← → zmieniają miesiąc, Shift + strzałki rok. Najedź na wydarzenie albo kliknij je, żeby zobaczyć szczegóły.', 'colorify-by-inyfinn' ) . '</p>'
			. '</section>';

		// Najbliższe wesele (albo wydarzenie), od dziś.
		$ahead = array_values( array_filter( $all, static function ( $e ) use ( $tstr ) {
			return $e['date'] >= $tstr && 'blokada' !== $e['type'] && 'anulowane' !== $e['status'];
		} ) );
		$next  = null;
		foreach ( $ahead as $e ) {
			if ( 'wesele' === $e['type'] ) {
				$next = $e;
				break;
			}
		}
		$next = $next ?? ( $ahead[0] ?? null );

		// Najbliższe: zwięźle, data + para + rodzaj.
		$items = array();
		foreach ( array_slice( $ahead, 0, 40 ) as $e ) {
			$items[] = self::plan_li( $e, $today );
		}
		$tile_next = self::plan_list_tile( __( 'Najbliższe', 'colorify-by-inyfinn' ), '', $items, __( 'Nic nie jest zaplanowane na najbliższe miesiące.', 'colorify-by-inyfinn' ), 'cfb-upc' );

		// Do zrobienia w tym tygodniu: przypomnienia po terminie i na 7 dni do przodu.
		$week   = $today->modify( '+7 days' )->format( 'Y-m-d' );
		$todo   = array_values( array_filter( $all, static function ( $e ) use ( $tstr, $week ) {
			return in_array( $e['type'], array( 'kontakt', 'oddanie', 'przypomnienie', 'przygotowanie' ), true ) && ! $e['done'] && 'anulowane' !== $e['status'] && $e['date'] <= $week && $e['date'] >= gmdate( 'Y-m-d', strtotime( $tstr . ' -45 days' ) );
		} ) );
		$titems = array();
		foreach ( $todo as $e ) {
			$late     = $e['date'] < $tstr;
			$type     = self::plan_type_key( (string) $e['type'] );
			$ts       = strtotime( $e['date'] . ' 12:00:00' );
			$parts    = self::plan_split_title( (string) $e['title'] );
			$titems[] = '<li><button type="button" class="cfb-li" data-ev="' . (int) $e['i'] . '" aria-haspopup="dialog"><span class="cfb-tico cfb-tico--' . esc_attr( $type ) . ' is-lg">' . self::icon_svg( self::plan_type_icon( $type ) ) . '</span>'
				. '<span class="cfb-li__main"><strong class="cfb-li__title">' . esc_html( '' !== $parts[1] ? $parts[1] : $parts[0] ) . '</strong><span class="cfb-li__sub' . ( $late ? ' is-late' : '' ) . '">' . esc_html( ( '' !== $parts[1] ? $parts[0] . ' · ' : '' ) . wp_date( 'l, j.m', $ts ) . ' · ' . self::plan_rel( $e['date'], $today ) ) . '</span></span></button></li>';
		}
		$tile_todo = self::plan_list_tile( __( 'Do zrobienia w tym tygodniu', 'colorify-by-inyfinn' ), $todo ? self::num( count( $todo ) ) : '', $titems, __( 'Nic nie czeka. Spokojny tydzień.', 'colorify-by-inyfinn' ), 'cfb-todo', 5 );

		// Zadatki do wpłaty: umowa podpisana, zadatku jeszcze nie ma. Kafel tylko wtedy, gdy wtyczka zna takie etapy.
		$tile_dep = '';
		if ( isset( $stats['umowa'] ) || isset( $stats['zadatek'] ) ) {
			$dep  = array_values( array_filter( $ahead, static function ( $e ) {
				return 'wesele' === $e['type'] && 'umowa' === $e['status'];
			} ) );
			$sum  = 0;
			$ditm = array();
			foreach ( $dep as $e ) {
				$sum    += max( 0, (int) $e['amount'] );
				$ts      = strtotime( $e['date'] . ' 12:00:00' );
				$ditm[]  = '<li><button type="button" class="cfb-li" data-ev="' . (int) $e['i'] . '" aria-haspopup="dialog">' . self::plan_date_badge( $e['date'] )
					. '<span class="cfb-li__main"><strong class="cfb-li__title">' . esc_html( $e['title'] ) . '</strong><span class="cfb-li__sub">' . esc_html( wp_date( 'l, j.m', $ts ) . ' · ' . self::plan_rel( $e['date'], $today ) ) . '</span></span>'
					. ( null !== $e['amount'] && $e['amount'] > 0 ? '<span class="cfb-li__amt">' . esc_html( self::plan_money( (float) $e['amount'] ) ) . '</span>' : '' ) . '</button></li>';
			}
			$tile_dep = self::plan_list_tile( __( 'Zadatki do wpłaty', 'colorify-by-inyfinn' ), $sum > 0 ? self::plan_money( (float) $sum ) : '', $ditm, __( 'Wszystkie zadatki są wpłacone.', 'colorify-by-inyfinn' ), 'cfb-dep' );
		}

		$boot = self::plan_boot( $first->format( 'Y-m' ) );

		// Bento (CSS grid z nazwanymi obszarami, układ zależy od szerokości): liczby, kalendarz, najbliższe wesele, do zrobienia,
		// najbliższe, zadatki. Kolejność w kodzie = kolejność na telefonie (najbliższe wesele i zadania przed kalendarzem).
		return '<div class="cfb" data-cfb>' . $head
			. '<div class="cfb-bento' . ( '' === $tile_dep ? ' no-dep' : '' ) . '">' . $strip . self::plan_next_tile( $next, $all, $today ) . $tile_todo . $cal . $tile_next . $tile_dep . '</div>'
			. $boot . '</div>';
	}

	/**
	 * Kafel z listą nadchodzących wydarzeń (styl Rezerwacje). Wiersz otwiera kartę szczegółów.
	 *
	 * @param array<int,array<string,mixed>> $events Wydarzenia z plan_all().
	 */
	private static function plan_upcoming( string $title, array $events, string $empty ): string {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$items = array();
		foreach ( $events as $e ) {
			$items[] = self::plan_li( $e, $today );
		}
		return self::plan_list_tile( $title, '', $items, $empty );
	}


	/* ===================== Motyw: Rezerwacje ===================== */

	private static function bookings( int $days ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$year0 = $today->modify( 'first day of january this year' );
		$all   = self::plan_all();
		$work  = array_values( array_filter( $all, static function ( $e ) use ( $year0 ) {
			return $e['date'] >= $year0->format( 'Y-m-d' ) && ! in_array( $e['type'], array( 'blokada', 'kontakt', 'oddanie', 'przypomnienie', 'przygotowanie' ), true );
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
		return $out . self::plan_boot( $today->format( 'Y-m' ) );
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


	/* ===================== Wygląd i skrypt ===================== */

	/** Arkusz i skrypt trzech styli planera (kalendarz, rezerwacje, portfolio); dane skryptu dokłada plan_boot(). */
	public static function planner_assets(): void {
		wp_enqueue_style( 'colorify-planner', COLORIFY_PLUGIN_URL . 'assets/colorify-planner.css', array(), COLORIFY_PLUGIN_VERSION );
		wp_enqueue_script( 'colorify-planner', COLORIFY_PLUGIN_URL . 'assets/colorify-planner.js', array(), COLORIFY_PLUGIN_VERSION, true );
	}
}
