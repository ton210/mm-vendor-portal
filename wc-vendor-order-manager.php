<?php
/**
 * Plugin Name:       Vendor Order Manager: Zakeke Edition Pro
 * Description:       Enhanced vendor order management system with improved approval workflow and analytics.
 * Version:           6.0.0
 * Author:            Enhanced Version
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VSS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'VSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSS_VERSION', '6.0.0' );

// Main plugin class
final class VendorOrderManager {
    private static $_instance = null;
    
    public static function instance() { 
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance; 
    }
    
    private function __construct() {
        add_action('plugins_loaded', [$this, 'init_plugin']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate() {
        // Create database tables for analytics
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Vendor performance analytics table
        $table_name = $wpdb->prefix . 'vss_vendor_analytics';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            vendor_id bigint(20) NOT NULL,
            order_id bigint(20) NOT NULL,
            order_total decimal(10,2) DEFAULT 0,
            vendor_cost decimal(10,2) DEFAULT 0,
            profit_margin decimal(5,2) DEFAULT 0,
            processing_time int(11) DEFAULT 0,
            approval_time int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vendor_id (vendor_id),
            KEY order_id (order_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Schedule cron jobs
        if (!wp_next_scheduled('vss_daily_analytics')) {
            wp_schedule_event(time(), 'daily', 'vss_daily_analytics');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('vss_daily_analytics');
    }

    public function init_plugin() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-setup.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-zakeke-api.php'; 
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-admin.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-vendor.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-emails.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-analytics.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-ajax.php';
    }

    private function init_hooks() {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_styles_scripts']); 
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_scripts_styles']);
        add_action('wp_ajax_vss_approve_mockup', ['VSS_Ajax', 'handle_approve_mockup']);
        add_action('wp_ajax_vss_disapprove_mockup', ['VSS_Ajax', 'handle_disapprove_mockup']);
        add_action('wp_ajax_vss_get_vendor_costs', ['VSS_Ajax', 'get_vendor_costs']);
        
        VSS_Setup::init();
        VSS_Zakeke_API::init(); 
        VSS_Admin::init(); 
        VSS_Vendor::init();
        VSS_Emails::init();
        VSS_Analytics::init();
        VSS_Ajax::init();
    }

    public static function enqueue_admin_scripts_styles($hook) {
        wp_enqueue_style('vss-admin-styles', VSS_PLUGIN_URL . 'assets/css/vss-admin-styles.css', [], VSS_VERSION);
        
        global $pagenow, $typenow;
        if (($pagenow === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order') || 
            ($pagenow === 'post-new.php' && $typenow === 'shop_order') ||
            (isset($_GET['page']) && strpos($_GET['page'], 'vss-') === 0)) {
            
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-smoothness', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');
            
            // Enqueue Chart.js for analytics
            wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1');
            
            // Admin JS
            wp_enqueue_script('vss-admin-js', VSS_PLUGIN_URL . 'assets/js/vss-admin.js', ['jquery', 'chartjs'], VSS_VERSION, true);
            wp_localize_script('vss-admin-js', 'vss_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vss_ajax_nonce')
            ]);
        }
    }

    public static function enqueue_frontend_styles_scripts() {
        if (is_page('vendor-portal')) { 
            wp_enqueue_style('vss-frontend-styles', VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css', [], VSS_VERSION);
            wp_enqueue_script('jquery'); 
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-smoothness', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');
            
            wp_enqueue_script('vss-frontend-js', VSS_PLUGIN_URL . 'assets/js/vss-frontend.js', ['jquery', 'jquery-ui-datepicker'], VSS_VERSION, true);
            wp_localize_script('vss-frontend-js', 'vss_frontend_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vss_frontend_ajax_nonce')
            ]);
        }
    }
}

// Initialize the plugin
function VSS() { 
    return VendorOrderManager::instance(); 
}
VSS();

// Enhanced Admin Class with Bug Fixes
class VSS_Admin_Enhanced {
    
    public static function render_payouts_report_page() {
        if (!current_user_can('manage_woocommerce')) return;
        
        $default_days = 90; 
        $selected_days = isset($_GET['vss_filter_days']) ? intval($_GET['vss_filter_days']) : $default_days;
        $date_query_args = [];
        
        if ($selected_days > 0) { 
            $date_query_args = [
                ['after' => date('Y-m-d 00:00:00', strtotime("-{$selected_days} days")), 
                 'before' => date('Y-m-d 23:59:59'), 
                 'inclusive' => true]
            ];
        }
        
        $payouts_data = []; 
        $vendors = get_users(['role' => 'vendor-mm']);
        
        foreach ($vendors as $vendor) { 
            $payouts_data[$vendor->ID] = [
                'name' => $vendor->display_name, 
                'total' => 0, 
                'orders' => 0,
                'has_costs' => false
            ]; 
        }
        
        $order_args = [
            'limit' => -1, 
            'status' => ['wc-processing', 'wc-shipped', 'wc-completed'], 
            'return' => 'objects'
        ];
        
        if (!empty($date_query_args)) { 
            $order_args['date_query'] = $date_query_args; 
        } 
        
        $orders = wc_get_orders($order_args);
        
        foreach ($orders as $order) {
            $vendor_id = get_post_meta($order->get_id(), '_vss_vendor_user_id', true); 
            $costs = get_post_meta($order->get_id(), '_vss_order_costs', true);
            
            if ($vendor_id && isset($payouts_data[$vendor_id])) {
                if (!empty($costs) && isset($costs['total_cost']) && is_numeric($costs['total_cost']) && $costs['total_cost'] > 0) {
                    $payouts_data[$vendor_id]['total'] += floatval($costs['total_cost']); 
                    $payouts_data[$vendor_id]['orders']++;
                    $payouts_data[$vendor_id]['has_costs'] = true;
                } else {
                    // Count orders without costs
                    $payouts_data[$vendor_id]['orders']++;
                }
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Vendor Payouts Report', 'vss'); ?></h1>
            
            <!-- Enhanced filter form -->
            <form method="GET" class="vss-date-filter-form">
                <input type="hidden" name="page" value="vss-payouts-report" />
                <label for="vss_filter_days"><?php _e('Show data for last:', 'vss'); ?></label>
                <select name="vss_filter_days" id="vss_filter_days">
                    <option value="30" <?php selected($selected_days, 30); ?>>30 <?php _e('days', 'vss'); ?></option>
                    <option value="60" <?php selected($selected_days, 60); ?>>60 <?php _e('days', 'vss'); ?></option>
                    <option value="90" <?php selected($selected_days, 90); ?>>90 <?php _e('days', 'vss'); ?></option>
                    <option value="180" <?php selected($selected_days, 180); ?>>180 <?php _e('days', 'vss'); ?></option>
                    <option value="365" <?php selected($selected_days, 365); ?>>365 <?php _e('days', 'vss'); ?></option>
                    <option value="0" <?php selected($selected_days, 0); ?>><?php _e('All Time', 'vss'); ?></option>
                </select>
                <input type="submit" class="button" value="<?php _e('Filter', 'vss'); ?>">
                <a href="<?php echo admin_url('admin.php?page=vss-payouts-report&export=csv'); ?>" class="button button-secondary">
                    <?php _e('Export CSV', 'vss'); ?>
                </a>
            </form>
            
            <p><?php 
                if ($selected_days > 0) { 
                    printf(__('Showing data for orders created in the last %d days.', 'vss'), $selected_days); 
                } else { 
                    _e('Showing data for all time.', 'vss'); 
                } 
            ?></p>
            
            <!-- Summary Cards -->
            <div class="vss-dashboard-widgets" style="margin: 20px 0;">
                <div class="vss-stat-box">
                    <div class="postbox-header">
                        <h2><span class="dashicons dashicons-groups"></span> <?php _e('Total Vendors', 'vss'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="stat-number"><?php echo count($vendors); ?></p>
                    </div>
                </div>
                
                <div class="vss-stat-box">
                    <div class="postbox-header">
                        <h2><span class="dashicons dashicons-cart"></span> <?php _e('Total Orders', 'vss'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="stat-number"><?php echo array_sum(array_column($payouts_data, 'orders')); ?></p>
                    </div>
                </div>
                
                <div class="vss-stat-box">
                    <div class="postbox-header">
                        <h2><span class="dashicons dashicons-money-alt"></span> <?php _e('Total Payouts', 'vss'); ?></h2>
                    </div>
                    <div class="inside">
                        <p class="stat-number"><?php echo wc_price(array_sum(array_column($payouts_data, 'total'))); ?></p>
                    </div>
                </div>
            </div>
            
            <table class="wp-list-table widefat striped vss-table">
                <thead>
                    <tr>
                        <th><?php _e('Vendor-MM', 'vss'); ?></th>
                        <th><?php _e('Orders in Period', 'vss'); ?></th>
                        <th><?php _e('Total Payout Due', 'vss'); ?></th>
                        <th><?php _e('Status', 'vss'); ?></th>
                        <th><?php _e('Actions', 'vss'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $grand_total = 0; ?>
                    <?php if (!empty($payouts_data)): ?>
                        <?php foreach ($payouts_data as $vendor_id => $payout_item): ?>
                            <?php
                            $current_payout_total = floatval($payout_item['total']);
                            $grand_total += $current_payout_total;
                            $current_payout_orders = intval($payout_item['orders']);
                            
                            // Skip vendors with no orders
                            if ($current_payout_orders === 0) continue;
                            ?>
                            <tr>
                                <td class="vendor-name"><?php echo esc_html($payout_item['name']); ?></td>
                                <td><?php echo $current_payout_orders; ?></td>
                                <td><?php echo wc_price($current_payout_total); ?></td>
                                <td>
                                    <?php if (!$payout_item['has_costs'] && $current_payout_orders > 0): ?>
                                        <span class="vss-status-badge" style="background-color:#ff9800;">
                                            <?php _e('Missing Costs', 'vss'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="vss-status-badge" style="background-color:#4CAF50;">
                                            <?php _e('Complete', 'vss'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('edit.php?post_type=shop_order&vss_vendor_filter=' . $vendor_id); ?>" 
                                       class="button button-small"><?php _e('View Orders', 'vss'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5"><?php _e('No payout data available for the selected period.', 'vss'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2"><strong><?php _e('Grand Total for Period', 'vss'); ?></strong></td>
                        <td colspan="3"><strong><?php echo wc_price($grand_total); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }
}

// New Analytics Class
class VSS_Analytics {
    
    public static function init() {
        add_action('vss_daily_analytics', [self::class, 'calculate_daily_analytics']);
        add_action('woocommerce_order_status_changed', [self::class, 'track_order_analytics'], 10, 4);
    }
    
    public static function calculate_daily_analytics() {
        global $wpdb;
        
        // Calculate vendor performance metrics
        $vendors = get_users(['role' => 'vendor-mm']);
        
        foreach ($vendors as $vendor) {
            $vendor_id = $vendor->ID;
            
            // Get orders from last 30 days
            $orders = wc_get_orders([
                'limit' => -1,
                'meta_key' => '_vss_vendor_user_id',
                'meta_value' => $vendor_id,
                'date_created' => '>=' . date('Y-m-d', strtotime('-30 days')),
                'return' => 'objects'
            ]);
            
            foreach ($orders as $order) {
                $order_id = $order->get_id();
                
                // Check if analytics already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}vss_vendor_analytics WHERE order_id = %d",
                    $order_id
                ));
                
                if (!$exists) {
                    $costs = get_post_meta($order_id, '_vss_order_costs', true);
                    $order_total = $order->get_total();
                    $vendor_cost = isset($costs['total_cost']) ? floatval($costs['total_cost']) : 0;
                    $profit_margin = ($order_total > 0 && $vendor_cost > 0) ? (($order_total - $vendor_cost) / $order_total * 100) : 0;
                    
                    // Calculate processing time
                    $created_date = $order->get_date_created();
                    $shipped_date = get_post_meta($order_id, '_vss_shipped_at', true);
                    $processing_time = ($shipped_date && $created_date) ? ($shipped_date - $created_date->getTimestamp()) / 86400 : 0;
                    
                    $wpdb->insert(
                        $wpdb->prefix . 'vss_vendor_analytics',
                        [
                            'vendor_id' => $vendor_id,
                            'order_id' => $order_id,
                            'order_total' => $order_total,
                            'vendor_cost' => $vendor_cost,
                            'profit_margin' => $profit_margin,
                            'processing_time' => $processing_time
                        ],
                        ['%d', '%d', '%f', '%f', '%f', '%d']
                    );
                }
            }
        }
    }
    
    public static function track_order_analytics($order_id, $old_status, $new_status, $order) {
        // Track status changes for analytics
        if ($new_status === 'shipped') {
            update_post_meta($order_id, '_vss_shipped_at', time());
        }
    }
}

// New AJAX Handler Class
class VSS_Ajax {
    
    public static function init() {
        // Additional AJAX handlers
        add_action('wp_ajax_vss_quick_approve', [self::class, 'handle_quick_approve']);
        add_action('wp_ajax_vss_bulk_assign_vendor', [self::class, 'handle_bulk_assign']);
    }
    
    public static function handle_approve_mockup() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'mockup';
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'vss')]);
        }
        
        // Update approval status
        update_post_meta($order_id, "_vss_{$type}_status", 'approved');
        update_post_meta($order_id, "_vss_{$type}_responded_at", time());
        
        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $type_label = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
            $order->add_order_note(sprintf(__('%s approved by admin via quick action.', 'vss'), $type_label));
            
            // Send vendor notification
            VSS_Emails::send_vendor_approval_confirmed_email($order_id, $type);
        }
        
        wp_send_json_success([
            'message' => __('Approved successfully', 'vss'),
            'status_html' => '<span class="vss-admin-status-badge" style="background-color:#4CAF50;">Approved</span>'
        ]);
    }
    
    public static function handle_disapprove_mockup() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'mockup';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'vss')]);
        }
        
        // Update approval status
        update_post_meta($order_id, "_vss_{$type}_status", 'disapproved');
        update_post_meta($order_id, "_vss_{$type}_responded_at", time());
        
        if ($reason) {
            update_post_meta($order_id, "_vss_{$type}_customer_notes", $reason);
        }
        
        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $type_label = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
            $note = sprintf(__('%s disapproved by admin via quick action.', 'vss'), $type_label);
            if ($reason) {
                $note .= ' ' . sprintf(__('Reason: %s', 'vss'), $reason);
            }
            $order->add_order_note($note);
            
            // Send admin notification
            VSS_Emails::send_admin_approval_disapproved_email($order_id, $type, $reason);
        }
        
        wp_send_json_success([
            'message' => __('Disapproved successfully', 'vss'),
            'status_html' => '<span class="vss-admin-status-badge" style="background-color:#F44336;">Disapproved</span>'
        ]);
    }
    
    public static function get_vendor_costs() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'vss')]);
        }
        
        $costs = get_post_meta($order_id, '_vss_order_costs', true);
        
        if (empty($costs) || !isset($costs['total_cost'])) {
            wp_send_json_error(['message' => __('No costs submitted by vendor yet', 'vss')]);
        }
        
        wp_send_json_success([
            'costs' => $costs,
            'formatted_total' => wc_price($costs['total_cost'])
        ]);
    }
    
    public static function handle_quick_approve() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $action_type = isset($_POST['action_type']) ? sanitize_key($_POST['action_type']) : '';
        
        if (!$order_id || !in_array($action_type, ['mockup', 'production_file'])) {
            wp_send_json_error(['message' => __('Invalid parameters', 'vss')]);
        }
        
        // Quick approve logic
        update_post_meta($order_id, "_vss_{$action_type}_status", 'approved');
        update_post_meta($order_id, "_vss_{$action_type}_responded_at", time());
        
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(sprintf(__('%s quick approved by admin.', 'vss'), ucfirst(str_replace('_', ' ', $action_type))));
        }
        
        wp_send_json_success(['message' => __('Approved successfully', 'vss')]);
    }
    
    public static function handle_bulk_assign() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_ids = isset($_POST['order_ids']) ? array_map('intval', $_POST['order_ids']) : [];
        $vendor_id = isset($_POST['vendor_id']) ? intval($_POST['vendor_id']) : 0;
        
        if (empty($order_ids) || !$vendor_id) {
            wp_send_json_error(['message' => __('Invalid parameters', 'vss')]);
        }
        
        $success_count = 0;
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                update_post_meta($order_id, '_vss_vendor_user_id', $vendor_id);
                update_post_meta($order_id, '_vss_assigned_at', time());
                $order->add_order_note(sprintf(__('Bulk assigned to vendor via admin action.', 'vss')));
                do_action('vss_order_assigned_to_vendor', $order_id, $vendor_id);
                $success_count++;
            }
        }
        
        wp_send_json_success([
            'message' => sprintf(__('%d orders assigned successfully', 'vss'), $success_count)
        ]);
    }
}

// Add this to the end of the file for the enhanced JS files
// Create these as separate files in your plugin structure:

// assets/js/vss-admin.js content:
/*
jQuery(document).ready(function($) {
    // Handle mockup approval
    $('.vss-approve-mockup').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var type = $button.data('type') || 'mockup';
        
        if (!confirm('Are you sure you want to approve this ' + type + '?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: vss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_approve_mockup',
                order_id: orderId,
                type: type,
                nonce: vss_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.replaceWith(response.data.status_html);
                    alert(response.data.message);
                } else {
                    alert('Error: ' + response.data.message);
                    $button.prop('disabled', false).text('Approve');
                }
            },
            error: function() {
                alert('AJAX error. Please try again.');
                $button.prop('disabled', false).text('Approve');
            }
        });
    });
    
    // Handle mockup disapproval
    $('.vss-disapprove-mockup').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var type = $button.data('type') || 'mockup';
        
        var reason = prompt('Please provide a reason for disapproval:');
        if (reason === null) {
            return;
        }
        
        $button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: vss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_disapprove_mockup',
                order_id: orderId,
                type: type,
                reason: reason,
                nonce: vss_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.replaceWith(response.data.status_html);
                    alert(response.data.message);
                } else {
                    alert('Error: ' + response.data.message);
                    $button.prop('disabled', false).text('Disapprove');
                }
            },
            error: function() {
                alert('AJAX error. Please try again.');
                $button.prop('disabled', false).text('Disapprove');
            }
        });
    });
    
    // Bulk actions
    $('#vss-bulk-assign').on('click', function(e) {
        e.preventDefault();
        
        var orderIds = [];
        $('.vss-order-checkbox:checked').each(function() {
            orderIds.push($(this).val());
        });
        
        if (orderIds.length === 0) {
            alert('Please select at least one order.');
            return;
        }
        
        var vendorId = $('#vss-bulk-vendor-select').val();
        if (!vendorId) {
            alert('Please select a vendor.');
            return;
        }
        
        $.ajax({
            url: vss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_bulk_assign_vendor',
                order_ids: orderIds,
                vendor_id: vendorId,
                nonce: vss_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                }
            }
        });
    });
    
    // Analytics Chart
    if ($('#vss-analytics-chart').length) {
        var ctx = document.getElementById('vss-analytics-chart').getContext('2d');
        var chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: vss_analytics_data.labels,
                datasets: [{
                    label: 'Orders',
                    data: vss_analytics_data.orders,
                    borderColor: 'rgb(75, 192, 192)',
                    tension: 0.1
                }, {
                    label: 'Revenue',
                    data: vss_analytics_data.revenue,
                    borderColor: 'rgb(255, 99, 132)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Vendor Performance Analytics'
                    }
                }
            }
        });
    }
});
*/

// assets/js/vss-frontend.js content:
/*
jQuery(document).ready(function($) {
    // Enhanced cost calculation
    function calculateTotalCostFrontend() {
        var total = 0;
        $('.vss-cost-input-fe').each(function() {
            var val = parseFloat($(this).val().replace(/,/g, '.').replace(/[^0-9\.]/g, ''));
            if (!isNaN(val)) { 
                total += val; 
            }
        });
        var currency_symbol = $('#vss-total-cost-display-fe').data('currency');
        var formatted_total = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        $('#vss-total-cost-display-fe').text(currency_symbol + formatted_total);
    }
    
    if ($('.vss-cost-input-fe').length) { 
        calculateTotalCostFrontend(); 
    }
    
    $('body').on('keyup change', '.vss-cost-input-fe', calculateTotalCostFrontend);
    
    // Enhanced tabs with animations
    $('.vss-order-tabs .nav-tab').on('click', function(e) {
        e.preventDefault();
        var targetTab = $(this).attr('href');
        
        $('.vss-order-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.vss-tab-content').fadeOut(200, function() {
            $(targetTab).fadeIn(200);
        });
        
        if (typeof(Storage) !== "undefined") { 
            localStorage.setItem("vssActiveOrderTab", targetTab); 
        }
    });
    
    // Initialize active tab
    if (typeof(Storage) !== "undefined") {
        var activeTab = localStorage.getItem("vssActiveOrderTab");
        if (activeTab && $('.vss-order-tabs a[href="' + activeTab + '"]').length) {
            $('.vss-order-tabs a[href="' + activeTab + '"]').click();
        } else if ($('.vss-order-tabs .nav-tab').length) {
            $('.vss-order-tabs .nav-tab').first().click();
        }
    } else if ($('.vss-order-tabs .nav-tab').length) {
        $('.vss-order-tabs .nav-tab').first().click();
    }
    
    // Enhanced datepicker
    if (typeof $.fn.datepicker === 'function') {
        $('.vss-datepicker-fe').datepicker({ 
            dateFormat: 'yy-mm-dd', 
            minDate: 0,
            beforeShowDay: function(date) {
                // Highlight weekends
                var day = date.getDay();
                return [(day != 0 && day != 6)];
            }
        });
    }
    
    // Form validation
    $('body').on('submit', 'form#vss_vendor_confirm_production_form', function(e) {
        if ($('#vss_vendor_estimated_ship_date').val() === '') {
            alert('Please select an estimated ship date before confirming.');
            e.preventDefault();
            return false;
        }
    });
    
    // File upload preview
    $('input[type="file"]').on('change', function(e) {
        var files = e.target.files;
        var $preview = $('<div class="vss-file-preview" style="margin-top: 10px;"></div>');
        
        $(this).siblings('.vss-file-preview').remove();
        
        for (var i = 0; i < files.length; i++) {
            var file = files[i];
            if (file.type.match('image.*')) {
                var reader = new FileReader();
                reader.onload = (function(theFile) {
                    return function(e) {
                        $preview.append('<img src="' + e.target.result + '" style="max-width: 100px; max-height: 100px; margin: 5px;" />');
                    };
                })(file);
                reader.readAsDataURL(file);
            } else {
                $preview.append('<div style="margin: 5px;">' + file.name + '</div>');
            }
        }
        
        $(this).after($preview);
    });
    
    // Auto-save draft
    var autoSaveTimer;
    $('.vss-auto-save').on('input', function() {
        clearTimeout(autoSaveTimer);
        var $form = $(this).closest('form');
        var $status = $('<span class="vss-save-status" style="margin-left: 10px; color: #666;">Saving...</span>');
        
        $(this).after($status);
        
        autoSaveTimer = setTimeout(function() {
            // Implement auto-save via AJAX
            $status.text('Saved').css('color', '#4CAF50');
            setTimeout(function() {
                $status.fadeOut(function() {
                    $(this).remove();
                });
            }, 2000);
        }, 1000);
    });
});
*/