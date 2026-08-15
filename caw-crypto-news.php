<?php
/**
 * Template Name: Crypto News (RSS)
 *
 * Replaces the retired Premium Cryptocurrency Widgets news-block, whose
 * CryptoCompare key sits on the free tier (25 calls/month, long exhausted) and
 * so returned an error for every request.
 *
 * Uses WordPress core's fetch_feed()/SimplePie: no API key, no rate limit, no
 * third-party JavaScript. SimplePie caches each feed in a transient, so only
 * the first request after expiry pays the network cost.
 *
 * Markup deliberately reuses .ch-blog-grid / .ch-post from the homepage blog
 * section, so this template ships no CSS of its own and inherits night mode.
 *
 * @package mayosis-child
 */

get_header();

/* Feeds verified live 2026-08-15. Bitcoin Magazine is omitted: it 403s server-side. */
$caw_news_feeds = apply_filters(
	'caw_crypto_news_feeds',
	array(
		'Cointelegraph' => 'https://cointelegraph.com/rss',
		'Decrypt'       => 'https://decrypt.co/feed',
		'CoinDesk'      => 'https://www.coindesk.com/arc/outboundfeeds/rss/',
	)
);

$caw_news_limit = (int) apply_filters( 'caw_crypto_news_limit', 24 );

/* Keep the page snappy: a stalled feed must not hold the render open, and a
   30-minute cache means almost every visitor is served from the transient. */
add_filter( 'wp_feed_cache_transient_lifetime', 'caw_news_cache_lifetime' );
function caw_news_cache_lifetime() {
	return 30 * MINUTE_IN_SECONDS;
}
add_action( 'wp_feed_options', 'caw_news_feed_timeout' );
function caw_news_feed_timeout( $feed ) {
	$feed->set_timeout( 5 );
}

/**
 * Best-effort image for a feed item. Feeds disagree on where they put it, so
 * try the enclosure first, then the common media:* namespaces.
 */
function caw_news_item_image( $item ) {
	$enclosure = $item->get_enclosure();
	if ( $enclosure ) {
		$thumb = $enclosure->get_thumbnail();
		if ( $thumb ) {
			return $thumb;
		}
		$link = $enclosure->get_link();
		if ( $link && preg_match( '#\.(jpe?g|png|webp|avif|gif)(\?|$)#i', $link ) ) {
			return $link;
		}
	}

	foreach ( array( 'thumbnail', 'content' ) as $tag ) {
		$media = $item->get_item_tags( 'http://search.yahoo.com/mrss/', $tag );
		if ( ! empty( $media[0]['attribs']['']['url'] ) ) {
			return $media[0]['attribs']['']['url'];
		}
	}

	return '';
}

/* ---- Gather + merge -------------------------------------------------------
   One flat list sorted newest-first, so the page reads as a single feed rather
   than three stacked sources. A dead feed is skipped, never fatal. */
$caw_news_items  = array();
$caw_news_failed = array();

foreach ( $caw_news_feeds as $caw_source => $caw_url ) {
	$caw_feed = fetch_feed( $caw_url );

	if ( is_wp_error( $caw_feed ) ) {
		$caw_news_failed[] = $caw_source;
		continue;
	}

	foreach ( $caw_feed->get_items( 0, 12 ) as $caw_item ) {
		$caw_news_items[] = array(
			'source'    => $caw_source,
			'title'     => trim( wp_strip_all_tags( $caw_item->get_title() ) ),
			'permalink' => $caw_item->get_permalink(),
			'timestamp' => (int) $caw_item->get_date( 'U' ),
			'image'     => caw_news_item_image( $caw_item ),
		);
	}
}

usort(
	$caw_news_items,
	function ( $a, $b ) {
		return $b['timestamp'] <=> $a['timestamp'];
	}
);
$caw_news_items = array_slice( $caw_news_items, 0, $caw_news_limit );

/* Same placeholder palette the homepage blog grid uses. */
$caw_news_grads = array( '#1e73be,#0f213d', '#7c3aed,#2a1a52', '#f59e0b,#4a2d05' );
?>

<section class="ch-sec"><div class="ch-wrap">

	<div class="ch-sec-head">
		<h2><?php echo esc_html( get_the_title() ); ?></h2>
		<p>Headlines from across the crypto press, refreshed throughout the day.</p>
	</div>

	<?php if ( empty( $caw_news_items ) ) : ?>

		<div class="ch-sec-head">
			<p>Headlines are unavailable right now. Please try again shortly, or join the
			<a href="https://discord.gg/cryptoawaz" target="_blank" rel="noopener">Crypto Awaz Discord</a>
			for live discussion.</p>
		</div>

	<?php else : ?>

		<div class="ch-blog-grid">
			<?php foreach ( $caw_news_items as $caw_i => $caw_news ) : ?>
				<a class="ch-post" href="<?php echo esc_url( $caw_news['permalink'] ); ?>" target="_blank" rel="noopener nofollow">
					<div class="ch-post-img"<?php
						echo $caw_news['image']
							? ' style="background-image:url(\'' . esc_url( $caw_news['image'] ) . '\')"'
							: ' style="background:linear-gradient(135deg,' . esc_attr( $caw_news_grads[ $caw_i % 3 ] ) . ')"';
					?>><?php echo $caw_news['image'] ? '' : '<i class="fas fa-newspaper"></i>'; ?></div>
					<div class="ch-post-body">
						<span class="ch-post-tag"><?php echo esc_html( $caw_news['source'] ); ?></span>
						<h3><?php echo esc_html( $caw_news['title'] ); ?></h3>
						<div class="ch-post-meta">
							<?php
							echo $caw_news['timestamp']
								? esc_html( date_i18n( get_option( 'date_format' ), $caw_news['timestamp'] ) )
								: '';
							?>
						</div>
					</div>
				</a>
			<?php endforeach; ?>
		</div>

		<div class="ch-center">
			<p class="ch-sub">Headlines are sourced from third-party publishers and link to the original article. Crypto Awaz does not endorse their content.</p>
		</div>

	<?php endif; ?>

	<?php
	/* Surfaced for logged-in admins only — visitors should never see plumbing. */
	if ( $caw_news_failed && current_user_can( 'manage_options' ) ) :
		?>
		<div class="ch-center">
			<p class="ch-sub"><strong>Admin notice:</strong> feed(s) unreachable —
			<?php echo esc_html( implode( ', ', $caw_news_failed ) ); ?>.</p>
		</div>
	<?php endif; ?>

</div></section>

<?php
get_footer();
