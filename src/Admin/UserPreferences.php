<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Admin;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

final class UserPreferences
{

	private const META_KEY = '_iftp_entries_preferences';

	/** @return array{orderby: string, order: string, column_positions: string[], visible_columns: string[], rev_bar_mode: string} */
	public static function defaults(): array
	{
		$all_cols = array('id', 'customer_name', 'request_id', 'form_title', 'payment_method', 'amount', 'payment_status', 'payment_link', 'created_at');
		return array(
			'orderby'          => 'id',
			'order'            => 'desc',
			'column_positions' => $all_cols,
			'visible_columns'  => $all_cols,
			'rev_bar_mode'     => 'split',
		);
	}

	/**
	 * Load stored preferences for a user, merged with defaults.
	 * Any columns added since the user last saved are appended to the end.
	 *
	 * @return array{orderby: string, order: string, column_positions: string[], visible_columns: string[], rev_bar_mode: string}
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
		$stored_with_missing   = array_merge($stored, $missing);

		$fixed = array('id', 'customer_name');
		$merged['column_positions'] = array_merge(
			$fixed,
			array_values(array_filter($stored_with_missing, fn($c) => ! in_array($c, $fixed, true)))
		);


		if (! isset($merged['visible_columns']) || ! is_array($merged['visible_columns'])) {
			$merged['visible_columns'] = $all_cols;
		} else {
			$merged['visible_columns'] = array_values(
				array_filter($merged['visible_columns'], fn(string $c) => in_array($c, $all_cols, true))
			);
			if (empty($merged['visible_columns'])) {
				$merged['visible_columns'] = $all_cols;
			}
		}

		foreach ($fixed as $fc) {
			if (! in_array($fc, $merged['visible_columns'], true)) {
				$merged['visible_columns'][] = $fc;
			}
		}


		$valid_ob    = array('id', 'customer_name', 'form_title', 'payment_method', 'amount', 'payment_status', 'created_at');
		$valid_order = array('asc', 'desc');

		$merged['orderby']    = in_array($merged['orderby'], $valid_ob, true)    ? $merged['orderby'] : 'id';
		$merged['order']      = in_array($merged['order'], $valid_order, true)   ? $merged['order']   : 'desc';
		$merged['rev_bar_mode'] = in_array($merged['rev_bar_mode'] ?? 'split', array('split', 'solid'), true) ? ($merged['rev_bar_mode'] ?? 'split') : 'split';

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
