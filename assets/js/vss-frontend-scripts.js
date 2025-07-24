/**
 * VSS Vendor Portal Frontend Scripts
 * Version: 7.0.0
 *
 * Handles all frontend functionality including:
 * - Tab navigation
 * - Cost calculations
 * - Form validations
 * - AJAX operations
 * - File fetching
 */

(function($) {
    'use strict';

    // Wait for DOM ready
    $(document).ready(function() {
        console.log('VSS Frontend Scripts v7.0.0 initializing...');

        // Check for required globals
        if (typeof vss_frontend_ajax === 'undefined') {
            console.error('VSS Error: vss_frontend_ajax is not defined. Script localization failed.');
            // Create fallback to prevent errors
            window.vss_frontend_ajax = {
                ajax_url: '/wp-admin/admin-ajax.php',
                nonce: '',
                debug: true
            };
        } else {
            console.log('VSS AJAX configuration loaded:', vss_frontend_ajax);
        }

        // Initialize all components
        initializeVendorTabs();
        initializeCostCalculations();
        initializeDatePickers();
        initializeFileHandlers();
        initializeNotifications();
        initializeFormValidation();
        initializeKeyboardShortcuts();

        // Enhanced Tab Functionality
        function initializeVendorTabs() {
            console.log('Initializing vendor tabs...');

            var $tabContainer = $('.vss-order-tabs');
            var $tabContents = $('.vss-tab-content');

            if ($tabContainer.length === 0) {
                console.log('No tab container found');
                return;
            }

            console.log('Found:', {
                containers: $tabContainer.length,
                tabs: $tabContainer.find('.nav-tab').length,
                contents: $tabContents.length
            });

            // Debug: List all tab contents
            $tabContents.each(function(index) {
                var id = $(this).attr('id');
                var title = $(this).data('tab-title') || 'Untitled';
                var hasContent = $(this).children().length > 0;
                console.log(`Tab content ${index}: #${id} - ${title} (has content: ${hasContent})`);
            });

            // Remove any existing handlers
            $tabContainer.off('click.vss-tabs');

            // Add click handler for tabs
            $tabContainer.on('click.vss-tabs', '.nav-tab', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $clickedTab = $(this);
                var targetHash = $clickedTab.attr('href');

                console.log('Tab clicked:', targetHash);

                if (!targetHash || targetHash === '#') {
                    console.error('Invalid tab target');
                    return false;
                }

                var targetId = targetHash.replace('#', '');
                var $targetContent = $('#' + targetId);

                if ($targetContent.length === 0) {
                    console.error('Target content not found:', targetId);
                    console.log('Available content IDs:', $tabContents.map(function() {
                        return this.id;
                    }).get());
                    return false;
                }

                // Update active states
                $tabContainer.find('.nav-tab').removeClass('nav-tab-active');
                $clickedTab.addClass('nav-tab-active');

                // Hide all contents and show target
                $tabContents.removeClass('vss-tab-active').hide();
                $targetContent.addClass('vss-tab-active').fadeIn(200);

                console.log('Switched to tab:', targetId);

                // Update URL hash without jumping
                if (history.replaceState) {
                    history.replaceState(null, null, targetHash);
                }

                // Trigger custom event
                $(document).trigger('vss:tab-changed', [targetId]);

                return false;
            });

            // Initialize active tab
            var $activeTab = $tabContainer.find('.nav-tab-active');
            if ($activeTab.length === 0) {
                var $firstTab = $tabContainer.find('.nav-tab').first();
                if ($firstTab.length > 0) {
                    console.log('No active tab found, activating first tab');
                    $firstTab.addClass('nav-tab-active');
                    var firstTargetId = $firstTab.attr('href').replace('#', '');
                    $tabContents.hide();
                    $('#' + firstTargetId).addClass('vss-tab-active').show();
                }
            } else {
                // Ensure active tab content is visible
                var activeHash = $activeTab.attr('href');
                if (activeHash) {
                    var activeId = activeHash.replace('#', '');
                    $tabContents.hide();
                    $('#' + activeId).addClass('vss-tab-active').show();
                }
            }

            // Handle URL hash on load
            if (window.location.hash) {
                var hash = window.location.hash;
                var $hashTab = $tabContainer.find('a[href="' + hash + '"]');
                if ($hashTab.length > 0) {
                    setTimeout(function() {
                        $hashTab.trigger('click.vss-tabs');
                    }, 100);
                }
            }

            console.log('Tab initialization complete');
        }

        // Cost Calculation System
        function initializeCostCalculations() {
            console.log('Initializing cost calculations...');

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

                // Get currency symbol
                var $display = $('#vss-total-cost-display-fe, #total_display');
                var currencySymbol = $display.data('currency') || '$';

                // Format total
                var formattedTotal = currencySymbol + total.toLocaleString(undefined, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                $display.text(formattedTotal);

                // Enable/disable submit button
                $('.vss-form-actions button[type="submit"]').prop('disabled', hasError);

                return total;
            }

            // Bind events
            $(document).on('keyup change paste', '.vss-cost-input-fe, .cost-item input[type="number"]', function() {
                calculateTotalCost();
            });

            // Initial calculation
            if ($('.vss-cost-input-fe, .cost-item input[type="number"]').length > 0) {
                calculateTotalCost();
            }
        }

        // Date Picker Initialization
        function initializeDatePickers() {
            console.log('Initializing date pickers...');

            if (typeof $.fn.datepicker !== 'function') {
                console.warn('jQuery UI Datepicker not loaded');
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
            console.log('Initializing file handlers...');

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
                $button.prop('disabled', true)
                       .html('<span class="vss-loading"></span> Fetching...');

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
                            var message = response.data && response.data.message
                                ? response.data.message
                                : 'Failed to fetch files. Please try again.';
                            showNotification(message, 'error');
                            $button.prop('disabled', false).text('Retry Fetch');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        showNotification('Connection error. Please try again.', 'error');
                        $button.prop('disabled', false).text('Retry Fetch');
                    }
                });
            });

            // File upload preview
            $(document).on('change', 'input[type="file"]', function() {
                var $input = $(this);
                var files = this.files;
                var $preview = $input.siblings('.file-preview');

                if (files.length > 0 && $preview.length === 0) {
                    $preview = $('<div class="file-preview"></div>');
                    $input.after($preview);
                }

                $preview.empty();

                Array.from(files).forEach(function(file) {
                    var $item = $('<div class="preview-item"></div>');
                    $item.text(file.name + ' (' + formatFileSize(file.size) + ')');
                    $preview.append($item);
                });
            });
        }

        // Notification System
        function initializeNotifications() {
            console.log('Initializing notifications...');

            // Close notification on click
            $(document).on('click', '.vss-notification .close', function() {
                $(this).closest('.vss-notification').fadeOut(300, function() {
                    $(this).remove();
                });
            });

            // Auto-hide notifications after 5 seconds
            $(document).on('vss:notification-shown', function(e, $notification) {
                setTimeout(function() {
                    $notification.find('.close').trigger('click');
                }, 5000);
            });
        }

        // Form Validation
        function initializeFormValidation() {
            console.log('Initializing form validation...');

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

            // File upload validation
            $('input[type="file"]').on('change', function() {
                var maxSize = 50 * 1024 * 1024; // 50MB
                var files = this.files;
                var hasError = false;

                for (var i = 0; i < files.length; i++) {
                    if (files[i].size > maxSize) {
                        showInlineError($(this), 'File "' + files[i].name + '" exceeds 50MB limit');
                        hasError = true;
                        break;
                    }
                }

                if (hasError) {
                    this.value = '';
                }
            });
        }

        // Keyboard Shortcuts
        function initializeKeyboardShortcuts() {
            console.log('Initializing keyboard shortcuts...');

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

                // Ctrl + S to save forms
                if (e.ctrlKey && e.keyCode === 83) {
                    e.preventDefault();
                    var $activeForm = $('.vss-tab-active form');
                    if ($activeForm.length) {
                        $activeForm.find('button[type="submit"]').trigger('click');
                    }
                }
            });

            // Tab keyboard navigation
            $('.vss-order-tabs').on('keydown', '.nav-tab', function(e) {
                var $currentTab = $(this);
                var $tabs = $('.vss-order-tabs .nav-tab');
                var currentIndex = $tabs.index($currentTab);

                switch(e.keyCode) {
                    case 37: // Left arrow
                        if (currentIndex > 0) {
                            e.preventDefault();
                            $tabs.eq(currentIndex - 1).focus().trigger('click');
                        }
                        break;
                    case 39: // Right arrow
                        if (currentIndex < $tabs.length - 1) {
                            e.preventDefault();
                            $tabs.eq(currentIndex + 1).focus().trigger('click');
                        }
                        break;
                    case 13: // Enter
                    case 32: // Space
                        e.preventDefault();
                        $currentTab.trigger('click');
                        break;
                }
            });
        }

        // Helper Functions
        function showNotification(message, type) {
            type = type || 'info';

            var $notification = $('<div class="vss-notification vss-notification-' + type + '">' +
                '<span class="message">' + escapeHtml(message) + '</span>' +
                '<span class="close">×</span>' +
                '</div>');

            $('body').append($notification);

            // Animate in
            setTimeout(function() {
                $notification.addClass('show');
            }, 10);

            $(document).trigger('vss:notification-shown', [$notification]);
        }

        function showInlineError($input, message) {
            clearInlineError($input);
            var $error = $('<span class="vss-inline-error">' + escapeHtml(message) + '</span>');
            $input.addClass('error').after($error);
        }

        function clearInlineError($input) {
            $input.removeClass('error');
            $input.siblings('.vss-inline-error').remove();
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
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

        // Public API
        window.vss = {
            version: '7.0.0',

            // Tab management
            tabs: {
                init: initializeVendorTabs,
                show: function(tabId) {
                    var $tab = $('.vss-order-tabs a[href="#' + tabId + '"]');
                    if ($tab.length) {
                        $tab.trigger('click');
                        console.log('Switched to tab:', tabId);
                        return true;
                    } else {
                        console.error('Tab not found:', tabId);
                        return false;
                    }
                },
                list: function() {
                    var tabs = [];
                    $('.vss-order-tabs .nav-tab').each(function() {
                        tabs.push({
                            text: $(this).text().trim(),
                            href: $(this).attr('href'),
                            isActive: $(this).hasClass('nav-tab-active')
                        });
                    });
                    return tabs;
                },
                debug: function() {
                    console.log('=== VSS Tab Debug Info ===');
                    console.log('Tab containers:', $('.vss-order-tabs').length);
                    console.log('Tab links:', $('.vss-order-tabs .nav-tab').length);
                    console.log('Tab contents:', $('.vss-tab-content').length);
                    console.log('Active tab:', $('.vss-order-tabs .nav-tab-active').attr('href'));
                    console.log('Visible content:', $('.vss-tab-content.vss-tab-active').attr('id'));

                    console.log('\nTab Status:');
                    $('.vss-order-tabs .nav-tab').each(function(i) {
                        var href = $(this).attr('href');
                        var targetExists = $(href).length > 0;
                        var isActive = $(this).hasClass('nav-tab-active');
                        console.log(`${i}: ${href} - Exists: ${targetExists}, Active: ${isActive}`);
                    });

                    console.log('\nContent Status:');
                    $('.vss-tab-content').each(function(i) {
                        var id = $(this).attr('id');
                        var isVisible = $(this).is(':visible');
                        var hasContent = $(this).children().length > 0;
                        console.log(`${i}: #${id} - Visible: ${isVisible}, Has content: ${hasContent}`);
                    });
                    console.log('=== End Debug Info ===');
                }
            },

            // Notifications
            notify: showNotification,

            // Utilities
            utils: {
                formatFileSize: formatFileSize,
                escapeHtml: escapeHtml,
                validateShipDate: validateShipDate
            },

            // Re-initialize everything
            reinit: function() {
                console.log('Re-initializing VSS components...');
                initializeVendorTabs();
                initializeCostCalculations();
                initializeDatePickers();
                initializeFileHandlers();
                initializeNotifications();
                initializeFormValidation();
                initializeKeyboardShortcuts();
            }
        };

        // Debug info
        console.log('VSS Frontend loaded successfully');
        console.log('Use vss.tabs.debug() for tab debugging');
        console.log('Use vss.tabs.show("tab-name") to switch tabs');
        console.log('Use vss.reinit() to re-initialize all components');

        // Re-init after AJAX operations
        $(document).ajaxComplete(function(event, xhr, settings) {
            // Only reinit for our own AJAX calls
            if (settings.url && settings.url.includes('vss_')) {
                setTimeout(function() {
                    initializeVendorTabs();
                    initializeDatePickers();
                }, 100);
            }
        });

        // Add debug class if in debug mode
        if (vss_frontend_ajax.debug) {
            $('body').addClass('vss-debug-mode');
        }
    });

})(jQuery);