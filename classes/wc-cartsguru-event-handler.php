<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * CartsGuru WooCommerce Event Handler
 *
 * @package  CartsGuru WooCommerce Plugin
 * @category Handlers
 * @author Carts Guru
 */
class WC_Cartsguru_Event_Handler
{
    protected static $_instance;

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function register_hooks()
    {
        add_action('woocommerce_checkout_update_order_review', array($this, 'handle_update_order_review'));
        add_action('woocommerce_cart_updated', array($this, 'handle_cart_updated'));
        add_action('woocommerce_before_checkout_process', array($this, 'handle_before_checkout_process'));
        add_action('woocommerce_thankyou', array($this, 'handle_thankyou'));
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed'));
        add_filter('query_vars', array($this, 'custom_query_vars'));
        add_action('parse_request', array($this, 'parse_request'));
        add_action('wp_head', array($this, 'display_head'));
        add_action('init', array($this, 'source_cookie'));
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'ajax_added_to_cart'), 10, 2);
        add_action( 'woocommerce_update_cart_action_cart_updated', array($this, 'on_action_cart_updated'), 20, 1 );
    }

    public function set_cart_id_into_session($id)
    {
        WooCommerce::instance()->session->set('cartsguru_woocommerce_cart_id', (string)$id);
    }

    public function get_cart_id_from_session()
    {
        return WooCommerce::instance()->session->get('cartsguru_woocommerce_cart_id');
    }

    private function remove_cart_id_from_sesion()
    {
        WooCommerce::instance()->session->set('cartsguru_woocommerce_cart_id', null);
    }

    private function get_cart()
    {
        $cart_id = $this->get_cart_id_from_session();
        if ($cart_id) {
            return WC_Cartsguru_Carts_Table::instance()->get_cart($cart_id);
        }
        return null;
    }

    private function handle_checkout($customer)
    {
        $cart = WooCommerce::instance()->cart;
        $data = WC_Cartsguru_Data_Adaptor::instance()->adapt_cart($cart, $customer);

        $cart_id = $this->get_cart_id_from_session();
        // Save or update the cart
        if ($cart_id) {
            WC_Cartsguru_Carts_Table::instance()->update_cart($cart_id, $data);
        } else {
            $cart_id = WC_Cartsguru_Carts_Table::instance()->insert_cart($data);
            if ($cart_id == null) {
                return;
            }
            $this->set_cart_id_into_session($cart_id);
        }

        if (!$data['email'] && !$data['phoneNumber']) {
            return;
        }

        $data['id'] = (string)$cart_id;
        // Set recover URL
        $token = WC_Cartsguru_Carts_Table::instance()->get_cart_token($cart_id);
        $data['recoverUrl'] = get_site_url() . '/?cartsguru_action=recover-cart&cartsguru_cart_id=' . $cart_id . '&cartsguru_cart_token=' . $token;
		// Send cart
        WC_Cartsguru_Remote_API::instance()->send_cart($data);
    }

    private function handle_order($orderId)
    {
        $order = WC_Cartsguru_Data_Adaptor::instance()->adapt_order($orderId);
        // Check source cookie
        if (isset($_COOKIE['cartsguru-source'])) {
            $order['source'] = json_decode($_COOKIE['cartsguru-source'], true);
            unset($_COOKIE['cartsguru-source']);
            setcookie('cartsguru-source', '', time() - (15 * 60), '/');
        }
        WC_Cartsguru_Remote_API::instance()->send_order($order);
    }

    public function handle_update_order_review($data)
    {
        //$data is equal to $_POST['post_data']
        WC_Cartsguru_Utils::instance()->log('handle_update_order_review');

        $customer = WC_Cartsguru_Data_Adaptor::instance()->get_customer_from_post_data($_POST['post_data']);

        $this->handle_checkout($customer);
    }

    public function handle_cart_updated()
    {
        global $woocommerce;
        WC_Cartsguru_Utils::instance()->log('handle_cart_updated');

        $oldCart = $this->get_cart();
        $customer = array();

        if ($oldCart) {
            $oldCart = unserialize($oldCart->cart_details);
            $customer = array(
                'first_name' => $oldCart['firstname'],
                'last_name' => $oldCart['lastname'],
                'email' => $oldCart['email'],
                'phone' => $oldCart['phoneNumber'],
                'country' => $oldCart['countryCode']
            );
        }

        $this->handle_checkout($customer);
    }

    public function handle_before_checkout_process()
    {
        WC_Cartsguru_Utils::instance()->log('handle_before_checkout_process');

        $customer =  WC_Cartsguru_Data_Adaptor::instance()->get_customer_from_checkout_data($_REQUEST);

        $this->handle_checkout($customer);
    }

    public function handle_thankyou($orderId)
    {
        WC_Cartsguru_Utils::instance()->log('handle_thankyou ' . $orderId);
        $cart_id = $this->get_cart_id_from_session();
        // Set orde on saved Cart
        WC_Cartsguru_Carts_Table::instance()->set_order($cart_id, $orderId);
        $this->remove_cart_id_from_sesion();
    }

    public function handle_order_status_changed($orderId)
    {
        WC_Cartsguru_Utils::instance()->log('handle_order_status_changed ' . $orderId);
        $this->handle_order($orderId);
    }

    // Custom query for route
    public function custom_query_vars($vars)
    {
        $vars[] = 'cartsguru_action';
        $vars[] = 'cartsguru_cart_id';
        $vars[] = 'cartsguru_cart_token';
        $vars[] = 'cartsguru_cart_discount';
        $vars[] = 'cartsguru_admin_action';
        $vars[] = 'cartsguru_admin_data';
        $vars[] = 'cartsguru_auth_key';
        $vars[] = 'cartsguru_catalog_offset';
        $vars[] = 'cartsguru_catalog_limit';
        return $vars;
    }

    // Fire when custom route is matched
    public function parse_request($wp)
    {
        $valid_actions = array('recover-cart', 'fb-catalog');
        if (!empty($wp->query_vars['cartsguru_action']) && in_array($wp->query_vars['cartsguru_action'], $valid_actions)) {
            // Recover cart call
            if ($wp->query_vars['cartsguru_action'] == 'recover-cart' && isset($wp->query_vars['cartsguru_cart_id']) && isset($wp->query_vars['cartsguru_cart_token'])) {
                // Recover cart route called
                global $woocommerce;
                $cart_id = $wp->query_vars['cartsguru_cart_id'];
                $cart_token = $wp->query_vars['cartsguru_cart_token'];
                $cart = WC_Cartsguru_Carts_Table::instance()->load_cart_if_exists($cart_id, $cart_token);

                if ($cart) {
                    $cart_details = $cart = unserialize($cart->cart_details);
                    // Empty cart
                    $woocommerce->cart->empty_cart();
                    // Add products to cart
                    $this->set_cart_id_into_session($cart_id);
                    foreach ($cart_details['items'] as $product) {
                        if (isset($product['composite_data'])) {
                            $woocommerce->cart->add_to_cart($product['id'], $product['quantity'], 0, array(), array('composite_data' => $product['composite_data']));
                        } else {
                            $woocommerce->cart->add_to_cart($product['id'], $product['quantity'], $product['variation_id'], $product['variation']);
                        }
                    }
                }
                if (isset($wp->query_vars['cartsguru_cart_discount'])) {
                  $cart_discount = $wp->query_vars['cartsguru_cart_discount'];
                  if ($woocommerce->cart->get_coupons()) {
                    $woocommerce->cart->remove_coupons();
                  }
                  $cartsguru_coupon = WC_Cartsguru_Data_Adaptor::instance()->get_coupon_by_code($cart_discount);
                  if (!$woocommerce->cart->add_discount(sanitize_text_field($cartsguru_coupon))) {
                    $woocommerce->show_messages();
                  }
                }
                $redirect_url = wc_get_page_permalink('cart');

                $queryParams = array();
                $pairs = explode('&', $_SERVER['QUERY_STRING']);
                foreach ($pairs as $pair) {
                    //Remove cartsguru_* keys
                    if (strpos($pair, 'cartsguru_') === 0) {
                        continue;
                    }
                    $queryParams[] = $pair;
                }

                //Concats query
                if (!empty($queryParams)) {
                    $redirect_url .= strpos($redirect_url, '?') !== false ? '&' : '?';
                    $redirect_url .= implode('&', $queryParams);
                }


                wp_safe_redirect($redirect_url);
            }
            // Catalog request
            if ($wp->query_vars['cartsguru_action'] == 'fb-catalog' && !empty($wp->query_vars['cartsguru_auth_key']) && WC_Cartsguru_Integration::instance()->auth_key === $wp->query_vars['cartsguru_auth_key']) {
                $offset = !empty($wp->query_vars['cartsguru_catalog_offset']) ? $wp->query_vars['cartsguru_catalog_offset'] : 0;
                $limit = !empty($wp->query_vars['cartsguru_catalog_limit']) ? $wp->query_vars['cartsguru_catalog_limit'] : 50;
                $processed_products = array();

                $args = array(
                    'post_type' => 'product',
                    'posts_per_page' => $limit,
                    'offset' => $offset
                );

                $loop = new WP_Query($args);
                while ($loop->have_posts()) {
                    $loop->the_post();
                    $id = get_the_ID();
                        // First try the excerpt
                        $content = get_the_excerpt();
                        // Then content
                        if ($content === '') {
                            $content = get_the_content();
                        }
                        // And resolve to title if none
                        if ($content === '') {
                            $content = $product->get_title();
                        }
                    $product = new WC_Product($id);
                    $img_url = wp_get_attachment_image_src(get_post_thumbnail_id($id), 'large');

                    $data = array(
                            'id' => $id,
                            'title' => $product->get_title(),
                            'description' => $content,
                            'price' => number_format(floatval($product->get_price()), 2, '.', ''),
                            'link' => $product->get_permalink(),
                            'image_link' => $img_url[0],
                            'availability' => ($product->is_in_stock() ? 'in stock' : 'out of stock')
                        );
                    $processed_products[] = $data;
                }
                    // Get product total
                    $args = array(
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'posts_per_page' => -1
                    );
                $products_count = new WP_Query($args);
                    // Send response
                    header('Content-Type: application/json');
                echo json_encode(
                        array(
                            'url' => get_site_url(),
                            'store_name' => get_bloginfo('name'),
                            'products' => $processed_products,
                            'total' => $products_count->found_posts
                        )
                    );
            }
            die;
        }
        $valid_admin_actions = array('toggleFeatures', 'displayConfig', 'createCoupons', 'deleteCoupons', 'getCoupons');
        // Check if admin action
        if (!empty($wp->query_vars['cartsguru_admin_action']) &&
            in_array($wp->query_vars['cartsguru_admin_action'], $valid_admin_actions) &&
            isset($wp->query_vars['cartsguru_auth_key']) &&
            $wp->query_vars['cartsguru_auth_key'] === WC_Cartsguru_Integration::instance()->auth_key) {
            // Toggle features action
            if ($wp->query_vars['cartsguru_admin_action'] == 'toggleFeatures' && isset($wp->query_vars['cartsguru_admin_data'])) {
                $data = json_decode(stripcslashes($wp->query_vars['cartsguru_admin_data']), true);
                if (is_array($data)) {
                    $response = array();

                    // Toggle facebook Display
                    if (array_key_exists('facebook', $data)) {
                        if ($data['facebook'] == true) {
                            update_option('carts_guru_feature_facebook', true);

                            $response['catalogUrl'] = get_site_url() . '/?cartsguru_action=fb-catalog';
                            $response['CARTSG_FEATURE_FB'] = true;
                        } elseif ($data['facebook'] == false) {
                            delete_option('carts_guru_feature_facebook');

                            $response['CARTSG_FEATURE_FB'] = false;
                        }
                    }

                    // Toggle facebook messenger
                    if (array_key_exists('fbm', $data)) {
                        if ($data['fbm'] == true) {
                            update_option('carts_guru_feature_fbm', true);

                            $response['feature_fbm'] = true;
                        } elseif ($data['fbm'] == false) {
                            delete_option('carts_guru_feature_fbm');

                            $response['feature_fbm'] = false;
                        }
                    }

                    // Toggle CI
                    if (array_key_exists('ci', $data)) {
                        if ($data['ci'] == true) {
                            update_option('carts_guru_feature_ci', true);

                            $response['feature_ci'] = true;
                        } elseif ($data['ci'] == false) {
                            delete_option('carts_guru_feature_ci');

                            $response['feature_ci'] = false;
                        }
                    }

                    if (array_key_exists('catalogId', $data)) {
                        update_option('carts_guru_facebook_catalogId', sanitize_text_field($data['catalogId']));
                    }

                    if (array_key_exists('pixel', $data)) {
                        update_option('carts_guru_facebook_pixel', sanitize_text_field($data['pixel']));
                    }

                    if (array_key_exists('trackerUrl', $data)) {
                        update_option('carts_guru_tracker_url', sanitize_text_field($data['trackerUrl']));
                    }

                    if (array_key_exists('pageId', $data)) {
                        update_option('carts_guru_facebook_pageId', sanitize_text_field($data['pageId']));
                    }

                    if (array_key_exists('appId', $data)) {
                        update_option('carts_guru_facebook_appId', sanitize_text_field($data['appId']));
                    }

                    // Toogle widgets
                    if (array_key_exists('widgets', $data) && is_array($data['widgets'])) {
                        update_option('carts_guru_feature_widgets', json_encode($data['widgets']));

                        $response['CARTSG_WIDGETS'] = $data['widgets'];
                    }

                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($response);
                }
            }

            // Dislay config action
            if ($wp->query_vars['cartsguru_admin_action'] == 'displayConfig') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array(
                    'CARTSG_SITE_ID' => WC_Cartsguru_Integration::instance()->site_id,
                    'CARTSG_FEATURE_FB' => get_option('carts_guru_feature_facebook'),
                    'CARTSG_FB_PIXEL' => get_option('carts_guru_facebook_pixel'),
                    'CARTSG_FB_CATALOGID' => get_option('carts_guru_facebook_catalogId'),
                    'CARTSG_FEATURE_FBM' => get_option('carts_guru_feature_fbm'),
                    'CARTSG_FB_PAGEID' => get_option('carts_guru_facebook_pageId'),
                    'CARTSG_FB_APPID' => get_option('carts_guru_facebook_appId'),
                    'CARTSG_TRACKERURL' => get_option('carts_guru_tracker_url'),
                    'CARTSG_FEATURE_CI' => get_option('carts_guru_feature_ci'),
                    'CARTSG_WIDGETS' => get_option('carts_guru_feature_widgets'),
                    'PLUGIN_VERSION'=> WC_Cartsguru_Integration::instance()->version
                ));
            }

            
            $cartsguru_admin_action = $wp->query_vars['cartsguru_admin_action'];
            $data = json_decode(stripcslashes($wp->query_vars['cartsguru_admin_data']), true);
            switch ($cartsguru_admin_action) {
              case 'getCoupons':
                $result = WC_Cartsguru_Data_Adaptor::instance()->get_coupons($data);
                break;
              case 'createCoupons':
                $result = WC_Cartsguru_Data_Adaptor::instance()->create_coupons($data);
                break;
              case 'deleteCoupons':
                $result = WC_Cartsguru_Data_Adaptor::instance()->delete_coupons($data);
                break;
              }

            if ($result) {
              header('Content-Type: application/json; charset=utf-8');
              echo json_encode($result);
            }
            exit;
        }
    }

    // Remove unwanted props
    protected function clean_cart($cart)
    {   
        if (array_key_exists('accountId', $cart) && $cart['accountId'] == "") {
            unset($cart['accountId']);
        }

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

    // Fires on every page
    public function display_head()
    {
      global $post, $woocommerce;

      $siteId = WC_Cartsguru_Integration::instance()->site_id;
      $facebook = get_option('carts_guru_feature_facebook');
      $pixel = get_option('carts_guru_facebook_pixel');
      $catalogId = get_option('carts_guru_facebook_catalogId');
      $fbm = get_option('carts_guru_feature_fbm');
      $ci = get_option('carts_guru_feature_ci');
      $pageId = get_option('carts_guru_facebook_pageId');
      $appId = get_option('carts_guru_facebook_appId');
      $trackerUrl = get_option('carts_guru_tracker_url');
      $widgets = get_option('carts_guru_feature_widgets');
      $widgets = $widgets ? $widgets : '[]';
      $cart = WC_Cartsguru_Data_Adaptor::instance()->adapt_cart(WooCommerce::instance()->cart, null);
      $cart['cartId'] = $this->get_cart_id_from_session();

      // Set recover URL
      if ($cart['cartId']) {
        $token = WC_Cartsguru_Carts_Table::instance()->get_cart_token($cart['cartId']);
        $cart['recoverUrl'] = get_site_url() . '/?cartsguru_action=recover-cart&cartsguru_cart_id=' . $cart['cartId'] . '&cartsguru_cart_token=' . $token;
      }

      $cart = $this->clean_cart($cart);

      $output = '';
      // Display on checkout page
      if (is_checkout()) {
        $output .= load_template(dirname(__FILE__) . '/../templates/checkout.php');
      }

      if ($facebook && $pixel && $catalogId) {
        $output .= load_template(dirname(__FILE__) . '/../templates/pixel.php');
        $output .= "fbq('init', '" . $pixel . "'); fbq('track', 'PageView');";

        // Product page
        if (is_product()) {
          $id = $post->ID;
          $product = new WC_Product($id);
          $output .= "fbq('track', 'ViewContent', {
            content_ids: ['" . $id . "'],
            content_type: 'product',
            value: " . number_format(floatval($product->get_price()), 2, '.', '') . ",
            currency: '" . get_woocommerce_currency() . "',
            product_catalog_id: " .$catalogId. "
          });";
        }

        // Add to Cart
        if (isset($_REQUEST['add-to-cart'])) {
          $id =  sanitize_text_field($_REQUEST['add-to-cart']);
          $product = new WC_Product($id);
          $output .= "fbq('track', 'AddToCart', {
            content_ids: ['" . $id . "'],
            content_type: 'product',
            value: " . number_format(floatval($product->get_price()), 2, '.', '') . ",
            currency: '" . get_woocommerce_currency() . "',
            product_catalog_id: " .$catalogId. "
          });";
        }

        // Checkout
        if (is_wc_endpoint_url('order-received')) {
          $order_key = sanitize_text_field($_REQUEST['key']);
          $order_id = wc_get_order_id_by_order_key($order_key);
          if ($order_id) {
            $order = new WC_Order($order_id);
            $items = $order->get_items('line_item');
            $ids = array();

            foreach ($items as $item) {
              $ids[] = $item['product_id'];
            }
            $output .= "fbq('track', 'Purchase', {
              content_ids: ['" . implode(',', $ids) . "'],
              content_type: 'product',
              value: " . number_format($order->get_total(), 2, '.', '') . ",
              currency: '" . get_woocommerce_currency() . "',
              product_catalog_id: " .$catalogId. "
            });";
          }
        }

        $output .= "</script>";
      }

      if ($fbm || $ci || $facebook) {
        $output .= "<script>";
        $output .= "window.onload = function () {
            var trkParams = window.trkParams || {
                siteId: '" . $siteId . "',
                features: {
                    ci: !!'" . $ci . "',
                    fbm: !!'" . $fbm . "',
                    fbAds: !!'" . $facebook . "',
                    scoring: false,
                    widgets: JSON.parse('" . $widgets . "')
                },
                fbSettings: {
                    app_id:  '" . $appId . "',
                    page_id: '" . $pageId . "' // ID of the page connected to FBM Application
                },
                data: {
                    cart: JSON.parse('" . json_encode($cart) . "')
                }
            };
            
            cgtrkStart = function () {
                CgTracker('init', trkParams);
            
                CgTracker('track', {
                    what:   'event',
                    ofType: 'visit'
                });
            
                // Track quit event
                window.onbeforeunload = function noop () {
                    setTimeout(function () {
                        CgTracker('track', {
                            what:    'event',
                            ofType:  'quit'
                        });
                    }, 0);
                };
            };
            
            (function(d, s, id) {
                var cgs, cgt = d.getElementsByTagName(s)[0];
                if (d.getElementById(id)) return;
                cgs = d.createElement(s); cgs.id = id;
                cgs.src = '" . $trackerUrl . "/dist/woocommerce-client.min.js';
                cgt.parentNode.insertBefore(cgs, cgt);
                cgs.onload = cgtrkStart;
            }(document, 'script', 'cg-evt'));
        ";
        // $output .= "document.body.innerHTML += \"<div class='cartsguru_hidden_fragments'></div>\"";
        $output .= "} </script>";
      }
      echo $output;
    }

    // Tracking for ajax addtocart
    public function ajax_added_to_cart($fragments)
    {
        $facebook = get_option('carts_guru_feature_facebook');
        $fbm = get_option('carts_guru_feature_fbm');
        $ci = get_option('carts_guru_feature_ci');
        $pixel = get_option('carts_guru_facebook_pixel');
        $catalogId = get_option('carts_guru_facebook_catalogId');
        $isCartUpdate = WC()->session->get( 'cg-cart-updated' );

        $output = "";
        if (($facebook || $fbm || $ci) && $isCartUpdate) {
            //Tracker
            // Update cart data
            $cart = WC_Cartsguru_Data_Adaptor::instance()->adapt_cart(WooCommerce::instance()->cart, null);
            $cart['cartId'] = $this->get_cart_id_from_session();

            // Set recover URL
            if ($cart['cartId']) {
                $token = WC_Cartsguru_Carts_Table::instance()->get_cart_token($cart['cartId']);
                $cart['recoverUrl'] = get_site_url() . '/?cartsguru_action=recover-cart&cartsguru_cart_id=' . $cart['cartId'] . '&cartsguru_cart_token=' . $token;
            }

            //clean cart data to send to tracker
            $cart = $this->clean_cart($cart);

            $output .= "<div class='cartsguru_hidden_fragments'><script>";
            $output .= "CgTracker('updateData', { cart: JSON.parse('". json_encode($cart) ."') });";
            
            // Fire events
            $output .= "CgTracker('fireStoredEvents');";

            // Rebind buttons
            $output .= "CgTracker('registerButtons');";
            $output .= "</script></div>";
            WC()->session->set( 'cg-cart-updated', false);
        }
        if ($facebook && $pixel && $catalogId && isset($_POST['product_id'])) {
            $product_id = sanitize_text_field($_POST['product_id']);
            $product = new WC_Product($product_id);

            $output .= '<script>';
            $output .= "fbq('track', 'AddToCart', {
			  content_ids: ['" . $product_id . "'],
			  content_type: 'product',
			  value: " . number_format(floatval($product->get_price()), 2, '.', '') . ",
			  currency: '" . get_woocommerce_currency() . "',
			  product_catalog_id: " .$catalogId. "
			});";
            $output .= '</script>';
            // Append the code
        }
        $fragments['div.cartsguru_hidden_fragments'] = $output;

        return $fragments;
    }

    public function on_action_cart_updated($cartUpdated) {
        if ($cartUpdated) {
            WC()->session->set( 'cg-cart-updated' , true );
        }
    }

    // Set source cookie
    public function source_cookie()
    {
        $facebook = get_option('carts_guru_feature_facebook');
        $utm_source = isset($_GET['utm_source']) ? sanitize_text_field($_GET['utm_source']) : null;
        $utm_campaign = isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : null;
        if ($facebook && !empty($utm_source) && $utm_source === 'cartsguru-fb' && !empty($utm_campaign)) {
            setcookie('cartsguru-source', json_encode(array(
                'type' => $utm_source,
                'campaign' => $utm_campaign,
                'timestamp' => time()
            )), time() + 60 * 60 * 24 * 30, '/');
        }
    }
}
