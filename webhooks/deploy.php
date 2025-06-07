<?php
/**
 * GitHub Webhook Deploy Script
 * Automatically deploys from gh-pages branch when receiving a push
 */

$configFile = __DIR__ . '/deploy-config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    die(json_encode(['error' => 'Configuration file not found: deploy-config.php']));
}

require_once $configFile;
require_once __DIR__ . '/ZipSync.php';

ini_set('display_errors', 'Off');
if (ENABLE_LOGS) {
    ini_set('log_errors', 'On');
    error_reporting(E_ALL);
    if (defined('LOG_FILE') && LOG_FILE) {
        ini_set('error_log', LOG_FILE);
    }
} else {
    ini_set('log_errors', 'Off');
    error_reporting(0);
}

function checkRequiredExtensions() {
    $required = ['curl', 'zip', 'json'];
    $missing = [];
    
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    
    if (!empty($missing)) {
        throw new Exception('Missing required PHP extensions: ' . implode(', ', $missing));
    }
    
    trigger_error('All required extensions are available', E_USER_NOTICE);
}

function createUniqueTempDir($baseTempDir) {
    $uniqueId = uniqid('deploy_', true) . '_' . getmypid();
    $uniqueTempDir = rtrim($baseTempDir, '/') . '/' . $uniqueId;
    
    if (!is_dir($uniqueTempDir)) {
        if (!mkdir($uniqueTempDir, 0755, true)) {
            throw new Exception("Failed to create temporary directory: $uniqueTempDir");
        }
    }
    
    trigger_error("Created unique temporary directory: $uniqueTempDir", E_USER_NOTICE);
    return $uniqueTempDir;
}

function cleanupTempDir($tempDir) {
    if (is_dir($tempDir)) {
        $escapedPath = escapeshellarg($tempDir);
        $output = [];
        $returnCode = 0;
        exec("rm -rf $escapedPath 2>&1", $output, $returnCode);
        
        if ($returnCode !== 0) {
            trigger_error("Warning: Failed to cleanup temp directory: " . implode("\n", $output), E_USER_WARNING);
        } else {
            trigger_error("Temp directory cleaned up successfully: $tempDir", E_USER_NOTICE);
        }
    }
}

function validateGitHubWebhook($payload, $signature, $secret) {
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

function validateRepository($payload) {
    if (empty(REMOTE_REPOSITORY)) {
        trigger_error("REMOTE_REPOSITORY not configured - deployment rejected", E_USER_ERROR);
        return false;
    }
    
    $incomingRepo = $payload['repository']['full_name'] ?? '';
    
    if ($incomingRepo !== REMOTE_REPOSITORY) {
        trigger_error("Repository mismatch - incoming: '$incomingRepo', expected: '" . REMOTE_REPOSITORY . "'", E_USER_WARNING);
        return false;
    }
    
    trigger_error("Repository validated: $incomingRepo", E_USER_NOTICE);
    return true;
}

function downloadRepositoryZip($repoUrl, $branch, $tempDir) {
    trigger_error("Downloading repository: $repoUrl (branch: $branch)", E_USER_NOTICE);

    // URL to download ZIP
    $zipUrl = str_replace('.git', '', $repoUrl) . "/archive/refs/heads/$branch.zip";
    $zipFile = "$tempDir/repo.zip";
    
    trigger_error("Downloading from: $zipUrl", E_USER_NOTICE);
    
    // Configure cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $zipUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout
    
    $zipContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || $zipContent === false) {
        $errorMsg = "Error downloading repository. HTTP Code: $httpCode";
        if ($curlError) {
            $errorMsg .= ", cURL Error: $curlError";
        }
        throw new Exception($errorMsg);
    }
    
    if (file_put_contents($zipFile, $zipContent) === false) {
        throw new Exception("Error saving ZIP file");
    }
    
    trigger_error("ZIP downloaded successfully: " . filesize($zipFile) . " bytes", E_USER_NOTICE);

    return $zipFile;
}

function syncToWebRoot($zipFile, $targetDir, $excludeList, $sourceDir = '') {
    trigger_error("Starting ZipSync from $zipFile to $targetDir", E_USER_NOTICE);
    
    if (!empty($sourceDir)) {
        trigger_error("Using source directory: $sourceDir", E_USER_NOTICE);
    }
    
    if (!empty($excludeList)) {
        trigger_error("Excluding: " . implode(', ', $excludeList), E_USER_NOTICE);
    }
    
    try {
        $zipSync = new ZipSync($zipFile, $targetDir, $excludeList, $sourceDir);
        $zipSync->sync();
        
        trigger_error("ZipSync completed successfully", E_USER_NOTICE);
        
        return ['status' => 'success'];
        
    } catch (Exception $e) {
        throw new Exception("ZipSync error: " . $e->getMessage());
    }
}

function deployFromGitHub($payload) {
    $tempDir = null;
    
    try {        
        trigger_error("=== STARTING DEPLOYMENT ===", E_USER_NOTICE);
        trigger_error("Repository: {$payload['repository']['full_name']}", E_USER_NOTICE);
        trigger_error("Branch: {$payload['ref']}", E_USER_NOTICE);
        trigger_error("Commit: {$payload['head_commit']['id']}", E_USER_NOTICE);
        trigger_error("Message: {$payload['head_commit']['message']}", E_USER_NOTICE);
        
        $repoUrl = 'https://github.com/' . REMOTE_REPOSITORY . '.git';
        $tempDir = createUniqueTempDir(TEMP_DIR);
        $zipFile = downloadRepositoryZip(
            $repoUrl,
            TARGET_BRANCH,
            $tempDir
        );
        
        // Get exclude list
        $excludeList = defined('EXCLUDE_LIST') ? EXCLUDE_LIST : [];
        
        // Get source directory from GitHub ZIP (format: repo-branch)
        $repoName = basename(REMOTE_REPOSITORY);
        
        // Synchronize using ZipSync
        $syncResult = syncToWebRoot($zipFile, WEB_ROOT, $excludeList, ZIP_DIR);
        
        // Clean temporary directory
        cleanupTempDir($tempDir);

        trigger_error("=== DEPLOYMENT COMPLETED SUCCESSFULLY ===", E_USER_NOTICE);
        
        return [
            'status' => 'success', 
            'message' => 'Deployment completed successfully',
        ];
        
    } catch (Exception $e) {
        trigger_error("ERROR: " . $e->getMessage(), E_USER_ERROR);
        
        // Cleanup on error
        if (isset($tempDir) && is_dir($tempDir)) {
            cleanupTempDir($tempDir);
        }
        
        return [
            'status' => 'error', 
            'message' => $e->getMessage()
        ];
    }
}

// === MAIN ENTRY POINT ===

try {
    checkRequiredExtensions();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        trigger_error("Method not allowed: {$_SERVER['REQUEST_METHOD']}", E_USER_WARNING);
        die(json_encode(['error' => 'Method not allowed']));
    }

    $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
    if ($event !== 'push') {
        trigger_error("Event ignored: $event", E_USER_NOTICE);
        echo json_encode(['status' => 'ignored', 'message' => 'Not a push event']);
        exit;
    }
    
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    
    if (!validateGitHubWebhook($payload, $signature, WEBHOOK_SECRET)) {
        http_response_code(401);
        trigger_error("Invalid webhook - signature does not match", E_USER_WARNING);
        die(json_encode(['error' => 'Invalid webhook signature']));
    }
    
    $payloadData = json_decode($payload, true);
    if (!$payloadData) {
        http_response_code(400);
        trigger_error("Invalid JSON payload", E_USER_ERROR);
        die(json_encode(['error' => 'Invalid JSON payload']));
    }

    if (!validateRepository($payloadData)) {
        trigger_error("Repository validation failed", E_USER_WARNING);
        echo json_encode(['status' => 'ignored', 'message' => 'Repository not allowed']);
        exit;
    }

    if ($payloadData['ref'] !== "refs/heads/" . TARGET_BRANCH) {
        trigger_error("Push ignored - branch: {$payloadData['ref']} (expected: refs/heads/" . TARGET_BRANCH . ")", E_USER_NOTICE);
        echo json_encode(['status' => 'ignored', 'message' => 'Branch does not match']);
        exit;
    }
    
    $result = deployFromGitHub($payloadData);
    
    http_response_code($result['status'] === 'success' ? 200 : 500);
    echo json_encode($result);
    
} catch (Exception $e) {
    trigger_error("Fatal error: " . $e->getMessage(), E_USER_ERROR);
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error', 'message' => $e->getMessage()]);
}
?>