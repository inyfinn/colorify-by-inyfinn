<?php
/**
 * Tryb ciemny.
 *
 * WordPress nie ma ciemnego panelu: poza menu i górnym paskiem wszystkie kolory ekranów są
 * wpisane na sztywno, także we wtyczkach. Dlatego tryb ciemny liczy silnik Dark Reader
 * (assets/vendor/darkreader.js, licencja MIT, wersja zamrożona w repozytorium). Silnik czyta
 * arkusze strony i dla każdej reguły wystawia odpowiednik z przeliczonymi kolorami, osobno
 * dla tła, tekstu i ramek, więc para "tło i tekst" zawsze zmienia się razem.
 *
 * Colorify podaje silnikowi:
 *   - bieguny: kolor karty i kolor tekstu z palety,
 *   - gotowe kolory akcentu dla każdej roli (zmienne --darkreader-*--wp-admin-theme-color),
 *   - listę własnych arkuszy, których silnik ma nie ruszać (rama panelu i interfejs wtyczki
 *     mają kolory dobrane ręcznie w tokenach).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wersja dołączonego Dark Readera (do cache przeglądarki i testów).
 */
const COLORIFY_DARKREADER_VERSION = '4.9.133';

/**
 * Arkusze, których Dark Reader nie przelicza (fragmenty adresu URL).
 *
 * @return array<int,string>
 */
function colorify_dark_ignored_stylesheets(): array {
	return array( 'colorify-chrome', 'colorify-ui', 'colorify-login' );
}

/**
 * Elementy, których kolory wpisane w atrybucie style mają zostać dosłowne: próbki kolorów.
 *
 * @return array<int,string>
 */
function colorify_dark_literal_color_selectors(): array {
	$selectors = array(
		'.wp-color-result',
		'.wp-picker-container .iris-picker *',
		'.component-color-indicator',
		'.components-circular-option-picker__option',
		'.color-palette-item',
		'[data-colorify-swatch]',
	);

	/**
	 * Filtruje listę selektorów, których stylów inline tryb ciemny nie przelicza.
	 *
	 * @param array<int,string> $selectors Selektory CSS.
	 */
	return array_values( array_filter( (array) apply_filters( 'colorify_dark_literal_color_selectors', $selectors ), 'is_string' ) );
}

/**
 * Argumenty DarkReader.enable( theme, fixes ) dla podanych tokenów trybu ciemnego.
 *
 * @param array<string,mixed> $tokens Wynik colorify_build_tokens() dla trybu dark.
 * @return array{theme:array<string,mixed>,fixes:array<string,mixed>}
 */
function colorify_dark_reader_arguments( array $tokens ): array {
	$dark = isset( $tokens['dark'] ) && is_array( $tokens['dark'] ) ? $tokens['dark'] : array();
	$vars = isset( $dark['vars'] ) && is_array( $dark['vars'] ) ? $dark['vars'] : array();

	return array(
		'theme' => array(
			'mode'                      => 1,
			'brightness'                => 100,
			'contrast'                  => 100,
			'grayscale'                 => 0,
			'sepia'                     => 0,
			'darkSchemeBackgroundColor' => isset( $dark['bg'] ) ? (string) $dark['bg'] : '#121f1a',
			'darkSchemeTextColor'       => isset( $dark['text'] ) ? (string) $dark['text'] : '#e8ece9',
			'styleSystemControls'       => true,
		),
		'fixes' => array(
			'invert'                  => array(),
			// Role akcentu: silnik zamienia var(--wp-admin-theme-color) na te zmienne zależnie od tego,
			// czy kolor jest tłem, tekstem czy ramką. Podajemy je sami, policzone pod kontrast.
			'css'                     => 'body{' . colorify_engine_declarations( $vars, true ) . '}',
			'ignoreInlineStyle'       => colorify_dark_literal_color_selectors(),
			'ignoreImageAnalysis'     => array(),
			'disableStyleSheetsProxy' => false,
			'ignoreCSSUrl'            => colorify_dark_ignored_stylesheets(),
		),
	);
}

/**
 * Skrypt silnika w nagłówku: tryb ciemny ma ruszyć przed pierwszym malowaniem strony.
 */
function colorify_dark_enqueue(): void {
	if ( ! colorify_engine_is_active() ) {
		return;
	}

	$mode   = colorify_get_effective_appearance_mode();
	$vendor = COLORIFY_PLUGIN_URL . 'assets/vendor/darkreader.js';
	$deps   = array();

	if ( 'dark' === $mode ) {
		wp_enqueue_script( 'colorify-darkreader', $vendor, array(), COLORIFY_DARKREADER_VERSION, false );
		$deps[] = 'colorify-darkreader';
	}

	wp_enqueue_script( 'colorify-engine', COLORIFY_PLUGIN_URL . 'assets/colorify-engine.js', $deps, COLORIFY_PLUGIN_VERSION, false );

	$config = array(
		'mode'          => $mode,
		'payloads'      => array(
			'dark'  => colorify_engine_client_payload( colorify_current_tokens( 0, 'dark' ) ),
			'light' => colorify_engine_client_payload( colorify_current_tokens( 0, 'light' ) ),
		),
		// W trybie jasnym silnik nie jest ładowany; przełącznik dociąga go dopiero po kliknięciu.
		'darkReaderUrl' => add_query_arg( 'ver', COLORIFY_DARKREADER_VERSION, $vendor ),
	);

	wp_add_inline_script(
		'colorify-engine',
		'window.colorifyEngine = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';',
		'before'
	);
}
/**
 * Strażnik kontrastu: po ustaniu zmian kolorów poprawia pojedyncze teksty poniżej WCAG AA.
 */
function colorify_guard_enqueue(): void {
	if ( ! colorify_engine_is_active() ) {
		return;
	}

	/**
	 * Wyłącznik strażnika kontrastu.
	 *
	 * @param bool $enabled Czy ładować strażnika.
	 */
	if ( ! apply_filters( 'colorify_contrast_guard', true ) ) {
		return;
	}

	wp_enqueue_script( 'colorify-guard', COLORIFY_PLUGIN_URL . 'assets/colorify-guard.js', array(), COLORIFY_PLUGIN_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'colorify_guard_enqueue', 100 );
add_action( 'customize_controls_enqueue_scripts', 'colorify_guard_enqueue', 100 );

add_action( 'admin_enqueue_scripts', 'colorify_dark_enqueue', 1 );
add_action( 'customize_controls_enqueue_scripts', 'colorify_dark_enqueue', 1 );
