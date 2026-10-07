<?php
/**
 * Pasek przełączników w panelu: zasoby i wyświetlanie.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pasek narzędzi — CSS + JS (przełączniki = natychmiastowy AJAX).
 *
 * @param int $context_user_id User ID.
 */
function colorify_enqueue_toolbar_assets( int $context_user_id = 0 ): void {
	if ( $context_user_id <= 0 ) {
		$context_user_id = get_current_user_id();
	}

	colorify_enqueue_sparkle_button_assets();

	wp_enqueue_style(
		'colorify-switch',
		COLORIFY_PLUGIN_URL . 'assets/colorify-switch.css',
		array(),
		COLORIFY_PLUGIN_VERSION
	);

	wp_enqueue_style(
		'colorify-admin-toolbar',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-toolbar.css',
		array( 'colorify-switch', 'colorify-sparkle-button' ),
		COLORIFY_PLUGIN_VERSION
	);

	wp_enqueue_script(
		'colorify-admin-toolbar',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-toolbar.js',
		array(),
		COLORIFY_PLUGIN_VERSION,
		true
	);

	wp_localize_script(
		'colorify-admin-toolbar',
		'colorifyAdminToolbar',
		array(
			'mode'            => colorify_get_effective_appearance_mode( $context_user_id ),
			'themeEnabled'    => colorify_is_user_theme_enabled( $context_user_id ) ? '1' : '0',
			'settingsScope'   => colorify_get_settings_scope(),
			'canManageGlobal' => current_user_can( 'manage_options' ) ? '1' : '0',
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'colorify-admin-appearance' ),
			'assets'          => array(
				'branding'  => add_query_arg( 'ver', COLORIFY_PLUGIN_VERSION, COLORIFY_PLUGIN_URL . 'assets/colorify-branding.css' ),
				'overrides' => add_query_arg( 'ver', COLORIFY_PLUGIN_VERSION, COLORIFY_PLUGIN_URL . 'assets/colorify-admin-overrides.css' ),
			),
		)
	);

	// Krytyczne pozycjonowanie paska (fallback gdy cache/encoding CSS).
	wp_add_inline_style(
		'colorify-admin-toolbar',
		'.colorify-mode-switch-float{position:fixed!important;top:0!important;right:148px!important;left:auto!important;height:32px!important;display:flex!important;align-items:center!important;justify-content:flex-end!important;z-index:100001!important;pointer-events:none!important;max-width:calc(100vw - 320px)!important}'
		. '.colorify-mode-switch-float .colorify-admin-toolbar{display:flex!important;align-items:center!important;gap:20px!important;pointer-events:auto!important;white-space:nowrap!important}'
		. '.colorify-toolbar-switch,.colorify-mode-switch,.colorify-theme-switch{display:inline-flex!important;align-items:center!important;gap:10px!important;flex-flow:row nowrap!important}'
		. '.colorify-mode-switch-float .colorify-sparkle-button.colorify-change-style-btn{background:var(--colorify-admin-accent,#b4e717)!important;color:var(--colorify-admin-on-accent,#050f0c)!important;border:1px solid color-mix(in srgb,var(--colorify-admin-accent,#b4e717) 82%,#000)!important}'
		. 'body.wp-admin.colorify-admin-light .colorify-toolbar-switch,body.wp-admin.colorify-admin-light .colorify-mode-switch,body.wp-admin.colorify-admin-light .colorify-theme-switch{color:var(--colorify-admin-text,#18181b)!important;background:color-mix(in srgb,var(--colorify-admin-accent,#b4e717) 12%,var(--colorify-admin-bg,#fafafa))!important}'
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
			'--colorify-admin-text',
			'--colorify-admin-text-muted',
			'--colorify-admin-bg',
			'--colorify-admin-border',
			'--colorify-admin-field',
			'--colorify-admin-surface-2',
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
 * Wyświetla pasek przełączników.
 */
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
add_action( 'in_admin_header', 'colorify_admin_render_mode_switch', 1 );
add_action( 'admin_footer', 'colorify_admin_render_mode_switch', 1 );

/**
 * Zasoby interfejsu wtyczki w panelu: pasek przełączników wszędzie, edytor wyglądu w profilu i ustawieniach.
 */
function colorify_enqueue_admin_ui(): void {
	$user_id = get_current_user_id();

	colorify_enqueue_toolbar_assets( $user_id );

	if ( ! colorify_is_appearance_editor_screen() ) {
		return;
	}

	colorify_enqueue_appearance_editor_script( $user_id );

	if ( ! colorify_is_user_theme_enabled( $user_id ) ) {
		colorify_enqueue_personalization_assets_when_theme_off();
		return;
	}

	wp_enqueue_style(
		'colorify-admin-overrides',
		COLORIFY_PLUGIN_URL . 'assets/colorify-admin-overrides.css',
		array(),
		COLORIFY_PLUGIN_VERSION
	);
	colorify_enqueue_appearance_editor_styles();
}
add_action( 'admin_enqueue_scripts', 'colorify_enqueue_admin_ui', 100 );
