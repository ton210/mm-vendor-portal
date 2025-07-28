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
     * Handle vendor application with enhanced fields
     */
    private static function handle_vendor_application() {
        if ( ! isset( $_POST['vss_apply_vendor'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'vss_vendor_application' ) ) {
            return;
        }

        // Basic Information
        $company_name = sanitize_text_field( $_POST['vss_company_name'] );
        $contact_name = sanitize_text_field( $_POST['vss_contact_name'] );
        $email = sanitize_email( $_POST['vss_email'] );
        $phone = sanitize_text_field( $_POST['vss_phone'] );
        $wechat = sanitize_text_field( $_POST['vss_wechat'] );
        $password = $_POST['vss_password'];

        // Company Details
        $province = sanitize_text_field( $_POST['vss_province'] );
        $city = sanitize_text_field( $_POST['vss_city'] );
        $company_website = esc_url_raw( $_POST['vss_company_website'] );
        $alibaba_page = esc_url_raw( $_POST['vss_alibaba_page'] );

        // Business Information
        $business_type = sanitize_text_field( $_POST['vss_business_type'] );
        $main_products = sanitize_textarea_field( $_POST['vss_main_products'] );
        $production_capacity = sanitize_text_field( $_POST['vss_production_capacity'] );
        $years_in_business = intval( $_POST['vss_years_in_business'] );

        // Validation
        $errors = [];

        if ( empty( $company_name ) ) {
            $errors[] = '请填写公司名称';
        }

        if ( empty( $contact_name ) ) {
            $errors[] = '请填写联系人姓名';
        }

        if ( empty( $email ) || ! is_email( $email ) ) {
            $errors[] = '请填写有效的电子邮箱';
        }

        if ( empty( $password ) || strlen( $password ) < 6 ) {
            $errors[] = '密码至少需要6个字符';
        }

        if ( email_exists( $email ) ) {
            $errors[] = '该邮箱已被注册';
        }

        if ( ! empty( $errors ) ) {
            // Store errors in session or transient for display
            set_transient( 'vss_vendor_app_errors_' . session_id(), $errors, 60 );
            wp_safe_redirect( wp_get_referer() );
            exit;
        }

        // Create user account
        $user_id = wp_create_user( $email, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            set_transient( 'vss_vendor_app_errors_' . session_id(), ['创建账户失败，请稍后再试'], 60 );
            wp_safe_redirect( wp_get_referer() );
            exit;
        }

        // Update user information
        wp_update_user( [
            'ID' => $user_id,
            'display_name' => $company_name,
        ] );

        $user = new WP_User( $user_id );
        $user->set_role( 'vendor-mm' );

        // Save vendor metadata
        update_user_meta( $user_id, 'vss_company_name', $company_name );
        update_user_meta( $user_id, 'vss_contact_name', $contact_name );
        update_user_meta( $user_id, 'vss_phone', $phone );
        update_user_meta( $user_id, 'vss_wechat', $wechat );
        update_user_meta( $user_id, 'vss_province', $province );
        update_user_meta( $user_id, 'vss_city', $city );
        update_user_meta( $user_id, 'vss_company_website', $company_website );
        update_user_meta( $user_id, 'vss_alibaba_page', $alibaba_page );
        update_user_meta( $user_id, 'vss_business_type', $business_type );
        update_user_meta( $user_id, 'vss_main_products', $main_products );
        update_user_meta( $user_id, 'vss_production_capacity', $production_capacity );
        update_user_meta( $user_id, 'vss_years_in_business', $years_in_business );
        update_user_meta( $user_id, 'vss_application_date', current_time( 'mysql' ) );
        update_user_meta( $user_id, 'vss_vendor_status', 'pending' ); // Pending approval

        // Send notification email to admin
        $admin_email = get_option( 'admin_email' );
        $subject = '新供应商申请 - ' . $company_name;

        $message = "收到新的供应商申请：\n\n";
        $message .= "公司信息\n";
        $message .= "=================\n";
        $message .= "公司名称: {$company_name}\n";
        $message .= "联系人: {$contact_name}\n";
        $message .= "邮箱: {$email}\n";
        $message .= "电话: {$phone}\n";
        $message .= "微信: {$wechat}\n\n";

        $message .= "地址信息\n";
        $message .= "=================\n";
        $message .= "省份: {$province}\n";
        $message .= "城市: {$city}\n\n";

        $message .= "业务信息\n";
        $message .= "=================\n";
        $message .= "业务类型: {$business_type}\n";
        $message .= "主营产品: {$main_products}\n";
        $message .= "生产能力: {$production_capacity}\n";
        $message .= "经营年限: {$years_in_business} 年\n\n";

        if ( $company_website ) {
            $message .= "公司网站: {$company_website}\n";
        }
        if ( $alibaba_page ) {
            $message .= "阿里巴巴店铺: {$alibaba_page}\n";
        }

        $message .= "\n查看申请: " . admin_url( 'user-edit.php?user_id=' . $user_id );

        wp_mail( $admin_email, $subject, $message );

        // Send welcome email to vendor
        $vendor_subject = '欢迎申请成为供应商 - ' . get_bloginfo( 'name' );
        $vendor_message = "尊敬的 {$contact_name}，\n\n";
        $vendor_message .= "感谢您申请成为我们的供应商。\n\n";
        $vendor_message .= "我们已收到您的申请，正在进行审核。审核通过后，我们会通过邮件通知您。\n\n";
        $vendor_message .= "申请信息：\n";
        $vendor_message .= "公司名称: {$company_name}\n";
        $vendor_message .= "联系邮箱: {$email}\n\n";
        $vendor_message .= "如有任何问题，请随时与我们联系。\n\n";
        $vendor_message .= "此致\n";
        $vendor_message .= get_bloginfo( 'name' );

        wp_mail( $email, $vendor_subject, $vendor_message );

        // Log in the new vendor (optional - you might want to wait for approval)
        // wp_set_current_user( $user_id );
        // wp_set_auth_cookie( $user_id );

        // Redirect with success message
        set_transient( 'vss_vendor_app_success_' . session_id(), true, 60 );
        wp_safe_redirect( add_query_arg( 'application', 'submitted', home_url( '/vendor-application/' ) ) );
        exit;
    }

    /**
     * Display vendor application form
     */
    public static function render_vendor_application_form() {
        // Check if user is already logged in
        if ( is_user_logged_in() ) {
            echo '<div class="vss-notice">您已经登录，无需重新申请。</div>';
            return;
        }

        // Check for success message
        if ( isset( $_GET['application'] ) && $_GET['application'] === 'submitted' ) {
            ?>
            <div class="vss-success-message">
                <h2>申请已提交成功！</h2>
                <p>感谢您申请成为我们的供应商。我们会尽快审核您的申请并通过邮件通知您。</p>
                <p>审核时间通常为1-3个工作日。</p>
            </div>
            <?php
            return;
        }

        // Check for errors
        $errors = get_transient( 'vss_vendor_app_errors_' . session_id() );
        if ( $errors ) {
            delete_transient( 'vss_vendor_app_errors_' . session_id() );
        }
        ?>

        <div class="vss-vendor-application-form">
            <h2>供应商申请表</h2>
            <p class="form-description">请填写以下信息申请成为我们的合作供应商</p>

            <?php if ( ! empty( $errors ) ) : ?>
                <div class="vss-errors">
                    <?php foreach ( $errors as $error ) : ?>
                        <p class="error"><?php echo esc_html( $error ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" class="vss-vendor-form">
                <?php wp_nonce_field( 'vss_vendor_application' ); ?>
                <input type="hidden" name="vss_fe_action" value="vendor_application">

                <!-- 公司基本信息 -->
                <fieldset class="form-section">
                    <legend>公司基本信息</legend>

                    <div class="form-group">
                        <label for="vss_company_name">公司名称 <span class="required">*</span></label>
                        <input type="text" id="vss_company_name" name="vss_company_name" required
                               placeholder="请输入公司全称">
                    </div>

                    <div class="form-row">
                        <div class="form-group half">
                            <label for="vss_province">省份 <span class="required">*</span></label>
                            <select id="vss_province" name="vss_province" required>
                                <option value="">请选择省份</option>
                                <option value="北京">北京</option>
                                <option value="上海">上海</option>
                                <option value="天津">天津</option>
                                <option value="重庆">重庆</option>
                                <option value="广东">广东</option>
                                <option value="浙江">浙江</option>
                                <option value="江苏">江苏</option>
                                <option value="山东">山东</option>
                                <option value="河北">河北</option>
                                <option value="河南">河南</option>
                                <option value="湖北">湖北</option>
                                <option value="湖南">湖南</option>
                                <option value="福建">福建</option>
                                <option value="安徽">安徽</option>
                                <option value="四川">四川</option>
                                <option value="陕西">陕西</option>
                                <option value="辽宁">辽宁</option>
                                <option value="吉林">吉林</option>
                                <option value="黑龙江">黑龙江</option>
                                <option value="江西">江西</option>
                                <option value="山西">山西</option>
                                <option value="云南">云南</option>
                                <option value="贵州">贵州</option>
                                <option value="广西">广西</option>
                                <option value="海南">海南</option>
                                <option value="甘肃">甘肃</option>
                                <option value="青海">青海</option>
                                <option value="内蒙古">内蒙古</option>
                                <option value="新疆">新疆</option>
                                <option value="西藏">西藏</option>
                                <option value="宁夏">宁夏</option>
                                <option value="台湾">台湾</option>
                                <option value="香港">香港</option>
                                <option value="澳门">澳门</option>
                                <option value="其他">其他</option>
                            </select>
                        </div>

                        <div class="form-group half">
                            <label for="vss_city">城市 <span class="required">*</span></label>
                            <input type="text" id="vss_city" name="vss_city" required
                                   placeholder="例如：深圳">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group half">
                            <label for="vss_company_website">公司网站</label>
                            <input type="url" id="vss_company_website" name="vss_company_website"
                                   placeholder="https://www.example.com">
                        </div>

                        <div class="form-group half">
                            <label for="vss_alibaba_page">阿里巴巴店铺</label>
                            <input type="url" id="vss_alibaba_page" name="vss_alibaba_page"
                                   placeholder="https://shop.1688.com/...">
                        </div>
                    </div>
                </fieldset>

                <!-- 联系人信息 -->
                <fieldset class="form-section">
                    <legend>联系人信息</legend>

                    <div class="form-group">
                        <label for="vss_contact_name">联系人姓名 <span class="required">*</span></label>
                        <input type="text" id="vss_contact_name" name="vss_contact_name" required
                               placeholder="请输入您的姓名">
                    </div>

                    <div class="form-row">
                        <div class="form-group half">
                            <label for="vss_email">电子邮箱 <span class="required">*</span></label>
                            <input type="email" id="vss_email" name="vss_email" required
                                   placeholder="example@company.com">
                            <small>此邮箱将作为登录账号</small>
                        </div>

                        <div class="form-group half">
                            <label for="vss_password">设置密码 <span class="required">*</span></label>
                            <input type="password" id="vss_password" name="vss_password" required
                                   minlength="6" placeholder="至少6个字符">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group half">
                            <label for="vss_phone">联系电话 <span class="required">*</span></label>
                            <input type="tel" id="vss_phone" name="vss_phone" required
                                   placeholder="13800138000">
                        </div>

                        <div class="form-group half">
                            <label for="vss_wechat">微信号</label>
                            <input type="text" id="vss_wechat" name="vss_wechat"
                                   placeholder="您的微信号">
                        </div>
                    </div>
                </fieldset>

                <!-- 业务信息 -->
                <fieldset class="form-section">
                    <legend>业务信息</legend>

                    <div class="form-row">
                        <div class="form-group half">
                            <label for="vss_business_type">业务类型 <span class="required">*</span></label>
                            <select id="vss_business_type" name="vss_business_type" required>
                                <option value="">请选择</option>
                                <option value="制造商">制造商</option>
                                <option value="贸易商">贸易商</option>
                                <option value="制造商兼贸易商">制造商兼贸易商</option>
                                <option value="服务商">服务商</option>
                            </select>
                        </div>

                        <div class="form-group half">
                            <label for="vss_years_in_business">经营年限</label>
                            <select id="vss_years_in_business" name="vss_years_in_business">
                                <option value="0">少于1年</option>
                                <option value="1">1-2年</option>
                                <option value="3">3-5年</option>
                                <option value="6">6-10年</option>
                                <option value="11">10年以上</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="vss_main_products">主营产品 <span class="required">*</span></label>
                        <textarea id="vss_main_products" name="vss_main_products" rows="3" required
                                  placeholder="请描述您的主要产品类别，例如：服装定制、印刷品、包装材料等"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="vss_production_capacity">生产能力</label>
                        <input type="text" id="vss_production_capacity" name="vss_production_capacity"
                               placeholder="例如：日产量10000件">
                    </div>
                </fieldset>

                <div class="form-submit">
                    <button type="submit" name="vss_apply_vendor" class="submit-button">
                        提交申请
                    </button>
                    <p class="terms-notice">
                        提交申请即表示您同意我们的<a href="/terms" target="_blank">服务条款</a>
                    </p>
                </div>
            </form>
        </div>

        <style>
        .vss-vendor-application-form {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .vss-vendor-application-form h2 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .form-description {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }

        .vss-errors {
            background: #fee;
            border: 1px solid #fcc;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .vss-errors .error {
            color: #c00;
            margin: 5px 0;
        }

        .vss-success-message {
            background: #e8f5e9;
            border: 1px solid #4caf50;
            padding: 30px;
            text-align: center;
            border-radius: 4px;
            margin: 20px auto;
            max-width: 600px;
        }

        .vss-success-message h2 {
            color: #2e7d32;
            margin-bottom: 15px;
        }

        .form-section {
            border: 1px solid #e0e0e0;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 6px;
            background: #fafafa;
        }

        .form-section legend {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            padding: 0 10px;
            background: #fafafa;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }

        .form-group .required {
            color: #e74c3c;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 15px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 0 2px rgba(76, 175, 80, 0.1);
        }

        .form-group small {
            display: block;
            margin-top: 5px;
            color: #999;
            font-size: 13px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group.half {
            flex: 1;
            margin-bottom: 0;
        }

        .form-submit {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .submit-button {
            background: #4CAF50;
            color: white;
            padding: 12px 40px;
            border: none;
            border-radius: 4px;
            font-size: 18px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .submit-button:hover {
            background: #45a049;
        }

        .terms-notice {
            margin-top: 15px;
            font-size: 14px;
            color: #666;
        }

        .terms-notice a {
            color: #4CAF50;
            text-decoration: none;
        }

        .terms-notice a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
            }

            .form-group.half {
                width: 100%;
            }
        }
        </style>
        <?php
    }
}