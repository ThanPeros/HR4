<?php
// OTP Configuration
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 10);
define('MAX_OTP_ATTEMPTS', 3);
define('OTP_LOCKOUT_MINUTES', 15);

class OTPManager
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    // Generate random OTP code
    public function generateOTP()
    {
        $characters = '0123456789';
        $otp = '';
        for ($i = 0; $i < OTP_LENGTH; $i++) {
            $otp .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $otp;
    }

    // Send OTP (simulated - integrate with your SMS/email service)
    public function sendOTP($username, $otpCode)
    {
        // In a real implementation, integrate with:
        // - SMS gateway (Twilio, Nexmo, etc.)
        // - Email service
        // - Authenticator app

        // For demo purposes, we'll log the OTP
        $log_message = "OTP for {$username}: {$otpCode} (Valid for " . OTP_EXPIRY_MINUTES . " minutes)";
        error_log($log_message);

        // Simulate sending - return true for success
        return true;

        // Example for actual implementation:
        /*
        // SMS Example with Twilio
        $client = new Twilio\Rest\Client($account_sid, $auth_token);
        $message = $client->messages->create(
            $phone_number,
            [
                'from' => $twilio_number,
                'body' => "Your verification code is: {$otpCode}"
            ]
        );
        return $message->sid;
        */
    }

    // Store OTP in database
    public function storeOTP($userId, $otpCode)
    {
        $expiryTime = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

        try {
            $stmt = $this->pdo->prepare("
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

    // Verify OTP
    public function verifyOTP($userId, $enteredOTP)
    {
        // Check if OTP is locked
        $user = $this->getUserOTPStatus($userId);
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
            $this->clearOTP($userId);
            return ['success' => true, 'message' => 'OTP verified successfully'];
        } else {
            // Increment failed attempts
            $newAttempts = $user['otp_attempts'] + 1;
            $this->updateOTPAttempts($userId, $newAttempts);

            if ($newAttempts >= MAX_OTP_ATTEMPTS) {
                $lockUntil = date('Y-m-d H:i:s', strtotime('+' . OTP_LOCKOUT_MINUTES . ' minutes'));
                $this->lockOTP($userId, $lockUntil);
                return ['success' => false, 'message' => 'Too many failed OTP attempts. Account locked for ' . OTP_LOCKOUT_MINUTES . ' minutes.'];
            }

            $remaining = MAX_OTP_ATTEMPTS - $newAttempts;
            return ['success' => false, 'message' => "Invalid OTP. {$remaining} attempts remaining."];
        }
    }

    // Get user OTP status
    private function getUserOTPStatus($userId)
    {
        $stmt = $this->pdo->prepare("
            SELECT otp_code, otp_expires, otp_attempts, otp_locked_until 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Update OTP attempts
    private function updateOTPAttempts($userId, $attempts)
    {
        $stmt = $this->pdo->prepare("UPDATE users SET otp_attempts = ? WHERE id = ?");
        return $stmt->execute([$attempts, $userId]);
    }

    // Lock OTP
    private function lockOTP($userId, $lockUntil)
    {
        $stmt = $this->pdo->prepare("UPDATE users SET otp_locked_until = ? WHERE id = ?");
        return $stmt->execute([$lockUntil, $userId]);
    }

    // Clear OTP data
    public function clearOTP($userId)
    {
        $stmt = $this->pdo->prepare("
            UPDATE users 
            SET otp_code = NULL, otp_expires = NULL, otp_attempts = 0, otp_locked_until = NULL 
            WHERE id = ?
        ");
        return $stmt->execute([$userId]);
    }

    // Check if OTP verification is required for user
    public function isOTPRequired($userId)
    {
        $user = $this->getUserOTPStatus($userId);
        return $user && $user['otp_code'] !== null;
    }
}
