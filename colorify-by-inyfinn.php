<?php
/**
 * Plugin Name:       Colorify by INYFINN
 * Plugin URI:        https://github.com/inyfinn/colorify-by-inyfinn
 * Description:       Kolory panelu WordPress (wp-admin) i strony logowania: schematy, własna paleta, dostrojenie, tryb ciemny i jasny. Ustawienia per użytkownik lub globalne.
 * Version:           2.0.0-dev
 * Author:            INYFINN
 * Author URI:        https://inyfinn.art
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       colorify-by-inyfinn
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/inyfinn/colorify-by-inyfinn
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'COLORIFY_MU_MODULE' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>Colorify by INYFINN:</strong> %s</p></div>',
				esc_html__(
					'Moduł MU colorify/ jest już załadowany. Dezaktywuj wtyczkę Colorify lub usuń colorify-loader.php: działa tylko jeden silnik naraz.',
					'colorify-by-inyfinn'
				)
			);
		}
	);
	return;
}

if ( defined( 'COLORIFY_BY_INYFINN_LOADED' ) ) {
	return;
}

define( 'COLORIFY_BY_INYFINN_LOADED', true );
define( 'COLORIFY_PLUGIN_FILE', __FILE__ );
define( 'COLORIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COLORIFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Jedna wersja wszędzie: nagłówek wtyczki powyżej, ta stała, cache CSS/JS (?ver=), stopka panelu.
 */
define( 'COLORIFY_PLUGIN_VERSION', '2.0.0-dev' );

/**
 * Repozytorium GitHub z wydaniami (owner/repo). Można nadpisać w wp-config.php.
 */
if ( ! defined( 'COLORIFY_GITHUB_REPO' ) ) {
	define( 'COLORIFY_GITHUB_REPO', 'inyfinn/colorify-by-inyfinn' );
}

const COLORIFY_BRANDING_NAME = 'Colorify';
const COLORIFY_CREDITS       = 'inyfinn.art © 2026';
const COLORIFY_CREDITS_URL   = 'https://inyfinn.art';

/**
 * Ładuje tłumaczenia wtyczki (język = locale WordPressa).
 */
function colorify_load_textdomain(): void {
	load_plugin_textdomain(
		'colorify-by-inyfinn',
		false,
		dirname( plugin_basename( COLORIFY_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'colorify_load_textdomain', 0 );

// Dane: zakres ustawień, definicje schematów, zapis.
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-scope.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-scheme-definitions.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-save.php';

// Silnik kolorów: matematyka, tokeny, schemat rejestrowany w WordPressie, tryb ciemny.
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-color-math.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-tokens.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-engine.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-dark.php';

// Interfejs wtyczki.
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-branding.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-toolbar-actions.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-sparkle-button.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-legacy-tokens.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-toolbar.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-profile-ui.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-settings.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-login.php';

// Aktualizacje z GitHub Releases.
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-package-normalizer.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-updater.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-plugin-links.php';

Colorify_Settings::init();

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! get_option( COLORIFY_SCOPE_OPTION ) ) {
			update_option( COLORIFY_SCOPE_OPTION, 'user' );
		}
	}
);
