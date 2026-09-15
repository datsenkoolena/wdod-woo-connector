<?php
/**
 * Plugin options: typed getters, field definitions and WooCommerce settings tab registration.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

use WDOD\WooConnector\Admin\Settings_Page;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings.
 *
 * Getters are static so they can be used anywhere; the class is still injected
 * as a dependency so consumers can be tested with a subclass.
 */
class Settings {

	/**
	 * WooCommerce settings tab ID.
	 */
	const TAB_ID = 'wdod_connector';

	/**
	 * Option names.
	 */
	const OPTION_ENABLED      = 'wdod_woo_connector_enabled';
	const OPTION_ENDPOINT     = 'wdod_woo_connector_endpoint';
	const OPTION_API_KEY      = 'wdod_woo_connector_api_key';
	const OPTION_STATUSES     = 'wdod_woo_connector_statuses';
	const OPTION_MAX_ATTEMPTS = 'wdod_woo_connector_max_attempts';

	/**
	 * AJAX action / nonce used by the "Test connection" button.
	 */
	const TEST_ACTION = 'wdod_woo_connector_test';

	/**
	 * Default statuses (with the "wc-" prefix as stored by WooCommerce).
	 *
	 * @var string[]
	 */
	const DEFAULT_STATUSES = array( 'wc-processing' );

	/**
	 * Default maximum number of delivery attempts.
	 */
	const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'register_page' ) );
		add_action( 'woocommerce_admin_field_wdod_test_button', array( $this, 'render_test_button' ) );
		add_action( 'admin_footer', array( $this, 'print_test_script' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION_ENDPOINT, array( $this, 'sanitize_endpoint' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION_MAX_ATTEMPTS, array( $this, 'sanitize_max_attempts' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WDOD_WOO_CONNECTOR_FILE ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Whether syncing is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Remote endpoint URL.
	 *
	 * @return string
	 */
	public static function get_endpoint() {
		return esc_url_raw( trim( (string) get_option( self::OPTION_ENDPOINT, '' ) ) );
	}

	/**
	 * Shared secret used for the HMAC signature.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		return (string) get_option( self::OPTION_API_KEY, '' );
	}

	/**
	 * Order statuses that trigger a sync, without the "wc-" prefix.
	 *
	 * @return string[]
	 */
	public static function get_statuses() {
		$raw = get_option( self::OPTION_STATUSES, self::DEFAULT_STATUSES );

		if ( ! is_array( $raw ) ) {
			$raw = self::DEFAULT_STATUSES;
		}

		$statuses = array();

		foreach ( $raw as $status ) {
			$status = sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );

			if ( '' !== $status ) {
				$statuses[] = $status;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Maximum number of delivery attempts (always >= 1).
	 *
	 * @return int
	 */
	public static function get_max_attempts() {
		return max( 1, (int) get_option( self::OPTION_MAX_ATTEMPTS, self::DEFAULT_MAX_ATTEMPTS ) );
	}

	/**
	 * URL of the settings tab.
	 *
	 * @return string
	 */
	public static function get_settings_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB_ID );
	}

	/**
	 * Field definitions consumed by WC_Admin_Settings::output_fields().
	 *
	 * @return array
	 */
	public static function get_fields() {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		return array(
			array(
				'title' => __( 'WDOD Connector', 'wdod-woo-connector' ),
				'type'  => 'title',
				'desc'  => __( 'Push orders to your external system as signed JSON webhooks. Deliveries run in the background and are retried with exponential backoff.', 'wdod-woo-connector' ),
				'id'    => 'wdod_woo_connector_section',
			),
			array(
				'title'   => __( 'Enable sync', 'wdod-woo-connector' ),
				'desc'    => __( 'Send orders to the endpoint below when they reach one of the selected statuses.', 'wdod-woo-connector' ),
				'id'      => self::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'       => __( 'Endpoint URL', 'wdod-woo-connector' ),
				'desc'        => __( 'HTTPS URL that receives the POST request.', 'wdod-woo-connector' ),
				'desc_tip'    => true,
				'id'          => self::OPTION_ENDPOINT,
				'type'        => 'text',
				'placeholder' => 'https://api.example.com/webhooks/woocommerce',
				'css'         => 'min-width:400px;',
			),
			array(
				'title'    => __( 'API key', 'wdod-woo-connector' ),
				'desc'     => __( 'Shared secret used to compute the X-WDOD-Signature (HMAC-SHA256) header.', 'wdod-woo-connector' ),
				'desc_tip' => true,
				'id'       => self::OPTION_API_KEY,
				'type'     => 'password',
				'css'      => 'min-width:400px;',
			),
			array(
				'title'    => __( 'Sync on statuses', 'wdod-woo-connector' ),
				'desc'     => __( 'Orders are queued for delivery whenever they transition to one of these statuses.', 'wdod-woo-connector' ),
				'desc_tip' => true,
				'id'       => self::OPTION_STATUSES,
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'css'      => 'min-width:300px;',
				'options'  => $statuses,
				'default'  => self::DEFAULT_STATUSES,
			),
			array(
				'title'             => __( 'Max attempts', 'wdod-woo-connector' ),
				'desc'              => __( 'How many times a delivery is attempted before the order is marked as failed.', 'wdod-woo-connector' ),
				'desc_tip'          => true,
				'id'                => self::OPTION_MAX_ATTEMPTS,
				'type'              => 'number',
				'default'           => self::DEFAULT_MAX_ATTEMPTS,
				'css'               => 'width:80px;',
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 20,
					'step' => 1,
				),
			),
			array(
				'title'     => __( 'Test connection', 'wdod-woo-connector' ),
				'id'        => 'wdod_woo_connector_test_button',
				'type'      => 'wdod_test_button',
				'is_option' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wdod_woo_connector_section',
			),
		);
	}

	/**
	 * Register the settings tab with WooCommerce.
	 *
	 * The page class extends WC_Settings_Page, which only exists once WooCommerce
	 * loads its settings screens, so it is instantiated here and nowhere else.
	 *
	 * @param array $pages Registered settings pages.
	 * @return array
	 */
	public function register_page( $pages ) {
		$pages[] = new Settings_Page();

		return $pages;
	}

	/**
	 * Sanitize the endpoint option.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_endpoint( $value ) {
		return esc_url_raw( trim( (string) $value ) );
	}

	/**
	 * Sanitize the max attempts option.
	 *
	 * @param mixed $value Submitted value.
	 * @return int
	 */
	public function sanitize_max_attempts( $value ) {
		return min( 20, max( 1, (int) $value ) );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function add_settings_link( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::get_settings_url() ),
			esc_html__( 'Settings', 'wdod-woo-connector' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Render the custom "Test connection" field.
	 *
	 * @param array $field Field definition.
	 * @return void
	 */
	public function render_test_button( array $field ) {
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="wdod-woo-connector-test"><?php echo esc_html( $field['title'] ); ?></label>
			</th>
			<td class="forminp">
				<button type="button" class="button button-secondary" id="wdod-woo-connector-test" data-nonce="<?php echo esc_attr( wp_create_nonce( self::TEST_ACTION ) ); ?>">
					<?php esc_html_e( 'Send test ping', 'wdod-woo-connector' ); ?>
				</button>
				<span id="wdod-woo-connector-test-result" class="description" role="status" aria-live="polite"></span>
				<p class="description"><?php esc_html_e( 'Sends a signed "ping" event to the endpoint using the values currently in the form (unsaved changes included).', 'wdod-woo-connector' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Print the inline script that powers the "Test connection" button.
	 *
	 * @return void
	 */
	public function print_test_script() {
		if ( ! $this->is_settings_tab() ) {
			return;
		}

		$config = array(
			'action'   => self::TEST_ACTION,
			'endpoint' => self::OPTION_ENDPOINT,
			'apiKey'   => self::OPTION_API_KEY,
			'i18n'     => array(
				'testing' => __( 'Testing…', 'wdod-woo-connector' ),
				'error'   => __( 'Request failed. Check the browser console and WooCommerce logs.', 'wdod-woo-connector' ),
			),
		);

		$script = 'window.WDODWooConnector = ' . wp_json_encode( $config ) . ';'
			. '(function ($) {'
			. '  var cfg = window.WDODWooConnector;'
			. '  $("#wdod-woo-connector-test").on("click", function (e) {'
			. '    e.preventDefault();'
			. '    var $btn = $(this), $out = $("#wdod-woo-connector-test-result");'
			. '    $btn.prop("disabled", true);'
			. '    $out.text(cfg.i18n.testing).css("color", "");'
			. '    $.post(window.ajaxurl, {'
			. '      action: cfg.action,'
			. '      nonce: $btn.data("nonce"),'
			. '      endpoint: $("#" + cfg.endpoint).val(),'
			. '      api_key: $("#" + cfg.apiKey).val()'
			. '    }).done(function (r) {'
			. '      var msg = (r && r.data && r.data.message) ? r.data.message : cfg.i18n.error;'
			. '      $out.text(msg).css("color", r && r.success ? "#00a32a" : "#d63638");'
			. '    }).fail(function () {'
			. '      $out.text(cfg.i18n.error).css("color", "#d63638");'
			. '    }).always(function () {'
			. '      $btn.prop("disabled", false);'
			. '    });'
			. '  });'
			. '})(jQuery);';

		wp_print_inline_script_tag( $script );
	}

	/**
	 * Whether the current request is the plugin's settings tab.
	 *
	 * @return bool
	 */
	private function is_settings_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable

		return 'wc-settings' === $page && self::TAB_ID === $tab;
	}
}
