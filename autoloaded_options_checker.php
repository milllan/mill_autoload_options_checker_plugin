<?php
/**
 * Plugin Name:       Autoloaded Options Optimizer
 * Plugin URI:        https://github.com/milllan/mill_autoload_options_checker_plugin
 * Description:       A tool to analyze, view, and manage autoloaded options in the wp_options table, with a remotely managed configuration.
 * Version:           3.1
 * Author:            Milan
 * Author URI:        https://wpspeedopt.net/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autoload-optimizer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// --- Main Plugin Hooks ---

add_action('admin_menu', 'ao_add_admin_page');
function ao_add_admin_page() {
    add_management_page(
        __('Autoloaded Options', 'autoload-optimizer'),
        __('Autoloaded Options', 'autoload-optimizer'),
        'manage_options',
        'autoloaded-options',
        'ao_display_admin_page'
    );
}

add_action('admin_init', 'ao_register_ajax_handlers');
function ao_register_ajax_handlers() {
    add_action('wp_ajax_ao_disable_autoload_options', 'ao_ajax_disable_autoload_options');
    add_action('wp_ajax_ao_get_option_value', 'ao_ajax_get_option_value');
}

/**
 * Manages the remote configuration.
 */
final class AO_Remote_Config_Manager {
    private static $instance;
    private const REMOTE_URL = 'https://raw.githubusercontent.com/milllan/mill_autoload_options_checker_plugin/main/config.json';
    private const CACHE_KEY = 'ao_remote_config_cache';
    private const CACHE_DURATION = 30 * DAY_IN_SECONDS;
    private $config_status = 'Not loaded yet.';

    private function __construct() {}

    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get_config() {
        $config = get_transient(self::CACHE_KEY);

        if (false === $config) {
            $this->config_status = __('Fetching from GitHub...', 'autoload-optimizer');
            $remote_config = $this->fetch_remote_config();
            
            if (false !== $remote_config) {
                $this->config_status = sprintf(
                    __('Live (Fetched from GitHub, Version: %s)', 'autoload-optimizer'),
                    esc_html($remote_config['version'] ?? 'N/A')
                );
                set_transient(self::CACHE_KEY, $remote_config, self::CACHE_DURATION);
                return $remote_config;
            } else {
                $this->config_status = __('Error: Could not fetch remote config and no fallback is available.', 'autoload-optimizer');
                return $this->get_empty_config_structure();
            }
        }

        $this->config_status = sprintf(
            __('Cached (Version: %s)', 'autoload-optimizer'),
            esc_html($config['version'] ?? 'N/A')
        );
        return $config;
    }

    private function fetch_remote_config() {
        $response = wp_remote_get(self::REMOTE_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $config = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !$this->is_config_valid($config)) {
            return false;
        }
        
        return $config;
    }

    private function is_config_valid($config) {
        if (!is_array($config)) return false;
        $required_keys = ['version', 'plugin_mappings', 'safe_literals', 'safe_patterns'];
        foreach ($required_keys as $key) {
            if (!isset($config[$key])) return false;
        }
        return true;
    }

    private function get_empty_config_structure() {
        return [
            'version' => '0.0.0 (Error)', 'plugin_mappings' => [], 'safe_literals' => [],
            'safe_patterns' => [], 'recommendations' => [], 'general_recommendations' => [],
        ];
    }

    public function get_config_status() {
        return $this->config_status;
    }
}

function ao_get_config() {
    return AO_Remote_Config_Manager::get_instance()->get_config();
}

// --- Display & UI Logic ---

function ao_display_admin_page() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $config_manager = AO_Remote_Config_Manager::get_instance();
    $config = $config_manager->get_config();
    $status_message = $config_manager->get_config_status();

    $options = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS option_length FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY option_length DESC");
    $active_plugin_paths = get_option('active_plugins', []);
    $grouped_options = [];
    $total_size = 0;
    
    foreach ($options as $option) {
        $total_size += $option->option_length;
        if ($option->option_length < 1024) continue;

        $is_safe = in_array($option->option_name, $config['safe_literals']);
        if (!$is_safe && !empty($config['safe_patterns'])) {
            $glob_patterns = array_map(
                static fn($p) => strtr($p, ['%' => '*', '_' => '?']),
                $config['safe_patterns']
            );
            foreach ($glob_patterns as $pattern) {
                if (fnmatch($pattern, $option->option_name)) { $is_safe = true; break; }
            }
        }
        }
        
        $plugin_name = __('Unknown', 'autoload-optimizer');
        $status_info = ['text' => __('Unknown', 'autoload-optimizer'), 'class' => ''];

        if (isset($config['plugin_mappings'][$option->option_name])) {
            $mapping = $config['plugin_mappings'][$option->option_name];
            $plugin_name = $mapping['name'];
            if ($mapping['file'] === 'core') $status_info = ['text' => __('WordPress Core', 'autoload-optimizer'), 'class' => 'notice-info'];
            elseif ($mapping['file'] === 'theme') $status_info = ['text' => __('Active Theme', 'autoload-optimizer'), 'class' => 'notice-info'];
            elseif (in_array($mapping['file'], $active_plugin_paths)) $status_info = ['text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success'];
            else $status_info = ['text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
        } else {
            if (strpos($option->option_name, 'elementor') === 0) $plugin_name = 'Elementor';
            elseif (strpos($option->option_name, 'wpseo') === 0) $plugin_name = 'Yoast SEO';
            elseif (strpos($option->option_name, 'rocket') === 0) $plugin_name = 'WP Rocket';
            elseif (strpos($option->option_name, 'transient') !== false) $plugin_name = 'WordPress Core (Transient)';
        }

        if (!isset($grouped_options[$plugin_name])) {
            $grouped_options[$plugin_name] = ['total_size' => 0, 'count' => 0, 'options' => []];
        }

        $grouped_options[$plugin_name]['total_size'] += $option->option_length;
        $grouped_options[$plugin_name]['count']++;
        $grouped_options[$plugin_name]['options'][] = ['name' => $option->option_name, 'length' => $option->option_length, 'is_safe' => $is_safe, 'status' => $status_info];
    }
    
    uasort($grouped_options, fn($a, $b) => $b['total_size'] <=> $a['total_size']);

    ?>
    <div class="wrap">
        <h1><?php _e('Autoloaded Options Optimizer', 'autoload-optimizer'); ?></h1>
        <div class="notice notice-info notice-alt"><p><strong><?php _e('Configuration Status:', 'autoload-optimizer'); ?></strong> <?php echo esc_html($status_message); ?></p></div>

        <?php if (empty($options)) : ?>
            <p><?php _e('No autoloaded options found.', 'autoload-optimizer'); ?></p>
        <?php else : ?>
            <div class="notice notice-warning notice-alt"><p><?php printf(__('<strong>Total Found:</strong> %d autoloaded options (%s).', 'autoload-optimizer'), count($options), size_format($total_size)); ?></p></div>
            
            <div id="ao-results-container" style="display:none; margin-top: 1rem;"></div>

            <div class="card" style="margin-top: 1rem;">
                <h2 class="title"><?php _e('Bulk Actions', 'autoload-optimizer'); ?></h2>
                <p><?php _e('Select multiple options from the table below and disable their autoload status in one go.', 'autoload-optimizer'); ?></p>
                <button id="ao-disable-selected" class="button button-primary"><?php _e('Disable Autoload for Selected', 'autoload-optimizer'); ?></button>
                <span class="spinner" style="float: none; margin-left: 5px;"></span>
            </div>

            <h2><?php _e('Large Autoloaded Options (>1KB)', 'autoload-optimizer'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th id="cb" class="manage-column column-cb check-column"><input type="checkbox" /></th>
                        <th><?php _e('Option Name / Status', 'autoload-optimizer'); ?></th>
                        <th><?php _e('Size', 'autoload-optimizer'); ?></th>
                        <th><?php _e('Action', 'autoload-optimizer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grouped_options as $plugin_name => $data) : ?>
                        <tr class="plugin-header">
                            <th class="check-column"></th>
                            <td colspan="3"><strong><?php echo esc_html($plugin_name); ?></strong> <small>(<?php printf('%d options, %s total', $data['count'], size_format($data['total_size'])); ?>)</small></td>
                        </tr>
                        <?php foreach ($data['options'] as $option) : ?>
                            <tr>
                                <th class="check-column">
                                    <?php if ($option['status']['text'] === __('Inactive Plugin', 'autoload-optimizer') || $option['is_safe']) : ?>
                                        <input type="checkbox" class="ao-option-checkbox" value="<?php echo esc_attr($option['name']); ?>">
                                    <?php endif; ?>
                                </th>
                                <td>
                                    <strong><a href="#" class="view-option-content" data-option-name="<?php echo esc_attr($option['name']); ?>"><?php echo esc_html($option['name']); ?></a></strong>
                                    <?php if ($option['is_safe']) : ?><span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="<?php _e('Safe to disable autoload', 'autoload-optimizer'); ?>"></span><?php endif; ?>
                                    <br><small class="<?php echo esc_attr($option['status']['class']); ?>"><?php echo esc_html($option['status']['text']); ?></small>
                                </td>
                                <td><?php echo size_format($option['length']); ?></td>
                                <td>
                                    <?php if ($option['status']['text'] === __('Inactive Plugin', 'autoload-optimizer') || $option['is_safe']) : ?>
                                        <button class="button disable-single" data-option="<?php echo esc_attr($option['name']); ?>"><?php _e('Disable Autoload', 'autoload-optimizer'); ?></button>
                                    <?php else : ?>
                                        <span title="<?php _e('Disabling autoload for core or active plugins is not recommended.', 'autoload-optimizer'); ?>"><?php _e('N/A', 'autoload-optimizer'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="card" style="margin-top: 2rem;">
                <h2><?php _e('Recommendations', 'autoload-optimizer'); ?></h2>
                <ul>
                    <?php
                    $displayed_recs = [];
                    foreach ($grouped_options as $plugin_name => $data) {
                        if (isset($config['recommendations'][$plugin_name]) && !in_array($plugin_name, $displayed_recs)) {
                            echo '<li>' . wp_kses_post($config['recommendations'][$plugin_name]) . '</li>';
                            $displayed_recs[] = $plugin_name;
                        }
                    }
                    foreach ($config['general_recommendations'] as $rec) { echo '<li>' . wp_kses_post($rec) . '</li>'; }
                    ?>
                </ul>
            </div>
            <div class="notice notice-error" style="margin-top: 1rem;"><p><strong><?php _e('Warning:', 'autoload-optimizer'); ?></strong> <?php _e('Always have a backup before making changes.', 'autoload-optimizer'); ?></p></div>
        <?php endif; ?>

        <div id="ao-option-modal-overlay"><div id="ao-option-modal-content"><span class="close-modal">&times;</span><h2 id="ao-option-modal-title"></h2><div id="ao-modal-body"></div></div></div>
    </div>
    <style>.plugin-header th, .plugin-header td { font-weight: bold; background-color: #f6f6f6; } .view-option-content { cursor: pointer; } #ao-option-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); z-index: 10001; justify-content: center; align-items: center; } #ao-option-modal-content { background: #fff; padding: 20px; border-radius: 4px; width: 80%; max-width: 900px; max-height: 80vh; overflow-y: auto; position: relative; } .close-modal { position: absolute; top: 5px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; color: #555; } #ao-modal-body pre { background: #f1f1f1; padding: 15px; border: 1px solid #ddd; white-space: pre-wrap; word-wrap: break-word; }</style>
    <?php
}

// --- AJAX Handlers & JavaScript ---

function ao_ajax_get_option_value() {
    check_ajax_referer('ao_view_option_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);
    $option_name = sanitize_text_field($_POST['option_name']);
    $option_value = get_option($option_name);
    if (false === $option_value) wp_send_json_error(['message' => __('Option not found.', 'autoload-optimizer')]);
    wp_send_json_success(['value' => '<pre>' . esc_html(print_r($option_value, true)) . '</pre>']);
}

function ao_ajax_disable_autoload_options() {
    check_ajax_referer('ao_disable_autoload_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);
    }

    $options_to_disable = isset($_POST['option_names']) ? (array) $_POST['option_names'] : [];
    if (empty($options_to_disable)) {
        wp_send_json_error(['message' => __('No options were selected.', 'autoload-optimizer')]);
    }

    // Prepare for logging
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/autoload-options-debug.log';
    $log_content = "\n=== Autoload Disable Action - " . date('Y-m-d H:i:s') . " ===\n";
    $log_content .= "User: " . wp_get_current_user()->user_login . "\n";
    
    global $wpdb;
    $success_count = 0;
    $failure_count = 0;
    $already_done = 0;

    foreach ($options_to_disable as $option_name) {
        $sane_option_name = sanitize_text_field($option_name);
        $current_autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $sane_option_name));

        if ('no' === $current_autoload) {
            $already_done++;
            $log_content .= "[SKIPPED] '{$sane_option_name}' - Autoload was already 'no'.\n";
            continue;
        }

        $result = $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => $sane_option_name]);
        
        if (false === $result) {
            $failure_count++;
            $log_content .= "[FAILED]  '{$sane_option_name}' - Database error on update.\n";
        } else {
            $success_count++;
            $log_content .= "[SUCCESS] '{$sane_option_name}' - Set autoload to 'no'.\n";
        }
    }

    // Write to the log file
    file_put_contents($log_file, $log_content, FILE_APPEND);

    $message = sprintf(
        __('Processed %d options: %d successfully disabled, %d failed, %d already disabled.', 'autoload-optimizer'),
        count($options_to_disable), $success_count, $failure_count, $already_done
    );
    $message .= ' ' . sprintf(__('A detailed record has been saved to %s.', 'autoload-optimizer'), '<code>/wp-content/uploads/autoload-options-debug.log</code>');

    wp_send_json_success(['message' => $message]);
}

add_action('admin_footer', 'ao_admin_page_scripts');
function ao_admin_page_scripts() {
    $screen = get_current_screen();
    if (!$screen || 'tools_page_autoloaded-options' !== $screen->id) return;
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        const modalOverlay = document.getElementById('ao-option-modal-overlay');
        const modalContent = document.getElementById('ao-option-modal-content');
        const modalTitle = document.getElementById('ao-option-modal-title');
        const modalBody = document.getElementById('ao-modal-body');
        const resultsContainer = document.getElementById('ao-results-container');
        const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

        function showModal(title, content) { modalTitle.textContent = title; modalBody.innerHTML = content; modalOverlay.style.display = 'flex'; }
        function hideModal() { modalOverlay.style.display = 'none'; }
        function showResult(message, type = 'success') { resultsContainer.innerHTML = `<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`; resultsContainer.style.display = 'block'; }

        function disableOptions(optionNames, button) {
            if (!confirm(`<?php _e('Are you sure you want to disable autoload for the selected option(s)?', 'autoload-optimizer'); ?>`)) {
                return;
            }

            const isBulk = Array.isArray(optionNames) && optionNames.length > 1;
            const spinner = isBulk ? document.querySelector('#ao-disable-selected + .spinner') : null;
            
            if (button) button.disabled = true;
            if (spinner) spinner.classList.add('is-active');

            const formData = new FormData();
            formData.append('action', 'ao_disable_autoload_options');
            formData.append('nonce', '<?php echo wp_create_nonce('ao_disable_autoload_nonce'); ?>');
            optionNames.forEach(name => formData.append('option_names[]', name));

            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showResult(data.data.message, 'success');
                        setTimeout(() => location.reload(), 3000);
                    } else {
                        showResult(data.data.message, 'error');
                    }
                })
                .finally(() => {
                    if (button) button.disabled = false;
                    if (spinner) spinner.classList.remove('is-active');
                });
        }

        document.querySelector('.wp-list-table').addEventListener('click', function(e) {
            if (e.target.classList.contains('view-option-content')) {
                e.preventDefault();
                const optionName = e.target.dataset.optionName;
                showModal('<?php _e('Viewing:', 'autoload-optimizer'); ?> ' + optionName, '<?php _e('Loading...', 'autoload-optimizer'); ?>');
                const formData = new FormData();
                formData.append('action', 'ao_get_option_value');
                formData.append('nonce', '<?php echo wp_create_nonce('ao_view_option_nonce'); ?>');
                formData.append('option_name', optionName);
                fetch(ajaxurl, { method: 'POST', body: formData }).then(r => r.json()).then(d => modalBody.innerHTML = d.success ? d.data.value : `<p style="color:red;">${d.data.message}</p>`);
            }
            if (e.target.classList.contains('disable-single')) {
                e.preventDefault();
                disableOptions([e.target.dataset.option], e.target);
            }
        });
        
        document.getElementById('ao-disable-selected').addEventListener('click', function(e){
            const selected = Array.from(document.querySelectorAll('.ao-option-checkbox:checked')).map(cb => cb.value);
            if (selected.length === 0) {
                alert('<?php _e('Please select at least one option from the table.', 'autoload-optimizer'); ?>');
                return;
            }
            disableOptions(selected, e.target);
        });

        modalOverlay.addEventListener('click', (e) => { if (e.target === modalOverlay) hideModal(); });
        modalContent.querySelector('.close-modal').addEventListener('click', hideModal);
    });
    </script>
    <?php
}