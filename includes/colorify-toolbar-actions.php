<?php
/**
 * Droga bez JavaScriptu dla paska: odnośniki GET z nonce (włączenie i wyłączenie stylu, tryb).
 * Skrypt interfejsu przejmuje te same kliknięcia i robi to przez AJAX; bez skryptu działa ta droga.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adres bezwzględny z REQUEST_URI (ścieżka od korzenia domeny, razem z podkatalogiem WordPressa).
 *
 * home_url( $path ) dokleja ścieżkę do adresu, który już zawiera podkatalog (home = https://x.pl/blog daje
 * /blog/blog/wp-admin/...), a admin_url() dokleja /wp-admin/. Dlatego bierzemy z adresu witryny tylko
 * schemat, host i port.
 *
 * @param string $home        Adres witryny (home_url()).
 * @param string $request_uri $_SERVER['REQUEST_URI'].
 * @return string Pusty, gdy adres witryny nie ma hosta.
 */
function colorify_url_from_request_uri( string $home, string $request_uri ): string {
	$parts = wp_parse_url( $home );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' )
		. '://' . $parts['host']
		. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
		. '/' . ltrim( $request_uri, '/' );
}

/**
 * Bieżący URL panelu (do powrotu po przełączeniu).
 */
function colorify_current_admin_url(): string {
	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$path = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = is_string( $path ) ? $path : '';
		if ( '' !== $path ) {
			$url = colorify_url_from_request_uri( home_url(), $path );
			if ( '' !== $url ) {
				return $url;
			}
		}
	}

	return admin_url( 'index.php' );
}

/**
 * URL akcji paska z nonce.
 *
 * @param string $action theme|mode.
 * @param string $value  Wartość (0|1 albo dark|light).
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
 * Obsługa ?colorify_theme= i ?colorify_mode=: zapis w PHP i przekierowanie.
 */
function colorify_handle_toolbar_get_actions(): void {
	if ( ! is_admin() || ! is_user_logged_in() ) {
		return;
	}

	$user_id = get_current_user_id();

	$redirect = remove_query_arg(
		array( 'colorify_theme', 'colorify_mode', '_colorify_nonce', '_wpnonce' ),
		colorify_current_admin_url()
	);

	if ( isset( $_GET['colorify_theme'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['_colorify_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_colorify_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, 'colorify_toolbar_theme' ) ) {
			return;
		}

		colorify_set_user_theme_enabled( $user_id, '1' === sanitize_key( wp_unslash( $_GET['colorify_theme'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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

		// W oknie aktualizacji ze starego kodu ten plik bywa nowy, a colorify-save.php jeszcze stary (bez tej funkcji).
		if ( ! function_exists( 'colorify_apply_mode_choice' ) ) {
			return;
		}

		colorify_apply_mode_choice( $user_id, $mode );
		wp_safe_redirect( $redirect );
		exit;
	}
}
add_action( 'admin_init', 'colorify_handle_toolbar_get_actions', 1 );
