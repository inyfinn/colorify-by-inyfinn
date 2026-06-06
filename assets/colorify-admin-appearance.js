/**
 * Panel admin — schematy + własna paleta (4 kolory) + dark/light.
 */
(function () {
	'use strict';

	var cfg = window.colorifyAdminAppearance || {};
	var schemes = cfg.schemes || {};
	var previews = cfg.previews || {};
	var schemeOrder = cfg.schemeOrder || [];
	var customDefaults = cfg.customColors || {};
	var tuningDefaults = {
		dark: {
			bg_brightness: 0,
			bg_saturation: 0,
			accent_brightness: 0,
			accent_saturation: 0,
		},
		light: {
			bg_brightness: 0,
			bg_saturation: 0,
			accent_brightness: 0,
			accent_saturation: 0,
		},
	};
	var customKey = cfg.customSchemeKey || 'colorify-custom';
	var tuningMin = typeof cfg.tuningMin === 'number' ? cfg.tuningMin : -90;
	var tuningMax = typeof cfg.tuningMax === 'number' ? cfg.tuningMax : 90;
	var tuningWarn = typeof cfg.tuningWarn === 'number' ? cfg.tuningWarn : 50;
	var state = {
		mode: cfg.mode === 'light' ? 'light' : 'dark',
		scheme: cfg.scheme || 'colorify-lime',
		custom: Object.assign(
			{ bg: '#050f0c', bg2: '#0b231c', accent: '#B4E717', accent2: '#92C200' },
			cfg.customColors || {}
		),
		tuning: {
			dark: Object.assign({}, tuningDefaults.dark, (cfg.customTuning && cfg.customTuning.dark) || {}),
			light: Object.assign({}, tuningDefaults.light, (cfg.customTuning && cfg.customTuning.light) || {}),
		},
	};

	function accentSoft(hex, mode) {
		var pct = mode === 'light' ? '10' : '15';
		return 'color-mix(in srgb, ' + hex + ' ' + pct + '%, transparent)';
	}

	function relativeLuminance(hex) {
		var h = String(hex || '').replace('#', '');
		if (h.length === 3) {
			h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
		}
		if (h.length !== 6) {
			return 0;
		}
		var ch = [];
		for (var i = 0; i < 3; i++) {
			var c = parseInt(h.slice(i * 2, i * 2 + 2), 16) / 255;
			ch.push(c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4));
		}
		return 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
	}

	function contrastRatio(fg, bg) {
		var l1 = relativeLuminance(fg);
		var l2 = relativeLuminance(bg);
		var lighter = Math.max(l1, l2);
		var darker = Math.min(l1, l2);
		return (lighter + 0.05) / (darker + 0.05);
	}

	function ensureVisibleOnDarkBg(hex, bg, minRatio) {
		var out = normalizeHex(hex) || hex;
		for (var i = 0; i < 40; i++) {
			if (contrastRatio(out, bg) >= minRatio) {
				break;
			}
			out = interpolateHex(out, '#ffffff', 0.08);
			if (i > 18 && contrastRatio(out, bg) < minRatio) {
				out = interpolateHex(out, '#92C200', 0.14);
			}
		}
		return out;
	}

	function ensureContrastOnBg(hex, bg, minRatio) {
		var out = normalizeHex(hex) || hex;
		for (var i = 0; i < 28; i++) {
			if (contrastRatio(out, bg) >= minRatio) {
				break;
			}
			out = interpolateHex(out, '#1C4B42', 0.1);
		}
		return out;
	}

	function interpolateHex(from, to, ratio) {
		var f = normalizeHex(from);
		var t = normalizeHex(to);
		if (!f || !t) {
			return from;
		}
		ratio = Math.max(0, Math.min(1, ratio));
		var fh = f.replace('#', '');
		var th = t.replace('#', '');
		var out = [];
		for (var i = 0; i < 3; i++) {
			var a = parseInt(fh.slice(i * 2, i * 2 + 2), 16);
			var b = parseInt(th.slice(i * 2, i * 2 + 2), 16);
			out.push(Math.round(a + (b - a) * ratio).toString(16).padStart(2, '0'));
		}
		return '#' + out.join('');
	}

	function hexToHsl(hex) {
		var h = normalizeHex(hex);
		if (!h) {
			return { h: 0, s: 0, l: 0 };
		}
		var raw = h.replace('#', '');
		var r = parseInt(raw.slice(0, 2), 16) / 255;
		var g = parseInt(raw.slice(2, 4), 16) / 255;
		var b = parseInt(raw.slice(4, 6), 16) / 255;
		var max = Math.max(r, g, b);
		var min = Math.min(r, g, b);
		var l = (max + min) / 2;
		var s = 0;
		var hue = 0;

		if (max !== min) {
			var d = max - min;
			s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
			if (max === r) {
				hue = (g - b) / d + (g < b ? 6 : 0);
			} else if (max === g) {
				hue = (b - r) / d + 2;
			} else {
				hue = (r - g) / d + 4;
			}
			hue /= 6;
		}

		return { h: hue * 360, s: s * 100, l: l * 100 };
	}

	function hslToHex(h, s, l) {
		h = ((h % 360) + 360) % 360;
		s = Math.max(0, Math.min(100, s)) / 100;
		l = Math.max(0, Math.min(100, l)) / 100;

		if (s <= 0.00001) {
			var val = Math.round(l * 255).toString(16).padStart(2, '0');
			return '#' + val + val + val;
		}

		var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
		var p = 2 * l - q;
		var hk = h / 360;
		var channels = [];

		[hk + 1 / 3, hk, hk - 1 / 3].forEach(function (t) {
			if (t < 0) {
				t += 1;
			}
			if (t > 1) {
				t -= 1;
			}
			var v;
			if (t < 1 / 6) {
				v = p + (q - p) * 6 * t;
			} else if (t < 1 / 2) {
				v = q;
			} else if (t < 2 / 3) {
				v = p + (q - p) * (2 / 3 - t) * 6;
			} else {
				v = p;
			}
			channels.push(Math.round(v * 255).toString(16).padStart(2, '0'));
		});

		return '#' + channels.join('');
	}

	function adjustHexHsl(hex, brightness, saturation) {
		var hsl = hexToHsl(hex);
		return hslToHex(
			hsl.h,
			Math.max(0, Math.min(100, hsl.s + (saturation || 0))),
			Math.max(0, Math.min(100, hsl.l + (brightness || 0)))
		);
	}

	function clampTuningValue(value) {
		var num = parseInt(value, 10);
		if (isNaN(num)) {
			return 0;
		}
		return Math.max(tuningMin, Math.min(tuningMax, num));
	}

	function isTuningOverThreshold(value) {
		return parseInt(value, 10) > tuningWarn;
	}

	function tuningLegacyEffectiveMagnitude(magnitude) {
		var abs = Math.abs(parseInt(magnitude, 10) || 0);
		if (!abs) {
			return 0;
		}
		var legacyFullAt = 50;
		var legacyMin = 0.05;
		var scale = abs >= legacyFullAt ? 1 : legacyMin + (1 - legacyMin) * (abs / legacyFullAt);
		return abs * scale;
	}

	/**
	 * Krzywa czułości (wszystkie suwaki):
	 * |50| → efekt jak dawniej przy |20|, |70| → jak przy |45|, powyżej |70| pełna moc.
	 */
	function effectiveTuningDelta(value) {
		var raw = parseInt(value, 10) || 0;
		if (!raw) {
			return 0;
		}
		var sign = raw < 0 ? -1 : 1;
		var abs = Math.abs(raw);
		var anchorSoft = typeof cfg.tuningSensAnchorSoft === 'number' ? cfg.tuningSensAnchorSoft : 50;
		var anchorStrong = typeof cfg.tuningSensAnchorStrong === 'number' ? cfg.tuningSensAnchorStrong : 70;
		var refLow = typeof cfg.tuningSensRefLow === 'number' ? cfg.tuningSensRefLow : 20;
		var refHigh = typeof cfg.tuningSensRefHigh === 'number' ? cfg.tuningSensRefHigh : 45;
		var effSoft = tuningLegacyEffectiveMagnitude(refLow);
		var effStrong = tuningLegacyEffectiveMagnitude(refHigh);

		if (abs >= anchorStrong) {
			return sign * (effStrong + (abs - anchorStrong));
		}
		if (abs >= anchorSoft) {
			var t = (abs - anchorSoft) / (anchorStrong - anchorSoft);
			return sign * (effSoft + t * (effStrong - effSoft));
		}
		return sign * (effSoft * (abs / anchorSoft));
	}

	function applyTuning(colors, mode) {
		var m = mode === 'light' ? 'light' : 'dark';
		var t = state.tuning[m] || tuningDefaults[m];
		var accentSoft = colors.accentSoft || colors.accent2 || colors.accent;
		var bgB = effectiveTuningDelta(t.bg_brightness);
		var bgS = effectiveTuningDelta(t.bg_saturation);
		var accB = effectiveTuningDelta(t.accent_brightness);
		var accS = effectiveTuningDelta(t.accent_saturation);
		return {
			bg: adjustHexHsl(colors.bg, bgB, bgS),
			bg2: adjustHexHsl(colors.bg2, bgB, bgS),
			accent: adjustHexHsl(colors.accent, accB, accS),
			accentSoft: adjustHexHsl(accentSoft, accB, accS),
			accent2: adjustHexHsl(colors.accent2 || accentSoft, accB, accS),
		};
	}

	function toneAccentForLight(accent, accentSoft, bg, bg2) {
		var seed = interpolateHex(accent, '#1C4B42', 0.22);
		var seedSoft = interpolateHex(accentSoft, '#3d524c', 0.35);
		return {
			accent: ensureContrastOnBg(seed, bg2, 4.5),
			soft: ensureContrastOnBg(seedSoft, bg2, 3.2),
		};
	}

	function hexToRgbCsv(hex) {
		var h = String(hex || '').replace('#', '');
		if (h.length === 3) {
			h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
		}
		if (h.length !== 6) {
			return '28,75,66';
		}
		return [
			parseInt(h.slice(0, 2), 16),
			parseInt(h.slice(2, 4), 16),
			parseInt(h.slice(4, 6), 16),
		].join(',');
	}

	function normalizeHex(value) {
		var v = String(value || '').trim();
		if (!v) {
			return '';
		}
		if (v.charAt(0) !== '#') {
			v = '#' + v;
		}
		if (!/^#[0-9a-fA-F]{6}$/.test(v)) {
			return '';
		}
		return v.toLowerCase();
	}

	function contrastOnAccent(hex) {
		return isLightHex(hex) ? '#050f0c' : '#ffffff';
	}

	function brightenText(hex, percent) {
		var from = normalizeHex(hex);
		if (!from) {
			return hex;
		}
		var h = from.replace('#', '');
		var to = { r: 255, g: 255, b: 255 };
		var ratio = (percent || 30) / 100;
		var out = [];
		for (var i = 0; i < 3; i++) {
			var a = parseInt(h.slice(i * 2, i * 2 + 2), 16);
			var ch = ['r', 'g', 'b'][i];
			var val = Math.round(a + (to[ch] - a) * ratio);
			out.push(val.toString(16).padStart(2, '0'));
		}
		return '#' + out.join('');
	}

	function isLightHex(hex) {
		var h = String(hex || '').replace('#', '');
		if (h.length === 3) {
			h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
		}
		if (h.length !== 6) {
			return false;
		}
		var r = parseInt(h.slice(0, 2), 16);
		var g = parseInt(h.slice(2, 4), 16);
		var b = parseInt(h.slice(4, 6), 16);
		return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.55;
	}

	function normalizeBgPair(bg, bg2) {
		if (relativeLuminance(bg) > relativeLuminance(bg2)) {
			return { bg: bg2, bg2: bg };
		}
		return { bg: bg, bg2: bg2 };
	}

	function layoutSurfaceTokens(bg, bg2, text, accent, isLight) {
		if (isLight) {
			return {
				surface: bg,
				surface2: 'color-mix(in srgb, ' + bg2 + ' 94%, ' + text + ' 6%)',
				field: bg2,
				fieldHover: 'color-mix(in srgb, ' + bg2 + ' 90%, ' + accent + ' 10%)',
				noticeBg: bg,
			};
		}
		return {
			surface: bg,
			surface2: 'color-mix(in srgb, ' + bg + ' 93%, ' + text + ' 7%)',
			field: 'color-mix(in srgb, ' + bg + ' 90%, ' + bg2 + ' 10%)',
			fieldHover: 'color-mix(in srgb, ' + bg + ' 86%, ' + bg2 + ' 14%)',
			noticeBg: bg,
		};
	}

	function uiSemanticTokens(bg, bg2, text, isLight) {
		var markPct = isLight ? '78' : '80';
		var sepPct = isLight ? '82' : '72';
		return {
			'--colorify-admin-mark': 'color-mix(in srgb, ' + bg + ' ' + markPct + '%, #000)',
			'--colorify-admin-row-bg': bg,
			'--colorify-admin-row-active-bg': bg,
			'--colorify-admin-row-hover-bg':
				'color-mix(in srgb, ' + bg + ' ' + (isLight ? '96' : '94') + '%, ' + text + ')',
			'--colorify-admin-row-separator':
				'color-mix(in srgb, ' + bg + ' ' + sepPct + '%, #000)',
			'--colorify-admin-notice-bg': bg,
			'--colorify-admin-ui-hover-bg':
				'color-mix(in srgb, ' + bg + ' ' + (isLight ? '92' : '90') + '%, ' + text + ')',
			'--colorify-admin-ui-selected-bg':
				'color-mix(in srgb, ' + bg + ' 88%, ' + bg2 + ' 12%)',
		};
	}

	function buildWpThemeTokens(tokens) {
		var themeBg = (tokens && tokens['--colorify-admin-bg']) || '#1C4B42';
		var mark =
			(tokens && tokens['--colorify-admin-mark']) ||
			'color-mix(in srgb, ' + themeBg + ' 80%, #000)';
		return {
			'--wp-admin-theme-color': themeBg,
			'--wp-admin-theme-color--rgb': hexToRgbCsv(themeBg),
			'--wp-admin-theme-color-darker-10':
				'color-mix(in srgb, ' + themeBg + ' 90%, #000)',
			'--wp-admin-theme-color-darker-20': mark,
		};
	}

	function pushSchemeVarsToDom(tokens) {
		if (!tokens) {
			return;
		}
		var all = Object.assign({}, tokens, buildWpThemeTokens(tokens));
		var root = document.documentElement;
		Object.keys(all).forEach(function (key) {
			root.style.setProperty(key, all[key]);
		});

		var css = ':root{';
		Object.keys(all).forEach(function (key) {
			css += key + ':' + all[key] + ';';
		});
		css += '}';

		var styleEl = document.getElementById('colorify-scheme-vars');
		if (!styleEl) {
			styleEl = document.createElement('style');
			styleEl.id = 'colorify-scheme-vars';
			document.head.appendChild(styleEl);
		}
		styleEl.textContent = css;
	}

	function setAdminColorClass(key) {
		if (!document.body) {
			return;
		}
		Array.prototype.slice.call(document.body.classList).forEach(function (cls) {
			if (cls.indexOf('admin-color-') === 0) {
				document.body.classList.remove(cls);
			}
		});
		document.body.classList.add('admin-color-' + key);
	}

	function buildTokens(schemeKey, mode) {
		var scheme = schemes[schemeKey];
		if (!scheme) {
			return null;
		}

		var isLight = mode === 'light';
		var bg;
		var bg2;
		var accent;
		var accentSoftColor;
		var text;
		var muted;
		var dim;
		var border;

		var link;
		var linkHover;

		if (scheme.custom || schemeKey === customKey) {
			var tunedCustom = applyTuning(state.custom, mode);
			var normCustom = normalizeBgPair(tunedCustom.bg, tunedCustom.bg2);
			bg = normCustom.bg;
			bg2 = normCustom.bg2;
			if (isLight) {
				var tonedCustom = toneAccentForLight(
					tunedCustom.accent,
					tunedCustom.accent2,
					bg,
					bg2
				);
				accent = tonedCustom.accent;
				accentSoftColor = tonedCustom.soft;
			} else {
				accent = ensureVisibleOnDarkBg(tunedCustom.accent, bg2, 3.0);
				accentSoftColor = ensureVisibleOnDarkBg(
					brightenText(tunedCustom.accent2, 40),
					bg2,
					2.8
				);
			}
		} else {
			var tunedScheme = applyTuning(
				{
					bg: isLight ? (scheme.bgLight || '#fefefd') : (scheme.bgDark || '#050f0c'),
					bg2: isLight ? (scheme.bg2Light || '#f7f6f3') : (scheme.bg2Dark || '#0b231c'),
					accent: isLight ? (scheme.accentLight || scheme.accent) : scheme.accent,
					accentSoft: isLight
						? (scheme.accentSoftLight || scheme.accentSoft)
						: scheme.accentSoft,
				},
				mode
			);
			var normScheme = normalizeBgPair(tunedScheme.bg, tunedScheme.bg2);
			bg = normScheme.bg;
			bg2 = normScheme.bg2;
			if (isLight) {
				accent = tunedScheme.accent;
				accentSoftColor = tunedScheme.accentSoft;
			} else {
				accent = ensureVisibleOnDarkBg(tunedScheme.accent, bg2, 3.0);
				accentSoftColor = ensureVisibleOnDarkBg(
					brightenText(tunedScheme.accentSoft, 40),
					bg2,
					2.8
				);
			}
		}

		if (isLight) {
			text = '#1a3d35';
			muted = '#3d524c';
			dim = '#5c6b66';
			border = 'rgba(28, 75, 66, 0.09)';
			link = ensureContrastOnBg(accent, bg, 4.5);
			linkHover = interpolateHex(link, '#0a1f1a', 0.12);
		} else {
			text = brightenText('#f4f4f5', 35);
			muted = brightenText('#c8d4cf', 38);
			dim = brightenText('#8fa39b', 40);
			border = 'rgba(255, 255, 255, 0.12)';
			link = ensureVisibleOnDarkBg(brightenText(accent, 42), bg, 4.5);
			linkHover = ensureVisibleOnDarkBg(brightenText(accent, 55), bg, 4.5);
		}

		var layout = layoutSurfaceTokens(bg, bg2, text, accent, isLight);

		var base = {
			'--colorify-admin-bg': bg,
			'--colorify-admin-sidebar': bg2,
			'--colorify-admin-surface': layout.surface,
			'--colorify-admin-surface-2': layout.surface2,
			'--colorify-admin-field': layout.field,
			'--colorify-admin-field-hover': layout.fieldHover,
			'--colorify-admin-border': border,
			'--colorify-admin-border-subtle': isLight ? 'rgba(28, 75, 66, 0.06)' : 'rgba(255, 255, 255, 0.08)',
			'--colorify-admin-icon': isLight ? '#4a5f59' : brightenText('#8fa39b', 38),
			'--colorify-admin-text': text,
			'--colorify-admin-text-muted': muted,
			'--colorify-admin-text-dim': dim,
			'--colorify-admin-accent': accent,
			'--colorify-admin-accent-muted': accentSoftColor,
			'--colorify-admin-accent-soft': accentSoft(accent, mode),
			'--colorify-admin-link': link,
			'--colorify-admin-link-hover': linkHover,
			'--colorify-admin-text-bright': '#ffffff',
			'--colorify-admin-on-accent': contrastOnAccent(accent),
		};
		var semantic = uiSemanticTokens(bg, bg2, text, isLight);
		Object.keys(semantic).forEach(function (key) {
			base[key] = semantic[key];
		});
		return base;
	}

	function getCustomPreviewColors(mode) {
		var previewMode = mode === 'light' ? 'light' : mode === 'dark' ? 'dark' : state.mode;
		var tuned = applyTuning(state.custom, previewMode);
		if (previewMode === 'light') {
			var toned = toneAccentForLight(tuned.accent, tuned.accent2, tuned.bg, tuned.bg2);
			return [tuned.bg, tuned.bg2, toned.accent, toned.soft];
		}
		return [tuned.bg, tuned.bg2, tuned.accent, tuned.accent2];
	}

	function getTunedPreviewColors(schemeKey, paletteKey) {
		var scheme = schemes[schemeKey];
		if (!scheme) {
			return null;
		}
		var mode = paletteKey === 'light' ? 'light' : 'dark';
		if (scheme.custom || schemeKey === customKey) {
			return getCustomPreviewColors(mode);
		}
		var isLight = mode === 'light';
		var tuned = applyTuning(
			{
				bg: isLight ? (scheme.bgLight || '#fefefd') : (scheme.bgDark || '#050f0c'),
				bg2: isLight ? (scheme.bg2Light || '#f7f6f3') : (scheme.bg2Dark || '#0b231c'),
				accent: isLight ? (scheme.accentLight || scheme.accent) : scheme.accent,
				accentSoft: isLight
					? (scheme.accentSoftLight || scheme.accentSoft)
					: scheme.accentSoft,
			},
			mode
		);
		if (isLight) {
			var tonedLight = toneAccentForLight(
				tuned.accent,
				tuned.accentSoft,
				tuned.bg,
				tuned.bg2
			);
			return [tuned.bg, tuned.bg2, tonedLight.accent, tonedLight.soft];
		}
		return [
			tuned.bg,
			tuned.bg2,
			ensureVisibleOnDarkBg(tuned.accent, tuned.bg2, 3.0),
			ensureVisibleOnDarkBg(brightenText(tuned.accentSoft, 40), tuned.bg2, 2.8),
		];
	}

	function applyAppearance(schemeKey, mode) {
		var scheme = schemes[schemeKey];
		if (!scheme) {
			return false;
		}

		state.scheme = schemeKey;
		state.mode = mode === 'light' ? 'light' : 'dark';

		var tokens = buildTokens(schemeKey, state.mode);
		if (!tokens) {
			return;
		}

		pushSchemeVarsToDom(tokens);
		setAdminColorClass(schemeKey);

		if (document.body) {
			document.body.classList.toggle('colorify-admin-light', state.mode === 'light');
		}

		document.documentElement.style.colorScheme = state.mode === 'light' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-colorify-admin-mode', state.mode);

		syncModeSwitch();
		syncPickerPreviews();
		syncCustomPreview();
		syncHiddenField();
		syncCustomPaletteVisibility();
		return true;
	}

	function syncHiddenField() {
		var modeField = document.getElementById('colorify-admin-appearance-field');
		if (modeField) {
			modeField.value = state.mode;
		}

		var schemeField = document.getElementById('colorify-admin-color-field');
		if (schemeField) {
			schemeField.value = state.scheme;
		}
	}

	function syncModeSwitch() {
		document.querySelectorAll('.colorify-mode-switch__input').forEach(function (input) {
			input.checked = state.mode === 'light';
		});
	}

	function syncPickerPreviews() {
		var paletteKey = state.mode === 'light' ? 'light' : 'dark';
		document.querySelectorAll('#color-picker .color-option').forEach(function (option) {
			var radio = option.querySelector('input[name="admin_color"]');
			if (!radio) {
				return;
			}
			var colors;
			if (radio.value === state.scheme || radio.value === customKey) {
				colors = getTunedPreviewColors(radio.value, paletteKey);
			} else {
				colors = previews[radio.value] && previews[radio.value][paletteKey];
			}
			if (!colors) {
				return;
			}
			option.querySelectorAll('.color-palette-shade').forEach(function (shade, index) {
				if (colors[index]) {
					shade.style.backgroundColor = colors[index];
				}
			});
		});
	}

	function syncCustomPreview() {
		var swatches = document.querySelectorAll('.colorify-custom-palette__swatch');
		var colors = getCustomPreviewColors();
		swatches.forEach(function (swatch, index) {
			if (colors[index]) {
				swatch.style.backgroundColor = colors[index];
			}
		});
	}

	function syncCustomPaletteVisibility() {
		var panel = document.getElementById('colorify-custom-palette');
		var tuningCard = document.querySelector('.colorify-tuning-card');
		var customActive = state.scheme === customKey;
		if (panel) {
			panel.classList.toggle('is-active', customActive);
		}
		if (tuningCard) {
			tuningCard.classList.toggle('is-active', true);
		}
		var customRadio = document.querySelector('.colorify-custom-admin-color-radio');
		if (customRadio) {
			customRadio.checked = customActive;
		}
	}

	function syncTuningSummary() {
		var summary = document.getElementById('colorify-tuning-summary');
		if (!summary) {
			return;
		}
		var lines = ['dark', 'light'].map(function (mode) {
			var t = state.tuning[mode] || tuningDefaults[mode] || {};
			var label = mode === 'light' ? 'Jasny' : 'Ciemny';
			return (
				label +
				': tło ' +
				(t.bg_brightness || 0) +
				'/' +
				(t.bg_saturation || 0) +
				', akcent ' +
				(t.accent_brightness || 0) +
				'/' +
				(t.accent_saturation || 0)
			);
		});
		summary.innerHTML = lines.map(function (line) {
			return '<span>' + line + '</span>';
		}).join('');
	}

	function getTuningControl(mode, key, type) {
		return document.querySelector(
			'.colorify-tuning-modal__' +
				type +
				'[data-colorify-tuning-mode="' +
				mode +
				'"][data-colorify-tuning-key="' +
				key +
				'"]'
		);
	}

	function syncTuningControl(mode, key, value) {
		var clamped = clampTuningValue(value);
		var over = isTuningOverThreshold(clamped);
		var range = getTuningControl(mode, key, 'range');
		var number = getTuningControl(mode, key, 'number');

		if (range) {
			range.value = String(clamped);
			range.setAttribute('aria-valuenow', String(clamped));
			range.classList.toggle('is-over-threshold', over);
		}
		if (number) {
			number.value = String(clamped);
			number.classList.toggle('is-over-threshold', over);
		}
	}

	function syncTuningOutputs() {
		document.querySelectorAll('.colorify-tuning-modal__range').forEach(function (range) {
			syncTuningControl(
				range.getAttribute('data-colorify-tuning-mode'),
				range.getAttribute('data-colorify-tuning-key'),
				range.value
			);
		});
	}

	function refreshTuningPreview() {
		syncCustomPreview();
		syncPickerPreviews();
		applyAppearance(state.scheme, state.mode);
	}

	function updateTuning(mode, key, value) {
		if (!state.tuning[mode]) {
			state.tuning[mode] = Object.assign({}, tuningDefaults[mode]);
		}
		state.tuning[mode][key] = clampTuningValue(value);
		syncTuningControl(mode, key, state.tuning[mode][key]);
		syncTuningSummary();
		refreshTuningPreview();
	}

	function openTuningModal() {
		var modal = document.getElementById('colorify-tuning-modal');
		if (!modal) {
			return;
		}
		modal.hidden = false;
		modal.setAttribute('aria-hidden', 'false');
		document.body.classList.add('colorify-tuning-modal-open');
	}

	function closeTuningModal() {
		var modal = document.getElementById('colorify-tuning-modal');
		if (!modal) {
			return;
		}
		modal.hidden = true;
		modal.setAttribute('aria-hidden', 'true');
		document.body.classList.remove('colorify-tuning-modal-open');
	}

	function ensureTuningModalClosed() {
		closeTuningModal();
		document.querySelectorAll('.colorify-tuning-modal__backdrop').forEach(function (backdrop) {
			if (!backdrop.closest('#colorify-tuning-modal')) {
				backdrop.remove();
			}
		});
	}

	function bindTuningModal() {
		if (document.body && document.body.dataset.colorifyTuningBound === '1') {
			return;
		}

		var openBtn = document.getElementById('colorify-tuning-open');
		var modal = document.getElementById('colorify-tuning-modal');
		if (!modal) {
			return;
		}

		if (openBtn && openBtn.dataset.colorifyBound !== '1') {
			openBtn.dataset.colorifyBound = '1';
			openBtn.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				openTuningModal();
			});
		}

		if (modal.dataset.colorifyBound !== '1') {
			modal.dataset.colorifyBound = '1';

			modal.querySelectorAll('[data-colorify-tuning-close]').forEach(function (el) {
				el.addEventListener('click', closeTuningModal);
			});

			modal.querySelectorAll('.colorify-tuning-modal__tab').forEach(function (tab) {
				tab.addEventListener('click', function () {
					var target = tab.getAttribute('data-colorify-tuning-tab');
					modal.querySelectorAll('.colorify-tuning-modal__tab').forEach(function (btn) {
						var active = btn === tab;
						btn.classList.toggle('is-active', active);
						btn.setAttribute('aria-selected', active ? 'true' : 'false');
					});
					modal.querySelectorAll('.colorify-tuning-modal__panel').forEach(function (panel) {
						var active = panel.getAttribute('data-colorify-tuning-panel') === target;
						panel.classList.toggle('is-active', active);
						panel.hidden = !active;
					});
				});
			});

			modal.querySelectorAll('.colorify-tuning-modal__range').forEach(function (range) {
				range.addEventListener('input', function () {
					updateTuning(
						range.getAttribute('data-colorify-tuning-mode'),
						range.getAttribute('data-colorify-tuning-key'),
						range.value
					);
				});
			});

			modal.querySelectorAll('.colorify-tuning-modal__number').forEach(function (input) {
				input.addEventListener('input', function () {
					updateTuning(
						input.getAttribute('data-colorify-tuning-mode'),
						input.getAttribute('data-colorify-tuning-key'),
						input.value
					);
				});
				input.addEventListener('blur', function () {
					updateTuning(
						input.getAttribute('data-colorify-tuning-mode'),
						input.getAttribute('data-colorify-tuning-key'),
						input.value
					);
				});
			});

			var resetBtn = document.getElementById('colorify-tuning-reset');
			if (resetBtn) {
				resetBtn.addEventListener('click', function () {
					state.tuning = {
						dark: Object.assign({}, tuningDefaults.dark),
						light: Object.assign({}, tuningDefaults.light),
					};
					['dark', 'light'].forEach(function (mode) {
						Object.keys(state.tuning[mode]).forEach(function (key) {
							syncTuningControl(mode, key, state.tuning[mode][key]);
						});
					});
					syncTuningSummary();
					refreshTuningPreview();
				});
			}
		}

		if (document.body && document.body.dataset.colorifyTuningEscape !== '1') {
			document.body.dataset.colorifyTuningEscape = '1';
			document.addEventListener('keydown', function (event) {
				var activeModal = document.getElementById('colorify-tuning-modal');
				if (event.key === 'Escape' && activeModal && !activeModal.hidden) {
					closeTuningModal();
				}
			});
		}

		if (document.body) {
			document.body.dataset.colorifyTuningBound = '1';
		}

		ensureTuningModalClosed();
		syncTuningOutputs();
		syncTuningSummary();
	}

	function syncSelected(option) {
		document.querySelectorAll('#color-picker .color-option').forEach(function (el) {
			el.classList.remove('selected');
		});
		if (option) {
			option.classList.add('selected');
		}
	}

	function selectCustomScheme() {
		var radio =
			document.querySelector('.colorify-custom-admin-color-radio') ||
			document.querySelector(
				'#color-picker input[name="admin_color"][value="' + customKey + '"]'
			);
		if (!radio) {
			return;
		}
		radio.checked = true;
		document.querySelectorAll('input[name="admin_color"]').forEach(function (r) {
			if (r !== radio) {
				r.checked = false;
			}
		});
		applyAppearance(customKey, state.mode);
		var option = radio.closest('.color-option');
		if (option) {
			syncSelected(option);
		} else {
			syncSelected(null);
		}
	}

	function updateCustomColor(key, value) {
		var hex = normalizeHex(value);
		if (!hex || !state.custom.hasOwnProperty(key)) {
			return;
		}
		state.custom[key] = hex;

		var colorInput = document.querySelector(
			'.colorify-custom-palette__input[data-colorify-custom-key="' + key + '"]'
		);
		var hexInput = document.querySelector(
			'input[name="colorify_custom_colors[' + key + ']"]'
		);
		if (colorInput) {
			colorInput.value = hex;
		}
		if (hexInput) {
			hexInput.value = hex;
		}

		syncCustomPreview();
		syncPickerPreviews();
		if (state.scheme === customKey) {
			applyAppearance(customKey, state.mode);
		}
	}

	function getProfileForm() {
		if (cfg.isSettingsPage) {
			return document.getElementById('colorify-settings-form');
		}
		return document.getElementById('your-profile');
	}

	function setSaveStatus(message, isError) {
		var status = document.getElementById('colorify-appearance-save-status');
		if (!status) {
			return;
		}
		if (!message) {
			status.hidden = true;
			status.textContent = '';
			status.classList.remove('is-error');
			return;
		}
		status.hidden = false;
		status.textContent = message;
		status.classList.toggle('is-error', !!isError);
	}

	function syncFormBeforeSave() {
		var form = getProfileForm();
		if (!form) {
			return false;
		}

		var appearanceField = document.getElementById('colorify-admin-appearance-field');
		if (appearanceField) {
			appearanceField.value = state.mode;
		}

		var schemeRadio = form.querySelector(
			'input[name="admin_color"][value="' + state.scheme + '"]'
		);
		if (schemeRadio) {
			schemeRadio.checked = true;
		}

		var schemeField = document.getElementById('colorify-admin-color-field');
		if (schemeField) {
			schemeField.value = state.scheme;
		}

		Object.keys(state.custom).forEach(function (key) {
			var hexInput = form.querySelector('input[name="colorify_custom_colors[' + key + ']"]');
			if (hexInput) {
				hexInput.value = state.custom[key];
			}
		});

		document.querySelectorAll('.colorify-tuning-modal__range').forEach(function (range) {
			var mode = range.getAttribute('data-colorify-tuning-mode');
			var tuningKey = range.getAttribute('data-colorify-tuning-key');
			if (!mode || !tuningKey || !state.tuning[mode]) {
				return;
			}
			range.value = String(state.tuning[mode][tuningKey]);
			syncTuningControl(mode, tuningKey, state.tuning[mode][tuningKey]);
		});

		return true;
	}

	function saveAppearanceProfile() {
		if (cfg.canSaveMode === false) {
			setSaveStatus('Zapis dostępny tylko na własnym profilu.', true);
			return;
		}

		var form = getProfileForm();
		if (!form) {
			setSaveStatus('Nie znaleziono formularza profilu.', true);
			return;
		}

		if (!syncFormBeforeSave()) {
			setSaveStatus('Nie udało się zsynchronizować ustawień.', true);
			return;
		}

		var btn = document.getElementById('colorify-appearance-save');
		if (btn) {
			btn.disabled = true;
			btn.setAttribute('aria-busy', 'true');
		}
		setSaveStatus('Zapisywanie…');

		var submit = form.querySelector('#submit');
		if (submit) {
			submit.click();
			return;
		}

		form.submit();
	}

	function bindProfileScopeToggle() {
		var wrap = document.getElementById('colorify-profile-scope-bar');
		if (!wrap || wrap.dataset.colorifyBound === '1') {
			return;
		}
		wrap.dataset.colorifyBound = '1';

		function syncScopeOptions() {
			wrap.querySelectorAll('.colorify-scope-toggle__option').forEach(function (option) {
				var radio = option.querySelector('input[type="radio"]');
				option.classList.toggle('is-active', !!(radio && radio.checked));
			});
		}

		wrap.querySelectorAll('.colorify-scope-toggle__option').forEach(function (option) {
			option.addEventListener('click', function () {
				var radio = option.querySelector('input[type="radio"]');
				if (!radio) {
					return;
				}
				radio.checked = true;
				syncScopeOptions();
			});
		});

		wrap.querySelectorAll('input[name="colorify_settings_scope"]').forEach(function (input) {
			input.addEventListener('change', syncScopeOptions);
		});
	}

	function bindSaveButton() {
		var btn = document.getElementById('colorify-appearance-save');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', saveAppearanceProfile);
	}

	function bindProfileFormSubmit() {
		var form = document.getElementById('your-profile');
		if (!form || form.dataset.colorifySubmitBound === '1') {
			return;
		}
		form.dataset.colorifySubmitBound = '1';
		form.addEventListener('submit', function () {
			syncFormBeforeSave();
		});
	}

	function saveMode(mode) {
		if (cfg.isSettingsPage) {
			return;
		}
		if (cfg.canSaveMode === false || !cfg.ajaxUrl || !cfg.nonce) {
			return;
		}
		var body = new URLSearchParams();
		body.set('action', 'colorify_save_admin_appearance');
		body.set('nonce', cfg.nonce);
		body.set('mode', mode);
		fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		}).catch(function () {
			/* ignore */
		});
	}

	function bindModeSwitch() {
		if (document.body && document.body.dataset.colorifyModeBound === '1') {
			return;
		}
		if (document.body) {
			document.body.dataset.colorifyModeBound = '1';
		}
		document.addEventListener('change', function (event) {
			var input = event.target;
			if (!input.classList || !input.classList.contains('colorify-mode-switch__input')) {
				return;
			}
			var mode = input.checked ? 'light' : 'dark';
			applyAppearance(state.scheme, mode);
			saveMode(mode);
		});
	}

	function pickSchemeFromOption(option) {
		if (!option || option.id === 'colorify-custom-palette') {
			return;
		}
		var radio = option.querySelector('input[name="admin_color"]');
		if (!radio) {
			return;
		}
		if (!radio.checked) {
			radio.checked = true;
		}
		applyAppearance(radio.value, state.mode);
		syncSelected(option);
	}

	function scheduleSchemePreview(option) {
		if (!option || option.id === 'colorify-custom-palette') {
			return;
		}
		window.setTimeout(function () {
			pickSchemeFromOption(option);
		}, 0);
	}

	function bindColorPicker() {
		var picker = document.getElementById('color-picker');
		if (!picker) {
			return;
		}

		if (picker.dataset.colorifyBound !== '1') {
			picker.dataset.colorifyBound = '1';

			picker.addEventListener('click', function (event) {
				scheduleSchemePreview(event.target.closest('.color-option'));
			});

			picker.querySelectorAll('input[name="admin_color"]').forEach(function (input) {
				input.addEventListener('change', function () {
					if (!input.checked) {
						return;
					}
					pickSchemeFromOption(input.closest('.color-option'));
				});
			});
		}

		// Po handlerze WP user-profile.js (ten sam plik ładuje się po user-profile).
		if (window.jQuery && picker.dataset.colorifyJqBound !== '1') {
			picker.dataset.colorifyJqBound = '1';
			window.jQuery(picker).on('click.colorifyPreview', '.color-option', function () {
				scheduleSchemePreview(this);
			});
		}
	}

	function bindAdminColorChangeFallback() {
		if (document.body && document.body.dataset.colorifyAdminColorBound === '1') {
			return;
		}
		if (document.body) {
			document.body.dataset.colorifyAdminColorBound = '1';
		}
		document.addEventListener('change', function (event) {
			var input = event.target;
			if (!input || input.name !== 'admin_color' || !input.checked) {
				return;
			}
			var option = input.closest('.color-option');
			if (option) {
				pickSchemeFromOption(option);
				return;
			}
			applyAppearance(input.value, state.mode);
			syncCustomPaletteVisibility();
		});
	}

	function bindSettingsSchemeSelect() {
		var select = document.getElementById('colorify-global-admin-color');
		if (!select || select.dataset.colorifyBound === '1') {
			return;
		}
		select.dataset.colorifyBound = '1';
		select.addEventListener('change', function () {
			applyAppearance(select.value, state.mode);
			syncCustomPaletteVisibility();
		});
	}

	function bindCustomPalette() {
		var panel = document.getElementById('colorify-custom-palette');
		if (!panel || panel.dataset.colorifyBound === '1') {
			return;
		}
		panel.dataset.colorifyBound = '1';

		panel.addEventListener('click', function (event) {
			if (
				event.target.closest('.colorify-custom-palette__input') ||
				event.target.closest('.colorify-custom-palette__hex')
			) {
				return;
			}
			selectCustomScheme();
		});

		panel.querySelectorAll('.colorify-custom-palette__input').forEach(function (input) {
			input.addEventListener('input', function () {
				updateCustomColor(input.getAttribute('data-colorify-custom-key'), input.value);
			});
			input.addEventListener('change', function () {
				selectCustomScheme();
			});
		});

		panel.querySelectorAll('.colorify-custom-palette__hex').forEach(function (input) {
			input.addEventListener('change', function () {
				var key = input.name.replace('colorify_custom_colors[', '').replace(']', '');
				updateCustomColor(key, input.value);
				selectCustomScheme();
			});
			input.addEventListener('blur', function () {
				var key = input.name.replace('colorify_custom_colors[', '').replace(']', '');
				updateCustomColor(key, input.value);
			});
		});

		syncCustomPreview();
		syncCustomPaletteVisibility();
	}

	function reorderColorPicker() {
		var picker = document.getElementById('color-picker');
		if (!picker || !schemeOrder.length) {
			return;
		}

		var byKey = {};
		picker.querySelectorAll('.color-option').forEach(function (option) {
			var radio = option.querySelector('input[name="admin_color"]');
			if (!radio || !radio.value) {
				return;
			}
			byKey[radio.value] = option;
		});

		schemeOrder.forEach(function (key) {
			if (byKey[key]) {
				picker.appendChild(byKey[key]);
			}
		});

		Object.keys(byKey).forEach(function (key) {
			if (schemeOrder.indexOf(key) === -1) {
				picker.appendChild(byKey[key]);
			}
		});
	}

	function relocateProfileModeBar() {
		// Pasek trybu renderuje PHP (hook admin_color_scheme_picker, priorytet 4) nad siatką
		// schematów. Przenoszenie do <tr> mogło odłączać węzeł od DOM — zostawiamy na miejscu.
	}

	function relocateTuningModal() {
		var modals = document.querySelectorAll('#colorify-tuning-modal');
		if (!modals.length || !document.body) {
			return;
		}
		var modal = modals[0];
		for (var i = 1; i < modals.length; i++) {
			modals[i].remove();
		}
		if (modal.parentNode !== document.body) {
			document.body.appendChild(modal);
		}
		ensureTuningModalClosed();
	}

	function relocateCustomPalette() {
		relocateTuningModal();

		if (cfg.isSettingsPage) {
			var settingsMount = document.getElementById('colorify-custom-palette-mount');
			if (settingsMount) {
				settingsMount.removeAttribute('hidden');
			}
			return;
		}

		if (document.querySelector('.user-admin-color-wrap .colorify-custom-section')) {
			return;
		}

		var picker = document.getElementById('color-picker');
		var mount = document.getElementById('colorify-custom-palette-mount');
		if (!picker || !mount) {
			return;
		}
		var parent = picker.parentNode;
		if (!parent) {
			return;
		}
		while (mount.firstChild) {
			parent.insertBefore(mount.firstChild, picker.nextSibling);
		}
		mount.remove();
	}

	var initialized = false;

	function hasAppearanceUi() {
		return !!(
			document.getElementById('color-picker') ||
			document.getElementById('colorify-global-admin-color') ||
			document.getElementById('colorify-settings-form')
		);
	}

	function safeDomTask(fn) {
		try {
			fn();
		} catch (err) {
			/* Nie przerywaj init — błędy DOM nie mogą blokować podglądu i przycisków. */
		}
	}

	function init() {
		safeDomTask(reorderColorPicker);
		safeDomTask(relocateCustomPalette);
		safeDomTask(relocateProfileModeBar);
		safeDomTask(relocateTuningModal);
		safeDomTask(ensureTuningModalClosed);

		bindModeSwitch();
		bindColorPicker();
		bindAdminColorChangeFallback();
		bindSettingsSchemeSelect();
		bindCustomPalette();
		bindTuningModal();
		bindSaveButton();
		bindProfileFormSubmit();
		bindProfileScopeToggle();

		if (!initialized) {
			var scheme = schemes[state.scheme] ? state.scheme : 'colorify-lime';
			state.scheme = scheme;
			applyAppearance(scheme, state.mode);

			var picker = document.getElementById('color-picker');
			if (picker) {
				var checkedRadio = picker.querySelector('input[name="admin_color"]:checked');
				if (checkedRadio) {
					syncSelected(checkedRadio.closest('.color-option'));
				}
			}
			initialized = true;
		}
	}

	function boot() {
		if (!hasAppearanceUi()) {
			return;
		}
		init();
	}

	function runBoot() {
		if (window.jQuery) {
			window.jQuery(boot);
		} else if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', boot);
		} else {
			boot();
		}
	}

	runBoot();
	window.addEventListener('load', function () {
		boot();
		relocateTuningModal();
		ensureTuningModalClosed();
	});
})();
