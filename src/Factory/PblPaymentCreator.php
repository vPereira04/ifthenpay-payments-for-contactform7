<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Factory;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Factory\DTO\PaymentData;

/**
 * Concrete Creator — creates a PblPayment (Pay-by-Link product).
 */
final class PblPaymentCreator extends PaymentCreator
{

	protected function create_payment(PaymentData $data): Payment
	{
		return new PblPayment($data);
	}
}
