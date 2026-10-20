// Buduje dist/colorify-by-inyfinn.zip z plikow sledzonych przez git.
//
//   node tools/build-zip.mjs <wersja> [--root <katalog repo>] [--out <plik zip>] [--worktree] [--legacy-ref <ref|none>]
//
// --worktree    bierze tez pliki jeszcze nieśledzone (bez ignorowanych); tylko do prob w laboratorium,
//               wydanie buduje sie z czystego, zatwierdzonego drzewa (tools/release.sh to wymusza).
// --legacy-ref  tagi (po przecinku), ktorych pliki musza byc w paczce albo na liscie ORPHANS updatera
//               (domyslnie wszystkie tagi v1.*; 'none' pomija sprawdzenie).
//
// Zasady: katalog glowny colorify-by-inyfinn/, zwykle ukosniki, konce linii LF w plikach tekstowych,
// bez site-plugins/ (osobne paczki), tools/, docs/, .ralph/, .git*, dist/, node_modules. Bez zaleznosci npm (zlib + wlasny zapis ZIP).
// Przed zapisem sprawdza wersje (naglowek i stala = argument), pliki-kotwice i brak "\" w nazwach.
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { deflateRawSync } from 'node:zlib';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const SLUG = 'colorify-by-inyfinn';
const ANCHORS = ['colorify-by-inyfinn.php', 'includes/colorify-scope.php', 'includes/colorify-toolbar-actions.php'];
const REQUIRED = [...ANCHORS, 'includes/class-colorify-github-updater.php', 'assets/vendor/darkreader.js'];
const EXCLUDE = [/^site-plugins\//, /^tools\//, /^docs\//, /^\.ralph\//, /^dist\//, /^node_modules\//, /(^|\/)\.git[^/]*$/];
const TEXT_EXT = /\.(php|css|js|mjs|json|md|po|pot|txt|svg|html|sh)$/i;

function fail(msg) {
	console.error('BLAD: ' + msg);
	process.exit(1);
}

const args = process.argv.slice(2);
const opt = (name) => {
	const i = args.indexOf('--' + name);
	return i === -1 ? null : args[i + 1];
};
const flag = (name) => args.includes('--' + name);
const version = args.find((a, i) => !a.startsWith('--') && !(i > 0 && args[i - 1].startsWith('--')));
if (!version || !/^\d+\.\d+\.\d+([-.][0-9A-Za-z.]+)?$/.test(version)) {
	fail('uzycie: node tools/build-zip.mjs <wersja, np. 1.5.0> [--root <katalog>] [--out <plik>]');
}

const root = resolve(opt('root') || join(dirname(fileURLToPath(import.meta.url)), '..'));
const out = resolve(opt('out') || join(root, 'dist', SLUG + '.zip'));

const git = (...a) => execFileSync('git', ['-C', root, ...a], { encoding: 'buffer', maxBuffer: 1 << 28, stdio: ['ignore', 'pipe', 'pipe'] });
const tracked = git('ls-files', '-z', ...(flag('worktree') ? ['--cached', '--others', '--exclude-standard'] : [])).toString('utf8').split('\0').filter(Boolean);
const files = tracked.filter((p) => !EXCLUDE.some((re) => re.test(p))).sort();

const bad = files.filter((p) => p.includes(String.fromCharCode(92)));
if (bad.length) fail('ukosnik wsteczny w nazwie pliku: ' + bad.join(', '));
for (const a of REQUIRED) if (!files.includes(a)) fail('brak wymaganego pliku w paczce: ' + a);

// Lista ORPHANS updatera (pliki kasowane po aktualizacji ze starej wersji): nie moze zawierac pliku z paczki,
// a kazdy plik z tagow v1.* spoza paczki musi na niej byc, inaczej zostalby na dysku po starej sciezce.
const updaterSrc = readFileSync(join(root, 'includes/class-colorify-github-updater.php'), 'utf8');
const orphanBlock = /const ORPHANS = array\(([\s\S]*?)\);/.exec(updaterSrc);
if (!orphanBlock) fail('nie znaleziono const ORPHANS w includes/class-colorify-github-updater.php');
const orphans = [...orphanBlock[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
for (const o of orphans) if (files.includes(o)) fail('plik z paczki jest na liscie ORPHANS (zostalby skasowany): ' + o);
const legacySizes = {}; // includes/*.php z tagow v1.*: rozmiary (manifest kompletnosci musi je odroznic od nowych plikow)
const legacyOpt = opt('legacy-ref');
if (legacyOpt !== 'none') {
	let refs = legacyOpt ? legacyOpt.split(',') : git('tag', '-l', 'v1.*').toString('utf8').split('\n').filter(Boolean);
	if (!refs.length) fail('brak tagow v1.* w repo (git fetch --tags albo --legacy-ref none)');
	const lost = new Set();
	for (const ref of refs) {
		let legacy;
		try {
			legacy = git('ls-tree', '-r', '-l', '-z', ref).toString('utf8').split('\0').filter(Boolean).map((l) => ({ size: parseInt(l.split('\t')[0].trim().split(/\s+/)[3], 10), p: l.split('\t')[1] }));
		} catch {
			fail(`nie ma refa ${ref} w repo (git fetch --tags albo --legacy-ref none)`);
		}
		for (const { p, size } of legacy) {
			if (!EXCLUDE.some((re) => re.test(p)) && !/^scripts\//.test(p) && !files.includes(p) && !orphans.includes(p)) lost.add(`${p} (${ref})`);
			if (/^includes\/[^/]+\.php$/.test(p)) (legacySizes[p] = legacySizes[p] || new Set()).add(size);
		}
	}
	if (lost.size) fail('pliki starszych wydan spoza paczki i spoza ORPHANS: ' + [...lost].join(', '));
	console.log(`ORPHANS: ${orphans.length} wpisow, zgodne z ${refs.length} tagami (${refs[0]} ... ${refs[refs.length - 1]})`);
}

// Zawartosc: LF w plikach tekstowych.
const entries = files.map((p) => {
	let data = readFileSync(join(root, p));
	if (TEXT_EXT.test(p)) data = Buffer.from(data.toString('utf8').replace(/\r\n/g, '\n'), 'utf8');
	return { name: SLUG + '/' + p, rel: p, data };
});

// Wersja: naglowek i stala musza byc rowne argumentowi.
const main = entries.find((e) => e.rel === 'colorify-by-inyfinn.php').data.toString('utf8');
const header = /^\s*\*\s*Version:\s*(.+?)\s*$/m.exec(main);
const constant = /define\(\s*'COLORIFY_PLUGIN_VERSION'\s*,\s*'([^']+)'\s*\)/.exec(main);
if (!header || header[1] !== version) fail(`naglowek Version (${header && header[1]}) != ${version}`);
if (!constant || constant[1] !== version) fail(`stala COLORIFY_PLUGIN_VERSION (${constant && constant[1]}) != ${version}`);

// Manifest kompletnosci: rozmiary includes/*.php wpisane do pliku glownego (patrz komentarz w colorify-by-inyfinn.php).
// Plik glowny w repo ma pusty wiersz-znacznik; tylko paczka dostaje wypelniony.
const PLACEHOLDER = "$colorify_manifest = '';";
const mainEntry = entries.find((e) => e.rel === 'colorify-by-inyfinn.php');
if (!main.includes(PLACEHOLDER)) fail('w pliku glownym brak wiersza ' + PLACEHOLDER);
const manifest = entries
	.filter((e) => /^includes\/[^/]+\.php$/.test(e.rel))
	.map((e) => `${e.rel}:${e.data.length}`)
	.join(',');
mainEntry.data = Buffer.from(main.replace(PLACEHOLDER, () => `$colorify_manifest = '${manifest}';`), 'utf8');
for (const e of entries) {
	if (/^includes\/[^/]+\.php$/.test(e.rel) && legacySizes[e.rel] && legacySizes[e.rel].has(e.data.length)) fail(`rozmiar ${e.rel} (${e.data.length}) rowny rozmiarowi z wydania 1.x: manifest nie odroznilby starego pliku od nowego`);
}
console.log(`Manifest kompletnosci: ${manifest.split(',').length} plikow includes/*.php`);

// Czas wpisow: czas HEAD (powtarzalny hash); bez commitow: stala data.
let stamp = new Date('2026-01-01T00:00:00Z');
try {
	const ct = parseInt(git('log', '-1', '--format=%ct').toString().trim(), 10);
	if (ct > 0) stamp = new Date(ct * 1000);
} catch {
	/* repo bez commitow */
}
const dosTime = (stamp.getUTCHours() << 11) | (stamp.getUTCMinutes() << 5) | (stamp.getUTCSeconds() >> 1);
const dosDate = ((stamp.getUTCFullYear() - 1980) << 9) | ((stamp.getUTCMonth() + 1) << 5) | stamp.getUTCDate();

const crcTable = new Uint32Array(256).map((_, n) => {
	let c = n;
	for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
	return c >>> 0;
});
const crc32 = (buf) => {
	let c = 0xffffffff;
	for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8);
	return (c ^ 0xffffffff) >>> 0;
};

const chunks = [];
const central = [];
let offset = 0;
for (const e of entries) {
	const name = Buffer.from(e.name, 'utf8');
	const packed = deflateRawSync(e.data, { level: 9 });
	const crc = crc32(e.data);
	const local = Buffer.alloc(30);
	local.writeUInt32LE(0x04034b50, 0);
	local.writeUInt16LE(20, 4); // wymagana wersja
	local.writeUInt16LE(0x0800, 6); // nazwy UTF-8
	local.writeUInt16LE(8, 8); // deflate
	local.writeUInt16LE(dosTime, 10);
	local.writeUInt16LE(dosDate, 12);
	local.writeUInt32LE(crc, 14);
	local.writeUInt32LE(packed.length, 18);
	local.writeUInt32LE(e.data.length, 22);
	local.writeUInt16LE(name.length, 26);
	chunks.push(local, name, packed);

	const c = Buffer.alloc(46);
	c.writeUInt32LE(0x02014b50, 0);
	c.writeUInt16LE((3 << 8) | 20, 4); // utworzone w systemie Unix
	c.writeUInt16LE(20, 6);
	c.writeUInt16LE(0x0800, 8);
	c.writeUInt16LE(8, 10);
	c.writeUInt16LE(dosTime, 12);
	c.writeUInt16LE(dosDate, 14);
	c.writeUInt32LE(crc, 16);
	c.writeUInt32LE(packed.length, 20);
	c.writeUInt32LE(e.data.length, 24);
	c.writeUInt16LE(name.length, 28);
	c.writeUInt32LE(((0o100644 << 16) >>> 0), 38); // zwykly plik 0644
	c.writeUInt32LE(offset, 42);
	central.push(c, name);

	offset += local.length + name.length + packed.length;
}
const centralBuf = Buffer.concat(central);
const end = Buffer.alloc(22);
end.writeUInt32LE(0x06054b50, 0);
end.writeUInt16LE(entries.length, 8);
end.writeUInt16LE(entries.length, 10);
end.writeUInt32LE(centralBuf.length, 12);
end.writeUInt32LE(offset, 16);

const zip = Buffer.concat([...chunks, centralBuf, end]);
mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, zip);

console.log(`Paczka ${SLUG} ${version}: ${entries.length} plikow`);
for (const e of entries) console.log('  ' + e.name + '  ' + e.data.length);
console.log('Zapisano: ' + out + ' (' + zip.length + ' B)');
console.log('SHA-256: ' + createHash('sha256').update(zip).digest('hex'));
