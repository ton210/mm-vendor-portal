<?php
/**
 * VSS Notifications Class
 *
 * Handles all email notifications for vendors, customers, and admins
 *
 * @package VendorOrderManager
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_Notifications {

    /**
     * Initialize notifications
     */
    public static function init() {
        // Order status change notifications
        add_action( 'woocommerce_order_status_changed', [ self::class, 'order_status_changed' ], 10, 4 );

        // Vendor assignment notifications
        add_action( 'vss_vendor_assigned', [ self::class, 'vendor_assigned_notification' ], 10, 2 );

        // Approval notifications
        add_action( 'vss_mockup_submitted', [ self::class, 'mockup_submitted_notification' ], 10, 2 );
        add_action( 'vss_production_file_submitted', [ self::class, 'production_file_submitted_notification' ], 10, 2 );
        add_action( 'vss_mockup_decision', [ self::class, 'mockup_decision_notification' ], 10, 3 );
        add_action( 'vss_production_decision', [ self::class, 'production_decision_notification' ], 10, 3 );

        // Shipping notifications
        add_action( 'vss_order_shipped', [ self::class, 'order_shipped_notification' ], 10, 2 );

        // Add custom email classes
        add_filter( 'woocommerce_email_classes', [ self::class, 'add_email_classes' ] );

        // Settings
        add_filter( 'woocommerce_email_settings', [ self::class, 'add_email_settings' ] );
    }

    /**
     * Order status changed notification
     */
    public static function order_status_changed( $order_id, $old_status, $new_status, $order ) {
        // Notify vendor when order is assigned
        if ( $new_status === 'processing' ) {
            $vendor_id = get_post_meta( $order_id, '_vss_vendor_user_id', true );
            if ( $vendor_id ) {
                self::send_vendor_new_order_notification( $order, $vendor_id );
            }
        }

        // Notify customer when order is shipped
        if ( $new_status === 'shipped' ) {
            self::send_customer_shipped_notification( $order );
        }
    }

    /**
     * Vendor assigned notification
     */
    public static function vendor_assigned_notification( $order_id, $vendor_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        self::send_vendor_new_order_notification( $order, $vendor_id );
    }

    /**
     * Send vendor new order notification
     */
    private static function send_vendor_new_order_notification( $order, $vendor_id ) {
        $vendor = get_user_by( 'id', $vendor_id );
        if ( ! $vendor ) {
            return;
        }

        $subject = sprintf( __( 'New Order Assigned: #%s', 'vss' ), $order->get_order_number() );

        $message = sprintf( __( 'Hello %s,', 'vss' ), $vendor->display_name ) . "\n\n";
        $message .= sprintf( __( 'A new order #%s has been assigned to you.', 'vss' ), $order->get_order_number() ) . "\n\n";
        $message .= __( 'Order Details:', 'vss' ) . "\n";
        $message .= sprintf( __( 'Customer: %s', 'vss' ), $order->get_formatted_billing_full_name() ) . "\n";
        $message .= sprintf( __( 'Order Total: %s', 'vss' ), $order->get_formatted_order_total() ) . "\n";
        $message .= sprintf( __( 'Items: %d', 'vss' ), $order->get_item_count() ) . "\n\n";

        $vendor_portal_url = home_url( '/vendor-portal/' );
        $message .= sprintf( __( 'View order details: %s', 'vss' ), add_query_arg( [
            'vss_action' => 'view_order',
            'order_id' => $order->get_id(),
        ], $vendor_portal_url ) ) . "\n\n";

        $message .= __( 'Please log in to your vendor portal to manage this order.', 'vss' ) . "\n";

        wp_mail( $vendor->user_email, $subject, $message, self::get_email_headers() );
    }

    /**
     * Send customer shipped notification
     */
    private static function send_customer_shipped_notification( $order ) {
        $tracking_number = get_post_meta( $order->get_id(), '_vss_tracking_number', true );
        $tracking_carrier = get_post_meta( $order->get_id(), '_vss_tracking_carrier', true );

        $subject = sprintf( __( 'Your order #%s has been shipped!', 'vss' ), $order->get_order_number() );

        $message = sprintf( __( 'Hello %s,', 'vss' ), $order->get_billing_first_name() ) . "\n\n";
        $message .= sprintf( __( 'Good news! Your order #%s has been shipped.', 'vss' ), $order->get_order_number() ) . "\n\n";

        if ( $tracking_number ) {
            $message .= __( 'Tracking Information:', 'vss' ) . "\n";
            $message .= sprintf( __( 'Tracking Number: %s', 'vss' ), $tracking_number ) . "\n";
            if ( $tracking_carrier ) {
                $message .= sprintf( __( 'Carrier: %s', 'vss' ), $tracking_carrier ) . "\n";
            }
            $message .= "\n";
        }

        $message .= __( 'You can track your order status anytime at:', 'vss' ) . "\n";
        $message .= home_url( '/track-order/' ) . "\n\n";

        $message .= __( 'Thank you for your order!', 'vss' ) . "\n";

        wp_mail( $order->get_billing_email(), $subject, $message, self::get_email_headers() );
    }

    /**
     * Mockup submitted notification
     */
    public static function mockup_submitted_notification( $order, $vendor_id ) {
        $subject = sprintf( __( 'Mockup Approval Required - Order #%s', 'vss' ), $order->get_order_number() );

        $message = sprintf( __( 'Hello %s,', 'vss' ), $order->get_billing_first_name() ) . "\n\n";
        $message .= sprintf( __( 'The mockup for your order #%s is ready for your approval.', 'vss' ), $order->get_order_number() ) . "\n\n";

        $approval_url = add_query_arg( [
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], home_url( '/customer-approval/' ) );

        $message .= __( 'Please review and approve the mockup:', 'vss' ) . "\n";
        $message .= $approval_url . "\n\n";

        $message .= __( 'If you have any questions or need changes, you can request them through the approval page.', 'vss' ) . "\n\n";
        $message .= __( 'Thank you!', 'vss' ) . "\n";

        wp_mail( $order->get_billing_email(), $subject, $message, self::get_email_headers() );
    }

    /**
     * Production file submitted notification
     */
    public static function production_file_submitted_notification( $order, $vendor_id ) {
        $subject = sprintf( __( 'Production File Approval Required - Order #%s', 'vss' ), $order->get_order_number() );

        $message = sprintf( __( 'Hello %s,', 'vss' ), $order->get_billing_first_name() ) . "\n\n";
        $message .= sprintf( __( 'The production files for your order #%s are ready for your final approval.', 'vss' ), $order->get_order_number() ) . "\n\n";

        $approval_url = add_query_arg( [
            'order_id' => $order->get_id(),
            'key' => $order->get_order_key(),
        ], home_url( '/customer-approval/' ) );

        $message .= __( 'Please review and approve the production files:', 'vss' ) . "\n";
        $message .= $approval_url . "\n\n";

        $message .= __( 'Once approved, your order will proceed to production.', 'vss' ) . "\n\n";
        $message .= __( 'Thank you!', 'vss' ) . "\n";

        wp_mail( $order->get_billing_email(), $subject, $message, self::get_email_headers() );
    }

    /**
     * Mockup decision notification
     */
    public static function mockup_decision_notification( $order, $status, $notes ) {
        $vendor_id = get_post_meta( $order->get_id(), '_vss_vendor_user_id', true );
        if ( ! $vendor_id ) {
            return;
        }

        $vendor = get_user_by( 'id', $vendor_id );
        if ( ! $vendor ) {
            return;
        }

        $subject = sprintf(
            __( 'Mockup %s - Order #%s', 'vss' ),
            $status === 'approved' ? __( 'Approved', 'vss' ) : __( 'Changes Requested', 'vss' ),
            $order->get_order_number()
        );

        $message = sprintf( __( 'Hello %s,', 'vss' ), $vendor->display_name ) . "\n\n";

        if ( $status === 'approved' ) {
            $message .= sprintf( __( 'Great news! The customer has approved the mockup for order #%s.', 'vss' ), $order->get_order_number() ) . "\n\n";
            $message .= __( 'You can now proceed with production.', 'vss' ) . "\n";
        } else {
            $message .= sprintf( __( 'The customer has requested changes to the mockup for order #%s.', 'vss' ), $order->get_order_number() ) . "\n\n";
            if ( ! empty( $notes ) ) {
                $message .= __( 'Customer notes:', 'vss' ) . "\n";
                $message .= $notes . "\n\n";
            }
            $message .= __( 'Please review the requested changes and submit a revised mockup.', 'vss' ) . "\n";
        }

        $vendor_portal_url = home_url( '/vendor-portal/' );
        $message .= "\n" . sprintf( __( 'View order details: %s', 'vss' ), add_query_arg( [
            'vss_action' => 'view_order',
            'order_id' => $order->get_id(),
        ], $vendor_portal_url ) ) . "\n";

        wp_mail( $vendor->user_email, $subject, $message, self::get_email_headers() );
    }

    /**
     * Production decision notification
     */
    public static function production_decision_notification( $order, $status, $notes ) {
        $vendor_id = get_post_meta( $order->get_id(), '_vss_vendor_user_id', true );
        if ( ! $vendor_id ) {
            return;
        }

        $vendor = get_user_by( 'id', $vendor_id );
        if ( ! $vendor ) {
            return;
        }

        $subject = sprintf(
            __( 'Production Files %s - Order #%s', 'vss' ),
            $status === 'approved' ? __( 'Approved', 'vss' ) : __( 'Changes Requested', 'vss' ),
            $order->get_order_number()
        );

        $message = sprintf( __( 'Hello %s,', 'vss' ), $vendor->display_name ) . "\n\n";

        if ( $status === 'approved' ) {
            $message .= sprintf( __( 'The customer has approved the production files for order #%s.', 'vss' ), $order->get_order_number() ) . "\n\n";
            $message .= __( 'You are now authorized to proceed with full production.', 'vss' ) . "\n";
        } else {
            $message .= sprintf( __( 'The customer has requested changes to the production files for order #%s.', 'vss' ), $order->get_order_number() ) . "\n\n";
            if ( ! empty( $notes ) ) {
                $message .= __( 'Customer notes:', 'vss' ) . "\n";
                $message .= $notes . "\n\n";
            }
            $message .= __( 'Please review the requested changes and submit revised files.', 'vss' ) . "\n";
        }

        $vendor_portal_url = home_url( '/vendor-portal/' );
        $message .= "\n" . sprintf( __( 'View order details: %s', 'vss' ), add_query_arg( [
            'vss_action' => 'view_order',
            'order_id' => $order->get_id(),
        ], $vendor_portal_url ) ) . "\n";

        wp_mail( $vendor->user_email, $subject, $message, self::get_email_headers() );
    }

    /**
     * Order shipped notification
     */
    public static function order_shipped_notification( $order, $vendor_id ) {
        // Admin notification
        $admin_email = get_option( 'admin_email' );
        $subject = sprintf( __( 'Order #%s Shipped by Vendor', 'vss' ), $order->get_order_number() );

        $vendor = get_user_by( 'id', $vendor_id );
        $vendor_name = $vendor ? $vendor->display_name : __( 'Unknown Vendor', 'vss' );

        $message = sprintf( __( 'Order #%s has been marked as shipped by vendor: %s', 'vss' ), $order->get_order_number(), $vendor_name ) . "\n\n";

        $tracking_number = get_post_meta( $order->get_id(), '_vss_tracking_number', true );
        if ( $tracking_number ) {
            $message .= sprintf( __( 'Tracking Number: %s', 'vss' ), $tracking_number ) . "\n";
        }

        $message .= "\n" . __( 'View order:', 'vss' ) . ' ' . admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );

        wp_mail( $admin_email, $subject, $message, self::get_email_headers() );
    }

    /**
     * Get email headers
     */
    private static function get_email_headers() {
        $headers = [];
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>';

        return $headers;
    }

    /**
     * Add custom email classes
     */
    public static function add_email_classes( $email_classes ) {
        // You can add custom WooCommerce email classes here if needed
        return $email_classes;
    }

    /**
     * Add email settings
     */
    public static function add_email_settings( $settings ) {
        $new_settings = [
            [
                'title' => __( 'Vendor Notifications', 'vss' ),
                'type' => 'title',
                'id' => 'vss_vendor_email_options',
            ],
            [
                'title' => __( 'Enable vendor notifications', 'vss' ),
                'id' => 'vss_enable_vendor_notifications',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __( 'Send email notifications to vendors for new orders and status changes', 'vss' ),
            ],
            [
                'title' => __( 'Enable customer approval notifications', 'vss' ),
                'id' => 'vss_enable_approval_notifications',
                'type' => 'checkbox',
                'default' => 'yes',
                'desc' => __( 'Send email notifications to customers when approval is required', 'vss' ),
            ],
            [
                'type' => 'sectionend',
                'id' => 'vss_vendor_email_options',
            ],
        ];

        // Insert after WooCommerce email options
        $insert_at = array_search( 'email_merchant_notes', array_column( $settings, 'id' ) );
        if ( $insert_at !== false ) {
            array_splice( $settings, $insert_at + 1, 0, $new_settings );
        } else {
            $settings = array_merge( $settings, $new_settings );
        }

        return $settings;
    }

    /**
     * Send test email (utility method)
     */
    public static function send_test_email( $to, $subject, $message ) {
        return wp_mail( $to, $subject, $message, self::get_email_headers() );
    }
}