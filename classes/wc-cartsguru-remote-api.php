<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * CartsGuru WooCommerce Remote API
 *
 * @package  CartsGuru WooCommerce Plugin
 * @category Remote API
 * @author Carts Guru
 */
class WC_Cartsguru_Remote_API
{
    protected static $_instance;

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function get_host()
    {
        return 'https://api.carts.guru';
    }

    private function post_request($url, array $data, $auth_key = null, $isSync = false)
    {
        if (!$auth_key) {
            $auth_key = WC_Cartsguru_Integration::instance()->auth_key;
        }
        
        if (!$auth_key) {
            return null;
        }

        $args = array(
            'timeout' => $isSync ? 30 : 2,  //We need only wait if is sync, seconds as integer
            'body' => json_encode($data)
        );

        $args['headers'] = array(
            'Content-type' => 'application/json'
        );
        if (!is_null($auth_key)) {
            $args['headers']['x-auth-key'] = $auth_key;
        }

        return wp_remote_post($url, $args);
    }

    public function register_site($site_id, $auth_key = null)
    {
        $url = $this->get_host();
        $url.= '/sites/' . $site_id . '/register-plugin';
        $data = array(
            'plugin' => 'woocommerce',
            'pluginVersion' => WC_Cartsguru_Integration::instance()->version,
            'storeVersion' => WC_Cartsguru_Utils::instance()->get_woocommerce_version(),
            'adminUrl' => get_site_url() . '/?cartsguru_admin_action='
        );
        $response = $this->post_request($url, $data, $auth_key, true);

        if (is_null($response) or is_wp_error($response)) {
            return false;
        } else {
            return $response;
        }
    }

    protected function is_cart_qualified($cart)
    {
        return (filter_var($cart['email'], FILTER_VALIDATE_EMAIL) or trim($cart['phoneNumber']) != '');
    }

    // Remove unwanted props
    protected function clean_cart($cart)
    {
        foreach ($cart['items'] as &$item) {
            if (array_key_exists('variation_id', $item)) {
                unset($item['variation_id']);
            }
            if (array_key_exists('variation', $item)) {
                unset($item['variation']);
            }
        }
        return $cart;
    }

    public function send_cart($cart)
    {
        $url = $this->get_host().'/carts';

        if ($this->is_cart_qualified($cart)) {
            // Clean items
            foreach ($cart['items'] as &$item) {
              if (isset($item['composite_data'])) {
                unset($item['composite_data']);
              }
            }
            $this->post_request($url, $this->clean_cart($cart));
        }
    }

    public function send_order($order)
    {
        $url = $this->get_host().'/orders';
        $this->post_request($url, $order);
    }

    public function send_orders($orders)
    {
        if (sizeof($orders) == 0) {
            return;
        }

        $url = $this->get_host().'/import/orders';
        $this->post_request($url, $orders);
    }
}
