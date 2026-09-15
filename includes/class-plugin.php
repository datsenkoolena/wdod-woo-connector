<?php
/**
 * Composition root: builds the services and wires them to WordPress.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $client;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Sync service.
	 *
	 * @var Sync_Service
	 */
	private $sync_service;

	/**
	 * Get the singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor: use instance().
	 */
	private function __construct() {}

	/**
	 * Build services and register hooks. Safe to call once; later calls are ignored.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->settings     = new Settings();
		$this->logger       = new Logger();
		$this->client       = new Api_Client( $this->settings );
		$this->queue        = new Queue();
		$this->sync_service = new Sync_Service( $this->client, $this->logger, $this->queue, $this->settings, new Payload_Builder() );

		$this->settings->init();
		( new Order_Hooks( $this->settings, $this->queue ) )->init();
		( new Checkout_Field() )->init();
		( new Order_Display() )->init();
		( new Order_Column( $this->queue ) )->init();

		$rest_controller = new Rest_Controller( $this->sync_service );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $rest_controller, 'register_routes' ) );
		add_action( Queue::HOOK, array( $this, 'run_sync' ), 10, 3 );
		add_action( 'wp_ajax_' . Settings::TEST_ACTION, array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wdod-woo-connector', false, dirname( plugin_basename( WDOD_WOO_CONNECTOR_FILE ) ) . '/languages' );
	}

	/**
	 * Scheduled action callback.
	 *
	 * @param int  $order_id Order ID.
	 * @param int  $attempt  Attempts already made.
	 * @param bool $force    Force flag.
	 * @return void
	 */
	public function run_sync( $order_id, $attempt = 0, $force = false ) {
		$this->sync_service->handle( (int) $order_id, (int) $attempt, (bool) $force );
	}

	/**
	 * AJAX: send a signed ping using the values currently in the settings form.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		check_ajax_referer( Settings::TEST_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wdod-woo-connector' ) ), 403 );
		}

		$endpoint = isset( $_POST['endpoint'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) : '';
		$api_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

		$result = $this->client->ping( $endpoint, $api_key );

		if ( $result->is_ok() ) {
			wp_send_json_success(
				array(
					/* translators: %d: HTTP status code */
					'message' => sprintf( __( 'Connection OK (HTTP %d).', 'wdod-woo-connector' ), $result->get_http_code() ),
				)
			);
		}

		$this->logger->warning( 'Test connection failed: ' . $result->get_message() );

		wp_send_json_error(
			array(
				/* translators: %s: error message */
				'message' => sprintf( __( 'Connection failed: %s', 'wdod-woo-connector' ), $result->get_message() ),
			)
		);
	}

	/**
	 * Settings service.
	 *
	 * @return Settings
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Queue service.
	 *
	 * @return Queue
	 */
	public function get_queue() {
		return $this->queue;
	}

	/**
	 * Sync service.
	 *
	 * @return Sync_Service
	 */
	public function get_sync_service() {
		return $this->sync_service;
	}
}
