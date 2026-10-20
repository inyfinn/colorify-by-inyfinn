<?php
/**
 * Widok kafli: skrzynki ekranu edycji (klasyczny edytor) jako zwijane kafle ze skrótem treści.
 *
 * Schemat z panelu Usług kancelarii (kw-uslugi 2.0), ale bez znajomości treści: każda skrzynka to kafel,
 * zwinięty pokazuje tytuł i pierwsze wypełnione pole. Zwijanie i jego zapamiętanie robi sam WordPress.
 * Włączany osobno dla każdego rodzaju ekranu pigułką "Widok kafli" przy tytule; domyślnie wszędzie wyłączony.
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_TILES_OPTION = 'colorify_tiles_screens';

/**
 * Id bieżącego ekranu, jeśli da się na nim pokazać kafle (klasyczny ekran edycji), inaczej ''.
 */
function colorify_tiles_screen_id(): string {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->base ) {
		return '';
	}
	if ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
		return '';
	}
	// Własne, ręcznie zaprojektowane ekrany zostają bez zmian.
	if ( in_array( $screen->post_type, (array) apply_filters( 'colorify_tiles_excluded_post_types', array( 'kw_usluga' ) ), true ) ) {
		return '';
	}
	return (string) $screen->id;
}

/**
 * Ekrany z włączonymi kaflami.
 */
function colorify_tiles_enabled_screens(): array {
	return array_values( array_filter( (array) get_option( COLORIFY_TILES_OPTION, array() ), 'is_string' ) );
}

/**
 * Czy kafle są włączone na danym ekranie.
 *
 * @param string $screen_id Id ekranu.
 */
function colorify_tiles_is_on( string $screen_id ): bool {
	return '' !== $screen_id && in_array( $screen_id, colorify_tiles_enabled_screens(), true );
}

add_filter(
	'admin_body_class',
	static function ( $classes ) {
		$classes = is_string( $classes ) ? $classes : '';
		return colorify_tiles_is_on( colorify_tiles_screen_id() ) ? trim( $classes . ' colorify-tiles' ) : $classes;
	},
	21
);

add_action(
	'admin_enqueue_scripts',
	static function () {
		$id  = colorify_tiles_screen_id();
		$on  = colorify_tiles_is_on( $id );
		$can = current_user_can( 'manage_options' );
		if ( '' === $id || ( ! $on && ! $can ) ) {
			return;
		}
		wp_enqueue_style( 'colorify-tiles', COLORIFY_PLUGIN_URL . 'assets/colorify-tiles.css', array(), COLORIFY_PLUGIN_VERSION );
		wp_enqueue_script( 'colorify-tiles', COLORIFY_PLUGIN_URL . 'assets/colorify-tiles.js', array( 'postbox' ), COLORIFY_PLUGIN_VERSION, true );
		wp_add_inline_script(
			'colorify-tiles',
			'window.colorifyTiles = ' . wp_json_encode(
				array(
					'screen'  => $id,
					'canEdit' => $can,
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => $can ? wp_create_nonce( 'colorify-tiles' ) : '',
				),
				JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
	},
	111
);

/*
 * Kafle zwinięte od startu także dla kont, które nigdy nie zwijały skrzynek (np. redaktor po włączeniu przez admina).
 * Pierwsze kliknięcie zapisuje własny stan konta i od tej pory decyduje WordPress.
 */
add_action(
	'add_meta_boxes',
	static function () {
		$id = colorify_tiles_screen_id();
		if ( ! colorify_tiles_is_on( $id ) || false !== get_user_option( 'closedpostboxes_' . $id ) ) {
			return;
		}
		global $wp_meta_boxes;
		foreach ( array( 'normal', 'advanced' ) as $context ) {
			foreach ( (array) ( $wp_meta_boxes[ $id ][ $context ] ?? array() ) as $boxes ) {
				foreach ( array_keys( (array) $boxes ) as $box_id ) {
					add_filter(
						"postbox_classes_{$id}_{$box_id}",
						static function ( $classes ) {
							$classes[] = 'closed';
							return $classes;
						}
					);
				}
			}
		}
	},
	999
);

add_action(
	'wp_ajax_colorify_tiles_toggle',
	static function () {
		check_ajax_referer( 'colorify-tiles', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$id = isset( $_POST['screen'] ) ? sanitize_key( wp_unslash( $_POST['screen'] ) ) : '';
		if ( '' === $id ) {
			wp_send_json_error( null, 400 );
		}
		$list = array_diff( colorify_tiles_enabled_screens(), array( $id ) );
		if ( ! empty( $_POST['on'] ) ) {
			$list[] = $id;
		}
		update_option( COLORIFY_TILES_OPTION, array_values( $list ), false );
		wp_send_json_success( array( 'screens' => array_values( $list ) ) );
	}
);
