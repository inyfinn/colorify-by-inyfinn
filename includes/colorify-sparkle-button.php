<?php
/**
 * Sparkle button — wspólny markup (login + toolbar).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * SVG sparkle — Uiverse.io by AlimurtuzaCodes (login).
 */
function colorify_login_btn_sparkle_svg(): string {
	return '<svg class="colorify-login-btn__sparkle" height="24" width="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
		. '<path d="M10,21.236,6.755,14.745.264,11.5,6.755,8.255,10,1.764l3.245,6.491L19.736,11.5l-6.491,3.245ZM18,21l1.5,3L21,21l3-1.5L21,18l-1.5-3L18,18l-3,1.5ZM19.333,4.667,20.5,7l1.167-2.333L24,3.5,21.667,2.333,20.5,0,19.333,2.333,17,3.5Z"></path>'
		. '</svg>';
}

/**
 * Markup login button — jeden element, bez warstw (Uiverse / AlimurtuzaCodes).
 */
function colorify_login_btn_inner_html(): string {
	return colorify_login_btn_sparkle_svg()
		. '<span class="colorify-login-btn__text"></span>';
}

/**
 * Wewnętrzna dekoracja — tylko toolbar „Zmień styl”.
 */
function colorify_sparkle_button_layers(): string {
	return '<span class="colorify-sparkle-button__spark" aria-hidden="true"></span>'
		. '<span class="colorify-sparkle-button__backdrop" aria-hidden="true"></span>'
		. colorify_sparkle_stars_svg();
}

/**
 * SVG gwiazdek — toolbar (JkHuger, kompakt).
 */
function colorify_sparkle_stars_svg(): string {
	return '<svg class="colorify-sparkle-button__stars" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
		. '<path d="M14 4l1.2 3.6L19 9l-3.8 1.4L14 14l-1.2-3.6L9 9l3.8-1.4L14 4z"/>'
		. '<path d="M6 6l.8 2.4L9 9l-2.2.8L6 12l-.8-2.2L3 9l2.2-.8L6 6z"/>'
		. '<path d="M18 14l.9 2.7L22 18l-2.7.9L18 22l-.9-2.7L14 18l2.7-.9L18 14z"/>'
		. '</svg>';
}

/**
 * Link „Zmień styl” ze sparkle (kompaktowy glow, overflow w wrapie).
 *
 * @param string $url   URL profilu / schematów.
 * @param string $label Etykieta.
 */
function colorify_sparkle_change_style_link_html( string $url, string $label ): string {
	return '<span class="colorify-sparkle-button-wrap colorify-sparkle-button-wrap--compact">'
		. '<a href="' . esc_url( $url ) . '" class="colorify-sparkle-button colorify-sparkle-button--compact colorify-change-style-btn">'
		. colorify_sparkle_button_layers()
		. colorify_admin_change_style_icon_html()
		. '<span class="colorify-sparkle-button__text colorify-change-style-btn__text">' . esc_html( $label ) . '</span>'
		. '</a>'
		. '</span>';
}

/**
 * Skrypt — podmienia #wp-submit na sparkle button (zachowuje name/id dla WP).
 */
function colorify_login_sparkle_submit_script(): void {
	?>
<script id="colorify-login-sparkle-submit">
(function () {
	// Przenieś wiersz submit na koniec formularza (pod „Remember Me”).
	var form = document.getElementById('loginform') || document.querySelector('#login form');
	if (form) {
		var submitRow = form.querySelector('p.submit');
		if (submitRow) {
			form.appendChild(submitRow);
		}
	}

	var input = document.getElementById('wp-submit');
	if (!input || input.classList.contains('colorify-login-btn') || input.dataset.colorifySparkleDone === '1') {
		return;
	}
	input.dataset.colorifySparkleDone = '1';

	var btn = document.createElement('button');
	btn.type = 'submit';
	btn.name = input.name || 'wp-submit';
	btn.id = 'wp-submit';
	btn.className = 'colorify-login-btn';
	btn.dataset.colorifySparkleDone = '1';
	btn.innerHTML = <?php echo wp_json_encode( colorify_login_btn_inner_html() ); ?>;

	var textEl = btn.querySelector('.colorify-login-btn__text');
	if (textEl) {
		textEl.textContent = input.value;
	}

	var parent = input.parentNode;
	if (!parent) {
		return;
	}
	parent.replaceChild(btn, input);
})();
</script>
	<?php
}
add_action( 'login_footer', 'colorify_login_sparkle_submit_script', 5 );

/**
 * Enqueue sparkle CSS (+ login JS niepotrzebny poza inline powyżej).
 */
function colorify_enqueue_sparkle_button_assets(): void {
	wp_enqueue_style(
		'colorify-sparkle-button',
		COLORIFY_PLUGIN_URL . 'assets/colorify-sparkle-button.css',
		array(),
		COLORIFY_PLUGIN_VERSION
	);
}

function colorify_enqueue_login_sparkle_assets(): void {
	colorify_enqueue_sparkle_button_assets();
}
add_action( 'login_enqueue_scripts', 'colorify_enqueue_login_sparkle_assets', 25 );

function colorify_enqueue_admin_sparkle_assets(): void {
	if ( ! is_admin() ) {
		return;
	}
	colorify_enqueue_sparkle_button_assets();
}
add_action( 'admin_enqueue_scripts', 'colorify_enqueue_admin_sparkle_assets', 99 );
