<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Payment;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Repository\EntryRepository;

/**
 * Handles the /iftp_cf7 endpoint — the unified return + webhook URL.
 *
 * URL pattern (same for all):
 *   https://site.com/iftp_cf7?callback=iftp_cf7&...
 *
 * Success (browser redirect from ifthenpay):
 *   ?callback=iftp_cf7&ref={entry_id}&apk={base64(gateway_key)}&val={amount}&ret={form_url}
 *
 * Cancel / Error (browser redirect):
 *   ?callback=iftp_cf7&ref={entry_id}&status={cancel|error}&ret={form_url}
 *
 * Server webhook (POST from ifthenpay, no browser):
 *   Same params as success — validated by apk, updates entry, returns 200.
 *
 * After processing a browser redirect the handler redirects back to the
 * form page (ret param) with ?iftp_cf7_pay=success|cancel|error&iftp_cf7_entry={id}.
 * The frontend JS then shows the appropriate message.
 */
final class GatewayEndpoint {

	public const QUERY_VAR   = 'iftp_cf7_ep';
	public const SLUG        = 'iftp_cf7';
	public const CALLBACK_ID = 'iftp_cf7';



	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
	}

	public static function add_rewrite_rule(): void {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function flush(): void {
		self::add_rewrite_rule();
		flush_rewrite_rules();
	}

	/** @param string[] $vars */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}



	public static function handle(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		// placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended -- gateway callbacks are not user-initiated; validated via apk.
		if ( sanitize_key( wp_unslash( $_REQUEST['callback'] ?? '' ) ) !== self::CALLBACK_ID ) {
			return;
		}

		$request_method   = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$method           = strtoupper( (string) $request_method );

		$entry_id = absint( wp_unslash( $_REQUEST['ref'] ?? $_REQUEST['id'] ?? 0 ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$apk      = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['apk'] ?? '' ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended

		$val    = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['val'] ?? $_REQUEST['amount'] ?? '' ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$status = sanitize_key( wp_unslash( $_REQUEST['status'] ?? '' ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$ret    = esc_url_raw( wp_unslash( (string) ( $_REQUEST['ret'] ?? '' ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		$mtd    = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['mtd'] ?? '' ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended

		$req    = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['req'] ?? $_REQUEST['requestId'] ?? '' ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended

		$error_msg = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['error'] ?? '' ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		if ( $status === '' && $error_msg !== '' ) {
			$status = 'error';
		}

		if ( $ret !== '' && strpos( $ret, home_url() ) !== 0 ) {
			$ret = '';
		}

		$entry  = null;
		$anchor = '';
		if ( $entry_id > 0 ) {
			$repo  = new EntryRepository();
			$entry = $repo->get_by_id( $entry_id );
			if ( $entry !== null ) {
				if ( $ret === '' && $entry->return_url !== '' ) {
					$ret = $entry->return_url;
				}
				if ( $entry->form_id > 0 ) {
					$anchor = '#iftp-payment-status-' . $entry->form_id;
				}
			}
		}
		if ( $ret === '' ) {
			$ret = home_url( '/' );
		}

		if ( $method === 'POST' ) {

			self::handle_webhook( $entry_id, $apk, $mtd, $req );
			echo 'OK'; // placeholderphpcs:ignore(try fixing) WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		if ( $apk !== '' && $val !== '' && $status === '' ) {

			self::process_success( $entry_id, $apk, $val, $mtd, $req );
			wp_safe_redirect(
				add_query_arg(
					array(
						'iftp_cf7_pay'   => 'success',
						'iftp_cf7_entry' => $entry_id,
					),
					$ret
				) . $anchor
			);
			exit;
		}

		if ( $status !== '' ) {

			$new_status = $status === 'cancel' ? 'cancelled' : 'failed';
			self::process_other( $entry_id, $new_status, $mtd, $req );
			wp_safe_redirect(
				add_query_arg(
					array(
						'iftp_cf7_pay'   => $status,
						'iftp_cf7_entry' => $entry_id,
					),
					$ret
				) . $anchor
			);
			exit;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	}



	private static function process_success( int $entry_id, string $apk, string $val = '', string $method = '', string $request_id = '' ): void {
		if ( $entry_id <= 0 ) {
			return;
		}

		$expected = Settings::get_anti_phishing_key();
		if ( $expected !== '' && $apk !== $expected ) {
			return;
		}

		$repo  = new EntryRepository();
		$entry = $repo->get_by_id( $entry_id );
		if ( $entry === null ) {
			return;
		}

		if ( $val !== '' && number_format( (float) $val, 2, '.', '' ) !== number_format( $entry->amount, 2, '.', '' ) ) {
			return;
		}

		$repo->update_transaction(
			$entry->id,
			$method,
			'completed',
			$request_id !== '' ? $request_id : null
		);
	}

	private static function process_other( int $entry_id, string $status, string $method = '', string $request_id = '' ): void {
		if ( $entry_id <= 0 ) {
			return;
		}
		$repo = new EntryRepository();
		if ( $method !== '' || $request_id !== '' ) {
			$repo->update_transaction(
				$entry_id,
				$method,
				$status,
				$request_id !== '' ? $request_id : null
			);
		} else {
			$repo->update_status( $entry_id, $status );
		}
	}

	private static function handle_webhook( int $entry_id, string $apk, string $method_get = '', string $req_get = '' ): void {
		if ( $entry_id <= 0 ) {
			return;
		}

		$expected = Settings::get_anti_phishing_key();
		if ( $expected !== '' && $apk !== $expected ) {
			return;
		}

		$repo  = new EntryRepository();
		$entry = $repo->get_by_id( $entry_id );
		if ( $entry === null ) {
			return;
		}

		$val = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['val'] ?? $_REQUEST['amount'] ?? '' ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Recommended
		if ( $val !== '' && number_format( (float) $val, 2, '.', '' ) !== number_format( $entry->amount, 2, '.', '' ) ) {
			return;
		}

		$method     = sanitize_text_field( wp_unslash( (string) ( $_POST['PaymentMethod'] ?? $_POST['Method'] ?? $method_get ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Missing
		$request_id = sanitize_text_field( wp_unslash( (string) ( $_POST['RequestId'] ?? $_POST['requestId'] ?? $req_get ) ) ); // placeholderphpcs:ignore(try fixing) WordPress.Security.NonceVerification.Missing

		$repo->update_transaction(
			$entry->id,
			$method,
			'completed',
			$request_id !== '' ? $request_id : null
		);

		do_action( 'iftp_cf7_payment_confirmed', $entry->id, $method );
	}



	/**
	 * Build the callback/return URL for ifthenpay.
	 *
	 * @param int    $entry_id    DB entry ID (used as 'ref' param).
	 * @param float  $amount      Payment amount (used as 'val' param for success URL).
	 * @param string $gateway_key Gateway key (base64-encoded as 'apk' param).
	 * @param string $return_url  URL the browser should be sent back to after processing.
	 */
	/**
	 * Success URL — ifthenpay replaces [REQUESTID], [PAYMENTMETHOD] with real values.
	 *
	 * Pattern (matching MemberPress):
	 *   /iftp_cf7?callback=iftp_cf7&ref={id}&apk={base64}&val={amount}
	 *              &mtd=[PAYMENTMETHOD]&req=[REQUESTID]&ret={form_url}
	 */
	public static function build_success_url( int $entry_id, float $amount, string $gateway_key, string $return_url ): string {
		$url = add_query_arg(
			array(
				'callback' => self::CALLBACK_ID,
				'ref'      => $entry_id,
				'apk'      => base64_encode( $gateway_key ), // placeholderphpcs:ignore(try fixing) WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'val'      => number_format( $amount, 2, '.', '' ),
				'ret'      => $return_url,
			),
			home_url( '/' . self::SLUG )
		);

		return $url . '&mtd=[PAYMENTMETHOD]&req=[REQUESTID]';
	}

	public static function build_status_url( int $entry_id, string $status, string $return_url ): string {
		return add_query_arg(
			array(
				'callback' => self::CALLBACK_ID,
				'ref'      => $entry_id,
				'status'   => $status,
				'ret'      => $return_url,
			),
			home_url( '/' . self::SLUG )
		);
	}
}
