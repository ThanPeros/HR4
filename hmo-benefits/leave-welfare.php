<?php
// leave-welfare.php - Leave & Welfare Benefits Management
session_start();

require_once '../includes/sidebar.php';

// Database connection
if (!file_exists('../config/db.php')) {
    if (!isset($pdo)) {
        try {
            $host = 'localhost';
            $dbname = 'dummyhr4';
            $username = 'root';
            $password = '';
            $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            die("DB Connection failed: " . $e->getMessage());
        }
    }
} else {
    require_once '../config/db.php';
}

// -------------------------------------------------------------------------
// SCHEMA SETUP
// -------------------------------------------------------------------------
function checkAndCreateTables($pdo) {
    // Leave Balances & Entitlements
    $pdo->exec("CREATE TABLE IF NOT EXISTS leave_entitlements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT,
        leave_type VARCHAR(50) NOT NULL,
        total_days DECIMAL(5,2) DEFAULT 0.00,
        used_days DECIMAL(5,2) DEFAULT 0.00,
        remaining_days DECIMAL(5,2) DEFAULT 0.00,
        year INT,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Welfare Programs
    $pdo->exec("CREATE TABLE IF NOT EXISTS welfare_programs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        program_name VARCHAR(100),
        category ENUM('Wellness', 'Welfare', 'Perk', 'Other') DEFAULT 'Welfare',
        description TEXT,
        eligibility_criteria VARCHAR(255),
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Employee Welfare Enrollments/Usage
    $pdo->exec("CREATE TABLE IF NOT EXISTS welfare_enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT,
        program_id INT,
        enrollment_date DATE,
        status ENUM('Active', 'Completed', 'Approved', 'Pending') DEFAULT 'Active',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed Welfare Programs
    $checkWelfare = $pdo->query("SELECT COUNT(*) FROM welfare_programs")->fetchColumn();
    if ($checkWelfare == 0) {
        $pdo->exec("INSERT INTO welfare_programs (program_name, category, description, eligibility_criteria) VALUES 
            ('Annual Health Checkup', 'Wellness', 'Free comprehensive medical exam', 'All Regular Employees'),
            ('Gym Membership Subsidy', 'Wellness', '50% reimbursement for gym fees', 'Regular Employees > 6 months'),
            ('Mental Health Assistant', 'Wellness', 'Free counseling sessions', 'All Employees'),
            ('Shuttle Service', 'Welfare', 'Free transport from key pickup points', 'All Employees'),
            ('Meal Allowance', 'Welfare', 'Daily subsidized lunch', 'On-site Employees')
        ");
    }
}
checkAndCreateTables($pdo);

// -------------------------------------------------------------------------
// ACTION HANDLERS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ADD WELFARE PROGRAM
    if (isset($_POST['add_program'])) {
        $name = $_POST['program_name'];
        $category = $_POST['category'];
        $desc = $_POST['description'];
        $criteria = $_POST['eligibility'];
        
        $stmt = $pdo->prepare("INSERT INTO welfare_programs (program_name, category, description, eligibility_criteria) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $category, $desc, $criteria]);
        
        $_SESSION['success_message'] = "New Welfare Program added successfully.";
        header("Location: leave-welfare.php"); 
        exit;
    }

    // ENROLL IN WELFARE
    if (isset($_POST['enroll_welfare'])) {
        $emp_id = $_POST['employee_id'];
        $prog_id = $_POST['program_id'];
        $date = $_POST['enrollment_date'];
        $notes = $_POST['notes'];
        
        $check = $pdo->prepare("SELECT id FROM welfare_enrollments WHERE employee_id = ? AND program_id = ?");
        $check->execute([$emp_id, $prog_id]);
        if ($check->rowCount() > 0) {
            $_SESSION['error_message'] = "Employee is already enrolled in this program.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO welfare_enrollments (employee_id, program_id, enrollment_date, notes) VALUES (?, ?, ?, ?)");
            $stmt->execute([$emp_id, $prog_id, $date, $notes]);
            $_SESSION['success_message'] = "Employee enrolled successfully.";
        }
        header("Location: leave-welfare.php");
        exit;
    }
}

// -------------------------------------------------------------------------
// DATA FETCHING
// -------------------------------------------------------------------------

// Fetch Welfare Programs
$programs = $pdo->query("SELECT * FROM welfare_programs ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Leave Data (Mocked/Simplified for UI demo as leave usually comes from core HR or Time)
// Here we join with employees to simulate a view
try {
    $leaveQuery = "
        SELECT 
            e.id as employee_id, e.name as employee_name, e.department,
            COALESCE(le.total_days, 15) as vacation_total,
            COALESCE(le.used_days, 0) as vacation_used,
            COALESCE(le.remaining_days, 15) as vacation_balance
        FROM employees e
        LEFT JOIN leave_entitlements le ON e.id = le.employee_id AND le.leave_type = 'Vacation' AND le.year = YEAR(CURRENT_DATE)
        WHERE e.status = 'Active'
        LIMIT 10
    ";
    $leaveData = $pdo->query($leaveQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $leaveData = [];
}

// Fetch Welfare Enrollments
try {
    $welfareQuery = "
        SELECT 
            we.*, 
            e.name as employee_name, 
            e.department, 
            wp.program_name, 
            wp.category
        FROM welfare_enrollments we
        LEFT JOIN employees e ON we.employee_id = e.id
        LEFT JOIN welfare_programs wp ON we.program_id = wp.id
        ORDER BY we.enrollment_date DESC
    ";
    $enrollments = $pdo->query($welfareQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $enrollments = [];
}

// Fetch Employees for Dropdown
try {
    $employees = $pdo->query("SELECT id, name FROM employees WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $employees = []; 
}

// Stats
$totalPrograms = count($programs);
$activeEnrollments = count($enrollments);
$totalLeaveBalance = 0; // Placeholder sum
foreach($leaveData as $l) $totalLeaveBalance += $l['vacation_balance'];

$currentTheme = $_SESSION['theme'] ?? 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leave & Welfare | HR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Shared Styles (matched with retirement-longterm.php) -->
    <style>
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

        * { margin: 0; padding: 0; box-sizing: border-box; }

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
            --secondary-color: #2c3e50;
        }

        /* Enhanced Filter/Action Styles */
        .filters-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: all 0.3s;
        }

        body.dark-mode .filters-container { background: var(--dark-card); }

        .btn {
            padding: 0.375rem 0.75rem; border: none; border-radius: var(--border-radius); cursor: pointer;
            font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; font-size: 0.875rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { background: #2e59d9; transform: translateY(-1px); }
        .btn-purple { background: var(--purple-color); color: white; }
        .btn-purple:hover { background: #5a32a3; color: white; transform: translateY(-1px); }

        /* Report/Table Card Styles */
        .report-card {
            background: white; border-radius: var(--border-radius); padding: 1.5rem;
            box-shadow: var(--shadow); transition: all 0.3s; border-left: 4px solid var(--primary-color);
            margin-bottom: 1.5rem;
        }
        body.dark-mode .report-card { background: var(--dark-card); }

        .report-card-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;
            padding-bottom: 1rem; border-bottom: 1px solid #e3e6f0;
        }
        body.dark-mode .report-card-header { border-bottom: 1px solid #4a5568; }

        .report-card-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0; }

        /* Enhanced Table Styles */
        .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: var(--border-radius); overflow: hidden; }
        body.dark-mode .data-table { background: #2d3748; }

        .data-table th {
            background: #f8f9fc; padding: 0.75rem; text-align: left; font-weight: 600;
            color: #4e73df; border-bottom: 1px solid #e3e6f0;
        }
        body.dark-mode .data-table th { background: #2d3748; color: #63b3ed; border-bottom: 1px solid #4a5568; }

        .data-table td { padding: 0.75rem; border-bottom: 1px solid #e3e6f0; vertical-align: middle; }
        body.dark-mode .data-table td { border-bottom: 1px solid #4a5568; }

        .data-table tr:hover { background: #f8f9fc; transform: scale(1.002); }
        body.dark-mode .data-table tr:hover { background: #2d3748; }

        /* Status Badges */
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; color: white; }
        .bg-Wellness { background: var(--success-color); }
        .bg-Welfare { background: var(--info-color); }
        .bg-Perk { background: var(--purple-color); }
        .bg-Active { background: var(--success-color); }
        .bg-Pending { background: var(--warning-color); }

        /* Main Layout */
        .main-content {
            padding: 2rem; min-height: 100vh; background-color: var(--secondary-color);
            margin-top: 60px;
        }
        body.dark-mode .main-content { background-color: var(--dark-bg); }

        .page-header {
            padding: 1.5rem; margin-bottom: 1.5rem; border-radius: var(--border-radius);
            background: white; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center;
        }
        body.dark-mode .page-header { background: var(--dark-card); }

        .page-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 1rem; }
        .page-subtitle { color: #6c757d; font-size: 0.9rem; margin-bottom: 0; }
        body.dark-mode .page-subtitle { color: #a0aec0; }
        
        .form-label { font-weight: 600; font-size: 0.9rem; }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-umbrella-beach"></i> Leave & Welfare Benefits</h1>
                <p class="page-subtitle">Manage paid leave, wellness programs, and employee perks</p>
            </div>
            <div>
                 <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#enrollModal">
                    <i class="fas fa-user-plus"></i> Enroll in Welfare
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if(isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Active Wellness Programs</h6>
                    <h3 class="text-primary"><?php echo $totalPrograms; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Employee Enrollments</h6>
                    <h3 class="text-success"><?php echo $activeEnrollments; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Total Leave Balance (Simulated)</h6>
                    <h3 class="text-info"><?php echo number_format($totalLeaveBalance); ?> Days</h3>
                </div>
            </div>
        </div>

        <!-- Content Row -->
        <div class="row">
            
            <!-- Welfare Programs -->
            <div class="col-lg-7 mb-4">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-spa"></i> Wellness & Welfare Programs</h3>
                         <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                            <i class="fas fa-plus"></i> New Program
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Program</th>
                                    <th>Category</th>
                                    <th>Eligibility</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($programs as $prog): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prog['program_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($prog['description']); ?></small>
                                    </td>
                                    <td><span class="badge bg-<?php echo $prog['category']; ?>"><?php echo $prog['category']; ?></span></td>
                                    <td><?php echo htmlspecialchars($prog['eligibility_criteria']); ?></td>
                                    <td><span class="badge bg-<?php echo $prog['status']; ?>"><?php echo $prog['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

             <!-- Leave Entitlements Summary (Quick View) -->
             <div class="col-lg-5 mb-4">
                <div class="report-card" style="border-left-color: var(--info-color);">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-calendar-check"></i> Leave Balances (Top 10)</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Total</th>
                                    <th>Used</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leaveData as $leave): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($leave['employee_name']); ?></td>
                                    <td><?php echo $leave['vacation_total']; ?></td>
                                    <td><?php echo $leave['vacation_used']; ?></td>
                                    <td class="fw-bold text-success"><?php echo $leave['vacation_balance']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Full Width: Employee Welfare Enrollments -->
            <div class="col-lg-12">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-users"></i> Employee Welfare Participation</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Program / Benefit</th>
                                    <th>Enrollment Date</th>
                                    <th>Notes</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($enrollments as $enr): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($enr['employee_name'] ?? 'Unknown'); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($enr['department'] ?? '-'); ?></small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($enr['program_name']); ?><br>
                                        <span class="badge bg-<?php echo $enr['category']; ?>"><?php echo $enr['category']; ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($enr['enrollment_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($enr['notes']); ?></td>
                                    <td><span class="badge bg-<?php echo $enr['status']; ?>"><?php echo $enr['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($enrollments)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding:2rem; color:#858796;">No active enrollments found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal: Add Program -->
    <div class="modal fade" id="addProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">New Welfare Program</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Program Name</label>
                            <input type="text" name="program_name" class="form-control" required placeholder="e.g. Gym Subsidy">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <option value="Wellness">Wellness (Health, Mental)</option>
                                <option value="Welfare">Welfare (Transport, Meals)</option>
                                <option value="Perk">Perk (Discounts, Gifts)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Eligibility Criteria</label>
                            <input type="text" name="eligibility" class="form-control" placeholder="e.g. Regular Employees Only">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_program" class="btn btn-primary">Create Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Enroll Employee -->
    <div class="modal fade" id="enrollModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Enroll Employee in Outcome</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select Employee...</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Program / Benefit</label>
                            <select name="program_id" class="form-select" required>
                                <?php foreach ($programs as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['program_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Enrollment Date</label>
                            <input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="enroll_welfare" class="btn btn-primary">Enroll</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
