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
            // Assuming VSS_Emails class exists and has this method
            // VSS_Emails::send_customer_production_confirmation_email( 
            //     $order_id, 
            //     $order->get_order_number(), 
            //     $estimated_ship_date 
            // );
        }

        // Log activity
        // Assuming Vendor_Order_Manager class exists and has this method
        // Vendor_Order_Manager::log_activity( 'vendor_production_confirmed', [
        //     'order_id' => $order_id,
        //     'ship_date' => $estimated_ship_date,
        // ] );

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
        // Assuming VSS_Emails class exists and has this method
        // VSS_Emails::send_customer_approval_request_email( $order_id, $type );

        // Log activity
        // Assuming Vendor_Order_Manager class exists and has this method
        // Vendor_Order_Manager::log_activity( "vendor_{$type}_submitted", [
        //     'order_id' => $order_id,
        //     'files_count' => count( $uploaded_file_ids ),
        // ] );

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
        // Assuming VSS_Zakeke_API class exists and has this method
        // $zakeke_response = VSS_Zakeke_API::get_zakeke_order_details_by_wc_order_id( $order_id );
        $zakeke_response = null; // Placeholder
        
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
    public static function filter_orders_for_vendor_in_admin( $query ) {
        if ( ! is_admin() || ! self::is_current_user_vendor() || ! $query->is_main_query() ) {
            return;
        }

        global $pagenow;
        if ( $pagenow === 'edit.php' && isset( $query->query_vars['post_type'] ) && $query->query_vars['post_type'] === 'shop_order' ) {
            $query->set( 'meta_key', '_vss_vendor_user_id' );
            $query->set( 'meta_value', get_current_user_id() );
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


    // --- MISSING FUNCTION STUBS ---

    public static function render_vendor_stats_shortcode($atts) {
        // TODO: Implement this function to render vendor statistics.
        return '';
    }

    public static function render_vendor_earnings_shortcode($atts) {
        // TODO: Implement this function to render vendor earnings.
        return '';
    }
    
    public static function restrict_admin_access() {
        // TODO: Implement logic to restrict access to specific admin pages for vendors.
    }

    public static function ajax_save_draft() {
        // TODO: Implement AJAX handler for saving drafts.
        wp_die();
    }

    public static function ajax_get_order_details() {
        // TODO: Implement AJAX handler for getting order details.
        wp_die();
    }

    public static function ajax_track_order() {
        // TODO: Implement AJAX handler for public order tracking.
        wp_die();
    }
    
    public static function add_vendor_dashboard_widgets() {
        // TODO: Implement function to add custom widgets to the WordPress dashboard for vendors.
    }

    private static function render_vendor_reports() {
        // TODO: Implement the vendor reports view.
        echo '<h3>' . esc_html__( 'Reports', 'vss' ) . '</h3><p>Reports functionality coming soon.</p>';
    }
    
    private static function render_vendor_settings() {
        // TODO: Implement the vendor settings view.
        echo '<h3>' . esc_html__( 'Settings', 'vss' ) . '</h3><p>Settings functionality coming soon.</p>';
    }

    private static function render_order_overview($order) {
        // TODO: Implement the order overview tab content.
        echo '<h4>' . esc_html__( 'Order Overview', 'vss' ) . '</h4><p>Order overview details will be shown here.</p>';
    }

    private static function render_order_products($order) {
        // TODO: Implement the order products tab content.
        echo '<h4>' . esc_html__( 'Products', 'vss' ) . '</h4><p>Order product details will be shown here.</p>';
    }

    private static function render_costs_section($order) {
        // TODO: Implement the costs tab content and form.
        echo '<h4>' . esc_html__( 'Costs', 'vss' ) . '</h4><p>Order costs will be managed here.</p>';
    }

    private static function render_shipping_section($order) {
        // TODO: Implement the shipping tab content and form.
        echo '<h4>' . esc_html__( 'Shipping', 'vss' ) . '</h4><p>Shipping details will be managed here.</p>';
    }

    private static function render_notes_section($order) {
        // TODO: Implement the notes tab content and form.
        echo '<h4>' . esc_html__( 'Notes', 'vss' ) . '</h4><p>Order notes will be managed here.</p>';
    }

    private static function render_files_section($order) {
        // TODO: Implement the files tab content.
        echo '<h4>' . esc_html__( 'Files', 'vss' ) . '</h4><p>Order files will be listed here.</p>';
    }

    private static function handle_save_costs($order, &$redirect_args) {
        // TODO: Implement logic to handle saving cost data.
    }

    private static function handle_save_tracking($order, &$redirect_args) {
        // TODO: Implement logic to handle saving tracking information.
    }

    private static function handle_add_note($order, &$redirect_args) {
        // TODO: Implement logic to handle adding an order note.
    }

} // <- This closing brace was missing