<?php
/**
 * VSS External Orders Integration
 *
 * Handles importing orders from external WooCommerce, BigCommerce, and Shopify stores
 * and syncs tracking information back to the source store.
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

        // Add tracking sync hook
        add_action( 'woocommerce_order_status_shipped', [ self::class, 'sync_tracking_to_external_store' ], 10, 1 );

        // Filter to display original order numbers
        add_filter( 'woocommerce_order_number', [ self::class, 'filter_order_number' ], 10, 2 );

        // Disable emails for imported orders
        add_action( 'woocommerce_before_order_object_save', [ self::class, 'disable_emails_for_imported_orders' ], 10, 2 );
    }

    /**
     * Filter order number to show original external order number
     */
    public static function filter_order_number( $order_number, $order ) {
        $custom_order_number = get_post_meta( $order->get_id(), '_order_number', true );
        if ( $custom_order_number ) {
            return $custom_order_number;
        }
        return $order_number;
    }

    /**
     * Disable emails for imported orders
     */
    public static function disable_emails_for_imported_orders( $order, $data_store ) {
        if ( $order->get_meta( '_vss_imported_order' ) === 'yes' ) {
            add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
            add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
            add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
            add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
            add_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );
        }
    }

    /**
     * Sync tracking information back to the external store when an order is marked as shipped.
     *
     * @param int $order_id The ID of the order.
     */
    public static function sync_tracking_to_external_store( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $source = get_post_meta( $order_id, '_vss_external_source', true );
        if ( ! $source ) {
            return; // Not an external order
        }

        $tracking_number = get_post_meta( $order_id, '_vss_tracking_number', true );
        $tracking_carrier = get_post_meta( $order_id, '_vss_tracking_carrier', true );

        if ( ! $tracking_number ) {
            $order->add_order_note( __( 'Tracking sync failed: No tracking number found.', 'vss' ) );
            return;
        }

        switch ( $source ) {
            case 'shopify':
                self::sync_tracking_to_shopify( $order, $tracking_number, $tracking_carrier );
                break;
            case 'bigcommerce':
                self::sync_tracking_to_bigcommerce( $order, $tracking_number, $tracking_carrier );
                break;
            case 'woocommerce':
                self::sync_tracking_to_woocommerce( $order, $tracking_number, $tracking_carrier );
                break;
        }
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

        // Shopify API settings
        register_setting( 'vss_external_orders', 'vss_shopify_store_name' );
        register_setting( 'vss_external_orders', 'vss_shopify_access_token' );

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

                <h2><?php esc_html_e( 'Shopify Store Settings', 'vss' ); ?></h2>
                <div class="notice notice-info inline">
                    <p><?php esc_html_e( 'Note: Only orders from July 28, 2025 onwards will be imported from Shopify.', 'vss' ); ?></p>
                </div>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Store Name', 'vss' ); ?></th>
                        <td>
                            <input type="text" name="vss_shopify_store_name" value="<?php echo esc_attr( get_option( 'vss_shopify_store_name', 'qstomize' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Your store name (e.g., \'your-store\' from your-store.myshopify.com)', 'vss' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Access Token', 'vss' ); ?></th>
                        <td>
                            <input type="password" name="vss_shopify_access_token" value="<?php echo esc_attr( get_option( 'vss_shopify_access_token', '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' ) ); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e( 'Your Shopify private app access token.', 'vss' ); ?></p>
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
                <button type="button" class="button" id="vss-test-shopify-connection">
                    <?php esc_html_e( 'Test Shopify Connection', 'vss' ); ?>
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

            // Test Shopify connection
            $('#vss-test-shopify-connection').on('click', function() {
                var button = $(this);
                button.prop('disabled', true);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'vss_test_external_connection',
                        platform: 'shopify',
                        _ajax_nonce: '<?php echo wp_create_nonce( 'vss_external_orders' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Shopify connection successful!');
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
        } elseif ( $platform === 'shopify' ) {
            $result = self::test_shopify_connection();
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
     * Test Shopify connection
     */
    private static function test_shopify_connection() {
        $store_name = get_option( 'vss_shopify_store_name' );
        $access_token = get_option( 'vss_shopify_access_token' );

        if ( ! $store_name || ! $access_token ) {
            return [
                'success' => false,
                'message' => __( 'Shopify API credentials not configured', 'vss' ),
            ];
        }

        $response = wp_remote_get(
            'https://' . $store_name . '.myshopify.com/admin/api/2023-10/orders.json?limit=1',
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $access_token,
                    'Content-Type' => 'application/json',
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

        // Import from Shopify
        $shopify_result = self::import_shopify_orders();
        $log[] = sprintf( __( 'Shopify: %s', 'vss' ), $shopify_result['message'] );
        $total_imported += $shopify_result['imported'];

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

        // Set minimum date to July 1, 2025
        $minimum_date = '2025-07-01T00:00:00Z';

        // Get last import date
        $last_import = get_option( 'vss_wc_last_import', false );
        $params = [
            'per_page' => 100,
            'orderby' => 'date',
            'order' => 'desc',
            'after' => $minimum_date, // Always use minimum date as the starting point
        ];

        // If we have a last import date that's after our minimum, use that instead
        if ( $last_import ) {
            $last_import_time = strtotime( $last_import );
            $minimum_time = strtotime( $minimum_date );

            if ( $last_import_time > $minimum_time ) {
                $params['after'] = date( 'c', $last_import_time );
            }
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

        // Disable customer emails for this order
        add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );

        // Create new order
        $order = wc_create_order( [
            'status' => $external_order['status'],
            'customer_id' => 0, // Guest order
        ] );

        if ( ! $order ) {
            return false;
        }

        // Set order number to match original
        update_post_meta( $order->get_id(), '_order_number', $external_order['number'] );

        // Safely set billing and shipping details
        $billing_details = is_array($external_order['billing']) ? $external_order['billing'] : [];
        $shipping_details = is_array($external_order['shipping']) ? $external_order['shipping'] : [];

        // Set billing details
        $order->set_billing_first_name( $billing_details['first_name'] ?? '' );
        $order->set_billing_last_name( $billing_details['last_name'] ?? '' );
        $order->set_billing_email( $billing_details['email'] ?? '' );
        $order->set_billing_phone( $billing_details['phone'] ?? '' );
        $order->set_billing_address_1( $billing_details['address_1'] ?? '' );
        $order->set_billing_address_2( $billing_details['address_2'] ?? '' );
        $order->set_billing_city( $billing_details['city'] ?? '' );
        $order->set_billing_state( $billing_details['state'] ?? '' );
        $order->set_billing_postcode( $billing_details['postcode'] ?? '' );
        $order->set_billing_country( $billing_details['country'] ?? '' );

        // Set shipping details
        $order->set_shipping_first_name( $shipping_details['first_name'] ?? '' );
        $order->set_shipping_last_name( $shipping_details['last_name'] ?? '' );
        $order->set_shipping_address_1( $shipping_details['address_1'] ?? '' );
        $order->set_shipping_address_2( $shipping_details['address_2'] ?? '' );
        $order->set_shipping_city( $shipping_details['city'] ?? '' );
        $order->set_shipping_state( $shipping_details['state'] ?? '' );
        $order->set_shipping_postcode( $shipping_details['postcode'] ?? '' );
        $order->set_shipping_country( $shipping_details['country'] ?? '' );

        // Set customer note if exists
        if ( ! empty( $external_order['customer_note'] ) ) {
            $order->set_customer_note( $external_order['customer_note'] );
        }

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

        // Add shipping if exists
        if ( ! empty( $external_order['shipping_lines'] ) ) {
            foreach ( $external_order['shipping_lines'] as $shipping ) {
                $item = new WC_Order_Item_Shipping();
                $item->set_method_title( $shipping['method_title'] );
                $item->set_method_id( $shipping['method_id'] );
                $item->set_total( $shipping['total'] );
                $order->add_item( $item );
            }
        }

        // Add fees if exists
        if ( ! empty( $external_order['fee_lines'] ) ) {
            foreach ( $external_order['fee_lines'] as $fee ) {
                $item = new WC_Order_Item_Fee();
                $item->set_name( $fee['name'] );
                $item->set_total( $fee['total'] );
                $order->add_item( $item );
            }
        }

        // Set payment method
        if ( ! empty( $external_order['payment_method'] ) ) {
            $order->set_payment_method( $external_order['payment_method'] );
            $order->set_payment_method_title( $external_order['payment_method_title'] );
        }

        // Set totals
        $order->set_discount_total( $external_order['discount_total'] );
        $order->set_discount_tax( $external_order['discount_tax'] );
        $order->set_shipping_total( $external_order['shipping_total'] );
        $order->set_shipping_tax( $external_order['shipping_tax'] );
        $order->set_cart_tax( $external_order['cart_tax'] );
        $order->set_total( $external_order['total'] );

        // Set order date
        $order->set_date_created( $external_order['date_created'] );

        // Save order
        $order->save();

        // Add meta data
        update_post_meta( $order->get_id(), '_vss_external_order_id', 'wc_' . $external_order['id'] );
        update_post_meta( $order->get_id(), '_vss_external_source', 'woocommerce' );
        update_post_meta( $order->get_id(), '_vss_external_order_number', $external_order['number'] );
        update_post_meta( $order->get_id(), '_vss_imported_order', 'yes' );

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

        // Re-enable customer emails
        remove_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );

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

        // Disable customer emails for this order
        add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );

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

        // Set order number to match original
        update_post_meta( $order->get_id(), '_order_number', $external_order['id'] );

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

        // Set shipping details if exists
        if ( ! empty( $external_order['shipping_addresses'] ) && isset( $external_order['shipping_addresses'][0] ) ) {
            $shipping = $external_order['shipping_addresses'][0];
            $order->set_shipping_first_name( $shipping['first_name'] );
            $order->set_shipping_last_name( $shipping['last_name'] );
            $order->set_shipping_address_1( $shipping['street_1'] );
            $order->set_shipping_address_2( $shipping['street_2'] );
            $order->set_shipping_city( $shipping['city'] );
            $order->set_shipping_state( $shipping['state'] );
            $order->set_shipping_postcode( $shipping['zip'] );
            $order->set_shipping_country( $shipping['country_iso2'] );
        }

        // Set customer note if exists
        if ( ! empty( $external_order['customer_message'] ) ) {
            $order->set_customer_note( $external_order['customer_message'] );
        }

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

        // Add shipping if exists
        if ( ! empty( $external_order['shipping_cost_ex_tax'] ) && $external_order['shipping_cost_ex_tax'] > 0 ) {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title( 'Shipping' );
            $item->set_method_id( 'flat_rate' );
            $item->set_total( $external_order['shipping_cost_ex_tax'] );
            $order->add_item( $item );
        }

        // Set payment method
        $order->set_payment_method( $external_order['payment_method'] );

        // Set totals
        $order->set_discount_total( $external_order['discount_amount'] );
        $order->set_shipping_total( $external_order['shipping_cost_ex_tax'] );
        $order->set_shipping_tax( $external_order['shipping_cost_tax'] );
        $order->set_total( $external_order['total_inc_tax'] );

        // Set order date
        $order->set_date_created( $external_order['date_created'] );

        // Save order
        $order->save();

        // Add meta data
        update_post_meta( $order->get_id(), '_vss_external_order_id', 'bc_' . $external_order['id'] );
        update_post_meta( $order->get_id(), '_vss_external_source', 'bigcommerce' );
        update_post_meta( $order->get_id(), '_vss_external_order_number', $external_order['id'] );
        update_post_meta( $order->get_id(), '_vss_imported_order', 'yes' );

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

        // Re-enable customer emails
        remove_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );

        return true;
    }

    /**
     * Import Shopify orders
     */
    private static function import_shopify_orders() {
        $store_name = get_option( 'vss_shopify_store_name' );
        $access_token = get_option( 'vss_shopify_access_token' );

        if ( ! $store_name || ! $access_token ) {
            return [
                'imported' => 0,
                'message' => __( 'API credentials not configured', 'vss' ),
            ];
        }

        // Set minimum date to July 28, 2025
        $minimum_date = '2025-07-28T00:00:00Z';

        // Get last import date
        $last_import = get_option( 'vss_shopify_last_import', false );
        $params = [
            'limit' => 250,
            'status' => 'any',
            'created_at_min' => $minimum_date,
        ];

        // If we have a last import date that's after our minimum, use that instead
        if ( $last_import ) {
            $last_import_time = strtotime( $last_import );
            $minimum_time = strtotime( $minimum_date );

            if ( $last_import_time > $minimum_time ) {
                $params['created_at_min'] = date( 'c', $last_import_time );
            }
        }

        $response = wp_remote_get(
            add_query_arg( $params, 'https://' . $store_name . '.myshopify.com/admin/api/2023-10/orders.json' ),
            [
                'headers' => [
                    'X-Shopify-Access-Token' => $access_token,
                    'Content-Type' => 'application/json',
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

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $orders = isset( $body['orders'] ) ? $body['orders'] : [];

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
            $order_date = strtotime( $external_order['created_at'] );
            $minimum_time = strtotime( '2025-07-28' );

            if ( $order_date < $minimum_time ) {
                $skipped++;
                continue; // Skip orders before July 28, 2025
            }

            if ( self::create_order_from_shopify( $external_order ) ) {
                $imported++;
            }
        }

        update_option( 'vss_shopify_last_import', current_time( 'mysql' ) );

        $message = sprintf( __( '%d orders imported', 'vss' ), $imported );
        if ( $skipped > 0 ) {
            $message .= sprintf( __( ' (%d orders skipped - before July 28, 2025)', 'vss' ), $skipped );
        }

        return [
            'imported' => $imported,
            'message' => $message,
        ];
    }

    /**
     * Create order from Shopify data
     */
    private static function create_order_from_shopify( $external_order ) {
        // Check if order already exists
        $existing = get_posts( [
            'post_type' => 'shop_order',
            'meta_key' => '_vss_external_order_id',
            'meta_value' => 'shopify_' . $external_order['id'],
            'posts_per_page' => 1,
        ] );

        if ( ! empty( $existing ) ) {
            return false; // Order already imported
        }

        // Disable customer emails for this order
        add_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
        add_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );

        // Map Shopify financial status to WooCommerce
        $status_map = [
            'pending' => 'pending',
            'authorized' => 'processing',
            'partially_paid' => 'processing',
            'paid' => 'processing',
            'partially_refunded' => 'refunded',
            'refunded' => 'refunded',
            'voided' => 'cancelled',
        ];

        $status = isset( $status_map[ $external_order['financial_status'] ] ) ? $status_map[ $external_order['financial_status'] ] : 'pending';

        // Create new order
        $order = wc_create_order( [
            'status' => $status,
            'customer_id' => 0, // Guest order
        ] );

        if ( ! $order ) {
            return false;
        }

        // Set order number to match original (Shopify uses 'name' field for order number)
        update_post_meta( $order->get_id(), '_order_number', $external_order['name'] );

        // Set billing details
        $billing = isset( $external_order['billing_address'] ) ? $external_order['billing_address'] : $external_order['customer'];
        $order->set_billing_first_name( $billing['first_name'] );
        $order->set_billing_last_name( $billing['last_name'] );
        $order->set_billing_email( $external_order['email'] );
        $order->set_billing_phone( $billing['phone'] );
        $order->set_billing_address_1( $billing['address1'] );
        $order->set_billing_address_2( $billing['address2'] );
        $order->set_billing_city( $billing['city'] );
        $order->set_billing_state( $billing['province_code'] );
        $order->set_billing_postcode( $billing['zip'] );
        $order->set_billing_country( $billing['country_code'] );

        // Set shipping details
        if ( isset( $external_order['shipping_address'] ) ) {
            $shipping = $external_order['shipping_address'];
            $order->set_shipping_first_name( $shipping['first_name'] );
            $order->set_shipping_last_name( $shipping['last_name'] );
            $order->set_shipping_address_1( $shipping['address1'] );
            $order->set_shipping_address_2( $shipping['address2'] );
            $order->set_shipping_city( $shipping['city'] );
            $order->set_shipping_state( $shipping['province_code'] );
            $order->set_shipping_postcode( $shipping['zip'] );
            $order->set_shipping_country( $shipping['country_code'] );
        }

        // Set customer note if exists
        if ( ! empty( $external_order['note'] ) ) {
            $order->set_customer_note( $external_order['note'] );
        }

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

        // Add shipping lines
        if ( ! empty( $external_order['shipping_lines'] ) ) {
            foreach ( $external_order['shipping_lines'] as $shipping ) {
                $item = new WC_Order_Item_Shipping();
                $item->set_method_title( $shipping['title'] );
                $item->set_method_id( $shipping['code'] );
                $item->set_total( $shipping['price'] );
                $order->add_item( $item );
            }
        }

        // Add discount lines
        if ( ! empty( $external_order['discount_codes'] ) ) {
            foreach ( $external_order['discount_codes'] as $discount ) {
                $order->apply_coupon( $discount['code'] );
            }
        }

        // Set payment gateway
        if ( ! empty( $external_order['gateway'] ) ) {
            $order->set_payment_method( $external_order['gateway'] );
            $order->set_payment_method_title( $external_order['gateway'] );
        }

        // Set totals
        $order->set_discount_total( $external_order['total_discounts'] );
        $order->set_shipping_total( $external_order['total_shipping_price_set']['shop_money']['amount'] ?? 0 );
        $order->set_cart_tax( $external_order['total_tax'] );
        $order->set_total( $external_order['total_price'] );

        // Set order date
        $order->set_date_created( $external_order['created_at'] );

        // Save order
        $order->save();

        // Add meta data
        update_post_meta( $order->get_id(), '_vss_external_order_id', 'shopify_' . $external_order['id'] );
        update_post_meta( $order->get_id(), '_vss_external_source', 'shopify' );
        update_post_meta( $order->get_id(), '_vss_external_order_number', $external_order['name'] );
        update_post_meta( $order->get_id(), '_vss_imported_order', 'yes' );

        // Assign to default vendor
        $default_vendor = get_option( 'vss_default_vendor_id' );
        if ( $default_vendor ) {
            update_post_meta( $order->get_id(), '_vss_vendor_user_id', $default_vendor );
        }

        // Add order note
        $order->add_order_note( sprintf(
            __( 'Order imported from Shopify. Original order %s', 'vss' ),
            $external_order['name']
        ) );

        // Re-enable customer emails
        remove_filter( 'woocommerce_email_enabled_new_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_completed_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_on_hold_order', '__return_false' );
        remove_filter( 'woocommerce_email_enabled_customer_note', '__return_false' );

        return true;
    }

    /**
     * Sync tracking information to Shopify.
     *
     * @param WC_Order $order           The order object.
     * @param string   $tracking_number The tracking number.
     * @param string   $tracking_carrier The tracking carrier.
     */
    private static function sync_tracking_to_shopify( $order, $tracking_number, $tracking_carrier ) {
        $store_name = get_option( 'vss_shopify_store_name', 'qstomize' );
        $access_token = get_option( 'vss_shopify_access_token', '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' );
        $external_order_id = get_post_meta( $order->get_id(), '_vss_external_order_id', true );

        // Extract the numeric ID from the stored value
        $external_order_id = str_replace( 'shopify_', '', $external_order_id );

        if ( ! $store_name || ! $access_token || ! $external_order_id ) {
            $order->add_order_note( __( 'Shopify tracking sync failed: Missing credentials or external order ID.', 'vss' ) );
            return;
        }

        // 1. Get fulfillments for the order
        $api_url = "https://{$store_name}.myshopify.com/admin/api/2023-10/orders/{$external_order_id}/fulfillments.json";
        $response = wp_remote_get( $api_url, [
            'headers' => [ 'X-Shopify-Access-Token' => $access_token ],
        ] );

        $fulfillments = json_decode( wp_remote_retrieve_body( $response ), true );
        $fulfillment_id = ! empty( $fulfillments['fulfillments'] ) ? $fulfillments['fulfillments'][0]['id'] : null;

        // 2. If no fulfillment exists, create one
        if ( ! $fulfillment_id ) {
            $fulfillment_data = [
                'fulfillment' => [
                    'location_id' => null, // Shopify will use the default location
                    'tracking_number' => $tracking_number,
                    'tracking_company' => $tracking_carrier,
                    'notify_customer' => true,
                ],
            ];

            $create_fulfillment_url = "https://{$store_name}.myshopify.com/admin/api/2023-10/orders/{$external_order_id}/fulfillments.json";
            $response = wp_remote_post( $create_fulfillment_url, [
                'headers' => [
                    'X-Shopify-Access-Token' => $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode( $fulfillment_data ),
            ] );

            $fulfillment_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $fulfillment_id = $fulfillment_body['fulfillment']['id'] ?? null;
        }

        // 3. Update the fulfillment with tracking info
        if ( $fulfillment_id ) {
            $update_url = "https://{$store_name}.myshopify.com/admin/api/2023-10/fulfillments/{$fulfillment_id}.json";
            $update_data = [
                'fulfillment' => [
                    'id' => $fulfillment_id,
                    'tracking_info' => [
                        'number' => $tracking_number,
                        'company' => $tracking_carrier,
                    ],
                    'notify_customer' => true,
                ],
            ];

            $response = wp_remote_request( $update_url, [
                'method' => 'PUT',
                'headers' => [
                    'X-Shopify-Access-Token' => $access_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode( $update_data ),
            ] );

            if ( ! is_wp_error( $response ) && in_array( wp_remote_retrieve_response_code( $response ), [ 200, 201 ] ) ) {
                $order->add_order_note( sprintf( __( 'Tracking synced to Shopify. Fulfillment ID: %s', 'vss' ), $fulfillment_id ) );
            } else {
                $error_message = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
                $order->add_order_note( sprintf( __( 'Shopify tracking sync failed: %s', 'vss' ), $error_message ) );
            }
        } else {
            $order->add_order_note( __( 'Shopify tracking sync failed: Could not create or find fulfillment.', 'vss' ) );
        }
    }

    /**
     * Sync tracking information to BigCommerce.
     *
     * @param WC_Order $order           The order object.
     * @param string   $tracking_number The tracking number.
     * @param string   $tracking_carrier The tracking carrier.
     */
    private static function sync_tracking_to_bigcommerce( $order, $tracking_number, $tracking_carrier ) {
        $store_hash = get_option( 'vss_bc_store_hash' );
        $access_token = get_option( 'vss_bc_access_token' );
        $external_order_id = get_post_meta( $order->get_id(), '_vss_external_order_id', true );

        // Extract the numeric ID from the stored value
        $external_order_id = str_replace( 'bc_', '', $external_order_id );

        if ( ! $store_hash || ! $access_token || ! $external_order_id ) {
            $order->add_order_note( __( 'BigCommerce tracking sync failed: Missing credentials or external order ID.', 'vss' ) );
            return;
        }

        $shipment_data = [
            'tracking_number' => $tracking_number,
            'shipping_provider' => $tracking_carrier,
            'order_address_id' => null, // Will be determined by BigCommerce
            'items' => [], // Add all items to the shipment
        ];

        $api_url = "https://api.bigcommerce.com/stores/{$store_hash}/v2/orders/{$external_order_id}/shipments";
        $response = wp_remote_post( $api_url, [
            'headers' => [
                'X-Auth-Token' => $access_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => json_encode( $shipment_data ),
        ] );

        if ( ! is_wp_error( $response ) && in_array( wp_remote_retrieve_response_code( $response ), [ 200, 201 ] ) ) {
            $order->add_order_note( __( 'Tracking synced to BigCommerce.', 'vss' ) );
        } else {
            $error_message = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
            $order->add_order_note( sprintf( __( 'BigCommerce tracking sync failed: %s', 'vss' ), $error_message ) );
        }
    }

    /**
     * Sync tracking information to an external WooCommerce store.
     *
     * @param WC_Order $order           The order object.
     * @param string   $tracking_number The tracking number.
     * @param string   $tracking_carrier The tracking carrier.
     */
    private static function sync_tracking_to_woocommerce( $order, $tracking_number, $tracking_carrier ) {
        $url = get_option( 'vss_wc_api_url' );
        $consumer_key = get_option( 'vss_wc_consumer_key' );
        $consumer_secret = get_option( 'vss_wc_consumer_secret' );
        $external_order_id = get_post_meta( $order->get_id(), '_vss_external_order_id', true );

        // Extract the numeric ID from the stored value
        $external_order_id = str_replace( 'wc_', '', $external_order_id );

        if ( ! $url || ! $consumer_key || ! $consumer_secret || ! $external_order_id ) {
            $order->add_order_note( __( 'WooCommerce tracking sync failed: Missing credentials or external order ID.', 'vss' ) );
            return;
        }

        // Assumes the destination store uses the "WooCommerce Shipment Tracking" plugin format.
        $tracking_data = [
            'meta_data' => [
                [
                    'key' => '_wc_shipment_tracking_items',
                    'value' => [
                        [
                            'tracking_provider' => $tracking_carrier,
                            'tracking_number' => $tracking_number,
                            'date_shipped' => time(),
                        ],
                    ],
                ],
            ],
        ];

        $api_url = "{$url}/wp-json/wc/v3/orders/{$external_order_id}";
        $response = wp_remote_request( $api_url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "{$consumer_key}:{$consumer_secret}" ),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode( $tracking_data ),
        ] );

        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $order->add_order_note( __( 'Tracking synced to external WooCommerce store.', 'vss' ) );
        } else {
            $error_message = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
            $order->add_order_note( sprintf( __( 'WooCommerce tracking sync failed: %s', 'vss' ), $error_message ) );
        }
    }

    /**
     * Scheduled import
     */
    public static function scheduled_import() {
        $wc_result = self::import_woocommerce_orders();
        $bc_result = self::import_bigcommerce_orders();
        $shopify_result = self::import_shopify_orders();

        $total = $wc_result['imported'] + $bc_result['imported'] + $shopify_result['imported'];

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