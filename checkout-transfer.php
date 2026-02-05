<?php
/**
 * Plugin Name: Checkout Transfer
 * Description: Seamlessly transfer WooCommerce cart and checkout to another domain.
 * Version: 1.1.0
 * Author: Muhammad Usama Shaheen
 * Author URI: https://codrammer.com 
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define plugin constants
define( 'CT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Autoloading logic (Simple require for now)
require_once CT_PLUGIN_DIR . 'includes/class-ct-logger.php';
require_once CT_PLUGIN_DIR . 'includes/class-ct-admin.php';
require_once CT_PLUGIN_DIR . 'includes/class-ct-api.php';
require_once CT_PLUGIN_DIR . 'includes/class-ct-sync.php';
require_once CT_PLUGIN_DIR . 'includes/class-ct-logic.php';

/**
 * Main Plugin Class
 */
class CheckoutTransfer {

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        
        // Initialize Components
        if ( is_admin() ) {
            new CT_Admin();
        }
        
        new CT_API();
        new CT_Sync();
        new CT_Logic();
    }

    public function activate() {
        $options = get_option( 'ct_settings' );
        if ( ! is_array( $options ) ) $options = array();
        
        if ( ! isset( $options['secret'] ) || empty( $options['secret'] ) ) {
            $options['secret'] = base64_encode( wp_generate_password( 32, true, true ) );
            update_option( 'ct_settings', $options );
        }
    }
}

// Initialize the plugin
new CheckoutTransfer();
