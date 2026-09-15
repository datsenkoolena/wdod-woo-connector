<?php
/**
 * Tests for Payload_Builder.
 *
 * @package WDOD\WooConnector\Tests
 */

namespace WDOD\WooConnector\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use WDOD\WooConnector\Order_Data;
use WDOD\WooConnector\Payload_Builder;
use PHPUnit\Framework\TestCase;

/**
 * Class PayloadBuilderTest.
 */
class PayloadBuilderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Set up Brain Monkey.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Tear down Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fixture order.
	 *
	 * @return Order_Data
	 */
	private function fixture(): Order_Data {
		return new Order_Data(
			[
				'id'               => 1234,
				'number'           => '1234',
				'status'           => 'processing',
				'currency'         => 'EUR',
				'total'            => 109.5,
				'subtotal'         => 100.0,
				'tax'              => 19.5,
				'shipping'         => 5.0,
				'discount'         => 15.0,
				'created'          => '2026-09-14T10:15:00+00:00',
				'customer'         => [
					'email'      => 'jane@example.com',
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
					'phone'      => '+49 30 1234567',
				],
				'billing'          => [ 'city' => 'Berlin', 'country' => 'DE' ],
				'shipping_address' => [ 'city' => 'Hamburg', 'country' => 'DE' ],
				'items'            => [
					[
						'product_id' => 10,
						'sku'        => 'MUG-01',
						'name'       => 'Mug',
						'qty'        => 2,
						'subtotal'   => 40.0,
						'total'      => 34.0,
					],
					[
						'product_id'   => 20,
						'variation_id' => 21,
						'sku'          => 'TEE-M',
						'name'         => 'T-Shirt - M',
						'qty'          => 1,
						'subtotal'     => 60.0,
						'total'        => 51.0,
					],
				],
				'delivery_date'    => '2026-09-20',
				'gift_note'        => 'Happy birthday!',
				'customer_note'    => 'Ring twice.',
				'payment_method'   => 'cod',
			]
		);
	}

	/**
	 * Top-level shape.
	 *
	 * @return void
	 */
	public function test_payload_has_event_timestamp_and_order(): void {
		$payload = ( new Payload_Builder() )->build( $this->fixture() );

		$this->assertSame( 'order.synced', $payload['event'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $payload['sent_at'] );
		$this->assertSame(
			[ 'event', 'sent_at', 'order' ],
			array_keys( $payload )
		);
	}

	/**
	 * Identity and totals.
	 *
	 * @return void
	 */
	public function test_order_identity_and_totals(): void {
		$order = ( new Payload_Builder() )->build( $this->fixture() )['order'];

		$this->assertSame( 1234, $order['id'] );
		$this->assertSame( '1234', $order['number'] );
		$this->assertSame( 'processing', $order['status'] );
		$this->assertSame( 'EUR', $order['currency'] );
		$this->assertSame( '2026-09-14T10:15:00+00:00', $order['created'] );
		$this->assertSame(
			[
				'subtotal' => 100.0,
				'discount' => 15.0,
				'shipping' => 5.0,
				'tax'      => 19.5,
				'total'    => 109.5,
			],
			$order['totals']
		);
		$this->assertSame( 'cod', $order['payment_method'] );
		$this->assertSame( 'Ring twice.', $order['customer_note'] );
	}

	/**
	 * Customer, addresses and line items.
	 *
	 * @return void
	 */
	public function test_customer_addresses_and_items(): void {
		$order = ( new Payload_Builder() )->build( $this->fixture() )['order'];

		$this->assertSame( 'jane@example.com', $order['customer']['email'] );
		$this->assertSame( 'Jane', $order['customer']['first_name'] );
		$this->assertSame( [ 'city' => 'Berlin', 'country' => 'DE' ], $order['billing'] );
		$this->assertSame( [ 'city' => 'Hamburg', 'country' => 'DE' ], $order['shipping'] );

		$this->assertCount( 2, $order['items'] );
		$this->assertSame(
			[
				'product_id'   => 10,
				'variation_id' => 0,
				'sku'          => 'MUG-01',
				'name'         => 'Mug',
				'qty'          => 2,
				'subtotal'     => 40.0,
				'total'        => 34.0,
			],
			$order['items'][0]
		);
		$this->assertSame( 21, $order['items'][1]['variation_id'] );
	}

	/**
	 * Checkout meta is passed through, empty values become null.
	 *
	 * @return void
	 */
	public function test_meta_fields(): void {
		$builder = new Payload_Builder();

		$order = $builder->build( $this->fixture() )['order'];
		$this->assertSame( '2026-09-20', $order['meta']['delivery_date'] );
		$this->assertSame( 'Happy birthday!', $order['meta']['gift_note'] );

		$empty = $builder->build( new Order_Data( [ 'id' => 1 ] ) )['order'];
		$this->assertNull( $empty['meta']['delivery_date'] );
		$this->assertNull( $empty['meta']['gift_note'] );
		$this->assertSame( [], $empty['items'] );
	}

	/**
	 * The wdod_woo_connector_payload filter can change the payload.
	 *
	 * @return void
	 */
	public function test_payload_filter_is_applied(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, array $payload, Order_Data $data ): array {
				$payload['order']['external_ref'] = 'ref-' . $data->id;
				return $payload;
			}
		);

		$payload = ( new Payload_Builder() )->build( $this->fixture() );

		$this->assertSame( 'ref-1234', $payload['order']['external_ref'] );
	}
}
