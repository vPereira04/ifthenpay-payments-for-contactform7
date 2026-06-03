<?php

declare(strict_types=1);

namespace Ifthenpay\CF7;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class Activation {

	private const DB_VERSION     = '1.3';
	private const DB_VERSION_KEY = 'iftp_cf7_db_version';

	public static function activate(): void {
		self::create_table();
		update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );

		\Ifthenpay\CF7\Payment\GatewayEndpoint::flush();
	}

	public static function deactivate(): void {}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_KEY ) !== self::DB_VERSION ) {
			self::create_table();
			update_option( self::DB_VERSION_KEY, self::DB_VERSION, false );
		}
	}

	private static function create_table(): void {
		global $wpdb;

		$table      = $wpdb->prefix . IFTP_CF7_TABLE;
		$charset    = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			form_title   VARCHAR(255)    NOT NULL DEFAULT '',
			customer_name  VARCHAR(255)  NOT NULL DEFAULT '',
			customer_email VARCHAR(100)  NOT NULL DEFAULT '',
			amount       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
			payment_method VARCHAR(20)   NOT NULL DEFAULT '',
			transaction_id VARCHAR(100)  NOT NULL DEFAULT '',
			payment_status VARCHAR(20)   NOT NULL DEFAULT 'pending',
			payment_url  VARCHAR(500)    NOT NULL DEFAULT '',
			return_url   VARCHAR(500)    NOT NULL DEFAULT '',
			form_data    LONGTEXT        NOT NULL,
			modal_token  VARCHAR(64)     NOT NULL DEFAULT '',
			request_id   VARCHAR(100)    DEFAULT NULL,
			is_read      TINYINT(1)      NOT NULL DEFAULT 0,
			created_at   DATETIME        NOT NULL,
			updated_at   DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			KEY          idx_form_id       (form_id),
			KEY          idx_transaction_id (transaction_id(20)),
			KEY          idx_payment_status (payment_status),
			KEY          idx_modal_token   (modal_token(32))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
