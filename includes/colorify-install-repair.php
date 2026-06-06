<?php
/**
 * Naprawa instalacji po złym zipie (backslash w ścieżkach → pliki assets%5C… na Linuxie).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Czy nazwa pliku wygląda na spłaszczoną ścieżkę z Windows zip.
 *
 * @param string $filename Nazwa pliku w katalogu wtyczki.
 */
function colorify_is_flattened_zip_entry( string $filename ): bool {
	if ( '' === $filename || false !== strpos( $filename, '/' ) ) {
		return false;
	}

	return null !== colorify_parse_flattened_zip_entry( $filename );
}

/**
 * Rozbija spłaszczoną nazwę na [folder, basename].
 *
 * @param string $filename Nazwa pliku.
 * @return array{0:string,1:string}|null
 */
function colorify_parse_flattened_zip_entry( string $filename ): ?array {
	$decoded = rawurldecode( $filename );

	foreach ( array( $filename, $decoded ) as $candidate ) {
		if ( preg_match( '#^(assets|includes|languages)(%5C|%5c|\\\\|\\\)(.+)$#', $candidate, $matches ) ) {
			return array( $matches[1], $matches[2] );
		}
	}

	return null;
}

/**
 * Przenosi plik (rename z fallbackiem copy+delete).
 *
 * @param string $from Source.
 * @param string $to   Destination.
 */
function colorify_move_file( string $from, string $to ): bool {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( @rename( $from, $to ) ) {
		return true;
	}

	if ( ! copy( $from, $to ) ) {
		return false;
	}

	wp_delete_file( $from );

	return true;
}

/**
 * Przenosi pliki typu assets%5Cfoo.css → assets/foo.css (przy każdym ładowaniu, dopóki są śmieci).
 */
function colorify_repair_broken_zip_paths(): void {
	static $done = false;

	if ( $done || ! defined( 'COLORIFY_PLUGIN_DIR' ) ) {
		return;
	}

	$done = true;

	$plugin_dir = trailingslashit( COLORIFY_PLUGIN_DIR );
	$repaired   = 0;

	$entries = scandir( $plugin_dir );
	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$full_path = $plugin_dir . $entry;

		if ( is_dir( $full_path ) ) {
			continue;
		}

		$parsed = colorify_parse_flattened_zip_entry( $entry );
		if ( null === $parsed ) {
			continue;
		}

		list( $folder, $basename ) = $parsed;
		$target_dir                = $plugin_dir . $folder;

		if ( ! wp_mkdir_p( $target_dir ) ) {
			continue;
		}

		$target_path = $target_dir . '/' . $basename;

		if ( file_exists( $target_path ) ) {
			wp_delete_file( $full_path );
		} elseif ( colorify_move_file( $full_path, $target_path ) ) {
			++$repaired;
		}
	}

	if ( $repaired > 0 ) {
		update_option( 'colorify_install_repair_count', (int) get_option( 'colorify_install_repair_count', 0 ) + $repaired, false );
	}
}
