<?php
/**
 * VSS Emails Class - Fixed Version
 *
 * Handles all email notifications for the Vendor Order Manager plugin
 *
 * @package VendorOrderManager
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_Emails {

    /**
     * Email templates cache
     *
     * @var array
     */
    private static $templates_cache = [];

    /**
     * Initialize email hooks
     */
    public static function init() {
        // Order assignment emails
        add_action( 'vss_order_assigned_to_vendor', [ self::class, 'send_new_assignment_email' ], 10, 2 );
        
        // Status change emails
        add_action( 'woocommerce_order_status_changed', [ self::class, 'send_status_change_emails' ], 10, 4 );
        
        // Production confirmation emails
        add_action( 'vss_production_confirmed', [ self::class, 'send_production_confirmation_emails' ], 10, 2 );
        
        // Approval emails
        add_action( 'vss_approval_requested', [ self::class, 'send_approval_request_email' ], 10, 3 );
        add_action( 'vss_approval_response', [ self::class, 'send_approval_response_emails' ], 10, 3 );
        
        // Reminder emails
        add_action( 'vss_send_vendor_reminder', [ self::class, 'send_vendor_reminder_email' ], 10, 2 );
        
        // Custom email styles
        add_action( 'woocommerce_email_header', [ self::class, 'add_custom_email_styles' ], 10, 2 );
        
        // Email settings
        add_filter( 'woocommerce_email_settings', [ self::class, 'add_email_settings' ] );
        
        // REMOVED: Email templates filter that was causing the error
        // add_filter( 'woocommerce_locate_template', [ self::class, 'locate_email_template' ], 10, 3 );
        
        // Test email
        add_action( 'wp_ajax_vss_send_test_email', [ self::class, 'ajax_send_test_email' ] );
    }

    /**
     * Add locate_email_template method to prevent fatal error
     * This method allows the plugin to override WooCommerce email templates if needed
     *
     * @param string $template
     * @param string $template_name
     * @param string $template_path
     * @return string
     */
    public static function locate_email_template( $template, $template_name, $template_path ) {
        // Only override email templates
        if ( strpos( $template_name, 'emails/' ) !== 0 ) {
            return $template;
        }
        
        // Check if this is a VSS-specific email template
        $vss_templates = [
            'emails/vss-vendor-new-assignment.php',
            'emails/vss-customer-approval-request.php',
            'emails/vss-customer-production-confirmation.php',
            'emails/vss-vendor-approval-confirmed.php',
            'emails/vss-admin-approval-disapproved.php',
            'emails/vss-vendor-disapproval-notification.php',
        ];
        
        if ( ! in_array( $template_name, $vss_templates ) ) {
            return $template;
        }
        
        // Look for custom template in theme first
        $custom_template = get_stylesheet_directory() . '/woocommerce/' . $template_name;
        if ( file_exists( $custom_template ) ) {
            return $custom_template;
        }
        
        // Look in parent theme
        $parent_template = get_template_directory() . '/woocommerce/' . $template_name;
        if ( file_exists( $parent_template ) ) {
            return $parent_template;
        }
        
        // Look in plugin templates directory
        $plugin_template = VSS_PLUGIN_PATH . 'templates/' . $template_name;
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
        
        // Return original template
        return $template;
    }

    /**
     * Send email using WooCommerce mailer
     *
     * @param string|array $to
     * @param string $subject
     * @param string $message
     * @param array $headers
     * @param array $attachments
     * @return bool
     */
    private static function send_email( $to, $subject, $message, $headers = [], $attachments = [] ) {
        // Get mailer instance
        $mailer = WC()->mailer();

        // Set default headers
        $default_headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        // From header
        $from_name = get_option( 'vss_email_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'vss_email_from_address', get_option( 'admin_email' ) );
        $default_headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );

        // Reply-to header if specified
        if ( isset( $headers['reply_to'] ) ) {
            $default_headers[] = sprintf( 'Reply-To: %s', $headers['reply_to'] );
            unset( $headers['reply_to'] );
        }

        // Merge headers
        $headers = array_merge( $default_headers, $headers );

        // Wrap message in WooCommerce email template
        $wrapped_message = $mailer->wrap_message( $subject, $message );

        // Apply inline styles
        $email_instance = new WC_Email();
        $styled_message = $email_instance->style_inline( $wrapped_message );

        // Send email
        $sent = $mailer->send( $to, $subject, $styled_message, $headers, $attachments );

        // Log email
        if ( get_option( 'vss_enable_email_log', 'no' ) === 'yes' ) {
            self::log_email( $to, $subject, $sent );
        }

        return $sent;
    }

    /**
     * Add email settings to WooCommerce
     *
     * @param array $settings
     * @return array
     */
    public static function add_email_settings( $settings ) {
        $vss_settings = [
            [
                'title' => __( 'Vendor Order Manager Email Settings', 'vss' ),
                'type' => 'title',
                'desc' => __( 'Configure email settings for vendor notifications.', 'vss' ),
                'id' => 'vss_email_options',
            ],
            [
                'title' => __( 'Enable Email Logging', 'vss' ),
                'desc' => __( 'Log all vendor-related emails for debugging.', 'vss' ),
                'id' => 'vss_enable_email_log',
                'type' => 'checkbox',
                'default' => 'no',
            ],
            [
                'title' => __( '"From" Name', 'vss' ),
                'desc' => __( 'Name that vendor emails are sent from.', 'vss' ),
                'id' => 'vss_email_from_name',
                'type' => 'text',
                'default' => get_bloginfo( 'name' ),
            ],
            [
                'title' => __( '"From" Email', 'vss' ),
                'desc' => __( 'Email address that vendor emails are sent from.', 'vss' ),
                'id' => 'vss_email_from_address',
                'type' => 'email',
                'default' => get_option( 'admin_email' ),
            ],
            [
                'type' => 'sectionend',
                'id' => 'vss_email_options',
            ],
        ];

        // Insert after WooCommerce email options
        $insert_at = array_search( 'email_merchant_notes', array_column( $settings, 'id' ) );
        if ( $insert_at !== false ) {
            array_splice( $settings, $insert_at + 1, 0, $vss_settings );
        } else {
            $settings = array_merge( $settings, $vss_settings );
        }

        return $settings;
    }

    /**
     * Add custom email styles
     *
     * @param string $email_heading
     * @param WC_Email $email
     */
    public static function add_custom_email_styles( $email_heading, $email ) {
        ?>
        <style type="text/css">
            /* VSS Custom Email Styles */
            .vss-email-section {
                margin: 30px 0;
                padding: 20px;
                background-color: #f9f9f9;
                border-radius: 8px;
                border: 1px solid #e5e5e5;
            }
            
            .vss-email-button {
                display: inline-block !important;
                padding: 14px 28px !important;
                margin: 10px 5px !important;
                text-decoration: none !important;
                border-radius: 5px !important;
                font-size: 16px !important;
                font-weight: 600 !important;
                text-align: center !important;
                transition: all 0.3s ease !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
                min-width: 140px !important;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
            }
            
            .vss-email-button:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
            }
            
            .vss-email-button-primary {
                background-color: #2271b1 !important;
                color: #ffffff !important;
                border: 2px solid #2271b1 !important;
            }
            
            .vss-email-button-primary:hover {
                background-color: #1e5e96 !important;
                border-color: #1e5e96 !important;
                color: #ffffff !important;
            }
            
            .vss-email-button-approve {
                background-color: #4CAF50 !important;
                color: #ffffff !important;
                border: 2px solid #4CAF50 !important;
            }
            
            .vss-email-button-approve:hover {
                background-color: #45a049 !important;
                border-color: #45a049 !important;
                color: #ffffff !important;
            }
            
            .vss-email-button-disapprove {
                background-color: #f44336 !important;
                color: #ffffff !important;
                border: 2px solid #f44336 !important;
            }
            
            .vss-email-button-disapprove:hover {
                background-color: #da190b !important;
                border-color: #da190b !important;
                color: #ffffff !important;
            }
            
            .vss-button-container {
                text-align: center !important;
                margin: 35px 0 !important;
                padding: 25px !important;
                background-color: #f5f5f5 !important;
                border-radius: 10px !important;
                border: 1px solid #e0e0e0 !important;
            }
            
            .vss-highlight-box {
                background-color: #e3f2fd !important;
                border-left: 4px solid #2196F3 !important;
                padding: 15px 20px !important;
                margin: 20px 0 !important;
                border-radius: 0 5px 5px 0 !important;
            }
            
            .vss-success-box {
                background-color: #e8f5e9 !important;
                border-left: 4px solid #4CAF50 !important;
                padding: 15px 20px !important;
                margin: 20px 0 !important;
                border-radius: 0 5px 5px 0 !important;
            }
            
            .vss-warning-box {
                background-color: #fff3cd !important;
                border-left: 4px solid #ffc107 !important;
                padding: 15px 20px !important;
                margin: 20px 0 !important;
                border-radius: 0 5px 5px 0 !important;
            }
            
            .vss-error-box {
                background-color: #ffebee !important;
                border-left: 4px solid #f44336 !important;
                padding: 15px 20px !important;
                margin: 20px 0 !important;
                border-radius: 0 5px 5px 0 !important;
            }
            
            .vss-order-details {
                width: 100% !important;
                border-collapse: collapse !important;
                margin: 20px 0 !important;
            }
            
            .vss-order-details th {
                background-color: #f5f5f5 !important;
                padding: 12px !important;
                text-align: left !important;
                border-bottom: 2px solid #ddd !important;
                font-weight: 600 !important;
            }
            
            .vss-order-details td {
                padding: 12px !important;
                border-bottom: 1px solid #eee !important;
            }
            
            .vss-file-preview {
                display: inline-block !important;
                margin: 10px !important;
                padding: 10px !important;
                border: 1px solid #ddd !important;
                border-radius: 5px !important;
                text-align: center !important;
                background-color: #fff !important;
            }
            
            .vss-file-preview img {
                max-width: 200px !important;
                max-height: 200px !important;
                border-radius: 3px !important;
            }
            
            .vss-vendor-notes {
                background-color: #f5f5f5 !important;
                padding: 15px !important;
                border-left: 4px solid #2271b1 !important;
                margin: 20px 0 !important;
                font-style: italic !important;
                border-radius: 0 5px 5px 0 !important;
            }
            
            .vss-countdown {
                display: inline-block !important;
                padding: 8px 16px !important;
                border-radius: 25px !important;
                font-weight: bold !important;
                font-size: 14px !important;
                margin: 10px 0 !important;
            }
            
            .vss-countdown-late {
                background-color: #ffebee !important;
                color: #c62828 !important;
                border: 1px solid #ef5350 !important;
            }
            
            .vss-countdown-today {
                background-color: #e8f5e9 !important;
                color: #2e7d32 !important;
                border: 1px solid #66bb6a !important;
            }
            
            .vss-countdown-upcoming {
                background-color: #e3f2fd !important;
                color: #1565c0 !important;
                border: 1px solid #42a5f5 !important;
            }
            
            @media only screen and (max-width: 600px) {
                .vss-email-button {
                    display: block !important;
                    width: 100% !important;
                    margin: 10px 0 !important;
                    box-sizing: border-box !important;
                }
                
                .vss-button-container {
                    padding: 15px !important;
                }
                
                .vss-order-details {
                    font-size: 14px !important;
                }
            }
        </style>
        <?php
    }

    /**
     * Send new assignment email to vendor
     *
     * @param int $order_id
     * @param int $vendor_id
     */
    public static function send_new_assignment_email( $order_id, $vendor_id ) {
        $order = wc_get_order( $order_id );
        $vendor = get_userdata( $vendor_id );

        if ( ! $order || ! $vendor || empty( $vendor->user_email ) ) {
            return;
        }

        $subject = sprintf( 
            __( '🎉 New Order #%s Assigned to You - %s', 'vss' ), 
            $order->get_order_number(), 
            get_bloginfo( 'name' ) 
        );

        $template_data = [
            'vendor' => $vendor,
            'order' => $order,
            'portal_url' => home_url( '/vendor-portal/' ),
            'order_url' => add_query_arg( [
                'vss_action' => 'view_order',
                'order_id' => $order_id,
            ], home_url( '/vendor-portal/' ) ),
            'items' => $order->get_items(),
            'customer_name' => $order->get_formatted_billing_full_name(),
            'shipping_address' => $order->get_formatted_shipping_address(),
            'has_zakeke' => self::order_has_zakeke_items( $order ),
        ];

        $message = self::get_email_template( 'vendor-new-assignment', $template_data );

        $sent = self::send_email( 
            $vendor->user_email, 
            $subject, 
            $message,
            [ 'reply_to' => get_option( 'admin_email' ) ]
        );

        if ( $sent ) {
            $order->add_order_note( sprintf( 
                __( 'New assignment email sent to vendor %s', 'vss' ), 
                $vendor->display_name 
            ) );
        }

        return $sent;
    }

    /**
     * Send status change emails
     *
     * @param int $order_id
     * @param string $old_status
     * @param string $new_status
     * @param WC_Order $order
     */
    public static function send_status_change_emails( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return;
            }
        }

        $vendor_id = get_post_meta( $order_id, '_vss_vendor_user_id', true );
        if ( ! $vendor_id ) {
            return;
        }

        $vendor = get_userdata( $vendor_id );
        if ( ! $vendor || empty( $vendor->user_email ) ) {
            return;
        }

        // Determine which emails to send based on status change
        $email_triggers = [
            'cancelled' => [ 'condition' => $old_status !== 'cancelled', 'template' => 'vendor-order-cancelled' ],
            'on-hold' => [ 'condition' => $old_status !== 'on-hold', 'template' => 'vendor-order-on-hold' ],
            'shipped' => [ 'condition' => $old_status !== 'shipped', 'template' => 'vendor-order-shipped' ],
            'completed' => [ 'condition' => $old_status !== 'completed', 'template' => 'vendor-order-completed' ],
        ];

        if ( ! isset( $email_triggers[ $new_status ] ) || ! $email_triggers[ $new_status ]['condition'] ) {
            return;
        }

        $trigger = $email_triggers[ $new_status ];
        $subject_key = str_replace( '-', '_', $trigger['template'] );
        $subject = apply_filters( "vss_email_subject_{$subject_key}", self::get_default_subject( $trigger['template'], $order ), $order );

        $template_data = [
            'vendor' => $vendor,
            'order' => $order,
            'old_status' => $old_status,
            'new_status' => $new_status,
            'portal_url' => home_url( '/vendor-portal/' ),
            'order_url' => add_query_arg( [
                'vss_action' => 'view_order',
                'order_id' => $order_id,
            ], home_url( '/vendor-portal/' ) ),
        ];

        $message = self::get_email_template( $trigger['template'], $template_data );

        self::send_email( $vendor->user_email, $subject, $message );
    }

    /**
     * Send customer approval request email
     *
     * @param int $order_id
     * @param string $type
     * @return bool
     */
    public static function send_customer_approval_request_email( $order_id, $type = 'mockup' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $customer_email = $order->get_billing_email();
        if ( ! $customer_email ) {
            $order->add_order_note( sprintf( 
                __( 'Customer %s approval request email NOT sent: No billing email found.', 'vss' ), 
                $type 
            ) );
            return false;
        }

        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        $subject = sprintf( 
            __( '👀 Action Required: Review %s for Order #%s', 'vss' ), 
            $type_label,
            $order->get_order_number()
        );

        // Get approval data
        $files_ids = get_post_meta( $order_id, "_vss_{$type}_files", true ) ?: [];
        $vendor_notes = get_post_meta( $order_id, "_vss_{$type}_vendor_notes", true );
        $vendor_id = get_post_meta( $order_id, '_vss_vendor_user_id', true );
        $vendor = $vendor_id ? get_userdata( $vendor_id ) : null;

        // Generate secure approval URLs
        $approve_nonce = wp_create_nonce( "vss_approve_{$type}_{$order_id}" );
        $disapprove_nonce = wp_create_nonce( "vss_disapprove_{$type}_{$order_id}" );

        $base_url = admin_url( 'admin-post.php' );
        $approve_url = add_query_arg( [
            'action' => "vss_handle_{$type}_approval",
            'order_id' => $order_id,
            'approval_status' => 'approved',
            '_wpnonce' => $approve_nonce,
        ], $base_url );

        $disapprove_url = add_query_arg( [
            'action' => "vss_handle_{$type}_approval",
            'order_id' => $order_id,
            'approval_status' => 'disapproved',
            '_wpnonce' => $disapprove_nonce,
        ], $base_url );

        $template_data = [
            'customer_name' => $order->get_billing_first_name() ?: __( 'Valued Customer', 'vss' ),
            'order' => $order,
            'type' => $type,
            'type_label' => $type_label,
            'files_ids' => $files_ids,
            'vendor_notes' => $vendor_notes,
            'vendor' => $vendor,
            'approve_url' => $approve_url,
            'disapprove_url' => $disapprove_url,
            'items' => $order->get_items(),
        ];

        $message = self::get_email_template( 'customer-approval-request', $template_data );

        $sent = self::send_email( 
            $customer_email, 
            $subject, 
            $message,
            [ 'reply_to' => 'help@munchmakers.com' ]
        );

        if ( $sent ) {
            update_post_meta( $order_id, "_vss_{$type}_email_sent_at", time() );
            $order->add_order_note( sprintf( 
                __( '%s approval request email sent to customer.', 'vss' ), 
                $type_label 
            ) );
        } else {
            $order->add_order_note( sprintf( 
                __( 'Failed to send %s approval request email to customer.', 'vss' ), 
                $type_label 
            ) );
        }

        return $sent;
    }

    /**
     * Send vendor approval confirmation email
     *
     * @param int $order_id
     * @param string $type
     * @return bool
     */
    public static function send_vendor_approval_confirmed_email( $order_id, $type = 'mockup' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $vendor_id = get_post_meta( $order_id, '_vss_vendor_user_id', true );
        if ( ! $vendor_id ) {
            return false;
        }

        $vendor = get_userdata( $vendor_id );
        if ( ! $vendor || empty( $vendor->user_email ) ) {
            return false;
        }

        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        $subject = sprintf( 
            __( '✅ %s Approved for Order #%s!', 'vss' ), 
            $type_label, 
            $order->get_order_number() 
        );

        $template_data = [
            'vendor' => $vendor,
            'order' => $order,
            'type' => $type,
            'type_label' => $type_label,
            'portal_url' => home_url( '/vendor-portal/' ),
            'order_url' => add_query_arg( [
                'vss_action' => 'view_order',
                'order_id' => $order_id,
            ], home_url( '/vendor-portal/' ) ),
            'tab_hash' => ( $type === 'mockup' ) ? '#tab-mockup' : '#tab-production',
        ];

        $message = self::get_email_template( 'vendor-approval-confirmed', $template_data );

        return self::send_email( $vendor->user_email, $subject, $message );
    }

    /**
     * Send admin disapproval notification email
     *
     * @param int $order_id
     * @param string $type
     * @param string $customer_notes
     * @return bool
     */
    public static function send_admin_approval_disapproved_email( $order_id, $type = 'mockup', $customer_notes = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $admin_email = get_option( 'admin_email' );
        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        $subject = sprintf( 
            __( '⚠️ %s DISAPPROVED for Order #%s - Action Required', 'vss' ), 
            $type_label, 
            $order->get_order_number() 
        );

        $vendor_id = get_post_meta( $order_id, '_vss_vendor_user_id', true );
        $vendor = $vendor_id ? get_userdata( $vendor_id ) : null;

        $template_data = [
            'order' => $order,
            'type' => $type,
            'type_label' => $type_label,
            'customer_notes' => $customer_notes,
            'vendor' => $vendor,
            'edit_order_url' => admin_url( 'post.php?post=' . $order_id . '&action=edit' ),
            'customer_email' => $order->get_billing_email(),
            'customer_phone' => $order->get_billing_phone(),
        ];

        $message = self::get_email_template( 'admin-approval-disapproved', $template_data );

        return self::send_email( $admin_email, $subject, $message );
    }

    /**
     * Send vendor disapproval notification
     *
     * @param int $order_id
     * @param string $type
     * @param string $customer_feedback
     * @return bool
     */
    public static function send_vendor_disapproval_notification( $order_id, $type, $customer_feedback = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $vendor_id = get_post_meta( $order_id, '_vss_vendor_user_id', true );
        if ( ! $vendor_id ) {
            return false;
        }

        $vendor = get_userdata( $vendor_id );
        if ( ! $vendor || empty( $vendor->user_email ) ) {
            return false;
        }

        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        $subject = sprintf( 
            __( '🔄 Changes Requested for %s - Order #%s', 'vss' ), 
            $type_label, 
            $order->get_order_number() 
        );

        $template_data = [
            'vendor' => $vendor,
            'order' => $order,
            'type' => $type,
            'type_label' => $type_label,
            'customer_feedback' => $customer_feedback,
            'portal_url' => home_url( '/vendor-portal/' ),
            'order_url' => add_query_arg( [
                'vss_action' => 'view_order',
                'order_id' => $order_id,
            ], home_url( '/vendor-portal/' ) ),
            'tab_hash' => ( $type === 'mockup' ) ? '#tab-mockup' : '#tab-production',
        ];

        $message = self::get_email_template( 'vendor-disapproval-notification', $template_data );

        return self::send_email( $vendor->user_email, $subject, $message );
    }

    /**
     * Send customer production confirmation email
     *
     * @param int $order_id
     * @param string $order_number
     * @param string $estimated_ship_date
     * @return bool
     */
    public static function send_customer_production_confirmation_email( $order_id, $order_number, $estimated_ship_date ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        // Check if already sent for this date
        $email_sent_flag = '_vss_customer_production_email_sent_at';
        $date_flag = '_vss_estimated_ship_date_at_last_email';
        
        $last_sent = get_post_meta( $order_id, $email_sent_flag, true );
        $last_date = get_post_meta( $order_id, $date_flag, true );
        
        if ( $last_sent && $last_date === $estimated_ship_date ) {
            return false;
        }

        $customer_email = $order->get_billing_email();
        if ( ! $customer_email ) {
            $order->add_order_note( __( 'Customer production confirmation email NOT sent: No billing email found.', 'vss' ) );
            return false;
        }

        $subject = sprintf( 
            __( '🎯 Your Order #%s is Now in Production!', 'vss' ), 
            $order_number 
        );

        $template_data = [
            'customer_name' => $order->get_billing_first_name() ?: __( 'Valued Customer', 'vss' ),
            'order' => $order,
            'order_number' => $order_number,
            'estimated_ship_date' => $estimated_ship_date,
            'formatted_ship_date' => date_i18n( get_option( 'date_format' ), strtotime( $estimated_ship_date ) ),
            'days_until_ship' => self::calculate_days_until_ship( $estimated_ship_date ),
            'items' => $order->get_items(),
            'tracking_url' => add_query_arg( [
                'track_order' => $order_id,
                'email' => $customer_email,
            ], home_url() ),
        ];

        $message = self::get_email_template( 'customer-production-confirmation', $template_data );

        $sent = self::send_email( 
            $customer_email, 
            $subject, 
            $message,
            [ 'reply_to' => 'help@munchmakers.com' ]
        );

        if ( $sent ) {
            update_post_meta( $order_id, $email_sent_flag, time() );
            update_post_meta( $order_id, $date_flag, $estimated_ship_date );
            $order->add_order_note( __( 'Production confirmation email sent to customer.', 'vss' ) );
        } else {
            $order->add_order_note( __( 'Failed to send production confirmation email to customer.', 'vss' ) );
        }

        return $sent;
    }

    /**
     * Send vendor reminder email
     *
     * @param int $order_id
     * @param int $vendor_id
     * @return bool
     */
    public static function send_vendor_reminder_email( $order_id, $vendor_id ) {
        $order = wc_get_order( $order_id );
        $vendor = get_userdata( $vendor_id );

        if ( ! $order || ! $vendor || empty( $vendor->user_email ) ) {
            return false;
        }

        // Check when last reminder was sent
        $last_reminder = get_post_meta( $order_id, '_vss_last_vendor_reminder', true );
        if ( $last_reminder && ( time() - $last_reminder ) < DAY_IN_SECONDS ) {
            return false; // Don't send more than one reminder per day
        }

        $subject = sprintf( 
            __( '⏰ Reminder: Order #%s Requires Your Attention', 'vss' ), 
            $order->get_order_number() 
        );

        $days_old = round( ( time() - $order->get_date_created()->getTimestamp() ) / DAY_IN_SECONDS );

        $template_data = [
            'vendor' => $vendor,
            'order' => $order,
            'days_old' => $days_old,
            'portal_url' => home_url( '/vendor-portal/' ),
            'order_url' => add_query_arg( [
                'vss_action' => 'view_order',
                'order_id' => $order_id,
            ], home_url( '/vendor-portal/' ) ),
            'has_ship_date' => (bool) get_post_meta( $order_id, '_vss_estimated_ship_date', true ),
            'mockup_status' => get_post_meta( $order_id, '_vss_mockup_status', true ),
            'production_status' => get_post_meta( $order_id, '_vss_production_file_status', true ),
        ];

        $message = self::get_email_template( 'vendor-reminder', $template_data );

        $sent = self::send_email( $vendor->user_email, $subject, $message );

        if ( $sent ) {
            update_post_meta( $order_id, '_vss_last_vendor_reminder', time() );
            $order->add_order_note( sprintf( 
                __( 'Reminder email sent to vendor %s', 'vss' ), 
                $vendor->display_name 
            ) );
        }

        return $sent;
    }

    /**
     * Get email template
     *
     * @param string $template
     * @param array $data
     * @return string
     */
    private static function get_email_template( $template, $data = [] ) {
        // Check cache
        $cache_key = md5( $template . serialize( $data ) );
        if ( isset( self::$templates_cache[ $cache_key ] ) ) {
            return self::$templates_cache[ $cache_key ];
        }

        // Extract data for use in template
        extract( $data );

        // Start output buffering
        ob_start();

        // Try to load custom template first
        $custom_template = get_stylesheet_directory() . '/vss-emails/' . $template . '.php';
        $plugin_template = VSS_PLUGIN_PATH . 'templates/emails/' . $template . '.php';

        if ( file_exists( $custom_template ) ) {
            include $custom_template;
        } elseif ( file_exists( $plugin_template ) ) {
            include $plugin_template;
        } else {
            // Use inline template
            self::render_inline_template( $template, $data );
        }

        $content = ob_get_clean();

        // Cache the result
        self::$templates_cache[ $cache_key ] = $content;

        return $content;
    }

    /**
     * Render inline template
     *
     * @param string $template
     * @param array $data
     */
    private static function render_inline_template( $template, $data ) {
        extract( $data );

        switch ( $template ) {
            case 'vendor-new-assignment':
                ?>
                <div class="vss-email-content">
                    <h2><?php printf( __( 'Hello %s,', 'vss' ), esc_html( $vendor->display_name ) ); ?></h2>
                    
                    <div class="vss-success-box">
                        <p><?php printf( __( 'Great news! A new order <strong>#%s</strong> has been assigned to you.', 'vss' ), esc_html( $order->get_order_number() ) ); ?></p>
                    </div>

                    <div class="vss-button-container">
                        <a href="<?php echo esc_url( $order_url ); ?>" class="vss-email-button vss-email-button-primary">
                            <?php esc_html_e( 'View Order Details', 'vss' ); ?>
                        </a>
                    </div>

                    <h3><?php esc_html_e( 'Order Summary:', 'vss' ); ?></h3>
                    <table class="vss-order-details">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Product', 'vss' ); ?></th>
                                <th><?php esc_html_e( 'Quantity', 'vss' ); ?></th>
                                <th><?php esc_html_e( 'SKU', 'vss' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $items as $item ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $item->get_name() ); ?></td>
                                    <td style="text-align: center;"><?php echo esc_html( $item->get_quantity() ); ?></td>
                                    <td><?php echo esc_html( $item->get_product() ? $item->get_product()->get_sku() : '—' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ( $has_zakeke ) : ?>
                        <div class="vss-highlight-box">
                            <p><strong><?php esc_html_e( 'Note:', 'vss' ); ?></strong> <?php esc_html_e( 'This order contains customized Zakeke items. Design files will be available soon.', 'vss' ); ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="vss-email-section">
                        <h4><?php esc_html_e( 'Shipping Information:', 'vss' ); ?></h4>
                        <p><?php echo wp_kses_post( $shipping_address ); ?></p>
                    </div>

                    <div class="vss-email-section">
                        <h4><?php esc_html_e( 'Next Steps:', 'vss' ); ?></h4>
                        <ol>
                            <li><?php esc_html_e( 'Review the order details and customer requirements', 'vss' ); ?></li>
                            <li><?php esc_html_e( 'Confirm production and set your estimated ship date', 'vss' ); ?></li>
                            <li><?php esc_html_e( 'Upload mockups for customer approval', 'vss' ); ?></li>
                            <li><?php esc_html_e( 'Submit your costs for this order', 'vss' ); ?></li>
                        </ol>
                    </div>

                    <p><?php esc_html_e( 'Thank you for your partnership!', 'vss' ); ?></p>
                    <p><em><?php echo esc_html( get_bloginfo( 'name' ) ); ?></em></p>
                </div>
                <?php
                break;

            case 'customer-approval-request':
                ?>
                <div class="vss-email-content">
                    <h2><?php printf( __( 'Hello %s,', 'vss' ), esc_html( $customer_name ) ); ?></h2>
                    
                    <p><?php printf( __( 'Your %s is ready for review for order <strong>#%s</strong>.', 'vss' ), strtolower( $type_label ), esc_html( $order->get_order_number() ) ); ?></p>

                    <?php if ( $vendor_notes ) : ?>
                        <div class="vss-vendor-notes">
                            <h4><?php esc_html_e( 'Notes from your vendor:', 'vss' ); ?></h4>
                            <p><?php echo nl2br( esc_html( $vendor_notes ) ); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $files_ids ) ) : ?>
                        <div class="vss-email-section">
                            <h3><?php esc_html_e( 'Files for Your Review:', 'vss' ); ?></h3>
                            <div style="text-align: center;">
                                <?php foreach ( $files_ids as $file_id ) : ?>
                                    <?php
                                    $file_url = wp_get_attachment_url( $file_id );
                                    if ( $file_url ) :
                                        if ( wp_attachment_is_image( $file_id ) ) :
                                            $img_src = wp_get_attachment_image_src( $file_id, 'large' );
                                            if ( $img_src ) :
                                    ?>
                                        <div class="vss-file-preview">
                                            <a href="<?php echo esc_url( $file_url ); ?>">
                                                <img src="<?php echo esc_url( $img_src[0] ); ?>" alt="<?php echo esc_attr( $type_label ); ?>" />
                                            </a>
                                        </div>
                                    <?php 
                                            endif;
                                        else :
                                    ?>
                                        <div class="vss-file-preview">
                                            <a href="<?php echo esc_url( $file_url ); ?>" style="display: block; padding: 20px;">
                                                📎 <?php echo esc_html( basename( get_attached_file( $file_id ) ) ); ?>
                                            </a>
                                        </div>
                                    <?php 
                                        endif;
                                    endif;
                                    ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="vss-button-container">
                        <h3><?php esc_html_e( 'Please choose your response:', 'vss' ); ?></h3>
                        <a href="<?php echo esc_url( $approve_url ); ?>" class="vss-email-button vss-email-button-approve">
                            ✓ <?php esc_html_e( 'APPROVE', 'vss' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $disapprove_url ); ?>" class="vss-email-button vss-email-button-disapprove">
                            ✗ <?php esc_html_e( 'REQUEST CHANGES', 'vss' ); ?>
                        </a>
                    </div>

                    <div class="vss-email-section" style="background-color: #f9f9f9; font-size: 14px;">
                        <p><strong><?php esc_html_e( 'If the buttons above don\'t work:', 'vss' ); ?></strong></p>
                        <p><?php esc_html_e( 'To Approve:', 'vss' ); ?><br>
                        <span style="font-size: 12px; color: #666;"><?php echo esc_url( $approve_url ); ?></span></p>
                        <p><?php esc_html_e( 'To Request Changes:', 'vss' ); ?><br>
                        <span style="font-size: 12px; color: #666;"><?php echo esc_url( $disapprove_url ); ?></span></p>
                    </div>

                    <p><?php esc_html_e( 'Questions? Reply to this email or contact us at help@munchmakers.com', 'vss' ); ?></p>
                </div>
                <?php
                break;

            case 'customer-production-confirmation':
                ?>
                <div class="vss-email-content">
                    <h2><?php printf( __( 'Hello %s,', 'vss' ), esc_html( $customer_name ) ); ?></h2>
                    
                    <div class="vss-success-box">
                        <h3>🎉 <?php esc_html_e( 'Great News!', 'vss' ); ?></h3>
                        <p><?php printf( __( 'Your order <strong>%s</strong> is now in production!', 'vss' ), esc_html( $order_number ) ); ?></p>
                        <p><strong><?php printf( __( 'Estimated Ship Date: %s', 'vss' ), esc_html( $formatted_ship_date ) ); ?></strong></p>
                        <?php if ( $days_until_ship > 0 ) : ?>
                            <span class="vss-countdown vss-countdown-upcoming">
                                <?php printf( _n( '%d day until shipping', '%d days until shipping', $days_until_ship, 'vss' ), $days_until_ship ); ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="vss-email-section">
                        <h3><?php esc_html_e( 'What happens next?', 'vss' ); ?></h3>
                        <ul>
                            <li><?php esc_html_e( 'Our skilled vendor is carefully crafting your custom order', 'vss' ); ?></li>
                            <li><?php esc_html_e( 'You\'ll receive tracking information once your order ships', 'vss' ); ?></li>
                            <li><?php esc_html_e( 'We\'ll notify you of any updates along the way', 'vss' ); ?></li>
                        </ul>
                    </div>

                    <div class="vss-email-section">
                        <h4><?php esc_html_e( 'Your Order:', 'vss' ); ?></h4>
                        <ul>
                            <?php foreach ( $items as $item ) : ?>
                                <li><?php echo esc_html( $item->get_name() ); ?> × <?php echo esc_html( $item->get_quantity() ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="vss-highlight-box">
                        <p><?php esc_html_e( 'Questions or need to make changes? Simply reply to this email or contact us at help@munchmakers.com', 'vss' ); ?></p>
                    </div>

                    <p><?php esc_html_e( 'Thank you for your patience as we create something special just for you!', 'vss' ); ?></p>
                    <p><strong><?php esc_html_e( 'The MunchMakers Team', 'vss' ); ?></strong></p>
                </div>
                <?php
                break;

            default:
                ?>
                <p><?php printf( __( 'Template "%s" not found.', 'vss' ), esc_html( $template ) ); ?></p>
                <?php
                break;
        }
    }

    /**
     * Get default email subject
     *
     * @param string $template
     * @param WC_Order $order
     * @return string
     */
    private static function get_default_subject( $template, $order ) {
        $subjects = [
            'vendor-order-cancelled' => sprintf( __( '❌ Order #%s Cancelled', 'vss' ), $order->get_order_number() ),
            'vendor-order-on-hold' => sprintf( __( '⏸️ Order #%s On Hold', 'vss' ), $order->get_order_number() ),
            'vendor-order-shipped' => sprintf( __( '📦 Order #%s Marked as Shipped', 'vss' ), $order->get_order_number() ),
            'vendor-order-completed' => sprintf( __( '✅ Order #%s Completed', 'vss' ), $order->get_order_number() ),
        ];

        return isset( $subjects[ $template ] ) ? $subjects[ $template ] : sprintf( __( 'Order #%s Update', 'vss' ), $order->get_order_number() );
    }

    /**
     * Check if order has Zakeke items
     *
     * @param WC_Order $order
     * @return bool
     */
    private static function order_has_zakeke_items( $order ) {
        foreach ( $order->get_items() as $item ) {
            if ( $item->get_meta( 'zakeke_data', true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Calculate days until ship
     *
     * @param string $ship_date
     * @return int
     */
    private static function calculate_days_until_ship( $ship_date ) {
        $ship_timestamp = strtotime( $ship_date );
        $today_timestamp = current_time( 'timestamp' );
        $diff = $ship_timestamp - $today_timestamp;
        return max( 0, ceil( $diff / DAY_IN_SECONDS ) );
    }

    /**
     * Log email
     *
     * @param string|array $to
     * @param string $subject
     * @param bool $sent
     */
    private static function log_email( $to, $subject, $sent ) {
        $log_entry = [
            'to' => is_array( $to ) ? implode( ', ', $to ) : $to,
            'subject' => $subject,
            'sent' => $sent,
            'timestamp' => current_time( 'mysql' ),
        ];

        // Store in custom table or as option
        $email_log = get_option( 'vss_email_log', [] );
        array_unshift( $email_log, $log_entry );
        
        // Keep only last 100 entries
        $email_log = array_slice( $email_log, 0, 100 );
        
        update_option( 'vss_email_log', $email_log );
    }

    /**
     * AJAX handler for sending test email
     */
    public static function ajax_send_test_email() {
        check_ajax_referer( 'vss_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'vss' ) ] );
        }

        $email_type = isset( $_POST['email_type'] ) ? sanitize_key( $_POST['email_type'] ) : '';
        $recipient = isset( $_POST['recipient'] ) ? sanitize_email( $_POST['recipient'] ) : get_option( 'admin_email' );

        if ( ! is_email( $recipient ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid email address.', 'vss' ) ] );
        }

        // Create test data
        $test_order = wc_create_order();
        $test_order->set_order_key( 'test_' . uniqid() );
        $test_order->add_product( wc_get_product( get_option( 'woocommerce_placeholder_image', 0 ) ) ?: new WC_Product(), 1 );
        $test_order->set_billing_email( $recipient );
        $test_order->set_billing_first_name( 'Test' );
        $test_order->set_billing_last_name( 'Customer' );

        $sent = false;
        $subject = sprintf( __( '[TEST] %s Email', 'vss' ), ucfirst( str_replace( '_', ' ', $email_type ) ) );

        switch ( $email_type ) {
            case 'vendor_assignment':
                $test_vendor = wp_get_current_user();
                update_post_meta( $test_order->get_id(), '_vss_vendor_user_id', $test_vendor->ID );
                $sent = self::send_new_assignment_email( $test_order->get_id(), $test_vendor->ID );
                break;

            case 'customer_approval':
                update_post_meta( $test_order->get_id(), '_vss_mockup_files', [ get_option( 'woocommerce_placeholder_image', 0 ) ] );
                $sent = self::send_customer_approval_request_email( $test_order->get_id(), 'mockup' );
                break;

            case 'production_confirmation':
                $sent = self::send_customer_production_confirmation_email( 
                    $test_order->get_id(), 
                    $test_order->get_order_number(), 
                    date( 'Y-m-d', strtotime( '+7 days' ) ) 
                );
                break;

            default:
                $message = '<p>' . sprintf( __( 'This is a test %s email from Vendor Order Manager.', 'vss' ), $email_type ) . '</p>';
                $sent = self::send_email( $recipient, $subject, $message );
                break;
        }

        // Clean up test order
        $test_order->delete( true );

        if ( $sent ) {
            wp_send_json_success( [ 'message' => sprintf( __( 'Test email sent to %s', 'vss' ), $recipient ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Failed to send test email. Please check your email settings.', 'vss' ) ] );
        }
    }
}