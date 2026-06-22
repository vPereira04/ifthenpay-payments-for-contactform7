<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Factory;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Factory\DTO\PaymentData;
use Ifthenpay\CF7\Factory\DTO\PaymentResult;

final class PblPaymentCreator
{

	public function make(PaymentData $data): PaymentResult
	{
		try {
			return (new PblPayment($data))->process();
		} catch (\Throwable $e) {
			return PaymentResult::failure($e->getMessage());
		}
	}
}
