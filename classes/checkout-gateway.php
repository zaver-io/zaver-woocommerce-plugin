<?php
namespace Zaver;
use Zaver\SDK\Checkout;
use Zaver\SDK\Refund;
use Zaver\SDK\Config\ItemType;
use Zaver\SDK\Object\PaymentCreationRequest;
use Zaver\SDK\Object\MerchantUrls;
use Zaver\SDK\Object\PaymentStatusResponse;
use Zaver\SDK\Object\MerchantRepresentative;
use Zaver\SDK\Object\RefundCreationRequest;
use Zaver\SDK\Object\RefundResponse;
use Zaver\SDK\Object\RefundUpdateRequest;
use Zaver\SDK\Object\RefundedLineItem;
use Zaver\SDK\Object\LineItem;
use WC_Order;
use WC_Payment_Gateway;
use Exception;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;

class Checkout_Gateway extends WC_Payment_Gateway {
	private $api_instance = null;
	private $refund_instance = null;

	public function __construct() {
		$this->id = Plugin::PAYMENT_METHOD;
		$this->has_fields = false;
		$this->method_title = __('Zaver Checkout', 'zco');

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->order_button_text = apply_filters('zco_order_button_text', __('Pay with Zaver', 'zco'));
		$this->supports = ['products', 'refunds'];

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
	}

	public function init_form_fields(): void {
		$this->form_fields = [
			'enabled' => [
				'type'    => 'checkbox',
				'default' => 'yes',
				'title'   => __('Enable/Disable', 'zco'),
				'label'   => __('Enable Zaver Checkout', 'zco'),
			],
			'title' => [
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __('Title', 'zco'),
				'description' => __('This controls the title which the user sees during checkout.', 'zco'),
				'default'     => __('Zaver Checkout', 'zco'),
			],
			'description' => [
				'type'    => 'textarea',
				'default' => '',
				'title'   => __('Customer Message', 'zco'),
			],
			'test_mode' => array(
				'type'        => 'checkbox',
				'default'     => 'no',
				'desc_tip'    => true,
				'title'       => __('Test mode', 'zco'),
				'label'       => __('Enable test mode', 'zco'),
				'description' => __('If you received any test credentials from Zaver, this checkbox should be checked.', 'zco'),
			),
			'api_key' => [
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __('API Key', 'zco'),
				'description' => __('The API key you got from Zaver.', 'zco'),
			],
			'callback_token' => [
				'type'        => 'text',
				'desc_tip'    => true,
				'title'       => __('Callback Token', 'zco'),
				'description' => __('The callback token you got from Zaver.', 'zco'),
			],
			'primary_color' => [
				'type'        => 'color',
				'desc_tip'    => true,
				'title'       => 'Primary color',
				'description' => '',
				'placeholder' => __('Default', 'zco'),
			],
			'secondary_color' => [
				'type'        => 'color',
				'desc_tip'    => true,
				'title'       => 'Secondary color',
				'description' => '',
				'placeholder' => __('Default', 'zco'),
			]
		];
	}

	public function process_payment($order_id): ?array {
		try {
			$order = wc_get_order($order_id);

			if(!$order instanceof WC_Order) {
				throw new Exception('Order not found');
			}

			$merchant_urls = MerchantUrls::create()
				->setCallbackUrl($this->get_payment_callback_url($order))
				->setSuccessUrl($this->get_return_url($order));

			$payment = PaymentCreationRequest::create()
				->setMerchantPaymentReference($order->get_order_number())
				->setAmount($order->get_total())
				->setCurrency($order->get_currency())
				->setMarket($order->get_billing_country())
				->setMerchantUrls($merchant_urls)
				->setMerchantMetadata([
					'originPlatform' => 'woocommerce',
					'originWebsite' => home_url(),
					'originPage' => $order->get_created_via(),
					'customerId' => (string)$order->get_customer_id(),
					'orderId' => (string)$order->get_id(),
				])
				->setTitle($this->get_purchase_title($order));
			
			$types = ['line_item', 'shipping', 'fee', 'coupon'];
			
			foreach($order->get_items($types) as $item) {
				$line_item = LineItem::create()
					->setName($item->get_name())
					->setQuantity($item->get_quantity())
					->setMerchantMetadata(['orderItemId' => $item->get_id()]);
				
				if($item->is_type('line_item')) {
					$this->prepare_line_item($line_item, $item);
				}
				elseif($item->is_type('shipping')) {
					$this->prepare_shipping($line_item, $item);
				}
				elseif($item->is_type('fee')) {
					$this->prepare_fee($line_item, $item);
				}
				elseif($item->is_type('coupon')) {
					$this->prepare_coupon($line_item, $item);
				}

				$payment->addLineItem($line_item);
			}

			$response = $this->api()->createPayment($payment);

			$order->update_meta_data('_zaver_payment', [
				'id' => $response->getPaymentId(),
				'token' => $response->getToken(),
				'tokenValidUntil' => $response->getValidUntil()
			]);

			$order->save_meta_data();

			Log::logger()->debug('Created Zaver payment request', ['orderId' => $order_id, 'paymentId' => $response->getPaymentId()]);

			return [
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url(true)
			];
		}
		catch(Exception $e) {
			Log::logger()->error('Zaver error during payment process: %s', $e->getMessage(), ['orderId' => $order_id]);

			wc_add_notice(__('An error occured - please try again, or contact site support', 'zco'), 'error');
			return null;
		}
	}

	public function process_refund($order_id, $amount = null, $reason = '') {
		try {
			if(empty($amount)) {
				throw new Exception('No refund amount specified');
			}

			$order = wc_get_order($order_id);

			if(!$order instanceof WC_Order) {
				throw new Exception('Order not found');
			}

			$payment = $order->get_meta('_zaver_payment');

			if(empty($payment) || !is_array($payment) || !isset($payment['id'])) {
				throw new Exception('Missing Zaver payment ID for order');
			}

			$refund = $this->get_refund($order, $amount);

			$this->create_refund_request($order, $refund, $payment['id']);

			return true;
		}
		catch(Exception $e) {
			Log::logger()->error('Zaver error during refund process: %s', $e->getMessage(), ['orderId' => $order_id, 'amount' => $amount, 'reason' => $reason]);

			return Helper::wp_error($e);
		}
	}

	private function create_refund_request(WC_Order $order, WC_Order_Refund $refund, string $payment_id): void {
		$request = RefundCreationRequest::create()
			->setPaymentId($payment_id)
			->setInvoiceReference($order->get_order_number())
			->setRefundAmount($refund->get_amount())
			->setRefundTaxAmount($refund->get_total_tax())
			->setCallbackUrl($this->get_refund_callback_url($order))
			->setMerchantMetadata([
				'originPlatform' => 'woocommerce',
				'originWebsite' => home_url(),
				'originPage' => $order->get_created_via(),
				'customerId' => (string)$order->get_customer_id(),
				'orderId' => (string)$order->get_id(),
			]);

			// Refunded line items
			if($item = $refund->get_items()) {
				$line_item = RefundedLineItem::create();

				$request->addLineItem($line_item);
			}

			throw new Exception('Hello');

			
			
			if(!empty($reason) && is_string($reason)) {
				$request->setDescription($reason);
			}

			if(is_user_logged_in()) {
				$user = wp_get_current_user();
				$representive = MerchantRepresentative::create()
					->setUsername($user->user_email);

				$request->setInitializingRepresentative($representive);
			}

			$response = $this->refund_api()->createRefund($request);
			
			$order->update_meta_data('_zaver_refund_ids', [...($order->get_meta('_zaver_refunds') ?: []), $response->getRefundId()]);
			$order->save_meta_data();
			$order->add_order_note(sprintf(__('Requested a refund of %F %s - refund ID: %s', 'zco'), $response->getRefundAmount(), $response->getCurrency(), $response->getRefundId()));

			Log::logger()->info('Requested a refund of %F %s', $response->getRefundAmount(), $response->getCurrency(), ['orderId' => $order->get_id(), 'refundId' => $response->getRefundId(), 'amount' => $amount, 'reason' => $reason]);

			if(is_user_logged_in()) {
				$user = wp_get_current_user();
				$representive = MerchantRepresentative::create()
					->setUsername($user->user_email);

				$this->refund_api()->approveRefund($response->getRefundId(), RefundUpdateRequest::create()->setActingRepresentative($representive));
			}
	}

	public function can_refund_order($order) {
		if(!$order instanceof WC_Order || !$this->supports('refunds')) return false;

		$payment = $order->get_meta('_zaver_payment');

		return (
			!empty($payment) &&
			is_array($payment) &&
			isset($payment['id'])
		);
	}

	public function api(): Checkout {
		if(is_null($this->api_instance)) {
			$this->api_instance = new Checkout($this->get_option('api_key', ''), $this->get_option('test_mode') === 'yes');
		}

		return $this->api_instance;
	}

	public function refund_api(): Refund {
		if(is_null($this->refund_instance)) {
			$this->refund_instance = new Refund($this->get_option('api_key', ''), $this->get_option('test_mode') === 'yes');
		}

		return $this->refund_instance;
	}

	public function get_html_snippet(string $token): string {
		$attributes = [];

		if($primary_color = $this->get_option('primary_color')) {
			$attributes['zco-primary-color'] = $primary_color;
		}

		if($secondary_color = $this->get_option('secondary_color')) {
			$attributes['zco-secondary-color'] = $secondary_color;
		}

		return $this->api()->getHtmlSnippet($token, apply_filters('zco_html_snippet_attributes', $attributes, $this));
	}

	public function receive_payment_callback(): PaymentStatusResponse {
		return $this->api()->receiveCallback($this->get_option('callback_token'));
	}

	public function receive_refund_callback(): RefundResponse {
		return $this->refund_api()->receiveCallback($this->get_option('callback_token'));
	}

	private function get_purchase_title(WC_Order $order): string {
		$items = $order->get_items();

		// If there's only one order item, return it as title
		if(count($items) === 1) {
			return reset($items)->get_name();
		}

		// If there's multiple order items, return a generic title
		return sprintf(__('Order %s', 'zco'), $order->get_order_number());
	}

	private function get_payment_callback_url(WC_Order $order): string {
		return add_query_arg([
			'wc-api' => 'zaver_payment_callback',
			'key' => $order->get_order_key()
		], home_url());
	}

	private function get_refund_callback_url(WC_Order $order): string {
		return add_query_arg([
			'wc-api' => 'zaver_refund_callback',
			'key' => $order->get_order_key()
		], home_url());
	}

	/**
	 * Return the last amount-matching refund of order.
	 */
	private function get_refund(WC_Order $order, $amount = null): WC_Order_Refund {
		$return = null;

		foreach($order->get_refunds() as $refund) {
			if($refund->get_amount() === $amount) {

				// Don't return directly, as there might be a more recent refund with the same amount
				$return = $refund;
			}
		}

		if(is_null($return)) {
			throw new Exception('No refund found');
		}

		return $return;
	}

	private function prepare_line_item(LineItem $zaver_item, WC_Order_Item_Product $wc_item): void {
		$tax = (float)$wc_item->get_total_tax();
		$total_price = (float)$wc_item->get_total() + $tax;
		$unit_price = $total_price / $wc_item->get_quantity();
		$product = $wc_item->get_product();
		
		$zaver_item
			->setUnitPrice($unit_price)
			->setTotalAmount($total_price)
			->setTaxRatePercent(Helper::get_line_item_tax_rate($wc_item))
			->setTaxAmount($tax)
			->setItemType(Helper::get_zaver_item_type($product))
			->setMerchantReference($product->get_sku());
	}

	private function prepare_shipping(LineItem $zaver_item, WC_Order_Item_Shipping $wc_item): void {
		$tax = (float)$wc_item->get_total_tax();
		$total_price = (float)$wc_item->get_total() + $tax;
		$unit_price = $total_price / $wc_item->get_quantity();
		
		$zaver_item
			->setUnitPrice($unit_price)
			->setTotalAmount($total_price)
			->setTaxRatePercent(Helper::get_line_item_tax_rate($wc_item, true))
			->setTaxAmount($tax)
			->setItemType(ItemType::SHIPPING)
			->setMerchantReference($wc_item->get_method_id());
	}

	private function prepare_fee(LineItem $zaver_item, WC_Order_Item_Fee $wc_item): void {
		$tax = (float)$wc_item->get_total_tax();
		$total_price = (float)$wc_item->get_total() + $tax;
		$unit_price = $total_price / $wc_item->get_quantity();
		
		$zaver_item
			->setUnitPrice($unit_price)
			->setTotalAmount($total_price)
			->setTaxRatePercent(Helper::get_line_item_tax_rate($wc_item))
			->setTaxAmount($tax)
			->setItemType(ItemType::FEE);
	}

	private function prepare_coupon(LineItem $zaver_item, WC_Order_Item_Coupon $wc_item): void {
		$tax = (float)$wc_item->get_discount_tax();
		$total_price = (float)$wc_item->get_discount() + $tax;
		$unit_price = $total_price / $wc_item->get_quantity();
		
		$zaver_item
			->setUnitPrice($unit_price)
			->setTotalAmount($total_price)
			->setTaxRatePercent(Helper::get_line_item_tax_rate($wc_item))
			->setTaxAmount($tax)
			->setItemType(ItemType::DISCOUNT);
	}
}