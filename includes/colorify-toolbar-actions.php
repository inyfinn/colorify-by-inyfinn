<?php
/**
 * Pasek Colorify — statyczne linki GET (bez JS, bez AJAX).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bieżący URL panelu admina (do powrotu po przełączeniu).
 */
function colorify_current_admin_url(): string {
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$path = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = is_string( $path ) ? $path : '';
		if ( '' !== $path ) {
			return admin_url( $path );
		}
	}

	return admin_url( 'index.php' );
}

/**
 * URL akcji paska (motyw / tryb) z nonce.
 *
 * @param string $action theme|mode.
 * @param string $value  Wartość (0|1 lub dark|light).
 */
function colorify_toolbar_action_url( string $action, string $value ): string {
	$base = remove_query_arg(
		array( 'colorify_theme', 'colorify_mode', '_colorify_nonce', '_wpnonce' ),
		colorify_current_admin_url()
	);

	return wp_nonce_url(
		add_query_arg( 'colorify_' . $action, $value, $base ),
		'colorify_toolbar_' . $action,
		'_colorify_nonce'
	);
}

/**
 * Obsługa ?colorify_theme= & ?colorify_mode= — zapis w PHP, przekierowanie.
 */
function colorify_handle_toolbar_get_actions(): void {
	if ( ! is_admin() || ! is_user_logged_in() ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}

	$redirect = remove_query_arg(
		array( 'colorify_theme', 'colorify_mode', '_colorify_nonce', '_wpnonce' ),
		colorify_current_admin_url()
	);

	if ( isset( $_GET['colorify_theme'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['_colorify_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_colorify_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, 'colorify_toolbar_theme' ) ) {
			return;
		}

		$enabled = '1' === sanitize_key( wp_unslash( $_GET['colorify_theme'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		colorify_set_user_theme_enabled( $user_id, $enabled );
		wp_safe_redirect( $redirect );
		exit;
	}

	if ( isset( $_GET['colorify_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['_colorify_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_colorify_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, 'colorify_toolbar_mode' ) ) {
			return;
		}

		$mode = sanitize_key( wp_unslash( $_GET['colorify_mode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $mode, array( 'dark', 'light' ), true ) ) {
			return;
		}

		if ( colorify_uses_global_settings() && current_user_can( 'manage_options' ) ) {
			colorify_set_global_appearance_mode( $mode );
		} else {
			colorify_set_user_appearance_mode( $user_id, $mode );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
add_action( 'admin_init', 'colorify_handle_toolbar_get_actions', 1 );
