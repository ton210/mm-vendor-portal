<?php
// includes/class-vss-setup.php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class VSS_Setup {
    public static function init() {
        add_action( 'init', [ self::class, 'add_vendor_role' ] );
        add_action( 'woocommerce_product_options_general_product_data', [ self::class, 'add_vendor_product_field' ] );
        add_action( 'woocommerce_process_product_meta', [ self::class, 'save_vendor_product_field' ] );
        add_filter( 'wc_order_statuses', function( $order_statuses ) {
            if ( ! isset( $order_statuses['wc-shipped'] ) ) {
                $order_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'vss' );
            }
            return $order_statuses;
        });
        // Use VSS_PLUGIN_PATH constant which should be defined in the main plugin file.
        register_activation_hook( VSS_PLUGIN_PATH . 'wc-vendor-order-manager.php', [self::class, 'schedule_cron_jobs_activation']); // Renamed for clarity
        register_deactivation_hook( VSS_PLUGIN_PATH . 'wc-vendor-order-manager.php', [self::class, 'unschedule_cron_jobs_deactivation']); // Renamed for clarity

        // Approval link handlers
        add_action('admin_post_nopriv_vss_handle_mockup_approval', [self::class, 'handle_customer_approval_response']);
        add_action('admin_post_vss_handle_mockup_approval', [self::class, 'handle_customer_approval_response']);
        add_action('admin_post_nopriv_vss_handle_production_file_approval', [self::class, 'handle_customer_approval_response']);
        add_action('admin_post_vss_handle_production_file_approval', [self::class, 'handle_customer_approval_response']);

        // Action hook to display messages to customer after approval/disapproval redirect
        add_action('wp_footer', [self::class, 'display_approval_response_messages']);
    }

    public static function add_vendor_role() {
        $role_id = 'vendor-mm';
        // Only remove and re-add if capabilities need updating. Otherwise, just check if it exists.
        if (!get_role($role_id)) {
            add_role( $role_id, __( 'Vendor-MM', 'vss' ), [
                'read' => true,
                'vendor-mm' => true, // Custom capability
                'read_admin_dashboard' => true, // Allows access to parts of admin if needed
                'upload_files' => true, // Important for uploading mockups/production files
            ]);
        } else {
            // Ensure 'upload_files' capability exists for the role if it was added before this cap.
            $role = get_role($role_id);
            if ($role && !$role->has_cap('upload_files')) {
                $role->add_cap('upload_files');
            }
        }
    }

    public static function add_vendor_product_field() {
        global $post;
        $vendors = get_users( [ 'role' => 'vendor-mm', 'fields' => [ 'ID', 'display_name' ] ] );
        $options = [ '' => __( '— No Vendor —', 'vss' ) ];
        foreach ( $vendors as $vendor ) $options[ $vendor->ID ] = $vendor->display_name;
        echo '<div class="options_group">';
        woocommerce_wp_select( [
            'id' => '_vss_vendor_user_id',
            'label' => __( 'Default Vendor', 'vss' ),
            'description' => __( 'Assign a default vendor. This can be overridden per order.', 'vss' ),
            'desc_tip' => true,
            'options' => $options,
            'value' => get_post_meta( $post->ID, '_vss_vendor_user_id', true ),
        ] );
        echo '</div>';
    }

    public static function save_vendor_product_field( $post_id ) {
        $vendor_id = isset( $_POST['_vss_vendor_user_id'] ) ? intval( $_POST['_vss_vendor_user_id'] ) : '';
        update_post_meta( $post_id, '_vss_vendor_user_id', $vendor_id );
    }

    public static function schedule_cron_jobs_activation() { // Renamed
        if ( ! wp_next_scheduled( 'vss_daily_generic_scheduler' ) ) {
            // wp_schedule_event( time(), 'daily', 'vss_daily_generic_scheduler' ); // Currently commented out in original
        }
    }

    public static function unschedule_cron_jobs_deactivation() { // Renamed
        wp_clear_scheduled_hook( 'vss_daily_generic_scheduler' );
        // Clear specific order fetch hooks if any are still lingering (complex to find all args)
        // The original code had a loop that might not be effective for args.
        // It's generally better to rely on meta flags to prevent re-runs if specific args are unknown.
    }

    public static function handle_customer_approval_response() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        // Changed 'status' to 'approval_status' to avoid potential conflicts with WC order status
        $new_approval_status = isset($_GET['approval_status']) ? sanitize_key($_GET['approval_status']) : '';
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';

        if (!$order_id || !in_array($new_approval_status, ['approved', 'disapproved']) || empty($action)) {
            wp_redirect(add_query_arg('vss_approval_msg', 'invalid_request', home_url('/')));
            exit;
        }

        $type = str_contains($action, 'mockup') ? 'mockup' : (str_contains($action, 'production_file') ? 'production_file' : null);
        if (!$type) {
            wp_redirect(add_query_arg('vss_approval_msg', 'invalid_type', home_url('/')));
            exit;
        }

        // Use the $new_approval_status in the nonce check string
        if (!wp_verify_nonce($nonce, "vss_{$new_approval_status}_{$type}_{$order_id}")) {
            wp_redirect(add_query_arg('vss_approval_msg', 'nonce_failed', home_url('/')));
            exit;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_redirect(add_query_arg('vss_approval_msg', 'order_not_found', home_url('/')));
            exit;
        }

        $current_db_status = get_post_meta($order_id, "_vss_{$type}_status", true);
        if ($current_db_status !== 'pending_approval') {
            $msg_key = ($current_db_status === $new_approval_status) ? 'already_' . $new_approval_status : 'not_pending';
            wp_redirect(add_query_arg(['vss_approval_msg' => $msg_key, 'type' => $type], home_url('/')));
            exit;
        }

        update_post_meta($order_id, "_vss_{$type}_status", $new_approval_status);
        update_post_meta($order_id, "_vss_{$type}_responded_at", time());

        $type_label_uc = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        $customer_notes_key = "_vss_{$type}_customer_notes";
        $customer_provided_notes = ''; // This would be populated if a form was submitted for disapproval notes

        // Clear customer notes if re-approving or if disapproval doesn't include new notes here
        delete_post_meta($order_id, $customer_notes_key);

        if ($new_approval_status === 'approved') {
            $order->add_order_note(sprintf(__('%s approved by customer via email link.', 'vss'), $type_label_uc));
            VSS_Emails::send_vendor_approval_confirmed_email($order_id, $type);
            wp_redirect(add_query_arg(['vss_approval_msg' => 'approved', 'type' => $type_label_uc], home_url('/')));
            exit;

        } elseif ($new_approval_status === 'disapproved') {
            // If you want customers to submit notes upon disapproval, the disapproval link
            // should ideally lead to a page with a form. That form would then POST to this
            // admin-post.php action, including the notes.
            // For this simplified direct link version, admin is notified to follow up.
            // Example: $customer_provided_notes = isset($_POST['customer_notes']) ? sanitize_textarea_field($_POST['customer_notes']) : '';
            // if($customer_provided_notes) update_post_meta($order_id, $customer_notes_key, $customer_provided_notes);

            $order->add_order_note(sprintf(__('%s disapproved by customer via email link. Admin notified to collect feedback.', 'vss'), $type_label_uc));
            VSS_Emails::send_admin_approval_disapproved_email($order_id, $type, $customer_provided_notes);
            wp_redirect(add_query_arg(['vss_approval_msg' => 'disapproved', 'type' => $type_label_uc], home_url('/')));
            exit;
        }
    }

    public static function display_approval_response_messages(){
        if (isset($_GET['vss_approval_msg'])) {
            $message_key = sanitize_key($_GET['vss_approval_msg']);
            $type_label = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : '';
            $message = '';

            switch ($message_key) {
                case 'approved':
                    $message = sprintf(__('%s successfully approved. Thank you!', 'vss'), $type_label);
                    break;
                case 'disapproved':
                    $message = sprintf(__('%s marked as disapproved. Our team will be in touch if further details are needed. Thank you.', 'vss'), $type_label);
                    break;
                case 'invalid_request':
                    $message = __('The approval link was invalid. Please contact support.', 'vss');
                    break;
                case 'invalid_type':
                    $message = __('The approval type in the link was invalid. Please contact support.', 'vss');
                    break;
                case 'nonce_failed':
                    $message = __('The approval link has expired or is invalid. Please contact support or await a new link if revisions are made.', 'vss');
                    break;
                case 'order_not_found':
                    $message = __('The order associated with this approval link could not be found. Please contact support.', 'vss');
                    break;
                case 'not_pending':
                    $message = sprintf(__('This %s approval request is not currently pending or has already been processed. If you need to make changes, please contact support.', 'vss'), $type_label);
                    break;
                case 'already_approved':
                     $message = sprintf(__('This %s has already been approved. No further action is needed from this link.', 'vss'), $type_label);
                    break;
                case 'already_disapproved':
                     $message = sprintf(__('This %s has already been marked as disapproved. Our team will follow up.', 'vss'), $type_label);
                    break;
            }

            if ($message) {
                // Simple JS alert for demonstration. You'd want a more integrated notification.
                echo "<script type='text/javascript'>alert('" . esc_js($message) . "');</script>";
                // Or, integrate with a theme's notification system or a dedicated page.
            }
        }
    }
}