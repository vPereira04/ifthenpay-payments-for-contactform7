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
		var newKey = String($(this).val() || '');
		iftpShowForGateway(newKey);
		iftpRefreshGatewayMethods(newKey);
	});



	function iftpEscAttr(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	function iftpBuildInactiveMethodHtml(entity_uc, label, logo, logo_dark, gateway_key) {
		var activateLabel = (window.iftpCf7Admin || {}).activate_method_label || 'Activate Method';
		var html  = '<div class="iftp-method-item iftp-method-item--inactive" data-entity="' + iftpEscAttr(entity_uc) + '">';
		html += '<div class="iftp-method-disabled-layer">';
		if (logo) {
			html += '<img src="' + iftpEscAttr(logo) + '" alt="' + iftpEscAttr(label) + '" class="iftp-method-logo"';
			if (logo_dark) { html += ' data-logo-dark="' + iftpEscAttr(logo_dark) + '"'; }
			html += ' />';
		}
		html += '<strong>' + iftpEscAttr(label) + '</strong>';
		html += '</div>';
		html += '<div class="iftp-method-activate-overlay">';
		html += '<button type="button" class="button button-small ifthenpay-activate"';
		html += ' data-entity="' + iftpEscAttr(entity_uc) + '"';
		html += ' data-gateway="' + iftpEscAttr(gateway_key) + '">';
		html += iftpEscAttr(activateLabel);
		html += '</button>';
		html += '</div>';
		html += '</div>';
		return html;
	}

	function iftpBuildMethodsHtml(gateway_key, api_methods, method_cat) {
		var catalogMap   = {};
		var savedMethods = (window.iftpCf7Admin || {}).saved_methods || {};

		method_cat.forEach(function (mc) {
			var ent = String(mc.entity || '').toUpperCase();
			if (ent) { catalogMap[ent] = mc; }
		});

		var gatewayEntities = Object.keys(api_methods).map(function (e) { return e.toUpperCase(); });
		var html = '';

		Object.keys(api_methods).forEach(function (entity) {
			var entity_uc = entity.toUpperCase();
			var m_data    = api_methods[entity] || {};
			var m_account = String(m_data.account || '');
			var m_label   = String(m_data.method  || entity_uc);
			var cat       = catalogMap[entity_uc] || {};
			var logo      = String(cat.logo      || '');
			var logo_dark = String(cat.logo_dark || '');

			if (m_account !== '') {
				var savedEntry  = savedMethods[entity_uc] || {};
				var wasEnabled  = savedEntry.enabled ? ' checked' : '';
				html += '<div class="iftp-method-item" data-entity="' + iftpEscAttr(entity_uc) + '">';
				html += '<label class="iftp-method-label">';
				html += '<input type="checkbox" class="ifthenpay-method-checkbox"';
				html += ' name="methods[' + iftpEscAttr(entity_uc) + '][enabled]" value="1"' + wasEnabled + ' />';
				if (logo) {
					html += '<img src="' + iftpEscAttr(logo) + '" alt="' + iftpEscAttr(m_label) + '" class="iftp-method-logo"';
					if (logo_dark) { html += ' data-logo-dark="' + iftpEscAttr(logo_dark) + '"'; }
					html += ' />';
				}
				html += '<strong>' + iftpEscAttr(m_label) + '</strong>';
				html += '</label>';
				html += '<div class="iftp-method-right"><code class="iftp-account">' + iftpEscAttr(m_account) + '</code></div>';
				html += '</div>';
			} else {
				html += iftpBuildInactiveMethodHtml(entity_uc, m_label, logo, logo_dark, gateway_key);
			}
		});

		method_cat.forEach(function (mc) {
			var mc_entity = String(mc.entity || '').toUpperCase();
			if (!mc_entity || gatewayEntities.indexOf(mc_entity) !== -1) { return; }
			var mc_label     = String(mc.label     || mc_entity);
			var mc_logo      = String(mc.logo      || '');
			var mc_logo_dark = String(mc.logo_dark || '');
			html += iftpBuildInactiveMethodHtml(mc_entity, mc_label, mc_logo, mc_logo_dark, gateway_key);
		});

		return html;
	}

	function iftpRefreshGatewayMethods(gateway_key) {
		if (!gateway_key) { return; }
		var $panel = $('.iftp-gateway-methods[data-gateway="' + gateway_key + '"]');
		if (!$panel.length) { return; }

		$panel.css('opacity', '0.5');

		$.post(
			(window.iftpCf7Admin || {}).ajax_url || '',
			{
				action:      'iftp_cf7_refresh_gateway_data',
				nonce:       (window.iftpCf7Admin || {}).nonce || '',
				gateway_key: gateway_key,
			},
			null,
			'json'
		)
			.done(function (response) {
				$panel.css('opacity', '');
				if (!response || !response.success) { return; }
				var data        = response.data || {};
				var api_methods = data.api_methods    || {};
				var method_cat  = data.method_catalog || [];
				$panel.html(iftpBuildMethodsHtml(gateway_key, api_methods, method_cat));
				iftpRebuildDefaultMethod(gateway_key);
			})
			.fail(function () {
				$panel.css('opacity', '');
			});
	}


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
	});

})(jQuery);
