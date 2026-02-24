<?php
// core-human/employment_info.php
// ALL PHP logic MUST run before including sidebar.php
// so that session_start(), header() redirects, and DB work
// are done before ANY HTML is sent to the browser.

// ─── 1. SESSION & AUTH (before any output) ─────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth guard (sidebar also does this, but we must not let execution continue
// past a redirect if we handle theme / POST here first)
if (!isset($_SESSION['user']) || !isset($_SESSION['role'])) {
    header('Location: ../index.php');
    exit;
}

// ─── 2. DATABASE CONNECTION ─────────────────────────────────────────────────
require_once '../config/db.php';

// Use mysqli for this page (config/db.php may give PDO; we need mysqli here)
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'dummy_hr4');
    if ($conn->connect_error) {
        die("DB connection failed: " . $conn->connect_error);
    }
}

// ─── 3. DB MIGRATION – add missing columns silently ─────────────────────────
$migrationCols = [
    "ADD COLUMN IF NOT EXISTS date_hired DATE NULL",
    "ADD COLUMN IF NOT EXISTS date_regularized DATE NULL",
    "ADD COLUMN IF NOT EXISTS employee_id VARCHAR(50) NULL AFTER id",
    "ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'Active'",
    "ADD COLUMN IF NOT EXISTS contract VARCHAR(50) DEFAULT 'Regular'",
    "ADD COLUMN IF NOT EXISTS salary DECIMAL(10,2) DEFAULT 0.00",
    "ADD COLUMN IF NOT EXISTS manager VARCHAR(100) NULL",
    "ADD COLUMN IF NOT EXISTS location VARCHAR(100) NULL",
    "ADD COLUMN IF NOT EXISTS payroll_eligible TINYINT(1) DEFAULT 1",
    "ADD COLUMN IF NOT EXISTS attendance_eligible TINYINT(1) DEFAULT 1",
    "ADD COLUMN IF NOT EXISTS benefits_eligible TINYINT(1) DEFAULT 1",
    "ADD COLUMN IF NOT EXISTS pay_grade VARCHAR(50) NULL",
    "ADD COLUMN IF NOT EXISTS work_schedule VARCHAR(100) NULL",
    "ADD COLUMN IF NOT EXISTS system_role VARCHAR(50) DEFAULT 'Employee'",
    "ADD COLUMN IF NOT EXISTS account_status VARCHAR(20) DEFAULT 'Enabled'",
    "ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];
foreach ($migrationCols as $col) {
    @$conn->query("ALTER TABLE employees $col");
}

// Ensure contract_types table & seed data
@$conn->query("CREATE TABLE IF NOT EXISTS contract_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$checkCount = $conn->query("SELECT COUNT(*) as cnt FROM contract_types");
if ($checkCount && $checkCount->fetch_assoc()['cnt'] == 0) {
    @$conn->query("INSERT INTO contract_types (type_name) VALUES
        ('Regular'),('Probationary'),('Contract'),('Project-Based'),('Intern')");
}

// ─── 4. HANDLE POST (save/update) ───────────────────────────────────────────
$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_employee') {
    $id            = $_POST['db_id'] ?? '';
    $employee_id   = $conn->real_escape_string($_POST['employee_id']   ?? '');
    $name          = $conn->real_escape_string($_POST['name']          ?? '');
    $date_hired    = !empty($_POST['date_hired'])       ? "'" . $conn->real_escape_string($_POST['date_hired'])       . "'" : "NULL";
    $date_reg      = !empty($_POST['date_regularized']) ? "'" . $conn->real_escape_string($_POST['date_regularized']) . "'" : "NULL";
    $status        = $conn->real_escape_string($_POST['status']        ?? 'Active');
    $contract      = $conn->real_escape_string($_POST['contract']      ?? 'Regular');
    $department    = $conn->real_escape_string($_POST['department']    ?? '');
    $job_title     = $conn->real_escape_string($_POST['job_title']     ?? '');
    $salary        = !empty($_POST['salary']) ? floatval($_POST['salary']) : 0;
    $manager       = $conn->real_escape_string($_POST['manager']       ?? '');
    $location_val  = $conn->real_escape_string($_POST['location']      ?? '');
    $pay_grade     = $conn->real_escape_string($_POST['pay_grade']     ?? '');
    $work_schedule = $conn->real_escape_string($_POST['work_schedule'] ?? '');
    $system_role   = $conn->real_escape_string($_POST['system_role']   ?? 'Employee');
    $acc_status    = $conn->real_escape_string($_POST['account_status'] ?? 'Enabled');
    $payroll_el    = isset($_POST['payroll_eligible'])    ? 1 : 0;
    $attend_el     = isset($_POST['attendance_eligible']) ? 1 : 0;
    $benefits_el   = isset($_POST['benefits_eligible'])   ? 1 : 0;

    if (!empty($id)) {
        $sql = "UPDATE employees SET
            employee_id='$employee_id', name='$name',
            date_hired=$date_hired, date_regularized=$date_reg,
            status='$status', contract='$contract',
            department='$department', job_title='$job_title', salary=$salary,
            manager='$manager', location='$location_val',
            pay_grade='$pay_grade', work_schedule='$work_schedule',
            system_role='$system_role', account_status='$acc_status',
            payroll_eligible=$payroll_el, attendance_eligible=$attend_el, benefits_eligible=$benefits_el
            WHERE id='$id'";
        if ($conn->query($sql)) {
            $message = "Record updated successfully.";
            $message_type = "success";
        } else {
            $message = "Update error: " . $conn->error;
            $message_type = "error";
        }
    } else {
        $sql = "INSERT INTO employees
            (employee_id, name, date_hired, date_regularized, status, contract,
             department, job_title, salary, manager, location,
             pay_grade, work_schedule, system_role, account_status,
             payroll_eligible, attendance_eligible, benefits_eligible)
            VALUES
            ('$employee_id','$name',$date_hired,$date_reg,'$status','$contract',
             '$department','$job_title',$salary,'$manager','$location_val',
             '$pay_grade','$work_schedule','$system_role','$acc_status',
             $payroll_el,$attend_el,$benefits_el)";
        if ($conn->query($sql)) {
            $message = "Record created successfully.";
            $message_type = "success";
        } else {
            $message = "Insert error: " . $conn->error;
            $message_type = "error";
        }
    }
}

// ─── 5. FETCH DATA ───────────────────────────────────────────────────────────
$search_term  = '';
$where_clause = '';
if (!empty($_GET['search'])) {
    $search_term  = $conn->real_escape_string($_GET['search']);
    $where_clause = "WHERE name LIKE '%$search_term%'
                        OR employee_id LIKE '%$search_term%'
                        OR department  LIKE '%$search_term%'";
}

$employees = $conn->query("SELECT * FROM employees $where_clause ORDER BY name ASC");
$totalRows = $employees ? $employees->num_rows : 0;

$empTypes  = $conn->query("SELECT type_name FROM contract_types ORDER BY type_name ASC");
$typesList = [];
if ($empTypes) {
    while ($t = $empTypes->fetch_assoc()) $typesList[] = $t['type_name'];
}
if (empty($typesList)) {
    $typesList = ['Regular', 'Probationary', 'Contract', 'Project-Based', 'Intern'];
}

// ─── 6. GET THEME (for body class) ──────────────────────────────────────────
$currentTheme = $_SESSION['theme'] ?? 'light';

// ─── 7. INCLUDE SIDEBAR (outputs <!DOCTYPE html>…<body> + sidebar nav) ──────
require_once '../includes/sidebar.php';

// Everything below this line is injected INSIDE the open <body> tag
// produced by sidebar.php.  Do NOT add <html>, <head>, or <body> here.
?>

<!-- Page-specific styles -->
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
        --success-color: #1cc88a;
        --info-color: #36b9cc;
    }

    .main-content {
        padding: 2rem;
        min-height: 100vh;
        margin-top: 60px;
    }

    body.dark-mode .main-content { background-color: var(--dark-bg); }

    /* Page Header */
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
    body.dark-mode .page-header { background: var(--dark-card); }

    .page-title {
        font-size: 1.5rem;
        font-weight: 600;
        margin-bottom: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .page-subtitle { color: #6c757d; font-size: 0.9rem; }
    body.dark-mode .page-subtitle { color: #a0aec0; }

    /* Filters */
    .filters-container {
        padding: 1.5rem;
        background: white;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow);
        margin-bottom: 1.5rem;
    }
    body.dark-mode .filters-container { background: var(--dark-card); }
    .filters-title { font-size: 1.1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }

    /* Report card / table wrapper */
    .report-card {
        background: white;
        border-radius: var(--border-radius);
        padding: 1.5rem;
        box-shadow: var(--shadow);
        border-left: 4px solid var(--primary-color);
        margin-bottom: 1.5rem;
    }
    body.dark-mode .report-card { background: var(--dark-card); }

    .report-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #e3e6f0;
    }
    body.dark-mode .report-card-header { border-bottom: 1px solid #4a5568; }
    .report-card-title { font-size: 1.1rem; font-weight: 600; margin: 0; }

    /* Table */
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
        background: #f8f9fc;
        padding: 0.75rem;
        text-align: left;
        font-weight: 600;
        color: #4e73df;
        border-bottom: 2px solid #e3e6f0;
    }
    body.dark-mode .data-table th { background: #2d3748; color: #63b3ed; border-bottom-color: #4a5568; }
    .data-table td { padding: 0.75rem; border-bottom: 1px solid #e3e6f0; vertical-align: middle; }
    body.dark-mode .data-table td { border-bottom-color: #4a5568; }
    .data-table tbody tr:hover { background: rgba(78,115,223,.05); }

    /* Badges */
    .status-badge       { padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600; }
    .status-active      { background: #d4edda; color: #155724; }
    .status-inactive    { background: #f8d7da; color: #721c24; }
    .status-resigned    { background: #fff3cd; color: #856404; }
    .status-terminated  { background: #f1b0b7; color: #a02828; }

    .employment-badge  { padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
    .badge-full-time   { background: #d1ecf1; color: #0c5460; }
    .badge-part-time   { background: #fff3cd; color: #856404; }
    .badge-contract    { background: #d4edda; color: #155724; }
    .badge-probation   { background: #f8d7da; color: #721c24; }
    .badge-secondary   { background: #e2e3e5; color: #383d41; }

    /* Dark modal overrides */
    body.dark-mode .modal-content         { background-color: var(--dark-card); color: var(--text-light); }
    body.dark-mode .modal-header,
    body.dark-mode .modal-footer          { border-color: #4a5568; }
    body.dark-mode .form-control,
    body.dark-mode .form-select           { background-color: #2d3748; border-color: #4a5568; color: white; }
</style>

<div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title"><i class="fas fa-briefcase"></i> Employment Management</h1>
            <p class="page-subtitle">Manage employee contracts, statuses and employment details</p>
        </div>
        <button class="btn btn-primary" onclick="openModal()">
            <i class="fas fa-plus me-1"></i> Add Employee
        </button>
    </div>

    <!-- Flash Message -->
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show mb-4" role="alert">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Search -->
    <div class="filters-container">
        <h3 class="filters-title mb-3"><i class="fas fa-search"></i> Search Employees</h3>
        <form method="GET" action="" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" class="form-control" name="search"
                   placeholder="Search by Name, ID or Department..."
                   value="<?php echo htmlspecialchars($search_term); ?>"
                   style="max-width:380px;">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Search</button>
            <a href="?" class="btn btn-secondary"><i class="fas fa-times me-1"></i> Clear</a>
        </form>
    </div>

    <!-- Employee Table -->
    <div class="report-card">
        <div class="report-card-header">
            <h3 class="report-card-title">
                All Employees
                <small class="text-muted fs-6">(<?php echo $totalRows; ?> records)</small>
            </h3>
        </div>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Full Name</th>
                        <th>Date Hired</th>
                        <th>Status</th>
                        <th>Contract</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Salary</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($employees && $employees->num_rows > 0): ?>
                        <?php while ($row = $employees->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['employee_id'] ?? '-'); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['name'] ?? ''); ?></td>
                                <td><?php echo !empty($row['date_hired']) ? date('M d, Y', strtotime($row['date_hired'])) : '—'; ?></td>
                                <td>
                                    <?php
                                    $s  = $row['status'] ?? 'Active';
                                    $sc = $s === 'Active'
                                        ? 'status-active'
                                        : ($s === 'Resigned'
                                            ? 'status-resigned'
                                            : ($s === 'Terminated'
                                                ? 'status-terminated'
                                                : 'status-inactive'));
                                    echo "<span class='status-badge $sc'>" . htmlspecialchars($s) . "</span>";
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $c  = $row['contract'] ?? 'Regular';
                                    $cc = ($c === 'Regular')
                                        ? 'badge-full-time'
                                        : ($c === 'Probationary'
                                            ? 'badge-probation'
                                            : ($c === 'Contract'
                                                ? 'badge-contract'
                                                : ($c === 'Project-Based'
                                                    ? 'badge-part-time'
                                                    : 'badge-secondary')));
                                    echo "<span class='employment-badge $cc'>" . htmlspecialchars($c) . "</span>";
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['department'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['job_title'] ?? ''); ?></td>
                                <td style="font-family:monospace; font-weight:600;">
                                    ₱<?php echo number_format((float)($row['salary'] ?? 0), 2); ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-info text-white me-1"
                                            onclick='viewEmployee(<?php echo json_encode($row); ?>)'
                                            title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-sm btn-primary"
                                            onclick='editEmployee(<?php echo json_encode($row); ?>)'
                                            title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                No employee records found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->

<!-- Bootstrap Modal -->
<div class="modal fade" id="masterModal" tabindex="-1" aria-labelledby="masterModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="masterModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Add Employee Record
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_employee">
                    <input type="hidden" name="db_id"  id="db_id">

                    <!-- Identification -->
                    <h6 class="text-primary mb-3"><i class="fas fa-id-card me-2"></i>Identification</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Employee ID <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="employee_id" id="employee_id" required placeholder="EMP-001">
                        </div>
                        <div class="col-md-8 mb-3">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="name" required placeholder="Last, First Middle">
                        </div>
                    </div>

                    <!-- Organization -->
                    <h6 class="text-primary mb-3 mt-2"><i class="fas fa-sitemap me-2"></i>Organization</h6>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" id="department" placeholder="e.g. IT">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Position</label>
                            <input type="text" class="form-control" name="job_title" id="job_title" placeholder="e.g. Developer">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Reporting Manager</label>
                            <input type="text" class="form-control" name="manager" id="manager" placeholder="Manager Name">
                        </div>
                    </div>

                    <!-- Employment Details -->
                    <h6 class="text-primary mb-3 mt-2"><i class="fas fa-briefcase me-2"></i>Employment Details</h6>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status" id="status">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Resigned">Resigned</option>
                                <option value="Terminated">Terminated</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Contract</label>
                            <select class="form-select" name="contract" id="contract">
                                <?php foreach ($typesList as $t): ?>
                                    <option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Date Hired</label>
                            <input type="date" class="form-control" name="date_hired" id="date_hired">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Date Regularized</label>
                            <input type="date" class="form-control" name="date_regularized" id="date_regularized">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Work Schedule / Shift</label>
                            <input type="text" class="form-control" name="work_schedule" id="work_schedule" placeholder="e.g. 9AM-6PM Mon-Fri">
                        </div>
                    </div>

                    <!-- Compensation & Eligibility -->
                    <h6 class="text-primary mb-3 mt-2"><i class="fas fa-coins me-2"></i>Compensation &amp; Eligibility</h6>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Basic Salary</label>
                            <input type="number" step="0.01" class="form-control" name="salary" id="salary" placeholder="0.00">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Salary Grade</label>
                            <input type="text" class="form-control" name="pay_grade" id="pay_grade" placeholder="e.g. SG-12">
                        </div>
                    </div>
                    <div class="row mt-1">
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
                </div><!-- /modal-body -->

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save me-1"></i> Save Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof bootstrap !== 'undefined' && document.getElementById('masterModal')) {
            window.masterModal = new bootstrap.Modal(document.getElementById('masterModal'));
        }
    });

    function openModal() {
        clearForm();
        enableEdit(false);
        document.getElementById('masterModalLabel').innerHTML =
            '<i class="fas fa-user-plus me-2"></i>Add Employee Record';
        if(window.masterModal) window.masterModal.show();
    }

    function editEmployee(data) {
        clearForm();
        populateForm(data);
        enableEdit(true);
        document.getElementById('masterModalLabel').innerHTML =
            '<i class="fas fa-edit me-2"></i>Edit Employee Record';
        if(window.masterModal) window.masterModal.show();
    }

    function viewEmployee(data) {
        clearForm();
        populateForm(data);
        disableAllInputs();
        document.getElementById('masterModalLabel').innerHTML =
            '<i class="fas fa-eye me-2"></i>View Employee Record';
        if(window.masterModal) window.masterModal.show();
    }

    function clearForm() {
        ['db_id','employee_id','name','date_hired','date_regularized',
         'department','job_title','manager','salary','pay_grade','work_schedule']
            .forEach(function(id) { 
                var el = document.getElementById(id); 
                if(el) el.value = ''; 
            });
        
        var st = document.getElementById('status');
        if(st) st.value = 'Active';
        
        var ct = document.getElementById('contract');
        if(ct) ct.value = 'Regular';
        
        ['payroll_eligible','attendance_eligible','benefits_eligible'].forEach(function(id) {
            var el = document.getElementById(id);
            if(el) el.checked = true;
        });
    }

    function populateForm(data) {
        if(document.getElementById('db_id')) document.getElementById('db_id').value = data.id || '';
        if(document.getElementById('employee_id')) document.getElementById('employee_id').value = data.employee_id || '';
        if(document.getElementById('name')) document.getElementById('name').value = data.name || '';
        if(document.getElementById('date_hired')) document.getElementById('date_hired').value = data.date_hired || '';
        if(document.getElementById('date_regularized')) document.getElementById('date_regularized').value = data.date_regularized || '';
        if(document.getElementById('status')) document.getElementById('status').value = data.status || 'Active';
        if(document.getElementById('contract')) document.getElementById('contract').value = data.contract || 'Regular';
        if(document.getElementById('department')) document.getElementById('department').value = data.department || '';
        if(document.getElementById('job_title')) document.getElementById('job_title').value = data.job_title || '';
        if(document.getElementById('manager')) document.getElementById('manager').value = data.manager || '';
        if(document.getElementById('salary')) document.getElementById('salary').value = data.salary || '';
        if(document.getElementById('pay_grade')) document.getElementById('pay_grade').value = data.pay_grade || '';
        if(document.getElementById('work_schedule')) document.getElementById('work_schedule').value = data.work_schedule || '';
        
        if(document.getElementById('payroll_eligible')) document.getElementById('payroll_eligible').checked = (data.payroll_eligible == 1);
        if(document.getElementById('attendance_eligible')) document.getElementById('attendance_eligible').checked = (data.attendance_eligible == 1);
        if(document.getElementById('benefits_eligible')) document.getElementById('benefits_eligible').checked = (data.benefits_eligible == 1);
    }

    function enableEdit(isEdit) {
        document.querySelectorAll('#masterModal input, #masterModal select')
            .forEach(function(el) { el.disabled = false; });
        var sb = document.getElementById('saveBtn');
        if(sb) sb.style.display = '';
        var empIdField = document.getElementById('employee_id');
        if (empIdField) {
            empIdField.readOnly = isEdit;
            empIdField.style.backgroundColor = isEdit ? '#eaecf4' : '';
        }
    }

    function disableAllInputs() {
        document.querySelectorAll('#masterModal input, #masterModal select')
            .forEach(function(el) { el.disabled = true; });
        var sb = document.getElementById('saveBtn');
        if(sb) sb.style.display = 'none';
    }
</script>
