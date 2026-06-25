<?php
/**
 * CAW custom single-download template.
 * Routed via the template_include filter in functions.php for ALL products,
 * giving every product the same redesigned layout.
 *
 * @package mayosis-child
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>

<main id="main" class="site-main caw-product-main">
<?php
while ( have_posts() ) :
    the_post();
    $id    = get_the_ID();
    $model = caw_get_price_model( $id );
    $stock = function_exists( 'caw_stock_info' ) ? caw_stock_info( $id ) : null;

    // Secondary / custom button data.
    $custom_url   = get_post_meta( $id, 'custom-button-url', true );
    $custom_text  = get_post_meta( $id, 'custom-button-title', true );
    $demo_link    = get_post_meta( $id, 'demo_link', true );
    $preview_text = get_theme_mod( 'live_preview_text', 'Live Preview' );

    // Left column media.
    $gallery_ids = get_post_meta( $id, 'vdw_gallery_id', true );
    ?>

    <div class="caw-product container">

        <!-- TOP STRIP: dynamic breadcrumb + TrustPilot -->
        <div class="caw-topstrip">
            <div class="caw-crumb">
                <?php if ( function_exists( 'dm_breadcrumbs' ) ) { dm_breadcrumbs(); } ?>
            </div>
            <div class="caw-tp"><?php caw_trustpilot_widget(); ?></div>
        </div>

        <!-- ABOVE THE FOLD -->
        <div class="caw-fold">

            <!-- LEFT: media / gallery -->
            <div class="caw-gallery">
                <?php
                if ( has_post_format( 'video' ) || has_post_format( 'audio' ) ) {
                    get_template_part( 'includes/edd_media' );
                }
                if ( $gallery_ids ) {
                    get_template_part( 'includes/product-gallery-prime' );
                } elseif ( has_post_thumbnail() ) {
                    the_post_thumbnail( 'large', array( 'class' => 'caw-feat-img' ) );
                }
                ?>
            </div>

            <!-- RIGHT: buy box -->
            <div class="caw-buybox"
                 data-default-pid="<?php echo isset( $model['default_pid'] ) ? (int) $model['default_pid'] : -1; ?>">

                <div class="caw-chips">
                    <?php
                    $cats = get_the_terms( $id, 'download_category' );
                    if ( $cats && ! is_wp_error( $cats ) ) {
                        foreach ( array_slice( $cats, 0, 2 ) as $c ) {
                            echo '<span class="caw-chip">' . esc_html( $c->name ) . '</span>';
                        }
                    }
                    ?>
                </div>

                <h1 class="caw-title"><?php the_title(); ?></h1>

                <div class="caw-meta-row">
                    <?php echo caw_review_stars_html( $id ); // phpcs:ignore ?>
                    <?php echo caw_sales_badge_html( $id ); // phpcs:ignore ?>
                </div>

                <?php if ( ! empty( $model['variable'] ) ) : ?>

                    <?php if ( ! empty( $model['two_axis'] ) ) : ?>
                        <!-- Plan axis -->
                        <div class="caw-selblock">
                            <div class="caw-sellabel"><?php echo esc_html( $model['plan_label'] ); ?></div>
                            <div class="caw-plans">
                                <?php foreach ( $model['plans'] as $i => $plan ) : ?>
                                    <div class="caw-plan<?php echo 0 === $i ? ' caw-active' : ''; ?>" data-plan="<?php echo esc_attr( $plan ); ?>">
                                        <div class="caw-pname"><?php echo esc_html( $plan ); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Duration axis (only if more than one value) -->
                        <?php if ( count( $model['durs'] ) > 1 ) : ?>
                            <div class="caw-selblock">
                                <div class="caw-sellabel"><?php echo esc_html( $model['dur_label'] ); ?></div>
                                <div class="caw-durs">
                                    <?php foreach ( $model['durs'] as $d ) : ?>
                                        <div class="caw-dur" data-dur="<?php echo esc_attr( $d ); ?>"><?php echo esc_html( $d ); ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="caw-durs caw-durs-hidden">
                                <div class="caw-dur caw-active" data-dur="<?php echo esc_attr( $model['durs'][0] ); ?>"></div>
                            </div>
                        <?php endif; ?>

                    <?php else : ?>
                        <!-- Single-axis option list (fallback) -->
                        <div class="caw-selblock">
                            <div class="caw-sellabel"><?php esc_html_e( 'Choose an option', 'mayosis' ); ?></div>
                            <div class="caw-opts">
                                <?php foreach ( $model['options'] as $i => $o ) :
                                    $os    = ( $stock && ! empty( $stock['variable'] ) && isset( $stock['options'][ $o['pid'] ] ) ) ? $stock['options'][ $o['pid'] ] : null;
                                    $o_out = $os && ! empty( $os['soldOut'] );
                                    ?>
                                    <div class="caw-opt<?php echo 0 === $i ? ' caw-active' : ''; ?><?php echo $o_out ? ' caw-soldout' : ''; ?>"
                                         data-pid="<?php echo (int) $o['pid']; ?>"<?php echo $o_out ? ' data-soldout="1"' : ''; ?>>
                                        <span class="caw-optname"><?php echo esc_html( $o['name'] ); ?></span>
                                        <span class="caw-optprice"><?php echo $o_out ? esc_html__( 'Sold out', 'mayosis' ) : esc_html( $model['pidPrice'][ $o['pid'] ] ); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="caw-pricerow">
                        <span class="caw-price"></span>
                        <span class="caw-per"></span>
                    </div>

                <?php else : ?>
                    <div class="caw-pricerow">
                        <span class="caw-price"><?php echo edd_price( $id, false ); // phpcs:ignore ?></span>
                    </div>
                <?php endif; ?>

                <?php
                // ---- Stock / availability badge (EDD Purchase Limit extension) ----
                $cta_soldout = false;
                $stock_badge = '';
                if ( $stock ) {
                    if ( empty( $stock['variable'] ) ) {
                        if ( ! empty( $stock['tracked'] ) ) {
                            if ( ! empty( $stock['soldOut'] ) ) {
                                $cta_soldout = true;
                                $stock_badge = '<span class="caw-stock caw-stock-out"><i class="fas fa-circle"></i> ' . esc_html__( 'Out of Stock', 'mayosis' ) . '</span>';
                            } elseif ( $stock['remaining'] <= 5 ) {
                                $stock_badge = '<span class="caw-stock caw-stock-low"><i class="fas fa-circle"></i> ' . sprintf( esc_html__( 'Only %d left', 'mayosis' ), $stock['remaining'] ) . '</span>';
                            } else {
                                $stock_badge = '<span class="caw-stock caw-stock-in"><i class="fas fa-circle"></i> ' . esc_html__( 'In Stock', 'mayosis' ) . '</span>';
                            }
                        }
                    } else {
                        // Variable: the badge is filled by JS for the selected option; seed hidden.
                        if ( ! empty( $stock['allSoldOut'] ) ) { $cta_soldout = true; }
                        $stock_badge = '<span class="caw-stock" style="display:none"></span>';
                    }
                }
                if ( $stock_badge ) {
                    echo '<div class="caw-stockrow">' . $stock_badge . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
                }
                ?>

                <!-- Native EDD purchase form (options hidden via CSS, submit triggered by our CTA) -->
                <div class="caw-edd-form">
                    <?php echo edd_get_purchase_link( array( 'download_id' => $id ) ); // phpcs:ignore ?>
                </div>

                <button type="button" class="caw-cta<?php echo $cta_soldout ? ' caw-soldout' : ''; ?>" id="caw-buy"<?php echo $cta_soldout ? ' disabled' : ''; ?>>
                    <span class="caw-cta-label"><?php echo $cta_soldout ? esc_html__( 'Out of Stock', 'mayosis' ) : esc_html__( 'Buy Now', 'mayosis' ); ?></span><span class="caw-cta-price"></span>
                </button>

                <?php if ( $custom_url ) : ?>
                    <a class="caw-cta2" href="<?php echo esc_url( $custom_url ); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html( $custom_text ? $custom_text : __( 'Purchase', 'mayosis' ) ); ?> &#8599;
                    </a>
                <?php elseif ( $demo_link ) : ?>
                    <a class="caw-cta2" href="<?php echo esc_url( $demo_link ); ?>" target="_blank" rel="noopener">
                        &#9654; <?php echo esc_html( $preview_text ); ?>
                    </a>
                <?php endif; ?>

                <div class="caw-trust">
                    <div class="caw-crypto">
                        <?php esc_html_e( 'Pay with', 'mayosis' ); ?>
                        <span class="caw-coins">
                            <span class="caw-coin caw-btc" title="Bitcoin">&#8383;</span>
                            <span class="caw-coin caw-eth" title="Ethereum">&#926;</span>
                            <span class="caw-coin caw-usdt" title="USDT">&#8366;</span>
                            <span class="caw-coin caw-bnb" title="BNB">B</span>
                        </span>
                        <?php esc_html_e( 'BTC, ETH, USDT & 50+ more', 'mayosis' ); ?>
                    </div>
                </div>
            </div><!-- /buybox -->
        </div><!-- /fold -->

        <!-- RELATED PRODUCTS (same category, above tabs) -->
        <?php
        $related = caw_related_products( $id, 4 );
        if ( $related->have_posts() ) :
            ?>
            <div class="caw-related">
                <div class="caw-related-head">
                    <span class="caw-cube">&#129513;</span> <?php esc_html_e( 'YOU MIGHT ALSO LIKE', 'mayosis' ); ?>
                    <a href="<?php echo esc_url( get_post_type_archive_link( 'download' ) ); ?>"><?php esc_html_e( 'Browse all products', 'mayosis' ); ?> &rarr;</a>
                </div>
                <div class="caw-relgrid">
                    <?php
                    while ( $related->have_posts() ) :
                        $related->the_post();
                        $rcat = get_the_terms( get_the_ID(), 'download_category' );
                        ?>
                        <a class="caw-relcard" href="<?php the_permalink(); ?>">
                            <div class="caw-relimg">
                                <?php if ( has_post_thumbnail() ) { the_post_thumbnail( 'medium' ); } ?>
                            </div>
                            <div class="caw-relbody">
                                <?php if ( $rcat && ! is_wp_error( $rcat ) ) : ?>
                                    <div class="caw-relcat"><?php echo esc_html( $rcat[0]->name ); ?></div>
                                <?php endif; ?>
                                <div class="caw-reltitle"><?php the_title(); ?></div>
                                <div class="caw-relprice"><?php echo edd_price( get_the_ID(), false ); // phpcs:ignore ?></div>
                            </div>
                        </a>
                        <?php
                    endwhile;
                    wp_reset_postdata();
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- TABS -->
        <?php $tabs = caw_build_tabs( $id ); ?>
        <?php if ( ! empty( $tabs ) ) : ?>
            <div class="caw-tabsection">
                <div class="caw-tabs" role="tablist">
                    <?php foreach ( $tabs as $i => $t ) :
                        $key   = isset( $t['key'] ) ? $t['key'] : 'tab' . $i;
                        $extra = ! empty( $t['info'] ) ? ' caw-info' : '';
                        ?>
                        <div class="caw-tab<?php echo 0 === $i ? ' caw-active' : ''; ?><?php echo esc_attr( $extra ); ?>"
                             id="caw-tabbtn-<?php echo esc_attr( $key ); ?>"
                             data-target="caw-panel-<?php echo esc_attr( $key ); ?>">
                            <?php echo esc_html( $t['title'] ); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php foreach ( $tabs as $i => $t ) :
                    $key = isset( $t['key'] ) ? $t['key'] : 'tab' . $i;
                    ?>
                    <div class="caw-panel<?php echo 0 === $i ? ' caw-active' : ''; ?>"
                         id="caw-panel-<?php echo esc_attr( $key ); ?>">
                        <?php
                        // Trusted output: the_content() (already filtered) + EDD reviews template.
                        // Do NOT run through wp_kses_post() — it strips <style>/<script> tags but
                        // leaves their inner CSS/JS as visible text (the NextSocial login form).
                        echo $t['html']; // phpcs:ignore WordPress.Security.EscapeOutput
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div><!-- /caw-product -->

    <?php
    // Pricing model payload for the live selector.
    $payload = array(
        'variable'   => ! empty( $model['variable'] ),
        'twoAxis'    => ! empty( $model['two_axis'] ),
        'defaultPid' => isset( $model['default_pid'] ) ? (int) $model['default_pid'] : -1,
        'map'        => isset( $model['map'] ) ? $model['map'] : new stdClass(),
        'pidPrice'   => isset( $model['pidPrice'] ) ? $model['pidPrice'] : new stdClass(),
        'buyNow'     => __( 'Buy Now', 'mayosis' ),
        'stock'      => ( $stock && ! empty( $stock['variable'] ) && ! empty( $stock['options'] ) ) ? $stock['options'] : new stdClass(),
        'outLabel'    => __( 'Out of Stock', 'mayosis' ),
        'inLabel'     => __( 'In Stock', 'mayosis' ),
        'lowLabel'    => __( 'Only %d left', 'mayosis' ),
        'addingLabel' => __( 'Adding to cart…', 'mayosis' ),
    );
    ?>
    <script>
    (function () {
        var box = document.querySelector('.caw-buybox');
        if (!box) return;
        var DATA = <?php echo wp_json_encode( $payload ); ?>;
        var form = box.querySelector('.caw-edd-form form') || box.querySelector('.caw-edd-form');
        var priceEl = box.querySelector('.caw-price');
        var perEl   = box.querySelector('.caw-per');
        var ctaPrice = box.querySelector('.caw-cta-price');
        var ctaLabel = box.querySelector('.caw-cta-label');
        var stockBadge = box.querySelector('.caw-stock');
        var cta = box.querySelector('#caw-buy');

        function nativeInputs() {
            return form ? form.querySelectorAll('input[name^="edd_options[price_id]"]') : [];
        }
        function stockFor(pid) { return (DATA.stock && DATA.stock[pid]) ? DATA.stock[pid] : null; }
        // Update the stock badge + CTA state for the selected price option.
        function applyStock(pid, pstr) {
            var s = stockFor(pid), soldOut = false, text = '', cls = '';
            if (s && s.tracked) {
                if (s.soldOut)            { soldOut = true; text = DATA.outLabel; cls = 'caw-stock-out'; }
                else if (s.remaining <= 5){ text = DATA.lowLabel.replace('%d', s.remaining); cls = 'caw-stock-low'; }
                else                      { text = DATA.inLabel; cls = 'caw-stock-in'; }
            }
            if (stockBadge) {
                if (text) { stockBadge.style.display = ''; stockBadge.className = 'caw-stock ' + cls; stockBadge.innerHTML = '<i class="fas fa-circle"></i> ' + text; }
                else      { stockBadge.style.display = 'none'; stockBadge.textContent = ''; }
            }
            if (cta) {
                cta.classList.toggle('caw-soldout', soldOut);
                cta.disabled = soldOut;
                if (ctaLabel) ctaLabel.textContent = soldOut ? DATA.outLabel : DATA.buyNow;
                if (ctaPrice) ctaPrice.textContent = (!soldOut && pstr) ? ' — ' + pstr : '';
            }
        }
        function setPid(pid) {
            var inputs = nativeInputs(), sel = null;
            inputs.forEach(function (inp) {
                var on = parseInt(inp.value, 10) === pid;
                inp.checked = on;
                if (on) sel = inp;
            });
            if (sel) sel.dispatchEvent(new Event('change', { bubbles: true }));
            var pstr = DATA.pidPrice[pid] || '';
            if (priceEl) priceEl.textContent = pstr;
            applyStock(pid, pstr);
        }

        // --- Two-axis selector ---
        var state = { plan: null, dur: null };
        function curPid() {
            return DATA.map[state.plan + '|||' + state.dur];
        }
        // A plan/duration combo is selectable only if it exists AND isn't sold out.
        function comboAvail(plan, dur) {
            var pid = DATA.map[plan + '|||' + dur];
            if (pid === undefined) return false;
            var s = stockFor(pid);
            return !(s && s.tracked && s.soldOut);
        }
        function refreshTwoAxis() {
            // grey out durations unavailable (missing or sold out) for the active plan
            box.querySelectorAll('.caw-dur').forEach(function (d) {
                var dv = d.getAttribute('data-dur');
                d.classList.toggle('caw-disabled', !comboAvail(state.plan, dv));
            });
            // if current duration invalid, pick first available
            if (!comboAvail(state.plan, state.dur)) {
                var firstAvail = null;
                box.querySelectorAll('.caw-dur').forEach(function (d) {
                    if (!firstAvail && !d.classList.contains('caw-disabled')) firstAvail = d;
                });
                if (firstAvail) {
                    state.dur = firstAvail.getAttribute('data-dur');
                    box.querySelectorAll('.caw-dur').forEach(function (d) {
                        d.classList.toggle('caw-active', d === firstAvail);
                    });
                }
            }
            var pid = curPid();
            if (pid !== undefined) {
                setPid(pid);
                if (perEl) perEl.textContent = state.dur ? '/ ' + state.dur : '';
            }
        }

        if (DATA.variable && DATA.twoAxis) {
            var plan0 = box.querySelector('.caw-plan.caw-active') || box.querySelector('.caw-plan');
            var dur0  = box.querySelector('.caw-dur.caw-active')  || box.querySelector('.caw-dur');
            if (plan0) { plan0.classList.add('caw-active'); state.plan = plan0.getAttribute('data-plan'); }
            if (dur0)  { dur0.classList.add('caw-active');  state.dur  = dur0.getAttribute('data-dur'); }

            box.querySelectorAll('.caw-plan').forEach(function (el) {
                el.addEventListener('click', function () {
                    box.querySelectorAll('.caw-plan').forEach(function (p) { p.classList.toggle('caw-active', p === el); });
                    state.plan = el.getAttribute('data-plan');
                    refreshTwoAxis();
                });
            });
            box.querySelectorAll('.caw-dur').forEach(function (el) {
                el.addEventListener('click', function () {
                    if (el.classList.contains('caw-disabled')) return;
                    box.querySelectorAll('.caw-dur').forEach(function (d) { d.classList.toggle('caw-active', d === el); });
                    state.dur = el.getAttribute('data-dur');
                    refreshTwoAxis();
                });
            });
            refreshTwoAxis();

        } else if (DATA.variable) {
            // --- Single-axis option list ---
            box.querySelectorAll('.caw-opt').forEach(function (el) {
                el.addEventListener('click', function () {
                    if (el.getAttribute('data-soldout')) return; // can't pick a sold-out option
                    box.querySelectorAll('.caw-opt').forEach(function (o) { o.classList.toggle('caw-active', o === el); });
                    setPid(parseInt(el.getAttribute('data-pid'), 10));
                });
            });
            var opt0 = box.querySelector('.caw-opt.caw-active') || box.querySelector('.caw-opt');
            // if the default option is sold out, jump to the first available one
            if (opt0 && opt0.getAttribute('data-soldout')) {
                var avail = null;
                box.querySelectorAll('.caw-opt').forEach(function (o) { if (!avail && !o.getAttribute('data-soldout')) avail = o; });
                if (avail) {
                    box.querySelectorAll('.caw-opt').forEach(function (o) { o.classList.toggle('caw-active', o === avail); });
                    opt0 = avail;
                }
            }
            if (opt0) setPid(parseInt(opt0.getAttribute('data-pid'), 10));
        }

        // --- CTA triggers the native submit/add-to-cart, with a loading state ---
        // The add-to-cart is an AJAX round-trip; without feedback the button feels
        // dead (esp. on mobile, where users double-tap). Show a spinner + "Adding…"
        // immediately and revert when the slide-out cart opens (edd:cart-preview:opened)
        // or EDD's legacy edd_cart_item_added fires; a timeout is the safety net.
        function ctaLoading(on) {
            if (!cta) return;
            if (on) {
                cta.classList.add('caw-loading');
                cta.setAttribute('aria-busy', 'true');
                cta.dataset.loading = '1';
                if (!cta.querySelector('.caw-spinner')) {
                    var sp = document.createElement('span');
                    sp.className = 'caw-spinner';
                    sp.setAttribute('aria-hidden', 'true');
                    cta.insertBefore(sp, cta.firstChild);
                }
                if (ctaLabel) ctaLabel.textContent = DATA.addingLabel;
                if (ctaPrice) ctaPrice.style.display = 'none';
            } else {
                cta.classList.remove('caw-loading');
                cta.removeAttribute('aria-busy');
                delete cta.dataset.loading;
                var ex = cta.querySelector('.caw-spinner');
                if (ex) ex.parentNode.removeChild(ex);
                if (ctaLabel) ctaLabel.textContent = DATA.buyNow;
                if (ctaPrice) ctaPrice.style.display = '';
            }
        }
        if (cta) {
            cta.addEventListener('click', function () {
                if (cta.disabled || cta.dataset.loading) return; // sold out or already adding
                var btn = (form && (form.querySelector('.edd-add-to-cart') ||
                                    form.querySelector('input[type=submit]') ||
                                    form.querySelector('button[type=submit]') ||
                                    form.querySelector('.edd-submit')));
                if (!btn) return;
                ctaLoading(true);
                var done = false, revert = function () { if (done) return; done = true; ctaLoading(false); };
                document.addEventListener('edd:cart-preview:opened', revert, { once: true });
                if (window.jQuery) window.jQuery(document.body).one('edd_cart_item_added', revert);
                setTimeout(revert, 8000); // safety net if no cart event fires
                btn.click();
            });
        }

        // --- Tabs ---
        document.querySelectorAll('.caw-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                document.querySelectorAll('.caw-tab').forEach(function (t) { t.classList.remove('caw-active'); });
                document.querySelectorAll('.caw-panel').forEach(function (p) { p.classList.remove('caw-active'); });
                tab.classList.add('caw-active');
                var panel = document.getElementById(tab.getAttribute('data-target'));
                if (panel) panel.classList.add('caw-active');
            });
        });
        // rating link jumps to reviews tab
        var jump = box.querySelector('[data-jump="reviews"]');
        if (jump) {
            jump.addEventListener('click', function (e) {
                e.preventDefault();
                var rt = document.getElementById('caw-tabbtn-reviews');
                if (rt) { rt.click(); rt.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            });
        }
    })();
    </script>

    <?php
endwhile;
?>
</main>

<?php get_footer(); ?>
