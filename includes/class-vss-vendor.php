<?php
/**
 * VSS Vendor Class - COMPLETE MERGED VERSION
 *
 * Handles vendor portal functionality with both frontend and admin capabilities.
 * This version combines frontend portal priorities with comprehensive vendor features,
 * including order management, pagination, performance optimizations, and UI enhancements.
 *
 * @package VendorOrderManager
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// It's recommended to define this constant in your main plugin file.
// Example: define( 'VSS_PLUGIN_FILE', __FILE__ );

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

        // Login redirect - UPDATED to prioritize frontend
        add_filter( 'login_redirect', [ self::class, 'vendor_login_redirect' ], 10, 3 );

        // Admin area setup - OPTIONAL (can be removed if you want frontend only)
        add_action( 'admin_menu', [ self::class, 'setup_vendor_admin_menu' ], 999 );
        add_action( 'admin_init', [ self::class, 'restrict_admin_access' ] );

        // Prevent vendor redirect to my-account
        add_action( 'init', [ self::class, 'prevent_vendor_redirect' ], 1 );
        add_filter( 'woocommerce_prevent_admin_access', [ self::class, 'allow_vendor_admin_access' ], 10, 1 );

        // AJAX handlers
        add_action( 'wp_ajax_vss_manual_fetch_zip', [ self::class, 'ajax_manual_fetch_zakeke_zip' ] );
        add_action( 'wp_ajax_vss_save_draft', [ self::class, 'ajax_save_draft' ] );
        add_action( 'wp_ajax_vss_get_order_details', [ self::class, 'ajax_get_order_details' ] );
        add_action( 'wp_ajax_nopriv_vss_track_order', [ self::class, 'ajax_track_order' ] );
        add_action( 'wp_ajax_vss_expand_order_row', [ self::class, 'ajax_expand_order_row' ] );
        add_action( 'wp_ajax_assign_order_to_vendor', [ self::class, 'ajax_assign_order_to_vendor' ] );

        // Vendor dashboard widgets
        add_action( 'wp_dashboard_setup', [ self::class, 'add_vendor_dashboard_widgets' ] );

        // Setup vendor roles and capabilities
        add_action( 'init', [ self::class, 'setup_vendor_capabilities' ] );

        // Profile fields
        add_action( 'show_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
        add_action( 'edit_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
        add_action( 'personal_options_update', [ self::class, 'save_vendor_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ self::class, 'save_vendor_profile_fields' ] );

        // Enqueue frontend assets for vendor portal
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_frontend_assets' ] );
    }

    /**
     * Allow vendor admin access by overriding WooCommerce's prevention.
     */
    public static function allow_vendor_admin_access( $prevent_access ) {
        if ( self::is_current_user_vendor() ) {
            return false;
        }
        return $prevent_access;
    }

    /**
     * Prevent vendor redirect from admin area.
     */
    public static function prevent_vendor_redirect() {
        if ( is_admin() && self::is_current_user_vendor() ) {
            // Remove WooCommerce's redirect
            remove_action( 'admin_init', 'wc_prevent_admin_access' );
            remove_action( 'admin_init', [ 'WC_Admin', 'prevent_admin_access' ] );
        }
    }

    /**
     * Check if current user is a vendor.
     *
     * @return bool
     */
    private static function is_current_user_vendor() {
        $user = wp_get_current_user();
        return in_array( 'vendor-mm', (array) $user->roles, true ) || current_user_can( 'vendor-mm' );
    }

    /**
     * Redirect vendors to FRONTEND portal upon login
     */
    public static function vendor_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( $user && ! is_wp_error( $user ) && in_array( 'vendor-mm', (array) $user->roles, true ) ) {
            // Get the vendor portal page URL
            $portal_page_id = get_option( 'vss_vendor_portal_page_id' );
            if ( $portal_page_id ) {
                return get_permalink( $portal_page_id );
            }
            // Fallback to home URL with vendor-portal slug
            return home_url( '/vendor-portal/' );
        }
        return $redirect_to;
    }

    /**
     * Restrict admin access for vendors - redirect to frontend
     */
    public static function restrict_admin_access() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }

        global $pagenow;

        // If vendor tries to access admin dashboard, redirect to frontend
        if ( $pagenow === 'index.php' || ( $pagenow === 'admin.php' && empty( $_GET['page'] ) ) ) {
            $portal_page_id = get_option( 'vss_vendor_portal_page_id' );
            if ( $portal_page_id ) {
                wp_redirect( get_permalink( $portal_page_id ) );
                exit;
            }
        }

        // Allowed pages for vendors in admin
        $allowed_pages = [
            'admin.php',
            'upload.php',
            'media-new.php',
            'admin-ajax.php',
            'profile.php',
        ];

        // Always allow our custom vendor pages
        if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'vss-vendor-' ) === 0 ) {
            return;
        }

        // Optionally, completely block admin access for vendors
        // Uncomment the following to force vendors to use frontend only:
        /*
        $portal_page_id = get_option( 'vss_vendor_portal_page_id' );
        if ( $portal_page_id ) {
            wp_redirect( get_permalink( $portal_page_id ) );
            exit;
        }
        */
    }

    /**
     * Setup minimal admin menu for vendors (optional)
     */
    public static function setup_vendor_admin_menu() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        // Remove most admin menus
        $restricted_menus = [
            'index.php',
            'edit.php',
            'edit-comments.php',
            'themes.php',
            'plugins.php',
            'users.php',
            'tools.php',
            'options-general.php',
            'woocommerce',
            'woocommerce-marketing',
            'edit.php?post_type=shop_order',
        ];

        foreach ( $restricted_menus as $menu ) {
            remove_menu_page( $menu );
        }

        // Add a simple redirect menu item to frontend portal
        add_menu_page(
            __( 'Vendor Portal', 'vss' ),
            __( 'Go to Portal', 'vss' ),
            'vendor-mm',
            'vss-vendor-redirect',
            [ self::class, 'redirect_to_frontend_portal' ],
            'dashicons-external',
            2
        );

        // Optional: Add admin orders page
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
     * Redirect to frontend portal
     */
    public static function redirect_to_frontend_portal() {
        $portal_page_id = get_option( 'vss_vendor_portal_page_id' );
        if ( $portal_page_id ) {
            wp_redirect( get_permalink( $portal_page_id ) );
            exit;
        }
    }

    /**
     * Enhanced vendor portal shortcode with all functionality
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

            // Render navigation
            self::render_vendor_navigation( $action );

            switch ( $action ) {
                case 'orders':
                    self::render_frontend_orders_list();
                    break;

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
     * Render vendor navigation
     */
    private static function render_vendor_navigation( $current_action ) {
        ?>
        <div class="vss-vendor-navigation">
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'dashboard', get_permalink() ) ); ?>"
               class="<?php echo $current_action === 'dashboard' ? 'active' : ''; ?>">
                <?php esc_html_e( 'Dashboard', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'orders', get_permalink() ) ); ?>"
               class="<?php echo $current_action === 'orders' ? 'active' : ''; ?>">
                <?php esc_html_e( 'My Orders', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'reports', get_permalink() ) ); ?>"
               class="<?php echo $current_action === 'reports' ? 'active' : ''; ?>">
                <?php esc_html_e( 'Reports', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'settings', get_permalink() ) ); ?>"
               class="<?php echo $current_action === 'settings' ? 'active' : ''; ?>">
                <?php esc_html_e( 'Settings', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="logout">
                <?php esc_html_e( 'Logout', 'vss' ); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Render frontend orders list
     */
    private static function render_frontend_orders_list() {
        $vendor_id = get_current_user_id();

        // Get filter parameters
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $per_page = 20;
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

        // Build query args
        $args = [
            'limit' => $per_page,
            'offset' => ( $paged - 1 ) * $per_page,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'paginate' => true,
        ];

        // Add status filter
        if ( $status_filter !== 'all' ) {
            $args['status'] = 'wc-' . $status_filter;
        }

        // Add search
        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        // Get orders with pagination
        $results = wc_get_orders( $args );
        $orders = $results->orders;
        $total_orders = $results->total;
        $total_pages = $results->max_num_pages;

        // Get status counts
        $status_counts = self::get_vendor_order_status_counts( $vendor_id );
        ?>

        <h2><?php esc_html_e( 'My Orders', 'vss' ); ?></h2>

        <!-- Status filters -->
        <ul class="vss-status-filters">
            <li>
                <a href="<?php echo esc_url( remove_query_arg( [ 'status', 'paged' ] ) ); ?>"
                   class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">
                    <?php esc_html_e( 'All', 'vss' ); ?>
                    <span class="count">(<?php echo number_format_i18n( $status_counts['all'] ); ?>)</span>
                </a>
            </li>
            <?php
            $statuses = [
                'processing' => __( 'Processing', 'vss' ),
                'shipped' => __( 'Shipped', 'vss' ),
                'completed' => __( 'Completed', 'vss' ),
            ];

            foreach ( $statuses as $status => $label ) :
                if ( isset( $status_counts[ $status ] ) && $status_counts[ $status ] > 0 ) :
            ?>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( [ 'status' => $status, 'paged' => 1 ] ) ); ?>"
                       class="<?php echo $status_filter === $status ? 'current' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                        <span class="count">(<?php echo number_format_i18n( $status_counts[ $status ] ); ?>)</span>
                    </a>
                </li>
            <?php
                endif;
            endforeach;
            ?>
        </ul>

        <!-- Search form -->
        <form method="get" class="vss-search-form">
            <input type="hidden" name="vss_action" value="orders">
            <?php if ( $status_filter !== 'all' ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
            <?php endif; ?>
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search orders...', 'vss' ); ?>">
            <button type="submit"><?php esc_html_e( 'Search', 'vss' ); ?></button>
        </form>

        <!-- Orders table -->
        <table class="vss-orders-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Items', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Ship Date', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'vss' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $orders ) ) : ?>
                    <?php foreach ( $orders as $order ) : ?>
                        <?php self::render_frontend_order_row( $order ); ?>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e( 'No orders found.', 'vss' ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="vss-pagination">
                <?php
                echo paginate_links( [
                    'base' => add_query_arg( 'paged', '%#%' ),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $paged,
                ] );
                ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Enqueue frontend assets
     */
    /**
     * Enqueue frontend assets
     */
    public static function enqueue_frontend_assets() {
        // More robust check: is it the portal page OR does the page contain the shortcode?
        $is_vendor_portal = false;
        $portal_page_id = get_option( 'vss_vendor_portal_page_id' );

        if ( is_page( $portal_page_id ) ) {
            $is_vendor_portal = true;
        } else if ( is_page() ) {
            global $post;
            if ( $post && has_shortcode( $post->post_content, 'vss_vendor_portal' ) ) {
                $is_vendor_portal = true;
            }
        }

        if ( $is_vendor_portal ) {
            // Enqueue frontend styles
            wp_enqueue_style(
                'vss-frontend-styles',
                VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css',
                [],
                VSS_VERSION
            );

            // Enqueue frontend scripts
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
            ] );

            // Enqueue jQuery UI datepicker styles
            wp_enqueue_style( 'jquery-ui-datepicker-style', '//code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css' );
        }
    }

    /**
     * AJAX handler for expanding order row details
     */
    public static function ajax_expand_order_row() {
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

        ob_start();
        ?>
        <div class="vss-order-expanded-details">
            <div class="order-items">
                <h4><?php esc_html_e( 'Order Items', 'vss' ); ?></h4>
                <table>
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Product', 'vss' ); ?></th>
                            <th><?php esc_html_e( 'SKU', 'vss' ); ?></th>
                            <th><?php esc_html_e( 'Quantity', 'vss' ); ?></th>
                            <th><?php esc_html_e( 'Files', 'vss' ); ?></th>
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
                                <td><?php echo esc_html( $item->get_name() ); ?></td>
                                <td><?php echo esc_html( $product ? $product->get_sku() : '—' ); ?></td>
                                <td><?php echo esc_html( $item->get_quantity() ); ?></td>
                                <td>
                                    <?php if ( $zip_url ) : ?>
                                        <a href="<?php echo esc_url( $zip_url ); ?>" class="button button-small" target="_blank">
                                            <?php esc_html_e( 'Download', 'vss' ); ?>
                                        </a>
                                    <?php elseif ( $zakeke_data ) : ?>
                                        <button type="button" class="button button-small vss-manual-fetch-zakeke-zip"
                                                data-order-id="<?php echo esc_attr( $order_id ); ?>"
                                                data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                                data-zakeke-design-id="<?php echo esc_attr( $zakeke_data['design'] ?? '' ); ?>">
                                            <?php esc_html_e( 'Fetch Files', 'vss' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <span><?php esc_html_e( 'No files', 'vss' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="order-actions">
                <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], get_permalink() ) ); ?>"
                   class="button button-primary">
                    <?php esc_html_e( 'View Full Details', 'vss' ); ?>
                </a>
            </div>
        </div>
        <?php
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    /**
     * Render vendor orders page with pagination and filters (Admin version).
     */
    public static function render_vendor_orders_page() {
        $vendor_id = get_current_user_id();

        // Get filter parameters
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $per_page = 100;
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;

        // Handle bulk actions
        if ( isset( $_POST['bulk_action'] ) && isset( $_POST['order_ids'] ) && check_admin_referer( 'vss_bulk_orders' ) ) {
            self::handle_bulk_actions();
        }

        // Build query args
        $args = [
            'limit' => $per_page,
            'offset' => ( $paged - 1 ) * $per_page,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'paginate' => true,
        ];

        // Add status filter
        if ( $status_filter !== 'all' ) {
            $args['status'] = 'wc-' . $status_filter;
        }

        // Add search
        if ( ! empty( $search ) ) {
            $args['s'] = $search;
        }

        // Get orders with pagination
        $results = wc_get_orders( $args );
        $orders = $results->orders;
        $total_orders = $results->total;
        $total_pages = $results->max_num_pages;

        // Get status counts
        $status_counts = self::get_vendor_order_status_counts( $vendor_id );

        // Sort orders to put processing orders at the top
        usort( $orders, function( $a, $b ) {
            $a_processing = $a->has_status( 'processing' ) ? 0 : 1;
            $b_processing = $b->has_status( 'processing' ) ? 0 : 1;

            if ( $a_processing !== $b_processing ) {
                return $a_processing - $b_processing;
            }

            // Then sort by date
            return $b->get_date_created()->getTimestamp() - $a->get_date_created()->getTimestamp();
        });

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'My Orders', 'vss' ); ?></h1>
            <a href="<?php echo esc_url( home_url( '/vendor-portal/' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Go to Vendor Portal', 'vss' ); ?>
            </a>

            <hr class="wp-header-end">

            <ul class="subsubsub">
                <li class="all">
                    <a href="<?php echo esc_url( remove_query_arg( [ 'status', 'paged' ] ) ); ?>"
                       class="<?php echo $status_filter === 'all' ? 'current' : ''; ?>">
                        <?php esc_html_e( 'All', 'vss' ); ?>
                        <span class="count">(<?php echo number_format_i18n( $status_counts['all'] ); ?>)</span>
                    </a>
                </li>
                <?php
                $statuses = [
                    'processing' => __( 'Processing', 'vss' ),
                    'shipped' => __( 'Shipped', 'vss' ),
                    'completed' => __( 'Completed', 'vss' ),
                    'pending' => __( 'Pending', 'vss' ),
                ];

                foreach ( $statuses as $status => $label ) :
                    if ( isset( $status_counts[ $status ] ) && $status_counts[ $status ] > 0 ) :
                ?>
                        <li class="<?php echo esc_attr( $status ); ?>">
                            | <a href="<?php echo esc_url( add_query_arg( [ 'status' => $status, 'paged' => 1 ] ) ); ?>"
                                 class="<?php echo $status_filter === $status ? 'current' : ''; ?>">
                                <?php echo esc_html( $label ); ?>
                                <span class="count">(<?php echo number_format_i18n( $status_counts[ $status ] ); ?>)</span>
                            </a>
                        </li>
                <?php
                    endif;
                endforeach;
                ?>
            </ul>

            <form method="get" class="search-form">
                <input type="hidden" name="page" value="vss-vendor-orders">
                <?php if ( $status_filter !== 'all' ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
                <?php endif; ?>
                <p class="search-box">
                    <input type="search" id="order-search-input" name="s"
                           value="<?php echo esc_attr( $search ); ?>"
                           placeholder="<?php esc_attr_e( 'Search by order number...', 'vss' ); ?>">
                    <input type="submit" id="search-submit" class="button"
                           value="<?php esc_attr_e( 'Search Orders', 'vss' ); ?>">
                </p>
            </form>

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

                    <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf( _n( '%s order', '%s orders', $total_orders, 'vss' ), number_format_i18n( $total_orders ) ); ?>
                        </span>
                        <?php
                        echo paginate_links( [
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $paged,
                        ] );
                        ?>
                    </div>
                    <?php endif; ?>
                </div>

                <table class="wp-list-table widefat fixed striped orders vss-vendor-orders-table">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                                <input id="cb-select-all-1" type="checkbox">
                            </td>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Order', 'vss' ); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Date', 'vss' ); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Status', 'vss' ); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Customer', 'vss' ); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Items', 'vss' ); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Ship Date', 'vss' ); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Files', 'vss' ); ?></th>
                            <th scope="col" class="manage-column"><?php esc_html_e( 'Actions', 'vss' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $orders ) ) : ?>
                            <?php foreach ( $orders as $order ) : ?>
                                <?php self::render_order_row( $order ); ?>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="9"> <p><?php esc_html_e( 'No orders found.', 'vss' ); ?></p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

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
                        ] );
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </form>

            <style>
            /* Status-based row colors */
            .vss-vendor-orders-table tbody tr.status-processing {
                background-color: #fff8e5 !important;
            }
            .vss-vendor-orders-table tbody tr.status-shipped {
                background-color: #e8f5e9 !important;
            }
            .vss-vendor-orders-table tbody tr.status-completed {
                background-color: #e3f2fd !important;
            }
            .vss-vendor-orders-table tbody tr.status-pending {
                background-color: #f5f5f5 !important;
            }
            .vss-vendor-orders-table tbody tr.status-late {
                background-color: #ffebee !important;
            }

            /* Override striped table styles */
            .vss-vendor-orders-table.striped > tbody > tr.status-processing:nth-child(odd),
            .vss-vendor-orders-table.striped > tbody > tr.status-processing:nth-child(even) {
                background-color: #fff8e5 !important;
            }
            .vss-vendor-orders-table.striped > tbody > tr.status-shipped:nth-child(odd),
            .vss-vendor-orders-table.striped > tbody > tr.status-shipped:nth-child(even) {
                background-color: #e8f5e9 !important;
            }
            .vss-vendor-orders-table.striped > tbody > tr.status-completed:nth-child(odd),
            .vss-vendor-orders-table.striped > tbody > tr.status-completed:nth-child(even) {
                background-color: #e3f2fd !important;
            }
            .vss-vendor-orders-table.striped > tbody > tr.status-late:nth-child(odd),
            .vss-vendor-orders-table.striped > tbody > tr.status-late:nth-child(even) {
                background-color: #ffebee !important;
            }

            .vss-late-indicator {
                background: #d32f2f;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 0.8em;
                font-weight: bold;
                display: inline-block;
                margin-left: 5px;
            }

            .search-form {
                float: right;
                margin-top: 10px;
                margin-bottom: 20px;
            }

            .search-box input[type="search"] {
                width: 280px;
                margin-right: 5px;
            }

            /* Make sure processing orders stand out */
            .vss-vendor-orders-table tbody tr.status-processing td {
                font-weight: 500;
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
     * Render a single order row for the admin table.
     */
    private static function render_order_row( $order ) {
        $order_id = $order->get_id();
        $ship_date = get_post_meta( $order_id, '_vss_estimated_ship_date', true );
        $is_late = false;

        if ( $ship_date && $order->has_status( 'processing' ) ) {
            $is_late = strtotime( $ship_date ) < current_time( 'timestamp' );
        }

        // Determine row class based on status
        $row_classes = [];
        if ( $is_late ) {
            $row_classes[] = 'status-late';
        } elseif ( $order->has_status( 'processing' ) ) {
            $row_classes[] = 'status-processing';
        } elseif ( $order->has_status( 'shipped' ) ) {
            $row_classes[] = 'status-shipped';
        } elseif ( $order->has_status( 'completed' ) ) {
            $row_classes[] = 'status-completed';
        } elseif ( $order->has_status( 'pending' ) ) {
            $row_classes[] = 'status-pending';
        }

        $row_class = implode( ' ', $row_classes );
        ?>
        <tr class="<?php echo esc_attr( $row_class ); ?>">
            <th scope="row" class="check-column">
                <input type="checkbox" name="order_ids[]" value="<?php echo esc_attr( $order_id ); ?>">
            </th>
            <td>
                <strong>
                    <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], home_url( '/vendor-portal/' ) ) ); ?>">
                        #<?php echo esc_html( $order->get_order_number() ); ?>
                    </a>
                </strong>
            </td>
            <td>
                <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?>
                <br>
                <small><?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'time_format' ) ) ); ?></small>
            </td>
            <td>
                <mark class="order-status status-<?php echo esc_attr( $order->get_status() ); ?>">
                    <span><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span>
                </mark>
                <?php if ( $is_late ) : ?>
                    <span class="vss-late-indicator"><?php esc_html_e( 'LATE', 'vss' ); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
                <?php if ( $order->get_billing_email() ) : ?>
                    <br><small><?php echo esc_html( $order->get_billing_email() ); ?></small>
                <?php endif; ?>
            </td>
            <td>
                <?php
                $item_count = $order->get_item_count();
                echo sprintf( _n( '%d item', '%d items', $item_count, 'vss' ), $item_count );
                ?>
            </td>
            <td>
                <?php if ( $ship_date ) : ?>
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ship_date ) ) ); ?>
                <?php else : ?>
                    <span style="color: #999;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                // Display attached ZIP file from admin
                $zip_file_id = get_post_meta( $order_id, '_vss_attached_zip_id', true );
                if ( $zip_file_id && ($zip_file_url = wp_get_attachment_url( $zip_file_id )) ) {
                    echo '<a href="' . esc_url( $zip_file_url ) . '" class="button button-small" target="_blank">' . esc_html__( 'Download ZIP', 'vss' ) . '</a><br>';
                }

                // Zakeke files
                foreach ($order->get_items() as $item_id => $item) {
                    $zakeke_data = $item->get_meta('zakeke_data', true);
                    $zip_url = $item->get_meta('_vss_zakeke_printing_files_zip_url', true);
                    $primary_zakeke_design_id = null;

                    if ($zakeke_data) {
                        $parsed_data = is_string($zakeke_data) ? json_decode($zakeke_data, true) : (array)$zakeke_data;
                        if (is_array($parsed_data) && isset($parsed_data['design'])) {
                            $primary_zakeke_design_id = $parsed_data['design'];
                        }
                    }

                    if ($zip_url) {
                        echo '<a href="' . esc_url($zip_url) . '" class="button button-small" target="_blank">' . esc_html__('Zakeke Files', 'vss') . '</a><br>';
                    } elseif ($primary_zakeke_design_id) {
                        echo '<button type="button" class="button button-small vss-manual-fetch-zakeke-zip"
                                data-order-id="' . esc_attr($order_id) . '"
                                data-item-id="' . esc_attr($item_id) . '"
                                data-zakeke-design-id="' . esc_attr($primary_zakeke_design_id) . '">'
                                . esc_html__('Fetch Zakeke', 'vss') . '</button><br>';
                    }
                }
                ?>
            </td>
            <td>
                <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], home_url( '/vendor-portal/' ) ) ); ?>"
                   class="button button-small">
                    <?php esc_html_e( 'View', 'vss' ); ?>
                </a>
            </td>
        </tr>
        <?php
    }

    /**
     * Get and cache vendor order status counts.
     */
    private static function get_vendor_order_status_counts( $vendor_id ) {
        $counts = [
            'all' => 0,
            'pending' => 0,
            'processing' => 0,
            'shipped' => 0,
            'completed' => 0,
        ];

        // Get all count
        $all_args = [
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'return' => 'ids',
            'limit' => -1,
        ];
        $counts['all'] = count( wc_get_orders( $all_args ) );

        // Get individual status counts
        foreach ( [ 'pending', 'processing', 'shipped', 'completed' ] as $status ) {
            $args = $all_args;
            $args['status'] = 'wc-' . $status;
            $counts[ $status ] = count( wc_get_orders( $args ) );
        }

        return $counts;
    }

    /**
     * Handle bulk actions submission from the orders page.
     */
    private static function handle_bulk_actions() {
        $action = sanitize_key( $_POST['bulk_action'] );
        $order_ids = array_map( 'intval', $_POST['order_ids'] );
        $vendor_id = get_current_user_id();

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

    /**
     * Setup vendor capabilities and role.
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
        } else {
            // Ensure role has necessary capabilities
            $role->add_cap( 'read' );
            $role->add_cap( 'upload_files' );
            $role->add_cap( 'vendor-mm' );
            $role->add_cap( 'manage_vendor_orders' );
        }
    }

    /**
     * Add custom widgets to the vendor dashboard.
     */
    public static function add_vendor_dashboard_widgets() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        // Remove default widgets
        global $wp_meta_boxes;
        $wp_meta_boxes['dashboard']['normal']['core'] = [];
        $wp_meta_boxes['dashboard']['side']['core'] = [];

        // Add vendor widgets
        wp_add_dashboard_widget(
            'vss_vendor_stats',
            __( 'Your Stats', 'vss' ),
            [ self::class, 'render_dashboard_stats_widget' ]
        );

        wp_add_dashboard_widget(
            'vss_vendor_recent',
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
     * Render the dashboard statistics widget.
     */
    public static function render_dashboard_stats_widget() {
        $vendor_id = get_current_user_id();
        $stats = self::get_vendor_statistics( $vendor_id );
        ?>
        <ul>
            <li><?php printf( __( 'Processing: <strong>%d</strong>', 'vss' ), $stats['processing'] ); ?></li>
            <li><?php printf( __( 'Shipped This Month: <strong>%d</strong>', 'vss' ), $stats['shipped_this_month'] ); ?></li>
            <li><?php printf( __( 'Earnings This Month: <strong>%s</strong>', 'vss' ), wc_price( $stats['earnings_this_month'] ) ); ?></li>
            <?php if ( $stats['late'] > 0 ) : ?>
                <li style="color: #d32f2f;"><?php printf( __( 'Late Orders: <strong>%d</strong>', 'vss' ), $stats['late'] ); ?></li>
            <?php endif; ?>
        </ul>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=vss-vendor-orders' ) ); ?>" class="button button-primary"><?php esc_html_e( 'View All Orders', 'vss' ); ?></a></p>
        <?php
    }

    /**
     * Render the dashboard recent orders widget.
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
        <ul>
            <?php foreach ( $orders as $order ) : ?>
                <li>
                    <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order->get_id() ], home_url( '/vendor-portal/' ) ) ); ?>">
                        #<?php echo esc_html( $order->get_order_number() ); ?>
                    </a>
                    - <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                    <span style="float: right;"><?php echo esc_html( $order->get_date_created()->date_i18n( 'M j' ) ); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * Get vendor statistics.
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
     * Render quick actions
     */
    private static function render_quick_actions() {
        ?>
        <div class="vss-quick-actions">
            <h3><?php esc_html_e( 'Quick Actions', 'vss' ); ?></h3>
            <div class="vss-action-buttons">
                <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'orders', get_permalink() ) ); ?>" class="button">
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
                            <?php self::render_frontend_order_row( $order ); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="vss-view-all">
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'orders', get_permalink() ) ); ?>">
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
     * Render frontend order row
     *
     * @param WC_Order $order
     */
    private static function render_frontend_order_row( $order ) {
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
            .vss-order-tabs {
                margin: 20px 0;
                border-bottom: 1px solid #ccd0d4;
                display: flex;
                flex-wrap: wrap;
            }
            .vss-order-tabs .nav-tab {
                display: inline-block;
                padding: 10px 20px;
                margin: 0 5px -1px 0;
                border: 1px solid #ccd0d4;
                border-bottom: 1px solid #ccd0d4;
                background: #f1f1f1;
                text-decoration: none;
                color: #555;
                cursor: pointer;
                transition: all 0.2s ease;
            }
            .vss-order-tabs .nav-tab:hover {
                background: #fff;
                color: #000;
            }
            .vss-order-tabs .nav-tab.nav-tab-active {
                background: #fff;
                color: #000;
                border-bottom: 1px solid #fff;
                font-weight: 600;
            }
            .vss-tab-content {
                display: none;
                padding: 20px 0;
            }
            .vss-tab-content.vss-tab-active {
                display: block;
            }
        </style>
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
                        $primary_zakeke_design_id = null;

                        // Parse Zakeke data to get design ID
                        if ( $zakeke_data ) {
                            $parsed_data = is_string( $zakeke_data ) ? json_decode( $zakeke_data, true ) : (array) $zakeke_data;
                            if ( is_array( $parsed_data ) && isset( $parsed_data['design'] ) ) {
                                $primary_zakeke_design_id = $parsed_data['design'];
                            }
                        }
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
                                        <?php esc_html_e( 'Download Zakeke Files', 'vss' ); ?>
                                    </a>
                                <?php elseif ( $primary_zakeke_design_id ) : ?>
                                    <button type="button" class="button button-small vss-manual-fetch-zakeke-zip"
                                            data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                                            data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                            data-zakeke-design-id="<?php echo esc_attr( $primary_zakeke_design_id ); ?>">
                                        <?php esc_html_e( 'Fetch Zakeke Files', 'vss' ); ?>
                                    </button>
                                <?php else : ?>
                                    <?php
                                    // Check if there's an admin uploaded ZIP file
                                    $admin_zip_id = get_post_meta( $order->get_id(), '_vss_attached_zip_id', true );
                                    if ( $admin_zip_id && ( $admin_zip_url = wp_get_attachment_url( $admin_zip_id ) ) ) :
                                    ?>
                                        <a href="<?php echo esc_url( $admin_zip_url ); ?>" class="button button-small" target="_blank">
                                            <?php esc_html_e( 'Download Admin ZIP', 'vss' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="no-files"><?php esc_html_e( 'No design files', 'vss' ); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Handle Zakeke file fetching
            $('.vss-manual-fetch-zakeke-zip').on('click', function() {
                var $button = $(this);
                var originalText = $button.text();

                $button.prop('disabled', true).text('<?php esc_js_e( 'Fetching...', 'vss' ); ?>');

                $.ajax({
                    url: vss_frontend_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'vss_manual_fetch_zip',
                        order_id: $button.data('order-id'),
                        item_id: $button.data('item-id'),
                        primary_zakeke_design_id: $button.data('zakeke-design-id'),
                        _ajax_nonce: vss_frontend_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Replace button with download link
                            var downloadLink = '<a href="' + response.data.zip_url + '" class="button button-small" target="_blank"><?php esc_js_e( 'Download Zakeke Files', 'vss' ); ?></a>';
                            $button.replaceWith(downloadLink);
                        } else {
                            alert(response.data.message || '<?php esc_js_e( 'Failed to fetch files. Please try again.', 'vss' ); ?>');
                            $button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('<?php esc_js_e( 'An error occurred. Please try again.', 'vss' ); ?>');
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
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
                $('#total_display').text('<?php echo get_woocommerce_currency_symbol(); ?>' + total.toFixed(2));
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
                    <?php
                    $has_design_files = false;

                    // Check for Zakeke files
                    foreach ( $order->get_items() as $item_id => $item ) : ?>
                        <?php
                        $zip_url = $item->get_meta( '_vss_zakeke_printing_files_zip_url', true );
                        $zakeke_data = $item->get_meta( 'zakeke_data', true );
                        $primary_zakeke_design_id = null;

                        // Parse Zakeke data to get design ID
                        if ( $zakeke_data ) {
                            $parsed_data = is_string( $zakeke_data ) ? json_decode( $zakeke_data, true ) : (array) $zakeke_data;
                            if ( is_array( $parsed_data ) && isset( $parsed_data['design'] ) ) {
                                $primary_zakeke_design_id = $parsed_data['design'];
                            }
                        }

                        if ( $zip_url ) :
                            $has_design_files = true;
                        ?>
                            <p>
                                <strong><?php echo esc_html( $item->get_name() ); ?>:</strong><br>
                                <a href="<?php echo esc_url( $zip_url ); ?>" target="_blank">
                                    <?php esc_html_e( 'Download Zakeke Design Files', 'vss' ); ?>
                                </a>
                            </p>
                        <?php elseif ( $primary_zakeke_design_id ) :
                            $has_design_files = true;
                        ?>
                            <p>
                                <strong><?php echo esc_html( $item->get_name() ); ?>:</strong><br>
                                <button type="button" class="button button-small vss-manual-fetch-zakeke-zip"
                                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                                        data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                        data-zakeke-design-id="<?php echo esc_attr( $primary_zakeke_design_id ); ?>">
                                    <?php esc_html_e( 'Fetch Zakeke Files', 'vss' ); ?>
                                </button>
                            </p>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php
                    // Check for admin uploaded ZIP
                    $admin_zip_id = get_post_meta( $order->get_id(), '_vss_attached_zip_id', true );
                    if ( $admin_zip_id && ( $admin_zip_url = wp_get_attachment_url( $admin_zip_id ) ) ) :
                        $has_design_files = true;
                    ?>
                        <p>
                            <strong><?php esc_html_e( 'Admin Uploaded ZIP:', 'vss' ); ?></strong><br>
                            <a href="<?php echo esc_url( $admin_zip_url ); ?>" target="_blank">
                                <?php esc_html_e( 'Download ZIP File', 'vss' ); ?>
                            </a>
                        </p>
                    <?php endif; ?>

                    <?php if ( ! $has_design_files ) : ?>
                        <p class="no-files"><?php esc_html_e( 'No design files available.', 'vss' ); ?></p>
                    <?php endif; ?>
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
