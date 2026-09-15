<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads Composer (PHPUnit, Brain Monkey) and then the plugin's own autoloader,
 * so the tests exercise the same class loading as production.
 *
 * @package WDOD\WooConnector\Tests
 */

$wdod_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $wdod_autoload ) ) {
	fwrite( STDERR, "Dependencies missing. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $wdod_autoload;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'WDOD_WOO_CONNECTOR_VERSION' ) ) {
	define( 'WDOD_WOO_CONNECTOR_VERSION', '1.0.0' );
}

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';

\WDOD\WooConnector\Autoloader::register();
