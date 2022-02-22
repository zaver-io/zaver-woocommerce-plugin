<?php
namespace Zaver;
use Zaver\SDK\Object\PaymentUpdateRequest;
use Zaver\SDK\Config\PaymentStatus;
use Exception;
use WC_Order;

final class Hooks {
	static public function instance(): self {
		static $instance = null;

		if(is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	private function __construct() {
		add_filter('wc_get_template', [$this, 'get_zaver_checkout_template'], 10, 3);

		add_action('woocommerce_api_zaver_payment_callback', [$this, 'handle_payment_callback']);
		add_action('woocommerce_api_zaver_refund_callback', [$this, 'handle_refund_callback']);
		add_action('woocommerce_order_status_cancelled', [$this, 'cancelled_order'], 10, 2);
		add_action('template_redirect', [$this, 'check_order_received']);
		add_action('zco_before_checkout', [$this, 'add_cancel_link']);
	}

	public function get_zaver_checkout_template(string $template, string $template_name, array $args): string {
		if($template_name === 'checkout/order-receipt.php' && isset($args['order']) && $args['order'] instanceof WC_Order) {

			/** @var WC_Order */
			$order = $args['order'];

			if($order->get_payment_method() === Plugin::PAYMENT_METHOD) {
				Log::logger()->debug('Rendering Zaver Checkout', ['orderId' => $order->get_id()]);

				return Plugin::PATH . '/templates/checkout.php';
			}
		}

		return $template;
	}

	public function handle_payment_callback(): void {
		try {
			$payment_status = Plugin::gateway()->receive_payment_callback();
			$meta = $payment_status->getMerchantMetadata();

			Log::logger()->debug('Received Zaver payment callback', (array)$payment_status);

			if(!isset($meta['orderId'])) {
				throw new Exception('Missing order ID');
			}

			$order = wc_get_order($meta['orderId']);

			if(!$order) {
				throw new Exception('Order not found');
			}

			Helper::handle_payment_response($order, $payment_status, false);
		}
		catch(Exception $e) {
			$order->update_status('failed', sprintf(__('Failed with Zaver payment: %s', 'zco'), $e->getMessage()));
			Log::logger()->error('Failed with Zaver payment: %s', $e->getMessage(), ['orderId' => $order->get_id()]);

			status_header(400);
		}
	}

	/**
	 * As the Zaver payment callback will only be called for sites over HTTPS,
	 * we need an alternative way for those sites on HTTP. This is it.
	 */
	public function check_order_received(): void {
		/** @var \WP_Query $wp */
		global $wp;

		try {
			// Ensure we're on the correct endpoint
			if(!isset($wp->query_vars['order-received'])) return;

			$order = wc_get_order($wp->query_vars['order-received']);

			// Don't care about orders with other payment methods
			if(!$order || $order->get_payment_method() !== Plugin::PAYMENT_METHOD) return;

			Helper::handle_payment_response($order);
		}
		catch(Exception $e) {
			$order->update_status('failed', sprintf(__('Failed with Zaver payment: %s', 'zco'), $e->getMessage()));
			Log::logger()->error('Failed with Zaver payment: %s', $e->getMessage(), ['orderId' => $order->get_id()]);

			wc_add_notice(__('An error occured with your Zaver payment - please try again, or contact the site support.', 'zco'), 'error');
			wp_redirect(wc_get_checkout_url());
			exit;
		}
	}

	public function cancelled_order(int $order_id, WC_Order $order): void {
		$payment = $order->get_meta('_zaver_payment');

		if(empty($payment) || !is_array($payment) || !isset($payment['id'])) return;

		try {
			$update = PaymentUpdateRequest::create()
				->setPaymentStatus(PaymentStatus::CANCELLED);

			Plugin::gateway()->api()->updatePayment($payment['id'], $update);

			$order->add_order_note(__('Cancelled Zaver payment', 'zco'));
			Log::logger()->info('Cancelled Zaver payment', ['orderId' => $order->get_id(), 'paymentId' => $payment['id']]);
		}
		catch(Exception $e) {
			$order->add_order_note(sprintf(__('Failed to cancel Zaver payment: %s', 'zco'), $e->getMessage()));
			Log::logger()->error('Failed to cancel Zaver payment: %s', $e->getMessage(), ['orderId' => $order->get_id(), 'paymentId' => $payment['id']]);
		}
	}

	public function add_cancel_link(WC_Order $order): void {
		printf('<p class="zco-cancel-order"><a href="%s">&larr; %s</a></p>', $order->get_cancel_order_url(wc_get_checkout_url()), __('Change payment method', 'zco'));
	}

	public function handle_refund_callback(): void {
		try {
			$refund = Plugin::gateway()->receive_refund_callback();
			$meta = $refund->getMerchantMetadata();

			Log::logger()->debug('Received Zaver refund callback', (array)$refund);

			if(!isset($meta['orderId'])) {
				throw new Exception('Missing order ID');
			}

			$order = wc_get_order($meta['orderId']);

			if(!$order) {
				throw new Exception('Order not found');
			}

			Helper::handle_refund_response($order, $refund);
		}
		catch(Exception $e) {
			$order->update_status('failed', sprintf(__('Failed with Zaver payment: %s', 'zco'), $e->getMessage()));
			Log::logger()->error('Failed with Zaver payment: %s', $e->getMessage(), ['orderId' => $order->get_id()]);

			status_header(400);
		}
	}
}