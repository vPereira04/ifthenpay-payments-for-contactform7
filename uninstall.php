<?php

/**
 * Uninstall routine — drops the plugin table and deletes all plugin options.
 *
 * @package Ifthenpay\CF7
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching


// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ifthenpay_cf7_entries");


delete_option('iftp_cf7_settings');
delete_option('iftp_cf7_gateway_catalog');
delete_option('iftp_cf7_method_catalog');
delete_option('iftp_cf7_db_version');


$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'iftp_cf7_form_config_%'
	)
);

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
