<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Payment;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Api\IfthenpayReturn;
use Ifthenpay\CF7\Repository\EntryRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint that receives payment status webhooks from ifthenpay.
 *
 * Anti-phishing key = base64_encode($gateway_key) — derived in Settings,
 * never stored. Validated here against the `chave` field sent by ifthenpay.
 */
final class Callback
{

	private const REST_NAMESPACE = 'ifthenpay-cf7/v1';
	private const REST_ROUTE     = '/callback';

	public function register_routes(): void
	{
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array($this, 'handle'),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle(WP_REST_Request $request): WP_REST_Response
	{
		$payload = $request->get_params();

		$expected = Settings::get_anti_phishing_key();
		if ($expected !== '') {
			$received = sanitize_text_field((string) ($payload['chave'] ?? $payload['key'] ?? $payload['sk'] ?? ''));
			if ($received !== $expected) {
				return new WP_REST_Response(array('error' => 'invalid_key'), 403);
			}
		}

		$entry_id   = IfthenpayReturn::get_callback_entry_id($payload);
		$is_success = IfthenpayReturn::is_successful_callback($payload);

		if ($entry_id <= 0) {
			return new WP_REST_Response(array('error' => 'missing_parameters'), 400);
		}

		$repo  = new EntryRepository();
		$entry = $repo->get_by_id($entry_id);

		if ($entry === null) {
			return new WP_REST_Response(array('error' => 'entry_not_found'), 404);
		}

		$val = sanitize_text_field((string) ($payload['val'] ?? $payload['amount'] ?? ''));
		if ($val === '') {
			return new WP_REST_Response(array('error' => 'missing_amount'), 400);
		}
		if (number_format((float) $val, 2, '.', '') !== number_format($entry->amount, 2, '.', '')) {
			return new WP_REST_Response(array('error' => 'amount_mismatch'), 403);
		}

		if ($is_success) {

			if ($entry->payment_status === 'completed') {
				return new WP_REST_Response(array('status' => 'ok'), 200);
			}

			$method     = sanitize_text_field((string) ($payload['PaymentMethod'] ?? $payload['Method'] ?? ''));
			$request_id = sanitize_text_field((string) ($payload['RequestId'] ?? $payload['requestId'] ?? $payload['req'] ?? ''));
			$repo->update_transaction($entry->id, $method, 'completed', $request_id !== '' ? $request_id : null);

			/** @fires iftp_cf7_payment_confirmed after ifthenpay confirms payment via webhook */
			do_action('iftp_cf7_payment_confirmed', $entry->id, $method);
		} else {

			if ($entry->payment_status === 'completed') {
				return new WP_REST_Response(array('status' => 'ok'), 200);
			}

			$repo->update_status($entry->id, 'failed');
			do_action('iftp_cf7_payment_failed', $entry->id);
		}

		return new WP_REST_Response(array('status' => 'ok'), 200);
	}

	public static function get_callback_url(): string
	{
		return rest_url(self::REST_NAMESPACE . self::REST_ROUTE);
	}
}
