<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Admin;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

final class UserPreferences
{

	private const META_KEY = '_iftp_entries_preferences';

	/** @return array{time_range: string, status: string, orderby: string, order: string, column_positions: string[], row_density: string} */
	public static function defaults(): array
	{
		return array(
			'time_range'       => 'all',
			'status'           => '',
			'orderby'          => 'id',
			'order'            => 'desc',
			'column_positions' => array('id', 'customer_name', 'request_id', 'form_title', 'payment_method', 'amount', 'payment_status', 'payment_link', 'created_at'),
			'row_density'      => 'normal',
		);
	}

	/**
	 * Load stored preferences for a user, merged with defaults.
	 * Any columns added since the user last saved are appended to the end.
	 *
	 * @return array{time_range: string, status: string, orderby: string, order: string, column_positions: string[], row_density: string}
	 */
	public static function get(int $user_id): array
	{
		$raw  = get_user_meta($user_id, self::META_KEY, true);
		$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : array();
		if (! is_array($data)) {
			$data = array();
		}
		$merged = array_merge(self::defaults(), $data);


		$all_cols = self::defaults()['column_positions'];
		$stored   = array_values(
			array_filter(
				is_array($merged['column_positions']) ? $merged['column_positions'] : array(),
				fn(string $c) => in_array($c, $all_cols, true)
			)
		);
		$missing               = array_values(array_diff($all_cols, $stored));
		$merged['column_positions'] = array_merge($stored, $missing);


		$valid_time   = array('all', 'year', 'month', 'week', 'day');
		$valid_status = array('', 'pending', 'completed', 'failed', 'cancelled');
		$valid_ob     = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$valid_order  = array('asc', 'desc');
		$valid_dens   = array('compact', 'normal', 'large');

		$merged['time_range']  = in_array($merged['time_range'], $valid_time, true)   ? $merged['time_range']  : 'all';
		$merged['status']      = in_array($merged['status'], $valid_status, true)      ? $merged['status']      : '';
		$merged['orderby']     = in_array($merged['orderby'], $valid_ob, true)         ? $merged['orderby']     : 'id';
		$merged['order']       = in_array($merged['order'], $valid_order, true)        ? $merged['order']       : 'desc';
		$merged['row_density'] = in_array($merged['row_density'], $valid_dens, true)   ? $merged['row_density'] : 'normal';

		return $merged;
	}

	/** Persist the full preferences array for a user. */
	public static function save(int $user_id, array $prefs): bool
	{
		return (bool) update_user_meta($user_id, self::META_KEY, wp_json_encode($prefs));
	}

	/** Merge a partial preferences payload into the user's stored preferences. */
	public static function merge(int $user_id, array $partial): bool
	{
		$current = self::get($user_id);
		return self::save($user_id, array_merge($current, $partial));
	}
}
