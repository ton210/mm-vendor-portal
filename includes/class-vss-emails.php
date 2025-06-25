<?php
/**
 * VSS Emails Class
 *
 * Handles email notifications for the Vendor Order Manager plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class VSS_Emails {

    /**
     * Initialize email hooks.
     */
    public static function init() {
        add_action('vss_order_assigned_to_vendor', [self::class, 'send_new_assignment_email'], 10, 2);
        add_action('woocommerce_order_status_changed', [self::class, 'send_status_change_emails'], 10, 4);
        // Other email triggers (like approval requests) are called directly from their respective action handlers.
    }

    /**
     * Send an email.
     *
     * @param string $to Recipient email address.
     * @param string $subject Email subject.
     * @param string $message Email message content (HTML).
     * @param string|null $reply_to_email Optional. Email address for Reply-To header.
     * @param string|null $reply_to_name Optional. Name for Reply-To header.
     * @param array $attachments Optional. Array of file paths for attachments.
     * @return bool True if email was sent successfully, false otherwise.
     */
    private static function send_email($to, $subject, $message, $reply_to_email = null, $reply_to_name = null, $attachments = []) {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $site_title = get_bloginfo('name');
        $admin_email_address = get_option('admin_email'); // Fallback 'from'
        $from_email = apply_filters('vss_email_from_address', $admin_email_address);
        $from_name = apply_filters('vss_email_from_name', $site_title);

        $headers[] = "From: {$from_name} <{$from_email}>";
        if ($reply_to_email) {
            $reply_to_header = "Reply-To: ";
            if ($reply_to_name) { $reply_to_header .= "{$reply_to_name} <{$reply_to_email}>"; }
            else { $reply_to_header .= $reply_to_email; }
            $headers[] = $reply_to_header;
        }

        $mailer = WC()->mailer(); // WC_Emails instance (plural)

        // Wrap the raw message content with WooCommerce's email header and footer.
        // The first argument to wrap_message is the email heading.
        $wrapped_message = $mailer->wrap_message($subject, $message);

        // Apply inline styles using a temporary WC_Email object (singular)
        // This object provides the style_inline method which uses Emogrifier if available.
        $email_styler = new WC_Email();
        $styled_html_message = $email_styler->style_inline($wrapped_message);
        
        // Check if styling failed (e.g., Emogrifier not available and it returned false or error)
        if (is_wp_error($styled_html_message) || false === $styled_html_message) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) {
                $error_detail = is_wp_error($styled_html_message) ? $styled_html_message->get_error_message() : 'returned false';
                error_log('VSS Email Error: WC_Email::style_inline() failed. Sending email with basic wrapping. Detail: ' . $error_detail);
            }
            $styled_html_message = $wrapped_message; // Fallback to just the wrapped message
        }

        return $mailer->send($to, $subject, $styled_html_message, $headers, $attachments);
    }

    /**
     * Send email to vendor when a new order is assigned.
     */
    public static function send_new_assignment_email($order_id, $vendor_id) {
        $order = wc_get_order($order_id);
        $vendor_user = get_userdata($vendor_id);

        if (!$order || !$vendor_user || empty($vendor_user->user_email)) {
            if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log("VSS Email Error: Could not send new assignment email for order {$order_id} to vendor {$vendor_id}. Order or vendor data missing.");
            }
            return;
        }

        $subject = sprintf(__('New Order #%s Assigned to You - %s', 'vss'), $order->get_order_number(), get_bloginfo('name'));
        $vendor_portal_link = home_url('/vendor-portal/');
        $order_link = esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], $vendor_portal_link));

        ob_start();
        ?>
        <p><?php printf(__('Hello %s,', 'vss'), esc_html($vendor_user->display_name)); ?></p>
        <p><?php printf(__('A new order #%s has been assigned to you.', 'vss'), '<strong>' . esc_html($order->get_order_number()) . '</strong>'); ?></p>
        <p><?php _e('Order details:', 'vss'); ?></p>
        <ul>
            <?php foreach ($order->get_items() as $item_id => $item): ?>
                <li><?php echo esc_html($item->get_name()); ?> &times; <?php echo esc_html($item->get_quantity()); ?></li>
            <?php endforeach; ?>
        </ul>
        <p><?php printf(__('You can view and manage this order in your portal: <a href="%s" style="color: #0073aa; text-decoration: underline;">View Order #%s</a>', 'vss'), $order_link, esc_html($order->get_order_number())); ?></p>
        <p><?php _e('Thank you,', 'vss'); ?><br><?php echo esc_html(get_bloginfo('name')); ?></p>
        <?php
        $message = ob_get_clean();
        self::send_email($vendor_user->user_email, $subject, $message);
    }

    /**
     * Send email notifications based on order status changes.
     */
    public static function send_status_change_emails($order_id, $old_status, $new_status, $order) {
        // Ensure $order is a WC_Order object
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
            if (!$order) return;
        }

        $vendor_id = get_post_meta($order_id, '_vss_vendor_user_id', true);
        if (!$vendor_id) return; // Only if a vendor is assigned

        $vendor_user = get_userdata($vendor_id);
        if (!$vendor_user || empty($vendor_user->user_email)) return;

        // Example: Notify vendor if order is cancelled
        if ($new_status === 'cancelled' && $old_status !== 'cancelled') {
            $subject = sprintf(__('Order #%s Status Update: Cancelled - %s', 'vss'), $order->get_order_number(), get_bloginfo('name'));
            $order_link = esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], home_url('/vendor-portal/')));
            $message = sprintf(__('<p>Hello %s,</p><p>Please be advised that order #%s has been <strong>cancelled</strong>.</p><p>You can view the order details here: <a href="%s" style="color: #0073aa; text-decoration: underline;">View Order #%s</a></p>', 'vss'),
                esc_html($vendor_user->display_name),
                '<strong>' . esc_html($order->get_order_number()) . '</strong>',
                $order_link,
                esc_html($order->get_order_number())
            );
            self::send_email($vendor_user->user_email, $subject, $message);
        }
        // Add other status change notifications as needed (e.g., on-hold, completed if relevant to vendor)
    }

    /**
     * Send email to customer confirming production and estimated ship date.
     */
    public static function send_customer_production_confirmation_email($order_id, $order_number_display, $estimated_ship_date) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $email_sent_flag = '_vss_customer_production_email_sent_at';
        $date_at_last_email_flag = '_vss_estimated_ship_date_at_last_email';

        $last_sent_timestamp = get_post_meta($order_id, $email_sent_flag, true);
        $date_at_last_email = get_post_meta($order_id, $date_at_last_email_flag, true);

        if ($last_sent_timestamp && $date_at_last_email === $estimated_ship_date) {
            return; // Email for this specific date was already sent.
        }

        $customer_email = $order->get_billing_email();
        if (!$customer_email) {
            $order->add_order_note(__('Customer production confirmation email NOT sent: No billing email found.', 'vss'));
            return;
        }

        $formatted_ship_date = date_i18n(wc_date_format(), strtotime($estimated_ship_date));
        $subject = sprintf(__('Your MunchMakers Order %s is in Production!', 'vss'), esc_html($order_number_display));
        $customer_name = $order->get_billing_first_name() ? $order->get_billing_first_name() : __('Valued Customer', 'vss');

        $email_content  = "<p>" . sprintf(__("Hello %s,", 'vss'), esc_html($customer_name)) . "</p>";
        $email_content .= "<p>" . sprintf(__('This is to confirm your order %s has begun production and is estimated to ship by %s.', 'vss'), '<strong>'.esc_html($order_number_display).'</strong>', '<strong>'.esc_html($formatted_ship_date).'</strong>') . "</p>";
        $email_content .= "<p>" . __('If you have any questions, concerns, or feedback please respond to this email or contact us at help@munchmakers.com.', 'vss') . "</p>";
        $email_content .= "<p>" . __('For now, we will get busy on production and keep you updated on its progress!', 'vss') . "</p>";
        $email_content .= "<p>" . __('Thanks,', 'vss') . "<br>" . __('The MunchMakers Team', 'vss') . "</p>";

        if (self::send_email($customer_email, $subject, $email_content, 'help@munchmakers.com', 'MunchMakers Support')) {
            update_post_meta($order_id, $email_sent_flag, time());
            update_post_meta($order_id, $date_at_last_email_flag, $estimated_ship_date);
            // Note about sending is handled by the calling function (admin or vendor confirmation)
        } else {
            $order->add_order_note(__('Failed to send customer production confirmation email.', 'vss'));
        }
    }

    /**
     * Send email to customer requesting approval for mockup or production files.
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

        $approve_nonce = wp_create_nonce("vss_approve_{$type}_{$order_id}");
        $disapprove_nonce = wp_create_nonce("vss_disapprove_{$type}_{$order_id}");

        $approve_link = add_query_arg(['action' => "vss_handle_{$type}_approval", 'order_id' => $order_id, 'approval_status' => 'approved', '_wpnonce' => $approve_nonce], admin_url('admin-post.php'));
        $disapprove_link = add_query_arg(['action' => "vss_handle_{$type}_approval", 'order_id' => $order_id, 'approval_status' => 'disapproved', '_wpnonce' => $disapprove_nonce], admin_url('admin-post.php'));

        $email_content  = "<p>" . sprintf(__("Hello %s,", 'vss'), esc_html($customer_name)) . "</p>";
        $email_content .= "<p>" . sprintf(__('Your vendor has submitted a %s for order #%s for your approval. Please review the details below and click "Approve" or "Disapprove".', 'vss'), $type_label_lc, '<strong>'.esc_html($order->get_order_number()).'</strong>') . "</p>";

        if ($vendor_notes) {
            $email_content .= "<h4 style='margin-top: 20px; margin-bottom: 5px;'>" . __('Notes from Vendor:', 'vss') . "</h4>";
            $email_content .= "<blockquote style='border-left: 3px solid #eeeeee; padding-left: 15px; margin-left: 0; margin-bottom: 20px; font-style: italic;'>" . nl2br(esc_html($vendor_notes)) . "</blockquote>";
        }

        if (!empty($files_ids)) {
            $email_content .= "<h4 style='margin-top: 20px; margin-bottom: 10px;'>" . __('Files for Your Review:', 'vss') . "</h4><div style='margin-bottom: 20px;'>";
            foreach ($files_ids as $file_id) {
                $file_url = wp_get_attachment_url($file_id);
                $file_path = get_attached_file($file_id);
                $file_name = $file_path ? basename($file_path) : __('Attached File', 'vss');
                if ($file_url) {
                     $email_content .= "<div style='margin-bottom: 15px; padding: 10px; border: 1px solid #dddddd; border-radius: 3px;'>";
                     $email_content .= "<p style='margin:0 0 5px 0;'><a href='" . esc_url($file_url) . "' target='_blank' style='font-weight:bold; color: #0073aa; text-decoration: underline;'>" . esc_html($file_name) . "</a></p>";
                     if(wp_attachment_is_image($file_id)){
                         $img_src_array = wp_get_attachment_image_src($file_id, 'medium'); // Returns an array (url, width, height, is_intermediate) or false
                         if ($img_src_array && isset($img_src_array[0])) {
                            $img_src = $img_src_array[0];
                            $email_content .= "<a href='" . esc_url($file_url) . "' target='_blank'><img src='" . esc_url($img_src) . "' alt='".esc_attr($file_name)."' style='max-width:300px; max-height:300px; height:auto; border:1px solid #cccccc; margin-top:5px; display:block;' /></a>";
                         }
                     }
                     $email_content .= "</div>";
                }
            }
            $email_content .= "</div>";
        }

        $email_content .= "<p style='margin-top:25px; padding-top:15px; border-top:1px solid #eeeeee; text-align:center;'>" . sprintf(
            '<a href="%s" style="background-color: #4CAF50; color: white !important; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px; font-size: 16px; display:inline-block; min-width: 120px;">%s</a>',
            esc_url($approve_link),
            __('APPROVE', 'vss')
        ) . sprintf(
            '<a href="%s" style="background-color: #F44336; color: white !important; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 5px; font-size: 16px; display:inline-block; min-width: 120px;">%s</a>',
            esc_url($disapprove_link),
            __('DISAPPROVE', 'vss')
        ) . "</p>";
        $email_content .= "<p style='margin-top:20px; font-size:small; color:#777777; text-align:center;'>" . __('If you have any questions, or if you wish to provide specific feedback upon disapproval, please reply to this email or contact us directly at help@munchmakers.com.', 'vss') . "</p>";
        $email_content .= "<p>" . __('Thanks,', 'vss') . "<br>" . __('The MunchMakers Team', 'vss') . "</p>";

        if (self::send_email($customer_email, $subject, $email_content, 'help@munchmakers.com', 'MunchMakers Support')) {
            $order->add_order_note(sprintf(__('%s approval request email sent to customer.', 'vss'), $type_label_uc));
        } else {
            $order->add_order_note(sprintf(__('Failed to send %s approval request email to customer.', 'vss'), $type_label_uc));
        }
    }

    /**
     * Send email to vendor when their submission (mockup/prod file) is approved.
     */
    public static function send_vendor_approval_confirmed_email($order_id, $type = 'mockup') {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $vendor_id = get_post_meta($order_id, '_vss_vendor_user_id', true);
        if (!$vendor_id) return;
        $vendor_data = get_userdata($vendor_id);
        if (!$vendor_data || empty($vendor_data->user_email)) return;

        $type_label_uc = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        $type_label_lc = strtolower($type_label_uc);
        $subject = sprintf(__('%s Approved for Order #%s!', 'vss'), $type_label_uc, $order->get_order_number());
        $order_portal_link = esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], home_url('/vendor-portal/')));
        $tab_hash = ($type === 'mockup') ? '#tab-mockup-approval' : '#tab-prodfile-approval';

        $message  = sprintf(__('<p>Hello %s,</p>', 'vss'), esc_html($vendor_data->display_name));
        $message .= sprintf(__('<p>Great news! The %s you submitted for order #%s has been <strong>approved</strong> by the customer.</p>', 'vss'), $type_label_lc, '<strong>'.esc_html($order->get_order_number()).'</strong>');
        $message .= sprintf(__('<p>You can view the order details here: <a href="%s" style="color: #0073aa; text-decoration: underline;">View Order #%s (%s Tab)</a></p>', 'vss'), $order_portal_link . $tab_hash, esc_html($order->get_order_number()), $type_label_uc);
        $message .= __('<p>Thank you!</p>', 'vss');
        self::send_email($vendor_data->user_email, $subject, $message);
    }

    /**
     * Send email to admin when a submission (mockup/prod file) is disapproved by customer.
     */
    public static function send_admin_approval_disapproved_email($order_id, $type = 'mockup', $customer_notes = '') {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $admin_email = get_option('admin_email');

        $type_label_uc = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        $type_label_lc = strtolower($type_label_uc);
        $subject = sprintf(__('%s DISAPPROVED for Order #%s - Action Required', 'vss'), $type_label_uc, $order->get_order_number());
        $edit_order_link = esc_url(get_edit_post_link($order_id));

        $message  = sprintf(__('<p>Hello Admin,</p>', 'vss'));
        $message .= sprintf(__('<p>The %s for order #%s has been <strong>disapproved</strong> by the customer.</p>', 'vss'), $type_label_lc, '<strong>'.esc_html($order->get_order_number()).'</strong>');
        if ($customer_notes) {
            $message .= '<p><strong>' . __('Customer Feedback (if provided):', 'vss') . '</strong><br>' . nl2br(esc_html($customer_notes)) . '</p>';
        } else {
            $message .= '<p>' . __('Please reach out to the customer to understand the reasons for disapproval (if not automatically provided) and coordinate with the vendor for revisions.', 'vss') . '</p>';
        }
        $message .= '<p>' . sprintf(__('You can review the order here: <a href="%s" style="color: #0073aa; text-decoration: underline;">Edit Order #%s</a>', 'vss'), $edit_order_link, esc_html($order->get_order_number())) . '</p>';
        self::send_email($admin_email, $subject, $message);
    }

} // End class VSS_Emails