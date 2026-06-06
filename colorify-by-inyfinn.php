<?php
/**
 * Plugin Name: Colorify by INYFINN
 * Plugin URI: https://inyfinn.art
 * Description: Personalizacja kolorów panelu WordPress (wp-admin): schematy, własna paleta, dostrojenie, tryb ciemny/jasny. Ustawienia per użytkownik lub globalne.
 * Version: 1.0.11
 * Author: INYFINN
 * Author URI: https://inyfinn.art
 * Text Domain: colorify-by-inyfinn
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'COLORIFY_MU_MODULE' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>Colorify by INYFINN:</strong> %s</p></div>',
				esc_html__(
					'Moduł MU colorify/ jest już załadowany. Dezaktywuj wtyczkę Colorify lub usuń colorify-loader.php — działa tylko jeden silnik naraz.',
					'colorify-by-inyfinn'
				)
			);
		}
	);
	return;
}

if ( defined( 'COLORIFY_BY_INYFINN_LOADED' ) ) {
	return;
}

define( 'COLORIFY_BY_INYFINN_LOADED', true );
define( 'COLORIFY_PLUGIN_FILE', __FILE__ );
define( 'COLORIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COLORIFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'COLORIFY_PLUGIN_VERSION', '1.0.11' );
define( 'COLORIFY_SETTINGS_CSS_VERSION', '1.2.6' );

/**
 * Opcjonalnie: repozytorium GitHub do automatycznych aktualizacji (owner/repo).
 * Np. w wp-config.php: define( 'COLORIFY_GITHUB_REPO', 'inyfinn/colorify-by-inyfinn' );
 */
if ( ! defined( 'COLORIFY_GITHUB_REPO' ) ) {
	define( 'COLORIFY_GITHUB_REPO', 'inyfinn/colorify-by-inyfinn' );
}

/**
 * Wykrywa aktywny MU-plugin (nie aktywuj obu naraz).
 */
function colorify_mu_module_is_loaded(): bool {
	return defined( 'COLORIFY_MU_MODULE' );
}

function colorify_mu_module_exists(): bool {
	return file_exists( WP_CONTENT_DIR . '/mu-plugins/colorify-loader.php' );
}

/**
 * Nazwa motywu / design systemu CMS.
 */
const COLORIFY_BRANDING_NAME     = 'Colorify';
const COLORIFY_CREDITS         = 'inyfinn.art © 2026';
const COLORIFY_CREDITS_URL     = 'https://inyfinn.art';
const COLORIFY_BRANDING_CSS_VERSION    = '1.14.13';
const COLORIFY_APPEARANCE_JS_VERSION   = '1.16.1';
const COLORIFY_ADMIN_OVERRIDES_VER     = '1.11.0';
const COLORIFY_ADMIN_COLORS_VER        = '1.1.1';
const COLORIFY_ADMIN_TOOLBAR_VER       = '1.0.9';
const COLORIFY_TABLE_READABLE_VER      = '1.0.2';

/**
 * Ładuje tłumaczenia wtyczki (język = locale WordPressa).
 */
function colorify_load_textdomain(): void {
	load_plugin_textdomain(
		'colorify-by-inyfinn',
		false,
		dirname( plugin_basename( COLORIFY_PLUGIN_FILE ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'colorify_load_textdomain', 0 );

require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-compat.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-install-repair.php';
colorify_repair_broken_zip_paths();

require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-scope.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-toolbar-actions.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/colorify-admin-schemes.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-settings.php';
require_once COLORIFY_PLUGIN_DIR . 'includes/class-colorify-updater.php';

Colorify_Settings::init();

/**
 * Nazwa witryny z WordPressa.
 */
function colorify_branding_site_name(): string {
	$name = get_bloginfo( 'name', 'display' );
	return ( is_string( $name ) && '' !== $name ) ? $name : COLORIFY_BRANDING_NAME;
}

function colorify_branding_style_name(): string {
	return COLORIFY_BRANDING_NAME;
}

function colorify_branding_site_icon_url( int $size = 512 ): string {
	$icon = get_site_icon_url( $size );
	if ( is_string( $icon ) && '' !== $icon ) {
		return $icon;
	}
	return COLORIFY_PLUGIN_URL . 'assets/inyfinn-logo-okrag.svg';
}

function colorify_branding_uses_site_favicon(): bool {
	$icon = get_site_icon_url( 512 );
	return is_string( $icon ) && '' !== $icon;
}

function colorify_branding_login_brand_html(): string {
	$icon_url  = colorify_branding_site_icon_url( 512 );
	$is_fav    = colorify_branding_uses_site_favicon();
	$img_class = 'colorify-login-brand__icon-img' . ( $is_fav ? '' : ' colorify-login-brand__icon-img--fallback' );

	return '<span class="colorify-login-brand">'
		. '<span class="colorify-login-brand__icon">'
		. '<img src="' . esc_url( $icon_url ) . '" alt="" class="' . esc_attr( $img_class ) . '" width="32" height="32" decoding="async" />'
		. '</span>'
		. '<span class="colorify-login-brand__wordmark">' . esc_html( colorify_branding_site_name() ) . '</span>'
		. '</span>';
}

function colorify_branding_front_url(): string {
	if ( defined( 'WP_HOME' ) && WP_HOME ) {
		return trailingslashit( WP_HOME ) . 'pl';
	}
	return home_url( '/pl' );
}

function colorify_branding_login_assets(): void {
	wp_enqueue_style(
		'colorify-branding-fonts',
		'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'colorify-branding',
		COLORIFY_PLUGIN_URL . 'assets/colorify-branding.css',
		array( 'colorify-branding-fonts', 'login' ),
		COLORIFY_BRANDING_CSS_VERSION
	);
}
add_action( 'login_enqueue_scripts', 'colorify_branding_login_assets' );

add_filter(
	'login_headertext',
	static function (): string {
		return colorify_branding_login_brand_html();
	}
);

add_filter(
	'login_headerurl',
	static function (): string {
		return colorify_branding_front_url();
	}
);

function colorify_branding_login_credits(): void {
	printf(
		'<p class="colorify-login-credits">%s · <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
		esc_html( colorify_branding_style_name() ),
		esc_url( COLORIFY_CREDITS_URL ),
		esc_html( COLORIFY_CREDITS )
	);
}
add_action( 'login_footer', 'colorify_branding_login_credits', 20 );

function colorify_branding_login_message( string $message ): string {
	if ( '' !== $message ) {
		return $message;
	}

	return sprintf(
		'<p class="colorify-login-tagline">Panel CMS · %s</p>',
		esc_html( colorify_branding_site_name() )
	);
}
add_filter( 'login_message', 'colorify_branding_login_message' );

add_filter(
	'login_site_html_link',
	static function ( string $link ): string {
		$url = esc_url( colorify_branding_front_url() );
		return sprintf(
			'<a href="%s">← %s</a>',
			$url,
			esc_html( colorify_branding_site_name() )
		);
	}
);

/**
 * Czy bieżący ekran to edytor wyglądu (profil / ustawienia wtyczki).
 */
function colorify_is_appearance_editor_screen(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return $screen && in_array(
		$screen->id,
		array( 'profile', 'user-edit', 'settings_page_colorify-by-inyfinn' ),
		true
	);
}

/**
 * Pasek narzędzi — sam CSS (przełączniki = linki GET w PHP).
 *
 * @param int $context_user_id User ID.
 */
function colorify_enqueue_toolbar_assets( int $context_user_id = 0 ): void {
	if ( $context_user_id <= 0 ) {
		$context_user_id = get_current_user_id();
	}

	wp_enqueue_style(
		'colorify-admin-toolbar',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-toolbar.css',
		array(),
		COLORIFY_ADMIN_TOOLBAR_VER
	);

	if ( ! colorify_is_user_theme_enabled( $context_user_id ) ) {
		$scheme = colorify_get_effective_admin_color( $context_user_id );
		$mode   = colorify_get_effective_appearance_mode( $context_user_id );
		$def    = colorify_admin_get_resolved_scheme( $scheme );
		$tokens = colorify_admin_tokens_from_scheme( $mode, $def );
		$vars   = array();
		foreach ( array(
			'--colorify-admin-accent',
			'--colorify-admin-on-accent',
			'--colorify-admin-accent-soft',
			'--colorify-admin-text-muted',
			'--colorify-admin-highlight-bg',
			'--colorify-admin-highlight-text',
		) as $key ) {
			if ( isset( $tokens[ $key ] ) ) {
				$vars[] = $key . ':' . $tokens[ $key ];
			}
		}
		if ( $vars ) {
			wp_add_inline_style( 'colorify-admin-toolbar', ':root{' . implode( ';', $vars ) . '}' );
		}
	}
}

/**
 * JS edytora wyglądu — wyłącznie profil / ustawienia Colorify.
 *
 * @param int $context_user_id User ID.
 */
function colorify_enqueue_appearance_editor_script( int $context_user_id ): void {
	$appearance_deps = array( 'jquery' );
	if ( wp_script_is( 'user-profile', 'registered' ) ) {
		$appearance_deps[] = 'user-profile';
	}

	wp_enqueue_script(
		'colorify-admin-appearance',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-appearance.js',
		$appearance_deps,
		COLORIFY_APPEARANCE_JS_VERSION,
		true
	);

	$scheme = colorify_get_effective_admin_color( $context_user_id );

	wp_localize_script(
		'colorify-admin-appearance',
		'colorifyAdminAppearance',
		colorify_admin_appearance_script_config( $context_user_id, $scheme )
	);
}

/**
 * @param int    $context_user_id User ID.
 * @param string $scheme          Klucz schematu.
 * @return array<string,mixed>
 */
function colorify_admin_appearance_script_config( int $context_user_id, string $scheme ): array {
	$can_save_mode = true;
	$screen        = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( $screen && 'user-edit' === $screen->base && isset( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$edit_user = (int) $_GET['user_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit_user !== get_current_user_id() ) {
			$can_save_mode = false;
		}
	}

	return array(
		'scheme'          => $scheme,
		'themeEnabled'    => colorify_is_user_theme_enabled( $context_user_id ) ? '1' : '0',
		'mode'            => colorify_get_effective_appearance_mode( $context_user_id ),
		'schemes'         => colorify_admin_schemes_for_js(),
		'previews'        => colorify_admin_previews_for_js(),
		'schemeOrder'     => colorify_admin_scheme_order_for_js(),
		'customColors'    => colorify_get_effective_custom_colors( $context_user_id ),
		'customTuning'    => colorify_get_effective_custom_tuning( $context_user_id ),
		'tuningMin'       => COLORIFY_ADMIN_TUNING_MIN,
		'tuningMax'       => COLORIFY_ADMIN_TUNING_MAX,
		'tuningWarn'      => COLORIFY_ADMIN_TUNING_WARN,
		'tuningSensAnchorSoft'   => COLORIFY_ADMIN_TUNING_SENS_ANCHOR_SOFT,
		'tuningSensAnchorStrong' => COLORIFY_ADMIN_TUNING_SENS_ANCHOR_STRONG,
		'tuningSensRefLow'       => COLORIFY_ADMIN_TUNING_SENS_REF_LOW,
		'tuningSensRefHigh'      => COLORIFY_ADMIN_TUNING_SENS_REF_HIGH,
		'customSchemeKey' => COLORIFY_ADMIN_CUSTOM_SCHEME_KEY,
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'nonce'           => wp_create_nonce( 'colorify-admin-appearance' ),
		'canSaveMode'     => $can_save_mode,
		'settingsScope'   => colorify_get_settings_scope(),
		'scopeBundles'    => array(
			'global' => colorify_get_scope_appearance_bundle( 'global', $context_user_id ),
			'user'   => colorify_get_scope_appearance_bundle( 'user', $context_user_id ),
		),
		'canManageGlobal'       => current_user_can( 'manage_options' ) ? '1' : '0',
		'hasUserPersonalScheme' => colorify_user_has_personal_appearance( $context_user_id ) ? '1' : '0',
		'isSettingsPage'  => $screen && 'settings_page_colorify-by-inyfinn' === $screen->id,
		'modeBarLabel'    => colorify_i18n( 'Panel mode', 'Tryb panelu' ),
		'i18n'            => array(
			'dark'          => colorify_i18n( 'Dark', 'Ciemny' ),
			'light'         => colorify_i18n( 'Light', 'Jasny' ),
			'changeStyle'   => colorify_i18n( 'Change style', 'Zmień styl' ),
			'panelMode'     => colorify_i18n( 'Panel mode', 'Tryb panelu' ),
			'toggleMode'    => colorify_i18n( 'Toggle dark or light mode', 'Przełącz tryb jasny/ciemny' ),
			'themeOff'      => colorify_i18n( 'Off', 'Wył.' ),
			'themeOn'       => colorify_i18n( 'On', 'Wł.' ),
			'toggleTheme'   => colorify_i18n( 'Toggle Colorify styling', 'Włącz/wyłącz styl Colorify' ),
			'scopeGlobalHint' => colorify_i18n(
				'Preview: global site colors (login and default panel). Changes here update login.',
				'Podgląd: globalne kolory witryny (logowanie i domyślny panel). Zmiany tutaj aktualizują login.'
			),
			'scopeUserHint' => colorify_i18n(
				'Preview: your personal color scheme saved in profile.',
				'Podgląd: Twój osobisty schemat zapisany w profilu.'
			),
			'scopeUserDefaultHint' => colorify_i18n(
				'Preview: per-user mode. Pick a scheme below to save your personal style.',
				'Podgląd: tryb per użytkownik. Wybierz schemat poniżej, aby zapisać własny styl.'
			),
			'saving'     => colorify_i18n( 'Saving…', 'Zapisywanie…' ),
			'saved'      => colorify_i18n( 'Saved.', 'Zapisano.' ),
			'saveFailed' => colorify_i18n( 'Could not save.', 'Nie udało się zapisać.' ),
			'settingsScopeLabels' => array(
				'user'   => colorify_i18n(
					'Per user — each user sets colors in profile',
					'Per użytkownik — każdy ustawia kolory w profilu'
				),
				'global' => colorify_i18n(
					'Global default — fallback without personal style',
					'Globalne domyślne — fallback bez własnego stylu'
				),
			),
		),
		'schemesPanelUrl' => colorify_admin_schemes_panel_url(),
		'assets'          => array(
			'branding'  => add_query_arg( 'ver', COLORIFY_BRANDING_CSS_VERSION, COLORIFY_PLUGIN_URL . 'assets/colorify-branding.css' ),
			'overrides' => add_query_arg( 'ver', COLORIFY_ADMIN_OVERRIDES_VER, COLORIFY_PLUGIN_URL . 'assets/colorify-admin-overrides.css' ),
			'settings'  => add_query_arg(
				'ver',
				defined( 'COLORIFY_SETTINGS_CSS_VERSION' ) ? COLORIFY_SETTINGS_CSS_VERSION : COLORIFY_PLUGIN_VERSION,
				COLORIFY_PLUGIN_URL . 'assets/colorify-settings.css'
			),
		),
	);
}

/**
 * Główny CSS motywu — jeden plik na typowych stronach admina.
 */
function colorify_enqueue_branding_admin_css(): void {
	$style_deps = array();
	foreach ( array( 'colors', 'common', 'forms', 'list-tables' ) as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			$style_deps[] = $handle;
		}
	}

	wp_enqueue_style(
		'colorify-branding-admin',
		COLORIFY_PLUGIN_URL . 'assets/colorify-branding.css',
		$style_deps,
		COLORIFY_BRANDING_CSS_VERSION
	);
}

/**
 * CSS edytora (profil / ustawienia) — nie ładuj na Wtyczkach, Kokpicie itd.
 */
function colorify_enqueue_appearance_editor_styles(): void {
	wp_enqueue_style(
		'colorify-admin-overrides',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-overrides.css',
		array( 'colorify-branding-admin' ),
		COLORIFY_ADMIN_OVERRIDES_VER
	);

	wp_enqueue_style(
		'colorify-settings',
		COLORIFY_PLUGIN_URL . 'assets/colorify-settings.css',
		array( 'colorify-admin-overrides' ),
		defined( 'COLORIFY_SETTINGS_CSS_VERSION' ) ? COLORIFY_SETTINGS_CSS_VERSION : COLORIFY_PLUGIN_VERSION
	);
}

/**
 * Profil / ustawienia Colorify — sekcja personalizacji także przy wyłączonym motywie.
 */
function colorify_enqueue_personalization_assets_when_theme_off(): void {
	if ( colorify_is_user_theme_enabled() ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit', 'settings_page_colorify-by-inyfinn' ), true ) ) {
		return;
	}

	wp_enqueue_style(
		'colorify-admin-overrides',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-overrides.css',
		array( 'colorify-admin-toolbar' ),
		COLORIFY_ADMIN_OVERRIDES_VER
	);

	wp_enqueue_style(
		'colorify-settings',
		COLORIFY_PLUGIN_URL . 'assets/colorify-settings.css',
		array( 'colorify-admin-overrides' ),
		defined( 'COLORIFY_SETTINGS_CSS_VERSION' ) ? COLORIFY_SETTINGS_CSS_VERSION : COLORIFY_PLUGIN_VERSION
	);
}

/**
 * Admin assets — wspólne dla wp-admin i strony ustawień wtyczki.
 *
 * @param int $context_user_id User ID kontekstu zapisu/podglądu.
 */
function colorify_enqueue_admin_assets( int $context_user_id = 0 ): void {
	if ( $context_user_id <= 0 ) {
		$context_user_id = get_current_user_id();
	}

	colorify_enqueue_toolbar_assets( $context_user_id );

	if ( colorify_is_appearance_editor_screen() ) {
		colorify_enqueue_appearance_editor_script( $context_user_id );
	}

	if ( ! colorify_is_user_theme_enabled( $context_user_id ) ) {
		colorify_enqueue_personalization_assets_when_theme_off();
		return;
	}

	colorify_enqueue_branding_admin_css();

	if ( colorify_is_appearance_editor_screen() ) {
		colorify_enqueue_appearance_editor_styles();
	}
}

function colorify_branding_admin_assets(): void {
	colorify_enqueue_admin_assets();
}
add_action( 'admin_enqueue_scripts', 'colorify_branding_admin_assets', 100 );
add_action( 'customize_controls_enqueue_scripts', 'colorify_branding_admin_assets', 100 );

add_action(
	'admin_head',
	static function (): void {
		if ( ! is_admin() || ! colorify_is_user_theme_enabled() ) {
			return;
		}
		$url = esc_url( colorify_branding_site_icon_url( 512 ) );
		printf(
			'<style id="colorify-admin-bar-site-icon">#wpadminbar #wp-admin-bar-site-name > .ab-item::before{content:""!important;display:inline-block!important;width:18px!important;height:18px!important;margin:7px 6px 0 0!important;padding:0!important;background:url("%s") center/contain no-repeat!important;opacity:.92;float:left!important;}</style>',
			$url // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	},
	100
);

function colorify_admin_render_mode_switch(): void {
	if ( ! is_admin() || ! is_user_logged_in() ) {
		return;
	}

	static $rendered = false;
	if ( $rendered ) {
		return;
	}
	$rendered = true;

	echo '<div class="colorify-mode-switch-float" id="colorify-mode-switch-float">';
	echo colorify_admin_floating_toolbar_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';
}
add_action( 'admin_footer', 'colorify_admin_render_mode_switch', 1 );

add_action(
	'admin_head',
	static function (): void {
		$mode  = colorify_get_effective_appearance_mode();
		$color = 'light' === $mode ? '#fefefd' : '#050f0c';
		printf( '<meta name="theme-color" content="%s">', esc_attr( $color ) );
	}
);

/**
 * Wersja buildu w prawym dolnym rogu stopki (bump przy zmianach assetów).
 */
function colorify_admin_footer_version(): string {
	return COLORIFY_APPEARANCE_JS_VERSION;
}

add_filter(
	'admin_footer_text',
	static function (): string {
		return sprintf(
			'<span class="colorify-admin-footer-brand"><a class="colorify-admin-footer-credits" href="%s" target="_blank" rel="noopener noreferrer">%s</a> · %s CMS · <strong>Colorify</strong></span>',
			esc_url( COLORIFY_CREDITS_URL ),
			esc_html( COLORIFY_CREDITS ),
			esc_html( colorify_branding_site_name() )
		);
	}
);

/** Prawy dolny róg: copyright INYFINN + nazwa witryny + wersja Colorify (zamiast WP core). */
add_filter(
	'update_footer',
	static function (): string {
		return sprintf(
			'<span class="colorify-admin-footer-right">'
			. '<a class="colorify-admin-footer-credits" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>'
			. ' · <span class="colorify-admin-footer-site">%3$s</span>'
			. ' · <span class="colorify-admin-footer-version">Colorify v%4$s</span>'
			. '</span>',
			esc_url( COLORIFY_CREDITS_URL ),
			esc_html( COLORIFY_CREDITS ),
			esc_html( colorify_branding_site_name() ),
			esc_html( colorify_admin_footer_version() )
		);
	},
	20
);

/**
 * Link „Zmień kolory” na liście wtyczek.
 *
 * @param array<int,string> $links Istniejące linki akcji.
 * @return array<int,string>
 */
function colorify_plugin_action_links( array $links ): array {
	$profile_url  = admin_url( 'profile.php' );
	$settings_url = admin_url( 'options-general.php?page=colorify-by-inyfinn' );

	$profile_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $profile_url ),
		esc_html__( 'Moje kolory (profil)', 'colorify-by-inyfinn' )
	);
	array_unshift( $links, $profile_link );

	if ( current_user_can( 'manage_options' ) ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Ustawienia globalne', 'colorify-by-inyfinn' )
		);
		array_unshift( $links, $settings_link );
	}

	if ( current_user_can( 'update_plugins' ) && '' !== colorify_get_github_repo() ) {
		$cached     = colorify_get_cached_github_release();
		$has_update = is_array( $cached ) && ! empty( $cached['version'] )
			&& version_compare( COLORIFY_PLUGIN_VERSION, $cached['version'], '<' );
		$label      = $has_update
			? sprintf(
				/* translators: %s: new version number */
				__( 'Aktualizuj do %s', 'colorify-by-inyfinn' ),
				$cached['version']
			)
			: __( 'Aktualizuj', 'colorify-by-inyfinn' );

		$update_link = sprintf(
			'<a href="%1$s"%2$s>%3$s</a>',
			esc_url( colorify_get_manual_update_url( admin_url( 'plugins.php' ) ) ),
			$has_update ? ' class="colorify-plugin-update-link"' : '',
			$has_update ? '<strong>' . esc_html( $label ) . '</strong>' : esc_html( $label )
		);
		array_unshift( $links, $update_link );
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'colorify_plugin_action_links' );

/**
 * Ostrzeżenie przy aktywacji, gdy MU-plugin nadal działa.
 */
function colorify_activation_notice(): void {
	if ( ! colorify_mu_module_is_loaded() ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>Colorify by INYFINN:</strong> %s</p></div>',
		esc_html__(
			'Moduł MU mu-plugins/colorify/ jest jednocześnie załadowany. Dezaktywuj wtyczkę lub usuń colorify-loader.php.',
			'colorify-by-inyfinn'
		)
	);
}
add_action( 'admin_notices', 'colorify_activation_notice' );

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! get_option( COLORIFY_SCOPE_OPTION ) ) {
			update_option( COLORIFY_SCOPE_OPTION, 'user' );
		}
	}
);
