<?php
/**
 * Kokpit: „Odwiedzający i blokady” (adresy IP odwiedzających, znaczniki bot / podejrzany / nasz, blokowanie adresów i zakresów,
 * wykluczanie ze statystyk). Tylko dla kont z uprawnieniem manage_options. Dane: colorify-analytics-ip.php (zapis jest
 * opcjonalny i domyślnie wyłączony). Podstrona: admin.php?page=colorify-ruch&modul=ip (przez colorify_insights_module('ip')).
 * Karta z podsumowaniem i odnośnikiem stoi na dole panelu „Ruch na stronie”.
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

function colorify_ip_can(): bool {
	return current_user_can( 'manage_options' ) && class_exists( 'Colorify_Analytics_IP' );
}

function colorify_ip_url( array $q = array() ): string {
	return add_query_arg( $q, admin_url( 'admin.php?page=' . COLORIFY_INSIGHTS_PAGE . '&modul=ip' ) );
}

/** Komunikaty po zapisie, po ludzku. */
function colorify_ip_msg( string $code ): array {
	$m = array(
		'blocked'   => array( 'ok', __( 'Adres zablokowany. Odwiedzający z tego adresu dostaje teraz komunikat „Dostęp zablokowany”.', 'colorify-by-inyfinn' ) ),
		'unblocked' => array( 'ok', __( 'Blokada zdjęta.', 'colorify-by-inyfinn' ) ),
		'excluded'  => array( 'ok', __( 'Odwiedzin z tego adresu nie liczymy już w statystykach.', 'colorify-by-inyfinn' ) ),
		'included'  => array( 'ok', __( 'Odwiedziny z tego adresu znów są liczone w statystykach.', 'colorify-by-inyfinn' ) ),
		'saved'     => array( 'ok', __( 'Ustawienia zapisane.', 'colorify-by-inyfinn' ) ),
		'ip'        => array( 'err', __( 'To nie wygląda na adres IP ani zakres. Przykłady: 203.0.113.7 albo 203.0.113.0/24.', 'colorify-by-inyfinn' ) ),
		'self'      => array( 'err', __( 'Nie zablokowano: ten wpis obejmuje adres, z którego teraz korzystasz, więc zamknąłby Ci dostęp.', 'colorify-by-inyfinn' ) ),
		'server'    => array( 'err', __( 'Nie zablokowano: ten wpis obejmuje adres samego serwera, bez którego strona nie działa.', 'colorify-by-inyfinn' ) ),
		'wide'      => array( 'err', __( 'Nie zablokowano: ten zakres jest za szeroki (może objąć tysiące zwykłych odwiedzających).', 'colorify-by-inyfinn' ) ),
		'dup'       => array( 'err', __( 'Ten adres jest już zablokowany.', 'colorify-by-inyfinn' ) ),
		'full'      => array( 'err', __( 'Lista blokad jest pełna (1000 wpisów). Usuń nieaktualne wpisy.', 'colorify-by-inyfinn' ) ),
		'nf'        => array( 'err', __( 'Takiego wpisu nie ma na liście.', 'colorify-by-inyfinn' ) ),
		'covered'   => array( 'err', __( 'Ten adres jest nadal objęty szerszym zakresem na liście wykluczonych. Usuń ten zakres w ustawieniach.', 'colorify-by-inyfinn' ) ),
	);
	return $m[ $code ] ?? array( '', '' );
}

/** Podsumowanie na dole panelu ruchu: jedna karta z liczbami i przyciskiem „Otwórz”. */
function colorify_insights_ip_summary(): string {
	if ( ! colorify_ip_can() ) {
		return '';
	}
	$open = '<a class="cfi-btn cfi-btn--ghost" href="' . esc_url( colorify_ip_url() ) . '">' . esc_html__( 'Otwórz Odwiedzający i blokady', 'colorify-by-inyfinn' ) . '</a>';
	$help = __( 'Lista adresów, z których ktoś wszedł na stronę, z oznaczeniem robotów i podejrzanych wejść. Stąd możesz zablokować adres albo przestać go liczyć w statystykach.', 'colorify-by-inyfinn' );
	if ( ! Colorify_Analytics_IP::enabled() ) {
		return colorify_insights_card( __( 'Odwiedzający i blokady', 'colorify-by-inyfinn' ), '<p class="cfi-p">' . esc_html( colorify_insights_nb( __( 'Zapis adresów odwiedzających jest wyłączony. Po włączeniu zobaczysz, kto wchodzi na stronę, które adresy to roboty albo spam, i zablokujesz je jednym kliknięciem.', 'colorify-by-inyfinn' ) ) ) . '</p>' . $open, '', '', 'cfi-ip', $help );
	}
	$c    = Colorify_Analytics_IP::counts();
	$tiles = colorify_insights_stat( __( 'Adresy', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $c['total'] ) ), '', sprintf( /* translators: %d: dni */ __( 'Z ostatnich %d dni', 'colorify-by-inyfinn' ), Colorify_Analytics_IP::days() ) )
		. colorify_insights_stat( __( 'Boty', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $c['bots'] ) ), '', __( 'Wyglądają na roboty', 'colorify-by-inyfinn' ) )
		. colorify_insights_stat( __( 'Podejrzane', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $c['suspicious'] ) ), '', __( 'Szybkie, skanery, dużo formularzy', 'colorify-by-inyfinn' ) )
		. colorify_insights_stat( __( 'Zablokowane', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( count( Colorify_Analytics_IP::blocklist() ) ) ), '', __( 'Wpisy na liście blokad', 'colorify-by-inyfinn' ) );
	return colorify_insights_card( __( 'Odwiedzający i blokady', 'colorify-by-inyfinn' ), '<div class="cfi-ipsum">' . $tiles . '</div><p class="cfi-foot">' . $open . '</p>', '', '', 'cfi-ip', $help );
}

/** Jeden wiersz listy odwiedzających. */
function colorify_ip_row( array $r, array $excl, array $block, string $me, string $nonce ): string {
	$ip      = (string) $r['ip'];
	$is_me   = '' !== $me && Colorify_Analytics_Rules::ip_norm( $me ) === Colorify_Analytics_Rules::ip_norm( $ip );
	$is_excl = Colorify_Analytics_Rules::ip_in_list( $ip, $excl );
	$blk     = Colorify_Analytics_IP::blocked( $ip, $block );
	$pills   = ( $is_me ? '<span class="cfi-pill is-up">' . esc_html__( 'to Ty', 'colorify-by-inyfinn' ) . '</span>' : '' )
		. ( $r['bot'] ? '<span class="cfi-pill">' . esc_html__( 'bot', 'colorify-by-inyfinn' ) . '</span>' : '' )
		. ( $r['suspicious'] ? '<span class="cfi-pill is-hi" title="' . esc_attr( implode( ', ', $r['reasons'] ) ) . '">' . esc_html__( 'podejrzany', 'colorify-by-inyfinn' ) . '</span>' : '' )
		. ( $is_excl ? '<span class="cfi-pill is-mid">' . esc_html__( 'nasz, nie liczymy', 'colorify-by-inyfinn' ) . '</span>' : '' )
		. ( $blk ? '<span class="cfi-pill is-down">' . esc_html__( 'zablokowany', 'colorify-by-inyfinn' ) . '</span>' : '' );
	$btn = '';
	if ( $blk ) {
		$btn .= '<button type="submit" name="op" value="unblock" class="cfi-btn cfi-btn--s cfi-btn--ghost">' . esc_html__( 'Odblokuj', 'colorify-by-inyfinn' ) . '</button>';
	} elseif ( ! $is_me ) {
		$btn .= '<button type="submit" name="op" value="block" class="cfi-btn cfi-btn--s">' . esc_html__( 'Zablokuj', 'colorify-by-inyfinn' ) . '</button>';
	}
	$btn .= $is_excl
		? '<button type="submit" name="op" value="include" class="cfi-btn cfi-btn--s cfi-btn--ghost">' . esc_html__( 'Licz w statystykach', 'colorify-by-inyfinn' ) . '</button>'
		: '<button type="submit" name="op" value="exclude" class="cfi-btn cfi-btn--s cfi-btn--ghost">' . esc_html__( 'Nie licz w statystykach', 'colorify-by-inyfinn' ) . '</button>';
	$forms = (int) $r['forms'] + (int) $r['quotes'];
	return '<tr data-t="' . esc_attr( mb_strtolower( $ip . ' ' . $r['page'] . ' ' . $r['ua'] . ' ' . ( $r['bot'] ? 'bot' : '' ) . ' ' . ( $r['suspicious'] ? 'podejrzany' : '' ) . ' ' . ( $is_excl ? 'nasz' : '' ) . ' ' . ( $blk ? 'zablokowany' : '' ) ) ) . '" data-n="' . (int) $r['views'] . '" data-p="' . (int) $r['first_seen'] . '" data-e="' . (int) $r['last_seen'] . '" data-c="' . $forms . '">'
		. '<td><code class="cfi-code">' . esc_html( $ip ) . '</code><span class="cfi-ip__pills">' . $pills . '</span></td>'
		. '<td class="is-num" data-l="' . esc_attr__( 'Odsłony', 'colorify-by-inyfinn' ) . '">' . esc_html( colorify_insights_n( $r['views'] ) ) . ( $r['hits'] > $r['views'] ? '<span class="cfi-sub2">' . esc_html( sprintf( /* translators: %s: liczba */ __( 'wejść: %s', 'colorify-by-inyfinn' ), colorify_insights_n( $r['hits'] ) ) ) . '</span>' : '' ) . '</td>'
		. '<td class="is-num cfi-hide-s">' . esc_html( wp_date( 'j.m.Y H:i', $r['first_seen'] ) ) . '</td>'
		. '<td class="is-num" data-l="' . esc_attr__( 'Ostatnie wejście', 'colorify-by-inyfinn' ) . '">' . esc_html( wp_date( 'j.m.Y H:i', $r['last_seen'] ) ) . '</td>'
		. '<td class="is-num cfi-hide-s">' . esc_html( colorify_insights_n( $forms ) ) . '</td>'
		. '<td class="cfi-hide-s"><span class="cfi-pg__u">' . esc_html( $r['page'] ) . '</span><span class="cfi-sub2">' . esc_html( $r['ua'] ) . '</span></td>'
		. '<td><form class="cfi-ipf" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="colorify_an_ip"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="ip" value="' . esc_attr( $ip ) . '">' . $btn . '</form></td></tr>';
}

/** Cała strona „Odwiedzający i blokady” (moduł ip). */
function colorify_insights_mod_ip(): string {
	if ( ! colorify_ip_can() ) {
		return '';
	}
	$nonce = wp_create_nonce( 'colorify_an_ip' );
	$out   = colorify_insights_back( admin_url( 'index.php' ), __( 'Kokpit', 'colorify-by-inyfinn' ) )
		. '<div class="cfi-head"><div><h2 class="cfi-h2">' . esc_html__( 'Odwiedzający i blokady', 'colorify-by-inyfinn' ) . '</h2><p class="cfi-sub">' . esc_html__( 'Kto wchodzi na stronę, które adresy to roboty albo spam i które zablokowaliśmy.', 'colorify-by-inyfinn' ) . '</p></div></div>';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko komunikat po zapisie.
	$msg = isset( $_GET['cfi_msg'] ) ? colorify_ip_msg( sanitize_key( wp_unslash( $_GET['cfi_msg'] ) ) ) : array( '', '' );
	if ( '' !== $msg[1] ) {
		$out .= '<p class="cfi-notice cfi-notice--' . esc_attr( $msg[0] ) . '" role="status">' . esc_html( $msg[1] ) . '</p>';
	}

	/* ustawienia */
	$on   = Colorify_Analytics_IP::enabled();
	$days = Colorify_Analytics_IP::days();
	$opts = '';
	foreach ( array( 30, 60, 90, 180, 365 ) as $d ) {
		$opts .= '<option value="' . $d . '"' . selected( $days, $d, false ) . '>' . esc_html( sprintf( /* translators: %d: dni */ _n( '%d dzień', '%d dni', $d, 'colorify-by-inyfinn' ), $d ) ) . '</option>';
	}
	$set = '<form class="cfi-excl__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="colorify_an_ip"><input type="hidden" name="op" value="settings"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
		. '<label class="cfi-ipchk"><input type="checkbox" name="enabled" value="1"' . checked( $on, true, false ) . '> <span>' . esc_html__( 'Zapisuj adresy IP odwiedzających', 'colorify-by-inyfinn' ) . '</span></label>'
		. '<label class="cfi-excl__l"><span>' . esc_html__( 'Przechowuj adresy przez', 'colorify-by-inyfinn' ) . '</span><select name="days">' . $opts . '</select></label>'
		. '<div class="cfi-excl__b"><button type="submit" class="cfi-btn">' . esc_html__( 'Zapisz', 'colorify-by-inyfinn' ) . '</button></div></form>'
		. '<p class="cfi-foot">' . esc_html( colorify_insights_nb( __( 'Zapisujemy adres IP, daty pierwszego i ostatniego wejścia, liczbę odsłon, ostatnią stronę i skróconą nazwę przeglądarki, wyłącznie w celu bezpieczeństwa: wykrywania spamu, robotów i skanerów. Adresy znikają same po wybranym czasie. Pamiętaj o dopisaniu tego do polityki prywatności.', 'colorify-by-inyfinn' ) ) ) . '</p>';
	$out .= colorify_insights_card( __( 'Zapis adresów', 'colorify-by-inyfinn' ), $set, '', '', 'cfi-ip-set', __( 'Tu włączasz zapis adresów IP odwiedzających i ustawiasz, jak długo je trzymamy. Bez zapisu nie ma listy poniżej, ale zablokowane adresy nadal działają.', 'colorify-by-inyfinn' ) );

	/* lista odwiedzających */
	if ( $on ) {
		$excl  = Colorify_Analytics::excluded_ips();
		$block = Colorify_Analytics_IP::blocklist();
		$me    = Colorify_Analytics::client_ip();
		$rows  = Colorify_Analytics_IP::rows( 300 );
		$c     = Colorify_Analytics_IP::counts();
		$out  .= '<section class="cfi-card cfi-strip cfi-strip--3" aria-label="' . esc_attr__( 'Najważniejsze liczby', 'colorify-by-inyfinn' ) . '">'
			. colorify_insights_stat( __( 'Adresy', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $c['total'] ) ), '', sprintf( /* translators: %d: dni */ __( 'Z ostatnich %d dni', 'colorify-by-inyfinn' ), $days ) )
			. colorify_insights_stat( __( 'Boty', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $c['bots'] ) ), '', __( 'Wyglądają na roboty', 'colorify-by-inyfinn' ) )
			. colorify_insights_stat( __( 'Podejrzane', 'colorify-by-inyfinn' ), esc_html( colorify_insights_n( $c['suspicious'] ) ), '', __( 'Szybkie, skanery, dużo formularzy', 'colorify-by-inyfinn' ) )
			. '</section>';
		if ( ! $rows ) {
			$body = colorify_insights_state( __( 'Pierwsze adresy pojawią się, gdy ktoś wejdzie na stronę.', 'colorify-by-inyfinn' ) );
		} else {
			$body = '<div class="cfi-tools"><label class="cfi-search"><span class="screen-reader-text">' . esc_html__( 'Szukaj adresu', 'colorify-by-inyfinn' ) . '</span><input type="search" placeholder="' . esc_attr__( 'Szukaj adresu, strony albo np. bot', 'colorify-by-inyfinn' ) . '" data-cfi-search></label><span class="cfi-count" data-cfi-count aria-live="polite"></span></div>'
				. '<div class="cfi-scroll"><table class="cfi-table cfi-sortable cfi-iptab" data-cfi-table><thead><tr>'
				. '<th><button type="button" data-sort="t">' . esc_html__( 'Adres', 'colorify-by-inyfinn' ) . '</button></th>'
				. '<th class="is-num"><button type="button" data-sort="n">' . esc_html__( 'Odsłony', 'colorify-by-inyfinn' ) . '</button></th>'
				. '<th class="is-num cfi-hide-s"><button type="button" data-sort="p">' . esc_html__( 'Pierwsze wejście', 'colorify-by-inyfinn' ) . '</button></th>'
				. '<th class="is-num"><button type="button" data-sort="e" aria-sort="descending" class="is-sorted">' . esc_html__( 'Ostatnie wejście', 'colorify-by-inyfinn' ) . '</button></th>'
				. '<th class="is-num cfi-hide-s"><button type="button" data-sort="c">' . esc_html__( 'Formularze', 'colorify-by-inyfinn' ) . '</button></th>'
				. '<th class="cfi-hide-s">' . esc_html__( 'Strona i przeglądarka', 'colorify-by-inyfinn' ) . '</th>'
				. '<th>' . esc_html__( 'Co zrobić', 'colorify-by-inyfinn' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$body .= colorify_ip_row( $r, $excl, $block, $me, $nonce );
			}
			$body .= '</tbody></table></div>' . ( $c['total'] > count( $rows ) ? '<p class="cfi-foot">' . esc_html( sprintf( /* translators: 1: pokazane 2: wszystkie */ __( 'Pokazano %1$s ostatnich z %2$s adresów.', 'colorify-by-inyfinn' ), colorify_insights_n( count( $rows ) ), colorify_insights_n( $c['total'] ) ) ) . '</p>' : '' );
		}
		$out .= colorify_insights_card(
			__( 'Odwiedzający', 'colorify-by-inyfinn' ),
			$body . '<p class="cfi-foot">' . esc_html( colorify_insights_nb( __( 'Bot: przeglądarka wygląda na robota albo skaner. Podejrzany: ponad 30 wejść na minutę, kilka prób nieistniejących adresów albo wiele wysłanych formularzy (najedź na znacznik, żeby zobaczyć powód). Nasz: adres wykluczony ze statystyk.', 'colorify-by-inyfinn' ) ) ) . '</p>',
			'',
			'',
			'cfi-ip-list',
			__( 'Adresy, z których ktoś wszedł na stronę, od ostatnio widzianych. Odsłony to otwarcia stron przez ludzi, a „wejść” liczy też roboty. „Nie licz w statystykach” sprawia, że odwiedziny z tego adresu znikają z kafelków i wykresów, a „Zablokuj” zamyka temu adresowi dostęp do strony.', 'colorify-by-inyfinn' )
		);
	}

	/* blokady */
	$block = Colorify_Analytics_IP::blocklist();
	$list  = '';
	if ( $block ) {
		$list = '<div class="cfi-scroll"><table class="cfi-table cfi-iptab"><thead><tr><th>' . esc_html__( 'Adres lub zakres', 'colorify-by-inyfinn' ) . '</th><th>' . esc_html__( 'Powód', 'colorify-by-inyfinn' ) . '</th><th class="cfi-hide-s">' . esc_html__( 'Kiedy i kto', 'colorify-by-inyfinn' ) . '</th><th>' . esc_html__( 'Co zrobić', 'colorify-by-inyfinn' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $block ) as $e ) {
			$list .= '<tr><td><code class="cfi-code">' . esc_html( $e['ip'] ) . '</code></td><td data-l="' . esc_attr__( 'Powód', 'colorify-by-inyfinn' ) . '">' . ( '' !== (string) $e['reason'] ? esc_html( $e['reason'] ) : '<span class="cfi-muted">—</span>' ) . '</td>'
				. '<td class="cfi-hide-s">' . esc_html( wp_date( 'j.m.Y H:i', (int) $e['ts'] ) . ( '' !== (string) $e['by'] ? ', ' . $e['by'] : '' ) ) . '</td>'
				. '<td><form class="cfi-ipf" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="colorify_an_ip"><input type="hidden" name="op" value="unblock"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="ip" value="' . esc_attr( $e['ip'] ) . '"><button type="submit" class="cfi-btn cfi-btn--s cfi-btn--ghost">' . esc_html__( 'Odblokuj', 'colorify-by-inyfinn' ) . '</button></form></td></tr>';
		}
		$list .= '</tbody></table></div>';
	} else {
		$list = colorify_insights_state( __( 'Nic nie jest zablokowane.', 'colorify-by-inyfinn' ) );
	}
	$add = '<form class="cfi-excl__form cfi-blk__add" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="colorify_an_ip"><input type="hidden" name="op" value="block"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '">'
		. '<label class="cfi-excl__l"><span>' . esc_html__( 'Adres lub zakres (np. 203.0.113.7 albo 203.0.113.0/24)', 'colorify-by-inyfinn' ) . '</span><input type="text" name="ip" autocomplete="off" spellcheck="false" required></label>'
		. '<label class="cfi-excl__l"><span>' . esc_html__( 'Powód (dla Ciebie)', 'colorify-by-inyfinn' ) . '</span><input type="text" name="reason" maxlength="200" autocomplete="off"></label>'
		. '<div class="cfi-excl__b"><button type="submit" class="cfi-btn">' . esc_html__( 'Zablokuj', 'colorify-by-inyfinn' ) . '</button></div></form>';
	$out .= colorify_insights_card(
		/* translators: %s: liczba wpisów */
		sprintf( __( 'Zablokowane adresy (%s)', 'colorify-by-inyfinn' ), colorify_insights_n( count( $block ) ) ),
		$list . '<h4 class="cfi-h4">' . esc_html__( 'Zablokuj adres ręcznie', 'colorify-by-inyfinn' ) . '</h4>' . $add . '<p class="cfi-foot">' . esc_html( colorify_insights_nb( __( 'Zablokowany adres dostaje komunikat „Dostęp zablokowany” na stronie, w formularzach i przy logowaniu. Ciebie, gdy jesteś zalogowany jako administrator, blokada nie dotyczy. Strony, które serwer podaje z pamięci podręcznej, mogą jeszcze przez chwilę dojść do zablokowanego adresu.', 'colorify-by-inyfinn' ) ) ) . '</p>',
		'',
		'',
		'cfi-ip-blocked',
		__( 'Adresy i zakresy, którym zamknęliśmy dostęp do strony. Zakres zapisujemy z ukośnikiem, np. 203.0.113.0/24 to 256 sąsiednich adresów. Nie da się zablokować własnego adresu ani adresu serwera.', 'colorify-by-inyfinn' )
	);
	return $out;
}

/* ====================================================================== Zapis (przyciski) ====================================================================== */

add_action(
	'admin_post_colorify_an_ip',
	static function () {
		check_admin_referer( 'colorify_an_ip' );
		if ( ! colorify_ip_can() ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'colorify-by-inyfinn' ), '', array( 'response' => 403 ) );
		}
		$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$ip   = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
		$user = wp_get_current_user();
		$code = 'ip';
		if ( 'settings' === $op ) {
			Colorify_Analytics_IP::configure( ! empty( $_POST['enabled'] ), isset( $_POST['days'] ) ? absint( $_POST['days'] ) : null );
			$code = 'saved';
		} elseif ( 'block' === $op ) {
			$reason      = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
			list( $ok, $c ) = Colorify_Analytics_IP::block( $ip, $reason, $user->user_login, Colorify_Analytics::client_ip() );
			$code        = $ok ? 'blocked' : $c;
		} elseif ( 'unblock' === $op ) {
			$code = Colorify_Analytics_IP::unblock( $ip ) ? 'unblocked' : 'nf';
		} elseif ( 'exclude' === $op || 'include' === $op ) {
			$n = Colorify_Analytics_IP::normalize( $ip );
			if ( '' !== $n ) {
				$cur  = Colorify_Analytics::excluded_ips();
				$cur  = 'exclude' === $op ? array_merge( $cur, array( $n ) ) : array_values( array_filter( $cur, static fn( $x ) => Colorify_Analytics_IP::normalize( (string) $x ) !== $n ) );
				$list = Colorify_Analytics::save_excluded_ips( $cur );
				$code = 'exclude' === $op ? 'excluded' : ( Colorify_Analytics_Rules::ip_in_list( $n, $list ) ? 'covered' : 'included' );
			}
		}
		wp_safe_redirect( colorify_ip_url( array( 'cfi_msg' => $code ) ) . ( in_array( $op, array( 'settings', 'block' ), true ) ? '' : '#cfi-ip-list' ) );
		exit;
	}
);
