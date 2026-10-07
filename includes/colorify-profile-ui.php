<?php
/**
 * Wybór schematu: blok używany w profilu użytkownika (zamiast natywnego wyboru rdzenia)
 * i w Ustawienia → Colorify (sekcja "Wygląd witryny"). Jeden render, dwa konteksty.
 *
 * Wygląd, teksty i zachowanie: design/SPEC.md rozdział 2. Dane i zapis: istniejące klucze
 * (admin_color, colorify_admin_appearance, colorify_custom_colors, colorify_custom_tuning,
 * colorify_settings_scope), zapis w colorify-save.php i colorify-scope.php.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nazwy grup schematów (klucze jak w colorify_admin_scheme_color_groups()).
 *
 * @return array<string,string>
 */
function colorify_ui_group_labels(): array {
	return array(
		'green'  => __( 'Zielone', 'colorify-by-inyfinn' ),
		'warm'   => __( 'Ciepłe', 'colorify-by-inyfinn' ),
		'blue'   => __( 'Niebieskie i szare', 'colorify-by-inyfinn' ),
		'purple' => __( 'Fioletowe', 'colorify-by-inyfinn' ),
		'earth'  => __( 'Ziemiste', 'colorify-by-inyfinn' ),
	);
}

/**
 * Etykiety czterech kolorów własnej palety (klucze jak w colorify_admin_sanitize_custom_colors()).
 *
 * @return array<string,string>
 */
function colorify_ui_palette_labels(): array {
	return array(
		'bg'      => __( 'Tło', 'colorify-by-inyfinn' ),
		'bg2'     => __( 'Drugie tło', 'colorify-by-inyfinn' ),
		'accent'  => __( 'Akcent', 'colorify-by-inyfinn' ),
		'accent2' => __( 'Drugi akcent', 'colorify-by-inyfinn' ),
	);
}

/**
 * Etykiety czterech suwaków dostrojenia (klucze jak w colorify_admin_sanitize_custom_tuning()).
 *
 * @return array<string,string>
 */
function colorify_ui_tuning_labels(): array {
	return array(
		'bg_brightness'     => __( 'Jasność tła', 'colorify-by-inyfinn' ),
		'bg_saturation'     => __( 'Nasycenie tła', 'colorify-by-inyfinn' ),
		'accent_brightness' => __( 'Jasność akcentu', 'colorify-by-inyfinn' ),
		'accent_saturation' => __( 'Nasycenie akcentu', 'colorify-by-inyfinn' ),
	);
}

/**
 * Zmienne CSS mini-ramki dla obu trybów: dane to dokładnie tablica preview z silnika
 * ([menu_bg, canvas, fill, theme]) plus kolor tekstu trybu.
 *
 * @param array<string,array<string,mixed>> $tokens Tokeny trybów (dark, light) z colorify_build_tokens().
 */
function colorify_ui_preview_style( array $tokens ): string {
	$out = '';

	foreach ( array( 'dark' => 'd', 'light' => 'l' ) as $mode => $p ) {
		if ( empty( $tokens[ $mode ]['preview'] ) ) {
			continue;
		}
		$pv  = $tokens[ $mode ]['preview'];
		$ink = isset( $tokens[ $mode ]['css']['--colorify-ui-text'] ) ? $tokens[ $mode ]['css']['--colorify-ui-text'] : ( 'dark' === $mode ? '#e6e9e2' : '#1e1e1e' );
		$out .= sprintf(
			'--p%1$s-menu:%2$s;--p%1$s-canvas:%3$s;--p%1$s-fill:%4$s;--p%1$s-theme:%5$s;--p%1$s-ink:%6$s;',
			$p,
			(string) sanitize_hex_color( (string) $pv[0] ),
			(string) sanitize_hex_color( (string) $pv[1] ),
			(string) sanitize_hex_color( (string) $pv[2] ),
			(string) sanitize_hex_color( (string) $pv[3] ),
			(string) sanitize_hex_color( (string) $ink )
		);
	}

	return $out;
}

/**
 * Mini-ramka panelu (menu, pasek, tło treści, akcent, przycisk). Kolory z danych schematu,
 * więc element ma data-colorify-swatch: tryb ciemny ich nie przelicza.
 *
 * @param string $style Zmienne --pd-* i --pl-*.
 * @param string $class Dodatkowe klasy.
 * @param string $id    Opcjonalne id.
 */
function colorify_ui_preview_html( string $style, string $class = '', string $id = '' ): string {
	return '<span class="colorify-preview' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' data-colorify-swatch style="' . esc_attr( $style ) . '" aria-hidden="true">'
		. '<span class="colorify-preview__bar"></span>'
		. '<span class="colorify-preview__menu"><span></span><span class="is-on"></span><span></span><span></span></span>'
		. '<span class="colorify-preview__content"><span class="is-theme"></span><span></span><span></span><span class="is-btn"></span></span>'
		. '</span>';
}

/**
 * Pasek "styl wyłączony": status, nie ostrzeżenie. Pokazywany tylko przy wyłączonym stylu.
 *
 * @param string $text Tekst (już zabezpieczony).
 */
function colorify_ui_offstrip( string $text ): void {
	if ( colorify_engine_is_active() ) {
		return;
	}

	echo '<div class="colorify-offstrip" role="status">'
		. colorify_ui_icon( 'power' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. '<p>' . $text . '</p>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. '<a class="button" href="' . esc_url( colorify_toolbar_action_url( 'theme', '1' ) ) . '" data-colorify-toggle="1">' . esc_html__( 'Włącz styl Colorify', 'colorify-by-inyfinn' ) . '</a>'
		. '</div>';
}

/**
 * Blok wyboru schematu.
 *
 * Argumenty:
 *   context      profile|settings
 *   bundle       array{scheme,mode,customColors,customTuning}: to, co edytujemy
 *   bundles      (profil administratora) array{user,global}: komplety do przełączania zakresu
 *   scope        user|global: zakres, który bundle reprezentuje
 *   show_scope   czy pokazać wybór zakresu (profil administratora)
 *   target_user  ID edytowanego użytkownika
 *   actions      czy dołożyć przycisk "Zapisz ustawienia" z zapisem AJAX (profil)
 *
 * @param array<string,mixed> $args Argumenty.
 */
function colorify_ui_render_picker( array $args ): void {
	$context     = 'settings' === ( $args['context'] ?? '' ) ? 'settings' : 'profile';
	$bundle      = $args['bundle'];
	$scheme      = (string) $bundle['scheme'];
	$mode        = 'light' === $bundle['mode'] ? 'light' : 'dark';
	$custom      = $bundle['customColors'];
	$tuning      = $bundle['customTuning'];
	$target_user = (int) ( $args['target_user'] ?? get_current_user_id() );
	$show_scope  = ! empty( $args['show_scope'] );
	$scope       = 'global' === ( $args['scope'] ?? 'user' ) ? 'global' : 'user';
	$custom_key  = COLORIFY_ADMIN_CUSTOM_SCHEME_KEY;
	$defs        = colorify_admin_scheme_definitions();
	$groups      = colorify_admin_scheme_color_groups();
	$labels      = colorify_ui_group_labels();

	$active_group = 'green';
	foreach ( $groups as $group => $keys ) {
		if ( in_array( $scheme, $keys, true ) ) {
			$active_group = $group;
		}
	}
	if ( $custom_key === $scheme ) {
		$active_group = 'custom';
	}

	// Podgląd wybranego schematu i własnej palety (z dostrojeniem).
	$current_tokens = colorify_engine_preview_tokens( $scheme, $custom_key === $scheme ? $custom : null, $tuning );
	$custom_tokens  = colorify_engine_preview_tokens( $custom_key, $custom, $tuning );
	$current_name   = $custom_key === $scheme ? __( 'Własna paleta', 'colorify-by-inyfinn' ) : ( isset( $defs[ $scheme ]['name'] ) ? (string) $defs[ $scheme ]['name'] : $scheme );

	$tune_changed = false;
	foreach ( array( 'dark', 'light' ) as $m ) {
		foreach ( (array) ( $tuning[ $m ] ?? array() ) as $value ) {
			if ( 0 !== (int) $value ) {
				$tune_changed = true;
			}
		}
	}

	$meta = ( 'dark' === $mode ? __( 'Tryb ciemny.', 'colorify-by-inyfinn' ) : __( 'Tryb jasny.', 'colorify-by-inyfinn' ) )
		. ' ' . ( $tune_changed ? __( 'Dostrojenie zmienione.', 'colorify-by-inyfinn' ) : __( 'Dostrojenie bez zmian.', 'colorify-by-inyfinn' ) );

	$config = array(
		'context'      => $context,
		'targetUser'   => $target_user,
		'scope'        => $scope,
		'canScope'     => $show_scope,
		'own'          => get_current_user_id() === $target_user,
		'hasPersonal'  => colorify_user_has_personal_appearance( get_current_user_id() ),
		'bundles'      => isset( $args['bundles'] ) ? $args['bundles'] : null,
		'customKey'    => $custom_key,
		'tuningWarn'   => COLORIFY_ADMIN_TUNING_WARN,
		'settingsUrl'  => admin_url( 'options-general.php?page=colorify-by-inyfinn' ),
	);

	echo '<div class="colorify-picker colorify-ui" id="colorify" data-view="' . esc_attr( $mode ) . '" data-colorify-context="' . esc_attr( $context ) . '" role="group" aria-label="' . esc_attr__( 'Schemat kolorów panelu', 'colorify-by-inyfinn' ) . '">';
	echo '<script type="application/json" id="colorify-picker-data">' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<noscript><style>.colorify-picker .colorify-scheme[hidden]{display:block}.colorify-picker .colorify-tabs{display:none}.colorify-picker .colorify-palette-panel[hidden]{display:block}</style></noscript>';

	if ( 'profile' === $context ) {
		colorify_ui_offstrip( esc_html__( 'Styl Colorify jest wyłączony dla Twojego konta. Panel wygląda jak czysty WordPress, a&nbsp;wybór poniżej zadziała po włączeniu.', 'colorify-by-inyfinn' ) );
	}

	// Zakres (profil administratora).
	if ( $show_scope ) {
		echo '<fieldset class="colorify-scopeline"><legend>' . esc_html__( 'Zakres ustawień', 'colorify-by-inyfinn' ) . '</legend>';
		printf(
			'<label><input type="radio" name="colorify_settings_scope" value="user" %s> %s</label>',
			checked( $scope, 'user', false ),
			esc_html__( 'Każdy użytkownik osobno', 'colorify-by-inyfinn' )
		);
		printf(
			'<label><input type="radio" name="colorify_settings_scope" value="global" %s> %s</label>',
			checked( $scope, 'global', false ),
			esc_html__( 'Jeden wygląd dla całej witryny', 'colorify-by-inyfinn' )
		);
		echo '<a href="' . esc_url( $config['settingsUrl'] ) . '">' . esc_html__( 'Szczegóły: Ustawienia → Colorify', 'colorify-by-inyfinn' ) . '</a>';
		echo '</fieldset>';
	}

	// Wybrany schemat.
	echo '<div class="colorify-current">';
	echo colorify_ui_preview_html( colorify_ui_preview_style( $current_tokens ), '', 'colorify-current-preview' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="colorify-current__text">';
	echo '<p class="colorify-current__label">' . esc_html__( 'Wybrany schemat', 'colorify-by-inyfinn' ) . '</p>';
	echo '<p class="colorify-current__name" id="colorify-current-name">' . esc_html( $current_name ) . '</p>';
	echo '<p class="colorify-current__meta" id="colorify-current-meta">' . esc_html( $meta ) . '</p>';
	echo '</div>';
	echo '<div class="colorify-seg" role="radiogroup" aria-label="' . esc_attr__( 'Tryb panelu', 'colorify-by-inyfinn' ) . '" data-colorify-seg="mode">';
	foreach ( array( 'dark' => array( __( 'Ciemny', 'colorify-by-inyfinn' ), 'moon' ), 'light' => array( __( 'Jasny', 'colorify-by-inyfinn' ), 'sun' ) ) as $m => $info ) {
		printf(
			'<button type="button" role="radio" class="colorify-seg__opt" aria-checked="%s" tabindex="%s" data-pick-mode="%s">%s%s</button>',
			$m === $mode ? 'true' : 'false',
			$m === $mode ? '0' : '-1',
			esc_attr( $m ),
			colorify_ui_icon( $info[1] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $info[0] )
		);
	}
	echo '</div></div>';
	echo '<input type="hidden" name="colorify_admin_appearance" id="colorify-field-mode" value="' . esc_attr( $mode ) . '">';

	// Zakładki grup.
	echo '<div class="colorify-tabs" role="tablist" aria-label="' . esc_attr__( 'Grupy schematów', 'colorify-by-inyfinn' ) . '" hidden>';
	foreach ( $groups as $group => $keys ) {
		$dots = '';
		foreach ( array_slice( $keys, 0, 3 ) as $key ) {
			if ( isset( $defs[ $key ]['accent'] ) ) {
				$dots .= '<i data-colorify-swatch style="background:' . esc_attr( (string) sanitize_hex_color( (string) $defs[ $key ]['accent'] ) ) . '"></i>';
			}
		}
		$selected = $active_group === $group;
		printf(
			'<button type="button" role="tab" class="colorify-tab" id="colorify-tab-%1$s" aria-selected="%2$s" aria-controls="colorify-grid" tabindex="%3$s" data-group="%1$s"><span class="colorify-tab__dots" aria-hidden="true">%4$s</span>%5$s <span class="colorify-tab__count">%6$d</span></button>',
			esc_attr( $group ),
			$selected ? 'true' : 'false',
			$selected ? '0' : '-1',
			$dots, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( $labels[ $group ] ?? $group ),
			count( $keys )
		);
	}
	$selected = 'custom' === $active_group;
	printf(
		'<button type="button" role="tab" class="colorify-tab" id="colorify-tab-custom" aria-selected="%1$s" aria-controls="colorify-palette" tabindex="%2$s" data-group="custom"><span class="colorify-tab__dots" aria-hidden="true"><i data-colorify-swatch style="background:%3$s"></i><i data-colorify-swatch style="background:%4$s"></i></span>%5$s</button>',
		$selected ? 'true' : 'false',
		$selected ? '0' : '-1',
		esc_attr( (string) sanitize_hex_color( (string) $custom['accent'] ) ),
		esc_attr( (string) sanitize_hex_color( (string) $custom['accent2'] ) ),
		esc_html__( 'Własna paleta', 'colorify-by-inyfinn' )
	);
	echo '</div>';

	// Siatka schematów (wszystkie grupy w jednym radiogroup, skrypt chowa nieaktywne).
	echo '<div class="colorify-grid" id="colorify-grid" role="radiogroup" aria-label="' . esc_attr__( 'Schemat kolorów', 'colorify-by-inyfinn' ) . '"' . ( 'custom' === $active_group ? ' hidden' : '' ) . '>';
	foreach ( $groups as $group => $keys ) {
		foreach ( $keys as $key ) {
			if ( ! isset( $defs[ $key ] ) ) {
				continue;
			}
			$def     = $defs[ $key ];
			$tokens  = array(
				'dark'  => colorify_build_tokens( $def, 'dark' ),
				'light' => colorify_build_tokens( $def, 'light' ),
			);
			$hidden  = $group !== $active_group ? ' hidden' : '';
			printf(
				'<label class="colorify-scheme" data-group="%1$s"%2$s><input type="radio" name="admin_color" value="%3$s" %4$s>%5$s<span class="colorify-scheme__name"><span>%6$s</span><span class="colorify-scheme__check">%7$s</span></span></label>',
				esc_attr( $group ),
				$hidden, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_attr( $key ),
				checked( $scheme, $key, false ),
				colorify_ui_preview_html( colorify_ui_preview_style( $tokens ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html( isset( $def['name'] ) ? (string) $def['name'] : $key ),
				colorify_ui_icon( 'check' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}
	echo '</div>';

	// Własna paleta.
	echo '<div class="colorify-palette-panel" id="colorify-palette" role="tabpanel" aria-labelledby="colorify-tab-custom"' . ( 'custom' === $active_group ? '' : ' hidden' ) . '>';
	printf(
		'<label class="colorify-scheme colorify-scheme--custom"><input type="radio" name="admin_color" value="%1$s" %2$s><span class="colorify-radio" aria-hidden="true"></span><span class="colorify-scheme__name"><span>%3$s</span></span></label>',
		esc_attr( $custom_key ),
		checked( $scheme, $custom_key, false ),
		esc_html__( 'Używaj własnej palety', 'colorify-by-inyfinn' )
	);
	echo '<p class="colorify-desc">' . esc_html__( 'Wpisz cztery kolory. Tekst, menu i&nbsp;przyciski dobierzemy automatycznie, żeby wszystko było czytelne.', 'colorify-by-inyfinn' ) . '</p>';
	echo '<div class="colorify-palette"><div class="colorify-palette__fields">';
	foreach ( colorify_ui_palette_labels() as $key => $label ) {
		$value = (string) $custom[ $key ];
		printf(
			'<div class="colorify-field"><label for="colorify-hex-%1$s">%2$s</label><div class="colorify-field__row">'
			. '<input type="color" class="colorify-swatch" id="colorify-sw-%1$s" data-key="%1$s" value="%3$s" aria-label="%4$s">'
			. '<input type="text" class="colorify-input colorify-hex" id="colorify-hex-%1$s" name="colorify_custom_colors[%1$s]" data-key="%1$s" value="%5$s" maxlength="7" spellcheck="false" autocomplete="off" aria-describedby="colorify-hex-err-%1$s">'
			. '</div><p class="colorify-field__err" id="colorify-hex-err-%1$s" hidden>%6$s</p></div>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( strtolower( $value ) ),
			/* translators: %s: nazwa koloru */
			esc_attr( sprintf( __( '%s: próbnik koloru', 'colorify-by-inyfinn' ), $label ) ),
			esc_attr( strtoupper( $value ) ),
			esc_html__( 'Wpisz kolor w zapisie #RRGGBB.', 'colorify-by-inyfinn' )
		);
	}
	echo '</div>';
	echo '<figure class="colorify-palette__preview">'
		. colorify_ui_preview_html( colorify_ui_preview_style( $custom_tokens ), '', 'colorify-custom-preview' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		. '<figcaption id="colorify-custom-caption">' . ( 'dark' === $mode ? esc_html__( 'Tak będzie wyglądać panel w&nbsp;trybie ciemnym.', 'colorify-by-inyfinn' ) : esc_html__( 'Tak będzie wyglądać panel w&nbsp;trybie jasnym.', 'colorify-by-inyfinn' ) ) . '</figcaption></figure>';
	echo '</div></div>';

	// Dostrojenie.
	echo '<details class="colorify-tuning colorify-card" id="colorify-tuning">';
	echo '<summary>' . colorify_ui_icon( 'chev', 'chev' ) . '<span>' . esc_html__( 'Dostrojenie', 'colorify-by-inyfinn' ) . '</span><span class="colorify-tuning__state' . ( $tune_changed ? ' is-changed' : '' ) . '" id="colorify-tuning-state">' . ( $tune_changed ? esc_html__( 'zmienione', 'colorify-by-inyfinn' ) : esc_html__( 'bez zmian', 'colorify-by-inyfinn' ) ) . '</span></summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<div class="colorify-tuning__body"><div class="colorify-tuning__head">';
	echo '<p class="colorify-desc">' . esc_html__( 'Jasność i&nbsp;nasycenie tła oraz akcentu. Zapisują się osobno dla trybu ciemnego i&nbsp;jasnego.', 'colorify-by-inyfinn' ) . '</p>';
	echo '<div class="colorify-seg" role="radiogroup" aria-label="' . esc_attr__( 'Dostrajany tryb', 'colorify-by-inyfinn' ) . '" id="colorify-tune-mode" data-colorify-seg="tune">';
	foreach ( array( 'dark' => __( 'Ciemny', 'colorify-by-inyfinn' ), 'light' => __( 'Jasny', 'colorify-by-inyfinn' ) ) as $m => $label ) {
		printf(
			'<button type="button" role="radio" class="colorify-seg__opt" aria-checked="%s" tabindex="%s" data-tune-mode="%s">%s</button>',
			$m === $mode ? 'true' : 'false',
			$m === $mode ? '0' : '-1',
			esc_attr( $m ),
			esc_html( $label )
		);
	}
	echo '</div></div>';
	echo '<datalist id="colorify-tune-ticks"><option value="0"></option></datalist>';
	echo '<div class="colorify-sliders">';
	foreach ( colorify_ui_tuning_labels() as $key => $label ) {
		$value = (int) ( $tuning[ $mode ][ $key ] ?? 0 );
		$sign  = $value > 0 ? '+' . $value : (string) $value;
		$strong = abs( $value ) > COLORIFY_ADMIN_TUNING_WARN;
		printf(
			'<div class="colorify-slider%1$s" data-id="%2$s"><label for="colorify-t-%2$s">%3$s</label><output for="colorify-t-%2$s" id="colorify-o-%2$s">%4$s</output>'
			. '<input type="range" id="colorify-t-%2$s" min="%5$d" max="%6$d" step="1" value="%7$d" list="colorify-tune-ticks" aria-describedby="colorify-w-%2$s">'
			. '<p class="colorify-slider__warn" id="colorify-w-%2$s">%8$s%9$s</p></div>',
			$strong ? ' is-strong' : '',
			esc_attr( $key ),
			esc_html( $label ),
			esc_html( $sign ),
			COLORIFY_ADMIN_TUNING_MIN,
			COLORIFY_ADMIN_TUNING_MAX,
			$value,
			colorify_ui_icon( 'warn' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html__( 'Duża zmiana. Kolory mogą mocno odbiegać od schematu.', 'colorify-by-inyfinn' )
		);
	}
	echo '</div>';
	// Wartości obu trybów idą do formularza polami ukrytymi; suwaki edytują zestaw wybranego trybu.
	foreach ( array( 'dark', 'light' ) as $m ) {
		foreach ( array_keys( colorify_ui_tuning_labels() ) as $key ) {
			printf(
				'<input type="hidden" name="colorify_custom_tuning[%1$s][%2$s]" value="%3$d" data-tune-mode="%1$s" data-tune-key="%2$s">',
				esc_attr( $m ),
				esc_attr( $key ),
				(int) ( $tuning[ $m ][ $key ] ?? 0 )
			);
		}
	}
	echo '<p class="colorify-tuning__foot"><button type="button" class="button" id="colorify-tune-reset">' . esc_html__( 'Przywróć wartości 0', 'colorify-by-inyfinn' ) . '</button></p>';
	echo '</div></details>';

	if ( ! empty( $args['actions'] ) ) {
		echo '<div class="colorify-actions">'
			. '<button type="button" class="button button-primary button-large" id="colorify-save">' . esc_html__( 'Zapisz ustawienia', 'colorify-by-inyfinn' ) . '</button>'
			. '<span class="colorify-status" id="colorify-status" data-state="idle" role="status" aria-live="polite"></span>'
			. '</div>';
	}

	echo '</div>';
}

/**
 * Nagłówek wiersza w profilu: "Schemat kolorów panelu" zamiast tekstu rdzenia.
 *
 * @param string $translation Przetłumaczony tekst.
 * @param string $text        Tekst źródłowy.
 * @param string $domain      Domena.
 */
function colorify_ui_profile_row_label( $translation, $text, $domain ) {
	global $pagenow;

	if ( 'Administration Color Scheme' === $text && 'default' === $domain && in_array( $pagenow, array( 'profile.php', 'user-edit.php' ), true ) ) {
		return __( 'Schemat kolorów panelu', 'colorify-by-inyfinn' );
	}

	return $translation;
}
add_filter( 'gettext', 'colorify_ui_profile_row_label', 10, 3 );

/**
 * Blok w profilu: zamiast natywnego wyboru rdzenia (ten sam punkt zaczepienia).
 *
 * @param int $user_id Edytowany użytkownik.
 */
function colorify_ui_profile_picker( $user_id ): void {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return;
	}

	$own       = get_current_user_id() === $user_id;
	$can_scope = $own && current_user_can( 'manage_options' );
	$scope     = ( $can_scope && colorify_uses_global_settings() ) ? 'global' : 'user';

	if ( $can_scope ) {
		$bundles = array(
			'user'   => colorify_get_scope_appearance_bundle( 'user', $user_id ),
			'global' => colorify_get_scope_appearance_bundle( 'global', $user_id ),
		);
		$bundle  = $bundles[ $scope ];
	} else {
		$bundles = null;
		$bundle  = array(
			'scheme'       => colorify_get_effective_admin_color( $user_id ),
			'mode'         => colorify_get_effective_appearance_mode( $user_id ),
			'customColors' => colorify_get_effective_custom_colors( $user_id ),
			'customTuning' => colorify_get_effective_custom_tuning( $user_id ),
		);
	}

	colorify_ui_render_picker(
		array(
			'context'     => 'profile',
			'bundle'      => $bundle,
			'bundles'     => $bundles,
			'scope'       => $scope,
			'show_scope'  => $can_scope,
			'target_user' => $user_id,
			'actions'     => true,
		)
	);
}

/**
 * Podmienia natywny wybór schematu rdzenia na blok Colorify.
 */
function colorify_ui_profile_picker_setup(): void {
	remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
	add_action( 'admin_color_scheme_picker', 'colorify_ui_profile_picker', 10 );
}
add_action( 'admin_init', 'colorify_ui_profile_picker_setup' );
