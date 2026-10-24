<?php
/**
 * Kafle na listach wpisów (edit.php): przełącznik "Kafle / Lista", suwak wielkości kafli (3-10 w rzędzie)
 * i tryb edycji, w którym administrator wybiera, co pokazuje kafel danego rodzaju treści.
 *
 * Kafel zna tylko to, co ma każdy wpis (obrazek, tytuł, status, data, autor, zajawka, taksonomie, pola własne bez
 * podkreślenia). Wtyczka strony dokłada własne pola filtrem `colorify_list_tile_fields` (np. adres i godziny sklepu)
 * i własny obrazek filtrem `colorify_list_tile_image`. Wybór widoku zapamiętuje każde konto osobno; domyślnie lista
 * (filtr `colorify_list_tiles_default_view`). Tabela WordPressa zostaje bez zmian, kafle ją tylko zastępują na ekranie.
 *
 * @package Colorify_By_Inyfinn
 */

defined( 'ABSPATH' ) || exit;

const COLORIFY_LT_OPTION    = 'colorify_list_tiles';
const COLORIFY_LT_VIEW_META = 'colorify_list_view';

/**
 * Rodzaj treści bieżącej listy, jeśli da się na niej pokazać kafle, inaczej ''.
 * Działa też w pre_get_posts (przed ustawieniem ekranu), dlatego opiera się na $pagenow i $typenow.
 */
function colorify_lt_post_type(): string {
	global $pagenow, $typenow;
	if ( ! is_admin() || 'edit.php' !== $pagenow ) {
		return '';
	}
	$pt = '' !== (string) $typenow ? (string) $typenow : ( isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : 'post' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! post_type_exists( $pt ) || in_array( $pt, (array) apply_filters( 'colorify_list_tiles_excluded_post_types', array( 'kw_usluga' ) ), true ) ) {
		return '';
	}
	return $pt;
}

/**
 * Widok listy dla konta: 'tiles' albo 'list'.
 *
 * @param string $pt Rodzaj treści.
 */
function colorify_lt_view( string $pt ): string {
	$saved = (array) get_user_meta( get_current_user_id(), COLORIFY_LT_VIEW_META, true );
	if ( isset( $saved[ $pt ] ) && in_array( $saved[ $pt ], array( 'tiles', 'list' ), true ) ) {
		return $saved[ $pt ];
	}
	return 'tiles' === apply_filters( 'colorify_list_tiles_default_view', 'list', $pt ) ? 'tiles' : 'list';
}

/**
 * Czy bieżąca lista jest pokazana jako kafle. Dla wtyczek stron (np. zawężenie zapytania tylko w kaflach).
 */
function colorify_lt_active(): bool {
	$pt = colorify_lt_post_type();
	return '' !== $pt && 'tiles' === colorify_lt_view( $pt );
}

/* ?cfl_view=tiles|list zapisuje wybór konta (link przełącznika działa też bez JavaScriptu). */
add_action(
	'load-edit.php',
	static function () {
		$pt = colorify_lt_post_type();
		$v  = isset( $_GET['cfl_view'] ) ? sanitize_key( wp_unslash( $_GET['cfl_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $pt || ! in_array( $v, array( 'tiles', 'list' ), true ) ) {
			return;
		}
		$saved        = (array) get_user_meta( get_current_user_id(), COLORIFY_LT_VIEW_META, true );
		$saved[ $pt ] = $v;
		update_user_meta( get_current_user_id(), COLORIFY_LT_VIEW_META, $saved );
	},
	1
);

/**
 * Klucze pól własnych (bez podkreślenia, czyli widoczne w edytorze) używanych przez dany rodzaj treści.
 *
 * @param string $pt Rodzaj treści.
 */
function colorify_lt_meta_keys( string $pt ): array {
	$key  = 'colorify_lt_meta2_' . $pt; // Tylko pola z prostą wartością (bez tablic motywów).
	$keys = get_transient( $key );
	if ( ! is_array( $keys ) ) {
		global $wpdb;
		$keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s AND pm.meta_value <> '' AND pm.meta_value NOT LIKE %s AND pm.meta_value NOT LIKE %s ORDER BY pm.meta_key LIMIT 40",
				$pt,
				$wpdb->esc_like( '_' ) . '%',
				'a:%',
				'O:%'
			)
		);
		$keys = array_values( array_filter( array_map( 'strval', (array) $keys ) ) );
		set_transient( $key, $keys, 12 * HOUR_IN_SECONDS );
	}
	return $keys;
}

/**
 * Pola kafla dla rodzaju treści: klucz => [label, group, place (image|badge|kicker|body), default, render( WP_Post ): string].
 * render zwraca gotowy HTML (pusty = pole się nie pokazuje); Colorify przepuszcza go przez wp_kses_post.
 *
 * @param string $pt Rodzaj treści.
 */
function colorify_lt_fields( string $pt ): array {
	static $memo = array();
	if ( isset( $memo[ $pt ] ) ) {
		return $memo[ $pt ];
	}
	$f = array(
		'image'   => array(
			'label'   => __( 'Obrazek', 'colorify-by-inyfinn' ),
			'group'   => __( 'Wpis', 'colorify-by-inyfinn' ),
			'place'   => 'image',
			'default' => true,
			'render'  => static function ( WP_Post $p ): string {
				$url = (string) apply_filters( 'colorify_list_tile_image', (string) get_the_post_thumbnail_url( $p, 'medium_large' ), $p );
				return '' !== $url ? '<img src="' . esc_url( $url ) . '" alt="" loading="lazy" decoding="async">' : '';
			},
		),
		'status'  => array(
			'label'   => __( 'Status (szkic, zaplanowany...)', 'colorify-by-inyfinn' ),
			'group'   => __( 'Wpis', 'colorify-by-inyfinn' ),
			'place'   => 'badge',
			'default' => true,
			'render'  => static function ( WP_Post $p ): string {
				if ( 'publish' === $p->post_status ) {
					return '';
				}
				$o = get_post_status_object( $p->post_status );
				return $o ? esc_html( $o->label ) : '';
			},
		),
		'date'    => array(
			'label'   => __( 'Data', 'colorify-by-inyfinn' ),
			'group'   => __( 'Wpis', 'colorify-by-inyfinn' ),
			'place'   => 'body',
			'default' => false,
			'render'  => static function ( WP_Post $p ): string {
				return esc_html( (string) get_the_date( '', $p ) );
			},
		),
		'author'  => array(
			'label'   => __( 'Autor', 'colorify-by-inyfinn' ),
			'group'   => __( 'Wpis', 'colorify-by-inyfinn' ),
			'place'   => 'body',
			'default' => false,
			'render'  => static function ( WP_Post $p ): string {
				return esc_html( (string) get_the_author_meta( 'display_name', (int) $p->post_author ) );
			},
		),
		'excerpt' => array(
			'label'   => __( 'Zajawka', 'colorify-by-inyfinn' ),
			'group'   => __( 'Wpis', 'colorify-by-inyfinn' ),
			'place'   => 'body',
			'default' => false,
			'render'  => static function ( WP_Post $p ): string {
				$t = '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : wp_strip_all_tags( strip_shortcodes( $p->post_content ) );
				return esc_html( wp_trim_words( $t, 22 ) );
			},
		),
	);
	foreach ( get_object_taxonomies( $pt, 'objects' ) as $tax ) {
		if ( ! $tax->show_ui || 'post_format' === $tax->name ) {
			continue;
		}
		$name                = $tax->name;
		$f[ 'tax:' . $name ] = array(
			'label'   => $tax->labels->name,
			'group'   => __( 'Taksonomie', 'colorify-by-inyfinn' ),
			'place'   => 'body',
			'default' => false,
			'render'  => static function ( WP_Post $p ) use ( $name ): string {
				$terms = get_the_terms( $p, $name );
				return is_array( $terms ) ? esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) : '';
			},
		);
	}
	foreach ( colorify_lt_meta_keys( $pt ) as $mk ) {
		$f[ 'meta:' . $mk ] = array(
			'label'   => ucfirst( trim( str_replace( array( '_', '-' ), ' ', $mk ) ) ),
			'group'   => __( 'Pola własne', 'colorify-by-inyfinn' ),
			'place'   => 'body',
			'default' => false,
			'render'  => static function ( WP_Post $p ) use ( $mk ): string {
				$v = get_post_meta( $p->ID, $mk, true );
				return is_scalar( $v ) && '' !== trim( (string) $v ) ? esc_html( wp_trim_words( (string) $v, 24 ) ) : '';
			},
		);
	}

	/**
	 * Pola kafla od wtyczki strony. Ten sam kształt co pola wbudowane; 'group' trafia do trybu edycji.
	 *
	 * @param array  $f  Pola.
	 * @param string $pt Rodzaj treści.
	 */
	$f = (array) apply_filters( 'colorify_list_tile_fields', $f, $pt );
	foreach ( $f as $k => $d ) {
		if ( ! is_array( $d ) || ! isset( $d['render'] ) || ! is_callable( $d['render'] ) ) {
			unset( $f[ $k ] );
			continue;
		}
		$f[ $k ] += array( 'label' => (string) $k, 'group' => __( 'Inne', 'colorify-by-inyfinn' ), 'place' => 'body', 'default' => false );
	}
	$memo[ $pt ] = $f;
	return $f;
}

/**
 * Pola włączone dla rodzaju treści (zapisane w trybie edycji albo domyślne).
 *
 * @param string $pt Rodzaj treści.
 */
function colorify_lt_enabled( string $pt ): array {
	$all  = colorify_lt_fields( $pt );
	$conf = (array) get_option( COLORIFY_LT_OPTION, array() );
	if ( isset( $conf[ $pt ]['fields'] ) && is_array( $conf[ $pt ]['fields'] ) ) {
		return array_values( array_intersect( array_keys( $all ), $conf[ $pt ]['fields'] ) );
	}
	return array_keys( array_filter( $all, static fn( $d ) => ! empty( $d['default'] ) ) );
}

add_action(
	'wp_ajax_colorify_list_tiles_save',
	static function () {
		check_ajax_referer( 'colorify-list-tiles', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$pt = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		if ( ! post_type_exists( $pt ) ) {
			wp_send_json_error( null, 400 );
		}
		$conf = (array) get_option( COLORIFY_LT_OPTION, array() );
		if ( ! empty( $_POST['reset'] ) ) {
			unset( $conf[ $pt ] );
		} else {
			$fields      = isset( $_POST['fields'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['fields'] ) ) : array();
			$conf[ $pt ] = array( 'fields' => array_values( array_unique( $fields ) ) );
		}
		update_option( COLORIFY_LT_OPTION, $conf, false );
		wp_send_json_success();
	}
);

add_filter(
	'admin_body_class',
	static function ( $classes ) {
		$classes = is_string( $classes ) ? $classes : '';
		return colorify_lt_active() ? trim( $classes . ' colorify-list-tiles' ) : $classes;
	},
	21
);

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'edit.php' !== $hook || '' === colorify_lt_post_type() ) {
			return;
		}
		wp_enqueue_style( 'colorify-list-tiles', COLORIFY_PLUGIN_URL . 'assets/colorify-list-tiles.css', array(), COLORIFY_PLUGIN_VERSION );
		wp_enqueue_script( 'colorify-list-tiles', COLORIFY_PLUGIN_URL . 'assets/colorify-list-tiles.js', array(), COLORIFY_PLUGIN_VERSION, true );
		wp_add_inline_script(
			'colorify-list-tiles',
			'window.colorifyListTiles = ' . wp_json_encode(
				array(
					'postType' => colorify_lt_post_type(),
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => current_user_can( 'manage_options' ) ? wp_create_nonce( 'colorify-list-tiles' ) : '',
				),
				JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
			) . ';',
			'before'
		);
	},
	112
);

/**
 * Ikona SVG akcji (stałe ścieżki, bez danych z zewnątrz).
 *
 * @param string $name Nazwa ikony.
 */
function colorify_lt_icon( string $name ): string {
	$d = array(
		'edit'  => 'M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4',
		'dup'   => 'M9 9h11v11H9zM5 15H4V4h11v1',
		'view'  => 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
		'trash' => 'M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 11v6M14 11v6',
		'more'  => 'M12 5v.01M12 12v.01M12 19v.01',
		'dot'   => 'M12 12v.01',
	);
	return '<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . ( $d[ $name ] ?? $d['dot'] ) . '"/></svg>';
}

/**
 * Akcje wiersza jak w tabeli WordPressa (edycja, kosz, podgląd) plus akcje innych wtyczek z post_row_actions.
 * Zwraca [ 'edit' => url, 'quick' => [ [kind, label, url, attrs] ], 'menu' => [ [label, html] ] ].
 *
 * @param WP_Post $p Wpis.
 */
function colorify_lt_actions( WP_Post $p ): array {
	$out  = array( 'edit' => '', 'quick' => array(), 'menu' => array() );
	$type = get_post_type_object( $p->post_type );
	if ( current_user_can( 'edit_post', $p->ID ) ) {
		$out['edit'] = (string) get_edit_post_link( $p->ID, 'raw' );
	}
	$core = array();
	if ( current_user_can( 'delete_post', $p->ID ) && 'trash' !== $p->post_status && EMPTY_TRASH_DAYS ) {
		$core['trash'] = '<a href="' . esc_url( get_delete_post_link( $p->ID ) ) . '">' . esc_html__( 'Przenieś do kosza', 'colorify-by-inyfinn' ) . '</a>';
	}
	if ( $type && $type->public && 'trash' !== $p->post_status ) {
		$core['view'] = '<a href="' . esc_url( (string) get_permalink( $p ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Zobacz', 'colorify-by-inyfinn' ) . '</a>';
	}
	$hook    = is_post_type_hierarchical( $p->post_type ) ? 'page_row_actions' : 'post_row_actions';
	$actions = (array) apply_filters( $hook, $core + array( 'edit' => '' ), $p );
	unset( $actions['edit'], $actions['inline hide-if-no-js'], $actions['inline'] );
	foreach ( $actions as $key => $html ) {
		$html = (string) $html;
		if ( '' === trim( wp_strip_all_tags( $html ) ) || ! preg_match( '~href=(["\'])(.*?)\1~', $html, $m ) ) {
			continue;
		}
		$url   = html_entity_decode( $m[2] );
		$label = trim( wp_strip_all_tags( $html ) );
		$kind  = 'trash' === $key ? 'trash' : ( 'view' === $key ? 'view' : ( preg_match( '/dupl|clone|copy/i', (string) $key ) ? 'dup' : '' ) );
		$blank = false !== strpos( $html, 'target="_blank"' );
		if ( '' !== $kind ) {
			$out['quick'][ $kind ] = array( $kind, 'dup' === $kind ? __( 'Duplikuj', 'colorify-by-inyfinn' ) : $label, $url, $blank );
		}
		$out['menu'][] = array( '' !== $kind ? $kind : 'dot', 'dup' === $kind ? __( 'Duplikuj', 'colorify-by-inyfinn' ) : $label, $url, $blank, 'trash' === $kind );
	}
	// Stała kolejność szybkich przycisków: duplikuj, zobacz, kosz.
	$out['quick'] = array_values( array_filter( array( $out['quick']['dup'] ?? null, $out['quick']['view'] ?? null, $out['quick']['trash'] ?? null ) ) );
	return $out;
}

/* Kafle i pasek nad tabelą. Drukowane na serwerze (działają bez JavaScriptu); skrypt tylko przenosi je pod widoki. */
add_action(
	'admin_notices',
	static function () {
		global $wp_query;
		$pt = colorify_lt_post_type();
		if ( '' === $pt || ! $wp_query instanceof WP_Query || ! is_array( $wp_query->posts ) ) {
			return;
		}
		$tiles  = 'tiles' === colorify_lt_view( $pt );
		$fields = colorify_lt_fields( $pt );
		$on     = colorify_lt_enabled( $pt );
		$admin  = current_user_can( 'manage_options' );
		$type   = get_post_type_object( $pt );
		?>
<div id="cfl" class="cfl" data-post-type="<?php echo esc_attr( $pt ); ?>">
	<div class="cfl-bar">
		<?php if ( $tiles && $admin ) : ?>
			<button type="button" class="cfl-pill cfl-edit-toggle" aria-expanded="false" aria-controls="cfl-edit"><?php echo colorify_lt_icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php esc_html_e( 'Dostosuj kafelki', 'colorify-by-inyfinn' ); ?></button>
		<?php endif; ?>
		<div class="cfl-right">
			<div class="cfl-views" role="group" aria-label="<?php esc_attr_e( 'Widok listy', 'colorify-by-inyfinn' ); ?>">
				<a class="cfl-view<?php echo $tiles ? ' is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'cfl_view', 'tiles' ) ); ?>"<?php echo $tiles ? ' aria-current="true"' : ''; ?>><?php esc_html_e( 'Kafle', 'colorify-by-inyfinn' ); ?></a>
				<a class="cfl-view<?php echo $tiles ? '' : ' is-on'; ?>" href="<?php echo esc_url( add_query_arg( 'cfl_view', 'list' ) ); ?>"<?php echo $tiles ? '' : ' aria-current="true"'; ?>><?php esc_html_e( 'Lista', 'colorify-by-inyfinn' ); ?></a>
			</div>
			<?php if ( $tiles ) : ?>
				<label class="cfl-size"><?php esc_html_e( 'Rozmiar kafelków', 'colorify-by-inyfinn' ); ?>
					<span><?php esc_html_e( 'duże', 'colorify-by-inyfinn' ); ?></span><input type="range" min="3" max="10" step="1" value="4" id="cfl-n"><span><?php esc_html_e( 'małe', 'colorify-by-inyfinn' ); ?></span>
					<output id="cfl-out">4</output>
				</label>
			<?php endif; ?>
		</div>
		<p class="cfl-note" id="cfl-note" role="status" hidden></p>
	</div>
		<?php if ( ! $tiles ) : ?>
</div>
			<?php
			return;
		endif;
		if ( $admin ) :
			$groups = array();
			foreach ( $fields as $k => $d ) {
				$groups[ $d['group'] ][ $k ] = $d;
			}
			?>
	<div class="cfl-edit" id="cfl-edit" hidden>
		<p class="cfl-edit-lead"><?php esc_html_e( 'Zaznacz, co ma być widać na każdym kafelku tej listy. Ustawienie obowiązuje wszystkich użytkowników.', 'colorify-by-inyfinn' ); ?></p>
		<div class="cfl-edit-groups">
			<?php foreach ( $groups as $g => $items ) : ?>
				<fieldset><legend><?php echo esc_html( $g ); ?></legend>
					<?php foreach ( $items as $k => $d ) : ?>
						<label><input type="checkbox" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $on, true ) ); ?>> <?php echo esc_html( $d['label'] ); ?></label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
		</div>
		<div class="cfl-edit-actions">
			<button type="button" class="cfl-pill is-primary" data-cfl-save><?php esc_html_e( 'Zapisz', 'colorify-by-inyfinn' ); ?></button>
			<button type="button" class="cfl-pill" data-cfl-reset><?php esc_html_e( 'Przywróć domyślne', 'colorify-by-inyfinn' ); ?></button>
			<span class="cfl-edit-msg" role="status"></span>
		</div>
	</div>
		<?php endif; ?>
	<div class="cfl-grid" id="cfl-grid">
		<?php if ( $type && current_user_can( $type->cap->create_posts ) ) : ?>
			<a class="cfl-card cfl-add" href="<?php echo esc_url( admin_url( 'post' === $pt ? 'post-new.php' : 'post-new.php?post_type=' . $pt ) ); ?>">
				<span class="cfl-plus" aria-hidden="true">+</span>
				<b><?php echo esc_html( $type->labels->add_new_item ); ?></b>
			</a>
		<?php endif; ?>
		<?php
		foreach ( $wp_query->posts as $p ) :
			$p = get_post( $p );
			if ( ! $p instanceof WP_Post ) {
				continue;
			}
			$parts = array( 'image' => '', 'badge' => '', 'kicker' => array(), 'body' => array() );
			foreach ( $on as $k ) {
				$d    = $fields[ $k ];
				$html = trim( (string) call_user_func( $d['render'], $p ) );
				if ( '' === $html ) {
					continue;
				}
				if ( 'image' === $d['place'] || 'badge' === $d['place'] ) {
					$parts[ $d['place'] ] .= $html;
				} else {
					$parts[ 'kicker' === $d['place'] ? 'kicker' : 'body' ][] = array( $d['label'], $html, ! empty( $d['no_label'] ) );
				}
			}
			$a     = colorify_lt_actions( $p );
			$title = '' !== trim( $p->post_title ) ? get_the_title( $p ) : __( '(bez tytułu)', 'colorify-by-inyfinn' );
			$title = (string) apply_filters( 'colorify_list_tile_title', html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ), $p );
			?>
		<article class="cfl-card" data-id="<?php echo (int) $p->ID; ?>">
			<?php if ( in_array( 'image', $on, true ) ) : ?>
				<a class="cfl-img" href="<?php echo esc_url( $a['edit'] ); ?>" tabindex="-1" aria-hidden="true"><?php echo wp_kses_post( $parts['image'] ); ?>
					<?php if ( '' !== $parts['badge'] ) : ?><span class="cfl-state"><?php echo wp_kses_post( $parts['badge'] ); ?></span><?php endif; ?>
				</a>
			<?php elseif ( '' !== $parts['badge'] ) : ?>
				<span class="cfl-state is-inline"><?php echo wp_kses_post( $parts['badge'] ); ?></span>
			<?php endif; ?>
			<div class="cfl-body">
				<?php if ( $parts['kicker'] ) : ?><p class="cfl-kicker"><?php echo wp_kses_post( implode( ' · ', wp_list_pluck( $parts['kicker'], 1 ) ) ); ?></p><?php endif; ?>
				<h3 class="cfl-title"><?php if ( $a['edit'] ) : ?><a href="<?php echo esc_url( $a['edit'] ); ?>"><?php echo esc_html( $title ); ?></a><?php else : ?><?php echo esc_html( $title ); ?><?php endif; ?></h3>
				<?php if ( $parts['body'] ) : ?>
					<div class="cfl-rows">
						<?php foreach ( $parts['body'] as $r ) : ?>
							<div class="cfl-row"><?php if ( ! $r[2] ) : ?><span><?php echo esc_html( $r[0] ); ?></span><?php endif; ?><div><?php echo wp_kses_post( $r[1] ); ?></div></div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<div class="cfl-actions">
					<?php // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- colorify_lt_icon() returns fixed SVG. ?>
					<?php if ( $a['edit'] ) : ?><a class="cfl-btn is-primary" href="<?php echo esc_url( $a['edit'] ); ?>" title="<?php esc_attr_e( 'Edytuj', 'colorify-by-inyfinn' ); ?>"><?php echo colorify_lt_icon( 'edit' ); ?><span class="cfl-lbl"><?php esc_html_e( 'Edytuj', 'colorify-by-inyfinn' ); ?></span></a><?php endif; ?>
					<?php foreach ( $a['quick'] as $q ) : ?>
						<a class="cfl-btn cfl-q<?php echo 'trash' === $q[0] ? ' cfl-trash' : ''; ?>" href="<?php echo esc_url( $q[2] ); ?>" title="<?php echo esc_attr( $q[1] ); ?>" aria-label="<?php echo esc_attr( $q[1] ); ?>"<?php echo $q[3] ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo colorify_lt_icon( $q[0] ); ?><span class="cfl-lbl"><?php echo esc_html( $q[1] ); ?></span></a>
					<?php endforeach; ?>
					<?php if ( $a['menu'] ) : ?>
						<details class="cfl-menu<?php echo count( $a['menu'] ) > count( $a['quick'] ) ? ' has-more' : ''; ?>">
							<summary class="cfl-btn" title="<?php esc_attr_e( 'Więcej akcji', 'colorify-by-inyfinn' ); ?>" aria-label="<?php esc_attr_e( 'Więcej akcji', 'colorify-by-inyfinn' ); ?>"><?php echo colorify_lt_icon( 'more' ); ?></summary>
							<div class="cfl-pop">
								<?php foreach ( $a['menu'] as $m ) : ?>
									<a href="<?php echo esc_url( $m[2] ); ?>"<?php echo $m[3] ? ' target="_blank" rel="noopener"' : ''; ?><?php echo $m[4] ? ' class="is-bad"' : ''; ?>><?php echo colorify_lt_icon( $m[0] ); ?><?php echo esc_html( $m[1] ); ?></a>
								<?php endforeach; ?>
							</div>
						</details>
					<?php endif; ?>
					<?php // phpcs:enable ?>
				</div>
			</div>
		</article>
		<?php endforeach; ?>
	</div>
</div>
		<?php
	}
);
