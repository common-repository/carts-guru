<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}


/**
 * CartsGuru WooCommerce Data Adaptor
 *
 * @package  CartsGuru WooCommerce Plugin
 * @category Data Adaptor
 * @author Carts Guru
 */
class WC_Cartsguru_Data_Adaptor
{
    protected static $_instance;

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function extract_image_url($id)
    {
        if ($id) {
            $product_image = wp_get_attachment_image_src($id);
            if (!empty($product_image)) {
                return $product_image[0];
            }
        }
        return '';
    }

    public function get_customer_from_post_data($post_data)
    {
        //$post_data is like billing_first_name=fx&billing_last_name=deom&billing_company=&billing_email=test1%40demo.com&billing_phone=0320000000&

        // Parsing data
        $post = array();
        $vars = explode('&', $post_data);
        foreach ($vars as $k => $value) {
            $v = explode('=', urldecode($value));
            $post[$v[0]] = $v[1];
        }

        return $this->get_customer_from_checkout_data($post);
    }

    public function get_customer_from_checkout_data($checkout_data)
    {
        return array(
            'first_name' => $checkout_data['billing_first_name'],
            'last_name' => $checkout_data['billing_last_name'],
            'email' => $checkout_data['billing_email'],
            'phone' => $checkout_data['billing_phone'],
            'country' => $checkout_data['billing_country']
        );
    }

    public static function adapt_WP_Term($term)
    {
        return $term->name;
    }

    public function adapt_cart($cart, $customer)
    {
        $is_woocommerce_3 = WC_Cartsguru_Utils::is_woocommerce_3();
        $totalET = 0;
        $totalATI = 0;
        $items = array();
        foreach ($cart->cart_contents as $item) {
            // Skip composite children
            if (isset($item['composite_data']) && isset($item['composite_parent'])) {
                continue;
            }

            $isProductVariation = array_key_exists('variation_id', $item);

            $product = $item['data'];
            $categories = $is_woocommerce_3 ?
                         array_reverse(array_map(array(__CLASS__,'adapt_WP_Term'), wc_get_product_terms($item['product_id'], 'product_cat', array( 'orderby' => 'parent', 'order' => 'DESC' )))):
                         explode(",", strip_tags($product->get_categories()));

            $cartItem = array(
                'id' => (string)$item['product_id'],
                'label' => (string) ($is_woocommerce_3 ? $product->get_name() : $product->post->post_title),
                'quantity' => (int)$item['quantity'],
                'totalET' =>  (float) ($is_woocommerce_3 ? wc_get_price_excluding_tax($product, array('qty' => $item['quantity'])) : $product->get_price_excluding_tax($item['quantity'])),
                'totalATI' => (float) ($is_woocommerce_3 ? wc_get_price_including_tax($product, array('qty' => $item['quantity'])) : $product->get_price_including_tax($item['quantity'])),
                'url' => $product->get_permalink(),
                'imageUrl' => (string)$this->extract_image_url($product->get_image_id()),
                'universe' => sizeof($categories) > 0 ? $categories[0] : '',
                'category' => sizeof($categories) > 1 ? $categories[sizeof($categories)-1] : '',
                'variation_id' => $isProductVariation ? $item['variation_id'] : 0,
                'variation' => $isProductVariation ? $item['variation'] : array()
            );

      			// Check if composite product
      			if (isset($item['composite_data']) && isset($item['composite_children'])) {
      				$cartItem['composite_data'] = $item['composite_data'];
      			}

            $items[] = $cartItem;
            $totalET += $cartItem['totalET'];
            $totalATI += $cartItem['totalATI'];
        }

        $date = new DateTime(null, new DateTimeZone('UTC'));

        // Custom fields
        $custom = array(
                'isNewCustomer' => WC_Cartsguru_Utils::instance()->is_new_customer((string)$customer['email']),
                'language' => WC_Cartsguru_Utils::instance()->get_browser_language()
        );

        $data = array(
            'siteId' => WC_Cartsguru_Integration::instance()->site_id,
            'creationDate' => $date->format('c'),
            'totalET' => $totalET,
            'totalATI' => $totalATI,
            'currency' => get_woocommerce_currency(),
            'ip' => WC_Cartsguru_Utils::instance()->get_remote_ip(),
            'accountId' => (string)$customer['email'],
            'lastname' => (string)$customer['last_name'],
            'firstname' => (string)$customer['first_name'],
            'email' => (string)$customer['email'],
            'phoneNumber' => (string)$customer['phone'],
            'countryCode' => (string)$customer['country'],
            'items' => $items,
            'custom' => $custom
        );

        return $data;
    }

    private function _legacy_adapt_order($orderId)
    {
      $order = new WC_Order($orderId);
      $post = get_post($orderId);

      $items = array();
      foreach ($order->get_items() as $item) {
          $product = new WC_Product($item['product_id']);
          $categories = explode(",", strip_tags($product->get_categories()));

          $item = array(
              'id' => (string)$item['product_id'],
              'label' => $item['name'],
              'quantity' => (int)$item['qty'],
              'totalET' => (float)$item['line_total'],
              'totalATI' => (float)$item['line_total'] + (float)$item['line_tax'] ,
              'url' => $product->get_permalink(),
              'imageUrl' => (string)$this->extract_image_url($product->get_image_id()),
              'universe' => sizeof($categories) > 0 ? $categories[0] : '',
              'category' => sizeof($categories) > 1 ? $categories[sizeof($categories)-1] : '',
          );

          $items[] = $item;
      }

      $date = new DateTime($post->post_date_gmt, new DateTimeZone('UTC'));
      $totalET = (float)($order->get_subtotal() - $order->get_total_discount());

      // Custom fields
      $custom = array(
              'isNewCustomer' => WC_Cartsguru_Utils::instance()->is_new_customer($order->billing_email),
              'language' => WC_Cartsguru_Utils::instance()->get_browser_language()
      );

      $data = array(
          'siteId' => WC_Cartsguru_Integration::instance()->site_id,
          'id' => (string)$orderId,
          'state' => $order->post_status,
          'creationDate' => $date->format('c'),
          'totalATI' => (float)$order->get_total(),
          'totalET' => $totalET,
          'currency' => (string)$order->get_order_currency(),
          'paymentMethod' => $order->payment_method_title,
          'accountId' => $order->billing_email,
          'lastname' => $order->billing_last_name,
          'firstname' => $order->billing_first_name,
          'email' => $order->billing_email,
          'phoneNumber' => $order->billing_phone,
          'countryCode' =>  $order->billing_country,
          'items' => $items,
          'custom' => $custom
      );

      return $data;
    }

    public function adapt_order($orderId)
    {
        if (!WC_Cartsguru_Utils::is_woocommerce_3()){
          return $this->_legacy_adapt_order($orderId);
        }

        $order = new WC_Order($orderId);

        $items = array();
        foreach ($order->get_items() as $item) {
            $product = new WC_Product($item['product_id']);
            $categories = array_reverse(array_map(array(__CLASS__,'adapt_WP_Term'), wc_get_product_terms($item['product_id'], 'product_cat', array( 'orderby' => 'parent', 'order' => 'DESC' ))));

            $item = array(
                'id' => (string)$item['product_id'],
                'label' => $item['name'],
                'quantity' => (int)$item['qty'],
                'totalET' => (float)$item['line_total'],
                'totalATI' => (float)$item['line_total'] + (float)$item['line_tax'] ,
                'url' => $product->get_permalink(),
                'imageUrl' => (string)$this->extract_image_url($product->get_image_id()),
                'universe' => sizeof($categories) > 0 ? $categories[0] : '',
                'category' => sizeof($categories) > 1 ? $categories[sizeof($categories)-1] : '',
            );

            $items[] = $item;
        }

        $data = array(
            'siteId' => WC_Cartsguru_Integration::instance()->site_id,
            'id' => (string)$orderId,
            'state' => $order->get_status(),
            'creationDate' => $order->get_date_created()->date('c'),
            'totalATI' => (float)$order->get_total(),
            'totalET' => (float)($order->get_subtotal() - $order->get_total_discount()),
            'currency' => (string)$order->get_order_currency(),
            'paymentMethod' => $order->get_payment_method_title(),
            'accountId' => $order->get_billing_email(),
            'lastname' => $order->get_billing_last_name(),
            'firstname' => $order->get_billing_first_name(),
            'email' => $order->get_billing_email(),
            'phoneNumber' => $order->get_billing_phone(),
            'countryCode' =>  $order->get_billing_country(),
            'items' => $items,
            'custom' => array(
              'isNewCustomer' => WC_Cartsguru_Utils::instance()->is_new_customer($order->billing_email),
              'language' => WC_Cartsguru_Utils::instance()->get_browser_language()
            )
        );

        return $data;
    }

    public function create_coupons($data) {
      if (is_array($data)) {
        foreach ($data['coupons'] as $coupon){
          $code = $coupon['code'];
          $coupon_code = $code; // Code
          if($this->get_coupon_by_code($coupon_code)){
            continue;
          }
          $amount = $data['freeShipping'] ? 0 : $data['reductionPercent']; // Amount
          $discount_type = 'percent';

          $post = array(
            'post_title' => strtoupper($coupon_code),
            'post_excerpt' => 'Carts Guru generated rule',
            'post_status' => 'publish',
            'post_author' => 1,
            'post_type'		=> 'shop_coupon'
          );

          $new_coupon_id = wp_insert_post($post);
          // add meta like this - correct way in WC, based on their documentation
          update_post_meta( $new_coupon_id, 'discount_type', $discount_type);
          update_post_meta( $new_coupon_id, 'coupon_amount', $amount);
          update_post_meta( $new_coupon_id, 'individual_use', 'no');
          update_post_meta( $new_coupon_id, 'product_ids', '' );
          update_post_meta( $new_coupon_id, 'exclude_product_ids', '');
          update_post_meta( $new_coupon_id, 'usage_limit', '' );
          update_post_meta( $new_coupon_id, 'expiry_date', $coupon['expirationDate']);
          update_post_meta( $new_coupon_id, 'apply_after_tax', 'yes');
          update_post_meta( $new_coupon_id, 'free_shipping', $data['freeShipping'] ? 'yes' : 'no');
        }
        return true;
      }
      return false;
    }

    public function get_coupons() {
      $args = array(
        'post_type'		=> 'shop_coupon',
        'discount_type' => 'percent'
      );
      $coupons = get_posts( $args );
      $coupon_names = array();
      foreach ( $coupons as $coupon ) {
        // Get the name for each coupon post
        $coupon_names[] = array (
        'title' => $coupon->post_title,
        'expirationDate' => $coupon->expiry_date,
        'freeShipping' => (boolean)$coupon->free_shipping,
        'reductionPercent' => (float)$coupon->coupon_amount
        );
      }
      return $coupon_names;
    }

    public function get_coupon_by_code($coupon_code) {
      $args = array(
        'post_type'		=> 'shop_coupon',
        'discount_type' => 'percent'
      );
      $coupons = get_posts($args);
      foreach ($coupons as $coupon) {
        // Get the name for each coupon post
        if ($coupon->post_title === $coupon_code) {
          return $coupon->post_title;
        }
      }
      return false;
    }

    public function delete_coupons($data) {
      if (!is_array($data)) {
        return false;
      }
        foreach ($data['couponCodes'] as $coupon){
          $coupon_data = new WC_Coupon($coupon);
          if(!empty($coupon_data->id)){
            wp_delete_post($coupon_data->id, true);
          }
      }
      return true;
    }
  }
