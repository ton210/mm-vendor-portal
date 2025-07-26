<?php
/**
 * Enhanced Costs Section
 * Add this method to replace the existing render_costs_section
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