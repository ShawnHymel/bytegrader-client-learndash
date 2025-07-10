<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Variables passed: $best_score, $passing_grade, $attempt_count, $latest_attempt
?>

<div class="bgcld-progress">
    <h4>📊 Try Again!</h4>
    <div class="bgcld-stats">
        <p><strong>Best Score:</strong> <?php echo esc_html($best_score); ?>% (Need: <?php echo esc_html($passing_grade); ?>%)</p>
        <p><strong>Attempts:</strong> <?php echo esc_html($attempt_count); ?></p>
        
        <?php if ($latest_attempt && !empty($latest_attempt['feedback'])): ?>
            <?php 
            $latest_score = $latest_attempt['score'];
            $latest_feedback = $latest_attempt['feedback'];
            $timestamp = date('M j, Y g:i A', $latest_attempt['timestamp']);
            ?>
            
            <p><strong>Latest Attempt:</strong> <?php echo esc_html($latest_score); ?>% (submitted <?php echo esc_html($timestamp); ?>)</p>
            
            <details style="margin-top: 15px;">
                <summary><strong>Latest Attempt Feedback</strong></summary>
                <div style="background: #f8f9fa; padding: 15px; border-radius: 4px; margin-top: 10px;">
                    <pre style="white-space: pre-wrap; font-family: inherit; margin: 0; font-size: 14px;"><?php echo esc_html($latest_feedback); ?></pre>
                </div>
            </details>
        <?php endif; ?>
        
        <p>Submit again to improve your score and continue with the course.</p>
    </div>
</div>