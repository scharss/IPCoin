# AGENTS.md

Guidance for AI agents and developers working on **IPCoin**, a WooCommerce plugin that switches the currency by visitor country using WooCommerce geolocation.

Author: Scharss Azael — https://byterock.xyz/ · License: MIT

## Project

- Plain PHP WordPress plugin. **No Composer, npm or build step.**
- Text domain: `ipcoin` (translations in `/languages`).
- Requires WordPress 5.8+, PHP 7.4+, WooCommerce 6.0+ (header tested up to WC 9.8).
- Declares compatibility with **HPOS** (`custom_order_tables`) and **Cart/Checkout Blocks** in `ipcoin.php`.
- Follows WordPress coding standards: tabs, sanitize input, escape output, `__()`/`esc_html__()` with the `ipcoin` text domain.

## Purpose

- Domestic countries (default `CO`) → store **base currency** (e.g. COP).
- Any other country → **foreign currency** (default USD).
- No detected country → **fallback currency** (default USD).
- The switch is **real**: the store currency, product prices, cart, order and payment gateway all use the visitor currency.

## File map

```
ipcoin/
├── ipcoin.php                         # Header, bootstrap, HPOS/blocks compatibility
├── includes/
│   ├── class-ipcoin.php               # Singleton, defaults, settings helpers, base/visitor currency, get_rate()
│   ├── class-ipcoin-geo.php           # WC_Geolocation + session/cookie persistence, country→currency
│   ├── class-ipcoin-rates.php         # Yahoo → er-api → Google (scrape) → manual, 12h cache
│   ├── class-ipcoin-currency.php      # woocommerce_currency filter + price/shipping/coupon conversion
│   └── class-ipcoin-settings.php      # "IPCoin" admin menu + Settings API
├── uninstall.php
├── README.md
└── AGENTS.md
```

## Runtime flow

1. `ipcoin.php` on `plugins_loaded` loads the text domain, checks WooCommerce, requires `includes/class-ipcoin.php` and calls `IPCoin::instance()`.
2. `IPCoin` (singleton) loads Geo, Rates, Currency and Settings, then calls `init()` on each.
3. `IPCoin_Geo::resolve()` runs on `init` (priority 20) and `wp` (priority 1), once per request:
   - real admin page → base currency;
   - `?ipcoin_country=XX` (admin + `manage_options` + `test_override`) → force country/currency and persist;
   - stored session/cookie → reuse the stored currency/country;
   - otherwise `WC_Geolocation::geolocate_ip('', true, true)` → country → currency, then persist.
4. `IPCoin_Currency` filters `woocommerce_currency` and converts prices, shipping and fixed coupons using `IPCoin_Rates::get_rate()`.
5. Because the cart/order are calculated with the filtered prices + currency, the order currency is the visitor currency and the gateway charges it.

## Key methods

- `IPCoin::defaults()`, `get_settings()`, `get_setting()`, `base_currency()`, `visitor_currency()`, `get_rate()`.
- `IPCoin_Geo::resolve()`, `country()`, `currency()`, `currency_for_country()`, `is_admin_context()`, `persist()`.
- `IPCoin_Currency::should_convert()`, `filter_currency()`, `convert_price()`, `convert_amount()`, `convert_shipping()`, `convert_coupon_amount()`.
- `IPCoin_Rates::get_rate()`, `fetch_rate()`, `clear_cache()`.
- `IPCoin_Settings::sanitize()`, `handle_refresh()`, `render_page()`.

## Data / options

- `ipcoin_settings` — settings array (see defaults below).
- `ipcoin_last_rate` — last successful rate: `base`, `target`, `rate`, `time`, `source`.
- `ipcoin_rate_keys` — transient keys created by the plugin (used by `clear_cache()`).
- Transients `ipcoin_rate_{BASE}_{TARGET}` — cached rates.
- WC session keys `ipcoin_country`, `ipcoin_currency`.
- Cookies `ipcoin_country`, `ipcoin_currency` (24h).

Defaults (`IPCoin::defaults()`):

```php
enabled            => 1
domestic_countries => 'CO'
foreign_currency   => 'USD'
fallback_currency  => 'USD'
rate_source        => 'auto'
cache_ttl          => 720     // minutes
manual_rate        => ''
convert_shipping   => 1
convert_coupons    => 1
test_override      => 1
```

## Hooks / filters

- `woocommerce_currency` → visitor currency (priority 100).
- `woocommerce_product_get_price`, `_regular_price`, `_sale_price` and `woocommerce_product_variation_get_*` → price × rate (priority 100).
- `woocommerce_package_rates` → shipping cost/taxes × rate.
- `woocommerce_coupon_get_amount` → fixed coupon amount × rate.
- `ipcoin_should_convert` → custom filter to disable conversion in a context.
- WooCommerce's own `woocommerce_geolocate_ip` / `woocommerce_get_geolocation` → usable for custom detection.

## Gotchas / rules

- **`is_admin()` is true in `admin-ajax.php`.** `is_admin_context()` returns true only for real admin pages (not AJAX, not REST). Do **not** replace it with a bare `is_admin()`.
- **Do not exclude AJAX/REST/Store API** from conversion: the block cart/checkout and other integrations depend on it.
- **Rounding:** `convert_amount()` uses `wc_get_price_decimals()` (the **store's** decimal setting), not the currency's ISO exponent. With 0 decimals (e.g. COP), USD prices are rounded to whole units.
- **Rate availability:** if no rate can be obtained, `should_convert()` returns `false` and the store stays in the base currency (never charge a wrong converted value).
- **Manual rate** overrides every source; saving the settings calls `clear_cache()`.
- **Stale fallback:** the last successful rate (`ipcoin_last_rate`) is used when all sources fail.
- **Session/cookie persistence (24h)** can keep a stale currency after changing settings; use the test override or clear the session/cookies.
- **Coupons:** only `fixed_cart` / `fixed_product` are converted; percentage coupons are untouched.
- **Fees:** fixed fees added by other plugins are not converted.
- **Lookup tables:** `wc_product_meta_lookup` prices are not rebuilt, so price sorting/filter widgets may use base values.
- **Payment gateway:** it must support the order **currency** and the customer's **country**. A gateway account enabled only for Colombia will reject foreign billing countries even for USD. This is outside IPCoin.
- **Test override** is admin-only (`manage_options`) and only when `test_override` is enabled.
- **Security:** sanitize settings in `sanitize()`, check capabilities, use the `ipcoin_refresh_rate` nonce, and escape all output.
- **HPOS:** orders are created with the filtered currency at checkout; compatibility is declared.

## Verification

```bash
php -l ipcoin.php
php -l includes/class-ipcoin.php
php -l includes/class-ipcoin-geo.php
php -l includes/class-ipcoin-rates.php
php -l includes/class-ipcoin-currency.php
php -l includes/class-ipcoin-settings.php
php -l uninstall.php
```

Manual:

- Visit the shop with `?ipcoin_country=CO` (admin) → prices/currency = base (COP).
- Visit with `?ipcoin_country=US` → prices/currency = USD (converted); add to cart and check the cart/checkout and the created order currency.
- Check the **IPCoin** admin menu for the detected country/currency and the last rate.
- Verify the gateway used can charge the order currency **and** the customer's country.

## Common tasks

- **Add a rate source:** add a `fetch_*()` method in `IPCoin_Rates`, wire it in `fetch_rate()`, and add the value to the `rate_source` whitelist in `IPCoin_Settings::sanitize()` and the settings `<select>`.
- **Change defaults:** update `IPCoin::defaults()` and keep the settings UI/sanitizer in sync.
- **Convert another amount type:** add the WooCommerce filter in `IPCoin_Currency::init()` and convert with the same rate + `wc_get_price_decimals()` logic.
- **Change geolocation:** filter `woocommerce_geolocate_ip` or modify `IPCoin_Geo::geolocate()`.
- **Clear rates:** `IPCoin_Rates::clear_cache()` (also called when saving settings).

## Do not

- Convert on real admin pages.
- Exclude AJAX/REST/Store API from conversion.
- Hardcode currencies/countries (read from settings).
- Remove the `ipcoin_should_convert` escape hatch.
- Store rates without an expiry (use the transient + `ipcoin_rate_keys` registry).
