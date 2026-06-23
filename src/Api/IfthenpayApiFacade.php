<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Api;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

/**
 * Facade — single entry point for all ifthenpay API interactions.
 *
 * Only two outbound API calls are ever made:
 *   1. connect()          → GET /gateway/get + GET /gateway/methods/available
 *   2. Payment-time calls → create_payment()
 *
 * All catalog data (gateways, methods) is fetched once on connect and
 * persisted to wp_options.  The rest of the codebase reads from the DB.
 */
final class IfthenpayApiFacade
{

	private const OPTION_GATEWAY_CATALOG = 'iftp_cf7_gateway_catalog';
	private const OPTION_METHOD_CATALOG  = 'iftp_cf7_method_catalog';

	/**
	 * Verify the Backoffice Key with a single API call and persist the gateway catalog.
	 *
	 * Intentionally does NOT fetch the method catalog — that happens on page load
	 * (Scenarios 2 & 3) so connect_backoffice stays at exactly 1 outbound request.
	 *
	 * API calls made here (exactly 1):
	 *   • GET /gateway/get?boKey=… — verifies the key and returns the gateway list
	 *
	 * @return array{ok:bool, gateways:array, error:string}
	 */
	public static function verify_and_save_gateway(string $backoffice_key): array
	{
		$backoffice_key = trim(sanitize_text_field($backoffice_key));
		if ($backoffice_key === '') {
			return array(
				'ok'       => false,
				'gateways' => array(),
				'error'    => 'empty_key',
			);
		}

		try {
			$client = new IfthenpayClient($backoffice_key);
			$rows   = $client->get_gateway_keys(defined('IFTP_CF7_GATEWAY_TYPE') ? IFTP_CF7_GATEWAY_TYPE : '');

			if (empty($rows)) {
				return array(
					'ok'       => false,
					'gateways' => array(),
					'error'    => 'no_gateways',
				);
			}

			$catalog = array();
			foreach ($rows as $row) {
				if (empty($row['GatewayKey'])) {
					continue;
				}
				$key = sanitize_text_field((string) $row['GatewayKey']);
				if ($key === '') {
					continue;
				}
				$alias         = sanitize_text_field((string) ($row['Alias'] ?? ''));
				$catalog[$key] = array(
					'gateway_key' => $key,
					'alias'       => $alias,
					'label'       => $alias !== '' ? $alias : $key,
					'methods'     => array(),
				);
			}

			if (empty($catalog)) {
				return array(
					'ok'       => false,
					'gateways' => array(),
					'error'    => 'no_gateways',
				);
			}

			update_option(self::OPTION_GATEWAY_CATALOG, $catalog, false);
			return array(
				'ok'       => true,
				'gateways' => $catalog,
				'error'    => '',
			);
		} catch (\Throwable $e) {
			return array(
				'ok'       => false,
				'gateways' => array(),
				'error'    => $e->getMessage(),
			);
		}
	}

	/**
	 * Validate the Backoffice Key, then fetch and persist both catalogs.
	 *
	 * API calls made here (maximum 2):
	 *   • GET /gateway/get?boKey=…      — gateway list
	 *   • GET /gateway/methods/available — visible payment methods
	 *
	 * @return array{ok:bool, gateways:array, methods:array, error:string}
	 */
	public static function connect(string $backoffice_key): array
	{
		$backoffice_key = trim(sanitize_text_field($backoffice_key));
		if ($backoffice_key === '') {
			return array(
				'ok'       => false,
				'gateways' => array(),
				'methods'  => array(),
				'error'    => 'empty_key',
			);
		}

		try {
			$raw_methods     = IfthenpayClient::get_available_methods();
			$gateway_catalog = IfthenpayClient::fetch_gateway_catalog($backoffice_key, $raw_methods);
			$method_catalog  = IfthenpayClient::build_method_catalog_from_raw($raw_methods);

			update_option(self::OPTION_GATEWAY_CATALOG, $gateway_catalog, false);
			update_option(self::OPTION_METHOD_CATALOG, $method_catalog, false);

			return array(
				'ok'       => true,
				'gateways' => $gateway_catalog,
				'methods'  => $method_catalog,
				'error'    => '',
			);
		} catch (\Throwable $e) {
			return array(
				'ok'       => false,
				'gateways' => array(),
				'methods'  => array(),
				'error'    => $e->getMessage(),
			);
		}
	}

	public static function get_gateway_catalog(): array
	{
		$catalog = get_option(self::OPTION_GATEWAY_CATALOG, array());
		return is_array($catalog) ? $catalog : array();
	}

	public static function get_method_catalog(): array
	{
		$catalog = get_option(self::OPTION_METHOD_CATALOG, array());
		return is_array($catalog) ? $catalog : array();
	}

	public static function get_methods_for_gateway(string $gateway_key): array
	{
		$catalog = self::get_gateway_catalog();
		return isset($catalog[$gateway_key]['methods']) && is_array($catalog[$gateway_key]['methods'])
			? $catalog[$gateway_key]['methods']
			: array();
	}

	/**
	 * @throws \RuntimeException on API failure
	 */
	public static function create_payment(string $gateway_key, array $payload): array
	{
		return IfthenpayClient::create_payment_link($gateway_key, $payload);
	}

	public static function clear_catalogs(): void
	{
		delete_option(self::OPTION_GATEWAY_CATALOG);

	}

	private function __construct() {}
}
