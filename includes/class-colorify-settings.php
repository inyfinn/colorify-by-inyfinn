<?php
/**
 * Panel ustawień wtyczki Colorify.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strona ustawień + przełącznik globalne / per użytkownik.
 */
final class Colorify_Settings {

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_init', array( self::class, 'handle_save' ) );
		add_action( 'admin_init', array( self::class, 'handle_github_save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_settings_assets' ), 10000 );
	}

	public static function register_menu(): void {
		add_options_page(
			__( 'Colorify by INYFINN', 'colorify-by-inyfinn' ),
			__( 'Colorify', 'colorify-by-inyfinn' ),
			'manage_options',
			'colorify-by-inyfinn',
			array( self::class, 'render_page' )
		);
	}

	public static function enqueue_settings_assets( string $hook ): void {
		if ( 'settings_page_colorify-by-inyfinn' !== $hook ) {
			return;
		}
		colorify_enqueue_admin_assets( get_current_user_id() );
	}

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
			: 'user';

		if ( ! in_array( $scope, array( 'user', 'global' ), true ) ) {
			$scope = 'user';
		}

		update_option( COLORIFY_SCOPE_OPTION, $scope );

		if ( 'global' === $scope ) {
			colorify_save_global_appearance( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'colorify-by-inyfinn',
					'colorify_saved'    => '1',
					'colorify_settings' => 'updated',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public static function handle_github_save(): void {
		if ( ! isset( $_POST['colorify_github_save'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'colorify_github_save', 'colorify_github_nonce' );

		$repo = isset( $_POST['colorify_github_repo'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST['colorify_github_repo'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		$repo = trim( $repo );
		if ( '' !== $repo && ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'colorify-by-inyfinn',
						'colorify_github' => 'invalid',
					),
					admin_url( 'options-general.php' )
				)
			);
			exit;
		}

		update_option( 'colorify_github_repo', $repo );

		if ( '' !== $repo ) {
			delete_transient( 'colorify_github_release_' . md5( $repo ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'colorify-by-inyfinn',
					'colorify_github' => 'saved',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$scope      = colorify_get_settings_scope();
		$profile_url = admin_url( 'profile.php' );
		$user        = wp_get_current_user();
		$show_saved       = isset( $_GET['colorify_saved'] ) && '1' === $_GET['colorify_saved']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$github_repo       = colorify_get_github_repo();
		$github_from_const = defined( 'COLORIFY_GITHUB_REPO' ) && is_string( COLORIFY_GITHUB_REPO ) && '' !== COLORIFY_GITHUB_REPO;
		$github_release    = '' !== $github_repo ? colorify_get_cached_github_release() : null;
		$run_update_url    = colorify_get_manual_update_url( admin_url( 'options-general.php?page=colorify-by-inyfinn' ) );

		?>
		<div class="wrap colorify-settings-wrap">
			<header class="colorify-settings-hero">
				<div class="colorify-settings-hero__brand">
					<img src="<?php echo esc_url( COLORIFY_PLUGIN_URL . 'assets/inyfinn-logo-okrag.svg' ); ?>" alt="" width="48" height="48" decoding="async" />
					<div>
						<h1><?php esc_html_e( 'Colorify by INYFINN', 'colorify-by-inyfinn' ); ?></h1>
						<p><?php esc_html_e( 'Personalizacja kolorów panelu WordPress — schematy, paleta, dostrojenie i tryb ciemny/jasny.', 'colorify-by-inyfinn' ); ?></p>
					</div>
				</div>
			</header>

			<?php if ( $show_saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ustawienia zapisane.', 'colorify-by-inyfinn' ); ?></p></div>
			<?php endif; ?>

			<?php if ( colorify_mu_module_is_loaded() ) : ?>
				<div class="notice notice-warning"><p>
					<strong><?php esc_html_e( 'Uwaga:', 'colorify-by-inyfinn' ); ?></strong>
					<?php esc_html_e( 'Moduł MU mu-plugins/colorify/ jest załadowany równolegle z wtyczką. Działa tylko jeden silnik — dezaktywuj wtyczkę lub usuń colorify-loader.php.', 'colorify-by-inyfinn' ); ?>
				</p></div>
			<?php elseif ( colorify_mu_module_exists() ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Moduł MU mu-plugins/colorify/ jest obecny, ale wyłączony automatycznie, dopóki ta wtyczka jest aktywna. Inne MU-pluginy działają normalnie.', 'colorify-by-inyfinn' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="" id="colorify-settings-form" class="colorify-settings-form">
				<?php wp_nonce_field( 'colorify_settings_save', 'colorify_settings_nonce' ); ?>
				<input type="hidden" name="colorify_settings_save" value="1" />

				<div class="colorify-settings-status" role="status" aria-live="polite">
					<span class="colorify-settings-status__label"><?php esc_html_e( 'Aktualnie aktywne:', 'colorify-by-inyfinn' ); ?></span>
					<span class="colorify-settings-status__badge colorify-settings-status__badge--<?php echo esc_attr( $scope ); ?>">
						<?php
						echo 'global' === $scope
							? esc_html__( 'Globalne domyślne — fallback dla użytkowników bez własnego stylu', 'colorify-by-inyfinn' )
							: esc_html__( 'Per użytkownik — każdy ustawia kolory w profilu', 'colorify-by-inyfinn' );
						?>
					</span>
				</div>

				<section class="colorify-settings-scope" aria-labelledby="colorify-scope-title">
					<h2 id="colorify-scope-title"><?php esc_html_e( 'Zakres ustawień kolorów', 'colorify-by-inyfinn' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Globalne: wtyczka narzuca domyślny wygląd tylko użytkownikom bez własnego wyboru w profilu — w tym na stronie logowania. Per użytkownik: każdy konfiguruje kolory samodzielnie.', 'colorify-by-inyfinn' ); ?></p>

					<div class="colorify-scope-toggle" role="radiogroup" aria-label="<?php esc_attr_e( 'Zakres ustawień', 'colorify-by-inyfinn' ); ?>">
						<label class="colorify-scope-toggle__option<?php echo 'user' === $scope ? ' is-active' : ''; ?>">
							<input type="radio" name="colorify_settings_scope" value="user" <?php checked( $scope, 'user' ); ?> />
							<span class="colorify-scope-toggle__label"><?php esc_html_e( 'Per użytkownik', 'colorify-by-inyfinn' ); ?></span>
							<span class="colorify-scope-toggle__hint"><?php esc_html_e( 'Każdy użytkownik zmienia kolory w swoim profilu.', 'colorify-by-inyfinn' ); ?></span>
						</label>
						<label class="colorify-scope-toggle__option<?php echo 'global' === $scope ? ' is-active' : ''; ?>">
							<input type="radio" name="colorify_settings_scope" value="global" <?php checked( $scope, 'global' ); ?> />
							<span class="colorify-scope-toggle__label"><?php esc_html_e( 'Globalne', 'colorify-by-inyfinn' ); ?></span>
							<span class="colorify-scope-toggle__hint"><?php esc_html_e( 'Domyślny styl z wtyczki — tylko dla użytkowników bez własnej personalizacji.', 'colorify-by-inyfinn' ); ?></span>
						</label>
					</div>
				</section>

				<section class="colorify-settings-panel" id="colorify-user-scope-panel"<?php echo 'user' !== $scope ? ' hidden' : ''; ?>>
					<div class="colorify-settings-callout">
						<h2><?php esc_html_e( 'Ustawienia per użytkownik', 'colorify-by-inyfinn' ); ?></h2>
						<p>
							<?php
							printf(
								/* translators: %s: profile URL */
								esc_html__( 'Aby zmienić kolory panelu, przejdź do %s → sekcja Personalizacja.', 'colorify-by-inyfinn' ),
								'<a href="' . esc_url( $profile_url ) . '">' . esc_html__( 'Ustawienia użytkownika (profil)', 'colorify-by-inyfinn' ) . '</a>'
							);
							?>
						</p>
						<p><a class="button button-primary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Otwórz profil użytkownika', 'colorify-by-inyfinn' ); ?></a></p>
					</div>
				</section>

				<section class="colorify-settings-panel" id="colorify-global-scope-panel"<?php echo 'global' !== $scope ? ' hidden' : ''; ?>>
					<h2><?php esc_html_e( 'Ustawienia globalne', 'colorify-by-inyfinn' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Poniżej ustawiasz domyślny wygląd witryny. Dotyczy tylko użytkowników, którzy nie wybrali własnego stylu w profilu.', 'colorify-by-inyfinn' ); ?></p>
					<div class="colorify-settings-callout colorify-settings-callout--login" role="note">
						<p>
							<strong><?php esc_html_e( 'Panel logowania', 'colorify-by-inyfinn' ); ?></strong> —
							<?php esc_html_e( 'Zmiana globalnego stylu aktualizuje także stronę logowania (wp-login.php). Kolory logowania zawsze pochodzą z ustawień globalnych.', 'colorify-by-inyfinn' ); ?>
						</p>
					</div>

					<input type="hidden" name="colorify_admin_appearance" id="colorify-admin-appearance-field" value="<?php echo esc_attr( colorify_get_effective_appearance_mode() ); ?>" />

					<div class="colorify-profile-mode-bar colorify-settings-mode-bar">
						<div class="colorify-profile-mode-bar__row">
							<span class="colorify-profile-mode-bar__label"><?php esc_html_e( 'Tryb panelu', 'colorify-by-inyfinn' ); ?></span>
							<?php echo colorify_admin_mode_switch_html( colorify_get_effective_appearance_mode() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<button type="button" class="button colorify-tuning-card__open colorify-profile-mode-bar__tuning-btn" id="colorify-tuning-open">
								<?php esc_html_e( 'Dostrojenie kolorów', 'colorify-by-inyfinn' ); ?>
							</button>
						</div>
						<p class="description colorify-profile-mode-bar__hint">
							<?php esc_html_e( 'Podgląd globalnych kolorów w trybie ciemnym lub jasnym.', 'colorify-by-inyfinn' ); ?>
						</p>
					</div>

					<div class="colorify-global-scheme-picker">
						<label for="colorify-global-admin-color">
							<?php esc_html_e( 'Domyślny schemat kolorów', 'colorify-by-inyfinn' ); ?>
						</label>
						<select name="admin_color" id="colorify-global-admin-color">
							<?php
							$current_scheme = colorify_get_effective_admin_color();
							foreach ( colorify_admin_scheme_definitions() as $key => $def ) {
								if ( ! empty( $def['custom'] ) ) {
									continue;
								}
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $key ),
									selected( $current_scheme, $key, false ),
									esc_html( $def['name'] ?? $key )
								);
							}
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( COLORIFY_ADMIN_CUSTOM_SCHEME_KEY ),
								selected( $current_scheme, COLORIFY_ADMIN_CUSTOM_SCHEME_KEY, false ),
								esc_html__( 'Własna paleta', 'colorify-by-inyfinn' )
							);
							?>
						</select>
					</div>

					<div id="colorify-global-appearance-mount">
						<?php
						if ( $user instanceof WP_User ) {
							colorify_admin_custom_palette_markup( $user, true );
						}
						?>
					</div>

				</section>

				<p class="colorify-settings-submit colorify-settings-submit--scope">
					<button type="submit" class="button button-primary button-hero colorify-settings-save" id="colorify-scope-save">
						<?php esc_html_e( 'Zapisz zakres ustawień', 'colorify-by-inyfinn' ); ?>
					</button>
					<span class="colorify-settings-submit__hint">
						<?php esc_html_e( 'Zapisuje wybór: per użytkownik lub globalne. W trybie globalnym zapisuje też schemat i paletę poniżej.', 'colorify-by-inyfinn' ); ?>
					</span>
				</p>
			</form>

			<section class="colorify-settings-panel colorify-settings-updates" aria-labelledby="colorify-updates-title">
				<h2 id="colorify-updates-title"><?php esc_html_e( 'Aktualizacja', 'colorify-by-inyfinn' ); ?></h2>
				<p class="colorify-settings-updates__version">
					<?php
					printf(
						/* translators: %s: current plugin version */
						esc_html__( 'Wersja %s', 'colorify-by-inyfinn' ),
						esc_html( COLORIFY_PLUGIN_VERSION )
					);
					?>
				</p>

				<?php if ( isset( $_GET['colorify_github'] ) && 'saved' === $_GET['colorify_github'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Repozytorium GitHub zapisane.', 'colorify-by-inyfinn' ); ?></p></div>
				<?php elseif ( isset( $_GET['colorify_github'] ) && 'invalid' === $_GET['colorify_github'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Nieprawidłowy format repozytorium. Użyj owner/repo.', 'colorify-by-inyfinn' ); ?></p></div>
				<?php endif; ?>

				<?php if ( '' !== $github_repo && current_user_can( 'update_plugins' ) ) : ?>
					<p class="colorify-settings-submit colorify-settings-submit--updates">
						<a class="button button-primary button-hero colorify-settings-update-btn" href="<?php echo esc_url( $run_update_url ); ?>">
							<?php
							echo esc_html(
								colorify_has_github_update() && is_array( $github_release ) && ! empty( $github_release['version'] )
									? sprintf(
										/* translators: %s: new version number */
										__( 'Aktualizuj do %s', 'colorify-by-inyfinn' ),
										$github_release['version']
									)
									: __( 'Aktualizuj', 'colorify-by-inyfinn' )
							);
							?>
						</a>
					</p>
				<?php endif; ?>

				<?php if ( ! $github_from_const ) : ?>
					<form method="post" action="" class="colorify-settings-github-form">
						<?php wp_nonce_field( 'colorify_github_save', 'colorify_github_nonce' ); ?>
						<input type="hidden" name="colorify_github_save" value="1" />
						<p>
							<label for="colorify-github-repo"><?php esc_html_e( 'Repozytorium GitHub', 'colorify-by-inyfinn' ); ?></label>
							<input
								type="text"
								class="regular-text code"
								id="colorify-github-repo"
								name="colorify_github_repo"
								value="<?php echo esc_attr( get_option( 'colorify_github_repo', '' ) ); ?>"
								placeholder="inyfinn/colorify-by-inyfinn"
							/>
						</p>
						<p class="colorify-settings-submit">
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Zapisz repozytorium', 'colorify-by-inyfinn' ); ?></button>
						</p>
					</form>
				<?php endif; ?>
			</section>

			<footer class="colorify-settings-footer">
				<p>
					<a href="<?php echo esc_url( COLORIFY_CREDITS_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( COLORIFY_CREDITS ); ?></a>
					· <?php esc_html_e( 'Dokumentacja w pliku README.md wtyczki', 'colorify-by-inyfinn' ); ?>
				</p>
			</footer>
		</div>
		<script>
		(function () {
			var form = document.getElementById('colorify-settings-form');
			if (!form) return;
			var userPanel = document.getElementById('colorify-user-scope-panel');
			var globalPanel = document.getElementById('colorify-global-scope-panel');
			var statusBadge = document.querySelector('.colorify-settings-status__badge');
			var statusLabels = {
				user: <?php echo wp_json_encode( __( 'Per użytkownik — każdy ustawia kolory w profilu', 'colorify-by-inyfinn' ) ); ?>,
				global: <?php echo wp_json_encode( __( 'Globalne domyślne — fallback bez własnego stylu', 'colorify-by-inyfinn' ) ); ?>
			};

			function syncScopeUi() {
				var checked = form.querySelector('input[name="colorify_settings_scope"]:checked');
				var value = checked ? checked.value : 'user';
				var isGlobal = value === 'global';
				if (userPanel) userPanel.hidden = isGlobal;
				if (globalPanel) globalPanel.hidden = !isGlobal;
				form.querySelectorAll('.colorify-scope-toggle__option').forEach(function (opt) {
					var input = opt.querySelector('input[type="radio"]');
					opt.classList.toggle('is-active', input && input.checked);
				});
				if (statusBadge) {
					statusBadge.textContent = statusLabels[value] || statusLabels.user;
					statusBadge.className = 'colorify-settings-status__badge colorify-settings-status__badge--' + value;
				}
			}

			form.querySelectorAll('input[name="colorify_settings_scope"]').forEach(function (radio) {
				radio.addEventListener('change', syncScopeUi);
			});
			syncScopeUi();
		})();
		</script>
		<?php
	}
}
