=== ifthenpay | Payments for Contact Form 7 ===
Contributors: ifthenpay
Tags: contact-form-7, ifthenpay, payment, multibanco, mbway, payshop
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Requires Plugins: contact-form-7

Accept payments via ifthenpay Pay by Link directly inside your Contact Form 7 forms.

== Description ==

**ifthenpay | Payments for Contact Form 7** integrates the ifthenpay payment gateway into Contact Form 7 forms. A seamless, hosted payment modal opens when the visitor clicks the Pay button — your site never handles card data.

= Key Features =

* **Pay by Link modal** — ifthenpay-hosted payment screen opens in a full-screen overlay; works on all devices.
* **Offline payments** — Multibanco (ATM reference) and Payshop references are generated and shown. Visitors can pay later; the plugin updates the entry status automatically via webhook callback.
* **Webhook callbacks** — A dedicated REST endpoint (`/wp-json/ifthenpay-cf7/v1/callback`) receives real-time payment confirmations from ifthenpay for all offline methods.
* **Payment Entries** — Every form submission with a payment is saved in a custom database table and displayed under **Contact Form 7 > ifthenpay Entries**.
* **Per-form configuration** — Enable/disable payments and select the gateway key per form from the **ifthenpay Payment Gateway** tab in the form editor.
* **Method control** — Enable or disable individual payment methods per form; request activation for new methods directly from the settings.
* **Mail tags** — Use `[ifthenpay-transaction-id]`, `[ifthenpay-amount]`, `[ifthenpay-method]`, and `[ifthenpay-status]` inside CF7 mail templates.
* **Flexible amount** — Fixed price or mapped to any CF7 field.

= Requirements =

* WordPress 6.5 or later
* PHP 8.2 or later
* Contact Form 7 5.9 or later
* An active ifthenpay account (https://ifthenpay.com)

= Getting Started =

1. Install and activate the plugin.
2. Go to **Contact Form 7 > ifthenpay** and enter your Backoffice Key.
3. Open the form, go to the **ifthenpay Payment Gateway** tab, enable payments, and choose a gateway key.
4. Enable the desired payment methods (MB, MB WAY, CCARD, etc.).
5. Add the `[ifthenpay_payment]` tag to the form body using the tag generator.
6. Copy the webhook URL from the settings page and register it in your ifthenpay Backoffice.

= Mail Tags =

* `[ifthenpay-transaction-id]` — The ifthenpay transaction ID
* `[ifthenpay-amount]` — Payment amount with currency
* `[ifthenpay-method]` — Payment method used (MB, MBWAY, etc.)
* `[ifthenpay-status]` — Payment status (Paid, Pending, etc.)
* `[ifthenpay-payment-url]` — The payment link URL
* `[ifthenpay-entry-id]` — Internal entry ID

== Installation ==

1. Upload the plugin to `/wp-content/plugins/ifthenpay-payments-for-contactform7/` or install via the Plugins screen.
2. Activate the plugin.
3. Go to **Contact Form 7 > ifthenpay** and configure your Backoffice Key.

== Frequently Asked Questions ==

= Does this plugin store payment card data? =

No. All payment data is handled by ifthenpay on their servers.

= What payment methods are supported? =

All methods on your ifthenpay account: Multibanco, MB WAY, CCARD, Payshop, and others.

= Do I need to configure the webhook? =

Yes, for automatic confirmation of offline payments (Multibanco, Payshop). Copy the callback URL from the settings page and register it in your ifthenpay Backoffice.

= Where are entries stored? =

In `wp_ifthenpay_cf7_entries`. View them under **Contact Form 7 > ifthenpay Entries**.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
