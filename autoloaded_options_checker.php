<?php
/**
 * Plugin Name:       Autoloaded Options Optimizer
 * Plugin URI:        https://github.com/milllan/mill_autoload_options_checker_plugin
 * Description:       A tool to analyze, view, and manage autoloaded options in the wp_options table, with a remotely managed configuration.
 * Version:           3.6.1
 * Author:            Milan Petrović
 * Author URI:        https://wpspeedopt.net/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autoload-optimizer
 * Update URI:        https://github.com/milllan/mill_autoload_options_checker_plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// --- Main Plugin Hooks ---

add_action('admin_menu', 'ao_add_admin_page');
function ao_add_admin_page() {
    add_management_page(
        __('Autoloaded Options Optimizer', 'autoload-optimizer'),
        __('Autoloaded Options Optimizer', 'autoload-optimizer'),
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
    private const DEFAULT_REMOTE_URL = 'https://raw.githubusercontent.com/milllan/mill_autoload_options_checker_plugin/main/config.json';
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
            $this->config_status = __('Fetching fresh config from GitHub...', 'autoload-optimizer');
            $remote_config = $this->fetch_remote_config();
            
            if (false !== $remote_config) {
                $this->config_status = sprintf(
                    __('Live (Fetched from GitHub, Version: %s)', 'autoload-optimizer'),
                    esc_html($remote_config['version'] ?? 'N/A')
                );
                set_transient(self::CACHE_KEY, $remote_config, self::CACHE_DURATION);
                return $remote_config;
            } else {
                $this->config_status = __('Error: Could not fetch remote config. No fallback available.', 'autoload-optimizer');
                return $this->get_empty_config_structure();
            }
        }

        $this->config_status = sprintf(
            __('Using Cached Config (Version: %s)', 'autoload-optimizer'),
            esc_html($config['version'] ?? 'N/A')
        );
        return $config;
    }

    private function fetch_remote_config() {
        $remote_url = apply_filters('ao_remote_config_url', self::DEFAULT_REMOTE_URL);
        $response = wp_remote_get($remote_url, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            $local_path = plugin_dir_path(__FILE__) . 'config.json';
            if (file_exists($local_path)) {
                $local_content = file_get_contents($local_path);
                $config = json_decode($local_content, true);
                if (json_last_error() === JSON_ERROR_NONE && $this->is_config_valid($config)) {
                    $this->config_status = __('Using Local Fallback Config', 'autoload-optimizer');
                    return $config;
                }
            }
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
    if (isset($_GET['ao_refresh_config']) && check_admin_referer('ao_refresh_config')) {
        delete_transient('ao_remote_config_cache');
        wp_safe_redirect(remove_query_arg(['ao_refresh_config', '_wpnonce']));
        exit;
    }
    $config = $config_manager->get_config();
    $status_message = $config_manager->get_config_status();

    $total_autoload_stats = $wpdb->get_row(
        "SELECT COUNT(option_name) as count, SUM(LENGTH(option_value)) as size
         FROM {$wpdb->options}
         WHERE autoload = 'yes'"
    );

    $large_options = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, LENGTH(option_value) AS option_length
         FROM {$wpdb->options}
         WHERE autoload = 'yes' AND LENGTH(option_value) >= %d
         ORDER BY option_length DESC", 1024
    ));

    $active_plugin_paths = get_option('active_plugins', []);
    $grouped_options = [];
    $large_options_size = 0;
    $inactive_plugin_option_count = 0;
    
    foreach($large_options as $option) { $large_options_size += $option->option_length; }

    foreach ($large_options as $option) {
        $is_safe = in_array($option->option_name, $config['safe_literals']);
        if (!$is_safe && !empty($config['safe_patterns'])) {
            foreach ($config['safe_patterns'] as $pattern) {
                if (fnmatch($pattern, $option->option_name)) { $is_safe = true; break; }
            }
        }
        
        // --- Option Identification Logic (Priority Order) ---
        $plugin_name = __('Unknown', 'autoload-optimizer');
        $status_info = ['code' => 'unknown', 'text' => __('Unknown', 'autoload-optimizer'), 'class' => ''];
        $mapping_found = false;
        
        $active_theme = wp_get_theme();
        $theme_slugs = [$active_theme->get_stylesheet()];
        if ($active_theme->parent()) {
            $theme_slugs[] = $active_theme->get_template();
        }

        // 1. Precise & Pattern Match from config.json (Highest Priority)
        $plugin_name = __('Unknown', 'autoload-optimizer');
        $status_info = ['code' => 'unknown', 'text' => __('Unknown', 'autoload-optimizer'), 'class' => ''];
        $mapping_found = false;
        
        $active_theme = wp_get_theme();
        $theme_slugs = [$active_theme->get_stylesheet()];
        if ($active_theme->parent()) {
            $theme_slugs[] = $active_theme->get_template();
        }

        // 1. Check config.json mappings first (Highest Priority)
        foreach ($config['plugin_mappings'] as $pattern => $mapping) {
            if (fnmatch($pattern, $option->option_name)) {
                $context_match = true;
                if (isset($mapping['context'])) {
                    if (isset($mapping['context']['theme']) && !in_array($mapping['context']['theme'], $theme_slugs, true)) {
                        $context_match = false;
                    }
                }

                if ($context_match) {
                    $plugin_name = $mapping['name'];
                    if ($mapping['file'] === 'core') $status_info = ['code' => 'core', 'text' => __('WordPress Core', 'autoload-optimizer'), 'class' => 'notice-info'];
                    elseif ($mapping['file'] === 'theme') $status_info = ['code' => 'theme', 'text' => __('Active Theme', 'autoload-optimizer'), 'class' => 'notice-info'];
                    elseif (in_array($mapping['file'], $active_plugin_paths)) $status_info = ['code' => 'plugin_active', 'text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success'];
                    else {
                        $status_info = ['code' => 'plugin_inactive', 'text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
                        $inactive_plugin_option_count++;
                    }
                    $mapping_found = true;
                    break; 
                }
            }
        }

        // 2. Fallbacks (ONLY run if no mapping was found in the config)
        if (!$mapping_found) {
            if (str_starts_with($option->option_name, '_transient_') || str_starts_with($option->option_name, '_site_transient_')) {
                $plugin_name = __('WordPress Core (Transient)', 'autoload-optimizer');
                $status_info = ['code' => 'core', 'text' => __('WordPress Core', 'autoload-optimizer'), 'class' => 'notice-info'];
            } 
            // NOTE: Add status checks for guessed plugins
            elseif (str_starts_with($option->option_name, 'elementor')) {
                 $plugin_name = 'Elementor';
                 $status_info = in_array('elementor/elementor.php', $active_plugin_paths) 
                    ? ['code' => 'plugin_active', 'text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success']
                    : ['code' => 'plugin_inactive', 'text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
            } elseif (str_starts_with($option->option_name, 'wpseo')) {
                 $plugin_name = 'Yoast SEO';
                 $status_info = in_array('wordpress-seo/wp-seo.php', $active_plugin_paths)
                    ? ['code' => 'plugin_active', 'text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success']
                    : ['code' => 'plugin_inactive', 'text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
            } elseif (str_starts_with($option->option_name, 'rocket')) {
                 $plugin_name = 'WP Rocket';
                 $status_info = in_array('wp-rocket/wp-rocket.php', $active_plugin_paths)
                    ? ['code' => 'plugin_active', 'text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success']
                    : ['code' => 'plugin_inactive', 'text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
            }
        }


        if (!isset($grouped_options[$plugin_name])) {
            $grouped_options[$plugin_name] = [
                'total_size' => 0, 
                'count' => 0, 
                'options' => [],
                'status' => $status_info
            ];
        }

        $grouped_options[$plugin_name]['total_size'] += $option->option_length;
        $grouped_options[$plugin_name]['count']++;
        $grouped_options[$plugin_name]['options'][] = ['name' => $option->option_name, 'length' => $option->option_length, 'is_safe' => $is_safe, 'status' => $status_info];
    }
    
    uasort($grouped_options, fn($a, $b) => $b['total_size'] <=> $a['total_size']);

    ?>
    <div class="wrap">
        <h1><?php _e('Autoloaded Options Optimizer', 'autoload-optimizer'); ?></h1>

        <div class="notice notice-warning notice-alt" style="margin-top: 1rem;">
            <?php if ($total_autoload_stats && $total_autoload_stats->count > 0) : ?>
                <p>
                    <?php printf(
                        __('<strong>Total autoloaded options:</strong> %d (%s).', 'autoload-optimizer'),
                        esc_html($total_autoload_stats->count),
                        esc_html(size_format($total_autoload_stats->size))
                    ); ?>
                </p>
                <?php if (!empty($large_options)) : ?>
                <p>
                    <?php printf(
                        __('This table shows the %d options that are larger than 1KB, which have a combined size of %s.', 'autoload-optimizer'),
                        count($large_options),
                        esc_html(size_format($large_options_size))
                    ); ?>
                </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="notice notice-success is-dismissible">
            <p><strong><?php _e('Safe to Disable:', 'autoload-optimizer'); ?></strong> <?php _e('Options marked with a green checkmark are generally safe to disable. These are typically cache data, logs, or other non-critical data.', 'autoload-optimizer'); ?></p>
            <p><button id="ao-disable-safe-options" class="button"><?php _e('Disable Autoload for All Safe Options', 'autoload-optimizer'); ?></button>
            <span class="spinner" style="float: none; vertical-align: middle; margin-left: 5px;"></span></p>
        </div>

        <?php if ($inactive_plugin_option_count > 0) : ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php 
                    printf(
                        _n(
                            '<strong>Found %d option from an inactive plugin.</strong> Disabling autoload for these options can improve performance.',
                            '<strong>Found %d options from inactive plugins.</strong> Disabling autoload for these options can improve performance.',
                            $inactive_plugin_option_count,
                            'autoload-optimizer'
                        ),
                        $inactive_plugin_option_count
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="notice notice-error"><p><strong><?php _e('Warning:', 'autoload-optimizer'); ?></strong> <?php _e('Always have a backup before making changes. Only disable autoload for options that belong to inactive plugins or are marked as safe.', 'autoload-optimizer'); ?></p></div>

        <div class="notice notice-info notice-alt" style="margin-top: 1rem;">
            <p><strong><?php _e('Configuration Status:', 'autoload-optimizer'); ?></strong> <?php echo esc_html($status_message); ?></p>
            <p><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('ao_refresh_config', '1'), 'ao_refresh_config')); ?>" class="button"><?php _e('Force Refresh Configuration', 'autoload-optimizer'); ?></a></p>
        </div>
        
        <div id="ao-dashboard-widgets-wrap" style="display: flex; gap: 20px; margin-top: 1rem;">
            <div class="card" style="flex: 1;">
                <!-- Bulk actions card -->
                <h2 class="title"><?php _e('Bulk Actions', 'autoload-optimizer'); ?></h2>
                <p><?php _e('Select options from the table and disable their autoload status in one go.', 'autoload-optimizer'); ?></p>
                <button id="ao-disable-selected" class="button button-primary"><?php _e('Disable Autoload for Selected', 'autoload-optimizer'); ?></button>
                <span class="spinner" style="float: none; vertical-align: middle; margin-left: 5px;"></span>
            </div>
            <div class="card" style="flex: 1.5;">
                <h2 class="title"><?php _e('Recommendations', 'autoload-optimizer'); ?></h2>
                <p>
                    <?php
                    $recommendation_found = false;
                    foreach ($grouped_options as $plugin_name => $data) {
                        // Check if a recommendation exists for this plugin in the config
                        if (isset($config['recommendations'][$plugin_name])) {
                            
                            // *** THIS IS THE NEW LOGIC BLOCK ***
                            // First, check if the group contains any options marked as safe
                            $has_safe_options = false;
                            foreach ($data['options'] as $option) {
                                if ($option['is_safe']) {
                                    $has_safe_options = true;
                                    break; // Optimization: stop checking once one is found
                                }
                            }

                            // Only show the recommendation if the plugin is inactive OR it has safe options
                            if ($data['status']['code'] === 'plugin_inactive' || $has_safe_options) {
                                $recommendation_found = true;
                                $rec_text = $config['recommendations'][$plugin_name];
                                $status_class = $data['status']['class'] ?? 'notice-info';
                                
                                $styled_rec_text = preg_replace_callback(
                                    '/<strong>(.*?:)<\/strong>/',
                                    function($matches) use ($status_class) {
                                        return sprintf(
                                            '<span class="notice %s" style="padding: 2px 8px; display: inline-block; margin: 0; font-weight: bold;">%s</span>',
                                            esc_attr($status_class),
                                            esc_html($matches[1])
                                        );
                                    },
                                    $rec_text,
                                    1
                                );

                                echo '<span>' . wp_kses_post($styled_rec_text) . '</span><br><br>';
                            }
                        }
                    }
                    if (!$recommendation_found) {
                        echo '<em>' . __('No specific recommendations for the large options found on your site.', 'autoload-optimizer') . '</em>';
                    }
                    ?>
                </p>
            </div>
        </div>

        <!-- NEW: Manual Option Lookup Form -->
        <div class="card" style="margin-top: 1rem;">
            <h2 class="title"><?php _e('Manual Option Lookup', 'autoload-optimizer'); ?></h2>
            <p><?php _e('Enter any option name from the wp_options table to view its content.', 'autoload-optimizer'); ?></p>
            <form id="ao-manual-lookup-form" style="display: flex; gap: 10px; align-items: center;">
                <input type="text" id="ao-manual-option-name" name="option_name" placeholder="<?php _e('e.g., active_plugins', 'autoload-optimizer'); ?>" style="width: 300px;" required>
                <button type="submit" class="button button-secondary"><?php _e('View Option', 'autoload-optimizer'); ?></button>
                <span class="spinner" style="float: none; vertical-align: middle;"></span>
            </form>
        </div>

        <div class="notice notice-info notice-alt" style="margin-top: 1rem;">


        <?php if (empty($large_options)) : ?>
            <p><?php _e('No autoloaded options larger than 1KB found.', 'autoload-optimizer'); ?></p>
        <?php else : ?>
            <div id="ao-results-container" style="display:none; margin-top: 1rem;"></div>

            <h2><?php _e('Large Autoloaded Options (>1KB)', 'autoload-optimizer'); ?></h2>
            <table class="wp-list-table widefat fixed">
                <thead>
                    <tr>
                        <th id="cb" class="manage-column column-cb check-column"><input type="checkbox" /></th>
                        <th class="manage-column column-primary column-option-name"><?php _e('Option Name', 'autoload-optimizer'); ?></th>
                        <th class="manage-column column-size"><?php _e('Size', 'autoload-optimizer'); ?></th>
                        <th class="manage-column column-percentage"><?php _e('% of Total', 'autoload-optimizer'); ?></th>
                        <th class="manage-column column-plugin"><?php _e('Plugin', 'autoload-optimizer'); ?></th>
                        <th class="manage-column column-status"><?php _e('Status', 'autoload-optimizer'); ?></th>
                        <th class="manage-column column-action"><?php _e('Action', 'autoload-optimizer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $group_index = 0; ?>
                    <?php foreach ($grouped_options as $plugin_name => $data) : ?>
                        <?php $group_class = ($group_index % 2 == 0) ? 'group-color-a' : 'group-color-b'; ?>
                        <tr class="plugin-header">
                            <th class="check-column"></th>
                            <td colspan="6">
                                <strong><?php echo esc_html($plugin_name); ?></strong> - 
                                <?php printf(
                                    __('%s total, %d options, %s of total large options', 'autoload-optimizer'),
                                    size_format($data['total_size']),
                                    $data['count'],
                                    $large_options_size > 0 ? number_format(($data['total_size'] / $large_options_size) * 100, 2) . '%' : '0%'
                                ); ?>
                            </td>
                        </tr>
                        <?php foreach ($data['options'] as $option) : ?>
                            <?php $row_attributes = $option['is_safe'] ? 'data-is-safe="true"' : ''; ?>
                            <tr class="<?php echo $group_class; ?>" <?php echo $row_attributes; ?>>
                                <th class="check-column">
                                    <?php if ($option['status']['code'] === 'plugin_inactive' || $option['is_safe']) : ?>
                                        <input type="checkbox" class="ao-option-checkbox" value="<?php echo esc_attr($option['name']); ?>">
                                    <?php endif; ?>
                                </th>
                                <td class="column-primary column-option-name">
                                    <strong><a href="#" class="view-option-content" data-option-name="<?php echo esc_attr($option['name']); ?>"><?php echo esc_html($option['name']); ?></a></strong>
                                    <?php if ($option['is_safe']) : ?><span class="dashicons dashicons-yes-alt" style="color:#46b450;" title="<?php _e('Safe to disable autoload', 'autoload-optimizer'); ?>"></span><?php endif; ?>
                                </td>
                                <td class="column-size"><?php echo size_format($option['length']); ?></td>
                                <td class="column-percentage"><?php echo $large_options_size > 0 ? number_format(($option['length'] / $large_options_size) * 100, 2) . '%' : 'N/A'; ?></td>
                                <td class="column-plugin"><?php echo esc_html($plugin_name); ?></td>
                                <td class="column-status"><span class="notice <?php echo esc_attr($option['status']['class']); ?>" style="padding: 2px 8px; display: inline-block; margin: 0;"><?php echo esc_html($option['status']['text']); ?></span></td>
                                <td class="column-action">
                                    <?php if ($option['status']['code'] === 'plugin_inactive' || $option['is_safe']) : ?>
                                        <button class="button disable-single" data-option="<?php echo esc_attr($option['name']); ?>"><?php _e('Disable', 'autoload-optimizer'); ?></button>
                                    <?php else : ?>
                                        <span title="<?php _e('Disabling autoload for core or active plugins is not recommended.', 'autoload-optimizer'); ?>"><?php _e('N/A', 'autoload-optimizer'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php $group_index++; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

        <!-- // <-- START: NEW READ-ONLY HISTORY TABLE SECTION -->
        <?php
        // Fetch history from both your plugin and Performance Lab
        $ao_history   = get_option('ao_optimizer_history', []);
        $perf_history = get_option('perflab_aao_disabled_options', []);

        // Combine and get unique option names
        $combined_history = array_unique(array_merge(is_array($ao_history) ? $ao_history : [], is_array($perf_history) ? $perf_history : []));
        sort($combined_history); // Sort alphabetically for consistency

        if (!empty($combined_history)) :
        ?>
            <div class="card" style="margin-top: 2rem;max-width:unset">
                <h2 class="title"><?php _e('History: Options with Autoload Disabled', 'autoload-optimizer'); ?></h2>
                <p><?php _e('This is a combined list of options where autoload has been disabled by this plugin or by the Performance Lab plugin.', 'autoload-optimizer'); ?></p>
                
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th class="manage-column"><?php _e('Option Name', 'autoload-optimizer'); ?></th>
                            <th class="manage-column" style="width: 25%;"><?php _e('Source of Change', 'autoload-optimizer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($combined_history as $option_name) : 
                            if (!is_string($option_name)) continue;
                            
                            $sources = [];
                            if (in_array($option_name, $ao_history, true)) {
                                $sources[] = __('This Plugin', 'autoload-optimizer');
                            }
                            if (in_array($option_name, $perf_history, true)) {
                                $sources[] = __('Performance Lab', 'autoload-optimizer');
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($option_name); ?></strong></td>
                            <td><?php echo esc_html(implode(' & ', $sources)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <!-- // <-- END: NEW READ-ONLY HISTORY TABLE SECTION -->

        <div id="ao-option-modal-overlay"><div id="ao-option-modal-content"><span class="close-modal">&times;</span><h2 id="ao-option-modal-title"></h2><div id="ao-modal-body"></div></div></div>
    </div>
    <style>
        .wp-list-table .column-option-name { width: 35%; }
        .wp-list-table .column-size, .wp-list-table .column-percentage { width: 8%; }
        .wp-list-table .column-plugin { width: 15%; }
        .wp-list-table .column-status { width: 12%; }
        .wp-list-table .column-action { width: 10%; }
        .wp-list-table tbody tr.group-color-a { background-color: #ffffff; }
        .wp-list-table tbody tr.group-color-b { background-color: #f6f7f7; }
        .wp-list-table tbody tr:hover { background-color: #f0f0f1 !important; }
        .plugin-header th, .plugin-header td { font-weight: bold; background-color: #f0f0f1; border-bottom: 1px solid #ddd; }
        .view-option-content { cursor: pointer; } #ao-option-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); z-index: 10001; justify-content: center; align-items: center; } #ao-option-modal-content { background: #fff; padding: 20px; border-radius: 4px; width: 80%; max-width: 900px; max-height: 80vh; overflow-y: auto; position: relative; } .close-modal { position: absolute; top: 5px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; color: #555; } #ao-modal-body pre { background: #f1f1f1; padding: 15px; border: 1px solid #ddd; white-space: pre-wrap; word-wrap: break-word; }
    </style>
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
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);

    $options_to_disable = isset($_POST['option_names']) ? (array) $_POST['option_names'] : [];
    if (empty($options_to_disable)) wp_send_json_error(['message' => __('No options were selected.', 'autoload-optimizer')]);

    // --- START: UPDATED HISTORY LOGIC ---
    // Get both history lists
    $ao_history = get_option('ao_optimizer_history', []);
    $perf_history = get_option('perflab_aao_disabled_options', []);

    // Ensure they are arrays
    if (!is_array($ao_history))   $ao_history = [];
    if (!is_array($perf_history)) $perf_history = [];
    // --- END: UPDATED HISTORY LOGIC ---

    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/autoload-options-debug.log';
    $log_content = "\n=== Autoload Disable Action - " . date('Y-m-d H:i:s') . " ===\n";
    $log_content .= "User: " . wp_get_current_user()->user_login . "\n";
    
    global $wpdb;
    $success_count = $failure_count = $already_done = 0;

    foreach ($options_to_disable as $option_name) {
        $sane_option_name = sanitize_text_field($option_name);
        
        // Use wp_set_option_autoload for consistency with Core/Perf Lab
        $result = wp_set_option_autoload($sane_option_name, false);
        
        if ($result) {
            $success_count++;
            $log_content .= "[SUCCESS] '{$sane_option_name}' - Set autoload to 'no'.\n";

            // Add the successfully disabled option to both history arrays if not already present
            if (!in_array($sane_option_name, $ao_history, true)) {
                $ao_history[] = $sane_option_name;
            }
            if (!in_array($sane_option_name, $perf_history, true)) {
                $perf_history[] = $sane_option_name;
            }
        } else {
            // Check if it failed because it was already 'no'
            $current_autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $sane_option_name));
            if ('no' === $current_autoload) {
                $already_done++;
                $log_content .= "[SKIPPED] '{$sane_option_name}' - Already 'no'.\n";

                // Ensure it's in the history lists even if it was already done
                if (!in_array($sane_option_name, $ao_history, true)) {
                    $ao_history[] = $sane_option_name;
                }
                if (!in_array($sane_option_name, $perf_history, true)) {
                    $perf_history[] = $sane_option_name;
                }
            } else {
                $failure_count++;
                $log_content .= "[FAILED]  '{$sane_option_name}' - DB error.\n";
            }
        }
    }
    
    // Save both updated history lists back to the database
    update_option('ao_optimizer_history', $ao_history, 'no');
    update_option('perflab_aao_disabled_options', $perf_history, 'no');

    file_put_contents($log_file, $log_content, FILE_APPEND);
    $message = sprintf(__('Processed %d options: %d disabled, %d failed, %d already off.', 'autoload-optimizer'), count($options_to_disable), $success_count, $failure_count, $already_done);
    $message .= ' ' . sprintf(__('Log saved to %s.', 'autoload-optimizer'), '<code>/wp-content/uploads/autoload-options-debug.log</code>');
    wp_send_json_success(['message' => $message]);
}

add_action('admin_footer', 'ao_admin_page_scripts');
function ao_admin_page_scripts() {
    $screen = get_current_screen();
    if (!$screen || 'tools_page_autoloaded-options' !== $screen->id) return;
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        // --- Element Selectors ---
        const modalOverlay = document.getElementById('ao-option-modal-overlay');
        const modalContent = document.getElementById('ao-option-modal-content');
        const modalTitle = document.getElementById('ao-option-modal-title');
        const modalBody = document.getElementById('ao-modal-body');
        const resultsContainer = document.getElementById('ao-results-container');
        const ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        const mainCheckbox = document.querySelector('.wp-list-table #cb input[type="checkbox"]');
        const itemCheckboxes = document.querySelectorAll('.wp-list-table .ao-option-checkbox');

        // --- Core Functions ---
        
        function showResult(message, type = 'success') { 
            resultsContainer.innerHTML = `<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`; 
            resultsContainer.style.display = 'block'; 
        }

        function disableOptions(optionNames, button) {
            if (!confirm(`<?php _e('Are you sure you want to disable autoload for the selected option(s)?', 'autoload-optimizer'); ?>`)) return;

            const spinner = button ? button.nextElementSibling : null;
            
            if (button) button.disabled = true;
            if (spinner && spinner.classList.contains('spinner')) spinner.classList.add('is-active');

            const formData = new FormData();
            formData.append('action', 'ao_disable_autoload_options');
            formData.append('nonce', '<?php echo wp_create_nonce('ao_disable_autoload_nonce'); ?>');
            optionNames.forEach(name => formData.append('option_names[]', name));

            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    showResult(data.data.message, data.success ? 'success' : 'error');
                    if (data.success) setTimeout(() => location.reload(), 3000);
                })
                .catch(() => showResult('<?php _e('Request failed. Please check the browser console for errors.', 'autoload-optimizer'); ?>', 'error'))
                .finally(() => {
                    if (button) button.disabled = false;
                    if (spinner && spinner.classList.contains('spinner')) spinner.classList.remove('is-active');
                });
        }

        function showModal(title, content) {
            modalTitle.textContent = title;
            modalBody.innerHTML = content;
            modalOverlay.style.display = 'flex';
        }

        function hideModal() {
            modalOverlay.style.display = 'none';
        }

        function viewOptionContent(optionName, spinner) {
            showModal('<?php _e('Viewing:', 'autoload-optimizer'); ?> ' + optionName, '<?php _e('Loading...', 'autoload-optimizer'); ?>');
            if (spinner) spinner.classList.add('is-active');

            const formData = new FormData();
            formData.append('action', 'ao_get_option_value');
            formData.append('nonce', '<?php echo wp_create_nonce('ao_view_option_nonce'); ?>');
            formData.append('option_name', optionName);
            
            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(d => {
                    modalBody.innerHTML = d.success ? d.data.value : `<p style="color:red;">${d.data.message}</p>`;
                })
                .catch(() => {
                    modalBody.innerHTML = `<p style="color:red;"><?php _e('An error occurred during the request.', 'autoload-optimizer'); ?></p>`;
                })
                .finally(() => {
                    if (spinner) spinner.classList.remove('is-active');
                });
        }

        // --- Event Listeners ---
        
        // Listener for clicks within the main table (View, Disable)
        const tableBody = document.querySelector('.wp-list-table tbody');
        if (tableBody) {
            tableBody.addEventListener('click', function(e) {
                if (e.target.classList.contains('view-option-content')) {
                    e.preventDefault();
                    viewOptionContent(e.target.dataset.optionName, null);
                }
                if (e.target.classList.contains('disable-single')) {
                    e.preventDefault();
                    disableOptions([e.target.dataset.option], e.target);
                }
            });
        }
        
        // Listener for the new Manual Lookup Form
        const manualLookupForm = document.getElementById('ao-manual-lookup-form');
        if (manualLookupForm) {
            manualLookupForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const optionNameInput = document.getElementById('ao-manual-option-name');
                const spinner = this.querySelector('.spinner');
                const optionName = optionNameInput.value.trim();

                if (optionName) {
                    viewOptionContent(optionName, spinner);
                }
            });
        }
        
        // Listener for the Bulk Disable button
        const disableSelectedBtn = document.getElementById('ao-disable-selected');
        if (disableSelectedBtn) {
            disableSelectedBtn.addEventListener('click', e => {
                e.preventDefault();
                const selected = Array.from(document.querySelectorAll('.ao-option-checkbox:checked')).map(cb => cb.value);
                if (selected.length === 0) {
                    alert('<?php _e('Please select at least one option from the table.', 'autoload-optimizer'); ?>');
                    return;
                }
                disableOptions(selected, e.target);
            });
        }

        // Listener for the "Disable All Safe" button
        const disableSafeBtn = document.getElementById('ao-disable-safe-options');
        if (disableSafeBtn) {
            disableSafeBtn.addEventListener('click', e => {
                e.preventDefault();
                const safeOptions = Array.from(document.querySelectorAll('tr[data-is-safe="true"] .ao-option-checkbox')).map(cb => cb.value);
                if (safeOptions.length === 0) {
                    alert('<?php _e('No safe options were found in the table to disable.', 'autoload-optimizer'); ?>');
                    return;
                }
                disableOptions(safeOptions, e.target);
            });
        }
        
        // Listener for the main "select all" checkbox
        if(mainCheckbox) {
            mainCheckbox.addEventListener('change', () => {
                itemCheckboxes.forEach(cb => { 
                    if(!cb.disabled) {
                        cb.checked = mainCheckbox.checked;
                    }
                });
            });
        }

        // Listeners for closing the modal
        modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) hideModal(); });
        modalContent.querySelector('.close-modal').addEventListener('click', hideModal);
    });
    </script>
    <?php
}

// --- Add "Settings" Link to Plugins Page ---

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ao_add_settings_link');

function ao_add_settings_link($links) {
    // Build the URL for your plugin's admin page.
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('tools.php?page=autoloaded-options')),
        __('Settings', 'autoload-optimizer')
    );

    // Add the "Settings" link to the beginning of the links array.
    array_unshift($links, $settings_link);

    return $links;
}

// --- GitHub Plugin Updater ---

/**
 * --------------------------------------------------------------------------
 *  Initialize GitHub Updater (Safely)
 * --------------------------------------------------------------------------
 *
 *  This code safely checks for and initializes the Plugin Update Checker
 *  library. If the library is not found (e.g., when the code is run from
 *  a snippet), it will not cause a fatal error.
 */
add_action('plugins_loaded', 'ao_initialize_updater');

function ao_initialize_updater() {
    // Define the path to the updater's bootstrap file.
    $updater_bootstrap_file = dirname(__FILE__) . '/lib/plugin-update-checker/plugin-update-checker.php';

    // Check if the file exists before trying to load it.
    if (file_exists($updater_bootstrap_file)) {
        require_once $updater_bootstrap_file;

        // The file exists, so we can safely use the library.
        try {
            $myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/milllan/mill_autoload_options_checker_plugin/', // GitHub repo URL
                __FILE__, // Main plugin file (__FILE__ points to this plugin's main file)
                'autoload-optimizer' // Plugin slug (use your text-domain for consistency)
            );
        } catch (Exception $e) {
            // Optional: Log the error if the library fails to initialize for some reason.
            // error_log('Plugin Update Checker failed to initialize: ' . $e->getMessage());
        }
    }
}