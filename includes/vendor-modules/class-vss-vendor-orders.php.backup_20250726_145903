<?php
/**
 * VSS Vendor Orders Module
 * * Order listing and management
 * * @package VendorOrderManager
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
        $search_type = isset( $_GET['search_type'] ) ? sanitize_key( $_GET['search_type'] ) : 'all';
        $date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
        $date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';
        $customer_country = isset( $_GET['customer_country'] ) ? sanitize_text_field( $_GET['customer_country'] ) : '';
        $customer_state = isset( $_GET['customer_state'] ) ? sanitize_text_field( $_GET['customer_state'] ) : '';

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

        // Enhanced search with type
        if ( ! empty( $search ) ) {
            if ( $search_type === 'customer_name' ) {
                $args['meta_query'][] = [
                    'relation' => 'OR',
                    [
                        'key' => '_billing_first_name',
                        'value' => $search,
                        'compare' => 'LIKE'
                    ],
                    [
                        'key' => '_billing_last_name',
                        'value' => $search,
                        'compare' => 'LIKE'
                    ]
                ];
                unset( $args['s'] );
            } elseif ( $search_type === 'customer_email' ) {
                $args['meta_key'] = '_billing_email';
                $args['meta_value'] = $search;
                $args['meta_compare'] = 'LIKE';
                unset( $args['s'] );
            } elseif ( $search_type === 'product_sku' ) {
                // Search by SKU requires custom query
                $args['post__in'] = self::get_orders_by_product_sku( $search, $vendor_id );
                if ( empty( $args['post__in'] ) ) {
                    $args['post__in'] = [ 0 ]; // Force no results
                }
                unset( $args['s'] );
            } else {
                $args['s'] = $search;
            }
        }

        // Date range filter
        if ( ! empty( $date_from ) ) {
            $args['date_created'] = '>=' . $date_from;
        }
        if ( ! empty( $date_to ) ) {
            if ( isset( $args['date_created'] ) ) {
                $args['date_created'] = [ $args['date_created'], '<=' . $date_to ];
            } else {
                $args['date_created'] = '<=' . $date_to;
            }
        }

        // Location filter
        if ( ! empty( $customer_country ) ) {
            if ( ! isset( $args['meta_query'] ) ) {
                $args['meta_query'] = [];
            }
            $args['meta_query'][] = [
                'key' => '_billing_country',
                'value' => $customer_country,
            ];

            if ( ! empty( $customer_state ) ) {
                $args['meta_query'][] = [
                    'key' => '_billing_state',
                    'value' => $customer_state,
                ];
            }
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

        <form method="get" class="vss-search-form vss-advanced-search">
            <input type="hidden" name="vss_action" value="orders">
            <?php if ( $status_filter !== 'all' ) : ?>
                <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
            <?php endif; ?>
            <div class="search-fields">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search orders, customers, emails, SKUs...', 'vss' ); ?>"
                       class="vss-search-input">
                <select name="search_type" class="vss-search-type">
                    <option value="all" <?php selected( $search_type, 'all' ); ?>><?php esc_html_e( 'All Fields', 'vss' ); ?></option>
                    <option value="order_number" <?php selected( $search_type, 'order_number' ); ?>><?php esc_html_e( 'Order Number', 'vss' ); ?></option>
                    <option value="customer_name" <?php selected( $search_type, 'customer_name' ); ?>><?php esc_html_e( 'Customer Name', 'vss' ); ?></option>
                    <option value="customer_email" <?php selected( $search_type, 'customer_email' ); ?>><?php esc_html_e( 'Customer Email', 'vss' ); ?></option>
                    <option value="product_sku" <?php selected( $search_type, 'product_sku' ); ?>><?php esc_html_e( 'Product SKU', 'vss' ); ?></option>
                </select>
                <button type="submit" class="button"><?php esc_html_e( 'Search', 'vss' ); ?></button>
            </div>
        </form>

        <div class="vss-filters-section">
            <button type="button" class="button vss-toggle-filters">
                <span class="dashicons dashicons-filter"></span>
                <?php esc_html_e( 'Advanced Filters', 'vss' ); ?>
            </button>

            <div class="vss-filter-panel" style="display: none;">
                <form method="get" class="vss-filter-form">
                    <input type="hidden" name="vss_action" value="orders">
                    <?php if ( $status_filter !== 'all' ) : ?>
                        <input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
                    <?php endif; ?>

                    <div class="filter-group">
                        <h4><?php esc_html_e( 'Date Range', 'vss' ); ?></h4>
                        <div class="date-range-inputs">
                            <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"
                                   placeholder="<?php esc_attr_e( 'From', 'vss' ); ?>">
                            <span class="date-separator">—</span>
                            <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"
                                   placeholder="<?php esc_attr_e( 'To', 'vss' ); ?>">
                        </div>
                    </div>

                    <div class="filter-group">
                        <h4><?php esc_html_e( 'Customer Location', 'vss' ); ?></h4>
                        <div class="location-inputs">
                            <select name="customer_country" class="vss-country-select">
                                <option value=""><?php esc_html_e( 'All Countries', 'vss' ); ?></option>
                                <?php
                                $countries = WC()->countries->get_countries();
                                foreach ( $countries as $code => $name ) :
                                ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $customer_country, $code ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if ( $customer_country ) : ?>
                                <select name="customer_state" class="vss-state-select">
                                    <option value=""><?php esc_html_e( 'All States', 'vss' ); ?></option>
                                    <?php
                                    $states = WC()->countries->get_states( $customer_country );
                                    if ( $states ) :
                                        foreach ( $states as $code => $name ) :
                                    ?>
                                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $customer_state, $code ); ?>>
                                            <?php echo esc_html( $name ); ?>
                                        </option>
                                    <?php
                                        endforeach;
                                    endif;
                                    ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply Filters', 'vss' ); ?></button>
                        <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'orders', get_permalink() ) ); ?>"
                           class="button"><?php esc_html_e( 'Clear Filters', 'vss' ); ?></a>
                    </div>
                </form>
            </div>
        </div>

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

        <div id="vss-quick-tracking-modal" class="vss-modal" style="display: none;">
            <div class="vss-modal-content">
                <div class="vss-modal-header">
                    <h3><?php esc_html_e( 'Add Tracking Information', 'vss' ); ?></h3>
                    <button type="button" class="vss-modal-close">&times;</button>
                </div>
                <div class="vss-modal-body">
                    <form id="vss-quick-tracking-form">
                        <input type="hidden" id="vss-quick-tracking-order-id" name="order_id" value="">

                        <div class="vss-form-group">
                            <label for="vss-quick-tracking-carrier"><?php esc_html_e( 'Shipping Carrier:', 'vss' ); ?> <span class="required">*</span></label>
                            <select id="vss-quick-tracking-carrier" name="tracking_carrier" required>
                                <option value=""><?php esc_html_e( '— Select Carrier —', 'vss' ); ?></option>
                                <optgroup label="<?php esc_attr_e( 'United States', 'vss' ); ?>">
                                    <option value="usps">USPS</option>
                                    <option value="ups">UPS</option>
                                    <option value="fedex">FedEx</option>
                                    <option value="dhl_us">DHL Express</option>
                                </optgroup>
                                <optgroup label="<?php esc_attr_e( 'International', 'vss' ); ?>">
                                    <option value="dhl">DHL Global</option>
                                    <option value="australia_post"><?php esc_html_e( 'Australia Post', 'vss' ); ?></option>
                                    <option value="royal_mail"><?php esc_html_e( 'Royal Mail (UK)', 'vss' ); ?></option>
                                    <option value="canada_post"><?php esc_html_e( 'Canada Post', 'vss' ); ?></option>
                                </optgroup>
                                <option value="other"><?php esc_html_e( 'Other', 'vss' ); ?></option>
                            </select>
                        </div>

                        <div class="vss-form-group">
                            <label for="vss-quick-tracking-number"><?php esc_html_e( 'Tracking Number:', 'vss' ); ?> <span class="required">*</span></label>
                            <input type="text" id="vss-quick-tracking-number" name="tracking_number" required
                                   placeholder="<?php esc_attr_e( 'Enter tracking number', 'vss' ); ?>">
                        </div>

                        <div class="vss-form-group">
                            <p class="vss-form-notice">
                                <strong><?php esc_html_e( 'Note:', 'vss' ); ?></strong>
                                <?php esc_html_e( 'This will mark the order as "Shipped" and notify the customer.', 'vss' ); ?>
                            </p>
                        </div>
                    </form>
                </div>
                <div class="vss-modal-footer">
                    <button type="button" class="button vss-modal-cancel"><?php esc_html_e( 'Cancel', 'vss' ); ?></button>
                    <button type="button" class="button button-primary vss-modal-submit"><?php esc_html_e( 'Save & Mark as Shipped', 'vss' ); ?></button>
                </div>
            </div>
        </div>

        <style>
        /* Quick Tracking Modal Styles */
        .vss-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .vss-modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .vss-modal-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .vss-modal-header h3 {
            margin: 0;
            font-size: 1.25em;
        }

        .vss-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .vss-modal-close:hover {
            color: #000;
        }

        .vss-modal-body {
            padding: 20px;
        }

        .vss-form-group {
            margin-bottom: 20px;
        }

        .vss-form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .vss-form-group input,
        .vss-form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
        }

        .vss-form-group input:focus,
        .vss-form-group select:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.1);
        }

        .vss-modal-footer {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .vss-form-notice {
            background: #fff3cd;
            padding: 12px;
            border-radius: 6px;
            border: 1px solid #ffc107;
            margin: 0;
        }

        .required {
            color: #d32f2f;
        }

        /* Add tracking button in order rows */
        .vss-quick-tracking-btn {
            margin-left: 5px;
        }

        @media (max-width: 768px) {
            .vss-modal-content {
                width: 95%;
                margin: 20px;
            }
        }
        </style>

        <script>
        // Quick Tracking Functionality
        jQuery(document).ready(function($) {
            'use strict';

            // Quick tracking button click handler
            $(document).on('click', '.vss-quick-tracking-btn', function(e) {
                e.preventDefault();

                var orderId = $(this).data('order-id');
                $('#vss-quick-tracking-order-id').val(orderId);
                $('#vss-quick-tracking-modal').fadeIn(200);

                // Reset form
                $('#vss-quick-tracking-form')[0].reset();
            });

            // Modal close handlers
            $('.vss-modal-close, .vss-modal-cancel').on('click', function() {
                $('#vss-quick-tracking-modal').fadeOut(200);
            });

            // Click outside modal to close
            $('#vss-quick-tracking-modal').on('click', function(e) {
                if ($(e.target).hasClass('vss-modal')) {
                    $(this).fadeOut(200);
                }
            });

            // Submit handler
            $('.vss-modal-submit').on('click', function() {
                var $form = $('#vss-quick-tracking-form');
                var $button = $(this);

                // Validate form
                if (!$form[0].checkValidity()) {
                    $form[0].reportValidity();
                    return;
                }

                // Disable button and show loading
                $button.prop('disabled', true).text('<?php esc_js_e( 'Saving...', 'vss' ); ?>');

                // Prepare data
                var formData = {
                    action: 'vss_quick_save_tracking',
                    order_id: $('#vss-quick-tracking-order-id').val(),
                    tracking_carrier: $('#vss-quick-tracking-carrier').val(),
                    tracking_number: $('#vss-quick-tracking-number').val(),
                    nonce: vss_frontend_ajax.nonce
                };

                // Submit via AJAX
                $.ajax({
                    url: vss_frontend_ajax.ajax_url,
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        if (response.success) {
                            // Close modal
                            $('#vss-quick-tracking-modal').fadeOut(200);

                            // Show success message
                            var successHtml = '<div class="vss-success-notice"><p>' + response.data.message + '</p></div>';
                            $('.vss-orders-table').before(successHtml);

                            // Update order row if needed
                            var $row = $('tr').find('[data-order-id="' + formData.order_id + '"]').closest('tr');
                            if ($row.length) {
                                // Update status badge
                                $row.find('.status-badge').removeClass('processing').addClass('shipped').text('<?php esc_js_e( 'Shipped', 'vss' ); ?>');

                                // Replace quick tracking button with tracking info
                                var trackingHtml = '<small><?php esc_js_e( 'Tracking:', 'vss' ); ?> ' + formData.tracking_number + '</small>';
                                $row.find('.vss-quick-tracking-btn').replaceWith(trackingHtml);
                            }

                            // Remove success message after 5 seconds
                            setTimeout(function() {
                                $('.vss-success-notice').fadeOut(function() {
                                    $(this).remove();
                                });
                            }, 5000);

                        } else {
                            alert(response.data.message || '<?php esc_js_e( 'An error occurred. Please try again.', 'vss' ); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_js_e( 'Connection error. Please try again.', 'vss' ); ?>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php esc_js_e( 'Save & Mark as Shipped', 'vss' ); ?>');
                    }
                });
            });

            // Batch Zakeke downloads
            $('#vss-batch-download-zakeke').on('click', function() {
                var selectedOrders = [];
                $('input[name="order_ids[]"]:checked').each(function() {
                    selectedOrders.push($(this).val());
                });

                if (selectedOrders.length === 0) {
                    alert('<?php esc_js_e( 'Please select at least one order.', 'vss' ); ?>');
                    return;
                }

                var $button = $(this);
                $button.prop('disabled', true).text('<?php esc_js_e( 'Preparing downloads...', 'vss' ); ?>');

                $.ajax({
                    url: vss_frontend_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'vss_batch_download_zakeke',
                        order_ids: selectedOrders,
                        nonce: vss_frontend_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Download each file
                            response.data.files.forEach(function(file) {
                                var link = document.createElement('a');
                                link.href = file.url;
                                link.download = file.name;
                                link.click();
                            });

                            $button.prop('disabled', false).text('<?php esc_js_e( 'Download Selected Zakeke Files', 'vss' ); ?>');
                        } else {
                            alert(response.data.message || '<?php esc_js_e( 'Error preparing downloads.', 'vss' ); ?>');
                            $button.prop('disabled', false).text('<?php esc_js_e( 'Download Selected Zakeke Files', 'vss' ); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_js_e( 'Connection error. Please try again.', 'vss' ); ?>');
                        $button.prop('disabled', false).text('<?php esc_js_e( 'Download Selected Zakeke Files', 'vss' ); ?>');
                    }
                });
            });

            // Advanced filters toggle
            $('.vss-toggle-filters').on('click', function() {
                $('.vss-filter-panel').slideToggle();
            });
        });
        </script>
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
        $priority = get_post_meta( $order_id, '_vss_order_priority', true );

        if ( $priority === 'high' ) {
            $row_class .= ' vss-high-priority';
        } elseif ( $priority === 'urgent' ) {
            $row_class .= ' vss-urgent-priority';
        }

        if ( $is_late ) {
            $row_class .= ' vss-status-late';
        } elseif ( $order->has_status( 'processing' ) ) {
            $row_class .= ' vss-status-processing';
        } elseif ( $order->has_status( 'shipped' ) ) {
            $row_class .= ' vss-status-shipped';
        }
        ?>
        <tr class="<?php echo esc_attr( $row_class ); ?>">
            <td>
                <a href="<?php echo esc_url( add_query_arg( [ 'vss_action' => 'view_order', 'order_id' => $order_id ], get_permalink() ) ); ?>">
                    #<?php echo esc_html( $order->get_order_number() ); ?>
                </a>
                <?php if ( $priority === 'high' || $priority === 'urgent' ) : ?>
                    <span class="vss-priority-indicator vss-priority-<?php echo esc_attr( $priority ); ?>"
                          title="<?php echo esc_attr( ucfirst( $priority ) . ' Priority' ); ?>">
                        <span class="dashicons dashicons-warning"></span>
                    </span>
                <?php endif; ?>
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
                <?php if ( $order->has_status( 'processing' ) ) : ?>
                    <button type="button" class="button button-small vss-quick-tracking-btn"
                            data-order-id="<?php echo esc_attr( $order_id ); ?>">
                        <?php esc_html_e( 'Add Tracking', 'vss' ); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
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

        ?>
        <div class="vss-single-page-layout">
            <div class="vss-order-header">
                <h2><?php printf( __( 'Order #%s', 'vss' ), $order->get_order_number() ); ?></h2>
                <p>
                    <a href="<?php echo esc_url( add_query_arg( 'vss_action', 'orders', get_permalink() ) ); ?>">
                        &larr; <?php esc_html_e( 'Back to Orders', 'vss' ); ?>
                    </a>
                </p>
            </div>

            <?php self::render_order_status_bar( $order ); ?>

            <div class="vss-order-sections">
                <div class="vss-order-section">
                    <div class="vss-section-header">
                        <h3><?php esc_html_e( 'Order Overview', 'vss' ); ?></h3>
                    </div>
                    <div class="vss-section-content">
                        <?php self::render_order_overview( $order ); ?>
                    </div>
                </div>

                <?php if ( $order->has_status( 'processing' ) ) : ?>
                <div class="vss-order-section vss-quick-actions-section">
                    <div class="vss-section-header">
                        <h3><?php esc_html_e( 'Quick Actions', 'vss' ); ?></h3>
                    </div>
                    <div class="vss-section-content">
                        <div class="vss-action-buttons">
                            <?php self::render_vendor_production_confirmation_section( $order ); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="vss-order-section">
                    <div class="vss-section-header">
                        <h3><?php esc_html_e( 'Order Items', 'vss' ); ?></h3>
                    </div>
                    <div class="vss-section-content">
                        <?php self::render_order_products( $order ); ?>
                    </div>
                </div>

                <div class="vss-order-section">
                    <div class="vss-section-header">
                        <h3><?php esc_html_e( 'Costs & Earnings', 'vss' ); ?></h3>
                    </div>
                    <div class="vss-section-content">
                        <?php self::render_costs_section( $order ); ?>
                    </div>
                </div>

                <div class="vss-order-section">
                    <div class="vss-section-header">
                        <h3><?php esc_html_e( 'Files & Mockups', 'vss' ); ?></h3>
                    </div>
                    <div class="vss-section-content">
                        <?php self::render_files_section( $order ); ?>
                    </div>
                </div>

                <div class="vss-order-section" id="section-notes">
                    <div class="vss-section-header">
                        <h3><?php esc_html_e( 'Order Notes', 'vss' ); ?></h3>
                    </div>
                    <div class="vss-section-content">
                        <?php self::render_notes_section( $order ); ?>
                    </div>
                </div>
            </div>
        </div>

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

        /* Priority Indicators */
        .vss-priority-indicator {
            display: inline-block;
            margin-left: 8px;
            vertical-align: middle;
        }

        .vss-priority-indicator .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }

        .vss-priority-high .dashicons {
            color: #9333ea;
        }

        .vss-priority-urgent .dashicons {
            color: #dc2626;
        }

        .vss-high-priority {
            background-color: #faf5ff !important;
        }

        .vss-urgent-priority {
            background-color: #fef2f2 !important;
        }

        /* Status Bar */
        .vss-order-status-bar {
            display: flex;
            justify-content: space-between;
            margin: 30px 0;
            padding: 0 20px;
        }

        .status-item {
            flex: 1;
            text-align: center;
            position: relative;
        }

        .status-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 20px;
            left: 50%;
            width: 100%;
            height: 2px;
            background: #e5e7eb;
            z-index: -1;
        }

        .status-item.completed::after {
            background: #10b981;
        }

        .status-dot {
            display: inline-block;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e5e7eb;
            margin-bottom: 8px;
        }

        .status-item.active .status-dot,
        .status-item.completed .status-dot {
            background: #10b981;
        }

        .status-label {
            display: block;
            font-size: 0.875em;
            color: #6b7280;
        }

        .status-item.active .status-label,
        .status-item.completed .status-label {
            color: #1f2937;
            font-weight: 600;
        }

        .status-late {
            display: block;
            color: #dc2626;
            font-weight: bold;
            font-size: 0.75em;
            margin-top: 4px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .overview-grid {
                grid-template-columns: 1fr;
            }

            .vss-section-content {
                padding: 20px;
            }

            .vss-order-status-bar {
                flex-wrap: wrap;
                padding: 0;
            }

            .status-item {
                flex: 1 1 50%;
                margin-bottom: 20px;
            }
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