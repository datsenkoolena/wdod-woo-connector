<?php
/**
 * Orchestrates a single sync attempt: load order, build payload, send, persist outcome, schedule retry.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

use WDOD\WooConnector\Interfaces\Api_Client_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Class Sync_Service.
 */
class Sync_Service {

	/**
	 * Order meta keys.
	 */
	const META_STATUS     = '_wdod_sync_status';
	const META_ATTEMPTS   = '_wdod_sync_attempts';
	const META_LAST_ERROR = '_wdod_sync_last_error';
	const META_SYNCED_AT  = '_wdod_synced_at';

	/**
	 * Sync statuses.
	 */
	const STATUS_PENDING = 'pending';
	const STATUS_SYNCED  = 'synced';
	const STATUS_FAILED  = 'failed';

	/**
	 * Base retry delay in seconds (doubles on every attempt).
	 */
	const BASE_RETRY_DELAY = 300;

	/**
	 * API client.
	 *
	 * @var Api_Client_Interface
	 */
	private $client;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Payload builder.
	 *
	 * @var Payload_Builder
	 */
	private $builder;

	/**
	 * Constructor.
	 *
	 * @param Api_Client_Interface $client   API client.
	 * @param Logger               $logger   Logger.
	 * @param Queue                $queue    Queue.
	 * @param Settings             $settings Settings.
	 * @param Payload_Builder|null $builder  Payload builder (optional).
	 */
	public function __construct( Api_Client_Interface $client, Logger $logger, Queue $queue, Settings $settings, Payload_Builder $builder = null ) {
		$this->client   = $client;
		$this->logger   = $logger;
		$this->queue    = $queue;
		$this->settings = $settings;
		$this->builder  = $builder ? $builder : new Payload_Builder();
	}

	/**
	 * Run one sync attempt for an order.
	 *
	 * @param int  $order_id Order ID.
	 * @param int  $attempt  Attempts already made before this run (0-based).
	 * @param bool $force    Sync even if the order is already marked as synced.
	 * @return void
	 */
	public function handle( $order_id, $attempt = 0, $force = false ) {
		$order_id = (int) $order_id;
		$attempt  = max( 0, (int) $attempt );
		$force    = (bool) $force;

		$order = $this->load_order( $order_id );

		if ( ! $order ) {
			$this->logger->error( sprintf( 'Order #%d not found; sync skipped.', $order_id ) );
			return;
		}

		if ( ! $this->settings->is_enabled() ) {
			$this->logger->debug( sprintf( 'Sync disabled in settings; order #%d skipped.', $order_id ) );
			return;
		}

		if ( ! $force && self::STATUS_SYNCED === $order->get_meta( self::META_STATUS ) ) {
			$this->logger->debug( sprintf( 'Order #%d already synced; skipped.', $order_id ) );
			return;
		}

		if ( '' === $this->settings->get_endpoint() ) {
			$order->update_meta_data( self::META_STATUS, self::STATUS_FAILED );
			$order->update_meta_data( self::META_LAST_ERROR, __( 'Endpoint URL is not configured.', 'wdod-woo-connector' ) );
			$order->save();

			$this->logger->error( sprintf( 'Order #%d not sent: endpoint URL is not configured.', $order_id ) );
			return;
		}

		$payload  = $this->builder->build( Order_Data::from_order( $order ) );
		$result   = $this->client->send( $payload );
		$decision = $this->decide( $result, $attempt, $this->settings->get_max_attempts() );

		$order->update_meta_data( self::META_STATUS, $decision['status'] );
		$order->update_meta_data( self::META_ATTEMPTS, $decision['attempts'] );

		if ( $result->is_ok() ) {
			$order->update_meta_data( self::META_SYNCED_AT, gmdate( 'c' ) );
			$order->delete_meta_data( self::META_LAST_ERROR );
			$order->add_order_note( __( 'Order synced to WDOD.', 'wdod-woo-connector' ) );
			$order->save();

			$this->logger->info(
				sprintf( 'Order #%d synced (attempt %d, %s).', $order_id, $decision['attempts'], $result->get_message() )
			);
			return;
		}

		$order->update_meta_data( self::META_LAST_ERROR, $result->get_message() );

		if ( $decision['retry'] ) {
			/**
			 * Filter the delay (seconds) before the next retry.
			 *
			 * @param int $delay    Delay in seconds (300 * 2^attempt by default).
			 * @param int $attempt  Attempts already made before this run.
			 * @param int $order_id Order ID.
			 */
			$delay = (int) apply_filters( 'wdod_woo_connector_retry_delay', $decision['delay'], $attempt, $order_id );

			$order->save();
			$this->queue->enqueue( $order_id, $decision['attempts'], $delay, $force );

			$this->logger->error(
				sprintf( 'Order #%d sync failed (attempt %d/%d): %s. Retrying in %d seconds.', $order_id, $decision['attempts'], $this->settings->get_max_attempts(), $result->get_message(), $delay )
			);
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: number of attempts, 2: error message */
				__( 'WDOD sync failed after %1$d attempt(s): %2$s', 'wdod-woo-connector' ),
				$decision['attempts'],
				$result->get_message()
			)
		);
		$order->save();

		$this->logger->error(
			sprintf( 'Order #%d sync failed permanently (attempt %d/%d): %s', $order_id, $decision['attempts'], $this->settings->get_max_attempts(), $result->get_message() )
		);
	}

	/**
	 * Pure decision logic shared by handle() and the unit tests.
	 *
	 * @param Result $result       Outcome of the request.
	 * @param int    $attempt      Attempts already made before this run (0-based).
	 * @param int    $max_attempts Maximum attempts allowed.
	 * @return array {
	 *     @type string $status   'synced', 'pending' (retry scheduled) or 'failed'.
	 *     @type bool   $retry    Whether a retry should be scheduled.
	 *     @type int    $delay    Base retry delay in seconds (before the `wdod_woo_connector_retry_delay` filter).
	 *     @type int    $attempts Total attempts after this run.
	 * }
	 */
	public function decide( Result $result, $attempt, $max_attempts ) {
		$attempt      = max( 0, (int) $attempt );
		$max_attempts = max( 1, (int) $max_attempts );
		$attempts     = $attempt + 1;

		if ( $result->is_ok() ) {
			return array(
				'status'   => self::STATUS_SYNCED,
				'retry'    => false,
				'delay'    => 0,
				'attempts' => $attempts,
			);
		}

		if ( $result->is_retryable() && $attempts < $max_attempts ) {
			return array(
				'status'   => self::STATUS_PENDING,
				'retry'    => true,
				'delay'    => self::BASE_RETRY_DELAY * ( 2 ** $attempt ),
				'attempts' => $attempts,
			);
		}

		return array(
			'status'   => self::STATUS_FAILED,
			'retry'    => false,
			'delay'    => 0,
			'attempts' => $attempts,
		);
	}

	/**
	 * Load an order. Protected so tests can substitute a fixture.
	 *
	 * @param int $order_id Order ID.
	 * @return \WC_Order|null
	 */
	protected function load_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}
}
