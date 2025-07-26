<?php
/**
 * VSS Vendor Assets Module
 * 
 * Asset management and enqueuing
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Assets functionality
 */
trait VSS_Vendor_Assets {



        /**
         * Enqueue frontend assets
         */
        public static function enqueue_frontend_assets() {
            // More robust check: is it the portal page OR does the page contain the shortcode?
            $is_vendor_portal = false;
            $portal_page_id = get_option( 'vss_vendor_portal_page_id' );

            if ( is_page( $portal_page_id ) ) {
                $is_vendor_portal = true;
            } else if ( is_page() ) {
                global $post;
                if ( $post && has_shortcode( $post->post_content, 'vss_vendor_portal' ) ) {
                    $is_vendor_portal = true;
                }
            }

            if ( $is_vendor_portal ) {
                // Enqueue frontend styles
                wp_enqueue_style(
                    'vss-frontend-styles',
                    VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css',
                    [],
                    VSS_VERSION
                );

                // Enqueue frontend scripts
                wp_enqueue_script(
                    'vss-frontend',
                    VSS_PLUGIN_URL . 'assets/js/vss-frontend-scripts.js',
                    [ 'jquery', 'jquery-ui-datepicker' ],
                    VSS_VERSION,
                    true
                );

                // Localize script
                wp_localize_script( 'vss-frontend', 'vss_frontend_ajax', [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'vss_frontend_nonce' ),
                ] );

                // Enqueue jQuery UI datepicker styles
                wp_enqueue_style( 'jquery-ui-datepicker-style', '//code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css' );
            }
        }


}
