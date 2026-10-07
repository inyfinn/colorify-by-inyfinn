<?php
/**
 * Stary silnik tokenów i rejestracji schematów (1.x). Do zastąpienia przez colorify-tokens.php i colorify-engine.php.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Powierzchnie UI — drabinka jak w natywnym wp-admin (canvas → karta → nagłówek → pole).
 *
 * Light: szare tło (#f0f0f1), białe postboxy, wyraźna ramka.
 * Dark: najciemniejszy canvas, jaśniejsze karty (mix w stronę bieli), nagłówki jeszcze jaśniejsze.
 *
 * @return array{canvas:string,surface:string,surface2:string,field:string,field_hover:string,notice_bg:string}
 */
function colorify_admin_layout_surface_tokens( string $bg, string $bg2, string $text, string $accent, bool $is_light ): array {
	if ( $is_light ) {
		$canvas = sprintf( 'color-mix(in srgb, %s 78%%, %s 22%%)', $bg2, $text );
		$card   = $bg;
		$header = sprintf( 'color-mix(in srgb, %s 97%%, %s 3%%)', $card, $text );

		return array(
			'canvas'       => $canvas,
			'surface'      => $card,
			'surface2'     => $header,
			'field'        => $card,
			'field_hover'  => sprintf( 'color-mix(in srgb, %s 94%%, %s 6%%)', $card, $accent ),
			'notice_bg'    => $card,
		);
	}

	$canvas = $bg;
	$card   = colorify_admin_interpolate_hex( $bg, '#ffffff', 0.11 );
	$header = $card;

	return array(
		'canvas'       => $canvas,
		'surface'      => $card,
		'surface2'     => $header,
		'field'        => colorify_admin_interpolate_hex( $card, $bg, 0.14 ),
		'field_hover'  => colorify_admin_interpolate_hex( $card, $bg2, 0.22 ),
		'notice_bg'    => $card,
	);
}

/**
 * Tokeny UI: tło wierszy = bg, pasek = 20% ciemniej, akcent tylko na linki.
 *
 * @return array<string,string>
 */
function colorify_admin_ui_semantic_tokens( string $bg, string $bg2, string $text, bool $is_light, string $surface = '' ): array {
	$mark_pct = $is_light ? '78' : '80';
	$row_base = '' !== $surface ? $surface : $bg;

	return array(
		'--colorify-admin-mark'            => sprintf( 'color-mix(in srgb, %s %s%%, #000)', $bg, $mark_pct ),
		'--colorify-admin-row-bg'          => $row_base,
		'--colorify-admin-row-active-bg'   => $row_base,
		'--colorify-admin-row-hover-bg'    => sprintf(
			'color-mix(in srgb, %s %s%%, %s)',
			$row_base,
			$is_light ? '94' : '90',
			$text
		),
		'--colorify-admin-row-separator'   => sprintf(
			'color-mix(in srgb, %s %s%%, %s)',
			$row_base,
			$is_light ? '78' : '68',
			$is_light ? '#000' : '#fff'
		),
		'--colorify-admin-notice-bg'       => $row_base,
		'--colorify-admin-ui-hover-bg'     => sprintf(
			'color-mix(in srgb, %s %s%%, %s)',
			$row_base,
			$is_light ? '90' : '86',
			$text
		),
		'--colorify-admin-ui-selected-bg'  => sprintf(
			'color-mix(in srgb, %s 84%%, %s 16%%)',
			$row_base,
			$bg2
		),
		'--colorify-admin-card-shadow'     => 'none',
	);
}

/**
 * @param string               $mode dark|light.
 * @param array<string,mixed>  $def  Resolved scheme.
 * @return array<string,string>
 */
function colorify_admin_tokens_from_scheme( string $mode, array $def ): array {
	$is_light = 'light' === $mode;

	if ( $is_light ) {
		$bg     = $def['bg_light'] ?? '#fefefd';
		$bg2    = $def['bg2_light'] ?? '#f7f6f3';
		$text   = '#18181b';
		$muted  = '#52525b';
		$dim    = '#71717a';
		$border = 'rgba(24, 24, 27, 0.12)';
	} else {
		$bg     = $def['bg_dark'] ?? '#050f0c';
		$bg2    = $def['bg2_dark'] ?? '#0b231c';
		$text   = colorify_admin_brighten_text_hex( '#f4f4f5', 35.0 );
		$muted  = colorify_admin_brighten_text_hex( '#c8d4cf', 38.0 );
		$dim    = colorify_admin_brighten_text_hex( '#8fa39b', 40.0 );
		$border = 'rgba(255, 255, 255, 0.2)';
	}

	list( $bg, $bg2 ) = $is_light ? array( $bg, $bg2 ) : colorify_admin_normalize_bg_pair( $bg, $bg2 );

	$accents  = colorify_admin_resolve_accents_for_mode( $mode, $def, $bg, $bg2 );
	$accent   = $accents[0];
	$acc_soft = $accents[1];
	$link     = $accents[2];
	$link_hov = $accents[3];

	$layout = colorify_admin_layout_surface_tokens( $bg, $bg2, $text, $accent, $is_light );

	$accent_soft_alpha = $is_light ? '10' : '15';

	return array_merge(
		array(
			'--colorify-admin-bg'            => $layout['canvas'] ?? $bg,
			'--colorify-admin-sidebar'       => $bg2,
			'--colorify-admin-surface'       => $layout['surface'],
			'--colorify-admin-surface-2'     => $layout['surface2'],
			'--colorify-admin-field'         => $layout['field'],
			'--colorify-admin-field-hover'   => $layout['field_hover'],
			'--colorify-admin-border'        => $border,
			'--colorify-admin-border-subtle' => $is_light ? 'rgba(24, 24, 27, 0.08)' : 'rgba(255, 255, 255, 0.12)',
			'--colorify-admin-icon'          => $is_light ? '#52525b' : colorify_admin_brighten_text_hex( '#8fa39b', 38.0 ),
			'--colorify-admin-text'          => $text,
			'--colorify-admin-text-muted'    => $muted,
			'--colorify-admin-text-dim'      => $dim,
			'--colorify-admin-text-bright'   => '#ffffff',
			'--colorify-admin-accent'        => $accent,
			'--colorify-admin-accent-muted'  => $acc_soft,
			'--colorify-admin-accent-soft'   => sprintf(
				'color-mix(in srgb, %s %s%%, transparent)',
				$accent,
				$accent_soft_alpha
			),
			'--colorify-admin-link'          => $link,
			'--colorify-admin-link-hover'    => $link_hov,
			'--colorify-admin-on-accent'     => colorify_admin_contrast_on_accent( $accent ),
			'--colorify-admin-readable-text' => $is_light ? '#18181b' : '#eef0ef',
			'--colorify-admin-readable-muted' => $is_light ? '#52525b' : '#b0bdb8',
			'--colorify-admin-readable-dim'  => $is_light ? '#71717a' : '#8a9691',
			'--colorify-admin-readable-icon' => $is_light ? '#52525b' : '#b0bdb8',
			'--colorify-admin-highlight-bg'   => $accent,
			'--colorify-admin-highlight-text' => colorify_admin_contrast_on_accent( $accent ),
		),
		colorify_admin_ui_semantic_tokens( $bg, $bg2, $text, $is_light, $layout['surface'] )
	);
}

/**
 * Mapuje tokeny Colorify na paletę Elementor Editor One (MUI ma kolory w JS — trzeba nadpisać :root).
 *
 * @param array<string,string> $tokens Tokeny schematu.
 * @param string               $mode   dark|light.
 * @return array<string,string>
 */
function colorify_admin_third_party_palette_tokens( array $tokens, string $mode ): array {
	if ( 'light' === $mode ) {
		return array();
	}

	$bg     = $tokens['--colorify-admin-bg'] ?? '#050f0c';
	$bg2    = $tokens['--colorify-admin-sidebar'] ?? '#0b231c';
	$surf   = $tokens['--colorify-admin-surface'] ?? $bg;
	$text   = $tokens['--colorify-admin-readable-text'] ?? '#eef0ef';
	$muted  = $tokens['--colorify-admin-readable-muted'] ?? '#b0bdb8';
	$dim    = $tokens['--colorify-admin-readable-dim'] ?? '#8a9691';
	$border = $tokens['--colorify-admin-border'] ?? 'rgba(255,255,255,0.12)';
	$hover  = $tokens['--colorify-admin-ui-hover-bg'] ?? $bg;
	$accent = $tokens['--colorify-admin-accent'] ?? '#b4e717';
	$active = sprintf( 'color-mix(in srgb, %s 22%%, %s)', $accent, $bg2 );
	$icon   = $tokens['--colorify-admin-readable-icon'] ?? $muted;

	return array(
		'--editor-one-sidebar-bg'            => $bg2,
		'--e-one-palette-background-default' => $bg,
		'--e-one-palette-background-paper'   => $surf,
		'--e-one-palette-text-primary'       => $text,
		'--e-one-palette-text-secondary'     => $muted,
		'--e-one-palette-text-tertiary'      => $dim,
		'--e-one-palette-text-disabled'      => $dim,
		'--e-one-palette-text-tab'           => $muted,
		'--e-one-palette-divider'            => '#5a6560',
		'--e-one-palette-border'             => $border,
		'--e-one-palette-action-hover'       => $hover,
		'--e-one-palette-action-selected'    => $active,
		'--e-one-palette-action-focus'       => $hover,
		'--e-a-bg-default'                   => $bg,
		'--e-a-bg-hover'                     => $hover,
		'--e-a-bg-active'                    => $active,
		'--e-a-bg-loading'                   => $surf,
		'--e-a-color-txt'                    => $text,
		'--e-a-color-txt-muted'              => $muted,
		'--e-a-color-txt-hover'              => $tokens['--colorify-admin-text-bright'] ?? '#ffffff',
		'--e-a-color-txt-active'             => $tokens['--colorify-admin-text-bright'] ?? '#ffffff',
		'--e-a-border-color'                 => $border,
		'--colorify-admin-nav-icon'          => $icon,
		'--colorify-admin-nav-active-bg'     => $accent,
	);
}

/**
 * @deprecated Użyj colorify_admin_tokens_from_scheme().
 * @param string $mode dark|light
 * @return array<string,string>
 */
function colorify_admin_scheme_tokens( string $mode ): array {
	return colorify_admin_tokens_from_scheme( $mode, colorify_admin_get_resolved_scheme( 'colorify-lime' ) );
}

/**
 * Rejestracja schematów WP — tylko ekrany edytora (nie na każdej stronie admina).
 */
function colorify_should_register_admin_color_schemes(): bool {
	if ( ! is_admin() || ! colorify_is_user_theme_enabled() ) {
		return false;
	}

	global $pagenow;

	if ( in_array( $pagenow, array( 'profile.php', 'user-edit.php' ), true ) ) {
		return true;
	}

	return 'options-general.php' === $pagenow
		&& isset( $_GET['page'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& 'colorify-by-inyfinn' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/**
 * Rejestracja schematów WP.
 */
function colorify_register_admin_color_schemes(): void {
	if ( ! colorify_should_register_admin_color_schemes() ) {
		return;
	}

	global $_wp_admin_css_colors;

	$_wp_admin_css_colors = array();
	$colors_ver           = COLORIFY_PLUGIN_VERSION;
	$colors_css_url       = add_query_arg( 'ver', $colors_ver, COLORIFY_PLUGIN_URL . 'assets/colorify-admin-colors.css' );
	$mode                 = colorify_get_effective_appearance_mode();
	$user_id              = get_current_user_id();

	foreach ( colorify_admin_scheme_definitions() as $key => $def ) {
		$resolved    = colorify_admin_get_resolved_scheme( $key, $user_id );
		$preview_key = 'light' === $mode ? 'preview_light' : 'preview_dark';
		$preview     = $resolved[ $preview_key ] ?? array( '#050f0c', '#0b231c', '#B4E717', '#92C200' );

		wp_admin_css_color(
			$key,
			$resolved['name'],
			$colors_css_url,
			$preview,
			array(
				'base'    => $resolved['icon_base'] ?? '#8fa39b',
				'focus'   => '#f4f4f5',
				'current' => '#ffffff',
			)
		);
	}
}
add_action( 'admin_init', 'colorify_register_admin_color_schemes', 99 );

/**
 * @param mixed $value Option value.
 * @return string
 */
function colorify_default_admin_color( $value ): string {
	$user_id = get_current_user_id();
	if ( $user_id > 0 && ! colorify_is_user_theme_enabled( $user_id ) ) {
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		if ( '' === $value || colorify_admin_scheme_is_registered( colorify_admin_normalize_scheme_key( $value ) ) ) {
			return 'modern';
		}
		return $value;
	}

	$value = is_string( $value ) ? colorify_admin_normalize_scheme_key( $value ) : '';

	$legacy = colorify_admin_legacy_light_map();
	if ( isset( $legacy[ $value ] ) ) {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			colorify_admin_set_appearance_mode( $user_id, 'light' );
		}
		return $legacy[ $value ];
	}

	if ( colorify_admin_scheme_is_registered( $value ) ) {
		return $value;
	}

	return 'colorify-lime';
}
add_filter( 'get_user_option_admin_color', 'colorify_default_admin_color' );

/**
 * Tokeny CSS — wyłącznie globalne ustawienia (login).
 *
 * @return array<string,string>
 */
function colorify_admin_scheme_css_tokens_for_login(): array {
	$mode   = colorify_get_global_appearance_mode();
	$def    = colorify_admin_get_resolved_global_scheme();
	$tuning = colorify_get_global_custom_tuning();
	$def    = colorify_admin_apply_user_tuning_to_scheme( $def, $mode, $tuning );

	return colorify_admin_finalize_scheme_css_tokens( $mode, $def );
}

/**
 * @param string               $mode dark|light.
 * @param array<string,mixed>  $def  Schemat.
 * @return array<string,string>
 */
function colorify_admin_finalize_scheme_css_tokens( string $mode, array $def ): array {
	$tokens = colorify_admin_tokens_from_scheme( $mode, $def );

	if ( 'dark' === $mode ) {
		$tokens = array_merge(
			$tokens,
			array(
				'--colorify-admin-text'           => '#eef0ef',
				'--colorify-admin-text-muted'     => '#b0bdb8',
				'--colorify-admin-text-dim'       => '#8a9691',
				'--colorify-admin-icon'           => '#b0bdb8',
				'--colorify-admin-readable-text'  => '#eef0ef',
				'--colorify-admin-readable-muted' => '#b0bdb8',
				'--colorify-admin-readable-dim'   => '#8a9691',
				'--colorify-admin-readable-icon'  => '#b0bdb8',
			)
		);
	}

	$tokens = array_merge( $tokens, colorify_admin_third_party_palette_tokens( $tokens, $mode ) );

	if ( 'dark' === $mode ) {
		$tokens['--colorify-admin-link-content'] = 'color-mix(in srgb, var(--colorify-admin-link, #72aee6) 72%, #eef0ef)';
		$tokens['--colorify-admin-radius-card'] = '4px';
		$tokens['--colorify-admin-radius-dashboard'] = '8px';
	} else {
		$tokens['--colorify-admin-link-content'] = 'var(--colorify-admin-link)';
		$tokens['--colorify-admin-radius-card'] = '4px';
		$tokens['--colorify-admin-radius-dashboard'] = '8px';
	}

	/*
	 * --wp-admin-theme-color MUSI być akcentem, nie tłem panelu.
	 * Core i wtyczki malują nim TEKST i tła przycisków (.wp-core-ui .button,
	 * komponenty Gutenberga, linki) — tło panelu dawało „czarne na czarnym”.
	 */
	$wp_theme = $tokens['--colorify-admin-accent'] ?? COLORIFY_ADMIN_BRAND_COLOR;
	$tokens['--wp-admin-theme-color']           = $wp_theme;
	$tokens['--wp-admin-theme-color--rgb']      = colorify_admin_hex_to_rgb_csv( $wp_theme );
	$tokens['--wp-admin-theme-color-darker-10'] = 'color-mix(in srgb, ' . $wp_theme . ' 90%, #000)';
	$tokens['--wp-admin-theme-color-darker-20'] = 'color-mix(in srgb, ' . $wp_theme . ' 80%, #000)';

	return $tokens;
}

/**
 * Tokeny CSS schematu (wp-admin).
 *
 * @return array<string,string>
 */
function colorify_admin_scheme_css_tokens(): array {
	$user_id = get_current_user_id();
	$mode    = colorify_get_effective_appearance_mode( $user_id );

	return colorify_admin_scheme_css_tokens_for_mode( $mode, $user_id );
}

/**
 * Tokeny CSS dla wybranego trybu (podgląd AJAX bez zapisu).
 *
 * @param string $mode    dark|light.
 * @param int    $user_id User ID.
 * @return array<string,string>
 */
function colorify_admin_scheme_css_tokens_for_mode( string $mode, int $user_id = 0 ): array {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	$mode    = 'light' === $mode ? 'light' : 'dark';
	$scheme  = colorify_get_effective_admin_color( $user_id );
	$def     = colorify_admin_get_resolved_scheme( $scheme, $user_id );
	$tuning  = colorify_get_effective_custom_tuning( $user_id );
	$def     = colorify_admin_apply_user_tuning_to_scheme( $def, $mode, $tuning );

	return colorify_admin_finalize_scheme_css_tokens( $mode, $def );
}

/**
 * @param array<string,string> $tokens Tokeny.
 * @return string Reguła :root + html z !important (po Elementorze).
 */
function colorify_admin_scheme_root_css_from_tokens( array $tokens ): string {
	$root_rules = array();
	foreach ( $tokens as $var => $value ) {
		$root_rules[] = esc_attr( $var ) . ':' . esc_attr( $value );
	}

	$force = array(
		'--e-one-palette-text-primary'   => '#eef0ef',
		'--e-one-palette-text-secondary' => '#b0bdb8',
		'--e-one-palette-text-tertiary'  => '#8a9691',
		'--e-one-palette-divider'        => 'rgba(255,255,255,0.28)',
		'--colorify-admin-text'          => '#eef0ef',
		'--colorify-admin-text-muted'    => '#b0bdb8',
	);
	$force_rules = array();
	foreach ( $force as $var => $value ) {
		$force_rules[] = esc_attr( $var ) . ':' . esc_attr( $value ) . '!important';
	}

	return ':root{' . implode( ';', $root_rules ) . '}'
		. 'html[data-colorify-admin-mode="dark"],body.wp-admin:not(.colorify-admin-light){'
		. implode( ';', $force_rules )
		. '}';
}

/**
 * @return string Reguła :root{...} bez tagu <style>.
 */
function colorify_admin_scheme_root_css(): string {
	return colorify_admin_scheme_root_css_from_tokens( colorify_admin_scheme_css_tokens() );
}

/**
 * Login — zawsze globalny schemat z panelu wtyczki.
 *
 * @return string
 */
function colorify_login_scheme_root_css(): string {
	return colorify_admin_scheme_root_css_from_tokens( colorify_admin_scheme_css_tokens_for_login() );
}

/**
 * CSS variables na podstawie schematu + trybu.
 */
function colorify_admin_scheme_inline_css(): void {
	if ( ! colorify_is_user_theme_enabled() ) {
		return;
	}
	echo '<style id="colorify-scheme-vars">' . colorify_admin_scheme_root_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'admin_head', 'colorify_admin_scheme_inline_css', 5 );

/**
 * Login: globalny schemat (head + przed colorify-branding.css).
 */
function colorify_login_scheme_inline_css(): void {
	echo '<style id="colorify-scheme-vars">' . colorify_login_scheme_root_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'login_head', 'colorify_login_scheme_inline_css', 5 );

/**
 * Login: tokeny w kolejce CSS (po wp_register, przed renderem).
 */
function colorify_login_scheme_inline_style(): void {
	wp_add_inline_style( 'colorify-branding', colorify_login_scheme_root_css() );
}
add_action( 'login_enqueue_scripts', 'colorify_login_scheme_inline_style', 20 );

/**
 * Po Elementorze — nadpisanie :root (admin.css ustawia text-primary:#0c0d0e).
 */
function colorify_admin_scheme_late_inline_style(): void {
	if ( ! colorify_is_user_theme_enabled() || ! wp_style_is( 'colorify-admin-overrides', 'enqueued' ) ) {
		return;
	}
	wp_add_inline_style( 'colorify-admin-overrides', colorify_admin_scheme_root_css() );
}
add_action( 'admin_enqueue_scripts', 'colorify_admin_scheme_late_inline_style', 100000 );

/**
 * @param string $classes Space-separated admin body classes.
 * @return string
 */
function colorify_admin_body_class( string $classes ): string {
	if ( ! colorify_is_user_theme_enabled() ) {
		return trim( $classes . ' colorify-theme-off' );
	}
	if ( 'light' === colorify_get_effective_appearance_mode() ) {
		return trim( $classes . ' colorify-admin-light' );
	}
	return trim( preg_replace( '/\bcolorify-admin-light\b/', '', $classes ) );
}
add_filter( 'admin_body_class', 'colorify_admin_body_class' );

/**
 * Atrybut trybu na <html> (bez JS — statycznie z PHP).
 *
 * @param string $output Istniejące atrybuty language_attributes.
 * @return string
 */
function colorify_admin_html_mode_attribute( string $output ): string {
	if ( ! is_admin() || ! colorify_is_user_theme_enabled() ) {
		return $output;
	}

	$mode = colorify_get_effective_appearance_mode();

	return trim( $output . ' data-colorify-admin-mode="' . esc_attr( $mode ) . '"' );
}
add_filter( 'language_attributes', 'colorify_admin_html_mode_attribute', 20 );

/**
 * Login — tryb zawsze z ustawień globalnych.
 */
function colorify_login_early_mode_script(): void {
	$mode = colorify_get_global_appearance_mode();
	printf(
		'<script id="colorify-admin-early-mode">document.documentElement.setAttribute("data-colorify-admin-mode","%s");</script>',
		esc_attr( $mode )
	);
}
add_action( 'login_head', 'colorify_login_early_mode_script', 1 );
