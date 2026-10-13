<?php
/**
 * Odnośniki w wierszu wtyczki na ekranie Wtyczki.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Link „Zmień kolory” na liście wtyczek.
 *
 * @param mixed $links Istniejące linki akcji (zwykle tablica).
 * @return array<int,string>
 */
function colorify_plugin_action_links( $links ): array {
	$links        = (array) $links; // Cudzy filtr plugin_action_links_* mógł zwrócić nie-tablicę.
	$profile_url  = admin_url( 'profile.php' );
	$settings_url = admin_url( 'options-general.php?page=colorify-by-inyfinn' );

	$profile_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $profile_url ),
		esc_html__( 'Moje kolory (profil)', 'colorify-by-inyfinn' )
	);
	array_unshift( $links, $profile_link );

	if ( current_user_can( 'manage_options' ) ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Ustawienia globalne', 'colorify-by-inyfinn' )
		);
		array_unshift( $links, $settings_link );
	}

	if ( current_user_can( 'update_plugins' ) ) {
		$check_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Colorify_Updater::check_url( 'plugins' ) ),
			esc_html__( 'Sprawdź aktualizacje', 'colorify-by-inyfinn' )
		);
		array_unshift( $links, $check_link );
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( COLORIFY_PLUGIN_FILE ), 'colorify_plugin_action_links' );
