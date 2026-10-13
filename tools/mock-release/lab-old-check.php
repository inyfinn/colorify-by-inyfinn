<?php
/**
 * Plugin Name: Colorify lab: link starego sprawdzania (mu-plugin, tylko laboratorium)
 *
 * Kod 1.4.9 ma obsluge ?colorify_check_updates=1 z nonce, ale nigdzie nie wystawia przycisku.
 * Ten plik wypisuje taki link w stopce panelu (id lab-old-check), zeby test mogl go kliknac.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_footer',
	static function () {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$url = add_query_arg(
			array(
				'colorify_check_updates' => '1',
				'_wpnonce'               => wp_create_nonce( 'colorify_check_updates' ),
			),
			admin_url( 'plugins.php' )
		);
		echo '<a id="lab-old-check" href="' . esc_url( $url ) . '" style="position:fixed;left:0;bottom:0;z-index:99999;font-size:11px">lab old check</a>';
	}
);
