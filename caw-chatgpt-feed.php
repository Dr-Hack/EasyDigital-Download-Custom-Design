<?php
/**
 * ChatGPT product feed: https://<site>/chatgpt-feed.tsv.gz (gzip TSV, a format the spec accepts;
 * /chatgpt-feed.tsv is the same file uncompressed, for reading by eye).
 *
 * The owner pastes the .gz URL into ChatGPT ("Add products to ChatGPT" → Use a hosted URL). Spec:
 * developers.openai.com/commerce/specs/file-upload/overview + /products. Full snapshot on every
 * build, stable filename, UTF-8, header row, rebuilt every 4 hours (spec: at least daily).
 *
 * - One row per price option (item_id "<download>-<price id>", group_id "<download>"); a product
 *   without options is one row "<download>-0". Renewal options are left out: they top up a card
 *   bought here before, so they mean nothing to a new buyer.
 * - The eSIM store is one row per destination with its cheapest plan, from the hourly
 *   caw_esim_range run (no supplier call here). Plans above CAW_ESIM_MAX_COST are never in it.
 * - Titles and descriptions say how many more options the product page has, so people visit it.
 * - Built every 4 hours by cron into uploads/caw-feed/; the URL serves that file (rebuilt on the spot if
 *   it is missing or over 6 hours old). Never contains supplier names, costs, keys or files.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CAW_FEED_PATH', 'chatgpt-feed.tsv' );
// target_countries is required on every row and must be from the list ChatGPT allows for this feed
// (shown on its validation screen; 2026-11-13: AE, GB, US, IE, IT, CH, AQ, DZ; Antarctica left out).
define( 'CAW_FEED_COUNTRIES', array( 'US', 'GB', 'AE', 'IE', 'IT', 'CH', 'DZ' ) );

function caw_feed_file() {
	$up = wp_upload_dir( null, false );
	return trailingslashit( $up['basedir'] ) . 'caw-feed/' . CAW_FEED_PATH;
}

/** One line of plain text: no HTML, shortcodes, tabs or line breaks. */
function caw_feed_text( $s, $max = 0 ) {
	$s = strip_shortcodes( (string) $s );
	$s = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $s );
	$s = wp_strip_all_tags( str_replace( array( '</p>', '<br', '</li>', '</h' ), array( '</p> ', ' <br', '</li> ', ' </h' ), $s ) );
	$s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
	if ( $max && mb_strlen( $s ) > $max ) {
		$s = mb_substr( $s, 0, $max - 1 );
		$s = preg_replace( '/\s+\S*$/u', '', $s ) . '…';
	}
	return $s;
}

function caw_feed_money( $amount ) {
	return number_format( (float) $amount, 2, '.', '' ) . ' ' . strtoupper( edd_get_currency() );
}

/** "Gift Cards > Gaming" from the product's first category. */
function caw_feed_category( $id ) {
	$terms = get_the_terms( $id, 'download_category' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return '';
	}
	$t     = $terms[0];
	$names = array();
	foreach ( array_reverse( get_ancestors( $t->term_id, 'download_category', 'taxonomy' ) ) as $a ) {
		$p = get_term( $a, 'download_category' );
		if ( $p && ! is_wp_error( $p ) ) {
			$names[] = html_entity_decode( $p->name, ENT_QUOTES, 'UTF-8' );
		}
	}
	$names[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
	return implode( ' > ', $names );
}

/** Product description as plain text: the excerpt if there is one, else the content. */
function caw_feed_about( $post ) {
	$s = caw_feed_text( $post->post_excerpt );
	if ( mb_strlen( $s ) < 80 ) {
		$s = trim( $s . ' ' . caw_feed_text( $post->post_content ) );
	}
	return $s;
}

/** Title with the option and the "+N more" hint, kept within 150 characters. */
function caw_feed_title( $name, $option, $more, $word ) {
	$tail = $option ? ' – ' . $option : '';
	if ( $more > 0 ) {
		/* translators: 1: count, 2: "options" / "plans" */
		$tail .= sprintf( ' (+%1$d more %2$s on site)', $more, 1 === $more ? rtrim( $word, 's' ) : $word );
	}
	$room = 150 - mb_strlen( $tail );
	if ( mb_strlen( $name ) > $room ) {
		$name = rtrim( mb_substr( $name, 0, $room - 1 ) ) . '…';
	}
	return $name . $tail;
}

/** [ regular, sale or 0 ] for one option, from the raw meta (the sale plugin only swaps on the front end). */
function caw_feed_prices( $id, $pid, $rows ) {
	if ( null !== $rows ) {
		$row  = isset( $rows[ $pid ] ) ? $rows[ $pid ] : array();
		$reg  = isset( $row['amount'] ) ? (float) $row['amount'] : 0.0;
		$sale = isset( $row['sale_price'] ) && '' !== (string) $row['sale_price'] ? (float) $row['sale_price'] : 0.0;
	} else {
		$reg  = (float) edd_sanitize_amount( get_post_meta( $id, 'edd_price', true ) );
		$sale = (string) get_post_meta( $id, 'edd_sale_price', true );
		$end  = (string) get_post_meta( $id, 'edd_sale_price_time', true );
		$sale = ( '' !== $sale && $end > gmdate( 'Y-m-d' ) ) ? (float) edd_sanitize_amount( $sale ) : 0.0; // same date rule as the plugin
	}
	return array( $reg, ( $sale > 0 && $sale < $reg ) ? $sale : 0.0 );
}

function caw_feed_columns() {
	return array( 'item_id', 'group_id', 'listing_has_variations', 'variant_dict', 'title', 'description', 'url', 'brand', 'seller_name', 'seller_url', 'image_url', 'availability', 'price', 'sale_price', 'condition', 'product_category', 'is_eligible_search', 'seller_privacy_policy', 'target_countries' );
}

/** Every feed row, as column => value. */
function caw_feed_rows() {
	$brand   = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ); // same as the product JSON-LD brand
	$home    = home_url( '/' );
	$privacy = get_privacy_policy_url();
	$out     = array();
	$ids     = get_posts( array(
		'post_type'      => 'download',
		'post_status'    => 'publish',
		'has_password'   => false,
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );

	foreach ( $ids as $id ) {
		$post  = get_post( $id );
		$image = get_the_post_thumbnail_url( $id, 'full' );
		if ( ! $image ) {
			continue; // image_url is required
		}
		$base = array(
			'brand'                 => $brand,
			'seller_name'           => 'Crypto Awaz',
			'seller_url'            => $home,
			'image_url'             => $image,
			'condition'             => 'new',
			'product_category'      => caw_feed_category( $id ),
			'is_eligible_search'    => 'true',
			'seller_privacy_policy' => $privacy,
			'target_countries'      => wp_json_encode( CAW_FEED_COUNTRIES ),
		);
		$name  = caw_feed_text( get_the_title( $id ) );
		$about = caw_feed_about( $post );
		$link  = get_permalink( $id );

		if ( function_exists( 'caw_esim_is_store' ) && caw_esim_is_store( $id ) ) {
			$out = array_merge( $out, caw_feed_esim_rows( $id, $name, $about, $link, $base ) );
			continue;
		}

		$variable = edd_has_variable_prices( $id );
		$stock    = function_exists( 'caw_stock_info' ) ? caw_stock_info( $id ) : null;
		if ( $variable ) {
			$rows  = get_post_meta( $id, 'edd_variable_prices', true );
			$rows  = is_array( $rows ) ? $rows : array();
			$names = array();
			foreach ( $rows as $pid => $r ) {
				$n = isset( $r['name'] ) ? caw_feed_text( $r['name'] ) : '';
				if ( '' !== $n && ! preg_match( '/renewal/i', $n ) ) {
					$names[ (int) $pid ] = $n;
				}
			}
		} else {
			$rows  = null;
			$names = array( 0 => '' );
		}
		$count = count( $names );
		foreach ( $names as $pid => $opt ) {
			list( $reg, $sale ) = caw_feed_prices( $id, $pid, $rows );
			if ( $reg <= 0 ) {
				continue; // price must be positive
			}
			if ( $variable ) {
				$out_of = ! empty( $stock['options'][ $pid ]['soldOut'] );
			} else {
				$out_of = ! empty( $stock['soldOut'] );
			}
			$desc = $about;
			if ( $count > 1 ) {
				$others = array_values( array_diff_key( $names, array( $pid => 1 ) ) );
				$list   = implode( ', ', array_slice( $others, 0, 8 ) ) . ( count( $others ) > 8 ? ', …' : '' );
				$desc   = sprintf( 'This listing: %1$s. %2$d more %3$s on the Crypto Awaz product page: %4$s. ', $opt, $count - 1, 2 === $count ? 'option is' : 'options are', $list ) . $desc;
			}
			$row = $base + array(
				'item_id'      => $id . '-' . $pid,
				'title'        => caw_feed_title( $name, $opt, $count - 1, 'options' ),
				'description'  => caw_feed_text( $desc, 5000 ),
				'url'          => $variable ? add_query_arg( 'option', $pid, $link ) : $link,
				'availability' => $out_of ? 'out_of_stock' : 'in_stock',
				'price'        => caw_feed_money( $reg ),
				'sale_price'   => $sale ? caw_feed_money( $sale ) : '',
			);
			if ( $variable ) {
				$parts = preg_split( '/\s+[-\x{2013}\x{2014}]\s+/u', $opt, 2 );
				$axes  = function_exists( 'caw_fox_map' ) ? caw_fox_map( $id ) : array();
				$axes  = ! empty( $axes['axes'] ) ? array_values( $axes['axes'] ) : array();
				$dict  = 2 === count( $parts )
					? array( ( ! empty( $axes[0] ) ? $axes[0] : 'Plan' ) => $parts[0], ( ! empty( $axes[1] ) ? $axes[1] : 'Variant' ) => $parts[1] )
					: array( 'Option' => $opt );
				$row  += array(
					'group_id'               => (string) $id,
					'listing_has_variations' => 'true',
					'variant_dict'           => wp_json_encode( $dict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				);
			}
			$out[] = $row;
		}
	}
	return $out;
}

/** eSIM store: one row per destination, priced at its cheapest plan. */
function caw_feed_esim_rows( $id, $name, $about, $link, $base ) {
	$range = (array) get_option( 'caw_esim_range', array() );
	$out   = array();
	foreach ( caw_esim_countries() as $c ) {
		$r = isset( $range[ $c['slug'] ] ) ? $range[ $c['slug'] ] : null;
		if ( ! $r || empty( $r[2] ) ) {
			continue; // not measured yet, or nothing in stock
		}
		$low  = caw_esim_price( $id, $r[0] );
		$high = caw_esim_price( $id, $r[1] );
		if ( $low <= 0 ) {
			continue; // no markup set: nothing is sold
		}
		$dest = html_entity_decode( $c['name'], ENT_QUOTES, 'UTF-8' );
		$plan = ! empty( $r[3] ) ? $r[3] : '';
		$more = (int) $r[2] - 1;
		$what = 'eSIM for ' . $dest . ( $plan ? ': ' . $plan : '' );
		$desc = sprintf(
			'Prepaid travel data eSIM for %1$s. This listing: %2$s. %3$d data plans for %1$s are on the Crypto Awaz eSIM page, from %4$s to %5$s, fixed-data and daily plans; search the destination there to see them all. ',
			$dest,
			$plan ? $plan : 'the cheapest plan',
			(int) $r[2],
			caw_feed_money( $low ),
			caw_feed_money( $high )
		) . $about;
		$out[] = $base + array(
			'item_id'                => $id . '-' . $c['slug'],
			'group_id'               => (string) $id,
			'listing_has_variations' => 'true',
			'variant_dict'           => wp_json_encode( array( 'Destination' => $dest ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'title'                  => caw_feed_title( $what, '', $more, 'plans' ),
			'description'            => caw_feed_text( $desc, 5000 ),
			'url'                    => add_query_arg( 'country', $c['slug'], $link ),
			'availability'           => 'in_stock',
			'price'                  => caw_feed_money( $low ),
			'sale_price'             => '',
		);
	}
	return $out;
}

/** The whole file as a string. */
function caw_feed_tsv() {
	$cols  = caw_feed_columns();
	$lines = array( implode( "\t", $cols ) );
	foreach ( caw_feed_rows() as $row ) {
		$cells = array();
		foreach ( $cols as $col ) {
			$cells[] = isset( $row[ $col ] ) ? str_replace( array( "\t", "\r", "\n" ), ' ', (string) $row[ $col ] ) : '';
		}
		$lines[] = implode( "\t", $cells );
	}
	return implode( "\n", $lines ) . "\n";
}

/** Write the file (temp file + rename, so a reader never sees half of it). */
function caw_feed_build() {
	$file = caw_feed_file();
	wp_mkdir_p( dirname( $file ) );
	if ( ! file_exists( dirname( $file ) . '/index.php' ) ) {
		file_put_contents( dirname( $file ) . '/index.php', "<?php\n// Silence is golden.\n" );
	}
	$tsv = caw_feed_tsv();
	$put = function ( $target, $data ) {
		$tmp = $target . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false === file_put_contents( $tmp, $data ) ) {
			return false;
		}
		if ( ! @rename( $tmp, $target ) ) { // Windows won't rename over an existing file
			@unlink( $target );
			if ( ! @rename( $tmp, $target ) ) {
				@unlink( $tmp );
				return false;
			}
		}
		return true;
	};
	if ( ! $put( $file, $tsv ) || ! $put( $file . '.gz', gzencode( $tsv, 9 ) ) ) {
		return false;
	}
	update_option( 'caw_feed_built', array( 'at' => time(), 'rows' => substr_count( $tsv, "\n" ) - 1 ), false );
	return true;
}

add_filter( 'cron_schedules', function ( $s ) {
	$s['caw_4h'] = array( 'interval' => 4 * HOUR_IN_SECONDS, 'display' => 'Every 4 hours' );
	return $s;
} );
add_action( 'init', function () {
	if ( 'caw_4h' !== wp_get_schedule( 'caw_feed_build' ) ) {
		wp_clear_scheduled_hook( 'caw_feed_build' ); // replaces the first, hourly version
		wp_schedule_event( time() + 120, 'caw_4h', 'caw_feed_build' );
	}
} );
add_action( 'caw_feed_build', 'caw_feed_build' );

// Serve /chatgpt-feed.tsv. Early, before WP turns the unknown path into a 404.
add_action( 'parse_request', function () {
	$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' );
	$sub  = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
	$want = ( $sub ? $sub . '/' : '' ) . CAW_FEED_PATH;
	if ( $want !== $path && $want . '.gz' !== $path ) {
		return;
	}
	$gz   = $want !== $path;
	$file = caw_feed_file() . ( $gz ? '.gz' : '' );
	// Also rebuilt when this file is newer than the feed, so an upload shows at once.
	if ( ! is_readable( $file ) || time() - filemtime( $file ) > 6 * HOUR_IN_SECONDS || filemtime( __FILE__ ) > filemtime( $file ) ) {
		caw_feed_build();
		clearstatcache();
	}
	if ( ! is_readable( $file ) ) {
		status_header( 503 );
		header( 'Retry-After: 600' );
		exit;
	}
	status_header( 200 );
	header( $gz ? 'Content-Type: application/gzip' : 'Content-Type: text/tab-separated-values; charset=utf-8' );
	header( 'Content-Disposition: ' . ( $gz ? 'attachment' : 'inline' ) . '; filename="' . basename( $file ) . '"' );
	header( 'Content-Length: ' . filesize( $file ) );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $file ) ) . ' GMT' );
	header( 'Cache-Control: public, max-age=900' );
	header( 'X-Robots-Tag: noindex' );
	if ( 'HEAD' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
		readfile( $file );
	}
	exit;
}, 0 );

// Feed links open the product with that option picked (?option=<price id>).
add_filter( 'caw_price_model', function ( $model, $id ) {
	if ( ! isset( $_GET['option'] ) || empty( $model['variable'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return $model;
	}
	$pid    = absint( $_GET['option'] ); // phpcs:ignore WordPress.Security.NonceVerification
	$prices = edd_get_variable_prices( $id );
	if ( ! isset( $prices[ $pid ] ) ) {
		return $model;
	}
	$model['default_pid'] = $pid;
	if ( ! empty( $model['two_axis'] ) && ! empty( $model['map'] ) ) {
		$key = array_search( $pid, $model['map'], true );
		if ( false !== $key ) {
			list( $model['default_plan'], $model['default_dur'] ) = explode( '|||', $key );
		}
	}
	return $model;
}, 30, 2 );
