// Test numerowania wierszy list w panelu "Treści strony" (assets/colorify-tresci.js, cftNumeruj).
// Do 2.12.x regex gubił ukośnik i zamieniał cft[faq][3][q] na ej[faq][0][3][q], więc zmiany list nie trafiały do zapisu.
// Uruchom: node tools/test-tresci.mjs
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const src = readFileSync(new URL('../assets/colorify-tresci.js', import.meta.url), 'utf8');
const a = src.indexOf('/* test:numeruj-start */'), b = src.indexOf('/* test:numeruj-end */');
assert.ok(a > 0 && b > a, 'brak znaczników test:numeruj w colorify-tresci.js');
const cftNumeruj = new Function(src.slice(a, b) + '; return cftNumeruj;')();

// Tak jak renumber(): nazwa pola, klucz podglądu, id.
const nazwa = (n, key, i) => cftNumeruj(n, 'cft[' + key + '][', ']', i);
const klucz = (k, key, i) => cftNumeruj(k, key + '.', '.', i);
const id = (s, key, i) => cftNumeruj(s, 'cft-' + key + '-', '-', i);

for (const [key, kol] of [['faq', 'q'], ['menu', 'u'], ['insta', 'img']]) {
	assert.equal(nazwa(`cft[${key}][3][${kol}]`, key, 0), `cft[${key}][0][${kol}]`);
	assert.equal(nazwa(`cft[${key}][__i__][${kol}]`, key, 5), `cft[${key}][5][${kol}]`, 'nowy wiersz z szablonu');
	assert.equal(nazwa(`cft[${key}][12][kadr]`, key, 2), `cft[${key}][2][kadr]`);
	assert.equal(klucz(`${key}.__i__.${kol}`, key, 7), `${key}.7.${kol}`);
	assert.equal(id(`cft-${key}-__i__-${kol}`, key, 4), `cft-${key}-4-${kol}`);
	assert.equal(nazwa(`cft[inne][3][${kol}]`, key, 0), `cft[inne][3][${kol}]`, 'cudza lista bez zmian');
	assert.ok(!nazwa(`cft[${key}][3][${kol}]`, key, 0).startsWith('ej['), 'nigdy ej[');
}
console.log('OK: numerowanie wierszy list (faq, menu, insta)');
