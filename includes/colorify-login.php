<?php
/**
 * Strona logowania: branding i zasoby.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Logo strony na górze logowania: filtr `colorify_login_logo` (wtyczka strony podaje adres obrazu, osobny dla trybu
 * ciemnego i jasnego), a gdy go nie ma, logo z Wygląd > Personalizuj > Tożsamość witryny (custom_logo). '' = brak logo.
 *
 * @param string $mode 'dark' albo 'light' (tryb strony logowania).
 */
function colorify_branding_login_logo_url( string $mode ): string {
	$url = '';
	$id  = (int) get_theme_mod( 'custom_logo' );
	if ( $id > 0 ) {
		$src = wp_get_attachment_image_url( $id, 'full' );
		$url = is_string( $src ) ? $src : '';
	}
	/**
	 * Logo strony na ekranie logowania.
	 *
	 * @param string $url  Adres obrazu albo ''.
	 * @param string $mode 'dark' albo 'light'.
	 */
	$url = apply_filters( 'colorify_login_logo', $url, $mode );
	return is_string( $url ) ? $url : '';
}

function colorify_branding_login_brand_html(): string {
	$logo = colorify_branding_login_logo_url( colorify_get_global_appearance_mode() );
	if ( '' !== $logo ) {
		// Marka strony to jej logo (obraz), bez znaku INYFINN: ten stoi na dole, w stopce.
		return '<span class="colorify-login-brand colorify-login-brand--logo">'
			. '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( colorify_branding_site_name() ) . '" class="colorify-login-brand__logo" decoding="async" />'
			. '</span>';
	}

	if ( colorify_branding_uses_site_favicon() ) {
		$icon = '<img src="' . esc_url( colorify_branding_site_icon_url( 512 ) ) . '" alt="" class="colorify-login-brand__icon-img" width="40" height="40" decoding="async" />';
	} else {
		// Bez logo i bez ikony witryny: znak INYFINN rysuje arkusz (maska), więc pasuje do każdego schematu.
		$icon = '<span class="colorify-login-brand__mark" aria-hidden="true"></span>';
	}

	return '<span class="colorify-login-brand">'
		. '<span class="colorify-login-brand__icon">' . $icon . '</span>'
		. '<span class="colorify-login-brand__wordmark">' . esc_html( colorify_branding_site_name() ) . '</span>'
		. '</span>';
}

/**
 * Arkusz logowania i tokeny schematu globalnego (zawsze ustawienia globalne, nie konto, bo nikt nie jest zalogowany).
 * Bez Dark Readera i bez żądań poza serwer: czcionki EB Garamond i IBM Plex Sans leżą w assets/fonts/.
 */
function colorify_branding_login_assets(): void {
	wp_enqueue_style(
		'colorify-login',
		COLORIFY_PLUGIN_URL . 'assets/colorify-login.css',
		array( 'login' ),
		COLORIFY_PLUGIN_VERSION
	);

	$tokens = colorify_login_tokens();
	wp_add_inline_style( 'colorify-login', ':root{' . colorify_engine_declarations( $tokens['css'] ) . '}' . colorify_skin_css() );
}
add_action( 'login_enqueue_scripts', 'colorify_branding_login_assets' );

/**
 * Tryb (ciemny albo jasny) na <html>: arkusz bierze z niego tło strony.
 *
 * @param string $output Atrybuty języka.
 */
function colorify_branding_login_html_attr( $output ): string {
	$output = is_string( $output ) ? $output : '';

	if ( ! colorify_is_login_request() ) {
		return $output;
	}

	return trim( $output . ' data-colorify-login="' . esc_attr( colorify_get_global_appearance_mode() ) . '"' );
}
add_filter( 'language_attributes', 'colorify_branding_login_html_attr' );

add_filter(
	'login_headertext',
	static function (): string {
		return colorify_branding_login_brand_html();
	}
);

add_filter(
	'login_headerurl',
	static function (): string {
		return colorify_branding_front_url();
	}
);

function colorify_branding_login_credits(): void {
	printf(
		'<div class="colorify-login-credits"><a class="colorify-login-credits__logo" href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s"><span class="colorify-login-credits__mark" aria-hidden="true"></span></a>'
		. '<p>%3$s · <a href="%1$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></div>',
		esc_url( COLORIFY_CREDITS_URL ),
		esc_attr( COLORIFY_CREDITS ),
		esc_html( colorify_branding_style_name() ),
		esc_html( COLORIFY_CREDITS )
	);
}
add_action( 'login_footer', 'colorify_branding_login_credits', 20 );

function colorify_branding_login_message( string $message ): string {
	if ( '' !== $message ) {
		return $message;
	}

	return sprintf(
		'<p class="colorify-login-tagline">Panel CMS · %s</p>',
		esc_html( colorify_branding_site_name() )
	);
}
add_filter( 'login_message', 'colorify_branding_login_message' );

add_filter(
	'login_site_html_link',
	static function ( string $link ): string {
		$url = esc_url( colorify_branding_front_url() );
		return sprintf(
			'<a href="%s">← %s</a>',
			$url,
			esc_html( colorify_branding_site_name() )
		);
	}
);
