<?php
/**
 * Extra checkout fields: preferred delivery date and gift note.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Checkout_Field.
 */
class Checkout_Field {

	/**
	 * Field keys (form field names).
	 */
	const FIELD_DELIVERY_DATE = 'wdod_delivery_date';
	const FIELD_GIFT_NOTE     = 'wdod_gift_note';

	/**
	 * Order meta keys.
	 */
	const META_DELIVERY_DATE = '_wdod_delivery_date';
	const META_GIFT_NOTE     = '_wdod_gift_note';

	/**
	 * Maximum gift note length in characters.
	 */
	const GIFT_NOTE_MAX_LENGTH = 200;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Field definitions in woocommerce_form_field() format.
	 *
	 * @return array
	 */
	public function get_fields() {
		$fields = array(
			self::FIELD_DELIVERY_DATE => array(
				'type'              => 'date',
				'label'             => __( 'Preferred delivery date', 'wdod-woo-connector' ),
				'required'          => false,
				'class'             => array( 'form-row-wide', 'wdod-delivery-date' ),
				'priority'          => 10,
				'custom_attributes' => array(
					'min' => current_time( 'Y-m-d' ),
				),
			),
			self::FIELD_GIFT_NOTE     => array(
				'type'              => 'textarea',
				'label'             => __( 'Gift note', 'wdod-woo-connector' ),
				'placeholder'       => __( 'Printed on the card, max. 200 characters.', 'wdod-woo-connector' ),
				'required'          => false,
				'class'             => array( 'form-row-wide', 'wdod-gift-note' ),
				'priority'          => 20,
				'maxlength'         => self::GIFT_NOTE_MAX_LENGTH,
				'custom_attributes' => array(
					'rows' => 3,
				),
			),
		);

		/**
		 * Filter the extra checkout fields. Return an empty array to disable them.
		 *
		 * @param array $fields Field definitions keyed by field name.
		 */
		return (array) apply_filters( 'wdod_woo_connector_checkout_fields', $fields );
	}

	/**
	 * Output the fields after the order notes.
	 *
	 * @param \WC_Checkout $checkout Checkout instance.
	 * @return void
	 */
	public function render_fields( $checkout ) {
		$fields = $this->get_fields();

		if ( empty( $fields ) ) {
			return;
		}

		echo '<div class="wdod-checkout-fields">';

		foreach ( $fields as $key => $args ) {
			$value = $checkout instanceof \WC_Checkout ? $checkout->get_value( $key ) : null;
			woocommerce_form_field( $key, $args, $value );
		}

		echo '</div>';
	}

	/**
	 * Validate submitted values (nonce is verified by WooCommerce before this hook fires).
	 *
	 * @return void
	 */
	public function validate() {
		$fields = $this->get_fields();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce-process-checkout-nonce.
		$date = isset( $_POST[ self::FIELD_DELIVERY_DATE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_DELIVERY_DATE ] ) ) : '';
		$note = isset( $_POST[ self::FIELD_GIFT_NOTE ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD_GIFT_NOTE ] ) ) : '';
		// phpcs:enable

		if ( isset( $fields[ self::FIELD_DELIVERY_DATE ] ) && '' !== $date ) {
			if ( ! self::is_valid_date( $date ) ) {
				wc_add_notice( __( 'Please enter the delivery date in the format YYYY-MM-DD.', 'wdod-woo-connector' ), 'error' );
			} elseif ( $date < current_time( 'Y-m-d' ) ) {
				wc_add_notice( __( 'The delivery date cannot be in the past.', 'wdod-woo-connector' ), 'error' );
			}
		}

		if ( isset( $fields[ self::FIELD_GIFT_NOTE ] ) && '' !== $note ) {
			$max = isset( $fields[ self::FIELD_GIFT_NOTE ]['maxlength'] ) ? (int) $fields[ self::FIELD_GIFT_NOTE ]['maxlength'] : self::GIFT_NOTE_MAX_LENGTH;

			if ( mb_strlen( $note ) > $max ) {
				wc_add_notice(
					sprintf(
						/* translators: %d: maximum number of characters */
						__( 'The gift note may not be longer than %d characters.', 'wdod-woo-connector' ),
						$max
					),
					'error'
				);
			}
		}
	}

	/**
	 * Persist the values on the order being created.
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Posted checkout data (registered fields only).
	 * @return void
	 */
	public function save( $order, $data ) {
		unset( $data );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$fields = $this->get_fields();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies woocommerce-process-checkout-nonce.
		$date = isset( $_POST[ self::FIELD_DELIVERY_DATE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_DELIVERY_DATE ] ) ) : '';
		$note = isset( $_POST[ self::FIELD_GIFT_NOTE ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::FIELD_GIFT_NOTE ] ) ) : '';
		// phpcs:enable

		if ( isset( $fields[ self::FIELD_DELIVERY_DATE ] ) && '' !== $date && self::is_valid_date( $date ) ) {
			$order->update_meta_data( self::META_DELIVERY_DATE, $date );
		}

		if ( isset( $fields[ self::FIELD_GIFT_NOTE ] ) && '' !== $note ) {
			$max = isset( $fields[ self::FIELD_GIFT_NOTE ]['maxlength'] ) ? (int) $fields[ self::FIELD_GIFT_NOTE ]['maxlength'] : self::GIFT_NOTE_MAX_LENGTH;

			$order->update_meta_data( self::META_GIFT_NOTE, mb_substr( $note, 0, $max ) );
		}
	}

	/**
	 * Strict Y-m-d validation.
	 *
	 * @param string $date Candidate value.
	 * @return bool
	 */
	public static function is_valid_date( $date ) {
		$parsed = \DateTime::createFromFormat( '!Y-m-d', $date );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $date;
	}
}
