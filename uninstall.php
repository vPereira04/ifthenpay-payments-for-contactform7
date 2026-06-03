<?php

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ifthenpay_cf7_entries" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange

delete_option( 'iftp_cf7_settings' );
delete_option( 'iftp_cf7_gateway_catalog' );
delete_option( 'iftp_cf7_method_catalog' );
delete_option( 'iftp_cf7_db_version' );

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'iftp\_cf7\_form\_config\_%'"
);
