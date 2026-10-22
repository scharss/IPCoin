# IPCoin

Location-based currency for WooCommerce. Visitors from the configured **domestic countries** (default **Colombia / CO**) see and pay in the store's **base currency** (e.g. **COP**). Visitors from any other country see and pay in a **foreign currency** (default **USD**), converted with a real-time exchange rate. If the country cannot be detected, a **fallback currency** (default **USD**) is used.

- **Author:** Scharss Azael — https://thesoftwarelab.xyz
- **License:** MIT
- **Requires:** WordPress 5.8+, PHP 7.4+, WooCommerce 6.0+
- **Tested up to:** WooCommerce 9.8
- **Compatibility:** HPOS (`custom_order_tables`) and Cart/Checkout Blocks declared

## Features

- **Real currency switch** (not just a label): store currency, product prices, cart, checkout, order and payment gateway all use the visitor currency.
- Country detection with WooCommerce's built-in geolocation (`WC_Geolocation::geolocate_ip()`).
- Persists the resolved country/currency in the WooCommerce session and a 24h cookie, so the price stays stable across pages, AJAX and the Store API.
- Real-time exchange rates: **Yahoo Finance → open.er-api.com → Google Finance (scrape) → manual rate**.
- **12-hour cache** by default (configurable), with a "Refresh rate now" button.
- Converts product prices, shipping costs/taxes and fixed-amount coupons.
- Admin test override (`?ipcoin_country=XX`).
- No build step: plain PHP.

## How it works

1. On `init` (priority 20) and `wp` (priority 1), `IPCoin_Geo::resolve()` resolves the visitor country/currency **once per request**.
2. The country is detected with `WC_Geolocation::geolocate_ip()` (Cloudflare/GeoIP headers, then the MaxMind GeoIP database when configured).
3. The country and currency are persisted in the WooCommerce session and in the `ipcoin_country` / `ipcoin_currency` cookies (24h).
4. `woocommerce_currency` returns the visitor currency, and the price/shipping/coupon filters convert the amounts with the current rate.
5. Because the cart and order are calculated with the filtered prices and currency, the order is created in the visitor currency and the payment gateway charges that currency.

## Installation

1. Copy the `ipcoin` folder to `wp-content/plugins/`.
2. Activate **IPCoin** in *Plugins*.
3. Open **IPCoin** in the admin menu and configure the domestic countries, currencies and rate source.
4. Make sure your payment gateway supports the foreign currency **and** the customer's country (see [Important notes](#important-notes)).

## Settings (wp-admin → IPCoin)

| Option | Default | Description |
| --- | --- | --- |
| Enable IPCoin | on | Turns the location-based currency and price conversion on/off. |
| Domestic countries | `CO` | Comma-separated ISO-2 codes that use the base currency. |
| Foreign currency | `USD` | Currency for visitors outside the domestic countries. |
| No-country fallback | `USD` | Currency used when geolocation cannot determine the country (VPN, invalid IP). |
| Rate source | `auto` | `auto` (Yahoo → er-api → Google), `yahoo`, `er_api` or `google`. |
| Cache (minutes) | `720` | Rate cache TTL. `720` = 12 hours. Minimum 1. |
| Manual rate | empty | Units of foreign currency per 1 base unit. When set, it overrides the APIs. |
| Convert shipping | on | Converts shipping cost and taxes. |
| Convert coupons | on | Converts `fixed_cart` / `fixed_product` coupons. |
| Test override | on | Allows administrators to simulate a country with `?ipcoin_country=XX`. |

The page also shows the current IP's detected country/currency, the last rate/source/date, and a **Refresh rate now** button.

## Exchange rates

Resolution order (`rate_source = auto`):

1. **Manual rate** — when set, always wins.
2. **Yahoo Finance** — `query1.finance.yahoo.com` chart API (direct pair, then inverse).
3. **open.er-api.com** — `v6/latest/{base}`.
4. **Google Finance** — scraped quote page (last resort).
5. **Last known rate** — the last successful rate is stored in `ipcoin_last_rate` and used as a stale fallback if every source fails.

Rates are cached in transients for `cache_ttl` minutes (default 720 = 12h). Saving the settings clears the cache; the **Refresh rate now** button clears it and fetches the foreign and fallback rates.

## Behavior details

- **Admin pages** (real admin screens) always use the base currency. `admin-ajax.php` and REST/Store API requests **do** convert because the block cart/checkout and other integrations rely on them.
- **Test override:** `?ipcoin_country=US` (logged-in administrator with `manage_options`, when enabled) forces the country/currency and persists it.
- **Session/cookie:** the values are stored in the WooCommerce session and cookies for 24h. After changing settings, a stale session/cookie can keep the old value for up to 24h; use the test override or clear the session/cookies.
- **Rounding:** conversion uses `wc_get_price_decimals()` (the **store's** decimal setting), not the currency's ISO exponent. With 0 decimals (e.g. COP), USD prices are rounded to whole units.
- **Coupons:** only fixed-amount coupons (`fixed_cart`, `fixed_product`) are converted; percentage coupons are not affected.
- **Fees:** fixed fees added by other plugins are not converted.

## Hooks / filters

- `ipcoin_should_convert` — return `false` to disable conversion in a specific context.
- `woocommerce_currency` — returns the visitor currency (priority 100).
- `woocommerce_product_get_price`, `woocommerce_product_get_regular_price`, `woocommerce_product_get_sale_price` and the `woocommerce_product_variation_get_*` equivalents — price × rate.
- `woocommerce_package_rates` — shipping cost/taxes × rate.
- `woocommerce_coupon_get_amount` — fixed coupon amount × rate.
- WooCommerce's own `woocommerce_geolocate_ip` / `woocommerce_get_geolocation` filters can be used for custom detection.

## Important notes

- **The payment gateway must support the order currency.** IPCoin creates USD orders for foreign visitors; a COP-only gateway cannot charge USD.
- **The payment gateway must also support the customer's country.** Some accounts (e.g. Rapyd enabled only for Colombia) reject foreign billing countries even when the currency is supported.
- WooCommerce price **lookup tables** (`wc_product_meta_lookup`) are not rebuilt, so price sorting/filter widgets may use base-currency values.
- Geolocation can be wrong with VPNs/proxies; use the fallback currency and the admin test override.
- Orders keep the currency they were placed in; changing the visitor's country later does not change existing orders.

## Uninstall

Uninstalling the plugin deletes `ipcoin_settings`, `ipcoin_last_rate`, the `ipcoin_rate_keys` option and all cached rate transients. It does not modify orders.

## Development

```bash
php -l ipcoin.php
php -l includes/class-ipcoin.php
php -l includes/class-ipcoin-geo.php
php -l includes/class-ipcoin-rates.php
php -l includes/class-ipcoin-currency.php
php -l includes/class-ipcoin-settings.php
php -l uninstall.php
```

Manual checks:

- `?ipcoin_country=CO` (admin) → base currency.
- `?ipcoin_country=US` → foreign currency; add to cart and verify the cart, checkout and created order currency.
- Check the **IPCoin** admin page for the detected country/currency and the last rate.

## License

MIT — see https://opensource.org/licenses/MIT
