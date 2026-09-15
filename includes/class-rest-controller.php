<?php
/**
 * REST API: GET/POST /wp-json/wdod/v1/orders/{id}/sync
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rest_Controller.
 */
class Rest_Controller extends \WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wdod/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'orders';

	/**
	 * Sync service.
	 *
	 * @var Sync_Service
	 */
	private $sync_service;

	/**
	 * Constructor.
	 *
	 * @param Sync_Service $sync_service Sync service.
	 */
	public function __construct( Sync_Service $sync_service ) {
		$this->sync_service = $sync_service;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/sync',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_id_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_id_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Only shop managers and administrators may use the endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_check( $request ) {
		unset( $request );

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new \WP_Error(
			'wdod_woo_connector_forbidden',
			__( 'You are not allowed to manage order syncs.', 'wdod-woo-connector' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET: current sync state of the order.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$order = $this->get_order( (int) $request['id'] );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return rest_ensure_response( $this->prepare_status( $order ) );
	}

	/**
	 * POST: run a forced sync synchronously and return the resulting state.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$order = $this->get_order( (int) $request['id'] );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$this->sync_service->handle( $order->get_id(), 0, true );

		// Reload so we return the persisted meta.
		$order = $this->get_order( $order->get_id() );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$data     = $this->prepare_status( $order );
		$response = rest_ensure_response( $data );

		if ( Sync_Service::STATUS_SYNCED !== $data['sync_status'] ) {
			$response->set_status( 502 );
		}

		return $response;
	}

	/**
	 * Item schema.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wdod_order_sync',
			'type'       => 'object',
			'properties' => array(
				'id'          => array(
					'description' => __( 'Order ID.', 'wdod-woo-connector' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'number'      => array(
					'description' => __( 'Order number.', 'wdod-woo-connector' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'sync_status' => array(
					'description' => __( 'Sync status.', 'wdod-woo-connector' ),
					'type'        => 'string',
					'enum'        => array( 'none', Sync_Service::STATUS_PENDING, Sync_Service::STATUS_SYNCED, Sync_Service::STATUS_FAILED ),
					'readonly'    => true,
				),
				'attempts'    => array(
					'description' => __( 'Number of delivery attempts.', 'wdod-woo-connector' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'last_error'  => array(
					'description' => __( 'Last error message, if any.', 'wdod-woo-connector' ),
					'type'        => array( 'string', 'null' ),
					'readonly'    => true,
				),
				'synced_at'   => array(
					'description' => __( 'ISO 8601 timestamp (UTC) of the last successful sync.', 'wdod-woo-connector' ),
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Argument schema for the {id} parameter.
	 *
	 * @return array
	 */
	private function get_id_args() {
		return array(
			'id' => array(
				'description'       => __( 'Order ID.', 'wdod-woo-connector' ),
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Load an order or return a 404 error.
	 *
	 * @param int $order_id Order ID.
	 * @return \WC_Order|\WP_Error
	 */
	private function get_order( $order_id ) {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error(
				'wdod_woo_connector_order_not_found',
				__( 'Order not found.', 'wdod-woo-connector' ),
				array( 'status' => 404 )
			);
		}

		return $order;
	}

	/**
	 * Build the response body.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	private function prepare_status( \WC_Order $order ) {
		$status     = (string) $order->get_meta( Sync_Service::META_STATUS );
		$last_error = (string) $order->get_meta( Sync_Service::META_LAST_ERROR );
		$synced_at  = (string) $order->get_meta( Sync_Service::META_SYNCED_AT );

		return array(
			'id'          => $order->get_id(),
			'number'      => (string) $order->get_order_number(),
			'sync_status' => '' !== $status ? $status : 'none',
			'attempts'    => (int) $order->get_meta( Sync_Service::META_ATTEMPTS ),
			'last_error'  => '' !== $last_error ? $last_error : null,
			'synced_at'   => '' !== $synced_at ? $synced_at : null,
		);
	}
}
