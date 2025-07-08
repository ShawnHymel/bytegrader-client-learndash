<?php
/**
 * Plugin Name: ByteGrader Client for LearnDash
 * Plugin URI: https://github.com/ShawnHymel/bytegrader-client-learndash
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
    const DEFAULT_MAX_FILE_SIZE_MB = 10;

    /***************************************************************************
     * Public methods
     */
    
    // Constructor - initialize the plugin, register hooks, and sets up admin menu
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_plugin_action_links'));
        add_action('wp_ajax_bgcld_test_connection', array($this, 'ajax_test_connection'));
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
        add_action('wp_ajax_bgcld_check_job_status', array($this, 'ajax_check_job_status'));
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
        $max_file_size = get_post_meta($post->ID, '_max_file_size', true) ?: self::DEFAULT_MAX_FILE_SIZE_MB;
        $assignment_id = get_post_meta($post->ID, '_bytegrader_assignment_id', true);
        
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
        
        if (isset($_POST['bytegrader_assignment_id'])) {
            update_post_meta($post_id, '_bytegrader_assignment_id', sanitize_text_field($_POST['bytegrader_assignment_id']));
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
    
    // Send project submission to ByteGrader server and wait for result
    public function handle_project_upload() {
        if (!wp_verify_nonce($_POST['nonce'], 'bgcld_upload_nonce') || !is_user_logged_in()) {
            wp_send_json_error('Security check failed');
        }
        
        // Get current user and quiz ID
        $user_id = get_current_user_id();
        $quiz_id = intval($_POST['quiz_id']);
    
        if (!isset($_FILES['project_file'])) {
            wp_send_json_error('No file uploaded');
        }
    
        // Get the uploaded file
        $file = $_FILES['project_file'];
        
        // Basic file validation
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload failed');
        }
    
        // Check file size
        $max_file_size = get_post_meta($quiz_id, '_max_file_size', true) ?: self::DEFAULT_MAX_FILE_SIZE_MB;
        $max_bytes = $max_file_size * 1024 * 1024;
        
        if ($file['size'] > $max_bytes) {
            wp_send_json_error("File too large. Maximum size is {$max_file_size}MB.");
        }
        
        // Get ByteGrader settings
        $settings = $this->get_bytegrader_settings();
        if (empty($settings['server_url']) || empty($settings['api_key'])) {
            wp_send_json_error('ByteGrader server not configured. Please contact your administrator.');
        }
        
        // Get assignment ID
        $assignment_id = $this->get_quiz_assignment_id($quiz_id);
        if (empty($assignment_id)) {
            wp_send_json_error('Assignment ID not configured for this quiz. Please contact your administrator.');
        }
        
        // Get current user info
        $user = wp_get_current_user();
        $username = $user->user_login; // or use $user->user_email if you prefer
        
        // Submit to ByteGrader server
        $bytegrader_result = $this->submit_to_bytegrader($settings, $assignment_id, $username, $file);
        
        if ($bytegrader_result['success']) {
            // Extract job ID from response
            $response_data = $bytegrader_result['data'];
            $job_id = $response_data['job_id'] ?? null;
            
            if ($job_id) {
                wp_send_json_success(array(
                    'job_id' => $job_id,
                    'username' => $username,
                    'status' => 'queued',
                    'message' => 'Project submitted successfully! Please keep this page open while grading completes...'
                ));
            } else {
                wp_send_json_error('Invalid response from grading server: missing job ID');
            }
        } else {
            wp_send_json_error('Submission failed: ' . $bytegrader_result['error']);
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

    // Add settings page to the admin menu
    public function add_admin_menu() {
        add_options_page(
            'ByteGrader Settings',           // Page title
            'ByteGrader',                    // Menu title
            'manage_options',                // Capability required
            'bytegrader-settings',           // Menu slug
            array($this, 'settings_page')    // Callback function
        );
    }

    // Add settings content to admin page
    public function register_settings() {

        // Register the settings group
        register_setting('bytegrader_settings', 'bytegrader_options', array(
            'sanitize_callback' => array($this, 'sanitize_settings')
        ));
        
        // Add settings section
        add_settings_section(
            'bytegrader_main_section',
            'Server Configuration',
            array($this, 'settings_section_callback'),
            'bytegrader_settings'
        );
        
        // Server URL field
        add_settings_field(
            'server_url',
            'ByteGrader Server URL',
            array($this, 'server_url_field_callback'),
            'bytegrader_settings',
            'bytegrader_main_section'
        );
        
        // API Key field
        add_settings_field(
            'api_key',
            'API Key',
            array($this, 'api_key_field_callback'),
            'bytegrader_settings',
            'bytegrader_main_section'
        );
    }

    // Add HTML to the admin page
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>ByteGrader Settings</h1>
            <p>Configure your ByteGrader server connection settings.</p>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('bytegrader_settings');
                do_settings_sections('bytegrader_settings');
                submit_button('Save Settings');
                ?>
            </form>
            
            <div style="margin-top: 30px; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                <h3>Test Connection</h3>
                <p>Test your connection to the ByteGrader server:</p>
                <button type="button" id="bgcld-test-connection" class="button button-secondary">Test Connection</button>
                <div id="bgcld-connection-result" style="margin-top: 15px; display: none;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#bgcld-test-connection').on('click', function() {
                const button = $(this);
                const resultDiv = $('#bgcld-connection-result');
                
                button.prop('disabled', true).text('Testing...');
                resultDiv.show().html('<p>🔄 Connecting to ByteGrader server...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'bgcld_test_connection',
                        nonce: '<?php echo wp_create_nonce('bgcld_test_connection'); ?>'
                    },
                    success: function(response) {
                        button.prop('disabled', false).text('Test Connection');
                        
                        if (response.success) {
                            resultDiv.html(
                                '<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">' +
                                '<h4 style="margin-top: 0; color: #155724;">✅ Connection Successful</h4>' +
                                '<pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto;">' +
                                JSON.stringify(response.data, null, 2) +
                                '</pre>' +
                                '</div>'
                            );
                        } else {
                            resultDiv.html(
                                '<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' +
                                '<h4 style="margin-top: 0; color: #721c24;">❌ Connection Failed</h4>' +
                                '<p style="margin-bottom: 0;">' + (response.data || 'Unknown error') + '</p>' +
                                '</div>'
                            );
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('Test Connection');
                        resultDiv.html(
                            '<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">' +
                            '<h4 style="margin-top: 0; color: #721c24;">❌ Request Failed</h4>' +
                            '<p style="margin-bottom: 0;">Could not connect to WordPress. Please try again.</p>' +
                            '</div>'
                        );
                    }
                });
            });
        });
        </script>
        <?php
    }

    // Callback: add description to settings section
    public function settings_section_callback() {
        echo '<p>Enter your ByteGrader server details below:</p>';
    }

    // Callback: set server URL field
    public function server_url_field_callback() {
        $options = get_option('bytegrader_options', array());
        $server_url = isset($options['server_url']) ? $options['server_url'] : '';
        
        echo '<input type="url" name="bytegrader_options[server_url]" value="' . esc_attr($server_url) . '" class="regular-text" placeholder="https://your-bytegrader-server.com" />';
        echo '<p class="description">The base URL of your ByteGrader server (without trailing slash)</p>';
    }

    // Callback: set API key field
    public function api_key_field_callback() {
        $options = get_option('bytegrader_options', array());
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';
        
        echo '<input type="password" name="bytegrader_options[api_key]" value="' . esc_attr($api_key) . '" class="regular-text" placeholder="Enter your API key" />';
        echo '<p class="description">Your ByteGrader API key for authentication</p>';
    }

    // Sanitize settings input - ensures data is safe before saving
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['server_url'])) {
            $sanitized['server_url'] = esc_url_raw(rtrim($input['server_url'], '/'));
        }
        
        if (isset($input['api_key'])) {
            $sanitized['api_key'] = sanitize_text_field($input['api_key']);
        }
        
        return $sanitized;
    }

    // Add settings link to the plugin action links
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=bytegrader-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    // Test connection to ByteGrader server via AJAX
    public function ajax_test_connection() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'bgcld_test_connection')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // Get settings
        $settings = $this->get_bytegrader_settings();
        
        if (empty($settings['server_url']) || empty($settings['api_key'])) {
            wp_send_json_error('Please save your server URL and API key first');
        }
        
        // Build the config endpoint URL
        $config_url = rtrim($settings['server_url'], '/') . '/config';
        
        // Make the request
        $response = wp_remote_get($config_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['api_key'],
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            wp_send_json_error('Connection error: ' . $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            wp_send_json_error("Server returned status {$status_code}: " . $body);
        }
        
        // Try to decode JSON
        $json_data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON response from server');
        }
        
        // Success!
        wp_send_json_success($json_data);
    }
    
    // AJAX handler to check job status
    public function ajax_check_job_status() {
        if (!wp_verify_nonce($_POST['nonce'], 'bgcld_upload_nonce') || !is_user_logged_in()) {
            wp_send_json_error('Security check failed');
        }
        
        $job_id = sanitize_text_field($_POST['job_id']);
        $username = sanitize_text_field($_POST['username']);
        $quiz_id = intval($_POST['quiz_id']);
        $user_id = get_current_user_id();
        
        if (empty($job_id) || empty($username)) {
            wp_send_json_error('Missing job ID or username');
        }
        
        // Get ByteGrader settings
        $settings = $this->get_bytegrader_settings();
        if (empty($settings['server_url']) || empty($settings['api_key'])) {
            wp_send_json_error('ByteGrader server not configured');
        }
        
        // Check status
        $status_result = $this->check_bytegrader_status($settings, $job_id, $username);
        
        if ($status_result['success']) {
            $parsed_status = $this->parse_job_status($status_result['data']);
            
            // If completed, submit to LearnDash
            if ($parsed_status['status'] === 'completed' && $parsed_status['score'] !== null) {
                $this->submit_quiz_result($user_id, $quiz_id, $parsed_status['score']);
            }
            
            wp_send_json_success($parsed_status);
        } else {
            wp_send_json_error($status_result['error']);
        }
    }
    
    /***************************************************************************
     * Private methods
     */

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
        
        // Get max file size from quiz settings or use default
        $max_file_size = get_post_meta($quiz_id, '_max_file_size', true) ?: self::DEFAULT_MAX_FILE_SIZE_MB;

        // Construct submission form HTML
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
                                    <button type="button" class="button button-primary button-large" id="bgcld-submit-project" 
                                        disabled 
                                        data-quiz-id="' . esc_attr($quiz_id) . '"
                                        data-max-file-size="' . esc_attr($max_file_size) . '">
                                        Submit Project for Grading
                                    </button>
                                </div>
                                <div class="bgcld-status" style="display: none;">
                                    <div class="bgcld-message"></div>
                                </div>
                            </div>';
    
        // Add next lesson button if applicable
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
    
    // Construct request and send project submission to ByteGrader
    private function submit_to_bytegrader($settings, $assignment_id, $username, $file) {
        
        // Build the submit endpoint URL
        $submit_url = rtrim($settings['server_url'], '/') . '/submit?assignment=' . urlencode($assignment_id);
        
        $this->debug("Submitting to ByteGrader: {$submit_url}");
        $this->debug("Assignment: {$assignment_id}, Username: {$username}");
        $this->debug("File: {$file['name']}, Size: {$file['size']} bytes");
        
        // Use WordPress's built-in cURL file upload
        $boundary = wp_generate_password(24, false);
        
        // Read the file contents
        $file_contents = file_get_contents($file['tmp_name']);
        if ($file_contents === false) {
            return array(
                'success' => false,
                'error' => 'Unable to read uploaded file'
            );
        }
        
        // Construct proper multipart body
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="' . $file['name'] . '"' . "\r\n";
        $body .= 'Content-Type: application/zip' . "\r\n";
        $body .= "\r\n";
        $body .= $file_contents;
        $body .= "\r\n";
        $body .= '--' . $boundary . '--' . "\r\n";
        
        // Set up headers
        $headers = array(
            'X-API-Key' => $settings['api_key'],
            'X-Username' => $username,
            'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            'Content-Length' => strlen($body)
        );
        
        $this->debug("Request headers: " . print_r($headers, true));
        $this->debug("Body length: " . strlen($body) . " bytes");
        
        // Make the request
        $response = wp_remote_post($submit_url, array(
            'headers' => $headers,
            'body' => $body,
            'timeout' => 60,
            'method' => 'POST',
            'sslverify' => true,
            'data_format' => 'body'
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->debug('ByteGrader request error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->debug("ByteGrader response status: {$status_code}");
        $this->debug("ByteGrader response body: " . $response_body);
        
        if ($status_code !== 200) {
            return array(
                'success' => false,
                'error' => "Server returned status {$status_code}: " . $response_body
            );
        }
        
        // Try to decode JSON response
        $json_data = json_decode($response_body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $response_data = $json_data;
        } else {
            $response_data = $response_body;
        }
        
        return array(
            'success' => true,
            'data' => $response_data
        );
    }
    
    // Check the status of a ByteGrader job
    private function check_bytegrader_status($settings, $job_id, $username) {
        
        // Build the status endpoint URL
        $status_url = rtrim($settings['server_url'], '/') . '/status/' . urlencode($job_id);
        
        $this->debug("Checking ByteGrader status: {$status_url}");
        $this->debug("Job ID: {$job_id}, Username: {$username}");
        
        // Set up headers
        $headers = array(
            'X-API-Key' => $settings['api_key'],
            'X-Username' => $username,
            'Content-Type' => 'application/json'
        );
        
        $this->debug("Request headers: " . print_r($headers, true));
        
        // Make the request
        $response = wp_remote_get($status_url, array(
            'headers' => $headers,
            'timeout' => 15,
            'sslverify' => true
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            $this->debug('ByteGrader status check error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->debug("ByteGrader status response code: {$status_code}");
        $this->debug("ByteGrader status response body: " . $response_body);
        
        if ($status_code === 404) {
            return array(
                'success' => false,
                'error' => 'Job not found'
            );
        }
        
        if ($status_code === 403) {
            return array(
                'success' => false,
                'error' => 'Access denied - username mismatch'
            );
        }
        
        if ($status_code !== 200) {
            return array(
                'success' => false,
                'error' => "Server returned status {$status_code}: " . $response_body
            );
        }
        
        // Try to decode JSON response
        $json_data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'Invalid JSON response from server'
            );
        }
        
        return array(
            'success' => true,
            'data' => $json_data
        );
    }
    
    // Parse ByteGrader job response and extract useful information
    private function parse_job_status($job_response) {
        if (!isset($job_response['job'])) {
            return array(
                'status' => 'unknown',
                'error' => 'Invalid job response format'
            );
        }
        
        $job = $job_response['job'];
        
        $parsed = array(
            'job_id' => $job['id'] ?? '',
            'filename' => $job['filename'] ?? '',
            'size' => $job['size'] ?? 0,
            'status' => $job['status'] ?? 'unknown',
            'assignment_id' => $job['assignment_id'] ?? '',
            'username' => $job['username'] ?? '',
            'created_at' => $job['created_at'] ?? '',
            'updated_at' => $job['updated_at'] ?? '',
            'score' => null,
            'feedback' => '',
            'error' => ''
        );
        
        // Parse result if available
        if (isset($job['result'])) {
            $result = $job['result'];
            $parsed['score'] = $result['score'] ?? null;
            $parsed['feedback'] = $result['feedback'] ?? '';
            $parsed['error'] = $result['error'] ?? '';
        }
        
        return $parsed;
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
    
    private function get_quiz_assignment_id($quiz_id) {
        return get_post_meta($quiz_id, '_bytegrader_assignment_id', true);
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

    // Get ByteGrader settings from the database
    private function get_bytegrader_settings() {
        $defaults = array(
            'server_url' => '',
            'api_key' => ''
        );
        
        return wp_parse_args(get_option('bytegrader_options', array()), $defaults);
    }
}

// Initialize the plugin
add_action('plugins_loaded', function() {
    new LearnDashAutograderQuiz();
});
