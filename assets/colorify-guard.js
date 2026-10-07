/**
 * Colorify contrast guard.
 *
 * Last safety net for readability. Once the colours of a screen have settled it measures
 * every visible piece of text against the background that is really behind it (WCAG 2.1
 * contrast, semi-transparent layers composited) and, where the result is below AA, moves
 * the text colour towards the readable end until it passes. Nothing else is touched:
 * no backgrounds, no layout.
 *
 * Scope:
 *   dark mode   whole admin page (the dark engine recolours unknown plugin styles; a few
 *               saturated colours can land under AA)
 *   light mode  admin menu and admin bar only (content keeps WordPress colours; plugins
 *               sometimes hard-code menu colours meant for a dark menu)
 *
 * How a fix is applied: the element gets data-colorify-guard="N" and one generated rule
 *   [data-colorify-guard="N"]:not(:hover):not(:focus):not(:active) { color: ... !important }
 * so the fix only holds in the resting state it was measured in; hover and focus colours
 * of the page stay as designed. On every pass fixed elements are measured again without
 * the fix, so a fix disappears as soon as it is no longer needed.
 *
 * Opt out for a subtree: data-colorify-guard-skip. Switch off entirely: PHP filter
 * colorify_contrast_guard.
 */
( function ( w, d ) {
	'use strict';

	var AA = 4.5;
	var AA_LARGE = 3;
	var TARGET_MARGIN = 0.15;
	var ATTR = 'data-colorify-guard';
	var SKIP = '[data-colorify-guard-skip], script, style, noscript, template, svg, canvas, video, iframe, option';
	var DISABLED = ':disabled, [aria-disabled="true"], .disabled, .button-disabled';
	var FIELDS = 'input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]):not([type=file]), textarea, select';

	/* ---------- colour ---------- */

	var cv = d.createElement( 'canvas' );
	cv.width = cv.height = 1;
	var ctx = cv.getContext( '2d', { willReadFrequently: true } );
	var parsed = new Map();

	function parse( str ) {
		var hit = parsed.get( str );
		if ( hit ) {
			return hit;
		}
		var out;
		var m = /^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,/]\s*([\d.]+%?))?\s*\)$/.exec( str );
		if ( m ) {
			var a = m[ 4 ] === undefined ? 1 : ( m[ 4 ].slice( -1 ) === '%' ? parseFloat( m[ 4 ] ) / 100 : parseFloat( m[ 4 ] ) );
			out = { r: +m[ 1 ], g: +m[ 2 ], b: +m[ 3 ], a: a };
		} else if ( ! str || str === 'transparent' || ! ctx ) {
			out = { r: 0, g: 0, b: 0, a: 0 };
		} else {
			// Anything else (color(srgb ...), oklch(), names): let the canvas resolve it.
			ctx.clearRect( 0, 0, 1, 1 );
			ctx.fillStyle = '#000';
			ctx.fillStyle = str;
			ctx.fillRect( 0, 0, 1, 1 );
			var px = ctx.getImageData( 0, 0, 1, 1 ).data;
			out = px[ 3 ] === 0 ? { r: 0, g: 0, b: 0, a: 0 } : { r: px[ 0 ], g: px[ 1 ], b: px[ 2 ], a: px[ 3 ] / 255 };
		}
		parsed.set( str, out );
		return out;
	}

	function channel( v ) {
		v /= 255;
		return v <= 0.03928 ? v / 12.92 : Math.pow( ( v + 0.055 ) / 1.055, 2.4 );
	}

	function luminance( c ) {
		return 0.2126 * channel( c.r ) + 0.7152 * channel( c.g ) + 0.0722 * channel( c.b );
	}

	function contrast( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );
		return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
	}

	function over( top, bottom ) {
		var a = top.a;
		return { r: top.r * a + bottom.r * ( 1 - a ), g: top.g * a + bottom.g * ( 1 - a ), b: top.b * a + bottom.b * ( 1 - a ), a: 1 };
	}

	function mix( a, b, t ) {
		return { r: a.r + ( b.r - a.r ) * t, g: a.g + ( b.g - a.g ) * t, b: a.b + ( b.b - a.b ) * t, a: 1 };
	}

	function css( c ) {
		return 'rgb(' + Math.round( c.r ) + ',' + Math.round( c.g ) + ',' + Math.round( c.b ) + ')';
	}

	/* ---------- what is behind the text ---------- */

	// Per pass: element -> { bg: opaque colour or null when a picture is in the way, op: opacity }.
	function surround( el, cache ) {
		var hit = cache.get( el );
		if ( hit ) {
			return hit;
		}
		var layers = [];
		var node = el;
		var unknown = false;
		var opaque = false;
		var opacity = 1;
		while ( node && node.nodeType === 1 ) {
			var cs = w.getComputedStyle( node );
			var op = parseFloat( cs.opacity );
			if ( isNaN( op ) ) {
				op = 1;
			}
			opacity *= op;
			if ( ! opaque && ! unknown ) {
				var img = cs.backgroundImage;
				if ( img && img !== 'none' ) {
					if ( img.indexOf( 'url(' ) !== -1 ) {
						unknown = true;
					} else {
						var stops = img.match( /rgba?\([^)]+\)|#[0-9a-f]{3,8}\b/gi );
						if ( stops && stops.length ) {
							var r = 0, g = 0, b = 0, a = 0;
							for ( var i = 0; i < stops.length; i++ ) {
								var s = parse( stops[ i ] );
								r += s.r * s.a;
								g += s.g * s.a;
								b += s.b * s.a;
								a += s.a;
							}
							if ( a > 0 ) {
								layers.push( { r: r / a, g: g / a, b: b / a, a: ( a / stops.length ) * op } );
							}
						}
					}
				}
				var bg = parse( cs.backgroundColor );
				if ( bg.a > 0 ) {
					layers.push( { r: bg.r, g: bg.g, b: bg.b, a: bg.a * op } );
					if ( bg.a * op >= 0.999 ) {
						opaque = true;
					}
				}
			}
			node = node.parentElement;
		}
		var result = null;
		if ( ! unknown ) {
			result = { r: 255, g: 255, b: 255, a: 1 };
			for ( var k = layers.length - 1; k >= 0; k-- ) {
				result = over( layers[ k ], result );
			}
		}
		var out = { bg: result, op: opacity };
		cache.set( el, out );
		return out;
	}

	/* ---------- fixes live in one generated stylesheet ---------- */

	var colours = [];
	var sheet = null;

	function rule( index, colour ) {
		var sel = '[' + ATTR + '="' + index + '"]:not(:hover):not(:focus):not(:active)';
		return sel + '{color:' + colour + '!important;-webkit-text-fill-color:' + colour + '!important}';
	}

	function slot( colour ) {
		var index = colours.indexOf( colour );
		if ( index === -1 ) {
			index = colours.push( colour ) - 1;
		}
		if ( ! sheet || ! sheet.isConnected ) {
			sheet = d.createElement( 'style' );
			sheet.id = 'colorify-guard-fixes';
			// The dark engine skips style elements carrying its own class.
			sheet.className = 'darkreader';
			d.head.appendChild( sheet );
			sheet.textContent = colours.map( function ( c, i ) {
				return rule( i, c );
			} ).join( '' );
		} else if ( sheet.textContent.indexOf( '"' + index + '"]' ) === -1 ) {
			sheet.textContent += rule( index, colour );
		}
		return index;
	}

	/* ---------- one element ---------- */

	function check( el, cache ) {
		// Measure the page's own colours, not an earlier fix.
		if ( el.hasAttribute( ATTR ) ) {
			el.removeAttribute( ATTR );
		}
		// Hover and pressed states are transient; a fix measured in them would be wrong at rest.
		if ( el.matches( ':hover, :active' ) ) {
			return;
		}
		if ( el.checkVisibility && ! el.checkVisibility( { checkOpacity: true, checkVisibilityCSS: true } ) ) {
			return;
		}
		var cs = w.getComputedStyle( el );
		var fill = cs.webkitTextFillColor;
		var fg = parse( fill && fill !== cs.color ? fill : cs.color );
		if ( fg.a === 0 ) {
			return;
		}
		var around = surround( el, cache );
		if ( ! around.bg ) {
			return;
		}
		var bg = around.bg;
		var alpha = fg.a * around.op;
		var size = parseFloat( cs.fontSize );
		var bold = parseInt( cs.fontWeight, 10 ) >= 700;
		var min = size >= 24 || ( size >= 18.66 && bold ) ? AA_LARGE : AA;
		if ( contrast( over( { r: fg.r, g: fg.g, b: fg.b, a: alpha }, bg ), bg ) >= min ) {
			return;
		}

		// Move towards the end that can be read on this background, as little as needed.
		var white = { r: 255, g: 255, b: 255, a: 1 };
		var black = { r: 0, g: 0, b: 0, a: 1 };
		var pole = contrast( white, bg ) >= contrast( black, bg ) ? white : black;
		var base = { r: fg.r, g: fg.g, b: fg.b, a: 1 };
		var fixed = pole;
		for ( var t = 0.1; t <= 1.0001; t += 0.1 ) {
			var c = mix( base, pole, t );
			if ( contrast( over( { r: c.r, g: c.g, b: c.b, a: around.op }, bg ), bg ) >= min + TARGET_MARGIN ) {
				fixed = c;
				break;
			}
		}
		el.setAttribute( ATTR, String( slot( css( fixed ) ) ) );
	}

	/* ---------- passes ---------- */

	var observer = null;
	var writing = false;

	function dark() {
		return !! d.body && d.body.classList.contains( 'colorify-dark' );
	}

	function scopes() {
		if ( dark() ) {
			return [ d.body ];
		}
		return [ d.getElementById( 'adminmenumain' ), d.getElementById( 'wpadminbar' ) ].filter( Boolean );
	}

	function inScope( el ) {
		return scopes().some( function ( root ) {
			return root === el || root.contains( el );
		} );
	}

	function collect( root ) {
		var seen = new Set();
		var list = [];
		function add( el ) {
			if ( el && ! seen.has( el ) && ! el.closest( SKIP ) && ! el.closest( DISABLED ) ) {
				seen.add( el );
				list.push( el );
			}
		}
		var walker = d.createTreeWalker( root, NodeFilter.SHOW_TEXT );
		var node;
		while ( ( node = walker.nextNode() ) ) {
			if ( node.nodeValue && node.nodeValue.trim() ) {
				add( node.parentElement );
			}
		}
		if ( root.querySelectorAll ) {
			// Earlier fixes need a second look even when their text is gone; form fields show text that is not a text node.
			Array.prototype.forEach.call( root.querySelectorAll( '[' + ATTR + '], ' + FIELDS ), add );
		}
		if ( root.matches && root.matches( '[' + ATTR + '], ' + FIELDS ) ) {
			add( root );
		}
		return list;
	}

	function scan( rootList ) {
		if ( ! d.body || ! d.body.classList.contains( 'colorify-on' ) ) {
			return;
		}
		var cache = new WeakMap();
		writing = true;
		try {
			for ( var i = 0; i < rootList.length; i++ ) {
				var list = collect( rootList[ i ] );
				for ( var k = 0; k < list.length; k++ ) {
					check( list[ k ], cache );
				}
			}
		} finally {
			// Forget the mutation records produced by our own attribute writes.
			if ( observer ) {
				observer.takeRecords();
			}
			writing = false;
		}
	}

	function pass() {
		scan( scopes() );
	}

	/* ---------- scheduling ---------- */

	var pending = new Set();
	var everything = false;
	var timer = 0;

	function flush() {
		timer = 0;
		if ( everything ) {
			everything = false;
			pending.clear();
			pass();
			return;
		}
		var list = [];
		pending.forEach( function ( el ) {
			if ( el.isConnected && inScope( el ) ) {
				list.push( el );
			}
		} );
		pending.clear();
		scan( list );
	}

	function schedule( delay ) {
		if ( timer ) {
			return;
		}
		timer = w.setTimeout( function () {
			if ( 'requestIdleCallback' in w ) {
				w.requestIdleCallback( flush, { timeout: 500 } );
			} else {
				flush();
			}
		}, delay );
	}

	function queue( el ) {
		if ( everything || ! el || el.nodeType !== 1 ) {
			return;
		}
		pending.add( el );
		if ( pending.size > 50 ) {
			everything = true;
		}
		schedule( 300 );
	}

	// The dark engine works asynchronously and has no "done" event. Its start-up style
	// (.darkreader--fallback) is emptied once the first full pass is over.
	function engineSettled() {
		if ( ! dark() ) {
			return true;
		}
		var fallback = d.querySelector( '.darkreader--fallback' );
		return !! fallback && fallback.textContent === '';
	}

	function settleThenPass() {
		var tries = 0;
		( function wait() {
			if ( engineSettled() || tries++ > 40 ) {
				pass();
				// Late stylesheets and scripts keep arriving for a moment after load.
				w.setTimeout( pass, 800 );
				w.setTimeout( pass, 2500 );
				return;
			}
			w.setTimeout( wait, 150 );
		} )();
	}

	function start() {
		settleThenPass();

		observer = new MutationObserver( function ( records ) {
			if ( writing ) {
				return;
			}
			for ( var i = 0; i < records.length; i++ ) {
				var r = records[ i ];
				if ( r.type === 'attributes' && r.attributeName === ATTR ) {
					continue;
				}
				queue( r.target.nodeType === 1 ? r.target : r.target.parentElement );
			}
		} );
		observer.observe( d.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: [ 'class', 'style', 'hidden', 'open', 'aria-expanded', 'aria-selected' ] } );

		d.addEventListener( 'colorify:applied', settleThenPass );
	}

	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	w.colorifyGuard = { pass: pass };
} )( window, document );
