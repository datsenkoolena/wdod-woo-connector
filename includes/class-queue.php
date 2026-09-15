<?php
/**
 * Background queue: Action Scheduler when available, WP-Cron as fallback.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Queue.
 */
class Queue {

	/**
	 * Action hook fired by the scheduler. Receives ( $order_id, $attempt, $force ).
	 */
	const HOOK = 'wdod_woo_connector_sync_order';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'wdod-woo-connector';

	/**
	 * Schedule a sync for an order.
	 *
	 * @param int  $order_id Order ID.
	 * @param int  $attempt  Number of attempts already made (0 for the first run).
	 * @param int  $delay    Delay in seconds.
	 * @param bool $force    Sync even if the order is already marked as synced.
	 * @return bool True when a new job was scheduled, false when an identical job is already pending.
	 */
	public function enqueue( $order_id, $attempt = 0, $delay = 0, $force = false ) {
		$args      = array( (int) $order_id, (int) $attempt, (bool) $force );
		$timestamp = time() + max( 0, (int) $delay );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				return false;
			}

			as_schedule_single_action( $timestamp, self::HOOK, $args, self::GROUP );

			return true;
		}

		if ( wp_next_scheduled( self::HOOK, $args ) ) {
			return false;
		}

		return false !== wp_schedule_single_event( $timestamp, self::HOOK, $args );
	}

	/**
	 * Remove every pending sync job (used on uninstall and for admin tooling).
	 *
	 * @return void
	 */
	public function clear_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}

		wp_unschedule_hook( self::HOOK );
	}
}
