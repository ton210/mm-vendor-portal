<?php
/**
 * VSS Vendor Orders Module
 * 
 * Order listing and management
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Orders functionality
 */
trait VSS_Vendor_Orders {


        /**
         * Render frontend orders list
         */
        private static function render_frontend_orders_list() {
            $vendor_id = get_current_user_id();

            // Get filter parameters
            $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
            $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
            $per_page = 100; // Show 100 orders per page
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

            <form method="get" class="vss-search-form">
                <input type="hidden" name="vss_action" value="orders">
                <?php if ( $status_filter !== 'all' ) : ?>
                    <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
                <?php endif; ?>
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search orders...', 'vss' ); ?>">
                <button type="submit"><?php esc_html_e( 'Search', 'vss' ); ?></button>
            </form>

            <?php if ( $total_orders > 0 ) : ?>
                <p class="vss-orders-info">
                    <?php
                    $start = ( ( $paged - 1 ) * $per_page ) + 1;
                    $end = min( $paged * $per_page, $total_orders );
                    printf(
                        esc_html__( 'Showing %1$d-%2$d of %3$d orders', 'vss' ),
                        $start,
                        $end,
                        $total_orders
                    );
                    ?>
                </p>
            <?php endif; ?>

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

            <?php if ( $total_pages > 1 ) : ?>
                <div class="vss-pagination">
                    <?php
                    // Build base URL preserving other query parameters
                    $base_url = add_query_arg( [
                        'vss_action' => 'orders',
                        'status' => $status_filter !== 'all' ? $status_filter : false,
                        's' => ! empty( $search ) ? $search : false,
                    ], get_permalink() );

                    echo paginate_links( [
                        'base' => $base_url . '%_%',
                        'format' => strpos( $base_url, '?' ) !== false ? '&paged=%#%' : '?paged=%#%',
                        'prev_text' => '&laquo; ' . __( 'Previous', 'vss' ),
                        'next_text' => __( 'Next', 'vss' ) . ' &raquo;',
                        'total' => $total_pages,
                        'current' => $paged,
                        'type' => 'list',
                        'end_size' => 2,
                        'mid_size' => 2,
                    ] );
                    ?>
                </div>
            <?php endif; ?>

            <style>
            /* Enhanced pagination styles */
            .vss-orders-info {
                margin: 15px 0;
                color: #666;
                font-style: italic;
            }

            .vss-pagination {
                margin-top: 30px;
                text-align: center;
            }

            .vss-pagination .page-numbers {
                list-style: none;
                margin: 0;
                padding: 0;
                display: inline-flex;
                gap: 5px;
                align-items: center;
            }

            .vss-pagination .page-numbers li {
                display: inline-block;
            }

            .vss-pagination .page-numbers a,
            .vss-pagination .page-numbers span.current,
            .vss-pagination .page-numbers span.dots {
                display: inline-block;
                padding: 8px 12px;
                text-decoration: none;
                border: 1px solid #ddd;
                border-radius: 4px;
                color: #333;
                background: #fff;
                transition: all 0.2s ease;
                min-width: 40px;
                text-align: center;
            }

            .vss-pagination .page-numbers a:hover {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }

            .vss-pagination .page-numbers span.current {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
                font-weight: bold;
            }

            .vss-pagination .page-numbers span.dots {
                border: none;
                background: none;
            }

            .vss-pagination .page-numbers .prev,
            .vss-pagination .page-numbers .next {
                min-width: auto;
                padding: 8px 16px;
            }

            @media (max-width: 768px) {
                .vss-pagination .page-numbers {
                    flex-wrap: wrap;
                    justify-content: center;
                }

                .vss-pagination .page-numbers a,
                .vss-pagination .page-numbers span {
                    padding: 6px 10px;
                    font-size: 14px;
                }
            }
            </style>
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
                    <span class="status-badge <?php echo esc_attr( $order->get_status() ); ?>">
                        <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
                    </span>
                    <?php if ( $is_late ) : ?>
                        <span class="vss-order-late-indicator"><?php esc_html_e( 'LATE', 'vss' ); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></td>
                <td><?php echo esc_html( $order->get_item_count() ); ?></td>
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
         * Render frontend order details - FIXED SINGLE PAGE VERSION
         * This replaces the tab-based layout with a single-page sectioned layout
         *
         * Add this to your class-vss-vendor.php file, replacing the existing
         * render_frontend_order_details method
         */
        private static function render_frontend_order_details( $order_id ) {
            $order = wc_get_order( $order_id );
            $current_user_id = get_current_user_id();

            if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != $current_user_id ) {
                self::render_error_message( __( 'Order not found or you do not have permission to view it.', 'vss' ) );
                return;
            }

            // Debug information (only show if VSS_DEBUG is true)
            if ( defined( 'VSS_DEBUG' ) && VSS_DEBUG ) {
                ?>
                <div class="vss-debug-info" style="background: #f0f0f0; padding: 20px; margin: 20px 0; border: 2px solid #999;">
                    <h3>Debug Information</h3>
                    <p><strong>Order ID:</strong> <?php echo esc_html( $order_id ); ?></p>
                    <p><strong>Order Status:</strong> <?php echo esc_html( $order->get_status() ); ?></p>
                    <p><strong>Has Processing Status:</strong> <?php echo $order->has_status( 'processing' ) ? 'YES' : 'NO'; ?></p>
                    <p><strong>Ship Date:</strong> <?php echo esc_html( get_post_meta( $order_id, '_vss_estimated_ship_date', true ) ?: 'Not set' ); ?></p>
                    <p><strong>Mockup Status:</strong> <?php echo esc_html( get_post_meta( $order_id, '_vss_mockup_status', true ) ?: 'Not set' ); ?></p>
                    <p><strong>Production File Status:</strong> <?php echo esc_html( get_post_meta( $order_id, '_vss_production_file_status', true ) ?: 'Not set' ); ?></p>
                    <p><strong>Tracking Number:</strong> <?php echo esc_html( get_post_meta( $order_id, '_vss_tracking_number', true ) ?: 'Not set' ); ?></p>
                </div>
                <?php
            }

            $portal_url = get_permalink();
            ?>
            <div class="vss-order-details-wrapper vss-single-page-layout">
                <div class="vss-order-header">
                    <h2><?php printf( __( 'Order #%s Details', 'vss' ), esc_html( $order->get_order_number() ) ); ?></h2>
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'dashboard', $portal_url ) ); ?>" class="button">
                        <span class="dashicons dashicons-arrow-left-alt"></span>
                        <?php esc_html_e( 'Back to Dashboard', 'vss' ); ?>
                    </a>
                </div>

                <?php
                // Order status bar
                self::render_order_status_bar( $order );

                // Production confirmation section
                self::render_vendor_production_confirmation_section( $order );
                ?>

                <div class="vss-order-sections">

                    <div class="vss-order-section" id="section-overview">
                        <div class="vss-section-header">
                            <h3><?php esc_html_e( 'Order Overview', 'vss' ); ?></h3>
                        </div>
                        <div class="vss-section-content">
                            <?php self::render_order_overview( $order ); ?>
                        </div>
                    </div>

                    <div class="vss-order-section" id="section-products">
                        <div class="vss-section-header">
                            <h3><?php esc_html_e( 'Products & Design Files', 'vss' ); ?></h3>
                        </div>
                        <div class="vss-section-content">
                            <?php self::render_order_products( $order ); ?>
                        </div>
                    </div>

                    <!-- Mockup Approval Section (Full Width) -->
                    <div class="vss-order-section" id="section-mockup">
                        <div class="vss-section-header">
                            <h3><?php esc_html_e( 'Mockup Approval', 'vss' ); ?></h3>
                        </div>
                        <div class="vss-section-content">
                            <?php
                            // Add debug output
                            if ( defined( 'VSS_DEBUG' ) && VSS_DEBUG ) {
                                echo '<div class="debug-info" style="background: #ffffcc; padding: 10px; margin-bottom: 20px;">';
                                echo '<strong>DEBUG:</strong> Rendering mockup section for order ' . $order->get_id();
                                echo ' | Status: ' . $order->get_status();
                                echo ' | Has processing: ' . ($order->has_status('processing') ? 'YES' : 'NO');
                                echo '</div>';
                            }

                            self::render_approval_section( $order, 'mockup' );
                            ?>
                        </div>
                    </div>

                    <!-- Production Files Section (Full Width) -->
                    <div class="vss-order-section" id="section-production">
                        <div class="vss-section-header">
                            <h3><?php esc_html_e( 'Production Files', 'vss' ); ?></h3>
                        </div>
                        <div class="vss-section-content">
                            <?php
                            // Add debug output
                            if ( defined( 'VSS_DEBUG' ) && VSS_DEBUG ) {
                                echo '<div class="debug-info" style="background: #ffffcc; padding: 10px; margin-bottom: 20px;">';
                                echo '<strong>DEBUG:</strong> Rendering production section for order ' . $order->get_id();
                                echo ' | Status: ' . $order->get_status();
                                echo ' | Has processing: ' . ($order->has_status('processing') ? 'YES' : 'NO');
                                echo '</div>';
                            }

                            self::render_approval_section( $order, 'production_file' );
                            ?>
                        </div>
                    </div>

                    <!-- Costs and Shipping Row -->
                    <div class="vss-order-row vss-two-column-row">
                        <!-- Costs Section -->
                        <div class="vss-order-section vss-half-width" id="section-costs">
                            <div class="vss-section-header">
                                <h3><?php esc_html_e( 'Order Costs', 'vss' ); ?></h3>
                            </div>
                            <div class="vss-section-content">
                                <?php self::render_costs_section( $order ); ?>
                            </div>
                        </div>

                        <!-- Shipping Section -->
                        <div class="vss-order-section vss-half-width" id="section-shipping">
                            <div class="vss-section-header">
                                <h3><?php esc_html_e( 'Shipping Information', 'vss' ); ?></h3>
                            </div>
                            <div class="vss-section-content">
                                <?php
                                // Add debug output
                                if ( defined( 'VSS_DEBUG' ) && VSS_DEBUG ) {
                                    echo '<div class="debug-info" style="background: #ffffcc; padding: 10px; margin-bottom: 20px;">';
                                    echo '<strong>DEBUG:</strong> Rendering shipping section for order ' . $order->get_id();
                                    echo ' | Status: ' . $order->get_status();
                                    echo ' | Has processing: ' . ($order->has_status('processing') ? 'YES' : 'NO');
                                    $tracking = get_post_meta( $order->get_id(), '_vss_tracking_number', true );
                                    echo ' | Tracking: ' . ($tracking ?: 'Not set');
                                    echo '</div>';
                                }

                                self::render_shipping_section( $order );
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="vss-order-section" id="section-notes">
                        <div class="vss-section-header">
                            <h3><?php esc_html_e( 'Order Notes & Communication', 'vss' ); ?></h3>
                        </div>
                        <div class="vss-section-content">
                            <?php self::render_notes_section( $order ); ?>
                        </div>
                    </div>

                    <div class="vss-order-section" id="section-files">
                        <div class="vss-section-header">
                            <h3><?php esc_html_e( 'All Order Files', 'vss' ); ?></h3>
                        </div>
                        <div class="vss-section-content">
                            <?php self::render_files_section( $order ); ?>
                        </div>
                    </div>

                    <div class="vss-order-section vss-quick-actions-section">
                        <div class="vss-section-header">
                            <h3><?php esc_html_e( 'Quick Actions', 'vss' ); ?></h3>
                        </div>
                        <div class="vss-section-content">
                            <div class="vss-action-buttons">
                                <?php if ( $order->has_status( 'processing' ) ) : ?>
                                    <a href="#section-shipping" class="button button-primary vss-smooth-scroll">
                                        <?php esc_html_e( 'Add Tracking Info', 'vss' ); ?>
                                    </a>
                                    <a href="#section-costs" class="button vss-smooth-scroll">
                                        <?php esc_html_e( 'Update Costs', 'vss' ); ?>
                                    </a>
                                    <a href="#section-mockup" class="button vss-smooth-scroll">
                                        <?php esc_html_e( 'Upload Mockup', 'vss' ); ?>
                                    </a>
                                <?php endif; ?>
                                <a href="#section-notes" class="button vss-smooth-scroll">
                                    <?php esc_html_e( 'Add Note', 'vss' ); ?>
                                </a>
                                <button type="button" class="button" onclick="window.print();">
                                    <?php esc_html_e( 'Print Order', 'vss' ); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                // Smooth scrolling for anchor links
                $('.vss-smooth-scroll').on('click', function(e) {
                    if (this.hash !== '') {
                        e.preventDefault();
                        var hash = this.hash;
                        var target = $(hash);

                        if (target.length) {
                            $('html, body').animate({
                                scrollTop: target.offset().top - 100
                            }, 800);
                        }
                    }
                });

                // Highlight active section while scrolling
                $(window).on('scroll', function() {
                    var scrollPos = $(document).scrollTop();

                    $('.vss-order-section').each(function() {
                        var currSection = $(this);
                        var currSectionTop = currSection.offset().top - 150;
                        var currSectionBottom = currSectionTop + currSection.outerHeight();

                        if (scrollPos >= currSectionTop && scrollPos < currSectionBottom) {
                            currSection.addClass('vss-active-section');
                        } else {
                            currSection.removeClass('vss-active-section');
                        }
                    });
                });

                // Handle Zakeke file fetching (keeping existing functionality)
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

                // Auto-expand sections with forms when there are errors
                <?php if ( isset( $_GET['vss_error'] ) ) : ?>
                var errorType = '<?php echo esc_js( $_GET['vss_error'] ); ?>';
                var targetSection = '';

                switch(errorType) {
                    case 'date_required':
                    case 'date_format':
                        targetSection = '.vss-production-confirmation-fe';
                        break;
                    case 'no_files_uploaded':
                        targetSection = '#section-mockup';
                        break;
                }

                if (targetSection && $(targetSection).length) {
                    $('html, body').animate({
                        scrollTop: $(targetSection).offset().top - 100
                    }, 500);
                }
                <?php endif; ?>
            });
            </script>

            <style>
            /* Single Page Layout Styles */
            .vss-single-page-layout {
                max-width: 1200px;
                margin: 0 auto;
            }

            .vss-order-sections {
                margin-top: 30px;
            }

            .vss-order-section {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                margin-bottom: 30px;
                overflow: hidden;
                transition: all 0.3s ease;
            }

            .vss-order-section:hover {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            }

            .vss-order-section.vss-active-section {
                border-color: #2271b1;
                box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.1);
            }

            .vss-section-header {
                background: #f9fafb;
                padding: 20px 30px;
                border-bottom: 1px solid #e5e7eb;
            }

            .vss-section-header h3 {
                margin: 0;
                font-size: 1.25em;
                color: #1f2937;
            }

            .vss-section-content {
                padding: 30px;
            }

            /* Two Column Layout */
            .vss-two-column {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0;
                padding: 0;
                background: transparent;
                border: none;
            }

            .vss-two-column .vss-column {
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                overflow: hidden;
            }

            .vss-two-column .vss-column:first-child {
                margin-right: 15px;
            }

            .vss-two-column .vss-column:last-child {
                margin-left: 15px;
            }

            /* Overview Grid */
            .overview-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 30px;
            }

            .overview-section {
                background: #f9fafb;
                padding: 20px;
                border-radius: 8px;
            }

            .overview-section h4 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #374151;
                font-size: 1.1em;
            }

            .overview-section p {
                margin: 8px 0;
                color: #4b5563;
            }

            /* Quick Actions Section */
            .vss-quick-actions-section {
                background: #f0f7ff;
                border-color: #2271b1;
            }

            .vss-quick-actions-section .vss-section-header {
                background: #e0efff;
            }

            .vss-quick-actions-section .vss-action-buttons {
                display: flex;
                gap: 15px;
                flex-wrap: wrap;
            }

            /* Table Styles */
            .vss-items-table,
            .vss-costs-form table {
                width: 100%;
                border-collapse: collapse;
            }

            .vss-items-table th,
            .vss-items-table td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #e5e7eb;
            }

            .vss-items-table th {
                background: #f9fafb;
                font-weight: 600;
                color: #374151;
            }

            /* Form Styles */
            .vss-costs-form .costs-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }

            .cost-item label {
                display: block;
                margin-bottom: 5px;
                font-weight: 500;
                color: #374151;
            }

            .cost-item input {
                width: 100%;
                padding: 8px 12px;
                border: 2px solid #e5e7eb;
                border-radius: 6px;
                font-size: 16px;
            }

            .cost-item input:focus {
                outline: none;
                border-color: #2271b1;
                box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.1);
            }

            /* Status Indicators */
            .status-pending {
                color: #f59e0b;
                background: #fef3c7;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 0.875em;
                font-weight: 500;
            }

            .status-approved {
                color: #10b981;
                background: #d1fae5;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 0.875em;
                font-weight: 500;
            }

            .status-disapproved {
                color: #ef4444;
                background: #fee2e2;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 0.875em;
                font-weight: 500;
            }

            /* Notes Section */
            .order-notes {
                max-height: 400px;
                overflow-y: auto;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                padding: 15px;
                background: #f9fafb;
                margin-bottom: 20px;
            }

            .note-item {
                padding: 15px 0;
                border-bottom: 1px solid #e5e7eb;
            }

            .note-item:last-child {
                border-bottom: none;
            }

            .note-meta {
                display: flex;
                justify-content: space-between;
                margin-bottom: 8px;
                color: #6b7280;
                font-size: 0.875em;
            }

            .note-content {
                color: #374151;
            }

            /* Files Grid */
            .files-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
            }

            .file-category {
                background: #f9fafb;
                padding: 20px;
                border-radius: 8px;
                border: 1px solid #e5e7eb;
            }

            .file-category h5 {
                margin-top: 0;
                margin-bottom: 15px;
                color: #374151;
            }

            .no-files {
                color: #9ca3af;
                font-style: italic;
            }

            /* Responsive Design */
            @media (max-width: 768px) {
                .vss-two-column {
                    grid-template-columns: 1fr;
                    gap: 30px;
                }

                .vss-two-column .vss-column {
                    margin: 0 !important;
                }

                .vss-two-column .vss-column:first-child {
                    margin-bottom: 0;
                }

                .overview-grid {
                    grid-template-columns: 1fr;
                }

                .vss-section-content {
                    padding: 20px;
                }

                .vss-action-buttons {
                    flex-direction: column;
                }

                .vss-action-buttons .button {
                    width: 100%;
                }
            }

            /* Print Styles */
            @media print {
                .vss-order-header p,
                .vss-quick-actions-section,
                .vss-production-confirmation-fe form,
                .vss-approval-form,
                .vss-costs-form,
                .vss-shipping-form,
                .vss-add-note-form,
                .button {
                    display: none !important;
                }

                .vss-order-section {
                    break-inside: avoid;
                    margin-bottom: 20px;
                }

                .vss-two-column {
                    display: block;
                }

                .vss-two-column .vss-column {
                    margin: 0 0 20px 0 !important;
                }
            }

            /* Two column row container */
            .vss-two-column-row {
                display: flex;
                gap: 30px;
                margin-bottom: 30px;
            }

            .vss-two-column-row .vss-half-width {
                flex: 1;
                min-width: 0; /* Prevent flex items from overflowing */
            }

            /* Ensure all sections are visible */
            .vss-order-section {
                display: block !important;
                visibility: visible !important;
            }

            .vss-section-content {
                display: block !important;
                visibility: visible !important;
                min-height: 50px;
            }

            /* Responsive */
            @media (max-width: 768px) {
                .vss-two-column-row {
                    flex-direction: column;
                }

                .vss-two-column-row .vss-half-width {
                    width: 100%;
                }
            }

            /* Debug styles */
            .debug-info {
                font-family: monospace;
                font-size: 12px;
                border: 1px dashed #666;
                border-radius: 4px;
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


}
