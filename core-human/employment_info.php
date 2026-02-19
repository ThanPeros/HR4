<?php
// core-human/employment_info.php
ob_start();
include '../includes/sidebar.php';
// Reliable Database Connection
require_once '../config/db.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    global $conn;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    // Fallback connection if included file didn't expose $conn
    $conn = new mysqli('localhost', 'root', '', 'dummy_hr4');
    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }
}

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}
$currentTheme = $_SESSION['theme'];

// Handle custom theme toggle if needed (from report.php pattern)
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

// --- DB MIGRATION & HELPER LOGIC (PRESERVED) --- //
try {
    // --- MASTER RECORD COLUMNS ---
    $cols = [
        "ADD COLUMN IF NOT EXISTS date_hired DATE NULL",
        "ADD COLUMN IF NOT EXISTS date_regularized DATE NULL",
        "ADD COLUMN IF NOT EXISTS employee_id VARCHAR(50) NULL AFTER id",
        "ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'Active' COMMENT 'Employment Status'", 
        "ADD COLUMN IF NOT EXISTS contract VARCHAR(50) DEFAULT 'Regular'",
        "ADD COLUMN IF NOT EXISTS salary DECIMAL(10,2) DEFAULT 0.00",
        
        // New Fields Requested
        "ADD COLUMN IF NOT EXISTS manager VARCHAR(100) NULL COMMENT 'Reporting Manager'",
        "ADD COLUMN IF NOT EXISTS location VARCHAR(100) NULL COMMENT 'Work Location/Branch'",
        "ADD COLUMN IF NOT EXISTS payroll_eligible TINYINT(1) DEFAULT 1",
        "ADD COLUMN IF NOT EXISTS attendance_eligible TINYINT(1) DEFAULT 1",
        "ADD COLUMN IF NOT EXISTS benefits_eligible TINYINT(1) DEFAULT 1",
        "ADD COLUMN IF NOT EXISTS pay_grade VARCHAR(50) NULL COMMENT 'Salary Grade/Level'",
        "ADD COLUMN IF NOT EXISTS work_schedule VARCHAR(100) NULL COMMENT 'Shift ID'",
        "ADD COLUMN IF NOT EXISTS system_role VARCHAR(50) DEFAULT 'Employee' COMMENT 'Admin, HR, Manager, Employee'",
        "ADD COLUMN IF NOT EXISTS account_status VARCHAR(20) DEFAULT 'Enabled' COMMENT 'Enabled/Disabled'",
        "ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];

    foreach ($cols as $colSql) {
        try {
            $conn->query("ALTER TABLE employees $colSql");
        } catch (Exception $e) { /* Ignore if exists */ }
    }

    // Ensure contract column is correct if it was old name
    $check = $conn->query("SHOW COLUMNS FROM employees LIKE 'employment_status'");
    if ($check->num_rows > 0) {
        $conn->query("ALTER TABLE employees CHANGE COLUMN employment_status contract VARCHAR(50) DEFAULT 'Regular'");
    }

    // --- NEW: Contract Types Table (Preserved) ---
    $conn->query("CREATE TABLE IF NOT EXISTS contract_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_name VARCHAR(50) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default types if empty
    $checkTypes = $conn->query("SELECT COUNT(*) as count FROM contract_types");
    if ($checkTypes && $checkTypes->fetch_assoc()['count'] == 0) {
        $conn->query("INSERT INTO contract_types (type_name) VALUES 
            ('Regular'), 
            ('Probationary'), 
            ('Contract'), 
            ('Project-Based'), 
            ('Intern')");
    }

} catch (Exception $e) {
    error_log("DB Migration Error: " . $e->getMessage());
}

// Handle Form Submission (Add/Edit)
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_employee') {
        $id = $_POST['db_id'] ?? '';
        $employee_id = $conn->real_escape_string($_POST['employee_id']);
        $name = $conn->real_escape_string($_POST['name']);
        $date_hired = !empty($_POST['date_hired']) ? "'" . $conn->real_escape_string($_POST['date_hired']) . "'" : "NULL";
        $date_regularized = !empty($_POST['date_regularized']) ? "'" . $conn->real_escape_string($_POST['date_regularized']) . "'" : "NULL";
        $status = $conn->real_escape_string($_POST['status']);
        $contract = $conn->real_escape_string($_POST['contract']);
        $department = $conn->real_escape_string($_POST['department']);
        $job_title = $conn->real_escape_string($_POST['job_title']);
        $salary = !empty($_POST['salary']) ? floatval($_POST['salary']) : 0;
        
        // New Master Fields
        $manager = $conn->real_escape_string($_POST['manager'] ?? '');
        $location = $conn->real_escape_string($_POST['location'] ?? '');
        $pay_grade = $conn->real_escape_string($_POST['pay_grade'] ?? '');
        $work_schedule = $conn->real_escape_string($_POST['work_schedule'] ?? '');
        $system_role = $conn->real_escape_string($_POST['system_role'] ?? 'Employee');
        $account_status = $conn->real_escape_string($_POST['account_status'] ?? 'Enabled');
        
        $payroll_eligible = isset($_POST['payroll_eligible']) ? 1 : 0;
        $attendance_eligible = isset($_POST['attendance_eligible']) ? 1 : 0;
        $benefits_eligible = isset($_POST['benefits_eligible']) ? 1 : 0;

        if (!empty($id)) {
            // Update
            $sql = "UPDATE employees SET 
                    employee_id = '$employee_id',
                    name = '$name',
                    date_hired = $date_hired,
                    date_regularized = $date_regularized,
                    status = '$status',
                    contract = '$contract',
                    department = '$department',
                    job_title = '$job_title',
                    salary = $salary,
                    manager = '$manager',
                    location = '$location',
                    pay_grade = '$pay_grade',
                    work_schedule = '$work_schedule',
                    system_role = '$system_role',
                    account_status = '$account_status',
                    payroll_eligible = $payroll_eligible,
                    attendance_eligible = $attendance_eligible,
                    benefits_eligible = $benefits_eligible
                    WHERE id = '$id'";
            if ($conn->query($sql)) {
                $message = "Employee Master Record updated successfully.";
                $message_type = "success";
            } else {
                $message = "Error updating: " . $conn->error;
                $message_type = "error";
            }
        } else {
            // Insert
            $sql = "INSERT INTO employees (
                        employee_id, name, date_hired, date_regularized, status, contract, department, job_title, salary,
                        manager, location, pay_grade, work_schedule, system_role, account_status,
                        payroll_eligible, attendance_eligible, benefits_eligible
                    ) VALUES (
                        '$employee_id', '$name', $date_hired, $date_regularized, '$status', '$contract', '$department', '$job_title', $salary,
                        '$manager', '$location', '$pay_grade', '$work_schedule', '$system_role', '$account_status',
                        $payroll_eligible, $attendance_eligible, $benefits_eligible
                    )";
            if ($conn->query($sql)) {
                $message = "Employee Master Record created successfully.";
                $message_type = "success";
            } else {
                $message = "Error adding: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}

// Fetch Employees (with basic search)
$search_term = '';
$where_clause = '';
if (isset($_GET['search'])) {
    $search_term = $conn->real_escape_string($_GET['search']);
    $where_clause = "WHERE name LIKE '%$search_term%' OR employee_id LIKE '%$search_term%' OR department LIKE '%$search_term%'";
}

$sql = "SELECT * FROM employees $where_clause ORDER BY name ASC";
$employees = $conn->query($sql);

// Fetch Contract Types for Dropdown
$empTypes = $conn->query("SELECT type_name FROM contract_types ORDER BY type_name ASC");
$typesList = [];
if ($empTypes) {
    while($t = $empTypes->fetch_assoc()) {
        $typesList[] = $t['type_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Master Record | HR System</title>
    <!-- Keeping Bootstrap for Modal functionality -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Copied Styles from report.php -->
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

        /* Enhanced Filter/Action Styles */
        .filters-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: all 0.3s;
        }

        body.dark-mode .filters-container {
            background: var(--dark-card);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            align-items: center;
        }

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
        
        /* Report/Table Card Styles */
        .report-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border-left: 4px solid var(--primary-color);
            margin-bottom: 1.5rem;
        }

        body.dark-mode .report-card {
            background: var(--dark-card);
        }

        .report-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .report-card-header {
            border-bottom: 1px solid #4a5568;
        }

        .report-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0;
        }

        /* Enhanced Table Styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        body.dark-mode .data-table {
            background: #2d3748;
        }

        .data-table th {
            background: #f8f9fc;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #4e73df;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .data-table th {
            background: #2d3748;
            color: #63b3ed;
            border-bottom: 1px solid #4a5568;
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
            vertical-align: middle;
        }

        body.dark-mode .data-table td {
            border-bottom: 1px solid #4a5568;
        }

        .data-table tr:hover {
            background: #f8f9fc;
            transform: scale(1.002);
        }

        body.dark-mode .data-table tr:hover {
            background: #2d3748;
        }

        /* Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-resigned { background: #fff3cd; color: #856404; }
        .status-terminated { background: #f1b0b7; color: #a02828; }

        body.dark-mode .status-active { background: #22543d; color: #9ae6b4; }
        body.dark-mode .status-inactive { background: #742a2a; color: #feb2b2; }

        .employment-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-full-time { background: #d1ecf1; color: #0c5460; }
        .badge-part-time { background: #fff3cd; color: #856404; }
        .badge-contract { background: #d4edda; color: #155724; }
        .badge-probation { background: #f8d7da; color: #721c24; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }

        /* Main Layout */
        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            margin-top: 60px;
            width: 100%;
        }

        body.dark-mode .main-content {
            background-color: var(--dark-bg);
        }

        .content-area {
            width: 100%;
            background: transparent; /* Changed from white to transparent since we use cards */
            border-radius: var(--border-radius);
        }

        .page-header {
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: var(--border-radius);
            background: white;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        body.dark-mode .page-header {
            background: var(--dark-card);
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
            margin-bottom: 0;
        }

        body.dark-mode .page-subtitle {
            color: #a0aec0;
        }
        
        /* Modal Adjustments for Dark Mode */
        body.dark-mode .modal-content {
            background-color: var(--dark-card);
            color: var(--text-light);
        }
        body.dark-mode .modal-header, body.dark-mode .modal-footer {
            border-color: #4a5568;
        }
        body.dark-mode .form-control, body.dark-mode .form-select {
            background-color: #2d3748;
            border-color: #4a5568;
            color: white;
        }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">
    
    <div class="main-content">
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-users"></i>
                        Employee Master Record
                    </h1>
                    <p class="page-subtitle">Manage employee information and contracts</p>
                </div>
                <div>
                     <button class="btn btn-primary" onclick="openModal()">
                        <i class="fas fa-plus"></i> Add Employee
                    </button>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo ($message_type == 'success') ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert" style="margin-bottom: 1.5rem;">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Search/Actions (Simulating Filters) -->
            <div class="filters-container">
                <div class="filters-header">
                    <h3 class="filters-title">
                        <i class="fas fa-search"></i> Search Employees
                    </h3>
                </div>
                <form method="GET" action="" class="filters-form d-flex gap-2">
                    <input type="text" class="form-control" name="search" placeholder="Search by Name, ID or Department..." value="<?php echo htmlspecialchars($search_term); ?>" style="max-width: 400px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="?" class="btn btn-secondary" style="background: #6c757d; color: white; text-decoration: none;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </form>
            </div>

            <!-- Employee List Table -->
            <div class="report-card">
                <div class="report-card-header">
                    <h3 class="report-card-title">
                        All Employees
                        <small class="text-muted">(<?php echo $employees->num_rows; ?> records)</small>
                    </h3>
                    <div class="report-card-actions">
                        <!-- Action buttons if needed -->
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Full Name</th>
                                <th>Date Hired</th>
                                <th>Status</th>
                                <th>Contract Type</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Salary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees->num_rows > 0): ?>
                                <?php while($row = $employees->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($row['employee_id'] ?? '-'); ?></strong></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                                $displayDate = !empty($row['date_hired']) ? date('M d, Y', strtotime($row['date_hired'])) : 'Jan 01, 2024'; 
                                                echo $displayDate;
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = $row['status'] ?? 'Active';
                                            $statusClass = 'status-inactive';
                                            if ($status === 'Active') $statusClass = 'status-active';
                                            elseif ($status === 'Resigned') $statusClass = 'status-resigned';
                                            elseif ($status === 'Terminated') $statusClass = 'status-terminated';
                                            
                                            echo "<span class='status-badge $statusClass'>$status</span>";
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $contract = $row['contract'] ?? 'Regular';
                                            $badge_class = 'badge-secondary';
                                            if($contract == 'Regular') $badge_class = 'badge-full-time';
                                            elseif($contract == 'Probationary') $badge_class = 'badge-probation';
                                            elseif($contract == 'Contract') $badge_class = 'badge-contract';
                                            elseif($contract == 'Project-Based') $badge_class = 'badge-part-time';
                                            
                                            echo "<span class='employment-badge $badge_class'>$contract</span>";
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['department']); ?></td>
                                        <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                                        <td style="font-family: 'Courier New', monospace; font-weight: 600;">₱<?php echo number_format((float)($row['salary'] ?? 0), 2); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info text-white me-1" onclick='viewEmployee(<?php echo json_encode($row); ?>)' title="View" style="display: inline-flex; width: auto;">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary" onclick='editEmployee(<?php echo json_encode($row); ?>)' title="Edit" style="display: inline-flex; width: auto;">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" style="text-align:center; padding: 2rem; color: #858796;">No records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal (Bootstrap - Preserved Logic) -->
    <div class="modal fade" id="masterModal" tabindex="-1" aria-labelledby="masterModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="masterModalLabel">Add Employee Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_employee">
                        <input type="hidden" name="db_id" id="db_id">
                        
                        <!-- Identification -->
                        <h6 class="text-primary mb-3"><i class="fas fa-id-card me-2"></i>Identification</h6>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="employee_id" class="form-label">Employee ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="employee_id" id="employee_id" required placeholder="EMP-001">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label for="name" class="form-label">Full Name (Profile Ref) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="name" required placeholder="Last Name, First Name">
                            </div>
                        </div>

                        <!-- Organization -->
                        <h6 class="text-primary mb-3 mt-2"><i class="fas fa-sitemap me-2"></i>Organization & Position</h6>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="department" class="form-label">Department ID</label>
                                <input type="text" class="form-control" name="department" id="department" placeholder="e.g. IT">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="job_title" class="form-label">Position ID</label>
                                <input type="text" class="form-control" name="job_title" id="job_title" placeholder="e.g. Developer">
                            </div>
                             <div class="col-md-4 mb-3">
                                <label for="manager" class="form-label">Reporting Manager ID</label>
                                <input type="text" class="form-control" name="manager" id="manager" placeholder="Manager ID/Name">
                            </div>
                        </div>

                        <!-- Employment Details -->
                        <h6 class="text-primary mb-3 mt-2"><i class="fas fa-briefcase me-2"></i>Employment Details</h6>
                        <div class="row">
                             <div class="col-md-3 mb-3">
                                <label for="status" class="form-label">Emp. Status</label>
                                <select class="form-select" name="status" id="status">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Resigned">Resigned</option>
                                    <option value="Terminated">Terminated</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label for="contract" class="form-label">Contract Type</label>
                                <select class="form-select" name="contract" id="contract">
                                    <option value="Regular">Regular</option>
                                    <option value="Probationary">Probationary</option>
                                    <option value="Contract">Contract</option>
                                    <option value="Project-Based">Project-Based</option>
                                    <option value="Intern">Intern</option>
                                </select>
                            </div>
                             <div class="col-md-3 mb-3">
                                <label for="date_hired" class="form-label">Date Hired</label>
                                <input type="date" class="form-control" name="date_hired" id="date_hired">
                            </div>
                             <div class="col-md-3 mb-3">
                                <label for="date_regularized" class="form-label">Date Regularized</label>
                                <input type="date" class="form-control" name="date_regularized" id="date_regularized">
                            </div>
                        </div>
                        
                         <div class="row">
                            <div class="col-md-12 mb-3">
                                <label for="work_schedule" class="form-label">Shift / Schedule ID</label>
                                <input type="text" class="form-control" name="work_schedule" id="work_schedule" placeholder="e.g. SHIFT-001 (9AM-6PM)">
                            </div>
                        </div>

                        <!-- Compensation & System -->
                        <h6 class="text-primary mb-3 mt-2"><i class="fas fa-coins me-2"></i>Compensation & Eligibility</h6>
                        <div class="row">
                             <div class="col-md-6 mb-3">
                                <label for="salary" class="form-label">Basic Salary</label>
                                <input type="number" step="0.01" class="form-control" name="salary" id="salary" placeholder="0.00">
                            </div>
                             <div class="col-md-6 mb-3">
                                <label for="pay_grade" class="form-label">Salary Grade</label>
                                <input type="text" class="form-control" name="pay_grade" id="pay_grade" placeholder="e.g. G-12">
                            </div>
                        </div>
                        
                        <div class="row mt-2">
                            <label class="form-label mb-2">Eligibilities</label>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="payroll_eligible" id="payroll_eligible" value="1" checked>
                                    <label class="form-check-label" for="payroll_eligible">Payroll Eligible</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="attendance_eligible" id="attendance_eligible" value="1" checked>
                                    <label class="form-check-label" for="attendance_eligible">Attendance Eligible</label>
                                </div>
                            </div>
                             <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="benefits_eligible" id="benefits_eligible" value="1" checked>
                                    <label class="form-check-label" for="benefits_eligible">Benefits Eligible</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const modalInstance = new bootstrap.Modal(document.getElementById('masterModal'));
        
        function openModal() {
            clearForm();
            enableEdit();
            document.getElementById('masterModalLabel').innerText = 'Add Employee Master Record';
            modalInstance.show();
        }

        function closeModal() {
            modalInstance.hide();
        }

        function clearForm() {
            document.getElementById('db_id').value = '';
            document.getElementById('employee_id').value = '';
            document.getElementById('name').value = '';

            document.getElementById('date_hired').value = '';
            document.getElementById('date_regularized').value = '';
            
            document.getElementById('status').value = 'Active';
            document.getElementById('contract').value = 'Regular';
            document.getElementById('department').value = '';
            document.getElementById('job_title').value = '';
            document.getElementById('manager').value = '';
            
            document.getElementById('salary').value = '';
            document.getElementById('pay_grade').value = '';
            document.getElementById('work_schedule').value = '';
            
            document.getElementById('payroll_eligible').checked = true;
            document.getElementById('attendance_eligible').checked = true;
            document.getElementById('benefits_eligible').checked = true;
        }

        function enableEdit(isEdit = false) {
            // Re-enable all fields
            const inputs = document.querySelectorAll('#masterModal input, #masterModal select');
            inputs.forEach(input => input.disabled = false);
            
            const saveBtn = document.querySelector('#masterModal .btn-primary');
            if (saveBtn) saveBtn.style.display = 'inline-block';

            if (isEdit) {
                // If editing, disable Employee ID (Key)
                document.getElementById('employee_id').readOnly = true;
                document.getElementById('employee_id').style.backgroundColor = '#eaecf4';
            } else {
                // If adding, enable Employee ID
                document.getElementById('employee_id').readOnly = false;
                document.getElementById('employee_id').style.backgroundColor = '';
            }
        }

        disableView = function() {
            // Disable all fields for view mode
            const inputs = document.querySelectorAll('#masterModal input, #masterModal select');
            inputs.forEach(input => input.disabled = true);
            const saveBtn = document.querySelector('#masterModal .btn-primary');
            if (saveBtn) saveBtn.style.display = 'none';
        }

        function editEmployee(data) {
            clearForm();
            enableEdit(true); // Set to edit mode
            populateForm(data);
            document.getElementById('masterModalLabel').innerText = 'Edit Employee Master Record';
            modalInstance.show();
        }

        function viewEmployee(data) {
            clearForm();
            populateForm(data);
            document.getElementById('masterModalLabel').innerText = 'View Employee Master Record';
            disableView(); // Set to view-only mode
            modalInstance.show();
        }
        
        function populateForm(data) {
            document.getElementById('db_id').value = data.id;
            document.getElementById('employee_id').value = data.employee_id || '';
            document.getElementById('name').value = data.name;
            document.getElementById('date_hired').value = data.date_hired || '';
            document.getElementById('date_regularized').value = data.date_regularized || '';
            
            document.getElementById('status').value = data.status || 'Active';
            document.getElementById('contract').value = data.contract || 'Regular';
            document.getElementById('department').value = data.department;
            document.getElementById('job_title').value = data.job_title;
            document.getElementById('manager').value = data.manager || '';
            
            document.getElementById('salary').value = data.salary || '';
            document.getElementById('pay_grade').value = data.pay_grade || '';
            document.getElementById('work_schedule').value = data.work_schedule || '';
            
            // Handle Checkboxes (PHP sends '1' or '0' as string or int)
            document.getElementById('payroll_eligible').checked = (data.payroll_eligible == 1);
            document.getElementById('attendance_eligible').checked = (data.attendance_eligible == 1);
            document.getElementById('benefits_eligible').checked = (data.benefits_eligible == 1);
        }
    </script>
</body>
</html>
<?php ob_end_flush(); ?>