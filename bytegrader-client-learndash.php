<?php
/**
 * Plugin Name: ByteGrader Client for LearnDash
 * Plugin URI: https://github.com/ShawnHymel/bytegrader-client-learndash
 * Description: Integrates ByteGrader autograding service with LearnDash LMS for automated code assessment
 * Version: 0.8.1
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

// Plugin constants
define('BGCLD_VERSION', '0.8.0');
define('BGCLD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BGCLD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BGCLD_PLUGIN_FILE', __FILE__);

// Version compatibility
define('BGCLD_MIN_BYTEGRADER_VERSION', '0.8.0');
define('BGCLD_MAX_BYTEGRADER_VERSION', '0.9.999');
define('BGCLD_TESTED_BYTEGRADER_VERSION', '0.8.1');

// Autoloader
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'BGCLD_') === 0) {
        $file_name = 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        $file_path = BGCLD_PLUGIN_DIR . 'includes/' . $file_name;
        
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
});

// Initialize plugin
add_action('plugins_loaded', function() {
    if (!class_exists('BGCLD_Plugin')) {
        require_once BGCLD_PLUGIN_DIR . 'includes/class-bgcld-plugin.php';
    }
    
    BGCLD_Plugin::get_instance();
});

// Activation/deactivation hooks
register_activation_hook(__FILE__, array('BGCLD_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('BGCLD_Plugin', 'deactivate'));
