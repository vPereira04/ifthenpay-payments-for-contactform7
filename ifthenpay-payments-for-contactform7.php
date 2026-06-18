<?php
/**
 * Plugin Name:       ifthenpay | Payments for Contact Form 7
 * Plugin URI:        https://ifthenpay.com
 * Description:       ifthenpay Pay by Link integration for Contact Form 7.
 * Version:           1.0.0
 * Tested up to:      7.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Requires Plugins:  contact-form-7
 * Author:            ifthenpay
 * Author URI:        https://ifthenpay.com/
 * License:           GPL v3
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ifthenpay-payments-for-contactform7
 * Domain Path:       /languages
 *
 * @package Ifthenpay\CF7
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IFTP_CF7_VERSION', '1.0.0' );
define( 'IFTP_CF7_FILE', __FILE__ );
define( 'IFTP_CF7_DIR', plugin_dir_path( __FILE__ ) );
define( 'IFTP_CF7_URL', plugin_dir_url( __FILE__ ) );
define( 'IFTP_CF7_SLUG', 'iftp_cf7' );
define( 'IFTP_CF7_TABLE', 'ifthenpay_cf7_entries' );
define( 'IFTP_CF7_GATEWAY_TYPE', 'ContactForm7' );

$ifthenpay_cf7_autoload = IFTP_CF7_DIR . 'vendor/autoload.php';
if ( file_exists( $ifthenpay_cf7_autoload ) ) {
	require_once $ifthenpay_cf7_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Ifthenpay\\CF7\\';
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}
			$relative = substr( $class_name, strlen( $prefix ) );
			$file     = IFTP_CF7_DIR . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

require_once IFTP_CF7_DIR . 'src/Activation.php';
require_once IFTP_CF7_DIR . 'src/Plugin.php';

register_activation_hook( __FILE__, array( \Ifthenpay\CF7\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Ifthenpay\CF7\Activation::class, 'deactivate' ) );

\Ifthenpay\CF7\Plugin::instance()->init();
