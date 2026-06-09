(function ($) {
	'use strict';



	function iftpShowForGateway(val) {

		$('.iftp-gateway-methods').each(function () {
			$(this).toggle($(this).data('gateway') === val);
		});

		iftpRebuildDefaultMethod(val);
	}

	/**
	 * Rebuild #iftp-default-method options based on which methods are currently
	 * checked inside the panel for the given gateway.
	 */
	function iftpRebuildDefaultMethod(gateway) {
		var $sel = $('#iftp-default-method');
		if (!$sel.length) return;


		var saved = $sel.data('saved') || $sel.val() || '';

		$sel.empty();

		$('.iftp-gateway-methods[data-gateway="' + gateway + '"]')
			.find('.iftp-method-item')
			.each(function () {
				var $item   = $(this);
				var entity  = String($item.data('entity') || '');
				var checked = $item.find('.ifthenpay-method-checkbox').is(':checked');
				if (!entity || !checked) return;
				$sel.append(
					$('<option>').val(entity).text(entity)
				);
			});


		if (saved && $sel.find('option[value="' + saved + '"]').length) {
			$sel.val(saved);
		}
	}


	$(document).on('change', '#iftp-gateway-key', function () {
		iftpShowForGateway(String($(this).val() || ''));
	});


	$(document).on('change', '.ifthenpay-method-checkbox', function () {
		var gateway   = String($('#iftp-gateway-key').val() || '');
		var entity_uc = String($(this).closest('.iftp-method-item').data('entity') || '').toUpperCase();
		if (entity_uc) {
			var saved = (window.iftpCf7Admin || {}).saved_methods || {};
			if (!saved[entity_uc]) { saved[entity_uc] = {}; }
			saved[entity_uc].enabled = $(this).is(':checked');
			if (window.iftpCf7Admin) { window.iftpCf7Admin.saved_methods = saved; }
		}
		iftpRebuildDefaultMethod(gateway);
	});



	$(document).on('click', '.ifthenpay-activate', function (e) {
		e.preventDefault();
		var $link   = $(this);
		var entity  = String($link.data('entity')  || '');
		var gateway = String($link.data('gateway') || '');
		if (!entity || !gateway) return;

		$link.text('Sending…');

		$.post(
			(window.iftpCf7Admin || {}).ajax_url || '',
			{
				action:      'iftp_cf7_activate_payment_method',
				nonce:       (window.iftpCf7Admin || {}).nonce || '',
				method:      entity,
				gateway_key: gateway,
			},
			null,
			'json'
		)
			.done(function (response) {
				var msg = (response && response.data && response.data.message)
					? String(response.data.message)
					: (response && response.success ? 'Sent.' : 'Failed.');
				$link.replaceWith($('<em>').text(msg));
			})
			.fail(function () {
				$link.text('Failed. Retry?');
			});
	});



	$(document).on('click', '#iftp-copy-callback, #iftp-copy-antiphish', function () {
		var targetId = $(this).attr('id') === 'iftp-copy-callback'
			? '#iftp-callback-url'
			: '#iftp-anti-phishing';
		var $input = $(targetId);
		if (!$input.length) return;

		var text = String($input.val() || '');
		var $btn = $(this);

		if (navigator.clipboard) {
			navigator.clipboard.writeText(text).then(function () {
				$btn.text('Copied!');
				setTimeout(function () { $btn.text('Copy'); }, 2000);
			});
		} else {
			$input.select();
			try { document.execCommand('copy'); } catch (_e) {}
			$btn.text('Copied!');
			setTimeout(function () { $btn.text('Copy'); }, 2000);
		}
	});



	$(document).on('change', '#iftp-cf7-enabled', function () {
		$('.iftp-cf7-row').toggleClass('iftp-cf7-row--hidden', !$(this).is(':checked'));
	});

	$(document).on('change', 'input[name="iftp_cf7_amount_source"]', function () {
		var isFixed = $(this).val() === 'fixed';
		$('input[name="iftp_cf7_amount_fixed"]').prop('disabled', !isFixed);
		$('input[name="iftp_cf7_amount_field"]').prop('disabled', isFixed);
	});




	var IFTP_SEL_KEY = 'iftp_cf7_selected_ids';

	function iftpSelGet() {
		try { return JSON.parse(sessionStorage.getItem(IFTP_SEL_KEY) || '[]'); }
		catch(e) { return []; }
	}
	function iftpSelSet(ids) { sessionStorage.setItem(IFTP_SEL_KEY, JSON.stringify(ids)); }
	function iftpSelAdd(id) {
		var ids = iftpSelGet();
		if (ids.indexOf(id) === -1) { ids.push(id); }
		iftpSelSet(ids);
	}
	function iftpSelRemove(id) {
		iftpSelSet(iftpSelGet().filter(function(i) { return i !== id; }));
	}
	function iftpSelClear() { sessionStorage.removeItem(IFTP_SEL_KEY); }

	function iftpSelUpdateUI() {
		var ids   = iftpSelGet();
		var count = ids.length;
		var $dn   = $('.displaying-num').first();

		if (count > 0) {
			var label = 'Selected ' + count + ' item' + (count === 1 ? '' : 's');
			if (!$dn.data('orig')) { $dn.data('orig', $dn.html()); }
			$dn.html('<strong>' + label + '</strong>');
		} else {
			var orig = $dn.data('orig');
			if (orig) { $dn.html(orig); }
		}

		var $boxes   = $('input[name="entry_ids[]"]');
		var total    = $boxes.length;
		var checked  = 0;
		$boxes.each(function() {
			var inSel = ids.indexOf($(this).val()) !== -1;
			$(this).prop('checked', inSel);
			if (inSel) { checked++; }
		});
		var $masters = $('#cb-select-all, #cb-select-all-2');
		$masters.prop('checked',       total > 0 && checked === total);
		$masters.prop('indeterminate', checked > 0 && checked < total);
	}

	$(document).on('change', '#cb-select-all, #cb-select-all-2', function() {
		var isChecked = $(this).is(':checked');
		$('input[name="entry_ids[]"]').each(function() {
			if (isChecked) { iftpSelAdd($(this).val()); }
			else           { iftpSelRemove($(this).val()); }
		});
		iftpSelUpdateUI();
	});

	$(document).on('change', 'input[name="entry_ids[]"]', function() {
		if ($(this).is(':checked')) { iftpSelAdd($(this).val()); }
		else                        { iftpSelRemove($(this).val()); }
		iftpSelUpdateUI();
	});

$(document).on('click', '#iftp-bulk-form input[type="submit"]', function() {
		var $form  = $(this).closest('form');
		var action = $form.find('select[name="action"]').val()
			|| $form.find('select[name="action2"]').val() || '';
		if (action !== 'delete') { return true; }

		var ids = iftpSelGet();
		var n   = ids.length || $('input[name="entry_ids[]"]:checked').length;
		if (!n) { return true; }

		if (!window.confirm('Delete ' + n + ' entr' + (n === 1 ? 'y' : 'ies') + '? This cannot be undone.')) {
			return false;
		}
		if (ids.length) {
			$form.find('input[name="entry_ids[]"]').prop('checked', false);
			$form.find('.iftp-sel-inject').remove();
			ids.forEach(function(id) {
				$form.append('<input type="hidden" class="iftp-sel-inject" name="entry_ids[]" value="' + Number(id) + '">');
			});
			iftpSelClear();
		}
		return true;
	});




	$(document).on('click', '#iftp-refresh-btn', function () {
		window.location.reload();
	});





	function iftpModalOpen() {
		$('#iftp-add-payment-form')[0].reset();
		$('.iftp-modal-error').hide().text('');
		$('.iftp-modal-submit').prop('disabled', false).text(
			(window.iftpCf7Admin || {}).add_payment_label || 'Add Payment'
		);
		$('#iftp-add-payment-modal').fadeIn(150);
		$('#ap_amount').trigger('focus');
	}

	function iftpModalClose() {
		$('#iftp-add-payment-modal').fadeOut(150);
	}

	$(document).on('click', '#iftp-add-payment-btn', function (e) {
		e.preventDefault();
		iftpModalOpen();
	});

	$(document).on('click', '.iftp-modal-overlay, .iftp-modal-close, .iftp-modal-cancel', function () {
		iftpModalClose();
	});

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
		var $form   = $(this);
		var $btn    = $form.find('.iftp-modal-submit');
		var $error  = $('.iftp-modal-error');
		var amount  = parseFloat($form.find('[name="ap_amount"]').val() || '0');

		if (!amount || amount <= 0) {
			$error.text('Please enter a valid amount.').show();
			return;
		}

		$btn.prop('disabled', true).text('Saving…');
		$error.hide().text('');

		$.post(
			(window.iftpCf7Admin || {}).ajax_url || '',
			{
				action:          'iftp_cf7_add_payment',
				nonce:           (window.iftpCf7Admin || {}).add_payment_nonce || '',
				customer_name:   $form.find('[name="ap_customer_name"]').val(),
				customer_email:  $form.find('[name="ap_customer_email"]').val(),
				customer_ip:     $form.find('[name="ap_customer_ip"]').val(),
				amount:          $form.find('[name="ap_amount"]').val(),
				payment_method:  $form.find('[name="ap_payment_method"]').val(),
				payment_status:  $form.find('[name="ap_payment_status"]').val(),
				form_title:      $form.find('[name="ap_form_title"]').val(),
			},
			null,
			'json'
		)
			.done(function (response) {
				if (response && response.success) {
					iftpModalClose();
					window.location.reload();
				} else {
					var msg = (response && response.data && response.data.message)
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
		var canvas = document.getElementById('iftp-cf7-dash-chart');
		if (!canvas || typeof Chart === 'undefined') return;

		var raw;
		try { raw = JSON.parse(canvas.dataset.chart || '{}'); }
		catch (_e) { raw = {}; }
		if (!raw.labels || !raw.labels.length) return;

		new Chart(canvas, {
			type: 'line',
			data: {
				labels: raw.labels,
				datasets: [{
					data:                 raw.amounts,
					fill:                 true,
					tension:              0.4,
					borderColor:          '#00609c',
					backgroundColor:      'rgba(0,96,156,.08)',
					pointRadius:          2,
					pointHoverRadius:     4,
					pointBackgroundColor: '#00609c',
					borderWidth:          1.5,
				}],
			},
			options: {
				responsive:          true,
				maintainAspectRatio: false,
				plugins: {
					legend:  { display: false },
					tooltip: {
						callbacks: {
							label: function(ctx) { return ' € ' + Number(ctx.raw).toFixed(2); },
						},
					},
				},
				scales: {
					x: {
						grid:  { display: false },
						border: { display: false },
						ticks: { color: '#8c8f94', font: { size: 10 }, maxTicksLimit: 7 },
					},
					y: {
						display:     false,
						beginAtZero: true,
					},
				},
			},
		});
	}




	function iftpInitChart() {
		var canvas = document.getElementById('iftp-cf7-chart');
		if (!canvas || typeof Chart === 'undefined') return;

		var raw;
		try {
			raw = JSON.parse(canvas.dataset.chart || '{}');
		} catch (_e) {
			raw = {};
		}
		if (!raw.labels || !raw.labels.length) return;

		var currentMode = 'count';
		var chartInst   = null;

		function getConfig(mode) {
			var isRev   = mode === 'revenue';
			var data    = isRev ? (raw.amounts || []) : (raw.counts || []);
			var color   = isRev ? '#00a32a' : '#00609c';
			var bgColor = isRev ? 'rgba(0,163,42,.07)' : 'rgba(0,96,156,.07)';
			var ptR     = raw.labels.length > 15 ? 2 : 3;

			return {
				color:   color,
				bgColor: bgColor,
				ptR:     ptR,
				data:    data,
				yTick:   isRev
					? function(v) { return '€ ' + v.toFixed(0); }
					: function(v) { return v; },
				tooltip: isRev
					? function(ctx) { return ' € ' + Number(ctx.raw).toFixed(2); }
					: function(ctx) { return ' ' + ctx.raw + ' payment' + (ctx.raw === 1 ? '' : 's'); },
			};
		}

		function buildChart(mode) {
			var cfg = getConfig(mode);

			if (chartInst) {
				var ds = chartInst.data.datasets[0];
				ds.data               = cfg.data;
				ds.borderColor        = cfg.color;
				ds.backgroundColor    = cfg.bgColor;
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
					datasets: [{
						label: '',
						data:  cfg.data,
						fill:  true,
						tension: 0.4,
						borderColor:          cfg.color,
						backgroundColor:      cfg.bgColor,
						pointBackgroundColor: cfg.color,
						pointRadius:      cfg.ptR,
						pointHoverRadius: 5,
					}],
				},
				options: {
					responsive:          true,
					maintainAspectRatio: false,
					interaction: { mode: 'index', intersect: false },
					plugins: {
						legend:  { display: false },
						tooltip: {
							callbacks: { label: cfg.tooltip },
						},
					},
					scales: {
						x: {
							grid:  { color: 'rgba(0,0,0,.05)' },
							ticks: { color: '#646970', font: { size: 11 }, maxTicksLimit: 12 },
						},
						y: {
							beginAtZero: true,
							grid:  { color: 'rgba(0,0,0,.05)' },
							ticks: {
								color: '#646970',
								font:  { size: 11 },
								callback: cfg.yTick,
							},
						},
					},
				},
			});
		}

		buildChart(currentMode);

		document.querySelectorAll('.iftp-chart-mode').forEach(function(btn) {
			btn.addEventListener('click', function() {
				currentMode = this.dataset.mode;
				document.querySelectorAll('.iftp-chart-mode').forEach(function(b) {
					b.classList.toggle('active', b.dataset.mode === currentMode);
				});
				buildChart(currentMode);
			});
		});
	}



	$(function () {

		var $gatewaySel = $('#iftp-gateway-key');
		if ($gatewaySel.length) {
			iftpShowForGateway(String($gatewaySel.val() || ''));
		}


		var $enabledCb = $('#iftp-cf7-enabled');
		if ($enabledCb.length) {
			$('.iftp-cf7-row').toggleClass('iftp-cf7-row--hidden', !$enabledCb.is(':checked'));
			var src = $('input[name="iftp_cf7_amount_source"]:checked').val() || 'fixed';
			$('input[name="iftp_cf7_amount_fixed"]').prop('disabled', src !== 'fixed');
			$('input[name="iftp_cf7_amount_field"]').prop('disabled', src !== 'field');
		}

		if ($('#iftp-bulk-form').length) {
			iftpSelUpdateUI();
		}

		iftpInitChart();
		iftpInitDashChart();
	});

})(jQuery);
