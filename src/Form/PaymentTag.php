<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Form;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use Ifthenpay\CF7\Admin\Settings;
use Ifthenpay\CF7\Api\IfthenpayApiFacade;

/**
 * Handles the [ifthenpay_payment] form tag.
 *
 * Supported options:
 *
 *   amount:30.00              — payment amount (required)
 *   text:"Pay now"            — custom button label (optional; default: "Pay with ifthenpay")
 *   css:"my-class"            — extra CSS class appended to the button (optional)
 *   hide:yes                  — hide the payment-method logos (optional; default: show)
 *
 * Example:
 *   [ifthenpay_payment amount:30.00 text:"Buy now" css:"btn-primary" hide:yes]
 */
final class PaymentTag {

	public function register(): void {
		wpcf7_add_form_tag(
			[ 'ifthenpay_payment', 'ifthenpay_payment*' ],
			[ $this, 'render' ],
			[ 'display-block' => true ]
		);
	}

	public function render( \WPCF7_FormTag $tag ): string {
		$cf7     = \WPCF7_ContactForm::get_current();
		$form_id = $cf7 instanceof \WPCF7_ContactForm ? $cf7->id() : 0;


		$amount_raw = str_replace( ',', '.', (string) ( $tag->get_option( 'amount', '', true ) ?? '' ) );
		$amount     = is_numeric( $amount_raw ) ? (float) $amount_raw : 0.0;


		$text = isset( $tag->values[0] ) ? trim( (string) $tag->values[0] ) : '';
		if ( $text === '' ) {
			$text = __( 'Pay with ifthenpay', 'ifthenpay-payments-for-contactform7' );
		}


		$css_raw   = $tag->get_option( 'css', '', true );
		$extra_css = '';
		if ( $css_raw !== false && $css_raw !== '' ) {
			$extra_css = sanitize_html_class( trim( (string) $css_raw, '"\'') );
		}


		$hide_raw    = $tag->get_option( 'hide', '', true );
		$hide_logos  = strtolower( trim( (string) $hide_raw, '"\'') ) === 'yes';


		$settings    = Settings::get_settings();
		$bk          = (string) ( $settings['backoffice_key'] ?? '' );
		$gk          = (string) ( $settings['gateway_key']    ?? '' );
		$is_ready    = $bk !== '' && $gk !== '' && $amount > 0 && $form_id > 0;

		$disabled_reason = '';
		if ( $bk === '' || $gk === '' ) {
			$disabled_reason = esc_html__( 'Complete ifthenpay setup on Contact Form 7 › Integration.', 'ifthenpay-payments-for-contactform7' );
		} elseif ( $amount <= 0 ) {
			$disabled_reason = esc_html__( 'Specify a valid amount in the tag, e.g. [ifthenpay_payment amount:30.00]', 'ifthenpay-payments-for-contactform7' );
		} elseif ( $form_id <= 0 ) {
			$disabled_reason = esc_html__( 'Form ID not found. Make sure the tag is used within a Contact Form 7 form.', 'ifthenpay-payments-for-contactform7' );
		}

		$methods    = (array) ( $settings['methods'] ?? [] );
		$method_cat = IfthenpayApiFacade::get_method_catalog();

		$btn_class = trim( 'iftp-cf7-pay-button' . ( $extra_css !== '' ? ' ' . $extra_css : '' ) . ( ! $is_ready ? ' iftp-cf7-pay-button--disabled' : '' ) );

		ob_start();
		?>
		<div class="iftp-cf7-payment-field<?php echo $is_ready ? ' iftp-cf7-is-ready' : ' iftp-cf7-is-misconfigured'; ?>"
			data-form-id="<?php echo esc_attr( (string) $form_id ); ?>"
			data-gateway-key="<?php echo esc_attr( $gk ); ?>"
			data-amount="<?php echo esc_attr( number_format( $amount, 2, '.', '' ) ); ?>"
			data-config-ready="<?php echo $is_ready ? '1' : '0'; ?>"
		>
			<?php if ( ! $hide_logos && $is_ready && ! empty( $method_cat ) ) : ?>
			<div class="iftp-cf7-payment-methods" aria-label="<?php esc_attr_e( 'Available payment methods', 'ifthenpay-payments-for-contactform7' ); ?>">
				<?php foreach ( $method_cat as $mc ) :
					$entity_uc = strtoupper( (string) ( $mc['entity'] ?? '' ) );
					$m_label   = (string) ( $mc['label']     ?? $entity_uc );
					$logo      = (string) ( $mc['logo']      ?? '' );
					$logo_dark = (string) ( $mc['logo_dark'] ?? '' );
					$m_cfg     = $methods[$entity_uc] ?? [];
					if ( empty( $m_cfg['enabled'] ) || $logo === '' ) continue;
				?>
				<img src="<?php echo esc_url( $logo ); ?>"
					<?php if ( $logo_dark !== '' ) : ?>data-logo-dark="<?php echo esc_url( $logo_dark ); ?>"<?php endif; ?>
					alt="<?php echo esc_attr( $m_label ); ?>"
					class="iftp-cf7-method-logo"
					height="24"
					loading="lazy"
				/>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<button type="button"
				class="<?php echo esc_attr( $btn_class ); ?>"
				data-gateway-key="<?php echo esc_attr( $gk ); ?>"
				data-form-id="<?php echo esc_attr( (string) $form_id ); ?>"
				data-amount="<?php echo esc_attr( number_format( $amount, 2, '.', '' ) ); ?>"
				<?php if ( ! $is_ready ) : ?> disabled aria-disabled="true" <?php endif; ?>
			>
				<?php echo esc_html( $text ); ?>
			</button>

			<?php if ( ! $is_ready && $disabled_reason !== '' ) : ?>
			<div class="iftp-cf7-config-warning" role="alert">
				<p><?php echo esc_html( $disabled_reason ); ?></p>
			</div>
			<?php else : ?>
			<div class="iftp-cf7-config-warning" style="display:none"></div>
			<?php endif; ?>

			<div class="iftp-cf7-runtime-warning" id="iftp-payment-status-<?php echo esc_attr( (string) $form_id ); ?>" role="alert"></div>

			<input type="hidden" name="iftp_cf7_entry_id"       class="iftp-cf7-entry-id"       value="" />
			<input type="hidden" name="iftp_cf7_transaction_id" class="iftp-cf7-transaction-id" value="" />
			<input type="hidden" name="iftp_cf7_modal_token"    class="iftp-cf7-modal-token"     value="" />
			<input type="hidden" name="iftp_cf7_payment_status" class="iftp-cf7-payment-status"  value="" />
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
