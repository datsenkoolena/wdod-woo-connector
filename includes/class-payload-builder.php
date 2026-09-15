<?php
/**
 * Turns an Order_Data DTO into the webhook payload.
 *
 * Pure: no WordPress or WooCommerce calls apart from the `wdod_woo_connector_payload` filter.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Payload_Builder.
 */
class Payload_Builder {

	/**
	 * Event name sent with every order payload.
	 */
	const EVENT = 'order.synced';

	/**
	 * Build the payload.
	 *
	 * @param Order_Data $data Order data.
	 * @return array
	 */
	public function build( Order_Data $data ) {
		$payload = array(
			'event'   => self::EVENT,
			'sent_at' => gmdate( 'c' ),
			'order'   => array(
				'id'             => (int) $data->id,
				'number'         => (string) $data->number,
				'status'         => (string) $data->status,
				'currency'       => (string) $data->currency,
				'created'        => (string) $data->created,
				'totals'         => array(
					'subtotal' => round( (float) $data->subtotal, 2 ),
					'discount' => round( (float) $data->discount, 2 ),
					'shipping' => round( (float) $data->shipping, 2 ),
					'tax'      => round( (float) $data->tax, 2 ),
					'total'    => round( (float) $data->total, 2 ),
				),
				'customer'       => $this->build_customer( $data->customer ),
				'billing'        => (array) $data->billing,
				'shipping'       => (array) $data->shipping_address,
				'items'          => $this->build_items( $data->items ),
				'meta'           => array(
					'delivery_date' => '' !== $data->delivery_date ? (string) $data->delivery_date : null,
					'gift_note'     => '' !== $data->gift_note ? (string) $data->gift_note : null,
				),
				'customer_note'  => (string) $data->customer_note,
				'payment_method' => (string) $data->payment_method,
			),
		);

		/**
		 * Filter the payload before it is sent.
		 *
		 * @param array      $payload Payload.
		 * @param Order_Data $data    Source data.
		 */
		return (array) apply_filters( 'wdod_woo_connector_payload', $payload, $data );
	}

	/**
	 * Normalise the customer block.
	 *
	 * @param array $customer Customer values.
	 * @return array
	 */
	private function build_customer( array $customer ) {
		return array(
			'email'      => isset( $customer['email'] ) ? (string) $customer['email'] : '',
			'first_name' => isset( $customer['first_name'] ) ? (string) $customer['first_name'] : '',
			'last_name'  => isset( $customer['last_name'] ) ? (string) $customer['last_name'] : '',
			'phone'      => isset( $customer['phone'] ) ? (string) $customer['phone'] : '',
		);
	}

	/**
	 * Normalise line items.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private function build_items( array $items ) {
		$result = array();

		foreach ( $items as $item ) {
			$item = (array) $item;

			$result[] = array(
				'product_id'   => isset( $item['product_id'] ) ? (int) $item['product_id'] : 0,
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'sku'          => isset( $item['sku'] ) ? (string) $item['sku'] : '',
				'name'         => isset( $item['name'] ) ? (string) $item['name'] : '',
				'qty'          => isset( $item['qty'] ) ? (int) $item['qty'] : 0,
				'subtotal'     => isset( $item['subtotal'] ) ? round( (float) $item['subtotal'], 2 ) : 0.0,
				'total'        => isset( $item['total'] ) ? round( (float) $item['total'], 2 ) : 0.0,
			);
		}

		return $result;
	}
}
