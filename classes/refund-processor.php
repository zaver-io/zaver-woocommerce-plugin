<?php
/**
 * Refund processor.
 *
 * @package ZCO/Classes
 */

namespace Zaver;

use KrokedilZCODeps\Zaver\SDK\Config\RefundStatus;
use KrokedilZCODeps\Zaver\SDK\Object\MerchantRepresentative;
use KrokedilZCODeps\Zaver\SDK\Object\RefundCreationRequest;
use KrokedilZCODeps\Zaver\SDK\Object\RefundUpdateRequest;
use KrokedilZCODeps\Zaver\SDK\Object\RefundLineItem;
use KrokedilZCODeps\Zaver\SDK\Object\RefundResponse;
use KrokedilZCODeps\Zaver\SDK\Object\MerchantUrls;
use WC_Order;
use Exception;
use WC_Order_Item;
use WC_Order_Item_Coupon;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Refund_Processor
 *
 * Handles the processing of refunds within the Zaver WooCommerce plugin.
 */
class Refund_Processor {

	/**
	 * Process a refund.
	 *
	 * @throws Exception When the refund ID is missing.
	 *
	 * @param WC_Order $order  Order object.
	 * @param float    $amount Refund amount.
	 *
	 * @throws Exception When the refund ID is missing.
	 */
	public static function process( $order, $amount ) {
		$amount = abs( Helper::format_number( $amount ) );

		$payment_id = $order->get_meta( '_zaver_payment' )['id'] ?? $order->get_meta( '_zaver_payment_id' );
		if ( empty( $payment_id ) ) {
			throw new Exception( 'Missing Zaver payment ID for order' );
		}

		$refund = self::find_refund( $order, $amount );

		$request = RefundCreationRequest::create()
			->setPaymentId( $payment_id )
			->setInvoiceReference( $order->get_order_number() )
			->setRefundAmount( abs( Helper::format_number( $refund->get_amount() ) ) )
			->setMerchantMetadata(
				array(
					'originPlatform' => 'woocommerce',
					'originWebsite'  => home_url(),
					'originPage'     => $order->get_created_via(),
					'customerId'     => (string) $order->get_customer_id(),
					'orderId'        => (string) $order->get_id(),
				)
			);

		$types = array( 'line_item', 'shipping', 'fee', 'coupon' );

		// Refunded line items.
		$items = $refund->get_items( $types );
		if ( ! empty( $items ) ) {
			foreach ( $items as $item ) {
				$zaver_line_item_id = $item->get_meta( '_zaver_line_item_id' );

				if ( ! $zaver_line_item_id ) {
					continue;
				}

				$line_item = RefundLineItem::create()
					->setLineItemId( $zaver_line_item_id );

				self::prepare_item( $line_item, $item );

				$request->addLineItem( $line_item );
			}
		} else {
			// Refunded fixed amount.
			$request->setRefundTaxAmount( abs( Helper::format_number( $refund->get_total_tax() ) ) );
		}

		$reason = $refund->get_reason();
		if ( ! empty( $reason ) ) {
			$request->setDescription( (string) $reason );
		}

		$callback_url = self::get_callback_url( $order );
		if ( $callback_url ) {
			$merchant_urls = MerchantUrls::create()->setCallbackUrl( $callback_url );
			$request->setMerchantUrls( $merchant_urls );
		}

		do_action( 'zco_before_process_refund', $request, $refund, $order );
		try {
			$response = Plugin::gateway()->refund_api()->createRefund( $request );
			$refund->update_meta_data( '_zaver_refund_id', $response->getRefundId() );

			$representative = self::get_current_representative();
			if ( $representative ) {
				Plugin::gateway()->refund_api()->approveRefund( $response->getRefundId(), RefundUpdateRequest::create()->setActingRepresentative( $representative ) );
			}

			do_action( 'zco_after_process_refund', $request, $refund, $order );
		} catch ( Exception $e ) {
			// translators: 1: Refund reason.
			$order->add_order_note( sprintf( __( 'Failed to request a refund with reason: %s', 'zco' ), $e->getMessage() ) );

		} finally {
			if ( isset( $response ) ) {
				// If the response is not set, an exception, and we can therefore reference the $e object.
				ZCO()->logger()->info(
					sprintf( 'Requested a refund of %F %s. Reason: %s', $response->getRefundAmount(), $response->getCurrency(), $e->getMessage() ),
					array(
						'payload'  => $request,
						'response' => $response,
						'orderId'  => $order->get_id(),
						'refundId' => $response->getRefundId(),
						'amount'   => $amount,
						'reason'   => $refund->get_reason(),
					)
				);

				// translators: 1: Refund amount, 2: Refund currency, 3: Refund ID.
				$order->add_order_note( sprintf( __( 'Requested a refund of %1$F %2$s - refund ID: %3$s', 'zco' ), Helper::format_number( $response->getRefundAmount() ), $response->getCurrency(), $response->getRefundId() ) );

			} else {
				ZCO()->logger()->info(
					sprintf( 'Failed to request a refund of %F %s.', $amount, $order->get_currency() ),
					array(
						'payload' => $request,
						'orderId' => $order->get_id(),
						'amount'  => $amount,
						'reason'  => $refund->get_reason(),
					)
				);

			}

			$refund->save();
		}
	}

	/**
	 * Processes a refund for a given order.
	 *
	 * Webbmaffian: Return the last amount-matching refund of order. This is unfortunately the only way
	 * to get a `WC_Order_Refund` object from the current refund.
	 *
	 * @throws Exception When no refund is found.
	 *
	 * @param WC_Order $order The order object to refund.
	 * @param float    $amount The amount to refund.
	 * @return WC_Order_Refund The refund object.
	 */
	private static function find_refund( $order, $amount = null ) {
		$result = null;

		foreach ( $order->get_refunds() as $refund ) {
			$refund_amount = (float) $refund->get_amount();

			if ( $refund_amount === $amount ) {

				// Don't return directly, as there might be a more recent refund with the same amount.
				$result = $refund;
			}
		}

		if ( null === $result ) {
			throw new Exception( 'No refund found' );
		}

		return apply_filters( 'zco_find_refund', $result, $order, $amount );
	}


	/**
	 * Retrieves the callback URL for a given WooCommerce order.
	 *
	 * @param WC_Order $order The WooCommerce order object.
	 * @return string|null The callback URL or null if not available.
	 */
	private static function get_callback_url( $order ) {
		if ( ! Helper::is_https() ) {
			return null;
		}

		return add_query_arg( 'key', $order->get_order_key(), home_url( '/wc-api/zaver_refund_callback/' ) );
	}

	/**
	 * Retrieves the current merchant representative.
	 *
	 * @return MerchantRepresentative|null The current merchant representative or null if not available.
	 */
	private static function get_current_representative() {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user = wp_get_current_user();

		return MerchantRepresentative::create()->setUsername( $user->user_email );
	}

	/**
	 * Prepares a refund line item for processing.
	 *
	 * @param RefundLineItem                                                                                    $zaver_item The refund line item to be prepared.
	 * @param WC_Order_Item|WC_Order_Item_Fee|WC_Order_Item_Coupon|WC_Order_Item_Product|WC_Order_Item_Shipping $wc_item The WooCommerce order item associated with the refund.
	 *
	 * @return void
	 */
	private static function prepare_item( $zaver_item, $wc_item ) {
		$tax         = abs( Helper::format_number( $wc_item->get_total_tax() ) );
		$total_price = abs( Helper::format_number( $wc_item->get_total() + $tax ) );
		$unit_price  = Helper::format_number( $total_price / $wc_item->get_quantity() );

		$zaver_item
			->setRefundTotalAmount( $total_price )
			->setRefundTaxAmount( $tax )
			->setRefundTaxRatePercent( Helper::get_line_item_tax_rate( $wc_item ) )
			->setRefundQuantity( $wc_item->get_quantity() )
			->setRefundUnitPrice( $unit_price );

		do_action( 'zco_process_refund_item', $zaver_item, $wc_item );
	}

	/**
	 * Handles the response for a refund request.
	 *
	 * @throws Exception When the order key is invalid.
	 *
	 * @param WC_Order            $order The WooCommerce order object.
	 * @param RefundResponse|null $refund The refund response object, or null if no refund response is provided.
	 *
	 * @return void
	 */
	public static function handle_response( $order, $refund = null ) {

		// Ensure that the order key is correct.
		$key = filter_input( INPUT_GET, 'key', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( empty( $key ) || ! hash_equals( $order->get_order_key(), $key ) ) {
			throw new Exception( 'Invalid order key' );
		}

		$refund_ids = $order->get_meta( '_zaver_refund_ids' );

		if ( empty( $refund_ids ) || ! is_array( $refund_ids ) ) {
			throw new Exception( 'Missing refund ID on order' );
		}

		if ( ! in_array( $refund->getPaymentId(), $refund_ids, true ) ) {
			throw new Exception( 'Mismatching refund ID' );
		}

		do_action( 'zco_process_refund_handle_response', $order, $refund );

		switch ( $refund->getStatus() ) {
			case RefundStatus::PENDING_MERCHANT_APPROVAL:
				$username = $refund->getInitializingRepresentative()->getUsername();
				if ( $username ) {
					// translators: 1: Refund amount, 2: Refund currency, 3: Username, 4: Refund description, 5: Refund ID.
					$order->add_order_note( sprintf( __( 'Refund of %1$F %2$s initialized by %3$s with the description "%4$s". Refund ID: %5$s', 'zco' ), $refund->getRefundAmount(), $refund->getCurrency(), $username, $refund->getDescription(), $refund->getRefundId() ) );
				} else {
					// translators: 1: Refund amount, 2: Refund currency, 3: Refund description, 4: Refund ID.
					$order->add_order_note( sprintf( __( 'Refund of %1$F %2$s initialized with the description "%3$s". Refund ID: %4$s', 'zco' ), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getDescription(), $refund->getRefundId() ) );
				}

				ZCO()->logger()->info(
					sprintf(
						'Refund of %F %s approved',
						$refund->getRefundAmount(),
						$refund->getCurrency()
					),
					array(
						'orderId'  => $order->get_id(),
						'refundId' => $refund->getRefundId(),
					)
				);
				break;

			case RefundStatus::PENDING_EXECUTION:
				$username = $refund->getApprovingRepresentative()->getUsername();
				if ( $username ) {
					// translators: 1: Refund amount, 2: Refund currency, 3: Username, 4: Refund ID.
					$order->add_order_note( sprintf( __( 'Refund of %1$F %2$s approved by %3$s - Refund ID: %4$s', 'zco' ), $refund->getRefundAmount(), $refund->getCurrency(), $username, $refund->getRefundId() ) );
				} else {
					// translators: 1: Refund amount, 2: Refund currency, 3: Refund ID.
					$order->add_order_note( sprintf( __( 'Refund of %1$F %2$s approved - Refund ID: %3$s', 'zco' ), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getRefundId() ) );
				}

				ZCO()->logger()->info(
					sprintf(
						'Refund of %F %s approved',
						$refund->getRefundAmount(),
						$refund->getCurrency()
					),
					array(
						'orderId'  => $order->get_id(),
						'refundId' => $refund->getRefundId(),
					)
				);
				break;

			case RefundStatus::EXECUTED:
				// translators: 1: Refund amount, 2: Refund currency, 3: Refund ID.
				$order->add_order_note( sprintf( __( 'Refund of %1$F %2$s completed - Refund ID: %3$s', 'zco' ), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getRefundId() ) );
				ZCO()->logger()->info(
					sprintf(
						'Refund of %F %s completed',
						$refund->getRefundAmount(),
						$refund->getCurrency()
					),
					array(
						'orderId'  => $order->get_id(),
						'refundId' => $refund->getRefundId(),
					)
				);
				break;

			case RefundStatus::CANCELLED:
				$username = $refund->getApprovingRepresentative()->getUsername();
				if ( $username ) {
					// translators: 1: Refund amount, 2: Refund currency, 3: Username, 4: Refund ID.
					$order->add_order_note( sprintf( __( 'Refund of %1$F %2$s cancelled by %3$s - Refund ID: %4$s', 'zco' ), $refund->getRefundAmount(), $refund->getCurrency(), $username, $refund->getRefundId() ) );
				} else {
					// translators: 1: Refund amount, 2: Refund currency, 3: Refund ID.
					$order->add_order_note( sprintf( __( 'Refund of %1$F %2$s cancelled - Refund ID: %3$s', 'zco' ), $refund->getRefundAmount(), $refund->getCurrency(), $refund->getRefundId() ) );
				}

				ZCO()->logger()->info(
					sprintf(
						'Refund of %F %s cancelled',
						$refund->getRefundAmount(),
						$refund->getCurrency()
					),
					array(
						'orderId'  => $order->get_id(),
						'refundId' => $refund->getRefundId(),
					)
				);
				break;
		}
	}
}
