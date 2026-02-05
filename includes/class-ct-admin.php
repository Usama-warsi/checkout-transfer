<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CT_Admin {
    private $options;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    public function add_plugin_page() {
        add_options_page(
            'Checkout Transfer Settings', 
            'Checkout Transfer', 
            'manage_options', 
            'checkout-transfer-settings', 
            array( $this, 'create_admin_page' )
        );
    }

    public function create_admin_page() {
        $this->options = get_option( 'ct_settings' );
        $active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'general';
        ?>
        <div class="wrap">
            <h1>Checkout Transfer Settings</h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=checkout-transfer-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="?page=checkout-transfer-settings&tab=sender" class="nav-tab <?php echo $active_tab == 'sender' ? 'nav-tab-active' : ''; ?>">Sender Mode</a>
                <a href="?page=checkout-transfer-settings&tab=receiver" class="nav-tab <?php echo $active_tab == 'receiver' ? 'nav-tab-active' : ''; ?>">Receiver Mode</a>
                <a href="?page=checkout-transfer-settings&tab=sync" class="nav-tab <?php echo $active_tab == 'sync' ? 'nav-tab-active' : ''; ?>">Product Sync</a>
                <a href="?page=checkout-transfer-settings&tab=debug" class="nav-tab <?php echo $active_tab == 'debug' ? 'nav-tab-active' : ''; ?>">Debug</a>
            </h2>

            <form method="post" action="options.php">
            <?php
                settings_fields( 'ct_settings_group' );
                echo '<input type="hidden" name="ct_active_tab" value="' . esc_attr($active_tab) . '" />';
                if ( $active_tab == 'general' ) {
                    do_settings_sections( 'ct-settings-admin-general' );
                } elseif ( $active_tab == 'sender' ) {
                    do_settings_sections( 'ct-settings-admin-sender' );
                } elseif ( $active_tab == 'receiver' ) {
                    do_settings_sections( 'ct-settings-admin-receiver' );
                } elseif ( $active_tab == 'debug' ) {
                    $this->render_debug_tab();
                } else {
                    $sync = new CT_Sync();
                    $sync->render_sync_tab();
                }
                if ($active_tab !== 'sync' && $active_tab !== 'debug') submit_button();
            ?>
            </form>
        </div>
        <?php 
        if ($active_tab === 'sync') {
            $sync = new CT_Sync();
            $sync->render_sync_scripts();
        }
    }

    public function page_init() {
        register_setting( 'ct_settings_group', 'ct_settings', array( $this, 'sanitize' ) );

        add_settings_section( 'section_general', 'General Settings', null, 'ct-settings-admin-general' );
        add_settings_field( 'enabled', 'Enable Logic', array( $this, 'checkbox_field' ), 'ct-settings-admin-general', 'section_general', array('id' => 'enabled') );
        add_settings_field( 'mode', 'Site Role', array( $this, 'select_field' ), 'ct-settings-admin-general', 'section_general', array(
            'id' => 'mode', 
            'options' => array('sender' => 'Sender Site (Sends Cart)', 'receiver' => 'Receiver Site (Processes Checkout)')
        ));
        add_settings_field( 'debug_enabled', 'Enable Debug Logging', array( $this, 'checkbox_field' ), 'ct-settings-admin-general', 'section_general', array('id' => 'debug_enabled') );
        add_settings_field( 'secret', 'Shared Secret (For Sync)', array( $this, 'text_field' ), 'ct-settings-admin-general', 'section_general', array('id' => 'secret', 'placeholder' => 'Enter a random string') );

        add_settings_section( 'section_sender', 'Sender Configuration', null, 'ct-settings-admin-sender' );
        add_settings_field( 'target_url', 'Receiver Site URL', array( $this, 'text_field' ), 'ct-settings-admin-sender', 'section_sender', array('id' => 'target_url', 'placeholder' => 'https://business2.local') );
        add_settings_field( 'sender_allowed_pages', 'Allowed Pages (Exclude from Redirect)', array( $this, 'page_checklist' ), 'ct-settings-admin-sender', 'section_sender', array('id' => 'sender_allowed_pages') );

        add_settings_section( 'section_receiver', 'Receiver Configuration', null, 'ct-settings-admin-receiver' );
        add_settings_field( 'source_url', 'Sender Site URL', array( $this, 'text_field' ), 'ct-settings-admin-receiver', 'section_receiver', array('id' => 'source_url', 'placeholder' => 'https://business.local') );
        add_settings_field( 'receiver_allowed_pages', 'Allowed Pages on Receiver', array( $this, 'page_checklist' ), 'ct-settings-admin-receiver', 'section_receiver', array('id' => 'receiver_allowed_pages') );
    }

    public function sanitize( $input ) {
        $existing = get_option( 'ct_settings' );
        if ( ! is_array( $existing ) ) $existing = array();
        
        $tab = isset($_POST['ct_active_tab']) ? $_POST['ct_active_tab'] : 'general';

        if ( $tab == 'general' ) {
            $existing['enabled'] = isset( $input['enabled'] ) ? absint( $input['enabled'] ) : 0;
            $existing['debug_enabled'] = isset( $input['debug_enabled'] ) ? absint( $input['debug_enabled'] ) : 0;
            if( isset( $input['mode'] ) ) $existing['mode'] = sanitize_text_field( $input['mode'] );
            if( isset( $input['secret'] ) ) $existing['secret'] = sanitize_text_field( $input['secret'] );
        }

        if ( $tab == 'sender' ) {
            if( isset( $input['target_url'] ) ) $existing['target_url'] = esc_url_raw( $input['target_url'] );
            $existing['sender_allowed_pages'] = isset( $input['sender_allowed_pages'] ) ? $input['sender_allowed_pages'] : array();
        }

        if ( $tab == 'receiver' ) {
            if( isset( $input['source_url'] ) ) $existing['source_url'] = esc_url_raw( $input['source_url'] );
            $existing['receiver_allowed_pages'] = isset( $input['receiver_allowed_pages'] ) ? $input['receiver_allowed_pages'] : array();
        }

        return $existing;
    }

    public function checkbox_field($args) {
        $val = isset($this->options[$args['id']]) ? $this->options[$args['id']] : 0;
        printf('<input type="checkbox" id="%s" name="ct_settings[%s]" value="1" %s />', $args['id'], $args['id'], checked(1, $val, false));
    }

    public function text_field($args) {
        $val = isset($this->options[$args['id']]) ? $this->options[$args['id']] : '';
        $is_secret = ($args['id'] === 'secret');
        $mode = isset($this->options['mode']) ? $this->options['mode'] : 'sender';
        
        echo '<div style="display: flex; gap: 10px; align-items: center;">';
        
        if ($is_secret) {
            if ($mode === 'receiver') {
                printf('<input type="text" id="%s" name="ct_settings[%s]" value="%s" class="regular-text" placeholder="%s" readonly onclick="this.select();" />', $args['id'], $args['id'], esc_attr($val), $args['placeholder']);
                echo '<button type="button" id="ct-regenerate-secret" class="button">Regenerate</button>';
                echo '</div>';
                echo '<p class="description"><strong>Receiver Mode:</strong> This site is the source of the secret. Copy this and paste it into your Sender site.</p>';
                ?>
                <script>
                jQuery(document).ready(function($) {
                    $('#ct-regenerate-secret').on('click', function() {
                        if (confirm('Regenerating will break existing sync until you update the other site. Continue?')) {
                            var newSecret = btoa(Math.random().toString(36).substring(2, 15) + Math.random().toString(36).substring(2, 15));
                            $('#secret').val(newSecret);
                        }
                    });
                });
                </script>
                <?php
            } else {
                printf('<input type="text" id="%s" name="ct_settings[%s]" value="%s" class="regular-text" placeholder="%s" />', $args['id'], $args['id'], esc_attr($val), $args['placeholder']);
                echo '</div>';
                echo '<p class="description"><strong>Sender Mode:</strong> Paste the secret from your **Receiver** site here to allow secure syncing.</p>';
            }
        } else {
            printf('<input type="text" id="%s" name="ct_settings[%s]" value="%s" class="regular-text" placeholder="%s" />', $args['id'], $args['id'], esc_attr($val), $args['placeholder']);
            echo '</div>';
        }
    }

    public function select_field($args) {
        $val = isset($this->options[$args['id']]) ? $this->options[$args['id']] : '';
        echo "<select id='{$args['id']}' name='ct_settings[{$args['id']}]'>";
        foreach($args['options'] as $key => $label) {
            printf('<option value="%s" %s>%s</option>', $key, selected($val, $key, false), $label);
        }
        echo "</select>";
    }

    public function page_checklist($args) {
        $selected = isset($this->options[$args['id']]) ? (array)$this->options[$args['id']] : array();
        
        // Use get_posts for better performance and memory management than get_pages()
        $pages = get_posts(array(
            'post_type' => 'page',
            'posts_per_page' => 200, // Reasonable limit
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        echo '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff; margin-bottom: 20px;">';
        echo '<strong>Static Pages (Top 200):</strong><br>';
        foreach($pages as $page) {
            printf(
                '<label><input type="checkbox" name="ct_settings[%s][]" value="%s" %s> %s</label><br>',
                $args['id'],
                $page->ID,
                checked(in_array($page->ID, $selected), true, false),
                $page->post_title
            );
        }
        
        echo '<hr><strong>Archives & Special Templates:</strong><br>';
        $archives = array(
            'archive_shop' => 'Shop Page',
            'archive_product_cat' => 'Product Categories',
            'archive_product_tag' => 'Product Tags',
            'single_product' => 'Single Product Pages',
            'search_results' => 'Search Results',
            'front_page' => 'Homepage',
            'blog_posts' => 'Blog / Post Archive',
            'wc_cart' => 'WooCommerce Cart',
            'wc_checkout' => 'WooCommerce Checkout',
            'wc_order_received' => 'Order Received / Thank You',
            'wc_order_tracking' => 'Order Tracking',
            'wc_my_account' => 'My Account (Main)',
            'wc_view_order' => 'View Order / Order Details',
            'wc_lost_password' => 'Lost Password'
        );

        foreach($archives as $key => $label) {
            printf(
                '<label><input type="checkbox" name="ct_settings[%s][]" value="%s" %s> %s</label><br>',
                $args['id'],
                $key,
                checked(in_array($key, $selected), true, false),
                $label
            );
        }
        echo '</div><p class="description">Select which sections should remain accessible. Unselected sections will be redirected.</p>';
    }

    public function render_debug_tab() {
        if (isset($_POST['ct_clear_logs'])) {
            update_option('ct_logs', array());
            echo '<div class="updated"><p>Logs cleared.</p></div>';
        }
        $logs = get_option('ct_logs', array());
        ?>
        <h2>System Status & Logs</h2>
        <table class="widefat fixed" style="margin-bottom: 20px;">
            <thead><tr><th>Component</th><th>Status</th></tr></thead>
            <tbody>
                <tr><td>WooCommerce Active</td><td><?php echo function_exists('WC') ? '✅ Yes' : '❌ No'; ?></td></tr>
                <tr><td>Site URL</td><td><?php echo site_url(); ?></td></tr>
                <tr><td>Mode</td><td><?php echo isset($this->options['mode']) ? ucfirst($this->options['mode']) : 'Not Set'; ?></td></tr>
            </tbody>
        </table>

        <h3>Recent Activity</h3>
        <div style="background: #fff; border: 1px solid #ccc; padding: 10px; height: 400px; overflow-y: auto; font-family: monospace;">
            <?php if (empty($logs)): ?>
                <p>No activity recorded yet.</p>
            <?php else: ?>
                <?php foreach($logs as $log): ?>
                    <div style="border-bottom: 1px solid #eee; padding: 5px 0;">
                        <span style="color: #888;"><?php echo $log['time']; ?></span> - <?php echo esc_html($log['msg']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <form method="post" style="margin-top: 10px;">
            <input type="hidden" name="ct_clear_logs" value="1">
            <button type="submit" class="button">Clear Logs</button>
        </form>
        <?php
    }
}
