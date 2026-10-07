// Builds the two colour-scheme stylesheets of Colorify from WordPress core's own
// scheme template (wp-admin/css/colors/_admin.scss).
//
//   node build-scheme-css.mjs            write assets/colorify-chrome.css and assets/colorify-scheme.css
//   node build-scheme-css.mjs --verify   prove the toolchain reproduces core's "modern" scheme, write nothing
//
// Why: WordPress colours its admin through this template. Compiling the very same
// template with CSS custom properties instead of fixed colours gives a stylesheet
// with core's selectors and specificity, so a Colorify scheme behaves exactly like
// a built-in one. Token values come from PHP (includes/colorify-tokens.php).
//
// Output is split in two because of dark mode:
//   colorify-chrome.css  admin menu, admin bar, page canvas. Exact palette colours.
//                        Dark Reader is told to leave this file alone.
//   colorify-scheme.css  accents inside the content area (links, buttons, pointers,
//                        media, themes, customizer). Dark Reader recolours these
//                        together with the rest of the page.
//
// Updating to a new WordPress version: copy the four partials from
// wp-admin/css/colors/ into tools/wp-core-colors/<version>/, point CORE at it and
// run --verify. Every patch below asserts that it still matches.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import * as sass from 'sass';
import postcss from 'postcss';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const CORE_VERSION = '7.1';
const CORE = path.join(HERE, 'wp-core-colors', CORE_VERSION);
const BUILD = path.join(HERE, '.build');
const VERIFY = process.argv.includes('--verify');

// Sass variable -> value handed to the template.
const THEME = 'var(--wp-admin-theme-color)';
const VARS = {
	'text-color': 'var(--colorify-text-color)',
	'base-color': 'var(--colorify-base-color)',
	'icon-color': 'var(--colorify-icon-color)',
	'highlight-color': THEME,
	'notification-color': 'var(--colorify-menu-bubble-background)',
	'body-background': 'var(--colorify-body-background)',
	'link': THEME,
	'link-focus': 'var(--wp-admin-theme-color-darker-20)',
	'button-color': THEME,
	'menu-text': 'var(--colorify-menu-text)',
	'menu-icon': 'var(--colorify-menu-icon)',
	'menu-background': 'var(--colorify-menu-background)',
	'menu-highlight-text': 'var(--colorify-menu-highlight-text)',
	'menu-highlight-icon': 'var(--colorify-menu-highlight-icon)',
	'menu-highlight-background': 'var(--colorify-menu-highlight-background)',
	'menu-current-text': 'var(--colorify-menu-current-text)',
	'menu-current-icon': 'var(--colorify-menu-current-icon)',
	'menu-current-background': 'var(--colorify-menu-current-background)',
	'menu-submenu-text': 'var(--colorify-menu-submenu-text)',
	'menu-submenu-background': 'var(--colorify-menu-submenu-background)',
	'menu-submenu-background-alt': 'var(--colorify-menu-submenu-background-alt)',
	'menu-submenu-focus-text': 'var(--colorify-menu-submenu-focus-text)',
	'menu-submenu-current-text': 'var(--colorify-menu-submenu-current-text)',
	'menu-bubble-text': 'var(--colorify-menu-bubble-text)',
	'menu-bubble-background': 'var(--colorify-menu-bubble-background)',
	'menu-bubble-current-text': 'var(--colorify-menu-bubble-current-text)',
	'menu-bubble-current-background': 'var(--colorify-menu-bubble-current-background)',
	'menu-collapse-text': 'var(--colorify-menu-collapse-text)',
	'menu-collapse-icon': 'var(--colorify-menu-collapse-icon)',
	'menu-collapse-focus-text': 'var(--colorify-menu-collapse-focus-text)',
	'menu-collapse-focus-icon': 'var(--colorify-menu-collapse-focus-icon)',
	'adminbar-avatar-frame': 'var(--colorify-adminbar-avatar-frame)',
	'adminbar-input-background': 'var(--colorify-adminbar-input-background)',
	'adminbar-recovery-exit-text': 'var(--colorify-adminbar-recovery-exit-text)',
	'adminbar-recovery-exit-background': 'var(--colorify-adminbar-recovery-exit-background)',
	'adminbar-recovery-exit-background-alt': 'var(--colorify-adminbar-recovery-exit-background-alt)',
	'menu-customizer-text': 'var(--colorify-menu-submenu-text)',
	'dashboard-accent-1': THEME,
	'dashboard-accent-2': THEME,
	'dashboard-icon-background': THEME,
};

// Edits to core's template. Each one must match exactly `count` times, otherwise
// the build stops: a changed core file needs a human look.
//
// 1-2: Sass colour maths cannot run on var(); replaced by ready-made tokens.
// 3-8: places where core paints content-area text or borders with a menu colour.
//      That is safe for core's own dark-menu schemes but unreadable as soon as the
//      menu is light or the accent is bright, so they get content colours instead.
const DARK_TEXT = '#1e1e1e'; // core $gray-900, the primary text colour of the content area
const PATCHES = [
	{ count: 1, find: 'color.adjust(variables.$highlight-color, $lightness: -5%)', put: 'var(--wp-admin-theme-color-darker-10)' },
	{ count: 3, find: 'color.adjust(variables.$button-color, $lightness: 10%)', put: 'var(--colorify-theme-lighter)' },
	{ count: 1, find: '.wp-ui-text-primary {\n\t\tcolor: variables.$base-color;', put: `.wp-ui-text-primary {\n\t\tcolor: ${DARK_TEXT};` },
	{ count: 1, find: '.wp-ui-text-highlight {\n\t\tcolor: variables.$menu-highlight-background;', put: `.wp-ui-text-highlight {\n\t\tcolor: ${THEME};` },
	{ count: 1, find: '.wp-ui-text-notification {\n\t\tcolor: variables.$menu-bubble-background;', put: `.wp-ui-text-notification {\n\t\tcolor: ${THEME};` },
	{ count: 1, find: '.view-switch a.current:before {\n\tcolor: variables.$menu-background;', put: `.view-switch a.current:before {\n\tcolor: ${DARK_TEXT};` },
	{ count: 1, find: '.view-switch a:hover:before {\n\tcolor: variables.$menu-bubble-background;', put: `.view-switch a:hover:before {\n\tcolor: ${THEME};` },
	{ count: 1, find: '.theme-section.current,\n.theme-filter.current {\n\tborder-bottom-color: variables.$menu-background;', put: `.theme-section.current,\n.theme-filter.current {\n\tborder-bottom-color: ${DARK_TEXT};` },
];

// Rules that core does not have and a non-default menu colour needs.
const EXTRA_CHROME = `
/* Colorify: core sets html and body to height 100%, so below the first screen the html background shows. */
html {
  background: var(--colorify-body-background);
}
/* Colorify: the mobile menu toggle icon sits on the highlight colour when the menu is open. */
.wp-responsive-open div#wp-responsive-toggle a:before {
  color: var(--colorify-menu-highlight-icon);
}
`;

// A selector belongs to the chrome file when it styles the admin menu, the admin
// bar or the page canvas.
const CHROME_RE = /#adminmenu|#adminmenuback|#adminmenuwrap|#wpadminbar|#collapse-button|#wp-responsive-toggle|\.wp-responsive-open|\.nav-tab-active/;
const isChrome = (selector) => CHROME_RE.test(selector) || selector.trim() === 'body';

function prepare(vars, patched, prelude = '') {
	fs.mkdirSync(BUILD, { recursive: true });
	for (const f of ['_mixins.scss', '_tokens.scss', '_variables.scss']) {
		fs.copyFileSync(path.join(CORE, f), path.join(BUILD, f));
	}
	let admin = fs.readFileSync(path.join(CORE, '_admin.scss'), 'utf8').replace(/\r\n/g, '\n');
	if (patched) {
		for (const p of PATCHES) {
			const n = admin.split(p.find).length - 1;
			if (n !== p.count) {
				throw new Error(`patch expected ${p.count} match(es), found ${n}: ${p.find.slice(0, 70)}`);
			}
			admin = admin.split(p.find).join(p.put);
		}
		if (admin.includes('color.adjust(variables.')) {
			throw new Error('core template has colour maths on a scheme variable that no patch covers');
		}
	}
	fs.writeFileSync(path.join(BUILD, '_admin.scss'), admin);
	const lines = [prelude + '@use "variables" with ('];
	for (const [k, v] of Object.entries(vars)) lines.push(`\t$${k}: ${v},`);
	lines.push(');', '@use "admin";', '');
	const entry = path.join(BUILD, 'entry.scss');
	fs.writeFileSync(entry, lines.join('\n'));
	return sass.compile(entry, { style: 'expanded', loadPaths: [BUILD] }).css;
}

const selectorsOf = (css) => {
	const set = new Set();
	postcss.parse(css).walkRules((r) => r.selectors.forEach((s) => set.add(s.replace(/\s+/g, ' ').trim())));
	return set;
};

// Colours can be serialised differently by different Sass versions; compare by value.
const normaliseColours = (css) =>
	css
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/rgb\(\s*([\d.]+%?)[,\s]+([\d.]+%?)[,\s]+([\d.]+%?)\s*\)/g, (m, r, g, b) => {
			const ch = (v) => Math.round(v.endsWith('%') ? (parseFloat(v) * 255) / 100 : parseFloat(v));
			return `rgb(${ch(r)},${ch(g)},${ch(b)})`;
		})
		.replace(/\s+/g, ' ')
		.trim();

if (VERIFY) {
	// 1. Unpatched template + core's "modern" values must reproduce core's shipped modern/colors.css.
	const modern = prepare(
		{
			'scheme-name': '"modern"',
			'base-color': '#1e1e1e',
			'highlight-color': '#3858e9',
			'menu-submenu-focus-text': '#7b90ff',
			'notification-color': '#3858e9',
			'link': '#3858e9',
			'link-focus': 'color.adjust(#3858e9, $lightness: -10%)',
			'custom-welcome-panel': '"false"',
		},
		false,
		'@use "sass:color";\n'
	);
	const compiled = modern;
	const reference = fs.readFileSync(path.join(CORE, 'modern-colors.reference.css'), 'utf8');
	// Compare text exactly and colour channels with a tolerance of 1 (rounding of x.5 differs between Sass versions).
	const split = (css) => {
		const nums = [];
		const text = normaliseColours(css).replace(/rgb\((\d+),(\d+),(\d+)\)/g, (m, r, g, bl) => {
			nums.push([+r, +g, +bl]);
			return 'rgb(#)';
		});
		return { text, nums };
	};
	const a = split(compiled);
	const b = split(reference);
	if (a.text !== b.text) {
		let i = 0;
		while (i < a.text.length && a.text[i] === b.text[i]) i++;
		console.error('MISMATCH with core modern/colors.css at char ' + i);
		console.error('  ours: ' + a.text.slice(Math.max(0, i - 80), i + 120));
		console.error('  core: ' + b.text.slice(Math.max(0, i - 80), i + 120));
		process.exit(1);
	}
	const off = a.nums.findIndex((c, i) => c.some((v, k) => Math.abs(v - b.nums[i][k]) > 1));
	if (off !== -1) {
		console.error('COLOUR MISMATCH #' + off + ': ours rgb(' + a.nums[off] + ') core rgb(' + b.nums[off] + ')');
		process.exit(1);
	}
	console.log('verify 1/2 ok: unpatched template reproduces core modern/colors.css (' + selectorsOf(reference).size + ' selectors)');

	// 2. The patched, variable-driven build must keep every core selector.
	const ours = selectorsOf(prepare(VARS, true));
	const missing = [...selectorsOf(reference)].filter((s) => !ours.has(s));
	if (missing.length) {
		console.error('selectors lost by the patched build:\n  ' + missing.join('\n  '));
		process.exit(1);
	}
	console.log('verify 2/2 ok: patched build keeps all core selectors');
	process.exit(0);
}

const css = prepare(VARS, true);
const root = postcss.parse(css);
const chrome = postcss.root();
const scheme = postcss.root();

root.each((node) => {
	if (node.type === 'comment') return; // section comments would end up in the wrong file
	if (node.type !== 'rule') {
		scheme.append(node.clone());
		return;
	}
	const inChrome = node.selectors.filter(isChrome);
	const inScheme = node.selectors.filter((s) => !isChrome(s));
	if (inChrome.length) chrome.append(node.clone({ selectors: inChrome }));
	if (inScheme.length) scheme.append(node.clone({ selectors: inScheme }));
});

const literal = (rootNode) => {
	// Anything that is not a token or one of core's own neutral literals would be a leak of a scheme colour.
	const found = new Set();
	rootNode.walkDecls((d) => {
		for (const m of d.value.matchAll(/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/gi)) {
			if (!/var\(/.test(m[0])) found.add(m[0].toLowerCase());
		}
	});
	return [...found];
};

const banner = (title) =>
	`/*! Colorify by INYFINN - ${title}\n` +
	` * Generated by tools/build-scheme-css.mjs from WordPress ${CORE_VERSION} wp-admin/css/colors/_admin.scss (GPL-2.0-or-later).\n` +
	` * Do not edit: change the tool or the tokens (includes/colorify-tokens.php) and rebuild. */\n`;

const out = (file, title, rootNode, extra = '') => {
	const text = banner(title) + rootNode.toString().replace(/\r\n/g, '\n').trim() + '\n' + extra;
	fs.writeFileSync(path.join(ROOT, 'assets', file), text);
	let rules = 0;
	postcss.parse(text).walkRules(() => rules++);
	console.log(`${file}: ${rules} rules, ${text.length} bytes, literal colours: ${literal(rootNode).join(' ') || 'none'}`);
};

out('colorify-chrome.css', 'admin menu, admin bar and page canvas', chrome, EXTRA_CHROME);
out('colorify-scheme.css', 'accents inside the content area', scheme);
