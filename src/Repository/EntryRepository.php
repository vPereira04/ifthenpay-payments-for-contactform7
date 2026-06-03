<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Repository\DTO\EntryDto;

final class EntryRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . IFTP_CF7_TABLE;
	}



	public function create( EntryDto $dto ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$this->table,
			[
				'form_id'        => $dto->form_id,
				'form_title'     => $dto->form_title,
				'customer_name'  => $dto->customer_name,
				'customer_email' => $dto->customer_email,
				'amount'         => number_format( $dto->amount, 2, '.', '' ),
				'payment_method' => $dto->payment_method,
				'transaction_id' => $dto->transaction_id,
				'payment_status' => $dto->payment_status,
				'payment_url'    => $dto->payment_url,
				'return_url'     => $dto->return_url,
				'form_data'      => $dto->form_data,
				'modal_token'    => $dto->modal_token,
				'is_read'        => $dto->is_read ? 1 : 0,
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( $wpdb->last_error ) {
			error_log( 'iftp_cf7: DB insert failed: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}

		return (int) $wpdb->insert_id;
	}



	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		$rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			[ 'payment_status' => sanitize_key( $status ), 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		return $rows !== false;
	}

	public function update_transaction( int $id, string $transaction_id, string $payment_method = '', string $status = 'completed', ?string $request_id = null ): bool {
		global $wpdb;
		$data    = [ 'transaction_id' => $transaction_id, 'payment_status' => sanitize_key( $status ), 'updated_at' => current_time( 'mysql' ) ];
		$formats = [ '%s', '%s', '%s' ];
		if ( $payment_method !== '' ) {
			$data['payment_method'] = strtoupper( sanitize_text_field( $payment_method ) );
			$formats[]              = '%s';
		}
		if ( $request_id !== null ) {
			$data['request_id'] = sanitize_text_field( $request_id );
			$formats[]          = '%s';
		}
		$rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table, $data, [ 'id' => $id ], $formats, [ '%d' ]
		);
		return $rows !== false;
	}

	public function update_payment_url( int $id, string $payment_url, string $modal_token, string $method = '' ): bool {
		global $wpdb;
		$data    = [ 'payment_url' => esc_url_raw( $payment_url ), 'modal_token' => $modal_token, 'updated_at' => current_time( 'mysql' ) ];
		$formats = [ '%s', '%s', '%s' ];
		if ( $method !== '' ) {
			$data['payment_method'] = strtoupper( sanitize_text_field( $method ) );
			$formats[]              = '%s';
		}
		$rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table, $data, [ 'id' => $id ], $formats, [ '%d' ]
		);
		return $rows !== false;
	}



	public function mark_as_read( int $id ): bool {
		return $this->set_read_flag( $id, 1 );
	}

	public function mark_as_unread( int $id ): bool {
		return $this->set_read_flag( $id, 0 );
	}

	private function set_read_flag( int $id, int $flag ): bool {
		global $wpdb;
		$rows = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			[ 'is_read' => $flag, 'updated_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
		return $rows !== false;
	}

	/**
	 * @param int[] $ids
	 */
	public function bulk_mark_read( array $ids ): int {
		return $this->bulk_flag( $ids, 1 );
	}

	/**
	 * @param int[] $ids
	 */
	public function bulk_mark_unread( array $ids ): int {
		return $this->bulk_flag( $ids, 0 );
	}

	/**
	 * @param int[] $ids
	 */
	private function bulk_flag( array $ids, int $flag ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"UPDATE {$this->table} SET is_read = %d, updated_at = %s WHERE id IN ({$placeholders})",
			array_merge( [ $flag, current_time( 'mysql' ) ], $ids )
		);
		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}



	public function delete( int $id ): bool {
		global $wpdb;
		$rows = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table, [ 'id' => $id ], [ '%d' ]
		);
		return $rows !== false && $rows > 0;
	}

	/**
	 * @param int[] $ids
	 */
	public function bulk_delete( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			"DELETE FROM {$this->table} WHERE id IN ({$placeholders})",
			$ids
		);
		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}



	public function get_by_id( int $id ): ?EntryDto {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? EntryDto::from( $row ) : null;
	}

	public function get_by_transaction_id( string $txn_id ): ?EntryDto {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE transaction_id = %s LIMIT 1", $txn_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? EntryDto::from( $row ) : null;
	}

	public function get_by_token( string $token ): ?EntryDto {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE modal_token = %s LIMIT 1", $token ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? EntryDto::from( $row ) : null;
	}

	/**
	 * @return EntryDto[]
	 */
	public function get_all( int $page = 1, int $per_page = 20, string $status = '', string $read_filter = '', string $search_field = '', string $search_op = 'contains', string $search_query = '' ): array {
		global $wpdb;
		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * $per_page;
		$where  = $this->build_where( $status, $read_filter, $search_field, $search_op, $search_query );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table}{$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( [ EntryDto::class, 'from' ], $rows ) : [];
	}

	public function count_all( string $status = '', string $read_filter = '', string $search_field = '', string $search_op = 'contains', string $search_query = '' ): int {
		global $wpdb;
		$where = $this->build_where( $status, $read_filter, $search_field, $search_op, $search_query );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}{$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function sum_amount( string $status = '', string $read_filter = '', string $search_field = '', string $search_op = 'contains', string $search_query = '' ): float {
		global $wpdb;
		$where = $this->build_where( $status, $read_filter, $search_field, $search_op, $search_query );
		return (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$this->table}{$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function count_unread(): int {
		return $this->count_all( '', 'unread' );
	}



	/**
	 * Count how many entries fall in the id range [from, to].
	 */
	public function count_range( int $from_id, int $to_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE id BETWEEN %d AND %d", $from_id, $to_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Delete entries in the id range, up to $limit rows.
	 *
	 * @return int Number of rows deleted.
	 */
	public function delete_range( int $from_id, int $to_id, int $limit = 1000 ): int {
		global $wpdb;
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$this->table} WHERE id BETWEEN %d AND %d ORDER BY id ASC LIMIT %d",
				$from_id,
				$to_id,
				$limit
			)
		);
	}

	private function build_where( string $status, string $read_filter, string $search_field = '', string $search_op = 'contains', string $search_query = '' ): string {
		global $wpdb;
		$conditions = [];

		if ( $status !== '' ) {
			$conditions[] = $wpdb->prepare( 'payment_status = %s', sanitize_key( $status ) );
		}
		if ( $read_filter === 'unread' ) {
			$conditions[] = 'is_read = 0';
		} elseif ( $read_filter === 'read' ) {
			$conditions[] = 'is_read = 1';
		}


		$allowed_fields = [
			'customer_name', 'customer_email', 'form_title',
			'transaction_id', 'request_id', 'payment_method', 'amount',
		];
		if ( $search_field !== '' && $search_query !== '' && in_array( $search_field, $allowed_fields, true ) ) {
			if ( $search_op === 'is' ) {
				$conditions[] = $wpdb->prepare( "`{$search_field}` = %s", $search_query ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$conditions[] = $wpdb->prepare( "`{$search_field}` LIKE %s", '%' . $wpdb->esc_like( $search_query ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		return empty( $conditions ) ? '' : ' WHERE ' . implode( ' AND ', $conditions );
	}
}
