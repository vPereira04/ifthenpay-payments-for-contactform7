/**
 * Restores localStorage/sessionStorage filter state into the URL before the page body renders.
 * Loaded in <head> (no defer/footer) so the redirect, if needed, happens before the user sees content.
 */
(function () {
	'use strict';
	try {
		var url = new URL(window.location.href);
		var changed = false;
		if (!url.searchParams.has('per_page')) {
			var pp = localStorage.getItem('iftp_cf7_per_page');
			if (pp && pp !== '20') {
				url.searchParams.set('per_page', pp);
				url.searchParams.set('paged', '1');
				url.searchParams.delete('cursor');
				url.searchParams.delete('dir');
				changed = true;
			}
		} else {
			localStorage.setItem('iftp_cf7_per_page', url.searchParams.get('per_page'));
		}
		if (!url.searchParams.has('period')) {
			var period = localStorage.getItem('iftp_cf7_period');
			if (period && period !== 'all') {
				url.searchParams.set('period', period);
				changed = true;
			}
		} else {
			localStorage.setItem('iftp_cf7_period', url.searchParams.get('period'));
		}
		if (!url.searchParams.has('status')) {
			var status = sessionStorage.getItem('iftp_cf7_status');
			if (status && status !== '') {
				url.searchParams.set('status', status);
				changed = true;
			}
		} else {
			sessionStorage.setItem('iftp_cf7_status', url.searchParams.get('status'));
		}
		if (!url.searchParams.has('form_id')) {
			var formId = sessionStorage.getItem('iftp_cf7_form_id');
			if (formId && formId !== '0') {
				url.searchParams.set('form_id', formId);
				changed = true;
			}
		} else {
			var fid = url.searchParams.get('form_id');
			if (fid && fid !== '0') {
				sessionStorage.setItem('iftp_cf7_form_id', fid);
			} else {
				sessionStorage.removeItem('iftp_cf7_form_id');
			}
		}
		if (changed) {
			window.location.replace(url.toString());
		}
	} catch (e) {}
}());
