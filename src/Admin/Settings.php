<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Admin;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

/**
 * Global settings — thin helper that reads/writes the single iftp_cf7_settings option.
 *
 * All setup UI is handled by IfthenpayService (CF7 Integration page).
 * Other classes (Process, FormPanel) call static getters here.
 */
final class Settings
{

	private const OPTION_KEY = 'iftp_cf7_settings';

	/** @return array<string, mixed> */
	public static function get_settings(): array
	{
		$raw = get_option(self::OPTION_KEY, array());
		return is_array($raw) ? $raw : array();
	}

	public static function get_backoffice_key(): string
	{
		return trim((string) (self::get_settings()['backoffice_key'] ?? ''));
	}

	public static function get_gateway_key(): string
	{
		return trim((string) (self::get_settings()['gateway_key'] ?? ''));
	}

	public static function get_methods(): array
	{
		$methods = self::get_settings()['methods'] ?? array();
		return is_array($methods) ? $methods : array();
	}

	public static function get_description(): string
	{
		return trim((string) (self::get_settings()['description'] ?? 'Payment'));
	}

	public static function get_expire_days(): int
	{
		return max(1, (int) (self::get_settings()['expire_days'] ?? 3));
	}

	/**
	 * Anti-phishing key — a random per-site secret shared only with ifthenpay.
	 *
	 * Registered with ifthenpay as the callback `apKey`; ifthenpay echoes it back
	 * in the server-to-server webhook so we can prove the request originated from
	 * them. Read-only — never derived from the gateway key (which is exposed on the
	 * frontend) and never sent to the browser. Returns '' until ensure_anti_phishing_key()
	 * has run, which makes webhook validation fail closed.
	 */
	public static function get_anti_phishing_key(): string
	{
		return trim((string) (self::get_settings()['anti_phishing_key'] ?? ''));
	}

	/**
	 * Return the anti-phishing secret, generating and persisting one on first use.
	 *
	 * Called only from the admin save path (when the callback is (re)registered with
	 * ifthenpay), never from the public webhook, so untrusted requests can't trigger
	 * a DB write.
	 */
	public static function ensure_anti_phishing_key(): string
	{
		$key = self::get_anti_phishing_key();
		if ($key === '') {
			$key = wp_generate_password(40, false);
			self::update_settings(array('anti_phishing_key' => $key));
		}
		return $key;
	}

	/** @return array<string, mixed> */
	public static function get_form_config(int $form_id): array
	{
		$raw = get_option('iftp_cf7_form_config_' . $form_id, array());
		return is_array($raw) ? $raw : array();
	}

	/** @param array<string, mixed> $changes */
	public static function update_settings(array $changes): void
	{
		$current = self::get_settings();
		update_option(self::OPTION_KEY, array_merge($current, $changes), false);
	}

	public static function clear_all(): void
	{
		delete_option(self::OPTION_KEY);
	}

	public function ajax_activate_payment_method(): void
	{
		check_ajax_referer('iftp_cf7_settings', 'nonce');
		if (! current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Unauthorized.', 'ifthenpay-payments-for-contactform7')), 403);
		}

		$gateway_key = isset($_POST['gateway_key'])
			? sanitize_text_field(wp_unslash((string) $_POST['gateway_key']))
			: '';
		$method      = isset($_POST['method'])
			? sanitize_text_field(wp_unslash((string) $_POST['method']))
			: '';

		if ($gateway_key === '' || $method === '') {
			wp_send_json_error(array('message' => __('Missing parameters.', 'ifthenpay-payments-for-contactform7')), 400);
		}

		$cooldown_key = 'iftp_cf7_activation_' . md5($gateway_key . $method);
		if (get_transient($cooldown_key)) {
			wp_send_json_error(array('message' => __('Request already sent in the last 24 hours.', 'ifthenpay-payments-for-contactform7')), 429);
		}

		$sent = \Ifthenpay\CF7\Api\IfthenpayEmailHelper::send_activation_email(
			self::get_backoffice_key(),
			$gateway_key,
			$method
		);

		if ($sent) {
			set_transient($cooldown_key, 1, DAY_IN_SECONDS);
			wp_send_json_success(array('message' => __('Activation request sent to ifthenpay support.', 'ifthenpay-payments-for-contactform7')));
		} else {
			wp_send_json_error(array('message' => __('Failed to send activation email. Contact ifthenpay directly.', 'ifthenpay-payments-for-contactform7')), 500);
		}
	}

}
