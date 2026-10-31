<?php
/**
 * Foxreload wholesale API — automatic fulfilment of gift codes sold through EDD.
 *
 * A product opts in by mapping its price options to Foxreload product IDs
 * (Downloads → Edit → "Foxreload supplier" box). From then on:
 *   - stock and wholesale cost are synced hourly; an option whose supplier is out
 *     of stock, or whose cost has eaten the margin, shows as sold out;
 *   - products that need a delivery detail (e.g. a PUBG Player ID) ask for it at checkout;
 *   - when an order completes, each mapped item is bought from Foxreload, and the
 *     codes are stored encrypted on the order item, shown on the receipt and emailed;
 *   - virtual bank cards (e.g. X Premium+) are issued or topped up the same way; the receipt
 *     shows the owner the card details, the bank's live 3-D Secure codes and the card's activity.
 *
 * wp-config.php:
 *   CAW_FOXRELOAD_API_KEY         required.
 *   CAW_FOXRELOAD_MOCK            defaults to TRUE — test orders, fake codes, no balance spent.
 *                                 Production must define it as false to buy real codes.
 *   CAW_FOXRELOAD_WEBHOOK_SECRET  optional; enables the order.completed webhook (Tools → Foxreload).
 *   CAW_FOXRELOAD_MIN_MARGIN      optional, default 0.05 — never buy when cost > price × (1 − margin).
 *   CAW_FOXRELOAD_REPRICE_THRESHOLD  optional, default 0.02 — automatic pricing skips moves smaller than this.
 *
 * Foxreload docs: https://public-api.foxreload.com/openapi.json (the /docs page omits webhooks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
   A. Config + API client
   ========================================================================= */

function caw_fox_enabled() {
	// Codes are stored with libsodium (bundled with PHP 7.2+); without it, stay off rather than store them in clear.
	return defined( 'CAW_FOXRELOAD_API_KEY' ) && '' !== (string) CAW_FOXRELOAD_API_KEY && function_exists( 'sodium_crypto_secretbox' );
}

function caw_fox_is_mock() {
	return ! defined( 'CAW_FOXRELOAD_MOCK' ) || CAW_FOXRELOAD_MOCK;
}

function caw_fox_min_margin() {
	return defined( 'CAW_FOXRELOAD_MIN_MARGIN' ) ? (float) CAW_FOXRELOAD_MIN_MARGIN : 0.05;
}

/**
 * One API call. Returns [ 'code' => HTTP status (0 = network), 'data' => decoded JSON, 'error' => Foxreload error code or '' ].
 */
function caw_fox_api( $method, $path, $body = null ) {
	if ( ! caw_fox_enabled() ) {
		return array( 'code' => 0, 'data' => null, 'error' => 'NOT_CONFIGURED' );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 25,
		'headers' => array(
			'X-API-Key'    => CAW_FOXRELOAD_API_KEY,
			'X-Language'   => 'en',
			'X-Currency'   => 'usd',
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$r = wp_remote_request( 'https://public-api.foxreload.com' . $path, $args );
	if ( is_wp_error( $r ) ) {
		return array( 'code' => 0, 'data' => null, 'error' => 'NETWORK: ' . $r->get_error_message() );
	}
	$code  = (int) wp_remote_retrieve_response_code( $r );
	$data  = json_decode( wp_remote_retrieve_body( $r ), true );
	$error = '';
	if ( $code >= 400 ) {
		$error = ( is_array( $data ) && ! empty( $data['code'] ) && is_string( $data['code'] ) ) ? $data['code'] : 'HTTP_' . $code;
	}
	return array( 'code' => $code, 'data' => $data, 'error' => $error );
}

/** Transient failures worth retrying later rather than failing the item. */
function caw_fox_is_transient( $res ) {
	return 0 === $res['code'] || 429 === $res['code'] || $res['code'] >= 500 || 'ORDER_CREATION_TIMEOUT' === $res['error'] || 'ORDER_LOCKED' === $res['error'];
}

function caw_fox_error_text( $res ) {
	if ( is_array( $res['data'] ) && isset( $res['data']['detail'] ) ) {
		$d = $res['data']['detail'];
		return $res['error'] . ' — ' . ( is_string( $d ) ? $d : wp_json_encode( $d ) );
	}
	return $res['error'];
}

/* =========================================================================
   B. Product mapping, sync and the margin guard
   ========================================================================= */

/**
 * `_caw_fox` post meta: [ 'items' => [ price_id => 'product_…' ], 'axes' => [ 'Region', 'Amount' ] ].
 * Non-variable products use price_id 0.
 */
function caw_fox_map( $download_id ) {
	$m = get_post_meta( $download_id, '_caw_fox', true );
	return ( is_array( $m ) && ! empty( $m['items'] ) ) ? $m : array();
}

function caw_fox_item_id( $download_id, $price_id ) {
	$m = caw_fox_map( $download_id );
	$k = (int) $price_id;
	return isset( $m['items'][ $k ] ) ? $m['items'][ $k ] : '';
}

/** Last synced supplier data for one option, or null if never synced. */
function caw_fox_cache( $download_id, $price_id ) {
	$c = get_post_meta( $download_id, '_caw_fox_cache', true );
	$k = (int) $price_id;
	return ( is_array( $c ) && isset( $c[ $k ] ) ) ? $c[ $k ] : null;
}

/**
 * What the customer pays for one option today (sale prices included). Reads the raw rows: the
 * sale plugin swaps in its sale price on the front end only, so edd_get_variable_prices() would
 * give the regular price in wp-admin and the sale price in cron.
 */
function caw_fox_sell_price( $download_id, $price_id ) {
	if ( edd_has_variable_prices( $download_id ) ) {
		$prices = get_post_meta( $download_id, 'edd_variable_prices', true );
		$row    = is_array( $prices ) && isset( $prices[ $price_id ] ) ? $prices[ $price_id ] : array();
		if ( isset( $row['sale_price'] ) && '' !== (string) $row['sale_price'] ) {
			return (float) $row['sale_price'];
		}
		return isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
	}
	return (float) edd_get_download_price( $download_id );
}

/** Supplier delivery type of one option: code, direct_topup, banking_card (issues a virtual card), banking_card_topup, … */
function caw_fox_type( $download_id, $price_id ) {
	$c = caw_fox_cache( $download_id, $price_id );
	return $c && ! empty( $c['type'] ) ? $c['type'] : 'code';
}

function caw_fox_is_card_type( $type ) {
	return in_array( $type, array( 'banking_card', 'banking_card_topup' ), true );
}

/** Delivery types this file knows how to hand over; anything new stays off sale until it is added. */
function caw_fox_supported_type( $type ) {
	return in_array( $type, array( 'code', 'direct_topup', 'banking_card', 'banking_card_topup', 'esim' ), true );
}

/** Activated straight on the buyer's account (Telegram Premium, game top-ups) rather than handed over as a code. */
function caw_fox_is_direct( $download_id, $price_id ) {
	$c = caw_fox_cache( $download_id, $price_id );
	return $c && ( ! empty( $c['svc'] ) || ( isset( $c['type'] ) && 'direct_topup' === $c['type'] ) );
}

/** Mapping box: how an option reaches the buyer, in the owner's words. */
function caw_fox_delivery_label( $c ) {
	$type = isset( $c['type'] ) ? $c['type'] : 'code';
	if ( ! caw_fox_supported_type( $type ) ) {
		$out = $type . ' (not supported yet)';
	} elseif ( 'esim' === $type ) {
		$out = 'eSIM (QR)';
	} elseif ( caw_fox_is_card_type( $type ) ) {
		$out = 'banking_card' === $type ? 'Virtual card' : 'Card top-up';
	} elseif ( ! empty( $c['svc'] ) || 'direct_topup' === $type ) {
		$out = 'Straight to buyer\'s account';
	} else {
		$out = 'Code (emailed)';
	}
	$ask = array_diff( isset( $c['req'] ) ? (array) $c['req'] : array(), array( 'card_id' ) );
	return $out . ( $ask ? ' — asks for ' . implode( ', ', array_map( 'caw_fox_field_label', $ask ) ) : '' );
}

// Supplier allows one per order on every mapped option (Telegram Premium) → same as EDD's "Disable quantity input".
add_filter( 'edd_download_quantity_disabled', function ( $disabled, $download_id ) {
	$map = $disabled ? array() : caw_fox_map( $download_id );
	if ( ! $map ) {
		return $disabled;
	}
	foreach ( array_keys( $map['items'] ) as $pid ) {
		$c = caw_fox_cache( $download_id, $pid );
		if ( ! $c || 1 !== ( isset( $c['max'] ) ? (int) $c['max'] : 0 ) ) {
			return $disabled;
		}
	}
	return true;
}, 10, 2 );

// Adding the same option again bumps its quantity even with the input hidden — cap it at the supplier's per-order limit.
add_action( 'edd_post_add_to_cart', function () {
	foreach ( caw_fox_cart_lines() as $line ) {
		$max = ! empty( $line['cache']['max'] ) ? (int) $line['cache']['max'] : 0;
		if ( $max && (int) $line['item']['quantity'] > $max ) {
			edd_set_cart_item_quantity( $line['item']['id'], $max, $line['item']['options'] );
		}
	}
} );

/** Buyable right now: in stock at the supplier and still profitable at the last sync. */
function caw_fox_available( $download_id, $price_id ) {
	$c = caw_fox_cache( $download_id, $price_id );
	return $c && ! empty( $c['ok'] );
}

/* ---- Automatic pricing: supplier cost + markup, sliding from the cheapest option to the dearest ---- */

/** Only reprice when the price would move by at least this much, so cent-level cost wobbles don't churn prices. */
function caw_fox_reprice_threshold() {
	return defined( 'CAW_FOXRELOAD_REPRICE_THRESHOLD' ) ? (float) CAW_FOXRELOAD_REPRICE_THRESHOLD : 0.02;
}

/** Lowest markup % the margin guard lets through (5% margin = 5.27% markup), rounded up. */
function caw_fox_min_markup() {
	$m = caw_fox_min_margin();
	return ceil( $m / ( 1 - $m ) * 1000 ) / 10;
}

/**
 * `_caw_fox['markup']` = [ 'reg' => [ cheapest %, dearest % ], 'sale' => [ … ] ]; an empty 'reg' means manual prices.
 * Equal ends = a flat markup.
 */
function caw_fox_markup( $download_id ) {
	$m   = caw_fox_map( $download_id );
	$out = array( 'reg' => array(), 'sale' => array() );
	foreach ( array( 'reg', 'sale' ) as $k ) {
		if ( ! empty( $m['markup'][ $k ] ) && 2 === count( $m['markup'][ $k ] ) ) {
			$out[ $k ] = array_map( 'floatval', array_values( $m['markup'][ $k ] ) );
		}
	}
	return $out;
}

/** Markup % for one cost: the cheapest option gets $range[0], the dearest $range[1], log-spaced between. */
function caw_fox_curve_pct( $range, $cost, $cmin, $cmax ) {
	if ( $cmax <= $cmin || $cost <= $cmin ) {
		return $range[0];
	}
	$t = min( 1, ( log( $cost ) - log( $cmin ) ) / ( log( $cmax ) - log( $cmin ) ) );
	return $range[0] + ( $range[1] - $range[0] ) * $t;
}

/** Round UP to a shop price: under $20 → x.x9, under $100 → x.49 / x.99, else x.99 (at most ~1–2.5% added). */
function caw_fox_shop_price( $x ) {
	$c    = (int) ceil( round( $x * 100, 4 ) );
	$step = $c < 2000 ? 10 : ( $c < 10000 ? 50 : 100 );
	$k    = (int) ceil( ( $c - ( $step - 1 ) ) / $step );
	return ( $k * $step + $step - 1 ) / 100;
}

/**
 * Set the regular (and sale) price of every mapped, in-stock option from its cost.
 * Returns change lines for the alert email. $exact (a manual save) skips the small-move threshold.
 */
function caw_fox_reprice( $download_id, $costs, $exact = false ) {
	$mk = caw_fox_markup( $download_id );
	if ( ! $mk['reg'] || ! $costs || ! edd_has_variable_prices( $download_id ) ) {
		return array();
	}
	$rows = get_post_meta( $download_id, 'edd_variable_prices', true );
	if ( ! is_array( $rows ) ) {
		return array();
	}
	$cmin    = min( $costs );
	$cmax    = max( $costs );
	$floor   = caw_fox_min_markup();
	$moved   = function ( $old, $new ) use ( $exact ) {
		return $exact ? abs( $new - $old ) >= 0.005 : ( $old <= 0 || abs( $new - $old ) / $old >= caw_fox_reprice_threshold() );
	};
	$changes = array();
	$dirty   = false;
	foreach ( $costs as $pid => $cost ) {
		if ( ! isset( $rows[ $pid ] ) ) {
			continue;
		}
		$row  = $rows[ $pid ];
		$name = isset( $row['name'] ) ? $row['name'] : (string) $pid;
		$reg  = caw_fox_shop_price( $cost * ( 1 + max( $floor, caw_fox_curve_pct( $mk['reg'], $cost, $cmin, $cmax ) ) / 100 ) );
		$old  = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
		if ( $moved( $old, $reg ) ) {
			$rows[ $pid ]['amount'] = edd_sanitize_amount( $reg );
			$changes[] = sprintf( '%s — %s: price $%s → $%s', get_the_title( $download_id ), $name, number_format( $old, 2 ), number_format( $reg, 2 ) );
			$dirty     = true;
		}
		$old_sale = isset( $row['sale_price'] ) && '' !== (string) $row['sale_price'] ? (float) $row['sale_price'] : 0.0;
		if ( $mk['sale'] ) {
			$sale = caw_fox_shop_price( $cost * ( 1 + max( $floor, caw_fox_curve_pct( $mk['sale'], $cost, $cmin, $cmax ) ) / 100 ) );
			$sale = $sale < min( $reg, (float) $rows[ $pid ]['amount'] ) ? $sale : 0.0; // a "sale" at or above the regular price is no sale
			if ( ( $sale && $moved( $old_sale, $sale ) ) || ( ! $sale && $old_sale ) ) {
				$rows[ $pid ]['sale_price'] = $sale ? edd_sanitize_amount( $sale ) : '';
				$changes[] = sprintf( '%s — %s: sale price %s → %s', get_the_title( $download_id ), $name, $old_sale ? '$' . number_format( $old_sale, 2 ) : 'none', $sale ? '$' . number_format( $sale, 2 ) : 'none' );
				$dirty     = true;
			}
		}
	}
	if ( ! $mk['sale'] ) {
		// Automatic pricing without a sale markup: a leftover hand-typed sale price would undercut it,
		// including on options whose cost didn't come through this time.
		foreach ( array_keys( caw_fox_map( $download_id )['items'] ) as $pid ) {
			if ( isset( $rows[ $pid ]['sale_price'] ) && '' !== (string) $rows[ $pid ]['sale_price'] ) {
				$changes[] = sprintf( '%s — %s: sale price $%s removed', get_the_title( $download_id ), isset( $rows[ $pid ]['name'] ) ? $rows[ $pid ]['name'] : $pid, number_format( (float) $rows[ $pid ]['sale_price'], 2 ) );
				$rows[ $pid ]['sale_price'] = '';
				$dirty = true;
			}
		}
	}
	if ( $dirty ) {
		update_post_meta( $download_id, 'edd_variable_prices', $rows );
		// EDD keeps the lowest option in edd_price for sorting and "From $x".
		update_post_meta( $download_id, 'edd_price', edd_sanitize_amount( min( array_map( function ( $r ) {
			return (float) $r['amount'];
		}, $rows ) ) ) );
	}
	return $changes;
}

/**
 * Refresh stock + cost for every mapped option of one product, reprice it if automatic
 * pricing is on, then decide what is buyable.
 * Returns human-readable lines for options that changed price or availability.
 */
function caw_fox_sync_download( $download_id, $exact = false ) {
	if ( caw_esim_is_store( $download_id ) ) {
		update_post_meta( $download_id, '_caw_fox_synced', time() ); // its plans refresh at add-to-cart and checkout
		return array();
	}
	$map = caw_fox_map( $download_id );
	if ( ! $map ) {
		delete_post_meta( $download_id, '_caw_fox_cache' );
		return array();
	}
	$old     = get_post_meta( $download_id, '_caw_fox_cache', true );
	$old     = is_array( $old ) ? $old : array();
	$cache   = array();
	$fresh   = array();
	foreach ( $map['items'] as $pid => $fox_id ) {
		$res = caw_fox_api( 'GET', '/api/products/' . rawurlencode( $fox_id ) );
		if ( $res['error'] ) {
			if ( caw_fox_is_transient( $res ) && isset( $old[ $pid ] ) ) {
				$cache[ $pid ] = $old[ $pid ]; // keep the last good data through an outage
				continue;
			}
			$cache[ $pid ] = array( 'ok' => false, 'reason' => caw_fox_error_text( $res ), 'at' => time() );
		} else {
			$p    = $res['data'];
			$cost = isset( $p['price'] ) && null !== $p['price'] ? (float) $p['price'] : 0.0;
			$qty  = isset( $p['quantity'] ) ? (int) $p['quantity'] : 0;
			$fresh[ $pid ] = true;
			$cache[ $pid ] = array(
				'ok'     => false,
				'reason' => '',
				'cost'   => $cost,
				'qty'    => $qty,
				'max'    => isset( $p['orderMaxQuantity'] ) ? (int) $p['orderMaxQuantity'] : 0,
				'req'    => isset( $p['requiredNoteFields'] ) ? array_values( (array) $p['requiredNoteFields'] ) : array(),
				'opts'   => isset( $p['noteFieldOptions'] ) ? (array) $p['noteFieldOptions'] : array(),
				'name'   => isset( $p['name'] ) ? (string) $p['name'] : '',
				'guide'  => isset( $p['userGuide'] ) ? (string) $p['userGuide'] : '',
				'type'   => isset( $p['deliveryType'] ) ? (string) $p['deliveryType'] : 'code',
				'svc'    => ! empty( $p['isService'] ),
				'at'     => time(),
			);
		}
	}

	$costs = array();
	foreach ( $fresh as $pid => $_ ) {
		if ( $cache[ $pid ]['qty'] > 0 && $cache[ $pid ]['cost'] > 0 ) {
			$costs[ $pid ] = $cache[ $pid ]['cost'];
		}
	}
	$changes = caw_fox_reprice( $download_id, $costs, $exact );

	foreach ( $cache as $pid => $entry ) {
		if ( isset( $fresh[ $pid ] ) ) {
			$sell   = caw_fox_sell_price( $download_id, $pid );
			$reason = '';
			if ( ! caw_fox_supported_type( $entry['type'] ) ) {
				$reason = 'delivery type "' . $entry['type'] . '" is not supported yet';
			} elseif ( in_array( 'password', $entry['req'], true ) ) {
				// "Auto via login" top-ups: we'd store and email the buyer's game password in plain text.
				$reason = 'asks for the buyer\'s account password (not supported)';
			} elseif ( ! isset( $costs[ $pid ] ) ) {
				$reason = 'out of stock at supplier';
			} elseif ( $entry['cost'] > $sell * ( 1 - caw_fox_min_margin() ) ) {
				$reason = sprintf( 'unprofitable: cost $%s vs price $%s', number_format( $entry['cost'], 2 ), number_format( $sell, 2 ) );
			}
			$cache[ $pid ]['ok']     = '' === $reason;
			$cache[ $pid ]['reason'] = $reason;
		}
		$was = isset( $old[ $pid ] ) ? ! empty( $old[ $pid ]['ok'] ) : null;
		if ( $was !== $cache[ $pid ]['ok'] ) {
			$changes[] = sprintf(
				'%s — %s: %s',
				get_the_title( $download_id ),
				edd_get_price_option_name( $download_id, $pid ) ?: 'price',
				$cache[ $pid ]['ok'] ? 'available again' : 'DISABLED (' . $cache[ $pid ]['reason'] . ')'
			);
		}
	}
	update_post_meta( $download_id, '_caw_fox_cache', $cache );
	update_post_meta( $download_id, '_caw_fox_synced', time() );
	update_option( 'caw_fox_last_sync', time(), false );
	return $changes;
}

function caw_fox_mapped_downloads() {
	return get_posts( array(
		'post_type'      => 'download',
		'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
		'posts_per_page' => 200,
		'fields'         => 'ids',
		'meta_key'       => '_caw_fox', // phpcs:ignore WordPress.DB.SlowDBQuery
	) );
}

/** "Sync now" on the Tools page: every mapped product in one request (admin requests get the time). */
function caw_fox_sync_all() {
	@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$changes = array();
	foreach ( caw_fox_mapped_downloads() as $id ) {
		$changes = array_merge( $changes, caw_fox_sync_download( $id ) );
	}
	if ( $changes ) {
		caw_fox_admin_alert( 'Foxreload prices / availability changed', implode( "\n", $changes ) );
	}
}

/**
 * Background sync, every 10 minutes: refresh the products not synced for an hour, stalest first,
 * stopping after ~20s. One run over every product (~0.7s per option, 60+ options) outlived the
 * host's limit for a cron request and never finished; this keeps each run short.
 * A product whose sync kills the run is skipped for the hour (_caw_fox_sync_try), so it can't
 * block the rest; caw_fox_due_run records each run for the Tools page.
 */
function caw_fox_sync_due() {
	@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$start = microtime( true );
	$run   = array( 'start' => time(), 'end' => 0, 'id' => 0 );
	update_option( 'caw_fox_due_run', $run, false );
	$due   = array();
	foreach ( caw_fox_mapped_downloads() as $id ) {
		$at = max( (int) get_post_meta( $id, '_caw_fox_synced', true ), (int) get_post_meta( $id, '_caw_fox_sync_try', true ) );
		if ( time() - $at >= 55 * MINUTE_IN_SECONDS ) {
			$due[ $id ] = $at;
		}
	}
	asort( $due );
	$changes = array();
	foreach ( array_keys( $due ) as $id ) {
		if ( microtime( true ) - $start > 20 ) {
			break; // the rest waits for the next run
		}
		update_post_meta( $id, '_caw_fox_sync_try', time() );
		$run['id'] = $id;
		update_option( 'caw_fox_due_run', $run, false );
		$changes = array_merge( $changes, caw_fox_sync_download( $id ) );
	}
	$run['end'] = time();
	update_option( 'caw_fox_due_run', $run, false );
	if ( $changes ) {
		caw_fox_admin_alert( 'Foxreload prices / availability changed', implode( "\n", $changes ) );
	}
}
add_action( 'caw_fox_sync_due', 'caw_fox_sync_due' );

add_filter( 'cron_schedules', function ( $s ) {
	$s['caw_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes' );
	$s['caw_ten_minutes']  = array( 'interval' => 600, 'display' => 'Every 10 minutes' );
	return $s;
} );

add_action( 'init', function () {
	if ( ! caw_fox_enabled() ) {
		return;
	}
	if ( wp_next_scheduled( 'caw_fox_sync_all' ) ) {
		wp_clear_scheduled_hook( 'caw_fox_sync_all' ); // the old hourly all-in-one run
	}
	if ( ! wp_next_scheduled( 'caw_fox_sync_due' ) ) {
		wp_schedule_event( time() + 60, 'caw_ten_minutes', 'caw_fox_sync_due' );
	}
	if ( ! wp_next_scheduled( 'caw_fox_reconcile' ) ) {
		wp_schedule_event( time() + 120, 'caw_five_minutes', 'caw_fox_reconcile' );
	}
} );

function caw_fox_admin_alert( $subject, $body ) {
	$to = function_exists( 'edd_get_admin_notice_emails' ) ? edd_get_admin_notice_emails() : get_option( 'admin_email' );
	// Plain-text email: titles arrive texturized ("&#038;", "&#8211;").
	$plain = function ( $s ) {
		return html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	};
	wp_mail( $to, '[Crypto Awaz] ' . $plain( $subject ), $plain( $body ) . "\n\n" . admin_url( 'tools.php?page=caw-foxreload' ) );
}

/* =========================================================================
   C. Storefront: axis labels, live stock, checkout fields
   ========================================================================= */

// "Global – 60 UC" options already split into two axes; name them.
add_filter( 'caw_price_model', function ( $model, $id ) {
	$map = caw_fox_map( $id );
	if ( $map && ! empty( $model['two_axis'] ) && ! empty( $map['axes'][0] ) ) {
		$model['plan_label'] = $map['axes'][0];
		$model['dur_label']  = ! empty( $map['axes'][1] ) ? $map['axes'][1] : $model['dur_label'];
	}
	return $model;
}, 10, 2 );

// Overlay supplier stock on the Purchase Limit stock the product page already understands.
add_filter( 'caw_stock_info', function ( $info, $id ) {
	$map = caw_fox_map( $id );
	if ( ! $map || ! is_array( $info ) ) {
		return $info;
	}
	$make = function ( $pid ) use ( $id ) {
		$c = caw_fox_cache( $id, $pid );
		if ( ! $c || empty( $c['ok'] ) ) {
			return array( 'tracked' => true, 'limit' => -1, 'sold' => 0, 'remaining' => 0, 'soldOut' => true );
		}
		return array( 'tracked' => true, 'limit' => $c['qty'], 'sold' => 0, 'remaining' => $c['qty'], 'soldOut' => false );
	};
	if ( empty( $info['variable'] ) ) {
		return ! empty( $info['soldOut'] ) ? $info : array_merge( $make( 0 ), array( 'variable' => false ) );
	}
	$all_out = true;
	foreach ( $info['options'] as $pid => $o ) {
		if ( isset( $map['items'][ $pid ] ) && empty( $o['soldOut'] ) ) {
			$info['options'][ $pid ] = $make( $pid );
		}
		if ( empty( $info['options'][ $pid ]['soldOut'] ) ) {
			$all_out = false;
		}
	}
	$info['tracked']    = true;
	$info['allSoldOut'] = $all_out;
	return $info;
}, 10, 2 );

/** Cart lines (by cart key) that are Foxreload products. */
function caw_fox_cart_lines() {
	$lines = array();
	foreach ( (array) edd_get_cart_contents() as $key => $item ) {
		$pid    = isset( $item['options']['price_id'] ) ? (int) $item['options']['price_id'] : 0;
		$fox_id = caw_fox_item_id( $item['id'], $pid );
		if ( $fox_id ) {
			$lines[ $key ] = array( 'item' => $item, 'price_id' => $pid, 'cache' => caw_fox_cache( $item['id'], $pid ) );
		}
	}
	return $lines;
}

/**
 * True once per request for a given box, and only while the visible page body is being built.
 * The checkout hook fires 4× per render (CFM fires it once per form pass, the block once more),
 * and AIOSEO renders page content inside <head> to build descriptions — that pass must not
 * use up the one slot. AJAX (gateway switch) re-renders have no <head>, so they always count.
 */
function caw_fox_render_once( $box ) {
	static $done = array();
	if ( ! wp_doing_ajax() && ( ! did_action( 'wp_head' ) || doing_action( 'wp_head' ) ) ) {
		return false;
	}
	if ( isset( $done[ $box ] ) ) {
		return false;
	}
	$done[ $box ] = true;
	return true;
}

function caw_fox_field_label( $field ) {
	$labels = array(
		'character_id'   => __( 'Player ID', 'mayosis' ),
		'account_id'     => __( 'Player ID', 'mayosis' ),
		'user_id'        => __( 'User ID', 'mayosis' ),
		'username'       => __( 'Username', 'mayosis' ),
		'server'         => __( 'Server', 'mayosis' ),
		'server_id'      => __( 'Server ID', 'mayosis' ),
		'server_region'  => __( 'Server region', 'mayosis' ),
		'region'         => __( 'Region', 'mayosis' ),
		'platform'       => __( 'Platform', 'mayosis' ),
		'character_name' => __( 'Character name', 'mayosis' ),
		'login'          => __( 'Account login (email)', 'mayosis' ),
		'genshin_uid'    => __( 'UID', 'mayosis' ),
		'kingdom_id'     => __( 'Kingdom ID', 'mayosis' ),
		'lord_id'        => __( 'Lord ID', 'mayosis' ),
		'card_id'        => __( 'Card to renew', 'mayosis' ),
	);
	return isset( $labels[ $field ] ) ? $labels[ $field ] : ucwords( str_replace( '_', ' ', $field ) );
}

/** Tidy what the buyer typed: "@name" or a t.me / profile link becomes the bare username. */
function caw_fox_clean_note( $field, $v ) {
	$v = trim( sanitize_text_field( $v ) );
	if ( 'username' === $field ) {
		$v = preg_replace( '#^(?:https?://)?(?:www\.)?(?:t\.me|telegram\.me|[a-z0-9.-]+\.[a-z]{2,})/#i', '', $v );
		$v = ltrim( trim( $v, " /\t" ), '@' );
	}
	return $v;
}

/** Numeric game IDs are the common typo source — insist on digits for them. */
function caw_fox_field_is_numeric( $field ) {
	return in_array( $field, array( 'character_id', 'account_id', 'user_id', 'server_id' ), true );
}

add_action( 'edd_purchase_form_user_info_fields', function () {
	$rows     = '';
	$game_ids = false;
	$hinted   = array();
	foreach ( caw_fox_cart_lines() as $key => $line ) {
		$req = ! empty( $line['cache']['req'] ) ? $line['cache']['req'] : array();
		// The product's own "Checkout note" (where to find the ID / username), once per product.
		$hint = $req && array_diff( $req, array( 'card_id' ) ) ? trim( (string) get_post_meta( $line['item']['id'], '_caw_fox_hint', true ) ) : '';
		if ( '' !== $hint && empty( $hinted[ $line['item']['id'] ] ) ) {
			$hinted[ $line['item']['id'] ] = true;
			$rows .= '<p class="caw-fox-note">' . wp_kses( $hint, array( 'strong' => array(), 'em' => array(), 'b' => array(), 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ) . '</p>';
		}
		foreach ( $req as $field ) {
			$name  = 'caw_fox_note[' . (int) $key . '][' . sanitize_key( $field ) . ']';
			$id    = 'caw-fox-' . (int) $key . '-' . sanitize_key( $field );
			$value = isset( $_POST['caw_fox_note'][ $key ][ $field ] ) ? sanitize_text_field( wp_unslash( $_POST['caw_fox_note'][ $key ][ $field ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$title = edd_get_cart_item_name( $line['item'] );
			$opts  = ! empty( $line['cache']['opts'][ $field ] ) ? (array) $line['cache']['opts'][ $field ] : array();
			if ( 'card_id' === $field ) {
				// A renewal tops up a card this customer already bought — offer only theirs, never a free-text id.
				$opts = caw_fox_card_options( get_current_user_id(), $line['item']['id'] );
				if ( ! $opts ) {
					$rows .= '<p class="caw-fox-field caw-fox-nocard">' . esc_html( sprintf( __( '%s tops up a card you bought from us. Please log in with the account you used for that order.', 'mayosis' ), $title ) ) . '</p>';
					continue;
				}
			} else {
				$game_ids = true;
			}
			$rows .= '<p class="caw-fox-field">';
			$rows .= '<label class="edd-label" for="' . esc_attr( $id ) . '">' . esc_html( caw_fox_field_label( $field ) . ' — ' . $title ) . ' <span class="edd-required-indicator">*</span></label>';
			if ( $opts ) {
				$rows .= '<select class="edd-select required" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" required>';
				foreach ( $opts as $o ) {
					$ov    = is_array( $o ) ? ( isset( $o['value'] ) ? $o['value'] : reset( $o ) ) : $o;
					$ol    = is_array( $o ) && isset( $o['label'] ) ? $o['label'] : $ov;
					$rows .= '<option value="' . esc_attr( $ov ) . '"' . selected( $value, $ov, false ) . '>' . esc_html( $ol ) . '</option>';
				}
				$rows .= '</select>';
			} else {
				$numeric = caw_fox_field_is_numeric( $field );
				$rows   .= '<input class="edd-input required" type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" required autocomplete="off" spellcheck="false" maxlength="64"'
					. ( $numeric ? ' inputmode="numeric" pattern="[0-9]{5,20}"' : '' ) . '>';
			}
			$rows .= '</p>';
		}
	}
	if ( '' === $rows || ! caw_fox_render_once( 'checkout-fields' ) ) {
		return;
	}
	echo '<div class="caw-fox-fields">';
	echo '<p class="caw-fox-fields-intro">' . ( $game_ids
		? esc_html__( 'Your order is delivered to the account below, so please double-check it.', 'mayosis' )
		: esc_html__( 'Choose the card to top up. The money is added to it a minute or two after your payment confirms.', 'mayosis' ) ) . '</p>';
	echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
	echo '</div>';
} );

add_action( 'edd_checkout_error_checks', function ( $valid_data, $post ) {
	$notes = isset( $post['caw_fox_note'] ) && is_array( $post['caw_fox_note'] ) ? $post['caw_fox_note'] : array();
	foreach ( caw_fox_cart_lines() as $key => $line ) {
		$title = edd_get_cart_item_name( $line['item'] );
		if ( ! caw_fox_available( $line['item']['id'], $line['price_id'] ) ) {
			edd_set_error( 'caw_fox_unavailable_' . $key, sprintf( __( '%s is out of stock right now. Please remove it from your cart.', 'mayosis' ), $title ) );
			continue;
		}
		// Card details and bank codes are shown only to the logged-in owner, so a card needs an account.
		$type = caw_fox_type( $line['item']['id'], $line['price_id'] );
		if ( caw_fox_is_card_type( $type ) && ! is_user_logged_in() && empty( $valid_data['need_new_user'] ) && empty( $valid_data['need_user_login'] ) ) {
			edd_set_error( 'caw_fox_account_' . $key, sprintf( __( 'Please log in or create an account to buy %s. Your card details are kept in your account.', 'mayosis' ), $title ) );
			continue;
		}
		$qty = edd_get_cart_item_quantity( $line['item']['id'], $line['item']['options'] );
		if ( ! empty( $line['cache']['max'] ) && $qty > $line['cache']['max'] ) {
			edd_set_error( 'caw_fox_qty_' . $key, sprintf( __( 'You can buy at most %1$d of %2$s per order.', 'mayosis' ), $line['cache']['max'], $title ) );
		}
		foreach ( (array) $line['cache']['req'] as $field ) {
			$v = isset( $notes[ $key ][ $field ] ) ? caw_fox_clean_note( $field, wp_unslash( $notes[ $key ][ $field ] ) ) : '';
			if ( 'card_id' === $field ) {
				if ( ! isset( caw_fox_user_cards( get_current_user_id(), $line['item']['id'] )[ $v ] ) ) {
					edd_set_error( 'caw_fox_note_' . $key . '_card', sprintf( __( 'Please choose which of your cards to top up with %s. Log in first if you are not logged in.', 'mayosis' ), $title ) );
				}
				continue;
			}
			if ( '' === $v ) {
				edd_set_error( 'caw_fox_note_' . $key . '_' . $field, sprintf( __( 'Please enter your %1$s for %2$s.', 'mayosis' ), caw_fox_field_label( $field ), $title ) );
			} elseif ( caw_fox_field_is_numeric( $field ) && ! preg_match( '/^\d{5,20}$/', $v ) ) {
				edd_set_error( 'caw_fox_note_' . $key . '_' . $field, sprintf( __( 'The %1$s for %2$s should be numbers only.', 'mayosis' ), caw_fox_field_label( $field ), $title ) );
			} elseif ( 'username' === $field && ! preg_match( '/^[A-Za-z0-9_.]{3,64}$/', $v ) ) {
				edd_set_error( 'caw_fox_note_' . $key . '_' . $field, sprintf( __( 'The Username for %s should be a single word (letters, numbers and _ only), without spaces.', 'mayosis' ), $title ) );
			}
		}
	}
}, 10, 2 );

// Copy the checkout details onto the order items they belong to (cart key == cart_index).
add_action( 'edd_built_order', function ( $order_id, $order_data ) {
	$post  = ! empty( $order_data['post_data'] ) && is_array( $order_data['post_data'] ) ? $order_data['post_data'] : $_POST; // phpcs:ignore WordPress.Security.NonceVerification
	$notes = isset( $post['caw_fox_note'] ) && is_array( $post['caw_fox_note'] ) ? $post['caw_fox_note'] : array();
	if ( ! $notes ) {
		return;
	}
	foreach ( edd_get_order_items( array( 'order_id' => $order_id, 'number' => 100 ) ) as $item ) {
		if ( empty( $notes[ $item->cart_index ] ) || ! is_array( $notes[ $item->cart_index ] ) ) {
			continue;
		}
		$clean = array();
		foreach ( $notes[ $item->cart_index ] as $field => $v ) {
			$clean[ sanitize_key( $field ) ] = caw_fox_clean_note( sanitize_key( $field ), wp_unslash( $v ) );
		}
		edd_update_order_item_meta( $item->id, '_caw_fox_note', $clean );
	}
}, 10, 2 );

/* =========================================================================
   D. Fulfilment
   Item meta: _caw_fox_status (created|pending|balance|delivered|failed|manual),
   _caw_fox_order (Foxreload order id), _caw_fox_remote (its status), _caw_fox_codes
   (encrypted), _caw_fox_service, _caw_fox_error, _caw_fox_try, _caw_fox_emailed,
   _caw_fox_cost_quote (cost the margin guard refused), _caw_fox_cost_ok (cost the owner approved);
   virtual cards: _caw_fox_card (issued) or _caw_fox_card_topup (funded), _caw_fox_card_last4, _caw_fox_card_name.
   ========================================================================= */

/**
 * Order items that are Foxreload products. EDD flips item statuses to "complete" only after
 * edd_complete_purchase has run, so the completion hook asks with $only_complete = false.
 */
function caw_fox_order_items( $order_id, $only_complete = true ) {
	$out = array();
	foreach ( edd_get_order_items( array( 'order_id' => $order_id, 'number' => 100 ) ) as $item ) {
		if ( ( ! $only_complete || 'complete' === $item->status ) && caw_fox_item_id( $item->product_id, $item->price_id ) ) {
			$out[] = $item;
		}
	}
	return $out;
}

function caw_fox_meta( $item_id, $key ) {
	return edd_get_order_item_meta( $item_id, $key, true );
}

function caw_fox_set( $item_id, $key, $value ) {
	edd_update_order_item_meta( $item_id, $key, $value );
}

/**
 * Our order reference at Foxreload. Re-sending it returns the existing order, so a retry can
 * never double-buy. Host + mock flag keep dev test orders from colliding with live order ids.
 */
function caw_fox_key_prefix() {
	$host = preg_replace( '/[^a-z0-9.]+/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	return 'caw-' . $host . '-' . ( caw_fox_is_mock() ? 'm' : '' );
}

function caw_fox_idem_key( $order_id, $item_id ) {
	$try = (int) caw_fox_meta( $item_id, '_caw_fox_try' );
	return caw_fox_key_prefix() . (int) $order_id . '-' . (int) $item_id . ( $try ? '-r' . $try : '' );
}

function caw_fox_note_order( $order_id, $text ) {
	if ( function_exists( 'edd_add_note' ) ) {
		edd_add_note( array( 'object_id' => $order_id, 'object_type' => 'order', 'content' => 'Foxreload: ' . $text ) );
	}
}

add_action( 'edd_complete_purchase', function ( $order_id ) {
	if ( caw_fox_enabled() && caw_fox_order_items( $order_id, false ) ) {
		wp_schedule_single_event( time(), 'caw_fox_fulfil', array( (int) $order_id ) );
	}
}, 20 );

add_action( 'caw_fox_fulfil', 'caw_fox_fulfil' );
function caw_fox_fulfil( $order_id ) {
	$order_id = (int) $order_id;
	$lock     = 'caw_fox_lock_' . $order_id;
	if ( get_transient( $lock ) ) {
		return;
	}
	set_transient( $lock, 1, 120 );

	$order = edd_get_order( $order_id );
	if ( ! $order || 'complete' !== $order->status ) {
		delete_transient( $lock );
		return;
	}
	$waiting = false;
	foreach ( caw_fox_order_items( $order_id ) as $item ) {
		if ( in_array( caw_fox_meta( $item->id, '_caw_fox_status' ), array( 'delivered', 'failed', 'manual' ), true ) ) {
			continue;
		}
		if ( 'waiting' === caw_fox_process_item( $order, $item ) ) {
			$waiting = true;
		}
	}
	caw_fox_send_codes_email( $order );

	if ( $waiting ) {
		$tries = (int) edd_get_order_meta( $order_id, '_caw_fox_polls', true ) + 1;
		edd_update_order_meta( $order_id, '_caw_fox_polls', $tries );
		if ( $tries <= 30 ) {
			wp_schedule_single_event( time() + 60, 'caw_fox_fulfil', array( $order_id ) );
		} elseif ( 31 === $tries ) {
			caw_fox_admin_alert( 'Order #' . $order_id . ' still not delivered', 'Foxreload has not completed this order after 30 minutes. Check it in the order screen.' . "\n" . admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $order_id ) );
		}
	}
	delete_transient( $lock );
}

/**
 * Advance one order item as far as it will go. Returns 'done' or 'waiting' (check again later).
 */
function caw_fox_process_item( $order, $item ) {
	$fox_id   = caw_fox_item_id( $item->product_id, $item->price_id );
	$fox_oid  = caw_fox_meta( $item->id, '_caw_fox_order' );
	$qty      = max( 1, (int) $item->quantity );

	if ( ! $fox_oid ) {
		// Price check against the live wholesale price, never more than the margin allows.
		$prod = caw_fox_api( 'GET', '/api/products/' . rawurlencode( $fox_id ) );
		if ( $prod['error'] ) {
			return caw_fox_is_transient( $prod ) ? 'waiting' : caw_fox_fail( $order, $item, 'product lookup failed: ' . caw_fox_error_text( $prod ) );
		}
		$unit = isset( $prod['data']['price'] ) ? (float) $prod['data']['price'] : 0.0;
		$cost = round( $unit * $qty, 4 );
		$cap  = (float) $item->total * ( 1 - caw_fox_min_margin() );
		if ( $unit <= 0 || (int) $prod['data']['quantity'] < $qty ) {
			return caw_fox_fail( $order, $item, 'out of stock at Foxreload' );
		}
		// The owner can approve a quoted cost for this one item ("Retry at $X"); 2% leeway for a small move.
		$ok = (float) caw_fox_meta( $item->id, '_caw_fox_cost_ok' );
		if ( $cost > $cap && ! ( $ok > 0 && $cost <= $ok * 1.02 ) ) {
			caw_fox_set( $item->id, '_caw_fox_cost_quote', $cost );
			return caw_fox_fail( $order, $item, sprintf( 'supplier cost $%s is above the allowed $%s (customer paid $%s). To buy anyway, press "Retry at $%1$s" on the order screen', number_format( $cost, 2 ), number_format( $cap, 2 ), number_format( (float) $item->total, 2 ) ) );
		}
		$note = caw_fox_meta( $item->id, '_caw_fox_note' );
		$note = is_array( $note ) ? $note : array();
		$key  = caw_fox_idem_key( $order->id, $item->id );
		$send = function ( $note ) use ( $fox_id, $qty, $cost, $key ) {
			return caw_fox_api( 'POST', '/api/orders/', array(
				'items'          => array( array(
					'itemId'     => $fox_id,
					'quantity'   => $qty,
					'totalPrice' => number_format( $cost, 4, '.', '' ), // rejected if the price moved since the lookup
					'note'       => (object) $note,
				) ),
				'idempotencyKey' => $key,
				'isMock'         => caw_fox_is_mock(),
			) );
		};
		$res = $send( $note );
		// Foxreload lists fields as required that the chosen offer then refuses (seen with PUBG
		// UC keys' character_id). The customer redeems the code themselves, so drop those and retry.
		if ( 'ORDER_ITEM_NOTE_FIELD_UNKNOWN' === $res['error'] && $note ) {
			$detail = isset( $res['data']['detail'] ) && is_string( $res['data']['detail'] ) ? $res['data']['detail'] : '';
			$drop   = preg_match( '/unknown note fields:\s*(.+)$/i', $detail, $m ) ? array_map( 'trim', explode( ',', $m[1] ) ) : array_keys( $note );
			$res    = $send( array_diff_key( $note, array_flip( $drop ) ) );
		}
		if ( 'ORDER_ALREADY_EXISTS' === $res['error'] ) {
			$res = caw_fox_api( 'GET', '/api/orders/?idempotencyKey=' . rawurlencode( $key ) . '&limit=1' );
			$res['data'] = isset( $res['data']['items'][0] ) ? $res['data']['items'][0] : null;
		}
		if ( $res['error'] || empty( $res['data']['id'] ) ) {
			if ( caw_fox_is_transient( $res ) || 'OFFER_PRICE_NOT_MATCHED' === $res['error'] ) {
				return 'waiting'; // price ticked between lookup and order; the next pass re-prices
			}
			return caw_fox_fail( $order, $item, 'order rejected: ' . ( $res['error'] ? caw_fox_error_text( $res ) : 'no order id' ) );
		}
		$fox_oid = $res['data']['id'];
		caw_fox_set( $item->id, '_caw_fox_order', $fox_oid );
		caw_fox_set( $item->id, '_caw_fox_status', 'created' );
		caw_fox_note_order( $order->id, sprintf( 'order %s created for %s ($%s%s).', $fox_oid, $item->product_name, number_format( $cost, 2 ), caw_fox_is_mock() ? ', MOCK' : '' ) );
	}

	$fo = caw_fox_api( 'GET', '/api/orders/' . rawurlencode( $fox_oid ) );
	if ( $fo['error'] ) {
		return 'waiting';
	}
	$status = isset( $fo['data']['status'] ) ? $fo['data']['status'] : '';

	if ( 'active' === $status ) {
		$pay = caw_fox_api( 'POST', '/api/orders/' . rawurlencode( $fox_oid ) . '/pay', new stdClass() );
		if ( 'BALANCE_NOT_ENOUGH' === $pay['error'] ) {
			if ( 'balance' !== caw_fox_meta( $item->id, '_caw_fox_status' ) ) {
				caw_fox_set( $item->id, '_caw_fox_status', 'balance' );
				caw_fox_note_order( $order->id, 'waiting — Foxreload balance too low.' );
				caw_fox_admin_alert( 'Top up Foxreload — order #' . $order->id . ' is waiting', 'Your Foxreload balance cannot pay for ' . $item->product_name . '. It will be bought automatically once the balance is topped up (retries for ~30 minutes, then use Retry on the order screen).' );
			}
			return 'waiting';
		}
		if ( $pay['error'] && 'ORDER_PAYMENT_ALREADY_CREATED' !== $pay['error'] && 'ORDER_PAYMENT_NOT_POSSIBLE' !== $pay['error'] ) {
			return caw_fox_is_transient( $pay ) ? 'waiting' : caw_fox_fail( $order, $item, 'payment failed: ' . caw_fox_error_text( $pay ) );
		}
		caw_fox_set( $item->id, '_caw_fox_status', 'pending' );
		$fo = caw_fox_api( 'GET', '/api/orders/' . rawurlencode( $fox_oid ) ); // codes are often there already
		if ( $fo['error'] ) {
			return 'waiting';
		}
		$status = isset( $fo['data']['status'] ) ? $fo['data']['status'] : '';
	}

	return caw_fox_apply_remote( $order, $item, $fo['data'] );
}

/** Act on a Foxreload order as GET /orders/{id} (or the webhook) returns it. */
function caw_fox_apply_remote( $order, $item, $fo ) {
	$status = isset( $fo['status'] ) ? $fo['status'] : '';
	caw_fox_set( $item->id, '_caw_fox_remote', $status );
	$fi = isset( $fo['items'][0] ) ? $fo['items'][0] : array();

	if ( 'completed' === $status ) {
		if ( ! empty( $fi['error'] ) ) {
			return caw_fox_fail( $order, $item, 'supplier error: ' . $fi['error'] );
		}
		$type = isset( $fi['product']['deliveryType'] ) ? $fi['product']['deliveryType'] : '';
		if ( caw_fox_is_card_type( $type ) ) {
			return caw_fox_apply_card( $order, $item, $fi, $type );
		}
		$codes = isset( $fi['externalData'] ) ? array_values( array_filter( array_map( 'strval', (array) $fi['externalData'] ), 'strlen' ) ) : array();
		$is_service = ! empty( $fi['product']['isService'] );
		if ( ! $codes && ! $is_service ) {
			return caw_fox_fail( $order, $item, 'completed without any codes' );
		}
		// A service may still return a code (Telegram: a backup to send the supplier's bot) — keep it.
		caw_fox_set( $item->id, '_caw_fox_codes', caw_fox_encrypt( wp_json_encode( $codes ) ) );
		caw_fox_set( $item->id, '_caw_fox_service', $is_service ? 1 : 0 );
		caw_fox_set( $item->id, '_caw_fox_esim', 'esim' === $type ? 1 : 0 );
		if ( ! empty( $fi['externalId'] ) ) {
			caw_fox_set( $item->id, '_caw_fox_external_id', sanitize_text_field( $fi['externalId'] ) ); // eSIM ICCID
		}
		caw_fox_set( $item->id, '_caw_fox_status', 'delivered' );
		caw_fox_note_order( $order->id, sprintf( 'delivered %d code(s) for %s.', count( $codes ), $item->product_name ) );
		return 'done';
	}
	if ( in_array( $status, array( 'failed', 'refunded', 'cancelled' ), true ) ) {
		$why = ! empty( $fi['error'] ) ? $fi['error'] : 'Foxreload order ' . $status;
		return caw_fox_fail( $order, $item, $why . ( 'refunded' === $status ? ' (refunded to Foxreload balance)' : '' ) );
	}
	if ( 'action_required' === $status ) {
		return caw_fox_fail( $order, $item, 'supplier asked for a confirmation code (login top-up) — not supported, handle manually' );
	}
	return 'waiting'; // paid / processing
}

/**
 * A completed card order. A new card's id comes back in externalData; a top-up returns nothing,
 * the card it funded is the one the customer chose at checkout. Only the id is kept — the card
 * number and CVV are fetched live when the owner asks for them (see section G).
 */
function caw_fox_apply_card( $order, $item, $fi, $type ) {
	if ( 'banking_card' === $type ) {
		$card = ! empty( $fi['externalData'][0] ) ? (string) $fi['externalData'][0] : ( ! empty( $fi['externalId'] ) ? (string) $fi['externalId'] : '' );
		$meta = '_caw_fox_card';
	} else {
		$note = caw_fox_meta( $item->id, '_caw_fox_note' );
		$card = is_array( $note ) && ! empty( $note['card_id'] ) ? (string) $note['card_id'] : '';
		$meta = '_caw_fox_card_topup';
	}
	if ( ! caw_fox_is_card_id( $card ) ) {
		return caw_fox_fail( $order, $item, 'completed without a card id' );
	}
	caw_fox_set( $item->id, $meta, $card );
	$info = caw_fox_api( 'GET', '/api/banking-cards/' . $card );
	if ( ! $info['error'] ) {
		caw_fox_set( $item->id, '_caw_fox_card_last4', sanitize_text_field( (string) $info['data']['last4'] ) );
		caw_fox_set( $item->id, '_caw_fox_card_name', sanitize_text_field( (string) $info['data']['nameOnCard'] ) );
	}
	caw_fox_set( $item->id, '_caw_fox_status', 'delivered' );
	$last4 = (string) caw_fox_meta( $item->id, '_caw_fox_card_last4' );
	caw_fox_note_order( $order->id, sprintf( '%s card •••• %s for %s.', 'banking_card' === $type ? 'issued' : 'topped up', $last4 ?: '?', $item->product_name ) );
	return 'done';
}

function caw_fox_fail( $order, $item, $why ) {
	caw_fox_set( $item->id, '_caw_fox_status', 'failed' );
	caw_fox_set( $item->id, '_caw_fox_error', $why );
	caw_fox_note_order( $order->id, 'FAILED for ' . $item->product_name . ' — ' . $why );
	caw_fox_admin_alert(
		'Delivery failed — order #' . $order->id,
		sprintf( "%s could not be delivered automatically.\nReason: %s\n\nSend it manually, then use \"Mark as delivered\" on the order screen — or fix the cause and press Retry.\n%s", $item->product_name, $why, admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $order->id ) )
	);
	return 'done';
}

// Safety net for anything a missed cron or webhook left behind (last 2 days).
add_action( 'caw_fox_reconcile', function () {
	global $wpdb;
	$t = $wpdb->prefix . 'edd_order_itemmeta';
	$ids = $wpdb->get_col( "SELECT DISTINCT i.order_id FROM {$wpdb->prefix}edd_order_items i JOIN $t m ON m.edd_order_item_id = i.id WHERE m.meta_key = '_caw_fox_status' AND m.meta_value IN ('created','pending','balance') AND i.date_created > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) LIMIT 50" ); // phpcs:ignore
	// Orders that completed but never got a first pass (cron missed entirely).
	$missed = $wpdb->get_col( "SELECT DISTINCT o.id FROM {$wpdb->prefix}edd_orders o JOIN {$wpdb->prefix}edd_order_items i ON i.order_id = o.id LEFT JOIN $t m ON m.edd_order_item_id = i.id AND m.meta_key = '_caw_fox_status' WHERE o.status = 'complete' AND o.type = 'sale' AND m.meta_id IS NULL AND o.date_completed > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) AND o.date_completed < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 MINUTE) LIMIT 100" ); // phpcs:ignore
	foreach ( array_unique( array_merge( $ids, $missed ) ) as $id ) {
		if ( caw_fox_order_items( (int) $id ) ) {
			caw_fox_fulfil( (int) $id );
		}
	}
} );

/* ---- Code storage: encrypted at rest; key from wp-config or the site's salts ---- */

function caw_fox_crypto_key() {
	$src = defined( 'CAW_FOXRELOAD_CODES_KEY' ) ? CAW_FOXRELOAD_CODES_KEY : wp_salt( 'secure_auth' );
	return sodium_crypto_generichash( 'caw-fox-codes|' . $src, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

function caw_fox_encrypt( $plain ) {
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, caw_fox_crypto_key() ) );
}

function caw_fox_decrypt( $stored ) {
	if ( ! is_string( $stored ) || 0 !== strpos( $stored, 'v1:' ) ) {
		return false;
	}
	$raw = base64_decode( substr( $stored, 3 ), true );
	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return false;
	}
	return sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), caw_fox_crypto_key() );
}

function caw_fox_codes( $item_id ) {
	$json = caw_fox_decrypt( caw_fox_meta( $item_id, '_caw_fox_codes' ) );
	$list = $json ? json_decode( $json, true ) : array();
	return is_array( $list ) ? $list : array();
}

/**
 * Activation instructions for an item: the product's own "How to redeem" text if set
 * (supplier guides are machine-translated and can be wrong), else the last synced supplier guide.
 */
function caw_fox_guide( $item ) {
	$own = get_post_meta( $item->product_id, '_caw_fox_guide', true );
	if ( is_string( $own ) && '' !== trim( $own ) ) {
		return force_balance_tags( wp_kses_post( wpautop( $own ) ) );
	}
	$c = caw_fox_cache( $item->product_id, $item->price_id );
	return $c && ! empty( $c['guide'] ) ? force_balance_tags( wp_kses_post( $c['guide'] ) ) : ''; // supplier HTML has stray </p>s
}

/** HTML for the delivered items of an order (receipt + email share it; the email gets inline styles). */
function caw_fox_items_html( $order, $for_email = false ) {
	$st = function ( $css ) use ( $for_email ) {
		return $for_email ? ' style="' . $css . '"' : '';
	};
	$html    = '';
	$pending = false;
	foreach ( caw_fox_order_items( $order->id ) as $item ) {
		$status = caw_fox_meta( $item->id, '_caw_fox_status' );
		$note   = caw_fox_meta( $item->id, '_caw_fox_note' );
		$option = edd_has_variable_prices( $item->product_id ) ? edd_get_price_option_name( $item->product_id, $item->price_id ) : '';
		$html  .= '<div class="caw-fox-item"' . $st( 'margin:0 0 20px;padding:18px;border:1px solid #e3e8f0;border-radius:10px;background:#f5f8fc' ) . '>';
		$html  .= '<p class="caw-fox-product"' . $st( 'margin:0 0 2px;font-size:13px;color:#5a6b88' ) . '>' . esc_html( get_the_title( $item->product_id ) ) . '</p>';
		$html  .= '<h3 class="caw-fox-title"' . $st( 'margin:0 0 10px;font-size:20px' ) . '>' . esc_html( $option ? $option : $item->product_name ) . '</h3>';
		if ( is_array( $note ) && $note ) {
			foreach ( array_diff_key( $note, array( 'card_id' => 1 ) ) as $f => $v ) { // the card panel names the card
				$html .= '<p class="caw-fox-for"' . $st( 'margin:0 0 6px;font-size:14px;color:#5a6b88' ) . '>' . esc_html( caw_fox_field_label( $f ) ) . ': <strong' . $st( 'color:#1d2733' ) . '>' . esc_html( $v ) . '</strong></p>';
			}
		}
		if ( 'delivered' === $status ) {
			if ( caw_fox_item_card( $item->id ) ) {
				$html .= caw_fox_card_html( $item, $for_email );
			} elseif ( caw_fox_meta( $item->id, '_caw_fox_esim' ) ) {
				$html .= caw_esim_html( $item, $for_email );
			} elseif ( caw_fox_meta( $item->id, '_caw_fox_service' ) ) {
				$html .= '<p class="caw-fox-direct"' . $st( 'margin:0 0 8px;font-size:15px' ) . '>&#9989; ' . esc_html__( 'Activated directly on the account above. Nothing to redeem.', 'mayosis' ) . '</p>';
				$refs  = caw_fox_codes( $item->id );
				if ( $refs ) {
					$html .= '<p' . $st( 'margin:0 0 4px;font-size:13px;color:#5a6b88' ) . '>' . esc_html( _n( 'Reference code (keep it in case you contact support):', 'Reference codes (keep them in case you contact support):', count( $refs ), 'mayosis' ) ) . '</p>';
					foreach ( $refs as $ref ) {
						$html .= '<p' . $st( 'margin:0 0 4px' ) . '><code' . $st( 'font-family:Consolas,Menlo,monospace;font-size:14px;word-break:break-all' ) . '>' . esc_html( $ref ) . '</code></p>';
					}
				}
			} else {
				$prefix = caw_fox_is_mock() ? '[TEST — not a real code] ' : '';
				$codes  = caw_fox_codes( $item->id );
				// The email puts heading + codes in one dotted box; the receipt styles them via style.css.
				$html  .= $for_email ? '<div style="margin:16px 0 4px;padding:16px;border:2px dotted #1e73be;border-radius:10px;background:#ffffff">' : '';
				$html  .= '<h4 class="caw-fox-label"' . $st( 'margin:0 0 10px;font-size:17px;font-weight:bold;color:#1e73be' ) . '>' . esc_html( _n( 'Your code:', 'Your codes:', count( $codes ), 'mayosis' ) ) . '</h4>';
				foreach ( $codes as $code ) {
					$is_url = (bool) preg_match( '#^https?://#i', $code );
					$html  .= '<div class="caw-fox-code"' . $st( 'margin:0 0 6px;padding:14px 12px;border-radius:8px;background:#eaf3fb;text-align:center' ) . '>';
					$html  .= '<code' . $st( 'font-family:Consolas,Menlo,monospace;font-size:22px;font-weight:bold;letter-spacing:.08em;color:#1d2733;word-break:break-all' ) . '>' . esc_html( $prefix . $code ) . '</code>';
					if ( $is_url ) {
						$html .= ' <a href="' . esc_url( $code ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open link', 'mayosis' ) . '</a>';
					} elseif ( ! $for_email ) {
						$html .= '<button type="button" class="caw-fox-copy" data-code="' . esc_attr( $code ) . '">' . esc_html__( 'Copy', 'mayosis' ) . '</button>';
					}
					$html .= '</div>';
				}
				$html .= $for_email ? '<p style="margin:8px 0 0;font-size:12px;color:#5a6b88;text-align:center">' . esc_html__( 'Select the code and copy it exactly as shown.', 'mayosis' ) . '</p></div>' : '';
			}
			$guide = caw_fox_guide( $item );
			if ( $guide ) {
				$title = caw_fox_item_card( $item->id ) ? esc_html__( 'How to use your card', 'mayosis' ) : ( caw_fox_meta( $item->id, '_caw_fox_service' ) || caw_fox_meta( $item->id, '_caw_fox_esim' ) ? esc_html__( 'Good to know', 'mayosis' ) : esc_html__( 'How to redeem', 'mayosis' ) );
				$html .= $for_email
					? '<div' . $st( 'margin-top:16px;padding-top:12px;border-top:1px solid #e3e8f0;font-size:14px;line-height:1.6;overflow-wrap:anywhere;word-break:break-word' ) . '><p style="margin:0 0 6px;font-size:16px;font-weight:bold">' . $title . '</p>' . $guide . '</div>'
					: '<details class="caw-fox-guide" open data-caw-collapse><summary>' . $title . '</summary><div class="caw-fox-guide-body">' . $guide . '</div></details>';
			}
		} elseif ( 'manual' === $status ) {
			$html .= '<p>' . esc_html__( 'Delivered by our team — please check your email.', 'mayosis' ) . '</p>';
		} elseif ( 'failed' === $status ) {
			$html .= '<p>' . esc_html__( 'We could not deliver this automatically. Our team has been notified and will send it to you or refund you shortly.', 'mayosis' ) . '</p>';
		} else {
			$pending = true;
			$wait    = caw_fox_is_direct( $item->product_id, $item->price_id )
				? __( 'Activating on your account… this usually takes a few minutes.', 'mayosis' )
				: ( 'esim' === caw_fox_type( $item->product_id, $item->price_id ) ? __( 'Preparing your eSIM… this usually takes under a minute.', 'mayosis' ) : __( 'Preparing your code… this usually takes under a minute.', 'mayosis' ) );
			$html   .= '<p class="caw-fox-wait"><span class="caw-fox-spinner" aria-hidden="true"></span>' . esc_html( $wait ) . '</p>';
		}
		$html .= '</div>';
	}
	return array( 'html' => $html, 'pending' => $pending );
}

/** One email per batch of newly delivered items. */
function caw_fox_send_codes_email( $order ) {
	$fresh = array();
	foreach ( caw_fox_order_items( $order->id ) as $item ) {
		if ( 'delivered' === caw_fox_meta( $item->id, '_caw_fox_status' ) && ! caw_fox_meta( $item->id, '_caw_fox_emailed' ) ) {
			$fresh[] = $item;
		}
	}
	if ( ! $fresh || ! function_exists( 'EDD' ) || empty( EDD()->emails ) ) {
		return;
	}
	$body = caw_fox_items_html( $order, true );
	$msg  = '<p>' . esc_html__( 'Thank you for your order. Here is what you bought:', 'mayosis' ) . '</p>' . $body['html'];
	$msg .= '<p>' . sprintf(
		/* translators: %s: receipt link */
		esc_html__( 'You can view these again anytime on your %s.', 'mayosis' ),
		'<a href="' . esc_url( edd_get_receipt_page_uri( $order->id ) ) . '">' . esc_html__( 'order receipt', 'mayosis' ) . '</a>'
	) . '</p>';
	$is_card = (bool) array_filter( array_map( function ( $item ) {
		return caw_fox_item_card( $item->id );
	}, $fresh ) );
	$all_direct = ! array_filter( $fresh, function ( $item ) {
		return ! caw_fox_meta( $item->id, '_caw_fox_service' );
	} );
	$files   = caw_esim_email_files( $fresh ); // the QR as an attachment too: some mail clients block the image link
	$subject = ( caw_fox_is_mock() ? '[TEST] ' : '' ) . sprintf( $is_card ? __( 'Your card for order #%s is ready', 'mayosis' ) : ( $all_direct ? __( 'Your order #%s has been activated', 'mayosis' ) : ( $files ? __( 'Your eSIM for order #%s is ready', 'mayosis' ) : __( 'Your code for order #%s is ready', 'mayosis' ) ) ), $order->get_number() );
	EDD()->emails->__set( 'heading', __( 'Your order is ready', 'mayosis' ) );
	if ( EDD()->emails->send( $order->email, $subject, $msg, $files ) ) {
		foreach ( $fresh as $item ) {
			caw_fox_set( $item->id, '_caw_fox_emailed', time() );
		}
	}
	array_map( 'wp_delete_file', $files );
}

/**
 * {caw_delivery_intro} for the purchase receipt template: the store's "click the link(s) below to
 * download" line is wrong for cards and codes, which have no files. Put this tag where that line was;
 * it prints one line per kind of item the order holds.
 */
add_action( 'edd_add_email_tags', function () {
	edd_add_email_tag( 'caw_delivery_intro', 'How this order is delivered: download links, a code by email, or a virtual card on the order page.', 'caw_fox_delivery_intro', 'Delivery intro', array( 'order' ) );
} );

function caw_fox_delivery_intro( $order_id ) {
	$kinds = array();
	foreach ( edd_get_order_items( array( 'order_id' => (int) $order_id, 'number' => 100 ) ) as $item ) {
		if ( ! caw_fox_item_id( $item->product_id, $item->price_id ) ) {
			$kinds['files'] = true;
		} elseif ( caw_fox_is_card_type( caw_fox_type( $item->product_id, $item->price_id ) ) ) {
			$kinds['card'] = true;
		} elseif ( 'esim' === caw_fox_type( $item->product_id, $item->price_id ) ) {
			$kinds['esim'] = true;
		} elseif ( caw_fox_is_direct( $item->product_id, $item->price_id ) ) {
			$kinds['direct'] = true;
		} else {
			$kinds['code'] = true;
		}
	}
	$page  = '<a href="' . esc_url( edd_get_receipt_page_uri( $order_id ) ) . '">';
	$lines = array();
	if ( isset( $kinds['card'] ) ) {
		/* translators: 1: opening link tag, 2: closing link tag */
		$lines[] = sprintf( __( 'Your virtual card will be ready on your %1$sorder page%2$s within a few minutes. Log in there to see the card details, your balance and activity, and any bank verification codes. For your security, the card number is never sent by email.', 'mayosis' ), $page, '</a>' );
	}
	if ( isset( $kinds['code'] ) ) {
		$lines[] = sprintf( __( 'Your code will be emailed to you separately within a few minutes. It is also shown on your %1$sorder page%2$s, with the steps to redeem it.', 'mayosis' ), $page, '</a>' );
	}
	if ( isset( $kinds['esim'] ) ) {
		$lines[] = sprintf( __( 'Your eSIM QR code will be emailed to you separately within a few minutes. It is also shown on your %1$sorder page%2$s, with the steps to install it.', 'mayosis' ), $page, '</a>' );
	}
	if ( isset( $kinds['direct'] ) ) {
		$lines[] = sprintf( __( 'Your purchase is activated directly on the account you entered at checkout, usually within a few minutes. We will email you when it is done, and you can follow it on your %1$sorder page%2$s.', 'mayosis' ), $page, '</a>' );
	}
	if ( isset( $kinds['files'] ) ) {
		$lines[] = __( 'Please click on the link(s) below to download your instructions / files:', 'mayosis' );
	}
	return implode( '<br><br>', $lines );
}

/* =========================================================================
   E. Webhook — order.completed carries the codes, so delivery doesn't wait for a poll
   ========================================================================= */

add_action( 'rest_api_init', function () {
	register_rest_route( 'caw/v1', '/foxreload', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // authenticated by the HMAC signature below
		'callback'            => 'caw_fox_webhook',
	) );
} );

function caw_fox_webhook( WP_REST_Request $req ) {
	if ( ! defined( 'CAW_FOXRELOAD_WEBHOOK_SECRET' ) || ! CAW_FOXRELOAD_WEBHOOK_SECRET ) {
		return new WP_REST_Response( array( 'ok' => false ), 503 );
	}
	$raw   = $req->get_body();
	$parts = array();
	foreach ( explode( ',', (string) $req->get_header( 'x-webhook-signature' ) ) as $p ) {
		$kv = explode( '=', trim( $p ), 2 );
		if ( 2 === count( $kv ) ) {
			$parts[ $kv[0] ] = $kv[1];
		}
	}
	$t = isset( $parts['t'] ) ? (int) $parts['t'] : 0;
	if ( ! $t || empty( $parts['v1'] ) || abs( time() - $t ) > 300
		|| ! hash_equals( hash_hmac( 'sha256', $t . '.' . $raw, CAW_FOXRELOAD_WEBHOOK_SECRET ), $parts['v1'] ) ) {
		return new WP_REST_Response( array( 'ok' => false ), 401 );
	}
	$evt = json_decode( $raw, true );
	if ( empty( $evt['id'] ) || get_transient( 'caw_fox_evt_' . md5( $evt['id'] ) ) ) {
		return new WP_REST_Response( array( 'ok' => true ), 200 ); // already handled
	}
	set_transient( 'caw_fox_evt_' . md5( $evt['id'] ), 1, DAY_IN_SECONDS );

	$key = isset( $evt['data']['idempotencyKey'] ) ? (string) $evt['data']['idempotencyKey'] : '';
	$prefix = caw_fox_key_prefix();
	if ( 0 === strpos( $key, $prefix ) && preg_match( '/^(\d+)-(\d+)(?:-r\d+)?$/', substr( $key, strlen( $prefix ) ), $m ) ) {
		caw_fox_fulfil( (int) $m[1] ); // re-reads the order itself; cheap and keeps one code path
	}
	return new WP_REST_Response( array( 'ok' => true ), 200 );
}

/* =========================================================================
   F. Receipt — codes load over AJAX so a cached receipt page can never carry them
   ========================================================================= */

function caw_fox_receipt_box( $order_id ) {
	// Both the 3.x and the legacy receipt hook can fire; show one box, in the page body.
	$items = caw_fox_order_items( $order_id );
	if ( ! $items || ! caw_fox_render_once( 'receipt' ) ) {
		return;
	}
	$cards = array_filter( $items, function ( $item ) {
		return caw_fox_is_card_type( caw_fox_type( $item->product_id, $item->price_id ) );
	} );
	$esims = array_filter( $items, function ( $item ) {
		return 'esim' === caw_fox_type( $item->product_id, $item->price_id );
	} );
	$url  = add_query_arg( array( 'action' => 'caw_fox_codes', 'order' => (int) $order_id ), admin_url( 'admin-ajax.php' ) );
	?>
	<section class="caw-fox-receipt" id="caw-sec-card" data-url="<?php echo esc_url( $url ); ?>" data-card-url="<?php echo esc_url( admin_url( 'admin-ajax.php?action=caw_fox_card' ) ); ?>" data-login="<?php echo esc_url( wp_login_url( edd_get_receipt_page_uri( $order_id ) ) ); ?>" aria-live="polite">
		<h2><?php echo esc_html( count( $cards ) === count( $items ) ? __( 'Your card', 'mayosis' ) : ( count( $esims ) === count( $items ) ? __( 'Your eSIM', 'mayosis' ) : __( 'Your codes', 'mayosis' ) ) ); ?></h2>
		<div class="caw-fox-body"><p class="caw-fox-wait"><?php esc_html_e( 'Loading…', 'mayosis' ); ?></p></div>
		<noscript><p><?php esc_html_e( 'Please turn on JavaScript to see this. If you can\'t, contact us and we\'ll help.', 'mayosis' ); ?></p></noscript>
	</section>
	<script>
	(function () {
		var box = document.querySelector('.caw-fox-receipt');
		if (!box) return;
		var body = box.querySelector('.caw-fox-body'), polls = 0, last = '';
		var phone = window.matchMedia ? window.matchMedia('(max-width: 1023px)') : { matches: false };
		function load() {
			fetch(box.getAttribute('data-url'), { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (!j || !j.success) { body.innerHTML = '<p>' + (j && j.data ? j.data : '') + '</p>'; return; }
					// Re-render only on change, so an open card panel survives another item's polling.
					if (j.data.html !== last) {
						last = j.data.html;
						body.innerHTML = j.data.html;
						// Phones: secondary sections start closed; the card and the code box stay open.
						if (phone.matches) body.querySelectorAll('details[data-caw-collapse]').forEach(function (d) { d.open = false; });
						body.querySelectorAll('.caw-fox-vcard').forEach(cardPanel);
					}
					if (j.data.pending && ++polls < 40) setTimeout(load, 8000);
				})
				.catch(function () {
					if (++polls < 40) setTimeout(load, 8000);
					else body.innerHTML = '<p><?php echo esc_js( __( 'We could not load this right now. Please refresh the page, or contact us.', 'mayosis' ) ); ?></p>';
				});
		}

		function esc(s) {
			return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; });
		}
		function copyBtn(v) {
			return '<button type="button" class="caw-fox-copy" data-code="' + esc(v) + '"><?php echo esc_js( __( 'Copy', 'mayosis' ) ); ?></button>';
		}
		// Clipboard API where allowed, else the old execCommand path (in-app browsers), else select the
		// text so a long-press copies it.
		function copyText(text, btn, node) {
			function done(ok) {
				btn.classList.add(ok ? 'is-copied' : 'is-failed');
				if (btn.classList.contains('caw-fox-copy')) { var t = btn.textContent; btn.textContent = ok ? '✓' : t; setTimeout(function () { btn.textContent = t; }, 1500); }
				setTimeout(function () { btn.classList.remove('is-copied', 'is-failed'); }, 1500);
				if (!ok && node && window.getSelection) { var r = document.createRange(); r.selectNodeContents(node); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
			}
			function legacy() {
				var ta = document.createElement('textarea'), ok = false;
				ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild(ta); ta.select();
				try { ok = document.execCommand('copy'); } catch (e) {}
				document.body.removeChild(ta);
				done(ok);
			}
			if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(function () { done(true); }, legacy);
			else legacy();
		}

		/* ---- Virtual card: details on the card face on request, live bank codes, balance + activity ---- */
		function cardPanel(panel) {
			var url = box.getAttribute('data-card-url') + '&item=' + panel.getAttribute('data-item');
			var reveal = panel.querySelector('.caw-fox-reveal'), label = reveal.querySelector('span');
			var pass = panel.querySelector('.caw-fox-pass'), msg = panel.querySelector('.caw-fox-card-msg'), bal = panel.querySelector('.caw-fox-bal');
			var codes = panel.querySelector('.caw-fox-3ds-body'), ledger = panel.querySelector('.caw-fox-ledger-body'), state = panel.querySelector('.caw-fox-codestate');
			var started = Date.now(), n = 0, timer = null, busy = false, seen = null, vals = {}, hideTimer = null;
			var masks = { pan: panel.getAttribute('data-mask'), exp: '••/••', cvv: '•••', pass: '' };

			function field(f, text) { panel.querySelectorAll('[data-f="' + f + '"]').forEach(function (s) { s.textContent = text; }); }
			function say(html) { msg.innerHTML = html; msg.hidden = !html; }
			function hide() {
				clearTimeout(hideTimer); vals = {};
				Object.keys(masks).forEach(function (f) { field(f, masks[f]); });
				pass.hidden = true;
				panel.classList.remove('is-open');
				reveal.setAttribute('aria-expanded', 'false');
				label.textContent = reveal.getAttribute('data-show');
			}
			function show() {
				reveal.disabled = true; say('');
				label.textContent = '<?php echo esc_js( __( 'Loading…', 'mayosis' ) ); ?>';
				// Give up after 20s so a stalled request ends in "Try again", never a dead button.
				var ctl = window.AbortController ? new AbortController() : null, guard = setTimeout(function () { if (ctl) ctl.abort(); }, 20000);
				fetch(url + '&op=creds', { credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
					.then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
					.then(function (x) {
						clearTimeout(guard);
						reveal.disabled = false;
						label.textContent = reveal.getAttribute('data-show');
						if (!x.j || !x.j.success) {
							say(401 === x.status
								? '<?php echo esc_js( __( 'Your login has expired.', 'mayosis' ) ); ?> <a href="' + esc(box.getAttribute('data-login')) + '"><?php echo esc_js( __( 'Log in again', 'mayosis' ) ); ?></a>'
								: esc(x.j && x.j.data ? x.j.data : '<?php echo esc_js( __( 'Card details are unavailable right now.', 'mayosis' ) ); ?>') + ' <button type="button" class="caw-fox-retry"><?php echo esc_js( __( 'Try again', 'mayosis' ) ); ?></button>');
							return;
						}
						var d = x.j.data;
						vals = { pan: d.pan_raw, exp: d.exp, cvv: d.cvv, name: d.name, pass: d.pass };
						field('pan', d.pan); field('exp', d.exp); field('cvv', d.cvv);
						if (d.pass) { field('pass', d.pass); pass.hidden = false; }
						panel.classList.add('is-open');
						reveal.setAttribute('aria-expanded', 'true');
						label.textContent = reveal.getAttribute('data-hide');
						hideTimer = setTimeout(hide, 60000);
					})
					.catch(function () {
						clearTimeout(guard);
						reveal.disabled = false;
						label.textContent = reveal.getAttribute('data-show');
						say('<?php echo esc_js( __( 'Could not reach the server. Check your connection.', 'mayosis' ) ); ?> <button type="button" class="caw-fox-retry"><?php echo esc_js( __( 'Try again', 'mayosis' ) ); ?></button>');
					});
			}
			reveal.addEventListener('click', function () { if (panel.classList.contains('is-open')) hide(); else show(); });
			panel.addEventListener('click', function (e) {
				if (e.target.closest('.caw-fox-retry')) { show(); return; }
				var c = e.target.closest('.caw-fox-ic');
				if (c && vals[c.getAttribute('data-copy')]) copyText(vals[c.getAttribute('data-copy')], c, c.previousElementSibling);
			});

			function renderCodes(list) {
				document.querySelectorAll('.caw-rcpt-nav [data-sec="code"]').forEach(function (a) { a.classList.toggle('has-code', list.length > 0); });
				var key = list.map(function (c) { return c.id; }).join(',');
				if (key === seen) return;
				seen = key;
				if (!list.length) {
					codes.innerHTML = '<p class="caw-fox-wait"><span class="caw-fox-spinner" aria-hidden="true"></span><?php echo esc_js( __( 'Waiting for a code… If your bank asks for one while you pay, it appears here within seconds.', 'mayosis' ) ); ?></p>';
					return;
				}
				codes.innerHTML = list.map(function (c, i) {
					return '<div class="caw-fox-code' + (i ? ' caw-fox-code-old' : '') + '"><code>' + esc(c.code) + '</code>' + copyBtn(c.code) + '</div>'
						+ '<p class="caw-fox-3ds-meta">' + (c.amount || c.merchant
							? esc('<?php echo esc_js( __( 'Code for your', 'mayosis' ) ); ?>' + (c.amount ? ' ' + c.amount : '') + ' <?php echo esc_js( __( 'payment', 'mayosis' ) ); ?>' + (c.merchant ? ' <?php echo esc_js( __( 'at', 'mayosis' ) ); ?> ' + c.merchant : '')) + ' '
							: '') + '<span data-exp="' + c.expires + '"></span></p>';
				}).join('');
				tick();
			}
			function tick() {
				codes.querySelectorAll('[data-exp]').forEach(function (s) {
					var left = Math.max(0, +s.getAttribute('data-exp') - Math.floor(Date.now() / 1000));
					s.textContent = left ? '· <?php echo esc_js( __( 'expires in', 'mayosis' ) ); ?> ' + Math.floor(left / 60) + ':' + ('0' + left % 60).slice(-2) : '· <?php echo esc_js( __( 'expired', 'mayosis' ) ); ?>';
				});
			}
			function renderLedger(d) {
				var rows = d.tx.map(function (t) {
					return '<li class="is-' + esc(t.status) + '"><span class="caw-fox-tx-m">' + esc(t.merchant) + '</span><strong>' + esc(t.amount) + '</strong>'
						+ '<small>' + esc(t.date) + ' · <span class="caw-fox-tx-s">' + esc(t.label) + '</span></small></li>';
				}).join('');
				bal.textContent = d.balance;
				ledger.innerHTML = rows ? '<ul class="caw-fox-tx">' + rows + '</ul>' : '<p class="caw-fox-muted"><?php echo esc_js( __( 'No card activity yet.', 'mayosis' ) ); ?></p>';
			}
			// Bank codes live for minutes, so look every 5s — for 15 minutes, while the tab is visible.
			function poll() {
				timer = null;
				if (Date.now() - started > 15 * 60000) {
					state.textContent = '<?php echo esc_js( __( 'Stopped checking. Tap the button if your bank sends a new code.', 'mayosis' ) ); ?>';
					return;
				}
				if (document.hidden) { timer = setTimeout(poll, 5000); return; }
				var full = n++ % 6 === 0; // balance + activity every 30s
				busy = true;
				fetch(url + '&op=live' + (full ? '&full=1' : ''), { credentials: 'same-origin', cache: 'no-store' })
					.then(function (r) { return r.json(); })
					.then(function (j) {
						if (j && j.success) {
							renderCodes(j.data.codes);
							if (j.data.ledger) renderLedger(j.data.ledger);
						}
					})
					.catch(function () {})
					.then(function () { busy = false; timer = setTimeout(poll, 5000); });
			}
			panel.addEventListener('click', function (e) {
				if (!e.target.closest('.caw-fox-resume')) return;
				started = Date.now(); n = 0;
				state.textContent = '<?php echo esc_js( __( 'Checking for new codes every 5 seconds', 'mayosis' ) ); ?>';
				if (!busy) { clearTimeout(timer); poll(); } // a running check carries on with the new window
			});
			setInterval(tick, 1000);
			poll();
		}
		box.addEventListener('click', function (e) {
			var b = e.target.closest('.caw-fox-copy');
			if (b) copyText(b.getAttribute('data-code'), b, b.previousElementSibling);
		});
		load();
	})();
	</script>
	<?php
}
/*
 * Receipt layout for orders holding cards or codes (the store's receipt is EDD's receipt block, whose
 * views can't be overridden): the before-table hook opens a two-column wrapper — sticky "on this page"
 * menu on desktop — puts the card / code box first, and opens a collapsible "Order details"; the
 * after-table hook closes it. Both hooks fire only for viewers allowed to see the items, so the
 * wrapper is opened only when that is true. Orders with only downloads keep EDD's own layout.
 */
function caw_fox_receipt_open( $order ) {
	$kinds   = caw_fox_receipt_kinds( $order->id );
	$session = edd_get_purchase_session();
	$sees    = is_user_logged_in() || ( ! empty( $session['purchase_key'] ) && $session['purchase_key'] === $order->payment_key );
	if ( ! $kinds || ! $sees || ! caw_fox_render_once( 'layout' ) ) {
		caw_fox_receipt_box( $order->id );
		return;
	}
	$files = false;
	foreach ( $order->get_items() as $item ) {
		if ( ! caw_fox_item_id( $item->product_id, $item->price_id ) ) {
			$files = true;
		}
	}
	// [ data-sec, label, CSS selector of the target ].
	if ( isset( $kinds['card'] ) ) {
		$nav = array(
			array( 'card', __( 'Your card', 'mayosis' ), '#caw-sec-card' ),
			array( 'code', __( 'Verification code', 'mayosis' ), '.caw-fox-sec-code' ),
			array( 'activity', __( 'Balance & activity', 'mayosis' ), '.caw-fox-ledger' ),
			array( 'guide', __( 'How to use', 'mayosis' ), '.caw-fox-guide' ),
		);
	} else {
		$all_esim = ! array_filter( caw_fox_order_items( $order->id ), function ( $item ) {
			return 'esim' !== caw_fox_type( $item->product_id, $item->price_id );
		} );
		$nav = array(
			array( 'card', $all_esim ? __( 'Your eSIM', 'mayosis' ) : __( 'Your code', 'mayosis' ), '#caw-sec-card' ),
			array( 'guide', $all_esim ? __( 'Install by hand', 'mayosis' ) : __( 'How to redeem', 'mayosis' ), '.caw-fox-guide' ),
		);
	}
	$details = $files ? __( 'Downloads & order details', 'mayosis' ) : __( 'Order details', 'mayosis' );
	$nav[]   = array( 'order', $details, '#caw-sec-order' );

	echo '<div class="caw-rcpt" id="caw-rcpt"><nav class="caw-rcpt-nav" aria-label="' . esc_attr__( 'On this page', 'mayosis' ) . '">';
	echo '<p class="caw-rcpt-nav-title">' . esc_html( sprintf( __( 'Order #%s', 'mayosis' ), $order->get_number() ) ) . '</p>';
	foreach ( $nav as $i => $link ) {
		echo '<a href="#caw-rcpt"' . ( 0 === $i ? ' class="is-active"' : '' ) . ' data-sec="' . esc_attr( $link[0] ) . '" data-target="' . esc_attr( $link[2] ) . '">' . esc_html( $link[1] ) . '</a>';
	}
	echo '</nav><div class="caw-rcpt-main">';
	caw_fox_receipt_box( $order->id );
	// Downloads must stay in sight on phones, so only a details-only section collapses there.
	echo '<details class="caw-rcpt-sec" id="caw-sec-order" open' . ( $files ? '' : ' data-caw-collapse' ) . '><summary>'
		. esc_html( $details . ' · #' . $order->get_number() . ' · ' . edd_get_status_label( $order->status ) ) . '</summary>';
	$GLOBALS['caw_rcpt_open'] = true;
}
add_action( 'edd_order_receipt_before_table', 'caw_fox_receipt_open' );

add_action( 'edd_order_receipt_after_table', function () {
	if ( empty( $GLOBALS['caw_rcpt_open'] ) ) {
		return;
	}
	$GLOBALS['caw_rcpt_open'] = false;
	?>
	</details></div></div>
	<script>
	(function () {
		var root = document.getElementById('caw-rcpt');
		if (!root) return;
		var links = root.querySelectorAll('.caw-rcpt-nav a');
		if (window.matchMedia && window.matchMedia('(max-width: 1023px)').matches) {
			root.querySelectorAll('.caw-rcpt-main > details[data-caw-collapse]').forEach(function (d) { d.open = false; });
		}
		function target(a) { return root.querySelector(a.getAttribute('data-target')); }
		root.querySelector('.caw-rcpt-nav').addEventListener('click', function (e) {
			var a = e.target.closest('a[data-target]'), t = a && target(a);
			if (!t) return;
			e.preventDefault();
			var d = t.closest('details');
			if (d) d.open = true;
			t.scrollIntoView({ behavior: 'smooth', block: 'start' });
		});
		// Highlight the section in view (the card panel arrives later over AJAX, so look targets up each time).
		var ticking = false;
		window.addEventListener('scroll', function () {
			if (ticking) return;
			ticking = true;
			requestAnimationFrame(function () {
				ticking = false;
				var active = links[0];
				links.forEach(function (a) { var t = target(a); if (t && t.getBoundingClientRect().top < 160) active = a; });
				links.forEach(function (a) { a.classList.toggle('is-active', a === active); });
			});
		}, { passive: true });
	})();
	</script>
	<?php
}, 99 );
add_action( 'edd_payment_receipt_after_table', function ( $payment ) {
	caw_fox_receipt_box( $payment->ID );
} );

// Gift codes are "Service" downloads with no files: skip the receipt's empty file list. Hooked on the
// final filter after Software Licensing, whose own callback there replaces the value it is given.
add_filter( 'edd_order_receipt_show_download_files', function ( $show, $download_id ) {
	return caw_fox_map( $download_id ) ? false : $show;
}, 20, 2 );

add_action( 'wp_ajax_nopriv_caw_fox_codes', function () {
	nocache_headers();
	wp_send_json_error( esc_html__( 'Please log in to see your codes. They were also emailed to you.', 'mayosis' ), 401 );
} );
add_action( 'wp_ajax_caw_fox_codes', function () {
	nocache_headers();
	$order = edd_get_order( isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only, ownership-checked
	if ( ! $order || ( (int) $order->user_id !== get_current_user_id() && ! current_user_can( 'view_shop_sensitive_data' ) ) ) {
		wp_send_json_error( esc_html__( 'Order not found.', 'mayosis' ), 404 );
	}
	if ( 'complete' !== $order->status ) {
		wp_send_json_success( array( 'html' => '<p class="caw-fox-wait">' . esc_html__( 'Waiting for your payment to confirm…', 'mayosis' ) . '</p>', 'pending' => true ) );
	}
	$out = caw_fox_items_html( $order );
	// The buyer is watching: don't wait for WP-cron (which only runs on uncached hits) — push the
	// order along here, at most every 15s. The lock + idempotency key make overlap with cron harmless.
	if ( $out['pending'] && ! get_transient( 'caw_fox_nudge_' . $order->id ) ) {
		set_transient( 'caw_fox_nudge_' . $order->id, 1, 15 );
		caw_fox_fulfil( $order->id );
		$out = caw_fox_items_html( $order );
	}
	wp_send_json_success( $out );
} );

/* =========================================================================
   G. Virtual bank cards (deliveryType banking_card / banking_card_topup)
   The card lives in our Foxreload account; the buyer gets its number to pay a
   subscription with. We keep only its id: number, CVV and the issuer's one-time
   3-D Secure codes are fetched live, for the logged-in owner, on the receipt.
   ========================================================================= */

function caw_fox_is_card_id( $id ) {
	return is_string( $id ) && (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id );
}

/** The card an order item issued or topped up, or ''. */
function caw_fox_item_card( $item_id ) {
	return (string) ( caw_fox_meta( $item_id, '_caw_fox_card' ) ?: caw_fox_meta( $item_id, '_caw_fox_card_topup' ) );
}

/**
 * Cards issued to a customer, newest first: [ card_id => [ 'last4' => …, 'date' => … ] ].
 * With $download_id, only cards bought from that product: each card is locked to its own service,
 * and Foxreload would happily top up an X card with a Claude renewal, stranding the money.
 */
function caw_fox_user_cards( $user_id, $download_id = 0 ) {
	global $wpdb;
	if ( ! $user_id ) {
		return array();
	}
	$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT m.meta_value AS card, l.meta_value AS last4, o.date_completed AS date
		FROM {$wpdb->prefix}edd_orders o
		JOIN {$wpdb->prefix}edd_order_items i ON i.order_id = o.id
		JOIN {$wpdb->prefix}edd_order_itemmeta m ON m.edd_order_item_id = i.id AND m.meta_key = '_caw_fox_card'
		LEFT JOIN {$wpdb->prefix}edd_order_itemmeta l ON l.edd_order_item_id = i.id AND l.meta_key = '_caw_fox_card_last4'
		WHERE o.user_id = %d AND o.status = 'complete' AND ( %d = 0 OR i.product_id = %d )
		ORDER BY o.id DESC LIMIT 50",
		$user_id,
		(int) $download_id,
		(int) $download_id
	) );
	$cards = array();
	foreach ( (array) $rows as $r ) {
		$cards[ $r->card ] = array( 'last4' => (string) $r->last4, 'date' => (string) $r->date );
	}
	return $cards;
}

/** Checkout <select> options for a renewal. */
function caw_fox_card_options( $user_id, $download_id ) {
	$opts = array();
	foreach ( caw_fox_user_cards( $user_id, $download_id ) as $id => $c ) {
		$opts[] = array(
			'value' => $id,
			/* translators: 1: last 4 digits, 2: purchase date */
			'label' => sprintf( __( 'Visa •••• %1$s — bought %2$s', 'mayosis' ), $c['last4'] ?: '????', $c['date'] ? wp_date( 'j M Y', strtotime( $c['date'] . ' UTC' ) ) : '' ),
		);
	}
	return $opts;
}

function caw_fox_card_html( $item, $for_email ) {
	$last4 = (string) caw_fox_meta( $item->id, '_caw_fox_card_last4' );
	$topup = ! caw_fox_meta( $item->id, '_caw_fox_card' );
	if ( $for_email ) {
		$text = $topup
			/* translators: %s: last 4 digits */
			? sprintf( __( 'Your renewal has been added to your card •••• %s.', 'mayosis' ), $last4 )
			: sprintf( __( 'Your virtual Visa card •••• %s is ready. For your security, the card number, CVV and bank verification codes are shown only on your order receipt. Log in to see them.', 'mayosis' ), $last4 );
		return '<p style="margin:12px 0;padding:14px;border:2px dotted #1e73be;border-radius:10px;background:#ffffff;font-size:15px">' . esc_html( $text ) . '</p>';
	}
	// Details sit masked on the card face; Show fetches them live and they mask again after 60s.
	$mask = '•••• •••• •••• ' . ( $last4 ?: '????' );
	$copy = function ( $key, $label ) {
		return '<button type="button" class="caw-fox-ic" data-copy="' . $key . '" aria-label="' . esc_attr( $label ) . '"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg></button>';
	};
	$renew = add_query_arg( 'caw_renew', (int) $item->price_id, get_permalink( $item->product_id ) );
	$html  = '<div class="caw-fox-vcard" data-item="' . (int) $item->id . '" data-mask="' . esc_attr( $mask ) . '">';
	$html .= '<div class="caw-fox-vcard-face">'
		. '<div class="caw-fox-vcard-top"><button type="button" class="caw-fox-reveal" aria-expanded="false" data-show="' . esc_attr__( 'Show details', 'mayosis' ) . '" data-hide="' . esc_attr__( 'Hide', 'mayosis' ) . '">'
		. '<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>'
		. '<span>' . esc_html__( 'Show details', 'mayosis' ) . '</span></button>'
		. ( caw_fox_is_mock() ? '<span class="caw-fox-vcard-test">TEST</span>' : '' )
		. '<span class="caw-fox-vcard-brand">VISA</span></div>'
		. '<div class="caw-fox-vcard-num"><span data-f="pan">' . esc_html( $mask ) . '</span>' . $copy( 'pan', __( 'Copy card number', 'mayosis' ) ) . '</div>'
		. '<div class="caw-fox-vcard-meta">'
		. '<div><small>' . esc_html__( 'Expires', 'mayosis' ) . '</small><span><span data-f="exp">••/••</span>' . $copy( 'exp', __( 'Copy expiry', 'mayosis' ) ) . '</span></div>'
		. '<div><small>CVV</small><span><span data-f="cvv">•••</span>' . $copy( 'cvv', __( 'Copy CVV', 'mayosis' ) ) . '</span></div></div>'
		. '<div class="caw-fox-vcard-name"><span data-f="name">' . esc_html( (string) caw_fox_meta( $item->id, '_caw_fox_card_name' ) ) . '</span>' . $copy( 'name', __( 'Copy name on card', 'mayosis' ) ) . '</div>'
		. '</div>';
	$html .= '<div class="caw-fox-pass" hidden><span>' . esc_html__( 'Bank password (if asked)', 'mayosis' ) . '</span><code data-f="pass"></code>' . $copy( 'pass', __( 'Copy bank password', 'mayosis' ) ) . '</div>';
	$html .= '<p class="caw-fox-card-msg" role="alert" hidden></p>';
	$html .= '<p class="caw-fox-hint">' . esc_html__( 'Details hide again after 60 seconds.', 'mayosis' ) . '</p>';
	if ( $topup ) {
		$html .= '<p class="caw-fox-for">' . esc_html__( 'Your renewal has been added to this card.', 'mayosis' ) . '</p>';
	}
	$html .= '<div class="caw-fox-balrow"><span>' . esc_html__( 'Balance', 'mayosis' ) . ' <strong class="caw-fox-bal">…</strong></span>'
		. '<a class="caw-fox-recharge" href="' . esc_url( $renew ) . '" target="_blank" rel="noopener">' . esc_html__( 'Recharge your card', 'mayosis' ) . ' ↗</a></div>';
	$html .= '<h4 class="caw-fox-label caw-fox-sec-code">' . esc_html__( 'Bank verification code', 'mayosis' ) . '</h4><div class="caw-fox-3ds-body"><p class="caw-fox-wait"><span class="caw-fox-spinner" aria-hidden="true"></span>' . esc_html__( 'Loading…', 'mayosis' ) . '</p></div>';
	$html .= '<div class="caw-fox-codebar"><span class="caw-fox-codestate">' . esc_html__( 'Checking for new codes every 5 seconds', 'mayosis' ) . '</span><button type="button" class="caw-fox-resume">' . esc_html__( 'Check for a new code', 'mayosis' ) . '</button></div>';
	$html .= '<details class="caw-fox-ledger" open data-caw-collapse><summary>' . esc_html__( 'Balance and activity', 'mayosis' ) . '</summary><div class="caw-fox-ledger-body"><p class="caw-fox-wait">' . esc_html__( 'Loading…', 'mayosis' ) . '</p></div></details>';
	return $html . '</div>';
}

/** What the receipt shows for an order, for its section menu: [ 'card' => true ] and/or [ 'code' => true ]. */
function caw_fox_receipt_kinds( $order_id ) {
	$kinds = array();
	foreach ( caw_fox_order_items( $order_id ) as $item ) {
		$kinds[ caw_fox_is_card_type( caw_fox_type( $item->product_id, $item->price_id ) ) ? 'card' : 'code' ] = true;
	}
	return $kinds;
}

// "Recharge your card" opens the product with the matching Renewal option picked (?caw_renew=<price id bought>).
add_filter( 'caw_price_model', function ( $model, $id ) {
	if ( empty( $_GET['caw_renew'] ) || empty( $model['two_axis'] ) || ! caw_fox_map( $id ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $model;
	}
	$bought = edd_get_price_option_name( $id, absint( $_GET['caw_renew'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	// Owner-typed names: ignore case, dash style and spacing ("New Card – Pro" ≈ "new card - pro").
	$norm = function ( $s ) {
		return preg_replace( '/\s+/u', ' ', preg_replace( '/\s*[-\x{2013}\x{2014}]\s*/u', ' - ', mb_strtolower( trim( (string) $s ) ) ) );
	};
	$want = str_replace( 'new card', 'renewal', $norm( $bought ) );
	foreach ( edd_get_variable_prices( $id ) as $pid => $p ) {
		if ( isset( $p['name'] ) && $norm( $p['name'] ) === $want ) {
			$key = array_search( (int) $pid, $model['map'], true );
			if ( false !== $key ) {
				list( $model['default_plan'], $model['default_dur'] ) = explode( '|||', $key );
				$model['default_pid'] = (int) $pid;
			}
			break;
		}
	}
	return $model;
}, 20, 2 );

function caw_fox_money( $amount, $currency = 'USD' ) {
	return ( 'USD' === strtoupper( (string) $currency ) ? '$' : strtoupper( (string) $currency ) . ' ' ) . number_format( (float) $amount, 2 );
}

add_action( 'wp_ajax_nopriv_caw_fox_card', function () {
	nocache_headers();
	wp_send_json_error( esc_html__( 'Please log in to see your card.', 'mayosis' ), 401 );
} );
add_action( 'wp_ajax_caw_fox_card', function () {
	nocache_headers();
	$item  = edd_get_order_item( isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only, ownership-checked
	$order = $item ? edd_get_order( $item->order_id ) : null;
	if ( ! $order || ( (int) $order->user_id !== get_current_user_id() && ! current_user_can( 'view_shop_sensitive_data' ) ) ) {
		wp_send_json_error( esc_html__( 'Card not found.', 'mayosis' ), 404 );
	}
	$card = caw_fox_item_card( $item->id );
	if ( 'complete' !== $order->status || ! caw_fox_is_card_id( $card ) ) {
		wp_send_json_error( esc_html__( 'Card not found.', 'mayosis' ), 404 );
	}
	$base = '/api/banking-cards/' . $card;

	if ( isset( $_GET['op'] ) && 'creds' === $_GET['op'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		$r = caw_fox_api( 'GET', $base . '/credentials' ); // live from the issuer, never stored
		if ( $r['error'] ) {
			wp_send_json_error( esc_html__( 'Card details are unavailable right now. Please try again in a minute.', 'mayosis' ), 503 );
		}
		$c   = $r['data'];
		$pan = preg_replace( '/\D/', '', (string) $c['pan'] );
		wp_send_json_success( array(
			'pan'     => trim( chunk_split( $pan, 4, ' ' ) ),
			'pan_raw' => $pan,
			'exp'     => sprintf( '%02d/%02d', (int) $c['expMonth'], (int) $c['expYear'] % 100 ),
			'cvv'     => (string) $c['cvv'],
			'name'    => (string) caw_fox_meta( $item->id, '_caw_fox_card_name' ),
			'pass'    => isset( $c['threeDsPassword'] ) ? (string) $c['threeDsPassword'] : '',
		) );
	}

	// Live panel. Several open tabs share one Foxreload call per card every few seconds.
	$full  = ! empty( $_GET['full'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$cache = 'caw_fox_live_' . md5( $card . ( $full ? '1' : '0' ) );
	$out   = get_transient( $cache );
	if ( false === $out ) {
		$out   = array( 'codes' => array() );
		$codes = caw_fox_api( 'GET', '/api/banking-cards/3ds-codes/?cardId=' . $card . ( caw_fox_is_mock() ? '&isMock=true' : '' ) );
		foreach ( $codes['error'] ? array() : (array) $codes['data'] as $c ) {
			$exp = strtotime( (string) $c['expiresAt'] );
			if ( $exp > time() ) {
				$out['codes'][] = array(
					'id'       => (string) $c['id'],
					'code'     => (string) $c['code'],
					'merchant' => isset( $c['merchant'] ) ? (string) $c['merchant'] : '',
					'amount'   => ! empty( $c['amount'] ) ? caw_fox_money( $c['amount'], isset( $c['currency'] ) ? $c['currency'] : 'USD' ) : '',
					'expires'  => $exp,
				);
			}
		}
		if ( $full ) {
			$bal    = caw_fox_api( 'GET', $base . '/balance' );
			$tx     = caw_fox_api( 'GET', $base . '/transactions/?limit=10' );
			$labels = array(
				'pending'   => __( 'Pending', 'mayosis' ),
				'completed' => __( 'Completed', 'mayosis' ),
				'declined'  => __( 'Declined', 'mayosis' ),
				'refunded'  => __( 'Refunded', 'mayosis' ),
				'reversed'  => __( 'Reversed', 'mayosis' ),
				'blocked'   => __( 'Blocked', 'mayosis' ),
			);
			$list = array();
			foreach ( $tx['error'] ? array() : (array) $tx['data']['items'] as $t ) {
				$status = (string) $t['status'];
				// Amounts are unsigned; top-ups come through as a "settlement" named like "Card top-up".
				$in     = in_array( $t['type'], array( 'refund', 'reversal' ), true ) || preg_match( '/top.?up|\bload|deposit/i', (string) $t['merchantName'] );
				$list[] = array(
					'date'     => wp_date( 'j M, H:i', strtotime( (string) $t['occurredAt'] ) ),
					'merchant' => $t['merchantName'] ? preg_replace( '/\s*\(\d+\)$/', '', (string) $t['merchantName'] ) : ucfirst( (string) $t['type'] ),
					'amount'   => ( $in ? '+' : '−' ) . caw_fox_money( $t['amount'], $t['currency'] ),
					'status'   => sanitize_key( $status ),
					'label'    => ( isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status ) ) . ( ! empty( $t['declineReason'] ) ? ' — ' . $t['declineReason'] : '' ),
				);
			}
			$out['ledger'] = array(
				'balance' => $bal['error'] ? '—' : caw_fox_money( $bal['data']['available'], $bal['data']['currency'] ),
				'tx'      => $list,
			);
		}
		set_transient( $cache, $out, 4 );
	}
	wp_send_json_success( $out );
} );

/* =========================================================================
   H. Admin — product mapping box, order box, Tools → Foxreload
   ========================================================================= */

add_action( 'add_meta_boxes_download', function () {
	add_meta_box( 'caw-foxreload', 'Foxreload supplier', 'caw_fox_render_product_box', 'download', 'normal', 'default' );
} );

function caw_fox_render_product_box( $post ) {
	if ( caw_esim_is_store( $post->ID ) ) {
		echo '<p>This product is the eSIM store: its options are created by themselves, see the eSIM store box.</p>';
		return; // no nonce here, so a save leaves its mapping alone
	}
	$map    = caw_fox_map( $post->ID );
	$prices = edd_has_variable_prices( $post->ID ) ? edd_get_variable_prices( $post->ID ) : array( 0 => array( 'name' => 'Price', 'amount' => edd_get_download_price( $post->ID ) ) );
	wp_nonce_field( 'caw_fox_product', 'caw_fox_nonce' );
	echo '<p>Map each price option to a Foxreload product ID (<code>product_…</code>) to deliver it automatically. Leave blank for manual delivery. Stock and cost refresh hourly and on save. Mode: <strong>' . ( caw_fox_is_mock() ? 'MOCK (test codes)' : 'LIVE' ) . '</strong>.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Option</th><th>Price</th><th>Foxreload product ID</th><th>Cost</th><th>Margin</th><th>Stock</th><th>Delivery</th><th>Status</th></tr></thead><tbody>';
	foreach ( $prices as $pid => $p ) {
		$c    = caw_fox_cache( $post->ID, $pid );
		$sell = edd_has_variable_prices( $post->ID ) ? caw_fox_sell_price( $post->ID, $pid ) : (float) $p['amount'];
		$cost = $c && ! empty( $c['cost'] ) ? (float) $c['cost'] : 0;
		$was  = (float) $p['amount'] > $sell ? '<del>$' . esc_html( number_format( (float) $p['amount'], 2 ) ) . '</del> ' : '';
		echo '<tr><td>' . esc_html( $p['name'] ) . '</td><td>' . $was . '$' . esc_html( number_format( $sell, 2 ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td><input type="text" class="regular-text code" name="caw_fox_items[' . (int) $pid . ']" value="' . esc_attr( isset( $map['items'][ $pid ] ) ? $map['items'][ $pid ] : '' ) . '" placeholder="product_…"></td>';
		echo '<td>' . ( $cost ? '$' . esc_html( number_format( $cost, 2 ) ) : '—' ) . '</td>';
		echo '<td>' . ( $cost && $sell ? esc_html( round( ( 1 - $cost / $sell ) * 100 ) ) . '%' : '—' ) . '</td>';
		echo '<td>' . ( $c && isset( $c['qty'] ) ? esc_html( number_format( $c['qty'] ) ) : '—' ) . '</td>';
		echo '<td>' . ( $c && isset( $c['type'] ) ? esc_html( caw_fox_delivery_label( $c ) ) : '—' ) . '</td>';
		echo '<td>' . ( ! $c ? '—' : ( $c['ok'] ? '✅ on sale' : '⛔ ' . esc_html( $c['reason'] ) ) ) . '</td></tr>';
	}
	echo '</tbody></table>';
	$mk    = caw_fox_markup( $post->ID );
	$pct   = function ( $name, $range, $i ) {
		return '<input type="number" step="0.1" min="' . esc_attr( caw_fox_min_markup() ) . '" max="200" name="caw_fox_markup[' . $name . '][' . $i . ']" value="' . esc_attr( isset( $range[ $i ] ) ? $range[ $i ] : '' ) . '" style="width:70px">%';
	};
	echo '<p><strong>Automatic pricing</strong> — price = Foxreload cost + markup, rounded up to a shop price (x.x9 under $20, x.49/x.99 under $100, x.99 above). The cheapest option gets the first %, the dearest the second, options in between slide along the curve; use the same % twice for a flat markup. Prices update on save and hourly when they would move ' . esc_html( caw_fox_reprice_threshold() * 100 ) . '% or more. <em>While on, prices typed above are overwritten.</em> Leave blank for manual prices. Minimum ' . esc_html( caw_fox_min_markup() ) . '% (the margin guard).</p>';
	if ( edd_has_variable_prices( $post->ID ) ) {
		echo '<p>Regular price markup: ' . $pct( 'reg', $mk['reg'], 0 ) . ' → ' . $pct( 'reg', $mk['reg'], 1 ) . ' &nbsp; &nbsp; Sale price markup: ' . $pct( 'sale', $mk['sale'], 0 ) . ' → ' . $pct( 'sale', $mk['sale'], 1 ) . ' <small>(blank = no sale)</small></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p><em>Automatic pricing needs variable prices (price options).</em></p>';
	}
	$axes = isset( $map['axes'] ) ? $map['axes'] : array( '', '' );
	echo '<p>Selector labels for "A – B" option names (e.g. Region / Amount): ';
	echo '<input type="text" name="caw_fox_axes[0]" value="' . esc_attr( $axes[0] ) . '" placeholder="Region" style="width:120px"> ';
	echo '<input type="text" name="caw_fox_axes[1]" value="' . esc_attr( isset( $axes[1] ) ? $axes[1] : '' ) . '" placeholder="Amount" style="width:120px"></p>';
	echo '<p><strong>Checkout note</strong> — shown above the fields this product asks for at checkout (see the Delivery column), e.g. where to find the Player ID or which username to enter. Leave blank for a general line.</p>';
	echo '<textarea name="caw_fox_hint" rows="2" class="large-text">' . esc_textarea( (string) get_post_meta( $post->ID, '_caw_fox_hint', true ) ) . '</textarea>';
	echo '<p><strong>How to redeem</strong> — shown under the code on the receipt and in the email (HTML allowed). Leave blank to use the supplier\'s guide.</p>';
	echo '<textarea name="caw_fox_guide" rows="8" class="large-text code">' . esc_textarea( (string) get_post_meta( $post->ID, '_caw_fox_guide', true ) ) . '</textarea>';
}

add_action( 'save_post_download', function ( $post_id ) {
	if ( ! isset( $_POST['caw_fox_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['caw_fox_nonce'] ) ), 'caw_fox_product' ) || ! current_user_can( 'edit_product', $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	$items = array();
	foreach ( (array) ( isset( $_POST['caw_fox_items'] ) ? wp_unslash( $_POST['caw_fox_items'] ) : array() ) as $pid => $fox ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$fox = trim( sanitize_text_field( $fox ) );
		if ( preg_match( '/^product_[a-z0-9]+$/i', $fox ) ) {
			$items[ (int) $pid ] = $fox;
		}
	}
	if ( ! $items ) {
		delete_post_meta( $post_id, '_caw_fox' );
		delete_post_meta( $post_id, '_caw_fox_cache' );
		return;
	}
	$axes = array_map( 'sanitize_text_field', array_slice( (array) ( isset( $_POST['caw_fox_axes'] ) ? wp_unslash( $_POST['caw_fox_axes'] ) : array() ), 0, 2 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$markup = array();
	$in     = isset( $_POST['caw_fox_markup'] ) ? (array) wp_unslash( $_POST['caw_fox_markup'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( array( 'reg', 'sale' ) as $k ) {
		$ends = isset( $in[ $k ] ) ? array_values( array_filter( array_map( 'trim', (array) $in[ $k ] ), 'is_numeric' ) ) : array();
		if ( 1 === count( $ends ) ) {
			$ends[] = $ends[0]; // one box filled = flat markup
		}
		if ( 2 === count( $ends ) ) {
			$markup[ $k ] = array_map( function ( $v ) {
				return min( 200, max( caw_fox_min_markup(), round( (float) $v, 1 ) ) );
			}, array_slice( $ends, 0, 2 ) );
		}
	}
	if ( empty( $markup['reg'] ) ) {
		$markup = array(); // a sale markup alone means nothing without a regular one
	}
	update_post_meta( $post_id, '_caw_fox', array( 'items' => $items, 'axes' => $axes, 'markup' => $markup ) );
	$guide = isset( $_POST['caw_fox_guide'] ) ? trim( wp_kses_post( wp_unslash( $_POST['caw_fox_guide'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( $guide ) {
		update_post_meta( $post_id, '_caw_fox_guide', $guide );
	} else {
		delete_post_meta( $post_id, '_caw_fox_guide' );
	}
	$hint = isset( $_POST['caw_fox_hint'] ) ? trim( wp_kses_post( wp_unslash( $_POST['caw_fox_hint'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( $hint ) {
		update_post_meta( $post_id, '_caw_fox_hint', $hint );
	} else {
		delete_post_meta( $post_id, '_caw_fox_hint' );
	}
	// save_post_download fires before EDD saves the prices (save_post, 10) — sync after, or the margin check uses the old price.
	add_action( 'save_post', function ( $id ) use ( $post_id ) {
		if ( (int) $id === (int) $post_id ) {
			caw_fox_sync_download( $post_id, true );
		}
	}, 99 );
}, 99 );

add_action( 'edd_view_order_details_main_after', function ( $order_id ) {
	$items = caw_fox_order_items( $order_id );
	if ( ! $items ) {
		return;
	}
	echo '<div class="postbox"><h3 class="hndle" style="padding:10px 12px;margin:0">Foxreload delivery</h3><div class="inside"><table class="widefat striped"><thead><tr><th>Item</th><th>Status</th><th>Foxreload order</th><th>Details</th><th></th></tr></thead><tbody>';
	foreach ( $items as $item ) {
		$status = caw_fox_meta( $item->id, '_caw_fox_status' ) ?: 'queued';
		$remote = caw_fox_meta( $item->id, '_caw_fox_remote' );
		$detail = '';
		$note   = caw_fox_meta( $item->id, '_caw_fox_note' );
		if ( is_array( $note ) ) {
			foreach ( $note as $f => $v ) {
				$detail .= esc_html( caw_fox_field_label( $f ) . ': ' . $v ) . '<br>';
			}
		}
		if ( 'failed' === $status ) {
			$detail .= '<span style="color:#b32d2e">' . esc_html( caw_fox_meta( $item->id, '_caw_fox_error' ) ) . '</span>';
		}
		$card = caw_fox_item_card( $item->id );
		if ( $card ) {
			$detail .= ( caw_fox_meta( $item->id, '_caw_fox_card' ) ? 'Card issued' : 'Topped up card' ) . ' •••• ' . esc_html( caw_fox_meta( $item->id, '_caw_fox_card_last4' ) ) . '<br><small><code>' . esc_html( $card ) . '</code></small>';
		} elseif ( 'delivered' === $status && current_user_can( 'view_shop_sensitive_data' ) ) {
			$detail .= '<details><summary>Show codes</summary><code>' . esc_html( implode( "\n", caw_fox_codes( $item->id ) ) ) . '</code></details>';
		}
		$actions = '';
		if ( in_array( $status, array( 'failed', 'balance', 'queued' ), true ) && ! in_array( $remote, array( 'paid', 'processing', 'completed' ), true ) ) {
			$actions .= '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=caw_fox_retry&item=' . $item->id ), 'caw_fox_item_' . $item->id ) ) . '">Retry</a> ';
			$quote = (float) caw_fox_meta( $item->id, '_caw_fox_cost_quote' );
			if ( 'failed' === $status && $quote > 0 ) {
				$diff = (float) $item->total - $quote;
				$ask  = sprintf( 'Buy from Foxreload at $%s? The customer paid $%s: %s $%s.', number_format( $quote, 2 ), number_format( (float) $item->total, 2 ), $diff >= 0 ? 'profit' : 'LOSS', number_format( abs( $diff ), 2 ) );
				$actions .= '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=caw_fox_retry_cost&item=' . $item->id ), 'caw_fox_item_' . $item->id ) ) . '" onclick="return confirm(' . esc_attr( wp_json_encode( $ask ) ) . ')">Retry at $' . esc_html( number_format( $quote, 2 ) ) . '</a> ';
			}
		}
		if ( 'delivered' !== $status && 'manual' !== $status ) {
			$actions .= '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=caw_fox_manual&item=' . $item->id ), 'caw_fox_item_' . $item->id ) ) . '" onclick="return confirm(\'Mark as delivered by hand?\')">Mark as delivered</a>';
		}
		echo '<tr><td>' . esc_html( $item->product_name ) . ' ×' . (int) $item->quantity . '</td><td><strong>' . esc_html( $status ) . '</strong>' . ( $remote ? '<br><small>remote: ' . esc_html( $remote ) . '</small>' : '' ) . '</td><td><code>' . esc_html( caw_fox_meta( $item->id, '_caw_fox_order' ) ) . '</code></td><td>' . $detail . '</td><td>' . $actions . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table></div></div>';
} );

function caw_fox_admin_item_action( $cb ) {
	$item_id = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	check_admin_referer( 'caw_fox_item_' . $item_id );
	if ( ! current_user_can( 'edit_shop_payments' ) ) {
		wp_die( 'Not allowed' );
	}
	$item = edd_get_order_item( $item_id );
	if ( ! $item ) {
		wp_die( 'Unknown item' );
	}
	$cb( $item );
	wp_safe_redirect( admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . $item->order_id ) );
	exit;
}

/** Start the item over. $approve = a supplier cost the owner accepted for it (0 = the normal margin guard). */
function caw_fox_retry_item( $item, $approve = 0.0 ) {
	// A refunded/failed Foxreload order can't be re-paid: start a fresh one under a new reference.
	if ( caw_fox_meta( $item->id, '_caw_fox_order' ) ) {
		caw_fox_set( $item->id, '_caw_fox_try', (int) caw_fox_meta( $item->id, '_caw_fox_try' ) + 1 );
	}
	foreach ( array( '_caw_fox_order', '_caw_fox_status', '_caw_fox_remote', '_caw_fox_error', '_caw_fox_cost_quote', '_caw_fox_cost_ok' ) as $k ) {
		edd_delete_order_item_meta( $item->id, $k );
	}
	$who = wp_get_current_user()->user_login;
	if ( $approve > 0 ) {
		caw_fox_set( $item->id, '_caw_fox_cost_ok', $approve );
		caw_fox_note_order( $item->order_id, sprintf( 'retry at supplier cost $%s approved by %s.', number_format( $approve, 2 ), $who ) );
	} else {
		caw_fox_note_order( $item->order_id, 'retry requested by ' . $who . '.' );
	}
	edd_update_order_meta( $item->order_id, '_caw_fox_polls', 0 );
	caw_fox_fulfil( $item->order_id );
}

add_action( 'admin_post_caw_fox_retry', function () {
	caw_fox_admin_item_action( function ( $item ) {
		caw_fox_retry_item( $item );
	} );
} );

add_action( 'admin_post_caw_fox_retry_cost', function () {
	caw_fox_admin_item_action( function ( $item ) {
		caw_fox_retry_item( $item, (float) caw_fox_meta( $item->id, '_caw_fox_cost_quote' ) );
	} );
} );

add_action( 'admin_post_caw_fox_manual', function () {
	caw_fox_admin_item_action( function ( $item ) {
		caw_fox_set( $item->id, '_caw_fox_status', 'manual' );
		caw_fox_note_order( $item->order_id, $item->product_name . ' marked as delivered by hand (' . wp_get_current_user()->user_login . ').' );
	} );
} );

add_action( 'admin_menu', function () {
	add_management_page( 'Foxreload', 'Foxreload', 'manage_shop_settings', 'caw-foxreload', 'caw_fox_render_tools_page' );
} );

add_action( 'admin_post_caw_fox_tools', function () {
	check_admin_referer( 'caw_fox_tools' );
	if ( ! current_user_can( 'manage_shop_settings' ) ) {
		wp_die( 'Not allowed' );
	}
	$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
	$msg = '';
	if ( 'sync' === $do ) {
		caw_fox_sync_all();
		$msg = 'Synced.';
	} elseif ( 'webhook' === $do ) {
		$res = caw_fox_api( 'POST', '/api/webhooks/', array( 'name' => 'Crypto Awaz', 'url' => rest_url( 'caw/v1/foxreload' ), 'events' => array( 'order.completed' ) ) );
		if ( $res['error'] ) {
			$msg = 'Webhook not created: ' . caw_fox_error_text( $res );
		} else {
			// Shown once, never stored — it belongs in wp-config.php.
			$secret = isset( $res['data']['secret'] ) ? $res['data']['secret'] : ( isset( $res['data']['signingSecret'] ) ? $res['data']['signingSecret'] : '' );
			set_transient( 'caw_fox_secret_once_' . get_current_user_id(), $secret, 300 );
			$msg = 'Webhook created. Copy the secret below into wp-config.php now — it is not shown again.';
		}
	}
	set_transient( 'caw_fox_msg_' . get_current_user_id(), $msg, 60 );
	wp_safe_redirect( admin_url( 'tools.php?page=caw-foxreload' ) );
	exit;
} );

function caw_fox_render_tools_page() {
	$uid    = get_current_user_id();
	$msg    = get_transient( 'caw_fox_msg_' . $uid );
	$secret = get_transient( 'caw_fox_secret_once_' . $uid );
	delete_transient( 'caw_fox_msg_' . $uid );
	delete_transient( 'caw_fox_secret_once_' . $uid );
	echo '<div class="wrap"><h1>Foxreload</h1>';
	if ( $msg ) {
		echo '<div class="notice notice-info"><p>' . esc_html( $msg ) . '</p></div>';
	}
	if ( $secret ) {
		echo '<div class="notice notice-warning"><p><code>define( \'CAW_FOXRELOAD_WEBHOOK_SECRET\', \'' . esc_html( $secret ) . '\' );</code></p></div>';
	}
	if ( ! caw_fox_enabled() ) {
		echo '<p>Add <code>CAW_FOXRELOAD_API_KEY</code> to wp-config.php.</p></div>';
		return;
	}
	$bal = caw_fox_api( 'GET', '/api/access/me/balances/' );
	$usd = '—';
	foreach ( (array) $bal['data'] as $b ) {
		if ( isset( $b['currency'] ) && 'usd' === strtolower( $b['currency'] ) ) {
			$usd = '$' . $b['available'] . ( (float) $b['reserved'] ? ' (+ $' . $b['reserved'] . ' reserved)' : '' );
		}
	}
	$hooks = caw_fox_api( 'GET', '/api/webhooks/' );
	$last  = (int) get_option( 'caw_fox_last_sync' );
	echo '<table class="form-table"><tr><th>Mode</th><td><strong>' . ( caw_fox_is_mock() ? 'MOCK — test orders, fake codes, no balance spent' : 'LIVE — real purchases' ) . '</strong></td></tr>';
	echo '<tr><th>USD balance</th><td>' . esc_html( $bal['error'] ? caw_fox_error_text( $bal ) : $usd ) . '</td></tr>';
	echo '<tr><th>Minimum margin</th><td>' . esc_html( caw_fox_min_margin() * 100 ) . '%</td></tr>';
	echo '<tr><th>Last sync</th><td>' . ( $last ? esc_html( human_time_diff( $last ) . ' ago' ) : 'never' ) . '</td></tr>';
	$next = wp_next_scheduled( 'caw_fox_sync_due' );
	$run  = (array) get_option( 'caw_fox_due_run', array() );
	if ( empty( $run['start'] ) ) {
		$bg = 'has never run';
	} elseif ( $run['end'] ) {
		$bg = 'last run ' . human_time_diff( $run['start'] ) . ' ago, finished';
	} elseif ( time() - $run['start'] < 3 * MINUTE_IN_SECONDS ) {
		$bg = 'running now';
	} else {
		$bg = 'last run ' . human_time_diff( $run['start'] ) . ' ago DID NOT FINISH' . ( $run['id'] ? ' (stopped during ' . get_the_title( $run['id'] ) . ')' : '' );
	}
	$bg .= '; next run ' . ( ! $next ? 'NOT SCHEDULED' : ( $next < time() - 5 * MINUTE_IN_SECONDS ? 'overdue by ' . human_time_diff( $next ) . ' (WP-cron not firing?)' : 'in ' . human_time_diff( $next ) ) );
	echo '<tr><th>Background sync</th><td>' . esc_html( $bg ) . '</td></tr>';
	echo '<tr><th>Webhook</th><td>' . ( defined( 'CAW_FOXRELOAD_WEBHOOK_SECRET' ) ? 'secret configured' : 'no secret in wp-config (polling only)' ) . '; registered: ' . esc_html( (string) count( (array) ( isset( $hooks['data']['items'] ) ? $hooks['data']['items'] : $hooks['data'] ) ) ) . '</td></tr></table>';
	echo '<h2>Mapped products</h2><ul>';
	foreach ( caw_fox_mapped_downloads() as $id ) {
		$at    = (int) get_post_meta( $id, '_caw_fox_synced', true );
		$try   = (int) get_post_meta( $id, '_caw_fox_sync_try', true );
		$stale = ! $at || time() - $at > 2 * HOUR_IN_SECONDS;
		echo '<li><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a> — <span' . ( $stale ? ' style="color:#b32d2e;font-weight:600"' : '' ) . '>synced ' . esc_html( $at ? human_time_diff( $at ) . ' ago' : 'never' ) . '</span>' . ( $try > $at + 5 * MINUTE_IN_SECONDS ? ' — last try ' . esc_html( human_time_diff( $try ) ) . ' ago did not finish' : '' ) . '</li>';
	}
	echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'caw_fox_tools' );
	echo '<input type="hidden" name="action" value="caw_fox_tools">';
	echo '<button class="button button-primary" name="do" value="sync">Sync stock &amp; prices now</button> ';
	echo '<button class="button" name="do" value="webhook" onclick="return confirm(\'Register ' . esc_js( rest_url( 'caw/v1/foxreload' ) ) . ' with Foxreload?\')">Register webhook</button>';
	echo '</form></div>';
}
