// Vendor Admin JavaScript for VSS Plugin
// Handles expandable order details and Zakeke file fetching

jQuery(document).ready(function($) {
    'use strict';
    
    // Initialize any datepickers if needed
    if ($.fn.datepicker) {
        $('.vss-datepicker').datepicker({
            dateFormat: 'yy-mm-dd',
            minDate: 0
        });
    }
    
    // Handle order details expansion
    $(document).on('click', '.vss-expand-trigger', function(e) {
        e.preventDefault();
        var $trigger = $(this);
        var orderId = $trigger.data('order-id');
        var $detailsRow = $('#vss-details-' + orderId);
        var $container = $detailsRow.find('.vss-order-details-content');
        
        if ($detailsRow.is(':visible')) {
            // Hide the details
            $detailsRow.slideUp(200);
            $trigger.text(vss_vendor_ajax.expand_text || 'Show Details');
        } else {
            // Show the details
            if (!$detailsRow.data('loaded')) {
                // Load details via AJAX if not already loaded
                $container.html('<div class="vss-loading-container"><span class="vss-loading"></span> Loading order details...</div>');
                
                $.ajax({
                    url: vss_vendor_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'vss_expand_order_row',
                        order_id: orderId,
                        nonce: vss_vendor_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $container.html(response.data.html);
                            $detailsRow.data('loaded', true);
                            
                            // Initialize any new elements
                            initializeOrderDetails($detailsRow);
                        } else {
                            $container.html('<div class="notice notice-error"><p>' + (response.data.message || 'Failed to load order details.') + '</p></div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        $container.html('<div class="notice notice-error"><p>Connection error. Please try again.</p></div>');
                    }
                });
            }
            
            $detailsRow.slideDown(200);
            $trigger.text(vss_vendor_ajax.collapse_text || 'Hide Details');
        }
    });
    
    // Handle Zakeke file fetching
    $(document).on('click', '.vss-fetch-zakeke-btn', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var itemId = $button.data('item-id');
        var designId = $button.data('design-id');
        
        if (!orderId || !itemId || !designId) {
            alert('Missing required information to fetch files.');
            return;
        }
        
        // Disable button and show loading
        $button.prop('disabled', true)
               .html('<span class="vss-loading"></span> Fetching...')
               .addClass('updating-message');
        
        $.ajax({
            url: vss_vendor_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_manual_fetch_zip',
                order_id: orderId,
                item_id: itemId,
                primary_zakeke_design_id: designId,
                _ajax_nonce: vss_vendor_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Success - show message and update UI
                    $button.replaceWith(
                        '<a href="' + response.data.zip_url + '" class="button button-small" target="_blank">Download</a>' +
                        '<span class="vss-success-message" style="color: #46b450; margin-left: 10px;">✓ ' + response.data.message + '</span>'
                    );
                    
                    // Update the file status in the main table row
                    var $mainRow = $('tr:has(.vss-expand-trigger[data-order-id="' + orderId + '"])');
                    var $fileCell = $mainRow.find('td:nth-child(6)'); // Files column
                    
                    // Check if all Zakeke files are now fetched
                    var $detailsRow = $('#vss-details-' + orderId);
                    if ($detailsRow.find('.vss-fetch-zakeke-btn').length === 0) {
                        // All files fetched - update status
                        $fileCell.find('.needs-fetch').removeClass('needs-fetch').addClass('has-zakeke').text('Zakeke');
                    }
                    
                    // Show success notification
                    showNotification('Zakeke files retrieved successfully!', 'success');
                } else {
                    // Error - show message and re-enable button
                    var errorMsg = response.data.message || 'Failed to fetch files. Please try again.';
                    alert(errorMsg);
                    
                    $button.prop('disabled', false)
                           .removeClass('updating-message')
                           .text('Retry Fetch');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                alert('Connection error. Please check your internet connection and try again.');
                
                $button.prop('disabled', false)
                       .removeClass('updating-message')
                       .text('Retry Fetch');
            }
        });
    });
    
    // Initialize elements in order details
    function initializeOrderDetails($container) {
        // Add hover effects to download links
        $container.find('a.button').hover(
            function() { $(this).addClass('button-primary'); },
            function() { $(this).removeClass('button-primary'); }
        );
        
        // Format file sizes if present
        $container.find('.file-size').each(function() {
            var bytes = parseInt($(this).data('bytes'));
            if (bytes) {
                $(this).text(formatFileSize(bytes));
            }
        });
    }
    
    // Show notification message
    function showNotification(message, type) {
        type = type || 'info';
        
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible vss-notification">' +
                       '<p>' + message + '</p>' +
                       '<button type="button" class="notice-dismiss"></button>' +
                       '</div>');
        
        // Add after page title
        $('.wrap h1').after($notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
        
        // Handle manual dismiss
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });
    }
    
    // Format file size from bytes
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    // Handle bulk file operations (future enhancement)
    $(document).on('click', '.vss-bulk-fetch-files', function(e) {
        e.preventDefault();
        
        if (!confirm('Fetch all missing Zakeke files for visible orders? This may take a while.')) {
            return;
        }
        
        var $buttons = $('.vss-fetch-zakeke-btn:visible');
        var total = $buttons.length;
        var current = 0;
        
        if (total === 0) {
            showNotification('No files to fetch.', 'info');
            return;
        }
        
        showNotification('Fetching ' + total + ' file(s)... Please wait.', 'info');
        
        // Process buttons sequentially to avoid overwhelming the server
        function fetchNext() {
            if (current >= total) {
                showNotification('Finished fetching files!', 'success');
                return;
            }
            
            var $button = $($buttons[current]);
            current++;
            
            // Trigger click on the fetch button
            $button.click();
            
            // Wait a bit before processing next
            setTimeout(fetchNext, 2000);
        }
        
        fetchNext();
    });
    
    // Add keyboard shortcuts
    $(document).on('keydown', function(e) {
        // ESC key closes expanded details
        if (e.keyCode === 27) {
            $('.vss-order-details-row:visible').each(function() {
                var orderId = $(this).attr('id').replace('vss-details-', '');
                $('.vss-expand-trigger[data-order-id="' + orderId + '"]').click();
            });
        }
    });
    
    // Add loading styles dynamically
    if ($('#vss-vendor-loading-styles').length === 0) {
        $('head').append(
            '<style id="vss-vendor-loading-styles">' +
            '.vss-loading-container { text-align: center; padding: 20px; }' +
            '.vss-notification { margin: 10px 0; }' +
            '.vss-success-message { display: inline-block; animation: fadeIn 0.5s; }' +
            '@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }' +
            '.button.updating-message { opacity: 0.7; cursor: wait; }' +
            '</style>'
        );
    }
});