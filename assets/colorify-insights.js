/* Kokpit: podpowiedź na wykresie, sortowanie i szukanie w tabeli stron, kopiowanie przekierowania, przełącznik Wykres / Tabela. Bez bibliotek. */
(function () {
	'use strict';
	function tips() {
		document.querySelectorAll('.cfi-chartwrap').forEach(function (w) {
			var tip = w.querySelector('.cfi-tip');
			if (!tip) { return; }
			function show(r) {
				var wb = w.getBoundingClientRect(), rb = r.getBoundingClientRect();
				tip.textContent = r.getAttribute('data-tip') || '';
				tip.hidden = false;
				tip.style.left = Math.min(Math.max(rb.left - wb.left + rb.width / 2, 80), wb.width - 80) + 'px';
			}
			w.addEventListener('mouseover', function (e) { if (e.target.classList && e.target.classList.contains('cfi-hit')) { show(e.target); } });
			w.addEventListener('mouseleave', function () { tip.hidden = true; });
			w.addEventListener('touchstart', function (e) { var t = document.elementFromPoint(e.touches[0].clientX, e.touches[0].clientY); if (t && t.classList.contains('cfi-hit')) { show(t); } }, { passive: true });
		});
	}
	function tables() {
		document.querySelectorAll('[data-cfi-table]').forEach(function (tb) {
			var body = tb.tBodies[0], rows = Array.prototype.slice.call(body.rows), card = tb.closest('.cfi-card');
			var q = card.querySelector('[data-cfi-search]'), cnt = card.querySelector('[data-cfi-count]');
			function count() { var n = rows.filter(function (r) { return !r.hidden; }).length; if (cnt) { cnt.textContent = n === rows.length ? '' : n + ' z ' + rows.length; } }
			if (q) { q.addEventListener('input', function () { var v = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = v && r.getAttribute('data-t').indexOf(v) < 0; }); count(); }); }
			tb.querySelectorAll('th button').forEach(function (b) {
				b.addEventListener('click', function () {
					var k = b.getAttribute('data-sort'), asc = b.classList.contains('is-sorted') && !b.classList.contains('is-asc');
					tb.querySelectorAll('th button').forEach(function (x) { x.classList.remove('is-sorted', 'is-asc'); x.removeAttribute('aria-sort'); });
					b.classList.add('is-sorted'); if (asc) { b.classList.add('is-asc'); }
					b.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
					rows.sort(function (a, c) {
						var x = a.getAttribute('data-' + k), y = c.getAttribute('data-' + k), r = k === 't' ? x.localeCompare(y, 'pl') : parseFloat(x) - parseFloat(y);
						return asc ? r : -r;
					}).forEach(function (r) { body.appendChild(r); });
				});
			});
		});
	}
	function copy() {
		document.addEventListener('click', function (e) {
			var b = e.target.closest('[data-cfi-copy]');
			if (!b) { return; }
			var t = b.getAttribute('data-cfi-copy'), o = b.textContent;
			function ok() { b.textContent = 'Skopiowano'; setTimeout(function () { b.textContent = o; }, 1500); }
			if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(ok, function () {}); }
		});
	}
	/* Karta z dwoma widokami (Wykres / Tabela). Tabela zostaje w drzewie dostępności także wtedy, gdy na ekranie jest wykres. */
	function views() {
		/* Tabela szersza niż karta (telefon): prawa krawędź gaśnie, dopóki jest co przewijać w bok. */
		function edge(s) { s.classList.toggle('is-more', s.scrollWidth - s.clientWidth - s.scrollLeft > 2); }
		document.addEventListener('click', function (e) {
			var b = e.target.closest ? e.target.closest('[data-cfi-view]') : null;
			if (!b) { return; }
			var card = b.closest('.cfi-card'), table = b.getAttribute('data-cfi-view') === 'table';
			card.querySelectorAll('[data-cfi-view]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			card.querySelectorAll('[data-cfi-pane]').forEach(function (p) {
				if (p.getAttribute('data-cfi-pane') === 'table') { p.classList.toggle('screen-reader-text', !table); } else { p.hidden = table; }
			});
			card.querySelectorAll('.cfi-scroll').forEach(edge);
		});
		document.addEventListener('scroll', function (e) {
			if (e.target.classList && e.target.classList.contains('cfi-scroll')) { edge(e.target); }
		}, true);
	}
	/* Podpowiedź przy segmencie i słupku (mysz, dotyk): nazwa i liczba. Te same liczby są w tabeli. */
	function hints() {
		var tip = null, timer = 0;
		function hide() { if (tip) { tip.hidden = true; } }
		function show(el) {
			if (!tip) { tip = document.createElement('div'); tip.className = 'cfi-tt'; document.body.appendChild(tip); }
			tip.textContent = el.getAttribute('data-cfi-tip') || '';
			tip.hidden = false;
			var r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
			tip.style.left = Math.round(Math.min(Math.max(r.left + r.width / 2 - w / 2, 8), document.documentElement.clientWidth - w - 8)) + 'px';
			tip.style.top = Math.round(r.top - h - 8 < 8 ? r.bottom + 8 : r.top - h - 8) + 'px';
		}
		function target(e) { return e.target.closest ? e.target.closest('[data-cfi-tip]') : null; }
		document.addEventListener('mouseover', function (e) { var el = target(e); if (el) { show(el); } else { hide(); } });
		document.addEventListener('touchstart', function (e) { var el = target(e); clearTimeout(timer); if (el) { show(el); timer = setTimeout(hide, 2500); } else { hide(); } }, { passive: true });
		window.addEventListener('scroll', hide, { passive: true });
	}
	function init() { tips(); tables(); copy(); views(); hints(); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
