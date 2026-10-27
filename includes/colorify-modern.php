<?php
/**
 * Styl panelu (Klasyczny / Nowoczesny) i komunikaty, które zawsze da się zamknąć.
 *
 * Nowoczesny: cały wp-admin dostaje zaokrąglone karty bez ramek, miękkie pola, przyciski-pigułki, spokojne tabele
 * i jednolite komunikaty (assets/colorify-modern.css). Klasyczny: wygląd WordPressa bez zmian.
 * Wspólne zmienne (--colorify-radius-*, --colorify-shadow-card, --colorify-tag-*) dostają też mu-pluginy stron.
 *
 * Komunikaty: każdy komunikat w panelu dostaje krzyżyk (także te bez is-dismissible). Zamknięcie zapamiętuje
 * się dla konta po odcisku treści, więc ten sam komunikat nie wraca; przywrócenie w Ustawienia, Colorify.
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_STYLE_OPTION        = 'colorify_admin_style';
const COLORIFY_DISMISSED_META      = 'colorify_dismissed_notices';
const COLORIFY_DISMISSED_MAX       = 300;

/**
 * Styl panelu witryny: modern albo classic (domyślnie classic, czyli bez zmian po aktualizacji).
 */
function colorify_get_admin_style(): string {
	return 'modern' === get_option( COLORIFY_STYLE_OPTION, 'classic' ) ? 'modern' : 'classic';
}

/**
 * Ekrany, na których nie zmieniamy wyglądu: edytory pełnoekranowe mają własny interfejs.
 */
function colorify_modern_screen_excluded(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return false;
	}
	if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
		return true;
	}
	return in_array( $screen->id, array( 'site-editor', 'customize' ), true );
}

/**
 * Klasa na body: colorify-style-modern albo colorify-style-classic.
 *
 * @param string $classes Klasy.
 */
function colorify_modern_body_class( $classes ): string {
	$classes = is_string( $classes ) ? $classes : '';
	if ( 'modern' !== colorify_get_admin_style() || colorify_modern_screen_excluded() ) {
		return trim( $classes . ' colorify-style-classic' );
	}
	return trim( $classes . ' colorify-style-modern' );
}
add_filter( 'admin_body_class', 'colorify_modern_body_class', 20 );

/**
 * Arkusz stylu nowoczesnego i skrypt zamykania komunikatów.
 */
function colorify_modern_enqueue(): void {
	if ( 'modern' === colorify_get_admin_style() && ! colorify_modern_screen_excluded() ) {
		wp_enqueue_style( 'colorify-modern', COLORIFY_PLUGIN_URL . 'assets/colorify-modern.css', array(), COLORIFY_PLUGIN_VERSION );
	}
	if ( get_current_user_id() > 0 ) {
		wp_enqueue_script( 'colorify-notices', COLORIFY_PLUGIN_URL . 'assets/colorify-notices.js', array(), COLORIFY_PLUGIN_VERSION, true );
		wp_add_inline_script(
			'colorify-notices',
			'window.colorifyNotices = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'colorify-notices' ),
					'label'   => __( 'Zamknij komunikat', 'colorify-by-inyfinn' ),
				),
				JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
	}
}
add_action( 'admin_enqueue_scripts', 'colorify_modern_enqueue', 110 );

/**
 * Wcześnie w nagłówku: zmienne stylu i ukrywanie zamkniętych komunikatów, zanim się pokażą (bez mignięcia).
 */
function colorify_modern_head(): void {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}
	if ( 'modern' === colorify_get_admin_style() ) {
		echo '<style id="colorify-style-tokens">:root{--colorify-radius-card:20px;--colorify-radius-control:12px;--colorify-radius-pill:999px;'
			. '--colorify-shadow-card:0 1px 2px rgba(16,24,40,.04),0 1px 3px rgba(16,24,40,.03);'
			. '--colorify-tag-ok-bg:#e2f4ea;--colorify-tag-ok-fg:#167443;--colorify-tag-info-bg:#e3eefc;--colorify-tag-info-fg:#1c5bb8;'
			. '--colorify-tag-warn-bg:#fdf1d8;--colorify-tag-warn-fg:#8a5a00;--colorify-tag-bad-bg:#fde7e9;--colorify-tag-bad-fg:#b42335;--colorify-tag-neutral-bg:color-mix(in srgb,var(--colorify-ui-text,#1d2327) 8%,transparent);--colorify-tag-neutral-fg:var(--colorify-ui-text-muted,#646970)}</style>' . "\n";
	}
	// Krzyżyk na komunikatach działa w obu stylach.
	echo '<style id="colorify-notice-close">.colorify-closable{position:relative;padding-right:48px!important}'
		. '.colorify-notice-close{position:absolute;top:50%;right:10px;transform:translateY(-50%);display:inline-grid;place-items:center;width:32px;height:32px;padding:0;border:0;border-radius:999px;background:transparent;color:currentColor;opacity:.65;cursor:pointer}'
		. '.colorify-notice-close:hover{opacity:1;background:rgba(0,0,0,.06)}.colorify-notice-close:focus-visible{opacity:1;outline:2px solid var(--colorify-ui-accent,#2271b1);outline-offset:1px}</style>' . "
";
	$hidden = colorify_get_dismissed_notices( $user_id );
	// Klucze to 12 znaków hex (sprawdzone przy zapisie), więc bezpieczne w JS bez dodatkowego kodowania.
	echo '<script id="colorify-notices-hidden">window.colorifyHiddenNotices=' . wp_json_encode( array_values( array_keys( $hidden ) ) ) . ';'
		. '(function(){var h=window.colorifyHiddenNotices;if(!h.length||!window.MutationObserver)return;'
		. 'var sel=".notice,.update-nag,div.updated,div.error";'
		. 'function key(el){var t=(el.innerText||el.textContent||"").replace(/\\d+/g,"#").replace(/\\s+/g," ").trim().slice(0,240);var x=5381;for(var i=0;i<t.length;i++){x=((x<<5)+x+t.charCodeAt(i))>>>0;}var y=52711;for(var j=t.length-1;j>=0;j--){y=((y<<5)+y+t.charCodeAt(j))>>>0;}return("00000000"+x.toString(16)).slice(-8)+("0000"+(y&65535).toString(16)).slice(-4);}'
		. 'function scan(root){(root.querySelectorAll?root.querySelectorAll(sel):[]).forEach(function(el){if(el.dataset.colorifyKey)return;var k=key(el);el.dataset.colorifyKey=k;if(h.indexOf(k)>-1){el.style.display="none";el.setAttribute("data-colorify-hidden","1");}});}'
		. 'var mo=new MutationObserver(function(){scan(document)});mo.observe(document.documentElement,{childList:true,subtree:true});'
		. 'document.addEventListener("DOMContentLoaded",function(){scan(document);setTimeout(function(){mo.disconnect()},4000);});})();</script>' . "\n";
}
add_action( 'admin_head', 'colorify_modern_head', 1 );

/**
 * @return array<string,int> klucz => czas zamknięcia.
 */
function colorify_get_dismissed_notices( int $user_id ): array {
	$list = get_user_meta( $user_id, COLORIFY_DISMISSED_META, true );
	return is_array( $list ) ? $list : array();
}

/**
 * AJAX: zapamiętaj zamknięty komunikat (albo wyczyść wszystkie przy reset=1).
 */
function colorify_ajax_dismiss_notice(): void {
	check_ajax_referer( 'colorify-notices', 'nonce' );
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}
	if ( ! empty( $_POST['reset'] ) ) {
		delete_user_meta( $user_id, COLORIFY_DISMISSED_META );
		wp_send_json_success( array( 'count' => 0 ) );
	}
	$key = isset( $_POST['key'] ) ? strtolower( (string) wp_unslash( $_POST['key'] ) ) : '';
	if ( ! preg_match( '/^[0-9a-f]{12}$/', $key ) ) {
		wp_send_json_error( array( 'message' => 'invalid_key' ), 400 );
	}
	$list         = colorify_get_dismissed_notices( $user_id );
	$list[ $key ] = time();
	if ( count( $list ) > COLORIFY_DISMISSED_MAX ) {
		asort( $list );
		$list = array_slice( $list, -COLORIFY_DISMISSED_MAX, null, true );
	}
	update_user_meta( $user_id, COLORIFY_DISMISSED_META, $list );
	wp_send_json_success( array( 'count' => count( $list ) ) );
}
add_action( 'wp_ajax_colorify_dismiss_notice', 'colorify_ajax_dismiss_notice' );

/**
 * Zapis stylu panelu z formularza Ustawienia, Colorify (wołane z handle_save i z autozapisu tego samego POST).
 *
 * @param array<string,mixed> $input Dane formularza.
 */
function colorify_save_admin_style_from_input( array $input ): void {
	if ( ! isset( $input['colorify_admin_style'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	update_option( COLORIFY_STYLE_OPTION, 'modern' === sanitize_key( (string) $input['colorify_admin_style'] ) ? 'modern' : 'classic' );
}

/**
 * Kolor uchwytu cienkiego paska przewijania. Domyślnie akcent schematu; strona (wtyczka, mu-plugin) może podać własny
 * filtrem colorify_scrollbar_color (np. '#B8955A') albo zmienną CSS --colorify-scrollbar-thumb.
 */
function colorify_scrollbar_color_css(): void {
	$color = sanitize_hex_color( (string) apply_filters( 'colorify_scrollbar_color', '' ) );
	if ( $color && 'modern' === colorify_get_admin_style() ) {
		echo '<style id="colorify-scrollbar-color">:root{--colorify-scrollbar-thumb:' . esc_html( $color ) . '}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kolor po sanitize_hex_color().
	}
}
add_action( 'admin_head', 'colorify_scrollbar_color_css', 3 );
