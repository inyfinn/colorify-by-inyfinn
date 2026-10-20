/**
 * Colorify by INYFINN: interfejs wtyczki w panelu.
 *
 *   1. Górny pasek: segment trybu i menu Colorify (zwykłe pozycje paska administratora).
 *   2. Wybór schematu (profil, Ustawienia → Colorify): siatka, własna paleta, dostrojenie, podgląd na żywo.
 *   3. Ustawienia → Colorify: zakres (pokazywanie sekcji "Twoje kolory").
 *
 * Kolory liczy serwer: skrypt tylko prosi o tokeny (colorify_preview_tokens) i oddaje je silnikowi
 * (window.colorify.applyPayloads). Bez skryptu działają odnośniki GET z nonce w pasku i klasyczny zapis formularzy.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.colorifyUi;
	if ( ! cfg ) {
		return;
	}

	/* ---------- Narzędzia ---------- */

	function $( sel, root ) {
		return ( root || d ).querySelector( sel );
	}

	function $$( sel, root ) {
		return Array.prototype.slice.call( ( root || d ).querySelectorAll( sel ) );
	}

	function speak( text ) {
		if ( w.wp && w.wp.a11y && w.wp.a11y.speak ) {
			w.wp.a11y.speak( text, 'assertive' );
		}
	}

	function post( action, fields ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.set( key, fields[ key ] );
		} );
		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body,
		} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( 'colorify: request failed' );
				}
				return j.data;
			} );
	}

	/** Komunikat błędu jak natywny .notice pod nagłówkiem ekranu (tylko gdy strona ma .wrap). */
	function showNotice( text ) {
		var wrap = $( '#wpbody-content .wrap' ) || $( '.wrap' );
		if ( ! wrap ) {
			return;
		}
		var old = $( '.colorify-js-notice', wrap );
		if ( old ) {
			old.remove();
		}
		var box = d.createElement( 'div' );
		box.className = 'notice notice-error is-dismissible colorify-js-notice';
		var p = d.createElement( 'p' );
		p.textContent = text;
		var close = d.createElement( 'button' );
		close.type = 'button';
		close.className = 'notice-dismiss';
		close.setAttribute( 'aria-label', cfg.i18n.dismiss || '' );
		close.addEventListener( 'click', function () {
			box.remove();
		} );
		box.appendChild( p );
		box.appendChild( close );
		var anchor = $( 'hr.wp-header-end', wrap ) || $( 'h1', wrap );
		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( box, anchor.nextSibling );
		} else {
			wrap.insertBefore( box, wrap.firstChild );
		}
	}

	function fail( text ) {
		speak( text );
		showNotice( text );
	}

	function busy( el, on ) {
		if ( ! el ) {
			return;
		}
		if ( on ) {
			el.setAttribute( 'aria-busy', 'true' );
		} else {
			el.removeAttribute( 'aria-busy' );
		}
	}

	var app = { mode: cfg.mode === 'light' ? 'light' : 'dark', picker: null };

	/* ---------- Klawiatura grup radio i zakładek (jedno miejsce w kolejności Tab) ---------- */

	var ROLE_ITEMS = '[role="radio"]:not(input),[role="tab"]';

	d.addEventListener( 'keydown', function ( e ) {
		var el = e.target;
		if ( ! el || ! el.matches || ! el.matches( ROLE_ITEMS ) ) {
			return;
		}
		var group = el.closest( '[role="radiogroup"],[role="tablist"]' );
		if ( ! group ) {
			return;
		}
		if ( ( e.key === ' ' || e.key === 'Spacebar' ) && el.tagName === 'A' ) {
			e.preventDefault();
			el.click();
			return;
		}
		var items = $$( ROLE_ITEMS, group ).filter( function ( x ) {
			return x.offsetParent !== null;
		} );
		var i = items.indexOf( el );
		if ( i < 0 ) {
			return;
		}
		var n = null;
		if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
			n = ( i + 1 ) % items.length;
		} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
			n = ( i - 1 + items.length ) % items.length;
		} else if ( e.key === 'Home' ) {
			n = 0;
		} else if ( e.key === 'End' ) {
			n = items.length - 1;
		}
		if ( n === null ) {
			return;
		}
		e.preventDefault();
		items[ n ].focus();
		items[ n ].click();
	} );

	/* ---------- Tryb: pasek ---------- */

	/** Ustawia zaznaczenie wszystkich grup trybu w pasku (segment i para w menu). */
	function syncBarMode( mode ) {
		app.mode = mode;
		$$( '#wpadminbar [data-colorify-mode]' ).forEach( function ( el ) {
			var on = el.getAttribute( 'data-colorify-mode' ) === mode;
			el.setAttribute( 'aria-checked', on ? 'true' : 'false' );
			el.tabIndex = on ? 0 : -1;
		} );
	}

	function engineSetMode( mode ) {
		if ( cfg.active && w.colorify ) {
			try {
				return w.colorify.setMode( mode );
			} catch ( e ) {
				return Promise.reject( e );
			}
		}
		return Promise.resolve();
	}

	function closeBarMenu() {
		var li = $( '#wp-admin-bar-colorify' );
		if ( li ) {
			li.classList.remove( 'hover' );
		}
	}

	function onBarMode( el ) {
		var mode = el.getAttribute( 'data-colorify-mode' );
		if ( el.getAttribute( 'aria-checked' ) === 'true' ) {
			closeBarMenu();
			return;
		}
		var prev = app.mode;
		busy( el, true );
		syncBarMode( mode );
		engineSetMode( mode ).catch( function () {} );
		d.dispatchEvent( new CustomEvent( 'colorify-ui:mode', { detail: { mode: mode } } ) );
		post( 'colorify_save_admin_appearance', { mode: mode } )
			.then( function () {
				busy( el, false );
				closeBarMenu();
			} )
			.catch( function () {
				busy( el, false );
				syncBarMode( prev );
				engineSetMode( prev ).catch( function () {} );
				d.dispatchEvent( new CustomEvent( 'colorify-ui:mode', { detail: { mode: prev } } ) );
				fail( cfg.i18n.modeError );
			} );
	}

	function onBarToggle( el ) {
		var enabled = el.getAttribute( 'data-colorify-toggle' );
		busy( el, true );
		post( 'colorify_toggle_user_theme', { enabled: enabled } )
			.then( function () {
				// Arkusze ramy i schematu ładuje serwer, więc włączenie i wyłączenie stylu przeładowuje stronę.
				w.location.reload();
			} )
			.catch( function () {
				busy( el, false );
				fail( cfg.i18n.styleError );
			} );
	}

	d.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( ! t || ! t.closest ) {
			return;
		}
		var mode = t.closest( '#wpadminbar [data-colorify-mode]' );
		if ( mode ) {
			e.preventDefault();
			onBarMode( mode );
			return;
		}
		var toggle = t.closest( '[data-colorify-toggle]' );
		if ( toggle ) {
			e.preventDefault();
			onBarToggle( toggle );
		}
	} );

	/** Menu Colorify w pasku: mechanizm rdzenia (klasa hover), skrypt dokłada kliknięcie, Spację i zamykanie. */
	function initBarMenu() {
		var li = $( '#wp-admin-bar-colorify' );
		if ( ! li ) {
			return;
		}
		var top = $( ':scope > .ab-item', li );
		var wrap = $( ':scope > .ab-sub-wrapper', li );
		if ( ! top ) {
			return;
		}
		if ( wrap ) {
			wrap.id = wrap.id || 'colorify-ab-sub';
			top.setAttribute( 'aria-controls', wrap.id );
		}
		top.setAttribute( 'role', 'button' );
		top.setAttribute( 'aria-haspopup', 'true' );
		top.setAttribute( 'aria-expanded', li.classList.contains( 'hover' ) ? 'true' : 'false' );

		new MutationObserver( function () {
			top.setAttribute( 'aria-expanded', li.classList.contains( 'hover' ) ? 'true' : 'false' );
		} ).observe( li, { attributes: true, attributeFilter: [ 'class' ] } );

		top.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			// Ekrany dotykowe obsługuje rdzeń (przełącza klasę hover). Mysz: kliknięcie otwiera, gdy menu jeszcze nie ruszyło.
			if ( ! ( 'ontouchstart' in w ) ) {
				li.classList.add( 'hover' );
			}
		} );

		top.addEventListener( 'keydown', function ( e ) {
			if ( e.key === ' ' || e.key === 'Spacebar' || e.key === 'ArrowDown' ) {
				e.preventDefault();
				li.classList.add( 'hover' );
				if ( e.key === 'ArrowDown' ) {
					var first = $( '.ab-submenu a', li );
					if ( first ) {
						first.focus();
					}
				}
			}
		} );

		li.addEventListener( 'focusout', function ( e ) {
			if ( e.relatedTarget && ! li.contains( e.relatedTarget ) ) {
				li.classList.remove( 'hover' );
			}
		} );

		d.addEventListener( 'click', function ( e ) {
			if ( ! li.contains( e.target ) ) {
				li.classList.remove( 'hover' );
			}
		} );
	}

	/* ---------- Pomocnicze: tokeny z odpowiedzi serwera ---------- */

	var HEX = /^#[0-9a-fA-F]{6}$/;

	function cssVar( css, name ) {
		var m = new RegExp( name + ':\\s*(#[0-9a-fA-F]{6})' ).exec( css || '' );
		return m ? m[ 1 ] : null;
	}

	/** Zmienne mini-ramki z gotowego arkusza tokenów trybu (to samo, co tablica preview silnika). */
	function setPreviewVars( el, payloads ) {
		if ( ! el || ! payloads ) {
			return;
		}
		[ [ 'dark', 'd' ], [ 'light', 'l' ] ].forEach( function ( pair ) {
			var p = payloads[ pair[ 0 ] ];
			if ( ! p || ! p.css ) {
				return;
			}
			var map = {
				menu: '--colorify-menu-background',
				canvas: '--colorify-ui-bg',
				fill: '--colorify-menu-highlight-background',
				theme: '--wp-admin-theme-color',
				ink: '--colorify-ui-text',
			};
			Object.keys( map ).forEach( function ( key ) {
				var v = cssVar( p.css, map[ key ] );
				if ( v ) {
					el.style.setProperty( '--p' + pair[ 1 ] + '-' + key, v );
				}
			} );
		} );
	}

	function fmt( v ) {
		return ( v > 0 ? '+' : '' ) + v;
	}

	function debounce( fn, ms ) {
		var timer = null;
		return function () {
			var args = arguments;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( null, args );
			}, ms );
		};
	}

	/* ---------- Wybór schematu ---------- */

	var TUNE_KEYS = [ 'bg_brightness', 'bg_saturation', 'accent_brightness', 'accent_saturation' ];
	var PALETTE_KEYS = [ 'bg', 'bg2', 'accent', 'accent2' ];

	function initPicker() {
		var root = $( '#colorify.colorify-picker' );
		var dataEl = $( '#colorify-picker-data' );
		if ( ! root || ! dataEl ) {
			return;
		}
		var data = {};
		try {
			data = JSON.parse( dataEl.textContent );
		} catch ( e ) {
			return;
		}

		var form = root.closest( 'form' );
		var settings = data.context === 'settings';
		var tabs = $( '.colorify-tabs', root );
		var grid = $( '#colorify-grid', root );
		var palette = $( '#colorify-palette', root );
		var modeField = $( '#colorify-field-mode', root );
		var saveBtn = $( '#colorify-save' );
		var statusEl = $( '#colorify-status' );
		var dirty = false;
		var seq = 0;
		var statusTimer = null;

		var P = { scheme: '', mode: 'dark', custom: {}, tuning: { dark: {}, light: {} }, tuneMode: 'dark', scope: data.scope || 'user' };
		var last = null;
		var lastPayloads = null;

		/* --- stan z DOM --- */

		function readState() {
			var s = { scheme: '', mode: modeField ? modeField.value : 'dark', custom: {}, tuning: { dark: {}, light: {} } };
			var checked = $( 'input[name="admin_color"]:checked', root );
			// Schemat bez karty w siatce (nieznany klucz): zostaje wartość z serwera, nigdy pusty ciąg.
			s.scheme = checked ? checked.value : ( data.scheme || '' );
			PALETTE_KEYS.forEach( function ( k ) {
				var hex = $( '#colorify-hex-' + k, root );
				s.custom[ k ] = hex ? hex.value.toLowerCase() : '';
			} );
			[ 'dark', 'light' ].forEach( function ( m ) {
				TUNE_KEYS.forEach( function ( k ) {
					var input = $( 'input[type="hidden"][data-tune-mode="' + m + '"][data-tune-key="' + k + '"]', root );
					s.tuning[ m ][ k ] = input ? parseInt( input.value, 10 ) || 0 : 0;
				} );
			} );
			return s;
		}

		function clone( s ) {
			return JSON.parse( JSON.stringify( s ) );
		}

		function loadState( s ) {
			P.scheme = s.scheme;
			P.mode = s.mode === 'light' ? 'light' : 'dark';
			P.custom = clone( s.custom );
			P.tuning = clone( s.tuning );
		}

		function groupOf( scheme ) {
			var radio = $( '.colorify-scheme[data-group] input[value="' + scheme + '"]', root );
			if ( scheme === data.customKey ) {
				return 'custom';
			}
			return radio ? radio.closest( '.colorify-scheme' ).getAttribute( 'data-group' ) : 'green';
		}

		function schemeName( scheme ) {
			if ( scheme === data.customKey ) {
				return cfg.i18n.customName;
			}
			var radio = $( '.colorify-scheme[data-group] input[value="' + scheme + '"]', root );
			var span = radio ? $( '.colorify-scheme__name span', radio.closest( '.colorify-scheme' ) ) : null;
			return span ? span.textContent : scheme;
		}

		function tuneChanged() {
			return [ 'dark', 'light' ].some( function ( m ) {
				return TUNE_KEYS.some( function ( k ) {
					return P.tuning[ m ][ k ] !== 0;
				} );
			} );
		}

		/* --- odświeżanie widoku --- */

		function renderMeta() {
			var name = $( '#colorify-current-name', root );
			var meta = $( '#colorify-current-meta', root );
			if ( name ) {
				name.textContent = schemeName( P.scheme );
			}
			if ( meta ) {
				meta.textContent = ( P.mode === 'dark' ? cfg.i18n.modeDark : cfg.i18n.modeLight ) + '. ' + ( tuneChanged() ? cfg.i18n.tuneChanged : cfg.i18n.tuneSame );
			}
			var st = $( '#colorify-tuning-state', root );
			if ( st ) {
				st.textContent = tuneChanged() ? cfg.i18n.tuneStateOn : cfg.i18n.tuneStateOff;
				st.classList.toggle( 'is-changed', tuneChanged() );
			}
		}

		function renderDots() {
			var dots = $$( '#colorify-tab-custom .colorify-tab__dots i', root );
			if ( dots[ 0 ] && HEX.test( P.custom.accent ) ) {
				dots[ 0 ].style.background = P.custom.accent;
			}
			if ( dots[ 1 ] && HEX.test( P.custom.accent2 ) ) {
				dots[ 1 ].style.background = P.custom.accent2;
			}
		}

		function setView( mode ) {
			P.mode = mode;
			root.setAttribute( 'data-view', mode );
			if ( modeField ) {
				modeField.value = mode;
			}
			$$( '[data-pick-mode]', root ).forEach( function ( b ) {
				var on = b.getAttribute( 'data-pick-mode' ) === mode;
				b.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				b.tabIndex = on ? 0 : -1;
			} );
			// Dostrojenie zawsze dotyczy trybu, który widać: jeden przełącznik zamiast dwóch.
			P.tuneMode = mode;
			if ( typeof renderSliders === 'function' ) {
				renderSliders();
			}
			var cap = $( '#colorify-custom-caption', root );
			if ( cap ) {
				cap.textContent = mode === 'dark' ? cfg.i18n.captionDark : cfg.i18n.captionLight;
			}
			renderMeta();
		}

		function renderSliders() {
			var m = P.tuneMode;
			TUNE_KEYS.forEach( function ( k ) {
				var row = $( '.colorify-slider[data-id="' + k + '"]', root );
				var input = $( '#colorify-t-' + k, root );
				var out = $( '#colorify-o-' + k, root );
				var v = P.tuning[ m ][ k ];
				if ( input ) {
					input.value = v;
				}
				if ( out ) {
					out.textContent = fmt( v );
				}
				if ( row ) {
					row.classList.toggle( 'is-strong', Math.abs( v ) > ( data.tuningWarn || 50 ) );
				}
			} );
			$$( '[data-tune-mode]', $( '#colorify-tune-mode', root ) ).forEach( function ( b ) {
				var on = b.getAttribute( 'data-tune-mode' ) === m;
				b.setAttribute( 'aria-checked', on ? 'true' : 'false' );
				b.tabIndex = on ? 0 : -1;
			} );
		}

		function writeTuningFields() {
			[ 'dark', 'light' ].forEach( function ( m ) {
				TUNE_KEYS.forEach( function ( k ) {
					var input = $( 'input[type="hidden"][data-tune-mode="' + m + '"][data-tune-key="' + k + '"]', root );
					if ( input ) {
						input.value = P.tuning[ m ][ k ];
					}
				} );
			} );
		}

		function renderPalette() {
			PALETTE_KEYS.forEach( function ( k ) {
				var hex = $( '#colorify-hex-' + k, root );
				var sw = $( '#colorify-sw-' + k, root );
				if ( hex ) {
					hex.value = String( P.custom[ k ] ).toUpperCase();
					clearInvalid( hex );
				}
				if ( sw && HEX.test( P.custom[ k ] ) ) {
					sw.value = P.custom[ k ];
				}
			} );
			renderDots();
		}

		function showGroup( group ) {
			$$( '.colorify-tab', tabs ).forEach( function ( t ) {
				var on = t.getAttribute( 'data-group' ) === group;
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				t.tabIndex = on ? 0 : -1;
			} );
			var isCustom = group === 'custom';
			grid.hidden = isCustom;
			palette.hidden = ! isCustom;
			$$( '.colorify-scheme[data-group]', grid ).forEach( function ( card ) {
				card.hidden = card.getAttribute( 'data-group' ) !== group;
			} );
		}

		function checkScheme( scheme ) {
			$$( 'input[name="admin_color"]', root ).forEach( function ( r ) {
				r.checked = r.value === scheme;
			} );
		}

		/** Całość stanu do kontrolek (przełączanie zakresu, wycofanie po błędzie). */
		function renderAll() {
			checkScheme( P.scheme );
			renderPalette();
			writeTuningFields();
			renderSliders();
			setView( P.mode );
			showGroup( groupOf( P.scheme ) );
		}

		/* --- czy panel idzie za wyborem --- */

		function scopeValue() {
			var r = $( 'input[name="colorify_settings_scope"]:checked' );
			return r ? r.value : P.scope;
		}

		function follows() {
			if ( ! cfg.active || ! w.colorify ) {
				return false;
			}
			// Zapis wyglądu witryny przy zakresie "jeden wygląd" zdejmuje własny wygląd administratora,
			// więc podgląd od razu idzie na panel.
			if ( settings ) {
				return scopeValue() === 'global' || ! data.hasPersonal;
			}
			return !! data.own;
		}

		function restorePanel() {
			if ( cfg.active && w.colorify && w.colorifyEngine && w.colorifyEngine.payloads ) {
				w.colorify.applyPayloads( w.colorifyEngine.payloads, cfg.mode ).then( function () {
					syncBarMode( cfg.mode );
				} ).catch( function () {} );
			}
		}

		/* --- status zapisu --- */

		function setStatus( state ) {
			if ( ! statusEl ) {
				return;
			}
			clearTimeout( statusTimer );
			statusEl.setAttribute( 'data-state', state );
			statusEl.textContent = '';
			if ( state === 'saving' ) {
				var spin = d.createElement( 'span' );
				spin.className = 'colorify-spin';
				spin.setAttribute( 'aria-hidden', 'true' );
				statusEl.appendChild( spin );
				statusEl.appendChild( d.createTextNode( cfg.i18n.saving ) );
			} else if ( state === 'saved' ) {
				statusEl.insertAdjacentHTML( 'beforeend', '<svg class="colorify-i" aria-hidden="true" focusable="false"><use href="#colorify-i-check"/></svg>' );
				statusEl.appendChild( d.createTextNode( cfg.i18n.saved ) );
				statusTimer = setTimeout( function () {
					setStatus( 'idle' );
				}, 3000 );
			} else if ( state === 'error' ) {
				statusEl.insertAdjacentHTML( 'beforeend', '<svg class="colorify-i" aria-hidden="true" focusable="false"><use href="#colorify-i-warn"/></svg>' );
				statusEl.appendChild( d.createTextNode( cfg.i18n.saveError ) );
			}
		}

		function setStatusText( text ) {
			if ( ! statusEl ) {
				fail( text );
				return;
			}
			setStatus( 'error' );
			statusEl.lastChild.textContent = text;
			speak( text );
		}

		/* --- podgląd na żywo --- */

		function previewFields() {
			var f = { scheme: P.scheme };
			if ( P.scheme === data.customKey ) {
				PALETTE_KEYS.forEach( function ( k ) {
					f[ 'custom[' + k + ']' ] = P.custom[ k ];
				} );
			}
			[ 'dark', 'light' ].forEach( function ( m ) {
				TUNE_KEYS.forEach( function ( k ) {
					f[ 'tuning[' + m + '][' + k + ']' ] = P.tuning[ m ][ k ];
				} );
			} );
			return f;
		}

		function requestPreview() {
			var mine = ++seq;
			var sent = readSnapshot();
			post( 'colorify_preview_tokens', previewFields() )
				.then( function ( payloads ) {
					if ( mine !== seq ) {
						return;
					}
					lastPayloads = payloads;
					last = sent;
					setPreviewVars( $( '#colorify-current-preview', root ), payloads );
					if ( P.scheme === data.customKey ) {
						setPreviewVars( $( '#colorify-custom-preview', root ), payloads );
					}
					if ( follows() ) {
						w.colorify.applyPayloads( payloads, P.mode ).then( function () {
							syncBarMode( P.mode );
						} ).catch( function () {} );
					}
				} )
				.catch( function () {
					if ( mine !== seq ) {
						return;
					}
					// Ostatni dobry stan: kontrolki wracają, panel zostaje w poprzednim wyglądzie.
					if ( last ) {
						loadState( last );
						renderAll();
					}
					setStatusText( cfg.i18n.styleError );
				} );
		}

		var schedulePreview = debounce( requestPreview, 120 );

		function readSnapshot() {
			return clone( { scheme: P.scheme, mode: P.mode, custom: P.custom, tuning: P.tuning } );
		}

		function touch() {
			dirty = true;
			scheduleAutosave();
		}

		/* --- zapis automatyczny: każda zmiana zapisuje się sama, bez przycisku --- */

		function hexAllValid() {
			return $$( '.colorify-hex', root ).every( function ( input ) {
				var v = input.value;
				return HEX.test( v && v.charAt( 0 ) !== '#' ? '#' + v : v );
			} );
		}

		function autosave() {
			if ( ! dirty || ! hexAllValid() ) {
				return;
			}
			if ( ! settings ) {
				saveAjax();
				return;
			}
			// Ustawienia → Colorify: ten sam POST co przycisk, tylko w tle (handle_save odsyła przekierowanie, fetch je śledzi).
			writeTuningFields();
			if ( modeField ) {
				modeField.value = P.mode;
			}
			var mine = ++saveSeq;
			setStatus( 'saving' );
			fetch( form.getAttribute( 'action' ) || w.location.href, {
				method: 'POST',
				credentials: 'same-origin',
				body: new FormData( form ),
			} )
				.then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( 'colorify: autosave failed' );
					}
					if ( mine === saveSeq ) {
						dirty = false;
						last = readSnapshot();
						if ( scopeValue() === 'global' ) {
							data.hasPersonal = false;
						}
						setStatus( 'saved' );
					}
				} )
				.catch( function () {
					if ( mine === saveSeq ) {
						setStatus( 'error' );
						speak( cfg.i18n.saveError );
					}
				} );
		}

		var saveSeq = 0;
		var scheduleAutosave = debounce( autosave, 700 );

		/* --- zdarzenia: schemat --- */

		grid.addEventListener( 'change', function ( e ) {
			if ( e.target.name !== 'admin_color' ) {
				return;
			}
			touch();
			P.scheme = e.target.value;
			// Nowy schemat startuje bez dostrojenia: stare przesunięcia przefarbowywały go (np. antracyt na brąz).
			[ 'dark', 'light' ].forEach( function ( m ) {
				TUNE_KEYS.forEach( function ( k ) {
					P.tuning[ m ][ k ] = 0;
				} );
			} );
			writeTuningFields();
			renderSliders();
			renderMeta();
			requestPreview();
		} );

		palette.addEventListener( 'change', function ( e ) {
			if ( e.target.name !== 'admin_color' ) {
				return;
			}
			touch();
			P.scheme = data.customKey;
			renderMeta();
			requestPreview();
		} );

		tabs.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[role="tab"]' );
			if ( b ) {
				showGroup( b.getAttribute( 'data-group' ) );
				// Zakładka "Własna paleta" sama ją włącza: bez osobnego pola "Używaj własnej palety".
				if ( b.getAttribute( 'data-group' ) === 'custom' && P.scheme !== data.customKey ) {
					var own = $( 'input[name="admin_color"][value="' + data.customKey + '"]', root );
					if ( own ) {
						own.checked = true;
						own.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					}
				}
			}
		} );

		/* --- zdarzenia: własna paleta --- */

		function markInvalid( input ) {
			input.setAttribute( 'aria-invalid', 'true' );
			var err = $( '#colorify-hex-err-' + input.getAttribute( 'data-key' ), root );
			if ( err ) {
				err.hidden = false;
			}
		}

		function clearInvalid( input ) {
			input.removeAttribute( 'aria-invalid' );
			var err = $( '#colorify-hex-err-' + input.getAttribute( 'data-key' ), root );
			if ( err ) {
				err.hidden = true;
			}
		}

		palette.addEventListener( 'input', function ( e ) {
			var key = e.target.getAttribute( 'data-key' );
			if ( ! key ) {
				return;
			}
			var v = e.target.value;
			if ( e.target.type === 'color' ) {
				$( '#colorify-hex-' + key, root ).value = v.toUpperCase();
				clearInvalid( $( '#colorify-hex-' + key, root ) );
			} else {
				if ( v && v.charAt( 0 ) !== '#' ) {
					v = '#' + v;
				}
				if ( ! HEX.test( v ) ) {
					return;
				}
				clearInvalid( e.target );
				$( '#colorify-sw-' + key, root ).value = v.toLowerCase();
			}
			touch();
			P.custom[ key ] = v.toLowerCase();
			P.scheme = data.customKey;
			$( 'input[name="admin_color"][value="' + data.customKey + '"]', root ).checked = true;
			renderMeta();
			renderDots();
			schedulePreview();
		} );

		palette.addEventListener( 'focusout', function ( e ) {
			if ( e.target.classList && e.target.classList.contains( 'colorify-hex' ) ) {
				var v = e.target.value;
				if ( v && v.charAt( 0 ) !== '#' ) {
					v = '#' + v;
				}
				if ( HEX.test( v ) ) {
					e.target.value = v.toUpperCase();
				}
			}
		} );

		/** Niepoprawny HEX przy zapisie: ramka, tekst pod polem, fokus na pierwszym. */
		function validateHex() {
			var first = null;
			$$( '.colorify-hex', root ).forEach( function ( input ) {
				var v = input.value;
				if ( v && v.charAt( 0 ) !== '#' ) {
					v = '#' + v;
				}
				if ( HEX.test( v ) ) {
					clearInvalid( input );
				} else {
					markInvalid( input );
					first = first || input;
				}
			} );
			if ( first ) {
				first.focus();
				return false;
			}
			return true;
		}

		/* --- zdarzenia: tryb i dostrojenie --- */

		root.addEventListener( 'click', function ( e ) {
			var pick = e.target.closest( '[data-pick-mode]' );
			if ( pick ) {
				touch();
				var mode = pick.getAttribute( 'data-pick-mode' );
				setView( mode );
				if ( follows() ) {
					var done = lastPayloads ? w.colorify.applyPayloads( lastPayloads, mode ) : w.colorify.setMode( mode );
					done.then( function () {
						syncBarMode( mode );
					} ).catch( function () {} );
				}
				return;
			}
			var tune = e.target.closest( '[data-tune-mode]' );
			if ( tune && tune.closest( '#colorify-tune-mode' ) ) {
				P.tuneMode = tune.getAttribute( 'data-tune-mode' );
				renderSliders();
			}
		} );

		d.addEventListener( 'colorify-ui:mode', function ( e ) {
			if ( ! follows() || P.mode === e.detail.mode ) {
				return;
			}
			setView( e.detail.mode );
			if ( lastPayloads ) {
				w.colorify.applyPayloads( lastPayloads, e.detail.mode ).catch( function () {} );
			}
		} );

		$( '.colorify-sliders', root ).addEventListener( 'input', function ( e ) {
			if ( e.target.type !== 'range' ) {
				return;
			}
			var row = e.target.closest( '.colorify-slider' );
			var key = row.getAttribute( 'data-id' );
			var v = parseInt( e.target.value, 10 ) || 0;
			touch();
			P.tuning[ P.tuneMode ][ key ] = v;
			$( '#colorify-o-' + key, root ).textContent = fmt( v );
			row.classList.toggle( 'is-strong', Math.abs( v ) > ( data.tuningWarn || 50 ) );
			writeTuningFields();
			renderMeta();
			schedulePreview();
		} );

		$( '#colorify-tune-reset', root ).addEventListener( 'click', function () {
			touch();
			TUNE_KEYS.forEach( function ( k ) {
				P.tuning[ P.tuneMode ][ k ] = 0;
			} );
			writeTuningFields();
			renderSliders();
			renderMeta();
			requestPreview();
		} );

		/* --- zdarzenia: zakres --- */

		$$( 'input[name="colorify_settings_scope"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', function () {
				touch();
				var scope = scopeValue();
				$$( '.colorify-scope-seg .colorify-seg__opt' ).forEach( function ( opt ) {
					var input = $( 'input', opt );
					opt.setAttribute( 'aria-checked', input && input.checked ? 'true' : 'false' );
				} );
				var desc = $( '#colorify-scope-desc' );
				if ( desc ) {
					desc.textContent = desc.getAttribute( scope === 'global' ? 'data-global' : 'data-user' ) || desc.textContent;
				}
				var userLook = $( '#colorify-user-look' );
				if ( userLook ) {
					userLook.hidden = scope !== 'user';
				}
				if ( ! settings && data.bundles && data.bundles[ scope ] ) {
					// Profil administratora: komplet wyglądu wybranego zakresu.
					var b = data.bundles[ scope ];
					P.scope = scope;
					loadState( { scheme: b.scheme, mode: b.mode, custom: b.customColors, tuning: b.customTuning } );
					P.custom = clone( P.custom );
					[ 'bg', 'bg2', 'accent', 'accent2' ].forEach( function ( k ) {
						P.custom[ k ] = String( P.custom[ k ] ).toLowerCase();
					} );
					renderAll();
					requestPreview();
				} else if ( settings ) {
					if ( follows() ) {
						requestPreview();
					} else {
						restorePanel();
					}
				}
			} );
		} );

		/* --- zapis --- */

		function collect() {
			var f = {
				user_id: data.targetUser,
				colorify_admin_appearance: P.mode,
				admin_color: P.scheme,
			};
			PALETTE_KEYS.forEach( function ( k ) {
				var v = P.custom[ k ];
				f[ 'colorify_custom_colors[' + k + ']' ] = String( v ).toUpperCase();
			} );
			[ 'dark', 'light' ].forEach( function ( m ) {
				TUNE_KEYS.forEach( function ( k ) {
					f[ 'colorify_custom_tuning[' + m + '][' + k + ']' ] = P.tuning[ m ][ k ];
				} );
			} );
			if ( data.canScope ) {
				f.colorify_settings_scope = scopeValue();
			}
			return f;
		}

		function saveAjax() {
			if ( ! validateHex() ) {
				return;
			}
			busy( saveBtn, true );
			setStatus( 'saving' );
			post( 'colorify_save_appearance_state', collect() )
				.then( function ( res ) {
					busy( saveBtn, false );
					setStatus( 'saved' );
					dirty = false;
					last = readSnapshot();
					data.hasPersonal = !! res.hasPersonal;
					$$( '.colorify-ab-scheme' ).forEach( function ( el ) {
						el.textContent = res.name;
					} );
				} )
				.catch( function () {
					busy( saveBtn, false );
					setStatus( 'error' );
					speak( cfg.i18n.saveError );
				} );
		}

		if ( ! settings && saveBtn ) {
			saveBtn.addEventListener( 'click', saveAjax );
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( settings ) {
					if ( ! validateHex() ) {
						e.preventDefault();
						return;
					}
					busy( saveBtn, true );
					setStatus( 'saving' );
					return;
				}
				// Profil, przycisk rdzenia "Zaktualizuj profil": bez ruszania wyboru nic z niego nie idzie w POST.
				if ( ! dirty ) {
					var names = $$( 'input[name^="colorify_"], input[name="admin_color"]', root ).concat( $$( 'input[name="colorify_settings_scope"]' ) );
					names.forEach( function ( el ) {
						el.disabled = true;
					} );
					setTimeout( function () {
						names.forEach( function ( el ) {
							el.disabled = false;
						} );
					}, 0 );
				} else if ( ! validateHex() ) {
					e.preventDefault();
				}
			} );
		}

		/* --- start --- */

		// Zapis jest automatyczny; przycisk zostaje tylko dla przeglądarki bez skryptów.
		if ( saveBtn ) {
			saveBtn.hidden = true;
		}

		loadState( readState() );
		P.tuneMode = P.mode;
		last = readSnapshot();
		tabs.hidden = false;
		showGroup( groupOf( P.scheme ) );
		renderSliders();
		app.picker = { follows: follows };
	}

	/* ---------- Dopasuj do strony ---------- */
	/* Strona główna ładuje się w niewidocznej ramce w przeglądarce administratora, a wygląd czytamy z gotowych stylów
	 * (getComputedStyle). Działa z każdym motywem i kreatorem, bo nie zależy od tego, gdzie motyw trzyma ustawienia. */

	function parseColor( value ) {
		var m = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,/\s]+([\d.]+%?))?/.exec( value || '' );
		if ( ! m ) {
			return null;
		}
		var a = m[ 4 ] === undefined ? 1 : m[ 4 ].slice( -1 ) === '%' ? parseFloat( m[ 4 ] ) / 100 : parseFloat( m[ 4 ] );
		var rgb = [ +m[ 1 ], +m[ 2 ], +m[ 3 ] ];
		var lin = rgb.map( function ( c ) {
			c /= 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		return {
			hex: '#' + rgb.map( function ( c ) {
				return ( '0' + c.toString( 16 ) ).slice( -2 );
			} ).join( '' ),
			alpha: a,
			lum: 0.2126 * lin[ 0 ] + 0.7152 * lin[ 1 ] + 0.0722 * lin[ 2 ],
			sat: Math.max.apply( null, rgb ) - Math.min.apply( null, rgb ),
		};
	}

	function mixHex( hex, target, ratio ) {
		return '#' + [ 1, 3, 5 ].map( function ( i ) {
			var v = Math.round( parseInt( hex.substr( i, 2 ), 16 ) * ( 1 - ratio ) + target * ratio );
			return ( '0' + v.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function firstFamily( stack ) {
		return ( stack || '' ).split( ',' )[ 0 ].replace( /["']/g, '' ).trim();
	}

	/** Pliki czcionek (tylko z tego samego serwera) dla podanych rodzin, z arkuszy strony. */
	function collectFaces( doc, families ) {
		var wanted = families.map( function ( f ) {
			return f.toLowerCase();
		} );
		var faces = [];
		function walk( sheet ) {
			var rules;
			try {
				rules = sheet.cssRules;
			} catch ( e ) {
				return; // arkusz z innego serwera
			}
			Array.prototype.forEach.call( rules || [], function ( rule ) {
				if ( rule.styleSheet ) {
					walk( rule.styleSheet );
					return;
				}
				if ( rule.type !== 5 || faces.length >= 16 ) {
					return;
				}
				var family = firstFamily( rule.style.getPropertyValue( 'font-family' ) );
				var src = rule.style.getPropertyValue( 'src' );
				var hit = /url\(\s*["']?([^"')]+\.woff2)[^"')]*["']?\s*\)/i.exec( src ) || /url\(\s*["']?([^"')]+\.(?:woff|ttf|otf))[^"')]*["']?\s*\)/i.exec( src );
				if ( wanted.indexOf( family.toLowerCase() ) === -1 || ! hit ) {
					return;
				}
				var url;
				try {
					url = new URL( hit[ 1 ], sheet.href || doc.baseURI );
				} catch ( e ) {
					return;
				}
				if ( url.origin !== w.location.origin ) {
					return;
				}
				faces.push( {
					family: family,
					url: url.origin + url.pathname,
					weight: rule.style.getPropertyValue( 'font-weight' ) || '400',
					style: rule.style.getPropertyValue( 'font-style' ) || 'normal',
					range: rule.style.getPropertyValue( 'unicode-range' ) || '',
				} );
			} );
		}
		Array.prototype.forEach.call( doc.styleSheets, walk );
		return faces;
	}

	function measureSite( doc, win ) {
		function shown( el ) {
			var r = el.getBoundingClientRect();
			return r.width > 20 && r.height > 10;
		}
		function firstShown( selector ) {
			return Array.prototype.filter.call( doc.querySelectorAll( selector ), shown )[ 0 ] || null;
		}
		var bodyCs = win.getComputedStyle( doc.body );
		var text = parseColor( bodyCs.color );
		var headingEl = firstShown( 'h1, h2, h3' );
		var heading = headingEl ? win.getComputedStyle( headingEl ).fontFamily : bodyCs.fontFamily;

		// Nakładki, które nie są stroną: baner cookies, pasek administratora, okienka czatu.
		var FOREIGN = '#wpadminbar, [id*="cookie" i], [class*="cookie" i], [id*="consent" i], [class*="consent" i], [class*="cmplz" i], [id*="cmplz" i], [class*="gdpr" i], [id*="chat" i]';
		function foreign( el ) {
			try {
				// Klasy na body/html (np. "cmplz-..." od wtyczki cookies) nie czynią całej strony nakładką.
				var hit = el.closest( FOREIGN );
				return !! hit && hit !== doc.body && hit !== doc.documentElement;
			} catch ( e ) {
				return false;
			}
		}

		// Przycisk strony (do rogów): pierwszy widoczny z własnym tłem, poza nakładkami.
		var button = null;
		Array.prototype.some.call(
			doc.querySelectorAll( '.elementor-button, .wp-block-button__link, .ast-button, .ast-custom-button, .btn, a.button, button[type="submit"], input[type="submit"], [class*="button"], [class*="btn"]' ),
			function ( el ) {
				var bg = shown( el ) && ! foreign( el ) ? parseColor( win.getComputedStyle( el ).backgroundColor ) : null;
				if ( bg && bg.alpha > 0.5 && bg.lum < 0.85 ) {
					button = el;
					return true;
				}
				return false;
			}
		);

		// Akcent: kolor nasycony, który na stronie widać najwięcej (tła przycisków, kolory odnośników, ikon, nagłówków),
		// a nie pierwszy z brzegu element. Kolory prawie czarne to tło menu, nie akcent.
		var votes = {};
		Array.prototype.forEach.call( doc.querySelectorAll( 'a, button, [class*="button"], [class*="btn"], h1, h2, h3, h4, svg, i, strong' ), function ( el ) {
			if ( foreign( el ) ) {
				return;
			}
			var r = el.getBoundingClientRect();
			if ( r.width < 4 || r.height < 4 ) {
				return;
			}
			var cs = win.getComputedStyle( el );
			[ [ cs.backgroundColor, r.width * r.height / 100 ], [ cs.color, r.width / 10 ], [ cs.fill, r.width / 10 ] ].forEach( function ( pair ) {
				var c = parseColor( pair[ 0 ] );
				if ( ! c || c.alpha < 0.6 || c.sat < 40 || c.lum < 0.04 || c.lum > 0.8 ) {
					return;
				}
				votes[ c.hex ] = votes[ c.hex ] || { c: c, n: 0 };
				votes[ c.hex ].n += pair[ 1 ];
			} );
		} );
		var ranked = Object.keys( votes ).map( function ( k ) {
			return votes[ k ];
		} ).sort( function ( a, b ) {
			return b.n - a.n;
		} );
		var accent = ranked[ 0 ] ? ranked[ 0 ].c : null;
		// Drugi akcent: następny wyraźnie inny kolor, a gdy go nie ma, jaśniejszy odcień akcentu.
		var link = null;
		ranked.slice( 1 ).some( function ( v ) {
			var diff = [ 1, 3, 5 ].reduce( function ( s, i ) {
				return s + Math.abs( parseInt( v.c.hex.substr( i, 2 ), 16 ) - parseInt( accent.hex.substr( i, 2 ), 16 ) );
			}, 0 );
			if ( diff > 60 ) {
				link = v.c;
				return true;
			}
			return false;
		} );
		if ( accent && ! link ) {
			link = { hex: mixHex( accent.hex, 255, 0.25 ) };
		}

		// Ciemna powierzchnia strony (stopka, nagłówek) na menu panelu; bez niej kolor tekstu, a na końcu grafit.
		var ink = null;
		Array.prototype.some.call( doc.querySelectorAll( 'footer, .site-footer, [data-elementor-type="footer"], header, .site-header' ), function ( el ) {
			var bg = shown( el ) ? parseColor( win.getComputedStyle( el ).backgroundColor ) : null;
			if ( bg && bg.alpha > 0.5 && bg.lum < 0.12 ) {
				ink = bg;
				return true;
			}
			return false;
		} );
		if ( ! ink ) {
			ink = text && text.lum < 0.12 ? text : { hex: '#1d2327', lum: 0.015 };
		}
		// Szary tekst albo szara stopka to za jasne tło dla menu: przyciemniamy do grafitu w tym samym odcieniu.
		ink = ink.lum > 0.03 ? mixHex( ink.hex, 0, 0.6 ) : ink.hex;

		var radius = button ? parseFloat( win.getComputedStyle( button ).borderTopLeftRadius ) || 0 : 4;

		return {
			heading: heading,
			body: bodyCs.fontFamily,
			radius: Math.round( Math.min( 10, radius ) ),
			faces: collectFaces( doc, [ firstFamily( heading ), firstFamily( bodyCs.fontFamily ) ] ),
			colors: accent
				? { bg: mixHex( ink, 0, 0.25 ), bg2: ink, accent: accent.hex, accent2: ( link || accent ).hex }
				: null,
		};
	}

	function initSiteMatch() {
		var run = $( '#colorify-match-run' );
		var box = $( '#colorify-match-result' );
		if ( ! run || ! box || ! cfg.homeUrl ) {
			return;
		}

		function show( nodes ) {
			box.textContent = '';
			[].concat( nodes ).forEach( function ( n ) {
				box.appendChild( typeof n === 'string' ? d.createTextNode( n ) : n );
			} );
			box.hidden = false;
		}

		function el( tag, cls, text ) {
			var n = d.createElement( tag );
			if ( cls ) {
				n.className = cls;
			}
			if ( text ) {
				n.textContent = text;
			}
			return n;
		}

		/** Nazwa czcionki dla człowieka: słowo kluczowe przeglądarki to czcionka systemowa. */
		function fontLabel( stack ) {
			var name = firstFamily( stack );
			return /^(-apple-system|blinkmacsystemfont|system-ui|ui-sans-serif|ui-serif|sans-serif|serif)$/i.test( name ) ? cfg.i18n.matchSystem : name;
		}

		function preview( look ) {
			var list = el( 'dl', 'colorify-match__list' );
			function row( label, value ) {
				list.appendChild( el( 'dt', '', label ) );
				var dd = el( 'dd' );
				[].concat( value ).forEach( function ( v ) {
					dd.appendChild( typeof v === 'string' ? d.createTextNode( v ) : v );
				} );
				list.appendChild( dd );
			}
			if ( look.colors ) {
				row( cfg.i18n.matchColors, [ 'bg2', 'accent', 'accent2' ].map( function ( key ) {
					var sw = el( 'span', 'colorify-match__swatch' );
					sw.setAttribute( 'data-colorify-swatch', '' );
					sw.style.background = look.colors[ key ];
					sw.title = look.colors[ key ];
					return sw;
				} ) );
			}
			row( cfg.i18n.matchHeading, fontLabel( look.heading ) );
			row( cfg.i18n.matchBody, fontLabel( look.body ) );
			row( cfg.i18n.matchRadius, look.radius + ' px' );

			var apply = el( 'button', 'button button-primary', cfg.i18n.matchApply );
			apply.type = 'button';
			var cancel = el( 'button', 'button', cfg.i18n.matchCancel );
			cancel.type = 'button';
			cancel.addEventListener( 'click', function () {
				box.hidden = true;
			} );
			apply.addEventListener( 'click', function () {
				apply.disabled = true;
				post( 'colorify_apply_site_look', { look: JSON.stringify( look ) } )
					.then( function () {
						w.location.reload();
					} )
					.catch( function () {
						apply.disabled = false;
						show( cfg.i18n.saveError );
					} );
			} );
			var actions = el( 'div', 'colorify-match__actions' );
			actions.appendChild( apply );
			actions.appendChild( cancel );

			show( [
				list,
				el( 'p', 'colorify-match__note', look.faces.length ? cfg.i18n.matchFonts + ' ' + look.faces.length + '.' : cfg.i18n.matchNoFonts ),
				el( 'p', 'colorify-match__note', look.colors ? cfg.i18n.matchNote : '' ),
				actions,
			] );
		}

		run.addEventListener( 'click', function () {
			var frame = d.createElement( 'iframe' );
			var done = false;
			function finish( look ) {
				if ( done ) {
					return;
				}
				done = true;
				clearTimeout( timer );
				frame.remove();
				run.disabled = false;
				if ( look ) {
					preview( look );
				} else {
					show( cfg.i18n.matchError );
				}
			}
			var timer = setTimeout( finish, 25000 );
			run.disabled = true;
			show( cfg.i18n.matchRun );
			frame.setAttribute( 'aria-hidden', 'true' );
			frame.tabIndex = -1;
			frame.style.cssText = 'position:absolute;left:-9999px;top:0;width:1280px;height:900px;border:0;visibility:hidden';
			frame.addEventListener( 'load', function () {
				// Chwila na czcionki i style dokładane skryptem.
				setTimeout( function () {
					try {
						finish( measureSite( frame.contentDocument, frame.contentWindow ) );
					} catch ( e ) {
						finish( null ); // strona zabrania ramki albo przekierowała na inny serwer
					}
				}, 1200 );
			} );
			frame.src = cfg.homeUrl;
			d.body.appendChild( frame );
		} );
	}

	/* ---------- Start ---------- */

	/* Styl panelu (Ustawienia, Colorify): zapis od razu i przeładowanie, bo zmienia arkusz całego panelu. */
	function initStyleSwitch() {
		$$( 'input[name="colorify_admin_style"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				var form = input.closest( 'form' );
				$$( '.colorify-style-seg .colorify-seg__opt' ).forEach( function ( opt ) {
					var r = $( 'input', opt );
					opt.setAttribute( 'aria-checked', r && r.checked ? 'true' : 'false' );
				} );
				if ( ! form ) {
					return;
				}
				fetch( form.getAttribute( 'action' ) || w.location.href, {
					method: 'POST',
					credentials: 'same-origin',
					body: new FormData( form ),
				} ).then( function () {
					w.location.reload();
				} );
			} );
		} );
	}

	function init() {
		initStyleSwitch();
		initBarMenu();
		initPicker();
		initSiteMatch();
	}

	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )( window, document );
