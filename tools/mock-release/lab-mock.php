<?php
/**
 * Plugin Name: Colorify lab mock (mu-plugin, tylko laboratorium)
 *
 * Przekierowuje zapytania o GitHuba na atrape uruchomiona na hoscie (host.docker.internal:8765).
 * Nie trafia do paczki wtyczki (tools/ jest wykluczone z ZIP).
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$mock = 'http://host.docker.internal:8765';

		// Kontener laboratorium nie ma dostepu do api.wordpress.org. Rdzen wywoluje filtry
		// update_plugins_{host} dopiero po poprawnej odpowiedzi tego API, wiec zwracamy pusta, poprawna.
		if ( preg_match( '#^https?://api\.wordpress\.org/plugins/update-check/#', $url ) ) {
			return array(
				'headers'  => array(),
				'body'     => '{"plugins":[],"translations":[],"no_update":[]}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		if ( preg_match( '#^https://api\.github\.com/repos/([^/]+/[^/]+)/releases/latest$#', $url, $m ) ) {
			$target = $mock . '/repos/' . $m[1] . '/releases/latest';
		} elseif ( preg_match( '#^https://github\.com/[^/]+/[^/]+/releases/download/([^/]+)/([^/?]+)$#', $url, $m ) ) {
			$target = $mock . '/download/' . $m[1] . '/' . $m[2];
		} else {
			return $pre;
		}

		$args['reject_unsafe_urls'] = false;
		return wp_remote_get( $target, $args );
	},
	10,
	3
);

// Dopuszczenie hosta atrapy dla wp_safe_remote_get (download_url).
add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) {
		return 'host.docker.internal' === $host ? true : $external;
	},
	10,
	2
);
