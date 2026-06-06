<?php
/**
 * Lokalny build release — ten sam format co GitHub Actions (git archive).
 *
 * Oficjalne release: tag v* → workflow .github/workflows/release.yml
 * Format WP: colorify-by-inyfinn/colorify-by-inyfinn.php
 *
 *   php scripts/build-release.php
 *
 * @package ColorifyByInyfinn
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}

$plugin_root = dirname( __DIR__ );
$plugin_slug = 'colorify-by-inyfinn';
$dist_dir    = $plugin_root . '/dist';
$zip_path    = $dist_dir . '/' . $plugin_slug . '.zip';

if ( ! is_dir( $dist_dir ) && ! mkdir( $dist_dir, 0755, true ) && ! is_dir( $dist_dir ) ) {
	fwrite( STDERR, "Nie można utworzyć katalogu dist/.\n" );
	exit( 1 );
}

$cmd = sprintf(
	'git -C %s archive --format=zip --prefix=%s/ HEAD -o %s',
	escapeshellarg( $plugin_root ),
	$plugin_slug,
	escapeshellarg( $zip_path )
);

passthru( $cmd, $exit_code );

if ( 0 !== $exit_code ) {
	fwrite( STDERR, "git archive nie powiodło się (kod {$exit_code}).\n" );
	exit( 1 );
}

echo "OK: {$zip_path}\n";
