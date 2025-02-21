<?php
/**
 * The Session class.
 *
 * @package ZCO/Classes
 */

namespace Zaver\Classes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Session
 *
 * Manages the checkout session.
 */
class Session {

	/**
	 * Class constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'get_session' ), 999999 );
	}

	/**
	 * Create or update Zaver payment session.
	 */
	public function get_session() {
		if ( ( ! is_checkout() && ! is_checkout_pay_page() ) || is_order_received_page() ) {
			return;
		}

		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( ! isset( $gateways[ \Zaver\Plugin::PAYMENT_METHOD ] ) ) {
			return;
		}
	}
}
