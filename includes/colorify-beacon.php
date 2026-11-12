<?php
/**
 * Dziennik odsłon Colorify: odbiornik krótkiego sygnału ze stron, które serwują cache całych stron (wtedy PHP
 * nie widzi każdego wejścia). Działa BEZ WordPressa: jedna linia na odsłonę w uploads/colorify-log/log-RRRRMMDDGG.php
 * (plik PHP z exit na początku + .htaccess deny all: z zewnątrz nic nie widać). Linia: czas zaokrąglony do 5 minut,
 * typ (v odsłona, e 404, b bot), klucz strony, domena odsyłająca, nazwa parametru kliknięcia (bez wartości), UTM,
 * klasa urządzenia. Bez IP, bez pełnego UA, bez identyfikatora. Każde pole przechodzi przez listę dozwolonych znaków.
 * Działa tylko, gdy WordPress utworzył uploads/colorify-log/on.php (tryb dziennika). Agreguje cron:
 * Colorify_Analytics::aggregate_log(). Opis: docs/kokpit-analityka.md.
 *
 * @package ColorifyByInyfinn
 */

header( 'Cache-Control: no-store' );
header( 'X-Robots-Tag: noindex, nofollow' );

( static function () {
	$dir = dirname( __DIR__, 3 ) . '/uploads/colorify-log';
	if ( ! is_file( $dir . '/on.php' ) ) {
		http_response_code( 204 );
		return;
	}
	require_once __DIR__ . '/colorify-analytics-rules.php';

	// phpcs:disable WordPress.Security -- poza WordPressem; każde pole niżej ma własną listę dozwolonych znaków.
	$get    = static function ( string $k, int $max ): string {
		$v = $_GET[ $k ] ?? '';
		return is_string( $v ) ? substr( $v, 0, $max ) : '';
	};
	$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
	$ua     = substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 512 );
	$ts     = (int) ( floor( time() / 300 ) * 300 );
	$line   = '';
	if ( 'HEAD' === $method || Colorify_Analytics_Rules::is_bot_ua( $ua ) ) {
		$line = $ts . "\tb";
	} elseif ( 'GET' === $method || 'POST' === $method ) {
		$type = $get( 't', 1 );
		$key  = $get( 'k', 400 );
		if ( ( 'v' === $type || 'e' === $type ) && preg_match( '#^(\d{1,20}|s|/[A-Za-z0-9/%._~!$&\'()*+,;=:@-]{0,399})$#', $key ) ) {
			$ref = strtolower( $get( 'r', 100 ) );
			$ref = preg_match( '~^[a-z0-9._-]{1,100}$~', $ref ) ? $ref : '';
			$clk = $get( 'x', 10 );
			$clk = isset( Colorify_Analytics_Rules::CLICK_IDS[ $clk ] ) ? $clk : '';
			$line = implode(
				"\t",
				array(
					$ts,
					$type,
					$key,
					$ref,
					$clk,
					Colorify_Analytics_Rules::utm_value( $get( 'us', 60 ) ),
					Colorify_Analytics_Rules::utm_value( $get( 'um', 60 ) ),
					Colorify_Analytics_Rules::utm_value( $get( 'uc', 60 ) ),
					Colorify_Analytics_Rules::device( $ua ),
				)
			);
		}
	}
	if ( '' !== $line ) {
		$file = $dir . '/log-' . gmdate( 'YmdH', $ts ) . '.php';
		// Limit 20 MB na godzinę (ok. 200 tys. odsłon): zalanie odbiornika nie zapełni dysku.
		if ( ! is_file( $file ) || filesize( $file ) < 20000000 ) {
			$fh = @fopen( $file, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
			if ( $fh ) {
				flock( $fh, LOCK_EX );
				$st = fstat( $fh );
				if ( 0 === (int) $st['size'] ) {
					fwrite( $fh, "<?php exit; ?>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				fwrite( $fh, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				fflush( $fh );
				flock( $fh, LOCK_UN );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}
	http_response_code( 204 );
} )();
