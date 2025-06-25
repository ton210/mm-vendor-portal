<?php
/**
 * VSS Vendor Class
 *
 * Handles vendor portal functionality and vendor-specific features
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
        
        // Admin area restrictions
        add_filter( 'pre_get_posts', [ self::class, 'filter_orders_for_vendor_in_admin' ] );
        add_action( 'admin_menu', [ self::class, 'restrict_admin_menu_for_vendors' ], 999 );
        add_action( 'admin_init', [ self::class, 'restrict_admin_access' ] );
        
        // AJAX handlers
        add_action( 'wp_ajax_vss_manual_fetch_zip', [ self::class, 'ajax_manual_fetch_zakeke_zip' ] );
        add_action( 'wp_ajax_vss_save_draft', [ self::class, 'ajax_save_draft' ] );
        add_action( 'wp_ajax_vss_get_order_details', [ self::class, 'ajax_get_order_details' ] );
        add_action( 'wp_ajax_nopriv_vss_track_order', [ self::class, 'ajax_track_order' ] );
        
        // Vendor dashboard widgets
        add_action( 'wp_dashboard_setup', [ self::class, 'add_vendor_dashboard_widgets' ] );
        
        // Profile fields
        add_action( 'show_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
        add_action( 'edit_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
        add_action( 'personal_options_update', [ self::class, 'save_vendor_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ self::class, 'save_vendor_profile_fields' ] );
    }

    /**
     * Check if current user is vendor
     *
     * @return bool
     */
    private static function is_current_user_vendor() {
        return current_user_can( 'vendor-mm' );
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
     * Restrict admin access for vendors
     */
    public static function restrict_admin_access() {
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

        global $pagenow;
        
        // Allowed pages for vendors
        $allowed_pages = [
            'index.php',
            'profile.php',
            'edit.php', // But filtered to show only their orders
            'post.php',
            'media-new.php',
            'upload.php',
            'admin-ajax.php',
        ];

        // Check if on shop_order edit page
        if ( $pagenow === 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] !== 'shop_order' ) {
            wp_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
            exit;
        }

        // Redirect if trying to access restricted pages
        if ( ! in_array( $pagenow, $allowed_pages, true ) && strpos( $pagenow, 'vss-' ) === false ) {
            wp_redirect( admin_url( 'edit.php?post_type=shop_order' ) );
            exit;
        }
    }

    /**
     * AJAX handler for saving draft
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
     * AJAX handler for getting order details
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
     * AJAX handler for public order tracking
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
                <a href="<?php echo esc_url( home_url( '/vendor-portal/?vss_action=reports' ) ); ?>" class="button">
                    <?php esc_html_e( 'View Full Reports', 'vss' ); ?>
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
                    <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ) ); ?>">
                        #<?php echo esc_html( $order->get_order_number() ); ?>
                    </a>
                    - <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                    <span class="order-date"><?php echo esc_html( $order->get_date_created()->date_i18n( 'M j' ) ); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="vss-dashboard-link">
            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>">
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
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>">
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
     * Render frontend order details
     *
     * @param int $order_id
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
                <a class="nav-tab" href="#tab-overview"><?php esc_html_e( 'Overview', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-products"><?php esc_html_e( 'Products', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-mockup"><?php esc_html_e( 'Mockup Approval', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-production"><?php esc_html_e( 'Production Files', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-costs"><?php esc_html_e( 'Costs', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-shipping"><?php esc_html_e( 'Shipping', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-notes"><?php esc_html_e( 'Notes', 'vss' ); ?></a>
                <a class="nav-tab" href="#tab-files"><?php esc_html_e( 'Files', 'vss' ); ?></a>
            </div>

            <div id="tab-overview" class="vss-tab-content">
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
        <?php
    }

    /**
     * Render order status bar
     *
     * @param WC_Order $order
     */
    private static function render_order_status_bar( $order ) {
        $statuses = [
            'pending' => __( 'Pending', 'vss' ),
            'processing' => __( 'Processing', 'vss' ),
            'in-production' => __( 'In Production', 'vss' ),
            'shipped' => __( 'Shipped', 'vss' ),
            'completed' => __( 'Completed', 'vss' ),
        ];

        $current_status = $order->get_status();
        ?>
        <div class="vss-order-status-bar">
            <?php foreach ( $statuses as $status => $label ) : ?>
                <div class="status-step <?php echo $status === $current_status ? 'active' : ''; ?> <?php echo array_search( $status, array_keys( $statuses ) ) < array_search( $current_status, array_keys( $statuses ) ) ? 'completed' : ''; ?>">
                    <div class="status-dot"></div>
                    <div class="status-label"><?php echo esc_html( $label ); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render vendor production confirmation section
     *
     * @param WC_Order $order
     */
    private static function render_vendor_production_confirmation_section( $order ) {
        $order_id = $order->get_id();
        $estimated_ship_date = get_post_meta( $order_id, '_vss_estimated_ship_date', true );
        $vendor_confirmed_at = get_post_meta( $order_id, '_vss_vendor_production_confirmed_at', true );
        $admin_confirmed_at = get_post_meta( $order_id, '_vss_admin_production_confirmed_at', true );

        $countdown_text = '';
        $countdown_class = '';

        if ( $estimated_ship_date ) {
            $ship_timestamp = strtotime( $estimated_ship_date );
            $today_timestamp = current_time( 'timestamp' );
            $days_diff = ( $ship_timestamp - $today_timestamp ) / DAY_IN_SECONDS;

            if ( $days_diff < 0 && $order->has_status( 'processing' ) ) {
                $countdown_text = sprintf( _n( '%d DAY LATE', '%d DAYS LATE', abs( round( $days_diff ) ), 'vss' ), abs( round( $days_diff ) ) );
                $countdown_class = 'is-late';
            } elseif ( $days_diff == 0 && $order->has_status( 'processing' ) ) {
                $countdown_text = __( 'Ships Today', 'vss' );
                $countdown_class = 'is-today';
            } elseif ( $days_diff > 0 ) {
                $countdown_text = sprintf( _n( '%d day left', '%d days left', round( $days_diff ), 'vss' ), round( $days_diff ) );
                $countdown_class = 'is-upcoming';
            }
        }
        ?>
        <div class="vss-production-confirmation-fe">
            <h3><?php esc_html_e( 'Production Status', 'vss' ); ?></h3>
            
            <?php if ( $estimated_ship_date ) : ?>
                <div class="vss-confirmation-info-fe">
                    <p><strong><?php esc_html_e( 'Estimated Ship Date:', 'vss' ); ?></strong> 
                        <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $estimated_ship_date ) ) ); ?>
                    </p>
                    <?php if ( $vendor_confirmed_at || $admin_confirmed_at ) : ?>
                        <p><?php esc_html_e( 'Production confirmed', 'vss' ); ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if ( $countdown_text ) : ?>
                    <p class="vss-ship-date-countdown-fe <?php echo esc_attr( $countdown_class ); ?>">
                        <?php echo esc_html( $countdown_text ); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" id="vss_vendor_confirm_production_form">
                <?php wp_nonce_field( 'vss_vendor_confirm_production', '_vss_confirm_nonce' ); ?>
                <input type="hidden" name="vss_fe_action" value="vendor_confirm_production">
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">

                <p>
                    <label for="vss_vendor_estimated_ship_date">
                        <strong><?php esc_html_e( 'Set/Update Estimated Ship Date:', 'vss' ); ?></strong>
                    </label>
                    <input type="text" 
                           id="vss_vendor_estimated_ship_date" 
                           name="vss_vendor_estimated_ship_date" 
                           class="vss-datepicker-fe" 
                           value="<?php echo esc_attr( $estimated_ship_date ); ?>" 
                           placeholder="YYYY-MM-DD" 
                           autocomplete="off" 
                           required>
                </p>

                <p class="vss-form-actions">
                    <button type="submit" class="button button-primary">
                        <?php
                        echo ( $vendor_confirmed_at || $admin_confirmed_at ) 
                            ? esc_html__( 'Update Ship Date', 'vss' ) 
                            : esc_html__( 'Confirm Production & Set Ship Date', 'vss' );
                        ?>
                    </button>
                </p>
                
                <p class="description">
                    <?php esc_html_e( 'Setting this date confirms you are beginning production. The customer will be notified.', 'vss' ); ?>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Render vendor reports
     */
    private static function render_vendor_reports() {
        $vendor_id = get_current_user_id();
        $period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '30';
        
        ?>
        <h2><?php esc_html_e( 'Vendor Reports', 'vss' ); ?></h2>
        
        <div class="vss-vendor-navigation">
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'dashboard', get_permalink() ) ); ?>">
                <?php esc_html_e( 'Dashboard', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'reports', get_permalink() ) ); ?>" class="active">
                <?php esc_html_e( 'Reports', 'vss' ); ?>
            </a>
            <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'settings', get_permalink() ) ); ?>">
                <?php esc_html_e( 'Settings', 'vss' ); ?>
            </a>
        </div>

        <div class="vss-report-filters">
            <form method="get">
                <input type="hidden" name="vss_action" value="reports">
                <label for="period"><?php esc_html_e( 'Time Period:', 'vss' ); ?></label>
                <select name="period" id="period">
                    <option value="7" <?php selected( $period, '7' ); ?>><?php esc_html_e( 'Last 7 days', 'vss' ); ?></option>
                    <option value="30" <?php selected( $period, '30' ); ?>><?php esc_html_e( 'Last 30 days', 'vss' ); ?></option>
                    <option value="90" <?php selected( $period, '90' ); ?>><?php esc_html_e( 'Last 90 days', 'vss' ); ?></option>
                    <option value="365" <?php selected( $period, '365' ); ?>><?php esc_html_e( 'Last year', 'vss' ); ?></option>
                </select>
                <button type="submit" class="button"><?php esc_html_e( 'Update', 'vss' ); ?></button>
            </form>
        </div>

        <?php
        // Calculate date range
        $days = intval( $period );
        $date_after = date( 'Y-m-d', strtotime( "-{$days} days" ) );

        // Get orders for period
        $orders = wc_get_orders( [
            'meta_key' => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'date_after' => $date_after,
            'return' => 'objects',
            'limit' => -1,
        ] );

        // Calculate metrics
        $total_orders = count( $orders );
        $total_earnings = 0;
        $orders_by_status = [];
        $daily_orders = [];
        $product_quantities = [];

        foreach ( $orders as $order ) {
            // Earnings
            $costs = get_post_meta( $order->get_id(), '_vss_order_costs', true );
            if ( isset( $costs['total_cost'] ) ) {
                $total_earnings += floatval( $costs['total_cost'] );
            }

            // Status breakdown
            $status = $order->get_status();
            if ( ! isset( $orders_by_status[ $status ] ) ) {
                $orders_by_status[ $status ] = 0;
            }
            $orders_by_status[ $status ]++;

            // Daily breakdown
            $date_key = $order->get_date_created()->date( 'Y-m-d' );
            if ( ! isset( $daily_orders[ $date_key ] ) ) {
                $daily_orders[ $date_key ] = 0;
            }
            $daily_orders[ $date_key ]++;

            // Product breakdown
            foreach ( $order->get_items() as $item ) {
                $product_name = $item->get_name();
                if ( ! isset( $product_quantities[ $product_name ] ) ) {
                    $product_quantities[ $product_name ] = 0;
                }
                $product_quantities[ $product_name ] += $item->get_quantity();
            }
        }

        // Sort products by quantity
        arsort( $product_quantities );
        ?>

        <div class="vss-report-summary">
            <h3><?php esc_html_e( 'Summary', 'vss' ); ?></h3>
            <div class="vss-stat-boxes">
                <div class="vss-stat-box-fe">
                    <span class="stat-number-fe"><?php echo esc_html( $total_orders ); ?></span>
                    <span class="stat-label-fe"><?php esc_html_e( 'Total Orders', 'vss' ); ?></span>
                </div>
                <div class="vss-stat-box-fe">
                    <span class="stat-number-fe"><?php echo wc_price( $total_earnings ); ?></span>
                    <span class="stat-label-fe"><?php esc_html_e( 'Total Earnings', 'vss' ); ?></span>
                </div>
                <div class="vss-stat-box-fe">
                    <span class="stat-number-fe"><?php echo $total_orders > 0 ? wc_price( $total_earnings / $total_orders ) : wc_price( 0 ); ?></span>
                    <span class="stat-label-fe"><?php esc_html_e( 'Average Order Value', 'vss' ); ?></span>
                </div>
            </div>
        </div>

        <div class="vss-report-details">
            <h3><?php esc_html_e( 'Order Status Breakdown', 'vss' ); ?></h3>
            <table class="vss-report-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Status', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'Count', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'Percentage', 'vss' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $orders_by_status as $status => $count ) : ?>
                        <tr>
                            <td><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></td>
                            <td><?php echo esc_html( $count ); ?></td>
                            <td><?php echo esc_html( round( ( $count / $total_orders ) * 100, 1 ) ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3><?php esc_html_e( 'Top Products', 'vss' ); ?></h3>
            <table class="vss-report-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Product', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'Quantity', 'vss' ); ?></th>
                    </tr>
<tbody>
                   <?php $counter = 0; ?>
                   <?php foreach ( $product_quantities as $product => $quantity ) : ?>
                       <?php if ( ++$counter > 10 ) break; // Show top 10 ?>
                       <tr>
                           <td><?php echo esc_html( $product ); ?></td>
                           <td><?php echo esc_html( $quantity ); ?></td>
                       </tr>
                   <?php endforeach; ?>
               </tbody>
           </table>
       </div>
       <?php
   }

   /**
    * Render vendor settings
    */
   private static function render_vendor_settings() {
       $vendor_id = get_current_user_id();
       $user = wp_get_current_user();
       
       // Handle form submission
       if ( isset( $_POST['vss_save_settings'] ) && wp_verify_nonce( $_POST['vss_settings_nonce'], 'vss_vendor_settings' ) ) {
           // Update user meta
           update_user_meta( $vendor_id, 'vss_company_name', sanitize_text_field( $_POST['company_name'] ) );
           update_user_meta( $vendor_id, 'vss_payment_email', sanitize_email( $_POST['payment_email'] ) );
           update_user_meta( $vendor_id, 'vss_default_production_time', intval( $_POST['default_production_time'] ) );
           update_user_meta( $vendor_id, 'vss_notification_preferences', array_map( 'sanitize_key', $_POST['notifications'] ?? [] ) );
           
           // Update user display name if changed
           if ( isset( $_POST['display_name'] ) && $_POST['display_name'] !== $user->display_name ) {
               wp_update_user( [
                   'ID' => $vendor_id,
                   'display_name' => sanitize_text_field( $_POST['display_name'] ),
               ] );
           }
           
           // Redirect with success message
           wp_redirect( add_query_arg( [
               'vss_action' => 'settings',
               'vss_notice' => 'settings_saved',
           ], get_permalink() ) );
           exit;
       }
       
       // Get current settings
       $company_name = get_user_meta( $vendor_id, 'vss_company_name', true );
       $payment_email = get_user_meta( $vendor_id, 'vss_payment_email', true ) ?: $user->user_email;
       $default_production_time = get_user_meta( $vendor_id, 'vss_default_production_time', true ) ?: 7;
       $notification_prefs = get_user_meta( $vendor_id, 'vss_notification_preferences', true ) ?: [];
       ?>
       
       <h2><?php esc_html_e( 'Vendor Settings', 'vss' ); ?></h2>
       
       <div class="vss-vendor-navigation">
           <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'dashboard', get_permalink() ) ); ?>">
               <?php esc_html_e( 'Dashboard', 'vss' ); ?>
           </a>
           <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'reports', get_permalink() ) ); ?>">
               <?php esc_html_e( 'Reports', 'vss' ); ?>
           </a>
           <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'settings', get_permalink() ) ); ?>" class="active">
               <?php esc_html_e( 'Settings', 'vss' ); ?>
           </a>
       </div>

       <form method="post" class="vss-settings-form">
           <?php wp_nonce_field( 'vss_vendor_settings', 'vss_settings_nonce' ); ?>
           
           <h3><?php esc_html_e( 'Profile Information', 'vss' ); ?></h3>
           <table class="form-table">
               <tr>
                   <th><label for="display_name"><?php esc_html_e( 'Display Name', 'vss' ); ?></label></th>
                   <td>
                       <input type="text" name="display_name" id="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" class="regular-text" />
                   </td>
               </tr>
               <tr>
                   <th><label for="company_name"><?php esc_html_e( 'Company Name', 'vss' ); ?></label></th>
                   <td>
                       <input type="text" name="company_name" id="company_name" value="<?php echo esc_attr( $company_name ); ?>" class="regular-text" />
                   </td>
               </tr>
               <tr>
                   <th><label for="payment_email"><?php esc_html_e( 'Payment Email', 'vss' ); ?></label></th>
                   <td>
                       <input type="email" name="payment_email" id="payment_email" value="<?php echo esc_attr( $payment_email ); ?>" class="regular-text" />
                       <p class="description"><?php esc_html_e( 'Email address for receiving payment notifications and invoices.', 'vss' ); ?></p>
                   </td>
               </tr>
           </table>

           <h3><?php esc_html_e( 'Production Settings', 'vss' ); ?></h3>
           <table class="form-table">
               <tr>
                   <th><label for="default_production_time"><?php esc_html_e( 'Default Production Time', 'vss' ); ?></label></th>
                   <td>
                       <input type="number" name="default_production_time" id="default_production_time" value="<?php echo esc_attr( $default_production_time ); ?>" min="1" max="30" class="small-text" />
                       <?php esc_html_e( 'days', 'vss' ); ?>
                       <p class="description"><?php esc_html_e( 'Default number of days for production when setting ship dates.', 'vss' ); ?></p>
                   </td>
               </tr>
           </table>

           <h3><?php esc_html_e( 'Email Notifications', 'vss' ); ?></h3>
           <table class="form-table">
               <tr>
                   <th><?php esc_html_e( 'Notify me when:', 'vss' ); ?></th>
                   <td>
                       <label>
                           <input type="checkbox" name="notifications[]" value="new_order" <?php checked( in_array( 'new_order', $notification_prefs ) ); ?> />
                           <?php esc_html_e( 'New order is assigned to me', 'vss' ); ?>
                       </label><br>
                       <label>
                           <input type="checkbox" name="notifications[]" value="approval_response" <?php checked( in_array( 'approval_response', $notification_prefs ) ); ?> />
                           <?php esc_html_e( 'Customer responds to mockup/production file', 'vss' ); ?>
                       </label><br>
                       <label>
                           <input type="checkbox" name="notifications[]" value="order_note" <?php checked( in_array( 'order_note', $notification_prefs ) ); ?> />
                           <?php esc_html_e( 'Admin adds a note to my order', 'vss' ); ?>
                       </label><br>
                       <label>
                           <input type="checkbox" name="notifications[]" value="weekly_summary" <?php checked( in_array( 'weekly_summary', $notification_prefs ) ); ?> />
                           <?php esc_html_e( 'Send weekly performance summary', 'vss' ); ?>
                       </label>
                   </td>
               </tr>
           </table>

           <p class="submit">
               <button type="submit" name="vss_save_settings" class="button button-primary">
                   <?php esc_html_e( 'Save Settings', 'vss' ); ?>
               </button>
           </p>
       </form>
       <?php
   }

   /**
    * Render order overview tab
    */
   private static function render_order_overview( $order ) {
       $order_id = $order->get_id();
       ?>
       <div class="vss-order-overview">
           <h4><?php esc_html_e( 'Order Information', 'vss' ); ?></h4>
           <div class="order-info-grid">
               <div class="info-item">
                   <strong><?php esc_html_e( 'Order Number:', 'vss' ); ?></strong>
                   <?php echo esc_html( $order->get_order_number() ); ?>
               </div>
               <div class="info-item">
                   <strong><?php esc_html_e( 'Order Date:', 'vss' ); ?></strong>
                   <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
               </div>
               <div class="info-item">
                   <strong><?php esc_html_e( 'Status:', 'vss' ); ?></strong>
                   <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
               </div>
               <div class="info-item">
                   <strong><?php esc_html_e( 'Total Value:', 'vss' ); ?></strong>
                   <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
               </div>
           </div>

           <h4><?php esc_html_e( 'Customer Information', 'vss' ); ?></h4>
           <div class="customer-info">
               <p>
                   <strong><?php esc_html_e( 'Name:', 'vss' ); ?></strong>
                   <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?>
               </p>
               <p>
                   <strong><?php esc_html_e( 'Email:', 'vss' ); ?></strong>
                   <a href="mailto:<?php echo esc_attr( $order->get_billing_email() ); ?>">
                       <?php echo esc_html( $order->get_billing_email() ); ?>
                   </a>
               </p>
               <?php if ( $order->get_billing_phone() ) : ?>
                   <p>
                       <strong><?php esc_html_e( 'Phone:', 'vss' ); ?></strong>
                       <a href="tel:<?php echo esc_attr( $order->get_billing_phone() ); ?>">
                           <?php echo esc_html( $order->get_billing_phone() ); ?>
                       </a>
                   </p>
               <?php endif; ?>
           </div>

           <h4><?php esc_html_e( 'Shipping Information', 'vss' ); ?></h4>
           <div class="shipping-info">
               <p><?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?></p>
               <?php if ( $order->get_shipping_method() ) : ?>
                   <p>
                       <strong><?php esc_html_e( 'Shipping Method:', 'vss' ); ?></strong>
                       <?php echo esc_html( $order->get_shipping_method() ); ?>
                   </p>
               <?php endif; ?>
           </div>

           <?php if ( $order->get_customer_note() ) : ?>
               <h4><?php esc_html_e( 'Customer Note', 'vss' ); ?></h4>
               <div class="customer-note">
                   <p><?php echo wp_kses_post( wpautop( $order->get_customer_note() ) ); ?></p>
               </div>
           <?php endif; ?>
       </div>
       <?php
   }

   /**
    * Render order products tab
    */
   private static function render_order_products( $order ) {
       ?>
       <div class="vss-order-products">
           <h4><?php esc_html_e( 'Order Items', 'vss' ); ?></h4>
           <table class="vss-products-table">
               <thead>
                   <tr>
                       <th><?php esc_html_e( 'Product', 'vss' ); ?></th>
                       <th><?php esc_html_e( 'SKU', 'vss' ); ?></th>
                       <th><?php esc_html_e( 'Quantity', 'vss' ); ?></th>
                       <th><?php esc_html_e( 'Customization', 'vss' ); ?></th>
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
                           <td>
                               <?php echo esc_html( $item->get_name() ); ?>
                               <?php if ( $item->get_variation_id() ) : ?>
                                <br><small><?php echo wc_get_formatted_variation( $item, true ); ?></small>
                               <?php endif; ?>
                           </td>
                           <td><?php echo $product ? esc_html( $product->get_sku() ) : '—'; ?></td>
                           <td><?php echo esc_html( $item->get_quantity() ); ?></td>
                           <td>
                               <?php if ( $zakeke_data ) : ?>
                                   <span class="dashicons dashicons-yes-alt" style="color: #4caf50;"></span>
                                   <?php esc_html_e( 'Zakeke Design', 'vss' ); ?>
                               <?php else : ?>
                                   <span style="color: #999;">—</span>
                               <?php endif; ?>
                           </td>
                           <td>
                               <?php if ( $zip_url ) : ?>
                                   <a href="<?php echo esc_url( $zip_url ); ?>" class="button button-small" target="_blank">
                                       <?php esc_html_e( 'Download ZIP', 'vss' ); ?>
                                   </a>
                               <?php elseif ( $zakeke_data ) : ?>
                                   <?php
                                   $parsed_data = is_string( $zakeke_data ) ? json_decode( $zakeke_data, true ) : (array) $zakeke_data;
                                   $design_id = isset( $parsed_data['design'] ) ? $parsed_data['design'] : '';
                                   ?>
                                   <button class="vss-manual-fetch-zakeke-zip button button-small" 
                                           data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                                           data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                           data-zakeke-design-id="<?php echo esc_attr( $design_id ); ?>">
                                       <?php esc_html_e( 'Fetch ZIP', 'vss' ); ?>
                                   </button>
                                   <span class="vss-fetch-zip-feedback"></span>
                               <?php else : ?>
                                   <span style="color: #999;">—</span>
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
    * Render costs section
    */
   private static function render_costs_section( $order ) {
       $order_id = $order->get_id();
       $saved_costs = get_post_meta( $order_id, '_vss_order_costs', true ) ?: [];
       ?>
       <div class="vss-costs-section">
           <h4><?php esc_html_e( 'Order Costs', 'vss' ); ?></h4>
           <form method="post" class="vss-costs-form">
               <?php wp_nonce_field( 'vss_save_costs', 'vss_costs_nonce' ); ?>
               <input type="hidden" name="vss_fe_action" value="save_costs">
               <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">

               <table class="vss-costs-input-table-fe">
                   <thead>
                       <tr>
                           <th><?php esc_html_e( 'Item', 'vss' ); ?></th>
                           <th><?php esc_html_e( 'Quantity', 'vss' ); ?></th>
                           <th><?php esc_html_e( 'Your Cost', 'vss' ); ?></th>
                       </tr>
                   </thead>
                   <tbody>
                       <?php foreach ( $order->get_items() as $item_id => $item ) : ?>
                           <tr>
                               <td>
                                   <strong><?php echo esc_html( $item->get_name() ); ?></strong>
                                   <?php if ( $product = $item->get_product() ) : ?>
                                       <br><small><?php echo esc_html( $product->get_sku() ); ?></small>
                                   <?php endif; ?>
                               </td>
                               <td><?php echo esc_html( $item->get_quantity() ); ?></td>
                               <td>
                                   <?php echo get_woocommerce_currency_symbol(); ?>
                                   <input type="text" 
                                          name="item_costs[<?php echo esc_attr( $item_id ); ?>]" 
                                          class="vss-cost-input-fe" 
                                          value="<?php echo isset( $saved_costs['line_items'][$item_id] ) ? esc_attr( $saved_costs['line_items'][$item_id] ) : ''; ?>"
                                          placeholder="0.00">
                               </td>
                           </tr>
                       <?php endforeach; ?>
                       <tr>
                           <td colspan="2"><strong><?php esc_html_e( 'Shipping Cost', 'vss' ); ?></strong></td>
                           <td>
                               <?php echo get_woocommerce_currency_symbol(); ?>
                               <input type="text" 
                                      name="shipping_cost" 
                                      class="vss-cost-input-fe" 
                                      value="<?php echo isset( $saved_costs['shipping_cost'] ) ? esc_attr( $saved_costs['shipping_cost'] ) : ''; ?>"
                                      placeholder="0.00">
                           </td>
                       </tr>
                   </tbody>
               </table>

               <div class="totals-section">
                   <p><strong><?php esc_html_e( 'Total Cost:', 'vss' ); ?></strong> 
                       <span id="vss-total-cost-display-fe" data-currency="<?php echo esc_attr( get_woocommerce_currency_symbol() ); ?>">
                           <?php echo get_woocommerce_currency_symbol(); ?>0.00
                       </span>
                   </p>
               </div>

               <p class="vss-form-actions">
                   <button type="submit" class="button button-primary">
                       <?php esc_html_e( 'Save Costs', 'vss' ); ?>
                   </button>
               </p>
           </form>
       </div>
       <?php
   }

   /**
    * Render shipping section
    */
   private static function render_shipping_section( $order ) {
       $order_id = $order->get_id();
       $tracking_number = get_post_meta( $order_id, '_vss_tracking_number', true );
       $tracking_carrier = get_post_meta( $order_id, '_vss_tracking_carrier', true );
       $shipped_at = get_post_meta( $order_id, '_vss_shipped_at', true );
       ?>
       <div class="vss-shipping-section">
           <h4><?php esc_html_e( 'Shipping Information', 'vss' ); ?></h4>
           
           <?php if ( $shipped_at ) : ?>
               <div class="vss-shipping-info">
                   <p><strong><?php esc_html_e( 'Shipped On:', 'vss' ); ?></strong> 
                       <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $shipped_at ) ); ?>
                   </p>
                   <?php if ( $tracking_number ) : ?>
                       <p><strong><?php esc_html_e( 'Tracking Number:', 'vss' ); ?></strong> 
                           <?php echo esc_html( $tracking_number ); ?>
                       </p>
                       <p><strong><?php esc_html_e( 'Carrier:', 'vss' ); ?></strong> 
                           <?php echo esc_html( $tracking_carrier ?: __( 'Standard Shipping', 'vss' ) ); ?>
                       </p>
                   <?php endif; ?>
               </div>
           <?php endif; ?>

           <?php if ( $order->has_status( 'processing' ) ) : ?>
               <form method="post" class="vss-shipping-form">
                   <?php wp_nonce_field( 'vss_save_tracking', 'vss_tracking_nonce' ); ?>
                   <input type="hidden" name="vss_fe_action" value="save_tracking">
                   <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">

                   <p>
                       <label for="tracking_carrier">
                           <strong><?php esc_html_e( 'Shipping Carrier:', 'vss' ); ?></strong>
                       </label>
                       <select name="tracking_carrier" id="tracking_carrier">
                           <option value=""><?php esc_html_e( 'Select Carrier', 'vss' ); ?></option>
                           <option value="ups" <?php selected( $tracking_carrier, 'ups' ); ?>>UPS</option>
                           <option value="fedex" <?php selected( $tracking_carrier, 'fedex' ); ?>>FedEx</option>
                           <option value="usps" <?php selected( $tracking_carrier, 'usps' ); ?>>USPS</option>
                           <option value="dhl" <?php selected( $tracking_carrier, 'dhl' ); ?>>DHL</option>
                           <option value="other" <?php selected( $tracking_carrier, 'other' ); ?>><?php esc_html_e( 'Other', 'vss' ); ?></option>
                       </select>
                   </p>

                   <p>
                       <label for="tracking_number">
                           <strong><?php esc_html_e( 'Tracking Number:', 'vss' ); ?></strong>
                       </label>
                       <input type="text" 
                              name="tracking_number" 
                              id="tracking_number" 
                              value="<?php echo esc_attr( $tracking_number ); ?>" 
                              placeholder="<?php esc_attr_e( 'Enter tracking number', 'vss' ); ?>">
                   </p>

                   <p class="vss-form-actions">
                       <button type="submit" class="button button-primary">
                           <?php esc_html_e( 'Save & Mark as Shipped', 'vss' ); ?>
                       </button>
                   </p>
                   
                   <p class="description">
                       <?php esc_html_e( 'Saving tracking information will mark this order as shipped and notify the customer.', 'vss' ); ?>
                   </p>
               </form>
           <?php endif; ?>
       </div>
       <?php
   }

   /**
    * Render notes section
    */
   private static function render_notes_section( $order ) {
       $order_id = $order->get_id();
       $notes = get_post_meta( $order_id, '_vss_private_notes', true ) ?: [];
       ?>
       <div class="vss-private-notes-fe">
           <h4><?php esc_html_e( 'Order Notes', 'vss' ); ?></h4>
           
           <div class="vss-notes-list-fe">
               <?php if ( empty( $notes ) ) : ?>
                   <p><?php esc_html_e( 'No notes yet.', 'vss' ); ?></p>
               <?php else : ?>
                   <?php foreach ( array_reverse( $notes ) as $note ) : ?>
                       <?php
                       $user = get_userdata( $note['user_id'] );
                       $author_name = $user ? $user->display_name : __( 'Unknown', 'vss' );
                       $author_class = '';
                       if ( $user && user_can( $user, 'manage_woocommerce' ) ) {
                           $author_class = 'is-admin';
                       }
                       ?>
                       <div class="vss-note-fe">
                           <p>
                               <strong class="vss-note-author-fe <?php echo esc_attr( $author_class ); ?>">
                                   <?php echo esc_html( $author_name ); ?>
                               </strong>
                               <span class="vss-note-date-fe">
                                   <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $note['timestamp'] ) ); ?>
                               </span>
                           </p>
                           <div class="vss-note-content-fe">
                               <?php echo wp_kses_post( wpautop( $note['note'] ) ); ?>
                           </div>
                       </div>
                   <?php endforeach; ?>
               <?php endif; ?>
           </div>

           <form method="post" class="vss-add-note-form">
               <?php wp_nonce_field( 'vss_add_note', 'vss_note_nonce' ); ?>
               <input type="hidden" name="vss_fe_action" value="add_note">
               <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">

               <p>
                   <label for="vss_new_note">
                       <strong><?php esc_html_e( 'Add New Note:', 'vss' ); ?></strong>
                   </label>
                   <textarea name="vss_new_note" 
                             id="vss_new_note" 
                             rows="4" 
                             placeholder="<?php esc_attr_e( 'Type your note here...', 'vss' ); ?>" 
                             required></textarea>
               </p>

               <p class="vss-form-actions">
                   <button type="submit" class="button">
                       <?php esc_html_e( 'Add Note', 'vss' ); ?>
                   </button>
               </p>
           </form>
       </div>
       <?php
   }

   /**
    * Render files section
    */
   private static function render_files_section( $order ) {
       $order_id = $order->get_id();
       $attached_file_id = get_post_meta( $order_id, '_vss_attached_zip_id', true );
       ?>
       <div class="vss-files-section">
           <h4><?php esc_html_e( 'Order Files', 'vss' ); ?></h4>
           
           <?php if ( $attached_file_id && get_post_status( $attached_file_id ) ) : ?>
               <?php
               $file_url = wp_get_attachment_url( $attached_file_id );
               $file_path = get_attached_file( $attached_file_id );
               $file_name = $file_path ? basename( $file_path ) : __( 'Attached File', 'vss' );
               ?>
               <div class="vss-download-file">
                   <p><strong><?php esc_html_e( 'Admin Uploaded File:', 'vss' ); ?></strong></p>
                   <p>
                       <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="button">
                           <span class="dashicons dashicons-download"></span>
                           <?php echo esc_html( $file_name ); ?>
                       </a>
                   </p>
               </div>
           <?php endif; ?>

           <h5><?php esc_html_e( 'Zakeke Design Files', 'vss' ); ?></h5>
           <?php
           $has_zakeke = false;
           foreach ( $order->get_items() as $item_id => $item ) {
               $zip_url = $item->get_meta( '_vss_zakeke_printing_files_zip_url', true );
               if ( $zip_url ) {
                   $has_zakeke = true;
                   ?>
                   <div class="vss-zakeke-file">
                       <p>
                           <strong><?php echo esc_html( $item->get_name() ); ?>:</strong>
                           <a href="<?php echo esc_url( $zip_url ); ?>" class="button button-small" target="_blank">
                               <span class="dashicons dashicons-download"></span>
                               <?php esc_html_e( 'Download Print Files', 'vss' ); ?>
                           </a>
                       </p>
                   </div>
                   <?php
               }
           }
           
           if ( ! $has_zakeke ) {
               echo '<p>' . esc_html__( 'No Zakeke design files available yet.', 'vss' ) . '</p>';
           }
           ?>

           <h5><?php esc_html_e( 'Approval Files', 'vss' ); ?></h5>
           <?php
           $mockup_files = get_post_meta( $order_id, '_vss_mockup_files', true ) ?: [];
           $production_files = get_post_meta( $order_id, '_vss_production_file_files', true ) ?: [];
           
           if ( ! empty( $mockup_files ) ) : ?>
               <p><strong><?php esc_html_e( 'Mockup Files:', 'vss' ); ?></strong></p>
               <div class="vss-approval-files-list">
                   <?php foreach ( $mockup_files as $file_id ) : ?>
                       <?php if ( $file_url = wp_get_attachment_url( $file_id ) ) : ?>
                           <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="button button-small">
                               <?php echo esc_html( basename( get_attached_file( $file_id ) ) ); ?>
                           </a>
                       <?php endif; ?>
                   <?php endforeach; ?>
               </div>
           <?php endif; ?>
           
           <?php if ( ! empty( $production_files ) ) : ?>
               <p><strong><?php esc_html_e( 'Production Files:', 'vss' ); ?></strong></p>
               <div class="vss-approval-files-list">
                   <?php foreach ( $production_files as $file_id ) : ?>
                       <?php if ( $file_url = wp_get_attachment_url( $file_id ) ) : ?>
                           <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="button button-small">
                               <?php echo esc_html( basename( get_attached_file( $file_id ) ) ); ?>
                           </a>
                       <?php endif; ?>
                   <?php endforeach; ?>
               </div>
           <?php endif; ?>
       </div>
       <?php
   }

   /**
    * Handle save costs
    */
   private static function handle_save_costs( $order, &$redirect_args ) {
       if ( ! wp_verify_nonce( $_POST['vss_costs_nonce'], 'vss_save_costs' ) ) {
           wp_die( __( 'Security check failed.', 'vss' ) );
       }

       $order_id = $order->get_id();
       $item_costs = isset( $_POST['item_costs'] ) ? array_map( 'floatval', $_POST['item_costs'] ) : [];
       $shipping_cost = isset( $_POST['shipping_cost'] ) ? floatval( $_POST['shipping_cost'] ) : 0;

       // Calculate total
       $total_cost = array_sum( $item_costs ) + $shipping_cost;

       // Save costs
       $costs_data = [
           'line_items' => $item_costs,
           'shipping_cost' => $shipping_cost,
           'total_cost' => $total_cost,
           'saved_at' => time(),
           'saved_by' => get_current_user_id(),
       ];

       update_post_meta( $order_id, '_vss_order_costs', $costs_data );

       // Add order note
       $order->add_order_note( sprintf(
           __( 'Vendor submitted costs: Total %s', 'vss' ),
           wc_price( $total_cost )
       ) );

       // Log activity
       Vendor_Order_Manager::log_activity( 'vendor_costs_submitted', [
           'order_id' => $order_id,
           'total_cost' => $total_cost,
       ] );

       $redirect_args['vss_notice'] = 'costs_saved';
       $redirect_args['#'] = 'tab-costs';
       wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
       exit;
   }

   /**
    * Handle save tracking
    */
   private static function handle_save_tracking( $order, &$redirect_args ) {
       if ( ! wp_verify_nonce( $_POST['vss_tracking_nonce'], 'vss_save_tracking' ) ) {
           wp_die( __( 'Security check failed.', 'vss' ) );
       }

       $order_id = $order->get_id();
       $tracking_number = isset( $_POST['tracking_number'] ) ? sanitize_text_field( $_POST['tracking_number'] ) : '';
       $tracking_carrier = isset( $_POST['tracking_carrier'] ) ? sanitize_key( $_POST['tracking_carrier'] ) : '';

       if ( empty( $tracking_number ) ) {
           $redirect_args['vss_error'] = 'tracking_required';
           $redirect_args['#'] = 'tab-shipping';
           wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
           exit;
       }

       // Save tracking info
       update_post_meta( $order_id, '_vss_tracking_number', $tracking_number );
       update_post_meta( $order_id, '_vss_tracking_carrier', $tracking_carrier );
       update_post_meta( $order_id, '_vss_shipped_at', time() );

       // Update order status to shipped
       $order->update_status( 'shipped', sprintf(
           __( 'Order shipped by vendor. Tracking: %s', 'vss' ),
           $tracking_number
       ) );

       // Send customer notification
       // VSS_Emails::send_customer_shipping_notification( $order_id, $tracking_number, $tracking_carrier );

       // Log activity
       Vendor_Order_Manager::log_activity( 'vendor_order_shipped', [
           'order_id' => $order_id,
           'tracking_number' => $tracking_number,
           'carrier' => $tracking_carrier,
       ] );

       $redirect_args['vss_notice'] = 'tracking_saved';
       wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
       exit;
   }

   /**
    * Handle add note
    */
   private static function handle_add_note( $order, &$redirect_args ) {
       if ( ! wp_verify_nonce( $_POST['vss_note_nonce'], 'vss_add_note' ) ) {
           wp_die( __( 'Security check failed.', 'vss' ) );
       }

       $order_id = $order->get_id();
       $new_note = isset( $_POST['vss_new_note'] ) ? sanitize_textarea_field( $_POST['vss_new_note'] ) : '';

       if ( empty( $new_note ) ) {
           $redirect_args['vss_error'] = 'note_required';
           $redirect_args['#'] = 'tab-notes';
           wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
           exit;
       }

       // Get existing notes
       $notes = get_post_meta( $order_id, '_vss_private_notes', true ) ?: [];

       // Add new note
       $notes[] = [
           'note' => $new_note,
           'user_id' => get_current_user_id(),
           'timestamp' => time(),
       ];

       // Save notes
       update_post_meta( $order_id, '_vss_private_notes', $notes );

       // Add order note for history
       $order->add_order_note( __( 'Vendor added an internal note.', 'vss' ) );

       $redirect_args['vss_notice'] = 'note_added';
       $redirect_args['#'] = 'tab-notes';
       wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
       exit;
   }

   /**
    * Render approval section
    *
    * @param WC_Order $order
    * @param string $type
    */
   private static function render_approval_section( $order, $type ) {
       $order_id = $order->get_id();
       $status = get_post_meta( $order_id, "_vss_{$type}_status", true ) ?: 'none';
       $files_ids = get_post_meta( $order_id, "_vss_{$type}_files", true ) ?: [];
       $vendor_notes = get_post_meta( $order_id, "_vss_{$type}_vendor_notes", true );
       $customer_notes = get_post_meta( $order_id, "_vss_{$type}_customer_notes", true );
       $sent_at = get_post_meta( $order_id, "_vss_{$type}_sent_at", true );
       $responded_at = get_post_meta( $order_id, "_vss_{$type}_responded_at", true );

       $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
       ?>
       <div class="vss-approval-section">
           <h3><?php echo esc_html( $type_label ); ?> <?php esc_html_e( 'Approval', 'vss' ); ?></h3>
           
           <div class="approval-status approval-status-<?php echo esc_attr( $status ); ?>">
               <p><strong><?php esc_html_e( 'Status:', 'vss' ); ?></strong> <?php echo esc_html( self::get_status_label( $status ) ); ?></p>
               
               <?php if ( $sent_at ) : ?>
                   <p><?php printf( __( 'Sent on %s', 'vss' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $sent_at ) ); ?></p>
               <?php endif; ?>
               
               <?php if ( $responded_at ) : ?>
                   <p><?php printf( __( 'Customer responded on %s', 'vss' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $responded_at ) ); ?></p>
               <?php endif; ?>
           </div>

           <?php if ( $status === 'disapproved' && $customer_notes ) : ?>
               <div class="vss-customer-feedback">
                   <h4><?php esc_html_e( 'Customer Feedback:', 'vss' ); ?></h4>
                   <p><?php echo nl2br( esc_html( $customer_notes ) ); ?></p>
               </div>
           <?php endif; ?>

           <?php if ( ! empty( $files_ids ) ) : ?>
               <div class="vss-submitted-files">
                   <h4><?php esc_html_e( 'Submitted Files:', 'vss' ); ?></h4>
                   <div class="vss-submitted-approval-files">
                       <?php foreach ( $files_ids as $file_id ) : ?>
                           <?php
                           $file_url = wp_get_attachment_url( $file_id );
                           $file_name = basename( get_attached_file( $file_id ) );
                           if ( $file_url ) :
                           ?>
                               <div class="vss-approval-file-item">
                                   <a href="<?php echo esc_url( $file_url ); ?>" target="_blank">
                                       <?php if ( wp_attachment_is_image( $file_id ) ) : ?>
                                           <?php echo wp_get_attachment_image( $file_id, 'thumbnail' ); ?>
                                       <?php else : ?>
                                           <span class="dashicons dashicons-media-default"></span>
                                       <?php endif; ?>
                                       <span class="vss-approval-file-name"><?php echo esc_html( $file_name ); ?></span>
                                   </a>
                               </div>
                           <?php endif; ?>
                       <?php endforeach; ?>
                   </div>
               </div>
           <?php endif; ?>

           <?php if ( $vendor_notes ) : ?>
               <div class="vss-vendor-notes">
                   <h4><?php esc_html_e( 'Your Notes:', 'vss' ); ?></h4>
                   <p><?php echo nl2br( esc_html( $vendor_notes ) ); ?></p>
               </div>
           <?php endif; ?>

           <?php if ( in_array( $status, [ 'none', 'disapproved' ], true ) ) : ?>
               <form method="post" enctype="multipart/form-data" class="vss-approval-form">
                   <?php wp_nonce_field( "vss_send_{$type}_for_approval", "_vss_{$type}_nonce" ); ?>
                   <input type="hidden" name="vss_fe_action" value="send_<?php echo esc_attr( $type ); ?>_for_approval">
                   <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
                   <input type="hidden" name="approval_type" value="<?php echo esc_attr( $type ); ?>">

                   <p>
                       <label for="vss_<?php echo esc_attr( $type ); ?>_files">
                           <strong><?php esc_html_e( 'Upload Files:', 'vss' ); ?></strong>
                       </label>
                       <input type="file" 
                              id="vss_<?php echo esc_attr( $type ); ?>_files" 
                              name="vss_approval_files[]" 
                              multiple="multiple" 
                              accept="image/*,application/pdf,.ai,.eps,.svg"
                              <?php echo $status === 'none' ? 'required' : ''; ?>>
                       <span class="description"><?php esc_html_e( 'Accepted: JPG, PNG, GIF, PDF, AI, EPS, SVG', 'vss' ); ?></span>
                   </p>

                   <p>
                       <label for="vss_<?php echo esc_attr( $type ); ?>_notes">
                           <strong><?php esc_html_e( 'Notes for Customer (Optional):', 'vss' ); ?></strong>
                       </label>
                       <textarea id="vss_<?php echo esc_attr( $type ); ?>_notes" 
                                 name="vss_vendor_notes" 
                                 rows="4" 
                                 style="width: 100%;"><?php echo esc_textarea( $vendor_notes ); ?></textarea>
                   </p>

                   <p class="vss-form-actions">
                       <button type="submit" class="button button-primary">
                           <?php echo $status === 'disapproved' ? esc_html__( 'Re-submit for Approval', 'vss' ) : esc_html__( 'Send for Approval', 'vss' ); ?>
                       </button>
                   </p>
               </form>
           <?php endif; ?>
       </div>
       <?php
   }

   /**
    * Get status label
    *
    * @param string $status
    * @return string
    */
   private static function get_status_label( $status ) {
       $labels = [
           'none' => __( 'Not Sent', 'vss' ),
           'pending_approval' => __( 'Pending Customer Approval', 'vss' ),
           'approved' => __( 'Approved by Customer', 'vss' ),
           'disapproved' => __( 'Changes Requested', 'vss' ),
       ];

       return isset( $labels[ $status ] ) ? $labels[ $status ] : __( 'Unknown', 'vss' );
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
    *
    * @param WC_Order $order
    * @param array $redirect_args
    */
   private static function handle_production_confirmation( $order, &$redirect_args ) {
       if ( ! wp_verify_nonce( $_POST['_vss_confirm_nonce'], 'vss_vendor_confirm_production' ) ) {
           wp_die( __( 'Security check failed.', 'vss' ) );
       }

       $estimated_ship_date = isset( $_POST['vss_vendor_estimated_ship_date'] ) ? sanitize_text_field( $_POST['vss_vendor_estimated_ship_date'] ) : '';

       if ( empty( $estimated_ship_date ) ) {
           $redirect_args['vss_error'] = 'date_required';
           wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
           exit;
       }

       if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $estimated_ship_date ) ) {
           $redirect_args['vss_error'] = 'date_format';
           wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
           exit;
       }

       $order_id = $order->get_id();
       update_post_meta( $order_id, '_vss_estimated_ship_date', $estimated_ship_date );
       update_post_meta( $order_id, '_vss_vendor_production_confirmed_at', time() );

       // Add order note
       $order->add_order_note( sprintf( 
           __( 'Production confirmed by vendor. Estimated ship date: %s', 'vss' ), 
           date_i18n( get_option( 'date_format' ), strtotime( $estimated_ship_date ) ) 
       ) );

       // Send customer notification if first confirmation
       $admin_confirmed = get_post_meta( $order_id, '_vss_admin_production_confirmed_at', true );
       if ( ! $admin_confirmed ) {
           VSS_Emails::send_customer_production_confirmation_email( 
               $order_id, 
               $order->get_order_number(), 
               $estimated_ship_date 
           );
       }

       // Log activity
       Vendor_Order_Manager::log_activity( 'vendor_production_confirmed', [
           'order_id' => $order_id,
           'ship_date' => $estimated_ship_date,
       ] );

       $redirect_args['vss_notice'] = 'production_confirmed';
       wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
       exit;
   }

   /**
    * Handle approval submission
    *
    * @param WC_Order $order
    * @param string $action
    * @param array $redirect_args
    */
   private static function handle_approval_submission( $order, $action, &$redirect_args ) {
       $type = strpos( $action, 'mockup' ) !== false ? 'mockup' : 'production_file';
       
       if ( ! wp_verify_nonce( $_POST["_vss_{$type}_nonce"], "vss_send_{$type}_for_approval" ) ) {
           wp_die( __( 'Security check failed.', 'vss' ) );
       }

       $order_id = $order->get_id();
       $vendor_notes = isset( $_POST['vss_vendor_notes'] ) ? sanitize_textarea_field( $_POST['vss_vendor_notes'] ) : '';
       $uploaded_file_ids = [];

       // Handle file uploads
       if ( ! empty( $_FILES['vss_approval_files']['name'][0] ) ) {
           require_once ABSPATH . 'wp-admin/includes/file.php';
           require_once ABSPATH . 'wp-admin/includes/image.php';
           require_once ABSPATH . 'wp-admin/includes/media.php';

           $files = $_FILES['vss_approval_files'];
           $allowed_types = [
               'jpg|jpeg|jpe' => 'image/jpeg',
               'gif' => 'image/gif',
               'png' => 'image/png',
               'pdf' => 'application/pdf',
               'ai' => 'application/postscript',
               'eps' => 'application/postscript',
               'svg' => 'image/svg+xml',
           ];

           for ( $i = 0; $i < count( $files['name'] ); $i++ ) {
               if ( empty( $files['name'][$i] ) ) {
                   continue;
               }

               $file = [
                   'name' => $files['name'][$i],
                   'type' => $files['type'][$i],
                   'tmp_name' => $files['tmp_name'][$i],
                   'error' => $files['error'][$i],
                   'size' => $files['size'][$i],
               ];

               $upload = wp_handle_upload( $file, [ 'test_form' => false, 'mimes' => $allowed_types ] );

               if ( ! empty( $upload['error'] ) ) {
                   $redirect_args['vss_error'] = 'file_upload_failed';
                   wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
                   exit;
               }

               $attachment = [
                   'post_mime_type' => $upload['type'],
                   'post_title' => sanitize_file_name( $files['name'][$i] ),
                   'post_content' => '',
                   'post_status' => 'inherit',
               ];

               $attach_id = wp_insert_attachment( $attachment, $upload['file'], $order_id );
               $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
               wp_update_attachment_metadata( $attach_id, $attach_data );

               $uploaded_file_ids[] = $attach_id;
           }
       }

       // Use existing files if re-submitting and no new files were uploaded
       if ( empty( $uploaded_file_ids ) ) {
           $existing_files = get_post_meta( $order_id, "_vss_{$type}_files", true );
           if ( ! empty( $existing_files ) ) {
               $uploaded_file_ids = $existing_files;
           } else {
               $redirect_args['vss_error'] = 'no_files_uploaded';
               $redirect_args['#'] = 'tab-' . str_replace( '_', '-', $type );
               wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
               exit;
           }
       }

       // Update meta
       update_post_meta( $order_id, "_vss_{$type}_files", $uploaded_file_ids );
       update_post_meta( $order_id, "_vss_{$type}_status", 'pending_approval' );
       update_post_meta( $order_id, "_vss_{$type}_vendor_notes", $vendor_notes );
       update_post_meta( $order_id, "_vss_{$type}_sent_at", time() );
       delete_post_meta( $order_id, "_vss_{$type}_customer_notes" );
       delete_post_meta( $order_id, "_vss_{$type}_responded_at" );

       // Send email
       VSS_Emails::send_customer_approval_request_email( $order_id, $type );

       // Log activity
       Vendor_Order_Manager::log_activity( "vendor_{$type}_submitted", [
           'order_id' => $order_id,
           'files_count' => count( $uploaded_file_ids ),
       ] );

       $redirect_args['vss_notice'] = "{$type}_sent";
       $redirect_args['#'] = 'tab-' . str_replace( '_', '-', $type );
       wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
       exit;
   }

   /**
    * AJAX handler for manual Zakeke ZIP fetch
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

       // Fetch from Zakeke API
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

       wp_send_json_error( [ 'message' => __( 'Print files not yet available. Please try again later.', 'vss' ) ] );
   }

   /**
    * Filter orders for vendor in admin
    *
    * @param WP_Query $query
    */
   // Find this function around line 1807
public static function filter_orders_for_vendor_in_admin( $query ) {
    if ( ! is_admin() || ! self::is_current_user_vendor() || ! $query->is_main_query() ) {
        return;
    }

    global $pagenow;
    if ( $pagenow === 'edit.php' && isset( $query->query_vars['post_type'] ) && $query->query_vars['post_type'] === 'shop_order' ) {
        // This is the original, problematic code
        // $query->set( 'meta_key', '_vss_vendor_user_id' );
        // $query->set( 'meta_value', get_current_user_id() );

        // REPLACE with this more robust code:
        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = [];
        }
        $meta_query[] = [
            'key' => '_vss_vendor_user_id',
            'value' => get_current_user_id(),
            'compare' => '=',
        ];
        $query->set( 'meta_query', $meta_query );
    }
}

   /**
    * Restrict admin menu for vendors
    */
   public static function restrict_admin_menu_for_vendors() {
       if ( ! self::is_current_user_vendor() ) {
           return;
       }

       // Remove unnecessary menu items
       $restricted_menus = [
           'index.php',
           'edit.php',
           'upload.php',
           'edit-comments.php',
           'themes.php',
           'plugins.php',
           'users.php',
           'tools.php',
           'options-general.php',
           'woocommerce',
           'woocommerce-marketing',
       ];

       foreach ( $restricted_menus as $menu ) {
           remove_menu_page( $menu );
       }

       // Keep only necessary items
       add_menu_page(
           __( 'Vendor Dashboard', 'vss' ),
           __( 'Dashboard', 'vss' ),
           'vendor-mm',
           'vss-vendor-dashboard',
           [ self::class, 'render_admin_vendor_dashboard' ],
           'dashicons-dashboard',
           2
       );

       add_menu_page(
           __( 'Orders', 'vss' ),
           __( 'Orders', 'vss' ),
           'vendor-mm',
           'edit.php?post_type=shop_order',
           '',
           'dashicons-cart',
           3
       );
   }

   /**
    * Render admin vendor dashboard
    */
   public static function render_admin_vendor_dashboard() {
       ?>
       <div class="wrap">
<h1><?php esc_html_e( 'Vendor Dashboard', 'vss' ); ?></h1>
           <p><?php esc_html_e( 'Welcome to your vendor dashboard. Use the menu to manage your orders.', 'vss' ); ?></p>
           <p>
               <a href="<?php echo esc_url( home_url( '/vendor-portal/' ) ); ?>" class="button button-primary">
                   <?php esc_html_e( 'Go to Vendor Portal', 'vss' ); ?>
               </a>
           </p>
       </div>
       <?php
   }

   /**
    * Add vendor profile fields
    *
    * @param WP_User $user
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
    *
    * @param int $user_id
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
    *
    * @param string $message
    */
   private static function render_error_message( $message ) {
       echo '<div class="vss-error-notice"><p>' . esc_html( $message ) . '</p></div>';
   }

} // End class VSS_Vendor