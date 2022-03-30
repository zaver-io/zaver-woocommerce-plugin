<?php
namespace Zaver;
use Zaver\SDK\Config\RefundStatus;
use Zaver\SDK\Object\MerchantRepresentative;
use Zaver\SDK\Object\RefundCreationRequest;
use Zaver\SDK\Object\RefundUpdateRequest;
use Zaver\SDK\Object\RefundLineItem;
use Zaver\SDK\Object\RefundResponse;
use Zaver\SDK\Object\MerchantUrls;
use WC_Order;
use Exception;
use WC_Order_Item;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;

class Refund_Processor {
	static public function process(WC_Order $order, float $amount): void {
		$payment = $order->get_meta('_zaver_payment');

		if(empty($payment) || !is_array($payment) || !isset($payment['id'])) {
			throw new Exception('Missing Zaver payment ID for order');
		}

		$refund = self::find_refund($order, $amount);

		$request = RefundCreationRequest::create()
			->setPaymentId($payment['id'])
			->setInvoiceReference($order->get_order_number())
			->setRefundAmount(abs($refund->get_amount()))
			->setMerchantMetadata([
				'originPlatform' => 'woocommerce',
				'originWebsite' => home_url(),
				'originPage' => $order->get_created_via(),
				'customerId' => (string)$order->get_customer_id(),
				'orderId' => (string)$order->get_id(),
			]);

		$types = ['line_item', 'shipping', 'fee', 'coupon'];

		// Refunded line items
		if($items = $refund->get_items($types)) {
			foreach($items as $item) {
				$zaver_line_item_id = $item->get_meta('_zaver_line_item_id');

				if(!$zaver_line_item_id) continue;

				$line_item = RefundLineItem::create()
					->setLineItemId($zaver_line_item_id);

				self::prepare_item($line_item, $item);

				$request->addLineItem($line_item);
			}
		}

		// Refunded fixed amount
		else {
			$request->setRefundTaxAmount(abs($refund->get_total_tax()));
		}
		
		if($reason = $refund->get_reason()) {
			$request->setDescription((string)$reason);
		}

		if($representive = self::get_current_representative()) {
			$request->setInitializingRepresentative($representive);
		}

		if($callback_url = self::get_callback_url($order)) {
			$merchant_urls = MerchantUrls::create()->setCallbackUrl($callback_url);
			$request->setMerchantUrls($merchant_urls);
		}

		$response = Plugin::gateway()->refund_api()->createRefund($request);
		
		$refund->update_meta_data('_zaver_refund_id', $response->getRefundId());
		$refund->save_meta_data();
		$order->add_order_note(sprintf(__('Requested a refund of %F %s - refund ID: %s', 'zco'), $response->getRefundAmount(), $response->getCurrency(), $response->getRefundId()));

		Log::logger()->info('Requested a refund of %F %s', $response->getRefundAmount(), $response->getCurrency(), ['orderId' => $order->get_id(), 'refundId' => $response->getRefundId(), 'amount' => $amount, 'reason' => $refund->get_reason()]);

		if($representive = self::get_current_representative()) {
			Plugin::gateway()->refund_api()->approveRefund($response->getRefundId(), RefundUpdateRequest::create()->setActingRepresentative($representive));
		}
	}

	/**
	 * Return the last amount-matching refund of order. This is unfortunately the only way
	 * to get a `WC_Order_Refund` object from the current refund.
	 */
	static private function find_refund(WC_Order $order, $amount = null): WC_Order_Refund {
		$return = null;

		foreach($order->get_refunds() as $refund) {
			$refund_amount = (float)$refund->get_amount();

			if($refund_amount === $amount) {

				// Don't return directly, as there might be a more recent refund with the same amount
				$return = $refund;
			}
		}

		if(is_null($return)) {
			throw new Exception('No refund found');
		}

		return $return;
	}

	static private function get_callback_url(WC_Order $order): ?string {
		if(!Helper::is_https()) return null;

		return add_query_arg([
			'wc-api' => 'zaver_refund_callback',
			'key' => $order->get_order_key()
		], home_url());
	}

	static private function get_current_representative(): ?MerchantRepresentative {
		if(!is_user_logged_in()) return null;

		$user = wp_get_current_user();

		return MerchantRepresentative::create()->setUsername($user->user_email);
	}

	/**
	 * @param RefundLineItem $zaver_item
	 * @param WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee|WC_Order_Item_Coupon $wc_item
	 */
	static private function prepare_item(RefundLineItem $zaver_item, WC_Order_Item $wc_item): void {
		$tax = abs((float)$wc_item->get_total_tax());
		$total_price = abs((float)$wc_item->get_total() + $tax);
		$unit_price = $total_price / $wc_item->get_quantity();

		$zaver_item
			->setRefundTotalAmount($total_price)
			->setRefundTaxAmount($tax)
			->setRefundTaxRatePercent(Helper::get_line_item_tax_rate($wc_item))
			->setRefundQuantity($wc_item->get_quantity())
			->setRefundUnitPrice($unit_price);
	}

	static public function handle_response(WC_Order $order, RefundResponse $refund = null): void {

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
}