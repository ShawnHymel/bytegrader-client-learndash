<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Variables passed: $quiz_id, $max_file_size, $show_next_button, $next_lesson_url
?>

<div class="bgcld-submission">
    <h3>📁 Project Submission</h3>
    <div class="bgcld-upload-area">
        <p>📤 Drop your project file here or click to browse</p>
        <p style="font-size: 14px; color: #666; margin-top: 10px;">
            ⚠️ <strong>Important:</strong> Keep this window open during grading. 
            Closing the browser will interrupt the process, and you will need to resubmit.
        </p>
        <input type="file" id="bgcld-project-file" accept=".zip,.tar.gz,.tar" style="display: none;" />
        <button type="button" class="button button-primary button-large" id="bgcld-choose-file">
            Choose Project File
        </button>
    </div>
    
    <div class="bgcld-file-info" style="display: none;">
        <p><strong>Selected file:</strong> <span id="bgcld-file-name"></span></p>
    </div>
    
    <div class="bgcld-actions">
        <button type="button" class="button button-primary button-large" id="bgcld-submit-project" 
            disabled 
            data-quiz-id="<?php echo esc_attr($quiz_id); ?>"
            data-max-file-size="<?php echo esc_attr($max_file_size); ?>">
            Submit Project for Grading
        </button>
    </div>
    
    <div class="bgcld-status" style="display: none;">
        <div class="bgcld-message"></div>
    </div>
</div>

<?php if ($show_next_button && $next_lesson_url): ?>
    <div class="bgcld-next-lesson-bottom" style="margin-top: 0; padding-top: 15px; display: flex; justify-content: flex-end;">
        <a class="ld-button" href="<?php echo esc_url($next_lesson_url); ?>" style="width: auto; display: inline-block;">
            <span class="ld-text">Next</span>
            <span class="ld-icon ld-icon-arrow-right"></span>
        </a>
    </div>
<?php endif; ?>

<div class="bgcld-attribution">
    <small>Powered by <a href="https://github.com/ShawnHymel/bytegrader" target="_blank">ByteGrader</a> - Open Source Autograding</small>
</div>
