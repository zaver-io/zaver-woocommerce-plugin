<?php
namespace Zaver;
use Zaver\SDK\Checkout;
use Zaver\SDK\Object\PaymentCreationRequest;
use WC_Payment_Gateway;

class Checkout_Gateway extends WC_Payment_Gateway {
	private $api_instance = null;

	public function __construct() {
		$this->id = 'zaver_checkout';
		$this->has_fields = false;
		$this->method_title = __('Zaver Checkout', 'zco');

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->order_button_text = apply_filters('zco_order_button_text', __('Pay with Zaver', 'zco'));

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
		];
	}

	public function process_payment($order_id): array {
		$order = wc_get_order($order_id);
		$payment = PaymentCreationRequest::create()
			->setMerchantPaymentReference($order->get_order_number())
			->setAmount($order->get_total())
			->setCurrency($order->get_currency())
			->setTitle('Foobar')
			->setDescription('Test test');

		$response = $this->api()->createPayment($payment);
		$order->update_meta_data('_zaver_payment', [
			'id' => $response->getPaymentId(),
			'token' => $response->getToken(),
			'tokenValidUntil' => $response->getValidUntil()
		]);
		$order->save_meta_data();

		return [
			'result' => 'success',
			'redirect' => $order->get_checkout_payment_url(true)
		];
	}

	public function api(): Checkout {
		if(is_null($this->api_instance)) {
			$this->api_instance = new Checkout($this->get_option('api_key', ''), $this->get_option('test_mode') === 'yes');
		}

		return $this->api_instance;
	}
}