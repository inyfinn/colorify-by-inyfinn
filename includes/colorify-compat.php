<?php
/**
 * Polyfille PHP (wtyczka deklaruje Requires PHP 7.4).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'str_starts_with' ) ) {
	/**
	 * @param string $haystack Haystack.
	 * @param string $needle   Needle.
	 */
	function str_starts_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}

		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}
