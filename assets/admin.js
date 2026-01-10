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
