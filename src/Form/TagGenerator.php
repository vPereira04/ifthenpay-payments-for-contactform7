<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Form;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

final class TagGenerator
{

	public function register(): void
	{
		if (! class_exists('WPCF7_TagGenerator')) {
			return;
		}

		$generator = \WPCF7_TagGenerator::get_instance();
		$generator->add(
			'ifthenpay_payment',
			__('ifthenpay Payment', 'ifthenpay-payments-for-contactform7'),
			array($this, 'render'),
			array('version' => '2')
		);
	}

	public function render(\WPCF7_ContactForm $_contact_form, array $args = array()): void
	{
		$args         = wp_parse_args($args, array());
		$content      = isset($args['content']) ? (string) $args['content'] : '';
		$default_text = __('Pay with ifthenpay', 'ifthenpay-payments-for-contactform7');
?>
		<header class="description-box">
			<h3><?php esc_html_e('ifthenpay Payment field form-tag generator', 'ifthenpay-payments-for-contactform7'); ?></h3>
			<p><?php esc_html_e('Generates a form-tag for the ifthenpay payment button. Add it once in your form body where the button should appear, and remove the default Submit button.', 'ifthenpay-payments-for-contactform7'); ?></p>
		</header>

		<div class="control-box">
			<input type="hidden" data-tag-part="basetype" value="ifthenpay_payment" />

			<fieldset>
				<legend>
					<?php esc_html_e('Amount', 'ifthenpay-payments-for-contactform7'); ?>
					<abbr title="<?php esc_attr_e('required', 'ifthenpay-payments-for-contactform7'); ?>" style="color:#d63638">*</abbr>
				</legend>
				<input type="text"
					id="<?php echo esc_attr($content . '-amount'); ?>"
					data-tag-part="option"
					data-tag-option="amount:"
					required
					pattern="[0-9]+([.,][0-9]+)?"
					inputmode="decimal"
					placeholder="<?php esc_attr_e('e.g. 10.00', 'ifthenpay-payments-for-contactform7'); ?>" />
				<div></div>
				<span class="description">
					<?php esc_html_e('(Required) - Numbers only; use . or , as decimal separator.', 'ifthenpay-payments-for-contactform7'); ?>
				</span>
			</fieldset>

			<fieldset>
				<legend><?php esc_html_e('Payment method icons', 'ifthenpay-payments-for-contactform7'); ?></legend>
				<label>
					<input type="checkbox"
						id="<?php echo esc_attr($content . '-hide'); ?>"
						data-tag-part="option"
						data-tag-option="hide:yes" />
					<?php esc_html_e('Hide icons', 'ifthenpay-payments-for-contactform7'); ?>
				</label>
				<div></div>
				<span class="description">
					<?php esc_html_e('(Optional) - Hides the payment method icons from the button.', 'ifthenpay-payments-for-contactform7'); ?>
				</span>
			</fieldset>

			<fieldset>
				<legend><?php esc_html_e('Custom CSS class', 'ifthenpay-payments-for-contactform7'); ?></legend>
				<input type="text"
					id="<?php echo esc_attr($content . '-css'); ?>"
					data-tag-part="option"
					data-tag-option="css:"
					pattern="[A-Za-z0-9_-]*"
					placeholder=" e.g. custom-css-class" />
				<div></div>
				<span class="description">
					<?php esc_html_e('(Optional) - A custom CSS class to apply to the payment button.', 'ifthenpay-payments-for-contactform7'); ?>
				</span>
			</fieldset>

			<fieldset>
				<legend><?php esc_html_e('Button text', 'ifthenpay-payments-for-contactform7'); ?></legend>
				<input type="text"
					id="<?php echo esc_attr($content . '-text'); ?>"
					data-tag-part="value"
					placeholder="Default: <?php echo esc_attr($default_text); ?>" />
				<div></div>
				<span class="description">
					<?php
					printf(
						/* translators: %s: default button label */
						esc_html__('(Optional) - Leave empty to use the default "%s".', 'ifthenpay-payments-for-contactform7'),
						esc_html($default_text)
					);
					?>
				</span>
			</fieldset>
		</div>

		<footer class="insert-box">
			<div class="flex-container">
				<input type="text" readonly
					data-tag-part="tag"
					class="code selectable"
					aria-label="<?php esc_attr_e('Generated tag', 'ifthenpay-payments-for-contactform7'); ?>" />
				<button type="button" class="button button-primary" data-taggen="insert-tag">
					<?php esc_html_e('Insert Tag', 'ifthenpay-payments-for-contactform7'); ?>
				</button>
			</div>
			<p class="mail-tag-tip">
				<?php esc_html_e('Add the following ifthenpay form-tag where you want the payment button to appear in your form template:', 'ifthenpay-payments-for-contactform7'); ?>
					<strong data-tag-part="mail-tag">[ifthenpay_payment amount:x]</strong>
				<?php esc_html_e('(Note: Remove the default CF7 [submit] button and use this one instead).', 'ifthenpay-payments-for-contactform7'); ?>
			</p>
		</footer>
	<?php
	}
}
