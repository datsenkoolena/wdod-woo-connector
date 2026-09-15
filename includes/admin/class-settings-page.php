<?php
/**
 * WooCommerce > Settings > WDOD Connector tab.
 *
 * Only loaded from the `woocommerce_get_settings_pages` filter, because the parent
 * class WC_Settings_Page is not available earlier.
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector\Admin;

use WDOD\WooConnector\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings_Page.
 */
class Settings_Page extends \WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = Settings::TAB_ID;
		$this->label = __( 'WDOD Connector', 'wdod-woo-connector' );

		parent::__construct();
	}

	/**
	 * Settings for the (only) section of this tab.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section() {
		return Settings::get_fields();
	}
}
