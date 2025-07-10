<?php

if (!defined('ABSPATH')) {
    exit;
}

class BGCLD_Ajax_Handlers {

    private $bytegrader_client;
    private $quiz_manager;
    private $version_checker;
    private $settings;
    
    public function __construct() {
        $this->bytegrader_client = new BGCLD_Bytegrader_Client();
        $this->quiz_manager = new BGCLD_Quiz_Manager();
        $this->version_checker = new BGCLD_Version_Checker();
        $this->settings = new BGCLD_Settings();
    }

        // Register AJAX handlers
        add_action('wp_ajax_bgcld_upload_project', array($this, 'handle_project_upload'));
        add_action('wp_ajax_nopriv_bgcld_upload_project', array($this, 'handle_project_upload'));
        add_action('wp_ajax_bgcld_check_job_status', array($this, 'ajax_check_job_status'));
        add_action('wp_ajax_bgcld_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_get_next_lesson_url', array($this, 'ajax_get_next_lesson_url'));
        add_action('wp_ajax_nopriv_get_next_lesson_url', array($this, 'ajax_get_next_lesson_url'));
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
        
        // Get assignment ID
        $assignment_id = $this->quiz_manager->get_quiz_assignment_id($quiz_id);
        if (empty($assignment_id)) {
            wp_send_json_error('Assignment ID not configured for this quiz. Please contact your administrator.');
        }
        
        // Get current user info
        $user = wp_get_current_user();
        $username = $user->user_login; // or use $user->user_email if you prefer
        
        // Submit to ByteGrader server
        $bytegrader_result = $this->bytegrader_client->submit_project($assignment_id, $username, $file);
        
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
            // Check if this is a duplicate submission error (409 Conflict)
            $error_message = $bytegrader_result['error'];
            
            if (strpos($error_message, 'Server returned status 409') !== false) {
                // Parse the response body for queue info
                $response_body = $bytegrader_result['response_body'] ?? '';
                $conflict_data = json_decode($response_body, true);
                
                if ($conflict_data && isset($conflict_data['queue_info'])) {
                    $queue_info = $conflict_data['queue_info'];
                    $queue_msg = '';
                    
                    if ($queue_info['queue_length'] > 0) {
                        $queue_msg = " There are {$queue_info['queue_length']} jobs in the queue.";
                    }
                    
                    wp_send_json_error(array(
                        'type' => 'duplicate_submission',
                        'message' => $conflict_data['error'] . $queue_msg,
                        'existing_job_id' => $conflict_data['existing_job_id'] ?? '',
                        'queue_info' => $queue_info
                    ));
                } else {
                    wp_send_json_error('You already have a submission being graded. Please wait for it to complete.');
                }
            } else {
                wp_send_json_error('Submission failed: ' . $error_message);
            }
        }
    }

    // Get next lesson URL based on quiz ID
    public function ajax_get_next_lesson_url() {
        if (!wp_verify_nonce($_POST['nonce'], 'next_lesson_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        $quiz_id = intval($_POST['quiz_id']);
        $next_url = $this->quiz_manager->get_next_lesson_url($quiz_id);
        
        wp_send_json_success(array('next_url' => $next_url));
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
        $settings = $this->settings->get_bytegrader_settings();
        
        if (empty($settings['server_url']) || empty($settings['api_key'])) {
            wp_send_json_error('Please save your server URL and API key first');
        }
        
        // Get server information
        $config_result = $this->version_checker->get_server_config($settings);
        $version_result = $this->version_checker->get_server_version($settings);
        
        // If we can't connect at all, fail early
        if (!$config_result['success']) {
            wp_send_json_error($config_result['error']);
        }
        
        // Check version compatibility if we got version info
        $compatibility_check = null;
        if ($version_result['success'] && $version_result['version']) {
            $compatibility_check = $this->version_checker->check_version_compatibility($version_result['version']);
        }
        
        $response_data = array(
            'config' => $config_result['config'],
            'version_info' => $version_result,  // Use the raw version result
            'compatibility' => $compatibility_check  // Use the compatibility check result
        );
        
        // Add warning if version is incompatible
        if ($compatibility_check && !$compatibility_check['compatible']) {
            $response_data['warning'] = $compatibility_check['message'];
        }
        
        wp_send_json_success($response_data);
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
        $settings = $this->settings->get_bytegrader_settings();
        if (empty($settings['server_url']) || empty($settings['api_key'])) {
            wp_send_json_error('ByteGrader server not configured');
        }
        
        // Check status
        $status_result = $this->bytegrader_client->check_job_status($settings, $job_id, $username);
        
        if ($status_result['success']) {
            $parsed_status = $this->bytegrader_client->parse_job_status($status_result['data']);
            
            // If job is queued, also get queue information
            if ($parsed_status['status'] === 'queued') {
                $queue_result = $this->bytegrader_client->check_bytegrader_queue($settings, $username);
                if ($queue_result['success']) {
                    $parsed_status['queue_info'] = $queue_result['data'];
                }
            }
            
            // If completed, submit to LearnDash
            if ($parsed_status['status'] === 'completed' && $parsed_status['score'] !== null) {
                $this->quiz_manager->submit_quiz_result($user_id, $quiz_id, $parsed_status['score']);
                
                // Store detailed attempt info for display
                $this->quiz_manager->store_latest_attempt(
                    $user_id, 
                    $quiz_id, 
                    $parsed_status['score'], 
                    $parsed_status['feedback'], 
                    $parsed_status['job_id']
                );
            }
            
            wp_send_json_success($parsed_status);
        } else {
            wp_send_json_error($status_result['error']);
        }
    }
}