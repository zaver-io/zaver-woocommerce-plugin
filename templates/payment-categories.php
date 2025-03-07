<?php
/**
 * Replace the template checkout/payment-method.php with Zaver's payment categories.
 *
 * @package ZCO/templates
 */

use Zaver\Plugin;
use Zaver\Classes\Helpers\Cart;


$payment         = Cart::create();
$response        = Plugin::gateway()->api()->createPayment( $payment );
$payment_methods = $response->getSpecificPaymentMethodData();

$session            = array();
$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
$gateway            = $available_gateways[ Plugin::PAYMENT_METHOD ];
$chosen_gateway     = $available_gateways[ array_key_first( $available_gateways ) ];

foreach ( $payment_methods as $payment_method ) {
	$checkout_token = $payment_method->getCheckoutToken();
	$payment_link   = $payment_method->getPaymentLink();
	$gateway_id     = Plugin::PAYMENT_METHOD . '_' . strtolower( $payment_method->getPaymentMethod() );
	$pretty_name    = ucwords( str_replace( '_', ' ', strtolower( $payment_method->getPaymentMethod() ) ) );

	$gateway        = $available_gateways[ Plugin::PAYMENT_METHOD ] ?? $available_gateways[ $gateway_id ];
	$gateway->id    = $gateway_id;
	$gateway->title = $pretty_name;

	$session[ $gateway_id ] = array(
		'token' => $checkout_token,
		'link'  => $payment_link,
	);

	// If Zaver is the chosen payment method...
	if ( false !== strpos( $chosen_gateway->id, Plugin::PAYMENT_METHOD ) || $gateway->chosen ) {
		$gateway->chosen = false;
		// ... set the first payment method received from Zaver as chosen.
		if ( $payment_method->getPaymentMethod() === $payment_methods[ array_key_first( $payment_methods ) ]->getPaymentMethod() ) {
			$gateway->chosen = true;
		}
	}

		// For "Linear Checkout for WooCommerce by Cartimize" to work, we cannot output any HTML.
	if ( did_action( 'cartimize_get_payment_methods_html' ) === 0 ) {
		wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
	}
}

WC()->session->set( 'zaver_checkout_payment_methods', $session );
