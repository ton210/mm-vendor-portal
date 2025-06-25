<?php
/**
 * Plugin Name:       Vendor Order Manager: Zakeke Edition Complete
 * Description:       Complete vendor order management system with all features in one file
 * Version:           6.2.0
 * Author:            Enhanced Version
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VSS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'VSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSS_VERSION', '6.2.0' );

// ============================================================================
// DEFINE ALL CLASSES FIRST BEFORE THE MAIN PLUGIN CLASS
// ============================================================================

// VSS_Setup Class
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
        register_activation_hook( VSS_PLUGIN_PATH . 'wc-vendor-order-manager.php', [self::class, 'schedule_cron_jobs_activation']);
        register_deactivation_hook( VSS_PLUGIN_PATH . 'wc-vendor-order-manager.php', [self::class, 'unschedule_cron_jobs_deactivation']);

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
        if (!get_role($role_id)) {
            add_role( $role_id, __( 'Vendor-MM', 'vss' ), [
                'read' => true,
                'vendor-mm' => true,
                'read_admin_dashboard' => true,
                'upload_files' => true,
            ]);
        } else {
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

    public static function schedule_cron_jobs_activation() {
        // Currently no cron jobs scheduled on activation
    }

    public static function unschedule_cron_jobs_deactivation() {
        wp_clear_scheduled_hook( 'vss_daily_generic_scheduler' );
    }

    public static function handle_customer_approval_response() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
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
        $customer_provided_notes = '';

        delete_post_meta($order_id, $customer_notes_key);

        if ($new_approval_status === 'approved') {
            $order->add_order_note(sprintf(__('%s approved by customer via email link.', 'vss'), $type_label_uc));
            VSS_Emails::send_vendor_approval_confirmed_email($order_id, $type);
            wp_redirect(add_query_arg(['vss_approval_msg' => 'approved', 'type' => $type_label_uc], home_url('/')));
            exit;

        } elseif ($new_approval_status === 'disapproved') {
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
                echo "<script type='text/javascript'>alert('" . esc_js($message) . "');</script>";
            }
        }
    }
}

// VSS_Zakeke_API Class
class VSS_Zakeke_API {
    const TOKEN_URL = "https://api.zakeke.com/token";
    const API_BASE_URL = "https://api.zakeke.com";
    const TOKEN_TRANSIENT_KEY = 'vss_zakeke_access_token';

    public static function init() {
    }

    private static function get_credentials() {
        $options = get_option('vss_zakeke_settings');
        return [
            'client_id' => isset($options['client_id']) ? trim($options['client_id']) : '',
            'client_secret' => isset($options['client_secret']) ? trim($options['client_secret']) : '',
        ];
    }

    public static function get_access_token() {
        $cached_token = get_transient(self::TOKEN_TRANSIENT_KEY);
        if ($cached_token) {
            return $cached_token;
        }

        $creds = self::get_credentials();
        if (empty($creds['client_id']) || empty($creds['client_secret'])) {
            error_log('VSS Zakeke API: Client ID or Secret not configured in settings.');
            return null;
        }

        $payload = ['grant_type' => 'client_credentials', 'access_type' => 'S2S'];
        $auth_header = 'Basic ' . base64_encode($creds['client_id'] . ':' . $creds['client_secret']);

        $response = wp_remote_post(self::TOKEN_URL, [
            'method'    => 'POST',
            'headers'   => [
                'Authorization' => $auth_header,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            'body'      => $payload,
            'timeout'   => 20,
        ]);

        if (is_wp_error($response)) {
            error_log('VSS Zakeke API Token Error (WP Error): ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code !== 200 || empty($data['access_token'])) { 
            error_log('VSS Zakeke API Token Error (' . $http_code . '): ' . $body);
            return null;
        }

        $token = $data['access_token'];
        $expires_in = isset($data['expires_in']) ? intval($data['expires_in']) - 120 : 35880; 
        set_transient(self::TOKEN_TRANSIENT_KEY, $token, $expires_in);
        return $token;
    }
    
    public static function get_zakeke_order_details_by_wc_order_id($wc_order_id) {
        $token = self::get_access_token();
        if (!$token) {
            error_log('VSS Zakeke API: Failed to get access token for fetching order details for WC Order ' . $wc_order_id);
            return null;
        }
        $api_url = self::API_BASE_URL . "/v2/order/" . $wc_order_id; 

        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('VSS Zakeke API Order Details Error (WP Error) for WC Order ' . $wc_order_id . ': ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code === 404) {
            return null; 
        } elseif ($http_code !== 200) {
            error_log('VSS Zakeke API Order Details Error (' . $http_code . ') for WC Order ' . $wc_order_id . ': ' . $body);
            return null;
        }
        return $data; 
    }
}

// VSS_Emails Class
class VSS_Emails {
    
    /**
     * Initialize email hooks.
     */
    public static function init() {
        add_action('vss_order_assigned_to_vendor', [self::class, 'send_new_assignment_email'], 10, 2);
        add_action('woocommerce_order_status_changed', [self::class, 'send_status_change_emails'], 10, 4);
    }

    /**
     * Send email using WooCommerce mailer
     */
    private static function send_email($to, $subject, $message, $reply_to_email = null, $reply_to_name = null, $attachments = []) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $site_title = get_bloginfo('name');
        $admin_email_address = get_option('admin_email');
        $from_email = apply_filters('vss_email_from_address', $admin_email_address);
        $from_name = apply_filters('vss_email_from_name', $site_title);

        $headers[] = "From: {$from_name} <{$from_email}>";
        if ($reply_to_email) {
            $reply_to_header = "Reply-To: ";
            if ($reply_to_name) { 
                $reply_to_header .= "{$reply_to_name} <{$reply_to_email}>"; 
            } else { 
                $reply_to_header .= $reply_to_email; 
            }
            $headers[] = $reply_to_header;
        }

        $mailer = WC()->mailer();
        $wrapped_message = $mailer->wrap_message($subject, $message);
        
        $email_styler = new WC_Email();
        $styled_html_message = $email_styler->style_inline($wrapped_message);
        
        if (is_wp_error($styled_html_message) || false === $styled_html_message) {
            $styled_html_message = $wrapped_message;
        }

        return $mailer->send($to, $subject, $styled_html_message, $headers, $attachments);
    }

    /**
     * Send customer approval request email
     */
    public static function send_customer_approval_request_email($order_id, $type = 'mockup') {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $customer_email = $order->get_billing_email();
        if (!$customer_email) {
            $order->add_order_note(sprintf(__('Customer %s approval request email NOT sent: No billing email found.', 'vss'), $type));
            return;
        }

        $type_label_uc = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        $type_label_lc = strtolower($type_label_uc);
        $subject = sprintf(__('Action Required: Your MunchMakers Order %s - %s Approval', 'vss'), $order->get_order_number(), $type_label_uc);
        $customer_name = $order->get_billing_first_name() ? $order->get_billing_first_name() : __('Valued Customer', 'vss');

        $files_ids = get_post_meta($order_id, "_vss_{$type}_files", true);
        $files_ids = is_array($files_ids) ? $files_ids : [];
        $vendor_notes = get_post_meta($order_id, "_vss_{$type}_vendor_notes", true);

        // Generate secure approval URLs
        $approve_nonce = wp_create_nonce("vss_approve_{$type}_{$order_id}");
        $disapprove_nonce = wp_create_nonce("vss_disapprove_{$type}_{$order_id}");

        $approve_link = add_query_arg([
            'action' => "vss_handle_{$type}_approval",
            'order_id' => $order_id,
            'approval_status' => 'approved',
            '_wpnonce' => $approve_nonce,
            'email' => base64_encode($customer_email)
        ], admin_url('admin-post.php'));
        
        $disapprove_link = add_query_arg([
            'action' => "vss_handle_{$type}_approval",
            'order_id' => $order_id,
            'approval_status' => 'disapproved',
            '_wpnonce' => $disapprove_nonce,
            'email' => base64_encode($customer_email)
        ], admin_url('admin-post.php'));

        // Build email content
        $email_content = '<p>Hello ' . esc_html($customer_name) . ',</p>';
        $email_content .= '<p>Your vendor has submitted a ' . $type_label_lc . ' for order <strong>#' . esc_html($order->get_order_number()) . '</strong> for your approval.</p>';
        
        if ($vendor_notes) {
            $email_content .= '<p><strong>Notes from Vendor:</strong><br>' . nl2br(esc_html($vendor_notes)) . '</p>';
        }

        if (!empty($files_ids)) {
            $email_content .= '<p><strong>Files for Review:</strong></p>';
            foreach ($files_ids as $file_id) {
                $file_url = wp_get_attachment_url($file_id);
                $file_path = get_attached_file($file_id);
                $file_name = $file_path ? basename($file_path) : __('Attached File', 'vss');
                if ($file_url) {
                    $email_content .= '<p>- <a href="' . esc_url($file_url) . '">' . esc_html($file_name) . '</a></p>';
                }
            }
        }

        $email_content .= '<p style="margin-top: 30px;"><strong>Please click one of the buttons below:</strong></p>';
        $email_content .= '<p><a href="' . esc_url($approve_link) . '" style="background-color: #4CAF50; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin-right: 10px;">APPROVE</a>';
        $email_content .= '<a href="' . esc_url($disapprove_link) . '" style="background-color: #f44336; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">REQUEST CHANGES</a></p>';
        
        $email_content .= '<p style="margin-top: 30px; font-size: 12px;">If the buttons don\'t work, copy and paste one of these links:</p>';
        $email_content .= '<p style="font-size: 12px;">To Approve: ' . esc_url($approve_link) . '</p>';
        $email_content .= '<p style="font-size: 12px;">To Request Changes: ' . esc_url($disapprove_link) . '</p>';

        if (self::send_email($customer_email, $subject, $email_content, 'help@munchmakers.com', 'MunchMakers Support')) {
            $order->add_order_note(sprintf(__('%s approval request email sent to customer.', 'vss'), $type_label_uc));
            update_post_meta($order_id, "_vss_{$type}_email_sent_at", time());
        } else {
            $order->add_order_note(sprintf(__('Failed to send %s approval request email to customer.', 'vss'), $type_label_uc));
        }
    }

    /**
     * Send new assignment email to vendor
     */
    public static function send_new_assignment_email($order_id, $vendor_id) {
        $order = wc_get_order($order_id);
        $vendor_user = get_userdata($vendor_id);

        if (!$order || !$vendor_user || empty($vendor_user->user_email)) {
            return;
        }

        $subject = sprintf(__('New Order #%s Assigned to You - %s', 'vss'), $order->get_order_number(), get_bloginfo('name'));
        $vendor_portal_link = home_url('/vendor-portal/');
        $order_link = esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], $vendor_portal_link));

        $message = '<p>Hello ' . esc_html($vendor_user->display_name) . ',</p>';
        $message .= '<p>A new order <strong>#' . esc_html($order->get_order_number()) . '</strong> has been assigned to you.</p>';
        $message .= '<p><a href="' . $order_link . '" style="background-color: #2271b1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">View Order Details</a></p>';
        $message .= '<p><strong>Order Items:</strong></p>';
        $message .= '<ul>';
        foreach ($order->get_items() as $item) {
            $message .= '<li>' . esc_html($item->get_name()) . ' x ' . $item->get_quantity() . '</li>';
        }
        $message .= '</ul>';
        $message .= '<p>Thank you,<br>' . esc_html(get_bloginfo('name')) . '</p>';

        self::send_email($vendor_user->user_email, $subject, $message);
    }

    /**
     * Send email notifications based on order status changes
     */
    public static function send_status_change_emails($order_id, $old_status, $new_status, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
            if (!$order) return;
        }

        $vendor_id = get_post_meta($order_id, '_vss_vendor_user_id', true);
        if (!$vendor_id) return;

        $vendor_user = get_userdata($vendor_id);
        if (!$vendor_user || empty($vendor_user->user_email)) return;

        if ($new_status === 'cancelled' && $old_status !== 'cancelled') {
            $subject = sprintf(__('Order #%s Cancelled - %s', 'vss'), $order->get_order_number(), get_bloginfo('name'));
            $message = '<p>Hello ' . esc_html($vendor_user->display_name) . ',</p>';
            $message .= '<p><strong>Important Notice:</strong> Order #' . esc_html($order->get_order_number()) . ' has been cancelled.</p>';
            $message .= '<p>Please halt any production work on this order immediately.</p>';
            $message .= '<p><a href="' . esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], home_url('/vendor-portal/'))) . '">View Order Details</a></p>';
            
            self::send_email($vendor_user->user_email, $subject, $message);
        }
    }

    /**
     * Send customer production confirmation email
     */
    public static function send_customer_production_confirmation_email($order_id, $order_number_display, $estimated_ship_date) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $email_sent_flag = '_vss_customer_production_email_sent_at';
        $date_at_last_email_flag = '_vss_estimated_ship_date_at_last_email';

        $last_sent_timestamp = get_post_meta($order_id, $email_sent_flag, true);
        $date_at_last_email = get_post_meta($order_id, $date_at_last_email_flag, true);

        if ($last_sent_timestamp && $date_at_last_email === $estimated_ship_date) {
            return;
        }

        $customer_email = $order->get_billing_email();
        if (!$customer_email) {
            $order->add_order_note(__('Customer production confirmation email NOT sent: No billing email found.', 'vss'));
            return;
        }

        $formatted_ship_date = date_i18n(wc_date_format(), strtotime($estimated_ship_date));
        $subject = sprintf(__('Your MunchMakers Order %s is in Production!', 'vss'), esc_html($order_number_display));
        $customer_name = $order->get_billing_first_name() ?: __('Valued Customer', 'vss');

        $message = '<p>Hello ' . esc_html($customer_name) . ',</p>';
        $message .= '<p>Great news! Your order <strong>' . esc_html($order_number_display) . '</strong> has begun production and is estimated to ship by <strong>' . esc_html($formatted_ship_date) . '</strong>.</p>';
        $message .= '<p>We\'ll send you tracking information once your order ships.</p>';
        $message .= '<p>Thank you for your patience!</p>';
        $message .= '<p>Best regards,<br>The MunchMakers Team</p>';

        if (self::send_email($customer_email, $subject, $message, 'help@munchmakers.com', 'MunchMakers Support')) {
            update_post_meta($order_id, $email_sent_flag, time());
            update_post_meta($order_id, $date_at_last_email_flag, $estimated_ship_date);
        } else {
            $order->add_order_note(__('Failed to send customer production confirmation email.', 'vss'));
        }
    }

    /**
     * Send vendor approval confirmation email
     */
    public static function send_vendor_approval_confirmed_email($order_id, $type = 'mockup') {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $vendor_id = get_post_meta($order_id, '_vss_vendor_user_id', true);
        if (!$vendor_id) return;
        
        $vendor_data = get_userdata($vendor_id);
        if (!$vendor_data || empty($vendor_data->user_email)) return;

        $type_label_uc = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        $subject = sprintf(__('%s Approved for Order #%s!', 'vss'), $type_label_uc, $order->get_order_number());
        $order_portal_link = esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], home_url('/vendor-portal/')));

        $message = '<p>Hello ' . esc_html($vendor_data->display_name) . ',</p>';
        $message .= '<p>Great news! The ' . strtolower($type_label_uc) . ' you submitted for order <strong>#' . esc_html($order->get_order_number()) . '</strong> has been <strong>approved</strong> by the customer.</p>';
        $message .= '<p><a href="' . $order_portal_link . '">View Order Details</a></p>';
        $message .= '<p>Thank you for your excellent work!</p>';

        self::send_email($vendor_data->user_email, $subject, $message);
    }

    /**
     * Send admin disapproval notification email
     */
    public static function send_admin_approval_disapproved_email($order_id, $type = 'mockup', $customer_notes = '') {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $admin_email = get_option('admin_email');
        $type_label_uc = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        $subject = sprintf(__('%s DISAPPROVED for Order #%s - Action Required', 'vss'), $type_label_uc, $order->get_order_number());
        $edit_order_link = esc_url(admin_url('post.php?post=' . $order_id . '&action=edit'));

        $message = '<p>Hello Admin,</p>';
        $message .= '<p>The ' . strtolower($type_label_uc) . ' for order <strong>#' . esc_html($order->get_order_number()) . '</strong> has been <strong>disapproved</strong> by the customer.</p>';
        
        if ($customer_notes) {
            $message .= '<p><strong>Customer Feedback:</strong><br>' . nl2br(esc_html($customer_notes)) . '</p>';
        } else {
            $message .= '<p>No specific feedback was provided. Please reach out to the customer for clarification.</p>';
        }
        
        $message .= '<p><a href="' . $edit_order_link . '">View Order in Admin</a></p>';

        self::send_email($admin_email, $subject, $message);
    }
}

// VSS_Admin Class (Stub - Full implementation should be pasted here from class-vss-admin.php)
class VSS_Admin {
    public static function init() {
        add_action( 'admin_menu', [ self::class, 'add_admin_menu_pages' ], 5 );
        add_action( 'admin_init', [ self::class, 'register_vss_settings' ]);
        add_action( 'add_meta_boxes', [ self::class, 'add_admin_meta_boxes' ], 10, 2 );
        add_action( 'save_post_shop_order', [ self::class, 'save_admin_meta_data' ], 10, 1 );
        add_action( 'save_post_shop_order', [ self::class, 'handle_admin_order_confirmation' ], 20, 1 );
        add_filter( 'manage_edit-shop_order_columns', [ self::class, 'add_vendor_order_column' ] );
        add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'populate_vendor_order_column' ], 10, 2 );
        add_action( 'wp_ajax_vss_split_order', [ self::class, 'ajax_split_order_handler' ] );
        add_action( 'restrict_manage_posts', [ self::class, 'add_vendor_filter_dropdown' ] );
        add_action( 'pre_get_posts', [ self::class, 'handle_vendor_filter_query' ] );
        // Additional hooks would be added here
    }
    
    // Minimal implementation to prevent errors
    public static function add_admin_menu_pages() {}
    public static function register_vss_settings() {}
    public static function add_admin_meta_boxes($post_type, $post_object) {}
    public static function save_admin_meta_data($post_id) {}
    public static function handle_admin_order_confirmation($post_id) {}
    public static function add_vendor_order_column($columns) { return $columns; }
    public static function populate_vendor_order_column($column, $post_id) {}
    public static function ajax_split_order_handler() {}
    public static function add_vendor_filter_dropdown($post_type) {}
    public static function handle_vendor_filter_query($query) {}
}

// VSS_Vendor Class (Stub - Full implementation should be pasted here from class-vss-vendor.php)
class VSS_Vendor {
    public static function init() {
        add_shortcode('vss_vendor_portal', [self::class, 'render_vendor_portal_shortcode']);
        add_action('template_redirect', [self::class, 'handle_frontend_forms']);
        add_filter('login_redirect', [self::class, 'vendor_login_redirect'], 9999, 3);
        add_filter( 'pre_get_posts', [ self::class, 'filter_orders_for_vendor_in_admin' ] );
        add_action('wp_ajax_vss_manual_fetch_zip', [self::class, 'ajax_manual_fetch_zakeke_zip']);
    }
    
    // Minimal implementation to prevent errors
    public static function render_vendor_portal_shortcode($atts) {
        return '<p>Vendor portal not fully implemented in single-file version.</p>';
    }
    
    public static function handle_frontend_forms() {}
    
    public static function vendor_login_redirect($redirect_to, $requested_redirect_to, $user) {
        return $redirect_to;
    }
    
    public static function filter_orders_for_vendor_in_admin($query) {}
    
    public static function ajax_manual_fetch_zakeke_zip() {
        wp_send_json_error(['message' => 'Not implemented in single-file version']);
    }
}

// VSS_Ajax Class
class VSS_Ajax {
    
    public static function handle_approve_mockup() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'mockup';
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'vss')]);
        }
        
        // Update approval status
        update_post_meta($order_id, "_vss_{$type}_status", 'approved');
        update_post_meta($order_id, "_vss_{$type}_responded_at", time());
        
        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $type_label = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
            $order->add_order_note(sprintf(__('%s approved by admin via quick action.', 'vss'), $type_label));
            
            // Send vendor notification
            VSS_Emails::send_vendor_approval_confirmed_email($order_id, $type);
        }
        
        wp_send_json_success([
            'message' => __('Approved successfully', 'vss'),
            'status_html' => '<span class="vss-admin-status-badge" style="background-color:#4CAF50;color:white;padding:3px 8px;border-radius:4px;">Approved</span>'
        ]);
    }
    
    public static function handle_disapprove_mockup() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'mockup';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'vss')]);
        }
        
        // Update approval status
        update_post_meta($order_id, "_vss_{$type}_status", 'disapproved');
        update_post_meta($order_id, "_vss_{$type}_responded_at", time());
        
        if ($reason) {
            update_post_meta($order_id, "_vss_{$type}_customer_notes", $reason);
        }
        
        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $type_label = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
            $note = sprintf(__('%s disapproved by admin via quick action.', 'vss'), $type_label);
            if ($reason) {
                $note .= ' ' . sprintf(__('Reason: %s', 'vss'), $reason);
            }
            $order->add_order_note($note);
            
            // Send admin notification
            VSS_Emails::send_admin_approval_disapproved_email($order_id, $type, $reason);
        }
        
        wp_send_json_success([
            'message' => __('Disapproved successfully', 'vss'),
            'status_html' => '<span class="vss-admin-status-badge" style="background-color:#F44336;color:white;padding:3px 8px;border-radius:4px;">Disapproved</span>'
        ]);
    }
    
    public static function get_vendor_costs() {
        check_ajax_referer('vss_ajax_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'vss')]);
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'vss')]);
        }
        
        $costs = get_post_meta($order_id, '_vss_order_costs', true);
        
        if (empty($costs) || !isset($costs['total_cost'])) {
            wp_send_json_error(['message' => __('No costs submitted by vendor yet', 'vss')]);
        }
        
        wp_send_json_success([
            'costs' => $costs,
            'formatted_total' => wc_price($costs['total_cost'])
        ]);
    }
}

// ============================================================================
// MAIN PLUGIN CLASS - Now all dependencies are defined above
// ============================================================================

// Main plugin class
final class VendorOrderManager {
    private static $_instance = null;
    
    public static function instance() { 
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance; 
    }
    
    private function __construct() {
        add_action('plugins_loaded', [$this, 'init_plugin']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate() {
        // Create database tables for analytics
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Vendor performance analytics table
        $table_name = $wpdb->prefix . 'vss_vendor_analytics';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            vendor_id bigint(20) NOT NULL,
            order_id bigint(20) NOT NULL,
            order_total decimal(10,2) DEFAULT 0,
            vendor_cost decimal(10,2) DEFAULT 0,
            profit_margin decimal(5,2) DEFAULT 0,
            processing_time int(11) DEFAULT 0,
            approval_time int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vendor_id (vendor_id),
            KEY order_id (order_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Add vendor role
        VSS_Setup::add_vendor_role();
        
        // Schedule cron jobs
        if (!wp_next_scheduled('vss_daily_analytics')) {
            wp_schedule_event(time(), 'daily', 'vss_daily_analytics');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('vss_daily_analytics');
        wp_clear_scheduled_hook('vss_fetch_zakeke_files_for_order_hook');
    }

    public function init_plugin() {
        $this->init_hooks();
        
        // Initialize all components - these classes are now defined above
        VSS_Setup::init();
        VSS_Zakeke_API::init(); 
        VSS_Admin::init(); 
        VSS_Vendor::init();
        VSS_Emails::init();
    }

    private function init_hooks() {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_styles_scripts']); 
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_scripts_styles']);
        add_action('wp_ajax_vss_approve_mockup', ['VSS_Ajax', 'handle_approve_mockup']);
        add_action('wp_ajax_vss_disapprove_mockup', ['VSS_Ajax', 'handle_disapprove_mockup']);
        add_action('wp_ajax_vss_get_vendor_costs', ['VSS_Ajax', 'get_vendor_costs']);
        add_action('wp_ajax_vss_manual_fetch_zip', ['VSS_Vendor', 'ajax_manual_fetch_zakeke_zip']);
    }

    public static function enqueue_admin_scripts_styles($hook) {
        wp_enqueue_style('vss-admin-styles', VSS_PLUGIN_URL . 'assets/css/vss-admin-styles.css', [], VSS_VERSION);
        
        global $pagenow, $typenow;
        if (($pagenow === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order') || 
            ($pagenow === 'post-new.php' && $typenow === 'shop_order') ||
            (isset($_GET['page']) && strpos($_GET['page'], 'vss-') === 0)) {
            
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-smoothness', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');
            
            // Enqueue Chart.js for analytics
            if (isset($_GET['page']) && strpos($_GET['page'], 'vss-') === 0) {
                wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', [], '3.9.1');
            }
            
            // Admin JS inline
            wp_add_inline_script('jquery', self::get_admin_inline_js());
            wp_localize_script('jquery', 'vss_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vss_ajax_nonce')
            ]);
        }
    }

    public static function enqueue_frontend_styles_scripts() {
        if (is_page('vendor-portal')) { 
            wp_enqueue_style('vss-frontend-styles', VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css', [], VSS_VERSION);
            wp_enqueue_script('jquery'); 
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jquery-ui-smoothness', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');
            
            wp_add_inline_script('jquery', self::get_frontend_inline_js());
            wp_localize_script('jquery', 'vss_frontend_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('vss_manual_fetch_zip_nonce')
            ]);
        }
    }
    
    private static function get_admin_inline_js() {
        return "
        jQuery(document).ready(function($) {
            // Handle mockup approval
            $(document).on('click', '.vss-approve-mockup', function(e) {
                e.preventDefault();
                var button = $(this);
                var orderId = button.data('order-id');
                var type = button.data('type') || 'mockup';
                
                if (!confirm('Are you sure you want to approve this ' + type + '?')) {
                    return;
                }
                
                button.prop('disabled', true).text('Processing...');
                
                $.ajax({
                    url: vss_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'vss_approve_mockup',
                        order_id: orderId,
                        type: type,
                        nonce: vss_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            button.replaceWith(response.data.status_html);
                            alert(response.data.message);
                        } else {
                            alert('Error: ' + response.data.message);
                            button.prop('disabled', false).text('Approve');
                        }
                    },
                    error: function() {
                        alert('AJAX error. Please try again.');
                        button.prop('disabled', false).text('Approve');
                    }
                });
            });
        });";
    }
    
    private static function get_frontend_inline_js() {
        return "
        jQuery(document).ready(function($) {
            // Cost calculation
            function calculateTotalCostFrontend() {
                var total = 0;
                $('.vss-cost-input-fe').each(function() {
                    var val = parseFloat($(this).val().replace(/,/g, '.').replace(/[^0-9\.]/g, ''));
                    if (!isNaN(val)) { 
                        total += val; 
                    }
                });
                var currency_symbol = $('#vss-total-cost-display-fe').data('currency') || '$';
                var formatted_total = currency_symbol + total.toLocaleString(undefined, {
                    minimumFractionDigits: 2, 
                    maximumFractionDigits: 2
                });
                $('#vss-total-cost-display-fe').text(formatted_total);
            }
            
            if ($('.vss-cost-input-fe').length) { 
                calculateTotalCostFrontend(); 
            }
            
            $('body').on('keyup change', '.vss-cost-input-fe', calculateTotalCostFrontend);
            
            // Tabs
            $('.vss-order-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();
                var targetTab = $(this).attr('href');
                $('.vss-order-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.vss-tab-content').hide();
                $(targetTab).fadeIn(200);
                if (typeof(Storage) !== 'undefined') { 
                    localStorage.setItem('vssActiveOrderTab', targetTab); 
                }
            });
            
            // Initialize active tab
            if (typeof(Storage) !== 'undefined') {
                var activeTab = localStorage.getItem('vssActiveOrderTab');
                if (activeTab && $('.vss-order-tabs a[href=\"' + activeTab + '\"]').length) {
                    $('.vss-order-tabs a[href=\"' + activeTab + '\"]').click();
                } else if ($('.vss-order-tabs .nav-tab').length) {
                    $('.vss-order-tabs .nav-tab').first().click();
                }
            } else if ($('.vss-order-tabs .nav-tab').length) {
                $('.vss-order-tabs .nav-tab').first().click();
            }
            
            // Datepicker
            if (typeof $.fn.datepicker === 'function') {
                $('.vss-datepicker-fe').datepicker({ 
                    dateFormat: 'yy-mm-dd', 
                    minDate: 0
                });
            }
            
            // Manual Zakeke fetch
            $('body').on('click', '.vss-manual-fetch-zakeke-zip', function(e) {
                e.preventDefault();
                var button = $(this);
                var orderId = button.data('order-id');
                var itemId = button.data('item-id');
                var zakekeDesignId = button.data('zakeke-design-id');
                var feedbackEl = button.siblings('.vss-fetch-zip-feedback');
                
                button.prop('disabled', true).text('Fetching...');
                feedbackEl.html('');
                
                $.ajax({
                    url: vss_frontend_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'vss_manual_fetch_zip',
                        _ajax_nonce: vss_frontend_ajax.nonce,
                        order_id: orderId,
                        item_id: itemId,
                        primary_zakeke_design_id: zakekeDesignId
                    },
                    success: function(response) {
                        if (response.success) {
                            feedbackEl.html('<span style=\"color:green;\">' + response.data.message + '</span>');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            feedbackEl.html('<span style=\"color:red;\">' + response.data.message + '</span>');
                            button.prop('disabled', false).text('Retry Fetch');
                        }
                    },
                    error: function() {
                        feedbackEl.html('<span style=\"color:red;\">Connection error. Please try again.</span>');
                        button.prop('disabled', false).text('Retry Fetch');
                    }
                });
            });
        });";
    }
}

// Initialize the plugin
function VSS() { 
    return VendorOrderManager::instance(); 
}
VSS();

// DO NOT include external files as they will cause "class already declared" errors
// All necessary classes are defined above in this single file