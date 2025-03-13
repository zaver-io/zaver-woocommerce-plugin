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
$payment_methods         = apply_filters( 'zco_available_payment_methods', array_reverse( $payment_methods ) );

foreach ( $payment_methods as $payment_method ) {
	$gateway_id  = Plugin::PAYMENT_METHOD . '_' . strtolower( $payment_method['paymentMethod'] );
	$gateway     = $available_gateways[ Plugin::PAYMENT_METHOD ] ?? $available_gateways[ $gateway_id ];
	$gateway->id = $gateway_id;

	if ( isset( $payment_method['localizations'][ $site_locale ] ) ) {
		$i18n                 = $payment_method['localizations'][ $site_locale ];
		$gateway->title       = $i18n['title'];
		$gateway->description = $i18n['description'];
		$gateway->icon        = $i18n['iconSvgSrc'];
	} else {
		$gateway->title       = $payment_method['title'];
		$gateway->description = $payment_method['description'];
		$gateway->icon        = $payment_method['iconSvgSrc'];
	}

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
