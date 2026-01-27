<?php
// auto-responsive.php - One-line include for automatic responsive design
// Usage: include 'auto-responsive.php';

// Define constants
define('AUTO_RESPONSIVE_PATH', __DIR__);

// Include the main responsive system
require_once AUTO_RESPONSIVE_PATH . '/responsive.php';

// Shortcut function for quick responsive checks
function is_mobile_responsive()
{
    return AutoResponsiveSystem::isMobile();
}

function get_device_responsive()
{
    return AutoResponsiveSystem::getDeviceType();
}

// Output viewport meta tag if not already present
if (!headers_sent()) {
    $hasViewport = false;
    $headers = headers_list();
    foreach ($headers as $header) {
        if (stripos($header, 'viewport') !== false) {
            $hasViewport = true;
            break;
        }
    }

    if (!$hasViewport && !isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">';
    }
}
