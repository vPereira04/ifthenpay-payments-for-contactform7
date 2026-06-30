<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Factory;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Api\IfthenpayApiFacade;
use Ifthenpay\CF7\Api\IfthenpayPayload;
use Ifthenpay\CF7\Factory\DTO\PaymentData;
use Ifthenpay\CF7\Factory\DTO\PaymentResult;
use Ifthenpay\CF7\Repository\EntryRepository;
use Ifthenpay\CF7\Repository\DTO\EntryDto;

/**
 * Concrete Product — creates a Pay-by-Link payment via the ifthenpay API.
 *
 * Responsibilities:
 *  1. Reserve an entry in the DB (pending state).
 *  2. Build the gateway URLs with the reserved entry ID.
 *  3. Call IfthenpayApiFacade::create_payment() (the Facade handles the HTTP call).
 *  4. Persist the payment URL to the entry.
 *  5. Return an immutable PaymentResult.
 */
final class PblPayment
{

	private EntryRepository $repo;

	public function __construct(private readonly PaymentData $data)
	{
		$this->repo = new EntryRepository();
	}

	public function process(): PaymentResult
	{
		$entry_id = $this->reserve_entry();

		if ($entry_id <= 0) {
			return PaymentResult::failure('Could not reserve a payment slot.');
		}

		$gateway_urls = IfthenpayPayload::build_gateway_urls(
			$entry_id,
			$this->data->base_url,
			$this->data->amount
		);

		$payload = IfthenpayPayload::build_pay_by_link_payload(
			array(
				'id'              => (string) $entry_id,
				'amount'          => $this->data->amount,
				'description'     => $this->data->description !== '' ? $this->data->description : 'Payment',
				'accounts'        => $this->data->accounts_string,
				'success_url'     => $gateway_urls['success_url'],
				'error_url'       => $gateway_urls['error_url'],
				'cancel_url'      => $gateway_urls['cancel_url'],
				'locale'          => $this->data->locale,
				'selected_method' => $this->data->selected_method_code,
				'email'           => $this->data->customer_email,
				'name'            => $this->data->customer_name,
			)
		);

		$response = IfthenpayApiFacade::create_payment($this->data->gateway_key, $payload);

		if (empty($response['RedirectUrl'])) {
			$this->repo->update_status($entry_id, 'failed');
			return PaymentResult::failure('Invalid response from ifthenpay.', $entry_id);
		}

		$payment_url = esc_url_raw((string) $response['RedirectUrl']);

		$this->repo->update_payment_url($entry_id, $payment_url);

		return PaymentResult::success($entry_id, $payment_url);
	}

	private function reserve_entry(): int
	{
		return $this->repo->create(
			EntryDto::from(
				array(
					'id'             => 0,
					'form_id'        => $this->data->form_id,
					'form_title'     => $this->data->form_title,
					'customer_name'  => $this->data->customer_name,
					'customer_email' => $this->data->customer_email,
					'customer_ip'    => $this->data->customer_ip,
					'amount'         => $this->data->amount,
					'payment_method' => '',
					'transaction_id' => '',
					'payment_status' => 'pending',
					'payment_url'    => '',
					'return_url'     => $this->data->base_url,
					'form_data'      => $this->data->form_data_json,
					'created_at'     => '',
					'updated_at'     => '',
				)
			)
		);
	}
}
