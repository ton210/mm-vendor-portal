<?php
/**
 * VSS External Orders Integration
 *
 * Handles importing orders from external WooCommerce and BigCommerce stores
 *
 * @package VendorOrderManager
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_External_Orders {

    /**
     * Initialize external orders functionality
     */
    public static function init() {
        // Admin menu
        add_action( 'admin_menu', [ self::class, 'add_admin_menu' ] );

        // AJAX handlers
        add_action( 'wp_ajax_vss_import_external_orders', [ self::class, 'ajax_import_orders' ] );
        add_action( 'wp_ajax_vss_test_external_connection', [ self::class, 'ajax_test_connection' ] );

        // Scheduled imports
        add_action( 'vss_import_external_orders_cron', [ self::class, 'scheduled_import' ] );

        // Add settings
        add_action( 'admin_init', [ self::class, 'register_settings' ] );

        // Schedule cron if enabled
        add_action( 'init', [ self::class, 'setup_cron' ] );
    }

    /**
     * Add admin menu items
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'External Orders', 'vss' ),
            __( 'External Orders', 'vss' ),
            'manage_woocommerce',
            'vss-external-orders',
            [ self::class, 'render_admin_page' ]
        );
    }

    /**
     * Register settings
     */
    public static function register_settings() {
        // WooCommerce API settings
        register_setting( 'vss_external_orders', 'vss_wc_api_url' );
        register_setting( 'vss_external_orders', 'vss_wc_consumer_key' );
        register_setting( 'vss_external_orders', 'vss_wc_consumer_secret' );

        // BigCommerce API settings
        register_setting( 'vss_external_orders', 'vss_bc_store_hash' );
        register_setting( 'vss_external_orders', 'vss_bc_access_token' );
        register_setting( 'vss_external_orders', 'vss_bc_client_id' );

        // Import settings
        register_setting( 'vss_external_orders', 'vss_auto_import_enabled' );
        register_setting( 'vss_external_orders', 'vss_import_interval' );
        register_setting( 'vss_external_orders', 'vss_default_vendor_id' );
        register_setting( 'vss_external_orders', 'vss_import_order_status' );
    }

    /**
     * Setup cron schedule
     */
    public static function setup_cron() {
        $auto_import = get_option( 'vss_auto_import_enabled', false );
        $interval = get_option( 'vss_import_interval', 'hourly' );

        if ( $auto_import ) {
            if ( ! wp_next_scheduled( 'vss_import_external_orders_cron' ) ) {
                wp_schedule_event( time(), $interval, 'vss_import_external_orders_cron' );
            }
        } else {
            wp_clear_scheduled_hook( 'vss_import_external_orders_cron' );
        }
    }

    /**
     * Render admin page
     */
    public static function render_admin_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'External Orders Import', 'vss' ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'vss_external_orders' ); ?>

                <h2><?php esc_html_e( 'WooCommerce Store Settings', 'vss' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Store URL', 'vss' ); ?></th>
                        <td>
                            <input type="url" name="vss_wc_api_url" value="<?php echo esc_attr( get_option( 'vss_wc_api_url' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Full URL to the external WooCommerce store (e.g., https://store.com)', 'vss' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Consumer Key', 'vss' ); ?></th>
                        <td>
                            <input type="text" name="vss_wc_consumer_key" value="<?php echo esc_attr( get_option( 'vss_wc_consumer_key' ) ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Consumer Secret', 'vss' ); ?></th>
                        <td>
                            <input type="password" name="vss_wc_consumer_secret" value="<?php echo esc_attr( get_option( 'vss_wc_consumer_secret' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Generate API keys in WooCommerce > Settings > Advanced > REST API', 'vss' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'BigCommerce Store Settings', 'vss' ); ?></h2>
                <div class="notice notice-info inline">
                    <p><?php esc_html_e( 'Note: Only orders from July 23, 2025 onwards will be imported from BigCommerce.', 'vss' ); ?></p>
                </div>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Store Hash', 'vss' ); ?></th>
                        <td>
                            <input type="text" name="vss_bc_store_hash" value="<?php echo esc_attr( get_option( 'vss_bc_store_hash' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Found in your BigCommerce control panel URL', 'vss' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Access Token', 'vss' ); ?></th>
                        <td>
                            <input type="password" name="vss_bc_access_token" value="<?php echo esc_attr( get_option( 'vss_bc_access_token' ) ); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client ID', 'vss' ); ?></th>
                        <td>
                            <input type="text" name="vss_bc_client_id" value="<?php echo esc_attr( get_option( 'vss_bc_client_id' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Create API account in BigCommerce > Settings > API', 'vss' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Import Settings', 'vss' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Auto Import', 'vss' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="vss_auto_import_enabled" value="1" <?php checked( get_option( 'vss_auto_import_enabled' ), 1 ); ?> />
                                <?php esc_html_e( 'Enable automatic order imports', 'vss' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Import Interval', 'vss' ); ?></th>
                        <td>
                            <select name="vss_import_interval">
                                <option value="hourly" <?php selected( get_option( 'vss_import_interval' ), 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'vss' ); ?></option>
                                <option value="twicedaily" <?php selected( get_option( 'vss_import_interval' ), 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'vss' ); ?></option>
                                <option value="daily" <?php selected( get_option( 'vss_import_interval' ), 'daily' ); ?>><?php esc_html_e( 'Daily', 'vss' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Default Vendor', 'vss' ); ?></th>
                        <td>
                            <?php
                            $vendors = get_users( [ 'role' => 'vendor-mm' ] );
                            ?>
                            <select name="vss_default_vendor_id">
                                <option value=""><?php esc_html_e( '— Unassigned —', 'vss' ); ?></option>
                                <?php foreach ( $vendors as $vendor ) : ?>
                                    <option value="<?php echo esc_attr( $vendor->ID ); ?>" <?php selected( get_option( 'vss_default_vendor_id' ), $vendor->ID ); ?>>
                                        <?php echo esc_html( $vendor->display_name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Imported orders will be assigned to this vendor by default', 'vss' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Import Order Status', 'vss' ); ?></th>
                        <td>
                            <select name="vss_import_order_status">
                                <option value="all" <?php selected( get_option( 'vss_import_order_status', 'all' ), 'all' ); ?>><?php esc_html_e( 'All Statuses', 'vss' ); ?></option>
                                <option value="processing" <?php selected( get_option( 'vss_import_order_status' ), 'processing' ); ?>><?php esc_html_e( 'Processing Only', 'vss' ); ?></option>
                                <option value="pending,processing" <?php selected( get_option( 'vss_import_order_status' ), 'pending,processing' ); ?>><?php esc_html_e( 'Pending & Processing', 'vss' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Manual Import', 'vss' ); ?></h2>
            <div class="vss-import-controls">
                <button type="button" class="button" id="vss-test-wc-connection">
                    <?php esc_html_e( 'Test WooCommerce Connection', 'vss' ); ?>
                </button>
                <button type="button" class="button" id="vss-test-bc-connection">
                    <?php esc_html_e( 'Test BigCommerce Connection', 'vss' ); ?>
                </button>
                <button type="button" class="button button-primary" id="vss-import-orders">
                    <?php esc_html_e( 'Import Orders Now', 'vss' ); ?>
                </button>
            </div>

            <div id="vss-import-log" style="margin-top: 20px; padding: 10px; background: #f1f1f1; display: none;">
                <h3><?php esc_html_e( 'Import Log', 'vss' ); ?></h3>
                <div id="vss-import-messages"></div>
            </div>

            <hr />

            <h2><?php esc_html_e( 'Recent Imports', 'vss' ); ?></h2>
            <?php self::render_import_history(); ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Test WooCommerce connection
            $('#vss-test-wc-connection').on('click', function() {
                var button = $(this);
                button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vss_test_external_connection',
                        platform: 'woocommerce',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'vss_external_orders' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('WooCommerce connection successful!');
                        } else {
                            alert('Connection failed: ' + response.data.message);
                        }
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            // Test BigCommerce connection
            $('#vss-test-bc-connection').on('click', function() {
                var button = $(this);
                button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vss_test_external_connection',
                        platform: 'bigcommerce',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'vss_external_orders' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('BigCommerce connection successful!');
                        } else {
                            alert('Connection failed: ' + response.data.message);
                        }
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            // Import orders
            $('#vss-import-orders').on('click', function() {
                var button = $(this);
                button.prop('disabled', true);

                $('#vss-import-log').show();
                $('#vss-import-messages').html('<p>Starting import...</p>');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vss_import_external_orders',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'vss_external_orders' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#vss-import-messages').html(response.data.log);
                            location.reload(); // Refresh to show new orders
                        } else {
                            $('#vss-import-messages').html('<p class="error">Import failed: ' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        $('#vss-import-messages').html('<p class="error">Import failed: Network error</p>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render import history
     */
    private static function render_import_history() {
        global $wpdb;

        $imports = $wpdb->get_results( "
            SELECT * FROM {$wpdb->prefix}vss_import_log
            ORDER BY import_date DESC
            LIMIT 10
        " );

        if ( empty( $imports ) ) {
            echo '<p>' . esc_html__( 'No imports yet.', 'vss' ) . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Date', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Platform', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Orders Imported', 'vss' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'vss' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $imports as $import ) : ?>
                    <tr>
                        <td><?php echo esc_html( $import->import_date ); ?></td>
                        <td><?php echo esc_html( ucfirst( $import->platform ) ); ?></td>
                        <td><?php echo esc_html( $import->orders_imported ); ?></td>
                        <td><?php echo esc_html( $import->status ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * AJAX test connection handler
     */
    public static function ajax_test_connection() {
        check_ajax_referer( 'vss_external_orders' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'vss' ) ] );
        }

        $platform = sanitize_key( $_POST['platform'] );

        if ( $platform === 'woocommerce' ) {
            $result = self::test_woocommerce_connection();
        } elseif ( $platform === 'bigcommerce' ) {
            $result = self::test_bigcommerce_connection();
        } else {
            wp_send_json_error( [ 'message' => __( 'Invalid platform', 'vss' ) ] );
        }

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Test WooCommerce connection
     */
    private static function test_woocommerce_connection() {
        $url = get_option( 'vss_wc_api_url' );
        $consumer_key = get_option( 'vss_wc_consumer_key' );
        $consumer_secret = get_option( 'vss_wc_consumer_secret' );

        if ( ! $url || ! $consumer_key || ! $consumer_secret ) {
            return [
                'success' => false,
                'message' => __( 'WooCommerce API credentials not configured', 'vss' ),
            ];
        }

        $response = wp_remote_get( $url . '/wp-json/wc/v3/orders?per_page=1', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret ),
            ],
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [
                'success' => false,
                'message' => sprintf( __( 'API returned status code %d', 'vss' ), $code ),
            ];
        }

        return [
            'success' => true,
            'message' => __( 'Connection successful', 'vss' ),
        ];
    }

    /**
     * Test BigCommerce connection
     */
    private static function test_bigcommerce_connection() {
        $store_hash = get_option( 'vss_bc_store_hash' );
        $access_token = get_option( 'vss_bc_access_token' );

        if ( ! $store_hash || ! $access_token ) {
            return [
                'success' => false,
                'message' => __( 'BigCommerce API credentials not configured', 'vss' ),
            ];
        }

        $response = wp_remote_get(
            'https://api.bigcommerce.com/stores/' . $store_hash . '/v2/orders?limit=1',
            [
                'headers' => [
                    'X-Auth-Token' => $access_token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return [
                'success' => false,
                'message' => sprintf( __( 'API returned status code %d', 'vss' ), $code ),
            ];
        }

        return [
            'success' => true,
            'message' => __( 'Connection successful', 'vss' ),
        ];
    }

    /**
     * AJAX import orders handler
     */
    public static function ajax_import_orders() {
        check_ajax_referer( 'vss_external_orders' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'vss' ) ] );
        }

        $log = [];
        $total_imported = 0;

        // Import from WooCommerce
        $wc_result = self::import_woocommerce_orders();
        $log[] = sprintf( __( 'WooCommerce: %s', 'vss' ), $wc_result['message'] );
        $total_imported += $wc_result['imported'];

        // Import from BigCommerce
        $bc_result = self::import_bigcommerce_orders();
        $log[] = sprintf( __( 'BigCommerce: %s', 'vss' ), $bc_result['message'] );
        $total_imported += $bc_result['imported'];

        // Log import
        self::log_import( 'manual', $total_imported, 'success' );

        $log[] = sprintf( __( 'Total orders imported: %d', 'vss' ), $total_imported );

        wp_send_json_success( [
            'imported' => $total_imported,
            'log' => '<p>' . implode( '</p><p>', $log ) . '</p>',
        ] );
    }

    /**
     * Import WooCommerce orders
     */
    private static function import_woocommerce_orders() {
        $url = get_option( 'vss_wc_api_url' );
        $consumer_key = get_option( 'vss_wc_consumer_key' );
        $consumer_secret = get_option( 'vss_wc_consumer_secret' );

        if ( ! $url || ! $consumer_key || ! $consumer_secret ) {
            return [
                'imported' => 0,
                'message' => __( 'API credentials not configured', 'vss' ),
            ];
        }

        // Get last import date
        $last_import = get_option( 'vss_wc_last_import', false );
        $params = [
            'per_page' => 100,
            'orderby' => 'date',
            'order' => 'desc',
        ];

        if ( $last_import ) {
            $params['after'] = date( 'c', strtotime( $last_import ) );
        }

        $status_filter = get_option( 'vss_import_order_status', 'all' );
        if ( $status_filter !== 'all' ) {
            $params['status'] = explode( ',', $status_filter );
        }

        $response = wp_remote_get(
            add_query_arg( $params, $url . '/wp-json/wc/v3/orders' ),
            [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret ),
                ],
                'timeout' => 60,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'imported' => 0,
                'message' => $response->get_error_message(),
            ];
        }

        $orders = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $orders ) ) {
            return [
                'imported' => 0,
                'message' => __( 'Invalid response from API', 'vss' ),
            ];
        }

        $imported = 0;
        foreach ( $orders as $external_order ) {
            if ( self::create_order_from_woocommerce( $external_order ) ) {
                $imported++;
            }
        }

        update_option( 'vss_wc_last_import', current_time( 'mysql' ) );

        return [
            'imported' => $imported,
            'message' => sprintf( __( '%d orders imported', 'vss' ), $imported ),
        ];
    }

    /**
     * Create order from WooCommerce data
     */
    private static function create_order_from_woocommerce( $external_order ) {
        // Check if order already exists
        $existing = get_posts( [
            'post_type' => 'shop_order',
            'meta_key' => '_vss_external_order_id',
            'meta_value' => 'wc_' . $external_order['id'],
            'posts_per_page' => 1,
        ] );

        if ( ! empty( $existing ) ) {
            return false; // Order already imported
        }

        // Create new order
        $order = wc_create_order( [
            'status' => $external_order['status'],
            'customer_id' => 0, // Guest order
        ] );

        if ( ! $order ) {
            return false;
        }

        // Set billing details
        $order->set_billing_first_name( $external_order['billing']['first_name'] );
        $order->set_billing_last_name( $external_order['billing']['last_name'] );
        $order->set_billing_email( $external_order['billing']['email'] );
        $order->set_billing_phone( $external_order['billing']['phone'] );
        $order->set_billing_address_1( $external_order['billing']['address_1'] );
        $order->set_billing_address_2( $external_order['billing']['address_2'] );
        $order->set_billing_city( $external_order['billing']['city'] );
        $order->set_billing_state( $external_order['billing']['state'] );
        $order->set_billing_postcode( $external_order['billing']['postcode'] );
        $order->set_billing_country( $external_order['billing']['country'] );

        // Set shipping details
        $order->set_shipping_first_name( $external_order['shipping']['first_name'] );
        $order->set_shipping_last_name( $external_order['shipping']['last_name'] );
        $order->set_shipping_address_1( $external_order['shipping']['address_1'] );
        $order->set_shipping_address_2( $external_order['shipping']['address_2'] );
        $order->set_shipping_city( $external_order['shipping']['city'] );
        $order->set_shipping_state( $external_order['shipping']['state'] );
        $order->set_shipping_postcode( $external_order['shipping']['postcode'] );
        $order->set_shipping_country( $external_order['shipping']['country'] );

        // Add line items
        foreach ( $external_order['line_items'] as $item ) {
            // Try to find matching product by SKU
            $product_id = wc_get_product_id_by_sku( $item['sku'] );

            if ( ! $product_id ) {
                // Create a simple product if not found
                $product = new WC_Product_Simple();
                $product->set_name( $item['name'] );
                $product->set_sku( $item['sku'] );
                $product->set_price( $item['price'] );
                $product->set_regular_price( $item['price'] );
                $product->save();
                $product_id = $product->get_id();
            }

            $order->add_product( wc_get_product( $product_id ), $item['quantity'] );
        }

        // Set totals
        $order->set_total( $external_order['total'] );

        // Save order
        $order->save();

        // Add meta data
        update_post_meta( $order->get_id(), '_vss_external_order_id', 'wc_' . $external_order['id'] );
        update_post_meta( $order->get_id(), '_vss_external_source', 'woocommerce' );
        update_post_meta( $order->get_id(), '_vss_external_order_number', $external_order['number'] );

        // Assign to default vendor
        $default_vendor = get_option( 'vss_default_vendor_id' );
        if ( $default_vendor ) {
            update_post_meta( $order->get_id(), '_vss_vendor_user_id', $default_vendor );
        }

        // Add order note
        $order->add_order_note( sprintf(
            __( 'Order imported from external WooCommerce store. Original order #%s', 'vss' ),
            $external_order['number']
        ) );

        return true;
    }

    /**
     * Import BigCommerce orders
     */
    private static function import_bigcommerce_orders() {
        $store_hash = get_option( 'vss_bc_store_hash' );
        $access_token = get_option( 'vss_bc_access_token' );

        if ( ! $store_hash || ! $access_token ) {
            return [
                'imported' => 0,
                'message' => __( 'API credentials not configured', 'vss' ),
            ];
        }

        // Set minimum date to July 23, 2025
        $minimum_date = '2025-07-23T00:00:00Z';

        // Get last import date
        $last_import = get_option( 'vss_bc_last_import', false );
        $params = [
            'limit' => 100,
            'sort' => 'date_created:desc',
            'min_date_created' => $minimum_date, // Always use minimum date
        ];

        // If we have a last import date that's after our minimum, use that instead
        if ( $last_import ) {
            $last_import_time = strtotime( $last_import );
            $minimum_time = strtotime( $minimum_date );

            if ( $last_import_time > $minimum_time ) {
                $params['min_date_created'] = date( 'c', $last_import_time );
            }
        }

        $response = wp_remote_get(
            add_query_arg( $params, 'https://api.bigcommerce.com/stores/' . $store_hash . '/v2/orders' ),
            [
                'headers' => [
                    'X-Auth-Token' => $access_token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 60,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'imported' => 0,
                'message' => $response->get_error_message(),
            ];
        }

        $orders = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $orders ) ) {
            return [
                'imported' => 0,
                'message' => __( 'Invalid response from API', 'vss' ),
            ];
        }

        $imported = 0;
        $skipped = 0;

        foreach ( $orders as $external_order ) {
            // Double-check the order date
            $order_date = strtotime( $external_order['date_created'] );
            $minimum_time = strtotime( '2025-07-23' );

            if ( $order_date < $minimum_time ) {
                $skipped++;
                continue; // Skip orders before July 23, 2025
            }

            if ( self::create_order_from_bigcommerce( $external_order ) ) {
                $imported++;
            }
        }

        update_option( 'vss_bc_last_import', current_time( 'mysql' ) );

        $message = sprintf( __( '%d orders imported', 'vss' ), $imported );
        if ( $skipped > 0 ) {
            $message .= sprintf( __( ' (%d orders skipped - before July 23, 2025)', 'vss' ), $skipped );
        }

        return [
            'imported' => $imported,
            'message' => $message,
        ];
    }

    /**
     * Create order from BigCommerce data
     */
    private static function create_order_from_bigcommerce( $external_order ) {
        // Check if order already exists
        $existing = get_posts( [
            'post_type' => 'shop_order',
            'meta_key' => '_vss_external_order_id',
            'meta_value' => 'bc_' . $external_order['id'],
            'posts_per_page' => 1,
        ] );

        if ( ! empty( $existing ) ) {
            return false; // Order already imported
        }

        // Map BigCommerce status to WooCommerce
        $status_map = [
            'Pending' => 'pending',
            'Awaiting Payment' => 'pending',
            'Awaiting Fulfillment' => 'processing',
            'Awaiting Shipment' => 'processing',
            'Partially Shipped' => 'processing',
            'Shipped' => 'shipped',
            'Completed' => 'completed',
            'Cancelled' => 'cancelled',
            'Declined' => 'cancelled',
            'Refunded' => 'refunded',
        ];

        $status = isset( $status_map[ $external_order['status'] ] ) ? $status_map[ $external_order['status'] ] : 'pending';

        // Create new order
        $order = wc_create_order( [
            'status' => $status,
            'customer_id' => 0, // Guest order
        ] );

        if ( ! $order ) {
            return false;
        }

        // Set billing details
        $billing = $external_order['billing_address'];
        $order->set_billing_first_name( $billing['first_name'] );
        $order->set_billing_last_name( $billing['last_name'] );
        $order->set_billing_email( $billing['email'] );
        $order->set_billing_phone( $billing['phone'] );
        $order->set_billing_address_1( $billing['street_1'] );
        $order->set_billing_address_2( $billing['street_2'] );
        $order->set_billing_city( $billing['city'] );
        $order->set_billing_state( $billing['state'] );
        $order->set_billing_postcode( $billing['zip'] );
        $order->set_billing_country( $billing['country_iso2'] );

        // Get products for this order
        $store_hash = get_option( 'vss_bc_store_hash' );
        $access_token = get_option( 'vss_bc_access_token' );

        $products_response = wp_remote_get(
            'https://api.bigcommerce.com/stores/' . $store_hash . '/v2/orders/' . $external_order['id'] . '/products',
            [
                'headers' => [
                    'X-Auth-Token' => $access_token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 30,
            ]
        );

        if ( ! is_wp_error( $products_response ) ) {
            $products = json_decode( wp_remote_retrieve_body( $products_response ), true );

            foreach ( $products as $item ) {
                // Try to find matching product by SKU
                $product_id = wc_get_product_id_by_sku( $item['sku'] );

                if ( ! $product_id ) {
                    // Create a simple product if not found
                    $product = new WC_Product_Simple();
                    $product->set_name( $item['name'] );
                    $product->set_sku( $item['sku'] );
                    $product->set_price( $item['price_ex_tax'] );
                    $product->set_regular_price( $item['price_ex_tax'] );
                    $product->save();
                    $product_id = $product->get_id();
                }

                $order->add_product( wc_get_product( $product_id ), $item['quantity'] );
            }
        }

        // Set totals
        $order->set_total( $external_order['total_inc_tax'] );

        // Save order
        $order->save();

        // Add meta data
        update_post_meta( $order->get_id(), '_vss_external_order_id', 'bc_' . $external_order['id'] );
        update_post_meta( $order->get_id(), '_vss_external_source', 'bigcommerce' );
        update_post_meta( $order->get_id(), '_vss_external_order_number', $external_order['id'] );

        // Assign to default vendor
        $default_vendor = get_option( 'vss_default_vendor_id' );
        if ( $default_vendor ) {
            update_post_meta( $order->get_id(), '_vss_vendor_user_id', $default_vendor );
        }

        // Add order note
        $order->add_order_note( sprintf(
            __( 'Order imported from BigCommerce. Original order #%s', 'vss' ),
            $external_order['id']
        ) );

        return true;
    }

    /**
     * Scheduled import
     */
    public static function scheduled_import() {
        $wc_result = self::import_woocommerce_orders();
        $bc_result = self::import_bigcommerce_orders();

        $total = $wc_result['imported'] + $bc_result['imported'];

        self::log_import( 'scheduled', $total, 'success' );
    }

    /**
     * Log import
     */
    private static function log_import( $type, $count, $status ) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'vss_import_log',
            [
                'import_date' => current_time( 'mysql' ),
                'platform' => $type,
                'orders_imported' => $count,
                'status' => $status,
            ]
        );
    }

    /**
     * Create database table on activation
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}vss_import_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            import_date datetime DEFAULT NULL,
            platform varchar(50) DEFAULT NULL,
            orders_imported int(11) DEFAULT 0,
            status varchar(50) DEFAULT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
}