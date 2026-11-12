/* Kokpit: podpowiedź na wykresie, sortowanie i szukanie w tabeli stron, kopiowanie przekierowania. Bez bibliotek. */
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
	function init() { tips(); tables(); copy(); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
