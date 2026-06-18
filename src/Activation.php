<?php

declare(strict_types=1);

namespace Ifthenpay\CF7;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

final class Activation
{

	private const DB_VERSION     = '1.9';
	private const DB_VERSION_KEY = 'iftp_cf7_db_version';

	public static function activate(): void
	{
		self::create_table();
		update_option(self::DB_VERSION_KEY, self::DB_VERSION, false);

		if (! wp_next_scheduled('iftp_cf7_cleanup_stale')) {
			wp_schedule_event(time(), 'hourly', 'iftp_cf7_cleanup_stale');
		}

		\Ifthenpay\CF7\Payment\GatewayEndpoint::flush();
	}

	public static function deactivate(): void
	{
		$timestamp = wp_next_scheduled('iftp_cf7_cleanup_stale');
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'iftp_cf7_cleanup_stale');
		}
		flush_rewrite_rules();
	}

	public static function maybe_upgrade(): void
	{
		if (get_option(self::DB_VERSION_KEY) !== self::DB_VERSION) {
			self::drop_superseded_indexes();
			self::create_table();
			update_option(self::DB_VERSION_KEY, self::DB_VERSION, false);
		}
	}

	/** Drop single-column indexes superseded by the v1.8 composite index. dbDelta() never drops, so we do it explicitly. */
	private static function drop_superseded_indexes(): void
	{
		global $wpdb;
		$table = $wpdb->prefix . IFTP_CF7_TABLE;
		foreach (array('idx_payment_status', 'idx_created_at', 'idx_updated_at') as $idx) {
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema migration; one-time upgrade check.
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
					$table,
					$idx
				)
			);
			if ($exists) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema migration on plugin upgrade; identifiers escaped via %i.
				$wpdb->query($wpdb->prepare('ALTER TABLE %i DROP INDEX %i', $table, $idx));
			}
		}
	}

	private static function create_table(): void
	{
		global $wpdb;

		$table   = $wpdb->prefix . IFTP_CF7_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			form_title   VARCHAR(255)    NOT NULL DEFAULT '',
			customer_name  VARCHAR(255)  NOT NULL DEFAULT '',
			customer_email VARCHAR(100)  NOT NULL DEFAULT '',
			customer_ip  VARCHAR(45)     NOT NULL DEFAULT '',
			amount       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
			payment_method VARCHAR(20)   NOT NULL DEFAULT '',
			transaction_id VARCHAR(100)  NOT NULL DEFAULT '',
			payment_status VARCHAR(20)   NOT NULL DEFAULT 'pending',
			payment_url  VARCHAR(500)    NOT NULL DEFAULT '',
			return_url   VARCHAR(500)    NOT NULL DEFAULT '',
			form_data    LONGTEXT        NOT NULL,
			request_id   VARCHAR(100)    DEFAULT NULL,
			created_at   DATETIME        NOT NULL,
			updated_at   DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			KEY          idx_form_id               (form_id),
			KEY          idx_transaction_id        (transaction_id(20)),
			KEY          idx_customer_name         (customer_name(100)),
			KEY          idx_customer_email        (customer_email),
			KEY          idx_status_amount         (payment_status, amount),
			KEY          idx_status_id             (payment_status, id),
			KEY          idx_updated_status_amount (updated_at, payment_status, amount)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}
}
