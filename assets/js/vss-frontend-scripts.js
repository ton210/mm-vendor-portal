// Frontend JavaScript for Vendor Order Manager
// File: assets/js/vss-frontend-scripts.js

jQuery(document).ready(function($) {
    'use strict';

    console.log('VSS Frontend Scripts loaded');

    // Initialize tabs functionality - FIXED VERSION
    function initializeTabs() {
        console.log('initializeTabs called');

        // Handle order detail tabs specifically
        var $orderTabs = $('.vss-order-tabs');

        if ($orderTabs.length > 0) {
            console.log('Order tabs found');

            // Remove any existing handlers first
            $orderTabs.find('a.nav-tab').off('click.vss');

            // Add click handler for order tabs
            $orderTabs.find('a.nav-tab').on('click.vss', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $tab = $(this);
                var targetId = $tab.attr('href').replace('#', '');
                console.log('Tab clicked, target:', targetId);

                // Update active tab
                $orderTabs.find('a.nav-tab').removeClass('nav-tab-active');
                $tab.addClass('nav-tab-active');

                // Hide all tab contents
                $('.vss-tab-content').removeClass('vss-tab-active').hide();

                // Show target content
                $('#' + targetId).addClass('vss-tab-active').show();

                // Update URL hash without jumping
                if (history.replaceState) {
                    history.replaceState(null, null, $tab.attr('href'));
                }

                return false;
            });

            // Check for hash in URL and activate corresponding tab
            var hash = window.location.hash;
            if (hash && $(hash).length) {
                $orderTabs.find('a[href="' + hash + '"]').trigger('click.vss');
            } else {
                // Show first tab by default
                $('.vss-tab-content').hide();
                $('.vss-tab-content:first').show().addClass('vss-tab-active');
                $orderTabs.find('a.nav-tab:first').addClass('nav-tab-active');
            }
        }
    }

    // Initialize tabs on page load
    initializeTabs();

    // Also initialize after AJAX loads
    $(document).ajaxComplete(function(event, xhr, settings) {
        console.log('AJAX complete, reinitializing tabs');
        setTimeout(function() {
            initializeTabs();
        }, 100);
    });

    // Enhanced cost calculation with real-time updates
    function calculateTotalCostFrontend() {
        var total = 0;
        var hasInvalidInput = false;

        $('.vss-cost-input-fe').each(function() {
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

        var currency_symbol = $('#vss-total-cost-display-fe').data('currency') || '$';
        var formatted_total = currency_symbol + total.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        $('#vss-total-cost-display-fe').text(formatted_total);

        // Show/hide save button based on validation
        if (hasInvalidInput) {
            $('.vss-form-actions button[type="submit"]').prop('disabled', true);
        } else {
            $('.vss-form-actions button[type="submit"]').prop('disabled', false);
        }
    }

    // Initialize cost calculation
    if ($('.vss-cost-input-fe').length) {
        calculateTotalCostFrontend();
    }

    // Real-time cost updates
    $('body').on('keyup change paste', '.vss-cost-input-fe', function() {
        calculateTotalCostFrontend();
    });

    // Keyboard navigation for tabs
    $('.vss-order-tabs').on('keydown', '.nav-tab', function(e) {
        var $currentTab = $(this);
        var $tabs = $('.vss-order-tabs .nav-tab');
        var currentIndex = $tabs.index($currentTab);

        switch(e.keyCode) {
            case 37: // Left arrow
                if (currentIndex > 0) {
                    $tabs.eq(currentIndex - 1).focus().click();
                }
                break;
            case 39: // Right arrow
                if (currentIndex < $tabs.length - 1) {
                    $tabs.eq(currentIndex + 1).focus().click();
                }
                break;
            case 13: // Enter
            case 32: // Space
                e.preventDefault();
                $currentTab.click();
                break;
        }
    });

    // Enhanced datepicker with custom options
    if (typeof $.fn.datepicker === 'function') {
        $('.vss-datepicker-fe').datepicker({
            dateFormat: 'yy-mm-dd',
            minDate: 0,
            maxDate: '+3m',
            showButtonPanel: true,
            changeMonth: true,
            changeYear: true,
            beforeShowDay: function(date) {
                // Disable weekends
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

    // Form validation
    $('body').on('submit', 'form#vss_vendor_confirm_production_form', function(e) {
        var $form = $(this);
        var $dateInput = $('#vss_vendor_estimated_ship_date');

        if ($dateInput.val() === '') {
            e.preventDefault();
            showInlineError($dateInput, 'Please select an estimated ship date before confirming.');
            $dateInput.focus();
            return false;
        }

        if (!validateShipDate($dateInput)) {
            e.preventDefault();
            return false;
        }

        // Show loading state
        $form.find('button[type="submit"]').prop('disabled', true).text('Processing...');
    });

    // File upload preview with drag and drop
    var $fileInputs = $('input[type="file"]');

    $fileInputs.each(function() {
        var $input = $(this);
        var $dropZone = $('<div class="vss-file-drop-zone">' +
            '<p>Drag files here or click to browse</p>' +
            '</div>');

        $input.wrap('<div class="vss-file-upload-wrapper"></div>');
        $input.before($dropZone);
        $input.hide();

        // Click to browse
        $dropZone.on('click', function() {
            $input.click();
        });

        // Drag and drop events
        $dropZone.on('dragover dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('drag-over');
        });

        $dropZone.on('dragleave dragend', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');
        });

        $dropZone.on('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('drag-over');

            var files = e.originalEvent.dataTransfer.files;
            handleFileSelect(files, $input);
        });
    });

    $fileInputs.on('change', function(e) {
        handleFileSelect(e.target.files, $(this));
    });

    function handleFileSelect(files, $input) {
        var $wrapper = $input.closest('.vss-file-upload-wrapper');
        var $preview = $wrapper.find('.vss-file-preview');

        if (!$preview.length) {
            $preview = $('<div class="vss-file-preview"></div>');
            $wrapper.append($preview);
        }

        $preview.empty();

        var allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        var maxSize = 10 * 1024 * 1024; // 10MB

        for (var i = 0; i < files.length; i++) {
            var file = files[i];

            // Validate file type
            if (!allowedTypes.includes(file.type) && !file.type.match('image.*')) {
                showNotification('Invalid file type: ' + file.name, 'error');
                continue;
            }

            // Validate file size
            if (file.size > maxSize) {
                showNotification('File too large: ' + file.name + ' (max 10MB)', 'error');
                continue;
            }

            if (file.type.match('image.*')) {
                var reader = new FileReader();
                reader.onload = (function(theFile) {
                    return function(e) {
                        var $item = $('<div class="preview-item">' +
                            '<img src="' + e.target.result + '" />' +
                            '<span class="filename">' + theFile.name + '</span>' +
                            '<span class="remove-file" data-filename="' + theFile.name + '">×</span>' +
                            '</div>');
                        $preview.append($item);
                    };
                })(file);
                reader.readAsDataURL(file);
            } else {
                var $item = $('<div class="preview-item">' +
                    '<div class="file-icon">📄</div>' +
                    '<span class="filename">' + file.name + '</span>' +
                    '<span class="remove-file" data-filename="' + file.name + '">×</span>' +
                    '</div>');
                $preview.append($item);
            }
        }
    }

    // Remove file from preview
    $(document).on('click', '.remove-file', function() {
        $(this).closest('.preview-item').fadeOut(function() {
            $(this).remove();
        });
    });

    // Auto-save draft functionality
    var autoSaveTimer;
    var autoSaveData = {};

    $('.vss-auto-save').on('input change', function() {
        var $input = $(this);
        var fieldName = $input.attr('name');
        var fieldValue = $input.val();

        autoSaveData[fieldName] = fieldValue;

        clearTimeout(autoSaveTimer);

        var $status = $input.siblings('.vss-save-status');
        if (!$status.length) {
            $status = $('<span class="vss-save-status">Saving...</span>');
            $input.after($status);
        }

        $status.text('Saving...').show();

        autoSaveTimer = setTimeout(function() {
            // Save to localStorage as draft
            if (typeof(Storage) !== "undefined") {
                var orderId = $input.closest('form').find('input[name="order_id"]').val();
                var draftKey = 'vss_draft_' + orderId;
                localStorage.setItem(draftKey, JSON.stringify(autoSaveData));

                $status.text('Draft saved').css('color', '#4CAF50');
                setTimeout(function() {
                    $status.fadeOut();
                }, 2000);
            }
        }, 1000);
    });

    // Load draft data
    function loadDraftData() {
        if (typeof(Storage) !== "undefined") {
            var orderId = $('input[name="order_id"]').val();
            if (orderId) {
                var draftKey = 'vss_draft_' + orderId;
                var draftData = localStorage.getItem(draftKey);

                if (draftData) {
                    try {
                        var data = JSON.parse(draftData);
                        $.each(data, function(fieldName, fieldValue) {
                            $('[name="' + fieldName + '"]').val(fieldValue);
                        });
                        showNotification('Draft data loaded', 'info');
                    } catch (e) {
                        console.error('Error loading draft:', e);
                    }
                }
            }
        }
    }

    loadDraftData();

    // Manual Zakeke ZIP Fetch with improved UI
    $('body').on('click', '.vss-manual-fetch-zakeke-zip', function(e) {
        e.preventDefault();
        var $button = $(this);
        var orderId = $button.data('order-id');
        var itemId = $button.data('item-id');
        var zakekeDesignId = $button.data('zakeke-design-id');
        var $feedbackEl = $button.siblings('.vss-fetch-zip-feedback');

        $button.prop('disabled', true).html('<span class="spinner"></span> Fetching...');
        $feedbackEl.html('');

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
                    $feedbackEl.html('<span class="success">✓ ' + response.data.message + '</span>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $feedbackEl.html('<span class="error">✗ ' + response.data.message + '</span>');
                    $button.prop('disabled', false).text('Retry Fetch');
                }
            },
            error: function(xhr, status, error) {
                $feedbackEl.html('<span class="error">✗ Connection error. Please try again.</span>');
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

    // Responsive table handling
    function makeTablesResponsive() {
        $('.vss-orders-table, .vss-products-table').each(function() {
            var $table = $(this);
            if (!$table.parent().hasClass('table-responsive')) {
                $table.wrap('<div class="table-responsive"></div>');
            }
        });
    }

    makeTablesResponsive();

    // Print functionality for order details
    $('#vss-print-order').on('click', function(e) {
        e.preventDefault();
        window.print();
    });

    // Search/filter functionality
    $('#vss-order-search').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();

        $('.vss-orders-table tbody tr').each(function() {
            var $row = $(this);
            var text = $row.text().toLowerCase();

            if (text.indexOf(searchTerm) > -1) {
                $row.show();
            } else {
                $row.hide();
            }
        });
    });

    // Sticky header for long pages
    var $stickyHeader = $('.vss-sticky-header');
    if ($stickyHeader.length) {
        var headerOffset = $stickyHeader.offset().top;

        $(window).on('scroll', function() {
            if ($(window).scrollTop() > headerOffset) {
                $stickyHeader.addClass('is-sticky');
            } else {
                $stickyHeader.removeClass('is-sticky');
            }
        });
    }
});