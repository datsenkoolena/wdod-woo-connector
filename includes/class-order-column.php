<?php
/**
 * "WDOD Sync" column and "Sync now" row action on the orders list (legacy and HPOS screens).
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Column.
 */
class Order_Column {

	/**
	 * Column key.
	 */
	const COLUMN = 'wdod_sync';

	/**
	 * The admin-post action name for "Sync now".
	 */
	const SYNC_NOW_ACTION = 'wdod_woo_connector_sync_now';

	/**
	 * Query argument used to show the result notice.
	 */
	const NOTICE_ARG = 'wdod_sync_notice';

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private $queue;

	/**
	 * Constructor.
	 *
	 * @param Queue $queue Queue.
	 */
	public function __construct( Queue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Legacy (posts table) screen.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 10, 2 );

		// HPOS screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_column' ), 10, 2 );

		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::SYNC_NOW_ACTION, array( $this, 'handle_sync_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'admin_head', array( $this, 'print_styles' ) );
	}

	/**
	 * Insert the column after the status column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$updated[ self::COLUMN ] = __( 'WDOD Sync', 'wdod-woo-connector' );
			}
		}

		if ( ! isset( $updated[ self::COLUMN ] ) ) {
			$updated[ self::COLUMN ] = __( 'WDOD Sync', 'wdod-woo-connector' );
		}

		return $updated;
	}

	/**
	 * Render the column on the legacy screen.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order post ID.
	 * @return void
	 */
	public function render_legacy_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( $order instanceof \WC_Order ) {
			$this->render_badge( $order );
		}
	}

	/**
	 * Render the column on the HPOS screen.
	 *
	 * @param string    $column Column key.
	 * @param \WC_Order $order  Order.
	 * @return void
	 */
	public function render_hpos_column( $column, $order ) {
		if ( self::COLUMN !== $column ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( $order instanceof \WC_Order ) {
			$this->render_badge( $order );
		}
	}

	/**
	 * Output the status badge.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function render_badge( \WC_Order $order ) {
		$status     = Order_Display::get_sync_status( $order );
		$attempts   = (int) $order->get_meta( Sync_Service::META_ATTEMPTS );
		$last_error = (string) $order->get_meta( Sync_Service::META_LAST_ERROR );
		$synced_at  = Order_Display::format_datetime( (string) $order->get_meta( Sync_Service::META_SYNCED_AT ) );

		$title = array();

		if ( $attempts > 0 ) {
			/* translators: %d: number of attempts */
			$title[] = sprintf( _n( '%d attempt', '%d attempts', $attempts, 'wdod-woo-connector' ), $attempts );
		}

		if ( '' !== $synced_at ) {
			/* translators: %s: date and time */
			$title[] = sprintf( __( 'Synced at %s', 'wdod-woo-connector' ), $synced_at );
		}

		if ( '' !== $last_error ) {
			/* translators: %s: error message */
			$title[] = sprintf( __( 'Last error: %s', 'wdod-woo-connector' ), $last_error );
		}

		printf(
			'<mark class="wdod-sync-badge wdod-sync-badge--%1$s" title="%2$s"><span>%3$s</span></mark>',
			esc_attr( '' !== $status ? $status : 'none' ),
			esc_attr( implode( ' | ', $title ) ),
			esc_html( Order_Display::get_sync_label( $status ) )
		);

		if ( $attempts > 0 ) {
			printf( ' <small class="wdod-sync-attempts">(%d)</small>', (int) $attempts );
		}
	}

	/**
	 * Add the "Sync now" action button.
	 *
	 * @param array     $actions Actions.
	 * @param \WC_Order $order   Order.
	 * @return array
	 */
	public function add_row_action( $actions, $order ) {
		if ( ! $order instanceof \WC_Order || ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		$actions['wdod_sync'] = array(
			'url'    => $this->get_sync_now_url( $order->get_id() ),
			'name'   => __( 'Sync now', 'wdod-woo-connector' ),
			'action' => 'wdod-sync',
		);

		return $actions;
	}

	/**
	 * Nonce-protected admin-post URL for a manual sync.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public function get_sync_now_url( $order_id ) {
		$url = add_query_arg(
			array(
				'action'   => self::SYNC_NOW_ACTION,
				'order_id' => (int) $order_id,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, self::SYNC_NOW_ACTION . '_' . (int) $order_id );
	}

	/**
	 * Handle the "Sync now" request: queue a forced sync and redirect back.
	 *
	 * @return void
	 */
	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wdod-woo-connector' ), '', array( 'response' => 403 ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.

		check_admin_referer( self::SYNC_NOW_ACTION . '_' . $order_id );

		$order  = $order_id ? wc_get_order( $order_id ) : false;
		$notice = 'error';

		if ( $order instanceof \WC_Order ) {
			$this->queue->enqueue( $order_id, 0, 0, true );
			$order->update_meta_data( Sync_Service::META_STATUS, Sync_Service::STATUS_PENDING );
			$order->save();
			$notice = 'queued';
		}

		$redirect = wp_get_referer();

		if ( ! $redirect ) {
			$redirect = $this->get_orders_list_url();
		}

		$redirect = remove_query_arg( array( self::NOTICE_ARG, '_wpnonce' ), $redirect );

		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $notice, $redirect ) );
		exit;
	}

	/**
	 * Show the result notice after a redirect.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag, no state change.
		$notice = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';

		if ( '' === $notice || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( 'queued' === $notice ) {
			$class   = 'notice-success';
			$message = __( 'The order has been queued for sync. It will be delivered in the background within a minute.', 'wdod-woo-connector' );
		} else {
			$class   = 'notice-error';
			$message = __( 'The order could not be queued for sync.', 'wdod-woo-connector' );
		}

		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Badge and action-button styles, printed on the orders screens only.
	 *
	 * @return void
	 */
	public function print_styles() {
		if ( ! $this->is_orders_screen() ) {
			return;
		}
		?>
		<style id="wdod-woo-connector-orders">
			.wdod-sync-badge { display: inline-flex; line-height: 2.5em; color: #2c4700; background: #dfe8c5; border-radius: 4px; padding: 0 1em; margin: -.25em 0; white-space: nowrap; max-width: 100%; }
			.wdod-sync-badge--none { color: #50575e; background: #e5e5e5; }
			.wdod-sync-badge--pending { color: #6b4700; background: #fce8b5; }
			.wdod-sync-badge--synced { color: #0d5c2f; background: #c6e1c6; }
			.wdod-sync-badge--failed { color: #761919; background: #f2c7c7; }
			.wdod-sync-attempts { color: #646970; }
			.column-wc_actions .wc-action-button-wdod-sync::after,
			.order_actions .wc-action-button-wdod-sync::after { font-family: Dashicons !important; content: "\f463" !important; }
		</style>
		<?php
	}

	/**
	 * Whether the current screen is an orders list.
	 *
	 * @return bool
	 */
	private function is_orders_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
	}

	/**
	 * Orders list URL for the active storage mode.
	 *
	 * @return string
	 */
	private function get_orders_list_url() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders' );
		}

		return admin_url( 'edit.php?post_type=shop_order' );
	}
}
