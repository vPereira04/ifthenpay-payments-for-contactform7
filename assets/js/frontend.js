(function ($) {
	'use strict';

	const cfg = window.iftpCf7Front || {};
	const SESSION_KEY = 'iftp_cf7_pbl_session';
	let pendingAfterSuccess = false;

	function apiPost(action, data) {
		return $.post(
			cfg.ajax_url,
			Object.assign({ action, nonce: cfg.ajax_nonce }, data || {}),
			null,
			'json'
		);
	}

	function saveSession(data) {
		try {
			sessionStorage.setItem(SESSION_KEY, JSON.stringify(data));
		} catch (_e) {}
	}
	function loadSession() {
		try {
			return JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
		} catch (_e) {
			return null;
		}
	}
	function clearSession() {
		try {
			sessionStorage.removeItem(SESSION_KEY);
		} catch (_e) {}
	}

	function getReturnStatus() {
		try {
			return (
				new URL(window.location.href).searchParams.get(
					'iftp_cf7_pay'
				) || ''
			);
		} catch (_e) {
			return '';
		}
	}
	function getReturnEntryId() {
		try {
			return (
				new URL(window.location.href).searchParams.get(
					'iftp_cf7_entry'
				) || ''
			);
		} catch (_e) {
			return '';
		}
	}
	function cleanUrl() {
		if (!window.history || !window.history.replaceState) {
			return;
		}
		try {
			const url = new URL(window.location.href);
			['iftp_cf7_pay', 'iftp_cf7_entry'].forEach(function (k) {
				url.searchParams.delete(k);
			});
			url.hash = '';
			history.replaceState({}, document.title, url.toString());
		} catch (_e) {}
	}

	function captureFormData($form) {
		const data = {};
		$form.find('input, textarea, select').each(function () {
			const $el = $(this);
			const name = $el.attr('name');
			const type = String($el.attr('type') || '').toLowerCase();
			if (
				!name ||
				type === 'submit' ||
				type === 'button' ||
				type === 'hidden'
			) {
				return;
			}
			if (
				(type === 'checkbox' || type === 'radio') &&
				!$el.is(':checked')
			) {
				return;
			}
			data[name] = $el.val();
		});
		return data;
	}

	function restoreFormData($form, data) {
		if (!data || typeof data !== 'object') {
			return;
		}
		$.each(data, function (name, value) {
			const $inputs = $form.find('[name="' + name + '"]');
			const type = String(
				$inputs.first().attr('type') || ''
			).toLowerCase();
			if (type === 'checkbox' || type === 'radio') {
				$inputs.filter('[value="' + value + '"]').prop('checked', true);
			} else {
				$inputs.val(value);
			}
		});
	}

	function disableFormButtons($form) {
		$form.find('button, input[type="submit"]').each(function () {
			const $el = $(this);
			$el.data('iftp-was-disabled', $el.prop('disabled'));
			$el.prop('disabled', true);
		});
	}

	function enableFormButtons($form) {
		$form.find('button, input[type="submit"]').each(function () {
			const $el = $(this);
			if (!$el.data('iftp-was-disabled')) {
				$el.prop('disabled', false);
			}
		});
	}

	function resolveAmount($form, $field) {
		const inline = parseFloat(String($field.data('amount') || ''));
		if (!isNaN(inline) && inline > 0) {
			return inline;
		}

		const btnAmt = parseFloat(
			String($field.find('.iftp-cf7-pay-button').data('amount') || '')
		);
		if (!isNaN(btnAmt) && btnAmt > 0) {
			return btnAmt;
		}

		let amount = 0;
		$form.find('input[name], select[name]').each(function () {
			const n = String($(this).attr('name') || '').toLowerCase();
			if (
				n.indexOf('amount') !== -1 ||
				n.indexOf('total') !== -1 ||
				n.indexOf('price') !== -1
			) {
				const v = parseFloat(String($(this).val() || ''));
				if (!isNaN(v) && v > 0) {
					amount = v;
					return false;
				}
			}
		});
		return amount;
	}

	function extractCustomer($form) {
		let name = '',
			email = '';
		$form
			.find('input[type="email"], input[name*="email"]')
			.each(function () {
				const v = String($(this).val() || '').trim();
				if (v && v.indexOf('@') !== -1) {
					email = v;
					return false;
				}
			});
		$form
			.find('input[name*="name"], input[name*="nome"]')
			.each(function () {
				const v = String($(this).val() || '').trim();
				if (v) {
					name = v;
					return false;
				}
			});
		return { name, email };
	}

	/**
	 * Show a status message in the payment field's runtime-warning container.
	 * For cancel/error, also provides Retry (link to same PBL) and New Payment (reload).
	 * @param $field
	 * @param type
	 * @param paymentUrl
	 */
	function showFieldMessage($field, type, paymentUrl) {
		const $warn = $field.find('.iftp-cf7-runtime-warning');
		if (!$warn.length) {
			return;
		}

		$warn
			.empty()
			.removeClass('iftp-warn-pending iftp-warn-cancel iftp-warn-error')
			.show();

		const isCancelType = type === 'cancel';
		$warn.addClass(isCancelType ? 'iftp-warn-cancel' : 'iftp-warn-error');

		const $p = $('<p class="iftp-msg">').addClass(
			isCancelType ? 'iftp-msg-cancel' : 'iftp-msg-error'
		);

		if (paymentUrl) {
			$p.append(
				$('<a>')
					.attr('href', paymentUrl)
					.text(cfg.msg_retry || 'Retry')
			);
			$p.append(
				document.createTextNode(' ' + (cfg.msg_or || 'or') + ' ')
			);
			$p.append(
				$('<a href="#">')
					.addClass('iftp-new-payment')
					.text(cfg.msg_new_payment || 'New Payment')
			);
		} else {
			$p.append(
				$('<a href="#">')
					.addClass('iftp-new-payment')
					.text(cfg.msg_retry || 'Retry')
			);
			$p.append(
				document.createTextNode(' ' + (cfg.msg_or || 'or') + ' ')
			);
			$p.append(
				$('<a href="#">')
					.addClass('iftp-new-payment')
					.text(cfg.msg_new_payment || 'New Payment')
			);
		}

		$warn.append($p);
	}

	function submitCf7Form($form) {
		enableFormButtons($form);
		const $btn = $form
			.find(
				'.wpcf7-submit:not(.iftp-cf7-pay-button), input[type="submit"]:not(.iftp-cf7-pay-button), button[type="submit"]:not(.iftp-cf7-pay-button)'
			)
			.first();
		if ($btn.length) {
			$btn.prop('disabled', false).trigger('click');
		} else {
			$form.trigger('submit');
		}
	}

	function handleReturnParams() {
		const status = getReturnStatus();
		if (!status) {
			return;
		}

		let entryId = getReturnEntryId();
		const session = loadSession();

		entryId = entryId || String((session && session.entry_id) || '');

		cleanUrl();

		const formId = session && session.form_id;
		const $field = formId
			? $(
					'.iftp-cf7-payment-field[data-form-id="' + formId + '"]'
				).first()
			: $('.iftp-cf7-payment-field').first();

		if (!$field.length) {
			return;
		}

		const $form = $field.closest('form');
		const paymentUrl = String((session && session.payment_url) || '');

		if (status === 'success') {

			try {
				const formEl = $form[0];
				if (formEl && formEl.action) {
					const actionUrl = new URL(
						formEl.action,
						window.location.href
					);
					actionUrl.searchParams.delete('iftp_cf7_pay');
					actionUrl.searchParams.delete('iftp_cf7_entry');
					actionUrl.hash = '';
					formEl.action = actionUrl.toString();
				}
			} catch (_e) {}


			(function () {
				const formEl = $form[0];
				if (!formEl) {
					return;
				}
				function clearHashOnSent(ev) {
					if (ev && ev.detail && ev.detail.status === 'sent') {
						formEl.removeEventListener(
							'wpcf7statuschanged',
							clearHashOnSent
						);
						setTimeout(function () {
							try {
								const u = new URL(window.location.href);
								u.hash = '';
								history.replaceState(
									{},
									document.title,
									u.toString()
								);
							} catch (_e) {}
						}, 0);
					}
				}
				formEl.addEventListener(
					'wpcf7statuschanged',
					clearHashOnSent
				);
			})();

			if (session && session.form_data) {
				restoreFormData($form, session.form_data);
			}

			$field.find('.iftp-cf7-entry-id').val(entryId);
			$field.find('.iftp-cf7-payment-status').val('pending');

			clearSession();

			$field.find('.iftp-cf7-pay-button').hide();
			pendingAfterSuccess = true;

			setTimeout(function () {
				submitCf7Form($form);
			}, 200);
		} else {



			const formEl = $form[0];
			if (formEl) {
				formEl.classList.remove('init', 'sent', 'resetting', 'submitting');
				formEl.classList.add('failed');
				if (formEl.setAttribute) {
					formEl.setAttribute('data-status', 'failed');
				}
			}

			const $output = $form.closest('.wpcf7').find('.wpcf7-response-output');
			if ($output.length) {
				const isCancelType = status === 'cancel';
				const $p = $('<p class="iftp-msg">').addClass(
					isCancelType ? 'iftp-msg-cancel' : 'iftp-msg-error'
				);

				if (paymentUrl) {
					$p.append(
						$('<a>')
							.attr('href', paymentUrl)
							.text(cfg.msg_retry || 'Retry')
					);
					$p.append(
						document.createTextNode(
							' ' + (cfg.msg_or || 'or') + ' '
						)
					);
					$p.append(
						$('<a href="#">')
							.addClass('iftp-new-payment')
							.text(cfg.msg_new_payment || 'New Payment')
					);
				} else {
					$p.append(
						$('<a href="#">')
							.addClass('iftp-new-payment')
							.text(cfg.msg_retry || 'Retry')
					);
					$p.append(
						document.createTextNode(
							' ' + (cfg.msg_or || 'or') + ' '
						)
					);
					$p.append(
						$('<a href="#">')
							.addClass('iftp-new-payment')
							.text(cfg.msg_new_payment || 'New Payment')
					);
				}

				$output.empty().append($p);
			}
		}
	}

	function handlePayClick($button) {
		const $form = $button.closest('form');
		const $field = $button.closest('.iftp-cf7-payment-field');
		const gatewayKey = String($button.data('gateway-key') || '');
		const formId = parseInt(String($field.data('form-id') || ''), 10) || 0;
		const amount = resolveAmount($form, $field);
		const customer = extractCustomer($form);
		const formTitle = String(
			$form.find('.wpcf7-form-title, h2').first().text() || ''
		).trim();

		if (!gatewayKey || !formId) {
			showFieldMessage($field, 'error', null);
			return;
		}
		if (amount <= 0) {
			const $warn = $field.find('.iftp-cf7-runtime-warning');
			$warn
				.html(
					'<p class="iftp-msg iftp-msg-error">' +
						(cfg.warning_amount_missing ||
							'The payment amount is not set.') +
						'</p>'
				)
				.show();
			return;
		}

		const formData = captureFormData($form);

		disableFormButtons($form);

		const origText = String($button.val() || $button.text() || '').trim();
		$button.val(cfg.opening_text || 'Opening payment...');

		apiPost('iftp_cf7_create_payment', {
			form_id: formId,
			gateway_key: gatewayKey,
			amount,
			customer_name: customer.name,
			customer_email: customer.email,
			form_title: formTitle,
			form_data: JSON.stringify(formData),
		})
			.done(function (response) {
				const data = (response && response.data) || {};

				if (!response || !response.success) {
					enableFormButtons($form);
					$button.val(origText);
					const $warn = $field.find('.iftp-cf7-runtime-warning');
					const msg =
						data.message ||
						'Unable to create payment. Please try again.';
					$warn
						.empty()
						.removeClass(
							'iftp-warn-pending iftp-warn-cancel iftp-warn-error'
						)
						.addClass('iftp-warn-error')
						.append(
							$('<p class="iftp-msg iftp-msg-error">').text(msg)
						)
						.show();
					return;
				}

				const paymentUrl = String(
					data.iframe_url || data.payment_url || ''
				);
				if (!paymentUrl) {
					enableFormButtons($form);
					$button.text(origText);
					return;
				}

				saveSession({
					entry_id: data.entry_id,
					payment_url: paymentUrl,
					form_id: formId,
					form_data: formData,
					ts: Date.now(),
				});

				window.location.href = paymentUrl;
			})
			.fail(function () {
				enableFormButtons($form);
				$button.val(origText);
				showFieldMessage($field, 'error', null);
			});
	}

	document.addEventListener('wpcf7mailsent', function () {
		if (!pendingAfterSuccess) {
			return;
		}
		pendingAfterSuccess = false;


		setTimeout(function () {
			try {
				const url = new URL(window.location.href);
				url.hash = '';
				history.replaceState({}, document.title, url.toString());
			} catch (_e) {}
		}, 0);
	});

	function initThemeLogos() {
		document
			.querySelectorAll('.iftp-cf7-payment-field')
			.forEach(function (el) {
				const probe = el.querySelector('label, p');
				if (!probe) {
					return;
				}
				const color = window.getComputedStyle(probe).color;
				const m = /rgba?\((\d+)[,\s]+(\d+)[,\s]+(\d+)/.exec(color);
				if (!m) {
					return;
				}
				const toLinear = function (c) {
					const s = parseInt(c, 10) / 255;
					return s <= 0.03928
						? s / 12.92
						: Math.pow((s + 0.055) / 1.055, 2.4);
				};
				const lum =
					0.2126 * toLinear(m[1]) +
					0.7152 * toLinear(m[2]) +
					0.0722 * toLinear(m[3]);
				if (lum > 0.5) {
					el.querySelectorAll('img[data-logo-dark]').forEach(
						function (img) {
							const dark = img.getAttribute('data-logo-dark');
							if (dark) {
								img.src = dark;
							}
						}
					);
				}
			});
	}

	function validateCF7Fields($form) {

		$form.find('.iftp-cf7-not-valid-tip').remove();
		$form
			.find('.iftp-cf7-not-valid')
			.removeClass('iftp-cf7-not-valid wpcf7-not-valid')
			.attr('aria-invalid', 'false');

		let valid = true;

		function markInvalid($field, message) {
			$field
				.addClass('wpcf7-not-valid iftp-cf7-not-valid')
				.attr('aria-invalid', 'true');
			const $wrap = $field.closest('.wpcf7-form-control-wrap');
			$(
				'<span class="wpcf7-not-valid-tip iftp-cf7-not-valid-tip" aria-hidden="true"></span>'
			)
				.text(message)
				.appendTo($wrap.length ? $wrap : $field.parent());
			valid = false;
		}


		$form
			.find(
				'input[aria-required="true"], textarea[aria-required="true"], select[aria-required="true"]'
			)
			.each(function () {
				const $field = $(this);
				if (!String($field.val() || '').trim()) {
					markInvalid(
						$field,
						cfg.error_field_required || 'Please fill in this field.'
					);
				}
			});


		$form.find('input[type="email"]').each(function () {
			const $field = $(this);
			const val = String($field.val() || '');
			if (val.length > 100) {
				markInvalid(
					$field,
					cfg.error_email_too_long ||
						'Email address must be 100 characters or fewer.'
				);
			}
		});

		return valid;
	}

	$(function () {
		handleReturnParams();
		initThemeLogos();

		$(document).on(
			'click',
			'.iftp-cf7-pay-button:not([disabled])',
			function (e) {
				e.preventDefault();
				const $button = $(this);
				const $form = $button.closest('form');
				const formEl = $form[0];
				if (
					formEl &&
					typeof formEl.checkValidity === 'function' &&
					!formEl.checkValidity()
				) {
					if (typeof formEl.reportValidity === 'function') {
						formEl.reportValidity();
					}
					return;
				}
				if (!validateCF7Fields($form)) {
					return;
				}
				handlePayClick($button);
			}
		);


		$(document).on(
			'input change',
			'.wpcf7-form-control.iftp-cf7-not-valid',
			function () {
				const $field = $(this);
				$field
					.removeClass('iftp-cf7-not-valid wpcf7-not-valid')
					.attr('aria-invalid', 'false');
				const $wrap = $field.closest('.wpcf7-form-control-wrap');
				($wrap.length ? $wrap : $field.parent())
					.find('.iftp-cf7-not-valid-tip')
					.remove();
			}
		);

		$(document).on('click', '.iftp-new-payment', function (e) {
			e.preventDefault();
			clearSession();
			window.location.reload();
		});
	});
})(jQuery);
