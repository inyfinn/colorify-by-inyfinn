<?php
/**
 * Schematy kolorów wp-admin + własna paleta (4 kolory) + tryb dark/light.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_ADMIN_APPEARANCE_META       = 'colorify_admin_appearance';
const COLORIFY_ADMIN_CUSTOM_COLORS_META    = 'colorify_admin_custom_colors';
const COLORIFY_ADMIN_CUSTOM_TUNING_META    = 'colorify_admin_custom_tuning';
const COLORIFY_ADMIN_BRAND_COLOR           = '#1C4B42';
const COLORIFY_ADMIN_CUSTOM_SCHEME_KEY     = 'colorify-custom';
const COLORIFY_ADMIN_TUNING_MIN            = -90;
const COLORIFY_ADMIN_TUNING_MAX            = 90;
const COLORIFY_ADMIN_TUNING_WARN              = 50;
const COLORIFY_ADMIN_TUNING_SENS_ANCHOR_SOFT  = 50;
const COLORIFY_ADMIN_TUNING_SENS_ANCHOR_STRONG = 70;
const COLORIFY_ADMIN_TUNING_SENS_REF_LOW      = 20;
const COLORIFY_ADMIN_TUNING_SENS_REF_HIGH     = 45;

/**
 * Mapuje stare klucze schematów (dk-*, vp-*, gc-*) na colorify-*.
 */
function colorify_admin_normalize_scheme_key( string $key ): string {
	$explicit = array(
		'vp-custom'   => 'colorify-custom',
		'gc-lime'     => 'colorify-lime',
		'gc-forest'   => 'colorify-forest',
		'gc-emerald'  => 'colorify-jade',
		'gc-violet'   => 'colorify-violet',
	);

	if ( isset( $explicit[ $key ] ) ) {
		return $explicit[ $key ];
	}

	if ( preg_match( '/^(?:dk|vp|gc)-(.+)$/', $key, $matches ) ) {
		return 'colorify-' . $matches[1];
	}

	return $key;
}

/**
 * Odczyt user_meta z fallbackiem na stare klucze vp_*.
 *
 * @param mixed $default Wartość domyślna.
 * @return mixed
 */
function colorify_admin_get_user_meta_value( int $user_id, string $meta_key, $default = '' ) {
	if ( $user_id <= 0 ) {
		return $default;
	}

	$value = get_user_meta( $user_id, $meta_key, true );
	if ( is_string( $value ) && '' !== $value ) {
		return $value;
	}
	if ( is_array( $value ) && ! empty( $value ) ) {
		return $value;
	}

	$legacy_map = array(
		COLORIFY_ADMIN_APPEARANCE_META    => 'vp_admin_appearance',
		COLORIFY_ADMIN_CUSTOM_COLORS_META => 'vp_admin_custom_colors',
		COLORIFY_ADMIN_CUSTOM_TUNING_META => 'vp_admin_custom_tuning',
	);

	if ( ! isset( $legacy_map[ $meta_key ] ) ) {
		return $default;
	}

	$legacy = get_user_meta( $user_id, $legacy_map[ $meta_key ], true );
	if ( is_string( $legacy ) && '' !== $legacy ) {
		return $legacy;
	}
	if ( is_array( $legacy ) && ! empty( $legacy ) ) {
		return $legacy;
	}

	return $default;
}

/**
 * Ogranicza wartość suwaka dostrojenia do dozwolonego zakresu.
 */
function colorify_admin_clamp_tuning_value( int $value ): int {
	return max( COLORIFY_ADMIN_TUNING_MIN, min( COLORIFY_ADMIN_TUNING_MAX, $value ) );
}

/**
 * Efekt suwaka wg poprzedniej krzywej (punkt odniesienia do kalibracji).
 */
function colorify_admin_tuning_legacy_effective_magnitude( int $magnitude ): float {
	$magnitude = max( 0, $magnitude );
	if ( 0 === $magnitude ) {
		return 0.0;
	}

	$legacy_full_at = 50.0;
	$legacy_min     = 0.05;

	if ( $magnitude >= $legacy_full_at ) {
		$scale = 1.0;
	} else {
		$scale = $legacy_min + ( 1.0 - $legacy_min ) * ( $magnitude / $legacy_full_at );
	}

	return (float) $magnitude * $scale;
}

/**
 * Efektywna delta HSL po zastosowaniu krzywej czułości (zachowuje znak).
 *
 * Kalibracja (wszystkie suwaki):
 * - |50| → efekt jak dawniej przy |20|
 * - |70| → efekt jak dawniej przy |45|
 * - powyżej |70| → pełna czułość (+1 jednostka efektu na +1 suwaka)
 */
function colorify_admin_effective_tuning_delta( int $value ): float {
	if ( 0 === $value ) {
		return 0.0;
	}

	$sign = $value < 0 ? -1.0 : 1.0;
	$abs  = (float) abs( $value );

	$anchor_soft   = (float) COLORIFY_ADMIN_TUNING_SENS_ANCHOR_SOFT;
	$anchor_strong = (float) COLORIFY_ADMIN_TUNING_SENS_ANCHOR_STRONG;
	$eff_soft      = colorify_admin_tuning_legacy_effective_magnitude( COLORIFY_ADMIN_TUNING_SENS_REF_LOW );
	$eff_strong    = colorify_admin_tuning_legacy_effective_magnitude( COLORIFY_ADMIN_TUNING_SENS_REF_HIGH );

	if ( $abs >= $anchor_strong ) {
		return $sign * ( $eff_strong + ( $abs - $anchor_strong ) );
	}

	if ( $abs >= $anchor_soft ) {
		$t = ( $abs - $anchor_soft ) / ( $anchor_strong - $anchor_soft );
		return $sign * ( $eff_soft + $t * ( $eff_strong - $eff_soft ) );
	}

	return $sign * ( $eff_soft * ( $abs / $anchor_soft ) );
}

/**
 * @return array{bg:string,bg2:string,accent:string,accent2:string}
 */
function colorify_admin_custom_colors_defaults(): array {
	return array(
		'bg'      => '#050f0c',
		'bg2'     => '#0b231c',
		'accent'  => '#B4E717',
		'accent2' => '#92C200',
	);
}

/**
 * @param int $user_id User ID.
 * @return array{bg:string,bg2:string,accent:string,accent2:string}
 */
function colorify_admin_get_custom_colors( int $user_id = 0 ): array {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	$defaults = colorify_admin_custom_colors_defaults();

	if ( $user_id <= 0 ) {
		return $defaults;
	}

	$stored = colorify_admin_get_user_meta_value( $user_id, COLORIFY_ADMIN_CUSTOM_COLORS_META, array() );
	if ( ! is_array( $stored ) ) {
		return $defaults;
	}

	$out = array();
	foreach ( array_keys( $defaults ) as $key ) {
		$out[ $key ] = colorify_admin_sanitize_hex_color( $stored[ $key ] ?? '' ) ?: $defaults[ $key ];
	}

	return $out;
}

/**
 * @param mixed $value Raw value.
 */
function colorify_admin_sanitize_hex_color( $value ): string {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) {
		return '';
	}
	$sanitized = sanitize_hex_color( $value );
	return is_string( $sanitized ) ? $sanitized : '';
}

/**
 * @param array<string,mixed> $input Raw POST/input.
 * @return array{bg:string,bg2:string,accent:string,accent2:string}
 */
function colorify_admin_sanitize_custom_colors( array $input ): array {
	$defaults = colorify_admin_custom_colors_defaults();
	$out      = array();

	foreach ( array_keys( $defaults ) as $key ) {
		$out[ $key ] = colorify_admin_sanitize_hex_color( $input[ $key ] ?? '' ) ?: $defaults[ $key ];
	}

	return $out;
}

/**
 * Surowa drabinka zielonych teł (przed strojeniem).
 *
 * @return array<int,array{0:string,1:string}>
 */
function colorify_admin_green_bg_ladder_raw(): array {
	return array(
		array( '#020806', '#040e0a' ),
		array( '#030908', '#05100c' ),
		array( '#030a08', '#06100d' ),
		array( '#040b09', '#07110f' ),
		array( '#040c0a', '#081210' ),
		array( '#040d0a', '#091311' ),
		array( '#050d0b', '#0a1412' ),
		array( '#050e0b', '#0a1614' ),
		array( '#050f0c', '#0b1816' ),
		array( '#05100c', '#0b1a18' ),
		array( '#05110d', '#0c1c1a' ),
		array( '#06120e', '#0d1e1c' ),
		array( '#06130f', '#0e201e' ),
		array( '#071410', '#0f2220' ),
		array( '#071511', '#102422' ),
		array( '#081612', '#112624' ),
		array( '#081713', '#122826' ),
		array( '#091814', '#132a28' ),
		array( '#0a1a16', '#152e2c' ),
		array( '#0b1c18', '#173630' ),
		array( '#0c1f1b', '#1a3834' ),
		array( '#0e2620', '#1e453c' ),
	);
}

/**
 * Głęboka/Myśliwska/Bagno +7%; Sosna→Limonka do poziomu Chartreuse.
 *
 * @param array<int,array{0:string,1:string}> $green Surowa drabinka.
 * @return array<int,array{0:string,1:string}>
 */
function colorify_admin_tune_green_bg_ladder( array $green ): array {
	$lift = static function ( array $pair, float $pct ): array {
		return colorify_admin_lift_bg_pair( $pair, $pct );
	};

	for ( $i = 0; $i <= 2; $i++ ) {
		$green[ $i ] = $lift( $green[ $i ], 7.0 );
	}

	$chartreuse = $lift( $green[10], 5.0 );
	$pine_start = $lift( $green[3], 7.0 );

	for ( $i = 3; $i <= 9; $i++ ) {
		$blend = 0.2 + ( ( $i - 3 ) / 6.0 ) * 0.8;
		$green[ $i ] = array(
			colorify_admin_interpolate_hex( $pine_start[0], $chartreuse[0], $blend ),
			colorify_admin_interpolate_hex( $pine_start[1], $chartreuse[1], $blend ),
		);
	}

	for ( $i = 10; $i < count( $green ); $i++ ) {
		$green[ $i ] = $lift( $green[ $i ], 5.0 );
	}

	return $green;
}

/**
 * Drabinka zielonych teł (ciemne → jaśniejsze).
 *
 * @return array<int,array{0:string,1:string}>
 */
function colorify_admin_green_bg_ladder(): array {
	return colorify_admin_tune_green_bg_ladder( colorify_admin_green_bg_ladder_raw() );
}

/**
 * Klucze schematów zielonych (pozostałe po redukcji puli).
 *
 * @return array<int,string>
 */
function colorify_admin_green_scheme_keys(): array {
	return array(
		'colorify-deep',
		'colorify-hunter',
		'colorify-marsh',
		'colorify-pine',
		'colorify-jade',
		'colorify-fern',
		'colorify-eucalyptus',
		'colorify-forest',
		'colorify-lime',
		'colorify-mint',
		'colorify-sage',
	);
}

/**
 * Klucze schematów ciepłych (dawne zielone sloty — rubin, rdza, złoto itd.).
 *
 * @return array<int,string>
 */
function colorify_admin_warm_scheme_keys(): array {
	return array(
		'colorify-seafoam',
		'colorify-chartreuse',
		'colorify-neon',
		'colorify-lime-soft',
		'colorify-moss',
		'colorify-olive',
		'colorify-avocado',
		'colorify-basil',
		'colorify-canopy',
		'colorify-spring',
		'colorify-grove',
	);
}

/**
 * Surowa drabinka ciepłych teł — rubin → czekolada (ciemne → jaśniejsze).
 *
 * @return array<int,array{0:string,1:string}>
 */
function colorify_admin_warm_bg_ladder_raw(): array {
	return array(
		array( '#0a0407', '#160b12' ),
		array( '#0b0405', '#180c0d' ),
		array( '#0a0306', '#170a10' ),
		array( '#0c0504', '#1a0e0c' ),
		array( '#0a0308', '#160c14' ),
		array( '#0b0403', '#181008' ),
		array( '#0a0503', '#1a1209' ),
		array( '#0b0503', '#1b1308' ),
		array( '#0a0603', '#1c1509' ),
		array( '#0b0605', '#1d120e' ),
		array( '#080604', '#151008' ),
	);
}

/**
 * Drabinka ciepłych teł z lekkim podbiciem (jak zielone).
 *
 * @return array<int,array{0:string,1:string}>
 */
function colorify_admin_warm_bg_ladder(): array {
	$warm = colorify_admin_warm_bg_ladder_raw();
	foreach ( $warm as $i => $pair ) {
		$warm[ $i ] = colorify_admin_lift_bg_pair( $pair, 5.0 );
	}
	return $warm;
}

/**
 * Podnosi ciemne tło o ~5% (mix w stronę bieli).
 */
function colorify_admin_lift_bg_hex( string $hex, float $percent = 5.0 ): string {
	return colorify_admin_interpolate_hex( $hex, '#ffffff', $percent / 100 );
}

/**
 * @param array{0:string,1:string} $pair Para bg + bg2.
 * @return array{0:string,1:string}
 */
function colorify_admin_lift_bg_pair( array $pair, float $percent = 5.0 ): array {
	return array(
		colorify_admin_lift_bg_hex( $pair[0], $percent ),
		colorify_admin_lift_bg_hex( $pair[1], $percent ),
	);
}

/**
 * Tekst czytelny na tle koloru — WCAG AA (domyślnie 4.5:1).
 *
 * @param string $bg         Tło (hex).
 * @param float  $min_ratio  Min. stosunek kontrastu.
 * @param string $dark       Kandydat ciemny.
 * @param string $light      Kandydat jasny.
 */
function colorify_admin_pick_text_on_bg(
	string $bg,
	float $min_ratio = 4.5,
	string $dark = '#050f0c',
	string $light = '#ffffff'
): string {
	$ratio_dark  = colorify_admin_contrast_ratio( $dark, $bg );
	$ratio_light = colorify_admin_contrast_ratio( $light, $bg );

	if ( $ratio_dark >= $min_ratio && $ratio_light >= $min_ratio ) {
		return $ratio_dark >= $ratio_light ? $dark : $light;
	}
	if ( $ratio_dark >= $min_ratio ) {
		return $dark;
	}
	if ( $ratio_light >= $min_ratio ) {
		return $light;
	}

	return $ratio_dark >= $ratio_light ? $dark : $light;
}

/**
 * Kolor tekstu na tle akcentu (przyciski, badge, podświetlenie wyboru).
 */
function colorify_admin_contrast_on_accent( string $hex ): string {
	return colorify_admin_pick_text_on_bg( $hex, 4.5 );
}

/**
 * Rozjaśnia kolor tekstu (~30% w stronę bieli).
 */
function colorify_admin_brighten_text_hex( string $hex, float $percent = 30.0 ): string {
	return colorify_admin_interpolate_hex( $hex, '#ffffff', $percent / 100 );
}

/**
 * Stosunek kontrastu WCAG (>= 4.5 = AA normalny tekst).
 */
function colorify_admin_contrast_ratio( string $fg, string $bg ): float {
	$l1 = colorify_admin_relative_luminance( $fg );
	$l2 = colorify_admin_relative_luminance( $bg );
	$lighter = max( $l1, $l2 );
	$darker  = min( $l1, $l2 );
	return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

/**
 * Rozjaśnia kolor aż do min. kontrastu na ciemnym tle (przyciski, hover, zaznaczenie).
 */
function colorify_admin_ensure_visible_on_dark_bg( string $hex, string $bg, float $min_ratio = 3.0 ): string {
	$out = $hex;
	for ( $i = 0; $i < 40; $i++ ) {
		if ( colorify_admin_contrast_ratio( $out, $bg ) >= $min_ratio ) {
			break;
		}
		$out = colorify_admin_interpolate_hex( $out, '#ffffff', 0.08 );
		if ( $i > 18 && colorify_admin_contrast_ratio( $out, $bg ) < $min_ratio ) {
			$out = colorify_admin_interpolate_hex( $out, '#92C200', 0.14 );
		}
	}
	return $out;
}

/**
 * Przyciemnia kolor aż do min. kontrastu na jasnym tle (neutralny grafit, bez zielonego washout).
 */
function colorify_admin_ensure_contrast_on_bg( string $hex, string $bg, float $min_ratio = 4.5 ): string {
	$out = $hex;
	for ( $i = 0; $i < 28; $i++ ) {
		if ( colorify_admin_contrast_ratio( $out, $bg ) >= $min_ratio ) {
			break;
		}
		$out = colorify_admin_interpolate_hex( $out, '#18181b', 0.1 );
	}
	return $out;
}

/**
 * Akcenty UI dopasowane do trybu (light = zachowana saturacja marki, dark = jaśniejsze linki).
 *
 * @return array{0:string,1:string,2:string,3:string} accent, accent_soft, link, link_hover
 */
function colorify_admin_resolve_accents_for_mode( string $mode, array $def, string $bg, string $bg2 ): array {
	$raw      = $def['accent'] ?? '#b4e717';
	$raw_soft = $def['accent_soft'] ?? '#92c200';

	if ( 'light' === $mode ) {
		if ( isset( $def['accent_light'] ) && is_string( $def['accent_light'] ) && '' !== $def['accent_light'] ) {
			$raw = $def['accent_light'];
		}
		if ( isset( $def['accent_soft_light'] ) && is_string( $def['accent_soft_light'] ) && '' !== $def['accent_soft_light'] ) {
			$raw_soft = $def['accent_soft_light'];
		}
		$accent = colorify_admin_ensure_contrast_on_bg( $raw, $bg2, 4.5 );
		$soft   = colorify_admin_ensure_contrast_on_bg( $raw_soft, $bg2, 3.0 );
		$link   = colorify_admin_ensure_contrast_on_bg( $accent, $bg, 4.5 );
		$hover  = colorify_admin_interpolate_hex( $link, '#09090b', 0.14 );
		return array( $accent, $soft, $link, $hover );
	}

	$accent = colorify_admin_ensure_visible_on_dark_bg( $raw, $bg2, 3.0 );
	$soft   = colorify_admin_ensure_visible_on_dark_bg(
		colorify_admin_brighten_text_hex( $raw_soft, 40.0 ),
		$bg2,
		2.8
	);
	$link   = colorify_admin_ensure_visible_on_dark_bg(
		colorify_admin_brighten_text_hex( $raw, 42.0 ),
		$bg,
		4.5
	);
	$hover  = colorify_admin_ensure_visible_on_dark_bg(
		colorify_admin_brighten_text_hex( $raw, 55.0 ),
		$bg,
		4.5
	);
	return array( $accent, $soft, $link, $hover );
}

/**
 * Stonowane akcenty do podglądu light w siatce schematów.
 *
 * @return array{0:string,1:string}
 */
function colorify_admin_light_preview_accents( string $accent, string $accent_soft, string $bg, string $bg2 ): array {
	$resolved = colorify_admin_resolve_accents_for_mode( 'light', array(
		'accent'      => $accent,
		'accent_soft' => $accent_soft,
	), $bg, $bg2 );
	return array( $resolved[0], $resolved[1] );
}

/**
 * Light: bg = prawie białe tło główne, bg2 = sidebar delikatnie bardziej szary.
 * L→P w siatce: jaśniejsze tło → bielsze (różnica bg/sidebar rośnie na ciemniejszych schematach).
 *
 * @param int $steps Liczba stopni.
 * @return array<int,array{0:string,1:string}>
 */
function colorify_admin_light_bg_ladder( int $steps = 1 ): array {
	$steps = max( 1, $steps );
	$pairs = array();

	for ( $i = 0; $i < $steps; $i++ ) {
		$t = 1 === $steps ? 1.0 : $i / ( $steps - 1 );
		$pairs[] = array(
			colorify_admin_interpolate_hex( '#f9f8f6', '#fefefd', $t ),
			colorify_admin_interpolate_hex( '#ebeae7', '#f7f6f3', $t ),
		);
	}

	return $pairs;
}

/**
 * Interpolacja kolorów hex (0 = from, 1 = to).
 */
function colorify_admin_interpolate_hex( string $from, string $to, float $ratio ): string {
	$ratio = max( 0.0, min( 1.0, $ratio ) );
	$from  = ltrim( $from, '#' );
	$to    = ltrim( $to, '#' );

	if ( 3 === strlen( $from ) ) {
		$from = $from[0] . $from[0] . $from[1] . $from[1] . $from[2] . $from[2];
	}
	if ( 3 === strlen( $to ) ) {
		$to = $to[0] . $to[0] . $to[1] . $to[1] . $to[2] . $to[2];
	}

	$channels = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$a   = hexdec( substr( $from, $i * 2, 2 ) );
		$b   = hexdec( substr( $to, $i * 2, 2 ) );
		$val = (int) round( $a + ( $b - $a ) * $ratio );
		$channels[] = str_pad( dechex( $val ), 2, '0', STR_PAD_LEFT );
	}

	return '#' . implode( '', $channels );
}

/**
 * Lekkie dosycenie tła odcieniem akcentu (jak Kamień / Północ).
 *
 * @return array{0:string,1:string}
 */
function colorify_admin_tint_bg_pair_with_accent(
	string $bg,
	string $bg2,
	string $accent,
	float $mix_main = 0.1,
	float $mix_sidebar = 0.16
): array {
	return array(
		colorify_admin_interpolate_hex( $bg, $accent, $mix_main ),
		colorify_admin_interpolate_hex( $bg2, $accent, $mix_sidebar ),
	);
}

/**
 * Czy ciemne tło schematu zostaje bez dodatkowego przyciemnienia (Kamień, Bursztyn, zielenie).
 */
function colorify_admin_scheme_skip_dark_dim( string $key ): bool {
	if ( in_array( $key, colorify_admin_green_scheme_keys(), true ) ) {
		return true;
	}

	return in_array( $key, array( 'colorify-stone', 'colorify-amber', 'colorify-emerald' ), true );
}

/**
 * Dodatkowe przyciemnienie ciemnego tła (HSL L −15) — Purpura, Fuksja, Dymny itd.
 */
function colorify_admin_scheme_dark_dim_amount( string $key ): float {
	return colorify_admin_scheme_skip_dark_dim( $key ) ? 0.0 : 15.0;
}

/**
 * Dostrojenie suwaków na wybranym schemacie (preset lub własna paleta już w resolved).
 *
 * @param array<string,mixed>                                      $def    Schemat.
 * @param string                                                   $mode   dark|light.
 * @param array{dark:array<string,int>,light:array<string,int>}    $tuning Dostrojenie użytkownika.
 * @return array<string,mixed>
 */
function colorify_admin_apply_user_tuning_to_scheme( array $def, string $mode, array $tuning ): array {
	if ( ! empty( $def['custom'] ) ) {
		return $def;
	}

	$m = 'light' === $mode ? 'light' : 'dark';
	$t = $tuning[ $m ] ?? colorify_admin_custom_tuning_defaults()[ $m ];

	$bg_b  = colorify_admin_effective_tuning_delta( (int) $t['bg_brightness'] );
	$bg_s  = colorify_admin_effective_tuning_delta( (int) $t['bg_saturation'] );
	$acc_b = colorify_admin_effective_tuning_delta( (int) $t['accent_brightness'] );
	$acc_s = colorify_admin_effective_tuning_delta( (int) $t['accent_saturation'] );

	if ( 'light' === $mode ) {
		$bg  = $def['bg_light'] ?? '#fefefd';
		$bg2 = $def['bg2_light'] ?? '#f7f6f3';
		$def['bg_light']  = colorify_admin_adjust_hex_hsl( $bg, $bg_b, $bg_s );
		$def['bg2_light'] = colorify_admin_adjust_hex_hsl( $bg2, $bg_b, $bg_s );
		$def['accent']      = colorify_admin_adjust_hex_hsl( $def['accent'] ?? '#b4e717', $acc_b, $acc_s );
		$def['accent_soft'] = colorify_admin_adjust_hex_hsl( $def['accent_soft'] ?? '#92c200', $acc_b, $acc_s );
		$acc_l              = colorify_admin_light_preview_accents(
			$def['accent'],
			$def['accent_soft'],
			$def['bg_light'],
			$def['bg2_light']
		);
		$def['accent_light']     = $acc_l[0];
		$def['accent_soft_light'] = $acc_l[1];
	} else {
		$def['bg_dark']  = colorify_admin_adjust_hex_hsl( $def['bg_dark'] ?? '#050f0c', $bg_b, $bg_s );
		$def['bg2_dark'] = colorify_admin_adjust_hex_hsl( $def['bg2_dark'] ?? '#0b231c', $bg_b, $bg_s );
		$def['accent']      = colorify_admin_adjust_hex_hsl( $def['accent'] ?? '#b4e717', $acc_b, $acc_s );
		$def['accent_soft'] = colorify_admin_adjust_hex_hsl( $def['accent_soft'] ?? '#92c200', $acc_b, $acc_s );
	}

	return $def;
}

/**
 * Współczynniki szczypnięcia akcentem — dark + light per schemat.
 *
 * @return array{dark:array{0:float,1:float},light:array{0:float,1:float}}
 */
function colorify_admin_scheme_tint_ratios( string $key ): array {
	$preset = array(
		'colorify-stone', 'colorify-midnight', 'colorify-graphite', 'colorify-charcoal', 'colorify-slate', 'colorify-smoke',
	);
	if ( in_array( $key, $preset, true ) ) {
		return array(
			'dark'  => array( 0.05, 0.08 ),
			'light' => array( 0.032, 0.058 ),
		);
	}

	$vivid = array( 'colorify-fuchsia', 'colorify-purple', 'colorify-rose', 'colorify-violet' );
	if ( in_array( $key, $vivid, true ) ) {
		return array(
			'dark'  => array( 0.12, 0.18 ),
			'light' => array( 0.048, 0.085 ),
		);
	}

	if ( in_array( $key, colorify_admin_warm_scheme_keys(), true ) ) {
		return array(
			'dark'  => array( 0.0, 0.0 ),
			'light' => array( 0.07, 0.12 ),
		);
	}

	if ( in_array( $key, colorify_admin_green_scheme_keys(), true ) ) {
		return array(
			'dark'  => array( 0.0, 0.0 ),
			'light' => array( 0.034, 0.062 ),
		);
	}

	return array(
		'dark'  => array( 0.09, 0.14 ),
		'light' => array( 0.038, 0.068 ),
	);
}

/**
 * @return array{h:float,s:float,l:float}
 */
function colorify_admin_hex_to_hsl( string $hex ): array {
	$hex = ltrim( colorify_admin_sanitize_hex_color( $hex ) ?: '#000000', '#' );
	$r   = hexdec( substr( $hex, 0, 2 ) ) / 255;
	$g   = hexdec( substr( $hex, 2, 2 ) ) / 255;
	$b   = hexdec( substr( $hex, 4, 2 ) ) / 255;

	$max = max( $r, $g, $b );
	$min = min( $r, $g, $b );
	$l   = ( $max + $min ) / 2;
	$h   = 0.0;
	$s   = 0.0;

	if ( abs( $max - $min ) > 0.00001 ) {
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );

		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}
		$h /= 6;
	}

	return array(
		'h' => $h * 360,
		's' => $s * 100,
		'l' => $l * 100,
	);
}

/**
 * @param float $h 0–360.
 * @param float $s 0–100.
 * @param float $l 0–100.
 */
function colorify_admin_hsl_to_hex( float $h, float $s, float $l ): string {
	$h = fmod( $h, 360 );
	if ( $h < 0 ) {
		$h += 360;
	}
	$s = max( 0, min( 100, $s ) ) / 100;
	$l = max( 0, min( 100, $l ) ) / 100;

	if ( $s <= 0.00001 ) {
		$val = (int) round( $l * 255 );
		$hex = str_pad( dechex( $val ), 2, '0', STR_PAD_LEFT );
		return '#' . $hex . $hex . $hex;
	}

	$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
	$p = 2 * $l - $q;
	$hk = $h / 360;

	$channels = array();
	foreach ( array( $hk + 1 / 3, $hk, $hk - 1 / 3 ) as $t ) {
		if ( $t < 0 ) {
			$t += 1;
		}
		if ( $t > 1 ) {
			$t -= 1;
		}
		if ( $t < 1 / 6 ) {
			$val = $p + ( $q - $p ) * 6 * $t;
		} elseif ( $t < 1 / 2 ) {
			$val = $q;
		} elseif ( $t < 2 / 3 ) {
			$val = $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		} else {
			$val = $p;
		}
		$channels[] = str_pad( dechex( (int) round( $val * 255 ) ), 2, '0', STR_PAD_LEFT );
	}

	return '#' . implode( '', $channels );
}

/**
 * Korekta jasności i nasycenia (zakres dostrojenia −90…90).
 */
function colorify_admin_adjust_hex_hsl( string $hex, float $brightness = 0, float $saturation = 0 ): string {
	$hsl = colorify_admin_hex_to_hsl( $hex );
	return colorify_admin_hsl_to_hex(
		$hsl['h'],
		max( 0, min( 100, $hsl['s'] + $saturation ) ),
		max( 0, min( 100, $hsl['l'] + $brightness ) )
	);
}

/**
 * @return array{dark:array<string,int>,light:array<string,int>}
 */
function colorify_admin_custom_tuning_defaults(): array {
	$zero = static function (): array {
		return array(
			'bg_brightness'      => 0,
			'bg_saturation'      => 0,
			'accent_brightness'  => 0,
			'accent_saturation'  => 0,
		);
	};

	return array(
		'dark'  => $zero(),
		'light' => $zero(),
	);
}

/**
 * @param int $user_id User ID.
 * @return array{dark:array<string,int>,light:array<string,int>}
 */
function colorify_admin_get_custom_tuning( int $user_id = 0 ): array {
	$user_id  = $user_id > 0 ? $user_id : get_current_user_id();
	$defaults = colorify_admin_custom_tuning_defaults();

	if ( $user_id <= 0 ) {
		return $defaults;
	}

	$stored = colorify_admin_get_user_meta_value( $user_id, COLORIFY_ADMIN_CUSTOM_TUNING_META, array() );
	if ( ! is_array( $stored ) ) {
		return $defaults;
	}

	$out = array();
	foreach ( array( 'dark', 'light' ) as $mode ) {
		$out[ $mode ] = array();
		foreach ( array_keys( $defaults['dark'] ) as $key ) {
			$raw = (int) ( $stored[ $mode ][ $key ] ?? 0 );
			$out[ $mode ][ $key ] = colorify_admin_clamp_tuning_value( $raw );
		}
	}

	return $out;
}

/**
 * @param mixed $input Raw POST.
 * @return array{dark:array<string,int>,light:array<string,int>}
 */
function colorify_admin_sanitize_custom_tuning( $input ): array {
	$defaults = colorify_admin_custom_tuning_defaults();
	if ( ! is_array( $input ) ) {
		return $defaults;
	}

	$out = array();
	foreach ( array( 'dark', 'light' ) as $mode ) {
		$out[ $mode ] = array();
		$src          = is_array( $input[ $mode ] ?? null ) ? $input[ $mode ] : array();
		foreach ( array_keys( $defaults['dark'] ) as $key ) {
			$raw = (int) ( $src[ $key ] ?? 0 );
			$out[ $mode ][ $key ] = colorify_admin_clamp_tuning_value( $raw );
		}
	}

	return $out;
}

/**
 * @param array{bg:string,bg2:string,accent:string,accent2:string} $colors Kolory bazowe.
 * @param string                                                   $mode   dark|light.
 * @param array{dark:array<string,int>,light:array<string,int>}    $tuning Dostrojenie.
 * @return array{bg:string,bg2:string,accent:string,accent2:string}
 */
function colorify_admin_apply_custom_tuning_colors( array $colors, string $mode, array $tuning ): array {
	$m = 'light' === $mode ? 'light' : 'dark';
	$t = $tuning[ $m ] ?? colorify_admin_custom_tuning_defaults()[ $m ];

	$bg_b  = colorify_admin_effective_tuning_delta( (int) $t['bg_brightness'] );
	$bg_s  = colorify_admin_effective_tuning_delta( (int) $t['bg_saturation'] );
	$acc_b = colorify_admin_effective_tuning_delta( (int) $t['accent_brightness'] );
	$acc_s = colorify_admin_effective_tuning_delta( (int) $t['accent_saturation'] );

	return array(
		'bg'      => colorify_admin_adjust_hex_hsl( $colors['bg'], $bg_b, $bg_s ),
		'bg2'     => colorify_admin_adjust_hex_hsl( $colors['bg2'], $bg_b, $bg_s ),
		'accent'  => colorify_admin_adjust_hex_hsl( $colors['accent'], $acc_b, $acc_s ),
		'accent2' => colorify_admin_adjust_hex_hsl( $colors['accent2'], $acc_b, $acc_s ),
	);
}

/**
 * Względna luminancja sRGB (0–1) — do sortowania teł L→P.
 */
function colorify_admin_relative_luminance( string $hex ): float {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return 0.0;
	}

	$channels = array();
	for ( $i = 0; $i < 3; $i++ ) {
		$c   = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
		$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/**
 * Sortuje klucze schematów po ciemności tła (bg_dark) — ciemniejsze pierwsze.
 *
 * @param array<int,string>              $keys   Klucze w grupie.
 * @param array<string,array<string,mixed>> $built Częściowo zbudowane definicje.
 * @return array<int,string>
 */
function colorify_admin_sort_scheme_keys_by_bg( array $keys, array $built ): array {
	$sorted = $keys;
	usort(
		$sorted,
		static function ( string $a, string $b ) use ( $built ): int {
			$la = colorify_admin_relative_luminance( (string) ( $built[ $a ]['bg_dark'] ?? '#000000' ) );
			$lb = colorify_admin_relative_luminance( (string) ( $built[ $b ]['bg_dark'] ?? '#000000' ) );
			if ( abs( $la - $lb ) < 0.00001 ) {
				return strcmp( $a, $b );
			}
			return $la < $lb ? -1 : 1;
		}
	);
	return $sorted;
}

/**
 * Grupy kolorystyczne — każda w osobnych rzędach siatki 4×N.
 *
 * @return array<string,array<int,string>>
 */
function colorify_admin_scheme_color_groups(): array {
	return array(
		'green'  => colorify_admin_green_scheme_keys(),
		'warm'   => colorify_admin_warm_scheme_keys(),
		'blue'   => array(
			'colorify-onyx', 'colorify-indigo', 'colorify-graphite', 'colorify-midnight', 'colorify-blue', 'colorify-cyan',
			'colorify-sky', 'colorify-teal', 'colorify-charcoal', 'colorify-slate', 'colorify-smoke',
		),
		'purple' => array(
			'colorify-violet', 'colorify-purple', 'colorify-fuchsia', 'colorify-rose',
		),
		'earth'  => array(
			'colorify-amber', 'colorify-stone', 'colorify-emerald',
		),
	);
}

/**
 * Delikatna drabinka czerni (akcenty bez zmian).
 *
 * @return array<int,array{0:string,1:string}>
 */
function colorify_admin_black_bg_ladder(): array {
	return array(
		array( '#0a0a0a', '#141414' ),
		array( '#0a0a0b', '#151515' ),
		array( '#0a0a0c', '#151618' ),
		array( '#0a0b0c', '#161619' ),
		array( '#0a0b0d', '#16171a' ),
		array( '#0b0b0d', '#17181b' ),
		array( '#0b0c0e', '#18191c' ),
		array( '#0b0c0f', '#181a1d' ),
		array( '#0c0d10', '#191b1e' ),
		array( '#0c0e11', '#1a1c1f' ),
		array( '#0d0e12', '#1b1d20' ),
		array( '#0d0f13', '#1c1e21' ),
	);
}

/**
 * Kolejność w siatce — grupy kolorystyczne, w rzędzie L→P ciemniejsze → jaśniejsze tło.
 *
 * @param array<string,array<string,mixed>>|null $built Opcjonalnie do sortowania po luminancji.
 * @return array<int,string>
 */
function colorify_admin_scheme_display_order( ?array $built = null ): array {
	$groups = colorify_admin_scheme_color_groups();
	$order  = array();

	foreach ( $groups as $keys ) {
		if ( null !== $built ) {
			$keys = colorify_admin_sort_scheme_keys_by_bg( $keys, $built );
		}
		$order = array_merge( $order, $keys );
	}

	return $order;
}

/**
 * Surowa pula schematów (akcenty bez zmian; tła z drabinek dark).
 *
 * @return array<string,array<int,mixed>>
 */
function colorify_admin_scheme_pool_raw(): array {
	$green = colorify_admin_green_bg_ladder();
	$warm  = colorify_admin_warm_bg_ladder();
	$black = colorify_admin_black_bg_ladder();

	return array(
		'colorify-deep'       => array( 'Głęboka zieleń', $green[0], '#4a9b84', '#6db89f' ),
		'colorify-hunter'     => array( 'Myśliwska', $green[1], '#355E3B', '#4A7C59' ),
		'colorify-marsh'      => array( 'Bagno', $green[2], '#1c4b42', '#2a5c52' ),
		'colorify-pine'       => array( 'Sosna', $green[3], '#1C4B42', '#2D6B5E' ),
		'colorify-jade'       => array( 'Jadeit', $green[4], '#10b981', '#34d399' ),
		'colorify-fern'       => array( 'Paproć', $green[5], '#3D8B37', '#5CB85C' ),
		'colorify-eucalyptus' => array( 'Eukaliptus', $green[6], '#44D7A8', '#7AE582' ),
		'colorify-forest'     => array( 'Las', $green[7], '#92C200', '#B4E717' ),
		'colorify-lime'       => array( 'Limonka', $green[8], '#B4E717', '#C8F033' ),
		'colorify-mint'       => array( 'Mięta', $green[9], '#2DD4BF', '#5EEAD4' ),
		'colorify-sage'       => array( 'Szałwia', $green[10], '#4A7C59', '#6B9E78' ),

		'colorify-seafoam'    => array( 'Rubin', $warm[0], '#e11d48', '#fb7185' ),
		'colorify-chartreuse' => array( 'Szkarłat', $warm[1], '#dc2626', '#f87171' ),
		'colorify-neon'       => array( 'Wiśnia', $warm[2], '#be123c', '#e11d48' ),
		'colorify-lime-soft'  => array( 'Koral', $warm[3], '#f97316', '#fb923c' ),
		'colorify-moss'       => array( 'Wino', $warm[4], '#9f1239', '#be185d' ),
		'colorify-olive'      => array( 'Rdza', $warm[5], '#c2410c', '#ea580c' ),
		'colorify-avocado'    => array( 'Miedź', $warm[6], '#b45309', '#d97706' ),
		'colorify-basil'      => array( 'Pomarańcza', $warm[7], '#ea580c', '#f97316' ),
		'colorify-canopy'     => array( 'Złoto', $warm[8], '#ca8a04', '#eab308' ),
		'colorify-spring'     => array( 'Brzoskwinia', $warm[9], '#fdba74', '#fcd34d' ),
		'colorify-grove'      => array( 'Czekolada', $warm[10], '#92400e', '#b45309' ),

		'colorify-onyx'     => array( 'Czerń', $black[0], '#7c3aed', '#a78bfa' ),
		'colorify-indigo'   => array( 'Indigo', $black[1], '#6366f1', '#818cf8' ),
		'colorify-graphite' => array( 'Grafit', array( '#0a0a0a', '#1c1c1f' ), '#6366f1', '#818cf8' ),
		'colorify-midnight' => array( 'Północ', array( '#0f172a', '#1e293b' ), '#06b6d4', '#22d3ee' ),
		'colorify-blue'     => array( 'Niebieski', $black[4], '#3b82f6', '#60a5fa' ),
		'colorify-cyan'     => array( 'Cyjan', $black[5], '#06b6d4', '#22d3ee' ),
		'colorify-sky'      => array( 'Błękit', $black[6], '#0ea5e9', '#38bdf8' ),
		'colorify-teal'     => array( 'Morski', $black[7], '#14b8a6', '#2dd4bf' ),
		'colorify-charcoal' => array( 'Antracyt', array( '#171717', '#262626' ), '#3b82f6', '#60a5fa' ),
		'colorify-slate'    => array( 'Łupkowy', array( '#18181b', '#27272a' ), '#0ea5e9', '#38bdf8' ),
		'colorify-smoke'    => array( 'Dymny', array( '#111827', '#1f2937' ), '#94a3b8', '#cbd5e1' ),

		'colorify-violet'  => array( 'Fiolet', $black[2], '#7c3aed', '#a78bfa' ),
		'colorify-purple'  => array( 'Purpura', $black[3], '#9333ea', '#a855f7' ),
		'colorify-fuchsia' => array( 'Fuksja', $black[8], '#d946ef', '#e879f9' ),
		'colorify-rose'    => array( 'Róż', $black[9], '#f43f5e', '#fb7185' ),

		'colorify-amber'   => array( 'Bursztyn', $black[11], '#f59e0b', '#fbbf24' ),
		'colorify-stone'   => array( 'Kamień', array( '#1c1917', '#292524' ), '#f59e0b', '#fbbf24' ),
		'colorify-emerald' => array( 'Szmaragd', $black[10], '#10b981', '#34d399' ),
	);
}

/**
 * Motywy — akcenty bez zmian; tła z drabinki; kolejność po luminancji w grupach.
 *
 * @return array<string,array<string,mixed>>
 */
function colorify_admin_scheme_definitions(): array {
	$def = static function (
		string $label,
		string $bg_dark,
		string $bg2_dark,
		string $accent,
		string $accent_soft,
		string $bg_light = '#fefefd',
		string $bg2_light = '#f7f6f3'
	): array {
		return array(
			'name'          => $label,
			'accent'        => $accent,
			'accent_soft'   => $accent_soft,
			'bg_dark'       => $bg_dark,
			'bg2_dark'      => $bg2_dark,
			'bg_light'      => $bg_light,
			'bg2_light'     => $bg2_light,
			'preview_dark'  => array( $bg_dark, $bg2_dark, $accent, $accent_soft ),
			'preview_light' => array( $bg_light, $bg2_light, $accent, $accent_soft ),
			'icon_base'     => '#8fa39b',
		);
	};

	$pool   = colorify_admin_scheme_pool_raw();
	$groups = colorify_admin_scheme_color_groups();
	$built  = array();

	foreach ( $pool as $key => $item ) {
		$bg_pair = str_starts_with( $key, 'colorify-' )
			? $item[1]
			: colorify_admin_lift_bg_pair( $item[1], 5.0 );
		$built[ $key ] = $def(
			$item[0],
			$bg_pair[0],
			$bg_pair[1],
			$item[2],
			$item[3],
			'#fefefd',
			'#f7f6f3'
		);
	}

	foreach ( $built as $key => $def ) {
		if ( ! empty( $def['custom'] ) || str_starts_with( $key, 'colorify-' ) ) {
			continue;
		}
		$ratios = colorify_admin_scheme_tint_ratios( $key );
		$tinted = colorify_admin_tint_bg_pair_with_accent(
			$def['bg_dark'],
			$def['bg2_dark'],
			$def['accent'],
			$ratios['dark'][0],
			$ratios['dark'][1]
		);
		$bg_d  = $tinted[0];
		$bg2_d = $tinted[1];
		$dim   = colorify_admin_scheme_dark_dim_amount( $key );
		if ( $dim > 0 ) {
			$bg_d  = colorify_admin_adjust_hex_hsl( $bg_d, -$dim, 0 );
			$bg2_d = colorify_admin_adjust_hex_hsl( $bg2_d, -$dim, 0 );
		}
		$built[ $key ]['bg_dark']      = $bg_d;
		$built[ $key ]['bg2_dark']     = $bg2_d;
		$built[ $key ]['preview_dark'] = array(
			$bg_d,
			$bg2_d,
			$def['accent'],
			$def['accent_soft'],
		);
	}

	foreach ( $groups as $group_keys ) {
		$sorted       = colorify_admin_sort_scheme_keys_by_bg( $group_keys, $built );
		$light_ladder = colorify_admin_light_bg_ladder( count( $sorted ) );

		foreach ( $sorted as $index => $key ) {
			if ( ! isset( $built[ $key ], $light_ladder[ $index ] ) ) {
				continue;
			}
			$ratios = colorify_admin_scheme_tint_ratios( $key );
			$tinted = colorify_admin_tint_bg_pair_with_accent(
				$light_ladder[ $index ][0],
				$light_ladder[ $index ][1],
				$built[ $key ]['accent'],
				$ratios['light'][0],
				$ratios['light'][1]
			);
			$bg_l  = $tinted[0];
			$bg2_l = $tinted[1];
			$acc_l = colorify_admin_light_preview_accents(
				$built[ $key ]['accent'],
				$built[ $key ]['accent_soft'],
				$bg_l,
				$bg2_l
			);

			$built[ $key ]['bg_light']          = $bg_l;
			$built[ $key ]['bg2_light']         = $bg2_l;
			$built[ $key ]['accent_light']      = $acc_l[0];
			$built[ $key ]['accent_soft_light']  = $acc_l[1];
			$built[ $key ]['preview_light']     = array( $bg_l, $bg2_l, $acc_l[0], $acc_l[1] );
		}
	}

	$built[ COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ] = array(
		'name'        => 'Własna paleta',
		'custom'      => true,
		'accent'      => '#B4E717',
		'accent_soft' => '#92C200',
		'icon_base'   => '#8fa39b',
	);

	$ordered = array();
	foreach ( colorify_admin_scheme_display_order( $built ) as $key ) {
		if ( isset( $built[ $key ] ) ) {
			$ordered[ $key ] = $built[ $key ];
		}
	}
	foreach ( $built as $key => $def ) {
		if ( empty( $def['custom'] ) && ! isset( $ordered[ $key ] ) ) {
			$ordered[ $key ] = $def;
		}
	}
	$ordered[ COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ] = $built[ COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ];

	return $ordered;
}

/**
 * @param string $key     Scheme key.
 * @param int    $user_id User ID.
 * @return array<string,mixed>
 */
function colorify_admin_get_resolved_scheme( string $key, int $user_id = 0 ): array {
	$key  = colorify_admin_normalize_scheme_key( $key );
	$defs = colorify_admin_scheme_definitions();

	if ( COLORIFY_ADMIN_CUSTOM_SCHEME_KEY === $key ) {
		$custom = colorify_admin_get_custom_colors( $user_id );
		$tuning = colorify_admin_get_custom_tuning( $user_id );
		$dark   = colorify_admin_apply_custom_tuning_colors( $custom, 'dark', $tuning );
		$light  = colorify_admin_apply_custom_tuning_colors( $custom, 'light', $tuning );
		$base   = $defs[ COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ];
		$acc_l  = colorify_admin_light_preview_accents(
			$light['accent'],
			$light['accent2'],
			$light['bg'],
			$light['bg2']
		);

		return array_merge(
			$base,
			array(
				'accent'            => $dark['accent'],
				'accent_soft'       => colorify_admin_brighten_text_hex( $dark['accent2'], 40.0 ),
				'bg_dark'           => $dark['bg'],
				'bg2_dark'          => $dark['bg2'],
				'bg_light'          => $light['bg'],
				'bg2_light'         => $light['bg2'],
				'accent_light'      => $acc_l[0],
				'accent_soft_light' => $acc_l[1],
				'preview_dark'      => array( $dark['bg'], $dark['bg2'], $dark['accent'], $dark['accent2'] ),
				'preview_light'     => array( $light['bg'], $light['bg2'], $acc_l[0], $acc_l[1] ),
			)
		);
	}

	return $defs[ $key ] ?? colorify_admin_get_resolved_scheme( 'colorify-lime', $user_id );
}

/**
 * Ciemniejszy kolor z pary zawsze jako tło główne (wiersze, panel).
 *
 * @return array{0:string,1:string}
 */
function colorify_admin_normalize_bg_pair( string $bg, string $bg2 ): array {
	if ( colorify_admin_relative_luminance( $bg ) > colorify_admin_relative_luminance( $bg2 ) ) {
		return array( $bg2, $bg );
	}

	return array( $bg, $bg2 );
}

/**
 * Powierzchnie UI — ciemniejszy bg na tabelach/wierszach; jaśniejszy bg2 tylko sidebar + subtelne podbicia.
 *
 * @return array{surface:string,surface2:string,field:string,field_hover:string,notice_bg:string}
 */
function colorify_admin_layout_surface_tokens( string $bg, string $bg2, string $text, string $accent, bool $is_light ): array {
	if ( $is_light ) {
		return array(
			'surface'      => $bg,
			'surface2'     => sprintf( 'color-mix(in srgb, %s 94%%, %s 6%%)', $bg2, $text ),
			'field'        => $bg2,
			'field_hover'  => sprintf( 'color-mix(in srgb, %s 90%%, %s 10%%)', $bg2, $accent ),
			'notice_bg'    => $bg,
		);
	}

	return array(
		'surface'      => $bg,
		'surface2'     => sprintf( 'color-mix(in srgb, %s 93%%, %s 7%%)', $bg, $text ),
		'field'        => sprintf( 'color-mix(in srgb, %s 90%%, %s 10%%)', $bg, $bg2 ),
		'field_hover'  => sprintf( 'color-mix(in srgb, %s 86%%, %s 14%%)', $bg, $bg2 ),
		'notice_bg'    => $bg,
	);
}

/**
 * Tokeny UI: tło wierszy = bg, pasek = 20% ciemniej, akcent tylko na linki.
 *
 * @return array<string,string>
 */
function colorify_admin_ui_semantic_tokens( string $bg, string $bg2, string $text, bool $is_light ): array {
	$mark_pct = $is_light ? '78' : '80';

	return array(
		'--colorify-admin-mark'            => sprintf( 'color-mix(in srgb, %s %s%%, #000)', $bg, $mark_pct ),
		'--colorify-admin-row-bg'          => $bg,
		'--colorify-admin-row-active-bg'   => $bg,
		'--colorify-admin-row-hover-bg'    => sprintf(
			'color-mix(in srgb, %s %s%%, %s)',
			$bg,
			$is_light ? '96' : '94',
			$text
		),
		'--colorify-admin-row-separator'   => sprintf(
			'color-mix(in srgb, %s %s%%, #000)',
			$bg,
			$is_light ? '82' : '72'
		),
		'--colorify-admin-notice-bg'       => $bg,
		'--colorify-admin-ui-hover-bg'     => sprintf(
			'color-mix(in srgb, %s %s%%, %s)',
			$bg,
			$is_light ? '92' : '90',
			$text
		),
		'--colorify-admin-ui-selected-bg'  => sprintf(
			'color-mix(in srgb, %s 88%%, %s 12%%)',
			$bg,
			$bg2
		),
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
		$border = 'rgba(24, 24, 27, 0.09)';
	} else {
		$bg     = $def['bg_dark'] ?? '#050f0c';
		$bg2    = $def['bg2_dark'] ?? '#0b231c';
		$text   = colorify_admin_brighten_text_hex( '#f4f4f5', 35.0 );
		$muted  = colorify_admin_brighten_text_hex( '#c8d4cf', 38.0 );
		$dim    = colorify_admin_brighten_text_hex( '#8fa39b', 40.0 );
		$border = 'rgba(255, 255, 255, 0.12)';
	}

	list( $bg, $bg2 ) = colorify_admin_normalize_bg_pair( $bg, $bg2 );

	$accents  = colorify_admin_resolve_accents_for_mode( $mode, $def, $bg, $bg2 );
	$accent   = $accents[0];
	$acc_soft = $accents[1];
	$link     = $accents[2];
	$link_hov = $accents[3];

	$layout = colorify_admin_layout_surface_tokens( $bg, $bg2, $text, $accent, $is_light );

	$accent_soft_alpha = $is_light ? '10' : '15';

	return array_merge(
		array(
			'--colorify-admin-bg'            => $bg,
			'--colorify-admin-sidebar'       => $bg2,
			'--colorify-admin-surface'       => $layout['surface'],
			'--colorify-admin-surface-2'     => $layout['surface2'],
			'--colorify-admin-field'         => $layout['field'],
			'--colorify-admin-field-hover'   => $layout['field_hover'],
			'--colorify-admin-border'        => $border,
			'--colorify-admin-border-subtle' => $is_light ? 'rgba(24, 24, 27, 0.06)' : 'rgba(255, 255, 255, 0.08)',
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
		colorify_admin_ui_semantic_tokens( $bg, $bg2, $text, $is_light )
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
 * @param string $hex Hex color.
 */
function colorify_admin_is_light_hex( string $hex ): bool {
	return '#050f0c' === colorify_admin_contrast_on_accent( $hex );
}

/**
 * @return array<string,string>
 */
function colorify_admin_legacy_light_map(): array {
	return array(
		'colorify-light-violet'  => 'colorify-violet',
		'colorify-light-indigo'  => 'colorify-indigo',
		'colorify-light-emerald' => 'colorify-emerald',
		'colorify-light-stone'   => 'colorify-violet',
		'vp-light-violet'        => 'colorify-violet',
		'vp-light-indigo'        => 'colorify-indigo',
		'vp-light-emerald'       => 'colorify-emerald',
		'vp-light-stone'         => 'colorify-violet',
	);
}

/**
 * @param int $user_id User ID.
 * @return string dark|light
 */
function colorify_admin_get_appearance_mode( int $user_id = 0 ): string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return 'dark';
	}

	$mode = colorify_admin_get_user_meta_value( $user_id, COLORIFY_ADMIN_APPEARANCE_META, 'dark' );
	return ( is_string( $mode ) && 'light' === $mode ) ? 'light' : 'dark';
}

/**
 * @param int    $user_id User ID.
 * @param string $mode    dark|light
 */
function colorify_admin_set_appearance_mode( int $user_id, string $mode ): void {
	$mode = 'light' === $mode ? 'light' : 'dark';
	update_user_meta( $user_id, COLORIFY_ADMIN_APPEARANCE_META, $mode );
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
 * Przełącznik ON/OFF — wyłącza styl Colorify (zostaje domyślny WP + ten pasek).
 *
 * @param bool $enabled Czy styl jest włączony.
 */
function colorify_admin_theme_switch_html( bool $enabled ): string {
	return '<div class="colorify-theme-switch colorify-toolbar-switch colorify-admin-toolbar__theme" role="group" aria-label="'
		. esc_attr( colorify_i18n( 'Colorify theme', 'Motyw Colorify' ) )
		. '">'
		. '<span class="colorify-mode-switch__label">' . esc_html( colorify_i18n( 'Off', 'Wył.' ) ) . '</span>'
		. '<label class="colorify-mode-switch__track">'
		. '<input type="checkbox" class="colorify-theme-switch__input" '
		. ( $enabled ? 'checked ' : '' )
		. 'aria-label="' . esc_attr( colorify_i18n( 'Toggle Colorify styling', 'Włącz/wyłącz styl Colorify' ) ) . '" />'
		. '<span class="colorify-mode-switch__thumb" aria-hidden="true"></span>'
		. '</label>'
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
		. '<a href="' . esc_url( $schemes_url ) . '" class="colorify-change-style-btn">'
		. colorify_admin_change_style_icon_html()
		. '<span class="colorify-change-style-btn__text">' . esc_html( colorify_i18n( 'Change style', 'Zmień styl' ) ) . '</span>'
		. '</a>'
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
		. '<label class="colorify-mode-switch__track">'
		. '<input type="checkbox" class="colorify-mode-switch__input" '
		. ( $is_light ? 'checked ' : '' )
		. 'aria-label="' . esc_attr( colorify_i18n( 'Toggle dark or light mode', 'Przełącz tryb jasny/ciemny' ) ) . '" />'
		. '<span class="colorify-mode-switch__thumb" aria-hidden="true"></span>'
		. '</label>'
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
 * @param string $key Scheme key.
 */
function colorify_admin_scheme_is_registered( string $key ): bool {
	$key = colorify_admin_normalize_scheme_key( $key );
	return isset( colorify_admin_scheme_definitions()[ $key ] );
}

/**
 * Rejestracja schematów WP — tylko ekrany edytora (nie na każdej stronie admina).
 */
function colorify_should_register_admin_color_schemes(): bool {
	if ( ! is_admin() ) {
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
 * @param string $hex Hex color.
 */
function colorify_admin_hex_to_rgb_csv( string $hex ): string {
	$hex = ltrim( $hex, '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
		return '28,75,66';
	}

	return sprintf(
		'%d,%d,%d',
		hexdec( substr( $hex, 0, 2 ) ),
		hexdec( substr( $hex, 2, 2 ) ),
		hexdec( substr( $hex, 4, 2 ) )
	);
}

/**
 * Schemat globalny (panel wtyczki) — używany na stronie logowania.
 *
 * @return array<string,mixed>
 */
function colorify_admin_get_resolved_global_scheme(): array {
	$key = colorify_get_global_admin_color();

	if ( COLORIFY_ADMIN_CUSTOM_SCHEME_KEY === $key ) {
		$custom = colorify_get_global_custom_colors();
		$tuning = colorify_get_global_custom_tuning();
		$dark   = colorify_admin_apply_custom_tuning_colors( $custom, 'dark', $tuning );
		$light  = colorify_admin_apply_custom_tuning_colors( $custom, 'light', $tuning );
		$base   = colorify_admin_scheme_definitions()[ COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ];
		$acc_l  = colorify_admin_light_preview_accents(
			$light['accent'],
			$light['accent2'],
			$light['bg'],
			$light['bg2']
		);

		return array_merge(
			$base,
			array(
				'accent'            => $dark['accent'],
				'accent_soft'       => colorify_admin_brighten_text_hex( $dark['accent2'], 40.0 ),
				'bg_dark'           => $dark['bg'],
				'bg2_dark'          => $dark['bg2'],
				'bg_light'          => $light['bg'],
				'bg2_light'         => $light['bg2'],
				'accent_light'      => $acc_l[0],
				'accent_soft_light' => $acc_l[1],
				'preview_dark'      => array( $dark['bg'], $dark['bg2'], $dark['accent'], $dark['accent2'] ),
				'preview_light'     => array( $light['bg'], $light['bg2'], $acc_l[0], $acc_l[1] ),
			)
		);
	}

	return colorify_admin_get_resolved_scheme( $key, 0 );
}

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
				'--colorify-admin-text'         => '#eef0ef',
				'--colorify-admin-text-muted'   => '#b0bdb8',
				'--colorify-admin-text-dim'     => '#8a9691',
				'--colorify-admin-icon'         => '#b0bdb8',
				'--colorify-admin-link'         => '#b0bdb8',
				'--colorify-admin-link-hover'   => '#eef0ef',
				'--colorify-admin-accent-muted' => '#b0bdb8',
				'--colorify-admin-readable-text' => '#eef0ef',
				'--colorify-admin-readable-muted' => '#b0bdb8',
				'--colorify-admin-readable-dim' => '#8a9691',
				'--colorify-admin-readable-icon' => '#b0bdb8',
			)
		);
	}

	$tokens = array_merge( $tokens, colorify_admin_third_party_palette_tokens( $tokens, $mode ) );

	$wp_theme = $tokens['--colorify-admin-bg'] ?? COLORIFY_ADMIN_BRAND_COLOR;
	$tokens['--wp-admin-theme-color']           = $wp_theme;
	$tokens['--wp-admin-theme-color--rgb']      = colorify_admin_hex_to_rgb_csv( $wp_theme );
	$tokens['--wp-admin-theme-color-darker-10'] = 'color-mix(in srgb, ' . $wp_theme . ' 90%, #000)';
	$tokens['--wp-admin-theme-color-darker-20'] = $tokens['--colorify-admin-mark'] ?? ( 'color-mix(in srgb, ' . $wp_theme . ' 80%, #000)' );

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
		'--colorify-admin-link'          => '#b0bdb8',
		'--colorify-admin-accent-muted'  => '#b0bdb8',
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
 * Radio `admin_color` ma pierwszeństwo — ukryte pole ładuje się ze starej wartości strony
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
	}

	return $meta;
}
add_filter( 'insert_user_meta', 'colorify_filter_insert_user_meta_admin_color', 10, 4 );

/**
 * Po wp_update_user() — nadpisuje ewentualny fallback WP (modern), gdy radio nie trafiło do POST.
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
 * AJAX — zapis trybu dark/light.
 */
function colorify_ajax_save_admin_appearance(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}

	$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! in_array( $mode, array( 'dark', 'light' ), true ) ) {
		wp_send_json_error( array( 'message' => 'invalid_mode' ), 400 );
	}

	$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'user'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( 'global' === $scope && current_user_can( 'manage_options' ) ) {
		colorify_set_global_appearance_mode( $mode );
	} else {
		colorify_set_user_appearance_mode( $user_id, $mode );
	}

	wp_send_json_success(
		array(
			'mode'  => $mode,
			'scope' => $scope,
		)
	);
}
add_action( 'wp_ajax_colorify_save_admin_appearance', 'colorify_ajax_save_admin_appearance' );

/**
 * AJAX — tokeny CSS dla trybu (natychmiastowy podgląd paska bez przeładowania).
 */
function colorify_ajax_get_scheme_tokens(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}

	$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! in_array( $mode, array( 'dark', 'light' ), true ) ) {
		wp_send_json_error( array( 'message' => 'invalid_mode' ), 400 );
	}

	wp_send_json_success(
		array(
			'mode'   => $mode,
			'tokens' => colorify_admin_scheme_css_tokens_for_mode( $mode, $user_id ),
		)
	);
}
add_action( 'wp_ajax_colorify_get_scheme_tokens', 'colorify_ajax_get_scheme_tokens' );

/**
 * AJAX — pełny zapis wyglądu (schemat, tryb, paleta, dostrojenie, zakres) bez przeładowania.
 */
function colorify_ajax_save_appearance_state(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}

	if ( ! isset( $_POST ) || ! is_array( $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json_error( array( 'message' => 'invalid_payload' ), 400 );
	}

	colorify_save_appearance_for_user( $user_id, wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	wp_send_json_success(
		array(
			'scope'  => colorify_get_settings_scope(),
			'mode'   => colorify_get_effective_appearance_mode( $user_id ),
			'scheme' => colorify_get_effective_admin_color( $user_id ),
		)
	);
}
add_action( 'wp_ajax_colorify_save_appearance_state', 'colorify_ajax_save_appearance_state' );

/**
 * AJAX — włącz/wyłącz styl Colorify (tylko pasek przełączników zostaje przy OFF).
 */
function colorify_ajax_toggle_user_theme(): void {
	check_ajax_referer( 'colorify-admin-appearance', 'nonce' );

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error( array( 'message' => 'not_logged_in' ), 403 );
	}

	$enabled = isset( $_POST['enabled'] ) ? (int) wp_unslash( $_POST['enabled'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( ! in_array( $enabled, array( 0, 1 ), true ) ) {
		wp_send_json_error( array( 'message' => 'invalid_state' ), 400 );
	}

	colorify_set_user_theme_enabled( $user_id, (bool) $enabled );

	wp_send_json_success( array( 'enabled' => (bool) $enabled ) );
}
add_action( 'wp_ajax_colorify_toggle_user_theme', 'colorify_ajax_toggle_user_theme' );

/**
 * AJAX — zapis schematu po kliknięciu (zastępuje core handler z błędnym kluczem meta).
 */
function colorify_ajax_save_user_color_scheme(): void {
	global $_wp_admin_css_colors;

	check_ajax_referer( 'save-color-scheme', 'nonce' );

	$color_scheme = isset( $_POST['color_scheme'] ) ? sanitize_key( wp_unslash( $_POST['color_scheme'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( '' === $color_scheme || ! isset( $_wp_admin_css_colors[ $color_scheme ] ) ) {
		wp_send_json_error();
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		wp_send_json_error();
	}

	if ( colorify_saves_appearance_to_global( $user_id ) ) {
		$previous_color_scheme = colorify_get_global_admin_color();
		colorify_persist_global_admin_color( $color_scheme );
	} else {
		$previous_color_scheme = get_user_option( 'admin_color', $user_id );
		colorify_persist_user_admin_color( $user_id, $color_scheme );
	}

	wp_send_json_success(
		array(
			'previousScheme' => 'admin-color-' . $previous_color_scheme,
			'currentScheme'  => 'admin-color-' . $color_scheme,
		)
	);
}

add_action( 'admin_init', 'colorify_replace_core_color_scheme_ajax', 100 );

/**
 * Podmiana wp_ajax_save_user_color_scheme — core zapisuje do nieprefiksowanego meta bez sync.
 */
function colorify_replace_core_color_scheme_ajax(): void {
	remove_action( 'wp_ajax_save-user-color-scheme', 'wp_ajax_save_user_color_scheme' );
	add_action( 'wp_ajax_save-user-color-scheme', 'colorify_ajax_save_user_color_scheme' );
}
