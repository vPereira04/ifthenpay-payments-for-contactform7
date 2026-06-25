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

	function ifthenpay_cf7_integration_uninstall(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ifthenpay_cf7_entries' ) );

		delete_option('iftp_cf7_settings');
		delete_option('iftp_cf7_gateway_catalog');
		delete_option('iftp_cf7_method_catalog');
		delete_option('iftp_cf7_db_version');

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$iftp_cf7_option_names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				'iftp_cf7_form_config_%'
			)
		);
		foreach ( (array) $iftp_cf7_option_names as $iftp_cf7_name ) {
			delete_option( (string) $iftp_cf7_name );
		}
	}

ifthenpay_cf7_integration_uninstall();
