<?php
/**
 * Syncs a ZIP file to a target directory with exclusion support
 * - Extracts ZIP contents to target directory, skipping excluded files/directories
 * - Removes files/directories in target that aren't in ZIP (except excluded ones)
 */
class ZipSync {
    private $zipPath;
    private $targetDir;
    private $sourceDir;
    private $exclusions;
    
    public function __construct($zipPath, $targetDir, $exclusions = [], $sourceDir = '') {
        $this->zipPath = $zipPath;
        $this->targetDir = rtrim($targetDir, '/\\');
        $this->sourceDir = $this->normalizePath($sourceDir);
        $this->exclusions = array_map([$this, 'normalizePath'], $exclusions);
    }
    
    public function sync() {
        if (!file_exists($this->zipPath)) {
            throw new Exception("ZIP file not found: {$this->zipPath}");
        }
        
        if (!is_dir($this->targetDir)) {
            if (!mkdir($this->targetDir, 0755, true)) {
                throw new Exception("Cannot create target directory: {$this->targetDir}");
            }
        }
        
        $zip = new ZipArchive();
        $result = $zip->open($this->zipPath);
        
        if ($result !== TRUE) {
            throw new Exception("Cannot open ZIP file: {$this->zipPath} (Error: $result)");
        }
        
        try {
            $zipFiles = $this->getZipFileList($zip);
            
            if (empty($zipFiles) && !empty($this->sourceDir)) {
                trigger_error("No files found in ZIP for source directory '{$this->sourceDir}'", E_USER_WARNING);
                return;
            }
            
            $this->extractZipFiles($zip, $zipFiles);
            $this->removeOrphanedFiles($zipFiles);
            
        } finally {
            $zip->close();
        }
    }
    
    private function getZipFileList($zip) {
        $files = [];
        $sourceDirPrefix = $this->sourceDir ? $this->sourceDir . '/' : '';
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if ($filename === false) continue;
            
            $normalizedPath = $this->normalizePath($filename);
            $relativePath = $normalizedPath;

            if (!empty($this->sourceDir)) {
                if (!str_starts_with($normalizedPath, $sourceDirPrefix)) {
                    continue;
                }
                $relativePath = substr($normalizedPath, strlen($sourceDirPrefix));
                if (empty($relativePath)) {
                    continue;
                }
            }

            if (!$this->isExcluded($relativePath)) {
                $files[] = $relativePath;
            }
        }
        
        return $files;
    }
    
    private function extractZipFiles($zip, $zipFiles) {
        $sourceDirPrefix = $this->sourceDir ? $this->sourceDir . '/' : '';
        
        foreach ($zipFiles as $relativePath) {
            $originalFilename = !empty($this->sourceDir) 
                ? $sourceDirPrefix . $relativePath 
                : $relativePath;
            
            $targetPath = $this->targetDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            
            $dir = dirname($targetPath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    trigger_error("Failed to create directory: $dir", E_USER_WARNING);
                    continue;
                }
            }
            
            if (!str_ends_with($originalFilename, '/')) {
                $fileContent = $zip->getFromName($originalFilename);
                if ($fileContent !== false) {
                    if (file_put_contents($targetPath, $fileContent) !== false) {
                        trigger_error("Extracted: $relativePath", E_USER_NOTICE);
                    } else {
                        trigger_error("Failed to extract file: $relativePath", E_USER_WARNING);
                    }
                } else {
                    trigger_error("Failed to read file from ZIP: $originalFilename", E_USER_WARNING);
                }
            } else {
                if (!is_dir($targetPath)) {
                    if (!mkdir($targetPath, 0755, true)) {
                        trigger_error("Failed to create directory: $relativePath", E_USER_WARNING);
                    }
                }
            }
        }
    }
    
    private function removeOrphanedFiles($zipFiles) {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $fileInfo) {
                $itemFullPath = $fileInfo->getPathname();
                $itemRelativePath = $this->getRelativePathFromTarget($itemFullPath);
                $normalizedRelativePath = $this->normalizePath($itemRelativePath);
                
                if ($this->isExcluded($normalizedRelativePath)) {
                    continue;
                }
                
                if ($fileInfo->isFile()) {
                    if (!in_array($normalizedRelativePath, $zipFiles)) {
                        if (unlink($itemFullPath)) {
                            trigger_error("Removed orphaned file: $itemRelativePath", E_USER_NOTICE);
                        } else {
                            trigger_error("Failed to remove file: $itemRelativePath", E_USER_WARNING);
                        }
                    }
                } else if ($fileInfo->isDir()) {
                    if ($this->isDirectoryEmpty($itemFullPath)) {
                        $dirWithSlash = $normalizedRelativePath . '/';
                        if (!in_array($normalizedRelativePath, $zipFiles) && !in_array($dirWithSlash, $zipFiles)) {
                            if (rmdir($itemFullPath)) {
                                trigger_error("Removed empty directory: $itemRelativePath", E_USER_NOTICE);
                            } else {
                                trigger_error("Failed to remove directory: $itemRelativePath", E_USER_WARNING);
                            }
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            trigger_error("Error scanning directory {$this->targetDir}: " . $e->getMessage(), E_USER_WARNING);
        }
    }

    private function getRelativePathFromTarget($fullPath) {
        $targetDirLength = strlen($this->targetDir);
        if (strpos($fullPath, $this->targetDir) === 0) {
            $relativePath = substr($fullPath, $targetDirLength);
            return ltrim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');
        }
        return $fullPath;
    }
    
    private function isExcluded($path) {
        foreach ($this->exclusions as $exclusion) {
            if ($path === $exclusion || str_starts_with($path, $exclusion . '/')) {
                return true;
            }
        }
        return false;
    }
    
    private function normalizePath($path) {
        return str_replace('\\', '/', trim($path, '/\\'));
    }
    
    private function isDirectoryEmpty($dir) {
        if (!is_readable($dir)) {
            return false;
        }
        
        $handle = opendir($dir);
        if ($handle === false) {
            return false;
        }
        
        while (($entry = readdir($handle)) !== false) {
            if ($entry !== '.' && $entry !== '..') {
                closedir($handle);
                return false;
            }
        }
        
        closedir($handle);
        return true;
    }
}
?>