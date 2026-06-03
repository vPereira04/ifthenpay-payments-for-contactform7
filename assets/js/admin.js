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
		var gateway = String($('#iftp-gateway-key').val() || '');
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



	$(document).on('change', '#cb-select-all, #cb-select-all-2', function () {
		$('input[name="entry_ids[]"]').prop('checked', $(this).is(':checked'));
	});

	$(document).on('change', 'input[name="entry_ids[]"]', function () {
		var total    = $('input[name="entry_ids[]"]').length;
		var selected = $('input[name="entry_ids[]"]:checked').length;
		$('#cb-select-all, #cb-select-all-2').prop('checked', total > 0 && total === selected);
	});

	$(document).on('click', '#iftp-bulk-form input[type="submit"]', function () {
		var $form   = $(this).closest('form');
		var action  = $form.find('select[name="action"]').val()
			|| $form.find('select[name="action2"]').val()
			|| '';
		if (action === 'delete') {
			var count = $('input[name="entry_ids[]"]:checked').length;
			if (count > 0) {
				return window.confirm(
					'Delete ' + count + ' entr' + (count === 1 ? 'y' : 'ies') + '? This cannot be undone.'
				);
			}
		}
		return true;
	});



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
	});

})(jQuery);
