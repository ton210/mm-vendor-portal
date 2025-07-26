<?php
/**
 * VSS Vendor Authentication Module
 * 
 * Authentication and access control
 * 
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Authentication functionality
 */
trait VSS_Vendor_Authentication {


        /**
         * Redirect vendors to FRONTEND portal upon login
         */
        public static function vendor_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
            if ( $user && ! is_wp_error( $user ) && in_array( 'vendor-mm', (array) $user->roles, true ) ) {
                // Get the vendor portal page URL
                $portal_page_id = get_option( 'vss_vendor_portal_page_id' );
                if ( $portal_page_id ) {
                    return get_permalink( $portal_page_id );
                }
                // Fallback to home URL with vendor-portal slug
                return home_url( '/vendor-portal/' );
            }
            return $redirect_to;
        }



        /**
         * Restrict admin access for vendors - redirect to frontend
         */
        public static function restrict_admin_access() {
            if ( ! self::is_current_user_vendor() ) {
                return;
            }

            if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                return;
            }

            global $pagenow;

            // If vendor tries to access admin dashboard, redirect to frontend
            if ( $pagenow === 'index.php' || ( $pagenow === 'admin.php' && empty( $_GET['page'] ) ) ) {
                $portal_page_id = get_option( 'vss_vendor_portal_page_id' );
                if ( $portal_page_id ) {
                    wp_redirect( get_permalink( $portal_page_id ) );
                    exit;
                }
            }

            // Allowed pages for vendors in admin
            $allowed_pages = [
                'admin.php',
                'upload.php',
                'media-new.php',
                'admin-ajax.php',
                'profile.php',
            ];

            // Always allow our custom vendor pages
            if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'vss-vendor-' ) === 0 ) {
                return;
            }

            // Optionally, completely block admin access for vendors
            // Uncomment the following to force vendors to use frontend only:
            /*
            $portal_page_id = get_option( 'vss_vendor_portal_page_id' );
            if ( $portal_page_id ) {
                wp_redirect( get_permalink( $portal_page_id ) );
                exit;
            }
            */
        }



        /**
         * Render login form
         *
         * @return string
         */
        private static function render_login_form() {
            ob_start();
            ?>
            <div class="vss-vendor-login">
                <h2><?php esc_html_e( 'Vendor Login', 'vss' ); ?></h2>
                <p><?php esc_html_e( 'You must be logged in as a vendor to view this content.', 'vss' ); ?></p>
                <?php
                wp_login_form( [
                    'redirect' => get_permalink(),
                    'form_id' => 'vss-vendor-login-form',
                    'label_username' => __( 'Username or Email', 'vss' ),
                    'label_password' => __( 'Password', 'vss' ),
                    'label_remember' => __( 'Remember Me', 'vss' ),
                    'label_log_in' => __( 'Log In', 'vss' ),
                    'remember' => true,
                ] );
                ?>
                <p class="vss-vendor-register">
                    <?php
                    printf(
                        __( 'Not a vendor yet? <a href="%s">Apply here</a>.', 'vss' ),
                        esc_url( home_url( '/vendor-application/' ) )
                    );
                    ?>
                </p>
            </div>
            <?php
            return ob_get_clean();
        }


}
