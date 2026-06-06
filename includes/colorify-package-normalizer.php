<?php
/**
 * Normalizacja paczki ZIP przed rozpakowaniem przez WordPress (Plugin_Upgrader → unzip_file).
 *
 * WordPress nie zamienia backslashy w nazwach wpisów ZIP — to musi być poprawione
 * po pobraniu, zanim core wywoła unzip_file() (class-wp-upgrader.php).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ścieżka wpisu ZIP → format wymagany przez Plugin_Upgrader: slug/plik.
 *
 * @param string $name        Nazwa wpisu w archiwum.
 * @param string $plugin_slug Slug katalogu wtyczki.
 */
function colorify_normalize_zip_entry_path( string $name, string $plugin_slug ): string {
	$name = str_replace( array( '\\', '%5C', '%5c' ), '/', $name );
	$name = preg_replace( '#^\./#', '', $name );
	$name = ltrim( $name, '/' );

	if ( str_starts_with( $name, $plugin_slug . '/' ) ) {
		return $name;
	}

	return $plugin_slug . '/' . $name;
}

/**
 * Czy archiwum wymaga przebudowy (backslash, brak folderu slug/).
 *
 * @param ZipArchive $zip         Otwarty archiwum.
 * @param string     $plugin_slug Slug wtyczki.
 */
function colorify_zip_needs_normalization( ZipArchive $zip, string $plugin_slug ): bool {
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$raw = $zip->getNameIndex( $i );
		if ( ! is_string( $raw ) || '' === $raw ) {
			continue;
		}

		if ( false !== strpos( $raw, '\\' ) || false !== stripos( $raw, '%5C' ) ) {
			return true;
		}

		$norm = colorify_normalize_zip_entry_path( $raw, $plugin_slug );
		if ( $norm !== $raw ) {
			return true;
		}
	}

	return false;
}

/**
 * Po pobraniu: buduje paczkę zgodną z WordPress (forward slash, jeden folder slug/).
 *
 * @param string $zip_path    Plik zip (np. z download_url).
 * @param string $plugin_slug Slug wtyczki.
 * @return string|WP_Error Ścieżka do zip gotowego dla unzip_file().
 */
function colorify_normalize_plugin_zip( string $zip_path, string $plugin_slug = 'colorify-by-inyfinn' ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return $zip_path;
	}

	$source = new ZipArchive();
	if ( true !== $source->open( $zip_path ) ) {
		return new WP_Error(
			'colorify_zip_open',
			__( 'Nie można otworzyć pobranej paczki Colorify.', 'colorify-by-inyfinn' )
		);
	}

	if ( ! colorify_zip_needs_normalization( $source, $plugin_slug ) ) {
		$source->close();
		return $zip_path;
	}

	$fixed = wp_tempnam( 'colorify-package-' );
	if ( ! $fixed ) {
		$source->close();
		return new WP_Error(
			'colorify_zip_temp',
			__( 'Brak pliku tymczasowego podczas przygotowania paczki Colorify.', 'colorify-by-inyfinn' )
		);
	}

	$out = new ZipArchive();
	if ( true !== $out->open( $fixed, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		$source->close();
		wp_delete_file( $fixed );
		return new WP_Error(
			'colorify_zip_create',
			__( 'Nie można utworzyć poprawionej paczki Colorify.', 'colorify-by-inyfinn' )
		);
	}

	for ( $i = 0; $i < $source->numFiles; $i++ ) {
		$raw = $source->getNameIndex( $i );
		if ( ! is_string( $raw ) || '' === $raw ) {
			continue;
		}

		$norm = colorify_normalize_zip_entry_path( $raw, $plugin_slug );
		if ( str_ends_with( $norm, '/' ) ) {
			continue;
		}

		$data = $source->getFromIndex( $i );
		if ( false === $data ) {
			continue;
		}

		$out->addFromString( $norm, $data );
	}

	$source->close();
	$out->close();

	wp_delete_file( $zip_path );

	return $fixed;
}

/**
 * Awaryjna naprawa przed require — tylko gdy brakuje includes/ (inaczej fatal).
 */
function colorify_emergency_repair_plugin_root(): void {
	if ( is_readable( COLORIFY_PLUGIN_DIR . 'includes/colorify-scope.php' ) ) {
		return;
	}

	$plugin_dir = trailingslashit( COLORIFY_PLUGIN_DIR );
	$entries    = scandir( $plugin_dir );
	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}

		$full = $plugin_dir . $entry;
		if ( is_dir( $full ) ) {
			continue;
		}

		$check = str_replace( array( '\\', '%5C', '%5c' ), '/', rawurldecode( $entry ) );
		if ( ! preg_match( '#^(assets|includes|languages)/(.+)$#', $check, $matches ) ) {
			continue;
		}

		$target_dir = $plugin_dir . $matches[1];
		if ( ! is_dir( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
		}

		$target = $target_dir . '/' . $matches[2];
		if ( file_exists( $target ) ) {
			wp_delete_file( $full );
		} elseif ( @rename( $full, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			continue;
		} elseif ( copy( $full, $target ) ) {
			wp_delete_file( $full );
		}
	}
}

/**
 * Naprawa katalogu wtyczki po złym rozpakowaniu (pliki assets%5C… w root).
 *
 * @param string              $plugin_dir   Katalog wtyczki.
 * @param WP_Filesystem_Base  $wp_filesystem System plików WP.
 */
function colorify_repair_flattened_plugin_dir( string $plugin_dir, $wp_filesystem ): void {
	$plugin_dir = trailingslashit( $plugin_dir );
	$list       = $wp_filesystem->dirlist( $plugin_dir, false, false );

	if ( ! is_array( $list ) ) {
		return;
	}

	foreach ( array_keys( $list ) as $name ) {
		$decoded = rawurldecode( $name );
		$check   = str_replace( array( '\\', '%5C', '%5c' ), '/', $decoded );

		if ( ! preg_match( '#^(assets|includes|languages)/(.+)$#', $check, $matches ) ) {
			continue;
		}

		$subdir = $plugin_dir . $matches[1];
		if ( ! $wp_filesystem->is_dir( $subdir ) ) {
			$wp_filesystem->mkdir( $subdir, FS_CHMOD_DIR );
		}

		$from = $plugin_dir . $name;
		$to   = $subdir . '/' . $matches[2];

		if ( $wp_filesystem->exists( $to ) ) {
			$wp_filesystem->delete( $from );
		} else {
			$wp_filesystem->move( $from, $to, true );
		}
	}
}
