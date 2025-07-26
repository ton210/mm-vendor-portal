<?php
/**
 * VSS Vendor Forms Module
 * 
 * Form handling and processing
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Forms functionality
 */
trait VSS_Vendor_Forms {


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


}
