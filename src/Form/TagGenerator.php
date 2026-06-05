<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Form;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

final class TagGenerator {

	public function register(): void {
		if ( ! class_exists( 'WPCF7_TagGenerator' ) ) {
			return;
		}

		$generator = \WPCF7_TagGenerator::get_instance();
		$generator->add(
			'ifthenpay_payment',
			__( 'ifthenpay Payment', 'ifthenpay-payments-for-contactform7' ),
			array( $this, 'render' ),
			array( 'version' => '2' )
		);
	}

	public function render( \WPCF7_ContactForm $_contact_form, array $args = array() ): void {
		$args         = wp_parse_args( $args, array() );
		$content      = isset( $args['content'] ) ? (string) $args['content'] : '';
		$default_text = __( 'Pay with ifthenpay', 'ifthenpay-payments-for-contactform7' );
		?>
		<div class="control-box">
			<fieldset>
				<legend>
					<?php esc_html_e( 'Generate the ifthenpay Payment tag. Add it once in your form body where the payment button should appear.', 'ifthenpay-payments-for-contactform7' ); ?>
				</legend>

				<input type="hidden" data-tag-part="basetype" value="ifthenpay_payment" />

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $content . '-amount' ); ?>">
									<?php esc_html_e( 'Amount', 'ifthenpay-payments-for-contactform7' ); ?>
									<abbr title="<?php esc_attr_e( 'required', 'ifthenpay-payments-for-contactform7' ); ?>" style="color:#d63638">*</abbr>
								</label>
							</th>
							<td>
								<input type="text"
									id="<?php echo esc_attr( $content . '-amount' ); ?>"
									data-tag-part="option"
									data-tag-option="amount:"
									required
									pattern="[0-9]+([.,][0-9]+)?"
									inputmode="decimal"
									placeholder="<?php esc_attr_e( 'e.g. 10.00', 'ifthenpay-payments-for-contactform7' ); ?>"
								/>
								<span class="description">
									<?php esc_html_e( 'Required. Numbers only; use . or , as decimal separator.', 'ifthenpay-payments-for-contactform7' ); ?>
								</span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Hide payment method icons', 'ifthenpay-payments-for-contactform7' ); ?>
							</th>
							<td>
								<label>
									<input type="checkbox"
										id="<?php echo esc_attr( $content . '-hide' ); ?>"
										data-tag-part="option"
										data-tag-option="hide:yes"
									/>
									<?php esc_html_e( 'Hide icons', 'ifthenpay-payments-for-contactform7' ); ?>
								</label>
								<span class="description">
									<?php esc_html_e( 'Optional.', 'ifthenpay-payments-for-contactform7' ); ?>
								</span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $content . '-css' ); ?>">
									<?php esc_html_e( 'Custom CSS Class', 'ifthenpay-payments-for-contactform7' ); ?>
								</label>
							</th>
							<td>
								<input type="text"
									id="<?php echo esc_attr( $content . '-css' ); ?>"
									data-tag-part="option"
									data-tag-option="css:"
									pattern="[A-Za-z0-9_-]*"
								/>
								<span class="description">
									<?php esc_html_e( 'Optional.', 'ifthenpay-payments-for-contactform7' ); ?>
								</span>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( $content . '-text' ); ?>">
									<?php esc_html_e( 'Button text', 'ifthenpay-payments-for-contactform7' ); ?>
								</label>
							</th>
							<td>
								<input type="text"
									id="<?php echo esc_attr( $content . '-text' ); ?>"
									data-tag-part="value"
									placeholder="<?php echo esc_attr( $default_text ); ?>"
								/>
								<span class="description">
									<?php
									printf(
										/* translators: %s: default button label */
										esc_html__( 'Optional. Leave empty to use the default "%s".', 'ifthenpay-payments-for-contactform7' ),
										esc_html( $default_text )
									);
									?>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</fieldset>
		</div>

		<div class="insert-box">
			<input type="text" readonly
				data-tag-part="tag"
				class="tag code"
				aria-label="<?php esc_attr_e( 'Generated tag', 'ifthenpay-payments-for-contactform7' ); ?>"
			/>
			<div class="submitbox">
				<button type="button" class="button button-primary" data-taggen="insert-tag">
					<?php esc_html_e( 'Insert Tag', 'ifthenpay-payments-for-contactform7' ); ?>
				</button>
			</div>
		</div>
		<?php
	}
}
