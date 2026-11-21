/* Kokpit: podpowiedź na wykresie, sortowanie i szukanie w tabeli stron, kopiowanie przekierowania, przełącznik Wykres / Tabela,
 * rozwijane wyjaśnienia „Co to znaczy?” i odświeżanie jednego widgetu bez przeładowania strony (zakres dat, Dni/Tygodnie). Bez bibliotek. */
(function () {
	'use strict';
	function tips(root) {
		root.querySelectorAll('.cfi-chartwrap').forEach(function (w) {
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
	function tables(root) {
		root.querySelectorAll('[data-cfi-table]').forEach(function (tb) {
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
	/* „Co to znaczy?”: przycisk rozwija panel z wyjaśnieniem (aria-expanded). Bez JavaScriptu panel jest od razu widoczny. */
	function help(root) {
		root.querySelectorAll('[data-cfi-help]').forEach(function (b) {
			var p = document.getElementById(b.getAttribute('aria-controls'));
			if (p) { p.hidden = true; }
		});
	}
	function helpClicks() {
		document.addEventListener('click', function (e) {
			var b = e.target.closest ? e.target.closest('[data-cfi-help]') : null;
			if (!b) { return; }
			var p = document.getElementById(b.getAttribute('aria-controls')), open = b.getAttribute('aria-expanded') !== 'true';
			b.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (p) { p.hidden = !open; }
		});
	}
	/* Odświeżanie jednego widgetu: zmiana zakresu (7/30/90/Rok, daty + Pokaż) i Dni/Tygodnie wymieniają tylko ten widget.
	 * Adres w pasku dostaje wybór (history.replaceState), więc odświeżenie strony go zachowuje. Gdy cokolwiek zawiedzie,
	 * przeglądarka idzie zwykłym linkiem (cała strona), czyli tak jak bez JavaScriptu. */
	function live() {
		var cfg = window.colorifyInsights;
		if (!cfg || !window.fetch || !window.URLSearchParams) { return; }
		var jobs = new WeakMap();
		function load(box, url, trigger) {
			var key = trigger && trigger.getAttribute('data-cfi-nav'), prev = jobs.get(box);
			if (prev) { prev.abort(); }
			var ctl = window.AbortController ? new AbortController() : null;
			jobs.set(box, ctl);
			var h = box.offsetHeight;
			box.style.minHeight = h + 'px'; /* wysokość zostaje na czas ładowania: układ strony się nie skacze */
			box.classList.add('is-loading');
			box.setAttribute('aria-busy', 'true');
			var body = new URLSearchParams({ action: 'colorify_insights', nonce: cfg.nonce, view: box.getAttribute('data-cfi-view'), ref: url });
			fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body, signal: ctl ? ctl.signal : undefined })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (!j || !j.success) { throw new Error('bad'); }
					box.innerHTML = j.data.html;
					try { history.replaceState(null, '', url); } catch (e) { /* adres bez zmian */ }
					init(box);
					var f = key ? box.querySelector('[data-cfi-nav="' + key + '"]') : box.querySelector('[data-cfi-form] button[type=submit]');
					if (f) { f.focus({ preventScroll: true }); }
					box.classList.remove('is-loading');
					box.removeAttribute('aria-busy');
					box.style.minHeight = '';
				})
				.catch(function (err) {
					if (err && err.name === 'AbortError') { return; }
					window.location.href = url;
				});
		}
		/* Okres całego widoku kokpitu (7/30/90 dni): wymieniamy cały widok. Widoki z kalendarzem mają własny skrypt i zostają przy zwykłym linku. */
		function loadView(box, url, a) {
			var key = a.getAttribute('data-cfd-nav');
			box.style.minHeight = box.offsetHeight + 'px';
			box.classList.add('is-loading');
			box.setAttribute('aria-busy', 'true');
			var body = new URLSearchParams({ action: 'colorify_dashboard', nonce: cfg.dash, ref: url });
			fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (!j || !j.success) { throw new Error('bad'); }
					var t = document.createElement('div');
					t.innerHTML = j.data.html;
					var n = t.firstElementChild;
					if (!n) { throw new Error('empty'); }
					box.parentNode.replaceChild(n, box);
					try { history.replaceState(null, '', url); } catch (e) { /* adres bez zmian */ }
					init(n);
					var f = n.querySelector('[data-cfd-nav="' + key + '"]');
					if (f) { f.focus({ preventScroll: true }); }
				})
				.catch(function () { window.location.href = url; });
		}
		document.addEventListener('click', function (e) {
			var v = e.target.closest ? e.target.closest('a[data-cfd-nav]') : null;
			if (v && !e.defaultPrevented && e.button === 0 && !(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) && v.closest('.cfd') && !document.querySelector('.cfb')) {
				e.preventDefault();
				loadView(v.closest('.cfd'), v.href, v);
				return;
			}
			var a = e.target.closest ? e.target.closest('a[data-cfi-nav]') : null, box = a && a.closest('.cfi-live');
			if (!a || !box || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
			e.preventDefault();
			load(box, a.href, a);
		});
		document.addEventListener('submit', function (e) {
			var f = e.target.closest ? e.target.closest('form[data-cfi-form]') : null, box = f && f.closest('.cfi-live');
			if (!f || !box) { return; }
			e.preventDefault();
			var q = new URLSearchParams(new FormData(f));
			load(box, window.location.origin + window.location.pathname + '?' + q.toString(), f.querySelector('button[type=submit]'));
		});
	}
	function init(root) { tips(root); tables(root); help(root); }
	function boot() { init(document); copy(); views(); hints(); helpClicks(); live(); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
