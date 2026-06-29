<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Admin;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

/**
 * Per-form CF7 editor panel.
 *
 * Only stores the per-form settings (enable toggle + amount mapping).
 * Global gateway, methods, description and expire days live in Settings
 * (configured once on the CF7 Integration page).
 */
final class FormPanel
{

	public function add_panel(array $panels): array
	{
		$panels['ifthenpay-panel'] = array(
			'title'   => __('ifthenpay Payment Gateway', 'ifthenpay-payments-for-contactform7'),
			'content' => $this->render_panel_content(),
		);
		return $panels;
	}

	private function render_panel_content(): string
	{
		$cf7 = \WPCF7_ContactForm::get_current();
		if (! $cf7 instanceof \WPCF7_ContactForm) {
			return '<p>' . esc_html__('Could not load form context.', 'ifthenpay-payments-for-contactform7') . '</p>';
		}

		$form_id        = $cf7->id();
		$config         = Settings::get_form_config($form_id);
		$backoffice_key = Settings::get_backoffice_key();
		$gateway_key    = Settings::get_gateway_key();

		$enabled       = ! empty($config['enabled']);
		$amount_source = isset($config['amount_source']) ? (string) $config['amount_source'] : 'fixed';
		$amount_fixed  = isset($config['amount_fixed']) ? (string) $config['amount_fixed'] : '';
		$amount_field  = isset($config['amount_field']) ? (string) $config['amount_field'] : '';

		ob_start();
?>
		<div class="iftp-cf7-form-panel" data-form-id="<?php echo esc_attr((string) $form_id); ?>">

			<?php if ($backoffice_key === '' || $gateway_key === '') : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: integration page link */
							esc_html__('Complete ifthenpay setup on the %s before enabling payments on this form.', 'ifthenpay-payments-for-contactform7'),
							'<a href="' . esc_url(admin_url('admin.php?page=wpcf7-integration&service=ifthenpay&action=setup')) . '">' .
								esc_html__('Integration page', 'ifthenpay-payments-for-contactform7') . '</a>'
						);
						?>
					</p>
				</div>
			<?php else : ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e('Enable Payments', 'ifthenpay-payments-for-contactform7'); ?></th>
							<td>
								<label>
									<input type="checkbox" id="iftp-cf7-enabled" name="iftp_cf7_enabled"
										value="1" <?php checked($enabled); ?> />
									<?php esc_html_e('Accept ifthenpay payments on this form', 'ifthenpay-payments-for-contactform7'); ?>
								</label>
							</td>
						</tr>

						<tr class="iftp-cf7-row<?php echo ! $enabled ? ' iftp-cf7-row--hidden' : ''; ?>">
							<th scope="row"><?php esc_html_e('Payment Amount', 'ifthenpay-payments-for-contactform7'); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="iftp_cf7_amount_source" value="fixed"
											<?php checked($amount_source, 'fixed'); ?> />
										<?php esc_html_e('Fixed amount', 'ifthenpay-payments-for-contactform7'); ?>
									</label>
									&nbsp;
									<input type="number" name="iftp_cf7_amount_fixed"
										value="<?php echo esc_attr($amount_fixed); ?>"
										min="0.01" step="0.01" class="small-text"
										placeholder="0.00"
										<?php echo $amount_source !== 'fixed' ? 'disabled' : ''; ?> />
									<span>€</span>
									<br /><br />
									<label>
										<input type="radio" name="iftp_cf7_amount_source" value="field"
											<?php checked($amount_source, 'field'); ?> />
										<?php esc_html_e('Read from CF7 field:', 'ifthenpay-payments-for-contactform7'); ?>
									</label>
									<input type="text" name="iftp_cf7_amount_field"
										value="<?php echo esc_attr($amount_field); ?>"
										class="regular-text"
										placeholder="your-amount"
										<?php echo $amount_source !== 'field' ? 'disabled' : ''; ?> />
									<p class="description">
										<?php esc_html_e('Enter the name of the CF7 field whose value holds the amount.', 'ifthenpay-payments-for-contactform7'); ?>
									</p>
								</fieldset>
							</td>
						</tr>

						<tr class="iftp-cf7-row<?php echo ! $enabled ? ' iftp-cf7-row--hidden' : ''; ?>">
							<th scope="row"><?php esc_html_e('Tag reminder', 'ifthenpay-payments-for-contactform7'); ?></th>
							<td>
								<p class="description">
									<?php esc_html_e('Add the', 'ifthenpay-payments-for-contactform7'); ?>
									<code>[ifthenpay_payment]</code>
									<?php esc_html_e('tag to the form body to show the payment button.', 'ifthenpay-payments-for-contactform7'); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<input type="hidden" name="iftp_cf7_form_id" value="<?php echo esc_attr((string) $form_id); ?>" />
				<input type="hidden" name="iftp_cf7_form_nonce"
					value="<?php echo esc_attr(wp_create_nonce('iftp_cf7_form_config_' . $form_id)); ?>" />

				<p style="color:#646970;font-style:italic;margin-top:12px;">
					<?php esc_html_e('Settings are saved when you click "Save" in the form editor.', 'ifthenpay-payments-for-contactform7'); ?>
				</p>
			<?php endif; ?>
		</div>
<?php
		return (string) ob_get_clean();
	}

	public function save_settings(\WPCF7_ContactForm $contact_form): void
	{
		$form_id = $contact_form->id();

		if (! current_user_can('manage_options')) {
			return;
		}

		$nonce = sanitize_text_field(
			wp_unslash((string) ($_POST['iftp_cf7_form_nonce'] ?? ''))
		);
		if (! wp_verify_nonce($nonce, 'iftp_cf7_form_config_' . $form_id)) {
			return;
		}

		$enabled       = ! empty($_POST['iftp_cf7_enabled']);
		$amount_source = sanitize_key(wp_unslash((string) ($_POST['iftp_cf7_amount_source'] ?? 'fixed')));
		$amount_fixed  = sanitize_text_field(wp_unslash((string) ($_POST['iftp_cf7_amount_fixed'] ?? '')));
		$amount_field  = sanitize_text_field(wp_unslash((string) ($_POST['iftp_cf7_amount_field'] ?? '')));

		$config = array(
			'enabled'       => $enabled,
			'amount_source' => in_array($amount_source, array('fixed', 'field'), true) ? $amount_source : 'fixed',
			'amount_fixed'  => is_numeric($amount_fixed) ? number_format((float) $amount_fixed, 2, '.', '') : '',
			'amount_field'  => $amount_field,
		);

		update_option('iftp_cf7_form_config_' . $form_id, $config, false);
	}

}
