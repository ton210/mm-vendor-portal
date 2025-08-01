<?php
/**
 * VSS Vendor Initialization Module
 * 
 * Initialization and setup functionality
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Initialization functionality
 */
trait VSS_Vendor_Initialization {



        /**
         * Initialize vendor functionality
         */
        public static function init() {
            // Shortcodes
            add_shortcode( 'vss_vendor_portal', [ self::class, 'render_vendor_portal_shortcode' ] );
            add_shortcode( 'vss_vendor_stats', [ self::class, 'render_vendor_stats_shortcode' ] );
            add_shortcode( 'vss_vendor_earnings', [ self::class, 'render_vendor_earnings_shortcode' ] );

            // Frontend forms
            add_action( 'template_redirect', [ self::class, 'handle_frontend_forms' ] );

            // Login redirect - UPDATED to prioritize frontend
            add_filter( 'login_redirect', [ self::class, 'vendor_login_redirect' ], 10, 3 );

            // Admin area setup - OPTIONAL (can be removed if you want frontend only)
            add_action( 'admin_menu', [ self::class, 'setup_vendor_admin_menu' ], 999 );
            add_action( 'admin_init', [ self::class, 'restrict_admin_access' ] );

            // Prevent vendor redirect to my-account
            add_action( 'init', [ self::class, 'prevent_vendor_redirect' ], 1 );
            add_filter( 'woocommerce_prevent_admin_access', [ self::class, 'allow_vendor_admin_access' ], 10, 1 );

            // AJAX handlers
            add_action( 'wp_ajax_vss_manual_fetch_zip', [ self::class, 'ajax_manual_fetch_zakeke_zip' ] );
            add_action( 'wp_ajax_vss_quick_save_tracking', [ self::class, 'ajax_quick_save_tracking' ] );
            add_action( 'wp_ajax_vss_save_draft', [ self::class, 'ajax_save_draft' ] );
            add_action( 'wp_ajax_vss_get_order_details', [ self::class, 'ajax_get_order_details' ] );
            add_action( 'wp_ajax_nopriv_vss_track_order', [ self::class, 'ajax_track_order' ] );
            add_action( 'wp_ajax_vss_expand_order_row', [ self::class, 'ajax_expand_order_row' ] );
            add_action( 'wp_ajax_assign_order_to_vendor', [ self::class, 'ajax_assign_order_to_vendor' ] );
            add_action( 'wp_ajax_vss_download_admin_zip', [ self::class, 'ajax_download_admin_zip' ] );
            add_action( 'wp_ajax_nopriv_vss_download_admin_zip', [ self::class, 'ajax_download_admin_zip' ] );


            // Vendor dashboard widgets
            add_action( 'wp_dashboard_setup', [ self::class, 'add_vendor_dashboard_widgets' ] );

            // Setup vendor roles and capabilities
            add_action( 'init', [ self::class, 'setup_vendor_capabilities' ] );

            // Profile fields
            add_action( 'show_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
            add_action( 'edit_user_profile', [ self::class, 'add_vendor_profile_fields' ] );
            add_action( 'personal_options_update', [ self::class, 'save_vendor_profile_fields' ] );
            add_action( 'edit_user_profile_update', [ self::class, 'save_vendor_profile_fields' ] );

            // Enqueue frontend assets for vendor portal
            add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_frontend_assets' ] );

            // Handle secure file downloads
            add_action( 'init', [ self::class, 'handle_file_download' ], 20 );
        }



        /**
         * Setup vendor capabilities and role.
         */
        public static function setup_vendor_capabilities() {
            $role = get_role( 'vendor-mm' );
            if ( ! $role ) {
                add_role( 'vendor-mm', __( 'Vendor MM', 'vss' ), [
                    'read' => true,
                    'upload_files' => true,
                    'vendor-mm' => true,
                    'manage_vendor_orders' => true,
                ] );
            } else {
                // Ensure role has necessary capabilities
                $role->add_cap( 'read' );
                $role->add_cap( 'upload_files' );
                $role->add_cap( 'vendor-mm' );
                $role->add_cap( 'manage_vendor_orders' );
            }
        }



        /**
         * Prevent vendor redirect from admin area.
         */
        public static function prevent_vendor_redirect() {
            if ( is_admin() && self::is_current_user_vendor() ) {
                // Remove WooCommerce's redirect
                remove_action( 'admin_init', 'wc_prevent_admin_access' );
                remove_action( 'admin_init', [ 'WC_Admin', 'prevent_admin_access' ] );
            }
        }



        /**
         * Allow vendor admin access by overriding WooCommerce's prevention.
         */
        public static function allow_vendor_admin_access( $prevent_access ) {
            if ( self::is_current_user_vendor() ) {
                return false;
            }
            return $prevent_access;
        }



        /**
         * Check if current user is a vendor.
         *
         * @return bool
         */
        private static function is_current_user_vendor() {
            $user = wp_get_current_user();
            return in_array( 'vendor-mm', (array) $user->roles, true ) || current_user_can( 'vendor-mm' );
        }


}
