<?php
/**
 * Simple file-based logger for webhook deployment
 */
class Logger {
    private $logFile;
    private $enableLogging;
    
    public function __construct($logFile = null, $enableLogging = true) {
        $this->enableLogging = $enableLogging;
        $this->logFile = $logFile ?: __DIR__ . '/deploy.log';
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Write log entry
     * 
     * @param string $message Log message
     * @param string $level Log level (INFO, WARNING, ERROR, DEBUG)
     * @param array $context Additional context data
     */
    public function writeLog($message, $level = 'INFO', $context = []) {
        if (!$this->enableLogging) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        
        $logEntry = sprintf(
            "[%s] [%s] %s",
            $timestamp,
            $level,
            $message
        );
        
        // Add context if provided
        if (!empty($context)) {
            $logEntry .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        
        $logEntry .= PHP_EOL;
        
        // Write to file with error handling
        if (file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            error_log("Failed to write to log file: " . $this->logFile);
        }
        
        // Also write to system error log for errors
        if ($level === 'ERROR') {
            error_log("Deploy Error: $message");
        }
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = []) {
        $this->writeLog($message, 'INFO', $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = []) {
        $this->writeLog($message, 'WARNING', $context);
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = []) {
        $this->writeLog($message, 'ERROR', $context);
    }
    
    /**
     * Log debug message
     */
    public function debug($message, $context = []) {
        $this->writeLog($message, 'DEBUG', $context);
    }
}