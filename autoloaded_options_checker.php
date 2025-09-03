<?php
/**
 * Plugin Name:       Autoloaded Options Optimizer
 * Plugin URI:        https://github.com/milllan/mill_autoload_options_checker_plugin
 * Description:       A tool to analyze, view, and manage autoloaded options in the wp_options table, with a remotely managed configuration.
 * Version:           3.9.0
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
    // --- NEW: Register the Find Source AJAX handler ---
    add_action('wp_ajax_ao_find_option_in_files', 'ao_ajax_find_option_in_files');
}

add_action('admin_head-tools_page_autoloaded-options', 'ao_admin_page_styles');

/**
 * Manages the remote configuration.
 */
final class AO_Remote_Config_Manager {
    private static $instance;
    private const DEFAULT_REMOTE_URL = 'https://raw.githubusercontent.com/milllan/mill_autoload_options_checker_plugin/main/config.json';
    private const CACHE_KEY = 'ao_remote_config_cache';
    private const CACHE_DURATION = 7 * DAY_IN_SECONDS;
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
                $this->config_status = __('Error: Could not fetch remote config. Using local fallback.', 'autoload-optimizer');
                return $this->get_local_fallback();
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
    
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $body = wp_remote_retrieve_body($response);
            $config = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && $this->is_config_valid($config)) {
                return $config;
            }
        }
        return false;
    }

    private function get_local_fallback() {
        $local_path = plugin_dir_path(__FILE__) . 'config.json';
        if (file_exists($local_path)) {
            $local_content = file_get_contents($local_path);
            $config = json_decode($local_content, true);
            if (json_last_error() === JSON_ERROR_NONE && $this->is_config_valid($config)) {
                return $config;
            }
        }
        return $this->get_empty_config_structure();
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
        $this->config_status = __('Error: Could not fetch config and no valid local fallback was found.', 'autoload-optimizer');
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

/**
 * Gathers and processes all data for the analysis page.
 * @return array Processed data for display.
 */
function ao_get_analysis_data() {
    global $wpdb;
    $config = ao_get_config();

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

    foreach ($large_options as $option) {
        $large_options_size += $option->option_length;
    }

    foreach ($large_options as $option) {
        $is_safe = in_array($option->option_name, $config['safe_literals']);
        if (!$is_safe && !empty($config['safe_patterns'])) {
            foreach ($config['safe_patterns'] as $pattern) {
                if (fnmatch($pattern, $option->option_name)) { $is_safe = true; break; }
            }
        }

        $plugin_name = __('Unknown', 'autoload-optimizer');
        $status_info = ['code' => 'unknown', 'text' => __('Unknown', 'autoload-optimizer'), 'class' => ''];
        $mapping_found = false;
        
        $active_theme = wp_get_theme();
        $theme_slugs = [$active_theme->get_stylesheet()];
        if ($active_theme->parent()) {
            $theme_slugs[] = $active_theme->get_template();
        }
        $theme_slugs = array_map('strtolower', $theme_slugs);

        foreach ($config['plugin_mappings'] as $pattern => $mapping) {
            if (fnmatch($pattern, $option->option_name)) {
                $context_match = !isset($mapping['context']['theme']) || in_array($mapping['context']['theme'], $theme_slugs, true);

                if ($context_match) {
                    $plugin_name = $mapping['name'];
                    $file_info = $mapping['file'];

                    if (strpos($file_info, 'theme:') === 0) {
                        $theme_slug = substr($file_info, 6);
                        if (in_array($theme_slug, $theme_slugs, true)) {
                            $status_info = ['code' => 'theme_active', 'text' => __('Active Theme', 'autoload-optimizer'), 'class' => 'notice-info'];
                        } else {
                            $status_info = ['code' => 'theme_inactive', 'text' => __('Inactive Theme', 'autoload-optimizer'), 'class' => 'notice-error'];
                            $inactive_plugin_option_count++;
                        }
                    } elseif ($file_info === 'core') {
                        $status_info = ['code' => 'core', 'text' => __('WordPress Core', 'autoload-optimizer'), 'class' => 'notice-info'];
                    } elseif ($file_info === 'theme') {
                        $status_info = ['code' => 'theme_active', 'text' => __('Active Theme', 'autoload-optimizer'), 'class' => 'notice-info'];
                    } elseif (in_array($file_info, $active_plugin_paths)) {
                        $status_info = ['code' => 'plugin_active', 'text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success'];
                    } else {
                        $status_info = ['code' => 'plugin_inactive', 'text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
                        $inactive_plugin_option_count++;
                    }
                    $mapping_found = true;
                    break; 
                }
            }
        }

        if (!$mapping_found) {
            if (strpos($option->option_name, '_transient_') === 0 || strpos($option->option_name, '_site_transient_') === 0) {
                $plugin_name = __('WordPress Core (Transient)', 'autoload-optimizer');
                $status_info = ['code' => 'core', 'text' => __('WordPress Core', 'autoload-optimizer'), 'class' => 'notice-info'];
            } else {
                $known_plugin_prefixes = [
                    'elementor' => ['name' => 'Elementor', 'file' => 'elementor/elementor.php'],
                    'wpseo'     => ['name' => 'Yoast SEO', 'file' => 'wordpress-seo/wp-seo.php'],
                    'rocket'    => ['name' => 'WP Rocket', 'file' => 'wp-rocket/wp-rocket.php'],
                ];
    
                foreach ($known_plugin_prefixes as $prefix => $data) {
                    if (strpos($option->option_name, $prefix) === 0) {
                        $plugin_name = $data['name'];
                        $is_active = in_array($data['file'], $active_plugin_paths);
                        $status_info = $is_active
                            ? ['code' => 'plugin_active', 'text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success']
                            : ['code' => 'plugin_inactive', 'text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
                        if (!$is_active) $inactive_plugin_option_count++;
                        break;
                    }
                }
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
    
    uasort($grouped_options, function($a, $b) { return $b['total_size'] <=> $a['total_size']; });

    return [
        'total_autoload_stats' => $total_autoload_stats,
        'large_options' => $large_options,
        'grouped_options' => $grouped_options,
        'large_options_size' => $large_options_size,
        'inactive_plugin_option_count' => $inactive_plugin_option_count,
        'config' => $config,
    ];
}


// --- Display & UI Logic ---

function ao_should_show_recommendation($group_data, $config, $plugin_name) {
    if (!isset($config['recommendations'][$plugin_name])) {
        return false;
    }
    $has_safe_options = false;
    foreach ($group_data['options'] as $option) {
        if ($option['is_safe']) {
            $has_safe_options = true;
            break;
        }
    }
    return strpos($group_data['status']['code'], '_inactive') !== false || $has_safe_options;
}

function ao_display_admin_page() {
    if (!current_user_can('manage_options')) return;

    $config_manager = AO_Remote_Config_Manager::get_instance();
    if (isset($_GET['ao_refresh_config']) && check_admin_referer('ao_refresh_config')) {
        delete_transient('ao_remote_config_cache');
        wp_safe_redirect(remove_query_arg(['ao_refresh_config', '_wpnonce']));
        exit;
    }
    
    // 1. Run the analysis first, which triggers the config load.
    $data = ao_get_analysis_data(); 
    // 2. NOW get the status, which has been updated by the line above.
    $status_message = $config_manager->get_config_status();

    extract($data); // Extracts variables like $grouped_options, $large_options_size, etc.
    
    ?>
    <div class="wrap" id="ao-plugin-wrapper" 
        data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
        data-disable-nonce="<?php echo esc_attr(wp_create_nonce('ao_disable_autoload_nonce')); ?>"
        data-view-nonce="<?php echo esc_attr(wp_create_nonce('ao_view_option_nonce')); ?>"
        data-find-nonce="<?php echo esc_attr(wp_create_nonce('ao_find_source_nonce')); ?>">
        <h1><?php _e('Autoloaded Options Optimizer', 'autoload-optimizer'); ?></h1>

        <!-- ... (Top summary boxes) ... -->
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
                            '<strong>Found %d option from an inactive plugin or theme.</strong> Disabling autoload for these options can improve performance.',
                            '<strong>Found %d options from inactive plugins or themes.</strong> Disabling autoload for these options can improve performance.',
                            $inactive_plugin_option_count,
                            'autoload-optimizer'
                        ),
                        $inactive_plugin_option_count
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>
        <div class="notice notice-error"><p><strong><?php _e('Warning:', 'autoload-optimizer'); ?></strong> <?php _e('Always have a backup before making changes. Only disable autoload for options that belong to inactive plugins/themes or are marked as safe.', 'autoload-optimizer'); ?></p></div>
        <div class="notice notice-info notice-alt" style="margin-top: 1rem;">
            <p><strong><?php _e('Configuration Status:', 'autoload-optimizer'); ?></strong> <?php echo esc_html($status_message); ?></p>
            <p><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('ao_refresh_config', '1'), 'ao_refresh_config')); ?>" class="button"><?php _e('Force Refresh Configuration', 'autoload-optimizer'); ?></a></p>
        </div>
        <div id="ao-dashboard-widgets-wrap" style="display: flex; gap: 20px; margin-top: 1rem;">
            <div class="card" style="flex: 1;">
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
                    foreach ($grouped_options as $plugin_name => $rec_data) {
                        if (ao_should_show_recommendation($rec_data, $config, $plugin_name)) {
                            $recommendation_found = true;
                            $rec_text = $config['recommendations'][$plugin_name];
                            $status_class = $rec_data['status']['class'] ?? 'notice-info';
                            $styled_rec_text = preg_replace_callback('/<strong>(.*?:)<\/strong>/', function($matches) use ($status_class) {
                                    return sprintf('<span class="notice %s" style="padding: 2px 8px; display: inline-block; margin: 0; font-weight: bold;">%s</span>', esc_attr($status_class), $matches[1]);
                                }, $rec_text, 1);
                            echo '<span>' . wp_kses_post($styled_rec_text) . '</span><br><br>';
                        }
                    }
                    if (!$recommendation_found) { echo '<em>' . __('No specific recommendations for the large options found on your site.', 'autoload-optimizer') . '</em>'; }
                    ?>
                </p>
            </div>
        </div>


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
                        <th class="manage-column column-plugin"><?php _e('Plugin / Theme', 'autoload-optimizer'); ?></th>
                        <th class="manage-column column-status"><?php _e('Status', 'autoload-optimizer'); ?></th>
                        <th class="manage-column column-action"><?php _e('Action', 'autoload-optimizer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $group_index = 0; ?>
                    <?php foreach ($grouped_options as $plugin_name => $group_data) : ?>
                        <?php $group_class = ($group_index % 2 == 0) ? 'group-color-a' : 'group-color-b'; ?>
                        <tr class="plugin-header">
                            <th class="check-column"></th>
                            <td colspan="6">
                                <strong><?php echo esc_html($plugin_name); ?></strong> - 
                                <?php printf(
                                    __('%s total, %d options, %s of total large options', 'autoload-optimizer'),
                                    size_format($group_data['total_size']),
                                    $group_data['count'],
                                    $large_options_size > 0 ? number_format(($group_data['total_size'] / $large_options_size) * 100, 2) . '%' : '0%'
                                ); ?>
                            </td>
                        </tr>
                        <?php foreach ($group_data['options'] as $option) : 
                            $is_actionable = $option['is_safe'] || (strpos($option['status']['code'], '_inactive') !== false);
                            $is_unknown = $plugin_name === __('Unknown', 'autoload-optimizer');
                        ?>
                            <tr class="<?php echo $group_class; ?>" <?php echo $option['is_safe'] ? 'data-is-safe="true"' : ''; ?> data-option-name="<?php echo esc_attr($option['name']); ?>">
                                <th class="check-column">
                                    <?php if ($is_actionable) : ?>
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
                                    <?php if ($is_actionable) : ?>
                                        <button class="button disable-single" data-option="<?php echo esc_attr($option['name']); ?>"><?php _e('Disable Autoload', 'autoload-optimizer'); ?></button>
                                    <?php elseif ($is_unknown) : // --- NEW: Add Find Source button for Unknown options --- ?>
                                        <button class="button find-in-files" data-option="<?php echo esc_attr($option['name']); ?>"><?php _e('Find Source', 'autoload-optimizer'); ?></button>
                                        <span class="spinner" style="float: none; vertical-align: middle;"></span>
                                    <?php else : ?>
                                        <span title="<?php _e('Disabling autoload for core or active plugins/themes is not recommended.', 'autoload-optimizer'); ?>"><?php _e('N/A', 'autoload-optimizer'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php $group_index++; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="card" style="margin-top: 1rem;">
            <h2 class="title"><?php _e('Manual Option Lookup', 'autoload-optimizer'); ?></h2>
            <p><?php _e('Enter any option name from the wp_options table to view its content.', 'autoload-optimizer'); ?></p>
            <form id="ao-manual-lookup-form" style="display: flex; gap: 10px; align-items: center;">
                <input type="text" id="ao-manual-option-name" name="option_name" placeholder="<?php _e('e.g., active_plugins', 'autoload-optimizer'); ?>" style="width: 300px;" required>
                <button type="submit" class="button button-secondary"><?php _e('View Option', 'autoload-optimizer'); ?></button>
                <span class="spinner" style="float: none; vertical-align: middle;"></span>
            </form>
        </div>
        <?php
        $ao_history   = get_option('ao_optimizer_history', []);
        $perf_history = get_option('perflab_aao_disabled_options', []);
        $combined_history = array_unique(array_merge(is_array($ao_history) ? $ao_history : [], is_array($perf_history) ? $perf_history : []));
        sort($combined_history);

        if (!empty($combined_history)) :
        ?>
            <div class="card" style="margin-top: 2rem;max-width:unset">
                <h2 class="title"><?php _e('History: Options with Autoload Disabled', 'autoload-optimizer'); ?></h2>
                <p><?php _e('This is a combined list of options where autoload has been disabled by this plugin or by the Performance Lab plugin.', 'autoload-optimizer'); ?></p>
                <table class="wp-list-table widefat striped">
                    <thead><tr><th class="manage-column"><?php _e('Option Name', 'autoload-optimizer'); ?></th><th class="manage-column" style="width: 25%;"><?php _e('Source of Change', 'autoload-optimizer'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($combined_history as $option_name) : if (!is_string($option_name)) continue; $sources = []; if (in_array($option_name, $ao_history, true)) $sources[] = __('This Plugin', 'autoload-optimizer'); if (in_array($option_name, $perf_history, true)) $sources[] = __('Performance Lab', 'autoload-optimizer'); ?>
                        <tr><td><strong><?php echo esc_html($option_name); ?></strong></td><td><?php echo esc_html(implode(' & ', $sources)); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div id="ao-option-modal-overlay"><div id="ao-option-modal-content"><span class="close-modal">&times;</span><h2 id="ao-option-modal-title"></h2><div id="ao-modal-body"></div></div></div>
    </div>
    <?php
}

function ao_admin_page_styles() {
    ?>
    <style>
        .wp-list-table .column-option-name { width: 35%; } .wp-list-table .column-size, .wp-list-table .column-percentage { width: 8%; } .wp-list-table .column-plugin { width: 15%; } .wp-list-table .column-status { width: 12%; } .wp-list-table .column-action { width: 10%; } .wp-list-table tbody tr.group-color-a { background-color: #ffffff; } .wp-list-table tbody tr.group-color-b { background-color: #f6f7f7; } .wp-list-table tbody tr:hover { background-color: #f0f0f1 !important; } .wp-list-table tbody tr.ao-row-processed { opacity: 0.6; pointer-events: none; } .plugin-header th, .plugin-header td { font-weight: bold; background-color: #f0f0f1; border-bottom: 1px solid #ddd; } .view-option-content { cursor: pointer; } #ao-option-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); z-index: 10001; justify-content: center; align-items: center; } #ao-option-modal-content { background: #fff; padding: 20px; border-radius: 4px; width: 80%; max-width: 900px; max-height: 80vh; overflow-y: auto; position: relative; } .close-modal { position: absolute; top: 5px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; color: #555; } #ao-modal-body pre { background: #f1f1f1; padding: 15px; border: 1px solid #ddd; white-space: pre-wrap; word-wrap: break-word; }
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

    $options_to_disable = isset($_POST['option_names']) ? wp_unslash((array) $_POST['option_names']) : [];
    if (empty($options_to_disable)) wp_send_json_error(['message' => __('No options were selected.', 'autoload-optimizer')]);

    $ao_history = get_option('ao_optimizer_history', []);
    $perf_history = get_option('perflab_aao_disabled_options', []);
    if (!is_array($ao_history))   $ao_history = [];
    if (!is_array($perf_history)) $perf_history = [];

    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/autoload-options-debug.log';
    $log_content = "\n=== Autoload Disable Action - " . date('Y-m-d H:i:s') . " ===\n";
    $log_content .= "User: " . wp_get_current_user()->user_login . "\n";
    
    global $wpdb;
    $success_count = $failure_count = $already_done = 0;
    $processed_options = [];

    foreach ($options_to_disable as $option_name) {
        $sane_option_name = sanitize_text_field($option_name);
        $result = wp_set_option_autoload($sane_option_name, false);
        
        if ($result) {
            $success_count++;
            $log_content .= "[SUCCESS] '{$sane_option_name}' - Set autoload to 'no'.\n";
            $processed_options[] = $sane_option_name;
            if (!in_array($sane_option_name, $ao_history, true)) $ao_history[] = $sane_option_name;
            if (!in_array($sane_option_name, $perf_history, true)) $perf_history[] = $sane_option_name;
        } else {
            $current_autoload = $wpdb->get_var($wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $sane_option_name));
            if ('no' === $current_autoload) {
                $already_done++;
                $log_content .= "[SKIPPED] '{$sane_option_name}' - Already 'no'.\n";
                $processed_options[] = $sane_option_name;
                if (!in_array($sane_option_name, $ao_history, true)) $ao_history[] = $sane_option_name;
                if (!in_array($sane_option_name, $perf_history, true)) $perf_history[] = $sane_option_name;
            } else {
                $failure_count++;
                $log_content .= "[FAILED]  '{$sane_option_name}' - DB error.\n";
            }
        }
    }
    
    update_option('ao_optimizer_history', $ao_history, 'no');
    update_option('perflab_aao_disabled_options', $perf_history, 'no');

    file_put_contents($log_file, $log_content, FILE_APPEND);
    $message = sprintf(__('Processed %d options: %d disabled, %d failed, %d already off.', 'autoload-optimizer'), count($options_to_disable), $success_count, $failure_count, $already_done);
    $message .= ' ' . sprintf(__('Log saved to %s.', 'autoload-optimizer'), '<code>/wp-content/uploads/autoload-options-debug.log</code>');
    
    wp_send_json_success(['message' => $message, 'disabled_options' => $processed_options]);
}

// --- NEW: AJAX handler for finding option source in files ---
function ao_ajax_find_option_in_files() {
    check_ajax_referer('ao_find_source_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);
    }

    $option_name = isset($_POST['option_name']) ? sanitize_text_field(wp_unslash($_POST['option_name'])) : '';
    if (empty($option_name)) {
        wp_send_json_error(['message' => __('Option name cannot be empty.', 'autoload-optimizer')]);
    }

    // Extra security: Only allow typical option name characters to prevent path traversal or other attacks.
    if (preg_match('/[^a-zA-Z0-9_\-\*]/', $option_name)) {
        wp_send_json_error(['message' => __('Invalid characters in option name.', 'autoload-optimizer')]);
    }

    $results = [];
    $search_paths = [
        'Plugins' => WP_PLUGIN_DIR,
        'Themes'  => get_theme_root(),
    ];

    foreach ($search_paths as $type => $path) {
        if (!is_dir($path) || !is_readable($path)) continue;
        
        $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
        $iterator  = new RecursiveIteratorIterator($directory);
        $regex     = new RegexIterator($iterator, '/\.(php|js|inc)$/i');

        foreach ($regex as $file) {
            // Check if file is readable and not excessively large to avoid performance issues.
            if ($file->isReadable() && $file->getSize() > 0 && $file->getSize() < 500000) {
                $content = @file_get_contents($file->getPathname());
                if ($content !== false && strpos($content, $option_name) !== false) {
                    $results[] = [
                        'type' => $type,
                        'path' => str_replace(WP_CONTENT_DIR, 'wp-content', $file->getPathname()),
                    ];
                }
            }
        }
    }

    if (empty($results)) {
        $html = '<p>' . __('No occurrences found in plugin or theme files.', 'autoload-optimizer') . '</p>';
        $html .= '<p><small>' . __('Note: This search checks for the option name as a literal string. It may not find options that are created dynamically (e.g., from variables) or only referenced in a database.', 'autoload-optimizer') . '</small></p>';
    } else {
        $html = '<h4>' . sprintf(_n('%d file found:', '%d files found:', count($results), 'autoload-optimizer'), count($results)) . '</h4>';
        $html .= '<ul style="list-style: disc; padding-left: 20px;">';
        foreach($results as $result) {
            $html .= '<li><strong>' . esc_html($result['type']) . ':</strong> <code>' . esc_html($result['path']) . '</code></li>';
        }
        $html .= '</ul>';
    }
    wp_send_json_success(['html' => $html]);
}


add_action('admin_footer', 'ao_admin_page_scripts');
function ao_admin_page_scripts() {
    $screen = get_current_screen();
    if (!$screen || 'tools_page_autoloaded-options' !== $screen->id) return;
    ?>
    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        // --- Element Selectors ---
        const wrapper = document.getElementById('ao-plugin-wrapper');
        const modalOverlay = document.getElementById('ao-option-modal-overlay');
        const modalContent = document.getElementById('ao-option-modal-content');
        const modalTitle = document.getElementById('ao-option-modal-title');
        const modalBody = document.getElementById('ao-modal-body');
        const resultsContainer = document.getElementById('ao-results-container');
        const mainCheckbox = document.querySelector('.wp-list-table #cb input[type="checkbox"]');
        const itemCheckboxes = document.querySelectorAll('.wp-list-table .ao-option-checkbox');

        const ajaxurl = wrapper.dataset.ajaxUrl;
        const disableNonce = wrapper.dataset.disableNonce;
        const viewNonce = wrapper.dataset.viewNonce;
        const findNonce = wrapper.dataset.findNonce; // --- NEW: Get find source nonce

        // --- Core Functions ---
        
        function showResult(message, type = 'success') { 
            resultsContainer.innerHTML = `<div class="notice notice-${type} is-dismissible"><p>${message}</p></div>`; 
            resultsContainer.style.display = 'block'; 
        }

        function cleanupEmptyGroups() {
            const tableBody = document.querySelector('.wp-list-table tbody');
            if (!tableBody) return;

            const headers = tableBody.querySelectorAll('tr.plugin-header');
            headers.forEach(header => {
                const nextRow = header.nextElementSibling;
                if (!nextRow || nextRow.classList.contains('plugin-header')) {
                    header.remove();
                }
            });
        }

        function disableOptions(optionNames, button) {
            if (!confirm(`<?php _e('Are you sure you want to disable autoload for the selected option(s)?', 'autoload-optimizer'); ?>`)) return;

            const spinner = button ? button.nextElementSibling : null;
            if (button) button.disabled = true;
            if (spinner && spinner.classList.contains('spinner')) spinner.classList.add('is-active');

            const formData = new FormData();
            formData.append('action', 'ao_disable_autoload_options');
            formData.append('nonce', disableNonce);
            optionNames.forEach(name => formData.append('option_names[]', name));

            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    showResult(data.data.message, data.success ? 'success' : 'error');
                    
                    if (data.success && data.data.disabled_options) {
                        data.data.disabled_options.forEach(optionName => {
                            const row = document.querySelector(`tr[data-option-name="${optionName}"]`);
                            if (row) {
                                // --- CHANGE: Simply remove the row entirely ---
                                row.remove();
                            }
                        });
                        cleanupEmptyGroups();
                        if (mainCheckbox) mainCheckbox.checked = false;
                    }
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
            formData.append('nonce', viewNonce);
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

        // --- NEW: Function to find the source of an option ---
        function findOptionSource(optionName, spinner) {
            showModal('<?php _e('Searching for source of:', 'autoload-optimizer'); ?> ' + optionName, '<?php _e('Searching plugin and theme files...', 'autoload-optimizer'); ?>');
            if (spinner) spinner.classList.add('is-active');

            const formData = new FormData();
            formData.append('action', 'ao_find_option_in_files');
            formData.append('nonce', findNonce);
            formData.append('option_name', optionName);

            fetch(ajaxurl, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(d => {
                    modalBody.innerHTML = d.success ? d.data.html : `<p style="color:red;">${d.data.message}</p>`;
                })
                .catch(() => {
                    modalBody.innerHTML = `<p style="color:red;"><?php _e('An error occurred during the request.', 'autoload-optimizer'); ?></p>`;
                })
                .finally(() => {
                    if (spinner) spinner.classList.remove('is-active');
                });
        }

        // --- Event Listeners ---
        
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
                // --- NEW: Listener for the Find Source button ---
                if (e.target.classList.contains('find-in-files')) {
                    e.preventDefault();
                    const optionName = e.target.dataset.option;
                    const spinner = e.target.nextElementSibling;
                    findOptionSource(optionName, spinner);
                }
            });
        }
        
        // ... (Other event listeners are unchanged)
        const manualLookupForm = document.getElementById('ao-manual-lookup-form');
        if (manualLookupForm) {
            manualLookupForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const optionNameInput = document.getElementById('ao-manual-option-name');
                const spinner = this.querySelector('.spinner');
                const optionName = optionNameInput.value.trim();
                if (optionName) viewOptionContent(optionName, spinner);
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
        
        if(mainCheckbox) {
            mainCheckbox.addEventListener('change', () => {
                itemCheckboxes.forEach(cb => { 
                    if(!cb.disabled) {
                        cb.checked = mainCheckbox.checked;
                    }
                });
            });
        }
        modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) hideModal(); });
        modalContent.querySelector('.close-modal').addEventListener('click', hideModal);

    });
    </script>
    <?php
}

// --- Add "Settings" Link to Plugins Page ---

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ao_add_settings_link');
function ao_add_settings_link($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        esc_url(admin_url('tools.php?page=autoloaded-options')),
        __('Settings', 'autoload-optimizer')
    );
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
    $updater_bootstrap_file = dirname(__FILE__) . '/lib/plugin-update-checker/plugin-update-checker.php';
    if (file_exists($updater_bootstrap_file)) {
        require_once $updater_bootstrap_file;
        try {
            YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/milllan/mill_autoload_options_checker_plugin/',
                __FILE__,
                'autoload-optimizer'
            );
        } catch (Exception $e) { /* Do nothing */ }
    }
}