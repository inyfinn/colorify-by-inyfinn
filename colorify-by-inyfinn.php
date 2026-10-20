<?php
/**
 * Plugin Name:       Colorify by INYFINN
 * Plugin URI:        https://github.com/inyfinn/colorify-by-inyfinn
 * Description:       Kolory panelu WordPress (wp-admin) i strony logowania: schematy, własna paleta, dostrojenie, tryb ciemny i jasny. Ustawienia per użytkownik lub globalne.
 * Version:           2.6.0
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
		static function () {
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
define( 'COLORIFY_PLUGIN_VERSION', '2.6.0' );

/**
 * Repozytorium GitHub z wydaniami (owner/repo). Można nadpisać w wp-config.php.
 */
if ( ! defined( 'COLORIFY_GITHUB_REPO' ) ) {
	define( 'COLORIFY_GITHUB_REPO', 'inyfinn/colorify-by-inyfinn' );
}

/*
 * Kontrola środowiska i kompletności plików. Ten plik celowo używa składni zgodnej z PHP 5.6
 * (bez typów zwracanych, bez typowanych funkcji anonimowych): stary PHP parsuje go w całości,
 * więc może pokazać komunikat. Reszta wtyczki leży w includes/ i ładuje się dopiero po kontroli.
 * Wartości muszą się zgadzać z nagłówkami "Requires PHP" i "Requires at least" powyżej.
 * Rdzeń WordPressa sprawdza "requires" tylko w bulk_upgrade(), więc ta kontrola jest jedyną obroną
 * przed aktualizacją ze starszej wersji (jej updater nie zna "requires").
 */
if ( ! function_exists( 'colorify_blocking_notice' ) ) {
	function colorify_blocking_notice() {
		global $wp_version, $colorify_block_reason, $colorify_block_file; // Globalne: plik jest dołączany w zasięgu globalnym (wp-settings.php).

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>Colorify by INYFINN:</strong> ';
		if ( 'files' === $colorify_block_reason ) {
			echo esc_html(
				'Pliki wtyczki są w trakcie aktualizacji albo instalacja jest niekompletna'
				. ( $colorify_block_file ? ' (niezgodny plik: ' . $colorify_block_file . ')' : '' )
				. '. Odśwież stronę za chwilę; jeśli komunikat nie znika, zainstaluj wtyczkę ponownie.'
			);
		} else {
			echo esc_html(
				sprintf(
					'Wtyczka wymaga PHP 7.4 lub nowszego (jest %1$s) i WordPressa 7.0 lub nowszego (jest %2$s). Colorify jest wyłączony, dopóki nie zaktualizujesz środowiska.',
					PHP_VERSION,
					isset( $wp_version ) ? $wp_version : '?'
				)
			);
		}
		echo '</p></div>';
	}
}

$colorify_wp_core = explode( '-', isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0' ); // Bez sufiksów -RC, -beta (jak is_wp_version_compatible()).
if ( version_compare( PHP_VERSION, '7.4', '<' ) || version_compare( $colorify_wp_core[0], '7.0', '<' ) ) {
	$GLOBALS['colorify_block_reason'] = 'environment';
	$GLOBALS['colorify_block_file']   = '';
	add_action( 'admin_notices', 'colorify_blocking_notice' );
	// Tylko komunikat i updater (w trybie lekkim): strona może dostać poprawioną wersję, a front działa bez Colorify.
	if ( is_readable( COLORIFY_PLUGIN_DIR . 'includes/class-colorify-github-updater.php' ) ) {
		require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-github-updater.php';
		if ( class_exists( 'Colorify_Updater' ) ) {
			Colorify_Updater::init( true );
		}
	}
	return;
}
unset( $colorify_wp_core );

/*
 * Kompletność plików w oknie aktualizacji: stara ścieżka (link "Aktualizuj" w 1.x) kopiuje pliki na istniejący
 * katalog jeden po drugim w kolejności z systemu plików, więc przez chwilę ten plik współistnieje ze starymi
 * albo niedokończonymi includes/. tools/build-zip.mjs wpisuje do paczki rozmiary wszystkich includes/*.php
 * (wiersz poniżej); gdy któryś plik ma inny rozmiar albo go brak, reszta wtyczki się nie ładuje (ładuje się tylko
 * updater, jeśli jego plik jest kompletny, żeby kolejne wydanie mogło naprawić instalację).
 * Kontrola trwa tylko dopóki zapisana wersja (opcja colorify_version, ustawiana przy pierwszym poprawnym załadowaniu
 * w panelu) różni się od tej: późniejsza zmiana pliku na działającej stronie niczego nie wyłącza.
 * W repozytorium wiersz jest pusty (tryb deweloperski, bez kontroli).
 */
$colorify_manifest = '';
if ( '' !== $colorify_manifest && get_option( 'colorify_version' ) !== COLORIFY_PLUGIN_VERSION ) {
	$colorify_bad        = '';
	$colorify_updater_ok = false;
	foreach ( explode( ',', $colorify_manifest ) as $colorify_entry ) {
		$colorify_pair = explode( ':', $colorify_entry );
		$colorify_path = COLORIFY_PLUGIN_DIR . $colorify_pair[0];
		$colorify_ok   = is_file( $colorify_path ) && filesize( $colorify_path ) === (int) $colorify_pair[1];
		if ( ! $colorify_ok && '' === $colorify_bad ) {
			$colorify_bad = $colorify_pair[0];
		}
		if ( $colorify_ok && 'includes/class-colorify-github-updater.php' === $colorify_pair[0] ) {
			$colorify_updater_ok = true;
		}
	}
	if ( '' !== $colorify_bad ) {
		$GLOBALS['colorify_block_reason'] = 'files';
		$GLOBALS['colorify_block_file']   = $colorify_bad;
		add_action( 'admin_notices', 'colorify_blocking_notice' );
		if ( $colorify_updater_ok ) {
			require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-github-updater.php';
			if ( class_exists( 'Colorify_Updater' ) ) {
				Colorify_Updater::init( true );
			}
		}
		return;
	}
	unset( $colorify_bad, $colorify_updater_ok, $colorify_entry, $colorify_pair, $colorify_path, $colorify_ok );
}

const COLORIFY_BRANDING_NAME = 'Colorify';
const COLORIFY_CREDITS       = 'inyfinn.art © 2026';
const COLORIFY_CREDITS_URL   = 'https://inyfinn.art';

// Interfejs jest po polsku, wtyczka nie dostarcza tłumaczeń. Text Domain z nagłówka zostaje: WordPress sam wczyta
// pliki .mo z wp-content/languages/plugins, jeśli ktoś je doda.

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
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-toolbar.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-profile-ui.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-modern.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-dashboard.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-tiles.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-settings-screen.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-login.php';

// Aktualizacje z GitHub Releases.
require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-github-updater.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-plugin-links.php';

Colorify_Settings::init();
Colorify_Updater::init();

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! get_option( COLORIFY_SCOPE_OPTION ) ) {
			update_option( COLORIFY_SCOPE_OPTION, 'user' );
		}
	}
);
