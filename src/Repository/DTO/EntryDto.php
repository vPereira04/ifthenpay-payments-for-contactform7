<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Repository\DTO;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

final readonly class EntryDto
{

	public function __construct(
		public int $id,
		public int $form_id,
		public string $form_title,
		public string $customer_name,
		public string $customer_email,
		public string $customer_ip,
		public float $amount,
		public string $payment_method,
		public ?string $request_id,
		public string $payment_status,
		public string $payment_url,
		public string $return_url,
		public string $form_data,
		public string $created_at,
		public ?string $link_generated_at,
		public string $updated_at,
	) {}

	/** @param array<string, mixed> $raw */
	public static function from(array $raw): self
	{
		$req = isset($raw['request_id']) && $raw['request_id'] !== null && $raw['request_id'] !== ''
			? sanitize_text_field((string) $raw['request_id'])
			: null;

		return new self(
			id: absint($raw['id'] ?? 0),
			form_id: absint($raw['form_id'] ?? 0),
			form_title: sanitize_text_field((string) ($raw['form_title'] ?? '')),
			customer_name: sanitize_text_field((string) ($raw['customer_name'] ?? '')),
			customer_email: sanitize_email((string) ($raw['customer_email'] ?? '')),
			customer_ip: sanitize_text_field((string) ($raw['customer_ip'] ?? '')),
			amount: (float) ($raw['amount'] ?? 0.0),
			payment_method: strtoupper(sanitize_key((string) ($raw['payment_method'] ?? ''))),
			request_id: $req,
			payment_status: sanitize_key((string) ($raw['payment_status'] ?? 'pending')),
			payment_url: esc_url_raw((string) ($raw['payment_url'] ?? '')),
			return_url: esc_url_raw((string) ($raw['return_url'] ?? '')),
			form_data: (string) ($raw['form_data'] ?? ''),
			created_at: sanitize_text_field((string) ($raw['created_at'] ?? '')),
			link_generated_at: isset($raw['link_generated_at']) && $raw['link_generated_at'] !== null
				? sanitize_text_field((string) $raw['link_generated_at'])
				: null,
			updated_at: sanitize_text_field((string) ($raw['updated_at'] ?? '')),
		);
	}

	public function status_label(): string
	{
		return match ($this->payment_status) {
			'completed' => __('Paid', 'ifthenpay-payments-for-contactform7'),
			'pending'   => __('Pending', 'ifthenpay-payments-for-contactform7'),
			'failed'    => __('Failed', 'ifthenpay-payments-for-contactform7'),
			'cancelled' => __('Cancelled', 'ifthenpay-payments-for-contactform7'),
			'expired'   => __('Expired', 'ifthenpay-payments-for-contactform7'),
			default     => ucfirst($this->payment_status),
		};
	}

	public function amount_formatted(): string
	{
		return number_format($this->amount, 2, ',', '.') . ' €';
	}

	public function is_paid(): bool
	{
		return $this->payment_status === 'completed';
	}
}
