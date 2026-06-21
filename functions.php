<?php
/**
 * Theme functions and definitions.
 */
function mayosis_child_enqueue_styles() {
    wp_enqueue_style(
        'mayosis-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( 'mayosis-style' ),
        wp_get_theme()->get( 'Version' )
    );


}
add_action( 'wp_enqueue_scripts', 'mayosis_child_enqueue_styles' );

// Change this to match your EDD Downloads permalink slug (Settings → Permalinks → EDD).
// Common values: 'downloads', 'products', 'shop'
define( 'EDD_SLUG', 'products' );

/* =============================================================================
   CHECKOUT — FORCE TWO-THIRDS LAYOUT
   Modifies the EDD checkout block's stored attrs before the render callback
   fires, so PHP generates the correct grid class from the start.
   This is more reliable than post-render string replacement.
   ============================================================================= */

add_filter( 'render_block_data', 'caw_force_checkout_two_col' );
function caw_force_checkout_two_col( $parsed_block ) {
    if ( isset( $parsed_block['blockName'] ) && $parsed_block['blockName'] === 'edd/checkout' ) {
        $parsed_block['attrs']['layout'] = 'two-thirds';
    }
    return $parsed_block;
}

/* =============================================================================
   CHECKOUT — HEADER (title + secure badge)
   Injected via edd_before_purchase_form so it works whether the page uses
   the Gutenberg block OR the legacy [edd_checkout] shortcode.
   ============================================================================= */

// Prepend the checkout header BEFORE the EDD checkout block (outside the grid/flex
// container) so on mobile it naturally sits above the order summary and form.
add_filter( 'render_block', 'caw_prepend_checkout_header', 10, 2 );
function caw_prepend_checkout_header( $block_content, $block ) {
    if ( ! isset( $block['blockName'] ) || $block['blockName'] !== 'edd/checkout' ) {
        return $block_content;
    }
    if ( ! function_exists( 'edd_is_checkout' ) || ! edd_is_checkout() ) {
        return $block_content;
    }
    $header = '<div class="caw-checkout-header">'
        . '<h1 class="caw-checkout-title">' . esc_html__( 'Checkout', 'mayosis' ) . '</h1>'
        . '<span class="caw-secure-badge"><i class="fa fa-lock"></i> ' . esc_html__( 'Secure Checkout', 'mayosis' ) . '</span>'
        . '</div>';
    return $header . $block_content;
}

/* =============================================================================
   CHECKOUT — TRUST BADGES (after cart totals in order summary column)
   ============================================================================= */

// Trust badges are injected via JavaScript (see caw_checkout_inline_js below)
// so they survive EDD's AJAX cart refresh that replaces #edd_checkout_cart.

/* =============================================================================
   CHECKOUT — LOCK NOTE BELOW PURCHASE BUTTON
   ============================================================================= */

add_action( 'edd_purchase_form_after_submit', 'caw_checkout_below_button_note' );
function caw_checkout_below_button_note() {
    if ( ! function_exists( 'edd_is_checkout' ) || ! edd_is_checkout() ) {
        return;
    }
    echo '<p class="caw-below-button-note">'
        . '<i class="fa fa-lock"></i> '
        . esc_html__( 'Secured payment — no hidden fees', 'mayosis' )
        . '</p>';
}

/* =============================================================================
   CHECKOUT — TRUST BADGES VIA JS
   Injected after .edd-blocks__cart (outside #edd_checkout_cart) so they
   survive EDD's AJAX refresh which only replaces #edd_checkout_cart content.
   MutationObserver re-injects if AJAX removes them.
   ============================================================================= */

add_action( 'wp_footer', 'caw_checkout_inline_js' );
function caw_checkout_inline_js() {
    if ( ! function_exists( 'edd_is_checkout' ) || ! edd_is_checkout() ) {
        return;
    }
    ?>
    <script>
    (function () {
        var badgesHTML =
            '<div class="caw-trust-badges">' +
            '<div class="caw-trust-item"><i class="fa fa-lock"></i><span><?php echo esc_js( __( '256-bit SSL encrypted checkout', 'mayosis' ) ); ?></span></div>' +
            '<div class="caw-trust-item"><i class="fa fa-shield"></i><span><?php echo esc_js( __( 'Crypto & card payments accepted', 'mayosis' ) ); ?></span></div>' +
            '</div>';

        function injectBadges() {
            var cart = document.querySelector('.edd-blocks__checkout .edd-blocks__cart');
            if (!cart) return;
            if (cart.querySelector('.caw-trust-badges')) return;
            cart.insertAdjacentHTML('beforeend', badgesHTML);
        }

        // Inject on load.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', injectBadges);
        } else {
            injectBadges();
        }

        // Re-inject after EDD AJAX refreshes #edd_checkout_cart.
        var eddCart = document.getElementById('edd_checkout_cart');
        if (eddCart) {
            new MutationObserver(function () { injectBadges(); })
                .observe(eddCart, { childList: true, subtree: false });
        }
    })();
    </script>
    <?php
}

/* =============================================================================
   CHECKOUT — HIDE SAVE / UPDATE CART BUTTONS
   ============================================================================= */

add_filter( 'edd_is_cart_saving_disabled', '__return_true' );
