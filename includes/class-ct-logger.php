<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CT_Logger {
    public static function log($message) {
        $options = get_option( 'ct_settings' );
        if ( empty($options['debug_enabled']) ) return;

        // Truncate message to avoid memory exhaustion from large strings
        $message = substr($message, 0, 1000);

        $log_entry = array(
            'time' => current_time('mysql'),
            'msg' => $message
        );
        $logs = get_option('ct_logs', array());
        if (!is_array($logs)) $logs = array();

        array_unshift($logs, $log_entry);
        $logs = array_slice($logs, 0, 50); // Keep last 50
        update_option('ct_logs', $logs, false); // Set autoload to false for logs
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[CheckoutTransfer] ' . $message);
        }
    }
}
