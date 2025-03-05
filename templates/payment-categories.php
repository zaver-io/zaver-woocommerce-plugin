<?php
/**
 * Replace the template checkout/payment-method.php with Zaver's payment categories.
 *
 * @package ZCO/templates
 */

use Zaver\Plugin;
use Zaver\Classes\Helpers\Cart;

$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
$gateway            = $available_gateways[ Plugin::PAYMENT_METHOD ];

$payment         = Cart::create();
$response        = Plugin::gateway()->api()->createPayment( $payment );
$payment_methods = $response->getSpecificPaymentMethodData();

$session = array();
foreach ( $payment_methods as $payment_method ) {
	$token       = $payment_method->getCheckoutToken();
	$link        = $payment_method->getPaymentLink();
	$id          = Plugin::PAYMENT_METHOD . '_' . strtolower( $payment_method->getPaymentMethod() );
	$pretty_name = ucwords( str_replace( '_', ' ', strtolower( $payment_method->getPaymentMethod() ) ) );

	$gateway        = $available_gateways[ Plugin::PAYMENT_METHOD ] ?? $available_gateways[ $id ];
	$gateway->id    = $id;
	$gateway->title = $pretty_name;

	$session[ $id ] = array(
		'token' => $token,
		'link'  => $link,
	);

	// For "Linear Checkout for WooCommerce by Cartimize" to work, we cannot output any HTML.
	if ( did_action( 'cartimize_get_payment_methods_html' ) === 0 ) {
		wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
	}
}

WC()->session->set( 'zaver_checkout_payment_methods', $session );
