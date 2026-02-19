<?php
// Include Auto-Responsive System for Login/OTP
require_once __DIR__ . '/responsive/auto_responsive.php';

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';
// Include security session manager
if (file_exists('security/session_manager.php')) {
    include 'security/session_manager.php';
}

// Include PHPMailer from vendor folder
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Create logs directory if it doesn't exist
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Security configuration
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 1 * 60); // 1 minute

// OTP Configuration
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 1);
define('MAX_OTP_ATTEMPTS', 3);
define('OTP_LOCKOUT_MINUTES', 15);

// Email Configuration - Gmail SMTP
define('FROM_EMAIL', 'your-email@gmail.com'); // Your Gmail address
define('FROM_NAME', 'SLATE Freight System');
define('REPLY_TO', 'support@yourdomain.com');
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com'); // Your Gmail address
define('SMTP_PASSWORD', 'your-app-password'); // Gmail App Password
define('SMTP_SECURE', 'tls');

// If user is already logged in and OTP verified, redirect to dashboard
if (isset($_SESSION['user']) && isset($_SESSION['role']) && isset($_SESSION['otp_verified'])) {
    if (function_exists('validateSession') && !validateSession()) {
        logoutUser();
        header('Location: index.php?error=session_expired');
        exit();
    }
    header('Location: dashboard/index.php');
    exit();
}

// Initialize login attempts if not set
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt_time'] = 0;
}

// Handle OTP cancellation
if (isset($_GET['cancel_otp'])) {
    unset($_SESSION['otp_user_id']);
    unset($_SESSION['otp_username']);
    unset($_SESSION['otp_role']);
    unset($_SESSION['otp_name']);
    unset($_SESSION['otp_pending']);
    unset($_SESSION['debug_otp']);
    header('Location: index.php');
    exit();
}

// Check if we're in OTP verification stage
$isOTPStage = isset($_SESSION['otp_user_id']) && isset($_SESSION['otp_pending']);

// Process OTP verification if in OTP stage
if ($isOTPStage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_otp'])) {
    $enteredOTP = sanitizeInput($_POST['otp_code'] ?? '');

    if (empty($enteredOTP)) {
        $otpError = "Please enter the OTP code.";
    } else {
        $verification = verifyOTP($_SESSION['otp_user_id'], $enteredOTP);

        if ($verification['success']) {
            // OTP verified successfully
            $_SESSION['otp_verified'] = true;
            $_SESSION['user_id'] = $_SESSION['otp_user_id'];
            $_SESSION['user'] = $_SESSION['otp_username'];
            $_SESSION['role'] = $_SESSION['otp_role'];
            $_SESSION['name'] = $_SESSION['otp_name'];
            $_SESSION['theme'] = 'light';
            $_SESSION['LAST_ACTIVITY'] = time();
            $_SESSION['CREATED'] = time();

            // Clear OTP session data
            unset($_SESSION['otp_user_id']);
            unset($_SESSION['otp_username']);
            unset($_SESSION['otp_role']);
            unset($_SESSION['otp_name']);
            unset($_SESSION['otp_pending']);
            unset($_SESSION['debug_otp']);

            // Redirect to dashboard
            header('Location: dashboard/index.php');
            exit();
        } else {
            $otpError = $verification['message'];
        }
    }
}

// Process resend OTP request
if ($isOTPStage && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    $newOTP = generateOTP();
    if (storeOTP($_SESSION['otp_user_id'], $newOTP)) {
        // Send the new OTP to the user's email from database
        if (sendOTPEmail($_SESSION['otp_username'], $newOTP, $_SESSION['otp_name'], $_SESSION['otp_user_id'])) {
            $otpSuccess = "New OTP has been sent to your registered email address.";
            unset($_SESSION['debug_otp']);
        } else {
            // Email failed but do not force offline mode redirection
            $otpError = "Failed to send OTP email. Please check your internet connection or contact support.";
        }
    } else {
        $otpError = "Failed to generate new OTP. Please try again.";
    }
}

// -------------------------------------------------------------------------
// GACHA LOGIN HANDLING (OFFLINE MODE)
// -------------------------------------------------------------------------
if (isset($_POST['gacha_login'])) {

    $selectedRole = $_POST['gacha_role'] ?? 'hr_staff';
    
    // Mock Users for Offline Mode
    $mockUsers = [
        'admin' => [
            'id' => 999, 'username' => 'admin', 'role' => 'admin', 
            'name' => 'System Administrator', 'email' => 'admin@slatefreight.com'
        ],
        'hr_manager' => [
            'id' => 888, 'username' => 'hr_manager', 'role' => 'hr_manager', 
            'name' => 'Jane Doe (Manager)', 'email' => 'jane.doe@slatefreight.com'
        ],
        'hr_staff' => [
            'id' => 777, 'username' => 'hr_staff', 'role' => 'hr_staff', 
            'name' => 'John Smith (Staff)', 'email' => 'john.smith@slatefreight.com'
        ]
    ];
    
    $user = $mockUsers[$selectedRole] ?? $mockUsers['hr_staff'];

    // Set Session (Bypass DB/OTP)
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['theme'] = 'light';
    $_SESSION['login_attempts'] = 0;
    $_SESSION['otp_verified'] = true; // IMPORTANT: Bypass OTP
    $_SESSION['LAST_ACTIVITY'] = time();
    $_SESSION['CREATED'] = time();

    // Log & Redirect
    logLoginAttempt($user['username'], 'SUCCESS', 'Offline Gacha Login');
    header('Location: dashboard/index.php');
    exit();
}

// Process login if form is submitted (initial login)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isOTPStage && !isset($_POST['verify_otp']) && !isset($_POST['resend_otp'])) {
    // Check if user is temporarily locked out
    if (isAccountLockedOut()) {
        $error = "Too many failed login attempts. Please try again in " . getRemainingLockoutTime() . " minutes.";
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $terms = isset($_POST['terms']);

        // Validate input
        if (empty($username) || empty($password)) {
            $error = "Username and password are required!";
            logLoginAttempt($username, 'FAILED', 'Empty credentials');
            incrementLoginAttempts();
        } elseif (!validateUsername($username)) {
            $error = "Invalid username format!";
            logLoginAttempt($username, 'FAILED', 'Invalid username format');
            incrementLoginAttempts();
        } elseif (!$terms) {
            $error = "You must accept the Terms and Conditions to login!";
            logLoginAttempt($username, 'FAILED', 'Terms not accepted');
        } else {
            // Database authentication
            try {
                // Check if database connection is established
                if (!isset($pdo)) {
                    // Fallback database connection if config/db.php doesn't exist
                    $pdo = createFallbackDBConnection();
                }

                if ($pdo) {
                    // Prepare SQL statement to prevent SQL injection
                    $stmt = $pdo->prepare("SELECT id, username, password, name, role, status, email FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    // Check if user exists, is active, and password is correct
                    if ($user && $user['status'] === 'active') {
                        // Check if password is hashed (starts with $2y$) or plain text
                        $authenticated = false;

                        if (password_verify($password, $user['password'])) {
                            // Password is correct and hashed
                            $authenticated = true;
                            logLoginAttempt($username, 'SUCCESS', 'Login successful (hashed password)');
                        } elseif ($user['password'] === $password) {
                            // Password is correct but stored as plain text
                            $authenticated = true;

                            // Upgrade to hashed password for security
                            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                            $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $updateStmt->execute([$hashedPassword, $user['id']]);

                            logLoginAttempt($username, 'SUCCESS', 'Login successful (upgraded plain text to hashed)');
                        }

                        if ($authenticated) {
                            // Reset login attempts on successful login
                            $_SESSION['login_attempts'] = 0;
                            $_SESSION['last_attempt_time'] = 0;

                            // Generate and send OTP
                            $otpCode = generateOTP();
                            if (storeOTP($user['id'], $otpCode)) {
                                // Send OTP email to the user's registered email from database
                                $emailSent = sendOTPEmail($user['username'], $otpCode, $user['name'], $user['id']);

                                if (!$emailSent) {
                                    // Email failed, show error on login page instead of redirecting
                                    $error = "Login successful, but failed to send OTP email. System might be offline.";
                                    logLoginAttempt($username, 'WARNING', 'OTP email failed');
                                    // Clean up potential partial session if we can't proceed
                                    unset($_SESSION['otp_user_id']);
                                    unset($_SESSION['otp_username']);
                                    unset($_SESSION['otp_role']);
                                    unset($_SESSION['otp_name']);
                                    unset($_SESSION['otp_pending']);
                                } else {

                                // Store user data in session for OTP verification
                                $_SESSION['otp_user_id'] = $user['id'];
                                $_SESSION['otp_username'] = $user['username'];
                                $_SESSION['otp_role'] = $user['role'];
                                $_SESSION['otp_name'] = $user['name'];
                                $_SESSION['otp_pending'] = true;

                                    // Redirect to show OTP form immediately
                                    header('Location: index.php');
                                    exit();
                                }
                            } else {
                                $error = "Failed to generate OTP. Please try again.";
                                logLoginAttempt($username, 'FAILED', 'OTP generation failed');
                            }
                        } else {
                            $error = "Invalid username or password!";
                            logLoginAttempt($username, 'FAILED', 'Invalid credentials');
                            incrementLoginAttempts();
                        }
                    } else {
                        $error = "Invalid username or password!";
                        logLoginAttempt($username, 'FAILED', 'Invalid credentials or inactive account');
                        incrementLoginAttempts();
                    }
                } else {
                    $error = "Database connection failed. Please contact administrator.";
                    logLoginAttempt($username, 'FAILED', 'Database connection error');
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "Login temporarily unavailable. Please try again later.";
            }
        }
    }
}

// Security functions
function sanitizeInput($data)
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function validateUsername($username)
{
    return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username);
}

function isAccountLockedOut()
{
    if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $time_since_last_attempt = time() - $_SESSION['last_attempt_time'];
        return $time_since_last_attempt < LOCKOUT_DURATION;
    }
    return false;
}

function getRemainingLockoutTime()
{
    $time_elapsed = time() - $_SESSION['last_attempt_time'];
    $remaining = ceil((LOCKOUT_DURATION - $time_elapsed) / 60);
    return max(1, $remaining);
}

function incrementLoginAttempts()
{
    $_SESSION['login_attempts']++;
    $_SESSION['last_attempt_time'] = time();
}

function logLoginAttempt($username, $status, $details = '')
{
    $log_file = __DIR__ . '/logs/login_attempts.log';
    $log_entry = date('Y-m-d H:i:s') . " - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN') .
        " - Username: " . $username . " - Status: " . $status .
        " - Details: " . $details . "\n";

    if (!file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX)) {
        error_log("Failed to write login attempt: " . $log_entry);
    }
}

// Fallback database connection
function createFallbackDBConnection()
{
    try {
        // Default database configuration - UPDATE THESE FOR YOUR HOSTING
        $host = 'localhost';
        $dbname = 'slatefre_slate_db';
        $username = 'slatefre_db_user';
        $password = 'your_db_password';

        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Fallback DB connection failed: " . $e->getMessage());
        return null;
    }
}

// PHPMailer Email Function
function sendEmailUsingPHPMailer($toEmail, $subject, $message)
{
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;

        // Enable debug output (optional - comment out in production)
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;

        // Recipients
        $mail->setFrom(SMTP_USERNAME, FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->addReplyTo(REPLY_TO, FROM_NAME);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags($message);

        // Send email
        $mail->send();
        error_log("OTP email sent successfully to {$toEmail} using PHPMailer");
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: {$mail->ErrorInfo}");
        error_log("Failed to send OTP email to {$toEmail}");
        return false;
    }
}

// Fallback email function using basic mail()
function sendEmailUsingPHP($toEmail, $subject, $message)
{
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: " . FROM_NAME . " <" . FROM_EMAIL . ">" . "\r\n";
    $headers .= "Reply-To: " . REPLY_TO . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    $headers .= "X-Priority: 1" . "\r\n"; // High priority

    if (mail($toEmail, $subject, $message, $headers)) {
        error_log("OTP email sent successfully to {$toEmail} using mail()");
        return true;
    }

    error_log("Failed to send OTP email to {$toEmail} using mail()");
    return false;
}

// OTP Functions
function generateOTP()
{
    $characters = '0123456789';
    $otp = '';
    for ($i = 0; $i < OTP_LENGTH; $i++) {
        $otp .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $otp;
}

function getUserEmail($userId)
{
    global $pdo;

    if (!$pdo) {
        $pdo = createFallbackDBConnection();
    }

    try {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['email'])) {
            return $user['email'];
        }

        return null;
    } catch (PDOException $e) {
        error_log("Get user email error: " . $e->getMessage());
        return null;
    }
}

function sendOTPEmail($username, $otpCode, $name, $userId)
{
    // Get the user's email from database
    $userEmail = getUserEmail($userId);

    // If user doesn't have an email in database, use fallback
    if (!$userEmail) {
        error_log("No email found for user ID: {$userId}. Using fallback email.");
        $userEmail = 'fallback@yourdomain.com'; // Change this to a valid fallback email
    }

    // Log the email being used
    error_log("Sending OTP to email: {$userEmail} for user: {$username}");

    $subject = "Your SLATE Verification Code";
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px; }
            .container { background: white; padding: 30px; border-radius: 10px; max-width: 600px; margin: 0 auto; }
            .header { background: linear-gradient(135deg, #0072ff, #00c6ff); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { padding: 20px; }
            .otp-code { font-size: 32px; font-weight: bold; text-align: center; color: #0072ff; margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px; letter-spacing: 5px; }
            .footer { text-align: center; color: #666; font-size: 12px; margin-top: 20px; }
            .info-box { background: #e3f2fd; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #2196f3; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>SLATE System</h1>
                <h2>Verification Code</h2>
            </div>
            <div class='content'>
                <div class='info-box'>
                    <strong>Login attempt detected:</strong><br>
                    Username: <strong>{$username}</strong><br>
                    Name: <strong>{$name}</strong><br>
                    Time: " . date('Y-m-d H:i:s') . "
                </div>
                
                <p>Hello <strong>{$name}</strong>,</p>
                <p>You are attempting to login to the SLATE Freight Management System. Use the verification code below to complete your login:</p>
                
                <div class='otp-code'>{$otpCode}</div>
                
                <p><strong>Login Details:</strong></p>
                <p><strong>Username:</strong> {$username}<br>
                <strong>Name:</strong> {$name}<br>
                <strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
                
                <p>This code will expire in " . OTP_EXPIRY_MINUTES . " minutes.</p>
                <p>If you did not attempt to login, please contact your system administrator immediately.</p>
            </div>
            <div class='footer'>
                <p>This is an automated security message from SLATE Freight Management System.</p>
                <p>Please do not reply to this email.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    // Try PHPMailer first, fall back to basic mail() if it fails
    if (sendEmailUsingPHPMailer($userEmail, $subject, $message)) {
        return true;
    }

    // If PHPMailer fails, try basic mail function
    error_log("PHPMailer failed, falling back to basic mail()");
    return sendEmailUsingPHP($userEmail, $subject, $message);
}

function storeOTP($userId, $otpCode)
{
    global $pdo;

    if (!$pdo) {
        $pdo = createFallbackDBConnection();
    }

    if ($pdo) {
        $expiryTime = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET otp_code = ?, otp_expires = ?, otp_attempts = 0, otp_locked_until = NULL 
                WHERE id = ?
            ");
            return $stmt->execute([$otpCode, $expiryTime, $userId]);
        } catch (PDOException $e) {
            error_log("OTP storage error: " . $e->getMessage());
            return false;
        }
    }

    return false;
}

function verifyOTP($userId, $enteredOTP)
{
    global $pdo;

    if (!$pdo) {
        $pdo = createFallbackDBConnection();
    }

    if (!$pdo) {
        return ['success' => false, 'message' => 'Database connection failed'];
    }

    // Check if OTP is locked
    $user = getUserOTPStatus($userId);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }

    // Check if OTP is locked
    if ($user['otp_locked_until'] && strtotime($user['otp_locked_until']) > time()) {
        $lockTime = ceil((strtotime($user['otp_locked_until']) - time()) / 60);
        return ['success' => false, 'message' => "OTP verification locked. Try again in {$lockTime} minutes."];
    }

    // Check if OTP exists and is not expired
    if (!$user['otp_code'] || !$user['otp_expires']) {
        return ['success' => false, 'message' => 'No OTP found. Please request a new one.'];
    }

    if (strtotime($user['otp_expires']) < time()) {
        return ['success' => false, 'message' => 'OTP has expired. Please request a new one.'];
    }

    // Verify OTP code
    if ($user['otp_code'] === $enteredOTP) {
        // Clear OTP data on successful verification
        clearOTP($userId);
        return ['success' => true, 'message' => 'OTP verified successfully'];
    } else {
        // Increment failed attempts
        $newAttempts = $user['otp_attempts'] + 1;
        updateOTPAttempts($userId, $newAttempts);

        if ($newAttempts >= MAX_OTP_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', strtotime('+' . OTP_LOCKOUT_MINUTES . ' minutes'));
            lockOTP($userId, $lockUntil);
            return ['success' => false, 'message' => 'Too many failed OTP attempts. Account locked for ' . OTP_LOCKOUT_MINUTES . ' minutes.'];
        }

        $remaining = MAX_OTP_ATTEMPTS - $newAttempts;
        return ['success' => false, 'message' => "Invalid OTP. {$remaining} attempts remaining."];
    }
}

function getUserOTPStatus($userId)
{
    global $pdo;

    if (!$pdo) {
        $pdo = createFallbackDBConnection();
    }

    if ($pdo) {
        $stmt = $pdo->prepare("
            SELECT otp_code, otp_expires, otp_attempts, otp_locked_until 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    return false;
}

function updateOTPAttempts($userId, $attempts)
{
    global $pdo;

    if (!$pdo) {
        $pdo = createFallbackDBConnection();
    }

    if ($pdo) {
        $stmt = $pdo->prepare("UPDATE users SET otp_attempts = ? WHERE id = ?");
        return $stmt->execute([$attempts, $userId]);
    }

    return false;
}

function lockOTP($userId, $lockUntil)
{
    global $pdo;

    if (!$pdo) {
        $pdo = createFallbackDBConnection();
    }

    if ($pdo) {
        $stmt = $pdo->prepare("UPDATE users SET otp_locked_until = ? WHERE id = ?");
        return $stmt->execute([$lockUntil, $userId]);
    }

    return false;
}

function clearOTP($userId)
{
    global $pdo;

    if (!$pdo) {
        $pdo = createFallbackDBConnection();
    }

    if ($pdo) {
        $stmt = $pdo->prepare("
            UPDATE users 
            SET otp_code = NULL, otp_expires = NULL, otp_attempts = 0, otp_locked_until = NULL 
            WHERE id = ?
        ");
        return $stmt->execute([$userId]);
    }

    return false;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="preload" href="resources/logo.png" as="image">
    <title>Login - SLATE System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            color: white;
            line-height: 1.6;
        }

        .main-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .login-container {
            width: 100%;
            max-width: 75rem;
            display: flex;
            background: rgba(31, 42, 56, 0.8);
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 0.625rem 1.875rem rgba(0, 0, 0, 0.3);
        }

        .welcome-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2.5rem;
            background: linear-gradient(135deg, rgba(0, 114, 255, 0.2), rgba(0, 198, 255, 0.2));
        }

        .welcome-panel h1 {
            font-size: 2.25rem;
            font-weight: 700;
            color: #ffffff;
            text-shadow: 0.125rem 0.125rem 0.5rem rgba(0, 0, 0, 0.6);
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .login-panel {
            width: 25rem;
            padding: 3.75rem 2.5rem;
            background: rgba(22, 33, 49, 0.95);
        }

        .login-box {
            width: 100%;
            text-align: center;
        }

        .login-box img {
            width: 6.25rem;
            height: auto;
            margin-bottom: 1.25rem;
        }

        .login-box h2 {
            margin-bottom: 1.5625rem;
            color: #ffffff;
            font-size: 1.75rem;
        }

        .login-box form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .login-box input[type="text"],
        .login-box input[type="password"] {
            width: 100%;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.375rem;
            color: white;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .login-box input:focus {
            outline: none;
            border-color: #00c6ff;
            box-shadow: 0 0 0 0.125rem rgba(0, 198, 255, 0.2);
        }

        .login-box input::placeholder {
            color: rgba(160, 160, 160, 0.8);
        }

        .login-box button {
            padding: 0.75rem;
            background: linear-gradient(to right, #0072ff, #00c6ff);
            border: none;
            border-radius: 0.375rem;
            font-weight: 600;
            font-size: 1rem;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .login-box button:hover {
            background: linear-gradient(to right, #0052cc, #009ee3);
            transform: translateY(-0.125rem);
            box-shadow: 0 0.3125rem 0.9375rem rgba(0, 0, 0, 0.2);
        }

        .login-box button:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .error-message {
            color: #ff6b6b;
            background: rgba(255, 107, 107, 0.1);
            padding: 0.75rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }

        .success-message {
            color: #4CAF50;
            background: rgba(76, 175, 80, 0.1);
            padding: 0.75rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }

        .otp-info {
            background: rgba(0, 198, 255, 0.1);
            border: 1px solid rgba(0, 198, 255, 0.3);
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        .otp-timer {
            font-weight: bold;
            color: #00c6ff;
            font-size: 1.1rem;
        }

        .otp-input {
            text-align: center;
            letter-spacing: 0.5rem;
            font-size: 1.2rem;
            font-weight: bold;
        }

        .button-group {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .button-group button {
            flex: 1;
        }

        .resend-btn {
            background: #6c757d !important;
        }

        .resend-btn:hover {
            background: #5a6268 !important;
        }

        .debug-otp {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        .debug-otp strong {
            color: #ffc107;
            font-size: 1.1rem;
        }

        footer {
            text-align: center;
            padding: 1.25rem;
            background: rgba(0, 0, 0, 0.2);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.875rem;
        }

        /* Updated Professional Loading Animation */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.85);
            display: none;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
            transition: opacity 0.3s ease;
        }

        .loading-container {
            text-align: center;
            background: rgba(255, 255, 255, 0.05);
            padding: 2rem;
            border-radius: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .spinner-ring {
            display: inline-block;
            width: 64px;
            height: 64px;
            position: relative;
        }
        .spinner-ring div {
            box-sizing: border-box;
            display: block;
            position: absolute;
            width: 51px;
            height: 51px;
            margin: 6px;
            border: 3px solid #00c6ff;
            border-radius: 50%;
            animation: spinner-ring 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            border-color: #00c6ff transparent transparent transparent;
        }
        .spinner-ring div:nth-child(1) { animation-delay: -0.45s; }
        .spinner-ring div:nth-child(2) { animation-delay: -0.3s; }
        .spinner-ring div:nth-child(3) { animation-delay: -0.15s; }

        @keyframes spinner-ring {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: white;
            font-size: 1.1rem;
            margin-top: 1.5rem;
            font-weight: 500;
            letter-spacing: 0.5px;
            text-align: center;
        }

        /* Button Loading State */
        .btn-loading {
            position: relative;
            color: transparent !important;
            pointer-events: none;
        }
        .btn-loading::after {
            content: "";
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid #ffffff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: button-spin 0.8s linear infinite;
        }
        
        @keyframes button-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* MOBILE RESPONSIVE FIX */
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                margin: 1rem;
                max-width: 100%;
            }

            .welcome-panel {
                padding: 2rem 1rem;
                min-height: auto;
                flex: none; /* Don't expand */
            }

            .welcome-panel h1 {
                font-size: 1.5rem;
                margin-bottom: 0.5rem;
            }

            .login-panel {
                width: 100%;
                padding: 2rem 1.5rem;
            }

            .main-container {
                padding: 1rem;
                align-items: flex-start; /* prevent centering vertically if content is tall */
            }
            
            /* Hide excessive branding on very small screens if needed */
            .logo-animation { 
                /* Assuming there might be a logo animation to scale down */
                transform: scale(0.8);
            }
        }
    </style>
</head>

<body>
    <div class="main-container">
        <div class="login-container">
            <div class="welcome-panel">
                <h1>FREIGHT MANAGEMENT SYSTEM</h1>
            </div>

            <div class="login-panel">
                <div class="login-box">
                    <img src="resources/logo.png" alt="SLATE Logo" onerror="this.style.display='none'">

                    <?php if ($isOTPStage): ?>
                        <!-- OTP Verification Form -->
                        <h2>Verify Your Identity</h2>

                        <?php if (isset($otpSuccess)): ?>
                            <div class="success-message"><?php echo $otpSuccess; ?></div>
                        <?php endif; ?>

                        <?php if (isset($otpError)): ?>
                            <div class="error-message"><?php echo $otpError; ?></div>
                        <?php endif; ?>

                        <div class="otp-info">
                            <p>Enter the 6-digit verification code sent to your registered email address.</p>
                            <p class="otp-timer">Valid for <?php echo OTP_EXPIRY_MINUTES; ?> minutes</p>
                        </div>

                        <form method="POST" id="otpForm">
                            <input type="text" name="otp_code" placeholder="Enter OTP Code" required
                                maxlength="6" pattern="[0-9]{6}" title="Enter 6-digit OTP code"
                                class="otp-input" autofocus autocomplete="off">

                                <button type="submit" name="verify_otp" id="verifyBtn">Verify OTP</button>
                                <button type="submit" name="resend_otp" class="resend-btn" id="resendBtn" formnovalidate>Resend OTP</button>
                            </div>
                        </form>

                        <div style="margin-top: 1rem; text-align: center;">
                            <a href="?cancel_otp=true" style="color: #00c6ff; text-decoration: none;">← Back to Login</a>
                        </div>

                    <?php else: ?>
                        <!-- Regular Login Form -->
                        <h2>SLATE Login</h2>

                        <?php if (isset($error)): ?>
                            <div class="error-message"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" id="loginForm">
                            <input type="text" name="username" placeholder="Username" required
                                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                                pattern="[a-zA-Z0-9_]{3,20}" title="Username must be 3-20 characters (letters, numbers, underscore)">
                            <input type="password" name="password" placeholder="Password" required
                                minlength="8" title="Password must be at least 8 characters">

                            <div class="checkbox-group">
                                <input type="checkbox" id="terms" name="terms" required <?php echo isset($_POST['terms']) ? 'checked' : ''; ?>>
                                <label for="terms">I agree to the Terms and Conditions</label>
                            </div>

                            <button type="submit" id="loginButton">Log In</button>
                            

                        </form>

                        <?php if (isAccountLockedOut()): ?>
                            <div style="margin-top: 1rem; color: #ff6b6b; font-size: 0.9rem;">
                                Account temporarily locked. Try again in <?php echo getRemainingLockoutTime(); ?> minutes.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <footer>
        &copy; <span id="currentYear"></span> SLATE Freight Management System. All rights reserved.
    </footer>

    <!-- Professional Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-container">
            <div class="spinner-ring"><div></div><div></div><div></div><div></div></div>
            <div class="loading-text" id="loadingText">Authenticating...</div>
        </div>
    </div>

    <script>
        document.getElementById('currentYear').textContent = new Date().getFullYear();

        document.addEventListener('DOMContentLoaded', function() {
// Update OTP Configuration
    const otpInput = document.querySelector('input[name="otp_code"]');
    if (otpInput) {
        otpInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        otpInput.focus();
    }

    // Resend OTP Countdown Timer
    const resendBtn = document.getElementById('resendBtn');
    let resendTimer = 60; // 60 seconds matching the 1 minute expiry
    
    if (resendBtn) {
        // Disable initially
        resendBtn.disabled = true;
        resendBtn.classList.add('disabled'); // Add styling class if needed
        
        // Update text function
        const updateButtonText = () => {
            if (resendTimer > 0) {
                resendBtn.innerText = `Resend OTP (${resendTimer}s)`;
                resendTimer--;
                setTimeout(updateButtonText, 1000);
            } else {
                resendBtn.disabled = false;
                resendBtn.classList.remove('disabled');
                resendBtn.innerText = 'Resend OTP';
            }
        };
        
        // Start countdown
        updateButtonText();
    }

    // Function to show loading state
    const showLoading = (btn, text = 'Loading...') => {
        // Add button loading state
        if(btn) {
            btn.classList.add('btn-loading');
            // Store original text just in case (though page usually reloads)
            btn.dataset.originalText = btn.innerText;
        }

        // Show Overlay
        const overlay = document.getElementById('loadingOverlay');
        const loadingText = document.getElementById('loadingText');
        if(overlay && loadingText) {
            loadingText.innerText = text;
            overlay.style.display = 'flex';
        }
    };

    // Handle Login Form
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const username = document.querySelector('input[name="username"]').value.trim();
            const password = document.querySelector('input[name="password"]').value.trim();
            const terms = document.querySelector('input[name="terms"]').checked;

            if (username && password && terms) {
                const btn = document.getElementById('loginButton');
                showLoading(btn, 'Verifying Credentials...');
            } else {
                // Prevent submission if fields missing (HTML5 should catch this, but good fallback)
                 // e.preventDefault(); 
            }
        });
    }

    // Handle OTP Form
    const otpForm = document.getElementById('otpForm');
    if(otpForm) {
        otpForm.addEventListener('submit', function(e) {
            // Determine which button was clicked
            const verifyBtn = document.getElementById('verifyBtn');
            const resendBtn = document.getElementById('resendBtn');
            
            // Simple check - in actual submit event, it's harder to know which button triggered it without click listeners
            // But we can assume verification if it wasn't the resend button explicitly tracked
            
            setTimeout(() => {
                 // We delay slightly to let the browser register the submit
                 const btn = document.activeElement; // Get the button that was clicked
                 if(btn && btn.name === 'resend_otp') {
                     showLoading(btn, 'Sending New OTP...');
                 } else {
                     showLoading(verifyBtn, 'Validating Code...');
                 }
            }, 0);
        });
    }
        });
    </script>
    <!-- Professional Offline Access Modal -->
    <div id="gachaModal" class="gacha-modal">
        <div class="gacha-content">
            <div class="gacha-header">
                <div class="offline-icon"><i class="fas fa-shield-alt"></i></div>
                <h3>Offline Security Check</h3>
                <p>Verify identity to access local environment</p>
            </div>
            
            <div class="access-panel" style="height: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem; background: transparent; border: none; box-shadow: none;">
                
                <div class="form-group">
                    <label style="color: #94a3b8; font-size: 0.85rem; display: block; margin-bottom: 0.5rem; text-align: left;">Select Access Role</label>
                    <select id="offlineRoleSelect" style="width: 100%; padding: 0.75rem; background: #0f172a; border: 1px solid #334155; color: white; border-radius: 0.375rem; outline: none;">
                        <option value="hr_staff">HR Operator (Staff)</option>
                        <option value="hr_manager">HR Manager</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>



                
            </div>

            <div class="gacha-actions">
                <button id="offlineLoginBtn" class="spin-btn">Verify & Enter Mode</button>
                <button id="closeGacha" class="cancel-btn">Cancel</button>
            </div>
        </div>
    </div>

    <form id="gachaForm" method="POST" style="display:none;">
        <input type="hidden" name="gacha_login" value="1">
        <input type="hidden" name="gacha_role" id="gachaRoleInput">
        <input type="hidden" name="captcha_input" id="gachaCaptchaInput">
    </form>

    <style>
        .gacha-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.95);
            z-index: 10000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(10px);
            font-family: 'Segoe UI', monospace;
        }

        .gacha-content {
            background: #1e293b;
            width: 100%;
            max-width: 450px;
            border-radius: 0.5rem;
            padding: 2.5rem;
            border: 1px solid #334155;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .gacha-content::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
        }

        .gacha-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .offline-icon {
            font-size: 2.5rem;
            color: #94a3b8;
            margin-bottom: 1rem;
        }

        .gacha-header h3 {
            color: #f8fafc;
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .gacha-header p {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .access-panel {
            position: relative;
            height: 80px; /* Reduced specific item height */
            background: #0f172a;
            border-radius: 0.375rem;
            overflow: hidden;
            margin-bottom: 1.5rem;
            border: 1px solid #334155;
            box-shadow: inset 0 2px 4px 0 rgba(0, 0, 0, 0.3);
        }

        .access-window {
            height: 100%;
            width: 100%;
            position: relative;
        }

        .access-strip {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            /* Transition handled by JS */
        }

        .access-item {
            height: 80px; /* Matching panel height */
            display: flex;
            align-items: center;
            justify-content: flex-start; /* Align left */
            padding: 0 1.5rem;
            border-bottom: 1px solid #1e293b;
            color: #94a3b8;
            transition: color 0.3s;
        }
        
        .access-item.active {
            background: rgba(59, 130, 246, 0.1);
            color: #fff;
        }

        .access-item i {
            font-size: 1.25rem;
            width: 30px;
            margin-right: 1rem;
            color: inherit;
        }

        .access-item .role-info {
            text-align: left;
        }

        .access-item .role-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: inherit;
        }
        
        .access-item .role-desc {
            font-size: 0.75rem;
            color: #64748b;
        }

        /* The visual "selection" line */
        .scan-line {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            border: 2px solid #3b82f6;
            border-radius: 0.375rem;
            pointer-events: none;
            z-index: 10;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.2);
        }

        .status-text {
            text-align: center;
            font-size: 0.75rem;
            color: #64748b;
            margin-bottom: 1.5rem;
            font-family: monospace;
        }

        .spin-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 0.375rem;
            cursor: pointer;
            width: 100%;
            margin-bottom: 0.75rem;
            transition: all 0.2s;
        }

        .spin-btn:hover {
            background: #2563eb;
        }

        .spin-btn:disabled {
            background: #475569;
            cursor: wait;
        }

        .cancel-btn {
            background: transparent;
            border: none;
            color: #64748b;
            padding: 0.5rem;
            cursor: pointer;
            font-size: 0.85rem;
            width: 100%;
        }
        .cancel-btn:hover { color: #94a3b8; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements
            const gachaBtn = document.getElementById('gachaBtn');
            const gachaModal = document.getElementById('gachaModal');
            const closeGacha = document.getElementById('closeGacha');
            const offlineLoginBtn = document.getElementById('offlineLoginBtn');
            const offlineRoleSelect = document.getElementById('offlineRoleSelect');
            const gachaRoleInput = document.getElementById('gachaRoleInput');
            const gachaForm = document.getElementById('gachaForm');

            if(gachaBtn) {
                gachaBtn.addEventListener('click', function(e) {
                    e.preventDefault(); 
                    if(gachaModal) gachaModal.style.display = 'flex';
                });
            }

            if(closeGacha) {
                closeGacha.addEventListener('click', function() {
                    if(gachaModal) gachaModal.style.display = 'none';
                });
            }

            if(offlineLoginBtn) {
                offlineLoginBtn.addEventListener('click', function() {
                    // Proceed
                    offlineLoginBtn.disabled = true;
                    offlineLoginBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Authenticating...';

                    setTimeout(() => {
                        if(gachaRoleInput && gachaForm && offlineRoleSelect) {
                            gachaRoleInput.value = offlineRoleSelect.value;
                            gachaForm.submit();
                        }
                    }, 800);
                });
            }
        });
    </script>
</body>

</html>