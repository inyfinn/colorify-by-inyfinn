/* Colorify, moduł Edytor treści (wp-admin): edytor akapitów bez surowego HTML, kadrowanie zdjęć (punkt ostrości + przybliżenie),
 * listy, podgląd na żywo prawdziwej strony (iframe tej samej domeny, ?colorify_tresci_podglad=1), kafle sekcji, stan zapisu.
 * Pisanie i kadr trafiają do podglądu od razu (podmiana w DOM po data-cft-key); zmiany struktury (wiersze list,
 * usunięcie zdjęcia) idą przez transient i przeładowanie podglądu. */
(function () {
	'use strict';
	var form = document.getElementById('cft-form');
	if (!form) { return; }
	var CFG = window.CFT || { strony: {}, sekcje: {}, pola: {} };
	var wrap = form.closest('.cft');
	var stan = document.getElementById('cft-stan');
	var dirty = false;

	function markDirty() {
		if (dirty) { return; }
		dirty = true;
		if (stan) { stan.textContent = 'Masz niezapisane zmiany'; stan.classList.add('is-dirty'); }
	}
	form.addEventListener('input', markDirty);
	form.addEventListener('change', markDirty);
	window.addEventListener('beforeunload', function (e) {
		if (dirty) { e.preventDefault(); e.returnValue = ''; }
	});

	function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
	function num(v) { var s = (Math.round(v * 100) / 100).toString(); return s; }
	function baseKey(key) { return String(key).split('.')[0]; }
	function sekcjaPola(key) { var p = CFG.pola[baseKey(key)]; return p ? p.s : null; }
	function stronaPola(key) { var s = sekcjaPola(key); return s && CFG.sekcje[s] ? CFG.sekcje[s].strona : 'oba'; }

	/* =====================================================================
	 * Karty: pamiętaj otwarte, otwieraj z #hash, przewijaj podgląd do sekcji
	 * ===================================================================== */
	var KEY = 'cftOtwarte';
	var karty = [].slice.call(form.querySelectorAll('details.cft-karta'));
	try {
		var saved = JSON.parse(localStorage.getItem(KEY) || 'null');
		if (saved && saved.length !== undefined) {
			karty.forEach(function (k) { k.open = saved.indexOf(k.getAttribute('data-karta')) > -1; });
		}
	} catch (e) {}
	if (!karty.some(function (k) { return k.open; }) && karty[1]) { karty[1].open = true; }
	if (/^#cft-karta-/.test(location.hash)) {
		var cel = document.getElementById(location.hash.slice(1));
		if (cel) { cel.open = true; setTimeout(function () { cel.scrollIntoView({ block: 'start' }); }, 50); }
	}
	karty.forEach(function (k) {
		k.addEventListener('toggle', function () {
			try {
				localStorage.setItem(KEY, JSON.stringify(karty.filter(function (x) { return x.open; }).map(function (x) { return x.getAttribute('data-karta'); })));
			} catch (e) {}
			if (k.open) { podgladSekcja(k.getAttribute('data-karta')); }
		});
	});

	/* =====================================================================
	 * Edytor akapitów: contenteditable, zapis lekkiego HTML do ukrytej textarea.
	 * Znaczki: <br class="ej-br-lg"> = chip „↵ tylko komputer”, <span class="ej-keep|ej-nw"> = „w jednej linii”.
	 * ===================================================================== */
	var CHIP = { 'ej-br-lg': '↵ tylko komputer' };
	function chipHtml(cls) { return '<span class="cft-znak" data-znak="' + cls + '" contenteditable="false" title="Nowa linia tylko na komputerze">' + CHIP[cls] + '</span>'; }

	function doEdytora(html) {
		var box = document.createElement('div');
		box.innerHTML = html;
		[].forEach.call(box.querySelectorAll('br'), function (br) {
			if (CHIP[br.className]) { var s = document.createElement('span'); s.innerHTML = chipHtml(br.className); br.replaceWith(s.firstChild); }
		});
		[].forEach.call(box.querySelectorAll('span.ej-keep, span.ej-nw'), function (s) {
			s.setAttribute('data-cls', s.className); s.className = 'cft-keep'; s.title = 'Te słowa zostaną w jednej linii';
		});
		return box.innerHTML;
	}

	function toHtml(node) {
		var out = '';
		[].forEach.call(node.childNodes, function (n) {
			if (n.nodeType === 3) { out += esc(n.nodeValue.replace(/ /g, ' ')); return; }
			if (n.nodeType !== 1) { return; }
			var t = n.tagName;
			if (n.classList.contains('cft-znak')) { out += '<br class="' + n.getAttribute('data-znak') + '">'; return; }
			if (t === 'BR') { out += n.className === 'ej-br' ? '<br class="ej-br">' : '<br>'; return; }
			if (t === 'B' || t === 'STRONG') { out += '<strong>' + toHtml(n) + '</strong>'; return; }
			if (t === 'I' || t === 'EM') { out += '<em>' + toHtml(n) + '</em>'; return; }
			if (t === 'SPAN' && (n.classList.contains('cft-keep') || /^ej-(keep|nw)$/.test(n.className))) { out += '<span class="' + (n.getAttribute('data-cls') || (n.classList.contains('cft-keep') ? 'ej-keep' : n.className)) + '">' + toHtml(n) + '</span>'; return; }
			if (t === 'A' && n.getAttribute('href')) { out += '<a href="' + esc(n.getAttribute('href')) + '">' + toHtml(n) + '</a>'; return; }
			if (t === 'DIV' || t === 'P') { out += (out && !/<br[^>]*>$/.test(out) ? '<br>' : '') + toHtml(n); return; }
			out += toHtml(n);
		});
		return out;
	}
	function zEdytora(ed, multi) {
		var h = toHtml(ed).replace(/^(\s*<br>)+|(<br>\s*)+$/g, '').trim();
		if (multi) { h = h.replace(/(\s*<br>\s*){2,}/g, '\n\n'); }
		return h;
	}
	function initEdytor(box) {
		var ed = box.querySelector('.cft-ed'), ta = box.querySelector('.cft-src');
		if (!ed || ed.cftInit) { return; }
		ed.cftInit = true;
		var multi = box.getAttribute('data-multi') === '1';
		ed.innerHTML = doEdytora(multi ? ta.value.replace(/\n{2,}/g, '<br><br>') : ta.value);
		ed.addEventListener('input', function () { ta.value = zEdytora(ed, multi); });
		ed.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); document.execCommand('insertLineBreak'); ed.dispatchEvent(new Event('input', { bubbles: true })); }
			if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'i')) { e.preventDefault(); document.execCommand(e.key === 'b' ? 'bold' : 'italic'); ed.dispatchEvent(new Event('input', { bubbles: true })); }
		});
		ed.addEventListener('paste', function (e) {
			e.preventDefault();
			var t = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
			document.execCommand('insertHTML', false, esc(t.replace(/\r\n/g, '\n')).replace(/\n/g, '<br>'));
			ed.dispatchEvent(new Event('input', { bubbles: true }));
		});
		box.querySelector('.cft-tb').addEventListener('mousedown', function (e) { e.preventDefault(); }); // keep the selection
		box.querySelector('.cft-tb').addEventListener('click', function (e) {
			var b = e.target.closest('[data-cmd]');
			if (!b) { return; }
			ed.focus();
			var cmd = b.getAttribute('data-cmd');
			if (cmd === 'bold' || cmd === 'italic') { document.execCommand(cmd); }
			if (cmd === 'br-lg') { document.execCommand('insertHTML', false, chipHtml('ej-br-lg') + ' '); }
			if (cmd === 'keep') {
				var sel = window.getSelection();
				if (!sel.rangeCount || sel.isCollapsed) { window.alert('Najpierw zaznacz słowa, które mają zostać w jednej linii.'); return; }
				var r = sel.getRangeAt(0), tmp = document.createElement('div');
				tmp.appendChild(r.cloneContents());
				document.execCommand('insertHTML', false, '<span class="cft-keep" title="Te słowa zostaną w jednej linii">' + tmp.innerHTML + '</span>');
			}
			ed.dispatchEvent(new Event('input', { bubbles: true }));
		});
	}
	function initEdytory(root) { [].forEach.call(root.querySelectorAll('.cft-edytor'), initEdytor); }
	initEdytory(form);

	/* ----- przywróć domyślne (pola tekstowe i edytory) ----- */
	form.addEventListener('click', function (e) {
		var reset = e.target.closest('.cft-reset');
		if (!reset) { return; }
		var el = document.getElementById(reset.getAttribute('data-target'));
		if (!el) { return; }
		el.value = el.getAttribute('data-default') || '';
		var box = el.closest('.cft-edytor');
		if (box) {
			var ed = box.querySelector('.cft-ed');
			ed.innerHTML = doEdytora(el.value);
			ed.dispatchEvent(new Event('input', { bubbles: true }));
			ed.focus();
		} else {
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.focus();
		}
	});

	/* =====================================================================
	 * Kadr: punkt ostrości (x, y w %) + przybliżenie z. Ten sam CSS co na stronie.
	 * ===================================================================== */
	function kadrCss(k, def) {
		if (k.z <= 1 && k.x === def.x && k.y === def.y) { return ''; }
		var css = 'object-position:' + k.x + '% ' + k.y + '%;';
		if (k.z > 1) {
			var f = 1 - 1 / k.z;
			css += 'transform:scale(' + num(k.z) + ');transform-origin:' + k.x + '% ' + k.y + '%;clip-path:inset(' + num(k.y * f) + '% ' + num((100 - k.x) * f) + '% ' + num((100 - k.y) * f) + '% ' + num(k.x * f) + '%);';
		}
		return css;
	}
	function kadrParse(s, def) {
		var p = String(s || '').split(',');
		if (p.length < 2 || isNaN(parseFloat(p[0])) || isNaN(parseFloat(p[1]))) { return { x: def.x, y: def.y, z: 1 }; }
		return { x: Math.max(0, Math.min(100, Math.round(+p[0]))), y: Math.max(0, Math.min(100, Math.round(+p[1]))), z: Math.max(1, Math.min(2.5, Math.round((+p[2] || 1) * 100) / 100)) };
	}
	function kadrApply(img, k, def) {
		var css = kadrCss(k, def);
		img.style.objectPosition = k.x + '% ' + k.y + '%';
		img.style.transform = ''; img.style.transformOrigin = ''; img.style.clipPath = '';
		if (css) { img.style.cssText += css; }
	}
	function initKadr(box) {
		if (box.cftInit) { return; }
		box.cftInit = true;
		var media = box.closest('.cft-media');
		var ramka = box.querySelector('.cft-kadr__ramka');
		var val = box.querySelector('.cft-kadr__val');
		var zoom = box.querySelector('input[type=range]');
		var out = box.querySelector('output');
		var def = kadrParse(box.getAttribute('data-def'), { x: 50, y: 50 });
		var k = kadrParse(val.value || box.getAttribute('data-def'), def);

		function draw(dirtyToo) {
			var img = ramka.querySelector('img'), pin = ramka.querySelector('.cft-kadr__pin');
			if (img) { kadrApply(img, k, def); }
			if (pin) { pin.style.left = k.x + '%'; pin.style.top = k.y + '%'; }
			zoom.value = k.z; out.textContent = num(k.z).replace('.', ',') + '×';
			val.value = kadrCss(k, def) ? k.x + ',' + k.y + ',' + num(k.z) : '';
			if (dirtyToo !== false) { markDirty(); podgladKadr(media.getAttribute('data-key'), k, def); }
		}
		box.cftReset = function () { k = { x: def.x, y: def.y, z: 1 }; draw(false); };
		draw(false);

		function fromPointer(e) {
			var r = ramka.getBoundingClientRect();
			k.x = Math.round(Math.max(0, Math.min(100, (e.clientX - r.left) / r.width * 100)));
			k.y = Math.round(Math.max(0, Math.min(100, (e.clientY - r.top) / r.height * 100)));
			draw();
		}
		ramka.addEventListener('pointerdown', function (e) {
			if (!ramka.querySelector('img') || e.button) { return; }
			e.preventDefault();
			ramka.setPointerCapture(e.pointerId);
			ramka.classList.add('is-drag');
			fromPointer(e);
			var move = function (ev) { fromPointer(ev); };
			var up = function () { ramka.classList.remove('is-drag'); ramka.removeEventListener('pointermove', move); ramka.removeEventListener('pointerup', up); ramka.removeEventListener('pointercancel', up); };
			ramka.addEventListener('pointermove', move);
			ramka.addEventListener('pointerup', up);
			ramka.addEventListener('pointercancel', up);
		});
		ramka.addEventListener('keydown', function (e) {
			var step = e.shiftKey ? 5 : 1, d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key];
			if (!d) { return; }
			e.preventDefault();
			k.x = Math.max(0, Math.min(100, k.x + d[0])); k.y = Math.max(0, Math.min(100, k.y + d[1]));
			draw();
		});
		zoom.addEventListener('input', function () { k.z = Math.round(+zoom.value * 100) / 100; draw(); });
		box.addEventListener('click', function (e) {
			var b = e.target.closest('[data-kadr], [data-kadr-widok]');
			if (!b) { return; }
			if (b.getAttribute('data-kadr') === 'srodek') { k.x = 50; k.y = 50; draw(); }
			if (b.getAttribute('data-kadr') === 'domyslne') { k = { x: def.x, y: def.y, z: 1 }; draw(); }
			if (b.hasAttribute('data-kadr-widok')) {
				[].forEach.call(box.querySelectorAll('[data-kadr-widok]'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
				ramka.style.aspectRatio = box.getAttribute('data-' + b.getAttribute('data-kadr-widok'));
			}
		});
	}
	function initKadry(root) { [].forEach.call(root.querySelectorAll('.cft-kadr'), initKadr); }
	initKadry(form);

	/* =====================================================================
	 * Media: biblioteka WordPressa
	 * ===================================================================== */
	function renderMedia(box, url) {
		var kind = box.getAttribute('data-kind');
		var kadr = box.querySelector('.cft-kadr');
		if (kadr) {
			var ramka = kadr.querySelector('.cft-kadr__ramka');
			ramka.innerHTML = url ? '<img src="' + esc(url) + '" alt="" draggable="false"><button type="button" class="cft-kadr__pin" aria-label="Punkt ostrości: przeciągnij myszą albo przesuń strzałkami"></button>' : '<span class="cft-media__brak">Brak, sekcja będzie bez tego elementu</span>';
			kadr.cftReset();
			return;
		}
		var pod = box.querySelector('.cft-media__pod');
		pod.textContent = '';
		if (!url) {
			var s = document.createElement('span');
			s.className = 'cft-media__brak';
			s.textContent = 'Brak, sekcja będzie bez tego elementu';
			pod.appendChild(s);
			return;
		}
		var el;
		if (kind === 'video') {
			el = document.createElement('video');
			el.src = url + '#t=0.5';
			el.muted = true; el.controls = true; el.preload = 'metadata'; el.setAttribute('playsinline', '');
		} else {
			el = document.createElement('img');
			el.src = url; el.alt = '';
		}
		pod.appendChild(el);
	}

	function pickMedia(box) {
		var kind = box.getAttribute('data-kind');
		var frame = wp.media({
			title: kind === 'video' ? 'Wybierz film' : 'Wybierz zdjęcie',
			button: { text: 'Użyj tego' },
			library: { type: kind === 'video' ? 'video' : 'image' },
			multiple: false
		});
		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = att.url;
			if (kind !== 'video' && att.sizes && att.sizes.large) { url = att.sizes.large.url; }
			box.querySelector('.cft-media__val').value = att.id;
			renderMedia(box, url);
			markDirty();
			podgladMedia(box.getAttribute('data-key'), kind, att.url);
			kafelImg(box.getAttribute('data-key'), att.url);
		});
		frame.open();
	}

	form.addEventListener('click', function (e) {
		var btn = e.target.closest('.cft-media [data-act]');
		if (!btn) { return; }
		var box = btn.closest('.cft-media');
		var act = btn.getAttribute('data-act');
		var val = box.querySelector('.cft-media__val');
		if (act === 'wybierz') { pickMedia(box); }
		if (act === 'usun') { val.value = '-'; renderMedia(box, ''); markDirty(); kafelImg(box.getAttribute('data-key'), ''); podgladPrzeladuj(); }
		if (act === 'domyslne') { val.value = ''; renderMedia(box, box.getAttribute('data-default-url')); markDirty(); kafelImg(box.getAttribute('data-key'), box.getAttribute('data-default-url')); podgladPrzeladuj(); }
	});

	/* =====================================================================
	 * Listy: dodaj, przesuń, usuń, przywróć domyślną
	 * ===================================================================== */
	// 2.13.0: bez wyrażeń regularnych (dawny regex miał zgubiony ukośnik i zmieniał nazwy na ej[...][0][i], więc zmiany list
	// z panelu nie trafiały do zapisu). cftNumeruj() zamienia tylko numer wiersza; test: tools/test-tresci.mjs.
	/* test:numeruj-start */
	function cftNumeruj(s, przed, po, i) {
		if (typeof s !== 'string' || s.indexOf(przed) !== 0) { return s; }
		var reszta = s.slice(przed.length), k = reszta.indexOf(po);
		return k < 0 ? s : przed + i + reszta.slice(k);
	}
	/* test:numeruj-end */
	function renumber(lista) {
		var key = lista.getAttribute('data-lista');
		[].forEach.call(lista.querySelectorAll('.cft-wiersze > .cft-wiersz'), function (row, i) {
			row.querySelector('.cft-nr').textContent = i + 1;
			row.setAttribute('data-i', i);
			[].forEach.call(row.querySelectorAll('[name]'), function (el) { el.name = cftNumeruj(el.name, 'cft[' + key + '][', ']', i); });
			[].forEach.call(row.querySelectorAll('[data-key]'), function (el) { el.setAttribute('data-key', cftNumeruj(el.getAttribute('data-key'), key + '.', '.', i)); });
			['id', 'for', 'aria-labelledby'].forEach(function (a) {
				[].forEach.call(row.querySelectorAll('[' + a + ']'), function (el) { el.setAttribute(a, cftNumeruj(el.getAttribute(a), 'cft-' + key + '-', '-', i)); });
			});
		});
	}

	form.addEventListener('click', function (e) {
		var lista = e.target.closest('.cft-lista');
		if (!lista) { return; }
		var wiersze = lista.querySelector('.cft-wiersze');
		if (e.target.closest('[data-dodaj]')) {
			wiersze.appendChild(lista.querySelector('.cft-szablon').content.cloneNode(true));
			renumber(lista);
			var last = wiersze.lastElementChild;
			initEdytory(last); initKadry(last);
			var first = last.querySelector('input[type=text], .cft-ed');
			if (first) { first.focus(); }
			markDirty();
			return;
		}
		if (e.target.closest('[data-lista-domyslna]')) {
			if (!window.confirm('Przywrócić domyślną listę? Twoje zmiany na tej liście znikną z formularza (po zapisaniu).')) { return; }
			wiersze.textContent = '';
			wiersze.appendChild(lista.querySelector('.cft-domyslne').content.cloneNode(true));
			renumber(lista);
			initEdytory(wiersze); initKadry(wiersze);
			markDirty();
			podgladPrzeladuj();
			return;
		}
		var ruch = e.target.closest('[data-ruch]');
		if (!ruch) { return; }
		var row = ruch.closest('.cft-wiersz');
		var what = ruch.getAttribute('data-ruch');
		if (what === 'gora' && row.previousElementSibling) { wiersze.insertBefore(row, row.previousElementSibling); }
		if (what === 'dol' && row.nextElementSibling) { wiersze.insertBefore(row.nextElementSibling, row); }
		if (what === 'usun') {
			var txt = row.querySelector('input[type=text]');
			if (!window.confirm('Usunąć ' + (txt && txt.value ? '„' + txt.value.slice(0, 60) + '”' : 'ten element') + '?')) { return; }
			row.remove();
		}
		renumber(lista);
		markDirty();
		podgladPrzeladuj();
		if (what !== 'usun') { ruch.focus(); }
	});
	// A new row changes the page structure as soon as it has content: reload once it has something.
	form.addEventListener('change', function (e) {
		var row = e.target.closest('.cft-wiersz');
		if (row && !row.cftSeen) { row.cftSeen = true; podgladPrzeladuj(); }
	});

	/* =====================================================================
	 * Kafle sekcji: nagłówki i zdjęcia aktualizują się w trakcie pisania
	 * ===================================================================== */
	function kafelTxt(key, text) {
		[].forEach.call(wrap.querySelectorAll('[data-kafel-txt="' + key + '"]'), function (el) { el.textContent = text; });
	}
	function kafelImg(key, url) {
		[].forEach.call(wrap.querySelectorAll('[data-kafel-img="' + key + '"]'), function (el) { el.hidden = !url; if (url) { el.src = url; } });
	}

	/* =====================================================================
	 * Podgląd na żywo
	 * ===================================================================== */
	var aside = document.getElementById('cft-podglad');
	var iframe = aside.querySelector('iframe');
	var ramkaP = aside.querySelector('.cft-podglad__ramka');
	var info = aside.querySelector('.cft-podglad__info');
	var pierwszaStrona = Object.keys(CFG.strony)[0] || 'home';
	var strona = pierwszaStrona, urz = window.matchMedia('(max-width: 1199px)').matches ? 'telefon' : 'komputer', ladowanie = false, przeladujT = null, scrollPo = 0, podgladZaladowany = false;
	[].forEach.call(aside.querySelectorAll('[data-urz]'), function (x) { x.setAttribute('aria-pressed', x.getAttribute('data-urz') === urz ? 'true' : 'false'); });
	var STYL = '.cft-hl{outline:2px solid #B8955A!important;outline-offset:6px;box-shadow:0 0 0 6px rgba(255,252,244,.9);transition:outline-color .3s}' +
		'[data-cft-key]{cursor:pointer}#cookie-notice,.cookie-notice-container,#wpadminbar{display:none!important}html{margin-top:0!important;scroll-behavior:smooth}';

	function doc() { try { return iframe.contentDocument; } catch (e) { return null; } }
	function els(key) { var d = doc(); return d ? [].slice.call(d.querySelectorAll('[data-cft-key="' + key + '"]')) : []; }

	var SZER = { komputer: 1440, duzy: 1920, telefon: 390 }; // the same widths the crop ratios were measured at
	function uklad() {
		var w = ramkaP.clientWidth, h = ramkaP.clientHeight;
		if (!w || !h) { return; }
		var base = SZER[urz] || 1440;
		var s = urz === 'telefon' ? Math.min(1, w / base) : w / base; // komputer/duży ekran: always fill the column (no bands), telefon: narrow card centred
		iframe.style.width = base + 'px';
		iframe.style.height = Math.round(h / s) + 'px';
		iframe.style.transform = 'scale(' + s + ')';
		iframe.style.left = Math.round((w - base * s) / 2) + 'px';
	}
	if ('ResizeObserver' in window) { new ResizeObserver(uklad).observe(ramkaP); } else { window.addEventListener('resize', uklad); }

	function wyslijStan() {
		var fd = new FormData(form);
		fd.set('action', 'colorify_tresci_podglad');
		fd.set('nonce', CFG.nonce);
		return fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}
	function zaladuj(cel) {
		ladowanie = true;
		info.textContent = 'Wczytuję podgląd…';
		wyslijStan().catch(function () {}).then(function () {
			iframe.src = CFG.strony[strona] + '&t=' + Date.now() + (cel ? '#' + cel : '');
		});
	}
	iframe.addEventListener('load', function () {
		var d = doc();
		ladowanie = false;
		podgladZaladowany = true;
		if (!d || !d.body || d.location.href === 'about:blank') { return; }
		info.textContent = '';
		var st = d.createElement('style'); st.textContent = STYL; d.head.appendChild(st);
		if (scrollPo) { iframe.contentWindow.scrollTo(0, scrollPo); scrollPo = 0; }
		d.addEventListener('click', function (e) {
			var t = e.target.closest('[data-cft-key]');
			var a = e.target.closest('a');
			if (t) { e.preventDefault(); e.stopPropagation(); otworzPole(t.getAttribute('data-cft-key')); return; }
			if (a && !/^#/.test(a.getAttribute('href') || '#')) { e.preventDefault(); }
		}, true);
		d.addEventListener('submit', function (e) { e.preventDefault(); }, true);
		if (ostatnieFokus) { podswietl(ostatnieFokus, true); }
	});

	function podgladPrzeladuj(cel) {
		clearTimeout(przeladujT);
		przeladujT = setTimeout(function () {
			if (!widoczny()) { return; }
			try { scrollPo = typeof cel === 'string' ? 0 : iframe.contentWindow.scrollY; } catch (e) {}
			zaladuj(typeof cel === 'string' ? cel : '');
		}, 700);
	}
	function widoczny() { return wrap.classList.contains('cft--podglad') || window.matchMedia('(min-width: 1200px)').matches; }

	function ustawStrone(s, cel) {
		if (s === 'oba') { return; }
		[].forEach.call(aside.querySelectorAll('[data-strona]'), function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-strona') === s ? 'true' : 'false'); });
		aside.querySelector('.cft-podglad__nowa').href = (CFG.adresy && CFG.adresy[s]) || CFG.strony[s];
		if (s !== strona || !podgladZaladowany) { strona = s; zaladuj(cel); }
	}
	aside.addEventListener('click', function (e) {
		var b = e.target.closest('[data-strona], [data-urz]');
		if (!b) { return; }
		if (b.hasAttribute('data-strona')) { ustawStrone(b.getAttribute('data-strona')); }
		if (b.hasAttribute('data-urz')) {
			urz = b.getAttribute('data-urz');
			[].forEach.call(aside.querySelectorAll('[data-urz]'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			uklad();
		}
	});

	/* ----- panel wysuwany poniżej 1200 px ----- */
	function podgladOtworz(open) {
		wrap.classList.toggle('cft--podglad', open);
		document.body.classList.toggle('cft-podglad-otwarty', open);
		[].forEach.call(wrap.querySelectorAll('[data-podglad-otworz]'), function (b) { b.setAttribute('aria-expanded', open ? 'true' : 'false'); });
		if (open) { uklad(); if (!podgladZaladowany || dirty) { zaladuj(); } }
	}
	wrap.addEventListener('click', function (e) {
		if (e.target.closest('[data-podglad-otworz]')) { podgladOtworz(true); }
		if (e.target.closest('[data-podglad-zamknij]')) { podgladOtworz(false); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && wrap.classList.contains('cft--podglad')) { podgladOtworz(false); } });

	/* ----- podmiana w DOM podglądu (bez przeładowania) ----- */
	function podgladTekst(key, text) { els(key).forEach(function (el) { if (!/^(IMG|VIDEO)$/.test(el.tagName)) { el.textContent = text; } }); }
	function podgladHtml(key, html, multi) {
		els(key).forEach(function (el) {
			if (/^(IMG|VIDEO)$/.test(el.tagName)) { return; }
			if (multi) {
				var ps = html.split(/\n{2,}/);
				el.innerHTML = ps.map(function (p, i) { return '<p' + (i === ps.length - 1 ? ' class="mb-0"' : '') + '>' + p + '</p>'; }).join('');
			} else { el.innerHTML = html; }
		});
	}
	function podgladMedia(key, kind, url) {
		els(key).forEach(function (el) {
			if (el.tagName === 'IMG') { el.src = url; }
			if (el.tagName === 'VIDEO') { el.poster = url; }
		});
		var d = doc();
		if (d && kind === 'video') { [].forEach.call(d.querySelectorAll('[data-cft-film="' + key + '"]'), function (v) { v.src = url; v.load(); }); }
		if (kind !== 'video') { podgladKadr(key, null, null); }
	}
	function podgladKadr(key, k, def) {
		els(key).forEach(function (el) {
			if (!/^(IMG|VIDEO)$/.test(el.tagName)) { return; }
			el.style.objectPosition = ''; el.style.transform = ''; el.style.transformOrigin = ''; el.style.clipPath = '';
			if (k) { kadrApply(el, k, def); }
		});
	}
	form.addEventListener('input', function (e) {
		var el = e.target.closest('[data-key]');
		if (!el) { return; }
		var key = el.getAttribute('data-key');
		if (el.classList.contains('cft-ed')) {
			var html = zEdytora(el, el.closest('.cft-edytor').getAttribute('data-multi') === '1');
			podgladHtml(key, html, el.closest('.cft-edytor').getAttribute('data-multi') === '1');
			kafelTxt(key, el.textContent);
		} else {
			podgladTekst(key, el.value);
			kafelTxt(key, el.value);
		}
	});

	/* ----- fokus w panelu → przewiń i podświetl w podglądzie; klik w podglądzie → pole ----- */
	var ostatnieFokus = null, fokusT = null;
	function podswietl(key, cicho) {
		var d = doc();
		if (!d) { return; }
		[].forEach.call(d.querySelectorAll('.cft-hl'), function (x) { x.classList.remove('cft-hl'); });
		var list = els(key);
		var cel = list[0];
		if (!cel) {
			var s = sekcjaPola(key), kot = s && CFG.sekcje[s] ? CFG.sekcje[s].kotwica : '';
			cel = kot ? d.querySelector(kot) : null;
			if (cel) { cel.scrollIntoView({ block: 'start', behavior: cicho ? 'auto' : 'smooth' }); }
			return;
		}
		list.forEach(function (x) { x.classList.add('cft-hl'); });
		cel.scrollIntoView({ block: 'center', behavior: cicho ? 'auto' : 'smooth' });
	}
	function podgladSekcja(sid) {
		var sec = CFG.sekcje[sid];
		if (!sec || !widoczny()) { return; }
		if (sec.strona !== 'oba' && sec.strona !== strona) { ustawStrone(sec.strona); }
		var d = doc();
		if (d && sec.kotwica && !ladowanie) { var c = d.querySelector(sec.kotwica); if (c) { c.scrollIntoView({ block: 'start', behavior: 'smooth' }); } }
	}
	form.addEventListener('focusin', function (e) {
		var el = e.target.closest('[data-key]') || e.target.closest('.cft-media[data-key]');
		if (!el) { return; }
		var key = el.getAttribute('data-key');
		ostatnieFokus = key;
		if (!widoczny()) { return; }
		var st = stronaPola(key);
		if (st !== 'oba' && st !== strona) { ustawStrone(st); return; }
		clearTimeout(fokusT);
		fokusT = setTimeout(function () { if (!ladowanie) { podswietl(key); } }, 120);
	});
	function otworzPole(key) {
		var el = form.querySelector('[data-key="' + key + '"]') || form.querySelector('[data-key="' + baseKey(key) + '"]');
		if (!el) { return; }
		var karta = el.closest('details.cft-karta');
		if (karta && !karta.open) { karta.open = true; }
		if (window.matchMedia('(max-width: 1199px)').matches) { podgladOtworz(false); }
		setTimeout(function () {
			el.scrollIntoView({ block: 'center', behavior: 'smooth' });
			var f = el.matches('input, textarea, [contenteditable]') ? el : el.querySelector('.cft-kadr__ramka, button');
			if (f) { f.focus({ preventScroll: true }); }
			el.classList.add('is-wskazane');
			setTimeout(function () { el.classList.remove('is-wskazane'); }, 1600);
		}, 60);
	}

	/* ----- start podglądu na szerokim ekranie ----- */
	if (window.matchMedia('(min-width: 1200px)').matches) {
		var pierwsza = karty.filter(function (k) { return k.open; })[0];
		var s0 = pierwsza && CFG.sekcje[pierwsza.getAttribute('data-karta')] ? CFG.sekcje[pierwsza.getAttribute('data-karta')].strona : pierwszaStrona;
		uklad();
		ustawStrone(s0 === 'oba' || !CFG.strony[s0] ? pierwszaStrona : s0);
	}

	/* =====================================================================
	 * Bramka wersji: co 60 s (i po powrocie do karty) sprawdź, czy ktoś (np. AI) nie zmienił treści poza panelem
	 * ===================================================================== */
	var wersjaEl = document.getElementById('cft-wersja');
	var obce = document.getElementById('cft-obce');
	function sprawdzWersje() {
		if (!wersjaEl || document.hidden) { return; }
		fetch(CFG.ajax + '?action=colorify_tresci_wersja', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
			if (j && j.success && j.data && j.data.w && j.data.w !== wersjaEl.value) { obce.hidden = false; }
		}).catch(function () {});
	}
	setInterval(sprawdzWersje, 60000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { sprawdzWersje(); } });
	window.addEventListener('focus', sprawdzWersje);
	if (obce) {
		obce.querySelector('a').addEventListener('click', function (e) {
			if (dirty && !window.confirm('Masz niezapisane zmiany. Odświeżyć i je odrzucić?')) { e.preventDefault(); return; }
			dirty = false;
		});
	}

	/* =====================================================================
	 * 2.13.0: wygląd tekstu (tokeny), układ strony, nowe sekcje, akapity
	 * ===================================================================== */
	var IK = CFG.ik || {}, TOK = CFG.tok || {}, WL = CFG.wl || {};
	var STY = CFG.style && !Array.isArray(CFG.style) ? CFG.style : {};
	var UK = CFG.uklad && !Array.isArray(CFG.uklad) ? CFG.uklad : {};
	var NOWE = CFG.nowe && !Array.isArray(CFG.nowe) ? CFG.nowe : {};
	var USUNIETE = {};
	var styleIn = document.getElementById('cft-style'), ukladIn = document.getElementById('cft-uklad-dane');
	function ik(n) { return '<svg class="cft-ik" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + (IK[n] || '') + '</svg>'; }
	function losowe(n) {
		var a = new Uint8Array(n), s = '', z = 'abcdefghijklmnopqrstuvwxyz0123456789';
		(window.crypto || window.msCrypto).getRandomValues(a);
		for (var i = 0; i < n; i++) { s += z[a[i] % 36]; }
		return s;
	}
	function btnIk(nazwa, akcja, opis, extra) { return '<button type="button" class="cft-ikona" ' + akcja + ' aria-label="' + esc(opis) + '" data-tip="' + esc(opis) + '"' + (extra || '') + '>' + ik(nazwa) + '</button>'; }
	Object.keys(NOWE).forEach(function (id) { CFG.pola[id] = { s: id, t: 'sekcja' }; CFG.sekcje[id] = { strona: NOWE[id].strona, kotwica: '[data-cft-sekcja="' + id + '"]' }; });

	/* ----- wygląd: stan, zmienne CSS (te same co w PHP: colorify_tresci_styl_vars) ----- */
	var NAZWY = Object.keys(WL).concat(['mi']);
	function cssTok(p, t) {
		if (p === 'lh') { var v = Math.round(Math.max(1, Math.min(2.2, +t)) * 20) / 20; return isNaN(v) ? '' : String(v); }
		return TOK[p] && TOK[p][t] ? TOK[p][t][1] : '';
	}
	function styVars(cel) {
		var s = STY[cel] || {}, b = s.b || {}, m = s.m || {}, v = {};
		Object.keys(b).forEach(function (p) { var c = cssTok(p, b[p]); if (c) { v[p] = c; } });
		Object.keys(m).forEach(function (p) { var c = cssTok(p, m[p]); if (c) { v[p + '-m'] = c; } });
		if (b.ta === 'justify' && !m.ta) { v['ta-m'] = 'left'; }
		if ((b.mw || m.mw) && b.ta === 'center') { v.mi = 'auto'; }
		if (m.mw && m.ta === 'center') { v['mi-m'] = 'auto'; }
		return v;
	}
	function styEls(cel) {
		var d = doc();
		if (!d) { return []; }
		return [].slice.call(cel.charAt(0) === '#' ? d.querySelectorAll('[data-cft-sekcja="' + cel.slice(1) + '"]') : d.querySelectorAll('[data-cft-key="' + cel + '"]')).filter(function (el) { return !/^(IMG|VIDEO)$/.test(el.tagName); });
	}
	function styZastosuj(cel) {
		var v = styVars(cel);
		styEls(cel).forEach(function (el) {
			NAZWY.forEach(function (n) {
				['', '-m'].forEach(function (s) { var k = n + s; if (v[k] !== undefined) { el.style.setProperty('--cft-' + k, v[k]); } else { el.style.removeProperty('--cft-' + k); } });
			});
		});
	}
	function styZapisz() {
		Object.keys(STY).forEach(function (c) {
			['b', 'm'].forEach(function (d) { if (STY[c][d] && !Object.keys(STY[c][d]).length) { delete STY[c][d]; } });
			if (!STY[c].b && !STY[c].m) { delete STY[c]; }
		});
		if (styleIn) { styleIn.value = JSON.stringify(STY); }
		markDirty();
		styOznacz();
	}
	function styOznacz() {
		[].forEach.call(wrap.querySelectorAll('[data-styl]'), function (b) { b.classList.toggle('is-zmienione', !!STY[b.getAttribute('data-styl')]); });
	}
	function styUstaw(cel, dev, p, t) {
		STY[cel] = STY[cel] || {};
		STY[cel][dev] = STY[cel][dev] || {};
		if (t === null || t === undefined || t === '') { delete STY[cel][dev][p]; } else { STY[cel][dev][p] = String(t); }
		styZapisz();
		styZastosuj(cel);
	}
	styOznacz();

	/* ----- edytor wyglądu (jeden otwarty naraz, pod przyciskiem "Wygląd") ----- */
	var otwartyPanel = null;
	function pokazUrz(dev) {
		urz = dev === 'm' ? 'telefon' : 'komputer';
		[].forEach.call(aside.querySelectorAll('[data-urz]'), function (x) { x.setAttribute('aria-pressed', x.getAttribute('data-urz') === urz ? 'true' : 'false'); });
		uklad();
	}
	function czyNaglowkowy(cel, dev) {
		var s = STY[cel] || {}, f = (s[dev] && s[dev].ff) || (s.b && s.b.ff);
		if (f) { return f === 'heading'; }
		var el = styEls(cel)[0];
		try { return !!el && /gilda|serif/i.test(iframe.contentWindow.getComputedStyle(el).fontFamily) && !/sans/i.test(iframe.contentWindow.getComputedStyle(el).fontFamily); } catch (e) { return false; }
	}
	function wierszHtml(cel, dev, p) {
		var s = STY[cel] || {}, cur = s[dev] && s[dev][p], dzied = dev === 'm' && !cur && s.b ? s.b[p] : undefined;
		var nazwa = WL[p][0], opcje = '';
		if (p === 'lh') {
			var val = cur || dzied || '1.5';
			opcje = '<input type="range" min="1" max="2.2" step="0.05" value="' + esc(val) + '" data-sp-lh aria-label="Interlinia"><output>' + (cur ? String(cur).replace('.', ',') : (dzied ? 'jak na komputerze' : 'z projektu')) + '</output>';
		} else {
			var gilda = p === 'fw' && czyNaglowkowy(cel, dev);
			Object.keys(TOK[p] || {}).forEach(function (t) {
				var d = TOK[p][t], wcisk = String(cur) === t, styl = '', tresc = esc(d[0]), off = '';
				if (p === 'c') { tresc = '<span class="cft-sp__kolor" data-colorify-swatch style="background:' + esc(d[1]) + '"></span><span class="screen-reader-text">' + esc(d[0]) + '</span>'; }
				if (p === 'ta') { tresc = ik({ left: 'lewo', center: 'srodek', right: 'prawo', justify: 'justuj' }[t]) + '<span class="screen-reader-text">' + esc(d[0]) + '</span>'; }
				if (p === 'ff') { styl = ' style="font-family:' + (t === 'heading' ? 'var(--cft-font-h)' : 'var(--cft-font)') + '"'; }
				if (p === 'fw') { styl = ' style="font-weight:' + esc(d[1]) + '"'; if (gilda && t !== '400') { off = ' disabled'; } }
				if (p === 'ta' && t === 'justify' && dev === 'm') { off = ' disabled'; }
				var tip = off ? (p === 'fw' ? 'Krój nagłówkowy ma tylko jedną grubość' : 'Na telefonie justowanie robi dziury między słowami') : d[0] + (String(dzied) === t ? ' (tak jak na komputerze)' : '');
				opcje += '<button type="button" class="cft-sp__o' + (p === 'c' || p === 'ta' ? ' cft-sp__o--ik' : '') + (String(dzied) === t ? ' is-dzied' : '') + '" data-sp-t="' + esc(t) + '" aria-pressed="' + (wcisk ? 'true' : 'false') + '" data-tip="' + esc(tip) + '"' + styl + off + '>' + tresc + '</button>';
			});
		}
		return '<div class="cft-sp__w' + (cur ? ' is-ust' : '') + '" data-p="' + p + '"><span class="cft-sp__n" id="sp-' + p + '">' + esc(nazwa) + (cur ? '<span class="cft-sp__zm" aria-label="zmienione"></span>' : '') + '</span>'
			+ '<div class="cft-sp__opcje" role="group" aria-labelledby="sp-' + p + '">' + opcje + '</div>'
			+ (cur ? btnIk('reset', 'data-sp-reset', 'Przywróć z projektu strony: ' + nazwa.toLowerCase()) : '<span class="cft-sp__pusto"></span>') + '</div>';
	}
	function panelRysuj(pan) {
		var cel = pan.cftCel, dev = pan.cftDev, sekcja = cel.charAt(0) === '#';
		var props = Object.keys(WL).filter(function (p) { return sekcja ? WL[p][2] === 's' : WL[p][2] !== 's'; });
		var tylko = pan.cftTylko, s = STY[cel] || {}, ile = 0;
		var w1 = '', w2 = '';
		props.forEach(function (p) {
			var ust = s[dev] && s[dev][p] !== undefined;
			if (ust) { ile++; }
			if (tylko && !ust) { return; }
			if (WL[p][2] === 2) { w2 += wierszHtml(cel, dev, p); } else { w1 += wierszHtml(cel, dev, p); }
		});
		pan.innerHTML = '<div class="cft-sp__gora"><span class="cft-przelacz" role="group" aria-label="Dla którego ekranu">'
			+ '<button type="button" data-sp-urz="b" aria-pressed="' + (dev === 'b') + '">' + ik('komputer') + 'Komputer</button>'
			+ '<button type="button" data-sp-urz="m" aria-pressed="' + (dev === 'm') + '">' + ik('telefon') + 'Telefon</button></span>'
			+ '<label class="cft-sp__tylko"><input type="checkbox" data-sp-tylko' + (tylko ? ' checked' : '') + '> Tylko zmienione</label>'
			+ btnIk('zamknij', 'data-sp-zamknij', 'Zamknij ustawienia wyglądu') + '</div>'
			+ '<p class="cft-sp__info">' + (dev === 'm' ? 'Telefon: ustaw tylko to, co ma być inne niż na komputerze. Przerywana ramka = tak jak na komputerze.' : (sekcja ? 'Odstęp nad i pod całą sekcją.' : 'Zmiana od razu w podglądzie. Kółko ze strzałką wraca do wyglądu z projektu.')) + '</p>'
			+ (w1 || (tylko && !w2 ? '<p class="cft-sp__info">Nic tu jeszcze nie zmieniono.</p>' : ''))
			+ (sekcja || (!w2 && tylko) ? '' : '<details class="cft-sp__wiecej"' + (pan.cftWiecej || tylko ? ' open' : '') + '><summary>Więcej ustawień: interlinia, odstęp liter, szerokość, wielkie litery, kursywa</summary>' + w2 + '</details>')
			+ (ile || (s.b || s.m) ? '<button type="button" class="cft-btn cft-btn--link cft-sp__wszystko" data-sp-reset-all>' + ik('reset') + 'Przywróć cały wygląd z projektu</button>' : '');
		var det = pan.querySelector('.cft-sp__wiecej');
		if (det) { det.addEventListener('toggle', function () { pan.cftWiecej = det.open; }); }
	}
	function panelOtworz(btn) {
		var cel = btn.getAttribute('data-styl');
		if (otwartyPanel) {
			var bylo = otwartyPanel.cftBtn;
			otwartyPanel.remove();
			if (bylo) { bylo.setAttribute('aria-expanded', 'false'); }
			otwartyPanel = null;
			if (bylo === btn) { return; }
		}
		var pan = document.createElement('div');
		pan.className = 'cft-sp';
		pan.setAttribute('role', 'region');
		pan.setAttribute('aria-label', btn.getAttribute('aria-label') || 'Wygląd');
		pan.cftCel = cel; pan.cftDev = urz === 'telefon' ? 'm' : 'b'; pan.cftBtn = btn;
		var host = btn.closest('.cft-akapit, .cft-nav__w, .cft-sek-pasek, .cft-pole') || btn.parentNode;
		if (host.classList.contains('cft-nav__w') || host.classList.contains('cft-sek-pasek')) { host.after(pan); } else { host.appendChild(pan); }
		btn.setAttribute('aria-expanded', 'true');
		otwartyPanel = pan;
		panelRysuj(pan);
		if (!styEls(cel).length && widoczny() && !ladowanie) {
			var n = document.createElement('p');
			n.className = 'cft-sp__info cft-sp__info--uwaga';
			n.textContent = 'Tego tekstu nie widać teraz w podglądzie (jest pusty albo na innej podstronie). Ustawienia i tak się zapiszą.';
			pan.insertBefore(n, pan.children[1]);
		}
		podswietl(cel.charAt(0) === '#' ? cel.slice(1) : cel);
	}
	wrap.addEventListener('click', function (e) {
		var b = e.target.closest('[data-styl]');
		if (b) { e.preventDefault(); panelOtworz(b); return; }
		var pan = e.target.closest('.cft-sp');
		if (!pan) { return; }
		var cel = pan.cftCel, dev = pan.cftDev;
		if (e.target.closest('[data-sp-zamknij]')) { pan.cftBtn.setAttribute('aria-expanded', 'false'); pan.cftBtn.focus(); pan.remove(); otwartyPanel = null; return; }
		var u = e.target.closest('[data-sp-urz]');
		if (u) { pan.cftDev = u.getAttribute('data-sp-urz'); pokazUrz(pan.cftDev); panelRysuj(pan); pan.querySelector('[data-sp-urz="' + pan.cftDev + '"]').focus(); return; }
		if (e.target.closest('[data-sp-reset-all]')) {
			if (!window.confirm('Przywrócić cały wygląd tego elementu z projektu strony (komputer i telefon)?')) { return; }
			delete STY[cel]; styZapisz(); styZastosuj(cel); panelRysuj(pan); return;
		}
		var w = e.target.closest('.cft-sp__w');
		if (!w) { return; }
		var p = w.getAttribute('data-p');
		if (e.target.closest('[data-sp-reset]')) { styUstaw(cel, dev, p, null); panelRysuj(pan); var x = pan.querySelector('.cft-sp__w[data-p="' + p + '"] .cft-sp__o'); if (x) { x.focus(); } return; }
		var o = e.target.closest('[data-sp-t]');
		if (o && !o.disabled) {
			var t = o.getAttribute('data-sp-t'), cur = STY[cel] && STY[cel][dev] && STY[cel][dev][p];
			styUstaw(cel, dev, p, String(cur) === t ? null : t); // drugi klik w to samo = z projektu
			if (p === 'mw' && !(STY[cel].b && STY[cel].b.ta) && styEls(cel)[0]) { // szerokość: zapamiętaj obecne wyrównanie, żeby blok stał tam, gdzie tekst
				try { var ta = iframe.contentWindow.getComputedStyle(styEls(cel)[0]).textAlign; if (ta === 'center') { styUstaw(cel, 'b', 'ta', 'center'); } } catch (er) {}
			}
			panelRysuj(pan);
			var f = pan.querySelector('.cft-sp__w[data-p="' + p + '"] [data-sp-t="' + t + '"]');
			if (f) { f.focus(); }
		}
	});
	wrap.addEventListener('input', function (e) {
		var r = e.target.closest('[data-sp-lh]');
		if (!r) { return; }
		var pan = r.closest('.cft-sp');
		styUstaw(pan.cftCel, pan.cftDev, 'lh', r.value);
		r.nextElementSibling.textContent = String(r.value).replace('.', ',');
		var w = r.closest('.cft-sp__w');
		w.classList.add('is-ust');
	});
	wrap.addEventListener('change', function (e) {
		if (e.target.closest('[data-sp-lh]')) { panelRysuj(e.target.closest('.cft-sp')); var r = otwartyPanel && otwartyPanel.querySelector('[data-sp-lh]'); if (r) { r.focus(); } }
		var t = e.target.closest('[data-sp-tylko]');
		if (t) { var pan = t.closest('.cft-sp'); pan.cftTylko = t.checked; panelRysuj(pan); }
	});
	// Styl przeżywa przeładowanie podglądu (serwer drukuje zmienne z transientu), ale po każdym load nakładamy go jeszcze raz.
	iframe.addEventListener('load', function () { Object.keys(STY).forEach(styZastosuj); });

	/* ----- układ strony: nawigator, przyciski w kartach, biblioteka nowych sekcji ----- */
	function defSek(pid, id) { return ((UK[pid] && UK[pid].def) || []).filter(function (x) { return x.id === id; })[0] || null; }
	function kartaId(pid, id) { var d = defSek(pid, id); return d ? d.karta : id; }
	function nazwaSek(pid, id) {
		var d = defSek(pid, id);
		if (d) { return d.label; }
		var n = NOWE[id], t = n && CFG.typy[n.typ], h = form.querySelector('[data-key="' + id + '.naglowek"]');
		return (t ? t.nazwa : 'Sekcja') + (h && h.value ? ': ' + h.value : '');
	}
	function widoczneO(pid) { return UK[pid].o.filter(function (id) { return !USUNIETE[id]; }); }
	function ukladZapisz() {
		var out = {};
		Object.keys(UK).forEach(function (pid) { out[pid] = { o: widoczneO(pid), h: UK[pid].h.filter(function (id) { return !USUNIETE[id]; }) }; });
		if (ukladIn) { ukladIn.value = JSON.stringify(out); }
	}
	function akcjeHtml(pid, id) {
		var d = defSek(pid, id), o = UK[pid].o, i = o.indexOf(id), ukr = UK[pid].h.indexOf(id) > -1, nazwa = nazwaSek(pid, id);
		if (USUNIETE[id]) { return '<button type="button" class="cft-btn cft-btn--link" data-nav="przywroc">' + ik('reset') + 'Cofnij usunięcie</button>'; }
		if (d && d.stala) { return '<span class="cft-nav__lock" tabindex="0" data-tip="Ta sekcja zawsze jest na górze strony" aria-label="Sekcja na stałe na górze">' + ik('klodka') + '</span>' + btnIk('odstepy', 'data-styl="#' + id + '"', 'Odstępy sekcji: ' + nazwa); }
		var pierwsza = o.filter(function (x) { var dx = defSek(pid, x); return !(dx && dx.stala) && !USUNIETE[x]; })[0];
		var ost = widoczneO(pid).slice(-1)[0];
		return btnIk('gora', 'data-nav="gora"', 'Przesuń wyżej', id === pierwsza ? ' disabled' : '')
			+ btnIk('dol', 'data-nav="dol"', 'Przesuń niżej', id === ost ? ' disabled' : '')
			+ btnIk(ukr ? 'oko_nie' : 'oko', 'data-nav="ukryj" aria-pressed="' + ukr + '"', ukr ? 'Pokaż na stronie (teraz ukryta)' : 'Ukryj na stronie (dane zostają)')
			+ btnIk('odstepy', 'data-styl="#' + id + '"', 'Odstępy sekcji: ' + nazwa)
			+ (d ? '' : btnIk('kopia', 'data-nav="kopia"', 'Duplikuj sekcję') + btnIk('kosz', 'data-nav="usun" data-czerwony', 'Usuń sekcję'));
	}
	function navRysuj(pid) {
		var box = form.querySelector('.cft-nawigator[data-strona="' + pid + '"]');
		if (!box || !UK[pid]) { return; }
		var html = '<div class="cft-nav__gora"><h3 class="cft-nav__t">' + ik('uklad') + 'Układ strony</h3><p class="cft-nav__p">Sekcje od góry strony. Strzałki zmieniają kolejność, oko ukrywa, plus dodaje nową sekcję w tym miejscu.</p></div><ol class="cft-nav__lista">';
		UK[pid].o.forEach(function (id) {
			var d = defSek(pid, id), ukr = UK[pid].h.indexOf(id) > -1, n = NOWE[id], typ = n && CFG.typy[n.typ];
			html += '<li class="cft-nav__w' + (ukr ? ' is-ukryta' : '') + (d ? '' : ' is-nowa') + (USUNIETE[id] ? ' is-usunieta' : '') + '" data-id="' + esc(id) + '" data-strona="' + esc(pid) + '">'
				+ '<button type="button" class="cft-nav__nazwa" data-nav="otworz">' + ik(typ ? typ.ikona : 'uklad') + '<span class="cft-nav__nt">' + esc(nazwaSek(pid, id)) + '</span>'
				+ (ukr ? '<span class="cft-nav__znak">Ukryta</span>' : '') + (d ? '' : '<span class="cft-nav__znak cft-nav__znak--nowa">' + (USUNIETE[id] ? 'Usunięta po zapisie' : 'Nowa') + '</span>') + '</button>'
				+ '<span class="cft-nav__akcje">' + akcjeHtml(pid, id) + '</span></li>'
				+ (USUNIETE[id] ? '' : '<li class="cft-nav__miedzy" data-po="' + esc(id) + '" data-strona="' + esc(pid) + '"><button type="button" class="cft-nav__dodaj" data-nav="dodaj" aria-expanded="false" aria-label="Dodaj nową sekcję pod: ' + esc(nazwaSek(pid, id)) + '">' + ik('plus') + '<span>Dodaj sekcję tutaj</span></button></li>');
		});
		box.innerHTML = html + '</ol>';
		// pasek sekcji w jej karcie (to samo, co w nawigatorze)
		UK[pid].o.forEach(function (id) {
			var karta = document.getElementById('cft-karta-' + kartaId(pid, id));
			if (!karta || (defSek(pid, id) && kartaId(pid, id) !== id && UK[pid].o.indexOf(kartaId(pid, id)) > -1)) { return; }
			var tr = karta.querySelector('.cft-karta__tresc'), pas = tr.querySelector(':scope > .cft-sek-pasek');
			if (!pas) { pas = document.createElement('div'); pas.className = 'cft-sek-pasek'; tr.insertBefore(pas, tr.firstChild); }
			pas.setAttribute('data-id', id); pas.setAttribute('data-strona', pid);
			pas.innerHTML = '<span class="cft-sek-pasek__t">' + ik('uklad') + 'Ta sekcja na stronie</span><span class="cft-nav__akcje">' + akcjeHtml(pid, id) + '</span>';
			karta.classList.toggle('is-ukryta', UK[pid].h.indexOf(id) > -1);
			karta.classList.toggle('is-usunieta', !!USUNIETE[id]);
		});
		styOznacz();
	}
	function kartyPrzestaw(pid) {
		var box = form.querySelector('.cft-karty[data-strona="' + pid + '"]');
		if (!box) { return; }
		var po = [];
		UK[pid].o.forEach(function (id) { var k = document.getElementById('cft-karta-' + kartaId(pid, id)); if (k && k.parentNode === box && po.indexOf(k) < 0) { po.push(k); } });
		[].forEach.call(box.children, function (k) { if (po.indexOf(k) < 0) { po.push(k); } });
		po.forEach(function (k) { box.appendChild(k); });
	}
	function ukladZmiana(pid, cel) {
		ukladZapisz(); kartyPrzestaw(pid); navRysuj(pid); markDirty(); podgladPrzeladuj(cel);
		if (strona !== pid && widoczny()) { ustawStrone(pid); }
	}
	function przesun(pid, id, kier) {
		var o = UK[pid].o, i = o.indexOf(id), j = i + kier;
		while (o[j] && USUNIETE[o[j]]) { j += kier; }
		var dj = o[j] && defSek(pid, o[j]);
		if (j < 0 || j >= o.length || (dj && dj.stala)) { return; }
		o.splice(i, 1); o.splice(j, 0, id);
	}
	var bibl = null;
	function biblZamknij() { if (bibl) { bibl.cftBtn.setAttribute('aria-expanded', 'false'); bibl.remove(); bibl = null; } }
	function biblOtworz(btn, pid, po) {
		var bylo = bibl && bibl.cftBtn === btn;
		biblZamknij();
		if (bylo) { return; }
		bibl = document.createElement('div');
		bibl.className = 'cft-bibl';
		bibl.cftBtn = btn;
		var h = '<p class="cft-bibl__t">Jaką sekcję dodać?</p><div class="cft-bibl__siatka">';
		Object.keys(CFG.typy).forEach(function (t) { var d = CFG.typy[t]; h += '<button type="button" class="cft-bibl__typ" data-typ="' + esc(t) + '">' + ik(d.ikona) + '<span><strong>' + esc(d.nazwa) + '</strong><small>' + esc(d.opis) + '</small></span></button>'; });
		bibl.innerHTML = h + '</div>' + '<button type="button" class="cft-btn cft-btn--link" data-bibl-zamknij>Anuluj</button>';
		bibl.cftPid = pid; bibl.cftPo = po;
		btn.closest('li').appendChild(bibl);
		btn.setAttribute('aria-expanded', 'true');
		bibl.querySelector('.cft-bibl__typ').focus();
	}
	function initKarta(k) { initEdytory(k); initKadry(k); }
	function dodajSekcje(pid, po, typ) {
		var tpl = form.querySelector('.cft-szablon-sekcji[data-typ="' + typ + '"]');
		if (!tpl) { return; }
		var id = 's-' + losowe(8), box = form.querySelector('.cft-karty[data-strona="' + pid + '"]');
		var tmp = document.createElement('div');
		tmp.innerHTML = tpl.innerHTML.split('s-__id__').join(id).split('__strona__').join(pid);
		var karta = tmp.firstElementChild;
		box.appendChild(karta);
		NOWE[id] = { typ: typ, strona: pid };
		CFG.pola[id] = { s: id, t: 'sekcja' };
		CFG.sekcje[id] = { strona: pid, kotwica: '[data-cft-sekcja="' + id + '"]' };
		var o = UK[pid].o;
		o.splice(o.indexOf(po) + 1, 0, id);
		initKarta(karta);
		karta.open = true;
		ukladZmiana(pid, id);
		setTimeout(function () { karta.scrollIntoView({ block: 'start', behavior: 'smooth' }); var f = karta.querySelector('.cft-karta__tresc input[type=text], .cft-ed'); if (f) { f.focus({ preventScroll: true }); } }, 80);
	}
	function synchronizujWartosci(root) { // przed klonowaniem przez HTML: wartości pól jako atrybuty
		[].forEach.call(root.querySelectorAll('input'), function (el) { if (el.type === 'checkbox') { if (el.checked) { el.setAttribute('checked', ''); } else { el.removeAttribute('checked'); } } else { el.setAttribute('value', el.value); } });
		[].forEach.call(root.querySelectorAll('textarea'), function (el) { el.textContent = el.value; });
		[].forEach.call(root.querySelectorAll('select'), function (el) { [].forEach.call(el.options, function (op) { if (op.selected) { op.setAttribute('selected', ''); } else { op.removeAttribute('selected'); } }); });
	}
	function duplikuj(pid, id) {
		var src = document.getElementById('cft-karta-' + id);
		if (!src) { return; }
		var nid = 's-' + losowe(8);
		synchronizujWartosci(src);
		var tmp = document.createElement('div');
		tmp.innerHTML = src.outerHTML.split(id).join(nid);
		var karta = tmp.firstElementChild;
		[].forEach.call(karta.querySelectorAll('.cft-sp'), function (x) { x.remove(); });
		src.after(karta);
		NOWE[nid] = { typ: NOWE[id].typ, strona: pid };
		CFG.pola[nid] = { s: nid, t: 'sekcja' };
		CFG.sekcje[nid] = { strona: pid, kotwica: '[data-cft-sekcja="' + nid + '"]' };
		Object.keys(STY).forEach(function (c) { if (c.indexOf(id) === 0 || c.indexOf('#' + id) === 0) { STY[c.split(id).join(nid)] = JSON.parse(JSON.stringify(STY[c])); } });
		styZapisz();
		[].forEach.call(karta.querySelectorAll('.cft-ed'), function (ed) { ed.cftInit = false; });
		initKarta(karta);
		var o = UK[pid].o;
		o.splice(o.indexOf(id) + 1, 0, nid);
		ukladZmiana(pid, nid);
	}
	function usunSekcje(pid, id, cofnij) {
		var karta = document.getElementById('cft-karta-' + id);
		if (!cofnij && !window.confirm('Usunąć sekcję „' + nazwaSek(pid, id) + '”? Zniknie ze strony po „Zapisz zmiany”. Do tego czasu możesz to cofnąć, a po zapisie wrócić do wcześniejszej wersji w Historii zmian.')) { return; }
		if (cofnij) { delete USUNIETE[id]; } else { USUNIETE[id] = true; }
		if (karta) { var u = karta.querySelector('[data-usun-sekcji]'); if (u) { u.value = cofnij ? '' : '1'; } if (!cofnij) { karta.open = false; } }
		ukladZmiana(pid);
	}
	wrap.addEventListener('click', function (e) {
		var b = e.target.closest('[data-nav]'), z = e.target.closest('[data-bibl-zamknij]'), t = e.target.closest('.cft-bibl__typ');
		if (z) { var bb = bibl && bibl.cftBtn; biblZamknij(); if (bb) { bb.focus(); } return; }
		if (t && bibl) { var pid0 = bibl.cftPid, po0 = bibl.cftPo; biblZamknij(); dodajSekcje(pid0, po0, t.getAttribute('data-typ')); return; }
		if (!b || b.disabled) { return; }
		var w = b.closest('[data-strona]'), pid = w.getAttribute('data-strona'), id = w.getAttribute('data-id') || w.getAttribute('data-po'), co = b.getAttribute('data-nav');
		if (co === 'dodaj') { biblOtworz(b, pid, id); return; }
		if (co === 'otworz') {
			var k = document.getElementById('cft-karta-' + kartaId(pid, id));
			if (k) { k.open = true; k.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
			if (widoczny()) { if (strona !== pid) { ustawStrone(pid, id); } else { var d = doc(), c = d && d.querySelector('[data-cft-sekcja="' + id + '"]'); if (c) { c.scrollIntoView({ block: 'start', behavior: 'smooth' }); } } }
			return;
		}
		if (co === 'gora' || co === 'dol') { przesun(pid, id, co === 'gora' ? -1 : 1); ukladZmiana(pid); var nb = wrap.querySelector('[data-strona="' + pid + '"][data-id="' + id + '"] [data-nav="' + co + '"]'); if (nb && !nb.disabled) { nb.focus(); } else { var nb2 = wrap.querySelector('[data-strona="' + pid + '"][data-id="' + id + '"] [data-nav]'); if (nb2) { nb2.focus(); } } return; }
		if (co === 'ukryj') {
			var h = UK[pid].h, i = h.indexOf(id), d0 = defSek(pid, id);
			if (i < 0 && d0 && d0.kotwica && !window.confirm('Menu albo przyciski na stronie prowadzą do tej sekcji (#' + d0.kotwica + '). Po ukryciu te linki nie będą miały dokąd przewinąć. Ukryć mimo to?')) { return; }
			if (i < 0) { h.push(id); } else { h.splice(i, 1); }
			ukladZmiana(pid);
			var ub = wrap.querySelector('.cft-nav__w[data-id="' + id + '"] [data-nav="ukryj"]'); if (ub) { ub.focus(); }
			return;
		}
		if (co === 'kopia') { duplikuj(pid, id); return; }
		if (co === 'usun') { usunSekcje(pid, id, false); return; }
		if (co === 'przywroc') { usunSekcje(pid, id, true); }
	});
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && bibl) { var bb = bibl.cftBtn; biblZamknij(); bb.focus(); } });
	Object.keys(UK).forEach(function (pid) { navRysuj(pid); });
	ukladZapisz();

	/* ----- akapity: dodaj, usuń, przesuń, duplikuj ----- */
	function akapitNowy(box, w, html) {
		var tpl = box.querySelector(':scope > .cft-akapit-szablon'), pid = 'p-' + losowe(8);
		var tmp = document.createElement('div');
		tmp.innerHTML = tpl.innerHTML.split('p-__p__').join(pid);
		var li = tmp.firstElementChild;
		if (w) { li.querySelector('select').value = w; }
		if (html) { li.querySelector('.cft-src').value = html; }
		initEdytory(li);
		return li;
	}
	wrap.addEventListener('click', function (e) {
		var box = e.target.closest('.cft-akapity');
		if (!box) { return; }
		var lista = box.querySelector(':scope > .cft-akapity__lista');
		if (e.target.closest('[data-akapit-dodaj]')) {
			var li = akapitNowy(box);
			lista.appendChild(li);
			li.querySelector('.cft-ed').focus();
			markDirty(); podgladPrzeladuj();
			return;
		}
		var b = e.target.closest('button[data-akapit]');
		if (!b) { return; }
		var row = b.closest('.cft-akapit'), co = b.getAttribute('data-akapit');
		if (co === 'gora' && row.previousElementSibling) { lista.insertBefore(row, row.previousElementSibling); }
		if (co === 'dol' && row.nextElementSibling) { lista.insertBefore(row.nextElementSibling, row); }
		if (co === 'kopia') {
			var kop = akapitNowy(box, row.querySelector('select').value, row.querySelector('.cft-src').value);
			row.after(kop);
			var zc = row.getAttribute('data-cel'), nc = kop.getAttribute('data-cel');
			if (STY[zc]) { STY[nc] = JSON.parse(JSON.stringify(STY[zc])); styZapisz(); }
			kop.querySelector('.cft-ed').focus();
		}
		if (co === 'usun') {
			var tx = (row.querySelector('.cft-ed').textContent || '').trim();
			if (tx && !window.confirm('Usunąć akapit „' + tx.slice(0, 60) + (tx.length > 60 ? '…' : '') + '”? Do zapisu możesz odświeżyć stronę bez zapisywania, a po zapisie wrócić do wersji z Historii.')) { return; }
			if (row.getAttribute('data-akapit').indexOf('p-') === 0) { delete STY[row.getAttribute('data-cel')]; styZapisz(); }
			var obok = row.nextElementSibling || row.previousElementSibling;
			row.remove();
			if (obok) { obok.querySelector('[data-akapit="usun"]').focus(); } else { box.querySelector('[data-akapit-dodaj]').focus(); }
		}
		if (co === 'gora' || co === 'dol') { b.focus(); }
		markDirty(); podgladPrzeladuj();
	});

	/* ----- zmiany struktury (rodzaj akapitu, układ i tło nowej sekcji): przeładuj podgląd ----- */
	form.addEventListener('change', function (e) { if (e.target.closest('[data-struktura]')) { podgladPrzeladuj(); } });
	// Nowy tekst, którego jeszcze nie ma w podglądzie (nowy akapit, nowa sekcja): podgląd raz się przeładuje.
	form.addEventListener('input', function (e) {
		var el = e.target.closest('.cft-ed[data-key], input[type=text][data-key]');
		if (!el) { return; }
		var key = el.getAttribute('data-key');
		if (key.indexOf('.') > 0 && !els(key).length && widoczny() && !ladowanie) { podgladPrzeladuj(); }
		var m = /^(s-[a-z0-9]+)[.]naglowek$/.exec(key);
		if (m) { [].forEach.call(wrap.querySelectorAll('.cft-nav__w[data-id="' + m[1] + '"] .cft-nav__nt'), function (x) { x.textContent = nazwaSek(NOWE[m[1]].strona, m[1]); }); }
	});

	/* =====================================================================
	 * Zapis i potwierdzenia
	 * ===================================================================== */
	form.addEventListener('submit', function () {
		[].forEach.call(form.querySelectorAll('.cft-lista'), renumber);
		dirty = false;
	});
	var all = document.querySelector('.cft-reset-all');
	if (all) {
		all.addEventListener('submit', function (e) {
			if (!window.confirm(all.getAttribute('data-potwierdz'))) { e.preventDefault(); return; }
			dirty = false;
		});
	}
})();
