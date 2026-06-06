<?php
/**
 * Aktualizacje wtyczki z GitHub Releases (natywny ekran Wtyczki → Aktualizuj).
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
 * Odczyt najnowszego release z GitHub API (cache 12 h).
 *
 * @return array<string,mixed>|null
 */
function colorify_fetch_github_release(): ?array {
	$repo = colorify_get_github_repo();
	if ( '' === $repo || ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
		return null;
	}

	$cache_key = 'colorify_github_release_' . md5( $repo );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return ! empty( $cached['error'] ) ? null : $cached;
	}

	$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', $repo );
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
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

		$release = colorify_fetch_github_release();
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

		$release = colorify_fetch_github_release();
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

		$repo = colorify_get_github_repo();
		if ( '' !== $repo ) {
			delete_transient( 'colorify_github_release_' . md5( $repo ) );
		}
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

		$repo = colorify_get_github_repo();
		if ( '' !== $repo ) {
			delete_transient( 'colorify_github_release_' . md5( $repo ) );
		}

		wp_update_plugins();

		wp_safe_redirect(
			remove_query_arg(
				'colorify_check_updates',
				wp_get_referer() ? wp_get_referer() : admin_url( 'options-general.php?page=colorify-by-inyfinn' )
			)
		);
		exit;
	}
}

Colorify_Github_Updater::init();
