/* ETA: edytor tekstów na karcie produktu (punkty = repeater, ramka, napis na przycisku). */
( function ( $ ) {
	'use strict';

	$( function () {
		var $list = $( '#eta-card-bullets-list' );

		/* ---------- punkty ---------- */
		if ( $list.length ) {
			var tpl = document.getElementById( 'eta-card-row-tpl' );

			var newRow = function ( text ) {
				var $row = $( $.trim( tpl.innerHTML ) );
				$row.find( 'input' ).val( text || '' );
				return $row;
			};

			var refresh = function () {
				var $rows = $list.children( '.eta-card-row' );
				$rows.each( function ( i ) {
					var $r = $( this );
					$r.find( '.eta-card-input' ).attr( 'aria-label', 'Punkt ' + ( i + 1 ) );
					$r.find( '.eta-card-up' ).prop( 'disabled', 0 === i );
					$r.find( '.eta-card-down' ).prop( 'disabled', i === $rows.length - 1 );
				} );
			};

			var focusRow = function ( $row ) {
				$row.find( '.eta-card-input' ).trigger( 'focus' );
			};

			var addAfter = function ( $after, text ) {
				var $row = newRow( text );
				if ( $after && $after.length ) {
					$after.after( $row );
				} else {
					$list.append( $row );
				}
				refresh();
				focusRow( $row );
				return $row;
			};

			$list.sortable( {
				handle: '.eta-card-handle',
				axis: 'y',
				items: '> .eta-card-row',
				tolerance: 'pointer',
				update: refresh
			} );

			$list.on( 'click', '.eta-card-dup', function () {
				var $row = $( this ).closest( '.eta-card-row' );
				addAfter( $row, $row.find( '.eta-card-input' ).val() );
			} );

			$list.on( 'click', '.eta-card-up', function () {
				var $row = $( this ).closest( '.eta-card-row' );
				$row.prev( '.eta-card-row' ).before( $row );
				refresh();
				$row.find( '.eta-card-up' ).not( ':disabled' ).add( $row.find( '.eta-card-down' ) ).not( ':disabled' ).first().trigger( 'focus' );
			} );

			$list.on( 'click', '.eta-card-down', function () {
				var $row = $( this ).closest( '.eta-card-row' );
				$row.next( '.eta-card-row' ).after( $row );
				refresh();
				$row.find( '.eta-card-down' ).not( ':disabled' ).add( $row.find( '.eta-card-up' ) ).not( ':disabled' ).first().trigger( 'focus' );
			} );

			$list.on( 'click', '.eta-card-del', function () {
				var $row = $( this ).closest( '.eta-card-row' );
				var $next = $row.next( '.eta-card-row' );
				var $target = $next.length ? $next : $row.prev( '.eta-card-row' );
				$row.remove();
				if ( ! $list.children( '.eta-card-row' ).length ) {
					addAfter( null, '' );
					return;
				}
				refresh();
				focusRow( $target );
			} );

			// Enter w polu dodaje nowy wiersz pod spodem (i nie wysyła formularza produktu).
			$list.on( 'keydown', '.eta-card-input', function ( e ) {
				if ( 13 === e.which ) {
					e.preventDefault();
					addAfter( $( this ).closest( '.eta-card-row' ), '' );
				}
			} );

			$( '.eta-card-add' ).on( 'click', function () {
				addAfter( $list.children( '.eta-card-row' ).last(), '' );
			} );

			$( '.eta-card-restore' ).on( 'click', function () {
				var defs = [];
				// Przy zakresie „wszystkie” przywracamy tekst z kodu, inaczej obecny domyślny (z opcji albo z kodu).
				var attr = $( 'input[name="eta_card_scope_bullets"]:checked' ).val() === 'all' ? 'data-code-defaults' : 'data-defaults';
				try {
					defs = JSON.parse( $list.attr( attr ) || '[]' );
				} catch ( err ) {
					defs = [];
				}
				$list.empty();
				if ( ! defs.length ) {
					defs = [ '' ];
				}
				$.each( defs, function ( i, t ) {
					$list.append( newRow( t ) );
				} );
				refresh();
				focusRow( $list.children( '.eta-card-row' ).first() );
			} );

			refresh();
		}

		/* ---------- przełącznik zakresu: tekst pomocniczy ---------- */
		$( '.eta-card-scope-switch input[type="radio"]' ).on( 'change', function () {
			var $f = $( this ).closest( '.eta-card-scope-switch' );
			$f.find( '.eta-card-scope-help strong' ).text( $f.find( '.eta-card-scope-help' ).attr( 'data-' + this.value ) );
		} );

		/* ---------- ramka ---------- */
		$( '.eta-card-restore-notice' ).on( 'click', function () {
			var all = $( 'input[name="eta_card_scope_notice"]:checked' ).val() === 'all';
			var def = $( '.eta-card-notice-wrap' ).attr( all ? 'data-code-default' : 'data-default' ) || '';
			var ed = window.tinymce && window.tinymce.get( 'eta_card_notice_editor' );
			$( '#eta_card_notice_editor' ).val( def );
			if ( ed && ! ed.isHidden() ) {
				ed.setContent( def );
				ed.focus();
			} else {
				$( '#eta_card_notice_editor' ).trigger( 'focus' );
			}
		} );

		/* ---------- napis na przycisku ---------- */
		var $sel = $( '#eta_card_button_sel' );
		if ( $sel.length && $.fn.selectWoo ) {
			$sel.selectWoo( {
				width: '100%',
				tags: true,
				minimumResultsForSearch: 0,
				language: {
					noResults: function () {
						return 'Brak wyników. Wpisz własny napis.';
					},
					searching: function () {
						return 'Szukam…';
					}
				}
			} );
			$( '.eta-card-fallback' ).hide();
		}
	} );
}( jQuery ) );
