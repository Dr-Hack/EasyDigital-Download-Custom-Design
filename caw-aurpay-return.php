<?php
/**
 * Aurpay: stop paid buyers being sent back to the coin picker.
 *
 * Aurpay returns the buyer to the receipt page as soon as they pay, usually before its callback marks
 * the order paid. The plugin's receipt hook (aurpay_edd_cryptocoin_payment, priority 10) sees "pending",
 * asks for a fresh token and redirects to a new cashier, so the buyer thinks they must pay again.
 * The first visit still goes to Aurpay; after that a pending order shows a waiting notice that
 * reloads the receipt once the order is paid. "Go to payment" (?caw_aurpay_pay=1) re-opens the cashier.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CAW_AURPAY_GIVE_UP', 45 * MINUTE_IN_SECONDS );

add_action( 'edd_order_receipt_before_table', 'caw_aurpay_receipt', 9 );
function caw_aurpay_receipt( $order ) {
	if ( ! function_exists( 'aurpay_edd_cryptocoin_payment' ) || ! is_object( $order ) || 'edd_aurpay_gateway' !== $order->gateway || 'pending' !== $order->status ) {
		return;
	}
	$sent = (int) edd_get_order_meta( $order->id, '_caw_aurpay_sent', true );
	if ( ! $sent || ! empty( $_GET['caw_aurpay_pay'] ) ) {
		// The plugin redirects to Aurpay and exits; remember that this order has been sent there.
		edd_update_order_meta( $order->id, '_caw_aurpay_sent', time() );
		return;
	}
	remove_action( 'edd_order_receipt_before_table', 'aurpay_edd_cryptocoin_payment' );

	$late = time() - $sent > CAW_AURPAY_GIVE_UP;
	$pay  = add_query_arg( 'caw_aurpay_pay', 1 );
	$poll = add_query_arg( array( 'action' => 'caw_aurpay_status', 'key' => $order->payment_key ), admin_url( 'admin-ajax.php' ) );
	?>
	<section class="caw-aurpay-wait" aria-live="polite"<?php echo $late ? '' : ' data-poll="' . esc_url( $poll ) . '"'; ?>>
		<?php if ( $late ) : ?>
			<h2><?php esc_html_e( 'We haven\'t received your payment yet', 'mayosis' ); ?></h2>
			<p><?php esc_html_e( 'If you already paid, your order will update by itself once the payment is confirmed on the blockchain, and we\'ll email you. If it\'s been more than an hour, please contact us with your order number.', 'mayosis' ); ?></p>
		<?php else : ?>
			<h2><span class="caw-aurpay-spin" aria-hidden="true"></span><?php esc_html_e( 'Confirming your payment…', 'mayosis' ); ?></h2>
			<p><?php esc_html_e( 'Paid already? You don\'t need to do anything else. Confirmation usually takes a few minutes. This page will update by itself, and your order details (code, eSIM QR or download) will also be emailed to you in 5 to 10 minutes.', 'mayosis' ); ?></p>
		<?php endif; ?>
		<p class="caw-aurpay-alt">
			<?php esc_html_e( 'Haven\'t paid yet?', 'mayosis' ); ?>
			<a href="<?php echo esc_url( $pay ); ?>"><?php esc_html_e( 'Go to payment', 'mayosis' ); ?></a>
			&middot; <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact us', 'mayosis' ); ?></a>
		</p>
	</section>
	<?php if ( ! $late ) : ?>
	<script>
	(function () {
		var box = document.querySelector('.caw-aurpay-wait[data-poll]');
		if (!box) return;
		var url = box.getAttribute('data-poll'), polls = 0;
		function check() {
			fetch(url, { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					// Strip ?caw_aurpay_pay so the reload can't re-open the cashier.
					if (j && j.success && j.data.status !== 'pending') { location.replace(location.href.replace(/([?&])caw_aurpay_pay=[^&]*&?/, '$1').replace(/[?&]$/, '')); return; }
					if (++polls < 300) setTimeout(check, 8000);
				})
				.catch(function () { if (++polls < 300) setTimeout(check, 8000); });
		}
		setTimeout(check, 5000);
	})();
	</script>
	<?php endif;
}

add_action( 'wp_ajax_caw_aurpay_status', 'caw_aurpay_status' );
add_action( 'wp_ajax_nopriv_caw_aurpay_status', 'caw_aurpay_status' );
function caw_aurpay_status() {
	$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( ! $key || ! edd_can_view_receipt( $key ) ) {
		wp_send_json_error( null, 403 );
	}
	$order = edd_get_order_by( 'payment_key', $key );
	nocache_headers();
	wp_send_json_success( array( 'status' => $order ? $order->status : '' ) );
}
