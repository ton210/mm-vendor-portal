<?php
/**
 * VSS Vendor Class - Modular Version
 *
 * Main wrapper class that includes all vendor functionality modules.
 * This file loads all the modular components and maintains backward compatibility.
 *
 * @package VendorOrderManager
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load all vendor modules
$modules_dir = plugin_dir_path( __FILE__ ) . 'vendor-modules/';

// Include all module files
require_once $modules_dir . 'class-vss-vendor-init.php';
require_once $modules_dir . 'class-vss-vendor-auth.php';
require_once $modules_dir . 'class-vss-vendor-admin-menu.php';
require_once $modules_dir . 'class-vss-vendor-dashboard.php';
require_once $modules_dir . 'class-vss-vendor-orders.php';
require_once $modules_dir . 'class-vss-vendor-order-details.php';
require_once $modules_dir . 'class-vss-vendor-forms.php';
require_once $modules_dir . 'class-vss-vendor-ajax.php';
require_once $modules_dir . 'class-vss-vendor-shortcodes.php';
require_once $modules_dir . 'class-vss-vendor-assets.php';
require_once $modules_dir . 'class-vss-vendor-utilities.php';

/**
 * Main VSS Vendor Class
 * 
 * This class uses traits to organize functionality into logical modules
 * while maintaining the same public API.
 */
class VSS_Vendor {

    // Include all functionality via traits
    use VSS_Vendor_Initialization;
    use VSS_Vendor_Authentication;
    use VSS_Vendor_Admin_Menu;
    use VSS_Vendor_Dashboard;
    use VSS_Vendor_Orders;
    use VSS_Vendor_Order_Details;
    use VSS_Vendor_Forms;
    use VSS_Vendor_Ajax;
    use VSS_Vendor_Shortcodes;
    use VSS_Vendor_Assets;
    use VSS_Vendor_Utilities;

}


// =========================================================================
// HELPER FUNCTIONS & HOOKS
// =========================================================================




// =========================================================================
// HELPER FUNCTIONS & HOOKS
// =========================================================================

/**
 * Add filter to optimize vendor order queries
 */
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'vss_optimize_vendor_order_query', 10, 2 );
function vss_optimize_vendor_order_query( $query, $query_vars ) {
    // Only optimize if searching for vendor orders
    if ( isset( $query_vars['meta_key'] ) && $query_vars['meta_key'] === '_vss_vendor_user_id' ) {
        // Force index usage for better performance
        add_filter( 'posts_clauses', 'vss_force_meta_index', 10, 2 );
    }
    return $query;
}

/**
 * Force the query to use a specific index on the postmeta table.
 */
function vss_force_meta_index( $clauses, $wp_query ) {
    global $wpdb;

    // Add index hint for meta queries
    $clauses['join'] = str_replace(
        "INNER JOIN {$wpdb->postmeta}",
        "INNER JOIN {$wpdb->postmeta} USE INDEX (meta_key)",
        $clauses['join']
    );

    // Remove this filter after use to avoid affecting other queries
    remove_filter( 'posts_clauses', 'vss_force_meta_index', 10, 2 );

    return $clauses;
}

/**
 * Add custom CSS for the vendor orders page for better UI/UX.
 */
add_action( 'admin_head', 'vss_vendor_orders_custom_css' );
function vss_vendor_orders_custom_css() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'vss-vendor-orders' ) {
        ?>
        <style>
            /* Enhanced order status styles */
            .vss-vendor-orders-table .order-status {
                display: inline-block;
                line-height: 2.5em;
                color: #777;
                background: #e5e5e5;
                border-radius: 4px;
                border-bottom: 1px solid rgba(0,0,0,0.05);
                margin: -0.25em 0;
                cursor: inherit !important;
                font-weight: 500;
                padding: 0 1em;
            }

            .vss-vendor-orders-table .order-status.status-processing {
                background: #f8dda7;
                color: #94660c;
            }

            .vss-vendor-orders-table .order-status.status-shipped {
                background: #c8e6c9;
                color: #2e7d32;
            }

            .vss-vendor-orders-table .order-status.status-completed {
                background: #d4edda;
                color: #155724;
            }

            .vss-vendor-orders-table .order-status.status-pending {
                background: #ccc;
                color: #666;
            }

            /* Hover effects */
            .vss-vendor-orders-table tbody tr:hover {
                transform: scale(1.01);
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                transition: all 0.2s ease-in-out;
            }

            /* Make processing orders stand out more */
            .vss-vendor-orders-table tbody tr.status-processing {
                position: relative;
            }

            .vss-vendor-orders-table tbody tr.status-processing::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 4px;
                background: #ff9800;
            }

            /* Late orders alert */
            .vss-vendor-orders-table tbody tr.status-late {
                position: relative;
            }

            .vss-vendor-orders-table tbody tr.status-late::before {
                content: '';
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                width: 4px;
                background: #f44336;
            }

            /* Better search box */
            .search-box input[type="search"] {
                padding: 8px 12px;
                font-size: 14px;
                border: 2px solid #ddd;
                border-radius: 4px;
                transition: all 0.3s;
            }

            .search-box input[type="search"]:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.1);
            }

            /* Status filter enhancement */
            .subsubsub li a {
                padding: 5px 10px;
                border-radius: 3px;
                transition: all 0.2s;
            }

            .subsubsub li a:hover {
                background: #f0f0f0;
            }

            .subsubsub li a.current {
                background: #2271b1;
                color: white;
                font-weight: 600;
            }

            /* Pagination improvements */
            .tablenav-pages a, .tablenav-pages span.current {
                display: inline-block;
                padding: 4px 12px;
                margin: 0 2px;
                border: 1px solid #ddd;
                border-radius: 3px;
                text-decoration: none;
                transition: all 0.2s;
            }

            .tablenav-pages a:hover {
                background: #2271b1;
                color: white;
                border-color: #2271b1;
            }

            .tablenav-pages span.current {
                background: #555;
                color: white;
                border-color: #555;
            }
        </style>
        <?php
    }
}

/**
 * Add keyboard shortcuts and auto-refresh functionality to the vendor orders page.
 */
add_action( 'admin_footer', 'vss_vendor_orders_keyboard_shortcuts' );
function vss_vendor_orders_keyboard_shortcuts() {
    if ( isset( $_GET['page'] ) && $_GET['page'] === 'vss-vendor-orders' ) {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Alt + N = Next page
                if (e.altKey && e.keyCode === 78) {
                    e.preventDefault();
                    $('.next-page:not(.disabled)').click();
                }

                // Alt + P = Previous page
                if (e.altKey && e.keyCode === 80) {
                    e.preventDefault();
                    $('.prev-page:not(.disabled)').click();
                }

                // Alt + S = Focus search
                if (e.altKey && e.keyCode === 83) {
                    e.preventDefault();
                    $('#order-search-input').focus();
                }

                // Alt + A = Select all
                if (e.altKey && e.keyCode === 65) {
                    e.preventDefault();
                    $('#cb-select-all-1').click();
                }
            });

            // Add tooltips
            $('.next-page').attr('title', 'Next Page (Alt+N)');
            $('.prev-page').attr('title', 'Previous Page (Alt+P)');
            $('#order-search-input').attr('title', 'Search Orders (Alt+S)');
            $('#cb-select-all-1').attr('title', 'Select All (Alt+A)');

            // Auto-refresh every 5 minutes for processing orders
            if ($('tr.status-processing').length > 0) {
                setTimeout(function() {
                    if (confirm('Refresh page to see latest orders?')) {
                        location.reload();
                    }
                }, 300000); // 5 minutes
            }
        });
        </script>
        <?php
    }
}

/**
 * Create a database index for better vendor order query performance.
 * This should be run once, e.g., on plugin activation.
 */
function vss_create_vendor_order_indexes() {
    global $wpdb;

    // Check if index already exists
    $index_exists = $wpdb->get_var("
        SELECT COUNT(1)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE table_schema = DATABASE()
        AND table_name = '{$wpdb->postmeta}'
        AND index_name = 'vss_vendor_lookup'
    ");

    if ( ! $index_exists ) {
        // Create composite index for vendor queries
        // Indexing the first 10 characters of meta_value is usually sufficient for user IDs.
        $wpdb->query("
            CREATE INDEX vss_vendor_lookup
            ON {$wpdb->postmeta} (meta_key, meta_value(10))
        ");
    }
}

// Run index creation on plugin activation
if ( defined( 'VSS_PLUGIN_FILE' ) ) {
    register_activation_hook( VSS_PLUGIN_FILE, 'vss_create_vendor_order_indexes' );
}

/**
 * Optional: Add logging for download attempts
 */
add_action( 'wp_ajax_vss_log_download_attempt', function() {
    check_ajax_referer( 'vss_frontend_nonce', '_ajax_nonce' );

    $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
    $file_id = isset( $_POST['file_id'] ) ? intval( $_POST['file_id'] ) : 0;

    if ( $order_id && $file_id ) {
        // Log the download attempt
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $user = wp_get_current_user();
            $order->add_order_note( sprintf(
                __( 'Download attempt for admin ZIP file by %s', 'vss' ),
                $user->display_name
            ) );
        }
    }

    wp_send_json_success();
});
שׂײַשׁ֜;צח;ז מת;תצם98ר
