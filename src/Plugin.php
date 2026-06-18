<?php

declare(strict_types=1);

namespace Ifthenpay\CF7;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Admin\EntriesPage;
use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Admin\UserPreferences;
use Ifthenpay\CF7\Form\TagGenerator;
use Ifthenpay\CF7\Form\PaymentTag;
use Ifthenpay\CF7\Payment\GatewayEndpoint;
use Ifthenpay\CF7\Payment\Process;
use Ifthenpay\CF7\Service\IfthenpayService;

final class Plugin
{

	private static ?self $instance = null;

	public static function instance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void
	{
		add_action('plugins_loaded', array($this, 'init_components'), 20);

		GatewayEndpoint::register();
	}

	public function init_components(): void
	{
		if (! defined('WPCF7_VERSION')) {
			add_action('admin_notices', array($this, 'missing_cf7_notice'));
			return;
		}

		Activation::maybe_upgrade();

		add_action('iftp_cf7_cleanup_stale', function (): void {
			(new \Ifthenpay\CF7\Repository\EntryRepository())->mark_stale_pending();
		});

		add_action('wpcf7_admin_init', array($this, 'register_service'));

		$process = new Process();
		add_action('wp_ajax_iftp_cf7_create_payment', array($process, 'ajax_create_payment'));
		add_action('wp_ajax_nopriv_iftp_cf7_create_payment', array($process, 'ajax_create_payment'));
		add_action('wp_ajax_iftp_cf7_verify_payment', array($process, 'ajax_verify_payment'));
		add_action('wp_ajax_nopriv_iftp_cf7_verify_payment', array($process, 'ajax_verify_payment'));
		add_action('wp_ajax_iftp_cf7_cancel_payment', array($process, 'ajax_cancel_payment'));
		add_action('wp_ajax_nopriv_iftp_cf7_cancel_payment', array($process, 'ajax_cancel_payment'));

		add_action('wpcf7_before_send_mail', array($process, 'on_before_send_mail'), 10, 3);
		add_filter('wpcf7_special_mail_tags', array($process, 'resolve_mail_tags'), 10, 4);
		add_filter('wpcf7_posted_data', array($process, 'inject_posted_fields'));
		add_filter('wpcf7_validate_email', array($process, 'validate_email_length'), 20, 2);
		add_filter('wpcf7_validate_email*', array($process, 'validate_email_length'), 20, 2);

		add_filter('wpcf7_form_action_url', array($this, 'strip_payment_params_from_action_url'));

		$tag = new PaymentTag();
		add_action('wpcf7_init', array($tag, 'register'));

		if (is_admin()) {
			$settings = new \Ifthenpay\CF7\Admin\Settings();
			add_action('wp_ajax_iftp_cf7_activate_payment_method', array($settings, 'ajax_activate_payment_method'));

			$entries_ajax = new EntriesPage();
			add_action('wp_ajax_iftp_cf7_add_payment', array($entries_ajax, 'ajax_add_payment'));
			add_action('wp_ajax_iftp_cf7_save_entries_prefs', array($entries_ajax, 'ajax_save_preferences'));
			add_action('wp_ajax_iftp_cf7_dismiss_ap_notice', array($entries_ajax, 'ajax_dismiss_add_payment_notice'));

			add_action('admin_menu', array($this, 'register_admin_menus'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
			add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));

			$tag_gen = new TagGenerator();
			add_action('wpcf7_admin_init', array($tag_gen, 'register'));
		}

		add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));

		add_action('admin_bar_menu', array($this, 'add_admin_bar_entries_node'), 100);
		add_action('wp_enqueue_scripts', array($this, 'enqueue_admin_bar_styles'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_bar_styles'));
	}



	public function add_admin_bar_entries_node(\WP_Admin_Bar $wp_admin_bar): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		$wp_admin_bar->add_node(array(
			'id'    => 'ifthenpay-cf7-entries',
			'title' => '<span class="iftp-ab-icon" aria-hidden="true">&#xE000;</span>'
				. '<span class="ab-label">' . esc_html__('Entries', 'ifthenpay-payments-for-contactform7') . '</span>',
			'href'  => admin_url('admin.php?page=ifthenpay-cf7-entries'),
			'meta'  => array('class' => 'ifthenpay-cf7-ab-node'),
		));
	}



	public function enqueue_admin_bar_styles(): void
	{
		if (! is_admin_bar_showing() || ! current_user_can('manage_options')) {
			return;
		}

		$woff2 = esc_url(IFTP_CF7_URL . 'assets/fonts/ifthenpay-icons.woff2');
		$woff  = esc_url(IFTP_CF7_URL . 'assets/fonts/ifthenpay-icons.woff');

		wp_add_inline_style('admin-bar',
			'@font-face{font-family:ifthenpay-icons-ab;'
			. 'src:url(' . $woff2 . ') format("woff2"),url(' . $woff . ') format("woff");'
			. 'font-display:block}'
			. '#wp-admin-bar-ifthenpay-cf7-entries > .ab-item{display:flex;align-items:center;gap:5px}'
			. '#wp-admin-bar-ifthenpay-cf7-entries .iftp-ab-icon{'
			. 'font-family:ifthenpay-icons-ab;speak:never;font-style:normal;font-weight:400;'
			. 'font-size:17px;line-height:1;display:inline-flex;align-items:center;flex-shrink:0;'
			. 'margin-top:5px;'
			. 'color:rgba(240,245,250,.65);transition:color .12s}'
			. '#wp-admin-bar-ifthenpay-cf7-entries:hover .iftp-ab-icon,'
			. '#wp-admin-bar-ifthenpay-cf7-entries.hover .iftp-ab-icon{color:#fff}'
		);
	}



	public function register_service(): void
	{
		if (! class_exists('WPCF7_Integration') || ! class_exists('WPCF7_Service')) {
			return;
		}

		$service_file = IFTP_CF7_DIR . 'src/Service/IfthenpayService.php';
		if (! file_exists($service_file)) {
			return;
		}

		require_once $service_file;

		if (! class_exists(IfthenpayService::class)) {
			return;
		}

		\WPCF7_Integration::get_instance()->add_service(
			'ifthenpay',
			IfthenpayService::get_instance()
		);
	}



	public function register_admin_menus(): void
	{
		$entries_page = new EntriesPage();
		$hook         = add_submenu_page(
			'wpcf7',
			__('ifthenpay Entries', 'ifthenpay-payments-for-contactform7'),
			__('ifthenpay Entries', 'ifthenpay-payments-for-contactform7'),
			'manage_options',
			'ifthenpay-cf7-entries',
			array($entries_page, 'render_page')
		);
		add_action('load-' . $hook, array($entries_page, 'process_actions'));
	}



	public function strip_payment_params_from_action_url(string $url): string
	{
		return remove_query_arg(array('iftp_cf7_pay', 'iftp_cf7_entry'), $url);
	}



	public function enqueue_frontend_assets(): void
	{
		if (! defined('WPCF7_VERSION')) {
			return;
		}

		wp_enqueue_script(
			'ifthenpay-cf7-frontend',
			IFTP_CF7_URL . 'assets/js/frontend.js',
			array('jquery'),
			$this->asset_version('assets/js/frontend.js'),
			true
		);

		wp_enqueue_style(
			'ifthenpay-cf7-frontend',
			IFTP_CF7_URL . 'assets/css/frontend.css',
			array(),
			$this->asset_version('assets/css/frontend.css')
		);

		wp_localize_script(
			'ifthenpay-cf7-frontend',
			'iftpCf7Front',
			array(
				'ajax_url'               => admin_url('admin-ajax.php'),
				'ajax_nonce'             => wp_create_nonce('iftp_cf7_frontend'),
				'opening_text'           => __('Opening payment...', 'ifthenpay-payments-for-contactform7'),
				'warning_amount_missing' => __('The payment amount is not set. Please check the form and try again.', 'ifthenpay-payments-for-contactform7'),
				'msg_retry'              => __('Retry', 'ifthenpay-payments-for-contactform7'),
				'msg_or'                 => __('or', 'ifthenpay-payments-for-contactform7'),
				'msg_new_payment'        => __('New Payment', 'ifthenpay-payments-for-contactform7'),
				'error_field_required'   => __('Please fill in this field.', 'ifthenpay-payments-for-contactform7'),
				'error_email_too_long'   => __('Email address must be 100 characters or fewer.', 'ifthenpay-payments-for-contactform7'),
			)
		);
	}

	/**
	 * Version string for an asset: file mtime when readable (busts cache on every
	 * edit), falling back to the plugin version. Path is relative to the plugin dir.
	 */
	private function asset_version(string $relative_path): string
	{
		$full = IFTP_CF7_DIR . $relative_path;
		$mtime = is_readable($full) ? filemtime($full) : false;
		return $mtime !== false ? (string) $mtime : IFTP_CF7_VERSION;
	}

	public function enqueue_admin_assets(string $hook): void
	{
		$cf7_hooks = array(
			'index.php',
			'toplevel_page_wpcf7',
			'contact_page_wpcf7-new',
			'contact_page_wpcf7-integration',
			'contact_page_ifthenpay-cf7-entries',
		);

		if (! in_array($hook, $cf7_hooks, true)) {
			return;
		}

		wp_enqueue_script(
			'ifthenpay-cf7-admin',
			IFTP_CF7_URL . 'assets/js/admin.js',
			array('jquery'),
			$this->asset_version('assets/js/admin.js'),
			true
		);

		wp_enqueue_style(
			'ifthenpay-cf7-admin',
			IFTP_CF7_URL . 'assets/css/admin.css',
			array(),
			$this->asset_version('assets/css/admin.css')
		);

		$method_cat      = get_option('iftp_cf7_method_catalog', array());
		$method_logos_js = array();
		foreach (is_array($method_cat) ? $method_cat : array() as $m) {
			if (! empty($m['entity']) && ! empty($m['logo'])) {
				$method_logos_js[ strtoupper((string) $m['entity']) ] = (string) $m['logo'];
			}
		}

		wp_localize_script(
			'ifthenpay-cf7-admin',
			'iftpCf7Admin',
			array(
				'ajax_url'              => admin_url('admin-ajax.php'),
				'nonce'                 => wp_create_nonce('iftp_cf7_settings'),
				'add_payment_nonce'     => wp_create_nonce('iftp_cf7_add_payment'),
				'prefs_nonce'           => wp_create_nonce('iftp_cf7_entries_prefs'),
				'dismiss_notice_nonce'  => wp_create_nonce('iftp_cf7_dismiss_ap_notice'),
				'default_col_order'     => UserPreferences::defaults()['column_positions'],
				'activate_method_label' => __('Activate Method', 'ifthenpay-payments-for-contactform7'),
				'saved_methods'         => Settings::get_methods(),
				'method_logos'          => $method_logos_js,
				'method_colors'         => array(
					'MBWAY'         => '#00a550',
					'MULTIBANCO'    => '#2271b1',
					'MB'            => '#2271b1',
					'CARD'          => '#dba617',
					'PAYSHOP'       => '#e84c3d',
					'COFIDIS'       => '#003d8f',
					'IFTHENPAYLINK' => '#f90',
				),
			)
		);
	}

	public function register_dashboard_widget(): void
	{
		wp_add_dashboard_widget(
			'ifthenpay_cf7_revenue',
			__('ifthenpay Payments', 'ifthenpay-payments-for-contactform7'),
			array($this, 'render_dashboard_widget')
		);
	}

	public function render_dashboard_widget(): void
	{
		$repo = new \Ifthenpay\CF7\Repository\EntryRepository();


		$widget_data = $repo->get_widget_period_stats();

		$default_period = '1';
		$default        = $widget_data[$default_period];
		$dash_data_json = (string) wp_json_encode($widget_data);

		/* translators: %d: number of paid transactions */
		$paid_template = esc_attr(__('from %d paid transactions', 'ifthenpay-payments-for-contactform7'));

		$entries_url = admin_url('admin.php?page=ifthenpay-cf7-entries');
?>
		<div class="iftp-metabox-body">
			<span id="iftp-cf7-dash-data" hidden
				data-period="<?php echo esc_attr($default_period); ?>"
				data-chart="<?php echo esc_attr($dash_data_json); ?>"></span>
			<div class="iftp-period-tabs iftp-dash-period-tabs" role="group" aria-label="<?php esc_attr_e('Time period', 'ifthenpay-payments-for-contactform7'); ?>">
				<div class="iftp-period-tabs-icon">e</div>
				<button type="button" class="iftp-period-tab" data-period="30">30d</button>
				<button type="button" class="iftp-period-tab" data-period="15">15d</button>
				<button type="button" class="iftp-period-tab" data-period="7">7d</button>
				<button type="button" class="iftp-period-tab active" data-period="1">1d</button>
			</div>
			<div class="iftp-rev-amount" id="iftp-cf7-dash-revenue">€<?php echo esc_html(number_format($default['revenue'], 2, '.', ',')); ?></div>
			<div class="iftp-rev-sub" id="iftp-cf7-dash-rev-sub" data-template="<?php echo $paid_template; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr'd above
																				?>">
				<?php
				printf(
					/* translators: %d: number of paid transactions */
					esc_html__('from %d paid transactions', 'ifthenpay-payments-for-contactform7'),
					(int) $default['counts']['completed']
				);
				?>
			</div>
			<hr class="iftp-rev-divider" />
			<div class="iftp-stats-list">
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#dba617"></span><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val" id="iftp-cf7-dash-count-pending"><?php echo esc_html((string) $default['counts']['pending']); ?></span>
				</div>
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#00a550"></span><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val" id="iftp-cf7-dash-count-completed"><?php echo esc_html((string) $default['counts']['completed']); ?></span>
				</div>
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#d63638"></span><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val" id="iftp-cf7-dash-count-failed"><?php echo esc_html((string) $default['counts']['failed']); ?></span>
				</div>
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot" style="background:#8c8f94"></span><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val" id="iftp-cf7-dash-count-cancelled"><?php echo esc_html((string) $default['counts']['cancelled']); ?></span>
				</div>
				<br></br>
			</div>
			<a href="<?php echo esc_url($entries_url); ?>" class="button button-secondary iftp-dash-view-all">
				<?php esc_html_e('View all entries', 'ifthenpay-payments-for-contactform7'); ?>
			</a>
		</div>
<?php
	}

	public function missing_cf7_notice(): void
	{
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__('ifthenpay | Payments for Contact Form 7', 'ifthenpay-payments-for-contactform7'),
			esc_html__('requires Contact Form 7 to be installed and active.', 'ifthenpay-payments-for-contactform7')
		);
	}

	private function __construct() {}
}
