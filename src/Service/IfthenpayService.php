<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Service;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Api\IfthenpayApiFacade;
use Ifthenpay\CF7\Api\IfthenpayClient;
use Ifthenpay\CF7\Payment\GatewayEndpoint;

if (! class_exists('WPCF7_Service')) {
	return;
}

/**
 * CF7 Integration page service for ifthenpay.
 *
 * Two-stage setup:
 *   Stage 1 — Enter Backoffice Key → Connect
 *   Stage 2 — Gateway key (first auto-selected) + methods table + default
 *              method + description + expire days → Save
 *
 * CF7 pipes display() through WPCF7_HTMLFormatter / wp_kses — <script> stripped.
 * All JS lives in admin.js.
 */
final class IfthenpayService extends \WPCF7_Service
{

	private static ?self $instance = null;

	public static function get_instance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function get_title(): string
	{
		return 'ifthenpay Payment Gateway';
	}

	public function get_categories(): array
	{
		return array('payments');
	}

	public function is_active(): bool
	{
		$s = Settings::get_settings();
		return ! empty($s['backoffice_key']) && ! empty($s['gateway_key']);
	}

	public function icon(): void {}

	public function link(): void
	{
		echo wp_kses_data('<a href="https://ifthenpay.com/" target="_blank" rel="noopener noreferrer">ifthenpay.com</a>');
	}

	public function load($action = ''): void
	{
		if ('setup' !== $action) {
			return;
		}

		$method     = strtoupper(sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? ''))));
		$sub_action = '';

		if ($method === 'GET') {
			$sub_action = isset($_GET['iftp_action'])
				? sanitize_key(wp_unslash((string) $_GET['iftp_action']))
				: '';
		} elseif ($method === 'POST') {
			$sub_action = isset($_POST['iftp_action'])
				? sanitize_key(wp_unslash((string) $_POST['iftp_action']))
				: '';
		}

		if ($sub_action === '') {
			if ($method === 'GET') {
				$bk      = Settings::get_backoffice_key();
				$message = sanitize_key(wp_unslash((string) ($_GET['message'] ?? '')));

				$no_refresh = array('saved', 'reset');
				if ($bk !== '' && ! in_array($message, $no_refresh, true)) {
					IfthenpayApiFacade::connect($bk);
				}
			}
			return;
		}

		if ($sub_action === 'reset') {
			if ($method === 'GET') {
				$nonce = isset($_GET['_wpnonce'])
					? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce']))
					: '';
				if (! wp_verify_nonce($nonce, 'iftp_cf7_service_setup') || ! current_user_can('manage_options')) {
					return;
				}
			} else {
				check_admin_referer('iftp_cf7_service_setup');
				if (! current_user_can('manage_options')) {
					return;
				}
			}
			Settings::clear_all();
			IfthenpayApiFacade::clear_catalogs();
			wp_safe_redirect($this->setup_url('message=reset'));
			exit;
		}

		if ($method !== 'POST') {
			return;
		}

		if ($sub_action === 'connect_backoffice') {
			check_admin_referer('iftp_cf7_service_setup');
			if (! current_user_can('manage_options')) {
				return;
			}

			$key = isset($_POST['backoffice_key'])
				? sanitize_text_field(wp_unslash((string) $_POST['backoffice_key']))
				: '';

			if ($key === '' || ! preg_match('/^\d{4}-\d{4}-\d{4}-\d{4}$/', $key)) {
				wp_safe_redirect($this->setup_url('message=invalid_key'));
				exit;
			}


			$result = IfthenpayApiFacade::verify_and_save_gateway($key);

			if (! $result['ok']) {
				$msg = $result['error'] === 'no_gateways' ? 'no_gateways' : 'connect_failed';
				wp_safe_redirect($this->setup_url("message={$msg}"));
				exit;
			}

			Settings::update_settings(
				array(
					'backoffice_key' => $key,
					'gateway_key'    => '',
				)
			);
			wp_safe_redirect($this->setup_url('message=connected'));
			exit;
		}

		if ($sub_action === 'save_config') {
			check_admin_referer('iftp_cf7_service_setup');
			if (! current_user_can('manage_options')) {
				return;
			}

			$gateway_key = isset($_POST['gateway_key'])
				? sanitize_text_field(wp_unslash((string) $_POST['gateway_key']))
				: '';

			$default_method = isset($_POST['default_method'])
				? strtoupper(sanitize_text_field(wp_unslash((string) $_POST['default_method'])))
				: '';

			$description = isset($_POST['description'])
				? sanitize_text_field(wp_unslash((string) $_POST['description']))
				: 'Payment';
			$expire_days = isset($_POST['expire_days'])
				? max(1, absint($_POST['expire_days']))
				: 3;

			$methods_raw = isset($_POST['methods']) && is_array($_POST['methods'])
				? map_deep(wp_unslash((array) $_POST['methods']), 'sanitize_text_field')
				: array();

			$api_methods    = IfthenpayApiFacade::get_methods_for_gateway($gateway_key);
			$methods_config = array();

			foreach ($api_methods as $entity => $m_data) {
				$entity_uc                    = strtoupper((string) $entity);
				$methods_config[$entity_uc] = array(
					'enabled' => ! empty($methods_raw[$entity_uc]['enabled']),
					'account' => sanitize_text_field((string) ($m_data['account'] ?? '')),
				);
			}

			Settings::update_settings(
				array(
					'gateway_key'    => $gateway_key,
					'default_method' => $default_method,
					'methods'        => $methods_config,
					'description'    => $description,
					'expire_days'    => $expire_days,
				)
			);

			if ($gateway_key !== '') {
				$base_cb = home_url('/' . GatewayEndpoint::SLUG . '/');
				$cb_ok = IfthenpayClient::activate_callback($gateway_key, $base_cb, Settings::ensure_anti_phishing_key());
				if (! $cb_ok) {
					do_action('iftp_cf7_log', sprintf('[iftp-cf7] activate_callback failed for gateway_key=%s', $gateway_key));
				}
			}

			wp_safe_redirect($this->setup_url('message=saved'));
			exit;
		}
	}

	public function display($action = ''): void
	{
		$settings = Settings::get_settings();
		$bk       = (string) ($settings['backoffice_key'] ?? '');
		$message  = sanitize_key(wp_unslash((string) ($_GET['message'] ?? '')));

		$this->print_notice($message);

		echo '<p>' . esc_html__('Streamline your checkout experience using ifthenpay\'s reliable payment gateway. Designed for effortless integration, it allows businesses to accept secure regional payments without complex setup processes. Their certified technology manages every transaction safely, helping brands increase conversion rates and optimize daily cash flow.', 'ifthenpay-payments-for-contactform7') . '</p>';

		echo '<p><a href="https://ifthenpay.com/join/" target="_blank" rel="noopener noreferrer">' . esc_html__('Become an ifthenpay Merchant', 'ifthenpay-payments-for-contactform7') . '</a></p>';

		if ($this->is_active()) {
			echo '<p class="dashicons-before dashicons-yes">'
				. esc_html__('ifthenpay is active on this site.', 'ifthenpay-payments-for-contactform7')
				. '</p>';
		}

		if ('setup' !== $action) {
			echo '<p><a href="' . esc_url($this->setup_url()) . '" class="button">'
				. esc_html(
					$this->is_active()
						? __('Manage integration', 'ifthenpay-payments-for-contactform7')
						: __('Setup integration', 'ifthenpay-payments-for-contactform7')
				)
				. '</a></p>';
			return;
		}

		if ($bk === '') {
			$this->display_stage_1();
		} else {
			$this->display_stage_2($settings);
		}
	}

	private function display_stage_1(): void
	{
?>
		<form method="post" action="<?php echo esc_url($this->setup_url()); ?>">
			<?php wp_nonce_field('iftp_cf7_service_setup'); ?>
			<input type="hidden" name="iftp_action" value="connect_backoffice" />
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="iftp-backoffice-key">
								<?php esc_html_e('Backoffice Key', 'ifthenpay-payments-for-contactform7'); ?>
							</label>
						</th>
						<td>
							<input type="text"
								id="iftp-backoffice-key"
								name="backoffice_key"
								class="regular-text code"
								placeholder="Insert your backoffice key here!"
								autocomplete="off"
								maxlength="20"
								required />
							<p class="description">
								<?php esc_html_e('Found in your ifthenpay Backoffice', 'ifthenpay-payments-for-contactform7'); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button(__('Connect', 'ifthenpay-payments-for-contactform7')); ?>
		</form>
	<?php
	}

	/** @param array<string, mixed> $settings */
	private function display_stage_2(array $settings): void
	{
		$bk             = (string) ($settings['backoffice_key'] ?? '');
		$current_gk     = (string) ($settings['gateway_key'] ?? '');
		$default_method = strtoupper((string) ($settings['default_method'] ?? ''));
		$description    = (string) ($settings['description'] ?? 'Payment');
		$expire_days    = (int) ($settings['expire_days'] ?? 3);
		$saved_methods  = (array) ($settings['methods'] ?? array());

		$catalog    = IfthenpayApiFacade::get_gateway_catalog();
		$method_cat = IfthenpayApiFacade::get_method_catalog();

		$effective_gk = $current_gk !== '' ? $current_gk : (string) array_key_first($catalog);

		$label_map = array();
		foreach ($method_cat as $mc) {
			$ent               = strtoupper((string) ($mc['entity'] ?? ''));
			$label_map[$ent] = (string) ($mc['label'] ?? $ent);
		}
	?>
		<form method="post" action="<?php echo esc_url($this->setup_url()); ?>" id="iftp-cf7-config-form">
			<?php wp_nonce_field('iftp_cf7_service_setup'); ?>
			<input type="hidden" name="iftp_action" value="save_config" />

			<table class="form-table">
				<tbody>

					<?php /* Backoffice key + Disconnect */ ?>
					<tr>
						<th><?php esc_html_e('Backoffice Key (Required)', 'ifthenpay-payments-for-contactform7'); ?></th>
						<td>
							<code><?php echo esc_html(substr($bk, 0, 4) . '-****-****-****'); ?></code>
							&nbsp;&nbsp;
							<a href="<?php echo esc_url($this->reset_url()); ?>"
								style="color:#b42318;"
								onclick="return confirm('<?php esc_attr_e('Disconnect ifthenpay? All settings will be cleared.', 'ifthenpay-payments-for-contactform7'); ?>');">
								<?php esc_html_e('Disconnect', 'ifthenpay-payments-for-contactform7'); ?>
							</a>
						</td>
					</tr>

					<?php /* Gateway key — first option auto-selected, no blank placeholder */ ?>
					<tr>
						<th>
							<label for="iftp-gateway-key">
								<?php esc_html_e('Gateway Key (Required)', 'ifthenpay-payments-for-contactform7'); ?>
							</label>
						</th>
						<td>
							<?php if (empty($catalog)) : ?>
								<p style="color:#b42318;">
									<?php esc_html_e('No gateways found. Contact ifthenpay support.', 'ifthenpay-payments-for-contactform7'); ?>
								</p>
							<?php else : ?>
								<select id="iftp-gateway-key" name="gateway_key">
									<?php foreach ($catalog as $gk => $gw) : ?>
										<option value="<?php echo esc_attr((string) $gk); ?>"
											<?php selected($effective_gk, (string) $gk); ?>>
											<?php echo esc_html((string) ($gw['label'] ?? $gk) . ' (' . $gk . ')'); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</td>
					</tr>

					<?php /* Payment methods table */ ?>
					<tr>
						<th><?php esc_html_e('Payment Methods (Required)', 'ifthenpay-payments-for-contactform7'); ?></th>
						<td>
							<?php
							foreach ($catalog as $gk => $gw) :
								$api_methods = (array) ($gw['methods'] ?? array());
							?>
								<div class="iftp-gateway-methods"
									data-gateway="<?php echo esc_attr((string) $gk); ?>"
									style="display:<?php echo $effective_gk === (string) $gk ? '' : 'none'; ?>">

									<?php
									foreach ($api_methods as $entity => $m_data) :
										$entity_uc = strtoupper((string) $entity);
										$m_label   = (string) ($m_data['method'] ?? $entity_uc);
										$m_account = (string) ($m_data['account'] ?? '');
										$logo      = '';
										$logo_dark = '';
										foreach ($method_cat as $mc) {
											if (strtoupper((string) ($mc['entity'] ?? '')) === $entity_uc) {
												$logo      = (string) ($mc['logo'] ?? '');
												$logo_dark = (string) ($mc['logo_dark'] ?? '');
												break;
											}
										}
										$has_account = $m_account !== '';
										$is_enabled  = $has_account && ! empty($saved_methods[$entity_uc]['enabled']);
									?>

										<?php if ($has_account) : ?>
											<div class="iftp-method-item" data-entity="<?php echo esc_attr($entity_uc); ?>">
												<label class="iftp-method-label">
													<input type="checkbox"
														class="ifthenpay-method-checkbox"
														name="methods[<?php echo esc_attr($entity_uc); ?>][enabled]"
														value="1"
														<?php checked($is_enabled); ?> />
													<?php if ($logo !== '') : ?>
														<img src="<?php echo esc_url($logo); ?>"
															<?php if ($logo_dark !== '') : ?>
															data-logo-dark="<?php echo esc_url($logo_dark); ?>"
															<?php endif; ?>
															alt="<?php echo esc_attr($m_label); ?>"
															class="iftp-method-logo" />
													<?php endif; ?>
													<strong><?php echo esc_html($m_label); ?></strong>
												</label>
												<div class="iftp-method-right">
													<code class="iftp-account"><?php echo esc_html($m_account); ?></code>
												</div>
											</div>

										<?php else : /* No account — disabled overlay with Activate button */ ?>

											<div class="iftp-method-item iftp-method-item--inactive"
												data-entity="<?php echo esc_attr($entity_uc); ?>">
												<div class="iftp-method-disabled-layer">
													<?php if ($logo !== '') : ?>
														<img src="<?php echo esc_url($logo); ?>"
															alt="<?php echo esc_attr($m_label); ?>"
															class="iftp-method-logo" />
													<?php endif; ?>
													<strong><?php echo esc_html($m_label); ?></strong>
												</div>
												<div class="iftp-method-activate-overlay">
													<button type="button"
														class="button button-small ifthenpay-activate"
														data-entity="<?php echo esc_attr($entity_uc); ?>"
														data-gateway="<?php echo esc_attr((string) $gk); ?>">
														<?php esc_html_e('Activate Method', 'ifthenpay-payments-for-contactform7'); ?>
													</button>
												</div>
											</div>

										<?php endif; ?>
									<?php endforeach; ?>

									<?php
									/* Methods not in this gateway at all — show greyed out with Activate */
									$gateway_entity_keys = array_map('strtoupper', array_keys($api_methods));
									foreach ($method_cat as $mc) :
										$mc_entity = strtoupper((string) ($mc['entity'] ?? ''));
										if ($mc_entity === '' || in_array($mc_entity, $gateway_entity_keys, true)) {
											continue;
										}
										$mc_label     = (string) ($mc['label'] ?? $mc_entity);
										$mc_logo      = (string) ($mc['logo'] ?? '');
										$mc_logo_dark = (string) ($mc['logo_dark'] ?? '');
									?>
										<div class="iftp-method-item iftp-method-item--inactive"
											data-entity="<?php echo esc_attr($mc_entity); ?>">
											<div class="iftp-method-disabled-layer">
												<?php if ($mc_logo !== '') : ?>
													<img src="<?php echo esc_url($mc_logo); ?>"
														<?php if ($mc_logo_dark !== '') : ?>
														data-logo-dark="<?php echo esc_url($mc_logo_dark); ?>"
														<?php endif; ?>
														alt="<?php echo esc_attr($mc_label); ?>"
														class="iftp-method-logo" />
												<?php endif; ?>
												<strong><?php echo esc_html($mc_label); ?></strong>
											</div>
											<div class="iftp-method-activate-overlay">
												<button type="button"
													class="button button-small ifthenpay-activate"
													data-entity="<?php echo esc_attr($mc_entity); ?>"
													data-gateway="<?php echo esc_attr((string) $gk); ?>">
													<?php esc_html_e('Activate Method', 'ifthenpay-payments-for-contactform7'); ?>
												</button>
											</div>
										</div>
									<?php endforeach; ?>

								</div>
							<?php endforeach; ?>
						</td>
					</tr>

					<?php /* Default payment method — label shown, entity value used */ ?>
					<tr>
						<th>
							<label for="iftp-default-method">
								<?php esc_html_e('Default Payment Method (Optional)', 'ifthenpay-payments-for-contactform7'); ?>
							</label>
						</th>
						<td>
							<?php
							$default_options = array();
							if (isset($catalog[$effective_gk]['methods'])) {
								foreach ($catalog[$effective_gk]['methods'] as $entity => $m_data) {
									$entity_uc = strtoupper((string) $entity);
									if (! empty($saved_methods[$entity_uc]['enabled'])) {
										$default_options[$entity_uc] = $label_map[$entity_uc] ?? $entity_uc;
									}
								}
							}
							?>
							<select id="iftp-default-method"
								name="default_method"
								data-saved="<?php echo esc_attr($default_method); ?>">
								<?php foreach ($default_options as $entity_uc => $label) : ?>
									<option value="<?php echo esc_attr($entity_uc); ?>"
										<?php selected($default_method, $entity_uc); ?>>
										<?php echo esc_html($label); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e('Which payment method is pre-selected in the Payment.', 'ifthenpay-payments-for-contactform7'); ?>
							</p>
						</td>
					</tr>

					<?php /* Default description */ ?>
					<tr>
						<th>
							<label for="iftp-description">
								<?php esc_html_e('Default Description (Optional)', 'ifthenpay-payments-for-contactform7'); ?>
							</label>
						</th>
						<td>
							<input type="text"
								id="iftp-description"
								name="description"
								class="regular-text"
								value="<?php echo esc_attr($description); ?>"
								placeholder="e.g.: Purchase at My Store"
								maxlength="100" />
							<p class="description">
								<?php esc_html_e('Label sent to ifthenpay and displayed to the customer on the payment page. Use something recognisable, like your store or product name.', 'ifthenpay-payments-for-contactform7'); ?>
							</p>
						</td>
					</tr>

					<?php /* Expire days */ ?>
					<tr>
						<th>
							<label for="iftp-expire-days">
								<?php esc_html_e('Payment expires in (days) / (Optional)', 'ifthenpay-payments-for-contactform7'); ?>
							</label>
						</th>
						<td>
							<input type="number"
								id="iftp-expire-days"
								name="expire_days"
								class="small-text"
								value="<?php echo esc_attr((string) $expire_days); ?>"
								min="1"
								max="30" />
							<p class="description">
								<?php esc_html_e('A value of 1 means a payment created today will expire tomorrow at 23:59. Pending payments older than this many days are marked Expired automatically each night.', 'ifthenpay-payments-for-contactform7'); ?>
							</p>
						</td>
					</tr>

				</tbody>
			</table>

			<?php submit_button(__('Save Configuration', 'ifthenpay-payments-for-contactform7')); ?>
		</form>
<?php
	}

	private function setup_url(string $extra = ''): string
	{
		$base = add_query_arg(
			array(
				'page'    => 'wpcf7-integration',
				'service' => 'ifthenpay',
				'action'  => 'setup',
			),
			admin_url('admin.php')
		);
		if ($extra !== '') {
			parse_str($extra, $params);
			$base = add_query_arg($params, $base);
		}
		return $base;
	}

	private function reset_url(): string
	{
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'wpcf7-integration',
					'service'     => 'ifthenpay',
					'action'      => 'setup',
					'iftp_action' => 'reset',
				),
				admin_url('admin.php')
			),
			'iftp_cf7_service_setup'
		);
	}

	private function print_notice(string $message): void
	{
		$map = array(
			'connected'      => array('success', __('Backoffice Key connected. Configure your gateway below.', 'ifthenpay-payments-for-contactform7')),
			'saved'          => array('success', __('Settings saved.', 'ifthenpay-payments-for-contactform7')),
			'reset'          => array('info', __('ifthenpay integration reset.', 'ifthenpay-payments-for-contactform7')),
			'invalid_key'    => array('error', __('Invalid Backoffice Key.', 'ifthenpay-payments-for-contactform7')),
			'connect_failed' => array('error', __('Could not validate the Backoffice Key. Please try again.', 'ifthenpay-payments-for-contactform7')),
			'no_gateways'    => array('error', __('No gateways found. Contact ifthenpay support.', 'ifthenpay-payments-for-contactform7')),
		);

		if ($message === '' || ! isset($map[$message])) {
			return;
		}

		[$type, $text] = $map[$message];

		if (function_exists('wp_admin_notice')) {
			wp_admin_notice(esc_html($text), array('type' => $type));
		} else {
			printf('<div class="notice notice-%s"><p>%s</p></div>', esc_attr($type), esc_html($text));
		}
	}
}
