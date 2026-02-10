<?php
// payroll/leave-time.php - Leave & Time-Off Management
session_start();

// Include database configuration
require_once '../config/db.php';

// Check database connection
if (!isset($pdo)) {
    die("Database connection failed. Please checks config/db.php");
}

// ============ HANDLING FORM SUBMISSIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $request_id = $_POST['request_id'] ?? 0;

    if ($request_id || $action === 'create_request') {
        try {
            if ($action === 'approve') {
                // Fetch Request Details
                $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
                $stmt->execute([$request_id]);
                $req = $stmt->fetch();

                if ($req && $req['status'] == 'Pending') {
                    // Determine Credit Column
                    $col = '';
                    if (strpos($req['leave_type'], 'Vacation') !== false) $col = 'vl_credits';
                    elseif (strpos($req['leave_type'], 'Sick') !== false) $col = 'sl_credits';
                    elseif (strpos($req['leave_type'], 'Emergency') !== false) $col = 'el_credits';

                    // Update Balance if applicable
                    if ($col) {
                        $year = date('Y', strtotime($req['start_date']));
                        $deductSql = "UPDATE leave_balances SET $col = $col - ? WHERE employee_id = ? AND year = ?";
                        $pdo->prepare($deductSql)->execute([$req['days_count'], $req['employee_id'], $year]);
                    }

                    // Update Status
                    $stmt = $pdo->prepare("UPDATE leave_requests SET status = 'Approved' WHERE id = ?");
                    $stmt->execute([$request_id]);
                    $_SESSION['success'] = "Request Approved & Balance Deducted!";
                }
            } elseif ($action === 'reject') {
                $stmt = $pdo->prepare("UPDATE leave_requests SET status = 'Rejected' WHERE id = ?");
                $stmt->execute([$request_id]);
                $_SESSION['success'] = "Request Rejected!";
            } elseif ($action === 'create_request') {
                // Handle New Request
                $emp_id = $_POST['employee_id'];
                $type = $_POST['leave_type'];
                $start = $_POST['start_date'];
                $end = $_POST['end_date'];
                $reason = $_POST['reason'];
                
                // Calculate days
                $diff = strtotime($end) - strtotime($start);
                $days = round($diff / (60 * 60 * 24)) + 1;
                
                if($days < 1) $days = 1;

                $stmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, days_count, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
                $stmt->execute([$emp_id, $type, $start, $end, $days, $reason]);
                $_SESSION['success'] = "New Leave Request Submitted Successfully!";
            }
            
            // Redirect to avoid resubmission safe way
            echo "<script>window.location.href='leave-time.php';</script>";
            exit;
            
        } catch (PDOException $e) {
             $_SESSION['error'] = $e->getMessage();
        }
    }
}

// Include sidebar AFTER logic (Output started here)
require_once '../includes/sidebar.php';

// Initialize theme
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$currentTheme = $_SESSION['theme'];

// ============ CREATE TABLES IF NOT EXISTS ============
function createLeaveTables($pdo) {
    // Leave Requests Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        leave_type VARCHAR(50) NOT NULL, /* Sick, Vacation, Emergency, Statutory */
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        days_count INT DEFAULT 1,
        reason TEXT,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Leave Balances Table (Simplified)
    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_balances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        year INT NOT NULL,
        vl_credits DECIMAL(5,2) DEFAULT 15.00, /* Vacation Leave */
        sl_credits DECIMAL(5,2) DEFAULT 15.00, /* Sick Leave */
        el_credits DECIMAL(5,2) DEFAULT 3.00,  /* Emergency Leave */
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_emp_year (employee_id, year)
    )");
}

createLeaveTables($pdo);

// ============ DATA FETCHING ============
// Fetch Balances for Display (Logic)
// ... (Balances fetching is further down or handled separately, but we focus on Requests here)

// ============ DATA FETCHING ============
// Fetch Requests
$requests = [];
try {
    // UPDATED: ORDER BY id DESC ensures newest requests appear at the top
    $sql = "SELECT lr.*, e.name as employee_name, e.department, e.job_title 
            FROM leave_requests lr
            LEFT JOIN employees e ON lr.employee_id = e.id
            ORDER BY lr.id DESC";
    $requests = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might be empty
}

// Fetch Active Employees for Dropdown (MOVED HERE)
$employees_list = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM employees WHERE status = 'Active' ORDER BY name");
    $employees_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Display Messages
if (isset($_SESSION['error'])) {
    echo '<div style="background:#e74a3b; color:white; padding:10px; margin:20px; border-radius:5px;">' . htmlspecialchars($_SESSION['error']) . '</div>';
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    echo '<div style="background:#1cc88a; color:white; padding:10px; margin:20px; border-radius:5px;">' . htmlspecialchars($_SESSION['success']) . '</div>';
    unset($_SESSION['success']);
}

// Stats
$stats = [
    'pending' => 0,
    'on_leave' => 0, // Mock: Active today
    'total_requests' => count($requests)
];
foreach($requests as $r) {
    if($r['status'] == 'Pending') $stats['pending']++;
    // Simple check if today is within range
    $today = date('Y-m-d');
    if($r['status'] == 'Approved' && $today >= $r['start_date'] && $today <= $r['end_date']) {
        $stats['on_leave']++;
    }
}

// Auto-Seed Balances for Active Employees
function seedLeaveBalances($pdo) {
    $current_year = date('Y');
    try {
        $stmt = $pdo->query("SELECT id FROM employees WHERE status = 'Active'");
        while ($row = $stmt->fetch()) {
            // Use INSERT IGNORE to prevent duplicates for (employee_id, year) unique key
            $sql = "INSERT IGNORE INTO leave_balances (employee_id, year, vl_credits, sl_credits, el_credits) 
                    VALUES (?, ?, 15.00, 15.00, 3.00)";
            $pdo->prepare($sql)->execute([$row['id'], $current_year]);
        }
    } catch (PDOException $e) { /* Ignore setup errors */ }
}
seedLeaveBalances($pdo);

// Fetch Balances for Display
$balances = [];
try {
    $current_year = date('Y');
    $sql = "SELECT lb.*, e.name, e.department, e.job_title 
            FROM leave_balances lb 
            JOIN employees e ON lb.employee_id = e.id 
            WHERE lb.year = ? 
            ORDER BY e.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$current_year]);
    $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave & Time-Off Management | HR System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .modal {
            display: none; position: fixed; z-index: 2000; left: 0; top: 0;
            width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: #fefefe; margin: 10% auto; padding: 20px;
            border: 1px solid #888; width: 500px; border-radius: var(--border-radius);
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .close { color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        .form-control {
            width: 100%; padding: 0.5rem; border: 1px solid #ddd;
            border-radius: var(--border-radius); font-size: 1rem;
        }

        /* Theme Variables - MATCHING PAYROLL CALCULATION */
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

        /* Base Styles */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            transition: background 0.3s, color 0.3s;
            line-height: 1.4;
        }

        body.dark-mode { background-color: var(--dark-bg); color: var(--text-light); }
        
        /* Modal Dark Mode Overrides */
        body.dark-mode .modal-content { background-color: var(--dark-card); color: white; border-color: #555; }
        body.dark-mode .form-control { background-color: #2d3748; color: white; border-color: #555; }

        /* Theme Toggle */
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

        body.dark-mode .theme-toggle-btn { background: #2d3748; border-color: #4a5568; color: white; }
        .theme-toggle-btn:hover { background: #e9ecef; transform: translateY(-1px); }
        body.dark-mode .theme-toggle-btn:hover { background: #4a5568; }

        /* Main Content */
        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            margin-top: 60px;
        }
        body.dark-mode .main-content { background-color: var(--dark-bg); }

        .content-area {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        body.dark-mode .content-area { background: var(--dark-card); }

        /* Header */
        .page-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }
        body.dark-mode .page-header { background: #2d3748; border-bottom: 1px solid #4a5568; }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .page-subtitle { color: #6c757d; font-size: 0.9rem; }
        body.dark-mode .page-subtitle { color: #a0aec0; }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .stat-card {
            background: #f8f9fc; padding: 1rem; border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
        }
        body.dark-mode .stat-card { background: #2d3748; }

        .stat-card.warning { border-left-color: var(--warning-color); }
        .stat-card.success { border-left-color: var(--success-color); }
        .stat-card.info { border-left-color: var(--info-color); }

        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--primary-color); }
        .stat-card.warning .stat-value { color: var(--warning-color); }
        .stat-card.success .stat-value { color: var(--success-color); }
        .stat-card.info .stat-value { color: var(--info-color); }

        .stat-label {
            font-size: 0.8rem; color: #6c757d;
            text-transform: uppercase; font-weight: 600;
        }
        body.dark-mode .stat-label { color: #a0aec0; }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .data-table th {
            background: #f8f9fc; padding: 0.75rem; text-align: center;
            font-weight: 600; border-bottom: 1px solid #e3e6f0;
        }
        body.dark-mode .data-table th { background: #2d3748; border-bottom: 1px solid #4a5568; }
        .data-table td {
            padding: 0.75rem; border-bottom: 1px solid #e3e6f0; text-align: center;
        }
        body.dark-mode .data-table td { border-bottom: 1px solid #4a5568; }

        /* Badges */
        .status-pill {
            padding: 0.25rem 0.6rem; border-radius: 50px; font-size: 0.8rem; font-weight: bold;
            min-width: 90px; display: inline-block;
        }
        .bg-pending { background: #fff3cd; color: #856404; }
        .bg-approved { background: #d4edda; color: #155724; }
        .bg-rejected { background: #f8d7da; color: #721c24; }
        
        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem; border: none; border-radius: var(--border-radius);
            cursor: pointer; font-weight: 600; transition: all 0.3s;
            display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; font-size: 0.9rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { background: #2e59d9; transform: translateY(-1px); }
        
        .btn-sm { padding: 0.3rem 0.7rem; font-size: 0.8rem; }
        .btn-success { background: var(--success-color); color: white; }

        /* Grid for Actions */
        .action-bar {
            display: flex; justify-content: flex-end; padding: 1.5rem;
            background: white; border-bottom: 1px solid #e3e6f0;
        }
        body.dark-mode .action-bar { background: var(--dark-card); border-color: #4a5568; }

    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">
    <!-- ... (Existing Theme Toggle, etc) ... -->
    <!-- ... (Skipping middle parts, handled by replace_file_content smartly) ... --> 
    <!-- ... -->


    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <!-- Theme Toggle -->
    <div class="theme-toggle-container">
        <a href="?toggle_theme=1" class="theme-toggle-btn">
            <?php if ($currentTheme === 'light'): ?>
                <i class="fas fa-moon"></i> Dark Mode
            <?php else: ?>
                <i class="fas fa-sun"></i> Light Mode
            <?php endif; ?>
        </a>
    </div>

    <div class="main-content">
        <!-- Page Header -->
        <div class="content-area">
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-calendar-check"></i> Leave & Time-Off Management
                </h1>
                <p class="page-subtitle">Track accrual, usage, and conversion of sick leave, vacation leave, and other statutory leaves.</p>
            </div>
        </div>

        <!-- Stats -->
        <div class="content-area" style="background: transparent; box-shadow: none; padding:0; margin-bottom:1.5rem;">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Requests</div>
                    <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-label">Pending Approval</div>
                    <div class="stat-value"><?php echo $stats['pending']; ?></div>
                </div>
                <div class="stat-card info">
                    <div class="stat-label">On Leave Today</div>
                    <div class="stat-value"><?php echo $stats['on_leave']; ?></div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="content-area">
            <div class="page-header" style="background: white; border-bottom: none; display:flex; justify-content:space-between; align-items:center;">
                <h2 style="font-size:1.2rem; margin:0;">Leave Requests</h2>
                <button class="btn btn-primary btn-sm" onclick="openModal()"><i class="fas fa-plus"></i> New Request</button>
            </div>
            
            <div style="padding: 0 1.5rem 1.5rem 1.5rem; overflow-x:auto;">
                <table class="data-table">
                    <!-- ... table content ... -->
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Leave Type</th>
                            <th>Date Range</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($requests as $r): ?>
                        <tr>
                            <td style="font-weight:600; text-align:left;">
                                <?php echo htmlspecialchars($r['employee_name']); ?><br>
                                <span style="font-size:0.8rem; font-weight:normal; color:#888;"><?php echo htmlspecialchars($r['department']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($r['leave_type']); ?></td>
                            <td>
                                <?php echo date('M d', strtotime($r['start_date'])); ?> - 
                                <?php echo date('M d, Y', strtotime($r['end_date'])); ?>
                            </td>
                            <td><?php echo $r['days_count']; ?></td>
                            <td>
                                <?php
                                    $s = $r['status'];
                                    $cls = 'bg-pending';
                                    if($s == 'Approved') $cls = 'bg-approved';
                                    if($s == 'Rejected') $cls = 'bg-rejected';
                                ?>
                                <span class="status-pill <?php echo $cls; ?>"><?php echo $s; ?></span>
                            </td>
                            <td>
                                <!-- Actions Wrapper -->
                                <div style="display:flex; gap:5px; justify-content:center;">
                                    <?php if($r['status'] == 'Pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                            <input type="hidden" name="action" value="approve">
                                            <button type="submit" class="btn btn-success btn-sm" title="Approve" onclick="return confirm('Approve?');">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-danger btn-sm" title="Reject" style="background-color: var(--danger-color); color:white;" onclick="return confirm('Reject?');">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-lock"></i></button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- Leave Balances Section -->
    <div class="content-area" style="margin: 0 2rem 2rem 2rem;">
        <div class="page-header" style="background: white; border-bottom: none;">
            <h2 style="font-size:1.2rem; margin:0;"><i class="fas fa-wallet"></i> Employee Leave Credits (<?php echo date('Y'); ?>)</h2>
        </div>
        <div style="padding: 0 1.5rem 1.5rem 1.5rem; overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Vacation Leave (VL)</th>
                        <th>Sick Leave (SL)</th>
                        <th>Emergency Leave (EL)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($balances)): ?>
                        <?php foreach($balances as $b): ?>
                        <tr>
                            <td style="font-weight:600; text-align:left;">
                                <?php echo htmlspecialchars($b['name']); ?><br>
                                <span style="font-size:0.8rem; font-weight:normal; color:#888;"><?php echo htmlspecialchars($b['department']); ?></span>
                            </td>
                            <td style="color:var(--primary-color); font-weight:bold;"><?php echo number_format($b['vl_credits'], 1); ?></td>
                            <td style="color:var(--info-color); font-weight:bold;"><?php echo number_format($b['sl_credits'], 1); ?></td>
                            <td style="color:var(--warning-color); font-weight:bold;"><?php echo number_format($b['el_credits'], 1); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4">No balance records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- New Request Modal -->
    <div id="requestModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>New Leave Request</h2>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_request">
                
                <div class="form-group">
                    <label>Employee</label>
                    <select name="employee_id" class="form-control" required>
                        <option value="">-- Select Employee --</option>
                        <?php foreach($employees_list as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Leave Type</label>
                    <select name="leave_type" class="form-control" required>
                        <option value="Vacation Leave">Vacation Leave</option>
                        <option value="Sick Leave">Sick Leave</option>
                        <option value="Emergency Leave">Emergency Leave</option>
                        <option value="Maternity/Paternity Leave">Maternity/Paternity Leave</option>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem;">
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Reason</label>
                    <textarea name="reason" class="form-control" rows="3" placeholder="Reason for leave..."></textarea>
                </div>

                <div style="text-align:right; margin-top:1rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('requestModal').style.display = 'block';
        }
        function closeModal() {
            document.getElementById('requestModal').style.display = 'none';
        }
        // Close modal if clicked outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('requestModal')) {
                closeModal();
            }
        }
    </script>
</html>
