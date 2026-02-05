<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CT_Logic {
    private $options;

    public function __construct() {
        add_action( 'init', array( $this, 'execute_logic' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'trigger_stock_sync' ), 10, 3 );
    }

    public function execute_logic() {
        $this->options = get_option( 'ct_settings' );
        if ( empty($this->options['enabled']) || $this->should_skip_logic() ) return;
        
        if ( $this->options['mode'] == 'receiver' && isset($_GET['transfer_cart']) ) {
             CT_Logger::log('Transfer request detected on Receiver.');
             $this->process_cart_transfer($_GET['transfer_cart']);
             $url = remove_query_arg('transfer_cart');
             CT_Logger::log('Redirecting to: ' . $url);
             wp_redirect( $url );
             exit;
        }

        if ( $this->options['mode'] == 'sender' ) {
            $this->sender_logic();
        } else {
            $this->receiver_logic();
        }
    }

    private function sender_logic() {
        if ( $this->should_skip_logic() ) return;
        
        $target_url = isset($this->options['target_url']) ? $this->options['target_url'] : '';
        if ( empty($target_url) ) return;

        add_filter( 'woocommerce_get_checkout_url', array( $this, 'filter_checkout_url' ), 999 );
        add_filter( 'woocommerce_get_cart_url', array( $this, 'filter_cart_url' ), 999 );
        add_action( 'template_redirect', array( $this, 'sender_redirection' ) );
        add_filter( 'login_url', array( $this, 'sender_login_url' ), 999 );
    }

    public function filter_cart_url( $url ) {
        $allowed = isset($this->options['sender_allowed_pages']) ? (array)$this->options['sender_allowed_pages'] : array();
        if ( in_array('wc_cart', $allowed) ) return $url;
        return $this->generate_transfer_url('cart', $url);
    }

    public function filter_checkout_url( $url ) {
        $allowed = isset($this->options['sender_allowed_pages']) ? (array)$this->options['sender_allowed_pages'] : array();
        if ( in_array('wc_checkout', $allowed) ) return $url;
        return $this->generate_transfer_url('checkout', $url);
    }

    private function generate_transfer_url($type = 'checkout', $original_url = '') {
        if ( !function_exists('WC') || WC()->cart->is_empty() ) {
             return !empty($original_url) ? $original_url : (($type === 'cart') ? wc_get_cart_url() : wc_get_checkout_url());
        }
        
        $cart_data = array();
        foreach (WC()->cart->get_cart() as $item) {
            $cart_data[] = array(
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'variation_id' => $item['variation_id'],
                'variation' => $item['variation'],
            );
        }
        $encoded = urlencode(base64_encode(json_encode($cart_data)));
        $endpoint = ($type === 'cart') ? 'cart/' : 'checkout/';
        $target = trailingslashit($this->options['target_url']) . $endpoint;
        $final_url = add_query_arg('transfer_cart', $encoded, $target);
        // Optimization: Truncate log message to avoid memory issues with massive URLs
        CT_Logger::log('Generated ' . $type . ' transfer URL: ' . substr($final_url, 0, 200) . '...');
        return $final_url;
    }

    public function sender_redirection() {
        if ( isset($_GET['clear_cart']) && $_GET['clear_cart'] == '1' ) {
            CT_Logger::log('Cart clear requested on Sender.');
            if (function_exists('WC')) WC()->cart->empty_cart();
            if ( isset($_GET['return_to']) ) {
                $return_url = esc_url_raw($_GET['return_to']);
                CT_Logger::log('Redirecting to return_to: ' . $return_url);
                wp_redirect( $return_url );
                exit;
            }
        }

        if ( isset($_GET['transfer_cart']) ) {
            CT_Logger::log('Transfer request detected on Sender (sync back).');
            $this->process_cart_transfer($_GET['transfer_cart']);
            wp_redirect( remove_query_arg('transfer_cart') );
            exit;
        }

        $allowed_ids = isset($this->options['sender_allowed_pages']) ? (array)$this->options['sender_allowed_pages'] : array();
        
        if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
            if ( !$this->is_allowed($allowed_ids) ) {
                $target_base = $this->options['target_url'];
                if (empty($target_base)) return;

                $path = $_SERVER['REQUEST_URI'];
                $dest = trailingslashit($target_base) . ltrim($path, '/');
                
                CT_Logger::log('Sender restricted page access. Redirecting to Receiver: ' . $dest);
                wp_redirect( $dest );
                exit;
            }
        }
    }

    public function sender_login_url($url) {
        return trailingslashit($this->options['target_url']) . 'my-account/';
    }

    private function receiver_logic() {
        add_action( 'template_redirect', array( $this, 'receiver_redirection' ) );
    }

    public function receiver_redirection() {
        if ( $this->should_skip_logic() ) return;

        if ( isset($_GET['transfer_cart']) ) {
            CT_Logger::log('Transfer request detected via template_redirect (Receiver).');
            $this->process_cart_transfer($_GET['transfer_cart']);
            wp_redirect( remove_query_arg('transfer_cart') );
            exit;
        }

        if ( is_wc_endpoint_url('order-received') && !isset($_GET['cleared']) ) {
            $source = isset($this->options['source_url']) ? $this->options['source_url'] : '';
            if (empty($source)) return;
            $current_url = add_query_arg( 'cleared', '1' ); 
            CT_Logger::log('Order received. Redirecting to clear sender cart. Return URL: ' . $current_url);
            $clear_url = add_query_arg( array('clear_cart' => '1', 'return_to' => urlencode($current_url)), $source );
            wp_redirect( $clear_url );
            exit;
        }

        $allowed_ids = isset($this->options['receiver_allowed_pages']) ? (array)$this->options['receiver_allowed_pages'] : array();
        if ( strpos($_SERVER['REQUEST_URI'], 'airwallex') !== false ) return;
        if ( $this->is_allowed($allowed_ids) ) return;

        $source = isset($this->options['source_url']) ? $this->options['source_url'] : '';
        if (empty($source)) return;
        $path = $_SERVER['REQUEST_URI'];
        $dest = trailingslashit($source) . ltrim($path, '/');
        
        if ( function_exists('WC') && !WC()->cart->is_empty() ) {
            $cart_data = array();
            foreach (WC()->cart->get_cart() as $item) {
               $cart_data[] = array(
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'variation_id' => $item['variation_id'],
                    'variation' => $item['variation'],
                );
            }
            $dest = add_query_arg('transfer_cart', urlencode(base64_encode(json_encode($cart_data))), $dest);
            CT_Logger::log('Syncing back to Sender: ' . $dest);
        }
        wp_redirect( $dest );
        exit;
    }

    private function process_cart_transfer($data) {
        if ( !function_exists('WC') ) {
            CT_Logger::log('ERROR: WooCommerce not found during transfer.');
            return;
        }
        
        $decoded_data = base64_decode(urldecode($data));
        $cart = json_decode($decoded_data, true);
        CT_Logger::log('Processing cart transfer. Items: ' . (is_array($cart) ? count($cart) : '0'));

        if ( is_array($cart) ) {
            WC()->cart->empty_cart();
            foreach ($cart as $item) {
                $product_id = intval($item['product_id']);
                $quantity = intval($item['quantity']);
                $variation_id = intval($item['variation_id']);
                $variation = isset($item['variation']) ? $item['variation'] : array();
                WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
            }
            WC()->cart->calculate_totals();
            WC()->session->set_customer_session_cookie(true);
            CT_Logger::log('Cart session saved.');
        } else {
            CT_Logger::log('ERROR: Invalid cart data received.');
        }
    }

    public function is_allowed($allowed_list) {
        if ( empty($allowed_list) ) return false;
        $id = get_the_ID();
        if ( in_array($id, $allowed_list) ) return true;
        if ( in_array('front_page', $allowed_list) && is_front_page() ) return true;
        if ( in_array('archive_shop', $allowed_list) && is_shop() ) return true;
        if ( in_array('archive_product_cat', $allowed_list) && is_product_category() ) return true;
        if ( in_array('archive_product_tag', $allowed_list) && is_product_tag() ) return true;
        if ( in_array('single_product', $allowed_list) && is_product() ) return true;
        if ( in_array('search_results', $allowed_list) && is_search() ) return true;
        if ( in_array('blog_posts', $allowed_list) && (is_home() || is_archive()) && !is_woocommerce() ) return true;
        if ( in_array('wc_cart', $allowed_list) && is_cart() ) return true;
        if ( in_array('wc_checkout', $allowed_list) && is_checkout() ) return true;
        if ( in_array('wc_order_received', $allowed_list) && is_wc_endpoint_url('order-received') ) return true;
        if ( in_array('wc_order_tracking', $allowed_list) && is_page(wc_get_page_id('order_tracking')) ) return true;
        if ( in_array('wc_my_account', $allowed_list) && is_account_page() ) return true;
        if ( in_array('wc_view_order', $allowed_list) && is_view_order_page() ) return true;
        if ( in_array('wc_lost_password', $allowed_list) && is_lost_password_page() ) return true;
        return false;
    }

    /**
     * Checks if the plugin logic should be bypassed (e.g., during editing)
     */
    private function should_skip_logic() {
        if ( is_admin() ) return true;
        
        // Elementor Editor
        if ( isset($_GET['elementor-preview']) || isset($_GET['action']) && $_GET['action'] == 'elementor' ) return true;
        if ( class_exists('\Elementor\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode() ) return true;

        // Customizer
        if ( function_exists('is_customize_preview') && is_customize_preview() ) return true;

        // Gutenberg (Frontend block editor check)
        if ( function_exists('is_block_editor') && is_block_editor() ) return true;
        
        // WP-JSON requests (except our own)
        if ( defined('REST_REQUEST') && REST_REQUEST && strpos($_SERVER['REQUEST_URI'], 'ct/v1') === false ) return true;

        return false;
    }

    /**
     * Triggers stock synchronization when an order is processed on the Receiver
     */
    public function trigger_stock_sync($order_id, $posted_data, $order) {
        $options = get_option('ct_settings');
        if (empty($options['enabled']) || $options['mode'] !== 'receiver') return;

        $items = array();
        foreach ($order->get_items() as $item_id => $item) {
            $items[] = array(
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'quantity'     => $item->get_quantity(),
            );
        }

        if (!empty($items)) {
            $sync = new CT_Sync();
            $sync->sync_stock_to_remote($items);
        }
    }
}
