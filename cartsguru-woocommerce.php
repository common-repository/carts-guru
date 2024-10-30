<?php
/**
 * @wordpress-plugin
 * Plugin Name: Carts Guru WooCommerce Plugin
 * Plugin URI: https://carts.guru/en?utm_source=woocommerce&utm_medium=plugin
 * Description: The first targeted and automated follow-up solution for abandoned carts through phone and text message!
 * Author: Carts Guru
 * Author URI: https://carts.guru/en?utm_source=woocommerce&utm_medium=plugin
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Version: 1.4.6
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (! class_exists('WC_Cartsguru')) :

/**
 * CartsGuru WooCommerce
 *
 * @package  CartsGuru WooCommerce Plugin
 * @category Main
 * @author Carts Guru
 */
class WC_Cartsguru
{

    /**
    * Construct the plugin.
    */
    public function __construct()
    {
        $locale = apply_filters('plugin_locale', get_locale(), 'cartsguru-woocommerce');
        load_textdomain('cartsguru-woocommerce', dirname(__FILE__) . '/languages/' . $locale . '.mo');
        load_plugin_textdomain('cartsguru-woocommerce', false, plugin_basename(dirname(__FILE__)) . '/languages');

        add_action('plugins_loaded', array( $this, 'init' ));
    }

    /**
    * Initialize the plugin.
    */
    public function init()
    {

        // Checks if WooCommerce is installed.
        if (class_exists('WC_Integration')) {
            // Check DB need update or not, register_activation_hook is not called during plugin update
            self::setup_tables();

            // Include our classes
            include_once(dirname(__FILE__) . '/classes/wc-cartsguru-utils.php');
            include_once(dirname(__FILE__) . '/classes/wc-cartsguru-remote-api.php');
            include_once(dirname(__FILE__) . '/classes/wc-cartsguru-data-adaptor.php');
            include_once(dirname(__FILE__) . '/classes/wc-cartsguru-event-handler.php');
            include_once(dirname(__FILE__) . '/classes/wc-cartsguru-integration.php');

            // Register the integration.
            add_filter('woocommerce_integrations', array($this, 'add_integration'));

            // Register events
            $eventHandler = WC_Cartsguru_Event_Handler::instance();
            $eventHandler->register_hooks();
        }
    }

    /**
     * Add a new integration to WooCommerce.
     */
    public function add_integration($integrations)
    {
        $integrations[] = 'WC_Cartsguru_Integration';
        return $integrations;
    }

    /**
    * Creates or update table to store carts data
    */
    public static function setup_tables()
    {
        $cartsguru_db_version = '1.0.0';
        $installed_ver = get_option('cartsguru_db_version');

        if ($installed_ver != $cartsguru_db_version) {
            $carts_table = WC_Cartsguru_Carts_Table::instance();
            $carts_table->setup();

            update_option('cartsguru_db_version', $cartsguru_db_version);
        }
    }

    /**
    * Removes data tables
    */
    public static function remove_tables()
    {
        $carts_table = WC_Cartsguru_Carts_Table::instance();
        $carts_table->remove();

        delete_option('cartsguru_db_version');
    }
}

$WC_Cartsguru = new WC_Cartsguru(__FILE__);
include_once(dirname(__FILE__) . '/classes/wc-cartsguru-carts-table.php');

// Create tables to store abandoned carts data
register_activation_hook(__FILE__, array('WC_Cartsguru', 'setup_tables'));

// Remove tables when plugin is uninstalled
register_uninstall_hook(__FILE__, array('WC_Cartsguru', 'remove_tables'));

endif;
