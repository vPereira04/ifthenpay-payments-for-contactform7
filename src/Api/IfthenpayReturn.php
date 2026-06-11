<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Api;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

final class IfthenpayReturn
{

	public static function extract_payment_method_from_result(array $result): string
	{
		foreach (array('PaymentMethod', 'Method', 'Entity', 'paymentMethod', 'method', 'entity') as $key) {
			if (! empty($result[$key])) {
				return strtoupper(sanitize_text_field((string) $result[$key]));
			}
		}
		return '';
	}

	public static function is_successful_callback(array $payload): bool
	{
		$status = strtoupper(sanitize_text_field((string) ($payload['status'] ?? $payload['s'] ?? '')));
		return in_array($status, array('1', 'OK', 'SUCCESS', 'PAID'), true);
	}

	public static function get_callback_entry_id(array $payload): int
	{
		foreach (array('iftp_cf7_entry', 'entry_id', 'order_id', 'id') as $key) {
			if (! empty($payload[$key])) {
				return absint($payload[$key]);
			}
		}
		return 0;
	}

	private function __construct() {}
}
