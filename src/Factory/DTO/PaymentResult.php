<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Factory\DTO;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Immutable result returned by the payment factory after creating a payment.
 */
final readonly class PaymentResult {

	public function __construct(
		public bool   $ok,
		public int    $entry_id,
		public string $payment_url,
		public string $modal_token,
		public string $error,
	) {}

	public static function success( int $entry_id, string $payment_url, string $modal_token ): self {
		return new self( ok: true,  entry_id: $entry_id, payment_url: $payment_url, modal_token: $modal_token, error: '' );
	}

	public static function failure( string $error, int $entry_id = 0 ): self {
		return new self( ok: false, entry_id: $entry_id, payment_url: '', modal_token: '', error: $error );
	}
}
