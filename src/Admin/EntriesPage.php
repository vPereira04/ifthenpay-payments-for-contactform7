<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Admin;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Repository\EntryRepository;
use Ifthenpay\CF7\Repository\DTO\EntryDto;

final class EntriesPage
{

	private const PER_PAGE = 20;

	/**
	 * Hooked to load-{page} so headers are not yet sent — safe to redirect.
	 */
	public function process_actions(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$repo = new EntryRepository();

		$iftp_action = isset($_GET['iftp_action']) ? sanitize_key(wp_unslash((string) $_GET['iftp_action'])) : ''; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		if ($iftp_action === 'delete' && isset($_GET['entry_id'], $_GET['_wpnonce'])) { // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$act_id    = absint($_GET['entry_id']); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$act_nonce = sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			if (wp_verify_nonce($act_nonce, 'iftp_cf7_entry_action_' . $act_id)) {
				$repo->delete($act_id);
				wp_safe_redirect(admin_url('admin.php?page=ifthenpay-cf7-entries'));
				exit;
			}
		}

		$this->handle_bulk_actions($repo);
	}



	public function render_page(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$repo = new EntryRepository();

		$view_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : ''; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['entry_id']) && wp_verify_nonce($view_nonce, 'iftp_cf7_view_entry')) { // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$entry_id = absint($_GET['entry_id']); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
			$entry    = $repo->get_by_id($entry_id);
			if ($entry !== null) {
				$this->render_single_entry($entry);
				return;
			}
		}


		$user_id = get_current_user_id();
		$prefs   = $user_id ? UserPreferences::get($user_id) : UserPreferences::defaults();

		$current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_field = isset($_GET['search_field']) ? sanitize_key(wp_unslash((string) $_GET['search_field'])) : 'customer_name'; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_op    = isset($_GET['search_op']) ? sanitize_key(wp_unslash((string) $_GET['search_op'])) : 'contains'; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_query = isset($_GET['search_query']) ? sanitize_text_field(wp_unslash((string) $_GET['search_query'])) : ''; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$search_op    = in_array($search_op, array('contains', 'is'), true) ? $search_op : 'contains';


		$status_in_url  = isset($_GET['status']); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$period_in_url  = isset($_GET['period']); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$orderby_in_url = isset($_GET['orderby']); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$order_in_url   = isset($_GET['order']); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended

		$status_raw  = $status_in_url  ? sanitize_key(wp_unslash((string) $_GET['status']))  : $prefs['status']; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$period_raw  = $period_in_url  ? sanitize_key(wp_unslash((string) $_GET['period']))  : $prefs['time_range']; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$orderby_raw = $orderby_in_url ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : $prefs['orderby']; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$order_raw   = $order_in_url   ? sanitize_key(wp_unslash((string) $_GET['order']))   : $prefs['order']; // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended

		$sort_cols = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$status    = in_array($status_raw,  array('', 'pending', 'completed', 'failed', 'cancelled'), true) ? $status_raw  : '';
		$period    = in_array($period_raw,  array('all', 'year', 'month', 'week', 'day'), true)              ? $period_raw  : 'all';
		$orderby   = in_array($orderby_raw, $sort_cols, true)                                                   ? $orderby_raw : 'id';
		$order     = in_array($order_raw,   array('asc', 'desc'), true)                                       ? $order_raw   : 'desc';


		if ($user_id && ($status_in_url || $period_in_url || $orderby_in_url || $order_in_url)) {
			$to_save = array();
			if ($status_in_url)  $to_save['status']     = $status;
			if ($period_in_url)  $to_save['time_range'] = $period;
			if ($orderby_in_url) $to_save['orderby']    = $orderby;
			if ($order_in_url)   $to_save['order']      = $order;
			UserPreferences::merge($user_id, $to_save);
		}

		$db_status = in_array($status, array('pending', 'completed', 'failed', 'cancelled'), true) ? $status : '';

		$total        = $repo->count_all($db_status, $search_field, $search_op, $search_query, $period);
		$total_amount = $repo->sum_amount($db_status, $search_field, $search_op, $search_query, $period);
		$entries      = $repo->get_all($current_page, self::PER_PAGE, $db_status, $search_field, $search_op, $search_query, $period, $orderby, $order);
		$total_pages  = max(1, (int) ceil($total / self::PER_PAGE));

		$this->render_list($repo, $entries, $current_page, $total_pages, $total, $total_amount, $status, $search_field, $search_op, $search_query, $period, $orderby, $order, $prefs);
	}



	private function handle_bulk_actions(EntryRepository $repo): void
	{
		$action = '';
		if (isset($_POST['action']) && sanitize_key(wp_unslash((string) $_POST['action'])) !== '-1') {
			$action = sanitize_key(wp_unslash((string) $_POST['action']));
		} elseif (isset($_POST['action2']) && sanitize_key(wp_unslash((string) $_POST['action2'])) !== '-1') {
			$action = sanitize_key(wp_unslash((string) $_POST['action2']));
		}

		if ($action === '') {
			return;
		}

		$nonce = isset($_POST['_wpnonce_bulk']) ? sanitize_text_field(wp_unslash((string) $_POST['_wpnonce_bulk'])) : '';
		if (! wp_verify_nonce($nonce, 'iftp_cf7_bulk_entries')) {
			return;
		}

		$ids = isset($_POST['entry_ids']) && is_array($_POST['entry_ids'])
			? array_filter(array_map('absint', (array) $_POST['entry_ids']))
			: array();

		if (empty($ids)) {
			return;
		}

		match ($action) {
			'delete'         => $repo->bulk_delete($ids),
			'mark_paid'      => $repo->bulk_update_status($ids, 'completed'),
			'mark_cancelled' => $repo->bulk_update_status($ids, 'cancelled'),
			'mark_failed'    => $repo->bulk_update_status($ids, 'failed'),
			'mark_pending'   => $repo->bulk_update_status($ids, 'pending'),
			'export_csv'     => $this->export_csv($ids, $repo),
			'export_excel'   => $this->export_excel($ids, $repo),
			default          => null,
		};

		if ($action !== 'export_csv' && $action !== 'export_excel') {
			$args = array('page' => 'ifthenpay-cf7-entries', 'bulk_done' => '1');
			foreach (array('status', 'period', 'paged', 'search_field', 'search_op', 'search_query') as $key) {
				if (! empty($_GET[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$args[$key] = sanitize_text_field(wp_unslash((string) $_GET[$key])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			}
			wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
			exit;
		}
	}



	public function ajax_add_payment(): void
	{
		check_ajax_referer('iftp_cf7_add_payment', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Unauthorized.', 'ifthenpay-payments-for-contactform7')), 403);
		}

		$name       = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash((string) $_POST['customer_name'])) : '';
		$email      = isset($_POST['customer_email']) ? sanitize_email(wp_unslash((string) $_POST['customer_email'])) : '';
		$ip         = isset($_POST['customer_ip']) ? sanitize_text_field(wp_unslash((string) $_POST['customer_ip'])) : '';
		$amount_raw = isset($_POST['amount']) ? sanitize_text_field(wp_unslash((string) $_POST['amount'])) : '0';
		$method     = isset($_POST['payment_method']) ? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['payment_method']))) : '';
		$status     = isset($_POST['payment_status']) ? sanitize_key(wp_unslash((string) $_POST['payment_status'])) : 'completed';
		$form_title = isset($_POST['form_title']) ? sanitize_text_field(wp_unslash((string) $_POST['form_title'])) : '';

		$form_data_raw = isset($_POST['form_data']) ? wp_unslash((string) $_POST['form_data']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON string; sanitizing the raw value before json_decode() would corrupt it. Each key and value is sanitized individually via sanitize_text_field/sanitize_textarea_field in the foreach block below before any data is stored.
		$form_data     = '';
		if ($form_data_raw !== '') {
			$decoded = json_decode($form_data_raw, true);
			if (is_array($decoded)) {
				$sanitized = array();
				foreach ($decoded as $k => $v) {
					$sanitized[sanitize_text_field((string) $k)] = sanitize_textarea_field((string) $v);
				}
				$form_data = (string) wp_json_encode($sanitized);
			}
		}

		$amount = is_numeric($amount_raw) ? (float) $amount_raw : 0.0;
		if ($amount <= 0) {
			wp_send_json_error(array('message' => __('Amount must be greater than zero.', 'ifthenpay-payments-for-contactform7')));
		}

		if (! in_array($status, array('pending', 'completed', 'failed', 'cancelled'), true)) {
			$status = 'completed';
		}

		$repo = new EntryRepository();
		$id   = $repo->create(
			EntryDto::from(
				array(
					'form_title'     => $form_title,
					'customer_name'  => $name,
					'customer_email' => $email,
					'customer_ip'    => $ip,
					'amount'         => $amount,
					'payment_method' => $method,
					'payment_status' => $status,
					'form_data'      => $form_data,
				)
			)
		);

		if ($id <= 0) {
			wp_send_json_error(array('message' => __('Failed to save entry.', 'ifthenpay-payments-for-contactform7')));
		}

		wp_send_json_success(array('id' => $id));
	}



	public function ajax_save_preferences(): void
	{
		check_ajax_referer('iftp_cf7_entries_prefs', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Unauthorized.'), 403);
		}
		$user_id = get_current_user_id();
		if (! $user_id) {
			wp_send_json_error(array('message' => 'Not logged in.'), 403);
		}

		$raw  = isset($_POST['prefs']) ? wp_unslash((string) $_POST['prefs']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- JSON string; sanitizing before json_decode() would corrupt it. Each decoded key is validated against an explicit allowlist and its value type-checked in the switch block below before any data is stored. Nonce verified above via check_ajax_referer.
		$data = json_decode($raw, true);
		if (! is_array($data)) {
			wp_send_json_error(array('message' => 'Invalid data.'));
		}

		$allowed_ob   = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$allowed_cols = array('id', 'customer_name', 'request_id', 'form_title', 'payment_method', 'amount', 'payment_status', 'payment_link', 'created_at');
		$clean        = array();

		foreach ($data as $k => $v) {
			switch ($k) {
				case 'time_range':
					if (in_array($v, array('all', 'year', 'month', 'week', 'day'), true)) {
						$clean['time_range'] = $v;
					}
					break;
				case 'status':
					if (in_array($v, array('', 'pending', 'completed', 'failed', 'cancelled'), true)) {
						$clean['status'] = $v;
					}
					break;
				case 'orderby':
					if (in_array($v, $allowed_ob, true)) {
						$clean['orderby'] = $v;
					}
					break;
				case 'order':
					if (in_array($v, array('asc', 'desc'), true)) {
						$clean['order'] = $v;
					}
					break;
				case 'column_positions':
					if (is_array($v)) {
						$clean['column_positions'] = array_values(
							array_filter($v, fn($c) => in_array($c, $allowed_cols, true))
						);
					}
					break;
				case 'row_density':
					if (in_array($v, array('compact', 'normal', 'large'), true)) {
						$clean['row_density'] = $v;
					}
					break;
			}
		}

		UserPreferences::merge($user_id, $clean);
		wp_send_json_success();
	}



	/** @param int[] $ids */
	private function export_csv(array $ids, EntryRepository $repo): void
	{
		$entries  = $repo->get_by_ids($ids);
		$filename = 'ifthenpay-entries-' . gmdate('Y-m-d') . '.csv';
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Pragma: no-cache');
		$out = fopen('php://output', 'w');
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Usado php://output para download direto de CSV.
		fwrite($out, "\xEF\xBB\xBF");
		fputcsv($out, array('ID', 'Form', 'Customer Name', 'Email', 'IP', 'Amount', 'Method', 'Status', 'Request ID', 'Created At', 'Updated At'));
		foreach ($entries as $entry) {
			fputcsv(
				$out,
				array(
					$entry->id,
					$entry->form_title ?: 'Form #' . $entry->form_id,
					$entry->customer_name,
					$entry->customer_email,
					$entry->customer_ip,
					$entry->amount_formatted(),
					$entry->payment_method,
					$entry->status_label(),
					$entry->request_id ?? '',
					$entry->created_at,
					$entry->updated_at,
				)
			);
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Fecho de stream de output direto.
		fclose($out);
		exit;
	}



	/** @param int[] $ids */
	private function export_excel(array $ids, EntryRepository $repo): void
	{
		$entries  = $repo->get_by_ids($ids);
		$filename = 'ifthenpay-entries-' . gmdate('Y-m-d') . '.xlsx';

		$headers = array('ID', 'Form', 'Customer Name', 'Email', 'IP', 'Amount', 'Method', 'Status', 'Request ID', 'Created At', 'Updated At');

		$rows = array();
		foreach ($entries as $entry) {
			$rows[] = array(
				$entry->id,
				$entry->form_title ?: 'Form #' . $entry->form_id,
				$entry->customer_name,
				$entry->customer_email,
				$entry->customer_ip,
				$entry->amount_formatted(),
				$entry->payment_method,
				$entry->status_label(),
				$entry->request_id ?? '',
				$entry->created_at,
				$entry->updated_at,
			);
		}

		$xlsx = $this->build_xlsx($headers, $rows);

		header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Cache-Control: max-age=0');
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary XLSX stream, not HTML.
		echo $xlsx;
		exit;
	}

	/**
	 * @param string[]   $headers
	 * @param mixed[][]  $rows
	 */
	private function build_xlsx(array $headers, array $rows): string
	{
		$col_letter = static function (int $n): string {
			$letter = '';
			while ($n > 0) {
				$letter = chr(65 + (($n - 1) % 26)) . $letter;
				$n      = (int) floor(($n - 1) / 26);
			}
			return $letter;
		};

		$xml_val = static function ($v): array {
			$s = (string) $v;
			if (is_numeric($s) && ! str_starts_with($s, '0')) {
				return array('t' => 'n', 'v' => $s);
			}
			$escaped = htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
			return array('t' => 'inlineStr', 'v' => $escaped);
		};

		$sheet_rows = '';
		$r          = 1;


		$sheet_rows .= '<row r="' . $r . '">';
		foreach ($headers as $ci => $h) {
			$col         = $col_letter($ci + 1);
			$ref         = $col . $r;
			$cell_val    = htmlspecialchars($h, ENT_XML1 | ENT_QUOTES, 'UTF-8');
			$sheet_rows .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . $cell_val . '</t></is></c>';
		}
		$sheet_rows .= '</row>';
		++$r;

		foreach ($rows as $row) {
			$sheet_rows .= '<row r="' . $r . '">';
			foreach ($row as $ci => $cell) {
				$col      = $col_letter($ci + 1);
				$ref      = $col . $r;
				$info     = $xml_val($cell);
				if ($info['t'] === 'n') {
					$sheet_rows .= '<c r="' . $ref . '"><v>' . $info['v'] . '</v></c>';
				} else {
					$sheet_rows .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $info['v'] . '</t></is></c>';
				}
			}
			$sheet_rows .= '</row>';
			++$r;
		}

		$sheet_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetData>' . $sheet_rows . '</sheetData>'
			. '</worksheet>';

		$styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="2">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>'
			. '</cellXfs>'
			. '</styleSheet>';

		$workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
			. 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="Entries" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>';

		$wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>';

		$pkg_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>';

		$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '</Types>';

		$tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
		$zip = new \ZipArchive();
		$zip->open($tmp, \ZipArchive::OVERWRITE);
		$zip->addFromString('[Content_Types].xml', $content_types);
		$zip->addFromString('_rels/.rels', $pkg_rels);
		$zip->addFromString('xl/workbook.xml', $workbook_xml);
		$zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);
		$zip->addFromString('xl/styles.xml', $styles_xml);
		$zip->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
		$zip->close();

		$data = (string) file_get_contents($tmp);
		wp_delete_file($tmp);
		return $data;
	}

	/** @return array{label: string, tier: string} */
	private function get_milestone_badge(float $total_paid): array
	{
		$milestones = array(
			10_000_000 => array('label' => '', 'tier' => 'second'),
			1_000_000  => array('label' => '',     'tier' => 'first-badge'),
		);
		foreach ($milestones as $threshold => $data) {
			if ($total_paid >= $threshold) {
				return $data;
			}
		}
		return array();
	}

	/**
	 * @param EntryDto[] $entries
	 */
	private function render_list(
		EntryRepository $repo,
		array $entries,
		int $current_page,
		int $total_pages,
		int $total,
		float $total_amount,
		string $current_tab,
		string $search_field = 'customer_name',
		string $search_op = 'contains',
		string $search_query = '',
		string $period = 'all',
		string $orderby = 'id',
		string $order = 'desc',
		array $prefs = array()
	): void {
		$counts        = array(
			''          => $repo->count_period('', $period, true),
			'pending'   => $repo->count_period('pending', $period, true),
			'completed' => $repo->count_period('completed', $period, true),
			'failed'    => $repo->count_period('failed', $period, true),
			'cancelled' => $repo->count_period('cancelled', $period, true),
		);

		$validated_tab   = in_array($current_tab, array('pending', 'completed', 'failed', 'cancelled'), true) ? $current_tab : '';
		$revenue_status  = $validated_tab !== '' ? $validated_tab : 'completed';
		$sidebar_revenue = $repo->sum_amount_period($revenue_status, $period);
		$sidebar_count   = $repo->count_period($revenue_status, $period);

		$chart_raw           = $repo->get_chart_data($validated_tab, $period);
		$chart_series        = $this->build_chart_series($chart_raw, $period);
		$chart_json          = (string) wp_json_encode($chart_series);

		$all_time_paid   = $repo->sum_amount_period('completed', 'all');
		$milestone_badge = $this->get_milestone_badge($all_time_paid);
		$show_revenue_toggle = $current_tab === 'completed';
		$method_catalog_raw  = get_option('iftp_cf7_method_catalog', array());
		$method_logos        = array();
		$method_logos_alt    = array();
		$method_logos_label  = array();
		foreach (is_array($method_catalog_raw) ? $method_catalog_raw : array() as $m) {
			if (! empty($m['entity']) && ! empty($m['logo'])) {
				$entity_upper                    = strtoupper((string) $m['entity']);
				$logo                            = (string) $m['logo'];
				$method_logos[$entity_upper]   = $logo;
				$ent_key                         = preg_replace('/[^A-Z0-9]/', '', $entity_upper);
				$method_logos_alt[$ent_key]    = $logo;
				if (! empty($m['label'])) {
					$lbl_key = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $m['label']));
					if ($lbl_key !== '' && ! isset($method_logos_label[$lbl_key])) {
						$method_logos_label[$lbl_key] = $logo;
					}
				}
			}
		}
		$get_logo = static function (string $pm) use ($method_logos, $method_logos_alt, $method_logos_label): string {
			if ($pm === '') {
				return '';
			}
			$upper = strtoupper($pm);
			if (isset($method_logos[$upper])) {
				return $method_logos[$upper];
			}
			$alt = preg_replace('/[^A-Z0-9]/', '', $upper);
			if (isset($method_logos_alt[$alt])) {
				return $method_logos_alt[$alt];
			}
			if (isset($method_logos_label[$alt])) {
				return $method_logos_label[$alt];
			}

			foreach ($method_logos_alt as $ent_key => $logo) {
				if ($alt !== '' && (str_contains($ent_key, $alt) || str_contains($alt, $ent_key))) {
					return $logo;
				}
			}
			return '';
		};

		$sort = array(
			'orderby'      => $orderby,
			'order'        => $order,
			'status'       => $current_tab,
			'period'       => $period,
			'search_field' => $search_field,
			'search_op'    => $search_op,
			'search_query' => $search_query,
		);

		$col_defs     = $this->get_col_defs();
		$all_col_keys = array_keys($col_defs);
		$saved_order  = ! empty($prefs['column_positions']) && is_array($prefs['column_positions'])
			? $prefs['column_positions']
			: $all_col_keys;

		$ordered_cols = array_values(array_filter($saved_order, fn($k) => isset($col_defs[$k])));
		$missing_cols = array_diff($all_col_keys, $ordered_cols);
		$ordered_cols = array_merge($ordered_cols, array_values($missing_cols));

		$row_density = in_array($prefs['row_density'] ?? '', array('compact', 'normal', 'large'), true)
			? $prefs['row_density']
			: 'normal';

		$col_labels_json = (string) wp_json_encode(
			array_map(fn(array $d) => $d['label'], $col_defs)
		);
?>
		<div class="wrap iftp-cf7-entries-wrap">
			<div class="iftp-page-header">
				<div class="iftp-header-left">
					<h1 class="wp-heading-inline">
						<?php esc_html_e('ifthenpay Entries', 'ifthenpay-payments-for-contactform7'); ?>
					</h1>
					<?php if (! empty($milestone_badge)) : ?>
						<span class="iftp-badge-group">
							<span class="iftp-badge">
								<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true">
									<circle cx="12" cy="5" r="2.2" />
									<rect x="10.2" y="9" width="3.6" height="11" rx="1.5" />
								</svg>
								ifthenpay
							</span>
							<span class="iftp-milestone-badge-wrap iftp-milestone-badge-wrap--<?php echo esc_attr($milestone_badge['tier']); ?>">
								<span class="iftp-milestone-badge iftp-milestone-badge--<?php echo esc_attr($milestone_badge['tier']); ?>">
									<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true">
										<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
									</svg>
									<?php echo esc_html($milestone_badge['label']); ?>
								</span>
							</span>
						</span>
					<?php else : ?>
						<span class="iftp-badge">
							<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true">
								<circle cx="12" cy="5" r="2.2" />
								<rect x="10.2" y="9" width="3.6" height="11" rx="1.5" />
							</svg>
							ifthenpay
						</span>
					<?php endif; ?>
				</div>
			</div>
			<hr class="wp-header-end" />

			<?php

			$rev_labels = array(
				'completed' => __('Paid Revenue', 'ifthenpay-payments-for-contactform7'),
				'pending'   => __('Pending Total', 'ifthenpay-payments-for-contactform7'),
				'failed'    => __('Failed Total', 'ifthenpay-payments-for-contactform7'),
				'cancelled' => __('Cancelled Total', 'ifthenpay-payments-for-contactform7'),
			);
			$rev_label   = $rev_labels[$revenue_status] ?? $rev_labels['completed'];
			$period_labels = array(
				'all'   => __('All time', 'ifthenpay-payments-for-contactform7'),
				'year'  => __('This year', 'ifthenpay-payments-for-contactform7'),
				'month' => __('This month', 'ifthenpay-payments-for-contactform7'),
				'week'  => __('Last 7 days', 'ifthenpay-payments-for-contactform7'),
				'day'   => __('Today', 'ifthenpay-payments-for-contactform7'),
			);
			$period_options = array(
				'all'   => _x('All', 'period filter', 'ifthenpay-payments-for-contactform7'),
				'year'  => _x('Year', 'period filter', 'ifthenpay-payments-for-contactform7'),
				'month' => _x('Month', 'period filter', 'ifthenpay-payments-for-contactform7'),
				'week'  => _x('Week', 'period filter', 'ifthenpay-payments-for-contactform7'),
				'day'   => _x('Day', 'period filter', 'ifthenpay-payments-for-contactform7'),
			);
			?>
			<?php /* Stats row */ ?>
			<div class="iftp-stats-row">
				<div class="iftp-stat-card iftp-stat-card--revenue">
					<div class="iftp-stat-rev-head">
						<div class="iftp-stat-card-label"><?php echo esc_html($rev_label); ?></div>
						<div class="iftp-period-tabs" role="group" aria-label="<?php esc_attr_e('Time period', 'ifthenpay-payments-for-contactform7'); ?>">
							<?php foreach ($period_options as $pkey => $plabel) :
								$purl = add_query_arg(
									array_filter(
										array(
											'page'         => 'ifthenpay-cf7-entries',
											'period'       => $pkey,
											'status'       => $current_tab !== '' ? $current_tab : null,
											'search_field' => $search_query !== '' ? $search_field : null,
											'search_op'    => $search_query !== '' ? $search_op : null,
											'search_query' => $search_query !== '' ? $search_query : null,
										)
									),
									admin_url('admin.php')
								);
							?>
								<a href="<?php echo esc_url($purl); ?>" class="iftp-period-tab<?php echo $period === $pkey ? ' active' : ''; ?>"><?php echo esc_html($plabel); ?></a>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="iftp-stat-card-amount">€<?php echo esc_html(number_format($sidebar_revenue, 2, '.', ',')); ?></div>
					<div class="iftp-stat-card-sub">
						<?php
						$status_word = array(
							'completed' => _x('paid', 'payment count label', 'ifthenpay-payments-for-contactform7'),
							'pending'   => _x('pending', 'payment count label', 'ifthenpay-payments-for-contactform7'),
							'failed'    => _x('failed', 'payment count label', 'ifthenpay-payments-for-contactform7'),
							'cancelled' => _x('cancelled', 'payment count label', 'ifthenpay-payments-for-contactform7'),
						);
						$count_word = $status_word[$revenue_status] ?? _x('payments', 'payment count label', 'ifthenpay-payments-for-contactform7');
						echo esc_html(
							number_format_i18n($sidebar_count) . ' ' . $count_word
								. ' · '
								. ($period_labels[$period] ?? $period_labels['all'])
						);
						?>
					</div>
					<div class="iftp-rev-bar">
						<?php if (($counts['completed'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['completed']; ?>;background:#00a32a"></div><?php endif; ?>
						<?php if (($counts['pending'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['pending']; ?>;background:#dba617"></div><?php endif; ?>
						<?php if (($counts['failed'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['failed']; ?>;background:#d63638"></div><?php endif; ?>
						<?php if (($counts['cancelled'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['cancelled']; ?>;background:#8c8f94"></div><?php endif; ?>
					</div>
				</div>
				<div class="iftp-stat-card">
					<div class="iftp-stat-card-label iftp-stat-label--paid"><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></div>
					<div class="iftp-stat-card-val iftp-stat-val--paid"><?php echo esc_html((string) ($counts['completed'] ?? 0)); ?></div>
				</div>
				<div class="iftp-stat-card">
					<div class="iftp-stat-card-label iftp-stat-label--pending"><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></div>
					<div class="iftp-stat-card-val iftp-stat-val--pending"><?php echo esc_html((string) ($counts['pending'] ?? 0)); ?></div>
				</div>
				<div class="iftp-stat-card">
					<div class="iftp-stat-card-label iftp-stat-label--failed"><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></div>
					<div class="iftp-stat-card-val iftp-stat-val--failed"><?php echo esc_html((string) ($counts['failed'] ?? 0)); ?></div>
				</div>
				<div class="iftp-stat-card">
					<div class="iftp-stat-card-label iftp-stat-label--cancelled"><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></div>
					<div class="iftp-stat-card-val iftp-stat-val--cancelled"><?php echo esc_html((string) ($counts['cancelled'] ?? 0)); ?></div>
				</div>
			</div>

			<?php /* ── Chart ── */ ?>
			<div class="iftp-chart-card">
				<div class="iftp-chart-header">
					<span class="iftp-chart-title">
						<?php esc_html_e('Payments over time', 'ifthenpay-payments-for-contactform7'); ?>
					</span>
					<div class="iftp-chart-modes">
						<button type="button" class="iftp-chart-mode active" data-mode="count">
							<?php esc_html_e('Payments', 'ifthenpay-payments-for-contactform7'); ?>
						</button>
						<?php if ($show_revenue_toggle) : ?>
							<button type="button" class="iftp-chart-mode" data-mode="revenue">
								<?php esc_html_e('Revenue', 'ifthenpay-payments-for-contactform7'); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
				<div class="iftp-chart-canvas-wrap">
					<canvas id="iftp-cf7-chart" data-chart="<?php echo esc_attr($chart_json); ?>"></canvas>
				</div>
			</div>

			<?php /* ── Main table ── */ ?>
			<div class="iftp-cf7-entries-main">

				<?php /* Filter tabs — inside panel, centred on table width */ ?>
				<ul class="subsubsub">
					<?php
					$tabs = array(
						''          => __('All', 'ifthenpay-payments-for-contactform7'),
						'pending'   => __('Pending', 'ifthenpay-payments-for-contactform7'),
						'completed' => __('Paid', 'ifthenpay-payments-for-contactform7'),
						'failed'    => __('Failed', 'ifthenpay-payments-for-contactform7'),
						'cancelled' => __('Cancelled', 'ifthenpay-payments-for-contactform7'),
					);
					foreach ($tabs as $key => $label) {
						$url = add_query_arg(
							array(
								'page'   => 'ifthenpay-cf7-entries',
								'status' => $key,
								'period' => $period,
							),
							admin_url('admin.php')
						);
						$cls = $current_tab === $key ? 'current' : '';
						$cnt = (int) ($counts[$key] ?? 0);
						printf(
							'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a></li>',
							esc_url($url),
							esc_attr($cls),
							esc_html($label),
							(int) $cnt
						);
					}
					?>
				</ul>

				<?php $this->render_search_bar($current_tab, $search_field, $search_op, $search_query, $period); ?>

				<?php if (empty($entries)) : ?>
					<div class="iftp-cf7-empty-state">
						<div class="iftp-empty-icon">
							<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<rect x="3" y="4" width="18" height="16" rx="2" />
								<line x1="8" y1="9" x2="16" y2="9" />
								<line x1="8" y1="13" x2="13" y2="13" />
							</svg>
						</div>
						<h3><?php esc_html_e('No entries found', 'ifthenpay-payments-for-contactform7'); ?></h3>
						<p><?php esc_html_e('No payment entries match your current filter. Try a different search or status.', 'ifthenpay-payments-for-contactform7'); ?></p>
					</div>
				<?php else : ?>

					<form method="post" id="iftp-bulk-form" autocomplete="off">
						<?php wp_nonce_field('iftp_cf7_bulk_entries', '_wpnonce_bulk'); ?>
						<input type="hidden" name="page" value="ifthenpay-cf7-entries" />
						<?php if ($current_tab !== '') : ?>
							<input type="hidden" name="status" value="<?php echo esc_attr($current_tab); ?>" />
						<?php endif; ?>

						<?php $this->render_tablenav_top($current_page, $total_pages, $total, $total_amount, $row_density); ?>

						<div class="iftp-entries-table-wrap iftp-density-<?php echo esc_attr($row_density); ?>"
							data-density="<?php echo esc_attr($row_density); ?>"
							data-col-labels="<?php echo esc_attr($col_labels_json); ?>"
							data-col-order="<?php echo esc_attr((string) wp_json_encode($ordered_cols)); ?>">
							<table class="wp-list-table widefat fixed striped iftp-cf7-entries-table">
								<thead>
									<tr>
										<td class="manage-column column-cb check-column">
											<input id="cb-select-all" type="checkbox" autocomplete="off" />
										</td>
										<?php foreach ($ordered_cols as $col_key) : ?>
											<?php $this->render_col_th($col_key, $col_defs[$col_key], $sort); ?>
										<?php endforeach; ?>
									</tr>
								</thead>
								<tbody>
									<?php
									$method_colors = array(
										'mbway'         => '#00a550',
										'multibanco'    => '#2271b1',
										'mb'            => '#2271b1',
										'card'          => '#dba617',
										'creditcard'    => '#dba617',
										'ccard'         => '#dba617',
										'payshop'       => '#e84c3d',
										'cofidis'       => '#003d8f',
										'cofidisinst'   => '#003d8f',
										'ifthenpaylink' => '#f90',
										'dinheiro'      => '#0f6b2f',
									);


									$cur_view_url = '';
									$cur_del_url  = '';

									$render_cell = array(
										'id' => function (EntryDto $e) use (&$cur_view_url, &$cur_del_url): void {
									?>
										<td class="column-id" data-col="id">
											<a href="<?php echo esc_url($cur_view_url); ?>">#<?php echo esc_html((string) $e->id); ?></a>
											<div class="row-actions">
												<span class="view"><a href="<?php echo esc_url($cur_view_url); ?>"><?php esc_html_e('View', 'ifthenpay-payments-for-contactform7'); ?></a></span>
												| <span class="trash"><a href="<?php echo esc_url($cur_del_url); ?>" class="submitdelete"
														onclick="return confirm('<?php esc_attr_e('Move this entry to trash?', 'ifthenpay-payments-for-contactform7'); ?>');"><?php esc_html_e('Trash', 'ifthenpay-payments-for-contactform7'); ?></a></span>
											</div>
										</td>
									<?php
										},
										'customer_name' => function (EntryDto $e): void {
									?>
										<td class="column-customer" data-col="customer_name">
											<strong><?php echo esc_html($e->customer_name ?: '—'); ?></strong>
											<?php if ($e->customer_email !== '') : ?>
												<br /><a href="mailto:<?php echo esc_attr($e->customer_email); ?>" class="iftp-list-email"><?php echo esc_html($e->customer_email); ?></a>
											<?php endif; ?>
										</td>
									<?php
										},
										'request_id' => function (EntryDto $e): void {
											echo '<td class="column-request" data-col="request_id" style="font-size:12px;">';
											echo $e->request_id ? '<p>' . esc_html($e->request_id) . '</p>' : '—';
											echo '</td>';
										},
										'form_title' => function (EntryDto $e): void {
											echo '<td class="column-form" data-col="form_title">' . esc_html($e->form_title ?: 'Form #' . $e->form_id) . '</td>';
										},
										'payment_method' => function (EntryDto $e) use ($get_logo, $method_colors): void {
											$key       = preg_replace('/[^a-z0-9]/', '', strtolower($e->payment_method));
											$dot_color = $method_colors[$key] ?? '#8c8f94';
											$logo_url  = $get_logo($e->payment_method);
											$is_cash   = $key === 'dinheiro';
											$is_test   = $key === 'teste';
									?>
										<td class="column-method" data-col="payment_method">
											<?php if ($e->payment_method !== '') : ?>
												<span class="iftp-method-pill">
													<?php if ($logo_url !== '') : ?>
														<img class="iftp-method-logo-img" src="<?php echo esc_url($logo_url); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block'" />
														<span class="iftp-method-dot" style="background:<?php echo esc_attr($dot_color); ?>;display:none"></span>
													<?php elseif ($is_cash) : ?>
														<span class="iftp-method-icon-emoji" aria-hidden="true">💶</span>
													<?php elseif ($is_test) : ?>
														<span class="iftp-method-icon-emoji" aria-hidden="true">📝</span>
													<?php else : ?>
														<span class="iftp-method-dot" style="background:<?php echo esc_attr($dot_color); ?>"></span>
													<?php endif; ?>
													<?php echo esc_html($e->payment_method); ?>
												</span>
												<?php else : ?>—<?php endif; ?>
										</td>
									<?php
										},
										'amount' => function (EntryDto $e): void {
											echo '<td class="column-amount" data-col="amount">' . esc_html($e->amount_formatted()) . '</td>';
										},
										'payment_status' => function (EntryDto $e): void {
									?>
										<td class="column-status" data-col="payment_status">
											<span class="iftp-cf7-status-badge iftp-cf7-status-<?php echo esc_attr($e->payment_status); ?>">
												<?php echo esc_html($e->status_label()); ?>
											</span>
										</td>
									<?php
										},
										'payment_link' => function (EntryDto $e): void {
									?>
										<td class="column-payment-link" data-col="payment_link">
											<?php if (! $e->is_paid() && $e->payment_url !== '') : ?>
												<a href="<?php echo esc_url($e->payment_url); ?>" target="_blank" rel="noopener noreferrer" class="iftp-open-link">
													<?php esc_html_e('Open', 'ifthenpay-payments-for-contactform7'); ?>
													<svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
														<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
														<polyline points="15 3 21 3 21 9" />
														<line x1="10" y1="14" x2="21" y2="3" />
													</svg>
												</a>
											<?php else : ?>
												<span class="iftp-dash">—</span>
											<?php endif; ?>
										</td>
									<?php
										},
										'created_at' => function (EntryDto $e): void {
											echo '<td class="column-date" data-col="created_at" style="font-size:12px;">' . esc_html($e->created_at) . '</td>';
										},
									);

									foreach ($entries as $entry) :
										$action_nonce = wp_create_nonce('iftp_cf7_entry_action_' . $entry->id);
										$cur_view_url = add_query_arg(
											array(
												'page'     => 'ifthenpay-cf7-entries',
												'entry_id' => $entry->id,
												'_wpnonce' => wp_create_nonce('iftp_cf7_view_entry'),
											),
											admin_url('admin.php')
										);
										$cur_del_url  = add_query_arg(
											array(
												'page'        => 'ifthenpay-cf7-entries',
												'iftp_action' => 'delete',
												'entry_id'    => $entry->id,
												'_wpnonce'    => $action_nonce,
											),
											admin_url('admin.php')
										);
									?>
										<tr data-href="<?php echo esc_url($cur_view_url); ?>">
											<th class="check-column">
												<input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr((string) $entry->id); ?>" autocomplete="off" />
											</th>
											<?php foreach ($ordered_cols as $col_key) : ?>
												<?php if (isset($render_cell[$col_key])) {
													$render_cell[$col_key]($entry);
												} ?>
											<?php endforeach; ?>
										</tr>
									<?php endforeach; ?>
								</tbody>
								<tfoot>
									<tr>
										<td class="manage-column column-cb check-column"><input id="cb-select-all-2" type="checkbox" autocomplete="off" /></td>
										<?php foreach ($ordered_cols as $col_key) : ?>
											<?php $this->render_col_th($col_key, $col_defs[$col_key], $sort); ?>
										<?php endforeach; ?>
									</tr>
								</tfoot>
							</table>
						</div><!-- .iftp-entries-table-wrap -->

						<?php $this->render_tablenav_bottom($current_page, $total_pages); ?>
					</form>

					<?php /* Column-order customize popover (rendered outside <form> to avoid accidental submit) */ ?>
					<div id="iftp-col-customize-popover" class="iftp-col-customize-popover" hidden
						role="dialog" aria-modal="true" aria-labelledby="iftp-col-customize-title">
						<div class="iftp-col-customize-header">
							<span id="iftp-col-customize-title" class="iftp-col-customize-title">
								<?php esc_html_e('Customize Columns', 'ifthenpay-payments-for-contactform7'); ?>
							</span>
							<button type="button" class="iftp-col-customize-close" aria-label="<?php esc_attr_e('Close', 'ifthenpay-payments-for-contactform7'); ?>">
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<line x1="18" y1="6" x2="6" y2="18" />
									<line x1="6" y1="6" x2="18" y2="18" />
								</svg>
							</button>
						</div>
						<p class="iftp-col-customize-hint"><?php esc_html_e('Drag to reorder columns.', 'ifthenpay-payments-for-contactform7'); ?></p>
						<ul class="iftp-col-list" id="iftp-col-list" role="listbox" aria-label="<?php esc_attr_e('Column order', 'ifthenpay-payments-for-contactform7'); ?>">
							<?php foreach ($ordered_cols as $col_key) : ?>
								<li class="iftp-col-item" data-col="<?php echo esc_attr($col_key); ?>" draggable="true" role="option" tabindex="0">
									<span class="iftp-col-drag-handle" aria-hidden="true">
										<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
											<circle cx="9" cy="5" r="1" fill="currentColor" />
											<circle cx="9" cy="12" r="1" fill="currentColor" />
											<circle cx="9" cy="19" r="1" fill="currentColor" />
											<circle cx="15" cy="5" r="1" fill="currentColor" />
											<circle cx="15" cy="12" r="1" fill="currentColor" />
											<circle cx="15" cy="19" r="1" fill="currentColor" />
										</svg>
									</span>
									<?php echo esc_html($col_defs[$col_key]['label']); ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<div class="iftp-col-customize-footer">
							<button type="button" class="button button-primary" id="iftp-col-customize-save">
								<?php esc_html_e('Save', 'ifthenpay-payments-for-contactform7'); ?>
							</button>
							<button type="button" class="button" id="iftp-col-customize-reset">
								<?php esc_html_e('Reset', 'ifthenpay-payments-for-contactform7'); ?>
							</button>
						</div>
					</div>

				<?php endif; /* empty / not empty */ ?>
			</div><!-- .iftp-cf7-entries-main -->

			<?php /* Info box at bottom */ ?>
			<div class="iftp-info-box">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<circle cx="12" cy="12" r="10" />
					<line x1="12" y1="8" x2="12" y2="12" />
					<line x1="12" y1="16" x2="12.01" y2="16" />
				</svg>
				<div class="iftp-info-box-content">
					<p><strong><?php esc_html_e('How entries work:', 'ifthenpay-payments-for-contactform7'); ?></strong>
						<?php esc_html_e('An entry is created every time a visitor clicks Pay on one of your Contact Form 7 forms. Entries start as Pending until payment is confirmed via callback.', 'ifthenpay-payments-for-contactform7'); ?></p>
					<p><?php esc_html_e('IDs are never reused — deleting entries does not reset the counter.', 'ifthenpay-payments-for-contactform7'); ?></p>
				</div>
			</div>

		</div><!-- .wrap -->

		<!-- Add Payment modal -->
		<div id="iftp-add-payment-modal" class="iftp-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="iftp-modal-title">
			<div class="iftp-modal-overlay"></div>
			<div class="iftp-modal-box">
				<div class="iftp-modal-head">
					<h2 id="iftp-modal-title">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<rect x="1" y="4" width="22" height="16" rx="2" />
							<line x1="1" y1="10" x2="23" y2="10" />
						</svg>
						<?php esc_html_e('Add Payment', 'ifthenpay-payments-for-contactform7'); ?>
					</h2>
					<div class="iftp-modal-mode-toggle" role="group" aria-label="<?php esc_attr_e('Mode', 'ifthenpay-payments-for-contactform7'); ?>">
						<button type="button" class="iftp-mode-btn iftp-mode-btn--active" data-mode="simple"><?php esc_html_e('Simple', 'ifthenpay-payments-for-contactform7'); ?></button>
						<button type="button" class="iftp-mode-btn" data-mode="complex"><?php esc_html_e('Complex', 'ifthenpay-payments-for-contactform7'); ?></button>
					</div>
					<button type="button" class="iftp-modal-close" aria-label="<?php esc_attr_e('Close', 'ifthenpay-payments-for-contactform7'); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<line x1="18" y1="6" x2="6" y2="18" />
							<line x1="6" y1="6" x2="18" y2="18" />
						</svg>
					</button>
				</div>
				<form id="iftp-add-payment-form" novalidate>
					<div class="iftp-modal-body">
						<div class="iftp-modal-error" style="display:none;"></div>

						<!-- Simple mode -->
						<div class="iftp-mode-panel iftp-mode-panel--simple">
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_customer_name"><?php esc_html_e('Customer Name', 'ifthenpay-payments-for-contactform7'); ?></label>
									<input type="text" id="ap_customer_name" name="ap_customer_name" class="regular-text" maxlength="255" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_customer_email"><?php esc_html_e('E-mail', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="email" id="ap_customer_email" name="ap_customer_email" class="regular-text" maxlength="100" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_amount"><?php esc_html_e('Amount (€)', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-required">*</span></label>
									<input type="number" id="ap_amount" name="ap_amount" step="0.01" min="0.01" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_payment_status"><?php esc_html_e('Status', 'ifthenpay-payments-for-contactform7'); ?></label>
									<select id="ap_payment_status" name="ap_payment_status">
										<option value="completed"><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="pending"><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="cancelled"><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="failed"><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></option>
									</select>
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_payment_method"><?php esc_html_e('Payment Method', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_payment_method" name="ap_payment_method" class="regular-text" maxlength="20"
										list="iftp-method-suggestions" placeholder="e.g. MBWAY, MULTIBANCO, CARD" />
									<datalist id="iftp-method-suggestions">
										<option value="MBWAY"></option>
										<option value="MULTIBANCO"></option>
										<option value="CARD"></option>
										<option value="PAYSHOP"></option>
										<option value="COFIDIS"></option>
										<option value="APPLE"></option>
										<option value="GOOGLE"></option>
										<option value="DINHEIRO"></option>
									</datalist>
								</div>
								<div class="iftp-modal-field">
									<label for="ap_form_title"><?php esc_html_e('Form / Reference', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_form_title" name="ap_form_title" class="regular-text" maxlength="255" />
								</div>
							</div>
						</div><!-- .iftp-mode-panel--simple -->

						<!-- Complex mode -->
						<div class="iftp-mode-panel iftp-mode-panel--complex" style="display:none">

							<div class="iftp-modal-section-label"><?php esc_html_e('Customer Data', 'ifthenpay-payments-for-contactform7'); ?></div>

							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_cx_customer_name"><?php esc_html_e('Customer Name', 'ifthenpay-payments-for-contactform7'); ?></label>
									<input type="text" id="ap_cx_customer_name" name="ap_cx_customer_name" class="regular-text" maxlength="255" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_cx_customer_email"><?php esc_html_e('E-mail', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="email" id="ap_cx_customer_email" name="ap_cx_customer_email" class="regular-text" maxlength="100" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_cx_amount"><?php esc_html_e('Amount (€)', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-required">*</span></label>
									<input type="number" id="ap_cx_amount" name="ap_cx_amount" step="0.01" min="0.01" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_cx_payment_status"><?php esc_html_e('Status', 'ifthenpay-payments-for-contactform7'); ?></label>
									<select id="ap_cx_payment_status" name="ap_cx_payment_status">
										<option value="completed"><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="pending"><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="cancelled"><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="failed"><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></option>
									</select>
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_cx_payment_method"><?php esc_html_e('Payment Method', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_cx_payment_method" name="ap_cx_payment_method" class="regular-text" maxlength="20"
										list="iftp-method-suggestions" placeholder="e.g. MBWAY, MULTIBANCO, CARD" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_cx_form_title"><?php esc_html_e('Form / Reference', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_cx_form_title" name="ap_cx_form_title" class="regular-text" maxlength="255" />
								</div>
							</div>

							<div class="iftp-modal-section-label"><?php esc_html_e('Submitted Data', 'ifthenpay-payments-for-contactform7'); ?></div>

							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_sd_name"><?php esc_html_e('Name', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_name" name="ap_sd_name" class="regular-text" maxlength="255" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_sd_email"><?php esc_html_e('Email', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="email" id="ap_sd_email" name="ap_sd_email" class="regular-text" maxlength="100" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_sd_morada"><?php esc_html_e('Morada', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_morada" name="ap_sd_morada" class="regular-text" maxlength="255" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_sd_codigo_postal"><?php esc_html_e('Código Postal', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_codigo_postal" name="ap_sd_codigo_postal" class="regular-text" maxlength="20" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field iftp-modal-field--full">
									<label for="ap_sd_telemovel"><?php esc_html_e('Telemóvel', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_telemovel" name="ap_sd_telemovel" class="regular-text" maxlength="45" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field iftp-modal-field--full">
									<label for="ap_sd_mensagem"><?php esc_html_e('Mensagem', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<textarea id="ap_sd_mensagem" name="ap_sd_mensagem" class="large-text" rows="3" maxlength="1000"></textarea>
								</div>
							</div>

						</div><!-- .iftp-mode-panel--complex -->

					</div><!-- .iftp-modal-body -->
					<div class="iftp-modal-foot">
						<button type="button" class="button iftp-modal-cancel"><?php esc_html_e('Cancel', 'ifthenpay-payments-for-contactform7'); ?></button>
						<button type="submit" class="button button-primary iftp-modal-submit"><?php esc_html_e('Add Payment', 'ifthenpay-payments-for-contactform7'); ?></button>
					</div>
				</form>
			</div>
		</div>

		<script>
			(function() {
				var STORAGE_KEY = 'iftp_cf7_selected';

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

				/* Sync both select-all headers to reflect the current checked state */
				function updateSelectAllState() {
					var cbs = Array.from(getAllCheckboxes());
					var total = cbs.length;
					var checked = cbs.filter(function(cb) {
						return cb.checked;
					}).length;
					['cb-select-all', 'cb-select-all-2'].forEach(function(id) {
						var el = document.getElementById(id);
						if (!el) return;
						el.checked = total > 0 && checked === total;
						el.indeterminate = checked > 0 && checked < total;
					});
				}

				/* Write current page's checkbox state into storage, preserving other pages' IDs */
				function syncStorageFromPage() {
					var storedSet = new Set(getStoredIds().map(String));
					getAllCheckboxes().forEach(function(cb) {
						storedSet.delete(cb.value);
						if (cb.checked) storedSet.add(cb.value);
					});
					setStoredIds(Array.from(storedSet));
				}

				function uncheckAll() {
					getAllCheckboxes().forEach(function(cb) {
						cb.checked = false;
					});
					['cb-select-all', 'cb-select-all-2'].forEach(function(id) {
						var el = document.getElementById(id);
						if (el) {
							el.checked = false;
							el.indeterminate = false;
						}
					});
				}

				/* Restore checkboxes whose IDs are in storage — always clears first */
				function restoreSelectionsFromStorage() {
					uncheckAll();
					var storedSet = new Set(getStoredIds().map(String));
					if (!storedSet.size) return;
					getAllCheckboxes().forEach(function(cb) {
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

				/* Run immediately, after DOMContentLoaded (beats browser form-state restore), and on bfcache */
				applyPageState();
				document.addEventListener('DOMContentLoaded', applyPageState);
				window.addEventListener('pageshow', function(e) {
					if (e.persisted) applyPageState();
				});

				/* Track individual checkbox changes */
				getAllCheckboxes().forEach(function(cb) {
					cb.addEventListener('change', function() {
						syncStorageFromPage();
						updateSelectAllState();
					});
				});

				/* Select-all checkboxes */
				['cb-select-all', 'cb-select-all-2'].forEach(function(id) {
					var el = document.getElementById(id);
					if (!el) return;
					el.addEventListener('change', function() {
						getAllCheckboxes().forEach(function(cb) {
							cb.checked = el.checked;
						});
						syncStorageFromPage();
						updateSelectAllState();
					});
				});

				/* Page-number input: navigate on Enter */
				var pageInput = document.getElementById('iftp-paged-input');
				if (pageInput) {
					pageInput.addEventListener('keydown', function(e) {
						if (e.key !== 'Enter') return;
						e.preventDefault();
						var p = parseInt(this.value, 10);
						var total = parseInt(this.getAttribute('data-total'), 10);
						if (isNaN(p) || p < 1) p = 1;
						if (p > total) p = total;
						var url = new URL(window.location.href);
						url.searchParams.set('paged', p);
						window.location.href = url.toString();
					});
				}

				/* Row-click navigation — click anywhere on a row to open entry */
				var entriesTable = document.querySelector('.iftp-cf7-entries-table tbody');
				if (entriesTable) {
					entriesTable.addEventListener('click', function(e) {
						var row = e.target.closest('tr[data-href]');
						if (!row) return;
						if (e.target.closest('.check-column')) return;
						if (e.target.closest('a, button, input')) return;
						window.location.href = row.getAttribute('data-href');
					});
				}
			})();
		</script>
	<?php
	}



	private function render_search_bar(string $current_tab, string $search_field, string $search_op, string $search_query, string $period = 'all'): void
	{
		$fields    = array(
			'customer_name'  => __('Name', 'ifthenpay-payments-for-contactform7'),
			'customer_email' => __('Email', 'ifthenpay-payments-for-contactform7'),
			'form_title'     => __('Form', 'ifthenpay-payments-for-contactform7'),
			'payment_method' => __('Method', 'ifthenpay-payments-for-contactform7'),
			'amount'         => __('Amount', 'ifthenpay-payments-for-contactform7'),
		);
		$clear_url = add_query_arg(
			array(
				'page'   => 'ifthenpay-cf7-entries',
				'status' => $current_tab,
				'period' => $period,
			),
			admin_url('admin.php')
		);
	?>
		<form method="get" id="iftp-search-form" class="iftp-search-bar">
			<input type="hidden" name="page" value="ifthenpay-cf7-entries" />
			<?php if ($current_tab !== '') : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr($current_tab); ?>" />
			<?php endif; ?>
			<?php if ($period !== 'all') : ?>
				<input type="hidden" name="period" value="<?php echo esc_attr($period); ?>" />
			<?php endif; ?>
			<select name="search_field" aria-label="<?php esc_attr_e('Search field', 'ifthenpay-payments-for-contactform7'); ?>">
				<?php foreach ($fields as $val => $label) : ?>
					<option value="<?php echo esc_attr($val); ?>" <?php selected($search_field, $val); ?>><?php echo esc_html($label); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="search_op" aria-label="<?php esc_attr_e('Operator', 'ifthenpay-payments-for-contactform7'); ?>">
				<option value="contains" <?php selected($search_op, 'contains'); ?>><?php esc_html_e('contains', 'ifthenpay-payments-for-contactform7'); ?></option>
				<option value="is" <?php selected($search_op, 'is'); ?>><?php esc_html_e('is', 'ifthenpay-payments-for-contactform7'); ?></option>
			</select>
			<input type="search" name="search_query" value="<?php echo esc_attr($search_query); ?>"
				class="regular-text" placeholder="<?php esc_attr_e('Type to search…', 'ifthenpay-payments-for-contactform7'); ?>" />
			<input type="submit" class="button" value="<?php esc_attr_e('Search', 'ifthenpay-payments-for-contactform7'); ?>" />
			<?php if ($search_query !== '') : ?>
				<a href="<?php echo esc_url($clear_url); ?>" class="button"><?php esc_html_e('Clear', 'ifthenpay-payments-for-contactform7'); ?></a>
			<?php endif; ?>
			<button type="button" id="iftp-refresh-btn" class="button iftp-icon-btn" aria-label="<?php esc_attr_e('Refresh', 'ifthenpay-payments-for-contactform7'); ?>"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<polyline points="1 4 1 10 7 10" />
					<path d="M3.51 15a9 9 0 1 0 .49-3.76" />
				</svg></button>
			<input type="button" id="iftp-add-payment-btn" class="button button-primary" value="<?php esc_attr_e('Add Payment', 'ifthenpay-payments-for-contactform7'); ?>" />
		</form>
	<?php
	}

	private function render_tablenav_top(int $current_page, int $total_pages, int $total, float $total_amount, string $row_density = 'normal'): void
	{
		$densities = array(
			'compact' => __('Compact', 'ifthenpay-payments-for-contactform7'),
			'normal'  => __('Normal', 'ifthenpay-payments-for-contactform7'),
			'large'   => __('Large', 'ifthenpay-payments-for-contactform7'),
		);
	?>
		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<select name="action">
					<option value="-1"><?php esc_html_e('Bulk Actions', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_paid"><?php esc_html_e('Mark as Paid', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_cancelled"><?php esc_html_e('Mark as Cancelled', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_failed"><?php esc_html_e('Mark as Failed', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_pending"><?php esc_html_e('Mark as Pending', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="export_csv"><?php esc_html_e('Export Selected CSV', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="export_excel"><?php esc_html_e('Export Selected Excel', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="delete"><?php esc_html_e('Delete', 'ifthenpay-payments-for-contactform7'); ?></option>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'ifthenpay-payments-for-contactform7'); ?>" />
			</div>
			<div class="alignright iftp-view-controls">
				<div class="iftp-density-toggle" role="group" aria-label="<?php esc_attr_e('Row density', 'ifthenpay-payments-for-contactform7'); ?>">
					<?php foreach ($densities as $key => $label) : ?>
						<button type="button"
							class="button iftp-density-btn<?php echo $row_density === $key ? ' iftp-density-btn--active' : ''; ?>"
							data-density="<?php echo esc_attr($key); ?>"
							aria-pressed="<?php echo $row_density === $key ? 'true' : 'false'; ?>">
							<?php echo esc_html($label); ?>
						</button>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button iftp-col-customize-btn" id="iftp-col-customize-btn"
					aria-haspopup="true" aria-expanded="false" aria-controls="iftp-col-customize-popover">
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<line x1="8" y1="6" x2="21" y2="6" />
						<line x1="8" y1="12" x2="21" y2="12" />
						<line x1="8" y1="18" x2="21" y2="18" />
						<line x1="3" y1="6" x2="3.01" y2="6" />
						<line x1="3" y1="12" x2="3.01" y2="12" />
						<line x1="3" y1="18" x2="3.01" y2="18" />
					</svg>
					<?php esc_html_e('Columns', 'ifthenpay-payments-for-contactform7'); ?>
				</button>
			</div>
			<?php $this->pagination($current_page, $total_pages, $total, $total_amount); ?>
			<br class="clear" />
		</div>
	<?php
	}

	private function render_tablenav_bottom(int $current_page, int $total_pages): void
	{
	?>
		<div class="tablenav bottom">
			<div class="alignleft actions bulkactions">
				<select name="action2">
					<option value="-1"><?php esc_html_e('Bulk Actions', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_paid"><?php esc_html_e('Mark as Paid', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_cancelled"><?php esc_html_e('Mark as Cancelled', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_failed"><?php esc_html_e('Mark as Failed', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="mark_pending"><?php esc_html_e('Mark as Pending', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="export_csv"><?php esc_html_e('Export Selected CSV', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="export_excel"><?php esc_html_e('Export Selected Excel', 'ifthenpay-payments-for-contactform7'); ?></option>
					<option value="delete"><?php esc_html_e('Delete', 'ifthenpay-payments-for-contactform7'); ?></option>
				</select>
				<input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'ifthenpay-payments-for-contactform7'); ?>" />
			</div>
			<?php $this->pagination($current_page, $total_pages); ?>
			<br class="clear" />
		</div>
	<?php
	}

	private function pagination(int $current_page, int $total_pages, int $total = 0, float $total_amount = 0.0): void
	{

		if ($total > 0 || $total_amount > 0.0) {
			$count_text = sprintf(
				/* translators: %d: number of entries */
				esc_html(_n('%d item', '%d items', $total, 'ifthenpay-payments-for-contactform7')),
				$total
			);
			if ($total_amount > 0.0) {
				$count_text .= ' &mdash; ' . esc_html(number_format($total_amount, 2, '.', ',')) . ' &euro;';
			}
			echo '<span class="displaying-num">' . wp_kses_post($count_text) . '</span>';
		}

		if ($total_pages <= 1) {
			return;
		}

		$first_url = esc_url(add_query_arg('paged', 1));
		$prev_url  = esc_url(add_query_arg('paged', max(1, $current_page - 1)));
		$next_url  = esc_url(add_query_arg('paged', min($total_pages, $current_page + 1)));
		$last_url  = esc_url(add_query_arg('paged', $total_pages));
	?>
		<span class="pagination-links">
			<?php if ($current_page > 1) : ?>
				<a class="first-page button" href="<?php echo esc_url($first_url); ?>"><span aria-hidden="true">&laquo;</span><span class="screen-reader-text"><?php esc_html_e('First page', 'ifthenpay-payments-for-contactform7'); ?></span></a>
				<a class="prev-page button" href="<?php echo esc_url($prev_url); ?>"><span aria-hidden="true">&lsaquo;</span><span class="screen-reader-text"><?php esc_html_e('Previous page', 'ifthenpay-payments-for-contactform7'); ?></span></a>
			<?php else : ?>
				<span class="first-page button disabled" aria-hidden="true">&laquo;</span>
				<span class="prev-page button disabled" aria-hidden="true">&lsaquo;</span>
			<?php endif; ?>

			<span class="paging-input">
				<label class="screen-reader-text" for="iftp-paged-input"><?php esc_html_e('Current page', 'ifthenpay-payments-for-contactform7'); ?></label>
				<input class="current-page" id="iftp-paged-input" type="text" value="<?php echo (int) $current_page; ?>" size="2"
					data-total="<?php echo (int) $total_pages; ?>" />
				<span class="tablenav-paging-text"> <?php esc_html_e('of', 'ifthenpay-payments-for-contactform7'); ?>
					<span class="total-pages"><?php echo (int) $total_pages; ?></span>
				</span>
			</span>

			<?php if ($current_page < $total_pages) : ?>
				<a class="next-page button" href="<?php echo esc_url($next_url); ?>"><span aria-hidden="true">&rsaquo;</span><span class="screen-reader-text"><?php esc_html_e('Next page', 'ifthenpay-payments-for-contactform7'); ?></span></a>
				<a class="last-page button" href="<?php echo esc_url($last_url); ?>"><span aria-hidden="true">&raquo;</span><span class="screen-reader-text"><?php esc_html_e('Last page', 'ifthenpay-payments-for-contactform7'); ?></span></a>
			<?php else : ?>
				<span class="next-page button disabled" aria-hidden="true">&rsaquo;</span>
				<span class="last-page button disabled" aria-hidden="true">&raquo;</span>
			<?php endif; ?>
		</span>
	<?php
	}



	/**
	 * Fill chart DB rows into a complete label/count/amount series for every bucket.
	 *
	 * @param array<int, array{bucket: string, cnt: string, total: string}> $rows
	 * @return array{labels: string[], counts: int[], amounts: float[]}
	 */
	private function build_chart_series(array $rows, string $period): array
	{
		$map_cnt   = [];
		$map_total = [];
		foreach ($rows as $row) {
			$map_cnt[(string) $row['bucket']]   = (int) $row['cnt'];
			$map_total[(string) $row['bucket']] = (float) $row['total'];
		}

		$labels  = [];
		$counts  = [];
		$amounts = [];
		$now     = current_time('timestamp');

		if ($period === 'day') {
			for ($h = 0; $h < 24; $h++) {
				$labels[]  = sprintf('%02d:00', $h);
				$counts[]  = $map_cnt[(string) $h] ?? 0;
				$amounts[] = round((float) ($map_total[(string) $h] ?? 0.0), 2);
			}
		} elseif ($period === 'year') {
			$year = (int) gmdate('Y', $now);
			for ($m = 1; $m <= 12; $m++) {
				$key       = $year . '-' . sprintf('%02d', $m);
				$labels[]  = gmdate('M', mktime(0, 0, 0, $m, 1, $year));
				$counts[]  = $map_cnt[$key] ?? 0;
				$amounts[] = round((float) ($map_total[$key] ?? 0.0), 2);
			}
		} elseif ($period === 'month') {
			$days = (int) gmdate('t', $now);
			$ym   = gmdate('Y-m-', $now);
			for ($d = 1; $d <= $days; $d++) {
				$key       = $ym . sprintf('%02d', $d);
				$labels[]  = (string) $d;
				$counts[]  = $map_cnt[$key] ?? 0;
				$amounts[] = round((float) ($map_total[$key] ?? 0.0), 2);
			}
		} elseif ($period === 'week') {
			for ($i = 6; $i >= 0; $i--) {
				$ts        = $now - $i * DAY_IN_SECONDS;
				$key       = gmdate('Y-m-d', $ts);
				$labels[]  = gmdate('d/m', $ts);
				$counts[]  = $map_cnt[$key] ?? 0;
				$amounts[] = round((float) ($map_total[$key] ?? 0.0), 2);
			}
		} else {
			for ($i = 29; $i >= 0; $i--) {
				$ts        = $now - $i * DAY_IN_SECONDS;
				$key       = gmdate('Y-m-d', $ts);
				$labels[]  = gmdate('d/m', $ts);
				$counts[]  = $map_cnt[$key] ?? 0;
				$amounts[] = round((float) ($map_total[$key] ?? 0.0), 2);
			}
		}

		return compact('labels', 'counts', 'amounts');
	}



	private function format_field_label(string $key): string
	{

		$label = preg_replace('/^(your[-_])/i', '', $key) ?? $key;

		$label = str_replace(array('-', '_'), ' ', $label);
		return ucwords($label);
	}



	/** @return array<string, array{label: string, css: string, sortable: bool, db_col?: string, default_dir?: string}> */
	private function get_col_defs(): array
	{
		return array(
			'id'             => array('label' => __('ID', 'ifthenpay-payments-for-contactform7'),           'css' => 'column-id',           'sortable' => true,  'db_col' => 'id',             'default_dir' => 'desc'),
			'customer_name'  => array('label' => __('Customer', 'ifthenpay-payments-for-contactform7'),      'css' => 'column-customer',     'sortable' => true,  'db_col' => 'customer_name',  'default_dir' => 'asc'),
			'request_id'     => array('label' => __('Request ID', 'ifthenpay-payments-for-contactform7'),    'css' => 'column-request',      'sortable' => false),
			'form_title'     => array('label' => __('Form', 'ifthenpay-payments-for-contactform7'),          'css' => 'column-form',         'sortable' => true,  'db_col' => 'form_title',     'default_dir' => 'asc'),
			'payment_method' => array('label' => __('Method', 'ifthenpay-payments-for-contactform7'),        'css' => 'column-method',       'sortable' => true,  'db_col' => 'payment_method', 'default_dir' => 'asc'),
			'amount'         => array('label' => __('Amount', 'ifthenpay-payments-for-contactform7'),        'css' => 'column-amount',       'sortable' => true,  'db_col' => 'amount',         'default_dir' => 'desc'),
			'payment_status' => array('label' => __('Status', 'ifthenpay-payments-for-contactform7'),        'css' => 'column-status',       'sortable' => true,  'db_col' => 'payment_status', 'default_dir' => 'asc'),
			'payment_link'   => array('label' => __('Payment Link', 'ifthenpay-payments-for-contactform7'),  'css' => 'column-payment-link', 'sortable' => false),
			'created_at'     => array('label' => __('Date', 'ifthenpay-payments-for-contactform7'),          'css' => 'column-date',         'sortable' => true,  'db_col' => 'created_at',     'default_dir' => 'desc'),
		);
	}

	/**
	 * @param array{label: string, css: string, sortable: bool, db_col?: string, default_dir?: string} $col_def
	 * @param array{orderby: string, order: string, status: string, period: string, search_field: string, search_op: string, search_query: string} $sort
	 */
	private function render_col_th(string $col_key, array $col_def, array $sort): void
	{
		$css = $col_def['css'];
		if (! empty($col_def['sortable'])) {
			$db_col    = $col_def['db_col'];
			$is_active = $sort['orderby'] === $db_col;
			if ($is_active) {
				$next_dir = $sort['order'] === 'asc' ? 'desc' : 'asc';
				$th_class = $css . ' sorted ' . $sort['order'];
			} else {
				$next_dir = $col_def['default_dir'];
				$th_class = $css . ' sortable ' . $col_def['default_dir'];
			}
			$args = array('page' => 'ifthenpay-cf7-entries', 'orderby' => $db_col, 'order' => $next_dir);
			if ($sort['status'] !== '') {
				$args['status'] = $sort['status'];
			}
			if ($sort['period'] !== 'all') {
				$args['period'] = $sort['period'];
			}
			if ($sort['search_query'] !== '') {
				$args['search_field'] = $sort['search_field'];
				$args['search_op']    = $sort['search_op'];
				$args['search_query'] = $sort['search_query'];
			}
			printf(
				'<th class="%s" data-col="%s"><a href="%s"><span>%s</span><span class="sorting-indicator" aria-hidden="true"></span></a></th>',
				esc_attr($th_class),
				esc_attr($col_key),
				esc_url(add_query_arg($args, admin_url('admin.php'))),
				esc_html($col_def['label'])
			);
		} else {
			printf(
				'<th class="%s" data-col="%s">%s</th>',
				esc_attr($css),
				esc_attr($col_key),
				esc_html($col_def['label'])
			);
		}
	}



	private function render_single_entry(EntryDto $entry): void
	{
		$back_url = admin_url('admin.php?page=ifthenpay-cf7-entries');
		$del_url  = add_query_arg(
			array(
				'page'        => 'ifthenpay-cf7-entries',
				'iftp_action' => 'delete',
				'entry_id'    => $entry->id,
				'_wpnonce'    => wp_create_nonce('iftp_cf7_entry_action_' . $entry->id),
			),
			admin_url('admin.php')
		);

		$form_data = array();
		if ($entry->form_data !== '' && $entry->form_data !== '{}') {
			$decoded   = json_decode($entry->form_data, true);
			$form_data = is_array($decoded) ? $decoded : array();
		}

		$method_colors = array(
			'mbway'         => '#00a550',
			'multibanco'    => '#2271b1',
			'mb'            => '#2271b1',
			'card'          => '#dba617',
			'creditcard'    => '#dba617',
			'payshop'       => '#e84c3d',
			'cofidis'       => '#003d8f',
			'cofidisinst'   => '#003d8f',
			'ifthenpaylink' => '#f90',
		);
		$dot_color_key      = preg_replace('/[^a-z0-9]/', '', strtolower($entry->payment_method));
		$dot_color          = $method_colors[$dot_color_key] ?? '#8c8f94';
		$detail_method_cat  = get_option('iftp_cf7_method_catalog', array());
		$detail_logos_exact = array();
		$detail_logos_alt   = array();
		$detail_logos_label = array();
		foreach (is_array($detail_method_cat) ? $detail_method_cat : array() as $m) {
			if (! empty($m['entity']) && ! empty($m['logo'])) {
				$ent                         = strtoupper((string) $m['entity']);
				$logo_v                      = (string) $m['logo'];
				$detail_logos_exact[$ent]  = $logo_v;
				$ent_key                     = preg_replace('/[^A-Z0-9]/', '', $ent);
				$detail_logos_alt[$ent_key] = $logo_v;
				if (! empty($m['label'])) {
					$lbl_key = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $m['label']));
					if ($lbl_key !== '' && ! isset($detail_logos_label[$lbl_key])) {
						$detail_logos_label[$lbl_key] = $logo_v;
					}
				}
			}
		}
		$pm_upper        = strtoupper($entry->payment_method);
		$pm_alt          = preg_replace('/[^A-Z0-9]/', '', $pm_upper);
		$detail_logo_url = $detail_logos_exact[$pm_upper]
			?? $detail_logos_alt[$pm_alt]
			?? $detail_logos_label[$pm_alt]
			?? '';
		if ($detail_logo_url === '' && $pm_alt !== '') {
			foreach ($detail_logos_alt as $ent_key => $logo_v) {
				if (str_contains($ent_key, $pm_alt) || str_contains($pm_alt, $ent_key)) {
					$detail_logo_url = $logo_v;
					break;
				}
			}
		}

		$name_parts = preg_split('/\s+/', trim($entry->customer_name));
		$initials   = '';
		foreach (array_slice($name_parts, 0, 2) as $part) {
			if ($part !== '') {
				$initials .= mb_strtoupper(mb_substr($part, 0, 1));
			}
		}
		if ($initials === '') {
			$initials = '?';
		}

		$gravatar_url = '';
		if ($entry->customer_email !== '') {
			$gravatar_url = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($entry->customer_email))) . '?s=80&d=404';
		}
	?>
		<div class="wrap iftp-cf7-entries-wrap">

			<div class="iftp-page-header">
				<div class="iftp-header-left">
					<a href="<?php echo esc_url($back_url); ?>" class="iftp-back-link">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<polyline points="15 18 9 12 15 6" />
						</svg>
						<?php esc_html_e('Entries', 'ifthenpay-payments-for-contactform7'); ?>
					</a>
					<h1 class="wp-heading-inline">
						<?php esc_html_e('Entry', 'ifthenpay-payments-for-contactform7'); ?>
						<strong>#<?php echo esc_html((string) $entry->id); ?></strong>
					</h1>
					<span class="iftp-badge">
						<svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true">
							<circle cx="12" cy="5" r="2.2" />
							<rect x="10.2" y="9" width="3.6" height="11" rx="1.5" />
						</svg>
						ifthenpay
					</span>
				</div>
				<a href="<?php echo esc_url($del_url); ?>" class="iftp-delete-btn"
					onclick="return confirm('<?php esc_attr_e('Delete this entry permanently?', 'ifthenpay-payments-for-contactform7'); ?>');">
					<svg viewBox="0 0 24 24" aria-hidden="true">
						<polyline points="3 6 5 6 21 6" />
						<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
						<path d="M10 11v6M14 11v6" />
						<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
					</svg>
					<?php esc_html_e('Delete Entry', 'ifthenpay-payments-for-contactform7'); ?>
				</a>
			</div>
			<hr class="wp-header-end" />

			<div class="iftp-detail-layout">

				<!-- ── Main column ── -->
				<div class="iftp-detail-main">

					<!-- Payment card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true">
								<rect x="1" y="4" width="22" height="16" rx="2" />
								<line x1="1" y1="10" x2="23" y2="10" />
							</svg>
							<h2><?php esc_html_e('Payment', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-card-body">
							<table class="iftp-detail-table">
								<tr>
									<th><?php esc_html_e('Status', 'ifthenpay-payments-for-contactform7'); ?></th>
									<td><span class="iftp-cf7-status-badge iftp-cf7-status-<?php echo esc_attr($entry->payment_status); ?>"><?php echo esc_html($entry->status_label()); ?></span></td>
								</tr>
								<tr>
									<th><?php esc_html_e('Amount', 'ifthenpay-payments-for-contactform7'); ?></th>
									<td><span class="iftp-amount"><?php echo esc_html($entry->amount_formatted()); ?></span></td>
								</tr>
								<tr>
									<th><?php esc_html_e('Method', 'ifthenpay-payments-for-contactform7'); ?></th>
									<td>
										<?php if ($entry->payment_method !== '') : ?>
											<span class="iftp-method-pill">
												<?php if ($detail_logo_url !== '') : ?>
													<img class="iftp-method-logo-img" src="<?php echo esc_url($detail_logo_url); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block'" />
													<span class="iftp-method-dot" style="background:<?php echo esc_attr($dot_color); ?>;display:none"></span>
												<?php else : ?>
													<span class="iftp-method-dot" style="background:<?php echo esc_attr($dot_color); ?>"></span>
												<?php endif; ?>
												<?php echo esc_html($entry->payment_method); ?>
											</span>
										<?php
										else :
										?>
											—<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e('Request ID', 'ifthenpay-payments-for-contactform7'); ?></th>
									<td><code><?php echo esc_html($entry->request_id ?? '—'); ?></code></td>
								</tr>
								<?php if ($entry->payment_url !== '') : ?>
									<tr>
										<th><?php esc_html_e('Payment Link', 'ifthenpay-payments-for-contactform7'); ?></th>
										<td>
											<a href="<?php echo esc_url($entry->payment_url); ?>" target="_blank" rel="noopener noreferrer" class="iftp-pay-link">
												<?php esc_html_e('Open payment link', 'ifthenpay-payments-for-contactform7'); ?>
												<svg viewBox="0 0 24 24" aria-hidden="true">
													<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
													<polyline points="15 3 21 3 21 9" />
													<line x1="10" y1="14" x2="21" y2="3" />
												</svg>
											</a>
										</td>
									</tr>
								<?php endif; ?>
							</table>
						</div>
					</div>

					<!-- Form card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
								<polyline points="14 2 14 8 20 8" />
								<line x1="16" y1="13" x2="8" y2="13" />
								<line x1="16" y1="17" x2="8" y2="17" />
							</svg>
							<h2><?php esc_html_e('Form', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-card-body">
							<table class="iftp-detail-table">
								<tr>
									<th><?php esc_html_e('Form', 'ifthenpay-payments-for-contactform7'); ?></th>
									<td><?php echo esc_html($entry->form_title ?: 'Form #' . $entry->form_id); ?></td>
								</tr>
							</table>
						</div>
					</div>

					<?php if (! empty($form_data)) : ?>
						<!-- Submitted data card -->
						<div class="iftp-card">
							<div class="iftp-card-head">
								<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true">
									<line x1="8" y1="6" x2="21" y2="6" />
									<line x1="8" y1="12" x2="21" y2="12" />
									<line x1="8" y1="18" x2="21" y2="18" />
									<line x1="3" y1="6" x2="3.01" y2="6" />
									<line x1="3" y1="12" x2="3.01" y2="12" />
									<line x1="3" y1="18" x2="3.01" y2="18" />
								</svg>
								<h2><?php esc_html_e('Submitted Data', 'ifthenpay-payments-for-contactform7'); ?></h2>
							</div>
							<div class="iftp-card-body">
								<table class="iftp-submitted">
									<thead>
										<tr>
											<th><?php esc_html_e('Field', 'ifthenpay-payments-for-contactform7'); ?></th>
											<th><?php esc_html_e('Value', 'ifthenpay-payments-for-contactform7'); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php
										foreach ($form_data as $key => $value) :
											if (strpos((string) $key, 'iftp_cf7_') === 0) {
												continue;
											}
											$str_val = is_array($value) ? implode(', ', $value) : (string) $value;
											$max_len = 200;
											$is_long = mb_strlen($str_val) > $max_len;
										?>
											<tr>
												<td><?php echo esc_html($this->format_field_label((string) $key)); ?></td>
												<td>
													<?php if ($is_long) : ?>
														<span class="iftp-val-short"><?php echo esc_html(mb_substr($str_val, 0, $max_len)); ?>&hellip;</span>
														<span class="iftp-val-full"><?php echo esc_html($str_val); ?></span>
														<br /><a href="#" class="iftp-read-more"
															data-more="<?php esc_attr_e('Read more', 'ifthenpay-payments-for-contactform7'); ?>"
															data-less="<?php esc_attr_e('Read less', 'ifthenpay-payments-for-contactform7'); ?>">
															<?php esc_html_e('Read more', 'ifthenpay-payments-for-contactform7'); ?>
														</a>
													<?php else : ?>
														<?php echo esc_html($str_val); ?>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</div>
					<?php endif; ?>

				</div><!-- .iftp-detail-main -->

				<!-- ── Aside ── -->
				<div class="iftp-detail-aside">

					<!-- Summary card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true">
								<line x1="12" y1="1" x2="12" y2="23" />
								<path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
							</svg>
							<h2><?php esc_html_e('Summary', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-summary-body">
							<div class="iftp-summary-amount"><?php echo esc_html($entry->amount_formatted()); ?></div>
							<div class="iftp-summary-sub">
								<?php if ($entry->payment_method !== '') : ?>
									<?php esc_html_e('via', 'ifthenpay-payments-for-contactform7'); ?>
									<?php echo esc_html($entry->payment_method); ?> &middot;
								<?php endif; ?>
								<span class="iftp-cf7-status-badge iftp-cf7-status-<?php echo esc_attr($entry->payment_status); ?> iftp-status-sm">
									<?php echo esc_html($entry->status_label()); ?>
								</span>
							</div>
							<hr class="iftp-summary-divider" />
							<div class="iftp-meta-list">
								<div class="iftp-meta-row">
									<span class="iftp-meta-label"><?php esc_html_e('Entry', 'ifthenpay-payments-for-contactform7'); ?></span>
									<span class="iftp-meta-value">#<?php echo esc_html((string) $entry->id); ?></span>
								</div>
								<div class="iftp-meta-row">
									<span class="iftp-meta-label"><?php esc_html_e('Form', 'ifthenpay-payments-for-contactform7'); ?></span>
									<span class="iftp-meta-value"><?php echo esc_html($entry->form_title ?: 'Form #' . $entry->form_id); ?></span>
								</div>
							</div>
						</div>
					</div>

					<!-- Customer aside card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true">
								<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
								<circle cx="12" cy="7" r="4" />
							</svg>
							<h2><?php esc_html_e('Customer', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-customer-body">
							<div class="iftp-customer-row">
								<div class="iftp-customer-avatar" aria-hidden="true">
									<?php echo esc_html($initials); ?>
									<?php if ($gravatar_url !== '') : ?>
										<img class="iftp-gravatar" src="<?php echo esc_url($gravatar_url); ?>" alt="" onerror="this.style.display='none';" />
									<?php endif; ?>
								</div>
								<div>
									<div class="iftp-customer-name"><?php echo esc_html($entry->customer_name ?: '—'); ?></div>
									<?php if ($entry->customer_email !== '') : ?>
										<div class="iftp-customer-email">
											<a href="mailto:<?php echo esc_attr($entry->customer_email); ?>"><?php echo esc_html($entry->customer_email); ?></a>
										</div>
									<?php endif; ?>
								</div>
							</div>
							<?php if ($entry->customer_ip !== '') : ?>
								<div class="iftp-customer-ip">
									<span class="iftp-meta-label"><?php esc_html_e('IP', 'ifthenpay-payments-for-contactform7'); ?></span>
									<code><?php echo esc_html($entry->customer_ip); ?></code>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<!-- Timestamps card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<svg class="iftp-card-icon" viewBox="0 0 24 24" aria-hidden="true">
								<circle cx="12" cy="12" r="10" />
								<polyline points="12 6 12 12 16 14" />
							</svg>
							<h2><?php esc_html_e('Timestamps', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-timestamps">
							<div class="iftp-ts-row">
								<span class="iftp-ts-label"><?php esc_html_e('Created', 'ifthenpay-payments-for-contactform7'); ?></span>
								<span class="iftp-ts-value"><?php echo esc_html($entry->created_at); ?></span>
							</div>
							<div class="iftp-ts-row">
								<span class="iftp-ts-label"><?php esc_html_e('Updated', 'ifthenpay-payments-for-contactform7'); ?></span>
								<span class="iftp-ts-value"><?php echo esc_html($entry->updated_at); ?></span>
							</div>
						</div>
					</div>

				</div><!-- .iftp-detail-aside -->

			</div><!-- .iftp-detail-layout -->
		</div><!-- .wrap -->
		<script>
			(function() {
				document.querySelectorAll('.iftp-read-more').forEach(function(btn) {
					btn.addEventListener('click', function(e) {
						e.preventDefault();
						var td = btn.closest('td');
						var full = td.querySelector('.iftp-val-full');
						var short = td.querySelector('.iftp-val-short');
						var open = full.classList.contains('iftp-val-open');
						if (open) {
							full.classList.remove('iftp-val-open');
							short.style.display = '';
							btn.textContent = btn.getAttribute('data-more');
						} else {
							full.classList.add('iftp-val-open');
							short.style.display = 'none';
							btn.textContent = btn.getAttribute('data-less');
						}
					});
				});
			})();
		</script>
<?php
	}
}
