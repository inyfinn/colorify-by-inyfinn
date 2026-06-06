<?php
/**
 * Sparkle button — wspólny markup (login + toolbar).
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * SVG gwiazdek (Uiverse / JkHuger — uproszczone pod Colorify).
 */
function colorify_sparkle_stars_svg(): string {
	return '<svg class="colorify-sparkle-button__stars" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">'
		. '<path d="M14 4l1.2 3.6L19 9l-3.8 1.4L14 14l-1.2-3.6L9 9l3.8-1.4L14 4z"/>'
		. '<path d="M6 6l.8 2.4L9 9l-2.2.8L6 12l-.8-2.2L3 9l2.2-.8L6 6z"/>'
		. '<path d="M18 14l.9 2.7L22 18l-2.7.9L18 22l-.9-2.7L14 18l2.7-.9L18 14z"/>'
		. '</svg>';
}

/**
 * Wewnętrzna dekoracja suwaka (spark + backdrop).
 */
function colorify_sparkle_button_layers(): string {
	return '<span class="colorify-sparkle-button__spark" aria-hidden="true"></span>'
		. '<span class="colorify-sparkle-button__backdrop" aria-hidden="true"></span>'
		. colorify_sparkle_stars_svg();
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
	var input = document.getElementById('wp-submit');
	if (!input || input.dataset.colorifySparkleDone === '1') {
		return;
	}
	input.dataset.colorifySparkleDone = '1';

	var wrap = document.createElement('span');
	wrap.className = 'colorify-sparkle-button-wrap colorify-sparkle-button-wrap--login';

	var btn = document.createElement('button');
	btn.type = 'submit';
	btn.name = input.name || 'wp-submit';
	btn.id = 'wp-submit';
	btn.className = input.className.replace(/\bbutton-primary\b/g, '').trim()
		+ ' colorify-sparkle-button colorify-sparkle-button--login button button-primary button-large';
	btn.innerHTML = <?php echo wp_json_encode( colorify_sparkle_button_layers() ); ?>
		+ '<span class="colorify-sparkle-button__text"></span>';

	btn.querySelector('.colorify-sparkle-button__text').textContent = input.value;

	var parent = input.parentNode;
	if (!parent) {
		return;
	}
	parent.insertBefore(wrap, input);
	wrap.appendChild(btn);
	input.remove();
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
