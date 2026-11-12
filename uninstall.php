<?php
/**
 * Odinstalowanie Colorify (Wtyczki → Usuń): dane analityki (tabele {prefix}colorify_*, opcje colorify_an_*,
 * cache zapytań, zadanie cron, pliki dziennika odsłon w uploads/colorify-log). Ustawienia wyglądu, treści
 * i opcja starego licznika (colorify_visits) zostają, jak dotąd.
 *
 * @package ColorifyByInyfinn
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/colorify-analytics.php';

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $colorify_site ) {
		switch_to_blog( (int) $colorify_site );
		Colorify_Analytics::uninstall();
		restore_current_blog();
	}
} else {
	Colorify_Analytics::uninstall();
}
