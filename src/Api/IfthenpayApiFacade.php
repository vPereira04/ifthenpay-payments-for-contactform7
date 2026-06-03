<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

/**
 * Facade — single entry point for all ifthenpay API interactions.
 *
 * Only two outbound API calls are ever made:
 *   1. connect()          → GET /gateway/get + GET /gateway/methods/available
 *   2. Payment-time calls → create_payment() and get_payment_status()
 *
 * All catalog data (gateways, methods) is fetched once on connect and
 * persisted to wp_options.  The rest of the codebase reads from the DB.
 */
final class IfthenpayApiFacade {

	private const OPTION_GATEWAY_CATALOG = 'iftp_cf7_gateway_catalog';
	private const OPTION_METHOD_CATALOG  = 'iftp_cf7_method_catalog';



	/**
	 * Validate the Backoffice Key, then fetch and persist both catalogs.
	 *
	 * API calls made here (maximum 2):
	 *   • GET /gateway/get?boKey=…      — gateway list
	 *   • GET /gateway/methods/available — visible payment methods
	 *
	 * @return array{ok:bool, gateways:array, methods:array, error:string}
	 */
	public static function connect( string $backoffice_key ): array {
		$backoffice_key = trim( sanitize_text_field( $backoffice_key ) );
		if ( $backoffice_key === '' ) {
			return [ 'ok' => false, 'gateways' => [], 'methods' => [], 'error' => 'empty_key' ];
		}

		try {
			$raw_methods = IfthenpayClient::get_available_methods();
			$gateway_catalog = IfthenpayClient::fetch_gateway_catalog( $backoffice_key, $raw_methods );
			$method_catalog  = IfthenpayClient::build_method_catalog_from_raw( $raw_methods );

			update_option( self::OPTION_GATEWAY_CATALOG, $gateway_catalog, false );
			update_option( self::OPTION_METHOD_CATALOG, $method_catalog, false );

			return [
				'ok'       => true,
				'gateways' => $gateway_catalog,
				'methods'  => $method_catalog,
				'error'    => '',
			];
		} catch ( \Throwable $e ) {
			return [ 'ok' => false, 'gateways' => [], 'methods' => [], 'error' => $e->getMessage() ];
		}
	}



	public static function get_gateway_catalog(): array {
		$catalog = get_option( self::OPTION_GATEWAY_CATALOG, [] );
		return is_array( $catalog ) ? $catalog : [];
	}

	public static function get_method_catalog(): array {
		$catalog = get_option( self::OPTION_METHOD_CATALOG, [] );
		return is_array( $catalog ) ? $catalog : [];
	}

	public static function get_methods_for_gateway( string $gateway_key ): array {
		$catalog = self::get_gateway_catalog();
		return isset( $catalog[$gateway_key]['methods'] ) && is_array( $catalog[$gateway_key]['methods'] )
			? $catalog[$gateway_key]['methods']
			: [];
	}



	/**
	 * @throws \RuntimeException on API failure
	 */
	public static function create_payment( string $gateway_key, array $payload ): array {
		return IfthenpayClient::create_payment_link( $gateway_key, $payload );
	}

	/**
	 * @throws \RuntimeException on API failure
	 */
	public static function get_payment_status( string $transaction_id ): array {
		return IfthenpayClient::get_payment_status( $transaction_id );
	}



	public static function clear_catalogs(): void {
		delete_option( self::OPTION_GATEWAY_CATALOG );
		delete_option( self::OPTION_METHOD_CATALOG );
	}

	private function __construct() {}
}
