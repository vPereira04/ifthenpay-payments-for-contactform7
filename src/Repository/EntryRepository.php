<?php

/**
 * Data-access layer for payment entries.
 *
 * @package Ifthenpay\CF7
 */

declare(strict_types=1);

namespace Ifthenpay\CF7\Repository;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Repository\DTO\EntryDto;

/**
 * Handles all CRUD operations on the ifthenpay_cf7_entries table.
 */
final class EntryRepository
{

	/** @var string Full (prefixed) table name. */
	private string $table;

	/** @var array<string, array<string, int|float>> Per-request memo so get_period_stats() runs once per period per page load. */
	private array $stats_memo = [];

	/**
	 * Constructor — resolves the table name once on instantiation.
	 */
	public function __construct()
	{
		global $wpdb;
		$this->table = $wpdb->prefix . IFTP_CF7_TABLE;
	}

	/**
	 * Insert a new entry and return the new row ID (0 on failure).
	 *
	 * @param EntryDto $dto Source data transfer object.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function create(EntryDto $dto): int
	{
		global $wpdb;

		$now = current_time('mysql');

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table; $wpdb->insert() handles escaping.
			$this->table,
			array(
				'form_id'        => $dto->form_id,
				'form_title'     => $dto->form_title,
				'customer_name'  => $dto->customer_name,
				'customer_email' => $dto->customer_email,
				'customer_ip'    => $dto->customer_ip,
				'amount'         => number_format($dto->amount, 2, '.', ''),
				'payment_method' => $dto->payment_method,
				'payment_status' => $dto->payment_status,
				'payment_url'    => $dto->payment_url,
				'return_url'     => $dto->return_url,
				'form_data'      => $dto->form_data,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
		);

		if ($wpdb->last_error) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the payment_status column of a single entry.
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status New status value.
	 * @return bool True on success, false on failure.
	 */
	public function update_status(int $id, string $status): bool
	{
		global $wpdb;
		$rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; write operation.
			$this->table,
			array(
				'payment_status' => sanitize_key($status),
				'updated_at'     => current_time('mysql'),
			),
			array('id' => $id),
			array('%s', '%s'),
			array('%d')
		);
		return false !== $rows;
	}

	/**
	 * Update payment details after a successful payment or webhook.
	 *
	 * @param int         $id             Entry ID.
	 * @param string      $payment_method Payment method entity code (optional).
	 * @param string      $status         New payment status (default 'completed').
	 * @param string|null $request_id     Gateway request ID (optional).
	 * @return bool True on success, false on failure.
	 */
	public function update_transaction(int $id, string $payment_method = '', string $status = 'completed', ?string $request_id = null): bool
	{
		global $wpdb;
		$data    = array(
			'payment_status' => sanitize_key($status),
			'updated_at'     => current_time('mysql'),
		);
		$formats = array('%s', '%s');
		if ('' !== $payment_method) {
			$data['payment_method'] = strtoupper(sanitize_text_field($payment_method));
			$formats[]              = '%s';
		}
		if (null !== $request_id) {
			$data['request_id'] = sanitize_text_field($request_id);
			$formats[]          = '%s';
		}
		$rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; write operation.
			$this->table,
			$data,
			array('id' => $id),
			$formats,
			array('%d')
		);
		return false !== $rows;
	}

	/**
	 * Persist a new payment URL (and optionally the payment method) for an entry.
	 *
	 * @param int    $id          Entry ID.
	 * @param string $payment_url Absolute payment URL returned by ifthenpay.
	 * @param string $method      Payment method entity code (optional).
	 * @return bool True on success, false on failure.
	 */
	public function update_payment_url(int $id, string $payment_url, string $method = ''): bool
	{
		global $wpdb;
		$now     = current_time('mysql');
		$data    = array(
			'payment_url'       => esc_url_raw($payment_url),
			'link_generated_at' => $now,
			'updated_at'        => $now,
		);
		$formats = array('%s', '%s', '%s');
		if ('' !== $method) {
			$data['payment_method'] = strtoupper(sanitize_text_field($method));
			$formats[]              = '%s';
		}
		$rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; write operation.
			$this->table,
			$data,
			array('id' => $id),
			$formats,
			array('%d')
		);
		return false !== $rows;
	}

	/**
	 * Delete a single entry by primary key.
	 *
	 * @param int $id Entry ID.
	 * @return bool True if a row was deleted, false otherwise.
	 */
	public function delete(int $id): bool
	{
		global $wpdb;
		$rows = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; write operation.
			$this->table,
			array('id' => $id),
			array('%d')
		);
		return false !== $rows && $rows > 0;
	}

	/**
	 * Delete multiple entries by their primary keys.
	 *
	 * @param int[] $ids Array of entry IDs to delete.
	 * @return int Number of rows deleted.
	 */
	public function bulk_delete(array $ids): int
	{
		global $wpdb;
		$ids = array_filter(array_map('absint', $ids));
		if (empty($ids)) {
			return 0;
		}

		$fmt        = implode(',', array_fill(0, count($ids), '%d'));
		$query_stmt = "DELETE FROM %i WHERE id IN ($fmt)";

		$affected = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; bulk write operation.
			$wpdb->prepare(
				$query_stmt, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query_stmt built structurally with %i/%d placeholders only; no user data interpolated.
				array_merge(array($this->table), array_values($ids))
			)
		);
		return $affected;
	}

	/**
	 * Update payment_status for multiple entries.
	 *
	 * @param int[]  $ids    Array of entry IDs.
	 * @param string $status New status value.
	 * @return int Number of rows updated.
	 */
	public function bulk_update_status(array $ids, string $status): int
	{
		global $wpdb;
		$ids    = array_filter(array_map('absint', $ids));
		$status = sanitize_key($status);
		if (empty($ids) || $status === '') {
			return 0;
		}

		$fmt        = implode(',', array_fill(0, count($ids), '%d'));
		$now        = current_time('mysql');
		$query_stmt = "UPDATE %i SET payment_status = %s, updated_at = %s WHERE id IN ($fmt)";

		$affected = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; bulk write operation.
			$wpdb->prepare(
				$query_stmt, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query_stmt built structurally with %i/%s/%d placeholders only; no user data interpolated.
				array_merge(array($this->table, $status, $now), array_values($ids))
			)
		);
		return $affected;
	}

	/**
	 * Retrieve a single entry by its primary key.
	 *
	 * @param int $id Entry ID.
	 * @return EntryDto|null The entry DTO, or null if not found.
	 */
	public function get_by_id(int $id): ?EntryDto
	{
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; caching payment data risks stale status.
			$wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id),
			ARRAY_A
		);
		return is_array($row) ? EntryDto::from($row) : null;
	}

	/**
	 * Return a page of entries, optionally filtered and searched.
	 *
	 * When orderby='id' (the default) keyset/cursor pagination is used: fetches exactly
	 * per_page+1 rows starting from $cursor — O(1) regardless of page depth.
	 * When orderby is any other column, classic OFFSET pagination is used.
	 *
	 * Always returns per_page+1 rows so the caller can detect has_more by checking
	 * whether count > per_page, then slice and reverse as needed.
	 *
	 * @param int    $page         Display page counter (used for OFFSET mode only).
	 * @param int    $per_page     Rows per page (N); method fetches N+1 to detect has_more.
	 * @param string $status       Filter by payment_status ('' = all).
	 * @param string $search_field Column to search in.
	 * @param string $search_op    'contains' or 'is'.
	 * @param string $search_query Search term.
	 * @param int    $cursor       Boundary ID for keyset pagination (0 = first page).
	 * @param string $dir          'next' (id < cursor DESC) or 'prev' (id > cursor ASC).
	 * @return EntryDto[]
	 */
	public function get_all(int $page = 1, int $per_page = 20, string $status = '', string $search_field = '', string $search_op = 'contains', string $search_query = '', string $period = 'all', string $orderby = 'id', string $order = 'desc', int $cursor = 0, string $dir = 'next', int $form_id = 0): array
	{
		global $wpdb;

		$status       = sanitize_key($status);
		$search_field = sanitize_key($search_field);
		$search_op    = in_array($search_op, array('contains', 'is'), true) ? $search_op : 'contains';
		$search_query = sanitize_text_field($search_query);
		$cursor       = absint($cursor);
		$dir          = $dir === 'prev' ? 'prev' : 'next';
		$per_page     = absint($per_page);
		$form_id      = absint($form_id);
		$fetch        = $per_page + 1;

		[$where_tpl, $w_args] = $this->build_where($status, $search_field, $search_op, $search_query, $form_id);

		$period_sql = $this->period_condition($period, $status, true);
		if ($period_sql !== '') {
			$where_tpl = $where_tpl === '' ? ' WHERE ' . $period_sql : $where_tpl . ' AND ' . $period_sql;
		}

		$allowed_order_cols = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$orderby_col        = in_array($orderby, $allowed_order_cols, true) ? $orderby : 'id';

		if ($orderby_col === 'id') {
			if ($cursor > 0 || $page === 1) {

				if ($cursor > 0) {

					if ($order === 'asc') {
						$cursor_cond = $dir === 'prev' ? 'id < %d' : 'id > %d';
						$order_dir   = $dir === 'prev' ? 'DESC'    : 'ASC';
					} else {
						$cursor_cond = $dir === 'prev' ? 'id > %d' : 'id < %d';
						$order_dir   = $dir === 'prev' ? 'ASC'     : 'DESC';
					}
					$where_tpl = $where_tpl === '' ? ' WHERE ' . $cursor_cond : $where_tpl . ' AND ' . $cursor_cond;
					$w_args[]  = $cursor;
				} else {

					$order_dir = $order === 'asc' ? 'ASC' : 'DESC';
				}
				$args      = array_merge(array($this->table), $w_args, array($fetch));

				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; intentionally live; $where_tpl built with only safe placeholders; $order_dir validated.
					$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Count matches dynamically-built placeholders in $where_tpl.
						'SELECT id, form_id, form_title, customer_name, customer_email, customer_ip, amount, payment_method, payment_status, payment_url, return_url, request_id, created_at, updated_at FROM %i' . $where_tpl . ' ORDER BY id ' . $order_dir . ' LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_tpl uses only safe placeholders; $order_dir is 'ASC' or 'DESC'.
						...$args
					),
					ARRAY_A
				);
			} else {

				$page      = max(1, $page);
				$offset    = absint(($page - 1) * $per_page);
				$order_dir = $order === 'asc' ? 'ASC' : 'DESC';
				$args      = array_merge(array($this->table), $w_args, array($fetch, $offset));

				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; intentionally live; $where_tpl uses only safe placeholders.
					$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Count matches dynamically-built placeholders in $where_tpl.
						'SELECT id, form_id, form_title, customer_name, customer_email, customer_ip, amount, payment_method, payment_status, payment_url, return_url, request_id, created_at, updated_at FROM %i' . $where_tpl . ' ORDER BY id ' . $order_dir . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_tpl uses only safe placeholders; $order_dir is 'ASC' or 'DESC'.
						...$args
					),
					ARRAY_A
				);
			}
		} else {

			$page      = max(1, $page);
			$offset    = absint(($page - 1) * $per_page);
			$order_dir = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
			$args      = array_merge(array($this->table), $w_args, array($orderby_col, $fetch, $offset));

			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; intentionally live; $where_tpl built with only safe placeholders; $order_dir validated.
				$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Count matches dynamically-built placeholders in $where_tpl.
					'SELECT id, form_id, form_title, customer_name, customer_email, customer_ip, amount, payment_method, payment_status, payment_url, return_url, request_id, created_at, updated_at FROM %i' . $where_tpl . ' ORDER BY %i ' . $order_dir . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_tpl uses only safe placeholders; $order_dir validated to 'ASC'/'DESC'.
					...$args
				),
				ARRAY_A
			);
		}

		return is_array($rows) ? array_map(array(EntryDto::class, 'from'), $rows) : array();
	}

	/**
	 * Count and sum in one query — replaces calling count_all() + sum_amount() separately.
	 *
	 * Result cached for 30 s when there is no search term.
	 *
	 * @return array{int, float} [$count, $sum]
	 */
	public function count_and_sum(string $status = '', string $search_field = '', string $search_op = 'contains', string $search_query = '', string $period = 'all', int $form_id = 0): array
	{
		global $wpdb;
		$status       = sanitize_key($status);
		$search_field = sanitize_key($search_field);
		$search_op    = in_array($search_op, array('contains', 'is'), true) ? $search_op : 'contains';
		$search_query = sanitize_text_field($search_query);
		$form_id      = absint($form_id);


		if ($period === 'all' && $search_query === '' && $form_id === 0) {
			$stats = $this->get_period_stats('all');
			if ($status === '') {
				return [
					$stats['completed_any'] + $stats['pending_any'] + $stats['failed_any'] + $stats['cancelled_any'],
					round($stats['completed_amount'] + $stats['pending_amount'] + $stats['failed_amount'] + $stats['cancelled_amount'], 2),
				];
			}
			if (isset($stats[$status . '_any'], $stats[$status . '_amount'])) {
				return [(int) $stats[$status . '_any'], round((float) $stats[$status . '_amount'], 2)];
			}
		}

		[$where_tpl, $w_args] = $this->build_where($status, $search_field, $search_op, $search_query, $form_id);

		$period_sql = $this->period_condition($period, $status, true);
		if ($period_sql !== '') {
			$where_tpl = $where_tpl === '' ? ' WHERE ' . $period_sql : $where_tpl . ' AND ' . $period_sql;
		}

		$args = array_merge(array($this->table), $w_args);
		$row  = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; intentionally live for accurate counts; $where_tpl built by build_where() + period_condition() using only hardcoded SQL and %s/%d/%i placeholders.
			$wpdb->prepare(
				'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM %i' . $where_tpl, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_tpl built by build_where() + period_condition() using only hardcoded SQL and %s/%d/%i placeholders.
				...$args
			),
			ARRAY_A
		);

		return array(
			(int)   ($row['cnt']   ?? 0),
			(float) ($row['total'] ?? 0.0),
		);
	}

	/**
	 * Return all period stats needed for the entries-page stats row in a single query.
	 *
	 * Replaces 8 individual count_period / sum_amount_period calls.
	 *
	 * Keys returned:
	 *   completed_any, pending_any, failed_any, cancelled_any  — any-activity counts (for status tabs)
	 *   completed_count, pending_count, …                      — status-specific period counts (for sidebar)
	 *   completed_amount, pending_amount, …                    — status-specific period amounts (for revenue card)
	 *
	 * @param string $period 'all'|'year'|'month'|'week'|'15day'|'30day'|'day'
	 * @return array<string, int|float>
	 */
	public function get_period_stats(string $period = 'all'): array
	{
		$period = sanitize_key($period);


		if (isset($this->stats_memo[$period])) {
			return $this->stats_memo[$period];
		}

		global $wpdb;


		if ($period === 'all') {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; intentionally live for accurate counts.
				$wpdb->prepare(
					'SELECT payment_status, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM %i GROUP BY payment_status',
					$this->table
				),
				ARRAY_A
			);

			$by = [];
			foreach ($rows ?: [] as $r) {
				$by[$r['payment_status']] = [(int) $r['cnt'], (float) $r['total']];
			}


			$this->stats_memo[$period] = [
				'completed_any'    => $by['completed'][0] ?? 0,
				'pending_any'      => $by['pending'][0]   ?? 0,
				'failed_any'       => $by['failed'][0]    ?? 0,
				'cancelled_any'    => $by['cancelled'][0] ?? 0,
				'expired_any'      => $by['expired'][0]   ?? 0,
				'completed_count'  => $by['completed'][0] ?? 0,
				'pending_count'    => $by['pending'][0]   ?? 0,
				'failed_count'     => $by['failed'][0]    ?? 0,
				'cancelled_count'  => $by['cancelled'][0] ?? 0,
				'expired_count'    => $by['expired'][0]   ?? 0,
				'completed_amount' => $by['completed'][1] ?? 0.0,
				'pending_amount'   => $by['pending'][1]   ?? 0.0,
				'failed_amount'    => $by['failed'][1]    ?? 0.0,
				'cancelled_amount' => $by['cancelled'][1] ?? 0.0,
				'expired_amount'   => $by['expired'][1]   ?? 0.0,
			];
			return $this->stats_memo[$period];
		}

		[$any_cond, $created_cond, $updated_cond] = $this->period_conditions_triple($period);

		$where   = $any_cond     !== '' ? " WHERE ({$any_cond})"   : '';
		$cre_sql = $created_cond !== '' ? " AND ({$created_cond})" : '';
		$upd_sql = $updated_cond !== '' ? " AND ({$updated_cond})" : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where/$cre_sql/$upd_sql are hardcoded date conditions from period_conditions_triple(), containing no user-controlled values.
		$sql = "SELECT
			SUM(CASE WHEN payment_status='completed' THEN 1 ELSE 0 END) AS completed_any,
			SUM(CASE WHEN payment_status='pending'   THEN 1 ELSE 0 END) AS pending_any,
			SUM(CASE WHEN payment_status='failed'    THEN 1 ELSE 0 END) AS failed_any,
			SUM(CASE WHEN payment_status='cancelled' THEN 1 ELSE 0 END) AS cancelled_any,
			SUM(CASE WHEN payment_status='expired'   THEN 1 ELSE 0 END) AS expired_any,
			SUM(CASE WHEN payment_status='completed'{$upd_sql} THEN 1 ELSE 0 END) AS completed_count,
			SUM(CASE WHEN payment_status='pending'{$cre_sql}   THEN 1 ELSE 0 END) AS pending_count,
			SUM(CASE WHEN payment_status='failed'{$cre_sql}    THEN 1 ELSE 0 END) AS failed_count,
			SUM(CASE WHEN payment_status='cancelled'{$cre_sql} THEN 1 ELSE 0 END) AS cancelled_count,
			SUM(CASE WHEN payment_status='expired'{$cre_sql}   THEN 1 ELSE 0 END) AS expired_count,
			COALESCE(SUM(CASE WHEN payment_status='completed'{$upd_sql} THEN amount ELSE 0 END),0) AS completed_amount,
			COALESCE(SUM(CASE WHEN payment_status='pending'{$cre_sql}   THEN amount ELSE 0 END),0) AS pending_amount,
			COALESCE(SUM(CASE WHEN payment_status='failed'{$cre_sql}    THEN amount ELSE 0 END),0) AS failed_amount,
			COALESCE(SUM(CASE WHEN payment_status='cancelled'{$cre_sql} THEN amount ELSE 0 END),0) AS cancelled_amount,
			COALESCE(SUM(CASE WHEN payment_status='expired'{$cre_sql}   THEN amount ELSE 0 END),0) AS expired_amount
			FROM %i{$where}";

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom plugin table; intentionally live for accurate counts; all interpolated SQL is hardcoded date conditions with no user-controlled values.
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built above from hardcoded CASE expressions and a %i placeholder; interpolated strings are internal period conditions from period_conditions_triple(), never user input.
				$this->table
			),
			ARRAY_A
		);

		$z = array(
			'completed_any' => 0,
			'pending_any' => 0,
			'failed_any' => 0,
			'cancelled_any' => 0,
			'expired_any' => 0,
			'completed_count' => 0,
			'pending_count' => 0,
			'failed_count' => 0,
			'cancelled_count' => 0,
			'expired_count' => 0,
			'completed_amount' => 0.0,
			'pending_amount' => 0.0,
			'failed_amount' => 0.0,
			'cancelled_amount' => 0.0,
			'expired_amount' => 0.0,
		);

		if (! is_array($row)) {
			$this->stats_memo[$period] = $z;
			return $z;
		}

		$this->stats_memo[$period] = array(
			'completed_any'    => (int)   ($row['completed_any']    ?? 0),
			'pending_any'      => (int)   ($row['pending_any']      ?? 0),
			'failed_any'       => (int)   ($row['failed_any']       ?? 0),
			'cancelled_any'    => (int)   ($row['cancelled_any']    ?? 0),
			'expired_any'      => (int)   ($row['expired_any']      ?? 0),
			'completed_count'  => (int)   ($row['completed_count']  ?? 0),
			'pending_count'    => (int)   ($row['pending_count']    ?? 0),
			'failed_count'     => (int)   ($row['failed_count']     ?? 0),
			'cancelled_count'  => (int)   ($row['cancelled_count']  ?? 0),
			'expired_count'    => (int)   ($row['expired_count']    ?? 0),
			'completed_amount' => (float) ($row['completed_amount'] ?? 0.0),
			'pending_amount'   => (float) ($row['pending_amount']   ?? 0.0),
			'failed_amount'    => (float) ($row['failed_amount']    ?? 0.0),
			'cancelled_amount' => (float) ($row['cancelled_amount'] ?? 0.0),
			'expired_amount'   => (float) ($row['expired_amount']   ?? 0.0),
		);
		return $this->stats_memo[$period];
	}

	/**
	 * Return dashboard-widget stats for all four periods in a single query.
	 *
	 * Replaces 20 individual count_period / sum_amount_period calls (5 per period × 4 periods).
	 * Only rows with any activity in the last 30 days are scanned.
	 *
	 * @return array<string, array{revenue: float, counts: array<string, int>}>
	 *   Keys: '1', '7', '15', '30' (day counts).
	 */
	public function get_widget_period_stats(): array
	{
		global $wpdb;


		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; intentionally live for accurate counts; all CASE expressions are hardcoded date conditions with no user-controlled values.
			$wpdb->prepare(
				"SELECT
				COALESCE(SUM(CASE WHEN payment_status='completed' AND updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN amount ELSE 0 END),0) AS d1_revenue,
				SUM(CASE WHEN payment_status='completed' AND updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS d1_completed,
				SUM(CASE WHEN payment_status='pending'   AND updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS d1_pending,
				SUM(CASE WHEN payment_status='failed'    AND updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS d1_failed,
				SUM(CASE WHEN payment_status='cancelled' AND updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS d1_cancelled,
				SUM(CASE WHEN payment_status='expired'   AND updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS d1_expired,
				COALESCE(SUM(CASE WHEN payment_status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN amount ELSE 0 END),0) AS d7_revenue,
				SUM(CASE WHEN payment_status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS d7_completed,
				SUM(CASE WHEN payment_status='pending'   AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS d7_pending,
				SUM(CASE WHEN payment_status='failed'    AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS d7_failed,
				SUM(CASE WHEN payment_status='cancelled' AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS d7_cancelled,
				SUM(CASE WHEN payment_status='expired'   AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS d7_expired,
				COALESCE(SUM(CASE WHEN payment_status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY) THEN amount ELSE 0 END),0) AS d15_revenue,
				SUM(CASE WHEN payment_status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY) THEN 1 ELSE 0 END) AS d15_completed,
				SUM(CASE WHEN payment_status='pending'   AND updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY) THEN 1 ELSE 0 END) AS d15_pending,
				SUM(CASE WHEN payment_status='failed'    AND updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY) THEN 1 ELSE 0 END) AS d15_failed,
				SUM(CASE WHEN payment_status='cancelled' AND updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY) THEN 1 ELSE 0 END) AS d15_cancelled,
				SUM(CASE WHEN payment_status='expired'   AND updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY) THEN 1 ELSE 0 END) AS d15_expired,
				COALESCE(SUM(CASE WHEN payment_status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN amount ELSE 0 END),0) AS d30_revenue,
				SUM(CASE WHEN payment_status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS d30_completed,
				SUM(CASE WHEN payment_status='pending'   AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS d30_pending,
				SUM(CASE WHEN payment_status='failed'    AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS d30_failed,
				SUM(CASE WHEN payment_status='cancelled' AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS d30_cancelled,
				SUM(CASE WHEN payment_status='expired'   AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS d30_expired
				FROM %i
				WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
				$this->table
			),
			ARRAY_A
		);

		$empty = array(
			'revenue' => 0.0,
			'counts'  => array('pending' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0, 'expired' => 0),
		);

		if (! is_array($row)) {
			return array('1' => $empty, '7' => $empty, '15' => $empty, '30' => $empty);
		}

		$build = static function (string $p) use ($row): array {
			return array(
				'revenue' => round((float) ($row["{$p}_revenue"] ?? 0), 2),
				'counts'  => array(
					'pending'   => (int) ($row["{$p}_pending"]   ?? 0),
					'completed' => (int) ($row["{$p}_completed"] ?? 0),
					'failed'    => (int) ($row["{$p}_failed"]    ?? 0),
					'cancelled' => (int) ($row["{$p}_cancelled"] ?? 0),
					'expired'   => (int) ($row["{$p}_expired"]   ?? 0),
				),
			);
		};

		$result = array(
			'1'  => $build('d1'),
			'7'  => $build('d7'),
			'15' => $build('d15'),
			'30' => $build('d30'),
		);
		return $result;
	}

	/**
	 * Returns a safe, hardcoded SQL snippet for the requested period (no user data).
	 *
	 * Always filters on updated_at — sargable range scan used by idx_updated_status_amount.
	 * updated_at >= created_at always, so any row created in the period also has updated_at
	 * in the period; rows updated later naturally carry an even more recent updated_at.
	 *
	 * $status and $any_activity are kept for backwards-compatibility but no longer affect output.
	 */
	private function period_condition(string $period, string $_status = '', bool $_any_activity = false): string
	{
		switch ($period) {
			case 'day':
				return "updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
			case 'week':
				return "updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
			case '15day':
				return "updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)";
			case '30day':
				return "updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
			case 'month':
				return "updated_at >= DATE_FORMAT(NOW(),'%Y-%m-01') AND updated_at < DATE_ADD(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 1 MONTH)";
			case 'year':
				return "updated_at >= DATE_FORMAT(NOW(),'%Y-01-01') AND updated_at < DATE_ADD(DATE_FORMAT(NOW(),'%Y-01-01'), INTERVAL 1 YEAR)";
			default:
				return '';
		}
	}

	/**
	 * Return [any_cond, created_cond, updated_cond] for a period — all hardcoded, no user data.
	 *
	 * All three values are now the same updated_at condition — OR conditions removed so
	 * idx_updated_status_amount can do a covering range scan instead of an index merge / full scan.
	 *
	 * @return array{string, string, string}
	 */
	private function period_conditions_triple(string $period): array
	{
		switch ($period) {
			case 'day':
				$c = "updated_at >= CURDATE() AND updated_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
				return array($c, $c, $c);
			case 'week':
				$c = "updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
				return array($c, $c, $c);
			case '15day':
				$c = "updated_at >= DATE_SUB(NOW(), INTERVAL 15 DAY)";
				return array($c, $c, $c);
			case '30day':
				$c = "updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
				return array($c, $c, $c);
			case 'month':
				$c = "updated_at >= DATE_FORMAT(NOW(),'%Y-%m-01') AND updated_at < DATE_ADD(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 1 MONTH)";
				return array($c, $c, $c);
			case 'year':
				$c = "updated_at >= DATE_FORMAT(NOW(),'%Y-01-01') AND updated_at < DATE_ADD(DATE_FORMAT(NOW(),'%Y-01-01'), INTERVAL 1 YEAR)";
				return array($c, $c, $c);
			default:
				return array('', '', '');
		}
	}

	/**
	 * Build a WHERE clause template and its parameter list.
	 *
	 * Uses %i (identifier) and %s/%d placeholders — safe for $wpdb->prepare().
	 * Field names are validated against an explicit allowlist before use.
	 *
	 * @param string $status       Filter by payment_status ('' = all).
	 * @param string $search_field Column to search in.
	 * @param string $search_op    'contains' or 'is'.
	 * @param string $search_query Search term.
	 * @return array{string, array<int, mixed>} [clause_template, params]
	 */
	private function build_where(string $status, string $search_field = '', string $search_op = 'contains', string $search_query = '', int $form_id = 0): array
	{
		global $wpdb;
		$conditions = array();
		$params     = array();

		if ('' !== $status) {
			$conditions[] = 'payment_status = %s';
			$params[]     = sanitize_key($status);
		}

		if ($form_id > 0) {
			$conditions[] = 'form_id = %d';
			$params[]     = $form_id;
		}

		$allowed_fields = array(
			'customer_name',
			'customer_email',
			'form_title',
			'request_id',
			'payment_method',
			'amount',
		);
		if ('' !== $search_field && '' !== $search_query && in_array($search_field, $allowed_fields, true)) {
			if ('is' === $search_op) {
				$conditions[] = '%i = %s';
				$params[]     = $search_field;
				$params[]     = $search_query;
			} else {
				$conditions[] = '%i LIKE %s';
				$params[]     = $search_field;
				$params[]     = '%' . $wpdb->esc_like($search_query) . '%';
			}
		}

		if (empty($conditions)) {
			return array('', array());
		}

		return array(
			' WHERE ' . implode(' AND ', $conditions),
			$params,
		);
	}

	/**
	 * Mark pending entries as failed when their creation date is older than $expire_days days.
	 * Mirrors ifthenpay's server-side expiry, which fires at 23:59 on the expiry date.
	 *
	 * @param int $expire_days Number of days after which a pending entry is considered expired.
	 * @return int Number of rows updated.
	 */
	public function mark_expired_pending(int $expire_days): int
	{
		global $wpdb;
		$days = max(1, $expire_days);

		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Daily scheduled expiry; intentionally live, no caching needed.
			$wpdb->prepare(
				"UPDATE %i SET payment_status = 'expired', updated_at = NOW() WHERE payment_status = 'pending' AND DATE(created_at) <= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$this->table,
				$days
			)
		);
		return (int) $rows;
	}

	/**
	 * Return all distinct form_ids that have at least one entry.
	 *
	 * Pure covering-index scan on idx_form_id — no heap reads even on large tables.
	 *
	 * @return int[]
	 */
	public function get_entry_form_ids(): array
	{
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table; intentionally live.
			$wpdb->prepare(
				'SELECT DISTINCT form_id FROM %i WHERE form_id > 0 ORDER BY form_id ASC',
				$this->table
			),
			ARRAY_A
		);
		return array_map('intval', array_column(is_array($rows) ? $rows : array(), 'form_id'));
	}
}
