<?php
/**
 * Plugin Name:          WDOD WooCommerce Connector
 * Plugin URI:           https://github.com/datsenkoolena/wdod-woo-connector
 * Description:          Pushes WooCommerce orders to an external API using signed webhooks, background queueing and exponential-backoff retries. Adds delivery date and gift note checkout fields, a sync status column and a REST endpoint for manual sync.
 * Version:              1.0.0
 * Author:               Olena Datsenko
 * Author URI:           https://github.com/datsenkoolena
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wdod-woo-connector
 * Domain Path:          /languages
 * Requires at least:    6.4
 * Requires PHP:         7.4
 * Tested up to:         7.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.2
 * WC tested up to:      9.9
 *
 * @package WDOD\WooConnector
 */

defined( 'ABSPATH' ) || exit;

define( 'WDOD_WOO_CONNECTOR_VERSION', '1.0.0' );
define( 'WDOD_WOO_CONNECTOR_FILE', __FILE__ );
define( 'WDOD_WOO_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDOD_WOO_CONNECTOR_URL', plugin_dir_url( __FILE__ ) );

require_once WDOD_WOO_CONNECTOR_DIR . 'includes/class-autoloader.php';

\WDOD\WooConnector\Autoloader::register();

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * All order reads/writes in this plugin go through the WC_Order CRUD API,
 * so the plugin works with both the legacy posts table and the custom orders tables.
 *
 * @return void
 */
function wdod_woo_connector_declare_hpos_compatibility() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'wdod_woo_connector_declare_hpos_compatibility' );

/**
 * Render an admin notice when WooCommerce is not active.
 *
 * @return void
 */
function wdod_woo_connector_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'WDOD WooCommerce Connector requires WooCommerce to be installed and active.', 'wdod-woo-connector' )
	);
}

/**
 * Boot the plugin once all plugins are loaded.
 *
 * @return void
 */
function wdod_woo_connector_boot() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wdod_woo_connector_missing_wc_notice' );
		return;
	}

	\WDOD\WooConnector\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'wdod_woo_connector_boot' );
