# Mayosis Child — EDD Checkout & Product Page

A WordPress child theme for [Mayosis](https://themeforest.net/item/mayosis-digital-marketplace-wordpress-theme/26568956) that replaces the default Easy Digital Downloads **checkout** and **single product** pages with a polished, conversion-focused design — without touching the parent theme.

---

## Part 1 — Single Product Page

A modern, marketplace-style product page applied to **every** EDD download via a single `template_include` filter (no per-product setup, no parent-theme edits).

### Features

- **Two-column above-the-fold** — image/gallery left, buy box right; everything that matters is visible without scrolling.
- **Adaptive price selector** — reads EDD's variable prices and renders the right UI automatically:
  - **Two-axis grid** (e.g. Plan × Duration) when option names follow `Plan - Duration`, greying out combinations that don't exist.
  - **Single-axis cards** when names don't split (e.g. hardware variants).
  - **Plain price** when the product has a single price.
  - The duration axis auto-collapses when every option shares one duration.
- **Live price + "Buy Now — $X"** — updates instantly as you choose; drives EDD's native cart so checkout is 100% standard.
- **EDD Reviews rating** — star average under the title (links to the Reviews tab); shows "No ratings yet" when empty.
- **TrustPilot strip** — official TrustPilot widget, theme-synced so it stays readable in dark mode.
- **Crypto payment badge** — BTC / ETH / USDT icons in the buy box.
- **"Want something else?"** — cross-category related-products grid for cross-sell, placed above the tabs.
- **Content tabs** — the product description is split into tabs automatically on each `<h2>` heading, followed by:
  - **Reviews** tab (EDD Reviews + login form), and
  - a distinct **Product Information** tab (dynamic EDD data + relocated FES vendor fields such as "Refund Supported").
- **Dark mode** — full `body.sp-night-mode-on` palette matching the Mayosis customizer.
- **Responsive** — single-column stack, touch-friendly controls, readable tab measure on mobile.

### How It Works

- **`template_include` filter** (`caw_force_single_product_template`) routes every `is_singular('download')` request to `caw-single-download.php`, so all products share one layout regardless of their stored page template (default / Prime / none).
- **`caw_get_price_model()`** parses `edd_get_variable_prices()`: it splits each option name on `" - "` (hyphen / en / em dash). Two clean parts → two-axis; otherwise → single-axis list. It detects which axis is a duration (month/year/week…) to label the axes, and builds a `plan|||duration → price_id` map.
- The selector UI sets the matching **native EDD checkbox/radio** and dispatches `change`; the custom **Buy Now** button clicks EDD's hidden add-to-cart button, so the native cart / checkout flow is preserved.
- **Tabs** are built by `caw_build_tabs()` from `the_content()` split on `<h2>`. FES (Frontend Submission) vendor "display field" tables are extracted from the content and relocated into the **Product Information** tab so they don't trail the last section.
- The **breadcrumb** uses the theme's own `dm_breadcrumbs()` (dynamic — reflects your EDD slug + category).
- **Dark mode** uses `body.sp-night-mode-on` overrides scoped under `.caw-product`.

### Option naming convention (for the two-axis grid)

For variable-price subscriptions, name each price option `Plan - Duration`, e.g.:

```
Pro - 1 Month
Pro - 1 Year
Max 5x - 3 Months
```

Anything that doesn't fit this pattern falls back gracefully to a single-axis card list — no configuration needed.

---

## Part 2 — Checkout

Replaces the default EDD blocks checkout with a two-column, conversion-focused layout.

### Features

- **Two-column checkout layout** — payment form left, order summary right.
- **Sticky order summary** — cart column stays visible while scrolling the form.
- **Trust badges** — SSL + payment icons in the order summary, persist through EDD's AJAX cart refresh.
- **Secure Checkout badge** + centred "Checkout" heading; **lock note** below the purchase button.
- **EDD Software Licensing** — "Renew An Existing License" form styled for dark mode.
- **EDD alert messages** — success / error / warning notices themed for dark mode.
- **Responsive** — mobile stack: heading → order summary → payment form.
- **Hides Save/Update Cart buttons** for a cleaner flow.

### How It Works

- `render_block_data` forces the EDD checkout block into `two-thirds` layout before render.
- `render_block` prepends the "Checkout" heading outside the block so it sits above both columns (and above the cart on mobile).
- Trust badges are injected into `.edd-blocks__cart` and re-injected by a `MutationObserver` after EDD's AJAX cart refresh.
- Dark mode overrides use `body.sp-night-mode-on`.

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8+ |
| [Mayosis Theme](https://themeforest.net/item/mayosis-digital-marketplace-wordpress-theme/26568956) | Any recent |
| [Easy Digital Downloads](https://easydigitaldownloads.com/) | 3.x+ (Blocks-based checkout) |
| EDD Reviews *(optional)* | for the product rating + Reviews tab |
| EDD Software Licensing *(optional)* | for the license renewal form styling |
| EDD Frontend Submission (FES) *(optional)* | vendor fields shown in Product Information |

## Installation

1. Install and activate the **Mayosis** parent theme.
2. Upload the `mayosis-child` folder to `/wp-content/themes/`.
3. Go to **Appearance → Themes** and activate **Mayosis Child**.
4. Ensure your EDD checkout page uses the **EDD Checkout** block (not the legacy shortcode).

## Configuration

### EDD Downloads Slug

In `functions.php`, match your EDD permalink (**Settings → Permalinks → EDD**):

```php
define( 'EDD_SLUG', 'products' ); // change to 'downloads', 'shop', etc.
```

### TrustPilot

Set your business unit / template in `caw_trustpilot_widget()` in `functions.php` (`data-businessunit-id`, `data-template-id`, `data-token`, review URL). The widget renders the live rating on your verified domain.

### Trust Badge Text (checkout)

Edit the strings in `caw_checkout_inline_js()` in `functions.php`:

```php
'256-bit SSL encrypted checkout'
'Crypto & card payments accepted'
```

### Dark Mode Colours

Sourced from the Mayosis Customizer (**Appearance → Customize → Dark Mode**); the child theme matches them under `body.sp-night-mode-on`.

## File Overview

| File | Purpose |
|---|---|
| `functions.php` | All PHP hooks/helpers — single-product template routing, price model, tabs, reviews, related products, TrustPilot, checkout enhancements |
| `style.css` | All CSS — product page + checkout, light/dark, responsive |
| `caw-single-download.php` | Custom single-product template (routed via `template_include`) |
| `checkout-template.php` | Full-width page template for the checkout page |

## Rollback

Remove the `caw_force_single_product_template` filter (or delete `caw-single-download.php`) to instantly restore the original product layout. The checkout and product enhancements are independent.

## License

MIT — free to use, modify, and distribute.
