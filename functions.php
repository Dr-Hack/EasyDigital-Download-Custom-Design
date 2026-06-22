<?php
/**
 * Theme functions and definitions.
 */
function mayosis_child_enqueue_styles() {
    $style_path = get_stylesheet_directory() . '/style.css';
    wp_enqueue_style(
        'mayosis-child-style',
        get_stylesheet_directory_uri() . '/style.css',
        array( 'mayosis-style' ),
        file_exists( $style_path ) ? filemtime( $style_path ) : wp_get_theme()->get( 'Version' )
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

/* =============================================================================
   SINGLE PRODUCT REDESIGN
   Route every single download to one custom template so ALL products share the
   same layout regardless of their per-post template (default / prime / none).
   Parent theme untouched; remove this filter to revert.
   ============================================================================= */

add_filter( 'template_include', 'caw_force_single_product_template', 99 );
function caw_force_single_product_template( $template ) {
    if ( is_singular( 'download' ) ) {
        $custom = get_stylesheet_directory() . '/caw-single-download.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
}

// Load the TrustPilot widget bootstrap only on single product pages.
add_action( 'wp_enqueue_scripts', 'caw_product_assets' );
function caw_product_assets() {
    if ( is_singular( 'download' ) ) {
        wp_enqueue_script(
            'trustpilot-widget',
            'https://widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js',
            array(),
            null,
            true
        );
    }
}

/* ---- TrustPilot config (see reference: businessunit-id etc.) ------------- */
function caw_trustpilot_widget( $template_id = '5419b6a8b0d04a076446a9ad', $height = '24px' ) {
    ?>
    <div class="trustpilot-widget" data-locale="en-US"
         data-template-id="<?php echo esc_attr( $template_id ); ?>"
         data-businessunit-id="64ff3c8ffe3677ea98269255"
         data-style-height="<?php echo esc_attr( $height ); ?>" data-style-width="100%"
         data-theme="light"
         data-token="d5f2ff76-24e8-4461-84a5-2c900ea672f0">
        <a href="https://www.trustpilot.com/review/cryptoawaz.com" target="_blank" rel="noopener">Trustpilot</a>
    </div>
    <?php
}

/* ---- Keep the TrustPilot widget readable in both day/night modes ---------
   The widget's text colour is set by data-theme; sync it with the night-mode
   switch and re-render so the real rating/score stays visible. */
add_action( 'wp_footer', 'caw_trustpilot_theme_sync' );
function caw_trustpilot_theme_sync() {
    if ( ! is_singular( 'download' ) ) {
        return;
    }
    ?>
    <script>
    (function () {
        function applyTP() {
            var w = document.querySelector('.caw-tp .trustpilot-widget');
            if (!w) return;
            var dark = document.body.classList.contains('sp-night-mode-on');
            w.setAttribute('data-theme', dark ? 'dark' : 'light');
            if (window.Trustpilot && window.Trustpilot.loadFromElement) {
                window.Trustpilot.loadFromElement(w, true);
            }
        }
        function ready() {
            if (window.Trustpilot) { applyTP(); }
            else { setTimeout(ready, 300); }
        }
        if (document.readyState === 'complete') { ready(); }
        else { window.addEventListener('load', ready); }
        // Re-apply when the user toggles night mode (body class changes).
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                if (muts[i].attributeName === 'class') { applyTP(); break; }
            }
        }).observe(document.body, { attributes: true });
    })();
    </script>
    <?php
}

/* ---- EDD Reviews: inline star rating under the title -------------------- */
function caw_review_stars_html( $id ) {
    if ( ! class_exists( 'EDD_Reviews' ) ) {
        return '';
    }
    $avg = (float) edd_reviews()->average_rating( false, $id );

    ob_start();
    if ( $avg > 0 ) {
        $full = (int) floor( $avg );
        $half = ( $avg - $full ) >= 0.5;
        echo '<a href="#caw-panel-reviews" class="caw-rating" data-jump="reviews">';
        echo '<span class="caw-stars">';
        for ( $i = 1; $i <= 5; $i++ ) {
            if ( $i <= $full ) {
                echo '<i class="fa fa-star"></i>';
            } elseif ( $i === $full + 1 && $half ) {
                echo '<i class="fa fa-star-half-o"></i>';
            } else {
                echo '<i class="fa fa-star-o"></i>';
            }
        }
        // EDD Reviews doesn't surface a public count, so show just the rating number.
        echo '</span> <b>' . esc_html( number_format( $avg, 1 ) ) . '</b>';
        echo '</a>';
    } else {
        echo '<span class="caw-rating caw-norating"><span class="caw-stars">'
            . str_repeat( '<i class="fa fa-star-o"></i>', 5 )
            . '</span> ' . esc_html__( 'No ratings yet', 'mayosis' ) . '</span>';
    }
    return ob_get_clean();
}

/* ---- Detect whether a list of axis values are durations ----------------- */
function caw_is_duration_list( $vals ) {
    if ( empty( $vals ) ) {
        return false;
    }
    $hit = 0;
    foreach ( $vals as $v ) {
        if ( preg_match( '/(month|year|week|day|lifetime|annual|\byr\b|\bmo\b)/i', $v ) ) {
            $hit++;
        }
    }
    return $hit >= ceil( count( $vals ) / 2 );
}

/* ---- Build the pricing model from EDD variable prices ------------------- */
function caw_get_price_model( $id ) {
    $model = array( 'variable' => false, 'two_axis' => false );

    if ( ! function_exists( 'edd_has_variable_prices' ) || ! edd_has_variable_prices( $id ) ) {
        return $model;
    }
    $prices = edd_get_variable_prices( $id );
    if ( empty( $prices ) ) {
        return $model;
    }

    $model['variable']    = true;
    $model['default_pid'] = function_exists( 'edd_get_default_variable_price' )
        ? (int) edd_get_default_variable_price( $id )
        : (int) array_key_first( $prices );

    // Parse each option name on " - " (hyphen / en/em dash, space padded).
    $rows = array();
    $two  = true;
    foreach ( $prices as $pid => $p ) {
        $name   = isset( $p['name'] ) ? $p['name'] : '';
        $amount = isset( $p['amount'] ) ? $p['amount'] : 0;
        $parts  = preg_split( '/\s+[-\x{2013}\x{2014}]\s+/u', $name, 2 );
        if ( count( $parts ) === 2 ) {
            $a = trim( $parts[0] );
            $b = trim( $parts[1] );
        } else {
            $two = false;
            $a   = trim( $name );
            $b   = '';
        }
        $rows[] = array( 'pid' => (int) $pid, 'name' => $name, 'amount' => $amount, 'a' => $a, 'b' => $b );
    }

    if ( $two ) {
        $aVals = array();
        $bVals = array();
        foreach ( $rows as $r ) {
            if ( ! in_array( $r['a'], $aVals, true ) ) { $aVals[] = $r['a']; }
            if ( ! in_array( $r['b'], $bVals, true ) ) { $bVals[] = $r['b']; }
        }
        $aDur = caw_is_duration_list( $aVals );
        $bDur = caw_is_duration_list( $bVals );
        $swap = ( $aDur && ! $bDur ); // put the non-duration axis first (as "plan")

        $plans = array();
        $durs  = array();
        $map   = array();
        foreach ( $rows as $r ) {
            $plan = $swap ? $r['b'] : $r['a'];
            $dur  = $swap ? $r['a'] : $r['b'];
            if ( ! in_array( $plan, $plans, true ) ) { $plans[] = $plan; }
            if ( ! in_array( $dur, $durs, true ) )  { $durs[]  = $dur; }
            $map[ $plan . '|||' . $dur ] = $r['pid'];
        }

        $model['two_axis']   = true;
        $model['plans']      = $plans;
        $model['durs']       = $durs;
        $model['map']        = $map;
        $model['dur_label']  = __( 'Duration', 'mayosis' );
        $model['plan_label'] = __( 'Plan', 'mayosis' );
        if ( ! $aDur && ! $bDur ) {
            $model['plan_label'] = __( 'Option', 'mayosis' );
            $model['dur_label']  = __( 'Variant', 'mayosis' );
        }
    } else {
        $opts = array();
        foreach ( $rows as $r ) {
            $opts[] = array( 'pid' => $r['pid'], 'name' => $r['name'], 'amount' => $r['amount'] );
        }
        $model['options'] = $opts;
    }

    // Formatted price string per price id (for live JS display).
    $pidPrice = array();
    foreach ( $prices as $pid => $p ) {
        // Decode HTML entities ($ is output as &#36;) so JS textContent shows "$" not "&#36;".
        $pidPrice[ (int) $pid ] = html_entity_decode(
            edd_currency_filter( edd_format_amount( $p['amount'] ) ),
            ENT_QUOTES,
            'UTF-8'
        );
    }
    $model['pidPrice'] = $pidPrice;

    return $model;
}

/* ---- EDD reviews markup (for the Reviews tab) --------------------------- */
function caw_reviews_html() {
    if ( ! class_exists( 'EDD_Reviews' ) || edd_reviews()->is_review_status( 'disabled' ) ) {
        return '';
    }
    ob_start();
    edd_get_template_part( 'reviews' );
    if ( get_option( 'thread_comments' ) ) {
        edd_get_template_part( 'reviews-reply' );
    }
    return ob_get_clean();
}

/* ---- Dynamic Product Information grid ----------------------------------- */
function caw_product_info_html( $id ) {
    $rows = array();
    $rows[] = array( __( 'Listed', 'mayosis' ), esc_html( get_the_date( '', $id ) ) );
    $rows[] = array( __( 'Last updated', 'mayosis' ), esc_html( get_the_modified_date( '', $id ) ) );

    if ( function_exists( 'edd_get_download_sales_stats' ) ) {
        $sales = edd_get_download_sales_stats( $id );
        if ( $sales ) {
            $rows[] = array( __( 'Sales', 'mayosis' ), number_format( $sales ) );
        }
    }
    $cat = get_the_term_list( $id, 'download_category', '', ', ' );
    if ( $cat && ! is_wp_error( $cat ) ) {
        $rows[] = array( __( 'Category', 'mayosis' ), $cat );
    }
    if ( function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $id ) ) {
        $prices = edd_get_variable_prices( $id );
        $amounts = wp_list_pluck( $prices, 'amount' );
        if ( $amounts ) {
            $min = edd_currency_filter( edd_format_amount( min( $amounts ) ) );
            $max = edd_currency_filter( edd_format_amount( max( $amounts ) ) );
            $rows[] = array( __( 'Price range', 'mayosis' ), $min . ' – ' . $max );
        }
    }
    // Refund info comes from the vendor's FES "Refund Supported" field (relocated into
    // this tab by caw_build_tabs), so we don't duplicate EDD's refundability here.
    $tags = get_the_term_list( $id, 'download_tag', '', ', ' );
    if ( $tags && ! is_wp_error( $tags ) ) {
        $rows[] = array( __( 'Tags', 'mayosis' ), $tags );
    }

    ob_start();
    echo '<div class="caw-infogrid">';
    foreach ( $rows as $r ) {
        echo '<div class="caw-row"><span>' . esc_html( $r[0] ) . '</span><span>' . wp_kses_post( $r[1] ) . '</span></div>';
    }
    echo '</div>';
    return ob_get_clean();
}

/* ---- Content tabs: split the_content() on <h2> + Reviews + Info --------- */
function caw_build_tabs( $id ) {
    $content = apply_filters( 'the_content', get_post_field( 'post_content', $id ) );

    // FES (Frontend Submission) appends vendor "display field" tables to the content
    // (e.g. "Refund Supported: No"). Pull them out so they don't trail the last content
    // tab — they're relocated into the Product Information tab below.
    $fes_html = '';
    if ( preg_match_all( '/<table[^>]*fes-display-field-table[^>]*>.*?<\/table>/is', $content, $m ) ) {
        $fes_html = implode( "\n", $m[0] );
        $content  = preg_replace( '/<table[^>]*fes-display-field-table[^>]*>.*?<\/table>/is', '', $content );
    }

    $parts   = preg_split( '/<h2\b[^>]*>(.*?)<\/h2>/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE );

    $tabs  = array();
    $intro = array_shift( $parts );
    if ( trim( strip_tags( (string) $intro ) ) !== '' ) {
        $tabs[] = array( 'title' => __( 'Description', 'mayosis' ), 'html' => $intro );
    }
    for ( $i = 0; $i + 1 < count( $parts ); $i += 2 ) {
        $title = trim( strip_tags( $parts[ $i ] ) );
        $body  = $parts[ $i + 1 ];
        if ( '' === $title ) {
            $title = __( 'Details', 'mayosis' );
        }
        $tabs[] = array( 'title' => $title, 'html' => $body );
    }

    $reviews = caw_reviews_html();
    if ( '' !== trim( $reviews ) ) {
        $tabs[] = array( 'title' => __( 'Reviews', 'mayosis' ), 'html' => $reviews, 'key' => 'reviews' );
    }
    $info_html = caw_product_info_html( $id );
    if ( '' !== $fes_html ) {
        $info_html .= '<div class="caw-fes-fields">' . $fes_html . '</div>';
    }
    $tabs[] = array( 'title' => __( 'Product Information', 'mayosis' ), 'html' => $info_html, 'info' => true );

    return $tabs;
}

/* ---- "WANT SOMETHING ELSE?" cross-category related products ------------- */
function caw_related_products( $id, $limit = 4 ) {
    $cur_cats = wp_get_object_terms( $id, 'download_category', array( 'fields' => 'ids' ) );

    $args = array(
        'post_type'           => 'download',
        'post_status'         => 'publish',
        'posts_per_page'      => $limit,
        'post__not_in'        => array( $id ),
        'orderby'             => 'rand',
        'ignore_sticky_posts' => 1,
    );
    if ( $cur_cats && ! is_wp_error( $cur_cats ) ) {
        $args['tax_query'] = array(
            array(
                'taxonomy' => 'download_category',
                'field'    => 'id',
                'terms'    => $cur_cats,
                'operator' => 'NOT IN',
            ),
        );
    }
    $q = new WP_Query( $args );
    if ( ! $q->have_posts() ) { // small catalog fallback: any other product
        wp_reset_postdata();
        unset( $args['tax_query'] );
        $q = new WP_Query( $args );
    }
    return $q;
}
