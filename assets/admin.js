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
