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

// Debug mode
if ( ! defined( 'VSS_DEBUG' ) ) {
    define( 'VSS_DEBUG', WP_DEBUG );
}

/**
 * Check if WooCommerce is active
 */
function vss_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'vss_woocommerce_missing_notice' );
        return false;
    }
    return true;
}

/**
 * WooCommerce missing notice
 */
function vss_woocommerce_missing_notice() {
    ?>
    <div class="error notice">
        <p><?php esc_html_e( 'Vendor Order Manager requires WooCommerce to be installed and active.', 'vss' ); ?></p>
    </div>
    <?php
}

/**
 * Initialize plugin
 */
add_action( 'plugins_loaded', 'vss_init', 0 );
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

    // Include optional components if they exist
    if ( file_exists( VSS_PLUGIN_DIR . 'includes/class-vss-zakeke-api.php' ) ) {
        require_once VSS_PLUGIN_DIR . 'includes/class-vss-zakeke-api.php';
    }

    if ( file_exists( VSS_PLUGIN_DIR . 'includes/class-vss-external-orders.php' ) ) {
        require_once VSS_PLUGIN_DIR . 'includes/class-vss-external-orders.php';
    }

    // Initialize components
    add_action( 'init', [ 'VSS_Admin', 'init' ], 10 );
    add_action( 'init', [ 'VSS_Vendor', 'init' ], 10 );
    add_action( 'init', [ 'VSS_Customer', 'init' ], 10 );
    add_action( 'init', [ 'VSS_Notifications', 'init' ], 10 );

    if ( class_exists( 'VSS_External_Orders' ) ) {
        add_action( 'init', [ 'VSS_External_Orders', 'init' ], 10 );
    }
}

/**
 * Enqueue frontend assets
 *
 * This handles global frontend assets. Component-specific assets
 * should be enqueued by their respective classes.
 */
add_action( 'wp_enqueue_scripts', 'vss_enqueue_frontend_assets', 20 );
function vss_enqueue_frontend_assets() {
    // Always enqueue global frontend styles
    wp_enqueue_style(
        'vss-frontend-global',
        VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css',
        [],
        VSS_VERSION
    );

    // Check if we need vendor-specific scripts
    if ( vss_should_load_vendor_scripts() ) {
        vss_enqueue_vendor_scripts();
    }

    // Check if we need customer-specific scripts
    if ( vss_should_load_customer_scripts() ) {
        vss_enqueue_customer_scripts();
    }
}

/**
 * Check if vendor scripts should be loaded
 */
function vss_should_load_vendor_scripts() {
    global $post;

    if ( ! is_page() || ! $post ) {
        return false;
    }

    // Check for vendor portal shortcode
    if ( has_shortcode( $post->post_content, 'vss_vendor_portal' ) ) {
        return true;
    }

    // Check if we're on the vendor portal page
    $vendor_portal_page_id = get_option( 'vss_vendor_portal_page_id' );
    if ( $vendor_portal_page_id && $post->ID == $vendor_portal_page_id ) {
        return true;
    }

    // Check for other vendor shortcodes
    $vendor_shortcodes = [ 'vss_vendor_stats', 'vss_vendor_earnings', 'vss_vendor_orders' ];
    foreach ( $vendor_shortcodes as $shortcode ) {
        if ( has_shortcode( $post->post_content, $shortcode ) ) {
            return true;
        }
    }

    return false;
}

/**
 * Check if customer scripts should be loaded
 */
function vss_should_load_customer_scripts() {
    global $post;

    if ( ! is_page() || ! $post ) {
        return false;
    }

    // Check for customer shortcodes
    $customer_shortcodes = [ 'vss_customer_approval', 'vss_track_order' ];
    foreach ( $customer_shortcodes as $shortcode ) {
        if ( has_shortcode( $post->post_content, $shortcode ) ) {
            return true;
        }
    }

    // Check if we're on customer pages
    $customer_pages = [
        get_option( 'vss_customer_approval_page_id' ),
        get_option( 'vss_track_order_page_id' )
    ];

    return in_array( $post->ID, array_filter( $customer_pages ) );
}

/**
 * Enqueue vendor-specific scripts
 */
function vss_enqueue_vendor_scripts() {
    // Don't enqueue if already done by VSS_Vendor class
    if ( wp_script_is( 'vss-frontend', 'enqueued' ) ) {
        return;
    }

    // jQuery UI for datepicker
    wp_enqueue_script( 'jquery-ui-datepicker' );
    wp_enqueue_style(
        'jquery-ui-datepicker',
        'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css',
        [],
        '1.12.1'
    );

    // Main frontend script
    wp_enqueue_script(
        'vss-frontend',
        VSS_PLUGIN_URL . 'assets/js/vss-frontend-scripts.js',
        [ 'jquery', 'jquery-ui-datepicker' ],
        VSS_VERSION,
        true
    );

    // Localize script
    wp_localize_script( 'vss-frontend', 'vss_frontend_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'vss_frontend_nonce' ),
        'debug' => VSS_DEBUG,
        'version' => VSS_VERSION,
        'currency_symbol' => get_woocommerce_currency_symbol(),
        'date_format' => get_option( 'date_format' ),
        'i18n' => [
            'loading' => __( 'Loading...', 'vss' ),
            'error' => __( 'An error occurred', 'vss' ),
            'confirm' => __( 'Are you sure?', 'vss' ),
            'save' => __( 'Save', 'vss' ),
            'cancel' => __( 'Cancel', 'vss' ),
            'close' => __( 'Close', 'vss' ),
        ]
    ] );
}

/**
 * Enqueue customer-specific scripts
 */
function vss_enqueue_customer_scripts() {
    // Customer approval scripts
    wp_enqueue_script(
        'vss-customer',
        VSS_PLUGIN_URL . 'assets/js/vss-customer-scripts.js',
        [ 'jquery' ],
        VSS_VERSION,
        true
    );

    // Localize script
    wp_localize_script( 'vss-customer', 'vss_customer_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'vss_customer_nonce' ),
    ] );
}

/**
 * Enqueue admin assets
 */
add_action( 'admin_enqueue_scripts', 'vss_enqueue_admin_assets' );
function vss_enqueue_admin_assets( $hook ) {
    // Global admin styles
    wp_enqueue_style(
        'vss-global-admin',
        VSS_PLUGIN_URL . 'assets/css/vss-global.css',
        [],
        VSS_VERSION
    );

    // Check if we're on a VSS admin page
    $is_vss_page = strpos( $hook, 'vss' ) !== false;
    $is_order_page = in_array( $hook, [ 'post.php', 'post-new.php' ] ) &&
                     isset( $_GET['post'] ) &&
                     get_post_type( $_GET['post'] ) === 'shop_order';

    if ( $is_vss_page || $is_order_page || $hook === 'edit.php' ) {
        // Admin-specific styles
        wp_enqueue_style(
            'vss-admin-styles',
            VSS_PLUGIN_URL . 'assets/css/vss-admin-styles.css',
            [],
            VSS_VERSION
        );

        // Admin scripts
        wp_enqueue_script(
            'vss-admin-scripts',
            VSS_PLUGIN_URL . 'assets/js/vss-admin-scripts.js',
            [ 'jquery', 'jquery-ui-datepicker' ],
            VSS_VERSION,
            true
        );

        // Localize admin script
        wp_localize_script( 'vss-admin-scripts', 'vss_admin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'vss_admin_nonce' ),
            'post_id' => isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0,
            'debug' => VSS_DEBUG,
        ] );

        // jQuery UI styles for admin
        wp_enqueue_style(
            'jquery-ui-admin',
            'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css',
            [],
            '1.12.1'
        );
    }
}

/**
 * Register custom order statuses
 */
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

    // Register "Approved" status
    register_post_status( 'wc-approved', [
        'label' => _x( 'Approved', 'Order status', 'vss' ),
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop( 'Approved <span class="count">(%s)</span>', 'Approved <span class="count">(%s)</span>', 'vss' ),
    ] );

    // Register "Disapproved" status
    register_post_status( 'wc-disapproved', [
        'label' => _x( 'Disapproved', 'Order status', 'vss' ),
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop( 'Disapproved <span class="count">(%s)</span>', 'Disapproved <span class="count">(%s)</span>', 'vss' ),
    ] );
}

/**
 * Add custom statuses to WooCommerce
 */
add_filter( 'wc_order_statuses', 'vss_add_custom_order_statuses' );
function vss_add_custom_order_statuses( $order_statuses ) {
    $new_order_statuses = [];

    // Add new statuses after "processing"
    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;

        if ( 'wc-processing' === $key ) {
            $new_order_statuses['wc-approved'] = _x( 'Approved', 'Order status', 'vss' );
            $new_order_statuses['wc-disapproved'] = _x( 'Disapproved', 'Order status', 'vss' );
            $new_order_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'vss' );
            $new_order_statuses['wc-delivered'] = _x( 'Delivered', 'Order status', 'vss' );
        }
    }

    return $new_order_statuses;
}

/**
 * Plugin activation
 */
register_activation_hook( __FILE__, 'vss_activate' );
function vss_activate() {
    // Create vendor role
    add_role( 'vendor-mm', __( 'Vendor MM', 'vss' ), [
        'read' => true,
        'upload_files' => true,
        'vendor-mm' => true,
        'manage_vendor_orders' => true,
        'edit_posts' => false,
        'delete_posts' => false,
        'publish_posts' => false,
    ] );

    // Add capabilities to admin
    $admin_role = get_role( 'administrator' );
    if ( $admin_role ) {
        $admin_role->add_cap( 'manage_vendor_orders' );
        $admin_role->add_cap( 'manage_all_vendor_orders' );
    }

    // Create database tables for external orders if class exists
    if ( class_exists( 'VSS_External_Orders' ) ) {
        VSS_External_Orders::create_tables();
    }

    // Create pages
    vss_create_pages();

    // Schedule cron events
    if ( ! wp_next_scheduled( 'vss_daily_tasks' ) ) {
        wp_schedule_event( time(), 'daily', 'vss_daily_tasks' );
    }

    // Clear permalinks
    flush_rewrite_rules();
}

/**
 * Plugin deactivation
 */
register_deactivation_hook( __FILE__, 'vss_deactivate' );
function vss_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook( 'vss_daily_tasks' );
    wp_clear_scheduled_hook( 'vss_import_external_orders_cron' );

    // Clear any transients
    delete_transient( 'vss_activation_redirect' );

    // Clear permalinks
    flush_rewrite_rules();
}

/**
 * Create required pages
 */
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
        'vendor-application' => [
            'title' => __( 'Become a Vendor', 'vss' ),
            'content' => '[vss_vendor_application]',
            'option' => 'vss_vendor_application_page_id',
        ],
    ];

    foreach ( $pages as $slug => $page ) {
        $page_id = get_option( $page['option'] );

        // Check if page exists
        if ( ! $page_id || ! get_post( $page_id ) ) {
            $page_id = wp_insert_post( [
                'post_title' => $page['title'],
                'post_content' => $page['content'],
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => $slug,
                'comment_status' => 'closed',
            ] );

            if ( ! is_wp_error( $page_id ) ) {
                update_option( $page['option'], $page_id );
            }
        }
    }
}

/**
 * Add settings link to plugins page
 */
add_filter( 'plugin_action_links_' . VSS_PLUGIN_BASENAME, 'vss_add_plugin_action_links' );
function vss_add_plugin_action_links( $links ) {
    $action_links = [
        '<a href="' . admin_url( 'admin.php?page=vss-settings' ) . '">' . __( 'Settings', 'vss' ) . '</a>',
        '<a href="' . admin_url( 'admin.php?page=vss-vendors' ) . '">' . __( 'Vendors', 'vss' ) . '</a>',
    ];

    return array_merge( $action_links, $links );
}

/**
 * Redirect after activation
 */
add_action( 'admin_init', 'vss_activation_redirect' );
function vss_activation_redirect() {
    if ( get_transient( 'vss_activation_redirect' ) ) {
        delete_transient( 'vss_activation_redirect' );

        if ( ! isset( $_GET['activate-multi'] ) ) {
            wp_redirect( admin_url( 'admin.php?page=vss-settings' ) );
            exit;
        }
    }
}

/**
 * Check plugin dependencies
 */
add_action( 'admin_init', 'vss_check_dependencies' );
function vss_check_dependencies() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        deactivate_plugins( VSS_PLUGIN_BASENAME );

        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'Vendor Order Manager has been deactivated because WooCommerce is not active. Please install and activate WooCommerce first.', 'vss' ); ?></p>
            </div>
            <?php
        } );

        if ( isset( $_GET['activate'] ) ) {
            unset( $_GET['activate'] );
        }
    }
}

/**
 * Custom cron schedules
 */
add_filter( 'cron_schedules', 'vss_add_cron_schedules' );
function vss_add_cron_schedules( $schedules ) {
    $schedules['vss_hourly'] = [
        'interval' => HOUR_IN_SECONDS,
        'display' => __( 'Once Hourly', 'vss' ),
    ];

    $schedules['vss_every_5_minutes'] = [
        'interval' => 5 * MINUTE_IN_SECONDS,
        'display' => __( 'Every 5 Minutes', 'vss' ),
    ];

    return $schedules;
}

/**
 * Daily maintenance tasks
 */
add_action( 'vss_daily_tasks', 'vss_run_daily_tasks' );
function vss_run_daily_tasks() {
    // Clean up old transients
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vss_%' AND option_value < " . time() );

    // Check for late orders and notify vendors
    if ( class_exists( 'VSS_Notifications' ) ) {
        VSS_Notifications::check_late_orders();
    }

    // Clean up old notification logs
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_vss_notification_%' AND meta_value < " . ( time() - 30 * DAY_IN_SECONDS ) );
}

/**
 * Add body classes for VSS pages
 */
add_filter( 'body_class', 'vss_body_classes' );
function vss_body_classes( $classes ) {
    global $post;

    if ( ! is_page() || ! $post ) {
        return $classes;
    }

    // Add class for vendor portal
    $vendor_portal_page_id = get_option( 'vss_vendor_portal_page_id' );
    if ( $vendor_portal_page_id && $post->ID == $vendor_portal_page_id ) {
        $classes[] = 'vss-vendor-portal-page';
    }

    // Add class for customer approval
    $customer_approval_page_id = get_option( 'vss_customer_approval_page_id' );
    if ( $customer_approval_page_id && $post->ID == $customer_approval_page_id ) {
        $classes[] = 'vss-customer-approval-page';
    }

    // Add debug class
    if ( VSS_DEBUG ) {
        $classes[] = 'vss-debug-mode';
    }

    return $classes;
}

/**
 * Log debug messages
 */
function vss_log( $message, $level = 'info' ) {
    if ( ! VSS_DEBUG ) {
        return;
    }

    if ( is_array( $message ) || is_object( $message ) ) {
        $message = print_r( $message, true );
    }

    $log_entry = sprintf( '[%s] [%s] %s', date( 'Y-m-d H:i:s' ), strtoupper( $level ), $message );

    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( 'VSS: ' . $log_entry );
    }
}

/**
 * Get plugin version
 */
function vss_get_version() {
    return VSS_VERSION;
}

/**
 * Check if current user is vendor
 */
function vss_is_vendor( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    $user = get_user_by( 'id', $user_id );
    return $user && in_array( 'vendor-mm', (array) $user->roles, true );
}

/**
 * Get vendor portal URL
 */
function vss_get_vendor_portal_url() {
    $page_id = get_option( 'vss_vendor_portal_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/vendor-portal/' );
}

/**
 * Get customer approval URL for an order
 */
function vss_get_customer_approval_url( $order_id, $key = '' ) {
    $page_id = get_option( 'vss_customer_approval_page_id' );
    $base_url = $page_id ? get_permalink( $page_id ) : home_url( '/customer-approval/' );

    return add_query_arg( [
        'order_id' => $order_id,
        'key' => $key ?: get_post_meta( $order_id, '_vss_approval_key', true ),
    ], $base_url );
}

// Initialize the plugin
vss_log( 'Vendor Order Manager v' . VSS_VERSION . ' initializing...', 'info' );