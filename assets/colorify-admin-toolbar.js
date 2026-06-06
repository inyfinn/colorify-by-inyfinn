/**
 * Pasek Colorify — przełączniki trybu i motywu (natychmiastowy podgląd + AJAX).
 *
 * @package ColorifyByInyfinn
 */
(function () {
	'use strict';

	var cfg = window.colorifyAdminToolbar || {};
	var mode = cfg.mode === 'light' ? 'light' : 'dark';
	var themeEnabled = cfg.themeEnabled === '1' || cfg.themeEnabled === 1 || cfg.themeEnabled === true;

	function isThemeActive() {
		return themeEnabled && document.body && !document.body.classList.contains('colorify-theme-off');
	}

	function getModeSaveScope() {
		var checked = document.querySelector('input[name="colorify_settings_scope"]:checked');
		if (checked) {
			return checked.value === 'global' ? 'global' : 'user';
		}
		if (cfg.canManageGlobal === '1' && cfg.settingsScope === 'global') {
			return 'global';
		}
		return 'user';
	}

	function syncModeSwitchInputs() {
		document.querySelectorAll('.colorify-mode-switch__input').forEach(function (input) {
			input.checked = mode === 'light';
		});
	}

	function syncThemeSwitchInputs() {
		document.querySelectorAll('.colorify-theme-switch__input').forEach(function (input) {
			input.checked = themeEnabled;
		});
	}

	function pushTokensToDom(tokens) {
		if (!tokens) {
			return;
		}
		var root = document.documentElement;
		Object.keys(tokens).forEach(function (key) {
			root.style.setProperty(key, tokens[key]);
		});

		var css = ':root{';
		Object.keys(tokens).forEach(function (key) {
			css += key + ':' + tokens[key] + ';';
		});
		css += '}';

		var styleEl = document.getElementById('colorify-scheme-vars');
		if (!styleEl) {
			styleEl = document.createElement('style');
			styleEl.id = 'colorify-scheme-vars';
			document.head.appendChild(styleEl);
		}
		styleEl.textContent = css;
	}

	function applyModeClasses(nextMode) {
		mode = nextMode === 'light' ? 'light' : 'dark';
		if (document.body) {
			document.body.classList.toggle('colorify-admin-light', mode === 'light');
		}
		document.documentElement.style.colorScheme = mode === 'light' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-colorify-admin-mode', mode);
		syncModeSwitchInputs();
		document.dispatchEvent(
			new CustomEvent('colorify:mode-changed', { detail: { mode: mode } })
		);
	}

	function fetchTokens(nextMode) {
		if (!cfg.ajaxUrl || !cfg.nonce) {
			return Promise.resolve(null);
		}
		var body = new URLSearchParams();
		body.set('action', 'colorify_get_scheme_tokens');
		body.set('nonce', cfg.nonce);
		body.set('mode', nextMode);
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data || !payload.data.tokens) {
					return null;
				}
				return payload.data.tokens;
			})
			.catch(function () {
				return null;
			});
	}

	function saveMode(nextMode) {
		if (!cfg.ajaxUrl || !cfg.nonce) {
			return Promise.resolve(false);
		}
		var body = new URLSearchParams();
		body.set('action', 'colorify_save_admin_appearance');
		body.set('nonce', cfg.nonce);
		body.set('mode', nextMode);
		body.set('scope', getModeSaveScope());
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				return !!(payload && payload.success);
			})
			.catch(function () {
				return false;
			});
	}

	function loadStylesheet(id, href) {
		return new Promise(function (resolve) {
			var existing = document.getElementById(id);
			if (existing) {
				resolve();
				return;
			}
			var link = document.createElement('link');
			link.id = id;
			link.rel = 'stylesheet';
			link.href = href;
			link.onload = function () {
				resolve();
			};
			link.onerror = function () {
				resolve();
			};
			document.head.appendChild(link);
		});
	}

	function enableThemeLive() {
		var assets = cfg.assets || {};
		var jobs = [];
		if (assets.branding) {
			jobs.push(loadStylesheet('colorify-branding-admin', assets.branding));
		}
		if (assets.overrides) {
			jobs.push(loadStylesheet('colorify-admin-overrides', assets.overrides));
		}
		return Promise.all(jobs).then(function () {
			if (document.body) {
				document.body.classList.remove('colorify-theme-off');
			}
			return fetchTokens(mode).then(function (tokens) {
				if (tokens) {
					pushTokensToDom(tokens);
				}
			});
		});
	}

	function disableThemeLive() {
		if (document.body) {
			document.body.classList.add('colorify-theme-off');
		}
		var schemeStyle = document.getElementById('colorify-scheme-vars');
		if (schemeStyle) {
			schemeStyle.remove();
		}
	}

	function saveThemeEnabled(enabled) {
		if (!cfg.ajaxUrl || !cfg.nonce) {
			return Promise.resolve(false);
		}
		var body = new URLSearchParams();
		body.set('action', 'colorify_toggle_user_theme');
		body.set('nonce', cfg.nonce);
		body.set('enabled', enabled ? '1' : '0');
		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					return false;
				}
				themeEnabled = enabled;
				cfg.themeEnabled = enabled ? '1' : '0';
				return true;
			})
			.catch(function () {
				return false;
			});
	}

	function onModeToggle(checked) {
		var nextMode = checked ? 'light' : 'dark';
		applyModeClasses(nextMode);
		if (isThemeActive()) {
			fetchTokens(nextMode).then(pushTokensToDom);
		}
		saveMode(nextMode);
	}

	function onThemeToggle(checked) {
		saveThemeEnabled(checked).then(function (saved) {
			if (saved) {
				window.location.reload();
				return;
			}
			syncThemeSwitchInputs();
		});
	}

	function bindToolbar() {
		if (document.body && document.body.dataset.colorifyToolbarBound === '1') {
			return;
		}
		if (document.body) {
			document.body.dataset.colorifyToolbarBound = '1';
		}

		syncModeSwitchInputs();
		syncThemeSwitchInputs();

		document.addEventListener('change', function (event) {
			var input = event.target;
			if (!input || !input.classList) {
				return;
			}
			if (input.classList.contains('colorify-mode-switch__input')) {
				onModeToggle(!!input.checked);
				return;
			}
			if (input.classList.contains('colorify-theme-switch__input')) {
				onThemeToggle(!!input.checked);
			}
		}, true);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindToolbar, { once: true });
	} else {
		bindToolbar();
	}
})();
