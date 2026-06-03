<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Api\IfthenpayReturn;
use Ifthenpay\CF7\Repository\EntryRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST endpoint that receives payment status webhooks from ifthenpay.
 *
 * Anti-phishing key = base64_encode($gateway_key) — derived in Settings,
 * never stored. Validated here against the `chave` field sent by ifthenpay.
 */
final class Callback {

	private const REST_NAMESPACE = 'ifthenpay-cf7/v1';
	private const REST_ROUTE     = '/callback';

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_params();


		$expected = Settings::get_anti_phishing_key();
		if ( $expected !== '' ) {
			$received = sanitize_text_field( (string) ( $payload['chave'] ?? $payload['key'] ?? $payload['sk'] ?? '' ) );
			if ( $received !== $expected ) {
				return new WP_REST_Response( [ 'error' => 'invalid_key' ], 403 );
			}
		}

		$transaction_id = IfthenpayReturn::get_callback_transaction_id( $payload );
		$entry_id       = IfthenpayReturn::get_callback_entry_id( $payload );
		$is_success     = IfthenpayReturn::is_successful_callback( $payload );

		if ( $transaction_id === '' && $entry_id <= 0 ) {
			return new WP_REST_Response( [ 'error' => 'missing_parameters' ], 400 );
		}

		$repo  = new EntryRepository();
		$entry = $transaction_id !== '' ? $repo->get_by_transaction_id( $transaction_id ) : null;
		if ( $entry === null && $entry_id > 0 ) {
			$entry = $repo->get_by_id( $entry_id );
		}

		if ( $entry === null ) {
			return new WP_REST_Response( [ 'error' => 'entry_not_found' ], 404 );
		}

		if ( $is_success ) {
			$method = sanitize_text_field( (string) ( $payload['PaymentMethod'] ?? $payload['Method'] ?? '' ) );
			$repo->update_transaction( $entry->id, $transaction_id ?: $entry->transaction_id, $method, 'completed' );

			/** @fires iftp_cf7_payment_confirmed after ifthenpay confirms payment via webhook */
			do_action( 'iftp_cf7_payment_confirmed', $entry->id, $transaction_id, $method );
		} else {
			$repo->update_status( $entry->id, 'failed' );
			do_action( 'iftp_cf7_payment_failed', $entry->id, $transaction_id );
		}

		return new WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	public static function get_callback_url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}
}
