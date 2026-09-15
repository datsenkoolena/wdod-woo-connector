<?php
/**
 * PSR-4-ish autoloader mapping the WDOD\WooConnector namespace to WPCS-style file names.
 *
 * Examples:
 *   WDOD\WooConnector\Sync_Service                     -> includes/class-sync-service.php
 *   WDOD\WooConnector\Interfaces\Api_Client_Interface  -> includes/interfaces/interface-api-client.php
 *   WDOD\WooConnector\Admin\Settings_Page              -> includes/admin/class-settings-page.php
 *
 * @package WDOD\WooConnector
 */

namespace WDOD\WooConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Class Autoloader.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	const PREFIX = 'WDOD\\WooConnector\\';

	/**
	 * Register the autoloader with SPL (prepended so it wins over Composer's classmap in tests).
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ), true, true );
	}

	/**
	 * Resolve a fully-qualified class name to a file and require it.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative  = substr( $class_name, strlen( self::PREFIX ) );
		$parts     = explode( '\\', $relative );
		$name      = array_pop( $parts );
		$slug      = strtolower( str_replace( '_', '-', $name ) );
		$directory = strtolower( str_replace( '_', '-', implode( '/', $parts ) ) );

		if ( '-interface' === substr( $slug, -10 ) ) {
			$file = 'interface-' . substr( $slug, 0, -10 ) . '.php';
		} else {
			$file = 'class-' . $slug . '.php';
		}

		$path = self::base_dir() . 'includes/' . ( '' !== $directory ? $directory . '/' : '' ) . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Plugin root directory with a trailing slash.
	 *
	 * Falls back to the parent of this file so the loader also works
	 * outside WordPress (PHPUnit bootstrap).
	 *
	 * @return string
	 */
	private static function base_dir() {
		if ( defined( 'WDOD_WOO_CONNECTOR_DIR' ) ) {
			return WDOD_WOO_CONNECTOR_DIR;
		}

		return dirname( __DIR__ ) . '/';
	}
}
