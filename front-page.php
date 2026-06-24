<?php
/**
 * Front Page — Crypto Awaz custom home (child theme).
 * Bespoke, fully dynamic homepage. Replaces the old Elementor home.
 * Header/footer come from the parent theme; all content below is custom.
 *
 * @package mayosis-child
 */

get_header();

/* ---------------------------------------------------------------------------
 * Data
 * ------------------------------------------------------------------------- */
global $wpdb;

$cawh_total_downloads = (int) wp_count_posts( 'download' )->publish;
$cawh_total_sales     = (int) $wpdb->get_var( "SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_edd_download_sales'" );
$cawh_total_vendors   = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'download' AND post_status = 'publish'" );

/* Hero spotlight products: id => [icon, gradient, badge]. Pulls live title/price/link. */
$cawh_hero = array(
	8517 => array( 'icon' => 'fas fa-robot',         'grad' => '#d97757,#a8442a', 'badge' => 'New' ),
	0    => array(), // placeholder
);
$cawh_x      = get_page_by_path( 'buy-x-premium-with-crypto-gift', OBJECT, 'download' );
$cawh_x_id   = $cawh_x ? $cawh_x->ID : 0;
$cawh_hero_cards = array(
	array( 'id' => 8517, 'icon' => 'fas fa-robot',        'grad' => '#d97757,#a8442a', 'badge' => 'New',      'small' => false ),
	array( 'id' => $cawh_x_id, 'icon' => '',                   'grad' => '#1e293b,#0f1c33', 'badge' => 'Top',      'small' => false, 'glyph' => '𝕏' ),
	array( 'id' => 7809, 'icon' => 'fas fa-tree',         'grad' => '#16a34a,#0c6b2e', 'badge' => '🌱 Eco',   'small' => false ),
	array( 'id' => 6530, 'icon' => 'fas fa-shield-alt','grad' => '#334155,#1e293b', 'badge' => '',          'small' => true ),
);

/**
 * Render a product card (best-selling / newly-listed grids).
 */
if ( ! function_exists( 'cawhome_product_card' ) ) {
	function cawhome_product_card( $id, $flag = '' ) {
		$cats  = get_the_term_list( $id, 'download_category', '', ', ' );
		$cat   = '';
		$terms = get_the_terms( $id, 'download_category' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$cat = esc_html( $terms[0]->name );
		}
		$thumb = get_the_post_thumbnail_url( $id, 'medium_large' );
		$price = function_exists( 'edd_price' ) ? edd_price( $id, false ) : '';
		$stars = function_exists( 'caw_review_stars_html' ) ? caw_review_stars_html( $id ) : '';
		?>
		<a class="ch-prod" href="<?php echo esc_url( get_permalink( $id ) ); ?>">
			<div class="ch-prod-img"<?php if ( $thumb ) echo ' style="background-image:url(\'' . esc_url( $thumb ) . '\')"'; ?>>
				<?php if ( ! $thumb ) echo '<i class="fas fa-box"></i>'; ?>
				<?php if ( $cat ) echo '<span class="ch-prod-cat">' . $cat . '</span>'; ?>
				<?php echo $flag; // phpcs:ignore ?>
			</div>
			<div class="ch-prod-body">
				<h3><?php echo esc_html( get_the_title( $id ) ); ?></h3>
				<div class="ch-prod-meta">
					<span class="ch-prod-price"><?php echo $price; // phpcs:ignore ?></span>
					<?php if ( $stars ) echo '<span class="ch-stars">' . $stars . '</span>'; // phpcs:ignore ?>
				</div>
				<span class="ch-prod-buy">Buy Now</span>
			</div>
		</a>
		<?php
	}
}

/* Best selling */
$cawh_best = new WP_Query( array(
	'post_type'      => 'download',
	'posts_per_page' => 4,
	'meta_key'       => '_edd_download_sales',
	'orderby'        => 'meta_value_num',
	'order'          => 'DESC',
	'no_found_rows'  => true,
) );

/* Newly listed */
$cawh_new = new WP_Query( array(
	'post_type'      => 'download',
	'posts_per_page' => 4,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'no_found_rows'  => true,
) );

/* Categories with icons */
$cawh_cat_icons = array(
	'trading-signals'       => array( 'fas fa-chart-line',   '#1e73be,#155f9e' ),
	'hardware'              => array( 'fas fa-microchip',     '#7c3aed,#5b21b6' ),
	'digital-subscriptions' => array( 'fas fa-crown',         '#0ea5e9,#0369a1' ),
	'courses'               => array( 'fas fa-graduation-cap','#f59e0b,#b45309' ),
	'scripts'               => array( 'fas fa-code',          '#10b981,#047857' ),
	'other'                 => array( 'fas fa-layer-group',   '#64748b,#334155' ),
);
$cawh_cats = get_terms( array(
	'taxonomy'   => 'download_category',
	'hide_empty' => true,
	'orderby'    => 'count',
	'order'      => 'DESC',
	'number'     => 6,
) );

/* Blog */
$cawh_blog = new WP_Query( array(
	'post_type'      => 'post',
	'posts_per_page' => 3,
	'ignore_sticky_posts' => true,
	'no_found_rows'  => true,
) );

$cawh_store_url = function_exists( 'edd_get_option' ) ? get_post_type_archive_link( 'download' ) : home_url( '/store/' );
?>

<main id="main" class="site-main cawhome">

<!-- ============================ HERO ============================ -->
<section class="ch-hero"><div class="ch-wrap ch-hero-inner">
	<div class="ch-hero-copy">
		<span class="ch-eyebrow"><i class="fas fa-bolt"></i> Pakistan's #1 Crypto Marketplace</span>
		<h1>The Marketplace Where<br><span class="ch-grad">Crypto Becomes Real</span></h1>
		<p class="ch-lead">Buy premium subscriptions, gift cards, trading signals &amp; hardware wallets — and pay seamlessly with Bitcoin, USDT, ETH and other major cryptocurrencies.</p>
		<div class="ch-hero-search">
			<i class="fas fa-search ch-search-ico"></i>
			<?php echo do_shortcode( '[mayosis_edd_search placeholder="Search ' . esc_attr( $cawh_total_downloads ) . '+ crypto products & services…" length="2"]' ); // phpcs:ignore ?>
		</div>
		<div class="ch-hero-cta">
			<a class="ch-btn ch-btn-primary" href="<?php echo esc_url( $cawh_store_url ); ?>"><i class="fas fa-store"></i> Browse Store</a>
			<a class="ch-btn ch-btn-ghost" href="https://www.facebook.com/groups/cryptoawaz" target="_blank" rel="noopener"><i class="fab fa-facebook"></i> Join Community</a>
		</div>
		<div class="ch-trust">
			<span class="ch-ti"><i class="fas fa-bolt ch-acc"></i> <b>Instant</b> delivery</span>
			<span class="ch-ti"><i class="fas fa-shopping-bag ch-acc"></i> <b><?php echo number_format( $cawh_total_sales ); ?></b> orders delivered</span>
			<span class="ch-ti"><i class="fas fa-shield-alt ch-acc"></i> <b>Buyer</b> protection</span>
			<span class="ch-ti"><i class="fab fa-bitcoin" style="color:#f7931a!important"></i> <b>Major</b> cryptos accepted</span>
		</div>
	</div>
	<div class="ch-hero-art">
		<?php
		$cawh_pos = array( 'ch-hc1', 'ch-hc4', 'ch-hc2', 'ch-hc3' );
		foreach ( $cawh_hero_cards as $i => $hc ) {
			if ( empty( $hc['id'] ) ) continue;
			$link  = get_permalink( $hc['id'] );
			$title = get_the_title( $hc['id'] );
			$price = function_exists( 'edd_price' ) ? edd_price( $hc['id'], false ) : '';
			$glyph = isset( $hc['glyph'] ) ? $hc['glyph'] : ( $hc['icon'] ? '<i class="' . esc_attr( $hc['icon'] ) . '"></i>' : '' );
			?>
			<a class="ch-hero-card <?php echo $cawh_pos[ $i ]; ?>" href="<?php echo esc_url( $link ); ?>">
				<div class="ch-hc-img" style="background:linear-gradient(135deg,<?php echo esc_attr( $hc['grad'] ); ?>)<?php echo $hc['small'] ? ';height:80px' : ''; ?>"><?php echo $glyph; // phpcs:ignore ?></div>
				<div class="ch-hc-title"<?php echo $hc['small'] ? ' style="font-size:13px"' : ''; ?>><?php echo esc_html( $title ); ?></div>
				<?php if ( ! $hc['small'] ) : ?>
				<div class="ch-hc-row"><span class="ch-hc-price"><?php echo $price; // phpcs:ignore ?></span><?php if ( $hc['badge'] ) echo '<span class="ch-hc-badge">' . esc_html( $hc['badge'] ) . '</span>'; ?></div>
				<?php endif; ?>
			</a>
			<?php
		}
		?>
		<div class="ch-coin" style="width:44px;height:44px;background:linear-gradient(135deg,#26a17b,#1c7e5f);top:140px;right:-4px;font-size:16px">&#8366;</div>
		<div class="ch-coin" style="width:40px;height:40px;background:linear-gradient(135deg,#627eea,#3c54c4);bottom:54px;left:62px;font-size:15px">&#926;</div>
	</div>
</div></section>

<!-- ====================== LIVE PRICE TICKER ====================== -->
<div class="ch-ticker"><div class="ch-wrap ch-ticker-inner">
	<div class="ch-ticker-lbl"><span class="ch-live"></span> LIVE PRICES</div>
	<div class="ch-ticker-track" id="ch-ticker-track">
		<!-- Populated live from CoinGecko. Swap for [cryptocurrency_widget id="X"] once a ticker widget is built in Premium Cryptocurrency Widgets. -->
		<div class="ch-tk"><i class="fab fa-bitcoin" style="color:#f7931a!important"></i><span class="ch-sym">BTC</span><span class="ch-px">—</span></div>
		<div class="ch-tk"><i class="fab fa-ethereum" style="color:#627eea!important"></i><span class="ch-sym">ETH</span><span class="ch-px">—</span></div>
		<div class="ch-tk"><i class="fas fa-dollar-sign" style="color:#26a17b!important"></i><span class="ch-sym">USDT</span><span class="ch-px">—</span></div>
		<div class="ch-tk"><i class="fas fa-coins" style="color:#f3ba2f!important"></i><span class="ch-sym">BNB</span><span class="ch-px">—</span></div>
		<div class="ch-tk"><i class="fas fa-circle" style="color:#9945ff!important"></i><span class="ch-sym">SOL</span><span class="ch-px">—</span></div>
		<div class="ch-tk"><i class="fas fa-tint" style="color:#3b9eff!important"></i><span class="ch-sym">XRP</span><span class="ch-px">—</span></div>
	</div>
</div></div>

<!-- ============================ STATS ============================ -->
<div class="ch-stats"><div class="ch-wrap ch-stats-inner">
	<div class="ch-stat"><div class="ch-n"><?php echo esc_html( $cawh_total_downloads ); ?><span>+</span></div><div class="ch-l">Crypto Products</div></div>
	<div class="ch-stat"><div class="ch-n"><?php echo number_format( $cawh_total_sales ); ?></div><div class="ch-l">Orders Delivered</div></div>
	<div class="ch-stat"><div class="ch-n"><?php echo esc_html( $cawh_total_vendors ); ?></div><div class="ch-l">Verified Vendors</div></div>
	<div class="ch-stat"><div class="ch-n">11</div><div class="ch-l">Cryptos Accepted</div></div>
</div></div>

<!-- ======================= BEST SELLING ========================= -->
<section class="ch-sec"><div class="ch-wrap">
	<div class="ch-sec-head"><h2>&#128293; Best Selling Products</h2><p>The most-loved crypto products &amp; services on the marketplace.</p></div>
	<div class="ch-prod-grid">
		<?php while ( $cawh_best->have_posts() ) : $cawh_best->the_post();
			$sales = function_exists( 'edd_get_download_sales_stats' ) ? edd_get_download_sales_stats( get_the_ID() ) : 0;
			$flag  = $sales > 0 ? '<span class="ch-prod-fire">&#128293; ' . number_format( $sales ) . ' sold</span>' : '';
			cawhome_product_card( get_the_ID(), $flag );
		endwhile; wp_reset_postdata(); ?>
	</div>
	<div class="ch-center"><a class="ch-btn ch-btn-ghost" href="<?php echo esc_url( $cawh_store_url ); ?>">View All Products <i class="fas fa-arrow-right"></i></a></div>
</div></section>

<!-- ======================== NEWLY LISTED ======================== -->
<section class="ch-sec ch-pt0"><div class="ch-wrap">
	<div class="ch-sec-head"><h2>&#127381; Newly Listed</h2><p>Fresh on the marketplace — be first to grab the latest drops.</p></div>
	<div class="ch-prod-grid">
		<?php while ( $cawh_new->have_posts() ) : $cawh_new->the_post();
			cawhome_product_card( get_the_ID(), '<span class="ch-prod-fire ch-new">NEW</span>' );
		endwhile; wp_reset_postdata(); ?>
	</div>
</div></section>

<!-- ====================== SHOP BY CATEGORY ====================== -->
<section class="ch-sec ch-pt0"><div class="ch-wrap">
	<div class="ch-sec-head"><h2>Shop by Category</h2><p>Find exactly what you need across our curated crypto marketplace.</p></div>
	<div class="ch-cat-grid">
		<?php foreach ( $cawh_cats as $term ) :
			$ic   = isset( $cawh_cat_icons[ $term->slug ] ) ? $cawh_cat_icons[ $term->slug ] : array( 'fas fa-tag', '#1e73be,#155f9e' );
			?>
			<a class="ch-cat" href="<?php echo esc_url( get_term_link( $term ) ); ?>">
				<div class="ch-cat-ico" style="background:linear-gradient(135deg,<?php echo esc_attr( $ic[1] ); ?>)"><i class="<?php echo esc_attr( $ic[0] ); ?>"></i></div>
				<div><h3><?php echo esc_html( $term->name ); ?></h3><p><?php echo (int) $term->count; ?> products</p></div>
				<i class="fas fa-arrow-right ch-arr"></i>
			</a>
		<?php endforeach; ?>
	</div>
</div></section>

<!-- ======================= WHY CRYPTO AWAZ ====================== -->
<section class="ch-sec ch-shade"><div class="ch-wrap">
	<div class="ch-sec-head"><h2>Why Crypto Awaz</h2><p>Pakistan's most trusted crypto marketplace — built for the community.</p></div>
	<div class="ch-feat-grid">
		<div class="ch-feat"><div class="ch-feat-ico"><i class="fab fa-bitcoin"></i></div><h3>Pay with Crypto</h3><p>BTC, ETH, USDT &amp; other major coins. No bank, no borders, no hassle.</p></div>
		<div class="ch-feat"><div class="ch-feat-ico"><i class="fas fa-bolt"></i></div><h3>Instant Delivery</h3><p>Most orders are delivered automatically the moment payment confirms.</p></div>
		<div class="ch-feat"><div class="ch-feat-ico"><i class="fas fa-shield-alt"></i></div><h3>Verified Vendors</h3><p>Every seller is vetted. Buyer protection on every single order.</p></div>
		<div class="ch-feat"><div class="ch-feat-ico"><i class="fas fa-headset"></i></div><h3>24/7 Support</h3><p>Real humans on Discord &amp; Facebook whenever you need a hand.</p></div>
	</div>
</div></section>

<!-- ======================== HOW IT WORKS ======================== -->
<section class="ch-sec ch-how"><div class="ch-wrap">
	<div class="ch-sec-head"><h2>How It Works</h2><p>From browse to delivery in three simple steps.</p></div>
	<div class="ch-how-grid">
		<div class="ch-step"><div class="ch-step-n">1</div><h3>Browse &amp; Choose</h3><p>Pick from <?php echo esc_html( $cawh_total_downloads ); ?>+ verified products — subscriptions, signals, hardware &amp; more.</p></div>
		<div class="ch-step"><div class="ch-step-n">2</div><h3>Pay with Crypto</h3><p>Checkout securely with BTC, USDT, ETH and other major cryptocurrencies.</p></div>
		<div class="ch-step"><div class="ch-step-n">3</div><h3>Get Instant Access</h3><p>Receive your product instantly — or fast-tracked by the vendor.</p></div>
	</div>
</div></section>

<!-- ========================= VENDOR CTA ========================= -->
<section class="ch-sec ch-pt0"><div class="ch-wrap">
	<div class="ch-cta-band">
		<div><h2>Want to sell your crypto products?</h2><p>Reach thousands of buyers across Pakistan &amp; beyond. List your services, get paid in crypto, and let us handle the marketplace.</p></div>
		<a class="ch-btn ch-btn-primary ch-btn-lg" href="<?php echo esc_url( home_url( '/sell-your-services-in-crypto/' ) ); ?>"><i class="fas fa-store"></i> Become a Vendor</a>
	</div>
</div></section>

<!-- =========================== BLOG ============================= -->
<section class="ch-sec ch-pt0"><div class="ch-wrap">
	<div class="ch-sec-head"><h2>From the Crypto Blog</h2><p>News, guides &amp; insights for the Pakistani crypto community.</p></div>
	<div class="ch-blog-grid">
		<?php
		$cawh_bg = array( '#1e73be,#0f213d', '#7c3aed,#2a1a52', '#f59e0b,#4a2d05' );
		$cawh_bi = 0;
		while ( $cawh_blog->have_posts() ) : $cawh_blog->the_post();
			$bt = get_the_category(); $btn = $bt ? $bt[0]->name : 'Blog';
			$bthumb = get_the_post_thumbnail_url( get_the_ID(), 'medium_large' );
			?>
			<a class="ch-post" href="<?php the_permalink(); ?>">
				<div class="ch-post-img"<?php echo $bthumb ? ' style="background-image:url(\'' . esc_url( $bthumb ) . '\')"' : ' style="background:linear-gradient(135deg,' . $cawh_bg[ $cawh_bi % 3 ] . ')"'; ?>><?php echo $bthumb ? '' : '<i class="fas fa-newspaper"></i>'; ?></div>
				<div class="ch-post-body"><span class="ch-post-tag"><?php echo esc_html( $btn ); ?></span><h3><?php the_title(); ?></h3><div class="ch-post-meta"><?php echo esc_html( get_the_date() ); ?></div></div>
			</a>
			<?php $cawh_bi++; endwhile; wp_reset_postdata(); ?>
	</div>
</div></section>

<!-- ========================== FAQ / HELP ======================== -->
<section class="ch-sec ch-pt0"><div class="ch-wrap">
	<div class="ch-sec-head"><h2>Need Help? Start Here</h2><p>Quick answers to the questions our community asks most.</p></div>
	<div class="ch-faq-grid">
		<a class="ch-faq-q" href="<?php echo esc_url( home_url( '/faqs/' ) ); ?>"><div class="ch-qi"><i class="fab fa-bitcoin"></i></div><h3>How do I pay with cryptocurrency?</h3><i class="fas fa-chevron-right ch-chev"></i></a>
		<a class="ch-faq-q" href="<?php echo esc_url( home_url( '/faqs/' ) ); ?>"><div class="ch-qi"><i class="fas fa-bolt"></i></div><h3>How fast will I receive my order?</h3><i class="fas fa-chevron-right ch-chev"></i></a>
		<a class="ch-faq-q" href="<?php echo esc_url( home_url( '/sell-services-in-crypto/' ) ); ?>"><div class="ch-qi"><i class="fas fa-store"></i></div><h3>How can I sell my product on Crypto Awaz?</h3><i class="fas fa-chevron-right ch-chev"></i></a>
		<a class="ch-faq-q" href="<?php echo esc_url( home_url( '/security-information/' ) ); ?>"><div class="ch-qi"><i class="fas fa-shield-alt"></i></div><h3>Is my purchase safe and protected?</h3><i class="fas fa-chevron-right ch-chev"></i></a>
		<a class="ch-faq-q" href="https://play.google.com/store/apps/details?id=com.cryptoawaz.cryptocurrencypakistan" target="_blank" rel="noopener"><div class="ch-qi"><i class="fas fa-mobile-alt"></i></div><h3>Do you have a mobile app?</h3><i class="fas fa-chevron-right ch-chev"></i></a>
		<a class="ch-faq-q" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><div class="ch-qi"><i class="fas fa-headset"></i></div><h3>How do I contact support?</h3><i class="fas fa-chevron-right ch-chev"></i></a>
	</div>
	<div class="ch-faq-cta">
		<p class="ch-sub">Explore our growing free knowledge base at <b>cryptocurrencypakistan.org</b> — built for the Pakistani crypto community.</p>
		<a class="ch-btn ch-btn-primary" href="https://cryptocurrencypakistan.org" target="_blank" rel="noopener"><i class="fas fa-question-circle"></i> Find Answers &amp; Get Help</a>
	</div>
</div></section>

<!-- ========================= COMMUNITY ========================== -->
<section class="ch-sec ch-pt0"><div class="ch-wrap">
	<div class="ch-news">
		<h2>Join the Crypto Awaz Community</h2>
		<p>Get product drops, exclusive deals and crypto insights straight to your inbox.</p>
		<div class="ch-news-cta"><a class="ch-btn ch-btn-primary ch-btn-lg" href="<?php echo esc_url( home_url( '/about-us/newsletter-signup/' ) ); ?>"><i class="fas fa-envelope"></i> Subscribe to the Newsletter</a></div>
		<div class="ch-news-chips">
			<a class="ch-chip" href="https://linktr.ee/cryptoawaz" target="_blank" rel="noopener"><i class="fab fa-discord" style="color:#5865f2"></i> Discord</a>
			<a class="ch-chip" href="https://www.facebook.com/cryptoawaz" target="_blank" rel="noopener"><i class="fab fa-facebook" style="color:#1877f2"></i> Facebook</a>
			<a class="ch-chip" href="https://www.instagram.com/cryptoawaz/" target="_blank" rel="noopener"><i class="fab fa-instagram" style="color:#e1306c"></i> Instagram</a>
			<a class="ch-chip" href="https://www.youtube.com/c/CryptoAwaz" target="_blank" rel="noopener"><i class="fab fa-youtube" style="color:#ff0000"></i> YouTube</a>
		</div>
	</div>
</div></section>

<!-- ===================== TRUSTPILOT (Review Collector — free-tier, ToS-safe) ===================== -->
<section class="ch-sec ch-pt0"><div class="ch-wrap">
	<div class="ch-review-cta">
		<div class="ch-review-ico"><i class="fas fa-comments"></i></div>
		<h2>Shopped with us? Share your experience</h2>
		<p>Your honest review helps the Pakistani crypto community shop with confidence. Leave a review or read what others have shared on Trustpilot.</p>
		<div class="ch-center ch-mt"><a class="ch-btn ch-btn-primary" href="https://www.trustpilot.com/review/cryptoawaz.com" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Read &amp; write reviews on Trustpilot</a></div>
	</div>
</div></section>

</main><!-- /.cawhome -->

<script>
/* Live price ticker — free CoinGecko API (no key). Replace with the
   Premium Cryptocurrency Widgets shortcode if a ticker widget is built there. */
(function(){
	var ids = {bitcoin:'BTC',ethereum:'ETH',tether:'USDT',binancecoin:'BNB',solana:'SOL',ripple:'XRP'};
	var order = ['bitcoin','ethereum','tether','binancecoin','solana','ripple'];
	fetch('https://api.coingecko.com/api/v3/simple/price?ids='+order.join(',')+'&vs_currencies=usd&include_24hr_change=true')
	.then(function(r){return r.json();}).then(function(d){
		var track = document.getElementById('ch-ticker-track');
		if(!track) return;
		var cells = track.querySelectorAll('.ch-tk');
		order.forEach(function(id,i){
			if(!d[id]||!cells[i]) return;
			var p = d[id].usd, ch = d[id].usd_24h_change||0;
			var px = cells[i].querySelector('.ch-px');
			px.textContent = '$'+(p>=1?p.toLocaleString(undefined,{maximumFractionDigits:p>=100?0:2}):p.toFixed(2));
			var s = document.createElement('span');
			s.className = ch>=0?'ch-up':'ch-dn';
			s.textContent = (ch>=0?'▲ ':'▼ ')+Math.abs(ch).toFixed(1)+'%';
			cells[i].appendChild(s);
		});
	}).catch(function(){});
})();
</script>

<?php
get_footer();
