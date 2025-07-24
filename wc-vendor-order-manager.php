<?php
/**
 * Plugin Name: Vendor Order Manager
 * Plugin URI: https://example.com/vendor-order-manager
 * Description: Comprehensive vendor management system for WooCommerce with approval workflows
 * Version: 7.0.1
 * Author: Your Company
 * Author URI: https://example.com
 * Text Domain: vss
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package VendorOrderManager
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'VSS_VERSION', '7.0.1' );
define( 'VSS_PLUGIN_FILE', __FILE__ );
define( 'VSS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'VSS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

// Debug mode
if ( ! defined( 'VSS_DEBUG' ) ) {
    define( 'VSS_DEBUG', WP_DEBUG );
}

/**
 * Main plugin class
 */
class Vendor_Order_Manager {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Check dependencies
        add_action( 'admin_init', [ $this, 'check_dependencies' ] );

        // Load plugin
        add_action( 'plugins_loaded', [ $this, 'init' ], 0 );

        // Activation/Deactivation hooks
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );

        // Add plugin action links
        add_filter( 'plugin_action_links_' . VSS_PLUGIN_BASENAME, [ $this, 'add_action_links' ] );

        // Early script loading for vendors
        add_action( 'init', [ $this, 'early_init' ], 5 );
    }

    /**
     * Early initialization
     */
    public function early_init() {
        // Register custom order statuses early
        $this->register_order_statuses();

        // Setup rewrite rules
        $this->add_rewrite_rules();
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'woocommerce_missing_notice' ] );
            return;
        }

        // Load text domain
        load_plugin_textdomain( 'vss', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        // Include required files
        $this->includes();

        // Initialize components
        $this->init_components();

        // Hook into WordPress
        $this->init_hooks();

        // Log initialization
        $this->log( 'Plugin initialized successfully', 'info' );
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once VSS_PLUGIN_DIR . 'includes/class-vss-setup.php';
        require_once VSS_PLUGIN_DIR . 'includes/class-vss-admin.php';
        require_once VSS_PLUGIN_DIR . 'includes/class-vss-vendor.php';
        require_once VSS_PLUGIN_DIR . 'includes/class-vss-emails.php';

        // Optional components
        if ( file_exists( VSS_PLUGIN_DIR . 'includes/class-vss-customer.php' ) ) {
            require_once VSS_PLUGIN_DIR . 'includes/class-vss-customer.php';
        }

        if ( file_exists( VSS_PLUGIN_DIR . 'includes/class-vss-notifications.php' ) ) {
            require_once VSS_PLUGIN_DIR . 'includes/class-vss-notifications.php';
        }

        if ( file_exists( VSS_PLUGIN_DIR . 'includes/class-vss-zakeke-api.php' ) ) {
            require_once VSS_PLUGIN_DIR . 'includes/class-vss-zakeke-api.php';
        }

        if ( file_exists( VSS_PLUGIN_DIR . 'includes/class-vss-external-orders.php' ) ) {
            require_once VSS_PLUGIN_DIR . 'includes/class-vss-external-orders.php';
        }
    }

    /**
     * Initialize components
     */
    private function init_components() {
        // Core components - Initialize in specific order
        VSS_Setup::init();
        VSS_Admin::init();
        VSS_Vendor::init();
        VSS_Emails::init();

        // Optional components
        if ( class_exists( 'VSS_Customer' ) ) {
            VSS_Customer::init();
        }

        if ( class_exists( 'VSS_Notifications' ) ) {
            VSS_Notifications::init();
        }

        if ( class_exists( 'VSS_Zakeke_API' ) ) {
            VSS_Zakeke_API::init();
        }

        if ( class_exists( 'VSS_External_Orders' ) ) {
            VSS_External_Orders::init();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Scripts and styles
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // Body classes
        add_filter( 'body_class', [ $this, 'add_body_classes' ] );

        // Cron schedules
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );

        // Daily tasks
        add_action( 'vss_daily_tasks', [ $this, 'run_daily_tasks' ] );

        // Admin init redirect
        add_action( 'admin_init', [ $this, 'activation_redirect' ] );

        // AJAX nonce for logged out users
        add_action( 'wp_head', [ $this, 'add_ajax_nonce' ] );
    }

    /**
     * Enqueue frontend assets with improved vendor detection
     */
    public function enqueue_frontend_assets() {
        // Always load global styles
        wp_enqueue_style(
            'vss-global',
            VSS_PLUGIN_URL . 'assets/css/vss-global.css',
            [],
            VSS_VERSION
        );

        // Check if we should load vendor-specific assets
        if ( $this->should_load_vendor_assets() ) {
            $this->enqueue_vendor_assets();
        }

        // Check if we should load customer-specific assets
        if ( $this->should_load_customer_assets() ) {
            $this->enqueue_customer_assets();
        }
    }

    /**
     * Improved check for vendor asset loading
     */
    private function should_load_vendor_assets() {
        // Priority 1: Check if current user is vendor
        if ( $this->is_current_user_vendor() ) {
            // Check URL patterns
            $current_url = $_SERVER['REQUEST_URI'];
            if ( strpos( $current_url, 'vendor-portal' ) !== false ||
                 isset( $_GET['vss_action'] ) ||
                 strpos( $current_url, 'vss_' ) !== false ) {
                return true;
            }
        }

        // Priority 2: Check if on vendor portal page
        if ( is_page() ) {
            global $post;

            // Check by page ID
            $vendor_portal_page_id = get_option( 'vss_vendor_portal_page_id' );
            if ( $vendor_portal_page_id && $post && $post->ID == $vendor_portal_page_id ) {
                return true;
            }

            // Check for shortcode
            if ( $post && has_shortcode( $post->post_content, 'vss_vendor_portal' ) ) {
                return true;
            }

            // Check for other vendor shortcodes
            $vendor_shortcodes = [ 'vss_vendor_stats', 'vss_vendor_earnings', 'vss_vendor_orders' ];
            foreach ( $vendor_shortcodes as $shortcode ) {
                if ( $post && has_shortcode( $post->post_content, $shortcode ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if we should load customer assets
     */
    private function should_load_customer_assets() {
        if ( ! is_page() ) {
            return false;
        }

        global $post;
        if ( ! $post ) {
            return false;
        }

        // Check for customer shortcodes
        $customer_shortcodes = [ 'vss_customer_approval', 'vss_track_order', 'vss_approval_handler' ];
        foreach ( $customer_shortcodes as $shortcode ) {
            if ( has_shortcode( $post->post_content, $shortcode ) ) {
                return true;
            }
        }

        // Check if on customer pages
        $customer_pages = [
            get_option( 'vss_customer_approval_page_id' ),
            get_option( 'vss_track_order_page_id' ),
        ];

        return in_array( $post->ID, array_filter( $customer_pages ) );
    }

    /**
     * Enqueue vendor-specific assets
     */
    private function enqueue_vendor_assets() {
        // jQuery and jQuery UI
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'jquery-ui-core' );
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_script( 'jquery-ui-tabs' );

        // jQuery UI styles
        wp_enqueue_style(
            'jquery-ui-style',
            'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css',
            [],
            '1.12.1'
        );

        // Frontend styles
        wp_enqueue_style(
            'vss-frontend-styles',
            VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css',
            [ 'vss-global' ],
            VSS_VERSION
        );

        // Frontend scripts
        wp_enqueue_script(
            'vss-frontend',
            VSS_PLUGIN_URL . 'assets/js/vss-frontend-scripts.js',
            [ 'jquery', 'jquery-ui-datepicker', 'jquery-ui-tabs' ],
            VSS_VERSION,
            true
        );

        // Localize script with comprehensive data
        wp_localize_script( 'vss-frontend', 'vss_frontend_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'vss_frontend_nonce' ),
            'debug' => VSS_DEBUG,
            'version' => VSS_VERSION,
            'plugin_url' => VSS_PLUGIN_URL,
            'is_vendor' => $this->is_current_user_vendor(),
            'currency_symbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$',
            'date_format' => get_option( 'date_format' ),
            'time_format' => get_option( 'time_format' ),
            'i18n' => [
                'loading' => __( 'Loading...', 'vss' ),
                'error' => __( 'An error occurred', 'vss' ),
                'confirm' => __( 'Are you sure?', 'vss' ),
                'save' => __( 'Save', 'vss' ),
                'cancel' => __( 'Cancel', 'vss' ),
                'close' => __( 'Close', 'vss' ),
                'fetching' => __( 'Fetching...', 'vss' ),
                'retry_fetch' => __( 'Retry Fetch', 'vss' ),
                'download' => __( 'Download', 'vss' ),
                'fetch_failed' => __( 'Failed to fetch files. Please try again.', 'vss' ),
                'no_files' => __( 'No files available', 'vss' ),
            ],
        ] );

        // Add inline initialization script
        wp_add_inline_script( 'vss-frontend', $this->get_inline_init_script(), 'after' );

        // Log asset loading
        $this->log( 'Vendor assets enqueued', 'debug' );
    }

    /**
     * Get inline initialization script
     */
    private function get_inline_init_script() {
        return '
        // VSS Inline Initialization
        (function($) {
            "use strict";

            // Wait for document ready
            $(document).ready(function() {
                console.log("VSS: Document ready, checking initialization...");

                // Check if main script loaded
                if (typeof window.vss === "undefined") {
                    console.error("VSS: Main script failed to load!");
                    return;
                }

                // Force initialize after a small delay to ensure everything is loaded
                setTimeout(function() {
                    console.log("VSS: Forcing initialization...");

                    // Initialize tabs if not already done
                    if (typeof vss.tabs !== "undefined" && typeof vss.tabs.init === "function") {
                        vss.tabs.init();
                        console.log("VSS: Tabs initialized");
                    }

                    // Add backup click handler for tabs
                    $(".vss-order-tabs .nav-tab").off("click.vss-backup").on("click.vss-backup", function(e) {
                        if (e.isDefaultPrevented()) return;

                        e.preventDefault();
                        var $tab = $(this);
                        var target = $tab.attr("href");

                        if (target && target !== "#" && target.indexOf("#") === 0) {
                            // Update active states
                            $(".vss-order-tabs .nav-tab").removeClass("nav-tab-active");
                            $tab.addClass("nav-tab-active");

                            // Show/hide content
                            $(".vss-tab-content").hide().removeClass("vss-tab-active");
                            $(target).show().addClass("vss-tab-active");

                            console.log("VSS: Tab switched via backup handler to", target);
                        }
                    });

                    console.log("VSS: Initialization complete");
                }, 100);
            });

            // Also try on window load as final fallback
            $(window).on("load", function() {
                setTimeout(function() {
                    if ($(".vss-order-tabs .nav-tab-active").length === 0) {
                        console.log("VSS: No active tab found on load, activating first tab");
                        $(".vss-order-tabs .nav-tab").first().trigger("click");
                    }
                }, 500);
            });

        })(jQuery);
        ';
    }

    /**
     * Enqueue customer-specific assets
     */
    private function enqueue_customer_assets() {
        // Customer styles
        wp_enqueue_style(
            'vss-customer-styles',
            VSS_PLUGIN_URL . 'assets/css/vss-customer-styles.css',
            [ 'vss-global' ],
            VSS_VERSION
        );

        // Customer scripts
        wp_enqueue_script(
            'vss-customer',
            VSS_PLUGIN_URL . 'assets/js/vss-customer-scripts.js',
            [ 'jquery' ],
            VSS_VERSION,
            true
        );

        // Localize script
        wp_localize_script( 'vss-customer', 'vss_customer_ajax', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'vss_customer_nonce' ),
        ] );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets( $hook ) {
        // Global admin styles
        wp_enqueue_style(
            'vss-global-admin',
            VSS_PLUGIN_URL . 'assets/css/vss-global.css',
            [],
            VSS_VERSION
        );

        // Check if we're on a VSS admin page or order page
        $is_vss_page = strpos( $hook, 'vss' ) !== false;
        $is_order_page = in_array( $hook, [ 'post.php', 'post-new.php' ] ) &&
                         isset( $_GET['post'] ) &&
                         get_post_type( $_GET['post'] ) === 'shop_order';
        $is_orders_list = $hook === 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order';

        if ( $is_vss_page || $is_order_page || $is_orders_list ) {
            // Admin styles
            wp_enqueue_style(
                'vss-admin-styles',
                VSS_PLUGIN_URL . 'assets/css/vss-admin-styles.css',
                [],
                VSS_VERSION
            );

            // Vendor admin styles if vendor
            if ( $this->is_current_user_vendor() ) {
                wp_enqueue_style(
                    'vss-vendor-admin-styles',
                    VSS_PLUGIN_URL . 'assets/css/vss-vendor-admin-styles.css',
                    [ 'vss-admin-styles' ],
                    VSS_VERSION
                );
            }

            // Admin scripts
            wp_enqueue_script(
                'vss-admin',
                VSS_PLUGIN_URL . 'assets/js/vss-admin.js',
                [ 'jquery', 'jquery-ui-datepicker', 'wp-media-utils' ],
                VSS_VERSION,
                true
            );

            // Vendor admin scripts
            if ( $this->is_current_user_vendor() ) {
                wp_enqueue_script(
                    'vss-vendor-admin',
                    VSS_PLUGIN_URL . 'assets/js/vss-vendor-admin.js',
                    [ 'jquery', 'vss-admin' ],
                    VSS_VERSION,
                    true
                );

                wp_localize_script( 'vss-vendor-admin', 'vss_vendor_ajax', [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'vss_vendor_admin_nonce' ),
                    'expand_text' => __( 'Show Details', 'vss' ),
                    'collapse_text' => __( 'Hide Details', 'vss' ),
                ] );
            }

            // Localize admin script
            wp_localize_script( 'vss-admin', 'vss_ajax', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'vss_admin_nonce' ),
                'post_id' => isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0,
                'debug' => VSS_DEBUG,
            ] );

            // jQuery UI styles
            wp_enqueue_style(
                'jquery-ui-admin',
                'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css',
                [],
                '1.12.1'
            );
        }
    }

    /**
     * Add AJAX nonce to head for logged out users
     */
    public function add_ajax_nonce() {
        if ( ! is_user_logged_in() ) {
            ?>
            <meta name="vss-nonce" content="<?php echo wp_create_nonce( 'vss_public_nonce' ); ?>">
            <?php
        }
    }

    /**
     * Check if current user is vendor
     */
    private function is_current_user_vendor() {
        if ( ! is_user_logged_in() ) {
            return false;
        }

        $user = wp_get_current_user();
        return in_array( 'vendor-mm', (array) $user->roles, true ) || current_user_can( 'vendor-mm' );
    }

    /**
     * Register custom order statuses
     */
    private function register_order_statuses() {
        // Shipped status
        register_post_status( 'wc-shipped', [
            'label' => _x( 'Shipped', 'Order status', 'vss' ),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop( 'Shipped <span class="count">(%s)</span>', 'Shipped <span class="count">(%s)</span>', 'vss' ),
        ] );

        // Add to WC order statuses
        add_filter( 'wc_order_statuses', function( $order_statuses ) {
            $order_statuses['wc-shipped'] = _x( 'Shipped', 'Order status', 'vss' );
            return $order_statuses;
        } );
    }

    /**
     * Add rewrite rules
     */
    private function add_rewrite_rules() {
        // Vendor portal rules
        add_rewrite_rule(
            '^vendor-portal/order/([0-9]+)/?$',
            'index.php?pagename=vendor-portal&vss_action=view_order&order_id=$matches[1]',
            'top'
        );

        // Add query vars
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'vss_action';
            $vars[] = 'order_id';
            return $vars;
        } );
    }

    /**
     * Add body classes
     */
    public function add_body_classes( $classes ) {
        // Add vendor class
        if ( $this->is_current_user_vendor() ) {
            $classes[] = 'vss-vendor-user';

            if ( is_admin() ) {
                $classes[] = 'vendor-mm-admin';
            }
        }

        // Add debug class
        if ( VSS_DEBUG ) {
            $classes[] = 'vss-debug-mode';
        }

        // Add page-specific classes
        if ( is_page() ) {
            global $post;

            $vendor_portal_page_id = get_option( 'vss_vendor_portal_page_id' );
            if ( $vendor_portal_page_id && $post && $post->ID == $vendor_portal_page_id ) {
                $classes[] = 'vss-vendor-portal-page';
            }

            $customer_approval_page_id = get_option( 'vss_customer_approval_page_id' );
            if ( $customer_approval_page_id && $post && $post->ID == $customer_approval_page_id ) {
                $classes[] = 'vss-customer-approval-page';
            }
        }

        return $classes;
    }

    /**
     * Check dependencies
     */
    public function check_dependencies() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( VSS_PLUGIN_BASENAME );

            add_action( 'admin_notices', function() {
                ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e( 'Vendor Order Manager has been deactivated because WooCommerce is not active. Please install and activate WooCommerce first.', 'vss' ); ?></p>
                </div>
                <?php
            } );

            if ( isset( $_GET['activate'] ) ) {
                unset( $_GET['activate'] );
            }
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="error notice">
            <p><?php esc_html_e( 'Vendor Order Manager requires WooCommerce to be installed and active.', 'vss' ); ?></p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create vendor role
        add_role( 'vendor-mm', __( 'Vendor MM', 'vss' ), [
            'read' => true,
            'upload_files' => true,
            'vendor-mm' => true,
            'manage_vendor_orders' => true,
            'view_admin_dashboard' => true,
            'edit_posts' => false,
            'delete_posts' => false,
            'publish_posts' => false,
        ] );

        // Add capabilities to admin
        $admin_role = get_role( 'administrator' );
        if ( $admin_role ) {
            $admin_role->add_cap( 'manage_vendor_orders' );
            $admin_role->add_cap( 'manage_all_vendor_orders' );
            $admin_role->add_cap( 'manage_vss_settings' );
        }

        // Create database tables
        $this->create_tables();

        // Create pages
        $this->create_pages();

        // Schedule cron events
        if ( ! wp_next_scheduled( 'vss_daily_tasks' ) ) {
            wp_schedule_event( time(), 'daily', 'vss_daily_tasks' );
        }

        // Set activation redirect flag
        set_transient( 'vss_activation_redirect', true, 30 );

        // Clear permalinks
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook( 'vss_daily_tasks' );
        wp_clear_scheduled_hook( 'vss_import_external_orders_cron' );

        // Clear transients
        delete_transient( 'vss_activation_redirect' );

        // Clear permalinks
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Activity log table
        $table_name = $wpdb->prefix . 'vss_activity_log';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) DEFAULT NULL,
            activity_type varchar(50) DEFAULT NULL,
            activity_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY activity_type (activity_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );

        // Import log table for external orders
        if ( class_exists( 'VSS_External_Orders' ) ) {
            $table_name = $wpdb->prefix . 'vss_import_log';
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                import_date datetime DEFAULT NULL,
                platform varchar(50) DEFAULT NULL,
                orders_imported int(11) DEFAULT 0,
                status varchar(50) DEFAULT NULL,
                PRIMARY KEY (id)
            ) $charset_collate;";

            dbDelta( $sql );
        }
    }

    /**
     * Create required pages
     */
    private function create_pages() {
        $pages = [
            'vendor-portal' => [
                'title' => __( 'Vendor Portal', 'vss' ),
                'content' => '[vss_vendor_portal]',
                'option' => 'vss_vendor_portal_page_id',
            ],
            'customer-approval' => [
                'title' => __( 'Order Approval', 'vss' ),
                'content' => '[vss_approval_handler]',
                'option' => 'vss_customer_approval_page_id',
            ],
            'track-order' => [
                'title' => __( 'Track Order', 'vss' ),
                'content' => '[vss_track_order]',
                'option' => 'vss_track_order_page_id',
            ],
            'vendor-application' => [
                'title' => __( 'Become a Vendor', 'vss' ),
                'content' => '[vss_vendor_application]',
                'option' => 'vss_vendor_application_page_id',
            ],
        ];

        foreach ( $pages as $slug => $page ) {
            $page_id = get_option( $page['option'] );

            // Check if page exists
            if ( ! $page_id || ! get_post( $page_id ) ) {
                $page_id = wp_insert_post( [
                    'post_title' => $page['title'],
                    'post_content' => $page['content'],
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'post_name' => $slug,
                    'comment_status' => 'closed',
                ] );

                if ( ! is_wp_error( $page_id ) ) {
                    update_option( $page['option'], $page_id );
                }
            }
        }
    }

    /**
     * Add plugin action links
     */
    public function add_action_links( $links ) {
        $action_links = [
            '<a href="' . admin_url( 'admin.php?page=vss-zakeke-settings' ) . '">' . __( 'Settings', 'vss' ) . '</a>',
            '<a href="' . admin_url( 'admin.php?page=vss-admin-dashboard' ) . '">' . __( 'Dashboard', 'vss' ) . '</a>',
        ];

        return array_merge( $action_links, $links );
    }

    /**
     * Activation redirect
     */
    public function activation_redirect() {
        if ( get_transient( 'vss_activation_redirect' ) ) {
            delete_transient( 'vss_activation_redirect' );

            if ( ! isset( $_GET['activate-multi'] ) ) {
                wp_redirect( admin_url( 'admin.php?page=vss-admin-dashboard' ) );
                exit;
            }
        }
    }

    /**
     * Add cron schedules
     */
    public function add_cron_schedules( $schedules ) {
        $schedules['vss_hourly'] = [
            'interval' => HOUR_IN_SECONDS,
            'display' => __( 'Once Hourly', 'vss' ),
        ];

        $schedules['vss_every_5_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __( 'Every 5 Minutes', 'vss' ),
        ];

        return $schedules;
    }

    /**
     * Run daily tasks
     */
    public function run_daily_tasks() {
        // Clean up old transients
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_vss_%' AND option_value < " . time() );

        // Clean up old activity logs
        $wpdb->query( "DELETE FROM {$wpdb->prefix}vss_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)" );

        // Check for late orders
        $this->check_late_orders();

        // Run additional tasks
        do_action( 'vss_daily_maintenance' );
    }

    /**
     * Check for late orders
     */
    private function check_late_orders() {
        $args = [
            'status' => 'processing',
            'meta_key' => '_vss_estimated_ship_date',
            'meta_value' => date( 'Y-m-d' ),
            'meta_compare' => '<',
            'return' => 'ids',
            'limit' => -1,
        ];

        $late_orders = wc_get_orders( $args );

        foreach ( $late_orders as $order_id ) {
            update_post_meta( $order_id, '_vss_is_late', true );
            do_action( 'vss_order_late', $order_id );
        }
    }

    /**
     * Log activity
     *
     * @param string $activity_type
     * @param array $data
     */
    public static function log_activity( $activity_type, $data = [] ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'vss_activity_log',
            [
                'user_id' => get_current_user_id(),
                'activity_type' => $activity_type,
                'activity_data' => json_encode( $data ),
                'created_at' => current_time( 'mysql' ),
            ]
        );
    }

    /**
     * Log debug messages
     *
     * @param mixed $message
     * @param string $level
     */
    public function log( $message, $level = 'info' ) {
        if ( ! VSS_DEBUG ) {
            return;
        }

        if ( is_array( $message ) || is_object( $message ) ) {
            $message = print_r( $message, true );
        }

        $log_entry = sprintf( '[%s] [VSS] [%s] %s', date( 'Y-m-d H:i:s' ), strtoupper( $level ), $message );

        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( $log_entry );
        }
    }
}

// Initialize plugin
Vendor_Order_Manager::get_instance();

// Global functions for backward compatibility
function vss_is_vendor( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    $user = get_user_by( 'id', $user_id );
    return $user && in_array( 'vendor-mm', (array) $user->roles, true );
}

function vss_get_vendor_portal_url() {
    $page_id = get_option( 'vss_vendor_portal_page_id' );
    return $page_id ? get_permalink( $page_id ) : home_url( '/vendor-portal/' );
}

function vss_log( $message, $level = 'info' ) {
    Vendor_Order_Manager::get_instance()->log( $message, $level );
}