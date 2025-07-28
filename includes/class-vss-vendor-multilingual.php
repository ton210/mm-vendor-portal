<?php
/**
 * VSS Vendor Multilingual Support
 * * Adds English and Simplified Chinese support for vendor interface
 * with automatic browser detection and manual switching
 * * @package VendorOrderManager
 * @since 7.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_Vendor_Multilingual {

    /**
     * Supported languages
     */
    const SUPPORTED_LANGUAGES = [
        'en' => 'English',
        'zh_CN' => '简体中文'
    ];

    /**
     * Initialize multilingual support
     */
    public static function init() {
        // Add language detection and switching
        add_action( 'init', [ self::class, 'set_vendor_language' ], 1 );

        // Add floating language switcher to vendor pages
        add_action( 'wp_footer', [ self::class, 'render_floating_language_switcher' ] );
        add_action( 'admin_footer', [ self::class, 'render_floating_language_switcher' ] );

        // Add language switcher to vendor pages
        add_action( 'vss_vendor_navigation_end', [ self::class, 'render_language_switcher' ] );
        add_action( 'admin_bar_menu', [ self::class, 'add_admin_bar_language_switcher' ], 999 );

        // Load translations
        add_action( 'init', [ self::class, 'load_vendor_translations' ], 5 );

        // AJAX handler for language switching
        add_action( 'wp_ajax_vss_switch_language', [ self::class, 'ajax_switch_language' ] );
        add_action( 'wp_ajax_nopriv_vss_switch_language', [ self::class, 'ajax_switch_language' ] );

        // Add translation filters
        add_filter( 'gettext', [ self::class, 'filter_vendor_translations' ], 10, 3 );
        add_filter( 'ngettext', [ self::class, 'filter_vendor_translations_plural' ], 10, 5 );

        // Enqueue language scripts
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_language_scripts' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_language_scripts' ] );
    }

    /**
     * Set vendor language based on browser or user preference
     */
    public static function set_vendor_language() {
        if ( ! self::is_vendor_page() ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id || ! self::is_user_vendor( $user_id ) ) {
            return;
        }

        // Check if user has manually selected a language
        $saved_language = get_user_meta( $user_id, 'vss_preferred_language', true );

        if ( ! $saved_language ) {
            // Detect browser language
            $saved_language = self::detect_browser_language();
            update_user_meta( $user_id, 'vss_preferred_language', $saved_language );
        }

        // Set WordPress locale for this session
        if ( $saved_language && $saved_language !== get_locale() ) {
            switch_to_locale( $saved_language );
        }

        // Store in session for quick access
        if ( ! session_id() ) {
            session_start();
        }
        $_SESSION['vss_vendor_language'] = $saved_language;
    }

    /**
     * Detect browser language
     */
    private static function detect_browser_language() {
        $browser_lang = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';

        // Check for Chinese
        if ( stripos( $browser_lang, 'zh' ) !== false ) {
            return 'zh_CN';
        }

        // Default to English
        return 'en_US';
    }

    /**
     * Check if current page is vendor page
     */
    private static function is_vendor_page() {
        // Frontend vendor portal
        if ( isset( $_GET['vss_action'] ) || strpos( $_SERVER['REQUEST_URI'], 'vendor-portal' ) !== false ) {
            return true;
        }

        // Admin vendor pages - more comprehensive check
        if ( is_admin() ) {
            $user_id = get_current_user_id();
            if ( $user_id && self::is_user_vendor( $user_id ) ) {
                return true;
            }
        }

        // Check if on vendor-specific pages
        global $pagenow;
        if ( in_array( $pagenow, ['index.php', 'admin.php', 'edit.php', 'post.php', 'post-new.php'] ) ) {
            $user_id = get_current_user_id();
            if ( $user_id && self::is_user_vendor( $user_id ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user is vendor
     */
    private static function is_user_vendor( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        return $user && in_array( 'vendor-mm', (array) $user->roles, true );
    }

    /**
     * Load vendor translations
     */
    public static function load_vendor_translations() {
        if ( ! self::is_vendor_page() ) {
            return;
        }

        $current_language = self::get_current_language();

        // Load custom translation file if Chinese
        if ( $current_language === 'zh_CN' ) {
            load_textdomain( 'vss', VSS_PLUGIN_DIR . 'languages/vss-zh_CN.mo' );
        }
    }

    /**
     * Get current language
     */
    public static function get_current_language() {
        if ( isset( $_SESSION['vss_vendor_language'] ) ) {
            return $_SESSION['vss_vendor_language'];
        }

        $user_id = get_current_user_id();
        if ( $user_id ) {
            return get_user_meta( $user_id, 'vss_preferred_language', true ) ?: 'en_US';
        }

        return 'en_US';
    }

    /**
     * Render floating language switcher
     */
    public static function render_floating_language_switcher() {
        if ( ! self::is_vendor_page() || ! self::is_user_vendor( get_current_user_id() ) ) {
            return;
        }

        $current_language = self::get_current_language();
        $is_chinese = $current_language === 'zh_CN';
        ?>
        <div id="vss-floating-language-switcher" class="vss-floating-lang-switcher">
            <div class="vss-lang-current" onclick="toggleLanguageMenu()">
                <span class="vss-lang-icon">🌐</span>
                <span class="vss-lang-text"><?php echo $is_chinese ? '中文' : 'EN'; ?></span>
                <span class="vss-lang-arrow">▼</span>
            </div>
            <div class="vss-lang-menu" id="vss-lang-menu">
                <a href="#" class="vss-lang-option <?php echo !$is_chinese ? 'active' : ''; ?>" onclick="vssSetLanguage('en'); return false;">
                    <span class="vss-lang-flag">🇺🇸</span> English
                </a>
                <a href="#" class="vss-lang-option <?php echo $is_chinese ? 'active' : ''; ?>" onclick="vssSetLanguage('zh_CN'); return false;">
                    <span class="vss-lang-flag">🇨🇳</span> 简体中文
                </a>
            </div>
        </div>

        <style>
            .vss-floating-lang-switcher {
                position: fixed;
                bottom: 30px;
                right: 30px;
                z-index: 999999;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }

            .vss-lang-current {
                background: #2271b1;
                color: white;
                padding: 12px 20px;
                border-radius: 30px;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                transition: all 0.3s ease;
                font-size: 14px;
                font-weight: 500;
            }

            .vss-lang-current:hover {
                background: #135e96;
                box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
                transform: translateY(-2px);
            }

            .vss-lang-icon {
                font-size: 18px;
            }

            .vss-lang-arrow {
                font-size: 10px;
                transition: transform 0.3s ease;
            }

            .vss-lang-menu {
                position: absolute;
                bottom: 60px;
                right: 0;
                background: white;
                border-radius: 12px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
                overflow: hidden;
                opacity: 0;
                visibility: hidden;
                transform: translateY(10px);
                transition: all 0.3s ease;
                min-width: 180px;
            }

            .vss-lang-menu.active {
                opacity: 1;
                visibility: visible;
                transform: translateY(0);
            }

            .vss-lang-menu.active ~ .vss-lang-current .vss-lang-arrow {
                transform: rotate(180deg);
            }

            .vss-lang-option {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 14px 20px;
                color: #333;
                text-decoration: none;
                transition: all 0.2s ease;
                font-size: 14px;
                border-bottom: 1px solid #f0f0f0;
            }

            .vss-lang-option:last-child {
                border-bottom: none;
            }

            .vss-lang-option:hover {
                background: #f5f5f5;
                color: #2271b1;
            }

            .vss-lang-option.active {
                background: #e8f4fd;
                color: #2271b1;
                font-weight: 600;
            }

            .vss-lang-flag {
                font-size: 20px;
            }

            /* Mobile adjustments */
            @media (max-width: 768px) {
                .vss-floating-lang-switcher {
                    bottom: 20px;
                    right: 20px;
                }

                .vss-lang-current {
                    padding: 10px 16px;
                    font-size: 13px;
                }

                .vss-lang-menu {
                    min-width: 160px;
                }

                .vss-lang-option {
                    padding: 12px 16px;
                    font-size: 13px;
                }
            }

            /* Dark mode support for admin */
            body.admin-color-midnight .vss-lang-menu,
            body.admin-color-ocean .vss-lang-menu,
            body.admin-color-nightfall .vss-lang-menu {
                background: #1e1e1e;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
            }

            body.admin-color-midnight .vss-lang-option,
            body.admin-color-ocean .vss-lang-option,
            body.admin-color-nightfall .vss-lang-option {
                color: #ccc;
                border-bottom-color: #333;
            }

            body.admin-color-midnight .vss-lang-option:hover,
            body.admin-color-ocean .vss-lang-option:hover,
            body.admin-color-nightfall .vss-lang-option:hover {
                background: #2a2a2a;
                color: #fff;
            }

            body.admin-color-midnight .vss-lang-option.active,
            body.admin-color-ocean .vss-lang-option.active,
            body.admin-color-nightfall .vss-lang-option.active {
                background: #2271b1;
                color: #fff;
            }
        </style>

        <script>
            function toggleLanguageMenu() {
                var menu = document.getElementById('vss-lang-menu');
                menu.classList.toggle('active');
            }

            // Close menu when clicking outside
            document.addEventListener('click', function(event) {
                var switcher = document.getElementById('vss-floating-language-switcher');
                var menu = document.getElementById('vss-lang-menu');

                if (!switcher.contains(event.target)) {
                    menu.classList.remove('active');
                }
            });
        </script>
        <?php
    }

    /**
     * Render language switcher
     */
    public static function render_language_switcher() {
        if ( ! self::is_user_vendor( get_current_user_id() ) ) {
            return;
        }

        $current_language = self::get_current_language();
        $current_code = $current_language === 'zh_CN' ? 'zh_CN' : 'en';
        ?>
        <div class="vss-language-switcher">
            <select id="vss-language-select" class="vss-language-dropdown">
                <?php foreach ( self::SUPPORTED_LANGUAGES as $code => $name ) : ?>
                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_code, $code ); ?>>
                        <?php echo esc_html( $name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    /**
     * Add language switcher to admin bar
     */
    public static function add_admin_bar_language_switcher( $wp_admin_bar ) {
        if ( ! is_admin() || ! self::is_user_vendor( get_current_user_id() ) ) {
            return;
        }

        $current_language = self::get_current_language();
        $current_code = $current_language === 'zh_CN' ? 'zh_CN' : 'en';

        $wp_admin_bar->add_node( [
            'id' => 'vss-language',
            'title' => '🌐 ' . self::SUPPORTED_LANGUAGES[$current_code],
            'href' => '#',
            'meta' => [
                'class' => 'vss-admin-bar-language'
            ]
        ] );

        foreach ( self::SUPPORTED_LANGUAGES as $code => $name ) {
            $wp_admin_bar->add_node( [
                'parent' => 'vss-language',
                'id' => 'vss-lang-' . $code,
                'title' => $name,
                'href' => '#',
                'meta' => [
                    'onclick' => "vssSetLanguage('" . esc_js( $code ) . "'); return false;"
                ]
            ] );
        }
    }

    /**
     * AJAX handler for language switching
     */
    public static function ajax_switch_language() {
        // Check nonce if provided
        if ( isset( $_POST['nonce'] ) ) {
            check_ajax_referer( 'vss_frontend_nonce', 'nonce' );
        }

        $language = isset( $_POST['language'] ) ? sanitize_text_field( $_POST['language'] ) : 'en';
        $user_id = get_current_user_id();

        if ( ! $user_id || ! self::is_user_vendor( $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        // Map language codes
        $locale = $language === 'zh_CN' ? 'zh_CN' : 'en_US';

        // Save user preference
        update_user_meta( $user_id, 'vss_preferred_language', $locale );

        // Update session
        if ( ! session_id() ) {
            session_start();
        }
        $_SESSION['vss_vendor_language'] = $locale;

        wp_send_json_success( [
            'message' => $language === 'zh_CN' ? '语言已切换' : 'Language switched',
            'reload' => true
        ] );
    }

    /**
     * Enqueue language scripts
     */
    public static function enqueue_language_scripts() {
        if ( ! self::is_vendor_page() ) {
            return;
        }

        // Create nonce for AJAX
        $nonce = wp_create_nonce( 'vss_frontend_nonce' );

        wp_add_inline_script( 'jquery', '
            function vssSetLanguage(language) {
                jQuery.ajax({
                    url: "' . admin_url( 'admin-ajax.php' ) . '",
                    type: "POST",
                    data: {
                        action: "vss_switch_language",
                        language: language,
                        nonce: "' . $nonce . '"
                    },
                    success: function(response) {
                        if (response.success && response.data.reload) {
                            location.reload();
                        }
                    },
                    error: function() {
                        alert("Language switch failed. Please try again.");
                    }
                });
            }

            jQuery(document).ready(function($) {
                $("#vss-language-select").on("change", function() {
                    vssSetLanguage($(this).val());
                });
            });
        ' );

        wp_add_inline_style( 'vss-frontend-styles', '
            .vss-language-switcher {
                margin-left: auto;
                padding: 0 20px;
            }

            .vss-language-dropdown {
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.3);
                color: #333;
                padding: 8px 15px;
                border-radius: 6px;
                font-size: 14px;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .vss-vendor-navigation .vss-language-dropdown {
                color: white;
            }

            .vss-language-dropdown:hover {
                background: rgba(255, 255, 255, 0.2);
                border-color: rgba(255, 255, 255, 0.5);
            }

            .vss-language-dropdown:focus {
                outline: none;
                box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.2);
            }

            @media (max-width: 768px) {
                .vss-language-switcher {
                    width: 100%;
                    padding: 10px 0;
                    order: -1;
                }

                .vss-language-dropdown {
                    width: 100%;
                }
            }
        ' );

        // Add admin styles if in admin area
        if ( is_admin() ) {
            wp_add_inline_style( 'admin-bar', '
                #wpadminbar .vss-admin-bar-language > a {
                    padding: 0 10px !important;
                }
            ' );
        }
    }

    /**
     * Filter translations for vendor pages
     */
    public static function filter_vendor_translations( $translated, $original, $domain ) {
        if ( ! self::is_vendor_page() || $domain !== 'vss' ) {
            return $translated;
        }

        $current_language = self::get_current_language();
        if ( $current_language !== 'zh_CN' ) {
            return $translated;
        }

        // Get Chinese translations
        $translations = self::get_chinese_translations();

        return isset( $translations[$original] ) ? $translations[$original] : $translated;
    }

    /**
     * Filter plural translations
     */
    public static function filter_vendor_translations_plural( $translated, $single, $plural, $number, $domain ) {
        if ( ! self::is_vendor_page() || $domain !== 'vss' ) {
            return $translated;
        }

        $current_language = self::get_current_language();
        if ( $current_language !== 'zh_CN' ) {
            return $translated;
        }

        // Get Chinese translations
        $translations = self::get_chinese_translations();

        // For Chinese, we typically don't have plural forms
        $key = $number === 1 ? $single : $plural;
        return isset( $translations[$key] ) ? $translations[$key] : $translated;
    }

    /**
     * Get Chinese translations
     */
    private static function get_chinese_translations() {
        return [
            // Navigation
            'Dashboard' => '控制面板',
            'My Orders' => '我的订单',
            'Reports' => '报告',
            'Settings' => '设置',
            'Logout' => '退出',
            'Go to Portal' => '前往门户',
            'Media' => '媒体',

            // Dashboard
            'Welcome back, %s!' => '欢迎回来，%s！',
            'Here\'s what\'s happening with your orders today.' => '以下是您今天的订单情况。',
            'Orders in Processing' => '处理中的订单',
            'Orders Late' => '延迟订单',
            'Shipped This Month' => '本月已发货',
            'Earnings This Month' => '本月收入',

            // Quick Actions
            'Quick Actions' => '快速操作',
            'View All Orders' => '查看所有订单',
            'Upload Files' => '上传文件',
            'View Reports' => '查看报告',
            'Contact Support' => '联系支持',

            // Recent Orders
            'Recent Orders' => '最近订单',
            'View all orders →' => '查看所有订单 →',
            'Order' => '订单',
            'Date' => '日期',
            'Status' => '状态',
            'Customer' => '客户',
            'Items' => '项目',
            'Ship Date' => '发货日期',
            'Actions' => '操作',
            'View' => '查看',
            'No orders yet' => '暂无订单',
            'Your orders will appear here once you receive them.' => '收到订单后将在此显示。',

            // Order Status
            'Processing' => '处理中',
            'Shipped' => '已发货',
            'Completed' => '已完成',
            'Pending' => '待处理',
            'All' => '全部',
            'LATE' => '延迟',

            // Order Details
            'Order #%s' => '订单 #%s',
            'Back to Orders' => '返回订单列表',
            'Order Overview' => '订单概览',
            'Order Information' => '订单信息',
            'Order Number:' => '订单号：',
            'Date Created:' => '创建日期：',
            'Status:' => '状态：',
            'Estimated Ship Date:' => '预计发货日期：',
            'Customer Information' => '客户信息',
            'Name:' => '姓名：',
            'Email:' => '邮箱：',
            'Phone:' => '电话：',
            'Shipping Address' => '送货地址',

            // Production Confirmation
            'Confirm Production' => '确认生产',
            'Please confirm that you can fulfill this order and provide an estimated ship date.' => '请确认您可以完成此订单并提供预计发货日期。',
            'Production Confirmed' => '生产已确认',
            'Estimated ship date: %s' => '预计发货日期：%s',

            // Order Items
            'Order Items' => '订单项目',
            'Product' => '产品',
            'SKU' => 'SKU',
            'Quantity' => '数量',
            'Design Files' => '设计文件',
            'Download Zakeke Files' => '下载 Zakeke 文件',
            'Fetch Zakeke Files' => '获取 Zakeke 文件',
            'Download Admin ZIP' => '下载管理员 ZIP',
            'No design files' => '无设计文件',

            // Costs
            'Costs & Earnings' => '成本与收入',
            'Order Costs' => '订单成本',
            'Materials:' => '材料：',
            'Labor:' => '人工：',
            'Shipping:' => '运费：',
            'Other:' => '其他：',
            'Total Cost:' => '总成本：',
            'Edit Costs' => '编辑成本',
            'Save Costs' => '保存成本',
            'Cancel' => '取消',
            'No cost information has been entered yet.' => '尚未输入成本信息。',
            'Enter your costs for this order. This information helps track profitability.' => '输入此订单的成本。此信息有助于跟踪盈利能力。',
            'Material Cost' => '材料成本',
            'Labor Cost' => '人工成本',
            'Shipping Cost' => '运费成本',
            'Other Costs' => '其他成本',
            'Cost Notes (optional):' => '成本备注（可选）：',

            // Shipping & Tracking
            'Shipping & Tracking' => '运输与跟踪',
            'Shipping Information' => '运输信息',
            'Tracking Number:' => '跟踪号：',
            'Carrier:' => '承运人：',
            'Shipped Date:' => '发货日期：',
            'Edit Tracking Info' => '编辑跟踪信息',
            'Shipping Carrier:' => '运输公司：',
            '— Select Carrier —' => '— 选择承运人 —',
            'United States' => '美国',
            'International' => '国际',
            'Other' => '其他',
            'Enter tracking number' => '输入跟踪号',
            'Enter the complete tracking number provided by the carrier' => '输入承运人提供的完整跟踪号',
            'Note:' => '注意：',
            'Adding tracking information will mark this order as "Shipped".' => '添加跟踪信息将把此订单标记为"已发货"。',
            'This will update the tracking information and notify the customer.' => '这将更新跟踪信息并通知客户。',
            'Save Tracking & Mark as Shipped' => '保存跟踪并标记为已发货',
            'Update Tracking Info' => '更新跟踪信息',
            'No tracking information available yet.' => '暂无跟踪信息。',
            'Track Package' => '跟踪包裹',

            // Approvals
            'Mockup Approval' => '样品审批',
            'Production Files Approval' => '生产文件审批',
            'Not Submitted' => '未提交',
            'Pending Review' => '待审核',
            'Approved' => '已批准',
            'Changes Requested' => '需要修改',
            'Submitted Files:' => '已提交文件：',
            'Waiting for customer review. You will be notified once they respond.' => '等待客户审核。客户回复后您将收到通知。',
            '%s has been approved by the customer!' => '%s已获客户批准！',
            'Customer requested changes:' => '客户要求修改：',
            'No specific feedback provided.' => '未提供具体反馈。',
            'Upload Revised %s:' => '上传修改后的%s：',
            'Upload %s for Approval:' => '上传%s供审批：',
            'Click to select files or drag and drop' => '点击选择文件或拖放',
            'Accepted: JPG, PNG, GIF, PDF (Max 10MB each)' => '接受：JPG、PNG、GIF、PDF（每个最大10MB）',
            'Notes for customer (optional):' => '给客户的备注（可选）：',
            'Add any notes or instructions for the customer...' => '添加给客户的任何备注或说明...',
            'Send %s for Approval' => '发送%s供审批',
            'The customer will receive an email notification to review and approve.' => '客户将收到电子邮件通知以审核和批准。',
            '%s can only be uploaded when the order is in processing status.' => '%s只能在订单处理中时上传。',
            'Current status: %s' => '当前状态：%s',

            // Files
            'Files & Mockups' => '文件与样品',
            'Order Files' => '订单文件',
            'Mockup Files' => '样品文件',
            'Production Files' => '生产文件',
            'No design files available.' => '无可用设计文件。',
            'No mockup files uploaded yet.' => '尚未上传样品文件。',
            'No production files uploaded yet.' => '尚未上传生产文件。',
            'Admin Uploaded ZIP:' => '管理员上传的ZIP：',
            'Download ZIP File' => '下载ZIP文件',

            // Notes
            'Order Notes' => '订单备注',
            'Add Note:' => '添加备注：',
            'Add a note about this order...' => '添加关于此订单的备注...',
            'Add Note' => '添加备注',

            // Status Bar
            'Order Received' => '已接收订单',
            'In Production' => '生产中',
            'Delivered' => '已送达',

            // Search & Filter
            'Search orders, customers, emails, SKUs...' => '搜索订单、客户、邮箱、SKU...',
            'All Fields' => '所有字段',
            'Order Number' => '订单号',
            'Customer Name' => '客户姓名',
            'Customer Email' => '客户邮箱',
            'Product SKU' => '产品SKU',
            'Search' => '搜索',
            'Advanced Filters' => '高级筛选',
            'Date Range' => '日期范围',
            'From' => '从',
            'To' => '至',
            'Customer Location' => '客户位置',
            'All Countries' => '所有国家',
            'All States' => '所有州',
            'Apply Filters' => '应用筛选',
            'Clear Filters' => '清除筛选',
            'Showing %1$d-%2$d of %3$d orders' => '显示 %3$d 个订单中的 %1$d-%2$d',

            // Notifications
            'Costs saved successfully!' => '成本保存成功！',
            'Tracking information saved and order marked as shipped!' => '跟踪信息已保存，订单已标记为已发货！',
            'Note added successfully!' => '备注添加成功！',
            'Production confirmed and estimated ship date updated!' => '生产已确认，预计发货日期已更新！',
            'Mockup sent for customer approval!' => '样品已发送供客户审批！',
            'Production files sent for customer approval!' => '生产文件已发送供客户审批！',
            'Settings saved successfully!' => '设置保存成功！',
            'File uploaded successfully!' => '文件上传成功！',
            'Estimated ship date is required.' => '需要预计发货日期。',
            'Invalid date format. Please use YYYY-MM-DD.' => '日期格式无效。请使用 YYYY-MM-DD。',
            'File upload failed. Please try again.' => '文件上传失败。请重试。',
            'No files were uploaded. Please select at least one file.' => '未上传文件。请至少选择一个文件。',
            'Invalid approval type specified.' => '指定的审批类型无效。',
            'Permission denied.' => '权限被拒绝。',
            'Invalid order or you do not have permission to view it.' => '无效订单或您无权查看。',
            'Order not found or you do not have permission to view it.' => '未找到订单或您无权查看。',

            // Buttons
            'Submit' => '提交',
            'Save' => '保存',
            'Update' => '更新',
            'Edit' => '编辑',
            'Delete' => '删除',
            'Download' => '下载',
            'Upload' => '上传',
            'Approve' => '批准',
            'Reject' => '拒绝',
            'Confirm' => '确认',
            'Close' => '关闭',
            'Previous' => '上一个',
            'Next' => '下一个',

            // Time periods
            'Today' => '今天',
            'Yesterday' => '昨天',
            'This Week' => '本周',
            'Last Week' => '上周',
            'This Month' => '本月',
            'Last Month' => '上月',
            'This Year' => '今年',
            'Last Year' => '去年',

            // Common phrases
            'Yes' => '是',
            'No' => '否',
            'None' => '无',
            'Unknown' => '未知',
            'N/A' => '不适用',
            'Total' => '总计',
            'Subtotal' => '小计',
            'Loading...' => '加载中...',
            'Please wait...' => '请稍候...',
            'Are you sure?' => '您确定吗？',
            'Success!' => '成功！',
            'Error!' => '错误！',
            'Warning!' => '警告！',
            'Info' => '信息',

            // Add more translations as needed
        ];
    }
}