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
require_once __DIR__ . '/Logger.php';

// Initialize logger
$logFile = defined('LOG_FILE') && LOG_FILE ? LOG_FILE : __DIR__ . '/deploy.log';
$logger = new Logger($logFile, DEBUG_MODE);
ini_set('display_errors', 'Off');

function checkRequiredExtensions() {
    global $logger;
    
    $required = ['curl', 'zip', 'json'];
    $missing = [];
    
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    
    if (!empty($missing)) {
        $message = 'Missing required PHP extensions: ' . implode(', ', $missing);
        $logger->error($message);
        throw new Exception($message);
    }
    
    $logger->info('All required extensions are available');
}

function createUniqueTempDir($baseTempDir) {
    global $logger;
    
    $uniqueId = uniqid('deploy_', true) . '_' . getmypid();
    $uniqueTempDir = rtrim($baseTempDir, '/') . '/' . $uniqueId;
    
    if (!is_dir($uniqueTempDir)) {
        if (!mkdir($uniqueTempDir, 0755, true)) {
            $logger->error("Failed to create temporary directory: $uniqueTempDir");
            throw new Exception("Failed to create temporary directory: $uniqueTempDir");
        }
    }
    
    $logger->info("Created unique temporary directory: $uniqueTempDir");
    return $uniqueTempDir;
}

function cleanupTempDir($tempDir) {
    global $logger;
    
    if (is_dir($tempDir)) {
        $escapedPath = escapeshellarg($tempDir);
        $output = [];
        $returnCode = 0;
        exec("rm -rf $escapedPath 2>&1", $output, $returnCode);
        
        if ($returnCode !== 0) {
            $logger->warning("Failed to cleanup temp directory: " . implode("\n", $output));
        } else {
            $logger->info("Temp directory cleaned up successfully: $tempDir");
        }
    }
}

function validateGitHubWebhook($payload, $signature, $secret) {
    if (empty($signature)) {
        return false;
    }
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    return hash_equals($expected, $signature);
}

function validateRepository($payload) {
    global $logger;
    
    if (empty(REMOTE_REPOSITORY)) {
        $logger->error("REMOTE_REPOSITORY not configured - deployment rejected");
        return false;
    }
    
    $incomingRepo = $payload['repository']['full_name'] ?? '';
    
    if ($incomingRepo !== REMOTE_REPOSITORY) {
        $logger->warning("Repository mismatch", [
            'incoming' => $incomingRepo,
            'expected' => REMOTE_REPOSITORY
        ]);
        return false;
    }
    
    $logger->info("Repository validated: $incomingRepo");
    return true;
}

function downloadRepositoryZip($repoUrl, $branch, $tempDir) {
    global $logger;
    
    $logger->info("Downloading repository: $repoUrl (branch: $branch)");

    // URL to download ZIP
    $zipUrl = str_replace('.git', '', $repoUrl) . "/archive/refs/heads/$branch.zip";
    $zipFile = "$tempDir/repo.zip";
    
    $logger->info("Downloading from: $zipUrl");
    
    // Configure cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $zipUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'JesusTorresDev-Web-Deploy/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Connection timeout
    
    $zipContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || $zipContent === false) {
        $errorMsg = "Error downloading repository. HTTP Code: $httpCode";
        if ($curlError) {
            $errorMsg .= ", cURL Error: $curlError";
        }
        $logger->error($errorMsg, ['url' => $zipUrl, 'http_code' => $httpCode]);
        throw new Exception($errorMsg);
    }
    
    if (file_put_contents($zipFile, $zipContent) === false) {
        $logger->error("Error saving ZIP file to: $zipFile");
        throw new Exception("Error saving ZIP file");
    }
    
    $fileSize = filesize($zipFile);
    $logger->info("ZIP downloaded successfully", [
        'file_size' => $fileSize . ' bytes'
    ]);

    return $zipFile;
}

function syncToWebRoot($zipFile, $targetDir, $excludeList, $sourceDir = '') {
    global $logger;
    
    $logger->info("Starting ZipSync", [
        'zip_file' => $zipFile,
        'target_dir' => $targetDir,
        'source_dir' => $sourceDir ?: 'root',
        'exclusions' => $excludeList
    ]);
    
    if (!empty($excludeList)) {
        $logger->debug("Exclusion list", ['exclusions' => $excludeList]);
    }
    
    try {
        $startTime = microtime(true);
        $zipSync = new ZipSync($zipFile, $targetDir, $excludeList, $sourceDir);
        $zipSync->sync();

        $logger->info("ZipSync completed successfully");
        return ['status' => 'success'];
        
    } catch (Exception $e) {
        $logger->error("ZipSync error: " . $e->getMessage());
        throw new Exception("ZipSync error: " . $e->getMessage());
    }
}

function deployFromGitHub($payload) {
    global $logger;
    
    $tempDir = null;
    
    try {        
        $logger->info("=== STARTING DEPLOYMENT ===");
        $logger->info("Repository deployment details", [
            'repository' => $payload['repository']['full_name'],
            'branch' => $payload['ref'],
            'commit' => $payload['head_commit']['id'],
            'message' => $payload['head_commit']['message'],
            'pusher' => $payload['pusher']['name'] ?? 'unknown'
        ]);
        
        $repoUrl = 'https://github.com/' . REMOTE_REPOSITORY . '.git';
        $tempDir = createUniqueTempDir(TEMP_DIR);
        $zipFile = downloadRepositoryZip(
            $repoUrl,
            TARGET_BRANCH,
            $tempDir
        );
        
        // Get exclude list
        $excludeList = defined('EXCLUDE_LIST') ? EXCLUDE_LIST : [];
        
        // Synchronize using ZipSync
        $syncResult = syncToWebRoot($zipFile, WEB_ROOT, $excludeList, ZIP_DIR);
        
        // Clean temporary directory
        cleanupTempDir($tempDir);
        
        $logger->info("=== DEPLOYMENT COMPLETED SUCCESSFULLY ===");
        
        return [
            'status' => 'success', 
            'message' => 'Deployment completed successfully'
        ];
        
    } catch (Exception $e) {
        $logger->error("Deployment failed: " . $e->getMessage());
        
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
    $logger->info("Webhook request received", [
        'method' => $_SERVER['REQUEST_METHOD'],
        'event' => $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    checkRequiredExtensions();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        $logger->warning("Method not allowed: {$_SERVER['REQUEST_METHOD']}");
        die(json_encode(['error' => 'Method not allowed']));
    }

    $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
    if ($event !== 'push') {
        http_response_code(200);
        $logger->info("Event ignored: $event");
        die(json_encode(['status' => 'ignored', 'message' => 'Not a push event']));
    }
    
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    
    if (!validateGitHubWebhook($payload, $signature, WEBHOOK_SECRET)) {
        http_response_code(401);
        $logger->warning("Invalid webhook signature");
        die(json_encode(['error' => 'Invalid webhook signature']));
    }
    
    $payloadData = json_decode($payload, true);
    if (!$payloadData) {
        http_response_code(400);
        $logger->error("Invalid JSON payload", ['json_error' => json_last_error_msg()]);
        die(json_encode(['error' => 'Invalid JSON payload']));
    }

    if (!validateRepository($payloadData)) {
        http_response_code(200);
        $logger->warning("Repository validation failed");
        die(json_encode(['status' => 'ignored', 'message' => 'Repository not allowed']));
    }

    if ($payloadData['ref'] !== "refs/heads/" . TARGET_BRANCH) {
        $logger->info("Push ignored - wrong branch", [
            'received_branch' => $payloadData['ref'],
            'expected_branch' => "refs/heads/" . TARGET_BRANCH
        ]);
        http_response_code(200); // Consider 200 for ignored events
        die(json_encode(['status' => 'ignored', 'message' => 'Branch does not match']));
    }
    
    $result = deployFromGitHub($payloadData);
    
    http_response_code($result['status'] === 'success' ? 200 : 500);
    echo json_encode($result);
    
} catch (Exception $e) {
    $logger->error("Fatal error: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    http_response_code(500);
    $errorMessage = defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : 'Internal server error';
    echo json_encode(['error' => 'Internal server error', 'message' => $errorMessage]);
}
?>