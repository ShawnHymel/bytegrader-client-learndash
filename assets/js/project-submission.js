jQuery(document).ready(function($) {
    
    // Initialize variables
    let selectedFile = null;
    let defaultFileSizeMB = 10; // Default max file size in MB
    
    // Polling variables
    let pollingInterval = null;
    let pollingJobId = null;
    let pollingUsername = null;
    let pollingStartTime = null;
    let pollingAttempts = 0;
    let beforeUnloadHandler = null;
    const POLLING_INTERVAL_MS = 5000; // 5 seconds
    
    // Debug wrapper function
    function debugLog(message, data = null) {
        if (bgcld_ajax.debug_mode) {
            if (data !== null) {
                console.log(message, data);
            } else {
                console.log(message);
            }
        }
    }

    // See if the file size is less than or equal to the specified size
    function validateFileSize(file, maxSizeMB) {
        const maxBytes = maxSizeMB * 1024 * 1024;
        return file.size <= maxBytes;
    }
    
    // Start polling for job status
    function startPolling(jobId, username, quizId) {
        debugLog('📊 Starting status polling for job:', jobId);
        
        pollingJobId = jobId;
        pollingUsername = username;
        pollingStartTime = Date.now();
        pollingAttempts = 0;
        
        // Add browser exit warning
        addBrowserExitWarning();
        
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
            debugLog('⏹️ Stopped status polling');
        }
        
        // Remove browser exit warning
        removeBrowserExitWarning();
    }
    
    // Function to add the warning
    function addBrowserExitWarning() {
        if (!beforeUnloadHandler) {
            beforeUnloadHandler = function(e) {
                if (pollingInterval) {
                    e.preventDefault();
                    e.returnValue = 'Your submission is still being graded. Are you sure you want to leave?';
                    return e.returnValue;
                }
            };
            
            window.addEventListener('beforeunload', beforeUnloadHandler);
            debugLog('🚨 Browser exit warning enabled');
        }
    }
    
    // Function to remove the warning
    function removeBrowserExitWarning() {
        if (beforeUnloadHandler) {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
            beforeUnloadHandler = null;
            debugLog('✅ Browser exit warning disabled');
        }
    }
    
    // Check job status via AJAX
    function checkJobStatus() {
        if (!pollingJobId) return;
        
        pollingAttempts++;
        const elapsedSeconds = Math.floor((Date.now() - pollingStartTime) / 1000);
        
        debugLog(`🔍 Checking job status (attempt ${pollingAttempts}, ${elapsedSeconds}s elapsed)`);
        
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
                debugLog('📨 Status check response:', response);
                handleStatusResponse(response, elapsedSeconds);
            },
            error: function(xhr, status, error) {
                console.error('❌ Status check failed:', error);
                const statusMsg = $('.bgcld-message');
                statusMsg.html(`⚠️ Connection error while checking status. Retrying...`);
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
        
        debugLog(`📋 Job status: ${status}`);
        
        switch (status) {
            case 'queued':
                let queueMsg = `⏳ Job queued for grading... (${elapsedSeconds}s elapsed)`;
                
                // Add queue information if available
                if (jobData.queue_info) {
                    const queueInfo = jobData.queue_info;
                    const queueLength = queueInfo.queue_length || 0;
                    const activeJobs = queueInfo.active_jobs || 0;
                    const maxConcurrent = queueInfo.max_concurrent || 1;
                    
                    // Total jobs ahead = active jobs + jobs in queue - 1 (user's job is in the queue count)
                    const jobsAhead = activeJobs + queueLength - 1;
                    
                    // Display different messages based on user's position in the queue
                    if (jobsAhead > 1) {
                        queueMsg = `⏳ ${jobsAhead} submissions ahead of you (${elapsedSeconds}s elapsed)<br>` +
                                  `<small style="color: #666;">Please keep this window open.</small>`;
                    } else if (jobsAhead === 1) {
                        queueMsg = `⏳ 1 submission ahead of you (${elapsedSeconds}s elapsed)<br>` +
                                  `<small style="color: #666;">Please keep this window open.</small>`;
                    } else if (activeJobs >= maxConcurrent) {
                        // No queue, but graders are busy
                        queueMsg = `⏳  Your submission is next. Waiting for grader to free up... (${elapsedSeconds}s elapsed)<br>` +
                                  `<small style="color: #666;">Please keep this window open.</small>`;
                    } else {
                        queueMsg = `⏳ Starting grading now... (${elapsedSeconds}s elapsed)<br>` +
                                  `<small style="color: #666;">Please keep this window open.</small>`;
                    }
                    
                    // Keep detailed info in console for debugging
                    debugLog(`📊 Queue details: ${queueLength} queued, ${activeJobs}/${maxConcurrent} active graders`);
                }
                
                statusMsg.html(queueMsg);
                break;
                
            case 'processing':
                statusMsg.html(`⚙️ Grading your project... (${elapsedSeconds}s elapsed)<br>` +
                                  `<small style="color: #666;">Please keep this window open.</small>`);
                break;
                
            case 'completed':
                debugLog('✅ Grading completed!');
                showCompletedResult(jobData, elapsedSeconds);
                stopPolling();
                break;
                
            case 'failed':
                debugLog('❌ Grading failed');
                showFailedResult(jobData, elapsedSeconds);
                stopPolling();
                break;
                
            default:
                statusMsg.html(`❓ Unknown status: ${status} (${elapsedSeconds}s elapsed)`);
                break;
        }
    }
    
    // Show completed result
    function showCompletedResult(jobData, elapsedSeconds) {
        const statusDiv = $('.bgcld-status');
        const statusMsg = $('.bgcld-message');
        
        statusDiv.removeClass('processing').addClass('neutral');
        
        // Remove warning immediately when completed
        removeBrowserExitWarning();
        
        let html = `Grading completed in ${elapsedSeconds} seconds!<br>`;
        html += `<strong>Score: ${jobData.score}%</strong>`;
        html += `<br><br><small style="color: #666;">The page will update to show your results and detailed feedback.</small>`;
        
        statusMsg.html(html);
        
        // Reload page after 2 seconds to show persistent completion state (reduced from 3 seconds)
        setTimeout(() => {
            debugLog('🔄 Reloading page to show completion state...');
            location.reload();
        }, 2000);
    }
    
    // Show failed result
    function showFailedResult(jobData, elapsedSeconds) {
        const statusDiv = $('.bgcld-status');
        const statusMsg = $('.bgcld-message');
        
        statusDiv.removeClass('processing').addClass('error');
        
        // Remove warning when failed
        removeBrowserExitWarning();
        
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
        
        // Remove warning on timeout/error
        removeBrowserExitWarning();
        
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
            debugLog('📁 File selected:', file.name);

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
        
        debugLog('🚀 Submitting project:', selectedFile.name);
        
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
                debugLog('✅ Upload response:', response);
                
                if (response.success) {
                    // Extract job info and start polling
                    const jobId = response.data.job_id;
                    const username = response.data.username;
                    
                    statusMsg.html('✅ File uploaded successfully! Starting grading...<br>' +
                                   '<small style="color: #666;">⚠️ Please keep this window open during grading.</small>');
                    
                    // Start polling for status
                    setTimeout(() => {
                        startPolling(jobId, username, button.data('quiz-id'));
                    }, 1000); // Wait 1 second before starting to poll
                    
                } else {
                    // Check if this is a duplicate submission error
                    if (response.data && response.data.type === 'duplicate_submission') {
                        statusDiv.removeClass('processing').addClass('neutral');
                        statusMsg.html('⚠️ ' + response.data.message + '<br><small>Please wait for your current submission to complete before trying again.</small>');
                    } else {
                        statusDiv.removeClass('processing').addClass('error');
                        statusMsg.html('❌ Error: ' + (response.data.message || response.data || 'Upload failed'));
                    }
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
