<?php
/**
 * Telemetry Data Collector for Autoloaded Options Optimizer
 *
 * This script receives telemetry data from plugin installations
 * and stores it for analysis.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Simple security check for direct access
    if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_USER_AGENT'])) {
        http_response_code(403);
        die('Access denied');
    }
}

/*
 * WARNING: This version stores actual site URLs for dashboard viewing.
 * Only use this if you control all data sources and understand the privacy implications.
 * For public use, consider using only hashed URLs for privacy protection.
 */

// Configuration
define('TELEMETRY_LOG_FILE', __DIR__ . '/telemetry-data.jsonl');
define('TELEMETRY_MAX_FILE_SIZE', 50 * 1024 * 1024); // 50MB max file size

// Handle CORS for web requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent');
header('Content-Type: application/json');

/* ---------- rate limit ---------- */
require_once __DIR__.'/rate-limit.php';
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!rateLimitAllow($clientIp)) { http_response_code(429); exit(json_encode(['error'=>'Too many requests'])); }

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get the raw POST data
$rawData = file_get_contents('php://input');
if (empty($rawData)) {
    http_response_code(400);
    echo json_encode(['error' => 'No data received']);
    exit;
}

// Decode the JSON data
$data = json_decode($rawData, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

// Handle data deletion requests
if (isset($data['action']) && $data['action'] === 'delete_site_data') {
    handleDataDeletion($data);
    exit;
}

// Validate the data structure for telemetry
if (!is_array($data) || !isset($data['unknown_options']) || !isset($data['site_hash'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data structure']);
    exit;
}

// Add metadata
$data['received_at'] = date('c');
$data['ip_hash'] = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
$data['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// Store site URL (for personal use - ensure you trust all data sources)
if (isset($data['site_url'])) {
    $data['site_url_hash'] = hash('sha256', $data['site_url']);
    // Keep the original URL for personal dashboard viewing
    // WARNING: Only do this if you control all data sources and understand privacy implications
}

// Remove any potentially sensitive data
unset($data['admin_email']);

// Validate and sanitize data
if (!is_array($data['unknown_options'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid unknown_options format']);
    exit;
}

// Validate known plugins if provided
if (isset($data['known_plugins']) && !is_array($data['known_plugins'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid known_plugins format']);
    exit;
}

// Validate known themes if provided
if (isset($data['known_themes']) && !is_array($data['known_themes'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid known_themes format']);
    exit;
}

// Sanitize option names (remove any potentially dangerous characters)
foreach ($data['unknown_options'] as &$option) {
    if (isset($option['name'])) {
        $option['name'] = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $option['name']);
    }
}

// Sanitize plugin names
if (isset($data['known_plugins'])) {
    foreach ($data['known_plugins'] as &$plugin) {
        if (isset($plugin['name'])) {
            $plugin['name'] = preg_replace('/[^a-zA-Z0-9_\-\.\s\(\)]/', '', $plugin['name']);
        }
    }
}

// Sanitize theme names
if (isset($data['known_themes'])) {
    foreach ($data['known_themes'] as &$theme) {
        if (isset($theme['name'])) {
            $theme['name'] = preg_replace('/[^a-zA-Z0-9_\-\.\s\(\)]/', '', $theme['name']);
        }
    }
}

// Log the data
$success = logTelemetryData($data);

if ($success) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Telemetry data received']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save telemetry data']);
}

/**
 * Checks if we should accept this telemetry submission (deduplication logic)
 * Always accept the latest submission per site, replacing older ones.
 */
function shouldAcceptTelemetry($data) {
    if (!file_exists(TELEMETRY_LOG_FILE)) {
        return true; // No previous data, accept
    }

    $siteHash = $data['site_hash'];
    $currentTimestamp = strtotime($data['received_at']);

    // Read all submissions to find the latest for this site
    $handle = fopen(TELEMETRY_LOG_FILE, 'r');
    if (!$handle) {
        return true; // Can't read file, accept
    }

    $latestTimestamp = 0;
    while (($line = fgets($handle)) !== false) {
        $submission = json_decode(trim($line), true);
        if ($submission && isset($submission['site_hash']) && $submission['site_hash'] === $siteHash) {
            $submissionTime = strtotime($submission['received_at']);
            if ($submissionTime > $latestTimestamp) {
                $latestTimestamp = $submissionTime;
            }
        }
    }
    fclose($handle);

    // Accept if this is newer than the latest existing
    return $currentTimestamp > $latestTimestamp;
}

/**
 * Logs telemetry data to a file, replacing older submissions from the same site
 */
function logTelemetryData($data) {
    try {
        // Check deduplication logic
        if (!shouldAcceptTelemetry($data)) {
            return true; // Silently accept but don't log duplicate
        }

        // Check if log file exists and rotate if too large
        if (file_exists(TELEMETRY_LOG_FILE) && filesize(TELEMETRY_LOG_FILE) > TELEMETRY_MAX_FILE_SIZE) {
            $backupFile = TELEMETRY_LOG_FILE . '.' . date('Y-m-d-H-i-s') . '.bak';
            rename(TELEMETRY_LOG_FILE, $backupFile);
        }

        $siteHash = $data['site_hash'];
        $allSubmissions = [];

        // Read existing data
        if (file_exists(TELEMETRY_LOG_FILE)) {
            $handle = fopen(TELEMETRY_LOG_FILE, 'r');
            while (($line = fgets($handle)) !== false) {
                $submission = json_decode(trim($line), true);
                if ($submission) {
                    // Skip older submissions from the same site
                    if (isset($submission['site_hash']) && $submission['site_hash'] === $siteHash) {
                        continue;
                    }
                    $allSubmissions[] = $submission;
                }
            }
            fclose($handle);
        }

        // Add the new submission
        $allSubmissions[] = $data;

        // Write back all submissions
        $content = '';
        foreach ($allSubmissions as $sub) {
            $content .= json_encode($sub) . "\n";
        }

        if (file_put_contents(TELEMETRY_LOG_FILE, $content, LOCK_EX) !== false) {
            return true;
        }
    } catch (Exception $e) {
        error_log('Telemetry logging error: ' . $e->getMessage());
    }

    return false;
}

/**
 * Handles data deletion requests
 */
function handleDataDeletion($data) {
    if (!isset($data['site_hash'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing site_hash for deletion']);
        return;
    }

    if (!file_exists(TELEMETRY_LOG_FILE)) {
        http_response_code(404);
        echo json_encode(['error' => 'No data file found']);
        return;
    }

    $siteHash = $data['site_hash'];
    $handle = fopen(TELEMETRY_LOG_FILE, 'r');
    if (!$handle) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot read log file']);
        return;
    }

    $remainingSubmissions = [];
    while (($line = fgets($handle)) !== false) {
        $submission = json_decode(trim($line), true);
        if ($submission && isset($submission['site_hash']) && $submission['site_hash'] !== $siteHash) {
            $remainingSubmissions[] = $submission;
        }
    }
    fclose($handle);

    // Write back remaining submissions
    $content = '';
    foreach ($remainingSubmissions as $sub) {
        $content .= json_encode($sub) . "\n";
    }

    if (file_put_contents(TELEMETRY_LOG_FILE, $content, LOCK_EX) !== false) {
        echo json_encode(['success' => true, 'message' => 'Data deleted successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete data']);
    }
}

/**
 * Gets aggregated stats considering only the latest submission per unique site
 */
function getAggregatedStats() {
    if (!file_exists(TELEMETRY_LOG_FILE)) {
        return [
            'unique_sites' => 0,
            'total_submissions' => 0,
            'total_unknown_options' => 0,
            'total_plugins' => 0,
            'avg_plugins_per_site' => 0
        ];
    }

    $handle = fopen(TELEMETRY_LOG_FILE, 'r');
    if (!$handle) {
        return ['error' => 'Cannot read log file'];
    }

    $submissions = [];
    while (($line = fgets($handle)) !== false) {
        $submission = json_decode(trim($line), true);
        if ($submission && isset($submission['site_hash'])) {
            $siteHash = $submission['site_hash'];
            $timestamp = strtotime($submission['received_at']);
            if (!isset($submissions[$siteHash]) || $timestamp > strtotime($submissions[$siteHash]['received_at'])) {
                $submissions[$siteHash] = $submission;
            }
        }
    }
    fclose($handle);

    $uniqueSites = count($submissions);
    $totalSubmissions = count($submissions); // Since we only keep latest per site
    $totalUnknownOptions = 0;
    $totalPlugins = 0;

    foreach ($submissions as $sub) {
        $totalUnknownOptions += count($sub['unknown_options'] ?? []);
        $totalPlugins += $sub['plugin_count'] ?? 0;
    }

    $avgPluginsPerSite = $uniqueSites > 0 ? round($totalPlugins / $uniqueSites, 2) : 0;

    return [
        'unique_sites' => $uniqueSites,
        'total_submissions' => $totalSubmissions,
        'total_unknown_options' => $totalUnknownOptions,
        'total_plugins' => $totalPlugins,
        'avg_plugins_per_site' => $avgPluginsPerSite
    ];
}

// Uncomment to test stats (for debugging)
// echo json_encode(getAggregatedStats());
?>