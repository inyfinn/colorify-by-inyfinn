<?php
/**
 * Wspólne funkcje brandingu: nazwa witryny, ikona, stopka panelu.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nazwa witryny z WordPressa.
 */
function colorify_branding_site_name(): string {
	$name = get_bloginfo( 'name', 'display' );
	return ( is_string( $name ) && '' !== $name ) ? $name : COLORIFY_BRANDING_NAME;
}

function colorify_branding_style_name(): string {
	return COLORIFY_BRANDING_NAME;
}

function colorify_branding_site_icon_url( int $size = 512 ): string {
	$icon = get_site_icon_url( $size );
	if ( is_string( $icon ) && '' !== $icon ) {
		return $icon;
	}
	return COLORIFY_PLUGIN_URL . 'assets/inyfinn-logo-okrag.svg';
}

function colorify_branding_uses_site_favicon(): bool {
	$icon = get_site_icon_url( 512 );
	return is_string( $icon ) && '' !== $icon;
}

function colorify_branding_front_url(): string {
	if ( defined( 'WP_HOME' ) && WP_HOME ) {
		return trailingslashit( WP_HOME ) . 'pl';
	}
	return home_url( '/pl' );
}

add_action(
	'admin_head',
	static function (): void {
		if ( ! is_admin() || ! colorify_is_user_theme_enabled() ) {
			return;
		}
		$url = esc_url( colorify_branding_site_icon_url( 512 ) );
		printf(
			'<style id="colorify-admin-bar-site-icon">#wpadminbar #wp-admin-bar-site-name > .ab-item::before{content:""!important;display:inline-block!important;width:18px!important;height:18px!important;margin:7px 6px 0 0!important;padding:0!important;background:url("%s") center/contain no-repeat!important;opacity:.92;float:left!important;}</style>',
			$url // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	},
	100
);

add_filter(
	'admin_footer_text',
	static function ( $text ): string {
		if ( ! colorify_is_user_theme_enabled() ) {
			return is_string( $text ) ? $text : '';
		}
		return sprintf(
			'<span class="colorify-admin-footer-brand"><a class="colorify-admin-footer-credits" href="%s" target="_blank" rel="noopener noreferrer">%s</a> · %s CMS · <strong>Colorify</strong></span>',
			esc_url( COLORIFY_CREDITS_URL ),
			esc_html( COLORIFY_CREDITS ),
			esc_html( colorify_branding_site_name() )
		);
	}
);

/** Prawy dolny róg: copyright INYFINN + nazwa witryny + wersja Colorify (zamiast WP core). */
add_filter(
	'update_footer',
	static function ( $text ): string {
		if ( ! colorify_is_user_theme_enabled() ) {
			return is_string( $text ) ? $text : '';
		}
		return sprintf(
			'<span class="colorify-admin-footer-right">'
			. '<a class="colorify-admin-footer-credits" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>'
			. ' · <span class="colorify-admin-footer-version">Colorify v%3$s</span>'
			. '</span>',
			esc_url( COLORIFY_CREDITS_URL ),
			esc_html( COLORIFY_CREDITS ),
			esc_html( COLORIFY_PLUGIN_VERSION )
		);
	},
	20
);
