<?php
namespace Zaver;

use Zaver\SDK\Object\PaymentUpdateRequest;
use Zaver\SDK\Config\PaymentStatus;
use Exception;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Hooks
 *
 * Registers the hooks for the plugin.
 */
final class Hooks {

	/**
	 * Get the instance of the hooks.
	 *
	 * @return Hooks
	 */
	public static function instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}


	/**
	 * Class constructor.
	 */
	private function __construct() {
		add_filter( 'wc_get_template', array( $this, 'get_zaver_checkout_template' ), 10, 3 );

		add_action( 'woocommerce_api_zaver_payment_callback', array( $this, 'handle_payment_callback' ) );
		add_action( 'woocommerce_api_zaver_refund_callback', array( $this, 'handle_refund_callback' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'cancelled_order' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'check_order_received' ) );
		add_action( 'zco_before_checkout', array( $this, 'add_cancel_link' ) );
	}

	/**
	 * Replace the checkout template with the Zaver Checkout template.
	 *
	 * @param string $template The template to replace.
	 * @param string $template_name The name of the template.
	 * @param array  $args The arguments passed to the template.
	 *
	 * @return string
	 */
	public function get_zaver_checkout_template( $template, $template_name, $args ) {
		if ( 'checkout/order-receipt.php' !== $template_name ) {
			return $template;
		}

		if ( ! ( isset( $args['order'] ) || $args['order'] instanceof WC_Order ) ) {
			return $template;
		}

		/**
		 * The WooCommerce order object.
		 *
		 * @var \WC_Order
		 */
		$order = $args['order'];

		if ( $order->get_payment_method() !== Plugin::PAYMENT_METHOD ) {
			return $template;
		}

		Log::logger()->debug( 'Rendering Zaver Checkout', array( 'orderId' => $order->get_id() ) );
		return ZCO_PLUGIN_PATH . '/templates/checkout.php';
	}

	/**
	 * Handle the payment callback from Zaver.
	 *
	 * @throws Exception If the order ID is missing or the order is not found.
	 * @return void
	 */
	public function handle_payment_callback() {
		try {
			$payment_status = Plugin::gateway()->receive_payment_callback();
			$meta           = $payment_status->getMerchantMetadata();

			Log::logger()->debug( 'Received Zaver payment callback', (array) $payment_status );

			if ( ! isset( $meta['orderId'] ) ) {
				throw new Exception( 'Missing order ID' );
			}

			$order = wc_get_order( $meta['orderId'] );

			if ( ! $order ) {
				throw new Exception( 'Order not found' );
			}

			Payment_Processor::handle_response( $order, $payment_status, false );
		} catch ( Exception $e ) {
			if ( $order ) {
				// translators: %s is the error message.
				$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
				Log::logger()->error( 'Failed with Zaver payment: %s', $e->getMessage(), array( 'orderId' => $order->get_id() ) );
			} else {
				Log::logger()->error( 'Failed with Zaver payment: %s', $e->getMessage() );
			}

			status_header( 400 );
		}
	}

	/**
	 * As the Zaver payment callback will only be called for sites over HTTPS,
	 * we need an alternative way for those sites on HTTP. This is it.
	 *
	 * @throws Exception If the order ID is missing or the order is not found.
	 * @return void
	 */
	public function check_order_received() {
		/**
		 * The global WP_Query instance.
		 *
		 * @var \WP_Query $wp
		 */
		global $wp;

		try {
			// Ensure we're on the correct endpoint.
			if ( ! is_order_received_page() ) {
				return;
			}

			// TODO: This can probably be replaced with get_query_var('order-received').
			$order = wc_get_order( $wp->query_vars['order-received'] );

			// Don't care about orders with other payment methods.
			if ( ! $order || $order->get_payment_method() !== Plugin::PAYMENT_METHOD ) {
				return;
			}

			Payment_Processor::handle_response( $order );
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			Log::logger()->error( 'Failed with Zaver payment: %s', $e->getMessage(), array( 'orderId' => $order->get_id() ) );

			wc_add_notice( __( 'An error occurred with your Zaver payment - please try again, or contact the site support.', 'zco' ), 'error' );

			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	}

	/**
	 * Handle the refund callback from Zaver.
	 *
	 * @throws Exception If the order ID is missing or the order is not found.
	 * @return void
	 */
	public function handle_refund_callback() {
		try {
			$refund = Plugin::gateway()->receive_refund_callback();
			$meta   = $refund->getMerchantMetadata();

			Log::logger()->debug( 'Received Zaver refund callback', (array) $refund );

			if ( ! isset( $meta['orderId'] ) ) {
				throw new Exception( 'Missing order ID' );
			}

			$order = wc_get_order( $meta['orderId'] );

			if ( ! $order ) {
				throw new Exception( 'Order not found' );
			}

			Refund_Processor::handle_response( $order, $refund );
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			Log::logger()->error( 'Failed with Zaver payment: %s', $e->getMessage(), array( 'orderId' => $order->get_id() ) );

			status_header( 400 );
		}
	}

	/**
	 * Cancel the Zaver payment when the order is cancelled.
	 *
	 * @throws Exception If the payment ID is missing.
	 *
	 * @param int      $order_id The WooCommerce order ID.
	 * @param WC_Order $order The WooCommerce order.
	 *
	 * @return void
	 */
	public function cancelled_order( $order_id, $order ) {
		$payment = $order->get_meta( '_zaver_payment' );
		if ( ! isset( $payment['id'] ) ) {
			return;
		}

		try {
			$update = PaymentUpdateRequest::create()
				->setPaymentStatus( PaymentStatus::CANCELLED );

			Plugin::gateway()->api()->updatePayment( $payment['id'], $update );

			$order->add_order_note( __( 'Cancelled Zaver payment', 'zco' ) );
			Log::logger()->info(
				'Cancelled Zaver payment',
				array(
					'orderId'   => $order->get_id(),
					'paymentId' => $payment['id'],
				)
			);
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->add_order_note( sprintf( __( 'Failed to cancel Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			Log::logger()->error(
				'Failed to cancel Zaver payment: %s',
				$e->getMessage(),
				array(
					'orderId'   => $order->get_id(),
					'paymentId' => $payment['id'],
				)
			);
		}
	}

	/**
	 * Prints a cancel link to the checkout page.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 *
	 * @return void
	 */
	public function add_cancel_link( $order ) {
		$url  = $order->get_cancel_order_url( wc_get_checkout_url() );
		$text = __( 'Change payment method', 'zco' );

		printf( '<p class="zco-cancel-order"><a href="%s">&larr; %s</a></p>', esc_url( $url ), esc_textarea( $text ) );
	}
}
