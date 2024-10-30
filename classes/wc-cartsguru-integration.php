<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * CartsGuru WooCommerce Integration
 *
 * @package  CartsGuru WooCommerce Plugin
 * @category Integration
 * @author Carts Guru
 */
class WC_Cartsguru_Integration extends WC_Integration
{
    public static function instance()
    {
        return new self();
    }

    public function __construct()
    {
        $this->id = 'cartsguru-settings';
        $this->method_title = __('Carts Guru', 'cartsguru-woocommerce');
        $this->method_description = __('Integration between Carts Guru and WooCommerce', 'cartsguru-woocommerce');

        $this->init_form_fields();
        $this->init_settings();

        $this->site_id = $this->get_option('site_id');
        $this->auth_key = $this->get_option('auth_key');
        $this->version = '1.4.5';

        add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
        ;
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'site_id' => array(
                'title' => __('Site ID', 'cartsguru-woocommerce'),
                'type' => 'text',
                'description' => __('Site ID identifies your site in Carts Guru', 'cartsguru-woocommerce'),
                'desc_tip' => true,
                'default' => ''
            ),
            'auth_key' => array(
                'title' => __('Auth Key', 'cartsguru-woocommerce'),
                'type' => 'text',
                'description' => __('Auth Key provided by Carts Guru to authorize data sent from WooCommerce to Carts Guru', 'cartsguru-woocommerce'),
                'desc_tip' => true,
                'default' => ''
            )
        );
    }

    public function process_admin_options()
    {
        parent::process_admin_options();

        $this->site_id = $this->get_option('site_id');
        $this->auth_key = $this->get_option('auth_key');

        $response = WC_Cartsguru_Remote_API::instance()->register_site($this->site_id, $this->auth_key);

        if (!$response || $response['response']['code'] != 200) {
            WC_Admin_Settings::add_error(__('Please check your parameters. The site id or auth key is invalid', 'cartsguru-woocommerce'));
            return false;
        }

        $body = json_decode($response['body']);

        if ($body->isNew) {
            $this->import_orders();
        }

        return true;
    }

    private function import_orders()
    {
        $query = new WP_Query(array(
            'post_type' => 'shop_order',
            'post_status' => array_keys(wc_get_order_statuses()),
            'posts_per_page' => 250
        ));
        $posts = $query->posts;

        $orders = array();
        $mapper = WC_Cartsguru_Data_Adaptor::instance();

        foreach ($posts as $post) {
            $order = $mapper->adapt_order($post->ID);
            $orders[] = $order;
        }

        WC_Cartsguru_Remote_API::instance()->send_orders($orders);
    }
}
