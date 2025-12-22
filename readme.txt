=== UPOS Payments ===
Contributors: UPOS
Tags: woocommerce, payment, gateway, upos, payments
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official UPOS Payments gateway for WooCommerce. Accept crypto payments easily in your store.

Note: Planning for official submission to the WordPress Plugin Directory is currently underway.

== Description ==

UPOS Payments allows your store to accept payments through UPOS payment services.

= Features =

* Automatic environment detection (Test/Live) based on API keys
* Comprehensive transaction logging
* Support for WooCommerce HPOS

= System Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher

== Installation ==

1. Upload the `upos-woocommerce` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to WooCommerce > Settings > Payments > UPOS Payments to configure.
4. Enter your API Key and API Secret (pk_test_... for Sandbox, pk_live_... for Production).
5. Save changes and start using it.

== Frequently Asked Questions ==

= How do I obtain API keys? =

Please contact UPOS payment services to get your API credentials.

= How do I test the payment functionality? =

Simply enter your test environment API keys (starting with `pk_test_` and `sk_test_`). The plugin will automatically switch to Test Mode.

== Changelog ==

= 1.0.0 =
* Initial release
* Support for WooCommerce HPOS

== Upgrade Notice ==

= 1.0.0 =
Initial version release.
