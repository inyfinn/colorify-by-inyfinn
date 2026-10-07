<?php
/**
 * Zapis ustawień wyglądu: profil, AJAX.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Zapis wyglądu (profil / AJAX) z tablicy wejściowej.
 *
 * @param int                 $user_id User ID.
 * @param array<string,mixed> $input   Dane formularza.
 */
function colorify_save_appearance_for_user( int $user_id, array $input ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	colorify_save_settings_scope_from_input( $input );

	if ( colorify_saves_appearance_to_global( $user_id, $input ) ) {
		colorify_save_global_appearance( $input );
		return;
	}

	$customized = false;

	if ( isset( $input['colorify_admin_appearance'] ) ) {
		$mode = sanitize_key( $input['colorify_admin_appearance'] );
		colorify_admin_set_appearance_mode( $user_id, $mode );
		$customized = true;
	}

	$scheme = colorify_resolve_admin_color_from_input( $input );
	if ( '' !== $scheme ) {
		colorify_persist_user_admin_color( $user_id, $scheme );
		$customized = true;
	}

	if ( isset( $input['colorify_custom_colors'] ) && is_array( $input['colorify_custom_colors'] ) ) {
		$colors = colorify_admin_sanitize_custom_colors( $input['colorify_custom_colors'] );
		update_user_meta( $user_id, COLORIFY_ADMIN_CUSTOM_COLORS_META, $colors );
		$customized = true;
	}

	if ( isset( $input['colorify_custom_tuning'] ) ) {
		$tuning = colorify_admin_sanitize_custom_tuning( $input['colorify_custom_tuning'] );
		update_user_meta( $user_id, COLORIFY_ADMIN_CUSTOM_TUNING_META, $tuning );
		$customized = true;
	}

	if ( $customized ) {
		colorify_mark_user_appearance_customized( $user_id );
	}
}

/**
 * @param int $user_id User ID.
 */
function colorify_save_admin_appearance_profile( int $user_id ): void {
	if ( ! isset( $_POST ) || ! is_array( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}
	colorify_save_appearance_for_user( $user_id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}
add_action( 'personal_options_update', 'colorify_save_admin_appearance_profile' );
add_action( 'edit_user_profile_update', 'colorify_save_admin_appearance_profile' );

/**
 * Odczyt wybranego schematu z POST.
 *
 * Radio `admin_color` ma pierwszeństwo: ukryte pole ładuje się ze starej wartości strony
 * i po kliknięciu schematu (np. Szkarłat) bez pełnej synchronizacji JS nadpisywało zapis.
 */
function colorify_resolve_admin_color_from_input( array $input ): string {
	if ( isset( $input['admin_color'] ) ) {
		$scheme = sanitize_key( $input['admin_color'] );
		if ( colorify_admin_scheme_is_registered( $scheme ) ) {
			return $scheme;
		}
	}

	if ( isset( $input['colorify_admin_color'] ) ) {
		$scheme = sanitize_key( $input['colorify_admin_color'] );
		if ( colorify_admin_scheme_is_registered( $scheme ) ) {
			return $scheme;
		}
	}

	return '';
}

/**
 * Odczyt wybranego schematu z POST.
 */
function colorify_resolve_admin_color_from_request(): string {
	if ( ! isset( $_POST ) || ! is_array( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return '';
	}
	return colorify_resolve_admin_color_from_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

/**
 * Zapisuje schemat w obu kluczach user_meta (prefiksowany + legacy WP).
 */
function colorify_persist_user_admin_color( int $user_id, string $scheme ): void {
	if ( $user_id <= 0 || ! colorify_admin_scheme_is_registered( $scheme ) ) {
		return;
	}

	update_user_option( $user_id, 'admin_color', $scheme, true );
	update_user_meta( $user_id, 'admin_color', $scheme );
	colorify_mark_user_appearance_customized( $user_id );
}

/**
 * Wymusza poprawny schemat w meta przed zapisem wp_update_user (fallback WP: modern).
 *
 * @param array<string,mixed> $meta     Meta użytkownika.
 * @param WP_User             $user     Użytkownik.
 * @param bool                $update   Czy aktualizacja.
 * @param array<string,mixed> $userdata Dane wejściowe.
 * @return array<string,mixed>
 */
function colorify_filter_insert_user_meta_admin_color( array $meta, WP_User $user, bool $update, array $userdata ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	if ( ! $update || empty( $_POST['action'] ) || 'update' !== $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return $meta;
	}

	$scheme = colorify_resolve_admin_color_from_request();
	if ( '' !== $scheme ) {
		$meta['admin_color'] = $scheme;
	} else {
		// Wybór schematu nie był ruszany (pole nie poszło w POST): rdzeń nie może go nadpisać swoim domyślnym.
		$raw = colorify_get_raw_user_admin_color( (int) $user->ID );
		if ( '' !== $raw ) {
			$meta['admin_color'] = $raw;
		}
	}

	return $meta;
}
add_filter( 'insert_user_meta', 'colorify_filter_insert_user_meta_admin_color', 10, 4 );

/**
 * Po wp_update_user(): nadpisuje ewentualny fallback WP (modern), gdy radio nie trafiło do POST.
 *
 * @param int           $user_id       User ID.
 * @param WP_User       $old_user_data Poprzednie dane.
 * @param array<string> $userdata      Nowe dane.
 */
function colorify_persist_user_appearance_on_profile_update( int $user_id, $old_user_data, array $userdata ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}

	if ( empty( $_POST['action'] ) || 'update' !== $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return;
	}

	$scheme = colorify_resolve_admin_color_from_request();
	if ( '' !== $scheme ) {
		colorify_persist_user_admin_color( $user_id, $scheme );
	} elseif ( isset( $userdata['admin_color'] ) && 'modern' === $userdata['admin_color'] ) {
		$raw = colorify_get_raw_user_admin_color( $user_id );
		if ( '' !== $raw && colorify_admin_scheme_is_registered( $raw ) ) {
			colorify_persist_user_admin_color( $user_id, $raw );
		}
	}

	if ( isset( $_POST['colorify_admin_appearance'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode = sanitize_key( wp_unslash( $_POST['colorify_admin_appearance'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		colorify_admin_set_appearance_mode( $user_id, $mode );
		colorify_mark_user_appearance_customized( $user_id );
	}
}
add_action( 'profile_update', 'colorify_persist_user_appearance_on_profile_update', 20, 3 );

/**
 * Zapis trybu wybranego w pasku: tam, skąd go czytamy (patrz colorify_get_effective_appearance_mode()).
 *
 * - osoba z własnym wyglądem: jej tryb,
 * - administrator przy zakresie "jeden wygląd": tryb globalny,
 * - pozostali: własny tryb. Gdy wyglądem rządzi zakres globalny, bieżący wygląd (schemat, paleta, dostrojenie)
 *   trafia do profilu razem z trybem, żeby zmiana trybu nie podmieniła schematu na domyślny.
 *
 * @param int    $user_id User ID.
 * @param string $mode    dark|light.
 * @return string user|global Gdzie zapisano.
 */
function colorify_apply_mode_choice( int $user_id, string $mode ): string {
	$mode = 'light' === $mode ? 'light' : 'dark';

	if ( colorify_user_has_personal_appearance( $user_id ) ) {
		colorify_set_user_appearance_mode( $user_id, $mode );
		return 'user';
	}

	if ( colorify_uses_global_settings() ) {
		if ( current_user_can( 'manage_options' ) ) {
			colorify_set_global_appearance_mode( $mode );
			return 'global';
		}

		colorify_persist_user_admin_color( $user_id, colorify_get_effective_admin_color( $user_id ) );
		update_user_meta( $user_id, COLORIFY_ADMIN_CUSTOM_COLORS_META, colorify_get_effective_custom_colors( $user_id ) );
		update_user_meta( $user_id, COLORIFY_ADMIN_CUSTOM_TUNING_META, colorify_get_effective_custom_tuning( $user_id ) );
	}

	colorify_set_user_appearance_mode( $user_id, $mode );
	return 'user';
}

/**
 * AJAX: zapis trybu dark/light z paska.
 */
function colorify_ajax_save_admin_appearance(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}

	$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
	if ( ! in_array( $mode, array( 'dark', 'light' ), true ) ) {
		wp_send_json_error( array( 'message' => 'invalid_mode' ), 400 );
	}

	$scope = colorify_apply_mode_choice( $user_id, $mode );

	wp_send_json_success(
		array(
			'mode'  => $mode,
			'scope' => $scope,
		)
	);
}
add_action( 'wp_ajax_colorify_save_admin_appearance', 'colorify_ajax_save_admin_appearance' );

/**
 * AJAX: pełny zapis wyglądu (schemat, tryb, paleta, dostrojenie, zakres) bez przeładowania.
 * Zapisuje dla użytkownika z pola user_id (domyślnie bieżący), jeśli bieżący może go edytować.
 */
function colorify_ajax_save_appearance_state(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	$current = get_current_user_id();
	if ( $current <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}

	$target = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : $current;
	if ( $target <= 0 || ! current_user_can( 'edit_user', $target ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	colorify_save_appearance_for_user( $target, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- każde pole przechodzi sanitize_* w colorify_save_appearance_for_user().

	wp_send_json_success(
		array(
			'scope'       => colorify_get_settings_scope(),
			'mode'        => colorify_get_effective_appearance_mode( $current ),
			'scheme'      => colorify_get_effective_admin_color( $current ),
			'name'        => colorify_ui_scheme_name( $current ),
			'hasPersonal' => colorify_user_has_personal_appearance( $current ),
		)
	);
}
add_action( 'wp_ajax_colorify_save_appearance_state', 'colorify_ajax_save_appearance_state' );

/**
 * AJAX: włączenie albo wyłączenie stylu Colorify (strona przeładowuje się po odpowiedzi).
 */
function colorify_ajax_toggle_user_theme(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}

	$enabled = isset( $_POST['enabled'] ) ? (int) wp_unslash( $_POST['enabled'] ) : -1;
	if ( ! in_array( $enabled, array( 0, 1 ), true ) ) {
		wp_send_json_error( array( 'message' => 'invalid_state' ), 400 );
	}

	colorify_set_user_theme_enabled( $user_id, (bool) $enabled );

	wp_send_json_success( array( 'enabled' => (bool) $enabled ) );
}
add_action( 'wp_ajax_colorify_toggle_user_theme', 'colorify_ajax_toggle_user_theme' );
