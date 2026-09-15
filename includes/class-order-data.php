<?php
/**
 * Plain data transfer object describing an order, decoupled from WC_Order.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Data.
 */
class Order_Data {

	/**
	 * Order ID.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Order number (may differ from ID with sequential-number plugins).
	 *
	 * @var string
	 */
	public $number = '';

	/**
	 * Status without the "wc-" prefix.
	 *
	 * @var string
	 */
	public $status = '';

	/**
	 * ISO 4217 currency code.
	 *
	 * @var string
	 */
	public $currency = '';

	/**
	 * Grand total.
	 *
	 * @var float
	 */
	public $total = 0.0;

	/**
	 * Items subtotal (before discounts).
	 *
	 * @var float
	 */
	public $subtotal = 0.0;

	/**
	 * Total tax.
	 *
	 * @var float
	 */
	public $tax = 0.0;

	/**
	 * Shipping total.
	 *
	 * @var float
	 */
	public $shipping = 0.0;

	/**
	 * Discount total.
	 *
	 * @var float
	 */
	public $discount = 0.0;

	/**
	 * Creation date, ISO 8601.
	 *
	 * @var string
	 */
	public $created = '';

	/**
	 * Customer: email, first_name, last_name, phone.
	 *
	 * @var array
	 */
	public $customer = array(
		'email'      => '',
		'first_name' => '',
		'last_name'  => '',
		'phone'      => '',
	);

	/**
	 * Billing address (WC_Order::get_address( 'billing' ) shape).
	 *
	 * @var array
	 */
	public $billing = array();

	/**
	 * Shipping address (WC_Order::get_address( 'shipping' ) shape).
	 *
	 * @var array
	 */
	public $shipping_address = array();

	/**
	 * Line items: product_id, variation_id, sku, name, qty, subtotal, total.
	 *
	 * @var array[]
	 */
	public $items = array();

	/**
	 * Preferred delivery date (Y-m-d) or empty string.
	 *
	 * @var string
	 */
	public $delivery_date = '';

	/**
	 * Gift note or empty string.
	 *
	 * @var string
	 */
	public $gift_note = '';

	/**
	 * Customer-provided order note.
	 *
	 * @var string
	 */
	public $customer_note = '';

	/**
	 * Payment gateway ID.
	 *
	 * @var string
	 */
	public $payment_method = '';

	/**
	 * Constructor.
	 *
	 * @param array $values Optional property values to hydrate.
	 */
	public function __construct( array $values = array() ) {
		foreach ( $values as $property => $value ) {
			if ( property_exists( $this, $property ) ) {
				$this->{$property} = $value;
			}
		}
	}

	/**
	 * Build the DTO from a WooCommerce order.
	 *
	 * @param \WC_Order $order Order.
	 * @return self
	 */
	public static function from_order( \WC_Order $order ) {
		$data = new self();

		$data->id       = (int) $order->get_id();
		$data->number   = (string) $order->get_order_number();
		$data->status   = (string) $order->get_status();
		$data->currency = (string) $order->get_currency();
		$data->total    = (float) $order->get_total();
		$data->subtotal = (float) $order->get_subtotal();
		$data->tax      = (float) $order->get_total_tax();
		$data->shipping = (float) $order->get_shipping_total();
		$data->discount = (float) $order->get_total_discount();

		$created       = $order->get_date_created();
		$data->created = $created ? $created->format( 'c' ) : '';

		$data->customer = array(
			'email'      => (string) $order->get_billing_email(),
			'first_name' => (string) $order->get_billing_first_name(),
			'last_name'  => (string) $order->get_billing_last_name(),
			'phone'      => (string) $order->get_billing_phone(),
		);

		$data->billing          = (array) $order->get_address( 'billing' );
		$data->shipping_address = (array) $order->get_address( 'shipping' );

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			$data->items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'sku'          => $product ? (string) $product->get_sku() : '',
				'name'         => (string) $item->get_name(),
				'qty'          => (int) $item->get_quantity(),
				'subtotal'     => (float) $item->get_subtotal(),
				'total'        => (float) $item->get_total(),
			);
		}

		$data->delivery_date  = (string) $order->get_meta( Checkout_Field::META_DELIVERY_DATE );
		$data->gift_note      = (string) $order->get_meta( Checkout_Field::META_GIFT_NOTE );
		$data->customer_note  = (string) $order->get_customer_note();
		$data->payment_method = (string) $order->get_payment_method();

		return $data;
	}
}
