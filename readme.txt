=== CardsPrint Sync ===
Contributors:      wordpresscps
Tags:              woocommerce, api, elixir, loyalty, products, orders
Requires at least: 5.9
Tested up to:      6.6
Stable tag:        1.0.0
Requires PHP:      7.4
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Two-way real-time synchronisation between WooCommerce and the CardsPrint (Elixir) backend: users, products, orders and points.

== Description ==
This plugin automatically:
* pushes new / updated users, products and orders to your Elixir backend;
* exposes a REST endpoint so Elixir can update WooCommerce data;
* stores an API key, login and store code in one settings screen.

No manual configuration besides entering your CardsPrint credentials.

== Installation ==
1. Upload the plugin files to `/wp-content/plugins/cardsprint-sync` or install via the built-in uploader.
2. Activate the plugin through the 'Plugins' screen.
3. Navigate to **Settings → CardsPrint Sync** and fill in API key, login, password and store code.
4. Save – data will start syncing immediately.

== Frequently Asked Questions ==
= Does this work with guest checkouts? =
Yes.  Guest orders are sent with user_id = 0.

= Can I change the remote URL? =
Not in the free version.  Use the filter `cps_api_url` if you need to override it programmatically.

== Screenshots ==
1. Settings screen – API credentials.
2. Automatic sync status on user profile page.

== Changelog ==
= 1.0.0 =
* Initial release.

== Upgrade Notice ==
= 1.0.0 =
First release.