<?php
/**
 * Plugin Name: ETA Order Report Form
 * Description: Forma wyników badań (raport papierowy / PDF w koncie) widoczna w adminie (edycja i lista zamówień), mailach, na stronie podziękowania i w Moje konto. Jedno źródło tekstu.
 * Version: 1.0.0
 */

defined( 'ABSPATH' ) || exit;

final class ETA_Order_Report_Form {

	const META_PAPER   = '_eta_paper_report_requested';
	const META_DIGITAL = '_eta_digital_delivery';
	const COLUMN       = 'eta_report_form';

	public static function init() : void {
		// Admin: edycja zamówienia i lista (HPOS + klasyczna).
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'admin_block' ) );
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_column' ), 30 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 30 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_head', array( __CLASS__, 'admin_css' ) );
		// Maile (admin i klient, HTML i plain): po karcie podsumowania klienta (p.8), przed danymi do przelewu (p.10).
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'email_block' ), 9, 4 );
		// Podziękowanie i Moje konto > zamówienie (jedno wywołanie szablonu order-details).
		add_action( 'woocommerce_order_details_before_order_table', array( __CLASS__, 'front_block' ), 9 );
	}

	/**
	 * Forma wyników zamówienia albo null, gdy w zamówieniu nie ma usługi.
	 * Papier: meta `_eta_paper_report_requested` (ustawiana tylko przy koszyku z usługą). Cyfrowo: `_eta_digital_delivery`
	 * albo usługa w pozycjach (np. koszyk mieszany bez papieru).
	 *
	 * @return array{kind:string,short:string,tone:string,text:string}|null
	 */
	public static function describe( $order, bool $for_admin ) : ?array {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}
		$method = trim( (string) $order->get_shipping_method() );

		if ( 'yes' === $order->get_meta( self::META_PAPER ) ) {
			$method = '' !== $method ? $method : __( 'brak metody wysyłki', 'eta-order-report-form' );
			return array(
				'kind'  => 'paper',
				'short' => $for_admin ? 'RAPORT PAPIEROWY' : 'Raport papierowy',
				'tone'  => 'paper',
				'text'  => $for_admin
					/* translators: %s: nazwa metody wysyłki */
					? sprintf( 'Forma wyników: RAPORT PAPIEROWY — wyślij wyniki na adres wysyłki (metoda: %s) + PDF w koncie klienta', $method )
					: sprintf( 'Forma wyników: raport papierowy, wysyłka na adres dostawy (%s). PDF znajdziesz też w koncie (Moje wyniki)', $method ),
			);
		}

		$digital = 'yes' === $order->get_meta( self::META_DIGITAL );
		if ( ! $digital && ! self::has_service( $order ) ) {
			return null;
		}
		$mixed = ! $digital; // usługa + produkty fizyczne, bez papieru: wysyłka dotyczy tylko produktów.
		return array(
			'kind'  => 'pdf',
			'short' => $for_admin ? 'PDF W KONCIE' : 'PDF w koncie',
			'tone'  => 'pdf',
			'text'  => $for_admin
				? 'Forma wyników: PDF w koncie klienta (Moje wyniki), ' . ( $mixed ? 'wysyłka dotyczy tylko produktów fizycznych' : 'bez wysyłki' )
				: 'Forma wyników: raport PDF w Twoim koncie (Moje wyniki)',
		);
	}

	/** Zamówienie z papierem i bez produktów fizycznych (same usługi): teksty maili mówią o raporcie, nie o paczce. */
	public static function is_paper_service_only( $order ) : bool {
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( self::META_PAPER ) ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $product instanceof WC_Product && ! self::product_is_service( $product ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Podpisy osi dla zamówień z papierem i samymi usługami: mówią o raporcie, nie o paczce (mail, podziękowanie, Moje konto).
	 * Inne zamówienia: bez zmian.
	 *
	 * @param list<array<string,mixed>> $steps
	 * @return array{0:string,1:list<array<string,mixed>>} [ tytuł, kroki ]
	 */
	public static function relabel_paper_timeline( $order, string $title, array $steps ) : array {
		if ( ! self::is_paper_service_only( $order ) ) {
			return array( $title, $steps );
		}
		foreach ( $steps as $i => $step ) {
			$done = ! empty( $step['done'] );
			switch ( (string) ( $step['key'] ?? '' ) ) {
				case 'confirmed':
					$steps[ $i ]['desc'] = __( 'Zamówienie zostało potwierdzone. Zaczynamy badania.', 'pharma-child' );
					break;
				case 'shipped':
					$steps[ $i ]['title'] = __( 'Raport papierowy w drodze', 'pharma-child' );
					$steps[ $i ]['desc']  = $done ? __( 'Raport papierowy został nadany. Możesz śledzić przesyłkę u kuriera.', 'pharma-child' ) : __( 'Raport papierowy zostanie nadany po zakończeniu badań.', 'pharma-child' );
					break;
				case 'delivered':
					$steps[ $i ]['title'] = __( 'Raport papierowy dostarczony', 'pharma-child' );
					$steps[ $i ]['desc']  = __( 'Raport dotarł do odbiorcy.', 'pharma-child' );
					break;
			}
		}
		return array( __( 'Status wysyłki raportu', 'pharma-child' ), $steps );
	}

	private static function product_is_service( WC_Product $product ) : bool {
		return class_exists( 'ETA_Shipping_Payments' ) ? ETA_Shipping_Payments::product_is_digital_service( $product ) : 'yes' === $product->get_meta( '_eta_is_service' );
	}

	private static function has_service( WC_Order $order ) : bool {
		foreach ( $order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			if ( self::product_is_service( $product ) ) {
				return true;
			}
		}
		return false;
	}

	/** Etykieta pogrubiona, reszta zwykła; „Moje wyniki” jako link tylko dla klienta. */
	private static function sentence_html( string $text, bool $link ) : string {
		$html = esc_html( $text );
		if ( $link ) {
			$url  = function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'moje-wyniki' ) : '';
			$html = '' !== $url ? str_replace( 'Moje wyniki', '<a href="' . esc_url( $url ) . '" style="color:#0b1b3d;font-weight:600;">Moje wyniki</a>', $html ) : $html;
		}
		return (string) preg_replace( '/^Forma wyników:/u', '<strong>Forma wyników:</strong>', $html, 1 );
	}

	/** Kolory z palety maili ETA (eta-email-confirmation.php): papier = bursztyn, PDF = zieleń. */
	private static function tone( string $tone ) : array {
		return 'paper' === $tone ? array( '#fff7e6', '#9a5b00' ) : array( '#e8f7f0', '#047857' );
	}

	/* ---------- admin: edycja zamówienia ---------- */

	public static function admin_block( $order ) : void {
		$d = self::describe( $order, true );
		if ( ! $d ) {
			return;
		}
		// Klasy statusów WC (bez własnych kolorów): papier jak „wstrzymane”, PDF jak „w trakcie realizacji”.
		$status = 'paper' === $d['kind'] ? 'status-on-hold' : 'status-processing';
		echo '<p class="form-field form-field-wide eta-report-form"><label>' . esc_html__( 'Forma wyników', 'eta-order-report-form' ) . '</label>';
		echo '<mark class="order-status ' . esc_attr( $status ) . '"><span>' . esc_html( $d['short'] ) . '</span></mark>';
		echo '<span class="eta-report-form__text">' . esc_html( $d['text'] ) . '</span></p>';
	}

	/* ---------- admin: lista zamówień ---------- */

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function add_column( $columns ) : array {
		$out = array();
		foreach ( (array) $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Wyniki', 'eta-order-report-form' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Wyniki', 'eta-order-report-form' );
		}
		return $out;
	}

	/**
	 * @param string       $column Column id.
	 * @param int|WC_Order $order  Order (HPOS) lub ID posta (lista klasyczna).
	 */
	public static function render_column( $column, $order = null ) : void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order );
		$d     = self::describe( $order, true );
		if ( ! $d ) {
			echo '<span aria-hidden="true">–</span>';
			return;
		}
		$status = 'paper' === $d['kind'] ? 'status-on-hold' : 'status-processing';
		$label  = 'paper' === $d['kind'] ? 'Papier' : 'PDF';
		echo '<mark class="order-status ' . esc_attr( $status ) . '" title="' . esc_attr( $d['text'] ) . '"><span>' . esc_html( $label ) . '</span></mark>';
	}

	public static function admin_css() : void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order' ), true ) ) {
			return;
		}
		echo '<style>.column-eta_report_form{width:90px}.eta-report-form mark.order-status{margin:0 0 6px}.eta-report-form .eta-report-form__text{display:block;clear:both;font-weight:600;line-height:1.5}</style>';
	}

	/* ---------- maile ---------- */

	/**
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Mail do sprzedawcy.
	 * @param bool     $plain_text    Plain text.
	 */
	public static function email_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ) : void { // phpcs:ignore
		$d = self::describe( $order, (bool) $sent_to_admin );
		if ( ! $d ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . $d['text'] . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$c = self::tone( $d['tone'] );
		echo '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation" style="margin:0 0 16px;background:' . esc_attr( $c[0] ) . ';border-radius:14px;"><tr><td style="padding:14px 18px;color:#1a1f2e;font-size:15px;line-height:1.5;">';
		echo '<span style="display:block;margin:0 0 2px;color:' . esc_attr( $c[1] ) . ';font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;">' . esc_html( $d['short'] ) . '</span>';
		echo self::sentence_html( $d['text'], ! $sent_to_admin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sentence_html escapuje.
		echo '</td></tr></table>';
	}

	/* ---------- podziękowanie i Moje konto ---------- */

	public static function front_block( $order ) : void {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}
		$d = self::describe( $order, false );
		if ( ! $d ) {
			return;
		}
		$c = self::tone( $d['tone'] );
		echo '<p class="eta-report-form eta-report-form--' . esc_attr( $d['kind'] ) . '" role="status" style="margin:0 0 16px;padding:14px 18px;border-radius:14px;background:' . esc_attr( $c[0] ) . ';color:#1a1f2e;font-size:15px;line-height:1.5;">';
		echo '<span style="display:block;margin:0 0 2px;color:' . esc_attr( $c[1] ) . ';font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;">' . esc_html( $d['short'] ) . '</span>';
		echo self::sentence_html( $d['text'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sentence_html escapuje.
		echo '</p>';
	}
}

ETA_Order_Report_Form::init();
