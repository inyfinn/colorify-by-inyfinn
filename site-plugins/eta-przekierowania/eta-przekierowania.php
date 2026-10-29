<?php
/**
 * Plugin Name: ETA - przekierowania 301
 * Description: Stare adresy (poprzednia strona, linki z Google i z treści) prowadzą na właściwe strony zamiast na stronę 404. Wtyczka strony etainnovations.com, przechowywana w repozytorium Colorify (site-plugins/eta-przekierowania).
 * Version: 1.0.1
 * Author: INYFINN
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Działa tylko, gdy WordPress nie znalazł strony (is_404), przed motywem, który pokazuje wtedy stronę "Page 404".
 * Źródło listy: logi serwera z października 2026 (adresy, pod które wchodzili ludzie, a dostawali 404).
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'ETA_PRZEKIEROWANIA' ) ) {
	return;
}
define( 'ETA_PRZEKIEROWANIA', '1.0.1' );

add_action(
	'template_redirect',
	static function () {
		if ( ! is_404() ) {
			return;
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = strtolower( rawurldecode( $path ) );
		$path = '/' . trim( $path, '/' );
		$to   = eta_redirect_target( $path );
		if ( '' !== $to ) {
			wp_safe_redirect( $to, 301, 'ETA redirects' );
			exit;
		}
	},
	0
);

/**
 * Docelowy adres dla starego adresu albo '' (zostaje 404).
 *
 * @param string $path Ścieżka bez końcowego ukośnika, małymi literami, zdekodowana.
 */
function eta_redirect_target( string $path ): string {
	$exact = array(
		'/kontakt.html' => '/contact/',
		'/kontakt'      => '/contact/',
		'/o-firmie'     => '/o-nas/',
		'/zespol'       => '/o-nas/',
		'/our-company'  => '/o-nas/',
		'/about'        => '/o-nas/',
		'/about-us'     => '/o-nas/',
		'/blog'         => '/publikacje/',
		'/news-updates' => '/publikacje/',
		'/oferta'       => '/sklep/',
	);
	if ( isset( $exact[ $path ] ) ) {
		return home_url( $exact[ $path ] );
	}

	// Stary blog: /post/<tytuł z polskimi znakami> -> wpis o tym samym tytule bez polskich znaków.
	if ( preg_match( '#^/post/([^/]+)$#', $path, $m ) ) {
		$post = get_page_by_path( sanitize_title( $m[1] ), OBJECT, 'post' );
		return $post ? (string) get_permalink( $post ) : home_url( '/publikacje/' );
	}

	// Stare kategorie: /category/<x> -> kategoria produktów o tym samym slugu.
	if ( preg_match( '#^/(?:category|product-category)/([^/]+)$#', $path, $m ) ) {
		$term = get_term_by( 'slug', sanitize_title( $m[1] ), 'product_cat' );
		return $term ? (string) get_term_link( $term ) : home_url( '/sklep/' );
	}

	// Stare hashtagi bloga: /news-updates/hashtags/<tag> -> wyszukiwanie tego słowa.
	if ( preg_match( '#^/news-updates/hashtags/([^/]+)$#', $path, $m ) ) {
		return add_query_arg( 's', rawurlencode( $m[1] ), home_url( '/' ) );
	}

	// Produkty z dema motywu i usunięte produkty -> sklep.
	if ( preg_match( '#^/product/[^/]+$#', $path ) ) {
		return home_url( '/sklep/' );
	}

	return '';
}
