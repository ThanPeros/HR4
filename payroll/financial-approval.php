<?php
// financial-approval.php - Financial Budget Approval System

// Database connection - Use same connection as payroll system
try {
    $host = 'localhost';
    $dbname = 'dummy_hr4'; // Same database
    $username = 'root';
    $password = '';

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

$currentTheme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_budget'])) {
        approveBudget($pdo, $_POST);
    } elseif (isset($_POST['reject_budget'])) {
        rejectBudget($pdo, $_POST);
    }
}

// Approve Budget
function approveBudget($pdo, $data)
{
    $budgetId = $data['budget_id'];
    $approvedBy = $_SESSION['user_name'] ?? 'Finance Manager';

    try {
        $sql = "UPDATE payroll_budgets SET 
                approval_status = 'Approved', 
                budget_status = 'Approved',
                approved_by = ?, 
                approved_at = NOW() 
                WHERE id = ? AND approval_status = 'Waiting for Approval'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$approvedBy, $budgetId]);

        $_SESSION['success_message'] = "Payroll budget #$budgetId approved successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error approving budget: " . $e->getMessage();
    }

    header('Location: financial-approval.php');
    exit;
}

// Reject Budget
function rejectBudget($pdo, $data)
{
    $budgetId = $data['budget_id'];
    $rejectedBy = $_SESSION['user_name'] ?? 'Finance Manager';
    $notes = $data['rejection_notes'] ?? '';

    try {
        $sql = "UPDATE payroll_budgets SET 
                approval_status = 'Rejected', 
                approved_by = ?, 
                approved_at = NOW(),
                approver_notes = ?
                WHERE id = ? AND approval_status = 'Waiting for Approval'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$rejectedBy, $notes, $budgetId]);

        $_SESSION['success_message'] = "Payroll budget #$budgetId rejected successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error rejecting budget: " . $e->getMessage();
    }

    header('Location: financial-approval.php');
    exit;
}

// Get budgets pending approval
try {
    $pending_budgets_stmt = $pdo->query("
        SELECT * FROM payroll_budgets 
        WHERE approval_status = 'Waiting for Approval'
        ORDER BY created_at DESC
    ");
    $pending_budgets = $pending_budgets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pending_budgets = [];
}

// Get all budgets for history
try {
    $all_budgets_stmt = $pdo->query("
        SELECT * FROM payroll_budgets 
        ORDER BY created_at DESC
    ");
    $all_budgets = $all_budgets_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_budgets = [];
}

// Get budget statistics
try {
    $stats_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_budgets,
            COUNT(CASE WHEN approval_status = 'Waiting for Approval' THEN 1 END) as pending_approval,
            COUNT(CASE WHEN approval_status = 'Approved' THEN 1 END) as approved,
            COUNT(CASE WHEN approval_status = 'Rejected' THEN 1 END) as rejected,
            SUM(total_net_pay) as total_budget_amount,
            SUM(total_net_pay) as total_allocated
        FROM payroll_budgets
    ");
    $budget_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $budget_stats = [
        'total_budgets' => 0,
        'pending_approval' => 0,
        'approved' => 0,
        'rejected' => 0,
        'total_budget_amount' => 0,
        'total_allocated' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Approval | HR System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Use the same CSS styles as payroll management */
        :root {
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --dark-bg: #2c3e50;
            --dark-card: #34495e;
            --text-light: #f8f9fa;
            --text-dark: #212529;
            --border-radius: 0.35rem;
            --shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --purple-color: #6f42c1;
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
            line-height: 1.4;
            overflow-x: hidden;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        .theme-toggle-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .theme-toggle-btn {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 20px;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            box-shadow: var(--shadow);
        }

        body.dark-mode .theme-toggle-btn {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }

        .theme-toggle-btn:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }

        body.dark-mode .theme-toggle-btn:hover {
            background: #4a5568;
        }

        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            width: 100%;
            margin-top: 60px;
        }

        body.dark-mode .main-content {
            background-color: var(--dark-bg);
        }

        .content-area {
            width: 100%;
            min-height: 100%;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        body.dark-mode .content-area {
            background: var(--dark-card);
        }

        .page-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        body.dark-mode .page-header {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 0.9rem;
        }

        body.dark-mode .page-subtitle {
            color: #a0aec0;
        }

        .nav-container {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        body.dark-mode .nav-container {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
        }

        .nav-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .nav-link {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s;
        }

        .nav-link:hover {
            color: #2e59d9;
        }

        /* Stats Cards */
        .stats-container {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }

        body.dark-mode .stat-card {
            background: var(--dark-card);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }

        .stat-card.warning {
            border-left-color: var(--warning-color);
        }

        .stat-card.success {
            border-left-color: var(--success-color);
        }

        .stat-card.info {
            border-left-color: var(--info-color);
        }

        .stat-card.purple {
            border-left-color: var(--purple-color);
        }

        .stat-card.danger {
            border-left-color: var(--danger-color);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        body.dark-mode .stat-label {
            color: #a0aec0;
        }

        /* Forms */
        .form-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
        }

        body.dark-mode .form-container {
            background: var(--dark-card);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .form-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2e59d9;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #17a673;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc3545;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background: #2c9faf;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1rem 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            border-color: var(--success-color);
            color: #155724;
        }

        body.dark-mode .alert-success {
            background: #22543d;
            color: #9ae6b4;
        }

        .alert-error {
            background: #f8d7da;
            border-color: var(--danger-color);
            color: #721c24;
        }

        body.dark-mode .alert-error {
            background: #744210;
            color: #fbd38d;
        }

        /* Budget Cards */
        .budget-management {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
        }

        body.dark-mode .budget-management {
            background: var(--dark-card);
        }

        .budget-card {
            border: 1px solid #e3e6f0;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fc;
        }

        body.dark-mode .budget-card {
            background: #2d3748;
            border-color: #4a5568;
        }

        .budget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .budget-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .budget-stat {
            text-align: center;
            padding: 0.5rem;
            background: white;
            border-radius: var(--border-radius);
            border: 1px solid #e3e6f0;
        }

        body.dark-mode .budget-stat {
            background: #1a202c;
            border-color: #4a5568;
        }

        .progress-bar {
            width: 100%;
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        body.dark-mode .progress-bar {
            background: #4a5568;
        }

        .progress-fill {
            height: 100%;
            background: var(--success-color);
            transition: width 0.3s;
        }

        .progress-warning {
            background: var(--warning-color);
        }

        .progress-danger {
            background: var(--danger-color);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-draft {
            background: #e9ecef;
            color: #495057;
        }

        .status-waiting {
            background: #fff3cd;
            color: #856404;
        }

        body.dark-mode .status-waiting {
            background: #744210;
            color: #fbd38d;
        }

        .status-approved {
            background: #d1ecf1;
            color: #0c5460;
        }

        body.dark-mode .status-approved {
            background: #1a365d;
            color: #63b3ed;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-rejected {
            background: #744210;
            color: #fbd38d;
        }

        .budget-status-draft {
            background: #e9ecef;
            color: #495057;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .budget-status-waiting {
            background: #fff3cd;
            color: #856404;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-waiting {
            background: #744210;
            color: #fbd38d;
        }

        .budget-status-approved {
            background: #d4edda;
            color: #155724;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-approved {
            background: #22543d;
            color: #9ae6b4;
        }

        .budget-status-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-rejected {
            background: #744210;
            color: #fbd38d;
        }

        .budget-status-released {
            background: #cce5ff;
            color: #004085;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-released {
            background: #2c5282;
            color: #bee3f8;
        }

        @media(max-width:768px) {
            .main-content {
                padding: 1rem;
            }

            .budget-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .budget-details {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media(max-width:480px) {
            .main-content {
                padding: 0.8rem;
            }

            .budget-management {
                margin: 1rem;
                padding: 1rem;
            }

            .budget-details {
                grid-template-columns: 1fr;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <!-- Theme Toggle -->
    <div class="theme-toggle-container">
        <a href="?toggle_theme=true" class="theme-toggle-btn">
            <i class="fas fa-<?php echo $currentTheme === 'dark' ? 'sun' : 'moon'; ?>"></i>
            <?php echo $currentTheme === 'dark' ? 'Light Mode' : 'Dark Mode'; ?>
        </a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-file-invoice-dollar"></i>
                    Financial Budget Approval
                </h1>
                <p class="page-subtitle">Approve or reject payroll budgets from HR department</p>
            </div>

            <!-- Navigation -->
            <div class="nav-container">
                <nav class="nav-breadcrumb">
                    <a href="../hr-dashboard/index.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Financial Budget Approval</span>
                </nav>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $budget_stats['total_budgets'] ?? 0; ?></div>
                    <div class="stat-label">Total Budgets</div>
                    <i class="fas fa-wallet" style="float: right; color: #4e73df; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo $budget_stats['pending_approval'] ?? 0; ?></div>
                    <div class="stat-label">Pending Approval</div>
                    <i class="fas fa-clock" style="float: right; color: #f6c23e; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $budget_stats['approved'] ?? 0; ?></div>
                    <div class="stat-label">Approved</div>
                    <i class="fas fa-check-circle" style="float: right; color: #1cc88a; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card danger">
                    <div class="stat-value"><?php echo $budget_stats['rejected'] ?? 0; ?></div>
                    <div class="stat-label">Rejected</div>
                    <i class="fas fa-times-circle" style="float: right; color: #e74a3b; font-size: 1.5rem;"></i>
                </div>
            </div>

            <!-- Pending Approval Section -->
            <div class="budget-management">
                <div class="form-header">
                    <h2 class="form-title">
                        <i class="fas fa-clock"></i>
                        Budgets Pending Approval
                    </h2>
                </div>

                <?php if (!empty($pending_budgets)): ?>
                    <?php foreach ($pending_budgets as $budget): ?>
                        <div class="budget-card">
                            <div class="budget-header">
                                <div>
                                    <h4><?php echo htmlspecialchars($budget['budget_name'] ?? 'Payroll Budget'); ?> - #<?php echo $budget['id']; ?></h4>
                                    <p>Period: <?php echo date('M j, Y', strtotime($budget['budget_period_start'])); ?> to <?php echo date('M j, Y', strtotime($budget['budget_period_end'])); ?></p>
                                    <p>Status: <span class="budget-status-waiting">Waiting for Approval</span></p>
                                    <p>Created: <?php echo date('M j, Y g:i A', strtotime($budget['created_at'])); ?></p>
                                    <?php if (isset($budget['submitted_for_approval_at'])): ?>
                                        <p>Submitted: <?php echo date('M j, Y g:i A', strtotime($budget['submitted_for_approval_at'])); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="form-actions">
                                    <form method="POST" action="" style="display: inline;">
                                        <input type="hidden" name="budget_id" value="<?php echo $budget['id']; ?>">
                                        <button type="submit" name="approve_budget" class="btn btn-success" onclick="return confirm('Approve this budget?')">
                                            <i class="fas fa-check"></i> Approve Budget
                                        </button>
                                    </form>
                                    <button type="button" onclick="showRejectForm(<?php echo $budget['id']; ?>)" class="btn btn-danger">
                                        <i class="fas fa-times"></i> Reject Budget
                                    </button>
                                </div>
                            </div>
                            <div class="budget-details">
                                <div class="budget-stat">
                                    <div class="stat-value">₱<?php echo number_format($budget['total_net_pay'], 2); ?></div>
                                    <div class="stat-label">Budget Amount</div>
                                </div>
                                <div class="budget-stat">
                                    <div class="stat-value"><?php echo $budget['total_employees']; ?></div>
                                    <div class="stat-label">Employees</div>
                                </div>
                                <div class="budget-stat">
                                    <div class="stat-value">₱<?php echo number_format($budget['total_gross_pay'], 2); ?></div>
                                    <div class="stat-label">Gross Pay</div>
                                </div>
                                <div class="budget-stat">
                                    <div class="stat-value">₱<?php echo number_format($budget['total_deductions'], 2); ?></div>
                                    <div class="stat-label">Deductions</div>
                                </div>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: 0%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="form-container">
                        <p style="text-align: center; padding: 2rem; color: #6c757d;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem;"></i><br>
                            No budgets pending approval.
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Budget History Section -->
            <div class="budget-management">
                <div class="form-header">
                    <h2 class="form-title">
                        <i class="fas fa-history"></i>
                        Budget Approval History
                    </h2>
                </div>

                <?php if (!empty($all_budgets)): ?>
                    <?php foreach ($all_budgets as $budget): ?>
                        <?php
                        $approvalStatus = $budget['approval_status'] ?? 'Draft';
                        if ($approvalStatus !== 'Waiting for Approval'):
                        ?>
                            <div class="budget-card">
                                <div class="budget-header">
                                    <div>
                                        <h4><?php echo htmlspecialchars($budget['budget_name'] ?? 'Payroll Budget'); ?> - #<?php echo $budget['id']; ?></h4>
                                        <p>Period: <?php echo date('M j, Y', strtotime($budget['budget_period_start'])); ?> to <?php echo date('M j, Y', strtotime($budget['budget_period_end'])); ?></p>
                                        <p>Approval Status:
                                            <span class="budget-status-<?php echo strtolower(str_replace(' ', '', $approvalStatus)); ?>">
                                                <?php echo htmlspecialchars($approvalStatus); ?>
                                            </span>
                                        </p>
                                        <?php if ($budget['approved_by']): ?>
                                            <p>Processed by: <?php echo htmlspecialchars($budget['approved_by']); ?> on <?php echo date('M j, Y g:i A', strtotime($budget['approved_at'])); ?></p>
                                        <?php endif; ?>
                                        <?php if (isset($budget['approver_notes']) && !empty($budget['approver_notes'])): ?>
                                            <p>Notes: <?php echo htmlspecialchars($budget['approver_notes']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="budget-details">
                                    <div class="budget-stat">
                                        <div class="stat-value">₱<?php echo number_format($budget['total_net_pay'], 2); ?></div>
                                        <div class="stat-label">Budget Amount</div>
                                    </div>
                                    <div class="budget-stat">
                                        <div class="stat-value"><?php echo $budget['total_employees']; ?></div>
                                        <div class="stat-label">Employees</div>
                                    </div>
                                    <div class="budget-stat">
                                        <div class="stat-value">₱<?php echo number_format($budget['total_gross_pay'], 2); ?></div>
                                        <div class="stat-label">Gross Pay</div>
                                    </div>
                                    <div class="budget-stat">
                                        <div class="stat-value">₱<?php echo number_format($budget['total_deductions'], 2); ?></div>
                                        <div class="stat-label">Deductions</div>
                                    </div>
                                </div>
                                <div class="progress-bar">
                                    <?php
                                    $progressClass = 'progress-fill';
                                    ?>
                                    <div class="<?php echo $progressClass; ?>" style="width: 100%"></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="form-container">
                        <p style="text-align: center; padding: 2rem; color: #6c757d;">
                            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem;"></i><br>
                            No budget history available.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showRejectForm(budgetId) {
            const notes = prompt('Please enter reason for rejection:', '');
            if (notes !== null) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                const budgetIdInput = document.createElement('input');
                budgetIdInput.type = 'hidden';
                budgetIdInput.name = 'budget_id';
                budgetIdInput.value = budgetId;

                const notesInput = document.createElement('input');
                notesInput.type = 'hidden';
                notesInput.name = 'rejection_notes';
                notesInput.value = notes;

                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'reject_budget';
                submitInput.value = '1';

                form.appendChild(budgetIdInput);
                form.appendChild(notesInput);
                form.appendChild(submitInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>

</html>
<?php ob_end_flush(); ?>