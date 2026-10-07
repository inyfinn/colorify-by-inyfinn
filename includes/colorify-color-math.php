<?php
/**
 * Matematyka koloru: luminancja, kontrast WCAG, OKLCH. Czyste funkcje, bez WordPressa.
 *
 * Specyfikacja: docs/TOKENS.md, rozdział 2.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

// Progi kontrastu: AA = minimum WCAG, AA_SAFE = cel przy doborze, STRONG = tekst "mocny".
defined( 'COLORIFY_CM_AA' ) || define( 'COLORIFY_CM_AA', 4.5 );
defined( 'COLORIFY_CM_AA_SAFE' ) || define( 'COLORIFY_CM_AA_SAFE', 4.6 );
defined( 'COLORIFY_CM_STRONG' ) || define( 'COLORIFY_CM_STRONG', 7.0 );

/**
 * Rozbija poprawny #rrggbb na kanały 0..255. Wejście ma być po colorify_cm_normalize().
 *
 * @param string $hex Kolor #rrggbb.
 * @return int[] Tablica r, g, b.
 */
function colorify_cm_to_rgb( string $hex ): array {
	return array(
		(int) hexdec( substr( $hex, 1, 2 ) ),
		(int) hexdec( substr( $hex, 3, 2 ) ),
		(int) hexdec( substr( $hex, 5, 2 ) ),
	);
}

/**
 * Składa #rrggbb z kanałów 0..255 (przycina i zaokrągla).
 *
 * @param float $r Czerwony.
 * @param float $g Zielony.
 * @param float $b Niebieski.
 */
function colorify_cm_from_rgb( float $r, float $g, float $b ): string {
	return sprintf(
		'#%02x%02x%02x',
		(int) round( min( 255, max( 0, $r ) ) ),
		(int) round( min( 255, max( 0, $g ) ) ),
		(int) round( min( 255, max( 0, $b ) ) )
	);
}

/**
 * Kanał sRGB 0..255 na liniowy 0..1 (tablica podręczna: 256 wartości).
 *
 * @param int $v Kanał 0..255.
 */
function colorify_cm_channel_to_linear( int $v ): float {
	static $lut = null;
	if ( null === $lut ) {
		$lut = array();
		for ( $i = 0; $i < 256; $i++ ) {
			$c         = $i / 255;
			$lut[ $i ] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
	}
	return $lut[ $v ];
}

/**
 * Liniowy kanał 0..1 na sRGB 0..255 (nieprzycięty, zmiennoprzecinkowy).
 *
 * @param float $v Kanał liniowy.
 */
function colorify_cm_linear_to_channel( float $v ): float {
	$v = min( 1.0, max( 0.0, $v ) );
	$s = $v <= 0.0031308 ? 12.92 * $v : 1.055 * pow( $v, 1 / 2.4 ) - 0.055;
	return $s * 255;
}

/**
 * Przyjmuje #rgb, #rrggbb (także wielkie litery), zwraca #rrggbb. Błędne wejście daje $fallback
 * (też znormalizowany; gdy i on jest błędny: #000000).
 *
 * @param string $hex      Kolor od użytkownika.
 * @param string $fallback Zapasowy kolor.
 */
function colorify_cm_normalize( string $hex, string $fallback ): string {
	$hex = strtolower( trim( $hex ) );
	if ( preg_match( '/^#([0-9a-f]{3})$/', $hex, $m ) ) {
		return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}
	if ( preg_match( '/^#[0-9a-f]{6}$/', $hex ) ) {
		return $hex;
	}
	$fallback = strtolower( trim( $fallback ) );
	if ( preg_match( '/^#([0-9a-f]{3})$/', $fallback, $m ) ) {
		return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}
	return preg_match( '/^#[0-9a-f]{6}$/', $fallback ) ? $fallback : '#000000';
}

/**
 * Luminancja względna WCAG 2.1.
 *
 * @param string $hex Kolor #rrggbb.
 */
function colorify_cm_luminance( string $hex ): float {
	list( $r, $g, $b ) = colorify_cm_to_rgb( $hex );
	return 0.2126 * colorify_cm_channel_to_linear( $r )
		+ 0.7152 * colorify_cm_channel_to_linear( $g )
		+ 0.0722 * colorify_cm_channel_to_linear( $b );
}

/**
 * Kontrast WCAG: (Lmax + 0.05) / (Lmin + 0.05).
 *
 * @param string $a Kolor #rrggbb.
 * @param string $b Kolor #rrggbb.
 */
function colorify_cm_contrast( string $a, string $b ): float {
	$la = colorify_cm_luminance( $a );
	$lb = colorify_cm_luminance( $b );
	return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
}

/**
 * Kolor #rrggbb na OKLCH (Ottosson): L 0..1, C, h w stopniach 0..360. Dla szarości h = 0.
 *
 * @param string $hex Kolor #rrggbb.
 * @return float[] Tablica L, C, h.
 */
function colorify_cm_to_oklch( string $hex ): array {
	list( $r8, $g8, $b8 ) = colorify_cm_to_rgb( $hex );
	$r = colorify_cm_channel_to_linear( $r8 );
	$g = colorify_cm_channel_to_linear( $g8 );
	$b = colorify_cm_channel_to_linear( $b8 );

	$l = pow( max( 0.0, 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b ), 1 / 3 );
	$m = pow( max( 0.0, 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b ), 1 / 3 );
	$s = pow( max( 0.0, 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b ), 1 / 3 );

	$ol = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
	$oa = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
	$ob = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

	$c = sqrt( $oa * $oa + $ob * $ob );
	$h = $c < 0.0001 ? 0.0 : rad2deg( atan2( $ob, $oa ) );
	if ( $h < 0 ) {
		$h += 360;
	}
	return array( $ol, $c, $h );
}

/**
 * OKLCH na liniowe sRGB (bez przycinania).
 *
 * @param float $l Jasność.
 * @param float $c Chroma.
 * @param float $h Odcień w stopniach.
 * @return float[] Tablica r, g, b (liniowe).
 */
function colorify_cm_oklch_to_linear( float $l, float $c, float $h ): array {
	$a = $c * cos( deg2rad( $h ) );
	$b = $c * sin( deg2rad( $h ) );

	$l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
	$m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
	$s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;

	$l3 = $l_ * $l_ * $l_;
	$m3 = $m_ * $m_ * $m_;
	$s3 = $s_ * $s_ * $s_;

	return array(
		4.0767416621 * $l3 - 3.3077115913 * $m3 + 0.2309699292 * $s3,
		-1.2684380046 * $l3 + 2.6097574011 * $m3 - 0.3413193965 * $s3,
		-0.0041960863 * $l3 - 0.7034186147 * $m3 + 1.7076147010 * $s3,
	);
}

/**
 * Czy liniowe sRGB mieści się w gamucie (z tolerancją 0.0001).
 *
 * @param float[] $lin Tablica r, g, b.
 */
function colorify_cm_in_gamut( array $lin ): bool {
	foreach ( $lin as $v ) {
		if ( $v < -0.0001 || $v > 1.0001 ) {
			return false;
		}
	}
	return true;
}

/**
 * OKLCH na #rrggbb. Poza gamutem zmniejsza chromę wyszukiwaniem binarnym (24 kroki).
 *
 * @param float $l Jasność 0..1 (przycinana).
 * @param float $c Chroma.
 * @param float $h Odcień w stopniach.
 */
function colorify_cm_from_oklch( float $l, float $c, float $h ): string {
	$l   = min( 1.0, max( 0.0, $l ) );
	$c   = max( 0.0, $c );
	if ( $l <= 0.0 ) {
		return '#000000';
	}
	if ( $l >= 1.0 ) {
		return '#ffffff';
	}
	$lin =colorify_cm_oklch_to_linear( $l, $c, $h );

	if ( ! colorify_cm_in_gamut( $lin ) ) {
		$lo = 0.0;
		$hi = $c;
		for ( $i = 0; $i < 24; $i++ ) {
			$mid = ( $lo + $hi ) / 2;
			if ( colorify_cm_in_gamut( colorify_cm_oklch_to_linear( $l, $mid, $h ) ) ) {
				$lo = $mid;
			} else {
				$hi = $mid;
			}
		}
		$lin = colorify_cm_oklch_to_linear( $l, $lo, $h );
	}

	return colorify_cm_from_rgb(
		colorify_cm_linear_to_channel( $lin[0] ),
		colorify_cm_linear_to_channel( $lin[1] ),
		colorify_cm_linear_to_channel( $lin[2] )
	);
}

/**
 * Ten sam odcień i chroma, nowa jasność L (przycięta do 0..1).
 *
 * @param string $hex Kolor #rrggbb.
 * @param float  $l   Nowa jasność.
 */
function colorify_cm_with_l( string $hex, float $l ): string {
	$lch = colorify_cm_to_oklch( $hex );
	return colorify_cm_from_oklch( $l, $lch[1], $lch[2] );
}

/**
 * Przesuwa jasność L o $delta.
 *
 * @param string $hex   Kolor #rrggbb.
 * @param float  $delta Zmiana L.
 */
function colorify_cm_shift_l( string $hex, float $delta ): string {
	$lch = colorify_cm_to_oklch( $hex );
	return colorify_cm_from_oklch( $lch[0] + $delta, $lch[1], $lch[2] );
}

/**
 * Interpolacja liniowa kanałów sRGB (0 = $a, 1 = $b).
 *
 * @param string $a Kolor #rrggbb.
 * @param string $b Kolor #rrggbb.
 * @param float  $t Udział $b.
 */
function colorify_cm_mix( string $a, string $b, float $t ): string {
	$ca = colorify_cm_to_rgb( $a );
	$cb = colorify_cm_to_rgb( $b );
	return colorify_cm_from_rgb(
		$ca[0] + ( $cb[0] - $ca[0] ) * $t,
		$ca[1] + ( $cb[1] - $ca[1] ) * $t,
		$ca[2] + ( $cb[2] - $ca[2] ) * $t
	);
}

/**
 * Zmienia jasność L krokami w kierunku $dir, aż $ok( kolor ) da true. Po dojściu do 0 lub 1:
 * #000000 albo #ffffff. Kolor wyjściowy jest zawsze zaokrąglony do #rrggbb przed sprawdzeniem
 * warunku, więc zaokrąglenie nie może zepsuć kontrastu.
 *
 * @param string   $hex  Kolor startowy #rrggbb.
 * @param callable $ok   Warunek: function( string $hex ): bool.
 * @param int      $dir  -1 ciemniej, +1 jaśniej.
 * @param float    $step Krok L.
 */
function colorify_cm_seek( string $hex, callable $ok, int $dir, float $step = 0.004 ): string {
	if ( $ok( $hex ) ) {
		return $hex;
	}

	$dir = $dir < 0 ? -1 : 1;
	$lch = colorify_cm_to_oklch( $hex );

	for ( $i = 1; $i <= 1000; $i++ ) {
		$l = $lch[0] + $dir * $i * $step;
		if ( $l <= 0 || $l >= 1 ) {
			break;
		}
		$candidate = colorify_cm_from_oklch( $l, $lch[1], $lch[2] );
		if ( $ok( $candidate ) ) {
			return $candidate;
		}
	}

	return $dir < 0 ? '#000000' : '#ffffff';
}

/**
 * Wybiera kandydata o wyższym kontraście z tłem; gdy najlepszy ma poniżej 4.5, czerń albo biel
 * (ta o wyższym kontraście; jedna z nich ma zawsze co najmniej 4.58).
 *
 * @param string $bg    Tło #rrggbb.
 * @param string $dark  Ciemny kandydat.
 * @param string $light Jasny kandydat.
 */
function colorify_cm_on( string $bg, string $dark, string $light ): string {
	$cd   = colorify_cm_contrast( $dark, $bg );
	$cl   = colorify_cm_contrast( $light, $bg );
	$best = $cd >= $cl ? $dark : $light;

	if ( max( $cd, $cl ) >= COLORIFY_CM_AA ) {
		return $best;
	}

	return colorify_cm_contrast( '#000000', $bg ) >= colorify_cm_contrast( '#ffffff', $bg ) ? '#000000' : '#ffffff';
}

/**
 * Kolor jako "r, g, b" (do zmiennych --rgb).
 *
 * @param string $hex Kolor #rrggbb.
 */
function colorify_cm_rgb_csv( string $hex ): string {
	return implode( ', ', colorify_cm_to_rgb( $hex ) );
}
