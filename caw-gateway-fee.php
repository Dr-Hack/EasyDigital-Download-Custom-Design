<?php
/**
 * Gateway fee (e.g. 6.9% PayPal) — recalculated when the purchase is submitted.
 *
 * EDD Gateway Fees 1.5.5 keeps the fee in the cart session. On EVERY normal page load it
 * deletes it and recalculates it for the DEFAULT gateway (Aurpay = no fee); the PayPal fee
 * only comes back through an AJAX call when the checkout's gateway form loads. So a buyer
 * who picks PayPal and then loads any other page (another tab, back/forward) still SEES
 * the fee on the open checkout, but the PayPal order is built without it (order 9421).
 *
 * Fix: right before EDD copies the cart fees into the purchase data, set the fee again
 * from the gateway actually posted with the checkout form. Uses the plugin's own
 * calculation, label and filter, so the amount matches what the checkout displays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'edd_pre_process_purchase', 'caw_gateway_fee_at_purchase', 1 );

function caw_gateway_fee_at_purchase() {
	if ( ! class_exists( 'EDD_GF_Frontend' ) || ! function_exists( 'EDD' ) || empty( EDD()->fees ) ) {
		return; // plugin off: leave the cart alone
	}

	// Same source and sanitising as edd_purchase_form_validate_gateway().
	$gateway = ! empty( $_REQUEST['edd-gateway'] ) // phpcs:ignore WordPress.Security.NonceVerification -- EDD verifies the checkout nonce right after this hook
		? sanitize_text_field( wp_unslash( $_REQUEST['edd-gateway'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
		: edd_get_default_gateway();

	EDD()->fees->remove_fee( 'gateway_fee' );

	if ( ! edd_is_gateway_active( $gateway ) || 0 >= (float) edd_get_cart_total() ) {
		return; // EDD rejects an inactive gateway itself; a free cart goes to "manual"
	}

	try {
		// The plugin's calculator without its constructor (which would register its hooks again).
		$calc = ( new ReflectionClass( 'EDD_GF_Frontend' ) )->newInstanceWithoutConstructor();
		$fee  = $calc->calculate_gateway_fee( $gateway );
	} catch ( Throwable $e ) {
		edd_debug_log( 'CAW gateway fee: calculation failed: ' . $e->getMessage() );
		return;
	}

	$fee = apply_filters( 'edd_gf_fee_total_before_add_fee', $fee );
	if ( 0 == (float) $fee ) { // phpcs:ignore Universal.Operators.StrictComparisons
		return;
	}

	EDD()->fees->add_fee(
		array(
			'amount' => $fee,
			'label'  => edd_get_option( 'edd_gf_label_' . $gateway, edd_get_gateway_checkout_label( $gateway ) . ' ' . __( 'fee', 'edd_gf' ) ),
			'id'     => 'gateway_fee',
		)
	);
	edd_debug_log( 'CAW gateway fee: ' . $gateway . ' fee set to ' . $fee . ' at purchase' );
}
