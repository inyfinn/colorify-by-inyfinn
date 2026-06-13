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
		colorify_repair_flattened_plugin_dir_native( $plugin_dir );
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

	colorify_repair_flattened_plugin_dir_native( $plugin_dir );
}

/**
 * Naprawa spłaszczonego katalogu — natywne PHP (działa na dyskach sieciowych Y:, RaiDrive).
 *
 * @param string $plugin_dir Katalog wtyczki.
 */
function colorify_repair_flattened_plugin_dir_native( string $plugin_dir ): void {
	$plugin_dir = trailingslashit( $plugin_dir );
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
 * Rekurencyjne kopiowanie katalogu (natywne PHP, bez WP_Filesystem).
 *
 * @param string $source Źródło.
 * @param string $dest   Cel.
 * @return true|WP_Error
 */
function colorify_recursive_copy_native( string $source, string $dest ) {
	$source = trailingslashit( wp_normalize_path( $source ) );
	$dest   = trailingslashit( wp_normalize_path( $dest ) );

	if ( ! is_dir( $source ) ) {
		return new WP_Error(
			'colorify_copy_source',
			__( 'Brak katalogu źródłowego paczki Colorify.', 'colorify-by-inyfinn' )
		);
	}

	if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
		return new WP_Error(
			'colorify_copy_dest',
			__( 'Nie można zapisać plików Colorify w katalogu wtyczki.', 'colorify-by-inyfinn' )
		);
	}

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
	} catch ( Exception $e ) {
		return new WP_Error(
			'colorify_copy_iter',
			$e->getMessage()
		);
	}

	foreach ( $iterator as $item ) {
		$relative = substr( wp_normalize_path( $item->getPathname() ), strlen( $source ) );
		$target   = $dest . $relative;

		if ( $item->isDir() ) {
			if ( ! is_dir( $target ) && ! wp_mkdir_p( $target ) ) {
				return new WP_Error(
					'colorify_copy_mkdir',
					sprintf(
						/* translators: %s: directory path */
						__( 'Nie można utworzyć katalogu: %s', 'colorify-by-inyfinn' ),
						$relative
					)
				);
			}
			continue;
		}

		$target_dir = dirname( $target );
		if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error(
				'colorify_copy_mkdir',
				sprintf(
					/* translators: %s: directory path */
					__( 'Nie można utworzyć katalogu: %s', 'colorify-by-inyfinn' ),
					dirname( $relative )
				)
			);
		}

		if ( ! copy( $item->getPathname(), $target ) ) {
			return new WP_Error(
				'colorify_copy_file',
				sprintf(
					/* translators: %s: file path */
					__( 'Nie można skopiować pliku: %s', 'colorify-by-inyfinn' ),
					$relative
				)
			);
		}
	}

	return true;
}

/**
 * Znajduje katalog główny wtyczki w rozpakowanym archiwum.
 *
 * @param string $extract_root Katalog po rozpakowaniu zipa.
 * @param string $plugin_slug  Slug wtyczki.
 * @return string|WP_Error
 */
function colorify_find_extracted_plugin_root( string $extract_root, string $plugin_slug ) {
	$extract_root = trailingslashit( wp_normalize_path( $extract_root ) );
	$candidates   = array(
		$extract_root . $plugin_slug,
		$extract_root,
	);

	foreach ( $candidates as $dir ) {
		if ( is_readable( $dir . '/colorify-by-inyfinn.php' ) ) {
			return trailingslashit( $dir );
		}
	}

	$entries = scandir( $extract_root );
	if ( is_array( $entries ) ) {
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$subdir = $extract_root . $entry;
			if ( is_dir( $subdir ) && is_readable( $subdir . '/colorify-by-inyfinn.php' ) ) {
				return trailingslashit( $subdir );
			}
		}
	}

	return new WP_Error(
		'colorify_no_plugin_root',
		__( 'Paczka ZIP nie zawiera pliku colorify-by-inyfinn.php w oczekiwanej strukturze.', 'colorify-by-inyfinn' )
	);
}

/**
 * Pobiera plik z URL (GitHub asset) do pliku tymczasowego.
 *
 * @param string $url URL pobierania.
 * @return string|WP_Error Ścieżka do pliku tymczasowego.
 */
function colorify_download_release_asset( string $url ) {
	if ( '' === $url ) {
		return new WP_Error( 'colorify_empty_url', __( 'Brak adresu pobierania paczki.', 'colorify-by-inyfinn' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';

	$tmp = download_url( $url, 300 );
	if ( ! is_wp_error( $tmp ) && is_readable( $tmp ) && filesize( $tmp ) > 100 ) {
		return $tmp;
	}

	if ( is_wp_error( $tmp ) ) {
		$download_error = $tmp;
	} else {
		wp_delete_file( $tmp );
		$download_error = new WP_Error( 'colorify_empty_download', __( 'Pobrany plik jest pusty.', 'colorify-by-inyfinn' ) );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 300,
			'redirection' => 5,
			'headers'     => array(
				'Accept' => 'application/octet-stream',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $download_error;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error(
			'colorify_http_download',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'GitHub zwrócił kod HTTP %d podczas pobierania paczki.', 'colorify-by-inyfinn' ),
				$code
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( '' === $body || strlen( $body ) < 100 ) {
		return $download_error;
	}

	$fallback = wp_tempnam( 'colorify-github-' );
	if ( ! $fallback || false === file_put_contents( $fallback, $body ) ) {
		return new WP_Error(
			'colorify_save_download',
			__( 'Nie można zapisać pobranej paczki na dysku.', 'colorify-by-inyfinn' )
		);
	}

	return $fallback;
}

/**
 * Instalacja aktualizacji: pobierz ZIP → rozpakuj → skopiuj natywnie do katalogu wtyczki.
 *
 * Omija Plugin_Upgrader / WP_Filesystem (problem na dyskach sieciowych).
 *
 * @param string $download_url URL zipa z GitHub Releases.
 * @param string $plugin_dir   Katalog docelowy wtyczki.
 * @param string $plugin_slug  Slug katalogu.
 * @return true|WP_Error
 */
function colorify_direct_install_from_download_url(
	string $download_url,
	string $plugin_dir,
	string $plugin_slug = 'colorify-by-inyfinn'
) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error(
			'colorify_no_zip',
			__( 'Serwer nie ma rozszerzenia ZipArchive — skontaktuj się z hostingiem.', 'colorify-by-inyfinn' )
		);
	}

	$zip_path = colorify_download_release_asset( $download_url );
	if ( is_wp_error( $zip_path ) ) {
		return $zip_path;
	}

	$normalized = colorify_normalize_plugin_zip( $zip_path, $plugin_slug );
	if ( is_wp_error( $normalized ) ) {
		wp_delete_file( $zip_path );
		return $normalized;
	}

	$extract_root = trailingslashit( get_temp_dir() ) . 'colorify-extract-' . wp_generate_password( 8, false );
	wp_mkdir_p( $extract_root );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $normalized ) ) {
		colorify_delete_dir_native( $extract_root );
		if ( $normalized !== $zip_path ) {
			wp_delete_file( $normalized );
		}
		wp_delete_file( $zip_path );
		return new WP_Error(
			'colorify_zip_open',
			__( 'Nie można otworzyć pobranej paczki Colorify.', 'colorify-by-inyfinn' )
		);
	}

	if ( ! $zip->extractTo( $extract_root ) ) {
		$zip->close();
		colorify_delete_dir_native( $extract_root );
		if ( $normalized !== $zip_path ) {
			wp_delete_file( $normalized );
		}
		wp_delete_file( $zip_path );
		return new WP_Error(
			'colorify_zip_extract',
			__( 'Nie można rozpakować paczki Colorify.', 'colorify-by-inyfinn' )
		);
	}
	$zip->close();

	if ( $normalized !== $zip_path ) {
		wp_delete_file( $normalized );
	}
	wp_delete_file( $zip_path );

	$source_root = colorify_find_extracted_plugin_root( $extract_root, $plugin_slug );
	if ( is_wp_error( $source_root ) ) {
		colorify_delete_dir_native( $extract_root );
		return $source_root;
	}

	$copied = colorify_recursive_copy_native( $source_root, $plugin_dir );
	colorify_delete_dir_native( $extract_root );

	if ( is_wp_error( $copied ) ) {
		return $copied;
	}

	colorify_repair_flattened_plugin_dir_native( $plugin_dir );

	return true;
}

/**
 * Usuwa katalog rekurencyjnie (natywne PHP).
 *
 * @param string $dir Ścieżka katalogu.
 */
function colorify_delete_dir_native( string $dir ): void {
	$dir = wp_normalize_path( $dir );
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$entries = scandir( $dir );
	if ( ! is_array( $entries ) ) {
		return;
	}

	foreach ( $entries as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			colorify_delete_dir_native( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
