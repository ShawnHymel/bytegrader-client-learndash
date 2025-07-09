jQuery(document).ready(function($) {
    console.log('🚀 Project submission interface loaded');
    
    let selectedFile = null;
    let defaultFileSizeMB = 10; // Default max file size in MB
    
    // Polling variables
    let pollingInterval = null;
    let pollingJobId = null;
    let pollingUsername = null;
    let pollingStartTime = null;
    let pollingAttempts = 0;
    const MAX_POLLING_ATTEMPTS = 60; // 5 minutes at 5-second intervals
    const POLLING_INTERVAL_MS = 5000; // 5 seconds

    // See if the file size is less than or equal to the specified size
    function validateFileSize(file, maxSizeMB) {
        const maxBytes = maxSizeMB * 1024 * 1024;
        return file.size <= maxBytes;
    }
    
    // Start polling for job status
    function startPolling(jobId, username, quizId) {
        console.log('📊 Starting status polling for job:', jobId);
        
        pollingJobId = jobId;
        pollingUsername = username;
        pollingStartTime = Date.now();
        pollingAttempts = 0;
        
        // Check immediately
        checkJobStatus();
        
        // Then check every 5 seconds
        pollingInterval = setInterval(checkJobStatus, POLLING_INTERVAL_MS);
    }
    
    // Stop polling
    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
            console.log('⏹️ Stopped status polling');
        }
    }
    
    // Check job status via AJAX
    function checkJobStatus() {
        if (!pollingJobId) return;
        
        pollingAttempts++;
        const elapsedSeconds = Math.floor((Date.now() - pollingStartTime) / 1000);
        
        console.log(`🔍 Checking job status (attempt ${pollingAttempts}, ${elapsedSeconds}s elapsed)`);
        
        $.ajax({
            url: bgcld_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'bgcld_check_job_status',
                nonce: bgcld_ajax.nonce,
                job_id: pollingJobId,
                username: pollingUsername,
                quiz_id: bgcld_ajax.quiz_id
            },
            success: function(response) {
                console.log('📨 Status check response:', response);
                handleStatusResponse(response, elapsedSeconds);
            },
            error: function(xhr, status, error) {
                console.error('❌ Status check failed:', error);
                const statusMsg = $('.bgcld-message');
                statusMsg.html(`⚠️ Connection error while checking status. Retrying...`);
                
                // Continue polling unless we've exceeded max attempts
                if (pollingAttempts >= MAX_POLLING_ATTEMPTS) {
                    stopPolling();
                    showFinalError('Status check failed after multiple attempts. Please refresh and try again.');
                }
            }
        });
    }
    
    // Handle the status response
    function handleStatusResponse(response, elapsedSeconds) {
        const statusDiv = $('.bgcld-status');
        const statusMsg = $('.bgcld-message');
        
        if (!response.success) {
            console.error('❌ Status check error:', response.data);
            statusMsg.html(`❌ Error: ${response.data}`);
            
            // Stop polling on permanent errors
            if (response.data.includes('not found') || response.data.includes('Access denied')) {
                stopPolling();
                statusDiv.removeClass('processing').addClass('error');
            }
            return;
        }
        
        const jobData = response.data;
        const status = jobData.status;
        
        console.log(`📋 Job status: ${status}`);
        
        switch (status) {
            case 'queued':
                let queueMsg = `⏳ Job queued for grading... (${elapsedSeconds}s elapsed)`;
                
                // Add queue information if available
                if (jobData.queue_info) {
                    const queueInfo = jobData.queue_info;
                    const queueLength = queueInfo.queue_length || 0;
                    const activeJobs = queueInfo.active_jobs || 0;
                    const maxConcurrent = queueInfo.max_concurrent || 1;
                    
                    if (queueLength > 1) {
                        // Multiple people ahead
                        queueMsg = `⏳ ${queueLength - 1} submissions ahead of you (${elapsedSeconds}s elapsed)`;
                    } else if (queueLength === 1) {
                        // You're next
                        queueMsg = `⏳ You're next in line! (${elapsedSeconds}s elapsed)`;
                    } else if (activeJobs >= maxConcurrent) {
                        // No queue, but graders are busy
                        queueMsg = `⏳ Grader is busy, starting soon... (${elapsedSeconds}s elapsed)`;
                    } else {
                        // Should start immediately
                        queueMsg = `⏳ Starting grading now... (${elapsedSeconds}s elapsed)`;
                    }
                    
                    // Keep detailed info in console for debugging
                    console.log(`📊 Queue details: ${queueLength} queued, ${activeJobs}/${maxConcurrent} active graders`);
                }
                
                statusMsg.html(queueMsg);
                break;
                
            case 'processing':
                statusMsg.html(`⚙️ Grading your project... (${elapsedSeconds}s elapsed)`);
                break;
                
            case 'completed':
                console.log('✅ Grading completed!');
                showCompletedResult(jobData, elapsedSeconds);
                stopPolling();
                break;
                
            case 'failed':
                console.log('❌ Grading failed');
                showFailedResult(jobData, elapsedSeconds);
                stopPolling();
                break;
                
            default:
                statusMsg.html(`❓ Unknown status: ${status} (${elapsedSeconds}s elapsed)`);
                break;
        }
        
        // Stop polling if we've exceeded max attempts
        if (pollingAttempts >= MAX_POLLING_ATTEMPTS) {
            stopPolling();
            showFinalError('Grading is taking longer than expected. Please refresh the page to check your results.');
        }
    }
    
    // Show completed result
    function showCompletedResult(jobData, elapsedSeconds) {
        const statusDiv = $('.bgcld-status');
        const statusMsg = $('.bgcld-message');
        
        statusDiv.removeClass('processing').addClass('success');
        
        let html = `✅ Grading completed in ${elapsedSeconds} seconds!<br>`;
        html += `<strong>Score: ${jobData.score}%</strong>`;
        
        if (jobData.feedback) {
            html += `<br><br><details style="margin-top: 10px;"><summary><strong>Detailed Feedback</strong></summary>`;
            html += `<pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 12px; white-space: pre-wrap; max-height: 200px; overflow-y: auto;">${jobData.feedback}</pre>`;
            html += `</details>`;
        }
        
        statusMsg.html(html);
        
        // Reload page after 3 seconds to show LearnDash completion state
        setTimeout(() => {
            console.log('🔄 Reloading page to show completion state...');
            location.reload();
        }, 3000);
    }
    
    // Show failed result
    function showFailedResult(jobData, elapsedSeconds) {
        const statusDiv = $('.bgcld-status');
        const statusMsg = $('.bgcld-message');
        
        statusDiv.removeClass('processing').addClass('error');
        
        let html = `❌ Grading failed after ${elapsedSeconds} seconds`;
        
        if (jobData.error) {
            html += `<br><strong>Error:</strong> ${jobData.error}`;
        }
        
        if (jobData.feedback) {
            html += `<br><br><strong>Details:</strong><br><pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 12px; white-space: pre-wrap;">${jobData.feedback}</pre>`;
        }
        
        html += `<br><br>You can try submitting again.`;
        
        statusMsg.html(html);
        
        // Re-enable submit button
        $('#bgcld-submit-project').prop('disabled', false);
    }
    
    // Show final error (timeout, etc.)
    function showFinalError(message) {
        const statusDiv = $('.bgcld-status');
        const statusMsg = $('.bgcld-message');
        
        statusDiv.removeClass('processing').addClass('error');
        statusMsg.html(`⏰ ${message}`);
        
        // Re-enable submit button
        $('#bgcld-submit-project').prop('disabled', false);
    }
    
    // File selection
    $('#bgcld-choose-file').on('click', function() {
        $('#bgcld-project-file').click();
    });
    
    $('#bgcld-project-file').on('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            selectedFile = file;
            console.log('📁 File selected:', file.name);

            // Check if file size exceeds the limit
            const maxFileSizeMB = $('#bgcld-submit-project').data('max-file-size') || defaultFileSizeMB;
            if (!validateFileSize(file, maxFileSizeMB)) {
                alert('File is too large. Please select a smaller file.');
                $(this).val('');
                return;
            }

            $('#bgcld-file-name').text(file.name);
            $('.bgcld-file-info').show();
            $('#bgcld-submit-project').prop('disabled', false);
        }
    });
    
    // Drag and drop
    $('.bgcld-upload-area')
        .on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('dragover');
        })
        .on('dragleave', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
        })
        .on('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
            
            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                const file = files[0];
                
                // Check file size for drag & drop too
                const maxFileSizeMB = $('#bgcld-submit-project').data('max-file-size') || defaultFileSizeMB;
                if (!validateFileSize(file, maxFileSizeMB)) {
                    alert('File is too large. Please select a smaller file.');
                    return;
                }
                
                selectedFile = file;
                $('#bgcld-file-name').text(selectedFile.name);
                $('.bgcld-file-info').show();
                $('#bgcld-submit-project').prop('disabled', false);
            }
        });
    
    // Submit project
    $('#bgcld-submit-project').on('click', function() {
        if (!selectedFile) {
            alert('Please select a file first.');
            return;
        }
        
        console.log('🚀 Submitting project:', selectedFile.name);
        
        const button = $(this);
        const statusDiv = $('.bgcld-status');
        const statusMsg = $('.bgcld-message');
        
        // Show loading state
        button.prop('disabled', true);
        statusDiv.show()
            .removeClass('success error')
            .addClass('processing');
        statusMsg.html('📤 Uploading your project...');
        
        // Create form data
        const formData = new FormData();
        formData.append('action', 'bgcld_upload_project');
        formData.append('quiz_id', button.data('quiz-id'));
        formData.append('nonce', bgcld_ajax.nonce);
        formData.append('project_file', selectedFile);
        
        // Submit via AJAX
        $.ajax({
            url: bgcld_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                console.log('✅ Upload response:', response);
                
                if (response.success) {
                    // Extract job info and start polling
                    const jobId = response.data.job_id;
                    const username = response.data.username;
                    
                    statusMsg.html('✅ File uploaded successfully! Starting grading...');
                    
                    // Start polling for status
                    setTimeout(() => {
                        startPolling(jobId, username, button.data('quiz-id'));
                    }, 1000); // Wait 1 second before starting to poll
                    
                } else {
                    statusDiv.removeClass('processing').addClass('error');
                    statusMsg.html('❌ Error: ' + (response.data || 'Upload failed'));
                    button.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                console.error('💥 Upload error:', error);
                statusDiv.removeClass('processing').addClass('error');
                statusMsg.html('❌ Upload failed. Please try again.');
                button.prop('disabled', false);
            }
        });
    });
});
