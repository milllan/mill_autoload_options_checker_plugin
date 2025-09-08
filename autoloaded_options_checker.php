<?php
/**
 * Plugin Name:       Autoloaded Options Optimizer
 * Plugin URI:        https://github.com/milllan/mill_autoload_options_checker_plugin
 * Description:       A tool to analyze, view, and manage autoloaded options in the wp_options table, with a remotely managed configuration.
 * Version:           4.1.7
 * Author:            Milan Petrović
 * Author URI:        https://wpspeedopt.net/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autoload-optimizer
 * Update URI:        https://github.com/milllan/mill_autoload_options_checker_plugin
 */

/**
 * Define AO_PLUGIN_VERSION for telemetry
 */
define('AO_PLUGIN_VERSION', '4.1.7');

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
    add_action('wp_ajax_ao_find_option_in_files', 'ao_ajax_find_option_in_files');
    add_action('wp_ajax_ao_send_telemetry', 'ao_ajax_send_telemetry');
}

add_action('admin_init', 'ao_register_settings');
function ao_register_settings() {
    register_setting('ao_settings', 'ao_telemetry_disabled');
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
 * Collects telemetry data for unknown options and site information
 */
function ao_collect_telemetry_data($grouped_options, $config) {
    // Check if telemetry is disabled
    if (get_option('ao_telemetry_disabled') === '1') {
        return;
    }

    $unknown_options = [];
    $known_plugins = [];
    $known_themes = [];
    $site_hash = hash('sha256', get_site_url());

    // Collect unknown options
    foreach ($grouped_options as $plugin_name => $group_data) {
        if ($plugin_name === __('Unknown', 'autoload-optimizer')) {
            foreach ($group_data['options'] as $option) {
                // Collect all unknown options > 1KB
                if ($option['length'] >= 1024) {
                    $unknown_options[] = [
                        'name' => $option['name'],
                        'size' => $option['length'],
                        'size_kb' => round($option['length'] / 1024, 2)
                    ];
                }
            }
        } else {
            // Collect known plugins/themes
            $total_size = 0;
            foreach ($group_data['options'] as $option) {
                $total_size += $option['length'];
            }

            if ($plugin_name !== __('WordPress Core', 'autoload-optimizer') &&
                $plugin_name !== __('WordPress Core (Transient)', 'autoload-optimizer')) {
                $known_plugins[] = [
                    'name' => $plugin_name,
                    'option_count' => $group_data['count'],
                    'total_size' => $total_size,
                    'total_size_kb' => round($total_size / 1024, 2)
                ];
            }
        }
    }

    // Get active theme info
    $active_theme = wp_get_theme();
    $known_themes[] = [
        'name' => $active_theme->get('Name'),
        'version' => $active_theme->get('Version'),
        'stylesheet' => $active_theme->get_stylesheet()
    ];

    // Always send telemetry data (simplified logic)
    $telemetry_data = [
        'site_hash' => $site_hash,
        'site_url' => get_site_url(), // Include site URL for deduplication
        'unknown_options' => $unknown_options,
        'known_plugins' => $known_plugins,
        'known_themes' => $known_themes,
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'plugin_count' => count(get_option('active_plugins', [])),
        'config_version' => $config['version'] ?? 'unknown',
        'timestamp' => current_time('timestamp')
    ];

    // Send telemetry asynchronously
    wp_schedule_single_event(time() + 30, 'ao_send_telemetry_event', [$telemetry_data]);
}

/**
 * Scheduled event to send telemetry data
 */
add_action('ao_send_telemetry_event', 'ao_send_telemetry_event_handler');
function ao_send_telemetry_event_handler($telemetry_data) {
    ao_send_telemetry_data($telemetry_data);
}

/**
 * Sends telemetry data to the collection endpoint
 */
function ao_send_telemetry_data($telemetry_data) {
    $endpoint = apply_filters('ao_telemetry_endpoint', 'https://wpspeedopt.net/telemetry-backend/telemetry-collector.php');

    $response = wp_remote_post($endpoint, [
        'method' => 'POST',
        'timeout' => 15,
        'headers' => [
            'Content-Type' => 'application/json',
            'User-Agent' => 'AutoloadedOptionsOptimizer/' . AO_PLUGIN_VERSION
        ],
        'body' => wp_json_encode($telemetry_data)
    ]);

    // Log success/failure for debugging (optional)
    if (is_wp_error($response)) {
        error_log('AO Telemetry Error: ' . $response->get_error_message());
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('AO Telemetry HTTP Error: ' . $response_code . ' - ' . wp_remote_retrieve_body($response));
        }
    }
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
                        // --- FIX: Use a generic status for non-specific theme rules ---
                        $status_info = ['code' => 'theme_generic', 'text' => __('Theme', 'autoload-optimizer'), 'class' => 'notice-info'];
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

    // Collect telemetry data for unknown options if enabled
    if (get_option('ao_telemetry_enabled') === '1') {
        ao_collect_telemetry_data($grouped_options, $config);
    }

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

/**
 * --- NEW: Generates general recommendations based on autoloaded option sizes. ---
 *
 * @param int $large_options_size The total size of options > 1KB.
 * @return string HTML formatted recommendations.
 */
function ao_get_general_recommendations_html($large_options_size) {
    $total_size_kb = $large_options_size / 1024;
    $html = '';
    $html .= '<p><strong>' . __('A Note on "Over-Optimizing":', 'autoload-optimizer') . '</strong> ';
    $html .= __('The goal is not to eliminate all large options, but to disable autoload for data that isn\'t needed on every page load (like admin notices, logs, or temporary caches). Options smaller than 1KB have a negligible impact and are not shown.', 'autoload-optimizer') . '</p>';

    // Tiered recommendations based on total size of large options
    $html .= '<p><strong>' . __('General Health:', 'autoload-optimizer') . '</strong> ';
    if ($total_size_kb < 800) {
        $html .= '<span class="notice notice-success" style="padding: 2px 8px; display: inline-block; margin: 0;">' . __('Good', 'autoload-optimizer') . '</span> ';
        $html .= __('Your total autoload size is in a healthy range. Review the table for any clear outliers from inactive plugins or known "safe" options.', 'autoload-optimizer');
    } elseif ($total_size_kb < 2048) {
        $html .= '<span class="notice notice-warning" style="padding: 2px 8px; display: inline-block; margin: 0;">' . __('Needs Attention', 'autoload-optimizer') . '</span> ';
        $html .= __('Your total autoload size is high. Prioritize options larger than 10KB and those from inactive plugins to see the most improvement.', 'autoload-optimizer');
    } else {
        $html .= '<span class="notice notice-error" style="padding: 2px 8px; display: inline-block; margin: 0;">' . __('Critical', 'autoload-optimizer') . '</span> ';
        $html .= __('Your total autoload size is very large and likely impacting performance. Focus on the largest options first, especially any over 100KB, as they are often the result of a plugin bug or misconfiguration.', 'autoload-optimizer');
    }
    $html .= '</p>';

    return $html;
}

function ao_display_admin_page() {
    if (!current_user_can('manage_options')) return;

    $config_manager = AO_Remote_Config_Manager::get_instance();
    if (isset($_GET['ao_refresh_config']) && check_admin_referer('ao_refresh_config')) {
        delete_transient('ao_remote_config_cache');
        wp_safe_redirect(remove_query_arg(['ao_refresh_config', '_wpnonce']));
        exit;
    }
    
    $data = ao_get_analysis_data(); 
    $status_message = $config_manager->get_config_status();

    extract($data);
    
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
            <p><button id="ao-disable-safe-options" class="button"><?php _e('Disable Autoload for All Safe Options', 'autoload-optimizer'); ?></button></p>
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
            </div>
            <div class="card" style="flex: 1.5;">
                <h2 class="title"><?php _e('Recommendations', 'autoload-optimizer'); ?></h2>
                <div>
                    <?php echo ao_get_general_recommendations_html($large_options_size); ?>
                </div>
                <hr style="margin: 1rem 0;">
                <div>
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
                            echo '<div style="margin-bottom: 0.75rem;">' . wp_kses_post($styled_rec_text) . '</div>';
                        }
                    }
                    if (!$recommendation_found) { 
                        echo '<em>' . __('No plugin-specific recommendations for the large options found on your site.', 'autoload-optimizer') . '</em>'; 
                    }
                    ?>
                </div>
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
                                    <?php elseif ($is_unknown) : ?>
                                        <button class="button find-in-files" data-option="<?php echo esc_attr($option['name']); ?>"><?php _e('Find Source', 'autoload-optimizer'); ?></button>
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
            </form>
        </div>

        <div class="card" style="margin-top: 1rem;">
            <h2 class="title"><?php _e('Telemetry Settings', 'autoload-optimizer'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('ao_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Usage Data Collection', 'autoload-optimizer'); ?></th>
                        <td>
                            <label for="ao_telemetry_disabled">
                                <input type="checkbox" id="ao_telemetry_disabled" name="ao_telemetry_disabled" value="1" <?php checked(get_option('ao_telemetry_disabled'), '1'); ?> />
                                <?php _e('Disable anonymous usage data collection', 'autoload-optimizer'); ?>
                            </label>
                            <p class="description">
                                <?php _e('By default, the plugin sends anonymous usage data to help improve plugin coverage and identify popular plugins/themes. This includes option names, sizes, and site information but no sensitive data. Uncheck to disable.', 'autoload-optimizer'); ?>
                                <a href="#" id="ao-privacy-details"><?php _e('Learn more about what data is collected', 'autoload-optimizer'); ?></a>
                            </p>
                            <p class="description">
                                <strong><?php _e('Note:', 'autoload-optimizer'); ?></strong> <?php _e('To collect telemetry data, you need to set up the telemetry collector endpoint. Use the filter', 'autoload-optimizer'); ?> <code>ao_telemetry_endpoint</code> <?php _e('or modify the endpoint URL in the plugin code.', 'autoload-optimizer'); ?>
                            </p>
                            <?php if (get_option('ao_telemetry_disabled') !== '1') : ?>
                            <p>
                                <button type="button" id="ao-send-telemetry" class="button button-secondary">
                                    <?php _e('Send Telemetry Data Now', 'autoload-optimizer'); ?>
                                </button>
                                <span id="ao-telemetry-status"></span>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Settings', 'autoload-optimizer')); ?>
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
        <div id="ao-privacy-modal-overlay"><div id="ao-privacy-modal-content"><span class="close-modal">&times;</span><h2><?php _e('Telemetry Data Collection', 'autoload-optimizer'); ?></h2><div id="ao-privacy-body">
            <p><strong><?php _e('What data is collected:', 'autoload-optimizer'); ?></strong></p>
            <ul>
                <li><?php _e('Site URL (hashed for privacy, used for deduplication)', 'autoload-optimizer'); ?></li>
                <li><?php _e('Names and sizes of unknown autoloaded options (no values)', 'autoload-optimizer'); ?></li>
                <li><?php _e('Names and usage statistics of known plugins/themes', 'autoload-optimizer'); ?></li>
                <li><?php _e('WordPress and PHP version numbers', 'autoload-optimizer'); ?></li>
                <li><?php _e('Total count of active plugins and themes', 'autoload-optimizer'); ?></li>
            </ul>
            <p><strong><?php _e('What is NOT collected:', 'autoload-optimizer'); ?></strong></p>
            <ul>
                <li><?php _e('Option values or sensitive data', 'autoload-optimizer'); ?></li>
                <li><?php _e('Admin email, passwords, or personal information', 'autoload-optimizer'); ?></li>
                <li><?php _e('Database contents or file system data', 'autoload-optimizer'); ?></li>
                <li><?php _e('User-generated content or media files', 'autoload-optimizer'); ?></li>
            </ul>
            <p><strong><?php _e('How the data helps:', 'autoload-optimizer'); ?></strong></p>
            <p><?php _e('This data helps identify popular plugins/themes and track usage patterns across different websites. The site URL hash prevents duplicate submissions while allowing us to see how sites change over time. This improves plugin coverage and helps prioritize development efforts.', 'autoload-optimizer'); ?></p>
            <p><strong><?php _e('Data handling:', 'autoload-optimizer'); ?></strong></p>
            <p><?php _e('Data is sent securely via HTTPS and stored temporarily for analysis. Site URLs are hashed before storage. You can disable telemetry at any time in the settings above.', 'autoload-optimizer'); ?></p>
        </div></div></div>
    </div>
    <?php
}

function ao_admin_page_styles() {
    ?>
    <style>
        .wp-list-table .column-option-name { width: 35%; } .wp-list-table .column-size, .wp-list-table .column-percentage { width: 8%; } .wp-list-table .column-plugin { width: 15%; } .wp-list-table .column-status { width: 12%; } .wp-list-table .column-action { width: 10%; } .wp-list-table tbody tr.group-color-a { background-color: #ffffff; } .wp-list-table tbody tr.group-color-b { background-color: #f6f7f7; } .wp-list-table tbody tr:hover { background-color: #f0f0f1 !important; } .wp-list-table tbody tr.ao-row-processed { opacity: 0.6; pointer-events: none; } .plugin-header th, .plugin-header td { font-weight: bold; background-color: #f0f0f1; border-bottom: 1px solid #ddd; } .view-option-content { cursor: pointer; }         #ao-option-modal-overlay, #ao-privacy-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); z-index: 10001; justify-content: center; align-items: center; } #ao-option-modal-content, #ao-privacy-modal-content { background: #fff; padding: 20px; border-radius: 4px; width: 80%; max-width: 900px; max-height: 80vh; overflow-y: auto; position: relative; } .close-modal { position: absolute; top: 5px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; color: #555; } #ao-modal-body pre, #ao-privacy-body { background: #f1f1f1; padding: 15px; border: 1px solid #ddd; white-space: pre-wrap; word-wrap: break-word; }
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

/**
 * AJAX handler for sending telemetry data
 */
function ao_ajax_send_telemetry() {
    check_ajax_referer('ao_telemetry_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);
    }

    if (get_option('ao_telemetry_disabled') === '1') {
        wp_send_json_error(['message' => __('Telemetry is disabled.', 'autoload-optimizer')]);
    }

    $data = ao_get_analysis_data();
    $config = $data['config'];

    // Collect all data using the same logic as automatic collection
    $unknown_options = [];
    $known_plugins = [];
    $site_hash = hash('sha256', get_site_url());

    // Collect unknown options
    foreach ($data['grouped_options'] as $plugin_name => $group_data) {
        if ($plugin_name === __('Unknown', 'autoload-optimizer')) {
            foreach ($group_data['options'] as $option) {
                if ($option['length'] >= 1024) {
                    $unknown_options[] = [
                        'name' => $option['name'],
                        'size' => $option['length'],
                        'size_kb' => round($option['length'] / 1024, 2)
                    ];
                }
            }
        } else {
            // Collect known plugins/themes
            $total_size = 0;
            foreach ($group_data['options'] as $option) {
                $total_size += $option['length'];
            }

            if ($plugin_name !== __('WordPress Core', 'autoload-optimizer') &&
                $plugin_name !== __('WordPress Core (Transient)', 'autoload-optimizer')) {
                $known_plugins[] = [
                    'name' => $plugin_name,
                    'option_count' => $group_data['count'],
                    'total_size' => $total_size,
                    'total_size_kb' => round($total_size / 1024, 2)
                ];
            }
        }
    }

    // Get active theme info
    $active_theme = wp_get_theme();
    $known_themes = [[
        'name' => $active_theme->get('Name'),
        'version' => $active_theme->get('Version'),
        'stylesheet' => $active_theme->get_stylesheet()
    ]];

    $telemetry_data = [
        'site_hash' => $site_hash,
        'site_url' => get_site_url(),
        'unknown_options' => $unknown_options,
        'known_plugins' => $known_plugins,
        'known_themes' => $known_themes,
        'wp_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'plugin_count' => count(get_option('active_plugins', [])),
        'config_version' => $config['version'] ?? 'unknown',
        'timestamp' => current_time('timestamp'),
        'manual_send' => true
    ];

    ao_send_telemetry_data($telemetry_data);
    wp_send_json_success(['message' => __('Telemetry data sent successfully. Thank you for helping improve the plugin!', 'autoload-optimizer')]);
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
        const findNonce = wrapper.dataset.findNonce;

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
            
            if (button) button.disabled = true;

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

        function viewOptionContent(optionName) {
            showModal('<?php _e('Viewing:', 'autoload-optimizer'); ?> ' + optionName, '<?php _e('Loading...', 'autoload-optimizer'); ?>');
            
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
                });
        }

        function findOptionSource(optionName) {
            showModal('<?php _e('Searching for source of:', 'autoload-optimizer'); ?> ' + optionName, '<?php _e('Searching plugin and theme files...', 'autoload-optimizer'); ?>');
            
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
                });
        }

        // --- Event Listeners ---
        
        const tableBody = document.querySelector('.wp-list-table tbody');
        if (tableBody) {
            tableBody.addEventListener('click', function(e) {
                if (e.target.classList.contains('view-option-content')) {
                    e.preventDefault();
                    viewOptionContent(e.target.dataset.optionName);
                }
                if (e.target.classList.contains('disable-single')) {
                    e.preventDefault();
                    disableOptions([e.target.dataset.option], e.target);
                }
                if (e.target.classList.contains('find-in-files')) {
                    e.preventDefault();
                    findOptionSource(e.target.dataset.option);
                }
            });
        }
        
        const manualLookupForm = document.getElementById('ao-manual-lookup-form');
        if (manualLookupForm) {
            manualLookupForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const optionNameInput = document.getElementById('ao-manual-option-name');
                const optionName = optionNameInput.value.trim();
                if (optionName) viewOptionContent(optionName);
            });
        }
        
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

        // Privacy modal handling
        const privacyOverlay = document.getElementById('ao-privacy-modal-overlay');
        const privacyModal = document.getElementById('ao-privacy-modal-content');
        const privacyLink = document.getElementById('ao-privacy-details');

        function showPrivacyModal() {
            privacyOverlay.style.display = 'flex';
        }

        function hidePrivacyModal() {
            privacyOverlay.style.display = 'none';
        }

        if (privacyLink) {
            privacyLink.addEventListener('click', e => {
                e.preventDefault();
                showPrivacyModal();
            });
        }

        if (privacyOverlay) {
            privacyOverlay.addEventListener('click', e => { if (e.target === privacyOverlay) hidePrivacyModal(); });
            privacyModal.querySelector('.close-modal').addEventListener('click', hidePrivacyModal);
        }

        // Manual telemetry send
        const sendTelemetryBtn = document.getElementById('ao-send-telemetry');
        const telemetryStatus = document.getElementById('ao-telemetry-status');

        if (sendTelemetryBtn) {
            sendTelemetryBtn.addEventListener('click', e => {
                e.preventDefault();
                sendTelemetryBtn.disabled = true;
                telemetryStatus.textContent = '<?php _e('Sending...', 'autoload-optimizer'); ?>';

                const formData = new FormData();
                formData.append('action', 'ao_send_telemetry');
                formData.append('nonce', '<?php echo wp_create_nonce('ao_telemetry_nonce'); ?>');

                fetch(ajaxurl, { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        telemetryStatus.textContent = data.success
                            ? '<?php _e('✓ Sent successfully!', 'autoload-optimizer'); ?>'
                            : '<?php _e('✗ Failed to send', 'autoload-optimizer'); ?>';
                        if (!data.success) {
                            console.error('Telemetry error:', data.data.message);
                        }
                    })
                    .catch(() => {
                        telemetryStatus.textContent = '<?php _e('✗ Network error', 'autoload-optimizer'); ?>';
                    })
                    .finally(() => {
                        sendTelemetryBtn.disabled = false;
                        setTimeout(() => { telemetryStatus.textContent = ''; }, 5000);
                    });
            });
        }
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

add_action('plugins_loaded', 'ao_initialize_updater');
function ao_initialize_updater() {
    // ... (This function is unchanged)
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