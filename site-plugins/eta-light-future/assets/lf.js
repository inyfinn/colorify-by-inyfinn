/* Katalog Light Future: śnieg, liczniki, zamówienie przedwstępne. */
( function () {
	'use strict';
	var root = document.getElementById( 'lf' );
	if ( ! root ) { return; }

	/* ---------- Śnieg: płatki o różnej wielkości, prędkości i wietrze; mało pracy dla procesora ---------- */
	( function snow() {
		var cv = root.querySelector( '.lf-snow' );
		var ctx = cv && cv.getContext( '2d' );
		if ( ! ctx ) { return; }
		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var dpr = Math.min( window.devicePixelRatio || 1, 1.5 );
		var w = 0, h = 0, flakes = [], wind = 0, windTarget = 0, last = 0, running = true;
		function rnd( a, b ) { return a + Math.random() * ( b - a ); }
		function flake( top ) {
			var r = Math.pow( Math.random(), 2.2 ) * 2.6 + 0.6; // przewaga drobnych płatków, czasem większy
			return { x: rnd( 0, w ), y: top ? rnd( -h, 0 ) : rnd( 0, h ), r: r, vy: 14 + r * 11 + rnd( 0, 10 ), ph: rnd( 0, 6.28 ), sw: rnd( 0.4, 1.6 ), a: rnd( 0.35, 0.9 ) };
		}
		function size() {
			w = root.clientWidth; h = root.clientHeight;
			cv.width = Math.round( w * dpr ); cv.height = Math.round( h * dpr );
			ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
			var n = Math.min( 140, Math.round( w * h / 26000 ) ); // gęstość zależna od powierzchni, z górnym limitem
			while ( flakes.length < n ) { flakes.push( flake( false ) ); }
			flakes.length = n;
		}
		function draw( dt ) {
			ctx.clearRect( 0, 0, w, h );
			if ( Math.random() < 0.004 ) { windTarget = rnd( -18, 18 ); } // podmuchy w losowych chwilach
			wind += ( windTarget - wind ) * Math.min( 1, dt * 0.6 );
			ctx.fillStyle = '#fff';
			for ( var i = 0; i < flakes.length; i++ ) {
				var f = flakes[ i ];
				f.ph += dt * f.sw;
				f.y += f.vy * dt;
				f.x += ( Math.sin( f.ph ) * 12 * f.sw + wind * ( f.r / 2 ) ) * dt;
				if ( f.y > h + 5 ) { flakes[ i ] = f = flake( true ); f.y = -5; }
				if ( f.x > w + 5 ) { f.x = -5; } else if ( f.x < -5 ) { f.x = w + 5; }
				ctx.globalAlpha = f.a;
				ctx.beginPath(); ctx.arc( f.x, f.y, f.r, 0, 6.283 ); ctx.fill();
			}
			ctx.globalAlpha = 1;
		}
		function loop( t ) {
			if ( ! running ) { return; }
			var dt = last ? Math.min( 0.05, ( t - last ) / 1000 ) : 0.016;
			last = t;
			draw( dt );
			requestAnimationFrame( loop );
		}
		size();
		if ( window.ResizeObserver ) { new ResizeObserver( size ).observe( root ); } else { window.addEventListener( 'resize', size ); }
		if ( reduce ) { draw( 0 ); return; } // bez ruchu: nieruchomy śnieg
		document.addEventListener( 'visibilitychange', function () {
			running = ! document.hidden; last = 0;
			if ( running ) { requestAnimationFrame( loop ); }
		} );
		requestAnimationFrame( loop );
	}() );

	/* ---------- Liczniki ---------- */
	var Q = {};
	var items = root.querySelectorAll( '.lf-item' );
	var totalEl = root.querySelector( '[data-total]' );
	var openBtn = root.querySelector( '[data-open]' );
	function total() { var n = 0; for ( var k in Q ) { n += Q[ k ]; } return n; }
	function paint() { var n = total(); totalEl.textContent = n; openBtn.disabled = n === 0; }
	items.forEach( function ( it ) {
		var id = it.getAttribute( 'data-id' );
		var input = it.querySelector( 'input' );
		function set( v ) {
			v = Math.max( 0, Math.min( 999, parseInt( v, 10 ) || 0 ) );
			Q[ id ] = v; input.value = v;
			it.classList.toggle( 'is-on', v > 0 );
			paint();
		}
		it.querySelectorAll( '[data-step]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { set( ( Q[ id ] || 0 ) + parseInt( b.getAttribute( 'data-step' ), 10 ) ); } );
		} );
		input.addEventListener( 'change', function () { set( input.value ); } );
		input.addEventListener( 'focus', function () { input.select(); } );
	} );

	/* ---------- Okno zamówienia ---------- */
	var modal = root.querySelector( '.lf-modal' );
	var form = root.querySelector( '.lf-form' );
	var err = root.querySelector( '.lf-err' );
	var done = root.querySelector( '.lf-done' );
	function names() {
		var out = [];
		items.forEach( function ( it ) {
			var q = Q[ it.getAttribute( 'data-id' ) ] || 0;
			if ( q ) { out.push( '<li><span>' + it.querySelector( '.lf-name' ).textContent + '</span><b>' + q + ' szt.</b></li>' ); }
		} );
		return out.join( '' );
	}
	function open() {
		root.querySelector( '[data-list]' ).innerHTML = names();
		form.hidden = false; done.hidden = true; err.hidden = true;
		modal.hidden = false;
		var first = form.querySelector( 'input' ); if ( first ) { first.focus(); }
	}
	function close() { modal.hidden = true; openBtn.focus(); }
	openBtn.addEventListener( 'click', open );
	root.querySelector( '[data-close]' ).addEventListener( 'click', close );
	modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) { close(); } } );
	document.addEventListener( 'keydown', function ( e ) { if ( 'Escape' === e.key && ! modal.hidden ) { close(); } } );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		var btn = form.querySelector( 'button[type=submit]' );
		var fd = new FormData( form );
		fd.append( 'action', 'eta_lf_order' );
		fd.append( 'nonce', window.etaLF.nonce );
		fd.append( 'items', JSON.stringify( Q ) );
		btn.disabled = true; err.hidden = true;
		fetch( window.etaLF.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					form.hidden = true; done.hidden = false;
					done.querySelector( '[data-no]' ).textContent = res.data.no || '';
					items.forEach( function ( it ) { var i = it.querySelector( 'input' ); i.value = 0; i.dispatchEvent( new Event( 'change' ) ); } );
				} else {
					err.textContent = ( res && res.data && res.data.msg ) || 'Nie udało się wysłać. Spróbuj jeszcze raz.';
					err.hidden = false;
				}
			} )
			.catch( function () { err.textContent = 'Brak połączenia. Spróbuj jeszcze raz.'; err.hidden = false; } )
			.then( function () { btn.disabled = false; } );
	} );
}() );
