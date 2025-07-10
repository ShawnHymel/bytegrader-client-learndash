<?php

if (!defined('ABSPATH')) {
    exit;
}

class BGCLD_Quiz_Manager {
    
    const DEFAULT_PASSING_GRADE = 80;
    const DEFAULT_MAX_FILE_SIZE_MB = 10;
    
    // Constructor - initialize hooks and actions
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_quiz_meta_boxes'));
        add_action('save_post', array($this, 'save_quiz_meta'));
        add_action('wp', array($this, 'maybe_hijack_quiz'));
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

    // Save custom meta data when quiz is saved
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
        
        if (isset($_POST['bytegrader_assignment_id'])) {
            update_post_meta($post_id, '_bytegrader_assignment_id', sanitize_text_field($_POST['bytegrader_assignment_id']));
        }
    }

    // Replace quiz content with project submission form if it's a project quiz
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

    // Replace the quiz content with custom HTML based on submission status
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

    // Get passing grade for the quiz from settings
    public function get_quiz_passing_grade($quiz_id) {
        $quiz_settings = get_post_meta($quiz_id, '_sfwd-quiz', true);
        return $quiz_settings['sfwd-quiz_passingpercentage'] ?? self::DEFAULT_PASSING_GRADE;
    }
    
    // Get the URL of the course item (topic, quiz, lesson) that comes after this quiz
    public function get_next_lesson_url($quiz_id) {
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
    
    // Get assignment ID
    public function get_quiz_assignment_id($quiz_id) {
        return get_post_meta($quiz_id, '_bytegrader_assignment_id', true);
    }
    
    // Update user progress based on quiz results
    public function submit_quiz_result($user_id, $quiz_id, $score_percent) {
        BGCLD_Plugin::debug("📊 Starting quiz submission for user_id: $user_id, quiz_id: $quiz_id, score: $score_percent%");
        
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
            BGCLD_Plugin::debug("Quiz marked complete: user_id=$user_id, quiz_id=$quiz_id, score=$score_percent%");
        } else {
            BGCLD_Plugin::debug("Quiz not passed: user_id=$user_id, quiz_id=$quiz_id, score=$score_percent% (need $passing_grade%)");
        }
        
        return true;
    }
    
    // Store latest attempt details for display
    public function store_latest_attempt($user_id, $quiz_id, $score, $feedback, $job_id) {
        $attempt_data = array(
            'score' => $score,
            'feedback' => $feedback,
            'job_id' => $job_id,
            'timestamp' => time(),
            'assignment_id' => get_post_meta($quiz_id, '_bytegrader_assignment_id', true)
        );
        
        $meta_key = '_bgcld_latest_attempt_' . $quiz_id;
        update_user_meta($user_id, $meta_key, $attempt_data);
        
        BGCLD_Plugin::debug("Stored latest attempt for user {$user_id}, quiz {$quiz_id}: score {$score}%");
    }
    
    // Get latest attempt details
    public function get_latest_attempt($user_id, $quiz_id) {
        $meta_key = '_bgcld_latest_attempt_' . $quiz_id;
        return get_user_meta($user_id, $meta_key, true);
    }

    // Render the quiz meta box in the admin area
    public function render_quiz_meta_box($post) {
        // Prepare variables for template
        $is_project_quiz = get_post_meta($post->ID, '_is_project_submission', true);
        $max_file_size = get_post_meta($post->ID, '_max_file_size', true) ?: self::DEFAULT_MAX_FILE_SIZE_MB;
        $assignment_id = get_post_meta($post->ID, '_bytegrader_assignment_id', true);
        
        // Include template
        include BGCLD_PLUGIN_DIR . 'templates/admin-quiz-meta-box.php';
    }

    // Render the quiz progress status
    private function render_progress_status($best_score, $passing_grade, $attempt_count, $passed) {
        $user_id = get_current_user_id();
        $quiz_id = get_the_ID();
        $latest_attempt = $this->get_latest_attempt($user_id, $quiz_id);
        
        // Use output buffering to capture template output
        ob_start();
        include BGCLD_PLUGIN_DIR . 'templates/quiz-progress-status.php';
        return ob_get_clean();
    }

    // Render the quiz completion status
    private function render_completion_status($best_score, $passing_grade, $attempt_count, $quiz_id) {
        $user_id = get_current_user_id();
        $latest_attempt = $this->get_latest_attempt($user_id, $quiz_id);
        
        ob_start();
        include BGCLD_PLUGIN_DIR . 'templates/quiz-completion-status.php';
        return ob_get_clean();

    }

    // Render the quiz submission form
    private function render_submission_form($quiz_id, $show_next_button = false, $next_lesson_url = null) {
        $max_file_size = get_post_meta($quiz_id, '_max_file_size', true) ?: self::DEFAULT_MAX_FILE_SIZE_MB;
        
        ob_start();
        include BGCLD_PLUGIN_DIR . 'templates/quiz-submission-form.php';
        return ob_get_clean();
    }
}