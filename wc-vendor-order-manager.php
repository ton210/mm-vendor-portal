<?php
/**
 * Plugin Name:       Vendor Order Manager: Zakeke Edition
 * Description:       A professional system for vendor order and customer communication management.
 * Version:           5.7.0
 * Author:            Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'VSS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'VSS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

final class VendorOrderManager {
    private static $_instance = null;
    public static function instance() { if ( is_null( self::$_instance ) ) self::$_instance = new self(); return self::$_instance; }
    
    private function __construct() {
        add_action('plugins_loaded', [$this, 'init_plugin']);
    }

    public function init_plugin() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-setup.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-zakeke-api.php'; 
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-admin.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-vendor.php';
        require_once VSS_PLUGIN_PATH . 'includes/class-vss-emails.php';
    }

    private function init_hooks() {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_frontend_styles_scripts']); 
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_scripts_styles']);
        VSS_Setup::init();
        VSS_Zakeke_API::init(); 
        VSS_Admin::init(); 
        VSS_Vendor::init();
        VSS_Emails::init();
    }

    public static function enqueue_admin_scripts_styles($hook) {
        wp_enqueue_style('vss-admin-styles', VSS_PLUGIN_URL . 'assets/css/vss-admin-styles.css', [], '5.7.0');
        global $pagenow, $typenow;
        if (($pagenow === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order') || 
            ($pagenow === 'post-new.php' && $typenow === 'shop_order')) {
            wp_enqueue_script('jquery-ui-datepicker');
            if (!wp_style_is('jquery-ui', 'enqueued') && !wp_style_is('jquery-ui-css', 'enqueued') && !wp_style_is('jquery-style', 'enqueued') && !wp_style_is('woocommerce_admin_styles', 'enqueued') ) {
                wp_enqueue_style('jquery-ui-smoothness', '//ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css');
            }
        }
    }

    public static function enqueue_frontend_styles_scripts() {
        if (is_page('vendor-portal')) { 
            wp_enqueue_style('vss-frontend-styles', VSS_PLUGIN_URL . 'assets/css/vss-frontend-styles.css', [], '5.7.0');
            wp_enqueue_script('jquery'); 
            wp_add_inline_script('jquery-core', self::get_frontend_js());
        }
    }
    public static function get_frontend_js() {
        ob_start();
        ?>
        jQuery(document).ready(function($){
            // For vendor cost input form
            function calculateTotalCostFrontend(){
                var total = 0;
                $('.vss-cost-input-fe').each(function(){
                    var val = parseFloat( $(this).val().replace(/,/g, '.').replace(/[^0-9\.]/g, '') );
                    if( !isNaN(val) ){ total += val; }
                });
                var currency_symbol = $('#vss-total-cost-display-fe').data('currency');
                var formatted_total = total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                $('#vss-total-cost-display-fe').text(currency_symbol + formatted_total);
            }
            if ($('.vss-cost-input-fe').length) { calculateTotalCostFrontend(); }
            $('body').on('keyup', '.vss-cost-input-fe', calculateTotalCostFrontend);

            // For frontend tabs
            $('.vss-order-tabs .nav-tab').on('click', function(e){
                e.preventDefault();
                var targetTab = $(this).attr('href');
                $('.vss-order-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.vss-tab-content').hide(); $(targetTab).show();
                if(typeof(Storage) !== "undefined") { localStorage.setItem("vssActiveOrderTab", targetTab); }
            });
            if(typeof(Storage) !== "undefined") {
                var activeTab = localStorage.getItem("vssActiveOrderTab");
                if (activeTab && $('.vss-order-tabs a[href="' + activeTab + '"]').length) {
                    $('.vss-order-tabs a[href="' + activeTab + '"]').click();
                } else if ($('.vss-order-tabs .nav-tab').length) {
                    $('.vss-order-tabs .nav-tab').first().click();
                }
            } else if ($('.vss-order-tabs .nav-tab').length) {
                $('.vss-order-tabs .nav-tab').first().click();
            }

            // For vendor-side datepicker
            if (typeof $.fn.datepicker === 'function') {
                $('.vss-datepicker-fe').datepicker({ dateFormat: 'yy-mm-dd', minDate: 0 });
            }
            
            // Frontend form validation for vendor ship date
            $('body').on('submit', 'form#vss_vendor_confirm_production_form', function(e){
                if ($('#vss_vendor_estimated_ship_date').val() === '') {
                    alert('<?php _e("Please select an estimated ship date before confirming.", "vss"); ?>');
                    e.preventDefault();
                }
            });


            // Manual Zakeke ZIP Fetch AJAX
            $('body').on('click', '.vss-manual-fetch-zakeke-zip', function(e){
                e.preventDefault();
                var $button = $(this); var orderId = $button.data('order-id'); var itemId = $button.data('item-id');
                var zakekeDesignId = $button.data('zakeke-design-id'); var $feedbackEl = $button.siblings('.vss-fetch-zip-feedback');
                $button.prop('disabled', true).text('<?php _e("Fetching...", "vss"); ?>'); $feedbackEl.html('');
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>', type: 'POST',
                    data: { action: 'vss_manual_fetch_zip', _ajax_nonce: '<?php echo wp_create_nonce('vss_manual_fetch_zip_nonce'); ?>', order_id: orderId, item_id: itemId, primary_zakeke_design_id: zakekeDesignId },
                    success: function(response){
                        if(response.success){
                            $feedbackEl.html('<span style="color:green;">' + response.data.message + ' <?php _e("Page will refresh.", "vss"); ?></span>');
                            setTimeout(function(){ location.reload(); }, 2000); 
                        } else {
                            $feedbackEl.html('<span style="color:red;">' + response.data.message + '</span>');
                            $button.prop('disabled', false).text('<?php _e("Retry Fetch", "vss"); ?>');
                        }
                    },
                    error: function(){
                        $feedbackEl.html('<span style="color:red;"><?php _e("AJAX Error. Please try again.", "vss"); ?></span>');
                        $button.prop('disabled', false).text('<?php _e("Retry Fetch", "vss"); ?>');
                    }
                });
            });
        });
        <?php
        return ob_get_clean();
    }
}
function VSS() { return VendorOrderManager::instance(); }
VSS();