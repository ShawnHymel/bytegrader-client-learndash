<?php
/**
 * Plugin Name: ByteGrader Client for LearnDash
 * Plugin URI: https://github.com/yourusername/bytegrader-client-learndash
 * Description: Integrates ByteGrader autograding service with LearnDash LMS for automated code assessment
 * Version: 0.8.0
 * Author: Shawn Hymel
 * Author URI: https://shawnhymel.com
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: bytegrader-client-learndash
 * Domain Path: /languages
 * Requires at least: 5.7
 * Requires PHP: 7.4
 * Tested up to: 6.8
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Version
define('BGCLD_VERSION', '0.8.0');

// Plugin paths
define('BGCLD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BGCLD_PLUGIN_DIR', plugin_dir_path(__FILE__));

class LearnDashAutograderQuiz {
    
    // Settings
    const DEFAULT_PASSING_GRADE = 80;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        
        // Admin hooks
        add_action('add_meta_boxes', array($this, 'add_quiz_meta_boxes'));
        add_action('save_post', array($this, 'save_quiz_meta'));
        
        // Frontend hooks
        add_action('wp', array($this, 'maybe_hijack_quiz'));
        
        // AJAX handlers
        add_action('wp_ajax_bgcld_upload_project', array($this, 'handle_project_upload'));
        add_action('wp_ajax_nopriv_bgcld_upload_project', array($this, 'handle_project_upload'));
        add_action('wp_ajax_bgcld_submit_code', array($this, 'handle_code_submission'));
        add_action('wp_ajax_nopriv_bgcld_submit_code', array($this, 'handle_code_submission'));
        add_action('wp_ajax_get_next_lesson_url', array($this, 'ajax_get_next_lesson_url'));
        add_action('wp_ajax_nopriv_get_next_lesson_url', array($this, 'ajax_get_next_lesson_url'));
    }
    
    public function enqueue_assets() {
        global $post;
        
        // Only load on project quiz pages
        if (!$post || $post->post_type !== 'sfwd-quiz') {
            return;
        }
        
        $is_project_quiz = get_post_meta($post->ID, '_is_project_submission', true);
        if (!$is_project_quiz) {
            return;
        }
        
        wp_enqueue_script(
            'bgcld-project-submission', 
            BGCLD_PLUGIN_URL . 'assets/js/project-submission.js',
            array('jquery'),
            BGCLD_VERSION,
            true
        );
        
        wp_enqueue_style(
            'bgcld-project-submission',
            BGCLD_PLUGIN_URL . 'assets/css/project-submission.css',
            array(),
            BGCLD_VERSION
        );
        
        wp_localize_script('bgcld-project-submission', 'bgcld_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bgcld_upload_nonce'),
            'quiz_id' => $post->ID
        ));
    }
    
    // Add custom meta box to quiz edit pages
    public function add_quiz_meta_boxes() {
        add_meta_box(
            'autograder_settings',
            'Autograder Settings',
            array($this, 'render_quiz_meta_box'),
            'sfwd-quiz',
            'normal',
            'high'
        );
    }
    
    // Render the custom settings in quiz admin
    public function render_quiz_meta_box($post) {
        wp_nonce_field('autograder_quiz_meta', 'autograder_quiz_nonce');
        
        $is_project_quiz = get_post_meta($post->ID, '_is_project_submission', true);
        $max_file_size = get_post_meta($post->ID, '_max_file_size', true) ?: '50';
        
        ?>
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
        <?php
    }
    
    public function save_quiz_meta($post_id) {
        if (!isset($_POST['autograder_quiz_nonce']) || !wp_verify_nonce($_POST['autograder_quiz_nonce'], 'autograder_quiz_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id) || get_post_type($post_id) !== 'sfwd-quiz') {
            return;
        }
        
        $is_project_quiz = (isset($_POST['quiz_type']) && $_POST['quiz_type'] === 'project') ? '1' : '';
        update_post_meta($post_id, '_is_project_submission', $is_project_quiz);
        
        if (isset($_POST['max_file_size'])) {
            update_post_meta($post_id, '_max_file_size', sanitize_text_field($_POST['max_file_size']));
        }
    }
    
    public function maybe_hijack_quiz() {
        global $post;
        
        if (!$post || $post->post_type !== 'sfwd-quiz') {
            return;
        }
        
        $is_project_quiz = get_post_meta($post->ID, '_is_project_submission', true);
        
        if ($is_project_quiz) {
            add_filter('the_content', array($this, 'replace_quiz_content'));
        }
    }
    
    public function replace_quiz_content($content) {
        global $post;
        
        if (!is_user_logged_in()) {
            return '<p>Please log in to access this assignment.</p>';
        }
        
        $user_id = get_current_user_id();
        $quiz_id = $post->ID;
        
        // Get quiz progress
        $quiz_progress = get_user_meta($user_id, '_sfwd-quizzes', true) ?: array();
        $best_score = 0;
        $attempt_count = 0;
        $passing_grade = $this->get_quiz_passing_grade($quiz_id);
        
        foreach ($quiz_progress as $attempt) {
            if (isset($attempt['quiz']) && $attempt['quiz'] == $quiz_id) {
                $best_score = max($best_score, $attempt['percentage']);
                $attempt_count++;
            }
        }
        
        $content = '';
        
        // Branch 1: No submissions yet
        if ($attempt_count === 0) {
            $content .= $this->render_submission_form($quiz_id);
        }
        
        // Branch 2: Has submissions but hasn't passed
        else if ($best_score < $passing_grade) {
            $content .= $this->render_progress_status($best_score, $passing_grade, $attempt_count, false);
            $content .= $this->render_submission_form($quiz_id);
        }
        
        // Branch 3: Has passed
        else {
            $next_lesson_url = $this->get_next_lesson_url($quiz_id);
            $content .= $this->render_completion_status($best_score, $passing_grade, $attempt_count, $quiz_id);
            $content .= $this->render_submission_form($quiz_id, true, $next_lesson_url);
        }
        
        return $content;
    }
    
    private function render_progress_status($best_score, $passing_grade, $attempt_count, $passed) {
        return '<div class="bgcld-progress">
                    <h4>📊 Try Again!</h4>
                    <p><strong>Best Score:</strong> ' . $best_score . '% (Need: ' . $passing_grade . '%)</p>
                    <p><strong>Attempts:</strong> ' . $attempt_count . '</p>
                    <p>Try again to continue with the course. You may submit again to improve your score.</p>
                </div>';
    }
    
    private function render_completion_status($best_score, $passing_grade, $attempt_count, $quiz_id) {
        return '<div class="bgcld-completion">
                    <h4>✅ Assignment Completed Successfully!</h4>
                    <p><strong>Best Score:</strong> ' . $best_score . '% (Passing: ' . $passing_grade . '%)</p>
                    <p><strong>Attempts:</strong> ' . $attempt_count . '</p>
                    <p>You may continue to the next lesson, but feel free to submit again to improve your score (max is 100%).</p>
                </div>';
    }
    
    private function render_submission_form($quiz_id, $show_next_button = false, $next_lesson_url = null) {
        $submission_form = '<div class="bgcld-submission">
                                <h3>📁 Project Submission</h3>
                                <div class="bgcld-upload-area">
                                    <p>📤 Drop your project file here or click to browse</p>
                                    <input type="file" id="bgcld-project-file" accept=".zip,.tar.gz,.tar" style="display: none;" />
                                    <button type="button" class="button button-primary button-large" id="bgcld-choose-file">
                                        Choose Project File
                                    </button>
                                </div>
                                <div class="bgcld-file-info" style="display: none;">
                                    <p><strong>Selected file:</strong> <span id="bgcld-file-name"></span></p>
                                </div>
                                <div class="bgcld-actions">
                                    <button type="button" class="button button-primary button-large" id="bgcld-submit-project" disabled data-quiz-id="' . esc_attr($quiz_id) . '">
                                        Submit Project for Grading
                                    </button>
                                </div>
                                <div class="bgcld-status" style="display: none;">
                                    <div class="bgcld-message"></div>
                                </div>
                            </div>';
    
        if ($show_next_button && $next_lesson_url) {
            $submission_form .= '<div class="bgcld-next-lesson-bottom" style="margin-top: 0; padding-top: 15px; display: flex; justify-content: flex-end;">
                                    <a class="ld-button" href="' . esc_url($next_lesson_url) . '" style="width: auto; display: inline-block;">
                                        <span class="ld-text">Next</span>
                                        <span class="ld-icon ld-icon-arrow-right"></span>
                                    </a>
                                </div>';
        }
    
        return $submission_form;
    }
    
    public function handle_project_upload() {
        if (!wp_verify_nonce($_POST['nonce'], 'bgcld_upload_nonce') || !is_user_logged_in()) {
            wp_send_json_error('Security check failed');
        }
        
        $user_id = get_current_user_id();
        $quiz_id = intval($_POST['quiz_id']);
        
        if (!isset($_FILES['project_file'])) {
            wp_send_json_error('No file uploaded');
        }
        
        $file = $_FILES['project_file'];
        
        // Basic file validation
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload failed');
        }
        
        // Simulate grading (replace with actual logic)
        sleep(2);
        $score = 75; //rand(70, 100);
        
        // Submit quiz result
        $result = $this->submit_quiz_result($user_id, $quiz_id, $score);
        
        if ($result) {
            wp_send_json_success(array(
                'score' => $score,
                'message' => 'Project compiled and tested successfully!'
            ));
        } else {
            wp_send_json_error('Failed to submit quiz result');
        }
    }
    
    public function handle_code_submission() {
        if (!wp_verify_nonce($_POST['nonce'], 'bgcld_nonce') || !is_user_logged_in()) {
            wp_send_json_error('Invalid request');
        }
        
        $user_id = get_current_user_id();
        $quiz_id = intval($_POST['quiz_id']);
        $pass_rate = intval($_POST['pass_rate']);
        
        $passed = (rand(1, 100) <= $pass_rate);
        
        if ($passed) {
            $score = rand(85, 100);
            $this->submit_quiz_result($user_id, $quiz_id, $score);
            wp_send_json_success(array('passed' => true, 'message' => 'Assignment passed!'));
        } else {
            wp_send_json_success(array('passed' => false, 'message' => 'Try again'));
        }
    }
    
    public function ajax_get_next_lesson_url() {
        if (!wp_verify_nonce($_POST['nonce'], 'next_lesson_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $quiz_id = intval($_POST['quiz_id']);
        $next_url = $this->get_next_lesson_url($quiz_id);
        
        wp_send_json_success(array('next_url' => $next_url));
    }
    
    private function get_quiz_passing_grade($quiz_id) {
        $quiz_settings = get_post_meta($quiz_id, '_sfwd-quiz', true);
        return $quiz_settings['sfwd-quiz_passingpercentage'] ?? self::DEFAULT_PASSING_GRADE;
    }
    
    // Get the URL of the course item (topic, quiz, lesson) that comes after this quiz
    private function get_next_lesson_url($quiz_id) {
        $course_id = learndash_get_course_id($quiz_id);
        
        if (!$course_id) {
            return null;
        }
        
        // Find the lesson this quiz belongs to
        $quiz_lesson = learndash_get_lesson_id($quiz_id);
        
        if ($quiz_lesson) {
            // Get all topics for this lesson
            $lesson_topics = learndash_get_topic_list($quiz_lesson, $course_id);
            
            // Build ordered list of all lesson content
            $lesson_items = array();
            
            // Add topics and their quizzes
            foreach ($lesson_topics as $topic) {
                $topic_id = $topic->ID;
                $lesson_items[] = $topic_id;
                
                // Add quizzes for this topic
                $topic_quizzes = learndash_get_lesson_quiz_list($topic_id, null, $course_id);
                foreach ($topic_quizzes as $topic_quiz) {
                    $lesson_items[] = $topic_quiz['id'];
                }
            }
            
            // Add lesson-level quizzes
            $lesson_quizzes = learndash_get_lesson_quiz_list($quiz_lesson, null, $course_id);
            foreach ($lesson_quizzes as $lesson_quiz) {
                $lesson_items[] = $lesson_quiz['id'];
            }
            
            // Find current quiz position and get next item
            $quiz_index = array_search($quiz_id, $lesson_items);
            if ($quiz_index !== false && isset($lesson_items[$quiz_index + 1])) {
                return get_permalink($lesson_items[$quiz_index + 1]);
            }
        }
        
        // No more items in current lesson, find next lesson
        $course_steps = learndash_get_course_steps($course_id);
        
        if ($quiz_lesson) {
            $lesson_index = array_search($quiz_lesson, $course_steps);
            if ($lesson_index !== false && isset($course_steps[$lesson_index + 1])) {
                return get_permalink($course_steps[$lesson_index + 1]);
            }
        }
        
        return null;
    }
    
    private function submit_quiz_result($user_id, $quiz_id, $score_percent) {
        
        
        $this->debug("📊 Starting quiz submission for user_id: $user_id, quiz_id: $quiz_id, score: $score_percent%");
        
        // Get course ID
        $course_id = learndash_get_course_id($quiz_id);
        
        // Update quiz data
        $quiz_data = array(
            'quiz' => $quiz_id,
            'score' => $score_percent,
            'count' => 1,
            'pass' => ($score_percent >= $this->get_quiz_passing_grade($quiz_id)) ? 1 : 0,
            'rank' => '-',
            'time' => time(),
            'pro_quizid' => $quiz_id,
            'course' => $course_id,
            'points' => $score_percent,
            'total_points' => 100,
            'percentage' => $score_percent,
            'timespent' => '5',
            'has_graded' => false,
            'statistic_ref_id' => 0
        );
        
        // Add to user quiz progress
        $quiz_progress = get_user_meta($user_id, '_sfwd-quizzes', true) ?: array();
        $quiz_progress[] = $quiz_data;
        update_user_meta($user_id, '_sfwd-quizzes', $quiz_progress);
        
        // Clear caches so users can see results immediately
        wp_cache_delete($user_id . '_' . $quiz_id, 'learndash_quiz_completion');
        wp_cache_delete('learndash_user_' . $user_id . '_quiz_' . $quiz_id, 'learndash');
        if (class_exists('LDLMS_Transients')) {
            LDLMS_Transients::purge_all();
        }
        
        // Mark completion if user passed
        if ($score_percent >= $this->get_quiz_passing_grade($quiz_id)) {
            update_user_meta($user_id, '_sfwd-quiz_completed_' . $quiz_id, time());
            learndash_process_mark_complete($user_id, $quiz_id, true, $course_id);
            $this->debug("Quiz marked complete: user_id=$user_id, quiz_id=$quiz_id, score=$score_percent%");
        } else {
            $this->debug("Quiz not passed: user_id=$user_id, quiz_id=$quiz_id, score=$score_percent% (need $passing_grade%)");
        }
        
        return true;
    }
    
    // Log debug messages
    private function debug($msg) {
        if (defined('BGCLD_DEBUG') && BGCLD_DEBUG) {
            if (is_array($msg) || is_object($msg)) {
                $msg = print_r($msg, true);
            }
            if (function_exists('error_log')) {
                error_log('[BGCLD] ' . $msg);
            }
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    new LearnDashAutograderQuiz();
});
