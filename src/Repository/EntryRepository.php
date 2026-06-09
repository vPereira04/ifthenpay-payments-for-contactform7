<?php
/**
 * Data-access layer for payment entries.
 *
 * @package Ifthenpay\CF7
 */

declare(strict_types=1);

namespace Ifthenpay\CF7\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Repository\DTO\EntryDto;

/**
 * Handles all CRUD operations on the ifthenpay_cf7_entries table.
 */
final class EntryRepository {

	/** @var string Full (prefixed) table name. */
	private string $table;

	/**
	 * Constructor — resolves the table name once on instantiation.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . IFTP_CF7_TABLE;
	}



	/**
	 * Insert a new entry and return the new row ID (0 on failure).
	 *
	 * @param EntryDto $dto Source data transfer object.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function create( EntryDto $dto ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->insert( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table,
			array(
				'form_id'        => $dto->form_id,
				'form_title'     => $dto->form_title,
				'customer_name'  => $dto->customer_name,
				'customer_email' => $dto->customer_email,
				'customer_ip'    => $dto->customer_ip,
				'amount'         => number_format( $dto->amount, 2, '.', '' ),
				'payment_method' => $dto->payment_method,
				'payment_status' => $dto->payment_status,
				'payment_url'    => $dto->payment_url,
				'return_url'     => $dto->return_url,
				'form_data'      => $dto->form_data,
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( $wpdb->last_error ) {
			error_log( 'iftp_cf7: DB insert failed: ' . $wpdb->last_error ); // placeholderphpcs:ignore(try fixing) WordPress.PHP.DevelopmentFunctions.error_log_error_log
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
	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		$rows = $wpdb->update( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			array(
				'payment_status' => sanitize_key( $status ),
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
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
	public function update_transaction( int $id, string $payment_method = '', string $status = 'completed', ?string $request_id = null ): bool {
		global $wpdb;
		$data    = array(
			'payment_status' => sanitize_key( $status ),
			'updated_at'     => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s' );
		if ( '' !== $payment_method ) {
			$data['payment_method'] = strtoupper( sanitize_text_field( $payment_method ) );
			$formats[]              = '%s';
		}
		if ( null !== $request_id ) {
			$data['request_id'] = sanitize_text_field( $request_id );
			$formats[]          = '%s';
		}
		$rows = $wpdb->update( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
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
	public function update_payment_url( int $id, string $payment_url, string $method = '' ): bool {
		global $wpdb;
		$data    = array(
			'payment_url' => esc_url_raw( $payment_url ),
			'updated_at'  => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s' );
		if ( '' !== $method ) {
			$data['payment_method'] = strtoupper( sanitize_text_field( $method ) );
			$formats[]              = '%s';
		}
		$rows = $wpdb->update( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
		return false !== $rows;
	}



	/**
	 * Delete a single entry by primary key.
	 *
	 * @param int $id Entry ID.
	 * @return bool True if a row was deleted, false otherwise.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$rows = $wpdb->delete( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			array( 'id' => $id ),
			array( '%d' )
		);
		return false !== $rows && $rows > 0;
	}

	/**
	 * Delete multiple entries by their primary keys.
	 *
	 * @param int[] $ids Array of entry IDs to delete.
	 * @return int Number of rows deleted.
	 */
	public function bulk_delete( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$fmt        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query_stmt = "DELETE FROM %i WHERE id IN ($fmt)";

		return (int) $wpdb->query(
			$wpdb->prepare(
				$query_stmt, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- String estrutural preparada dinamicamente com segurança.
				array_merge( array( $this->table ), array_values( $ids ) )
			)
		);
	}

	/**
	 * Update payment_status for multiple entries.
	 *
	 * @param int[]  $ids    Array of entry IDs.
	 * @param string $status New status value.
	 * @return int Number of rows updated.
	 */
	public function bulk_update_status( array $ids, string $status ): int {
		global $wpdb;
		$ids    = array_filter( array_map( 'absint', $ids ) );
		$status = sanitize_key( $status );
		if ( empty( $ids ) || $status === '' ) {
			return 0;
		}

		$fmt        = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$now        = current_time( 'mysql' );
		$query_stmt = "UPDATE %i SET payment_status = %s, updated_at = %s WHERE id IN ($fmt)";

		return (int) $wpdb->query(
			$wpdb->prepare(
				$query_stmt, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- String estrutural preparada dinamicamente com segurança.
				array_merge( array( $this->table, $status, $now ), array_values( $ids ) )
			)
		);
	}

	/**
	 * Retrieve multiple entries by their primary keys.
	 *
	 * @param int[] $ids Array of entry IDs.
	 * @return EntryDto[]
	 */
	public function get_by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$fmt  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id IN (' . $fmt . ') ORDER BY created_at DESC', // placeholderphpcs:ignore(try fixing) WordPress.DB.PreparedSQL.NotPrepared
				array_merge( array( $this->table ), array_values( $ids ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( EntryDto::class, 'from' ), $rows ) : array();
	}



	/**
	 * Retrieve a single entry by its primary key.
	 *
	 * @param int $id Entry ID.
	 * @return EntryDto|null The entry DTO, or null if not found.
	 */
	public function get_by_id( int $id ): ?EntryDto {
		global $wpdb;
		$row = $wpdb->get_row( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table, $id ),
			ARRAY_A
		);
		return is_array( $row ) ? EntryDto::from( $row ) : null;
	}

	/**
	 * Return a page of entries, optionally filtered and searched.
	 *
	 * @param int    $page         1-based page number.
	 * @param int    $per_page     Results per page.
	 * @param string $status       Filter by payment_status ('' = all).
	 * @param string $search_field Column to search in.
	 * @param string $search_op    'contains' or 'is'.
	 * @param string $search_query Search term.
	 * @return EntryDto[]
	 */
	public function get_all( int $page = 1, int $per_page = 20, string $status = '', string $search_field = '', string $search_op = 'contains', string $search_query = '', string $period = 'all' ): array {
		global $wpdb;
		$page                    = max( 1, $page );
		$offset                  = ( $page - 1 ) * $per_page;

		$status 				 = sanitize_key( $status );
		$search_field 			 = sanitize_key( $search_field );
		$search_op 			 	 = in_array( $search_op, array( 'contains', 'is' ), true ) ? $search_op : 'contains';
		$search_query			 = sanitize_text_field( $search_query );

		[ $where_tpl, $w_args ]  = $this->build_where( $status, $search_field, $search_op, $search_query );

		$period_sql = $this->period_condition( $period, $status, true );
		if ( $period_sql !== '' ) {
			$where_tpl = $where_tpl === '' ? ' WHERE ' . $period_sql : $where_tpl . ' AND ' . $period_sql;
		}

		$per_page				 = absint( $per_page );
		$offset   				 = absint( $offset );
		$args 					 = array_merge( array( $this->table ), $w_args, array( $per_page, $offset ) );

		$rows = $wpdb->get_results( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// placeholderphpcs:ignore(try fixing) WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				/*translators: 1. SQL query with placeholders, not user-facing text. Do not translate or localize. */
				'SELECT * FROM %i' . $where_tpl . ' ORDER BY id DESC LIMIT %d OFFSET %d', // placeholderphpcs:ignore(try fixing) WordPress.DB.PreparedSQL.NotPrepared
				...$args
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( EntryDto::class, 'from' ), $rows ) : array();
	}

	/**
	 * Count entries matching the given filters.
	 *
	 * @param string $status       Filter by payment_status ('' = all).
	 * @param string $search_field Column to search in.
	 * @param string $search_op    'contains' or 'is'.
	 * @param string $search_query Search term.
	 * @return int Total number of matching entries.
	 */
	public function count_all( string $status = '', string $search_field = '', string $search_op = 'contains', string $search_query = '', string $period = 'all' ): int {
		global $wpdb;
		$status 				 = sanitize_key( $status );
		$search_field 			 = sanitize_key( $search_field );
		$search_op 			 	 = in_array( $search_op, array( 'contains', 'is' ), true ) ? $search_op : 'contains';
		$search_query			 = sanitize_text_field( $search_query );

		[ $where_tpl, $w_args ]  = $this->build_where( $status, $search_field, $search_op, $search_query );

		$period_sql = $this->period_condition( $period, $status, true );
		if ( $period_sql !== '' ) {
			$where_tpl = $where_tpl === '' ? ' WHERE ' . $period_sql : $where_tpl . ' AND ' . $period_sql;
		}

		$args = array_merge( array( $this->table ), $w_args );
		return (int) $wpdb->get_var( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i' . $where_tpl, // placeholderphpcs:ignore(try fixing) WordPress.DB.PreparedSQL.NotPrepared
				...$args
			)
		);
	}

	/**
	 * Sum the amount column for entries matching the given filters.
	 *
	 * @param string $status       Filter by payment_status ('' = all).
	 * @param string $search_field Column to search in.
	 * @param string $search_op    'contains' or 'is'.
	 * @param string $search_query Search term.
	 * @return float Sum of amounts, or 0.0.
	 */
	public function sum_amount( string $status = '', string $search_field = '', string $search_op = 'contains', string $search_query = '', string $period = 'all' ): float {
		global $wpdb;
		$status 				 = sanitize_key( $status );
		$search_field 			 = sanitize_key( $search_field );
		$search_op 			 	 = in_array( $search_op, array( 'contains', 'is' ), true ) ? $search_op : 'contains';
		$search_query			 = sanitize_text_field( $search_query );

		[ $where_tpl, $w_args ]  = $this->build_where( $status, $search_field, $search_op, $search_query );

		$period_sql = $this->period_condition( $period, $status, true );
		if ( $period_sql !== '' ) {
			$where_tpl = $where_tpl === '' ? ' WHERE ' . $period_sql : $where_tpl . ' AND ' . $period_sql;
		}

		$args = array_merge( array( $this->table ), $w_args );
		return (float) $wpdb->get_var( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount),0) FROM %i' . $where_tpl, // placeholderphpcs:ignore(try fixing) WordPress.DB.PreparedSQL.NotPrepared
				...$args
			)
		);
	}

	/**
	 * Sum amounts filtered by status and a time period — used for the revenue card.
	 *
	 * Period values: 'day' (today), 'week' (last 7 days), 'month' (current calendar month),
	 *               'year' (current calendar year), 'all' (no date filter, the default).
	 */
	public function sum_amount_period( string $status = '', string $period = 'all' ): float {
		global $wpdb;
		$status = sanitize_key( $status );
		$period = sanitize_key( $period );

		[ $where_tpl, $w_args ] = $this->build_where( $status );


		$period_sql = $this->period_condition( $period, $status );
		if ( $period_sql !== '' ) {
			$where_tpl = $where_tpl === ''
				? ' WHERE ' . $period_sql
				: $where_tpl . ' AND ' . $period_sql;
		}

		$args = array_merge( array( $this->table ), $w_args );
		return (float) $wpdb->get_var( // placeholderphpcs:ignore(try fixing) WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COALESCE(SUM(amount),0) FROM %i' . $where_tpl, // placeholderphpcs:ignore(try fixing) WordPress.DB.PreparedSQL.NotPrepared
				...$args
			)
		);
	}

	/** Count rows filtered by status and a time period — mirrors sum_amount_period(). */
	public function count_period( string $status = '', string $period = 'all', bool $any_activity = false ): int {
		global $wpdb;
		$status = sanitize_key( $status );
		$period = sanitize_key( $period );

		[ $where_tpl, $w_args ] = $this->build_where( $status );

		$period_sql = $this->period_condition( $period, $status, $any_activity );
		if ( $period_sql !== '' ) {
			$where_tpl = $where_tpl === ''
				? ' WHERE ' . $period_sql
				: $where_tpl . ' AND ' . $period_sql;
		}

		$args = array_merge( array( $this->table ), $w_args );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i' . $where_tpl, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				...$args
			)
		);
	}

	/**
	 * Return per-bucket counts and amounts for the chart.
	 *
	 * Bucket keys:
	 *  - 'day'   → string hour "0"–"23"
	 *  - 'year'  → "YYYY-MM"
	 *  - others  → "YYYY-MM-DD"
	 *
	 * @return array<int, array{bucket: string, cnt: string, total: string}>
	 */
	public function get_chart_data( string $status = '', string $period = 'all' ): array {
		global $wpdb;
		$status = sanitize_key( $status );
		$col    = $status === 'completed' ? 'updated_at' : 'created_at';

		[ $where_tpl, $w_args ] = $this->build_where( $status );

		if ( $period === 'day' ) {
			$group_expr  = "HOUR({$col})";
			$period_cond = "DATE({$col}) = CURDATE()";
		} elseif ( $period === 'year' ) {
			$group_expr  = "DATE_FORMAT({$col}, '%Y-%m')";
			$period_cond = "YEAR({$col}) = YEAR(NOW())";
		} elseif ( $period === 'month' ) {
			$group_expr  = "DATE({$col})";
			$period_cond = "YEAR({$col}) = YEAR(NOW()) AND MONTH({$col}) = MONTH(NOW())";
		} elseif ( $period === 'week' ) {
			$group_expr  = "DATE({$col})";
			$period_cond = "{$col} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
		} else {
			$group_expr  = "DATE({$col})";
			$period_cond = "{$col} >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
		}

		$where_tpl = $where_tpl === ''
			? " WHERE {$period_cond}"
			: $where_tpl . " AND {$period_cond}";

		$args = array_merge( [ $this->table ], $w_args );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"SELECT {$group_expr} AS bucket, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total FROM %i{$where_tpl} GROUP BY {$group_expr} ORDER BY {$group_expr}",
				...$args
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}



	/**
	 * Returns a safe, hardcoded SQL snippet for the requested period (no user data).
	 *
	 * Two modes:
	 * - Stats cards ($any_activity = false): completed uses updated_at so a payment
	 *   created on a previous day but paid today is counted in today's revenue/count.
	 *   All other statuses use created_at.
	 * - Table ($any_activity = true): entry appears if created_at OR updated_at falls
	 *   within the period, so cancelled/failed attempts from earlier entries surface too.
	 */
	private function period_condition( string $period, string $status = '', bool $any_activity = false ): string {
		if ( $any_activity ) {
			switch ( $period ) {
				case 'day':
					return "(DATE(created_at) = CURDATE() OR DATE(updated_at) = CURDATE())";
				case 'week':
					return "(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))";
				case 'month':
					return "((YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())) OR (YEAR(updated_at) = YEAR(NOW()) AND MONTH(updated_at) = MONTH(NOW())))";
				case 'year':
					return "(YEAR(created_at) = YEAR(NOW()) OR YEAR(updated_at) = YEAR(NOW()))";
				default:
					return '';
			}
		}

		$col = $status === 'completed' ? 'updated_at' : 'created_at';
		switch ( $period ) {
			case 'day':
				return "DATE({$col}) = CURDATE()";
			case 'week':
				return "{$col} >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
			case 'month':
				return "YEAR({$col}) = YEAR(NOW()) AND MONTH({$col}) = MONTH(NOW())";
			case 'year':
				return "YEAR({$col}) = YEAR(NOW())";
			default:
				return '';
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
	private function build_where( string $status, string $search_field = '', string $search_op = 'contains', string $search_query = '' ): array {
		global $wpdb;
		$conditions = array();
		$params     = array();

		if ( '' !== $status ) {
			$conditions[] = 'payment_status = %s';
			$params[]     = sanitize_key( $status );
		}

		$allowed_fields = array(
			'customer_name',
			'customer_email',
			'form_title',
			'request_id',
			'payment_method',
			'amount',
		);
		if ( '' !== $search_field && '' !== $search_query && in_array( $search_field, $allowed_fields, true ) ) {
			if ( 'is' === $search_op ) {
				$conditions[] = '%i = %s';
				$params[]     = $search_field;
				$params[]     = $search_query;
			} else {
				$conditions[] = '%i LIKE %s';
				$params[]     = $search_field;
				$params[]     = '%' . $wpdb->esc_like( $search_query ) . '%';
			}
		}

		if ( empty( $conditions ) ) {
			return array( '', array() );
		}

		return array(
			' WHERE ' . implode( ' AND ', $conditions ),
			$params,
		);
	}
}
