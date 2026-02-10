<?php
// provider-login-portal.php - Provider & HR Manager Login Portal

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400, // 24 hours
        'cookie_secure' => false,    // Set to true if using HTTPS
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict'
    ]);
}

// Include database configuration
$dbConfigPath = __DIR__ . '/../config/db.php';
if (file_exists($dbConfigPath)) {
    require_once $dbConfigPath;
} else {
    // If no config file, initialize $pdo as null
    $pdo = null;
}

// Initialize variables
$error_message = '';
$success_message = '';

// Setup provider accounts in database if needed
// Setup provider accounts in database if needed
function setupProviderAccounts($pdo)
{
    if (!$pdo) return false;

    try {
        // 1. Create Table
        $sqlCreate = "CREATE TABLE IF NOT EXISTS `providers` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `provider_name` varchar(150) NOT NULL,
          `username` varchar(100) DEFAULT NULL,
          `password_hash` varchar(255) DEFAULT NULL,
          `provider_type` enum('HMO','Insurance','Dental','Vision','Wellness','Other') DEFAULT 'HMO',
          `email` varchar(150) DEFAULT NULL,
          `contact_person` varchar(100) DEFAULT NULL,
          `phone` varchar(50) DEFAULT NULL,
          `address` text DEFAULT NULL,
          `coverage_type` enum('Comprehensive','Basic','Supplemental','Specialized') DEFAULT 'Comprehensive',
          `portal_access` enum('enabled','disabled','suspended') DEFAULT 'enabled',
          `portal_status` enum('active','inactive','pending') DEFAULT 'active',
          `status` enum('Active','Inactive','Pending') DEFAULT 'Active',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `username` (`username`),
          UNIQUE KEY `provider_name` (`provider_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        $pdo->exec($sqlCreate);

        // 2. Check if data exists, if not (or forcefully) insert/update
        // The user asked to Apply this, implying we should ensure these records exist.
        // We will use INSERT ON DUPLICATE KEY UPDATE or just INSERT IGNORE to avoid errors, 
        // but given the specific ID requirement, let's try to upsert.
        
        $providersData = [
            [1, 'Maxicare Healthcare Corporation', 'maxicare', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'HMO', 'support@maxicare.com.ph', 'Juan Dela Cruz', '+63 2 8888 9999', 'Maxicare Center, Ortigas Center, Pasig City', 'Comprehensive', 'enabled', 'active', 'Active'],
            [2, 'MediCard Philippines, Inc.', 'medicard', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'HMO', 'info@medicardphils.com', 'Maria Santos', '+63 2 7777 8888', 'MediCard Building, Makati City', 'Comprehensive', 'enabled', 'active', 'Active'],
            [3, 'Intellicare', 'intellicare', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'HMO', 'customerservice@intellicare.com.ph', 'Pedro Reyes', '+63 2 6666 7777', 'Intellicare House, Bonifacio Global City', 'Comprehensive', 'enabled', 'active', 'Active'],
            [4, 'Philam Life', 'philam', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Insurance', 'philamlife@aia.com', 'Robert Lim', '+63 2 5555 6666', 'Philam Life Tower, Paseo de Roxas, Makati', 'Comprehensive', 'enabled', 'active', 'Active'],
            [5, 'Sun Life Grepa', 'sunlife', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Insurance', 'contact@sunlife.com.ph', 'Anna Gomez', '+63 2 4444 5555', 'Sun Life Centre, Bonifacio Global City', 'Comprehensive', 'enabled', 'active', 'Active']
        ];

        $stmt = $pdo->prepare("INSERT INTO `providers` 
            (`id`, `provider_name`, `username`, `password_hash`, `provider_type`, `email`, `contact_person`, `phone`, `address`, `coverage_type`, `portal_access`, `portal_status`, `status`) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            provider_name=VALUES(provider_name), 
            username=VALUES(username),
            password_hash=VALUES(password_hash),
            email=VALUES(email)");

        foreach ($providersData as $row) {
            $stmt->execute($row);
        }

        return true;
    } catch (PDOException $e) {
        error_log("Setup provider accounts error: " . $e->getMessage());
        return false;
    }
}

// Setup provider accounts if database is available
if ($pdo instanceof PDO) {
    setupProviderAccounts($pdo);
}

// Security functions
function sanitizeInput($data)
{
    if (is_null($data) || $data === '') {
        return $data;
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // Sanitize inputs
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate inputs
    if (empty($username) || empty($password)) {
        $error_message = "Please enter both username and password.";
    } else {
        // First, try to authenticate as a provider
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM `providers` WHERE `username` = ? AND `portal_access` = 'enabled'");
                $stmt->execute([$username]);
                $provider = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($provider && password_verify($password, $provider['password_hash'])) {
                    // Set session variables for provider
                    $_SESSION['user_logged_in'] = true;
                    $_SESSION['user_role'] = 'provider';
                    $_SESSION['provider_id'] = $provider['id'];
                    $_SESSION['provider_name'] = $provider['provider_name'];
                    $_SESSION['username'] = $provider['username'];
                    $_SESSION['user_email'] = $provider['email'];
                    $_SESSION['user_name'] = $provider['contact_person'];
                    $_SESSION['provider_status'] = $provider['status'];
                    $_SESSION['login_time'] = time();

                    // Clear output buffer before redirect
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    // Redirect to provider dashboard
                    header('Location: provider-dashboard.php');
                    exit;
                }
            } catch (PDOException $e) {
                error_log("Provider login error: " . $e->getMessage());
            }
        }

        // If not a provider, try HR/Admin login
        if ($pdo instanceof PDO) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `username` = ? AND `role` IN ('hr_manager', 'hr_staff', 'admin')");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    // Set session variables for HR/Admin
                    $_SESSION['user_logged_in'] = true;
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['hr_manager_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['department'] = 'Human Resources';
                    $_SESSION['title'] = ucfirst(str_replace('_', ' ', $user['role']));
                    $_SESSION['login_time'] = time();

                    // Clear output buffer before redirect
                    if (ob_get_level() > 0) {
                        ob_end_clean();
                    }

                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        header('Location: hr-provider-management.php');
                    } else {
                        header('Location: hr-provider-management.php');
                    }
                    exit;
                }
            } catch (PDOException $e) {
                error_log("HR login error: " . $e->getMessage());
            }
        }

        // Try default HR accounts as fallback
        $hr_accounts = [
            'hr_manager' => [
                'name' => 'HR Manager',
                'password' => 'hr123',
                'email' => 'hr@company.com',
                'role' => 'hr_manager'
            ],
            'hr_staff' => [
                'name' => 'HR Staff',
                'password' => 'hr123',
                'email' => 'hrstaff@company.com',
                'role' => 'hr_staff'
            ],
            'admin' => [
                'name' => 'Administrator',
                'password' => 'admin123',
                'email' => 'admin@company.com',
                'role' => 'admin'
            ]
        ];

        if (array_key_exists($username, $hr_accounts) && $password === $hr_accounts[$username]['password']) {
            $_SESSION['user_logged_in'] = true;
            $_SESSION['user_role'] = $hr_accounts[$username]['role'];
            $_SESSION['user_id'] = 1000 + rand(1, 999);
            $_SESSION['hr_manager_id'] = 1000 + rand(1, 999);
            $_SESSION['username'] = $username;
            $_SESSION['user_name'] = $hr_accounts[$username]['name'];
            $_SESSION['user_email'] = $hr_accounts[$username]['email'];
            $_SESSION['department'] = 'Human Resources';
            $_SESSION['title'] = ucfirst(str_replace('_', ' ', $hr_accounts[$username]['role']));
            $_SESSION['login_time'] = time();

            // Clear output buffer before redirect
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Redirect based on role
            if ($hr_accounts[$username]['role'] === 'admin') {
                header('Location: hr-provider-management.php');
            } else {
                header('Location: hr-provider-management.php');
            }
            exit;
        }

        // If no authentication succeeded
        $error_message = "Invalid username or password. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider & HR Manager Login | Benefits & HMO Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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

        .welcome-panel p {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.9);
            text-align: center;
            max-width: 30rem;
            line-height: 1.6;
        }

        .login-panel {
            width: 25rem;
            padding: 2.5rem;
            background: rgba(22, 33, 49, 0.95);
        }

        .login-box {
            width: 100%;
            text-align: center;
        }

        .login-box h2 {
            margin-bottom: 0.5rem;
            color: #ffffff;
            font-size: 1.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-description {
            text-align: center;
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
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

        .password-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        .toggle-password {
            background: none;
            border: none;
            color: rgba(160, 160, 160, 0.8);
            cursor: pointer;
            font-size: 1rem;
            margin-left: 10px;
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
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .login-box button:hover {
            background: linear-gradient(to right, #0052cc, #009ee3);
            transform: translateY(-0.125rem);
            box-shadow: 0 0.3125rem 0.9375rem rgba(0, 0, 0, 0.2);
        }

        .error-message {
            color: #ff6b6b;
            background: rgba(255, 107, 107, 0.1);
            padding: 0.75rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(255, 107, 107, 0.3);
            text-align: left;
        }

        .success-message {
            color: #4CAF50;
            background: rgba(76, 175, 80, 0.1);
            padding: 0.75rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(76, 175, 80, 0.3);
            text-align: left;
        }

        .demo-credentials {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.3);
            border-radius: 0.375rem;
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.85rem;
        }

        .demo-credentials h4 {
            color: #ffc107;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .credential-section {
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px dashed rgba(255, 193, 7, 0.3);
        }

        .credential-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .credential-section h5 {
            color: #ffc107;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .demo-credentials p {
            margin: 0.25rem 0;
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.85rem;
        }

        .demo-account-pill {
            display: inline-block;
            background: rgba(255, 193, 7, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            margin: 0.25rem;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            color: #ffc107;
        }

        .demo-account-pill:hover {
            background: rgba(255, 193, 7, 0.3);
            transform: translateY(-1px);
        }

        footer {
            text-align: center;
            padding: 1.25rem;
            background: rgba(0, 0, 0, 0.2);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                max-width: 25rem;
            }

            .welcome-panel {
                padding: 1.5rem;
            }

            .welcome-panel h1 {
                font-size: 1.75rem;
            }

            .login-panel {
                width: 100%;
                padding: 2rem;
            }
        }
    </style>
</head>

<body>
    <div class="main-container">
        <div class="login-container">
            <div class="welcome-panel">
                <h1>BENEFITS & HMO PORTAL</h1>
                <p>Secure login for healthcare providers and HR managers to manage benefits administration and provider relationships.</p>
            </div>

            <div class="login-panel">
                <div class="login-box">
                    <h2>
                        <i class="fas fa-shield-alt"></i>
                        Unified Login Portal
                    </h2>
                    <p class="login-description">Enter your credentials to access the system. You'll be automatically redirected based on your role.</p>

                    <?php if (!empty($error_message)): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success_message)): ?>
                        <div class="success-message">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Unified Login Form -->
                    <form method="POST" action="" id="loginForm">
                        <div class="form-group">
                            <label for="usernameInput" class="form-label">
                                <i class="fas fa-user"></i> Username
                            </label>
                            <input type="text" name="username" class="form-input" id="usernameInput"
                                placeholder="Enter your username" required
                                value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="password" class="form-label">
                                <i class="fas fa-lock"></i> Password
                            </label>
                            <div class="password-container">
                                <input type="password" name="password" id="password"
                                    class="form-input" placeholder="Enter your password" required>
                                <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" name="login" class="login-btn">
                            <i class="fas fa-sign-in-alt"></i>
                            Login to System
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer>
        &copy; <span id="currentYear"></span> Benefits & HMO Management Portal. All rights reserved.
    </footer>

    <script>
        // Set current year
        document.getElementById('currentYear').textContent = new Date().getFullYear();

        // Toggle password visibility
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.toggle-password i');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.classList.remove('fa-eye');
                toggleButton.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleButton.classList.remove('fa-eye-slash');
                toggleButton.classList.add('fa-eye');
            }
        }
    </script>
</body>

</html>