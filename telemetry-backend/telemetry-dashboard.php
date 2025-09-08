<?php
/**
 * Telemetry Dashboard for Autoloaded Options Optimizer
 *
 * Simple web interface to view and analyze collected telemetry data
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Simple security check
    if (!isset($_SERVER['HTTP_HOST'])) {
        die('Access denied');
    }
}

// Configuration
define('TELEMETRY_LOG_FILE', __DIR__ . '/telemetry-data.jsonl');
define('TELEMETRY_PASSWORD', 'wdexter3'); // Change this!

// Simple authentication
$authenticated = false;
if (isset($_POST['password']) && $_POST['password'] === TELEMETRY_PASSWORD) {
    $authenticated = true;
    setcookie('telemetry_auth', hash('sha256', TELEMETRY_PASSWORD), time() + 3600);
} elseif (isset($_COOKIE['telemetry_auth']) && $_COOKIE['telemetry_auth'] === hash('sha256', TELEMETRY_PASSWORD)) {
    $authenticated = true;
}

if (!$authenticated) {
    showLoginForm();
    exit;
}

// Load and analyze telemetry data
$telemetryData = loadTelemetryData();
$analysis = analyzeTelemetryData($telemetryData);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telemetry Dashboard - Autoloaded Options Optimizer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #333; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
        .stat-card { background: #f8f9fa; padding: 15px; border-radius: 6px; border-left: 4px solid #007cba; }
        .stat-number { font-size: 2em; font-weight: bold; color: #007cba; }
        .stat-label { color: #666; margin-top: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        .option-name { font-family: monospace; font-size: 0.9em; }
        .logout { float: right; padding: 10px 15px; background: #dc3545; color: white; text-decoration: none; border-radius: 4px; }
        .logout:hover { background: #c82333; }
    </style>
</head>
<body>
    <div class="container">
        <a href="?logout=1" class="logout">Logout</a>
        <h1>Telemetry Dashboard</h1>
        <p>Analysis of collected telemetry data from Autoloaded Options Optimizer installations.</p>
        <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px;">
            <strong>⚠️ Privacy Notice:</strong> This dashboard displays actual site URLs. Only use this setup if you control all data sources and understand the privacy implications.
        </div>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($analysis['total_submissions']); ?></div>
                <div class="stat-label">Unique Sites</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($analysis['total_all_submissions']); ?></div>
                <div class="stat-label">Total Submissions</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($analysis['unique_options']); ?></div>
                <div class="stat-label">Unknown Options</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($analysis['unique_plugins']); ?></div>
                <div class="stat-label">Known Plugins</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($analysis['unique_themes']); ?></div>
                <div class="stat-label">Known Themes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo round($analysis['avg_options_per_submission'], 1); ?></div>
                <div class="stat-label">Avg Options per Site</div>
            </div>
        </div>

        <h2>Top Unknown Options</h2>
        <table>
            <thead>
                <tr>
                    <th>Option Name</th>
                    <th>Sites</th>
                    <th>Frequency</th>
                    <th>Avg Size</th>
                    <th>Potential Pattern</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($analysis['top_options'] as $option): ?>
                <tr>
                    <td class="option-name"><?php echo htmlspecialchars($option['name']); ?></td>
                    <td><?php echo $option['count']; ?></td>
                    <td><?php echo round(($option['count'] / $analysis['total_submissions']) * 100, 1); ?>%</td>
                    <td><?php echo size_format($option['avg_size']); ?></td>
                    <td><code><?php echo htmlspecialchars($option['suggested_pattern']); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Top Known Plugins</h2>
        <table>
            <thead>
                <tr>
                    <th>Plugin Name</th>
                    <th>Sites</th>
                    <th>Frequency</th>
                    <th>Avg Options</th>
                    <th>Avg Size</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($analysis['top_plugins'] as $name => $stats): ?>
                <tr>
                    <td><?php echo htmlspecialchars($name); ?></td>
                    <td><?php echo $stats['count']; ?></td>
                    <td><?php echo round(($stats['count'] / $analysis['unique_sites']) * 100, 1); ?>%</td>
                    <td><?php echo round($stats['avg_options'], 1); ?></td>
                    <td><?php echo size_format($stats['avg_size']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Top Known Themes</h2>
        <table>
            <thead>
                <tr>
                    <th>Theme Name</th>
                    <th>Sites</th>
                    <th>Frequency</th>
                    <th>Versions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($analysis['top_themes'] as $name => $stats): ?>
                <tr>
                    <td><?php echo htmlspecialchars($name); ?></td>
                    <td><?php echo $stats['count']; ?></td>
                    <td><?php echo round(($stats['count'] / $analysis['unique_sites']) * 100, 1); ?>%</td>
                    <td><?php echo count(array_unique($stats['versions'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Recent Submissions</h2>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Site URL</th>
                    <th>WP Version</th>
                    <th>PHP Version</th>
                    <th>Unknown Options</th>
                    <th>Known Plugins</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $recent = array_slice($telemetryData, -10);
                foreach (array_reverse($recent) as $submission):
                ?>
                <tr>
                    <td><?php echo date('Y-m-d H:i', strtotime($submission['received_at'])); ?></td>
                    <td><a href="<?php echo htmlspecialchars($submission['site_url'] ?? 'N/A'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($submission['site_url'] ?? 'N/A'); ?></a></td>
                    <td><?php echo htmlspecialchars($submission['wp_version'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($submission['php_version'] ?? 'N/A'); ?></td>
                    <td><?php echo count($submission['unknown_options'] ?? []); ?></td>
                    <td><?php echo count($submission['known_plugins'] ?? []); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>All Sites Overview</h2>
        <table>
            <thead>
                <tr>
                    <th>Site URL</th>
                    <th>Last Seen</th>
                    <th>WP Version</th>
                    <th>PHP Version</th>
                    <th>Unknown Options</th>
                    <th>Known Plugins</th>
                    <th>Total Submissions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $sitesOverview = [];
                foreach ($telemetryData as $submission) {
                    $siteUrl = $submission['site_url'] ?? 'Unknown';
                    if (!isset($sitesOverview[$siteUrl])) {
                        $sitesOverview[$siteUrl] = [
                            'last_seen' => $submission['received_at'],
                            'wp_version' => $submission['wp_version'] ?? 'N/A',
                            'php_version' => $submission['php_version'] ?? 'N/A',
                            'unknown_options' => count($submission['unknown_options'] ?? []),
                            'known_plugins' => count($submission['known_plugins'] ?? []),
                            'submissions' => 1
                        ];
                    } else {
                        $sitesOverview[$siteUrl]['submissions']++;
                        // Update to most recent data
                        if (strtotime($submission['received_at']) > strtotime($sitesOverview[$siteUrl]['last_seen'])) {
                            $sitesOverview[$siteUrl]['last_seen'] = $submission['received_at'];
                            $sitesOverview[$siteUrl]['wp_version'] = $submission['wp_version'] ?? 'N/A';
                            $sitesOverview[$siteUrl]['php_version'] = $submission['php_version'] ?? 'N/A';
                            $sitesOverview[$siteUrl]['unknown_options'] = count($submission['unknown_options'] ?? []);
                            $sitesOverview[$siteUrl]['known_plugins'] = count($submission['known_plugins'] ?? []);
                        }
                    }
                }

                // Sort by most recent first
                uasort($sitesOverview, function($a, $b) {
                    return strtotime($b['last_seen']) <=> strtotime($a['last_seen']);
                });

                foreach ($sitesOverview as $siteUrl => $data):
                ?>
                <tr>
                    <td><a href="<?php echo htmlspecialchars($siteUrl); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($siteUrl); ?></a></td>
                    <td><?php echo date('Y-m-d H:i', strtotime($data['last_seen'])); ?></td>
                    <td><?php echo htmlspecialchars($data['wp_version']); ?></td>
                    <td><?php echo htmlspecialchars($data['php_version']); ?></td>
                    <td><?php echo $data['unknown_options']; ?></td>
                    <td><?php echo $data['known_plugins']; ?></td>
                    <td><?php echo $data['submissions']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

<?php

function showLoginForm() {
    if (isset($_GET['logout'])) {
        setcookie('telemetry_auth', '', time() - 3600);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Telemetry Dashboard Login</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            input { display: block; width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
            button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
            button:hover { background: #005a87; }
        </style>
    </head>
    <body>
        <div class="login">
            <h2>Telemetry Dashboard</h2>
            <form method="post">
                <input type="password" name="password" placeholder="Enter password" required>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function loadTelemetryData() {
    if (!file_exists(TELEMETRY_LOG_FILE)) {
        return [];
    }

    $data = [];
    $handle = fopen(TELEMETRY_LOG_FILE, 'r');

    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);
            if ($decoded) {
                $data[] = $decoded;
            }
        }
        fclose($handle);
    }

    return $data;
}

function analyzeTelemetryData($data) {
    $totalAllSubmissions = count($data); // Total submissions before filtering

    // Filter to only the latest submission per unique site
    $latestSubmissions = [];
    foreach ($data as $submission) {
        $siteHash = $submission['site_hash'] ?? '';
        if (!$siteHash) continue;

        if (!isset($latestSubmissions[$siteHash]) ||
            strtotime($submission['received_at']) > strtotime($latestSubmissions[$siteHash]['received_at'])) {
            $latestSubmissions[$siteHash] = $submission;
        }
    }

    $data = array_values($latestSubmissions); // Use only latest per site

    $options = [];
    $plugins = [];
    $themes = [];
    $uniqueSites = [];
    $totalSubmissions = count($data); // Now this is unique sites

    foreach ($data as $submission) {
        // Track unique sites
        if (isset($submission['site_hash'])) {
            $uniqueSites[$submission['site_hash']] = true;
        }

        // Process unknown options
        if (isset($submission['unknown_options'])) {
            foreach ($submission['unknown_options'] as $option) {
                $name = $option['name'];
                if (!isset($options[$name])) {
                    $options[$name] = ['count' => 0, 'total_size' => 0, 'sizes' => []];
                }
                $options[$name]['count']++;
                $options[$name]['total_size'] += $option['size'];
                $options[$name]['sizes'][] = $option['size'];
            }
        }

        // Process known plugins
        if (isset($submission['known_plugins'])) {
            foreach ($submission['known_plugins'] as $plugin) {
                $name = $plugin['name'];
                if (!isset($plugins[$name])) {
                    $plugins[$name] = ['count' => 0, 'total_size' => 0, 'total_options' => 0];
                }
                $plugins[$name]['count']++;
                $plugins[$name]['total_size'] += $plugin['total_size'] ?? 0;
                $plugins[$name]['total_options'] += $plugin['option_count'] ?? 0;
            }
        }

        // Process known themes
        if (isset($submission['known_themes'])) {
            foreach ($submission['known_themes'] as $theme) {
                $name = $theme['name'];
                if (!isset($themes[$name])) {
                    $themes[$name] = ['count' => 0, 'versions' => []];
                }
                $themes[$name]['count']++;
                if (isset($theme['version'])) {
                    $themes[$name]['versions'][] = $theme['version'];
                }
            }
        }
    }

    // Calculate averages for options
    foreach ($options as &$option) {
        $option['avg_size'] = count($option['sizes']) > 0 ? $option['total_size'] / count($option['sizes']) : 0;
    }

    // Calculate averages for plugins
    foreach ($plugins as &$plugin) {
        $plugin['avg_size'] = $plugin['count'] > 0 ? $plugin['total_size'] / $plugin['count'] : 0;
        $plugin['avg_options'] = $plugin['count'] > 0 ? $plugin['total_options'] / $plugin['count'] : 0;
    }

    // Sort by frequency
    uasort($options, function($a, $b) { return $b['count'] <=> $a['count']; });
    uasort($plugins, function($a, $b) { return $b['count'] <=> $a['count']; });
    uasort($themes, function($a, $b) { return $b['count'] <=> $a['count']; });

    // Add metadata
    foreach ($options as $name => &$option) {
        $option['name'] = $name;
        $option['suggested_pattern'] = guessPattern($name);
    }

    return [
        'total_submissions' => $totalSubmissions,
        'total_all_submissions' => $totalAllSubmissions,
        'unique_sites' => count($uniqueSites),
        'unique_options' => count($options),
        'unique_plugins' => count($plugins),
        'unique_themes' => count($themes),
        'total_option_instances' => array_sum(array_column($options, 'count')),
        'avg_options_per_submission' => $totalSubmissions > 0 ? array_sum(array_column($options, 'count')) / $totalSubmissions : 0,
        'top_options' => array_slice($options, 0, 50),
        'top_plugins' => array_slice($plugins, 0, 30),
        'top_themes' => array_slice($themes, 0, 20)
    ];
}

function guessPattern($optionName) {
    if (strpos($optionName, '_') !== false) {
        $parts = explode('_', $optionName);
        if (count($parts) >= 2) {
            return $parts[0] . '_' . $parts[1] . '*';
        }
    }
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
?>