<?php
/**
 * The Cart class.
 *
 * @package ZCO/Classes/Helpers
 */

namespace Zaver\Classes\Helpers;

use Zaver\SDK\Config\ItemType;
use Zaver\SDK\Object\PaymentCreationRequest;
use Zaver\SDK\Object\MerchantUrls;
use Zaver\SDK\Object\LineItem;
use WC_Order;
use Zaver\Plugin;
use Zaver\Helper;
use KrokedilZCODeps\Krokedil\WooCommerce\Cart\Cart as WCCart;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cart
 *
 * Processes the WC cart for payment handling.
 */
class Cart {

	/**
	 * Create Zaver payment session.
	 *
	 * @return PaymentCreationRequest
	 */
	public static function create() {
		$config = array(
			'slug'         => 'zaver_checkout',
			'price_format' => 'major',
		);

		$cart = new WCCart( WC()->cart, $config );

		$payment = PaymentCreationRequest::create()
		// ->setMerchantPaymentReference( $order->get_order_number() )
		->setAmount( $cart->get_total() )
		->setCurrency( get_woocommerce_currency() )
		->setMarket( $cart->customer->get_billing_country() )
		->setMerchantMetadata(
			array(
				'originPlatform' => 'woocommerce',
				'originWebsite'  => home_url(),
				// 'originPage'     => $order->get_created_via(),
				// 'customerId'     => (string) $order->get_customer_id(),
				// 'orderId'        => (string) $order->get_id(),
			)
		)
		// ->setTitle( self::get_purchase_title( $order ) );
		->setTitle( 'TODO: MISSING IMPLEMENTATION' );

		$merchant_urls = MerchantUrls::create()
		->setSuccessUrl( Plugin::gateway()->get_return_url() );

		$callback_url = self::get_callback_url();
		if ( ! empty( $callback_url ) ) {
			$merchant_urls->setCallbackUrl( $callback_url );
		}

		$payment->setMerchantUrls( $merchant_urls );

		foreach ( $cart->get_line_items() as $item ) {
			$line_item = LineItem::create()
			->setName( $item->get_name() )
			->setQuantity( $item->get_quantity() );

			$is_digital = $item->product->is_downloadable() || $item->product->is_virtual();
			if ( $is_digital ) {
				$line_item->setItemType( ItemType::DIGITAL );
			} else {
				$line_item->setItemType( ItemType::PHYSICAL );
			}

			$type = $item->get_type();
			switch ( $type ) {
				case 'fee':
					$line_item->setItemType( ItemType::FEE );
					break;
				case 'coupon':
					$line_item->setItemType( ItemType::DISCOUNT );
					break;
				default:
					$type = 'line_item';
					$line_item->setMerchantReference( $item->get_sku() );
					break;
			}

			$line_item
			->setUnitPrice( $item->get_unit_price() + $item->get_unit_tax_amount() )
			->setTotalAmount( $item->get_total_amount() + $item->get_total_tax_amount() )
			->setTaxRatePercent( self::normalize( $item->get_tax_rate() ) )
			->setTaxAmount( $item->get_total_tax_amount() );

			do_action( "zco_process_payment_{$type}", $line_item, $item );
			$payment->addLineItem( $line_item );
		}

		foreach ( $cart->get_line_shipping() as $item ) {
			$line_item = LineItem::create()
			->setName( $item->get_name() )
			->setQuantity( $item->get_quantity() )
			->setMerchantReference( $item->get_sku() )
			->setItemType( ItemType::SHIPPING )
			->setUnitPrice( $item->get_unit_price() + $item->get_unit_tax_amount() )
			->setTotalAmount( $item->get_total_amount() + $item->get_total_tax_amount() )
			->setTaxRatePercent( self::normalize( $item->get_tax_rate() ) )
			->setTaxAmount( $item->get_total_tax_amount() );

			do_action( 'zco_process_payment_shipping', $line_item, $item );
			$payment->addLineItem( $line_item );
		}

		return $payment;
	}

	/**
	 * Get the title for the purchase.
	 *
	 * @param WC_Order $order The order to get the title for.
	 * @return string
	 */
	private static function get_purchase_title( $order ) {
		$items = $order->get_items();

		// If there's only one order item, return it as title.
		// If there's multiple order items, return a generic title.
		// translators: %s is the order number.
		$title = count( $items ) === 1 ? reset( $items )->get_name() : sprintf( __( 'Order %s', 'zco' ), $order->get_order_number() );

		return apply_filters( 'zco_payment_purchase_title', $title, $order );
	}

	/**
	 * Normalize a minor into a major number.
	 *
	 * Workaround until the Krokedil WC SDK supports major numbers properly.
	 *
	 * @param numeric $number The number to normalize.
	 * @return numeric
	 */
	private static function normalize( $number ) {
		return 0 === $number % 100 ? $number / 100 : $number;
	}

	/**
	 * Get the callback URL for the payment.
	 *
	 * @return string|null
	 */
	private static function get_callback_url() {
		if ( ! Helper::is_https() ) {
			return null;
		}

		$url = add_query_arg(
			array(
				'session_id' => '{session_id}',
				'order_id'   => '{order_id}',
			),
			wc_get_checkout_url()
		);

		return apply_filters( 'ledyer_payments_confirmation_url', $url );

		// return add_query_arg(
		// array(
		// 'wc-api' => 'zaver_payment_callback',
		// 'key'    => $order->get_order_key(),
		// ),
		// home_url()
		// );
		// }
	}
}
