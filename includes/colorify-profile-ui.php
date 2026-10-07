<?php
/**
 * Interfejs wyboru schematu: profil użytkownika, pasek przełączników, własna paleta, modal dostrojenia.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kolejność kart w profilu (WP sortuje DOM po kluczu — JS przekłada).
 *
 * @return array<int,string>
 */
function colorify_admin_scheme_order_for_js(): array {
	$order = array();
	foreach ( colorify_admin_scheme_definitions() as $key => $def ) {
		if ( ! empty( $def['custom'] ) ) {
			continue;
		}
		$order[] = $key;
	}
	return $order;
}

/**
 * @return array<string,array{dark:array<int,string>,light:array<int,string>}>
 */
function colorify_admin_previews_for_js(): array {
	$out = array();
	foreach ( colorify_admin_scheme_definitions() as $key => $def ) {
		if ( ! empty( $def['custom'] ) ) {
			continue;
		}
		$out[ $key ] = array(
			'dark'  => $def['preview_dark'],
			'light' => $def['preview_light'],
		);
	}
	return $out;
}

/**
 * Tłumaczenie zgodne z locale WordPressa (fallback PL gdy brak pliku .mo).
 *
 * @param string $en          Tekst źródłowy (angielski).
 * @param string $pl_fallback Polski fallback.
 */
function colorify_i18n( string $en, string $pl_fallback = '' ): string {
	$translated = __( $en, 'colorify-by-inyfinn' );
	if ( $translated !== $en ) {
		return $translated;
	}
	if ( '' !== $pl_fallback && str_starts_with( determine_locale(), 'pl' ) ) {
		return $pl_fallback;
	}
	return $en;
}

/**
 * URL panelu schematów (profil → Personalizacja → #color-picker).
 *
 * @param int $user_id User ID.
 */
function colorify_admin_schemes_panel_url( int $user_id = 0 ): string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return admin_url( 'profile.php#color-picker' );
	}
	return get_edit_profile_url( $user_id ) . '#color-picker';
}

/**
 * Ikona palety (SVG) przy przycisku Zmień styl.
 */
function colorify_admin_change_style_icon_html(): string {
	return '<span class="colorify-change-style-btn__icon" aria-hidden="true">'
		. '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" focusable="false">'
		. '<path d="M12 3c-4.97 0-9 4.03-9 9s4.03 9 9 9c.83 0 1.5-.67 1.5-1.5 0-.39-.15-.74-.39-1.01-.23-.26-.38-.61-.38-.99 0-.83.67-1.5 1.5-1.5H16c2.76 0 5-2.24 5-5 0-4.42-4.03-8-9-8zm-5.5 9c-.83 0-1.5-.67-1.5-1.5S5.67 9 6.5 9 8 9.67 8 10.5 7.33 12 6.5 12zm3-4C8.67 8 8 7.33 8 6.5S8.67 5 9.5 5s1.5.67 1.5 1.5S10.33 8 9.5 8zm5 0c-.83 0-1.5-.67-1.5-1.5S13.67 5 14.5 5s1.5.67 1.5 1.5S15.33 8 14.5 8zm3 4c-.83 0-1.5-.67-1.5-1.5S16.67 9 17.5 9s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/>'
		. '</svg></span>';
}

/**
 * Globalny markup przełącznika (label.switch + span.slider).
 *
 * @param string $input_class Klasa inputa (np. colorify-mode-switch__input).
 * @param bool   $checked     Stan początkowy.
 * @param string $aria_label  Etykieta dostępności.
 */
function colorify_ui_switch_html( string $input_class, bool $checked, string $aria_label ): string {
	return '<label class="switch">'
		. '<input type="checkbox" class="' . esc_attr( $input_class ) . '" '
		. ( $checked ? 'checked ' : '' )
		. 'aria-label="' . esc_attr( $aria_label ) . '" />'
		. '<span class="slider"></span>'
		. '</label>';
}

/**
 * Przełącznik ON/OFF — wyłącza styl Colorify (zostaje domyślny WP + ten pasek).
 *
 * @param bool $enabled Czy styl jest włączony.
 */
function colorify_admin_theme_switch_html( bool $enabled ): string {
	return '<div class="colorify-theme-switch colorify-toolbar-switch colorify-admin-toolbar__theme" role="group" aria-label="'
		. esc_attr( colorify_i18n( 'Colorify theme', 'Motyw Colorify' ) )
		. '">'
		. '<span class="colorify-mode-switch__label">' . esc_html( colorify_i18n( 'Off', 'Wył.' ) ) . '</span>'
		. colorify_ui_switch_html(
			'colorify-theme-switch__input',
			$enabled,
			colorify_i18n( 'Toggle Colorify styling', 'Włącz/wyłącz styl Colorify' )
		)
		. '<span class="colorify-mode-switch__label">' . esc_html( colorify_i18n( 'On', 'Wł.' ) ) . '</span>'
		. '</div>';
}

/**
 * Pływający pasek: Zmień styl | separator | tryb | ON/OFF stylu.
 */
function colorify_admin_floating_toolbar_html(): string {
	$mode        = colorify_get_effective_appearance_mode();
	$schemes_url = colorify_admin_schemes_panel_url();
	$theme_on    = colorify_is_user_theme_enabled();

	return '<div class="colorify-admin-toolbar" role="toolbar" aria-label="' . esc_attr( COLORIFY_BRANDING_NAME ) . '">'
		. '<div class="colorify-admin-toolbar__segment colorify-admin-toolbar__segment--style">'
		. colorify_sparkle_change_style_link_html(
			$schemes_url,
			colorify_i18n( 'Change style', 'Zmień styl' )
		)
		. '</div>'
		. '<span class="colorify-admin-toolbar__sep" aria-hidden="true"></span>'
		. '<div class="colorify-admin-toolbar__segment colorify-admin-toolbar__segment--controls">'
		. colorify_admin_mode_switch_html( $mode )
		. '<span class="colorify-admin-toolbar__sep" aria-hidden="true"></span>'
		. colorify_admin_theme_switch_html( $theme_on )
		. '</div>'
		. '</div>';
}

/**
 * @param string $mode dark|light
 */
function colorify_admin_mode_switch_html( string $mode ): string {
	$is_light = 'light' === $mode;

	return '<div class="colorify-mode-switch colorify-toolbar-switch" role="group" aria-label="' . esc_attr( colorify_i18n( 'Panel mode', 'Tryb panelu' ) ) . '">'
		. '<span class="colorify-mode-switch__label">' . esc_html( colorify_i18n( 'Dark', 'Ciemny' ) ) . '</span>'
		. colorify_ui_switch_html(
			'colorify-mode-switch__input',
			$is_light,
			colorify_i18n( 'Toggle dark or light mode', 'Przełącz tryb jasny/ciemny' )
		)
		. '<span class="colorify-mode-switch__label">' . esc_html( colorify_i18n( 'Light', 'Jasny' ) ) . '</span>'
		. '</div>';
}

/**
 * Przełącznik zakresu globalne / per użytkownik (tylko administrator).
 *
 * @param int $user_id User ID.
 */
function colorify_admin_profile_scope_bar( int $user_id ): void {
	if ( ! current_user_can( 'manage_options' ) || (int) get_current_user_id() !== (int) $user_id ) {
		return;
	}

	$scope        = colorify_get_settings_scope();
	$settings_url = admin_url( 'options-general.php?page=colorify-by-inyfinn' );

	echo '<div class="colorify-profile-scope-bar" id="colorify-profile-scope-bar">';
	echo '<h3 class="colorify-profile-scope-bar__title">' . esc_html__( 'Zakres kolorów witryny', 'colorify-by-inyfinn' ) . '</h3>';
	echo '<p class="description colorify-profile-scope-bar__desc">';
	echo esc_html__( 'Globalne: domyślny wygląd z wtyczki dla użytkowników bez własnego wyboru. Per użytkownik: każdy ustawia kolory w profilu.', 'colorify-by-inyfinn' );
	echo '</p>';
	echo '<div class="colorify-scope-toggle colorify-profile-scope-toggle" role="radiogroup" aria-label="' . esc_attr__( 'Zakres ustawień kolorów', 'colorify-by-inyfinn' ) . '">';
	printf(
		'<label class="colorify-scope-toggle__option%s"><input type="radio" name="colorify_settings_scope" value="user" %s /><span class="colorify-scope-toggle__label">%s</span><span class="colorify-scope-toggle__hint">%s</span></label>',
		'user' === $scope ? ' is-active' : '',
		checked( $scope, 'user', false ),
		esc_html__( 'Per użytkownik', 'colorify-by-inyfinn' ),
		esc_html__( 'Każdy wybiera kolory w profilu.', 'colorify-by-inyfinn' )
	);
	printf(
		'<label class="colorify-scope-toggle__option%s"><input type="radio" name="colorify_settings_scope" value="global" %s /><span class="colorify-scope-toggle__label">%s</span><span class="colorify-scope-toggle__hint">%s</span></label>',
		'global' === $scope ? ' is-active' : '',
		checked( $scope, 'global', false ),
		esc_html__( 'Globalne (domyślne)', 'colorify-by-inyfinn' ),
		esc_html__( 'Kolory logowania + domyślny panel.', 'colorify-by-inyfinn' )
	);
	echo '</div>';
	echo '<p class="description colorify-profile-scope-bar__link">';
	echo esc_html__(
		'Globalne: kolory logowania i domyślny panel. Per użytkownik: osobisty schemat w profilu.',
		'colorify-by-inyfinn'
	);
	echo ' <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Ustawienia → Colorify', 'colorify-by-inyfinn' ) . '</a>';
	echo '</p></div>';
}
add_action( 'admin_color_scheme_picker', 'colorify_admin_profile_scope_bar', 2 );

/**
 * Przełącznik dark/light — sekcja Personalizacja (profil / edycja użytkownika).
 *
 * @param int $user_id User ID.
 */
function colorify_admin_render_profile_mode_bar( int $user_id ): void {
	$mode         = colorify_get_effective_appearance_mode( $user_id );
	$has_personal = colorify_user_has_personal_appearance( $user_id );

	echo '<div class="colorify-profile-mode-bar" id="colorify-profile-mode-bar">';
	echo '<div class="colorify-profile-mode-bar__row">';
	echo '<span class="colorify-profile-mode-bar__label">' . esc_html( colorify_i18n( 'Panel mode', 'Tryb panelu' ) ) . '</span>';
	echo colorify_admin_mode_switch_html( $mode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<button type="button" class="button colorify-tuning-card__open colorify-profile-mode-bar__tuning-btn" id="colorify-tuning-open">'
		. esc_html__( 'Dostrojenie kolorów', 'colorify-by-inyfinn' )
		. '</button>';
	echo '</div>';

	echo '<p class="description colorify-profile-mode-bar__hint" id="colorify-profile-mode-bar-hint">';
	$profile_scope = colorify_get_settings_scope();
	$shows_scope_toggle = current_user_can( 'manage_options' ) && (int) get_current_user_id() === (int) $user_id;
	if ( $shows_scope_toggle && 'global' === $profile_scope ) {
		echo esc_html__(
			'Podgląd: globalne kolory witryny (logowanie i domyślny panel). Zmiany tutaj aktualizują login.',
			'colorify-by-inyfinn'
		);
	} elseif ( $has_personal ) {
		echo esc_html__( 'Podgląd: Twój osobisty schemat zapisany w profilu.', 'colorify-by-inyfinn' );
	} else {
		echo esc_html__(
			'Podgląd: tryb per użytkownik. Wybierz schemat poniżej, aby zapisać własny styl.',
			'colorify-by-inyfinn'
		);
	}
	echo '</p>';

	echo '</div>';
}

/**
 * Przełącznik trybu — nad siatką schematów (profil); JS może przenieść do sekcji Personalizacja.
 *
 * @param int $user_id User ID.
 */
function colorify_admin_profile_picker_mode_bar( int $user_id ): void {
	echo '<div class="colorify-profile-mode-bar-wrap" id="colorify-profile-mode-bar-wrap">';
	colorify_admin_render_profile_mode_bar( $user_id );
	echo '</div>';
}
add_action( 'admin_color_scheme_picker', 'colorify_admin_profile_picker_mode_bar', 4 );

/**
 * Paleta ręczna + karta dostrojenia — pod siatką schematów na profilu.
 *
 * @param int $user_id User ID.
 */
function colorify_admin_profile_picker_custom_tools( int $user_id ): void {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return;
	}

	colorify_admin_custom_palette_section_markup( $user );
}
add_action( 'admin_color_scheme_picker', 'colorify_admin_profile_picker_custom_tools', 11 );

/**
 * @return array<string,array<string,mixed>>
 */
function colorify_admin_schemes_for_js(): array {
	$out = array();
	foreach ( colorify_admin_scheme_definitions() as $key => $def ) {
		$out[ $key ] = array(
			'accent'     => $def['accent'] ?? '#B4E717',
			'accentSoft' => $def['accent_soft'] ?? '#92C200',
			'custom'     => ! empty( $def['custom'] ),
		);
		if ( empty( $def['custom'] ) ) {
			$out[ $key ]['bgDark']         = $def['bg_dark'] ?? '#050f0c';
			$out[ $key ]['bg2Dark']        = $def['bg2_dark'] ?? '#0b231c';
			$out[ $key ]['bgLight']        = $def['bg_light'] ?? '#fefefd';
			$out[ $key ]['bg2Light']       = $def['bg2_light'] ?? '#f7f6f3';
			$out[ $key ]['accentLight']    = $def['accent_light'] ?? $def['accent'];
			$out[ $key ]['accentSoftLight'] = $def['accent_soft_light'] ?? $def['accent_soft'];
		}
	}
	return $out;
}

/**
 * @param WP_User $user User being edited.
 */
function colorify_admin_appearance_profile_field( WP_User $user ): void {
	$user_id = (int) $user->ID;
	$mode    = colorify_admin_get_appearance_mode( $user_id );
	$scheme  = colorify_get_effective_admin_color( $user_id );

	printf(
		'<input type="hidden" name="colorify_admin_appearance" id="colorify-admin-appearance-field" value="%s" />'
		. '<input type="hidden" name="colorify_admin_color" id="colorify-admin-color-field" value="%s" />',
		esc_attr( $mode ),
		esc_attr( $scheme )
	);
}
add_action( 'personal_options', 'colorify_admin_appearance_profile_field', 99 );
add_action( 'edit_user_profile', 'colorify_admin_appearance_profile_field', 99 );

/**
 * Etykiety pól palety i dostrojenia.
 *
 * @return array{labels:array<string,string>,tuning_labels:array<string,string>}
 */
function colorify_admin_custom_palette_labels(): array {
	return array(
		'labels' => array(
			'bg'      => __( 'Tło', 'colorify-by-inyfinn' ),
			'bg2'     => __( 'Dodatkowe tło', 'colorify-by-inyfinn' ),
			'accent'  => __( 'Akcent', 'colorify-by-inyfinn' ),
			'accent2' => __( 'Dodatkowy akcent', 'colorify-by-inyfinn' ),
		),
		'tuning_labels' => array(
			'bg_brightness'     => __( 'Jasność tła', 'colorify-by-inyfinn' ),
			'bg_saturation'     => __( 'Nasycenie tła', 'colorify-by-inyfinn' ),
			'accent_brightness' => __( 'Jasność akcentów', 'colorify-by-inyfinn' ),
			'accent_saturation' => __( 'Nasycenie akcentów', 'colorify-by-inyfinn' ),
		),
	);
}

/**
 * Kolory i dostrojenie do formularza (per-user lub globalne w panelu admina).
 *
 * @param WP_User $user User being edited.
 * @return array{colors:array<string,string>,tuning:array{dark:array<string,int>,light:array<string,int>}}
 */
function colorify_admin_custom_palette_form_data( WP_User $user, bool $for_global_panel = false ): array {
	if ( $for_global_panel ) {
		return array(
			'colors' => colorify_get_global_custom_colors(),
			'tuning' => colorify_get_global_custom_tuning(),
		);
	}

	$user_id = (int) $user->ID;
	if ( colorify_user_has_personal_appearance( $user_id ) ) {
		return array(
			'colors' => colorify_admin_get_custom_colors( $user_id ),
			'tuning' => colorify_admin_get_custom_tuning( $user_id ),
		);
	}

	if ( colorify_uses_global_settings() ) {
		return array(
			'colors' => colorify_get_global_custom_colors(),
			'tuning' => colorify_get_global_custom_tuning(),
		);
	}

	return array(
		'colors' => colorify_admin_get_custom_colors( $user_id ),
		'tuning' => colorify_admin_get_custom_tuning( $user_id ),
	);
}

/**
 * Sekcja: własna paleta (4 kolory) + karta dostrojenia — widoczna pod #color-picker.
 *
 * @param WP_User $user User being edited.
 */
function colorify_admin_custom_palette_section_markup( WP_User $user, bool $for_global_panel = false ): void {
	$form_data = colorify_admin_custom_palette_form_data( $user, $for_global_panel );
	$colors    = $form_data['colors'];
	$label_map = colorify_admin_custom_palette_labels();
	$labels    = $label_map['labels'];

	echo '<div class="colorify-custom-section">';
	echo '<hr class="colorify-scheme-divider colorify-scheme-divider--top" aria-hidden="true" />';
	echo '<div class="colorify-custom-section__grid">';

	echo '<div id="colorify-custom-palette" class="colorify-custom-palette-card color-option">';
	echo '<div class="colorify-custom-palette-card__head">';
	echo '<h3 class="colorify-custom-palette-card__title">' . esc_html__( 'Własna paleta kolorów', 'colorify-by-inyfinn' ) . '</h3>';
	echo '<p class="description colorify-custom-palette-card__desc">'
		. esc_html__( '4 kolory — podgląd na żywo. Zapisz profil, aby zachować.', 'colorify-by-inyfinn' )
		. '</p>';
	echo '<input type="radio" name="admin_color" id="admin_color_' . esc_attr( COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ) . '" '
		. 'value="' . esc_attr( COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ) . '" class="screen-reader-text colorify-custom-admin-color-radio" />';
	echo '</div>';
	echo '<div class="colorify-custom-palette-card__grid" role="group" aria-label="' . esc_attr__( 'Własna paleta kolorów', 'colorify-by-inyfinn' ) . '">';

	foreach ( $labels as $key => $label ) {
		$value = $colors[ $key ];
		printf(
			'<label class="colorify-custom-palette-card__cell">'
			. '<span class="colorify-custom-palette-card__label">%s</span>'
			. '<span class="colorify-custom-palette__row">'
			. '<input type="color" class="colorify-custom-palette__input" data-colorify-custom-key="%s" value="%s" '
			. 'aria-label="%s" title="%s" />'
			. '<input type="text" class="colorify-custom-palette__hex" name="colorify_custom_colors[%s]" value="%s" '
			. 'maxlength="7" pattern="#?[0-9a-fA-F]{6}" spellcheck="false" autocomplete="off" />'
			. '</span></label>',
			esc_html( $label ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_attr( $label ),
			esc_attr( $label ),
			esc_attr( $key ),
			esc_attr( $value )
		);
	}

	echo '</div>';
	echo '<div class="colorify-custom-palette-card__preview color-palette" aria-hidden="true">';
	for ( $i = 0; $i < 4; $i++ ) {
		echo '<span class="color-palette-shade colorify-custom-palette__swatch"></span>';
	}
	echo '</div></div>';

	echo '<div class="colorify-tuning-card">';
	echo '<div class="colorify-tuning-card__body">';
	echo '<h3 class="colorify-tuning-card__title">' . esc_html__( 'Dostrojenie kolorów', 'colorify-by-inyfinn' ) . '</h3>';
	echo '<p class="description colorify-tuning-card__desc">'
		. esc_html__( 'Jasność i nasycenie osobno dla trybu ciemnego i jasnego.', 'colorify-by-inyfinn' )
		. '</p>';
	echo '<div class="colorify-tuning-card__summary" id="colorify-tuning-summary" aria-live="polite"></div>';
	echo '</div>';
	echo '<div class="colorify-tuning-card__actions">';
	echo '<button type="button" class="button colorify-tuning-card__open colorify-profile-mode-bar__tuning-btn colorify-tuning-card__open--footer">'
		. esc_html__( 'Dostrojenie kolorów', 'colorify-by-inyfinn' )
		. '</button>';
	echo '<button type="button" class="button button-primary colorify-tuning-card__save" id="colorify-appearance-save" '
		. 'aria-describedby="colorify-appearance-save-hint">'
		. esc_html__( 'Zapisz ustawienia', 'colorify-by-inyfinn' )
		. '</button>';
	echo '<span id="colorify-appearance-save-hint" class="screen-reader-text">'
		. esc_html__( 'Zapisuje schemat, paletę i dostrojenie w profilu użytkownika.', 'colorify-by-inyfinn' )
		. '</span>';
	echo '<p class="colorify-tuning-card__save-status" id="colorify-appearance-save-status" role="status" aria-live="polite" hidden></p>';
	echo '</div>';
	echo '</div>';

	echo '</div></div>';
}

/**
 * Modal dostrojenia (suwaki) — wstrzykiwany w stopce admina.
 *
 * @param WP_User $user User being edited.
 */
function colorify_admin_tuning_modal_markup( WP_User $user, bool $for_global_panel = false ): void {
	$form_data      = colorify_admin_custom_palette_form_data( $user, $for_global_panel );
	$tuning         = $form_data['tuning'];
	$label_map      = colorify_admin_custom_palette_labels();
	$tuning_labels  = $label_map['tuning_labels'];

	echo '<div id="colorify-tuning-modal" class="colorify-tuning-modal" hidden aria-hidden="true">';
	echo '<div class="colorify-tuning-modal__backdrop" data-colorify-tuning-close></div>';
	echo '<div class="colorify-tuning-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="colorify-tuning-modal-title">';
	echo '<header class="colorify-tuning-modal__header">';
	echo '<h2 id="colorify-tuning-modal-title" class="colorify-tuning-modal__title">'
		. esc_html__( 'Dostrojenie kolorów', 'colorify-by-inyfinn' )
		. '</h2>';
	echo '<button type="button" class="colorify-tuning-modal__close" data-colorify-tuning-close aria-label="'
		. esc_attr__( 'Zamknij', 'colorify-by-inyfinn' )
		. '">&times;</button>';
	echo '</header>';
	echo '<div class="colorify-tuning-modal__tabs" role="tablist">';
	echo '<button type="button" class="colorify-tuning-modal__tab is-active" role="tab" aria-selected="true" data-colorify-tuning-tab="dark">'
		. esc_html__( 'Ciemny', 'colorify-by-inyfinn' )
		. '</button>';
	echo '<button type="button" class="colorify-tuning-modal__tab" role="tab" aria-selected="false" data-colorify-tuning-tab="light">'
		. esc_html__( 'Jasny', 'colorify-by-inyfinn' )
		. '</button>';
	echo '</div>';

	foreach ( array( 'dark' => __( 'Tryb ciemny', 'colorify-by-inyfinn' ), 'light' => __( 'Tryb jasny', 'colorify-by-inyfinn' ) ) as $mode => $mode_label ) {
		$hidden = 'dark' === $mode ? '' : ' hidden';
		$active = 'dark' === $mode ? ' is-active' : '';
		echo '<div class="colorify-tuning-modal__panel' . esc_attr( $active ) . '" data-colorify-tuning-panel="' . esc_attr( $mode ) . '"' . $hidden . '>';
		echo '<p class="colorify-tuning-modal__panel-label">' . esc_html( $mode_label ) . '</p>';

		foreach ( array(
			'bg'     => __( 'Tła', 'colorify-by-inyfinn' ),
			'accent' => __( 'Akcenty', 'colorify-by-inyfinn' ),
		) as $group => $group_label ) {
			echo '<fieldset class="colorify-tuning-modal__group"><legend>' . esc_html( $group_label ) . '</legend>';
			echo '<div class="colorify-tuning-modal__controls">';
			foreach ( array( 'brightness' => 'brightness', 'saturation' => 'saturation' ) as $suffix => $suffix_key ) {
				$field   = $group . '_' . $suffix_key;
				$val     = (int) ( $tuning[ $mode ][ $field ] ?? 0 );
				$label   = $tuning_labels[ $field ] ?? $field;
				$ctrl_id = 'colorify-tuning-' . $mode . '-' . $field;
				printf(
					'<div class="colorify-tuning-modal__control">'
					. '<label class="colorify-tuning-modal__control-label" for="%s">%s</label>'
					. '<div class="colorify-tuning-modal__control-row">'
					. '<input type="range" class="colorify-tuning-modal__range" id="%s" data-colorify-tuning-mode="%s" data-colorify-tuning-key="%s" '
					. 'name="colorify_custom_tuning[%s][%s]" min="%d" max="%d" step="1" value="%d" '
					. 'aria-valuemin="%d" aria-valuemax="%d" aria-valuenow="%d" />'
					. '<input type="number" class="colorify-tuning-modal__number" data-colorify-tuning-mode="%s" data-colorify-tuning-key="%s" '
					. 'min="%d" max="%d" step="1" value="%d" inputmode="numeric" '
					. 'aria-label="%s (%s)" />'
					. '</div></div>',
					esc_attr( $ctrl_id ),
					esc_html( $label ),
					esc_attr( $ctrl_id ),
					esc_attr( $mode ),
					esc_attr( $field ),
					esc_attr( $mode ),
					esc_attr( $field ),
					COLORIFY_ADMIN_TUNING_MIN,
					COLORIFY_ADMIN_TUNING_MAX,
					$val,
					COLORIFY_ADMIN_TUNING_MIN,
					COLORIFY_ADMIN_TUNING_MAX,
					$val,
					esc_attr( $mode ),
					esc_attr( $field ),
					COLORIFY_ADMIN_TUNING_MIN,
					COLORIFY_ADMIN_TUNING_MAX,
					$val,
					esc_attr( $label ),
					esc_attr( $mode )
				);
			}
			echo '</div></fieldset>';
		}
		echo '</div>';
	}

	echo '<footer class="colorify-tuning-modal__footer">';
	echo '<button type="button" class="button" id="colorify-tuning-reset">'
		. esc_html__( 'Resetuj dostrojenie', 'colorify-by-inyfinn' )
		. '</button>';
	echo '<button type="button" class="button button-primary" data-colorify-tuning-close>'
		. esc_html__( 'Gotowe', 'colorify-by-inyfinn' )
		. '</button>';
	echo '</footer></div></div></div>';
}

/**
 * Markup własnej palety + modal (panel ustawień wtyczki).
 *
 * @param WP_User $user    User being edited.
 * @param bool    $visible Czy mount ma być widoczny od razu (panel wtyczki).
 */
function colorify_admin_custom_palette_markup( WP_User $user, bool $visible = false ): void {
	echo '<div id="colorify-custom-palette-mount"' . ( $visible ? '' : ' hidden' ) . '>';
	colorify_admin_custom_palette_section_markup( $user, true );
	echo '</div>';
	colorify_admin_tuning_modal_markup( $user, true );
}

/**
 * Modal dostrojenia w stopce (profil — sekcja jest już pod #color-picker).
 */
function colorify_admin_print_tuning_modal_mount(): void {
	static $rendered = false;
	if ( $rendered ) {
		return;
	}

	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
		return;
	}

	$user_id = get_current_user_id();
	if ( 'user-edit' === $screen->id && isset( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = (int) $_GET['user_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	if ( $user_id <= 0 ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user instanceof WP_User ) {
		return;
	}

	$rendered = true;
	colorify_admin_tuning_modal_markup( $user );
}
add_action( 'admin_footer', 'colorify_admin_print_tuning_modal_mount', 20 );

/**
 * Czy bieżący ekran to edytor wyglądu (profil / ustawienia wtyczki).
 */
function colorify_is_appearance_editor_screen(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen && in_array(
		$screen->id,
		array( 'profile', 'user-edit', 'settings_page_colorify-by-inyfinn' ),
		true
	);
}

/**
 * Ekran WordPress Customizer (customize.php).

/**
 * JS edytora wyglądu — wyłącznie profil / ustawienia Colorify.
 *
 * @param int $context_user_id User ID.
 */
function colorify_enqueue_appearance_editor_script( int $context_user_id ): void {
	$appearance_deps = array( 'jquery' );
	if ( wp_script_is( 'user-profile', 'registered' ) ) {
		$appearance_deps[] = 'user-profile';
	}

	wp_enqueue_script(
		'colorify-admin-appearance',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-appearance.js',
		$appearance_deps,
		COLORIFY_PLUGIN_VERSION,
		true
	);

	$scheme = colorify_get_effective_admin_color( $context_user_id );

	wp_localize_script(
		'colorify-admin-appearance',
		'colorifyAdminAppearance',
		colorify_admin_appearance_script_config( $context_user_id, $scheme )
	);
}

/**
 * @param int    $context_user_id User ID.
 * @param string $scheme          Klucz schematu.
 * @return array<string,mixed>
 */
function colorify_admin_appearance_script_config( int $context_user_id, string $scheme ): array {
	$can_save_mode = true;
	$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $screen && 'user-edit' === $screen->base && isset( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_user = (int) $_GET['user_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit_user !== get_current_user_id() ) {
			$can_save_mode = false;
		}
	}

	return array(
		'scheme'          => $scheme,
		'themeEnabled'    => colorify_is_user_theme_enabled( $context_user_id ) ? '1' : '0',
		'mode'            => colorify_get_effective_appearance_mode( $context_user_id ),
		'schemes'         => colorify_admin_schemes_for_js(),
		'previews'        => colorify_admin_previews_for_js(),
		'schemeOrder'     => colorify_admin_scheme_order_for_js(),
		'customColors'    => colorify_get_effective_custom_colors( $context_user_id ),
		'customTuning'    => colorify_get_effective_custom_tuning( $context_user_id ),
		'tuningMin'       => COLORIFY_ADMIN_TUNING_MIN,
		'tuningMax'       => COLORIFY_ADMIN_TUNING_MAX,
		'tuningWarn'      => COLORIFY_ADMIN_TUNING_WARN,
		'tuningSensAnchorSoft'   => COLORIFY_ADMIN_TUNING_SENS_ANCHOR_SOFT,
		'tuningSensAnchorStrong' => COLORIFY_ADMIN_TUNING_SENS_ANCHOR_STRONG,
		'tuningSensRefLow'       => COLORIFY_ADMIN_TUNING_SENS_REF_LOW,
		'tuningSensRefHigh'      => COLORIFY_ADMIN_TUNING_SENS_REF_HIGH,
		'customSchemeKey' => COLORIFY_ADMIN_CUSTOM_SCHEME_KEY,
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'nonce'           => wp_create_nonce( 'colorify-admin-appearance' ),
		'canSaveMode'     => $can_save_mode,
		'settingsScope'   => colorify_get_settings_scope(),
		'scopeBundles'    => array(
			'global' => colorify_get_scope_appearance_bundle( 'global', $context_user_id ),
			'user'   => colorify_get_scope_appearance_bundle( 'user', $context_user_id ),
		),
		'canManageGlobal'       => current_user_can( 'manage_options' ) ? '1' : '0',
		'hasUserPersonalScheme' => colorify_user_has_personal_appearance( $context_user_id ) ? '1' : '0',
		'isSettingsPage'  => $screen && 'settings_page_colorify-by-inyfinn' === $screen->id,
		'modeBarLabel'    => colorify_i18n( 'Panel mode', 'Tryb panelu' ),
		'i18n'            => array(
			'dark'          => colorify_i18n( 'Dark', 'Ciemny' ),
			'light'         => colorify_i18n( 'Light', 'Jasny' ),
			'changeStyle'   => colorify_i18n( 'Change style', 'Zmień styl' ),
			'panelMode'     => colorify_i18n( 'Panel mode', 'Tryb panelu' ),
			'toggleMode'    => colorify_i18n( 'Toggle dark or light mode', 'Przełącz tryb jasny/ciemny' ),
			'themeOff'      => colorify_i18n( 'Off', 'Wył.' ),
			'themeOn'       => colorify_i18n( 'On', 'Wł.' ),
			'toggleTheme'   => colorify_i18n( 'Toggle Colorify styling', 'Włącz/wyłącz styl Colorify' ),
			'scopeGlobalHint' => colorify_i18n(
				'Preview: global site colors (login and default panel). Changes here update login.',
				'Podgląd: globalne kolory witryny (logowanie i domyślny panel). Zmiany tutaj aktualizują login.'
			),
			'scopeUserHint' => colorify_i18n(
				'Preview: your personal color scheme saved in profile.',
				'Podgląd: Twój osobisty schemat zapisany w profilu.'
			),
			'scopeUserDefaultHint' => colorify_i18n(
				'Preview: per-user mode. Pick a scheme below to save your personal style.',
				'Podgląd: tryb per użytkownik. Wybierz schemat poniżej, aby zapisać własny styl.'
			),
			'saving'     => colorify_i18n( 'Saving…', 'Zapisywanie…' ),
			'saved'      => colorify_i18n( 'Saved.', 'Zapisano.' ),
			'saveFailed' => colorify_i18n( 'Could not save.', 'Nie udało się zapisać.' ),
			'settingsScopeLabels' => array(
				'user'   => colorify_i18n(
					'Per user — each user sets colors in profile',
					'Per użytkownik — każdy ustawia kolory w profilu'
				),
				'global' => colorify_i18n(
					'Global default — fallback without personal style',
					'Globalne domyślne — fallback bez własnego stylu'
				),
			),
		),
		'schemesPanelUrl' => colorify_admin_schemes_panel_url(),
		'assets'          => array(
			'branding'  => add_query_arg( 'ver', COLORIFY_PLUGIN_VERSION, COLORIFY_PLUGIN_URL . 'assets/colorify-branding.css' ),
			'overrides' => add_query_arg( 'ver', COLORIFY_PLUGIN_VERSION, COLORIFY_PLUGIN_URL . 'assets/colorify-admin-overrides.css' ),
			'settings'  => add_query_arg( 'ver', COLORIFY_PLUGIN_VERSION, COLORIFY_PLUGIN_URL . 'assets/colorify-settings.css' ),
		),
	);
}

/**
 * Główny CSS motywu — jeden plik na typowych stronach admina.

/**
 * CSS edytora (profil / ustawienia) — tylko siatka schematów.
 */
function colorify_enqueue_appearance_editor_styles(): void {
	wp_enqueue_style(
		'colorify-settings',
		COLORIFY_PLUGIN_URL . 'assets/colorify-settings.css',
		array( 'colorify-admin-overrides' ),
		COLORIFY_PLUGIN_VERSION
	);
}

/**
 * Profil / ustawienia Colorify przy wyłączonym motywie — tylko UI wtyczki, bez nadpisywania całego wp-admin.
 */
function colorify_enqueue_personalization_assets_when_theme_off(): void {
	if ( colorify_is_user_theme_enabled() ) {
		return;
	}

	if ( ! colorify_is_appearance_editor_screen() ) {
		return;
	}

	wp_enqueue_style(
		'colorify-settings',
		COLORIFY_PLUGIN_URL . 'assets/colorify-settings.css',
		array(),
		COLORIFY_PLUGIN_VERSION
	);
}

/**
 * CSS WordPress Customizer (dark + light).
