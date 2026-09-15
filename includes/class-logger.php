<?php
/**
 * Thin wrapper around the WooCommerce logger.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger.
 *
 * Delegates to wc_get_logger() lazily so the class can be constructed before
 * WooCommerce is fully loaded (and in unit tests, where a Null_Logger is used).
 */
class Logger {

	/**
	 * Log source shown in WooCommerce > Status > Logs.
	 */
	const SOURCE = 'wdod-woo-connector';

	/**
	 * Underlying WooCommerce logger (or null until first use).
	 *
	 * @var \WC_Logger_Interface|null
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param \WC_Logger_Interface|null $logger Optional logger implementation.
	 */
	public function __construct( $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   Log level (emergency|alert|critical|error|warning|notice|info|debug).
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ) {
		$handler = $this->get_handler();

		if ( null === $handler ) {
			return;
		}

		$context['source'] = self::SOURCE;

		$handler->log( $level, $message, $context );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function info( $message, array $context = array() ) {
		$this->log( 'info', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function warning( $message, array $context = array() ) {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function error( $message, array $context = array() ) {
		$this->log( 'error', $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 * @return void
	 */
	public function debug( $message, array $context = array() ) {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * Resolve the underlying logger on first use.
	 *
	 * @return \WC_Logger_Interface|null
	 */
	protected function get_handler() {
		if ( null === $this->logger && function_exists( 'wc_get_logger' ) ) {
			$this->logger = wc_get_logger();
		}

		return $this->logger;
	}
}
