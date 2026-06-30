<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Api;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use RuntimeException;

final class IfthenpayClient
{

	private const API_BASE = 'https://api.ifthenpay.com';

	private string $backoffice_key;

	public function __construct(string $backoffice_key)
	{
		$this->backoffice_key = sanitize_text_field($backoffice_key);
	}

	public static function get_available_methods(): array
	{
		return self::request('GET', self::API_BASE . '/gateway/methods/available');
	}

	public static function build_method_catalog_from_raw(array $raw_methods): array
	{
		$catalog = array();
		foreach ($raw_methods as $method) {
			if (! is_array($method) || empty($method['Entity']) || empty($method['IsVisible'])) {
				continue;
			}
			$catalog[] = array(
				'entity'    => strtoupper((string) $method['Entity']),
				'label'     => isset($method['Method']) ? (string) $method['Method'] : (string) $method['Entity'],
				'logo'      => isset($method['SmallImageUrl']) ? (string) $method['SmallImageUrl'] : '',
				'logo_dark' => isset($method['SmallImageUrlDark']) ? (string) $method['SmallImageUrlDark'] : '',
				'position'  => (int) ($method['Position'] ?? 0),
			);
		}
		return $catalog;
	}

	public function get_gateway_keys(string $type = ''): array
	{
		$args = array('boKey' => $this->backoffice_key);
		$type = sanitize_text_field($type);
		if ($type !== '') {
			$args['Type'] = $type;
		}
		return self::request('GET', add_query_arg($args, self::API_BASE . '/gateway/get'));
	}

	public static function fetch_gateway_catalog(string $backoffice_key, array $raw_methods = array()): array
	{
		$backoffice_key = trim(sanitize_text_field($backoffice_key));
		if ($backoffice_key === '') {
			return array();
		}
		try {
			$catalog = (new self($backoffice_key))->get_gateway_catalog($raw_methods);
		} catch (\Throwable) {
			return array();
		}
		return is_array($catalog) ? $catalog : array();
	}

	public function get_gateway_catalog(array $raw_methods = array()): array
	{
		$rows = $this->get_gateway_keys(IFTP_CF7_GATEWAY_TYPE);

		if (empty($raw_methods)) {
			try {
				$raw_methods = self::get_available_methods();
			} catch (RuntimeException) {
				$raw_methods = array();
			}
		}

		$catalog = array();
		foreach ($rows as $row) {
			if (empty($row['GatewayKey'])) {
				continue;
			}
			$key = sanitize_text_field($row['GatewayKey']);
			if ($key === '') {
				continue;
			}
			$alias           = sanitize_text_field($row['Alias'] ?? '');
			$catalog[$key] = array(
				'gateway_key' => $key,
				'alias'       => $alias,
				'label'       => $alias !== '' ? $alias : $key,
				'methods'     => self::build_gateway_method_accounts($row, $raw_methods),
			);
		}
		return $catalog;
	}

	public static function create_payment_link(string $gateway_key, array $payload): array
	{
		$url = rtrim(self::API_BASE, '/') . '/gateway/pinpay/' . rawurlencode($gateway_key);
		return self::request(
			'POST',
			$url,
			array(
				'headers' => array('Content-Type' => 'application/json'),
				'body'    => wp_json_encode($payload),
			)
		);
	}

	private static function build_gateway_method_accounts(array $row, array $available_methods): array
	{
		$methods = array();
		foreach ($available_methods as $method) {
			if (empty($method['IsVisible']) || empty($method['Method'])) {
				continue;
			}
			$key = sanitize_text_field($method['Entity'] ?? '');
			if ($key === '') {
				continue;
			}
			$value = self::get_gateway_method_account_value($row, $method);
			if ($value === '') {
				continue;
			}
			$methods[$key] = array(
				'method'     => $method['Method'],
				'entity'     => $key,
				'account'    => $value,
				'is_visible' => (bool) $method['IsVisible'],
			);
		}
		return $methods;
	}

	private static function get_gateway_method_account_value(array $row, array $method): string
	{
		$entity = strtoupper((string) ($method['Entity'] ?? ''));
		$label  = strtoupper((string) ($method['Method'] ?? ''));

		$candidates = array_values(
			array_unique(
				array_filter(
					array_map(
						'strval',
						array(
							$method['Entity'] ?? '',
							$method['Method'] ?? '',
							$entity,
							$label,
							strtolower((string) ($method['Entity'] ?? '')),
							strtolower((string) ($method['Method'] ?? '')),
						)
					),
					static fn(string $k): bool => trim($k) !== ''
				)
			)
		);

		if ($entity === 'MB' || $label === 'MULTIBANCO') {
			$candidates[] = 'Multibanco';
			$candidates[] = 'MULTIBANCO';
			$candidates[] = 'MB';
		}

		foreach ($candidates as $candidate) {
			if (! array_key_exists($candidate, $row)) {
				continue;
			}
			$value = sanitize_text_field((string) $row[$candidate]);
			if (trim($value) !== '') {
				return $value;
			}
		}
		return '';
	}

	public static function activate_callback(string $gateway_key, string $base_callback_url, string $anti_phishing_key): bool
	{
		$url = self::API_BASE . '/endpoint/callback/activation/?cms=contactform7';

		$payload = array(
			'apKey' => $anti_phishing_key,
			'chave' => $gateway_key,
			'urlCb' => $base_callback_url .
				'[ORDER_ID]' . '?apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]',
		);

		try {
			$res = self::request(
				'POST',
				$url,
				array(
					'headers' => array('Content-Type' => 'application/json'),
					'body'    => wp_json_encode($payload),
				)
			);
			return (string) ($res['data'] ?? '') === 'OK';
		} catch (RuntimeException) {
			return false;
		}
	}

	/**
	 * @throws RuntimeException
	 */
	private static function request(string $method, string $url, array $args = array(), int $timeout = 20): array
	{
		$args = wp_parse_args(
			$args,
			array(
				'timeout'   => $timeout,
				'sslverify' => true,
			)
		);

		$response = strtoupper($method) === 'POST'
			? wp_remote_post($url, $args)
			: wp_remote_get($url, $args);

		if (is_wp_error($response)) {
			throw new RuntimeException(esc_html($response->get_error_message()));
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);

		if ($code < 200 || $code >= 300) {
			throw new RuntimeException(
				sprintf('Ifthenpay API error (%s): %s', esc_html((string) $code), esc_html(mb_substr($body, 0, 300))),
				(int) $code
			);
		}

		return self::decode($body);
	}

	/**
	 * @throws RuntimeException
	 */
	private static function decode(string $body): array
	{
		$data = json_decode($body, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new RuntimeException('Invalid JSON response from Ifthenpay API.');
		}

		if (isset($data['d'])) {
			$data = is_string($data['d']) ? json_decode($data['d'], true) : $data['d'];
		}

		return is_array($data) ? $data : array('data' => $data);
	}
}
