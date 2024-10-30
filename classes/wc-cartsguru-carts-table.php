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
class WC_Cartsguru_Carts_Table
{
    protected static $_instance;
    protected $name = 'cartsguru_carts';

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function get_name()
    {
        global $wpdb;
        return $wpdb->prefix . $this->name;
    }

    private function generateUUID()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

        // 32 bits for "time_low"
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),

        // 16 bits for "time_mid"
        mt_rand(0, 0xffff),

        // 16 bits for "time_hi_and_version",
        // four most significant bits holds version number 4
        mt_rand(0, 0x0fff) | 0x4000,

        // 16 bits, 8 bits for "clk_seq_hi_res",
        // 8 bits for "clk_seq_low",
        // two most significant bits holds zero and one for variant DCE1.1
        mt_rand(0, 0x3fff) | 0x8000,

        // 48 bits for "node"
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
      );
    }

    public function setup()
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $table_name = $this->get_name();
        $sql = "CREATE TABLE $table_name (
					id int(11) NOT NULL AUTO_INCREMENT,
					cart_id VARCHAR(64) NOT NULL,
					cart_details LONGTEXT NOT NULL,
					token VARCHAR(64),
					order_id VARCHAR(64),
					created int(11) NOT NULL,
					last_modified int(11) NOT NULL,
					UNIQUE KEY id (id),
					PRIMARY KEY cart_id (cart_id)
					) DEFAULT CHARACTER SET utf8;";

        dbDelta($sql);

                //Update old records
                global $wpdb;
        $updateQuery= "UPDATE  $table_name SET cart_id = id WHERE cart_id = ''";
        $wpdb->query($updateQuery);
    }

    public function remove()
    {
        global $wpdb;

        $table_name = $this->get_name();
        $sql = "DROP TABLE " . $table_name;
        $wpdb->query($sql);
    }

    public function insert_cart($data)
    {
        global $wpdb;
        $table_name = $this->get_name();
        $current_time = current_time('timestamp');
        $token = $this->generateUUID();
        $is_inserted = $wpdb->insert($table_name, array('cart_details' => serialize($data), 'cart_id' => $token, 'token' => $token, 'created' => $current_time, 'last_modified' => $current_time));
        if (!$is_inserted) {
            return null;
        }
        $cart_id = current_time('Ymd') . str_pad($wpdb->insert_id, 5, 0, STR_PAD_LEFT);
        $wpdb->update($table_name, array('cart_id' => $cart_id), array('id' => $wpdb->insert_id));

        return $cart_id;
    }

    public function update_cart($cart_id, $data)
    {
        global $wpdb;
        $table_name = $this->get_name();
        $current_time = current_time('timestamp');
        $wpdb->update($table_name, array('cart_details' => serialize($data), 'last_modified' => $current_time), array('cart_id' => $cart_id));
    }

    public function set_order($cart_id, $order_id)
    {
        global $wpdb;
        $table_name = $this->get_name();
        $cart = $this->get_cart($cart_id);
        if ($cart) {
            $wpdb->update($table_name, array('order_id' => $order_id), array('cart_id' => $cart_id));
        }
    }

    public function get_cart_token($cart_id)
    {
        global $wpdb;
        $table_name = $this->get_name();
        $cart = $this->get_cart($cart_id);
        return $cart->token;
    }

    public function load_cart_if_exists($cart_id, $token)
    {
        global $wpdb;
        $table_name = $this->get_name();
        $cart = $wpdb->get_results("SELECT * FROM $table_name WHERE cart_id='$cart_id' AND token='$token' AND order_id is NULL");
        if (count($cart) > 0) {
            return $cart[0];
        }
        return false;
    }

    public function get_cart($cart_id)
    {
        global $wpdb;
        $table_name = $this->get_name();
        $cart = $wpdb->get_results("SELECT * FROM $table_name WHERE cart_id='$cart_id'");
        if (count($cart) > 0) {
            return $cart[0];
        }
        return null;
    }
}
