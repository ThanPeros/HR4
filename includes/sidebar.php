<?php
ob_start(); // Start output buffering at the VERY beginning

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in, if not redirect to login
if (!isset($_SESSION['user']) || !isset($_SESSION['role'])) {
    header('Location: ../index.php');
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    // Clear all session variables
    $_SESSION = array();

    // Destroy the session
    session_destroy();

    // Clear session cookie
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

    // Clear remember me cookie
    setcookie('remember_login', '', time() - 3600, "/");

    // Redirect to login page
    header('Location: ../index.php');
    exit;
}

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    header('Location: ' . $current_url);
    exit;
}

// Initialize sidebar state if not set
if (!isset($_SESSION['sidebar_state'])) {
    $_SESSION['sidebar_state'] = 'open';
}

// Handle sidebar toggle
if (isset($_GET['toggle_sidebar'])) {
    $_SESSION['sidebar_state'] = ($_SESSION['sidebar_state'] === 'open') ? 'closed' : 'open';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    header('Location: ' . $current_url);
    exit;
}

$currentTheme = $_SESSION['theme'];
$sidebarState = $_SESSION['sidebar_state'];
$userRole = $_SESSION['role'];
$userName = htmlspecialchars($_SESSION['name'] ?? 'User', ENT_QUOTES, 'UTF-8');

// Format role for display
$displayRole = strtoupper(str_replace('_', ' ', $userRole));
$welcomeRole = 'HR ' . ucfirst(str_replace('hr_', '', $userRole));

// Define accessible modules based on role
$accessibleModules = [];
if ($userRole === 'hr_manager') {
    // Full access to all modules
    $accessibleModules = [
        'dashboard' => true,
        'core_human_capital' => true,
        'payroll_management' => true,
        'compensation_planning' => true,
        'hmo_benefits' => true
    ];
} elseif ($userRole === 'hr_staff') {
    // Limited access - only dashboard and core human capital
    $accessibleModules = [
        'dashboard' => true,
        'core_human_capital' => true,
        'payroll_management' => false,
        'compensation_planning' => false,
        'hmo_benefits' => false
    ];
} else {
    // Default minimal access for unknown roles
    $accessibleModules = [
        'dashboard' => true,
        'core_human_capital' => false,
        'payroll_management' => false,
        'compensation_planning' => false,
        'hmo_benefits' => false
    ];
}

// Function to check if module is accessible
function canAccess($module)
{
    global $accessibleModules;
    return isset($accessibleModules[$module]) && $accessibleModules[$module];
}

// Get current page for active menu highlighting
$currentPage = basename($_SERVER['PHP_SELF']);

// Function to check if file exists and return proper path
function getModulePath($basePath, $moduleFile)
{
    $fullPath = $basePath . $moduleFile;
    // Check if file exists, if not return '#' to prevent 404
    if (file_exists($fullPath)) {
        return $fullPath;
    }
    return '#'; // Return '#' if file doesn't exist to prevent 404 errors
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Human Resources 4</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --header-height: 60px;
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --dark-bg: #2c3e50;
            --dark-card: #34495e;
            --text-light: #f8f9fa;
            --text-dark: #212529;
            --border-radius: 0.35rem;
            --shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            transition: background 0.3s, color 0.3s;
            min-height: 100vh;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        /* Header */
        .dashboard-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            background: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            box-shadow: var(--shadow);
            z-index: 800;
            transition: left 0.3s ease;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .dashboard-header {
            background: var(--dark-card);
            border-bottom: 1px solid #4a5568;
        }

        .header-content {
            margin-left: 0;
            transition: margin-left 0.3s ease;
            width: 100%;
        }

        /* Fitted Sidebar & Header Styles */
        @media (min-width: 769px) {
            body.sidebar-open {
                margin-left: var(--sidebar-width);
                transition: margin-left 0.3s ease;
            }

            body.sidebar-open .dashboard-header {
                left: var(--sidebar-width);
                width: calc(100% - var(--sidebar-width));
            }
            
            /* Ensure sidebar is strictly fixed to left */
            .sidebar {
                box-shadow: none; /* Remove shadow to make it look 'fitted' */
                border-right: 1px solid rgba(255, 255, 255, 0.1);
            }
        }
        
        .sidebar-open .dashboard-header {
            /* Mobile behavior is handled by default fixed pos, desktop overridden above */
        }

        @media (max-width: 768px) {
            .sidebar-open .dashboard-header {
                left: 0;
            }
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .hamburger {
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            background: #f8f9fc;
            border-radius: 4px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s, color 0.3s;
            border: none;
        }

        body.dark-mode .hamburger {
            background: #2d3748;
            color: white;
        }

        .hamburger:hover {
            background: #e9ecef;
        }

        body.dark-mode .hamburger:hover {
            background: #4a5568;
        }

        .header-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        body.dark-mode .header-title {
            color: var(--text-light);
        }

        .system-title {
            font-size: 1rem;
            opacity: 0.8;
            font-weight: 400;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.9rem;
        }

        .theme-toggle-header {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 4px;
            transition: background 0.3s, color 0.3s;
            color: var(--text-dark);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        body.dark-mode .theme-toggle-header {
            color: var(--text-light);
        }

        .theme-toggle-header:hover {
            background: #e9ecef;
        }

        body.dark-mode .theme-toggle-header:hover {
            background: #4a5568;
        }

        .user-role {
            background: var(--primary-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: #2c3e50;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            transform: translateX(-100%);
        }

        .sidebar-open .sidebar {
            transform: translateX(0);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 280px;
            }
        }

        .sidebar .logo {
            padding: 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .sidebar .logo img {
            max-width: 100%;
            height: auto;
            max-height: 60px;
        }

        .system-name {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.8);
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .sidebar-clock {
            font-size: 0.9rem;
            font-weight: 600;
            color: #4e73df;
            margin-top: 5px;
        }

        .sidebar a {
            display: block;
            color: rgba(255, 255, 255, 0.8);
            padding: 0.8rem 1rem;
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.3s;
            font-size: 0.9rem;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 3px solid white;
        }

        /* Submenu Styles */
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(0, 0, 0, 0.1);
        }

        .submenu.active {
            max-height: 400px;
        }

        .submenu a {
            padding-left: 2rem;
            font-size: 0.85rem;
            border-left: 3px solid transparent;
        }

        .submenu a:hover {
            border-left: 3px solid #4e73df;
        }

        .menu-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .menu-item .arrow {
            transition: transform 0.3s;
            font-size: 0.8rem;
        }

        .menu-item.active .arrow {
            transform: rotate(90deg);
        }

        .theme-toggle-sidebar {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin: 0.5rem 0;
            padding: 0.5rem 0;
        }

        .theme-toggle-sidebar a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0.6rem 1rem;
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
        }

        .theme-toggle-sidebar a:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-left: 3px solid #f39c12;
        }

        .logout-link {
            background-color: #e74a3b;
            color: white !important;
            text-align: center;
            margin: 0.5rem;
            border-radius: var(--border-radius);
            padding: 0.8rem;
            font-weight: 500;
            font-size: 0.9rem;
            transition: background 0.3s;
        }

        .logout-link:hover {
            background-color: #c0392b;
        }

        /* Restricted access styling */
        .restricted-module {
            opacity: 0.6;
            position: relative;
            cursor: not-allowed;
        }

        .restricted-module::after {
            content: "Access Restricted";
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            background: #e74a3b;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .restricted-module:hover::after {
            opacity: 1;
        }

        /* Missing file styling */
        .missing-file {
            opacity: 0.6;
            position: relative;
            cursor: not-allowed;
        }

        .missing-file::after {
            content: "File Missing";
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            background: #f39c12;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.7rem;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .missing-file:hover::after {
            opacity: 1;
        }

        /* Mobile overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .sidebar-open .sidebar-overlay {
            display: block;
        }

        @media (min-width: 769px) {
            .sidebar-overlay {
                display: none !important;
            }
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .header-text {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .separator {
            color: #6c757d;
            margin: 0 5px;
        }

        .current-time {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-dark);
        }

        body.dark-mode .current-time {
            color: var(--text-light);
        }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?> <?php echo $sidebarState === 'open' ? 'sidebar-open' : ''; ?>">
    <div class="dashboard-header" id="dashboardHeader">
        <div class="header-content">
            <div class="header-text">
                <button class="hamburger" id="hamburger" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="header-title">
                    <span>HR Dashboard</span>
                    <span class="separator">|</span>
                    <span>Welcome, <?php echo $welcomeRole; ?></span>
                    <span class="separator">|</span>
                    <span id="currentTime"></span>
                </div>
            </div>
        </div>
    </div>

    <div class="sidebar" id="sidebar">
        <div>
            <div class="logo">
                <img src="../resources/logo.png" alt="SLATE Logo" onerror="this.style.display='none'">
            </div>
            <div class="system-name">
                <div>HR ANALYTICS DASHBOARD</div>
                <div class="sidebar-clock" id="sidebarClock"></div>
            </div>

            <?php
            $dashboardPath = getModulePath('../dashboard/', 'index.php');
            $dashboardClass = ($currentPage === 'index.php') ? 'active' : '';
            $dashboardClass .= ($dashboardPath === '#') ? ' missing-file' : '';
            ?>
            <a href="<?php echo $dashboardPath; ?>" class="<?php echo $dashboardClass; ?>"
                <?php if ($dashboardPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            
            <!-- Removed Responsive View Link -->

            <?php if (canAccess('core_human_capital')): ?>
                <div class="menu-item" onclick="toggleSubmenu('coreHumanCapital')">
                    <a href="javascript:void(0)" style="flex: 1;">
                        <i class="fas fa-users"></i> Core Human Capital
                    </a>
                    <span class="arrow">›</span>
                </div>
                <div class="submenu" id="coreHumanCapital">
                    <?php
                    $employeeProfPath = getModulePath('../core-human/', 'employee-prof.php');
                    $employeeProfClass = ($employeeProfPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $employeeProfPath; ?>" class="<?php echo $employeeProfClass; ?>"
                        <?php if ($employeeProfPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-id-card"></i> Employee Profiles
                    </a>

                    <?php
                    $employmentInfoPath = getModulePath('../core-human/', 'employment_info.php');
                    $employmentInfoClass = ($employmentInfoPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $employmentInfoPath; ?>" class="<?php echo $employmentInfoClass; ?>"
                        <?php if ($employmentInfoPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-briefcase"></i> Employment Management
                    </a>
                    
                    <?php
                    $contractPath = getModulePath('../core-human/', 'contract-employment.php');
                    $contractClass = ($contractPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $contractPath; ?>" class="<?php echo $contractClass; ?>"
                        <?php if ($contractPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-file-contract"></i> Contract Details
                    </a>

                    <?php
                    $employmentLifePath = getModulePath('../core-human/', 'employment-life.php');
                    $employmentLifeClass = ($employmentLifePath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $employmentLifePath; ?>" class="<?php echo $employmentLifeClass; ?>"
                        <?php if ($employmentLifePath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-user-clock"></i> Employment Lifecycle
                    </a>

                    <?php
                    $reportPath = getModulePath('../core-human/', 'report.php');
                    $reportClass = ($reportPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $reportPath; ?>" class="<?php echo $reportClass; ?>"
                        <?php if ($reportPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-chart-bar"></i> Reports
                    </a>
                </div>
            <?php endif; ?>

            <?php if (canAccess('payroll_management')): ?>
                <?php
                $payrollPath = getModulePath('../payroll/', 'index.php');
                $payrollClass = (strpos($currentPage, 'payroll') !== false) ? 'active' : '';
                $payrollClass .= ($payrollPath === '#') ? ' missing-file' : '';
                ?>
                <a href="<?php echo $payrollPath; ?>" class="<?php echo $payrollClass; ?>"
                    <?php if ($payrollPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                    <i class="fas fa-money-bill-wave"></i> Payroll Management
                </a>
            <?php else: ?>
                <a href="javascript:void(0)" class="restricted-module" onclick="showAccessDenied()">
                    <i class="fas fa-money-bill-wave"></i> Payroll Management
                </a>
            <?php endif; ?>

            <?php if (canAccess('compensation_planning')): ?>
                <div class="menu-item" onclick="toggleSubmenu('compensationPlanning')">
                    <a href="javascript:void(0)" style="flex: 1;">
                        <i class="fas fa-chart-line"></i> Compensation Planning
                    </a>
                    <span class="arrow">›</span>
                </div>
                <div class="submenu" id="compensationPlanning">
                    <?php
                    $salaryStructPath = getModulePath('../compensation/', 'salary-structure.php');
                    $salaryStructClass = ($salaryStructPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $salaryStructPath; ?>" class="<?php echo $salaryStructClass; ?>"
                        <?php if ($salaryStructPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-sitemap"></i> Salary Structure
                    </a>

                    <?php
                    $allowancePath = getModulePath('../compensation/', 'allowance-matrix.php');
                    $allowanceClass = ($allowancePath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $allowancePath; ?>" class="<?php echo $allowanceClass; ?>"
                        <?php if ($allowancePath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-gifts"></i> Allowance Matrix
                    </a>

                    <?php
                    $rulesPath = getModulePath('../compensation/', 'compensation-rules.php');
                    $rulesClass = ($rulesPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $rulesPath; ?>" class="<?php echo $rulesClass; ?>"
                        <?php if ($rulesPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-book"></i> Compensation Rules
                    </a>

                    <?php
                    $bonusMovementsPath = getModulePath('../compensation/', 'bonus-and-movements.php');
                    $bonusMovementsClass = ($bonusMovementsPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $bonusMovementsPath; ?>" class="<?php echo $bonusMovementsClass; ?>"
                        <?php if ($bonusMovementsPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-gift"></i> Bonus & Movements
                    </a>

                    <!-- ADDED: Report Item -->
                    <?php
                    $compensationReportPath = getModulePath('../compensation/', 'compensation-report.php');
                    $compensationReportClass = ($compensationReportPath === '#') ? 'missing-file' : '';
                    ?>
                    <a href="<?php echo $compensationReportPath; ?>" class="<?php echo $compensationReportClass; ?>"
                        <?php if ($compensationReportPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                        <i class="fas fa-file-alt"></i> Compensation Report
                    </a>
                </div>
            <?php else: ?>
                <a href="javascript:void(0)" class="restricted-module" onclick="showAccessDenied()">
                    <i class="fas fa-chart-line"></i> Compensation Planning
                </a>
            <?php endif; ?>

            <?php if (canAccess('hmo_benefits')): ?>
                <?php
                $hmoPath = getModulePath('../hmo-benefits/', 'index.php');
                $hmoClass = (strpos($currentPage, 'hmo-benefits') !== false) ? 'active' : '';
                $hmoClass .= ($hmoPath === '#') ? ' missing-file' : '';
                ?>
                <a href="<?php echo $hmoPath; ?>" class="<?php echo $hmoClass; ?>"
                    <?php if ($hmoPath === '#') echo 'onclick="showFileMissing()"'; ?>>
                    <i class="fas fa-heartbeat"></i> HMO and Benefits Administration
                </a>
            <?php else: ?>
                <a href="javascript:void(0)" class="restricted-module" onclick="showAccessDenied()">
                    <i class="fas fa-heartbeat"></i> HMO and Benefits Administration
                </a>
            <?php endif; ?>

            <div class="theme-toggle-sidebar">
                <a href="?toggle_theme=1" id="sidebarThemeToggle">
                    <i class="fas fa-<?php echo $currentTheme === 'light' ? 'moon' : 'sun'; ?>"></i>
                    <span>Switch to <?php echo $currentTheme === 'light' ? 'Dark' : 'Light'; ?> Mode</span>
                </a>
            </div>
        </div>
        <a href="?logout=1" class="logout-link" onclick="return confirmLogout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <script>
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const body = document.body;

        function confirmLogout() {
            return confirm('Are you sure you want to logout?');
        }

        function toggleTheme() {
            // Use AJAX to toggle theme without page refresh
            fetch('?toggle_theme=1')
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    }
                })
                .catch(error => {
                    console.error('Error toggling theme:', error);
                    window.location.href = '?toggle_theme=1';
                });
        }

        function toggleSidebar() {
            // Toggle sidebar using CSS classes only - no page refresh
            body.classList.toggle('sidebar-open');

            // Save sidebar state via AJAX to maintain state across page refreshes
            const isOpen = body.classList.contains('sidebar-open');
            fetch('?toggle_sidebar=1&state=' + (isOpen ? 'open' : 'closed'))
                .catch(error => {
                    console.error('Error saving sidebar state:', error);
                });
        }

        window.toggleSubmenu = function(menuId) {
            const submenu = document.getElementById(menuId);
            const menuItem = submenu.previousElementSibling;

            // Close all other submenus when opening a new one
            document.querySelectorAll('.submenu').forEach(sm => {
                if (sm.id !== menuId && sm.classList.contains('active')) {
                    sm.classList.remove('active');
                    const prevItem = sm.previousElementSibling;
                    if (prevItem && prevItem.classList.contains('menu-item')) {
                        prevItem.classList.remove('active');
                    }
                }
            });

            submenu.classList.toggle('active');
            menuItem.classList.toggle('active');
        };

        window.showAccessDenied = function() {
            alert('Access Denied: This module is only available to HR Managers. Please contact your administrator.');
        };

        window.showFileMissing = function() {
            alert('File Missing: The requested page is currently unavailable. Please contact the administrator.');
            return false;
        };

        // Sidebar Clock
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
            const dateString = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
            
            const clockElement = document.getElementById('sidebarClock');
            if(clockElement) {
                clockElement.innerHTML = `<div>${timeString}</div><div style="font-size: 0.75rem; opacity: 0.8; font-weight: normal;">${dateString}</div>`;
            }
            
            // Also update header time if it exists
            const headerTime = document.getElementById('currentTime');
            if(headerTime) {
                headerTime.textContent = dateString + ' | ' + timeString;
            }
        }
        
        setInterval(updateTime, 1000);
        updateTime(); // Run immediately

        // Close sidebar when clicking on overlay
        sidebarOverlay.addEventListener('click', function() {
            toggleSidebar();
        });

        // Close sidebar when a menu item is clicked on mobile
        document.querySelectorAll('.sidebar a[href!="javascript:void(0)"]').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    toggleSidebar();
                }
            });
        });

        // Prevent navigation for missing files
        document.querySelectorAll('.missing-file').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                showFileMissing();
                return false;
            });
        });

        // Prevent navigation for restricted modules
        document.querySelectorAll('.restricted-module').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                showAccessDenied();
                return false;
            });
        });

        // Close sidebar when pressing Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                toggleSidebar();
            }
        });

        // Update current time
        function updateCurrentTime() {
            const now = new Date();
            const options = {
                weekday: 'short',
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true
            };
            document.getElementById('currentTime').textContent = now.toLocaleDateString('en-US', options);
        }

        // Update time immediately and then every second
        updateCurrentTime();
        setInterval(updateCurrentTime, 1000);
    </script>
</body>

</html>
<?php ob_end_flush(); ?>