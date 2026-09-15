<?php
/**
 * Immutable value object describing the outcome of an API request.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Result.
 */
final class Result {

	/**
	 * Whether the request succeeded (2xx).
	 *
	 * @var bool
	 */
	private $ok;

	/**
	 * HTTP status code (0 when no HTTP response was received, e.g. network error).
	 *
	 * @var int
	 */
	private $http_code;

	/**
	 * Human-readable message.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Whether a retry could succeed.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Constructor. Use the named constructors instead.
	 *
	 * @param bool   $ok        Success flag.
	 * @param int    $http_code HTTP status code.
	 * @param string $message   Message.
	 * @param bool   $retryable Retryable flag.
	 */
	private function __construct( $ok, $http_code, $message, $retryable ) {
		$this->ok        = (bool) $ok;
		$this->http_code = (int) $http_code;
		$this->message   = (string) $message;
		$this->retryable = (bool) $retryable;
	}

	/**
	 * Build a successful result.
	 *
	 * @param int    $http_code HTTP status code.
	 * @param string $message   Message.
	 * @return self
	 */
	public static function success( $http_code = 200, $message = '' ) {
		return new self( true, $http_code, $message, false );
	}

	/**
	 * Build a failed result.
	 *
	 * Network errors (code 0), rate limiting (429) and server errors (5xx)
	 * are considered transient and therefore retryable. Everything else
	 * (4xx, 3xx) is treated as permanent.
	 *
	 * @param int    $http_code HTTP status code.
	 * @param string $message   Message.
	 * @return self
	 */
	public static function failure( $http_code, $message = '' ) {
		$http_code = (int) $http_code;
		$retryable = ( 0 === $http_code || 429 === $http_code || $http_code >= 500 );

		return new self( false, $http_code, $message, $retryable );
	}

	/**
	 * Whether the request succeeded.
	 *
	 * @return bool
	 */
	public function is_ok() {
		return $this->ok;
	}

	/**
	 * HTTP status code.
	 *
	 * @return int
	 */
	public function get_http_code() {
		return $this->http_code;
	}

	/**
	 * Message.
	 *
	 * @return string
	 */
	public function get_message() {
		return $this->message;
	}

	/**
	 * Whether a retry could succeed.
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return $this->retryable;
	}
}
