<?php
/**
 * Contract for the outbound API client.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector\Interfaces;

use WDOD\WooConnector\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Interface Api_Client_Interface.
 */
interface Api_Client_Interface {

	/**
	 * Send a payload to the remote endpoint.
	 *
	 * @param array $payload JSON-serialisable payload.
	 * @return Result
	 */
	public function send( array $payload );
}
