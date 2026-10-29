<?php
/**
 * Plugin Name: ETA - katalog Kosmetyki Light Future
 * Description: Ukryty świąteczny katalog kosmetyków Light Future (tylko z linku) i zamówienia przedwstępne bez cen. Shortcode [eta_light_future]. Zamówienia oznaczone "Kosmetyki Light Future", filtr i suma sztuk w panelu zamówień.
 * Version: 1.1.0
 * Author: INYFINN
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 *
 * Wtyczka strony etainnovations.com, przechowywana w repozytorium Colorify (site-plugins/eta-light-future).
 */

defined( 'ABSPATH' ) || exit;

const ETA_LF_VERSION = '1.1.0';
const ETA_LF_CAT     = 'kosmetyki-light-future';
const ETA_LF_XMAS    = 'oferta-swiateczna'; // produkt w obu kategoriach = tylko w katalogu, bez ceny
const ETA_LF_META    = '_eta_light_future';
const ETA_LF_LABEL   = 'Kosmetyki Light Future';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/* ===================== Dane ===================== */

/**
 * Produkty katalogu w kolejności menu.
 *
 * @return WC_Product[]
 */
function eta_lf_products(): array {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return array();
	}
	$all = wc_get_products( array( 'category' => array( ETA_LF_CAT ), 'status' => 'publish', 'limit' => 100, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
	return array_values( array_filter( $all, static fn( $p ) => eta_lf_is_lf_product( $p->get_id() ) ) );
}

function eta_lf_is_lf_product( $product_id ): bool {
	$parent = wp_get_post_parent_id( (int) $product_id );
	$id = $parent ? $parent : (int) $product_id;
	// Katalog przedwstępny: produkt musi być w OBU kategoriach. Sam "Kosmetyki Light Future" = zwykły produkt sklepu.
	return has_term( ETA_LF_CAT, 'product_cat', $id ) && has_term( ETA_LF_XMAS, 'product_cat', $id );
}

/**
 * Polska typografia: spójniki i przyimki jednoliterowe sklejone z następnym słowem, ostatnie dwa słowa razem (bez wdów).
 *
 * @param string $text Tekst zwykły (bez HTML).
 */
function eta_lf_typo( string $text ): string {
	$t = esc_html( trim( $text ) );
	$t = preg_replace( '/(^|\s)([aiouwzAIOUWZ]|na|do|od|po|ze|we|się)\s+/u', '$1$2&nbsp;', $t );
	return preg_replace( '/\s+(\S+)$/u', '&nbsp;$1', $t );
}

/* ===================== Widoczność ===================== */

/** Czy w "Kosmetyki Light Future" jest choć jeden zwykły produkt (bez "Oferta świąteczna"). */
function eta_lf_has_regular_products(): bool {
	$cached = get_transient( 'eta_lf_regular' );
	if ( false !== $cached ) {
		return '1' === $cached;
	}
	$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 1, 'tax_query' => array( 'relation' => 'AND', array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => ETA_LF_CAT ), array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => ETA_LF_XMAS, 'operator' => 'NOT IN' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	set_transient( 'eta_lf_regular', $ids ? '1' : '0', HOUR_IN_SECONDS );
	return (bool) $ids;
}

/*
 * Widoczność w sklepie liczona z kategorii przy każdym zapisie produktu:
 * obie kategorie = ukryty w sklepie i wyszukiwarce; usunięta "Oferta świąteczna" = zwykły produkt (widoczność wraca).
 */
add_action(
	'woocommerce_after_product_object_save',
	static function ( $product ) {
		static $busy = false;
		if ( $busy || ! $product instanceof WC_Product || $product->get_parent_id() ) {
			return;
		}
		delete_transient( 'eta_lf_regular' );
		$pre  = eta_lf_is_lf_product( $product->get_id() );
		$auto = (bool) $product->get_meta( '_eta_lf_auto_hidden' );
		$busy = true;
		if ( $pre && 'hidden' !== $product->get_catalog_visibility() ) {
			$product->set_catalog_visibility( 'hidden' );
			$product->update_meta_data( '_eta_lf_auto_hidden', 1 );
			$product->save();
		} elseif ( ! $pre && $auto ) {
			$product->set_catalog_visibility( 'visible' );
			$product->delete_meta_data( '_eta_lf_auto_hidden' );
			$product->save();
		}
		$busy = false;
	}
);
// Zmiana samych kategorii (szybka edycja, masowa edycja) też przelicza widoczność.
add_action(
	'set_object_terms',
	static function ( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'product_cat' === $taxonomy && 'product' === get_post_type( $object_id ) ) {
			$p = wc_get_product( $object_id );
			if ( $p ) {
				do_action( 'woocommerce_after_product_object_save', $p, null );
			}
		}
	},
	20,
	4
);

// Kategoria nie pojawia się w listach kategorii na stronie (sklep, widżety, menu automatyczne).
add_filter(
	'get_terms',
	static function ( $terms, $taxonomies, $args = array() ) {
		// Tylko listy kategorii; pytania o kategorie konkretnego produktu (object_ids, has_term) zostają nietknięte.
		if ( is_admin() || wp_doing_ajax() || ! empty( $args['object_ids'] ) || ! in_array( 'product_cat', (array) $taxonomies, true ) || ! is_array( $terms ) ) {
			return $terms;
		}
		$hide = array( ETA_LF_XMAS );
		if ( ! eta_lf_has_regular_products() ) {
			$hide[] = ETA_LF_CAT;
		}
		return array_values( array_filter( $terms, static fn( $t ) => ! ( $t instanceof WP_Term && in_array( $t->slug, $hide, true ) ) ) );
	},
	10,
	3
);

// Strony z katalogiem i produkty Light Future poza wyszukiwarkami.
add_action(
	'wp_head',
	static function () {
		$is_catalog = is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'eta_light_future' );
		$is_lf      = ( is_singular( 'product' ) && eta_lf_is_lf_product( get_queried_object_id() ) ) || is_tax( 'product_cat', ETA_LF_XMAS );
		if ( $is_catalog || $is_lf ) {
			echo "<meta name=\"robots\" content=\"noindex, nofollow\">\n";
		}
	},
	1
);
add_filter(
	'rank_math/frontend/robots',
	static function ( $robots ) {
		$id = get_queried_object_id();
		if ( ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', $id ), 'eta_light_future' ) ) || ( is_singular( 'product' ) && eta_lf_is_lf_product( $id ) ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'nofollow';
		}
		return $robots;
	}
);

// Bez ceny i bez "Dodaj do koszyka" na karcie produktu: zamawia się tylko z katalogu.
add_filter(
	'woocommerce_get_price_html',
	static function ( $html, $product ) {
		return ( $product instanceof WC_Product && eta_lf_is_lf_product( $product->get_id() ) ) ? '' : $html;
	},
	20,
	2
);
add_filter(
	'woocommerce_is_purchasable',
	static function ( $ok, $product ) {
		return ( $product instanceof WC_Product && eta_lf_is_lf_product( $product->get_id() ) ) ? false : $ok;
	},
	20,
	2
);

/* ===================== Katalog (shortcode) ===================== */

// Klasa na body strony z katalogiem: pełna szerokość, granat od nagłówka do stopki.
add_filter(
	'body_class',
	static function ( $classes ) {
		if ( is_singular() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'eta_light_future' ) ) {
			$classes[] = 'eta-lf-page';
		}
		return $classes;
	}
);

add_shortcode(
	'eta_light_future',
	static function () {
		$products = eta_lf_products();
		$base     = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_style( 'eta-lf-fonts', 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600&family=Instrument+Sans:wght@500;600;700&family=DM+Sans:wght@400;500;600&display=swap', array(), null );
		wp_enqueue_style( 'eta-lf', $base . 'lf.css', array(), ETA_LF_VERSION );
		wp_enqueue_script( 'eta-lf', $base . 'lf.js', array(), ETA_LF_VERSION, true );
		wp_localize_script(
			'eta-lf',
			'etaLF',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'eta-lf-order' ),
			)
		);

		ob_start();
		?>
		<div class="lf" id="lf">
			<canvas class="lf-snow" aria-hidden="true"></canvas>
			<section class="lf-hero">
				<p class="lf-eyebrow">Kolekcja świąteczna<br>Light Future</p>
				<h1 class="lf-title">Ciepło, które<br>pachnie miodem</h1>
				<p class="lf-lead"><?php echo eta_lf_typo( 'Dziesięć naturalnych kosmetyków na prezent i dla siebie.' ); ?><br><br><?php echo eta_lf_typo( 'Zbieramy zamówienia przedwstępne, ceny podamy przed wysyłką.' ); ?></p>
			</section>
			<div class="lf-list">
				<?php
				$count = count( $products );
				foreach ( $products as $i => $p ) :
					$img  = wp_get_attachment_image( $p->get_image_id(), 'large', false, array( 'class' => 'lf-img', 'loading' => $i < 2 ? 'eager' : 'lazy', 'alt' => $p->get_name() ) );
					$pack = (string) $p->get_meta( '_lf_pack' );
					$keys = (string) $p->get_meta( '_lf_keys' );
					?>
					<article class="lf-item" data-id="<?php echo (int) $p->get_id(); ?>">
						<div class="lf-ph"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
						<div class="lf-body">
							<p class="lf-num"><?php echo esc_html( sprintf( '%02d / %02d', $i + 1, $count ) ); ?></p>
							<h2 class="lf-name"><?php echo eta_lf_typo( $p->get_name() ); ?></h2>
							<p class="lf-desc"><?php echo eta_lf_typo( wp_strip_all_tags( $p->get_short_description() ) ); ?></p>
							<details class="lf-more"><summary>Działanie i skład</summary><div><?php echo wp_kses_post( wpautop( $p->get_description() ) ); ?></div></details>
							<div class="lf-meta">
								<?php if ( $pack ) : ?><span class="lf-pill"><?php echo esc_html( $pack ); ?></span><?php endif; ?>
								<?php if ( $keys ) : ?><span class="lf-pill"><?php echo esc_html( $keys ); ?></span><?php endif; ?>
								<div class="lf-st" role="group" aria-label="<?php echo esc_attr( 'Ilość: ' . $p->get_name() ); ?>">
									<button type="button" data-step="-1" aria-label="Mniej">−</button>
									<input type="text" inputmode="numeric" pattern="[0-9]*" value="0" aria-label="Ilość">
									<button type="button" data-step="1" aria-label="Więcej">+</button>
								</div>
							</div>
						</div>
					</article>
				<?php endforeach; ?>
			</div>

			<div class="lf-bar" role="region" aria-label="Zamówienie">
				<span class="lf-bar__sum">Wybrano: <b data-total>0</b> szt.</span>
				<button type="button" class="lf-cta" data-open disabled>Zamów przedwstępnie</button>
			</div>

			<div class="lf-modal" hidden>
				<div class="lf-sheet" role="dialog" aria-modal="true" aria-labelledby="lf-sheet-t">
					<button type="button" class="lf-x" data-close aria-label="Zamknij">×</button>
					<h2 id="lf-sheet-t">Zamówienie przedwstępne</h2>
					<p class="lf-note">Nie płacisz teraz. Potwierdzimy cenę i termin mailowo albo telefonicznie.</p>
					<ul class="lf-sum" data-list></ul>
					<form class="lf-form" novalidate>
						<label>Imię i nazwisko<input name="name" autocomplete="name" required></label>
						<label>E-mail<input name="email" type="email" autocomplete="email" required></label>
						<label>Telefon<input name="phone" type="tel" autocomplete="tel"></label>
						<label>Uwagi (opcjonalnie)<textarea name="note" rows="3"></textarea></label>
						<input name="website" class="lf-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
						<p class="lf-err" role="alert" hidden></p>
						<button type="submit" class="lf-cta lf-cta--wide">Zamów przedwstępnie</button>
					</form>
					<div class="lf-done" hidden>
						<h2>Dziękujemy!</h2>
						<p>Zamówienie <b data-no></b> przyjęte. Wysłaliśmy potwierdzenie na Twój e-mail.</p>
					</div>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
);

/* ===================== Zamówienie ===================== */

function eta_lf_ajax_order(): void {
	if ( ! check_ajax_referer( 'eta-lf-order', 'nonce', false ) ) {
		wp_send_json_error( array( 'msg' => 'Strona wygasła. Odśwież ją i spróbuj jeszcze raz.' ), 403 );
	}
	if ( ! empty( $_POST['website'] ) ) { // pułapka na boty
		wp_send_json_success( array( 'no' => '' ) );
	}
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key = 'eta_lf_rate_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 5 ) {
		wp_send_json_error( array( 'msg' => 'Za dużo zamówień w krótkim czasie. Spróbuj za godzinę albo napisz do nas.' ), 429 );
	}

	$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
	$note  = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
	$items = json_decode( wp_unslash( (string) ( $_POST['items'] ?? '' ) ), true );
	if ( '' === $name || ! is_email( $email ) ) {
		wp_send_json_error( array( 'msg' => 'Podaj imię i nazwisko oraz poprawny e-mail.' ), 400 );
	}
	$lines = array();
	foreach ( (array) $items as $id => $qty ) {
		$id  = absint( $id );
		$qty = min( 999, absint( $qty ) );
		if ( $qty > 0 && eta_lf_is_lf_product( $id ) && wc_get_product( $id ) ) {
			$lines[ $id ] = $qty;
		}
	}
	if ( ! $lines ) {
		wp_send_json_error( array( 'msg' => 'Wybierz co najmniej jeden produkt.' ), 400 );
	}

	$parts = preg_split( '/\s+/u', $name, 2 );
	$order = wc_create_order( array( 'created_via' => 'eta-light-future' ) );
	foreach ( $lines as $id => $qty ) {
		$order->add_product( wc_get_product( $id ), $qty, array( 'subtotal' => 0, 'total' => 0 ) );
	}
	$order->set_billing_first_name( $parts[0] );
	$order->set_billing_last_name( $parts[1] ?? '' );
	$order->set_billing_email( $email );
	$order->set_billing_phone( $phone );
	$order->set_customer_note( $note );
	$order->set_customer_ip_address( $ip );
	$order->set_payment_method_title( 'Zamówienie przedwstępne (bez płatności)' );
	$order->update_meta_data( ETA_LF_META, 1 );
	$order->calculate_totals( false );
	$order->save();
	$order->update_status( 'processing', 'Zamówienie przedwstępne z katalogu ' . ETA_LF_LABEL . '.' );
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );

	// "W trakcie realizacji" wysyła klientowi potwierdzenie; administrator dostaje zwykły mail o nowym zamówieniu.
	$mails = WC()->mailer()->get_emails();
	if ( isset( $mails['WC_Email_New_Order'] ) ) {
		$mails['WC_Email_New_Order']->trigger( $order->get_id(), $order );
	}
	wp_send_json_success( array( 'no' => '#' . $order->get_order_number() ) );
}
add_action( 'wp_ajax_eta_lf_order', 'eta_lf_ajax_order' );
add_action( 'wp_ajax_nopriv_eta_lf_order', 'eta_lf_ajax_order' );

function eta_lf_is_lf_order( $order ): bool {
	return $order instanceof WC_Order && (bool) $order->get_meta( ETA_LF_META );
}

// Maile i podgląd zamówienia: bez kwot, z wyraźnym oznaczeniem.
add_filter(
	'woocommerce_get_order_item_totals',
	static function ( $rows, $order ) {
		if ( ! eta_lf_is_lf_order( $order ) ) {
			return $rows;
		}
		return array(
			'eta_lf' => array(
				'label' => 'Rodzaj:',
				'value' => 'Zamówienie przedwstępne ' . ETA_LF_LABEL . ' (bez ceny, cenę potwierdzimy)',
			),
		);
	},
	20,
	2
);
add_filter(
	'woocommerce_order_formatted_line_subtotal',
	static function ( $subtotal, $item, $order ) {
		return eta_lf_is_lf_order( $order ) ? '-' : $subtotal;
	},
	20,
	3
);
add_action(
	'woocommerce_email_before_order_table',
	static function ( $order, $sent_to_admin = false ) {
		if ( $sent_to_admin && eta_lf_is_lf_order( $order ) ) { // klient ma własny baner w szablonie maila
			echo '<p style="margin:0 0 16px;padding:10px 14px;background:#fbe4e1;color:#8a2a24;border-radius:8px;font-weight:600">' . esc_html( ETA_LF_LABEL ) . ': zamówienie przedwstępne, bez płatności.</p>';
		}
	},
	5,
	2
);

/* ===================== Panel zamówień ===================== */

// Plakietka przy numerze zamówienia (HPOS i stara lista).
$eta_lf_badge = static function ( $column, $order ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
	if ( 'order_number' === $column && eta_lf_is_lf_order( $order ) ) {
		echo ' <span class="eta-lf-tag">' . esc_html( ETA_LF_LABEL ) . '</span>';
	}
};
add_action( 'manage_woocommerce_page_wc-orders_custom_column', $eta_lf_badge, 20, 2 );
add_action( 'manage_shop_order_posts_custom_column', $eta_lf_badge, 20, 2 );

// Filtr "Kosmetyki Light Future" nad listą zamówień: ?eta_lf=1.
add_filter(
	'woocommerce_order_list_table_prepare_items_query_args',
	static function ( $args ) {
		if ( ! empty( $_GET['eta_lf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['meta_query']   = (array) ( $args['meta_query'] ?? array() );
			$args['meta_query'][] = array( 'key' => ETA_LF_META, 'value' => '1' );
		}
		return $args;
	}
);
add_filter(
	'views_woocommerce_page_wc-orders',
	static function ( $views ) {
		$url             = add_query_arg( array( 'page' => 'wc-orders', 'eta_lf' => 1 ), admin_url( 'admin.php' ) );
		$cur             = ! empty( $_GET['eta_lf'] ) ? ' class="current" aria-current="page"' : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$views['eta_lf'] = '<a href="' . esc_url( $url ) . '"' . $cur . '>' . esc_html( ETA_LF_LABEL ) . ' <span class="count">(' . (int) count( eta_lf_order_ids() ) . ')</span></a>';
		return $views;
	}
);

/**
 * Zamówienia Light Future bez anulowanych, nieudanych i zwróconych.
 *
 * @return int[]
 */
function eta_lf_order_ids(): array {
	return wc_get_orders(
		array(
			'limit'      => -1,
			'return'     => 'ids',
			'status'     => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-cancelled', 'wc-failed', 'wc-refunded', 'wc-checkout-draft' ) ),
			'meta_query' => array( array( 'key' => ETA_LF_META, 'value' => '1' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
}

// Suma sztuk nad listą, gdy filtr jest włączony. WooCommerce chowa komunikaty na swoich ekranach,
// więc tabelka idzie w stopce i skrypt przenosi ją nad listę zamówień.
add_action(
	'admin_footer',
	static function () {
		$screen = get_current_screen();
		if ( empty( $_GET['eta_lf'] ) || ! $screen || false === strpos( (string) $screen->id, 'wc-orders' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$sum = array();
		foreach ( eta_lf_order_ids() as $oid ) {
			foreach ( wc_get_order( $oid )->get_items() as $item ) {
				$name         = $item->get_name();
				$sum[ $name ] = ( $sum[ $name ] ?? 0 ) + (int) $item->get_quantity();
			}
		}
		arsort( $sum );
		echo '<template id="eta-lf-sum-tpl"><div class="eta-lf-sum"><p class="eta-lf-sum__t">Suma zamówień przedwstępnych: <b>' . (int) array_sum( $sum ) . ' szt.</b> (bez anulowanych)</p><table><tbody>';
		foreach ( $sum as $name => $qty ) {
			echo '<tr><td>' . esc_html( $name ) . '</td><td><b>' . (int) $qty . ' szt.</b></td></tr>';
		}
		if ( ! $sum ) {
			echo '<tr><td>Jeszcze nie ma zamówień.</td><td></td></tr>';
		}
		echo '</tbody></table></div></template>';
		echo '<script>(function(){var t=document.getElementById("eta-lf-sum-tpl"),a=document.querySelector("#wc-orders-filter")||document.querySelector(".wp-header-end");if(t&&a){a.parentNode.insertBefore(t.content.cloneNode(true),a);}})();</script>';
	}
);

add_action(
	'admin_head',
	static function () {
		echo '<style>.eta-lf-tag{display:inline-block;margin-left:6px;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:600;line-height:16px;background:var(--colorify-tag-bad-bg,#fbe4e1);color:var(--colorify-tag-bad-fg,#8a2a24)}'
			. '.eta-lf-sum{clear:both;margin:12px 0 16px;padding:18px 22px;background:var(--colorify-ui-surface,#fff);border-radius:var(--colorify-radius-card,20px);box-shadow:var(--colorify-shadow-card,0 1px 3px rgba(0,0,0,.06));max-width:640px}'
			. '.eta-lf-sum__t{margin:0 0 10px;font-size:14px}.eta-lf-sum table{width:100%;border-collapse:collapse}.eta-lf-sum td{padding:6px 0;border-top:1px solid rgba(0,0,0,.06)}.eta-lf-sum td:last-child{text-align:right;white-space:nowrap}</style>';
	}
);
