<?php
/**
 * VSS Vendor Ajax Module
 * 
 * AJAX handlers
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Ajax functionality
 */
trait VSS_Vendor_Ajax {


        
        /**
         * AJAX handler for batch Zakeke downloads
         */
        public static function ajax_batch_download_zakeke() {
            check_ajax_referer( 'vss_frontend_nonce', 'nonce' );

            if ( ! self::is_current_user_vendor() ) {
                wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vss' ) ] );
            }

            $order_ids = isset( $_POST['order_ids'] ) ? array_map( 'intval', $_POST['order_ids'] ) : [];

            if ( empty( $order_ids ) ) {
                wp_send_json_error( [ 'message' => __( 'No orders selected.', 'vss' ) ] );
            }

            $files = [];
            $vendor_id = get_current_user_id();

            foreach ( $order_ids as $order_id ) {
                $order = wc_get_order( $order_id );

                // Verify vendor has access
                if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != $vendor_id ) {
                    continue;
                }

                foreach ( $order->get_items() as $item_id => $item ) {
                    $zip_url = $item->get_meta( '_vss_zakeke_printing_files_zip_url', true );

                    if ( $zip_url ) {
                        $files[] = [
                            'url' => $zip_url,
                            'name' => 'order_' . $order->get_order_number() . '_item_' . $item_id . '.zip'
                        ];
                    }
                }
            }

            if ( empty( $files ) ) {
                wp_send_json_error( [ 'message' => __( 'No Zakeke files found in selected orders.', 'vss' ) ] );
            }

            wp_send_json_success( [ 'files' => $files ] );
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
         * AJAX handler for downloading admin ZIP (alternative method)
         */
        public static function ajax_download_admin_zip() {
            // Check nonce
            check_ajax_referer( 'vss_frontend_nonce', 'nonce' );

            $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
            $file_id = isset( $_POST['file_id'] ) ? intval( $_POST['file_id'] ) : 0;

            if ( ! $order_id || ! $file_id ) {
                wp_send_json_error( [ 'message' => __( 'Invalid request.', 'vss' ) ] );
            }

            // Verify user has access
            if ( ! self::is_current_user_vendor() ) {
                wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vss' ) ] );
            }

            $order = wc_get_order( $order_id );
            if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != get_current_user_id() ) {
                wp_send_json_error( [ 'message' => __( 'Invalid order or permission denied.', 'vss' ) ] );
            }

            // Verify file belongs to order
            $attached_file_id = get_post_meta( $order_id, '_vss_attached_zip_id', true );
            if ( $attached_file_id != $file_id ) {
                wp_send_json_error( [ 'message' => __( 'File not found for this order.', 'vss' ) ] );
            }

            // Generate secure download URL
            $download_url = self::get_secure_download_url( $file_id, $order_id );

            wp_send_json_success( [
                'download_url' => $download_url,
                'message' => __( 'Download link generated successfully.', 'vss' ),
            ] );
        }

        /**
         * AJAX quick save tracking handler
         */
        public static function ajax_quick_save_tracking() {
            check_ajax_referer( 'vss_frontend_nonce', 'nonce' );

            if ( ! self::is_current_user_vendor() ) {
                wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vss' ) ] );
            }

            $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
            $tracking_carrier = isset( $_POST['tracking_carrier'] ) ? sanitize_text_field( $_POST['tracking_carrier'] ) : '';
            $tracking_number = isset( $_POST['tracking_number'] ) ? sanitize_text_field( $_POST['tracking_number'] ) : '';

            if ( ! $order_id || ! $tracking_carrier || ! $tracking_number ) {
                wp_send_json_error( [ 'message' => __( 'Missing required information.', 'vss' ) ] );
            }

            // Verify vendor has access to this order
            $order = wc_get_order( $order_id );
            if ( ! $order || get_post_meta( $order_id, '_vss_vendor_user_id', true ) != get_current_user_id() ) {
                wp_send_json_error( [ 'message' => __( 'Invalid order or permission denied.', 'vss' ) ] );
            }

            // Save tracking information
            update_post_meta( $order_id, '_vss_tracking_number', $tracking_number );
            update_post_meta( $order_id, '_vss_tracking_carrier', $tracking_carrier );
            update_post_meta( $order_id, '_vss_shipped_at', current_time( 'timestamp' ) );

            // Update order status to shipped
            $order->update_status( 'shipped', __( 'Order marked as shipped by vendor with tracking information.', 'vss' ) );

            // Send notification email
            do_action( 'vss_order_shipped', $order_id, $tracking_number, $tracking_carrier );

            wp_send_json_success( [
                'message' => __( 'Tracking information saved and order marked as shipped!', 'vss' ),
                'order_id' => $order_id,
                'tracking_number' => $tracking_number,
                'tracking_carrier' => $tracking_carrier,
            ] );
        }




}
