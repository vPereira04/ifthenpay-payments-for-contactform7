<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Factory;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Factory\DTO\PaymentResult;

/**
 * Product interface for the Factory Method pattern.
 */
interface Payment
{

	public function process(): PaymentResult;
}
