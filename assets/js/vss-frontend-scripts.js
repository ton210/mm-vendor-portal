/**
 * VSS Vendor Portal Frontend Scripts - FIXED VERSION
 * Version: 7.0.2
 *
 * This version fixes the initialization issues and tab functionality
 */

(function($) {
    'use strict';

    // Create global VSS object immediately
    window.VSS = window.VSS || {};
    window.vss = window.vss || {}; // Also create lowercase version for compatibility

    console.log('VSS: Frontend scripts loading...');

    // Initialize everything when DOM is ready
    $(document).ready(function() {
        console.log('VSS: Document ready, initializing...');
        initializeVSSFrontend();
    });

    function initializeVSSFrontend() {
        console.log('VSS: Starting initialization...');

        // Check for required globals
        if (typeof vss_frontend_ajax === 'undefined') {
            console.error('VSS Error: vss_frontend_ajax is not defined. Creating fallback...');
            window.vss_frontend_ajax = {
                ajax_url: '/wp-admin/admin-ajax.php',
                nonce: '',
                debug: true
            };
        }

        // Initialize all components
        try {
            initializeVendorTabs();
            initializeCostCalculations();
            initializeDatePickers();
            initializeFileHandlers();
            initializeNotifications();
            initializeFormValidation();
            initializeKeyboardShortcuts();

            console.log('VSS: All components initialized successfully');
        } catch (error) {
            console.error('VSS: Error during initialization:', error);
        }
    }

    // Enhanced Tab Functionality with better error handling
    function initializeVendorTabs() {
        console.log('VSS: Initializing vendor tabs...');

        // Find all tab containers (support multiple instances)
        $('.vss-order-tabs').each(function() {
            var $tabContainer = $(this);
            var tabsId = $tabContainer.attr('id') || 'tabs-' + Math.random().toString(36).substr(2, 9);

            if (!$tabContainer.attr('id')) {
                $tabContainer.attr('id', tabsId);
            }

            // Find related tab contents
            var $tabContents = $tabContainer.nextAll('.vss-tab-content');

            console.log('VSS: Found tab container:', {
                id: tabsId,
                tabs: $tabContainer.find('.nav-tab').length,
                contents: $tabContents.length
            });

            // Hide all tab contents except the active one
            $tabContents.not('.vss-tab-active').hide();

            // Remove any existing handlers to prevent duplicates
            $tabContainer.off('click.vss-tabs');

            // Add click handler for tabs
            $tabContainer.on('click.vss-tabs', '.nav-tab', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $clickedTab = $(this);
                var targetHash = $clickedTab.attr('href');

                console.log('VSS: Tab clicked:', targetHash);

                if (!targetHash || targetHash === '#') {
                    console.error('VSS: Invalid tab target');
                    return false;
                }

                // Handle both ID and hash formats
                var targetId = targetHash.replace('#', '');
                var $targetContent = $('#' + targetId);

                // If not found by ID, try by class
                if ($targetContent.length === 0) {
                    $targetContent = $('.vss-tab-content[data-tab="' + targetId + '"]');
                }

                if ($targetContent.length === 0) {
                    console.error('VSS: Target content not found:', targetId);
                    return false;
                }

                // Update active states
                $tabContainer.find('.nav-tab').removeClass('nav-tab-active');
                $clickedTab.addClass('nav-tab-active');

                // Hide all contents and show target with animation
                $tabContents.removeClass('vss-tab-active').hide();
                $targetContent.addClass('vss-tab-active').fadeIn(200);

                console.log('VSS: Switched to tab:', targetId);

                // Update URL hash without jumping
                if (history.replaceState) {
                    history.replaceState(null, null, targetHash);
                }

                // Trigger custom event
                $(document).trigger('vss:tab-changed', [targetId]);

                return false;
            });

            // Initialize first tab if no active tab
            var $activeTab = $tabContainer.find('.nav-tab-active');
            if ($activeTab.length === 0) {
                console.log('VSS: No active tab found, activating first tab');
                var $firstTab = $tabContainer.find('.nav-tab').first();
                if ($firstTab.length > 0) {
                    $firstTab.addClass('nav-tab-active');
                    var firstTargetId = $firstTab.attr('href').replace('#', '');
                    $('#' + firstTargetId).addClass('vss-tab-active').show();
                }
            } else {
                // Ensure active tab content is visible
                var activeTargetId = $activeTab.attr('href').replace('#', '');
                $('#' + activeTargetId).show();
            }
        });

        // Handle direct URL hash on page load
        if (window.location.hash) {
            setTimeout(function() {
                var $hashTab = $('.vss-order-tabs a[href="' + window.location.hash + '"]');
                if ($hashTab.length > 0) {
                    $hashTab.trigger('click.vss-tabs');
                }
            }, 100);
        }

        console.log('VSS: Tab initialization complete');
    }

    // Cost Calculation System
    function initializeCostCalculations() {
        console.log('VSS: Initializing cost calculations...');

        function calculateTotalCost() {
            var total = 0;
            var hasError = false;

            $('.vss-cost-input-fe, .cost-item input[type="number"]').each(function() {
                var $input = $(this);
                var value = parseFloat($input.val()) || 0;

                if (value < 0) {
                    $input.addClass('error');
                    hasError = true;
                } else {
                    $input.removeClass('error');
                    total += value;
                }
            });

            // Update display
            var $display = $('#vss-total-cost-display-fe, #total_display');
            var currencySymbol = $display.data('currency') || '$';
            var formattedTotal = currencySymbol + total.toFixed(2);
            $display.text(formattedTotal);

            // Enable/disable submit based on errors
            $('.vss-form-actions button[type="submit"]').prop('disabled', hasError);

            return total;
        }

        // Bind events
        $(document).on('input change', '.vss-cost-input-fe, .cost-item input[type="number"]', function() {
            calculateTotalCost();
        });

        // Initial calculation
        if ($('.vss-cost-input-fe, .cost-item input[type="number"]').length > 0) {
            calculateTotalCost();
        }
    }

    // Date Picker Initialization
    function initializeDatePickers() {
        console.log('VSS: Initializing date pickers...');

        if (typeof $.fn.datepicker !== 'function') {
            console.warn('VSS: jQuery UI Datepicker not loaded');
            return;
        }

        $('.vss-datepicker-fe, #estimated_ship_date').each(function() {
            var $input = $(this);

            $input.datepicker({
                dateFormat: 'yy-mm-dd',
                minDate: 0,
                maxDate: '+3m',
                showButtonPanel: true,
                changeMonth: true,
                changeYear: true,
                beforeShowDay: function(date) {
                    var day = date.getDay();
                    // Disable weekends
                    return [(day !== 0 && day !== 6), ''];
                },
                onSelect: function(dateText, inst) {
                    $(this).trigger('change');
                    validateShipDate($(this));
                }
            });
        });
    }

    // Ship Date Validation
    function validateShipDate($input) {
        var selectedDate = $input.val();
        if (!selectedDate) return true;

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

    // File Handlers (Zakeke ZIP fetching)
    function initializeFileHandlers() {
        console.log('VSS: Initializing file handlers...');

        $(document).on('click', '.vss-manual-fetch-zakeke-zip', function(e) {
            e.preventDefault();

            var $button = $(this);
            var originalText = $button.text();
            var orderId = $button.data('order-id');
            var itemId = $button.data('item-id');
            var zakekeDesignId = $button.data('zakeke-design-id');

            if (!orderId || !itemId || !zakekeDesignId) {
                showNotification('Missing required data for file fetch', 'error');
                return;
            }

            // Disable button and show loading
            $button.prop('disabled', true).text('Fetching...');

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
                        // Replace button with download link
                        var $downloadLink = $('<a>')
                            .attr('href', response.data.zip_url)
                            .attr('target', '_blank')
                            .addClass('button button-small')
                            .text('Download Zakeke Files');

                        $button.replaceWith($downloadLink);
                        showNotification('Zakeke files retrieved successfully!', 'success');
                    } else {
                        showNotification(response.data.message || 'Failed to fetch files', 'error');
                        $button.prop('disabled', false).text('Retry Fetch');
                    }
                },
                error: function() {
                    showNotification('Connection error. Please try again.', 'error');
                    $button.prop('disabled', false).text('Retry Fetch');
                }
            });
        });
    }

    // Notification System
    function initializeNotifications() {
        console.log('VSS: Initializing notifications...');

        // Close notification on click
        $(document).on('click', '.vss-notification .close', function() {
            $(this).closest('.vss-notification').fadeOut(300, function() {
                $(this).remove();
            });
        });
    }

    // Form Validation
    function initializeFormValidation() {
        console.log('VSS: Initializing form validation...');

        // Production confirmation form
        $('form.vss-production-form').on('submit', function(e) {
            var $form = $(this);
            var $dateInput = $form.find('#estimated_ship_date');

            if (!validateShipDate($dateInput)) {
                e.preventDefault();
                $dateInput.focus();
                return false;
            }
        });

        // Cost form validation
        $('form.vss-costs-form').on('submit', function(e) {
            var hasError = false;

            $(this).find('input[type="number"]').each(function() {
                var value = parseFloat($(this).val()) || 0;
                if (value < 0) {
                    hasError = true;
                    showInlineError($(this), 'Value cannot be negative');
                }
            });

            if (hasError) {
                e.preventDefault();
                return false;
            }
        });
    }

    // Keyboard Shortcuts
    function initializeKeyboardShortcuts() {
        console.log('VSS: Initializing keyboard shortcuts...');

        $(document).on('keydown', function(e) {
            // Don't trigger if typing in input
            if ($(e.target).is('input, textarea, select')) {
                return;
            }

            // Alt + 1-8 for tabs
            if (e.altKey && e.keyCode >= 49 && e.keyCode <= 56) {
                e.preventDefault();
                var tabIndex = e.keyCode - 49;
                var $tab = $('.vss-order-tabs .nav-tab').eq(tabIndex);
                if ($tab.length) {
                    $tab.trigger('click');
                }
            }
        });
    }

    // Helper Functions
    function showNotification(message, type) {
        type = type || 'info';

        // Remove existing notifications
        $('.vss-notification').remove();

        var $notification = $('<div class="vss-notification vss-notification-' + type + '">' +
            '<span class="message">' + escapeHtml(message) + '</span>' +
            '<span class="close">×</span>' +
            '</div>');

        $('body').append($notification);

        // Position it
        $notification.css({
            position: 'fixed',
            top: '20px',
            right: '20px',
            padding: '15px 20px',
            background: type === 'success' ? '#4CAF50' : '#f44336',
            color: 'white',
            borderRadius: '4px',
            boxShadow: '0 2px 5px rgba(0,0,0,0.2)',
            zIndex: 9999,
            minWidth: '250px'
        });

        // Auto-hide after 5 seconds
        setTimeout(function() {
            $notification.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    function showInlineError($input, message) {
        clearInlineError($input);
        var $error = $('<span class="vss-inline-error">' + escapeHtml(message) + '</span>');
        $error.css({
            color: '#f44336',
            fontSize: '12px',
            display: 'block',
            marginTop: '5px'
        });
        $input.addClass('error').after($error);
    }

    function clearInlineError($input) {
        $input.removeClass('error');
        $input.siblings('.vss-inline-error').remove();
    }

    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // Public API - Make sure this is available globally
    window.vss = {
        version: '7.0.2',
        tabs: {
            init: initializeVendorTabs,
            show: function(tabId) {
                var $tab = $('.vss-order-tabs a[href="#' + tabId + '"]');
                if ($tab.length) {
                    $tab.trigger('click');
                    return true;
                }
                return false;
            }
        },
        notify: showNotification,
        reinit: initializeVSSFrontend,
        calculateCosts: initializeCostCalculations,
        initDatePickers: initializeDatePickers
    };

    // Also expose on VSS object for backward compatibility
    window.VSS = window.vss;

    console.log('VSS: Frontend script loaded successfully, window.vss available:', typeof window.vss !== 'undefined');

})(jQuery);