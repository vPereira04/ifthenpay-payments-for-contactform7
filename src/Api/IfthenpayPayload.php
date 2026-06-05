<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class IfthenpayPayload {

	public static function build_pay_by_link_payload( array $args ): array {
		$id          = (string) ( $args['id'] ?? '' );
		$description = sanitize_text_field( $args['description'] ?? '' );

		$payload = array(
			'id'          => $id,
			'amount'      => self::format_amount( $args['amount'] ?? 0 ),
			'description' => self::build_description( $id, $description ),
			'accounts'    => (string) ( $args['accounts'] ?? '' ),
			'success_url' => $args['success_url'] ?? '',
			'error_url'   => $args['error_url'] ?? '',
			'cancel_url'  => $args['cancel_url'] ?? '',
			'otp'         => 'true',
			'lang'        => self::map_locale_to_lang( (string) ( $args['locale'] ?? get_locale() ) ),
		);

		foreach ( array( 'selected_method', 'email', 'name', 'fields' ) as $field ) {
			if ( empty( $args[ $field ] ) ) {
				continue;
			}
			$payload[ $field ] = $args[ $field ];
		}

		return $payload;
	}

	/**
	 * Build the success / cancel / error return URLs for ifthenpay.
	 *
	 * Format: https://site.com/iftp_cf7?callback=iftp_cf7&ref={id}&apk={base64(gk)}&val={amount}&ret={form_url}
	 * All routing goes through GatewayEndpoint which validates, updates the DB
	 * entry, then redirects the browser back to the form page.
	 *
	 * @param string $return_url   Form page URL the browser is sent back to.
	 * @param float  $amount       Payment amount (included in success URL).
	 * @param string $gateway_key  Gateway key (base64-encoded as anti-phishing).
	 */
	public static function build_gateway_urls( int $entry_id, string $return_url, float $amount = 0.0, string $gateway_key = '' ): array {

		$endpoint_class = 'Ifthenpay\\CF7\\Payment\\GatewayEndpoint';

		if ( class_exists( $endpoint_class ) ) {
			return array(
				'success_url' => $endpoint_class::build_success_url( $entry_id, $amount, $gateway_key, $return_url ),
				'cancel_url'  => $endpoint_class::build_status_url( $entry_id, 'cancel', $return_url ),
				'error_url'   => $endpoint_class::build_status_url( $entry_id, 'error', $return_url ),
			);
		}

		return array(
			'success_url' => add_query_arg(
				array(
					'iftp_cf7_pay'   => 'success',
					'iftp_cf7_entry' => $entry_id,
				),
				$return_url
			),
			'cancel_url'  => add_query_arg(
				array(
					'iftp_cf7_pay'   => 'cancel',
					'iftp_cf7_entry' => $entry_id,
				),
				$return_url
			),
			'error_url'   => add_query_arg(
				array(
					'iftp_cf7_pay'   => 'error',
					'iftp_cf7_entry' => $entry_id,
				),
				$return_url
			),
		);
	}

	public static function build_accounts_string( array $methods_config ): string {
		$parts = array();
		foreach ( $methods_config as $method ) {
			if ( empty( $method['enabled'] ) ) {
				continue;
			}
			$account = isset( $method['account'] ) ? trim( (string) $method['account'] ) : '';
			if ( $account === '' ) {
				continue;
			}
			$parts[] = preg_replace( '/\s*\|\s*/', '|', $account );
		}
		return implode( ';', array_values( array_filter( $parts, static fn( $v ) => is_string( $v ) && $v !== '' ) ) );
	}

	public static function get_gateway_methods_config( array $config, string $gateway_key ): array {
		if (
			$gateway_key !== '' &&
			! empty( $config['gateway_methods'][ $gateway_key ]['methods'] ) &&
			is_array( $config['gateway_methods'][ $gateway_key ]['methods'] )
		) {
			return $config['gateway_methods'][ $gateway_key ]['methods'];
		}
		return isset( $config['methods'] ) && is_array( $config['methods'] ) ? $config['methods'] : array();
	}

	public static function get_selected_method_code( array $config, array $methods_config ): string {
		$map = array();
		foreach ( self::get_available_methods_from_database() as $method ) {
			if ( empty( $method['Entity'] ) || ! isset( $method['Position'] ) ) {
				continue;
			}
			$map[ strtoupper( (string) $method['Entity'] ) ] = (string) $method['Position'];
		}

		if ( empty( $map ) ) {
			return '';
		}

		$entity = '';
		if ( ! empty( $config['default_method'] ) ) {
			$entity = strtoupper( (string) $config['default_method'] );
		}
		if ( ! empty( $config['gateway_key'] ) ) {
			$gk = (string) $config['gateway_key'];
			if ( ! empty( $config['gateway_methods'][ $gk ]['default_method'] ) ) {
				$entity = strtoupper( (string) $config['gateway_methods'][ $gk ]['default_method'] );
			}
		}

		if ( $entity !== '' && isset( $methods_config[ $entity ] ) && ! empty( $methods_config[ $entity ]['enabled'] ) ) {
			return $map[ $entity ] ?? (string) reset( $map );
		}

		$enabled = array();
		foreach ( $methods_config as $ent => $data ) {
			if ( ! empty( $data['enabled'] ) ) {
				$enabled[] = strtoupper( (string) $ent );
			}
		}

		if ( empty( $enabled ) ) {
			return (string) reset( $map );
		}

		$best     = null;
		$best_pos = PHP_INT_MAX;
		foreach ( $enabled as $ent ) {
			if ( isset( $map[ $ent ] ) ) {
				$pos = (int) $map[ $ent ];
				if ( $pos < $best_pos ) {
					$best_pos = $pos;
					$best     = $ent;
				}
			}
		}

		return $best !== null ? $map[ $best ] : (string) reset( $map );
	}

	public static function format_amount( float|int|string $amount, int $decimals = 2 ): string {
		if ( ! is_numeric( $amount ) ) {
			return (string) $amount;
		}
		return number_format( (float) $amount, max( 0, $decimals ), '.', '' );
	}

	public static function map_locale_to_lang( string $locale ): string {
		return match ( substr( strtolower( $locale ), 0, 2 ) ) {
			'pt', 'es', 'fr' => substr( strtolower( $locale ), 0, 2 ),
			default => 'en',
		};
	}

	private static function build_description( string $id, string $description ): string {
		if ( $id === '' ) {
			return $description;
		}
		return $description !== ''
			? sprintf( 'Order #%s - %s', $id, $description )
			: sprintf( 'Order #%s', $id );
	}

	private static function get_available_methods_from_database(): array {
		$catalog = get_option( 'iftp_cf7_method_catalog', array() );
		if ( ! is_array( $catalog ) ) {
			return array();
		}
		$methods = array();
		foreach ( $catalog as $method ) {
			if ( ! is_array( $method ) || empty( $method['entity'] ) ) {
				continue;
			}
			$methods[] = array(
				'Entity'   => strtoupper( (string) $method['entity'] ),
				'Position' => (string) ( $method['position'] ?? 0 ),
			);
		}
		return $methods;
	}

	private function __construct() {}
}
