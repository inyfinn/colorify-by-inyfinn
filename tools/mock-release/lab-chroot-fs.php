<?php
/**
 * Plugin Name: Colorify lab: system plikow "chroot" (mu-plugin, tylko laboratorium)
 *
 * Udaje FTP/SSH z punktu widzenia WordPressa: sciezki "zdalne" ($wp_filesystem) sa inne niz lokalne
 * (zdalna = lokalna bez /var/www/html, jak katalog domowy konta FTP). Wszystkie operacje na plikach
 * w upgraderze ida przez te klase, wiec ewentualne uzycie sciezki lokalnej w $wp_filesystem konczy sie
 * "plik nie istnieje", tak jak na prawdziwym FTP. Tylko zadania WWW (nie WP-CLI). Nie trafia do paczki.
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	return;
}

// FS_METHOD bywa juz zdefiniowane w wp-config.php (lab: direct), wiec metode podmieniamy filtrem.
foreach ( array(
	'FTP_HOST' => 'lab.invalid',
	'FTP_USER' => 'lab',
	'FTP_PASS' => 'lab',
) as $colorify_const => $colorify_value ) {
	if ( ! defined( $colorify_const ) ) {
		define( $colorify_const, $colorify_value );
	}
}
unset( $colorify_const, $colorify_value );

add_filter(
	'filesystem_method',
	static function () {
		return 'chroot';
	}
);

add_filter(
	'filesystem_method_file',
	static function ( $file, $method ) {
		return 'chroot' === $method ? __DIR__ . '/lab-chroot-fs-class.php' : $file;
	},
	10,
	2
);
