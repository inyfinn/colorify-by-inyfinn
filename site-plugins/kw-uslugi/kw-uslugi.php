<?php
/**
 * Plugin Name:       Kancelaria Wieczorek: Usługi, cennik i formularz
 * Description:       Usługi z cenami w jednym miejscu (menu "Usługi"): cennik z zakładkami [kw_cennik], formularz kontaktowy [kw_formularz] z kopią wiadomości w panelu i trybem awaryjnym oraz starsze shortcody [kw_uslugi] i [kw_uslugi_lista].
 * Version:           2.0.4
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Inyfinn
 * License:           GPL-2.0-or-later
 * Text Domain:       kw-uslugi
 *
 * @package kw-uslugi
 */

defined( 'ABSPATH' ) || exit;

define( 'KW_USLUGI_WERSJA', '2.0.4' );
define( 'KW_USLUGI_PLIK', __FILE__ );
define( 'KW_USLUGI_KATALOG', plugin_dir_path( __FILE__ ) );

require_once KW_USLUGI_KATALOG . 'includes/dane.php';
require_once KW_USLUGI_KATALOG . 'includes/widok.php';
require_once KW_USLUGI_KATALOG . 'includes/kontakt.php';

if ( is_admin() ) {
	require_once KW_USLUGI_KATALOG . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'kw_uslugi_aktywacja' );
register_deactivation_hook( __FILE__, 'kw_uslugi_dezaktywacja' );
