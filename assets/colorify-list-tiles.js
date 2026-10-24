/* Colorify: kafle na listach wpisów. Suwak (zapamiętany na rodzaj treści), prawdziwa liczba kolumn na wąskim ekranie,
   mierzenie rzędu akcji (podpisy, potem "..."), tryb edycji pól kafla. */
( function () {
	'use strict';
	var C = window.colorifyListTiles || {};
	var box = document.getElementById( 'cfl' );
	if ( ! box ) { return; }
	var get = function ( k, d ) { try { return window.localStorage.getItem( k ) || d; } catch ( e ) { return d; } };
	var put = function ( k, v ) { try { window.localStorage.setItem( k, v ); } catch ( e ) { /* tryb prywatny */ } };

	// Pod widokami ("Wszystkie | Opublikowane"), nad formularzem listy.
	var form = document.getElementById( 'posts-filter' );
	if ( form && form.parentNode ) { form.parentNode.insertBefore( box, form ); }

	var grid = document.getElementById( 'cfl-grid' );
	if ( ! grid ) { return; }
	var range = document.getElementById( 'cfl-n' );
	var out = document.getElementById( 'cfl-out' );
	var note = document.getElementById( 'cfl-note' );
	var key = 'cfl-n-' + ( C.postType || 'post' );
	var want = 4;

	function real() {
		return getComputedStyle( grid ).gridTemplateColumns.split( ' ' ).filter( Boolean ).length || want;
	}
	/* Akcje w jednym rzędzie: is-c1 bez podpisów, is-c2 akcje w "...", is-c3 sam ołówek. */
	function fit() {
		[].forEach.call( grid.querySelectorAll( '.cfl-card[data-id]' ), function ( card ) {
			var row = card.querySelector( '.cfl-actions' );
			if ( ! row ) { return; }
			card.classList.remove( 'is-c1', 'is-c2', 'is-c3' );
			for ( var i = 1; i <= 3 && row.scrollWidth > row.clientWidth + 1; i++ ) {
				card.classList.add( 'is-c' + i );
			}
		} );
	}
	function paint() {
		var n = real();
		if ( out ) { out.textContent = n + ' w rzędzie'; }
		if ( note ) {
			note.hidden = n >= want;
			note.textContent = n < want ? 'Ekran jest za wąski na ' + want + ' w rzędzie, widok zmieniony na ' + n + '. Poszerz okno, żeby wrócić do ' + want + '.' : '';
		}
		fit();
	}
	function size( n ) {
		want = Math.max( 3, Math.min( 10, parseInt( n, 10 ) || 4 ) );
		box.style.setProperty( '--cfl-n', want );
		if ( range ) { range.value = want; }
		put( key, want );
		paint();
	}
	if ( range ) { range.addEventListener( 'input', function () { size( range.value ); } ); }
	size( get( key, '4' ) );
	if ( window.ResizeObserver ) {
		var t = 0;
		new window.ResizeObserver( function () { cancelAnimationFrame( t ); t = requestAnimationFrame( paint ); } ).observe( grid );
	} else {
		window.addEventListener( 'resize', paint );
	}
	if ( document.fonts && document.fonts.ready ) { document.fonts.ready.then( fit ); }

	/* "...": jedno menu naraz, zamyka klik obok i Escape. */
	document.addEventListener( 'click', function ( e ) {
		[].forEach.call( grid.querySelectorAll( '.cfl-menu[open]' ), function ( d ) { if ( ! d.contains( e.target ) ) { d.open = false; } } );
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) { return; }
		[].forEach.call( grid.querySelectorAll( '.cfl-menu[open]' ), function ( d ) { d.open = false; d.querySelector( 'summary' ).focus(); } );
	} );

	/* Tryb edycji: co pokazuje kafel (dla wszystkich kont, zapis w opcjach witryny). */
	var toggle = box.querySelector( '.cfl-edit-toggle' );
	var panel = document.getElementById( 'cfl-edit' );
	if ( ! toggle || ! panel || ! C.nonce ) { return; }
	var msg = panel.querySelector( '.cfl-edit-msg' );
	toggle.addEventListener( 'click', function () {
		panel.hidden = ! panel.hidden;
		toggle.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
	} );
	function send( reset ) {
		var body = new URLSearchParams();
		body.append( 'action', 'colorify_list_tiles_save' );
		body.append( 'nonce', C.nonce );
		body.append( 'post_type', C.postType );
		if ( reset ) {
			body.append( 'reset', '1' );
		} else {
			[].forEach.call( panel.querySelectorAll( 'input[type=checkbox]:checked' ), function ( c ) { body.append( 'fields[]', c.value ); } );
		}
		msg.textContent = 'Zapisuję...';
		fetch( C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j || ! j.success ) { throw new Error( 'save' ); }
				msg.textContent = 'Zapisano, odświeżam...';
				window.location.reload();
			} )
			.catch( function () { msg.textContent = 'Nie udało się zapisać. Odśwież stronę i spróbuj ponownie.'; } );
	}
	panel.querySelector( '[data-cfl-save]' ).addEventListener( 'click', function () { send( false ); } );
	panel.querySelector( '[data-cfl-reset]' ).addEventListener( 'click', function () { send( true ); } );
}() );
