<?php
/**
 * Plugin Name: Devto Wp Importer
 * Plugin URI:  https://wordpress.org/plugins/devto-importer/
 * Description: Import you Dev.to posts to your WordPress site by one click
 * Version: 0.1.0
 * Author: WeLabs
 * Author URI: https://wordpress.org/plugins/devto-importer/
 * Text Domain: devto-wp-importer
 * WC requires at least: 5.0.0
 * Domain Path: /languages/
 * Requires Plugins:
 * License: GPL2
 */
use WeLabs\DevtoWpImporter\DevtoWpImporter;

// don't call the file directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'DEVTO_WP_IMPORTER_FILE' ) ) {
    define( 'DEVTO_WP_IMPORTER_FILE', __FILE__ );
}

if ( ! defined( 'DEVTO_WP_IMPORTER_BASENAME' ) ) {
    define( 'DEVTO_WP_IMPORTER_BASENAME', plugin_basename( __FILE__ ) );
}

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Load Devto_Wp_Importer Plugin when all plugins loaded
 *
 * @return \WeLabs\DevtoWpImporter\DevtoWpImporter
 */
function welabs_devto_wp_importer() {
    return DevtoWpImporter::init();
}

// Lets Go....
welabs_devto_wp_importer();
