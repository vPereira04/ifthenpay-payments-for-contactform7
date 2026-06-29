<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Payment;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Api\IfthenpayPayload;
use Ifthenpay\CF7\Factory\PblPaymentCreator;
use Ifthenpay\CF7\Factory\DTO\PaymentData;
use Ifthenpay\CF7\Repository\EntryRepository;

final class Process
{

	private EntryRepository $repository;

	/** @var array<int, int> Maps form_id → entry_id within the current HTTP request (avoids transient race conditions). */
	private array $mail_entry_ids = [];

	public function __construct()
	{
		$this->repository = new EntryRepository();
	}

	/**
	 * Inject our hidden POST fields into CF7's posted_data.
	 *
	 * @param array<string, mixed> $posted_data
	 * @return array<string, mixed>
	 */
	public function inject_posted_fields(array $posted_data): array
	{
		$keys = array('iftp_cf7_entry_id', 'iftp_cf7_payment_status');
		foreach ($keys as $key) {

			$raw = isset($_POST[$key]) ? wp_unslash((string) $_POST[$key]) : null;
			if ($raw !== null) {
				$posted_data[$key] = sanitize_text_field($raw);
			}
		}
		return $posted_data;
	}

	/**
	 * Called by CF7 before sending the confirmation mail.
	 *
	 * The entry is already created when the user clicked Pay (ajax_create_payment).
	 * Here we register the entry ID so mail tags can resolve within the same request.
	 *
	 * @param \WPCF7_ContactForm $contact_form
	 * @param bool               $_abort  By-ref CF7 abort flag (unused — we never abort).
	 * @param \WPCF7_Submission  $submission
	 */
	public function on_before_send_mail($contact_form, bool &$_abort, $submission): void
	{
		$posted = is_object($submission) && method_exists($submission, 'get_posted_data')
			? (array) $submission->get_posted_data()
			: array();

		$entry_id = absint($posted['iftp_cf7_entry_id'] ?? 0);

		if ($entry_id <= 0) {
			return;
		}

		if ($this->repository->get_by_id($entry_id) === null) {
			return;
		}

		$this->mail_entry_ids[(int) $contact_form->id()] = $entry_id;
	}

	/**
	 * Resolve CF7 special mail tags like [ifthenpay-transaction-id].
	 *
	 * @param string $output
	 * @param string $name
	 * @param bool   $_html
	 * @param mixed  $mail_component
	 */
	public function resolve_mail_tags(?string $output, string $name, bool $_html, $mail_component): string
	{
		$output = $output ?? '';
		if (strpos($name, 'ifthenpay') !== 0) {
			return $output;
		}

		$form_id = 0;
		if (is_object($mail_component) && method_exists($mail_component, 'contact_form')) {
			$cf = $mail_component->contact_form();
			if (is_object($cf) && method_exists($cf, 'id')) {
				$form_id = (int) $cf->id();
			}
		}

		$entry_id = $this->mail_entry_ids[$form_id] ?? 0;
		if ($entry_id <= 0) {
			return $output;
		}

		$entry = $this->repository->get_by_id($entry_id);
		if ($entry === null) {
			return $output;
		}

		return match ($name) {
			'ifthenpay-amount'      => esc_html($entry->amount_formatted()),
			'ifthenpay-method'      => esc_html($entry->payment_method),
			'ifthenpay-status'      => esc_html($entry->status_label()),
			'ifthenpay-payment-url' => esc_url($entry->payment_url),
			'ifthenpay-entry-id'    => esc_html((string) $entry->id),
			default                 => $output,
		};
	}

	public function ajax_create_payment(): void
	{
		check_ajax_referer('iftp_cf7_frontend', 'nonce');

		$form_id    = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
		$amount_raw = isset($_POST['amount']) ? sanitize_text_field(wp_unslash((string) $_POST['amount'])) : '';
		$name       = isset($_POST['customer_name']) ? sanitize_text_field(wp_unslash((string) $_POST['customer_name'])) : '';
		$email      = isset($_POST['customer_email']) ? sanitize_email(wp_unslash((string) $_POST['customer_email'])) : '';
		$form_title = isset($_POST['form_title']) ? sanitize_text_field(wp_unslash((string) $_POST['form_title'])) : '';


		$form_data_raw = isset($_POST['form_data']) ? wp_unslash((string) $_POST['form_data']) : '';

		$customer_ip = sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? '')));

		$settings    = Settings::get_settings();
		$backoffice  = (string) ($settings['backoffice_key'] ?? '');
		$gateway_key = (string) ($settings['gateway_key'] ?? '');

		if ($form_id <= 0) {
			wp_send_json_error(array('message' => __('Invalid form ID.', 'ifthenpay-payments-for-contactform7')));
		}

		if (mb_strlen($email) > 100) {
			wp_send_json_error(array('message' => __('The email address is too long (maximum 100 characters).', 'ifthenpay-payments-for-contactform7')));
		}

		if (mb_strlen($name) > 255) {
			wp_send_json_error(array('message' => __('The name is too long (maximum 255 characters).', 'ifthenpay-payments-for-contactform7')));
		}

		$amount = is_numeric($amount_raw) ? (float) $amount_raw : 0.0;
		if ($amount <= 0) {
			wp_send_json_error(array('message' => __('The payment amount is missing or invalid.', 'ifthenpay-payments-for-contactform7')));
		}

		if ($backoffice === '' || $gateway_key === '') {
			wp_send_json_error(array('message' => __('ifthenpay is not configured. Complete the setup on the Integration page.', 'ifthenpay-payments-for-contactform7')));
		}

		$methods_cfg = (array) ($settings['methods'] ?? array());
		$accounts    = IfthenpayPayload::build_accounts_string($methods_cfg);

		if ($accounts === '') {
			wp_send_json_error(array('message' => __('No payment methods are enabled. Configure them on the Integration page.', 'ifthenpay-payments-for-contactform7')));
		}

		$selected_code = IfthenpayPayload::get_selected_method_code($settings, $methods_cfg);
		$description   = (string) ($settings['description'] ?? 'Payment');
		$expire_days   = max(1, (int) ($settings['expire_days'] ?? 3));
		$base_url      = wp_get_referer() ?: home_url('/');

		if ($form_title === '') {
			$cf7        = function_exists('wpcf7_contact_form') ? wpcf7_contact_form($form_id) : null;
			$form_title = $cf7 instanceof \WPCF7_ContactForm ? $cf7->title() : 'Form #' . $form_id;
		}

		$form_data_json = '{}';
		if ($form_data_raw !== '') {
			$decoded = json_decode($form_data_raw, true);
			if (is_array($decoded)) {
				$form_data_json = (string) wp_json_encode($this->sanitize_form_data($decoded));
			}
		}

		$payment_data = PaymentData::from(
			array(
				'form_id'              => $form_id,
				'form_title'           => $form_title,
				'amount'               => $amount,
				'gateway_key'          => $gateway_key,
				'accounts_string'      => $accounts,
				'selected_method_code' => $selected_code,
				'customer_name'        => $name,
				'customer_email'       => $email,
				'customer_ip'          => $customer_ip,
				'description'          => $description,
				'expire_days'          => $expire_days,
				'base_url'             => $base_url,
				'locale'               => get_locale(),
				'form_data_json'       => $form_data_json,
			)
		);

		$result = (new PblPaymentCreator())->make($payment_data);

		if (! $result->ok) {
			wp_send_json_error(array('message' => $result->error ?: __('Failed to create the payment link. Please try again.', 'ifthenpay-payments-for-contactform7')));
		}

		wp_send_json_success(
			array(
				'entry_id'   => $result->entry_id,
				'iframe_url' => $result->payment_url,
			)
		);
	}

	public function ajax_verify_payment(): void
	{
		check_ajax_referer('iftp_cf7_frontend', 'nonce');

		$entry_id      = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
		$return_action = isset($_POST['return_action']) ? sanitize_key(wp_unslash((string) $_POST['return_action'])) : '';

		if ($entry_id <= 0) {
			wp_send_json_error(array('message' => __('Missing payment reference.', 'ifthenpay-payments-for-contactform7')), 400);
		}

		$entry = $this->repository->get_by_id($entry_id);
		if ($entry === null) {
			wp_send_json_error(array('message' => __('Payment record not found.', 'ifthenpay-payments-for-contactform7')), 404);
		}

		if ($return_action === 'cancel') {
			$this->repository->update_status($entry_id, 'cancelled');
			wp_send_json_success(array('status' => 'cancelled'));
		}

		if ($return_action === 'error') {
			$this->repository->update_status($entry_id, 'failed');
			wp_send_json_success(array('status' => 'failed'));
		}

		wp_send_json_success(
			array(
				'status'         => $entry->payment_status,
				'payment_method' => $entry->payment_method,
			)
		);
	}

	public function ajax_cancel_payment(): void
	{
		check_ajax_referer('iftp_cf7_frontend', 'nonce');

		$entry_id = isset($_POST['entry_id']) ? absint($_POST['entry_id']) : 0;
		if ($entry_id <= 0) {
			wp_send_json_error(array('message' => __('Missing payment reference.', 'ifthenpay-payments-for-contactform7')), 400);
		}

		$this->repository->update_status($entry_id, 'cancelled');
		wp_send_json_success(array('status' => 'cancelled'));
	}

	/**
	 * CF7 wpcf7_validate_email / wpcf7_validate_email* filter.
	 * Rejects email values that exceed the customer_email column limit (100 chars).
	 *
	 * @param \WPCF7_Validation $result
	 * @param \WPCF7_FormTag    $tag
	 * @return \WPCF7_Validation
	 */
	public function validate_email_length(\WPCF7_Validation $result, \WPCF7_FormTag $tag): \WPCF7_Validation
	{
		$name  = $tag->name;

		$value = isset($_POST[$name]) ? sanitize_text_field(wp_unslash((string) $_POST[$name])) : '';
		if ($value !== '' && mb_strlen($value) > 100) {
			$result->invalidate(
				$tag,
				__('The email address is too long (maximum 100 characters).', 'ifthenpay-payments-for-contactform7')
			);
		}
		return $result;
	}

	/**
	 * Recursively sanitizes a decoded form_data array before DB storage.
	 * Keys → sanitize_text_field; scalar values → sanitize_textarea_field;
	 * nested arrays are processed depth-first.
	 *
	 * @param array<mixed, mixed> $data
	 * @return array<string, mixed>
	 */
	private function sanitize_form_data(array $data): array
	{
		$clean = array();
		foreach ($data as $k => $v) {
			$safe_key = sanitize_text_field((string) $k);
			if (is_array($v)) {
				$clean[$safe_key] = $this->sanitize_form_data($v);
			} else {
				$clean[$safe_key] = sanitize_textarea_field((string) $v);
			}
		}
		return $clean;
	}
}
