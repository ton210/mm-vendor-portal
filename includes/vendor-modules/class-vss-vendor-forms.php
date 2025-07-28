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
        if ( ! isset( $_POST['vss_fe_action'] ) ) {
            return;
        }

        $action = sanitize_key( $_POST['vss_fe_action'] );

        // Handle vendor application separately (doesn't require vendor status)
        if ( $action === 'vendor_application' ) {
            self::handle_vendor_application();
            return;
        }

        // All other actions require vendor status
        if ( ! self::is_current_user_vendor() ) {
            return;
        }

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

            case 'report_issue':
                self::handle_report_issue( $order, $redirect_args );
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

    /**
     * Handle vendor issue reporting
     */
    private static function handle_report_issue( $order, &$redirect_args ) {
        if ( ! check_admin_referer( 'vss_report_issue' ) ) {
            wp_die( __( 'Security check failed.', 'vss' ) );
        }

        $priority = isset( $_POST['issue_priority'] ) ? sanitize_key( $_POST['issue_priority'] ) : 'low';
        $message = isset( $_POST['issue_message'] ) ? sanitize_textarea_field( $_POST['issue_message'] ) : '';

        if ( empty( $message ) ) {
            $redirect_args['vss_error'] = 'issue_message_required';
            wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
            exit;
        }

        // Save issue
        $issues = get_post_meta( $order->get_id(), '_vss_vendor_issues', true ) ?: [];
        $vendor = wp_get_current_user();

        $new_issue = [
            'vendor_id' => get_current_user_id(),
            'vendor_name' => $vendor->display_name,
            'timestamp' => current_time( 'timestamp' ),
            'priority' => $priority,
            'message' => $message,
            'order_number' => $order->get_order_number(),
            'order_id' => $order->get_id(),
        ];

        $issues[] = $new_issue;
        update_post_meta( $order->get_id(), '_vss_vendor_issues', $issues );

        // Add order note
        $order->add_order_note( sprintf(
            __( 'Vendor reported %s priority issue: %s', 'vss' ),
            $priority,
            $message
        ) );

        // Send notifications
        self::send_issue_notifications( $new_issue, $order );

        $redirect_args['vss_notice'] = 'issue_reported';
        wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
        exit;
    }

    /**
     * Send email and Slack notifications for issues
     */
    private static function send_issue_notifications( $issue, $order ) {
        $settings = get_option( 'vss_zakeke_settings' );

        // Email notification
        $admin_email = isset( $settings['admin_email_notifications'] ) ? $settings['admin_email_notifications'] : get_option( 'admin_email' );

        if ( $admin_email ) {
            $subject = sprintf( '[%s Priority] Vendor Issue - Order #%s', ucfirst( $issue['priority'] ), $issue['order_number'] );

            $message = "Vendor Issue Report\n\n";
            $message .= "Order: #{$issue['order_number']}\n";
            $message .= "Vendor: {$issue['vendor_name']}\n";
            $message .= "Priority: " . ucfirst( $issue['priority'] ) . "\n";
            $message .= "Time: " . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $issue['timestamp'] ) . "\n\n";
            $message .= "Issue Description:\n{$issue['message']}\n\n";
            $message .= "View Order: " . admin_url( 'post.php?post=' . $issue['order_id'] . '&action=edit' );

            wp_mail( $admin_email, $subject, $message );
        }

        // Slack notification
        $webhook_url = isset( $settings['slack_webhook_url'] ) ? $settings['slack_webhook_url'] : '';
        $channel = isset( $settings['slack_channel'] ) ? $settings['slack_channel'] : '#vendor-issues';

        if ( $webhook_url ) {
            $color = 'good';
            if ( $issue['priority'] === 'high' ) $color = 'warning';
            if ( $issue['priority'] === 'urgent' ) $color = 'danger';

            $slack_message = [
                'channel' => $channel,
                'username' => 'Vendor Issue Bot',
                'icon_emoji' => ':warning:',
                'attachments' => [
                    [
                        'color' => $color,
                        'title' => sprintf( '[%s Priority] Order #%s', ucfirst( $issue['priority'] ), $issue['order_number'] ),
                        'fields' => [
                            [
                                'title' => 'Vendor',
                                'value' => $issue['vendor_name'],
                                'short' => true,
                            ],
                            [
                                'title' => 'Priority',
                                'value' => ucfirst( $issue['priority'] ),
                                'short' => true,
                            ],
                            [
                                'title' => 'Issue Description',
                                'value' => $issue['message'],
                                'short' => false,
                            ],
                        ],
                        'actions' => [
                            [
                                'type' => 'button',
                                'text' => 'View Order',
                                'url' => admin_url( 'post.php?post=' . $issue['order_id'] . '&action=edit' ),
                            ],
                        ],
                        'footer' => 'Vendor Order Manager',
                        'ts' => $issue['timestamp'],
                    ],
                ],
            ];

            wp_remote_post( $webhook_url, [
                'body' => json_encode( $slack_message ),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ] );
        }
    }

    /**
     * Handle vendor application
     */
    private static function handle_vendor_application() {
        if ( ! isset( $_POST['vss_apply_vendor'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'vss_vendor_application' ) ) {
            return;
        }

        $first_name = sanitize_text_field( $_POST['vss_first_name'] );
        $last_name = sanitize_text_field( $_POST['vss_last_name'] );
        $email = sanitize_email( $_POST['vss_email'] );
        $company_name = sanitize_text_field( $_POST['vss_company_name'] );
        $password = $_POST['vss_password'];

        if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) || empty( $password ) ) {
            // Handle error
            return;
        }

        if ( email_exists( $email ) ) {
            // Handle error
            return;
        }

        $user_id = wp_create_user( $email, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            // Handle error
            return;
        }

        wp_update_user( [
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $first_name . ' ' . $last_name,
        ] );

        $user = new WP_User( $user_id );
        $user->set_role( 'vendor-mm' );

        if ( ! empty( $company_name ) ) {
            update_user_meta( $user_id, 'vss_company_name', $company_name );
        }

        // Send notification to admin
        wp_mail(
            get_option( 'admin_email' ),
            'New Vendor Application',
            "A new vendor has applied:\n\nName: $first_name $last_name\nEmail: $email\nCompany: $company_name"
        );

        // Log in the new vendor
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id );

        // Redirect to the vendor portal
        wp_safe_redirect( home_url( '/vendor-portal/' ) );
        exit;
    }
}