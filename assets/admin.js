jQuery(document).ready(function($) {
    let statusInterval;

    // Start Scan
    $('#mdc-start-scan').on('click', function() {
        if (!confirm('Start a new background scan? Existing results will be cleared.')) return;

        showLoader('Initializing scan...');
        $(this).prop('disabled', true);
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_start_scan',
                nonce: mdc_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    hideLoader();
                    alert('Error starting scan.');
                }
            },
            error: function() {
                hideLoader();
            }
        });
    });

    // Filter Change
    $('.mdc-filter-form select').on('change', function() {
        showLoader('Filtering results...');
    });

    // Pagination Click
    $(document).on('click', '.mdc-pagination a', function() {
        showLoader('Loading page...');
    });

    // Individual Trash
    $(document).on('click', '.mdc-trash-btn', function() {
        if (!confirm('Move this item to trash?')) return;

        const $btn = $(this);
        const $row = $btn.closest('tr');
        const id = $btn.data('id');

        $btn.prop('disabled', true).text('Trashing...');
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_trash_attachment',
                nonce: mdc_params.nonce,
                attachment_id: id
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data || 'Error trashing item.');
                    $btn.prop('disabled', false).text('Move to Trash');
                }
            }
        });
    });

    // Bulk Trash
    $(document).on('click', '#mdc-bulk-trash', function() {
        if (!confirm('Are you SURE you want to move ALL unused items to trash? This only affects items with 0 matches.')) return;

        showLoader('Bulk trashing items...');
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_bulk_trash',
                nonce: mdc_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Successfully trashed ' + response.data.count + ' items.');
                    location.reload();
                } else {
                    hideLoader();
                    alert(response.data || 'Error trashing items.');
                }
            },
            error: function() {
                hideLoader();
            }
        });
    });

    // Individual Restore
    $(document).on('click', '.mdc-restore-btn', function() {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const id = $btn.data('id');

        $btn.prop('disabled', true).text('Restoring...');
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_restore_attachment',
                nonce: mdc_params.nonce,
                attachment_id: id
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert(response.data || 'Error restoring item.');
                    $btn.prop('disabled', false).text('Restore');
                }
            }
        });
    });

    // Exclude Attachment
    $(document).on('click', '.mdc-exclude-btn', function() {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const id = $btn.data('id');

        $btn.prop('disabled', true).text('Excluding...');
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_exclude_attachment',
                nonce: mdc_params.nonce,
                attachment_id: id
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert('Error excluding item.');
                    $btn.prop('disabled', false).text('Exclude');
                }
            }
        });
    });

    // Include Attachment
    $(document).on('click', '.mdc-include-btn', function() {
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const id = $btn.data('id');

        $btn.prop('disabled', true).text('Including...');
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_include_attachment',
                nonce: mdc_params.nonce,
                attachment_id: id
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert('Error including item.');
                    $btn.prop('disabled', false).text('Include Again');
                }
            }
        });
    });

    // Selection Mode Toggle
    $('#mdc-toggle-selection').on('click', function() {
        const isModeOn = $(this).hasClass('mdc-selection-on');
        
        if (isModeOn) {
            cancelSelection();
        } else {
            $(this).addClass('mdc-selection-on').text('Exit Selection Mode');
            $('.mdc-col-cb').show();
            // Scroll to the table if needed
        }
    });

    // Cancel Selection
    $(document).on('click', '#mdc-cancel-selection', function() {
        cancelSelection();
    });

    function cancelSelection() {
        $('#mdc-toggle-selection').removeClass('mdc-selection-on').text('Bulk Select Mode');
        $('.mdc-col-cb').hide();
        $('.mdc-row-cb, #mdc-cb-select-all').prop('checked', false);
        updateBulkBar();
    }

    // Select All
    $(document).on('change', '#mdc-cb-select-all', function() {
        $('.mdc-row-cb').prop('checked', $(this).prop('checked'));
        updateBulkBar();
    });

    // Row Checkbox change
    $(document).on('change', '.mdc-row-cb', function() {
        updateBulkBar();
    });

    function updateBulkBar() {
        const count = $('.mdc-row-cb:checked').length;
        if (count > 0) {
            $('.mdc-selected-count').text(count + ' items selected');
            $('#mdc-bulk-actions-bar').fadeIn(200);
            
            // Show/Hide Include/Exclude buttons based on current filter
            const filter = new URLSearchParams(window.location.search).get('mdc_filter');
            if (filter === 'excluded') {
                $('#mdc-bulk-exclude-selected').hide();
                $('#mdc-bulk-include-selected').show();
            } else {
                $('#mdc-bulk-exclude-selected').show();
                $('#mdc-bulk-include-selected').hide();
            }
        } else {
            $('#mdc-bulk-actions-bar').fadeOut(200);
            $('#mdc-cb-select-all').prop('checked', false);
        }
    }

    // Bulk Actions handler
    $(document).on('click', '#mdc-bulk-trash-selected, #mdc-bulk-exclude-selected, #mdc-bulk-include-selected', function() {
        const $btn = $(this);
        const action = $btn.attr('id').split('-')[2]; // trash, exclude, include
        const ids = $('.mdc-row-cb:checked').map(function() { return $(this).val(); }).get();
        
        if (ids.length === 0) return;

        let confirmMsg = 'Apply action to ' + ids.length + ' items?';
        if (action === 'trash') confirmMsg = 'Move ' + ids.length + ' items to Trash?';
        
        if (!confirm(confirmMsg)) return;

        showLoader('Performing bulk ' + action + '...');
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_bulk_action_selected',
                nonce: mdc_params.nonce,
                ids: ids,
                bulk_action: action
            },
            success: function(response) {
                if (response.success) {
                    alert('Successfully processed ' + response.data.count + ' items.');
                    location.reload();
                } else {
                    hideLoader();
                    alert(response.data || 'Error performing bulk action.');
                }
            },
            error: function() {
                hideLoader();
            }
        });
    });

    function showLoader(text) {
        if (text) $('#mdc-loading-overlay p').text(text);
        $('#mdc-loading-overlay').css('display', 'flex').hide().fadeIn(200);
    }

    function hideLoader() {
        $('#mdc-loading-overlay').fadeOut(200);
    }

    // Stop Scan
    $(document).on('click', '#mdc-stop-scan', function() {
        showLoader('Stopping scan...');
        $(this).prop('disabled', true);
        
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_stop_scan',
                nonce: mdc_params.nonce
            },
            success: function(response) {
                location.reload();
            },
            error: function() {
                hideLoader();
            }
        });
    });

    // Match Toggle
    $(document).on('click', '.mdc-toggle-matches', function() {
        const id = $(this).data('id');
        $(`#mdc-matches-${id}`).slideToggle();
    });

    // Polling logic
    if ($('#mdc-start-scan').length && $('#mdc-status').text().includes('progress')) {
        $('#mdc-progress-bar-container').show();
        $('#mdc-progress-text').show();
        statusInterval = setInterval(checkStatus, 3000);
    }

    function checkStatus() {
        $.ajax({
            url: mdc_params.ajax_url,
            type: 'POST',
            data: {
                action: 'mdc_check_status',
                nonce: mdc_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#mdc-progress-bar').css('width', data.percent + '%');
                    $('#mdc-progress-text').text(`${data.offset} / ${data.total}`);
                    
                    if (data.status === 'idle') {
                        clearInterval(statusInterval);
                        location.reload();
                    }
                }
            }
        });
    }
});
