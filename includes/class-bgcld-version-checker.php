<?php

if (!defined('ABSPATH')) {
    exit;
}

class BGCLD_Version_Checker {

    // Get configuration settings from the server
    public function get_server_config($settings) {
        $config_url = rtrim($settings['server_url'], '/') . '/config';
        
        $response = wp_remote_get($config_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['api_key'],
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => 'Connection error: ' . $response->get_error_message(),
                'config' => null
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            return array(
                'success' => false,
                'error' => "Server returned status {$status_code}: " . $body,
                'config' => null
            );
        }
        
        $json_data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'Invalid JSON response from server',
                'config' => null
            );
        }
        
        return array(
            'success' => true,
            'config' => $json_data
        );
    }
    
    // Get version number from ByteGrader /version endpoint
    public function get_server_version($settings) {
        $version_url = rtrim($settings['server_url'], '/') . '/version';
        
        $response = wp_remote_get($version_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $settings['api_key'],
                'Content-Type' => 'application/json'
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => 'Could not check server version: ' . $response->get_error_message(),
                'version' => null,
                'build_time' => null,
                'git_commit' => null
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code !== 200) {
            return array(
                'success' => false,
                'error' => "Server version check failed (status $status_code): " . $body,
                'version' => null,
                'build_time' => null,
                'git_commit' => null
            );
        }
        
        $version_data = json_decode($body, true);
        if (!$version_data || !isset($version_data['version'])) {
            return array(
                'success' => false,
                'error' => 'Invalid version response from server: ' . $body,
                'version' => null,
                'build_time' => null,
                'git_commit' => null
            );
        }
        
        return array(
            'success' => true,
            'version' => $version_data['version'],
            'build_time' => $version_data['build_time'] ?? null,
            'git_commit' => $version_data['git_commit'] ?? null,
            'error' => null
        );
    }
    
    // Check that the BGCLD is compatible with the server (based on min/max versions)
    public function check_version_compatibility($server_version) {
        if (version_compare($server_version, BGCLD_MIN_BYTEGRADER_VERSION, '<')) {
            return array(
                'compatible' => false,
                'message' => "ByteGrader server version $server_version is too old. Minimum required: " . BGCLD_MIN_BYTEGRADER_VERSION,
                'client_version' => BGCLD_VERSION,
                'server_version' => $server_version
            );
        }
        
        if (version_compare($server_version, BGCLD_MAX_BYTEGRADER_VERSION, '>')) {
            return array(
                'compatible' => false,
                'message' => "ByteGrader server version $server_version is too new. Maximum supported: " . BGCLD_MAX_BYTEGRADER_VERSION,
                'client_version' => BGCLD_VERSION,
                'server_version' => $server_version
            );
        }
        
        if (version_compare($server_version, BGCLD_TESTED_BYTEGRADER_VERSION, '!=')) {
            return array(
                'compatible' => true,
                'message' => "ByteGrader server version $server_version is compatible but not fully tested. Tested with: " . BGCLD_TESTED_BYTEGRADER_VERSION,
                'client_version' => BGCLD_VERSION,
                'server_version' => $server_version
            );
        }
        
        return array(
            'compatible' => true,
            'message' => "ByteGrader server version $server_version is fully compatible",
            'client_version' => BGCLD_VERSION,
            'server_version' => $server_version
        );
    }
}
