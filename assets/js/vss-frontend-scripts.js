// Fixed Tab Functionality for VSS Vendor Portal
// File: assets/js/vss-frontend-scripts.js (UPDATED VERSION)

jQuery(document).ready(function($) {
    'use strict';

    console.log('VSS Frontend Scripts loaded');

    // Enhanced Tab Functionality - MAIN FIX
    function initializeVendorTabs() {
        console.log('Initializing vendor tabs...');

        var $tabContainer = $('.vss-order-tabs');
        var $tabContents = $('.vss-tab-content');

        if ($tabContainer.length === 0) {
            console.log('No tab container found');
            return;
        }

        console.log('Found tab container with', $tabContainer.find('.nav-tab').length, 'tabs');

        // Remove any existing handlers to prevent conflicts
        $tabContainer.off('click.vss-tabs');

        // Add click handler for tabs
        $tabContainer.on('click.vss-tabs', '.nav-tab', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $clickedTab = $(this);
            var targetHash = $clickedTab.attr('href');

            console.log('Tab clicked:', targetHash);

            if (!targetHash || targetHash === '#') {
                console.log('Invalid tab target');
                return false;
            }

            var targetId = targetHash.replace('#', '');
            var $targetContent = $('#' + targetId);

            if ($targetContent.length === 0) {
                console.log('Target content not found:', targetId);
                return false;
            }

            // Update active tab
            $tabContainer.find('.nav-tab').removeClass('nav-tab-active');
            $clickedTab.addClass('nav-tab-active');

            // Update content visibility
            $tabContents.removeClass('vss-tab-active').hide();
            $targetContent.addClass('vss-tab-active').show();

            console.log('Tab switched to:', targetId);

            // Update URL hash without jumping
            if (history.replaceState) {
                history.replaceState(null, null, targetHash);
            }

            return false;
        });

        // Initialize first tab if none is active
        var $activeTab = $tabContainer.find('.nav-tab-active');
        if ($activeTab.length === 0) {
            var $firstTab = $tabContainer.find('.nav-tab').first();
            if ($firstTab.length > 0) {
                console.log('No active tab found, activating first tab');
                $firstTab.trigger('click.vss-tabs');
            }
        } else {
            // Make sure the active tab's content is visible
            var activeHash = $activeTab.attr('href');
            if (activeHash) {
                var activeId = activeHash.replace('#', '');
                $tabContents.removeClass('vss-tab-active').hide();
                $('#' + activeId).addClass('vss-tab-active').show();
            }
        }

        // Check for hash in URL and activate corresponding tab
        var hash = window.location.hash;
        if (hash && $(hash).length > 0) {
            var $hashTab = $tabContainer.find('a[href="' + hash + '"]');
            if ($hashTab.length > 0) {
                console.log('Activating tab from URL hash:', hash);
                $hashTab.trigger('click.vss-tabs');
            }
        }

        console.log('Tab initialization complete');
    }

    // Initialize tabs on page load
    initializeVendorTabs();

    // Re-initialize after AJAX
    $(document).ajaxComplete(function() {
        setTimeout(initializeVendorTabs, 100);
    });

    // Cost calculation functionality
    function calculateTotalCostFrontend() {
        var total = 0;
        var hasInvalidInput = false;

        $('.vss-cost-input-fe, .cost-item input[type="number"]').each(function() {
            var $input = $(this);
            var val = $input.val().replace(/,/g, '.').replace(/[^0-9\.]/g, '');
            var numVal = parseFloat(val);

            if (val !== '' && isNaN(numVal)) {
                $input.addClass('error');
                hasInvalidInput = true;
            } else {
                $input.removeClass('error');
                if (!isNaN(numVal)) {
                    total += numVal;
                }
            }
        });

        var currency_symbol = $('#vss-total-cost-display-fe, #total_display').data('currency') || '$';
        var formatted_total = currency_symbol + total.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        $('#vss-total-cost-display-fe, #total_display').text(formatted_total);

        // Enable/disable save button
        $('.vss-form-actions button[type="submit"], .vss-costs-form button[type="submit"]').prop('disabled', hasInvalidInput);
    }

    // Initialize cost calculation
    if ($('.vss-cost-input-fe, .cost-item input[type="number"]').length) {
        calculateTotalCostFrontend();
    }

    // Real-time cost updates
    $('body').on('keyup change paste', '.vss-cost-input-fe, .cost-item input[type="number"]', function() {
        calculateTotalCostFrontend();
    });

    // Enhanced datepicker
    if (typeof $.fn.datepicker === 'function') {
        $('.vss-datepicker-fe, #estimated_ship_date').datepicker({
            dateFormat: 'yy-mm-dd',
            minDate: 0,
            maxDate: '+3m',
            showButtonPanel: true,
            changeMonth: true,
            changeYear: true,
            beforeShowDay: function(date) {
                var day = date.getDay();
                var isWeekend = (day === 0 || day === 6);
                return [!isWeekend, isWeekend ? 'ui-datepicker-unselectable' : ''];
            },
            onSelect: function(dateText) {
                $(this).trigger('change');
                validateShipDate($(this));
            }
        });
    }

    // Ship date validation
    function validateShipDate($input) {
        var selectedDate = $input.val();
        if (!selectedDate) return;

        var date = new Date(selectedDate);
        var today = new Date();
        today.setHours(0, 0, 0, 0);

        if (date < today) {
            showInlineError($input, 'Ship date cannot be in the past');
            return false;
        }

        clearInlineError($input);
        return true;
    }

    // Manual Zakeke ZIP Fetch
    $('body').on('click', '.vss-manual-fetch-zakeke-zip', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var itemId = $button.data('item-id');
        var zakekeDesignId = $button.data('zakeke-design-id');

        $button.prop('disabled', true).html('<span class="vss-loading"></span> Fetching...');

        $.ajax({
            url: vss_frontend_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'vss_manual_fetch_zip',
                _ajax_nonce: vss_frontend_ajax.nonce,
                order_id: orderId,
                item_id: itemId,
                primary_zakeke_design_id: zakekeDesignId
            },
            success: function(response) {
                if (response.success) {
                    var downloadLink = '<a href="' + response.data.zip_url + '" class="button button-small" target="_blank">Download Zakeke Files</a>';
                    $button.replaceWith(downloadLink);
                    showNotification('Zakeke files retrieved successfully!', 'success');
                } else {
                    showNotification(response.data.message || 'Failed to fetch files. Please try again.', 'error');
                    $button.prop('disabled', false).text('Retry Fetch');
                }
            },
            error: function(xhr, status, error) {
                showNotification('Connection error. Please try again.', 'error');
                $button.prop('disabled', false).text('Retry Fetch');
                console.error('AJAX Error:', error);
            }
        });
    });

    // Helper Functions
    function showNotification(message, type) {
        var $notification = $('<div class="vss-notification vss-notification-' + type + '">' +
            '<span class="message">' + message + '</span>' +
            '<span class="close">×</span>' +
            '</div>');

        $('body').append($notification);

        $notification.animate({ right: '20px' }, 300);

        setTimeout(function() {
            $notification.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);
    }

    $(document).on('click', '.vss-notification .close', function() {
        $(this).closest('.vss-notification').fadeOut(function() {
            $(this).remove();
        });
    });

    function showInlineError($input, message) {
        clearInlineError($input);
        var $error = $('<span class="vss-inline-error">' + message + '</span>');
        $input.addClass('error').after($error);
    }

    function clearInlineError($input) {
        $input.removeClass('error');
        $input.siblings('.vss-inline-error').remove();
    }

    // Keyboard navigation for tabs
    $('.vss-order-tabs').on('keydown', '.nav-tab', function(e) {
        var $currentTab = $(this);
        var $tabs = $('.vss-order-tabs .nav-tab');
        var currentIndex = $tabs.index($currentTab);

        switch(e.keyCode) {
            case 37: // Left arrow
                if (currentIndex > 0) {
                    $tabs.eq(currentIndex - 1).focus().trigger('click.vss-tabs');
                }
                break;
            case 39: // Right arrow
                if (currentIndex < $tabs.length - 1) {
                    $tabs.eq(currentIndex + 1).focus().trigger('click.vss-tabs');
                }
                break;
            case 13: // Enter
            case 32: // Space
                e.preventDefault();
                $currentTab.trigger('click.vss-tabs');
                break;
        }
    });

    // Debug function to check tab setup
    window.vssDebugTabs = function() {
        console.log('=== VSS Tab Debug Info ===');
        console.log('Tab containers:', $('.vss-order-tabs').length);
        console.log('Tab links:', $('.vss-order-tabs .nav-tab').length);
        console.log('Tab contents:', $('.vss-tab-content').length);

        $('.vss-order-tabs .nav-tab').each(function(i) {
            var href = $(this).attr('href');
            var targetExists = $(href).length > 0;
            console.log('Tab ' + i + ': ' + href + ' -> Target exists: ' + targetExists);
        });
    };

    // Auto-run debug in development
    if (window.location.hostname === 'localhost' || window.location.hostname.includes('dev')) {
        setTimeout(window.vssDebugTabs, 1000);
    }
});