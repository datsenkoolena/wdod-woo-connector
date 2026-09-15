<?php
/**
 * Listens to order status transitions and queues syncs.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Hooks.
 */
class Order_Hooks {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Queue    $queue    Queue.
	 */
	public function __construct( Settings $settings, Queue $queue ) {
		$this->settings = $settings;
		$this->queue    = $queue;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_status_hooks' ) );
	}

	/**
	 * Hook woocommerce_order_status_{status} for each configured status.
	 *
	 * @return void
	 */
	public function register_status_hooks() {
		/**
		 * Filter the order statuses (without "wc-" prefix) that trigger a sync.
		 *
		 * @param string[] $statuses Statuses.
		 */
		$statuses = apply_filters( 'wdod_woo_connector_sync_statuses', $this->settings->get_statuses() );

		foreach ( (array) $statuses as $status ) {
			$status = sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );

			if ( '' === $status ) {
				continue;
			}

			add_action( 'woocommerce_order_status_' . $status, array( $this, 'maybe_enqueue' ), 10, 2 );
		}
	}

	/**
	 * Queue a sync unless disabled or already synced.
	 *
	 * @param int            $order_id Order ID.
	 * @param \WC_Order|null $order    Order (passed by WooCommerce).
	 * @param bool           $force    Sync even if already synced.
	 * @return bool Whether a job was queued.
	 */
	public function maybe_enqueue( $order_id, $order = null, $force = false ) {
		if ( ! $this->settings->is_enabled() ) {
			return false;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		if ( ! $force && Sync_Service::STATUS_SYNCED === $order->get_meta( Sync_Service::META_STATUS ) ) {
			return false;
		}

		$queued = $this->queue->enqueue( (int) $order_id, 0, 0, (bool) $force );

		if ( $queued && '' === (string) $order->get_meta( Sync_Service::META_STATUS ) ) {
			$order->update_meta_data( Sync_Service::META_STATUS, Sync_Service::STATUS_PENDING );
			$order->save();
		}

		return $queued;
	}
}
