<?php
/**
 * Schematy kolorów: definicje, własna paleta, dostrojenie, pomocnicze funkcje koloru.
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
		'colorify-jungle' => 'colorify-kancelaria',
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
 * Klucze schematów ciepłych (dawne zielone sloty - rubin, rdza, złoto itd.).
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
 * Surowa drabinka ciepłych teł - rubin → czekolada (ciemne → jaśniejsze).
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
 * Tekst czytelny na tle koloru - WCAG AA (domyślnie 4.5:1).
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
 * Dodatkowe przyciemnienie ciemnego tła (HSL L −15) - Purpura, Fuksja, Dymny itd.
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
 * Współczynniki szczypnięcia akcentem - dark + light per schemat.
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
 * @param float $h 0-360.
 * @param float $s 0-100.
 * @param float $l 0-100.
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
 * Względna luminancja sRGB (0-1) - do sortowania teł L→P.
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
 * Sortuje klucze schematów po ciemności tła (bg_dark) - ciemniejsze pierwsze.
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
 * Grupy kolorystyczne - każda w osobnych rzędach siatki 4×N.
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
			COLORIFY_DEFAULT_SCHEME, 'colorify-amber', 'colorify-stone', 'colorify-emerald',
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
 * Kolejność w siatce - grupy kolorystyczne, w rzędzie L→P ciemniejsze → jaśniejsze tło.
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
		// Domyślny schemat (COLORIFY_DEFAULT_SCHEME): wygląd strony kancelarii. Opcjonalne [4], [5] = bg_light, bg2_light.
		'colorify-kancelaria' => array( 'Grafit i złoto', array( '#1a1a1a', '#242424' ), '#b1976b', '#d8c195', '#ffffff', '#f5f5f5' ),

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
 * Motywy - akcenty bez zmian; tła z drabinki; kolejność po luminancji w grupach.
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
			$item[4] ?? '#fefefd',
			$item[5] ?? '#f7f6f3'
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
		// Schematy z własnymi wartościami jasnymi (pool [4], [5]) nie biorą udziału w drabince, żeby nie przesuwać cudzych.
		$sorted       = colorify_admin_sort_scheme_keys_by_bg( $group_keys, $built );
		$own_light    = array_values( array_filter( $sorted, static function ( $k ) use ( $pool ) { return isset( $pool[ $k ][5] ); } ) );
		$sorted       = array_values( array_diff( $sorted, $own_light ) );
		$light_ladder = colorify_admin_light_bg_ladder( count( $sorted ) );
		foreach ( $own_light as $key ) {
			$sorted[]                    = $key;
			$light_ladder[ count( $sorted ) - 1 ] = array( $pool[ $key ][4], $pool[ $key ][5] );
		}

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
			$bg_l  = $pool[ $key ][4] ?? $tinted[0];
			$bg2_l = $pool[ $key ][5] ?? $tinted[1];
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
		// Paleta osoby tylko, gdy ma własny wygląd; inaczej paleta witryny. Wcześniej brało puste meta osoby,
		// czyli domyślną limonkę, zamiast kolorów zapisanych dla witryny ("Dopasuj do strony" dawało zieleń).
		$custom = function_exists( 'colorify_get_effective_custom_colors' ) ? colorify_get_effective_custom_colors( $user_id ) : colorify_admin_get_custom_colors( $user_id );
		$tuning = function_exists( 'colorify_get_effective_custom_tuning' ) ? colorify_get_effective_custom_tuning( $user_id ) : colorify_admin_get_custom_tuning( $user_id );
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

	return $defs[ $key ] ?? colorify_admin_get_resolved_scheme( COLORIFY_DEFAULT_SCHEME, $user_id );
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
 * @param string $key Scheme key.
 */
function colorify_admin_scheme_is_registered( string $key ): bool {
	$key = colorify_admin_normalize_scheme_key( $key );
	return isset( colorify_admin_scheme_definitions()[ $key ] );
}

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
 * Schemat globalny (panel wtyczki) - używany na stronie logowania.
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
