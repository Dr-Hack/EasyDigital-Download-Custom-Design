<?php
/**
 * EDD Reviews 2.3.0 — temporary security hardening + reviewer-email privacy.
 *
 * Part A is EDD support's workaround for the three bugs we reported (2026-09-01),
 * plus one addition: EDD's own Approve/Unapprove only writes the
 * `edd_review_approved` meta, so we mirror it onto comment_approved.
 * Remove Part A once EDD Reviews ships a fix.
 *
 * Part B stops ~1,170 users whose display_name is their email address from
 * having it published (review bylines, JSON-LD, comment classes, author archives).
 * Display-only — no stored data is changed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
   A. EDD Reviews hardening (EDD support's snippet)
   ========================================================================= */

/**
 * 1 & 2: real nonce check + server-side "verified buyers only".
 * Priority 1 runs before EDD Reviews' process_review() at 10.
 */
add_action( 'edd_reviews_process_review', function () {
	$nonce = isset( $_POST['edd_reviews_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['edd_reviews_nonce'] ) ) : '';
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'edd_reviews_nonce' ) ) {
		wp_die( esc_html__( 'Nonce verification has failed', 'edd-reviews' ), esc_html__( 'Error', 'edd-reviews' ), array( 'response' => 403 ) );
	}

	if ( ! function_exists( 'edd_get_option' ) ) {
		return;
	}

	// The plugin's own form gate skips the buyer check when guest reviews are on.
	if ( ! edd_get_option( 'edd_reviews_only_allow_reviews_by_buyer', false ) || edd_get_option( 'edd_reviews_enable_guest_reviews', false ) ) {
		return;
	}

	$post_id = isset( $_POST['edd-reviews-review-post-ID'] ) ? absint( $_POST['edd-reviews-review-post-ID'] ) : 0;
	$user_id = get_current_user_id();

	if ( ! $user_id || ! $post_id || ! edd_has_user_purchased( $user_id, $post_id ) ) {
		wp_die( esc_html__( 'You must be a buyer of this download to submit a review.', 'edd-reviews' ), esc_html__( 'Error', 'edd-reviews' ), array( 'response' => 403 ) );
	}
}, 1 );

/**
 * 3: honour WordPress moderation instead of the hard-coded comment_approved => 1.
 * wp_allow_comment() returns 1, 0, 'spam' or 'trash'; true = WP_Error instead of dying.
 */
add_filter( 'edd_reviews_insert_review_args', function ( $args ) {
	$approved                 = wp_allow_comment( $args, true );
	$args['comment_approved'] = is_wp_error( $approved ) ? 0 : $approved;
	return $args;
}, 999 );

/**
 * EDD Reviews shows/hides reviews by the `edd_review_approved` meta, and its admin
 * Approve/Unapprove buttons only touch that meta. Mirror it onto the comment row so
 * approved reviews don't sit in WP's "Pending" count forever, and so WP's
 * "previously approved author" rule recognises the reviewer next time.
 */
function caw_sync_review_approval( $meta_id, $comment_id, $meta_key, $meta_value ) {
	if ( 'edd_review_approved' !== $meta_key || ! in_array( (string) $meta_value, array( '0', '1' ), true ) ) {
		return;
	}
	$comment = get_comment( $comment_id );
	if ( ! $comment || 'edd_review' !== $comment->comment_type || (string) $meta_value === (string) $comment->comment_approved ) {
		return;
	}
	global $wpdb;
	$wpdb->update( $wpdb->comments, array( 'comment_approved' => (string) $meta_value ), array( 'comment_ID' => $comment->comment_ID ) );
	clean_comment_cache( $comment->comment_ID );
	wp_update_comment_count( $comment->comment_post_ID );
}
add_action( 'added_comment_meta', 'caw_sync_review_approval', 10, 4 );
add_action( 'updated_comment_meta', 'caw_sync_review_approval', 10, 4 );

/* =========================================================================
   B. Never publish a customer's email address as their name
   ========================================================================= */

/** "kazimuit@gmail.com" -> "kaz***". Anything that isn't an email passes through. */
function caw_mask_email_name( $name ) {
	if ( ! is_string( $name ) || false === strpos( $name, '@' ) ) {
		return $name;
	}
	// "mrbeast.management@outlook.com Beast" — mask any email embedded in the name.
	return preg_replace_callback(
		'/([^\s@]+)@[^\s@]+\.[^\s@]+/',
		function ( $m ) {
			return mb_substr( $m[1], 0, min( 3, max( 1, mb_strlen( $m[1] ) - 1 ) ) ) . '***';
		},
		$name
	);
}

function caw_mask_public_name( $name ) {
	return is_admin() ? $name : caw_mask_email_name( $name );
}
// Review bylines (walker, shortcodes, widgets) and EDD Reviews' JSON-LD all go through get_comment_author().
add_filter( 'get_comment_author', 'caw_mask_public_name', 99 );
add_filter( 'the_author', 'caw_mask_public_name', 99 );
add_filter( 'get_the_author_display_name', 'caw_mask_public_name', 99 );

// <li class="comment-author-umairfarooq620gmail-com"> — the class is built from user_nicename.
add_filter( 'comment_class', function ( $classes ) {
	return array_values( array_filter( (array) $classes, function ( $c ) {
		return 0 !== strpos( $c, 'comment-author-' );
	} ) );
}, 99 );

/**
 * Buyers aren't authors, yet WP serves /author/<email-slug>/ (and ?author=N) for every
 * account, titled with the display name — i.e. an enumerable list of customer emails.
 * 404 the archive unless the user has published something (blog posts or products).
 */
add_action( 'template_redirect', function () {
	if ( ! is_author() ) {
		return;
	}
	$author = get_queried_object();
	if ( $author instanceof WP_User && count_user_posts( $author->ID, array( 'post', 'download' ), true ) > 0 ) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}, 1 );
