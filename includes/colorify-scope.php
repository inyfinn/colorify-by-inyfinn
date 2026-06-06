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
 * Surowa wartość admin_color z user_meta (bez filtrów WP — unika pętli rekurencji).
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
	$scheme = get_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'admin_color', 'colorify-lime' );
	$scheme = is_string( $scheme ) ? colorify_admin_normalize_scheme_key( $scheme ) : 'colorify-lime';
	return colorify_admin_scheme_is_registered( $scheme ) ? $scheme : 'colorify-lime';
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

	if ( colorify_uses_global_settings() ) {
		return colorify_get_global_appearance_mode();
	}

	return colorify_admin_get_appearance_mode( $user_id );
}

/**
 * Zapis trybu — profil użytkownika (własna personalizacja).
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

	if ( colorify_uses_global_settings() ) {
		return colorify_get_global_custom_colors();
	}

	return colorify_admin_get_custom_colors( $user_id );
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

	if ( colorify_uses_global_settings() ) {
		return colorify_get_global_custom_tuning();
	}

	return colorify_admin_get_custom_tuning( $user_id );
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
		return 'colorify-lime';
	}

	if ( colorify_uses_global_settings() ) {
		return colorify_get_global_admin_color();
	}

	return 'colorify-lime';
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

	if ( isset( $input['admin_color'] ) ) {
		$scheme = sanitize_key( $input['admin_color'] );
		if ( colorify_admin_scheme_is_registered( $scheme ) ) {
			update_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'admin_color', $scheme );
		}
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
 * Filtr user option — globalny domyślny schemat tylko bez własnego wyboru użytkownika.
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
