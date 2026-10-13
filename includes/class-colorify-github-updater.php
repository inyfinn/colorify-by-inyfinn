<?php
/**
 * Aktualizacje Colorify z GitHub Releases.
 *
 * Mechanizm WordPressa 5.8+: nagłówek `Update URI` wskazujący github.com
 * + filtr `update_plugins_github.com`. WordPress sam pyta o nowe wydanie przy swoim
 * zwykłym sprawdzaniu aktualizacji i sam instaluje paczkę natywnym "Zaktualizuj teraz".
 * Ta klasa niczego nie instaluje i nie wykonuje zapytań HTTP poza filtrem aktualizacji
 * i `plugins_api`.
 *
 * Paczką jest wyłącznie asset release (colorify-by-inyfinn.zip), nigdy zipball.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wykrywanie wydania na GitHubie, cache, sprzątanie po wersji 1.4.9.
 */
final class Colorify_Updater {

	const SLUG         = 'colorify-by-inyfinn';
	const CACHE_KEY    = 'colorify_github_latest';
	const VERSION_OPT  = 'colorify_version';
	const UPGRADED_OPT = 'colorify_upgraded_at';
	const CHECK_ACTION = 'colorify_check_updates';

	// Cache krótszy niż 12 h rdzenia: inaczej cykliczne sprawdzenie WordPressa
	// trafiałoby w jeszcze ważny cache i oferta spóźniałaby się o kolejne 12 h.
	const CACHE_OK_TTL   = 39600;
	const CACHE_FAIL_TTL = 900;
	const GOOD_KEY       = 'colorify_github_latest_good';
	const GOOD_TTL       = 604800;

	const REPO_REGEX  = '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#';
	const TAG_REGEX   = '/^v?(\d+\.\d+\.\d+(?:[-.][0-9A-Za-z.]+)?)$/';
	const ASSET_REGEX = '/^colorify-by-inyfinn(-[0-9][0-9A-Za-z.\-]*)?\.zip$/';

	/**
	 * Pliki wersji 1.4.9, których nie ma w paczce 2.0 (ścieżki względem katalogu wtyczki).
	 * Zamknięta lista: instalacja ręczną ścieżką starego kodu kopiuje pliki na istniejący
	 * katalog i niczego nie czyści, a natywna aktualizacja WordPressa czyści katalog sama.
	 * tools/build-zip.mjs porównuje tę listę z plikami wszystkich tagów v1.* i przerywa budowę, gdy
	 * plik z tamtych wydań nie jest ani w paczce, ani tutaj.
	 */
	const ORPHANS = array(
		'assets/colorify-admin-appearance.js',
		'assets/colorify-admin-colors.css',
		'assets/colorify-admin-compat.js',
		'assets/colorify-admin-global-readable.css',
		'assets/colorify-admin-overrides.css',
		'assets/colorify-admin-plugins.css',
		'assets/colorify-admin-toolbar.css',
		'assets/colorify-admin-toolbar.js',
		'assets/colorify-admin-wc-layout.css',
		'assets/colorify-admin-wp-structure.css',
		'assets/colorify-classic-editor-dark.css',
		'assets/colorify-customizer.css',
		'assets/colorify-global-darkmode.css',
		'assets/colorify-settings.css',
		'assets/colorify-switch.css',
		'assets/colorify-table-readable.css',
		'includes/colorify-admin-schemes.php',
		'includes/colorify-compat.php',
		'includes/colorify-package-normalizer.php',
		// Starsze wydania (1.0.x-1.4.0): pliki z tagow v1.* oraz katalog .ralph, który trafił do ZIP-a 1.4.0.
		// Usunięte z 2.0 po 1.4.9 (stary wygląd wtyczki i przycisk, zastąpione nowym interfejsem).
		'assets/colorify-branding.css',
		'assets/colorify-sparkle-button.css',
		'includes/colorify-sparkle-button.php',
		'languages/colorify-by-inyfinn-pl_PL.po',
		// Nazwy z 1.x, pod którymi 2.0 nie ma już plików (updater i ekran ustawień mają nowe nazwy: stary plik główny
		// ładuje do końca stary, kompletny kod).
		'includes/class-colorify-updater.php',
		'includes/class-colorify-settings.php',
		'assets/colorify-admin-media.css',
		'includes/colorify-install-repair.php',
		'.ralph/projects/colorify-wp-admin-global/prd.md',
		'.ralph/projects/colorify-wp-admin-global/tasks.json',
	);

	/**
	 * Katalogi po sierotach, od najgłębszego. Kasowane tylko puste (rmdir bez rekurencji).
	 */
	const ORPHAN_DIRS = array(
		'.ralph/projects/colorify-wp-admin-global',
		'.ralph/projects',
		'.ralph',
		'languages',
	);

	/**
	 * Rejestruje hooki.
	 *
	 * @param bool $light true: tylko oferta aktualizacji i ochrona instalacji (bez sprzątania po starych wersjach,
	 *                    bez znacznika wersji, bez linku i komunikatów). Tak ładuje się updater, gdy reszta wtyczki
	 *                    jest zablokowana (zbyt stare środowisko, niekompletne pliki): znacznik wersji zdjąłby
	 *                    wtedy kontrolę kompletności plików.
	 */
	public static function init( $light = false ) {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 10, 3 );

		// Każde skasowanie transientu rdzenia (wp_clean_plugins_cache, wp_clean_update_cache,
		// `wp plugin list --force-check`) unieważnia też nasz cache.
		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'purge_cache' ) );
		// "Sprawdź ponownie" na ekranie Aktualizacje: update-core.php?force-check=1.
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_purge_on_force_check' ), 5 );

		// Ochrona przed źle zbudowaną paczką: zły katalog główny albo brak pliku głównego.
		// WP_Error z tych filtrów zatrzymuje instalację, a po instalacji rdzeń przywraca poprzednią wersję.
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verify_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'verify_source' ), 10, 4 );
		add_filter( 'upgrader_post_install', array( __CLASS__, 'verify_post_install' ), 10, 3 );

		if ( $light ) {
			return;
		}

		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'late_legacy_cleanup' ), 2 );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_check_notice' ) );
	}

	/**
	 * Repozytorium (owner/repo) ze stałej COLORIFY_GITHUB_REPO.
	 *
	 * @return string Pusty, gdy stała jest niepoprawna.
	 */
	public static function repo() {
		$repo = ( defined( 'COLORIFY_GITHUB_REPO' ) && is_string( COLORIFY_GITHUB_REPO ) ) ? trim( COLORIFY_GITHUB_REPO ) : '';

		return preg_match( self::REPO_REGEX, $repo ) ? $repo : '';
	}

	/**
	 * Filtr update_plugins_github.com (wołany przez wp_update_plugins() dla każdej wtyczki
	 * z Update URI na github.com).
	 *
	 * @param array|false $update      Dotychczasowa odpowiedź.
	 * @param array       $plugin_data Dane z nagłówka wtyczki.
	 * @param string      $plugin_file Plik wtyczki względem katalogu wtyczek.
	 * @param string[]    $locales     Nieużywane.
	 * @return array|false
	 */
	public static function filter_update( $update, $plugin_data, $plugin_file, $locales = array() ) {
		unset( $locales );

		if ( plugin_basename( COLORIFY_PLUGIN_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( null === $release ) {
			return $update;
		}

		$plugin_data = is_array( $plugin_data ) ? $plugin_data : array();

		// requires i requires_php z NOWEGO wydania; bez linii w treści release bierzemy
		// wartości z nagłówka zainstalowanej wtyczki.
		$requires     = '' !== $release['requires'] ? $release['requires'] : ( isset( $plugin_data['RequiresWP'] ) ? (string) $plugin_data['RequiresWP'] : '' );
		$requires_php = '' !== $release['requires_php'] ? $release['requires_php'] : ( isset( $plugin_data['RequiresPHP'] ) ? (string) $plugin_data['RequiresPHP'] : '' );

		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			// Dodatkowe pole: rdzeń zachowuje je w transiencie update_plugins, a verify_download() czyta je stamtąd.
			'colorify_sha256' => isset( $release['digest'] ) ? $release['digest'] : '', // Cache zapisany starszym kodem nie ma klucza digest.
			'requires'     => $requires,
			'requires_php' => $requires_php,
		);
	}

	/**
	 * Okno "Szczegóły" w panelu (zamiast zapytania do wordpress.org).
	 *
	 * @param false|object|array $result Dotychczasowy wynik.
	 * @param string             $action Akcja plugins_api.
	 * @param object             $args   Argumenty.
	 * @return false|object|array
	 */
	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( null === $release ) {
			return $result;
		}

		// Bez linii wymagań w treści wydania: wymagania z nagłówka zainstalowanej wtyczki (nie literał).
		$header = get_file_data(
			COLORIFY_PLUGIN_FILE,
			array(
				'RequiresWP'  => 'Requires at least',
				'RequiresPHP' => 'Requires PHP',
			)
		);

		return (object) array(
			'name'          => 'Colorify by INYFINN',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://inyfinn.art">INYFINN</a>',
			'homepage'      => 'https://inyfinn.art',
			'requires'      => '' !== $release['requires'] ? $release['requires'] : $header['RequiresWP'],
			'requires_php'  => '' !== $release['requires_php'] ? $release['requires_php'] : $header['RequiresPHP'],
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'external'      => true,
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Personalizacja kolorów panelu WordPress (wp-admin) i strony logowania.', 'colorify-by-inyfinn' ) . '</p>',
				'changelog'   => '' !== $release['notes']
					? wp_kses_post( wpautop( esc_html( $release['notes'] ) ) )
					: '<p>' . esc_html__( 'Zobacz wydanie na GitHubie.', 'colorify-by-inyfinn' ) . '</p>',
			),
		);
	}

	/**
	 * Czy hook upgradera dotyczy tej wtyczki.
	 *
	 * @param mixed $hook_extra Kontekst upgradera.
	 * @return bool
	 */
	private static function targets_us( $hook_extra ) {
		return is_array( $hook_extra )
			&& isset( $hook_extra['plugin'] )
			&& plugin_basename( COLORIFY_PLUGIN_FILE ) === $hook_extra['plugin'];
	}

	/**
	 * Pobranie paczki z weryfikacją sumy SHA-256 podanej przez GitHub API przy ofercie aktualizacji.
	 *
	 * Suma obecna i niezgodna: WP_Error, nic nie jest instalowane. Suma nieobecna (API jej nie podało albo ma
	 * inny format): instalacja jak dotąd, bez weryfikacji, bo starsze wydania mogą pola `digest`
	 * nie mieć. Suma pochodzi z tej samej odpowiedzi API co adres paczki, więc chroni przed
	 * uszkodzonym albo podmienionym plikiem po drodze, nie przed przejęciem konta na GitHubie.
	 *
	 * @param false|string|WP_Error $reply      Dotychczasowy wynik (false: rdzeń sam pobiera).
	 * @param string                $package    Adres paczki.
	 * @param WP_Upgrader|null      $upgrader   Upgrader.
	 * @param array                 $hook_extra Kontekst.
	 * @return false|string|WP_Error Ścieżka pobranego pliku po poprawnej weryfikacji.
	 */
	public static function verify_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) || ! self::targets_us( $hook_extra ) ) {
			return $reply;
		}

		$digest = self::offered_digest( $package );
		if ( '' === $digest ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( is_object( $upgrader ) && isset( $upgrader->skin ) && is_object( $upgrader->skin ) ) {
			$upgrader->skin->feedback( 'downloading_package', $package );
		}

		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return new WP_Error( 'download_failed', __( 'Pobieranie paczki Colorify nie powiodło się.', 'colorify-by-inyfinn' ), $file->get_error_message() );
		}

		$actual = hash_file( 'sha256', $file );
		if ( ! is_string( $actual ) || ! hash_equals( $digest, strtolower( $actual ) ) ) {
			wp_delete_file( $file );

			return new WP_Error(
				'colorify_bad_digest',
				__( 'Suma kontrolna pobranej paczki Colorify nie zgadza się z sumą podaną przez GitHub. Instalacja wstrzymana, obecna wersja działa bez zmian.', 'colorify-by-inyfinn' )
			);
		}

		return $file;
	}

	/**
	 * Suma SHA-256 zapisana w ofercie aktualizacji (transient update_plugins) dla tego adresu paczki.
	 *
	 * @param string $package Adres paczki.
	 * @return string Pusty, gdy oferta nie dotyczy tego adresu albo nie niesie sumy.
	 */
	private static function offered_digest( $package ) {
		$basename = plugin_basename( COLORIFY_PLUGIN_FILE );
		$updates  = get_site_transient( 'update_plugins' );

		if ( is_object( $updates ) && isset( $updates->response[ $basename ] ) ) {
			$item = $updates->response[ $basename ];
			if ( is_object( $item ) && isset( $item->package, $item->colorify_sha256 ) && $item->package === $package && is_string( $item->colorify_sha256 ) ) {
				return $item->colorify_sha256;
			}
		}

		return '';
	}

	/**
	 * Po rozpakowaniu paczka musi mieć jeden katalog główny colorify-by-inyfinn/,
	 * inaczej WordPress zainstalowałby wtyczkę pod inną ścieżką i ją wyłączył.
	 *
	 * @param string|WP_Error $source        Katalog źródłowy po rozpakowaniu.
	 * @param string          $remote_source Katalog rozpakowania.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $hook_extra    Kontekst.
	 * @return string|WP_Error
	 */
	public static function verify_source( $source, $remote_source = '', $upgrader = null, $hook_extra = array() ) {
		unset( $remote_source, $upgrader );

		if ( is_wp_error( $source ) || ! self::targets_us( $hook_extra ) ) {
			return $source;
		}

		if ( self::SLUG !== basename( untrailingslashit( $source ) ) ) {
			return new WP_Error(
				'colorify_bad_package_root',
				__( 'Niepoprawna paczka Colorify: oczekiwany jest jeden katalog główny colorify-by-inyfinn/.', 'colorify-by-inyfinn' )
			);
		}

		global $wp_filesystem;

		// Rdzeń instaluje do katalogu o nazwie katalogu źródłowego i kasuje stary katalog wtyczki. Gdy wtyczka stoi
		// w katalogu o innej nazwie (np. po wgraniu "Source code (zip)" z GitHuba: colorify-by-inyfinn-main),
		// nowa wersja trafiłaby do colorify-by-inyfinn/, a wpis w active_plugins wskazywałby nieistniejący plik.
		// Dlatego źródło dostaje nazwę katalogu, w którym wtyczka faktycznie stoi.
		$install_dir = dirname( plugin_basename( COLORIFY_PLUGIN_FILE ) );
		if ( '.' !== $install_dir && self::SLUG !== $install_dir ) {
			if ( ! $wp_filesystem ) {
				return new WP_Error( 'colorify_no_filesystem', __( 'Brak dostępu do systemu plików WordPressa.', 'colorify-by-inyfinn' ) );
			}
			$renamed = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $install_dir;
			if ( ! $wp_filesystem->move( untrailingslashit( $source ), $renamed, true ) ) {
				return new WP_Error(
					'colorify_rename_failed',
					__( 'Nie udało się przygotować paczki Colorify pod nazwę katalogu, w którym stoi wtyczka.', 'colorify-by-inyfinn' )
				);
			}
			$source = trailingslashit( $renamed );
		}

		// Rdzeń sprawdza "requires" tylko w bulk_upgrade() (ekran Wtyczki, Aktualizacje), a nie w upgrade()
		// (update.php bez JS, WP-CLI, auto-aktualizacje). Nowa paczka nie może trafić na zbyt stare środowisko.
		$main = $wp_filesystem ? $wp_filesystem->get_contents( trailingslashit( $source ) . 'colorify-by-inyfinn.php' ) : false;
		if ( is_string( $main ) ) {
			$wp_core = explode( '-', isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0' );
			$php_ok  = ! preg_match( '/^[ \t\/*#@]*Requires PHP:[ \t]*(\d+(?:\.\d+){0,2})/mi', substr( $main, 0, 8192 ), $m ) || version_compare( PHP_VERSION, $m[1], '>=' );
			$wp_ok   = ! preg_match( '/^[ \t\/*#@]*Requires at least:[ \t]*(\d+(?:\.\d+){0,2})/mi', substr( $main, 0, 8192 ), $m ) || version_compare( $wp_core[0], $m[1], '>=' );
			if ( ! $php_ok || ! $wp_ok ) {
				return new WP_Error(
					'colorify_requirements',
					__( 'Ta wersja Colorify wymaga nowszego PHP lub WordPressa niż na tej stronie. Aktualizacja została wstrzymana, obecna wersja działa bez zmian.', 'colorify-by-inyfinn' )
				);
			}
		}

		return $source;
	}

	/**
	 * Po instalacji plik główny musi istnieć. WP_Error przywraca poprzednią wersję (kopia
	 * w upgrade-temp-backup, przywracana przy zamknięciu żądania).
	 *
	 * @param bool|WP_Error $response   Wynik filtra.
	 * @param array         $hook_extra Kontekst.
	 * @param array         $result     Wynik instalacji (m.in. destination i remote_destination).
	 * @return bool|WP_Error
	 */
	public static function verify_post_install( $response, $hook_extra = array(), $result = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $response ) || ! self::targets_us( $hook_extra ) ) {
			return $response;
		}

		// $result['destination'] to ścieżka LOKALNA, $result['remote_destination'] to ta sama ścieżka widziana
		// przez $wp_filesystem (przy FS_METHOD=ftpext/ssh2 obie się różnią, np. /wp-content/plugins/... zamiast
		// /var/www/html/wp-content/plugins/...). Sprawdzenie lokalnej ścieżki przez $wp_filesystem zwróciłoby fałsz
		// i cofnęło poprawną aktualizację, więc: zdalna ścieżka przez $wp_filesystem ALBO lokalna przez file_exists().
		// Błąd jest tylko wtedy, gdy plik nie istnieje w żadnym z dwóch widoków (fałszywy alarm cofa aktualizację
		// przy każdej próbie, więc wolimy przepuścić niż cofnąć).
		$found = false;
		if ( is_array( $result ) ) {
			if ( ! empty( $result['remote_destination'] ) && $wp_filesystem ) {
				$found = $wp_filesystem->exists( trailingslashit( $result['remote_destination'] ) . 'colorify-by-inyfinn.php' );
			}
			if ( ! $found && ! empty( $result['destination'] ) ) {
				$found = file_exists( trailingslashit( $result['destination'] ) . 'colorify-by-inyfinn.php' );
			}
		}

		if ( ! $found ) {
			return new WP_Error(
				'colorify_bad_package',
				__( 'Niepoprawna paczka Colorify: brakuje pliku colorify-by-inyfinn.php po rozpakowaniu.', 'colorify-by-inyfinn' )
			);
		}

		return $response;
	}

	/**
	 * Najnowsze poprawne wydanie z cache albo z GitHuba.
	 *
	 * Błąd i wydanie bez poprawnej paczki są zapamiętane na 15 minut, poprawne wydanie na 11 h.
	 *
	 * @return array|null
	 */
	private static function latest_release() {
		$repo = self::repo();
		if ( '' === $repo ) {
			return null;
		}

		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['repo'] ) && $cached['repo'] === $repo ) {
			if ( ! empty( $cached['version'] ) ) {
				return $cached;
			}

			// Wpis o niepowodzeniu (15 minut odstępu między próbami): oferta nie znika, tylko stoi na ostatnim dobrym wydaniu.
			return empty( $cached['failed'] ) ? null : self::last_good_release( $repo );
		}

		$release = self::fetch_release( $repo );
		if ( false === $release ) {
			// Błąd sieci, limit API (403/429) albo 5xx: rdzeń budowałby listę aktualizacji od zera i oferta by zniknęła.
			set_site_transient( self::CACHE_KEY, array( 'repo' => $repo, 'failed' => true ), self::CACHE_FAIL_TTL );
			return self::last_good_release( $repo );
		}
		if ( null === $release ) {
			// Odpowiedź poprawna, ale bez użytecznego wydania (brak assetu, wersja spoza wzorca): bez oferty.
			set_site_transient( self::CACHE_KEY, array( 'repo' => $repo ), self::CACHE_FAIL_TTL );
			return null;
		}

		$release['repo'] = $repo;
		set_site_transient( self::CACHE_KEY, $release, self::CACHE_OK_TTL );
		set_site_transient( self::GOOD_KEY, $release, self::GOOD_TTL );

		return $release;
	}

	/**
	 * Ostatnie poprawnie pobrane wydanie (do 7 dni) na czas awarii API GitHuba.
	 *
	 * @param string $repo owner/repo.
	 * @return array|null
	 */
	private static function last_good_release( $repo ) {
		$good = get_site_transient( self::GOOD_KEY );

		return is_array( $good ) && ! empty( $good['version'] ) && isset( $good['repo'] ) && $good['repo'] === $repo ? $good : null;
	}

	/**
	 * Pobiera i waliduje odpowiedź GET /repos/{owner}/{repo}/releases/latest.
	 *
	 * @param string $repo owner/repo.
	 * @return array|null|false false: błąd sieci albo odpowiedź inna niż 200; null: odpowiedź bez użytecznego wydania.
	 */
	private static function fetch_release( $repo ) {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo . '/releases/latest',
			array(
				'timeout' => 5,
				// Bez nagłówka X-GitHub-Api-Version: GitHub używa wtedy wersji domyślnej, więc nie ma daty,
				// po której zapytania zaczęłyby dostawać 400.
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'colorify-by-inyfinn/' . COLORIFY_PLUGIN_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return false; // Nie JSON (strona pośrednika, ucięta odpowiedź): awaria, nie brak wydania.
		}
		if ( empty( $data['tag_name'] ) || ! is_string( $data['tag_name'] ) ) {
			return null;
		}

		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$tag = trim( $data['tag_name'] );
		if ( ! preg_match( self::TAG_REGEX, $tag, $matches ) ) {
			return null;
		}

		$package = self::package_url( $data, $repo );
		if ( '' === $package ) {
			return null;
		}

		$notes = isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '';
		$page  = isset( $data['html_url'] ) && is_string( $data['html_url'] ) && 0 === strpos( $data['html_url'], 'https://github.com/' )
			? $data['html_url']
			: 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode( $tag );

		return array(
			'version'      => $matches[1],
			'url'          => $page,
			'package'      => $package,
			'digest'       => self::asset_digest( $data, $package ),
			'notes'        => $notes,
			'published'    => isset( $data['published_at'] ) && is_string( $data['published_at'] ) ? $data['published_at'] : '',
			'requires'     => self::notes_field( $notes, 'Requires at least' ),
			'requires_php' => self::notes_field( $notes, 'Requires PHP' ),
		);
	}

	/**
	 * Adres assetu release: nazwa pasuje do ASSET_REGEX, adres zaczyna się od
	 * https://github.com/<repo>/releases/download/. Zipball nigdy.
	 *
	 * @param array  $release Odpowiedź GitHub API.
	 * @param string $repo    owner/repo.
	 * @return string Pusty, gdy brak poprawnego assetu.
	 */
	private static function package_url( array $release, $repo ) {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		$prefix = 'https://github.com/' . $repo . '/releases/download/';

		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = isset( $asset['name'] ) && is_string( $asset['name'] ) ? $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) && is_string( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';

			if ( '' !== $url && 0 === strpos( $url, $prefix ) && preg_match( self::ASSET_REGEX, $name ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Suma SHA-256 assetu z odpowiedzi API (pole `digest`, format `sha256:<64 znaki hex>`).
	 *
	 * @param array  $release Odpowiedź GitHub API.
	 * @param string $url     Adres wybranego assetu.
	 * @return string Pusty, gdy API nie podaje sumy (starsze wydania) albo ma inny format.
	 */
	private static function asset_digest( array $release, $url ) {
		foreach ( $release['assets'] as $asset ) {
			if ( is_array( $asset ) && isset( $asset['browser_download_url'], $asset['digest'] ) && $asset['browser_download_url'] === $url && is_string( $asset['digest'] ) && preg_match( '/^sha256:([0-9a-f]{64})$/i', $asset['digest'], $m ) ) {
				return strtolower( $m[1] );
			}
		}

		return '';
	}

	/**
	 * Wartość linii `Etykieta: 1.2.3` z treści release (wypisuje ją tools/release.sh).
	 *
	 * @param string $notes Treść release.
	 * @param string $label Etykieta (stała, bez znaków specjalnych regex).
	 * @return string Pusty, gdy brak linii.
	 */
	private static function notes_field( $notes, $label ) {
		if ( preg_match( '/^[ \t]*' . $label . ':[ \t]*(\d+(?:\.\d+){0,2})[ \t\r]*$/mi', $notes, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Wydanie z cache (bez zapytań HTTP).
	 *
	 * @return array|null Pusty wynik, gdy cache jest pusty albo zapamiętał błąd.
	 */
	public static function cached_release() {
		$cached = get_site_transient( self::CACHE_KEY );

		return is_array( $cached ) && ! empty( $cached['version'] ) && self::repo() === ( isset( $cached['repo'] ) ? $cached['repo'] : '' ) ? $cached : null;
	}

	/**
	 * Kasuje nasz cache wydania.
	 */
	public static function purge_cache() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Ekran Aktualizacje z force-check=1 ("Sprawdź ponownie"): wydanie ma być widoczne od razu.
	 * Rdzeń przy force-check nie kasuje transientu update_plugins, a wp_update_plugins() na tym
	 * ekranie odczekuje minutę od poprzedniego sprawdzenia. Kasujemy więc własny cache i zerujemy
	 * znacznik last_checked (dane innych wtyczek zostają), a zaraz potem rdzeń woła
	 * wp_update_plugins() z tego samego load-update-core.php (priorytet 10, nasz 5).
	 */
	public static function maybe_purge_on_force_check() {
		if ( empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		self::force_recheck();
	}

	/**
	 * Kasuje nasz cache i zeruje last_checked w transiencie rdzenia (dane aktualizacji innych
	 * wtyczek zostają, więc nieudane zapytanie do wordpress.org niczego nie gubi).
	 */
	private static function force_recheck() {
		self::purge_cache();

		$updates = get_site_transient( 'update_plugins' );
		if ( is_object( $updates ) ) {
			$updates->last_checked = 0;
			set_site_transient( 'update_plugins', $updates );
		}
	}

	/**
	 * Adres linku "Sprawdź aktualizacje" (admin-post, nonce).
	 *
	 * @param string $return Gdzie wrócić: 'plugins' albo 'settings'.
	 * @return string
	 */
	public static function check_url( $return = 'plugins' ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'          => self::CHECK_ACTION,
					'colorify_return' => 'settings' === $return ? 'settings' : 'plugins',
				),
				admin_url( 'admin-post.php' )
			),
			self::CHECK_ACTION
		);
	}

	/**
	 * Akcja "Sprawdź aktualizacje": czyści cache, odświeża listę aktualizacji WordPressa i wraca.
	 * Niczego nie instaluje; instaluje wyłącznie WordPress.
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Brak uprawnień do aktualizacji wtyczek.', 'colorify-by-inyfinn' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::CHECK_ACTION );

		self::force_recheck();
		wp_update_plugins();

		$return = isset( $_GET['colorify_return'] ) ? sanitize_key( wp_unslash( $_GET['colorify_return'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base   = 'settings' === $return
			? admin_url( 'options-general.php?page=colorify-by-inyfinn' )
			: admin_url( 'plugins.php' );

		wp_safe_redirect( add_query_arg( 'colorify_checked', '1', $base ) );
		exit;
	}

	/**
	 * Stan z listy aktualizacji WordPressa (bez zapytań HTTP).
	 *
	 * @return array{state:string,version:string} state: update, current albo unknown.
	 */
	public static function status() {
		$basename = plugin_basename( COLORIFY_PLUGIN_FILE );
		$updates  = get_site_transient( 'update_plugins' );

		if ( is_object( $updates ) && isset( $updates->response[ $basename ]->new_version ) ) {
			return array(
				'state'   => 'update',
				'version' => (string) $updates->response[ $basename ]->new_version,
			);
		}

		if ( is_object( $updates ) && isset( $updates->no_update[ $basename ] ) ) {
			return array(
				'state'   => 'current',
				'version' => COLORIFY_PLUGIN_VERSION,
			);
		}

		return array(
			'state'   => 'unknown',
			'version' => '',
		);
	}

	/**
	 * Komunikat po "Sprawdź aktualizacje" (lista wtyczek i Ustawienia, Colorify).
	 */
	public static function render_check_notice() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_colorify-by-inyfinn' ), true ) ) {
			return;
		}

		// Stary kod 1.4.9 (link "Aktualizuj") po instalacji przekierowuje z ?colorify_update=updated.
		// Jego komunikat znika razem ze starymi plikami, więc pokazujemy go tutaj.
		if ( isset( $_GET['colorify_update'] ) && 'updated' === sanitize_key( wp_unslash( $_GET['colorify_update'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible colorify-updated-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: wersja wtyczki */
						__( 'Colorify zaktualizowany do wersji %s.', 'colorify-by-inyfinn' ),
						COLORIFY_PLUGIN_VERSION
					)
				)
			);
			return;
		}

		if ( empty( $_GET['colorify_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$status = self::status();

		if ( 'update' === $status['state'] ) {
			$class   = 'notice-info';
			$message = sprintf(
				/* translators: %s: nowa wersja */
				__( 'Dostępna jest nowa wersja Colorify: %s. Zainstalujesz ją przyciskiem "Zaktualizuj teraz" przy wtyczce.', 'colorify-by-inyfinn' ),
				$status['version']
			);
		} elseif ( 'current' === $status['state'] ) {
			$class   = 'notice-success';
			$message = __( 'Masz najnowszą wersję Colorify.', 'colorify-by-inyfinn' );
		} else {
			$class   = 'notice-warning';
			$message = __( 'Nie udało się sprawdzić aktualizacji Colorify (GitHub niedostępny albo wydanie bez poprawnej paczki). Spróbuj za kilka minut.', 'colorify-by-inyfinn' );
		}

		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Jednorazowo po zmianie wersji (znacznik: opcja colorify_version): sprząta po 1.4.9
	 * i usuwa pliki-sieroty.
	 */
	public static function maybe_upgrade() {
		if ( COLORIFY_PLUGIN_VERSION === get_option( self::VERSION_OPT ) ) {
			return;
		}

		self::cleanup_legacy_state();
		self::remove_orphans();

		update_option( self::UPGRADED_OPT, time() );
		update_option( self::VERSION_OPT, COLORIFY_PLUGIN_VERSION );
	}

	/**
	 * Przez 7 dni po zmianie wersji usuwa opcję colorify_pending_reactivate, jeśli się pojawi.
	 * Stary kod 1.4.9 ustawia ją na końcu własnej aktualizacji (ścieżka update.php), czyli już po tym,
	 * jak nowy kod zdążył posprzątać. Opcja nie jest autoload, stąd ograniczenie czasowe
	 * (znacznik UPGRADED_OPT jest autoload, więc po tygodniu nie kosztuje już ani jednego zapytania).
	 */
	public static function late_legacy_cleanup() {
		$upgraded_at = (int) get_option( self::UPGRADED_OPT, 0 );

		if ( $upgraded_at > 0 && ( time() - $upgraded_at ) < 7 * DAY_IN_SECONDS ) {
			// get_option() najpierw: delete_option() nie korzysta z cache nieistniejących opcji (zapytanie SQL na każde żądanie admina).
			if ( false !== get_option( 'colorify_pending_reactivate' ) ) {
				delete_option( 'colorify_pending_reactivate' );
			}
		}
	}

	/**
	 * Opcje i transjenty starego updatera (1.4.9).
	 */
	private static function cleanup_legacy_state() {
		delete_option( 'colorify_pending_reactivate' );
		delete_transient( 'colorify_plugin_was_active_before_update' );
		delete_transient( 'colorify_update_in_progress' );
		delete_transient( 'colorify_update_notice_' . get_current_user_id() );

		$repos = array( 'inyfinn/colorify-by-inyfinn', self::repo() );
		$saved = get_option( 'colorify_github_repo', '' );
		if ( is_string( $saved ) && '' !== $saved ) {
			$repos[] = trim( $saved );
		}
		foreach ( array_unique( $repos ) as $repo ) {
			if ( '' !== $repo ) {
				delete_transient( 'colorify_github_release_' . md5( $repo ) );
			}
		}
		delete_option( 'colorify_github_repo' );
	}

	/**
	 * Kasuje pojedynczo pliki z zamkniętej listy ORPHANS (potem puste katalogi z ORPHAN_DIRS).
	 * Każda ścieżka przechodzi przez realpath() i musi leżeć wewnątrz katalogu wtyczki (dowiązanie
	 * wskazujące na zewnątrz jest pomijane). Nic nie jest kasowane rekurencyjnie.
	 */
	private static function remove_orphans() {
		$base = realpath( COLORIFY_PLUGIN_DIR );
		if ( false === $base ) {
			return;
		}
		$base = rtrim( wp_normalize_path( $base ), '/' ) . '/';

		foreach ( self::ORPHANS as $relative ) {
			$real = realpath( COLORIFY_PLUGIN_DIR . $relative );
			if ( false !== $real && is_file( $real ) && 0 === strpos( wp_normalize_path( $real ), $base ) ) {
				wp_delete_file( $real );
			}
		}

		foreach ( self::ORPHAN_DIRS as $relative ) {
			$real = realpath( COLORIFY_PLUGIN_DIR . $relative );
			if ( false !== $real && is_dir( $real ) && 0 === strpos( wp_normalize_path( $real ) . '/', $base ) && wp_normalize_path( $real ) . '/' !== $base ) {
				@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- tylko pusty katalog, bez rekurencji.
			}
		}
	}
}
