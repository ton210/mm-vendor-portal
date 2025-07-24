<?php
/**
 * Plugin Name: Vendor Order Manager
 * Plugin URI: https://example.com/vendor-order-manager
 * Description: Comprehensive vendor management system for WooCommerce with approval workflows
 * Version: 7.0.0
 * Author: Your Company
 * Author URI: https://example.com
 * Text Domain: vss
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'VSS_VERSION', '7.0.0' );
define( 'VSS_PLUGIN_FILE', __FILE__ );
define( 'VSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Check if WooCommerce is active
function vss_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'vss_woocommerce_missing_notice' );
        return false;
    }
    return true;
}

// WooCommerce missing notice
function vss_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e( 'Vendor Order Manager requires WooCommerce to be installed and active.', 'vss' ); ?></p>
    </div>
    <?php
}

// Initialize plugin
add_action( 'plugins_loaded', 'vss_init' );
function vss_init() {
    if ( ! vss_check_woocommerce() ) {
        return;
    }

    // Load text domain
    load_plugin_textdomain( 'vss', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    // Include required files
    require_once VSS_PLUGIN_DIR . 'includes/class-vss-admin.php';
    require_once VSS_PLUGIN_DIR . 'includes/class-vss-vendor.php';
    require_once VSS_PLUGIN_DIR . 'includes/class-vss-customer.php';
    require_once VSS_PLUGIN_DIR . 'includes/class-vss-notifications.php';
    require_once VSS_PLUGIN_DIR . 'includes/class-vss-zakeke-api.php';
    require_once VSS_PLUGIN_DIR . 'includes/class-vss-external-orders.php';

    // Initialize components
    add_action( 'init', [ 'VSS_Admin', 'init' ] );
    add_action( 'init', [ 'VSS_Vendor', 'init' ] );
    add_action( 'init', [ 'VSS_Customer', 'init' ] );
    add_action( 'init', [ 'VSS_Notifications', 'init' ] );
    add_action( 'init', [ 'VSS_External_Orders', 'init' ] );
}

// Enqueue global styles and scripts
add_action( 'wp_enqueue_scripts', 'vss_enqueue_frontend_assets' );
function vss_enqueue_frontend_assets() {
    // Frontend styles
    wp_enqueue_style( 
        'vss-frontend-styles', 
        VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css', 
        [], 
        VSS_VERSION 
    );

    // Frontend scripts
    wp_enqueue_script( 
        'vss-frontend-scripts', 
        VSS_PLUGIN_URL . 'assets/js/vss-frontend-scripts.js', 
        [ 'jquery' ], 
        VSS_VERSION, 
        true 
    );

    // Localize script
    wp_localize_script( 'vss-frontend-scripts', 'vss_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'vss_frontend_nonce' ),
    ] );
}

// Enqueue admin styles and scripts
add_action( 'admin_enqueue_scripts', 'vss_enqueue_admin_assets' );
function vss_enqueue_admin_assets( $hook ) {
    // Global admin styles
    wp_enqueue_style( 
        'vss-global-styles', 
        VSS_PLUGIN_URL . 'assets/css/vss-global.css', 
        [], 
        VSS_VERSION 
    );

    // Admin-specific styles and scripts
    if ( strpos( $hook, 'vss' ) !== false || $hook === 'edit.php' || $hook === 'post.php' ) {
        wp_enqueue_style( 
            'vss-admin-styles', 
            VSS_PLUGIN_URL . 'assets/css/vss-admin-styles.css', 
            [], 
            VSS_VERSION 
        );

        wp_enqueue_script( 
            'vss-admin-scripts', 
            VSS_PLUGIN_URL . 'assets/js/vss-admin-scripts.js', 
            [ 'jquery' ], 
            VSS_VERSION, 
            true 
        );

        wp_localize_script( 'vss-admin-scripts', 'vss_admin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'vss_admin_nonce' ),
        ] );
    }
}

// Add custom order statuses
add_action( 'init', 'vss_register_custom_order_statuses' );
function vss_register_custom_order_statuses() {
    // Register "Shipped" status
    register_post_status( 'wc-shipped', [
        'label' => _x( 'Shipped', 'Order status', 'vss' ),
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'vss' ),
    ] );

    // Register "Delivered" status
    register_post_status( 'wc-delivered', [
        'label' => _x( 'Delivered', 'Order status', 'vss' ),
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop( 'Delivered <span class="count">(%s)</span>', 'Delivered <span class="count">(%s)</span>', 'vss' ),
    ] );
}

// Add custom statuses to WooCommerce
add_filter( 'wc_order_statuses', 'vss_add_custom_order_statuses' );
function vss_add_custom_order_statuses( $order_statuses ) {
    $new_order_statuses = [];

    // Add new statuses after "processing"
    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;

        if ( 'wc-processing' === $key ) {
            $new_order_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'vss' );
            $new_order_statuses['wc-delivered'] = _x( 'Delivered', 'Order status', 'vss' );
        }
    }

    return $new_order_statuses;
}

// Activation hook
register_activation_hook( __FILE__, 'vss_activate' );
function vss_activate() {
    // Create vendor role
    add_role( 'vendor-mm', __( 'Vendor MM', 'vss' ), [
        'read' => true,
        'upload_files' => true,
        'vendor-mm' => true,
        'manage_vendor_orders' => true,
    ] );
    
    // Create database tables for external orders
    require_once VSS_PLUGIN_DIR . 'includes/class-vss-external-orders.php';
    VSS_External_Orders::create_tables();
    
    // Create pages
    vss_create_pages();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook( __FILE__, 'vss_deactivate' );
function vss_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook( 'vss_import_external_orders_cron' );
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Create required pages
function vss_create_pages() {
    $pages = [
        'vendor-portal' => [
            'title' => __( 'Vendor Portal', 'vss' ),
            'content' => '[vss_vendor_portal]',
            'option' => 'vss_vendor_portal_page_id',
        ],
        'customer-approval' => [
            'title' => __( 'Customer Approval', 'vss' ),
            'content' => '[vss_customer_approval]',
            'option' => 'vss_customer_approval_page_id',
        ],
        'track-order' => [
            'title' => __( 'Track Order', 'vss' ),
            'content' => '[vss_track_order]',
            'option' => 'vss_track_order_page_id',
        ],
    ];

    foreach ( $pages as $slug => $page ) {
        $page_id = get_option( $page['option'] );
        
        if ( ! $page_id || ! get_post( $page_id ) ) {
            $page_id = wp_insert_post( [
                'post_title' => $page['title'],
                'post_content' => $page['content'],
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => $slug,
            ] );

            if ( ! is_wp_error( $page_id ) ) {
                update_option( $page['option'], $page_id );
            }
        }
    }
}

// Add settings link to plugins page
add_filter( 'plugin_action_links_' . VSS_PLUGIN_BASENAME, 'vss_add_settings_link' );
function vss_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=vss-settings' ) . '">' . __( 'Settings', 'vss' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

// Add custom capabilities to admin role
add_action( 'admin_init', 'vss_add_admin_capabilities' );
function vss_add_admin_capabilities() {
    $role = get_role( 'administrator' );
    if ( $role ) {
        $role->add_cap( 'manage_vendor_orders' );
    }
}

// Check plugin dependencies
add_action( 'admin_init', 'vss_check_dependencies' );
function vss_check_dependencies() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( VSS_PLUGIN_BASENAME );
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'Vendor Order Manager has been deactivated because WooCommerce is not active.', 'vss' ); ?></p>
            </div>
            <?php
        } );
    }
}

// Custom cron schedules
add_filter( 'cron_schedules', 'vss_add_cron_schedules' );
function vss_add_cron_schedules( $schedules ) {
    $schedules['vss_hourly'] = [
        'interval' => HOUR_IN_SECONDS,
        'display' => __( 'Once Hourly', 'vss' ),
    ];
    
    return $schedules;
}