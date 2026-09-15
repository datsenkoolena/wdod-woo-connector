<?php
/**
 * Shows delivery date, gift note and sync status in admin, emails and the customer order view.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Order_Display.
 */
class Order_Display {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'render_admin_box' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_email_fields' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_details' ) );
	}

	/**
	 * Admin order screen box.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function render_admin_box( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$view = array(
			'order'         => $order,
			'delivery_date' => self::format_delivery_date( (string) $order->get_meta( Checkout_Field::META_DELIVERY_DATE ) ),
			'gift_note'     => (string) $order->get_meta( Checkout_Field::META_GIFT_NOTE ),
			'sync_status'   => self::get_sync_status( $order ),
			'sync_label'    => self::get_sync_label( self::get_sync_status( $order ) ),
			'attempts'      => (int) $order->get_meta( Sync_Service::META_ATTEMPTS ),
			'last_error'    => (string) $order->get_meta( Sync_Service::META_LAST_ERROR ),
			'synced_at'     => self::format_datetime( (string) $order->get_meta( Sync_Service::META_SYNCED_AT ) ),
		);

		$template = WDOD_WOO_CONNECTOR_DIR . 'templates/admin/order-meta-box.php';

		if ( is_readable( $template ) ) {
			include $template;
		}
	}

	/**
	 * Add the fields to WooCommerce emails.
	 *
	 * @param array     $fields        Existing fields.
	 * @param bool      $sent_to_admin Whether the email goes to the admin.
	 * @param \WC_Order $order         Order.
	 * @return array
	 */
	public function add_email_fields( $fields, $sent_to_admin, $order ) {
		unset( $sent_to_admin );

		if ( ! $order instanceof \WC_Order ) {
			return $fields;
		}

		$date = (string) $order->get_meta( Checkout_Field::META_DELIVERY_DATE );
		$note = (string) $order->get_meta( Checkout_Field::META_GIFT_NOTE );

		if ( '' !== $date ) {
			$fields[ Checkout_Field::FIELD_DELIVERY_DATE ] = array(
				'label' => __( 'Preferred delivery date', 'wdod-woo-connector' ),
				'value' => self::format_delivery_date( $date ),
			);
		}

		if ( '' !== $note ) {
			$fields[ Checkout_Field::FIELD_GIFT_NOTE ] = array(
				'label' => __( 'Gift note', 'wdod-woo-connector' ),
				'value' => $note,
			);
		}

		return $fields;
	}

	/**
	 * Thank-you page and My Account > Orders > View order.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function render_customer_details( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$date = (string) $order->get_meta( Checkout_Field::META_DELIVERY_DATE );
		$note = (string) $order->get_meta( Checkout_Field::META_GIFT_NOTE );

		if ( '' === $date && '' === $note ) {
			return;
		}
		?>
		<section class="woocommerce-wdod-details wdod-order-details">
			<h2 class="woocommerce-column__title"><?php esc_html_e( 'Delivery details', 'wdod-woo-connector' ); ?></h2>
			<table class="woocommerce-table shop_table wdod-order-details__table">
				<tbody>
					<?php if ( '' !== $date ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Preferred delivery date', 'wdod-woo-connector' ); ?></th>
							<td><?php echo esc_html( self::format_delivery_date( $date ) ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( '' !== $note ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Gift note', 'wdod-woo-connector' ); ?></th>
							<td><?php echo nl2br( esc_html( $note ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped before nl2br(). ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Sync status stored on the order ('' when never queued).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function get_sync_status( \WC_Order $order ) {
		return (string) $order->get_meta( Sync_Service::META_STATUS );
	}

	/**
	 * Human-readable sync status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function get_sync_label( $status ) {
		switch ( $status ) {
			case Sync_Service::STATUS_SYNCED:
				return __( 'Synced', 'wdod-woo-connector' );
			case Sync_Service::STATUS_PENDING:
				return __( 'Pending', 'wdod-woo-connector' );
			case Sync_Service::STATUS_FAILED:
				return __( 'Failed', 'wdod-woo-connector' );
			default:
				return __( 'Not synced', 'wdod-woo-connector' );
		}
	}

	/**
	 * Format a Y-m-d value with the site's date format.
	 *
	 * @param string $date Y-m-d string.
	 * @return string Formatted date or empty string.
	 */
	public static function format_delivery_date( $date ) {
		if ( '' === $date || ! Checkout_Field::is_valid_date( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date . ' 00:00:00' );

		return false === $timestamp ? $date : date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Format an ISO 8601 (UTC) timestamp in the site's timezone and format.
	 *
	 * @param string $iso ISO 8601 string.
	 * @return string Formatted date-time or empty string.
	 */
	public static function format_datetime( $iso ) {
		if ( '' === $iso ) {
			return '';
		}

		$timestamp = strtotime( $iso );

		if ( false === $timestamp ) {
			return $iso;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
