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

	private const VALID_PER_PAGE = array(10, 20, 30, 40);

	/**
	 * Hooked to load-{page} so headers are not yet sent — safe to redirect.
	 */
	public function process_actions(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$repo = new EntryRepository();

		$iftp_action = isset($_GET['iftp_action']) ? sanitize_key(wp_unslash((string) $_GET['iftp_action'])) : '';
		if ($iftp_action === 'delete' && isset($_GET['entry_id'], $_GET['_wpnonce'])) {
			$act_id    = absint($_GET['entry_id']);
			$act_nonce = sanitize_text_field(wp_unslash((string) $_GET['_wpnonce']));
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

		$view_nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce'])) : '';
		if (isset($_GET['entry_id']) && wp_verify_nonce($view_nonce, 'iftp_cf7_view_entry')) {
			$entry_id = absint($_GET['entry_id']);
			$entry    = $repo->get_by_id($entry_id);
			if ($entry !== null) {
				$this->render_single_entry($entry);
				return;
			}
		}


		$user_id = get_current_user_id();
		$prefs   = $user_id ? UserPreferences::get($user_id) : UserPreferences::defaults();

		$current_page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
		$search_field = isset($_GET['search_field']) ? sanitize_key(wp_unslash((string) $_GET['search_field'])) : 'customer_name';
		$search_op    = isset($_GET['search_op']) ? sanitize_key(wp_unslash((string) $_GET['search_op'])) : 'contains';
		$search_query = isset($_GET['search_query']) ? sanitize_text_field(wp_unslash((string) $_GET['search_query'])) : '';
		$search_op    = in_array($search_op, array('contains', 'is'), true) ? $search_op : 'contains';


		$orderby_in_url = isset($_GET['orderby']);
		$order_in_url   = isset($_GET['order']);

		$status_raw   = isset($_GET['status'])  ? sanitize_key(wp_unslash((string) $_GET['status']))  : '';
		$period_raw   = isset($_GET['period'])  ? sanitize_key(wp_unslash((string) $_GET['period']))  : 'all';
		$orderby_raw  = $orderby_in_url ? (isset($_GET['orderby']) ? sanitize_key(wp_unslash((string) $_GET['orderby'])) : '') : $prefs['orderby'];
		$order_raw    = $order_in_url   ? (isset($_GET['order'])   ? sanitize_key(wp_unslash((string) $_GET['order']))   : '') : $prefs['order'];
		$per_page_raw = absint((int) ($_GET['per_page'] ?? 20));
		$form_id      = absint((int) ($_GET['form_id'] ?? 0));

		$sort_cols = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$status    = in_array($status_raw,  array('', 'pending', 'completed', 'failed', 'cancelled', 'expired'), true) ? $status_raw  : '';
		$period    = in_array($period_raw,  array('all', 'year', 'month', 'week', 'day'), true)              ? $period_raw  : 'all';
		$orderby   = in_array($orderby_raw, $sort_cols, true)                                                   ? $orderby_raw : 'id';
		$order     = in_array($order_raw,   array('asc', 'desc'), true)                                       ? $order_raw   : 'desc';
		$per_page  = in_array((int) $per_page_raw, self::VALID_PER_PAGE, true)                                  ? (int) $per_page_raw : 20;


		if ($user_id && ($orderby_in_url || $order_in_url)) {
			$to_save = array();
			if ($orderby_in_url) $to_save['orderby'] = $orderby;
			if ($order_in_url)   $to_save['order']   = $order;
			UserPreferences::merge($user_id, $to_save);
			$prefs = array_merge($prefs, $to_save);
		}

		$db_status = in_array($status, array('pending', 'completed', 'failed', 'cancelled', 'expired'), true) ? $status : '';


		$cursor  = ($current_page > 1) ? absint((int) ($_GET['cursor'] ?? 0)) : 0;
		$dir_raw = isset($_GET['dir']) ? sanitize_key(wp_unslash((string) $_GET['dir'])) : '';
		$dir     = in_array($dir_raw, array('prev', 'last'), true) ? $dir_raw : 'next';


		[$total_count] = $repo->count_and_sum($db_status, $search_field, $search_op, $search_query, $period, $form_id);


		if ($dir === 'last') {
			$current_page = max(1, (int) ceil($total_count / $per_page));
			$cursor       = 0;
			$dir          = 'next';
		}


		$entries_raw = $repo->get_all($current_page, $per_page, $db_status, $search_field, $search_op, $search_query, $period, $orderby, $order, $cursor, $dir, $form_id);

		$has_more = count($entries_raw) > $per_page;
		$entries  = $has_more ? array_slice($entries_raw, 0, $per_page) : $entries_raw;


		if ($orderby === 'id' && $dir === 'prev') {
			$entries   = array_reverse($entries);
			$has_prev  = $has_more;
			$has_next  = $current_page > 1;
		} else {
			$has_prev  = $current_page > 1;
			$has_next  = $has_more;
		}

		$first_id = ! empty($entries) ? $entries[0]->id : 0;
		$last_id  = ! empty($entries) ? $entries[count($entries) - 1]->id : 0;

		$first_entry = empty($entries) ? 0 : ($current_page - 1) * $per_page + 1;
		$last_entry  = empty($entries) ? 0 : $first_entry + count($entries) - 1;


		$base_args = array('page' => 'ifthenpay-cf7-entries');
		if ($status !== '')       $base_args['status']       = $status;
		if ($period !== 'all')    $base_args['period']       = $period;
		if ($orderby !== 'id')    $base_args['orderby']      = $orderby;
		if ($order !== 'desc')    $base_args['order']        = $order;
		if ($per_page !== 20)     $base_args['per_page']     = $per_page;
		if ($form_id > 0)         $base_args['form_id']      = $form_id;
		if ($search_query !== '') {
			$base_args['search_field'] = $search_field;
			$base_args['search_op']    = $search_op;
			$base_args['search_query'] = $search_query;
		}

		if ($orderby === 'id') {

			$next_url = $has_next ? add_query_arg(array_merge($base_args, array('paged' => $current_page + 1, 'cursor' => $last_id, 'dir' => 'next')), admin_url('admin.php')) : '';
			if (! $has_prev) {
				$prev_url = '';
			} elseif ($current_page === 2) {
				$prev_url = add_query_arg($base_args, admin_url('admin.php'));
			} else {
				$prev_url = add_query_arg(array_merge($base_args, array('paged' => $current_page - 1, 'cursor' => $first_id, 'dir' => 'prev')), admin_url('admin.php'));
			}
		} else {

			$next_url = $has_next ? add_query_arg(array_merge($base_args, array('paged' => $current_page + 1)), admin_url('admin.php')) : '';
			$prev_url = $has_prev ? add_query_arg(array_merge($base_args, array('paged' => max(1, $current_page - 1))), admin_url('admin.php')) : '';
		}


		$first_page_url = add_query_arg($base_args, admin_url('admin.php'));

		$forms = $this->get_cf7_forms_for_dropdown($repo);
		$this->render_list($repo, $entries, $current_page, $has_prev, $has_next, $prev_url, $next_url, $first_page_url, $status, $search_field, $search_op, $search_query, $period, $orderby, $order, $form_id, $forms, $prefs, $per_page, $total_count, $first_entry, $last_entry);
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
			'mark_expired'   => $repo->bulk_update_status($ids, 'expired'),
			default          => null,
		};

		$args = array('page' => 'ifthenpay-cf7-entries', 'bulk_done' => '1');

		foreach (array('status', 'period', 'paged', 'search_field', 'search_op', 'search_query') as $key) {
			$val = isset($_GET[$key]) ? wp_unslash((string) $_GET[$key]) : '';
			if ($val !== '') {
				$args[$key] = sanitize_text_field($val);
			}
		}
		wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
		exit;
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

		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		$suffix     = '';
		$len        = wp_rand(5, 10);
		for ($i = 0; $i < $len; $i++) {
			$suffix .= $chars[ wp_rand(0, strlen($chars) - 1) ];
		}
		$request_id = 'Manual-' . $suffix;


		$form_data_raw = isset($_POST['form_data']) ? wp_unslash((string) $_POST['form_data']) : '';
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

		if (! in_array($status, array('pending', 'completed', 'failed', 'cancelled', 'expired'), true)) {
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
					'request_id'     => $request_id,
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


		$raw  = isset($_POST['prefs']) ? wp_unslash((string) $_POST['prefs']) : '';
		$data = json_decode($raw, true);
		if (! is_array($data)) {
			wp_send_json_error(array('message' => 'Invalid data.'));
		}

		$allowed_ob   = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$allowed_cols = array('id', 'customer_name', 'request_id', 'form_title', 'payment_method', 'amount', 'payment_status', 'payment_link', 'created_at');
		$clean        = array();

		foreach ($data as $k => $v) {
			switch ($k) {
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
						$filtered    = array_values(array_filter($v, fn($c) => in_array($c, $allowed_cols, true)));
						$fixed_first = array('id', 'customer_name');
						$clean['column_positions'] = array_merge(
							$fixed_first,
							array_values(array_filter($filtered, fn($c) => ! in_array($c, $fixed_first, true)))
						);
					}
					break;
				case 'visible_columns':
					if (is_array($v)) {
						$filtered = array_values(array_filter($v, fn($c) => in_array($c, $allowed_cols, true)));
						$merged   = array_values(array_unique(array_merge(array('id', 'customer_name'), $filtered)));
						if (! empty($merged)) {
							$clean['visible_columns'] = $merged;
						}
					}
					break;
				case 'rev_bar_mode':
					if (in_array($v, array('split', 'solid'), true)) {
						$clean['rev_bar_mode'] = $v;
					}
					break;
			}
		}

		UserPreferences::merge($user_id, $clean);
		wp_send_json_success();
	}

	public function ajax_dismiss_add_payment_notice(): void
	{
		check_ajax_referer('iftp_cf7_dismiss_ap_notice', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Unauthorized.'), 403);
		}
		$user_id = get_current_user_id();
		if (! $user_id) {
			wp_send_json_error(array('message' => 'Not logged in.'), 403);
		}
		update_user_meta($user_id, 'iftp_cf7_add_payment_notice_dismissed', '1');
		wp_send_json_success();
	}

	public function ajax_dismiss_info_box(): void
	{
		check_ajax_referer('iftp_cf7_dismiss_info_box', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => 'Unauthorized.'), 403);
		}
		$user_id = get_current_user_id();
		if (! $user_id) {
			wp_send_json_error(array('message' => 'Not logged in.'), 403);
		}
		update_user_meta($user_id, 'iftp_cf7_info_box_dismissed', '1');
		wp_send_json_success();
	}

	/**
	 * @param EntryDto[] $entries
	 */
	private function render_list(
		EntryRepository $repo,
		array $entries,
		int $current_page,
		bool $has_prev,
		bool $has_next,
		string $prev_url,
		string $next_url,
		string $first_page_url,
		string $current_tab,
		string $search_field = 'customer_name',
		string $search_op = 'contains',
		string $search_query = '',
		string $period = 'all',
		string $orderby = 'id',
		string $order = 'desc',
		int $form_id = 0,
		array $forms = array(),
		array $prefs = array(),
		int $per_page = 20,
		int $total_count = 0,
		int $first_entry = 0,
		int $last_entry = 0
	): void {

		$stats   = $repo->get_period_stats($period);
		$counts  = array(
			''          => $stats['completed_any'] + $stats['pending_any'] + $stats['failed_any'] + $stats['cancelled_any'] + $stats['expired_any'],
			'pending'   => $stats['pending_any'],
			'completed' => $stats['completed_any'],
			'failed'    => $stats['failed_any'],
			'cancelled' => $stats['cancelled_any'],
			'expired'   => $stats['expired_any'],
		);

		$validated_tab   = in_array($current_tab, array('pending', 'completed', 'failed', 'cancelled', 'expired'), true) ? $current_tab : '';
		$revenue_status  = $validated_tab !== '' ? $validated_tab : 'completed';
		$sidebar_revenue = (float) ($stats[$revenue_status . '_amount'] ?? 0.0);
		$sidebar_count   = (int)   ($stats[$revenue_status . '_count']  ?? 0);

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
			'form_id'      => $form_id,
		);

		$col_defs     = $this->get_col_defs();
		$all_col_keys = array_keys($col_defs);
		$saved_order  = ! empty($prefs['column_positions']) && is_array($prefs['column_positions'])
			? $prefs['column_positions']
			: $all_col_keys;

		$ordered_cols = array_values(array_filter($saved_order, fn($k) => isset($col_defs[$k])));
		$missing_cols = array_diff($all_col_keys, $ordered_cols);
		$ordered_cols = array_merge($ordered_cols, array_values($missing_cols));

		$fixed_col_keys = array_keys(array_filter($col_defs, fn($d) => ! empty($d['fixed'])));
		$ordered_cols   = array_merge(
			$fixed_col_keys,
			array_values(array_filter($ordered_cols, fn($k) => ! in_array($k, $fixed_col_keys, true)))
		);

		$col_labels_json = (string) wp_json_encode(
			array_map(fn(array $d) => $d['label'], $col_defs)
		);

		$visible_cols = ! empty($prefs['visible_columns']) && is_array($prefs['visible_columns'])
			? array_values(array_filter($prefs['visible_columns'], fn($k) => isset($col_defs[$k])))
			: $all_col_keys;
		if (empty($visible_cols)) {
			$visible_cols = $all_col_keys;
		}

		foreach ($fixed_col_keys as $fc) {
			if (! in_array($fc, $visible_cols, true)) {
				$visible_cols[] = $fc;
			}
		}
		$visible_set  = array_flip($visible_cols);
		$hidden_cols  = array_values(array_diff($all_col_keys, $visible_cols));

		$period_options = array(
			'all'   => _x('All', 'period filter', 'ifthenpay-payments-for-contactform7'),
			'year'  => _x('Year', 'period filter', 'ifthenpay-payments-for-contactform7'),
			'month' => _x('Month', 'period filter', 'ifthenpay-payments-for-contactform7'),
			'week'  => _x('Week', 'period filter', 'ifthenpay-payments-for-contactform7'),
			'day'   => _x('Day', 'period filter', 'ifthenpay-payments-for-contactform7'),
		);

?>
		<div class="wrap iftp-cf7-entries-wrap">
			<div class="iftp-page-header">
				<div class="iftp-header-left">
					<div class="iftp-page-title-chip">
						<img src="<?php echo esc_url(IFTP_CF7_URL . 'assets/images/ifthenpaylogo.webp'); ?>" alt="ifthenpay" class="iftp-brand-logo" draggable="false" />
						<span class="iftp-page-title-chip-sep" aria-hidden="true"></span>
						<span class="iftp-page-title-chip-label"><?php esc_html_e('Entries', 'ifthenpay-payments-for-contactform7'); ?></span>
					</div>
					<span class="iftp-period-mobile-label">| <?php echo esc_html($period_options[$period] ?? ''); ?></span>
				</div>
				<div class="iftp-header-right">
					<!-- Add Payment button -->
					<button type="button" id="iftp-add-payment-btn" class="iftp-action-btn iftp-action-btn--add">
						<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<line x1="12" y1="5" x2="12" y2="19" />
							<line x1="5" y1="12" x2="19" y2="12" />
						</svg>
						<span class="iftp-btn-sep" aria-hidden="true"></span>
						<span class="iftp-action-btn-label"><?php esc_html_e('Add Payment', 'ifthenpay-payments-for-contactform7'); ?></span>
					</button>

					<!-- Calendar / Period dropdown — replaces the old horizontal tab strip -->
					<div class="iftp-period-dropdown" id="iftp-period-dropdown">
						<button type="button" class="iftp-action-btn iftp-period-dropdown-trigger" id="iftp-period-trigger"
							aria-haspopup="true" aria-expanded="false" aria-controls="iftp-period-panel"
							aria-label="<?php esc_attr_e('Time period', 'ifthenpay-payments-for-contactform7'); ?>">
							<span class="iftp-period-cal-icon" aria-hidden="true">
								<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 448 512" fill="currentColor">
									<path d="M0 464c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V192H0v272zm320-160c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm0 96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm-128-96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm0 96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm-128-96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16v-32zm0 96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16v-32zM400 64h-48V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H160V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H48C21.5 64 0 85.5 0 112v48h448v-48c0-26.5-21.5-48-48-48z" />
								</svg>
							</span>
							<span class="iftp-period-dropdown-label" id="iftp-period-label"><?php echo esc_html($period_options[$period]); ?></span>
							<svg class="iftp-period-dropdown-chevron" viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<polyline points="6 9 12 15 18 9" />
							</svg>
						</button>
						<div class="iftp-period-dropdown-panel" id="iftp-period-panel" hidden role="menu">
							<?php foreach ($period_options as $pkey => $plabel) :
								$purl = add_query_arg(
									array_filter(
										array(
											'page'         => 'ifthenpay-cf7-entries',
											'period'       => $pkey,
											'status'       => $current_tab !== '' ? $current_tab : null,
											'form_id'      => $form_id > 0 ? $form_id : null,
											'search_field' => $search_query !== '' ? $search_field : null,
											'search_op'    => $search_query !== '' ? $search_op : null,
											'search_query' => $search_query !== '' ? $search_query : null,
										)
									),
									admin_url('admin.php')
								);
							?>
								<a href="<?php echo esc_url($purl); ?>"
									class="iftp-period-opt<?php echo $period === $pkey ? ' active' : ''; ?>"
									role="menuitem">
									<span class="iftp-period-opt-dot" aria-hidden="true"></span>
									<?php echo esc_html($plabel); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>

				</div>
			</div>
			<hr class="wp-header-end" />

			<?php

			$rev_labels = array(
				'bluecompleted'	 => __('Gray Revenue', 'ifthenpay-payments-for-contactform7'),
				'completed'		 => __('Paid Revenue', 'ifthenpay-payments-for-contactform7'),
				'pending'   	 => __('Pending Total', 'ifthenpay-payments-for-contactform7'),
				'failed'    	 => __('Failed Total', 'ifthenpay-payments-for-contactform7'),
				'cancelled'	  	 => __('Cancelled Total', 'ifthenpay-payments-for-contactform7'),
				'expired'   	 => __('Expired Total', 'ifthenpay-payments-for-contactform7'),
			);
			$revenue_css = $validated_tab !== '' ? $validated_tab : 'bluecompleted';
			$rev_label   = $rev_labels[$revenue_status] ?? $rev_labels['bluecompleted'];
			$rev_bar_mode     = $prefs['rev_bar_mode'] ?? 'split';
			$bar_solid_colors = array('completed' => '#34a853', 'pending' => '#d69300', 'failed' => '#ea4335', 'cancelled' => '#718096', 'expired' => '#2e414e');
			$bar_solid_color  = $bar_solid_colors[$current_tab] ?? '#00609c';
			$period_labels = array(
				'all'   => __('All time', 'ifthenpay-payments-for-contactform7'),
				'year'  => __('This year', 'ifthenpay-payments-for-contactform7'),
				'month' => __('This month', 'ifthenpay-payments-for-contactform7'),
				'week'  => __('Last 7 days', 'ifthenpay-payments-for-contactform7'),
				'day'   => __('Today', 'ifthenpay-payments-for-contactform7'),
			);


			$_stat_base = array_filter(array(
				'page'    => 'ifthenpay-cf7-entries',
				'period'  => $period !== 'all' ? $period : null,
				'form_id' => $form_id > 0 ? $form_id : null,
			));
			$_stat_url  = static function (string $s) use ($current_tab, $_stat_base): string {
				return add_query_arg(array_merge($_stat_base, array('status' => $current_tab === $s ? '' : $s)), admin_url('admin.php'));
			};
			?>
			<?php
			$_period_label = $period_labels[$period] ?? $period_labels['all'];
			?>
			<?php /* Stats row */ ?>
			<div class="iftp-stats-row<?php echo $current_tab !== '' ? ' has-active' : ''; ?>" data-active-status="<?php echo esc_attr($current_tab); ?>">
				<div class="iftp-stat-card iftp-stat-card--<?php echo esc_attr($revenue_css); ?> iftp-stat-card--revenue">
					<div class="iftp-stat-card-label"><span class="iftp-stat-card-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<polyline points="2,15 7,9 11,12 17,5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
								<polyline points="13,5 17,5 17,9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
							</svg></span><?php echo esc_html($rev_label); ?></div>
					<div class="iftp-stat-card-amount"><?php echo esc_html(number_format($sidebar_revenue, 2, ',', '.') . ' €'); ?></div>
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
					<div class="iftp-rev-bar<?php echo $rev_bar_mode === 'solid' ? ' iftp-rev-bar--solid' : ''; ?>"
						role="button" tabindex="0"
						aria-label="<?php esc_attr_e('Toggle bar view', 'ifthenpay-payments-for-contactform7'); ?>"
						data-rev-bar-mode="<?php echo esc_attr($rev_bar_mode); ?>">
						<div class="iftp-rev-seg iftp-rev-seg--solid" style="flex:1;background:<?php echo esc_attr($bar_solid_color); ?>"></div>
						<?php if (($counts['completed'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['completed']; ?>;background:#34a853"></div><?php endif; ?>
						<?php if (($counts['pending'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['pending']; ?>;background:#d69300"></div><?php endif; ?>
						<?php if (($counts['failed'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['failed']; ?>;background:#ea4335"></div><?php endif; ?>
						<?php if (($counts['cancelled'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['cancelled']; ?>;background:#718096"></div><?php endif; ?>
						<?php if (($counts['expired'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['expired']; ?>;background:#2e414e"></div><?php endif; ?>
					</div>
					<span class="iftp-stat-card-ghost iftp-stat-card-ghost--revenue" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<polyline points="2,15 7,9 11,12 17,5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
							<polyline points="13,5 17,5 17,9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
						</svg></span>
				</div>
				<a href="<?php echo esc_url($_stat_url('completed')); ?>" class="iftp-stat-card iftp-stat-card--completed<?php echo $current_tab === 'completed' ? ' iftp-stat-card--active' : ''; ?>" data-status="completed" draggable="false">
					<div class="iftp-stat-card-header iftp-stat-label--paid"><span class="iftp-stat-card-icon" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
								<path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
							</svg></span><span class="iftp-stat-card-label"><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></span></div>
					<div class="iftp-stat-card-val iftp-stat-val--paid"><?php echo esc_html((string) ($counts['completed'] ?? 0)); ?></div>
					<div class="iftp-stat-card-sub"><?php echo esc_html($_period_label); ?></div>
					<div class="iftp-rev-bar">
						<?php if (($counts['completed'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['completed']; ?>;background:#34a853"></div><?php endif; ?>
					</div>
					<span class="iftp-stat-card-ghost" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
							<path d="M6.5 10.5l2.5 2.5 4.5-5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
						</svg></span>
				</a>
				<a href="<?php echo esc_url($_stat_url('pending')); ?>" class="iftp-stat-card iftp-stat-card--pending<?php echo $current_tab === 'pending' ? ' iftp-stat-card--active' : ''; ?>" data-status="pending" draggable="false">
					<div class="iftp-stat-card-header iftp-stat-label--pending"><span class="iftp-stat-card-icon" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
								<path d="M10 6v4l2.5 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
							</svg></span><span class="iftp-stat-card-label"><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></span></div>
					<div class="iftp-stat-card-val iftp-stat-val--pending"><?php echo esc_html((string) ($counts['pending'] ?? 0)); ?></div>
					<div class="iftp-stat-card-sub"><?php echo esc_html($_period_label); ?></div>
					<div class="iftp-rev-bar">
						<?php if (($counts['pending'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['pending']; ?>;background:#d69300"></div><?php endif; ?>
					</div>
					<span class="iftp-stat-card-ghost" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
							<path d="M10 6v4l2.5 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
						</svg></span>
				</a>
				<a href="<?php echo esc_url($_stat_url('failed')); ?>" class="iftp-stat-card iftp-stat-card--failed<?php echo $current_tab === 'failed' ? ' iftp-stat-card--active' : ''; ?>" data-status="failed" draggable="false">
					<div class="iftp-stat-card-header iftp-stat-label--failed"><span class="iftp-stat-card-icon" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
								<path d="M7 7l6 6M13 7l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
							</svg></span><span class="iftp-stat-card-label"><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></span></div>
					<div class="iftp-stat-card-val iftp-stat-val--failed"><?php echo esc_html((string) ($counts['failed'] ?? 0)); ?></div>
					<div class="iftp-stat-card-sub"><?php echo esc_html($_period_label); ?></div>
					<div class="iftp-rev-bar">
						<?php if (($counts['failed'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['failed']; ?>;background:#ea4335"></div><?php endif; ?>
					</div>
					<span class="iftp-stat-card-ghost" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
							<path d="M7 7l6 6M13 7l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
						</svg></span>
				</a>
				<a href="<?php echo esc_url($_stat_url('cancelled')); ?>" class="iftp-stat-card iftp-stat-card--cancelled<?php echo $current_tab === 'cancelled' ? ' iftp-stat-card--active' : ''; ?>" data-status="cancelled" draggable="false">
					<div class="iftp-stat-card-header iftp-stat-label--cancelled"><span class="iftp-stat-card-icon" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
								<path d="M7 10h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
							</svg></span><span class="iftp-stat-card-label"><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></span></div>
					<div class="iftp-stat-card-val iftp-stat-val--cancelled"><?php echo esc_html((string) ($counts['cancelled'] ?? 0)); ?></div>
					<div class="iftp-stat-card-sub"><?php echo esc_html($_period_label); ?></div>
					<div class="iftp-rev-bar">
						<?php if (($counts['cancelled'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['cancelled']; ?>;background:#718096"></div><?php endif; ?>
					</div>
					<span class="iftp-stat-card-ghost" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5" />
							<path d="M7 10h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
						</svg></span>
				</a>
				<a href="<?php echo esc_url($_stat_url('expired')); ?>" class="iftp-stat-card iftp-stat-card--expired<?php echo $current_tab === 'expired' ? ' iftp-stat-card--active' : ''; ?>" data-status="expired" draggable="false">
					<div class="iftp-stat-card-header iftp-stat-label--expired"><span class="iftp-stat-card-icon" aria-hidden="true"><svg width="13" height="13" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M5 19h10M5 1h10M15 1v4l-5 5-5-5V1M5 19v-4l5-5 5 5v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
							</svg></span><span class="iftp-stat-card-label"><?php esc_html_e('Expired', 'ifthenpay-payments-for-contactform7'); ?></span></div>
					<div class="iftp-stat-card-val iftp-stat-val--expired"><?php echo esc_html((string) ($counts['expired'] ?? 0)); ?></div>
					<div class="iftp-stat-card-sub"><?php echo esc_html($_period_label); ?></div>
					<div class="iftp-rev-bar">
						<?php if (($counts['expired'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['expired']; ?>;background:#2e414e"></div><?php endif; ?>
					</div>
					<span class="iftp-stat-card-ghost" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M5 19h10M5 1h10M15 1v4l-5 5-5-5V1M5 19v-4l5-5 5 5v4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
						</svg></span>
				</a>
			</div>

			<?php /* Mobile stats summary card — hidden on tablet/desktop via CSS */ ?>
			<div class="iftp-stats-row-mobile">
				<div class="iftp-mobile-rev-card iftp-stat-card--<?php echo esc_attr($revenue_css); ?>">
					<div class="iftp-mobile-rev-card__meta">
						<span class="iftp-mobile-rev-card__label"><span class="iftp-stat-card-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><polyline points="2,15 7,9 11,12 17,5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /><polyline points="13,5 17,5 17,9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg></span><?php echo esc_html($rev_label); ?></span>
					</div>
					<div class="iftp-mobile-rev-card__amount"><?php echo esc_html(number_format($sidebar_revenue, 2, ',', '.') . ' €'); ?></div>
					<div class="iftp-rev-bar iftp-mobile-rev-card__bar<?php echo $rev_bar_mode === 'solid' ? ' iftp-rev-bar--solid' : ''; ?>">
						<div class="iftp-rev-seg iftp-rev-seg--solid" style="flex:1;background:<?php echo esc_attr($bar_solid_color); ?>"></div>
						<?php if (($counts['completed'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['completed']; ?>;background:#34a853"></div><?php endif; ?>
						<?php if (($counts['pending']   ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['pending'];   ?>;background:#d69300"></div><?php endif; ?>
						<?php if (($counts['failed']    ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['failed'];    ?>;background:#ea4335"></div><?php endif; ?>
						<?php if (($counts['cancelled'] ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['cancelled']; ?>;background:#718096"></div><?php endif; ?>
						<?php if (($counts['expired']   ?? 0) > 0) : ?><div class="iftp-rev-seg" style="flex:<?php echo (int) $counts['expired'];   ?>;background:#2e414e"></div><?php endif; ?>
					</div>
					<span class="iftp-stat-card-ghost iftp-stat-card-ghost--revenue" aria-hidden="true"><svg width="64" height="64" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><polyline points="2,15 7,9 11,12 17,5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /><polyline points="13,5 17,5 17,9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg></span>
				</div>
			</div>

			<?php /* ── Main table ── */ ?>
			<div class="iftp-cf7-entries-main">

				<?php /* Filter tabs — inside panel, centred on table width */ ?>
				<div class="iftp-filter-tabs-bar" role="tablist" aria-label="<?php esc_attr_e('Filter by status', 'ifthenpay-payments-for-contactform7'); ?>">
					<?php
					$tabs = array(
						''          => __('All', 'ifthenpay-payments-for-contactform7'),
						'completed' => __('Paid', 'ifthenpay-payments-for-contactform7'),
						'pending'   => __('Pending', 'ifthenpay-payments-for-contactform7'),
						'failed'    => __('Failed', 'ifthenpay-payments-for-contactform7'),
						'cancelled' => __('Cancelled', 'ifthenpay-payments-for-contactform7'),
						'expired'   => __('Expired', 'ifthenpay-payments-for-contactform7'),
					);
					$tab_slug = array(
						''          => 'all',
						'completed' => 'paid',
						'pending'   => 'pending',
						'failed'    => 'failed',
						'cancelled' => 'cancelled',
						'expired'   => 'expired',
					);
					foreach ($tabs as $key => $label) :
						$tab_args = array(
							'page'   => 'ifthenpay-cf7-entries',
							'status' => $key,
							'period' => $period,
						);
						if ($form_id > 0) {
							$tab_args['form_id'] = $form_id;
						}
						$url     = add_query_arg($tab_args, admin_url('admin.php'));
						$is_curr = $current_tab === $key;
						$cnt     = (int) ($counts[$key] ?? 0);
						$slug    = $tab_slug[$key];
					?>
						<a href="<?php echo esc_url($url); ?>"
							class="iftp-tab iftp-tab--<?php echo esc_attr($slug); ?><?php echo $is_curr ? ' active' : ''; ?>"
							role="tab"
							aria-selected="<?php echo $is_curr ? 'true' : 'false'; ?>">
							<?php echo esc_html($label); ?>
							<span class="iftp-tab-count"><?php echo esc_html((string) $cnt); ?></span>
						</a>
					<?php endforeach; ?>
					<?php if (! empty($entries)) : ?>
						<div class="iftp-tabs-bulk-actions">
							<select name="action" form="iftp-bulk-form" aria-label="<?php esc_attr_e('Bulk Actions', 'ifthenpay-payments-for-contactform7'); ?>">
								<option value="-1"><?php esc_html_e('Bulk Actions', 'ifthenpay-payments-for-contactform7'); ?></option>
								<option value="mark_paid"><?php esc_html_e('Mark as Paid', 'ifthenpay-payments-for-contactform7'); ?></option>
								<option value="mark_cancelled"><?php esc_html_e('Mark as Cancelled', 'ifthenpay-payments-for-contactform7'); ?></option>
								<option value="mark_failed"><?php esc_html_e('Mark as Failed', 'ifthenpay-payments-for-contactform7'); ?></option>
								<option value="mark_pending"><?php esc_html_e('Mark as Pending', 'ifthenpay-payments-for-contactform7'); ?></option>
								<option value="mark_expired"><?php esc_html_e('Mark as Expired', 'ifthenpay-payments-for-contactform7'); ?></option>
								<option value="delete"><?php esc_html_e('Delete', 'ifthenpay-payments-for-contactform7'); ?></option>
							</select>
							<input type="submit" form="iftp-bulk-form" class="iftp-action-btn" value="<?php esc_attr_e('Apply', 'ifthenpay-payments-for-contactform7'); ?>" />
						</div>
					<?php endif; ?>
				</div>

				<?php $this->render_tablenav_top($per_page, $current_tab, $search_field, $search_op, $search_query, $period, $form_id, $forms, ! empty($entries)); ?>

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

						<!-- Column visibility CSS is injected via wp_add_inline_style() in Plugin::enqueue_admin_assets(). -->
						<!-- admin-entries.js reads data-hidden-cols on the table wrapper as a fallback. -->
						<div class="iftp-entries-table-wrap iftp-density-compact"
							data-col-labels="<?php echo esc_attr($col_labels_json); ?>"
							data-col-order="<?php echo esc_attr((string) wp_json_encode($ordered_cols)); ?>"
							data-hidden-cols="<?php echo esc_attr((string) wp_json_encode($hidden_cols)); ?>">
							<table class="wp-list-table widefat fixed striped iftp-cf7-entries-table">
								<thead>
									<tr>
										<td class="manage-column column-cb check-column">
											<span class="iftp-cb-wrap"><input id="cb-select-all" type="checkbox" autocomplete="off" /><span class="iftp-checkmark" aria-hidden="true"><svg viewBox="0 0 10 10" fill="none"><polyline class="iftp-check-path" points="1.5,5.5 4,8 8.5,2.5"/></svg></span></span>
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
										'cash'      => '#0f6b2f',
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
												| <span class="trash"><a href="<?php echo esc_url($cur_del_url); ?>" class="submitdelete iftp-confirm-link"
														data-iftp-confirm="<?php esc_attr_e('Move this entry to trash?', 'ifthenpay-payments-for-contactform7'); ?>"
														data-iftp-confirm-title="<?php esc_attr_e('Move to Trash', 'ifthenpay-payments-for-contactform7'); ?>"><?php esc_html_e('Trash', 'ifthenpay-payments-for-contactform7'); ?></a></span>
											</div>
										</td>
									<?php
										},
										'customer_name' => function (EntryDto $e): void {
									?>
										<td class="column-customer" data-col="customer_name">
											<strong><?php echo esc_html($e->customer_name ?: '—'); ?></strong>
											<?php if ($e->customer_email !== '') : ?>
												<a href="mailto:<?php echo esc_attr($e->customer_email); ?>" class="iftp-list-email"><?php echo esc_html($e->customer_email); ?></a>
											<?php endif; ?>
										</td>
									<?php
										},
										'request_id' => function (EntryDto $e): void {
											echo '<td class="column-request" data-col="request_id">';
											echo $e->request_id ? '<p title="' . esc_html($e->request_id) . '">' . esc_html($e->request_id) . '</p>' : '—';
											echo '</td>';
										},
										'form_title' => function (EntryDto $e): void {
											echo '<td class="column-form" data-col="form_title">' . esc_html($e->form_title ?: 'Form #' . $e->form_id) . '</td>';
										},
										'payment_method' => function (EntryDto $e) use ($get_logo, $method_colors): void {
											$key       = preg_replace('/[^a-z0-9]/', '', strtolower($e->payment_method));
											$dot_color = $method_colors[$key] ?? '#8c8f94';
											$logo_url  = $get_logo($e->payment_method);
											$is_cash   = $key === 'cash';
									?>
										<td class="column-method" data-col="payment_method">
											<?php if ($e->payment_method !== '') : ?>
												<span class="iftp-method-pill">
													<?php if ($logo_url !== '') : ?>
														<img class="iftp-method-logo-img" src="<?php echo esc_url($logo_url); ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block'" />
														<span class="iftp-method-dot" style="background:<?php echo esc_attr($dot_color); ?>;display:none"></span>
													<?php elseif ($is_cash) : ?>
														<span class="iftp-method-icon-emoji" aria-hidden="true">💶</span>
													<?php else : ?>
														<span class="iftp-method-dot" style="background:<?php echo esc_attr($dot_color); ?>"></span>
													<?php endif; ?>
													<span class="iftp-pill-text"><?php echo esc_html($e->payment_method); ?></span>
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
											echo '<td class="column-date" data-col="created_at">' . esc_html($e->created_at) . '</td>';
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
										<tr>
											<th class="check-column">
												<span class="iftp-cb-wrap"><input type="checkbox" name="entry_ids[]" value="<?php echo esc_attr((string) $entry->id); ?>" autocomplete="off" /><span class="iftp-checkmark" aria-hidden="true"><svg viewBox="0 0 10 10" fill="none"><polyline class="iftp-check-path" points="1.5,5.5 4,8 8.5,2.5"/></svg></span></span>
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
										<td class="manage-column column-cb check-column"><span class="iftp-cb-wrap"><input id="cb-select-all-2" type="checkbox" autocomplete="off" /><span class="iftp-checkmark" aria-hidden="true"><svg viewBox="0 0 10 10" fill="none"><polyline class="iftp-check-path" points="1.5,5.5 4,8 8.5,2.5"/></svg></span></span></td>
										<?php foreach ($ordered_cols as $col_key) : ?>
											<?php $this->render_col_th($col_key, $col_defs[$col_key], $sort); ?>
										<?php endforeach; ?>
									</tr>
								</tfoot>
							</table>
						</div><!-- .iftp-entries-table-wrap -->

						<?php $this->render_tablenav_bottom($current_page, $has_prev, $has_next, $prev_url, $next_url, $first_page_url, $total_count, $first_entry, $last_entry, $per_page); ?>
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
						<p class="iftp-col-customize-hint"><?php esc_html_e('Drag to reorder · check to show/hide. ID and Customer are always shown.', 'ifthenpay-payments-for-contactform7'); ?></p>
						<ul class="iftp-col-list" id="iftp-col-list" role="listbox" aria-label="<?php esc_attr_e('Column order', 'ifthenpay-payments-for-contactform7'); ?>">
							<?php foreach ($ordered_cols as $col_key) : ?>
								<?php if (! empty($col_defs[$col_key]['fixed'])) continue; ?>
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
									<span class="iftp-cb-wrap"><input type="checkbox"
										class="iftp-col-visibility-cb"
										id="iftp-colvis-<?php echo esc_attr($col_key); ?>"
										<?php checked(isset($visible_set[$col_key])); ?> /><span class="iftp-checkmark" aria-hidden="true"><svg viewBox="0 0 10 10" fill="none"><polyline class="iftp-check-path" points="1.5,5.5 4,8 8.5,2.5"/></svg></span></span>
									<label for="iftp-colvis-<?php echo esc_attr($col_key); ?>" class="iftp-col-item-label">
										<?php echo esc_html($col_defs[$col_key]['label']); ?>
									</label>
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

			<?php /* Info box at bottom — hidden once user dismisses */ ?>
			<?php if (! get_user_meta(get_current_user_id(), 'iftp_cf7_info_box_dismissed', true)) : ?>
				<div class="iftp-info-box" id="iftp-info-box">
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
					<button type="button" class="iftp-info-box-dismiss" id="iftp-info-box-dismiss"
						aria-label="<?php esc_attr_e('Dismiss', 'ifthenpay-payments-for-contactform7'); ?>"
						data-nonce="<?php echo esc_attr(wp_create_nonce('iftp_cf7_dismiss_info_box')); ?>">
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<line x1="18" y1="6" x2="6" y2="18" />
							<line x1="6" y1="6" x2="18" y2="18" />
						</svg>
					</button>
				</div>
			<?php endif; ?>

			<button type="button" id="iftp-scroll-btn" class="iftp-scroll-btn" data-per-page="<?php echo esc_attr((string) $per_page); ?>" aria-label="<?php esc_attr_e('Scroll to top', 'ifthenpay-payments-for-contactform7'); ?>">
				<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<line x1="12" y1="19" x2="12" y2="6" />
					<polyline points="6 12 12 6 18 12" />
				</svg>
			</button>
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
						<div class="iftp-modal-section-label"><?php esc_html_e('Customer Data', 'ifthenpay-payments-for-contactform7'); ?></div>
						<div class="iftp-modal-error" style="display:none;"></div>

						<!-- Simple mode -->
						<div class="iftp-mode-panel iftp-mode-panel--simple">
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_customer_name"><?php esc_html_e('Customer Name', 'ifthenpay-payments-for-contactform7'); ?></label>
									<input type="text" id="ap_customer_name" placeholder="e.g.: John Smith" name="ap_customer_name" class="regular-text" maxlength="255" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_customer_email"><?php esc_html_e('E-mail', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="email" id="ap_customer_email" placeholder="e.g.: john.smith@gmail.com" name="ap_customer_email" class="regular-text" maxlength="100" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_amount"><?php esc_html_e('Amount (€)', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-required">*</span></label>
									<input type="number" id="ap_amount" placeholder="e.g.: 10000,00" name="ap_amount" step="0.01" min="0.01" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_payment_status"><?php esc_html_e('Status', 'ifthenpay-payments-for-contactform7'); ?></label>
									<select id="ap_payment_status" name="ap_payment_status">
										<option value="completed"><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="pending"><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="cancelled"><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="failed"><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="expired"><?php esc_html_e('Expired', 'ifthenpay-payments-for-contactform7'); ?></option>
									</select>
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_payment_method"><?php esc_html_e('Payment Method', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<select id="ap_payment_method" name="ap_payment_method">
										<option value=""><?php esc_html_e('— Select method —', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="MBWAY">MBWAY</option>
										<option value="MULTIBANCO">MULTIBANCO</option>
										<option value="CARD">CARD</option>
										<option value="PAYSHOP">PAYSHOP</option>
										<option value="PIX">PIX</option>
										<option value="COFIDIS">COFIDIS</option>
										<option value="APPLE">APPLE</option>
										<option value="GOOGLE">GOOGLE</option>
										<option value="CASH">CASH</option>
									</select>
								</div>
								<div class="iftp-modal-field">
									<label for="ap_form_title"><?php esc_html_e('Form / Reference', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_form_title" placeholder="(can be fictional)  e.g.: Real Life Payment" name="ap_form_title" class="regular-text" maxlength="255" />
								</div>
							</div>
						</div><!-- .iftp-mode-panel--simple -->

						<!-- Complex mode -->
						<div class="iftp-mode-panel iftp-mode-panel--complex" style="display:none">
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_cx_amount"><?php esc_html_e('Amount (€)', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-required">*</span></label>
									<input type="number" id="ap_cx_amount" placeholder="e.g.: 10000,00" name="ap_cx_amount" step="0.01" min="0.01" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_cx_payment_status"><?php esc_html_e('Status', 'ifthenpay-payments-for-contactform7'); ?></label>
									<select id="ap_cx_payment_status" name="ap_cx_payment_status">
										<option value="completed"><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="pending"><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="cancelled"><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="failed"><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="expired"><?php esc_html_e('Expired', 'ifthenpay-payments-for-contactform7'); ?></option>
									</select>
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_cx_payment_method"><?php esc_html_e('Payment Method', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<select id="ap_cx_payment_method" name="ap_cx_payment_method">
										<option value=""><?php esc_html_e('— Select method —', 'ifthenpay-payments-for-contactform7'); ?></option>
										<option value="MBWAY">MBWAY</option>
										<option value="MULTIBANCO">MULTIBANCO</option>
										<option value="CARD">CARD</option>
										<option value="PAYSHOP">PAYSHOP</option>
										<option value="PIX">PIX</option>
										<option value="COFIDIS">COFIDIS</option>
										<option value="APPLE">APPLE</option>
										<option value="GOOGLE">GOOGLE</option>
										<option value="CASH">CASH</option>
									</select>
								</div>
								<div class="iftp-modal-field">
									<label for="ap_cx_form_title"><?php esc_html_e('Form / Reference', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_cx_form_title" placeholder="(can be fictional)  e.g.: Real Life Payment" name="ap_cx_form_title" class="regular-text" maxlength="255" />
								</div>
							</div>

							<div class="iftp-modal-section-label"><?php esc_html_e('Submitted Data', 'ifthenpay-payments-for-contactform7'); ?></div>

							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_sd_name"><?php esc_html_e('Name', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_name" placeholder="e.g.: John Smith" name="ap_sd_name" class="regular-text" maxlength="255" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_sd_email"><?php esc_html_e('Email', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="email" id="ap_sd_email" placeholder="e.g.: john.smith@gmail.com" name="ap_sd_email" class="regular-text" maxlength="100" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field">
									<label for="ap_sd_morada"><?php esc_html_e('Address', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_morada" placeholder="e.g.: 123 Main Street" name="ap_sd_morada" class="regular-text" maxlength="255" />
								</div>
								<div class="iftp-modal-field">
									<label for="ap_sd_codigo_postal"><?php esc_html_e('Postal Code', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_codigo_postal" placeholder="e.g.: 10001" name="ap_sd_codigo_postal" class="regular-text" maxlength="20" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field iftp-modal-field--full">
									<label for="ap_sd_telemovel"><?php esc_html_e('Mobile Phone', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<input type="text" id="ap_sd_telemovel" placeholder="e.g.: 987654321" name="ap_sd_telemovel" class="regular-text" maxlength="45" />
								</div>
							</div>
							<div class="iftp-modal-row">
								<div class="iftp-modal-field iftp-modal-field--full">
									<label for="ap_sd_mensagem"><?php esc_html_e('Message', 'ifthenpay-payments-for-contactform7'); ?> <span class="iftp-optional"><?php esc_html_e('(optional)', 'ifthenpay-payments-for-contactform7'); ?></span></label>
									<textarea id="ap_sd_mensagem" name="ap_sd_mensagem" placeholder="e.g.: Hello World..." class="large-text" rows="3" maxlength="1000"></textarea>
								</div>
							</div>

						</div><!-- .iftp-mode-panel--complex -->

					</div><!-- .iftp-modal-body -->
					<div class="iftp-modal-foot">
						<button type="button" class="button iftp-modal-cancel" style="height:36px;line-height:34px;border-radius:6px;font-size:13px;padding:0 16px;background:#fff;border:1px solid #c3c4c7;color:#50575e;cursor:pointer;box-sizing:border-box;min-height:0"><?php esc_html_e('Cancel', 'ifthenpay-payments-for-contactform7'); ?></button>
						<button type="submit" class="button button-primary iftp-modal-submit"><?php esc_html_e('Add Payment', 'ifthenpay-payments-for-contactform7'); ?></button>
					</div>
				</form>
			</div>
			<?php if (! get_user_meta(get_current_user_id(), 'iftp_cf7_add_payment_notice_dismissed', true)) : ?>
				<div class="iftp-ap-toast" id="iftp-ap-toast">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="12" cy="12" r="10" />
						<line x1="12" y1="8" x2="12" y2="12" />
						<line x1="12" y1="16" x2="12.01" y2="16" />
					</svg>
					<p><?php esc_html_e('Adding payments here is optional — you are not required to do so. This simply lets you manually record real-world payments in your list for organizational purposes.', 'ifthenpay-payments-for-contactform7'); ?></p>
					<button type="button" class="iftp-ap-toast-close" aria-label="<?php esc_attr_e('Dismiss', 'ifthenpay-payments-for-contactform7'); ?>">&#215;</button>
				</div>
			<?php endif; ?>
		</div>

		<!-- Confirm action modal -->
		<div id="iftp-confirm-modal" class="iftp-modal iftp-modal--sm" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="iftp-confirm-title">
			<div class="iftp-modal-overlay"></div>
			<div class="iftp-modal-box">
				<div class="iftp-modal-head">
					<h2 id="iftp-confirm-title">
						<span id="iftp-confirm-heading"></span>
					</h2>
					<button type="button" class="iftp-modal-close iftp-confirm-close" aria-label="<?php esc_attr_e('Close', 'ifthenpay-payments-for-contactform7'); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<line x1="18" y1="6" x2="6" y2="18" />
							<line x1="6" y1="6" x2="18" y2="18" />
						</svg>
					</button>
				</div>
				<div class="iftp-confirm-body">
					<p id="iftp-confirm-message"></p>
				</div>
				<div class="iftp-modal-foot">
					<button type="button" class="button iftp-confirm-cancel"><?php esc_html_e('No', 'ifthenpay-payments-for-contactform7'); ?></button>
					<a href="#" id="iftp-confirm-yes" class="button button-primary iftp-confirm-yes-btn"><?php esc_html_e('Yes', 'ifthenpay-payments-for-contactform7'); ?></a>
				</div>
			</div>
		</div>

	<!-- Checkbox persistence, select-all, and page-jump are handled by admin-entries.js (enqueued via wp_enqueue_script). -->
	<?php
	}

	/**
	 * Build the form-filter dropdown list from live CF7 posts + orphaned form IDs.
	 *
	 * Uses get_posts() on wpcf7_contact_form (fast — only ~N posts, not 655k rows).
	 * Orphaned form_ids (entries whose CF7 form was deleted) come from a pure
	 * covering-index scan via get_entry_form_ids() — no heap reads.
	 *
	 * @return array<int, array{form_id: int, form_title: string}>
	 */
	private function get_cf7_forms_for_dropdown(EntryRepository $repo): array
	{
		$wp_forms = array();
		if (post_type_exists('wpcf7_contact_form')) {
			$posts = get_posts(array(
				'post_type'              => 'wpcf7_contact_form',
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'post_status'            => 'publish',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			));
			foreach ($posts as $post) {
				$wp_forms[$post->ID] = $post->post_title;
			}
		}

		$forms = array();
		foreach ($wp_forms as $id => $title) {
			$forms[] = array('form_id' => $id, 'form_title' => $title);
		}

		foreach ($repo->get_entry_form_ids() as $form_id) {
			if (! isset($wp_forms[$form_id])) {
				$forms[] = array(
					'form_id'    => $form_id,
					/* translators: %d: the ID of a deleted CF7 form */
					'form_title' => sprintf(__('Deleted Form #%d', 'ifthenpay-payments-for-contactform7'), $form_id),
				);
			}
		}

		return $forms;
	}

	private function render_tablenav_top(
		int $per_page = 20,
		string $current_tab = '',
		string $search_field = 'customer_name',
		string $search_op = 'contains',
		string $search_query = '',
		string $period = 'all',
		int $form_id = 0,
		array $forms = array(),
		bool $has_entries = true
	): void {
		$fields = array(
			'customer_name'  => __('Name', 'ifthenpay-payments-for-contactform7'),
			'customer_email' => __('Email', 'ifthenpay-payments-for-contactform7'),
			'form_title'     => __('Form', 'ifthenpay-payments-for-contactform7'),
			'payment_method' => __('Method', 'ifthenpay-payments-for-contactform7'),
			'amount'         => __('Amount', 'ifthenpay-payments-for-contactform7'),
			'request_id'     => __('Request ID', 'ifthenpay-payments-for-contactform7'),
		);
		$clear_args  = array('page' => 'ifthenpay-cf7-entries', 'status' => $current_tab, 'period' => $period);
		if ($form_id > 0) {
			$clear_args['form_id'] = 0;
		}
		$clear_url   = add_query_arg($clear_args, admin_url('admin.php'));
		$has_filters = $search_query !== '' || $form_id > 0;
	?>
		<div class="tablenav top iftp-tablenav-top">
			<form method="get" id="iftp-search-form" class="iftp-tablenav-middle">
				<input type="hidden" name="page" value="ifthenpay-cf7-entries" />
				<?php if ($current_tab !== '') : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr($current_tab); ?>" />
				<?php endif; ?>
				<?php if ($period !== 'all') : ?>
					<input type="hidden" name="period" value="<?php echo esc_attr($period); ?>" />
				<?php endif; ?>
				<?php if (! empty($forms)) : ?>
					<select name="form_id" id="iftp-form-filter" aria-label="<?php esc_attr_e('Filter by form', 'ifthenpay-payments-for-contactform7'); ?>" onchange="this.form.submit()">
						<option value="0"><?php esc_html_e('All Forms', 'ifthenpay-payments-for-contactform7'); ?></option>
						<?php foreach ($forms as $f) : ?>
							<option value="<?php echo esc_attr((string) $f['form_id']); ?>" <?php selected($form_id, $f['form_id']); ?>><?php echo esc_html($f['form_title']); ?></option>
						<?php endforeach; ?>
					</select>
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
				<input type="text" name="search_query" value="<?php echo esc_attr($search_query); ?>"
					class="iftp-search-input" placeholder="<?php esc_attr_e('Search…', 'ifthenpay-payments-for-contactform7'); ?>" />
				<button type="submit" class="iftp-action-btn iftp-action-btn--icon" aria-label="<?php esc_attr_e('Search', 'ifthenpay-payments-for-contactform7'); ?>"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<circle cx="11" cy="11" r="8" />
						<line x1="21" y1="21" x2="16.65" y2="16.65" />
					</svg></button>
				<?php if ($has_filters) : ?>
					<a href="<?php echo esc_url($clear_url); ?>" class="iftp-action-btn iftp-action-btn--ghost"><?php esc_html_e('Clear', 'ifthenpay-payments-for-contactform7'); ?></a>
				<?php endif; ?>
			</form>

			<?php if ($has_entries) : ?>
				<div class="iftp-tablenav-right">
					<label for="iftp-per-page" class="screen-reader-text"><?php esc_html_e('Items per page', 'ifthenpay-payments-for-contactform7'); ?></label>
					<select id="iftp-per-page" class="iftp-per-page-select" data-current="<?php echo esc_attr((string) $per_page); ?>">
						<?php foreach (self::VALID_PER_PAGE as $opt) : ?>
							<option value="<?php echo esc_attr((string) $opt); ?>" <?php selected($per_page, $opt); ?>>Page/<?php echo esc_html((string) $opt); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="iftp-action-btn iftp-col-customize-btn" id="iftp-col-customize-btn"
						aria-haspopup="true" aria-expanded="false" aria-controls="iftp-col-customize-popover">
						<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
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
			<?php endif; ?>
		</div>
	<?php
	}

	private function render_tablenav_bottom(int $current_page, bool $has_prev, bool $has_next, string $prev_url, string $next_url, string $first_page_url = '', int $total_count = 0, int $first_entry = 0, int $last_entry = 0, int $per_page = 20): void
	{
		$total_pages = ($per_page > 0 && $total_count > 0) ? max(1, (int) ceil($total_count / $per_page)) : 0;
	?>
		<div class="tablenav bottom">
			<span class="displaying-num" data-total="<?php echo esc_attr((string) $total_count); ?>">
				<?php if ($total_count > 0 && $first_entry > 0) : ?>
					<?php echo esc_html($first_entry . ' - ' . $last_entry . ' out of ' . $total_count); ?>
				<?php endif; ?>
			</span>
			<?php $this->pagination($current_page, $has_prev, $has_next, $prev_url, $next_url, $first_page_url, $total_pages); ?>
			<br class="clear" />
		</div>
	<?php
	}

	private function pagination(int $current_page, bool $has_prev, bool $has_next, string $prev_url, string $next_url, string $first_page_url = '', int $total_pages = 0): void
	{
		if (! $has_prev && ! $has_next && $first_page_url === '') {
			return;
		}


		$window_start = max(1, $current_page - 1);
		$window_end   = $total_pages > 0 ? min($current_page + 2, $total_pages) : ($has_next ? $current_page + 2 : $current_page);


		if ($total_pages > 0 && ($window_end - $window_start) < 3) {
			$window_end = min($window_start + 3, $total_pages);
			if (($window_end - $window_start) < 3) {
				$window_start = max(1, $window_end - 3);
			}
		}


		$show_page_one       = $window_start > 1;

		$show_left_ellipsis  = $window_start > 2;

		$show_last           = $total_pages > 0 && $window_end < $total_pages;

		$show_right_ellipsis = $show_last && ($window_end < $total_pages - 1);

		$page_url = static function (int $n) use ($first_page_url): string {
			if ($n <= 1) {
				return $first_page_url;
			}
			return add_query_arg(array('paged' => $n), $first_page_url);
		};
	?>
		<span class="pagination-links iftp-pagination-links" role="navigation" aria-label="<?php esc_attr_e('Pagination', 'ifthenpay-payments-for-contactform7'); ?>">

			<?php if ($has_prev) : ?>
				<a class="iftp-pag-btn iftp-pag-nav" href="<?php echo esc_url($prev_url); ?>" aria-label="<?php esc_attr_e('Previous page', 'ifthenpay-payments-for-contactform7'); ?>">
					<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="15 18 9 12 15 6" />
					</svg>
				</a>
			<?php else : ?>
				<span class="iftp-pag-btn iftp-pag-nav disabled" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="15 18 9 12 15 6" />
					</svg>
				</span>
			<?php endif; ?>

			<span class="iftp-pag-pages">
				<?php if ($show_page_one) : ?>
					<a class="iftp-pag-page" href="<?php echo esc_url($page_url(1)); ?>" aria-label="<?php esc_attr_e('Page 1', 'ifthenpay-payments-for-contactform7'); ?>">1</a>
				<?php endif; ?>

				<?php if ($show_left_ellipsis) : ?>
					<span class="iftp-pag-ellipsis" aria-hidden="true" style="color:#718096;padding:0 2px;">...</span>
				<?php endif; ?>

				<?php for ($p = $window_start; $p <= $window_end; $p++) : ?>
					<?php if ($p === $current_page) : ?>
						<span class="iftp-pag-page iftp-pag-page-active" aria-current="page"><?php echo esc_html((string) $p); ?></span>
					<?php else : ?>
						<a class="iftp-pag-page" href="<?php echo esc_url($page_url($p)); ?>"
						aria-label="<?php /* translators: %d: page number */ echo esc_attr(sprintf(__('Page %d', 'ifthenpay-payments-for-contactform7'), $p)); ?>"><?php echo esc_html((string) $p); ?></a>
					<?php endif; ?>
				<?php endfor; ?>

				<?php if ($show_right_ellipsis) : ?>
					<input
						type="text"
						inputmode="numeric"
						pattern="[0-9]*"
						class="iftp-pag-input"
						data-total="<?php echo esc_attr((string) $total_pages); ?>"
						placeholder="..."
						data-base-url="<?php echo esc_url($first_page_url); ?>"
						title="<?php esc_attr_e('Type a page number and press Enter', 'ifthenpay-payments-for-contactform7'); ?>"
						autocomplete="off" />
				<?php endif; ?>

				<?php if ($show_last) : ?>
					<a class="iftp-pag-page" href="<?php echo esc_url($page_url($total_pages)); ?>"
						aria-label="<?php /* translators: %d: page number */ echo esc_attr(sprintf(__('Page %d', 'ifthenpay-payments-for-contactform7'), $total_pages)); ?>"><?php echo esc_html((string) $total_pages); ?></a>
				<?php endif; ?>
			</span>

			<?php if ($has_next) : ?>
				<a class="iftp-pag-btn iftp-pag-nav" href="<?php echo esc_url($next_url); ?>" aria-label="<?php esc_attr_e('Next page', 'ifthenpay-payments-for-contactform7'); ?>">
					<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="9 18 15 12 9 6" />
					</svg>
				</a>
			<?php else : ?>
				<span class="iftp-pag-btn iftp-pag-nav disabled" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="9 18 15 12 9 6" />
					</svg>
				</span>
			<?php endif; ?>

		</span>
	<?php
	}

	private function format_field_label(string $key): string
	{

		$label = preg_replace('/^(your[-_])/i', '', $key) ?? $key;

		$label = str_replace(array('-', '_'), ' ', $label);


		static $translations = array(
			'nome'           => 'Name',
			'primeiro nome'  => 'First Name',
			'ultimo nome'    => 'Last Name',
			'apelido'        => 'Last Name',
			'morada'         => 'Address',
			'rua'            => 'Street',
			'cidade'         => 'City',
			'localidade'     => 'City',
			'distrito'       => 'District',
			'codigo postal'  => 'Postal Code',
			'cp'             => 'Postal Code',
			'pais'           => 'Country',
			'telemovel'      => 'Mobile Phone',
			'telefone'       => 'Phone',
			'telefone fixo'  => 'Phone',
			'nif'            => 'Tax ID',
			'nib'            => 'Bank Account',
			'iban'           => 'IBAN',
			'mensagem'       => 'Message',
			'assunto'        => 'Subject',
			'empresa'        => 'Company',
			'descricao'      => 'Description',
			'observacoes'    => 'Notes',
			'notas'          => 'Notes',
			'comentarios'    => 'Comments',
			'data'           => 'Date',
			'hora'           => 'Time',
			'quantidade'     => 'Quantity',
			'valor'          => 'Amount',
			'preco'          => 'Price',
			'genero'         => 'Gender',
			'sexo'           => 'Gender',
			'nascimento'     => 'Date of Birth',
			'website'        => 'Website',
			'site'           => 'Website',
		);

		$lower = strtolower(trim($label));
		if (isset($translations[$lower])) {
			return $translations[$lower];
		}

		return ucwords($label);
	}

	/** @return array<string, array{label: string, css: string, sortable: bool, db_col?: string, default_dir?: string}> */
	private function get_col_defs(): array
	{
		return array(
			'id'             => array('label' => __('ID', 'ifthenpay-payments-for-contactform7'),           'css' => 'column-id',           'sortable' => true,  'db_col' => 'id',             'default_dir' => 'desc', 'fixed' => true),
			'customer_name'  => array('label' => __('Customer', 'ifthenpay-payments-for-contactform7'),      'css' => 'column-customer',     'sortable' => true,  'db_col' => 'customer_name',  'default_dir' => 'asc',  'fixed' => true),
			'request_id'     => array('label' => __('Request ID', 'ifthenpay-payments-for-contactform7'),    'css' => 'column-request',      'sortable' => false),
			'amount'         => array('label' => __('Amount', 'ifthenpay-payments-for-contactform7'),        'css' => 'column-amount',       'sortable' => true,  'db_col' => 'amount',         'default_dir' => 'desc'),
			'form_title'     => array('label' => __('Form', 'ifthenpay-payments-for-contactform7'),          'css' => 'column-form',         'sortable' => true,  'db_col' => 'form_title',     'default_dir' => 'asc'),
			'payment_status' => array('label' => __('Status', 'ifthenpay-payments-for-contactform7'),        'css' => 'column-status',       'sortable' => true,  'db_col' => 'payment_status', 'default_dir' => 'asc'),
			'payment_method' => array('label' => __('Method', 'ifthenpay-payments-for-contactform7'),        'css' => 'column-method',       'sortable' => true,  'db_col' => 'payment_method', 'default_dir' => 'asc'),
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
			if (! empty($sort['form_id'])) {
				$args['form_id'] = (int) $sort['form_id'];
			}
			if ($sort['search_query'] !== '') {
				$args['search_field'] = $sort['search_field'];
				$args['search_op']    = $sort['search_op'];
				$args['search_query'] = $sort['search_query'];
			}
			$up_class   = ($is_active && $sort['order'] === 'asc')  ? 'iftp-sort-arrow iftp-sort-up iftp-sort-active' : 'iftp-sort-arrow iftp-sort-up';
			$down_class = ($is_active && $sort['order'] === 'desc') ? 'iftp-sort-arrow iftp-sort-down iftp-sort-active' : 'iftp-sort-arrow iftp-sort-down';
			printf(
				'<th class="%s" data-col="%s"><a href="%s"><span>%s</span><span class="iftp-sort-arrows" aria-hidden="true"><span class="%s">&#x2191;</span><span class="%s">&#x2193;</span></span></a></th>',
				esc_attr($th_class),
				esc_attr($col_key),
				esc_url(add_query_arg($args, admin_url('admin.php'))),
				esc_html($col_def['label']),
				esc_attr($up_class),
				esc_attr($down_class)
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
			'ccard'         => '#dba617',
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
				$ent                          = strtoupper((string) $m['entity']);
				$logo_v                       = (string) $m['logo'];
				$detail_logos_exact[$ent]     = $logo_v;
				$ent_key                      = preg_replace('/[^A-Z0-9]/', '', $ent);
				$detail_logos_alt[$ent_key]   = $logo_v;
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


		$hero_status_styles = array(
			'completed' => array('bg' => '#e6f4ea', 'color' => '#137333', 'dot' => '#34a853'),
			'pending'   => array('bg' => '#fffae9', 'color' => '#a55a00', 'dot' => '#d69300'),
			'failed'    => array('bg' => '#fce8e6', 'color' => '#c5221f', 'dot' => '#ea4335'),
			'cancelled' => array('bg' => '#f1f3f4', 'color' => '#5f6368', 'dot' => '#9aa5b4'),
			'expired'   => array('bg' => '#f1f5f9', 'color' => '#718096', 'dot' => '#2e414e'),
		);
		$hero_style = $hero_status_styles[$entry->payment_status] ?? $hero_status_styles['pending'];

		$ghost_svgs = array(
			'completed' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="8.5"/><path d="M6.5 10.5l2.5 2.5 4.5-5"/></svg>',
			'pending'   => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="8.5"/><path d="M10 6v4l2.5 2"/></svg>',
			'failed'    => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="8.5"/><path d="M7 7l6 6M13 7l-6 6"/></svg>',
			'cancelled' => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="8.5"/><path d="M7 10h6"/></svg>',
			'expired'   => '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 19h10M5 1h10M15 1v4l-5 5-5-5V1M5 19v-4l5-5 5 5v4"/></svg>',
		);
		$ghost_color_map = array('completed' => '#34a853', 'pending' => '#d69300', 'failed' => '#ea4335', 'cancelled' => '#9aa5b4', 'expired' => '#2e414e');
		$ghost_svg   = $ghost_svgs[$entry->payment_status] ?? $ghost_svgs['pending'];
		$ghost_color = $ghost_color_map[$entry->payment_status] ?? '#9aa5b4';


		$link_done = $entry->link_generated_at !== null || $entry->payment_url !== '';
		$is_done   = in_array($entry->payment_status, array('completed', 'failed', 'cancelled', 'expired'), true);

		$step2_dot_class = $link_done ? 'iftp-step-dot--done' : 'iftp-step-dot--pending';
		$step2_lbl_muted = $link_done ? '' : ' iftp-step-lbl--muted';
		$step3_dot_class = match ($entry->payment_status) {
			'completed' => 'iftp-step-dot--done',
			'failed'    => 'iftp-step-dot--failed',
			'cancelled' => 'iftp-step-dot--cancelled',
			'expired'   => 'iftp-step-dot--expired',
			default     => 'iftp-step-dot--pending',
		};
		$step3_lbl_muted = $is_done ? '' : ' iftp-step-lbl--muted';
		$line1_class     = $link_done ? 'iftp-step-line--done' : 'iftp-step-line--pending';
		$line2_class     = match ($entry->payment_status) {
			'completed' => 'iftp-step-line--done',
			'failed'    => 'iftp-step-line--failed',
			'cancelled' => 'iftp-step-line--cancelled',
			'expired'   => 'iftp-step-line--expired',
			default     => 'iftp-step-line--pending',
		};

		$step2_time  = $entry->link_generated_at ?? ($entry->payment_url !== '' ? __('Previously', 'ifthenpay-payments-for-contactform7') : '—');
		$step3_time  = $entry->updated_at;
		$form_label  = $entry->form_title ?: 'Form #' . $entry->form_id;
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
					<div class="iftp-entry-chip">
						<img src="<?php echo esc_url(IFTP_CF7_URL . 'assets/images/ifthenpaylogo.webp'); ?>" alt="ifthenpay" class="iftp-brand-logo" draggable="false" />
						<span class="iftp-entry-chip-sep" aria-hidden="true"></span>
						<span class="iftp-entry-chip-label"><?php esc_html_e('Entry', 'ifthenpay-payments-for-contactform7'); ?> <strong>#<?php echo esc_html((string) $entry->id); ?></strong></span>
					</div>
				</div>
				<a href="<?php echo esc_url($del_url); ?>" class="iftp-delete-btn iftp-confirm-link"
					data-iftp-confirm="<?php esc_attr_e('Delete this entry permanently? This cannot be undone.', 'ifthenpay-payments-for-contactform7'); ?>"
					data-iftp-confirm-title="<?php esc_attr_e('Delete Entry', 'ifthenpay-payments-for-contactform7'); ?>"
					data-iftp-confirm-destructive="1">
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

			<!-- ── Detail grid ── -->
			<div class="iftp-detail-grid">

				<!-- Left column -->
				<div class="iftp-detail-col">

					<!-- ── Hero band ── -->
					<div class="iftp-detail-hero">
						<div class="iftp-hero-accent iftp-hero-accent--<?php echo esc_attr($entry->payment_status); ?>"></div>
						<div class="iftp-hero-ghost" style="color:<?php echo esc_attr($ghost_color); ?>"><?php echo wp_kses($ghost_svg, self::get_svg_kses()); ?></div>
						<div class="iftp-hero-inner">
							<div class="iftp-hero-amount-wrap">
								<span class="iftp-hero-eyebrow"><?php echo esc_html('Entry #' . $entry->id . ' · ' . $form_label); ?></span>
								<span class="iftp-hero-amount"><?php echo esc_html($entry->amount_formatted()); ?></span>
								<span class="iftp-hero-status-badge" style="background:<?php echo esc_attr($hero_style['bg']); ?>;color:<?php echo esc_attr($hero_style['color']); ?>">
									<span class="iftp-hero-status-dot" style="background:<?php echo esc_attr($hero_style['dot']); ?>;box-shadow:0 0 0 4px <?php echo esc_attr($hero_style['dot']); ?>29"></span>
									<?php echo esc_html($entry->status_label()); ?>
								</span>
							</div>
							<div class="iftp-hero-divider"></div>
							<div class="iftp-hero-meta">
								<div class="iftp-hero-mrow">
									<div class="iftp-hero-micon">
										<svg viewBox="0 0 24 24" aria-hidden="true">
											<rect x="1" y="4" width="22" height="16" rx="2" />
											<line x1="1" y1="10" x2="23" y2="10" />
										</svg>
									</div>
									<div>
										<div class="iftp-hero-mlbl"><?php esc_html_e('Method', 'ifthenpay-payments-for-contactform7'); ?></div>
										<div class="iftp-hero-mval">
											<?php if ($entry->payment_method !== '') : ?>
												<?php if ($detail_logo_url !== '') : ?>
													<img class="iftp-method-logo-img" src="<?php echo esc_url($detail_logo_url); ?>" alt="" style="height:16px;max-width:40px;vertical-align:middle;margin-right:4px" onerror="this.style.display='none'" />
												<?php else : ?>
													<span style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($dot_color); ?>;display:inline-block;margin-right:5px;flex-shrink:0"></span>
												<?php endif; ?>
												<?php echo esc_html($entry->payment_method); ?>
												<?php else : ?>—<?php endif; ?>
										</div>
									</div>
								</div>
								<div class="iftp-hero-mrow">
									<div class="iftp-hero-micon">
										<svg viewBox="0 0 24 24" aria-hidden="true">
											<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
											<circle cx="12" cy="7" r="4" />
										</svg>
									</div>
									<div>
										<div class="iftp-hero-mlbl"><?php esc_html_e('Customer', 'ifthenpay-payments-for-contactform7'); ?></div>
										<div class="iftp-hero-mval"><?php echo esc_html($entry->customer_name ?: '—'); ?></div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- ── Journey strip ── -->
					<div class="iftp-journey" role="list" aria-label="<?php esc_attr_e('Payment journey', 'ifthenpay-payments-for-contactform7'); ?>">
						<div class="iftp-step" role="listitem">
							<div class="iftp-step-dot iftp-step-dot--done">
								<svg viewBox="0 0 24 24" aria-hidden="true">
									<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
									<line x1="12" y1="11" x2="12" y2="17" />
									<line x1="9" y1="14" x2="15" y2="14" />
								</svg>
							</div>
							<div class="iftp-step-lbl"><?php esc_html_e('Created', 'ifthenpay-payments-for-contactform7'); ?></div>
							<div class="iftp-step-desc"><?php esc_html_e('Customer clicked to Pay', 'ifthenpay-payments-for-contactform7'); ?></div>
							<div class="iftp-step-time"><?php echo esc_html($entry->created_at); ?></div>
						</div>
						<div class="iftp-step-line <?php echo esc_attr($line1_class); ?>" aria-hidden="true"></div>
						<div class="iftp-step" role="listitem">
							<div class="iftp-step-dot <?php echo esc_attr($step2_dot_class); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true">
									<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
									<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
								</svg>
							</div>
							<div class="iftp-step-lbl<?php echo esc_attr($step2_lbl_muted); ?>"><?php esc_html_e('Link Generated', 'ifthenpay-payments-for-contactform7'); ?></div>
							<div class="iftp-step-desc<?php echo esc_attr($step2_lbl_muted); ?>"><?php esc_html_e('Payment link generated', 'ifthenpay-payments-for-contactform7'); ?></div>
							<div class="iftp-step-time"><?php echo esc_html($step2_time); ?></div>
						</div>
						<div class="iftp-step-line <?php echo esc_attr($line2_class); ?>" aria-hidden="true"></div>
						<div class="iftp-step" role="listitem">
							<div class="iftp-step-dot <?php echo esc_attr($step3_dot_class); ?>">
								<?php if ($entry->payment_status === 'completed') : ?>
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
										<polyline points="22 4 12 14.01 9 11.01" />
									</svg>
								<?php elseif ($entry->payment_status === 'failed') : ?>
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<circle cx="12" cy="12" r="10" />
										<line x1="15" y1="9" x2="9" y2="15" />
										<line x1="9" y1="9" x2="15" y2="15" />
									</svg>
								<?php elseif ($entry->payment_status === 'cancelled') : ?>
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<circle cx="12" cy="12" r="10" />
										<line x1="8" y1="12" x2="16" y2="12" />
									</svg>
								<?php elseif ($entry->payment_status === 'expired') : ?>
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<path d="M5 22h14M5 2h14M17 2v4l-5 6L7 6V2M7 22v-4l5-6 5 6v4" />
									</svg>
								<?php else : ?>
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<circle cx="12" cy="12" r="10" />
										<polyline points="12 6 12 12 16 14" />
									</svg>
								<?php endif; ?>
							</div>
							<div class="iftp-step-lbl<?php echo esc_attr($step3_lbl_muted); ?>"><?php esc_html_e('Updated', 'ifthenpay-payments-for-contactform7'); ?></div>
							<div class="iftp-step-desc<?php echo esc_attr($step3_lbl_muted); ?>"><?php esc_html_e('Payment Status Update', 'ifthenpay-payments-for-contactform7'); ?></div>
							<div class="iftp-step-time"><?php echo esc_html($step3_time); ?></div>
						</div>
					</div><!-- .iftp-journey -->

					<!-- Transaction card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<span class="iftp-card-head-ic">
								<svg viewBox="0 0 24 24" aria-hidden="true">
									<rect x="2" y="5" width="20" height="14" rx="2" />
									<line x1="2" y1="10" x2="22" y2="10" />
									<line x1="7" y1="15" x2="7.01" y2="15" />
									<line x1="11" y1="15" x2="15" y2="15" />
								</svg>
							</span>
							<h2><?php esc_html_e('Transaction', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-drows">
							<div class="iftp-drow">
								<span class="iftp-drow-lbl">
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<line x1="4" y1="9" x2="20" y2="9" />
										<line x1="4" y1="15" x2="20" y2="15" />
										<line x1="10" y1="3" x2="8" y2="21" />
										<line x1="16" y1="3" x2="14" y2="21" />
									</svg>
									<?php esc_html_e('Request ID', 'ifthenpay-payments-for-contactform7'); ?>
								</span>
								<span class="iftp-code"><?php echo esc_html($entry->request_id ?? '—'); ?></span>
							</div>
							<div class="iftp-drow">
								<span class="iftp-drow-lbl">
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
										<polyline points="15 3 21 3 21 9" />
										<line x1="10" y1="14" x2="21" y2="3" />
									</svg>
									<?php esc_html_e('Payment Link', 'ifthenpay-payments-for-contactform7'); ?>
								</span>
								<?php if ($entry->payment_url !== '') : ?>
									<a class="iftp-paybtn" href="<?php echo esc_url($entry->payment_url); ?>" target="_blank" rel="noopener noreferrer">
										<?php esc_html_e('Open link', 'ifthenpay-payments-for-contactform7'); ?>
										<svg viewBox="0 0 24 24" aria-hidden="true">
											<line x1="7" y1="17" x2="17" y2="7" />
											<polyline points="7 7 17 7 17 17" />
										</svg>
									</a>
								<?php else : ?>
									<span class="iftp-code">—</span>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<?php if (! empty($form_data)) : ?>
						<!-- Submitted data card -->
						<div class="iftp-card">
							<div class="iftp-card-head">
								<span class="iftp-card-head-ic">
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<line x1="8" y1="6" x2="21" y2="6" />
										<line x1="8" y1="12" x2="21" y2="12" />
										<line x1="8" y1="18" x2="21" y2="18" />
										<line x1="3" y1="6" x2="3.01" y2="6" />
										<line x1="3" y1="12" x2="3.01" y2="12" />
										<line x1="3" y1="18" x2="3.01" y2="18" />
									</svg>
								</span>
								<h2><?php esc_html_e('Submitted Data', 'ifthenpay-payments-for-contactform7'); ?></h2>
							</div>
							<div class="iftp-kgrid">
								<?php
								$field_entries = array();
								foreach ($form_data as $key => $value) {
									if (strpos((string) $key, 'iftp_cf7_') === 0) {
										continue;
									}
									$field_entries[] = array('key' => (string) $key, 'value' => $value);
								}
								$total_fields = count($field_entries);
								foreach ($field_entries as $fi => $fd) :
									$str_val   = is_array($fd['value']) ? implode(', ', $fd['value']) : (string) $fd['value'];
									$max_len   = 200;
									$is_long   = mb_strlen($str_val) > $max_len;
									$is_last   = ($fi === $total_fields - 1);
									$is_odd    = ($total_fields % 2 !== 0);
									$cell_full = ($is_last && $is_odd) ? ' iftp-kcell--full' : '';
									$field_svg = $this->get_field_icon_svg($fd['key']);
								?>
									<div class="iftp-kcell<?php echo esc_attr($cell_full); ?>">
										<div class="iftp-klbl">
											<?php echo wp_kses($field_svg, self::get_svg_kses()); ?>
											<?php echo esc_html($this->format_field_label($fd['key'])); ?>
										</div>
										<div class="iftp-kval">
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
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				</div><!-- .iftp-detail-col (left) -->
				<!-- Right column (aside) -->
				<div class="iftp-detail-col iftp-detail-col--aside">
					<!-- Customer card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<span class="iftp-card-head-ic">
								<svg viewBox="0 0 24 24" aria-hidden="true">
									<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
									<circle cx="12" cy="7" r="4" />
								</svg>
							</span>
							<h2><?php esc_html_e('Customer', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-cust-body">
							<div class="iftp-cust-row">
								<div class="iftp-avatar-new" aria-hidden="true">
									<?php echo esc_html($initials); ?>
									<?php if ($gravatar_url !== '') : ?>
										<img class="iftp-gravatar" src="<?php echo esc_url($gravatar_url); ?>" alt="" onerror="this.style.display='none';" />
									<?php endif; ?>
								</div>
								<div>
									<div class="iftp-cust-name"><?php echo esc_html($entry->customer_name ?: '—'); ?></div>
									<?php if ($entry->customer_email !== '') : ?>
										<div class="iftp-cust-email">
											<a href="mailto:<?php echo esc_attr($entry->customer_email); ?>"><?php echo esc_html($entry->customer_email); ?></a>
										</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
					<!-- Timestamps card -->
					<div class="iftp-card">
						<div class="iftp-card-head">
							<span class="iftp-card-head-ic">
								<svg viewBox="0 0 24 24" aria-hidden="true">
									<circle cx="12" cy="12" r="10" />
									<polyline points="12 6 12 12 16 14" />
								</svg>
							</span>
							<h2><?php esc_html_e('Timestamps', 'ifthenpay-payments-for-contactform7'); ?></h2>
						</div>
						<div class="iftp-ts-body">
							<div class="iftp-tsrow">
								<div class="iftp-ts-ic iftp-ts-ic--created">
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<line x1="12" y1="5" x2="12" y2="19" />
										<line x1="5" y1="12" x2="19" y2="12" />
									</svg>
								</div>
								<div>
									<div class="iftp-ts-lbl"><?php esc_html_e('Created', 'ifthenpay-payments-for-contactform7'); ?></div>
									<div class="iftp-ts-val"><?php echo esc_html($entry->created_at); ?></div>
								</div>
							</div>
							<?php if ($entry->link_generated_at !== null) : ?>
								<div class="iftp-tsrow">
									<div class="iftp-ts-ic iftp-ts-ic--link">
										<svg viewBox="0 0 24 24" aria-hidden="true">
											<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
											<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
										</svg>
									</div>
									<div>
										<div class="iftp-ts-lbl"><?php esc_html_e('Link Generated', 'ifthenpay-payments-for-contactform7'); ?></div>
										<div class="iftp-ts-val"><?php echo esc_html($entry->link_generated_at); ?></div>
									</div>
								</div>
							<?php endif; ?>
							<div class="iftp-tsrow">
								<div class="iftp-ts-ic iftp-ts-ic--updated">
									<svg viewBox="0 0 24 24" aria-hidden="true">
										<polyline points="1 4 1 10 7 10" />
										<path d="M3.51 15a9 9 0 1 0 .49-3.76" />
									</svg>
								</div>
								<div>
									<div class="iftp-ts-lbl"><?php esc_html_e('Updated', 'ifthenpay-payments-for-contactform7'); ?></div>
									<div class="iftp-ts-val"><?php echo esc_html($entry->updated_at); ?></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div><!-- .iftp-detail-grid -->
		</div><!-- .wrap -->

		<!-- Confirm action modal -->
		<div id="iftp-confirm-modal" class="iftp-modal iftp-modal--sm" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="iftp-confirm-title">
			<div class="iftp-modal-overlay"></div>
			<div class="iftp-modal-box">
				<div class="iftp-modal-head">
					<h2 id="iftp-confirm-title">
						<span id="iftp-confirm-heading"></span>
					</h2>
					<button type="button" class="iftp-modal-close iftp-confirm-close" aria-label="<?php esc_attr_e('Close', 'ifthenpay-payments-for-contactform7'); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<line x1="18" y1="6" x2="6" y2="18" />
							<line x1="6" y1="6" x2="18" y2="18" />
						</svg>
					</button>
				</div>
				<div class="iftp-confirm-body">
					<p id="iftp-confirm-message"></p>
				</div>
				<div class="iftp-modal-foot">
					<button type="button" class="button iftp-confirm-cancel"><?php esc_html_e('No', 'ifthenpay-payments-for-contactform7'); ?></button>
					<a href="#" id="iftp-confirm-yes" class="button button-primary iftp-confirm-yes-btn"><?php esc_html_e('Yes', 'ifthenpay-payments-for-contactform7'); ?></a>
				</div>
			</div>
		</div>
<?php
	}
	private function get_field_icon_svg(string $key): string
	{
		$k = strtolower(preg_replace('/[^a-z0-9]/i', '', $key) ?? $key);
		if (preg_match('/name|nome|first|last|fullname/', $k)) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
		}
		if (preg_match('/email|mail/', $k)) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>';
		}
		if (preg_match('/phone|tel|mobile|movel|telemovel|celular/', $k)) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13 19.79 19.79 0 0 1 1.61 4.37 2 2 0 0 1 3.59 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
		}
		if (preg_match('/address|morada|rua|street|city|cidade|zip|postal/', $k)) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
		}
		if (preg_match('/message|mensagem|notes|notas|comment|observ/', $k)) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
		}
		if (preg_match('/amount|valor|price|preco|total/', $k)) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
		}
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>';
	}

	/**
	 * Allowed SVG tags and attributes for wp_kses() — covers all inline icons used in this file.
	 *
	 * wp_kses() lowercases attribute names, so camelCase SVG attributes (e.g. viewBox, strokeWidth)
	 * must be listed in lowercase. Modern browsers parse inline SVG case-insensitively within HTML5.
	 *
	 * @return array<string, array<string, array<mixed>>>
	 */
	private static function get_svg_kses(): array
	{
		$shared = array(
			'fill'            => array(),
			'stroke'          => array(),
			'stroke-width'    => array(),
			'stroke-linecap'  => array(),
			'stroke-linejoin' => array(),
			'aria-hidden'     => array(),
		);
		return array(
			'svg'      => array_merge(
				$shared,
				array(
					'viewbox' => array(),
					'xmlns'   => array(),
					'width'   => array(),
					'height'  => array(),
				)
			),
			'path'     => array_merge($shared, array('d' => array())),
			'polyline' => array_merge($shared, array('points' => array())),
			'circle'   => array_merge($shared, array('cx' => array(), 'cy' => array(), 'r' => array())),
			'line'     => array_merge($shared, array('x1' => array(), 'y1' => array(), 'x2' => array(), 'y2' => array())),
			'rect'     => array_merge($shared, array('x' => array(), 'y' => array(), 'width' => array(), 'height' => array(), 'rx' => array(), 'ry' => array())),
		);
	}
}
