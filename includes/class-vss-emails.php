<?php
/**
 * Enhanced VSS Emails Class
 *
 * Improved email notifications with better approval buttons and design
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_Emails_Enhanced {

    /**
     * Initialize email hooks.
     */
    public static function init() {
        add_action('vss_order_assigned_to_vendor', [self::class, 'send_new_assignment_email'], 10, 2);
        add_action('woocommerce_order_status_changed', [self::class, 'send_status_change_emails'], 10, 4);
        
        // Add custom email styles
        add_action('woocommerce_email_header', [self::class, 'add_custom_email_styles'], 10, 2);
    }

    /**
     * Add custom CSS styles to WooCommerce emails
     */
    public static function add_custom_email_styles($email_heading, $email) {
        ?>
        <style type="text/css">
            /* Custom button styles for VSS emails */
            .vss-email-button {
                display: inline-block !important;
                padding: 15px 30px !important;
                margin: 10px 5px !important;
                text-decoration: none !important;
                border-radius: 5px !important;
                font-size: 16px !important;
                font-weight: bold !important;
                text-align: center !important;
                transition: all 0.3s ease !important;
                font-family: Arial, sans-serif !important;
                min-width: 150px !important;
            }
            
            .vss-email-button-approve {
                background-color: #4CAF50 !important;
                color: #ffffff !important;
                border: 2px solid #4CAF50 !important;
            }
            
            .vss-email-button-approve:hover {
                background-color: #45a049 !important;
                color: #ffffff !important;
            }
            
            .vss-email-button-disapprove {
                background-color: #f44336 !important;
                color: #ffffff !important;
                border: 2px solid #f44336 !important;
            }
            
            .vss-email-button-disapprove:hover {
                background-color: #da190b !important;
                color: #ffffff !important;
            }
            
            .vss-button-container {
                text-align: center !important;
                margin: 30px 0 !important;
                padding: 20px !important;
                background-color: #f9f9f9 !important;
                border-radius: 10px !important;
            }
            
            .vss-file-preview-container {
                margin: 20px 0 !important;
                padding: 15px !important;
                border: 1px solid #e0e0e0 !important;
                border-radius: 8px !important;
                background-color: #ffffff !important;
            }
            
            .vss-vendor-notes {
                background-color: #f5f5f5 !important;
                padding: 15px !important;
                border-left: 4px solid #2271b1 !important;
                margin: 20px 0 !important;
                font-style: italic !important;
            }
            
            @media only screen and (max-width: 600px) {
                .vss-email-button {
                    display: block !important;
                    width: 100% !important;
                    margin: 10px 0 !important;
                }
            }
        </style>
        <?php
    }

    /**
     * Send enhanced email with better formatting
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
     * Enhanced customer approval request email with better buttons
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

        // Create approval page URLs (alternative approach)
        $approval_page_url = home_url('/order-approval/');
        
        $approve_link = add_query_arg([
            'action' => "vss_handle_{$type}_approval",
            'order_id' => $order_id,
            'approval_status' => 'approved',
            '_wpnonce' => $approve_nonce,
            'email' => base64_encode($customer_email)
        ], $approval_page_url);
        
        $disapprove_link = add_query_arg([
            'action' => "vss_handle_{$type}_approval",
            'order_id' => $order_id,
            'approval_status' => 'disapproved',
            '_wpnonce' => $disapprove_nonce,
            'email' => base64_encode($customer_email)
        ], $approval_page_url);

        // Build email content with enhanced styling
        $email_content = self::get_email_header($customer_name);
        
        $email_content .= sprintf(
            '<p style="font-size: 16px; line-height: 1.6; color: #333333;">Your vendor has submitted a %s for order <strong>#%s</strong> for your approval. Please review the details below and click one of the buttons to approve or request changes.</p>',
            $type_label_lc,
            esc_html($order->get_order_number())
        );

        // Add vendor notes if present
        if ($vendor_notes) {
            $email_content .= '<div class="vss-vendor-notes">';
            $email_content .= '<h4 style="margin: 0 0 10px 0; color: #2271b1;">' . __('Notes from Vendor:', 'vss') . '</h4>';
            $email_content .= '<p style="margin: 0;">' . nl2br(esc_html($vendor_notes)) . '</p>';
            $email_content .= '</div>';
        }

        // Display files for review
        if (!empty($files_ids)) {
            $email_content .= '<div class="vss-file-preview-container">';
            $email_content .= '<h4 style="margin: 0 0 15px 0; color: #333333;">' . __('Files for Your Review:', 'vss') . '</h4>';
            
            foreach ($files_ids as $file_id) {
                $file_url = wp_get_attachment_url($file_id);
                $file_path = get_attached_file($file_id);
                $file_name = $file_path ? basename($file_path) : __('Attached File', 'vss');
                
                if ($file_url) {
                    $email_content .= '<div style="margin-bottom: 15px; padding: 15px; border: 1px solid #e0e0e0; border-radius: 5px; background-color: #fafafa;">';
                    
                    // File name and download link
                    $email_content .= '<p style="margin: 0 0 10px 0; font-weight: bold;">';
                    $email_content .= '<a href="' . esc_url($file_url) . '" style="color: #2271b1; text-decoration: none;">' . esc_html($file_name) . '</a>';
                    $email_content .= '</p>';
                    
                    // Image preview if applicable
                    if (wp_attachment_is_image($file_id)) {
                        $img_src_array = wp_get_attachment_image_src($file_id, 'large');
                        if ($img_src_array && isset($img_src_array[0])) {
                            $email_content .= '<a href="' . esc_url($file_url) . '" style="display: block; text-align: center;">';
                            $email_content .= '<img src="' . esc_url($img_src_array[0]) . '" alt="' . esc_attr($file_name) . '" style="max-width: 100%; height: auto; max-height: 400px; border: 1px solid #dddddd; border-radius: 5px;" />';
                            $email_content .= '</a>';
                        }
                    }
                    
                    $email_content .= '</div>';
                }
            }
            
            $email_content .= '</div>';
        }

        // Enhanced button section
        $email_content .= '<div class="vss-button-container">';
        $email_content .= '<h3 style="margin: 0 0 20px 0; color: #333333;">' . __('Please select your response:', 'vss') . '</h3>';
        
        // Approve button
        $email_content .= '<a href="' . esc_url($approve_link) . '" class="vss-email-button vss-email-button-approve" style="background-color: #4CAF50; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-size: 18px; font-weight: bold; display: inline-block; margin: 0 10px;">';
        $email_content .= '✓ ' . __('APPROVE', 'vss');
        $email_content .= '</a>';
        
        // Disapprove button
        $email_content .= '<a href="' . esc_url($disapprove_link) . '" class="vss-email-button vss-email-button-disapprove" style="background-color: #f44336; color: #ffffff; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-size: 18px; font-weight: bold; display: inline-block; margin: 0 10px;">';
        $email_content .= '✗ ' . __('REQUEST CHANGES', 'vss');
        $email_content .= '</a>';
        
        $email_content .= '</div>';

        // Alternative text links
        $email_content .= '<div style="margin-top: 30px; padding: 20px; background-color: #f5f5f5; border-radius: 5px;">';
        $email_content .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666666;">' . __('If the buttons above do not work, please copy and paste one of these links into your browser:', 'vss') . '</p>';
        $email_content .= '<p style="margin: 5px 0; font-size: 12px; word-break: break-all;">';
        $email_content .= '<strong>' . __('To Approve:', 'vss') . '</strong><br>';
        $email_content .= '<span style="color: #2271b1;">' . esc_url($approve_link) . '</span>';
        $email_content .= '</p>';
        $email_content .= '<p style="margin: 5px 0; font-size: 12px; word-break: break-all;">';
        $email_content .= '<strong>' . __('To Request Changes:', 'vss') . '</strong><br>';
        $email_content .= '<span style="color: #2271b1;">' . esc_url($disapprove_link) . '</span>';
        $email_content .= '</p>';
        $email_content .= '</div>';

        // Footer
        $email_content .= self::get_email_footer();

        if (self::send_email($customer_email, $subject, $email_content, 'help@munchmakers.com', 'MunchMakers Support')) {
            $order->add_order_note(sprintf(__('%s approval request email sent to customer.', 'vss'), $type_label_uc));
            
            // Store email sent timestamp
            update_post_meta($order_id, "_vss_{$type}_email_sent_at", time());
        } else {
            $order->add_order_note(sprintf(__('Failed to send %s approval request email to customer.', 'vss'), $type_label_uc));
        }
    }

    /**
     * Get email header template
     */
    private static function get_email_header($customer_name) {
        $header = '<div style="margin-bottom: 30px;">';
        $header .= '<p style="font-size: 18px; color: #333333; margin: 0 0 20px 0;">' . sprintf(__('Hello %s,', 'vss'), esc_html($customer_name)) . '</p>';
        return $header;
    }

    /**
     * Get email footer template
     */
    private static function get_email_footer() {
        $footer = '<div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e0e0e0;">';
        $footer .= '<p style="font-size: 14px; color: #666666; margin: 0 0 10px 0;">';
        $footer .= __('If you have any questions or need assistance, please reply to this email or contact us at help@munchmakers.com.', 'vss');
        $footer .= '</p>';
        $footer .= '<p style="font-size: 14px; color: #666666; margin: 0;">';
        $footer .= __('Thank you,', 'vss') . '<br>';
        $footer .= '<strong>' . __('The MunchMakers Team', 'vss') . '</strong>';
        $footer .= '</p>';
        $footer .= '</div>';
        return $footer;
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

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; color: #333333;">
            <p style="font-size: 18px; margin-bottom: 20px;"><?php printf(__('Hello %s,', 'vss'), esc_html($vendor_user->display_name)); ?></p>
            
            <div style="background-color: #f0f8ff; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <p style="font-size: 16px; margin: 0 0 10px 0;">
                    <?php printf(__('A new order <strong>#%s</strong> has been assigned to you.', 'vss'), esc_html($order->get_order_number())); ?>
                </p>
                
                <a href="<?php echo $order_link; ?>" style="display: inline-block; background-color: #2271b1; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 10px;">
                    <?php _e('View Order Details', 'vss'); ?>
                </a>
            </div>
            
            <h3 style="color: #333333; margin-bottom: 15px;"><?php _e('Order Items:', 'vss'); ?></h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <thead>
                    <tr style="background-color: #f5f5f5;">
                        <th style="padding: 10px; text-align: left; border-bottom: 2px solid #ddd;"><?php _e('Product', 'vss'); ?></th>
                        <th style="padding: 10px; text-align: center; border-bottom: 2px solid #ddd;"><?php _e('Quantity', 'vss'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order->get_items() as $item_id => $item): ?>
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo esc_html($item->get_name()); ?></td>
                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #eee;"><?php echo esc_html($item->get_quantity()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="margin-top: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 5px;">
                <p style="margin: 0 0 10px 0;"><strong><?php _e('Next Steps:', 'vss'); ?></strong></p>
                <ol style="margin: 0; padding-left: 20px;">
                    <li><?php _e('Review the order details in your vendor portal', 'vss'); ?></li>
                    <li><?php _e('Confirm production and set estimated ship date', 'vss'); ?></li>
                    <li><?php _e('Upload mockups for customer approval', 'vss'); ?></li>
                    <li><?php _e('Submit your costs for this order', 'vss'); ?></li>
                </ol>
            </div>
            
            <p style="margin-top: 30px; font-size: 14px; color: #666666;">
                <?php _e('Thank you for your partnership!', 'vss'); ?><br>
                <?php echo esc_html(get_bloginfo('name')); ?>
            </p>
        </div>
        <?php
        $message = ob_get_clean();
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

        // Enhanced status change emails
        if ($new_status === 'cancelled' && $old_status !== 'cancelled') {
            $subject = sprintf(__('Order #%s Cancelled - %s', 'vss'), $order->get_order_number(), get_bloginfo('name'));
            $order_link = esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], home_url('/vendor-portal/')));
            
            ob_start();
            ?>
            <div style="font-family: Arial, sans-serif; color: #333333;">
                <p style="font-size: 18px;"><?php printf(__('Hello %s,', 'vss'), esc_html($vendor_user->display_name)); ?></p>
                
                <div style="background-color: #fff3cd; border: 1px solid #ffeeba; padding: 20px; border-radius: 5px; margin: 20px 0;">
                    <p style="margin: 0; color: #856404;">
                        <strong><?php _e('Important Notice:', 'vss'); ?></strong> 
                        <?php printf(__('Order #%s has been cancelled.', 'vss'), esc_html($order->get_order_number())); ?>
                    </p>
                </div>
                
                <p><?php _e('Please halt any production work on this order immediately.', 'vss'); ?></p>
                
                <a href="<?php echo $order_link; ?>" style="display: inline-block; background-color: #6c757d; color: #ffffff; padding: 10px 25px; text-decoration: none; border-radius: 5px; margin-top: 15px;">
                    <?php _e('View Order Details', 'vss'); ?>
                </a>
            </div>
            <?php
            $message = ob_get_clean();
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

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; color: #333333;">
            <p style="font-size: 18px; margin-bottom: 20px;"><?php printf(__('Hello %s,', 'vss'), esc_html($customer_name)); ?></p>
            
            <div style="background-color: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                <h2 style="color: #155724; margin: 0 0 10px 0;">🎉 <?php _e('Great News!', 'vss'); ?></h2>
                <p style="font-size: 16px; color: #155724; margin: 0;">
                    <?php printf(__('Your order <strong>%s</strong> has begun production and is estimated to ship by <strong>%s</strong>.', 'vss'), 
                        esc_html($order_number_display), 
                        esc_html($formatted_ship_date)
                    ); ?>
                </p>
            </div>
            
            <div style="margin: 30px 0;">
                <h3 style="color: #333333; margin-bottom: 15px;"><?php _e('What happens next?', 'vss'); ?></h3>
                <ul style="line-height: 1.8;">
                    <li><?php _e('Our vendor is crafting your custom order with care', 'vss'); ?></li>
                    <li><?php _e('You\'ll receive tracking information once your order ships', 'vss'); ?></li>
                    <li><?php _e('We\'ll notify you of any updates along the way', 'vss'); ?></li>
                </ul>
            </div>
            
            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 30px;">
                <p style="margin: 0; font-size: 14px; color: #666666;">
                    <?php _e('Questions or concerns? Simply reply to this email or contact us at help@munchmakers.com', 'vss'); ?>
                </p>
            </div>
            
            <p style="margin-top: 30px; font-size: 16px;">
                <?php _e('Thanks for your patience as we create something special just for you!', 'vss'); ?>
            </p>
            
            <p style="margin-top: 20px;">
                <?php _e('Best regards,', 'vss'); ?><br>
                <strong><?php _e('The MunchMakers Team', 'vss'); ?></strong>
            </p>
        </div>
        <?php
        $message = ob_get_clean();

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
        $tab_hash = ($type === 'mockup') ? '#tab-mockup-approval' : '#tab-prodfile-approval';

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; color: #333333;">
            <p style="font-size: 18px;"><?php printf(__('Hello %s,', 'vss'), esc_html($vendor_data->display_name)); ?></p>
            
            <div style="background-color: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h2 style="color: #155724; margin: 0;">✅ <?php _e('Approval Received!', 'vss'); ?></h2>
            </div>
            
            <p style="font-size: 16px; line-height: 1.6;">
                <?php printf(__('Great news! The %s you submitted for order <strong>#%s</strong> has been <strong>approved</strong> by the customer.', 'vss'), 
                    strtolower($type_label_uc), 
                    esc_html($order->get_order_number())
                ); ?>
            </p>
            
            <p style="margin-top: 20px;">
                <a href="<?php echo $order_portal_link . $tab_hash; ?>" style="display: inline-block; background-color: #28a745; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                    <?php _e('View Order Details', 'vss'); ?>
                </a>
            </p>
            
            <p style="margin-top: 30px; color: #666666;">
                <?php _e('Thank you for your excellent work!', 'vss'); ?>
            </p>
        </div>
        <?php
        $message = ob_get_clean();
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

        ob_start();
        ?>
        <div style="font-family: Arial, sans-serif; color: #333333;">
            <p style="font-size: 18px;"><?php _e('Hello Admin,', 'vss'); ?></p>
            
            <div style="background-color: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h2 style="color: #721c24; margin: 0;">⚠️ <?php _e('Customer Disapproval', 'vss'); ?></h2>
            </div>
            
            <p style="font-size: 16px; line-height: 1.6;">
                <?php printf(__('The %s for order <strong>#%s</strong> has been <strong>disapproved</strong> by the customer.', 'vss'), 
                    strtolower($type_label_uc), 
                    esc_html($order->get_order_number())
                ); ?>
            </p>
            
            <?php if ($customer_notes): ?>
                <div style="background-color: #fff3cd; border: 1px solid #ffeeba; padding: 15px; border-radius: 5px; margin: 20px 0;">
                    <h3 style="color: #856404; margin: 0 0 10px 0;"><?php _e('Customer Feedback:', 'vss'); ?></h3>
                    <p style="color: #856404; margin: 0;"><?php echo nl2br(esc_html($customer_notes)); ?></p>
                </div>
            <?php else: ?>
                <p style="background-color: #e9ecef; padding: 15px; border-radius: 5px;">
                    <?php _e('No specific feedback was provided. Please reach out to the customer for clarification.', 'vss'); ?>
                </p>
            <?php endif; ?>
            
            <h3 style="margin-top: 30px;"><?php _e('Recommended Actions:', 'vss'); ?></h3>
            <ol style="line-height: 1.8;">
                <li><?php _e('Contact the customer to understand their concerns', 'vss'); ?></li>
                <li><?php _e('Coordinate with the vendor for necessary revisions', 'vss'); ?></li>
                <li><?php _e('Submit revised files for approval', 'vss'); ?></li>
            </ol>
            
            <p style="margin-top: 20px;">
                <a href="<?php echo $edit_order_link; ?>" style="display: inline-block; background-color: #dc3545; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">
                    <?php _e('View Order in Admin', 'vss'); ?>
                </a>
            </p>
        </div>
        <?php
        $message = ob_get_clean();
        self::send_email($admin_email, $subject, $message);
    }
}

// Additional handler for approval landing page
class VSS_Approval_Page_Handler {
    
    public static function init() {
        add_action('init', [self::class, 'add_rewrite_rules']);
        add_filter('query_vars', [self::class, 'add_query_vars']);
        add_action('template_redirect', [self::class, 'handle_approval_page']);
        add_shortcode('vss_approval_handler', [self::class, 'render_approval_handler']);
    }
    
    public static function add_rewrite_rules() {
        add_rewrite_rule('^order-approval/?', 'index.php?vss_approval_page=1', 'top');
    }
    
    public static function add_query_vars($vars) {
        $vars[] = 'vss_approval_page';
        return $vars;
    }
    
    public static function handle_approval_page() {
        if (get_query_var('vss_approval_page')) {
            // Handle the approval action
            if (isset($_GET['action']) && isset($_GET['order_id'])) {
                self::process_approval_action();
            } else {
                // Show approval form page
                self::show_approval_form();
            }
            exit;
        }
    }
    
    public static function process_approval_action() {
        $action = sanitize_key($_GET['action']);
        $order_id = intval($_GET['order_id']);
        $status = isset($_GET['approval_status']) ? sanitize_key($_GET['approval_status']) : '';
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
        
        // Extract type from action
        $type = '';
        if (strpos($action, 'mockup') !== false) {
            $type = 'mockup';
        } elseif (strpos($action, 'production_file') !== false) {
            $type = 'production_file';
        }
        
        if (!$type || !in_array($status, ['approved', 'disapproved'])) {
            wp_die(__('Invalid approval request.', 'vss'));
        }
        
        // Verify nonce
        if (!wp_verify_nonce($nonce, "vss_{$status}_{$type}_{$order_id}")) {
            wp_die(__('Security check failed. This link may have expired.', 'vss'));
        }
        
        // Process the approval
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die(__('Order not found.', 'vss'));
        }
        
        // Update order meta
        update_post_meta($order_id, "_vss_{$type}_status", $status);
        update_post_meta($order_id, "_vss_{$type}_responded_at", time());
        
        // Show confirmation page
        self::show_confirmation_page($order, $type, $status);
    }
    
    public static function show_confirmation_page($order, $type, $status) {
        $type_label = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo sprintf(__('%s Response Recorded', 'vss'), $type_label); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background-color: #f5f5f5;
                    margin: 0;
                    padding: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                }
                .confirmation-container {
                    background: white;
                    padding: 40px;
                    border-radius: 10px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                    max-width: 500px;
                    text-align: center;
                }
                .status-icon {
                    font-size: 60px;
                    margin-bottom: 20px;
                }
                .status-approved { color: #4CAF50; }
                .status-disapproved { color: #f44336; }
                h1 { color: #333; margin-bottom: 20px; }
                p { color: #666; line-height: 1.6; margin-bottom: 20px; }
                .order-info {
                    background: #f9f9f9;
                    padding: 15px;
                    border-radius: 5px;
                    margin: 20px 0;
                }
                .feedback-form {
                    margin-top: 20px;
                    text-align: left;
                }
                .feedback-form textarea {
                    width: 100%;
                    padding: 10px;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                    min-height: 100px;
                }
                .feedback-form button {
                    background: #2271b1;
                    color: white;
                    border: none;
                    padding: 10px 30px;
                    border-radius: 5px;
                    cursor: pointer;
                    margin-top: 10px;
                }
            </style>
        </head>
        <body>
            <div class="confirmation-container">
                <?php if ($status === 'approved'): ?>
                    <div class="status-icon status-approved">✅</div>
                    <h1><?php _e('Thank You!', 'vss'); ?></h1>
                    <p><?php printf(__('You have successfully approved the %s for order #%s.', 'vss'), $type_label, $order->get_order_number()); ?></p>
                    <p><?php _e('We will proceed with your order immediately.', 'vss'); ?></p>
                <?php else: ?>
                    <div class="status-icon status-disapproved">❌</div>
                    <h1><?php _e('Changes Requested', 'vss'); ?></h1>
                    <p><?php printf(__('You have requested changes to the %s for order #%s.', 'vss'), $type_label, $order->get_order_number()); ?></p>
                    <p><?php _e('Our team will contact you shortly to discuss the changes needed.', 'vss'); ?></p>
                    
                    <div class="feedback-form">
                        <h3><?php _e('Additional Feedback (Optional)', 'vss'); ?></h3>
                        <form method="post" action="">
                            <textarea name="customer_feedback" placeholder="<?php _e('Please describe what changes you would like...', 'vss'); ?>"></textarea>
                            <button type="submit"><?php _e('Submit Feedback', 'vss'); ?></button>
                        </form>
                    </div>
                <?php endif; ?>
                
                <div class="order-info">
                    <strong><?php _e('Order Details:', 'vss'); ?></strong><br>
                    <?php _e('Order Number:', 'vss'); ?> #<?php echo esc_html($order->get_order_number()); ?><br>
                    <?php _e('Date:', 'vss'); ?> <?php echo esc_html($order->get_date_created()->date_i18n(wc_date_format())); ?>
                </div>
                
                <p style="font-size: 14px; color: #999;">
                    <?php _e('If you have any questions, please contact us at help@munchmakers.com', 'vss'); ?>
                </p>
            </div>
        </body>
        </html>
        <?php
    }
}