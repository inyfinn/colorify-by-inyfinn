<?php
/**
 * Strona logowania: branding i zasoby.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

function colorify_branding_login_brand_html(): string {
	$icon_url  = colorify_branding_site_icon_url( 512 );
	$is_fav    = colorify_branding_uses_site_favicon();
	$img_class = 'colorify-login-brand__icon-img' . ( $is_fav ? '' : ' colorify-login-brand__icon-img--fallback' );

	return '<span class="colorify-login-brand">'
		. '<span class="colorify-login-brand__icon">'
		. '<img src="' . esc_url( $icon_url ) . '" alt="" class="' . esc_attr( $img_class ) . '" width="32" height="32" decoding="async" />'
		. '</span>'
		. '<span class="colorify-login-brand__wordmark">' . esc_html( colorify_branding_site_name() ) . '</span>'
		. '</span>';
}

function colorify_branding_login_assets(): void {
	wp_enqueue_style(
		'colorify-branding-fonts',
		'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'colorify-branding',
		COLORIFY_PLUGIN_URL . 'assets/colorify-branding.css',
		array( 'colorify-branding-fonts', 'login' ),
		COLORIFY_PLUGIN_VERSION
	);
}
add_action( 'login_enqueue_scripts', 'colorify_branding_login_assets' );

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
		'<p class="colorify-login-credits">%s · <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
		esc_html( colorify_branding_style_name() ),
		esc_url( COLORIFY_CREDITS_URL ),
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
