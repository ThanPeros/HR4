<?php
// retirement-longterm.php - Retirement & Long-Term Benefits Management
session_start();

require_once '../includes/sidebar.php';

// Database connection
if (!file_exists('../config/db.php')) {
    // Fallback if config not found (copied from payroll-calculation.php pattern)
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
    // Retirement Plans Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS retirement_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_name VARCHAR(100),
        type ENUM('Provident Fund', 'Pension', 'Long-Service Benefit', 'Other') DEFAULT 'Provident Fund',
        description TEXT,
        employer_contribution_percent DECIMAL(5,2) DEFAULT 0.00,
        employee_contribution_percent DECIMAL(5,2) DEFAULT 0.00,
        vesting_period_years INT DEFAULT 0,
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Enrollments
    $pdo->exec("CREATE TABLE IF NOT EXISTS retirement_enrollments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT,
        plan_id INT,
        enrollment_date DATE,
        status ENUM('Active', 'Suspended', 'Completed', 'Eligible') DEFAULT 'Active',
        total_accumulated_value DECIMAL(15,2) DEFAULT 0.00,
        remarks TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed Data for Plans
    $checkPlans = $pdo->query("SELECT COUNT(*) FROM retirement_plans")->fetchColumn();
    if ($checkPlans == 0) {
        $pdo->exec("INSERT INTO retirement_plans (plan_name, type, description, employer_contribution_percent, employee_contribution_percent, vesting_period_years) VALUES 
            ('Standard Provident Fund', 'Provident Fund', 'Company matched savings plan', 5.00, 5.00, 5),
            ('Executive Pension Scheme', 'Pension', 'Defined benefit for executives', 10.00, 2.00, 10),
            ('10-Year Service Award', 'Long-Service Benefit', 'Bonus for long service', 100.00, 0.00, 10)
        ");
    }


    // Schema Migration: Check if 'plan_name' exists, if not and 'name' exists, rename it.
    try {
        $cols = $pdo->query("DESCRIBE retirement_plans")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('plan_name', $cols) && in_array('name', $cols)) {
            $pdo->exec("ALTER TABLE retirement_plans CHANGE name plan_name VARCHAR(100)");
        } elseif (!in_array('plan_name', $cols)) {
            // If neither exists (unlikely given SELECT * worked), add it
             $pdo->exec("ALTER TABLE retirement_plans ADD COLUMN plan_name VARCHAR(100) AFTER id");
        }
    } catch (Exception $e) { 
        // Ignore if error, e.g. permission denied
    }
}
checkAndCreateTables($pdo);

// -------------------------------------------------------------------------
// ACTION HANDLERS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ADD NEW PLAN
    if (isset($_POST['add_plan'])) {
        $name = $_POST['plan_name'];
        $type = $_POST['type'];
        $desc = $_POST['description'];
        $er_cont = $_POST['employer_contribution'] ?? 0;
        $ee_cont = $_POST['employee_contribution'] ?? 0;
        $vesting = $_POST['vesting_period'] ?? 0;
        
        $stmt = $pdo->prepare("INSERT INTO retirement_plans (plan_name, type, description, employer_contribution_percent, employee_contribution_percent, vesting_period_years) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $type, $desc, $er_cont, $ee_cont, $vesting]);
        
        $_SESSION['success_message'] = "New Retirement Plan added successfully.";
        header("Location: retirement-longterm.php"); // PRG pattern
        exit;
    }

    // ENROLL EMPLOYEE
    if (isset($_POST['enroll_employee'])) {
        $emp_id = $_POST['employee_id'];
        $plan_id = $_POST['plan_id'];
        $date = $_POST['enrollment_date'];
        $value = $_POST['initial_value'] ?? 0;
        
        // Check if already enrolled
        $check = $pdo->prepare("SELECT id FROM retirement_enrollments WHERE employee_id = ? AND plan_id = ?");
        $check->execute([$emp_id, $plan_id]);
        if ($check->rowCount() > 0) {
            $_SESSION['error_message'] = "Employee is already enrolled in this plan.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO retirement_enrollments (employee_id, plan_id, enrollment_date, total_accumulated_value) VALUES (?, ?, ?, ?)");
            $stmt->execute([$emp_id, $plan_id, $date, $value]);
            $_SESSION['success_message'] = "Employee enrolled successfully.";
        }
        header("Location: retirement-longterm.php");
        exit;
    }
}

// -------------------------------------------------------------------------
// DATA FETCHING
// -------------------------------------------------------------------------

// Fetch Plans
$plans = $pdo->query("SELECT * FROM retirement_plans ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Enrollments (with joins)
// Using LEFT JOINs to be safe even if linked data is missing, though normally it shouldn't be.
// Assuming 'employees' table exists from previous context.
try {
    $enrollmentsQuery = "
        SELECT 
            re.*, 
            e.name as employee_name, 
            e.department, 
            rp.plan_name, 
            rp.type as plan_type
        FROM retirement_enrollments re
        LEFT JOIN employees e ON re.employee_id = e.id
        LEFT JOIN retirement_plans rp ON re.plan_id = rp.id
        ORDER BY re.enrollment_date DESC
    ";
    $enrollments = $pdo->query($enrollmentsQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $enrollments = []; // Fallback if employees table issue
}

// Fetch Employees for Dropdown
try {
    $employees = $pdo->query("SELECT id, name FROM employees WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $employees = []; 
}

// Stats
$totalPlans = count($plans);
$totalEnrolled = count($enrollments);
$totalFundValue = array_sum(array_column($enrollments, 'total_accumulated_value'));

// Theme logic
$currentTheme = $_SESSION['theme'] ?? 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retirement & Benefits | HR System</title>
    <!-- Use Bootstrap for consistency -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Copied Styles from payroll-calculation.php -->
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
            --secondary-color: #2c3e50; /* Adjust for main content background */
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

        .filters-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;
        }

        .filters-title {
            font-size: 1.2rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0;
        }

        .btn {
            padding: 0.375rem 0.75rem; border: none; border-radius: var(--border-radius); cursor: pointer;
            font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; font-size: 0.875rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { background: #2e59d9; transform: translateY(-1px); }
        .btn-success { background: var(--success-color); color: white; }
        .btn-warning { background: var(--warning-color); color: #333; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-info { background: var(--info-color); color: white; }

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
        .bg-Active { background: var(--success-color); }
        .bg-Inactive { background: var(--danger-color); }
        .bg-Archive { background: var(--warning-color); }

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
        
        /* Modal Form Labels */
        .form-label { font-weight: 600; font-size: 0.9rem; }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-piggy-bank"></i> Retirement & Long-Term Benefits</h1>
                <p class="page-subtitle">Manage provident funds, pensions, and long-service eligibility</p>
            </div>
            <div>
                 <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#enrollModal">
                    <i class="fas fa-user-plus"></i> Enroll Employee
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

        <!-- Stats Row (Design matched with payroll-calculation.php Stats) -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Total Active Plans</h6>
                    <h3 class="text-primary"><?php echo $totalPlans; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Active Enrollments</h6>
                    <h3 class="text-success"><?php echo $totalEnrolled; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Total Fund Value</h6>
                    <h3 class="text-info">₱<?php echo number_format($totalFundValue, 2); ?></h3>
                </div>
            </div>
        </div>

        <!-- Content Row -->
        <div class="row">
            
            <!-- Full Width: Plans List -->
            <div class="col-lg-12 mb-4">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-list-alt"></i> Available Benefit Plans</h3>
                         <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addPlanModal">
                            <i class="fas fa-plus"></i> Add New Plan
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Plan Name</th>
                                    <th>Type</th>
                                    <th>Contribution (ER / EE)</th>
                                    <th>Vesting Period</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($plans as $plan): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($plan['plan_name'] ?? $plan['name'] ?? 'Unknown Plan'); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($plan['description']); ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo $plan['type']; ?></span></td>
                                    <td>
                                        <div class="small">
                                            Emp: <span class="text-success"><?php echo $plan['employer_contribution_percent']; ?>%</span> / 
                                            Ee: <span class="text-info"><?php echo $plan['employee_contribution_percent']; ?>%</span>
                                        </div>
                                    </td>
                                    <td><?php echo $plan['vesting_period_years']; ?> Years</td>
                                    <td><span class="badge bg-<?php echo $plan['status']; ?>"><?php echo $plan['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($plans)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding:2rem; color:#858796;">No retirement plans defined.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Full Width: Employee Participation -->
            <div class="col-lg-12">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-users"></i> Participation & Eligibility</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Enrolled Plan</th>
                                    <th>Date Enrolled</th>
                                    <th>Accumulated Value</th>
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
                                        <?php echo htmlspecialchars($enr['plan_name']); ?><br>
                                        <span class="badge bg-light text-dark border"><?php echo $enr['plan_type']; ?></span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($enr['enrollment_date'])); ?></td>
                                    <td><span class="text-success fw-bold">₱<?php echo number_format($enr['total_accumulated_value'], 2); ?></span></td>
                                    <td><span class="badge bg-<?php echo $enr['status']; ?>"><?php echo $enr['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($enrollments)): ?>
                                    <tr><td colspan="5" style="text-align:center; padding:2rem; color:#858796;">No employees enrolled yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal: Add Plan -->
    <div class="modal fade" id="addPlanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Retirement Plan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Plan Name</label>
                            <input type="text" name="plan_name" class="form-control" required placeholder="e.g. Senior Provident Fund">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option value="Provident Fund">Provident Fund</option>
                                <option value="Pension">Pension</option>
                                <option value="Long-Service Benefit">Long-Service Benefit</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Employer Conti (%)</label>
                                <input type="number" step="0.01" name="employer_contribution" class="form-control" value="0.00">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Employee Conti (%)</label>
                                <input type="number" step="0.01" name="employee_contribution" class="form-control" value="0.00">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Vesting Period (Years)</label>
                            <input type="number" name="vesting_period" class="form-control" value="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_plan" class="btn btn-primary">Create Plan</button>
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
                        <h5 class="modal-title">Enroll Employee</h5>
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
                            <label class="form-label">Plan</label>
                            <select name="plan_id" class="form-select" required>
                                <?php foreach ($plans as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['plan_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Enrollment Date</label>
                            <input type="date" name="enrollment_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Initial Fund Value (Opening Balance)</label>
                            <input type="number" step="0.01" name="initial_value" class="form-control" value="0.00">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="enroll_employee" class="btn btn-primary">Enroll</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
