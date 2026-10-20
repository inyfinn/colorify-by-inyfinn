/* Colorify: widok kafli na klasycznych ekranach edycji. Skrót zwiniętego kafla: pierwsze wypełnione pole. */
( function () {
	'use strict';
	var C = window.colorifyTiles;
	if ( ! C ) { return; }
	var body = document.body;
	var cols = '#normal-sortables > .postbox, #advanced-sortables > .postbox';

	function clean( s, max ) {
		s = String( s || '' ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).replace( /[:*]\s*$/, '' ).trim();
		return s.length > max ? s.slice( 0, max - 1 ) + '…' : s;
	}
	function labelOf( el ) {
		if ( el.labels && el.labels[0] ) { return el.labels[0].textContent; }
		var row = el.closest( '.form-field, tr, p, li' );
		var l = row && row.querySelector( 'label, th' );
		return l ? l.textContent : ( el.getAttribute( 'placeholder' ) || '' );
	}
	function valueOf( el ) {
		var t = ( el.type || '' ).toLowerCase();
		if ( t === 'checkbox' || t === 'radio' ) { return el.checked ? 'tak' : ''; }
		if ( el.tagName === 'SELECT' ) {
			var o = el.options[ el.selectedIndex ];
			return o && el.value !== '' && el.value !== '0' ? o.textContent : '';
		}
		return el.value;
	}
	function fields( box ) {
		var out = [], ticks = [];
		box.querySelectorAll( '.inside input, .inside select, .inside textarea' ).forEach( function ( el ) {
			var t = ( el.type || '' ).toLowerCase();
			if ( /^(hidden|submit|button|file|reset|image|search)$/.test( t ) || /nonce|_wp_http_referer/.test( el.name || '' ) || el.disabled ) { return; }
			var v = clean( valueOf( el ), 80 );
			if ( v ) { ( /^(checkbox|radio)$/.test( t ) ? ticks : out ).push( { l: clean( labelOf( el ), 40 ), v: v } ); }
		} );
		return out.concat( ticks ); // pola z treścią przed zaznaczeniami
	}
	function span( cls, text ) {
		var s = document.createElement( 'span' );
		s.className = cls;
		s.textContent = text;
		return s;
	}
	function summarize( box ) {
		var sum = box.querySelector( ':scope > .cft-sum' );
		if ( ! sum ) {
			sum = document.createElement( 'div' );
			sum.className = 'cft-sum';
			var head = box.querySelector( ':scope > .postbox-header' );
			if ( head ) { head.insertAdjacentElement( 'afterend', sum ); } else { box.prepend( sum ); }
		}
		var f = fields( box );
		sum.textContent = '';
		if ( ! f.length ) {
			sum.appendChild( span( 'cft-sum__muted', 'Puste' ) );
			return;
		}
		if ( f[0].l ) { sum.appendChild( span( 'cft-sum__label', f[0].l ) ); }
		sum.appendChild( span( 'cft-sum__value', f[0].v ) );
		if ( f.length > 1 ) { sum.appendChild( span( 'cft-sum__muted', '+ ' + ( f.length - 1 ) + ( f.length === 2 ? ' pole' : ' pól' ) ) ); }
	}
	function refresh() {
		if ( body.classList.contains( 'colorify-tiles' ) ) { document.querySelectorAll( cols ).forEach( summarize ); }
	}

	// Kliknięcie w nagłówek kafla zwija i rozwija (WordPress sam zapamiętuje stan dla konta).
	document.addEventListener( 'click', function ( e ) {
		if ( ! body.classList.contains( 'colorify-tiles' ) ) { return; }
		if ( e.target.closest( '.postbox .handlediv' ) ) { setTimeout( refresh, 0 ); return; }
		var head = e.target.closest( '.postbox-header' );
		if ( ! head || e.target.closest( 'button, a, input, select, label' ) || ! head.closest( '#normal-sortables, #advanced-sortables' ) ) { return; }
		var btn = head.querySelector( '.handlediv' );
		if ( btn ) { btn.click(); }
	} );
	document.addEventListener( 'change', function ( e ) {
		var box = e.target.closest && e.target.closest( '.postbox' );
		if ( box && body.classList.contains( 'colorify-tiles' ) ) { summarize( box ); }
	} );

	// Pigułka "Widok kafli" przy tytule ekranu, tylko dla administratora.
	if ( C.canEdit ) {
		var h1 = document.querySelector( '.wrap h1.wp-heading-inline, .wrap > h1' );
		if ( h1 ) {
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'cft-toggle';
			var paint = function () {
				var on = body.classList.contains( 'colorify-tiles' );
				b.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				b.textContent = on ? 'Widok kafli: wł.' : 'Widok kafli: wył.';
				b.title = on ? 'Wyłącz kafle na tym rodzaju ekranu' : 'Pokaż skrzynki tego ekranu jako kafle';
			};
			paint();
			b.addEventListener( 'click', function () {
				var on = ! body.classList.contains( 'colorify-tiles' );
				body.classList.toggle( 'colorify-tiles', on );
				if ( on ) {
					// Przy włączeniu zwiń wszystko, żeby od razu było widać skróty.
					// Jeden zapis stanu zamiast klikania każdej skrzynki (osobne zapisy ścigały się na serwerze).
					document.querySelectorAll( cols ).forEach( function ( box ) {
						box.classList.add( 'closed' );
						var h = box.querySelector( '.handlediv' );
						if ( h ) { h.setAttribute( 'aria-expanded', 'false' ); }
					} );
					if ( window.postboxes && window.postboxes.save_state ) { window.postboxes.save_state( window.pagenow ); }
				}
				paint();
				refresh();
				var fd = new FormData();
				fd.append( 'action', 'colorify_tiles_toggle' );
				fd.append( 'nonce', C.nonce );
				fd.append( 'screen', C.screen );
				fd.append( 'on', on ? '1' : '' );
				fetch( C.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } );
			} );
			var after = h1.parentNode.querySelector( ':scope > .page-title-action' ) || h1;
			after.insertAdjacentElement( 'afterend', b );
		}
	}

	refresh();
}() );
