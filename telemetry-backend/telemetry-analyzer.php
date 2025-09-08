<?php
/**
 * Telemetry Data Analyzer for Autoloaded Options Optimizer
 *
 * This script processes telemetry data to identify unknown options
 * that should be added to the config.json file.
 *
 * Usage: php telemetry-analyzer.php <telemetry-log-file.json>
 */

// Prevent direct access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

/*
 * WARNING: This version displays actual site URLs in the analysis output.
 * Only use this if you control all data sources and understand the privacy implications.
 * For public use, consider using only hashed URLs for privacy protection.
 */

if ($argc < 2) {
    die("Usage: php telemetry-analyzer.php <telemetry-log-file.json>\n");
}

$logFile = $argv[1];

if (!file_exists($logFile)) {
    die("Error: Log file '$logFile' not found.\n");
}

$logData = json_decode(file_get_contents($logFile), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Error: Invalid JSON in log file.\n");
}

    echo "=== Autoloaded Options Optimizer - Telemetry Analysis ===\n\n";
    echo "⚠️  WARNING: This analysis displays actual site URLs.\n";
    echo "   Only use this if you control all data sources.\n\n";

analyzeTelemetryData($logData);

function analyzeTelemetryData($data) {
    $unknownOptions = [];
    $knownPlugins = [];
    $knownThemes = [];
    $uniqueSites = [];
    $totalSubmissions = count($data);
    $totalUnknownOptions = 0;

    echo "Total telemetry submissions: $totalSubmissions\n\n";

    // Aggregate data from all submissions
    foreach ($data as $submission) {
        // Track unique sites
        if (isset($submission['site_hash'])) {
            $uniqueSites[$submission['site_hash']] = true;
        }

        // Aggregate unknown options
        if (isset($submission['unknown_options'])) {
            foreach ($submission['unknown_options'] as $option) {
                $name = $option['name'];
                $size = $option['size'];

                if (!isset($unknownOptions[$name])) {
                    $unknownOptions[$name] = [
                        'count' => 0,
                        'total_size' => 0,
                        'sizes' => [],
                        'avg_size' => 0
                    ];
                }

                $unknownOptions[$name]['count']++;
                $unknownOptions[$name]['total_size'] += $size;
                $unknownOptions[$name]['sizes'][] = $size;
                $totalUnknownOptions++;
            }
        }

        // Aggregate known plugins
        if (isset($submission['known_plugins'])) {
            foreach ($submission['known_plugins'] as $plugin) {
                $name = $plugin['name'];
                if (!isset($knownPlugins[$name])) {
                    $knownPlugins[$name] = [
                        'count' => 0,
                        'total_option_count' => 0,
                        'total_size' => 0,
                        'avg_size' => 0
                    ];
                }
                $knownPlugins[$name]['count']++;
                $knownPlugins[$name]['total_option_count'] += $plugin['option_count'] ?? 0;
                $knownPlugins[$name]['total_size'] += $plugin['total_size'] ?? 0;
            }
        }

        // Aggregate known themes
        if (isset($submission['known_themes'])) {
            foreach ($submission['known_themes'] as $theme) {
                $name = $theme['name'];
                if (!isset($knownThemes[$name])) {
                    $knownThemes[$name] = [
                        'count' => 0,
                        'versions' => []
                    ];
                }
                $knownThemes[$name]['count']++;
                if (isset($theme['version'])) {
                    $knownThemes[$name]['versions'][] = $theme['version'];
                }
            }
        }
    }

    echo "Total unique sites: " . count($uniqueSites) . "\n";
    echo "Total unique unknown options found: " . count($unknownOptions) . "\n";
    echo "Total unknown option instances: $totalUnknownOptions\n";
    echo "Total unique known plugins: " . count($knownPlugins) . "\n";
    echo "Total unique known themes: " . count($knownThemes) . "\n\n";

    echo "=== Sites Overview ===\n\n";
    $sitesByUrl = [];
    foreach ($data as $submission) {
        $siteUrl = $submission['site_url'] ?? 'Unknown';
        if (!isset($sitesByUrl[$siteUrl])) {
            $sitesByUrl[$siteUrl] = [
                'submissions' => 0,
                'last_seen' => $submission['received_at'],
                'unknown_count' => count($submission['unknown_options'] ?? []),
                'plugins_count' => count($submission['known_plugins'] ?? [])
            ];
        }
        $sitesByUrl[$siteUrl]['submissions']++;
        if (strtotime($submission['received_at']) > strtotime($sitesByUrl[$siteUrl]['last_seen'])) {
            $sitesByUrl[$siteUrl]['last_seen'] = $submission['received_at'];
            $sitesByUrl[$siteUrl]['unknown_count'] = count($submission['unknown_options'] ?? []);
            $sitesByUrl[$siteUrl]['plugins_count'] = count($submission['known_plugins'] ?? []);
        }
    }

    uasort($sitesByUrl, function($a, $b) {
        return strtotime($b['last_seen']) <=> strtotime($a['last_seen']);
    });

    foreach ($sitesByUrl as $url => $info) {
        echo sprintf("%-50s | %2d submissions | Last: %s | %3d unknown | %2d plugins\n",
            substr($url, 0, 50),
            $info['submissions'],
            date('Y-m-d H:i', strtotime($info['last_seen'])),
            $info['unknown_count'],
            $info['plugins_count']
        );
    }
    echo "\n";

    // Calculate averages for unknown options
    foreach ($unknownOptions as $name => &$stats) {
        $stats['avg_size'] = round($stats['total_size'] / $stats['count']);
        $stats['avg_size_kb'] = round($stats['avg_size'] / 1024, 2);
    }

    // Calculate averages for known plugins
    foreach ($knownPlugins as $name => &$stats) {
        $stats['avg_option_count'] = round($stats['total_option_count'] / $stats['count'], 1);
        $stats['avg_size'] = round($stats['total_size'] / $stats['count']);
        $stats['avg_size_kb'] = round($stats['avg_size'] / 1024, 2);
    }

    // Sort unknown options by frequency and average size
    uasort($unknownOptions, function($a, $b) {
        // Primary sort: frequency (higher count first)
        if ($a['count'] !== $b['count']) {
            return $b['count'] <=> $a['count'];
        }
        // Secondary sort: average size (larger first)
        return $b['avg_size'] <=> $a['avg_size'];
    });

    // Sort known plugins by frequency
    uasort($knownPlugins, function($a, $b) {
        return $b['count'] <=> $a['count'];
    });

    // Sort known themes by frequency
    uasort($knownThemes, function($a, $b) {
        return $b['count'] <=> $a['count'];
    });

    echo "=== Top Unknown Options by Frequency ===\n\n";

    $topOptions = array_slice($unknownOptions, 0, 20, true);

    foreach ($topOptions as $name => $stats) {
        $percentage = round(($stats['count'] / $totalSubmissions) * 100, 1);
        echo sprintf("%-50s | %3d sites (%4.1f%%) | %6s avg\n",
            substr($name, 0, 50),
            $stats['count'],
            $percentage,
            size_format($stats['avg_size'])
        );
    }

    echo "\n=== Suggested Config Additions ===\n\n";
    echo "Add these to config.json under 'plugin_mappings':\n\n";

    foreach ($topOptions as $name => $stats) {
        if ($stats['count'] >= 3) { // Only suggest options seen on 3+ sites
            $pattern = guessPattern($name);
            echo "\"$pattern\": {\n";
            echo "    \"name\": \"[PLUGIN/THEME NAME - TO BE DETERMINED]\",\n";
            echo "    \"file\": \"[plugin-or-theme-file.php]\"\n";
            echo "},\n\n";
        }
    }

    echo "\n=== Top Known Plugins by Usage ===\n\n";
    $topPlugins = array_slice($knownPlugins, 0, 20, true);
    foreach ($topPlugins as $name => $stats) {
        $percentage = round(($stats['count'] / count($uniqueSites)) * 100, 1);
        echo sprintf("%-40s | %3d sites (%4.1f%%) | %3.1f avg options | %6s avg\n",
            substr($name, 0, 40),
            $stats['count'],
            $percentage,
            $stats['avg_option_count'],
            size_format($stats['avg_size'])
        );
    }

    echo "\n=== Top Known Themes by Usage ===\n\n";
    $topThemes = array_slice($knownThemes, 0, 15, true);
    foreach ($topThemes as $name => $stats) {
        $percentage = round(($stats['count'] / count($uniqueSites)) * 100, 1);
        $versionCount = count(array_unique($stats['versions']));
        echo sprintf("%-40s | %3d sites (%4.1f%%) | %2d versions\n",
            substr($name, 0, 40),
            $stats['count'],
            $percentage,
            $versionCount
        );
    }

    echo "\n=== Analysis Complete ===\n";
    echo "Review the suggestions above and research the plugin/theme for each option.\n";
    echo "Only add mappings for legitimate plugins/themes that are commonly used.\n";
    echo "Known plugins/themes data helps prioritize development efforts.\n";
}

function guessPattern($optionName) {
    // Try to create a reasonable pattern from the option name
    if (strpos($optionName, '_') !== false) {
        // If it has underscores, create a prefix pattern
        $parts = explode('_', $optionName);
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1] . '*';
        }
    }

    // Fallback to the full name if no good pattern can be guessed
    return $optionName;
}

function size_format($bytes) {
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . 'MB';
    } elseif ($bytes >= 1024) {
        return round($bytes / 1024, 1) . 'KB';
    } else {
        return $bytes . 'B';
    }
}