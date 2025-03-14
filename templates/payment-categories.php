<?php
/**
 * Replace the template checkout/payment-method.php with Zaver's payment categories.
 *
 * @package ZCO/templates
 */

use Zaver\Plugin;
use KrokedilZCODeps\Zaver\SDK\Object\PaymentMethodsRequest;


$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
$gateway            = $available_gateways[ Plugin::PAYMENT_METHOD ];
$chosen_gateway     = $available_gateways[ array_key_first( $available_gateways ) ];
$site_locale        = str_replace( '_', '-', get_locale() );

$market                  = WC()->customer->get_billing_country();
$market                  = empty( $market ) ? wc_get_base_location()['country'] : $market;
$payment_methods_request = ( new PaymentMethodsRequest() )
	->setMarket( $market )
	->setAmount( WC()->cart->get_total( 'edit' ) )
	->setCurrency( get_woocommerce_currency() );
$payment_methods         = Plugin::gateway()->api()->getPaymentMethods( $payment_methods_request )->getPaymentMethods();

Zaver\ZCO()->logger()->info(
	'Received payment methods',
	array(
		'payload'        => wp_json_encode( $payment_methods_request ),
		'paymentMethods' => $payment_methods,
	)
);

if ( ! is_array( $payment_methods ) ) {
	return;
}
$payment_methods = apply_filters( 'zco_available_payment_methods', array_reverse( $payment_methods ) );

$i18n = include ZCO_PLUGIN_PATH . '/assets/i18n/payment-methods.php';

foreach ( $payment_methods as $payment_method ) {
	$name        = $payment_method['paymentMethod'];
	$gateway_id  = Plugin::PAYMENT_METHOD . '_' . strtolower( $name );
	$gateway     = $available_gateways[ Plugin::PAYMENT_METHOD ] ?? $available_gateways[ $gateway_id ];
	$gateway->id = $gateway_id;

	if ( ! isset( $i18n[ $name ][ $site_locale ] ) ) {
		$site_locale = 'sv_SE';
	}

	$gateway->title       = $i18n[ $name ][ $site_locale ]['title'];
	$gateway->subtitle    = $i18n[ $name ][ $site_locale ]['subtitle'];
	$gateway->description = $i18n[ $name ][ $site_locale ]['description'];

	// If Zaver is the chosen payment method...
	if ( false !== strpos( $chosen_gateway->id, Plugin::PAYMENT_METHOD ) || $gateway->chosen ) {
		$gateway->chosen = false;
		// ... set the first payment method received from Zaver as chosen.
		if ( $payment_method['paymentMethod'] === $payment_methods[ array_key_first( $payment_methods ) ]['paymentMethod'] ) {
			$gateway->chosen = true;
		}
	}

		// For "Linear Checkout for WooCommerce by Cartimize" to work, we cannot output any HTML.
	if ( did_action( 'cartimize_get_payment_methods_html' ) === 0 ) {
		wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
	}
}
