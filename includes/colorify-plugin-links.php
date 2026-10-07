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
 * @param array<int,string> $links Istniejące linki akcji.
 * @return array<int,string>
 */
function colorify_plugin_action_links( array $links ): array {
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

	if ( current_user_can( 'update_plugins' ) && '' !== colorify_get_github_repo() ) {
		$cached     = colorify_get_cached_github_release();
		$has_update = is_array( $cached ) && ! empty( $cached['version'] )
			&& version_compare( COLORIFY_PLUGIN_VERSION, $cached['version'], '<' );
		$label      = $has_update
			? sprintf(
				/* translators: %s: new version number */
				__( 'Aktualizuj do %s', 'colorify-by-inyfinn' ),
				$cached['version']
			)
			: __( 'Aktualizuj', 'colorify-by-inyfinn' );

		$update_link = sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( colorify_get_manual_update_url( admin_url( 'plugins.php' ) ) ),
			$has_update ? ' class="colorify-plugin-update-link"' : '',
			$has_update ? '<strong>' . esc_html( $label ) . '</strong>' : esc_html( $label )
		);
		array_unshift( $links, $update_link );
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( COLORIFY_PLUGIN_FILE ), 'colorify_plugin_action_links' );
