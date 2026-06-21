# Mayosis Child — EDD Checkout

A WordPress child theme for [Mayosis](https://themeforest.net/item/mayosis-digital-marketplace-wordpress-theme/26568956) that replaces the default Easy Digital Downloads checkout with a polished, conversion-focused design.

## Features

- **Two-column checkout layout** — payment form on the left, order summary on the right
- **Sticky order summary** — cart column stays visible while scrolling the form
- **Dark mode support** — fully themed to match Mayosis's `sp-night-mode-on` colour scheme
- **Trust badges** — SSL and payment method icons in the order summary, persist through EDD's AJAX cart refresh
- **Secure Checkout badge** — lock icon + green pill above the form
- **Centred checkout heading** — "Checkout" title centred with the secure badge pinned right
- **Lock note below purchase button** — "Secured payment — no hidden fees"
- **EDD Software Licensing** — "Renew An Existing License" form properly styled for dark mode
- **EDD alert messages** — success / error / warning notices themed for dark mode
- **Responsive** — mobile stack order: heading → order summary → payment form
- **Hides Save/Update Cart buttons** — cleaner checkout flow

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8+ |
| [Mayosis Theme](https://themeforest.net/item/mayosis-digital-marketplace-wordpress-theme/26568956) | Any recent |
| [Easy Digital Downloads](https://easydigitaldownloads.com/) | 3.x+ (Blocks-based checkout) |
| EDD Software Licensing *(optional)* | Any — for the license renewal form styling |

## Installation

1. Install and activate the **Mayosis** parent theme.
2. Upload the `mayosis-child` folder to `/wp-content/themes/`.
3. Go to **Appearance → Themes** and activate **Mayosis Child — EDD Checkout**.
4. Make sure your EDD checkout page uses the **EDD Checkout** block (not the legacy shortcode).

## Configuration

### EDD Downloads Slug

Open `functions.php` and update the slug to match your site's EDD permalink setting (**Settings → Permalinks → EDD**):

```php
define( 'EDD_SLUG', 'products' ); // change to 'downloads', 'shop', etc.
```

### Trust Badge Text

The trust badges are injected via JavaScript in `functions.php` inside `caw_checkout_inline_js()`. Edit the two strings to match your store:

```php
'256-bit SSL encrypted checkout'
'Crypto & card payments accepted'
```

### Dark Mode Colours

Colours are sourced from the Mayosis Customizer (**Appearance → Customize → Dark Mode**). The child theme reads the same values automatically — no hardcoded colours to change.

## File Overview

| File | Purpose |
|---|---|
| `style.css` | All checkout CSS — layout, dark mode, responsive |
| `functions.php` | PHP hooks — two-column layout, header injection, trust badges, alert theming |
| `checkout-template.php` | Full-width page template for the checkout page |

## How It Works

- `render_block_data` filter forces the EDD checkout block into `two-thirds` layout mode before it renders.
- `render_block` filter prepends the "Checkout" heading outside the block so it sits above both columns on desktop and above the order summary on mobile.
- Trust badges are injected via JS into `.edd-blocks__cart` (outside `#edd_checkout_cart`) so they survive EDD's AJAX cart refresh. A `MutationObserver` re-injects them if EDD replaces the inner cart HTML.
- Dark mode overrides use `body.sp-night-mode-on` — the class Mayosis toggles when the user switches to night mode.

## License

MIT — free to use, modify, and distribute.
