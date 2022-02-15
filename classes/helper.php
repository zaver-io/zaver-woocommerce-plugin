<?php
namespace Zaver;
use Zaver\SDK\Object\PaymentStatusResponse;
use Zaver\SDK\Config\PaymentStatus;
use Exception;
use WC_Order;

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
				break;
			
			case PaymentStatus::CANCELLED:
				if($redirect) {
					wp_redirect($order->get_cancel_order_url());
					exit;
				}
				
				$order->update_status('cancelled', __('Zaver payment cancelled - cancelling order', 'zco'));
				break;
			
			case PaymentStatus::CREATED:
				if($redirect) {
					wp_redirect($order->get_checkout_payment_url(true));
					exit;
				}

				// Do nothing
				break;
		}
	}
}