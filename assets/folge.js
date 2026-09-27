/*
 * Tabs of the episode view.
 *
 * Without JavaScript all panels are stacked — nothing is lost. With
 * JavaScript exactly one is visible; which one is kept in the URL fragment
 * (#reiter-audio), so links from mails and the return after saving open the
 * right tab. On narrow screens a select list replaces the tab bar.
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-aaspf-reiter]');
	if (!root) {
		return;
	}

	var buttons = Array.prototype.slice.call(root.querySelectorAll('[role="tab"]'));
	var select = root.querySelector('.aaspf-reiter-wahl');
	var keys = buttons.map(function (b) { return b.id.replace(/^tab-/, ''); });

	function panel(key) {
		return document.getElementById('reiter-' + key);
	}

	function show(key, focus) {
		if (keys.indexOf(key) === -1) {
			key = root.getAttribute('data-standard') || keys[0];
		}

		keys.forEach(function (k, i) {
			var active = k === key;
			var p = panel(k);
			buttons[i].setAttribute('aria-selected', active ? 'true' : 'false');
			buttons[i].tabIndex = active ? 0 : -1;
			if (p) {
				p.hidden = !active;
			}
		});

		if (select) {
			select.value = key;
		}

		if (focus) {
			var target = panel(key);
			if (target) {
				target.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}
	}

	function fromHash() {
		var match = /^#reiter-([a-z]+)$/.exec(window.location.hash);
		return match ? match[1] : '';
	}

	buttons.forEach(function (button, index) {
		button.addEventListener('click', function (event) {
			event.preventDefault();
			var key = keys[index];
			history.replaceState(null, '', '#reiter-' + key);
			show(key, false);
		});

		button.addEventListener('keydown', function (event) {
			var step = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' ? -1 : 0);
			if (!step) {
				return;
			}
			event.preventDefault();
			var next = (index + step + buttons.length) % buttons.length;
			buttons[next].focus();
			buttons[next].click();
		});
	});

	if (select) {
		select.addEventListener('change', function () {
			history.replaceState(null, '', '#reiter-' + select.value);
			show(select.value, false);
		});
	}

	// Links such as "to the open items" open their tab and scroll to the target.
	document.addEventListener('click', function (event) {
		var link = event.target.closest ? event.target.closest('[data-aaspf-reiter-sprung]') : null;
		if (!link) {
			return;
		}
		event.preventDefault();
		var key = link.getAttribute('data-aaspf-reiter-sprung');
		history.replaceState(null, '', '#reiter-' + key);
		show(key, true);
	});

	window.addEventListener('hashchange', function () {
		show(fromHash(), false);
	});

	show(fromHash(), false);
})();

/*
 * Live status: while a step runs on its own, poll every fifteen seconds.
 * Update the progress; when another stage is up or a human is needed, reload
 * the page (the tab stays, it lives in the URL fragment). Background tabs do
 * not poll, and polling stops after three hours.
 */
(function () {
	'use strict';

	var box = document.querySelector('[data-aaspf-live]');
	if (!box || typeof window.aaspfFolge === 'undefined' || !window.fetch) {
		return;
	}

	var key = box.getAttribute('data-key');
	var episode = box.getAttribute('data-episode');
	var zahl = box.querySelector('[data-aaspf-live-zahl]');
	var balken = box.querySelector('[data-aaspf-live-balken]');
	var started = Date.now();

	function ask() {
		if (document.hidden) {
			return;
		}
		if (Date.now() - started > 3 * 60 * 60 * 1000) {
			window.clearInterval(timer);
			return;
		}

		var body = new FormData();
		body.append('action', window.aaspfFolge.action);
		body.append('nonce', window.aaspfFolge.nonce);
		body.append('episode', episode);

		fetch(window.aaspfFolge.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (answer) {
				if (!answer || !answer.success) {
					return;
				}
				var data = answer.data;
				if (data.key !== key || data.human) {
					window.location.reload();
					return;
				}
				if (zahl) {
					zahl.textContent = data.fertig + ' ' + (window.aaspfFolge.of || 'of') + ' ' + data.gesamt;
				}
				if (balken) {
					balken.max = Math.max(1, data.gesamt);
					balken.value = data.fertig;
				}
			})
			.catch(function () { /* next attempt in fifteen seconds */ });
	}

	var timer = window.setInterval(ask, 15000);
})();
