<?php
/**
 * Ekrany "Witaj" rdzenia (about.php, credits.php, freedoms.php, contribute.php) przekierowują na kokpit.
 *
 * Po aktualizacji WordPressa rdzeń pokazuje stronę z nowościami, której nikt w panelu nie potrzebuje, a kokpit Colorify
 * jest właśnie tym, co ma być pierwsze. Opcja jest domyślnie włączona (można ją wyłączyć w Ustawienia, Colorify).
 * Przekierowanie działa tylko dla zalogowanych w panelu i nigdy nie zapętla się: celem jest index.php.
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_ABOUT_REDIRECT_OPTION = 'colorify_redirect_about';

/** Czy ekrany "Witaj" przekierowują na kokpit (domyślnie tak). */
function colorify_about_redirect_enabled(): bool {
	return 'no' !== get_option( COLORIFY_ABOUT_REDIRECT_OPTION, 'yes' );
}

/**
 * Wcześnie w panelu: strony powitalne rdzenia na kokpit. Filtr colorify_about_redirect_pages pozwala zmienić listę ekranów.
 */
function colorify_about_redirect(): void {
	global $pagenow;
	$pages = (array) apply_filters( 'colorify_about_redirect_pages', array( 'about.php', 'credits.php', 'freedoms.php', 'contribute.php' ) );
	if ( ! in_array( (string) $pagenow, $pages, true ) || ! colorify_about_redirect_enabled() ) {
		return;
	}
	if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	wp_safe_redirect( admin_url( 'index.php' ) );
	exit;
}
add_action( 'admin_init', 'colorify_about_redirect', 1 );

/**
 * Zapis przełącznika z formularza Ustawienia, Colorify. Pole ukryte colorify_redirect_about_present mówi, że przełącznik był
 * w formularzu (niezaznaczone pole nie wysyła nic), więc zapisy z innych miejsc nie wyłączają opcji.
 *
 * @param array<string,mixed> $input Dane formularza.
 */
function colorify_save_about_redirect_from_input( array $input ): void {
	if ( empty( $input['colorify_redirect_about_present'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	update_option( COLORIFY_ABOUT_REDIRECT_OPTION, ! empty( $input['colorify_redirect_about'] ) ? 'yes' : 'no' );
}
