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

$mapping = array(
	'BANK_TRANSFER'   => array(
		'id'          => 'bank_transfer',
		'icon_id'     => 'BankTransfer.svg',
		'pretty_name' => 'Bank Transfer',
	),
	'INSTALLMENTS'    => array(
		'id'          => 'installments',
		'icon_id'     => 'Installments.svg',
		'pretty_name' => 'Installments',
	),
	'INVOICE'         => array(
		'id'          => 'invoice',
		'icon_id'     => 'Invoice.svg',
		'pretty_name' => 'Invoice',
	),
	'MONTHLY_INVOICE' => array(
		'id'          => 'monthly_invoice',
		'icon_id'     => 'MonthlyInvoice.svg',
		'pretty_name' => 'Monthly Invoice',
	),
	'PAY_NOW'         => array(
		'id'          => 'pay_now',
		'icon_id'     => 'PayNow.svg',
		'pretty_name' => 'Pay Now',
	),
	'SWISH'           => array(
		'id'          => 'swish',
		'icon_id'     => 'Swish.svg',
		'pretty_name' => 'Swish',
	),
	'VIPPS'           => array(
		'id'          => 'vipps',
		'icon_id'     => 'Vipps.svg',
		'pretty_name' => 'Vipps',
	),
);

foreach ( $payment_methods as $payment_method ) {
	$checkout_token = $payment_method->getCheckoutToken();
	$payment_link   = $payment_method->getPaymentLink();

	$is_mapped = isset( $mapping[ $payment_method->getPaymentMethod() ] );
	if ( $is_mapped ) {
		$mapped_method = $mapping[ $payment_method->getPaymentMethod() ];
		$gateway_id    = Plugin::PAYMENT_METHOD . "_{$mapped_method['id']}";
		$pretty_name   = $mapped_method['pretty_name'];
	} else {
		$gateway_id  = Plugin::PAYMENT_METHOD . '_' . strtolower( $payment_method->getPaymentMethod() );
		$pretty_name = ucwords( str_replace( '_', ' ', strtolower( $payment_method->getPaymentMethod() ) ) );
	}

	$gateway        = $available_gateways[ Plugin::PAYMENT_METHOD ] ?? $available_gateways[ $gateway_id ];
	$gateway->id    = $gateway_id;
	$gateway->title = $pretty_name;

	$default_icon_url = plugin_dir_url( ZCO_MAIN_FILE ) . 'assets/icon.svg';
	$icon_path_url    = $default_icon_url;
	if ( $is_mapped ) {
		$icon_type     = 'Icon-Plate'; // 'Icon' or 'Icon-Plate'.
		$icon_path_url = plugin_dir_url( ZCO_MAIN_FILE ) . "assets/payment-methods/{$icon_type}-{$mapped_method['icon_id']}";
	}
	$gateway->icon = $icon_path_url;

	$session[ $gateway_id ] = array(
		'token' => $checkout_token,
		'link'  => $payment_link,
	);

	// If Zaver is the chosen payment method...
	if ( false !== strpos( $chosen_gateway->id, Plugin::PAYMENT_METHOD ) || $gateway->chosen ) {
		$gateway->chosen = false;
		// ... set the first payment method received from Zaver as chosen.
		if ( $payment_method === $payment_methods[ array_key_first( $payment_methods ) ]->getPaymentMethod() ) {
			$gateway->chosen = true;
		}
	}

		// For "Linear Checkout for WooCommerce by Cartimize" to work, we cannot output any HTML.
	if ( did_action( 'cartimize_get_payment_methods_html' ) === 0 ) {
		wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
	}
}

WC()->session->set( 'zaver_checkout_payment_methods', $session );
