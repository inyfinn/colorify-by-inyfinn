/**
 * Colorify engine runtime.
 *
 * Loaded in <head>. Starts dark mode before the first paint and exposes a small API
 * used by the toolbar switch and by the scheme picker preview:
 *
 *   colorify.getMode()                         'dark' | 'light'
 *   colorify.setMode( 'dark' | 'light' )       switch without a reload, returns a Promise
 *   colorify.applyPayloads( payloads, mode )   apply tokens fetched from the server (preview)
 *
 * A "payload" comes from PHP (colorify_engine_client_payload): { mode, css, icons, metaColor, dark }.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.colorifyEngine;
	if ( ! cfg || ! cfg.payloads ) {
		return;
	}

	var state = { mode: cfg.mode === 'light' ? 'light' : 'dark', payloads: cfg.payloads };
	var loading = null;

	function ensureDarkReader() {
		if ( w.DarkReader ) {
			return Promise.resolve( w.DarkReader );
		}
		if ( ! loading ) {
			loading = new Promise( function ( resolve, reject ) {
				var s = d.createElement( 'script' );
				s.src = cfg.darkReaderUrl;
				s.onload = function () {
					resolve( w.DarkReader );
				};
				s.onerror = function () {
					loading = null;
					reject( new Error( 'Colorify: dark mode engine failed to load' ) );
				};
				d.head.appendChild( s );
			} );
		}
		return loading;
	}

	function enable( DR, args ) {
		// Same-origin stylesheets are read directly; fetch is only used for the rest.
		DR.setFetchMethod( w.fetch.bind( w ) );
		DR.enable( args.theme, args.fixes );
	}

	function setTokens( payload ) {
		var style = d.getElementById( 'colorify-tokens' );
		if ( style ) {
			style.textContent = payload.css;
		}
		var meta = d.querySelector( 'meta[name="theme-color"]' );
		if ( meta && payload.metaColor ) {
			meta.setAttribute( 'content', payload.metaColor );
		}
		// Menu icons delivered as SVG are painted by core's svg-painter from these colours.
		if ( payload.icons ) {
			w._wpColorScheme = { icons: payload.icons };
			if ( w.wp && w.wp.svgPainter ) {
				try {
					w.wp.svgPainter.setColors( w._wpColorScheme );
					w.wp.svgPainter.paint();
				} catch ( e ) {}
			}
		}
	}

	function apply( payload ) {
		if ( ! payload ) {
			return Promise.resolve();
		}
		var dark = payload.mode === 'dark' && payload.dark;

		setTokens( payload );
		state.mode = dark ? 'dark' : 'light';
		if ( d.body ) {
			d.body.classList.toggle( 'colorify-dark', !! dark );
			d.body.classList.toggle( 'colorify-light', ! dark );
		}

		var done;
		if ( dark ) {
			done = ensureDarkReader().then( function ( DR ) {
				enable( DR, payload.dark );
			} );
		} else {
			if ( w.DarkReader && w.DarkReader.isEnabled() ) {
				w.DarkReader.disable();
			}
			done = Promise.resolve();
		}

		return done.then( function () {
			d.dispatchEvent( new CustomEvent( 'colorify:applied', { detail: { mode: state.mode } } ) );
		} );
	}

	w.colorify = {
		getMode: function () {
			return state.mode;
		},
		setMode: function ( mode ) {
			return apply( state.payloads[ mode === 'light' ? 'light' : 'dark' ] );
		},
		applyPayloads: function ( payloads, mode ) {
			if ( payloads && payloads.dark && payloads.light ) {
				state.payloads = payloads;
			}
			return apply( state.payloads[ ( mode || state.mode ) === 'light' ? 'light' : 'dark' ] );
		},
	};

	// First paint. In dark mode the engine script is already on the page (enqueued before this file).
	var first = state.payloads[ state.mode ];
	if ( state.mode === 'dark' && w.DarkReader && first && first.dark ) {
		enable( w.DarkReader, first.dark );
	}
} )( window, document );
