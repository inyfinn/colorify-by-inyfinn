<?php
/**
 * Styl kokpitu: Klasyczny (WordPress) albo jeden z czterech motywów: Sprzedaż, Zapytania, Redakcja, Firma.
 *
 * Motyw zastępuje widżety kokpitu jednym ekranem: pasek skrótów, karta "do zrobienia", liczby, listy.
 * Wygląd: jasne zaokrąglone karty bez ramek (kolory ze schematu Colorify). Pierwowzór: kokpit sprzedażowy
 * etainnovations.com (klient: "fajnie, bo poprzednio, żeby dojść do zamówień, trzeba było naklikać").
 * Motywy zaprojektowane z ChatGPT 20.10.2026: kafelek pokazuje tylko dane, które da się policzyć lokalnie;
 * gdy źródła nie ma, kafelek znika albo mówi to wprost, nigdy nie udaje zera.
 *
 * Licznik wejść: własny, bez ciasteczek (goście, bez botów; unikalny = skrót IP + przeglądarki w danym dniu).
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

final class Colorify_Dashboard {

	use Colorify_Dashboard_Planner;

	const OPTION     = 'colorify_dashboard_style';
	const VISITS     = 'colorify_visits';
	const MISSING    = 'colorify_404';
	const KEEP_DAYS  = 400;
	const PAGES_DAY  = 200;
	const PAID       = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );
	const STYLES     = array( 'classic', 'sales', 'leads', 'editorial', 'company', 'calendar', 'bookings', 'portfolio' );

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'note_404' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'count_visit' ), 99 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'setup' ), PHP_INT_MAX );
		add_action( 'current_screen', array( __CLASS__, 'quiet_screen' ) );
	}

	public static function style(): string {
		$s = (string) get_option( self::OPTION, 'classic' );
		return in_array( $s, self::STYLES, true ) ? $s : 'classic';
	}

	/** @return array<string,array{0:string,1:string}> klucz => [nazwa, opis] */
	public static function labels(): array {
		return array(
			'classic'   => array( __( 'Klasyczny', 'colorify-by-inyfinn' ), __( 'Kokpit WordPressa z widżetami.', 'colorify-by-inyfinn' ) ),
			'sales'     => array( __( 'Sprzedaż', 'colorify-by-inyfinn' ), __( 'Sklep: przychód, zamówienia do realizacji, płatności, magazyn.', 'colorify-by-inyfinn' ) ),
			'leads'     => array( __( 'Zapytania', 'colorify-by-inyfinn' ), __( 'Usługi: wiadomości z formularzy, popularne podstrony.', 'colorify-by-inyfinn' ) ),
			'editorial' => array( __( 'Redakcja', 'colorify-by-inyfinn' ), __( 'Blog: komentarze, zaplanowane wpisy, szkice, czytane.', 'colorify-by-inyfinn' ) ),
			'company'   => array( __( 'Firma', 'colorify-by-inyfinn' ), __( 'Wizytówka: kondycja strony, aktualizacje, tłumaczenia.', 'colorify-by-inyfinn' ) ),
			'calendar'  => array( __( 'Kalendarz zleceń', 'colorify-by-inyfinn' ), __( 'Terminarz: miesiąc z wydarzeniami, najbliższe zlecenia, liczby (dane podaje wtyczka zleceń).', 'colorify-by-inyfinn' ) ),
			'bookings'  => array( __( 'Rezerwacje', 'colorify-by-inyfinn' ), __( 'Usługi na zamówienie: etapy zleceń, wartość, najbliższy termin (dane podaje wtyczka zleceń).', 'colorify-by-inyfinn' ) ),
			'portfolio' => array( __( 'Portfolio', 'colorify-by-inyfinn' ), __( 'Twórca: ostatnio dodane zdjęcia, strony, wejścia.', 'colorify-by-inyfinn' ) ),
		);
	}

	/** Motyw, który ten użytkownik naprawdę zobaczy (bez uprawnień do danych: klasyczny). */
	private static function effective(): string {
		$s = self::style();
		if ( 'classic' === $s || ! current_user_can( 'edit_posts' ) ) {
			return 'classic';
		}
		if ( 'sales' === $s && ( ! function_exists( 'wc_get_orders' ) || ! current_user_can( 'manage_woocommerce' ) ) ) {
			return 'classic';
		}
		if ( in_array( $s, array( 'leads', 'company', 'calendar', 'bookings' ), true ) && ! current_user_can( 'manage_options' ) ) {
			return 'classic';
		}
		if ( 'portfolio' === $s && ! current_user_can( 'upload_files' ) ) {
			return 'classic';
		}
		return $s;
	}

	/* ===================== Licznik wejść ===================== */

	/** Czy to wejście ma być liczone (człowiek, zwykłe GET, nie panel). */
	private static function countable(): bool {
		if ( is_user_logged_in() || is_admin() || wp_doing_ajax() || is_feed() || is_preview() || is_robots() ) {
			return false;
		}
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return false;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return '' !== $ua && ! preg_match( '/bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|curl|wget|python|monitor|uptime|^Mozilla\/5\.0$/i', $ua );
	}

	/** @var bool Ten request to 404 (zapamiętane przed motywem, który potrafi podmienić 404 na zwykłą stronę). */
	private static $was_404 = false;

	/**
	 * Adresy, pod które ludzie wchodzą i dostają 404: ścieżka, ile razy, skąd przyszli (ostatni Referer), kiedy ostatnio.
	 * Priorytet 0: przed motywami, które przy 404 pokazują własną stronę i ukrywają is_404().
	 */
	public static function note_404(): void {
		if ( ! is_404() ) {
			return;
		}
		self::$was_404 = true;
		if ( ! self::countable() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = substr( rawurldecode( $path ), 0, 200 );
		// Skanery szukające plików (.php, .env, kopie) to nie ludzie: pomijamy.
		if ( '' === $path || preg_match( '#\.(php|env|git|sql|bak|zip|ini|log|key|pem|ya?ml|asp|aspx)(/|$)|/wp-(content|includes|admin)/|/\.#i', $path ) ) {
			return;
		}
		$ref  = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_parse_url( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$all  = get_option( self::MISSING, array() );
		$all  = is_array( $all ) ? $all : array();
		$row  = $all[ $path ] ?? array( 'n' => 0 );
		$row  = array( 'n' => (int) $row['n'] + 1, 't' => time(), 'r' => '' !== $ref ? $ref : ( $row['r'] ?? '' ) );
		$all[ $path ] = $row;
		if ( count( $all ) > 150 ) { // ponytail: trzymamy 100 najświeższych adresów; dłuższa historia w logach serwera.
			uasort( $all, static fn( $a, $b ) => $b['t'] <=> $a['t'] );
			$all = array_slice( $all, 0, 100, true );
		}
		update_option( self::MISSING, $all, false );
	}

	/**
	 * Wiersze karty "Adresy, które nie istnieją" z ostatnich $days dni.
	 *
	 * @return array<int,array<int,string>>
	 */
	private static function missing_rows( int $days, int $n = 5 ): array {
		$all  = get_option( self::MISSING, array() );
		$from = time() - $days * DAY_IN_SECONDS;
		$all  = array_filter( is_array( $all ) ? $all : array(), static fn( $r ) => (int) ( $r['t'] ?? 0 ) >= $from );
		uasort( $all, static fn( $a, $b ) => $b['n'] <=> $a['n'] );
		$rows = array();
		foreach ( array_slice( $all, 0, $n, true ) as $path => $r ) {
			$rows[] = array( (string) $path, home_url( (string) $path ), self::num( (float) $r['n'] ) . '×', '' !== ( $r['r'] ?? '' ) ? sprintf( /* translators: %s: domena */ __( 'Skąd: %s', 'colorify-by-inyfinn' ), $r['r'] ) : __( 'Wejście bez odnośnika (zakładka, stary link, wpisany adres)', 'colorify-by-inyfinn' ) );
		}
		return $rows;
	}

	public static function count_visit(): void {
		// 404 nie jest wejściem na stronę, także gdy motyw pokazał w jego miejscu własną stronę "Page 404".
		if ( self::$was_404 || is_404() || ! self::countable() ) {
			return;
		}
		$ua = (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$day  = wp_date( 'Y-m-d' );
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$hash = substr( md5( $ip . '|' . $ua . '|' . $day . '|' . wp_salt( 'nonce' ) ), 0, 12 );

		// ponytail: odczyt-zapis opcji bez blokady; przy dużym ruchu zgubi pojedyncze wejścia, wtedy osobna tabela z INSERT.
		$seen_key = 'colorify_uv_' . $day;
		$seen     = get_transient( $seen_key );
		$seen     = is_array( $seen ) ? $seen : array();
		$is_new   = ! isset( $seen[ $hash ] );
		if ( $is_new ) {
			$seen[ $hash ] = 1;
			set_transient( $seen_key, $seen, 2 * DAY_IN_SECONDS );
		}

		$all = self::visits();
		if ( ! isset( $all[ $day ] ) ) {
			$all[ $day ] = array( 'v' => 0, 'u' => 0, 'p' => array() );
			$cut         = wp_date( 'Y-m-d', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
			foreach ( array_keys( $all ) as $d ) {
				if ( $d < $cut ) {
					unset( $all[ $d ] );
				}
			}
		}
		++$all[ $day ]['v'];
		if ( $is_new ) {
			++$all[ $day ]['u'];
		}
		$pid = (int) get_queried_object_id();
		if ( $pid > 0 && ( is_singular() || is_front_page() ) ) {
			$p = isset( $all[ $day ]['p'] ) && is_array( $all[ $day ]['p'] ) ? $all[ $day ]['p'] : array();
			if ( isset( $p[ $pid ] ) || count( $p ) < self::PAGES_DAY ) {
				$p[ $pid ] = ( $p[ $pid ] ?? 0 ) + 1;
			}
			$all[ $day ]['p'] = $p;
		}
		update_option( self::VISITS, $all, false );
	}

	/** @return array<string,array{v:int,u:int,p?:array<int,int>}> */
	private static function visits(): array {
		$all = get_option( self::VISITS, null );
		if ( null === $all ) {
			// Przejęcie licznika z dawnego kokpitu ETA (eta-dashboard.php), żeby historia nie zaczynała się od zera.
			$old = get_option( 'eta_dash_visits', array() );
			$all = is_array( $old ) ? $old : array();
			update_option( self::VISITS, $all, false );
		}
		return is_array( $all ) ? $all : array();
	}

	/**
	 * @return array{views:int,visitors:int,pages:array<int,int>,first:string}
	 */
	private static function traffic( string $from, string $to ): array {
		$v = 0;
		$u = 0;
		$p = array();
		$all = self::visits();
		foreach ( $all as $d => $row ) {
			if ( $d >= $from && $d < $to ) {
				$v += (int) ( $row['v'] ?? 0 );
				$u += (int) ( $row['u'] ?? 0 );
				foreach ( (array) ( $row['p'] ?? array() ) as $id => $n ) {
					$p[ (int) $id ] = ( $p[ (int) $id ] ?? 0 ) + (int) $n;
				}
			}
		}
		arsort( $p );
		return array( 'views' => $v, 'visitors' => $u, 'pages' => $p, 'first' => $all ? (string) min( array_keys( $all ) ) : '' );
	}

	/* ===================== Ekran kokpitu ===================== */

	public static function setup(): void {
		if ( 'classic' === self::effective() ) {
			return;
		}
		global $wp_meta_boxes;
		$wp_meta_boxes['dashboard'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		remove_action( 'welcome_panel', 'wp_welcome_panel' );
		wp_add_dashboard_widget( 'colorify_dashboard', __( 'Kokpit', 'colorify-by-inyfinn' ), array( __CLASS__, 'render' ) );
	}

	public static function quiet_screen( $screen ): void {
		if ( ! $screen || 'dashboard' !== $screen->id || 'classic' === self::effective() ) {
			return;
		}
		add_filter( 'screen_layout_columns', static function ( $c ) { $c['dashboard'] = 1; return $c; } );
		add_filter( 'get_user_option_screen_layout_dashboard', static function () { return 1; } );
		add_filter( 'screen_options_show_screen', '__return_false' );
		add_action( 'admin_head', array( __CLASS__, 'css' ) );
		add_action( 'admin_head', array( __CLASS__, 'planner_css' ) );
	}

	/* ---------- Drobne elementy widoku ---------- */

	private static function num( float $v, int $dec = 0 ): string {
		return number_format( $v, $dec, ',', "\u{00A0}" );
	}

	private static function money( float $v ): string {
		$cur = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '';
		return self::num( $v, ( $v < 1000 && floor( $v ) !== $v ) ? 2 : 0 ) . ( '' !== $cur ? "\u{00A0}" . $cur : '' );
	}

	/** Zmiana w %; bez poprzedniego okresu: nic (nie "+∞%"). */
	private static function delta( float $cur, float $prev ): string {
		if ( $prev <= 0 ) {
			return '';
		}
		$pct = ( $cur - $prev ) / $prev * 100;
		$cls = $pct > 0.5 ? 'up' : ( $pct < -0.5 ? 'down' : 'flat' );
		$arr = 'up' === $cls ? '↗' : ( 'down' === $cls ? '↘' : '' );
		return '<span class="cfd-pill cfd-pill--' . $cls . '" title="' . esc_attr__( 'w porównaniu z poprzednim okresem', 'colorify-by-inyfinn' ) . '">' . esc_html( self::num( abs( $pct ) ) . '% ' . $arr ) . '</span>';
	}

	private static function icon( string $name ): string {
		$paths = array(
			'cash'    => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
			'bag'     => '<path d="M6 8h12l-1 12H7L6 8z"/><path d="M9 8a3 3 0 0 1 6 0"/>',
			'box'     => '<path d="M3 7l9-4 9 4v10l-9 4-9-4V7z"/><path d="M3 7l9 4 9-4M12 11v10"/>',
			'avg'     => '<path d="M4 19h16"/><path d="M6 15l4-4 3 3 5-6"/>',
			'eye'     => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
			'conv'    => '<path d="M4 4h16l-6 8v6l-4 2v-8L4 4z"/>',
			'pages'   => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
			'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
			'edit'    => '<path d="M4 20h4L19 9l-4-4L4 16v4z"/><path d="M13 7l4 4"/>',
			'comment' => '<path d="M4 5h16v11H9l-5 4V5z"/>',
			'clock'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
			'shield'  => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
			'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-3M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.9 3M20 20v-4h-4"/>',
			'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.5 3 14.5 0 18M12 3c-3 3.5-3 14.5 0 18"/>',
			'plus'    => '<path d="M12 5v14M5 12h14"/>',
			'list'    => '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
			'card'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
			'alert'   => '<path d="M12 4l9 16H3L12 4z"/><path d="M12 10v4M12 17h.01"/>',
			'file'    => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/>',
			'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
			'pin'     => '<path d="M12 21s7-6.2 7-11a7 7 0 0 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
			'image'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-5-5-8 8"/>',
			'film'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 5v14M16 5v14M3 10h5M3 14h5M16 10h5M16 14h5"/>',
		);
		return '<span class="cfd-ico cfd-ico--' . esc_attr( $name ) . '" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ( $paths[ $name ] ?? '' ) . '</svg></span>';
	}

	/** "1 wyświetlenie", "3 wyświetlenia", "12 wyświetleń" (polska odmiana bez pliku tłumaczeń). */
	private static function views_label( int $n ): string {
		$f = ( 1 === $n ) ? 'wyświetlenie' : ( ( $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 12 || $n % 100 > 14 ) ) ? 'wyświetlenia' : 'wyświetleń' );
		return self::num( (float) $n ) . ' ' . $f;
	}

	/** Wartość to tekst (np. tytuł strony), a nie liczba lub kwota: dostaje mniejsze pismo w dwóch liniach. */
	private static function is_text_value( string $value ): bool {
		$v = trim( wp_strip_all_tags( $value ) );
		return ! preg_match( '/^[-+]?\d/u', $v ) && (bool) preg_match( '/\p{L}{2}/u', $v );
	}

	/** Jedna liczba w pasku statystyk; z $href cała pozycja jest linkiem. */
	private static function stat( string $icon, string $label, string $value, string $delta = '', string $hint = '', string $href = '' ): string {
		$inner = '<span class="cfd-stat__top">' . self::icon( $icon ) . '<span class="cfd-stat__label">' . esc_html( $label ) . '</span></span>'
			. '<span class="cfd-stat__row"><span class="cfd-stat__value' . ( self::is_text_value( $value ) ? ' cfd-stat__value--text' : '' ) . '">' . $value . '</span>' . $delta . '</span>'
			. ( '' !== $hint ? '<span class="cfd-stat__hint">' . esc_html( $hint ) . '</span>' : '' );
		return '' !== $href ? '<a class="cfd-stat cfd-stat--link" href="' . esc_url( $href ) . '">' . $inner . '</a>' : '<div class="cfd-stat">' . $inner . '</div>';
	}

	/** @param array<int,array{0:string,1:string,2:string}> $items ikona, etykieta, adres */
	private static function shortcuts( array $items ): string {
		$out = '<nav class="cfd-quick" aria-label="' . esc_attr__( 'Skróty', 'colorify-by-inyfinn' ) . '">';
		foreach ( $items as $i => $it ) {
			$out .= '<a class="cfd-quick__a' . ( 0 === $i ? ' is-main' : '' ) . '" href="' . esc_url( $it[2] ) . '">' . self::icon( $it[0] ) . '<span>' . esc_html( $it[1] ) . '</span></a>';
		}
		return $out . '</nav>';
	}

	/** Karta akcentowa "do zrobienia". @param array<int,string> $lines */
	private static function promo( string $tag, string $title, array $lines, string $btn, string $href, bool $calm = false ): string {
		$out = '<section class="cfd-card cfd-promo' . ( $calm ? ' cfd-promo--calm' : '' ) . '"><span class="cfd-promo__tag">' . esc_html( $tag ) . '</span>'
			. '<p class="cfd-promo__title">' . esc_html( $title ) . '</p>';
		foreach ( $lines as $l ) {
			$out .= '<p class="cfd-promo__text">' . esc_html( $l ) . '</p>';
		}
		if ( '' !== $btn ) {
			$out .= '<a class="cfd-promo__btn" href="' . esc_url( $href ) . '">' . esc_html( $btn ) . '</a>';
		}
		return $out . '</section>';
	}

	/**
	 * Karta z listą wierszy.
	 *
	 * @param array<int,array{0:string,1:string,2?:string,3?:string}> $rows tytuł, adres, prawa kolumna, podtytuł
	 */
	private static function list_card( string $title, array $rows, string $empty, string $more_label = '', string $more_href = '', bool $numbered = false ): string {
		$out = '<section class="cfd-card"><div class="cfd-card__bar"><p class="cfd-card__title">' . esc_html( $title ) . '</p>'
			. ( '' !== $more_href ? '<a class="cfd-link" href="' . esc_url( $more_href ) . '">' . esc_html( $more_label ) . '</a>' : '' ) . '</div>';
		if ( ! $rows ) {
			return $out . '<p class="cfd-empty">' . esc_html( $empty ) . '</p></section>';
		}
		$out .= '<ol class="cfd-rows' . ( $numbered ? ' is-numbered' : '' ) . '">';
		foreach ( $rows as $r ) {
			$sub  = isset( $r[3] ) && '' !== $r[3] ? '<span class="cfd-rows__sub">' . esc_html( $r[3] ) . '</span>' : '';
			$out .= '<li><a href="' . esc_url( $r[1] ) . '"><span class="cfd-rows__main">' . esc_html( $r[0] ) . '</span>' . $sub . '</a>'
				. ( isset( $r[2] ) && '' !== $r[2] ? '<span class="cfd-rows__aside">' . esc_html( $r[2] ) . '</span>' : '' ) . '</li>';
		}
		return $out . '</ol></section>';
	}

	/** @return array{0:string,1:string,2:string} od, do (bez), poprzedni-od, w Y-m-d */
	private static function range( int $days ): array {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$from  = $today->modify( '-' . ( $days - 1 ) . ' days' );
		return array( $from->format( 'Y-m-d' ), $today->modify( '+1 day' )->format( 'Y-m-d' ), $from->modify( '-' . $days . ' days' )->format( 'Y-m-d' ) );
	}

	private static function head( string $sub, int $days, bool $range = true ): string {
		$out = '<div class="cfd-head"><p class="cfd-head__sub">' . esc_html( $sub ) . '</p>';
		if ( $range ) {
			$out .= '<nav class="cfd-range" aria-label="' . esc_attr__( 'Okres', 'colorify-by-inyfinn' ) . '">';
			foreach ( array( 7, 30, 90 ) as $d ) {
				$out .= '<a href="' . esc_url( add_query_arg( 'cfd_days', $d, admin_url( 'index.php' ) ) ) . '"' . ( $d === $days ? ' aria-current="page"' : '' ) . '>' . (int) $d . ' ' . esc_html__( 'dni', 'colorify-by-inyfinn' ) . '</a>';
			}
			$out .= '</nav>';
		}
		return $out . '</div>';
	}

	/** Pasek ruchu (wspólny dla motywów). */
	private static function traffic_strip( array $cur, array $prev, int $days, int $orders = -1 ): string {
		$tracking = '' !== $cur['first'];
		$partial  = $tracking && $cur['first'] > wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		/* translators: %s: data */
		$hint  = $tracking ? ( $partial ? sprintf( __( 'Licznik od %s', 'colorify-by-inyfinn' ), wp_date( 'j.m', strtotime( $cur['first'] ) ) ) : '' ) : __( 'Licznik zaczyna od dziś', 'colorify-by-inyfinn' );
		$cmp   = $tracking && ! $partial;
		$out   = '<section class="cfd-card cfd-strip" aria-label="' . esc_attr__( 'Ruch na stronie', 'colorify-by-inyfinn' ) . '">';
		$out  .= self::stat( 'eye', __( 'Wejścia na stronę', 'colorify-by-inyfinn' ), esc_html( self::num( $cur['views'] ) ), $cmp ? self::delta( $cur['views'], $prev['views'] ) : '', $hint );
		$out  .= self::stat( 'user', __( 'Unikalni odwiedzający', 'colorify-by-inyfinn' ), esc_html( self::num( $cur['visitors'] ) ), $cmp ? self::delta( $cur['visitors'], $prev['visitors'] ) : '' );
		if ( $orders >= 0 ) {
			$conv = ( $cmp && $cur['visitors'] > 0 ) ? $orders / $cur['visitors'] * 100 : -1;
			$out .= self::stat( 'conv', __( 'Konwersja', 'colorify-by-inyfinn' ), $conv >= 0 ? esc_html( self::num( $conv, 1 ) . '%' ) : '-', '', $conv >= 0 ? __( 'Zamówienia na 100 odwiedzających', 'colorify-by-inyfinn' ) : __( 'Gdy licznik obejmie cały okres', 'colorify-by-inyfinn' ) );
		}
		$top  = array_slice( $cur['pages'], 0, 1, true );
		$tid  = (int) key( $top );
		$out .= self::stat( 'pages', __( 'Najczęściej oglądana', 'colorify-by-inyfinn' ), $tid ? esc_html( get_the_title( $tid ) ) : '-', '', $tid ? self::views_label( (int) reset( $top ) ) : '', $tid ? (string) get_edit_post_link( $tid, 'raw' ) : '' );
		$out .= '</section>';
		$miss = self::missing_rows( $days );
		if ( $miss ) {
			$out .= self::list_card( __( 'Adresy, które nie istnieją (404)', 'colorify-by-inyfinn' ), $miss, '' );
		}
		return $out;
	}

	/** @param array<int,int> $pages */
	private static function top_pages_rows( array $pages, int $n = 5 ): array {
		$rows = array();
		foreach ( array_slice( $pages, 0, $n, true ) as $id => $views ) {
			$title = get_the_title( (int) $id );
			if ( '' === $title ) {
				continue;
			}
			/* translators: %s: liczba */
			$rows[] = array( $title, (string) get_edit_post_link( (int) $id, 'raw' ), sprintf( __( '%s wyśw.', 'colorify-by-inyfinn' ), self::num( (float) $views ) ) );
		}
		return $rows;
	}

	/* ===================== Render ===================== */

	public static function render(): void {
		$days = isset( $_GET['cfd_days'] ) ? absint( $_GET['cfd_days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$m    = self::effective();
		echo '<div class="cfd cfd--' . esc_attr( $m ) . '">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- elementy składane z wartości po esc_*.
		switch ( $m ) {
			case 'sales':
				echo self::sales( $days );
				break;
			case 'leads':
				echo self::leads( $days );
				break;
			case 'editorial':
				echo self::editorial( $days );
				break;
			case 'company':
				echo self::company( $days );
				break;
			case 'calendar':
				echo self::calendar( $days );
				break;
			case 'bookings':
				echo self::bookings( $days );
				break;
			case 'portfolio':
				echo self::portfolio( $days );
				break;
		}
		// phpcs:enable
		echo '<p class="cfd-note">' . esc_html__( 'Dane odświeżają się co 5 minut. Styl kokpitu zmienisz w Ustawienia, Colorify.', 'colorify-by-inyfinn' ) . '</p></div>';
	}

	/* ---------- Motyw: Sprzedaż ---------- */

	private static function sales( int $days ): string {
		global $wpdb;
		$p       = $wpdb->prefix;
		$in      = "'" . implode( "','", self::PAID ) . "'";
		list( $from, $to, $prevf ) = self::range( $days );
		$orders_url = function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		$has_stats  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'wc_order_stats' ) );

		$key   = 'colorify_dash_sales_' . $days;
		$s     = get_transient( $key );
		if ( ! is_array( $s ) ) {
			$period = static function ( string $a, string $b ) use ( $wpdb, $p, $in, $has_stats ): array {
				if ( ! $has_stats ) {
					return array( 'orders' => 0, 'revenue' => 0, 'items' => 0 );
				}
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) orders, COALESCE(SUM(net_total),0) revenue, COALESCE(SUM(num_items_sold),0) items FROM {$p}wc_order_stats WHERE parent_id = 0 AND status IN ($in) AND date_created >= %s AND date_created < %s", $a . ' 00:00:00', $b . ' 00:00:00' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array_map( 'floatval', (array) $row );
			};
			$daily = array();
			for ( $d = $from; $d < $to; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
				$daily[ $d ] = 0.0;
			}
			if ( $has_stats ) {
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT DATE(date_created) d, SUM(net_total) s FROM {$p}wc_order_stats WHERE parent_id = 0 AND status IN ($in) AND date_created >= %s AND date_created < %s GROUP BY DATE(date_created)", $from . ' 00:00:00', $to . ' 00:00:00' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$daily[ $r['d'] ] = (float) $r['s'];
				}
			}
			$top = $has_stats ? (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.product_id, SUM(l.product_qty) qty FROM {$p}wc_order_product_lookup l JOIN {$p}wc_order_stats s ON s.order_id = l.order_id WHERE s.status IN ($in) AND l.date_created >= %s AND l.date_created < %s GROUP BY l.product_id ORDER BY qty DESC LIMIT 5", $from . ' 00:00:00', $to . ' 00:00:00' ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$todo    = count( wc_get_orders( array( 'status' => array( 'processing', 'on-hold' ), 'limit' => 200, 'return' => 'ids', 'type' => 'shop_order' ) ) );
			$failed  = count( wc_get_orders( array( 'status' => array( 'failed' ), 'limit' => 200, 'return' => 'ids', 'type' => 'shop_order', 'date_created' => '>=' . strtotime( $from ) ) ) );
			$pending = count( wc_get_orders( array( 'status' => array( 'pending' ), 'limit' => 200, 'return' => 'ids', 'type' => 'shop_order', 'date_created' => '<' . ( time() - DAY_IN_SECONDS ) ) ) );
			$low_amt = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
			$stock   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.product_id, l.stock_quantity, l.stock_status FROM {$p}wc_product_meta_lookup l JOIN {$wpdb->posts} po ON po.ID = l.product_id AND po.post_status = 'publish' WHERE l.stock_status = 'outofstock' OR (l.stock_quantity IS NOT NULL AND l.stock_quantity <= %d) ORDER BY l.stock_quantity ASC LIMIT 4", $low_amt ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$invoices = 0;
			if ( class_exists( 'ETA_Checkout_Invoice' ) ) {
				foreach ( wc_get_orders( array( 'status' => array( 'processing', 'on-hold' ), 'limit' => 200, 'type' => 'shop_order' ) ) as $o ) {
					$invoices += ! empty( ETA_Checkout_Invoice::get_invoice_data( $o )['invoice'] ) ? 1 : 0;
				}
			}
			$s = array(
				'cur' => $period( $from, $to ), 'prev' => $period( $prevf, $from ), 'daily' => $daily, 'top' => $top,
				'todo' => $todo, 'failed' => $failed, 'pending' => $pending, 'stock' => $stock, 'invoices' => $invoices,
			);
			set_transient( $key, $s, 5 * MINUTE_IN_SECONDS );
		}
		$c   = $s['cur'];
		$pv  = $s['prev'];
		$aov = $c['orders'] > 0 ? $c['revenue'] / $c['orders'] : 0;
		$aop = $pv['orders'] > 0 ? $pv['revenue'] / $pv['orders'] : 0;

		/* translators: %d: liczba dni */
		$out  = self::head( sprintf( __( 'Ostatnie %d dni', 'colorify-by-inyfinn' ), $days ), $days );
		$out .= self::shortcuts(
			array(
				array( 'list', __( 'Zamówienia', 'colorify-by-inyfinn' ), $orders_url ),
				array( 'plus', __( 'Dodaj produkt', 'colorify-by-inyfinn' ), admin_url( 'post-new.php?post_type=product' ) ),
				array( 'box', __( 'Produkty', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=product' ) ),
				array( 'avg', __( 'Raporty', 'colorify-by-inyfinn' ), admin_url( 'admin.php?page=wc-admin&path=/analytics/overview' ) ),
			)
		);

		$out .= '<section class="cfd-card cfd-strip" aria-label="' . esc_attr__( 'Sprzedaż', 'colorify-by-inyfinn' ) . '">';
		$out .= self::stat( 'cash', __( 'Przychód netto', 'colorify-by-inyfinn' ), esc_html( self::money( $c['revenue'] ) ), self::delta( $c['revenue'], $pv['revenue'] ), '', admin_url( 'admin.php?page=wc-admin&path=/analytics/revenue' ) );
		$out .= self::stat( 'bag', __( 'Zamówienia', 'colorify-by-inyfinn' ), esc_html( self::num( $c['orders'] ) ), self::delta( $c['orders'], $pv['orders'] ), '', $orders_url );
		$out .= self::stat( 'box', __( 'Sprzedane produkty', 'colorify-by-inyfinn' ), esc_html( self::num( $c['items'] ) ), self::delta( $c['items'], $pv['items'] ), '', admin_url( 'admin.php?page=wc-admin&path=/analytics/products' ) );
		$out .= self::stat( 'avg', __( 'Średnie zamówienie', 'colorify-by-inyfinn' ), esc_html( self::money( $aov ) ), self::delta( $aov, $aop ) );
		$out .= '</section>';

		// Wykres + do zrobienia.
		$max     = max( 1.0, max( $s['daily'] ) );
		$top_day = array_search( max( $s['daily'] ), $s['daily'], true );
		$chart   = '<section class="cfd-card cfd-chart"><div class="cfd-card__bar"><p class="cfd-card__label">' . esc_html__( 'Przychód dzień po dniu', 'colorify-by-inyfinn' ) . '</p><a class="cfd-link" href="' . esc_url( admin_url( 'admin.php?page=wc-admin&path=/analytics/revenue' ) ) . '">' . esc_html__( 'Analiza', 'colorify-by-inyfinn' ) . '</a></div><p class="cfd-chart__value">' . esc_html( self::money( $c['revenue'] ) ) . '</p><div class="cfd-bars" role="img" aria-label="' . esc_attr__( 'Przychód dzienny w wybranym okresie', 'colorify-by-inyfinn' ) . '">';
		foreach ( $s['daily'] as $d => $v ) {
			$h      = $v > 0 ? max( 6, round( $v / $max * 100 ) ) : 4;
			$hot    = $v > 0 && $d === $top_day;
			$chart .= '<span class="cfd-bar' . ( $hot ? ' is-top' : '' ) . '" style="--h:' . (int) $h . '%" title="' . esc_attr( wp_date( 'j.m', strtotime( $d ) ) . ': ' . self::money( $v ) ) . '">' . ( $hot ? '<span class="cfd-bar__tip">' . esc_html( self::money( $v ) ) . '</span>' : '' ) . '</span>';
		}
		$keys   = array_keys( $s['daily'] );
		$chart .= '</div><div class="cfd-bars__axis"><span>' . esc_html( wp_date( 'j.m', strtotime( (string) reset( $keys ) ) ) ) . '</span><span>' . esc_html__( 'dziś', 'colorify-by-inyfinn' ) . '</span></div></section>';

		$todo  = (int) $s['todo'];
		$lines = array();
		if ( $s['invoices'] > 0 ) {
			/* translators: %d: liczba */
			$lines[] = sprintf( _n( '%d z nich ma prośbę o fakturę VAT.', '%d z nich ma prośbę o fakturę VAT.', $s['invoices'], 'colorify-by-inyfinn' ), $s['invoices'] );
		}
		$check = (int) $s['failed'] + (int) $s['pending'];
		if ( $check > 0 ) {
			/* translators: %d: liczba */
			$lines[] = sprintf( _n( '%d płatność do sprawdzenia (nieudana albo nieopłacona od ponad doby).', '%d płatności do sprawdzenia (nieudane albo nieopłacone od ponad doby).', $check, 'colorify-by-inyfinn' ), $check );
		}
		if ( $s['stock'] ) {
			/* translators: %d: liczba */
			$lines[] = sprintf( _n( '%d produkt kończy się w magazynie.', '%d produkty kończą się w magazynie.', count( $s['stock'] ), 'colorify-by-inyfinn' ), count( $s['stock'] ) );
		}
		if ( $todo > 0 ) {
			/* translators: %d: liczba */
			$title = sprintf( _n( '%d zamówienie do realizacji', '%d zamówienia do realizacji', $todo, 'colorify-by-inyfinn' ), $todo );
			$promo = self::promo( __( 'Do zrobienia', 'colorify-by-inyfinn' ), $title, $lines, __( 'Otwórz zamówienia', 'colorify-by-inyfinn' ), add_query_arg( 'status', 'wc-processing', $orders_url ) );
		} elseif ( $lines ) {
			$promo = self::promo( __( 'Do zrobienia', 'colorify-by-inyfinn' ), __( 'Wszystkie zamówienia obsłużone', 'colorify-by-inyfinn' ), $lines, __( 'Otwórz zamówienia', 'colorify-by-inyfinn' ), $orders_url );
		} else {
			$promo = self::promo( __( 'Do zrobienia', 'colorify-by-inyfinn' ), __( 'Na ten moment nic nie czeka', 'colorify-by-inyfinn' ), array( __( 'Nowe zamówienia pojawią się tutaj.', 'colorify-by-inyfinn' ) ), __( 'Wszystkie zamówienia', 'colorify-by-inyfinn' ), $orders_url, true );
		}
		$out .= '<div class="cfd-row">' . $chart . $promo . '</div>';

		// Ostatnie zamówienia + najlepiej sprzedające się (+ magazyn).
		$recent = wc_get_orders( array( 'limit' => 6, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order' ) );
		$table  = '<section class="cfd-card"><div class="cfd-card__bar"><p class="cfd-card__title">' . esc_html__( 'Ostatnie zamówienia', 'colorify-by-inyfinn' ) . '</p><a class="cfd-link" href="' . esc_url( $orders_url ) . '">' . esc_html__( 'Wszystkie', 'colorify-by-inyfinn' ) . '</a></div>';
		if ( ! $recent ) {
			$table .= '<p class="cfd-empty">' . esc_html__( 'Pierwsze zamówienie jeszcze przed Tobą.', 'colorify-by-inyfinn' ) . '</p>';
		} else {
			$table .= '<div class="cfd-table-wrap"><table class="cfd-table"><thead><tr><th scope="col">' . esc_html__( 'Nr', 'colorify-by-inyfinn' ) . '</th><th scope="col">' . esc_html__( 'Data', 'colorify-by-inyfinn' ) . '</th><th scope="col">' . esc_html__( 'Klient', 'colorify-by-inyfinn' ) . '</th><th scope="col" class="num">' . esc_html__( 'Kwota', 'colorify-by-inyfinn' ) . '</th><th scope="col">' . esc_html__( 'Status', 'colorify-by-inyfinn' ) . '</th></tr></thead><tbody>';
			foreach ( $recent as $o ) {
				$edit = $o->get_edit_order_url();
				$vat  = '';
				if ( class_exists( 'ETA_Checkout_Invoice' ) ) {
					$inv = ETA_Checkout_Invoice::get_invoice_data( $o );
					if ( ! empty( $inv['invoice'] ) ) {
						$vat = '' !== (string) ( $inv['nip'] ?? '' )
							? ' <button type="button" class="cfd-pill cfd-pill--info cfd-copy" data-copy="' . esc_attr( $inv['nip'] ) . '" title="' . esc_attr__( 'Kopiuj NIP', 'colorify-by-inyfinn' ) . ' ' . esc_attr( $inv['nip'] ) . '">VAT</button>'
							: ' <span class="cfd-pill cfd-pill--info">VAT</span>';
					}
				}
				$table .= '<tr class="cfd-row-link" data-href="' . esc_url( $edit ) . '"><td><a href="' . esc_url( $edit ) . '">#' . esc_html( $o->get_order_number() ) . '</a></td>'
					. '<td>' . esc_html( $o->get_date_created() ? wp_date( 'j.m.Y', $o->get_date_created()->getTimestamp() ) : '' ) . '</td>'
					. '<td>' . esc_html( trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ) . $vat . '</td>'
					. '<td class="num">' . esc_html( self::money( (float) $o->get_total() ) ) . '</td>'
					. '<td>' . self::status_pill( $o->get_status() ) . '</td></tr>';
			}
			$table .= '</tbody></table></div>';
		}
		$table .= '</section>';

		$top_rows = array();
		foreach ( $s['top'] as $t ) {
			$prod = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $t['product_id'] ) : null;
			if ( $prod ) {
				/* translators: %s: liczba */
				$top_rows[] = array( $prod->get_name(), (string) get_edit_post_link( $prod->get_parent_id() ? $prod->get_parent_id() : $prod->get_id(), 'raw' ), sprintf( __( '%s szt.', 'colorify-by-inyfinn' ), self::num( (float) $t['qty'] ) ) );
			}
		}
		$side = self::list_card( __( 'Najlepiej sprzedające się', 'colorify-by-inyfinn' ), $top_rows, __( 'Brak sprzedaży w tym okresie.', 'colorify-by-inyfinn' ), __( 'Analiza', 'colorify-by-inyfinn' ), admin_url( 'admin.php?page=wc-admin&path=/analytics/products' ), true );
		if ( $s['stock'] ) {
			$stock_rows = array();
			foreach ( $s['stock'] as $r ) {
				$stock_rows[] = array( get_the_title( (int) $r['product_id'] ), (string) get_edit_post_link( (int) $r['product_id'], 'raw' ), 'outofstock' === $r['stock_status'] ? __( 'brak', 'colorify-by-inyfinn' ) : sprintf( /* translators: %s: liczba */ __( '%s szt.', 'colorify-by-inyfinn' ), self::num( (float) $r['stock_quantity'] ) ) );
			}
			$side .= self::list_card( __( 'Kończy się w magazynie', 'colorify-by-inyfinn' ), $stock_rows, '', __( 'Magazyn', 'colorify-by-inyfinn' ), admin_url( 'admin.php?page=wc-admin&path=/analytics/stock' ) );
		}
		$out .= '<div class="cfd-row cfd-row--b"><div class="cfd-stack">' . $side . '</div>' . $table . '</div>';

		list( $tf, $tt, $tp ) = self::range( $days );
		$out .= self::traffic_strip( self::traffic( $tf, $tt ), self::traffic( $tp, $tf ), $days, (int) $c['orders'] );
		$out .= self::copy_script();
		return $out;
	}

	private static function status_pill( string $status ): string {
		$map = array(
			'completed'  => array( __( 'Zrealizowane', 'colorify-by-inyfinn' ), 'up' ),
			'processing' => array( __( 'W realizacji', 'colorify-by-inyfinn' ), 'info' ),
			'on-hold'    => array( __( 'Wstrzymane', 'colorify-by-inyfinn' ), 'warn' ),
			'pending'    => array( __( 'Oczekuje', 'colorify-by-inyfinn' ), 'warn' ),
			'cancelled'  => array( __( 'Anulowane', 'colorify-by-inyfinn' ), 'flat' ),
			'failed'     => array( __( 'Nieudane', 'colorify-by-inyfinn' ), 'down' ),
			'refunded'   => array( __( 'Zwrócone', 'colorify-by-inyfinn' ), 'flat' ),
		);
		$m = $map[ $status ] ?? array( $status, 'flat' );
		return '<span class="cfd-pill cfd-pill--' . $m[1] . '">' . esc_html( $m[0] ) . '</span>';
	}

	private static function copy_script(): string {
		return '<script>(function(){document.addEventListener("click",function(e){var c=e.target.closest(".cfd-copy");if(c){e.preventDefault();e.stopPropagation();var t=c.getAttribute("data-copy")||"";var d=function(){var o=c.textContent;c.textContent="' . esc_js( __( 'Skopiowano', 'colorify-by-inyfinn' ) ) . '";c.classList.add("is-done");setTimeout(function(){c.textContent=o;c.classList.remove("is-done")},1500)};if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(d,function(){})}return}var r=e.target.closest(".cfd-row-link");if(!r||e.target.closest("a,button"))return;if(window.getSelection&&String(window.getSelection()))return;var h=r.getAttribute("data-href");if(!h)return;if(e.ctrlKey||e.metaKey||e.button===1){window.open(h,"_blank")}else{window.location.href=h}})})();</script>';
	}

	/* ---------- Motyw: Zapytania ---------- */

	/**
	 * Wiadomości z formularzy zapisanych w bazie (pierwsza wtyczka, która je przechowuje).
	 *
	 * @return array{name:string,url:string,unread:int|null,total:int,recent:array<int,array<int,string>>,forms:array<int,array<int,string>>}|null
	 */
	private static function forms( string $from ): ?array {
		global $wpdb;
		$p = $wpdb->prefix;
		$excerpt = static function ( $data ): string {
			$vals = array();
			foreach ( (array) $data as $v ) {
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) && strlen( (string) $v ) < 400 ) {
					$vals[] = trim( wp_strip_all_tags( (string) $v ) );
				}
				if ( count( $vals ) >= 3 ) {
					break;
				}
			}
			return wp_trim_words( implode( ' · ', $vals ), 14, '…' );
		};
		// Fluent Forms.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'fluentform_submissions' ) ) ) {
			$url    = admin_url( 'admin.php?page=fluent_forms_all_entries' );
			$rows   = (array) $wpdb->get_results( "SELECT s.id, s.form_id, s.response, s.status, s.created_at, f.title FROM {$p}fluentform_submissions s LEFT JOIN {$p}fluentform_forms f ON f.id = s.form_id ORDER BY s.id DESC LIMIT 5", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$recent = array();
			foreach ( $rows as $r ) {
				$recent[] = array( $excerpt( json_decode( (string) $r['response'], true ) ), admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $r['form_id'] . '#/entries/' . (int) $r['id'] ), wp_date( 'j.m H:i', strtotime( $r['created_at'] ) ), (string) $r['title'] );
			}
			$forms = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.form_id, f.title, COUNT(*) n FROM {$p}fluentform_submissions s LEFT JOIN {$p}fluentform_forms f ON f.id = s.form_id WHERE s.created_at >= %s GROUP BY s.form_id ORDER BY n DESC LIMIT 5", $from ), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$forms[] = array( (string) $f['title'], admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $f['form_id'] ), self::num( (float) $f['n'] ) );
			}
			return array(
				'name'   => 'Fluent Forms',
				'url'    => $url,
				'unread' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}fluentform_submissions WHERE status = 'unread'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'total'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}fluentform_submissions WHERE created_at >= %s", $from ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'recent' => $recent,
				'forms'  => $forms,
			);
		}
		// WPForms (wersja płatna zapisuje wpisy w tabeli).
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'wpforms_entries' ) ) ) {
			$rows   = (array) $wpdb->get_results( "SELECT entry_id, form_id, fields, viewed, date FROM {$p}wpforms_entries ORDER BY entry_id DESC LIMIT 5", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$recent = array();
			foreach ( $rows as $r ) {
				$fields   = array_map( static fn( $f ) => $f['value'] ?? '', (array) json_decode( (string) $r['fields'], true ) );
				$recent[] = array( $excerpt( $fields ), admin_url( 'admin.php?page=wpforms-entries&view=details&entry_id=' . (int) $r['entry_id'] ), wp_date( 'j.m H:i', strtotime( $r['date'] ) ), get_the_title( (int) $r['form_id'] ) );
			}
			$forms = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT form_id, COUNT(*) n FROM {$p}wpforms_entries WHERE date >= %s GROUP BY form_id ORDER BY n DESC LIMIT 5", $from ), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$forms[] = array( get_the_title( (int) $f['form_id'] ), admin_url( 'admin.php?page=wpforms-entries&view=list&form_id=' . (int) $f['form_id'] ), self::num( (float) $f['n'] ) );
			}
			return array(
				'name'   => 'WPForms',
				'url'    => admin_url( 'admin.php?page=wpforms-entries' ),
				'unread' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforms_entries WHERE viewed = 0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'total'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}wpforms_entries WHERE date >= %s", $from ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'recent' => $recent,
				'forms'  => $forms,
			);
		}
		// MetForm (Entries) i Flamingo (Contact Form 7): wpisy jako typy wpisów.
		foreach ( array(
			'metform-entry'    => array( 'MetForm', admin_url( 'edit.php?post_type=metform-entry' ), 'metform_entries__form_id' ),
			'flamingo_inbound' => array( 'Flamingo', admin_url( 'admin.php?page=flamingo_inbound' ), '' ),
		) as $type => $info ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			$posts  = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
			$recent = array();
			foreach ( $posts as $po ) {
				// Treść zgłoszenia: MetForm trzyma ją w metform_entries__form_data, Flamingo w _from/_subject/_field_*.
				$meta = array();
				if ( 'metform-entry' === $type ) {
					$meta[] = maybe_unserialize( get_post_meta( $po->ID, 'metform_entries__form_data', true ) );
				} else {
					foreach ( (array) get_post_meta( $po->ID ) as $k => $v ) {
						if ( in_array( $k, array( '_from_name', '_from', '_subject' ), true ) || 0 === strpos( $k, '_field_' ) ) {
							$meta[] = maybe_unserialize( $v[0] ?? '' );
						}
					}
				}
				$flat = array();
				array_walk_recursive( $meta, static function ( $x ) use ( &$flat ) { $flat[] = $x; } );
				$link     = 'flamingo_inbound' === $type ? admin_url( 'admin.php?page=flamingo_inbound&post=' . $po->ID . '&action=edit' ) : (string) get_edit_post_link( $po->ID, 'raw' );
				$form     = '' !== $info[2] ? get_the_title( (int) get_post_meta( $po->ID, $info[2], true ) ) : '';
				$recent[] = array( $excerpt( $flat ) ?: get_the_title( $po ), $link, wp_date( 'j.m H:i', strtotime( $po->post_date ) ), $form );
			}
			$total = count( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids', 'date_query' => array( array( 'after' => $from, 'inclusive' => true ) ) ) ) );
			return array( 'name' => $info[0], 'url' => $info[1], 'unread' => null, 'total' => $total, 'recent' => $recent, 'forms' => array() );
		}
		return null;
	}

	private static function leads( int $days ): string {
		list( $from, $to, $prevf ) = self::range( $days );
		$key = 'colorify_dash_leads_' . $days;
		$f   = get_transient( $key );
		if ( false === $f ) {
			$f = self::forms( $from . ' 00:00:00' );
			set_transient( $key, $f ?? 'none', 5 * MINUTE_IN_SECONDS );
		}
		$f    = is_array( $f ) ? $f : null;
		// Zlecenia z wtyczki (filtry colorify_calendar_events / colorify_dashboard_kpis) to też zapytania: nie straszymy "formularze nie zapisują".
		$ext  = $f ? null : self::plan_leads_source();
		$tcur = self::traffic( $from, $to );
		$tpre = self::traffic( $prevf, $from );
		$msg  = $f ? $f['url'] : ( $ext ? $ext['url'] : admin_url( 'plugins.php' ) );

		/* translators: %d: liczba dni */
		$out  = self::head( sprintf( __( 'Ostatnie %d dni', 'colorify-by-inyfinn' ), $days ), $days );
		$out .= self::shortcuts(
			array(
				array( 'mail', __( 'Wiadomości', 'colorify-by-inyfinn' ), $msg ),
				array( 'pages', __( 'Strony', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=page' ) ),
				array( 'plus', __( 'Dodaj stronę', 'colorify-by-inyfinn' ), admin_url( 'post-new.php?post_type=page' ) ),
				array( 'edit', __( 'Nowy wpis', 'colorify-by-inyfinn' ), admin_url( 'post-new.php' ) ),
			)
		);

		$out .= '<section class="cfd-card cfd-strip">';
		if ( $ext ) {
			$out .= self::stat( 'mail', __( 'Zlecenia w terminarzu', 'colorify-by-inyfinn' ), esc_html( self::num( $ext['count'] ) ), '', __( 'Najbliższe pół roku', 'colorify-by-inyfinn' ), $msg );
		} else {
			$out .= self::stat( 'mail', __( 'Zapytania', 'colorify-by-inyfinn' ), $f ? esc_html( self::num( $f['total'] ) ) : '-', '', $f ? $f['name'] : __( 'Formularze nie zapisują wiadomości', 'colorify-by-inyfinn' ), $msg );
		}
		$out .= self::stat( 'eye', __( 'Wejścia na stronę', 'colorify-by-inyfinn' ), esc_html( self::num( $tcur['views'] ) ), self::delta( $tcur['views'], $tpre['views'] ) );
		$out .= self::stat( 'user', __( 'Unikalni odwiedzający', 'colorify-by-inyfinn' ), esc_html( self::num( $tcur['visitors'] ) ), self::delta( $tcur['visitors'], $tpre['visitors'] ) );
		$rate = ( $f && $tcur['visitors'] > 0 ) ? $f['total'] / $tcur['visitors'] * 100 : -1;
		$out .= self::stat( 'conv', __( 'Zapytania na 100 odwiedzających', 'colorify-by-inyfinn' ), $rate >= 0 ? esc_html( self::num( $rate, 1 ) ) : '-' );
		$out .= '</section>';

		if ( ! $f && $ext ) {
			$nx    = $ext['next'];
			$promo = $nx
				? self::promo( __( 'Najbliższe zlecenie', 'colorify-by-inyfinn' ), $nx['title'], array_filter( array( wp_date( 'j F Y', strtotime( $nx['date'] . ' 12:00:00' ) ), $nx['meta'] ) ), '' !== $nx['url'] ? __( 'Otwórz zlecenie', 'colorify-by-inyfinn' ) : '', $nx['url'] )
				: self::promo( __( 'Do przejrzenia', 'colorify-by-inyfinn' ), __( 'Brak nadchodzących zleceń', 'colorify-by-inyfinn' ), array( __( 'Nowe zlecenia pojawią się tutaj.', 'colorify-by-inyfinn' ) ), '', '', true );
		} elseif ( ! $f ) {
			$promo = self::promo( __( 'Do przejrzenia', 'colorify-by-inyfinn' ), __( 'Wiadomości nie są zapisywane na stronie', 'colorify-by-inyfinn' ), array( __( 'Formularze wysyłają je tylko e-mailem. Żeby widzieć je tutaj, włącz zapisywanie zgłoszeń (np. MetForm: Store Entries, Contact Form 7: wtyczka Flamingo).', 'colorify-by-inyfinn' ) ), __( 'Wtyczki', 'colorify-by-inyfinn' ), admin_url( 'plugins.php' ), true );
		} elseif ( null !== $f['unread'] && $f['unread'] > 0 ) {
			/* translators: %d: liczba */
			$promo = self::promo( __( 'Do przejrzenia', 'colorify-by-inyfinn' ), sprintf( _n( '%d nowa wiadomość', '%d nowe wiadomości', $f['unread'], 'colorify-by-inyfinn' ), $f['unread'] ), $f['recent'] ? array( sprintf( /* translators: 1: formularz, 2: data */ __( 'Ostatnia: %1$s, %2$s.', 'colorify-by-inyfinn' ), $f['recent'][0][3] ?: $f['name'], $f['recent'][0][2] ) ) : array(), __( 'Otwórz wiadomości', 'colorify-by-inyfinn' ), $f['url'] );
		} elseif ( $f['recent'] ) {
			$promo = self::promo( __( 'Do przejrzenia', 'colorify-by-inyfinn' ), __( 'Ostatnie zapytanie', 'colorify-by-inyfinn' ), array( $f['recent'][0][0], $f['recent'][0][2] ), __( 'Otwórz wiadomości', 'colorify-by-inyfinn' ), $f['url'] );
		} else {
			$promo = self::promo( __( 'Do przejrzenia', 'colorify-by-inyfinn' ), __( 'Nie ma jeszcze wiadomości', 'colorify-by-inyfinn' ), array( __( 'Nowe zapytania z formularzy pojawią się tutaj.', 'colorify-by-inyfinn' ) ), '', '', true );
		}
		$recent = $f ? self::list_card( __( 'Ostatnie zapytania', 'colorify-by-inyfinn' ), array_map( static fn( $r ) => array( $r[0], $r[1], $r[2], $r[3] ), $f['recent'] ), __( 'Brak zapytań.', 'colorify-by-inyfinn' ), __( 'Wszystkie', 'colorify-by-inyfinn' ), $f['url'] )
			: self::list_card( __( 'Popularne podstrony', 'colorify-by-inyfinn' ), self::top_pages_rows( $tcur['pages'] ), __( 'Licznik zbiera dane od dziś.', 'colorify-by-inyfinn' ), '', '', true );
		$out   .= '<div class="cfd-row">' . $recent . $promo . '</div>';

		$side = array();
		if ( $f && $f['forms'] ) {
			$side[] = self::list_card( __( 'Skąd przychodzą zapytania', 'colorify-by-inyfinn' ), $f['forms'], '' );
		}
		if ( $f ) {
			$side[] = self::list_card( __( 'Popularne podstrony', 'colorify-by-inyfinn' ), self::top_pages_rows( $tcur['pages'] ), __( 'Licznik zbiera dane od dziś.', 'colorify-by-inyfinn' ), '', '', true );
		}
		$side[] = self::list_card( __( 'Ostatnio zmieniane strony', 'colorify-by-inyfinn' ), self::recent_posts( 'page', array( 'publish', 'draft' ) ), __( 'Brak stron.', 'colorify-by-inyfinn' ), __( 'Wszystkie', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=page' ) );
		$out   .= '<div class="cfd-row cfd-row--even">' . implode( '', $side ) . '</div>';
		return $out;
	}

	/** @return array<int,array<int,string>> */
	private static function recent_posts( string $type, array $status, int $n = 5, string $order = 'modified', string $dir = 'DESC' ): array {
		$rows = array();
		foreach ( get_posts( array( 'post_type' => $type, 'post_status' => $status, 'numberposts' => $n, 'orderby' => $order, 'order' => $dir ) ) as $po ) {
			$when   = 'date' === $order ? $po->post_date : $po->post_modified;
			$rows[] = array( get_the_title( $po ) ?: __( '(bez tytułu)', 'colorify-by-inyfinn' ), (string) get_edit_post_link( $po->ID, 'raw' ), wp_date( 'j.m H:i', strtotime( $when ) ), 'draft' === $po->post_status ? __( 'szkic', 'colorify-by-inyfinn' ) : '' );
		}
		return $rows;
	}

	/* ---------- Motyw: Redakcja ---------- */

	private static function editorial( int $days ): string {
		list( $from, $to, $prevf ) = self::range( $days );
		$counts   = wp_count_posts( 'post' );
		$comments = wp_count_comments();
		$pub_now  = count( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids', 'date_query' => array( array( 'after' => $from, 'inclusive' => true ) ) ) ) );
		$pub_prev = count( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids', 'date_query' => array( array( 'after' => $prevf, 'before' => $from, 'inclusive' => true ) ) ) ) );
		$missed   = get_posts( array( 'post_type' => 'post', 'post_status' => 'future', 'numberposts' => 10, 'date_query' => array( array( 'before' => current_time( 'mysql' ) ) ) ) );
		$tcur     = self::traffic( $from, $to );
		$tpre     = self::traffic( $prevf, $from );

		/* translators: %d: liczba dni */
		$out  = self::head( sprintf( __( 'Ostatnie %d dni', 'colorify-by-inyfinn' ), $days ), $days );
		$out .= self::shortcuts(
			array(
				array( 'plus', __( 'Nowy wpis', 'colorify-by-inyfinn' ), admin_url( 'post-new.php' ) ),
				array( 'file', __( 'Szkice', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_status=draft&post_type=post' ) ),
				array( 'comment', __( 'Komentarze', 'colorify-by-inyfinn' ), admin_url( 'edit-comments.php?comment_status=moderated' ) ),
				array( 'clock', __( 'Zaplanowane', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_status=future&post_type=post' ) ),
			)
		);
		$out .= '<section class="cfd-card cfd-strip">';
		$out .= self::stat( 'edit', __( 'Opublikowane', 'colorify-by-inyfinn' ), esc_html( self::num( $pub_now ) ), self::delta( $pub_now, $pub_prev ), '', admin_url( 'edit.php?post_status=publish&post_type=post' ) );
		$out .= self::stat( 'clock', __( 'Zaplanowane', 'colorify-by-inyfinn' ), esc_html( self::num( (float) $counts->future ) ), '', '', admin_url( 'edit.php?post_status=future&post_type=post' ) );
		$out .= self::stat( 'file', __( 'Szkice', 'colorify-by-inyfinn' ), esc_html( self::num( (float) $counts->draft ) ), '', '', admin_url( 'edit.php?post_status=draft&post_type=post' ) );
		$out .= self::stat( 'comment', __( 'Komentarze do moderacji', 'colorify-by-inyfinn' ), esc_html( self::num( (float) $comments->moderated ) ), '', '', admin_url( 'edit-comments.php?comment_status=moderated' ) );
		$out .= '</section>';

		$lines = array();
		if ( $missed ) {
			/* translators: %d: liczba */
			$lines[] = sprintf( _n( '%d zaplanowany wpis nie opublikował się o czasie.', '%d zaplanowane wpisy nie opublikowały się o czasie.', count( $missed ), 'colorify-by-inyfinn' ), count( $missed ) );
		}
		if ( (int) $comments->moderated > 0 ) {
			/* translators: %d: liczba */
			$promo = self::promo( __( 'Do zrobienia', 'colorify-by-inyfinn' ), sprintf( _n( '%d komentarz czeka na moderację', '%d komentarze czekają na moderację', (int) $comments->moderated, 'colorify-by-inyfinn' ), (int) $comments->moderated ), $lines, __( 'Przejrzyj komentarze', 'colorify-by-inyfinn' ), admin_url( 'edit-comments.php?comment_status=moderated' ) );
		} elseif ( $missed ) {
			$promo = self::promo( __( 'Do zrobienia', 'colorify-by-inyfinn' ), __( 'Publikacja nie wyszła o czasie', 'colorify-by-inyfinn' ), $lines, __( 'Zobacz zaplanowane', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_status=future&post_type=post' ) );
		} else {
			$promo = self::promo( __( 'Do zrobienia', 'colorify-by-inyfinn' ), __( 'Na ten moment nic nie wymaga uwagi', 'colorify-by-inyfinn' ), array( __( 'Napisz coś nowego albo dokończ szkic.', 'colorify-by-inyfinn' ) ), __( 'Nowy wpis', 'colorify-by-inyfinn' ), admin_url( 'post-new.php' ), true );
		}
		$upcoming = self::recent_posts( 'post', array( 'future' ), 5, 'date', 'ASC' );
		$out     .= '<div class="cfd-row">' . self::list_card( __( 'Kolejne publikacje', 'colorify-by-inyfinn' ), $upcoming, __( 'Nic nie jest zaplanowane.', 'colorify-by-inyfinn' ), __( 'Zaplanuj wpis', 'colorify-by-inyfinn' ), admin_url( 'post-new.php' ) ) . $promo . '</div>';

		$out .= '<div class="cfd-row cfd-row--even">'
			. self::list_card( __( 'Szkice do dokończenia', 'colorify-by-inyfinn' ), self::recent_posts( 'post', array( 'draft' ) ), __( 'Wszystkie rozpoczęte wpisy są opublikowane albo zaplanowane.', 'colorify-by-inyfinn' ), __( 'Wszystkie', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_status=draft&post_type=post' ) )
			. self::list_card( __( 'Najczęściej czytane', 'colorify-by-inyfinn' ), self::top_pages_rows( $tcur['pages'] ), __( 'Licznik zbiera dane od dziś.', 'colorify-by-inyfinn' ), '', '', true )
			. self::comments_card()
			. '</div>';
		$out .= self::traffic_strip( $tcur, $tpre, $days );
		return $out;
	}

	private static function comments_card(): string {
		$rows = array();
		foreach ( get_comments( array( 'number' => 5, 'status' => 'all' ) ) as $c ) {
			$rows[] = array( wp_trim_words( wp_strip_all_tags( $c->comment_content ), 10, '…' ), admin_url( 'comment.php?action=editcomment&c=' . (int) $c->comment_ID ), '0' === (string) $c->comment_approved ? __( 'czeka', 'colorify-by-inyfinn' ) : '', $c->comment_author . ' · ' . get_the_title( (int) $c->comment_post_ID ) );
		}
		return self::list_card( __( 'Ostatnie komentarze', 'colorify-by-inyfinn' ), $rows, __( 'Brak komentarzy.', 'colorify-by-inyfinn' ), __( 'Wszystkie', 'colorify-by-inyfinn' ), admin_url( 'edit-comments.php' ) );
	}

	/* ---------- Motyw: Firma ---------- */

	private static function company( int $days ): string {
		list( $from, $to, $prevf ) = self::range( $days );
		$health  = json_decode( (string) get_transient( 'health-check-site-status-result' ), true );
		$crit    = is_array( $health ) ? (int) ( $health['critical'] ?? 0 ) : -1;
		$recomm  = is_array( $health ) ? (int) ( $health['recommended'] ?? 0 ) : -1;
		$upd     = function_exists( 'wp_get_update_data' ) ? wp_get_update_data() : array( 'counts' => array() );
		$ucount  = (array) ( $upd['counts'] ?? array() );
		$utotal  = (int) ( $ucount['plugins'] ?? 0 ) + (int) ( $ucount['themes'] ?? 0 ) + (int) ( $ucount['wordpress'] ?? 0 );
		$pages   = wp_count_posts( 'page' );
		$tcur    = self::traffic( $from, $to );
		$tpre    = self::traffic( $prevf, $from );

		// Brakujące tłumaczenia (Polylang): strony bez wersji w każdym języku.
		$missing = array();
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post_translations' ) ) {
			$langs = (array) pll_languages_list();
			if ( count( $langs ) > 1 ) {
				foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 150, 'fields' => 'ids', 'lang' => function_exists( 'pll_default_language' ) ? pll_default_language() : '' ) ) as $id ) {
					$have = array_keys( (array) pll_get_post_translations( $id ) );
					$miss = array_diff( $langs, $have );
					if ( $miss ) {
						$missing[] = array( get_the_title( $id ), (string) get_edit_post_link( $id, 'raw' ), strtoupper( implode( ', ', $miss ) ) );
					}
				}
			}
		}

		$out  = self::head( __( 'Stan strony', 'colorify-by-inyfinn' ), $days, false );
		$out .= self::shortcuts(
			array(
				array( 'edit', __( 'Edytuj stronę główną', 'colorify-by-inyfinn' ), (int) get_option( 'page_on_front' ) ? (string) get_edit_post_link( (int) get_option( 'page_on_front' ), 'raw' ) : admin_url( 'edit.php?post_type=page' ) ),
				array( 'pages', __( 'Wszystkie strony', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=page' ) ),
				array( 'refresh', __( 'Aktualizacje', 'colorify-by-inyfinn' ), admin_url( 'update-core.php' ) ),
				array( 'shield', __( 'Kondycja witryny', 'colorify-by-inyfinn' ), admin_url( 'site-health.php' ) ),
			)
		);
		$out .= '<section class="cfd-card cfd-strip">';
		$out .= self::stat( 'shield', __( 'Krytyczne problemy', 'colorify-by-inyfinn' ), $crit >= 0 ? esc_html( self::num( $crit ) ) : '-', '', $crit >= 0 ? sprintf( /* translators: %d: liczba */ __( 'Zalecenia: %d', 'colorify-by-inyfinn' ), $recomm ) : __( 'Otwórz Kondycję witryny, żeby sprawdzić', 'colorify-by-inyfinn' ), admin_url( 'site-health.php' ) );
		$out .= self::stat( 'refresh', __( 'Aktualizacje', 'colorify-by-inyfinn' ), esc_html( self::num( $utotal ) ), '', $utotal ? sprintf( /* translators: 1,2: liczby */ __( 'Wtyczki %1$d, motywy %2$d', 'colorify-by-inyfinn' ), (int) ( $ucount['plugins'] ?? 0 ), (int) ( $ucount['themes'] ?? 0 ) ) : __( 'Wszystko aktualne', 'colorify-by-inyfinn' ), admin_url( 'update-core.php' ) );
		$out .= self::stat( 'pages', __( 'Opublikowane strony', 'colorify-by-inyfinn' ), esc_html( self::num( (float) $pages->publish ) ), '', '', admin_url( 'edit.php?post_type=page' ) );
		$out .= self::stat( 'eye', __( 'Wejścia na stronę', 'colorify-by-inyfinn' ), esc_html( self::num( $tcur['views'] ) ), '', __( 'Ostatnie 30 dni', 'colorify-by-inyfinn' ) );
		$out .= '</section>';

		$lines = array();
		if ( $crit > 0 ) {
			/* translators: %d: liczba */
			$lines[] = sprintf( _n( '%d krytyczny problem w Kondycji witryny.', '%d krytyczne problemy w Kondycji witryny.', $crit, 'colorify-by-inyfinn' ), $crit );
		}
		if ( $utotal > 0 ) {
			/* translators: %d: liczba */
			$lines[] = sprintf( _n( 'Dostępna jest %d aktualizacja.', 'Dostępne są aktualizacje: %d.', $utotal, 'colorify-by-inyfinn' ), $utotal );
		}
		if ( $missing ) {
			/* translators: %d: liczba */
			$lines[] = sprintf( _n( '%d strona nie ma wszystkich tłumaczeń.', '%d strony nie mają wszystkich tłumaczeń.', count( $missing ), 'colorify-by-inyfinn' ), count( $missing ) );
		}
		$attention = ( $crit > 0 ? 1 : 0 ) + ( $utotal > 0 ? 1 : 0 ) + ( $missing ? 1 : 0 );
		$promo     = $attention
			? self::promo( __( 'Wymaga uwagi', 'colorify-by-inyfinn' ), sprintf( /* translators: %d: liczba */ _n( '%d rzecz do sprawdzenia', '%d rzeczy do sprawdzenia', $attention, 'colorify-by-inyfinn' ), $attention ), $lines, __( 'Sprawdź', 'colorify-by-inyfinn' ), $crit > 0 ? admin_url( 'site-health.php' ) : ( $utotal ? admin_url( 'update-core.php' ) : admin_url( 'edit.php?post_type=page' ) ) )
			: self::promo( __( 'Wymaga uwagi', 'colorify-by-inyfinn' ), __( 'Strona działa bez zastrzeżeń', 'colorify-by-inyfinn' ), array( __( 'Brak krytycznych problemów i aktualizacji.', 'colorify-by-inyfinn' ) ), '', '', true );

		$out .= '<div class="cfd-row">' . self::list_card( __( 'Popularne podstrony', 'colorify-by-inyfinn' ), self::top_pages_rows( $tcur['pages'] ), __( 'Licznik zbiera dane od dziś.', 'colorify-by-inyfinn' ), '', '', true ) . $promo . '</div>';
		$cards = array( self::list_card( __( 'Ostatnio zmieniane strony', 'colorify-by-inyfinn' ), self::recent_posts( 'page', array( 'publish', 'draft' ) ), __( 'Brak stron.', 'colorify-by-inyfinn' ), __( 'Wszystkie', 'colorify-by-inyfinn' ), admin_url( 'edit.php?post_type=page' ) ) );
		if ( $missing ) {
			$cards[] = self::list_card( __( 'Brakujące tłumaczenia', 'colorify-by-inyfinn' ), array_slice( $missing, 0, 5 ), '' );
		}
		$out .= '<div class="cfd-row cfd-row--even">' . implode( '', $cards ) . '</div>';
		return $out;
	}

	/* ===================== Wygląd ===================== */

	public static function css(): void {
		?>
<style id="colorify-dashboard-css">
#dashboard-widgets-wrap .postbox-container{width:100%!important}
body.index-php #wpbody-content .notice,body.index-php #wpbody-content .update-nag,body.index-php #wpbody-content div.error,body.index-php #wpbody-content div.updated{display:none!important}
#dashboard-widgets .meta-box-sortables{min-height:0;border:0}
#colorify_dashboard{background:transparent!important;border:0!important;box-shadow:none!important;border-radius:0!important;overflow:visible!important}
#colorify_dashboard .postbox-header{display:none}
#colorify_dashboard .inside{margin:0!important;padding:0!important}
.cfd{--s:var(--colorify-ui-surface,#fff);--t:var(--colorify-ui-text,#1d2327);--m:var(--colorify-ui-text-muted,#646970);--a:var(--colorify-ui-accent,#2271b1);--on:var(--colorify-ui-on-accent,#fff);--line:color-mix(in srgb,var(--t) 8%,transparent);--r:var(--colorify-radius-card,20px);color:var(--t);max-width:1280px;display:flex;flex-direction:column;gap:20px}
.cfd a:focus-visible,.cfd button:focus-visible{outline:2px solid var(--a);outline-offset:2px}
.cfd-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
.cfd-head__sub{margin:0;color:var(--m);font-size:14px}
.cfd-range{display:inline-flex;gap:4px;padding:4px;border-radius:12px;background:var(--s)}
.cfd-range a{display:inline-flex;align-items:center;min-height:36px;padding:0 14px;border-radius:9px;color:var(--m);text-decoration:none;font-size:13px;font-weight:500}
.cfd-range a:hover{color:var(--t)}
.cfd-range a[aria-current]{background:var(--a);color:var(--on)}
.cfd-quick{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.cfd-quick__a{display:flex;align-items:center;gap:12px;min-height:56px;padding:0 18px;border-radius:16px;background:var(--s);color:var(--t);text-decoration:none;font-size:14px;font-weight:600;box-shadow:0 1px 2px rgba(16,24,40,.04);transition:transform .15s ease,box-shadow .15s ease,color .15s ease}
.cfd-quick__a:hover{color:var(--a);transform:translateY(-1px);box-shadow:0 4px 12px rgba(16,24,40,.08)}
body.colorify-dark .cfd-quick__a:hover{background:color-mix(in srgb,#fff 5%,var(--s))}
.cfd-quick__a.is-main{background:color-mix(in srgb,var(--a) 10%,var(--s));color:var(--a)}
.cfd-card{background:var(--s);border-radius:var(--r);padding:24px 28px;box-shadow:var(--colorify-shadow-card,0 1px 2px rgba(16,24,40,.04));min-width:0}
.cfd-card__label,.cfd-card__title{margin:0;font-size:15px;font-weight:600;color:var(--t)}
.cfd-card__label{font-weight:500;color:var(--m)}
.cfd-card__bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
.cfd-link{font-size:13px;font-weight:500;color:var(--a);text-decoration:none;white-space:nowrap}
.cfd-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));padding:22px 0}
.cfd-stat{padding:0 28px;display:flex;flex-direction:column;gap:8px;min-width:0;color:inherit;text-decoration:none}
.cfd-stat+.cfd-stat{border-left:1px solid var(--line)}
a.cfd-stat:hover .cfd-stat__label{color:var(--a)}
.cfd-stat__top{display:flex;align-items:center;gap:10px}
.cfd-stat__label{font-size:14px;color:var(--m)}
.cfd-stat__row{display:flex;align-items:center;flex-wrap:wrap;gap:8px 10px;min-width:0}
.cfd-stat__value{font-size:28px;line-height:1.15;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.cfd-stat__value--text{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;font-size:16px;line-height:22px;font-weight:600;letter-spacing:0;overflow-wrap:break-word}
.cfd-stat__hint{font-size:12px;color:var(--m)}
.cfd-ico{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:50%;flex:none}
.cfd-ico svg{width:16px;height:16px}
.cfd-ico--cash,.cfd-ico--alert{background:#fdecdf;color:#d9622b}.cfd-ico--bag,.cfd-ico--conv,.cfd-ico--shield{background:#e2f4ea;color:#1f8a52}.cfd-ico--box,.cfd-ico--eye,.cfd-ico--mail,.cfd-ico--list{background:#e3eefc;color:#2a6fd6}.cfd-ico--avg,.cfd-ico--comment{background:#fbe4ea;color:#c8345a}.cfd-ico--user,.cfd-ico--clock,.cfd-ico--globe{background:#efe7fb;color:#7046c2}.cfd-ico--pages,.cfd-ico--file,.cfd-ico--edit,.cfd-ico--refresh,.cfd-ico--plus,.cfd-ico--card{background:#f2efe6;color:#8a6d1f}
.cfd-pill{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border:0;border-radius:999px;font:inherit;font-size:11px;font-weight:600;line-height:1.6;white-space:nowrap}
.cfd-pill--up{background:var(--colorify-tag-ok-bg,#e2f4ea);color:var(--colorify-tag-ok-fg,#167443)}.cfd-pill--down{background:var(--colorify-tag-bad-bg,#fde7e9);color:var(--colorify-tag-bad-fg,#b42335)}.cfd-pill--flat{background:var(--line);color:var(--m)}.cfd-pill--info{background:var(--colorify-tag-info-bg,#e3eefc);color:var(--colorify-tag-info-fg,#1c5bb8)}.cfd-pill--warn{background:var(--colorify-tag-warn-bg,#fdf1d8);color:var(--colorify-tag-warn-fg,#8a5a00)}
button.cfd-pill{cursor:copy}button.cfd-pill.is-done{background:#e2f4ea;color:#167443}
.cfd-row{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:20px}
.cfd-row--b{grid-template-columns:minmax(0,1fr) minmax(0,1.6fr)}
.cfd-row--even{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}
.cfd-stack{display:flex;flex-direction:column;gap:20px;min-width:0}
.cfd-chart{display:flex;flex-direction:column}
.cfd-chart__value{margin:6px 0 20px;font-size:30px;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.cfd-bars{position:relative;display:flex;align-items:flex-end;gap:5px;height:170px;margin-top:auto;padding-top:34px}
.cfd-bar{position:relative;flex:1;height:var(--h);min-width:3px;border-radius:6px;background:color-mix(in srgb,var(--t) 9%,transparent)}
.cfd-bar:hover{background:color-mix(in srgb,var(--a) 45%,transparent)}
.cfd-bar.is-top{background:var(--a)}
.cfd-bar__tip{position:absolute;bottom:calc(100% + 8px);left:50%;transform:translateX(-50%);padding:5px 9px;border-radius:8px;background:#1d2327;color:#fff;font-size:12px;font-weight:600;white-space:nowrap}
.cfd-bars__axis{display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--m)}
.cfd-promo{display:flex;flex-direction:column;align-items:flex-start;gap:10px;padding:26px 28px;background:var(--a);color:var(--on);background-image:radial-gradient(circle at 110% 120%,rgba(255,255,255,.18) 0 38%,transparent 39%)}
.cfd-promo--calm{background:var(--s);color:var(--t);background-image:none}
.cfd-promo__tag{padding:4px 12px;border-radius:999px;background:rgba(255,255,255,.95);color:var(--a);font-size:12px;font-weight:600}
.cfd-promo--calm .cfd-promo__tag{background:color-mix(in srgb,var(--a) 12%,var(--s))}
.cfd-promo__title{margin:4px 0 0;font-size:22px;line-height:1.25;font-weight:700}
.cfd-promo__text{margin:0;font-size:14px;line-height:1.5;opacity:.9}
.cfd-promo__btn{margin-top:auto;align-self:stretch;display:flex;align-items:center;justify-content:center;min-height:46px;margin-top:14px;border-radius:12px;background:#fff;color:#1d2327;font-size:14px;font-weight:600;text-decoration:none}
.cfd-promo__btn:hover{color:#1d2327;background:rgba(255,255,255,.9)}
.cfd-promo--calm .cfd-promo__btn{background:var(--a);color:var(--on)}
.cfd-rows{margin:8px 0 0;padding:0;list-style:none;counter-reset:t}
.cfd-rows li{display:flex;align-items:center;gap:12px;padding:12px 0;border-top:1px solid var(--line);font-size:14px;counter-increment:t}
.cfd-rows li:first-child{border-top:0}
.cfd-rows.is-numbered li::before{content:counter(t);color:var(--m);font-size:12px;font-weight:600;width:16px;flex:none}
.cfd-rows a{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;color:var(--t);text-decoration:none}
.cfd-rows a:hover .cfd-rows__main{color:var(--a)}
.cfd-rows__main{font-weight:500;overflow-wrap:anywhere}
.cfd-rows__sub{font-size:12px;color:var(--m)}
.cfd-rows__aside{color:var(--m);white-space:nowrap;font-variant-numeric:tabular-nums;font-size:13px}
.cfd-table-wrap{overflow-x:auto}
.cfd-table{width:100%;border-collapse:collapse;font-size:14px}
.cfd-table th{padding:10px 8px;border-bottom:1px solid var(--line);color:var(--m);font-size:12px;font-weight:500;text-align:left}
.cfd-table td{padding:12px 8px;border-bottom:1px solid var(--line);white-space:nowrap}
.cfd-table tr:last-child td{border-bottom:0}
.cfd-table .num{text-align:right;font-variant-numeric:tabular-nums}
.cfd-table a{color:var(--t);font-weight:600;text-decoration:none}
.cfd-row-link{cursor:pointer}
.cfd-row-link:hover td{background:color-mix(in srgb,var(--a) 6%,transparent)}
.cfd-row-link td:first-child{border-radius:10px 0 0 10px}.cfd-row-link td:last-child{border-radius:0 10px 10px 0}
.cfd-empty,.cfd-note{color:var(--m);font-size:13px;margin:0}
@media (max-width:1100px){.cfd-row,.cfd-row--b{grid-template-columns:minmax(0,1fr)}.cfd-strip{grid-template-columns:repeat(2,minmax(0,1fr));row-gap:20px}.cfd-stat:nth-child(3){border-left:0}.cfd-promo{order:-1}}
@media (max-width:600px){.cfd-card{padding:20px}.cfd-quick{grid-template-columns:1fr 1fr}.cfd-strip{padding:18px 0}.cfd-stat{padding:0 16px}.cfd-stat__value:not(.cfd-stat__value--text){font-size:22px}.cfd-chart__value{font-size:24px}}
</style>
		<?php
	}

	/** Zapis z formularza Ustawienia, Colorify. @param array<string,mixed> $input */
	public static function save_from_input( array $input ): void {
		if ( ! isset( $input['colorify_dashboard_style'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = sanitize_key( (string) $input['colorify_dashboard_style'] );
		update_option( self::OPTION, in_array( $s, self::STYLES, true ) ? $s : 'classic' );
		foreach ( array( 7, 30, 90 ) as $d ) {
			delete_transient( 'colorify_dash_sales_' . $d );
			delete_transient( 'colorify_dash_leads_' . $d );
		}
	}
}

Colorify_Dashboard::init();
