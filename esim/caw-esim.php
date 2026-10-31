<?php
/**
 * eSIM store: one product that sells Foxreload's whole eSIM catalogue (~250 countries/regions,
 * ~14,000 plans). The owner ticks "eSIM store" on a product and sets a markup, nothing else.
 *
 * The customer searches a country, its plans load live, and Buy turns the chosen plan into a real
 * price option on the product (created on first purchase, re-used after). From there it is an
 * ordinary Foxreload option, so checkout, the margin guard, delivery, retries and refunds in
 * caw-foxreload.php all apply. Only plans someone has put in a cart become options, which keeps the
 * hourly sync out of it: their cost is re-read at add-to-cart and at checkout instead. Options nobody
 * bought are reclaimed when the limit is reached, so bots can't fill it for good.
 *
 * Post meta: _caw_esim (1), _caw_esim_rows (master copy of the price options, because EDD rewrites
 * edd_variable_prices from the edit form), and the usual _caw_fox / _caw_fox_cache / _caw_fox_guide.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Costs the markup curve slides between: fixed, because the rows on the product are only the plans sold so far. */
define( 'CAW_ESIM_COST_MIN', 0.5 );
define( 'CAW_ESIM_COST_MAX', 100.0 );
define( 'CAW_ESIM_MAX_ROWS', 3000 ); // a bot can't grow the product without limit

function caw_esim_is_store( $download_id ) {
	return (bool) get_post_meta( $download_id, '_caw_esim', true );
}

/* =========================================================================
   Catalogue (cached; the API is never hit per visitor)
   ========================================================================= */

/** [ [ 'slug', 'name', 'region' => bool ], … ] sorted by name. Last good list survives an outage. */
function caw_esim_countries() {
	$list = get_transient( 'caw_esim_countries' );
	if ( is_array( $list ) ) {
		return $list;
	}
	$res = caw_fox_api( 'GET', '/api/categories/tree?parentId=esim&depth=1&withStockOnly=true' );
	if ( $res['error'] || empty( $res['data']['items'] ) ) {
		$list = get_option( 'caw_esim_countries_last', array() );
		set_transient( 'caw_esim_countries', $list, 5 * MINUTE_IN_SECONDS );
		return $list;
	}
	$list = array();
	foreach ( $res['data']['items'] as $c ) {
		if ( preg_match( '/physical sim|airalo/i', $c['name'] ) || empty( $c['inStockCount'] ) ) {
			continue; // not eSIMs, or a reseller brand with unnamed "data plan" items
		}
		$list[] = array(
			'slug'   => (string) $c['slug'],
			'name'   => (string) $c['name'],
			'region' => (bool) preg_match( '/\(|&|^(global|europe|africa|asia|north america|south america|central asia|middle east|mena|gcc|gulf region|oceania|caribbean|balkans)\b|asia-20|aukus/i', $c['name'] ),
		);
	}
	usort( $list, function ( $a, $b ) {
		return strcmp( remove_accents( $a['name'] ), remove_accents( $b['name'] ) );
	} );
	set_transient( 'caw_esim_countries', $list, 6 * HOUR_IN_SECONDS );
	update_option( 'caw_esim_countries_last', $list, false );
	return $list;
}

function caw_esim_country( $slug ) {
	foreach ( caw_esim_countries() as $c ) {
		if ( $c['slug'] === $slug ) {
			return $c;
		}
	}
	return null;
}

/** "5 GB", "500 MB". */
function caw_esim_gb( $gb ) {
	return $gb < 1 ? round( $gb * 1000 ) . ' MB' : rtrim( rtrim( number_format( $gb, 1 ), '0' ), '.' ) . ' GB';
}

/** One supplier product as a plan, or null if it isn't sellable. */
function caw_esim_plan( $p ) {
	$a    = isset( $p['attributes'] ) && is_array( $p['attributes'] ) ? $p['attributes'] : array();
	$use  = isset( $a['data_usage_type'] ) ? $a['data_usage_type'] : '';
	$days = isset( $a['package_period_days'] ) ? (int) $a['package_period_days'] : 0;
	$gb   = isset( $a['internet_gb'] ) ? (float) $a['internet_gb'] : 0.0;
	$cost = isset( $p['price'] ) ? (float) $p['price'] : 0.0;
	if ( 'esim' !== ( isset( $p['deliveryType'] ) ? $p['deliveryType'] : '' ) || empty( $p['quantity'] ) || $cost <= 0 || ! $days || ! in_array( $use, array( 'total', 'daily_limit_reduced' ), true ) ) {
		return null;
	}
	$daily = 'daily_limit_reduced' === $use;
	$speed = isset( $a['reducted_speed'] ) ? (int) $a['reducted_speed'] : 0;
	$open  = false !== stripos( (string) $p['name'], 'no daily limit' );
	$days_txt = sprintf( _n( '%d day', '%d days', $days, 'mayosis' ), $days );
	if ( $open ) {
		$data = __( 'Unlimited', 'mayosis' );
	} elseif ( $daily ) {
		$data = caw_esim_gb( $gb ) . __( '/day', 'mayosis' );
	} else {
		$data = caw_esim_gb( $gb );
	}
	$after = '';
	if ( $daily && ! $open && $speed ) {
		$after = $speed >= 1000 ? sprintf( __( 'then %s Mbps', 'mayosis' ), $speed / 1000 ) : sprintf( __( 'then %d kbps', 'mayosis' ), $speed );
	}
	// The supplier words this a dozen ways and in two languages. No plan in the catalogue forbids it.
	$hotspot = (bool) preg_match( '/hot.?spot|tether|shar(e|ing)|distribut|раздава/iu', ( isset( $p['description'] ) ? $p['description'] : '' ) . ' ' . ( isset( $a['internet_description'] ) ? $a['internet_description'] : '' ) );
	$note    = '';
	if ( ! empty( $a['customer_notice'] ) ) {
		// Supplier notices are Russian only. The one in use today says to install before arriving in Türkiye.
		$note = __( 'Install this eSIM before you travel. It can\'t be set up after you arrive.', 'mayosis' );
	}
	return array(
		'id'      => (string) $p['id'],
		'k'       => substr( md5( (string) $p['id'] ), 0, 12 ), // what the browser sees instead of the supplier's id
		'data'    => $data,
		'days'    => $days,
		'label'   => $data . ', ' . $days_txt . ( $after ? ', ' . $after : '' ),
		'after'   => $after,
		'hotspot' => $hotspot,
		'daily'   => $daily,
		'gb'      => $open ? 9999 : $gb,
		'speed'   => $speed,
		'install' => isset( $a['validity_days'] ) ? (int) $a['validity_days'] : 0,
		'note'    => $note,
		'cost'    => $cost,
		'qty'     => (int) $p['quantity'],
	);
}

/** In-stock plans of one country, cheapest per data + days + speed tier. Empty array = none; null = supplier unreachable. */
function caw_esim_plans( $slug ) {
	$key   = 'caw_esim_p3_' . md5( $slug );
	$plans = get_transient( $key );
	if ( is_array( $plans ) ) {
		return $plans;
	}
	$plans = array();
	for ( $offset = 0; $offset < 600; $offset += 200 ) {
		$res = caw_fox_api( 'GET', '/api/products/?categoryId=' . rawurlencode( $slug ) . '&withStockOnly=true&limit=200&offset=' . $offset );
		if ( $res['error'] ) {
			return null;
		}
		$items = isset( $res['data']['items'] ) ? (array) $res['data']['items'] : array();
		foreach ( $items as $p ) {
			$plan = caw_esim_plan( $p );
			if ( ! $plan ) {
				continue;
			}
			// The supplier lists one size several times, differing only in the speed after the daily limit:
			// keep the cheapest of the slow ones and the cheapest at 1 Mbps or more, and on a tie the faster.
			$same = $plan['daily'] . '|' . $plan['gb'] . '|' . $plan['days'] . '|' . ( $plan['speed'] >= 1000 ? 'fast' : 'slow' );
			$held = isset( $plans[ $same ] ) ? $plans[ $same ] : null;
			if ( ! $held || $plan['cost'] < $held['cost'] || ( $plan['cost'] === $held['cost'] && $plan['speed'] > $held['speed'] ) ) {
				$plans[ $same ] = $plan;
			}
		}
		if ( count( $items ) < 200 ) {
			break;
		}
	}
	// A slower plan that costs no less than the 1 Mbps one has no reason to be on the page.
	foreach ( $plans as $same => $plan ) {
		$fast = substr( $same, 0, -4 ) . 'fast';
		if ( 'slow' === substr( $same, -4 ) && isset( $plans[ $fast ] ) && $plans[ $fast ]['cost'] <= $plan['cost'] ) {
			unset( $plans[ $same ] );
		}
	}
	$plans = array_values( $plans );
	usort( $plans, function ( $a, $b ) {
		return array( $a['days'], $a['gb'], $a['speed'] ) <=> array( $b['days'], $b['gb'], $b['speed'] );
	} );
	set_transient( $key, $plans, HOUR_IN_SECONDS );
	return $plans;
}

/** [ cheapest %, dearest % ]. Read directly: caw_fox_map() ignores a product with no options yet, as a new store has. */
function caw_esim_markup( $download_id ) {
	$m = get_post_meta( $download_id, '_caw_fox', true );
	return ! empty( $m['markup']['reg'] ) && 2 === count( $m['markup']['reg'] ) ? array_map( 'floatval', array_values( $m['markup']['reg'] ) ) : array();
}

/** Selling price from cost, or 0 while no markup is set (nothing is sold). */
function caw_esim_price( $download_id, $cost ) {
	$mk = caw_esim_markup( $download_id );
	if ( ! $mk ) {
		return 0.0;
	}
	$pct = max( caw_fox_min_markup(), caw_fox_curve_pct( $mk, $cost, CAW_ESIM_COST_MIN, CAW_ESIM_COST_MAX ) );
	return caw_fox_shop_price( $cost * ( 1 + $pct / 100 ) );
}

/* =========================================================================
   Plan → price option
   ========================================================================= */

function caw_esim_save_rows( $download_id, $rows ) {
	update_post_meta( $download_id, '_caw_esim_rows', $rows );
	update_post_meta( $download_id, 'edd_variable_prices', $rows );
	update_post_meta( $download_id, '_variable_pricing', 1 );
	update_post_meta( $download_id, 'edd_price', edd_sanitize_amount( min( array_map( function ( $r ) {
		return (float) $r['amount'];
	}, $rows ) ) ) );
}

/** Options on no order, not added to a cart for a day. The first stays: it gives EDD its "From $x" price. */
function caw_esim_unsold_rows( $download_id, $rows, $cache ) {
	global $wpdb;
	$sold = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT price_id FROM {$wpdb->prefix}edd_order_items WHERE product_id = %d", $download_id ) ) );
	$out  = array();
	foreach ( array_slice( array_keys( $rows ), 1 ) as $pid ) {
		if ( ! in_array( (int) $pid, $sold, true ) && ( empty( $cache[ $pid ]['at'] ) || time() - $cache[ $pid ]['at'] > DAY_IN_SECONDS ) ) {
			$out[] = $pid;
		}
	}
	return $out;
}

/**
 * The price option for a plan, created if this is its first sale and repriced from the live cost.
 * Returns the price id, or 0 if the product has reached its limit.
 */
function caw_esim_row( $download_id, $plan, $country ) {
	global $wpdb;
	$lock = 'caw_esim_' . (int) $download_id;
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ); // two buyers at once must not take the same id
	wp_cache_delete( $download_id, 'post_meta' );

	$map   = get_post_meta( $download_id, '_caw_fox', true );
	$map   = is_array( $map ) ? $map : array();
	$map  += array( 'items' => array(), 'axes' => array(), 'markup' => array() );
	$rows  = get_post_meta( $download_id, '_caw_esim_rows', true );
	$rows  = is_array( $rows ) ? $rows : array();
	$cache = get_post_meta( $download_id, '_caw_fox_cache', true );
	$cache = is_array( $cache ) ? $cache : array();
	$pid   = array_search( $plan['id'], $map['items'], true );
	if ( false === $pid ) {
		if ( count( $rows ) >= CAW_ESIM_MAX_ROWS ) {
			foreach ( caw_esim_unsold_rows( $download_id, $rows, $cache ) as $old ) {
				unset( $rows[ $old ], $cache[ $old ], $map['items'][ $old ] );
			}
		}
		if ( count( $rows ) >= CAW_ESIM_MAX_ROWS ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			return 0;
		}
		// Ids never come back: an old cart must not wake up holding a different plan.
		$pid = max( (int) get_post_meta( $download_id, '_caw_esim_last', true ), $rows ? max( array_keys( $rows ) ) : 0 ) + 1;
		update_post_meta( $download_id, '_caw_esim_last', $pid );
	}
	$pid                 = (int) $pid;
	$price               = caw_esim_price( $download_id, $plan['cost'] );
	$map['items'][ $pid ] = $plan['id'];
	$rows[ $pid ]        = array(
		'index'  => $pid,
		'name'   => $country . ' - ' . $plan['label'],
		'amount' => edd_sanitize_amount( $price ),
	);
	$cache[ $pid ]       = array(
		'ok'      => true,
		'reason'  => '',
		'cost'    => $plan['cost'],
		'qty'     => $plan['qty'],
		'max'     => 1,
		'req'     => array(),
		'opts'    => array(),
		'name'    => $rows[ $pid ]['name'],
		'guide'   => '', // the supplier's has {{…}} placeholders; caw_esim_html() writes our own
		'type'    => 'esim',
		'svc'     => false,
		'install' => $plan['install'],
		'at'      => time(),
	);
	update_post_meta( $download_id, '_caw_fox', $map );
	update_post_meta( $download_id, '_caw_fox_cache', $cache );
	caw_esim_save_rows( $download_id, $rows );
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	return $pid;
}

// Plans sit in carts for days: re-read the cost at checkout, so a rise is shown before payment rather
// than failing the margin guard after it.
add_action( 'edd_checkout_error_checks', function () {
	foreach ( caw_fox_cart_lines() as $key => $line ) {
		$id = (int) $line['item']['id'];
		if ( ! caw_esim_is_store( $id ) || ( ! empty( $line['cache']['at'] ) && time() - $line['cache']['at'] < 5 * MINUTE_IN_SECONDS ) ) {
			continue;
		}
		$res = caw_fox_api( 'GET', '/api/products/' . rawurlencode( caw_fox_item_id( $id, $line['price_id'] ) ) );
		if ( caw_fox_is_transient( $res ) ) {
			continue; // the guard at delivery still protects the margin
		}
		$plan  = $res['error'] ? null : caw_esim_plan( $res['data'] );
		$cache = get_post_meta( $id, '_caw_fox_cache', true );
		if ( ! $plan ) {
			$cache[ $line['price_id'] ]['ok']     = false;
			$cache[ $line['price_id'] ]['reason'] = 'out of stock at supplier';
			update_post_meta( $id, '_caw_fox_cache', $cache );
			continue; // caw-foxreload's own check, later on this hook, reports it
		}
		$old     = caw_fox_sell_price( $id, $line['price_id'] );
		$country = trim( strstr( (string) edd_get_price_option_name( $id, $line['price_id'] ), ' - ', true ) );
		caw_esim_row( $id, $plan, $country );
		$new = caw_fox_sell_price( $id, $line['price_id'] );
		if ( $new > $old ) {
			edd_set_error( 'caw_esim_price_' . $key, sprintf( __( 'The price of %1$s has changed to $%2$s. Please check your total and order again.', 'mayosis' ), edd_get_cart_item_name( $line['item'] ), number_format( $new, 2 ) ) );
		}
	}
}, 5 );

/* =========================================================================
   Storefront: search + plans over AJAX. Nonce-free on purpose: the product page is
   edge-cached for hours, and these only read the catalogue or fill the visitor's own cart.
   ========================================================================= */

function caw_esim_ajax_plans() {
	$slug    = isset( $_GET['country'] ) ? sanitize_title( wp_unslash( $_GET['country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$country = caw_esim_country( $slug );
	if ( ! $country || ! caw_esim_is_store( $id ) ) {
		wp_send_json_error( __( 'That destination is not available.', 'mayosis' ), 404 );
	}
	$plans = caw_esim_plans( $slug );
	if ( null === $plans ) {
		wp_send_json_error( __( 'Plans could not be loaded right now. Please try again in a minute.', 'mayosis' ), 503 );
	}
	$out = array();
	foreach ( $plans as $p ) {
		$price = caw_esim_price( $id, $p['cost'] );
		if ( $price ) {
			$out[] = array(
				'k'     => $p['k'],
				'data'  => $p['data'],
				'days'  => $p['days'],
				'after' => $p['after'],
				'hot'   => $p['hotspot'],
				'daily' => $p['daily'],
				'note'  => $p['note'],
				'price' => number_format( $price, 2, '.', '' ),
			);
		}
	}
	header( 'Cache-Control: private, max-age=300' );
	wp_send_json_success( array( 'name' => $country['name'], 'plans' => $out ) );
}
add_action( 'wp_ajax_caw_esim_plans', 'caw_esim_ajax_plans' );
add_action( 'wp_ajax_nopriv_caw_esim_plans', 'caw_esim_ajax_plans' );

function caw_esim_ajax_add() {
	nocache_headers();
	$slug    = isset( $_POST['country'] ) ? sanitize_title( wp_unslash( $_POST['country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$k       = isset( $_POST['k'] ) ? sanitize_key( $_POST['k'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$country = caw_esim_country( $slug );
	$plans   = $country && caw_esim_is_store( $id ) ? caw_esim_plans( $slug ) : null;
	$plan    = null;
	foreach ( (array) $plans as $p ) {
		if ( $p['k'] === $k ) {
			$plan = $p;
		}
	}
	if ( ! $plan || ! caw_esim_price( $id, $plan['cost'] ) ) {
		wp_send_json_error( __( 'This plan is no longer available. Please choose another.', 'mayosis' ), 409 );
	}
	$pid = caw_esim_row( $id, $plan, $country['name'] );
	if ( ! $pid ) {
		if ( ! get_transient( 'caw_esim_cap_alert' ) ) {
			set_transient( 'caw_esim_cap_alert', 1, HOUR_IN_SECONDS );
			caw_fox_admin_alert( 'eSIM store: option limit reached', 'The eSIM product has ' . CAW_ESIM_MAX_ROWS . ' price options added in the last day, so a customer could not buy a new plan. If there are few orders, this is probably a bot adding plans to carts.' );
		}
		wp_send_json_error( __( 'This plan can\'t be bought right now. Please contact us.', 'mayosis' ), 503 );
	}
	edd_add_to_cart( $id, array( 'price_id' => $pid ) );
	wp_send_json_success( array( 'url' => edd_get_checkout_uri() ) );
}
add_action( 'wp_ajax_caw_esim_add', 'caw_esim_ajax_add' );
add_action( 'wp_ajax_nopriv_caw_esim_add', 'caw_esim_ajax_add' );

/** The buy box of the eSIM product (replaces the option selector and Buy button). */
function caw_esim_render_picker( $id ) {
	if ( ! caw_esim_markup( $id ) ) {
		echo '<p class="caw-esim-msg">' . esc_html__( 'eSIMs are not on sale right now.', 'mayosis' ) . '</p>';
		return;
	}
	$countries = caw_esim_countries();
	// What people type that isn't the supplier's name for the place.
	$aliases = array(
		'esim-united-arab-emirates' => 'uae dubai abu dhabi emirates',
		'esim-saudi-arabia'         => 'ksa makkah mecca madinah medina umrah hajj riyadh jeddah',
		'esim-turkiye'              => 'turkey istanbul',
		'esim-united-kingdom'       => 'uk britain england london scotland',
		'esim-united-states'        => 'usa america new york',
		'esim-south-korea'          => 'korea seoul',
		'esim-czechia'              => 'czech republic',
		'esim-china'                => 'beijing shanghai',
	);
	$flags = include __DIR__ . '/flag-codes.php';
	$data  = array();
	foreach ( $countries as $c ) {
		$data[] = array( $c['slug'], $c['name'], $c['region'] ? 1 : 0, isset( $aliases[ $c['slug'] ] ) ? $aliases[ $c['slug'] ] : '', isset( $flags[ $c['slug'] ] ) ? $flags[ $c['slug'] ] : '' );
	}
	?>
	<div class="caw-esim" data-id="<?php echo (int) $id; ?>" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
		<div class="caw-selblock caw-esim-where">
			<label class="caw-sellabel" for="caw-esim-q"><?php esc_html_e( 'Where do you need data?', 'mayosis' ); ?></label>
			<input type="text" id="caw-esim-q" class="caw-esim-q" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="caw-esim-list" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e( 'Search a country or region', 'mayosis' ); ?>">
			<ul id="caw-esim-list" class="caw-esim-list" role="listbox" aria-label="<?php esc_attr_e( 'Destinations', 'mayosis' ); ?>" hidden></ul>
		</div>
		<div class="caw-esim-plans" aria-live="polite"></div>
		<p class="caw-esim-note"><?php esc_html_e( 'Data only, no phone number. Your phone must support eSIM and be network-unlocked. To check, dial *#06#: if an EID number shows, it supports eSIM.', 'mayosis' ); ?></p>
		<div class="caw-pricerow" aria-live="polite" aria-atomic="true" hidden><span class="caw-price"></span><span class="caw-per"></span></div>
		<button type="button" class="caw-cta caw-esim-buy" disabled><span class="caw-cta-label"><?php esc_html_e( 'Choose a destination', 'mayosis' ); ?></span></button>
		<p class="caw-esim-msg" role="alert" hidden></p>
	</div>
	<script>
	(function () {
		var COUNTRIES = <?php echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;
		var FLAGS = <?php echo wp_json_encode( get_stylesheet_directory_uri() . '/esim/flags/' ); ?>;
		var TOP = ['united-arab-emirates', 'saudi-arabia', 'turkiye', 'pakistan', 'qatar', 'oman', 'malaysia', 'thailand', 'united-kingdom', 'united-states'].map(function (s) { return 'esim-' + s; });
		var T = {
			buy: <?php echo wp_json_encode( __( 'Buy Now', 'mayosis' ) ); ?>,
			pick: <?php echo wp_json_encode( __( 'Choose a plan', 'mayosis' ) ); ?>,
			dest: <?php echo wp_json_encode( __( 'Choose a destination', 'mayosis' ) ); ?>,
			fixed: <?php echo wp_json_encode( __( 'Fixed data', 'mayosis' ) ); ?>,
			daily: <?php echo wp_json_encode( __( 'Daily data', 'mayosis' ) ); ?>,
			day: <?php echo wp_json_encode( __( 'day', 'mayosis' ) ); ?>,
			days: <?php echo wp_json_encode( __( 'days', 'mayosis' ) ); ?>,
			all: <?php echo wp_json_encode( __( 'All', 'mayosis' ) ); ?>,
			load: <?php echo wp_json_encode( __( 'Loading plans…', 'mayosis' ) ); ?>,
			none: <?php echo wp_json_encode( __( 'No plans are available for this destination right now.', 'mayosis' ) ); ?>,
			nores: <?php echo wp_json_encode( __( 'Nothing found. Try the region, for example Europe.', 'mayosis' ) ); ?>,
			fail: <?php echo wp_json_encode( __( 'Something went wrong. Please try again.', 'mayosis' ) ); ?>,
			regions: <?php echo wp_json_encode( __( 'Regions', 'mayosis' ) ); ?>,
			countries: <?php echo wp_json_encode( __( 'All countries', 'mayosis' ) ); ?>,
			popular: <?php echo wp_json_encode( __( 'Popular', 'mayosis' ) ); ?>,
			hot: <?php echo wp_json_encode( __( 'Hotspot', 'mayosis' ) ); ?>,
			hotall: <?php echo wp_json_encode( __( 'Hotspot allowed on all these plans: share data with your other devices.', 'mayosis' ) ); ?>,
			cap: <?php echo wp_json_encode( __( 'Daily plans give you that much at full speed each day. After that the internet keeps working, but slowed to the speed shown until the next day starts. Around 500 kbps suits messages and maps, not video.', 'mayosis' ) ); ?>
		};
		var box = document.querySelector('.caw-esim');
		if (!box) return;
		var q = box.querySelector('.caw-esim-q'), list = box.querySelector('.caw-esim-list'), area = box.querySelector('.caw-esim-plans');
		var buy = box.querySelector('.caw-esim-buy'), label = buy.querySelector('.caw-cta-label'), msg = box.querySelector('.caw-esim-msg');
		var row = box.querySelector('.caw-pricerow'), priceEl = box.querySelector('.caw-price'), perEl = box.querySelector('.caw-per');
		var ajax = box.getAttribute('data-url'), id = box.getAttribute('data-id');
		var country = null, plans = [], tab = 'fixed', dayFilter = 0, chosen = null, active = -1, token = 0;

		function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
		function norm(s) { return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
		function flag(c) { return '<img class="caw-esim-flag" src="' + esc(FLAGS + (c[4] || 'globe') + '.svg') + '" alt="" width="20" height="15" loading="lazy">'; }
		function daysTxt(n) { return n + ' ' + (1 === n ? T.day : T.days); }

		function matches(text) {
			var t = norm(text).trim();
			if (!t) {
				var top = COUNTRIES.filter(function (c) { return TOP.indexOf(c[0]) > -1; }).sort(function (a, b) { return TOP.indexOf(a[0]) - TOP.indexOf(b[0]); });
				var more = COUNTRIES.filter(function (c) { return TOP.indexOf(c[0]) < 0; });
				return top.concat(more.filter(function (c) { return !c[2]; }), more.filter(function (c) { return c[2]; }));
			}
			var starts = [], rest = [];
			COUNTRIES.forEach(function (c) {
				var n = norm(c[1]);
				if (0 === n.indexOf(t)) starts.push(c);
				else if (n.indexOf(t) > -1 || norm(c[3]).indexOf(t) > -1) rest.push(c);
			});
			return starts.concat(rest);
		}
		function renderList() {
			var text = q.value, found = matches(text), html = '', group = null;
			found.forEach(function (c, i) {
				var g = TOP.indexOf(c[0]) > -1 ? T.popular : (c[2] ? T.regions : T.countries);
				if (!text.trim() && g !== group) html += '<li class="caw-esim-grp" role="presentation">' + esc(g) + '</li>';
				group = g;
				html += '<li role="option" id="caw-esim-o' + i + '" data-i="' + i + '" aria-selected="' + (i === active) + '"' + (i === active ? ' class="is-active"' : '') + '>' + flag(c) + esc(c[1]) + (c[2] ? ' <small>' + esc(T.regions.replace(/s$/, '')) + '</small>' : '') + '</li>';
			});
			list.innerHTML = html || '<li class="caw-esim-grp" role="presentation">' + esc(T.nores) + '</li>';
			list._found = found;
			list.hidden = false;
			q.setAttribute('aria-expanded', 'true');
			if (active > -1) q.setAttribute('aria-activedescendant', 'caw-esim-o' + active); else q.removeAttribute('aria-activedescendant');
			var a = list.querySelector('.is-active');
			if (a) a.scrollIntoView({ block: 'nearest' });
		}
		function closeList() { list.hidden = true; q.setAttribute('aria-expanded', 'false'); q.removeAttribute('aria-activedescendant'); active = -1; }

		function pick(c) {
			q.value = c[1];
			closeList();
			load(c);
		}
		function setCta(text, on) { label.textContent = text; buy.disabled = !on; }
		function load(c) {
			var mine = ++token;
			country = c; plans = []; chosen = null; tab = 'fixed'; dayFilter = 0;
			msg.hidden = true; row.hidden = true;
			setCta(T.pick, false);
			area.innerHTML = '<p class="caw-fox-wait"><span class="caw-fox-spinner" aria-hidden="true"></span>' + esc(T.load) + '</p>';
			fetch(ajax + '?action=caw_esim_plans&id=' + encodeURIComponent(id) + '&country=' + encodeURIComponent(c[0]))
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (mine !== token) return;
					if (!j || !j.success) { area.innerHTML = '<p class="caw-esim-msg">' + esc(j && j.data ? j.data : T.fail) + '</p>'; return; }
					plans = j.data.plans;
					if (!plans.some(function (p) { return !p.daily; })) tab = 'daily';
					render();
				})
				.catch(function () { if (mine === token) area.innerHTML = '<p class="caw-esim-msg">' + esc(T.fail) + '</p>'; });
		}
		function render() {
			if (!plans.length) { area.innerHTML = '<p class="caw-esim-msg">' + esc(T.none) + '</p>'; return; }
			var fixed = plans.filter(function (p) { return !p.daily; }), daily = plans.filter(function (p) { return p.daily; });
			var html = '';
			if (fixed.length && daily.length) {
				html += '<div class="caw-esim-tabs" role="tablist">'
					+ '<button type="button" role="tab" data-tab="fixed" aria-selected="' + ('fixed' === tab) + '" tabindex="' + ('fixed' === tab ? 0 : -1) + '">' + esc(T.fixed) + '</button>'
					+ '<button type="button" role="tab" data-tab="daily" aria-selected="' + ('daily' === tab) + '" tabindex="' + ('daily' === tab ? 0 : -1) + '">' + esc(T.daily) + '</button></div>';
			}
			var show = 'daily' === tab ? daily : fixed;
			if ('daily' === tab) {
				var seen = {}, opts = [];
				daily.forEach(function (p) { if (!seen[p.days]) { seen[p.days] = 1; opts.push(p.days); } });
				if (opts.length > 1) {
					if (opts.indexOf(dayFilter) < 0) dayFilter = opts[0];
					html += '<div class="caw-esim-days" role="group">' + opts.map(function (d) {
						return '<button type="button" data-days="' + d + '" aria-pressed="' + (d === dayFilter) + '">' + esc(daysTxt(d)) + '</button>';
					}).join('') + '</div>';
					show = daily.filter(function (p) { return p.days === dayFilter; });
				}
			}
			var hotAll = show.every(function (p) { return p.hot; });
			if ('daily' === tab) html += '<p class="caw-esim-info">' + esc(T.cap) + '</p>';
			if (hotAll) html += '<p class="caw-esim-info">' + esc(T.hotall) + '</p>';
			html += '<div class="caw-esim-grid" role="radiogroup" aria-label="' + esc(country[1]) + '">' + show.map(function (p) {
				var on = chosen === p.k;
				return '<button type="button" class="caw-esim-plan' + (on ? ' is-on' : '') + '" role="radio" aria-checked="' + on + '" data-k="' + esc(p.k) + '">'
					+ '<span class="caw-esim-pdata">' + esc(p.data) + '</span>'
					+ '<span class="caw-esim-pdays">' + esc(daysTxt(p.days)) + (p.after ? ', ' + esc(p.after) : '') + (!hotAll && p.hot ? ', ' + esc(T.hot) : '') + '</span>'
					+ '<span class="caw-esim-pprice">$' + esc(p.price) + '</span></button>';
			}).join('') + '</div>';
			var cur = plans.filter(function (p) { return p.k === chosen; })[0];
			if (cur && cur.note) html += '<p class="caw-esim-warn">' + esc(cur.note) + '</p>';
			area.innerHTML = html;
		}
		function choose(k) {
			chosen = k;
			var p = plans.filter(function (x) { return x.k === k; })[0];
			priceEl.textContent = '$' + p.price;
			perEl.innerHTML = flag(country) + esc(country[1] + ', ' + p.data + ', ' + daysTxt(p.days) + (p.after ? ', ' + p.after : ''));
			row.hidden = false;
			setCta(T.buy, true);
			render();
			buy.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); // on phones it sits below the whole list
		}

		q.addEventListener('focus', function () { q.select(); active = -1; renderList(); });
		q.addEventListener('input', function () { active = -1; renderList(); });
		q.addEventListener('keydown', function (e) {
			var n = list._found ? list._found.length : 0;
			if ('ArrowDown' === e.key || 'ArrowUp' === e.key) {
				e.preventDefault();
				if (list.hidden) { renderList(); return; }
				if (n) { active = (active + ('ArrowDown' === e.key ? 1 : n - 1 + (active < 0 ? 1 : 0))) % n; renderList(); }
			} else if ('Enter' === e.key) {
				e.preventDefault();
				if (!list.hidden && n) pick(list._found[active > -1 ? active : 0]);
			} else if ('Escape' === e.key) {
				closeList();
			}
		});
		// mousedown, not click: the input's blur closes the list before a click lands.
		list.addEventListener('mousedown', function (e) {
			var li = e.target.closest('[data-i]');
			e.preventDefault();
			if (li) pick(list._found[+li.getAttribute('data-i')]);
		});
		q.addEventListener('blur', function () { closeList(); if (country) q.value = country[1]; });

		area.addEventListener('click', function (e) {
			var t = e.target.closest('[data-tab]'), d = e.target.closest('[data-days]'), p = e.target.closest('[data-k]');
			if (t) { tab = t.getAttribute('data-tab'); render(); }
			else if (d) { dayFilter = +d.getAttribute('data-days'); render(); }
			else if (p) { choose(p.getAttribute('data-k')); }
		});
		buy.addEventListener('click', function () {
			if (!chosen || !country) return;
			buy.disabled = true; msg.hidden = true;
			label.textContent = '…';
			var body = new URLSearchParams();
			body.set('action', 'caw_esim_add'); body.set('id', id); body.set('country', country[0]); body.set('k', chosen);
			fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j && j.success) { window.location.href = j.data.url; return; }
					msg.textContent = j && j.data ? j.data : T.fail; msg.hidden = false; setCta(T.buy, true);
				})
				.catch(function () { msg.textContent = T.fail; msg.hidden = false; setCta(T.buy, true); });
		});

		// ?country=esim-pakistan opens straight on that destination (for links and ads).
		var want = new URLSearchParams(window.location.search).get('country');
		if (want) {
			var hit = COUNTRIES.filter(function (c) { return c[0] === want; })[0];
			if (hit) pick(hit);
		}
	})();
	</script>
	<?php
}

/* =========================================================================
   Delivery: QR, install links and manual codes (receipt + email)
   ========================================================================= */

/** [ full LPA string, SM-DP+ address, activation code ] or null. */
function caw_esim_parse( $code ) {
	return preg_match( '/^LPA:1\$([^$\s]+)\$([^$\s]*)/i', trim( (string) $code ), $m ) ? array( trim( (string) $code ), $m[1], $m[2] ) : null;
}

/** The activation string of a delivered item. Mock orders return a fake code, so give them a fake LPA to see the layout. */
function caw_esim_lpa( $item_id ) {
	$codes = caw_fox_codes( $item_id );
	$code  = $codes ? (string) $codes[0] : '';
	return caw_esim_parse( $code ) ?: ( caw_fox_is_mock() && $code ? caw_esim_parse( 'LPA:1$test.invalid$' . preg_replace( '/[^A-Za-z0-9]/', '', $code ) ) : null );
}

function caw_esim_qr_sig( $item_id ) {
	return substr( hash_hmac( 'sha256', 'esim-qr|' . (int) $item_id, caw_fox_crypto_key() ), 0, 32 );
}

// The email's image. Signed, so it works in a mail client without a login, and only for that one item.
function caw_esim_ajax_qr() {
	$id  = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	$sig = isset( $_GET['sig'] ) ? sanitize_key( $_GET['sig'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$lpa = $id && hash_equals( caw_esim_qr_sig( $id ), $sig ) ? caw_esim_lpa( $id ) : null;
	if ( ! $lpa ) {
		status_header( 404 );
		exit;
	}
	nocache_headers();
	header( 'Content-Type: image/png' );
	echo caw_qr_png( $lpa[0] ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
add_action( 'wp_ajax_caw_esim_qr', 'caw_esim_ajax_qr' );
add_action( 'wp_ajax_nopriv_caw_esim_qr', 'caw_esim_ajax_qr' );

function caw_esim_html( $item, $for_email ) {
	$st  = function ( $css ) use ( $for_email ) {
		return $for_email ? ' style="' . $css . '"' : '';
	};
	$lpa = caw_esim_lpa( $item->id );
	if ( ! $lpa ) {
		return '<p>' . esc_html__( 'Your eSIM is ready, but we could not show it here. Our team has been notified and will send it to you.', 'mayosis' ) . '</p>';
	}
	list( $full, $smdp, $code ) = $lpa;
	$qr_alt = __( 'eSIM QR code', 'mayosis' );
	$html   = caw_fox_is_mock() ? '<p' . $st( 'margin:0 0 8px;font-weight:bold;color:#b32d2e' ) . '>[TEST — this QR will not install]</p>' : '';
	if ( $for_email ) {
		$src   = add_query_arg( array( 'action' => 'caw_esim_qr', 'item' => (int) $item->id, 'sig' => caw_esim_qr_sig( $item->id ) ), admin_url( 'admin-ajax.php' ) );
		$html .= '<p style="margin:12px 0;text-align:center"><img src="' . esc_url( $src ) . '" width="240" height="240" alt="' . esc_attr( $qr_alt ) . '" style="display:inline-block;background:#fff"></p>';
		$html .= '<p style="margin:0 0 12px;font-size:12px;color:#5a6b88;text-align:center">' . esc_html__( 'If the image does not show, the same QR code is attached to this email.', 'mayosis' ) . '</p>';
	} else {
		$html .= '<div class="caw-esim-qr">' . caw_qr_svg( $full, $qr_alt ) . '</div>';
	}
	$carddata = 'carddata=' . $full; // Apple and Google document the string unencoded
	$btn      = 'display:inline-block;margin:0 6px 8px;padding:10px 16px;border-radius:8px;background:#1e73be;color:#ffffff;text-decoration:none;font-weight:bold';
	$html    .= '<p class="caw-esim-links"' . $st( 'margin:0 0 12px;text-align:center' ) . '>'
		. '<a href="' . esc_url( 'https://esimsetup.apple.com/esim_qrcode_provisioning?' . $carddata ) . '"' . $st( $btn ) . '>' . esc_html__( 'Install on iPhone', 'mayosis' ) . '</a> '
		. '<a href="' . esc_url( 'https://esimsetup.android.com/esim_qrcode_provisioning?' . $carddata ) . '"' . $st( $btn ) . '>' . esc_html__( 'Install on Android', 'mayosis' ) . '</a></p>';

	$c       = caw_fox_cache( $item->product_id, $item->price_id );
	$install = $c && ! empty( $c['install'] ) ? (int) $c['install'] : 0;
	$steps   = array(
		__( 'Connect to Wi-Fi first: the eSIM downloads over the internet.', 'mayosis' ),
		__( 'Open this page on another screen and scan the QR code. iPhone: Settings → Cellular → Add eSIM → Use QR Code. Android: Settings → Network → SIMs → Add eSIM. On the phone you are installing on, tap the install button above instead.', 'mayosis' ),
		__( 'Turn the new eSIM on and choose it for mobile data. If it does not connect after a minute, turn on Data Roaming for it.', 'mayosis' ),
	);
	$html .= '<ol class="caw-esim-steps"' . $st( 'margin:0 0 12px;padding-left:20px;font-size:14px;line-height:1.6' ) . '>';
	foreach ( $steps as $s ) {
		$html .= '<li>' . esc_html( $s ) . '</li>';
	}
	$html .= '</ol>';
	$html .= '<p class="caw-esim-warn"' . $st( 'margin:0 0 12px;font-size:14px' ) . '><strong>' . esc_html__( 'Do not delete the eSIM after installing it.', 'mayosis' ) . '</strong> ' . esc_html__( 'It usually can\'t be installed twice. To stop using it, turn it off instead.', 'mayosis' ) . ' ';
	$html .= esc_html__( 'Your plan\'s days start when the eSIM first connects to a network.', 'mayosis' );
	if ( $install ) {
		/* translators: %d: days */
		$html .= ' ' . esc_html( sprintf( __( 'Install and connect it within %d days of buying.', 'mayosis' ), $install ) );
	}
	$html .= '</p>';

	$manual = array(
		array( __( 'SM-DP+ address', 'mayosis' ), $smdp ),
		array( __( 'Activation code', 'mayosis' ), $code ),
	);
	$body = '';
	foreach ( $manual as $m ) {
		$body .= '<p' . $st( 'margin:0 0 6px;font-size:14px' ) . '>' . esc_html( $m[0] ) . ':<br><code' . $st( 'font-family:Consolas,Menlo,monospace;font-size:13px;word-break:break-all' ) . '>' . esc_html( $m[1] ) . '</code>'
			. ( $for_email ? '' : '<button type="button" class="caw-fox-copy" data-code="' . esc_attr( $m[1] ) . '">' . esc_html__( 'Copy', 'mayosis' ) . '</button>' ) . '</p>';
	}
	$iccid = (string) caw_fox_meta( $item->id, '_caw_fox_external_id' );
	if ( $iccid ) {
		$body .= '<p' . $st( 'margin:0;font-size:13px;color:#5a6b88' ) . '>ICCID: ' . esc_html( $iccid ) . '</p>';
	}
	$title = esc_html__( 'Install by hand', 'mayosis' );
	$html .= $for_email
		? '<div style="margin-top:12px;padding-top:12px;border-top:1px solid #e3e8f0"><p style="margin:0 0 6px;font-weight:bold">' . $title . '</p>' . $body . '</div>'
		: '<details class="caw-fox-guide"><summary>' . $title . '</summary><div class="caw-fox-guide-body">' . $body . '</div></details>';
	return $html;
}

/** QR images to attach to a delivery email (temp files; the caller deletes them after sending). */
function caw_esim_email_files( $items ) {
	$files = array();
	foreach ( $items as $n => $item ) {
		$lpa = caw_fox_meta( $item->id, '_caw_fox_esim' ) ? caw_esim_lpa( $item->id ) : null;
		if ( $lpa ) {
			$path = trailingslashit( get_temp_dir() ) . 'esim-qr-' . (int) $item->id . '.png';
			if ( file_put_contents( $path, caw_qr_png( $lpa[0] ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$files[] = $path;
			}
		}
	}
	return $files;
}

/* =========================================================================
   Product schema: price range of the whole catalogue, full-size image
   ========================================================================= */

// The product only holds plans sold so far, so the range is built up over a few hourly runs.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'caw_esim_range' ) ) {
		wp_schedule_event( time() + 60, 'hourly', 'caw_esim_range' );
	}
} );

add_action( 'caw_esim_range', function () {
	$list  = caw_esim_countries();
	$range = (array) get_option( 'caw_esim_range', array() );
	$next  = (int) get_option( 'caw_esim_range_next', 0 );
	$stop  = time() + 20;
	for ( $n = 0; $n < count( $list ) && time() < $stop; $n++ ) {
		$slug  = $list[ ( $next + $n ) % count( $list ) ]['slug'];
		$plans = caw_esim_plans( $slug );
		if ( null === $plans ) {
			break; // supplier trouble: try again next hour
		}
		$costs = wp_list_pluck( $plans, 'cost' );
		if ( $costs ) {
			$range[ $slug ] = array( min( $costs ), max( $costs ), count( $costs ) );
		} else {
			unset( $range[ $slug ] );
		}
	}
	update_option( 'caw_esim_range', $range, false );
	update_option( 'caw_esim_range_next', $list ? ( $next + $n ) % count( $list ) : 0, false );
} );

add_filter( 'edd_generate_download_structured_data', function ( $data, $download ) {
	if ( ! is_object( $download ) || ! caw_esim_is_store( $download->ID ) ) {
		return $data;
	}
	$image = get_the_post_thumbnail_url( $download->ID, 'full' );
	if ( $image ) {
		$data['image'] = $image;
	}
	$range = (array) get_option( 'caw_esim_range', array() );
	$low   = $range ? caw_esim_price( $download->ID, min( array_column( $range, 0 ) ) ) : 0;
	$high  = $range ? caw_esim_price( $download->ID, max( array_column( $range, 1 ) ) ) : 0;
	if ( $low && $high ) {
		$data['offers'] = array(
			'@type'         => 'AggregateOffer',
			'priceCurrency' => edd_get_currency(),
			'lowPrice'      => number_format( $low, 2, '.', '' ),
			'highPrice'     => number_format( $high, 2, '.', '' ),
			'offerCount'    => array_sum( array_column( $range, 2 ) ),
			'availability'  => 'https://schema.org/InStock',
			'url'           => get_permalink( $download->ID ),
		);
	}
	return $data;
}, 10, 2 );

/* =========================================================================
   Admin
   ========================================================================= */

add_action( 'add_meta_boxes_download', function () {
	if ( current_user_can( 'manage_shop_settings' ) ) {
		add_meta_box( 'caw-esim', 'eSIM store', 'caw_esim_render_box', 'download', 'normal', 'high' );
	}
} );

function caw_esim_render_box( $post ) {
	$on = caw_esim_is_store( $post->ID );
	$mk = caw_esim_markup( $post->ID );
	wp_nonce_field( 'caw_esim_box', 'caw_esim_nonce' );
	echo '<p><label><input type="checkbox" name="caw_esim_on" value="1"' . checked( $on, true, false ) . '> <strong>This product is the eSIM store</strong> — customers search a country and buy any Foxreload eSIM plan. Nothing else to enter: no product IDs and no price options.</label></p>';
	echo '<p>Markup: <input type="number" step="0.1" min="' . esc_attr( caw_fox_min_markup() ) . '" max="200" name="caw_esim_mk[0]" value="' . esc_attr( isset( $mk[0] ) ? $mk[0] : 20 ) . '" style="width:70px">% on plans costing $' . esc_html( number_format( CAW_ESIM_COST_MIN, 2 ) ) . ' or less, sliding to <input type="number" step="0.1" min="' . esc_attr( caw_fox_min_markup() ) . '" max="200" name="caw_esim_mk[1]" value="' . esc_attr( isset( $mk[1] ) ? $mk[1] : 10 ) . '" style="width:70px">% at $' . esc_html( number_format( CAW_ESIM_COST_MAX, 0 ) ) . ' and above. Rounded up to a shop price. Minimum ' . esc_html( caw_fox_min_markup() ) . '%.</p>';
	if ( $on ) {
		echo '<p>Sold so far: ' . (int) count( (array) get_post_meta( $post->ID, '_caw_esim_rows', true ) ) . ' different plans (they appear as price options by themselves; leave those alone). Mode: <strong>' . ( caw_fox_is_mock() ? 'MOCK (test codes)' : 'LIVE' ) . '</strong>.</p>';
		echo '<p><strong>Good to know</strong> — extra text under the install steps on the receipt and in the email (HTML allowed). Optional.</p>';
		echo '<textarea name="caw_esim_guide" rows="3" class="large-text">' . esc_textarea( (string) get_post_meta( $post->ID, '_caw_fox_guide', true ) ) . '</textarea>';
	}
}

add_action( 'save_post_download', function ( $post_id ) {
	if ( ! isset( $_POST['caw_esim_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['caw_esim_nonce'] ) ), 'caw_esim_box' ) || ! current_user_can( 'manage_shop_settings' ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( empty( $_POST['caw_esim_on'] ) ) {
		delete_post_meta( $post_id, '_caw_esim' );
		return;
	}
	update_post_meta( $post_id, '_caw_esim', 1 );
	$in  = isset( $_POST['caw_esim_mk'] ) ? array_map( 'floatval', (array) wp_unslash( $_POST['caw_esim_mk'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$map = get_post_meta( $post_id, '_caw_fox', true );
	$map = is_array( $map ) ? $map : array( 'items' => array(), 'axes' => array() );
	$map['markup'] = array( 'reg' => array(
		min( 200, max( caw_fox_min_markup(), isset( $in[0] ) ? $in[0] : 20 ) ),
		min( 200, max( caw_fox_min_markup(), isset( $in[1] ) ? $in[1] : 10 ) ),
	) );
	update_post_meta( $post_id, '_caw_fox', $map );
	if ( isset( $_POST['caw_esim_guide'] ) ) {
		$guide = trim( wp_kses_post( wp_unslash( $_POST['caw_esim_guide'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $guide ) {
			update_post_meta( $post_id, '_caw_fox_guide', $guide );
		} else {
			delete_post_meta( $post_id, '_caw_fox_guide' );
		}
	}
	// EDD saves its price table at save_post 10, from the form: put ours back afterwards, and make sure
	// a new store has one option, which is what gives EDD a "From $x" price.
	add_action( 'save_post', function ( $id ) use ( $post_id ) {
		if ( (int) $id !== (int) $post_id ) {
			return;
		}
		$rows = get_post_meta( $post_id, '_caw_esim_rows', true );
		if ( is_array( $rows ) && $rows ) {
			caw_esim_save_rows( $post_id, $rows );
			return;
		}
		foreach ( (array) caw_esim_plans( 'esim-pakistan' ) as $plan ) {
			if ( ! $plan['daily'] ) {
				caw_esim_row( $post_id, $plan, 'Pakistan' );
				break;
			}
		}
	}, 99 );
}, 100 );
