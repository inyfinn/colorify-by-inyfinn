<?php
/**
 * Test tokenów kolorów Colorify 2.0 (docs/TOKENS.md, rozdział 6).
 *
 * Uruchamiany wewnątrz WordPressa (wtyczka musi być załadowana):
 *   wp eval-file tools/test-tokens.php        albo        wp eval-file - < tools/test-tokens.php
 * Kod wyjścia 0 tylko gdy wszystko przeszło.
 *
 * @package ColorifyByInyfinn
 */

if ( ! function_exists( 'colorify_build_tokens' ) || ! function_exists( 'colorify_admin_scheme_definitions' ) ) {
	fwrite( STDERR, "Uruchom wewnatrz WordPressa z zaladowana wtyczka Colorify (wp eval-file).\n" );
	exit( 2 );
}

$hex_re   = '/^#[0-9a-f]{6}$/';
$rgb_re   = '/^\d{1,3}, \d{1,3}, \d{1,3}$/';
$n_pairs  = 0;
$failures = array(); // Każda: array( zestaw, tryb, rodzaj, opis, kontrast, wymagany ).
$min      = array(); // rodzaj => array( kontrast, zestaw, tryb ).

/**
 * Zapisuje wynik jednej pary tekst/tło.
 */
$pair = static function ( string $set, string $mode, string $kind, string $fg, string $bg, float $need ) use ( &$n_pairs, &$failures, &$min, $hex_re ): void {
	++$n_pairs;
	if ( ! preg_match( $hex_re, $fg ) || ! preg_match( $hex_re, $bg ) ) {
		$failures[] = array( $set, $mode, $kind, "zly format: $fg na $bg", 0.0, $need );
		return;
	}
	$cr = colorify_cm_contrast( $fg, $bg );
	if ( ! isset( $min[ $kind ] ) || $cr < $min[ $kind ][0] ) {
		$min[ $kind ] = array( $cr, $set, $mode );
	}
	if ( $cr < $need ) {
		$failures[] = array( $set, $mode, $kind, "$fg na $bg", $cr, $need );
	}
};

/**
 * Zapisuje porażkę nie-parową (format, równość, kompletność).
 */
$fail = static function ( string $set, string $mode, string $kind, string $msg ) use ( &$n_pairs, &$failures ): void {
	++$n_pairs;
	$failures[] = array( $set, $mode, $kind, $msg, 0.0, 0.0 );
};

$required_css = array(
	'--colorify-menu-background', '--colorify-menu-text', '--colorify-menu-icon',
	'--colorify-menu-highlight-background', '--colorify-menu-highlight-text', '--colorify-menu-highlight-icon',
	'--colorify-menu-current-background', '--colorify-menu-current-text', '--colorify-menu-current-icon',
	'--colorify-menu-submenu-background', '--colorify-menu-submenu-background-alt', '--colorify-menu-submenu-text',
	'--colorify-menu-submenu-focus-text', '--colorify-menu-submenu-current-text',
	'--colorify-menu-bubble-background', '--colorify-menu-bubble-text',
	'--colorify-menu-bubble-current-background', '--colorify-menu-bubble-current-text',
	'--colorify-menu-collapse-text', '--colorify-menu-collapse-icon',
	'--colorify-menu-collapse-focus-text', '--colorify-menu-collapse-focus-icon',
	'--colorify-adminbar-avatar-frame', '--colorify-adminbar-input-background',
	'--colorify-adminbar-recovery-exit-background', '--colorify-adminbar-recovery-exit-text',
	'--colorify-adminbar-recovery-exit-background-alt',
	'--colorify-base-color', '--colorify-text-color', '--colorify-icon-color', '--colorify-body-background',
	'--wp-admin-theme-color', '--wp-admin-theme-color--rgb',
	'--wp-admin-theme-color-darker-10', '--wp-admin-theme-color-darker-10--rgb',
	'--wp-admin-theme-color-darker-20', '--wp-admin-theme-color-darker-20--rgb',
	'--colorify-theme-lighter',
	'--colorify-ui-bg', '--colorify-ui-surface', '--colorify-ui-surface-2', '--colorify-ui-border',
	'--colorify-ui-text', '--colorify-ui-text-muted', '--colorify-ui-accent', '--colorify-ui-accent-fill',
	'--colorify-ui-on-accent', '--colorify-ui-brand', '--colorify-ui-on-brand', '--colorify-ui-focus',
);
$required_dark_vars = array();
foreach ( array( 'bg', 'text', 'border' ) as $role ) {
	foreach ( array( '', '-darker-10', '-darker-20' ) as $suffix ) {
		$required_dark_vars[] = '--darkreader-' . $role . '--wp-admin-theme-color' . $suffix;
		$required_dark_vars[] = '--darkreader-' . $role . '--wp-admin-theme-color' . $suffix . '--rgb';
	}
}

/**
 * Sprawdza jeden zestaw (schemat albo paleta) w jednym trybie.
 */
$check_set = static function ( string $set, array $def, string $mode, array $other ) use ( $pair, $fail, $hex_re, $rgb_re, $required_css, $required_dark_vars ): void {
	$t   = colorify_build_tokens( $def, $mode );
	$css = $t['css'];
	$is_dark = 'dark' === $mode;

	// Kompletność i format.
	foreach ( $required_css as $name ) {
		if ( ! isset( $css[ $name ] ) ) {
			$fail( $set, $mode, 'kompletnosc', "brak $name" );
		}
	}
	foreach ( $css as $name => $v ) {
		$ok = '--rgb' === substr( $name, -5 ) ? (bool) preg_match( $rgb_re, $v ) : (bool) preg_match( $hex_re, $v );
		if ( ! $ok ) {
			$fail( $set, $mode, 'format', "$name = $v" );
		}
	}
	if ( $t['mode'] !== $mode ) {
		$fail( $set, $mode, 'kompletnosc', 'mode = ' . $t['mode'] );
	}
	if ( $is_dark !== ( null !== $t['dark'] ) ) {
		$fail( $set, $mode, 'kompletnosc', 'klucz dark niezgodny z trybem' );
	}
	foreach ( array_merge( $t['icons'], $t['preview'], array( $t['meta_theme_color'] ) ) as $v ) {
		if ( ! preg_match( $hex_re, $v ) ) {
			$fail( $set, $mode, 'format', "icons/preview/meta = $v" );
		}
	}
	if ( 3 !== count( $t['icons'] ) || 4 !== count( $t['preview'] ) ) {
		$fail( $set, $mode, 'kompletnosc', 'icons/preview: zla liczba elementow' );
	}

	$g = static function ( string $name ) use ( $css ): string {
		return $css[ $name ] ?? '';
	};

	$menu_bg  = $g( '--colorify-menu-background' );
	$menu_txt = $g( '--colorify-menu-text' );
	$fill     = $g( '--colorify-menu-highlight-background' );
	$sub_bg   = $g( '--colorify-menu-submenu-background' );
	$sub_alt  = $g( '--colorify-menu-submenu-background-alt' );
	$canvas   = $g( '--colorify-ui-bg' );
	$surface  = $g( '--colorify-ui-surface' );
	$surface2 = $g( '--colorify-ui-surface-2' );
	$text     = $g( '--colorify-ui-text' );
	$theme    = $g( '--wp-admin-theme-color' );

	// 4.2 Rama.
	$pair( $set, $mode, 'menu-text / menu-bg', $menu_txt, $menu_bg, 4.5 );
	$pair( $set, $mode, 'menu-icon / menu-bg', $g( '--colorify-menu-icon' ), $menu_bg, 4.5 );
	$pair( $set, $mode, 'fill / menu-bg (>=1.5)', $fill, $menu_bg, 1.5 );
	foreach ( array( 'highlight-text', 'highlight-icon', 'current-text', 'current-icon' ) as $k ) {
		$pair( $set, $mode, 'on-fill / fill', $g( '--colorify-menu-' . $k ), $fill, 4.5 );
	}
	if ( $g( '--colorify-menu-current-background' ) !== $fill ) {
		$fail( $set, $mode, 'rowne', 'current-background != highlight-background' );
	}
	foreach ( array( '--colorify-menu-submenu-text', '--colorify-menu-submenu-focus-text' ) as $name ) {
		$kind = 'submenu-text' === substr( $name, 18 ) ? 'submenu-text / 3 tla' : 'submenu-focus-text / 3 tla';
		foreach ( array( $sub_bg, $sub_alt, $menu_bg ) as $bg ) {
			$pair( $set, $mode, $kind, $g( $name ), $bg, 4.5 );
		}
	}
	$pair( $set, $mode, 'submenu-current-text / submenu-bg', $g( '--colorify-menu-submenu-current-text' ), $sub_bg, 4.5 );
	$pair( $set, $mode, 'bubble-text / bubble-bg', $g( '--colorify-menu-bubble-text' ), $g( '--colorify-menu-bubble-background' ), 4.5 );
	$pair( $set, $mode, 'bubble-current-text / bubble-current-bg', $g( '--colorify-menu-bubble-current-text' ), $g( '--colorify-menu-bubble-current-background' ), 4.5 );
	$pair( $set, $mode, 'collapse-text / menu-bg', $g( '--colorify-menu-collapse-text' ), $menu_bg, 4.5 );
	$pair( $set, $mode, 'collapse-icon / menu-bg', $g( '--colorify-menu-collapse-icon' ), $menu_bg, 4.5 );
	$pair( $set, $mode, 'collapse-focus / fill', $g( '--colorify-menu-collapse-focus-text' ), $fill, 4.5 );
	$pair( $set, $mode, 'collapse-focus / fill', $g( '--colorify-menu-collapse-focus-icon' ), $fill, 4.5 );
	$pair( $set, $mode, 'menu-text / adminbar-avatar-frame', $menu_txt, $g( '--colorify-adminbar-avatar-frame' ), 4.5 );
	$pair( $set, $mode, 'menu-text / adminbar-input-bg', $menu_txt, $g( '--colorify-adminbar-input-background' ), 4.5 );
	$pair( $set, $mode, 'recovery-exit-text / bg', $g( '--colorify-adminbar-recovery-exit-text' ), $g( '--colorify-adminbar-recovery-exit-background' ), 4.5 );
	$pair( $set, $mode, 'recovery-exit-text / bg-alt', $g( '--colorify-adminbar-recovery-exit-text' ), $g( '--colorify-adminbar-recovery-exit-background-alt' ), 4.5 );
	$pair( $set, $mode, 'text-color / base-color', $g( '--colorify-text-color' ), $g( '--colorify-base-color' ), 4.5 );
	$pair( $set, $mode, 'icon-color / base-color', $g( '--colorify-icon-color' ), $g( '--colorify-base-color' ), 4.5 );
	foreach ( array( 'icons.base' => $t['icons']['base'], 'icons.focus' => $t['icons']['focus'], 'icons.current' => $t['icons']['current'] ) as $label => $v ) {
		$pair( $set, $mode, 'icons (SVG menu)', $v, 'icons.base' === $label ? $menu_bg : $fill, 4.5 );
	}
	if ( $t['meta_theme_color'] !== $menu_bg || $t['preview'][0] !== $menu_bg || $t['preview'][1] !== $canvas || $t['preview'][2] !== $fill || $t['preview'][3] !== $theme ) {
		$fail( $set, $mode, 'rowne', 'preview / meta_theme_color niezgodne z tokenami' );
	}

	// Rama dosłownie z palety.
	$norm = static function ( $v, string $fb ): string {
		return colorify_cm_normalize( is_string( $v ) ? $v : '', $fb );
	};
	if ( $is_dark ) {
		$a = $norm( $def['bg_dark'] ?? '', '#050f0c' );
		$b = $norm( $def['bg2_dark'] ?? '', '#0b231c' );
		$expect_menu = colorify_cm_luminance( $a ) > colorify_cm_luminance( $b ) ? $a : $b;
	} else {
		$expect_menu = $norm( $def['bg2_light'] ?? '', '#f7f6f3' );
	}
	if ( $menu_bg !== $expect_menu ) {
		$fail( $set, $mode, 'rowne', "menu-background $menu_bg != paleta $expect_menu" );
	}

	// 4.3 Akcent treści. canvas jasny liczymy z trybu jasnego tych samych danych.
	$canvas_light = 'light' === $mode ? $canvas : $other['css']['--colorify-ui-bg'];
	$pair( $set, $mode, 'theme / bialy', $theme, '#ffffff', 4.5 );
	$pair( $set, $mode, 'theme / canvas jasny', $theme, $canvas_light, 4.5 );
	$pair( $set, $mode, 'theme-darker-10 / bialy (dodatkowe)', $g( '--wp-admin-theme-color-darker-10' ), '#ffffff', 4.5 );
	$pair( $set, $mode, 'theme-darker-20 / bialy (dodatkowe)', $g( '--wp-admin-theme-color-darker-20' ), '#ffffff', 4.5 );
	if ( $theme !== $other['css']['--wp-admin-theme-color'] ) {
		$fail( $set, $mode, 'rowne', 'theme rozny w trybie jasnym i ciemnym' );
	}
	foreach ( array( '', '-darker-10', '-darker-20' ) as $suffix ) {
		$v = $g( '--wp-admin-theme-color' . $suffix );
		if ( $g( '--wp-admin-theme-color' . $suffix . '--rgb' ) !== colorify_cm_rgb_csv( $v ) ) {
			$fail( $set, $mode, 'rowne', "--rgb niezgodne z kolorem ($suffix)" );
		}
	}

	// 4.1 Powierzchnie i tekst.
	$muted = $g( '--colorify-ui-text-muted' );
	if ( $is_dark ) {
		$surface_high = colorify_cm_shift_l( $surface, 0.07 );
		$pair( $set, $mode, 'text / surface_high (>=9)', $text, $surface_high, 9.0 );
		$pair( $set, $mode, 'text / surface, surface2, canvas', $text, $surface, 4.5 );
		$pair( $set, $mode, 'text / surface, surface2, canvas', $text, $surface2, 4.5 );
		$pair( $set, $mode, 'text / surface, surface2, canvas', $text, $canvas, 4.5 );
		$pair( $set, $mode, 'text_muted / surface2', $muted, $surface2, 4.5 );
		$pair( $set, $mode, 'text_muted / surface, canvas', $muted, $surface, 4.5 );
		$pair( $set, $mode, 'text_muted / surface, canvas', $muted, $canvas, 4.5 );
	} else {
		$pair( $set, $mode, 'text / surface, surface2, canvas', $text, $surface, 4.5 );
		$pair( $set, $mode, 'text / surface, surface2, canvas', $text, $surface2, 4.5 );
		$pair( $set, $mode, 'text / surface, surface2, canvas', $text, $canvas, 4.5 );
		$pair( $set, $mode, 'text_muted / surface2', $muted, $surface2, 4.5 );
		$pair( $set, $mode, 'text_muted / surface, canvas', $muted, $surface, 4.5 );
		$pair( $set, $mode, 'text_muted / surface, canvas', $muted, $canvas, 4.5 );
		$lum = colorify_cm_luminance( $canvas );
		++$GLOBALS['colorify_test_n_pairs_extra'];
		if ( $lum < 0.86 ) {
			$fail( $set, $mode, 'canvas jasny: luminancja >= 0.86', sprintf( 'canvas %s ma luminancje %.4f', $canvas, $lum ) );
		}
		if ( $lum < ( $GLOBALS['colorify_test_min_canvas_lum'] ?? 9 ) ) {
			$GLOBALS['colorify_test_min_canvas_lum'] = $lum;
		}
	}

	// 4.4 Dark Reader (tylko tryb ciemny).
	if ( $is_dark && is_array( $t['dark'] ) ) {
		$v = $t['dark']['vars'];
		foreach ( $required_dark_vars as $name ) {
			if ( ! isset( $v[ $name ] ) ) {
				$fail( $set, $mode, 'kompletnosc', "brak $name" );
			}
		}
		foreach ( $v as $name => $val ) {
			$ok = '--rgb' === substr( $name, -5 ) ? (bool) preg_match( $rgb_re, $val ) : (bool) preg_match( $hex_re, $val );
			if ( ! $ok ) {
				$fail( $set, $mode, 'format', "$name = $val" );
			}
		}
		if ( $t['dark']['bg'] !== $surface || $t['dark']['text'] !== $text ) {
			$fail( $set, $mode, 'rowne', 'dark.bg/dark.text niezgodne z surface/text' );
		}
		$d_fill  = $v['--darkreader-bg--wp-admin-theme-color'] ?? '';
		$d_hover = $v['--darkreader-bg--wp-admin-theme-color-darker-10'] ?? '';
		$d_text  = $v['--darkreader-text--wp-admin-theme-color'] ?? '';
		$d_thov  = $v['--darkreader-text--wp-admin-theme-color-darker-10'] ?? '';
		$pair( $set, $mode, 'text / d_fill', $text, $d_fill, 4.5 );
		$pair( $set, $mode, 'text / d_fill_hover', $text, $d_hover, 4.5 );
		$pair( $set, $mode, 'text / d_fill_hover', $text, $v['--darkreader-bg--wp-admin-theme-color-darker-20'] ?? '', 4.5 );
		foreach ( array( 'd_text' => $d_text, 'd_text_hover' => $d_thov ) as $label => $c ) {
			$pair( $set, $mode, $label . ' / surface_high', $c, $surface_high, 4.5 );
			$pair( $set, $mode, $label . ' / canvas', $c, $canvas, 4.5 );
		}
		$pair( $set, $mode, 'd_text-darker-20 / surface_high', $v['--darkreader-text--wp-admin-theme-color-darker-20'] ?? '', $surface_high, 4.5 );
		$pair( $set, $mode, 'd_border / surface_high', $v['--darkreader-border--wp-admin-theme-color'] ?? '', $surface_high, 4.5 );
		$pair( $set, $mode, 'd_text / surface (ui)', $d_text, $surface, 4.5 );
		$pair( $set, $mode, 'd_text / surface2 (ui)', $d_text, $surface2, 4.5 );
	}

	// 4.5 Ekrany wtyczki.
	$accent = $g( '--colorify-ui-accent' );
	foreach ( array( $surface, $surface2, $canvas ) as $bg ) {
		$pair( $set, $mode, 'ui-accent / surface, surface2, canvas', $accent, $bg, 4.5 );
		$pair( $set, $mode, 'ui-focus / surface, surface2, canvas', $g( '--colorify-ui-focus' ), $bg, 4.5 );
	}
	$pair( $set, $mode, 'ui-on-accent / ui-accent-fill', $g( '--colorify-ui-on-accent' ), $g( '--colorify-ui-accent-fill' ), 4.5 );
	$pair( $set, $mode, 'ui-on-brand / ui-brand', $g( '--colorify-ui-on-brand' ), $g( '--colorify-ui-brand' ), 4.5 );
};

$GLOBALS['colorify_test_n_pairs_extra'] = 0;

// 1. Wszystkie schematy wtyczki x 2 tryby.
$schemes   = colorify_admin_scheme_definitions();
$n_sets    = 0;
$n_builds  = 0;
$t_builds  = 0.0;
$timed_run = static function ( array $def, string $set, string $mode ) use ( $check_set, &$n_sets, &$n_builds, &$t_builds ): void {
	$other_mode = 'dark' === $mode ? 'light' : 'dark';
	$t0         = microtime( true );
	$other      = colorify_build_tokens( $def, $other_mode );
	$t_builds  += microtime( true ) - $t0;
	++$n_builds;
	$check_set( $set, $def, $mode, $other );
	++$n_sets;
};

foreach ( $schemes as $key => $def ) {
	$resolved = colorify_admin_get_resolved_scheme( (string) $key );
	foreach ( array( 'dark', 'light' ) as $mode ) {
		$tuned = colorify_admin_apply_user_tuning_to_scheme( $resolved, $mode, colorify_admin_custom_tuning_defaults() );
		$timed_run( $tuned, "schemat $key", $mode );
	}
}
$n_scheme_sets = $n_sets;

// 2. Palety brzegowe i 2000 losowych palet (ziarno stałe).
$edge = array();
$pool = array( '#000000', '#ffffff', '#808080', '#777777', '#ff0000', '#00ff00', '#0000ff', '#ffff00', '#00ffff', '#ff00ff', '#2a2a2a', '#d5d5d5' );
foreach ( $pool as $i => $c ) {
	$edge[] = array(
		'accent' => $c, 'accent_soft' => $c, 'bg_dark' => $pool[ ( $i + 1 ) % count( $pool ) ],
		'bg2_dark' => $c, 'bg_light' => $pool[ ( $i + 5 ) % count( $pool ) ], 'bg2_light' => $c,
	);
}
foreach ( array( '#050505', '#0a0a0a', '#1a1a1a', '#303030', '#3a3a3a', '#454545', '#5a5a5a', '#6a6a6a', '#757575', '#898989', '#9a9a9a', '#b0b0b0' ) as $c ) {
	$edge[] = array(
		'accent' => '#b4e717', 'accent_soft' => '#92c200', 'bg_dark' => $c, 'bg2_dark' => $c, 'bg_light' => $c, 'bg2_light' => $c,
	);
}
$edge[] = array( 'accent' => '', 'accent_soft' => 'zle', 'bg_dark' => '#ABC', 'bg2_dark' => 'nie-kolor', 'bg_light' => '', 'bg2_light' => '#FFF' );
$edge[] = array();
foreach ( $edge as $i => $def ) {
	foreach ( array( 'dark', 'light' ) as $mode ) {
		$timed_run( $def, 'brzegowa #' . $i, $mode );
	}
}
$n_edge_sets = $n_sets - $n_scheme_sets;

mt_srand( 20261007 );
$rand_hex = static function (): string {
	return sprintf( '#%06x', mt_rand( 0, 0xFFFFFF ) );
};
$n_random = 2000;
for ( $i = 0; $i < $n_random; $i++ ) {
	$def = array(
		'bg_dark'     => $rand_hex(),
		'bg2_dark'    => $rand_hex(),
		'bg_light'    => $rand_hex(),
		'bg2_light'   => $rand_hex(),
		'accent'      => $rand_hex(),
		'accent_soft' => $rand_hex(),
	);
	foreach ( array( 'dark', 'light' ) as $mode ) {
		$timed_run( $def, 'losowa #' . $i . ' ' . wp_json_encode( $def ), $mode );
	}
}
$n_random_sets = $n_sets - $n_scheme_sets - $n_edge_sets;

// 3. Wartości kontrolne matematyki.
$control = array(
	array( 'kontrast #000000/#ffffff = 21', abs( colorify_cm_contrast( '#000000', '#ffffff' ) - 21.0 ) < 0.0001 ),
	array( 'kontrast #767676/#ffffff ~ 4.54', abs( colorify_cm_contrast( '#767676', '#ffffff' ) - 4.54 ) < 0.01 ),
	array( 'kontrast #ffffff/#ffffff = 1', abs( colorify_cm_contrast( '#ffffff', '#ffffff' ) - 1.0 ) < 0.0001 ),
	array( 'luminancja #ffffff = 1', abs( colorify_cm_luminance( '#ffffff' ) - 1.0 ) < 0.0001 ),
	array( 'luminancja #000000 = 0', colorify_cm_luminance( '#000000' ) < 0.0001 ),
	array( 'normalize #ABC -> #aabbcc', colorify_cm_normalize( '#ABC', '#000000' ) === '#aabbcc' ),
	array( 'normalize #B4E717 -> #b4e717', colorify_cm_normalize( '#B4E717', '#000000' ) === '#b4e717' ),
	array( 'normalize "" -> fallback', colorify_cm_normalize( '', '#B4E717' ) === '#b4e717' ),
	array( 'normalize "red" -> fallback', colorify_cm_normalize( 'red', '#123456' ) === '#123456' ),
	array( 'normalize "#12345" -> fallback', colorify_cm_normalize( '#12345', '#123456' ) === '#123456' ),
	array( 'rgb_csv #587000 = "88, 112, 0"', colorify_cm_rgb_csv( '#587000' ) === '88, 112, 0' ),
	array( 'mix #000000/#ffffff 0.5 = #808080', colorify_cm_mix( '#000000', '#ffffff', 0.5 ) === '#808080' ),
	array( 'oklch biel L=1', abs( colorify_cm_to_oklch( '#ffffff' )[0] - 1.0 ) < 0.001 ),
	array( 'oklch czern L=0', colorify_cm_to_oklch( '#000000' )[0] < 0.001 ),
	array( 'with_l klamruje do 0..1', '#ffffff' === colorify_cm_with_l( '#336699', 5.0 ) && '#000000' === colorify_cm_with_l( '#336699', -5.0 ) ),
	array( 'seek: spelniony warunek zwraca wejscie', colorify_cm_seek( '#336699', static function () {
		return true;
	}, 1 ) === '#336699' ),
	array( 'seek: niespelnialny warunek -> #ffffff / #000000', colorify_cm_seek( '#336699', static function () {
		return false;
	}, 1 ) === '#ffffff' && colorify_cm_seek( '#336699', static function () {
		return false;
	}, -1 ) === '#000000' ),
	array( 'on: biel na czerni, czern na bieli', colorify_cm_on( '#000000', '#111111', '#eeeeee' ) === '#eeeeee' && colorify_cm_on( '#ffffff', '#111111', '#eeeeee' ) === '#111111' ),
	array( 'on: kandydaci za slabi -> czern/biel', in_array( colorify_cm_on( '#777777', '#666666', '#888888' ), array( '#000000', '#ffffff' ), true ) ),
);
foreach ( $control as $row ) {
	++$n_pairs;
	if ( ! $row[1] ) {
		$failures[] = array( 'wartosci kontrolne', '-', 'math', $row[0], 0.0, 0.0 );
	}
}

// 4. Test odwrotny OKLCH: 500 losowych kolorów, różnica najwyżej 1 na kanał.
$inv_bad  = 0;
$inv_max  = 0;
$inv_n    = 500;
for ( $i = 0; $i < $inv_n; $i++ ) {
	$c   = $rand_hex();
	$lch = colorify_cm_to_oklch( $c );
	$r   = colorify_cm_from_oklch( $lch[0], $lch[1], $lch[2] );
	$a   = colorify_cm_to_rgb( $c );
	$b   = colorify_cm_to_rgb( $r );
	$d   = max( abs( $a[0] - $b[0] ), abs( $a[1] - $b[1] ), abs( $a[2] - $b[2] ) );
	$inv_max = max( $inv_max, $d );
	if ( $d > 1 ) {
		++$inv_bad;
		$failures[] = array( 'odwrotny oklch', '-', 'oklch', "$c -> $r (roznica $d)", 0.0, 0.0 );
	}
}
$n_pairs += $inv_n;

// 5. API wokół WordPressa: current/login zwracają ten sam kształt co build.
$api = array( 'current' => colorify_current_tokens( 1, 'dark' ), 'current-light' => colorify_current_tokens( 1, 'light' ), 'login' => colorify_login_tokens() );
foreach ( $api as $label => $tok ) {
	++$n_pairs;
	$shape_ok = isset( $tok['mode'], $tok['css'], $tok['icons'], $tok['preview'], $tok['meta_theme_color'] ) && array_key_exists( 'dark', $tok );
	if ( ! $shape_ok ) {
		$failures[] = array( 'API ' . $label, '-', 'api', 'zly ksztalt wyniku', 0.0, 0.0 );
	}
}
++$n_pairs;
if ( colorify_current_tokens( 1, 'dark' ) !== $api['current'] ) {
	$failures[] = array( 'API', '-', 'api', 'colorify_current_tokens() niestabilne miedzy wywolaniami', 0.0, 0.0 );
}

// Podsumowanie.
$n_pairs += $GLOBALS['colorify_test_n_pairs_extra'];
echo "=== test-tokens ===\n";
printf( "zestawy: %d (schematy %d x 2 tryby = %d, brzegowe %d, losowe palety %d x 2 tryby = %d)\n", $n_sets, count( $schemes ), $n_scheme_sets, $n_edge_sets, $n_random, $n_random_sets );
printf( "sprawdzone pary i warunki: %d\n", $n_pairs );
printf( "test odwrotny oklch: %d kolorow, najwieksza roznica %d na kanal, porazek %d\n", $inv_n, $inv_max, $inv_bad );
printf( "najnizsza luminancja canvas (tryb jasny): %.4f (wymagane co najmniej 0.86)\n", $GLOBALS['colorify_test_min_canvas_lum'] ?? 0 );
printf( "colorify_build_tokens(): %d wywolan, srednio %.3f ms\n", $n_builds, 1000 * $t_builds / max( 1, $n_builds ) );

$by_kind = array();
foreach ( $failures as $f ) {
	$by_kind[ $f[2] ] = ( $by_kind[ $f[2] ] ?? 0 ) + 1;
}
$failed = count( $failures );
$sets_failed = count( array_unique( array_map( static function ( $f ) {
	return $f[0] . '|' . $f[1];
}, $failures ) ) );
printf( "\nPORAZEK: %d (w %d zestawach)\n", $failed, $sets_failed );
arsort( $by_kind );
foreach ( $by_kind as $kind => $n ) {
	printf( "  %-46s %d\n", $kind, $n );
}
$shown = 0;
foreach ( $failures as $f ) {
	// Schematy wtyczki i wartości kontrolne wypisujemy zawsze, resztę do 25 pozycji.
	$always = 0 === strpos( $f[0], 'schemat ' ) || 0 === strpos( $f[0], 'wartosci' ) || 0 === strpos( $f[0], 'odwrotny' );
	if ( ! $always && $shown++ >= 25 ) {
		continue;
	}
	printf( "  [%s | %s] %s: %s%s\n", substr( $f[0], 0, 120 ), $f[1], $f[2], $f[3], $f[5] > 0 ? sprintf( ' kontrast %.3f, wymagane %.1f', $f[4], $f[5] ) : '' );
}

echo "\nnajnizszy kontrast wg rodzaju pary (kontrast, zestaw, tryb):\n";
ksort( $min );
foreach ( $min as $kind => $row ) {
	printf( "  %-44s %7.3f  %s | %s\n", $kind, $row[0], substr( $row[1], 0, 60 ), $row[2] );
}

echo "\n" . ( $failed ? "WYNIK: $failed porazek" : 'WYNIK: 0 porazek' ) . "\n";
exit( $failed ? 1 : 0 );
