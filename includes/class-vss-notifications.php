<?php
/**
 * VSS Customer Class
 *
 * Handles customer-facing functionality including approval workflows
 *
 * @package VendorOrderManager
 * @since 7.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VSS_Customer {

    /**
     * Initialize customer functionality
     */
    public static function init() {
        // Shortcodes
        add_shortcode( 'vss_customer_approval', [ self::class, 'render_customer_approval_shortcode' ] );
        add_shortcode( 'vss_track_order', [ self::class, 'render_track_order_shortcode' ] );

        // AJAX handlers
        add_action( 'wp_ajax_vss_approve_mockup', [ self::class, 'ajax_approve_mockup' ] );
        add_action( 'wp_ajax_nopriv_vss_approve_mockup', [ self::class, 'ajax_approve_mockup' ] );
        add_action( 'wp_ajax_vss_approve_production', [ self::class, 'ajax_approve_production' ] );
        add_action( 'wp_ajax_nopriv_vss_approve_production', [ self::class, 'ajax_approve_production' ] );
        add_action( 'wp_ajax_vss_add_customer_note', [ self::class, 'ajax_add_customer_note' ] );
        add_action( 'wp_ajax_nopriv_vss_add_customer_note', [ self::class, 'ajax_add_customer_note' ] );

        // Form handlers
        add_action( 'init', [ self::class, 'handle_approval_forms' ] );
    }

    /**
     * Render customer approval shortcode
     */
    public static function render_customer_approval_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'show_title' => 'yes',
        ], $atts, 'vss_customer_approval' );

        // Get order ID and key from URL
        $order_id = isset( $_GET['order_id'] ) ? intval( $_GET['order_id'] ) : 0;
        $order_key = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';

        if ( ! $order_id || ! $order_key ) {
            return '<div class="vss-error">' . esc_html__( 'Invalid approval link.', 'vss' ) . '</div>';
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $order_key ) {
            return '<div class="vss-error">' . esc_html__( 'Invalid order or key.', 'vss' ) . '</div>';
        }

        ob_start();
        ?>
        <div class="vss-customer-approval-container">
            <?php if ( $atts['show_title'] === 'yes' ) : ?>
                <h2><?php esc_html_e( 'Order Approval', 'vss' ); ?></h2>
            <?php endif; ?>

            <?php self::render_approval_content( $order ); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render approval content
     */
    private static function render_approval_content( $order ) {
        $mockup_status = get_post_meta( $order->get_id(), '_vss_mockup_status', true );
        $production_status = get_post_meta( $order->get_id(), '_vss_production_file_status', true );

        ?>
        <div class="vss-order-info">
            <h3><?php esc_html_e( 'Order Information', 'vss' ); ?></h3>
            <p><strong><?php esc_html_e( 'Order Number:', 'vss' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></p>
            <p><strong><?php esc_html_e( 'Order Date:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) ); ?></p>
        </div>

        <?php if ( $mockup_status === 'pending' ) : ?>
            <?php self::render_mockup_approval( $order ); ?>
        <?php elseif ( $production_status === 'pending' ) : ?>
            <?php self::render_production_approval( $order ); ?>
        <?php else : ?>
            <div class="vss-no-pending-approvals">
                <p><?php esc_html_e( 'There are no items pending your approval at this time.', 'vss' ); ?></p>
                <?php if ( $mockup_status === 'approved' ) : ?>
                    <p><?php esc_html_e( 'Mockup approved on:', 'vss' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ), get_post_meta( $order->get_id(), '_vss_mockup_approved_at', true ) ) ); ?></p>
                <?php endif; ?>
                <?php if ( $production_status === 'approved' ) : ?>
                    <p><?php esc_html_e( 'Production files approved on:', 'vss' ); ?> <?php echo esc_html( date_i18n( get_option( 'date_format' ), get_post_meta( $order->get_id(), '_vss_production_file_approved_at', true ) ) ); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Render mockup approval section
     */
    private static function render_mockup_approval( $order ) {
        $mockup_files = get_post_meta( $order->get_id(), '_vss_mockup_files', true );

        if ( empty( $mockup_files ) ) {
            return;
        }
        ?>
        <div class="vss-mockup-approval">
            <h3><?php esc_html_e( 'Mockup Approval Required', 'vss' ); ?></h3>
            <p><?php esc_html_e( 'Please review the mockup below and approve or request changes.', 'vss' ); ?></p>

            <div class="vss-mockup-files">
                <?php foreach ( $mockup_files as $file_url ) : ?>
                    <?php
                    $file_type = wp_check_filetype( $file_url );
                    if ( strpos( $file_type['type'], 'image' ) !== false ) :
                    ?>
                        <div class="mockup-image">
                            <img src="<?php echo esc_url( $file_url ); ?>" alt="<?php esc_attr_e( 'Mockup', 'vss' ); ?>" />
                        </div>
                    <?php else : ?>
                        <div class="mockup-file">
                            <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="button">
                                <?php esc_html_e( 'Download File', 'vss' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <form method="post" class="vss-approval-form">
                <?php wp_nonce_field( 'vss_customer_approval', 'vss_nonce' ); ?>
                <input type="hidden" name="vss_action" value="approve_mockup" />
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />

                <div class="vss-approval-actions">
                    <button type="submit" name="approval_decision" value="approve" class="button button-primary">
                        <?php esc_html_e( 'Approve Mockup', 'vss' ); ?>
                    </button>
                    <button type="submit" name="approval_decision" value="disapprove" class="button button-secondary">
                        <?php esc_html_e( 'Request Changes', 'vss' ); ?>
                    </button>
                </div>

                <div class="vss-approval-notes">
                    <label for="customer_notes"><?php esc_html_e( 'Notes (optional):', 'vss' ); ?></label>
                    <textarea name="customer_notes" id="customer_notes" rows="4" placeholder="<?php esc_attr_e( 'Add any comments or change requests here...', 'vss' ); ?>"></textarea>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render production approval section
     */
    private static function render_production_approval( $order ) {
        $production_files = get_post_meta( $order->get_id(), '_vss_production_file_files', true );

        if ( empty( $production_files ) ) {
            return;
        }
        ?>
        <div class="vss-production-approval">
            <h3><?php esc_html_e( 'Production File Approval Required', 'vss' ); ?></h3>
            <p><?php esc_html_e( 'Please review the production files below and approve to proceed with production.', 'vss' ); ?></p>

            <div class="vss-production-files">
                <?php foreach ( $production_files as $file_url ) : ?>
                    <?php
                    $file_type = wp_check_filetype( $file_url );
                    if ( strpos( $file_type['type'], 'image' ) !== false ) :
                    ?>
                        <div class="production-image">
                            <img src="<?php echo esc_url( $file_url ); ?>" alt="<?php esc_attr_e( 'Production File', 'vss' ); ?>" />
                        </div>
                    <?php else : ?>
                        <div class="production-file">
                            <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="button">
                                <?php esc_html_e( 'Download File', 'vss' ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <form method="post" class="vss-approval-form">
                <?php wp_nonce_field( 'vss_customer_approval', 'vss_nonce' ); ?>
                <input type="hidden" name="vss_action" value="approve_production" />
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>" />

                <div class="vss-approval-actions">
                    <button type="submit" name="approval_decision" value="approve" class="button button-primary">
                        <?php esc_html_e( 'Approve for Production', 'vss' ); ?>
                    </button>
                    <button type="submit" name="approval_decision" value="disapprove" class="button button-secondary">
                        <?php esc_html_e( 'Request Changes', 'vss' ); ?>
                    </button>
                </div>

                <div class="vss-approval-notes">
                    <label for="customer_notes"><?php esc_html_e( 'Notes (optional):', 'vss' ); ?></label>
                    <textarea name="customer_notes" id="customer_notes" rows="4" placeholder="<?php esc_attr_e( 'Add any comments or change requests here...', 'vss' ); ?>"></textarea>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle approval forms
     */
    public static function handle_approval_forms() {
        if ( ! isset( $_POST['vss_action'] ) || ! isset( $_POST['vss_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['vss_nonce'], 'vss_customer_approval' ) ) {
            return;
        }

        $action = sanitize_text_field( $_POST['vss_action'] );
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $decision = isset( $_POST['approval_decision'] ) ? sanitize_text_field( $_POST['approval_decision'] ) : '';
        $notes = isset( $_POST['customer_notes'] ) ? sanitize_textarea_field( $_POST['customer_notes'] ) : '';

        if ( ! $order_id || ! $decision ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( $action === 'approve_mockup' ) {
            self::process_mockup_decision( $order, $decision, $notes );
        } elseif ( $action === 'approve_production' ) {
            self::process_production_decision( $order, $decision, $notes );
        }
    }

    /**
     * Process mockup decision
     */
    private static function process_mockup_decision( $order, $decision, $notes ) {
        $status = $decision === 'approve' ? 'approved' : 'disapproved';

        update_post_meta( $order->get_id(), '_vss_mockup_status', $status );
        update_post_meta( $order->get_id(), '_vss_mockup_' . $status . '_at', current_time( 'timestamp' ) );

        if ( ! empty( $notes ) ) {
            update_post_meta( $order->get_id(), '_vss_mockup_customer_notes', $notes );
        }

        // Add order note
        $note_text = $decision === 'approve'
            ? __( 'Customer approved the mockup.', 'vss' )
            : __( 'Customer requested changes to the mockup.', 'vss' );

        if ( ! empty( $notes ) ) {
            $note_text .= ' ' . sprintf( __( 'Customer notes: %s', 'vss' ), $notes );
        }

        $order->add_order_note( $note_text );

        // Send notification to vendor
        do_action( 'vss_mockup_decision', $order, $status, $notes );

        // Redirect with success message
        $redirect_url = add_query_arg( [
            'vss_approval' => 'success',
            'type' => 'mockup',
            'decision' => $decision,
        ], get_permalink() );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Process production decision
     */
    private static function process_production_decision( $order, $decision, $notes ) {
        $status = $decision === 'approve' ? 'approved' : 'disapproved';

        update_post_meta( $order->get_id(), '_vss_production_file_status', $status );
        update_post_meta( $order->get_id(), '_vss_production_file_' . $status . '_at', current_time( 'timestamp' ) );

        if ( ! empty( $notes ) ) {
            update_post_meta( $order->get_id(), '_vss_production_file_customer_notes', $notes );
        }

        // Add order note
        $note_text = $decision === 'approve'
            ? __( 'Customer approved the production files.', 'vss' )
            : __( 'Customer requested changes to the production files.', 'vss' );

        if ( ! empty( $notes ) ) {
            $note_text .= ' ' . sprintf( __( 'Customer notes: %s', 'vss' ), $notes );
        }

        $order->add_order_note( $note_text );

        // Send notification to vendor
        do_action( 'vss_production_decision', $order, $status, $notes );

        // Redirect with success message
        $redirect_url = add_query_arg( [
            'vss_approval' => 'success',
            'type' => 'production',
            'decision' => $decision,
        ], get_permalink() );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Render track order shortcode
     */
    public static function render_track_order_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'show_form' => 'yes',
        ], $atts, 'vss_track_order' );

        ob_start();
        ?>
        <div class="vss-track-order-container">
            <h2><?php esc_html_e( 'Track Your Order', 'vss' ); ?></h2>

            <?php if ( $atts['show_form'] === 'yes' ) : ?>
                <form class="vss-track-order-form" id="vss-track-order-form">
                    <div class="form-field">
                        <label for="order_number"><?php esc_html_e( 'Order Number:', 'vss' ); ?></label>
                        <input type="text"
                               id="order_number"
                               name="order_number"
                               placeholder="<?php esc_attr_e( 'Enter your order number', 'vss' ); ?>"
                               required />
                    </div>

                    <div class="form-field">
                        <label for="order_email"><?php esc_html_e( 'Email Address:', 'vss' ); ?></label>
                        <input type="email"
                               id="order_email"
                               name="order_email"
                               placeholder="<?php esc_attr_e( 'Enter your email address', 'vss' ); ?>"
                               required />
                    </div>

                    <div class="form-field">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Track Order', 'vss' ); ?>
                        </button>
                    </div>
                </form>

                <div id="vss-track-order-results" style="display: none;"></div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#vss-track-order-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $results = $('#vss-track-order-results');
                var $button = $form.find('button[type="submit"]');

                $button.prop('disabled', true).text('<?php esc_js_e( 'Searching...', 'vss' ); ?>');

                $.ajax({
                    url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                    type: 'POST',
                    data: {
                        action: 'vss_track_order',
                        order_id: $('#order_number').val(),
                        email: $('#order_email').val(),
                        nonce: '<?php echo wp_create_nonce( 'vss_track_order' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var html = '<div class="vss-order-tracking-results">';
                            html += '<h3><?php esc_js_e( 'Order Found', 'vss' ); ?></h3>';
                            html += '<p><strong><?php esc_js_e( 'Order Number:', 'vss' ); ?></strong> #' + data.order_number + '</p>';
                            html += '<p><strong><?php esc_js_e( 'Status:', 'vss' ); ?></strong> ' + data.status_label + '</p>';
                            html += '<p><strong><?php esc_js_e( 'Order Date:', 'vss' ); ?></strong> ' + data.date_created + '</p>';

                            if (data.estimated_ship_date) {
                                html += '<p><strong><?php esc_js_e( 'Estimated Ship Date:', 'vss' ); ?></strong> ' + data.estimated_ship_date + '</p>';
                            }

                            if (data.tracking) {
                                html += '<p><strong><?php esc_js_e( 'Tracking Number:', 'vss' ); ?></strong> ' + data.tracking.number + '</p>';
                                html += '<p><strong><?php esc_js_e( 'Carrier:', 'vss' ); ?></strong> ' + data.tracking.carrier + '</p>';
                            }

                            html += '</div>';
                            $results.html(html).show();
                        } else {
                            $results.html('<div class="vss-error">' + response.data.message + '</div>').show();
                        }
                    },
                    error: function() {
                        $results.html('<div class="vss-error"><?php esc_js_e( 'An error occurred. Please try again.', 'vss' ); ?></div>').show();
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php esc_js_e( 'Track Order', 'vss' ); ?>');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX approve mockup handler
     */
    public static function ajax_approve_mockup() {
        // Implementation would go here
        wp_send_json_error( [ 'message' => __( 'Not implemented', 'vss' ) ] );
    }

    /**
     * AJAX approve production handler
     */
    public static function ajax_approve_production() {
        // Implementation would go here
        wp_send_json_error( [ 'message' => __( 'Not implemented', 'vss' ) ] );
    }

    /**
     * AJAX add customer note handler
     */
    public static function ajax_add_customer_note() {
        // Implementation would go here
        wp_send_json_error( [ 'message' => __( 'Not implemented', 'vss' ) ] );
    }
}