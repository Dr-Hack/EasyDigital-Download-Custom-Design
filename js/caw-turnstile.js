/**
 * Cloudflare Turnstile glue.
 *
 * Two jobs:
 *   1. Mount widgets — server-rendered ones ([data-caw-turnstile]) plus the
 *      three forms inside the Mayosis MSV auth modal, whose markup lives in the
 *      parent theme and has no PHP hook.
 *   2. Make the modal's AJAX submits carry the token. The modal's own handler
 *      posts a hand-built field list rather than serialising the form, so the
 *      token is appended in an ajaxPrefilter, and a capture-phase submit
 *      listener holds the submit back until a token exists.
 */
(function () {
	'use strict';

	var cfg = window.cawTurnstile || {};
	if (!cfg.sitekey) {
		return;
	}

	/* MSV modal form id → Cloudflare action name. */
	var MSV_FORMS = {
		mayosis_mbw_lrb_login_form: 'msv-login',
		mayosis_mbw_lrb_register_form: 'msv-register',
		mayosis_mbw_lrb_lost_password_form: 'msv-forgot'
	};

	/* admin-ajax actions the modal posts. */
	var MSV_ACTIONS = [
		'mayosis_mbw_lrb_ajax_login',
		'mayosis_mbw_lrb_ajax_register',
		'mayosis_mbw_lrb_ajax_lost_password'
	];

	var TOKEN_FIELD = 'cf-turnstile-response';
	var widgets = []; // { id, holder }

	/* ── Helpers ─────────────────────────────────────────────────────────── */

	function isNight() {
		return document.body && document.body.classList.contains('sp-night-mode-on');
	}

	function tokenOf(form) {
		if (!form) {
			return '';
		}
		var input = form.querySelector('[name="' + TOKEN_FIELD + '"]');
		return input && input.value ? input.value : '';
	}

	function resetAll() {
		if (typeof turnstile === 'undefined') {
			return;
		}
		widgets.forEach(function (w) {
			try {
				turnstile.reset(w.id);
			} catch (e) { /* widget already gone */ }
		});
	}

	/* ── Mounting ────────────────────────────────────────────────────────── */

	function mount(holder, action, appearance, size) {
		if (holder.getAttribute('data-caw-mounted') === '1') {
			return;
		}
		holder.setAttribute('data-caw-mounted', '1');

		var id = turnstile.render(holder, {
			sitekey: cfg.sitekey,
			action: action,
			appearance: appearance || 'interaction-only',
			size: size || 'flexible',
			theme: isNight() ? 'dark' : 'light',
			'response-field-name': TOKEN_FIELD,
			'retry-interval': 3000,
			callback: function () {
				holder.classList.remove('caw-turnstile--pending');
				var form = holder.closest('form');
				if (form && form.getAttribute('data-caw-await') === '1') {
					form.removeAttribute('data-caw-await');
					releaseButton(form);
					if (window.jQuery) {
						window.jQuery(form).trigger('submit');
					}
				}
			},
			'error-callback': function () {
				holder.classList.remove('caw-turnstile--pending');
				var form = holder.closest('form');
				if (form) {
					form.removeAttribute('data-caw-await');
					releaseButton(form);
				}
				return true; // let Turnstile retry on its own
			}
		});

		widgets.push({ id: id, holder: holder });
	}

	/* Server-rendered widgets (wp-login.php, FES forms). */
	function mountRendered() {
		var nodes = document.querySelectorAll('[data-caw-turnstile]');
		Array.prototype.forEach.call(nodes, function (el) {
			mount(
				el,
				el.getAttribute('data-action') || 'submit',
				el.getAttribute('data-appearance'),
				el.getAttribute('data-size')
			);
		});
	}

	/* ── MSV modal: mount on demand ──────────────────────────────────────── */

	/* Give one MSV form its widget. Idempotent: mount() no-ops on a holder it
	   has already rendered into. */
	function mountModalForm(form) {
		if (!form || !MSV_FORMS[form.id]) {
			return;
		}
		var holder = form.querySelector('.caw-turnstile');
		if (!holder) {
			holder = document.createElement('div');
			holder.className = 'caw-turnstile caw-turnstile--modal';
			var btn = form.querySelector('.msv-auth-btn');
			if (btn) {
				form.insertBefore(holder, btn);
			} else {
				form.appendChild(holder);
			}
		}
		mount(holder, MSV_FORMS[form.id], cfg.appearance, cfg.size);
	}

	/* Which of the three forms is on screen right now. The forgot form lives in
	   an overlay that sits on top of the sliding track, so it wins when open. */
	function visibleModalForm(modal) {
		if (modal.querySelector('.msv-auth-forgot-overlay.is-open')) {
			return document.getElementById('mayosis_mbw_lrb_lost_password_form');
		}
		var panel = modal.querySelector('.msv-auth-panels__track .msv-auth-panel.is-active');
		return panel ? panel.querySelector('form') : null;
	}

	/**
	 * Mount when the modal opens, and again on each tab switch — visible form
	 * only.
	 *
	 * Mounting all three up front spent a challenge per form on every logged-out
	 * pageview: ~12 issued per modal that anyone actually opened. That buried
	 * real auth traffic in Turnstile's analytics (a genuine credential-stuffing
	 * spike would not have stood out against it) and left the dashboard
	 * reporting that siteverify was never called for the widget.
	 *
	 * The theme's script fires nothing on open or switchTab, so both are read
	 * off the class attribute instead — `is-open` on the modal, `is-active` on a
	 * track panel, `is-open` on the forgot overlay. Mounting late is safe
	 * because gate() already holds the submit until a token lands.
	 */
	function watchModal(modal) {
		if (!Number(cfg.modal)) {
			return;
		}

		if (window.MutationObserver) {
			var queued = false;
			var observer = new MutationObserver(function (records) {
				var relevant = records.some(function (r) {
					/* The forgot overlay carries .msv-auth-panel too. */
					return r.target === modal || r.target.classList.contains('msv-auth-panel');
				});
				if (!relevant || queued) {
					return;
				}
				/* Open-with-a-tab is two class changes in one batch; coalesce so
				   the final state is what gets read. setTimeout rather than
				   requestAnimationFrame — rAF is tied to the rendering pipeline
				   and is throttled to a standstill in a background tab, which
				   would strand the modal without a widget. */
				queued = true;
				setTimeout(function () {
					queued = false;
					syncModal(modal);
				}, 0);
			});
			observer.observe(modal, { attributes: true, attributeFilter: ['class'], subtree: true });
		}

		syncModal(modal); // in case something opened it before we booted
	}

	function syncModal(modal) {
		if (!modal.classList.contains('is-open')) {
			return;
		}
		mountModalForm(visibleModalForm(modal));
	}

	/* ── Submit gate (MSV modal) ─────────────────────────────────────────── */

	function holdButton(form) {
		var btn = form.querySelector('.msv-auth-btn');
		var txt = btn && btn.querySelector('.msv-auth-btn__text');
		if (!btn || !txt || btn.getAttribute('data-caw-held') === '1') {
			return;
		}
		btn.setAttribute('data-caw-held', '1');
		btn.setAttribute('data-caw-label', txt.textContent);
		txt.textContent = cfg.waitMsg || 'Verifying…';
		btn.disabled = true;
	}

	function releaseButton(form) {
		var btn = form.querySelector('.msv-auth-btn');
		var txt = btn && btn.querySelector('.msv-auth-btn__text');
		if (!btn || btn.getAttribute('data-caw-held') !== '1') {
			return;
		}
		if (txt && btn.getAttribute('data-caw-label')) {
			txt.textContent = btn.getAttribute('data-caw-label');
		}
		btn.removeAttribute('data-caw-held');
		btn.disabled = false;
	}

	function gate(e) {
		var form = e.target;
		if (!form || !form.id || !MSV_FORMS[form.id]) {
			return;
		}
		if (tokenOf(form)) {
			return; // token in hand — let the modal's own handler run
		}

		/* Safety net for a modal opened in some way the observer didn't see:
		   without a widget there is no token, and the hold below would never be
		   released. Mounting here costs this one submit the solve time. */
		mountModalForm(form);

		/* Challenge still solving. Hold the submit; the widget callback
		   re-fires it as soon as the token lands. */
		e.preventDefault();
		e.stopImmediatePropagation();
		form.setAttribute('data-caw-await', '1');
		holdButton(form);

		var holder = form.querySelector('.caw-turnstile');
		if (holder) {
			holder.classList.add('caw-turnstile--pending');
		}
	}

	/* ── Boot ────────────────────────────────────────────────────────────── */

	function boot() {
		if (typeof turnstile === 'undefined' || !turnstile.render) {
			return;
		}
		mountRendered();

		var modal = document.getElementById('msv-auth-modal');
		if (modal) {
			/* Capture phase so this runs before the modal's delegated jQuery
			   handler, which is bound on the modal element itself. */
			modal.addEventListener('submit', gate, true);
			watchModal(modal);
		}

		if (window.jQuery) {
			var $ = window.jQuery;

			/* The modal posts a hand-built data object — append the token. */
			$.ajaxPrefilter(function (options) {
				if (typeof options.data !== 'string') {
					return;
				}
				var hit = MSV_ACTIONS.some(function (a) {
					return options.data.indexOf('action=' + a) !== -1;
				});
				if (!hit || options.data.indexOf(TOKEN_FIELD + '=') !== -1) {
					return;
				}
				var form = formForRequest(options.data);
				options.data += '&' + TOKEN_FIELD + '=' + encodeURIComponent(tokenOf(form));
			});

			/* Tokens are single-use: after every auth round-trip, get a fresh
			   one so a failed attempt can be retried. */
			$(document).on('ajaxComplete', function (ev, xhr, settings) {
				if (typeof settings.data !== 'string') {
					return;
				}
				var hit = MSV_ACTIONS.some(function (a) {
					return settings.data.indexOf('action=' + a) !== -1;
				});
				if (hit) {
					resetAll();
				}
			});
		}
	}

	/* Map an outgoing request back to the form that produced it. */
	function formForRequest(data) {
		if (data.indexOf('action=mayosis_mbw_lrb_ajax_login') !== -1) {
			return document.getElementById('mayosis_mbw_lrb_login_form');
		}
		if (data.indexOf('action=mayosis_mbw_lrb_ajax_register') !== -1) {
			return document.getElementById('mayosis_mbw_lrb_register_form');
		}
		return document.getElementById('mayosis_mbw_lrb_lost_password_form');
	}

	/* api.js is loaded with render=explicit, so wait for it either way. */
	if (typeof turnstile !== 'undefined' && turnstile.ready) {
		turnstile.ready(boot);
	} else {
		window.onloadTurnstileCallback = boot;
		document.addEventListener('DOMContentLoaded', function () {
			if (typeof turnstile !== 'undefined') {
				turnstile.ready ? turnstile.ready(boot) : boot();
			}
		});
	}
}());
