<?php

declare(strict_types=1);

namespace Ifthenpay\CF7;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Admin\EntriesPage;
use Ifthenpay\CF7\Form\TagGenerator;
use Ifthenpay\CF7\Form\PaymentTag;
use Ifthenpay\CF7\Payment\GatewayEndpoint;
use Ifthenpay\CF7\Payment\Process;
use Ifthenpay\CF7\Service\IfthenpayService;

final class Plugin {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		add_action( 'plugins_loaded', [ $this, 'init_components' ], 20 );

		GatewayEndpoint::register();
	}

	public function init_components(): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			add_action( 'admin_notices', [ $this, 'missing_cf7_notice' ] );
			return;
		}

		Activation::maybe_upgrade();


		add_action( 'wpcf7_admin_init', [ $this, 'register_service' ] );

		$process = new Process();
		add_action( 'wp_ajax_iftp_cf7_create_payment',        [ $process, 'ajax_create_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_cf7_create_payment', [ $process, 'ajax_create_payment' ] );
		add_action( 'wp_ajax_iftp_cf7_verify_payment',        [ $process, 'ajax_verify_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_cf7_verify_payment', [ $process, 'ajax_verify_payment' ] );
		add_action( 'wp_ajax_iftp_cf7_cancel_payment',        [ $process, 'ajax_cancel_payment' ] );
		add_action( 'wp_ajax_nopriv_iftp_cf7_cancel_payment', [ $process, 'ajax_cancel_payment' ] );

		add_action( 'wpcf7_before_send_mail',  [ $process, 'on_before_send_mail' ], 10, 3 );
		add_filter( 'wpcf7_special_mail_tags', [ $process, 'resolve_mail_tags' ], 10, 4 );
		add_filter( 'wpcf7_posted_data',       [ $process, 'inject_posted_fields' ] );


		$tag = new PaymentTag();
		add_action( 'wpcf7_init', [ $tag, 'register' ] );

		if ( is_admin() ) {
			$settings = new \Ifthenpay\CF7\Admin\Settings();
			add_action( 'wp_ajax_iftp_cf7_activate_payment_method', [ $settings, 'ajax_activate_payment_method' ] );

			add_action( 'admin_menu',            [ $this, 'register_admin_menus' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );


			$tag_gen = new TagGenerator();
			add_action( 'wpcf7_admin_init', [ $tag_gen, 'register' ] );
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
	}



	public function register_service(): void {
		if ( ! class_exists( 'WPCF7_Integration' ) || ! class_exists( 'WPCF7_Service' ) ) {
			return;
		}

		$service_file = IFTP_CF7_DIR . 'src/Service/IfthenpayService.php';
		if ( ! file_exists( $service_file ) ) {
			return;
		}

		require_once $service_file;

		if ( ! class_exists( IfthenpayService::class ) ) {
			return;
		}


		\WPCF7_Integration::get_instance()->add_service(
			'ifthenpay',
			IfthenpayService::get_instance()
		);
	}



	public function register_admin_menus(): void {
		add_submenu_page(
			'wpcf7',
			__( 'ifthenpay Entries', 'ifthenpay-payments-for-contactform7' ),
			__( 'ifthenpay Entries', 'ifthenpay-payments-for-contactform7' ),
			'manage_options',
			'ifthenpay-cf7-entries',
			[ new EntriesPage(), 'render_page' ]
		);
	}



	public function enqueue_frontend_assets(): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			return;
		}

		wp_enqueue_script(
			'ifthenpay-cf7-frontend',
			IFTP_CF7_URL . 'assets/js/frontend.js',
			[ 'jquery' ],
			IFTP_CF7_VERSION,
			true
		);

		wp_enqueue_style(
			'ifthenpay-cf7-frontend',
			IFTP_CF7_URL . 'assets/css/frontend.css',
			[],
			IFTP_CF7_VERSION
		);

		wp_localize_script(
			'ifthenpay-cf7-frontend',
			'iftpCf7Front',
			[
				'ajax_url'               => admin_url( 'admin-ajax.php' ),
				'ajax_nonce'             => wp_create_nonce( 'iftp_cf7_frontend' ),
				'opening_text'           => __( 'Opening payment...', 'ifthenpay-payments-for-contactform7' ),
				'warning_amount_missing' => __( 'The payment amount is not set. Please check the form and try again.', 'ifthenpay-payments-for-contactform7' ),
				'msg_pending'            => __( 'Your payment is pending. You can close this window while you wait.', 'ifthenpay-payments-for-contactform7' ),
				'msg_cancel_prefix'      => __( 'Your payment was cancelled!', 'ifthenpay-payments-for-contactform7' ),
				'msg_error_prefix'       => __( 'Your payment failed!', 'ifthenpay-payments-for-contactform7' ),
				'msg_retry'              => __( 'Retry', 'ifthenpay-payments-for-contactform7' ),
				'msg_or'                 => __( 'or', 'ifthenpay-payments-for-contactform7' ),
				'msg_new_payment'        => __( 'New Payment', 'ifthenpay-payments-for-contactform7' ),
			]
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		$cf7_hooks = [
			'toplevel_page_wpcf7',
			'contact_page_wpcf7-new',
			'contact_page_wpcf7-integration',
			'contact_page_ifthenpay-cf7-entries',
		];

		if ( ! in_array( $hook, $cf7_hooks, true ) ) {
			return;
		}

		wp_enqueue_script(
			'ifthenpay-cf7-admin',
			IFTP_CF7_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			IFTP_CF7_VERSION,
			true
		);

		wp_enqueue_style(
			'ifthenpay-cf7-admin',
			IFTP_CF7_URL . 'assets/css/admin.css',
			[],
			IFTP_CF7_VERSION
		);

		wp_localize_script(
			'ifthenpay-cf7-admin',
			'iftpCf7Admin',
			[
				'ajax_url'  => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'iftp_cf7_settings' ),
			]
		);
	}



	public function missing_cf7_notice(): void {
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'ifthenpay | Payments for Contact Form 7', 'ifthenpay-payments-for-contactform7' ),
			esc_html__( 'requires Contact Form 7 to be installed and active.', 'ifthenpay-payments-for-contactform7' )
		);
	}

	private function __construct() {}
}
