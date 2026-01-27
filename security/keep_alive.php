<?php
// keep_alive.php

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
    echo json_encode(['status' => 'success', 'message' => 'Session extended']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No active session']);
}
