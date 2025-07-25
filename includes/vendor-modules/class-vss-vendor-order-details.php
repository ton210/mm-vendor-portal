<?php
/**
 * VSS Vendor Order Details Module - Enhanced Version
 *
 * Order detail views and sections with tracking and approval functionality
 *
 * @package VendorOrderManager
 * @subpackage Modules
 * @since 7.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait for Order Details functionality
 */
trait VSS_Vendor_Order_Details {

    /**
     * Render order overview
     */
    private static function render_order_overview( $order ) {
        ?>
        <div class="vss-order-overview">
            <div class="overview-grid">
                <div class="overview-section">
                    <h4><?php esc_html_e( 'Order Information', 'vss' ); ?></h4>
                    <p><strong><?php esc_html_e( 'Order Number:', 'vss' ); ?></strong> #<?php echo esc_html( $order->get_order_number() ); ?></p>
                    <p><strong><?php esc_html_e( 'Date Created:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
                    <p><strong><?php esc_html_e( 'Status:', 'vss' ); ?></strong> <?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></p>
                    <?php
                    $ship_date = get_post_meta( $order->get_id(), '_vss_estimated_ship_date', true );
                    if ( $ship_date ) :
                    ?>
                    <p><strong><?php esc_html_e( 'Estimated Ship Date:', 'vss' ); ?></strong> <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ship_date ) ) ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="overview-section">
                    <h4><?php esc_html_e( 'Customer Information', 'vss' ); ?></h4>
                    <p><strong><?php esc_html_e( 'Name:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></p>
                    <p><strong><?php esc_html_e( 'Email:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_billing_email() ); ?></p>
                    <?php if ( $order->get_billing_phone() ) : ?>
                    <p><strong><?php esc_html_e( 'Phone:', 'vss' ); ?></strong> <?php echo esc_html( $order->get_billing_phone() ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="overview-section">
                    <h4><?php esc_html_e( 'Shipping Address', 'vss' ); ?></h4>
                    <div class="shipping-address">
                        <?php echo wp_kses_post( $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address() ); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render order products with secure download URLs
     */
    private static function render_order_products( $order ) {
        ?>
        <div class="vss-order-products">
            <h4><?php esc_html_e( 'Order Items', 'vss' ); ?></h4>
            <table class="vss-items-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Product', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'SKU', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'Quantity', 'vss' ); ?></th>
                        <th><?php esc_html_e( 'Design Files', 'vss' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $order->get_items() as $item_id => $item ) : ?>
                        <?php
                        $product = $item->get_product();
                        $zakeke_data = $item->get_meta( 'zakeke_data', true );
                        $zip_url = $item->get_meta( '_vss_zakeke_printing_files_zip_url', true );
                        $primary_zakeke_design_id = null;

                        // Parse Zakeke data to get design ID
                        if ( $zakeke_data ) {
                            $parsed_data = is_string( $zakeke_data ) ? json_decode( $zakeke_data, true ) : (array) $zakeke_data;
                            if ( is_array( $parsed_data ) && isset( $parsed_data['design'] ) ) {
                                $primary_zakeke_design_id = $parsed_data['design'];
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $item->get_name() ); ?></strong>
                                <?php if ( $product && $product->get_image_id() ) : ?>
                                    <br><?php echo wp_get_attachment_image( $product->get_image_id(), 'thumbnail' ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $product ? $product->get_sku() : '—' ); ?></td>
                            <td><?php echo esc_html( $item->get_quantity() ); ?></td>
                            <td>
                                <?php if ( $zip_url ) : ?>
                                    <a href="<?php echo esc_url( $zip_url ); ?>" class="button button-small" target="_blank">
                                        <?php esc_html_e( 'Download Zakeke Files', 'vss' ); ?>
                                    </a>
                                <?php elseif ( $primary_zakeke_design_id ) : ?>
                                    <button type="button" class="button button-small vss-manual-fetch-zakeke-zip"
                                            data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                                            data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                            data-zakeke-design-id="<?php echo esc_attr( $primary_zakeke_design_id ); ?>">
                                        <?php esc_html_e( 'Fetch Zakeke Files', 'vss' ); ?>
                                    </button>
                                <?php else : ?>
                                    <?php
                                    // Check if there's an admin uploaded ZIP file
                                    $admin_zip_id = get_post_meta( $order->get_id(), '_vss_attached_zip_id', true );
                                    if ( $admin_zip_id ) :
                                        // Use secure download URL instead of direct attachment URL
                                        $secure_download_url = self::get_secure_download_url( $admin_zip_id, $order->get_id() );
                                    ?>
                                        <a href="<?php echo esc_url( $secure_download_url ); ?>"
                                           class="button button-small vss-admin-zip-download"
                                           data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                                           data-file-id="<?php echo esc_attr( $admin_zip_id ); ?>">
                                            <?php esc_html_e( 'Download Admin ZIP', 'vss' ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="no-files"><?php esc_html_e( 'No design files', 'vss' ); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Handle Zakeke file fetching
            $('.vss-manual-fetch-zakeke-zip').on('click', function() {
                var $button = $(this);
                var originalText = $button.text();

                $button.prop('disabled', true).text('<?php echo esc_js( __( 'Fetching...', 'vss' ) ); ?>');

                $.ajax({
                    url: vss_frontend_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'vss_manual_fetch_zip',
                        order_id: $button.data('order-id'),
                        item_id: $button.data('item-id'),
                        primary_zakeke_design_id: $button.data('zakeke-design-id'),
                        _ajax_nonce: vss_frontend_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Replace button with download link
                            var downloadLink = '<a href=\"' + response.data.zip_url + '\" class=\"button button-small\" target=\"_blank\"><?php echo esc_js( __( 'Download Zakeke Files', 'vss' ) ); ?></a>';
                            $button.replaceWith(downloadLink);
                        } else {
                            alert(response.data.message || '<?php echo esc_js( __( 'Failed to fetch files. Please try again.', 'vss' ) ); ?>');
                            $button.prop('disabled', false).text(originalText);
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js( __( 'An error occurred. Please try again.', 'vss' ) ); ?>');
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Enhanced render_shipping_section with always-visible tracking form
     */
    private static function render_shipping_section( $order ) {
        $tracking_number = get_post_meta( $order->get_id(), '_vss_tracking_number', true );
        $tracking_carrier = get_post_meta( $order->get_id(), '_vss_tracking_carrier', true );
        $shipped_at = get_post_meta( $order->get_id(), '_vss_shipped_at', true );
        $order_status = $order->get_status();
        ?>
        <div class="vss-shipping-section">
            <h4><?php esc_html_e( 'Shipping Information', 'vss' ); ?></h4>

            <?php if ( $tracking_number ) : ?>
                <div class="tracking-info-display">
                    <div class="tracking-details">
                        <p><strong><?php esc_html_e( 'Tracking Number:', 'vss' ); ?></strong>
                            <span class="tracking-number"><?php echo esc_html( $tracking_number ); ?></span>
                            <?php if ( $tracking_carrier && $tracking_number ) : ?>
                                <?php $tracking_url = self::get_tracking_url( $tracking_carrier, $tracking_number ); ?>
                                <?php if ( $tracking_url ) : ?>
                                    <a href="<?php echo esc_url( $tracking_url ); ?>" target="_blank" class="track-button">
                                        <?php esc_html_e( 'Track Package', 'vss' ); ?>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                        <?php if ( $tracking_carrier ) : ?>
                            <p><strong><?php esc_html_e( 'Carrier:', 'vss' ); ?></strong>
                                <?php echo esc_html( self::get_carrier_name( $tracking_carrier ) ); ?>
                            </p>
                        <?php endif; ?>
                        <?php if ( $shipped_at ) : ?>
                            <p><strong><?php esc_html_e( 'Shipped Date:', 'vss' ); ?></strong>
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $shipped_at ) ); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ( in_array( $order_status, ['processing', 'shipped'] ) ) : ?>
                        <button type="button" class="button button-small edit-tracking-btn">
                            <?php esc_html_e( 'Edit Tracking Info', 'vss' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            // Show form if: processing/shipped status OR no tracking info yet
            $show_form = in_array( $order_status, ['processing', 'shipped'] ) || ! $tracking_number;
            $form_style = $tracking_number && ! isset( $_GET['edit_tracking'] ) ? 'style="display:none;"' : '';
            ?>

            <?php if ( $show_form ) : ?>
                <div class="tracking-form-wrapper" <?php echo $form_style; ?>>
                    <?php if ( ! in_array( $order_status, ['processing', 'shipped'] ) ) : ?>
                        <div class="vss-notice vss-notice-info">
                            <p><?php esc_html_e( 'Tracking information can be added when the order is in "Processing" or "Shipped" status.', 'vss' ); ?></p>
                            <p><?php printf( __( 'Current order status: %s', 'vss' ), '<strong>' . wc_get_order_status_name( $order_status ) . '</strong>' ); ?></p>
                        </div>
                    <?php else : ?>
                        <form method="post" class="vss-shipping-form">
                            <?php wp_nonce_field( 'vss_save_tracking' ); ?>
                            <input type="hidden" name="vss_fe_action" value="save_tracking">
                            <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">

                            <div class="tracking-fields">
                                <div class="field-group">
                                    <label for="tracking_carrier"><?php esc_html_e( 'Shipping Carrier:', 'vss' ); ?> <span class="required">*</span></label>
                                    <select name="tracking_carrier" id="tracking_carrier" required>
                                        <option value=""><?php esc_html_e( '— Select Carrier —', 'vss' ); ?></option>
                                        <optgroup label="<?php esc_attr_e( 'United States', 'vss' ); ?>">
                                            <option value="usps" <?php selected( $tracking_carrier, 'usps' ); ?>>USPS</option>
                                            <option value="ups" <?php selected( $tracking_carrier, 'ups' ); ?>>UPS</option>
                                            <option value="fedex" <?php selected( $tracking_carrier, 'fedex' ); ?>>FedEx</option>
                                            <option value="dhl_us" <?php selected( $tracking_carrier, 'dhl_us' ); ?>>DHL Express</option>
                                        </optgroup>
                                        <optgroup label="<?php esc_attr_e( 'International', 'vss' ); ?>">
                                            <option value="dhl" <?php selected( $tracking_carrier, 'dhl' ); ?>>DHL Global</option>
                                            <option value="australia_post" <?php selected( $tracking_carrier, 'australia_post' ); ?>><?php esc_html_e( 'Australia Post', 'vss' ); ?></option>
                                            <option value="royal_mail" <?php selected( $tracking_carrier, 'royal_mail' ); ?>><?php esc_html_e( 'Royal Mail (UK)', 'vss' ); ?></option>
                                            <option value="canada_post" <?php selected( $tracking_carrier, 'canada_post' ); ?>><?php esc_html_e( 'Canada Post', 'vss' ); ?></option>
                                        </optgroup>
                                        <option value="other" <?php selected( $tracking_carrier, 'other' ); ?>><?php esc_html_e( 'Other', 'vss' ); ?></option>
                                    </select>
                                </div>

                                <div class="field-group">
                                    <label for="tracking_number"><?php esc_html_e( 'Tracking Number:', 'vss' ); ?> <span class="required">*</span></label>
                                    <input type="text"
                                           name="tracking_number"
                                           id="tracking_number"
                                           value="<?php echo esc_attr( $tracking_number ); ?>"
                                           placeholder="<?php esc_attr_e( 'Enter tracking number', 'vss' ); ?>"
                                           required>
                                    <small class="field-description"><?php esc_html_e( 'Enter the complete tracking number provided by the carrier', 'vss' ); ?></small>
                                </div>
                            </div>

                            <p class="vss-form-notice">
                                <strong><?php esc_html_e( 'Note:', 'vss' ); ?></strong>
                                <?php
                                if ( $order_status === 'processing' ) {
                                    esc_html_e( 'Adding tracking information will mark this order as "Shipped".', 'vss' );
                                } else {
                                    esc_html_e( 'This will update the tracking information and notify the customer.', 'vss' );
                                }
                                ?>
                            </p>

                            <div class="form-actions">
                                <input type="submit"
                                       value="<?php echo $tracking_number ? esc_attr__( 'Update Tracking Info', 'vss' ) : esc_attr__( 'Save Tracking & Mark as Shipped', 'vss' ); ?>"
                                       class="button button-primary">
                                <?php if ( $tracking_number ) : ?>
                                    <button type="button" class="button cancel-edit-btn"><?php esc_html_e( 'Cancel', 'vss' ); ?></button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <?php if ( ! $tracking_number ) : ?>
                    <p class="no-tracking-info"><?php esc_html_e( 'No tracking information available yet.', 'vss' ); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <style>
        /* Enhanced Shipping Section Styles */
        .vss-shipping-section {
            position: relative;
        }

        .tracking-info-display {
            background: #f0f8ff;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #b8daff;
        }

        .tracking-details p {
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tracking-number {
            font-family: monospace;
            font-size: 1.1em;
            background: white;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }

        .track-button {
            background: #007cba;
            color: white !important;
            padding: 4px 12px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9em;
            transition: background 0.2s;
        }

        .track-button:hover {
            background: #005a87;
            text-decoration: none;
        }

        .edit-tracking-btn {
            margin-top: 15px;
        }

        .tracking-form-wrapper {
            transition: all 0.3s ease;
        }

        .vss-shipping-section .tracking-fields {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
            margin: 20px 0;
        }

        .vss-shipping-section .field-group {
            display: flex;
            flex-direction: column;
        }

        .vss-shipping-section .field-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .vss-shipping-section .field-group input,
        .vss-shipping-section .field-group select {
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .vss-shipping-section .field-group input:focus,
        .vss-shipping-section .field-group select:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.1);
        }

        .field-description {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .vss-shipping-section .tracking-fields {
                grid-template-columns: 1fr;
            }

            .tracking-details p {
                flex-wrap: wrap;
            }
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Toggle edit form
            $('.edit-tracking-btn').on('click', function() {
                $('.tracking-form-wrapper').slideDown();
                $('.tracking-info-display').slideUp();
            });

            $('.cancel-edit-btn').on('click', function() {
                $('.tracking-form-wrapper').slideUp();
                $('.tracking-info-display').slideDown();
            });
        });
        </script>
        <?php
    }

    /**
     * Enhanced render_approval_section with better visibility
     */
    private static function render_approval_section( $order, $type ) {
        $type_label = $type === 'mockup' ? __( 'Mockup', 'vss' ) : __( 'Production Files', 'vss' );
        $files = get_post_meta( $order->get_id(), '_vss_' . $type . '_files', true );
        $status = get_post_meta( $order->get_id(), '_vss_' . $type . '_status', true );
        $order_status = $order->get_status();

        // More permissive status check - allow for multiple statuses
        $allowed_statuses = ['pending', 'processing', 'on-hold', 'in-production'];
        $can_upload = in_array( $order_status, $allowed_statuses ) && ( ! $status || $status === 'disapproved' || $status === 'none' );
        ?>
        <div class="vss-approval-section vss-<?php echo esc_attr( $type ); ?>-section">
            <div class="section-header-info">
                <h4><?php echo esc_html( $type_label ); ?> <?php esc_html_e( 'Approval', 'vss' ); ?></h4>
                <?php if ( $status ) : ?>
                    <span class="current-status status-<?php echo esc_attr( $status ); ?>">
                        <?php echo esc_html( self::get_approval_status_label( $status ) ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php if ( $files && is_array( $files ) ) : ?>
                <div class="submitted-files-display">
                    <h5><?php esc_html_e( 'Submitted Files:', 'vss' ); ?></h5>
                    <div class="files-grid">
                        <?php foreach ( $files as $file_url ) : ?>
                            <?php
                            $file_name = basename( $file_url );
                            $file_ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
                            $is_image = in_array( $file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'] );
                            ?>
                            <div class="file-item">
                                <?php if ( $is_image ) : ?>
                                    <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="file-preview">
                                        <img src="<?php echo esc_url( $file_url ); ?>" alt="<?php echo esc_attr( $file_name ); ?>">
                                        <span class="file-name"><?php echo esc_html( $file_name ); ?></span>
                                    </a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="file-link">
                                        <span class="dashicons dashicons-media-default"></span>
                                        <span class="file-name"><?php echo esc_html( $file_name ); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ( $status === 'pending' || $status === 'pending_approval' ) : ?>
                        <div class="approval-pending-notice">
                            <span class="dashicons dashicons-clock"></span>
                            <?php esc_html_e( 'Waiting for customer review. You will be notified once they respond.', 'vss' ); ?>
                        </div>
                    <?php elseif ( $status === 'approved' ) : ?>
                        <div class="approval-success-notice">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php printf( __( '%s has been approved by the customer!', 'vss' ), $type_label ); ?>
                        </div>
                    <?php elseif ( $status === 'disapproved' ) : ?>
                        <?php
                        $disapproval_reason = get_post_meta( $order->get_id(), '_vss_' . $type . '_disapproval_reason', true );
                        $customer_notes = get_post_meta( $order->get_id(), '_vss_' . $type . '_customer_notes', true );
                        ?>
                        <div class="disapproval-notice">
                            <span class="dashicons dashicons-warning"></span>
                            <strong><?php esc_html_e( 'Customer requested changes:', 'vss' ); ?></strong>
                            <?php if ( $customer_notes ) : ?>
                                <p class="customer-feedback"><?php echo esc_html( $customer_notes ); ?></p>
                            <?php elseif ( $disapproval_reason ) : ?>
                                <p class="customer-feedback"><?php echo esc_html( $disapproval_reason ); ?></p>
                            <?php else : ?>
                                <p class="customer-feedback"><?php esc_html_e( 'No specific feedback provided.', 'vss' ); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $can_upload ) : ?>
                <div class="approval-upload-section">
                    <?php if ( ! $files || $status === 'disapproved' ) : ?>
                        <h5>
                            <?php
                            if ( $status === 'disapproved' ) {
                                printf( __( 'Upload Revised %s:', 'vss' ), $type_label );
                            } else {
                                printf( __( 'Upload %s for Approval:', 'vss' ), $type_label );
                            }
                            ?>
                        </h5>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" class="vss-approval-form">
                        <?php wp_nonce_field( 'vss_approval_submission' ); ?>
                        <input type="hidden" name="vss_fe_action" value="send_<?php echo esc_attr( $type ); ?>_for_approval">
                        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">

                        <div class="upload-area">
                            <div class="upload-dropzone" id="dropzone-<?php echo esc_attr( $type ); ?>">
                                <input type="file"
                                       name="approval_files[]"
                                       id="approval_files_<?php echo esc_attr( $type ); ?>"
                                       multiple
                                       accept="image/*,.pdf"
                                       required>

                                <label for="approval_files_<?php echo esc_attr( $type ); ?>" class="upload-label">
                                    <span class="dashicons dashicons-upload"></span>
                                    <span class="upload-text"><?php esc_html_e( 'Click to select files or drag and drop', 'vss' ); ?></span>
                                    <span class="upload-info"><?php esc_html_e( 'Accepted: JPG, PNG, GIF, PDF (Max 10MB each)', 'vss' ); ?></span>
                                </label>
                            </div>

                            <div class="selected-files" id="selected-files-<?php echo esc_attr( $type ); ?>"></div>
                        </div>

                        <div class="form-notes">
                            <label for="approval_notes_<?php echo esc_attr( $type ); ?>">
                                <?php esc_html_e( 'Notes for customer (optional):', 'vss' ); ?>
                            </label>
                            <textarea name="approval_notes"
                                      id="approval_notes_<?php echo esc_attr( $type ); ?>"
                                      rows="3"
                                      placeholder="<?php esc_attr_e( 'Add any notes or instructions for the customer...', 'vss' ); ?>"></textarea>
                        </div>

                        <div class="submit-section">
                            <input type="submit"
                                   value="<?php printf( esc_attr__( 'Send %s for Approval', 'vss' ), $type_label ); ?>"
                                   class="button button-primary button-large">
                            <p class="submit-note">
                                <?php esc_html_e( 'The customer will receive an email notification to review and approve.', 'vss' ); ?>
                            </p>
                        </div>
                    </form>
                </div>
            <?php elseif ( ! in_array( $order_status, $allowed_statuses ) ) : ?>
                <div class="vss-notice vss-notice-info">
                    <p><?php printf( __( '%s can only be uploaded when the order is in processing status.', 'vss' ), $type_label ); ?></p>
                    <p><?php printf( __( 'Current status: %s', 'vss' ), '<strong>' . wc_get_order_status_name( $order_status ) . '</strong>' ); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <style>
        /* Enhanced Approval Section Styles */
        .vss-approval-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .section-header-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header-info h4 {
            margin: 0;
            font-size: 1.3em;
            color: #333;
        }

        .current-status {
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.9em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .current-status.status-pending,
        .current-status.status-pending_approval {
            background: #fff3cd;
            color: #856404;
        }

        .current-status.status-approved {
            background: #d4edda;
            color: #155724;
        }

        .current-status.status-disapproved {
            background: #f8d7da;
            color: #721c24;
        }

        /* Files Display */
        .submitted-files-display {
            margin: 20px 0;
        }

        .submitted-files-display h5 {
            margin: 0 0 15px 0;
            color: #555;
        }

        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .file-item {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .file-item:hover {
            border-color: #2271b1;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .file-preview {
            display: block;
            text-decoration: none;
            color: #333;
        }

        .file-preview img {
            width: 100%;
            height: 120px;
            object-fit: cover;
        }

        .file-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
            text-decoration: none;
            color: #333;
            text-align: center;
        }

        .file-link .dashicons {
            font-size: 48px;
            color: #666;
            margin-bottom: 10px;
        }

        .file-name {
            display: block;
            padding: 8px;
            font-size: 0.85em;
            background: #f8f8f8;
            word-break: break-word;
        }

        /* Notices */
        .approval-pending-notice,
        .approval-success-notice,
        .disapproval-notice {
            padding: 15px;
            border-radius: 6px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 15px;
        }

        .approval-pending-notice {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .approval-success-notice {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .disapproval-notice {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .disapproval-notice .dashicons,
        .approval-pending-notice .dashicons,
        .approval-success-notice .dashicons {
            font-size: 20px;
            margin-top: 2px;
        }

        .customer-feedback {
            margin: 10px 0 0 30px;
            padding: 10px;
            background: white;
            border-left: 3px solid #dc3545;
            border-radius: 4px;
        }

        /* Upload Area */
        .approval-upload-section {
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
        }

        .approval-upload-section h5 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 1.1em;
        }

        .upload-area {
            margin-bottom: 20px;
        }

        .upload-dropzone {
            border: 3px dashed #ccc;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            background: #fafafa;
            position: relative;
            transition: all 0.3s ease;
        }

        .upload-dropzone.dragover {
            border-color: #2271b1;
            background: #f0f7ff;
        }

        .upload-dropzone input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            cursor: pointer;
        }

        .upload-label .dashicons {
            font-size: 48px;
            color: #666;
        }

        .upload-text {
            font-size: 1.1em;
            color: #333;
            font-weight: 500;
        }

        .upload-info {
            font-size: 0.9em;
            color: #666;
        }

        .selected-files {
            margin-top: 15px;
        }

        .selected-file {
            display: inline-block;
            margin: 5px;
            padding: 8px 15px;
            background: #e0e7ff;
            border-radius: 20px;
            font-size: 0.9em;
            position: relative;
        }

        .selected-file .remove {
            margin-left: 8px;
            cursor: pointer;
            color: #dc3545;
            font-weight: bold;
        }

        /* Form Notes */
        .form-notes {
            margin: 20px 0;
        }

        .form-notes label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-notes textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
        }

        .form-notes textarea:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.1);
        }

        /* Submit Section */
        .submit-section {
            margin-top: 25px;
        }

        .submit-section .button-large {
            padding: 12px 30px;
            font-size: 16px;
        }

        .submit-note {
            margin: 10px 0 0 0;
            color: #666;
            font-size: 0.9em;
            font-style: italic;
        }

        /* Common styles */
        .vss-notice {
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }

        .vss-notice-info {
            background: #e3f2fd;
            border: 1px solid #2196f3;
            color: #1976d2;
        }

        .vss-form-notice {
            background: #fff3cd;
            padding: 10px 15px;
            border-radius: 4px;
            margin: 15px 0;
            border: 1px solid #ffc107;
        }

        .required {
            color: #d32f2f;
        }

        @media (max-width: 768px) {
            .files-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }

            .section-header-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Enhanced file upload preview
            $('#approval_files_<?php echo esc_js( $type ); ?>').on('change', function() {
                var files = this.files;
                var $selectedFiles = $('#selected-files-<?php echo esc_js( $type ); ?>');
                $selectedFiles.empty();

                if (files.length > 0) {
                    $selectedFiles.append('<p><strong><?php echo esc_js( __( 'Selected files:', 'vss' ) ); ?></strong></p>');
                    for (var i = 0; i < files.length; i++) {
                        var fileName = files[i].name;
                        var fileSize = (files[i].size / 1024 / 1024).toFixed(2);
                        $selectedFiles.append(
                            '<span class="selected-file">' +
                            fileName + ' (' + fileSize + 'MB)' +
                            '<span class="remove" data-index="' + i + '">×</span>' +
                            '</span>'
                        );
                    }
                }
            });

            // Drag and drop functionality
            var $dropzone = $('#dropzone-<?php echo esc_js( $type ); ?>');

            $dropzone.on('dragover', function(e) {
                e.preventDefault();
                $(this).addClass('dragover');
            });

            $dropzone.on('dragleave', function(e) {
                e.preventDefault();
                $(this).removeClass('dragover');
            });

            $dropzone.on('drop', function(e) {
                e.preventDefault();
                $(this).removeClass('dragover');

                var files = e.originalEvent.dataTransfer.files;
                $('#approval_files_<?php echo esc_js( $type ); ?>')[0].files = files;
                $('#approval_files_<?php echo esc_js( $type ); ?>').trigger('change');
            });
        });
        </script>
        <?php
    }

    /**
     * Render costs section
     */
    private static function render_costs_section( $order ) {
        $costs = get_post_meta( $order->get_id(), '_vss_order_costs', true );
        if ( ! is_array( $costs ) ) {
            $costs = [
                'material_cost' => 0,
                'labor_cost' => 0,
                'shipping_cost' => 0,
                'other_cost' => 0,
                'total_cost' => 0,
            ];
        }

        $order_status = $order->get_status();
        $can_edit = in_array( $order_status, ['pending', 'processing', 'on-hold', 'in-production', 'shipped'] );
        ?>
        <div class="vss-costs-section">
            <h4><?php esc_html_e( 'Order Costs', 'vss' ); ?></h4>

            <?php if ( $costs['total_cost'] > 0 ) : ?>
                <div class="costs-summary">
                    <div class="cost-breakdown">
                        <div class="cost-line">
                            <span class="cost-label"><?php esc_html_e( 'Materials:', 'vss' ); ?></span>
                            <span class="cost-value"><?php echo wc_price( $costs['material_cost'] ); ?></span>
                        </div>
                        <div class="cost-line">
                            <span class="cost-label"><?php esc_html_e( 'Labor:', 'vss' ); ?></span>
                            <span class="cost-value"><?php echo wc_price( $costs['labor_cost'] ); ?></span>
                        </div>
                        <div class="cost-line">
                            <span class="cost-label"><?php esc_html_e( 'Shipping:', 'vss' ); ?></span>
                            <span class="cost-value"><?php echo wc_price( $costs['shipping_cost'] ); ?></span>
                        </div>
                        <?php if ( $costs['other_cost'] > 0 ) : ?>
                            <div class="cost-line">
                                <span class="cost-label"><?php esc_html_e( 'Other:', 'vss' ); ?></span>
                                <span class="cost-value"><?php echo wc_price( $costs['other_cost'] ); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="cost-line cost-total">
                            <span class="cost-label"><?php esc_html_e( 'Total Cost:', 'vss' ); ?></span>
                            <span class="cost-value"><?php echo wc_price( $costs['total_cost'] ); ?></span>
                        </div>
                    </div>

                    <?php if ( $can_edit ) : ?>
                        <button type="button" class="button button-small edit-costs-btn">
                            <?php esc_html_e( 'Edit Costs', 'vss' ); ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php
            $form_style = $costs['total_cost'] > 0 && ! isset( $_GET['edit_costs'] ) ? 'style="display:none;"' : '';
            ?>

            <?php if ( $can_edit ) : ?>
                <div class="costs-form-wrapper" <?php echo $form_style; ?>>
                    <form method="post" class="vss-costs-form">
                        <?php wp_nonce_field( 'vss_save_costs' ); ?>
                        <input type="hidden" name="vss_fe_action" value="save_costs">
                        <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">

                        <p class="form-description">
                            <?php esc_html_e( 'Enter your costs for this order. This information helps track profitability.', 'vss' ); ?>
                        </p>

                        <div class="costs-grid">
                            <div class="cost-item">
                                <label for="material_cost">
                                    <?php esc_html_e( 'Material Cost', 'vss' ); ?>
                                    <span class="dashicons dashicons-info" title="<?php esc_attr_e( 'Cost of raw materials, supplies, etc.', 'vss' ); ?>"></span>
                                </label>
                                <div class="input-group">
                                    <span class="currency-symbol"><?php echo get_woocommerce_currency_symbol(); ?></span>
                                    <input type="number"
                                           name="material_cost"
                                           id="material_cost"
                                           value="<?php echo esc_attr( $costs['material_cost'] ); ?>"
                                           step="0.01"
                                           min="0"
                                           class="vss-cost-input">
                                </div>
                            </div>

                            <div class="cost-item">
                                <label for="labor_cost">
                                    <?php esc_html_e( 'Labor Cost', 'vss' ); ?>
                                    <span class="dashicons dashicons-info" title="<?php esc_attr_e( 'Cost of time and labor to produce', 'vss' ); ?>"></span>
                                </label>
                                <div class="input-group">
                                    <span class="currency-symbol"><?php echo get_woocommerce_currency_symbol(); ?></span>
                                    <input type="number"
                                           name="labor_cost"
                                           id="labor_cost"
                                           value="<?php echo esc_attr( $costs['labor_cost'] ); ?>"
                                           step="0.01"
                                           min="0"
                                           class="vss-cost-input">
                                </div>
                            </div>

                            <div class="cost-item">
                                <label for="shipping_cost">
                                    <?php esc_html_e( 'Shipping Cost', 'vss' ); ?>
                                    <span class="dashicons dashicons-info" title="<?php esc_attr_e( 'Your cost to ship to customer', 'vss' ); ?>"></span>
                                </label>
                                <div class="input-group">
                                    <span class="currency-symbol"><?php echo get_woocommerce_currency_symbol(); ?></span>
                                    <input type="number"
                                           name="shipping_cost"
                                           id="shipping_cost"
                                           value="<?php echo esc_attr( $costs['shipping_cost'] ); ?>"
                                           step="0.01"
                                           min="0"
                                           class="vss-cost-input">
                                </div>
                            </div>

                            <div class="cost-item">
                                <label for="other_cost">
                                    <?php esc_html_e( 'Other Costs', 'vss' ); ?>
                                    <span class="dashicons dashicons-info" title="<?php esc_attr_e( 'Any additional costs', 'vss' ); ?>"></span>
                                </label>
                                <div class="input-group">
                                    <span class="currency-symbol"><?php echo get_woocommerce_currency_symbol(); ?></span>
                                    <input type="number"
                                           name="other_cost"
                                           id="other_cost"
                                           value="<?php echo esc_attr( $costs['other_cost'] ); ?>"
                                           step="0.01"
                                           min="0"
                                           class="vss-cost-input">
                                </div>
                            </div>
                        </div>

                        <div class="cost-notes">
                            <label for="cost_notes"><?php esc_html_e( 'Cost Notes (optional):', 'vss' ); ?></label>
                            <textarea name="cost_notes"
                                      id="cost_notes"
                                      rows="2"
                                      placeholder="<?php esc_attr_e( 'Any notes about these costs...', 'vss' ); ?>"><?php echo esc_textarea( get_post_meta( $order->get_id(), '_vss_cost_notes', true ) ); ?></textarea>
                        </div>

                        <div class="cost-total-display">
                            <span class="total-label"><?php esc_html_e( 'Total Cost:', 'vss' ); ?></span>
                            <span class="total-amount" id="vss-total-cost-display"><?php echo wc_price( $costs['total_cost'] ); ?></span>
                        </div>

                        <div class="form-actions">
                            <input type="submit" value="<?php esc_attr_e( 'Save Costs', 'vss' ); ?>" class="button button-primary">
                            <?php if ( $costs['total_cost'] > 0 ) : ?>
                                <button type="button" class="button cancel-costs-btn"><?php esc_html_e( 'Cancel', 'vss' ); ?></button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            <?php else : ?>
                <?php if ( $costs['total_cost'] == 0 ) : ?>
                    <p class="no-costs-info"><?php esc_html_e( 'No cost information has been entered yet.', 'vss' ); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <style>
        /* Enhanced Costs Section Styles */
        .vss-costs-section {
            background: white;
            padding: 25px;
            border-radius: 8px;
        }

        .costs-summary {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .cost-breakdown {
            margin-bottom: 15px;
        }

        .cost-line {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .cost-line:last-child {
            border-bottom: none;
        }

        .cost-line.cost-total {
            margin-top: 10px;
            padding-top: 15px;
            border-top: 2px solid #dee2e6;
            font-size: 1.2em;
            font-weight: bold;
        }

        .cost-label {
            color: #495057;
        }

        .cost-value {
            font-weight: 500;
            color: #212529;
        }

        .edit-costs-btn {
            margin-top: 15px;
        }

        /* Costs Form */
        .costs-form-wrapper {
            transition: all 0.3s ease;
        }

        .form-description {
            background: #e3f2fd;
            padding: 12px 15px;
            border-radius: 6px;
            color: #1976d2;
            margin-bottom: 20px;
            font-size: 0.95em;
        }

        .costs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .cost-item label {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .cost-item label .dashicons {
            font-size: 16px;
            color: #666;
            cursor: help;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .currency-symbol {
            position: absolute;
            left: 12px;
            color: #666;
            font-weight: 500;
            pointer-events: none;
        }

        .vss-cost-input {
            width: 100%;
            padding: 10px 12px 10px 28px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .vss-cost-input:focus {
            outline: none;
            border-color: #2271b1;
            box-shadow: 0 0 0 3px rgba(34, 113, 177, 0.1);
        }

        .vss-cost-input.error {
            border-color: #dc3545;
        }

        .cost-notes {
            margin: 20px 0;
        }

        .cost-notes label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .cost-notes textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
        }

        .cost-total-display {
            background: #f0f0f0;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 1.3em;
        }

        .total-label {
            font-weight: 600;
            color: #333;
        }

        .total-amount {
            font-weight: bold;
            color: #2271b1;
        }

        .no-costs-info {
            color: #666;
            font-style: italic;
            text-align: center;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .costs-grid {
                grid-template-columns: 1fr;
            }
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            // Toggle edit form
            $('.edit-costs-btn').on('click', function() {
                $('.costs-form-wrapper').slideDown();
                $('.costs-summary').slideUp();
            });

            $('.cancel-costs-btn').on('click', function() {
                $('.costs-form-wrapper').slideUp();
                $('.costs-summary').slideDown();
            });

            // Real-time cost calculation
            $('.vss-cost-input').on('input', function() {
                var total = 0;
                var hasError = false;

                $('.vss-cost-input').each(function() {
                    var value = parseFloat($(this).val()) || 0;

                    if (value < 0) {
                        $(this).addClass('error');
                        hasError = true;
                    } else {
                        $(this).removeClass('error');
                        total += value;
                    }
                });

                // Update total display
                var currencySymbol = '<?php echo get_woocommerce_currency_symbol(); ?>';
                $('#vss-total-cost-display').html(currencySymbol + total.toFixed(2));

                // Disable submit if there are errors
                $('.vss-costs-form input[type="submit"]').prop('disabled', hasError);
            });

            // Initialize calculation on page load
            if ($('.vss-cost-input').length > 0) {
                $('.vss-cost-input').first().trigger('input');
            }
        });
        </script>
        <?php
    }

    /**
     * Render notes section
     */
    private static function render_notes_section( $order ) {
        $notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        ?>
        <div class="vss-notes-section">
            <h4><?php esc_html_e( 'Order Notes', 'vss' ); ?></h4>

            <?php if ( $notes ) : ?>
                <div class="order-notes">
                    <?php foreach ( $notes as $note ) : ?>
                        <div class="note-item">
                            <div class="note-meta">
                                <strong><?php echo esc_html( $note->added_by ); ?></strong>
                                <span class="note-date"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $note->date_created ) ) ); ?></span>
                            </div>
                            <div class="note-content"><?php echo wp_kses_post( wpautop( $note->content ) ); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" class="vss-add-note-form">
                <?php wp_nonce_field( 'vss_add_note' ); ?>
                <input type="hidden" name="vss_fe_action" value="add_note">
                <input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">

                <label for="vendor_note"><?php esc_html_e( 'Add Note:', 'vss' ); ?></label>
                <textarea name="vendor_note"
                          id="vendor_note"
                          rows="4"
                          placeholder="<?php esc_attr_e( 'Add a note about this order...', 'vss' ); ?>"></textarea>

                <input type="submit" value="<?php esc_attr_e( 'Add Note', 'vss' ); ?>" class="button">
            </form>
        </div>
        <?php
    }

    /**
     * Render files section
     */
    private static function render_files_section( $order ) {
        ?>
        <div class="vss-files-section">
            <h4><?php esc_html_e( 'Order Files', 'vss' ); ?></h4>

            <div class="files-grid">
                <div class="file-category">
                    <h5><?php esc_html_e( 'Design Files', 'vss' ); ?></h5>
                    <?php
                    $has_design_files = false;

                    // Check for Zakeke files
                    foreach ( $order->get_items() as $item_id => $item ) : ?>
                        <?php
                        $zip_url = $item->get_meta( '_vss_zakeke_printing_files_zip_url', true );
                        $zakeke_data = $item->get_meta( 'zakeke_data', true );
                        $primary_zakeke_design_id = null;

                        // Parse Zakeke data to get design ID
                        if ( $zakeke_data ) {
                            $parsed_data = is_string( $zakeke_data ) ? json_decode( $zakeke_data, true ) : (array) $zakeke_data;
                            if ( is_array( $parsed_data ) && isset( $parsed_data['design'] ) ) {
                                $primary_zakeke_design_id = $parsed_data['design'];
                            }
                        }

                        if ( $zip_url ) :
                            $has_design_files = true;
                            ?>
                            <p>
                                <strong><?php echo esc_html( $item->get_name() ); ?>:</strong><br>
                                <a href="<?php echo esc_url( $zip_url ); ?>" target="_blank">
                                    <?php esc_html_e( 'Download Zakeke Design Files', 'vss' ); ?>
                                </a>
                            </p>
                        <?php elseif ( $primary_zakeke_design_id ) :
                            $has_design_files = true;
                            ?>
                            <p>
                                <strong><?php echo esc_html( $item->get_name() ); ?>:</strong><br>
                                <button type="button" class="button button-small vss-manual-fetch-zakeke-zip"
                                        data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                                        data-item-id="<?php echo esc_attr( $item_id ); ?>"
                                        data-zakeke-design-id="<?php echo esc_attr( $primary_zakeke_design_id ); ?>">
                                    <?php esc_html_e( 'Fetch Zakeke Files', 'vss' ); ?>
                                </button>
                            </p>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php
                    // Check for admin uploaded ZIP with secure URL
                    $admin_zip_id = get_post_meta( $order->get_id(), '_vss_attached_zip_id', true );
                    if ( $admin_zip_id ) :
                        $has_design_files = true;
                        $secure_download_url = self::get_secure_download_url( $admin_zip_id, $order->get_id() );
                        ?>
                        <p>
                            <strong><?php esc_html_e( 'Admin Uploaded ZIP:', 'vss' ); ?></strong><br>
                            <a href="<?php echo esc_url( $secure_download_url ); ?>"
                               class="vss-admin-zip-download"
                               data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                               data-file-id="<?php echo esc_attr( $admin_zip_id ); ?>">
                                <?php esc_html_e( 'Download ZIP File', 'vss' ); ?>
                            </a>
                        </p>
                    <?php endif; ?>

                    <?php if ( ! $has_design_files ) : ?>
                        <p class="no-files"><?php esc_html_e( 'No design files available.', 'vss' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="file-category">
                    <h5><?php esc_html_e( 'Mockup Files', 'vss' ); ?></h5>
                    <?php
                    $mockup_files = get_post_meta( $order->get_id(), '_vss_mockup_files', true );
                    if ( $mockup_files ) :
                        foreach ( $mockup_files as $file_url ) :
                            ?>
                            <p><a href="<?php echo esc_url( $file_url ); ?>" target="_blank"><?php echo esc_html( basename( $file_url ) ); ?></a></p>
                        <?php
                        endforeach;
                    else :
                        ?>
                        <p class="no-files"><?php esc_html_e( 'No mockup files uploaded yet.', 'vss' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="file-category">
                    <h5><?php esc_html_e( 'Production Files', 'vss' ); ?></h5>
                    <?php
                    $production_files = get_post_meta( $order->get_id(), '_vss_production_file_files', true );
                    if ( $production_files ) :
                        foreach ( $production_files as $file_url ) :
                            ?>
                            <p><a href="<?php echo esc_url( $file_url ); ?>" target="_blank"><?php echo esc_html( basename( $file_url ) ); ?></a></p>
                        <?php
                        endforeach;
                    else :
                        ?>
                        <p class="no-files"><?php esc_html_e( 'No production files uploaded yet.', 'vss' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Helper method to get carrier name
     */
    private static function get_carrier_name( $carrier_code ) {
        $carriers = [
            'usps' => 'USPS',
            'ups' => 'UPS',
            'fedex' => 'FedEx',
            'dhl' => 'DHL Global',
            'dhl_us' => 'DHL Express',
            'australia_post' => 'Australia Post',
            'royal_mail' => 'Royal Mail (UK)',
            'canada_post' => 'Canada Post',
            'other' => 'Other Carrier'
        ];

        return isset( $carriers[$carrier_code] ) ? $carriers[$carrier_code] : ucfirst( $carrier_code );
    }

    /**
     * Helper method to get tracking URL
     */
    private static function get_tracking_url( $carrier, $tracking_number ) {
        $tracking_urls = [
            'usps' => 'https://tools.usps.com/go/TrackConfirmAction_input?qtc_tLabels1=' . $tracking_number,
            'ups' => 'https://www.ups.com/track?loc=en_US&tracknum=' . $tracking_number,
            'fedex' => 'https://www.fedex.com/fedextrack/?tracknumbers=' . $tracking_number,
            'dhl' => 'https://www.dhl.com/en/express/tracking.html?AWB=' . $tracking_number,
            'dhl_us' => 'https://www.dhl.com/us-en/home/tracking.html?tracking-id=' . $tracking_number,
            'australia_post' => 'https://auspost.com.au/track/track.html?id=' . $tracking_number,
            'royal_mail' => 'https://www3.royalmail.com/track-your-item#/tracking-results/' . $tracking_number,
            'canada_post' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=' . $tracking_number,
        ];

        return isset( $tracking_urls[$carrier] ) ? $tracking_urls[$carrier] : '';
    }

    /**
     * Helper method to get approval status label
     */
    private static function get_approval_status_label( $status ) {
        $labels = [
            'none' => __( 'Not Submitted', 'vss' ),
            'pending' => __( 'Pending Review', 'vss' ),
            'pending_approval' => __( 'Pending Review', 'vss' ),
            'approved' => __( 'Approved', 'vss' ),
            'disapproved' => __( 'Changes Requested', 'vss' ),
        ];

        return isset( $labels[$status] ) ? $labels[$status] : ucfirst( $status );
    }

    



    /**
     * Note: get_secure_download_url() method should be implemented in the main VSS_Vendor class
     * not in this trait to avoid method collision with VSS_Vendor_Utilities trait.
     */
}