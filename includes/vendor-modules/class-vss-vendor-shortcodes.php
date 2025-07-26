<?php
/**
 * VSS Vendor Shortcodes Module
 * 
 * Shortcode implementations
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Shortcodes functionality
 */
trait VSS_Vendor_Shortcodes {


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
                ?>

                <div class="vss-content-wrapper">
                    <?php
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
         * Render vendor navigation
         */
        private static function render_vendor_navigation( $current_action ) {
            ?>
            <nav class="vss-vendor-navigation">
                <div class="vss-vendor-navigation-inner">
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'dashboard', get_permalink() ) ); ?>"
                       class="<?php echo $current_action === 'dashboard' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-dashboard"></span>
                        <?php esc_html_e( 'Dashboard', 'vss' ); ?>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'orders', get_permalink() ) ); ?>"
                       class="<?php echo $current_action === 'orders' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-cart"></span>
                        <?php esc_html_e( 'My Orders', 'vss' ); ?>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'reports', get_permalink() ) ); ?>"
                       class="<?php echo $current_action === 'reports' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-chart-area"></span>
                        <?php esc_html_e( 'Reports', 'vss' ); ?>
                    </a>
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'settings', get_permalink() ) ); ?>"
                       class="<?php echo $current_action === 'settings' ? 'active' : ''; ?>">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php esc_html_e( 'Settings', 'vss' ); ?>
                    </a>
                    <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="logout">
                        <span class="dashicons dashicons-exit"></span>
                        <?php esc_html_e( 'Logout', 'vss' ); ?>
                    </a>
                </div>
            </nav>
            <?php
        }


}
