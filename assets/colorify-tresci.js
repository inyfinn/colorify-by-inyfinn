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
	function renumber(lista) {
		var key = lista.getAttribute('data-lista');
		var reName = new RegExp('^cft\[' + key + '\\]\\[[^\\]]*\\]');
		var reKey = new RegExp('^' + key + '\\.[^.]+\\.');
		var reId = new RegExp('^cft-' + key + '-[^-]+-');
		[].forEach.call(lista.querySelectorAll('.cft-wiersze > .cft-wiersz'), function (row, i) {
			row.querySelector('.cft-nr').textContent = i + 1;
			row.setAttribute('data-i', i);
			[].forEach.call(row.querySelectorAll('[name]'), function (el) { el.name = el.name.replace(reName, 'ej[' + key + '][' + i + ']'); });
			[].forEach.call(row.querySelectorAll('[data-key]'), function (el) { el.setAttribute('data-key', el.getAttribute('data-key').replace(reKey, key + '.' + i + '.')); });
			['id', 'for', 'aria-labelledby'].forEach(function (a) {
				[].forEach.call(row.querySelectorAll('[' + a + ']'), function (el) { el.setAttribute(a, el.getAttribute(a).replace(reId, 'cft-' + key + '-' + i + '-')); });
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

	function podgladPrzeladuj() {
		clearTimeout(przeladujT);
		przeladujT = setTimeout(function () {
			if (!widoczny()) { return; }
			try { scrollPo = iframe.contentWindow.scrollY; } catch (e) {}
			zaladuj();
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
