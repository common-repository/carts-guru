<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * CartsGuru WooCommerce Utilities
 *
 * @package  CartsGuru WooCommerce Plugin
 * @category Utilities
 * @author Carts Guru
 */
class WC_Cartsguru_Utils
{
    protected static $_instance;
    protected $logger;

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function get_remote_ip()
    {
        $values = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'CF-Connecting-IP',
            'REMOTE_ADDR'
        );
        foreach ($values as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        return $ip;
                    }
                }
            }
        }

        return null;
    }

    public function get_woocommerce_version()
    {
        return WooCommerce::instance()->version;
    }

    public static function is_woocommerce_3()
    {
        return version_compare(WooCommerce::instance()->version, '3.0.0', '>=');
    }

    public function log($message)
    {
        if (!$this->logger) {
            $this->logger = new WC_Logger();
        }
        $this->logger->add('cartsguru', $message);
    }

        // Checks if customer made any orders previously
    public function is_new_customer($email)
    {
        global $wpdb;
        return $wpdb->get_var(
                $wpdb->prepare("
					SELECT COUNT(DISTINCT posts.id)
					FROM {$wpdb->posts} as posts
					LEFT JOIN {$wpdb->postmeta} AS postmeta ON posts.id = postmeta.post_id
					WHERE
						posts.post_status IN ( 'wc-completed', 'wc-processing' ) AND
						postmeta.meta_key IN ( '_billing_email', '_customer_user' ) AND
						postmeta.meta_value  = %s
					", $email
                )
            ) == 0;
    }

    // Get customer language from browser
    public function get_browser_language()
    {
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            foreach (explode(",", strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'])) as $accept) {
                if (preg_match("!([a-z-]+)(;q=([0-9\\.]+))?!", trim($accept), $found)) {
                    $langs[] = $found[1];
                    $quality[] = (isset($found[3]) ? (float) $found[3] : 1.0);
                }
            }
            // Order the codes by quality
            array_multisort($quality, SORT_NUMERIC, SORT_DESC, $langs);
            // iterate through languages found in the accept-language header
            foreach ($langs as $lang) {
                $lang = substr($lang, 0, 2);
                return $lang;
            }
        }
        return null;
    }
}
