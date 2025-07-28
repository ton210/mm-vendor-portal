<?php
/**
 * VSS Setup Class
 *
 * Handles plugin setup, installation, and core functionality initialization
 *
 * @package VendorOrderManager
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_Setup {

    /**
     * Initialize setup hooks
     *
     * @return void
     */
    public static function init() {
        // Core setup
        add_action( 'init', [ self::class, 'register_post_statuses' ], 5 );
        add_action( 'init', [ self::class, 'add_vendor_role' ], 10 );
        add_action( 'init', [ self::class, 'add_rewrite_rules' ], 20 );
        
        // Product fields
        add_action( 'woocommerce_product_options_general_product_data', [ self::class, 'add_vendor_product_field' ] );
        add_action( 'woocommerce_process_product_meta', [ self::class, 'save_vendor_product_field' ] );
        
        // Order statuses
        add_filter( 'wc_order_statuses', [ self::class, 'add_custom_order_statuses' ] );
        add_filter( 'woocommerce_register_shop_order_post_statuses', [ self::class, 'register_custom_order_statuses' ] );
        
        // Approval handlers
        add_action( 'admin_post_nopriv_vss_handle_mockup_approval', [ self::class, 'handle_customer_approval_response' ] );
        add_action( 'admin_post_vss_handle_mockup_approval', [ self::class, 'handle_customer_approval_response' ] );
        add_action( 'admin_post_nopriv_vss_handle_production_file_approval', [ self::class, 'handle_customer_approval_response' ] );
        add_action( 'admin_post_vss_handle_production_file_approval', [ self::class, 'handle_customer_approval_response' ] );
        
        // Shortcodes
        add_shortcode( 'vss_vendor_portal', [ 'VSS_Vendor', 'render_vendor_portal_shortcode' ] );
        add_shortcode( 'vss_approval_handler', [ self::class, 'render_approval_handler_shortcode' ] );
        add_shortcode( 'vss_vendor_list', [ self::class, 'render_vendor_list_shortcode' ] );
        add_shortcode( 'vss_vendor_application', [ 'VSS_Vendor', 'render_vendor_application_shortcode' ] );
        
        // Cron events
        add_action( 'vss_daily_analytics', [ self::class, 'run_daily_analytics' ] );
        add_action( 'vss_hourly_order_sync', [ self::class, 'run_hourly_order_sync' ] );
        add_action( 'vss_weekly_cleanup', [ self::class, 'run_weekly_cleanup' ] );
        
        // AJAX handlers for approval
        add_action( 'wp_ajax_nopriv_vss_submit_disapproval_feedback', [ self::class, 'ajax_submit_disapproval_feedback' ] );
        add_action( 'wp_ajax_vss_submit_disapproval_feedback', [ self::class, 'ajax_submit_disapproval_feedback' ] );
    }

    /**
     * Register custom post statuses
     */
    public static function register_post_statuses() {
        // Shipped status
        register_post_status( 'wc-shipped', [
            'label' => _x( 'Shipped', 'Order status', 'vss' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'vss' ),
        ] );

        // In Production status
        register_post_status( 'wc-in-production', [
            'label' => _x( 'In Production', 'Order status', 'vss' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( 'In Production <span class="count">(%s)</span>', 'In Production <span class="count">(%s)</span>', 'vss' ),
        ] );

        // Awaiting Approval status
        register_post_status( 'wc-awaiting-approval', [
            'label' => _x( 'Awaiting Approval', 'Order status', 'vss' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( 'Awaiting Approval <span class="count">(%s)</span>', 'Awaiting Approval <span class="count">(%s)</span>', 'vss' ),
        ] );
    }

    /**
     * Add custom order statuses to WooCommerce
     *
     * @param array $order_statuses
     * @return array
     */
    public static function add_custom_order_statuses( $order_statuses ) {
        $new_statuses = [
            'wc-shipped' => _x( 'Shipped', 'Order status', 'vss' ),
            'wc-in-production' => _x( 'In Production', 'Order status', 'vss' ),
            'wc-awaiting-approval' => _x( 'Awaiting Approval', 'Order status', 'vss' ),
        ];

        // Insert after processing
        $pos = array_search( 'wc-processing', array_keys( $order_statuses ) );
        if ( $pos !== false ) {
            $order_statuses = array_slice( $order_statuses, 0, $pos + 1, true ) +
                             $new_statuses +
                             array_slice( $order_statuses, $pos + 1, null, true );
        } else {
            $order_statuses = array_merge( $order_statuses, $new_statuses );
        }

        return $order_statuses;
    }

    /**
     * Register custom order statuses for shop order post type
     *
     * @param array $order_statuses
     * @return array
     */
    public static function register_custom_order_statuses( $order_statuses ) {
        $order_statuses['wc-shipped'] = [
            'label' => _x( 'Shipped', 'Order status', 'vss' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'vss' ),
        ];

        $order_statuses['wc-in-production'] = [
            'label' => _x( 'In Production', 'Order status', 'vss' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( 'In Production <span class="count">(%s)</span>', 'In Production <span class="count">(%s)</span>', 'vss' ),
        ];

        $order_statuses['wc-awaiting-approval'] = [
            'label' => _x( 'Awaiting Approval', 'Order status', 'vss' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( 'Awaiting Approval <span class="count">(%s)</span>', 'Awaiting Approval <span class="count">(%s)</span>', 'vss' ),
        ];

        return $order_statuses;
    }

    /**
     * Add vendor role with appropriate capabilities
     */
    public static function add_vendor_role() {
        $role_id = 'vendor-mm';
        $role = get_role( $role_id );

        if ( ! $role ) {
            $role = add_role( $role_id, __( 'Vendor-MM', 'vss' ), [
                // Basic capabilities
                'read' => true,
                'vendor-mm' => true,
                
                // Dashboard access
                'read_admin_dashboard' => true,
                
                // File management
                'upload_files' => true,
                'edit_files' => true,
                
                // Order capabilities
                'view_vss_orders' => true,
                'edit_vss_orders' => true,
                'view_vss_reports' => true,
                
                // Product viewing
                'read_product' => true,
                'read_private_products' => true,
            ] );
        } else {
            // Update existing role with new capabilities
            $capabilities = [
                'upload_files' => true,
                'edit_files' => true,
                'view_vss_orders' => true,
                'edit_vss_orders' => true,
                'view_vss_reports' => true,
                'read_product' => true,
                'read_private_products' => true,
            ];

            foreach ( $capabilities as $cap => $grant ) {
                if ( ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap, $grant );
                }
            }
        }

        // Also add capabilities to administrators
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_caps = [
                'manage_vss_vendors' => true,
                'view_vss_orders' => true,
                'edit_vss_orders' => true,
                'delete_vss_orders' => true,
                'view_vss_reports' => true,
                'manage_vss_settings' => true,
            ];

            foreach ( $admin_caps as $cap => $grant ) {
                if ( ! $admin_role->has_cap( $cap ) ) {
                    $admin_role->add_cap( $cap, $grant );
                }
            }
        }
    }

    /**
     * Add custom rewrite rules
     */
    public static function add_rewrite_rules() {
        // Vendor portal rules
        add_rewrite_rule(
            '^vendor-portal/order/([0-9]+)/?$',
            'index.php?pagename=vendor-portal&vss_action=view_order&order_id=$matches[1]',
            'top'
        );

        // Approval handler rules
        add_rewrite_rule(
            '^order-approval/([a-zA-Z0-9]+)/?$',
            'index.php?pagename=order-approval&approval_token=$matches[1]',
            'top'
        );

        // API endpoints
        add_rewrite_rule(
            '^vss-api/v1/([a-zA-Z0-9-]+)/?$',
            'index.php?vss_api=1&vss_endpoint=$matches[1]',
            'top'
        );
    }

    /**
     * Add vendor selection field to product data
     */
    public static function add_vendor_product_field() {
        global $post;

        $vendors = get_users( [
            'role' => 'vendor-mm',
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => [ 'ID', 'display_name', 'user_email' ],
        ] );

        $options = [ '' => __( '— No Default Vendor —', 'vss' ) ];
        foreach ( $vendors as $vendor ) {
            $options[ $vendor->ID ] = sprintf(
                '%s (%s)',
                $vendor->display_name,
                $vendor->user_email
            );
        }

        echo '<div class="options_group vss-vendor-options">';
        
        woocommerce_wp_select( [
            'id' => '_vss_vendor_user_id',
            'label' => __( 'Default Vendor', 'vss' ),
            'description' => __( 'Assign a default vendor for this product. Can be overridden per order.', 'vss' ),
            'desc_tip' => true,
            'options' => $options,
            'value' => get_post_meta( $post->ID, '_vss_vendor_user_id', true ),
            'wrapper_class' => 'form-row form-row-full',
        ] );

        // Additional vendor settings
        woocommerce_wp_checkbox( [
            'id' => '_vss_auto_assign_vendor',
            'label' => __( 'Auto-assign to vendor', 'vss' ),
            'description' => __( 'Automatically assign orders containing this product to the default vendor.', 'vss' ),
            'desc_tip' => true,
            'value' => get_post_meta( $post->ID, '_vss_auto_assign_vendor', true ),
        ] );

        woocommerce_wp_text_input( [
            'id' => '_vss_vendor_sku',
            'label' => __( 'Vendor SKU', 'vss' ),
            'description' => __( 'SKU used by the vendor for this product.', 'vss' ),
            'desc_tip' => true,
            'value' => get_post_meta( $post->ID, '_vss_vendor_sku', true ),
        ] );

        woocommerce_wp_text_input( [
            'id' => '_vss_production_time',
            'label' => __( 'Production Time (days)', 'vss' ),
            'description' => __( 'Expected production time in days for this product.', 'vss' ),
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => [
                'min' => '0',
                'step' => '1',
            ],
            'value' => get_post_meta( $post->ID, '_vss_production_time', true ),
        ] );

        echo '</div>';
    }

    /**
     * Save vendor product fields
     *
     * @param int $post_id
     */
    public static function save_vendor_product_field( $post_id ) {
        // Vendor ID
        $vendor_id = isset( $_POST['_vss_vendor_user_id'] ) ? intval( $_POST['_vss_vendor_user_id'] ) : '';
        update_post_meta( $post_id, '_vss_vendor_user_id', $vendor_id );

        // Auto-assign
        $auto_assign = isset( $_POST['_vss_auto_assign_vendor'] ) ? 'yes' : 'no';
        update_post_meta( $post_id, '_vss_auto_assign_vendor', $auto_assign );

        // Vendor SKU
        $vendor_sku = isset( $_POST['_vss_vendor_sku'] ) ? sanitize_text_field( $_POST['_vss_vendor_sku'] ) : '';
        update_post_meta( $post_id, '_vss_vendor_sku', $vendor_sku );

        // Production time
        $production_time = isset( $_POST['_vss_production_time'] ) ? intval( $_POST['_vss_production_time'] ) : '';
        update_post_meta( $post_id, '_vss_production_time', $production_time );

        // Log activity
        if ( $vendor_id ) {
            Vendor_Order_Manager::log_activity( 'product_vendor_assigned', [
                'product_id' => $post_id,
                'vendor_id' => $vendor_id,
            ] );
        }
    }

    /**
     * Handle customer approval response
     */
    public static function handle_customer_approval_response() {
        $order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
        $approval_status = isset( $_GET['approval_status'] ) ? sanitize_key( $_GET['approval_status'] ) : '';
        $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( $_GET['_wpnonce'] ) : '';
        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
        $token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';

        // Validate request
        if ( ! $order_id || ! in_array( $approval_status, [ 'approved', 'disapproved' ], true ) || empty( $action ) ) {
            wp_die( __( 'Invalid approval request.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 400 ] );
        }

        // Determine type from action
        $type = '';
        if ( strpos( $action, 'mockup' ) !== false ) {
            $type = 'mockup';
        } elseif ( strpos( $action, 'production_file' ) !== false ) {
            $type = 'production_file';
        } else {
            wp_die( __( 'Invalid approval type.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 400 ] );
        }

        // Verify nonce
        if ( ! wp_verify_nonce( $nonce, "vss_{$approval_status}_{$type}_{$order_id}" ) ) {
            wp_die( __( 'Security check failed. This link may have expired.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 403 ] );
        }

        // Verify order exists
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( __( 'Order not found.', 'vss' ), __( 'Error', 'vss' ), [ 'response' => 404 ] );
        }

        // Check current status
        $current_status = get_post_meta( $order_id, "_vss_{$type}_status", true );
        if ( $current_status !== 'pending_approval' ) {
            $message = $current_status === $approval_status 
                ? sprintf( __( 'This %s has already been %s.', 'vss' ), $type, $approval_status )
                : sprintf( __( 'This %s is not pending approval.', 'vss' ), $type );
            
            wp_die( $message, __( 'Notice', 'vss' ), [ 'response' => 200 ] );
        }

        // Update status
        update_post_meta( $order_id, "_vss_{$type}_status", $approval_status );
        update_post_meta( $order_id, "_vss_{$type}_responded_at", time() );

        // Log activity
        Vendor_Order_Manager::log_activity( "{$type}_{$approval_status}", [
            'order_id' => $order_id,
            'customer_email' => $order->get_billing_email(),
        ] );

        // Handle based on status
        if ( $approval_status === 'approved' ) {
            self::handle_approval_approved( $order, $type );
        } else {
            self::handle_approval_disapproved( $order, $type );
        }
    }

    /**
     * Handle approved status
     *
     * @param WC_Order $order
     * @param string $type
     */
    private static function handle_approval_approved( $order, $type ) {
        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        
        // Add order note
        $order->add_order_note( sprintf( __( '%s approved by customer via email link.', 'vss' ), $type_label ) );
        
        // Send vendor notification
        VSS_Emails::send_vendor_approval_confirmed_email( $order->get_id(), $type );
        
        // Update order status if needed
        if ( $type === 'production_file' && $order->has_status( 'awaiting-approval' ) ) {
            $order->update_status( 'in-production', __( 'Production files approved, order now in production.', 'vss' ) );
        }
        
        // Redirect to success page
        $success_url = add_query_arg( [
            'vss_approval' => 'success',
            'type' => $type,
            'status' => 'approved',
        ], home_url( '/order-approval/' ) );
        
        wp_redirect( $success_url );
        exit;
    }

    /**
     * Handle disapproved status
     *
     * @param WC_Order $order
     * @param string $type
     */
    private static function handle_approval_disapproved( $order, $type ) {
        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        
        // Add order note
        $order->add_order_note( sprintf( __( '%s disapproved by customer via email link. Admin notified.', 'vss' ), $type_label ) );
        
        // Send admin notification
        VSS_Emails::send_admin_approval_disapproved_email( $order->get_id(), $type );
        
        // Redirect to feedback page
        $feedback_url = add_query_arg( [
            'vss_approval' => 'feedback',
            'type' => $type,
            'order_id' => $order->get_id(),
            'token' => wp_create_nonce( "vss_feedback_{$type}_{$order->get_id()}" ),
        ], home_url( '/order-approval/' ) );
        
        wp_redirect( $feedback_url );
        exit;
    }

    /**
     * Render approval handler shortcode
     *
     * @param array $atts
     * @return string
     */
    public static function render_approval_handler_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'class' => 'vss-approval-handler',
        ], $atts, 'vss_approval_handler' );

        ob_start();
        
        // Check if it's a success or feedback page
        if ( isset( $_GET['vss_approval'] ) ) {
            if ( $_GET['vss_approval'] === 'success' ) {
                self::render_approval_success_page();
            } elseif ( $_GET['vss_approval'] === 'feedback' ) {
                self::render_approval_feedback_page();
            }
        } else {
            echo '<p>' . __( 'Invalid approval request.', 'vss' ) . '</p>';
        }
        
        return ob_get_clean();
    }

    /**
     * Render approval success page
     */
    private static function render_approval_success_page() {
        $type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        ?>
        <div class="vss-approval-success">
            <div class="success-icon">✅</div>
            <h2><?php esc_html_e( 'Thank You!', 'vss' ); ?></h2>
            <p><?php printf( esc_html__( 'You have successfully approved the %s.', 'vss' ), esc_html( $type_label ) ); ?></p>
            <p><?php esc_html_e( 'We will proceed with your order immediately.', 'vss' ); ?></p>
            <p><?php esc_html_e( 'If you have any questions, please contact us at', 'vss' ); ?> 
               <a href="mailto:help@munchmakers.com">help@munchmakers.com</a></p>
        </div>
        <?php
    }

    /**
     * Render approval feedback page
     */
    private static function render_approval_feedback_page() {
        $type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : '';
        $order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
        $token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        
        if ( ! wp_verify_nonce( $token, "vss_feedback_{$type}_{$order_id}" ) ) {
            wp_die( __( 'Invalid feedback request.', 'vss' ) );
        }
        
        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        ?>
        <div class="vss-approval-feedback">
            <div class="feedback-icon">❌</div>
            <h2><?php esc_html_e( 'Changes Requested', 'vss' ); ?></h2>
            <p><?php printf( esc_html__( 'You have requested changes to the %s.', 'vss' ), esc_html( $type_label ) ); ?></p>
            
            <form id="vss-feedback-form" method="post">
                <h3><?php esc_html_e( 'Please tell us what changes you need:', 'vss' ); ?></h3>
                <textarea name="feedback" rows="6" required placeholder="<?php esc_attr_e( 'Describe the changes you would like...', 'vss' ); ?>"></textarea>
                
                <?php wp_nonce_field( 'vss_submit_feedback', 'vss_feedback_nonce' ); ?>
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
                <input type="hidden" name="type" value="<?php echo esc_attr( $type ); ?>">
                
                <button type="submit" class="button"><?php esc_html_e( 'Submit Feedback', 'vss' ); ?></button>
            </form>
            
            <p><?php esc_html_e( 'Our team will review your feedback and make the necessary changes.', 'vss' ); ?></p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#vss-feedback-form').on('submit', function(e) {
                e.preventDefault();
                
                var $form = $(this);
                var $button = $form.find('button[type="submit"]');
                
                $button.prop('disabled', true).text('<?php esc_js( __( 'Submitting...', 'vss' ) ); ?>');
                
                $.ajax({
                    url: '<?php echo admin_url( 'admin-ajax.php' ); ?>',
                    type: 'POST',
                    data: $form.serialize() + '&action=vss_submit_disapproval_feedback',
                    success: function(response) {
                        if (response.success) {
                            $form.replaceWith('<div class="success-message"><p>' + response.data.message + '</p></div>');
                        } else {
                            alert(response.data.message || '<?php esc_js( __( 'An error occurred. Please try again.', 'vss' ) ); ?>');
                            $button.prop('disabled', false).text('<?php esc_js( __( 'Submit Feedback', 'vss' ) ); ?>');
                        }
                    },
                    error: function() {
                        alert('<?php esc_js( __( 'Connection error. Please try again.', 'vss' ) ); ?>');
                        $button.prop('disabled', false).text('<?php esc_js( __( 'Submit Feedback', 'vss' ) ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for disapproval feedback
     */
    public static function ajax_submit_disapproval_feedback() {
        // Verify nonce
        if ( ! wp_verify_nonce( $_POST['vss_feedback_nonce'] ?? '', 'vss_submit_feedback' ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'vss' ) ] );
        }

        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
        $feedback = isset( $_POST['feedback'] ) ? sanitize_textarea_field( $_POST['feedback'] ) : '';

        if ( ! $order_id || ! $type || ! $feedback ) {
            wp_send_json_error( [ 'message' => __( 'Missing required information.', 'vss' ) ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Order not found.', 'vss' ) ] );
        }

        // Save feedback
        update_post_meta( $order_id, "_vss_{$type}_customer_notes", $feedback );

        // Add order note
        $type_label = ( $type === 'mockup' ) ? __( 'Mockup', 'vss' ) : __( 'Production File', 'vss' );
        $order->add_order_note( sprintf( __( 'Customer provided feedback for %s: %s', 'vss' ), $type_label, $feedback ) );

        // Send notifications
        VSS_Emails::send_admin_approval_disapproved_email( $order_id, $type, $feedback );
        VSS_Emails::send_vendor_disapproval_notification( $order_id, $type, $feedback );

        wp_send_json_success( [
            'message' => __( 'Thank you! Your feedback has been submitted. Our team will review it and contact you soon.', 'vss' ),
        ] );
    }

    /**
     * Render vendor list shortcode
     *
     * @param array $atts
     * @return string
     */
    public static function render_vendor_list_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'columns' => 3,
            'orderby' => 'display_name',
            'order' => 'ASC',
            'show_products' => 'yes',
        ], $atts, 'vss_vendor_list' );

        $vendors = get_users( [
            'role' => 'vendor-mm',
            'orderby' => $atts['orderby'],
            'order' => $atts['order'],
        ] );

        if ( empty( $vendors ) ) {
            return '<p>' . __( 'No vendors found.', 'vss' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="vss-vendor-list columns-<?php echo esc_attr( $atts['columns'] ); ?>">
            <?php foreach ( $vendors as $vendor ) : ?>
                <div class="vss-vendor-item">
                    <h3><?php echo esc_html( $vendor->display_name ); ?></h3>
                    <?php if ( $vendor->description ) : ?>
                        <p><?php echo esc_html( $vendor->description ); ?></p>
                    <?php endif; ?>
                    
                    <?php if ( $atts['show_products'] === 'yes' ) : ?>
                        <?php
                        $products = wc_get_products( [
                            'meta_key' => '_vss_vendor_user_id',
                            'meta_value' => $vendor->ID,
                            'limit' => 5,
                        ] );
                        
                        if ( ! empty( $products ) ) : ?>
                            <h4><?php esc_html_e( 'Products:', 'vss' ); ?></h4>
                            <ul>
                                <?php foreach ( $products as $product ) : ?>
                                    <li>
                                        <a href="<?php echo esc_url( $product->get_permalink() ); ?>">
                                            <?php echo esc_html( $product->get_name() ); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Run daily analytics
     */
    public static function run_daily_analytics() {
        // Implemented in VSS_Analytics class if available
        do_action( 'vss_run_daily_analytics' );
    }

    /**
     * Run hourly order sync
     */
    public static function run_hourly_order_sync() {
        // Check for stale orders
        $stale_orders = wc_get_orders( [
            'status' => 'processing',
            'date_modified' => '<' . date( 'Y-m-d H:i:s', strtotime( '-3 days' ) ),
            'meta_key' => '_vss_vendor_user_id',
            'meta_compare' => 'EXISTS',
            'limit' => -1,
        ] );

        foreach ( $stale_orders as $order ) {
            // Send reminder to vendor
            $vendor_id = get_post_meta( $order->get_id(), '_vss_vendor_user_id', true );
            if ( $vendor_id ) {
                VSS_Emails::send_vendor_reminder_email( $order->get_id(), $vendor_id );
            }
        }

        // Sync with Zakeke
        do_action( 'vss_sync_zakeke_orders' );
    }

    /**
     * Run weekly cleanup
     */
    public static function run_weekly_cleanup() {
        global $wpdb;

        // Clean old activity logs (keep 90 days)
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}vss_activity_log WHERE created_at < %s",
            date( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
        ) );

        // Clean expired transients
        delete_expired_transients();

        // Clean orphaned meta
        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.ID IS NULL AND pm.meta_key LIKE '_vss_%'"
        );

        do_action( 'vss_weekly_cleanup' );
    }
}