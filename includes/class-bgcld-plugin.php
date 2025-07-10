<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BGCLD_Plugin {

    private $quiz_manager;
    private $settings;
    private $ajax_handlers;

    // Constructor - initialize the plugin components
    public function __construct() {
        $this->init();
    }

    // Initialize the plugin components and hooks
    public function init() {

        // Initialize components
        $this->quiz_manager = new BGCLD_Quiz_Manager();
        $this->settings = new BGCLD_Settings();
        $this->ajax_handlers = new BGCLD_Ajax_Handlers();
        
        // Core hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_filter('plugin_action_links_' . plugin_basename(BGCLD_PLUGIN_FILE), array($this, 'add_plugin_action_links'));
    }
    
    // Load assets for the plugin
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
            'quiz_id' => $post->ID,
            'debug_mode' => $this->get_debug_mode()
        ));
    }

    // Add settings link to the plugin action links
    public function add_plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=bytegrader-settings') . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    // Activate the plugin
    public static function activate() {
        flush_rewrite_rules();
    }
    
    // Deactivate the plugin
    public static function deactivate() {
        flush_rewrite_rules();
    }
}
