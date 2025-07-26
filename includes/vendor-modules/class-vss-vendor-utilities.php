<?php
/**
 * VSS Vendor Utilities Module
 * 
 * Utility functions and helpers
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Utilities functionality
 */
trait VSS_Vendor_Utilities {


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
         * Render error message
         */
        private static function render_error_message( $message ) {
            echo '<div class="vss-error-notice"><p>' . esc_html( $message ) . '</p></div>';
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
         * Handle direct file downloads with authentication
         */
        public static function handle_file_download() {
            // Check if this is a VSS file download request
            if ( isset( $_GET['vss_download'] ) && isset( $_GET['file_id'] ) && isset( $_GET['order_id'] ) ) {
                $file_id = intval( $_GET['file_id'] );
                $order_id = intval( $_GET['order_id'] );
                $nonce = isset( $_GET['_wpnonce'] ) ? $_GET['_wpnonce'] : '';

                // Verify nonce
                if ( ! wp_verify_nonce( $nonce, 'vss_download_file_' . $file_id . '_' . $order_id ) ) {
                    wp_die( __( 'Security check failed.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 403 ] );
                }

                // Check if user is logged in
                if ( ! is_user_logged_in() ) {
                    wp_die( __( 'You must be logged in to download files.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 403 ] );
                }

                // Verify user has access to this order
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    wp_die( __( 'Order not found.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 404 ] );
                }

                $current_user_id = get_current_user_id();
                $vendor_id = get_post_meta( $order_id, '_vss_vendor_user_id', true );

                // Check if user is the assigned vendor or an admin
                if ( $vendor_id != $current_user_id && ! current_user_can( 'manage_woocommerce' ) ) {
                    wp_die( __( 'You do not have permission to download this file.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 403 ] );
                }

                // Verify the file belongs to this order
                $attached_file_id = get_post_meta( $order_id, '_vss_attached_zip_id', true );
                if ( $attached_file_id != $file_id ) {
                    wp_die( __( 'File not found for this order.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 404 ] );
                }

                // Get file path
                $file_path = get_attached_file( $file_id );
                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    wp_die( __( 'File not found on server.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 404 ] );
                }

                // Get file info
                $file_name = basename( $file_path );
                $file_type = wp_check_filetype( $file_path );
                $file_mime = $file_type['type'] ?: 'application/octet-stream';

                // Log download
                $order->add_order_note( sprintf(
                    __( 'Admin ZIP file downloaded by %s', 'vss' ),
                    wp_get_current_user()->display_name
                ) );

                // Serve file
                self::serve_file_download( $file_path, $file_name, $file_mime );
                exit;
            }
        }



        /**
         * Serve file for download
         */
        private static function serve_file_download( $file_path, $file_name, $mime_type ) {
            // Clean any output buffers
            while ( ob_get_level() ) {
                ob_end_clean();
            }

            // Disable caching
            header( 'Cache-Control: no-cache, must-revalidate' );
            header( 'Pragma: no-cache' );
            header( 'Expires: 0' );

            // Set content headers
            header( 'Content-Type: ' . $mime_type );
            header( 'Content-Disposition: attachment; filename="' . $file_name . '"' );
            header( 'Content-Length: ' . filesize( $file_path ) );
            header( 'Content-Transfer-Encoding: binary' );

            // Output file
            readfile( $file_path );
        }



        /**
         * Generate secure download URL for admin ZIP files
         */
        public static function get_secure_download_url( $file_id, $order_id ) {
            $nonce = wp_create_nonce( 'vss_download_file_' . $file_id . '_' . $order_id );

            return add_query_arg( [
                'vss_download' => '1',
                'file_id' => $file_id,
                'order_id' => $order_id,
                '_wpnonce' => $nonce,
            ], home_url() );
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


}
