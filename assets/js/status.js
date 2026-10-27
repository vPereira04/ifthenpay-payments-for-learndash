/**
 * Course page payment status: keeps checking until ifthenpay confirms the payment.
 *
 * One quick check, then 10 × 3 s, then every 10 s up to 25 minutes, plus a
 * re-check whenever the tab comes back (the student was in their banking app).
 * The alert is updated in place, so its role="status" announces the change.
 * Only "paid" stops the watch: a failed or cancelled Multibanco reference can
 * still be paid later.
 */
(function () {
	'use strict';

	var cfg = window.iftpLdStatus || {};
	var box = document.getElementById('iftp-ld-status');

	if (!box || !cfg.ajaxUrl) {
		return;
	}

	var FAST_TRIES = 10;
	var FAST_DELAY = 3000;
	var SLOW_DELAY = 10000;
	var BUDGET = 25 * 60 * 1000;

	var started = Date.now();
	var tries = 0;
	var timer = null;
	var busy = false;
	var done = false;
	var state = box.getAttribute('data-state') || 'pending';

	function render(next) {
		var look = cfg.states && cfg.states[next];

		if (!look || next === state) {
			return;
		}

		var holder = document.createElement('div');
		holder.innerHTML = look.html;

		var fresh = holder.firstElementChild;
		var current = box.querySelector('.ld-alert');

		if (!fresh) {
			return;
		}

		if (current) {
			// Same node, new look: a re-inserted live region usually isn't announced.
			current.className = fresh.className;
			current.innerHTML = fresh.innerHTML;
		} else {
			box.innerHTML = '';
			box.appendChild(fresh);
		}

		state = next;
		box.setAttribute('data-state', next);
	}

	function giveUp() {
		var message = box.querySelector('.ld-alert__message, .ld-alert-messages');

		if ('pending' === state && message && cfg.i18n && cfg.i18n.refresh) {
			message.textContent = message.textContent + ' ' + cfg.i18n.refresh;
		}
	}

	function schedule() {
		if (done) {
			return;
		}

		if (Date.now() - started > BUDGET) {
			giveUp();
			return;
		}

		window.clearTimeout(timer);
		timer = window.setTimeout(check, tries < FAST_TRIES ? FAST_DELAY : SLOW_DELAY);
	}

	function check() {
		if (busy || done) {
			return;
		}

		busy = true;
		tries++;

		var body = new window.URLSearchParams();
		body.append('action', cfg.action);
		body.append('ref', box.getAttribute('data-ref') || '');
		body.append('sig', box.getAttribute('data-sig') || '');

		window
			.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				var next = json && json.success && json.data ? json.data.state : '';

				if ('paid' === next) {
					done = true;
					window.location.replace((json.data && json.data.successUrl) || cfg.successUrl || window.location.href);
					return;
				}

				// Back from a "success" return but not confirmed yet: say so instead of the plain pending text.
				if ('pending' === next && ('checking' === state || 'confirming' === state)) {
					next = 'confirming';
				}

				if (next) {
					render(next);
				}
			})
			.catch(function () {
				// A dropped request just waits for the next round.
			})
			.then(function () {
				busy = false;
				schedule();
			});
	}

	document.addEventListener('visibilitychange', function () {
		if ('visible' === document.visibilityState && !done) {
			window.clearTimeout(timer);
			check();
		}
	});

	check();
})();
