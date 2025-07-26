<?php
/**
 * VSS Vendor Admin Menu Module
 * 
 * Admin menu and backend interface
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Admin Menu functionality
 */
trait VSS_Vendor_Admin_Menu {


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
         * Render vendor orders page with pagination and filters (Admin version).
         */
        public static function render_vendor_orders_page() {
            $vendor_id = get_current_user_id();

            // Get filter parameters
            $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
            $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
            $per_page = 100; // Show 100 orders per page
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
                            </span>
                            <?php
                            // Build base URL preserving other parameters
                            $base_url = add_query_arg( [
                                'page' => 'vss-vendor-orders',
                                'status' => $status_filter !== 'all' ? $status_filter : false,
                                's' => ! empty( $search ) ? $search : false,
                            ], admin_url( 'admin.php' ) );

                            echo paginate_links( [
                                'base' => $base_url . '%_%',
                                'format' => '&paged=%#%',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $paged,
                                'end_size' => 2,
                                'mid_size' => 2,
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
                                    <td colspan="9">
                                        <p><?php esc_html_e( 'No orders found.', 'vss' ); ?></p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php
                                printf(
                                    esc_html__( 'Showing %1$d-%2$d of %3$d orders', 'vss' ),
                                    $start,
                                    $end,
                                    $total_orders
                                );
                                ?>
                            </span>
                            <?php
                            echo paginate_links( [
                                'base' => $base_url . '%_%',
                                'format' => '&paged=%#%',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $paged,
                                'end_size' => 2,
                                'mid_size' => 2,
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

                /* Pagination info styling */
                .tablenav .displaying-num {
                    font-style: italic;
                    color: #666;
                    margin-right: 15px;
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


}
