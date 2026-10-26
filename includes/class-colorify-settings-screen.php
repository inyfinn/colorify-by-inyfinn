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
		colorify_save_admin_style_from_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Colorify_Dashboard::save_from_input( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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

		// Jeden ekran: kto widzi, skąd kolory (schematy, własna paleta, strona), dostrojenie. Bez osobnej sekcji czcionek:
		// czcionki i rogi przychodzą razem z "Dopasuj do mojej strony".
		echo '<section class="colorify-card colorify-main" id="colorify-global-look" aria-labelledby="colorify-global-title">';
		echo '<div class="colorify-main__head">';
		echo '<div class="colorify-main__title"><h2 id="colorify-global-title">' . esc_html__( 'Kolory panelu', 'colorify-by-inyfinn' ) . '</h2>';
		echo '<p class="colorify-desc" id="colorify-scope-desc" data-user="' . esc_attr__( 'Każdy wybiera kolory w swoim profilu. Ten wygląd dostają osoby bez wyboru i strona logowania.', 'colorify-by-inyfinn' ) . '" data-global="' . esc_attr__( 'Ten wygląd widzą wszyscy w panelu i strona logowania.', 'colorify-by-inyfinn' ) . '">'
			. ( 'global' === $scope ? esc_html__( 'Ten wygląd widzą wszyscy w panelu i strona logowania.', 'colorify-by-inyfinn' ) : esc_html__( 'Każdy wybiera kolory w swoim profilu. Ten wygląd dostają osoby bez wyboru i strona logowania.', 'colorify-by-inyfinn' ) )
			. '</p></div>';
		echo '<div class="colorify-main__tools">';
		echo '<div class="colorify-seg colorify-scope-seg" role="radiogroup" aria-label="' . esc_attr__( 'Kto widzi ten wygląd', 'colorify-by-inyfinn' ) . '">';
		foreach ( array( 'global' => __( 'Dla wszystkich', 'colorify-by-inyfinn' ), 'user' => __( 'Każdy osobno', 'colorify-by-inyfinn' ) ) as $value => $label ) {
			printf(
				'<label class="colorify-seg__opt" aria-checked="%3$s"><input type="radio" class="screen-reader-text" name="colorify_settings_scope" value="%1$s" %4$s>%2$s</label>',
				esc_attr( $value ),
				esc_html( $label ),
				$scope === $value ? 'true' : 'false',
				checked( $scope, $value, false )
			);
		}
		echo '</div>';
		echo '</div></div>';

		// Styl panelu (Klasyczny / Nowoczesny) i komunikaty.
		$style  = colorify_get_admin_style();
		$hidden = count( colorify_get_dismissed_notices( get_current_user_id() ) );
		echo '<div class="colorify-site colorify-style-row">';
		echo '<div class="colorify-site__text"><p class="colorify-site__title">' . esc_html__( 'Styl panelu', 'colorify-by-inyfinn' ) . '</p>';
		echo '<p class="colorify-site__hint">' . esc_html__( 'Nowoczesny: zaokrąglone karty bez ramek, miękkie pola i przyciski, spokojne tabele i jednolite komunikaty w całym panelu. Klasyczny: wygląd WordPressa.', 'colorify-by-inyfinn' ) . '</p>';
		echo '<p class="colorify-site__now">';
		/* translators: %d: liczba ukrytych komunikatów */
		echo esc_html( sprintf( _n( 'Ukryty komunikat: %d.', 'Ukryte komunikaty: %d.', $hidden, 'colorify-by-inyfinn' ), $hidden ) ) . ' ';
		echo '<button type="button" class="button-link" id="colorify-notices-reset"' . ( $hidden ? '' : ' disabled' ) . '>' . esc_html__( 'Pokaż je z powrotem', 'colorify-by-inyfinn' ) . '</button></p>';
		echo '</div>';
		echo '<div class="colorify-seg colorify-scope-seg colorify-style-seg" role="radiogroup" aria-label="' . esc_attr__( 'Styl panelu', 'colorify-by-inyfinn' ) . '">';
		foreach ( array( 'modern' => __( 'Nowoczesny', 'colorify-by-inyfinn' ), 'classic' => __( 'Klasyczny', 'colorify-by-inyfinn' ) ) as $value => $label ) {
			printf(
				'<label class="colorify-seg__opt" aria-checked="%3$s"><input type="radio" class="screen-reader-text" name="colorify_admin_style" value="%1$s" %4$s>%2$s</label>',
				esc_attr( $value ),
				esc_html( $label ),
				$style === $value ? 'true' : 'false',
				checked( $style, $value, false )
			);
		}
		echo '</div></div>';

		// Styl kokpitu: Klasyczny albo jeden z czterech motywów.
		$dstyle = Colorify_Dashboard::style();
		$dl     = Colorify_Dashboard::labels();
		echo '<div class="colorify-site colorify-style-row">';
		echo '<div class="colorify-site__text"><p class="colorify-site__title">' . esc_html__( 'Styl kokpitu', 'colorify-by-inyfinn' ) . '</p>';
		echo '<p class="colorify-site__hint" id="colorify-dash-hint">' . esc_html( $dl[ $dstyle ][1] ) . '</p></div>';
		echo '<div class="colorify-seg colorify-scope-seg colorify-style-seg colorify-dash-seg" role="radiogroup" aria-label="' . esc_attr__( 'Styl kokpitu', 'colorify-by-inyfinn' ) . '">';
		foreach ( array( 'calendar', 'bookings', 'portfolio', 'sales', 'leads', 'editorial', 'company', 'classic' ) as $value ) {
			printf(
				'<label class="colorify-seg__opt" aria-checked="%3$s" title="%5$s"><input type="radio" class="screen-reader-text" name="colorify_dashboard_style" value="%1$s" %4$s>%2$s</label>',
				esc_attr( $value ),
				esc_html( $dl[ $value ][0] ),
				$dstyle === $value ? 'true' : 'false',
				checked( $dstyle, $value, false ),
				esc_attr( $dl[ $value ][1] )
			);
		}
		echo '</div></div>';

		// Panel dopasowania do strony: zawsze widoczny, z tym, co zastosowano ostatnio.
		$skin_now  = colorify_skin_sanitize( get_option( COLORIFY_SKIN_OPTION, array() ) );
		$site_cols = get_option( COLORIFY_GLOBAL_OPTION_PREFIX . 'custom_colors', array() );
		$site_on   = COLORIFY_ADMIN_CUSTOM_SCHEME_KEY === colorify_get_global_admin_color() && is_array( $site_cols ) && ! empty( $site_cols );
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		echo '<div class="colorify-site">';
		echo '<div class="colorify-site__text"><p class="colorify-site__title">' . esc_html__( 'Dopasowanie do strony', 'colorify-by-inyfinn' ) . '</p>';
		/* translators: %s: domena strony */
		echo '<p class="colorify-site__hint">' . esc_html( sprintf( __( 'Odczytuje kolory, czcionki i rogi z %s. Nic się nie zmienia, dopóki nie klikniesz „Zastosuj”.', 'colorify-by-inyfinn' ), $host ) ) . '</p>';
		if ( $site_on ) {
			$font = str_replace( '"', '', (string) strtok( $skin_now['body'], ',' ) );
			echo '<p class="colorify-site__now"><span>' . esc_html__( 'Teraz w panelu:', 'colorify-by-inyfinn' ) . '</span>';
			foreach ( array( 'bg2', 'accent', 'accent2' ) as $k ) {
				$hex = (string) sanitize_hex_color( (string) ( $site_cols[ $k ] ?? '' ) );
				if ( '' !== $hex ) {
					echo '<i class="colorify-match__swatch" data-colorify-swatch style="background:' . esc_attr( $hex ) . '" title="' . esc_attr( $hex ) . '"></i>';
				}
			}
			if ( 'site' === $skin_now['preset'] ) {
				/* translators: 1: czcionka, 2: zaokrąglenie w px */
				echo '<span>' . esc_html( sprintf( __( '%1$s, rogi %2$d px', 'colorify-by-inyfinn' ), $font, $skin_now['radius'] ) ) . '</span></p>';
			} else {
				echo '<span>' . esc_html__( 'czcionki WordPressa', 'colorify-by-inyfinn' ) . '</span></p>';
			}
		}
		echo '</div>';
		echo '<button type="button" class="button button-primary colorify-match-btn" id="colorify-match-run">' . esc_html( $site_on ? __( 'Odczytaj ponownie', 'colorify-by-inyfinn' ) : __( 'Dopasuj do mojej strony', 'colorify-by-inyfinn' ) ) . '</button>';
		echo '<div class="colorify-match__result" id="colorify-match-result" role="status" aria-live="polite" hidden></div>';
		echo '</div>';
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
