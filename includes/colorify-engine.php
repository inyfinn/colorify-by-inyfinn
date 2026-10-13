<?php
/**
 * Silnik kolorów panelu.
 *
 * Colorify nie nadpisuje arkuszy WordPressa. Rejestruje swoje schematy tak, jak robi to sam
 * WordPress (wp_admin_css_color), więc rdzeń sam ładuje arkusz schematu, nadaje klasę
 * admin-color-* i koloruje ikony SVG menu. Wartości kolorów trafiają do zmiennych CSS
 * wyliczonych w colorify-tokens.php.
 *
 * Arkusze (budowane z _admin.scss rdzenia przez tools/build-scheme-css.mjs):
 *   colorify-scheme.css  akcenty w treści; ładowany przez rdzeń jako uchwyt "colors"
 *   colorify-chrome.css  menu boczne, górny pasek, tło ekranu
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Czy Colorify koloruje panel dla tego użytkownika (wyłącznik w górnym pasku).
 *
 * @param int $user_id Użytkownik; 0 = bieżący.
 */
function colorify_engine_is_active( int $user_id = 0 ): bool {
	return colorify_is_user_theme_enabled( $user_id );
}

/**
 * Rejestruje schematy Colorify w WordPressie. Schematy rdzenia zostają nietknięte.
 */
function colorify_engine_register_schemes(): void {
	if ( ! colorify_engine_is_active() ) {
		return;
	}

	$url    = add_query_arg( 'cv', rawurlencode( COLORIFY_PLUGIN_VERSION ), COLORIFY_PLUGIN_URL . 'assets/colorify-scheme.css' );
	$tokens = colorify_current_tokens();
	$key    = 'light' === $tokens['mode'] ? 'preview_light' : 'preview_dark';

	foreach ( colorify_admin_scheme_definitions() as $scheme => $def ) {
		$preview = isset( $def[ $key ] ) && is_array( $def[ $key ] ) ? array_values( $def[ $key ] ) : $tokens['preview'];

		wp_admin_css_color(
			$scheme,
			isset( $def['name'] ) ? (string) $def['name'] : $scheme,
			$url,
			$preview,
			// Kolory ikon SVG menu liczą się tylko dla aktywnego schematu, więc wszystkie dostają bieżące.
			$tokens['icons']
		);
	}
}
add_action( 'admin_init', 'colorify_engine_register_schemes', 5 );

/**
 * Która nazwa schematu obowiązuje: zapisany schemat Colorify albo domyślna Limonka.
 * Przy wyłączonym stylu WordPress dostaje swój schemat "modern".
 *
 * @param mixed $value Wartość opcji admin_color.
 */
function colorify_engine_filter_admin_color( $value ): string {
	$user_id = get_current_user_id();
	$value   = is_string( $value ) ? sanitize_key( $value ) : '';

	if ( $user_id > 0 && ! colorify_engine_is_active( $user_id ) ) {
		if ( '' === $value || colorify_admin_scheme_is_registered( colorify_admin_normalize_scheme_key( $value ) ) ) {
			return 'modern';
		}
		return $value;
	}

	$value  = colorify_admin_normalize_scheme_key( $value );
	$legacy = colorify_admin_legacy_light_map();
	if ( isset( $legacy[ $value ] ) ) {
		if ( $user_id > 0 ) {
			colorify_admin_set_appearance_mode( $user_id, 'light' );
		}
		return $legacy[ $value ];
	}

	return colorify_admin_scheme_is_registered( $value ) ? $value : COLORIFY_DEFAULT_SCHEME;
}
add_filter( 'get_user_option_admin_color', 'colorify_engine_filter_admin_color' );

/**
 * Zamienia tablicę zmiennych na deklaracje CSS. Przepuszcza tylko nazwy zmiennych i proste
 * wartości kolorów, bo wynik trafia do znacznika style.
 *
 * @param array<string,string> $vars      Zmienne CSS.
 * @param bool                 $important Dopisać !important.
 */
function colorify_engine_declarations( array $vars, bool $important = false ): string {
	$out = '';
	foreach ( $vars as $name => $value ) {
		if ( ! is_string( $name ) || ! is_string( $value ) ) {
			continue;
		}
		if ( ! preg_match( '/^--[a-z0-9-]+$/', $name ) || ! preg_match( '/^[#0-9a-z,. ]+$/', $value ) ) {
			continue;
		}
		$out .= $name . ':' . $value . ( $important ? '!important' : '' ) . ';';
	}
	return $out;
}

/**
 * Arkusz ze zmiennymi dla podanych tokenów.
 *
 * @param array<string,mixed> $tokens Wynik colorify_build_tokens().
 */
function colorify_engine_tokens_css( array $tokens ): string {
	$vars = isset( $tokens['css'] ) && is_array( $tokens['css'] ) ? $tokens['css'] : array();

	// Edytor blokowy (ThemeProvider) wpisuje swój niebieski kolor motywu w atrybut style kontenerów. Deklaracja
	// !important z arkusza wygrywa z atrybutem style bez !important, więc cały edytor dostaje kolor motywu schematu.
	$theme_vars = array_intersect_key(
		$vars,
		array_flip(
			array(
				'--wp-admin-theme-color',
				'--wp-admin-theme-color--rgb',
				'--wp-admin-theme-color-darker-10',
				'--wp-admin-theme-color-darker-10--rgb',
				'--wp-admin-theme-color-darker-20',
				'--wp-admin-theme-color-darker-20--rgb',
			)
		)
	);
	// OBEJSCIE rdzenia 7.1.3 (edit-post getAdminThemeColors(); do zdjecia, gdy rdzen da hak na ziarno koloru): ThemeProvider liczy ok. 130 zmiennych --wpds-color-* z ziarna wybranego po klasie body
	// admin-color-<schemat> z zaszytej mapy rdzenia (nieznany schemat = domyslny niebieski), bez haka. Zmienne
	// marki (przyciski, pola wyboru, linki, obramowania) dostaja wiec kolory z tokenow motywu.
	$brand = array();
	$theme = isset( $vars['--wp-admin-theme-color'] ) ? (string) $vars['--wp-admin-theme-color'] : '';
	if ( preg_match( '/^#[0-9a-f]{6}$/', $theme ) && isset( $vars['--wp-admin-theme-color-darker-10'], $vars['--wp-admin-theme-color-darker-20'] ) ) {
		$d10   = (string) $vars['--wp-admin-theme-color-darker-10'];
		$d20   = (string) $vars['--wp-admin-theme-color-darker-20'];
		$brand = array(
			'--wpds-color-background-interactive-brand-strong'        => $theme,
			'--wpds-color-background-interactive-brand-strong-active' => $d10,
			'--wpds-color-foreground-interactive-brand-strong'        => '#ffffff',
			'--wpds-color-foreground-interactive-brand-strong-active' => '#ffffff',
			'--wpds-color-foreground-interactive-brand'               => $theme,
			'--wpds-color-foreground-interactive-brand-active'        => $d20,
			'--wpds-color-background-thumb-brand'                     => $theme,
			'--wpds-color-background-thumb-brand-active'              => $theme,
			'--wpds-color-stroke-interactive-brand'                   => $theme,
			'--wpds-color-stroke-interactive-brand-active'            => $d20,
			'--wpds-color-stroke-surface-brand-strong'                => $theme,
			'--wpds-color-stroke-surface-brand'                       => colorify_cm_mix( $theme, '#ffffff', 0.7 ),
			'--wpds-color-background-surface-brand'                   => colorify_cm_mix( $theme, '#ffffff', 0.92 ),
			'--wpds-color-background-interactive-brand-weak-active'   => colorify_cm_mix( $theme, '#ffffff', 0.88 ),
		);
	}
	$inline = '[style*="--wp-admin-theme-color"],[style*="--wpds-color-"]{' . colorify_engine_declarations( array_merge( $theme_vars, $brand ), true ) . '}';

	if ( 'dark' !== ( $tokens['mode'] ?? '' ) || ! isset( $vars['--colorify-body-background'] ) ) {
		return 'html,body{' . colorify_engine_declarations( $vars ) . '}' . $inline;
	}

	// Ciemne tło ekranu dopiero wtedy, gdy silnik trybu ciemnego naprawdę maluje stronę: sam ustawia
	// wtedy atrybut data-darkreader-scheme na <html> i zdejmuje go, gdy przestaje (blokada skryptu,
	// błąd, znacznik darkreader-lock, druga kopia silnika w rozszerzeniu przeglądarki). Bez silnika
	// zostaje jasna treść WordPressa w ciemnej ramie: czytelnie, zamiast ciemnego tekstu na ciemnym tle.
	$canvas                             = $vars['--colorify-body-background'];
	$vars['--colorify-body-background'] = '#f0f0f0';

	return 'html,body{' . colorify_engine_declarations( $vars ) . '}'
		. 'html[data-darkreader-scheme],html[data-darkreader-scheme] body{' . colorify_engine_declarations( array( '--colorify-body-background' => $canvas ) ) . '}'
		. $inline;
}

/**
 * Zmienne schematu w nagłówku strony.
 */
function colorify_engine_print_tokens(): void {
	if ( ! colorify_engine_is_active() ) {
		return;
	}

	$tokens = colorify_current_tokens();

	printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( $tokens['meta_theme_color'] ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zbudowane z przefiltrowanych nazw i wartości.
	echo '<style id="colorify-tokens">' . colorify_engine_tokens_css( $tokens ) . '</style>' . "\n";
}
add_action( 'admin_head', 'colorify_engine_print_tokens', 1 );
add_action( 'customize_controls_head', 'colorify_engine_print_tokens', 1 );

/**
 * Kolor motywu schematu także na stronie publicznej, dla zalogowanych. Narzędzia, które pokazują się tam
 * osobom z panelu (np. zgłaszanie poprawek), biorą akcent z --wp-admin-theme-color, a poza panelem tę zmienną
 * ustawia tylko arkusz bloków rdzenia (niebieski WordPressa, stąd html:root). Wartości z trybu jasnego, bo strona nie jest przyciemniana.
 */
function colorify_engine_print_front_theme_color(): void {
	if ( ! is_user_logged_in() || ! colorify_engine_is_active() ) {
		return;
	}

	$tokens = colorify_current_tokens( 0, 'light' );
	$vars   = array();
	foreach ( (array) $tokens['css'] as $name => $value ) {
		if ( 0 === strpos( (string) $name, '--wp-admin-theme-color' ) ) {
			$vars[ $name ] = $value;
		}
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zbudowane z przefiltrowanych nazw i wartości.
	echo '<style id="colorify-front-theme-color">html:root{' . colorify_engine_declarations( $vars ) . '}</style>' . "
";
}
add_action( 'wp_head', 'colorify_engine_print_front_theme_color', 1 );

/**
 * Arkusz ramy (menu, górny pasek, tło ekranu) po arkuszu schematu.
 */
function colorify_engine_enqueue(): void {
	if ( ! colorify_engine_is_active() ) {
		return;
	}

	// Zaleznosc od arkusza schematu tylko, gdy jest zarejestrowany: niektore wtyczki (kreator WPForms) wyrejestrowuja
	// uchwyt "colors", a WordPress nie wypisuje wtedy stylu wcale (menu i gorny pasek bez naszych kolorow).
	wp_enqueue_style(
		'colorify-chrome',
		COLORIFY_PLUGIN_URL . 'assets/colorify-chrome.css',
		wp_style_is( 'colors', 'registered' ) ? array( 'colors' ) : array(),
		COLORIFY_PLUGIN_VERSION
	);
}
add_action( 'admin_enqueue_scripts', 'colorify_engine_enqueue', 100 );
add_action( 'customize_controls_enqueue_scripts', 'colorify_engine_enqueue', 100 );

/**
 * Klasy body: stan stylu i tryb. Czyta je interfejs wtyczki.
 *
 * @param string $classes Klasy body panelu.
 */
function colorify_engine_body_class( $classes ): string {
	$classes = is_string( $classes ) ? $classes : '';

	if ( ! colorify_engine_is_active() ) {
		return trim( $classes . ' colorify-off' );
	}

	return trim( $classes . ' colorify-on colorify-' . colorify_get_effective_appearance_mode() );
}
add_filter( 'admin_body_class', 'colorify_engine_body_class' );

/**
 * Tokeny obu trybów dla dowolnego wyboru (podgląd na żywo w profilu i ustawieniach, bez zapisu).
 *
 * @param string                                                  $scheme Klucz schematu.
 * @param array<string,string>|null                               $custom Własna paleta (bg, bg2, accent, accent2) albo null.
 * @param array{dark:array<string,int>,light:array<string,int>}   $tuning Dostrojenie.
 * @return array{dark:array<string,mixed>,light:array<string,mixed>}
 */
function colorify_engine_preview_tokens( string $scheme, ?array $custom, array $tuning ): array {
	$scheme = colorify_admin_normalize_scheme_key( $scheme );
	$out    = array();

	foreach ( array( 'dark', 'light' ) as $mode ) {
		if ( COLORIFY_ADMIN_CUSTOM_SCHEME_KEY === $scheme && null !== $custom ) {
			// Tak samo jak colorify_admin_get_resolved_scheme() dla własnej palety: tła ciemne z dostrojenia
			// trybu ciemnego, jasne z jasnego, akcent z ciemnego. Podgląd musi dać to samo co zapis.
			$clean = colorify_admin_sanitize_custom_colors( $custom );
			$dark  = colorify_admin_apply_custom_tuning_colors( $clean, 'dark', $tuning );
			$light = colorify_admin_apply_custom_tuning_colors( $clean, 'light', $tuning );
			$def   = array(
				'accent'      => $dark['accent'],
				'accent_soft' => $dark['accent2'],
				'bg_dark'     => $dark['bg'],
				'bg2_dark'    => $dark['bg2'],
				'bg_light'    => $light['bg'],
				'bg2_light'   => $light['bg2'],
			);
		} else {
			$def = colorify_admin_get_resolved_scheme( $scheme, get_current_user_id() );
			$def = colorify_admin_apply_user_tuning_to_scheme( $def, $mode, $tuning );
		}
		$out[ $mode ] = colorify_build_tokens( $def, $mode );
	}

	return $out;
}

/**
 * AJAX: tokeny do podglądu. Niczego nie zapisuje.
 */
function colorify_engine_ajax_preview_tokens(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	if ( ! current_user_can( 'read' ) ) {
		wp_send_json_error( null, 403 );
	}

	$scheme = isset( $_POST['scheme'] ) ? sanitize_key( wp_unslash( $_POST['scheme'] ) ) : COLORIFY_DEFAULT_SCHEME;
	$custom = null;
	if ( isset( $_POST['custom'] ) && is_array( $_POST['custom'] ) ) {
		$custom = array_map( 'sanitize_text_field', wp_unslash( $_POST['custom'] ) );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in colorify_admin_sanitize_custom_tuning().
	$tuning = colorify_admin_sanitize_custom_tuning( isset( $_POST['tuning'] ) ? wp_unslash( $_POST['tuning'] ) : array() );

	$tokens = colorify_engine_preview_tokens( $scheme, $custom, $tuning );

	wp_send_json_success(
		array(
			'dark'  => colorify_engine_client_payload( $tokens['dark'] ),
			'light' => colorify_engine_client_payload( $tokens['light'] ),
		)
	);
}
add_action( 'wp_ajax_colorify_preview_tokens', 'colorify_engine_ajax_preview_tokens' );

/**
 * Tokeny w postaci dla przeglądarki: gotowy arkusz zmiennych, kolory ikon menu, ustawienia trybu ciemnego.
 *
 * @param array<string,mixed> $tokens Wynik colorify_build_tokens().
 * @return array<string,mixed>
 */
function colorify_engine_client_payload( array $tokens ): array {
	return array(
		'mode'      => $tokens['mode'],
		'css'       => colorify_engine_tokens_css( $tokens ),
		'icons'     => $tokens['icons'],
		'metaColor' => $tokens['meta_theme_color'],
		'dark'      => 'dark' === $tokens['mode'] ? colorify_dark_reader_arguments( $tokens ) : null,
	);
}
