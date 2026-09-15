<?php
/**
 * No-op logger used in unit tests and when logging is not desired.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Null_Logger.
 */
class Null_Logger extends Logger {

	/**
	 * Discard the entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		// Intentionally empty.
	}
}
