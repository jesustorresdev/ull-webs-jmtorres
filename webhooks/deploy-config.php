<?php
/**
 * GitHub Webhook Deploy Configuration
 * 
 * Copy this file and customize it for your environment.
 * Make sure to keep this file secure and out of public access.
 */

// Secret token for webhook validation (must match GitHub webhook secret)
define('WEBHOOK_SECRET', 'PewMc6h6KdFmWvYaV04GOMHLa0gryFMOgPmGcP5dEoYGQsmVZxzU92hwyQDpQWz9');

// Repository to deploy from (format: 'username/repository-name')
define('REMOTE_REPOSITORY', 'jesustorresdev/ull-webs-jmtorres');

// Target branch that triggers deployment
define('TARGET_BRANCH', 'gh-pages');

// Directory inside the ZIP file to extract
define('ZIP_DIR', 'ull-webs-jmtorres-' . TARGET_BRANCH);

// Web root directory where the site will be deployed
define('WEB_ROOT', dirname(dirname(__FILE__)));

// Temporary directory for downloads and extraction
define('TEMP_DIR', '/tmp/webhook_deploy');

// Log file path (make sure web server has write permissions)
define('LOG_FILE', WEB_ROOT . '/webhook_deploy.log');

// Enable or disable logging (true/false)
define('ENABLE_LOGS', true);

// Files and directories to exclude from sync
// Use patterns supported by rsync --exclude
define('EXCLUDE_LIST', [
    'webhooks'
]);
?>