<?php
/**
 * Plugin Name:       Autoloaded Options Optimizer
 * Plugin URI:        https://github.com/milllan/mill_autoload_options_checker_plugin
 * Description:       A tool to analyze, view, and manage autoloaded options in the wp_options table, with a remotely managed configuration.
 * Version:           4.1.28
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
define('AO_PLUGIN_VERSION', '4.1.28');
define('AO_PLUGIN_FILE', __FILE__);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Manages the remote configuration.
 */
final class AO_Remote_Config_Manager {
    private static $instance;
    private const DEFAULT_REMOTE_URL = 'https://raw.githubusercontent.com/milllan/mill_autoload_options_checker_plugin/main/known-options.json';
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
        $local_path = plugin_dir_path(AO_PLUGIN_FILE) . 'known-options.json';
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
        $required_keys = ['version', 'plugins', 'safe_literals', 'safe_patterns'];
        foreach ($required_keys as $key) {
            if (!isset($config[$key])) return false;
        }
        return true;
    }

    private function get_empty_config_structure() {
        $this->config_status = __('Error: Could not fetch config and no valid local fallback was found.', 'autoload-optimizer');
        return [
            'version' => '0.0.0 (Error)', 'plugins' => [], 'safe_literals' => [],
            'safe_patterns' => [], 'recommendations' => [], 'general_recommendations' => [],
        ];
    }

    public function get_config_status() {
        return $this->config_status;
    }

    public function get_all_safe_patterns() {
        $config = $this->get_config();
        $safe_patterns = $config['safe_patterns'] ?? [];

        foreach ($config['plugins'] ?? [] as $plugin) {
            foreach ($plugin['options'] ?? [] as $option_string) {
                if (strpos($option_string, 'safe:') === 0) {
                    $safe_patterns[] = substr($option_string, 5);
                }
            }
        }
        return array_unique($safe_patterns);
    }
}

final class Autoloaded_Options_Optimizer_Plugin {
    private static $instance;

    public static function get_instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_page']);
        add_action('admin_init', [$this, 'register_ajax_handlers']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_head-tools_page_autoloaded-options', [$this, 'admin_page_styles']);
        add_action('ao_send_telemetry_event', [$this, 'send_telemetry_event_handler']);
        add_action('admin_footer', [$this, 'admin_page_scripts']);
        add_filter('plugin_action_links_' . plugin_basename(AO_PLUGIN_FILE), [$this, 'add_settings_link']);
        add_action('plugins_loaded', [$this, 'initialize_updater']);
    }

    public function add_admin_page() {
        add_management_page(
            __('Autoloaded Options Optimizer', 'autoload-optimizer'),
            __('Autoloaded Options Optimizer', 'autoload-optimizer'),
            'manage_options',
            'autoloaded-options',
            [$this, 'display_admin_page']
        );
    }

    public function register_ajax_handlers() {
        add_action('wp_ajax_ao_disable_autoload_options', [$this, 'ajax_disable_autoload_options']);
        add_action('wp_ajax_ao_get_option_value', [$this, 'ajax_get_option_value']);
        add_action('wp_ajax_ao_find_option_in_files', [$this, 'ajax_find_option_in_files']);
        add_action('wp_ajax_ao_send_telemetry', [$this, 'ajax_send_telemetry']);
    }

    public function register_settings() {
        register_setting('ao_settings', 'ao_telemetry_disabled');
    }

    private function collect_telemetry_data($grouped_options, $config) {
        if (get_option('ao_telemetry_disabled') === '1') {
            return;
        }

        $telemetry_data = $this->build_telemetry_payload($grouped_options, $config);

        $key = 'ao_tel_' . wp_generate_password(12, false, false);
        set_transient($key, $telemetry_data, 10 * MINUTE_IN_SECONDS);
        wp_schedule_single_event(time() + 30, 'ao_send_telemetry_event', [$key]);
    }

    //helper
    private function build_telemetry_payload($grouped_options, $config) {
        $unknown_options = [];
        $known_plugins = [];
        $known_themes = [];
        $site_hash = hash('sha256', get_site_url());

        // Collect unknown options
        foreach ($grouped_options as $plugin_name => $group_data) {
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

        $active_theme = wp_get_theme();
        $known_themes[] = [
            'name' => $active_theme->get('Name'),
            'version' => $active_theme->get('Version'),
            'stylesheet' => $active_theme->get_stylesheet()
        ];

        return [
            'site_hash' => $site_hash,
            'site_url' => get_site_url(),
            'unknown_options' => $unknown_options,
            'known_plugins' => $known_plugins,
            'known_themes' => $known_themes,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_count' => count(get_option('active_plugins', [])),
            'config_version' => $config['version'] ?? 'unknown',
            'timestamp' => current_time('timestamp')
        ];
    }


    /**
     * Scheduled event to send telemetry data
     */
    public function send_telemetry_event_handler($key) {
        $payload = get_transient($key);
        if ($payload !== false) {
            delete_transient($key);
            $this->send_telemetry_data($payload);
        }
    }

/**
 * Sends telemetry data to the collection endpoint
 */

    private function send_telemetry_data($telemetry_data) {
        $endpoint = apply_filters('ao_telemetry_endpoint', 'https://wpspeedopt.net/telemetry-backend/telemetry-collector.php');

        wp_remote_post($endpoint, [
            'method' => 'POST',
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'AutoloadedOptionsOptimizer/' . AO_PLUGIN_VERSION
            ],
            'body' => wp_json_encode($telemetry_data)
        ]);
    }

/**
 * Identifies an option's source from the new, leaner plugin-centric config structure.
 *
 * This function iterates through the known plugins and their associated option strings.
 * It parses strings with a "safe:" prefix to determine their safety status.
 *
 * @param string $option_name The name of the option to identify.
 * @param array  $plugins_config The 'plugins' section of the config array.
 * @param array  $active_plugin_paths An array of active plugin paths.
 * @return array|false The identified source information or false if no match is found.
 */
    private function identify_option_source_from_config($option_name, $plugins_config, $active_plugin_paths) {
        $active_theme = wp_get_theme();
        $theme_slugs = [strtolower($active_theme->get_stylesheet())];
        if ($active_theme->parent()) {
            $theme_slugs[] = strtolower($active_theme->get_template());
        }

        foreach ($plugins_config as $plugin_slug => $plugin_data) {
            if (empty($plugin_data['options'])) {
                continue;
            }

            foreach ($plugin_data['options'] as $option_string) {
                $is_safe = false;
                $pattern = $option_string;

                if (strpos($pattern, 'safe:') === 0) {
                    $is_safe = true;
                    $pattern = substr($pattern, 5);
                }

                if (fnmatch($pattern, $option_name)) {
                    $file_info = $plugin_data['file'];
                    $status_info = ['code' => 'unknown', 'text' => __('Unknown', 'autoload-optimizer'), 'class' => ''];

                    if (strpos($file_info, 'theme:') === 0) {
                        $theme_slug = strtolower(substr($file_info, 6));
                        if (in_array($theme_slug, $theme_slugs, true)) {
                            $status_info = ['code' => 'theme_active', 'text' => __('Active Theme', 'autoload-optimizer'), 'class' => 'notice-info'];
                        } else {
                            $status_info = ['code' => 'theme_inactive', 'text' => __('Inactive Theme', 'autoload-optimizer'), 'class' => 'notice-error'];
                        }
                    } elseif ($file_info === 'core') {
                        $status_info = ['code' => 'core', 'text' => __('WordPress Core', 'autoload-optimizer'), 'class' => 'notice-info'];
                    } elseif (in_array($file_info, $active_plugin_paths)) {
                        $status_info = ['code' => 'plugin_active', 'text' => __('Active Plugin', 'autoload-optimizer'), 'class' => 'notice-success'];
                    } else {
                        $status_info = ['code' => 'plugin_inactive', 'text' => __('Inactive Plugin', 'autoload-optimizer'), 'class' => 'notice-error'];
                    }

                    return [
                        'name'      => $plugin_data['name'],
                        'status'    => $status_info,
                        'is_safe'   => $is_safe,
                        'recommendation' => $plugin_data['recommendation'] ?? null
                    ];
                }
            }
        }

        return false;
    }

/**
 * Gathers and processes all data for the analysis page.
 * This is the final, corrected version with robust safety checking.
 *
 * @param bool $schedule_telemetry Whether to schedule telemetry collection.
 * @return array Processed data for display.
 */

    private function get_analysis_data($schedule_telemetry = true) {
        global $wpdb;
        $config = AO_Remote_Config_Manager::get_instance()->get_config();
        $plugins_config = $config['plugins'] ?? [];
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
        $all_safe_patterns = AO_Remote_Config_Manager::get_instance()->get_all_safe_patterns();
        foreach ($large_options as $option) {
            $plugin_name = __('Unknown', 'autoload-optimizer');
            $status_info = ['code' => 'unknown', 'text' => __('Unknown', 'autoload-optimizer'), 'class' => ''];
            $source_info = $this->identify_option_source_from_config($option->option_name, $plugins_config, $active_plugin_paths);
            if ($source_info !== false) {
                $plugin_name = $source_info['name'];
                $status_info = $source_info['status'];
                $recommendation = $source_info['recommendation'];
            } else {
                $recommendation = null;
                if (strpos($option->option_name, '_transient_') === 0 || strpos($option->option_name, '_site_transient_') === 0) {
                    $plugin_name = __('WordPress Core (Transient)', 'autoload-optimizer');
                    $status_info = ['code' => 'core', 'text' => __('WordPress Core', 'autoload-optimizer'), 'class' => 'notice-info'];
                }
            }
            $is_safe = false;
            if (in_array($option->option_name, $config['safe_literals'] ?? [])) {
                $is_safe = true;
            } else {
                foreach ($all_safe_patterns as $safe_pattern) {
                    if (fnmatch($safe_pattern, $option->option_name)) {
                        $is_safe = true;
                        break;
                    }
                }
            }
            if (strpos($status_info['code'], '_inactive') !== false) {
                $inactive_plugin_option_count++;
            }
            if (!isset($grouped_options[$plugin_name])) {
                $grouped_options[$plugin_name] = [
                    'total_size' => 0, 'count' => 0, 'options' => [], 'status' => $status_info, 'recommendation' => $recommendation
                ];
            }
            $grouped_options[$plugin_name]['total_size'] += $option->option_length;
            $grouped_options[$plugin_name]['count']++;
            $grouped_options[$plugin_name]['options'][] = [
                'name' => $option->option_name, 'length' => $option->option_length, 'is_safe' => $is_safe, 'status' => $status_info
            ];
        }
        uasort($grouped_options, function($a, $b) { return $b['total_size'] <=> $a['total_size']; });
        if ($schedule_telemetry && get_option('ao_telemetry_disabled') !== '1') {
            $this->collect_telemetry_data($grouped_options, $config);
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

    private function get_general_recommendations_html($large_options_size) {
        $total_size_kb = $large_options_size / 1024;
        $html = '';
        $html .= '<p><strong>' . __('A Note on "Over-Optimizing":', 'autoload-optimizer') . '</strong> ';
        $html .= __('The goal is not to eliminate all large options, but to disable autoload for data that isn\'t needed on every page load (like admin notices, logs, or temporary caches). Options smaller than 1KB have a negligible impact and are not shown.', 'autoload-optimizer') . '</p>';
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

    public function display_admin_page() {
        if (!current_user_can('manage_options')) return;

        $config_manager = AO_Remote_Config_Manager::get_instance();
        if (isset($_GET['ao_refresh_config']) && check_admin_referer('ao_refresh_config')) {
            delete_transient('ao_remote_config_cache');
            delete_transient('ao_telemetry_check_flag');
            wp_safe_redirect(remove_query_arg(['ao_refresh_config', '_wpnonce']));
            exit;
        }

        if (false === get_transient('ao_telemetry_check_flag')) {
            $data = $this->get_analysis_data(true);
            set_transient('ao_telemetry_check_flag', '1', 7 * DAY_IN_SECONDS);
        } else {
            $data = $this->get_analysis_data(false); 
        }

        $status_message = $config_manager->get_config_status();
        ?>
        <div class="wrap" id="ao-plugin-wrapper" 
            data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
            data-disable-nonce="<?php echo esc_attr(wp_create_nonce('ao_disable_autoload_nonce')); ?>"
            data-view-nonce="<?php echo esc_attr(wp_create_nonce('ao_view_option_nonce')); ?>"
            data-find-nonce="<?php echo esc_attr(wp_create_nonce('ao_find_source_nonce')); ?>">
            <h1><?php _e('Autoloaded Options Optimizer', 'autoload-optimizer'); ?></h1>

            <?php $this->render_summary_notices($data); ?>
            <?php $this->render_config_status($status_message); ?>
            <?php $this->render_dashboard_widgets($data); ?>
            <?php $this->render_history_table(); ?>
            <?php $this->render_options_table($data); ?>
            <?php $this->render_bottom_widgets(); ?>
            <?php $this->render_modal(); ?>

        </div>
        <?php
    }

    private function render_summary_notices($data) {
        extract($data);
        ?>
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
        <?php
    }

    private function render_config_status($status_message) {
        ?>
        <div class="notice notice-info notice-alt" style="margin-top: 1rem;">
            <p><strong><?php _e('Configuration Status:', 'autoload-optimizer'); ?></strong> <?php echo esc_html($status_message); ?></p>
            <p><a href="<?php echo esc_url(wp_nonce_url(add_query_arg('ao_refresh_config', '1'), 'ao_refresh_config')); ?>" class="button"><?php _e('Force Refresh Configuration', 'autoload-optimizer'); ?></a></p>
        </div>
        <?php
    }

    // --- MODIFICATION: Removed the Bulk Actions card and the flex container ---
    private function render_dashboard_widgets($data) {
        extract($data);
        ?>
    <div id="ao-top-widgets-wrap" style="display: flex; gap: 20px; margin-top: 1rem;">
        <div class="card" style="flex: 1;margin-top: 1rem;">
            <h2 class="title"><?php _e('Recommendations', 'autoload-optimizer'); ?></h2>
            <div>
                <?php echo $this->get_general_recommendations_html($large_options_size); ?>
            </div>
            <hr style="margin: 1rem 0;">
            <div>
                <?php
                $recommendation_found = false;
                foreach ($grouped_options as $plugin_name => $group_data) {
                    $has_actionable_options = (strpos($group_data['status']['code'], '_inactive') !== false);
                    if (!$has_actionable_options) {
                        foreach ($group_data['options'] as $option) {
                            if ($option['is_safe']) {
                                $has_actionable_options = true;
                                break;
                            }
                        }
                    }
                    if ($has_actionable_options && !empty($group_data['recommendation'])) {
                        $recommendation_found = true;
                        $rec_text = $group_data['recommendation']; 
                        $status_class = $group_data['status']['class'] ?? 'notice-info';
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
        <?php
    }

    private function render_options_table($data) {
        extract($data);
        if (empty($large_options)) {
            echo '<p>' . __('No autoloaded options larger than 1KB found.', 'autoload-optimizer') . '</p>';
            return;
        }
        ?>
        <div id="ao-results-container" style="display:none; margin-top: 1rem;"></div>
        <h2><?php _e('Large Autoloaded Options (>1KB)', 'autoload-optimizer'); ?></h2>
        <table class="wp-list-table widefat fixed">
            <thead>
                <tr>
                    <?php // --- MODIFICATION: Removed checkbox header --- ?>
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
                        <?php // --- MODIFICATION: Changed colspan from 6 to 5 --- ?>
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
                            <?php // --- MODIFICATION: Removed checkbox table cell --- ?>
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
        <?php
    }

    private function render_bottom_widgets() {
        ?>
        <div id="ao-bottom-widgets-wrap" style="display: flex; gap: 20px; margin-top: 1rem;">
            <div class="card" style="flex: 1;">
                <h2 class="title"><?php _e('Manual Option Lookup', 'autoload-optimizer'); ?></h2>
                <p><?php _e('Enter any option name from the wp_options table to view its content.', 'autoload-optimizer'); ?></p>
                <form id="ao-manual-lookup-form" style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" id="ao-manual-option-name" name="option_name" placeholder="<?php _e('e.g., active_plugins', 'autoload-optimizer'); ?>" style="width: 300px;" required>
                    <button type="submit" class="button button-secondary"><?php _e('View Option', 'autoload-optimizer'); ?></button>
                </form>
            </div>
             <div class="card" style="flex: 1;">
                 <h2 class="title"><?php _e('Telemetry Settings', 'autoload-optimizer'); ?></h2>
             <form method="post" action="options.php">
                 <?php settings_fields('ao_settings'); ?>
                 <div style="margin-bottom: 0;">
                     <label for="ao_telemetry_disabled" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">
                         <input type="checkbox" id="ao_telemetry_disabled" name="ao_telemetry_disabled" value="1" <?php checked(get_option('ao_telemetry_disabled'), '1'); ?> style="margin-right: 8px;" />
                         <?php _e('Disable anonymous usage data collection', 'autoload-optimizer'); ?>
                     </label>
                     <p class="description" style="margin: 0.5rem 0; color: #666; font-size: 13px; line-height: 1.4;">
                         <?php _e('By default, the plugin sends anonymous usage data to help improve plugin coverage and identify popular plugins/themes. This includes option names, sizes, and site information but no sensitive data. Uncheck to disable.', 'autoload-optimizer'); ?>
                     </p>
                 </div>
                 <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #ddd;">
                     <?php if (get_option('ao_telemetry_disabled') !== '1') : ?>
                         <div>
                             <button type="button" id="ao-send-telemetry" class="button button-secondary">
                                 <?php _e('Send Telemetry Data Now', 'autoload-optimizer'); ?>
                             </button>
                             <span id="ao-telemetry-status" style="margin-left: 10px; font-style: italic;"></span>
                         </div>
                     <?php else: ?>
                        <div></div>
                     <?php endif; ?>
                     <div>
                         <?php submit_button(__('Save Settings', 'autoload-optimizer')); ?>
                     </div>
                 </div>
             </form>
         </div>
        </div>
        <?php
    }

    private function render_history_table() {
        $ao_history   = get_option('ao_optimizer_history', []);
        $perf_history = get_option('perflab_aao_disabled_options', []);
        $combined_history = array_unique(array_merge(is_array($ao_history) ? $ao_history : [], is_array($perf_history) ? $perf_history : []));
        sort($combined_history);
        if (empty($combined_history)) return;
        ?>
        <div class="card" style="margin-top: 2rem;max-width:unset">
            <h2 class="title"><?php _e('History: Options with Autoload Disabled', 'autoload-optimizer'); ?></h2>
            <p><?php _e('This is a combined list of options where autoload has been disabled by this plugin or by the Performance Lab plugin.', 'autoload-optimizer'); ?></p>
            <table class="wp-list-table widefat striped">
                <thead><tr><th class="manage-column"><?php _e('Option Name', 'autoload-optimizer'); ?></th><th class="manage-column" style="width:185px"><?php _e('Source of Change', 'autoload-optimizer'); ?></th></tr></thead>
                <tbody>
                    <?php foreach ($combined_history as $option_name) : if (!is_string($option_name)) continue; $sources = []; if (in_array($option_name, $ao_history, true)) $sources[] = __('This Plugin', 'autoload-optimizer'); if (in_array($option_name, $perf_history, true)) $sources[] = __('Performance Lab', 'autoload-optimizer'); ?>
                    <tr><td><strong><?php echo esc_html($option_name); ?></strong></td><td><?php echo esc_html(implode(' & ', $sources)); ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
        <?php
    }

    private function render_modal() {
        ?>
        <div id="ao-option-modal-overlay"><div id="ao-option-modal-content"><span class="close-modal">&times;</span><h2 id="ao-option-modal-title"></h2><div id="ao-modal-body"></div></div></div>
        <?php
    }

    public function admin_page_styles() {
        ?>
        <style>
            <?php // --- MODIFICATION: Adjusted column widths after removing checkboxes --- ?>
            .wp-list-table .column-option-name { width: 40%; } .wp-list-table .column-size, .wp-list-table .column-percentage { width: 8%; } .wp-list-table .column-plugin { width: 20%; } .wp-list-table .column-status { width: 12%; } .wp-list-table .column-action { width: 10%; } .wp-list-table tbody tr.group-color-a { background-color: #ffffff; } .wp-list-table tbody tr.group-color-b { background-color: #f6f7f7; } .wp-list-table tbody tr:hover { background-color: #f0f0f1 !important; } .wp-list-table tbody tr.ao-row-processed { opacity: 0.6; pointer-events: none; } .plugin-header th, .plugin-header td { font-weight: bold; background-color: #f0f0f1; border-bottom: 1px solid #ddd; } .view-option-content { cursor: pointer; }
            .wp-list-table tbody tr { height: 32px; }
            .wp-list-table tbody td, .wp-list-table tbody th { padding: 0 8px; vertical-align: middle; }
            .wp-list-table thead th { padding: 8px; }
            #ao-option-modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); z-index: 10001; justify-content: center; align-items: center; } #ao-option-modal-content { background: #fff; padding: 20px; border-radius: 4px; width: 80%; max-width: 900px; max-height: 80vh; overflow-y: auto; position: relative; } .close-modal { position: absolute; top: 5px; right: 15px; font-size: 28px; font-weight: bold; cursor: pointer; color: #555; } #ao-modal-body pre { background: #f1f1f1; padding: 15px; border: 1px solid #ddd; white-space: pre-wrap; word-wrap: break-word; }
        </style>
        <?php
    }

    public function ajax_get_option_value() {
        check_ajax_referer('ao_view_option_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);
        $option_name = sanitize_text_field($_POST['option_name']);
        $option_value = get_option($option_name);
        if (false === $option_value) wp_send_json_error(['message' => __('Option not found.', 'autoload-optimizer')]);
        wp_send_json_success(['value' => '<pre>' . esc_html(print_r($option_value, true)) . '</pre>']);
    }

    public function ajax_disable_autoload_options() {
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
        file_put_contents($log_file, $log_content, FILE_APPEND | LOCK_EX);
        $message = sprintf(__('Processed %d options: %d disabled, %d failed, %d already off.', 'autoload-optimizer'), count($options_to_disable), $success_count, $failure_count, $already_done);
        $message .= ' ' . sprintf(__('Log saved to %s.', 'autoload-optimizer'), '<code>/wp-content/uploads/autoload-options-debug.log</code>');
        wp_send_json_success(['message' => $message, 'disabled_options' => $processed_options]);
    }

    public function ajax_find_option_in_files() {
        check_ajax_referer('ao_find_source_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);
        }
        $option_name = isset($_POST['option_name']) ? sanitize_text_field(wp_unslash($_POST['option_name'])) : '';
        if (empty($option_name)) {
            wp_send_json_error(['message' => __('Option name cannot be empty.', 'autoload-optimizer')]);
        }
        if (preg_match('/^[a-zA-Z0-9_\-*]+$/', $option_name) !== 1) {
            wp_send_json_error(['message' => __('Invalid characters in option name.', 'autoload-optimizer')]);
        }
        $cache_key = 'ao_file_search_' . md5($option_name);
        $cached_results = get_transient($cache_key);
        if (false !== $cached_results) {
            wp_send_json_success(['html' => $cached_results]);
            return;
        }
        $results = [];
        $search_paths = [
            'Plugins' => WP_PLUGIN_DIR,
            'Themes'  => get_theme_root(),
        ];
        $start_time = microtime(true);
        $timeout = 90;
        foreach ($search_paths as $type => $path) {
            if (!is_dir($path) || !is_readable($path)) continue;
            $directory = new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
            $filtered_iterator = new class($directory) extends RecursiveFilterIterator {
                public function accept() {
                    $file = $this->current();
                    $basename = $file->getBasename();
                    return !in_array($basename, ['node_modules', 'vendor', '.git', 'dist', 'build', 'cache', '.svn']);
                }
            };
            $iterator  = new RecursiveIteratorIterator($filtered_iterator);
            $regex     = new RegexIterator($iterator, '/\.(php|js|inc)$/i');
            foreach ($regex as $file) {
                if (microtime(true) - $start_time > $timeout) {
                    break 2;
                }
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
        set_transient($cache_key, $html, HOUR_IN_SECONDS);
        wp_send_json_success(['html' => $html]);
    }

    public function ajax_send_telemetry() {
        check_ajax_referer('ao_telemetry_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'autoload-optimizer')]);
        }
        if (get_option('ao_telemetry_disabled') === '1') {
            wp_send_json_error(['message' => __('Telemetry is disabled.', 'autoload-optimizer')]);
        }
        
        // Use the analysis function to get the latest data, but DO NOT schedule telemetry again.
        $data = $this->get_analysis_data(false); 

        // Call the new builder method to get the payload
        $telemetry_data = $this->build_telemetry_payload($data['grouped_options'], $data['config']);
        
        // Add the flag to indicate this was a manual send
        $telemetry_data['manual_send'] = true;
        $this->send_telemetry_data($telemetry_data);
        wp_send_json_success(['message' => __('Telemetry data sent successfully. Thank you for helping improve the plugin!', 'autoload-optimizer')]);
    }

    public function admin_page_scripts() {
        $screen = get_current_screen();
        if (!$screen || 'tools_page_autoloaded-options' !== $screen->id) return;
        ?>
        <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function() {
            const wrapper = document.getElementById('ao-plugin-wrapper');
            const modalOverlay = document.getElementById('ao-option-modal-overlay');
            const modalContent = document.getElementById('ao-option-modal-content');
            const modalTitle = document.getElementById('ao-option-modal-title');
            const modalBody = document.getElementById('ao-modal-body');
            const resultsContainer = document.getElementById('ao-results-container');
            
            // --- MODIFICATION: Removed mainCheckbox and itemCheckboxes variables ---

            const ajaxurl = wrapper.dataset.ajaxUrl;
            const disableNonce = wrapper.dataset.disableNonce;
            const viewNonce = wrapper.dataset.viewNonce;
            const findNonce = wrapper.dataset.findNonce;
            
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
            
            // --- MODIFICATION: Removed 'disableSelectedBtn' event listener ---

            const disableSafeBtn = document.getElementById('ao-disable-safe-options');
            if (disableSafeBtn) {
                disableSafeBtn.addEventListener('click', e => {
                    e.preventDefault();
                    // --- MODIFICATION: Changed selector to get names from data attributes instead of checkboxes ---
                    const safeOptions = Array.from(document.querySelectorAll('tr[data-is-safe="true"]')).map(tr => tr.dataset.optionName);
                    if (safeOptions.length === 0) {
                        alert('<?php _e('No safe options were found in the table to disable.', 'autoload-optimizer'); ?>');
                        return;
                    }
                    disableOptions(safeOptions, e.target);
                });
            }
            
            // --- MODIFICATION: Removed 'mainCheckbox' event listener ---

            modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) hideModal(); });
            modalContent.querySelector('.close-modal').addEventListener('click', hideModal);

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

    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('tools.php?page=autoloaded-options')),
            __('Settings', 'autoload-optimizer')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    public function initialize_updater() {
        $parsedown_file = dirname(AO_PLUGIN_FILE) . '/lib/parsedown-1.7.4/Parsedown.php';
        $updater_bootstrap_file = dirname(AO_PLUGIN_FILE) . '/lib/plugin-update-checker/plugin-update-checker.php';

        // 1. First, check if the library's bootstrap file actually exists in your plugin.    // We check if the class already exists in case another plugin loaded it.
        if (file_exists($parsedown_file) && !class_exists('Parsedown')) {
            require_once $parsedown_file;
        }
        if (!file_exists($updater_bootstrap_file)) {
            return; // Exit if the library isn't there, preventing errors.
        }

        // 2. The SAFE LOADING CHECK: Before requiring your copy, check if the main Factory class
        // has already been loaded by another plugin.
        // IMPORTANT: Verify the exact namespace in your /lib/ folder. It could be v5, v5p4, v5p6, etc.
        if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            require_once $updater_bootstrap_file;
        }

        // 3. Now that we are SURE the class exists (either we loaded it or another plugin did),
        // we can safely build our update checker instance.
        try {
            $myUpdateChecker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/milllan/mill_autoload_options_checker_plugin/',
                AO_PLUGIN_FILE,
                'autoload-optimizer'
            );

            // (Optional but Recommended) Set the branch to 'main' or 'master'.
            // This ensures updates are pulled from the correct branch.
            $myUpdateChecker->setBranch('main');
        } catch (Exception $e) {
            error_log('PUC Initialization Error in Autoloaded Options Optimizer: ' . $e->getMessage());
        }
    }
}

Autoloaded_Options_Optimizer_Plugin::get_instance();