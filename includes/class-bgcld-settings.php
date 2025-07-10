<?php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BGCLD_Settings {

    // Constructor - initialize the settings, register hooks, and sets up admin menu
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
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
        
        // Debug field
        add_settings_field(
            'debug_mode',
            'Debug Mode',
            array($this, 'debug_mode_field_callback'),
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
                            let statusClass = 'success';
                            let statusIcon = '✅';
                            let statusTitle = 'Connection Successful';
                            
                            // Check for version compatibility warning
                            if (response.data.compatibility && !response.data.compatibility.compatible) {
                                statusClass = 'warning';
                                statusIcon = '⚠️';
                                statusTitle = 'Connection Successful (Version Warning)';
                            }
                            
                            let versionInfo = '';
                            if (response.data.version_info && response.data.compatibility) {
                                const vi = response.data.version_info;
                                const comp = response.data.compatibility;
                                
                                versionInfo = `
                                    <h5>Version Compatibility</h5>
                                    <p><strong>Server Version:</strong> ${vi.version || 'Unknown'}</p>
                                    <p><strong>Client Version:</strong> ${comp.client_version || 'Unknown'}</p>
                                    <p><strong>Status:</strong> ${comp.message || 'Unknown'}</p>
                                `;
                                
                                // Add build info if available
                                if (vi.build_time || vi.git_commit) {
                                    versionInfo += `<p><strong>Build Info:</strong> `;
                                    if (vi.build_time) versionInfo += `Built ${vi.build_time}`;
                                    if (vi.git_commit) versionInfo += ` (${vi.git_commit})`;
                                    versionInfo += `</p>`;
                                }
                            } else if (response.data.version_info && !response.data.version_info.success) {
                                versionInfo = `
                                    <h5>Version Check</h5>
                                    <p style="color: #d63384;"><strong>Warning:</strong> Could not retrieve version info - ${response.data.version_info.error}</p>
                                `;
                            }
                            
                            let configInfo = '';
                            if (response.data.config) {
                                configInfo = `
                                    <h5>Server Configuration</h5>
                                    <pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto;">${JSON.stringify(response.data.config, null, 2)}</pre>
                                `;
                            }
                            
                            resultDiv.html(
                                `<div style="padding: 10px; background: ${statusClass === 'success' ? '#d4edda' : '#fff3cd'}; border: 1px solid ${statusClass === 'success' ? '#c3e6cb' : '#ffeaa7'}; border-radius: 4px;">` +
                                `<h4 style="margin-top: 0; color: ${statusClass === 'success' ? '#155724' : '#856404'};">${statusIcon} ${statusTitle}</h4>` +
                                versionInfo +
                                configInfo +
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
    
    // Callback: set debug mode
    public function debug_mode_field_callback() {
        $options = get_option('bytegrader_options', array());
        $debug_mode = isset($options['debug_mode']) ? $options['debug_mode'] : false;
        
        echo '<label>';
        echo '<input type="checkbox" name="bytegrader_options[debug_mode]" value="1" ' . checked(1, $debug_mode, false) . ' />';
        echo ' Enable debug console logging';
        echo '</label>';
        echo '<p class="description">Shows detailed logging in browser console for troubleshooting</p>';
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
        
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? true : false;
        
        return $sanitized;
    }

    // Get ByteGrader settings from the database
    public function get_bytegrader_settings() {
        $defaults = array(
            'server_url' => '',
            'api_key' => ''
        );
        
        return wp_parse_args(get_option('bytegrader_options', array()), $defaults);
    }

    // Get debug mode setting
    public function get_debug_mode() {
        $options = get_option('bytegrader_options', array());
        return isset($options['debug_mode']) ? $options['debug_mode'] : false;
    }
}