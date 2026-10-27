<?php
/**
 * Moduł "Kreator e-maili", ekran wp-admin: lista szablonów z rejestru, edytor jednego szablonu (wersje, chipy zmiennych,
 * B / I), podgląd na żywo w iframe (600 / 375, dane przykładowe albo wybrany rekord), test na własny adres, historia.
 * Ładowany tylko, gdy moduł jest włączony i wtyczka zarejestrowała szablony. Style: colorify-tresci.css (tokeny .cft) + własne.
 *
 * @package ColorifyByInyfinn
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', static function () {
	$r = colorify_maile_rejestr();
	if ( '' !== (string) $r['menu']['rodzic'] ) {
		add_submenu_page( (string) $r['menu']['rodzic'], (string) $r['tytul'], (string) $r['menu']['etykieta'], colorify_maile_cap(), colorify_maile_slug(), 'cfm_ekran' );
		if ( '' !== (string) $r['zastepuje'] ) {
			remove_submenu_page( (string) $r['menu']['rodzic'], colorify_maile_slug() ); // stary wpis menu wtyczki prowadzi tu przez przekierowanie
		}
	} else {
		add_menu_page( (string) $r['tytul'], (string) $r['menu']['etykieta'], colorify_maile_cap(), colorify_maile_slug(), 'cfm_ekran', 'dashicons-email-alt', 23 );
	}
}, 20 );

/** Stary ekran wtyczki (rejestr: zastepuje) przekierowuje na moduł. */
add_action( 'admin_init', static function () {
	$z = (string) colorify_maile_rejestr()['zastepuje'];
	if ( '' === $z || ! isset( $_GET['page'] ) || $z !== sanitize_key( wp_unslash( $_GET['page'] ) ) || wp_doing_ajax() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	wp_safe_redirect( colorify_maile_url() );
	exit;
}, 1 );

add_action( 'admin_enqueue_scripts', static function ( $hook ) {
	if ( false === strpos( (string) $hook, '_page_' . colorify_maile_slug() ) ) {
		return;
	}
	$r    = colorify_maile_rejestr();
	$deps = array();
	if ( '' !== (string) $r['fonty'] ) {
		wp_enqueue_style( 'cft-fonty', esc_url_raw( (string) $r['fonty'] ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		$deps[] = 'cft-fonty';
	}
	wp_enqueue_style( 'colorify-tresci', COLORIFY_PLUGIN_URL . 'assets/colorify-tresci.css', $deps, COLORIFY_PLUGIN_VERSION );
	wp_enqueue_style( 'colorify-maile', COLORIFY_PLUGIN_URL . 'assets/colorify-maile.css', array( 'colorify-tresci' ), COLORIFY_PLUGIN_VERSION );
	wp_enqueue_script( 'colorify-maile', COLORIFY_PLUGIN_URL . 'assets/colorify-maile.js', array(), COLORIFY_PLUGIN_VERSION, true );
	wp_localize_script( 'colorify-maile', 'CFM', array( 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'colorify_maile' ) ) );
} );

/** Domyślny adres do testów: z rejestru, inaczej e-mail zalogowanej osoby. */
function cfm_test_adres(): string {
	$a = (string) colorify_maile_rejestr()['test_adres'];
	return is_email( $a ) ? $a : (string) wp_get_current_user()->user_email;
}

/** Szablon z żądania (id w 'szablon') albo pierwszy z rejestru. */
function cfm_biezacy( array $src ) {
	$id = isset( $src['szablon'] ) ? sanitize_key( (string) $src['szablon'] ) : '';
	$t  = colorify_maile_szablon( $id );
	if ( ! $t ) {
		$sz = colorify_maile_rejestr()['szablony'];
		$id = (string) array_key_first( $sz );
		$t  = $sz[ $id ];
	}
	return array( $id, $t );
}

function cfm_wroc( string $id, string $msg ): void {
	wp_safe_redirect( colorify_maile_url( $id, array( 'cfm_msg' => $msg ) ) );
	exit;
}

/* ---------- zapis, przywracanie (admin-post) ---------- */

add_action( 'admin_post_colorify_maile_zapis', static function () {
	if ( ! current_user_can( colorify_maile_cap() ) ) {
		wp_die( 'Brak uprawnień.', 403 );
	}
	check_admin_referer( 'colorify_maile_zapis' );
	$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitizacja per pole niżej
	list( $id, $t ) = cfm_biezacy( $post );
	$wersja = isset( $post['cfm_wersja'] ) ? sanitize_key( (string) $post['cfm_wersja'] ) : null;
	if ( ! empty( $post['reset'] ) ) {
		$r = colorify_maile_zapisz( $t, null, 'Przywrócono domyślne', $wersja );
	} elseif ( isset( $post['przywroc'] ) && '' !== $post['przywroc'] ) {
		$h = colorify_maile_historia( $t );
		$i = (int) $post['przywroc'];
		if ( ! isset( $h[ $i ] ) ) {
			cfm_wroc( $id, 'brak-wersji' );
		}
		$r = colorify_maile_zapisz( $t, $h[ $i ]['d'], 'Przywrócono wersję z ' . wp_date( 'j.m.Y H:i', $h[ $i ]['t'] ), $wersja );
	} else {
		$n = colorify_maile_z_formularza( $t, $post );
		if ( ! $n['wersje'] ) {
			cfm_wroc( $id, 'pusto' );
		}
		$r = colorify_maile_zapisz( $t, colorify_maile_do_surowego( $t, $n, colorify_maile_surowe( $t ) ), 'Zapis szablonu', $wersja );
	}
	if ( is_wp_error( $r ) ) {
		cfm_wroc( $id, 'konflikt' );
	}
	cfm_wroc( $id, $r ? ( ! empty( $post['reset'] ) ? 'domyslne' : ( isset( $post['przywroc'] ) && '' !== $post['przywroc'] ? 'przywrocono' : 'zapisano' ) ) : 'bez-zmian' );
} );

/* ---------- podgląd i test (admin-ajax) ---------- */

function cfm_ajax_wejscie(): array {
	if ( ! current_user_can( colorify_maile_cap() ) || ! check_ajax_referer( 'colorify_maile', 'nonce', false ) ) {
		wp_send_json_error( array( 'komunikat' => 'Brak uprawnień albo wygasła sesja. Odśwież stronę.' ), 403 );
	}
	$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitizacja per pole w colorify_maile_z_formularza()
	list( $id, $t ) = cfm_biezacy( $post );
	$n  = colorify_maile_z_formularza( $t, $post );
	$nr = max( 0, (int) ( $post['wersja_nr'] ?? 0 ) );
	$sz = $n['wersje'][ $nr ] ?? ( $n['wersje'][0] ?? colorify_maile_domyslne( $t )['wersje'][0] );
	$z  = colorify_maile_dane( $t, (int) ( $post['rekord'] ?? 0 ) );
	return array( $id, $t, $sz, $z, $post );
}

add_action( 'wp_ajax_colorify_maile_podglad', static function () {
	list( , $t, $sz, $z ) = cfm_ajax_wejscie();
	wp_send_json_success( array( 'html' => colorify_maile_html( $t, $sz, $z ), 'temat' => colorify_maile_temat( $t, $sz, $z ) ) );
} );

add_action( 'wp_ajax_colorify_maile_test', static function () {
	list( , $t, $sz, $z, $post ) = cfm_ajax_wejscie();
	$adres = sanitize_email( (string) ( $post['adres'] ?? '' ) );
	if ( ! is_email( $adres ) ) {
		$adres = cfm_test_adres();
	}
	$blad = colorify_maile_wyslij( $t, $sz, $z, $adres );
	if ( '' !== $blad ) {
		wp_send_json_error( array( 'komunikat' => 'Nie wyszło: ' . $blad ) );
	}
	wp_send_json_success( array( 'komunikat' => 'Test poszedł na ' . $adres . ' (' . wp_date( 'H:i:s' ) . '), temat: „' . colorify_maile_temat( $t, $sz, $z ) . '”. Sprawdź skrzynkę, także spam.' ) );
} );

/* ---------- ekran ---------- */

/** Jedno pole szablonu; $nr = numer wersji. */
function cfm_pole( string $k, array $p, string $v, int $nr, bool $chipy ): void {
	$typ  = (string) ( $p[0] ?? 'text' );
	$id   = 'cfm-' . $nr . '-' . $k;
	$name = 'm[' . $nr . '][' . $k . ']';
	$hint = (string) ( $p[2] ?? '' );
	echo '<div class="cft-pole cfm-pole cfm-pole--' . esc_attr( $typ ) . '">';
	echo '<label class="cft-etykieta" for="' . esc_attr( $id ) . '">' . esc_html( (string) ( $p[1] ?? $k ) ) . '</label>';
	if ( 'area' === $typ ) {
		echo '<div class="cft-tb" role="toolbar" aria-label="Formatowanie"><button type="button" data-tag="b" title="Pogrubienie" aria-label="Pogrubienie"><b>B</b></button><button type="button" data-tag="i" title="Kursywa" aria-label="Kursywa"><i>I</i></button></div>';
	}
	if ( in_array( $typ, array( 'area', 'plain', 'lista' ), true ) ) {
		$rows = 'lista' === $typ ? max( 3, substr_count( $v, "\n" ) + 2 ) : max( 3, min( 10, substr_count( $v, "\n" ) + 3 ) );
		echo '<textarea class="cft-in" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="' . (int) $rows . '" data-cfm-pole' . ( $chipy ? ' data-cfm-chipy' : '' ) . '>' . esc_textarea( $v ) . '</textarea>';
	} else {
		$t = in_array( $typ, array( 'url', 'email' ), true ) ? $typ : 'text';
		echo '<input class="cft-in" type="' . esc_attr( $t ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '" data-cfm-pole' . ( $chipy && 'text' === $t ? ' data-cfm-chipy' : '' ) . '>';
	}
	if ( '' !== $hint ) {
		echo '<p class="cft-podp">' . esc_html( $hint ) . '</p>';
	}
	echo '</div>';
}

function cfm_ekran(): void {
	if ( ! current_user_can( colorify_maile_cap() ) ) {
		wp_die( 'Brak uprawnień.', 403 );
	}
	$r = colorify_maile_rejestr();
	list( $id, $t ) = cfm_biezacy( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
	$n     = colorify_maile_odczyt( $t );
	$dom   = colorify_maile_domyslne( $t );
	$hist  = colorify_maile_historia( $t );
	$msg   = isset( $_GET['cfm_msg'] ) ? sanitize_key( wp_unslash( $_GET['cfm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$kom   = array(
		'zapisano'    => array( '', 'Szablon zapisany.' ),
		'domyslne'    => array( '', 'Przywrócono domyślny szablon. Poprzedni jest w historii.' ),
		'przywrocono' => array( '', 'Przywrócono wybraną wersję.' ),
		'bez-zmian'   => array( '', 'Nic się nie zmieniło, więc nic nie zostało zapisane.' ),
		'pusto'       => array( 'cft-info--blad', 'Szablon musi mieć choć jedną wersję z treścią.' ),
		'brak-wersji' => array( 'cft-info--blad', 'Nie ma takiej wersji w historii. Odśwież stronę.' ),
		'konflikt'    => array( 'cft-info--blad', 'Nie zapisano: ktoś zapisał ten szablon w międzyczasie. Odśwież stronę i wprowadź zmiany jeszcze raz.' ),
	);
	$rek   = is_callable( $t['rekordy'] ) ? (array) call_user_func( $t['rekordy'] ) : array();
	$wiele = $t['wersje'] > 1;
	$chipy = ! empty( $t['zmienne'] );
	?>
	<div class="wrap cft cfm">
		<div class="cft-naglowek">
			<h1 class="cft-h1"><?php echo esc_html( (string) $r['tytul'] ); ?></h1>
			<p class="cft-lead">Szablony maili do par. Wpisujesz raz, a dane pary (imiona, data, pakiet…) wchodzą same z chipów. Po prawej widzisz mail tak, jak zobaczy go para; „Wyślij test” wysyła go na Twój adres.</p>
		</div>

		<nav class="cfm-lista" aria-label="Szablony">
			<?php foreach ( $r['szablony'] as $sid => $s ) : $o = colorify_maile_odczyt( $s ); ?>
				<a class="cfm-lista__a<?php echo $sid === $id ? ' is-on' : ''; ?>" href="<?php echo esc_url( colorify_maile_url( $sid ) ); ?>" <?php echo $sid === $id ? 'aria-current="page"' : ''; ?>>
					<span class="cfm-lista__n"><?php echo esc_html( (string) $s['nazwa'] ); ?></span>
					<span class="cfm-lista__m"><?php echo esc_html( ( $s['wersje'] > 1 ? count( $o['wersje'] ) . ' z ' . $s['wersje'] . ' wersji' : ( $o['zapisany'] ? 'zmieniony' : 'domyślny' ) ) . ( '' !== (string) $s['wlacznik'] ? ( $o['wlaczony'] ? ' · włączony' : ' · wyłączony' ) : '' ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if ( isset( $kom[ $msg ] ) ) : ?>
			<div class="cft-info <?php echo esc_attr( $kom[ $msg ][0] ); ?>" role="status"><span><?php echo esc_html( $kom[ $msg ][1] ); ?></span></div>
		<?php endif; ?>

		<div class="cfm-uklad">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cfm-form" class="cfm-form" data-szablon="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="action" value="colorify_maile_zapis">
			<input type="hidden" name="szablon" value="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="cfm_wersja" value="<?php echo esc_attr( colorify_maile_wersja( $t ) ); ?>">
			<?php wp_nonce_field( 'colorify_maile_zapis' ); ?>

			<section class="cft-karta cfm-karta">
				<div class="cfm-karta__gora">
					<div>
						<h2 class="cfm-h2"><?php echo esc_html( (string) $t['nazwa'] ); ?></h2>
						<?php if ( '' !== (string) $t['opis'] ) : ?><p class="cft-lead cfm-opis"><?php echo esc_html( (string) $t['opis'] ); ?></p><?php endif; ?>
					</div>
					<?php if ( '' !== (string) $t['wlacznik'] ) : ?>
						<label class="cfm-wlacznik"><input type="checkbox" name="m_wlaczony" value="1" <?php checked( ! empty( $n['wlaczony'] ) ); ?>><span>Włączony</span></label>
					<?php endif; ?>
				</div>

				<?php if ( $chipy ) : ?>
					<div class="cfm-chipy" role="group" aria-label="Wstaw daną pary">
						<span class="cft-grupa__t">Dane pary: kliknij w pole, potem w chip</span>
						<div class="cfm-chipy__lista">
							<?php foreach ( (array) $t['zmienne'] as $k => $opis ) : ?>
								<button type="button" class="cfm-chip" data-cfm-zm="{<?php echo esc_attr( $k ); ?>}" title="<?php echo esc_attr( (string) $opis ); ?>">{<?php echo esc_html( $k ); ?>}</button>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $wiele ) : ?>
					<div class="cfm-wersje" data-cfm-max="<?php echo (int) $t['wersje']; ?>">
						<div class="cfm-wersje__pasek" role="tablist" aria-label="Wersje">
							<?php foreach ( $n['wersje'] as $i => $w ) : ?>
								<button type="button" role="tab" class="cfm-wersje__tab" data-cfm-tab="<?php echo (int) $i; ?>" aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>">Wersja <?php echo (int) $i + 1; ?></button>
							<?php endforeach; ?>
							<button type="button" class="cft-btn cft-btn--link cfm-wersje__dodaj" data-cfm-dodaj>+ Dodaj wersję</button>
						</div>
						<p class="cft-podp">Przycisk w zleceniu losuje jedną z wersji przy każdym kliknięciu, więc każda para dostaje trochę inny mail. Do <?php echo (int) $t['wersje']; ?> wersji.</p>
					</div>
				<?php endif; ?>

				<?php foreach ( $n['wersje'] as $i => $w ) : ?>
					<fieldset class="cfm-wersja" data-cfm-wersja="<?php echo (int) $i; ?>"<?php echo $wiele && $i > 0 ? ' hidden' : ''; ?>>
						<?php if ( $wiele ) : ?><legend class="screen-reader-text">Wersja <?php echo (int) $i + 1; ?></legend><?php endif; ?>
						<?php foreach ( (array) $t['pola'] as $k => $p ) { cfm_pole( (string) $k, (array) $p, (string) ( $w[ $k ] ?? '' ), (int) $i, $chipy ); } ?>
						<?php if ( $wiele ) : ?><button type="button" class="cft-btn cft-btn--link cfm-wersja__usun" data-cfm-usun>Usuń tę wersję</button><?php endif; ?>
					</fieldset>
				<?php endforeach; ?>
				<template class="cfm-szablon-wersji">
					<fieldset class="cfm-wersja" data-cfm-wersja="__i__">
						<?php foreach ( (array) $t['pola'] as $k => $p ) { cfm_pole( (string) $k, (array) $p, '', 999, $chipy ); } ?>
						<button type="button" class="cft-btn cft-btn--link cfm-wersja__usun" data-cfm-usun>Usuń tę wersję</button>
					</fieldset>
				</template>
				<template class="cfm-domyslne" data-cfm-domyslne="<?php echo esc_attr( wp_json_encode( $dom['wersje'] ) ); ?>"></template>

				<div class="cft-pasek cfm-pasek">
					<button type="submit" class="cft-btn cft-btn--glowny">Zapisz szablon</button>
					<span class="cft-stan" id="cfm-stan" aria-live="polite"></span>
					<span class="cft-pasek__linki">
						<button type="button" class="cft-btn cft-btn--link" data-cfm-reset-form>Przywróć domyślne</button>
						<button type="submit" class="cft-btn cft-btn--obrys cft-btn--maly cfm-pasek__podglad" data-cfm-podglad-otworz>Podgląd</button>
					</span>
				</div>
			</section>

			<?php if ( $hist ) : ?>
				<details class="cft-karta cfm-historia">
					<summary><span class="cft-karta__o"><span class="cft-karta__t">Poprzednie wersje</span><span class="cft-karta__g">Ostatnie <?php echo count( $hist ); ?> zapisów tego szablonu. „Przywróć” wraca do wybranej, obecną dopisuje do historii.</span></span></summary>
					<ul class="cft-historia cfm-historia__lista">
						<?php foreach ( array_reverse( $hist, true ) as $i => $h ) : ?>
							<li class="cft-historia__w"><div class="cft-historia__tekst"><strong><?php echo esc_html( wp_date( 'j.m.Y, H:i', (int) $h['t'] ) ); ?></strong><span><?php echo esc_html( $h['l'] . ( '' !== $h['u'] ? ', ' . $h['u'] : '' ) ); ?></span></div>
								<button type="submit" class="cft-btn cft-btn--obrys cft-btn--maly" name="przywroc" value="<?php echo (int) $i; ?>" formnovalidate>Przywróć</button></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
			<p class="cfm-reset"><button type="submit" class="cft-btn cft-btn--link" name="reset" value="1" data-cfm-reset formnovalidate>Przywróć domyślny szablon (zapisz od razu)</button></p>
		</form>

		<aside class="cft-podglad cfm-podglad" id="cfm-podglad" aria-label="Podgląd maila">
			<div class="cft-podglad__pasek">
				<label class="cfm-rekord">Dane
					<select id="cfm-rekord" class="cft-in">
						<option value="0">przykładowe</option>
						<?php foreach ( $rek as $rid => $et ) : ?><option value="<?php echo (int) $rid; ?>"><?php echo esc_html( (string) $et ); ?></option><?php endforeach; ?>
					</select>
				</label>
				<span class="cft-przelacz" role="group" aria-label="Szerokość podglądu"><button type="button" data-szer="600" aria-pressed="true">Komputer</button><button type="button" data-szer="375" aria-pressed="false">Telefon</button></span>
				<button type="button" class="cft-podglad__zamknij" data-cfm-podglad-zamknij aria-label="Zamknij podgląd"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
			</div>
			<p class="cfm-temat">Temat: <strong id="cfm-temat"></strong></p>
			<div class="cfm-ramka"><iframe id="cfm-iframe" title="Podgląd maila" style="width:600px"></iframe></div>
			<div class="cfm-test">
				<label class="cfm-test__l" for="cfm-adres">Wyślij test na</label>
				<input type="email" id="cfm-adres" class="cft-in" value="<?php echo esc_attr( cfm_test_adres() ); ?>">
				<button type="button" class="cft-btn cft-btn--obrys cft-btn--maly" id="cfm-test">Wyślij test</button>
				<p class="cft-podp" id="cfm-test-stan" role="status" aria-live="polite"></p>
			</div>
		</aside>
		</div>
	</div>
	<?php
}
