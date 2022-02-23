<?php
namespace Zaver;
use Zaver\SDK\Object\PaymentStatusResponse;
use Zaver\SDK\Config\PaymentStatus;
use Zaver\SDK\Object\RefundResponse;
use Zaver\SDK\Config\RefundStatus;
use Exception;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;
use WC_Tax;
use WP_Error;
use Zaver\SDK\Config\ItemType;

class Helper {
	static public function handle_payment_response(WC_Order $order, ?PaymentStatusResponse $payment_status = null, bool $redirect = true): void {

		// Ignore orders that are already paid
		if(!$order->needs_payment()) return;

		// Ensure that the order key is correct
		if(!isset($_GET['key']) || !hash_equals($order->get_order_key(), wc_clean(wp_unslash($_GET['key'])))) {
			throw new Exception('Invalid order key');
		}

		$payment = $order->get_meta('_zaver_payment');

		if(empty($payment) || !is_array($payment) || !isset($payment['id'])) {
			throw new Exception('Missing payment ID on order');
		}

		if(is_null($payment_status)) {
			$payment_status = Plugin::gateway()->api()->getPaymentStatus($payment['id']);
		}
		elseif($payment_status->getPaymentId() !== $payment['id']) {
			throw new Exception('Mismatching payment ID');
		}

		switch($payment_status->getPaymentStatus()) {
			case PaymentStatus::SETTLED:
				$order->payment_complete($payment_status->getPaymentId());
				$order->add_order_note(sprintf(__('Successful payment with Zaver - payment ID: %s', 'zco'), $payment_status->getPaymentId()));
				Log::logger()->info('Successful payment with Zaver', ['orderId' => $order->get_id(), 'paymentId' => $payment_status->getPaymentId()]);
				break;
			
			case PaymentStatus::CANCELLED:
				Log::logger()->info('Zaver Payment was cancelled', ['orderId' => $order->get_id(), 'paymentId' => $payment_status->getPaymentId()]);

				if($redirect) {
					wp_redirect($order->get_cancel_order_url());
					exit;
				}
				
				$order->update_status('cancelled', __('Zaver payment was cancelled - cancelling order', 'zco'));
				break;
			
			case PaymentStatus::CREATED:
				Log::logger()->debug('Zaver Payment is still in CREATED state', ['orderId' => $order->get_id(), 'paymentId' => $payment_status->getPaymentId()]);

				if($redirect) {
					wp_redirect($order->get_checkout_payment_url(true));
					exit;
				}

				// Do nothing
				break;
		}
	}

	static public function handle_refund_response(WC_Order $order, RefundResponse $refund = null): void {

		// Ensure that the order key is correct
		if(!isset($_GET['key']) || !hash_equals($order->get_order_key(), wc_clean(wp_unslash($_GET['key'])))) {
			throw new Exception('Invalid order key');
		}

		$refund_ids = $order->get_meta('_zaver_refund_ids');

		if(empty($refund_ids) || !is_array($refund_ids)) {
			throw new Exception('Missing refund ID on order');
		}

		if(!in_array($refund->getPaymentId(), $refund_ids)) {
			throw new Exception('Mismatching refund ID');
		}

		switch($refund->getStatus()) {
			case RefundStatus::PENDING_MERCHANT_APPROVAL:
				if($username = $refund->getInitializingRepresentative()->getUsername()) {
					$order->add_order_note(sprintf(__('Refund of %F %s initialized by %s with the description "%s". Refund ID: %s', 'zco'), $refund->getRefundAmount(), $refund->getCurrency(), $username, $refund->getDescription(), $refund->getRefundId()));
				}
				else {
					$order->add_order_note(sprintf(__('Refund of %F %s initialized with the description "%s". Refund ID: %s', 'zco'), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getDescription(), $refund->getRefundId()));
				}

				Log::logger()->info('Refund of %F %s approved', $refund->getRefundAmount(), $refund->getCurrency(), ['orderId' => $order->get_id(), 'refundId' => $refund->getRefundId()]);
				break;
			
			case RefundStatus::PENDING_EXECUTION:
				if($username = $refund->getApprovingRepresentative()->getUsername()) {
					$order->add_order_note(sprintf(__('Refund of %F %s approved by %s - Refund ID: %s', 'zco'), $refund->getRefundAmount(), $refund->getCurrency(), $username, $refund->getRefundId()));
				}
				else {
					$order->add_order_note(sprintf(__('Refund of %F %s approved - Refund ID: %s', 'zco'), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getRefundId()));
				}

				Log::logger()->info('Refund of %F %s approved', $refund->getRefundAmount(), $refund->getCurrency(), ['orderId' => $order->get_id(), 'refundId' => $refund->getRefundId()]);
				break;
			
			case RefundStatus::EXECUTED:
				$order->add_order_note(sprintf(__('Refund of %F %s completed - Refund ID: %s', 'zco'), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getRefundId()));
				Log::logger()->info('Refund of %F %s completed', $refund->getRefundAmount(), $refund->getCurrency(), ['orderId' => $order->get_id(), 'refundId' => $refund->getRefundId()]);
				break;
			
			case RefundStatus::CANCELLED:
				if($username = $refund->getApprovingRepresentative()->getUsername()) {
					$order->add_order_note(sprintf(__('Refund of %F %s cancelled by %s - Refund ID: %s', 'zco'), $refund->getRefundAmount(), $refund->getCurrency(), $username, $refund->getRefundId()));
				}
				else {
					$order->add_order_note(sprintf(__('Refund of %F %s cancelled - Refund ID: %s', 'zco'), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getRefundId()));
				}

				Log::logger()->info('Refund of %F %s cancelled', $refund->getRefundAmount(), $refund->getCurrency(), ['orderId' => $order->get_id(), 'refundId' => $refund->getRefundId()]);
				break;
		}
	}

	static public function wp_error(Exception $e, $data = null): WP_Error {
		return new WP_Error($e->getCode() ?: 'error', $e->getMessage(), $data);
	}

	static public function get_line_item_tax_rate(WC_Order_Item $item, bool $is_shipping = false): float {
		$order = $item->get_order();
		$args = [
			'country'   => $order->get_billing_country(),
			'state'     => $order->get_billing_state(),
			'city'      => $order->get_billing_city(),
			'postcode'  => $order->get_billing_postcode(),
			'tax_class' => $item->get_tax_class(),
		];

		$rates = ($is_shipping ? WC_Tax::find_shipping_rates($args) : WC_Tax::find_rates($args));

		if(empty($rates)) {
			return 0;
		}

		return (float)end($rates)['rate'];
	}

	static public function get_zaver_item_type(WC_Product $product): string {
		return ($product->is_virtual() ? ItemType::DIGITAL : ItemType::PHYSICAL);
	}
}