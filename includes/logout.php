<?php
// logout.php

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'security/session_manager.php';

$reason = $_GET['reason'] ?? 'normal';

// Log logout reason
if (isset($_SESSION['user'])) {
    $log_file = __DIR__ . '/logs/session_logs.log';
    $log_entry = date('Y-m-d H:i:s') . " - User: " . $_SESSION['user'] .
        " - Logout reason: " . $reason . "\n";

    if (!file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX)) {
        error_log("Failed to write session log: " . $log_entry);
    }
}

logoutUser();
header('Location: login.php?message=logged_out');
exit();
