<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CT_Sync {
    public function __construct() {
        add_action( 'wp_ajax_ct_sync_products', array( $this, 'ajax_sync_products' ) );
    }

    public function ajax_sync_products() {
        check_ajax_referer('ct_sync_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $step = isset($_POST['step']) ? $_POST['step'] : '';
        $options = get_option('ct_settings');
        $target_url = isset($options['target_url']) ? $options['target_url'] : '';
        $secret = isset($options['secret']) ? $options['secret'] : '';

        if (empty($target_url) || empty($secret)) wp_send_json_error('Receiver URL and Secret must be configured.');

        if ($step == 'fetch') {
            $response = wp_remote_get(trailingslashit($target_url) . 'wp-json/ct/v1/products', array(
                'headers' => array('X-CT-Secret' => $secret),
                'timeout' => 30
            ));
            if (is_wp_error($response)) wp_send_json_error($response->get_error_message());
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) wp_send_json_error('Invalid response from Receiver site.');
            wp_send_json_success($data);
        }

        if ($step == 'sync_one') {
            $pid = intval($_POST['product_id']);
            $response = wp_remote_get(trailingslashit($target_url) . 'wp-json/ct/v1/product/' . $pid, array(
                'headers' => array('X-CT-Secret' => $secret),
                'timeout' => 30
            ));
            if (is_wp_error($response)) wp_send_json_error($response->get_error_message());
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!$data || isset($data['code'])) wp_send_json_error('Failed to fetch details for Product ID ' . $pid);

            $this->save_remote_product($data);
            
            // Optimization: Clear object cache to prevent exhaustion
            wp_cache_flush();
            
            wp_send_json_success();
        }
        wp_die();
    }

    private function save_remote_product($data) {
        $id = intval($data['id']);
        $exists = get_post($id);
        
        $post_data = array(
            'import_id'    => $id,
            'post_title'   => isset($data['name']) ? $data['name'] : $data['post_title'],
            'post_content' => isset($data['description']) ? $data['description'] : $data['post_content'],
            'post_excerpt' => isset($data['short_description']) ? $data['short_description'] : $data['post_excerpt'],
            'post_status'  => $data['status'],
            'post_type'    => 'product',
        );

        if ($exists) {
            $post_data['ID'] = $id;
            wp_update_post($post_data);
        } else {
            global $wpdb;
            $wpdb->insert($wpdb->posts, array(
                'ID' => $id,
                'post_title' => $post_data['post_title'],
                'post_type' => 'product',
                'post_status' => $data['status'],
                'post_author' => get_current_user_id()
            ));
            wp_update_post($post_data);
        }

        $product = wc_get_product($id);
        if (!$product) return;

        if (isset($data['regular_price'])) $product->set_regular_price($data['regular_price']);
        if (isset($data['sale_price'])) $product->set_sale_price($data['sale_price']);
        if (isset($data['sku'])) $product->set_sku($data['sku']);
        $product->set_status($data['status']);
        
        if (!empty($data['sync_categories'])) {
            $cat_ids = array();
            foreach ($data['sync_categories'] as $cat) {
                $term = get_term_by('slug', $cat['slug'], 'product_cat');
                if (!$term) {
                    $term_info = wp_insert_term($cat['name'], 'product_cat', array('slug' => $cat['slug']));
                    if (!is_wp_error($term_info)) $cat_ids[] = $term_info['term_id'];
                } else {
                    $cat_ids[] = $term->term_id;
                }
            }
            wp_set_object_terms($id, $cat_ids, 'product_cat');
        }

        if (!empty($data['sync_tags'])) {
            $tag_ids = array();
            foreach ($data['sync_tags'] as $tag) {
                $term = get_term_by('slug', $tag['slug'], 'product_tag');
                if (!$term) {
                    $term_info = wp_insert_term($tag['name'], 'product_tag', array('slug' => $tag['slug']));
                    if (!is_wp_error($term_info)) $tag_ids[] = $term_info['term_id'];
                } else {
                    $tag_ids[] = $term->term_id;
                }
            }
            wp_set_object_terms($id, $tag_ids, 'product_tag');
        }

        if (!empty($data['sync_attributes'])) {
            $attributes = array();
            foreach ($data['sync_attributes'] as $attr_data) {
                $attribute = new WC_Product_Attribute();
                $attribute->set_name($attr_data['name']);
                $attribute->set_visible($attr_data['visible']);
                $attribute->set_variation($attr_data['variation']);
                
                if (!empty($attr_data['taxonomy'])) {
                    $attribute->set_id(wc_attribute_taxonomy_id_by_name($attr_data['taxonomy']));
                    $attribute->set_options(wp_list_pluck($attr_data['terms'], 'name'));
                } else {
                    $attribute->set_options($attr_data['options']);
                }
                $attributes[] = $attribute;
            }
            $product->set_attributes($attributes);
        }

        if (!empty($data['featured_image_url'])) {
            $img_id = $this->upload_remote_image($data['featured_image_url'], $id);
            if ($img_id) $product->set_image_id($img_id);
        }
        
        if (!empty($data['gallery_image_urls'])) {
            $gallery_ids = array();
            foreach ($data['gallery_image_urls'] as $url) {
                $img_id = $this->upload_remote_image($url, $id);
                if ($img_id) $gallery_ids[] = $img_id;
            }
            $product->set_gallery_image_ids($gallery_ids);
        }

        $product->save();

        if (isset($data['sync_variations']) && is_array($data['sync_variations'])) {
            wp_set_object_terms($id, 'variable', 'product_type');
            foreach ($data['sync_variations'] as $v_data) {
                $this->save_remote_variation($v_data, $id);
            }
        }
    }

    private function save_remote_variation($v_data, $parent_id) {
        $v_id = intval($v_data['id']);
        $exists = get_post($v_id);

        if (!$exists) {
            global $wpdb;
            $wpdb->insert($wpdb->posts, array(
                'ID' => $v_id,
                'post_type' => 'product_variation',
                'post_status' => $v_data['status'],
                'post_parent' => $parent_id,
                'post_author' => get_current_user_id()
            ));
        } else {
            wp_update_post(array('ID' => $v_id, 'post_parent' => $parent_id, 'post_type' => 'product_variation'));
        }

        $variation = new WC_Product_Variation($v_id);
        $variation->set_regular_price($v_data['regular_price']);
        $variation->set_sale_price($v_data['sale_price']);
        $variation->set_sku($v_data['sku']);
        $variation->set_status($v_data['status']);
        $variation->set_attributes($v_data['attributes']);

        if (!empty($v_data['featured_image_url'])) {
            $img_id = $this->upload_remote_image($v_data['featured_image_url'], $v_id);
            if ($img_id) $variation->set_image_id($img_id);
        }

        $variation->save();
    }

    private function upload_remote_image($url, $post_id) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $name = basename(parse_url($url, PHP_URL_PATH));
        $tmp = download_url($url, 30, false);

        if (is_wp_error($tmp)) {
            $response = wp_remote_get($url, array('timeout' => 30, 'sslverify' => false));
            if (is_wp_error($response)) {
                error_log('CT Sync Error: Image download failed for ' . $url . ' - ' . $response->get_error_message());
                return false;
            }
            $body = wp_remote_retrieve_body($response);
            $tmp = wp_tempnam($url);
            file_put_contents($tmp, $body);
        }

        $file_array = array(
            'name' => $name,
            'tmp_name' => $tmp
        );

        $id = media_handle_sideload($file_array, $post_id, "Remote Image " . $post_id);

        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            error_log('CT Sync Error: Sideload failed for ' . $url . ' - ' . $id->get_error_message());
            return false;
        }

        return $id;
    }

    public function render_sync_tab() {
        $options = get_option('ct_settings');
        $mode = isset($options['mode']) ? $options['mode'] : '';
        if ($mode !== 'sender') {
            echo '<p>Product Sync is only available in <strong>Sender Mode</strong> (to pull product data from the Receiver site).</p>';
            return;
        }
        ?>
        <h2>Product Synchronizer</h2>
        <p>This allows you to select products from the <strong>Receiver Site</strong> and import them here with the <strong>same ID</strong>.</p>
        <div id="ct-sync-container" style="background: #fff; border: 1px solid #ccc; padding: 20px; border-radius: 4px;">
            <button type="button" id="ct-fetch-products" class="button button-primary">Fetch Products from Receiver</button>
            <div id="ct-product-list" style="margin-top: 20px; max-height: 400px; overflow-y: auto; border: 1px solid #eee; padding: 15px; display:none;"></div>
            <div id="ct-sync-controls" style="margin-top:20px; display:none; border-top: 1px solid #eee; padding-top: 20px;">
                <button type="button" id="ct-start-sync" class="button button-secondary">Sync Selected Products</button>
                <span id="ct-sync-status" style="margin-left: 15px; font-weight: bold; color: #0073aa;"></span>
            </div>
        </div>
        <?php
    }

    public function render_sync_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#ct-fetch-products').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Fetching...');
                $('#ct-product-list').hide();
                $('#ct-sync-controls').hide();
                $.ajax({
                    url: ajaxurl, type: 'POST',
                    data: { action: 'ct_sync_products', step: 'fetch', nonce: '<?php echo wp_create_nonce("ct_sync_nonce"); ?>' },
                    success: function(response) {
                        if (response.success) {
                            var html = '<table class="wp-list-table widefat fixed striped"><thead><tr><th class="manage-column column-cb check-column"><input type="checkbox" id="ct-select-all"></th><th>ID</th><th>Title</th></tr></thead><tbody>';
                            response.data.forEach(function(p) {
                                html += '<tr><th><input type="checkbox" class="ct-sync-check" value="'+p.id+'"></th><td>'+p.id+'</td><td>'+p.title+'</td></tr>';
                            });
                            html += '</tbody></table>';
                            $('#ct-product-list').html(html).show();
                            $('#ct-sync-controls').show();
                            $('#ct-select-all').on('change', function() { $('.ct-sync-check').prop('checked', $(this).prop('checked')); });
                        } else { alert('Error: ' + response.data); }
                    },
                    error: function() { alert('Failed to connect to Receiver site.'); },
                    complete: function() { btn.prop('disabled', false).text('Fetch Products from Receiver'); }
                });
            });

            $('#ct-start-sync').on('click', function() {
                var selected = [];
                $('.ct-sync-check:checked').each(function() { selected.push($(this).val()); });
                if (selected.length === 0) { alert('Select at least one product.'); return; }
                if (!confirm('⚠️ WARNING: Syncing will OVERWRITE any existing products on this site that have the same ID. Continue?')) return;

                var btn = $(this);
                var status = $('#ct-sync-status');
                btn.prop('disabled', true);
                var process_item = function(index) {
                    if (index >= selected.length) { status.text('✅ All selected products synced successfully!'); btn.prop('disabled', false); return; }
                    status.text('⏳ Syncing: ' + (index + 1) + ' / ' + selected.length + '...');
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: { action: 'ct_sync_products', step: 'sync_one', product_id: selected[index], nonce: '<?php echo wp_create_nonce("ct_sync_nonce"); ?>' },
                        success: function(response) {
                            if (response.success) { process_item(index + 1); } 
                            else { status.text('❌ Error on ID ' + selected[index] + ': ' + response.data); btn.prop('disabled', false); }
                        },
                        error: function() { status.text('❌ Network error syncing ID ' + selected[index]); btn.prop('disabled', false); }
                    });
                };
                process_item(0);
            });
        });
        </script>
        <?php
    }

    /**
     * Pings the remote site to update stock levels
     */
    public function sync_stock_to_remote($items) {
        if (empty($items)) return;

        $options = get_option('ct_settings');
        $mode = isset($options['mode']) ? $options['mode'] : '';
        
        // Stock sync only triggers from Receiver -> Sender
        if ($mode !== 'receiver') return;

        $target_url = isset($options['source_url']) ? $options['source_url'] : '';
        $secret = isset($options['secret']) ? $options['secret'] : '';

        if (empty($target_url) || empty($secret)) {
            CT_Logger::log('Stock Sync Error: Sender URL or Secret not configured.');
            return;
        }

        $endpoint = trailingslashit($target_url) . 'wp-json/ct/v1/stock/update';
        
        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'X-CT-Secret'  => $secret,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array('items' => $items)),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            CT_Logger::log('Stock Sync Error: ' . $response->get_error_message());
        } else {
            CT_Logger::log('Stock Sync Triggered: Receiver -> Sender. Status: ' . wp_remote_retrieve_response_code($response));
        }
    }
}
