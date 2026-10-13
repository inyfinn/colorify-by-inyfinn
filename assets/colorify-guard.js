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
 *   dark mode   whole admin page (the dark engine recolours unknown plugin styles and a
 *               few saturated colours can land under AA)
 *   light mode  admin menu and admin bar only. Content keeps WordPress colours, but plugins
 *               paint their own menu items (a fixed background for the current item) and
 *               count on core's white menu text, which a scheme may have replaced.
 *
 * Icon fonts drawn in ::before (menu icons, dashicons) are measured too, against 3:1, and so is any glyph in the
 * ::before or ::after of a text element (notice icons). Inline SVG shapes (fill and stroke) and the border of
 * checkboxes and radios are held to 3:1 as well. Text inside a link or button is measured again on mouseover and
 * focusin, in the hovered state, and fixed through a rule that only holds while the control is hovered or focused.
 *
 * Fixes never make the page slide between colours: the fix rules switch transitions off, and a measurement that has
 * to take a fix off for a moment switches transitions off inline for its length (quiet()).
 *
 * How a fix is applied: the element gets data-colorify-guard="N" and one generated rule
 *   [data-colorify-guard="N"]:not(:hover):not(:active) { color: ... !important }
 * (also dropped while the link or button around it is hovered or pressed), so the fix only
 * holds in the resting state it was measured in and the page's own hover colours stay as designed. On every pass fixed elements are measured again without
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
	var ICONS = '.wp-menu-image, .dashicons, .dashicons-before, .ab-icon';
	var UI_MIN = 3;
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
	var ATTR_B = 'data-colorify-guard-b';
	var ATTR_P = 'data-colorify-guard-p';
	var ATTR_Q = 'data-colorify-guard-q';
	var ATTR_H = 'data-colorify-guard-h';

	function rule( index, item ) {
		if ( item.prop === 'border' ) {
			// The border of a checkbox or radio: always, whatever the state.
			return '[' + ATTR_B + '="' + index + '"]{border-color:' + item.colour + '!important;transition:none!important}';
		}
		if ( item.prop === 'hover' ) {
			// Hovered or focused: on the element itself or inside the link or button that is.
			var on = ':is(:hover,:focus)';
			return '[' + ATTR_H + '="' + index + '"]' + on + ',:is(a,button,[role="button"],summary,label)' + on + ' [' + ATTR_H + '="' + index + '"]'
				+ '{color:' + item.colour + '!important;-webkit-text-fill-color:' + item.colour + '!important;transition:none!important}';
		}
		if ( item.prop === 'pseudo' ) {
			// Glyph drawn in ::before or ::after of an element that is not an icon itself (notice icons, badges).
			return '[' + ATTR_Q + '="' + index + '"]::before,[' + ATTR_Q + '="' + index + '"]::after{color:' + item.colour + '!important;-webkit-text-fill-color:' + item.colour + '!important;transition:none!important}';
		}
		if ( item.prop === 'fill' || item.prop === 'stroke' || item.prop === 'fill,stroke' ) {
			return '[' + ATTR_P + '="' + index + '"]{' + item.prop.split( ',' ).map( function ( p ) {
				return p + ':' + item.colour + '!important';
			} ).join( ';' ) + ';transition:none!important}';
		}
		// Resting state only: not while the element, or the link or button it sits in, is hovered or pressed.
		var busy = ':is(a,button,[role="button"],summary,label):is(:hover,:active)';
		var sel = '[' + ATTR + '="' + index + '"]:not(:hover):not(:active):not(' + busy + ' *)';
		return sel + ',' + sel + '::before{color:' + item.colour + '!important;-webkit-text-fill-color:' + item.colour + '!important;transition:none!important}';
	}

	function slot( colour, prop ) {
		var item = { colour: colour, prop: prop || 'color' };
		var index = -1;
		for ( var i = 0; i < colours.length; i++ ) {
			if ( colours[ i ].colour === item.colour && colours[ i ].prop === item.prop ) {
				index = i;
			}
		}
		if ( index === -1 ) {
			index = colours.push( item ) - 1;
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
		} else if ( sheet.textContent.indexOf( rule( index, item ) ) === -1 ) {
			sheet.textContent += rule( index, item );
		}
		return index;
	}

	// Move a colour towards the end that can be read on this background, as little as needed.
	function fixed( fg, bg, op, min ) {
		var white = { r: 255, g: 255, b: 255, a: 1 };
		var black = { r: 0, g: 0, b: 0, a: 1 };
		var pole = contrast( white, bg ) >= contrast( black, bg ) ? white : black;
		var base = { r: fg.r, g: fg.g, b: fg.b, a: 1 };
		for ( var t = 0.1; t <= 1.0001; t += 0.1 ) {
			var c = mix( base, pole, t );
			if ( contrast( over( { r: c.r, g: c.g, b: c.b, a: op }, bg ), bg ) >= min + TARGET_MARGIN ) {
				return c;
			}
		}
		return pole;
	}

	// Measuring the page's own colour means taking our fix off for a moment. With the page's transitions on, the
	// element would start sliding towards its own colour and the measurement would catch a half-way value, so
	// transitions are switched off inline for the length of the measurement.
	function quiet( el ) {
		var prev = el.style.getPropertyValue( 'transition' );
		var prio = el.style.getPropertyPriority( 'transition' );
		el.style.setProperty( 'transition', 'none', 'important' );
		return function () {
			if ( prev ) {
				el.style.setProperty( 'transition', prev, prio );
			} else {
				el.style.removeProperty( 'transition' );
			}
		};
	}

	// Run a measurement of el with the given attribute removed (and put back by the measurement when still needed).
	function bare( el, attr, run ) {
		var restore = el.hasAttribute( attr ) ? quiet( el ) : null;
		try {
			run();
		} finally {
			if ( restore ) {
				restore();
			}
		}
	}

	/* ---------- one element ---------- */

	function check( el, cache ) {
		bare( el, ATTR, function () {
			measure( el, cache );
		} );
	}

	function measure( el, cache ) {
		// Hover and pressed states are transient: leave the element (and any fix it has) alone.
		if ( el.matches( ':hover, :active' ) || el.closest( 'a:hover, button:hover, [role="button"]:hover, summary:hover, label:hover' ) ) {
			return;
		}
		// Measure the page's own colours, not an earlier fix.
		if ( el.hasAttribute( ATTR ) ) {
			el.removeAttribute( ATTR );
		}
		if ( el.checkVisibility && ! el.checkVisibility( { checkOpacity: true, checkVisibilityCSS: true } ) ) {
			return;
		}
		var cs = w.getComputedStyle( el );
		var fill = cs.webkitTextFillColor;
		var fg = parse( fill && fill !== cs.color ? fill : cs.color );
		// An icon element without text of its own: the glyph lives in ::before.
		var icon = el.matches( ICONS ) && ! el.textContent.trim();
		if ( icon ) {
			var before = w.getComputedStyle( el, '::before' );
			if ( ! before.content || before.content === 'none' || before.content === 'normal' ) {
				return;
			}
			fg = parse( before.color );
		}
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
		var min = icon ? UI_MIN : ( size >= 24 || ( size >= 18.66 && bold ) ? AA_LARGE : AA );
		if ( contrast( over( { r: fg.r, g: fg.g, b: fg.b, a: alpha }, bg ), bg ) >= min ) {
			return;
		}

		el.setAttribute( ATTR, String( slot( css( fixed( fg, bg, around.op, min ) ) ) ) );
	}

	/* ---------- icons and checkboxes (non-text, 3:1) ---------- */

	var SHAPES = 'path,circle,rect,polygon,polyline,ellipse,line,use';

	function visible( el ) {
		if ( el.checkVisibility && ! el.checkVisibility( { checkOpacity: true, checkVisibilityCSS: true } ) ) {
			return false;
		}
		var r = el.getBoundingClientRect();
		return r.width >= 6 && r.height >= 6;
	}

	// An inline SVG icon: every shape painted below 3:1 gets its own fill or stroke moved towards the readable end.
	function checkSvg( svg, cache ) {
		var fixedNodes = Array.prototype.slice.call( svg.querySelectorAll( '[' + ATTR_P + ']' ) );
		if ( svg.hasAttribute( ATTR_P ) ) {
			fixedNodes.push( svg );
		}
		var restores = fixedNodes.map( quiet );
		try {
			measureSvg( svg, cache, fixedNodes );
		} finally {
			restores.forEach( function ( r ) {
				r();
			} );
		}
	}

	function measureSvg( svg, cache, fixedNodes ) {
		fixedNodes.forEach( function ( n ) {
			n.removeAttribute( ATTR_P );
		} );
		if ( ! visible( svg ) ) {
			return;
		}
		var around = surround( svg, cache );
		if ( ! around.bg ) {
			return;
		}
		var shapes = [ svg ].concat( Array.prototype.slice.call( svg.querySelectorAll( SHAPES ), 0, 24 ) );
		shapes.forEach( function ( sh ) {
			var cs = w.getComputedStyle( sh );
			var props = [];
			var paint = null;
			[ [ 'fill', cs.fill, parseFloat( cs.fillOpacity ), 1 ], [ 'stroke', cs.stroke, parseFloat( cs.strokeOpacity ), parseFloat( cs.strokeWidth ) ] ].forEach( function ( p ) {
				if ( ! p[ 1 ] || p[ 1 ] === 'none' || p[ 1 ].indexOf( 'url(' ) === 0 || ! ( p[ 3 ] > 0 ) ) {
					return;
				}
				var c = parse( p[ 1 ] );
				var a = c.a * ( isNaN( p[ 2 ] ) ? 1 : p[ 2 ] ) * around.op;
				if ( c.a > 0 && contrast( over( { r: c.r, g: c.g, b: c.b, a: a }, around.bg ), around.bg ) < UI_MIN ) {
					props.push( p[ 0 ] );
					paint = paint || c;
				}
			} );
			if ( props.length ) {
				sh.setAttribute( ATTR_P, String( slot( css( fixed( paint, around.bg, around.op, UI_MIN ) ), props.join( ',' ) ) ) );
			}
		} );
	}

	function checkBox( el, cache ) {
		bare( el, ATTR_B, function () {
			measureBox( el, cache );
		} );
	}

	function measureBox( el, cache ) {
		if ( el.hasAttribute( ATTR_B ) ) {
			el.removeAttribute( ATTR_B );
		}
		if ( ! visible( el ) ) {
			return;
		}
		var cs = w.getComputedStyle( el );
		var border = parse( cs.borderTopColor );
		if ( ! ( parseFloat( cs.borderTopWidth ) >= 1 ) || cs.borderTopStyle === 'none' || border.a === 0 ) {
			return;
		}
		var around = surround( el.parentElement || el, cache );
		if ( ! around.bg || contrast( over( { r: border.r, g: border.g, b: border.b, a: border.a * around.op }, around.bg ), around.bg ) >= UI_MIN ) {
			return;
		}
		el.setAttribute( ATTR_B, String( slot( css( fixed( border, around.bg, around.op, UI_MIN ) ), 'border' ) ) );
	}

	// A glyph in ::before or ::after (an icon font or a symbol) below 3:1 on what is behind the element.
	function checkPseudo( el, cache ) {
		if ( el.hasAttribute( ATTR_Q ) ) {
			el.removeAttribute( ATTR_Q );
		}
		var bad = null;
		var around = null;
		[ '::before', '::after' ].forEach( function ( which ) {
			var ps = w.getComputedStyle( el, which );
			var content = ps.content;
			if ( ! content || content === 'none' || content === 'normal' || /^["']{2}$/.test( content ) || /^(url|image-set|-webkit-image-set|counter|attr)\(/.test( content ) ) {
				return;
			}
			if ( ps.display === 'none' || ps.visibility === 'hidden' || ( ps.backgroundImage && ps.backgroundImage !== 'none' ) || parse( ps.backgroundColor ).a > 0.1 ) {
				return;
			}
			var fg = parse( ps.color );
			if ( fg.a === 0 ) {
				return;
			}
			around = around || surround( el, cache );
			if ( ! around.bg || contrast( over( { r: fg.r, g: fg.g, b: fg.b, a: fg.a * around.op }, around.bg ), around.bg ) >= UI_MIN ) {
				return;
			}
			bad = bad || fg;
		} );
		if ( bad ) {
			el.setAttribute( ATTR_Q, String( slot( css( fixed( bad, around.bg, around.op, UI_MIN ) ), 'pseudo' ) ) );
		}
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
			Array.prototype.forEach.call( root.querySelectorAll( '[' + ATTR + '], ' + FIELDS + ', ' + ICONS ), add );
		}
		if ( root.matches && root.matches( '[' + ATTR + '], ' + FIELDS + ', ' + ICONS ) ) {
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
					checkPseudo( list[ k ], cache );
				}
				var root = rootList[ i ];
				if ( root.querySelectorAll ) {
					Array.prototype.forEach.call( root.querySelectorAll( 'svg' ), function ( svg ) {
						if ( ! svg.closest( '[data-colorify-guard-skip]' ) && ! svg.parentElement.closest( 'svg' ) ) {
							checkSvg( svg, cache );
						}
					} );
					Array.prototype.forEach.call( root.querySelectorAll( 'input[type=checkbox], input[type=radio]' ), function ( box ) {
						if ( ! box.closest( '[data-colorify-guard-skip]' ) ) {
							checkBox( box, cache );
						}
					} );
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

	/* ---------- hover and focus ---------- */

	var INTERACTIVE = 'a, button, [role="button"], summary, label';
	var lastHover = null;
	var lastHoverAt = 0;

	// Text that turns unreadable only while its link or button is hovered or focused (the page's own :hover colour,
	// often a darker or paler variant). Measured at once on mouseover and focusin, with transitions switched off along
	// the chain from the text to the control, so a half-way colour is never mistaken for the final one.
	function checkHover( target ) {
		var root = target.closest ? target.closest( INTERACTIVE ) : null;
		if ( ! root || ! root.matches( ':is(:hover,:focus,:focus-within)' ) || root.closest( SKIP ) || root.closest( DISABLED ) ) {
			return;
		}
		var now = Date.now();
		if ( root === lastHover && now - lastHoverAt < 250 ) {
			return;
		}
		lastHover = root;
		lastHoverAt = now;

		var cache = new WeakMap();
		var seen = new Set();
		var walker = d.createTreeWalker( root, NodeFilter.SHOW_TEXT );
		var node;
		var count = 0;
		writing = true;
		try {
			while ( ( node = walker.nextNode() ) && count++ < 40 ) {
				var el = node.parentElement;
				if ( ! el || seen.has( el ) || ! node.nodeValue.trim() ) {
					continue;
				}
				seen.add( el );
				var chain = [];
				for ( var n = el; n && n !== root.parentElement; n = n.parentElement ) {
					chain.push( n );
				}
				var restores = chain.map( quiet );
				try {
					el.removeAttribute( ATTR_H );
					var cs = w.getComputedStyle( el );
					var fill = cs.webkitTextFillColor;
					var fg = parse( fill && fill !== cs.color ? fill : cs.color );
					var around = surround( el, cache );
					if ( fg.a === 0 || ! around.bg ) {
						continue;
					}
					var size = parseFloat( cs.fontSize );
					var bold = parseInt( cs.fontWeight, 10 ) >= 700;
					var min = size >= 24 || ( size >= 18.66 && bold ) ? AA_LARGE : AA;
					if ( contrast( over( { r: fg.r, g: fg.g, b: fg.b, a: fg.a * around.op }, around.bg ), around.bg ) < min ) {
						el.setAttribute( ATTR_H, String( slot( css( fixed( fg, around.bg, around.op, min ) ), 'hover' ) ) );
					}
				} finally {
					restores.forEach( function ( r ) {
						r();
					} );
				}
			}
		} finally {
			if ( observer ) {
				observer.takeRecords();
			}
			writing = false;
		}
	}

	function onPointer( e ) {
		if ( d.body && d.body.classList.contains( 'colorify-on' ) && e.target && e.target.nodeType === 1 && inScope( e.target ) ) {
			checkHover( e.target );
		}
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
		// No idle callback: a panel that has just opened (Help, menus) must not stay unreadable for half a second.
		timer = w.setTimeout( flush, delay );
	}

	function queue( el ) {
		if ( everything || ! el || el.nodeType !== 1 ) {
			return;
		}
		pending.add( el );
		if ( pending.size > 50 ) {
			everything = true;
		}
		schedule( 60 );
	}

	// The dark engine works asynchronously and has no "done" event. Its start-up style
	// (.darkreader--fallback) is emptied once the first full pass is over.
	function engineSettled() {
		// No engine on the page (light mode, or it was blocked): nothing to wait for.
		if ( ! dark() || ! d.documentElement.hasAttribute( 'data-darkreader-scheme' ) ) {
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
				w.setTimeout( pass, 600 );
				w.setTimeout( pass, 1400 );
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
		d.addEventListener( 'mouseover', onPointer, true );
		d.addEventListener( 'focusin', onPointer, true );
	}

	if ( d.readyState === 'loading' ) {
		d.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	w.colorifyGuard = { pass: pass };
} )( window, document );
