<?php
/**
 * HTTP client that delivers signed JSON payloads to the configured endpoint.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

use WDOD\WooConnector\Interfaces\Api_Client_Interface;

defined( 'ABSPATH' ) || exit;

/**
 * Class Api_Client.
 */
class Api_Client implements Api_Client_Interface {

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 15;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Send a payload to the configured endpoint.
	 *
	 * @param array $payload JSON-serialisable payload.
	 * @return Result
	 */
	public function send( array $payload ) {
		return $this->request( $this->settings->get_endpoint(), $this->settings->get_api_key(), $payload );
	}

	/**
	 * Send a lightweight "ping" event, optionally with credentials that are not saved yet.
	 *
	 * @param string $endpoint Endpoint override (falls back to the saved option).
	 * @param string $api_key  API key override (falls back to the saved option).
	 * @return Result
	 */
	public function ping( $endpoint = '', $api_key = '' ) {
		$endpoint = '' !== $endpoint ? $endpoint : $this->settings->get_endpoint();
		$api_key  = '' !== $api_key ? $api_key : $this->settings->get_api_key();

		$payload = array(
			'event'   => 'ping',
			'sent_at' => gmdate( 'c' ),
			'site'    => home_url(),
		);

		return $this->request( $endpoint, $api_key, $payload );
	}

	/**
	 * Perform the HTTP request.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @param string $api_key  Shared secret.
	 * @param array  $payload  Payload.
	 * @return Result
	 */
	private function request( $endpoint, $api_key, array $payload ) {
		$endpoint = esc_url_raw( trim( (string) $endpoint ) );

		if ( '' === $endpoint ) {
			return Result::failure( 0, __( 'Endpoint URL is not configured.', 'wdod-woo-connector' ) );
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return Result::failure( 0, __( 'Payload could not be encoded as JSON.', 'wdod-woo-connector' ) );
		}

		$args = array(
			'method'      => 'POST',
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
			'httpversion' => '1.1',
			'user-agent'  => 'WDODWooConnector/' . WDOD_WOO_CONNECTOR_VERSION . '; ' . home_url(),
			'headers'     => array(
				'Content-Type'     => 'application/json; charset=utf-8',
				'Accept'           => 'application/json',
				'X-WDOD-Signature' => hash_hmac( 'sha256', $body, (string) $api_key ),
				'X-WDOD-Timestamp' => (string) time(),
				'X-WDOD-Event'     => isset( $payload['event'] ) ? (string) $payload['event'] : '',
			),
			'body'        => $body,
		);

		/**
		 * Filter the arguments passed to wp_remote_post().
		 *
		 * @param array  $args     Request arguments.
		 * @param array  $payload  Decoded payload.
		 * @param string $endpoint Endpoint URL.
		 */
		$args = apply_filters( 'wdod_woo_connector_request_args', $args, $payload, $endpoint );

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return Result::failure( 0, $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$message = $this->describe_response( $response, $code );

		if ( $code >= 200 && $code < 300 ) {
			return Result::success( $code, $message );
		}

		return Result::failure( $code, $message );
	}

	/**
	 * Build a short, log-friendly description of the response.
	 *
	 * @param array $response Response array.
	 * @param int   $code     HTTP status code.
	 * @return string
	 */
	private function describe_response( $response, $code ) {
		$reason = (string) wp_remote_retrieve_response_message( $response );
		$body   = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( '' !== $body ) {
			$body = wp_strip_all_tags( $body );

			if ( function_exists( 'mb_substr' ) ) {
				$body = mb_substr( $body, 0, 200 );
			} else {
				$body = substr( $body, 0, 200 );
			}
		}

		$description = sprintf( 'HTTP %d %s', $code, $reason );

		return '' !== $body ? $description . ' - ' . $body : $description;
	}
}
