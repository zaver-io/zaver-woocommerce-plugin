<?php
namespace Zaver;
use Exception;
use WC_Order_Item;
use WC_Product;
use WC_Tax;
use WP_Error;
use Zaver\SDK\Config\ItemType;

class Helper {
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

	static public function is_https(): bool {
		$url = strtolower(home_url());

		return (strncmp($url, 'https:', 6) === 0);
	}
}