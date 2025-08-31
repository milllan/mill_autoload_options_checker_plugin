<?php
/**
 * Plugin Name: Autoloaded Options Checker
 * Description: Adds a tool to check and manage autoloaded options in the wp_options table.
 * Version: 1.2
 */

/**
 * Adds Autoloaded Options Checker under Tools menu
 */
function add_autoloaded_options_page() {
    add_management_page(
        'Autoloaded Options',
        'Autoloaded Options',
        'manage_options',
        'autoloaded-options',
        'display_autoloaded_options'
    );
}
add_action('admin_menu', 'add_autoloaded_options_page');

/**
 * Register AJAX handler for disabling autoload
 */
function register_autoload_ajax_handler() {
    add_action('wp_ajax_disable_autoload_options', 'disable_autoload_options_ajax');
    add_action('wp_ajax_disable_safe_autoload_options', 'disable_safe_autoload_options_ajax');
}
add_action('admin_init', 'register_autoload_ajax_handler');

/**
 * AJAX handler for disabling autoload options
 */
function disable_autoload_options_ajax() {
    // Verify nonce
    if (!check_ajax_referer('disable_autoload_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action'));
    }
    
    // Get selected options
    $options_to_disable = isset($_POST['options']) ? $_POST['options'] : array();
    
    if (empty($options_to_disable) || !is_array($options_to_disable)) {
        wp_send_json_error(array('message' => 'No options selected'));
    }
    
    // Log file path
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/autoload-options-debug.log';
    
    // Initialize log
    $log = "=== Autoload Options Debug Log - " . date('Y-m-d H:i:s') . " ===\n";
    $log .= "User: " . wp_get_current_user()->user_login . "\n";
    $log .= "Options to disable: " . implode(', ', $options_to_disable) . "\n";
    
    global $wpdb;
    $disabled_count = 0;
    $disabled_options = array();
    $failed_options = array();
    
    foreach ($options_to_disable as $option_name) {
        // Sanitize option name
        $option_name = sanitize_text_field($option_name);
        
        // Check if option exists
        $option = $wpdb->get_row($wpdb->prepare(
            "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ));
        
        if (!$option) {
            $log .= "Option not found: $option_name\n";
            $failed_options[] = $option_name;
            continue;
        }
        
        if ($option->autoload === 'no') {
            $log .= "Option already set to no autoload: $option_name\n";
            $disabled_options[] = $option_name;
            $disabled_count++;
            continue;
        }
        
        // Update autoload status
        $result = $wpdb->update(
            $wpdb->options,
            array('autoload' => 'no'),
            array('option_name' => $option_name),
            array('%s'),
            array('%s')
        );
        
        if ($result === false) {
            $log .= "Failed to update option: $option_name - Error: " . $wpdb->last_error . "\n";
            $failed_options[] = $option_name;
        } else {
            $log .= "Successfully updated option: $option_name\n";
            $disabled_options[] = $option_name;
            $disabled_count++;
        }
    }
    
    // Write to log file
    file_put_contents($log_file, $log, FILE_APPEND);
    
    // Prepare response
    $response = array(
        'success' => true,
        'disabled_count' => $disabled_count,
        'disabled_options' => $disabled_options,
        'failed_options' => $failed_options,
        'log_file' => $upload_dir['baseurl'] . '/autoload-options-debug.log'
    );
    
    wp_send_json_success($response);
}

/**
 * AJAX handler for disabling safe autoload options
 */
function disable_safe_autoload_options_ajax() {
    // Verify nonce
    if (!check_ajax_referer('disable_autoload_nonce', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have permission to perform this action'));
    }
    
    // Log file path
    $upload_dir = wp_upload_dir();
    $log_file = $upload_dir['basedir'] . '/autoload-options-debug.log';
    
    // Initialize log
    $log = "=== Safe Autoload Options Debug Log - " . date('Y-m-d H:i:s') . " ===\n";
    $log .= "User: " . wp_get_current_user()->user_login . "\n";
    $log .= "Disabling safe autoload options\n";
    
    global $wpdb;
    
    // Safe options to disable (literal matches)
    $safe_options_literal = array(
        '_transient_wpassetcleanup_assets_info',
        '_transient_wc_attribute_taxonomies',
        '_transient_dirsize_cache',
        'asp_updates',
        'Avada_backups',
        'aviaAsset_css_filecontent',
        'aviaAsset_js_filecontent',
        'apmm_font_family',
        'blc_installation_log',
        'br_get_taxonomy_hierarchy_product_cat',
        'brainstrom_bundled_products',
        'ced_etsy_chunk_products',                    
        'channel_statics',
        'cherry_customiser_fonts_google',
        'count_per_day_search',
        'cron_projects',
        'cp_modal_preset_templates',
        'dd_normal_button',
        'dd_float_button',
        'essential-grid_update_info',
        'et_divi',
        'fusion_options',
        'fusion_options_500_backup',
        'fs_accounts',
        'fbrfg_favicon_non_interactive_api_request',
        'itsec-storage',
        'ig_last_gallery_items',
        'IWP_backup_history',
        'jetpack_file_data',
        'jetpack_static_asset_cdn_files',
        'jetpack_plugin_api_action_links',
        'jet_menu_options',
        'jetpack_active_plan',
        'joc_plugins',
        'gform_version_info',
        'fs_api_cache',
        'fs_accounts',
        'limit_login_logged',
        'mepr_options',
        'monsterinsights_report_data_overview',
        'mwp_public_keys',
        'mw_adminimize',
        'nss_plugin_info_sfwd_lms',
        'nm_theme_custom_styles',
        'nm_instant_suggestions_product_data',
        'thrive_tcb_download_lp',
        'top_opt_already_tweeted_posts',
        'page_scroll_to_id_instances',
        'permalink-manager-external-redirects',
        'permalink-manager-redirects',
        'permalink-manager-uris',
        'personify_active_campaign_tags',
        'redux_builder_amp',
        'revslider-addons',
        'td_011',
        'td_011_log',
        'td_011_settings',
        'tve_leads_saved_tpl_meta', 
        'otgs-installer-log',
        'otgs_active_components',
        'personify_active_campaign_fields',
        'ping_sites',
        'rank_math_seo_analysis_results',
        'uninstall_plugins',
        'um_cache_fonticons',
        'uncode',
        'widget_custom_html',
        'wd_audit_cached',
        'wc_psad_google_font_list',
        'wc_facebook_google_product_categories',
        'wc_remote_inbox_notifications_specs',
        'wp_installer_settings',
        'wp_schema_pro_optimized_structured_data',
        'ws_menu_editor_pro',
        'wcml_trbl_products_needs_fix_postmeta',
        'woocommerce_ultimate_tabs_options',
        'woocommerce_tracker_ua',
        'woolementor-docs-json',
        'wp_custom_admin_interface_settings_AdminMenu',
        'wpf_template',
        'wpassetcleanup_global_data',
        'wphb_scripts_collection',
        'wphb_styles_collection',
        'wpml_strings_need_links_fixed',
        'wpseo_taxonomy_meta',
        'wpseo-premium-redirects-export-plain',
        'yikes_woo_reusable_products_tabs_applied',
        'zero-spam-settings',
    );
    
    // Safe options to disable (pattern matches)
    $safe_options_patterns = array(
        '_transient_amp%',
        '_transient_bawmrp%',
        '_transient_fusion%',
        '_transient_google%',
        '_wpallexport_session_%',
        '%mytheme%',
        'ai1wm%',
        'astra-sites-and-pages%',
        'astra-blocks%',
        'bwp_minify_detector%',
        'cherry_customiser%',
        'ctf%',
        'css3_vertical_table_shortcode%',
        'duplicator%',
        'edd_api_request_%',
        'ced_etsy_product_logs_%',
        'ced_etsy_product_inventory_logs_%',
        'ced_etsy_chunk_products_%',
        'ced_etsy_order_logs_%',
        'elementor_pro_remote%',
        'fs_contact_form%',
        'Jupiter_options%',
        'fusion_dynamic%',
        'giapi%',
        'nf_form_%',
        'mailserver%',
        'qode_options%',
        'quickppr_redirects%',
        'tinypng%',
        'trustpilot%',
        'of_template%',
        'premiothemes_comingsoon_%',
        'p3%',
        'userpro%',
        'updraft%',
        'ywsn%',
        'ywccp%',
        'wc_csv_import_suite_background_import_results_%',
        'wfacp_c_%',
        'wp-optimize%',
        'wfcm%',
        'wpseo-gsc-%',
    );
    
    $disabled_count = 0;
    $disabled_options = array();
    
    // Process literal matches
    foreach ($safe_options_literal as $option_name) {
        $result = $wpdb->update(
            $wpdb->options,
            array('autoload' => 'no'),
            array('option_name' => $option_name, 'autoload' => 'yes'),
            array('%s'),
            array('%s', '%s')
        );
        
        if ($result !== false) {
            $disabled_count++;
            $disabled_options[] = $option_name;
            $log .= "Disabled autoload for literal match: $option_name\n";
        }
    }
    
    // Process pattern matches
    foreach ($safe_options_patterns as $pattern) {
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET autoload = 'no' WHERE autoload = 'yes' AND option_name LIKE %s",
            $pattern
        ));
        
        if ($result !== false) {
            $disabled_count += $result;
            $log .= "Disabled autoload for $result options matching pattern: $pattern\n";
            
            // Get the actual option names for logging
            $matched_options = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE autoload = 'no' AND option_name LIKE %s",
                $pattern
            ));
            
            foreach ($matched_options as $option) {
                if (!in_array($option, $disabled_options)) {
                    $disabled_options[] = $option;
                }
            }
        }
    }
    
    // Write to log file
    file_put_contents($log_file, $log, FILE_APPEND);
    
    // Prepare response
    $response = array(
        'success' => true,
        'disabled_count' => $disabled_count,
        'disabled_options' => array_slice($disabled_options, 0, 50), // Limit to first 50 for display
        'total_options' => count($disabled_options),
        'log_file' => $upload_dir['baseurl'] . '/autoload-options-debug.log'
    );
    
    wp_send_json_success($response);
}

/**
 * Displays the autoloaded options page with plugin grouping in table format
 */
function display_autoloaded_options() {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        return;
    }
    
    echo '<div class="wrap">';
    echo '<h1>Autoloaded Options Analysis</h1>';
    
    // Get all autoloaded options
    $options = $wpdb->get_results(
        "SELECT option_name, LENGTH(option_value) AS option_length 
        FROM {$wpdb->options} 
        WHERE autoload = 'yes' 
        ORDER BY option_length DESC"
    );
    
    if (empty($options)) {
        echo '<p>No autoloaded options found.</p>';
        echo '</div>';
        return;
    }
    
    // Get active plugins with full paths
    $active_plugins = get_option('active_plugins');
    $active_plugin_paths = $active_plugins;
    
    // Create mapping of options to plugins with exact file paths
    $option_plugin_mapping = array(
        // Mapping for Starbox
        'abh_options' => array('name' => 'Starbox - the Author Box for Humans', 'file' => 'starbox/starbox.php'),
        // Original mappings
        'wp_installer_settings' => array('name' => 'WPML', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        'wpml_strings_need_links_fixed' => array('name' => 'WPML', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        'duplicator_pro_package_active' => array('name' => 'Duplicator Pro', 'file' => 'duplicator-pro/duplicator-pro.php'),
        'rewrite_rules' => array('name' => 'WordPress Core', 'file' => 'core'),
        'wpassetcleanup_global_data' => array('name' => 'WP Asset Cleanup', 'file' => 'wp-asset-cleanup/wp-asset-cleanup.php'),
        'icl_sitepress_settings' => array('name' => 'WPML', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        'ad_inserter' => array('name' => 'Ad Inserter', 'file' => 'ad-inserter/ad-inserter.php'),
        'wpcr3_options' => array('name' => 'WP Customer Reviews', 'file' => 'wp-customer-reviews/wp-customer-reviews.php'),
        'cptui_post_types' => array('name' => 'Custom Post Type UI', 'file' => 'custom-post-type-ui/custom-post-type-ui.php'),
        'wpseo_titles' => array('name' => 'Yoast SEO', 'file' => 'wordpress-seo/wp-seo.php'),
        'otgs-installer-log' => array('name' => 'WPML', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        'wp_user_roles' => array('name' => 'WordPress Core', 'file' => 'core'),
        'aioseop_options' => array('name' => 'All in One SEO', 'file' => 'all-in-one-seo-pack/all_in_one_seo_pack.php'),
        'wpcf-custom-taxonomies' => array('name' => 'Toolset Types', 'file' => 'types/types.php'),
        'cpt_custom_post_types' => array('name' => 'Custom Post Types', 'file' => 'custom-post-types/custom-post-types.php'),
        'cptui_taxonomies' => array('name' => 'Custom Post Type UI', 'file' => 'custom-post-type-ui/custom-post-type-ui.php'),
        'code_snippets_settings' => array('name' => 'Code Snippets', 'file' => 'code-snippets/code-snippets.php'),
        'wpcode_snippets' => array('name' => 'WPCode - Insert Headers & Footers', 'file' => 'insert-headers-and-footers/wpcode.php'),
        
        'rank_math_analytics_all_services' => array('name' => 'Rank Math', 'file' => 'seo-by-rank-math/rank-math.php'),
        'wpml_language_switcher' => array('name' => 'WPML', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        'tve_default_styles' => array('name' => 'Thrive Themes', 'file' => 'thrive-visual-editor/thrive-visual-editor.php'),
        'otgs_active_components' => array('name' => 'WPML', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        'brainstrom_products' => array('name' => 'Brainstorm Force', 'file' => 'brainstorm-force/brainstorm-force.php'),
        'adsensexplosion' => array('name' => 'AdSense Explosion', 'file' => 'adsensexplosion/adsensexplosion.php'),
        'ATE_RETURNED_JOBS_QUEUE' => array('name' => 'WPML', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        
        // New mappings for the second website
        'aaa_option_optimizer_settings' => array('name' => 'AAA Option Optimizer', 'file' => 'aaa-option-optimizer/aaa-option-optimizer.php'),
        'al_plugin_options' => array('name' => 'Activity Log', 'file' => 'aryo-activity-log/aryo-activity-log.php'),
        'iaff_options' => array('name' => 'Auto Image Attributes From Filename', 'file' => 'auto-image-attributes-from-filename-with-bulk-updater/iaff_image-attributes-from-filename.php'),
        'aiap_settings' => array('name' => 'Auto Image Attributes Pro', 'file' => 'auto-image-attributes-pro/auto-image-attributes-pro.php'),
        'bsr_options' => array('name' => 'Better Search Replace', 'file' => 'better-search-replace/better-search-replace.php'),
        'brb_options' => array('name' => 'Business Reviews Bundle', 'file' => 'business-reviews-bundle/brb.php'),
        'cloudflare_api_email' => array('name' => 'Cloudflare', 'file' => 'cloudflare/cloudflare.php'),
        'code_snippets_settings' => array('name' => 'Code Snippets', 'file' => 'code-snippets/code-snippets.php'),
        'complianz_options' => array('name' => 'Complianz Privacy Suite', 'file' => 'complianz-gdpr-premium/complianz-gpdr-premium.php'),
        'elementor_active_kit' => array('name' => 'Elementor', 'file' => 'elementor/elementor.php'),
        'elementor_pro_license_key' => array('name' => 'Elementor Pro', 'file' => 'elementor-pro/elementor-pro.php'),
        'fma_settings' => array('name' => 'File Manager Advanced', 'file' => 'file-manager-advanced/file_manager_advanced.php'),
        'gtm_server_side_settings' => array('name' => 'GTM Server Side', 'file' => 'gtm-server-side/gtm-server-side.php'),
        'imagify_settings' => array('name' => 'Imagify', 'file' => 'imagify/imagify.php'),
        'instant_indexing_settings' => array('name' => 'Instant Indexing', 'file' => 'fast-indexing-api/instant-indexing.php'),
        'link_whisper_settings' => array('name' => 'Link Whisper', 'file' => 'link-whisper-premium/link-whisper.php'),
        'wp_media_cleaner' => array('name' => 'Media Cleaner', 'file' => 'media-cleaner/media-cleaner.php'),
        'salc_options' => array('name' => 'Simple Admin Language Change', 'file' => 'simple-admin-language-change/simple-admin-language-change.php'),
        'bodhi_svg_options' => array('name' => 'SVG Support', 'file' => 'svg-support/svg-support.php'),
        'tlw_options' => array('name' => 'Temporary Login Without Password', 'file' => 'temporary-login-without-password/temporary-login-without-password.php'),
        'updraft_options' => array('name' => 'UpdraftPlus', 'file' => 'updraftplus/updraftplus.php'),
        'grw_options' => array('name' => 'Widgets for Google Reviews', 'file' => 'widget-google-reviews/grw.php'),
        'wp_rocket_settings' => array('name' => 'WP Rocket', 'file' => 'wp-rocket/wp-rocket.php'),
        'wp_rocket_d_bugger_settings' => array('name' => 'WP Rocket D-bugger', 'file' => 'wp-rocket-debugger/wp-rocket-d-bugger.php'),
        'wp_rocket_disable_used_css_font_preload' => array('name' => 'WP Rocket Disable Used CSS Fonts Preload', 'file' => 'wp-rocket-disable-used-css-font-preload/wp-rocket-disable-used-css-font-preload.php'),
        'wp_rocket_cache_ignore_query_strings' => array('name' => 'WP Rocket Ignore Query Strings', 'file' => 'wp-rocket-cache-ignore-query-strings/wp-rocket-cache-ignore-query-strings.php'),
        'wpml_cms_nav_settings' => array('name' => 'WPML CMS Navigation', 'file' => 'wpml-cms-nav/plugin.php'),
        'wpml_media_settings' => array('name' => 'WPML Media Translation', 'file' => 'wpml-media-translation/plugin.php'),
        'sitepress_settings' => array('name' => 'WPML Multilingual CMS', 'file' => 'sitepress-multilingual-cms/sitepress.php'),
        'wpmlseo_options' => array('name' => 'WPML SEO', 'file' => 'wp-seo-multilingual/plugin.php'),
        'wpml_sticky_links_options' => array('name' => 'WPML Sticky Links', 'file' => 'wpml-sticky-links/plugin.php'),
        'wpml_st_options' => array('name' => 'WPML String Translation', 'file' => 'wpml-string-translation/plugin.php'),
        'wptd_image_compare_options' => array('name' => 'WPTD Image Compare', 'file' => 'wptd-image-compare/wptd-image-compare.php'),
        'wpseo' => array('name' => 'Yoast SEO', 'file' => 'wordpress-seo/wp-seo.php'),
        'wpseo_premium' => array('name' => 'Yoast SEO Premium', 'file' => 'wordpress-seo-premium/wp-seo-premium.php'),
        'youlo_price_calculator_settings' => array('name' => 'YOULO Price Calculator', 'file' => 'youlo-price-calculator/youlo-price-calculator.php'),
        
        // Inactive plugins
        'ai_engine_settings' => array('name' => 'AI Engine', 'file' => 'ai-engine/ai-engine.php'),
        'disable_elementor_editor_translation' => array('name' => 'Disable Elementor Editor Translation', 'file' => 'disable-elementor-editor-translation/disable-elementor-editor-translation.php'),
        'enable_media_replace' => array('name' => 'Enable Media Replace', 'file' => 'enable-media-replace/enable-media-replace.php'),
        'imagify_tools_settings' => array('name' => 'Imagify Tools', 'file' => 'imagify-tools/imagify-tools.php'),
        'mfrh_settings' => array('name' => 'Media File Renamer Pro', 'file' => 'media-file-renamer-pro/media-file-renamer-pro.php'),
        'mla_options' => array('name' => 'Media Library Assistant', 'file' => 'media-library-assistant/index.php'),
        'mystickymenu_options' => array('name' => 'My Sticky Bar Pro', 'file' => 'mystickymenu-pro/mystickymenu.php'),
        'query_monitor' => array('name' => 'Query Monitor', 'file' => 'query-monitor/query-monitor.php'),
        'wpcf-types' => array('name' => 'Toolset Types', 'file' => 'types/wpcf.php'),
        'wpmudev_updates' => array('name' => 'WPMU DEV Dashboard', 'file' => 'wpmudev-updates/update-notifications.php'),
        'wps_hide_login' => array('name' => 'WPS Hide Login', 'file' => 'wps-hide-login/wps-hide-login.php'),
        'yoast_test_helper' => array('name' => 'Yoast Test Helper', 'file' => 'yoast-test-helper/yoast-test-helper.php'),
        
        // Additional mapping for Limit Login Attempts Reloaded
        'limit_login_logged' => array('name' => 'Limit Login Attempts Reloaded', 'file' => 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php'),
        
        // Mapping for Aelia Currency Switcher
        'fs_accounts' => array('name' => 'Aelia Currency Switcher', 'file' => 'woocommerce-aelia-currencyswitcher/woocommerce-aelia-currencyswitcher.php'),
    );
    
    // Safe options to disable (literal matches)
    $safe_options_literal = array(
        '_transient_wpassetcleanup_assets_info',
        '_transient_wc_attribute_taxonomies',
        '_transient_dirsize_cache',
        'asp_updates',
        'Avada_backups',
        'aviaAsset_css_filecontent',
        'aviaAsset_js_filecontent',
        'apmm_font_family',
        'blc_installation_log',
        'br_get_taxonomy_hierarchy_product_cat',
        'brainstrom_bundled_products',
        'ced_etsy_chunk_products',                    
        'channel_statics',
        'cherry_customiser_fonts_google',
        'count_per_day_search',
        'cron_projects',
        'cp_modal_preset_templates',
        'dd_normal_button',
        'dd_float_button',
        'essential-grid_update_info',
        'et_divi',
        'fusion_options',
        'fusion_options_500_backup',
        'fs_accounts',
        'fbrfg_favicon_non_interactive_api_request',
        'itsec-storage',
        'ig_last_gallery_items',
        'IWP_backup_history',
        'jetpack_file_data',
        'jetpack_static_asset_cdn_files',
        'jetpack_plugin_api_action_links',
        'jet_menu_options',
        'jetpack_active_plan',
        'joc_plugins',
        'gform_version_info',
        'fs_api_cache',
        'fs_accounts',
        'limit_login_logged',
        'mepr_options',
        'monsterinsights_report_data_overview',
        'mwp_public_keys',
        'mw_adminimize',
        'nss_plugin_info_sfwd_lms',
        'nm_theme_custom_styles',
        'nm_instant_suggestions_product_data',
        'thrive_tcb_download_lp',
        'top_opt_already_tweeted_posts',
        'page_scroll_to_id_instances',
        'permalink-manager-external-redirects',
        'permalink-manager-redirects',
        'permalink-manager-uris',
        'personify_active_campaign_tags',
        'redux_builder_amp',
        'revslider-addons',
        'td_011',
        'td_011_log',
        'td_011_settings',
        'tve_leads_saved_tpl_meta', 
        'otgs-installer-log',
        'otgs_active_components',
        'personify_active_campaign_fields',
        'ping_sites',
        'rank_math_seo_analysis_results',
        'uninstall_plugins',
        'um_cache_fonticons',
        'uncode',
        'widget_custom_html',
        'wd_audit_cached',
        'wc_psad_google_font_list',
        'wc_facebook_google_product_categories',
        'wc_remote_inbox_notifications_specs',
        'wp_installer_settings',
        'wp_schema_pro_optimized_structured_data',
        'ws_menu_editor_pro',
        'wcml_trbl_products_needs_fix_postmeta',
        'woocommerce_ultimate_tabs_options',
        'woocommerce_tracker_ua',
        'woolementor-docs-json',
        'wp_custom_admin_interface_settings_AdminMenu',
        'wpf_template',
        'wpassetcleanup_global_data',
        'wphb_scripts_collection',
        'wphb_styles_collection',
        'wpml_strings_need_links_fixed',
        'wpseo_taxonomy_meta',
        'wpseo-premium-redirects-export-plain',
        'yikes_woo_reusable_products_tabs_applied',
        'zero-spam-settings',
    );
    
    // Safe options to disable (pattern matches)
    $safe_options_patterns = array(
        '_transient_amp%',
        '_transient_bawmrp%',
        '_transient_fusion%',
        '_transient_google%',
        '_wpallexport_session_%',
        '%mytheme%',
        'ai1wm%',
        'astra-sites-and-pages%',
        'astra-blocks%',
        'bwp_minify_detector%',
        'cherry_customiser%',
        'ctf%',
        'css3_vertical_table_shortcode%',
        'duplicator%',
        'edd_api_request_%',
        'ced_etsy_product_logs_%',
        'ced_etsy_product_inventory_logs_%',
        'ced_etsy_chunk_products_%',
        'ced_etsy_order_logs_%',
        'elementor_pro_remote%',
        'fs_contact_form%',
        'Jupiter_options%',
        'fusion_dynamic%',
        'giapi%',
        'nf_form_%',
        'mailserver%',
        'qode_options%',
        'quickppr_redirects%',
        'tinypng%',
        'trustpilot%',
        'of_template%',
        'premiothemes_comingsoon_%',
        'p3%',
        'userpro%',
        'updraft%',
        'ywsn%',
        'ywccp%',
        'wc_csv_import_suite_background_import_results_%',
        'wfacp_c_%',
        'wp-optimize%',
        'wfcm%',
        'wpseo-gsc-%',
    );
    
    // Group options by plugin
    $grouped_options = array();
    $total_size = 0;
    
    foreach ($options as $option) {
        $total_size += $option->option_length;
        
        // Skip options smaller than 1KB
        if ($option->option_length < 1024) continue;
        
        // Determine plugin
        $plugin_name = 'Unknown';
        $plugin_file = '';
        $status = 'Unknown';
        $status_class = '';
        $is_safe = false;
        
        // Check if option is in safe list
        if (in_array($option->option_name, $safe_options_literal)) {
            $is_safe = true;
        } else {
            foreach ($safe_options_patterns as $pattern) {
                if (fnmatch($pattern, $option->option_name)) {
                    $is_safe = true;
                    break;
                }
            }
        }
        
        // Try to find the plugin in our mapping
        if (isset($option_plugin_mapping[$option->option_name])) {
            $plugin_name = $option_plugin_mapping[$option->option_name]['name'];
            $plugin_file = $option_plugin_mapping[$option->option_name]['file'];
            
            if ($plugin_file === 'core') {
                $status = 'WordPress Core';
                $status_class = 'notice-info';
            } elseif (in_array($plugin_file, $active_plugin_paths)) {
                $status = 'Active Plugin';
                $status_class = 'notice-success';
            } else {
                $status = 'Inactive Plugin';
                $status_class = 'notice-error';
            }
        } else {
            // Try to guess the plugin based on the option name
            if (strpos($option->option_name, 'elementor') !== false) {
                $plugin_name = 'Elementor';
                $plugin_file = 'elementor/elementor.php';
                $status = in_array($plugin_file, $active_plugin_paths) ? 'Active Plugin' : 'Inactive Plugin';
                $status_class = in_array($plugin_file, $active_plugin_paths) ? 'notice-success' : 'notice-error';
            } elseif (strpos($option->option_name, 'wpml') !== false) {
                $plugin_name = 'WPML';
                $plugin_file = 'sitepress-multilingual-cms/sitepress.php';
                $status = in_array($plugin_file, $active_plugin_paths) ? 'Active Plugin' : 'Inactive Plugin';
                $status_class = in_array($plugin_file, $active_plugin_paths) ? 'notice-success' : 'notice-error';
            } elseif (strpos($option->option_name, 'wpseo') !== false) {
                $plugin_name = 'Yoast SEO';
                $plugin_file = 'wordpress-seo/wp-seo.php';
                $status = in_array($plugin_file, $active_plugin_paths) ? 'Active Plugin' : 'Inactive Plugin';
                $status_class = in_array($plugin_file, $active_plugin_paths) ? 'notice-success' : 'notice-error';
            } elseif (strpos($option->option_name, 'rocket') !== false) {
                $plugin_name = 'WP Rocket';
                $plugin_file = 'wp-rocket/wp-rocket.php';
                $status = in_array($plugin_file, $active_plugin_paths) ? 'Active Plugin' : 'Inactive Plugin';
                $status_class = in_array($plugin_file, $active_plugin_paths) ? 'notice-success' : 'notice-error';
            } elseif (strpos($option->option_name, 'imagify') !== false) {
                $plugin_name = 'Imagify';
                $plugin_file = 'imagify/imagify.php';
                $status = in_array($plugin_file, $active_plugin_paths) ? 'Active Plugin' : 'Inactive Plugin';
                $status_class = in_array($plugin_file, $active_plugin_paths) ? 'notice-success' : 'notice-error';
            } elseif (strpos($option->option_name, 'limit_login') !== false) {
                $plugin_name = 'Limit Login Attempts Reloaded';
                $plugin_file = 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php';
                $status = in_array($plugin_file, $active_plugin_paths) ? 'Active Plugin' : 'Inactive Plugin';
                $status_class = in_array($plugin_file, $active_plugin_paths) ? 'notice-success' : 'notice-error';
            } elseif (strpos($option->option_name, 'fs_accounts') !== false) {
                $plugin_name = 'Aelia Currency Switcher';
                $plugin_file = 'woocommerce-aelia-currencyswitcher/woocommerce-aelia-currencyswitcher.php';
                $status = in_array($plugin_file, $active_plugin_paths) ? 'Active Plugin' : 'Inactive Plugin';
                $status_class = in_array($plugin_file, $active_plugin_paths) ? 'notice-success' : 'notice-error';
            } elseif (strpos($option->option_name, 'transient') !== false || strpos($option->option_name, 'cron') !== false) {
                $plugin_name = 'WordPress Core';
                $plugin_file = 'core';
                $status = 'WordPress Core';
                $status_class = 'notice-info';
            }
        }
        
        // Add to group
        if (!isset($grouped_options[$plugin_name])) {
            $grouped_options[$plugin_name] = array(
                'name' => $plugin_name,
                'file' => $plugin_file,
                'status' => $status,
                'status_class' => $status_class,
                'total_size' => 0,
                'count' => 0,
                'options' => array()
            );
        }
        
        $grouped_options[$plugin_name]['total_size'] += $option->option_length;
        $grouped_options[$plugin_name]['count']++;
        
        // Add option with safe flag
        $option_array = (array)$option;
        $option_array['is_safe'] = $is_safe;
        $grouped_options[$plugin_name]['options'][] = (object)$option_array;
    }
    
    // Sort groups by total size (descending)
    uasort($grouped_options, function($a, $b) {
        return $b['total_size'] - $a['total_size'];
    });
    
    // Display summary
    echo '<div class="notice notice-info">';
    echo '<p><strong>Total autoloaded options:</strong> ' . count($options) . ' | <strong>Total size:</strong> ' . size_format($total_size) . '</p>';
    echo '</div>';
    
    // Add safe options button
    echo '<div class="notice notice-warning">';
    echo '<p><strong>Safe to Disable:</strong> There are many options that are generally safe to disable autoload for. These are typically cache data, logs, or other non-critical data.</p>';
    echo '<p>';
    echo '<button id="disable-safe-options" class="button button-primary">Disable All Safe Options</button> ';
    echo '<span id="safe-loading-indicator" style="display:none;">Processing... <img src="' . admin_url('images/loading.gif') . '" alt="Loading..." /></span>';
    echo '</p>';
    echo '</div>';
    
    // Display table with plugin grouping
    echo '<h2>Large Autoloaded Options (>1KB) by Plugin</h2>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>Option Name</th>';
    echo '<th>Size</th>';
    echo '<th>Percentage</th>';
    echo '<th>Plugin</th>';
    echo '<th>Status</th>';
    echo '<th>Action</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $inactive_plugin_options = array();
    $safe_plugin_options = array();
    $current_plugin = '';
    
    foreach ($grouped_options as $plugin_name => $plugin_data) {
        // Add plugin header row
        echo '<tr class="plugin-header">';
        echo '<td colspan="6"><strong>' . esc_html($plugin_name) . '</strong> - ';
        echo size_format($plugin_data['total_size']) . ' total, ';
        echo $plugin_data['count'] . ' options, ';
        echo number_format(($plugin_data['total_size'] / $total_size) * 100, 2) . '% of total</td>';
        echo '</tr>';
        
        // Add individual options for this plugin
        foreach ($plugin_data['options'] as $option) {
            $percentage = ($option->option_length / $total_size) * 100;
            
            // Determine row class based on safety
            $row_class = '';
            if ($option->is_safe) {
                $row_class = ' class="safe-option"';
                $safe_plugin_options[] = $option->option_name;
            }
            
            echo '<tr' . $row_class . '>';
            echo '<td><strong>' . esc_html($option->option_name) . '</strong>';
            if ($option->is_safe) {
                echo ' <span class="dashicons dashicons-yes" style="color:#46b450;" title="Safe to disable"></span>';
            }
            echo '</td>';
            echo '<td>' . size_format($option->option_length) . '</td>';
            echo '<td>' . number_format($percentage, 2) . '%</td>';
            echo '<td>' . esc_html($plugin_name) . '</td>';
            echo '<td><span class="notice ' . esc_attr($plugin_data['status_class']) . '">' . esc_html($plugin_data['status']) . '</span></td>';
            echo '<td>';
            
            if ($plugin_data['status'] === 'Inactive Plugin' || $option->is_safe) {
                if ($plugin_data['status'] === 'Inactive Plugin') {
                    $inactive_plugin_options[] = $option->option_name;
                }
                echo '<input type="checkbox" class="option-checkbox" value="' . esc_attr($option->option_name) . '" /> ';
                echo '<button class="button disable-single" data-option="' . esc_attr($option->option_name) . '">Disable Autoload</button>';
            } else {
                echo 'N/A';
            }
            
            echo '</td>';
            echo '</tr>';
        }
    }
    
    echo '</tbody>';
    echo '</table>';
    
    // Add CSS for safe options
    echo '<style>
    .safe-option {
        background-color: #f6f7f7;
    }
    .safe-option td:first-child {
        font-weight: bold;
    }
    </style>';
    
    // Display large options summary
    echo '<div class="notice notice-warning">';
    echo '<p><strong>Large options summary:</strong> ' . count($options) . ' options (' . size_format($total_size) . ') account for 100% of all autoloaded data</p>';
    echo '</div>';
    
    // Display form submission button if there are inactive plugin options
    if (!empty($inactive_plugin_options)) {
        echo '<div class="notice notice-warning">';
        echo '<p><strong>Found ' . count($inactive_plugin_options) . ' options from inactive plugins.</strong> Disabling autoload for these options can improve performance.</p>';
        echo '<p>';
        echo '<button id="disable-selected" class="button button-primary">Disable Autoload for Selected Options</button> ';
        echo '<span id="loading-indicator" style="display:none;">Processing... <img src="' . admin_url('images/loading.gif') . '" alt="Loading..." /></span>';
        echo '</p>';
        echo '</div>';
    }
    
    // Add a manual query section for direct database access
    echo '<div class="card">';
    echo '<h2>Manual Database Query</h2>';
    echo '<p>If the automatic method doesn\'t work, you can manually run these SQL queries to disable autoload for specific options:</p>';
    echo '<textarea readonly style="width:100%; height:100px; font-family:monospace;">';
    
    foreach ($inactive_plugin_options as $option) {
        echo "UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name = '" . esc_sql($option) . "';\n";
    }
    
    echo '</textarea>';
    echo '<p class="description">You can run these queries using phpMyAdmin or a database management tool.</p>';
    echo '</div>';
    
    // Display recommendations
    echo '<div class="card">';
    echo '<h2>Recommendations</h2>';
    echo '<ol>';
    echo '<li><strong>WPML (Total: ~232 KB):</strong> This is the largest contributor to autoloaded data. Since WPML is active, do not disable these options.</li>';
    echo '<li><strong>Aelia Currency Switcher (fs_accounts):</strong> This is a large option that is marked as safe to disable. Disabling autoload for this option can significantly improve performance.</li>';
    echo '<li><strong>Duplicator Pro (Total: ~31 KB):</strong> If you no longer use this plugin, disable autoload for its options.</li>';
    echo '<li><strong>WordPress Core (Total: ~38 KB):</strong> Core options should not be disabled.</li>';
    echo '<li><strong>WP Asset Cleanup (Total: ~29 KB):</strong> If you no longer use this plugin, disable autoload for its options.</li>';
    echo '<li><strong>Ad Inserter (Total: ~20 KB):</strong> If you no longer use this plugin, disable autoload for its options.</li>';
    echo '<li><strong>WP Customer Reviews (Total: ~19 KB):</strong> If you no longer use this plugin, disable autoload for its options.</li>';
    echo '<li><strong>Custom Post Type UI (Total: ~17 KB):</strong> If you no longer use this plugin, disable autoload for its options.</li>';
    echo '<li><strong>Yoast SEO (Total: ~10 KB):</strong> If you no longer use this plugin, disable autoload for its options.</li>';
    echo '<li><strong>Limit Login Attempts Reloaded:</strong> Security-related options should generally remain autoloaded for optimal protection.</li>';
    echo '<li><strong>Safe Options:</strong> Options marked with a green checkmark are generally safe to disable. These are typically cache data, logs, or other non-critical data.</li>';
    echo '</ol>';
    echo '</div>';
    
    echo '<div class="notice notice-error">';
    echo '<p><strong>Warning:</strong> Only disable autoload for options that belong to inactive plugins or are marked as safe. Disabling autoload for active plugins or core options may break your site. Always backup your database before making changes.</p>';
    echo '</div>';
    
    // Add results container
    echo '<div id="results-container" style="display:none;"></div>';
    
    echo '</div>';
    
    // Add JavaScript for AJAX functionality
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Function to disable autoload for selected options
        $('#disable-selected').on('click', function() {
            var selectedOptions = [];
            $('.option-checkbox:checked').each(function() {
                selectedOptions.push($(this).val());
            });
            
            if (selectedOptions.length === 0) {
                alert('Please select at least one option to disable.');
                return;
            }
            
            disableAutoloadOptions(selectedOptions);
        });
        
        // Function to disable autoload for safe options
        $('#disable-safe-options').on('click', function() {
            if (!confirm('Are you sure you want to disable autoload for all safe options? This action cannot be undone.')) {
                return;
            }
            
            // Show loading indicator
            $('#safe-loading-indicator').show();
            $('#disable-safe-options').prop('disabled', true);
            
            // Prepare data
            var data = {
                action: 'disable_safe_autoload_options',
                nonce: '<?php echo wp_create_nonce('disable_autoload_nonce'); ?>'
            };
            
            // Send AJAX request
            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    // Display results
                    var resultsHtml = '<div class="notice notice-success">';
                    resultsHtml += '<p>Successfully disabled autoload for ' + response.data.disabled_count + ' safe options.</p>';
                    
                    if (response.data.total_options > 50) {
                        resultsHtml += '<p>Showing first 50 options of ' + response.data.total_options + ' total options:</p>';
                    }
                    
                    resultsHtml += '<ul>';
                    $.each(response.data.disabled_options, function(index, option) {
                        resultsHtml += '<li>' + option + '</li>';
                    });
                    resultsHtml += '</ul>';
                    
                    resultsHtml += '<p>Debug log: <a href="' + response.data.log_file + '" target="_blank">View Log File</a></p>';
                    resultsHtml += '</div>';
                    
                    $('#results-container').html(resultsHtml).show();
                    
                    // Reload page after 3 seconds to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    // Display error
                    $('#results-container').html('<div class="notice notice-error"><p>Error: ' + response.data.message + '</p></div>').show();
                }
                
                // Hide loading indicator
                $('#safe-loading-indicator').hide();
                $('#disable-safe-options').prop('disabled', false);
            }).fail(function(xhr, status, error) {
                // Display error
                $('#results-container').html('<div class="notice notice-error"><p>AJAX request failed: ' + error + '</p></div>').show();
                
                // Hide loading indicator
                $('#safe-loading-indicator').hide();
                $('#disable-safe-options').prop('disabled', false);
            });
        });
        
        // Function to disable autoload for a single option
        $('.disable-single').on('click', function() {
            var optionName = $(this).data('option');
            disableAutoloadOptions([optionName]);
        });
        
        // Function to handle AJAX request
        function disableAutoloadOptions(options) {
            // Show loading indicator
            $('#loading-indicator').show();
            $('#disable-selected').prop('disabled', true);
            
            // Prepare data
            var data = {
                action: 'disable_autoload_options',
                nonce: '<?php echo wp_create_nonce('disable_autoload_nonce'); ?>',
                options: options
            };
            
            // Send AJAX request
            $.post(ajaxurl, data, function(response) {
                if (response.success) {
                    // Display results
                    var resultsHtml = '<div class="notice notice-success">';
                    resultsHtml += '<p>Successfully disabled autoload for ' + response.data.disabled_count + ' options:</p>';
                    resultsHtml += '<ul>';
                    $.each(response.data.disabled_options, function(index, option) {
                        resultsHtml += '<li>' + option + '</li>';
                    });
                    resultsHtml += '</ul>';
                    
                    if (response.data.failed_options.length > 0) {
                        resultsHtml += '<p>Failed to disable autoload for ' + response.data.failed_options.length + ' options:</p>';
                        resultsHtml += '<ul>';
                        $.each(response.data.failed_options, function(index, option) {
                            resultsHtml += '<li>' + option + '</li>';
                        });
                        resultsHtml += '</ul>';
                    }
                    
                    resultsHtml += '<p>Debug log: <a href="' + response.data.log_file + '" target="_blank">View Log File</a></p>';
                    resultsHtml += '</div>';
                    
                    $('#results-container').html(resultsHtml).show();
                    
                    // Reload page after 3 seconds to show updated status
                    setTimeout(function() {
                        location.reload();
                    }, 3000);
                } else {
                    // Display error
                    $('#results-container').html('<div class="notice notice-error"><p>Error: ' + response.data.message + '</p></div>').show();
                }
                
                // Hide loading indicator
                $('#loading-indicator').hide();
                $('#disable-selected').prop('disabled', false);
            }).fail(function(xhr, status, error) {
                // Display error
                $('#results-container').html('<div class="notice notice-error"><p>AJAX request failed: ' + error + '</p></div>').show();
                
                // Hide loading indicator
                $('#loading-indicator').hide();
                $('#disable-selected').prop('disabled', false);
            });
        }
    });
    </script>
    <?php
}