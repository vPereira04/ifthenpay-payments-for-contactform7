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

		add_action('iftp_cf7_expire_payments', function (): void {
			(new \Ifthenpay\CF7\Repository\EntryRepository())->mark_expired_pending(Settings::get_expire_days());
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
			add_action('wp_ajax_iftp_cf7_dismiss_info_box', array($entries_ajax, 'ajax_dismiss_info_box'));

			add_action('admin_menu', array($this, 'register_admin_menus'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
			add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_shortcut'));
			add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));
			add_filter('admin_footer_text', array($this, 'footer_powered_by'));

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
			'title' => '<span class="iftp-ab-icon ab-icon" aria-hidden="true"></span>'
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

		wp_add_inline_style(
			'admin-bar',
			'@font-face{font-family:ifthenpay-icons-ab;'
				. 'src:url(' . $woff2 . ') format("woff2"),url(' . $woff . ') format("woff");'
				. 'font-display:block}'
				. '#wp-admin-bar-ifthenpay-cf7-entries > .ab-item{display:flex;align-items:center;gap:6px}'
				. '#wp-admin-bar-ifthenpay-cf7-entries .iftp-ab-icon::before{'
				. 'content:"\E000"; font-family:ifthenpay-icons-ab !important; font-size:25px !important;'
				. 'transition:color .12s !important}'
		);
	}

	public function register_service(): void
	{
		if (! class_exists('WPCF7_Integration') || ! class_exists('WPCF7_Service')) {
			return;
		}

		require_once IFTP_CF7_DIR . 'src/Service/IfthenpayService.php';

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

	public function enqueue_admin_shortcut(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		wp_localize_script('common', 'iftpCf7Shortcut', array(
			'url' => admin_url('admin.php?page=ifthenpay-cf7-entries'),
		));
		wp_add_inline_script(
			'common',
			'document.addEventListener("keydown",function(e){' .
				'if(!(e.ctrlKey||e.metaKey)||!e.shiftKey||e.code!=="KeyF")return;' .
				'var t=(document.activeElement||{}).tagName||"";' .
				'if(t==="INPUT"||t==="TEXTAREA"||t==="SELECT")return;' .
				'e.preventDefault();window.location.href=(window.iftpCf7Shortcut||{}).url||"";' .
			'});'
		);
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


		if ($hook === 'contact_page_ifthenpay-cf7-entries' && current_user_can('manage_options')) {

			wp_enqueue_script(
				'ifthenpay-cf7-entries-restore',
				IFTP_CF7_URL . 'assets/js/admin-entries-restore.js',
				array(),
				$this->asset_version('assets/js/admin-entries-restore.js'),
				false
			);


			wp_enqueue_script(
				'ifthenpay-cf7-entries',
				IFTP_CF7_URL . 'assets/js/admin-entries.js',
				array('ifthenpay-cf7-admin'),
				$this->asset_version('assets/js/admin-entries.js'),
				true
			);


			$all_col_keys   = array('id', 'customer_name', 'request_id', 'amount', 'form_title', 'payment_status', 'payment_method', 'payment_link', 'created_at');
			$fixed_col_keys = array('id', 'customer_name');
			$user_id        = get_current_user_id();
			$prefs          = $user_id ? UserPreferences::get($user_id) : UserPreferences::defaults();
			$visible_cols   = ! empty($prefs['visible_columns']) && is_array($prefs['visible_columns'])
				? array_values(array_filter($prefs['visible_columns'], fn($k) => in_array($k, $all_col_keys, true)))
				: $all_col_keys;
			foreach ($fixed_col_keys as $fc) {
				if (! in_array($fc, $visible_cols, true)) {
					$visible_cols[] = $fc;
				}
			}
			$hidden_cols = array_values(array_diff($all_col_keys, $visible_cols));
			if (! empty($hidden_cols)) {
				$col_css = '';
				foreach ($hidden_cols as $hk) {
					$col_css .= '.iftp-cf7-entries-table [data-col="' . sanitize_html_class($hk) . '"] { display: none; }' . "\n";
				}
				wp_add_inline_style('ifthenpay-cf7-admin', $col_css);
			}
		}

		$method_cat      = get_option('iftp_cf7_method_catalog', array());
		$method_logos_js = array();
		foreach (is_array($method_cat) ? $method_cat : array() as $m) {
			if (! empty($m['entity']) && ! empty($m['logo'])) {
				$method_logos_js[strtoupper((string) $m['entity'])] = (string) $m['logo'];
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
				'dismiss_info_box_nonce' => wp_create_nonce('iftp_cf7_dismiss_info_box'),
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

		$default_period = '7';
		$default        = $widget_data[$default_period];
		$dash_data_json = (string) wp_json_encode($widget_data);

		/* translators: %d: number of paid transactions */
		$paid_template = __('from %d paid transactions', 'ifthenpay-payments-for-contactform7');

		$entries_url = admin_url('admin.php?page=ifthenpay-cf7-entries');

		$period_labels = array(
			'1'  => __('Last 24 hours', 'ifthenpay-payments-for-contactform7'),
			'7'  => __('Last 7 days', 'ifthenpay-payments-for-contactform7'),
			'15' => __('Last 15 days', 'ifthenpay-payments-for-contactform7'),
			'30' => __('Last 30 days', 'ifthenpay-payments-for-contactform7'),
		);
?>
		<div class="iftp-metabox-body">
			<span id="iftp-cf7-dash-data" hidden
				data-period="<?php echo esc_attr($default_period); ?>"
				data-chart="<?php echo esc_attr($dash_data_json); ?>"></span>

			<div class="iftp-dash-top-row">
				<div class="iftp-dash-metric">
					<div class="iftp-rev-amount" id="iftp-cf7-dash-revenue">€<?php echo esc_html(number_format($default['revenue'], 2, '.', ',')); ?></div>
					<div class="iftp-rev-sub" id="iftp-cf7-dash-rev-sub" data-template="<?php echo esc_attr($paid_template); ?>">
						<?php
						printf(
							/* translators: %d: number of paid transactions */
							esc_html__('from %d paid transactions', 'ifthenpay-payments-for-contactform7'),
							(int) $default['counts']['completed']
						);
						?>
					</div>
				</div>

				<div class="iftp-dash-period-dropdown" id="iftp-dash-period-dropdown">
					<button type="button"
						class="iftp-action-btn iftp-dash-period-trigger"
						id="iftp-dash-period-trigger"
						aria-haspopup="true"
						aria-expanded="false"
						aria-controls="iftp-dash-period-panel"
						aria-label="<?php esc_attr_e('Time period', 'ifthenpay-payments-for-contactform7'); ?>">
						<span class="iftp-period-cal-icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 448 512" fill="currentColor">
								<path d="M0 464c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V192H0v272zm320-160c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm0 96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm-128-96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm0 96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16h-32c-8.8 0-16-7.2-16-16v-32zm-128-96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16v-32zm0 96c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16v-32zM400 64h-48V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H160V16c0-8.8-7.2-16-16-16h-32c-8.8 0-16 7.2-16 16v48H48C21.5 64 0 85.5 0 112v48h448v-48c0-26.5-21.5-48-48-48z" />
							</svg>
						</span>
						<span class="iftp-dash-period-label" id="iftp-dash-period-label"><?php echo esc_html($period_labels[$default_period]); ?></span>
						<svg class="iftp-period-dropdown-chevron" viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<polyline points="6 9 12 15 18 9" />
						</svg>
					</button>
					<div class="iftp-dash-period-panel" id="iftp-dash-period-panel" hidden role="menu">
						<?php foreach ($period_labels as $pkey => $plabel) : ?>
						<button type="button"
							class="iftp-period-opt<?php echo $pkey === $default_period ? ' active' : ''; ?>"
							role="menuitem"
							data-period="<?php echo esc_attr($pkey); ?>"
							data-label="<?php echo esc_attr($plabel); ?>">
							<span class="iftp-period-opt-dot" aria-hidden="true"></span>
							<?php echo esc_html($plabel); ?>
						</button>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<hr class="iftp-rev-divider" />
			<div class="iftp-stats-list">
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot iftp-stat-dot--pending"></span><?php esc_html_e('Pending', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val iftp-stat-val--pending" id="iftp-cf7-dash-count-pending"><?php echo esc_html((string) $default['counts']['pending']); ?></span>
				</div>
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot iftp-stat-dot--paid"></span><?php esc_html_e('Paid', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val iftp-stat-val--paid" id="iftp-cf7-dash-count-completed"><?php echo esc_html((string) $default['counts']['completed']); ?></span>
				</div>
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot iftp-stat-dot--failed"></span><?php esc_html_e('Failed', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val iftp-stat-val--failed" id="iftp-cf7-dash-count-failed"><?php echo esc_html((string) $default['counts']['failed']); ?></span>
				</div>
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot iftp-stat-dot--cancelled"></span><?php esc_html_e('Cancelled', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val iftp-stat-val--cancelled" id="iftp-cf7-dash-count-cancelled"><?php echo esc_html((string) $default['counts']['cancelled']); ?></span>
				</div>
				<div class="iftp-stat-row">
					<span class="iftp-stat-lbl"><span class="iftp-stat-dot iftp-stat-dot--expired"></span><?php esc_html_e('Expired', 'ifthenpay-payments-for-contactform7'); ?></span>
					<span class="iftp-stat-val iftp-stat-val--expired" id="iftp-cf7-dash-count-expired"><?php echo esc_html((string) ($default['counts']['expired'] ?? 0)); ?></span>
				</div>
			</div>
			<a href="<?php echo esc_url($entries_url); ?>" class="iftp-dash-view-all">
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

	public function footer_powered_by(string $text): string
	{
		$screen = get_current_screen();
		if ($screen === null) {
			return $text;
		}
		$plugin_screens = array(
			'contact_page_ifthenpay-cf7-entries',
		);
		if (! in_array($screen->id, $plugin_screens, true)) {
			return $text;
		}
		$logo_url = esc_url(IFTP_CF7_URL . 'assets/images/ifthenpaylogo.webp');
		$plugin_version = IFTP_CF7_VERSION;
		return '<span class="iftp-bot-title-chip">'
			. '<span class="iftp-bot-title-chip-label">' . esc_html__('Powered by', 'ifthenpay-payments-for-contactform7') . '</span>'
			. '<img src="' . $logo_url . '" alt="ifthenpay" class="iftp-brand-logo-bot" draggable="false" />'
			. '</span>'
			. '<p id="iftp-footer-version" class="alignright"> Version ' . esc_html($plugin_version) . '</p>';
	}

	private function __construct() {}
}
