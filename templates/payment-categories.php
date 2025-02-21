<?php
/**
 * Replace the template checkout/payment-method.php with Zaver's payment categories.
 *
 * @package ZCO/templates
 */

use Zaver\Plugin;
use Zaver\Classes\Helpers\Cart;

$token = WC()->session->get( 'zaver_token' );
if ( empty( $token ) ) {
	$payment    = Cart::create();
	$response   = Plugin::gateway()->api()->createPayment( $payment );
	$categories = $response->getSpecificPaymentMethodData();
	$token      = $response->getToken();

	WC()->session->set( 'zaver_token', $token );
	WC()->session->set( 'zaver_html_snippet', Plugin::gateway()->get_html_snippet( $token ) );
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- We need to inject an HTML snippet.
echo WC()->session->get( 'zaver_html_snippet' );
