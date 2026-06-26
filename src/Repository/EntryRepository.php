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

	/** @var string Object-cache group for all plugin cache entries. */
	private const CACHE_GROUP = 'iftp_cf7';

	/** @var int TTL for list/stats caches (seconds). */
	private const CACHE_TTL = 30;

	/** @var int TTL for individual-entry caches (seconds). */
	private const ENTRY_CACHE_TTL = 600;

	/** @var array<string, array<string, int|float>> Per-request memo so get_period_stats() runs once per period per page load. */
	private array $stats_memo = [];

	public function __construct()
	{
		global $wpdb;
		$this->table = $wpdb->prefix . IFTP_CF7_TABLE;
	}

	public function create(EntryDto $dto): int
	{
		global $wpdb;
		$now = current_time('mysql');
		$row = array(
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
		);
		$fmt = array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s');
		if ($dto->request_id !== null) {
			$row['request_id'] = $dto->request_id;
			$fmt[]             = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API exists for this operation.
		$wpdb->insert($this->table, $row, $fmt);
		if ($wpdb->last_error) {
			return 0;
		}
		$new_id = (int) $wpdb->insert_id;
		wp_cache_delete('stats_all', self::CACHE_GROUP);
		return $new_id;
	}

	public function update_status(int $id, string $status): bool
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; cache invalidated below.
		$rows = $wpdb->update(
			$this->table,
			array(
				'payment_status' => sanitize_key($status),
				'updated_at'     => current_time('mysql'),
			),
			array('id' => $id),
			array('%s', '%s'),
			array('%d')
		);
		wp_cache_delete('entry_' . $id, self::CACHE_GROUP);
		return false !== $rows;
	}

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; cache invalidated below.
		$rows = $wpdb->update(
			$this->table,
			$data,
			array('id' => $id),
			$formats,
			array('%d')
		);
		wp_cache_delete('entry_' . $id, self::CACHE_GROUP);
		return false !== $rows;
	}

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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; cache invalidated below.
		$rows = $wpdb->update(
			$this->table,
			$data,
			array('id' => $id),
			$formats,
			array('%d')
		);
		wp_cache_delete('entry_' . $id, self::CACHE_GROUP);
		return false !== $rows;
	}

	public function delete(int $id): bool
	{
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; cache invalidated below.
		$rows = $wpdb->delete(
			$this->table,
			array('id' => $id),
			array('%d')
		);
		wp_cache_delete('entry_' . $id, self::CACHE_GROUP);
		return false !== $rows && $rows > 0;
	}

	/**
	 * Delete multiple entries by their primary keys.
	 *
	 * Uses individual $wpdb->delete() calls (the WP abstraction layer) instead of a
	 * raw DELETE ... IN() query — WPCS-safe escaping per row, cache invalidated per entry.
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
		$deleted = 0;
		foreach ($ids as $id) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; cache invalidated on the next line.
			$rows = $wpdb->delete($this->table, array('id' => $id), array('%d'));
			if (false !== $rows && $rows > 0) {
				wp_cache_delete('entry_' . $id, self::CACHE_GROUP);
				$deleted++;
			}
		}
		return $deleted;
	}

	/**
	 * Update payment_status for multiple entries.
	 *
	 * Uses individual $wpdb->update() calls (the WP abstraction layer) instead of a
	 * raw UPDATE ... IN() query — WPCS-safe escaping per row, cache invalidated per entry.
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
		$updated = 0;
		$now     = current_time('mysql');
		foreach ($ids as $id) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; cache invalidated on the next line.
			$rows = $wpdb->update(
				$this->table,
				array('payment_status' => $status, 'updated_at' => $now),
				array('id' => $id),
				array('%s', '%s'),
				array('%d')
			);
			if (false !== $rows) {
				wp_cache_delete('entry_' . $id, self::CACHE_GROUP);
				$updated++;
			}
		}
		return $updated;
	}

	/**
	 * Retrieve a single entry by its primary key.
	 *
	 * @param int $id Entry ID.
	 * @return EntryDto|null The entry DTO, or null if not found.
	 */
	public function get_by_id(int $id): ?EntryDto
	{
		$cached = wp_cache_get('entry_' . $id, self::CACHE_GROUP);
		if (false !== $cached) {
			return $cached instanceof EntryDto ? $cached : null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API exists for this operation.
		$row    = $wpdb->get_row(
			$wpdb->prepare('SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id),
			ARRAY_A
		);
		$result = is_array($row) ? EntryDto::from($row) : null;
		wp_cache_set('entry_' . $id, $result ?? false, self::CACHE_GROUP, self::ENTRY_CACHE_TTL);
		return $result;
	}

	/**
	 * Return a page of entries, optionally filtered and searched.
	 *
	 * When orderby='id' keyset/cursor pagination is used (O(1) regardless of page depth).
	 * When orderby is any other column, classic OFFSET pagination is used.
	 * Always returns per_page+1 rows so the caller can detect has_more.
	 *
	 * @param int    $page         Display page counter (OFFSET mode only).
	 * @param int    $per_page     Rows per page; method fetches N+1 to detect has_more.
	 * @param string $status       Filter by payment_status ('' = all).
	 * @param string $search_field Column to search in.
	 * @param string $search_op    'contains' or 'is'.
	 * @param string $search_query Search term.
	 * @param int    $cursor       Boundary ID for keyset pagination (0 = first page).
	 * @param string $dir          'next' or 'prev'.
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

		$cache_key = 'get_all_' . md5(serialize(array($page, $per_page, $status, $search_field, $search_op, $search_query, $period, $orderby, $order, $cursor, $dir, $form_id)));
		$cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
		if (false !== $cached) {
			return is_array($cached) ? $cached : array();
		}


		$where_sql  = $this->build_where($status, $search_field, $search_op, $search_query, $form_id);
		$period_sql = $this->period_condition($period, $status, true);
		if ($period_sql !== '') {
			$where_sql = $where_sql === '' ? ' WHERE ' . $period_sql : $where_sql . ' AND ' . $period_sql;
		}

		$allowed_order_cols = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$orderby_col        = in_array($orderby, $allowed_order_cols, true) ? $orderby : 'id';
		$rows               = array();

		if ($orderby_col === 'id') {
			if ($cursor > 0 || $page === 1) {
				if ($cursor > 0) {

					if ($order === 'asc') {
						$cursor_prepared = $dir === 'prev'
							? $wpdb->prepare('id < %d', $cursor)
							: $wpdb->prepare('id > %d', $cursor);
					} else {
						$cursor_prepared = $dir === 'prev'
							? $wpdb->prepare('id > %d', $cursor)
							: $wpdb->prepare('id < %d', $cursor);
					}
					$where_sql = $where_sql === '' ? ' WHERE ' . $cursor_prepared : $where_sql . ' AND ' . $cursor_prepared;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Results cached above.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							'SELECT id, form_id, form_title, customer_name, customer_email, customer_ip, amount, payment_method, payment_status, payment_url, return_url, request_id, created_at, updated_at FROM %i' . $where_sql . ' ORDER BY id ' . ( $order === 'asc' ? ( $dir === 'prev' ? 'DESC' : 'ASC' ) : ( $dir === 'prev' ? 'ASC' : 'DESC' ) ) . ' LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is assembled from $wpdb->prepare() output with validated inputs; dynamic runtime WHERE clauses cannot be expressed as static SQL literals.
							$this->table,
							$fetch
						),
						ARRAY_A
					);
				} else {

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Results cached above.
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							'SELECT id, form_id, form_title, customer_name, customer_email, customer_ip, amount, payment_method, payment_status, payment_url, return_url, request_id, created_at, updated_at FROM %i' . $where_sql . ' ORDER BY id ' . ( $order === 'asc' ? 'ASC' : 'DESC' ) . ' LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is assembled from $wpdb->prepare() output with validated inputs; dynamic runtime WHERE clauses cannot be expressed as static SQL literals.
							$this->table,
							$fetch
						),
						ARRAY_A
					);
				}
			} else {

				$page   = max(1, $page);
				$offset = absint(($page - 1) * $per_page);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Results cached above.
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT id, form_id, form_title, customer_name, customer_email, customer_ip, amount, payment_method, payment_status, payment_url, return_url, request_id, created_at, updated_at FROM %i' . $where_sql . ' ORDER BY id ' . ( $order === 'asc' ? 'ASC' : 'DESC' ) . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is assembled from $wpdb->prepare() output with validated inputs; dynamic runtime WHERE clauses cannot be expressed as static SQL literals.
						$this->table,
						$fetch,
						$offset
					),
					ARRAY_A
				);
			}
		} else {

			$page   = max(1, $page);
			$offset = absint(($page - 1) * $per_page);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Results cached above.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, form_id, form_title, customer_name, customer_email, customer_ip, amount, payment_method, payment_status, payment_url, return_url, request_id, created_at, updated_at FROM %i' . $where_sql . ' ORDER BY %i ' . ( strtolower($order) === 'asc' ? 'ASC' : 'DESC' ) . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where_sql is assembled from $wpdb->prepare() output with validated inputs; dynamic runtime WHERE clauses cannot be expressed as static SQL literals.
					$this->table,
					$orderby_col,
					$fetch,
					$offset
				),
				ARRAY_A
			);
		}

		$result = is_array($rows) ? array_map(array(EntryDto::class, 'from'), $rows) : array();
		wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
		return $result;
	}

	/**
	 * Count and sum in one query — replaces calling count_all() + sum_amount() separately.
	 *
	 * Result cached for CACHE_TTL seconds when there is no search term.
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
					$stats['completed_any'] + $stats['pending_any'] + $stats['failed_any'] + $stats['cancelled_any'] + $stats['expired_any'],
					round($stats['completed_amount'] + $stats['pending_amount'] + $stats['failed_amount'] + $stats['cancelled_amount'] + $stats['expired_amount'], 2),
				];
			}
			if (isset($stats[$status . '_any'], $stats[$status . '_amount'])) {
				return [(int) $stats[$status . '_any'], round((float) $stats[$status . '_amount'], 2)];
			}
		}

		$cache_key = 'cnt_sum_' . md5(serialize(array($status, $search_field, $search_op, $search_query, $period, $form_id)));
		$cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
		if (false !== $cached && is_array($cached)) {
			return $cached;
		}

		$where_sql  = $this->build_where($status, $search_field, $search_op, $search_query, $form_id);
		$period_sql = $this->period_condition($period, $status, true);
		if ($period_sql !== '') {
			$where_sql = $where_sql === '' ? ' WHERE ' . $period_sql : $where_sql . ' AND ' . $period_sql;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table; no WP Core API. Results cached above. $where_sql is assembled from $wpdb->prepare() output with validated inputs; dynamic WHERE clauses cannot be expressed as static SQL literals.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM %i' . $where_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql is assembled from $wpdb->prepare() output with validated inputs; dynamic runtime WHERE clauses cannot be expressed as static SQL literals.
				$this->table
			),
			ARRAY_A
		);

		$result = array(
			(int)   ($row['cnt']   ?? 0),
			(float) ($row['total'] ?? 0.0),
		);
		wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
		return $result;
	}

	/**
	 * Return all period stats needed for the entries-page stats row in a single query.
	 *
	 * Replaces 8 individual count_period / sum_amount_period calls.
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

		$cache_key = 'stats_' . $period;
		$cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
		if (false !== $cached && is_array($cached)) {
			$this->stats_memo[$period] = $cached;
			return $cached;
		}

		global $wpdb;

		if ($period === 'all') {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Results cached above.
			$rows = $wpdb->get_results(
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
			wp_cache_set($cache_key, $this->stats_memo[$period], self::CACHE_GROUP, self::CACHE_TTL);
			return $this->stats_memo[$period];
		}

		[$any_cond, $created_cond, $updated_cond] = $this->period_conditions_triple($period);

		$where   = $any_cond     !== '' ? " WHERE ({$any_cond})"   : '';
		$cre_sql = $created_cond !== '' ? " AND ({$created_cond})" : '';
		$upd_sql = $updated_cond !== '' ? " AND ({$updated_cond})" : '';


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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table; no WP Core API. Results cached above. $sql contains only hardcoded SQL keywords and date literals from period_conditions_triple(); no user data is interpolated.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql contains only hardcoded SQL keywords and date literals from period_conditions_triple(); no user data is interpolated.
				$this->table
			),
			ARRAY_A
		);

		$z = array(
			'completed_any'    => 0,
			'pending_any'      => 0,
			'failed_any'       => 0,
			'cancelled_any'    => 0,
			'expired_any'      => 0,
			'completed_count'  => 0,
			'pending_count'    => 0,
			'failed_count'     => 0,
			'cancelled_count'  => 0,
			'expired_count'    => 0,
			'completed_amount' => 0.0,
			'pending_amount'   => 0.0,
			'failed_amount'    => 0.0,
			'cancelled_amount' => 0.0,
			'expired_amount'   => 0.0,
		);

		if (! is_array($row)) {
			$this->stats_memo[$period] = $z;
			wp_cache_set($cache_key, $z, self::CACHE_GROUP, self::CACHE_TTL);
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
		wp_cache_set($cache_key, $this->stats_memo[$period], self::CACHE_GROUP, self::CACHE_TTL);
		return $this->stats_memo[$period];
	}

	/**
	 * Return dashboard-widget stats for all four periods in a single query.
	 *
	 * @return array<string, array{revenue: float, counts: array<string, int>}>
	 *   Keys: '1', '7', '15', '30' (day counts).
	 */
	public function get_widget_period_stats(): array
	{
		$cache_key = 'widget_stats';
		$cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
		if (false !== $cached && is_array($cached)) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Results cached above.
		$row = $wpdb->get_row(
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
			$result = array('1' => $empty, '7' => $empty, '15' => $empty, '30' => $empty);
			wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
			return $result;
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
		wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL);
		return $result;
	}

	/**
	 * Returns a safe, hardcoded SQL snippet for the requested period (no user data).
	 *
	 * Always filters on updated_at — sargable range scan used by idx_updated_status_amount.
	 * $status and $any_activity kept for backwards-compatibility; they no longer affect output.
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
	 * All three values are now the same updated_at condition so idx_updated_status_amount
	 * can do a covering range scan instead of an index merge / full scan.
	 *
	 * @return array{string, string, string}
	 */
	private function period_conditions_triple(string $period): array
	{
		$c = $this->period_condition($period);
		return array($c, $c, $c);
	}

	/**
	 * Build a pre-prepared WHERE clause string.
	 *
	 * Each condition is processed through $wpdb->prepare() individually before concatenation,
	 * so no raw user input is ever embedded in the returned string. Field names are validated
	 * against an explicit allowlist before use.
	 *
	 * @param string $status       Filter by payment_status ('' = all).
	 * @param string $search_field Column to search in.
	 * @param string $search_op    'contains' or 'is'.
	 * @param string $search_query Search term.
	 * @param int    $form_id      Filter by form_id (0 = all).
	 * @return string Pre-prepared WHERE clause, or empty string if no conditions apply.
	 */
	private function build_where(string $status, string $search_field = '', string $search_op = 'contains', string $search_query = '', int $form_id = 0): string
	{
		global $wpdb;
		$conditions = array();

		if ('' !== $status) {
			$conditions[] = $wpdb->prepare('payment_status = %s', sanitize_key($status));
		}

		if ($form_id > 0) {
			$conditions[] = $wpdb->prepare('form_id = %d', $form_id);
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
				$conditions[] = $wpdb->prepare('%i = %s', $search_field, $search_query);
			} else {
				$conditions[] = $wpdb->prepare('%i LIKE %s', $search_field, '%' . $wpdb->esc_like($search_query) . '%');
			}
		}

		if (empty($conditions)) {
			return '';
		}

		return ' WHERE ' . implode(' AND ', $conditions);
	}

	/**
	 * Mark pending entries as expired when their creation date is older than $expire_days days.
	 * Mirrors ifthenpay's server-side expiry, which fires at 23:59 on the expiry date.
	 *
	 * @param int $expire_days Number of days after which a pending entry is considered expired.
	 * @return int Number of rows updated.
	 */
	public function mark_expired_pending(int $expire_days): int
	{
		global $wpdb;
		$days = max(1, $expire_days);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Cache busted below.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET payment_status = 'expired', updated_at = NOW() WHERE payment_status = 'pending' AND DATE(created_at) <= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
				$this->table,
				$days
			)
		);
		wp_cache_delete('stats_all', self::CACHE_GROUP);
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
		$cache_key = 'form_ids';
		$cached    = wp_cache_get($cache_key, self::CACHE_GROUP);
		if (false !== $cached && is_array($cached)) {
			return $cached;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom plugin table; no WP Core API. Results cached above.
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DISTINCT form_id FROM %i WHERE form_id > 0 ORDER BY form_id ASC',
				$this->table
			),
			ARRAY_A
		);
		$result = array_map('intval', array_column(is_array($rows) ? $rows : array(), 'form_id'));
		wp_cache_set($cache_key, $result, self::CACHE_GROUP, self::ENTRY_CACHE_TTL);
		return $result;
	}
}
