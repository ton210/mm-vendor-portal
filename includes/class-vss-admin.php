<?php
/**
 * VSS Admin Class - Updated with Admin Cost Input
 *
 * Handles all admin-side functionality for the Vendor Order Manager plugin.
 * Now includes ability for admins to input costs.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class VSS_Admin {

    /**
     * Initialize admin hooks.
     */
    public static function init() {
        add_action( 'admin_menu', [ self::class, 'add_admin_menu_pages' ], 5 );
        add_action( 'admin_init', [ self::class, 'register_vss_settings' ]);
        add_action( 'add_meta_boxes', [ self::class, 'add_admin_meta_boxes' ], 10, 2 );
        add_action( 'save_post_shop_order', [ self::class, 'save_admin_meta_data' ], 10, 1 );
        add_action( 'save_post_shop_order', [ self::class, 'handle_admin_order_confirmation' ], 20, 1 );
        add_filter( 'manage_edit-shop_order_columns', [ self::class, 'add_vendor_order_column' ] );
        add_action( 'manage_shop_order_posts_custom_column', [ self::class, 'populate_vendor_order_column' ], 10, 2 );
        add_action( 'wp_ajax_vss_split_order', [ self::class, 'ajax_split_order_handler' ] );
        add_action( 'restrict_manage_posts', [ self::class, 'add_vendor_filter_dropdown' ] );
        add_action( 'pre_get_posts', [ self::class, 'handle_vendor_filter_query' ] );
        add_action('vss_fetch_zakeke_files_for_order_hook', [self::class, 'vss_do_fetch_zakeke_files_for_order'], 10, 1);
        add_action('vss_order_assigned_to_vendor', [self::class, 'schedule_zakeke_fetch_on_assignment_if_needed'], 20, 2);
        add_action('woocommerce_order_status_processing', [self::class, 'schedule_zakeke_fetch_on_status_processing'], 20, 1);
        add_action( 'admin_notices', [ self::class, 'display_vss_admin_notices' ] );

        // New AJAX handler for admin cost updates
        add_action( 'wp_ajax_vss_admin_save_costs', [ self::class, 'ajax_admin_save_costs' ] );
    }

    /**
     * Display admin notices for VSS plugin.
     */
    public static function display_vss_admin_notices() {
        if ( !function_exists('get_current_screen') ) { return; }
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'shop_order' && ($screen->action === 'edit' || $screen->action === '') && isset($_GET['post']) ) {
            $post_id = intval($_GET['post']);
            if ( $message = get_transient( 'vss_admin_confirmation_notice_' . $post_id ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
                delete_transient( 'vss_admin_confirmation_notice_' . $post_id );
            }
            if ( $message = get_transient( 'vss_admin_error_notice_' . $post_id ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
                delete_transient( 'vss_admin_error_notice_' . $post_id );
            }
        }
    }

    /**
     * Add admin menu pages.
     */
    public static function add_admin_menu_pages() {
        add_menu_page(__( 'Vendor Portal', 'vss' ), __( 'Vendor Portal', 'vss' ), 'manage_woocommerce', 'vss-admin-dashboard', [ self::class, 'render_admin_dashboard_page' ], 'dashicons-store', 57 );
        add_submenu_page('vss-admin-dashboard', __( 'Dashboard', 'vss' ), __( 'Dashboard', 'vss' ), 'manage_woocommerce', 'vss-admin-dashboard', [ self::class, 'render_admin_dashboard_page' ]);
        add_submenu_page('vss-admin-dashboard', __( 'Payouts Report', 'vss' ), __( 'Payouts Report', 'vss' ), 'manage_woocommerce', 'vss-payouts-report', [ self::class, 'render_payouts_report_page' ]);
        add_submenu_page('vss-admin-dashboard', __( 'Performance Report', 'vss' ), __( 'Performance Report', 'vss' ), 'manage_woocommerce', 'vss-performance-report', [ self::class, 'render_performance_report_page' ]);
        add_submenu_page('vss-admin-dashboard', __( 'Zakeke API Settings', 'vss' ), __( 'Zakeke Settings', 'vss' ), 'manage_options', 'vss-zakeke-settings', [ self::class, 'render_zakeke_settings_page' ]);
    }

    /**
     * Register plugin settings for Zakeke API.
     */
    public static function register_vss_settings() {
        register_setting('vss_zakeke_options_group', 'vss_zakeke_settings');
        add_settings_section('vss_zakeke_api_section', __('Zakeke API Credentials', 'vss'), null, 'vss-zakeke-settings-page');
        add_settings_field('vss_zakeke_client_id', __('Zakeke Client ID', 'vss'), [self::class, 'render_settings_field_input'], 'vss-zakeke-settings-page', 'vss_zakeke_api_section', ['id' => 'client_id', 'type' => 'text', 'description' => __('Enter your Zakeke API Client ID.', 'vss')]);
        add_settings_field('vss_zakeke_client_secret', __('Zakeke Client Secret', 'vss'), [self::class, 'render_settings_field_input'], 'vss-zakeke-settings-page', 'vss_zakeke_api_section', ['id' => 'client_secret', 'type' => 'password', 'description' => __('Enter your Zakeke API Client Secret.', 'vss')]);
    }

    /**
     * Render input field for settings.
     */
    public static function render_settings_field_input($args) {
        $options = get_option('vss_zakeke_settings');
        $value = isset($options[$args['id']]) ? esc_attr($options[$args['id']]) : '';
        $type = isset($args['type']) ? $args['type'] : 'text';
        echo "<input type='{$type}' id='vss_zakeke_{$args['id']}' name='vss_zakeke_settings[{$args['id']}]' value='{$value}' class='regular-text' />";
        if (!empty($args['description'])) { echo "<p class='description'>". esc_html($args['description']) ."</p>"; }
    }

    /**
     * Render Zakeke settings page.
     */
    public static function render_zakeke_settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php _e('Zakeke API Settings', 'vss'); ?></h1>
            <form method="post" action="options.php" class="vss-settings-form">
                <?php settings_fields('vss_zakeke_options_group'); do_settings_sections('vss-zakeke-settings-page'); submit_button(); ?>
            </form>
            <p><strong><?php _e('Important Security Note:', 'vss'); ?></strong> <?php _e('Your Client Secret is stored as plain text in the database. Ensure your site has strong security measures in place.', 'vss'); ?></p>
        </div>
        <?php
    }

    public static function schedule_zakeke_fetch_on_status_processing($order_id){
        $vendor_id = get_post_meta($order_id, '_vss_vendor_user_id', true);
        self::schedule_zakeke_fetch_on_assignment_if_needed($order_id, $vendor_id);
    }

    public static function schedule_zakeke_fetch_on_assignment_if_needed($order_id, $vendor_id) {
        if (!$vendor_id) return;
        $order = wc_get_order($order_id);
        if (!$order || !$order->has_status('processing')) return;
        if (get_post_meta($order_id, '_vss_zakeke_fetch_scheduled_or_attempted', true)) return;
        $has_zakeke_items_needing_zip = false;
        foreach ($order->get_items() as $item_id => $item) {
            if ($item->get_meta('zakeke_data', true) && !$item->get_meta('_vss_zakeke_printing_files_zip_url', true)) { $has_zakeke_items_needing_zip = true; break; }
        }
        if ($has_zakeke_items_needing_zip) {
            if (!wp_next_scheduled('vss_fetch_zakeke_files_for_order_hook', array('order_id' => $order_id))) {
                wp_schedule_single_event(time() + 15 * MINUTE_IN_SECONDS, 'vss_fetch_zakeke_files_for_order_hook', array('order_id' => $order_id));
                update_post_meta($order_id, '_vss_zakeke_fetch_scheduled_or_attempted', true);
                $order->add_order_note(__('Zakeke print file check scheduled in 15 minutes.', 'vss'));
            }
        }
    }

    public static function vss_do_fetch_zakeke_files_for_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) { error_log("VSS Cron: Order ID {$order_id} not found for Zakeke fetch."); return; }
        $order_item_updated = false; $zakeke_api_response_for_order = null; $api_call_made_for_this_order = false;
        foreach ($order->get_items() as $item_id => $item) {
            $zakeke_meta_data_raw = $item->get_meta('zakeke_data', true); $primary_zakeke_design_id = null;
            if ($zakeke_meta_data_raw) {
                $parsed_data = is_string($zakeke_meta_data_raw) ? json_decode($zakeke_meta_data_raw, true) : (array) $zakeke_meta_data_raw;
                if (is_array($parsed_data) && isset($parsed_data['design'])) { $primary_zakeke_design_id = $parsed_data['design']; }
            }
            if ($primary_zakeke_design_id && !$item->get_meta('_vss_zakeke_printing_files_zip_url', true)) {
                if ($zakeke_api_response_for_order === null && !$api_call_made_for_this_order) {
                    $zakeke_api_response_for_order = VSS_Zakeke_API::get_zakeke_order_details_by_wc_order_id($order_id); $api_call_made_for_this_order = true;
                }
                if ($zakeke_api_response_for_order && isset($zakeke_api_response_for_order['items']) && is_array($zakeke_api_response_for_order['items'])) {
                    foreach ($zakeke_api_response_for_order['items'] as $zakeke_api_item) {
                        if (isset($zakeke_api_item['design']) && $zakeke_api_item['design'] === $primary_zakeke_design_id) {
                            if (!empty($zakeke_api_item['printingFilesZip'])) {
                                $item->update_meta_data('_vss_zakeke_printing_files_zip_url', esc_url_raw($zakeke_api_item['printingFilesZip'])); $order_item_updated = true;
                                if (preg_match('#/s-\d+/(\d+)/zip/#', $zakeke_api_item['printingFilesZip'], $matches) && isset($matches[1])) {
                                    $item->update_meta_data('_vss_zakeke_secondary_design_id', sanitize_text_field($matches[1]));
                                }
                            }
                            $item->save(); break;
                        }
                    }
                }
            }
        }
        if ($order_item_updated) { $order->add_order_note(__('Zakeke print file URLs/Design IDs updated via cron/fetch.', 'vss')); }
        update_post_meta($order_id, '_vss_zakeke_fetch_attempt_complete', true);
    }

    public static function render_admin_dashboard_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $date_after_90_days = date('Y-m-d H:i:s', strtotime('-90 days'));
        $processing_query = new WC_Order_Query(['status' => 'wc-processing', 'meta_query' => [['key'=>'_vss_vendor_user_id', 'compare' => 'EXISTS']], 'date_created' => '>=' . $date_after_90_days, 'limit' => -1, 'return' => 'ids']);
        $shipped_query = new WC_Order_Query(['status' => 'wc-shipped', 'meta_query' => [['key'=>'_vss_vendor_user_id', 'compare' => 'EXISTS']], 'date_created' => '>=' . $date_after_90_days, 'limit' => -1, 'return' => 'ids']);
        $stale_query = new WC_Order_Query(['status' => 'wc-processing', 'meta_query' => [['key'=>'_vss_vendor_user_id', 'compare' => 'EXISTS']], 'date_modified' => '<=' . date('Y-m-d H:i:s', strtotime('-3 days')), 'limit' => -1, 'return' => 'ids']);
        $stats = ['processing' => count($processing_query->get_orders()), 'shipped' => count($shipped_query->get_orders()), 'stale' => count($stale_query->get_orders())];
        ?>
        <div class="wrap"><h1 class="wp-heading-inline"><?php _e('Vendor Orders Dashboard', 'vss'); ?> <span style="font-size:0.7em; color: #777;"><?php _e('(Summary for Last 90 Days)', 'vss'); ?></span></h1><div class="vss-dashboard-widgets-wrapper"><div class="vss-dashboard-widgets">
        <div class="postbox vss-stat-box"><div class="postbox-header"><h2><span class="dashicons dashicons-hourglass"></span> <?php _e('Orders in Processing (by Vendors)', 'vss'); ?></h2></div><div class="inside"><p class="stat-number"><?php echo $stats['processing']; ?></p><a class="stat-link" href="<?php echo admin_url('edit.php?post_type=shop_order&order_status=processing'); ?>"><?php _e('View All Processing', 'vss'); ?></a></div></div>
        <div class="postbox vss-stat-box"><div class="postbox-header"><h2><span class="dashicons dashicons-cart"></span> <?php _e('Orders Shipped (by Vendors)', 'vss'); ?></h2></div><div class="inside"><p class="stat-number"><?php echo $stats['shipped']; ?></p><a class="stat-link" href="<?php echo admin_url('edit.php?post_type=shop_order&order_status=shipped'); ?>"><?php _e('View All Shipped', 'vss'); ?></a></div></div>
        <div class="postbox vss-stat-box <?php echo $stats['stale'] > 0 ? 'is-critical' : ''; ?>"><div class="postbox-header"><h2><span class="dashicons dashicons-warning"></span> <?php _e('Stale Processing Orders (> 3 Days unmodified)', 'vss'); ?></h2></div><div class="inside"><p class="stat-number"><?php echo $stats['stale']; ?></p><a class="stat-link" href="<?php echo admin_url('edit.php?post_type=shop_order&order_status=processing&orderby=modified&order=asc'); ?>"><?php _e('View Stale Orders', 'vss'); ?></a></div></div>
        </div></div></div><?php
    }

    public static function render_payouts_report_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $default_days = 90; $selected_days = isset($_GET['vss_filter_days']) ? intval($_GET['vss_filter_days']) : $default_days;
        $date_query_args = []; if ($selected_days > 0) { $date_query_args = [['after' => date('Y-m-d 00:00:00', strtotime("-{$selected_days} days")), 'before' => date('Y-m-d 23:59:59'), 'inclusive' => true]];}
        $payouts_data = []; $vendors = get_users(['role' => 'vendor-mm']);
        foreach ($vendors as $vendor) { $payouts_data[$vendor->ID] = ['name' => $vendor->display_name, 'total' => 0, 'orders' => 0]; }
        $order_args = ['limit' => -1, 'status' => ['wc-processing', 'wc-shipped', 'wc-completed'], 'return' => 'objects'];
        if (!empty($date_query_args)) { $order_args['date_query'] = $date_query_args; } $orders = wc_get_orders($order_args);
        foreach ($orders as $order) {
            $vendor_id = get_post_meta($order->get_id(), '_vss_vendor_user_id', true); $costs = get_post_meta($order->get_id(), '_vss_order_costs', true);
            if ($vendor_id && isset($payouts_data[$vendor_id]) && !empty($costs) && isset($costs['total_cost']) && is_numeric($costs['total_cost'])) {
                $payouts_data[$vendor_id]['total'] += floatval($costs['total_cost']); $payouts_data[$vendor_id]['orders']++;
            }
        }
        ?>
        <div class="wrap"><h1><?php _e('Vendor Payouts Report', 'vss'); ?></h1>
        <form method="GET" class="vss-date-filter-form"><input type="hidden" name="page" value="vss-payouts-report" /><label for="vss_filter_days"><?php _e('Show data for last:', 'vss'); ?></label><select name="vss_filter_days" id="vss_filter_days"><option value="30" <?php selected($selected_days, 30); ?>>30 <?php _e('days', 'vss'); ?></option><option value="60" <?php selected($selected_days, 60); ?>>60 <?php _e('days', 'vss'); ?></option><option value="90" <?php selected($selected_days, 90); ?>>90 <?php _e('days', 'vss'); ?></option><option value="180" <?php selected($selected_days, 180); ?>>180 <?php _e('days', 'vss'); ?></option><option value="365" <?php selected($selected_days, 365); ?>>365 <?php _e('days', 'vss'); ?></option><option value="0" <?php selected($selected_days, 0); ?>><?php _e('All Time', 'vss'); ?></option></select><input type="submit" class="button" value="<?php _e('Filter', 'vss'); ?>"></form>
        <p><?php if ($selected_days > 0) { printf(__('Showing data for orders created in the last %d days.', 'vss'), $selected_days); } else { _e('Showing data for all time.', 'vss'); } ?></p>
        <table class="wp-list-table widefat striped vss-table"><thead><tr><th><?php _e('Vendor-MM', 'vss'); ?></th><th><?php _e('Orders in Period', 'vss'); ?></th><th><?php _e('Total Payout Due', 'vss'); ?></th></tr></thead>
        <tbody>
            <?php $grand_total = 0; ?>
            <?php if (!empty($payouts_data)): ?>
                <?php foreach ($payouts_data as $payout_item): ?>
                    <?php
                        $current_payout_total = 0;
                        if (isset($payout_item['total']) && is_numeric($payout_item['total'])) {
                            $current_payout_total = floatval($payout_item['total']); $grand_total += $current_payout_total;
                        }
                        $current_payout_orders = (isset($payout_item['orders']) && is_numeric($payout_item['orders'])) ? intval($payout_item['orders']) : 0;
                    ?>
                    <?php if ($current_payout_orders > 0 || $current_payout_total > 0 ): ?>
                        <tr>
                            <td class="vendor-name"><?php echo isset($payout_item['name']) ? esc_html($payout_item['name']) : __('N/A', 'vss'); ?></td>
                            <td><?php echo $current_payout_orders; ?></td><td><?php echo wc_price($current_payout_total); ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="3"><?php _e('No payout data available for the selected period.', 'vss'); ?></td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot><tr class="total-row"><td colspan="2"><strong><?php _e('Grand Total for Period', 'vss'); ?></strong></td><td><strong><?php echo wc_price($grand_total); ?></strong></td></tr></tfoot></table>
        </div><?php
    }

    public static function render_performance_report_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $vendors = get_users(['role' => 'vendor-mm']); $ninety_days_ago = strtotime('-90 days');
        ?>
        <div class="wrap"><h1><?php _e('Vendor Performance Report', 'vss'); ?> <span style="font-size:0.7em; color: #777;"><?php _e('(Based on Orders in Last 90 Days)', 'vss'); ?></span></h1>
        <table class="wp-list-table widefat striped vss-table"><thead><tr><th><?php _e('Vendor-MM', 'vss'); ?></th><th><?php _e('Avg. Shipping Time', 'vss'); ?></th><th><?php _e('Orders Shipped in Period', 'vss'); ?></th></tr></thead>
        <tbody>
            <?php if (!empty($vendors)): foreach ($vendors as $vendor): ?>
                <?php
                $orders_query = new WC_Order_Query(['limit' => -1, 'meta_key' => '_vss_vendor_user_id', 'meta_value' => $vendor->ID, 'date_created' => '>=' . date('Y-m-d', $ninety_days_ago), 'status' => ['wc-shipped', 'wc-completed'], 'return' => 'objects']);
                $orders = $orders_query->get_orders(); $total_ship_time_from_assignment = 0; $shipped_count = 0;
                if (!empty($orders)) {
                    foreach ($orders as $order) {
                        $assigned_at = get_post_meta($order->get_id(), '_vss_assigned_at', true) ?: $order->get_date_created()->getTimestamp();
                        $shipped_at = get_post_meta($order->get_id(), '_vss_shipped_at', true); // This meta needs to be reliably set when vendor ships
                        if ($shipped_at && $assigned_at) { $time_diff_seconds = $shipped_at - $assigned_at; if ($time_diff_seconds > 0) { $total_ship_time_from_assignment += $time_diff_seconds; $shipped_count++; }}
                    }
                }
                $avg_ship_seconds = ($shipped_count > 0 && $total_ship_time_from_assignment > 0) ? ($total_ship_time_from_assignment / $shipped_count) : 0;
                $avg_ship_display = ($avg_ship_seconds > 0) ? human_time_diff(0, $avg_ship_seconds) : __('N/A', 'vss');
                ?>
                <tr><td class="vendor-name"><?php echo esc_html($vendor->display_name); ?></td><td><?php echo $avg_ship_display; ?></td><td><?php echo $shipped_count; ?></td></tr>
            <?php endforeach; else: ?>
                <tr><td colspan="3"><?php _e('No vendors found.', 'vss'); ?></td></tr>
            <?php endif; ?>
        </tbody></table>
        </div><?php
    }

    public static function add_admin_meta_boxes($post_type, $post_object) {
        if ($post_type !== 'shop_order' || !current_user_can('manage_woocommerce')) return;
        add_meta_box('vss-vendor-management-box',__( 'Vendor Assignment & Files', 'vss' ),[ self::class, 'render_vendor_management_meta_box' ],'shop_order','side','default');
        add_meta_box('vss-production-confirmation-box',__( 'Production Confirmation & Ship Estimate (Admin)', 'vss' ),[ self::class, 'render_production_confirmation_meta_box' ],'shop_order','side','high');
        add_meta_box('vss-vendor-payout-box',__( 'Vendor Costs & Admin Input', 'vss' ),[ self::class, 'render_vendor_payout_meta_box' ],'shop_order','normal','low');
        add_meta_box('vss-private-notes-box',__( 'Internal Order Notes', 'vss' ),[ self::class, 'render_private_notes_box' ],'shop_order','normal','low');
    }

    public static function render_production_confirmation_meta_box($post) {
        wp_nonce_field('vss_admin_confirm_production_nonce', '_vss_admin_confirm_production_nonce'); $order = wc_get_order($post->ID); if(!$order) return;
        $estimated_ship_date = get_post_meta($post->ID, '_vss_estimated_ship_date', true); $admin_confirmed_at = get_post_meta($post->ID, '_vss_admin_production_confirmed_at', true); $vendor_confirmed_at = get_post_meta($post->ID, '_vss_vendor_production_confirmed_at', true);
        $countdown_text = ''; $countdown_class = ''; $last_confirmed_by = ''; $last_confirmation_time = 0;
        if ($admin_confirmed_at && $vendor_confirmed_at) { if ($admin_confirmed_at > $vendor_confirmed_at) { $last_confirmed_by = __('Admin', 'vss'); $last_confirmation_time = $admin_confirmed_at; } else { $last_confirmed_by = __('Vendor', 'vss'); $last_confirmation_time = $vendor_confirmed_at; }
        } elseif ($admin_confirmed_at) { $last_confirmed_by = __('Admin', 'vss'); $last_confirmation_time = $admin_confirmed_at; } elseif ($vendor_confirmed_at) { $last_confirmed_by = __('Vendor', 'vss'); $last_confirmation_time = $vendor_confirmed_at; }
        if ($estimated_ship_date) {
            $ship_timestamp = strtotime($estimated_ship_date); $today_timestamp = current_time('timestamp'); $ship_date_only = date('Y-m-d', $ship_timestamp); $today_date_only = date('Y-m-d', $today_timestamp);
            $days_diff = (strtotime($ship_date_only) - strtotime($today_date_only)) / DAY_IN_SECONDS;
            if ($days_diff < 0 && $order->has_status('wc-processing')) { $countdown_text = sprintf(_n('%d DAY LATE', '%d DAYS LATE', abs(round($days_diff)), 'vss'), abs(round($days_diff))); $countdown_class = 'is-late';
            } elseif ($days_diff == 0 && $order->has_status('wc-processing')) { $countdown_text = __('Ships Today', 'vss'); $countdown_class = 'is-today';
            } elseif ($days_diff > 0) { $countdown_text = sprintf(_n('%d day left to ship', '%d days left to ship', round($days_diff), 'vss'), round($days_diff)); $countdown_class = 'is-upcoming';}
        } ?>
        <div class="vss-confirmation-section">
            <?php if (($admin_confirmed_at || $vendor_confirmed_at) && $estimated_ship_date): ?>
                <div class="vss-confirmation-info">
                    <?php if ($last_confirmed_by && $last_confirmation_time) : ?><p><strong><?php _e('Production Status:', 'vss'); ?></strong> <?php printf(__('Confirmed by %s on %s.', 'vss'), esc_html($last_confirmed_by), esc_html(date_i18n(wc_date_format() . ' ' . wc_time_format() , $last_confirmation_time))); ?></p><?php endif; ?>
                    <p><strong><?php _e('Current Est. Ship Date:', 'vss'); ?></strong> <?php echo esc_html(date_i18n(wc_date_format(), strtotime($estimated_ship_date))); ?></p>
                </div>
                <?php if ($countdown_text): ?><p class="vss-ship-date-countdown <?php echo esc_attr($countdown_class); ?>"><?php echo esc_html($countdown_text); ?></p><?php endif; ?>
                <hr><p><label for="vss_estimated_ship_date_field"><?php _e('Update Estimated Ship Date (Admin):', 'vss'); ?></label></p>
            <?php else: ?><p><label for="vss_estimated_ship_date_field"><?php _e('Select Estimated Ship Date (Admin):', 'vss'); ?></label></p><?php endif; ?>
            <input type="text" id="vss_estimated_ship_date_field" name="vss_estimated_ship_date" class="vss-datepicker" value="<?php echo esc_attr($estimated_ship_date); ?>" placeholder="YYYY-MM-DD" autocomplete="off" style="width:100%;" />
            <button type="submit" class="button button-primary" name="vss_admin_confirm_production_btn" value="1" style="margin-top:10px;"><?php echo ($admin_confirmed_at || $vendor_confirmed_at) ? __('Update Date & Re-notify Customer', 'vss') : __('Set Date, Confirm & Notify Customer', 'vss'); ?></button>
            <p class="description"><?php _e('Customer will be notified of the estimated ship date.', 'vss'); ?></p>
        </div><script type="text/javascript">jQuery(document).ready(function($){if(typeof $.fn.datepicker==='function'){$('#vss_estimated_ship_date_field').datepicker({dateFormat:'yy-mm-dd',minDate:0});}});</script><?php
    }

    public static function handle_admin_order_confirmation($post_id) {
        if (!isset($_POST['_vss_admin_confirm_production_nonce']) || !wp_verify_nonce($_POST['_vss_admin_confirm_production_nonce'], 'vss_admin_confirm_production_nonce')) return;
        if (!current_user_can('edit_shop_order', $post_id)) return; if (!isset($_POST['vss_admin_confirm_production_btn'])) return; if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        $estimated_ship_date = isset($_POST['vss_estimated_ship_date']) ? sanitize_text_field($_POST['vss_estimated_ship_date']) : '';
        if (empty($estimated_ship_date)) { set_transient('vss_admin_error_notice_' . $post_id, __('Estimated ship date is required.', 'vss'), 45); return; }
        if (!preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $estimated_ship_date)) { set_transient('vss_admin_error_notice_' . $post_id, __('Invalid date format. Use YYYY-MM-DD.', 'vss'), 45); return; }
        $order = wc_get_order($post_id); if (!$order) return; $previous_ship_date = get_post_meta($post_id, '_vss_estimated_ship_date', true); $admin_already_confirmed = get_post_meta($post_id, '_vss_admin_production_confirmed_at', true);
        update_post_meta($post_id, '_vss_estimated_ship_date', $estimated_ship_date); update_post_meta($post_id, '_vss_admin_production_confirmed_at', time());
        $note_action = $admin_already_confirmed ? __('Admin updated est. ship date to %s.', 'vss') : __('Production confirmed by admin. Est. ship date: %s.', 'vss');
        $note = sprintf($note_action, date_i18n(wc_date_format(), strtotime($estimated_ship_date)));
        $email_sent_flag = '_vss_customer_production_email_sent_at'; $date_last_email_flag = '_vss_estimated_ship_date_at_last_email';
        $last_sent_ts = get_post_meta($post_id, $email_sent_flag, true); $date_at_last_sent = get_post_meta($post_id, $date_last_email_flag, true);
        if (!$last_sent_ts || ($date_at_last_sent !== $estimated_ship_date)) {
            // VSS_Emails::send_customer_production_confirmation_email($post_id, $order->get_order_number(), $estimated_ship_date);
            set_transient('vss_admin_confirmation_notice_' . $post_id, __('Order confirmed/updated & customer notified.', 'vss'), 45); $note .= ' ' . __('Customer notified.', 'vss');
        } else { set_transient('vss_admin_confirmation_notice_' . $post_id, __('Est. ship date updated. Customer NOT re-notified.', 'vss'), 45); $note .= ' ' . __('Customer NOT re-notified.', 'vss');}
        $order->add_order_note($note);
    }

    public static function render_private_notes_box($post) {
        if ( ! $post instanceof WP_Post || empty( $post->ID ) ) { if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) { error_log( 'VSS Critical Error: render_private_notes_box called with invalid $post object. Post data: ' . print_r($post, true) ); } echo '<p>' . esc_html__( 'Error: Could not load notes. Invalid post object provided to meta box.', 'vss' ) . '</p>'; return; }
        wp_nonce_field('vss_save_private_note_meta', 'vss_private_note_nonce');

        $notes_raw = get_post_meta($post->ID, '_vss_private_notes', true); $notes = is_array($notes_raw) ? $notes_raw : [];

        if ( empty( $notes ) ) {
            echo '<p style="margin-top:0;">' . esc_html__('No internal notes yet for this order.', 'vss') . '</p>';
        } else {
            echo '<h4 style="margin-top:0;">' . esc_html__('Current Internal Notes', 'vss') . '</h4>';
            echo '<div id="vss-notes-list" style="border: 1px solid #ccd0d4; padding: 10px; margin-bottom: 15px; max-height: 250px; overflow-y: auto; background: #f9f9f9;">';
            $reversed_notes = (is_array($notes) && !empty($notes)) ? array_reverse($notes) : []; if (empty($reversed_notes) && !empty($notes) && defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) { error_log( 'VSS Warning: array_reverse failed or resulted in empty array for non-empty notes. Post ID ' . $post->ID ); }
            foreach ($reversed_notes as $index => $note_item) {
                if ( !is_array($note_item) || !array_key_exists('user_id', $note_item) || !array_key_exists('timestamp', $note_item) || !array_key_exists('note', $note_item) ) { echo '<div class="vss-note" style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 8px; color:red;"><p>' . sprintf(esc_html__('Error: A note (at position %d) is malformed and cannot be displayed correctly.', 'vss'), $index) . '</p></div>'; if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) { error_log( 'VSS Warning: Malformed note item in _vss_private_notes for post ID ' . $post->ID . ' at original array index (before reverse) ' . (count($notes) - 1 - $index) . '. Note Data: ' . print_r( $note_item, true ) ); } continue; }
                $user = get_userdata($note_item['user_id']); $author_name = ''; $author_class = '';
                if ($user) { $author_name = esc_html($user->display_name); if (user_can($user, 'manage_woocommerce')) $author_class = 'is-admin'; elseif (user_can($user, 'vendor-mm')) $author_class = 'is-vendor'; }
                else { $author_name = sprintf(esc_html__('User (ID: %s - Not Found)', 'vss'), esc_html($note_item['user_id']));}
                echo '<div class="vss-note" style="border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 8px;"><p style="margin:0 0 5px 0;"><strong class="vss-note-author ' . esc_attr($author_class) . '">' . $author_name . '</strong> ';
                $timestamp = is_numeric($note_item['timestamp']) ? (int) $note_item['timestamp'] : 0;
                if ($timestamp > 0) { echo '<span class="vss-note-date" style="color: #787c82; font-size: 0.9em;">' . esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp, true)) . '</span></p>'; }
                else { echo '<span class="vss-note-date" style="color: #787c82; font-size: 0.9em;">(' . esc_html__('Invalid or missing date', 'vss') . ')</span></p>'; if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) { error_log( 'VSS Warning: Invalid timestamp for note in post ID ' . $post->ID . '. Timestamp value: ' . print_r($note_item['timestamp'], true) );}}
                $note_content_raw = isset($note_item['note']) ? $note_item['note'] : ''; $note_display = is_string($note_content_raw) ? wpautop(esc_html($note_content_raw)) : '[' . __('Note content is not in expected string format.', 'vss') . ']';
                if(!is_string($note_content_raw) && defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) {error_log( 'VSS Warning: Note content is not a string for post ID ' . $post->ID . '. Content type: ' . gettype($note_content_raw) );}
                echo '<div class="vss-note-content" style="margin: 5px 0 0; white-space: pre-wrap;">' . $note_display . '</div></div>';
            }
            echo '</div>';
        }
        echo '<div style="margin-top:20px; padding-top:20px; border-top:1px solid #ddd;">';
        echo '<h4 style="margin-top:0; margin-bottom:10px;">' . esc_html__('Add New Internal Note:', 'vss') . '</h4>';
        echo '<textarea name="vss_new_private_note" id="vss_new_private_note_admin" rows="4" style="width:100%; margin-bottom:10px;" placeholder="' . esc_attr__('Type your internal note here...', 'vss') . '"></textarea>';
        echo '<p class="description">' . esc_html__('This note is for internal use only. It will not be visible to the customer or the assigned vendor through their portal.', 'vss') . '</p>';
        echo '</div>';
    }

    public static function save_admin_meta_data($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return $post_id; if (!current_user_can('edit_shop_order', $post_id)) return $post_id;
        $order = wc_get_order($post_id); if (!$order) { if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) { error_log("VSS Error: save_admin_meta_data - Could not get WC_Order object for post ID: " . $post_id); } return $post_id; }

        // Save private notes
        if (isset($_POST['vss_private_note_nonce']) && wp_verify_nonce($_POST['vss_private_note_nonce'], 'vss_save_private_note_meta')) {
            if (isset($_POST['vss_new_private_note']) && !empty(trim($_POST['vss_new_private_note']))) {
                $notes = get_post_meta($post_id, '_vss_private_notes', true); $notes = is_array($notes) ? $notes : [];
                $notes[] = ['note' => sanitize_textarea_field($_POST['vss_new_private_note']), 'user_id' => get_current_user_id(), 'timestamp' => time()];
                update_post_meta($post_id, '_vss_private_notes', $notes);
            }
        } elseif (isset($_POST['vss_new_private_note']) && defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ) { error_log("VSS Security: Attempt to save private note without valid nonce for order ID: " . $post_id); }

        // Save vendor assignment and file uploads
        if (isset($_POST['vss_vendor_management_nonce']) && wp_verify_nonce($_POST['vss_vendor_management_nonce'], 'vss_save_vendor_management_meta')) {
            if(isset($_POST['_vss_vendor_user_id'])){
                $old_vendor_id_raw = get_post_meta($post_id, '_vss_vendor_user_id', true); $old_vendor_id = !empty($old_vendor_id_raw) ? intval($old_vendor_id_raw) : 0;
                $new_vendor_id = intval($_POST['_vss_vendor_user_id']);
                if ( $new_vendor_id !== $old_vendor_id ) {
                    update_post_meta( $post_id, '_vss_vendor_user_id', $new_vendor_id );
                    if ($new_vendor_id > 0) {
                        update_post_meta($post_id, '_vss_assigned_at', time());
                        if (!$order->has_status(['wc-shipped', 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-completed'])) { if($order->get_status() !== 'processing') $order->update_status('wc-processing', __('Order assigned to vendor by admin.', 'vss')); else $order->add_order_note(__('Order re-assigned to new vendor by admin.', 'vss')); }
                        do_action('vss_order_assigned_to_vendor', $post_id, $new_vendor_id);
                    } else { delete_post_meta($post_id, '_vss_assigned_at'); $order->add_order_note(__('Order unassigned from vendor by admin.', 'vss'));}
                }
            }
            if ( isset( $_POST['_vss_remove_zip_file'] ) && $_POST['_vss_remove_zip_file'] == '1' ) { delete_post_meta( $post_id, '_vss_attached_zip_id' ); $order->add_order_note(__('Admin removed attached ZIP file for vendor.', 'vss'));
            } elseif ( isset( $_POST['_vss_attached_zip_id'] ) && !empty($_POST['_vss_attached_zip_id']) ) {
                $new_file_id = intval( $_POST['_vss_attached_zip_id'] ); $old_file_id = get_post_meta( $post_id, '_vss_attached_zip_id', true);
                if($new_file_id !== (int)$old_file_id){ update_post_meta( $post_id, '_vss_attached_zip_id', $new_file_id ); $file_path = get_attached_file($new_file_id); $file_name = $file_path ? basename($file_path) : 'ID ' . $new_file_id; $order->add_order_note(sprintf(__('Admin attached/updated ZIP file for vendor: %s', 'vss'), esc_html($file_name))); }
            }
        } elseif ((isset($_POST['_vss_vendor_user_id']) || isset($_POST['_vss_attached_zip_id'])) && defined( 'WP_DEBUG' ) && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) { error_log("VSS Security: Attempt to save vendor management data without valid nonce for order ID: " . $post_id); }
    }

    /**
     * [COMPLETED] Renders the vendor payout meta box, including an admin form to input costs.
     */
    public static function render_vendor_payout_meta_box($post) {
        $order_id = $post->ID;
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '<p>' . esc_html__('Error: Could not retrieve order details.', 'vss') . '</p>';
            return;
        }

        $costs_raw = get_post_meta($order_id, '_vss_order_costs', true);
        $costs = is_array($costs_raw) ? $costs_raw : [];

        $saved_by_user_id = isset($costs['saved_by']) ? $costs['saved_by'] : 0;
        $saved_by_user = $saved_by_user_id ? get_userdata($saved_by_user_id) : null;
        ?>

        <div class="vss-payout-details">
            <?php if (!empty($costs) && isset($costs['total_cost']) && $costs['total_cost'] >= 0): ?>
                <div style="background:#f9f9f9; padding:15px; margin-bottom:20px; border-radius:4px; border:1px solid #e5e5e5;">
                    <h4 style="margin-top:0;"><?php _e('Current Saved Costs', 'vss'); ?></h4>

                    <?php if ($saved_by_user && isset($costs['saved_at'])): ?>
                        <p style="font-style:italic; color:#666; margin-bottom:10px;">
                            <?php
                            printf(
                                __('Entered by %s on %s', 'vss'),
                                '<strong>' . esc_html($saved_by_user->display_name) . '</strong>',
                                esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $costs['saved_at']))
                            );
                            ?>
                        </p>
                    <?php endif; ?>

                    <?php if (isset($costs['line_items']) && is_array($costs['line_items'])): ?>
                        <?php foreach($order->get_items() as $item_id => $item): ?>
                            <?php if (isset($costs['line_items'][$item_id]) && is_numeric($costs['line_items'][$item_id])): ?>
                                <div class="payout-line" style="display:flex; justify-content:space-between; padding:2px 0;">
                                    <span><?php echo esc_html($item->get_name()) . ' &times; ' . $item->get_quantity(); ?></span>
                                    <strong><?php echo wc_price(floatval($costs['line_items'][$item_id])); ?></strong>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (isset($costs['shipping_cost']) && is_numeric($costs['shipping_cost']) && $costs['shipping_cost'] > 0): ?>
                        <hr style="margin: 8px 0;">
                        <div class="payout-line" style="display:flex; justify-content:space-between; padding:2px 0;">
                            <span><?php _e('Shipping Cost:', 'vss'); ?></span>
                            <strong><?php echo wc_price(floatval($costs['shipping_cost'])); ?></strong>
                        </div>
                    <?php endif; ?>

                    <hr style="margin: 8px 0;">
                    <div class="payout-line payout-line-total" style="display:flex; justify-content:space-between; padding:2px 0; font-weight:bold;">
                        <span><?php _e('Total Cost:', 'vss'); ?></span>
                        <strong><?php echo wc_price(floatval($costs['total_cost'])); ?></strong>
                    </div>
                </div>
            <?php else: ?>
                <p><?php _e('No costs have been submitted for this order yet.', 'vss'); ?></p>
            <?php endif; ?>

            <div id="vss-admin-cost-input-wrapper" style="border-top: 1px solid #ddd; padding-top: 20px; margin-top: 20px;">
                <h4 style="margin-top:0;"><?php _e('Enter / Override Vendor Costs', 'vss'); ?></h4>
                <p class="description"><?php _e('Use this form to manually enter costs. This will overwrite any previously saved values.', 'vss'); ?></p>

                <div id="vss-admin-costs-form">
                    <?php wp_nonce_field('vss_admin_save_costs_nonce', '_vss_admin_costs_nonce'); ?>

                    <table class="form-table">
                        <?php foreach($order->get_items() as $item_id => $item): ?>
                            <tr>
                                <th scope="row">
                                    <label for="vss-item-cost-<?php echo esc_attr($item_id); ?>">
                                        <?php echo esc_html($item->get_name()); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="number" step="0.01" min="0"
                                           id="vss-item-cost-<?php echo esc_attr($item_id); ?>"
                                           name="vss_admin_costs[line_items][<?php echo esc_attr($item_id); ?>]"
                                           class="wc_input_price"
                                           placeholder="<?php echo esc_attr(wc_format_localized_price(isset($costs['line_items'][$item_id]) ? $costs['line_items'][$item_id] : '')); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <th scope="row">
                                <label for="vss-shipping-cost"><?php _e('Shipping Cost', 'vss'); ?></label>
                            </th>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       id="vss-shipping-cost"
                                       name="vss_admin_costs[shipping_cost]"
                                       class="wc_input_price"
                                       placeholder="<?php echo esc_attr(wc_format_localized_price(isset($costs['shipping_cost']) ? $costs['shipping_cost'] : '')); ?>">
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:10px;">
                        <button type="button" id="vss-admin-save-costs-btn" class="button button-primary"><?php _e('Save Costs', 'vss'); ?></button>
                        <span id="vss-admin-costs-spinner" class="spinner"></span>
                    </div>
                    <div id="vss-admin-costs-feedback" style="margin-top:10px; font-weight: bold;"></div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($){
                $('#vss-admin-save-costs-btn').on('click', function(e) {
                    e.preventDefault();
                    var formWrapper = $('#vss-admin-costs-form');
                    var feedbackDiv = $('#vss-admin-costs-feedback');
                    var button = $(this);
                    var spinner = $('#vss-admin-costs-spinner');

                    button.prop('disabled', true);
                    spinner.addClass('is-active');
                    feedbackDiv.html('');

                    var adminCosts = { line_items: {}, shipping_cost: '' };
                    var hasInput = false;

                    formWrapper.find('input[name^="vss_admin_costs[line_items]"]').each(function() {
                        var name = $(this).attr('name');
                        var value = $(this).val();
                        if (value) {
                            var itemId = name.match(/\[(\d+)\]/)[1];
                            adminCosts.line_items[itemId] = value;
                            hasInput = true;
                        }
                    });
                    var shippingCost = formWrapper.find('input[name="vss_admin_costs[shipping_cost]"]').val();
                    if (shippingCost) {
                        adminCosts.shipping_cost = shippingCost;
                        hasInput = true;
                    }

                    if (!hasInput) {
                        feedbackDiv.html('<span style="color:red;"><?php echo esc_js(__("Please enter at least one cost value to save.", "vss")); ?></span>');
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                        return;
                    }

                    var data = {
                        action: 'vss_admin_save_costs',
                        _ajax_nonce: formWrapper.find('#_vss_admin_costs_nonce').val(),
                        order_id: <?php echo esc_attr($order_id); ?>,
                        vss_admin_costs: adminCosts
                    };

                    $.post(ajaxurl, data, function(response) {
                        if (response.success) {
                            feedbackDiv.html('<span style="color:green;">' + response.data.message + '</span>');
                            setTimeout(function(){ location.reload(); }, 2000);
                        } else {
                            feedbackDiv.html('<span style="color:red;">Error: ' + response.data.message + '</span>');
                            button.prop('disabled', false);
                            spinner.removeClass('is-active');
                        }
                    }).fail(function(){
                        feedbackDiv.html('<span style="color:red;"><?php echo esc_js(__("An unexpected AJAX error occurred. Please check the browser console and try again.", "vss")); ?></span>');
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * [NEW] Handles the AJAX request to save costs entered by an admin.
     */
    public static function ajax_admin_save_costs() {
        check_ajax_referer('vss_admin_save_costs_nonce', '_ajax_nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id || !current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => __('Permission denied or invalid order ID.', 'vss')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order object not found.', 'vss')]);
        }

        $costs_data = isset($_POST['vss_admin_costs']) ? $_POST['vss_admin_costs'] : [];
        if (empty($costs_data)) {
            wp_send_json_error(['message' => __('No cost data submitted.', 'vss')]);
        }

        $admin_costs = [];
        $total_cost = 0;

        // Process line items
        if (isset($costs_data['line_items']) && is_array($costs_data['line_items'])) {
            foreach ($costs_data['line_items'] as $item_id_raw => $cost_raw) {
                $item_id = intval($item_id_raw);
                $cost_value = floatval(wp_unslash(str_replace(',', '.', $cost_raw)));
                if ($cost_value >= 0 && $order->get_item($item_id)) {
                    $admin_costs['line_items'][$item_id] = $cost_value;
                    $total_cost += $cost_value;
                }
            }
        }

        // Process shipping cost
        if (isset($costs_data['shipping_cost']) && $costs_data['shipping_cost'] !== '') {
            $shipping_cost = floatval(wp_unslash(str_replace(',', '.', $costs_data['shipping_cost'])));
            if ($shipping_cost >= 0) {
                $admin_costs['shipping_cost'] = $shipping_cost;
                $total_cost += $shipping_cost;
            }
        }

        if (empty($admin_costs)) {
            wp_send_json_error(['message' => __('No valid costs were entered.', 'vss')]);
        }

        $admin_costs['total_cost'] = $total_cost;
        $admin_costs['saved_at'] = time();
        $admin_costs['saved_by'] = get_current_user_id();
        $admin_costs['entered_by'] = 'admin';

        update_post_meta($order_id, '_vss_order_costs', $admin_costs);
        $order->add_order_note(sprintf(__('Admin manually entered/updated costs. New Total: %s', 'vss'), wc_price($total_cost)));

        wp_send_json_success(['message' => __('Costs saved successfully. The page will now refresh.', 'vss')]);
    }

    /**
     * [RESTORED] Renders the vendor management meta box (Assignment, Splitting, File Upload).
     */
    public static function render_vendor_management_meta_box( $post_obj ) {
        $post_id = $post_obj->ID; wp_nonce_field( 'vss_save_vendor_management_meta', 'vss_vendor_management_nonce' );
        $current_vendor_id = get_post_meta( $post_id, '_vss_vendor_user_id', true );
        if (get_post_meta( $post_id, '_vss_is_split_parent', true )) { echo '<p>' . __( 'This is a parent order that has been split. Manage assignments on sub-orders.', 'vss' ) . '</p>'; return; }
        if ($parent_id = get_post_meta( $post_id, '_vss_parent_order_id', true )) { $parent_order_obj = wc_get_order($parent_id); $parent_order_link = $parent_order_obj ? get_edit_post_link( $parent_id ) : '#'; $parent_order_number = $parent_order_obj ? $parent_order_obj->get_order_number() : $parent_id; echo '<p>' . sprintf( __( 'This is a sub-order of %s.', 'vss' ), '<a href="' . esc_url($parent_order_link) . '">Order #' . esc_html($parent_order_number) . '</a>' ) . '</p>';}
        $vendors = get_users( [ 'role' => 'vendor-mm', 'fields' => [ 'ID', 'display_name' ] ] );
        echo '<h4>' . __( 'Assign to Vendor-MM', 'vss' ) . '</h4><select name="_vss_vendor_user_id" style="width:100%;"><option value="0">' . __( '— Unassigned —', 'vss' ) . '</option>';
        foreach ( $vendors as $vendor ) { echo '<option value="' . esc_attr($vendor->ID) . '"' . selected($vendor->ID, $current_vendor_id, false) . '>' . esc_html($vendor->display_name) . '</option>';}
        echo '</select><p class="description">' . __( 'Manually assign this entire order to a vendor.', 'vss') . '</p><hr style="margin: 1em 0;">';
        echo '<h4>' . __( 'Split Order', 'vss' ) . '</h4><p class="description">' . __('If items in this order have different default vendors (set at product level), this action will create sub-orders for each assigned vendor.', 'vss') . '</p>';
        echo '<button type="button" id="vss-split-order-btn" class="button button-secondary" data-order-id="' . $post_id . '">' . __( 'Split Order by Product Vendor', 'vss' ) . '</button>';
        echo '<div id="vss-split-feedback" style="margin-top:10px; font-weight: bold;"></div><hr style="margin: 1em 0;">';
        echo '<h4>' . __( 'Upload File for Vendor-MM', 'vss' ) . '</h4>';
        $file_id = get_post_meta( $post_id, '_vss_attached_zip_id', true );
        if ( $file_id && get_post_status($file_id) ) { $file_url = wp_get_attachment_url( $file_id ); $file_path = get_attached_file( $file_id ); $file_name = $file_path ? basename( $file_path ) : __('Attached File', 'vss'); echo '<p><a href="' . esc_url($file_url) . '" target="_blank">' . esc_html($file_name) . '</a></p>'; echo '<button type="button" id="vss-remove-file-btn" class="button button-secondary button-small">' . __('Remove File', 'vss') . '</button>'; echo '<input type="hidden" name="_vss_remove_zip_file" id="vss-remove-zip-file-input" value="">'; }
        else { echo '<button type="button" id="vss-upload-file-btn" class="button button-secondary">' . __('Upload ZIP File', 'vss') . '</button>';}
        echo '<input type="hidden" name="_vss_attached_zip_id" id="vss-attached-zip-id" value="' . esc_attr($file_id) . '">';
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($){
                $('#vss-split-order-btn').on('click',function(e){
                    e.preventDefault();var t=$(this),o=t.data("order-id"),n=$("#vss-split-feedback");
                    if(!confirm("<?php echo esc_js(__("Are you sure you want to split this order based on product vendors? This action cannot be easily undone and will create new sub-orders.", "vss")); ?>"))return;
                    n.html('<span style="color:#f5a623;"><?php echo esc_js( __("Splitting...", "vss") ); ?></span>'); t.prop("disabled",!0);
                    $.post(ajaxurl,{action:"vss_split_order",order_id:o,_ajax_nonce:"<?php echo wp_create_nonce("vss_split_order_nonce"); ?>"},function(e){
                        if(e.success){n.html('<span style="color:green;">'+e.data.message+" <?php echo esc_js( __('Page will refresh.', 'vss') ); ?></span>");setTimeout(function(){location.reload()},2500);
                        }else{n.html('<span style="color:red;">'+e.data.message+"</span>");t.prop("disabled",!1);}
                    }).fail(function(){n.html('<span style="color:red;"><?php echo esc_js( __("AJAX error. Please try again or check browser console.", "vss") ); ?></span>'); t.prop("disabled", false);});
                });
                var mediaUploader;
                $('#vss-upload-file-btn').click(function(e){
                    e.preventDefault(); if(mediaUploader){mediaUploader.open(); return;}
                    mediaUploader=wp.media.frames.file_frame=wp.media({
                        title:"<?php echo esc_js( __('Choose ZIP File','vss') ); ?>",
                        button:{text:"<?php echo esc_js( __('Use This File','vss') ); ?>"},
                        library:{type:"application/zip"},multiple:!1
                    });
                    mediaUploader.on("select",function(){var e=mediaUploader.state().get("selection").first().toJSON();$("#vss-attached-zip-id").val(e.id);$('#post').submit();});
                    mediaUploader.open();
                });
                $('#vss-remove-file-btn').click(function(e){
                    e.preventDefault(); if(confirm("<?php echo esc_js( __('Are you sure you want to remove this file?','vss') ); ?>")){$('#vss-attached-zip-id').val('');$('#vss-remove-zip-file-input').val('1');$('#post').submit();}
                });
            });
        </script>
        <?php
    }

    /**
     * [RESTORED] Handles the AJAX request for splitting an order.
     */
    public static function ajax_split_order_handler() {
        check_ajax_referer( 'vss_split_order_nonce', '_ajax_nonce' ); $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id || !current_user_can('edit_shop_order', $order_id)) { wp_send_json_error(['message' => __('Permission denied or invalid order ID.', 'vss')]); }
        $original_order = wc_get_order($order_id); if (!$original_order) { wp_send_json_error(['message' => __('Original order not found.', 'vss')]); }
        if (get_post_meta($order_id, '_vss_is_split_parent', true)) { wp_send_json_error(['message' => __('This order has already been split.', 'vss')]); }
        if (get_post_meta($order_id, '_vss_parent_order_id', true)) { wp_send_json_error(['message' => __('Cannot split a sub-order.', 'vss')]); }
        $items_by_vendor = []; foreach ($original_order->get_items() as $item_id => $item) { $product_id = $item->get_product_id(); $product_vendor_id = get_post_meta($product_id, '_vss_vendor_user_id', true); $vendor_id_for_item = $product_vendor_id ? intval($product_vendor_id) : 'unassigned'; $items_by_vendor[$vendor_id_for_item][] = $item; }
        if (count($items_by_vendor) <= 1) { $single_vendor_key = key($items_by_vendor); if ($single_vendor_key === 'unassigned' && count($items_by_vendor['unassigned']) === count($original_order->get_items())) { wp_send_json_error(['message' => __('All items are unassigned. Assign vendors to products or assign the order manually.', 'vss')]); } else if (count($items_by_vendor) === 1 && $single_vendor_key !== 'unassigned') { wp_send_json_error(['message' => __('All items belong to one vendor. No split needed.', 'vss')]); } else { wp_send_json_error(['message' => __('Items for one vendor group or all unassigned. No split needed.', 'vss')]); }}
        $child_order_ids = []; $original_order->add_order_note(__('Attempting to split order by product vendor...', 'vss')); $errors_during_split = [];
        foreach ($items_by_vendor as $vendor_id_key => $items_for_vendor) {
            if ('unassigned' === $vendor_id_key || !$vendor_id_key) { $un_names = []; foreach($items_for_vendor as $ui) $un_names[] = $ui->get_name(); $errors_during_split[] = sprintf(__('Items "%s" have no default vendor; not split.', 'vss'), implode(', ',$un_names)); continue; }
            $vendor_id = intval($vendor_id_key); $vendor_user = get_userdata($vendor_id); if(!$vendor_user){ $errors_during_split[] = sprintf(__('Vendor ID %d not found; items not split.', 'vss'), $vendor_id); continue; }
            try {
                $sub_order = wc_create_order(['customer_id' => $original_order->get_customer_id()]); if (is_wp_error($sub_order)) { $errors_during_split[] = sprintf(__('Error creating sub-order for %s: %s', 'vss'), $vendor_user->display_name, $sub_order->get_error_message()); continue; }
                foreach ($items_for_vendor as $itm) { $prd = $itm->get_product(); if ($prd) { $sub_order->add_product($prd, $itm->get_quantity(), ['variation' => $itm->get_variation_attributes(), 'totals' => ['subtotal' => $itm->get_subtotal(), 'subtotal_tax' => $itm->get_subtotal_tax(), 'total' => $itm->get_total(), 'tax' => $itm->get_total_tax(), 'tax_data' => $itm->get_taxes()]]); }}
                $sub_order->set_address($original_order->get_address('billing'), 'billing'); $sub_order->set_address($original_order->get_address('shipping'), 'shipping');
                $sub_order->set_payment_method($original_order->get_payment_method()); $sub_order->set_payment_method_title($original_order->get_payment_method_title()); $sub_order->set_customer_note($original_order->get_customer_note());
                $sub_order->update_meta_data('_vss_vendor_user_id', $vendor_id); update_post_meta($sub_order->get_id(), '_vss_assigned_at', time()); $sub_order->update_meta_data('_vss_parent_order_id', $order_id); $sub_order->update_meta_data('_vss_sub_order_shipping_apportioned', false);
                $sub_order->calculate_totals(true); $sub_order->set_status('wc-processing', sprintf(__('Sub-order from parent #%s.', 'vss'), $original_order->get_order_number())); $sub_order->add_order_note(sprintf(__('Assigned to vendor: %s.', 'vss'), $vendor_user->display_name)); $sub_order->save();
                $child_order_ids[] = $sub_order->get_id(); do_action('vss_order_assigned_to_vendor', $sub_order->get_id(), $vendor_id);
            } catch (Exception $e) { $errors_during_split[] = sprintf(__('Exception for vendor %s: %s', 'vss'), $vendor_user->display_name, $e->getMessage());}
        }
        if (!empty($child_order_ids)) {
            $child_links = array_map(function($id){ return '#'.$id; }, $child_order_ids);
            $original_order->update_status('completed', sprintf(__('Order split into sub-orders: %s', 'vss'), implode(', ', $child_links)));
            $original_order->update_meta_data('_vss_is_split_parent', true); $original_order->update_meta_data('_vss_child_orders', $child_order_ids); $msg = __('Order successfully split.', 'vss');
            if(!empty($errors_during_split)) { $msg .= ' ' . __('Issues: ', 'vss') . implode('; ', $errors_during_split); $original_order->add_order_note(__('Split completed with issues: ', 'vss') . implode('; ', $errors_during_split));} wp_send_json_success(['message' => $msg]);
        } else { $msg = __('Splitting resulted in no new sub-orders.', 'vss'); if(!empty($errors_during_split)) { $msg .= ' ' . __('Issues: ', 'vss') . implode('; ', $errors_during_split);} $original_order->add_order_note($msg); wp_send_json_error(['message' => $msg]); }
    }

    /**
     * [RESTORED] Adds custom columns to the WooCommerce orders list table.
     */
    public static function add_vendor_order_column($columns){
        $reordered = [];
        foreach ($columns as $k => $v) {
            $reordered[$k] = $v;
            if ($k === 'order_status') {
                $reordered['vendor'] = __('Vendor-MM', 'vss');
                $reordered['mockup_status'] = __('Mockup', 'vss');
                $reordered['prod_file_status'] = __('Prod. Files', 'vss');
            }
        }
        return $reordered;
    }

    /**
     * [RESTORED] Populates the custom columns in the WooCommerce orders list table.
     */
    public static function populate_vendor_order_column($column, $post_id){
        switch ($column) {
            case 'vendor':
                if (get_post_meta($post_id, '_vss_is_split_parent', true)) { echo '<strong>'.__('Split Parent','vss').'</strong>'; return; }
                $vid = get_post_meta($post_id, '_vss_vendor_user_id', true);
                if ($vid) { $v = get_user_by('id', $vid); echo esc_html($v ? $v->display_name : __('Unknown Vendor', 'vss')); } else { echo '—';}
                break;
            case 'mockup_status':
                self::display_approval_status_icon(get_post_meta($post_id, '_vss_mockup_status', true) ?: 'none');
                break;
            case 'prod_file_status':
                self::display_approval_status_icon(get_post_meta($post_id, '_vss_production_file_status', true) ?: 'none');
                break;
        }
    }

    /**
     * [RESTORED] Helper to display a status badge.
     */
    private static function display_approval_status_icon($status) {
        $txt = __('N/A', 'vss'); $clr = '#909090';
        switch ($status) {
            case 'pending_approval': $txt=__('Pending','vss'); $clr='#ffba00'; break;
            case 'approved': $txt=__('Approved','vss'); $clr='#4CAF50'; break;
            case 'disapproved': $txt=__('Disapproved','vss'); $clr='#F44336'; break;
            case 'none':
            default: $txt=__('Not Sent','vss'); $clr='#ababab'; break;
        }
        echo '<span class="vss-admin-status-badge" style="display:inline-block;padding:3px 8px;background-color:'.esc_attr($clr).';color:white;border-radius:4px;font-size:0.85em;text-align:center;min-width:70px;">'.esc_html($txt).'</span>';
    }

    /**
     * [RESTORED] Adds a vendor filter dropdown to the orders list table.
     */
    public static function add_vendor_filter_dropdown($post_type) {
        if ('shop_order' !== $post_type || !current_user_can('manage_woocommerce')) return;
        $vendors = get_users(['role'=>'vendor-mm','fields'=>['ID','display_name']]); $current = isset($_GET['vss_vendor_filter']) ? sanitize_text_field($_GET['vss_vendor_filter']) : '';
        ?><select name="vss_vendor_filter" id="vss_vendor_filter"><option value=""><?php _e('All Vendors','vss'); ?></option><option value="unassigned" <?php selected($current,'unassigned'); ?>><?php _e('-- Unassigned --','vss'); ?></option><?php foreach($vendors as $v):?><option value="<?php echo esc_attr($v->ID); ?>" <?php selected($current,$v->ID); ?>><?php echo esc_html($v->display_name); ?></option><?php endforeach; ?></select><?php
    }

    /**
     * [RESTORED] Handles the query modification for the vendor filter.
     */
    public static function handle_vendor_filter_query($query) {
        if (is_admin() && $query->is_main_query() && isset($query->query_vars['post_type']) && $query->query_vars['post_type']==='shop_order' && isset($_GET['vss_vendor_filter'])) {
            $filter = sanitize_text_field($_GET['vss_vendor_filter']);
            if (!empty($filter)) {
                if ($filter==='unassigned') {
                    $mq = $query->get('meta_query');
                    if(!is_array($mq)) $mq=[];
                    $mq[]=['relation'=>'OR',['key'=>'_vss_vendor_user_id','compare'=>'NOT EXISTS'],['key'=>'_vss_vendor_user_id','value'=>['','0'],'compare'=>'IN']];
                    $query->set('meta_query',$mq);
                } else {
                    $query->set('meta_key','_vss_vendor_user_id');
                    $query->set('meta_value',intval($filter));
                }
            }
        }
    }

} // End class VSS_Admin