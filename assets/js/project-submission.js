jQuery(document).ready(function($) {
    console.log('🚀 Project submission interface loaded');
    
    let selectedFile = null;
    
    // File selection
    $('#ldag-choose-file').on('click', function() {
        $('#ldag-project-file').click();
    });
    
    $('#ldag-project-file').on('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            selectedFile = file;
            console.log('📁 File selected:', file.name);
            
            $('#ldag-file-name').text(file.name);
            $('.ldag-file-info').show();
            $('#ldag-submit-project').prop('disabled', false);
        }
    });
    
    // Drag and drop
    $('.ldag-upload-area')
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
                $('#ldag-file-name').text(selectedFile.name);
                $('.ldag-file-info').show();
                $('#ldag-submit-project').prop('disabled', false);
            }
        });
    
    // Submit project
    $('#ldag-submit-project').on('click', function() {
        if (!selectedFile) {
            alert('Please select a file first.');
            return;
        }
        
        console.log('🚀 Submitting project:', selectedFile.name);
        
        const button = $(this);
        const statusDiv = $('.ldag-status');
        const statusMsg = $('.ldag-message');
        
        // Show loading state
        button.prop('disabled', true);
        statusDiv.show()
            .removeClass('success error')
            .addClass('processing');
        statusMsg.html('⏳ Grading your project...');
        
        // Create form data
        const formData = new FormData();
        formData.append('action', 'ldag_upload_project');
        formData.append('quiz_id', button.data('quiz-id'));
        formData.append('nonce', ldag_ajax.nonce);
        formData.append('project_file', selectedFile);
        
        // Submit via AJAX
        $.ajax({
            url: ldag_ajax.ajax_url,
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
