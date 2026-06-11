<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Factory;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Factory\DTO\PaymentData;
use Ifthenpay\CF7\Factory\DTO\PaymentResult;

/**
 * Factory Method — abstract creator.
 *
 * Declares the factory method `create_payment()` that concrete subclasses
 * override to instantiate the appropriate payment type.
 * The template method `make()` is the stable algorithm; only the object
 * creation step is delegated to the subclass.
 */
abstract class PaymentCreator
{

	/**
	 * Factory method — override in concrete creators to return a Payment instance.
	 */
	abstract protected function create_payment(PaymentData $data): Payment;

	/**
	 * Template method — orchestrates the payment lifecycle.
	 * Calls the factory method internally; callers always use `make()`.
	 */
	final public function make(PaymentData $data): PaymentResult
	{
		try {
			$payment = $this->create_payment($data);
			return $payment->process();
		} catch (\Throwable $e) {
			return PaymentResult::failure($e->getMessage());
		}
	}
}
