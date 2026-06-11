<?php

declare(strict_types=1);

namespace Ifthenpay\CF7\Api;

if (! defined('ABSPATH')) {
	die('Are you sure?');
}

final class IfthenpayEmailHelper
{

	private const SUPPORT_EMAIL = 'suporte@ifthenpay.com';

	public static function send_activation_email(
		string $backoffice_key,
		string $gateway_key,
		string $method_entity,
		string $plugin_context = 'ContactForm7'
	): bool {
		if ($backoffice_key === '' || $gateway_key === '' || $method_entity === '') {
			return false;
		}

		$subject = sprintf(
			/* translators: 1: payment method entity, 2: plugin context */
			__('[ifthenpay] Activate %1$s for %2$s', 'ifthenpay-payments-for-contactform7'),
			strtoupper($method_entity),
			$plugin_context
		);

		$body = sprintf(
			"Hello,\n\nPlease activate the following payment method:\n\n- Backoffice Key: %s\n- Gateway Key: %s\n- Method: %s\n- Plugin: %s\n\nThank you.",
			esc_html($backoffice_key),
			esc_html($gateway_key),
			strtoupper(esc_html($method_entity)),
			esc_html($plugin_context)
		);

		return wp_mail(self::SUPPORT_EMAIL, $subject, $body);
	}

	private function __construct() {}
}
