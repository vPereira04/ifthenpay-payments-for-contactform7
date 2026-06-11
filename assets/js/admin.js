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
				let node;
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
					node = document.createElement('span');
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
		const $dn = $('.displaying-num').first();

		if (count > 0) {
			const label =
				'Selected ' + count + ' item' + (count === 1 ? '' : 's');
			if (!$dn.data('orig')) {
				$dn.data('orig', $dn.html());
			}
			$dn.html('<strong>' + label + '</strong>');
		} else {
			const orig = $dn.data('orig');
			if (orig) {
				$dn.html(orig);
			}
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



	function iftpModalOpen() {
		$('#iftp-add-payment-form')[0].reset();

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
		$('#iftp-add-payment-modal').fadeIn(150);
		$('#ap_amount').trigger('focus');
	}

	$(document).on(
		'click',
		'#iftp-add-payment-modal .iftp-mode-btn',
		function () {
			const mode = String($(this).data('mode') || 'simple');
			const $modal = $('#iftp-add-payment-modal');
			$modal.find('.iftp-mode-btn').removeClass('iftp-mode-btn--active');
			$(this).addClass('iftp-mode-btn--active');
			$modal.find('.iftp-mode-panel').hide();
			$modal.find('.iftp-mode-panel--' + mode).show();

			const focusId = mode === 'complex' ? '#ap_cx_amount' : '#ap_amount';
			$modal.find(focusId).trigger('focus');
		}
	);

	function iftpModalClose() {
		$('#iftp-add-payment-modal').fadeOut(150);
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
		if (e.key === 'Escape' && $('#iftp-add-payment-modal').is(':visible')) {
			iftpModalClose();
		}
	});

	$(document).on('click', '.iftp-modal-box', function (e) {
		e.stopPropagation();
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

		const postData = {
			action: 'iftp_cf7_add_payment',
			nonce: (window.iftpCf7Admin || {}).add_payment_nonce || '',
			customer_name: $form
				.find(
					isComplex
						? '[name="ap_cx_customer_name"]'
						: '[name="ap_customer_name"]'
				)
				.val(),
			customer_email: $form
				.find(
					isComplex
						? '[name="ap_cx_customer_email"]'
						: '[name="ap_customer_email"]'
				)
				.val(),
			customer_ip: isComplex
				? $form.find('[name="ap_cx_customer_ip"]').val()
				: '',
			amount: amountVal,
			payment_method: $form
				.find(
					isComplex
						? '[name="ap_cx_payment_method"]'
						: '[name="ap_payment_method"]'
				)
				.val(),
			payment_status: $form
				.find(
					isComplex
						? '[name="ap_cx_payment_status"]'
						: '[name="ap_payment_status"]'
				)
				.val(),
			form_title: $form
				.find(
					isComplex
						? '[name="ap_cx_form_title"]'
						: '[name="ap_form_title"]'
				)
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


	function iftpInitDashChart() {
		const canvas = document.getElementById('iftp-cf7-dash-chart');
		if (!canvas || typeof Chart === 'undefined') {
			return;
		}

		let allData;
		try {
			allData = JSON.parse(canvas.dataset.chart || '{}');
		} catch (_e) {
			allData = {};
		}

		let currentPeriod = String(canvas.dataset.period || '7');
		let chartInst = null;

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

			const chart = d.chart || {};
			if (!chart.labels || !chart.labels.length) {
				return;
			}

			if (chartInst) {
				chartInst.data.labels = chart.labels;
				chartInst.data.datasets[0].data = chart.counts;
				chartInst.update();
				return;
			}

			chartInst = new Chart(canvas, {
				type: 'line',
				data: {
					labels: chart.labels,
					datasets: [
						{
							data: chart.counts,
							fill: true,
							tension: 0.4,
							borderColor: '#00609c',
							backgroundColor: 'rgba(0,96,156,.08)',
							pointRadius: 2,
							pointHoverRadius: 4,
							pointBackgroundColor: '#00609c',
							borderWidth: 1.5,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: {
								label(ctx) {
									const n = Number(ctx.raw);
									return (
										' ' +
										n +
										' payment' +
										(n === 1 ? '' : 's')
									);
								},
							},
						},
					},
					scales: {
						x: {
							grid: { display: false },
							border: { display: false },
							ticks: {
								color: '#8c8f94',
								font: { size: 10 },
								maxTicksLimit: 10,
							},
						},
						y: {
							display: false,
							beginAtZero: true,
						},
					},
				},
			});
		}

		applyPeriod(currentPeriod);

		const widgetBody = canvas.closest('.iftp-metabox-body');
		if (widgetBody) {
			widgetBody
				.querySelectorAll('.iftp-dash-period-tabs .iftp-period-tab')
				.forEach(function (btn) {
					btn.addEventListener('click', function () {
						currentPeriod = String(this.dataset.period || '7');
						widgetBody
							.querySelectorAll(
								'.iftp-dash-period-tabs .iftp-period-tab'
							)
							.forEach(function (b) {
								b.classList.toggle(
									'active',
									b.dataset.period === currentPeriod
								);
							});
						applyPeriod(currentPeriod);
					});
				});
		}
	}


	function iftpInitChart() {
		const canvas = document.getElementById('iftp-cf7-chart');
		if (!canvas || typeof Chart === 'undefined') {
			return;
		}

		let raw;
		try {
			raw = JSON.parse(canvas.dataset.chart || '{}');
		} catch (_e) {
			raw = {};
		}
		if (!raw.labels || !raw.labels.length) {
			return;
		}

		let currentMode = 'count';
		let chartInst = null;

		function getConfig(mode) {
			const isRev = mode === 'revenue';
			const data = isRev ? raw.amounts || [] : raw.counts || [];
			const color = isRev ? '#00a32a' : '#00609c';
			const bgColor = isRev ? 'rgba(0,163,42,.07)' : 'rgba(0,96,156,.07)';
			const ptR = raw.labels.length > 15 ? 2 : 3;

			return {
				color,
				bgColor,
				ptR,
				data,
				yTick: isRev
					? function (v) {
							return '€ ' + v.toFixed(0);
						}
					: function (v) {
							return v;
						},
				tooltip: isRev
					? function (ctx) {
							return ' € ' + Number(ctx.raw).toFixed(2);
						}
					: function (ctx) {
							return (
								' ' +
								ctx.raw +
								' payment' +
								(ctx.raw === 1 ? '' : 's')
							);
						},
			};
		}

		function buildChart(mode) {
			const cfg = getConfig(mode);

			if (chartInst) {
				const ds = chartInst.data.datasets[0];
				ds.data = cfg.data;
				ds.borderColor = cfg.color;
				ds.backgroundColor = cfg.bgColor;
				ds.pointBackgroundColor = cfg.color;
				chartInst.options.scales.y.ticks.callback = cfg.yTick;
				chartInst.options.plugins.tooltip.callbacks.label = cfg.tooltip;
				chartInst.update();
				return;
			}

			chartInst = new Chart(canvas, {
				type: 'line',
				data: {
					labels: raw.labels,
					datasets: [
						{
							label: '',
							data: cfg.data,
							fill: true,
							tension: 0.4,
							borderColor: cfg.color,
							backgroundColor: cfg.bgColor,
							pointBackgroundColor: cfg.color,
							pointRadius: cfg.ptR,
							pointHoverRadius: 5,
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					interaction: { mode: 'index', intersect: false },
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: { label: cfg.tooltip },
						},
					},
					scales: {
						x: {
							grid: { color: 'rgba(0,0,0,.05)' },
							ticks: {
								color: '#646970',
								font: { size: 11 },
								maxTicksLimit: 12,
							},
						},
						y: {
							beginAtZero: true,
							grid: { color: 'rgba(0,0,0,.05)' },
							ticks: {
								color: '#646970',
								font: { size: 11 },
								callback: cfg.yTick,
							},
						},
					},
				},
			});
		}

		buildChart(currentMode);

		document.querySelectorAll('.iftp-chart-mode').forEach(function (btn) {
			btn.addEventListener('click', function () {
				currentMode = this.dataset.mode;
				document
					.querySelectorAll('.iftp-chart-mode')
					.forEach(function (b) {
						b.classList.toggle(
							'active',
							b.dataset.mode === currentMode
						);
					});
				buildChart(currentMode);
			});
		});
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

		function savePrefs(partial) {
			$.post((window.iftpCf7Admin || {}).ajax_url || '', {
				action: 'iftp_cf7_save_entries_prefs',
				nonce: (window.iftpCf7Admin || {}).prefs_nonce || '',
				prefs: JSON.stringify(partial),
			});
		}


		$(document).on('click', '.iftp-density-btn', function () {
			const density = String($(this).data('density') || 'normal');
			$('.iftp-density-btn')
				.removeClass('iftp-density-btn--active')
				.attr('aria-pressed', 'false');
			$(this)
				.addClass('iftp-density-btn--active')
				.attr('aria-pressed', 'true');
			$wrap
				.removeClass(
					'iftp-density-compact iftp-density-normal iftp-density-large'
				)
				.addClass('iftp-density-' + density)
				.attr('data-density', density);
			savePrefs({ row_density: density });
		});


		function openPopover() {
			$popover.removeAttr('hidden');
			$btn.attr('aria-expanded', 'true');
			const off = $btn.offset();
			const h = $btn.outerHeight() || 28;
			$popover.css({
				top: off.top + h + 4 + 'px',
				left: off.left + 'px',
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


		let dragSrc = null;

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


		$('#iftp-col-customize-save').on('click', function () {
			const newOrder = [];
			$list.find('.iftp-col-item').each(function () {
				newOrder.push(String($(this).data('col')));
			});
			reorderTableCols(newOrder);
			$wrap.attr('data-col-order', JSON.stringify(newOrder));
			savePrefs({ column_positions: newOrder });
			closePopover();
		});


		$('#iftp-col-customize-reset').on('click', function () {
			const defaults =
				(window.iftpCf7Admin || {}).default_col_order || [];
			if (!defaults.length) {
				return;
			}
			$.each(defaults, function (i, colKey) {
				const $item = $list.find(
					'.iftp-col-item[data-col="' + colKey + '"]'
				);
				if ($item.length) {
					$list.append($item);
				}
			});
			reorderTableCols(defaults);
			$wrap.attr('data-col-order', JSON.stringify(defaults));
			savePrefs({ column_positions: defaults });
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

		iftpInitChart();
		iftpInitDashChart();
		iftpInitEntriesPrefs();
	});
})(jQuery);
