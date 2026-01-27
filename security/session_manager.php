<?php
// session_manager.php

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout configuration - CHANGED TO 1 MINUTE
define('SESSION_TIMEOUT', 1 * 60); // 1 minute (changed from 30 minutes)
define('SESSION_WARNING_TIME', 30); // 30 seconds warning (changed from 5 minutes)
define('COUNTDOWN_DURATION', 10); // 10 second countdown

function checkSessionTimeout()
{
    if (isset($_SESSION['LAST_ACTIVITY'])) {
        $session_lifetime = time() - $_SESSION['LAST_ACTIVITY'];

        // If session is about to expire, set warning flag
        if ($session_lifetime > (SESSION_TIMEOUT - SESSION_WARNING_TIME)) {
            $_SESSION['session_warning'] = true;
            $_SESSION['time_remaining'] = SESSION_TIMEOUT - $session_lifetime;
        }

        // If session has expired, log out
        if ($session_lifetime > SESSION_TIMEOUT) {
            return false;
        }
    }

    // Update last activity time
    $_SESSION['LAST_ACTIVITY'] = time();
    return true;
}

function validateSession()
{
    return isset($_SESSION['user_id'], $_SESSION['user'], $_SESSION['role']) && checkSessionTimeout();
}

function logoutUser()
{
    // Clear all session variables
    $_SESSION = array();

    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    // Destroy session
    session_destroy();
}
