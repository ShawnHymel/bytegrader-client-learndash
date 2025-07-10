<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Variables passed: $post, $is_project_quiz, $max_file_size, $assignment_id
?>

<?php wp_nonce_field('autograder_quiz_meta', 'autograder_quiz_nonce'); ?>

<table class="form-table">
    <tr>
        <th scope="row">Quiz Type</th>
        <td>
            <label>
                <input type="radio" name="quiz_type" value="regular" <?php checked(!$is_project_quiz); ?> />
                Regular Knowledge Quiz
            </label><br>
            <label>
                <input type="radio" name="quiz_type" value="project" <?php checked($is_project_quiz); ?> />
                Project Submission Quiz
            </label>
        </td>
    </tr>
    <tr class="project-settings" style="<?php echo !$is_project_quiz ? 'display:none;' : ''; ?>">
        <th scope="row">Max File Size (MB)</th>
        <td>
            <input type="number" name="max_file_size" value="<?php echo esc_attr($max_file_size); ?>" min="1" max="500" />
        </td>
    </tr>
    <tr class="project-settings" style="<?php echo !$is_project_quiz ? 'display:none;' : ''; ?>">
        <th scope="row">Assignment ID</th>
        <td>
            <input type="text" name="bytegrader_assignment_id" value="<?php echo esc_attr($assignment_id); ?>" class="regular-text" placeholder="e.g., cpp-hello-world" />
            <p class="description">The assignment identifier on your ByteGrader server</p>
        </td>
    </tr>
</table>

<script>
jQuery(document).ready(function($) {
    $('input[name="quiz_type"]').on('change', function() {
        if ($(this).val() === 'project') {
            $('.project-settings').show();
        } else {
            $('.project-settings').hide();
        }
    });
});
</script>
