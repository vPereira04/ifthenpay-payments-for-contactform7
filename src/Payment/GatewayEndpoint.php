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
 * Two distinct kinds of request hit this endpoint, with very different trust:
 *
 * 1. Browser return (GET) — the customer's browser, redirected by ifthenpay:
 *      Success: /iftp_callback/{entry_id}/?val={amount}&ret={form_url}
 *      Cancel/Error: /iftp_callback/{entry_id}/?status={cancel|error}&ret={form_url}
 *    The browser carries no shared secret, so this path is NOT trusted and changes
 *    NO payment state. It only redirects back to the form page with
 *    ?iftp_cf7_pay=success|cancel|error&iftp_cf7_entry={id} so the frontend JS can
 *    show the right message.
 *
 * 2. Server-to-server webhook (POST from ifthenpay, no browser):
 *      Same path, with apk={anti-phishing secret}&val={amount}&mtd=...&req=...
 *    This is the ONLY path that updates the entry. It is authenticated by comparing
 *    the apk against the per-site anti-phishing secret (hash_equals) — a random value
 *    shared only with ifthenpay, never derived from the gateway key and never exposed
 *    to the browser. A WP nonce is not applicable: the caller is ifthenpay, not a
 *    logged-in user.
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


		if ($method === 'POST') {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- External webhook; authenticated by the anti-phishing secret (hash_equals) inside handle_webhook(), not by a nonce.
			$apk = sanitize_text_field(
				wp_unslash((string) ($_POST['apk'] ?? $_GET['apk'] ?? ''))
			);
			$mtd = sanitize_text_field(
				wp_unslash((string) ($_POST['mtd'] ?? $_GET['mtd'] ?? ''))
			);
			$req = sanitize_text_field(
				wp_unslash((string) ($_POST['req'] ?? $_POST['requestId'] ?? $_GET['req'] ?? $_GET['requestId'] ?? ''))
			);
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			self::handle_webhook($entry_id, $apk, $mtd, $req);
			echo 'OK';
			exit;
		}


		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect; these values only choose which UI message to show and change no state.
		$status = sanitize_key(
			wp_unslash((string) ($_GET['status'] ?? ''))
		);
		$error_msg = sanitize_text_field(
			wp_unslash((string) ($_GET['error'] ?? ''))
		);
		$has_success = isset($_GET['val']) || isset($_GET['amount']);
		$ret = esc_url_raw(
			wp_unslash((string) ($_GET['ret'] ?? ''))
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ($status === '' && $error_msg !== '') {
			$status = 'error';
		}

		if ($ret !== '' && strpos($ret, home_url()) !== 0) {
			$ret = '';
		}

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

		$pay = '';
		if ($status !== '') {
			$pay = $status;
		} elseif ($has_success) {
			$pay = 'success';
		}

		if ($pay === '') {
			wp_safe_redirect($ret . $anchor);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'iftp_cf7_pay'   => $pay,
					'iftp_cf7_entry' => $entry_id,
				),
				$ret
			) . $anchor
		);
		exit;
	}

	private static function handle_webhook(int $entry_id, string $apk, string $method_get = '', string $req_get = ''): void
	{
		if ($entry_id <= 0) {
			return;
		}

		$expected = Settings::get_anti_phishing_key();
		if ($expected === '' || ! hash_equals($expected, $apk)) {
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

		$val = sanitize_text_field(wp_unslash((string) ($_REQUEST['val'] ?? $_REQUEST['amount'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ($val === '') {
			return;
		}

		if (abs((float) $val - $entry->amount) > 0.009) {
			return;
		}

		$method     = sanitize_text_field(wp_unslash((string) ($_POST['PaymentMethod'] ?? $_POST['Method'] ?? $method_get))); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$request_id = sanitize_text_field(wp_unslash((string) ($_POST['RequestId'] ?? $_POST['requestId'] ?? $req_get))); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$repo->update_transaction(
			$entry->id,
			$method,
			'completed',
			$request_id !== '' ? $request_id : null
		);

		do_action('iftp_cf7_payment_confirmed', $entry->id, $method);
	}

	public static function build_success_url(int $entry_id, float $amount, string $return_url): string
	{

		return add_query_arg(
			array(
				'val' => number_format($amount, 2, '.', ''),
				'ret' => $return_url,
			),
			home_url('/' . self::SLUG . '/' . $entry_id . '/')
		);
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
