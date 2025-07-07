jQuery(document).ready(function($) {
    console.log('🚀 Project submission interface loaded');
    
    let selectedFile = null;
    let defaultFileSizeMB = 10; // Default max file size in MB

    // See if the file size is less than or equal to the specified size
    function validateFileSize(file, maxSizeMB) {
        const maxBytes = maxSizeMB * 1024 * 1024;
        return file.size <= maxBytes;
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
                selectedFile = files[0];
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
        statusMsg.html('⏳ Grading your project...');
        
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
                    statusDiv.removeClass('processing').addClass('neutral');
                    statusMsg.html('Score: <strong>' + response.data.score + '%</strong>');
                    
                    // Reload page after 2 seconds to show completion state
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
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
