(function ($) {
	'use strict';


	function iftpOdometer(el, newText) {
		if (!el) {
			return;
		}
		el.innerHTML = '';
		let digitIdx = 0;
		String(newText)
			.split('')
			.forEach(function (ch) {
				if (/\d/.test(ch)) {
					const slot = document.createElement('span');
					slot.className = 'iftp-od-slot';
					const reel = document.createElement('span');
					reel.className = 'iftp-od-reel';
					for (let i = 0; i <= 9; i++) {
						const d = document.createElement('span');
						d.textContent = i;
						reel.appendChild(d);
					}
					slot.appendChild(reel);
					el.appendChild(slot);
					(function (r, target, delay) {
						requestAnimationFrame(function () {
							requestAnimationFrame(function () {
								r.style.transition =
									'transform .55s ' +
									delay +
									'ms cubic-bezier(.23,1,.32,1)';
								r.style.transform =
									'translateY(-' + target + 'em)';
							});
						});
					})(reel, parseInt(ch, 10), digitIdx++ * 25);
				} else {
					const node = document.createElement('span');
					node.className = 'iftp-od-sym';
					node.textContent = ch;
					el.appendChild(node);
				}
			});
	}


	function iftpCountSlide(el, text, delay) {
		if (!el) {
			return;
		}
		el.classList.remove('iftp-count-entering');
		void el.offsetWidth;
		el.classList.add('iftp-count-exiting');
		setTimeout(
			function () {
				el.textContent = String(text);
				el.classList.remove('iftp-count-exiting');
				el.classList.add('iftp-count-entering');
			},
			130 + (delay || 0)
		);
	}

	function iftpShowForGateway(val) {
		$('.iftp-gateway-methods').each(function () {
			$(this).toggle($(this).data('gateway') === val);
		});

		iftpRebuildDefaultMethod(val);
	}

	/**
	 * Rebuild #iftp-default-method options based on which methods are currently
	 * checked inside the panel for the given gateway.
	 * @param gateway
	 */
	function iftpRebuildDefaultMethod(gateway) {
		const $sel = $('#iftp-default-method');
		if (!$sel.length) {
			return;
		}

		const saved = $sel.data('saved') || $sel.val() || '';

		$sel.empty();

		$('.iftp-gateway-methods[data-gateway="' + gateway + '"]')
			.find('.iftp-method-item')
			.each(function () {
				const $item = $(this);
				const entity = String($item.data('entity') || '');
				const checked = $item
					.find('.ifthenpay-method-checkbox')
					.is(':checked');
				if (!entity || !checked) {
					return;
				}
				$sel.append($('<option>').val(entity).text(entity));
			});

		if (saved && $sel.find('option[value="' + saved + '"]').length) {
			$sel.val(saved);
		}
	}

	$(document).on('change', '#iftp-gateway-key', function () {
		iftpShowForGateway(String($(this).val() || ''));
	});

	$(document).on('change', '.ifthenpay-method-checkbox', function () {
		const gateway = String($('#iftp-gateway-key').val() || '');
		const entity_uc = String(
			$(this).closest('.iftp-method-item').data('entity') || ''
		).toUpperCase();
		if (entity_uc) {
			const saved = (window.iftpCf7Admin || {}).saved_methods || {};
			if (!saved[entity_uc]) {
				saved[entity_uc] = {};
			}
			saved[entity_uc].enabled = $(this).is(':checked');
			if (window.iftpCf7Admin) {
				window.iftpCf7Admin.saved_methods = saved;
			}
		}
		iftpRebuildDefaultMethod(gateway);
	});

	$(document).on('click', '.ifthenpay-activate', function (e) {
		e.preventDefault();
		const $link = $(this);
		const entity = String($link.data('entity') || '');
		const gateway = String($link.data('gateway') || '');
		if (!entity || !gateway) {
			return;
		}

		$link.text('Sending…');

		$.post(
			(window.iftpCf7Admin || {}).ajax_url || '',
			{
				action: 'iftp_cf7_activate_payment_method',
				nonce: (window.iftpCf7Admin || {}).nonce || '',
				method: entity,
				gateway_key: gateway,
			},
			null,
			'json'
		)
			.done(function (response) {
				const msg =
					response && response.data && response.data.message
						? String(response.data.message)
						: response && response.success
							? 'Sent.'
							: 'Failed.';
				$link.replaceWith($('<em>').text(msg));
			})
			.fail(function () {
				$link.text('Failed. Retry?');
			});
	});

	$(document).on(
		'click',
		'#iftp-copy-callback, #iftp-copy-antiphish',
		function () {
			const targetId =
				$(this).attr('id') === 'iftp-copy-callback'
					? '#iftp-callback-url'
					: '#iftp-anti-phishing';
			const $input = $(targetId);
			if (!$input.length) {
				return;
			}

			const text = String($input.val() || '');
			const $btn = $(this);

			if (navigator.clipboard) {
				navigator.clipboard.writeText(text).then(function () {
					$btn.text('Copied!');
					setTimeout(function () {
						$btn.text('Copy');
					}, 2000);
				});
			} else {
				$input.select();
				try {
					document.execCommand('copy');
				} catch (_e) {}
				$btn.text('Copied!');
				setTimeout(function () {
					$btn.text('Copy');
				}, 2000);
			}
		}
	);

	$(document).on('change', '#iftp-cf7-enabled', function () {
		$('.iftp-cf7-row').toggleClass(
			'iftp-cf7-row--hidden',
			!$(this).is(':checked')
		);
	});

	$(document).on(
		'change',
		'input[name="iftp_cf7_amount_source"]',
		function () {
			const isFixed = $(this).val() === 'fixed';
			$('input[name="iftp_cf7_amount_fixed"]').prop('disabled', !isFixed);
			$('input[name="iftp_cf7_amount_field"]').prop('disabled', isFixed);
		}
	);


	const IFTP_SEL_KEY = 'iftp_cf7_selected_ids';

	function iftpSelGet() {
		try {
			return JSON.parse(sessionStorage.getItem(IFTP_SEL_KEY) || '[]');
		} catch (e) {
			return [];
		}
	}
	function iftpSelSet(ids) {
		sessionStorage.setItem(IFTP_SEL_KEY, JSON.stringify(ids));
	}
	function iftpSelAdd(id) {
		const ids = iftpSelGet();
		if (ids.indexOf(id) === -1) {
			ids.push(id);
		}
		iftpSelSet(ids);
	}
	function iftpSelRemove(id) {
		iftpSelSet(
			iftpSelGet().filter(function (i) {
				return i !== id;
			})
		);
	}
	function iftpSelClear() {
		sessionStorage.removeItem(IFTP_SEL_KEY);
	}

	function iftpSelUpdateUI() {
		const ids = iftpSelGet();
		const count = ids.length;
		const $dn = $('.displaying-num');

		if (count > 0) {
			const total = $dn.data('total') || '';
			const label = count + ' selected out of ' + total;
			if ($dn.data('orig') === undefined) {
				$dn.data('orig', $dn.html());
			}
			$dn.html(
				'<strong>' +
					label +
					'</strong>' +
					' <a href="#" id="iftp-clear-selection" style="margin-left:6px;">(Clear)</a>'
			);
		} else if ($dn.data('orig') !== undefined) {
			$dn.html($dn.data('orig'));
		}

		const $boxes = $('input[name="entry_ids[]"]');
		const total = $boxes.length;
		let checked = 0;
		$boxes.each(function () {
			const inSel = ids.indexOf($(this).val()) !== -1;
			$(this).prop('checked', inSel);
			if (inSel) {
				checked++;
			}
		});
		const $masters = $('#cb-select-all, #cb-select-all-2');
		$masters.prop('checked', total > 0 && checked === total);
		$masters.prop('indeterminate', checked > 0 && checked < total);
	}

	$(document).on('change', '#cb-select-all, #cb-select-all-2', function () {
		const isChecked = $(this).is(':checked');
		$('input[name="entry_ids[]"]').each(function () {
			if (isChecked) {
				iftpSelAdd($(this).val());
			} else {
				iftpSelRemove($(this).val());
			}
		});
		iftpSelUpdateUI();
	});

	$(document).on('change', 'input[name="entry_ids[]"]', function () {
		if ($(this).is(':checked')) {
			iftpSelAdd($(this).val());
		} else {
			iftpSelRemove($(this).val());
		}
		iftpSelUpdateUI();
	});

	$(document).on('click', '#iftp-clear-selection', function (e) {
		e.preventDefault();
		iftpSelClear();
		$('input[name="entry_ids[]"], #cb-select-all, #cb-select-all-2').prop('checked', false);
		$('.iftp-cf7-entries-table tbody tr').removeClass('iftp-row-selected');
		iftpSelUpdateUI();
	});

	$(document).on(
		'click',
		'#iftp-bulk-form input[type="submit"]',
		function () {
			const $form = $(this).closest('form');
			const action =
				$form.find('select[name="action"]').val() ||
				$form.find('select[name="action2"]').val() ||
				'';
			if (action !== 'delete') {
				return true;
			}

			const ids = iftpSelGet();
			const n =
				ids.length || $('input[name="entry_ids[]"]:checked').length;
			if (!n) {
				return true;
			}

			if (
				!window.confirm(
					'Delete ' +
						n +
						' entr' +
						(n === 1 ? 'y' : 'ies') +
						'? This cannot be undone.'
				)
			) {
				return false;
			}
			if (ids.length) {
				$form.find('input[name="entry_ids[]"]').prop('checked', false);
				$form.find('.iftp-sel-inject').remove();
				ids.forEach(function (id) {
					$form.append(
						'<input type="hidden" class="iftp-sel-inject" name="entry_ids[]" value="' +
							Number(id) +
							'">'
					);
				});
				iftpSelClear();
			}
			return true;
		}
	);


	$(document).on('click', '#iftp-refresh-btn', function () {
		window.location.reload();
	});



	const IFTP_METHOD_OPTIONS = [
		{ value: '', label: '— Select method —' },
		{ value: 'MBWAY', label: 'MB WAY' },
		{ value: 'MULTIBANCO', label: 'Multibanco' },
		{ value: 'CARD', label: 'Card' },
		{ value: 'PAYSHOP', label: 'Payshop' },
		{ value: 'PIX', label: 'Pix' },
		{ value: 'COFIDIS', label: 'Cofidis' },
		{ value: 'APPLE', label: 'Apple Pay' },
		{ value: 'GOOGLE', label: 'Google Pay' },
		{ value: 'CASH', label: 'Cash' },
	];

	function iftpCloseAllMethodDropdowns() {
		$('.iftp-method-select__list').hide();
		$('.iftp-method-select').removeClass('iftp-method-select--open');
		$('.iftp-method-select__trigger').attr('aria-expanded', 'false');
	}


	const IFTP_LOGO_ALIASES = { MULTIBANCO: 'MB', CARD: 'CCARD' };

	function iftpGetMethodLogo(logos, value) {
		return logos[value] || logos[IFTP_LOGO_ALIASES[value]] || '';
	}

	function iftpBuildMethodDropdown($select) {
		const logos = (window.iftpCf7Admin || {}).method_logos || {};
		const colors = (window.iftpCf7Admin || {}).method_colors || {};

		$select.hide().attr('aria-hidden', 'true');

		const $wrap = $('<div class="iftp-method-select"></div>');
		const $trigger = $(
			'<button type="button" class="iftp-method-select__trigger" aria-haspopup="listbox" aria-expanded="false"></button>'
		);
		const $icon = $('<span class="iftp-method-select__icon"></span>');
		const $lbl = $('<span class="iftp-method-select__label"></span>').text(
			IFTP_METHOD_OPTIONS[0].label
		);
		const $arrow = $(
			'<svg class="iftp-method-select__arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>'
		);
		$trigger.append($icon, $lbl, $arrow);


		const $list = $(
			'<div class="iftp-method-select__list iftp-method-select__list--portal" role="listbox"></div>'
		).hide();
		$('body').append($list);

		function positionList() {
			const rect = $trigger[0].getBoundingClientRect();
			$list.css({
				position: 'fixed',
				top: Math.round(rect.bottom + 4),
				left: Math.round(rect.left),
				width: Math.round(rect.width),
			});
		}

		function openList() {
			positionList();
			$list.data('iftp-reposition', positionList).show();
			$trigger.attr('aria-expanded', 'true');
			$wrap.addClass('iftp-method-select--open');
		}

		function buildOptIcon($el, value) {
			if (value === 'CASH') {
				$el.append(
					$(
						'<span class="iftp-method-select__opt-emoji" aria-hidden="true">💶</span>'
					)
				);
				return;
			}
			const logo = iftpGetMethodLogo(logos, value);
			const color = colors[value] || '#8c8f94';
			if (logo) {
				$el.append(
					$('<img class="iftp-method-select__opt-img" alt="">').attr(
						'src',
						logo
					)
				);
			} else {
				$el.append(
					$('<span class="iftp-method-select__opt-dot"></span>').css(
						'background',
						color
					)
				);
			}
		}

		IFTP_METHOD_OPTIONS.forEach(function (opt) {
			const $opt = $(
				'<div class="iftp-method-select__opt" role="option"></div>'
			).attr('data-value', opt.value);
			if (opt.value === '') {
				$opt.addClass('iftp-method-select__opt--placeholder').text(
					opt.label
				);
			} else {
				buildOptIcon($opt, opt.value);
				$opt.append($('<span></span>').text(opt.label));
			}
			$opt.on('click', function (e) {
				e.stopPropagation();
				const val = String($(this).data('value') || '');
				$select.val(val).trigger('change');
				$lbl.text(opt.label);
				$icon.empty();
				if (val) {
					buildOptIcon($icon, val);
				}
				$list
					.find('.iftp-method-select__opt--active')
					.removeClass('iftp-method-select__opt--active');
				$opt.addClass('iftp-method-select__opt--active');
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-method-select--open');
			});
			$list.append($opt);
		});

		$trigger.on('click', function (e) {
			e.stopPropagation();
			const isOpen = $list.is(':visible');
			iftpCloseAllMethodDropdowns();
			iftpCloseAllStatusDropdowns();
			iftpCloseAllBulkDropdowns();
			if (!isOpen) {
				openList();
			}
		});

		$trigger.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$trigger.trigger('click');
			}
			if (e.key === 'Escape') {
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-method-select--open');
			}
		});

		$wrap.append($trigger);
		$select.after($wrap);
	}

	$(document).on('click', iftpCloseAllMethodDropdowns);


	$(document).on('scroll', '.iftp-modal-body', iftpCloseAllMethodDropdowns);


	$(window).on('resize.iftpMethodDropdown', function () {
		$('.iftp-method-select__list:visible').each(function () {
			const fn = $(this).data('iftp-reposition');
			if (typeof fn === 'function') {
				fn();
			}
		});
	});


	$(
		'#iftp-add-payment-modal select[name="ap_payment_method"], #iftp-add-payment-modal select[name="ap_cx_payment_method"]'
	).each(function () {
		iftpBuildMethodDropdown($(this));
	});



	const IFTP_STATUS_OPTIONS = [
		{ value: 'completed', label: 'Paid', color: '#00a32a' },
		{ value: 'pending', label: 'Pending', color: '#d69300' },
		{ value: 'cancelled', label: 'Cancelled', color: '#8c8f94' },
		{ value: 'failed', label: 'Failed', color: '#d63638' },
		{ value: 'expired', label: 'Expired', color: '#2e414e' },
	];

	function iftpCloseAllStatusDropdowns() {
		$('.iftp-status-select__list').hide();
		$('.iftp-status-select').removeClass('iftp-status-select--open');
		$('.iftp-status-select__trigger').attr('aria-expanded', 'false');
	}

	function iftpBuildStatusDropdown($select) {
		$select.hide().attr('aria-hidden', 'true');

		const $wrap = $('<div class="iftp-status-select"></div>');
		const $trigger = $(
			'<button type="button" class="iftp-status-select__trigger" aria-haspopup="listbox" aria-expanded="false"></button>'
		);
		const $icon = $('<span class="iftp-status-select__icon"></span>');
		const $lbl = $('<span class="iftp-status-select__label"></span>');
		const $arrow = $(
			'<svg class="iftp-status-select__arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>'
		);

		const firstOpt = IFTP_STATUS_OPTIONS[0];
		$lbl.text(firstOpt.label);
		$icon.append(
			$('<span class="iftp-status-select__dot"></span>').css(
				'background',
				firstOpt.color
			)
		);
		$trigger.append($icon, $lbl, $arrow);

		const $list = $(
			'<div class="iftp-status-select__list iftp-status-select__list--portal" role="listbox"></div>'
		).hide();
		$('body').append($list);

		function positionList() {
			const rect = $trigger[0].getBoundingClientRect();
			$list.css({
				position: 'fixed',
				top: Math.round(rect.bottom + 4),
				left: Math.round(rect.left),
				width: Math.round(rect.width),
			});
		}

		function openList() {
			positionList();
			$list.data('iftp-reposition', positionList).show();
			$trigger.attr('aria-expanded', 'true');
			$wrap.addClass('iftp-status-select--open');
		}

		IFTP_STATUS_OPTIONS.forEach(function (opt) {
			const $opt = $(
				'<div class="iftp-status-select__opt" role="option"></div>'
			).attr('data-value', opt.value);
			$opt.append(
				$('<span class="iftp-status-select__dot"></span>').css(
					'background',
					opt.color
				)
			);
			$opt.append($('<span></span>').text(opt.label));
			$opt.on('click', function (e) {
				e.stopPropagation();
				const val = String($(this).data('value') || '');
				$select.val(val).trigger('change');
				$lbl.text(opt.label);
				$icon
					.empty()
					.append(
						$('<span class="iftp-status-select__dot"></span>').css(
							'background',
							opt.color
						)
					);
				$list
					.find('.iftp-status-select__opt--active')
					.removeClass('iftp-status-select__opt--active');
				$opt.addClass('iftp-status-select__opt--active');
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-status-select--open');
			});
			$list.append($opt);
		});

		$trigger.on('click', function (e) {
			e.stopPropagation();
			const isOpen = $list.is(':visible');
			iftpCloseAllStatusDropdowns();
			iftpCloseAllMethodDropdowns();
			if (!isOpen) {
				openList();
			}
		});

		$trigger.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$trigger.trigger('click');
			}
			if (e.key === 'Escape') {
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-status-select--open');
			}
		});

		$wrap.append($trigger);
		$select.after($wrap);
	}

	$(document).on('click', iftpCloseAllStatusDropdowns);
	$(document).on('scroll', '.iftp-modal-body', iftpCloseAllStatusDropdowns);

	$(window).on('resize.iftpStatusDropdown', function () {
		$('.iftp-status-select__list:visible').each(function () {
			const fn = $(this).data('iftp-reposition');
			if (typeof fn === 'function') {
				fn();
			}
		});
	});

	$(
		'#iftp-add-payment-modal select[name="ap_payment_status"], #iftp-add-payment-modal select[name="ap_cx_payment_status"]'
	).each(function () {
		iftpBuildStatusDropdown($(this));
	});



	const IFTP_BULK_ICONS = {
		mark_paid: { type: 'dot', color: '#00a32a' },
		mark_cancelled: { type: 'dot', color: '#8c8f94' },
		mark_failed: { type: 'dot', color: '#d63638' },
		mark_pending: { type: 'dot', color: '#d69300' },
		mark_expired: { type: 'dot', color: '#2e414e' },
		delete: {
			type: 'svg',
			html: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>',
		},
	};

	function iftpCloseAllBulkDropdowns() {
		$('.iftp-bulk-select__list').hide();
		$('.iftp-bulk-select').removeClass('iftp-bulk-select--open');
		$('.iftp-bulk-select__trigger').attr('aria-expanded', 'false');
	}

	function iftpBuildBulkDropdown($select) {
		$select.hide().attr('aria-hidden', 'true');

		const $wrap = $('<div class="iftp-bulk-select"></div>');
		const $trigger = $(
			'<button type="button" class="iftp-bulk-select__trigger" aria-haspopup="listbox" aria-expanded="false"></button>'
		);
		const $icon = $('<span class="iftp-bulk-select__icon"></span>');
		const $lbl = $('<span class="iftp-bulk-select__label"></span>');
		const $arrow = $(
			'<svg class="iftp-bulk-select__arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>'
		);

		$lbl.text($select.find('option[value="-1"]').text() || 'Bulk Actions');
		$trigger.append($icon, $lbl, $arrow);

		const $list = $(
			'<div class="iftp-bulk-select__list iftp-bulk-select__list--portal" role="listbox"></div>'
		).hide();
		$('body').append($list);

		function positionList() {
			const rect = $trigger[0].getBoundingClientRect();
			$list.css({
				position: 'fixed',
				top: Math.round(rect.bottom + 4),
				left: Math.round(rect.left),
				width: Math.max(Math.round(rect.width), 190),
			});
		}

		function openList() {
			positionList();
			$list.data('iftp-reposition', positionList).show();
			$trigger.attr('aria-expanded', 'true');
			$wrap.addClass('iftp-bulk-select--open');
		}

		function buildOptIcon($el, value) {
			const info = IFTP_BULK_ICONS[value];
			if (!info) {
				return;
			}
			if (info.type === 'dot') {
				$el.append(
					$('<span class="iftp-bulk-select__dot"></span>').css(
						'background',
						info.color
					)
				);
			} else if (info.type === 'svg') {
				$el.append($(info.html));
			}
		}

		$select.find('option').each(function () {
			const val = String($(this).val() || '');
			const txt = String($(this).text() || '');
			const $opt = $(
				'<div class="iftp-bulk-select__opt" role="option"></div>'
			).attr('data-value', val);
			if (val === '-1') {
				$opt.addClass('iftp-bulk-select__opt--placeholder').text(txt);
			} else {
				if (val === 'delete') {
					$opt.addClass('iftp-bulk-select__opt--danger');
				}
				buildOptIcon($opt, val);
				$opt.append($('<span></span>').text(txt));
			}
			$opt.on('click', function (e) {
				e.stopPropagation();
				const chosen = String($(this).data('value') || '');
				$select.val(chosen).trigger('change');
				if (chosen === '-1') {
					$lbl.text(txt);
					$icon.empty();
					$list
						.find('.iftp-bulk-select__opt--active')
						.removeClass('iftp-bulk-select__opt--active');
				} else {
					$lbl.text(txt);
					$icon.empty();
					buildOptIcon($icon, chosen);
					$list
						.find('.iftp-bulk-select__opt--active')
						.removeClass('iftp-bulk-select__opt--active');
					$opt.addClass('iftp-bulk-select__opt--active');
				}
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-bulk-select--open');
			});
			$list.append($opt);
		});

		$trigger.on('click', function (e) {
			e.stopPropagation();
			const isOpen = $list.is(':visible');
			iftpCloseAllBulkDropdowns();
			iftpCloseAllMethodDropdowns();
			iftpCloseAllStatusDropdowns();
			iftpCloseAllPerPageDropdowns();
			iftpCloseAllFilterDropdowns();
			if (!isOpen) {
				openList();
			}
		});

		$trigger.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$trigger.trigger('click');
			}
			if (e.key === 'Escape') {
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-bulk-select--open');
			}
		});

		$wrap.append($trigger);
		$select.after($wrap);
	}

	$(document).on('click', function (e) {
		if (!$(e.target).closest('.iftp-bulk-select').length) {
			iftpCloseAllBulkDropdowns();
		}
	});

	$(window).on(
		'resize.iftpBulkDropdown scroll.iftpBulkDropdown',
		function () {
			$('.iftp-bulk-select__list:visible').each(function () {
				const fn = $(this).data('iftp-reposition');
				if (typeof fn === 'function') {
					fn();
				}
			});
		}
	);


	$('select[name="action"], select[name="action2"]')
		.filter(function () {
			const $s = $(this);
			return (
				$s.closest('#iftp-bulk-form').length > 0 ||
				$s.attr('form') === 'iftp-bulk-form'
			);
		})
		.each(function () {
			iftpBuildBulkDropdown($(this));
		});



	function iftpCloseAllPerPageDropdowns() {
		$('.iftp-perpage-select__list').hide();
		$('.iftp-perpage-select').removeClass('iftp-perpage-select--open');
		$('.iftp-perpage-select__trigger').attr('aria-expanded', 'false');
	}

	function iftpBuildPerPageDropdown($select) {
		$select.hide().attr('aria-hidden', 'true');

		const $wrap = $('<div class="iftp-perpage-select"></div>');
		const $trigger = $(
			'<button type="button" class="iftp-perpage-select__trigger" aria-haspopup="listbox" aria-expanded="false"></button>'
		);
		const $lbl = $('<span class="iftp-perpage-select__label"></span>');
		const $arrow = $(
			'<svg class="iftp-perpage-select__arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>'
		);

		let currentVal = String($select.data('current') || $select.val() || '');
		$lbl.text(
			$select.find('option[value="' + currentVal + '"]').text() ||
				$select.find('option').first().text()
		);
		$trigger.append($lbl, $arrow);

		const $list = $(
			'<div class="iftp-perpage-select__list iftp-perpage-select__list--portal" role="listbox"></div>'
		).hide();
		$('body').append($list);

		function positionList() {
			const rect = $trigger[0].getBoundingClientRect();
			$list.css({
				position: 'fixed',
				top: Math.round(rect.bottom + 4),
				left: Math.round(rect.left),
				width: Math.round(rect.width),
			});
		}

		function openList() {
			positionList();
			$list.data('iftp-reposition', positionList).show();
			$trigger.attr('aria-expanded', 'true');
			$wrap.addClass('iftp-perpage-select--open');
		}

		$select.find('option').each(function () {
			const val = String($(this).val() || '');
			const txt = String($(this).text() || '');
			const $opt = $(
				'<div class="iftp-perpage-select__opt" role="option"></div>'
			)
				.attr('data-value', val)
				.text(txt);
			if (val === currentVal) {
				$opt.addClass('iftp-perpage-select__opt--active');
			}
			$opt.on('click', function (e) {
				e.stopPropagation();
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-perpage-select--open');
				if (val === currentVal) {
					return;
				}
				currentVal = val;
				$lbl.text(txt);
				$list
					.find('.iftp-perpage-select__opt--active')
					.removeClass('iftp-perpage-select__opt--active');
				$opt.addClass('iftp-perpage-select__opt--active');
				$select.val(val).trigger('change');
			});
			$list.append($opt);
		});

		$trigger.on('click', function (e) {
			e.stopPropagation();
			const isOpen = $list.is(':visible');
			iftpCloseAllPerPageDropdowns();
			iftpCloseAllBulkDropdowns();
			iftpCloseAllFilterDropdowns();
			if (!isOpen) {
				openList();
			}
		});

		$trigger.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$trigger.trigger('click');
			}
			if (e.key === 'Escape') {
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-perpage-select--open');
			}
		});

		$wrap.append($trigger);
		$select.after($wrap);
	}

	$(document).on('click', function (e) {
		if (!$(e.target).closest('.iftp-perpage-select').length) {
			iftpCloseAllPerPageDropdowns();
		}
	});

	$(window).on(
		'resize.iftpPerPageDropdown scroll.iftpPerPageDropdown',
		function () {
			$('.iftp-perpage-select__list:visible').each(function () {
				const fn = $(this).data('iftp-reposition');
				if (typeof fn === 'function') {
					fn();
				}
			});
		}
	);

	$('.iftp-per-page-select').each(function () {
		iftpBuildPerPageDropdown($(this));
	});



	function iftpModalOpen() {
		$('#iftp-add-payment-form')[0].reset();
		iftpCloseAllFilterDropdowns();
		iftpCloseAllBulkDropdowns();
		iftpCloseAllPerPageDropdowns();

		iftpCloseAllMethodDropdowns();
		$('.iftp-method-select__opt--active').removeClass(
			'iftp-method-select__opt--active'
		);
		$('#iftp-add-payment-modal .iftp-method-select').each(function () {
			$(this)
				.find('.iftp-method-select__label')
				.text(IFTP_METHOD_OPTIONS[0].label);
			$(this).find('.iftp-method-select__icon').empty();
		});

		iftpCloseAllStatusDropdowns();
		$('.iftp-status-select__opt--active').removeClass(
			'iftp-status-select__opt--active'
		);
		$('#iftp-add-payment-modal .iftp-status-select').each(function () {
			const firstOpt = IFTP_STATUS_OPTIONS[0];
			$(this).find('.iftp-status-select__label').text(firstOpt.label);
			const $ic = $(this).find('.iftp-status-select__icon');
			$ic.empty().append(
				$('<span class="iftp-status-select__dot"></span>').css(
					'background',
					firstOpt.color
				)
			);
		});

		$('#iftp-add-payment-modal .iftp-mode-btn').removeClass(
			'iftp-mode-btn--active'
		);
		$(
			'#iftp-add-payment-modal .iftp-mode-btn[data-mode="simple"]'
		).addClass('iftp-mode-btn--active');
		$('#iftp-add-payment-modal .iftp-mode-panel--complex').hide();
		$('#iftp-add-payment-modal .iftp-mode-panel--simple').show();
		$('.iftp-modal-error').hide().text('');
		$('.iftp-modal-submit')
			.prop('disabled', false)
			.text(
				(window.iftpCf7Admin || {}).add_payment_label || 'Add Payment'
			);

		const _sw = window.innerWidth - document.documentElement.clientWidth;
		$('body')
			.css('padding-right', _sw > 0 ? _sw + 'px' : '')
			.addClass('iftp-modal-open');
		const $modal = $('#iftp-add-payment-modal');
		$modal.show();
		void $modal[0].offsetWidth;
		$modal.addClass('iftp-modal--open');
		$('#ap_amount').trigger('focus');

		setTimeout(function () {
			$('#iftp-ap-toast').addClass('iftp-ap-toast--visible');
		}, 220);
	}

	$(document).on(
		'click',
		'#iftp-add-payment-modal .iftp-mode-btn',
		function () {
			const mode = String($(this).data('mode') || 'simple');
			const $modal = $('#iftp-add-payment-modal');
			$modal.find('.iftp-mode-btn').removeClass('iftp-mode-btn--active');
			$(this).addClass('iftp-mode-btn--active');
			iftpCloseAllMethodDropdowns();
			const $outgoing = $modal.find('.iftp-mode-panel:visible');
			const $incoming = $modal.find('.iftp-mode-panel--' + mode);
			$outgoing.fadeOut(80, function () {
				$incoming.fadeIn(160);
				const focusId =
					mode === 'complex' ? '#ap_cx_amount' : '#ap_amount';
				$modal.find(focusId).trigger('focus');
			});
		}
	);

	function iftpModalClose() {
		iftpCloseAllMethodDropdowns();
		$('#iftp-ap-toast').removeClass('iftp-ap-toast--visible');
		const $modal = $('#iftp-add-payment-modal');
		$modal.removeClass('iftp-modal--open');

		setTimeout(function () {
			$modal.hide();
			$('body').css('padding-right', '').removeClass('iftp-modal-open');
		}, 210);
	}

	$(document).on('click', '#iftp-add-payment-btn', function (e) {
		e.preventDefault();
		iftpModalOpen();
	});

	$(document).on(
		'click',
		'.iftp-modal-overlay, .iftp-modal-close, .iftp-modal-cancel',
		function () {
			iftpModalClose();
		}
	);

	$(document).on('keydown', function (e) {
		if (
			e.key === 'Escape' &&
			$('#iftp-add-payment-modal').hasClass('iftp-modal--open')
		) {
			iftpModalClose();
		}
	});

	$(document).on('click', '.iftp-modal-box', function (e) {
		e.stopPropagation();
		if (!$(e.target).closest('.iftp-method-select').length) {
			iftpCloseAllMethodDropdowns();
		}
		if (!$(e.target).closest('.iftp-status-select').length) {
			iftpCloseAllStatusDropdowns();
		}
	});

	$(document).on('submit', '#iftp-add-payment-form', function (e) {
		e.preventDefault();
		const $form = $(this);
		const $btn = $form.find('.iftp-modal-submit');
		const $error = $('.iftp-modal-error');
		const isComplex =
			$form
				.closest('.iftp-modal-box')
				.find('.iftp-mode-btn--active')
				.data('mode') === 'complex';
		const amountVal = isComplex
			? String($form.find('[name="ap_cx_amount"]').val() || '0')
			: String($form.find('[name="ap_amount"]').val() || '0');
		const amount = parseFloat(amountVal);

		if (!amount || amount <= 0) {
			$error.text('Please enter a valid amount.').show();
			return;
		}

		$btn.prop('disabled', true).text('Saving…');
		$error.hide().text('');

		const $panel = $form.find(
			isComplex ? '.iftp-mode-panel--complex' : '.iftp-mode-panel--simple'
		);

		const postData = {
			action: 'iftp_cf7_add_payment',
			nonce: (window.iftpCf7Admin || {}).add_payment_nonce || '',
			payment_mode: isComplex ? 'complex' : 'simple',
			customer_name: isComplex
				? $panel.find('[name="ap_sd_name"]').val()
				: $panel.find('[name="ap_customer_name"]').val(),
			customer_email: isComplex
				? $panel.find('[name="ap_sd_email"]').val()
				: $panel.find('[name="ap_customer_email"]').val(),
			customer_ip: '',
			amount: amountVal,
			payment_method: $panel
				.find(
					'[name="ap_cx_payment_method"], [name="ap_payment_method"]'
				)
				.val(),
			payment_status: $panel
				.find(
					'[name="ap_cx_payment_status"], [name="ap_payment_status"]'
				)
				.val(),
			form_title: $panel
				.find('[name="ap_cx_form_title"], [name="ap_form_title"]')
				.val(),
		};

		if (isComplex) {
			const sd = {
				name: $form.find('[name="ap_sd_name"]').val(),
				email: $form.find('[name="ap_sd_email"]').val(),
				localidade: $form.find('[name="ap_sd_localidade"]').val(),
				morada: $form.find('[name="ap_sd_morada"]').val(),
				pais: $form.find('[name="ap_sd_pais"]').val(),
				'codigo-postal': $form
					.find('[name="ap_sd_codigo_postal"]')
					.val(),
				telemovel: $form.find('[name="ap_sd_telemovel"]').val(),
				mensagem: $form.find('[name="ap_sd_mensagem"]').val(),
				'metodo-pagamento': $form
					.find('[name="ap_sd_metodo_pagamento"]')
					.val(),
			};
			const sdFiltered = {};
			Object.keys(sd).forEach(function (k) {
				if (sd[k]) {
					sdFiltered[k] = sd[k];
				}
			});
			if (Object.keys(sdFiltered).length) {
				postData.form_data = JSON.stringify(sdFiltered);
			}
		}

		$.post(
			(window.iftpCf7Admin || {}).ajax_url || '',
			postData,
			null,
			'json'
		)
			.done(function (response) {
				if (response && response.success) {
					iftpModalClose();
					window.location.reload();
				} else {
					const msg =
						response && response.data && response.data.message
							? String(response.data.message)
							: 'Failed to save entry.';
					$error.text(msg).show();
					$btn.prop('disabled', false).text('Add Payment');
				}
			})
			.fail(function () {
				$error.text('Network error. Please try again.').show();
				$btn.prop('disabled', false).text('Add Payment');
			});
	});


	$(document).on('click', '#iftp-ap-toast .iftp-ap-toast-close', function () {
		const $toast = $('#iftp-ap-toast');
		$toast.removeClass('iftp-ap-toast--visible');
		setTimeout(function () {
			$toast.remove();
		}, 320);
		$.post((window.iftpCf7Admin || {}).ajax_url || '', {
			action: 'iftp_cf7_dismiss_ap_notice',
			nonce: (window.iftpCf7Admin || {}).dismiss_notice_nonce || '',
		});
	});


	function iftpInitDashWidget() {
		const elRevenue = document.getElementById('iftp-cf7-dash-revenue');
		const elRevSub = document.getElementById('iftp-cf7-dash-rev-sub');
		const elPending = document.getElementById(
			'iftp-cf7-dash-count-pending'
		);
		const elCompleted = document.getElementById(
			'iftp-cf7-dash-count-completed'
		);
		const elFailed = document.getElementById('iftp-cf7-dash-count-failed');
		const elCancelled = document.getElementById(
			'iftp-cf7-dash-count-cancelled'
		);

		if (!elRevenue) {
			return;
		}

		const dataEl = document.getElementById('iftp-cf7-dash-data');
		if (!dataEl) {
			return;
		}

		let allData;
		try {
			allData = JSON.parse(dataEl.dataset.chart || '{}');
		} catch (_e) {
			allData = {};
		}

		const paidTpl =
			(elRevSub && elRevSub.dataset.template) ||
			'from %d paid transactions';

		function formatRevenue(n) {
			const parts = Number(n).toFixed(2).split('.');
			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
			return '€' + parts.join('.');
		}

		function applyPeriod(period) {
			const d = allData[period];
			if (!d) {
				return;
			}
			iftpOdometer(elRevenue, formatRevenue(d.revenue || 0));
			if (elRevSub) {
				elRevSub.textContent = paidTpl.replace(
					'%d',
					d.counts.completed || 0
				);
			}
			iftpCountSlide(elCompleted, d.counts.completed || 0, 0);
			iftpCountSlide(elPending, d.counts.pending || 0, 60);
			iftpCountSlide(elFailed, d.counts.failed || 0, 120);
			iftpCountSlide(elCancelled, d.counts.cancelled || 0, 180);
		}

		let currentPeriod = String(dataEl.dataset.period || '7');
		applyPeriod(currentPeriod);

		const trigger = document.getElementById('iftp-dash-period-trigger');
		const panel = document.getElementById('iftp-dash-period-panel');
		const label = document.getElementById('iftp-dash-period-label');

		if (trigger && panel) {
			function openDashPanel() {
				panel.removeAttribute('hidden');
				trigger.setAttribute('aria-expanded', 'true');
			}
			function closeDashPanel() {
				panel.setAttribute('hidden', '');
				trigger.setAttribute('aria-expanded', 'false');
			}

			trigger.addEventListener('click', function (e) {
				e.stopPropagation();
				if (panel.hasAttribute('hidden')) {
					openDashPanel();
				} else {
					closeDashPanel();
				}
			});

			trigger.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') {
					closeDashPanel();
				}
			});

			document.addEventListener('click', function (e) {
				const dropdown = document.getElementById(
					'iftp-dash-period-dropdown'
				);
				if (dropdown && !dropdown.contains(e.target)) {
					closeDashPanel();
				}
			});

			panel.addEventListener('click', function (e) {
				const opt = e.target.closest('.iftp-period-opt[data-period]');
				if (!opt) {
					return;
				}

				currentPeriod = String(opt.dataset.period || '7');
				if (label) {
					label.textContent =
						opt.dataset.label || opt.textContent.trim();
				}
				panel
					.querySelectorAll('.iftp-period-opt')
					.forEach(function (o) {
						o.classList.toggle(
							'active',
							o.dataset.period === currentPeriod
						);
					});
				closeDashPanel();
				applyPeriod(currentPeriod);
			});
		}
	}


	function iftpInitCheckboxes() {

		const MARK =
			'<span class="iftp-checkmark" aria-hidden="true">' +
			'<svg viewBox="0 0 10 10" fill="none">' +
			'<polyline class="iftp-check-path" points="1.5,5.5 4,8 8.5,2.5"/>' +
			'</svg></span>';

		$(
			'.iftp-col-visibility-cb, ' +
			'.iftp-cf7-entries-table .check-column input[type="checkbox"]'
		).each(function () {
			const $input = $(this);
			if ($input.parent().hasClass('iftp-cb-wrap')) return;
			$input.wrap('<span class="iftp-cb-wrap"></span>');
			$input.after(MARK);
		});


		function syncRowSelected($input) {
			$input.closest('tbody tr').toggleClass(
				'iftp-row-selected',
				!!$input.prop('checked')
			);
		}


		$(document).on(
			'change',
			'.iftp-cf7-entries-table tbody input[name="entry_ids[]"]',
			function () { syncRowSelected($(this)); }
		);


		$(document).on('change', '#cb-select-all, #cb-select-all-2', function () {
			setTimeout(function () {
				$('.iftp-cf7-entries-table tbody input[name="entry_ids[]"]')
					.each(function () { syncRowSelected($(this)); });
			}, 10);
		});


		$('.iftp-cf7-entries-table tbody input[name="entry_ids[]"]')
			.each(function () { syncRowSelected($(this)); });
	}


	function iftpInitEntriesPrefs() {
		const $wrap = $('.iftp-entries-table-wrap');
		const $table = $wrap.find('.iftp-cf7-entries-table');
		const $btn = $('#iftp-col-customize-btn');
		const $popover = $('#iftp-col-customize-popover');
		const $list = $('#iftp-col-list');

		if (!$wrap.length) {
			return;
		}


		if ($popover.length && $popover.parent().get(0) !== document.body) {
			$popover.appendTo(document.body);
		}

		function savePrefs(partial) {
			return $.post((window.iftpCf7Admin || {}).ajax_url || '', {
				action: 'iftp_cf7_save_entries_prefs',
				nonce: (window.iftpCf7Admin || {}).prefs_nonce || '',
				prefs: JSON.stringify(partial),
			});
		}


		$(document).on('submit', '#iftp-search-form', function (e) {
			const query = $.trim(
				$(this).find('input[name="search_query"]').val()
			);
			if (query === '') {
				e.preventDefault();
			}
		});


		$(document).on(
			'click',
			'.iftp-cf7-entries-main .subsubsub a',
			function (e) {
				if ($(this).hasClass('current')) {
					e.preventDefault();
				}
			}
		);


		$(document).on('change', '.iftp-per-page-select', function () {
			const val = parseInt($(this).val(), 10) || 20;
			try {
				localStorage.setItem('iftp_cf7_per_page', String(val));
			} catch (e) {}
			const url = new URL(window.location.href);
			url.searchParams.set('per_page', String(val));
			url.searchParams.set('paged', '1');
			url.searchParams.delete('cursor');
			url.searchParams.delete('dir');
			window.location.href = url.toString();
		});


		$(document).on(
			'keydown',
			'.iftp-pagination-links .current-page',
			function (e) {
				if (e.key !== 'Enter') {
					return;
				}
				e.preventDefault();
				const page = parseInt($(this).val(), 10);
				const baseUrl = $(this).data('jump-url');
				if (!isNaN(page) && page >= 1 && baseUrl) {
					const url = new URL(baseUrl, window.location.origin);
					url.searchParams.set('paged', String(page));
					url.searchParams.delete('cursor');
					url.searchParams.delete('dir');
					window.location.href = url.toString();
				}
			}
		);


		function openPopover() {
			$popover.removeAttr('hidden');
			$btn.attr('aria-expanded', 'true');

			const rect = $btn[0].getBoundingClientRect();
			const margin = 8;
			const popW = $popover.outerWidth();
			const viewW = document.documentElement.clientWidth;
			let left = rect.left + window.pageXOffset;
			const maxLeft = window.pageXOffset + viewW - popW - margin;
			if (left > maxLeft) {
				left = Math.max(window.pageXOffset + margin, maxLeft);
			}
			$popover.css({
				top: rect.bottom + window.pageYOffset + 4 + 'px',
				left: left + 'px',
			});
		}

		function closePopover() {
			$popover.attr('hidden', '');
			$btn.attr('aria-expanded', 'false');
		}

		$btn.on('click', function () {
			if ($popover.is(':not([hidden])')) {
				closePopover();
			} else {
				openPopover();
			}
		});

		$popover.on('click', '.iftp-col-customize-close', closePopover);

		$(document).on('click.iftpColPop', function (e) {
			if ($popover.is('[hidden]')) {
				return;
			}
			if (
				!$(e.target).closest(
					'#iftp-col-customize-popover, #iftp-col-customize-btn'
				).length
			) {
				closePopover();
			}
		});

		$(document).on('keydown.iftpColPop', function (e) {
			if (e.key === 'Escape' && $popover.is(':not([hidden])')) {
				closePopover();
				$btn.trigger('focus');
			}
		});

		$(window).on('resize.iftpColPop', function () {
			if ($popover.is(':not([hidden])')) {
				openPopover();
			}
		});


		let dragSrc = null;
		let touchDragSrc = null;

		$list.on('dragstart', '.iftp-col-item', function (e) {
			dragSrc = this;
			$(this).addClass('iftp-col-dragging');
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			e.originalEvent.dataTransfer.setData(
				'text/plain',
				String($(this).data('col'))
			);
		});

		$list.on('dragend', '.iftp-col-item', function () {
			$('.iftp-col-item').removeClass(
				'iftp-col-dragging iftp-col-drag-over'
			);
			dragSrc = null;
		});

		$list.on('dragover', '.iftp-col-item', function (e) {
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'move';
			if (this !== dragSrc) {
				$('.iftp-col-item').removeClass('iftp-col-drag-over');
				$(this).addClass('iftp-col-drag-over');
			}
			return false;
		});

		$list.on('dragleave', '.iftp-col-item', function () {
			$(this).removeClass('iftp-col-drag-over');
		});

		$list.on('drop', '.iftp-col-item', function (e) {
			e.preventDefault();
			if (dragSrc && this !== dragSrc) {
				const $src = $(dragSrc);
				const $tgt = $(this);
				if ($src.index() < $tgt.index()) {
					$src.insertAfter($tgt);
				} else {
					$src.insertBefore($tgt);
				}
			}
			$('.iftp-col-item').removeClass('iftp-col-drag-over');
			return false;
		});


		const listEl = $list[0];
		if (listEl) {
			listEl.addEventListener('touchstart', function (e) {
				const item = e.target.closest('.iftp-col-item');
				if (!item) return;
				touchDragSrc = item;
				item.classList.add('iftp-col-dragging');
			}, { passive: true });

			listEl.addEventListener('touchmove', function (e) {
				if (!touchDragSrc) return;
				e.preventDefault();
				const touch = e.touches[0];
				const el = document.elementFromPoint(touch.clientX, touch.clientY);
				const target = el && el.closest('.iftp-col-item');
				listEl.querySelectorAll('.iftp-col-item').forEach(function (n) {
					n.classList.remove('iftp-col-drag-over');
				});
				if (target && target !== touchDragSrc) {
					target.classList.add('iftp-col-drag-over');
				}
			}, { passive: false });

			listEl.addEventListener('touchend', function (e) {
				if (!touchDragSrc) return;
				const touch = e.changedTouches[0];
				const el = document.elementFromPoint(touch.clientX, touch.clientY);
				const target = el && el.closest('.iftp-col-item');
				if (target && target !== touchDragSrc) {
					const $src = $(touchDragSrc);
					const $tgt = $(target);
					if ($src.index() < $tgt.index()) {
						$src.insertAfter($tgt);
					} else {
						$src.insertBefore($tgt);
					}
				}
				listEl.querySelectorAll('.iftp-col-item').forEach(function (n) {
					n.classList.remove('iftp-col-dragging', 'iftp-col-drag-over');
				});
				touchDragSrc = null;
			}, { passive: true });
		}


		function reorderTableCols(order) {
			$table.find('thead tr, tfoot tr').each(function () {
				const $row = $(this);
				$.each(order, function (i, colKey) {
					const $cell = $row.find('[data-col="' + colKey + '"]');
					if ($cell.length) {
						$row.append($cell);
					}
				});
			});
			$table.find('tbody tr').each(function () {
				const $row = $(this);
				$.each(order, function (i, colKey) {
					const $cell = $row.find('[data-col="' + colKey + '"]');
					if ($cell.length) {
						$row.append($cell);
					}
				});
			});
		}


		function toggleTableCol(colKey, visible) {
			const $cells = $table.find('[data-col="' + colKey + '"]');
			if (visible) {
				$cells.css({ display: 'table-cell', opacity: '0' });

				requestAnimationFrame(function () {
					requestAnimationFrame(function () {
						$cells.css('opacity', '');
					});
				});
			} else {
				$cells.addClass('iftp-col-hiding');
				setTimeout(function () {
					$cells.filter('.iftp-col-hiding').css('display', 'none').removeClass('iftp-col-hiding');
				}, 190);
			}
		}


		(function () {
			try {
				const stored = localStorage.getItem('iftp_cf7_columns');
				if (!stored) {
					return;
				}
				const cols = JSON.parse(stored);
				if (!cols) {
					return;
				}
				if (Array.isArray(cols.positions) && cols.positions.length) {
					reorderTableCols(cols.positions);
					cols.positions.forEach(function (colKey) {
						const $item = $list.find(
							'.iftp-col-item[data-col="' + colKey + '"]'
						);
						if ($item.length) {
							$list.append($item);
						}
					});
				}
				if (Array.isArray(cols.visible)) {
					$list.find('.iftp-col-item').each(function () {
						const col = String($(this).data('col'));
						const vis = cols.visible.indexOf(col) !== -1;
						$(this)
							.find('.iftp-col-visibility-cb')
							.prop('checked', vis);
						toggleTableCol(col, vis);
					});
				}
			} catch (e) {}
		})();


		$list.on('change', '.iftp-col-visibility-cb', function (e) {
			e.stopPropagation();
			const col = String($(this).closest('.iftp-col-item').data('col'));
			toggleTableCol(col, $(this).is(':checked'));
			const newOrder = [];
			const visibleCols = [];
			$list.find('.iftp-col-item').each(function () {
				const c = String($(this).data('col'));
				newOrder.push(c);
				if ($(this).find('.iftp-col-visibility-cb').is(':checked')) {
					visibleCols.push(c);
				}
			});
			try {
				localStorage.setItem(
					'iftp_cf7_columns',
					JSON.stringify({
						positions: newOrder,
						visible: visibleCols,
					})
				);
			} catch (e) {}
			savePrefs({
				column_positions: newOrder,
				visible_columns: visibleCols,
			});
		});


		$('#iftp-col-customize-save').on('click', function () {
			const newOrder = [];
			const visibleCols = [];
			$list.find('.iftp-col-item').each(function () {
				const col = String($(this).data('col'));
				newOrder.push(col);
				if ($(this).find('.iftp-col-visibility-cb').is(':checked')) {
					visibleCols.push(col);
				}
			});
			reorderTableCols(newOrder);
			newOrder.forEach(function (col) {
				toggleTableCol(col, visibleCols.indexOf(col) !== -1);
			});
			try {
				localStorage.setItem(
					'iftp_cf7_columns',
					JSON.stringify({
						positions: newOrder,
						visible: visibleCols,
					})
				);
			} catch (e) {}
			savePrefs({
				column_positions: newOrder,
				visible_columns: visibleCols,
			});
			closePopover();
		});


		$('#iftp-col-customize-reset').on('click', function () {
			const defaults =
				(window.iftpCf7Admin || {}).default_col_order || [];
			if (!defaults.length) {
				return;
			}
			reorderTableCols(defaults);
			defaults.forEach(function (col) {
				toggleTableCol(col, true);
			});
			defaults.forEach(function (colKey) {
				const $item = $list.find(
					'.iftp-col-item[data-col="' + colKey + '"]'
				);
				if ($item.length) {
					$list.append($item);
				}
			});
			$list.find('.iftp-col-visibility-cb').prop('checked', true);
			try {
				localStorage.removeItem('iftp_cf7_columns');
			} catch (e) {}
			savePrefs({
				column_positions: defaults,
				visible_columns: defaults,
			});
			closePopover();
		});
	}

	$(function () {
		const $gatewaySel = $('#iftp-gateway-key');
		if ($gatewaySel.length) {
			iftpShowForGateway(String($gatewaySel.val() || ''));
		}

		const $enabledCb = $('#iftp-cf7-enabled');
		if ($enabledCb.length) {
			$('.iftp-cf7-row').toggleClass(
				'iftp-cf7-row--hidden',
				!$enabledCb.is(':checked')
			);
			const src =
				$('input[name="iftp_cf7_amount_source"]:checked').val() ||
				'fixed';
			$('input[name="iftp_cf7_amount_fixed"]').prop(
				'disabled',
				src !== 'fixed'
			);
			$('input[name="iftp_cf7_amount_field"]').prop(
				'disabled',
				src !== 'field'
			);
		}

		if ($('#iftp-bulk-form').length) {
			iftpSelUpdateUI();
		}


		const revAmountEl = document.querySelector('.iftp-stat-card-amount');
		if (revAmountEl) {
			iftpOdometer(revAmountEl, revAmountEl.textContent.trim());
		}


		const statsRow = document.querySelector(
			'.iftp-stats-row[data-active-status]'
		);
		if (statsRow) {
			const applyStatDim = function (activeStatus) {
				if (activeStatus === '') {
					statsRow.classList.remove('has-active');
				} else {
					statsRow.classList.add('has-active');
				}
				statsRow
					.querySelectorAll('.iftp-stat-card[data-status]')
					.forEach(function (card) {
						card.classList.toggle(
							'iftp-stat-card--active',
							card.dataset.status === activeStatus
						);
					});
			};
			applyStatDim(statsRow.dataset.activeStatus);
			statsRow
				.querySelectorAll('.iftp-stat-card[data-status]')
				.forEach(function (card) {
					card.addEventListener('click', function () {
						const current = statsRow.dataset.activeStatus;
						applyStatDim(
							current === card.dataset.status
								? ''
								: card.dataset.status
						);
					});
				});
		}


		(function () {
			const $bar = $(
				'.iftp-stat-card--revenue .iftp-rev-bar[data-rev-bar-mode]'
			);
			if (!$bar.length) {
				return;
			}

			const STATUS_BAR_COLORS = {
				completed: '#00a32a',
				pending: '#dba617',
				failed: '#d63638',
				cancelled: '#8c8f94',
			};

			function getCurrentSolidColor() {
				const activeStatus =
					$bar.closest('.iftp-stats-row').data('active-status') || '';
				return STATUS_BAR_COLORS[activeStatus] || '#00609c';
			}

			$bar.on('click keydown', function (e) {
				if (
					e.type === 'keydown' &&
					e.key !== 'Enter' &&
					e.key !== ' '
				) {
					return;
				}
				if (e.type === 'keydown') {
					e.preventDefault();
				}

				const mode =
					$bar.data('rev-bar-mode') === 'solid' ? 'split' : 'solid';
				$bar.find('.iftp-rev-seg--solid').css(
					'background',
					getCurrentSolidColor()
				);
				$bar.toggleClass('iftp-rev-bar--solid', mode === 'solid');
				$bar.data('rev-bar-mode', mode);

				$.post((window.iftpCf7Admin || {}).ajax_url || '', {
					action: 'iftp_cf7_save_entries_prefs',
					nonce: (window.iftpCf7Admin || {}).prefs_nonce || '',
					prefs: JSON.stringify({ rev_bar_mode: mode }),
				});
			});
		})();

		iftpInitDashWidget();
		iftpInitCheckboxes();
		iftpInitEntriesPrefs();

	});



	const IFTP_SEARCH_FIELD_ICONS = {
		customer_name:
			'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
		customer_email:
			'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="2,4 12,13 22,4"/></svg>',
		form_title:
			'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="12" y2="16"/></svg>',
		payment_method:
			'<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
		amount: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
		request_id: '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>',
	};

	function iftpCloseAllFilterDropdowns() {
		$('.iftp-filter-select__list').hide();
		$('.iftp-filter-select').removeClass('iftp-filter-select--open');
		$('.iftp-filter-select__trigger').attr('aria-expanded', 'false');
	}

	function iftpBuildFilterDropdown($select, opts) {
		opts = opts || {};
		const icons = opts.icons || null;

		$select.hide().attr('aria-hidden', 'true');

		const $wrap = $('<div class="iftp-filter-select"></div>');
		const $trigger = $(
			'<button type="button" class="iftp-filter-select__trigger" aria-haspopup="listbox" aria-expanded="false"></button>'
		);
		const $icon = icons
			? $('<span class="iftp-filter-select__icon"></span>')
			: null;
		const $lbl = $('<span class="iftp-filter-select__label"></span>');
		const $arrow = $(
			'<svg class="iftp-filter-select__arrow" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>'
		);

		if (opts.minWidth) {
			$trigger.css('min-width', opts.minWidth + 'px');
		}

		const rawCurrent = $select.attr('data-current');
		let currentVal =
			rawCurrent !== undefined
				? String(rawCurrent)
				: String($select.val() || '');
		$lbl.text(
			$select.find('option[value="' + currentVal + '"]').text() ||
				$select.find('option').first().text()
		);
		if ($icon && icons[currentVal]) {
			$icon.append($(icons[currentVal]));
		}

		if ($icon) {
			$trigger.append($icon);
		}
		$trigger.append($lbl, $arrow);

		const $list = $(
			'<div class="iftp-filter-select__list iftp-filter-select__list--portal" role="listbox"></div>'
		).hide();
		if (opts.scrollable) {
			$list.css({
				maxHeight: (opts.listMaxHeight || 220) + 'px',
				overflowY: 'auto',
			});
		}
		$('body').append($list);

		function positionList() {
			const rect = $trigger[0].getBoundingClientRect();
			$list.css({
				position: 'fixed',
				top: Math.round(rect.bottom + 4),
				left: Math.round(rect.left),
				width: Math.max(Math.round(rect.width), opts.listMinWidth || 0),
			});
		}

		function openList() {
			positionList();
			$list.data('iftp-reposition', positionList).show();
			$trigger.attr('aria-expanded', 'true');
			$wrap.addClass('iftp-filter-select--open');
		}

		$select.find('option').each(function () {
			const val = String($(this).val() || '');
			const txt = String($(this).text() || '');
			const $opt = $(
				'<div class="iftp-filter-select__opt" role="option"></div>'
			).attr('data-value', val);
			if (val === currentVal) {
				$opt.addClass('iftp-filter-select__opt--active');
			}
			if (icons && icons[val]) {
				$opt.append($(icons[val]));
			}
			$opt.append($('<span></span>').text(txt));
			$opt.on('click', function (e) {
				e.stopPropagation();
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-filter-select--open');
				if (val === currentVal) {
					return;
				}
				currentVal = val;
				$lbl.text(txt);
				if ($icon) {
					$icon.empty();
					if (icons && icons[val]) {
						$icon.append($(icons[val]));
					}
				}
				$list
					.find('.iftp-filter-select__opt--active')
					.removeClass('iftp-filter-select__opt--active');
				$opt.addClass('iftp-filter-select__opt--active');
				$select.val(val).trigger('change');
			});
			$list.append($opt);
		});

		$select.data('iftp-filter-update', function (val) {
			const txt =
				$select.find('option[value="' + val + '"]').text() || '';
			$lbl.text(txt);
			if ($icon) {
				$icon.empty();
				if (icons && icons[val]) {
					$icon.append($(icons[val]));
				}
			}
			$list
				.find('.iftp-filter-select__opt--active')
				.removeClass('iftp-filter-select__opt--active');
			$list
				.find('[data-value="' + val + '"]')
				.addClass('iftp-filter-select__opt--active');
			$select.val(val);
		});

		$trigger.on('click', function (e) {
			e.stopPropagation();
			const isOpen = $list.is(':visible');
			iftpCloseAllFilterDropdowns();
			iftpCloseAllBulkDropdowns();
			iftpCloseAllPerPageDropdowns();
			if (!isOpen) {
				openList();
			}
		});

		$trigger.on('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				e.preventDefault();
				$trigger.trigger('click');
			}
			if (e.key === 'Escape') {
				$list.hide();
				$trigger.attr('aria-expanded', 'false');
				$wrap.removeClass('iftp-filter-select--open');
			}
		});

		$wrap.append($trigger);
		$select.after($wrap);
	}

	$(document).on('click', function (e) {
		if (!$(e.target).closest('.iftp-filter-select').length) {
			iftpCloseAllFilterDropdowns();
		}
	});

	$(window).on(
		'resize.iftpFilterDropdown scroll.iftpFilterDropdown',
		function () {
			$('.iftp-filter-select__list:visible').each(function () {
				const fn = $(this).data('iftp-reposition');
				if (typeof fn === 'function') {
					fn();
				}
			});
		}
	);

	$('#iftp-form-filter').each(function () {
		iftpBuildFilterDropdown($(this), {
			minWidth: 180,
			scrollable: true,
			listMaxHeight: 330,
		});
	});

	$('#iftp-search-form select[name="search_field"]').each(function () {
		iftpBuildFilterDropdown($(this), {
			minWidth: 108,
			icons: IFTP_SEARCH_FIELD_ICONS,
		});
	});

	$('#iftp-search-form select[name="search_op"]').each(function () {
		iftpBuildFilterDropdown($(this), { minWidth: 90 });
	});


	(function () {
		const btn = document.getElementById('iftp-scroll-btn');
		if (!btn) {
			return;
		}

		const perPage = parseInt(btn.getAttribute('data-per-page') || '20', 10);
		if (perPage <= 10) {
			return;
		}

		function update() {
			if (window.scrollY > 100) {
				btn.classList.add('iftp-scroll-btn--visible');
			} else {
				btn.classList.remove('iftp-scroll-btn--visible');
			}
		}

		window.addEventListener('scroll', update, { passive: true });
		window.addEventListener('resize', update, { passive: true });
		update();

		btn.addEventListener('click', function () {
			window.scrollTo({ top: 0, behavior: 'smooth' });
		});
	})();


	(function () {
		const dismissBtn = document.getElementById('iftp-info-box-dismiss');
		if (!dismissBtn) {
			return;
		}

		dismissBtn.addEventListener('click', function () {
			const box = document.getElementById('iftp-info-box');
			const nonce = dismissBtn.getAttribute('data-nonce') || '';

			if (box) {
				box.style.transition = 'opacity 0.2s';
				box.style.opacity = '0';
				setTimeout(function () {
					box.remove();
				}, 220);
			}

			if (typeof iftpCf7Admin === 'undefined' || !iftpCf7Admin.ajax_url) {
				return;
			}

			const data = new FormData();
			data.append('action', 'iftp_cf7_dismiss_info_box');
			data.append('nonce', nonce);
			fetch(iftpCf7Admin.ajax_url, {
				method: 'POST',
				body: data,
				credentials: 'same-origin',
			});
		});
	})();


	(function () {
		const $trigger = $('#iftp-period-trigger');
		const $panel = $('#iftp-period-panel');
		if (!$trigger.length) {
			return;
		}

		function openPanel() {
			$panel.removeAttr('hidden');
			$trigger.attr('aria-expanded', 'true');
		}

		function closePanel() {
			$panel.attr('hidden', '');
			$trigger.attr('aria-expanded', 'false');
		}

		$trigger.on('click', function (e) {
			e.stopPropagation();
			if ($panel.attr('hidden') !== undefined) {
				openPanel();
			} else {
				closePanel();
			}
		});

		$trigger.on('keydown', function (e) {
			if (e.key === 'Escape') {
				closePanel();
			}
		});

		$(document).on('click', function (e) {
			if (!$(e.target).closest('#iftp-period-dropdown').length) {
				closePanel();
			}
		});


		$panel.on('click', '.iftp-period-opt', function () {
			$trigger.addClass('is-loading');
			closePanel();

		});
	})();


	(function () {
		if (!$('.iftp-per-page-select').length) {
			return;
		}


		$('#iftp-period-panel').on('click', '.iftp-period-opt', function () {
			const href = $(this).attr('href') || '';
			const match = href.match(/[?&]period=([^&]*)/);
			try {
				localStorage.setItem(
					'iftp_cf7_period',
					match ? decodeURIComponent(match[1]) : 'all'
				);
			} catch (e) {}
		});


		$(document).on('click', '.iftp-stat-card[data-status]', function () {
			try {
				sessionStorage.setItem(
					'iftp_cf7_status',
					String($(this).data('status') || '')
				);
			} catch (e) {}
		});


		$(document).on('change', '#iftp-form-filter', function () {
			const fid = String($(this).val() || '0');
			try {
				if (fid !== '0') {
					sessionStorage.setItem('iftp_cf7_form_id', fid);
				} else {
					sessionStorage.removeItem('iftp_cf7_form_id');
				}
			} catch (e) {}
		});

	})();


	(function () {
		document.querySelectorAll('.iftp-read-more').forEach(function (btn) {
			btn.addEventListener('click', function (e) {
				e.preventDefault();
				const cell = btn.closest('.iftp-kcell') || btn.closest('td');
				const full = cell ? cell.querySelector('.iftp-val-full') : null;
				const short = cell
					? cell.querySelector('.iftp-val-short')
					: null;
				if (!full) {
					return;
				}
				const open = full.classList.contains('iftp-val-open');
				if (open) {
					full.classList.remove('iftp-val-open');
					if (short) {
						short.style.display = '';
					}
					btn.textContent =
						btn.getAttribute('data-more') || btn.textContent;
				} else {
					full.classList.add('iftp-val-open');
					if (short) {
						short.style.display = 'none';
					}
					btn.textContent =
						btn.getAttribute('data-less') || btn.textContent;
				}
			});
		});
	})();



	function iftpConfirmOpen($trigger) {
		var msg         = $trigger.data('iftp-confirm');
		var title       = $trigger.data('iftp-confirm-title') || 'Confirm';
		var href        = $trigger.attr('href');
		var destructive = String($trigger.data('iftp-confirm-destructive')) === '1';

		$('#iftp-confirm-heading').text(title);
		$('#iftp-confirm-message').text(msg);
		$('#iftp-confirm-yes').attr('href', href);

		var $yes = $('#iftp-confirm-yes');
		if (destructive) {
			$yes.addClass('iftp-confirm-yes-btn--danger').removeClass('button-primary');
		} else {
			$yes.removeClass('iftp-confirm-yes-btn--danger').addClass('button-primary');
		}

		var _sw = window.innerWidth - document.documentElement.clientWidth;
		$('body')
			.css('padding-right', _sw > 0 ? _sw + 'px' : '')
			.addClass('iftp-modal-open');

		var $modal = $('#iftp-confirm-modal');
		$modal.show();
		void $modal[0].offsetWidth;
		$modal.addClass('iftp-modal--open');
	}

	function iftpConfirmClose() {
		var $modal = $('#iftp-confirm-modal');
		$modal.removeClass('iftp-modal--open');
		setTimeout(function () {
			$modal.hide();
			$('body').css('padding-right', '').removeClass('iftp-modal-open');
		}, 210);
	}

	$(document).on('click', '.iftp-confirm-link', function (e) {
		e.preventDefault();
		iftpConfirmOpen($(this));
	});

	$(document).on(
		'click',
		'#iftp-confirm-modal .iftp-modal-overlay, .iftp-confirm-close, .iftp-confirm-cancel',
		function () {
			iftpConfirmClose();
		}
	);

	$(document).on('click', '#iftp-confirm-modal .iftp-modal-box', function (e) {
		e.stopPropagation();
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' && $('#iftp-confirm-modal').hasClass('iftp-modal--open')) {
			iftpConfirmClose();
		}
	});
})(jQuery);
