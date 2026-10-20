<?php
/**
 * Ustawienia → Colorify: zakres, wygląd witryny, aktualizacja.
 * Układ i teksty: design/SPEC.md rozdział 3.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strona ustawień wtyczki.
 */
final class Colorify_Settings {

	const SLUG = 'colorify-by-inyfinn';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'handle_save' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'Colorify by INYFINN', 'colorify-by-inyfinn' ),
			__( 'Colorify', 'colorify-by-inyfinn' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Adres ekranu ustawień.
	 *
	 * @param array<string,string> $args Dodatkowe parametry.
	 */
	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Zapis zakresu i wyglądu witryny (klasyczny POST). Wygląd witryny zapisuje się zawsze:
	 * dotyczy logowania i osób bez własnego wyboru, niezależnie od zakresu.
	 */
	public static function handle_save(): void {
		if ( ! isset( $_POST['colorify_settings_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'colorify_settings_save', 'colorify_settings_nonce' );

		$scope = isset( $_POST['colorify_settings_scope'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_key( wp_unslash( $_POST['colorify_settings_scope'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: colorify_get_settings_scope();

		if ( ! in_array( $scope, array( 'user', 'global' ), true ) ) {
			$scope = 'user';
		}

		update_option( COLORIFY_SCOPE_OPTION, $scope );
		if ( isset( $_POST['colorify_skin_preset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// Zmienia się tylko wybór skóry: pomiar strony (czcionki, rogi) zostaje zapisany.
			$skin           = colorify_get_skin();
			$skin['preset'] = sanitize_key( wp_unslash( $_POST['colorify_skin_preset'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			update_option( COLORIFY_SKIN_OPTION, colorify_skin_sanitize( $skin ) );
		}
		colorify_save_global_appearance( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- pola przechodzą sanitize_* w funkcji zapisu.
		if ( 'global' === $scope ) {
			colorify_clear_user_appearance( get_current_user_id() );
		}

		wp_safe_redirect( self::page_url( array( 'colorify_saved' => '1' ) ) );
		exit;
	}

	/**
	 * Komunikaty tego ekranu jako zwykłe .notice rdzenia: WordPress przenosi je pod nagłówek.
	 */
	public static function render_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'settings_page_' . self::SLUG !== $screen->id ) {
			return;
		}

		if ( isset( $_GET['colorify_saved'] ) && '1' === $_GET['colorify_saved'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ustawienia zapisane.', 'colorify-by-inyfinn' ) . '</p></div>';
		}
	}

	/**
	 * Tekst "Aktualna wersja..." (gotowy do wstawienia, zabezpieczony).
	 */
	private static function ok_text(): string {
		// Czas ostatniego sprawdzania listy aktualizacji przez WordPressa (też po "Sprawdź aktualizacje").
		$updates = get_site_transient( 'update_plugins' );
		$last    = ( is_object( $updates ) && isset( $updates->last_checked ) ) ? (int) $updates->last_checked : 0;
		if ( $last <= 0 ) {
			return esc_html__( 'Nie sprawdzano jeszcze.', 'colorify-by-inyfinn' );
		}

		$time = wp_date( 'H:i', $last );
		if ( wp_date( 'Y-m-d', $last ) === wp_date( 'Y-m-d' ) ) {
			/* translators: %s: godzina HH:MM */
			return sprintf( esc_html__( 'Aktualna wersja. Sprawdzono dziś o&nbsp;%s.', 'colorify-by-inyfinn' ), esc_html( $time ) );
		}

		/* translators: 1: data, 2: godzina HH:MM */
		return sprintf( esc_html__( 'Aktualna wersja. Sprawdzono %1$s o&nbsp;%2$s.', 'colorify-by-inyfinn' ), esc_html( wp_date( 'j.m.Y', $last ) ), esc_html( $time ) );
	}

	/**
	 * Tekst "Dostępna wersja X." (zabezpieczony).
	 *
	 * @param string $version Numer wersji.
	 */
	private static function new_text( string $version ): string {
		/* translators: %s: numer wersji */
		return sprintf( esc_html__( 'Dostępna wersja %s.', 'colorify-by-inyfinn' ), esc_html( $version ) );
	}

	/**
	 * Trzy mini-ramki (zakres: wiele wyglądów albo jeden) i miniatura ekranu logowania.
	 *
	 * @param array<int,array<string,mixed>> $defs  Definicje schematów.
	 * @param bool                           $login Czy dołożyć mini ekran logowania.
	 */
	private static function scope_pic( array $defs, bool $login ): string {
		$out         = '';
		$first_style = '';

		foreach ( $defs as $def ) {
			$style       = colorify_ui_preview_style(
				array(
					'dark'  => colorify_build_tokens( $def, 'dark' ),
					'light' => colorify_build_tokens( $def, 'light' ),
				)
			);
			$first_style = '' === $first_style ? $style : $first_style;
			$out        .= colorify_ui_preview_html( $style, 'colorify-preview--dark' );
		}

		if ( $login && '' !== $first_style ) {
			$out .= '<span class="colorify-pic-sep"></span><span class="colorify-login" data-colorify-swatch style="' . esc_attr( $first_style ) . '"><i></i></span>';
		}

		return $out;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$scope       = colorify_get_settings_scope();
		$bundle      = colorify_get_scope_appearance_bundle( 'global' );
		$github_repo = Colorify_Updater::repo();
		$release     = Colorify_Updater::cached_release();
		$status      = Colorify_Updater::status();
		$has_update  = 'update' === $status['state'] && '' !== $status['version'];
		$version_new = $has_update ? $status['version'] : '';
		$release_url = is_array( $release ) && ! empty( $release['url'] )
			? (string) $release['url']
			: ( '' !== $github_repo ? 'https://github.com/' . $github_repo . '/releases' : '' );
		$basename    = plugin_basename( COLORIFY_PLUGIN_FILE );
		// Ścieżka zbiorcza rdzenia (plugins.php, action=update-selected, nonce bulk-plugins): bulk_upgrade() nie wyłącza
		// wtyczki na czas instalacji i sprawdza "requires". update.php?action=upgrade-plugin wyłączałoby Colorify przed
		// pobraniem i zostawiało wyłączony po każdym błędzie (a wyłączony Colorify nie ma updatera).
		$update_url  = wp_nonce_url( self_admin_url( 'plugins.php?action=update-selected&plugins=' . rawurlencode( $basename ) ), 'bulk-plugins' );

		// Trzy różne wyglądy (zakres "osobno") i trzy takie same plus logowanie (zakres "jeden wygląd").
		$defs        = colorify_admin_scheme_definitions();
		$sample_defs = array();
		foreach ( array( 'colorify-lime', 'colorify-basil', 'colorify-sky' ) as $key ) {
			if ( isset( $defs[ $key ] ) ) {
				$sample_defs[] = $defs[ $key ];
			}
		}
		$global_def = colorify_admin_get_resolved_global_scheme();
		$pic_user   = self::scope_pic( $sample_defs, false );
		$pic_global = self::scope_pic( array( $global_def, $global_def, $global_def ), true );

		// Klasa colorify-ui tylko na własnych blokach: WordPress wstawia cudze komunikaty (np. baner motywu) w .wrap
		// zaraz za hr.wp-header-end, więc opakowanie .wrap nie może nosić naszych reguł przycisków i typografii.
		echo '<div class="wrap colorify-wrap">';
		echo '<div class="colorify-ui colorify-screen-head"><h1 class="colorify-h1">' . colorify_ui_icon( 'logo', 'colorify-h1__logo colorify-i--fill' ) . esc_html__( 'Colorify', 'colorify-by-inyfinn' ) . '</h1></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<hr class="wp-header-end">';
		echo '<div class="colorify-ui colorify-screen" data-view="' . esc_attr( $bundle['mode'] ) . '">';

		echo '<form method="post" action="" class="colorify-form" id="colorify-settings-form">';
		wp_nonce_field( 'colorify_settings_save', 'colorify_settings_nonce' );
		echo '<input type="hidden" name="colorify_settings_save" value="1">';

		colorify_ui_offstrip( esc_html__( 'Styl Colorify jest wyłączony dla Twojego konta (przełącznik w&nbsp;górnym pasku). Ustawienia witryny poniżej działają normalnie dla pozostałych osób.', 'colorify-by-inyfinn' ) );

		// Kto wybiera kolory.
		echo '<section class="colorify-card" aria-labelledby="colorify-scope-title">';
		echo '<p class="colorify-label">' . esc_html__( 'Zakres', 'colorify-by-inyfinn' ) . '</p>';
		echo '<h2 id="colorify-scope-title">' . esc_html__( 'Kto wybiera kolory panelu', 'colorify-by-inyfinn' ) . '</h2>';
		echo '<div class="colorify-scope" role="radiogroup" aria-labelledby="colorify-scope-title">';
		printf(
			'<label class="colorify-scope__opt"><input type="radio" name="colorify_settings_scope" value="user" %1$s><span class="colorify-scope__title">%2$s</span><p class="colorify-scope__hint">%3$s</p><span class="colorify-scope__pic" aria-hidden="true">%4$s</span></label>',
			checked( $scope, 'user', false ),
			esc_html__( 'Każdy użytkownik osobno', 'colorify-by-inyfinn' ),
			esc_html__( 'Każdy wybiera schemat w&nbsp;swoim profilu. Osoby bez wyboru widzą domyślny wygląd wtyczki.', 'colorify-by-inyfinn' ),
			$pic_user // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		printf(
			'<label class="colorify-scope__opt"><input type="radio" name="colorify_settings_scope" value="global" %1$s><span class="colorify-scope__title">%2$s</span><p class="colorify-scope__hint">%3$s</p><span class="colorify-scope__pic" aria-hidden="true">%4$s</span></label>',
			checked( $scope, 'global', false ),
			esc_html__( 'Jeden wygląd dla całej witryny', 'colorify-by-inyfinn' ),
			esc_html__( 'Poniższy wygląd dostaną osoby bez własnego wyboru w&nbsp;profilu oraz strona logowania.', 'colorify-by-inyfinn' ),
			$pic_global // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
		echo '</div></section>';

		// Wygląd witryny: widoczny zawsze (logowanie i osoby bez własnego wyboru).
		echo '<section class="colorify-card" id="colorify-global-look" aria-labelledby="colorify-global-title">';
		echo '<p class="colorify-label">' . esc_html__( 'Kolory', 'colorify-by-inyfinn' ) . '</p>';
		echo '<h2 id="colorify-global-title">' . esc_html__( 'Wygląd witryny', 'colorify-by-inyfinn' ) . '</h2>';
		echo '<p class="colorify-desc">' . esc_html__( 'Dla osób bez własnego wyboru w&nbsp;profilu i&nbsp;dla strony logowania. Każdy nadal może wybrać co innego u&nbsp;siebie.', 'colorify-by-inyfinn' ) . '</p>';
		colorify_ui_render_picker(
			array(
				'context'     => 'settings',
				'bundle'      => $bundle,
				'scope'       => 'global',
				'show_scope'  => false,
				'target_user' => get_current_user_id(),
				'actions'     => false,
			)
		);
		echo '</section>';

		// Skóra: czcionki i rogi ekranów Colorify i strony logowania.
		$skin      = colorify_get_skin();
		$stored    = colorify_skin_sanitize( get_option( COLORIFY_SKIN_OPTION, array() ) );
		$measured  = '' !== $stored['body'];
		$body_name = str_replace( '"', '', (string) strtok( $stored['body'], ',' ) );
		$body_name = preg_match( '/^(-apple-system|BlinkMacSystemFont|system-ui|ui-sans-serif|ui-serif|sans-serif|serif)$/i', $body_name ) ? __( 'czcionka systemowa', 'colorify-by-inyfinn' ) : $body_name;
		$site_hint = $measured
			/* translators: 1: czcionka tekstu, 2: zaokrąglenie w px */
			? sprintf( __( 'Zmierzone na stronie głównej: %1$s, rogi %2$d px.', 'colorify-by-inyfinn' ), $body_name, $stored['radius'] )
			: __( 'Najpierw użyj przycisku „Dopasuj do strony”.', 'colorify-by-inyfinn' );
		$skins     = array(
			'neutral'    => array( __( 'Neutralny', 'colorify-by-inyfinn' ), __( 'Czcionka panelu WordPressa, lekko zaokrąglone rogi. Pasuje do każdej strony.', 'colorify-by-inyfinn' ) ),
			'site'       => array( __( 'Jak strona', 'colorify-by-inyfinn' ), $site_hint ),
			'kancelaria' => array( __( 'Kancelaria', 'colorify-by-inyfinn' ), __( 'EB Garamond i IBM Plex Sans, ostre rogi.', 'colorify-by-inyfinn' ) ),
		);
		echo '<section class="colorify-card" id="colorify-skin" aria-labelledby="colorify-skin-title">';
		echo '<p class="colorify-label">' . esc_html__( 'Styl', 'colorify-by-inyfinn' ) . '</p>';
		echo '<h2 id="colorify-skin-title">' . esc_html__( 'Czcionki i kształt', 'colorify-by-inyfinn' ) . '</h2>';
		echo '<p class="colorify-desc">' . esc_html__( 'Dla ekranów Colorify i&nbsp;strony logowania. Reszta panelu zostaje przy czcionce WordPressa.', 'colorify-by-inyfinn' ) . '</p>';
		echo '<div class="colorify-scope colorify-skins" role="radiogroup" aria-labelledby="colorify-skin-title">';
		foreach ( $skins as $key => $row ) {
			printf(
				'<label class="colorify-scope__opt"><input type="radio" name="colorify_skin_preset" value="%1$s" %2$s %3$s><span class="colorify-scope__title">%4$s</span><p class="colorify-scope__hint">%5$s</p></label>',
				esc_attr( $key ),
				checked( $skin['preset'], $key, false ),
				disabled( 'site' === $key && ! $measured, true, false ),
				esc_html( $row[0] ),
				esc_html( $row[1] )
			);
		}
		echo '</div>';
		echo '<div class="colorify-match">';
		echo '<button type="button" class="button" id="colorify-match-run">' . esc_html__( 'Dopasuj do strony', 'colorify-by-inyfinn' ) . '</button>';
		echo '<span class="colorify-match__hint">' . esc_html__( 'Odczytuje kolory, czcionki i&nbsp;rogi przycisków ze strony głównej. Nic nie zapisuje, dopóki nie zatwierdzisz.', 'colorify-by-inyfinn' ) . '</span>';
		echo '<div class="colorify-match__result" id="colorify-match-result" role="status" aria-live="polite" hidden></div>';
		echo '</div>';
		echo '</section>';

		// Twoje kolory: tylko przy zakresie "osobno" (wskazówka, gdzie wybiera się kolory panelu).
		echo '<section class="colorify-card" id="colorify-user-look" aria-labelledby="colorify-user-title"' . ( 'user' === $scope ? '' : ' hidden' ) . '>';
		echo '<h2 id="colorify-user-title">' . esc_html__( 'Twoje kolory', 'colorify-by-inyfinn' ) . '</h2>';
		echo '<p class="colorify-desc">' . esc_html__( 'Kolory panelu wybiera się w&nbsp;profilu, tak samo jak każdy użytkownik.', 'colorify-by-inyfinn' ) . '</p>';
		echo '<a class="button" href="' . esc_url( admin_url( 'profile.php#colorify' ) ) . '">' . esc_html__( 'Otwórz mój profil', 'colorify-by-inyfinn' ) . '</a>';
		echo '</section>';

		echo '<div class="colorify-actions">'
			. '<button type="submit" class="button button-primary button-large" id="colorify-save">' . esc_html__( 'Zapisz ustawienia', 'colorify-by-inyfinn' ) . '</button>'
			. '<span class="colorify-status" id="colorify-status" data-state="idle" role="status" aria-live="polite"></span>'
			. '</div>';
		echo '</form>';

		// Aktualizacja: jeden wiersz pod formularzem.
		echo '<section class="colorify-update' . ( $has_update ? ' has-update' : '' ) . '" id="colorify-update" aria-labelledby="colorify-update-title">';
		echo '<h2 id="colorify-update-title" class="screen-reader-text">' . esc_html__( 'Aktualizacja', 'colorify-by-inyfinn' ) . '</h2>';
		echo '<span class="colorify-update__ver">' . esc_html( sprintf( /* translators: %s: wersja */ __( 'Colorify %s', 'colorify-by-inyfinn' ), COLORIFY_PLUGIN_VERSION ) ) . '</span>';
		echo '<span class="colorify-update__ok" id="colorify-update-ok">' . self::ok_text() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="colorify-update__new" id="colorify-update-new">' . ( $has_update ? self::new_text( $version_new ) : '' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="colorify-update__actions">';
		if ( '' !== $release_url ) {
			echo '<a href="' . esc_url( $release_url ) . '" id="colorify-release-link" target="_blank" rel="noopener">' . esc_html__( 'Lista zmian na GitHubie', 'colorify-by-inyfinn' ) . ' ' . colorify_ui_icon( 'ext' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( current_user_can( 'update_plugins' ) ) {
			echo '<a class="button colorify-update__ok" id="colorify-check-update" href="' . esc_url( Colorify_Updater::check_url( 'settings' ) ) . '">' . esc_html__( 'Sprawdź aktualizacje', 'colorify-by-inyfinn' ) . '</a>';
			echo '<a class="button button-primary colorify-update__new" id="colorify-run-update" href="' . esc_url( $update_url ) . '">'
				. esc_html( $has_update ? sprintf( /* translators: %s: wersja */ __( 'Aktualizuj do %s', 'colorify-by-inyfinn' ), $version_new ) : __( 'Aktualizuj', 'colorify-by-inyfinn' ) )
				. '</a>';
		}
		echo '</div>';
		echo '</section>';

		echo '<p class="colorify-footer">' . esc_html__( 'Colorify by INYFINN', 'colorify-by-inyfinn' ) . ' &middot; <a href="' . esc_url( COLORIFY_CREDITS_URL ) . '" target="_blank" rel="noopener">inyfinn.art</a></p>';
		echo '</div>';
		echo '</div>';
	}
}
