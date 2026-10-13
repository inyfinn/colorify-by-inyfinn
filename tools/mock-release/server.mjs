// Atrapa GitHuba do testow updatera Colorify (tylko laboratorium, nie trafia do paczki).
//
//   node tools/mock-release/server.mjs [--port 8765] --zip 1.5.0=<plik.zip> [--zip 1.5.1=<plik.zip> ...]
//
// Endpointy:
//   GET  /repos/{owner}/{repo}/releases/latest   JSON jak z api.github.com (wg stanu)
//   GET  /download/{tag}/{asset}                 plik ZIP dla tagu
//   GET  /__config, POST /__config               odczyt i zmiana stanu (JSON, pola ponizej)
//   GET  /__hits                                 dziennik zapytan (kontrola UA i liczby zapytan)
//
// POST /__config przyjmuje tez {"zips": {"1.5.2": "<plik.zip>"}} (dodaje paczki bez restartu).
// Stan: tag ("v1.5.0"), mode: ok | noasset | foreign | zipball | status500 | status403 | badjson,
//       assetName, body (tresc release), repo (owner/repo uzyty w browser_download_url),
//       digest: auto (sha256 pliku ZIP, jak prawdziwe API) | wrong (inna suma) | none (brak pola) | md5 (zly format),
//       swap: {"2.0.1": "<plik.zip>"} (adres paczki zwraca INNY plik niz ten, z ktorego policzono digest).
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';

const argv = process.argv.slice(2);
const port = parseInt(argv[argv.indexOf('--port') + 1] || '8765', 10) || 8765;
const zips = new Map();
argv.forEach((a, i) => {
	if (a === '--zip') {
		const [ver, path] = argv[i + 1].split('=');
		zips.set(ver, path);
	}
});

const state = {
	tag: 'v' + ([...zips.keys()][0] || '1.5.0'),
	mode: 'ok',
	assetName: 'colorify-by-inyfinn.zip',
	repo: 'inyfinn/colorify-by-inyfinn',
	body: 'Requires at least: 7.0\nRequires PHP: 7.4\n\nTestowe wydanie.',
	digest: 'auto',
	swap: {},
};
const hits = [];

function releaseJson() {
	const base = `https://github.com/${state.repo}/releases/download/${state.tag}/`;
	const release = {
		tag_name: state.tag,
		name: state.tag,
		html_url: `https://github.com/${state.repo}/releases/tag/${state.tag}`,
		draft: false,
		prerelease: false,
		published_at: '2026-10-07T12:00:00Z',
		body: state.body,
		zipball_url: `https://api.github.com/repos/${state.repo}/zipball/${state.tag}`,
		assets: [],
	};
	if (state.mode === 'ok') {
		const asset = { name: state.assetName, browser_download_url: base + state.assetName };
		const file = zips.get(state.tag.replace(/^v/, ''));
		if (state.digest === 'auto' && file) asset.digest = 'sha256:' + createHash('sha256').update(readFileSync(file)).digest('hex');
		if (state.digest === 'wrong') asset.digest = 'sha256:' + '0'.repeat(64);
		if (state.digest === 'md5') asset.digest = 'md5:' + '0'.repeat(32);
		release.assets.push(asset);
	}
	if (state.mode === 'foreign') release.assets.push({ name: state.assetName, browser_download_url: `https://evil.example.com/${state.assetName}` });
	return release;
}

createServer((req, res) => {
	const url = new URL(req.url, 'http://x');
	hits.push({ t: new Date().toISOString(), method: req.method, path: url.pathname, ua: req.headers['user-agent'] || '', apiVersion: req.headers['x-github-api-version'] || '' });
	const send = (code, type, data) => {
		res.writeHead(code, { 'Content-Type': type });
		res.end(data);
	};

	if (url.pathname === '/__hits') return send(200, 'application/json', JSON.stringify(hits, null, 1));
	if (url.pathname === '/__config') {
		if (req.method === 'POST') {
			let raw = '';
			req.on('data', (c) => (raw += c));
			req.on('end', () => {
				try {
					const cfg = JSON.parse(raw || '{}');
					if (cfg.zips) for (const [ver, path] of Object.entries(cfg.zips)) zips.set(ver, path);
					delete cfg.zips;
					Object.assign(state, cfg);
					send(200, 'application/json', JSON.stringify(state));
				} catch (e) {
					send(400, 'text/plain', String(e));
				}
			});
			return;
		}
		return send(200, 'application/json', JSON.stringify(state));
	}
	if (/^\/repos\/[^/]+\/[^/]+\/releases\/latest$/.test(url.pathname)) {
		if (state.mode === 'status500') return send(500, 'application/json', '{"message":"boom"}');
		if (state.mode === 'status403') return send(403, 'application/json', '{"message":"API rate limit exceeded"}');
		if (state.mode === 'badjson') return send(200, 'application/json', '<<not json>>');
		return send(200, 'application/json', JSON.stringify(releaseJson()));
	}
	const dl = /^\/download\/([^/]+)\/([^/]+)$/.exec(url.pathname);
	if (dl) {
		const ver = dl[1].replace(/^v/, '');
		const path = state.swap[ver] || zips.get(ver);
		if (!path) return send(404, 'text/plain', 'no zip for ' + dl[1]);
		return send(200, 'application/zip', readFileSync(path));
	}
	send(404, 'text/plain', 'not found');
}).listen(port, '0.0.0.0', () => console.log(`mock GitHub na :${port}, paczki: ${[...zips.keys()].join(', ') || '(brak)'}`));
