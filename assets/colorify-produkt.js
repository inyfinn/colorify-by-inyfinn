/* Colorify, moduł Edytor produktu (wp-admin): dodatki do wspólnego skryptu Edytora treści. colorify-tresci.js robi karty,
 * granicę panel / podgląd, ramkę podglądu, podświetlanie i stan zapisu; tu są edytory WP, zdjęcia, teksty wspólne
 * z osobnym zapisem, zakładki WooCommerce w podglądzie i odświeżanie podglądu. */
(function () {
	'use strict';
	var form = document.getElementById('cft-form');
	if (!form || !window.CPE) { return; }
	var CPE = window.CPE;
	var wrap = form.closest('.cft'), aside = document.getElementById('cft-podglad'), iframe = aside.querySelector('iframe');
	var auto = document.getElementById('cpe-auto'), autoW = document.getElementById('cpe-auto-w');

	/* ----- odświeżanie podglądu -----
	 * Zmiana ukrytego pola [data-struktura] uruchamia we wspólnym skrypcie przeładowanie ramki ze zwłoką (CFT.zwloka).
	 * Pole z bloku tekstów wspólnych używa drugiego wyzwalacza, który nie oznacza formularza produktu jako zmienionego. */
	function odswiez(el) {
		var w = el && el.closest ? el.closest('.cpe-wspolne') : null;
		(w ? autoW : auto).dispatchEvent(new Event('change', { bubbles: true }));
		if (w) { blokBrudny(w); }
	}
	function tinySave() { if (window.tinymce) { window.tinymce.triggerSave(); } }
	form.addEventListener('input', function (e) {
		if (e.target.hasAttribute && e.target.hasAttribute('data-cpe-auto')) { odswiez(e.target); }
	});
	form.addEventListener('change', function (e) {
		var w = e.target.closest ? e.target.closest('.cpe-wspolne') : null;
		if (w) { blokBrudny(w); }
	});
	wrap.querySelector('[data-cpe-odswiez]').addEventListener('click', function () {
		tinySave();
		wrap.dispatchEvent(new CustomEvent('cft:odswiez'));
	});
	wrap.addEventListener('cft:limit', function () { document.getElementById('cpe-limit').hidden = false; });

	/* ----- edytory WP (TinyMCE i zakładka „Tekst”) -----
	 * Pole „tknięte” mówi serwerowi, że ktoś naprawdę pisał: samo otwarcie edytora przepisuje HTML po swojemu
	 * i nie może zmienić treści produktu. */
	function edytorTkniety(box) {
		var flaga = form.querySelector('[data-cpe-tkniete="' + box.getAttribute('data-cpe-wp') + '"]');
		if (flaga) { flaga.value = '1'; }
		odswiez(box);
	}
	[].forEach.call(form.querySelectorAll('[data-cpe-wp]'), function (box) {
		var ta = document.getElementById(box.getAttribute('data-cpe-wp'));
		if (ta) { ta.addEventListener('input', function () { edytorTkniety(box); }); }
	});
	function edytorPodlacz(ed) {
		var box = form.querySelector('[data-cpe-wp="' + ed.id + '"]');
		if (!box || ed.cpePodlaczony) { return; }
		ed.cpePodlaczony = true;
		var ost = ed.getContent();
		ed.on('input keyup change undo redo', function () {
			var teraz = ed.getContent();
			if (teraz === ost) { return; }
			ost = teraz;
			ed.save();
			edytorTkniety(box);
		});
		// Fokus w edytorze = fokus pola: wspólny skrypt podświetla element w podglądzie po zdarzeniu focusin z etykiety.
		var etyk = box.parentNode.querySelector('[data-key]');
		ed.on('focus', function () { if (etyk) { etyk.dispatchEvent(new FocusEvent('focusin', { bubbles: true })); } });
	}
	if (window.tinymce) {
		window.tinymce.on('AddEditor', function (e) { e.editor.on('init', function () { edytorPodlacz(e.editor); }); });
		(window.tinymce.editors || []).forEach(function (ed) { if (ed.initialized) { edytorPodlacz(ed); } });
	}

	/* ----- pole z kilkoma znacznikami (pogrubienie, kursywa, odnośnik): przyciski owijają zaznaczony tekst ----- */
	form.addEventListener('click', function (e) {
		var z = e.target.closest('[data-cpe-znacznik]');
		if (!z) { return; }
		var ta = document.getElementById(z.parentNode.getAttribute('data-cpe-tb')), tag = z.getAttribute('data-cpe-znacznik');
		var a = ta.selectionStart, b = ta.selectionEnd;
		if (a === b) { window.alert('Najpierw zaznacz w polu tekst, który chcesz zmienić.'); ta.focus(); return; }
		var otw = '<' + tag + '>';
		if (tag === 'a') {
			var url = window.prompt('Adres, do którego ma prowadzić odnośnik:', 'https://');
			if (!url || url === 'https://') { ta.focus(); return; }
			otw = '<a href="' + url.replace(/"/g, '&quot;') + '">';
		}
		ta.setRangeText(otw + ta.value.slice(a, b) + '</' + tag + '>', a, b, 'end');
		ta.dispatchEvent(new Event('input', { bubbles: true }));
		ta.focus();
	});

	/* ----- zdjęcie główne i dodatkowe zdjęcia (biblioteka mediów WordPressa) ----- */
	function miniatura(att) {
		var s = att.sizes || {}, w = s.medium || s.thumbnail || s.full;
		return w ? w.url : att.url;
	}
	function biblioteka(opcje, cb) {
		var f = window.wp.media(opcje);
		f.on('select', function () { cb(f.state().get('selection').toJSON()); });
		f.open();
	}
	function kafel(el, adresy) {
		var k = el.closest('details.cft-karta').querySelector('[data-cpe-kafel]');
		if (!k) { return; }
		k.textContent = '';
		adresy.slice(0, 3).forEach(function (u) { var i = document.createElement('img'); i.src = u; i.alt = ''; k.appendChild(i); });
	}
	function galeriaZapisz(gal) {
		var el = [].slice.call(gal.querySelectorAll('.cpe-galeria__lista > .cpe-galeria__el'));
		gal.querySelector('input[type=hidden]').value = el.map(function (li) { return li.getAttribute('data-id'); }).join(',');
		el.forEach(function (li, i) {
			li.querySelector('[data-cpe-gal="lewo"]').disabled = i === 0;
			li.querySelector('[data-cpe-gal="prawo"]').disabled = i === el.length - 1;
		});
		kafel(gal, el.map(function (li) { return li.querySelector('img').src; }));
	}
	[].forEach.call(form.querySelectorAll('.cpe-galeria'), galeriaZapisz);
	form.addEventListener('click', function (e) {
		var m = e.target.closest('[data-cpe-media]'), g = e.target.closest('[data-cpe-gal]');
		if (m) {
			var box = m.closest('.cpe-media'), val = box.querySelector('input[type=hidden]'), pod = box.querySelector('.cft-media__pod'), usun = box.querySelector('[data-cpe-media="usun"]');
			if (m.getAttribute('data-cpe-media') === 'usun') {
				val.value = '0';
				pod.innerHTML = '<span class="cft-media__brak">Brak zdjęcia</span>';
				usun.hidden = true;
				kafel(box, []);
				odswiez(box);
				box.querySelector('[data-cpe-media="wybierz"]').focus();
				return;
			}
			biblioteka({ title: 'Wybierz zdjęcie główne', button: { text: 'Użyj tego zdjęcia' }, library: { type: 'image' }, multiple: false }, function (sel) {
				if (!sel[0]) { return; }
				var img = document.createElement('img');
				img.src = miniatura(sel[0]); img.alt = '';
				val.value = sel[0].id;
				pod.textContent = ''; pod.appendChild(img);
				usun.hidden = false;
				kafel(box, [img.src]);
				odswiez(box);
			});
			return;
		}
		if (!g) { return; }
		var gal = g.closest('.cpe-galeria'), lista = gal.querySelector('.cpe-galeria__lista'), co = g.getAttribute('data-cpe-gal'), el = g.closest('.cpe-galeria__el');
		if (co === 'dodaj') {
			biblioteka({ title: 'Dodaj zdjęcia do galerii', button: { text: 'Dodaj do galerii' }, library: { type: 'image' }, multiple: 'add' }, function (sel) {
				sel.forEach(function (att) {
					if (lista.querySelector('[data-id="' + att.id + '"]')) { return; }
					var li = gal.querySelector('.cpe-galeria__szablon').content.firstElementChild.cloneNode(true);
					li.setAttribute('data-id', att.id);
					li.querySelector('img').src = miniatura(att);
					lista.appendChild(li);
				});
				galeriaZapisz(gal);
				odswiez(gal);
			});
			return;
		}
		if (co === 'usun') { el.remove(); }
		if (co === 'lewo' && el.previousElementSibling) { lista.insertBefore(el, el.previousElementSibling); }
		if (co === 'prawo' && el.nextElementSibling) { lista.insertBefore(el.nextElementSibling, el); }
		galeriaZapisz(gal);
		odswiez(gal);
		if (co === 'usun') { gal.querySelector('[data-cpe-gal="dodaj"]').focus(); } else { (g.disabled ? el.querySelector('button:not(:disabled)') : g).focus(); }
	});

	/* ----- teksty wspólne: osobny zapis z potwierdzeniem zasięgu ----- */
	function blokBrudny(blok) {
		var s = blok.querySelector('.cpe-wspolne__stan');
		if (!s) { return; } // bez uprawnień nie ma przycisku ani stanu
		blok.classList.add('is-brudny');
		s.className = 'cpe-wspolne__stan is-brudny';
		s.textContent = 'Niezapisane zmiany w tekście wspólnym';
	}
	form.addEventListener('click', function (e) {
		var b = e.target.closest('[data-cpe-wspolne]');
		if (!b) { return; }
		var blok = b.closest('.cpe-wspolne'), stan = blok.querySelector('.cpe-wspolne__stan');
		var zasieg = b.getAttribute('data-cpe-zasieg') || 'Zmiana dotyczy także innych produktów.';
		if (!window.confirm('Zapisać tekst wspólny?\n\n' + zasieg + '\n\nZmiana będzie od razu widoczna na stronach tych produktów.')) { return; }
		tinySave();
		var fd = new FormData(form);
		fd.set('action', 'colorify_produkt_wspolne');
		fd.set('nonce', CPE.nonce);
		fd.set('karta', b.getAttribute('data-cpe-wspolne'));
		b.disabled = true;
		stan.className = 'cpe-wspolne__stan';
		stan.textContent = 'Zapisuję…';
		fetch(CPE.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
			if (!j || !j.success) { throw new Error('zapis'); }
			blok.classList.remove('is-brudny');
			stan.className = 'cpe-wspolne__stan is-ok';
			stan.textContent = j.data.zmiana ? 'Zapisane. Zmiana jest już na stronach produktów.' : 'Nic się nie zmieniło, więc nic nie zostało zapisane.';
		}).catch(function () {
			stan.className = 'cpe-wspolne__stan is-blad';
			stan.textContent = 'Nie udało się zapisać. Spróbuj jeszcze raz.';
		}).then(function () { b.disabled = false; });
	});
	function brudneBloki() { return [].slice.call(wrap.querySelectorAll('.cpe-wspolne.is-brudny')); }
	window.addEventListener('beforeunload', function (e) {
		if (brudneBloki().length) { e.preventDefault(); e.returnValue = ''; }
	});
	// Faza przechwytywania na przodku formularza: przy rezygnacji wspólny skrypt nie może uznać formularza za zapisany.
	wrap.addEventListener('submit', function (e) {
		if (e.target !== form) { return; }
		tinySave();
		var b = brudneBloki();
		if (!b.length) { return; }
		if (!window.confirm('Masz niezapisane zmiany w tekście wspólnym.\n„Zapisz zmiany” zapisuje tylko ten produkt.\n\nZapisać produkt i porzucić zmiany w tekście wspólnym?')) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return;
		}
		b.forEach(function (x) { x.classList.remove('is-brudny'); });
	}, true);

	/* ----- zakładki WooCommerce w podglądzie: karta zakładki otwiera ją w ramce (także po przeładowaniu ramki) ----- */
	var zakladki = CPE.zakladki || {}, ostatnia = '';
	function zakladka(id) {
		try {
			var d = iframe.contentDocument, w = iframe.contentWindow;
			var a = d && d.querySelector('.wc-tabs a[href="#tab-' + id + '"]'), t = d && d.querySelector('.woocommerce-tabs');
			if (!a) { return; }
			a.click();
			if (t) { w.scrollTo(0, Math.max(0, w.scrollY + t.getBoundingClientRect().top - 12)); }
		} catch (e) {}
	}
	[].forEach.call(form.querySelectorAll('details.cft-karta'), function (k) {
		k.addEventListener('toggle', function () {
			if (!k.open) { return; }
			ostatnia = zakladki[k.getAttribute('data-karta')] || '';
			if (ostatnia) { zakladka(ostatnia); }
		});
	});
	iframe.addEventListener('load', function () {
		if (ostatnia) { setTimeout(function () { zakladka(ostatnia); }, 120); }
	});
})();
