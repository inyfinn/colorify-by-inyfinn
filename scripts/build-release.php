<?php
/**
 * Buduje colorify-by-inyfinn.zip z poprawnymi ścieżkami (forward slash + folder główny).
 *
 * Uruchom z katalogu wtyczki:
 *   php scripts/build-release.php
 *
 * @package ColorifyByInyfinn
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "Brak rozszerzenia ZipArchive w PHP.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
$plugin_slug = 'colorify-by-inyfinn';
$dist_dir    = $plugin_root . '/dist';
$zip_path    = $dist_dir . '/' . $plugin_slug . '.zip';

$exclude_dirs = array( '.git', 'dist', 'scripts', 'node_modules', '.idea', '.vscode' );
$exclude_files = array( '.gitignore', '.DS_Store' );

if ( ! is_dir( $dist_dir ) && ! mkdir( $dist_dir, 0755, true ) && ! is_dir( $dist_dir ) ) {
	fwrite( STDERR, "Nie można utworzyć katalogu dist/.\n" );
	exit( 1 );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Nie można utworzyć zip: {$zip_path}\n" );
	exit( 1 );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_root, FilesystemIterator::SKIP_DOTS )
);

$added = 0;

foreach ( $iterator as $file ) {
	/** @var SplFileInfo $file */
	if ( ! $file->isFile() ) {
		continue;
	}

	$absolute = $file->getPathname();
	$relative = substr( $absolute, strlen( $plugin_root ) + 1 );
	$relative = str_replace( '\\', '/', $relative );

	$parts = explode( '/', $relative );
	if ( in_array( $parts[0], $exclude_dirs, true ) ) {
		continue;
	}

	$basename = basename( $relative );
	if ( in_array( $basename, $exclude_files, true ) ) {
		continue;
	}

	if ( preg_match( '/\.zip$/i', $basename ) ) {
		continue;
	}

	$zip_name = $plugin_slug . '/' . $relative;

	if ( ! $zip->addFile( $absolute, $zip_name ) ) {
		fwrite( STDERR, "Nie dodano pliku: {$zip_name}\n" );
		continue;
	}

	++$added;
}

$zip->close();

echo "OK: {$zip_path} ({$added} plików)\n";

// Weryfikacja: wszystkie ścieżki w zipie muszą używać / i zaczynać się od slug/.
$verify = new ZipArchive();
if ( true !== $verify->open( $zip_path ) ) {
	fwrite( STDERR, "Weryfikacja zip nieudana.\n" );
	exit( 1 );
}

for ( $i = 0; $i < $verify->numFiles; $i++ ) {
	$name = $verify->getNameIndex( $i );
	if ( false === strpos( $name, '/' ) || 0 !== strpos( $name, $plugin_slug . '/' ) ) {
		fwrite( STDERR, "Zła ścieżka w zip: {$name}\n" );
		exit( 1 );
	}
	if ( false !== strpos( $name, '\\' ) || false !== strpos( $name, '%5C' ) ) {
		fwrite( STDERR, "Backslash w zip: {$name}\n" );
		exit( 1 );
	}
}

$verify->close();
echo "Weryfikacja ścieżek: OK\n";
