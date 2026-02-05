<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CT_API {
    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
    }

    public function register_rest_routes() {
        register_rest_route( 'ct/v1', '/products', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_remote_products' ),
            'permission_callback' => array( $this, 'api_permission_check' )
        ));
        register_rest_route( 'ct/v1', '/product/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( $this, 'get_remote_product_details' ),
            'permission_callback' => array( $this, 'api_permission_check' )
        ));
        register_rest_route( 'ct/v1', '/stock/update', array(
            'methods' => 'POST',
            'callback' => array( $this, 'update_stock_levels' ),
            'permission_callback' => array( $this, 'api_permission_check' )
        ));
    }

    public function api_permission_check( $request ) {
        $secret = $request->get_header( 'X-CT-Secret' );
        $options = get_option( 'ct_settings' );
        $stored_secret = isset($options['secret']) ? $options['secret'] : '';
        return !empty($stored_secret) && $secret === $stored_secret;
    }

    public function get_remote_products() {
        // Optimization: Use direct SQL or get_posts with fewer fields to save memory
        global $wpdb;
        $products = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");
        
        $data = array();
        foreach($products as $p) { 
            $data[] = array('id' => (int)$p->ID, 'title' => $p->post_title); 
        }
        return rest_ensure_response($data);
    }

    public function get_remote_product_details($request) {
        $id = $request['id'];
        $product = wc_get_product($id);
        if (!$product) return new WP_Error('no_product', 'Product not found', array('status' => 404));
        
        $data = $product->get_data();
        
        // 1. Image URLs
        $image_id = $product->get_image_id();
        if ($image_id) $data['featured_image_url'] = wp_get_attachment_url($image_id);
        
        $gallery_ids = $product->get_gallery_image_ids();
        $data['gallery_image_urls'] = array();
        foreach ($gallery_ids as $gid) {
            $data['gallery_image_urls'][] = wp_get_attachment_url($gid);
        }

        // 2. Taxonomies
        $data['sync_categories'] = array();
        $terms = get_the_terms($id, 'product_cat');
        if ($terms) foreach ($terms as $t) $data['sync_categories'][] = array('slug' => $t->slug, 'name' => $t->name);

        $data['sync_tags'] = array();
        $terms = get_the_terms($id, 'product_tag');
        if ($terms) foreach ($terms as $t) $data['sync_tags'][] = array('slug' => $t->slug, 'name' => $t->name);

        // 3. Attributes
        $data['sync_attributes'] = array();
        $attributes = $product->get_attributes();
        foreach ($attributes as $attr) {
            $attr_data = $attr->get_data();
            if ($attr->is_taxonomy()) {
                $attr_data['taxonomy'] = $attr->get_name();
                $terms = $attr->get_terms();
                $attr_data['terms'] = array();
                foreach ($terms as $term) $attr_data['terms'][] = array('slug' => $term->slug, 'name' => $term->name);
            }
            $data['sync_attributes'][] = $attr_data;
        }

        // 4. Variations
        if ($product->is_type('variable')) {
            $data['sync_variations'] = array();
            $variation_ids = $product->get_children();
            foreach ($variation_ids as $vid) {
                $variation = wc_get_product($vid);
                if ($variation) {
                    $v_data = $variation->get_data();
                    $v_image_id = $variation->get_image_id();
                    if ($v_image_id) $v_data['featured_image_url'] = wp_get_attachment_url($v_image_id);
                    $data['sync_variations'][] = $v_data;
                }
            }
        }

        return rest_ensure_response($data);
    }

    public function update_stock_levels($request) {
        $items = $request->get_param('items');
        if (!is_array($items)) return new WP_Error('invalid_data', 'Invalid items data', array('status' => 400));

        $results = array();
        foreach ($items as $item) {
            $product_id = intval($item['product_id']);
            $variation_id = isset($item['variation_id']) ? intval($item['variation_id']) : 0;
            $qty = intval($item['quantity']);

            $target_id = $variation_id ? $variation_id : $product_id;
            $product = wc_get_product($target_id);

            if ($product && $product->managing_stock()) {
                $new_stock = wc_update_product_stock($product, $qty, 'decrease');
                $results[] = array(
                    'id' => $target_id,
                    'status' => 'success',
                    'new_stock' => $new_stock
                );
            } else {
                $results[] = array(
                    'id' => $target_id,
                    'status' => 'skipped',
                    'reason' => ($product ? 'Stock management disabled' : 'Product not found')
                );
            }
        }

        CT_Logger::log('Remote stock update processed: ' . json_encode($results));
        return rest_ensure_response(array('success' => true, 'results' => $results));
    }
}
