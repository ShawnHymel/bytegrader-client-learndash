<?php

if (!defined('ABSPATH')) {
    exit;
}

class BGCLD_Bytegrader_Client {

    private $settings;
    private $version_checker;
    
    // Constructor - initialize settings and version checker
    public function __construct() {
        $this->settings = new BGCLD_Settings();
        $this->version_checker = new BGCLD_Version_Checker();
    }

    // Wrapper for submitting a project to ByteGrader
    public function submit_project($assignment_id, $username, $file) {
        $settings = $this->settings->get_bytegrader_settings();
        
        if (empty($settings['server_url']) || empty($settings['api_key'])) {
            return array(
                'success' => false,
                'error' => 'ByteGrader server not configured'
            );
        }
        
        // Quick version check
        $version_check = $this->version_checker->check_compatibility($settings);
        if (!$version_check['compatible']) {
            BGCLD_Plugin::debug("Version compatibility warning: " . $version_check['message']);
        }
        
        return $this->submit_to_bytegrader($settings, $assignment_id, $username, $file);
    }

    // Wrapper for checking job status
    public function check_job_status($job_id, $username) {
        $settings = $this->settings->get_bytegrader_settings();

        if (empty($settings['server_url']) || empty($settings['api_key'])) {
            return array(
                'success' => false,
                'error' => 'ByteGrader server not configured'
            );
        }

        return $this->check_bytegrader_status($settings, $job_id, $username);
    }

    // Parse ByteGrader job response and extract useful information
    public function parse_job_status($job_response) {
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

    // Check ByteGrader queue status
    public function check_bytegrader_queue($settings, $username) {

        // Build the queue endpoint URL
        $queue_url = rtrim($settings['server_url'], '/') . '/queue';
        
        BGCLD_Plugin::debug("Checking ByteGrader queue: {$queue_url}");
        
        // Set up headers
        $headers = array(
            'X-API-Key' => $settings['api_key'],
            'X-Username' => $username,
            'Content-Type' => 'application/json'
        );
        
        // Make the request
        $response = wp_remote_get($queue_url, array(
            'headers' => $headers,
            'timeout' => 10,
            'sslverify' => true
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            BGCLD_Plugin::debug('ByteGrader queue check error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        BGCLD_Plugin::debug("ByteGrader queue response code: {$status_code}");
        BGCLD_Plugin::debug("ByteGrader queue response body: " . $response_body);
        
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

    // Construct request and send project submission to ByteGrader
    private function submit_to_bytegrader($settings, $assignment_id, $username, $file) {
        
        // Quick version compatibility check before submission
        $version_check = $this->version_checker->get_server_version($settings);
        if (!$version_check['compatible']) {
            BGCLD_Plugin::debug("Version compatibility warning: " . $version_check['message']);
            // Log warning but continue - don't block submissions for minor version differences
        }
        
        // Build the submit endpoint URL
        $submit_url = rtrim($settings['server_url'], '/') . '/submit?assignment=' . urlencode($assignment_id);
        
        BGCLD_Plugin::debug("Submitting to ByteGrader: {$submit_url}");
        BGCLD_Plugin::debug("Assignment: {$assignment_id}, Username: {$username}");
        BGCLD_Plugin::debug("File: {$file['name']}, Size: {$file['size']} bytes");
        
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
        
        BGCLD_Plugin::debug("Request headers: " . print_r($headers, true));
        BGCLD_Plugin::debug("Body length: " . strlen($body) . " bytes");
        
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
            BGCLD_Plugin::debug('ByteGrader request error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        BGCLD_Plugin::debug("ByteGrader response status: {$status_code}");
        BGCLD_Plugin::debug("ByteGrader response body: " . $response_body);
        
        if ($status_code !== 200) {
            return array(
                'success' => false,
                'error' => "Server returned status {$status_code}: " . $response_body,
                'response_body' => $response_body,
                'status_code' => $status_code
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
        
        BGCLD_Plugin::debug("Checking ByteGrader status: {$status_url}");
        BGCLD_Plugin::debug("Job ID: {$job_id}, Username: {$username}");
        
        // Set up headers
        $headers = array(
            'X-API-Key' => $settings['api_key'],
            'X-Username' => $username,
            'Content-Type' => 'application/json'
        );
        
        BGCLD_Plugin::debug("Request headers: " . print_r($headers, true));
        
        // Make the request
        $response = wp_remote_get($status_url, array(
            'headers' => $headers,
            'timeout' => 15,
            'sslverify' => true
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            BGCLD_Plugin::debug('ByteGrader status check error: ' . $response->get_error_message());
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        BGCLD_Plugin::debug("ByteGrader status response code: {$status_code}");
        BGCLD_Plugin::debug("ByteGrader status response body: " . $response_body);
        
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
}