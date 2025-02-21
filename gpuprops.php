<?php
/**
 * Plugin Name: CSS GPU Accelerator Pro
 * Description: Automatically transforms CSS animations to use GPU-accelerated properties using server-side parsing
 * Version: 1.0
 * Author: Your Name
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include Composer autoloader if not already included
if (!file_exists(plugin_dir_path(__FILE__) . 'vendor/autoload.php')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>CSS GPU Accelerator Pro requires Composer dependencies. Please run <code>composer install</code> in the plugin directory.</p></div>';
    });
    return;
}
require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

use Sabberworm\CSS\Parser;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Value\Size;
use Sabberworm\CSS\Value\CSSString;
use Sabberworm\CSS\Property\Selector;

class CSS_GPU_Accelerator_Pro {
    // Default settings
    private $default_options = [
        'enable_transform' => 'yes',
        'enable_will_change' => 'yes',
        'enable_backface' => 'yes',
        'enable_perspective' => 'yes',
        'exclude_selectors' => '',
        'target_selectors' => 'animation, transition, @keyframes'
    ];
    
    private $options;
    
    public function __construct() {
        // Initialize the plugin
        add_action('plugins_loaded', [$this, 'init']);
    }
    
    public function init() {
        // Load options
        $this->options = get_option('css_gpu_accelerator_options', $this->default_options);
        
        // Register hooks
        add_action('wp_enqueue_scripts', [$this, 'enqueue_original_styles'], 999);
        add_filter('style_loader_tag', [$this, 'process_stylesheet'], 10, 4);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Register activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);
    }
    
    public function activate() {
        // Create composer.json if it doesn't exist
        $composer_file = plugin_dir_path(__FILE__) . 'composer.json';
        if (!file_exists($composer_file)) {
            $composer_json = [
                "require" => [
                    "sabberworm/php-css-parser" => "^8.4"
                ]
            ];
            file_put_contents($composer_file, json_encode($composer_json, JSON_PRETTY_PRINT));
        }
        
        // Create directory for cached stylesheets
        $cache_dir = plugin_dir_path(__FILE__) . 'cache';
        if (!file_exists($cache_dir)) {
            mkdir($cache_dir, 0755, true);
        }
        
        // Save default options
        update_option('css_gpu_accelerator_options', $this->default_options);
        
        // Add notice to run composer install
        set_transient('css_gpu_accelerator_activation_notice', true, 5);
    }
    
    public function add_admin_menu() {
        add_options_page(
            'CSS GPU Accelerator Pro',
            'CSS GPU Accelerator',
            'manage_options',
            'css-gpu-accelerator',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('css_gpu_accelerator_settings', 'css_gpu_accelerator_options');
        
        add_settings_section(
            'css_gpu_accelerator_section',
            'GPU Acceleration Settings',
            [$this, 'settings_section_callback'],
            'css-gpu-accelerator'
        );
        
        add_settings_field(
            'enable_transform',
            'Enable transform: translateZ(0)',
            [$this, 'checkbox_callback'],
            'css-gpu-accelerator',
            'css_gpu_accelerator_section',
            [
                'label_for' => 'enable_transform',
                'field_name' => 'enable_transform',
                'description' => 'Adds transform: translateZ(0) to animated elements'
            ]
        );
        
        add_settings_field(
            'enable_will_change',
            'Enable will-change: transform',
            [$this, 'checkbox_callback'],
            'css-gpu-accelerator',
            'css_gpu_accelerator_section',
            [
                'label_for' => 'enable_will_change',
                'field_name' => 'enable_will_change',
                'description' => 'Adds will-change: transform to animated elements'
            ]
        );
        
        add_settings_field(
            'enable_backface',
            'Enable backface-visibility: hidden',
            [$this, 'checkbox_callback'],
            'css-gpu-accelerator',
            'css_gpu_accelerator_section',
            [
                'label_for' => 'enable_backface',
                'field_name' => 'enable_backface',
                'description' => 'Adds backface-visibility: hidden to animated elements'
            ]
        );
        
        add_settings_field(
            'enable_perspective',
            'Enable perspective: 1000px',
            [$this, 'checkbox_callback'],
            'css-gpu-accelerator',
            'css_gpu_accelerator_section',
            [
                'label_for' => 'enable_perspective',
                'field_name' => 'enable_perspective',
                'description' => 'Adds perspective: 1000px to animated elements'
            ]
        );
        
        add_settings_field(
            'target_selectors',
            'Target CSS Properties',
            [$this, 'text_callback'],
            'css-gpu-accelerator',
            'css_gpu_accelerator_section',
            [
                'label_for' => 'target_selectors',
                'field_name' => 'target_selectors',
                'description' => 'Comma-separated list of CSS properties/rules to target for GPU acceleration'
            ]
        );
        
        add_settings_field(
            'exclude_selectors',
            'Exclude Selectors',
            [$this, 'textarea_callback'],
            'css-gpu-accelerator',
            'css_gpu_accelerator_section',
            [
                'label_for' => 'exclude_selectors',
                'field_name' => 'exclude_selectors',
                'description' => 'Comma-separated list of CSS selectors to exclude from GPU acceleration'
            ]
        );
    }
    
    public function settings_section_callback() {
        echo '<p>Configure which GPU acceleration techniques to apply to your CSS animations.</p>';
    }
    
    public function checkbox_callback($args) {
        $field_name = $args['field_name'];
        $checked = isset($this->options[$field_name]) && $this->options[$field_name] === 'yes' ? 'checked' : '';
        
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . 
             '" name="css_gpu_accelerator_options[' . esc_attr($field_name) . ']" value="yes" ' . 
             $checked . '>';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    public function text_callback($args) {
        $field_name = $args['field_name'];
        $value = isset($this->options[$field_name]) ? $this->options[$field_name] : '';
        
        echo '<input type="text" id="' . esc_attr($args['label_for']) . 
             '" name="css_gpu_accelerator_options[' . esc_attr($field_name) . ']" value="' . 
             esc_attr($value) . '" class="regular-text">';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    public function textarea_callback($args) {
        $field_name = $args['field_name'];
        $value = isset($this->options[$field_name]) ? $this->options[$field_name] : '';
        
        echo '<textarea id="' . esc_attr($args['label_for']) . 
             '" name="css_gpu_accelerator_options[' . esc_attr($field_name) . ']" rows="4" class="large-text">' . 
             esc_textarea($value) . '</textarea>';
        
        if (isset($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('css_gpu_accelerator_settings');
                do_settings_sections('css-gpu-accelerator');
                submit_button('Save Settings');
                ?>
            </form>
            <hr>
            <h2>Cache Management</h2>
            <p>The plugin caches transformed stylesheets for better performance.</p>
            <form method="post" action="">
                <?php wp_nonce_field('css_gpu_accelerator_clear_cache', 'css_gpu_accelerator_nonce'); ?>
                <input type="hidden" name="action" value="clear_cache">
                <?php submit_button('Clear Cache', 'secondary', 'clear_cache_button'); ?>
            </form>
        </div>
        <?php
    }
    
    public function enqueue_original_styles() {
        // Nothing to do here - we're just hooking into the filter
    }
    
    public function process_stylesheet($tag, $handle, $href, $media) {
        // Skip processing if it's an admin page or if the URL is external
        if (is_admin() || strpos($href, site_url()) === false) {
            return $tag;
        }
        
        // Get the stylesheet URL and generate a cache key
        $cache_key = md5($href . json_encode($this->options));
        $cache_file = plugin_dir_path(__FILE__) . 'cache/' . $cache_key . '.css';
        
        // Check if we have a cached version
        if (file_exists($cache_file) && filemtime($cache_file) > (time() - WEEK_IN_SECONDS)) {
            $processed_url = plugins_url('cache/' . $cache_key . '.css', __FILE__);
            return str_replace($href, $processed_url, $tag);
        }
        
        // Get the CSS content
        $css_content = $this->fetch_css_content($href);
        if (empty($css_content)) {
            return $tag;
        }
        
        // Process the CSS with Sabberworm
        $processed_css = $this->transform_css_with_sabberworm($css_content);
        
        // Save the processed CSS to cache
        file_put_contents($cache_file, $processed_css);
        
        // Return the modified tag with the processed CSS URL
        $processed_url = plugins_url('cache/' . $cache_key . '.css', __FILE__);
        return str_replace($href, $processed_url, $tag);
    }
    
    private function fetch_css_content($url) {
        $response = wp_remote_get($url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        return wp_remote_retrieve_body($response);
    }
    
    private function transform_css_with_sabberworm($css_content) {
        try {
            // Initialize the parser
            $parser = new Parser($css_content);
            $css_document = $parser->parse();
            
            // Get the list of selectors to exclude
            $exclude_selectors = array_map('trim', explode(',', $this->options['exclude_selectors']));
            
            // Get the list of CSS properties/rules to target
            $target_properties = array_map('trim', explode(',', $this->options['target_selectors']));
            
            // Process all rule sets
            $all_contents = $css_document->getAllContents();
            
            foreach ($all_contents as $content) {
                if ($content instanceof DeclarationBlock) {
                    $selectors = $content->getSelectors();
                    $selector_strings = [];
                    
                    // Convert selectors to strings for checking exclusions
                    foreach ($selectors as $selector) {
                        $selector_strings[] = $selector->getSelector();
                    }
                    
                    // Skip excluded selectors
                    $should_exclude = false;
                    foreach ($exclude_selectors as $exclude) {
                        if (empty($exclude)) continue;
                        
                        foreach ($selector_strings as $selector_string) {
                            if (strpos($selector_string, $exclude) !== false) {
                                $should_exclude = true;
                                break 2;
                            }
                        }
                    }
                    
                    if ($should_exclude) {
                        continue;
                    }
                    
                    // Check if this rule contains any of our target properties
                    $has_target_property = false;
                    $rules = $content->getRules();
                    
                    foreach ($rules as $rule) {
                        $rule_name = $rule->getRule();
                        foreach ($target_properties as $target) {
                            if (empty($target)) continue;
                            
                            if (strpos($rule_name, $target) !== false) {
                                $has_target_property = true;
                                break 2;
                            }
                        }
                    }
                    
                    // Apply GPU acceleration properties
                    if ($has_target_property) {
                        // Add transform: translateZ(0)
                        if (isset($this->options['enable_transform']) && $this->options['enable_transform'] === 'yes') {
                            $content->addRule('transform', 'translateZ(0)');
                        }
                        
                        // Add will-change: transform
                        if (isset($this->options['enable_will_change']) && $this->options['enable_will_change'] === 'yes') {
                            $content->addRule('will-change', 'transform');
                        }
                        
                        // Add backface-visibility: hidden
                        if (isset($this->options['enable_backface']) && $this->options['enable_backface'] === 'yes') {
                            $content->addRule('backface-visibility', 'hidden');
                        }
                        
                        // Add perspective: 1000px
                        if (isset($this->options['enable_perspective']) && $this->options['enable_perspective'] === 'yes') {
                            $content->addRule('perspective', '1000px');
                        }
                    }
                }
            }
            
            // Get the modified CSS
            return $css_document->render();
            
        } catch (Exception $e) {
            // Log error and return original CSS on failure
            error_log('CSS GPU Accelerator Pro - CSS Parser Error: ' . $e->getMessage());
            return $css_content;
        }
    }
}

// Initialize the plugin
$css_gpu_accelerator = new CSS_GPU_Accelerator_Pro();

// Add notice for Composer installation
add_action('admin_notices', function() {
    if (get_transient('css_gpu_accelerator_activation_notice')) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p>Thank you for installing CSS GPU Accelerator Pro! To complete setup, please run <code>composer install</code> in the plugin directory to install the Sabberworm CSS Parser.</p>
        </div>
        <?php
        delete_transient('css_gpu_accelerator_activation_notice');
    }
});

// Process clear cache action
add_action('admin_init', function() {
    if (isset($_POST['action']) && $_POST['action'] === 'clear_cache' && 
        isset($_POST['css_gpu_accelerator_nonce']) && 
        wp_verify_nonce($_POST['css_gpu_accelerator_nonce'], 'css_gpu_accelerator_clear_cache')) {
        
        $cache_dir = plugin_dir_path(__FILE__) . 'cache';
        if (is_dir($cache_dir)) {
            $files = glob($cache_dir . '/*.css');
            foreach ($files as $file) {
                unlink($file);
            }
        }
        
        add_settings_error(
            'css_gpu_accelerator_messages',
            'css_gpu_accelerator_message',
            'Cache cleared successfully.',
            'updated'
        );
    }
});
