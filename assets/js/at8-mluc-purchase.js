/**
 * Membership purchase card: buy buttons and gateway radios.
 *
 * Configuration arrives through window.at8MlucPurchase (localized by
 * AT8_MLUC_Payments::enqueue_purchase_script()): ajaxUrl, nonce and the
 * translated strings used by the PayPal flow. Every .at8-mluc-buy block on
 * the page is bound on its own, so several cards (the purchase path, an
 * add-on's upgrade ladder) can coexist.
 */
(function () {
	'use strict';

	var cfg = window.at8MlucPurchase || {};
	var strings = cfg.strings || {};

	function post(action, extra) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', cfg.nonce || '');
		if (extra) { Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); }); }
		return fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); });
	}

	function bind(root) {
		var msg = root.querySelector('.at8-mluc-buy-msg');
		var ppMount = root.querySelector('.at8-mluc-pp-mount');
		var ppOrder = null;

		function say(text) { if (msg) { msg.textContent = text || ''; } }

		function mountPayPal(orderId) {
			if (!window.paypal || !ppMount) {
				say(strings.paypalLoad || '');
				return;
			}
			ppOrder = orderId;
			ppMount.hidden = false;
			ppMount.innerHTML = '';
			window.paypal.Buttons({
				style: { layout: 'vertical', label: 'paypal' },
				createOrder: function () {
					return post('at8_mluc_paypal_create', { order_id: orderId }).then(function (res) {
						if (!res.success) { throw new Error(res.message || 'create failed'); }
						return res.data.pp_order_id;
					});
				},
				onApprove: function () {
					say(strings.confirming || '');
					return post('at8_mluc_paypal_capture', { order_id: orderId, pp_order_id: ppOrder }).then(function (res) {
						say(res.message);
						if (res.success) { setTimeout(function () { window.location.reload(); }, 1500); }
					});
				},
				onError: function () {
					say(strings.paypalError || '');
				}
			}).render(ppMount);
		}

		root.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.at8-mluc-btn-buy') : null;
			if (!btn) { return; }
			e.preventDefault();
			var level = btn.getAttribute('data-level');
			var gwEl = root.querySelector('input[name="at8_mluc_buy_gateway_' + level + '"]:checked');
			var gw = gwEl ? gwEl.value : '';
			var payload = { level: level, gateway: gw };
			// Upgrade ladders mark their button; the server then charges the
			// difference instead of the full list price.
			var upgrade = btn.getAttribute('data-upgrade');
			if (upgrade) {
				payload.upgrade = upgrade;
			}
			say('');
			btn.disabled = true;
			post('at8_mluc_buy_level', payload)
				.then(function (res) {
					if (!res.success) { say(res.message); return; }
					var flow = res.data && res.data.flow;
					if ('paypal' === flow) {
						say(strings.paypalDo || '');
						mountPayPal(res.data.order_id);
					} else if (('stripe' === flow || 'redirect' === flow) && res.data.redirect) {
						window.location.href = res.data.redirect;
					} else {
						say(res.message);
					}
				})
				.catch(function () {
					say(strings.network || '');
				})
				.finally(function () { btn.disabled = false; });
		});
	}

	var roots = document.querySelectorAll('.at8-mluc-buy');
	Array.prototype.forEach.call(roots, bind);
}());
