<?php
/**
 * Checkout Template
 *
 * This template is used for displaying the checkout page in the Zaver WooCommerce plugin.
 *
 * @package ZCO/templates
 */

namespace Zaver;

$payment = $order->get_meta( '_zaver_payment' );
if ( ! $payment ) {
	wp_die( 'Invalid order' );
}

Log::logger()->debug(
	'Rendered Zaver Checkout',
	array(
		'orderId'   => $order->get_id(),
		'paymentId' => $payment['id'],
	)
);

do_action( 'zco_before_checkout', $order );
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to inject an HTML snippet.
echo Plugin::gateway()->get_html_snippet( $payment['token'] );
do_action( 'zco_after_checkout', $order );
?>

<style>
	main .entry-title { display: none; }
	.zco-cancel-order { text-align: center; white-space: nowrap; }
</style>