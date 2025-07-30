// Admin JavaScript for Vendor Order Manager
// File: assets/js/vss-admin.js

jQuery(document).ready(function($) {
    'use strict';
    
    // Handle mockup approval with improved UI feedback
    $(document).on('click', '.vss-approve-mockup', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var type = $button.data('type') || 'mockup';
        
        if (!confirm('Are you sure you want to approve this ' + type + '?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Processing...');
        $button.closest('tr').addClass('vss-loading');
        
        $.ajax({
            url: vss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_approve_mockup',
                order_id: orderId,
                type: type,
                nonce: vss_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    $button.replaceWith(response.data.status_html);
                    $button.closest('tr').removeClass('vss-loading');
                    
                    // Show success notification
                    showNotification(response.data.message, 'success');
                    
                    // Update any related status displays
                    updateOrderStatus(orderId, type, 'approved');
                } else {
                    showNotification('Error: ' + response.data.message, 'error');
                    $button.prop('disabled', false).text('Approve');
                    $button.closest('tr').removeClass('vss-loading');
                }
            },
            error: function() {
                showNotification('AJAX error. Please try again.', 'error');
                $button.prop('disabled', false).text('Approve');
                $button.closest('tr').removeClass('vss-loading');
            }
        });
    });
    
    // Handle mockup disapproval with reason dialog
    $(document).on('click', '.vss-disapprove-mockup', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var type = $button.data('type') || 'mockup';
        
        // Create custom dialog for disapproval reason
        var dialogHtml = '<div id="vss-disapproval-dialog" style="display:none;">' +
            '<p>Please provide a reason for disapproval:</p>' +
            '<textarea id="vss-disapproval-reason" style="width:100%;height:100px;"></textarea>' +
            '</div>';
        
        if (!$('#vss-disapproval-dialog').length) {
            $('body').append(dialogHtml);
        }
        
        $('#vss-disapproval-dialog').dialog({
            title: 'Disapproval Reason',
            modal: true,
            width: 400,
            buttons: {
                'Submit': function() {
                    var reason = $('#vss-disapproval-reason').val();
                    if (!reason.trim()) {
                        alert('Please provide a reason for disapproval.');
                        return;
                    }
                    
                    $(this).dialog('close');
                    
                    $button.prop('disabled', true).text('Processing...');
                    $button.closest('tr').addClass('vss-loading');
                    
                    $.ajax({
                        url: vss_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'vss_disapprove_mockup',
                            order_id: orderId,
                            type: type,
                            reason: reason,
                            nonce: vss_ajax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $button.replaceWith(response.data.status_html);
                                $button.closest('tr').removeClass('vss-loading');
                                showNotification(response.data.message, 'success');
                                updateOrderStatus(orderId, type, 'disapproved');
                            } else {
                                showNotification('Error: ' + response.data.message, 'error');
                                $button.prop('disabled', false).text('Disapprove');
                                $button.closest('tr').removeClass('vss-loading');
                            }
                        },
                        error: function() {
                            showNotification('AJAX error. Please try again.', 'error');
                            $button.prop('disabled', false).text('Disapprove');
                            $button.closest('tr').removeClass('vss-loading');
                        }
                    });
                },
                'Cancel': function() {
                    $(this).dialog('close');
                }
            }
        });
    });
    
    // Load vendor costs dynamically
    $(document).on('click', '.vss-load-costs', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var $container = $button.closest('.inside');
        
        $button.prop('disabled', true).text('Loading...');
        
        $.ajax({
            url: vss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_get_vendor_costs',
                order_id: orderId,
                nonce: vss_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var costsHtml = '<div class="vss-costs-display">';
                    
                    if (response.data.costs.line_items) {
                        costsHtml += '<h4>Item Costs:</h4><ul>';
                        $.each(response.data.costs.line_items, function(itemId, cost) {
                            costsHtml += '<li>Item #' + itemId + ': ' + formatCurrency(cost) + '</li>';
                        });
                        costsHtml += '</ul>';
                    }
                    
                    if (response.data.costs.shipping_cost) {
                        costsHtml += '<p><strong>Shipping:</strong> ' + formatCurrency(response.data.costs.shipping_cost) + '</p>';
                    }
                    
                    costsHtml += '<p class="total"><strong>Total:</strong> ' + response.data.formatted_total + '</p>';
                    costsHtml += '</div>';
                    
                    $container.html(costsHtml);
                } else {
                    $container.html('<p class="vss-error">' + response.data.message + '</p>');
                }
            },
            error: function() {
                $container.html('<p class="vss-error">Failed to load costs. Please try again.</p>');
                $button.prop('disabled', false).text('Load Costs');
            }
        });
    });
    
    // Bulk assign vendors
    $('#vss-bulk-assign').on('click', function(e) {
        e.preventDefault();
        
        var orderIds = [];
        $('.vss-order-checkbox:checked').each(function() {
            orderIds.push($(this).val());
        });
        
        if (orderIds.length === 0) {
            showNotification('Please select at least one order.', 'warning');
            return;
        }
        
        var vendorId = $('#vss-bulk-vendor-select').val();
        if (!vendorId || vendorId === '0') {
            showNotification('Please select a vendor.', 'warning');
            return;
        }
        
        if (!confirm('Assign ' + orderIds.length + ' orders to the selected vendor?')) {
            return;
        }
        
        var $button = $(this);
        $button.prop('disabled', true).text('Processing...');
        
        $.ajax({
            url: vss_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_bulk_assign_vendor',
                order_ids: orderIds,
                vendor_id: vendorId,
                nonce: vss_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification(response.data.message, 'success');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotification('Error: ' + response.data.message, 'error');
                    $button.prop('disabled', false).text('Bulk Assign');
                }
            },
            error: function() {
                showNotification('AJAX error. Please try again.', 'error');
                $button.prop('disabled', false).text('Bulk Assign');
            }
        });
    });
    
    // Select all checkboxes
    $('#vss-select-all').on('change', function() {
        $('.vss-order-checkbox').prop('checked', $(this).is(':checked'));
        updateBulkActionsVisibility();
    });
    
    $('.vss-order-checkbox').on('change', function() {
        updateBulkActionsVisibility();
    });
    
    // Initialize date range picker for reports
    if ($('#vss-date-range').length) {
        $('#vss-date-range').daterangepicker({
            locale: {
                format: 'YYYY-MM-DD'
            },
            startDate: moment().subtract(29, 'days'),
            endDate: moment(),
            ranges: {
                'Today': [moment(), moment()],
                'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                'This Month': [moment().startOf('month'), moment().endOf('month')],
                'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        });
    }
    
    // Initialize analytics chart
    if ($('#vss-analytics-chart').length && typeof Chart !== 'undefined') {
        initializeAnalyticsChart();
    }
    
    // Quick view order details
    $(document).on('click', '.vss-quick-view', function(e) {
        e.preventDefault();
        var orderId = $(this).data('order-id');
        
        // Implement quick view modal
        loadOrderQuickView(orderId);
    });
    
    // Export functionality
    $('#vss-export-csv').on('click', function(e) {
        e.preventDefault();
        var params = $(this).closest('form').serialize();
        window.location.href = vss_ajax.ajax_url + '?action=vss_export_csv&' + params + '&nonce=' + vss_ajax.nonce;
    });
    
    // Helper Functions
    
    function showNotification(message, type) {
        var typeClass = 'notice-' + type;
        if (type === 'error') typeClass = 'notice-error';
        if (type === 'warning') typeClass = 'notice-warning';
        
        var noticeHtml = '<div class="notice ' + typeClass + ' is-dismissible vss-notice">' +
            '<p>' + message + '</p>' +
            '<button type="button" class="notice-dismiss"></button>' +
            '</div>';
        
        $('.wrap h1').after(noticeHtml);
        
        // Auto dismiss after 5 seconds
        setTimeout(function() {
            $('.vss-notice').fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    function updateOrderStatus(orderId, type, status) {
        // Update any status displays on the page
        var $statusCell = $('[data-order-id="' + orderId + '"]').find('.vss-' + type + '-status');
        if ($statusCell.length) {
            var statusBadge = '<span class="vss-status-badge vss-status-' + status + '">' + 
                status.charAt(0).toUpperCase() + status.slice(1) + '</span>';
            $statusCell.html(statusBadge);
        }
    }
    
    function formatCurrency(amount) {
        return '$' + parseFloat(amount).toFixed(2);
    }
    
    function updateBulkActionsVisibility() {
        var checkedCount = $('.vss-order-checkbox:checked').length;
        if (checkedCount > 0) {
            $('.vss-bulk-actions').show();
            $('#vss-selected-count').text(checkedCount);
        } else {
            $('.vss-bulk-actions').hide();
        }
    }
    
    function initializeAnalyticsChart() {
        // This would be populated with actual data from PHP
        var ctx = document.getElementById('vss-analytics-chart').getContext('2d');
        var chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Orders',
                    data: [12, 19, 23, 25, 22, 30],
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    tension: 0.1
                }, {
                    label: 'Revenue',
                    data: [1200, 1900, 2300, 2500, 2200, 3000],
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    tension: 0.1,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Vendor Performance Analytics'
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                }
            }
        });
    }
    
    function loadOrderQuickView(orderId) {
        // Implement order quick view modal
        console.log('Loading quick view for order:', orderId);
    }
    
    // Initialize tooltips
    if ($.fn.tooltip) {
        $('.vss-tooltip').tooltip();
    }

// Media uploader for admin
    var mediaUploader;

    $(document).on('click', '#vss-upload-file-btn', function(e) {
        e.preventDefault();
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        mediaUploader = wp.media({
            title: vss_admin_vars.media_uploader.title,
            button: {
                text: vss_admin_vars.media_uploader.button_text
            },
            library: {
                type: 'application/zip'
            },
            multiple: false
        });

        mediaUploader.on('select', function() {
            var attachment = mediaUploader.state().get('selection').first().toJSON();
            $('#vss-attached-zip-id').val(attachment.id);
            $('#post').submit(); // Save the order to attach the file
        });

        mediaUploader.open();
    });

    $(document).on('click', '#vss-remove-file-btn', function(e) {
        e.preventDefault();
        if (confirm(vss_admin_vars.media_uploader.remove_confirm)) {
            $('#vss-attached-zip-id').val('');
            $('#vss-remove-zip-file-input').val('1');
            $('#post').submit(); // Save the order to remove the file
        }
    });
});