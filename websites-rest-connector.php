<?php
/**
 * Polylang Auto Translate
 * 
 * Plugin Name:       Websites REST connector
 * Description:       Connects content of 2 websites via REST API
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            Robbert Vermeulen
 * Author URI:        https://www.robbertvermeulen.com
 * Text Domain:       wrc-plugin
 * Domain Path:       /languages
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.txt
 */

define('WRC_PLUGIN_VERSION', '1.0.0');
define('WRC_PLUGIN_DIR', plugin_dir_path( __FILE__ ));
define('WRC_PLUGIN_URL', plugin_dir_url( __FILE__ ));

require __DIR__ . '/vendor/autoload.php';

class Websites_REST_Connector_Plugin {

    private static $instance = null;

    private function __construct() {

        // include WRC_PLUGIN_DIR . 'includes/admin.php';
        include WRC_PLUGIN_DIR . 'includes/rest-connector.php';
        include WRC_PLUGIN_DIR . 'includes/admin.php';
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

}

Websites_REST_Connector_Plugin::get_instance();