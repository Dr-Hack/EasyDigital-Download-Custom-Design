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

// Cloudflare Turnstile — auth modal, wp-login.php and the FES vendor forms.
require_once get_stylesheet_directory() . '/caw-turnstile.php';

/* =============================================================================
   SOCIAL LOGIN — Nextend buttons in the auth popup and on the account pages
   ============================================================================= */

add_action( 'init', 'caw_register_social_login_bridge', 20 );
/**
 * Light up the auth popup's built-in social section using Nextend.
 *
 * The parent theme's popup (header-account.php) already renders an
 * "or continue with" divider followed by `[edd_social_login]` in BOTH the login
 * and register panels — but only when that shortcode exists. It's a hook left
 * for EDD's own social add-on, which this site doesn't run; Nextend registers
 * `[nextend_social_login]` instead. Aliasing one onto the other lights up the
 * popup's existing markup with no parent-theme edit and no DOM shuffling.
 *
 * If a genuine EDD social add-on is ever activated it keeps the shortcode and
 * this bridge stands down.
 */
function caw_register_social_login_bridge() {
	if ( shortcode_exists( 'edd_social_login' ) || ! shortcode_exists( 'nextend_social_login' ) ) {
		return;
	}
	add_shortcode( 'edd_social_login', 'caw_social_login_buttons' );
}

/**
 * Nextend's buttons, wrapped so the child theme can style them.
 *
 * @param array $atts labeltype: 'login' ("Continue with X") or 'register'.
 */
function caw_social_login_buttons( $atts = array() ) {
	if ( ! shortcode_exists( 'nextend_social_login' ) || is_user_logged_in() ) {
		return '';
	}

	$atts = shortcode_atts( array( 'labeltype' => 'login' ), (array) $atts, 'edd_social_login' );

	return '<div class="caw-social-login">'
		. do_shortcode( sprintf( '[nextend_social_login align="center" labeltype="%s"]', esc_attr( $atts['labeltype'] ) ) )
		. '</div>';
}

add_action( 'edd_checkout_login_fields_after', 'caw_checkout_social_login', 20 );
/**
 * Social buttons below the checkout login fields.
 *
 * REQUIRES: Nextend Social Login → Easy Digital Downloads → checkout position
 * set to "No connect button". Nextend renders its own set otherwise, and every
 * position it offers is whole-form (edd_checkout_form_top,
 * edd_purchase_form_before_email, ..._before_submit, ..._after_submit,
 * edd_cart_items_before, edd_before_purchase_form) — the default puts them at
 * the top of the purchase form, above Personal Info and nowhere near "Log Into
 * Your Account". Leave that setting on and both sets render.
 *
 * Its `edd_login` setting does offer before/after the login fields, but hooks
 * `edd_login_fields_after`, which EDD fires only for the standalone edd/login
 * block and [edd_login] shortcode; the checkout fires
 * `edd_checkout_login_fields_after`. So this placement isn't reachable from
 * Nextend's settings, hence doing it here.
 *
 * Priority 20, so the buttons follow the "Lost Password?" link below.
 */
function caw_checkout_social_login() {
	$buttons = caw_social_login_buttons( array( 'labeltype' => 'login' ) );
	if ( '' === $buttons ) {
		return;
	}

	echo '<div class="caw-social-divider"><span>' . esc_html__( 'or continue with', 'mayosis-child' ) . '</span></div>'
		. $buttons; // phpcs:ignore WordPress.Security.EscapeOutput
}

add_action( 'edd_checkout_login_fields_after', 'caw_checkout_lost_password_link' );
/**
 * Restore the "Lost Password?" link in the checkout login form.
 *
 * EDD only renders that link when its Login Page setting points at a page
 * holding the `edd/login` block (blocks/views/checkout/purchase-form/login.php
 * wraps it in `if ( $login_page )`). This site deliberately clears that setting
 * so every auth journey goes through wp-login.php — which silently removed the
 * link, leaving a returning customer who forgot their password no way out of
 * the checkout form.
 *
 * EDD fires this action at exactly the spot its own link would occupy, so the
 * markup and classes match. Skipped if the setting is ever restored, so the two
 * can't both render.
 */
function caw_checkout_lost_password_link() {
	if ( function_exists( 'edd_get_login_page_uri' ) && edd_get_login_page_uri() ) {
		return; // EDD is rendering its own.
	}

	$redirect = function_exists( 'edd_get_checkout_uri' ) ? edd_get_checkout_uri() : '';

	printf(
		'<p class="edd-blocks-form__group edd-blocks-form__group-lost-password caw-lost-password"><a href="%1$s">%2$s</a></p>',
		esc_url( wp_lostpassword_url( $redirect ) ),
		esc_html__( 'Lost Password?', 'mayosis-child' )
	);
}

/* =============================================================================
   POPUP REGISTRATION — require a verified email address
   ============================================================================= */

add_action( 'wp_ajax_nopriv_mayosis_mbw_lrb_ajax_register', 'caw_verified_popup_registration', 2 );
/**
 * Replace the popup's registration handler with WordPress core's.
 *
 * The theme's own handler (mayosis-ajax-auth.php, priority 10) takes a
 * user-chosen password, calls wp_insert_user() and then wp_set_auth_cookie() —
 * signing the visitor straight in — and notifies only the admin. Nothing ever
 * proves the address belongs to them, so anything typed into the email field
 * becomes an account. That was the same weakness as the old /register/ page.
 *
 * core's register_new_user() instead generates the password itself
 * (wp_generate_password) and fires the `register_new_user` action, which core
 * hooks wp_send_new_user_notifications() onto with $notify = 'both' — so the
 * registrant is emailed a set-password link and the account is unusable until
 * they follow it. Same guarantee as wp-login.php?action=register.
 *
 * Runs at priority 2: after the Turnstile guard at 1, before the theme's
 * handler at 10. wp_send_json() exits, so the theme's handler never runs.
 *
 * Turnstile's own `registration_errors` check (which register_new_user fires)
 * no-ops here — it is scoped to real wp-login.php posts, and this is
 * admin-ajax.php — so the token is checked once, not twice.
 */
function caw_verified_popup_registration() {
	if ( ! check_ajax_referer( 'mayosis_mbw_lrb_ajax_register_nonce', 'security', false ) ) {
		wp_send_json(
			array(
				'registered' => false,
				'message'    => __( 'Security check failed. Please refresh the page and try again.', 'mayosis-child' ),
			)
		);
	}

	if ( ! get_option( 'users_can_register' ) ) {
		wp_send_json(
			array(
				'registered' => false,
				'message'    => __( 'New registrations are currently disabled.', 'mayosis-child' ),
			)
		);
	}

	$username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
	$email    = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	/* register_new_user() does the rest of the validation itself: empty fields,
	   illegal characters, existing username, existing or malformed email. */
	$user_id = register_new_user( $username, $email );

	if ( is_wp_error( $user_id ) ) {
		wp_send_json(
			array(
				'registered' => false,
				/* Core's messages carry markup ("<strong>Error:</strong> …", a
				   login link); the popup renders into textContent. */
				'message'    => wp_strip_all_tags( $user_id->get_error_message() ),
			)
		);
	}

	wp_send_json(
		array(
			'registered' => true,
			'message'    => __( 'Account created — check your email for a link to set your password.', 'mayosis-child' ),
			/* The popup's JS always navigates ~1.2s after a success, so send it
			   somewhere that repeats the instruction rather than reloading the
			   page and losing it. This is core's own post-registration screen
			   ("Registration complete. Please check your email…").
			   site_url(), not wp_login_url(), so EDD's login_url filter can't
			   redirect it to a page that has no such message. */
			'redirect'   => site_url( 'wp-login.php?checkemail=registered', 'login' ),
		)
	);
}

add_action( 'wp_enqueue_scripts', 'caw_dedupe_auth_modal', 20 );
/**
 * Keep exactly one auth popup, and move it somewhere it can actually be seen.
 *
 * `mayosis_header_elements()` renders `header-account.php` once per header
 * region configured with the "account" element, and that template emits the
 * entire `#msv-auth-modal` every time — so this site's header produces two
 * identical modals: duplicate element IDs, two sets of social buttons, and two
 * sets of login/register/forgot forms.
 *
 * Worse, the first copy sits inside the desktop-only header wrapper
 * (`.d-none.d-lg-block`). Both the theme's own script and ours resolve
 * `#msv-auth-modal` with getElementById semantics — first match wins — so below
 * the `lg` breakpoint the popup that gets opened is the one inside a
 * `display:none` ancestor, and tapping Login appears to do nothing at all.
 *
 * Relocating the survivor to <body> fixes that for every breakpoint at once. It
 * also takes the dialog out of the sticky header, whose transforms would
 * otherwise become the containing block for its `position:fixed` overlay.
 *
 * Injected immediately before the theme's own auth script, so the move is done
 * before anything binds.
 */
function caw_dedupe_auth_modal() {
	if ( is_user_logged_in() || ! wp_script_is( 'msv-ajax-auth', 'enqueued' ) ) {
		return;
	}

	wp_add_inline_script(
		'msv-ajax-auth',
		'(function(){var m=document.querySelectorAll("#msv-auth-modal");if(!m.length){return;}'
		. 'for(var i=m.length-1;i>0;i--){m[i].parentNode.removeChild(m[i]);}'
		. 'document.body.appendChild(m[0]);}());',
		'before'
	);

	/**
	 * Size the sliding panel track to whichever panel is showing.
	 *
	 * The two panels are flex items in one track, so the track is always as tall
	 * as the TALLER of them — the login panel (397px) renders inside a box sized
	 * for the register panel (505px), leaving ~110px of empty card below the
	 * social buttons. Harmless slack on desktop; on the mobile bottom sheet it
	 * reads as the dialog failing to close.
	 *
	 * Runs after the theme's script so `.is-active` is already being managed.
	 * A ResizeObserver covers content that changes height later — a Turnstile
	 * challenge appearing, an error message, the password strength meter.
	 */
	wp_add_inline_script(
		'msv-ajax-auth',
		'(function(){'
		. 'var m=document.getElementById("msv-auth-modal");if(!m){return;}'
		. 'var pans=m.querySelector(".msv-auth-panels"),track=m.querySelector(".msv-auth-panels__track");'
		. 'if(!pans||!track){return;}'
		. 'function fit(){'
		. 'var ov=pans.querySelector(".msv-auth-forgot-overlay.is-open");'
		. 'if(ov){track.style.height=ov.scrollHeight+"px";return;}'
		. 'var a=track.querySelector(".msv-auth-panel.is-active");'
		. 'if(a){track.style.height=a.offsetHeight+"px";}}'
		. 'if(window.ResizeObserver){var ro=new ResizeObserver(fit);'
		. 'Array.prototype.forEach.call(pans.querySelectorAll(".msv-auth-panel"),function(p){ro.observe(p);});}'
		. 'if(window.MutationObserver){new MutationObserver(fit).observe(pans,'
		. '{subtree:true,attributes:true,attributeFilter:["class"]});}'
		. 'window.addEventListener("resize",fit);setTimeout(fit,60);}());',
		'after'
	);

	/**
	 * Take the password fields out of the register panel.
	 *
	 * caw_verified_popup_registration() hands registration to core, which
	 * generates the password and emails a set-password link — so asking for one
	 * here would be collecting a value that gets thrown away. Removed from the
	 * DOM rather than hidden with CSS: a hidden pair invites a password manager
	 * to fill one field and not the other, and the theme's script refuses to
	 * submit when the two don't match.
	 *
	 * The login panel's password field is untouched.
	 */
	wp_add_inline_script(
		'msv-ajax-auth',
		'(function(){'
		. 'var f=document.getElementById("mayosis_mbw_lrb_register_form");if(!f){return;}'
		. 'var ids=["mayosis_mbw_lrb_register_password","mayosis_mbw_lrb_register_confirm_password"];'
		. 'for(var i=0;i<ids.length;i++){var el=document.getElementById(ids[i]);'
		. 'if(el){var w=el.closest(".msv-field");if(w&&w.parentNode){w.parentNode.removeChild(w);}}}'
		. 'var s=f.querySelector(".msv-pw-strength");if(s&&s.parentNode){s.parentNode.removeChild(s);}'
		. 'var note=document.createElement("p");note.className="caw-reg-note";'
		. 'note.textContent=' . wp_json_encode( __( 'We’ll email you a link to choose your password.', 'mayosis-child' ) ) . ';'
		. 'var btn=f.querySelector(".msv-auth-btn");if(btn){f.insertBefore(note,btn);}'
		. '}());',
		'after'
	);
}

add_filter( 'render_block', 'caw_social_login_on_account_blocks', 10, 2 );
/**
 * Add the same social buttons under the EDD login and registration blocks.
 *
 * Appended after the rendered block rather than hooked into the form, so the
 * buttons sit outside the <form> — Nextend renders its own forms for some
 * providers, and nesting those would be invalid markup.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $block         Parsed block.
 */
function caw_social_login_on_account_blocks( $block_content, $block ) {
	if ( is_user_logged_in() || empty( $block['blockName'] ) ) {
		return $block_content;
	}

	$labels = array(
		'edd/login'    => 'login',
		'edd/register' => 'register',
	);
	if ( ! isset( $labels[ $block['blockName'] ] ) ) {
		return $block_content;
	}

	$buttons = caw_social_login_buttons( array( 'labeltype' => $labels[ $block['blockName'] ] ) );
	if ( '' === $buttons ) {
		return $block_content;
	}

	return $block_content
		. '<div class="caw-social-divider"><span>' . esc_html__( 'or continue with', 'mayosis-child' ) . '</span></div>'
		. $buttons;
}

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

/*
 * Force our custom front-page.php for the static front page.
 * Elementor's page-templates module hijacks the front page via template_include
 * at priority 11 (returns header-footer.php), overriding WP's front-page.php.
 * Re-claim it at priority 100 so our bespoke homepage renders.
 */
add_filter( 'template_include', 'caw_force_front_page_template', 100 );
function caw_force_front_page_template( $template ) {
    if ( is_front_page() && ! is_home() ) {
        $custom = get_stylesheet_directory() . '/front-page.php';
        if ( file_exists( $custom ) ) {
            return $custom;
        }
    }
    return $template;
}

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

// Load the official TrustPilot TrustBox bootstrap on single product pages.
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

/* ---- Official TrustPilot TrustBox (Review Collector) --------------------
   Exact embed generated from the TrustPilot Business panel (token e0768b23…). */
function caw_trustpilot_widget() {
    ?>
    <!-- TrustBox widget - Review Collector -->
    <div class="trustpilot-widget" data-locale="en-US"
         data-template-id="56278e9abfbbba0bdcd568bc"
         data-businessunit-id="64ff3c8ffe3677ea98269255"
         data-style-height="52px" data-style-width="100%"
         data-token="e0768b23-39b9-4196-b8dc-b633497d7ee4">
        <a href="https://www.trustpilot.com/review/cryptoawaz.com" target="_blank" rel="noopener">Trustpilot</a>
    </div>
    <!-- End TrustBox widget -->
    <?php
}

/* ---- Keep the TrustBox readable in night mode --------------------------- */
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

/* ---- Sales-count badge (social proof near the title) -------------------- */
function caw_sales_badge_html( $id ) {
    if ( ! function_exists( 'edd_get_download_sales_stats' ) ) {
        return '';
    }
    $sales = (int) edd_get_download_sales_stats( $id );
    if ( $sales <= 0 ) {
        return '';
    }
    return '<span class="caw-sales-badge"><i class="fa fa-fire" aria-hidden="true"></i> '
        . esc_html( number_format( $sales ) ) . ' ' . esc_html__( 'sold', 'mayosis' ) . '</span>';
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

        // Which plan/dur combo is EDD's "Set as default" price? Used to seed
        // the initial selection (falls back to the first of each axis).
        $default_plan = isset( $plans[0] ) ? $plans[0] : '';
        $default_dur  = isset( $durs[0] ) ? $durs[0] : '';
        foreach ( $map as $key => $pid ) {
            if ( (int) $pid === (int) $model['default_pid'] ) {
                $kp           = explode( '|||', $key );
                $default_plan = $kp[0];
                $default_dur  = isset( $kp[1] ) ? $kp[1] : '';
                break;
            }
        }

        $model['two_axis']     = true;
        $model['plans']        = $plans;
        $model['durs']         = $durs;
        $model['map']          = $map;
        $model['default_plan'] = $default_plan;
        $model['default_dur']  = $default_dur;
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

/* ---- Vendor details (FES) for the "Vendor Details" tab ------------------- */
function caw_vendor_html( $id ) {
    $author_id = (int) get_post_field( 'post_author', $id );
    if ( ! $author_id ) {
        return '';
    }

    // Only treat the author as a "vendor" when FES says so (skip admin-authored products).
    $is_vendor = function_exists( 'EDD_FES' ) && isset( EDD_FES()->vendors )
        && method_exists( EDD_FES()->vendors, 'user_is_vendor' )
        && EDD_FES()->vendors->user_is_vendor( $author_id );
    if ( ! $is_vendor ) {
        return '';
    }

    $name = get_the_author_meta( 'display_name', $author_id );
    if ( '' === trim( (string) $name ) ) {
        $name = get_the_author_meta( 'user_nicename', $author_id );
    }
    $avatar = get_avatar( $author_id, 72, '', $name, array( 'class' => 'caw-vendor-avatar' ) );
    $bio    = get_the_author_meta( 'description', $author_id );

    $reg   = get_the_author_meta( 'user_registered', $author_id );
    $year  = $reg ? date_i18n( 'Y', strtotime( $reg ) ) : '';
    $count = (int) count_user_posts( $author_id, 'download', true );

    $store_url = '';
    if ( isset( EDD_FES()->vendors ) && method_exists( EDD_FES()->vendors, 'get_vendor_store_url' ) ) {
        $store_url = EDD_FES()->vendors->get_vendor_store_url( $author_id );
    }
    if ( ! $store_url ) {
        $store_url = get_author_posts_url( $author_id );
    }

    ob_start();
    ?>
    <div class="caw-vendor">
        <div class="caw-vendor-head">
            <?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <div class="caw-vendor-meta">
                <div class="caw-vendor-name">
                    <?php echo esc_html( $name ); ?>
                    <span class="caw-vendor-verified" title="<?php esc_attr_e( 'Verified vendor', 'mayosis' ); ?>">&#10004;</span>
                </div>
                <div class="caw-vendor-sub">
                    <?php if ( $year ) : ?><span><?php printf( esc_html__( 'Member since %s', 'mayosis' ), esc_html( $year ) ); ?></span><?php endif; ?>
                    <?php if ( $count ) : ?><span><?php printf( esc_html( _n( '%d product', '%d products', $count, 'mayosis' ) ), $count ); ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ( $bio ) : ?>
            <p class="caw-vendor-bio"><?php echo esc_html( $bio ); ?></p>
        <?php endif; ?>

        <div class="caw-vendor-actions">
            <button type="button" class="caw-vendor-msgbtn"><?php esc_html_e( 'Message Vendor', 'mayosis' ); ?></button>
            <?php if ( $store_url ) : ?>
                <a class="caw-vendor-store" href="<?php echo esc_url( $store_url ); ?>"><?php esc_html_e( 'Visit Store', 'mayosis' ); ?> &rarr;</a>
            <?php endif; ?>
        </div>

        <div class="caw-vendor-contact" hidden>
            <?php echo do_shortcode( '[fes_vendor_contact_form id="' . $author_id . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        </div>
    </div>
    <?php
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
    $vendor_html = caw_vendor_html( $id );
    if ( '' !== trim( $vendor_html ) ) {
        $tabs[] = array( 'title' => __( 'Vendor Details', 'mayosis' ), 'html' => $vendor_html, 'key' => 'vendor', 'meta' => true );
    }
    $info_html = caw_product_info_html( $id );
    if ( '' !== $fes_html ) {
        $info_html .= '<div class="caw-fes-fields">' . $fes_html . '</div>';
    }
    $tabs[] = array( 'title' => __( 'Product Information', 'mayosis' ), 'html' => $info_html, 'info' => true, 'meta' => true );

    return $tabs;
}

/* ---- Related products — SAME category as the current product ------------ */
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
                'operator' => 'IN',
            ),
        );
    }
    $q = new WP_Query( $args );
    // Fallback: if this category has too few other products, fill with any others.
    if ( ! $q->have_posts() ) {
        wp_reset_postdata();
        unset( $args['tax_query'] );
        $q = new WP_Query( $args );
    }
    return $q;
}

/* ---- Stock / availability via the EDD Purchase Limit extension -----------
   Returns structured stock data for the custom single-product template, or
   null when the EDD Purchase Limit plugin isn't active. Limit semantics from
   the plugin: 0/blank = unlimited (untracked), -1 = manually sold out, >0 =
   capped. Counts are site-wide (matches the plugin's default scope). ------- */
function caw_stock_info( $id ) {
    if ( ! function_exists( 'edd_pl_get_file_purchase_limit' ) || ! function_exists( 'edd_pl_get_file_purchases' ) ) {
        return null;
    }

    // Build a per-item stock record from a raw limit + sold count.
    $make = function ( $limit, $sold ) {
        $limit = is_numeric( $limit ) ? (int) $limit : 0;
        $sold  = (int) $sold;
        if ( 0 === $limit ) {
            return array( 'tracked' => false ); // unlimited
        }
        if ( -1 === $limit ) {
            return array( 'tracked' => true, 'limit' => -1, 'sold' => $sold, 'remaining' => 0, 'soldOut' => true );
        }
        $remaining = max( 0, $limit - $sold );
        return array(
            'tracked'   => true,
            'limit'     => $limit,
            'sold'      => $sold,
            'remaining' => $remaining,
            'soldOut'   => $remaining <= 0,
        );
    };

    if ( function_exists( 'edd_has_variable_prices' ) && edd_has_variable_prices( $id ) ) {
        $prices     = edd_get_variable_prices( $id );
        $options    = array();
        $any_track  = false;
        $all_out    = true;
        if ( $prices ) {
            foreach ( $prices as $pid => $p ) {
                $info = $make(
                    edd_pl_get_file_purchase_limit( $id, null, $pid ),
                    edd_pl_get_file_purchases( $id, $pid )
                );
                $options[ (int) $pid ] = $info;
                if ( ! empty( $info['tracked'] ) ) {
                    $any_track = true;
                    if ( empty( $info['soldOut'] ) ) {
                        $all_out = false;
                    }
                } else {
                    $all_out = false; // an untracked option is always buyable
                }
            }
        }
        return array(
            'variable'   => true,
            'tracked'    => $any_track,
            'options'    => $options,
            'allSoldOut' => $any_track ? $all_out : false,
        );
    }

    $count = function_exists( 'edd_pl_get_download_purchase_count' )
        ? edd_pl_get_download_purchase_count( $id )
        : edd_pl_get_file_purchases( $id );
    $info             = $make( edd_pl_get_file_purchase_limit( $id ), $count );
    $info['variable'] = false;
    return $info;
}

/* ---- Is a product fully sold out (every tier / the single price)? ---------
   EDD core has no stock concept, and the Purchase Limit extension exposes no
   query filter — sold-out is computed, so we filter listings in PHP. Returns
   false when stock isn't tracked (plugin off, or unlimited = always buyable). */
function caw_is_fully_sold_out( $id ) {
    if ( ! function_exists( 'caw_stock_info' ) ) {
        return false;
    }
    $s = caw_stock_info( $id );
    if ( ! $s ) {
        return false;
    }
    if ( ! empty( $s['variable'] ) ) {
        return ! empty( $s['allSoldOut'] );
    }
    return ! empty( $s['tracked'] ) && ! empty( $s['soldOut'] );
}

/* =============================================================================
   NEUTRALISE ORPHANED [cryptocurrency_widget] SHORTCODES

   Premium Cryptocurrency Widgets was deactivated: its CryptoCompare key is on
   the free tier (25 calls/month) and has been exhausted for a long time, so
   every widget fetched an error and rendered empty while still shipping 514 KB
   (288 KB app.js + 225 KB style.css) on EVERY page. The homepage ticker it once
   powered is now our own CoinGecko one in front-page.php.

   WordPress prints an unregistered shortcode as literal text, so the leftover
   calls in /home/ (8), /crypto-news/ (1) and /vendor-feedback/ (1) would show
   raw "[cryptocurrency_widget type=... ]" to visitors. Registering a no-op
   keeps those pages clean until the content itself is cleaned up.

   Guarded on function_exists so reactivating the plugin silently takes over
   again — WP keeps whichever handler registered first, and the plugin registers
   on its own 'init', so this only claims the tag when the plugin is absent.
   ============================================================================= */

add_action( 'init', 'caw_stub_dead_crypto_widget_shortcode', 99 );
function caw_stub_dead_crypto_widget_shortcode() {
    if ( shortcode_exists( 'cryptocurrency_widget' ) ) {
        return; // Plugin is active and owns the tag.
    }
    add_shortcode( 'cryptocurrency_widget', '__return_empty_string' );
}

/* =============================================================================
   PERF STAGE 1 — STOP SHIPPING THE CHILD STYLESHEET TWICE

   The parent does `wp_enqueue_style( 'mayosis-style', get_stylesheet_uri() )`
   (library/mayosis-enqueue.php:29). In a child theme get_stylesheet_uri()
   resolves to the CHILD's style.css — so the parent already loads our file, and
   mayosis_child_enqueue_styles() then loads the identical file a second time
   under its own handle. Prod shipped both: ?ver=7.0.4 and ?ver=<filemtime>.

   We keep OUR handle (it carries the filemtime cache-bust) and blank the
   parent's src instead of deregistering it, because other handles may declare
   'mayosis-style' as a dependency — an unregistered handle would silently drop
   whatever depends on it. A registered handle with an empty src prints no
   <link> but still satisfies dependency resolution, and any wp_add_inline_style
   attached to it still emits.

   Cascade is unaffected: both copies are byte-identical and sat adjacent in the
   document (positions 15 and 16, immediately before bootstrap/essential/main).
   ============================================================================= */

add_action( 'wp_enqueue_scripts', 'caw_drop_duplicate_child_stylesheet', 20 );
function caw_drop_duplicate_child_stylesheet() {
    $styles = wp_styles();
    if ( isset( $styles->registered['mayosis-style'] ) ) {
        $styles->registered['mayosis-style']->src = '';
    }
}

/* =============================================================================
   PERF STAGE 1 — DROP THE EMOJI POLYFILL ON THE FRONT END

   WordPress ships a detection script plus a stylesheet to rewrite emoji as
   Twemoji <img> tags, for browsers that have not needed it in years. Front end
   only — the admin keeps its own copy so the editor is untouched.

   Side benefit: the vendor "verified" tick stops rendering as a washed-out
   Twemoji image and falls back to the native glyph.
   ============================================================================= */

/* =============================================================================
   PERF STAGE 2 — STOP MAILPOET SHIPPING 62 GOOGLE FONT FAMILIES

   MailPoet's CustomFonts::enqueueStyle() (lib/Form/Util/CustomFonts.php:97)
   enqueues its ENTIRE font-picker list on every front-end request — 62 families
   split across three fonts.googleapis.com stylesheets, purely so the form
   editor's dropdown can preview them. Three render-blocking cross-origin
   requests, plus the DNS/TLS handshake to Google, on every page view.

   None of our forms selects a custom font (no `fontFamily` is set on any of the
   three rows in wp_mailpoet_forms), so nothing renders differently without them.

   We use the filter rather than the Settings toggle: the underlying setting is
   `3rd_party_libs.enabled`, which ALSO gates MailPoet's admin DocsBot widget and
   the email editor's third-party libraries. The filter touches only the fonts.

   If a form ever does need a Google font, return true here for that request —
   or better, self-host just the family it uses.
   ============================================================================= */

add_filter( 'mailpoet_display_custom_fonts', '__return_false' );

/* =============================================================================
   LEGACY URL REDIRECTS

   The site carried three privacy pages, all three linked from menus and all
   three indexed:
     /privacy-policy/        (ID 3)  WordPress boilerplate, but the page WP has
                                     registered as wp_page_for_privacy_policy
     /privacy-policy-2/      (ID 66) the real, bespoke Crypto Awaz policy
     /security-information/  (ID 38) the boilerplate again, mis-titled

   Resolution: page 66's content was moved into page 3, so /privacy-policy/
   keeps the clean slug and WP's designated-page setting while carrying the real
   policy. 66 and 38 are retired and folded in here.

   Gated on is_404() so it is self-sequencing: while the old pages still exist
   they render normally, and the redirect only takes over once they are gone.
   That also means this cannot mask a live page by accident.

   WP's own redirect_guess_404_permalink() does not cover these — it matches on
   `post_name LIKE '<request>%'`, and neither "privacy-policy-2" nor
   "security-information" is a prefix of "privacy-policy".
   ============================================================================= */

add_action( 'template_redirect', 'caw_legacy_url_redirects' );
function caw_legacy_url_redirects() {
    if ( ! is_404() ) {
        return;
    }

    $map = apply_filters(
        'caw_legacy_redirect_map',
        array(
            'privacy-policy-2'     => '/privacy-policy/',
            'security-information' => '/privacy-policy/',
        )
    );

    $path = trim( wp_parse_url( add_query_arg( array() ), PHP_URL_PATH ), '/' );
    if ( isset( $map[ $path ] ) ) {
        wp_safe_redirect( home_url( $map[ $path ] ), 301 );
        exit;
    }
}

/* =============================================================================
   PERF STAGE 4 — DROP PLUGIN/THEME ASSETS THE FRONT PAGE NEVER USES

   front-page.php is entirely bespoke markup, so most of what the parent theme
   and the plugin stack enqueue site-wide has nothing to act on here. Every
   entry below was checked against the RENDERED DOM (with <style>, <script>,
   <link> and <meta> stripped, since the selectors otherwise match inside CSS)
   and confirmed to have zero nodes on the front page.

   Scoped to the front page on purpose. Swiper and Plyr drive the product
   gallery and media previews, and EDD Reviews powers the Reviews tab, so
   caw-single-download.php still needs all three.

   NOT dequeued, despite looking unused: MailPoet. The home page carries a real
   popup subscribe form (`mailpoet_form mailpoet_form_form mailpoet_form_popup`,
   with the overlay and slide-up animation), so its 39 KB is load-bearing.

   dashicons rides along with edd-reviews, which declares it as a dependency
   (edd-reviews/src/AssetLoader.php). Dequeuing dashicons alone does nothing —
   WP's dependency resolver re-adds it — so the two have to go together. Our own
   star ratings do not need either: caw_product_rating() reads the average via
   edd_reviews()->average_rating() and draws Font Awesome stars.
   ============================================================================= */

add_action( 'wp_enqueue_scripts', 'caw_dequeue_front_page_extras', 999 );
function caw_dequeue_front_page_extras() {
    if ( is_admin() || ! is_front_page() || is_home() ) {
        return;
    }

    $styles = apply_filters(
        'caw_front_page_dequeue_styles',
        array(
            'bbp-default',                  // no forum on the front page
            'contact-form-7',               // no CF7 form
            'plyr',                         // no <audio>/<video>
            'swiperjs',                     // no swiper container
            'beerslidercss',                // no before/after slider
            'edd-sale-counter-advanced',    // no countdown
            'edd-user-profiles',
            'edd-recurring',
            'edd-reviews',                  // stars here are our own Font Awesome markup
            'dashicons',                    // only queued as edd-reviews' dependency
            'aioseo/css/src/vue/standalone/blocks/table-of-contents/global.scss',
        )
    );

    $scripts = apply_filters(
        'caw_front_page_dequeue_scripts',
        array(
            'contact-form-7',
            'swv',                          // CF7's form validation runtime
            'plyr',
            'swiperjs',
            'beerslider',
            'flipclock',                    // sale-counter dependency
            'edd-sale-counter-advanced',
            'edd-user-profiles',
        )
    );

    foreach ( $styles as $handle ) {
        wp_dequeue_style( $handle );
    }
    foreach ( $scripts as $handle ) {
        wp_dequeue_script( $handle );
    }
}

/* =============================================================================
   PERF STAGE 3 — DROP ELEMENTOR ON PAGES OUR OWN TEMPLATES RENDER

   The front page and every single download are rendered by front-page.php and
   caw-single-download.php, so no Elementor widget ever runs on them. Elementor
   still enqueues its full front-end bundle regardless, because the underlying
   post carries _elementor_data: on the home page that is 13 stylesheets plus
   three scripts — frontend.min.js, frontend-modules.min.js,
   webpack.runtime.min.js, the eicons and Font Awesome sets, the Kit CSS
   (post-154), the page CSS (post-6019) and five locally-hosted Google fonts.

   Verified before writing this: the home page and a product page contain ZERO
   Elementor DOM nodes once <style>/<script> blocks are stripped (the selectors
   only ever appear inside CSS). /about-us/ has 60 and /contact/ has 21 — both
   are genuinely Elementor-built, which is exactly why this is scoped rather
   than global. Elementor Pro is not installed, so there are no theme-builder
   header/footer templates that could be caught out by this.

   Matching on src rather than a handle list: Elementor renames and splits
   handles between versions (widget-heading / widget-divider / widget-icon-box
   are recent per-widget additions), whereas the path is stable.

   The body keeps its elementor-page / elementor-kit-154 classes either way —
   our reader-column CSS keys off those, so the cascade is unaffected.
   ============================================================================= */

/**
 * Whether the current request is rendered by one of our own templates, and so
 * cannot contain Elementor output.
 */
function caw_page_renders_without_elementor() {
    $ours = ( is_front_page() && ! is_home() ) || is_singular( 'download' );

    return (bool) apply_filters( 'caw_page_renders_without_elementor', $ours );
}

/* Two passes are needed. Most of the bundle is queued on wp_enqueue_scripts, but
   Elementor adds the Kit's locally-hosted Google fonts and its Font Awesome sets
   later, so a single early pass leaves eight files behind. wp_print_styles fires
   immediately before WP_Styles::do_items(), which is the last safe moment. */
add_action( 'wp_enqueue_scripts', 'caw_dequeue_elementor_where_unused', 999 );
add_action( 'wp_print_styles', 'caw_dequeue_elementor_where_unused', 99 );
function caw_dequeue_elementor_where_unused() {
    if ( is_admin() || ! caw_page_renders_without_elementor() ) {
        return;
    }

    /* mayosis-core's before/after widget lives under its own elementor/ dir and
       is likewise useless without an Elementor widget to drive it.

       Elementor's Font Awesome copy goes too: the parent theme already ships a
       complete FA5 at mayosis/css/all.min.css — both the Brands and Free
       families, every glyph front-page.php uses, and the webfonts/ directory it
       points at is present and served. Keeping both is a pure duplicate. */
    $pattern = '#/(plugins/elementor|uploads/elementor|plugins/mayosis-core/public/elementor)/#';

    foreach ( array( wp_styles(), wp_scripts() ) as $deps ) {
        /* Collect first — dequeue() mutates the queue we would be iterating. */
        $drop = array();
        foreach ( $deps->queue as $handle ) {
            $src = isset( $deps->registered[ $handle ]->src ) ? $deps->registered[ $handle ]->src : '';
            if ( $src && preg_match( $pattern, $src ) ) {
                $drop[] = $handle;
            }
        }
        foreach ( $drop as $handle ) {
            $deps->dequeue( $handle );
        }
    }
}

add_action( 'init', 'caw_disable_frontend_emoji' );
function caw_disable_frontend_emoji() {
    if ( is_admin() ) {
        return;
    }

    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );

    /* Drop the preconnect WP emits for the emoji CDN. */
    add_filter(
        'wp_resource_hints',
        function ( $urls, $relation ) {
            if ( 'dns-prefetch' !== $relation ) {
                return $urls;
            }
            return array_filter(
                $urls,
                function ( $url ) {
                    return false === strpos( is_array( $url ) ? ( $url['href'] ?? '' ) : $url, 's.w.org' );
                }
            );
        },
        10,
        2
    );
}
