<?php
// includes/class-vss-vendor.php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class VSS_Vendor {
    public static function init() {
        add_shortcode('vss_vendor_portal', [self::class, 'render_vendor_portal_shortcode']);
        add_action('template_redirect', [self::class, 'handle_frontend_forms']);
        add_filter('login_redirect', [self::class, 'vendor_login_redirect'], 9999, 3);
        add_filter( 'pre_get_posts', [ self::class, 'filter_orders_for_vendor_in_admin' ] );
        add_action('wp_ajax_vss_manual_fetch_zip', [self::class, 'ajax_manual_fetch_zakeke_zip']);
        // Removed AJAX handlers for mockup/production file uploads as we are using standard forms
    }

    private static function is_current_user_vendor() {
        return function_exists('wp_get_current_user') && current_user_can( 'vendor-mm' );
    }

    public static function vendor_login_redirect($redirect_to, $requested_redirect_to, $user) {
        if ( $user && !is_wp_error($user) && !empty($user->roles) && is_array($user->roles) ) {
            if (in_array('vendor-mm', $user->roles)) {
                return home_url('/vendor-portal/');
            }
        }
        return $redirect_to;
    }

    public static function render_vendor_portal_shortcode($atts) {
        if (!self::is_current_user_vendor()) {
            return '<p>' . __('You must be logged in as a Vendor-MM to view this content.', 'vss') . '</p>' . wp_login_form(['echo' => false]);
        }
        ob_start();
        echo '<div class="vss-frontend-portal">';
        if (isset($_GET['vss_notice'])) {
            $notice_type = 'vss-success-notice'; $message = '';
            switch($_GET['vss_notice']) {
                case 'costs_saved': $message = __('Costs saved successfully!', 'vss'); break;
                case 'tracking_saved': $message = __('Tracking information saved successfully!', 'vss'); break;
                case 'note_added': $message = __('Note added successfully!', 'vss'); break;
                case 'production_confirmed': $message = __('Production confirmed and estimated ship date updated!', 'vss'); break;
                case 'mockup_sent': $message = __('Mockup sent for customer approval!', 'vss'); break;
                case 'production_file_sent': $message = __('Production files sent for customer approval!', 'vss'); break;
            }
            if ($message) echo '<div class="'.$notice_type.'"><p>' . esc_html($message) . '</p></div>';
        }
         if (isset($_GET['vss_error'])) {
            $message = '';
            switch($_GET['vss_error']) {
                case 'date_required': $message = __('Estimated ship date is required.', 'vss'); break;
                case 'date_format': $message = __('Invalid estimated ship date format. Please use YYYY-MM-DD.', 'vss'); break;
                case 'file_upload_failed': $message = __('File upload failed. Please try again.', 'vss'); break;
                case 'no_files_uploaded': $message = __('No files were uploaded. Please select at least one file.', 'vss'); break;
                case 'invalid_approval_type': $message = __('Invalid approval type specified.', 'vss'); break;
            }
            if ($message) echo '<div class="vss-error-notice"><p>' . esc_html($message) . '</p></div>';
        }

        $action = isset($_GET['vss_action']) ? sanitize_key($_GET['vss_action']) : 'dashboard';
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        if ($order_id && $action === 'view_order') {
            self::render_frontend_order_details($order_id);
        } else {
            self::render_frontend_dashboard_summary();
            self::render_frontend_orders_list();
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function render_frontend_dashboard_summary() {
        $vendor_id = get_current_user_id();
        $processing_orders_query = new WC_Order_Query([
            'limit'      => -1,
            'status'     => 'wc-processing',
            'meta_key'   => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'return'     => 'ids'
        ]);
        $processing_order_ids = $processing_orders_query->get_orders();

        $late_orders_count = 0;
        if (!empty($processing_order_ids)) {
            foreach($processing_order_ids as $order_id_val) {
                $estimated_ship_date = get_post_meta($order_id_val, '_vss_estimated_ship_date', true);
                $admin_confirmed_at = get_post_meta($order_id_val, '_vss_admin_production_confirmed_at', true);
                $vendor_confirmed_at = get_post_meta($order_id_val, '_vss_vendor_production_confirmed_at', true);
                if (($admin_confirmed_at || $vendor_confirmed_at) && $estimated_ship_date) {
                    $order_check_late = wc_get_order($order_id_val);
                    if ($order_check_late && $order_check_late->has_status('wc-processing') && strtotime($estimated_ship_date) < current_time('timestamp')) {
                        $late_orders_count++;
                    }
                }
            }
        }
        ?>
        <h2><?php _e('Dashboard Summary', 'vss'); ?></h2>
        <div class="vss-stat-boxes">
            <div class="vss-stat-box-fe"><span class="stat-number-fe"><?php echo count($processing_order_ids); ?></span><span class="stat-label-fe"><?php _e('My Orders in Processing', 'vss'); ?></span></div>
            <div class="vss-stat-box-fe <?php echo $late_orders_count > 0 ? 'is-critical' : ''; ?>"><span class="stat-number-fe"><?php echo $late_orders_count; ?></span><span class="stat-label-fe"><?php _e('Orders Late for Shipment', 'vss'); ?></span></div>
        </div>
        <?php
    }

    private static function render_frontend_orders_list() {
        $vendor_id = get_current_user_id();
        $current_page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
        $orders_query_args = [
            'limit'      => 20,
            'paginate'   => true,
            'page'       => $current_page,
            'meta_key'   => '_vss_vendor_user_id',
            'meta_value' => $vendor_id,
            'status'     => array('wc-processing', 'wc-shipped', 'wc-cancelled', 'wc-completed', 'wc-on-hold'),
            'orderby'    => 'date',
            'order'      => 'DESC',
            'return'     => 'objects',
        ];

        $orders_query = new WC_Order_Query($orders_query_args);
        $query_output = $orders_query->get_orders();
        $results_for_loop = [];
        if (is_object($query_output) && property_exists($query_output, 'orders') && is_array($query_output->orders)) { $results_for_loop = $query_output->orders; }
        elseif (is_array($query_output)) { $results_for_loop = $query_output; }
        ?>
        <h2><?php _e('My Assigned Orders', 'vss'); ?></h2>
        <table class="vss-orders-table">
            <thead><tr>
                <th><?php _e('Order', 'vss'); ?></th>
                <th><?php _e('Date Assigned', 'vss'); ?></th>
                <th><?php _e('Status', 'vss'); ?></th>
                <th><?php _e('Ship By Est.', 'vss'); ?></th>
                <th><?php _e('Mockup', 'vss'); ?></th>
                <th><?php _e('Prod. Files', 'vss'); ?></th>
                <th><?php _e('Actions', 'vss'); ?></th>
            </tr></thead>
            <tbody><?php if (!empty($results_for_loop)): foreach ($results_for_loop as $order_item):
                $order_obj = null;
                if ($order_item instanceof WC_Order) { $order_obj = $order_item; }
                elseif (is_numeric($order_item)) { $order_obj = wc_get_order($order_item); }
                elseif (is_object($order_item) && isset($order_item->ID)) { $order_obj = wc_get_order($order_item->ID); }
                elseif (is_array($order_item) && isset($order_item['ID'])) { $order_obj = wc_get_order($order_item['ID']);}
                if (!$order_obj || !is_a($order_obj, 'WC_Order')) continue;

                $order_id = $order_obj->get_id();
                $estimated_ship_date_str = get_post_meta($order_id, '_vss_estimated_ship_date', true);
                $estimated_ship_date_display = __('N/A', 'vss');
                $is_late = false;
                if ($estimated_ship_date_str) {
                    $estimated_ship_date_ts = strtotime($estimated_ship_date_str);
                    $estimated_ship_date_display = date_i18n(wc_date_format(), $estimated_ship_date_ts);
                    if ($order_obj->has_status('wc-processing') && $estimated_ship_date_ts < current_time('timestamp')) {
                        $is_late = true;
                    }
                }

                $mockup_status = get_post_meta($order_id, '_vss_mockup_status', true) ?: 'none';
                $prod_file_status = get_post_meta($order_id, '_vss_production_file_status', true) ?: 'none';

                $row_class = '';
                if ($mockup_status === 'disapproved' || $prod_file_status === 'disapproved') {
                    $row_class = 'vss-status-disapproved';
                } elseif ($mockup_status === 'pending_approval' || $prod_file_status === 'pending_approval') {
                    $row_class = 'vss-status-pending';
                } elseif (($mockup_status === 'approved' || $mockup_status === 'none') && ($prod_file_status === 'approved' || $prod_file_status === 'none')) {
                    if ($mockup_status === 'approved' || $prod_file_status === 'approved') { // At least one was actively approved
                         $row_class = 'vss-status-approved';
                    }
                }
                 // Late status takes precedence for visual warning if order is processing
                if ($is_late && $order_obj->has_status('wc-processing')) {
                    $row_class = 'vss-status-late';
                }

                ?>
                <tr class="<?php echo esc_attr($row_class); ?>">
                    <td><a href="<?php echo esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_obj->get_id()], home_url('/vendor-portal/'))); ?>">#<?php echo $order_obj->get_order_number(); ?></a></td>
                    <td><?php $assigned_timestamp = get_post_meta($order_obj->get_id(), '_vss_assigned_at', true); echo $assigned_timestamp ? date_i18n(wc_date_format(), $assigned_timestamp) : date_i18n(wc_date_format(), $order_obj->get_date_created()->getTimestamp()); ?></td>
                    <td><?php echo wc_get_order_status_name($order_obj->get_status()); if ($is_late && $order_obj->has_status('wc-processing')): ?><span class="vss-order-late-indicator"><?php _e('LATE', 'vss'); ?></span><?php endif; ?></td>
                    <td><?php echo esc_html($estimated_ship_date_display); ?></td>
                    <td><?php self::display_approval_status_text($mockup_status); ?></td>
                    <td><?php self::display_approval_status_text($prod_file_status); ?></td>
                    <td class="vss-order-actions"><a href="<?php echo esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_obj->get_id()], home_url('/vendor-portal/'))); ?>"><?php _e('View / Manage', 'vss'); ?></a></td>
                </tr><?php endforeach; else: ?><tr><td colspan="7"><?php _e('No orders assigned to you yet.', 'vss'); ?></td></tr><?php endif; ?>
            </tbody>
        </table>
        <?php if ( isset($orders_query->max_num_pages) && $orders_query->max_num_pages > 1 ) { echo '<nav class="woocommerce-pagination vss-pagination">'; echo paginate_links( apply_filters( 'woocommerce_pagination_args', array( 'base' => esc_url_raw( add_query_arg( 'paged', '%#%', remove_query_arg('paged', home_url('/vendor-portal/')) ) ), 'format' => '?paged=%#%', 'add_args' => false, 'current' => max( 1, $current_page ), 'total' => $orders_query->max_num_pages, 'prev_text' => is_rtl() ? '&rarr;' : '&larr;', 'next_text'     => is_rtl() ? '&larr;' : '&rarr;', 'type' => 'list', 'end_size' => 3, 'mid_size' => 3, ))); echo '</nav>'; } ?>
        <?php
    }

    private static function display_approval_status_text($status) {
        switch ($status) {
            case 'pending_approval': _e('Pending', 'vss'); break;
            case 'approved': _e('Approved', 'vss'); break;
            case 'disapproved': _e('Disapproved', 'vss'); break;
            case 'none':
            default: _e('N/A', 'vss'); break;
        }
    }

    private static function render_frontend_order_details($order_id) {
        $order = wc_get_order($order_id); $current_user_id = get_current_user_id();
        if (!$order || get_post_meta($order_id, '_vss_vendor_user_id', true) != $current_user_id) { echo '<p class="vss-error-notice">' . __('Error: Order not found or you do not have permission to view it.', 'vss') . '</p>'; return; }
        $portal_page_url = home_url('/vendor-portal/');
        echo '<h2>' . sprintf(__('Order #%s Details', 'vss'), $order->get_order_number()) . '</h2>';
        echo '<p><a href="' . esc_url($portal_page_url) . '">&laquo; ' . __('Back to Orders List', 'vss') . '</a></p>';

        self::render_vendor_production_confirmation_section($order);
        echo '<hr style="margin: 20px 0;">';
        ?>
        <div class="vss-order-tabs">
            <a class="nav-tab" href="#tab-customer-shipping"><?php _e('Customer & Shipping', 'vss'); ?></a>
            <a class="nav-tab" href="#tab-products-customizations"><?php _e('Products & Customizations', 'vss'); ?></a>
            <a class="nav-tab" href="#tab-mockup-approval"><?php _e('Mockup Approval', 'vss'); ?></a>
            <a class="nav-tab" href="#tab-prodfile-approval"><?php _e('Production File Approval', 'vss'); ?></a>
            <a class="nav-tab" href="#tab-costs"><?php _e('My Costs', 'vss'); ?></a>
            <a class="nav-tab" href="#tab-tracking"><?php _e('Shipment Tracking', 'vss'); ?></a>
            <a class="nav-tab" href="#tab-notes"><?php _e('Private Notes', 'vss'); ?></a>
            <a class="nav-tab" href="#tab-admin-files"><?php _e('Admin Files', 'vss'); ?></a>
        </div>
        <div id="tab-customer-shipping" class="vss-tab-content">
            <h4><?php _e('Order Status', 'vss'); ?></h4> <p><strong><?php _e('Current Woo Status:', 'vss'); ?></strong> <?php echo wc_get_order_status_name($order->get_status()); ?></p>
            <hr style="margin: 10px 0;">
            <h4><?php _e('Customer Details', 'vss'); ?></h4>
            <p><strong><?php _e('Name:', 'vss'); ?></strong> <?php echo $order->get_formatted_billing_full_name(); ?></p>
            <p><strong><?php _e('Customer Phone:', 'vss'); ?></strong> <?php echo esc_html($order->get_billing_phone()); ?></p>
            <h4><?php _e('Shipping Information', 'vss'); ?></h4>
            <?php if ($order->get_shipping_method()): ?><p><strong><?php _e('Shipping Method:', 'vss'); ?></strong> <?php echo esc_html($order->get_shipping_method()); ?></p><?php endif; ?>
            <?php if ($order->get_formatted_shipping_address()): ?><p><strong><?php _e('Shipping Address:', 'vss'); ?></strong><br><?php echo $order->get_formatted_shipping_address(); ?></p><?php else: ?><p><?php _e('No shipping address provided.', 'vss'); ?></p><?php endif; ?>
        </div>
        <div id="tab-products-customizations" class="vss-tab-content"><h4><?php _e('Products & Customization Details', 'vss'); ?></h4><table class="vss-orders-table"><thead><tr><th><?php _e('Product', 'vss'); ?></th><th><?php _e('SKU', 'vss'); ?></th><th><?php _e('Quantity', 'vss'); ?></th><th><?php _e('Customization Details', 'vss'); ?></th></tr></thead><tbody><?php foreach($order->get_items() as $item_id => $item): $_product = $item->get_product(); ?><tr><td><?php echo $item->get_name(); ?></td><td><?php echo $_product ? $_product->get_sku() : '–'; ?></td><td><?php echo $item->get_quantity(); ?></td><td><div class="vss-zakeke-info"><?php $zakeke_meta_data_raw = $item->get_meta('zakeke_data', true); $primary_zakeke_design_id = null; $zakeke_preview_url = null; if ($zakeke_meta_data_raw) { $zakeke_data_parsed = is_string($zakeke_meta_data_raw) ? json_decode($zakeke_meta_data_raw, true) : (array) $zakeke_meta_data_raw; if (is_array($zakeke_data_parsed)) { if (isset($zakeke_data_parsed['design'])) $primary_zakeke_design_id = $zakeke_data_parsed['design']; if (isset($zakeke_data_parsed['previews']) && is_array($zakeke_data_parsed['previews']) && !empty($zakeke_data_parsed['previews'])) { foreach ($zakeke_data_parsed['previews'] as $preview_item) { $preview = (array) $preview_item; if (isset($preview['label']) && strtolower($preview['label']) === 'front' && isset($preview['url'])) { $zakeke_preview_url = $preview['url']; break; } } if (!$zakeke_preview_url && isset($zakeke_data_parsed['previews'][0])) { $first_preview = (array) $zakeke_data_parsed['previews'][0]; if (isset($first_preview['url'])) $zakeke_preview_url = $first_preview['url']; } } } } $secondary_design_id = $item->get_meta('_vss_zakeke_secondary_design_id', true); $zakeke_print_zip_url = $item->get_meta('_vss_zakeke_printing_files_zip_url', true); $specific_design_string = $item->get_meta('_munchmakers_zakeke_design_info_string', true); if ($specific_design_string) { echo '<div>' . nl2br(esc_html($specific_design_string)) . '</div>'; } else { if ($primary_zakeke_design_id) echo '<div><strong>Design Doc ID:</strong> ' . esc_html($primary_zakeke_design_id) . '</div>'; if ($secondary_design_id) echo '<div><strong>Design ID:</strong> ' . esc_html($secondary_design_id) . '</div>'; if (!$primary_zakeke_design_id && !$secondary_design_id) echo '<div>' . __('No Zakeke Design IDs available.', 'vss') . '</div>'; } if ($zakeke_preview_url) { echo '<div style="margin-top:5px;"><a href="' . esc_url($zakeke_preview_url) . '" target="_blank" title="' . __('View Full Preview', 'vss') . '"><img src="' . esc_url($zakeke_preview_url) . '" alt="' . __('Design Preview', 'vss') . '" class="vss-zakeke-preview-image" /></a></div>'; } else { echo '<div style="margin-top:5px;">' . __('No Zakeke preview image found.', 'vss') . '</div>'; } if ($zakeke_print_zip_url) { echo '<div class="vss-zakeke-download-link" style="margin-top:10px;"><a href="' . esc_url($zakeke_print_zip_url) . '" target="_blank">' . __('Download Design Files (ZIP)', 'vss') . '</a></div>'; } else { echo '<div style="margin-top:10px;">' . __('Zakeke Download ZIP link not yet available.', 'vss') . '</div>'; if ($primary_zakeke_design_id && !$item->get_meta('_vss_zakeke_printing_files_zip_url', true) && !get_post_meta($order->get_id(), '_vss_zakeke_fetch_attempt_complete', true) ) { echo '<button class="vss-manual-fetch-zakeke-zip" data-order-id="' . esc_attr($order_id) . '" data-item-id="' . esc_attr($item_id) . '" data-zakeke-design-id="' . esc_attr($primary_zakeke_design_id) . '">' . __('Attempt to Fetch Zakeke ZIP', 'vss') . '</button>'; echo '<div class="vss-fetch-zip-feedback" style="font-size:0.9em; margin-top:5px;"></div>'; } elseif ($primary_zakeke_design_id && get_post_meta($order->get_id(), '_vss_zakeke_fetch_attempt_complete', true) && !$item->get_meta('_vss_zakeke_printing_files_zip_url', true)) { echo '<p><small><em>' . __('(ZIP file check previously attempted.)', 'vss') . '</em></small></p>'; } } ?></div></td></tr><?php endforeach; ?></tbody></table></div>
        <div id="tab-mockup-approval" class="vss-tab-content">
            <?php self::render_frontend_approval_section($order, 'mockup'); ?>
        </div>
        <div id="tab-prodfile-approval" class="vss-tab-content">
            <?php self::render_frontend_approval_section($order, 'production_file'); ?>
        </div>
        <div id="tab-costs" class="vss-tab-content"><?php self::render_frontend_costs_form($order); ?></div>
        <div id="tab-tracking" class="vss-tab-content"><?php self::render_frontend_tracking_form($order); ?></div>
        <div id="tab-notes" class="vss-tab-content"><?php self::render_frontend_private_notes($order); ?></div>
        <div id="tab-admin-files" class="vss-tab-content"><?php self::render_frontend_admin_files($order); ?></div>
        <?php
    }

    private static function render_vendor_production_confirmation_section($order) {
        $order_id = $order->get_id();
        $estimated_ship_date = get_post_meta($order_id, '_vss_estimated_ship_date', true);
        $vendor_confirmed_at = get_post_meta($order_id, '_vss_vendor_production_confirmed_at', true);
        $admin_confirmed_at = get_post_meta($order_id, '_vss_admin_production_confirmed_at', true);

        $countdown_text = ''; $countdown_class = '';
        $confirmed_by_text = '';
        $last_confirmation_timestamp = 0;

        if ($vendor_confirmed_at && $admin_confirmed_at) {
            if ($vendor_confirmed_at > $admin_confirmed_at) {
                $confirmed_by_text = sprintf(__('Last updated by you on %s.', 'vss'), date_i18n(wc_date_format() . ' ' . wc_time_format(), $vendor_confirmed_at));
                $last_confirmation_timestamp = $vendor_confirmed_at;
            } else {
                $confirmed_by_text = sprintf(__('Last updated by admin on %s.', 'vss'), date_i18n(wc_date_format() . ' ' . wc_time_format(), $admin_confirmed_at));
                $last_confirmation_timestamp = $admin_confirmed_at;
            }
        } elseif ($vendor_confirmed_at) {
            $confirmed_by_text = sprintf(__('Confirmed by you on %s.', 'vss'), date_i18n(wc_date_format() . ' ' . wc_time_format(), $vendor_confirmed_at));
            $last_confirmation_timestamp = $vendor_confirmed_at;
        } elseif ($admin_confirmed_at) {
            $confirmed_by_text = sprintf(__('Confirmed by admin on %s.', 'vss'), date_i18n(wc_date_format() . ' ' . wc_time_format(), $admin_confirmed_at));
            $last_confirmation_timestamp = $admin_confirmed_at;
        }

        if ($estimated_ship_date) {
            $ship_timestamp = strtotime($estimated_ship_date); $today_timestamp = current_time('timestamp');
            $ship_date_only = date('Y-m-d', $ship_timestamp); $today_date_only = date('Y-m-d', $today_timestamp);
            $days_diff = (strtotime($ship_date_only) - strtotime($today_date_only)) / DAY_IN_SECONDS;

            if ($days_diff < 0 && $order->has_status('wc-processing')) {
                $countdown_text = sprintf(_n('%d DAY LATE', '%d DAYS LATE', abs(round($days_diff)), 'vss'), abs(round($days_diff)));
                $countdown_class = 'is-late';
            } elseif ($days_diff == 0 && $order->has_status('wc-processing')) {
                $countdown_text = __('Ships Today', 'vss'); $countdown_class = 'is-today';
            } elseif ($days_diff > 0) {
                $countdown_text = sprintf(_n('%d day left to ship', '%d days left to ship', round($days_diff), 'vss'), round($days_diff));
                $countdown_class = 'is-upcoming';
            }
        }
        ?>
        <div class="vss-production-confirmation-fe">
            <h3><?php _e('Production Status & Ship Estimate', 'vss'); ?></h3>
            <?php if ($estimated_ship_date): ?>
                <div class="vss-confirmation-info-fe">
                    <p><strong><?php _e('Current Estimated Ship Date:', 'vss'); ?></strong> <?php echo esc_html(date_i18n(wc_date_format(), strtotime($estimated_ship_date))); ?></p>
                    <?php if ($confirmed_by_text) : ?><p><?php echo esc_html($confirmed_by_text); ?></p><?php endif; ?>
                </div>
                <?php if ($countdown_text): ?><p class="vss-ship-date-countdown-fe <?php echo esc_attr($countdown_class); ?>"><?php echo esc_html($countdown_text); ?></p><?php endif; ?>
                <hr>
            <?php endif; ?>

            <form method="post" id="vss_vendor_confirm_production_form" action="<?php echo esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order->get_id()], home_url('/vendor-portal/'))); ?>">
                <?php wp_nonce_field('vss_vendor_confirm_production_frontend', '_vss_vendor_confirm_nonce_fe'); ?>
                <input type="hidden" name="vss_fe_action" value="vendor_confirm_production">
                <input type="hidden" name="order_id" value="<?php echo $order->get_id(); ?>">

                <p><label for="vss_vendor_estimated_ship_date"><strong><?php echo ($vendor_confirmed_at || $admin_confirmed_at) ? __('Update My Estimated Ship Date:', 'vss') : __('Set My Estimated Ship Date:', 'vss'); ?></strong></label>
                <input type="text" id="vss_vendor_estimated_ship_date" name="vss_vendor_estimated_ship_date" class="vss-datepicker-fe" value="<?php echo esc_attr($estimated_ship_date); ?>" placeholder="YYYY-MM-DD" autocomplete="off" /></p>

                <p class="vss-form-actions">
                    <button type="submit" class="button">
                        <?php echo ($vendor_confirmed_at || $admin_confirmed_at) ? __('Update Est. Ship Date & Confirm My Production', 'vss') : __('Confirm My Production & Set Est. Ship Date', 'vss'); ?>
                    </button>
                </p>
                <p class="description"><small><?php _e('Setting or updating this date will mark your confirmation of production. The customer may be notified if this is the first confirmation.', 'vss'); ?></small></p>
            </form>
        </div>
        <?php
    }

    private static function render_frontend_approval_section($order, $type = 'mockup') {
        $order_id = $order->get_id();
        $status = get_post_meta($order_id, "_vss_{$type}_status", true) ?: 'none';
        $files_ids = get_post_meta($order_id, "_vss_{$type}_files", true); // Array of attachment IDs
        $files_ids = is_array($files_ids) ? $files_ids : [];
        $vendor_notes = get_post_meta($order_id, "_vss_{$type}_vendor_notes", true);
        $customer_notes = get_post_meta($order_id, "_vss_{$type}_customer_notes", true);
        $sent_at = get_post_meta($order_id, "_vss_{$type}_sent_at", true);
        $responded_at = get_post_meta($order_id, "_vss_{$type}_responded_at", true);

        $type_label_uc = ($type === 'mockup') ? __('Mockup', 'vss') : __('Production File', 'vss');
        $tab_hash = ($type === 'mockup') ? '#tab-mockup-approval' : '#tab-prodfile-approval';
        ?>
        <h3><?php printf(esc_html__('%s Approval Status: %s', 'vss'), $type_label_uc, self::get_status_friendly_name($status)); ?></h3>

        <?php if ($status === 'pending_approval' && $sent_at): ?>
            <p><?php printf(esc_html__('Sent for customer approval on %s.', 'vss'), date_i18n(wc_date_format() . ' ' . wc_time_format(), $sent_at)); ?></p>
        <?php elseif ($status === 'approved' && $responded_at): ?>
            <p><?php printf(esc_html__('Approved by customer on %s.', 'vss'), date_i18n(wc_date_format() . ' ' . wc_time_format(), $responded_at)); ?></p>
        <?php elseif ($status === 'disapproved' && $responded_at): ?>
            <p><?php printf(esc_html__('Disapproved by customer on %s.', 'vss'), date_i18n(wc_date_format() . ' ' . wc_time_format(), $responded_at)); ?></p>
            <?php if ($customer_notes): ?>
                <div class="vss-customer-feedback"><strong><?php _e('Customer Feedback:', 'vss'); ?></strong><br><?php echo nl2br(esc_html($customer_notes)); ?></div>
            <?php else: ?>
                 <p><?php _e('Admin has been notified to get feedback from the customer.', 'vss'); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($files_ids)): ?>
            <h4><?php _e('Submitted Files:', 'vss'); ?></h4>
            <div class="vss-submitted-approval-files">
            <?php foreach ($files_ids as $file_id):
                $file_url = wp_get_attachment_url($file_id);
                $file_path = get_attached_file($file_id);
                $file_name = $file_path ? basename($file_path) : __('File not found', 'vss');
                if($file_url):
            ?>
                <div class="vss-approval-file-item">
                    <a href="<?php echo esc_url($file_url); ?>" target="_blank">
                        <?php if (wp_attachment_is_image($file_id)):
                            $thumb_url = wp_get_attachment_image_src($file_id, 'thumbnail'); // thumbnail, medium, large, full
                        ?>
                            <img src="<?php echo esc_url($thumb_url[0]); ?>" alt="<?php echo esc_attr($file_name); ?>" />
                        <?php else: ?>
                            <span class="dashicons dashicons-media-default" style="font-size: 50px; width:50px; height:50px;"></span>
                        <?php endif; ?>
                        <span class="vss-approval-file-name"><?php echo esc_html($file_name); ?></span>
                    </a>
                </div>
            <?php endif; endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($vendor_notes): ?>
            <p><strong><?php _e('Your Notes to Customer (sent with this version):', 'vss'); ?></strong><br><?php echo nl2br(esc_html($vendor_notes)); ?></p>
        <?php endif; ?>


        <?php if ($status === 'none' || $status === 'disapproved'): ?>
            <form method="post" action="<?php echo esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order_id], home_url('/vendor-portal/')) . $tab_hash); ?>" enctype="multipart/form-data" class="vss-approval-form">
                <?php wp_nonce_field("vss_send_{$type}_for_approval_frontend", "_vss_{$type}_approval_nonce_fe"); ?>
                <input type="hidden" name="vss_fe_action" value="send_<?php echo esc_attr($type); ?>_for_approval">
                <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
                <input type="hidden" name="approval_type" value="<?php echo esc_attr($type); ?>">

                <p><label for="vss_<?php echo esc_attr($type); ?>_files_upload"><strong><?php _e('Upload Images (Multiple Allowed):', 'vss'); ?></strong></label><br>
                <input type="file" id="vss_<?php echo esc_attr($type); ?>_files_upload" name="vss_approval_files[]" multiple="multiple" accept="image/*,application/pdf,.ai,.eps,.svg"></p>
                 <p class="description"><small><?php _e('Accepted file types: images (jpg, png, gif), PDF, AI, EPS, SVG.', 'vss'); ?></small></p>
                <?php if ($status === 'disapproved'): ?>
                    <p class="description"><small><?php _e('Re-uploading files will replace the previously disapproved set. If no new files are uploaded, the existing files (shown above) will be considered for re-submission if you add new notes.', 'vss'); ?></small></p>
                <?php endif; ?>


                <p><label for="vss_<?php echo esc_attr($type); ?>_vendor_notes"><strong><?php _e('Notes to Customer (Optional):', 'vss'); ?></strong></label><br>
                <textarea id="vss_<?php echo esc_attr($type); ?>_vendor_notes" name="vss_vendor_notes" rows="3" style="width:100%;"><?php echo esc_textarea(($status === 'disapproved' && $vendor_notes) ? $vendor_notes : ''); ?></textarea></p>

                <p class="vss-form-actions"><button type="submit" class="button"><?php echo ($status === 'disapproved') ? __('Re-submit for Approval', 'vss') : __('Send for Approval', 'vss'); ?></button></p>
            </form>
        <?php endif; ?>
        <?php
    }

    private static function get_status_friendly_name($status_key) {
        $names = [
            'none' => __('Not Sent', 'vss'),
            'pending_approval' => __('Pending Customer Approval', 'vss'),
            'approved' => __('Approved by Customer', 'vss'),
            'disapproved' => __('Disapproved by Customer', 'vss'),
        ];
        return isset($names[$status_key]) ? $names[$status_key] : __('Unknown', 'vss');
    }

    private static function render_frontend_costs_form($order) { $saved_costs = get_post_meta($order->get_id(), '_vss_order_costs', true); $saved_costs = is_array($saved_costs) ? $saved_costs : []; ?><h3><?php _e('My Costs for this Order', 'vss'); ?></h3><p><?php _e('Enter your costs for fulfilling this order. This will be used to calculate your payout.', 'vss'); ?></p><form method="post" action="<?php echo esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order->get_id()], home_url('/vendor-portal/'))); ?>#tab-costs"><?php wp_nonce_field('vss_save_costs_frontend', '_vss_costs_nonce_fe'); ?><input type="hidden" name="vss_fe_action" value="save_costs"><input type="hidden" name="order_id" value="<?php echo $order->get_id(); ?>"><table class="vss-costs-input-table-fe"><thead><tr><th><?php _e('Product', 'vss'); ?></th><th style="text-align: right;"><?php _e('My Cost', 'vss'); ?></th></tr></thead><tbody><?php foreach($order->get_items() as $item_id => $item): $cost = isset($saved_costs['line_items'][$item_id]) ? wc_format_localized_price($saved_costs['line_items'][$item_id]) : ''; $_product = $item->get_product(); ?><tr><td><div class="cost-product-name"><?php echo $item->get_name(); ?> &times; <?php echo $item->get_quantity(); ?></div><div class="cost-product-sku"><?php echo $_product ? $_product->get_sku() : __('No SKU', 'vss'); ?></div></td><td style="text-align: right;"><?php echo get_woocommerce_currency_symbol(); ?> <input type="text" class="vss-cost-input-fe" name="vss_costs[line_items][<?php echo $item_id; ?>]" value="<?php echo esc_attr($cost); ?>"></td></tr><?php endforeach; ?></tbody></table><div class="totals-section"><p><strong><?php _e('My Shipping Cost:', 'vss'); ?></strong> <?php echo get_woocommerce_currency_symbol(); ?> <input type="text" class="vss-cost-input-fe" name="vss_costs[shipping_cost]" value="<?php echo esc_attr(wc_format_localized_price($saved_costs['shipping_cost'] ?? '')); ?>"></p><hr><p><strong><?php _e('Total Cost For This Order:', 'vss'); ?></strong> <span id="vss-total-cost-display-fe" data-currency="<?php echo get_woocommerce_currency_symbol(); ?>"><?php echo wc_price($saved_costs['total_cost'] ?? 0); ?></span></p></div><p class="vss-form-actions"><button type="submit" class="button"><?php _e('Save Costs', 'vss'); ?></button></p></form><?php }

    private static function get_shipping_carriers_list() {
        $prioritized = ['UPS' => 'UPS', 'USPS' => 'USPS', 'FedEx' => 'FedEx', 'DHL Express' => 'DHL Express', 'DHL Paket' => 'DHL Paket'];
        // The full_list_raw string is extremely long and was provided by the user.
        // For brevity in this response, it's truncated here, but assume the full string is used.
        $full_list_raw = "247express:247 Express,2u-express:2U Express,360lion:360lion Express,4px:4px,99minutos:99minutos,a-duie-pyle:A. Duie Pyle,aaa-cooper:AAA Cooper,abf:ABF,abx-express:ABX Express,aci-logistix:ACI Logistix,acommerce:ACOMMERCE,acs-courier:ACS Courier,act-logistic:ACT logistic,adaone:ADSOne,airpak-express:Airpak Express,airspeed-international:Airspeed International,airwings-india:Airwings India,ait-worldwide:AIT Worldwide,ajex:AJEX,albania-post:Albania Post,aliexpress-standard-shipping:Aliexpress Standard Shipping,allekurier:Allekurier,alliedexpress:Allied Express,am-home-delivery:AM Home Delivery,amazon-in:Amazon Shipping IN,amazon-it:Amazon IT,amazon-logistics:Amazon Logistics,amazon-uk:Amazon UK,amsegroup:AMSEGROUP,an-post:An Post,andreani:Andreani,anjun:Anjun,ant-eparcel:Ant Eparcel,antilles-post:Antilles Post,apc:APC,apd:APD,aramex:Aramex,aramex-au:Aramex AU,aramex-nz:Aramex NZ,aras-kargo:Aras Kargo,arco-spedizioni:Arco Spedizioni,ark-express:Ark express,armenia-post:Armenia Post,arrowxl:ArrowXL,aruba-post:Aruba Post,asendia:Asendia,asendia-de:Asendia Germany,asendia-uk:Asendia UK,asendia-usa:Asendia USA,associated-global-systems:Associated Global Systems,asyad-express:Asyad Express,australia-ems:Australia EMS,australia-post:Australia Post,ausworld express:Ausworld Express,averitt-express:Averitt Express,axlehire:AxleHire,aymakan:AyMakan,azerbaijan-post:Azerbaijan Post,bangladesh-ems:Bangladesh EMS,barbados-post:Barbados Post,bee-express:Beexpress,beebird-logistics:Beebird Logistics,belarus-post:Belarus Post,belgium-post:Bpost,benin-post:Benin Post,bermuda-post:Bermuda Post,best-express-my:BEST Express MY,best-express-th:BEST Express TH,better-trucks:Better Trucks,bex-express:BEX Express,bgy:BGY,bh-posta:BH Posta,bigfoot-express:Bigfoot Express,bird-system-ltd:BIRD SYSTEM LTD,biz-courier:Biz Courier,bjytsywl:BJYTSYWL,blexpress:Blexpress,blue-sky-express:Blue Sky Express,bluecare:Bluecare Express,bluedart:Bluedart,blueex:BlueEx,bluegrace-logistics:Bluegrace Logistics,bobbox:BobBox,bombax:Bombax,bombino-express:Bombino Express,bondscouriers:Bonds Couriers,border-express:Border Express,bosnia-and-herzegovina-post:Bosnia And Herzegovina Post,bosta:Bosta,box-now:BOX NOW,boxberry:Boxberry,boxc-logistics:Boxc Logistics,brazil-correios:Brazil Correios,bridge:Bridge,bring:Bring,brink-transport:Brink Transport,brt:BRT,bulgaria-post:Bulgaria Post,c3xpress:C3xpress,cacesa-postal:Cacesa Postal,cainiao:Cainiao,call-courier:Call Courier,cambodia-post:Cambodia Post,camposcadilhe:Campos&Cadilhe,canada-air-express:Canada Air Express,canada-post:Canada Post,canpar:Canpar,cargo-expreso:Cargo Expreso,cargo-international:Cargo International,cargus:Cargus,cbl-logistica:CBL Logistica,cdl:CDL,central-transport:Central Transport,ceska-posta:Ceska Posta,ceva-logistics:CEVA Logistics,cgs-Express:CGS Express,ch-express:CH EXPRESS,chilexpress:Chilexpress,china-post:China Post,china-post-e-commerce:China Post E-commerce,china-railway-flying-leopard:China Railway Flying Leopard,chinz-logistics:Chinz Logistics,chit-chats:Chit Chats,chronopost:Chronopost,chukou1:Chukou1,cirro:Cirro,cirro-parcel-fr:Cirro Parcel FR,citi-sprint:Citi Sprint,city-express:City Express,city-link-express:City-Link Express,cj-logistics:CJ Logistics,cj-packet:CJPacket,cloud-mail-cross-border-express:Cloud mail cross border Express,cne-express:CNE Express,colis-prive:Colis Prive,colissimo:Colissimo,collectplus:CollectPlus,collivery:Collivery,colombia-post:Colombia Post,cometcourier:Comet Hellas,con-way-freight:Con-way Freight,connectco:ConnectCo,coordinadora:Coordinadora,correios-cabo-verde:Correios Cabo Verde,correos-chile:Correos Chile,correos-de-cuba:Correos de Cuba,correos-de-mexico:Correos de Mexico,correos-express:Correos Express,correos-spain:Correos España,cosco-express:Cosco Express,costa-rica-post:Costa Rica Post,courant:Courant,courier-center:Courier Center,courier-it:Courier IT,courierpost:CourierPost,couriersplease:CouriersPlease,cpszy:CPSZY,crazy-express:Crazy Express,credifin-logistics:Credifin Logistics,croatia-post:Croatia Post,cross-country-freight-solution:Cross Country Freight Solution,ctc-express:CTC Express,ctt-express:CTT Express,ctt-express-spain:CTT Express Spain,cuba-post:Cuba Post,cubyn:Cubyn,custom-companies:Custom Companies,cy-epxress:CY Epxress,cycloon:Cycloon,cyprus-post:Cyprus Post,czech-post:Czech Post,dachser:Dachser,danske-fragtmænd:Danske Fragtmænd,dao:DAO,dawn-wing:Dawn Wing,daylight-transport:Daylight Transport,dayross:Day & Ross,Dayton-Freight:Dayton Freight,db-schenker:DB Schenker,dd-express:DD Express,dealersend:DealerSend,dealfy:Dealfy,delhivery:Delhivery,deliveright:Deliveright,delivro:Delivro,dellin:Dellin,deltec-courier:Deltec Courier,denmark-post:Denmark Post,deppon:Deppon,deutsche-post:Deutsche Post,deutsche-post-dhl:Deutsche Post DHL,dexpress:DExpress,dhl-at:DHL at,dhl-be:DHL Benelux,dhl-cz:DHL cz,dhl-de:DHL Germany,dhl-ecommerce:DHL eCommerce,dhl-ecommerce-asia:DHL eCommerce Asia,dhl-es:DHL Spain,dhl-freight:DHL Freight,dhl-global-mail:DHL Global Mail,dhl-mx:DHL Mexico,dhl-parcel:DHL Parcel,dhl-parcel-se:DHL Parcel Spain,dhl-parcel-uk:DHL Parcel UK,dhl-pl:DHL Poland,dhl-se:DHL se,dhl-uk:DHL Express UK,dhl-us:DHL US,dhlink:Dhlink,dhlparcel-nl:DHLParcel NL,diamond-line-delivery:Diamond Line Delivery,dicom:Dicom,direct-freight:Direct Freight,dohrn:Dohrn,domex:Domex,dominican-post:Dominican Post,dotzot:Dotzot,dpd-at:DPD Austria,dpd-be:DPD BE,dpd-china:DPD China,dpd-croatia:DPD Croatia,dpd-cz:DPD Czech Republic,dpd-de:DPD Germany,dpd-et:DPD Estonia,dpd-fr:DPD France,dpd-hu:DPD Hungary,dpd-ie:DPD Ireland,dpd-local:DPD Local,dpd-lt:DPD Lithuania,dpd-lv:DPD Latvia,dpd-nl:DPD Netherlands,dpd-pl:DPD Poland,dpd-pt:DPD Portugal,dpd-romania:DPD Romania,dpd-sa:DPD South Africa,dpd-si:DPD Slovenia,dpd-sk:DPD Slovakia,dpd-switzerland:DPD Switzerland,dpd-uk:DPD UK,dpex:DPEX,dragonfly:Dragonfly,dsv:DSV,dtdc:DTDC,dtdc-plus:DTDC Plus,dx-delivery:DX delivery,early-bird:Early Bird,easy-mail:Easy Mail,ec-firstclass:EC Firstclass,echo:Echo,ecms-express:ECMS Express,ecom-express:Ecom Express,ecoscooting:EcoScooting,ed-post:ED Post,efsPost:EFSPost,eg-express:EG Express,ekart:Ekart,el-salvador-post:El Salvador Post,elog-luxembourg:Elog Luxembourg,elta-courier:ELTA Courier,elta-hellenic:ELTA Hellenic,emile:Emile,emirates-post:Emirates Post,emons:Emons,ems:EMS,envia:Envia,envialia:ENVIALIA,epacket:ePacket,eparcel-korea:eParcel Korea,epost-global:ePost Global,equick:Equick,espost:Espost,estafeta:Estafeta,estes:Estes,estonia-post:Estonia Post,etg:ETG,ethiopia-post:Ethiopia Post,etower:eTower,euasia-express:Euasia Express,eudore:Eudore,euexpress:Auexpress,eurodis:Eurodis,evri:Evri,evriinternational:Evri International,ewe global express:EWE Global Express,exelot:Exelot,expeditors:Expeditors,express-freight:Express Freight,expresscourierintl:Express Courier,expressone:Express one,famiport:Famiport,fan-courier:Fan Courier,faroe-islands:Faroe Islands Post,fast-despatch-logistics:Fast Despatch Logistics,fastgo:Fastgo,fastway-ireland:Fastway Ireland,fastway-za:FastWay South Africa,fbb:FAST BEE,fedex-fims:FedEx FIMS,fedex-freight:FedEx Freight,fedex-ground:FedEx Ground,fedex-mexico:FedEx Mexico,fedex-pl:FedEx Poland,fedex-sameday:FedEx Sameday,fedex-uk:FedEx UK,fetchr:Fetchr,finland-post:Finland Post,first-flight:First Flight,firstmile:FirstMile,fjex:FJEX,flash-express:Flash Express,flash-express-my:Flash Express MY,flash-express-ph:Flash Express PH,fleetoptics:FleetOptics,fliway:Fliway,flysman:FLYSMAN,flyt-express:Flyt Express,flyway-express:Flyway Express,followmont-transport:Followmont Transport,forward-air:Forward Air,foxpost:FoxPost,foz-post:Foz Post,franch-express:Franch Express,freightquote:Freightquote,frontier:Frontier,ft-exprss:FT Exprss,ftd-Express:FTD Express,fujie-express:FUJIE Express,gati:Gati,gdex:GDEX,geis-cz:Geis CZ,geis-poland:Geis Poland,gel-express:GEL Express,geodis:GEODIS,georgia-post:Georgia Post,geswl:GESWL Express,ghana-post:Ghana Post,ghn:Giao Hàng Nhanh,gibraltar-post:Gibraltar Post,gig-logistics:Gig Logistics,global-order-tracking:Global Order Tracking,globalpost:GlobalPost,gls:GLS Europe,gls-au:GLS Austria,gls-canada:GLS Canada,gls-croatia:GLS Croatia,gls-czech:GLS Czech,gls-denmark:GLS Denmark,gls-france:GLS France,gls-hungary:GLS Hungary,gls-ireland:GLS Ireland,gls-italy:GLS Italy,gls-netherlands:GLS Netherlands,gls-paket:GLS Paket,gls-romania:GLS Romania,gls-serbia:GLS Serbia,gls-slovakia:GLS Slovakia,gls-slovenia:GLS Slovenia,gls-spain:GLS Spain,gls-us:GLS US,go-express:Go Express,gofo-express:GOFO Express,gogo-xpress:GOGO Xpress,gojavas:GoJavas,grand-slam-express:Grand Slam Express,greyhound:Greyhound,grupo-ampm:Grupo ampm,gso:GSO,gta-gsm:GTA GSM,hailify:Hailify,happy-post:Happy Post,hcrd:HCRD,hct:HCT Logistics,hd-express:HD Express,hellmann-worldwide-logistics:Hellmann Worldwide Logistics,hepsi-jet:HepsiJet,hermes:Hermes,hermes-de:Hermes Germany,hfd:HFD,hhy-express:HHY Express,high-energy-transport:High Energy Transport,hjwl:HJWL,hkd-express:HKD Express,holland-regional:Holland Regional,hong-kong-post:Hong Kong Post,hotwms:HOTWMS,hound:Hound,hua-han-logistics:Hua Han Logistics,hua-xi:Hua Xi,hua-yu:Hua Yu,hui-feng-logistics:Hui Feng Logistics,hunter-express:Hunter Express,hyper-sku:HyperSKU,i-parcel:I-parcel,iceland-post:Iceland Post,ics-courier:ICS Courier,icumulus-global-express:iCumulus Global Express,imex-global-solutions:IMEX Global Solutions,imile:iMile,iml-logistics:IML Logistics,india-post:India Post,inpost:InPost Italy,inpost-paczkomaty:InPost Paczkomaty,inpost-spain:InPost Spain,intelcom:Intelcom,intercargo:Intercargo,internet-express:Internet Express Couriers,interparcel-au:Interparcel Au,interparcel-uk:Interparcel Uk,intertown-transport:Intertown Transport,intexpress:Intexpress,iq-fulfillment:IQfulfillment,israel-post:Israel Post,itdida:Itdida,itella:Itella,ivory-coast-ems:Ivory Coast EMS,j-net Express:J-NET Express,j-t:J&T ID,jadlog:Jadlog,jamaica-post:Jamaica Post,janco-express:Janco Express,janio:Janio,jcex:JCEX,jersey-post:Jersey Post,jet-express:JET Express,jet-international:JET International,jiachen-international:JIACHEN INTERNATIONAL,jiayou:Jiayou,jingdong-logistics:JINGDONG Logistics,jitsu:Jitsu,jne:JNE Express,joeyco:JoeyCo,jp-post:JP Post,jps:JPS,js-express:JS EXPRESS,jt-cargo:J&T Cargo,jt-cargo-my:J&T Cargo MY,jt-express-cn:J&T CN,jt-express-ph:J&T PH,jt-express-sg:J&T SG,jt-express-th:J&T TH,jt-express-vn:J&T VN,jt-sa:J&T SA,jtexpress-my:J&T MY,kaigenlogistics:Kaigenlogistics,kazakhstan-post:Kazakhstan Post,kerry-ecommerce:Kerry eCommerce,kerry-express-th:Kerry Express TH,kerry-express-vn:Kerry Express VN,kerry-logistics:Kerry Logistics,kerry-tj:Kerry TJ Logistics,kgm-hub:KGM Hub,kindersley-transport:Kindersley Transport,king-delivery:King Delivery,king-solutions:King Solutions,komon-express:KOMON EXPRESS,korea-post:Korea Post,kuehne:Kuehne Nagel,kwai-bon:Kwai Bon,la-huo-express:La Huo Express,la-poste:La Poste,lafasta:Lafasta,landmark-global:Landmark Global,laos-post:Laos Post,laser-logistics:Laser Logistics,lasership:LaserShip,latam-you:Latam You,latvijas-pasts:Latvia Post,lbc-express:LBC Express,ld-express:LD Express,leopard-courier:Leopard Courier,lexship:Lexship,lf-express:LF Express,liccardi-express:LICCARDI EXPRESS,liechtenstein-post:Liechtenstein Post,lietuvos-pasta:Lithuania Post,ljs:LJS,logen:LOGEN,logistics-tracking:Logistics Tracking,longcps:LONGCPS,loomis-express:Loomis Express,lotte-logistics:Lotte Global Logistics,lpexpress:LP Express,lso:Lone Star Overnight,ltian-exp:Ltian Exp,luxembourg-post:Luxembourg Post,lvse-international:LvSe International,macedonia-post:Macedonia Post,madhur-couriers:Madhur Couriers,magyar-posta:Magyar Posta,mahavir:Mahavir,mailamericas:MailAmericas,mailplus:MailPlus,mainfreight:Main Freight,malaysia-post:Poslaju National,maldives-post:Maldives Post,malta-post:Malta Post,manitoulin-transport:Manitoulin Transport,maple-logistics-express:Maple Logistics Express,mark-express:Mark Express,matdespatch:Matdespatch,matkahuolto:Matkahuolto,mauritius-post:Mauritius Post,mbe:Mail Boxed Etc,meest:Meest,metropolitan-warehouse:Metropolitan Warehouse,mng-kargo:MNG Kargo,monaco-ems:Monaco EMS,mondial-relay:Mondial Relay,moran-transportation:Moran Transportation,morning-express:Morning Express,mrw:MRW,muller-phipps:Muller Phipps,mxpress:M Xpress,my-austrianpost:GmbH,mylerz:mylerz,nacex:Nacex,namibia-post:Namibia Post,nampost:Nampost,naqel:Naqel,nationex:Nationex,nationwide-express:Nationwide Express,new-zealand-post:New Zealand Post,neway:Neway,newgistics:Newgistics,nexive:Nexive,nigeria-post:Nigeria Post,nightline:Nightline,ninja-express:Ninja Express,ninja-van:Ninja Van Singapore,ninja-van-international:Ninja Van International,ninja-van-vietnam:Ninja Van Vietnam,ninjavan-my:Ninja Van Malaysia,ninjavan-ph:Ninja Van Philippines,Nippon Express:Nippon Express,norsk-global:Norsk Global,northline:Northline,nova-poshta-global:Nova Post Global,nova-post-local:Nova Post Local,nz-couriers:NZ Couriers,ocs-express:OCS Express,ocs-worldwide:OCS Worldwide,old-dominion:Old Dominion,omni-parcel:Omni Parcel,omniva:Omniva Lithuania,omniva-latvia:Omniva Latvia,onedelivery:Onedelivery,ontrac:OnTrac,orange-connex:Orange Connex,orangeds:OrangeDS,orlen-paczka:Orlen paczka,osmworldwide:OSM Worldwide,overseas-logistics:Overseas Logistics,overseas-territory-fr-ems:Overseas Territory FR EMS,overseas-territory-us-post:Overseas Territory US Post,oxperta-express:Oxperta Express,p2p-trackpak:P2P TrakPak,packeta:Packeta,packlink:Packlink,packs:PACKS,pakistan-post:Pakistan Post,palletways:Palletways,pandu-logistics:Pandu Logistics,papua-new-guinea-post:Papua New Guinea Post,paquet:Paquet Express,parcel-broker:Parcel Broker,parcel-freight-logistics:Parcel Freight Logistics,parcel2go:parcel2go,parcelforce:ParcelForce,parcelmate:Parcelmate,parcelport:Parcelport,pargo:Pargo,pasamar:Pasamar,pass-the-parcel:Pass The Parcel,passport:Passport,pbt-express-freight-network:PBT Express Freight Network,pca:PCA,peninsula-truck-lines:Peninsula Truck Lines,pfcexpress:PFC Express,pgeon:Pgeon,phlpost:PHL Post,pickupp:Pickupp,pidge:Pidge,piggyship:PiggyShip,pilot:Pilot,pitney-bowes:Pitney Bowes,pittohio:Pitt Ohio,pmm:PMM,poland-post:Poczta Polska,polar-express:Polar Express,pony-express:Pony Express,portugal-post-ctt:Portugal Post - CTT,pos-indonesia:Pos Indonesia,poslaju:PosLaju,posstore:Posstore,post-at:post.at,post-haste:Post Haste,post-nord-denmark:PostNord Denmark,posta-crne-gore:Montenegro Post,posta-romana:Romania Post,postal-state-international:Postal State International,postaplus:PostaPlus,poste-italiane:Poste Italiane,posten-norge:Posten Norge,postex:PostEx,postnet:PostNet,postnl-3s:PostNL 3S,postnl-international:PostNL International,postnord-finland:PostNord Finland,postnord-norge:PostNord Norge,postnord-sverige-ab:PostNord Sverige AB,postnord-sweden:PostNord Sweden,postone:Post One,ppl-cz:PPL CZ,pts:PTS,ptt-posta:PTT Posta,pullman-cargo:Pullman Cargo,purolator:Purolator,pushpak-courier:Pushpak Courier,qexpress:QEXPRESS,qhxyyg:Qhxyyg,qi-eleven:7-ELEVEN,quantium:Quantium,quiken:Quiken,quiqup:Quiqup,qxpress:Qxpress,raf:RAF Philippines,ram:RAM,redpack:Redpack,redpack-mexico:Redpack Mexico,redur-es:Redur Spain,redx:Redx,relais-colis:Relais Colis,rhenus-logistics:Rhenus Logistics,rides:Wahana,rivigo:RIVIGO,rl-carriers:RL Carriers,roadbull-logistics:Roadbull Logistics,roadlogistics:Road Logistics,roadrunner-freight:Roadrunner Freight,royal-mail:Royal Mail,royal-shipments:Royal Shipments,rpx:RPX Indonesia,rpx-online:RPX Online,rr-donnelley:RR Donnelley,rtt-logistics:RTT Logistics,run bai internation:Run Bai Internation,russian-post:Russian Post,rzy-express:RZY Express,safexpress:Safexpress,sagawa:Sagawa,saia:Saia,saia-ltl-freight:Saia LTL Freight,sailpost:Sailpost,saint-lucia-post:Saint Lucia Post,sameday:Sameday,sameday-hungary:Sameday Hungary,samoa-post:Samoa Post,san-marino-post:San Marino Post,sap-express:SAP Express,saudi-post:Saudi Post,scg-express:SCG Express,score-jp:Score Jp,sda:SDA,sdh:SDH,sdk-express:SDK Express,sdt:SDT,seino:Seino,seko:Seko,sendex:Sendex,sendle:Sendle,sequoia-logistica:Sequoia Logistica,serbia-post:Serbia Post,serpost:Serpost,servientrega:Servientrega,servientrega-ecuador:Servientrega Ecuador,sf-express:SF Express,sf-international:SF International,sfc-service:SFC Service,sfyd-express:SFYD Express,shadowfax:Shadowfax,shipa:Shipa,shipgce-express:Shipgce Express,shippit:Shippit,shree-anjani-courier:Shree Anjani Courier,shree-mahabali-express:Shree Mahabali Express,shree-maruti-courier:Shree Maruti Courier,shree-nandan-courier:Shree Nandan Courier,shree-tirupati-courier:Shree Tirupati Courier,singapore-post:Singapore Post,singapore-speedpost:Singapore Speedpost,skr-international:SKR InterNational,skroutz-last-mile:Skroutz Last Mile,skybox:Skybox,skyking:Skyking,skynet:Skynet,skynet-south-africa:Skynet South Africa,skynet-worldwide:SkyNet Worldwide,skynet-worldwide-express:SkyNet Worldwide Express,slicity:SLICITY,slovakia-post:Slovakia Post,slovenia-post:Slovenia Post,smart-delivery:Smart Delivery,smartr-logistics:Smartr Logistics,smsa-express:SMSA Express,solid-logistcs:Solid Logistcs,south-african-post-office:South African Post Office,southeastern:Southeastern Freight Lines,spee-dee:Spee-Dee,speedaf-express:Speedaf Express,speedex-courier:Speedex Courier,speedex-greece:Speedex Greece,speedship:SpeedShip,speedx:SpeedX,speedy:Speedy,spoton:Spoton,spring-gds:Spring GDS,spx-id:SPX ID,spx-my:SPX MY,spx-sg:SPX SG,spx-th:SPX TH,spx-vn:SPX VN,sre-korea:SRE Korea,sri-lanka-post:Sri Lanka Post,st-courier:ST courier,starken:Starken,starlinks:Starlinks,startrack:StarTrack,stead-fast:Stead Fast,sto-express:STO Express,straightship:Straightship,sunson:Sunson,sunyou:Sunyou,superb-express:Superb Express,suxess-logistics:Suxess Logistics,swe:SWE,swiship-au:FBA Swiship AU,swiship-ca:FBA Swiship CA,swiship-de:FBA Swiship DE,swiship-es:FBA Swiship ES,swiship-fr:FBA Swiship FR,swiship-it:FBA Swiship IT,swiship-jp:FBA Swiship JP,swiship-uk:FBA Swiship UK,swiship-usa:FBA Swiship USA,swiss-post:Swiss Post,swyftlogistics:Swyft Logistics,sx-express:SX Express,szendex:Szendex,t-cat:T Cat,taiwan pelican express:Taiwan Pelican Express,taiwan-post:Chunghwa Post,takesend:TakeSend,tanzania-post:Tanzania Post,taoplus:Taoplus,tas-courier:TAS COURIER,taxydema:Taxydema,taxydromiki:Geniki Taxydromiki,tci-express:TCI Express,tcs-express:TCS express,team-global-express:Team Global Express,team-global-express-nz:Team Global Express NZ,tele-post:Greenland Post,teleport:Teleport,tfmxpress:TFMXpress,tforce-freight:TForce Freight,tforce-logistic:TForce Logistics,thailand-post:Thailand Post,the-courier-guy:The Courier Guy,the-lorry:The Lorry,the-professional-couriers:The Professional Couriers,tiki:TIKI,timely-titan:Timely Titan,timisc:TIMISC,tip-sa:Tipsa,tk-kit:Tk Kit,tma:TMA,tnt:TNT,tnt-australia:TNT Australia,tnt-click:TNT Click,tnt-france:TNT France,tnt-italy:TNT Italy,tnt-reference:TNT Reference,tnt-sweden:TNT Sweden,tnt-uk:TNT UK,togo-post:Togo Post,toll:TOLL,toll-ipec:Toll IPEC,topyou:TopYou,total-transportation:Total Transportation,trackon:Trackon,tracx-logis:TracX Logis,transportes-rapex:Transportes Rapex,trax-courier:Trax Courier,tres-guerras:Tres Guerras,tstexp:Tstexp,ttkeurope:Ttkeurope,tuffnells:Tuffnells,turkey-post:Turkey Post,tuvalu-post:Tuvalu Post,u-speed-express:U-Speed Express,ubi-smart-parcel:UBI Smart Parcel,ubx-express:UBX Express,udaan-express:Udaan Express,uds:UDS,uganda-post:Uganda Post,ukmail:UK Mail,ukraine-ems:Ukraine EMS,ukrposhta:Ukrposhta,un-line:Un-line,unicon-express-usa:UNICON EXPRESS USA,uniuni:UniUni,ups:UPS,ups-france:UPS France,ups-germany:UPS Germany,ups-global:UPS Global,ups-i-parcel:UPS i-parcel,ups-italy:UPS Italy,ups-se:UPS SE,ups-uk:UPS UK,upu:UPU,uruguay-post:Uruguay Post,urvaam:Urvaam,usky-express:Usky express,usps:USPS,uzbekistan-post:Uzbekistan Post,v-xpress:V-Xpress,valueway:VALUEWAY,vanuatu-post:Vanuatu Post,veho:Veho,venipak:Venipak,vietnam-post:Vietnam Post,viettel-post:Viettel Post,walkers:Walkers,wanbexpress:wanbexpress,wanmeng:Wanmeng,ward-transport:Ward Transport,we-world-express:We World Express,wel:WEL,whats-ship:Whats Ship,whistl:Whistl,wiseloads:Wiseloads,wndirect:wnDirect,xde-logistics:XDE Logistics,xdexpress:XDEXPRESS,xdp-uk:XDP Express,xend-express:Xend Express,xin-shu-logistics:Xin Shu Logistics,xlobo:Xlobo,xpo-logistics:XPO Logistics,xpost:XPOST,xpress-freight:Xpress Freight,xpressbees:Xpressbees,xun-tian-international:Xun Tian International,xyex:XYEX,xys-logistics:XYS Logistics,yadex:Yadex,yakit:Yakit,yamato:Yamato,yanwen:Yanwen,yanwen-express-us:Yanwen Express US,ydh:YDH,ydm:YDM,yfhex-logistics:YFHEX LOGISTICS,yht:YHT,yi-long-exp:Yi Long Exp,yide-international:Yide International,yjs-china:Yjs-China,yodel:Yodel,yodel-direct:Yodel Direct,youhai-international-express:Youhai International Express,yousheng-international-express:Yousheng International Express,yrc:YRC,ysd-post:YSD Post,yto-express:YTO Express,yue-xi-logistics:Yue Xi Logistics,yuema-express:Yuema Express,yunda-express:Yunda Express,yunexpress:Yun Express,yurtici-kargo:Yurtici Kargo,ywbz:YWBZ,zajel:ZAJEL,zajil-express:Zajil Express,zambia post:Zambia Post,zeleris:Zeleris,zhi-teng-logistics:Zhi Teng Logistics,zhongrong-tailong:Zhongrong Tailong,zinc:Zinc,zip-ph:Zip,zmc-express:ZMC EXPRESS,zto-express:ZTO Express"; // This needs to be the full string from your original code.

        $all_carriers_parsed = [];
        $pairs = explode(',', $full_list_raw); // Corrected: $full_list_raw
        foreach ($pairs as $pair) {
            if (strpos($pair, ':') !== false) {
                list($slug, $name) = explode(':', $pair, 2);
                $trimmed_name = trim($name);
                if (!in_array($trimmed_name, $prioritized) && !array_key_exists($trimmed_name, $prioritized)) {
                    $all_carriers_parsed[$trimmed_name] = $trimmed_name;
                }
            }
        }
        asort($all_carriers_parsed);

        $final_list = $prioritized;
        foreach($all_carriers_parsed as $name_key => $name_val){
            if(!isset($final_list[$name_key])){
                $final_list[$name_key] = $name_val;
            }
        }
        return $final_list;
    }

    private static function render_frontend_tracking_form($order) {
        $tracking_items = get_post_meta( $order->get_id(), '_wc_shipment_tracking_items', true );
        $tracking_item_data = is_array($tracking_items) && !empty($tracking_items) ? end($tracking_items) : null;
        $current_provider = $tracking_item_data && isset($tracking_item_data['tracking_provider']) ? $tracking_item_data['tracking_provider'] : '';
        $current_tracking_number = $tracking_item_data && isset($tracking_item_data['tracking_number']) ? $tracking_item_data['tracking_number'] : '';

        $carriers = self::get_shipping_carriers_list();
        ?>
        <h3><?php _e('Shipment Tracking', 'vss'); ?></h3>
        <p><?php _e('Enter the tracking number to mark this order as "Shipped". This will sync with TrackShip.', 'vss'); ?></p>
        <form method="post" action="<?php echo esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order->get_id()], home_url('/vendor-portal/'))); ?>#tab-tracking">
            <?php wp_nonce_field('vss_save_tracking_frontend', '_vss_tracking_nonce_fe'); ?>
            <input type="hidden" name="vss_fe_action" value="save_tracking">
            <input type="hidden" name="order_id" value="<?php echo $order->get_id(); ?>">

            <p><label for="vss_tracking_number_fe"><strong><?php _e('Tracking Number:', 'vss'); ?></strong></label><br>
            <input type="text" id="vss_tracking_number_fe" name="vss_tracking_number" style="width:100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;" value="<?php echo esc_attr($current_tracking_number); ?>"></p>

            <p><label for="vss_shipping_provider_fe"><strong><?php _e('Shipping Carrier:', 'vss'); ?></strong></label><br>
            <input type="text" id="vss_shipping_provider_fe" name="vss_shipping_provider" list="shipping_providers_fe_datalist" style="width:100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;" value="<?php echo esc_attr($current_provider); ?>" placeholder="<?php _e('Type or select a carrier...', 'vss'); ?>">
            <datalist id="shipping_providers_fe_datalist">
                <?php foreach ($carriers as $provider_value => $provider_name): // Changed to use key=>value if keys are slugs ?>
                    <option value="<?php echo esc_attr($provider_name); ?>">
                <?php endforeach; ?>
            </datalist>
            </p>
            <p class="vss-form-actions"><button type="submit" class="button"><?php _e('Save Tracking & Mark Shipped', 'vss'); ?></button></p>
        </form>
        <?php
    }

    private static function render_frontend_private_notes($order) { $notes = get_post_meta($order->get_id(), '_vss_private_notes', true); $notes = is_array($notes) ? $notes : []; ?><h3><?php _e('Private Notes', 'vss'); ?></h3><div class="vss-private-notes-fe"><div class="vss-notes-list-fe"><?php if (empty($notes)): ?><p><?php _e('No private notes yet.', 'vss'); ?></p><?php else: ?><?php foreach (array_reverse($notes) as $note_item): if (is_array($note_item) && isset($note_item['user_id']) && isset($note_item['timestamp']) && isset($note_item['note'])): $user = get_userdata($note_item['user_id']); $author_name = $user ? $user->display_name : __('System', 'vss'); $author_class = ''; if($user){ $author_class = user_can($user, 'manage_woocommerce') ? 'is-admin' : (user_can($user,'vendor-mm') ? 'is-vendor' : ''); } ?><div class="vss-note-fe"><p style="margin:0;"><strong class="vss-note-author-fe <?php echo $author_class; ?>"><?php echo esc_html($author_name); ?></strong> <span class="vss-note-date-fe"><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $note_item['timestamp']); ?></span></p><div class="vss-note-content-fe"><?php echo wpautop(esc_html($note_item['note'])); ?></div></div><?php endif; endforeach; ?><?php endif; ?></div><form method="post" action="<?php echo esc_url(add_query_arg(['vss_action' => 'view_order', 'order_id' => $order->get_id()], home_url('/vendor-portal/'))); ?>#tab-notes"><?php wp_nonce_field('vss_add_note_frontend', '_vss_note_nonce_fe'); ?><input type="hidden" name="vss_fe_action" value="add_note"><input type="hidden" name="order_id" value="<?php echo $order->get_id(); ?>"><p><label for="vss_new_note_fe"><strong><?php _e('Add New Note:', 'vss'); ?></strong></label><br><textarea id="vss_new_note_fe" name="vss_new_private_note" rows="4" style="width:100%; padding: 8px; border: 1px solid #ddd; border-radius: 3px;"></textarea></p><p class="vss-form-actions"><button type="submit" class="button"><?php _e('Add Note', 'vss'); ?></button></p></form></div><?php }

    private static function render_frontend_admin_files($order) { $file_id = get_post_meta( $order->get_id(), '_vss_attached_zip_id', true ); ?><h3><?php _e('Files from Admin', 'vss'); ?></h3><?php if ( $file_id ) { $file_url = wp_get_attachment_url( $file_id ); $file_path = get_attached_file($file_id); $file_name = $file_path ? basename( $file_path ) : __('Attached File', 'vss'); echo '<div class="vss-download-file"><p><a href="' . esc_url( $file_url ) . '" class="button" download>' . __('Download Attached File', 'vss') . '</a> <br><em>(' . esc_html( $file_name ) . ')</em></p></div>'; } else { echo '<p>' . __('No files uploaded by admin for this order.', 'vss') . '</p>'; } }

    public static function handle_frontend_forms() {
        if (!is_page('vendor-portal') || !self::is_current_user_vendor()) return;
        $base_redirect_url = home_url('/vendor-portal/');
        $redirect_url_params = [];

        // Check if it's a form submission we need to handle
        if (!isset($_POST['vss_fe_action']) || !isset($_POST['order_id'])) {
            // If not our specific form, still prepare for potential GET notices on page load
             if (isset($_REQUEST['order_id'])) $redirect_url_params['order_id'] = intval($_REQUEST['order_id']);
             if (isset($_REQUEST['vss_action'])) $redirect_url_params['vss_action'] = sanitize_key($_REQUEST['vss_action']);
            return;
        }


        $action = sanitize_key($_POST['vss_fe_action']);
        $order_id_post = intval($_POST['order_id']);

        // Always set these for the redirect URL base
        $redirect_url_params['order_id'] = $order_id_post;
        $redirect_url_params['vss_action'] = 'view_order';


        $order = wc_get_order($order_id_post);
        if (!$order || get_post_meta($order_id_post, '_vss_vendor_user_id', true) != get_current_user_id()) {
            wp_die(__('Invalid order or permission denied.', 'vss'));
        }

        $final_redirect_url_base = add_query_arg($redirect_url_params, $base_redirect_url);

        if ($action === "send_mockup_for_approval" || $action === "send_production_file_for_approval") {
            $type = isset($_POST['approval_type']) ? sanitize_key($_POST['approval_type']) : '';
            if (!in_array($type, ['mockup', 'production_file'])) {
                 wp_redirect(add_query_arg('vss_error', 'invalid_approval_type', $final_redirect_url_base . "#tab-customer-shipping")); // Generic tab
                 exit;
            }
            $tab_hash = ($type === 'mockup') ? '#tab-mockup-approval' : '#tab-prodfile-approval';

            if (!wp_verify_nonce($_POST["_vss_{$type}_approval_nonce_fe"], "vss_send_{$type}_for_approval_frontend")) {
                wp_die(__('Nonce verification failed.', 'vss'));
            }

            $vendor_notes = isset($_POST['vss_vendor_notes']) ? sanitize_textarea_field($_POST['vss_vendor_notes']) : '';
            $uploaded_file_ids = [];

            if (!empty($_FILES['vss_approval_files']['name'][0])) {
                if (!function_exists('wp_handle_upload')) require_once(ABSPATH . 'wp-admin/includes/file.php');
                if (!function_exists('wp_generate_attachment_metadata')) require_once(ABSPATH . 'wp-admin/includes/image.php');
                if (!function_exists('wp_read_image_metadata')) require_once(ABSPATH . 'wp-admin/includes/media.php');

                $files_to_upload = $_FILES['vss_approval_files'];
                $allowed_mime_types = [ // Define allowed mime types
                    'jpg|jpeg|jpe' => 'image/jpeg',
                    'gif'          => 'image/gif',
                    'png'          => 'image/png',
                    'pdf'          => 'application/pdf',
                    'ai'           => 'application/postscript', // .ai can also be application/pdf
                    'eps'          => 'application/postscript',
                    'svg'          => 'image/svg+xml'
                ];

                foreach ($files_to_upload['name'] as $key => $value) {
                    if ($files_to_upload['name'][$key]) {
                        $current_file = array(
                            'name'     => $files_to_upload['name'][$key],
                            'type'     => $files_to_upload['type'][$key],
                            'tmp_name' => $files_to_upload['tmp_name'][$key],
                            'error'    => $files_to_upload['error'][$key],
                            'size'     => $files_to_upload['size'][$key]
                        );
                        // Validate file type before upload attempt
                        $file_type_check = wp_check_filetype_and_ext($current_file['tmp_name'], $current_file['name'], $allowed_mime_types);
                        if ( false === $file_type_check['ext'] || false === $file_type_check['type'] ) {
                            // File type not allowed
                             wp_redirect(add_query_arg('vss_error', 'invalid_file_type', $final_redirect_url_base . $tab_hash));
                             exit;
                        }


                        $upload_overrides = array('test_form' => false, 'mimes' => $allowed_mime_types);
                        $movefile = wp_handle_upload($current_file, $upload_overrides);

                        if ($movefile && !isset($movefile['error'])) {
                            $filename = $movefile['file'];
                            $attachment = array(
                                'post_mime_type' => $movefile['type'],
                                'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $filename ) ),
                                'post_content'   => '',
                                'post_status'    => 'inherit',
                                'guid'           => $movefile['url']
                            );
                            $attach_id = wp_insert_attachment($attachment, $filename, $order_id_post); // Associate with order post ID
                            $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
                            wp_update_attachment_metadata($attach_id, $attach_data);
                            $uploaded_file_ids[] = $attach_id;
                        } else {
                            $error_message = isset($movefile['error']) ? $movefile['error'] : 'unknown_upload_error';
                            wp_redirect(add_query_arg(['vss_error' => 'file_upload_failed', 'detail' => sanitize_key($error_message)], $final_redirect_url_base . $tab_hash));
                            exit;
                        }
                    }
                }
            }

            $existing_files = get_post_meta($order_id_post, "_vss_{$type}_files", true);
            $existing_files = is_array($existing_files) ? $existing_files : [];

            if (!empty($uploaded_file_ids)) {
                // New files uploaded, replace old ones
                // Optionally, delete old attachments if they are no longer needed
                // foreach($existing_files as $old_file_id) { wp_delete_attachment($old_file_id, true); }
                update_post_meta($order_id_post, "_vss_{$type}_files", $uploaded_file_ids);
                $files_for_email = $uploaded_file_ids;
            } else {
                // No new files uploaded. If existing files are there (e.g. for a re-submission), use them.
                $files_for_email = $existing_files;
            }

            if (empty($files_for_email)) {
                 wp_redirect(add_query_arg('vss_error', 'no_files_uploaded', $final_redirect_url_base . $tab_hash));
                 exit;
            }


            update_post_meta($order_id_post, "_vss_{$type}_status", 'pending_approval');
            update_post_meta($order_id_post, "_vss_{$type}_vendor_notes", $vendor_notes);
            update_post_meta($order_id_post, "_vss_{$type}_sent_at", time());
            // Clear any previous customer notes for this approval cycle
            delete_post_meta($order_id_post, "_vss_{$type}_customer_notes");
            delete_post_meta($order_id_post, "_vss_{$type}_responded_at");


            VSS_Emails::send_customer_approval_request_email($order_id_post, $type);

            wp_redirect(add_query_arg('vss_notice', "{$type}_sent", $final_redirect_url_base . $tab_hash));
            exit;
        }


        switch($action) {
            case 'save_costs':
                if (isset($_POST['_vss_costs_nonce_fe']) && wp_verify_nonce($_POST['_vss_costs_nonce_fe'], 'vss_save_costs_frontend') && isset($_POST['vss_costs'])) {
                    $costs_data = $_POST['vss_costs'];
                    $sanitized_costs = ['line_items' => [], 'shipping_cost' => 0, 'total_cost' => 0];
                    $total = 0;
                    if (!empty($costs_data['line_items'])) foreach ($costs_data['line_items'] as $item_id => $cost_val) {
                        $sanitized_cost = wc_format_decimal(wc_clean(str_replace(',', '.', $cost_val)));
                        $sanitized_costs['line_items'][intval($item_id)] = $sanitized_cost;
                        $total += floatval($sanitized_cost);
                    }
                    if (!empty($costs_data['shipping_cost'])) {
                        $sanitized_shipping = wc_format_decimal(wc_clean(str_replace(',', '.', $costs_data['shipping_cost'])));
                        $sanitized_costs['shipping_cost'] = $sanitized_shipping;
                        $total += floatval($sanitized_shipping);
                    }
                    $sanitized_costs['total_cost'] = $total;
                    update_post_meta($order_id_post, '_vss_order_costs', $sanitized_costs);
                    wp_redirect(add_query_arg('vss_notice', 'costs_saved', $final_redirect_url_base . '#tab-costs'));
                    exit;
                }
                break;
            case 'save_tracking':
                if (isset($_POST['_vss_tracking_nonce_fe']) && wp_verify_nonce($_POST['_vss_tracking_nonce_fe'], 'vss_save_tracking_frontend') && isset($_POST['vss_tracking_number'])) {
                    $tracking_number = sanitize_text_field( $_POST['vss_tracking_number'] );
                    $provider = sanitize_text_field( $_POST['vss_shipping_provider'] );
                    if ( !empty($tracking_number) && !empty($provider) ) {
                        // Get existing tracking items or initialize as empty array
                        $tracking_items = get_post_meta( $order_id_post, '_wc_shipment_tracking_items', true );
                        if ( ! is_array( $tracking_items ) ) {
                            $tracking_items = [];
                        }
                        // Add new tracking entry
                        $tracking_items[] = [
                            'tracking_provider'        => $provider,
                            'tracking_number'          => $tracking_number,
                            'date_shipped'             => time(), // Or a date chosen by vendor
                            'custom_tracking_provider' => false, // Assuming you map to known providers
                            'custom_tracking_link'     => ''
                        ];
                        update_post_meta( $order_id_post, '_wc_shipment_tracking_items', $tracking_items );
                        if ( !$order->has_status('shipped') && !$order->has_status('completed') ) { // only update if not already shipped/completed
                             $order->update_status( 'shipped', sprintf(__( 'Tracking %s (%s) added by vendor from portal.', 'vss' ), $tracking_number, $provider) );
                        } else {
                             $order->add_order_note( sprintf(__( 'Additional tracking %s (%s) added by vendor from portal.', 'vss' ), $tracking_number, $provider) );
                        }
                        wp_redirect(add_query_arg('vss_notice', 'tracking_saved', $final_redirect_url_base . '#tab-tracking'));
                        exit;
                    }
                }
                break;
            case 'add_note':
                if (isset($_POST['_vss_note_nonce_fe']) && wp_verify_nonce($_POST['_vss_note_nonce_fe'], 'vss_add_note_frontend') && !empty(trim($_POST['vss_new_private_note']))) {
                    $notes = get_post_meta($order_id_post, '_vss_private_notes', true);
                    $notes = is_array($notes) ? $notes : [];
                    $notes[] = [ 'note' => sanitize_textarea_field($_POST['vss_new_private_note']), 'user_id' => get_current_user_id(), 'timestamp' => time() ];
                    update_post_meta($order_id_post, '_vss_private_notes', $notes);
                    wp_redirect(add_query_arg('vss_notice', 'note_added', $final_redirect_url_base . '#tab-notes'));
                    exit;
                }
                break;
            case 'vendor_confirm_production':
                if (isset($_POST['_vss_vendor_confirm_nonce_fe']) && wp_verify_nonce($_POST['_vss_vendor_confirm_nonce_fe'], 'vss_vendor_confirm_production_frontend')) {
                    $estimated_ship_date = isset($_POST['vss_vendor_estimated_ship_date']) ? sanitize_text_field($_POST['vss_vendor_estimated_ship_date']) : '';
                    if (empty($estimated_ship_date)) {
                        wp_redirect(add_query_arg('vss_error', 'date_required', $final_redirect_url_base)); exit;
                    }
                    if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $estimated_ship_date)) {
                        wp_redirect(add_query_arg('vss_error', 'date_format', $final_redirect_url_base)); exit;
                    }

                    $previous_ship_date = get_post_meta($order_id_post, '_vss_estimated_ship_date', true);
                    update_post_meta($order_id_post, '_vss_estimated_ship_date', $estimated_ship_date);
                    update_post_meta($order_id_post, '_vss_vendor_production_confirmed_at', time());

                    $note_action = get_post_meta($order_id_post, '_vss_vendor_production_confirmed_at', true) && $previous_ship_date ? __('Vendor updated estimated ship date to %s from portal.', 'vss') : __('Production confirmed by vendor. Estimated ship date: %s (from portal).', 'vss');
                    $note = sprintf($note_action, date_i18n(wc_date_format(), strtotime($estimated_ship_date)));

                    // Check if admin has already confirmed. If not, this vendor confirmation might trigger customer email.
                    $admin_confirmed_at = get_post_meta($order_id_post, '_vss_admin_production_confirmed_at', true);
                    $customer_email_sent_flag = '_vss_customer_production_email_sent_at';
                    $email_already_sent_timestamp = get_post_meta($order_id_post, $customer_email_sent_flag, true);
                    $date_at_last_email = get_post_meta($order_id_post, '_vss_estimated_ship_date_at_last_email', true);

                    if (!$admin_confirmed_at && (!$email_already_sent_timestamp || $date_at_last_email !== $estimated_ship_date) ) {
                        VSS_Emails::send_customer_production_confirmation_email($order_id_post, $order->get_order_number(), $estimated_ship_date);
                        $note .= ' ' . __('Customer notified.', 'vss');
                    } else {
                         $note .= ' ' . __('Customer NOT re-notified (already informed or date unchanged by this action).', 'vss');
                    }
                    $order->add_order_note($note);
                    wp_redirect(add_query_arg('vss_notice', 'production_confirmed', $final_redirect_url_base));
                    exit;
                }
                break;
        }
    }

    public static function filter_orders_for_vendor_in_admin($query) {
        global $pagenow;
        if (is_admin() && $pagenow === 'edit.php' && self::is_current_user_vendor() && $query->is_main_query() && isset($query->query_vars['post_type']) && $query->query_vars['post_type'] === 'shop_order') {
            $query->set('meta_key','_vss_vendor_user_id');
            $query->set('meta_value',get_current_user_id());
        }
    }

    public static function ajax_manual_fetch_zakeke_zip() {
        check_ajax_referer('vss_manual_fetch_zip_nonce', '_ajax_nonce');
        if (!self::is_current_user_vendor()) wp_send_json_error(['message' => __('Permission denied.', 'vss')]);

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $item_id_wc = isset($_POST['item_id']) ? intval($_POST['item_id']) : 0;
        $primary_zakeke_design_id = isset($_POST['primary_zakeke_design_id']) ? sanitize_text_field($_POST['primary_zakeke_design_id']) : null;

        if (!$order_id || !$item_id_wc || !$primary_zakeke_design_id) wp_send_json_error(['message' => __('Missing required data.', 'vss')]);

        $order = wc_get_order($order_id);
        if (!$order || get_post_meta($order_id, '_vss_vendor_user_id', true) != get_current_user_id()) wp_send_json_error(['message' => __('Invalid order or permission denied.', 'vss')]);

        $item = $order->get_item($item_id_wc);
        if (!$item) wp_send_json_error(['message' => __('Order item not found.', 'vss')]);

        $zakeke_api_response_for_order = VSS_Zakeke_API::get_zakeke_order_details_by_wc_order_id($order_id);
        $found_zip_url = null; $found_secondary_id = null;

        if ($zakeke_api_response_for_order && isset($zakeke_api_response_for_order['items']) && is_array($zakeke_api_response_for_order['items'])) {
            foreach ($zakeke_api_response_for_order['items'] as $zakeke_api_item) {
                if (isset($zakeke_api_item['design']) && $zakeke_api_item['design'] === $primary_zakeke_design_id) {
                    if (!empty($zakeke_api_item['printingFilesZip'])) {
                        $found_zip_url = esc_url_raw($zakeke_api_item['printingFilesZip']);
                        $item->update_meta_data('_vss_zakeke_printing_files_zip_url', $found_zip_url);
                        if (preg_match('#/s-\d+/(\d+)/zip/#', $found_zip_url, $matches) && isset($matches[1])) {
                            $found_secondary_id = sanitize_text_field($matches[1]);
                            $item->update_meta_data('_vss_zakeke_secondary_design_id', $found_secondary_id);
                        }
                        $item->save();
                        update_post_meta($order_id, '_vss_zakeke_fetch_attempt_complete', true);
                        $order->add_order_note(sprintf(__('Vendor manually fetched Zakeke ZIP for item #%s.', 'vss'), $item_id_wc));
                        wp_send_json_success(['message' => __('Zakeke files info updated!', 'vss'), 'zip_url' => $found_zip_url, 'primary_design_id' => $primary_zakeke_design_id, 'secondary_design_id' => $found_secondary_id ]);
                        return;
                    }
                    break;
                }
            }
        }
        wp_send_json_error(['message' => __('Could not retrieve Zakeke ZIP file via API. It may not be ready yet or an API error occurred.', 'vss')]);
    }
}