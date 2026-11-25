<?php
/**
 * Plugin Name: ETA Product Card Texts
 * Description: Edytowalne teksty z karty produktu pod ceną: punkty, ramka usługi laboratoryjnej, napis na przycisku. Kolejność: meta produktu -> opcja eta_card_globals[wariant] -> tekst z kodu ETA_Shop_Template. Podpisuje też boxy ekranu edycji produktu.
 * Version: 1.3.0
 *
 * Meta produktu: _eta_card_bullets, _eta_card_bullets_hide, _eta_card_notice, _eta_card_notice_hide, _eta_card_button.
 * Opcja eta_card_globals: [ 'lab-service'|'lab-prep'|'cosmetics' => [ 'bullets' => string[], 'notice_html' => string, 'button_text' => string ] ].
 * Opcja eta_card_globals['_all'] (wspólne dla sklepu): [ 'trust_strip' => [ 3 x [ 'title' => str, 'text' => str ] ], 'returns_html' => str ].
 * API: ETA_Product_Card_Texts::defaults( $product_id ) : array.
 */

defined( 'ABSPATH' ) || exit;

final class ETA_Product_Card_Texts {

	private const NONCE    = 'eta_card_texts';
	private const VARIANTS = [ 'lab-service', 'lab-prep', 'cosmetics' ];

	/** @var array<int, array{variant: string, label: string, items: string[]}> */
	private static $info_cache = [];
	/** Wyłącza nasz filtr przycisku (do odczytu tekstu domyślnego). */
	private static $bypass_button = false;

	public static function boot() : void {
		// Klasa ETA_Shop_Template jest definiowana później (kolejność plików), więc sprawdzamy ją w hookach.
		add_action( 'init', [ __CLASS__, 'swap_renderers' ], 20 );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes', [ __CLASS__, 'add_boxes' ], 5 );
			add_action( 'add_meta_boxes', [ __CLASS__, 'relabel_boxes' ], 99 );
			add_action( 'edit_form_after_title', [ __CLASS__, 'description_hint' ] );
			add_filter( 'get_user_option_meta-box-order_product', [ __CLASS__, 'order_first' ] );
			add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save' ] );
			add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ], 20 );
			foreach ( [ 'eta-card-bullets', 'eta-card-notice', 'eta-card-button' ] as $box ) {
				add_filter( 'postbox_classes_product_' . $box, [ __CLASS__, 'never_closed' ] );
			}
		}
	}

	public static function swap_renderers() : void {
		if ( ! class_exists( 'ETA_Shop_Template' ) ) {
			return;
		}
		remove_action( 'woocommerce_single_product_summary', [ 'ETA_Shop_Template', 'render_value_props' ], 26 );
		remove_action( 'woocommerce_single_product_summary', [ 'ETA_Shop_Template', 'render_service_notice' ], 27 );
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_bullets' ], 26 );
		add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'render_notice' ], 27 );
		// eta-shop-template ustawia napis przycisku na 25; my nadpisujemy po nim.
		add_filter( 'woocommerce_product_single_add_to_cart_text', [ __CLASS__, 'filter_button' ], 30, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', [ __CLASS__, 'filter_button' ], 30, 2 );
		// Pasek trzech kafli (after_single_product/5) i zakładka Polityka zwrotów: opcja _all -> fallback na oryginał.
		remove_action( 'woocommerce_after_single_product', [ 'ETA_Shop_Template', 'render_trust_strip' ], 5 );
		add_action( 'woocommerce_after_single_product', [ __CLASS__, 'render_trust_strip' ], 5 );
		add_filter( 'woocommerce_product_tabs', [ __CLASS__, 'swap_returns_callback' ], 99 );
	}

	/* ---------- teksty wspólne (opcja) ---------- */

	/** Opcja odczytywana i sanityzowana przy każdym użyciu (zapisuje ją edytor ETA). */
	private static function globals() : array {
		$raw = get_option( 'eta_card_globals' );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( self::VARIANTS as $v ) {
			if ( empty( $raw[ $v ] ) || ! is_array( $raw[ $v ] ) ) {
				continue;
			}
			$b = $raw[ $v ]['bullets'] ?? [];
			$b = is_string( $b ) ? self::lines( $b ) : ( is_array( $b ) ? array_filter( array_map( static function ( $x ) {
				return is_scalar( $x ) ? trim( sanitize_text_field( (string) $x ) ) : '';
			}, $b ), 'strlen' ) : [] );
			$n = isset( $raw[ $v ]['notice_html'] ) && is_string( $raw[ $v ]['notice_html'] )
				? trim( wp_kses( $raw[ $v ]['notice_html'], self::notice_kses() ) ) : '';
			$t = isset( $raw[ $v ]['button_text'] ) && is_string( $raw[ $v ]['button_text'] )
				? trim( sanitize_text_field( $raw[ $v ]['button_text'] ) ) : '';
			$out[ $v ] = [ 'bullets' => array_values( $b ), 'notice_html' => $n, 'button_text' => $t ];
		}
		if ( ! empty( $raw['_all'] ) && is_array( $raw['_all'] ) ) {
			$all = [ 'trust_strip' => [], 'returns_html' => '' ];
			if ( isset( $raw['_all']['trust_strip'] ) && is_array( $raw['_all']['trust_strip'] ) ) {
				foreach ( array_slice( array_values( $raw['_all']['trust_strip'] ), 0, 3 ) as $it ) {
					$all['trust_strip'][] = [
						'title' => is_array( $it ) && isset( $it['title'] ) && is_scalar( $it['title'] ) ? trim( sanitize_text_field( (string) $it['title'] ) ) : '',
						'text'  => is_array( $it ) && isset( $it['text'] ) && is_scalar( $it['text'] ) ? trim( sanitize_text_field( (string) $it['text'] ) ) : '',
					];
				}
			}
			if ( isset( $raw['_all']['returns_html'] ) && is_string( $raw['_all']['returns_html'] ) ) {
				$all['returns_html'] = trim( wp_kses( $raw['_all']['returns_html'], self::returns_kses() ) );
			}
			$out['_all'] = $all;
		}
		return $out;
	}

	private static function global_for( string $variant, string $key ) {
		$g = self::globals();
		return $g[ $variant ][ $key ] ?? ( 'bullets' === $key ? [] : '' );
	}

	/* ---------- wariant i teksty z kodu ---------- */

	/** Wariant, etykieta i punkty domyślne z kodu ETA_Shop_Template (ob_start oryginału). Cache na żądanie. */
	private static function info( WC_Product $product ) : array {
		$id = $product->get_id();
		if ( isset( self::$info_cache[ $id ] ) ) {
			return self::$info_cache[ $id ];
		}
		$info = [ 'variant' => '', 'label' => 'Najważniejsze informacje', 'items' => [] ];
		$html = self::capture( [ 'ETA_Shop_Template', 'render_value_props' ], $product );
		if ( preg_match( '/eta-value-props--([a-z0-9-]+)"\s+aria-label="([^"]*)"/', $html, $m ) ) {
			$info['variant'] = $m[1];
			$info['label']   = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
			if ( preg_match_all( '/<li[^>]*>(.*?)<\/li>/s', $html, $li ) ) {
				foreach ( $li[1] as $item ) {
					$info['items'][] = self::plain( $item );
				}
			}
		}
		return self::$info_cache[ $id ] = $info;
	}

	private static function notice_applies( WC_Product $product ) : bool {
		return ETA_Shop_Template::product_is_lab_service( $product );
	}

	/** Wnętrze <p> domyślnej ramki z kodu ('' gdy produkt nie jest usługą). */
	private static function code_notice_html( WC_Product $product ) : string {
		$html = self::capture( [ 'ETA_Shop_Template', 'render_service_notice' ], $product );
		if ( preg_match( '/<p[^>]*>(.*)<\/p>/s', $html, $m ) ) {
			return trim( preg_replace( '/\s+/u', ' ', $m[1] ) );
		}
		return '';
	}

	private static function code_button_text( WC_Product $product ) : string {
		// shortcut: add_to_cart_text_for_product() oddaje wejście w adminie (is_admin), więc tekst usługi powielamy tu; zmienić razem z nim.
		if ( is_admin() && ! wp_doing_ajax() && self::notice_applies( $product ) ) {
			return 'Zleć badanie';
		}
		self::$bypass_button = true;
		try {
			return (string) $product->single_add_to_cart_text();
		} finally {
			self::$bypass_button = false;
		}
	}

	/** Wynik oryginalnego renderera dla produktu (global $product ustawiany tymczasowo). */
	private static function capture( callable $cb, WC_Product $product ) : string {
		$prev               = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $product;
		ob_start();
		try {
			call_user_func( $cb );
		} finally {
			$html               = (string) ob_get_clean();
			$GLOBALS['product'] = $prev;
		}
		return $html;
	}

	private static function plain( string $html ) : string {
		$t = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $t ) ) );
	}

	private static function lines( string $text ) : array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $l ) {
			$l = trim( $l );
			if ( '' !== $l ) {
				$out[] = $l;
			}
		}
		return $out;
	}

	private static function notice_kses() : array {
		return [
			'strong' => [],
			'em'     => [],
			'br'     => [],
			'a'      => [ 'href' => true, 'title' => true ],
		];
	}

	private static function returns_kses() : array {
		return [
			'p' => [], 'ul' => [], 'ol' => [], 'li' => [], 'strong' => [], 'em' => [], 'br' => [],
			'a' => [ 'href' => true, 'title' => true ],
		];
	}

	/** Pasek kafli: element po elemencie wpis z opcji albo oryginał z kodu (ob_start). */
	public static function render_trust_strip() : void {
		$custom = self::globals()['_all']['trust_strip'] ?? [];
		if ( ! array_filter( $custom, static function ( $i ) {
			return '' !== $i['title'] || '' !== $i['text'];
		} ) ) {
			ETA_Shop_Template::render_trust_strip();
			return;
		}
		ob_start();
		ETA_Shop_Template::render_trust_strip();
		$orig = (string) ob_get_clean();
		preg_match_all( '#<strong>(.*?)</strong>\s*<span>(.*?)</span>#s', $orig, $m, PREG_SET_ORDER );
		?>
		<section class="eta-trust-strip" aria-label="Gwarancje">
			<?php foreach ( $m as $i => $o ) : ?>
				<div class="eta-trust-strip__item">
					<strong><?php echo esc_html( ! empty( $custom[ $i ]['title'] ) ? $custom[ $i ]['title'] : html_entity_decode( $o[1], ENT_QUOTES, 'UTF-8' ) ); ?></strong>
					<span><?php echo esc_html( ! empty( $custom[ $i ]['text'] ) ? $custom[ $i ]['text'] : html_entity_decode( $o[2], ENT_QUOTES, 'UTF-8' ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	public static function swap_returns_callback( $tabs ) {
		if ( is_array( $tabs ) && isset( $tabs['eta_returns'] ) ) {
			$tabs['eta_returns']['callback'] = [ __CLASS__, 'returns_tab_content' ];
		}
		return $tabs;
	}

	public static function returns_tab_content() : void {
		$html = self::globals()['_all']['returns_html'] ?? '';
		if ( '' === $html ) {
			ETA_Shop_Template::returns_tab_content();
			return;
		}
		$html = wp_kses( $html, self::returns_kses() ); // kses wycina klasy, więc dodajemy je z powrotem (CSS ETA: .eta-returns-list, .eta-returns-note).
		$html = str_replace( '<ul>', '<ul class="eta-returns-list">', $html );
		$pos  = strrpos( $html, '<p>' );
		if ( false !== $pos ) {
			$html = substr_replace( $html, '<p class="eta-returns-note">', $pos, 3 );
		}
		echo '<h2 class="eta-tab-title">' . esc_html__( 'Polityka zwrotów', 'pharma-child' ) . '</h2>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function scope_label( string $variant ) : string {
		$map = [
			'lab-service' => 'wszystkie usługi laboratoryjne',
			'lab-prep'    => 'wszystkie wzorce, bufory i preparaty',
			'cosmetics'   => 'wszystkie kosmetyki',
		];
		return $map[ $variant ] ?? '';
	}

	/** Liczba opublikowanych produktów danego wariantu (cache 10 min, liczone tylko w adminie). */
	private static function scope_count( string $variant ) : int {
		if ( '' === $variant ) {
			return 0;
		}
		$counts = get_transient( 'eta_card_scope_counts' );
		if ( ! is_array( $counts ) ) {
			$counts = array_fill_keys( self::VARIANTS, 0 );
			foreach ( wc_get_products( [ 'limit' => -1, 'status' => 'publish', 'return' => 'ids' ] ) as $pid ) {
				$p = wc_get_product( $pid );
				if ( $p ) {
					$v = self::info( $p )['variant'];
					if ( isset( $counts[ $v ] ) ) {
						$counts[ $v ]++;
					}
				}
			}
			set_transient( 'eta_card_scope_counts', $counts, 10 * MINUTE_IN_SECONDS );
		}
		return (int) ( $counts[ $variant ] ?? 0 );
	}

	/**
	 * Teksty domyślne produktu BEZ wpisu per produkt: opcja eta_card_globals albo tekst z kodu.
	 *
	 * @return array{bullets: string[], notice_html: string, button_text: string, variant: string, scope_label: string, scope_count: int,
	 *               code_bullets: string[], code_notice_html: string, code_button_text: string, notice_applies: bool}
	 */
	public static function defaults( int $product_id ) : array {
		$empty = [ 'bullets' => [], 'notice_html' => '', 'button_text' => '', 'variant' => '', 'scope_label' => '', 'scope_count' => 0,
			'code_bullets' => [], 'code_notice_html' => '', 'code_button_text' => '', 'notice_applies' => false ];
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $p || ! class_exists( 'ETA_Shop_Template' ) ) {
			return $empty;
		}
		$info    = self::info( $p );
		$v       = $info['variant'];
		$applies = self::notice_applies( $p );
		$code_n  = $applies ? self::code_notice_html( $p ) : '';
		$code_b  = self::code_button_text( $p );
		$g_b     = self::global_for( $v, 'bullets' );
		$g_n     = $applies ? self::global_for( 'lab-service', 'notice_html' ) : '';
		$g_t     = self::global_for( $v, 'button_text' );
		return [
			'bullets'           => $g_b ? $g_b : $info['items'],
			'notice_html'       => '' !== $g_n ? $g_n : $code_n,
			'button_text'       => '' !== $g_t ? $g_t : $code_b,
			'variant'           => $v,
			'scope_label'       => self::scope_label( $v ),
			'scope_count'       => self::scope_count( $v ),
			'code_bullets'      => $info['items'],
			'code_notice_html'  => $code_n,
			'code_button_text'  => $code_b,
			'notice_applies'    => $applies,
		];
	}

	/* ---------- frontend ---------- */

	private static function current_product() : ?WC_Product {
		global $product;
		return $product instanceof WC_Product ? $product : null;
	}

	public static function render_bullets() : void {
		$p = self::current_product();
		if ( ! $p ) {
			return;
		}
		$id = $p->get_id();
		if ( 'yes' === get_post_meta( $id, '_eta_card_bullets_hide', true ) ) {
			return;
		}
		$info  = self::info( $p );
		$lines = self::lines( (string) get_post_meta( $id, '_eta_card_bullets', true ) );
		if ( ! $lines && '' !== $info['variant'] ) {
			$lines = self::global_for( $info['variant'], 'bullets' );
		}
		if ( ! $lines ) {
			ETA_Shop_Template::render_value_props();
			return;
		}
		$variant = '' !== $info['variant'] ? $info['variant'] : 'custom';
		?>
		<ul class="eta-value-props eta-value-props--<?php echo esc_attr( $variant ); ?>" aria-label="<?php echo esc_attr( $info['label'] ); ?>">
			<?php foreach ( $lines as $line ) : ?>
				<li><span class="eta-vp__dot" aria-hidden="true"></span><?php echo esc_html( $line ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	public static function render_notice() : void {
		$p = self::current_product();
		if ( ! $p ) {
			return;
		}
		$id = $p->get_id();
		if ( 'yes' === get_post_meta( $id, '_eta_card_notice_hide', true ) ) {
			return;
		}
		$html = trim( (string) get_post_meta( $id, '_eta_card_notice', true ) );
		if ( '' === $html && self::notice_applies( $p ) ) {
			$html = self::global_for( 'lab-service', 'notice_html' );
		}
		if ( '' === $html ) {
			ETA_Shop_Template::render_service_notice();
			return;
		}
		echo '<p class="eta-service-notice" role="note">' . wp_kses( nl2br( $html ), self::notice_kses() ) . '</p>';
	}

	public static function filter_button( $text, $product = null ) {
		if ( self::$bypass_button || ! $product instanceof WC_Product || ! class_exists( 'ETA_Shop_Template' ) ) {
			return $text;
		}
		$own = trim( (string) get_post_meta( $product->get_id(), '_eta_card_button', true ) );
		if ( '' !== $own ) {
			return $own;
		}
		$g = self::globals();
		if ( ! $g ) {
			return $text;
		}
		$v = self::info( $product )['variant'];
		return ( '' !== $v && ! empty( $g[ $v ]['button_text'] ) ) ? $g[ $v ]['button_text'] : $text;
	}

	/* ---------- admin ---------- */

	public static function add_boxes() : void {
		if ( ! class_exists( 'ETA_Shop_Template' ) ) {
			return;
		}
		add_meta_box( 'eta-card-bullets', 'Punkty pod tekstem', [ __CLASS__, 'box_bullets' ], 'product', 'normal', 'high' );
		add_meta_box( 'eta-card-notice', 'Ramka informacyjna', [ __CLASS__, 'box_notice' ], 'product', 'normal', 'high' );
		add_meta_box( 'eta-card-button', 'Napis na przycisku', [ __CLASS__, 'box_button' ], 'product', 'normal', 'high' );
	}

	/** Zapisana kolejność boxów (user meta) wrzuca nieznane boxy na koniec; stawiamy swoje trzy pierwsze (zaraz pod opisem). */
	public static function order_first( $order ) {
		if ( is_array( $order ) && isset( $order['normal'] ) && is_string( $order['normal'] ) ) {
			$mine = [ 'eta-card-bullets', 'eta-card-notice', 'eta-card-button' ];
			$rest = array_filter(
				array_map( 'trim', explode( ',', $order['normal'] ) ),
				static function ( $b ) use ( $mine ) {
					return '' !== $b && 'eta-card-texts' !== $b && ! in_array( $b, $mine, true );
				}
			);
			$order['normal'] = implode( ',', array_merge( $mine, $rest ) );
		}
		return $order;
	}

	public static function enqueue( $hook ) : void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		$css = 'assets/css/eta-card-texts-admin.css';
		$js  = 'assets/js/eta-card-texts-admin.js';
		$vc  = file_exists( WPMU_PLUGIN_DIR . '/' . $css ) ? (string) filemtime( WPMU_PLUGIN_DIR . '/' . $css ) : '1';
		$vj  = file_exists( WPMU_PLUGIN_DIR . '/' . $js ) ? (string) filemtime( WPMU_PLUGIN_DIR . '/' . $js ) : '1';

		$sdeps = [ 'dashicons' ];
		if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
			$sdeps[] = 'woocommerce_admin_styles';
		}
		$jdeps = [ 'jquery', 'jquery-ui-sortable' ];
		if ( wp_script_is( 'selectWoo', 'registered' ) ) {
			$jdeps[] = 'selectWoo';
		}
		wp_enqueue_style( 'eta-card-texts-admin', WPMU_PLUGIN_URL . '/' . $css, $sdeps, $vc );
		wp_enqueue_script( 'eta-card-texts-admin', WPMU_PLUGIN_URL . '/' . $js, $jdeps, $vj, true );
	}

	/** Tylko tytuły boxów (callbacki i działanie bez zmian), żeby klient wiedział, co gdzie wyświetla się w sklepie. */
	public static function relabel_boxes() : void {
		global $wp_meta_boxes;
		$titles = [
			'postexcerpt'                => 'Tekst pod ceną — krótki opis',
			'postimagediv'               => 'Zdjęcie główne',
			'woocommerce-product-images' => 'Dodatkowe zdjęcia',
			'product_catdiv'             => 'Kategorie — od nich zależą link »powrót do kategorii«, punkty i przycisk',
			'tagsdiv-product_tag'        => 'Tagi — widoczne pod przyciskiem',
		];
		if ( empty( $wp_meta_boxes['product'] ) ) {
			return;
		}
		foreach ( $wp_meta_boxes['product'] as $context => $prios ) {
			foreach ( $prios as $prio => $boxes ) {
				foreach ( $titles as $id => $title ) {
					if ( isset( $boxes[ $id ] ) && is_array( $boxes[ $id ] ) ) {
						$wp_meta_boxes['product'][ $context ][ $prio ][ $id ]['title'] = $title;
					}
				}
			}
		}
	}

	/** Główny edytor (Opis) nie jest meta boxem, więc podpis wstawiamy nad nim. */
	public static function description_hint( $post ) : void {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		echo '<p class="description" style="margin:8px 0 4px"><strong>Opis — zakładka »Opis«</strong></p>';
	}

	/** Jeden wiersz punktu (repeater). Tekst jest zwykłą wartością pola, nie placeholderem. */
	private static function row_html( string $text ) : string {
		$btns = [
			'dup'  => [ 'admin-page', 'Duplikuj punkt' ],
			'up'   => [ 'arrow-up-alt2', 'Przesuń punkt w górę' ],
			'down' => [ 'arrow-down-alt2', 'Przesuń punkt w dół' ],
			'del'  => [ 'trash', 'Usuń punkt' ],
		];
		ob_start();
		?>
		<li class="eta-card-row">
			<span class="eta-card-handle dashicons dashicons-menu" title="Przeciągnij, aby zmienić kolejność" aria-hidden="true"></span>
			<input type="text" class="eta-card-input" name="eta_card_bullets[]" value="<?php echo esc_attr( $text ); ?>" aria-label="Punkt">
			<?php foreach ( $btns as $k => $b ) : ?>
				<button type="button" class="button eta-card-btn eta-card-<?php echo esc_attr( $k ); ?>" aria-label="<?php echo esc_attr( $b[1] ); ?>" title="<?php echo esc_attr( $b[1] ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( $b[0] ); ?>" aria-hidden="true"></span></button>
			<?php endforeach; ?>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	/** Dopełniacz nazwy zasięgu (zdanie „wspólny dla …”). */
	private static function scope_gen( string $variant ) : string {
		$map = [
			'lab-service' => 'wszystkich usług laboratoryjnych',
			'lab-prep'    => 'wszystkich wzorców, buforów i preparatów',
			'cosmetics'   => 'wszystkich kosmetyków',
		];
		return $map[ $variant ] ?? 'wszystkich produktów tej kategorii';
	}

	private static function produkt_pl( int $n ) : string {
		$j = $n % 10;
		$s = $n % 100;
		return $n . ' ' . ( 1 === $n ? 'produkt' : ( $j >= 2 && $j <= 4 && ( $s < 12 || $s > 14 ) ? 'produkty' : 'produktów' ) );
	}

	/** Nagłówkowe zdanie o zasięgu na samej górze boxa (natywna ramka WP notice-info). */
	private static function scope_notice( array $d ) : void {
		$head = '' !== $d['variant']
			? sprintf( 'Tekst domyślny jest wspólny dla %s (%s).', self::scope_gen( $d['variant'] ), self::produkt_pl( (int) $d['scope_count'] ) )
			: 'Tekst domyślny jest wspólny dla wszystkich produktów tej kategorii.';
		?>
		<div class="notice notice-info inline eta-card-scope">
			<p class="eta-card-scope__head"><?php echo esc_html( $head ); ?></p>
			<p>Wpis tutaj zmienia tylko ten produkt.</p>
		</div>
		<?php
	}

	/** Boxy ETA nigdy nie startują zwinięte (user mógł je kiedyś zwinąć; zapisany stan ignorujemy). */
	public static function never_closed( $classes ) {
		return is_array( $classes ) ? array_values( array_diff( $classes, [ 'closed' ] ) ) : $classes;
	}

	public static function box_bullets( WP_Post $post ) : void {
		$id   = (int) $post->ID;
		$d    = self::defaults( $id );
		$rows = self::lines( (string) get_post_meta( $id, '_eta_card_bullets', true ) );
		if ( ! $rows ) {
			$rows = $d['bullets'];
		}
		if ( ! $rows ) {
			$rows = [ '' ];
		}
		$hide = 'yes' === get_post_meta( $id, '_eta_card_bullets_hide', true );

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<input type="hidden" name="eta_card_has_bullets" value="1">
		<?php self::scope_notice( $d ); ?>
		<p class="description">Tekst pod ceną zmieniasz w polu „Tekst pod ceną — krótki opis” niżej. Tu zmieniasz punkty pod nim (jeden punkt na wiersz).</p>
		<ul id="eta-card-bullets-list" class="eta-card-list" data-defaults="<?php echo esc_attr( wp_json_encode( array_values( $d['bullets'] ) ) ); ?>">
			<?php
			foreach ( $rows as $r ) {
				echo self::row_html( (string) $r ); // phpcs:ignore WordPress.Security.EscapeOutput -- row_html escapes.
			}
			?>
		</ul>
		<template id="eta-card-row-tpl"><?php echo self::row_html( '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>
		<p class="eta-card-toolbar">
			<button type="button" class="button button-secondary eta-card-add">+ Dodaj punkt</button>
			<button type="button" class="button-link eta-card-restore">Przywróć domyślne punkty</button>
		</p>
		<p class="description">Puste wiersze są pomijane. Chcesz, żeby nie było żadnych punktów? Zaznacz „Nie pokazuj punktów”.</p>
		<p><label for="eta_card_bullets_hide"><input type="checkbox" id="eta_card_bullets_hide" name="eta_card_bullets_hide" value="yes" <?php checked( $hide ); ?>> Nie pokazuj punktów</label></p>
		<?php
	}

	public static function box_notice( WP_Post $post ) : void {
		$id   = (int) $post->ID;
		$d    = self::defaults( $id );
		$cur  = trim( (string) get_post_meta( $id, '_eta_card_notice', true ) );
		$txt  = '' !== $cur ? $cur : (string) $d['notice_html'];
		$hide = 'yes' === get_post_meta( $id, '_eta_card_notice_hide', true );
		?>
		<input type="hidden" name="eta_card_has_notice" value="1">
		<?php self::scope_notice( $d ); ?>
		<p class="description">Ramka pod punktami, np. „To usługa laboratoryjna, nie produkt fizyczny…”.</p>
		<?php if ( '' === (string) $d['notice_html'] ) : ?>
			<p class="description">Dla tego produktu domyślnie nie ma ramki (puste pole = brak ramki).</p>
		<?php endif; ?>
		<div class="eta-card-notice-wrap" data-default="<?php echo esc_attr( (string) $d['notice_html'] ); ?>">
			<?php
			wp_editor(
				$txt,
				'eta_card_notice_editor',
				[
					'textarea_name' => 'eta_card_notice',
					'textarea_rows' => 4,
					'media_buttons' => false,
					'teeny'         => true,
					'quicktags'     => false,
					'wpautop'       => false,
					'tinymce'       => [
						'toolbar1'         => 'bold,italic,link,unlink,undo,redo',
						'toolbar2'         => '',
						'toolbar3'         => '',
						'height'           => 120,
						'forced_root_block' => false,
					],
				]
			);
			?>
		</div>
		<p class="description">Pogrubienie, pochylenie i link ustawiasz przyciskami nad polem.</p>
		<p class="eta-card-toolbar"><button type="button" class="button-link eta-card-restore-notice">Przywróć domyślny tekst</button></p>
		<p><label for="eta_card_notice_hide"><input type="checkbox" id="eta_card_notice_hide" name="eta_card_notice_hide" value="yes" <?php checked( $hide ); ?>> Nie pokazuj ramki</label></p>
		<?php
	}

	/** Gotowe napisy na przycisk: domyślne z kodu (eta-shop-template, katalog Light Future) i już użyte na innych produktach. */
	private static function button_options( array $d, string $current ) : array {
		global $wpdb;
		$opts = [ '__default__' => '' !== $d['button_text'] ? $d['button_text'] . ' (domyślny)' : '(domyślny)' ];
		$seen = [ $d['button_text'] => true ];
		$add  = static function ( string $t ) use ( &$opts, &$seen ) {
			$t = trim( $t );
			if ( '' !== $t && empty( $seen[ $t ] ) ) {
				$opts[ $t ] = $t;
				$seen[ $t ] = true;
			}
		};
		// shortcut: lista z kodu (add_to_cart_text_for_product + eta-light-future) wpisana ręcznie; dopisać tu nowy napis, gdy pojawi się w kodzie.
		foreach ( [ 'Zleć badanie', 'Dodaj do koszyka', 'Zamów przedwstępnie' ] as $t ) {
			$add( $t );
		}
		foreach ( self::VARIANTS as $v ) {
			$add( (string) self::global_for( $v, 'button_text' ) );
		}
		$used = $wpdb->get_col( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_eta_card_button' AND meta_value <> '' ORDER BY meta_value LIMIT 50" );
		foreach ( (array) $used as $t ) {
			$add( (string) $t );
		}
		$add( $current );
		return $opts;
	}

	public static function box_button( WP_Post $post ) : void {
		$id  = (int) $post->ID;
		$d   = self::defaults( $id );
		$cur = trim( (string) get_post_meta( $id, '_eta_card_button', true ) );
		$sel = '' !== $cur ? $cur : '__default__';
		?>
		<input type="hidden" name="eta_card_has_button" value="1">
		<?php self::scope_notice( $d ); ?>
		<p class="description">Napis na przycisku zamawiania pod ramką.</p>
		<p><label for="eta_card_button_sel" class="screen-reader-text">Napis na przycisku</label>
		<select id="eta_card_button_sel" name="eta_card_button_sel" class="eta-card-select" style="width:100%">
			<?php foreach ( self::button_options( $d, $cur ) as $val => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $val ); ?>" <?php selected( (string) $val, $sel ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select></p>
		<p class="eta-card-fallback"><label for="eta_card_button_custom">Własny napis (zostaw puste, żeby użyć wyboru z listy)</label><br>
		<input type="text" id="eta_card_button_custom" name="eta_card_button_custom" class="large-text" value=""></p>
		<p class="description">Wybierz z listy (szukaj wpisując) albo wpisz własny napis. „(domyślny)” = napis wspólny, bez zmiany tylko dla tego produktu.</p>
		<?php
	}

	/** Lista punktów z formularza (repeater albo stary textarea), bez pustych. */
	private static function posted_bullets() : array {
		$raw = isset( $_POST['eta_card_bullets'] ) ? wp_unslash( $_POST['eta_card_bullets'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- nonce w save(), sanitize niżej.
		if ( is_string( $raw ) ) {
			$raw = self::lines( $raw );
		}
		$out = [];
		foreach ( (array) $raw as $t ) {
			$t = is_scalar( $t ) ? trim( sanitize_text_field( (string) $t ) ) : '';
			if ( '' !== $t ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/** HTML ramki z edytora (TinyMCE: <p>, U+00A0, bogus <br>) sprowadzony do formatu przechowywanego i wstawianego w <p class="eta-service-notice">. */
	private static function clean_notice( string $h ) : string {
		$h = str_replace( [ "\xC2\xA0", "\r" ], [ '&nbsp;', '' ], $h );
		$h = preg_replace( '#</p>\s*<p[^>]*>#i', '<br><br>', trim( $h ) );
		$h = preg_replace( [ '#^\s*<p[^>]*>#i', '#</p>\s*$#i', '#(\s*<br[^>]*>)+\s*$#i' ], '', (string) $h );
		return trim( wp_kses( (string) $h, self::notice_kses() ) );
	}

	private static function norm_html( string $h ) : string {
		return trim( (string) preg_replace( '/\s+/u', ' ', self::clean_notice( $h ) ) );
	}

	/** Zapis meta albo usunięcie (pusta wartość = produkt idzie za tekstem domyślnym). */
	private static function put( int $post_id, string $key, string $val ) : void {
		if ( '' === trim( $val ) ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $val );
		}
	}

	public static function save( int $post_id ) : void {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! class_exists( 'ETA_Shop_Template' ) ) {
			return;
		}
		$d = self::defaults( $post_id );

		if ( isset( $_POST['eta_card_has_bullets'] ) ) {
			$list = self::posted_bullets();
			// Lista równa domyślnej (albo pusta) = brak własnego wpisu, produkt dalej idzie za domyślnymi.
			self::put( $post_id, '_eta_card_bullets', ( $list === array_values( $d['bullets'] ) ) ? '' : implode( "\n", $list ) );
			self::put( $post_id, '_eta_card_bullets_hide', isset( $_POST['eta_card_bullets_hide'] ) ? 'yes' : '' );
		}

		if ( isset( $_POST['eta_card_has_notice'] ) ) {
			$n = isset( $_POST['eta_card_notice'] ) ? self::clean_notice( wp_unslash( $_POST['eta_card_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- clean_notice() robi wp_kses.
			self::put( $post_id, '_eta_card_notice', ( self::norm_html( $n ) === self::norm_html( (string) $d['notice_html'] ) ) ? '' : $n );
			self::put( $post_id, '_eta_card_notice_hide', isset( $_POST['eta_card_notice_hide'] ) ? 'yes' : '' );
		}

		if ( isset( $_POST['eta_card_has_button'] ) ) {
			$sel = isset( $_POST['eta_card_button_sel'] ) ? sanitize_text_field( wp_unslash( $_POST['eta_card_button_sel'] ) ) : '';
			$cus = isset( $_POST['eta_card_button_custom'] ) ? sanitize_text_field( wp_unslash( $_POST['eta_card_button_custom'] ) ) : '';
			$val = '' !== $cus ? $cus : ( '__default__' === $sel ? '' : $sel );
			self::put( $post_id, '_eta_card_button', ( $val === (string) $d['button_text'] ) ? '' : $val );
		}
	}
}

ETA_Product_Card_Texts::boot();

/* ---------- Colorify „Edytor produktu z podglądem”: karty ETA (filtr colorify_produkt_rejestr, docs/edytor-produktu.md) ----------
 * Wklej na końcu mu-plugins/eta-product-card-texts.php, PO ETA_Product_Card_Texts::boot();
 * Colorify bez tego bloku pokazuje tylko karty WooCommerce. Blok niczego nie zapisuje i nie zmienia strony:
 * mówi edytorowi, które meta i które klucze opcji eta_card_globals pokazać jako karty. */
add_filter(
	'colorify_produkt_rejestr',
	static function ( $r, $id ) {
		if ( ! class_exists( 'ETA_Shop_Template' ) || ! class_exists( 'ETA_Product_Card_Texts' ) ) {
			return $r;
		}
		$r = is_array( $r ) ? $r : [];
		$d = ETA_Product_Card_Texts::defaults( (int) $id );
		$v = (string) $d['variant'];

		$ile = static function ( int $n ) : string {
			$j = $n % 10;
			$s = $n % 100;
			return $n . ' ' . ( 1 === $n ? 'produkt' : ( $j >= 2 && $j <= 4 && ( $s < 12 || $s > 14 ) ? 'produkty' : 'produktów' ) );
		};
		$zasieg    = '' !== $v ? 'Zmiana dotyczy: ' . $d['scope_label'] . ' (' . $ile( (int) $d['scope_count'] ) . ').' : '';
		$wszystkie = 'Zmiana dotyczy wszystkich produktów w sklepie (' . $ile( (int) wp_count_posts( 'product' )->publish ) . ').';
		$wspolne   = static function ( string $tytul, string $zasieg, array $pola ) : array {
			return [
				'opcja'  => 'eta_card_globals',
				'tytul'  => $tytul,
				'zasieg' => $zasieg,
				'pola'   => $pola,
			];
		};

		// Elementy strony ETA dla kart WooCommerce (klik w podglądzie otwiera kartę, fokus w karcie podświetla element).
		$r['selektory'] = [
			'nazwa'     => '.eta-shop-hero__title, .product_title',
			'kategorie' => '.eta-product-category, .product_meta .posted_in',
		] + (array) ( $r['selektory'] ?? [] );

		$r['karty']['baner'] = [
			'tytul'     => 'Baner na górze strony',
			'gdzie'     => 'Zdjęcie z dużym tytułem nad produktem',
			'kolejnosc' => 5,
			'selektor'  => '.eta-shop-hero',
			'info'      => 'Tytuł w banerze to nazwa produktu: zmienisz ją w karcie „Nazwa produktu”. Zdjęcie banera jest jedno dla całego sklepu i nie zmienia się tutaj.',
		];
		$r['karty']['krotki'] = [
			'info' => 'Gdy to pole jest puste, strona sama pokazuje pierwsze zdanie z opisu.',
		];

		$r['karty']['punkty'] = [
			'tytul'     => 'Punkty pod tekstem',
			'gdzie'     => 'Lista z kropkami pod tekstem pod ceną',
			'kolejnosc' => 45,
			'selektor'  => '.eta-value-props',
			'pola'      => [
				'_eta_card_bullets'      => [
					'typ'        => 'linie',
					'etykieta'   => 'Punkty tego produktu (jeden punkt w jednym wierszu)',
					'wiersze'    => 4,
					'domyslne'   => implode( "\n", (array) $d['bullets'] ),
					'selektor'   => '.eta-value-props',
					'podpowiedz' => $d['bullets'] ? '' : 'Ten produkt nie ma punktów domyślnych: puste pole = brak punktów.',
				],
				'_eta_card_bullets_hide' => [
					'typ'      => 'bool',
					'etykieta' => 'Nie pokazuj punktów przy tym produkcie',
				],
			],
			'wspolne'   => '' === $v ? [] : $wspolne(
				'Punkty domyślne',
				$zasieg,
				[
					'bullets' => [
						'typ'      => 'linie',
						'etykieta' => 'Punkty domyślne (jeden punkt w jednym wierszu)',
						'wiersze'  => 4,
						'sciezka'  => [ $v, 'bullets' ],
						'tablica'  => true,
						'domyslne' => implode( "\n", (array) $d['code_bullets'] ),
					],
				]
			),
		];

		$r['karty']['ramka'] = [
			'tytul'     => 'Ramka informacyjna',
			'gdzie'     => 'Ramka pod punktami, nad przyciskiem',
			'kolejnosc' => 50,
			'selektor'  => '.eta-service-notice',
			'pola'      => [
				'_eta_card_notice'      => [
					'typ'        => 'lekki',
					'etykieta'   => 'Tekst ramki tego produktu',
					'wiersze'    => 4,
					'domyslne'   => (string) $d['notice_html'],
					'selektor'   => '.eta-service-notice',
					'podpowiedz' => '' !== (string) $d['notice_html'] ? '' : 'Ten produkt nie ma ramki domyślnej: puste pole = brak ramki.',
				],
				'_eta_card_notice_hide' => [
					'typ'      => 'bool',
					'etykieta' => 'Nie pokazuj ramki przy tym produkcie',
				],
			],
			'wspolne'   => ! $d['notice_applies'] ? [] : $wspolne(
				'Ramka domyślna',
				'lab-service' === $v ? $zasieg : 'Zmiana dotyczy wszystkich usług laboratoryjnych w sklepie.',
				[
					'notice_html' => [
						'typ'      => 'lekki',
						'etykieta' => 'Tekst ramki domyślnej',
						'wiersze'  => 4,
						'sciezka'  => [ 'lab-service', 'notice_html' ],
						'domyslne' => (string) $d['code_notice_html'],
					],
				]
			),
		];

		$r['karty']['przycisk'] = [
			'tytul'     => 'Napis na przycisku',
			'gdzie'     => 'Przycisk zamawiania pod ramką',
			'kolejnosc' => 55,
			'selektor'  => '.single_add_to_cart_button',
			'kafel'     => 'ciemny',
			'pola'      => [
				'_eta_card_button' => [
					'typ'      => 'tekst',
					'etykieta' => 'Napis na przycisku tego produktu',
					'domyslne' => (string) $d['button_text'],
					'selektor' => '.single_add_to_cart_button',
				],
			],
			'wspolne'   => '' === $v ? [] : $wspolne(
				'Napis domyślny',
				$zasieg,
				[
					'button_text' => [
						'typ'      => 'tekst',
						'etykieta' => 'Napis domyślny na przycisku',
						'sciezka'  => [ $v, 'button_text' ],
						'domyslne' => (string) $d['code_button_text'],
					],
				]
			),
		];

		$r['karty']['kategorie'] = [
			'gdzie' => 'Link „← powrót do kategorii” nad nazwą, okruszki, wiersze „Kategorie” i „Tagi” pod przyciskiem',
			'info'  => 'Od kategorii zależą też domyślne punkty, ramka i napis na przycisku. „Product ID” pod tagami to stały numer produktu, nie da się go zmienić.',
		];

		// Polityka zwrotów: tekst z kodu (bez nagłówka) jako treść startowa edytora.
		ob_start();
		ETA_Shop_Template::returns_tab_content();
		$zwroty = trim( (string) preg_replace( '#^\s*<h2[^>]*>.*?</h2>#s', '', (string) ob_get_clean() ) );
		$r['karty']['zwroty'] = [
			'tytul'     => 'Polityka zwrotów — zakładka pod produktem',
			'gdzie'     => 'Trzecia zakładka pod produktem, taka sama przy każdym produkcie',
			'kolejnosc' => 85,
			'zakladka'  => 'eta_returns',
			'selektor'  => '#tab-eta_returns',
			'wspolne'   => $wspolne(
				'',
				$wszystkie,
				[
					'returns_html' => [
						'typ'      => 'html',
						'etykieta' => 'Treść zakładki (nagłówek „Polityka zwrotów” dodaje strona)',
						'wiersze'  => 12,
						'sciezka'  => [ '_all', 'returns_html' ],
						'domyslne' => $zwroty,
						'kses'     => [
							'p'      => [],
							'ul'     => [],
							'ol'     => [],
							'li'     => [],
							'strong' => [],
							'em'     => [],
							'br'     => [],
							'a'      => [
								'href'  => true,
								'title' => true,
							],
						],
					],
				]
			),
		];

		$r['karty']['powiazane'] = [
			'info' => 'Sklep dobiera te produkty sam, losowo z tych samych kategorii co ten produkt. Żeby pokazywały się inne, zmień kategorie w karcie „Kategorie i tagi”.',
		];

		// Pasek trzech kafli: teksty z kodu jako szara podpowiedź; puste pole = tekst z kodu dla tej pozycji.
		ob_start();
		ETA_Shop_Template::render_trust_strip();
		preg_match_all( '#<strong>(.*?)</strong>\s*<span>(.*?)</span>#s', (string) ob_get_clean(), $m, PREG_SET_ORDER );
		$pasek = [];
		foreach ( [ 0, 1, 2 ] as $i ) {
			$pasek[ 'tytul' . $i ] = [
				'typ'          => 'tekst',
				'etykieta'     => 'Kafel ' . ( $i + 1 ) . ': tytuł',
				'sciezka'      => [ '_all', 'trust_strip', $i, 'title' ],
				'zostaw_puste' => true,
				'domyslne'     => html_entity_decode( (string) ( $m[ $i ][1] ?? '' ), ENT_QUOTES, 'UTF-8' ),
			];
			$pasek[ 'tekst' . $i ] = [
				'typ'          => 'tekst',
				'etykieta'     => 'Kafel ' . ( $i + 1 ) . ': tekst',
				'sciezka'      => [ '_all', 'trust_strip', $i, 'text' ],
				'zostaw_puste' => true,
				'domyslne'     => html_entity_decode( (string) ( $m[ $i ][2] ?? '' ), ENT_QUOTES, 'UTF-8' ),
			];
		}
		$r['karty']['pasek'] = [
			'tytul'     => 'Pasek trzech kafli pod produktem',
			'gdzie'     => 'Trzy kafle na dole strony, pod powiązanymi produktami',
			'kolejnosc' => 95,
			'selektor'  => '.eta-trust-strip',
			'kafel'     => 'boksy',
			'wspolne'   => $wspolne( '', $wszystkie, $pasek ),
		];

		return $r;
	},
	10,
	2
);
