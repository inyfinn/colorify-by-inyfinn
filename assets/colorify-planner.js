/*
 * Colorify: kokpit "Kalendarz zleceń" i "Rezerwacje".
 *  - kalendarz miesiąca rysowany z danych wtyczki (window.colorifyPlanner): przełączanie miesięcy i lat bez przeładowania,
 *    wybór miesiąca/roku (klik albo wpisanie), strzałki ←/→ (miesiąc) i Shift+←/→ (rok);
 *  - jedna karta szczegółów dla wszystkich wydarzeń (kalendarz, listy, kafel najbliższego): pokazuje się po najechaniu
 *    albo fokusie, znika po 0,5 s, gdy kursor jej opuści, i nie znika, gdy kursor wróci na wydarzenie albo wejdzie na kartę;
 *    klik/dotyk/Enter przypina kartę; w karcie: Skopiuj informacje, Otwórz zlecenie, Zadzwoń, Trasa, Poprzednie/Następne.
 * Tekst wstawiamy zawsze przez textContent; innerHTML tylko dla ikon z serwera.
 */
( function () {
	'use strict';

	var cfg = window.colorifyPlanner;
	var root = document.querySelector( '.cfd' );
	var card = document.getElementById( 'cfb-card' );
	if ( ! cfg || ! root || ! card || ! Array.isArray( cfg.events ) ) {
		return;
	}

	var CLOSE_DELAY = 500;
	var OPEN_DELAY = 90;
	var T = cfg.i18n || {};
	var events = cfg.events;
	var byDate = {};
	var navList = [];
	var navPos = {};
	var mobileQuery = window.matchMedia ? window.matchMedia( '(max-width: 1023px)' ) : { matches: false };
	var live = root.querySelector( '.cfb-live' );

	events.forEach( function ( e ) {
		( byDate[ e.date ] = byDate[ e.date ] || [] ).push( e );
		if ( 'blokada' !== e.type ) {
			navPos[ e.i ] = navList.length;
			navList.push( e );
		}
	} );
	var RANK = { wesele: 0, kontakt: 1, oddanie: 2, przygotowanie: 3, przypomnienie: 4, blokada: 9 };
	Object.keys( byDate ).forEach( function ( d ) {
		byDate[ d ].sort( function ( a, b ) {
			return ( RANK[ a.type ] || 5 ) - ( RANK[ b.type ] || 5 ) || a.i - b.i;
		} );
	} );

	/* ---------- Pomocnicze ---------- */

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	function icon( name ) {
		var s = el( 'span', 'cfb-ico-i' );
		s.style.display = 'inline-flex';
		s.setAttribute( 'aria-hidden', 'true' );
		s.innerHTML = cfg.icons[ name ] || ''; // eslint-disable-line no-unsanitized/property -- ikony z serwera (stałe)
		return s;
	}

	function pad( n ) {
		return ( n < 10 ? '0' : '' ) + n;
	}

	function ymd( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() );
	}

	function announce( text ) {
		if ( live ) {
			live.textContent = '';
			window.setTimeout( function () {
				live.textContent = text;
			}, 30 );
		}
	}

	function typeClass( e ) {
		return 'wesele' === e.type ? 'cfb-st-' + e.st : 'cfb-ty-' + e.type;
	}

	function pillClass( st ) {
		return { nowe: 'info', wstepnie: 'warn', umowa: 'info', zadatek: 'up' }[ st ] || 'flat';
	}

	/* ---------- Kalendarz ---------- */

	var cal = root.querySelector( '[data-cfb-cal]' );
	var view = { y: 0, m: 0 };

	function parseMonth( s ) {
		var p = String( s || '' ).split( '-' );
		return { y: parseInt( p[ 0 ], 10 ), m: parseInt( p[ 1 ], 10 ) - 1 };
	}

	/** Tekst na chipie: wesele to para, przypomnienie to samo zadanie ("Imiona: co zrobić" ma pełny tekst w karcie). */
	function who( e ) {
		var k = e.title.indexOf( ': ' );
		return 'wesele' !== e.type && k > 0 ? e.title.slice( k + 2 ) : e.title;
	}

	function chip( e, full ) {
		var b = el( 'button', 'cfb-ev ' + typeClass( e ) );
		b.type = 'button';
		b.setAttribute( 'data-ev', e.i );
		b.setAttribute( 'aria-haspopup', 'dialog' );
		b.setAttribute( 'aria-label', e.tl + ': ' + e.title + ', ' + e.long );
		b.appendChild( icon( { wesele: 'ring', kontakt: 'phone', oddanie: 'box', blokada: 'lock', przygotowanie: 'list', przypomnienie: 'bell' }[ e.type ] || 'bell' ) );
		b.appendChild( el( 'span', 'cfb-ev__t', full ? e.title : who( e ) ) );
		return b;
	}

	/** Telefon: lista dni miesiąca z wydarzeniami zamiast siatki 7 kolumn. */
	function renderAgenda( wrap ) {
		var ul = el( 'ol', 'cfb-agenda' );
		var days = new Date( view.y, view.m + 1, 0 ).getDate();
		for ( var d = 1; d <= days; d++ ) {
			var key = view.y + '-' + pad( view.m + 1 ) + '-' + pad( d );
			var items = byDate[ key ] || [];
			if ( ! items.length ) {
				continue;
			}
			var dt = new Date( view.y, view.m, d );
			var li = el( 'li', key === cfg.today ? 'is-today' : '' );
			var badge = el( 'span', 'cfb-agenda__d' );
			badge.appendChild( el( 'b', '', String( d ) ) );
			badge.appendChild( el( 'i', '', cfg.dows[ ( dt.getDay() + 6 ) % 7 ] ) );
			li.appendChild( badge );
			var box = el( 'div', 'cfb-agenda__evs' );
			items.forEach( function ( e ) {
				box.appendChild( chip( e, true ) );
			} );
			li.appendChild( box );
			ul.appendChild( li );
		}
		wrap.textContent = '';
		if ( ul.children.length ) {
			wrap.appendChild( ul );
		} else {
			wrap.appendChild( el( 'p', 'cfb-agenda__empty', T.noEvents || '' ) );
		}
	}

	function renderGrid() {
		var wrap = cal.querySelector( '[data-cfb-grid]' );
		var label = cal.querySelector( '[data-cfb-label]' );
		if ( mobileQuery.matches ) {
			renderAgenda( wrap );
			label.textContent = cfg.months[ view.m ] + ' ' + view.y;
			return;
		}
		var first = new Date( view.y, view.m, 1 );
		var offset = ( first.getDay() + 6 ) % 7;
		var max = 2;
		var table = el( 'table', 'cfb-grid' );
		var thead = el( 'thead' );
		var hr = el( 'tr' );
		cfg.dows.forEach( function ( d ) {
			var th = el( 'th', '', d );
			th.scope = 'col';
			hr.appendChild( th );
		} );
		thead.appendChild( hr );
		table.appendChild( thead );
		var body = el( 'tbody' );
		var row = null;
		for ( var i = 0; i < 42; i++ ) {
			var d = new Date( view.y, view.m, 1 - offset + i );
			var key = ymd( d );
			if ( 0 === i % 7 ) {
				row = el( 'tr' );
				body.appendChild( row );
			}
			var items = byDate[ key ] || [];
			var cls = 'cfb-day' + ( d.getMonth() !== view.m ? ' is-out' : '' ) + ( key === cfg.today ? ' is-today' : '' ) + ( d.getDay() % 6 === 0 ? ' is-weekend' : '' ) + ( items.length ? ' has-ev' : '' );
			var td = el( 'td', cls );
			td.setAttribute( 'data-date', key );
			td.setAttribute( 'data-n', items.length );
			if ( key === cfg.today ) {
				td.setAttribute( 'aria-current', 'date' );
			}
			td.appendChild( el( 'span', 'cfb-num', String( d.getDate() ) ) );
			var box = el( 'div', 'cfb-day__evs' );
			var shown = Math.min( items.length, max );
			items.slice( 0, shown ).forEach( function ( e ) {
				box.appendChild( chip( e ) );
			} );
			if ( items.length > shown ) {
				var more = el( 'button', 'cfb-more', ( T.more || '+%d' ).replace( '%d', items.length - shown ) );
				more.type = 'button';
				more.setAttribute( 'data-more', key );
				more.setAttribute( 'aria-expanded', 'false' );
				box.appendChild( more );
				var rest = el( 'div', 'cfb-day__rest' );
				rest.hidden = true;
				items.slice( shown ).forEach( function ( e ) {
					rest.appendChild( chip( e ) );
				} );
				box.appendChild( rest );
			}
			td.appendChild( box );
			row.appendChild( td );
		}
		table.appendChild( body );
		wrap.textContent = '';
		wrap.appendChild( table );
		label.textContent = cfg.months[ view.m ] + ' ' + view.y;
	}

	function setMonth( y, m, keepUrl ) {
		var d = new Date( y, m, 1 );
		view.y = d.getFullYear();
		view.m = d.getMonth();
		renderGrid();
		if ( ! keepUrl && window.history && history.replaceState ) {
			try {
				var u = new URL( window.location.href );
				var now = new Date();
				if ( view.y === now.getFullYear() && view.m === now.getMonth() ) {
					u.searchParams.delete( 'cfd_month' );
				} else {
					u.searchParams.set( 'cfd_month', view.y + '-' + pad( view.m + 1 ) );
				}
				history.replaceState( null, '', u.toString() );
			} catch ( err ) { /* adres bez zmian */ }
		}
		if ( state.i !== null ) {
			markActive();
		}
	}

	/* ---------- Wybór miesiąca i roku ---------- */

	var picker = cal ? cal.querySelector( '[data-cfb-picker]' ) : null;
	var pickBtn = cal ? cal.querySelector( '[data-cfb-pick]' ) : null;
	var pickYear = 0;

	function monthsWithEvents( y ) {
		var has = {};
		events.forEach( function ( e ) {
			if ( e.date.slice( 0, 4 ) === String( y ) ) {
				has[ parseInt( e.date.slice( 5, 7 ), 10 ) - 1 ] = true;
			}
		} );
		return has;
	}

	function renderPicker() {
		picker.textContent = '';
		var yr = el( 'div', 'cfb-pick__year' );
		var prev = el( 'button', 'cfb-nav__a' );
		prev.type = 'button';
		prev.setAttribute( 'aria-label', T.prevYear );
		prev.appendChild( icon( 'prev' ) );
		var input = el( 'input' );
		input.type = 'number';
		input.min = '1990';
		input.max = '2100';
		input.value = pickYear;
		input.setAttribute( 'aria-label', T.year );
		input.inputMode = 'numeric';
		var next = el( 'button', 'cfb-nav__a' );
		next.type = 'button';
		next.setAttribute( 'aria-label', T.nextYear );
		next.appendChild( icon( 'next' ) );
		yr.appendChild( prev );
		yr.appendChild( input );
		yr.appendChild( next );
		picker.appendChild( yr );
		var grid = el( 'div', 'cfb-pick__grid' );
		var has = monthsWithEvents( pickYear );
		cfg.short.forEach( function ( name, m ) {
			var b = el( 'button', 'cfb-pick__m' + ( has[ m ] ? ' has-ev' : '' ), name );
			b.type = 'button';
			b.setAttribute( 'data-m', m );
			b.setAttribute( 'aria-label', cfg.months[ m ] + ' ' + pickYear );
			if ( pickYear === view.y && m === view.m ) {
				b.setAttribute( 'aria-current', 'true' );
			}
			grid.appendChild( b );
		} );
		picker.appendChild( grid );
		var today = el( 'button', 'cfb-btn cfb-pick__today', T.today );
		today.type = 'button';
		picker.appendChild( today );

		function setYear( v ) {
			if ( v >= 1990 && v <= 2100 ) {
				pickYear = v;
				renderPicker();
				picker.querySelector( 'input' ).focus();
			}
		}
		prev.addEventListener( 'click', function () {
			setYear( pickYear - 1 );
		} );
		next.addEventListener( 'click', function () {
			setYear( pickYear + 1 );
		} );
		input.addEventListener( 'input', function () {
			var v = parseInt( input.value, 10 );
			if ( String( input.value ).length === 4 && v >= 1990 && v <= 2100 ) {
				pickYear = v;
				// tylko odśwież siatkę miesięcy, pole zostaje (wpisywanie nie traci fokusu)
				var fresh = monthsWithEvents( pickYear );
				picker.querySelectorAll( '.cfb-pick__m' ).forEach( function ( b ) {
					var m = parseInt( b.getAttribute( 'data-m' ), 10 );
					b.classList.toggle( 'has-ev', !! fresh[ m ] );
					b.setAttribute( 'aria-label', cfg.months[ m ] + ' ' + pickYear );
					if ( pickYear === view.y && m === view.m ) {
						b.setAttribute( 'aria-current', 'true' );
					} else {
						b.removeAttribute( 'aria-current' );
					}
				} );
			}
		} );
		input.addEventListener( 'keydown', function ( ev ) {
			if ( 'Enter' === ev.key ) {
				ev.preventDefault();
				var v = parseInt( input.value, 10 );
				if ( v >= 1990 && v <= 2100 ) {
					setMonth( v, view.m );
				}
				closePicker( true );
			}
		} );
		grid.addEventListener( 'click', function ( ev ) {
			var b = ev.target.closest( '.cfb-pick__m' );
			if ( b ) {
				setMonth( pickYear, parseInt( b.getAttribute( 'data-m' ), 10 ) );
				closePicker( true );
			}
		} );
		today.addEventListener( 'click', function () {
			goToday();
			closePicker( true );
		} );
	}

	function openPicker() {
		closeCard( true );
		pickYear = view.y;
		renderPicker();
		picker.hidden = false;
		pickBtn.setAttribute( 'aria-expanded', 'true' );
		var cur = picker.querySelector( '[aria-current="true"]' ) || picker.querySelector( 'input' );
		if ( cur ) {
			cur.focus();
		}
	}

	function closePicker( refocus ) {
		if ( ! picker || picker.hidden ) {
			return;
		}
		picker.hidden = true;
		pickBtn.setAttribute( 'aria-expanded', 'false' );
		if ( refocus ) {
			pickBtn.focus();
		}
	}

	function goToday() {
		var t = parseMonth( cfg.today.slice( 0, 7 ) );
		setMonth( t.y, t.m );
	}

	/* ---------- Karta szczegółów ---------- */

	var state = { i: null, pinned: false, anchor: null, openTimer: 0, closeTimer: 0, trigger: null, hideTimer: 0 };

	function clearTimers() {
		window.clearTimeout( state.openTimer );
		window.clearTimeout( state.closeTimer );
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.cssText = 'position:fixed;left:-9999px;top:0';
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( err ) { /* brak zgody */ }
			ta.remove();
			ok ? resolve() : reject( new Error( 'copy' ) );
		} );
	}

	function copyButton( e, extraClass ) {
		var b = el( 'button', 'cfb-btn ' + ( extraClass || '' ) );
		b.type = 'button';
		b.appendChild( icon( 'copy' ) );
		var lab = el( 'span', '', T.copy );
		b.appendChild( lab );
		b.addEventListener( 'click', function () {
			copyText( e.copy ).then( function () {
				lab.textContent = T.copied;
				b.classList.add( 'is-done' );
				announce( T.copied );
			}, function () {
				lab.textContent = T.copyFail;
			} );
			window.setTimeout( function () {
				lab.textContent = T.copy;
				b.classList.remove( 'is-done' );
			}, 1800 );
		} );
		return b;
	}

	// Przyciski "Skopiuj informacje" w kaflu najbliższego wesela (serwerowe, z indeksem wydarzenia).
	root.querySelectorAll( '[data-cfb-copy]' ).forEach( function ( b ) {
		var e = events[ parseInt( b.getAttribute( 'data-cfb-copy' ), 10 ) ];
		if ( ! e ) {
			return;
		}
		var lab = b.querySelector( 'span' );
		b.addEventListener( 'click', function () {
			copyText( e.copy ).then( function () {
				lab.textContent = T.copied;
				b.classList.add( 'is-done' );
				announce( T.copied );
			}, function () {
				lab.textContent = T.copyFail;
			} );
			window.setTimeout( function () {
				lab.textContent = T.copy;
				b.classList.remove( 'is-done' );
			}, 1800 );
		} );
	} );

	/** Klik w wartość (karta, kafel najbliższego) kopiuje ją; ikonka zmienia się na ptaszek na 1,2 s. */
	root.addEventListener( 'click', function ( ev ) {
		var vb = ev.target.closest ? ev.target.closest( '.cfb-val' ) : null;
		if ( ! vb ) {
			return;
		}
		ev.stopPropagation();
		var ic = vb.querySelector( '.cfb-val__i' );
		copyText( vb.getAttribute( 'data-cfb-val' ) || '' ).then( function () {
			vb.classList.add( 'is-done' );
			if ( ic ) {
				ic.innerHTML = cfg.icons.check; // eslint-disable-line no-unsanitized/property -- ikona z serwera
			}
			announce( T.copied );
		}, function () {
			announce( T.copyFail );
		} );
		window.setTimeout( function () {
			vb.classList.remove( 'is-done' );
			if ( ic ) {
				ic.innerHTML = cfg.icons.copy; // eslint-disable-line no-unsanitized/property -- ikona z serwera
			}
		}, 1200 );
	}, true );

	root.addEventListener( 'click', function ( ev ) {
		var b = ev.target.closest ? ev.target.closest( '[data-cfb-showall]' ) : null;
		if ( b ) {
			var open = 'true' !== b.getAttribute( 'aria-expanded' );
			b.closest( '.cfb-tile' ).querySelectorAll( '[data-extra]' ).forEach( function ( li ) {
				li.hidden = ! open;
			} );
			b.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			b.textContent = open ? b.getAttribute( 'data-less' ) : b.getAttribute( 'data-all' );
			return;
		}
		var m = ev.target.closest ? ev.target.closest( '[data-more]' ) : null;
		if ( m ) {
			var td = m.closest( '.cfb-day' );
			var wasOpen = td.classList.contains( 'is-expanded' );
			root.querySelectorAll( '.cfb-day.is-expanded' ).forEach( function ( d ) {
				d.classList.remove( 'is-expanded' );
				d.querySelector( '.cfb-day__rest' ).hidden = true;
			} );
			if ( ! wasOpen ) {
				td.classList.add( 'is-expanded' );
				td.querySelector( '.cfb-day__rest' ).hidden = false;
			}
			m.setAttribute( 'aria-expanded', wasOpen ? 'false' : 'true' );
		}
	} );

	function renderCard( e ) {
		card.textContent = '';
		var top = el( 'div', 'cfb-card__top' );
		var ti = el( 'span', 'cfb-tico is-lg cfb-tico--' + e.type );
		ti.appendChild( icon( { wesele: 'ring', kontakt: 'phone', oddanie: 'box', blokada: 'lock', przygotowanie: 'list', przypomnienie: 'bell' }[ e.type ] || 'bell' ) );
		top.appendChild( ti );
		top.appendChild( el( 'span', 'cfb-card__type', e.tl ) );
		if ( e.sl ) {
			top.appendChild( el( 'span', 'cfd-pill cfd-pill--' + pillClass( e.st ), e.sl ) );
		}
		var x = el( 'button', 'cfb-card__x' );
		x.type = 'button';
		x.setAttribute( 'aria-label', T.close );
		x.appendChild( icon( 'close' ) );
		x.addEventListener( 'click', function () {
			closeCard( true );
		} );
		top.appendChild( x );
		card.appendChild( top );
		var h = el( 'p', 'cfb-card__title', e.title );
		h.setAttribute( 'role', 'heading' );
		h.setAttribute( 'aria-level', '3' );
		card.appendChild( h );
		card.appendChild( el( 'p', 'cfb-card__when', e.long + ' · ' + e.rel ) );
		if ( e.rows && e.rows.length ) {
			var dl = el( 'dl', 'cfb-dl' );
			e.rows.forEach( function ( r ) {
				var row = el( 'div', 'cfb-kv' + ( r[ 1 ].length > 46 || r[ 1 ].indexOf( '\n' ) > -1 ? ' is-wide' : '' ) );
				row.appendChild( el( 'dt', '', r[ 0 ] ) );
				var dd = el( 'dd' );
				var vb = el( 'button', 'cfb-val' );
				vb.type = 'button';
				vb.setAttribute( 'data-cfb-val', r[ 1 ] );
				vb.setAttribute( 'aria-label', ( T.copyValue || '%s' ).replace( '%s', r[ 0 ] ) );
				vb.appendChild( el( 'span', '', r[ 1 ] ) );
				var vi = el( 'i', 'cfb-val__i' );
				vi.setAttribute( 'aria-hidden', 'true' );
				vi.innerHTML = cfg.icons.copy; // eslint-disable-line no-unsanitized/property -- ikona z serwera
				vb.appendChild( vi );
				dd.appendChild( vb );
				row.appendChild( dd );
				dl.appendChild( row );
			} );
			card.appendChild( dl );
		}
		var actions = el( 'div', 'cfb-actions' );
		actions.appendChild( copyButton( e, 'is-main' ) );
		if ( e.url ) {
			var open = el( 'a', 'cfb-btn' );
			open.href = e.url;
			open.appendChild( icon( 'ext' ) );
			open.appendChild( el( 'span', '', T.open ) );
			actions.appendChild( open );
		}
		if ( e.phone ) {
			var call = el( 'a', 'cfb-btn' );
			call.href = 'tel:' + e.phone.replace( /[^\d+]/g, '' );
			call.appendChild( icon( 'phone' ) );
			call.appendChild( el( 'span', '', T.call ) );
			actions.appendChild( call );
		}
		if ( e.route ) {
			var rt = el( 'a', 'cfb-btn' );
			rt.href = e.route;
			rt.target = '_blank';
			rt.rel = 'noopener noreferrer';
			rt.appendChild( icon( 'route' ) );
			rt.appendChild( el( 'span', '', T.route ) );
			actions.appendChild( rt );
		}
		card.appendChild( actions );
		var pos = navPos[ e.i ];
		if ( navList.length > 1 && undefined !== pos ) {
			var nav = el( 'div', 'cfb-card__nav' );
			var pb = el( 'button', 'cfb-btn' );
			pb.type = 'button';
			pb.appendChild( icon( 'prev' ) );
			pb.appendChild( el( 'span', '', T.prev ) );
			pb.disabled = 0 === pos;
			pb.addEventListener( 'click', function () {
				step( -1 );
			} );
			var nb = el( 'button', 'cfb-btn' );
			nb.type = 'button';
			nb.appendChild( el( 'span', '', T.next ) );
			nb.appendChild( icon( 'next' ) );
			nb.disabled = pos === navList.length - 1;
			nb.addEventListener( 'click', function () {
				step( 1 );
			} );
			nav.appendChild( pb );
			nav.appendChild( el( 'span', 'cfb-card__pos', ( pos + 1 ) + ' / ' + navList.length ) );
			nav.appendChild( nb );
			card.appendChild( nav );
		}
	}

	function place() {
		if ( mobileQuery.matches ) {
			card.style.left = '';
			card.style.top = '';
			return;
		}
		var a = state.anchor && document.body.contains( state.anchor ) ? state.anchor.getBoundingClientRect() : null;
		var vw = document.documentElement.clientWidth;
		var vh = window.innerHeight;
		var cw = card.offsetWidth;
		var ch = card.offsetHeight;
		var x;
		var y;
		if ( a ) {
			x = a.right + 10;
			if ( x + cw > vw - 12 ) {
				x = a.left - cw - 10;
			}
			if ( x < 12 ) {
				x = Math.max( 12, Math.min( a.left, vw - cw - 12 ) );
				y = a.bottom + 10;
				if ( y + ch > vh - 12 ) {
					y = a.top - ch - 10;
				}
			} else {
				y = a.top - 12;
			}
		} else {
			x = Math.max( 12, ( vw - cw ) / 2 );
			y = Math.max( 12, ( vh - ch ) / 3 );
		}
		y = Math.max( 12, Math.min( y, vh - ch - 12 ) );
		card.style.left = Math.round( x ) + 'px';
		card.style.top = Math.round( y ) + 'px';
	}

	function markActive() {
		root.querySelectorAll( '.cfb-ev.is-active' ).forEach( function ( n ) {
			n.classList.remove( 'is-active' );
		} );
		if ( state.i !== null ) {
			var n = root.querySelector( '.cfb-ev[data-ev="' + state.i + '"]' );
			if ( n ) {
				n.classList.add( 'is-active' );
			}
		}
	}

	function show( i, anchor, pinned ) {
		var e = events[ i ];
		if ( ! e ) {
			return;
		}
		window.clearTimeout( state.hideTimer );
		clearTimers();
		state.i = i;
		state.anchor = anchor || state.anchor;
		state.pinned = !! pinned;
		renderCard( e );
		card.hidden = false;
		place();
		// następna klatka: przejście wejścia
		window.requestAnimationFrame( function () {
			card.classList.add( 'is-open' );
		} );
		markActive();
	}

	function closeCard( refocus ) {
		clearTimers();
		if ( state.i === null ) {
			return;
		}
		var trg = state.trigger;
		state.i = null;
		state.pinned = false;
		card.classList.remove( 'is-open' );
		markActive();
		window.clearTimeout( state.hideTimer );
		state.hideTimer = window.setTimeout( function () {
			if ( state.i === null ) {
				card.hidden = true;
			}
		}, 220 );
		if ( refocus && trg && document.body.contains( trg ) ) {
			state.refocus = true;
			trg.focus();
			window.setTimeout( function () {
				state.refocus = false;
			}, 50 );
		}
	}

	function scheduleClose() {
		window.clearTimeout( state.closeTimer );
		if ( state.pinned || state.i === null ) {
			return;
		}
		state.closeTimer = window.setTimeout( function () {
			closeCard( false );
		}, CLOSE_DELAY );
	}

	function findAnchor( i ) {
		var e = events[ i ];
		var n = cal ? cal.querySelector( '[data-ev="' + i + '"]' ) : null;
		return n || ( cal && e ? cal.querySelector( '[data-date="' + e.date + '"]' ) : null );
	}

	/** Poprzednie / następne wydarzenie w czasie; kalendarz przewija się do jego miesiąca. */
	function step( dir ) {
		var pos = navPos[ state.i ];
		if ( undefined === pos ) {
			return;
		}
		var t = navList[ pos + dir ];
		if ( ! t ) {
			return;
		}
		if ( cal ) {
			var m = parseMonth( t.date.slice( 0, 7 ) );
			if ( m.y !== view.y || m.m !== view.m ) {
				setMonth( m.y, m.m );
			}
		}
		var anchor = findAnchor( t.i );
		show( t.i, anchor, true );
		announce( t.tl + ': ' + t.title + ', ' + t.long );
		var go = card.querySelector( '.cfb-card__nav .cfb-btn:not([disabled])' );
		var wanted = card.querySelectorAll( '.cfb-card__nav .cfb-btn' )[ dir > 0 ? 1 : 0 ];
		( wanted && ! wanted.disabled ? wanted : go || card ).focus( { preventScroll: true } );
	}

	function triggerOf( target ) {
		return target && target.closest ? target.closest( '[data-ev]' ) : null;
	}

	root.addEventListener( 'mouseover', function ( ev ) {
		var t = triggerOf( ev.target );
		if ( ! t || t.contains( ev.relatedTarget ) ) {
			return;
		}
		window.clearTimeout( state.closeTimer );
		if ( state.pinned ) {
			return;
		}
		var i = parseInt( t.getAttribute( 'data-ev' ), 10 );
		window.clearTimeout( state.openTimer );
		if ( state.i === i ) {
			return;
		}
		state.openTimer = window.setTimeout( function () {
			state.trigger = t;
			show( i, t, false );
		}, state.i === null ? OPEN_DELAY : 0 );
	} );

	root.addEventListener( 'mouseout', function ( ev ) {
		var t = triggerOf( ev.target );
		if ( ! t || t.contains( ev.relatedTarget ) ) {
			return;
		}
		window.clearTimeout( state.openTimer );
		if ( ev.relatedTarget && card.contains( ev.relatedTarget ) ) {
			return;
		}
		scheduleClose();
	} );

	card.addEventListener( 'mouseenter', function () {
		window.clearTimeout( state.closeTimer );
	} );
	card.addEventListener( 'mouseleave', function () {
		var over = document.activeElement && card.contains( document.activeElement ) && state.pinned;
		if ( ! over ) {
			scheduleClose();
		}
	} );

	root.addEventListener( 'focusin', function ( ev ) {
		var t = triggerOf( ev.target );
		if ( ! t || state.pinned || state.refocus ) {
			return;
		}
		// fokus z klawiatury pokazuje kartę; fokus po kliknięciu myszą przejmuje obsługa kliknięcia
		if ( ! t.matches( ':focus-visible' ) ) {
			return;
		}
		var i = parseInt( t.getAttribute( 'data-ev' ), 10 );
		state.trigger = t;
		show( i, t, false );
	} );
	root.addEventListener( 'focusout', function ( ev ) {
		var t = triggerOf( ev.target );
		if ( t && ! ( ev.relatedTarget && card.contains( ev.relatedTarget ) ) ) {
			scheduleClose();
		}
	} );

	card.addEventListener( 'focusin', function () {
		window.clearTimeout( state.closeTimer );
	} );

	root.addEventListener( 'click', function ( ev ) {
		var t = triggerOf( ev.target );
		if ( t ) {
			ev.preventDefault();
			var i = parseInt( t.getAttribute( 'data-ev' ), 10 );
			state.trigger = t;
			show( i, t, true );
			if ( 0 === ev.detail ) { // Enter / Spacja: fokus do karty, żeby dało się dojść do przycisków
				var f = card.querySelector( '.cfb-btn, a' );
				( f || card ).focus( { preventScroll: true } );
			} else {
				card.focus( { preventScroll: true } );
			}
			return;
		}
		// kliknięcie w dzień z wydarzeniami (poza chipem) otwiera pierwsze wydarzenie dnia
		var td = ev.target.closest ? ev.target.closest( '.cfb-day.has-ev' ) : null;
		if ( td && cal && cal.contains( td ) && ! ev.target.closest( '[data-more]' ) ) {
			var list = byDate[ td.getAttribute( 'data-date' ) ] || [];
			if ( list.length ) {
				state.trigger = null;
				show( list[ 0 ].i, td, true );
				card.focus( { preventScroll: true } );
			}
		}
	} );

	document.addEventListener( 'click', function ( ev ) {
		if ( ! document.body.contains( ev.target ) ) {
			return;
		}
		if ( state.i !== null && state.pinned && ! card.contains( ev.target ) && ! triggerOf( ev.target ) && ! ( ev.target.closest && ev.target.closest( '.cfb-day.has-ev' ) ) ) {
			closeCard( false );
		}
		if ( picker && ! picker.hidden && ! picker.contains( ev.target ) && ! pickBtn.contains( ev.target ) ) {
			closePicker( false );
		}
		if ( ! ( ev.target.closest && ev.target.closest( '.cfb-day.is-expanded' ) ) ) {
			root.querySelectorAll( '.cfb-day.is-expanded' ).forEach( function ( d ) {
				d.classList.remove( 'is-expanded' );
				d.querySelector( '.cfb-day__rest' ).hidden = true;
				d.querySelector( '[data-more]' ).setAttribute( 'aria-expanded', 'false' );
			} );
		}
	} );

	document.addEventListener( 'keydown', function ( ev ) {
		if ( 'Escape' === ev.key ) {
			if ( picker && ! picker.hidden ) {
				closePicker( true );
			} else if ( state.i !== null ) {
				closeCard( true );
			}
			return;
		}
		if ( ! cal || ev.ctrlKey || ev.altKey || ev.metaKey || ( 'ArrowLeft' !== ev.key && 'ArrowRight' !== ev.key ) ) {
			return;
		}
		var tg = ev.target;
		if ( tg && tg.closest && tg.closest( 'input, textarea, select, [contenteditable], #adminmenu, #wpadminbar, .cfb-pick, .cfb-card' ) ) {
			return;
		}
		ev.preventDefault();
		var dir = 'ArrowRight' === ev.key ? 1 : -1;
		if ( ev.shiftKey ) {
			setMonth( view.y + dir, view.m );
		} else {
			setMonth( view.y, view.m + dir );
		}
	} );

	window.addEventListener( 'scroll', function () {
		if ( state.i === null ) {
			return;
		}
		if ( state.pinned ) {
			place();
		} else {
			closeCard( false );
		}
	}, { passive: true } );
	window.addEventListener( 'resize', function () {
		if ( state.i !== null ) {
			place();
		}
	} );

	/* ---------- Start ---------- */

	if ( cal ) {
		var start = parseMonth( cfg.month );
		view.y = start.y;
		view.m = start.m;
		renderGrid();
		cal.addEventListener( 'click', function ( ev ) {
			var nav = ev.target.closest( '[data-cfb-nav]' );
			if ( nav ) {
				closeCard( false );
				setMonth( view.y, view.m + parseInt( nav.getAttribute( 'data-cfb-nav' ), 10 ) );
			} else if ( ev.target.closest( '[data-cfb-today]' ) ) {
				closeCard( false );
				goToday();
			} else if ( ev.target.closest( '[data-cfb-pick]' ) ) {
				if ( picker.hidden ) {
					openPicker();
				} else {
					closePicker( false );
				}
			}
		} );
		if ( mobileQuery.addEventListener ) {
			mobileQuery.addEventListener( 'change', renderGrid );
		}
	}
}() );
