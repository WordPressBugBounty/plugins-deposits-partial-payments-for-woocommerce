/* Acowebs feedback: deactivation popup (plugins.php) and newsletter opt-in notice. No dependencies. */
(function () {
	'use strict';

	var cfg = window.awcdp_feedback;
	if (!cfg) {
		return;
	}

	function post(action, fields) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(fields || {}).forEach(function (key) {
			body.append(key, fields[key]);
		});
		// keepalive lets the request finish even if the page navigates away first.
		return fetch(cfg.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			body: body
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok || !json || !json.success) {
					throw json && json.data ? json.data : {};
				}
				return json;
			});
		});
	}

	/* ---------- Deactivation popup ---------- */

	function initDeactivation() {
		var overlay = document.getElementById('acofb-awcdp-deactivation');
		if (!overlay) {
			return;
		}

		var form = overlay.querySelector('.acofb-modal-form');
		var consent = overlay.querySelector('.acofb-check');
		var contactInputs = overlay.querySelectorAll('.acofb-contact-fields input');
		var sendBtn = overlay.querySelector('[data-acofb-action="send"]');
		var linkSelector =
			'tr[data-slug="' + cfg.slug + '"] .deactivate a, tr[data-plugin="' + cfg.basename + '"] .deactivate a';
		var deactivateUrl = '';
		var opener = null;
		var busy = false;

		function syncContact() {
			// Hidden contact fields must not be sent, nor block the form with a malformed email.
			Array.prototype.forEach.call(contactInputs, function (input) {
				input.disabled = !consent.checked;
			});
		}

		function open(link) {
			opener = link;
			deactivateUrl = link.href;
			overlay.hidden = false;
			var first = overlay.querySelector('.acofb-radio');
			if (first) {
				first.focus();
			}
		}

		function close() {
			if (busy) {
				return;
			}
			overlay.hidden = true;
			form.reset();
			syncContact();
			if (opener) {
				opener.focus();
			}
		}

		function deactivate() {
			if (deactivateUrl) {
				window.location.href = deactivateUrl;
			}
		}

		function send() {
			if (busy) {
				return;
			}
			var reason = form.querySelector('input[name="reason"]:checked');
			if (!reason) {
				overlay.querySelector('.acofb-radio').focus();
				return;
			}
			if (!form.checkValidity()) {
				var invalid = form.querySelector(':invalid');
				if (invalid) {
					invalid.focus();
				}
				return;
			}

			var details = reason.parentNode.querySelector('textarea');
			var fields = {
				reason: reason.value,
				reason_details: details ? details.value : '',
				consent: consent.checked ? '1' : '0'
			};
			if (consent.checked) {
				fields.name = form.querySelector('input[name="name"]').value;
				fields.email = form.querySelector('input[name="email"]').value;
			}

			busy = true;
			Array.prototype.forEach.call(overlay.querySelectorAll('.acofb-btn'), function (btn) {
				btn.disabled = true;
			});
			sendBtn.textContent = cfg.i18n.sending;

			// Never block deactivation: go when the request finishes or after 3 seconds, whichever is first.
			var gone = false;
			function go() {
				if (!gone) {
					gone = true;
					deactivate();
				}
			}
			var timer = setTimeout(go, 3000);
			try {
				post('awcdp_deactivation_feedback', fields).then(go, go);
			} catch (e) {
				clearTimeout(timer);
				go();
			}
		}

		document.addEventListener('click', function (event) {
			var link = event.target.closest ? event.target.closest(linkSelector) : null;
			if (link) {
				event.preventDefault();
				open(link);
			}
		});

		overlay.addEventListener('click', function (event) {
			if (event.target === overlay) {
				close();
				return;
			}
			var btn = event.target.closest('[data-acofb-action]');
			if (!btn) {
				return;
			}
			var action = btn.getAttribute('data-acofb-action');
			if ('skip' === action) {
				deactivate();
			} else if ('cancel' === action) {
				close();
			}
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			send();
		});

		consent.addEventListener('change', syncContact);

		overlay.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) {
				close();
				return;
			}
			if ('Tab' !== event.key) {
				return;
			}
			// Keep focus inside the dialog.
			var focusable = Array.prototype.filter.call(
				overlay.querySelectorAll('a[href], button, input, textarea'),
				function (el) {
					return !el.disabled && el.offsetParent !== null;
				}
			);
			if (!focusable.length) {
				return;
			}
			var first = focusable[0];
			var last = focusable[focusable.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});

		syncContact();
	}

	/* ---------- Newsletter notice ---------- */

	function initNewsletter() {
		var notice = document.getElementById('acofb-awcdp-newsletter');
		if (!notice) {
			return;
		}

		var form = notice.querySelector('form');
		var body = notice.querySelector('.acofb-notice-body');
		var thanks = notice.querySelector('.acofb-thanks');
		var msg = notice.querySelector('.acofb-msg');
		var submitBtn = form.querySelector('button[type="submit"]');
		var done = false;

		function setMessage(text, isError) {
			msg.textContent = text || '';
			msg.classList.toggle('acofb-msg--error', !!isError);
		}

		notice.addEventListener('click', function (event) {
			if (!event.target.closest('[data-acofb-action="dismiss"]')) {
				return;
			}
			if (!done) {
				post('awcdp_newsletter_dismiss').catch(function () {});
			}
			notice.parentNode.removeChild(notice);
		});

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (submitBtn.disabled) {
				return;
			}
			if (!form.checkValidity()) {
				form.reportValidity();
				return;
			}

			submitBtn.disabled = true;
			setMessage(cfg.i18n.subscribing, false);

			post('awcdp_newsletter_optin', {
				email: form.elements.email.value,
				name: form.elements.name.value,
				phone: form.elements.phone.value,
				website_hp: form.elements.website_hp.value
			}).then(
				function () {
					done = true;
					body.hidden = true;
					thanks.hidden = false;
					thanks.focus();
				},
				function (error) {
					submitBtn.disabled = false;
					setMessage((error && error.message) || cfg.i18n.error, true);
				}
			);
		});
	}

	function init() {
		initDeactivation();
		initNewsletter();
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
