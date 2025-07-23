<?php
/**
 * VSS Vendor Class - FIXED VERSION
 *
 * Handles vendor portal functionality and vendor-specific features
 * Fixed: Vendor order access and added dedicated vendor orders page
 *
 * @package VendorOrderManager
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_Vendor {

    /**
     * Initialize vendor functionality
     */
    public static function init() {
        // Shortcodes
        add_shortcode( 'vss_vendor_portal', [ self::class, 'render_vendor_portal_shortcode' ] );
        add_shortcode( 'vss_vendor_stats', [ self::class, 'render_vendor_stats_shortcode' ] );
        add_shortcode( 'vss_vendor_earnings', [ self::class, 'render_vendor_earnings_shortcode' ] );
        
        // Frontend forms
        add_action( 'template_redirect', [ self::class, 'handle_frontend_forms' ] );
        
        // Login redirect
        add_filter( 'login_redirect', [ self::class, 'vendor_login_redirect' ], 9999, 3 );
        
        // Admin area restrictions - FIXED
        add_action( 'admin_menu', [ self::class, 'setup_vendor_admin_menu' ], 999 );
        add_action( 'admin_init', [ self::class, 'restrict_admin_access' ] );
        
        // Ensure vendors can access admin - CRITICAL FIX
        add_filter( 'user_has_cap', [ self::class, 'vendor_admin_access' ], 10, 4 );
        add_action( 'init', [ self::class, 'prevent_vendor_redirect' ], 1 );
        
        // AJAX handlers
        add_action( 'wp_ajax_vss_manual_fetch_zip', [ self::class, 'ajax_manual_fetch_zakeke_zip' ] );
        add_action( 'wp_ajax_vss_save_draft', [ self::class, 'ajax_save_draft' ] );
        add_action( 'wp_ajax_vss_get_order_details', [ self::class, 'ajax_get_order_details' ] );
        add_action( 'wp_ajax_nopriv_vss_track_order', [ self::class, 'ajax_track_order' ] );
        
        // Debug AJAX handler for assigning orders
        add_action( 'wp_ajax_assign_order_to_vendor', [ self::class, 'ajax_assign_order_to_vendor' ] );
        
        // Vendor dashboard widgets
        add_action( 'wp_dashboard_setup', [ self::class, 'add_vendor_dashboard_widgets' ] );
        
        // Setup vendor roles and capabilities
        add_action( 'init', [ self::class, 'setup_vendor_capabilities' ] );
        
        // Admin notices for setup
        add_action( 'admin_notices', [ self::class, 'vendor_setup_notices' ] );
        
        // Profile fields
        add_action( 'show_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
        add_action( 'edit_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
        add_action( 'personal_options_update', [ self::class, 'save_vendor_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ self::class, 'save_vendor_profile_fields' ] );
    }

    /**
     * Prevent vendor redirect - CRITICAL FIX
     */
    public static function prevent_vendor_redirect() {
        // If we're in admin and user is vendor, prevent any redirects
        if ( is_admin() && self::is_current_user_vendor() ) {
            // Remove any filters that might redirect vendors
            remove_all_filters( 'wp_redirect' );
            remove_all_actions( 'template_redirect' );
            
            // Add our own redirect handler
            add_filter( 'wp_redirect', [ self::class, 'handle_vendor_redirects' ], 1, 2 );
        }
    }

    /**
     * Handle vendor redirects
     */
    public static function handle_vendor_redirects( $location, $status ) {
        if ( ! self::is_current_user_vendor() ) {
            return $location;
        }

        // If redirecting to my-account, redirect to vendor dashboard instead
        if ( strpos( $location, '/my-account' ) !== false || strpos( $location, 'myaccount' ) !== false ) {
            return admin_url( 'admin.php?page=vss-vendor-dashboard' );
        }

        // If trying to access vendor pages, allow it
        if ( strpos( $location, 'page=vss-vendor-' ) !== false ) {
            return $location;
        }

        // Allow admin URLs for vendors
        if ( strpos( $location, '/wp-admin/' ) !== false ) {
            return $location;
        }

        return $location;
    }

    /**
     * Check if current user is vendor
     *
     * @return bool
     */
    private static function is_current_user_vendor() {
        $user = wp_get_current_user();
        
        // Check multiple possible capability/role combinations
        $is_vendor = current_user_can( 'vendor-mm' ) || 
                    in_array( 'vendor-mm', $user->roles, true ) ||
                    current_user_can( 'manage_vendor_orders' ) ||
                    in_array( 'vendor', $user->roles, true ) ||
                    in_array( 'shop_manager', $user->roles, true ) ||  // Fallback
                    current_user_can( 'manage_woocommerce' );          // Fallback
                    
        // Debug logging
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'VSS Debug: User roles: ' . print_r( $user->roles, true ) );
            error_log( 'VSS Debug: Has vendor-mm cap: ' . ( current_user_can( 'vendor-mm' ) ? 'YES' : 'NO' ) );
            error_log( 'VSS Debug: Has manage_woocommerce cap: ' . ( current_user_can( 'manage_woocommerce' ) ? 'YES' : 'NO' ) );
            error_log( 'VSS Debug: Is vendor result: ' . ( $is_vendor ? 'YES' : 'NO' ) );
        }
        
        return $is_vendor;
    }

    /**
     * Vendor login redirect
     *
     * @param string $redirect_to
     * @param string $requested_redirect_to
     * @param WP_User|WP_Error $user
     * @return string
     */
    public static function vendor_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( $user && ! is_wp_error( $user ) && in_array( 'vendor-mm', (array) $user->roles, true ) ) {
            $vendor_portal_page = get_option( 'vss_vendor_portal_page_id' );
            if ( $vendor_portal_page ) {
                return get_permalink( $vendor_portal_page );
            }
            return home_url( '/vendor-portal/' );
        }
        return $redirect_to;
    }

    /**
     * Setup vendor admin menu - FIXED VERSION
     */
    public static function setup_vendor_admin_menu() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        // Remove default WooCommerce menu
        remove_menu_page( 'woocommerce' );
        remove_menu_page( 'edit.php?post_type=shop_order' );
        
        // Remove other unnecessary menus
        $restricted_menus = [
            'edit.php', // Posts
            'upload.php', // Media (we'll add back selectively)
            'edit-comments.php',
            'themes.php',
            'plugins.php',
            'users.php',
            'tools.php',
            'options-general.php',
            'woocommerce-marketing',
        ];

        foreach ( $restricted_menus as $menu ) {
            remove_menu_page( $menu );
        }

        // Add vendor-specific menus
        add_menu_page(
            __( 'Vendor Dashboard', 'vss' ),
            __( 'Dashboard', 'vss' ),
            'vendor-mm',
            'vss-vendor-dashboard',
            [ self::class, 'render_admin_vendor_dashboard' ],
            'dashicons-dashboard',
            2
        );

        // Add vendor orders page
        add_menu_page(
            __( 'My Orders', 'vss' ),
            __( 'My Orders', 'vss' ),
            'vendor-mm',
            'vss-vendor-orders',
            [ self::class, 'render_vendor_orders_page' ],
            'dashicons-cart',
            3
        );

        // Add media library access
        add_menu_page(
            __( 'Media', 'vss' ),
            __( 'Media', 'vss' ),
            'upload_files',
            'upload.php',
            '',
            'dashicons-admin-media',
            4
        );
    }

    /**
     * Render vendor orders page - FULL VERSION
     */
    public static function render_vendor_orders_page() {
        $current_user = wp_get_current_user();
        $vendor_id = get_current_user_id();
        
        // Handle bulk actions
        if ( isset( $_POST['bulk_action'] ) && isset( $_POST['order_ids'] ) && check_admin_referer( 'vss_bulk_orders' ) ) {
            $action = sanitize_key( $_POST['bulk_action'] );
            $order_ids = array_map( 'intval', $_POST['order_ids'] );
            
            if ( $action === 'mark_shipped' ) {
                $updated = 0;
                foreach ( $order_ids as $order_id ) {
                    $order = wc_get_order( $order_id );
                    if ( $order && get_post_meta( $order_id, '_vss_vendor_user_id', true ) == $vendor_id ) {
                        $order->update_status( 'shipped', __( 'Bulk marked as shipped by vendor.', 'vss' ) );
                        update_post_meta( $order_id, '_vss_shipped_at', time() );
                        $updated++;
                    }
                }
                echo '<div class="notice notice-success"><p>' . sprintf( __( '%d orders marked as shipped.', 'vss' ), $updated ) . '</p></div>';
            }
        }
        
        // Get filter parameters - DEFAULT TO PROCESSING ORDERS
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'processing';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $per_page = 100; // Show 100 orders per page
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        
        // Use direct SQL query for better performance and reliability
        global $wpdb;
        
        // Base query to get order IDs for this vendor
        $base_sql = "
            SELECT DISTINCT p.ID as order_id, p.post_date, p.post_status
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND pm.meta_key = '_vss_vendor_user_id'
            AND pm.meta_value = %d
        ";
        
        $sql_params = [ $vendor_id ];
        
        // Add status filter
        if ( $status_filter !== 'all' ) {
            $base_sql .= " AND p.post_status = %s";
            $sql_params[] = 'wc-' . $status_filter;
        }
        
        // Add search filter
        if ( ! empty( $search ) ) {
            $base_sql .= " AND (p.ID LIKE %s OR p.post_excerpt LIKE %s)";
            $search_term = '%' . $wpdb->esc_like( $search ) . '%';
            $sql_params[] = $search_term;
            $sql_params[] = $search_term;
        }
        
        // Get total count
        $count_sql = "SELECT COUNT(DISTINCT p.ID) " . substr( $base_sql, strpos( $base_sql, 'FROM' ) );
        $total_orders = $wpdb->get_var( $wpdb->prepare( $count_sql, $sql_params ) );
        
        // Add pagination and ordering
        $base_sql .= " ORDER BY p.post_date DESC";
        if ( $per_page > 0 ) {
            $offset = ( $paged - 1 ) * $per_page;
            $base_sql .= " LIMIT %d OFFSET %d";
            $sql_params[] = $per_page;
            $sql_params[] = $offset;
        }
        
        // Get order IDs
        $order_results = $wpdb->get_results( $wpdb->prepare( $base_sql, $sql_params ) );
        $order_ids = wp_list_pluck( $order_results, 'order_id' );
        
        // Convert to WC_Order objects
        $orders = [];
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $orders[] = $order;
            }
        }
        
        // Get status counts - use same approach for consistency
        $status_counts = [];
        
        // All orders count
        $all_count_sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND pm.meta_key = '_vss_vendor_user_id'
            AND pm.meta_value = %d
        ";
        $status_counts['all'] = $wpdb->get_var( $wpdb->prepare( $all_count_sql, $vendor_id ) );
        
        // Individual status counts
        $status_labels = [
            'pending' => __( 'Pending', 'vss' ),
            'processing' => __( 'Processing', 'vss' ),
            'shipped' => __( 'Shipped', 'vss' ),
            'completed' => __( 'Completed', 'vss' ),
        ];
        
        foreach ( array_keys( $status_labels ) as $status ) {
            $status_count_sql = $all_count_sql . " AND p.post_status = %s";
            $count = $wpdb->get_var( $wpdb->prepare( $status_count_sql, $vendor_id, 'wc-' . $status ) );
            if ( $count > 0 ) {
                $status_counts[ $status ] = $count;
            }
        }
        ?>
        
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'My Orders', 'vss' ); ?></h1>
            <a href="<?php echo esc_url( home_url( '/vendor-portal/' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Go to Vendor Portal', 'vss' ); ?>
            </a>
            
            <hr class="wp-header-end">
            
            <!-- Debug Info (remove in production) -->
            <?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
            <div class="notice notice-info">
                <p><strong>Debug Info:</strong></p>
                <p>Vendor ID: <?php echo $vendor_id; ?></p>
                <p>Total Orders Found: <?php echo $total_orders; ?></p>
                <p>Current Status Filter: <?php echo $status_filter; ?></p>
                <p>SQL Query: <code><?php echo esc_html( $wpdb->prepare( $base_sql, $sql_params ) ); ?></code></p>
            </div>
            <?php endif; ?>
            
            <!-- Status Filter -->
            <ul class="subsubsub">
                <li class="all">
                    <a href="<?php echo esc_url( add_query_arg( 'status', 'all' ) ); ?>" 
                       class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">
                        <?php esc_html_e( 'All', 'vss' ); ?> 
                        <span class="count">(<?php echo $status_counts['all']; ?>)</span>
                    </a>
                </li>
                <?php
                foreach ( $status_labels as $status => $label ) :
                    if ( isset( $status_counts[ $status ] ) && $status_counts[ $status ] > 0 ) :
                ?>
                    <li class="<?php echo esc_attr( $status ); ?>">
                        | <a href="<?php echo esc_url( add_query_arg( 'status', $status ) ); ?>" 
                             class="<?php echo $status_filter === $status ? 'current' : ''; ?>">
                            <?php echo esc_html( $label ); ?> 
                            <span class="count">(<?php echo $status_counts[ $status ]; ?>)</span>
                        </a>
                    </li>
                <?php 
                    endif;
                endforeach; 
                ?>
            </ul>
            
            <!-- Search Form -->
            <form method="get" class="search-form">
                <input type="hidden" name="page" value="vss-vendor-orders">
                <?php if ( $status_filter !== 'all' ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
                <?php endif; ?>
                <p class="search-box">
                    <input type="search" id="order-search-input" name="s" 
                           value="<?php echo esc_attr( $search ); ?>" 
                           placeholder="<?php esc_attr_e( 'Search orders...', 'vss' ); ?>">
                    <input type="submit" id="search-submit" class="button" 
                           value="<?php esc_attr_e( 'Search', 'vss' ); ?>">
                </p>
            </form>
            
            <!-- Bulk Actions Form -->
            <form method="post" id="vss-orders-form">
                <?php wp_nonce_field( 'vss_bulk_orders' ); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value=""><?php esc_html_e( 'Bulk Actions', 'vss' ); ?></option>
                            <option value="mark_shipped"><?php esc_html_e( 'Mark as Shipped', 'vss' ); ?></option>
                        </select>
                        <input type="submit" id="doaction" class="button action" value="<?php esc_attr_e( 'Apply', 'vss' ); ?>">
                    </div>
                    
                    <?php
                    // Pagination
                    $total_pages = $per_page > 0 ? ceil( $total_orders / $per_page ) : 1;
                    if ( $total_pages > 1 ) :
                    ?>
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf( _n( '%s item', '%s items', $total_orders, 'vss' ), number_format_i18n( $total_orders ) ); ?>
                        </span>
                        <?php
                        echo paginate_links( [
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $paged,
                            'type' => 'plain',
                        ] );
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Orders Table -->
                <table class="wp-list-table widefat fixed striped orders">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                                <input id="cb-select-all-1" type="checkbox">
                            </td>
                            <th scope="col" class="manage-column column-order"><?php esc_html_e( 'Order', 'vss' ); ?></th>
                            <th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'vss' ); ?></th>
                            <th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'vss' ); ?></th>
                            <th scope="col" class="manage-column column-customer"><?php esc_html_e( 'Customer', 'vss' ); ?></th>
                            <th scope="col" class="manage-column column-items"><?php esc_html_e( 'Items', 'vss' ); ?></th>
                            <th scope="col" class="manage-column column-ship-date"><?php esc_html_e( 'Ship Date', 'vss' ); ?></th>
                            <!-- REMOVED TOTAL COLUMN as requested -->
                            <th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'vss' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $orders ) ) : ?>
                            <?php foreach ( $orders as $order ) : ?>
                                <?php
                                $order_id = $order->get_id();
                                $ship_date = get_post_meta( $order_id, '_vss_estimated_ship_date', true );
                                $is_late = false;
                                
                                if ( $ship_date && $order->has_status( 'processing' ) ) {
                                    $is_late = strtotime( $ship_date ) < current_time( 'timestamp' );
                                }
                                
                                $row_class = '';
                                if ( $is_late ) {
                                    $row_class = 'vss-late-order';
                                }
                                ?>
                                <tr class="<?php echo esc_attr( $row_class ); ?>">
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="order_ids[]" value="<?php echo esc_attr( $order_id ); ?>">
                                    </th>
                                    <td class="column-order">
                                        <strong>
                                            <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], home_url( '/vendor-portal/' ) ) ); ?>">
                                                #<?php echo esc_html( $order->get_order_number() ); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td class="column-date">
                                        <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?>
                                    </td>
                                    <td class="column-status">
                                        <mark class="order-status status-<?php echo esc_attr( $order->get_status() ); ?>">
                                            <span><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span>
                                        </mark>
                                        <?php if ( $is_late ) : ?>
                                            <br><span class="vss-late-indicator"><?php esc_html_e( 'LATE', 'vss' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-customer">
                                        <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
                                        <?php if ( $order->get_billing_email() ) : ?>
                                            <br><small><?php echo esc_html( $order->get_billing_email() ); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="column-items">
                                        <?php echo count( $order->get_items() ); ?> 
                                        <?php echo _n( 'item', 'items', count( $order->get_items() ), 'vss' ); ?>
                                    </td>
                                    <td class="column-ship-date">
                                        <?php if ( $ship_date ) : ?>
                                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ship_date ) ) ); ?>
                                        <?php else : ?>
                                            <span style="color: #999;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- REMOVED TOTAL COLUMN as requested -->
                                    <td class="column-actions">
                                        <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], home_url( '/vendor-portal/' ) ) ); ?>" 
                                           class="button button-small">
                                            <?php esc_html_e( 'View', 'vss' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="8"> <!-- Updated colspan since we removed total column -->
                                    <?php esc_html_e( 'No orders found.', 'vss' ); ?>
                                    <?php if ( $status_counts['all'] > 0 ) : ?>
                                        <br><small><?php esc_html_e( 'Try adjusting your filters or search terms.', 'vss' ); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Bottom pagination -->
                <?php if ( $total_pages > 1 ) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links( [
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $paged,
                            'type' => 'plain',
                        ] );
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>
            
            <!-- Show summary at bottom -->
            <div class="vss-orders-summary">
                <p>
                    <strong><?php esc_html_e( 'Summary:', 'vss' ); ?></strong>
                    <?php printf( esc_html__( 'Showing %d orders out of %d total orders for your vendor account.', 'vss' ), count( $orders ), $total_orders ); ?>
                    <?php if ( $per_page > 0 && $total_orders > $per_page ) : ?>
                        <?php printf( esc_html__( ' Displaying page %d of %d.', 'vss' ), $paged, $total_pages ); ?>
                    <?php endif; ?>
                </p>
            </div>
            
            <style>
            .vss-late-order { background-color: #ffebee !important; }
            .vss-late-indicator { 
                background: #d32f2f; 
                color: white; 
                padding: 2px 6px; 
                border-radius: 3px; 
                font-size: 0.8em; 
                font-weight: bold; 
            }
            
            /* Updated column widths since we removed total column */
            .column-order { width: 12%; }
            .column-date { width: 12%; }
            .column-status { width: 15%; }
            .column-customer { width: 25%; }
            .column-items { width: 10%; }
            .column-ship-date { width: 12%; }
            .column-actions { width: 14%; }
            
            .search-form { 
                float: right; 
                margin-top: 10px; 
                margin-bottom: 20px; 
            }
            
            .search-box input[type="search"] { 
                width: 280px; 
                margin-right: 5px; 
            }
            
            .vss-orders-summary {
                margin-top: 20px;
                padding: 15px;
                background: #f9f9f9;
                border-left: 4px solid #0073aa;
            }
            
            .vss-orders-summary p {
                margin: 0;
                font-size: 14px;
            }
            </style>
            
            <script>
            jQuery(document).ready(function($) {
                // Select all checkbox functionality
                $('#cb-select-all-1').on('change', function() {
                    $('input[name="order_ids[]"]').prop('checked', $(this).is(':checked'));
                });
                
                // Confirm bulk actions
                $('#doaction').on('click', function(e) {
                    var action = $('#bulk-action-selector-top').val();
                    var checked = $('input[name="order_ids[]"]:checked').length;
                    
                    if (!action) {
                        e.preventDefault();
                        alert('<?php esc_js_e( 'Please select a bulk action.', 'vss' ); ?>');
                        return false;
                    }
                    
                    if (checked === 0) {
                        e.preventDefault();
                        alert('<?php esc_js_e( 'Please select at least one order.', 'vss' ); ?>');
                        return false;
                    }
                    
                    if (action === 'mark_shipped') {
                        return confirm('<?php esc_js_e( 'Are you sure you want to mark the selected orders as shipped?', 'vss' ); ?>');
                    }
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Ensure vendors can access admin area
     */
    public static function vendor_admin_access( $user_caps, $caps, $args, $user ) {
        // If user has vendor-mm role, grant access to admin
        if ( isset( $user->roles ) && in_array( 'vendor-mm', $user->roles, true ) ) {
            // Grant basic admin access capabilities
            $user_caps['read'] = true;
            $user_caps['upload_files'] = true;
            $user_caps['edit_posts'] = false; // Prevent post editing
            $user_caps['edit_others_posts'] = false;
            $user_caps['edit_pages'] = false;
            $user_caps['edit_others_pages'] = false;
            
            // Grant access to vendor pages
            if ( in_array( 'vendor-mm', $caps, true ) ) {
                $user_caps['vendor-mm'] = true;
            }

            // CRITICAL: Allow admin access
            if ( in_array( 'read', $caps, true ) ) {
                $user_caps['read'] = true;
            }
        }
        
        return $user_caps;
    }

    /**
     * Restrict admin access for vendors - FIXED
     */
    public static function restrict_admin_access() {
        // Only run this for vendor users
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        // Don't restrict on AJAX calls
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        global $pagenow;
        
        // Debug current page
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'VSS Debug: Current page: ' . $pagenow );
            error_log( 'VSS Debug: GET params: ' . print_r( $_GET, true ) );
        }
        
        // Allowed pages for vendors
        $allowed_pages = [
            'index.php',           // Dashboard
            'profile.php',         // Profile
            'upload.php',          // Media library
            'media-new.php',       // Upload new media
            'admin-ajax.php',      // AJAX requests
            'admin.php',           // Custom admin pages
        ];

        // Always allow our custom vendor pages
        if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'vss-vendor-' ) === 0 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'VSS Debug: Allowing vendor page: ' . $_GET['page'] );
            }
            return;
        }

        // Allow admin.php if it's for vendor pages
        if ( $pagenow === 'admin.php' && isset( $_GET['page'] ) ) {
            $allowed_admin_pages = [
                'vss-vendor-dashboard',
                'vss-vendor-orders',
            ];
            
            if ( in_array( $_GET['page'], $allowed_admin_pages, true ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'VSS Debug: Allowing vendor admin page: ' . $_GET['page'] );
                }
                return;
            }
        }

        // Check if on disallowed page
        if ( ! in_array( $pagenow, $allowed_pages, true ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'VSS Debug: Redirecting from disallowed page: ' . $pagenow );
            }
            wp_redirect( admin_url( 'admin.php?page=vss-vendor-dashboard' ) );
            exit;
        }
    }

    /**
     * Setup vendor capabilities
     */
    public static function setup_vendor_capabilities() {
        $role = get_role( 'vendor-mm' );
        if ( ! $role ) {
            add_role( 'vendor-mm', __( 'Vendor MM', 'vss' ), [
                'read' => true,
                'upload_files' => true,
                'vendor-mm' => true,
                'manage_vendor_orders' => true,
            ] );
        }
    }

    /**
     * Vendor setup notices
     */
    public static function vendor_setup_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $role = get_role( 'vendor-mm' );
        if ( ! $role ) {
            ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e( 'Vendor MM role not found. Please run setup to create vendor role.', 'vss' ); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Render vendor portal shortcode
     *
     * @param array $atts
     * @return string
     */
    public static function render_vendor_portal_shortcode( $atts ) {
        if ( ! self::is_current_user_vendor() ) {
            return self::render_login_form();
        }

        $atts = shortcode_atts( [
            'view' => 'dashboard',
        ], $atts, 'vss_vendor_portal' );

        ob_start();
        ?>
        <div class="vss-frontend-portal">
            <?php
            self::render_notices();
            
            $action = isset( $_GET['vss_action'] ) ? sanitize_key( $_GET['vss_action'] ) : 'dashboard';
            $order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;

            switch ( $action ) {
                case 'view_order':
                    if ( $order_id ) {
                        self::render_frontend_order_details( $order_id );
                    } else {
                        self::render_error_message( __( 'Invalid order ID.', 'vss' ) );
                    }
                    break;

                case 'reports':
                    self::render_vendor_reports();
                    break;

                case 'settings':
                    self::render_vendor_settings();
                    break;

                case 'dashboard':
                default:
                    self::render_vendor_dashboard();
                    break;
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render vendor stats shortcode
     *
     * @param array $atts
     * @return string
     */
    public static function render_vendor_stats_shortcode( $atts ) {
        if ( ! self::is_current_user_vendor() ) {
            return '';
        }

        $atts = shortcode_atts( [
            'period' => 'month',
            'display' => 'grid',
        ], $atts, 'vss_vendor_stats' );

        $vendor_id = get_current_user_id();
        $stats = self::get_vendor_statistics( $vendor_id );

        ob_start();
        ?>
        <div class="vss-vendor-stats-widget <?php echo esc_attr( 'display-' . $atts['display'] ); ?>">
            <div class="vss-stat-item">
                <span class="stat-value"><?php echo esc_html( $stats['processing'] ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Active Orders', 'vss' ); ?></span>
            </div>
            <div class="vss-stat-item">
                <span class="stat-value"><?php echo esc_html( $stats['shipped_this_month'] ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Shipped This Month', 'vss' ); ?></span>
            </div>
            <div class="vss-stat-item">
                <span class="stat-value"><?php echo wc_price( $stats['earnings_this_month'] ); ?></span>
                <span class="stat-label"><?php esc_html_e( 'Monthly Earnings', 'vss' ); ?></span>
            </div>
            <?php if ( $stats['late'] > 0 ) : ?>
                <div class="vss-stat-item critical">
                    <span class="stat-value"><?php echo esc_html( $stats['late'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Late Orders', 'vss' ); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render vendor earnings shortcode
     *
     * @param array $atts
     * @return string
     */
    public static function render_vendor_earnings_shortcode( $atts ) {
        if ( ! self::is_current_user_vendor() ) {
            return '';
        }

        $atts = shortcode_atts( [
            'period' => '30',
            'show_chart' => 'no',
        ], $atts, 'vss_vendor_earnings' );

        $vendor_id = get_current_user_id();
        $days = intval( $atts['period'] );
        $date_after = date( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Get earnings data
        $orders = wc_get_orders( [
            'status' => [ 'wc-shipped', 'wc-completed' ],
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'date_after' => $date_after,
            'return' => 'objects',
            'limit' => -1,
        ] );

        $total_earnings = 0;
        $daily_earnings = [];

        foreach ( $orders as $order ) {
            // Earnings
            $costs = get_post_meta( $order->get_id(), '_vss_order_costs', true );
            if ( isset( $costs['total_cost'] ) ) {
                $amount = floatval( $costs['total_cost'] );
                $total_earnings += $amount;
                
                $date_key = $order->get_date_modified()->date( 'Y-m-d' );
                if ( ! isset( $daily_earnings[ $date_key ] ) ) {
                    $daily_earnings[ $date_key ] = 0;
                }
                $daily_earnings[ $date_key ] += $amount;
            }
        }

        ob_start();
        ?>
        <div class="vss-vendor-earnings-widget">
            <div class="earnings-summary">
                <h4><?php printf( esc_html__( 'Last %d Days Earnings', 'vss' ), $days ); ?></h4>
                <div class="total-amount"><?php echo wc_price( $total_earnings ); ?></div>
                <div class="order-count"><?php printf( esc_html__( 'From %d orders', 'vss' ), count( $orders ) ); ?></div>
            </div>
            
            <?php if ( $atts['show_chart'] === 'yes' && ! empty( $daily_earnings ) ) : ?>
                <div class="earnings-chart" id="vss-earnings-chart-<?php echo esc_attr( uniqid() ); ?>">
                    <canvas></canvas>
                </div>
                <script>
                    jQuery(document).ready(function($) {
                        var chartData = <?php echo wp_json_encode( array_values( $daily_earnings ) ); ?>;
                        var chartLabels = <?php echo wp_json_encode( array_keys( $daily_earnings ) ); ?>;
                        // Chart initialization would go here if Chart.js is available
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Add vendor dashboard widgets
     */
    public static function add_vendor_dashboard_widgets() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        // Remove default widgets for vendors
        remove_meta_box( 'dashboard_incoming_links', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_plugins', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_secondary', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_recent_drafts', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );

        // Add vendor-specific widgets
        wp_add_dashboard_widget(
            'vss_vendor_stats_widget',
            __( 'Your Performance', 'vss' ),
            [ self::class, 'render_dashboard_stats_widget' ]
        );

        wp_add_dashboard_widget(
            'vss_vendor_recent_orders_widget',
            __( 'Recent Orders', 'vss' ),
            [ self::class, 'render_dashboard_recent_orders_widget' ]
        );

        wp_add_dashboard_widget(
            'vss_vendor_pending_tasks_widget',
            __( 'Pending Tasks', 'vss' ),
            [ self::class, 'render_dashboard_pending_tasks_widget' ]
        );
    }

    /**
     * Render dashboard stats widget
     */
    public static function render_dashboard_stats_widget() {
        $vendor_id = get_current_user_id();
        $stats = self::get_vendor_statistics( $vendor_id );
        ?>
        <div class="vss-dashboard-stats">
            <ul>
                <li><?php printf( __( 'Orders in Processing: <strong>%d</strong>', 'vss' ), $stats['processing'] ); ?></li>
                <li><?php printf( __( 'Shipped This Month: <strong>%d</strong>', 'vss' ), $stats['shipped_this_month'] ); ?></li>
                <li><?php printf( __( 'Earnings This Month: <strong>%s</strong>', 'vss' ), wc_price( $stats['earnings_this_month'] ) ); ?></li>
                <?php if ( $stats['late'] > 0 ) : ?>
                    <li class="critical"><?php printf( __( 'Late Orders: <strong>%d</strong>', 'vss' ), $stats['late'] ); ?></li>
                <?php endif; ?>
            </ul>
            <p class="vss-dashboard-link">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=vss-vendor-orders' ) ); ?>" class="button">
                    <?php esc_html_e( 'View All Orders', 'vss' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Render dashboard recent orders widget
     */
    public static function render_dashboard_recent_orders_widget() {
        $vendor_id = get_current_user_id();
        $orders = wc_get_orders( [
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'orderby' => 'date',
            'order' => 'DESC',
            'limit' => 5,
        ] );

        if ( empty( $orders ) ) {
            echo '<p>' . esc_html__( 'No recent orders.', 'vss' ) . '</p>';
            return;
        }
        ?>
        <ul class="vss-recent-orders-list">
            <?php foreach ( $orders as $order ) : ?>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order->get_id() ], home_url( '/vendor-portal/' ) ) ); ?>">
                        #<?php echo esc_html( $order->get_order_number() ); ?>
                    </a>
                    - <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                    <span class="order-date"><?php echo esc_html( $order->get_date_created()->date_i18n( 'M j' ) ); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="vss-dashboard-link">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=vss-vendor-orders' ) ); ?>">
                <?php esc_html_e( 'View all orders →', 'vss' ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Render dashboard pending tasks widget
     */
    public static function render_dashboard_pending_tasks_widget() {
        $vendor_id = get_current_user_id();
        $tasks = [];

        // Orders needing ship date
        $no_ship_date = wc_get_orders( [
            'status' => 'processing',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_vss_vendor_user_id',
                    'value' => $vendor_id,
                ],
                [
                    'key' => '_vss_estimated_ship_date',
                    'compare' => 'NOT EXISTS',
                ],
            ],
            'return' => 'ids',
            'limit' => -1,
        ] );

        if ( ! empty( $no_ship_date ) ) {
            $tasks[] = sprintf( 
                _n( '%d order needs ship date', '%d orders need ship date', count( $no_ship_date ), 'vss' ), 
                count( $no_ship_date ) 
            );
        }

        // Orders needing mockup
        $no_mockup = wc_get_orders( [
            'status' => 'processing',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_vss_vendor_user_id',
                    'value' => $vendor_id,
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => '_vss_mockup_status',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => '_vss_mockup_status',
                        'value' => 'none',
                    ],
                ],
            ],
            'return' => 'ids',
            'limit' => -1,
        ] );

        if ( ! empty( $no_mockup ) ) {
            $tasks[] = sprintf( 
                _n( '%d order needs mockup', '%d orders need mockup', count( $no_mockup ), 'vss' ), 
                count( $no_mockup ) 
            );
        }

        if ( empty( $tasks ) ) {
            echo '<p class="vss-no-tasks">' . esc_html__( 'All caught up! No pending tasks.', 'vss' ) . '</p>';
        } else {
            echo '<ul class="vss-pending-tasks">';
            foreach ( $tasks as $task ) {
                echo '<li>' . esc_html( $task ) . '</li>';
            }
            echo '</ul>';
        }
    }

    /**
     * Render login form
     *
     * @return string
     */
    private static function render_login_form() {
        ob_start();
        ?>
        <div class="vss-vendor-login">
            <h2><?php esc_html_e( 'Vendor Login', 'vss' ); ?></h2>
            <p><?php esc_html_e( 'You must be logged in as a vendor to view this content.', 'vss' ); ?></p>
            <?php
            wp_login_form( [
                'redirect' => get_permalink(),
                'form_id' => 'vss-vendor-login-form',
                'label_username' => __( 'Username or Email', 'vss' ),
                'label_password' => __( 'Password', 'vss' ),
                'label_remember' => __( 'Remember Me', 'vss' ),
                'label_log_in' => __( 'Log In', 'vss' ),
                'remember' => true,
            ] );
            ?>
            <p class="vss-vendor-register">
                <?php
                printf(
                    __( 'Not a vendor yet? <a href="%s">Apply here</a>.', 'vss' ),
                    esc_url( home_url( '/vendor-application/' ) )
                );
                ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render notices
     */
    private static function render_notices() {
        // Success notices
        if ( isset( $_GET['vss_notice'] ) ) {
            $notices = [
                'costs_saved' => __( 'Costs saved successfully!', 'vss' ),
                'tracking_saved' => __( 'Tracking information saved and order marked as shipped!', 'vss' ),
                'note_added' => __( 'Note added successfully!', 'vss' ),
                'production_confirmed' => __( 'Production confirmed and estimated ship date updated!', 'vss' ),
                'mockup_sent' => __( 'Mockup sent for customer approval!', 'vss' ),
                'production_file_sent' => __( 'Production files sent for customer approval!', 'vss' ),
                'settings_saved' => __( 'Settings saved successfully!', 'vss' ),
                'file_uploaded' => __( 'File uploaded successfully!', 'vss' ),
            ];

            $notice_key = sanitize_key( $_GET['vss_notice'] );
            if ( isset( $notices[ $notice_key ] ) ) {
                echo '<div class="vss-success-notice"><p>' . esc_html( $notices[ $notice_key ] ) . '</p></div>';
            }
        }

        // Error notices
        if ( isset( $_GET['vss_error'] ) ) {
            $errors = [
                'date_required' => __( 'Estimated ship date is required.', 'vss' ),
                'date_format' => __( 'Invalid date format. Please use YYYY-MM-DD.', 'vss' ),
                'file_upload_failed' => __( 'File upload failed. Please try again.', 'vss' ),
                'no_files_uploaded' => __( 'No files were uploaded. Please select at least one file.', 'vss' ),
                'invalid_approval_type' => __( 'Invalid approval type specified.', 'vss' ),
                'permission_denied' => __( 'You do not have permission to perform this action.', 'vss' ),
                'invalid_order' => __( 'Invalid order or you do not have permission to view it.', 'vss' ),
            ];

            $error_key = sanitize_key( $_GET['vss_error'] );
            if ( isset( $errors[ $error_key ] ) ) {
                echo '<div class="vss-error-notice"><p>' . esc_html( $errors[ $error_key ] ) . '</p></div>';
            }
        }
    }

    /**
     * Render vendor dashboard
     */
    private static function render_vendor_dashboard() {
        $vendor_id = get_current_user_id();
        $stats = self::get_vendor_statistics( $vendor_id );
        ?>
        <h1><?php esc_html_e( 'Vendor Dashboard', 'vss' ); ?></h1>
        
        <div class="vss-vendor-navigation">
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'dashboard', get_permalink() ) ); ?>" class="active">
                <?php esc_html_e( 'Dashboard', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'reports', get_permalink() ) ); ?>">
                <?php esc_html_e( 'Reports', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'settings', get_permalink() ) ); ?>">
                <?php esc_html_e( 'Settings', 'vss' ); ?>
            </a>
        </div>

        <div class="vss-stat-boxes">
            <div class="vss-stat-box-fe">
                <span class="stat-number-fe"><?php echo esc_html( $stats['processing'] ); ?></span>
                <span class="stat-label-fe"><?php esc_html_e( 'Orders in Processing', 'vss' ); ?></span>
            </div>
            <div class="vss-stat-box-fe <?php echo $stats['late'] > 0 ? 'is-critical' : ''; ?>">
                <span class="stat-number-fe"><?php echo esc_html( $stats['late'] ); ?></span>
                <span class="stat-label-fe"><?php esc_html_e( 'Orders Late', 'vss' ); ?></span>
            </div>
            <div class="vss-stat-box-fe">
                <span class="stat-number-fe"><?php echo esc_html( $stats['shipped_this_month'] ); ?></span>
                <span class="stat-label-fe"><?php esc_html_e( 'Shipped This Month', 'vss' ); ?></span>
            </div>
            <div class="vss-stat-box-fe">
                <span class="stat-number-fe"><?php echo wc_price( $stats['earnings_this_month'] ); ?></span>
                <span class="stat-label-fe"><?php esc_html_e( 'Earnings This Month', 'vss' ); ?></span>
            </div>
        </div>

        <?php
        self::render_quick_actions();
        self::render_recent_orders();
        self::render_pending_approvals();
    }

    /**
     * Get vendor statistics
     *
     * @param int $vendor_id
     * @return array
     */
    private static function get_vendor_statistics( $vendor_id ) {
        $stats = [
            'processing' => 0,
            'late' => 0,
            'shipped_this_month' => 0,
            'earnings_this_month' => 0,
        ];

        // Processing orders
        $processing_orders = wc_get_orders( [
            'status' => 'processing',
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'return' => 'ids',
            'limit' => -1,
        ] );
        $stats['processing'] = count( $processing_orders );

        // Late orders
        foreach ( $processing_orders as $order_id ) {
            $ship_date = get_post_meta( $order_id, '_vss_estimated_ship_date', true );
            if ( $ship_date && strtotime( $ship_date ) < current_time( 'timestamp' ) ) {
                $stats['late']++;
            }
        }

        // Shipped this month
        $month_start = date( 'Y-m-01 00:00:00' );
        $shipped_orders = wc_get_orders( [
            'status' => 'shipped',
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'date_modified' => '>=' . $month_start,
            'return' => 'objects',
            'limit' => -1,
        ] );
        $stats['shipped_this_month'] = count( $shipped_orders );

        // Earnings this month
        foreach ( $shipped_orders as $order ) {
            $costs = get_post_meta( $order->get_id(), '_vss_order_costs', true );
            if ( isset( $costs['total_cost'] ) ) {
                $stats['earnings_this_month'] += floatval( $costs['total_cost'] );
            }
        }

        return apply_filters( 'vss_vendor_statistics', $stats, $vendor_id );
    }

    /**
     * Render quick actions
     */
    private static function render_quick_actions() {
        ?>
        <div class="vss-quick-actions">
            <h3><?php esc_html_e( 'Quick Actions', 'vss' ); ?></h3>
            <div class="vss-action-buttons">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=vss-vendor-orders' ) ); ?>" class="button">
                    <?php esc_html_e( 'View All Orders', 'vss' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>" class="button" target="_blank">
                    <?php esc_html_e( 'Upload Files', 'vss' ); ?>
                </a>
                <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'reports', get_permalink() ) ); ?>" class="button">
                    <?php esc_html_e( 'View Reports', 'vss' ); ?>
                </a>
                <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" class="button">
                    <?php esc_html_e( 'Contact Support', 'vss' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render recent orders
     */
    private static function render_recent_orders() {
        $vendor_id = get_current_user_id();
        $orders = wc_get_orders( [
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'orderby' => 'date',
            'order' => 'DESC',
            'limit' => 10,
        ] );
        ?>
        <div class="vss-recent-orders">
            <h3><?php esc_html_e( 'Recent Orders', 'vss' ); ?></h3>
            <?php if ( ! empty( $orders ) ) : ?>
                <table class="vss-orders-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Order', 'vss' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'vss' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'vss' ); ?></th>
                            <th><?php esc_html_e( 'Ship Date', 'vss' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'vss' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orders as $order ) : ?>
                            <?php self::render_order_row( $order ); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="vss-view-all">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=vss-vendor-orders' ) ); ?>">
                        <?php esc_html_e( 'View all orders →', 'vss' ); ?>
                    </a>
                </p>
            <?php else : ?>
                <p><?php esc_html_e( 'No orders found.', 'vss' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render order row
     *
     * @param WC_Order $order
     */
    private static function render_order_row( $order ) {
        $order_id = $order->get_id();
        $ship_date = get_post_meta( $order_id, '_vss_estimated_ship_date', true );
        $is_late = false;

        if ( $ship_date && $order->has_status( 'processing' ) ) {
            $is_late = strtotime( $ship_date ) < current_time( 'timestamp' );
        }

        $row_class = '';
        if ( $is_late ) {
            $row_class = 'vss-status-late';
        } elseif ( $order->has_status( 'processing' ) ) {
            $row_class = 'vss-status-processing';
        } elseif ( $order->has_status( 'shipped' ) ) {
            $row_class = 'vss-status-shipped';
        }
        ?>
        <tr class="<?php echo esc_attr( $row_class ); ?>">
            <td>
                <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], get_permalink() ) ); ?>">
                    #<?php echo esc_html( $order->get_order_number() ); ?>
                </a>
            </td>
            <td><?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?></td>
            <td>
                <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                <?php if ( $is_late ) : ?>
                    <span class="vss-order-late-indicator"><?php esc_html_e( 'LATE', 'vss' ); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ( $ship_date ) : ?>
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ship_date ) ) ); ?>
                <?php else : ?>
                    <span style="color: #999;">—</span>
                <?php endif; ?>
            </td>
            <td class="vss-order-actions">
                <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], get_permalink() ) ); ?>">
                    <?php esc_html_e( 'View', 'vss' ); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    /**
     * Render pending approvals
     */
    private static function render_pending_approvals() {
        $vendor_id = get_current_user_id();
        $pending_orders = wc_get_orders( [
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_vss_vendor_user_id',
                    'value' => $vendor_id,
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => '_vss_mockup_status',
                        'value' => 'disapproved',
                    ],
                    [
                        'key' => '_vss_production_file_status',
                        'value' => 'disapproved',
                    ],
                ],
            ],
            'limit' => 5,
        ] );

        if ( empty( $pending_orders ) ) {
            return;
        }
        ?>
        <div class="vss-pending-approvals">
            <h3><?php esc_html_e( 'Items Requiring Attention', 'vss' ); ?></h3>
            <ul>
                <?php foreach ( $pending_orders as $order ) : ?>
                    <li>
                        <?php
                        $mockup_status = get_post_meta( $order->get_id(), '_vss_mockup_status', true );
                        $production_status = get_post_meta( $order->get_id(), '_vss_production_file_status', true );
                        
                        $issues = [];
                        if ( $mockup_status === 'disapproved' ) {
                            $issues[] = __( 'Mockup disapproved', 'vss' );
                        }
                        if ( $production_status === 'disapproved' ) {
                            $issues[] = __( 'Production file disapproved', 'vss' );
                        }
                        ?>
                        <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order->get_id() ], get_permalink() ) ); ?>">
                            #<?php echo esc_html( $order->get_order_number() ); ?>
                        </a>
                        - <?php echo esc_html( implode( ', ', $issues ) ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Render admin vendor dashboard
     */
    public static function render_admin_vendor_dashboard() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vendor Dashboard', 'vss' ); ?></h1>
            <p><?php esc_html_e( 'Welcome to your vendor dashboard. Use the menu to manage your orders.', 'vss' ); ?></p>
            <div class="vss-dashboard-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=vss-vendor-orders' ) ); ?>" class="button button-primary button-hero">
                    <?php esc_html_e( 'View My Orders', 'vss' ); ?>
                </a>
                <a href="<?php echo esc_url( home_url( '/vendor-portal/' ) ); ?>" class="button button-secondary button-hero">
                    <?php esc_html_e( 'Go to Vendor Portal', 'vss' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler to assign orders to vendors (for testing)
     */
    public static function ajax_assign_order_to_vendor() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        if ( ! check_ajax_referer( 'assign_vendor', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce' );
        }

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $vendor_id = isset( $_POST['vendor_id'] ) ? intval( $_POST['vendor_id'] ) : 0;

        if ( ! $order_id || ! $vendor_id ) {
            wp_send_json_error( 'Missing order ID or vendor ID' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( 'Order not found' );
        }

        // Assign the order to the vendor
        update_post_meta( $order_id, '_vss_vendor_user_id', $vendor_id );
        
        // Add order note
        $order->add_order_note( sprintf( 'Order assigned to vendor (User ID: %d)', $vendor_id ) );

        wp_send_json_success( 'Order assigned successfully' );
    }

    /**
     * AJAX save draft handler
     */
    public static function ajax_save_draft() {
        check_ajax_referer( 'vss_frontend_nonce', 'nonce' );

        if ( ! self::is_current_user_vendor() ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vss' ) ] );
        }

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $draft_data = isset( $_POST['draft_data'] ) ? $_POST['draft_data'] : [];

        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'vss' ) ] );
        }

        // Verify vendor has access
        $order = wc_get_order( $order_id );
        if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Invalid order or permission denied.', 'vss' ) ] );
        }

        // Save draft data as transient (expires in 7 days)
        $transient_key = 'vss_draft_' . $order_id . '_' . get_current_user_id();
        set_transient( $transient_key, $draft_data, 7 * DAY_IN_SECONDS );

        wp_send_json_success( [
            'message' => __( 'Draft saved successfully.', 'vss' ),
            'draft_id' => $transient_key,
        ] );
    }

    /**
     * AJAX get order details handler
     */
    public static function ajax_get_order_details() {
        check_ajax_referer( 'vss_frontend_nonce', 'nonce' );

        if ( ! self::is_current_user_vendor() ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vss' ) ] );
        }

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'vss' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Invalid order or permission denied.', 'vss' ) ] );
        }

        // Prepare order data
        $order_data = [
            'id' => $order->get_id(),
            'number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'date_created' => $order->get_date_created()->date( 'c' ),
            'customer' => [
                'name' => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ],
            'shipping' => [
                'address' => $order->get_formatted_shipping_address(),
                'method' => $order->get_shipping_method(),
            ],
            'items' => [],
            'meta' => [
                'ship_date' => get_post_meta( $order_id, '_vss_estimated_ship_date', true ),
                'mockup_status' => get_post_meta( $order_id, '_vss_mockup_status', true ),
                'production_status' => get_post_meta( $order_id, '_vss_production_file_status', true ),
            ],
        ];

        // Add items
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $order_data['items'][] = [
                'id' => $item_id,
                'name' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'sku' => $product ? $product->get_sku() : '',
                'zakeke_data' => $item->get_meta( 'zakeke_data', true ),
            ];
        }

        wp_send_json_success( $order_data );
    }

    /**
     * AJAX track order handler
     */
    public static function ajax_track_order() {
        check_ajax_referer( 'vss_track_order', 'nonce' );

        $order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( $_POST['order_id'] ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';

        if ( ! $order_id || ! $email ) {
            wp_send_json_error( [ 'message' => __( 'Please provide both order number and email.', 'vss' ) ] );
        }

        // Try to find order
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            // Try by order number
            $orders = wc_get_orders( [
                'order_number' => $order_id,
                'limit' => 1,
            ] );
            $order = ! empty( $orders ) ? $orders[0] : null;
        }

        if ( ! $order || $order->get_billing_email() !== $email ) {
            wp_send_json_error( [ 'message' => __( 'Order not found. Please check your order number and email.', 'vss' ) ] );
        }

        // Get tracking info
        $ship_date = get_post_meta( $order->get_id(), '_vss_estimated_ship_date', true );
        $tracking_number = get_post_meta( $order->get_id(), '_vss_tracking_number', true );
        $tracking_carrier = get_post_meta( $order->get_id(), '_vss_tracking_carrier', true );

        $response = [
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'status_label' => wc_get_order_status_name( $order->get_status() ),
            'date_created' => $order->get_date_created()->date_i18n( get_option( 'date_format' ) ),
        ];

        if ( $ship_date ) {
            $response['estimated_ship_date'] = date_i18n( get_option( 'date_format' ), strtotime( $ship_date ) );
        }

        if ( $tracking_number ) {
            $response['tracking'] = [
                'number' => $tracking_number,
                'carrier' => $tracking_carrier ?: __( 'Standard Shipping', 'vss' ),
            ];
        }

        wp_send_json_success( $response );
    }

    /**
     * AJAX manual fetch zakeke zip handler
     */
    public static function ajax_manual_fetch_zakeke_zip() {
        check_ajax_referer( 'vss_frontend_nonce', '_ajax_nonce' );

        if ( ! self::is_current_user_vendor() ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vss' ) ] );
        }

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
        $design_id = isset( $_POST['primary_zakeke_design_id'] ) ? sanitize_text_field( $_POST['primary_zakeke_design_id'] ) : '';

        if ( ! $order_id || ! $item_id || ! $design_id ) {
            wp_send_json_error( [ 'message' => __( 'Missing required data.', 'vss' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'Invalid order or permission denied.', 'vss' ) ] );
        }

        $item = $order->get_item( $item_id );
        if ( ! $item ) {
            wp_send_json_error( [ 'message' => __( 'Order item not found.', 'vss' ) ] );
        }

        // Fetch from Zakeke API (assuming this class exists)
        if ( class_exists( 'VSS_Zakeke_API' ) ) {
            $zakeke_response = VSS_Zakeke_API::get_zakeke_order_details_by_wc_order_id( $order_id );
            
            if ( ! $zakeke_response || ! isset( $zakeke_response['items'] ) ) {
                wp_send_json_error( [ 'message' => __( 'Could not retrieve Zakeke data.', 'vss' ) ] );
            }

            $found_zip_url = null;
            foreach ( $zakeke_response['items'] as $zakeke_item ) {
                if ( isset( $zakeke_item['design'] ) && $zakeke_item['design'] === $design_id ) {
                    if ( ! empty( $zakeke_item['printingFilesZip'] ) ) {
                        $found_zip_url = $zakeke_item['printingFilesZip'];
                        $item->update_meta_data( '_vss_zakeke_printing_files_zip_url', esc_url_raw( $found_zip_url ) );
                        $item->save();
                        
                        update_post_meta( $order_id, '_vss_zakeke_fetch_attempt_complete', true );
                        $order->add_order_note( sprintf( __( 'Vendor manually fetched Zakeke ZIP for item #%s.', 'vss' ), $item_id ) );
                        
                        wp_send_json_success( [
                            'message' => __( 'Zakeke files retrieved successfully!', 'vss' ),
                            'zip_url' => $found_zip_url,
                        ] );
                    }
                    break;
                }
            }
        }

        wp_send_json_error( [ 'message' => __( 'Print files not yet available. Please try again later.', 'vss' ) ] );
    }

    /**
     * Handle frontend forms
     */
    public static function handle_frontend_forms() {
        if ( ! isset( $_POST['vss_fe_action'] ) || ! self::is_current_user_vendor() ) {
            return;
        }

        $action = sanitize_key( $_POST['vss_fe_action'] );
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;

        if ( ! $order_id ) {
            return;
        }

        // Verify vendor has access to this order
        $order = wc_get_order( $order_id );
        if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != get_current_user_id() ) {
            wp_die( __( 'Invalid order or permission denied.', 'vss' ) );
        }

        $redirect_args = [
            'vss_action' => 'view_order',
            'order_id' => $order_id,
        ];

        switch ( $action ) {
            case 'vendor_confirm_production':
                self::handle_production_confirmation( $order, $redirect_args );
                break;

            case 'send_mockup_for_approval':
            case 'send_production_file_for_approval':
                self::handle_approval_submission( $order, $action, $redirect_args );
                break;

            case 'save_costs':
                self::handle_save_costs( $order, $redirect_args );
                break;

            case 'save_tracking':
                self::handle_save_tracking( $order, $redirect_args );
                break;

            case 'add_note':
                self::handle_add_note( $order, $redirect_args );
                break;
        }
    }

    /**
     * Handle production confirmation
     */
    private static function handle_production_confirmation( $order, &$redirect_args ) {
        if ( ! check_admin_referer( 'vss_production_confirmation' ) ) {
            wp_die( __( 'Security check failed.', 'vss' ) );
        }

        $estimated_ship_date = isset( $_POST['estimated_ship_date'] ) ? sanitize_text_field( $_POST['estimated_ship_date'] ) : '';

        if ( empty( $estimated_ship_date ) ) {
            $redirect_args['vss_error'] = 'date_required';
            wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
            exit;
        }

        // Validate date format
        $date = DateTime::createFromFormat( 'Y-m-d', $estimated_ship_date );
        if ( ! $date || $date->format( 'Y-m-d' ) !== $estimated_ship_date ) {
            $redirect_args['vss_error'] = 'date_format';
            wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
            exit;
        }

        // Save production confirmation
        update_post_meta( $order->get_id(), '_vss_estimated_ship_date', $estimated_ship_date );
        update_post_meta( $order->get_id(), '_vss_production_confirmed_at', current_time( 'timestamp' ) );
        update_post_meta( $order->get_id(), '_vss_production_confirmed_by', get_current_user_id() );

        $order->add_order_note( sprintf( 
            __( 'Vendor confirmed production with estimated ship date: %s', 'vss' ), 
            date_i18n( get_option( 'date_format' ), strtotime( $estimated_ship_date ) ) 
        ) );

        $redirect_args['vss_notice'] = 'production_confirmed';
        wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
        exit;
    }

    /**
     * Handle approval submission
     */
    private static function handle_approval_submission( $order, $action, &$redirect_args ) {
        if ( ! check_admin_referer( 'vss_approval_submission' ) ) {
            wp_die( __( 'Security check failed.', 'vss' ) );
        }

        $approval_type = $action === 'send_mockup_for_approval' ? 'mockup' : 'production_file';
        
        // Handle file uploads
        $uploaded_files = [];
        if ( ! empty( $_FILES['approval_files']['name'][0] ) ) {
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
            require_once( ABSPATH . 'wp-admin/includes/image.php' );

            $files = $_FILES['approval_files'];
            for ( $i = 0; $i < count( $files['name'] ); $i++ ) {
                if ( $files['error'][$i] === UPLOAD_ERR_OK ) {
                    $file = [
                        'name' => $files['name'][$i],
                        'type' => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error' => $files['error'][$i],
                        'size' => $files['size'][$i],
                    ];

                    $upload = wp_handle_upload( $file, [ 'test_form' => false ] );
                    if ( ! isset( $upload['error'] ) ) {
                        $uploaded_files[] = $upload['url'];
                    }
                }
            }
        }

        if ( empty( $uploaded_files ) ) {
            $redirect_args['vss_error'] = 'no_files_uploaded';
            wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
            exit;
        }

        // Save approval data
        $meta_key = '_vss_' . $approval_type . '_files';
        update_post_meta( $order->get_id(), $meta_key, $uploaded_files );
        update_post_meta( $order->get_id(), '_vss_' . $approval_type . '_status', 'pending' );
        update_post_meta( $order->get_id(), '_vss_' . $approval_type . '_submitted_at', current_time( 'timestamp' ) );

        $order->add_order_note( sprintf( 
            __( 'Vendor submitted %s for customer approval. %d files uploaded.', 'vss' ), 
            $approval_type === 'mockup' ? 'mockup' : 'production files',
            count( $uploaded_files )
        ) );

        $notice_key = $approval_type === 'mockup' ? 'mockup_sent' : 'production_file_sent';
        $redirect_args['vss_notice'] = $notice_key;
        wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
        exit;
    }

    /**
     * Handle save costs
     */
    private static function handle_save_costs( $order, &$redirect_args ) {
        if ( ! check_admin_referer( 'vss_save_costs' ) ) {
            wp_die( __( 'Security check failed.', 'vss' ) );
        }

        $costs = [
            'material_cost' => isset( $_POST['material_cost'] ) ? floatval( $_POST['material_cost'] ) : 0,
            'labor_cost' => isset( $_POST['labor_cost'] ) ? floatval( $_POST['labor_cost'] ) : 0,
            'shipping_cost' => isset( $_POST['shipping_cost'] ) ? floatval( $_POST['shipping_cost'] ) : 0,
            'other_cost' => isset( $_POST['other_cost'] ) ? floatval( $_POST['other_cost'] ) : 0,
        ];

        $costs['total_cost'] = array_sum( $costs );

        update_post_meta( $order->get_id(), '_vss_order_costs', $costs );
        update_post_meta( $order->get_id(), '_vss_costs_updated_at', current_time( 'timestamp' ) );

        $order->add_order_note( sprintf( 
            __( 'Vendor updated order costs. Total: %s', 'vss' ), 
            wc_price( $costs['total_cost'] )
        ) );

        $redirect_args['vss_notice'] = 'costs_saved';
        wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
        exit;
    }

    /**
     * Handle save tracking
     */
    private static function handle_save_tracking( $order, &$redirect_args ) {
        if ( ! check_admin_referer( 'vss_save_tracking' ) ) {
            wp_die( __( 'Security check failed.', 'vss' ) );
        }

        $tracking_number = isset( $_POST['tracking_number'] ) ? sanitize_text_field( $_POST['tracking_number'] ) : '';
        $tracking_carrier = isset( $_POST['tracking_carrier'] ) ? sanitize_text_field( $_POST['tracking_carrier'] ) : '';

        if ( ! empty( $tracking_number ) ) {
            update_post_meta( $order->get_id(), '_vss_tracking_number', $tracking_number );
            update_post_meta( $order->get_id(), '_vss_tracking_carrier', $tracking_carrier );
            update_post_meta( $order->get_id(), '_vss_shipped_at', current_time( 'timestamp' ) );

            // Update order status to shipped
            $order->update_status( 'shipped', __( 'Order marked as shipped by vendor with tracking information.', 'vss' ) );

            $redirect_args['vss_notice'] = 'tracking_saved';
        }

        wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
        exit;
    }

    /**
     * Handle add note
     */
    private static function handle_add_note( $order, &$redirect_args ) {
        if ( ! check_admin_referer( 'vss_add_note' ) ) {
            wp_die( __( 'Security check failed.', 'vss' ) );
        }

        $note = isset( $_POST['vendor_note'] ) ? sanitize_textarea_field( $_POST['vendor_note'] ) : '';

        if ( ! empty( $note ) ) {
            $order->add_order_note( sprintf( __( 'Vendor note: %s', 'vss' ), $note ), false );
            $redirect_args['vss_notice'] = 'note_added';
        }

        wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
        exit;
    }

    /**
     * Render frontend order details
     */
    private static function render_frontend_order_details( $order_id ) {
        $order = wc_get_order( $order_id );
        $current_user_id = get_current_user_id();

        if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != $current_user_id ) {
            self::render_error_message( __( 'Order not found or you do not have permission to view it.', 'vss' ) );
            return;
        }

        $portal_url = get_permalink();
        ?>
        <div class="vss-order-details-wrapper">
            <div class="vss-order-header">
                <h2><?php printf( __( 'Order #%s Details', 'vss' ), esc_html( $order->get_order_number() ) ); ?></h2>
                <p><a href="<?php echo esc_url( $portal_url ); ?>" class="button button-secondary">&larr; <?php esc_html_e( 'Back to Dashboard', 'vss' ); ?></a></p>
            </div>

            <?php self::render_order_status_bar( $order ); ?>
            <?php self::render_vendor_production_confirmation_section( $order ); ?>

            <div class="vss-order-tabs">
                <a class="nav-tab nav-tab-active" href="#tab-overview"><?php esc_html_e( 'Overview', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-products"><?php esc_html_e( 'Products', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-mockup"><?php esc_html_e( 'Mockup Approval', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-production"><?php esc_html_e( 'Production Files', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-costs"><?php esc_html_e( 'Costs', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-shipping"><?php esc_html_e( 'Shipping', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-notes"><?php esc_html_e( 'Notes', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-files"><?php esc_html_e( 'Files', 'vss' ); ?></a>
            </div>

            <div id="tab-overview" class="vss-tab-content vss-tab-active">
                <?php self::render_order_overview( $order ); ?>
            </div>

            <div id="tab-products" class="vss-tab-content">
                <?php self::render_order_products( $order ); ?>
            </div>

            <div id="tab-mockup" class="vss-tab-content">
                <?php self::render_approval_section( $order, 'mockup' ); ?>
            </div>

            <div id="tab-production" class="vss-tab-content">
                <?php self::render_approval_section( $order, 'production_file' ); ?>
            </div>

            <div id="tab-costs" class="vss-tab-content">
                <?php self::render_costs_section( $order ); ?>
            </div>

            <div id="tab-shipping" class="vss-tab-content">
                <?php self::render_shipping_section( $order ); ?>
            </div>

            <div id="tab-notes" class="vss-tab-content">
                <?php self::render_notes_section( $order ); ?>
            </div>

            <div id="tab-files" class="vss-tab-content">
                <?php self::render_files_section( $order ); ?>
            </div>
        </div>

        <style>
        .vss-order-tabs { margin: 20px 0; border-bottom: 1px solid #ccd0d4; }
        .nav-tab { 
            display: inline-block; 
            padding: 8px 12px; 
            margin: 0 5px -1px 0; 
            border: 1px solid #ccd0d4; 
            border-bottom: none; 
            background: #f1f1f1; 
            text-decoration: none; 
            color: #555; 
        }
        .nav-tab-active, .nav-tab:hover { background: #fff; color: #000; }
        .vss-tab-content { display: none; padding: 20px 0; }
        .vss-tab-active { display: block; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                var target = $(this).attr('href');
                
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.vss-tab-content').removeClass('vss-tab-active');
                $(target).addClass('vss-tab-active');
            });
        });
        </script>
        <?php
    }

    /**
     * Render order status bar
     */
    private static function render_order_status_bar( $order ) {
        $status = $order->get_status();
        $ship_date = get_post_meta( $order->get_id(), '_vss_estimated_ship_date', true );
        $is_late = $ship_date && $order->has_status( 'processing' ) && strtotime( $ship_date ) < current_time( 'timestamp' );
        ?>
        <div class="vss-order-status-bar">
            <div class="status-item <?php echo $status === 'pending' ? 'active' : 'completed'; ?>">
                <span class="status-dot"></span>
                <span class="status-label"><?php esc_html_e( 'Order Received', 'vss' ); ?></span>
            </div>
            <div class="status-item <?php echo $status === 'processing' ? 'active' : ($status === 'shipped' || $status === 'completed' ? 'completed' : ''); ?>">
                <span class="status-dot"></span>
                <span class="status-label"><?php esc_html_e( 'In Production', 'vss' ); ?></span>
                <?php if ( $is_late ) : ?>
                    <span class="status-late"><?php esc_html_e( 'LATE', 'vss' ); ?></span>
                <?php endif; ?>
            </div>
            <div class="status-item <?php echo $status === 'shipped' ? 'active' : ($status === 'completed' ? 'completed' : ''); ?>">
                <span class="status-dot"></span>
                <span class="status-label"><?php esc_html_e( 'Shipped', 'vss' ); ?></span>
            </div>
            <div class="status-item <?php echo $status === 'completed' ? 'completed' : ''; ?>">
                <span class="status-dot"></span>
                <span class="status-label"><?php esc_html_e( 'Delivered', 'vss' ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render vendor production confirmation section
     */
    private static function render_vendor_production_confirmation_section( $order ) {
        $ship_date = get_post_meta( $order->get_id(), '_vss_estimated_ship_date', true );
        $production_confirmed = get_post_meta( $order->get_id(), '_vss_production_confirmed_at', true );

        if ( $order->has_status( 'processing' ) && ! $production_confirmed ) :
        ?>
        <div class="vss-production-confirmation-fe">
            <h3><?php esc_html_e( 'Confirm Production', 'vss' ); ?></h3>
            <p><?php esc_html_e( 'Please confirm that you can fulfill this order and provide an estimated ship date.', 'vss' ); ?></p>
            
            <form method="post" class="vss-production-form">
                <?php wp_nonce_field( 'vss_production_confirmation' ); ?>
                <input type="hidden" name="vss_fe_action" value="vendor_confirm_production">
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
                
                <label for="estimated_ship_date"><?php esc_html_e( 'Estimated Ship Date:', 'vss' ); ?></label>
                <input type="date" 
                       name="estimated_ship_date" 
                       id="estimated_ship_date" 
                       value="<?php echo esc_attr( $ship_date ); ?>" 
                       min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" 
                       required>
                
                <input type="submit" value="<?php esc_attr_e( 'Confirm Production', 'vss' ); ?>" class="button button-primary">
            </form>
        </div>
        <?php
        elseif ( $ship_date ) :
        ?>
        <div class="vss-production-confirmed">
            <p><strong><?php esc_html_e( 'Production Confirmed', 'vss' ); ?></strong> - <?php printf( __( 'Estimated ship date: %s', 'vss' ), date_i18n( get_option( 'date_format' ), strtotime( $ship_date ) ) ); ?></p>
        </div>
        <?php
        endif;
    }

    /**
     * Render order overview
     */
    private static function render_order_overview( $order ) {
        ?>
        <div class="vss-order-overview">
            <div class="overview-grid">
                <div class="overview-section">
                    <h4><?php esc_html_e( 'Order Information', 'vss' ); ?></h4>
                    <p><strong><?php esc_html_e( 'Order Number:', 'vss' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></p>
                    <p><strong><?php esc_html_e( 'Date Created:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
                    <p><strong><?php esc_html_e( 'Status:', 'vss' ); ?></strong> <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></p>
                    <?php
                    $ship_date = get_post_meta( $order->get_id(), '_vss_estimated_ship_date', true );
                    if ( $ship_date ) :
                    ?>
                    <p><strong><?php esc_html_e( 'Estimated Ship Date:', 'vss' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ship_date ) ) ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="overview-section">
                    <h4><?php esc_html_e( 'Customer Information', 'vss' ); ?></h4>
                    <p><strong><?php esc_html_e( 'Name:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></p>
                    <p><strong><?php esc_html_e( 'Email:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_billing_email() ); ?></p>
                    <?php if ( $order->get_billing_phone() ) : ?>
                    <p><strong><?php esc_html_e( 'Phone:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_billing_phone() ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="overview-section">
                    <h4><?php esc_html_e( 'Shipping Address', 'vss' ); ?></h4>
                    <div class="shipping-address">
                        <?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render order products
     */
    private static function render_order_products( $order ) {
        ?>
        <div class="vss-order-products">
            <h4><?php esc_html_e( 'Order Items', 'vss' ); ?></h4>
            <table class="vss-items-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Product', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'SKU', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'Quantity', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'Design Files', 'vss' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $order->get_items() as $item_id => $item ) : ?>
                        <?php
                        $product = $item->get_product();
                        $zakeke_data = $item->get_meta( 'zakeke_data', true );
                        $zip_url = $item->get_meta( '_vss_zakeke_printing_files_zip_url', true );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $item->get_name() ); ?></strong>
                                <?php if ( $product && $product->get_image_id() ) : ?>
                                    <br><?php echo wp_get_attachment_image( $product->get_image_id(), 'thumbnail' ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $product ? $product->get_sku() : '—' ); ?></td>
                            <td><?php echo esc_html( $item->get_quantity() ); ?></td>
                            <td>
                                <?php if ( $zip_url ) : ?>
                                    <a href="<?php echo esc_url( $zip_url ); ?>" class="button button-small" target="_blank">
                                        <?php esc_html_e( 'Download Files', 'vss' ); ?>
                                    </a>
                                <?php elseif ( $zakeke_data ) : ?>
                                    <button type="button" class="button button-small vss-fetch-zakeke" 
                                            data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                                            data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                            data-design-id="<?php echo esc_attr( $zakeke_data['design_id'] ?? '' ); ?>">
                                        <?php esc_html_e( 'Fetch Files', 'vss' ); ?>
                                    </button>
                                <?php else : ?>
                                    <span class="no-files"><?php esc_html_e( 'No design files', 'vss' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render approval section
     */
    private static function render_approval_section( $order, $type ) {
        $type_label = $type === 'mockup' ? __( 'Mockup', 'vss' ) : __( 'Production Files', 'vss' );
        $files = get_post_meta( $order->get_id(), '_vss_' . $type . '_files', true );
        $status = get_post_meta( $order->get_id(), '_vss_' . $type . '_status', true );
        ?>
        <div class="vss-approval-section">
            <h4><?php echo esc_html( $type_label ); ?> <?php esc_html_e( 'Approval', 'vss' ); ?></h4>
            
            <?php if ( $files && $status ) : ?>
                <div class="approval-status">
                    <p><strong><?php esc_html_e( 'Status:', 'vss' ); ?></strong> 
                        <span class="status-<?php echo esc_attr( $status ); ?>">
                            <?php echo esc_html( ucfirst( $status ) ); ?>
                        </span>
                    </p>
                    
                    <h5><?php esc_html_e( 'Submitted Files:', 'vss' ); ?></h5>
                    <ul class="approval-files">
                        <?php foreach ( $files as $file_url ) : ?>
                            <li><a href="<?php echo esc_url( $file_url ); ?>" target="_blank"><?php echo esc_html( basename( $file_url ) ); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ( $order->has_status( 'processing' ) && ( ! $status || $status === 'disapproved' ) ) : ?>
                <form method="post" enctype="multipart/form-data" class="vss-approval-form">
                    <?php wp_nonce_field( 'vss_approval_submission' ); ?>
                    <input type="hidden" name="vss_fe_action" value="send_<?php echo esc_attr( $type ); ?>_for_approval">
                    <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
                    
                    <label for="approval_files_<?php echo esc_attr( $type ); ?>">
                        <?php printf( __( 'Upload %s Files:', 'vss' ), $type_label ); ?>
                    </label>
                    <input type="file" 
                           name="approval_files[]" 
                           id="approval_files_<?php echo esc_attr( $type ); ?>" 
                           multiple 
                           accept="image/*,.pdf" 
                           required>
                    <p class="description"><?php esc_html_e( 'Select multiple files (images or PDFs) to upload.', 'vss' ); ?></p>
                    
                    <input type="submit" 
                           value="<?php printf( esc_attr__( 'Submit %s for Approval', 'vss' ), $type_label ); ?>" 
                           class="button button-primary">
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render costs section
     */
    private static function render_costs_section( $order ) {
        $costs = get_post_meta( $order->get_id(), '_vss_order_costs', true );
        $costs = wp_parse_args( $costs, [
            'material_cost' => 0,
            'labor_cost' => 0,
            'shipping_cost' => 0,
            'other_cost' => 0,
            'total_cost' => 0,
        ] );
        ?>
        <div class="vss-costs-section">
            <h4><?php esc_html_e( 'Order Costs', 'vss' ); ?></h4>
            
            <form method="post" class="vss-costs-form">
                <?php wp_nonce_field( 'vss_save_costs' ); ?>
                <input type="hidden" name="vss_fe_action" value="save_costs">
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
                
                <div class="costs-grid">
                    <div class="cost-item">
                        <label for="material_cost"><?php esc_html_e( 'Material Cost:', 'vss' ); ?></label>
                        <input type="number" 
                               name="material_cost" 
                               id="material_cost" 
                               value="<?php echo esc_attr( $costs['material_cost'] ); ?>" 
                               step="0.01" 
                               min="0">
                    </div>
                    
                    <div class="cost-item">
                        <label for="labor_cost"><?php esc_html_e( 'Labor Cost:', 'vss' ); ?></label>
                        <input type="number" 
                               name="labor_cost" 
                               id="labor_cost" 
                               value="<?php echo esc_attr( $costs['labor_cost'] ); ?>" 
                               step="0.01" 
                               min="0">
                    </div>
                    
                    <div class="cost-item">
                        <label for="shipping_cost"><?php esc_html_e( 'Shipping Cost:', 'vss' ); ?></label>
                        <input type="number" 
                               name="shipping_cost" 
                               id="shipping_cost" 
                               value="<?php echo esc_attr( $costs['shipping_cost'] ); ?>" 
                               step="0.01" 
                               min="0">
                    </div>
                    
                    <div class="cost-item">
                        <label for="other_cost"><?php esc_html_e( 'Other Cost:', 'vss' ); ?></label>
                        <input type="number" 
                               name="other_cost" 
                               id="other_cost" 
                               value="<?php echo esc_attr( $costs['other_cost'] ); ?>" 
                               step="0.01" 
                               min="0">
                    </div>
                </div>
                
                <div class="total-cost">
                    <strong><?php esc_html_e( 'Total Cost:', 'vss' ); ?> <span id="total_display"><?php echo wc_price( $costs['total_cost'] ); ?></span></strong>
                </div>
                
                <input type="submit" value="<?php esc_attr_e( 'Save Costs', 'vss' ); ?>" class="button button-primary">
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            function updateTotal() {
                var total = 0;
                $('.cost-item input[type="number"]').each(function() {
                    total += parseFloat($(this).val()) || 0;
                });
                $('#total_display').text(' + total.toFixed(2));
            }
            
            $('.cost-item input[type="number"]').on('input', updateTotal);
        });
        </script>
        <?php
    }

    /**
     * Render shipping section
     */
    private static function render_shipping_section( $order ) {
        $tracking_number = get_post_meta( $order->get_id(), '_vss_tracking_number', true );
        $tracking_carrier = get_post_meta( $order->get_id(), '_vss_tracking_carrier', true );
        $shipped_at = get_post_meta( $order->get_id(), '_vss_shipped_at', true );
        ?>
        <div class="vss-shipping-section">
            <h4><?php esc_html_e( 'Shipping Information', 'vss' ); ?></h4>
            
            <?php if ( $tracking_number ) : ?>
                <div class="tracking-info">
                    <p><strong><?php esc_html_e( 'Tracking Number:', 'vss' ); ?></strong> <?php echo esc_html( $tracking_number ); ?></p>
                    <?php if ( $tracking_carrier ) : ?>
                        <p><strong><?php esc_html_e( 'Carrier:', 'vss' ); ?></strong> <?php echo esc_html( $tracking_carrier ); ?></p>
                    <?php endif; ?>
                    <?php if ( $shipped_at ) : ?>
                        <p><strong><?php esc_html_e( 'Shipped Date:', 'vss' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $shipped_at ) ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $order->has_status( 'processing' ) ) : ?>
                <form method="post" class="vss-shipping-form">
                    <?php wp_nonce_field( 'vss_save_tracking' ); ?>
                    <input type="hidden" name="vss_fe_action" value="save_tracking">
                    <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
                    
                    <div class="tracking-fields">
                        <div class="field-group">
                            <label for="tracking_number"><?php esc_html_e( 'Tracking Number:', 'vss' ); ?></label>
                            <input type="text" 
                                   name="tracking_number" 
                                   id="tracking_number" 
                                   value="<?php echo esc_attr( $tracking_number ); ?>" 
                                   placeholder="<?php esc_attr_e( 'Enter tracking number', 'vss' ); ?>">
                        </div>
                        
                        <div class="field-group">
                            <label for="tracking_carrier"><?php esc_html_e( 'Carrier:', 'vss' ); ?></label>
                            <select name="tracking_carrier" id="tracking_carrier">
                                <option value=""><?php esc_html_e( 'Select Carrier', 'vss' ); ?></option>
                                <option value="ups" <?php selected( $tracking_carrier, 'ups' ); ?>>UPS</option>
                                <option value="fedex" <?php selected( $tracking_carrier, 'fedex' ); ?>>FedEx</option>
                                <option value="usps" <?php selected( $tracking_carrier, 'usps' ); ?>>USPS</option>
                                <option value="dhl" <?php selected( $tracking_carrier, 'dhl' ); ?>>DHL</option>
                                <option value="other" <?php selected( $tracking_carrier, 'other' ); ?>><?php esc_html_e( 'Other', 'vss' ); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <input type="submit" value="<?php esc_attr_e( 'Save Tracking & Mark as Shipped', 'vss' ); ?>" class="button button-primary">
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render notes section
     */
    private static function render_notes_section( $order ) {
        $notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        ?>
        <div class="vss-notes-section">
            <h4><?php esc_html_e( 'Order Notes', 'vss' ); ?></h4>
            
            <?php if ( $notes ) : ?>
                <div class="order-notes">
                    <?php foreach ( $notes as $note ) : ?>
                        <div class="note-item">
                            <div class="note-meta">
                                <strong><?php echo esc_html( $note->added_by ); ?></strong>
                                <span class="note-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $note->date_created ) ) ); ?></span>
                            </div>
                            <div class="note-content"><?php echo wp_kses_post( wpautop( $note->content ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="vss-add-note-form">
                <?php wp_nonce_field( 'vss_add_note' ); ?>
                <input type="hidden" name="vss_fe_action" value="add_note">
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
                
                <label for="vendor_note"><?php esc_html_e( 'Add Note:', 'vss' ); ?></label>
                <textarea name="vendor_note" 
                          id="vendor_note" 
                          rows="4" 
                          placeholder="<?php esc_attr_e( 'Add a note about this order...', 'vss' ); ?>"></textarea>
                
                <input type="submit" value="<?php esc_attr_e( 'Add Note', 'vss' ); ?>" class="button">
            </form>
        </div>
        <?php
    }

    /**
     * Render files section
     */
    private static function render_files_section( $order ) {
        ?>
        <div class="vss-files-section">
            <h4><?php esc_html_e( 'Order Files', 'vss' ); ?></h4>
            
            <div class="files-grid">
                <div class="file-category">
                    <h5><?php esc_html_e( 'Design Files', 'vss' ); ?></h5>
                    <?php foreach ( $order->get_items() as $item_id => $item ) : ?>
                        <?php
                        $zip_url = $item->get_meta( '_vss_zakeke_printing_files_zip_url', true );
                        if ( $zip_url ) :
                        ?>
                            <p><a href="<?php echo esc_url( $zip_url ); ?>" target="_blank"><?php echo esc_html( $item->get_name() ); ?> - <?php esc_html_e( 'Design Files', 'vss' ); ?></a></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                
                <div class="file-category">
                    <h5><?php esc_html_e( 'Mockup Files', 'vss' ); ?></h5>
                    <?php
                    $mockup_files = get_post_meta( $order->get_id(), '_vss_mockup_files', true );
                    if ( $mockup_files ) :
                        foreach ( $mockup_files as $file_url ) :
                    ?>
                        <p><a href="<?php echo esc_url( $file_url ); ?>" target="_blank"><?php echo esc_html( basename( $file_url ) ); ?></a></p>
                    <?php 
                        endforeach;
                    else :
                    ?>
                        <p class="no-files"><?php esc_html_e( 'No mockup files uploaded yet.', 'vss' ); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="file-category">
                    <h5><?php esc_html_e( 'Production Files', 'vss' ); ?></h5>
                    <?php
                    $production_files = get_post_meta( $order->get_id(), '_vss_production_file_files', true );
                    if ( $production_files ) :
                        foreach ( $production_files as $file_url ) :
                    ?>
                        <p><a href="<?php echo esc_url( $file_url ); ?>" target="_blank"><?php echo esc_html( basename( $file_url ) ); ?></a></p>
                    <?php 
                        endforeach;
                    else :
                    ?>
                        <p class="no-files"><?php esc_html_e( 'No production files uploaded yet.', 'vss' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render vendor reports
     */
    private static function render_vendor_reports() {
        ?>
        <div class="vss-vendor-reports">
            <h3><?php esc_html_e( 'Vendor Reports', 'vss' ); ?></h3>
            <p><?php esc_html_e( 'Reports functionality will be implemented here.', 'vss' ); ?></p>
        </div>
        <?php
    }

    /**
     * Render vendor settings
     */
    private static function render_vendor_settings() {
        ?>
        <div class="vss-vendor-settings">
            <h3><?php esc_html_e( 'Vendor Settings', 'vss' ); ?></h3>
            <p><?php esc_html_e( 'Settings functionality will be implemented here.', 'vss' ); ?></p>
        </div>
        <?php
    }

    /**
     * Add vendor profile fields
     */
    public static function add_vendor_profile_fields( $user ) {
        if ( ! in_array( 'vendor-mm', $user->roles, true ) ) {
            return;
        }
        ?>
        <h2><?php esc_html_e( 'Vendor Information', 'vss' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="vss_company_name"><?php esc_html_e( 'Company Name', 'vss' ); ?></label></th>
                <td>
                    <input type="text" 
                           name="vss_company_name" 
                           id="vss_company_name" 
                           value="<?php echo esc_attr( get_user_meta( $user->ID, 'vss_company_name', true ) ); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="vss_payment_email"><?php esc_html_e( 'Payment Email', 'vss' ); ?></label></th>
                <td>
                    <input type="email" 
                           name="vss_payment_email" 
                           id="vss_payment_email" 
                           value="<?php echo esc_attr( get_user_meta( $user->ID, 'vss_payment_email', true ) ); ?>" 
                           class="regular-text" />
                    <p class="description"><?php esc_html_e( 'Email address for receiving payment notifications.', 'vss' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="vss_default_production_time"><?php esc_html_e( 'Default Production Time', 'vss' ); ?></label></th>
                <td>
                    <input type="number" 
                           name="vss_default_production_time" 
                           id="vss_default_production_time" 
                           value="<?php echo esc_attr( get_user_meta( $user->ID, 'vss_default_production_time', true ) ); ?>" 
                           min="1" 
                           max="30" 
                           class="small-text" />
                    <?php esc_html_e( 'days', 'vss' ); ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save vendor profile fields
     */
    public static function save_vendor_profile_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $fields = [
            'vss_company_name' => 'sanitize_text_field',
            'vss_payment_email' => 'sanitize_email',
            'vss_default_production_time' => 'intval',
        ];

        foreach ( $fields as $field => $sanitize_callback ) {
            if ( isset( $_POST[ $field ] ) ) {
                update_user_meta( $user_id, $field, call_user_func( $sanitize_callback, $_POST[ $field ] ) );
            }
        }
    }

    /**
     * Render error message
     */
    private static function render_error_message( $message ) {
        echo '<div class="vss-error-notice"><p>' . esc_html( $message ) . '</p></div>';
    }

} // End class VSS_Vendor