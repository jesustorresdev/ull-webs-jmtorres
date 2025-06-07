<?php
/**
 * PHP Extensions Lister
 * Lists all available PHP extensions with detailed information
 */

// Set content type to HTML for better formatting
header('Content-Type: text/html; charset=UTF-8');

// Function to format extension information
function formatExtensionInfo($extension) {
    $info = [];
    
    // Get extension version if available
    $version = phpversion($extension);
    if ($version) {
        $info[] = "Version: " . $version;
    }
    
    // Check if extension is loaded
    $loaded = extension_loaded($extension) ? "Yes" : "No";
    $info[] = "Loaded: " . $loaded;
    
    return $info;
}

// Function to get extension functions
function getExtensionFunctions($extension) {
    $functions = get_extension_funcs($extension);
    return $functions ? count($functions) : 0;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP Extensions List</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .stats {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .extension-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        .extension-card {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            background: #fafafa;
        }
        .extension-name {
            font-weight: bold;
            color: #2c5aa0;
            font-size: 16px;
            margin-bottom: 8px;
        }
        .extension-info {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
        }
        .loaded {
            background: #d4edda;
            border-color: #c3e6cb;
        }
        .not-loaded {
            background: #f8d7da;
            border-color: #f5c6cb;
        }
        .filter-section {
            margin-bottom: 20px;
            text-align: center;
        }
        .filter-btn {
            padding: 8px 15px;
            margin: 0 5px;
            border: 1px solid #007bff;
            background: white;
            color: #007bff;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }
        .filter-btn.active {
            background: #007bff;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 PHP Extensions</h1>
        
        <?php
        // Get all loaded extensions
        $loadedExtensions = get_loaded_extensions();
        sort($loadedExtensions);
        
        // Get PHP version
        $phpVersion = phpversion();
        
        // Calculate statistics
        $totalExtensions = count($loadedExtensions);
        $coreExtensions = 0;
        $thirdPartyExtensions = 0;
        
        // Categorize extensions (basic categorization)
        $coreExtensionsList = ['Core', 'standard', 'SPL', 'Reflection', 'pcre', 'date', 'filter'];
        
        foreach ($loadedExtensions as $ext) {
            if (in_array($ext, $coreExtensionsList)) {
                $coreExtensions++;
            } else {
                $thirdPartyExtensions++;
            }
        }
        ?>
        
        <div class="stats">
            <strong>PHP Version:</strong> <?php echo $phpVersion; ?> | 
            <strong>Total Extensions:</strong> <?php echo $totalExtensions; ?> | 
            <strong>Core:</strong> <?php echo $coreExtensions; ?> | 
            <strong>Additional:</strong> <?php echo $thirdPartyExtensions; ?>
        </div>
        
        <div class="filter-section">
            <a href="?filter=all" class="filter-btn <?php echo (!isset($_GET['filter']) || $_GET['filter'] == 'all') ? 'active' : ''; ?>">All Extensions</a>
            <a href="?filter=core" class="filter-btn <?php echo (isset($_GET['filter']) && $_GET['filter'] == 'core') ? 'active' : ''; ?>">Core Only</a>
            <a href="?filter=additional" class="filter-btn <?php echo (isset($_GET['filter']) && $_GET['filter'] == 'additional') ? 'active' : ''; ?>">Additional Only</a>
        </div>
        
        <div class="extension-grid">
            <?php
            $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
            
            foreach ($loadedExtensions as $extension) {
                $isCore = in_array($extension, $coreExtensionsList);
                
                // Apply filter
                if ($filter == 'core' && !$isCore) continue;
                if ($filter == 'additional' && $isCore) continue;
                
                $info = formatExtensionInfo($extension);
                $functionCount = getExtensionFunctions($extension);
                $isLoaded = extension_loaded($extension);
                $cardClass = $isLoaded ? 'loaded' : 'not-loaded';
                ?>
                <div class="extension-card <?php echo $cardClass; ?>">
                    <div class="extension-name"><?php echo htmlspecialchars($extension); ?></div>
                    <div class="extension-info">
                        <?php foreach ($info as $infoItem): ?>
                            <?php echo htmlspecialchars($infoItem); ?><br>
                        <?php endforeach; ?>
                        Functions: <?php echo $functionCount; ?><br>
                        Type: <?php echo $isCore ? 'Core' : 'Additional'; ?>
                    </div>
                </div>
                <?php
            }
            ?>
        </div>
        
        <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 5px; font-size: 12px; color: #666;">
            <strong>Note:</strong> This script shows currently loaded extensions. Some extensions may be compiled but not loaded. 
            Generated on <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>
</body>
</html>