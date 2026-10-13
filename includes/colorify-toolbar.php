<?php
/**
 * Górny pasek: dwie zwykłe pozycje paska administratora (segment trybu i menu Colorify),
 * wspólne zasoby interfejsu (arkusz i skrypt) oraz zestaw ikon.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ikona z zestawu inline (symbole z colorify_ui_sprite()).
 *
 * @param string $name  Nazwa symbolu.
 * @param string $class Dodatkowa klasa.
 */
function colorify_ui_icon( string $name, string $class = '' ): string {
	return '<svg class="colorify-i' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" aria-hidden="true" focusable="false"><use href="#colorify-i-' . esc_attr( $name ) . '"/></svg>';
}

/**
 * Zestaw symboli SVG (jeden na stronę), na końcu strony panelu.
 */
function colorify_ui_sprite(): void {
	static $done = false;
	if ( $done || ! is_user_logged_in() ) {
		return;
	}
	$done = true;

	$logo = '';
	$file = COLORIFY_PLUGIN_DIR . 'assets/inyfinn-logo-okrag.svg';
	if ( is_readable( $file ) ) {
		$svg = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- plik wtyczki, nie adres.
		if ( is_string( $svg ) && preg_match( '#<g\b[^>]*>(.*)</g>#s', $svg, $m ) ) {
			$logo = '<symbol id="colorify-i-logo" viewBox="0 0 500 500"><g fill="currentColor" stroke="none">' . $m[1] . '</g></symbol>';
		}
	}

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- statyczne znaczniki wtyczki.
	echo '<svg id="colorify-sprite" style="display:none" aria-hidden="true" focusable="false">'
		. $logo
		. '<symbol id="colorify-i-moon" viewBox="0 0 24 24"><path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/></symbol>'
		. '<symbol id="colorify-i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>'
		. '<symbol id="colorify-i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>'
		. '<symbol id="colorify-i-chev" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>'
		. '<symbol id="colorify-i-power" viewBox="0 0 24 24"><path d="M12 3v9"/><path d="M6.3 6.3a8 8 0 1 0 11.4 0"/></symbol>'
		. '<symbol id="colorify-i-warn" viewBox="0 0 24 24"><path d="M12 3l10 18H2z"/><path d="M12 10v5M12 18.5v.5"/></symbol>'
		. '<symbol id="colorify-i-ext" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9"/><path d="M19 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h5"/></symbol>'
		. '</svg>' . "\n";
	// phpcs:enable
}
add_action( 'admin_footer', 'colorify_ui_sprite', 1 );

/**
 * Nazwa schematu obowiązującego użytkownika (do nagłówka menu paska).
 *
 * @param int $user_id Użytkownik.
 */
function colorify_ui_scheme_name( int $user_id = 0 ): string {
	$key  = colorify_get_effective_admin_color( $user_id );
	$defs = colorify_admin_scheme_definitions();

	return isset( $defs[ $key ]['name'] ) ? (string) $defs[ $key ]['name'] : $key;
}

/**
 * Pozycja segmentu: odnośnik z adresem GET (działa bez JS), skrypt przejmuje kliknięcie.
 *
 * @param string $mode    dark|light.
 * @param bool   $checked Czy wybrany.
 * @param string $label   Etykieta.
 * @param string $icon    Nazwa ikony.
 */
function colorify_toolbar_seg_option( string $mode, bool $checked, string $label, string $icon ): string {
	return '<a class="colorify-ab-seg__opt" role="radio" aria-checked="' . ( $checked ? 'true' : 'false' ) . '" tabindex="' . ( $checked ? '0' : '-1' ) . '" href="' . esc_url( colorify_toolbar_action_url( 'mode', $mode ) ) . '" data-colorify-mode="' . esc_attr( $mode ) . '">'
		. colorify_ui_icon( $icon )
		. '<span class="colorify-ab-label">' . esc_html( $label ) . '</span></a>';
}

/**
 * Pozycja podmenu: odnośnik z adresem GET i atrybutami dla skryptu.
 *
 * @param string $attrs   Atrybuty (już zabezpieczone).
 * @param string $href    Adres.
 * @param string $icon    Ikona.
 * @param string $label   Etykieta.
 * @param string $tail    Dodatkowy znacznik na końcu.
 * @param string $class   Klasy.
 */
function colorify_toolbar_sub_action( string $attrs, string $href, string $icon, string $label, string $tail = '', string $class = '' ): string {
	return '<a class="colorify-ab-act' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '" href="' . esc_url( $href ) . '" ' . $attrs . '>'
		. colorify_ui_icon( $icon )
		. '<span class="colorify-ab-label">' . esc_html( $label ) . '</span>' . $tail . '</a>';
}

/**
 * Dwie pozycje w grupie top-secondary: segment trybu i menu Colorify.
 *
 * @param WP_Admin_Bar $bar Pasek.
 */
function colorify_toolbar_admin_bar_menu( $bar ): void {
	// Tylko panel: na froncie strony kontrolek Colorify nie ma.
	if ( ! is_admin() || ! is_user_logged_in() || ! $bar instanceof WP_Admin_Bar ) {
		return;
	}

	$user_id = get_current_user_id();
	$active  = colorify_engine_is_active( $user_id );
	$mode    = colorify_get_effective_appearance_mode( $user_id );
	$mode_l  = __( 'Tryb panelu', 'colorify-by-inyfinn' );

	// Segment trybu stoi przed pozycją Colorify (grupa wyświetla pozycje w kolejności dodania). Tylko przy włączonym stylu; poniżej 783 px ukrywa go arkusz.
	if ( $active ) {
		$bar->add_node(
			array(
				'id'     => 'colorify-mode',
				'parent' => 'top-secondary',
				'title'  => '<span class="colorify-ab-seg" role="radiogroup" aria-label="' . esc_attr( $mode_l ) . '">'
					. colorify_toolbar_seg_option( 'dark', 'dark' === $mode, __( 'Ciemny', 'colorify-by-inyfinn' ), 'moon' )
					. colorify_toolbar_seg_option( 'light', 'light' === $mode, __( 'Jasny', 'colorify-by-inyfinn' ), 'sun' )
					. '</span>',
				'meta'   => array( 'class' => 'colorify-ab-mode' ),
			)
		);
	}

	$bar->add_node(
		array(
			'id'     => 'colorify',
			'parent' => 'top-secondary',
			'title'  => '<span class="colorify-ab-icon">' . colorify_ui_icon( 'logo', 'colorify-i--fill' ) . '</span>'
				. '<span class="colorify-ab-label">' . ( $active ? esc_html__( 'Colorify', 'colorify-by-inyfinn' ) : esc_html__( 'Colorify wyłączony', 'colorify-by-inyfinn' ) ) . '</span>'
				. colorify_ui_icon( 'chev', 'colorify-ab-chev' ),
			'href'   => '#colorify-menu',
			'meta'   => array( 'class' => 'colorify-ab-menu' . ( $active ? ' is-on' : ' is-off' ) ),
		)
	);

	if ( $active ) {
		$bar->add_node(
			array(
				'id'     => 'colorify-info',
				'parent' => 'colorify',
				'title'  => sprintf(
					/* translators: %s: nazwa schematu */
					esc_html__( 'Schemat: %s', 'colorify-by-inyfinn' ),
					'<strong class="colorify-ab-scheme">' . esc_html( colorify_ui_scheme_name( $user_id ) ) . '</strong>'
				),
				'meta'   => array( 'class' => 'colorify-ab-info' ),
			)
		);
	} else {
		$bar->add_node(
			array(
				'id'     => 'colorify-info',
				'parent' => 'colorify',
				'title'  => esc_html__( 'Styl wyłączony. Panel wygląda jak czysty WordPress.', 'colorify-by-inyfinn' ),
				'meta'   => array( 'class' => 'colorify-ab-info' ),
			)
		);
		$bar->add_node(
			array(
				'id'     => 'colorify-on',
				'parent' => 'colorify',
				'title'  => colorify_toolbar_sub_action(
					'data-colorify-toggle="1"',
					colorify_toolbar_action_url( 'theme', '1' ),
					'power',
					__( 'Włącz styl Colorify', 'colorify-by-inyfinn' ),
					'',
					'colorify-ab-primary'
				),
				'meta'   => array( 'class' => 'colorify-ab-row' ),
			)
		);
	}

	$bar->add_node(
		array(
			'id'     => 'colorify-change',
			'parent' => 'colorify',
			'title'  => esc_html__( 'Zmień styl', 'colorify-by-inyfinn' ),
			'href'   => admin_url( 'profile.php#colorify' ),
			'meta'   => array( 'class' => 'colorify-ab-row' ),
		)
	);

	// Para trybu w menu: na telefonie (segment ukryty) i przy wyłączonym stylu (segmentu nie ma).
	$tick = '<span class="colorify-ab-tick">' . colorify_ui_icon( 'check' ) . '</span>';
	$bar->add_node(
		array(
			'id'     => 'colorify-submode',
			'parent' => 'colorify',
			'title'  => '<span class="colorify-ab-submode" role="radiogroup" aria-label="' . esc_attr( $mode_l ) . '">'
				. '<a class="colorify-ab-act" role="radio" aria-checked="' . ( 'dark' === $mode ? 'true' : 'false' ) . '" tabindex="' . ( 'dark' === $mode ? '0' : '-1' ) . '" href="' . esc_url( colorify_toolbar_action_url( 'mode', 'dark' ) ) . '" data-colorify-mode="dark">'
				. colorify_ui_icon( 'moon' ) . '<span class="colorify-ab-label">' . esc_html__( 'Tryb ciemny', 'colorify-by-inyfinn' ) . '</span>' . $tick . '</a>'
				. '<a class="colorify-ab-act" role="radio" aria-checked="' . ( 'light' === $mode ? 'true' : 'false' ) . '" tabindex="' . ( 'light' === $mode ? '0' : '-1' ) . '" href="' . esc_url( colorify_toolbar_action_url( 'mode', 'light' ) ) . '" data-colorify-mode="light">'
				. colorify_ui_icon( 'sun' ) . '<span class="colorify-ab-label">' . esc_html__( 'Tryb jasny', 'colorify-by-inyfinn' ) . '</span>' . $tick . '</a>'
				. '</span>',
			'meta'   => array( 'class' => 'colorify-ab-row colorify-ab-submode-row' ),
		)
	);

	if ( $active ) {
		$bar->add_node(
			array(
				'id'     => 'colorify-off',
				'parent' => 'colorify',
				'title'  => colorify_toolbar_sub_action(
					'data-colorify-toggle="0"',
					colorify_toolbar_action_url( 'theme', '0' ),
					'power',
					__( 'Wyłącz styl Colorify', 'colorify-by-inyfinn' )
				),
				'meta'   => array( 'class' => 'colorify-ab-row' ),
			)
		);
	}
}
add_action( 'admin_bar_menu', 'colorify_toolbar_admin_bar_menu', 9 );

/**
 * Arkusz i skrypt interfejsu wtyczki: na każdym ekranie panelu (pasek), reszta tylko tam, gdzie jest znacznik.
 */
function colorify_ui_enqueue(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$user_id = get_current_user_id();
	$active  = colorify_engine_is_active( $user_id );

	$deps = array();
	foreach ( array( 'admin-bar', 'common', 'forms', 'buttons' ) as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) ) {
			$deps[] = $handle;
		}
	}
	// Po arkuszu ramy, żeby pozycje paska miały ostatnie słowo przy równej specyficzności.
	if ( wp_style_is( 'colorify-chrome', 'enqueued' ) ) {
		$deps[] = 'colorify-chrome';
	}

	wp_enqueue_style( 'colorify-ui', COLORIFY_PLUGIN_URL . 'assets/colorify-ui.css', $deps, COLORIFY_PLUGIN_VERSION );
	wp_enqueue_script( 'colorify-ui', COLORIFY_PLUGIN_URL . 'assets/colorify-ui.js', array( 'wp-a11y' ), COLORIFY_PLUGIN_VERSION, true );

	$decode = static function ( string $text ): string {
		return html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	};

	$config = array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'colorify-admin-appearance' ),
		'active'    => $active,
		'mode'      => colorify_get_effective_appearance_mode( $user_id ),
		'userId'    => $user_id,
		'homeUrl'   => home_url( '/' ),
		'i18n'      => array(
			'matchRun'     => $decode( __( 'Czytam stronę główną...', 'colorify-by-inyfinn' ) ),
			'matchError'   => $decode( __( 'Nie udało się odczytać strony głównej. Wybierz styl ręcznie.', 'colorify-by-inyfinn' ) ),
			'matchApply'   => $decode( __( 'Zastosuj', 'colorify-by-inyfinn' ) ),
			'matchCancel'  => $decode( __( 'Anuluj', 'colorify-by-inyfinn' ) ),
			'matchColors'  => $decode( __( 'Kolory', 'colorify-by-inyfinn' ) ),
			'matchHeading' => $decode( __( 'Nagłówki', 'colorify-by-inyfinn' ) ),
			'matchBody'    => $decode( __( 'Tekst', 'colorify-by-inyfinn' ) ),
			'matchSystem'  => $decode( __( 'systemowa', 'colorify-by-inyfinn' ) ),
			'matchRadius'  => $decode( __( 'Rogi', 'colorify-by-inyfinn' ) ),
			'matchFonts'   => $decode( __( 'Pliki czcionek z tej strony:', 'colorify-by-inyfinn' ) ),
			'matchNoFonts' => $decode( __( 'Strona ładuje czcionki z zewnętrznego serwera albo ich nie ma. Colorify użyje wtedy czcionki systemowej.', 'colorify-by-inyfinn' ) ),
			'matchNote'    => $decode( __( 'Kolory zostaną zapisane jako własna paleta witryny, a Twoje konto dostanie te same kolory.', 'colorify-by-inyfinn' ) ),
			'modeError'    => $decode( __( 'Nie udało się zmienić trybu. Spróbuj ponownie.', 'colorify-by-inyfinn' ) ),
			'styleError'   => $decode( __( 'Nie udało się zmienić stylu. Spróbuj ponownie.', 'colorify-by-inyfinn' ) ),
			'dismiss'      => $decode( __( 'Zamknij komunikat', 'colorify-by-inyfinn' ) ),
			'saving'       => $decode( __( 'Zapisywanie...', 'colorify-by-inyfinn' ) ),
			'saved'        => $decode( __( 'Zapisano.', 'colorify-by-inyfinn' ) ),
			'saveError'    => $decode( __( 'Nie udało się zapisać. Spróbuj ponownie.', 'colorify-by-inyfinn' ) ),
			'badHex'       => $decode( __( 'Wpisz kolor w zapisie #RRGGBB.', 'colorify-by-inyfinn' ) ),
			'customName'   => $decode( __( 'Własna paleta', 'colorify-by-inyfinn' ) ),
			'modeDark'     => $decode( __( 'Tryb ciemny', 'colorify-by-inyfinn' ) ),
			'modeLight'    => $decode( __( 'Tryb jasny', 'colorify-by-inyfinn' ) ),
			'tuneSame'     => $decode( __( 'Dostrojenie bez zmian.', 'colorify-by-inyfinn' ) ),
			'tuneChanged'  => $decode( __( 'Dostrojenie zmienione.', 'colorify-by-inyfinn' ) ),
			'tuneStateOff' => $decode( __( 'bez zmian', 'colorify-by-inyfinn' ) ),
			'tuneStateOn'  => $decode( __( 'zmienione', 'colorify-by-inyfinn' ) ),
			'captionDark'  => $decode( __( 'Tak będzie wyglądać panel w&nbsp;trybie ciemnym.', 'colorify-by-inyfinn' ) ),
			'captionLight' => $decode( __( 'Tak będzie wyglądać panel w&nbsp;trybie jasnym.', 'colorify-by-inyfinn' ) ),
		),
	);

	wp_add_inline_script( 'colorify-ui', 'window.colorifyUi = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';', 'before' );
}
add_action( 'admin_enqueue_scripts', 'colorify_ui_enqueue', 101 );

/**
 * Tuż przed wypisaniem stylów: zależności arkusza, które inna wtyczka zdążyła wyrejestrować (np. kreator WPForms
 * wyrzuca uchwyt "colors"), wypadają z listy. Inaczej WordPress pomija cały arkusz i ekrany wtyczki tracą styl.
 */
function colorify_ui_prune_missing_style_deps(): void {
	$styles = wp_styles();
	if ( ! isset( $styles->registered['colorify-ui'] ) ) {
		return;
	}

	$styles->registered['colorify-ui']->deps = array_values(
		array_filter(
			(array) $styles->registered['colorify-ui']->deps,
			static function ( $handle ) use ( $styles ) {
				return isset( $styles->registered[ $handle ] );
			}
		)
	);
}
add_action( 'admin_print_styles', 'colorify_ui_prune_missing_style_deps', 0 );
