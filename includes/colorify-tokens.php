<?php
/**
 * Tokeny kolorów: z definicji schematu i trybu wylicza wszystkie zmienne CSS panelu.
 *
 * Specyfikacja: docs/TOKENS.md, rozdziały 3 do 5.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seek, w którym warunek musi spełnić kontrast z każdym z teł naraz.
 *
 * @param string   $hex Kolor startowy.
 * @param string[] $bgs Tła, na których kolor ma być czytelny.
 * @param int      $dir Kierunek zmiany jasności (-1 ciemniej, +1 jaśniej).
 */
function colorify_tokens_seek_on_all( string $hex, array $bgs, int $dir ): string {
	return colorify_cm_seek(
		$hex,
		static function ( $c ) use ( $bgs ) {
			foreach ( $bgs as $bg ) {
				if ( colorify_cm_contrast( $c, $bg ) < COLORIFY_CM_AA_SAFE ) {
					return false;
				}
			}
			return true;
		},
		$dir
	);
}

/**
 * Seek na jednym tle z progiem AA_SAFE.
 *
 * @param string $hex Kolor startowy.
 * @param string $bg  Tło.
 * @param int    $dir Kierunek zmiany jasności.
 */
function colorify_tokens_seek_on( string $hex, string $bg, int $dir ): string {
	return colorify_tokens_seek_on_all( $hex, array( $bg ), $dir );
}

/**
 * Chroma kandydata, który przejmuje odcień od $source: dla szarości (C źródła poniżej 0.004) odcień jest
 * nieokreślony, więc chroma = 0 (bez przypadkowego różowego odcienia).
 *
 * @param float  $chroma Chroma wzoru.
 * @param string $source Kolor, od którego pochodzi odcień.
 */
function colorify_tokens_chroma( float $chroma, string $source ): float {
	return colorify_cm_to_oklch( $source )[1] < 0.004 ? 0.0 : $chroma;
}

/**
 * Przesuwa jasność tła o $delta, ale gdy tekst na przesuniętym tle spadłby poniżej 4.5, przesuwa o -$delta.
 *
 * @param string $bg    Tło #rrggbb.
 * @param float  $delta Zmiana L według specyfikacji.
 * @param string $text  Tekst, który ma być czytelny na wyniku.
 */
function colorify_tokens_shift_away( string $bg, float $delta, string $text ): string {
	$shifted = colorify_cm_shift_l( $bg, $delta );
	if ( colorify_cm_contrast( $text, $shifted ) >= COLORIFY_CM_AA ) {
		return $shifted;
	}
	return colorify_cm_shift_l( $bg, -$delta );
}

/**
 * Wszystkie zmienne CSS panelu dla schematu i trybu (docs/TOKENS.md).
 *
 * Wartości kolorów to zawsze #rrggbb małymi literami, zmienne --rgb to "r, g, b". Zmienne
 * `--darkreader-*` w `dark.vars` są podane bez `!important`: dopisuje je ten, kto je drukuje.
 *
 * @param array<string,mixed> $def  Schemat po dostrojeniu (accent, accent_soft, bg_dark, bg2_dark, bg_light, bg2_light).
 * @param string              $mode dark|light.
 * @return array{
 *   mode: string,
 *   css: array<string,string>,
 *   dark: array{bg:string,text:string,vars:array<string,string>}|null,
 *   icons: array{base:string,focus:string,current:string},
 *   preview: array{0:string,1:string,2:string,3:string},
 *   meta_theme_color: string
 * }
 */
function colorify_build_tokens( array $def, string $mode ): array {
	$mode   = 'light' === $mode ? 'light' : 'dark';
	$is_dark = 'dark' === $mode;
	$white  = '#ffffff';

	// 3. Wejście: braki i śmieci zastępuje Limonka.
	$defaults = array(
		'accent'      => '#b4e717',
		'accent_soft' => '#92c200',
		'bg_dark'     => '#050f0c',
		'bg2_dark'    => '#0b231c',
		'bg_light'    => '#fefefd',
		'bg2_light'   => '#f7f6f3',
	);
	$in = array();
	foreach ( $defaults as $key => $fallback ) {
		$in[ $key ] = colorify_cm_normalize( isset( $def[ $key ] ) && is_string( $def[ $key ] ) ? $def[ $key ] : '', $fallback );
	}

	$accent = $in['accent'];
	$h_acc  = colorify_cm_to_oklch( $accent )[2];

	// Z pary bg_dark/bg2_dark ciemniejszy to tło ekranu w trybie ciemnym, jaśniejszy to menu w OBU trybach.
	if ( colorify_cm_luminance( $in['bg_dark'] ) > colorify_cm_luminance( $in['bg2_dark'] ) ) {
		$dark_canvas_src = $in['bg2_dark'];
		$menu_bg         = $in['bg_dark'];
	} else {
		$dark_canvas_src = $in['bg_dark'];
		$menu_bg         = $in['bg2_dark'];
	}

	// 4.1 Powierzchnie. canvas_light jest potrzebny w obu trybach (akcent treści).
	$c_light      = colorify_tokens_chroma( colorify_tokens_chroma( min( colorify_cm_to_oklch( $in['bg2_light'] )[1], 0.012 ), $in['bg2_light'] ), $accent );
	$canvas_light = colorify_cm_from_oklch( 0.955, $c_light, $h_acc );

	if ( $is_dark ) {
		$src_lch      = colorify_cm_to_oklch( $dark_canvas_src );
		$canvas       = colorify_cm_from_oklch( min( $src_lch[0], 0.22 ), colorify_tokens_chroma( $src_lch[1], $dark_canvas_src ), $src_lch[2] );
		$surface      = colorify_cm_shift_l( $canvas, 0.07 ); // 2.5.2: wyraźniej odcięte karty w trybie ciemnym (user: "panele lekko za mało widoczne")
		$surface2     = colorify_cm_shift_l( $surface, 0.03 );
		$surface_high = colorify_cm_shift_l( $surface, 0.07 );
		$text         = colorify_cm_from_oklch( 0.93, colorify_tokens_chroma( 0.01, $accent ), $h_acc );
		$text_muted   = colorify_cm_seek(
			colorify_cm_mix( $text, $surface, 0.38 ),
			static function ( $c ) use ( $surface2 ) {
				return colorify_cm_contrast( $c, $surface2 ) >= COLORIFY_CM_AA_SAFE;
			},
			1
		);
		$border       = colorify_cm_mix( $surface, $text, 0.18 );
	} else {
		$canvas       = $canvas_light;
		$surface      = $white;
		$surface2     = '#f6f7f7';
		$surface_high = $canvas;
		$text         = '#1e1e1e';
		$text_muted   = '#50575e';
		$border       = '#dcdcde';
	}

	// 4.2 Rama: menu boczne i górny pasek (te same wartości w obu trybach).
	$h_menu    = colorify_cm_to_oklch( $menu_bg )[2];
	$menu_dark = colorify_cm_luminance( $menu_bg ) < 0.2;
	$menu_text = colorify_cm_on(
		$menu_bg,
		colorify_cm_from_oklch( 0.18, colorify_tokens_chroma( 0.02, $menu_bg ), $h_menu ),
		colorify_cm_from_oklch( 0.96, colorify_tokens_chroma( 0.008, $menu_bg ), $h_menu )
	);
	$toward    = colorify_cm_luminance( $menu_text ) >= colorify_cm_luminance( $menu_bg ) ? 1 : -1;
	$menu_icon = colorify_tokens_seek_on( colorify_cm_mix( $menu_text, $menu_bg, 0.30 ), $menu_bg, $toward );

	$fill = $accent;
	if ( colorify_cm_contrast( $accent, $menu_bg ) < 1.5 ) {
		$fill = colorify_cm_seek(
			$accent,
			static function ( $c ) use ( $menu_bg ) {
				return colorify_cm_contrast( $c, $menu_bg ) >= 1.5;
			},
			$menu_dark ? 1 : -1
		);
	}
	$on_fill = colorify_cm_on( $fill, colorify_cm_from_oklch( 0.18, colorify_tokens_chroma( 0.03, $accent ), $h_acc ), $white );

	// Przesunięcie tła, które obniża kontrast tekstu menu poniżej 4.5, jest odbijane na drugą stronę. Przy menu
	// o luminancji około 0.12 do 0.30 tła leżałyby po obu stronach progu czerń/biel i żaden wspólny tekst by nie istniał.
	$submenu_bg     = colorify_tokens_shift_away( $menu_bg, $menu_dark ? -0.035 : 0.03, $menu_text );
	$submenu_bg_alt = colorify_tokens_shift_away( $menu_bg, $menu_dark ? 0.05 : -0.04, $menu_text );
	$three_bgs      = array( $submenu_bg, $submenu_bg_alt, $menu_bg );

	$submenu_text       = colorify_tokens_seek_on_all( colorify_cm_mix( $menu_text, $menu_bg, 0.25 ), $three_bgs, $toward );
	// Kierunek wg strony tekstu menu ($toward), nie wg progu luminancji 0.2.
	$submenu_focus_text = colorify_tokens_seek_on_all( $accent, $three_bgs, $toward );

	$adminbar_bg = colorify_tokens_shift_away( $menu_bg, $menu_dark ? 0.06 : -0.05, $menu_text );

	$recovery_alt = colorify_cm_shift_l( $fill, -0.04 );
	if ( colorify_cm_contrast( $on_fill, $recovery_alt ) < COLORIFY_CM_AA ) {
		$recovery_alt = $fill;
	}

	// 4.3 Akcent treści: rodzina koloru motywu.
	$theme = colorify_tokens_seek_on_all( $accent, array( $white, $canvas_light ), -1 );
	$d10   = colorify_cm_shift_l( $theme, -0.04 );
	$d20   = colorify_cm_shift_l( $theme, -0.08 );

	$css = array(
		'--colorify-menu-background'                     => $menu_bg,
		'--colorify-menu-text'                           => $menu_text,
		'--colorify-menu-icon'                           => $menu_icon,
		'--colorify-menu-highlight-background'           => $fill,
		'--colorify-menu-highlight-text'                 => $on_fill,
		'--colorify-menu-highlight-icon'                 => $on_fill,
		'--colorify-menu-current-background'             => $fill,
		'--colorify-menu-current-text'                   => $on_fill,
		'--colorify-menu-current-icon'                   => $on_fill,
		'--colorify-menu-submenu-background'             => $submenu_bg,
		'--colorify-menu-submenu-background-alt'         => $submenu_bg_alt,
		'--colorify-menu-submenu-text'                   => $submenu_text,
		'--colorify-menu-submenu-focus-text'             => $submenu_focus_text,
		'--colorify-menu-submenu-current-text'           => $menu_text,
		'--colorify-menu-bubble-background'              => $fill,
		'--colorify-menu-bubble-text'                    => $on_fill,
		'--colorify-menu-bubble-current-background'      => $submenu_bg,
		'--colorify-menu-bubble-current-text'            => $menu_text,
		'--colorify-menu-collapse-text'                  => $menu_icon,
		'--colorify-menu-collapse-icon'                  => $menu_icon,
		'--colorify-menu-collapse-focus-text'            => $on_fill,
		'--colorify-menu-collapse-focus-icon'            => $on_fill,
		'--colorify-adminbar-avatar-frame'               => $adminbar_bg,
		'--colorify-adminbar-input-background'           => $adminbar_bg,
		'--colorify-adminbar-recovery-exit-background'   => $fill,
		'--colorify-adminbar-recovery-exit-text'         => $on_fill,
		'--colorify-adminbar-recovery-exit-background-alt' => $recovery_alt,
		'--colorify-base-color'                          => $menu_bg,
		'--colorify-text-color'                          => $menu_text,
		'--colorify-icon-color'                          => $menu_icon,
		'--colorify-body-background'                     => $canvas,
		'--wp-admin-theme-color'                         => $theme,
		'--wp-admin-theme-color--rgb'                    => colorify_cm_rgb_csv( $theme ),
		'--wp-admin-theme-color-darker-10'               => $d10,
		'--wp-admin-theme-color-darker-10--rgb'          => colorify_cm_rgb_csv( $d10 ),
		'--wp-admin-theme-color-darker-20'               => $d20,
		'--wp-admin-theme-color-darker-20--rgb'          => colorify_cm_rgb_csv( $d20 ),
		'--colorify-theme-lighter'                       => colorify_cm_shift_l( $theme, 0.07 ),
	);

	// 4.4 Tryb ciemny: role akcentu dla Dark Readera.
	$dark = null;
	if ( $is_dark ) {
		$d_fill = colorify_cm_seek(
			colorify_cm_with_l( $accent, 0.62 ),
			static function ( $c ) use ( $text ) {
				return colorify_cm_contrast( $text, $c ) >= COLORIFY_CM_AA_SAFE;
			},
			-1
		);
		$d_fill       = colorify_cm_shift_l( $d_fill, -0.03 );
		$d_fill_hover = colorify_cm_shift_l( $d_fill, 0.02 );
		$d_text       = colorify_cm_seek(
			$accent,
			static function ( $c ) use ( $surface_high ) {
				return colorify_cm_contrast( $c, $surface_high ) >= 5.0;
			},
			1
		);
		$d_text_hover = colorify_cm_shift_l( $d_text, 0.05 );

		$rgb  = colorify_cm_rgb_csv( $d_text );
		$vars = array(
			'--darkreader-bg--wp-admin-theme-color'                 => $d_fill,
			'--darkreader-bg--wp-admin-theme-color-darker-10'       => $d_fill_hover,
			'--darkreader-bg--wp-admin-theme-color-darker-20'       => $d_fill_hover,
			'--darkreader-text--wp-admin-theme-color'               => $d_text,
			'--darkreader-text--wp-admin-theme-color-darker-10'     => $d_text_hover,
			'--darkreader-text--wp-admin-theme-color-darker-20'     => $d_text_hover,
			'--darkreader-border--wp-admin-theme-color'             => $d_text,
			'--darkreader-border--wp-admin-theme-color-darker-10'   => $d_text_hover,
			'--darkreader-border--wp-admin-theme-color-darker-20'   => $d_text_hover,
		);
		foreach ( array( 'bg', 'text', 'border' ) as $role ) {
			foreach ( array( '', '-darker-10', '-darker-20' ) as $suffix ) {
				$vars[ '--darkreader-' . $role . '--wp-admin-theme-color' . $suffix . '--rgb' ] = $rgb;
			}
		}

		$dark = array(
			'bg'   => $surface,
			'text' => $text,
			'vars' => $vars,
		);
		$ui_accent      = $d_text;
		$ui_accent_fill = $d_fill;
		$ui_on_accent   = $text;
	} else {
		$ui_accent      = $theme;
		$ui_accent_fill = $theme;
		$ui_on_accent   = $white;
	}

	// 4.5 Tokeny ekranów samej wtyczki.
	$css['--colorify-ui-bg']          = $canvas;
	$css['--colorify-ui-surface']     = $surface;
	$css['--colorify-ui-surface-2']   = $surface2;
	$css['--colorify-ui-border']      = $border;
	$css['--colorify-ui-text']        = $text;
	$css['--colorify-ui-text-muted']  = $text_muted;
	$css['--colorify-ui-accent']      = $ui_accent;
	$css['--colorify-ui-accent-fill'] = $ui_accent_fill;
	$css['--colorify-ui-on-accent']   = $ui_on_accent;
	$css['--colorify-ui-brand']       = $fill;
	$css['--colorify-ui-on-brand']    = $on_fill;
	$css['--colorify-ui-focus']       = $ui_accent;

	// Stany semantyczne (tekst i ikony na surface oraz surface2): start z kolorów rdzenia WP, potem seek do AA_SAFE.
	$state_start = $is_dark
		? array(
			'warning' => '#e0b040',
			'danger'  => '#ff8a80',
			'success' => '#7fd88a',
		)
		: array(
			'warning' => '#996800',
			'danger'  => '#d63638',
			'success' => '#006e1b',
		);
	foreach ( $state_start as $state => $start ) {
		$css[ '--colorify-ui-' . $state ] = colorify_tokens_seek_on_all( $start, array( $surface, $surface2 ), $is_dark ? 1 : -1 );
	}

	return array(
		'mode'             => $mode,
		'css'              => $css,
		'dark'             => $dark,
		'icons'            => array(
			'base'    => $menu_icon,
			'focus'   => $on_fill,
			'current' => $on_fill,
		),
		'preview'          => array( $menu_bg, $canvas, $fill, $theme ),
		'meta_theme_color' => $menu_bg,
	);
}

/**
 * Tokeny dla użytkownika (zakres per użytkownik albo globalny rozstrzyga colorify-scope.php).
 * Wynik jest pamiętany na czas żądania (klucz: użytkownik + tryb).
 *
 * @param int         $user_id ID użytkownika (0 = bieżący).
 * @param string|null $mode    dark|light albo null (tryb obowiązujący użytkownika).
 * @return array<string,mixed> Patrz colorify_build_tokens().
 */
function colorify_current_tokens( int $user_id = 0, ?string $mode = null ): array {
	static $cache = array();

	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	$mode    = null === $mode ? colorify_get_effective_appearance_mode( $user_id ) : ( 'light' === $mode ? 'light' : 'dark' );
	$key     = $user_id . '|' . $mode;

	if ( ! isset( $cache[ $key ] ) ) {
		$scheme = colorify_get_effective_admin_color( $user_id );
		$def    = colorify_admin_get_resolved_scheme( $scheme, $user_id );
		$tuning = colorify_get_effective_custom_tuning( $user_id );
		$def    = colorify_admin_apply_user_tuning_to_scheme( $def, $mode, $tuning );

		$cache[ $key ] = colorify_build_tokens( $def, $mode );
	}

	return $cache[ $key ];
}

/**
 * Tokeny strony logowania: zawsze ustawienia globalne.
 *
 * @return array<string,mixed> Patrz colorify_build_tokens().
 */
function colorify_login_tokens(): array {
	$mode   = colorify_get_global_appearance_mode();
	$def    = colorify_admin_get_resolved_global_scheme();
	$tuning = colorify_get_global_custom_tuning();
	$def    = colorify_admin_apply_user_tuning_to_scheme( $def, $mode, $tuning );

	return colorify_build_tokens( $def, $mode );
}
