<?php
/**
 * Zakres ustawień: per użytkownik vs globalne domyślne (fallback).
 *
 * Globalne = domyślny wygląd z Ustawienia → Colorify dla użytkowników
 * bez własnego wyboru w profilu. Użytkownik z personalizacją zachowuje swoje kolory.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_SCOPE_OPTION              = 'colorify_settings_scope';
const COLORIFY_GLOBAL_OPTION_PREFIX      = 'colorify_global_';
const COLORIFY_ADMIN_CUSTOMIZED_META     = 'colorify_admin_has_customized';
const COLORIFY_THEME_ENABLED_META        = 'colorify_theme_enabled';

// Schemat używany, gdy nikt nic nie wybrał (jedyne miejsce, gdzie ta wartość jest wpisana).
if ( ! defined( 'COLORIFY_DEFAULT_SCHEME' ) ) {
	define( 'COLORIFY_DEFAULT_SCHEME', 'colorify-kancelaria' );
}

/**
 * Czy Colorify styluje panel (OFF = natywny WordPress + pasek przełączników).
 *
 * @param int $user_id User ID.
 */
function colorify_is_user_theme_enabled( int $user_id = 0 ): bool {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return true;
	}
	if ( ! metadata_exists( 'user', $user_id, COLORIFY_THEME_ENABLED_META ) ) {
		return true;
	}
	return (bool) (int) get_user_meta( $user_id, COLORIFY_THEME_ENABLED_META, true );
}

/**
 * @param int  $user_id User ID.
 * @param bool $enabled Włącz/wyłącz styl Colorify.
 */
function colorify_set_user_theme_enabled( int $user_id, bool $enabled ): void {
	if ( $user_id <= 0 ) {
		return;
	}
	if ( $enabled ) {
		delete_user_meta( $user_id, COLORIFY_THEME_ENABLED_META );
		return;
	}
	update_user_meta( $user_id, COLORIFY_THEME_ENABLED_META, 0 );
}

/**
 * Gdy motyw OFF - WordPress ładuje domyślny schemat (nie colorify-admin-colors.css).
 *
 * @param mixed               $value  Wartość opcji.
 * @param string              $option Nazwa opcji.
 * @param int|WP_User|false   $user   ID lub obiekt użytkownika (WP przekazuje WP_User).
 * @return mixed
 */
function colorify_filter_admin_color_when_theme_off( $value, string $option, $user ) {
	if ( 'admin_color' !== $option ) {
		return $value;
	}

	$user_id = 0;
	if ( $user instanceof WP_User ) {
		$user_id = (int) $user->ID;
	} elseif ( is_numeric( $user ) ) {
		$user_id = (int) $user;
	}

	if ( $user_id <= 0 ) {
		return $value;
	}

	if ( ! colorify_is_user_theme_enabled( $user_id ) ) {
		return 'modern';
	}

	return $value;
}
add_filter( 'get_user_option_admin_color', 'colorify_filter_admin_color_when_theme_off', 999, 3 );

/**
 * @return string user|global
 */
function colorify_get_settings_scope(): string {
	$scope = get_option( COLORIFY_SCOPE_OPTION, 'user' );
	return in_array( $scope, array( 'user', 'global' ), true ) ? $scope : 'user';
}

function colorify_uses_global_settings(): bool {
	return 'global' === colorify_get_settings_scope();
}

/**
 * Surowa wartość admin_color z user_meta (bez filtrów WP - unika pętli rekurencji).
 */
function colorify_get_raw_user_admin_color( int $user_id ): string {
	if ( $user_id <= 0 ) {
		return '';
	}

	global $wpdb;
	$meta_key = $wpdb->get_blog_prefix() . 'admin_color';
	$value    = get_user_meta( $user_id, $meta_key, true );

	if ( is_string( $value ) && '' !== $value ) {
		return $value;
	}

	$legacy = get_user_meta( $user_id, 'admin_color', true );

	return is_string( $legacy ) ? $legacy : '';
}

/**
 * Czy użytkownik ma własną personalizację Colorify (profil / zapis własny).
 */
function colorify_user_has_personal_appearance( int $user_id ): bool {
	if ( $user_id <= 0 ) {
		return false;
	}

	if ( get_user_meta( $user_id, COLORIFY_ADMIN_CUSTOMIZED_META, true ) ) {
		return true;
	}

	$scheme = colorify_get_raw_user_admin_color( $user_id );
	if ( '' !== $scheme ) {
		$normalized = colorify_admin_normalize_scheme_key( $scheme );
		if ( colorify_admin_scheme_is_registered( $normalized ) ) {
			return true;
		}
	}

	if ( metadata_exists( 'user', $user_id, COLORIFY_ADMIN_APPEARANCE_META ) ) {
		return true;
	}

	if ( metadata_exists( 'user', $user_id, COLORIFY_ADMIN_CUSTOM_COLORS_META ) ) {
		return true;
	}

	if ( metadata_exists( 'user', $user_id, COLORIFY_ADMIN_CUSTOM_TUNING_META ) ) {
		$tuning = colorify_admin_get_custom_tuning( $user_id );
		foreach ( array( 'dark', 'light' ) as $mode ) {
			foreach ( $tuning[ $mode ] as $value ) {
				if ( 0 !== (int) $value ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Oznacz użytkownika jako mającego własną personalizację.
 */
function colorify_mark_user_appearance_customized( int $user_id ): void {
	if ( $user_id > 0 ) {
		update_user_meta( $user_id, COLORIFY_ADMIN_CUSTOMIZED_META, 1 );
	}
}

/**
 * Zapis zakresu z profilu administratora.
 *
 * @param array<string,mixed> $input POST.
 */
function colorify_save_settings_scope_from_input( array $input ): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! isset( $input['colorify_settings_scope'] ) ) {
		return;
	}

	$scope = sanitize_key( $input['colorify_settings_scope'] );
	if ( in_array( $scope, array( 'user', 'global' ), true ) ) {
		update_option( COLORIFY_SCOPE_OPTION, $scope );
	}
}

/**
 * @return string dark|light
 */
function colorify_get_global_appearance_mode(): string {
	$mode = get_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'appearance_mode', 'dark' );
	return ( is_string( $mode ) && 'light' === $mode ) ? 'light' : 'dark';
}

/**
 * @return array{bg:string,bg2:string,accent:string,accent2:string}
 */
function colorify_get_global_custom_colors(): array {
	$stored = get_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'custom_colors', array() );
	return colorify_admin_sanitize_custom_colors( is_array( $stored ) ? $stored : array() );
}

/**
 * @return array{dark:array<string,int>,light:array<string,int>}
 */
function colorify_get_global_custom_tuning(): array {
	$stored = get_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'custom_tuning', array() );
	return colorify_admin_sanitize_custom_tuning( is_array( $stored ) ? $stored : array() );
}

/**
 * @return string
 */
function colorify_get_global_admin_color(): string {
	$scheme = get_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'admin_color', COLORIFY_DEFAULT_SCHEME );
	$scheme = is_string( $scheme ) ? colorify_admin_normalize_scheme_key( $scheme ) : COLORIFY_DEFAULT_SCHEME;
	return colorify_admin_scheme_is_registered( $scheme ) ? $scheme : COLORIFY_DEFAULT_SCHEME;
}

/**
 * Zapis globalnego schematu (login + domyślny wygląd przy zakresie globalnym).
 *
 * @param string $scheme Klucz schematu.
 */
function colorify_persist_global_admin_color( string $scheme ): void {
	$scheme = colorify_admin_normalize_scheme_key( $scheme );
	if ( colorify_admin_scheme_is_registered( $scheme ) ) {
		update_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'admin_color', $scheme );
	}
}

/**
 * Czy zapis wyglądu z profilu admina trafia do ustawień globalnych (m.in. login).
 *
 * @param int $user_id User ID.
 */
function colorify_saves_appearance_to_global( int $user_id = 0, ?array $input = null ): bool {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 || (int) get_current_user_id() !== (int) $user_id ) {
		return false;
	}

	$source = null !== $input ? $input : ( isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	// Radio zakresu w profilu / ustawieniach ma pierwszeństwo (login = tylko zapis globalny).
	if ( isset( $source['colorify_settings_scope'] ) ) {
		return 'global' === sanitize_key( $source['colorify_settings_scope'] );
	}

	if ( isset( $source['scope'] ) ) {
		$scope = sanitize_key( $source['scope'] );
		if ( 'user' === $scope ) {
			return false;
		}
		if ( 'global' === $scope ) {
			return true;
		}
	}

	return colorify_uses_global_settings();
}

/**
 * Zapisany schemat użytkownika (meta), bez fallbacku globalnego.
 *
 * @param int $user_id User ID.
 * @return string
 */
function colorify_get_user_stored_admin_color( int $user_id = 0 ): string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return COLORIFY_DEFAULT_SCHEME;
	}
	$scheme = colorify_get_raw_user_admin_color( $user_id );
	$scheme = '' !== $scheme ? colorify_admin_normalize_scheme_key( $scheme ) : '';
	if ( '' !== $scheme && colorify_admin_scheme_is_registered( $scheme ) ) {
		return $scheme;
	}
	return COLORIFY_DEFAULT_SCHEME;
}

/**
 * Pakiet wyglądu dla podglądu zakresu (global / per-user) w profilu admina.
 *
 * @param string $scope   global|user.
 * @param int    $user_id User ID.
 * @return array{scheme:string,mode:string,customColors:array<string,string>,customTuning:array<string,array<string,int>>}
 */
function colorify_get_scope_appearance_bundle( string $scope, int $user_id = 0 ): array {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();

	if ( 'global' === $scope ) {
		return array(
			'scheme'       => colorify_get_global_admin_color(),
			'mode'         => colorify_get_global_appearance_mode(),
			'customColors' => colorify_get_global_custom_colors(),
			'customTuning' => colorify_get_global_custom_tuning(),
		);
	}

	return array(
		'scheme'       => colorify_get_user_stored_admin_color( $user_id ),
		'mode'         => colorify_admin_get_appearance_mode( $user_id ),
		'customColors' => colorify_admin_get_custom_colors( $user_id ),
		'customTuning' => colorify_admin_get_custom_tuning( $user_id ),
	);
}

/**
 * Login zawsze = ustawienia globalne z panelu wtyczki (nie per-user, nie scope).
 *
 * @return string
 */
function colorify_is_login_request(): bool {
	global $pagenow;
	return isset( $pagenow ) && 'wp-login.php' === $pagenow;
}

/**
 * @param int $user_id User ID.
 * @return string dark|light
 */
function colorify_get_effective_appearance_mode( int $user_id = 0 ): string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();

	if ( $user_id > 0 && colorify_user_has_personal_appearance( $user_id ) ) {
		return colorify_admin_get_appearance_mode( $user_id );
	}

	// Bez własnego wyboru: wygląd witryny z Ustawienia → Colorify, w obu zakresach.
	return colorify_get_global_appearance_mode();
}

/**
 * Zapis trybu - profil użytkownika (własna personalizacja).
 *
 * @param int $user_id User ID.
 */
function colorify_set_user_appearance_mode( int $user_id, string $mode ): void {
	$mode = 'light' === $mode ? 'light' : 'dark';
	colorify_admin_set_appearance_mode( $user_id, $mode );
	colorify_mark_user_appearance_customized( $user_id );
}

/**
 * Zapis globalnego trybu (panel wtyczki).
 */
function colorify_set_global_appearance_mode( string $mode ): void {
	$mode = 'light' === $mode ? 'light' : 'dark';
	update_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'appearance_mode', $mode );
}

/**
 * @param int $user_id User ID.
 * @return array{bg:string,bg2:string,accent:string,accent2:string}
 */
function colorify_get_effective_custom_colors( int $user_id = 0 ): array {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();

	if ( $user_id > 0 && colorify_user_has_personal_appearance( $user_id ) ) {
		return colorify_admin_get_custom_colors( $user_id );
	}

	return colorify_get_global_custom_colors();
}

/**
 * @param int $user_id User ID.
 * @return array{dark:array<string,int>,light:array<string,int>}
 */
function colorify_get_effective_custom_tuning( int $user_id = 0 ): array {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();

	if ( $user_id > 0 && colorify_user_has_personal_appearance( $user_id ) ) {
		return colorify_admin_get_custom_tuning( $user_id );
	}

	return colorify_get_global_custom_tuning();
}

/**
 * @param int $user_id User ID.
 */
function colorify_get_effective_admin_color( int $user_id = 0 ): string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();

	if ( $user_id > 0 && colorify_user_has_personal_appearance( $user_id ) ) {
		$scheme = colorify_get_raw_user_admin_color( $user_id );
		$scheme = '' !== $scheme ? colorify_admin_normalize_scheme_key( $scheme ) : '';
		if ( '' !== $scheme && colorify_admin_scheme_is_registered( $scheme ) ) {
			return $scheme;
		}
		return COLORIFY_DEFAULT_SCHEME;
	}

	return colorify_get_global_admin_color();
}

/**
 * Zapis globalnych ustawień wyglądu (panel wtyczki).
 *
 * @param array<string,mixed> $input POST.
 */
function colorify_save_global_appearance( array $input ): void {
	if ( isset( $input['colorify_admin_appearance'] ) ) {
		$mode = sanitize_key( $input['colorify_admin_appearance'] );
		colorify_set_global_appearance_mode( $mode );
	}

	$scheme = colorify_resolve_admin_color_from_input( $input );
	if ( '' !== $scheme ) {
		update_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'admin_color', $scheme );
	}

	if ( isset( $input['colorify_custom_colors'] ) && is_array( $input['colorify_custom_colors'] ) ) {
		$colors = colorify_admin_sanitize_custom_colors( $input['colorify_custom_colors'] );
		update_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'custom_colors', $colors );
	}

	if ( isset( $input['colorify_custom_tuning'] ) ) {
		$tuning = colorify_admin_sanitize_custom_tuning( $input['colorify_custom_tuning'] );
		update_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'custom_tuning', $tuning );
	}
}

/**
 * Filtr user option - globalny domyślny schemat tylko bez własnego wyboru użytkownika.
 *
 * @param mixed         $value  Wartość opcji.
 * @param string        $option Nazwa opcji.
 * @param false|WP_User $user   Użytkownik.
 */
function colorify_filter_global_user_option( $value, string $option, $user ) {
	static $in_filter = false;

	if ( $in_filter || 'admin_color' !== $option ) {
		return $value;
	}

	$user_id = ( $user instanceof WP_User ) ? (int) $user->ID : 0;
	if ( $user_id <= 0 ) {
		return $value;
	}

	if ( ! colorify_is_user_theme_enabled( $user_id ) ) {
		return $value;
	}

	$in_filter = true;

	if ( colorify_user_has_personal_appearance( $user_id ) ) {
		$in_filter = false;
		return $value;
	}

	if ( colorify_uses_global_settings() ) {
		$in_filter = false;
		return colorify_get_global_admin_color();
	}

	$in_filter = false;
	return $value;
}
add_filter( 'get_user_option_admin_color', 'colorify_filter_global_user_option', 20, 3 );

/*
 * Skóra: czcionki i zaokrąglenie rogów ekranów Colorify i strony logowania (kolory dają tokeny schematu).
 *   neutral     czcionka systemowa panelu WordPressa, rogi 4 px (domyślna: pasuje do każdej strony)
 *   kancelaria  EB Garamond + IBM Plex Sans, ostre rogi (wartości zapasowe w arkuszach)
 *   site        czcionki i rogi zmierzone na stronie głównej przyciskiem "Dopasuj do strony"
 */
const COLORIFY_SKIN_OPTION = 'colorify_skin';

/**
 * Stos czcionek z wartości font-family: każda nazwa to litery, cyfry, spacja, podkreślenie albo łącznik.
 * Wynik jest gotowy do wstawienia w CSS (nazwy w cudzysłowach, słowa kluczowe bez).
 *
 * @param mixed $value Surowa wartość font-family.
 */
function colorify_skin_sanitize_font_stack( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$generic = array( 'serif', 'sans-serif', 'monospace', 'system-ui', 'ui-serif', 'ui-sans-serif', '-apple-system', 'blinkmacsystemfont' );
	$out     = array();
	foreach ( array_slice( explode( ',', $value ), 0, 8 ) as $name ) {
		$name = trim( $name, " \t\n\r\"'" );
		if ( ! preg_match( '/^[A-Za-z0-9 _-]{1,60}$/', $name ) ) {
			continue;
		}
		$out[] = in_array( strtolower( $name ), $generic, true ) ? $name : '"' . $name . '"';
	}

	return implode( ', ', $out );
}

/**
 * @param mixed $input Zapisana albo nadesłana skóra.
 * @return array{preset:string,heading:string,body:string,radius:int,faces:array<int,array<string,string>>}
 */
function colorify_skin_sanitize( $input ): array {
	$input  = is_array( $input ) ? $input : array();
	$preset = isset( $input['preset'] ) && in_array( $input['preset'], array( 'neutral', 'kancelaria', 'site' ), true ) ? $input['preset'] : 'neutral';
	$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$faces  = array();

	foreach ( array_slice( is_array( $input['faces'] ?? null ) ? $input['faces'] : array(), 0, 16 ) as $face ) {
		if ( ! is_array( $face ) ) {
			continue;
		}
		$family = trim( (string) ( $face['family'] ?? '' ), " \"'" );
		$url    = esc_url_raw( (string) ( $face['url'] ?? '' ), array( 'http', 'https' ) );
		$weight = trim( (string) ( $face['weight'] ?? '400' ) );
		$range  = trim( (string) ( $face['range'] ?? '' ) );
		// Pliki czcionek tylko z tego samego serwera: Colorify nie ładuje niczego z zewnątrz.
		if ( ! preg_match( '/^[A-Za-z0-9 _-]{1,60}$/', $family )
			|| '' === $url || preg_match( '/["\'()\\\\<>\s]/', $url )
			|| strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host
			|| ! preg_match( '/\.(woff2|woff|ttf|otf)$/i', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
			continue;
		}
		$faces[] = array(
			'family' => $family,
			'url'    => $url,
			'weight' => preg_match( '/^(normal|bold|\d{3}( \d{3})?)$/', $weight ) ? $weight : '400',
			'style'  => 'italic' === ( $face['style'] ?? '' ) ? 'italic' : 'normal',
			'range'  => preg_match( '/^[Uu0-9A-Fa-f+?, -]{1,600}$/', $range ) ? $range : '',
		);
	}

	return array(
		'preset'  => $preset,
		'heading' => colorify_skin_sanitize_font_stack( $input['heading'] ?? '' ),
		'body'    => colorify_skin_sanitize_font_stack( $input['body'] ?? '' ),
		'radius'  => max( 0, min( 10, (int) ( $input['radius'] ?? 0 ) ) ),
		'faces'   => $faces,
	);
}

/**
 * @return array{preset:string,heading:string,body:string,radius:int,faces:array<int,array<string,string>>}
 */
function colorify_get_skin(): array {
	$skin = colorify_skin_sanitize( get_option( COLORIFY_SKIN_OPTION, array() ) );
	// "site" bez pomiaru nie ma czego pokazać.
	if ( 'site' === $skin['preset'] && '' === $skin['body'] ) {
		$skin['preset'] = 'neutral';
	}
	return $skin;
}

/**
 * Arkusz skóry: zmienne czytane przez colorify-ui.css i colorify-login.css oraz @font-face czcionek strony.
 * Skóra kancelarii nie wypisuje nic: jej wartości są zapasowymi w arkuszach.
 */
function colorify_skin_css(): string {
	$skin = colorify_get_skin();

	if ( 'kancelaria' === $skin['preset'] ) {
		return '';
	}

	if ( 'neutral' === $skin['preset'] ) {
		$system = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
		return ':root{--colorify-skin-serif:' . $system . ';--colorify-skin-sans:' . $system . ';--colorify-skin-radius:4px}';
	}

	$css = '';
	foreach ( $skin['faces'] as $face ) {
		$css .= '@font-face{font-family:"' . $face['family'] . '";src:url("' . $face['url'] . '");font-weight:' . $face['weight']
			. ';font-style:' . $face['style'] . ';font-display:swap' . ( '' !== $face['range'] ? ';unicode-range:' . $face['range'] : '' ) . '}';
	}
	$heading = '' !== $skin['heading'] ? $skin['heading'] : $skin['body'];

	return $css . ':root{--colorify-skin-serif:' . $heading . ', serif;--colorify-skin-sans:' . $skin['body'] . ', sans-serif;--colorify-skin-radius:' . $skin['radius'] . 'px}';
}

/**
 * Skóra w nagłówku panelu.
 */
function colorify_skin_print(): void {
	$css = colorify_skin_css();
	if ( '' !== $css ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zbudowane z wartości po colorify_skin_sanitize().
		echo '<style id="colorify-skin-vars">' . $css . '</style>' . "\n";
	}
}
add_action( 'admin_head', 'colorify_skin_print', 2 );
add_action( 'customize_controls_head', 'colorify_skin_print', 2 );

/**
 * AJAX: zapis wyglądu zmierzonego na stronie głównej (kolory jako własna paleta witryny, czcionki i rogi jako skóra).
 */
function colorify_ajax_apply_site_look(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	$look = json_decode( isset( $_POST['look'] ) ? (string) wp_unslash( $_POST['look'] ) : '', true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pola przechodzą sanitize_* niżej.
	if ( ! is_array( $look ) ) {
		wp_send_json_error( array( 'message' => 'invalid' ), 400 );
	}

	$skin = colorify_skin_sanitize( array_merge( $look, array( 'preset' => 'site' ) ) );
	if ( '' === $skin['body'] ) {
		wp_send_json_error( array( 'message' => 'no_font' ), 400 );
	}
	update_option( COLORIFY_SKIN_OPTION, $skin );

	if ( isset( $look['colors'] ) && is_array( $look['colors'] ) && '' !== colorify_admin_sanitize_hex_color( $look['colors']['accent'] ?? '' ) ) {
		$colors = colorify_admin_sanitize_custom_colors( $look['colors'] );
		update_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'custom_colors', $colors );
		colorify_persist_global_admin_color( COLORIFY_ADMIN_CUSTOM_SCHEME_KEY );
		// Osoba, która dopasowuje wygląd, ma go od razu zobaczyć: jej konto dostaje tę samą paletę (tryb zostaje).
		$user_id = get_current_user_id();
		update_user_meta( $user_id, COLORIFY_ADMIN_CUSTOM_COLORS_META, $colors );
		update_user_meta( $user_id, $GLOBALS['wpdb']->get_blog_prefix() . 'admin_color', COLORIFY_ADMIN_CUSTOM_SCHEME_KEY );
		update_user_meta( $user_id, COLORIFY_ADMIN_CUSTOMIZED_META, 1 );
	}

	wp_send_json_success( array( 'faces' => count( $skin['faces'] ) ) );
}
add_action( 'wp_ajax_colorify_apply_site_look', 'colorify_ajax_apply_site_look' );
