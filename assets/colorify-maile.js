/* Colorify, moduł Kreator e-maili (wp-admin): wersje (zakładki, dodaj, usuń), chipy zmiennych w miejscu kursora, B / I,
 * podgląd na żywo (AJAX, debounce) z wyborem rekordu i szerokości, test na własny adres, przywracanie domyślnych w formularzu. */
(function () {
	'use strict';
	var form = document.getElementById('cfm-form');
	if (!form) { return; }
	var CFG = window.CFM || {};
	var wrap = form.closest('.cfm');
	var stan = document.getElementById('cfm-stan');
	var iframe = document.getElementById('cfm-iframe');
	var tematEl = document.getElementById('cfm-temat');
	var rekord = document.getElementById('cfm-rekord');
	var aside = document.getElementById('cfm-podglad');
	var wiele = !!form.querySelector('.cfm-wersje');
	var max = wiele ? parseInt(form.querySelector('.cfm-wersje').getAttribute('data-cfm-max'), 10) || 6 : 1;
	var aktywna = 0, ostatniePole = null, dirty = false, timer = null, zadanie = 0;

	function markDirty() {
		if (!dirty) { dirty = true; if (stan) { stan.textContent = 'Masz niezapisane zmiany'; stan.classList.add('is-dirty'); } }
	}
	form.addEventListener('input', function () { markDirty(); podgladPozniej(); });
	form.addEventListener('change', function () { markDirty(); podgladPozniej(); });
	window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
	form.addEventListener('submit', function () { dirty = false; });

	/* ----- wersje ----- */
	function wersje() { return [].slice.call(form.querySelectorAll('.cfm-wersja:not(template .cfm-wersja)')); }
	function renumeruj() {
		var pasek = form.querySelector('.cfm-wersje__pasek');
		var lista = wersje();
		lista.forEach(function (f, i) {
			f.setAttribute('data-cfm-wersja', i);
			[].forEach.call(f.querySelectorAll('[name]'), function (el) { el.name = el.name.replace(/^m\[[^\]]*\]/, 'm[' + i + ']'); });
			[].forEach.call(f.querySelectorAll('[id^="cfm-"]'), function (el) { el.id = el.id.replace(/^cfm-[^-]+-/, 'cfm-' + i + '-'); });
			[].forEach.call(f.querySelectorAll('label[for^="cfm-"]'), function (el) { el.htmlFor = el.htmlFor.replace(/^cfm-[^-]+-/, 'cfm-' + i + '-'); });
		});
		if (!pasek) { return; }
		[].forEach.call(pasek.querySelectorAll('[data-cfm-tab]'), function (b) { b.remove(); });
		var dodaj = pasek.querySelector('[data-cfm-dodaj]');
		lista.forEach(function (f, i) {
			var b = document.createElement('button');
			b.type = 'button'; b.setAttribute('role', 'tab'); b.className = 'cfm-wersje__tab'; b.setAttribute('data-cfm-tab', i);
			b.textContent = 'Wersja ' + (i + 1);
			pasek.insertBefore(b, dodaj);
		});
		dodaj.hidden = lista.length >= max;
		pokaz(Math.min(aktywna, lista.length - 1));
	}
	function pokaz(i) {
		aktywna = Math.max(0, i);
		wersje().forEach(function (f, j) { f.hidden = wiele && j !== aktywna; });
		[].forEach.call(form.querySelectorAll('[data-cfm-tab]'), function (b) { b.setAttribute('aria-selected', parseInt(b.getAttribute('data-cfm-tab'), 10) === aktywna ? 'true' : 'false'); });
		podgladPozniej();
	}
	form.addEventListener('click', function (e) {
		var tab = e.target.closest('[data-cfm-tab]');
		if (tab) { pokaz(parseInt(tab.getAttribute('data-cfm-tab'), 10)); return; }
		if (e.target.closest('[data-cfm-dodaj]')) {
			var tpl = form.querySelector('.cfm-szablon-wersji');
			var nowa = tpl.content.cloneNode(true);
			form.querySelector('.cfm-szablon-wersji').before(nowa);
			aktywna = wersje().length - 1;
			renumeruj(); markDirty();
			var first = wersje()[aktywna].querySelector('input, textarea'); if (first) { first.focus(); }
			return;
		}
		if (e.target.closest('[data-cfm-usun]')) {
			var lista = wersje();
			if (lista.length <= 1) { window.alert('Szablon musi mieć choć jedną wersję.'); return; }
			if (!window.confirm('Usunąć wersję ' + (aktywna + 1) + '?')) { return; }
			lista[aktywna].remove(); renumeruj(); markDirty();
			return;
		}
		if (e.target.closest('[data-cfm-reset-form]')) {
			if (!window.confirm('Wstawić domyślne teksty do formularza? Zapiszesz je dopiero przyciskiem „Zapisz szablon”.')) { return; }
			var dom = [];
			try { dom = JSON.parse(form.querySelector('.cfm-domyslne').getAttribute('data-cfm-domyslne')) || []; } catch (err) {}
			wersje().forEach(function (f) { f.remove(); });
			var tpl2 = form.querySelector('.cfm-szablon-wersji');
			dom.forEach(function (w) {
				var f = tpl2.content.cloneNode(true).querySelector('.cfm-wersja');
				Object.keys(w).forEach(function (k) { var el = f.querySelector('[name$="[' + k + ']"]'); if (el) { el.value = w[k]; } });
				tpl2.before(f);
			});
			aktywna = 0; renumeruj(); markDirty();
			return;
		}
		if (e.target.closest('[data-cfm-reset]')) {
			if (!window.confirm('Przywrócić domyślny szablon i zapisać od razu? Obecny trafi do historii.')) { e.preventDefault(); }
			return;
		}
		var tb = e.target.closest('.cft-tb button');
		if (tb) {
			var area = tb.closest('.cfm-pole').querySelector('textarea');
			var tag = tb.getAttribute('data-tag');
			var a = area.selectionStart, b = area.selectionEnd, v = area.value;
			area.value = v.slice(0, a) + '<' + tag + '>' + v.slice(a, b) + '</' + tag + '>' + v.slice(b);
			area.focus(); area.setSelectionRange(a + tag.length + 2, b + tag.length + 2);
			area.dispatchEvent(new Event('input', { bubbles: true }));
			return;
		}
		var chip = e.target.closest('[data-cfm-zm]');
		if (chip) {
			var el = ostatniePole && form.contains(ostatniePole) && !ostatniePole.closest('[hidden]') ? ostatniePole : (wersje()[aktywna] || form).querySelector('[data-cfm-chipy]');
			if (!el) { return; }
			var s = el.selectionStart || 0, t = el.selectionEnd || 0, z = chip.getAttribute('data-cfm-zm');
			el.value = el.value.slice(0, s) + z + el.value.slice(t);
			el.focus(); el.setSelectionRange(s + z.length, s + z.length);
			el.dispatchEvent(new Event('input', { bubbles: true }));
		}
	});
	form.addEventListener('focusin', function (e) { if (e.target.matches('[data-cfm-chipy]')) { ostatniePole = e.target; } });

	/* ----- podgląd ----- */
	function dane(extra) {
		var fd = new FormData(form);
		fd.set('nonce', CFG.nonce);
		fd.set('wersja_nr', aktywna);
		fd.set('rekord', rekord ? rekord.value : 0);
		Object.keys(extra || {}).forEach(function (k) { fd.set(k, extra[k]); });
		return fd;
	}
	function podglad() {
		if (!iframe) { return; }
		var fd = dane({ action: 'colorify_maile_podglad' });
		var n = ++zadanie;
		fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
			if (n !== zadanie) { return; }
			if (!j || !j.success) { tematEl.textContent = (j && j.data && j.data.komunikat) || 'Błąd podglądu'; return; }
			tematEl.textContent = j.data.temat;
			var y = 0; try { y = iframe.contentWindow.scrollY; } catch (err) {}
			iframe.srcdoc = j.data.html;
			iframe.onload = function () { try { iframe.contentWindow.scrollTo(0, y); } catch (err) {} };
		}).catch(function () { tematEl.textContent = 'Błąd połączenia'; });
	}
	function podgladPozniej() { clearTimeout(timer); timer = setTimeout(podglad, 450); }
	if (rekord) { rekord.addEventListener('change', podglad); }
	if (aside) {
		aside.addEventListener('click', function (e) {
			var b = e.target.closest('[data-szer]');
			if (b) {
				[].forEach.call(aside.querySelectorAll('[data-szer]'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				iframe.style.width = b.getAttribute('data-szer') + 'px';
			}
			if (e.target.closest('[data-cfm-podglad-zamknij]')) { wrap.classList.remove('cfm--podglad'); document.body.classList.remove('cfm-podglad-otwarty'); }
		});
	}
	form.addEventListener('click', function (e) {
		if (e.target.closest('[data-cfm-podglad-otworz]')) { e.preventDefault(); wrap.classList.add('cfm--podglad'); document.body.classList.add('cfm-podglad-otwarty'); podglad(); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && wrap.classList.contains('cfm--podglad')) { wrap.classList.remove('cfm--podglad'); document.body.classList.remove('cfm-podglad-otwarty'); } });

	/* ----- test ----- */
	var test = document.getElementById('cfm-test'), testStan = document.getElementById('cfm-test-stan'), adres = document.getElementById('cfm-adres');
	if (test) {
		test.addEventListener('click', function () {
			test.disabled = true; testStan.textContent = 'Wysyłam…';
			fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: dane({ action: 'colorify_maile_test', adres: adres ? adres.value : '' }) }).then(function (r) { return r.json(); }).then(function (j) {
				testStan.textContent = (j && j.data && j.data.komunikat) || (j && j.success ? 'Wysłano.' : 'Nie wyszło.');
			}).catch(function () { testStan.textContent = 'Błąd połączenia.'; }).then(function () { test.disabled = false; });
		});
	}

	renumeruj();
	podglad();
})();
