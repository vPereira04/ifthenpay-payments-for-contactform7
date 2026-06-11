<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Factory\DTO;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

/**
 * Immutable data carrier passed from the AJAX handler into the payment factory.
 */
final readonly class PaymentData
{

	public function __construct(
		public int $form_id,
		public string $form_title,
		public float $amount,
		public string $gateway_key,
		public string $accounts_string,
		public string $selected_method_code,
		public string $customer_name,
		public string $customer_email,
		public string $customer_ip,
		public string $description,
		public int $expire_days,
		public string $base_url,
		public string $locale,
		public string $form_data_json,
	) {}

	/** @param array<string, mixed> $raw */
	public static function from(array $raw): self
	{
		return new self(
			form_id: absint($raw['form_id'] ?? 0),
			form_title: sanitize_text_field((string) ($raw['form_title'] ?? '')),
			amount: (float) ($raw['amount'] ?? 0.0),
			gateway_key: sanitize_text_field((string) ($raw['gateway_key'] ?? '')),
			accounts_string: (string) ($raw['accounts_string'] ?? ''),
			selected_method_code: (string) ($raw['selected_method_code'] ?? ''),
			customer_name: sanitize_text_field((string) ($raw['customer_name'] ?? '')),
			customer_email: sanitize_email((string) ($raw['customer_email'] ?? '')),
			customer_ip: sanitize_text_field((string) ($raw['customer_ip'] ?? '')),
			description: sanitize_text_field((string) ($raw['description'] ?? '')),
			expire_days: max(1, absint($raw['expire_days'] ?? 3)),
			base_url: esc_url_raw((string) ($raw['base_url'] ?? home_url('/'))),
			locale: sanitize_text_field((string) ($raw['locale'] ?? get_locale())),
			form_data_json: (string) ($raw['form_data_json'] ?? '{}'),
		);
	}
}
