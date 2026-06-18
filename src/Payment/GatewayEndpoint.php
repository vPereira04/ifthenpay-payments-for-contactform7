<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Payment;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Repository\EntryRepository;

/**
 * Handles the /iftp_callback/{ref} endpoint — the unified return + webhook URL.
 *
 * Success (browser redirect from ifthenpay):
 *   /iftp_callback/{entry_id}/?apk={base64(gateway_key)}&val={amount}&ret={form_url}&mtd=[PAYMENTMETHOD]&req=[REQUESTID]
 *
 * Cancel / Error (browser redirect):
 *   /iftp_callback/{entry_id}/?status={cancel|error}&ret={form_url}
 *
 * Server webhook (POST from ifthenpay, no browser):
 *   Same params as success — validated by apk, updates entry, returns 200.
 *
 * After processing a browser redirect the handler redirects back to the
 * form page (ret param) with ?iftp_cf7_pay=success|cancel|error&iftp_cf7_entry={id}.
 * The frontend JS then shows the appropriate message.
 */
final class GatewayEndpoint
{

	public const QUERY_VAR = 'iftp_cf7_ep';
	public const SLUG      = 'iftp_callback';
	public const REF_VAR   = 'iftp_cf7_ref';



	public static function register(): void
	{
		add_action('init', array(self::class, 'add_rewrite_rule'));
		add_filter('query_vars', array(self::class, 'add_query_vars'));
		add_action('template_redirect', array(self::class, 'handle'), 1);
	}

	public static function add_rewrite_rule(): void
	{
		add_rewrite_rule(
			'^' . self::SLUG . '/([^/]+)/?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::REF_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function flush(): void
	{
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/** @param string[] $vars */
	public static function add_query_vars(array $vars): array
	{
		$vars[] = self::QUERY_VAR;
		$vars[] = self::REF_VAR;
		return $vars;
	}

	public static function handle(): void
	{
		if (! get_query_var(self::QUERY_VAR)) {
			return;
		}

		$request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'GET';
		$method         = strtoupper((string) $request_method);

		$entry_id = absint(get_query_var(self::REF_VAR));
		$apk      = sanitize_text_field(wp_unslash((string) ($_REQUEST['apk'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callback; nonce not applicable.

		$val    = sanitize_text_field(wp_unslash((string) ($_REQUEST['val'] ?? $_REQUEST['amount'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callback; nonce not applicable.
		$status = sanitize_key(wp_unslash($_REQUEST['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callback; nonce not applicable.
		$ret    = esc_url_raw(wp_unslash((string) ($_REQUEST['ret'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callback; nonce not applicable.
		$mtd    = sanitize_text_field(wp_unslash((string) ($_REQUEST['mtd'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callback; nonce not applicable.

		$req    = sanitize_text_field(wp_unslash((string) ($_REQUEST['req'] ?? $_REQUEST['requestId'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callback; nonce not applicable.

		$error_msg = sanitize_text_field(wp_unslash((string) ($_REQUEST['error'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Payment gateway callback; nonce not applicable.
		if ($status === '' && $error_msg !== '') {
			$status = 'error';
		}

		if ($ret !== '' && strpos($ret, home_url()) !== 0) {
			$ret = '';
		}

		$entry  = null;
		$anchor = '';
		if ($entry_id > 0) {
			$repo  = new EntryRepository();
			$entry = $repo->get_by_id($entry_id);
			if ($entry !== null) {
				if ($ret === '' && $entry->return_url !== '') {
					$ret = $entry->return_url;
				}
				if ($entry->form_id > 0) {
					$anchor = '#iftp-msg';
				}
			}
		}
		if ($ret === '') {
			$ret = home_url('/');
		}

		if ($method === 'POST') {

			self::handle_webhook($entry_id, $apk, $mtd, $req);
			echo 'OK';
			exit;
		}

		if ($apk !== '' && $val !== '' && $status === '') {

			self::process_success($entry_id, $apk, $val, $mtd, $req);
			wp_safe_redirect(
				add_query_arg(
					array(
						'iftp_cf7_pay'   => 'success',
						'iftp_cf7_entry' => $entry_id,
					),
					$ret
				) . $anchor
			);
			exit;
		}

		if ($status !== '') {

			$new_status = $status === 'cancel' ? 'cancelled' : 'failed';
			self::process_other($entry_id, $new_status, $mtd, $req);
			wp_safe_redirect(
				add_query_arg(
					array(
						'iftp_cf7_pay'   => $status,
						'iftp_cf7_entry' => $entry_id,
					),
					$ret
				) . $anchor
			);
			exit;
		}

		wp_safe_redirect(home_url('/'));
		exit;
	}

	private static function process_success(int $entry_id, string $apk, string $val = '', string $method = '', string $request_id = ''): void
	{
		if ($entry_id <= 0) {
			return;
		}

		$expected = Settings::get_anti_phishing_key();
		if ($expected !== '' && $apk !== $expected) {
			defined('WP_DEBUG') && WP_DEBUG && error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, gated by WP_DEBUG.
				sprintf('[iftp-cf7] process_success: APK mismatch for entry %d. got=%s expected=%s', $entry_id, $apk, $expected)
			);
			return;
		}

		$repo  = new EntryRepository();
		$entry = $repo->get_by_id($entry_id);
		if ($entry === null) {
			defined('WP_DEBUG') && WP_DEBUG && error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, gated by WP_DEBUG.
				sprintf('[iftp-cf7] process_success: entry %d not found', $entry_id)
			);
			return;
		}


		if ($entry->payment_status === 'completed') {
			defined('WP_DEBUG') && WP_DEBUG && error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, gated by WP_DEBUG.
				sprintf('[iftp-cf7] process_success: entry %d already completed, skipping', $entry_id)
			);
			return;
		}

		if ($val === '' || number_format((float) $val, 2, '.', '') !== number_format($entry->amount, 2, '.', '')) {
			defined('WP_DEBUG') && WP_DEBUG && error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, gated by WP_DEBUG.
				sprintf('[iftp-cf7] process_success: amount mismatch for entry %d. val=%s db_amount=%s', $entry_id, $val, number_format($entry->amount, 2, '.', ''))
			);
			return;
		}

		$repo->update_transaction(
			$entry->id,
			$method,
			'completed',
			$request_id !== '' ? $request_id : null
		);
		defined('WP_DEBUG') && WP_DEBUG && error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging, gated by WP_DEBUG.
			sprintf('[iftp-cf7] process_success: entry %d marked completed via method=%s', $entry_id, $method)
		);
	}

	private static function process_other(int $entry_id, string $status, string $method = '', string $request_id = ''): void
	{
		if ($entry_id <= 0) {
			return;
		}
		$repo  = new EntryRepository();
		$entry = $repo->get_by_id($entry_id);

		if ($entry === null || $entry->payment_status === 'completed') {
			return;
		}
		if ($method !== '' || $request_id !== '') {
			$repo->update_transaction(
				$entry_id,
				$method,
				$status,
				$request_id !== '' ? $request_id : null
			);
		} else {
			$repo->update_status($entry_id, $status);
		}
	}

	private static function handle_webhook(int $entry_id, string $apk, string $method_get = '', string $req_get = ''): void
	{
		if ($entry_id <= 0) {
			return;
		}

		$expected = Settings::get_anti_phishing_key();
		if ($expected !== '' && $apk !== $expected) {
			return;
		}

		$repo  = new EntryRepository();
		$entry = $repo->get_by_id($entry_id);
		if ($entry === null) {
			return;
		}


		if ($entry->payment_status === 'completed') {
			return;
		}

		$val = sanitize_text_field(wp_unslash((string) ($_REQUEST['val'] ?? $_REQUEST['amount'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Server-side webhook from payment gateway; nonce not applicable.
		if ($val !== '' && number_format((float) $val, 2, '.', '') !== number_format($entry->amount, 2, '.', '')) {
			return;
		}

		$method     = sanitize_text_field(wp_unslash((string) ($_POST['PaymentMethod'] ?? $_POST['Method'] ?? $method_get))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Server-side webhook from payment gateway; nonce verification not applicable.
		$request_id = sanitize_text_field(wp_unslash((string) ($_POST['RequestId'] ?? $_POST['requestId'] ?? $req_get))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Server-side webhook from payment gateway; nonce verification not applicable.

		$repo->update_transaction(
			$entry->id,
			$method,
			'completed',
			$request_id !== '' ? $request_id : null
		);

		do_action('iftp_cf7_payment_confirmed', $entry->id, $method);
	}

	public static function build_success_url(int $entry_id, float $amount, string $gateway_key, string $return_url): string
	{
		$base = home_url('/' . self::SLUG . '/' . $entry_id . '/');
		$url  = add_query_arg(
			array(
				'apk' => base64_encode($gateway_key),
				'val' => number_format($amount, 2, '.', ''),
				'ret' => $return_url,
			),
			$base
		);
		return $url . '&mtd=[PAYMENTMETHOD]&req=[REQUESTID]';
	}

	public static function build_status_url(int $entry_id, string $status, string $return_url): string
	{
		return add_query_arg(
			array(
				'status' => $status,
				'ret'    => $return_url,
			),
			home_url('/' . self::SLUG . '/' . $entry_id . '/')
		);
	}
}
