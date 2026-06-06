<?php
/**
 * Aktualizacje wtyczki z GitHub Releases (natywny ekran Wtyczki + ręczna aktualizacja).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repozytorium GitHub: stała COLORIFY_GITHUB_REPO (owner/repo) lub opcja colorify_github_repo.
 *
 * @return string
 */
function colorify_get_github_repo(): string {
	if ( defined( 'COLORIFY_GITHUB_REPO' ) && is_string( COLORIFY_GITHUB_REPO ) && '' !== COLORIFY_GITHUB_REPO ) {
		return trim( COLORIFY_GITHUB_REPO );
	}

	$option = get_option( 'colorify_github_repo', '' );

	return is_string( $option ) ? trim( $option ) : '';
}

/**
 * Odczyt release wyłącznie z cache (bez HTTP — bezpieczne na liście wtyczek).
 *
 * @return array<string,mixed>|null
 */
function colorify_get_cached_github_release(): ?array {
	$repo = colorify_get_github_repo();
	if ( '' === $repo ) {
		return null;
	}

	$cached = get_transient( 'colorify_github_release_' . md5( $repo ) );

	return is_array( $cached ) && empty( $cached['error'] ) ? $cached : null;
}

/**
 * Czyści cache ostatniego release GitHub.
 */
function colorify_purge_github_release_cache(): void {
	$repo = colorify_get_github_repo();
	if ( '' !== $repo ) {
		delete_transient( 'colorify_github_release_' . md5( $repo ) );
	}
}

/**
 * Odczyt najnowszego release z GitHub API (cache 12 h).
 *
 * @param bool $force_refresh Pomiń cache i pobierz na świeżo.
 * @return array<string,mixed>|null
 */
function colorify_fetch_github_release( bool $force_refresh = false ): ?array {
	$repo = colorify_get_github_repo();
	if ( '' === $repo || ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
		return null;
	}

	$cache_key = 'colorify_github_release_' . md5( $repo );

	if ( $force_refresh ) {
		delete_transient( $cache_key );
	} else {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return ! empty( $cached['error'] ) ? null : $cached;
		}
	}

	$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo );
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 8,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		set_transient( $cache_key, array( 'error' => true ), HOUR_IN_SECONDS );
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		set_transient( $cache_key, array( 'error' => true ), HOUR_IN_SECONDS );
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
		set_transient( $cache_key, array( 'error' => true ), HOUR_IN_SECONDS );
		return null;
	}

	$version  = ltrim( (string) $data['tag_name'], 'vV' );
	$download = '';

	if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
		foreach ( $data['assets'] as $asset ) {
			if (
				! empty( $asset['browser_download_url'] )
				&& is_string( $asset['name'] ?? null )
				&& '.zip' === substr( strtolower( $asset['name'] ), -4 )
			) {
				$download = (string) $asset['browser_download_url'];
				break;
			}
		}
	}

	if ( '' === $download && ! empty( $data['zipball_url'] ) ) {
		$download = (string) $data['zipball_url'];
	}

	$release = array(
		'version'   => $version,
		'url'       => ! empty( $data['html_url'] ) ? (string) $data['html_url'] : sprintf( 'https://github.com/%s/releases', $repo ),
		'download'  => $download,
		'notes'     => ! empty( $data['body'] ) ? (string) $data['body'] : '',
		'published' => ! empty( $data['published_at'] ) ? (string) $data['published_at'] : '',
	);

	set_transient( $cache_key, $release, 12 * HOUR_IN_SECONDS );

	return $release;
}

/**
 * URL ręcznej aktualizacji (lista wtyczek lub ustawienia Colorify).
 *
 * @param string $redirect_after Bazowy URL powrotu po akcji.
 */
function colorify_get_manual_update_url( string $redirect_after = '' ): string {
	$base = '' !== $redirect_after ? $redirect_after : admin_url( 'plugins.php' );

	return wp_nonce_url(
		add_query_arg( 'colorify_run_update', '1', $base ),
		'colorify_run_update'
	);
}

/**
 * Czy na GitHubie jest nowszy release niż zainstalowana wersja.
 */
function colorify_has_github_update(): bool {
	if ( '' === colorify_get_github_repo() ) {
		return false;
	}

	$release = colorify_get_cached_github_release();
	if ( ! is_array( $release ) || empty( $release['version'] ) ) {
		return false;
	}

	return version_compare( COLORIFY_PLUGIN_VERSION, $release['version'], '<' );
}

/**
 * Komunikat po ręcznej aktualizacji (plugins.php + Ustawienia → Colorify).
 */
function colorify_render_update_result_notice(): void {
	if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$allowed = $screen && in_array( $screen->id, array( 'plugins', 'settings_page_colorify-by-inyfinn' ), true );
	if ( ! $allowed ) {
		return;
	}

	$update_result = isset( $_GET['colorify_update'] ) ? sanitize_key( wp_unslash( $_GET['colorify_update'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$new_version   = isset( $_GET['colorify_new_version'] ) ? sanitize_text_field( wp_unslash( $_GET['colorify_new_version'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( '' === $update_result ) {
		return;
	}

	if ( 'updated' === $update_result ) {
		$message = '' !== $new_version
			? sprintf(
				/* translators: %s: version number */
				__( 'Colorify zaktualizowany do wersji %s.', 'colorify-by-inyfinn' ),
				$new_version
			)
			: __( 'Colorify został zaktualizowany.', 'colorify-by-inyfinn' );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		return;
	}

	if ( 'already_latest' === $update_result ) {
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
			esc_html__( 'To aktualna wersja.', 'colorify-by-inyfinn' )
		);
		return;
	}

	$stored = get_transient( 'colorify_update_notice_' . get_current_user_id() );
	if ( is_string( $stored ) && '' !== $stored ) {
		delete_transient( 'colorify_update_notice_' . get_current_user_id() );
		$message = $stored;
	} else {
		$message = __( 'Aktualizacja nie powiodła się.', 'colorify-by-inyfinn' );
	}

	printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
}
add_action( 'admin_notices', 'colorify_render_update_result_notice', 5 );

/**
 * Ręczna aktualizacja: sprawdź GitHub Releases → pobierz zip → zainstaluj.
 *
 * @return array{success:bool,code:string,message:string,version?:string,remote?:string}
 */
function colorify_run_manual_github_update(): array {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return array(
			'success' => false,
			'code'    => 'capability',
			'message' => __( 'Brak uprawnień do aktualizacji wtyczek.', 'colorify-by-inyfinn' ),
		);
	}

	if ( '' === colorify_get_github_repo() ) {
		return array(
			'success' => false,
			'code'    => 'no_repo',
			'message' => __( 'Nie skonfigurowano repozytorium GitHub.', 'colorify-by-inyfinn' ),
		);
	}

	colorify_purge_github_release_cache();

	$release = colorify_fetch_github_release( true );
	if ( ! $release || empty( $release['version'] ) || empty( $release['download'] ) ) {
		return array(
			'success' => false,
			'code'    => 'fetch_failed',
			'message' => __( 'Nie udało się pobrać informacji o release z GitHub.', 'colorify-by-inyfinn' ),
		);
	}

	$current = COLORIFY_PLUGIN_VERSION;
	$remote  = (string) $release['version'];

	if ( version_compare( $current, $remote, '>=' ) ) {
		return array(
			'success' => true,
			'code'    => 'already_latest',
			'message' => __( 'To aktualna wersja.', 'colorify-by-inyfinn' ),
			'version' => $current,
			'remote'  => $remote,
		);
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	$plugin_basename = plugin_basename( COLORIFY_PLUGIN_FILE );

	$updates = get_site_transient( 'update_plugins' );
	if ( ! is_object( $updates ) ) {
		$updates = new stdClass();
	}
	if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
		$updates->response = array();
	}

	$updates->response[ $plugin_basename ] = (object) array(
		'id'          => 'colorify-by-inyfinn',
		'slug'        => 'colorify-by-inyfinn',
		'plugin'      => $plugin_basename,
		'new_version' => $remote,
		'url'         => $release['url'],
		'package'     => $release['download'],
		'tested'      => get_bloginfo( 'version' ),
	);
	set_site_transient( 'update_plugins', $updates );

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->upgrade( $plugin_basename );

	colorify_purge_github_release_cache();

	if ( is_wp_error( $result ) ) {
		return array(
			'success' => false,
			'code'    => 'upgrade_error',
			'message' => $result->get_error_message(),
		);
	}

	if ( false === $result ) {
		$message = __( 'Aktualizacja nie powiodła się.', 'colorify-by-inyfinn' );
		if ( method_exists( $skin, 'get_error_messages' ) ) {
			$errors = $skin->get_error_messages();
			if ( ! empty( $errors ) && is_array( $errors ) ) {
				$message = implode( ' ', array_map( 'wp_strip_all_tags', $errors ) );
			}
		}
		return array(
			'success' => false,
			'code'    => 'upgrade_failed',
			'message' => $message,
		);
	}

	return array(
		'success' => true,
		'code'    => 'updated',
		'message' => sprintf(
			/* translators: %s: new version number */
			__( 'Zaktualizowano do wersji %s.', 'colorify-by-inyfinn' ),
			$remote
		),
		'version' => $remote,
		'remote'  => $remote,
	);
}

/**
 * Wstrzyknięcie informacji o aktualizacji do WordPressa.
 */
final class Colorify_Github_Updater {

	private string $plugin_basename;
	private string $plugin_slug = 'colorify-by-inyfinn';

	public static function init(): void {
		if ( '' === colorify_get_github_repo() ) {
			return;
		}
		new self();
	}

	private function __construct() {
		$this->plugin_basename = plugin_basename( COLORIFY_PLUGIN_FILE );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_force_check' ) );
		add_action( 'admin_init', array( $this, 'maybe_run_manual_update' ) );
	}

	/**
	 * @param object|false $transient Transient aktualizacji.
	 * @return object|false
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		// Tylko cache — nigdy HTTP na plugins.php (wp_update_plugins blokuje request do 20 s).
		$release = colorify_get_cached_github_release();
		if ( ! $release || empty( $release['version'] ) || empty( $release['download'] ) ) {
			return $transient;
		}

		$current = COLORIFY_PLUGIN_VERSION;
		$item    = (object) array(
			'id'          => $this->plugin_slug,
			'slug'        => $this->plugin_slug,
			'plugin'      => $this->plugin_basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['download'],
			'tested'      => get_bloginfo( 'version' ),
		);

		if ( version_compare( $current, $release['version'], '<' ) ) {
			$transient->response[ $this->plugin_basename ] = $item;
		} else {
			$transient->no_update[ $this->plugin_basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Szczegóły wtyczki w modalu „View details”.
	 *
	 * @param false|object|array<string,mixed> $result Wynik.
	 * @param string                           $action Akcja API.
	 * @param object                           $args   Argumenty.
	 * @return false|object|array<string,mixed>
	 */
	public function plugin_info( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		$release = colorify_get_cached_github_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Colorify by INYFINN',
			'slug'          => $this->plugin_slug,
			'version'       => $release['version'],
			'author'        => '<a href="https://inyfinn.art">INYFINN</a>',
			'homepage'      => 'https://inyfinn.art',
			'download_link' => $release['download'],
			'sections'      => array(
				'description' => __( 'Personalizacja kolorów panelu WordPress (wp-admin) i strony logowania.', 'colorify-by-inyfinn' ),
				'changelog'   => wp_kses_post( $release['notes'] ),
			),
			'last_updated'  => $release['published'],
		);
	}

	/**
	 * Po aktualizacji wtyczki — odśwież cache release.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $options  Opcje.
	 */
	public function purge_cache( $upgrader, array $options ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if (
			empty( $options['action'] )
			|| 'update' !== $options['action']
			|| empty( $options['type'] )
			|| 'plugin' !== $options['type']
		) {
			return;
		}

		colorify_purge_github_release_cache();
	}

	/**
	 * Ręczne sprawdzenie z panelu ustawień (?colorify_check_updates=1).
	 */
	public function maybe_force_check(): void {
		if (
			! is_admin()
			|| ! current_user_can( 'update_plugins' )
			|| empty( $_GET['colorify_check_updates'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| '1' !== $_GET['colorify_check_updates'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| empty( $_GET['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'colorify_check_updates' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}

		colorify_purge_github_release_cache();
		colorify_fetch_github_release( true );
		wp_update_plugins();

		wp_safe_redirect(
			remove_query_arg(
				array( 'colorify_check_updates', '_wpnonce' ),
				wp_get_referer() ? wp_get_referer() : admin_url( 'options-general.php?page=colorify-by-inyfinn' )
			)
		);
		exit;
	}

	/**
	 * Ręczna instalacja aktualizacji z GitHub (?colorify_run_update=1).
	 */
	public function maybe_run_manual_update(): void {
		if (
			! is_admin()
			|| ! current_user_can( 'update_plugins' )
			|| empty( $_GET['colorify_run_update'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| '1' !== $_GET['colorify_run_update'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| empty( $_GET['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'colorify_run_update' ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}

		$result = colorify_run_manual_github_update();

		$redirect_args = array(
			'colorify_update' => $result['code'],
		);

		if ( ! empty( $result['version'] ) ) {
			$redirect_args['colorify_new_version'] = $result['version'];
		}

		if ( ! $result['success'] && ! empty( $result['message'] ) ) {
			set_transient(
				'colorify_update_notice_' . get_current_user_id(),
				$result['message'],
				MINUTE_IN_SECONDS
			);
		} elseif ( $result['success'] && ! empty( $result['message'] ) && 'updated' !== $result['code'] ) {
			set_transient(
				'colorify_update_notice_' . get_current_user_id(),
				$result['message'],
				MINUTE_IN_SECONDS
			);
		}

		$referer       = wp_get_referer();
		$redirect_base = admin_url( 'plugins.php' );
		if ( is_string( $referer ) && false !== strpos( $referer, 'page=colorify-by-inyfinn' ) ) {
			$redirect_base = admin_url( 'options-general.php' );
			$redirect_args['page'] = 'colorify-by-inyfinn';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, $redirect_base ) );
		exit;
	}
}

Colorify_Github_Updater::init();
