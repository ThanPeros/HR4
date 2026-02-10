<?php
// hmo-benefits/benefits-eligibility.php - Benefits Eligibility, Enrollment & Records
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
    // 1. Eligibility Rules
    $pdo->exec("CREATE TABLE IF NOT EXISTS benefits_eligibility_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rule_name VARCHAR(100) NOT NULL,
        criteria TEXT,
        applicable_to ENUM('All Employees', 'Regular', 'Probationary', 'Executive') DEFAULT 'All Employees',
        status ENUM('Active', 'Inactive') DEFAULT 'Active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Enrollment Periods
    $pdo->exec("CREATE TABLE IF NOT EXISTS benefits_enrollment_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        period_name VARCHAR(100) NOT NULL,
        start_date DATE,
        end_date DATE,
        status ENUM('Open', 'Closed', 'Upcoming') DEFAULT 'Upcoming',
        description TEXT
    )");

    // 3. Employee Benefit Profiles (Records)
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_benefit_profiles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT,
        benefit_type VARCHAR(50) NOT NULL,
        status ENUM('Active', 'Expired', 'Pending', 'Ineligible') DEFAULT 'Pending',
        activation_date DATE,
        expiration_date DATE,
        notes TEXT,
        documents_count INT DEFAULT 0,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Seed Data
    $checkRules = $pdo->query("SELECT COUNT(*) FROM benefits_eligibility_rules")->fetchColumn();
    if ($checkRules == 0) {
        $pdo->exec("INSERT INTO benefits_eligibility_rules (rule_name, criteria, applicable_to, status) VALUES 
            ('Standard HMO Coverage', 'Immediate upon hiring', 'Regular', 'Active'),
            ('Executive Insurance', 'Level 5+ Managers only', 'Executive', 'Active'),
            ('Dependents Coverage', 'Up to 2 dependents free after 1 year', 'Regular', 'Active')
        ");
    }

    $checkPeriods = $pdo->query("SELECT COUNT(*) FROM benefits_enrollment_periods")->fetchColumn();
    if ($checkPeriods == 0) {
        $pdo->exec("INSERT INTO benefits_enrollment_periods (period_name, start_date, end_date, status, description) VALUES 
            ('2026 Annual Enrollment', '2026-01-01', '2026-01-31', 'Closed', 'Main enrollment for HMO and Insurance'),
            ('Q2 Special Enrollment', '2026-04-01', '2026-04-15', 'Upcoming', 'For new hires and special cases')
        ");
    }
}
checkAndCreateTables($pdo);

// -------------------------------------------------------------------------
// ACTION HANDLERS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ADD ELIGIBILITY RULE
    if (isset($_POST['add_rule'])) {
        $name = $_POST['rule_name'];
        $criteria = $_POST['criteria'];
        $applies = $_POST['applicable_to'];
        
        $stmt = $pdo->prepare("INSERT INTO benefits_eligibility_rules (rule_name, criteria, applicable_to) VALUES (?, ?, ?)");
        $stmt->execute([$name, $criteria, $applies]);
        
        $_SESSION['success_message'] = "Eligibility Rule added successfully.";
        header("Location: benefits-eligibility.php"); 
        exit;
    }

    // ADD ENROLLMENT PERIOD
    if (isset($_POST['add_period'])) {
        $name = $_POST['period_name'];
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        $status = $_POST['status'];
        
        $stmt = $pdo->prepare("INSERT INTO benefits_enrollment_periods (period_name, start_date, end_date, description, status) VALUES (?, ?, ?, '', ?)");
        $stmt->execute([$name, $start, $end, $status]);
        
        $_SESSION['success_message'] = "Enrollment period created.";
        header("Location: benefits-eligibility.php");
        exit;
    }

    // UPDATE PROFILE
    if (isset($_POST['update_profile'])) {
        $emp_id = $_POST['employee_id'];
        $type = $_POST['benefit_type'];
        $status = $_POST['status'];
        $activation = $_POST['activation_date'];
        $expiration = $_POST['expiration_date'];
        
        $stmt = $pdo->prepare("INSERT INTO employee_benefit_profiles (employee_id, benefit_type, status, activation_date, expiration_date) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$emp_id, $type, $status, $activation, $expiration]);
        
        $_SESSION['success_message'] = "Employee profile updated.";
        header("Location: benefits-eligibility.php");
        exit;
    }
}

// -------------------------------------------------------------------------
// DATA FETCHING
// -------------------------------------------------------------------------

// Fetch Rules
$rules = $pdo->query("SELECT * FROM benefits_eligibility_rules ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Periods
$periods = $pdo->query("SELECT * FROM benefits_enrollment_periods ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Profiles
$profilesQuery = "
    SELECT 
        p.*, 
        e.name as employee_name, 
        e.department,
        e.employee_id as emp_code
    FROM employee_benefit_profiles p
    LEFT JOIN employees e ON p.employee_id = e.id
    ORDER BY p.last_updated DESC
";
$profiles = $pdo->query($profilesQuery)->fetchAll(PDO::FETCH_ASSOC);

// Fetch Employees for Dropdown
$employees = $pdo->query("SELECT id, name FROM employees WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$activeRules = 0;
foreach($rules as $r) if($r['status'] == 'Active') $activeRules++;

$openPeriods = 0;
foreach($periods as $p) if($p['status'] == 'Open') $openPeriods++;

$activeProfiles = 0;
foreach($profiles as $prof) if($prof['status'] == 'Active') $activeProfiles++;

$currentTheme = $_SESSION['theme'] ?? 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Benefits Eligibility & Records | HR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Shared Styles (matched with leave-welfare.php) -->
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
        .bg-Inactive { background: var(--secondary-color); color: #858796; border: 1px solid #ccc; }
        .bg-Open { background: var(--success-color); }
        .bg-Closed { background: var(--danger-color); }
        .bg-Upcoming { background: var(--info-color); }
        .bg-Expired { background: var(--danger-color); }
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
                <h1 class="page-title"><i class="fas fa-file-contract"></i> Benefits Eligibility & Records</h1>
                <p class="page-subtitle">Track eligibility rules, enrollment periods, and employee benefit profiles</p>
            </div>
            <div>
                 <button class="btn btn-purple" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
                    <i class="fas fa-user-edit"></i> Update Profile
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
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Active Eligibility Rules</h6>
                    <h3 class="text-primary"><?php echo $activeRules; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Open Enrollment Periods</h6>
                    <h3 class="text-success"><?php echo $openPeriods; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Active Employee Profiles</h6>
                    <h3 class="text-info"><?php echo $activeProfiles; ?></h3>
                </div>
            </div>
        </div>

        <!-- Content Row -->
        <div class="row">
            
            <!-- Eligibility Rules -->
            <div class="col-lg-7 mb-4">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-list-ul"></i> Eligibility Rules</h3>
                         <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addRuleModal">
                            <i class="fas fa-plus"></i> Add Rule
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rule Name</th>
                                    <th>Criteria</th>
                                    <th>Applicable To</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rules as $rule): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($rule['rule_name']); ?></strong></td>
                                    <td><small><?php echo htmlspecialchars($rule['criteria']); ?></small></td>
                                    <td><span class="badge bg-secondary"><?php echo $rule['applicable_to']; ?></span></td>
                                    <td><span class="badge bg-<?php echo $rule['status']; ?>"><?php echo $rule['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

             <!-- Enrollment Periods -->
             <div class="col-lg-5 mb-4">
                <div class="report-card" style="border-left-color: var(--info-color);">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-calendar-alt"></i> Enrollment Periods</h3>
                        <button class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#addPeriodModal">
                            <i class="fas fa-plus"></i> New
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Period / Event</th>
                                    <th>Dates</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($periods as $p): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($p['period_name']); ?></strong>
                                    </td>
                                    <td>
                                        <div style="font-size: 0.8rem;">
                                            <?php echo date('M d', strtotime($p['start_date'])); ?> - 
                                            <?php echo date('M d, Y', strtotime($p['end_date'])); ?>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-<?php echo $p['status']; ?>"><?php echo $p['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Employee Benefit Profiles & Records -->
            <div class="col-lg-12">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-id-card"></i> Employee Benefit Profiles & Records</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Benefit Type</th>
                                    <th>Status</th>
                                    <th>Activation / Expiration</th>
                                    <th>Documents</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $prof): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prof['employee_name'] ?? 'Unknown'); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($prof['department'] ?? '-'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($prof['benefit_type']); ?></td>
                                    <td><span class="badge bg-<?php echo $prof['status']; ?>"><?php echo $prof['status']; ?></span></td>
                                    <td>
                                        <small class="d-block">Active: <?php echo $prof['activation_date'] ? date('M d, Y', strtotime($prof['activation_date'])) : '-'; ?></small>
                                        <small class="d-block text-muted">Expires: <?php echo $prof['expiration_date'] ? date('M d, Y', strtotime($prof['expiration_date'])) : '-'; ?></small>
                                    </td>
                                    <td><i class="fas fa-paperclip"></i> <?php echo $prof['documents_count']; ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary"><i class="fas fa-eye"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($profiles)): ?>
                                    <tr><td colspan="6" style="text-align:center; padding:2rem; color:#858796;">No employee profiles found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal: Add Eligibility Rule -->
    <div class="modal fade" id="addRuleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">New Eligibility Rule</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Rule Name</label>
                            <input type="text" name="rule_name" class="form-control" required placeholder="e.g. Regular Employees">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Applicable To</label>
                            <select name="applicable_to" class="form-select">
                                <option>All Employees</option>
                                <option>Regular</option>
                                <option>Probationary</option>
                                <option>Executive</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Criteria / Description</label>
                            <textarea name="criteria" class="form-control" rows="3" placeholder="Describe the rule..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_rule" class="btn btn-primary">Save Rule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Add Enrollment Period -->
    <div class="modal fade" id="addPeriodModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">New Enrollment Period</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Period Name</label>
                            <input type="text" name="period_name" class="form-control" required placeholder="e.g. 2026 Annual Enrollment">
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Upcoming">Upcoming</option>
                                <option value="Open">Open</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_period" class="btn btn-primary">Create Period</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Update Benefit Profile -->
    <div class="modal fade" id="updateProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Employee Benefit Profile</h5>
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
                            <label class="form-label">Benefit Type</label>
                            <select name="benefit_type" class="form-select">
                                <option>HMO Coverage</option>
                                <option>Life Insurance</option>
                                <option>Dental</option>
                                <option>Vision</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Expired">Expired</option>
                                <option value="Pending">Pending</option>
                                <option value="Ineligible">Ineligible</option>
                            </select>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3">
                                <label class="form-label">Activation Date</label>
                                <input type="date" name="activation_date" class="form-control">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Expiration Date</label>
                                <input type="date" name="expiration_date" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_profile" class="btn btn-purple">Update Profile</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
