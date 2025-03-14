<?php
/**
 * The hooks class.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use KrokedilZCODeps\Zaver\SDK\Object\PaymentUpdateRequest;
use KrokedilZCODeps\Zaver\SDK\Config\PaymentStatus;
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

		// Process the checkout before the payment is processed.
		add_action( 'woocommerce_checkout_process', array( $this, 'override_gateway_id' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_styles' ) );
	}

	/**
	 * Enqueue the checkout styles.
	 *
	 * @return void
	 */
	public function enqueue_checkout_styles() {
		if ( ! is_checkout() ) {
			return;
		}

		wp_register_style( 'zco-checkout', plugin_dir_url( ZCO_MAIN_FILE ) . '/assets/css/checkout.css', array(), Plugin::VERSION );
		wp_enqueue_style( 'zco-checkout' );
	}

	/**
	 * Override the gateway ID to match the chosen payment method.
	 *
	 * @return void
	 */
	public function override_gateway_id() {
		$payment_method = filter_input( INPUT_POST, 'payment_method', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( strpos( $payment_method, 'zaver_checkout' ) === false ) {
			return;
		}

		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
		$chosen_gateway     = $available_gateways[ Plugin::PAYMENT_METHOD ];
		$chosen_gateway->id = $payment_method;
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

		if ( ! isset( $args['order'] ) || ! $args['order'] instanceof WC_Order ) {
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

		ZCO()->logger()->debug( 'Rendering Zaver Checkout', array( 'orderId' => $order->get_id() ) );
		return ZCO_PLUGIN_PATH . '/templates/checkout.php';
	}

	/**
	 * Handle the payment callback from Zaver.
	 *
	 * @throws Exception If the order ID is missing or the order is not found.
	 * @return void
	 */
	public function handle_payment_callback() {
		$order = false;

		try {
			$payment_status = Plugin::gateway()->receive_payment_callback();
			$meta           = $payment_status->getMerchantMetadata();

			ZCO()->logger()->debug( 'Received Zaver payment callback', (array) $payment_status );

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
				ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ), array( 'orderId' => $order->get_id() ) );
			} else {
				ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ) );
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
		try {
			// Ensure we're on the correct endpoint.
			if ( ! is_order_received_page() ) {
				return;
			}

			$order = wc_get_order( get_query_var( 'order-received' ) );

			// Don't care about orders with other payment methods.
			if ( ! $order || strpos( $order->get_payment_method(), Plugin::PAYMENT_METHOD ) === false ) {
				return;
			}

			Payment_Processor::handle_response( $order );
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->update_status( 'failed', sprintf( __( 'Failed with Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ), array( 'orderId' => $order->get_id() ) );

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

			ZCO()->logger()->debug( 'Received Zaver refund callback', (array) $refund );

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
			ZCO()->logger()->error( sprintf( 'Failed with Zaver payment: %s', $e->getMessage() ), array( 'orderId' => $order->get_id() ) );

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

			$response = Plugin::gateway()->api()->updatePayment( $payment['id'], $update );
			$order->add_order_note( __( 'Cancelled Zaver payment', 'zco' ) );

			ZCO()->logger()->info(
				'Cancelled Zaver payment',
				array(
					'payload'   => $update,
					'response'  => $response,
					'orderId'   => $order->get_id(),
					'paymentId' => $payment['id'],
				)
			);
		} catch ( Exception $e ) {
			// translators: %s is the error message.
			$order->add_order_note( sprintf( __( 'Failed to cancel Zaver payment: %s', 'zco' ), $e->getMessage() ) );
			ZCO()->logger()->error(
				sprintf(
					'Failed to cancel Zaver payment: %s',
					$e->getMessage()
				),
				array(
					'payload'   => $update ?? null,
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
