<?php
/**
 * VSS Vendor Dashboard Module
 * 
 * Dashboard and widget functionality
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Dashboard functionality
 */
trait VSS_Vendor_Dashboard {


        /**
         * Render vendor dashboard
         */
        private static function render_vendor_dashboard() {
            $vendor_id = get_current_user_id();
            $stats = self::get_vendor_statistics( $vendor_id );
            $user = wp_get_current_user();
            ?>

            
            <?php
            $logo_id = get_user_meta( $vendor_id, 'vss_vendor_logo_id', true );
            $logo_url = $logo_id ? wp_get_attachment_url( $logo_id ) : '';
            ?>

            <!-- Vendor Logo Display -->
            <?php if ( $logo_url ) : ?>
            <div class="vss-vendor-logo-header">
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $user->display_name ); ?>" class="vendor-logo">
            </div>
            <?php endif; ?>


            <!-- Page Header -->
            <div class="vss-page-header">
                <h1><?php printf( __( 'Welcome back, %s!', 'vss' ), esc_html( $user->display_name ) ); ?></h1>
                <p><?php esc_html_e( 'Here\'s what\'s happening with your orders today.', 'vss' ); ?></p>
            </div>

            <!-- Stats Dashboard -->
            <div class="vss-stat-boxes">
                <div class="vss-stat-box-fe">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-admin-generic"></span>
                    </div>
                    <span class="stat-number-fe"><?php echo esc_html( $stats['processing'] ); ?></span>
                    <span class="stat-label-fe"><?php esc_html_e( 'Orders in Processing', 'vss' ); ?></span>
                </div>

                <div class="vss-stat-box-fe <?php echo $stats['late'] > 0 ? 'is-critical' : ''; ?>">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <span class="stat-number-fe"><?php echo esc_html( $stats['late'] ); ?></span>
                    <span class="stat-label-fe"><?php esc_html_e( 'Orders Late', 'vss' ); ?></span>
                </div>

                <div class="vss-stat-box-fe">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-yes-alt"></span>
                    </div>
                    <span class="stat-number-fe"><?php echo esc_html( $stats['shipped_this_month'] ); ?></span>
                    <span class="stat-label-fe"><?php esc_html_e( 'Shipped This Month', 'vss' ); ?></span>
                </div>

                <div class="vss-stat-box-fe">
                    <div class="stat-icon">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
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
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e( 'View All Orders', 'vss' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'media-new.php' ) ); ?>" class="button" target="_blank">
                        <span class="dashicons dashicons-upload"></span>
                        <?php esc_html_e( 'Upload Files', 'vss' ); ?>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'reports', get_permalink() ) ); ?>" class="button">
                        <span class="dashicons dashicons-analytics"></span>
                        <?php esc_html_e( 'View Reports', 'vss' ); ?>
                    </a>
                    <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" class="button">
                        <span class="dashicons dashicons-email-alt"></span>
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
                <div class="vss-recent-orders-header">
                    <h3><?php esc_html_e( 'Recent Orders', 'vss' ); ?></h3>
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'orders', get_permalink() ) ); ?>" class="vss-view-all">
                        <?php esc_html_e( 'View all orders →', 'vss' ); ?>
                    </a>
                </div>

                <?php if ( ! empty( $orders ) ) : ?>
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
                            <?php foreach ( $orders as $order ) : ?>
                                <?php self::render_frontend_order_row( $order ); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="vss-empty-state">
                        <div class="vss-empty-state-icon">
                            <span class="dashicons dashicons-cart"></span>
                        </div>
                        <h3><?php esc_html_e( 'No orders yet', 'vss' ); ?></h3>
                        <p><?php esc_html_e( 'Your orders will appear here once you receive them.', 'vss' ); ?></p>
                    </div>
                <?php endif; ?>
            </div>
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


}
