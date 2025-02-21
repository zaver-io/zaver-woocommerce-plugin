<?php
/**
 * Replace the template checkout/payment-method.php with Ledyer's payment categories.
 */

use Zaver\Plugin;
use Zaver\Classes\Helpers\Cart;

$gateway_id = Plugin::PAYMENT_METHOD;
$order_id   = absint( get_query_var( 'order-pay', 0 ) );
if ( ! empty( $order_id ) ) {
	$_order = wc_get_order( $order_id );
}

$payment            = Cart::create();
$response           = Plugin::gateway()->api()->createPayment( $payment );
$payment_categories = $response->getSpecificPaymentMethodData();

if ( ! empty( $payment_categories ) && is_array( $payment_categories ) ) {
	$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
	$gateway            = $available_gateways[ $gateway_id ];
	$chosen_gateway     = $available_gateways[ array_key_first( $available_gateways ) ];

	foreach ( apply_filters( "{$gateway_id}_available_payment_categories", $payment_categories ) as $payment_category ) {
		$category_id   = '_' . strtolower( $payment_category['paymentMethod'] );
		$gateway_title = str_replace( '_', '', strtolower( $payment_category['paymentMethod'] ) );

		$gateway     = $available_gateways[ $gateway_id ] ?? $available_gateways[ $category_id ];
		$gateway->id = $category_id;
		// $gateway->icon        = $payment_category['assets']['urls']['logo'] ?? null;
		$gateway->title = $gateway_title;
		// $gateway->description = $payment_category['description'];

		// For "Linear Checkout for WooCommerce by Cartimize" to work, we cannot output any HTML.
		if ( did_action( 'cartimize_get_payment_methods_html' ) === 0 ) {
			wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
		}
	}
}
