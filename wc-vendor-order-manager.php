<?php
/**
 * Plugin Name:       Vendor Order Manager: Zakeke Edition
 * Plugin URI:        https://munchmakers.com/
 * Description:       Complete vendor order management system with Zakeke integration, approval workflows, and vendor portal
 * Version:           7.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            MunchMakers Team
 * Author URI:        https://munchmakers.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vss
 * Domain Path:       /languages
 * WC requires at least: 5.0
 * WC tested up to:   8.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define( 'VSS_VERSION', '7.0.0' );
define( 'VSS_PLUGIN_FILE', __FILE__ );
define( 'VSS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'VSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VSS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main Vendor Order Manager Class
 *
 * @since 7.0.0
 */
final class Vendor_Order_Manager {

    /**
     * Plugin instance
     *
     * @var Vendor_Order_Manager|null
     */
    private static $_instance = null;

    /**
     * Plugin components
     *
     * @var array
     */
    private $components = [];

    /**
     * Get plugin instance
     *
     * @return Vendor_Order_Manager
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Define additional constants
     */
    private function define_constants() {
        define( 'VSS_ADMIN_PATH', VSS_PLUGIN_PATH . 'admin/' );
        define( 'VSS_INCLUDES_PATH', VSS_PLUGIN_PATH . 'includes/' );
        define( 'VSS_TEMPLATES_PATH', VSS_PLUGIN_PATH . 'templates/' );
        define( 'VSS_ASSETS_URL', VSS_PLUGIN_URL . 'assets/' );
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core files
        require_once VSS_INCLUDES_PATH . 'class-vss-setup.php';
        require_once VSS_INCLUDES_PATH . 'class-vss-admin.php';
        require_once VSS_INCLUDES_PATH . 'class-vss-vendor.php';
        require_once VSS_INCLUDES_PATH . 'class-vss-emails.php';
        require_once VSS_INCLUDES_PATH . 'class-vss-zakeke-api.php';
        
        // Additional includes for enhanced functionality
        $this->include_if_exists( 'class-vss-ajax.php' );
        $this->include_if_exists( 'class-vss-analytics.php' );
        $this->include_if_exists( 'class-vss-integrations.php' );
        $this->include_if_exists( 'class-vss-rest-api.php' );
    }

    /**
     * Include file if it exists
     *
     * @param string $filename
     */
    private function include_if_exists( $filename ) {
        $filepath = VSS_INCLUDES_PATH . $filename;
        if ( file_exists( $filepath ) ) {
            require_once $filepath;
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook( VSS_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( VSS_PLUGIN_FILE, [ $this, 'deactivate' ] );
        register_uninstall_hook( VSS_PLUGIN_FILE, [ __CLASS__, 'uninstall' ] );

        // Initialize on plugins_loaded
        add_action( 'plugins_loaded', [ $this, 'init' ], 10 );
        
        // Plugin action links
        add_filter( 'plugin_action_links_' . VSS_PLUGIN_BASENAME, [ $this, 'add_action_links' ] );
        
        // Load textdomain
        add_action( 'init', [ $this, 'load_textdomain' ] );
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Check dependencies
        if ( ! $this->check_dependencies() ) {
            return;
        }

        // Initialize components
        $this->init_components();

        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        // AJAX handlers
        $this->register_ajax_handlers();

        // Fire action for extensions
        do_action( 'vss_loaded' );
    }

    /**
     * Check plugin dependencies
     *
     * @return bool
     */
    private function check_dependencies() {
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'woocommerce_missing_notice' ] );
            return false;
        }

        // Check PHP version
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            add_action( 'admin_notices', [ $this, 'php_version_notice' ] );
            return false;
        }

        return true;
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Core components
        $this->components['setup'] = VSS_Setup::init();
        $this->components['admin'] = VSS_Admin::init();
        $this->components['vendor'] = VSS_Vendor::init();
        $this->components['emails'] = VSS_Emails::init();
        $this->components['zakeke'] = VSS_Zakeke_API::init();

        // Optional components
        if ( class_exists( 'VSS_Ajax' ) ) {
            $this->components['ajax'] = VSS_Ajax::init();
        }
        
        if ( class_exists( 'VSS_Analytics' ) ) {
            $this->components['analytics'] = VSS_Analytics::init();
        }
        
        if ( class_exists( 'VSS_Integrations' ) ) {
            $this->components['integrations'] = VSS_Integrations::init();
        }
        
        if ( class_exists( 'VSS_REST_API' ) ) {
            $this->components['rest_api'] = VSS_REST_API::init();
        }
    }

    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers() {
        // Admin AJAX
        add_action( 'wp_ajax_vss_approve_mockup', [ 'VSS_Admin', 'ajax_approve_mockup' ] );
        add_action( 'wp_ajax_vss_disapprove_mockup', [ 'VSS_Admin', 'ajax_disapprove_mockup' ] );
        add_action( 'wp_ajax_vss_get_vendor_costs', [ 'VSS_Admin', 'ajax_get_vendor_costs' ] );
        add_action( 'wp_ajax_vss_split_order', [ 'VSS_Admin', 'ajax_split_order_handler' ] );
        add_action( 'wp_ajax_vss_bulk_assign_vendor', [ 'VSS_Admin', 'ajax_bulk_assign_vendor' ] );
        
        // Vendor AJAX
        add_action( 'wp_ajax_vss_manual_fetch_zip', [ 'VSS_Vendor', 'ajax_manual_fetch_zakeke_zip' ] );
        add_action( 'wp_ajax_vss_save_draft', [ 'VSS_Vendor', 'ajax_save_draft' ] );
        
        // Public AJAX (if needed)
        add_action( 'wp_ajax_nopriv_vss_track_order', [ 'VSS_Vendor', 'ajax_track_order' ] );
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Global frontend styles
        wp_enqueue_style(
            'vss-global',
            VSS_ASSETS_URL . 'css/vss-global.css',
            [],
            VSS_VERSION
        );

        // Vendor portal specific assets
        if ( is_page( 'vendor-portal' ) || has_shortcode( get_post()->post_content ?? '', 'vss_vendor_portal' ) ) {
            // Styles
            wp_enqueue_style(
                'vss-frontend',
                VSS_ASSETS_URL . 'css/vss-frontend-styles.css',
                [ 'dashicons' ],
                VSS_VERSION
            );

            // Scripts
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_enqueue_style( 
                'jquery-ui-smoothness', 
                'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css',
                [],
                '1.12.1'
            );

            wp_enqueue_script(
                'vss-frontend',
                VSS_ASSETS_URL . 'js/vss-frontend.js',
                [ 'jquery', 'jquery-ui-datepicker' ],
                VSS_VERSION,
                true
            );

            // Localize script
            wp_localize_script( 'vss-frontend', 'vss_frontend', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'vss_frontend_nonce' ),
                'i18n' => [
                    'confirm_delete' => __( 'Are you sure you want to delete this?', 'vss' ),
                    'loading' => __( 'Loading...', 'vss' ),
                    'error' => __( 'An error occurred. Please try again.', 'vss' ),
                    'saved' => __( 'Saved successfully!', 'vss' ),
                ],
                'date_format' => $this->get_js_date_format(),
                'currency' => get_woocommerce_currency_symbol(),
            ] );
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets( $hook ) {
        global $post_type, $post;

        // Global admin styles
        wp_enqueue_style(
            'vss-admin-global',
            VSS_ASSETS_URL . 'css/vss-admin-global.css',
            [],
            VSS_VERSION
        );

        // Plugin specific pages
        if ( strpos( $hook, 'vss-' ) !== false || 
             ( $post_type === 'shop_order' ) ||
             ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'vss-' ) === 0 ) ) {
            
            // Styles
            wp_enqueue_style(
                'vss-admin',
                VSS_ASSETS_URL . 'css/vss-admin-styles.css',
                [ 'woocommerce_admin_styles' ],
                VSS_VERSION
            );

            // Scripts
            wp_enqueue_script( 'jquery-ui-datepicker' );
            wp_enqueue_style( 
                'jquery-ui-smoothness', 
                'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css',
                [],
                '1.12.1'
            );

            // Media uploader
            if ( $post_type === 'shop_order' ) {
                wp_enqueue_media();
            }

            // Chart.js for analytics
            if ( isset( $_GET['page'] ) && in_array( $_GET['page'], [ 'vss-performance-report', 'vss-analytics' ] ) ) {
                wp_enqueue_script(
                    'chartjs',
                    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js',
                    [],
                    '4.4.0'
                );
            }

            wp_enqueue_script(
                'vss-admin',
                VSS_ASSETS_URL . 'js/vss-admin.js',
                [ 'jquery', 'jquery-ui-datepicker' ],
                VSS_VERSION,
                true
            );

            // Localize script
            wp_localize_script( 'vss-admin', 'vss_admin', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'vss_admin_nonce' ),
                'post_id' => $post->ID ?? 0,
                'i18n' => [
                    'confirm_approve' => __( 'Are you sure you want to approve this?', 'vss' ),
                    'confirm_disapprove' => __( 'Are you sure you want to disapprove this?', 'vss' ),
                    'confirm_split' => __( 'Are you sure you want to split this order? This action cannot be undone.', 'vss' ),
                    'loading' => __( 'Processing...', 'vss' ),
                    'error' => __( 'An error occurred. Please try again.', 'vss' ),
                ],
            ] );
        }
    }

    /**
     * Convert PHP date format to jQuery UI format
     *
     * @return string
     */
    private function get_js_date_format() {
        $php_format = get_option( 'date_format' );
        $js_format = str_replace(
            [ 'Y', 'm', 'd', 'j', 'n' ],
            [ 'yy', 'mm', 'dd', 'd', 'm' ],
            $php_format
        );
        return $js_format;
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'vss',
            false,
            dirname( VSS_PLUGIN_BASENAME ) . '/languages'
        );
    }

    /**
     * Add plugin action links
     *
     * @param array $links
     * @return array
     */
    public function add_action_links( $links ) {
        $action_links = [
            'settings' => sprintf(
                '<a href="%s">%s</a>',
                admin_url( 'admin.php?page=vss-zakeke-settings' ),
                __( 'Settings', 'vss' )
            ),
            'dashboard' => sprintf(
                '<a href="%s">%s</a>',
                admin_url( 'admin.php?page=vss-admin-dashboard' ),
                __( 'Dashboard', 'vss' )
            ),
        ];

        return array_merge( $action_links, $links );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();

        // Add vendor role
        VSS_Setup::add_vendor_role();

        // Schedule cron events
        $this->schedule_cron_events();

        // Create default pages
        $this->create_default_pages();

        // Set default options
        $this->set_default_options();

        // Clear rewrite rules
        flush_rewrite_rules();

        // Set activation flag
        set_transient( 'vss_activated', true, 30 );
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Analytics table
        $analytics_table = $wpdb->prefix . 'vss_analytics';
        $sql_analytics = "CREATE TABLE IF NOT EXISTS $analytics_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            vendor_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            metric_type varchar(50) NOT NULL,
            metric_value decimal(10,2) DEFAULT 0,
            metric_date date NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY vendor_metrics (vendor_id, metric_type, metric_date),
            KEY order_id (order_id)
        ) $charset_collate;";

        // Activity log table
        $activity_table = $wpdb->prefix . 'vss_activity_log';
        $sql_activity = "CREATE TABLE IF NOT EXISTS $activity_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            action varchar(100) NOT NULL,
            details text,
            ip_address varchar(45),
            user_agent text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_activity (user_id, created_at),
            KEY order_activity (order_id, created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_analytics );
        dbDelta( $sql_activity );

        // Update database version
        update_option( 'vss_db_version', VSS_VERSION );
    }

    /**
     * Schedule cron events
     */
    private function schedule_cron_events() {
        // Daily analytics
        if ( ! wp_next_scheduled( 'vss_daily_analytics' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vss_daily_analytics' );
        }

        // Hourly order sync
        if ( ! wp_next_scheduled( 'vss_hourly_order_sync' ) ) {
            wp_schedule_event( time() + ( 30 * MINUTE_IN_SECONDS ), 'hourly', 'vss_hourly_order_sync' );
        }

        // Weekly cleanup
        if ( ! wp_next_scheduled( 'vss_weekly_cleanup' ) ) {
            wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', 'vss_weekly_cleanup' );
        }
    }

    /**
     * Create default pages
     */
    private function create_default_pages() {
        $pages = [
            'vendor-portal' => [
                'title' => __( 'Vendor Portal', 'vss' ),
                'content' => '[vss_vendor_portal]',
                'option' => 'vss_vendor_portal_page_id',
            ],
            'order-approval' => [
                'title' => __( 'Order Approval', 'vss' ),
                'content' => '[vss_approval_handler]',
                'option' => 'vss_approval_page_id',
            ],
        ];

        foreach ( $pages as $slug => $page ) {
            $page_id = wp_insert_post( [
                'post_title' => $page['title'],
                'post_content' => $page['content'],
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_name' => $slug,
            ] );

            if ( $page_id && ! is_wp_error( $page_id ) ) {
                update_option( $page['option'], $page_id );
            }
        }
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        $defaults = [
            'vss_email_from_name' => get_bloginfo( 'name' ),
            'vss_email_from_address' => get_option( 'admin_email' ),
            'vss_enable_auto_assignment' => 'no',
            'vss_enable_vendor_notifications' => 'yes',
            'vss_enable_customer_notifications' => 'yes',
            'vss_default_ship_days' => 7,
            'vss_enable_analytics' => 'yes',
            'vss_enable_activity_log' => 'yes',
        ];

        foreach ( $defaults as $option => $value ) {
            if ( get_option( $option ) === false ) {
                update_option( $option, $value );
            }
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook( 'vss_daily_analytics' );
        wp_clear_scheduled_hook( 'vss_hourly_order_sync' );
        wp_clear_scheduled_hook( 'vss_weekly_cleanup' );
        wp_clear_scheduled_hook( 'vss_fetch_zakeke_files_for_order_hook' );

        // Clear rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        // Only run if explicitly told to delete data
        if ( ! defined( 'VSS_DELETE_DATA_ON_UNINSTALL' ) || ! VSS_DELETE_DATA_ON_UNINSTALL ) {
            return;
        }

        global $wpdb;

        // Delete tables
        $tables = [
            $wpdb->prefix . 'vss_analytics',
            $wpdb->prefix . 'vss_activity_log',
        ];

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS $table" );
        }

        // Delete options
        $options = [
            'vss_db_version',
            'vss_vendor_portal_page_id',
            'vss_approval_page_id',
            'vss_zakeke_settings',
            'vss_email_from_name',
            'vss_email_from_address',
            'vss_enable_auto_assignment',
            'vss_enable_vendor_notifications',
            'vss_enable_customer_notifications',
            'vss_default_ship_days',
            'vss_enable_analytics',
            'vss_enable_activity_log',
        ];

        foreach ( $options as $option ) {
            delete_option( $option );
        }

        // Delete transients
        $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_vss_%'" );
        $wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_timeout_vss_%'" );

        // Remove vendor role
        remove_role( 'vendor-mm' );
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Vendor Order Manager', 'vss' ); ?></strong> 
                <?php esc_html_e( 'requires WooCommerce to be installed and active.', 'vss' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * PHP version notice
     */
    public function php_version_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'Vendor Order Manager', 'vss' ); ?></strong> 
                <?php 
                printf(
                    esc_html__( 'requires PHP version %s or higher. You are running version %s.', 'vss' ),
                    '7.4',
                    PHP_VERSION
                ); 
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Get component instance
     *
     * @param string $component
     * @return object|null
     */
    public function get_component( $component ) {
        return isset( $this->components[ $component ] ) ? $this->components[ $component ] : null;
    }

    /**
     * Log activity
     *
     * @param string $action
     * @param array $details
     */
    public static function log_activity( $action, $details = [] ) {
        if ( get_option( 'vss_enable_activity_log' ) !== 'yes' ) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'vss_activity_log',
            [
                'user_id' => get_current_user_id(),
                'order_id' => $details['order_id'] ?? null,
                'action' => $action,
                'details' => wp_json_encode( $details ),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
    }
}

/**
 * Main instance of Vendor Order Manager
 *
 * @since 7.0.0
 * @return Vendor_Order_Manager
 */
function VSS() {
    return Vendor_Order_Manager::instance();
}

// Initialize the plugin
VSS();