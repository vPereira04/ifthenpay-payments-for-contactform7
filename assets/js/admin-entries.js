/**
 * Entries list page: checkbox persistence, select-all, page-jump navigation,
 * and column visibility (reads data-hidden-cols from the table wrapper).
 *
 * Loaded in the footer — DOM is fully available when this runs.
 */
(function () {
	'use strict';

	/* ── Column visibility ─────────────────────────────────────────────────── */
	/* Apply display:none to hidden columns declared by PHP via data attribute. */
	var tableWrap = document.querySelector('.iftp-entries-table-wrap');
	if (tableWrap) {
		var hiddenCols = [];
		try {
			hiddenCols = JSON.parse(tableWrap.getAttribute('data-hidden-cols') || '[]');
		} catch (e) {}
		hiddenCols.forEach(function (col) {
			tableWrap.querySelectorAll('[data-col="' + col + '"]').forEach(function (el) {
				el.style.display = 'none';
			});
		});
	}

	/* ── Checkbox cross-page persistence ───────────────────────────────────── */
	var STORAGE_KEY = 'iftp_cf7_selected_ids';

	function getStoredIds() {
		try {
			return JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');
		} catch (e) {
			return [];
		}
	}

	function setStoredIds(ids) {
		try {
			sessionStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
		} catch (e) {}
	}

	function clearStoredIds() {
		try {
			sessionStorage.removeItem(STORAGE_KEY);
		} catch (e) {}
	}

	function getAllCheckboxes() {
		return document.querySelectorAll('input[name="entry_ids[]"]');
	}

	/* Sync both select-all headers to reflect the current checked state. */
	function updateSelectAllState() {
		var cbs = Array.from(getAllCheckboxes());
		var total = cbs.length;
		var checked = cbs.filter(function (cb) { return cb.checked; }).length;
		['cb-select-all', 'cb-select-all-2'].forEach(function (id) {
			var el = document.getElementById(id);
			if (!el) return;
			el.checked = total > 0 && checked === total;
			el.indeterminate = checked > 0 && checked < total;
		});
	}

	/* Write current page's checkbox state into storage, preserving other pages' IDs. */
	function syncStorageFromPage() {
		var storedSet = new Set(getStoredIds().map(String));
		getAllCheckboxes().forEach(function (cb) {
			storedSet.delete(cb.value);
			if (cb.checked) storedSet.add(cb.value);
		});
		setStoredIds(Array.from(storedSet));
	}

	function uncheckAll() {
		getAllCheckboxes().forEach(function (cb) { cb.checked = false; });
		['cb-select-all', 'cb-select-all-2'].forEach(function (id) {
			var el = document.getElementById(id);
			if (el) { el.checked = false; el.indeterminate = false; }
		});
	}

	/* Restore checkboxes whose IDs are in storage — always clears first. */
	function restoreSelectionsFromStorage() {
		uncheckAll();
		var storedSet = new Set(getStoredIds().map(String));
		if (!storedSet.size) return;
		getAllCheckboxes().forEach(function (cb) {
			if (storedSet.has(cb.value)) cb.checked = true;
		});
		updateSelectAllState();
	}

	function applyPageState() {
		var p = new URLSearchParams(window.location.search);
		if (p.get('bulk_done') === '1') {
			clearStoredIds();
			p.delete('bulk_done');
			history.replaceState(null, '', window.location.pathname + '?' + p.toString());
			uncheckAll();
		} else {
			restoreSelectionsFromStorage();
		}
	}

	/* Run now (footer scripts fire after body), on DOMContentLoaded if somehow early, and on bfcache. */
	applyPageState();
	document.addEventListener('DOMContentLoaded', applyPageState);
	window.addEventListener('pageshow', function (e) {
		if (e.persisted) applyPageState();
	});

	/* Track individual checkbox changes. */
	getAllCheckboxes().forEach(function (cb) {
		cb.addEventListener('change', function () {
			syncStorageFromPage();
			updateSelectAllState();
		});
	});

	/* Select-all checkboxes. */
	['cb-select-all', 'cb-select-all-2'].forEach(function (id) {
		var el = document.getElementById(id);
		if (!el) return;
		el.addEventListener('change', function () {
			getAllCheckboxes().forEach(function (cb) { cb.checked = el.checked; });
			syncStorageFromPage();
			updateSelectAllState();
		});
	});

	/* ── Page-jump inputs: navigate on Enter ───────────────────────────────── */
	document.querySelectorAll('.iftp-pag-input').forEach(function (input) {
		input.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter') return;
			e.preventDefault();
			var p = parseInt(this.value, 10);
			var total = parseInt(this.getAttribute('data-total'), 10) || 1;
			if (isNaN(p) || p < 1) return;
			if (p > total) p = total;
			var baseUrl = this.getAttribute('data-base-url') || window.location.href;
			var url = new URL(baseUrl, window.location.href);
			if (p > 1) {
				url.searchParams.set('paged', p);
			} else {
				url.searchParams.delete('paged');
			}
			window.location.href = url.toString();
		});
	});

}());
