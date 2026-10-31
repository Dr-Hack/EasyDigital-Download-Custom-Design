<?php
/**
 * Minimal QR encoder (byte mode, error correction M, versions 1–14, up to 365 bytes).
 * Runs here rather than on a QR web service because what it encodes is an eSIM activation code.
 * Needs no GD: the PNG is written by hand, so it works on any host.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reed-Solomon parity bytes for $data over GF(256), polynomial 0x11D. */
function caw_qr_parity( $data, $len ) {
	static $exp = array(), $log = array();
	if ( ! $exp ) {
		$x = 1;
		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;
			$x       <<= 1;
			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}
	}
	$mul = function ( $a, $b ) use ( &$exp, &$log ) {
		return ( $a && $b ) ? $exp[ ( $log[ $a ] + $log[ $b ] ) % 255 ] : 0;
	};
	$gen = array( 1 );
	for ( $i = 0; $i < $len; $i++ ) {
		$next = array_fill( 0, count( $gen ) + 1, 0 );
		foreach ( $gen as $j => $c ) {
			$next[ $j ]     ^= $c;
			$next[ $j + 1 ] ^= $mul( $c, $exp[ $i ] );
		}
		$gen = $next;
	}
	$rem = array_fill( 0, $len, 0 );
	foreach ( $data as $byte ) {
		$factor = $byte ^ array_shift( $rem );
		$rem[]  = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$rem[ $i ] ^= $mul( $gen[ $i + 1 ], $factor );
		}
	}
	return $rem;
}

/** Data bytes in one symbol, interleaved and with parity added: returns the bit string to place. */
function caw_qr_codewords( $text, $ver, $spec ) {
	list( $ec, $n1, $d1, $n2, $d2 ) = $spec;
	$cap  = $n1 * $d1 + $n2 * $d2;
	$bits = '0100' . str_pad( decbin( strlen( $text ) ), $ver < 10 ? 8 : 16, '0', STR_PAD_LEFT );
	foreach ( str_split( $text ) as $ch ) {
		$bits .= str_pad( decbin( ord( $ch ) ), 8, '0', STR_PAD_LEFT );
	}
	$bits .= str_repeat( '0', min( 4, $cap * 8 - strlen( $bits ) ) );
	$bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );
	$data  = array_map( 'bindec', str_split( $bits, 8 ) );
	for ( $pad = 0xEC; count( $data ) < $cap; $pad ^= 0xFD ) {
		$data[] = $pad;
	}
	$blocks = array();
	$parity = array();
	$at     = 0;
	foreach ( array( array( $n1, $d1 ), array( $n2, $d2 ) ) as $group ) {
		for ( $i = 0; $i < $group[0]; $i++ ) {
			$block    = array_slice( $data, $at, $group[1] );
			$at      += $group[1];
			$blocks[] = $block;
			$parity[] = caw_qr_parity( $block, $ec );
		}
	}
	$out = '';
	for ( $i = 0, $max = max( $d1, $d2 ); $i < $max; $i++ ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block[ $i ] ) ) {
				$out .= str_pad( decbin( $block[ $i ] ), 8, '0', STR_PAD_LEFT );
			}
		}
	}
	for ( $i = 0; $i < $ec; $i++ ) {
		foreach ( $parity as $block ) {
			$out .= str_pad( decbin( $block[ $i ] ), 8, '0', STR_PAD_LEFT );
		}
	}
	return $out;
}

/** The BCH-protected 15-bit format word (level M) or 18-bit version word. */
function caw_qr_bch( $value, $poly, $width, $mask ) {
	$top = strlen( decbin( $poly ) ) - 1;
	$rem = $value << $top;
	for ( $i = $width - 1; $i >= $top; $i-- ) {
		if ( $rem & ( 1 << $i ) ) {
			$rem ^= $poly << ( $i - $top );
		}
	}
	return ( ( $value << $top ) | $rem ) ^ $mask;
}

/** QR modules as rows of 0/1, or null if the text doesn't fit. */
function caw_qr_matrix( $text ) {
	// [ parity per block, blocks in group 1, data bytes each, blocks in group 2, data bytes each ] at level M.
	$specs = array(
		1 => array( 10, 1, 16, 0, 0 ), array( 16, 1, 28, 0, 0 ), array( 26, 1, 44, 0, 0 ), array( 18, 2, 32, 0, 0 ),
		array( 24, 2, 43, 0, 0 ), array( 16, 4, 27, 0, 0 ), array( 18, 4, 31, 0, 0 ), array( 22, 2, 38, 2, 39 ),
		array( 22, 3, 36, 2, 37 ), array( 26, 4, 43, 1, 44 ), array( 30, 1, 50, 4, 51 ), array( 22, 6, 36, 2, 37 ),
		array( 22, 8, 37, 1, 38 ), array( 24, 4, 40, 5, 41 ),
	);
	$align = array(
		1 => array(), array( 6, 18 ), array( 6, 22 ), array( 6, 26 ), array( 6, 30 ), array( 6, 34 ), array( 6, 22, 38 ),
		array( 6, 24, 42 ), array( 6, 26, 46 ), array( 6, 28, 50 ), array( 6, 30, 54 ), array( 6, 32, 58 ), array( 6, 34, 62 ),
		array( 6, 26, 46, 66 ),
	);
	$ver = 0;
	foreach ( $specs as $v => $s ) {
		if ( strlen( $text ) + ( $v < 10 ? 2 : 3 ) <= $s[1] * $s[2] + $s[3] * $s[4] ) {
			$ver = $v;
			break;
		}
	}
	if ( ! $ver ) {
		return null;
	}
	$size  = 17 + 4 * $ver;
	$grid  = array_fill( 0, $size, array_fill( 0, $size, 0 ) );
	$fixed = $grid; // modules the data and the mask must leave alone
	$put   = function ( $x, $y, $on ) use ( &$grid, &$fixed, $size ) {
		if ( $x >= 0 && $y >= 0 && $x < $size && $y < $size ) {
			$grid[ $y ][ $x ]  = $on ? 1 : 0;
			$fixed[ $y ][ $x ] = 1;
		}
	};
	for ( $i = 0; $i < $size; $i++ ) { // timing patterns
		$put( 6, $i, 0 === $i % 2 );
		$put( $i, 6, 0 === $i % 2 );
	}
	foreach ( array( array( 3, 3 ), array( $size - 4, 3 ), array( 3, $size - 4 ) ) as $c ) { // finders
		for ( $dy = -4; $dy <= 4; $dy++ ) {
			for ( $dx = -4; $dx <= 4; $dx++ ) {
				$d = max( abs( $dx ), abs( $dy ) );
				$put( $c[0] + $dx, $c[1] + $dy, 2 !== $d && 4 !== $d );
			}
		}
	}
	foreach ( $align[ $ver ] as $cy ) {
		foreach ( $align[ $ver ] as $cx ) {
			if ( ( 6 === $cx && 6 === $cy ) || ( 6 === $cx && $cy === $size - 7 ) || ( $cx === $size - 7 && 6 === $cy ) ) {
				continue; // would land on a finder
			}
			for ( $dy = -2; $dy <= 2; $dy++ ) {
				for ( $dx = -2; $dx <= 2; $dx++ ) {
					$put( $cx + $dx, $cy + $dy, 1 !== max( abs( $dx ), abs( $dy ) ) );
				}
			}
		}
	}
	$format = function ( $mask ) use ( $put, $size ) {
		$w = caw_qr_bch( $mask, 0x537, 15, 0x5412 ); // level M = 00
		$b = function ( $i ) use ( $w ) {
			return ( $w >> $i ) & 1;
		};
		for ( $i = 0; $i <= 5; $i++ ) {
			$put( 8, $i, $b( $i ) );
		}
		$put( 8, 7, $b( 6 ) );
		$put( 8, 8, $b( 7 ) );
		$put( 7, 8, $b( 8 ) );
		for ( $i = 9; $i < 15; $i++ ) {
			$put( 14 - $i, 8, $b( $i ) );
		}
		for ( $i = 0; $i < 8; $i++ ) {
			$put( $size - 1 - $i, 8, $b( $i ) );
		}
		for ( $i = 8; $i < 15; $i++ ) {
			$put( 8, $size - 15 + $i, $b( $i ) );
		}
		$put( 8, $size - 8, 1 ); // the dark module
	};
	$format( 0 ); // reserves those modules
	if ( $ver >= 7 ) {
		$w = caw_qr_bch( $ver, 0x1F25, 18, 0 );
		for ( $i = 0; $i < 18; $i++ ) {
			$a = $size - 11 + $i % 3;
			$b = (int) floor( $i / 3 );
			$on = ( $w >> $i ) & 1;
			$put( $a, $b, $on );
			$put( $b, $a, $on );
		}
	}

	// Lay the codewords out in the standard two-wide zig-zag, right to left.
	$bits = caw_qr_codewords( $text, $ver, $specs[ $ver ] );
	$n    = 0;
	for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
		if ( 6 === $right ) {
			$right = 5; // the vertical timing column is skipped
		}
		for ( $v = 0; $v < $size; $v++ ) {
			for ( $j = 0; $j < 2; $j++ ) {
				$x  = $right - $j;
				$up = 0 === ( ( $right + 1 ) & 2 );
				$y  = $up ? $size - 1 - $v : $v;
				if ( ! $fixed[ $y ][ $x ] && $n < strlen( $bits ) ) {
					$grid[ $y ][ $x ] = (int) $bits[ $n ];
					$n++;
				}
			}
		}
	}

	$flip = function ( $m, $x, $y ) {
		switch ( $m ) {
			case 0: return 0 === ( $x + $y ) % 2;
			case 1: return 0 === $y % 2;
			case 2: return 0 === $x % 3;
			case 3: return 0 === ( $x + $y ) % 3;
			case 4: return 0 === ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2;
			case 5: return 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3;
			case 6: return 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
			default: return 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
		}
	};
	$data = $grid;
	$best = null;
	$low  = PHP_INT_MAX;
	for ( $m = 0; $m < 8; $m++ ) {
		$grid = $data;
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( ! $fixed[ $y ][ $x ] && $flip( $m, $x, $y ) ) {
					$grid[ $y ][ $x ] ^= 1;
				}
			}
		}
		$format( $m );
		// Any mask decodes; a lightweight score (long runs, solid 2×2 blocks) just helps cameras.
		$score = 0;
		for ( $a = 0; $a < $size; $a++ ) {
			$row = $col = 0;
			for ( $b = 0; $b < $size; $b++ ) {
				$row = ( $b && $grid[ $a ][ $b ] === $grid[ $a ][ $b - 1 ] ) ? $row + 1 : 1;
				$col = ( $b && $grid[ $b ][ $a ] === $grid[ $b - 1 ][ $a ] ) ? $col + 1 : 1;
				$score += ( 5 === $row ? 3 : ( $row > 5 ? 1 : 0 ) ) + ( 5 === $col ? 3 : ( $col > 5 ? 1 : 0 ) );
				if ( $a && $b && $grid[ $a ][ $b ] === $grid[ $a ][ $b - 1 ] && $grid[ $a ][ $b ] === $grid[ $a - 1 ][ $b ] && $grid[ $a ][ $b ] === $grid[ $a - 1 ][ $b - 1 ] ) {
					$score += 3;
				}
			}
		}
		if ( $score < $low ) {
			$low  = $score;
			$best = $grid;
		}
	}
	return $best;
}

/** Inline SVG (dark modules on a white plate, so it scans in night mode too). */
function caw_qr_svg( $text, $label = '' ) {
	$m = caw_qr_matrix( $text );
	if ( ! $m ) {
		return '';
	}
	$size = count( $m );
	$path = '';
	foreach ( $m as $y => $row ) {
		foreach ( $row as $x => $on ) {
			if ( $on ) {
				$path .= 'M' . ( $x + 4 ) . ' ' . ( $y + 4 ) . 'h1v1h-1z';
			}
		}
	}
	$box = $size + 8;
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $box . ' ' . $box . '" role="img" aria-label="' . esc_attr( $label ) . '" shape-rendering="crispEdges"><rect width="' . $box . '" height="' . $box . '" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
}

/** 1-bit greyscale PNG with the 4-module quiet zone. */
function caw_qr_png( $text, $scale = 8 ) {
	$m = caw_qr_matrix( $text );
	if ( ! $m ) {
		return '';
	}
	$quiet = 4;
	$w     = ( count( $m ) + 2 * $quiet ) * $scale;
	$blank = str_repeat( "\xFF", (int) ceil( $w / 8 ) );
	$raw   = str_repeat( "\0" . $blank, $quiet * $scale );
	foreach ( $m as $row ) {
		$line = str_repeat( '1', $quiet * $scale );
		foreach ( $row as $on ) {
			$line .= str_repeat( $on ? '0' : '1', $scale );
		}
		$line  .= str_repeat( '1', $quiet * $scale );
		$packed = '';
		foreach ( str_split( str_pad( $line, strlen( $blank ) * 8, '1' ), 8 ) as $byte ) {
			$packed .= chr( bindec( $byte ) );
		}
		$raw .= str_repeat( "\0" . $packed, $scale );
	}
	$raw  .= str_repeat( "\0" . $blank, $quiet * $scale );
	$chunk = function ( $type, $body ) {
		return pack( 'N', strlen( $body ) ) . $type . $body . pack( 'N', crc32( $type . $body ) );
	};
	return "\x89PNG\r\n\x1A\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $w, 1, 0, 0, 0, 0 ) ) . $chunk( 'IDAT', gzcompress( $raw, 9 ) ) . $chunk( 'IEND', '' );
}
