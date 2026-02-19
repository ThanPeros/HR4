<?php
// core-human/contract-employment.php

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

// --- AJAX HANDLER (Moved to Top to prevent HTML pollution) --- //
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json');
    // Error handling for missing DB connection
    if (!isset($conn)) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed']);
        exit;
    }

    $empId = intval($_GET['employee_id']);
    $histSql = "SELECT * FROM contract_history WHERE employee_id = $empId ORDER BY start_date DESC";
    
    // Check if table exists (handled by migration below, but for AJAX we need it NOW)
    // Actually migration might not have run if we are just calling AJAX.
    // Safe to run simple query.
    
    $res = $conn->query($histSql);
    
    if($res) {
        $history = [];
        while($r = $res->fetch_assoc()) {
            $history[] = $r;
        }
        echo json_encode(['success' => true, 'history' => $history]);
    } else {
        // Table might not exist yet if page never loaded? 
        // Or generic SQL error
        echo json_encode(['success' => false, 'error' => $conn->error]);
    }
    exit;
}

include '../includes/sidebar.php';

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}
$currentTheme = $_SESSION['theme'];

// Handle custom theme toggle if needed
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

// --- DB MIGRATION LOGIC (UPDATED For History) --- //
try {
    // Check if "contract_history" table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'contract_history'");
    
    if ($checkTable->num_rows == 0) {
        // Create 'contract_history' table
        $sql = "CREATE TABLE contract_history (
            id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT(6) UNSIGNED NOT NULL,
            contract_type VARCHAR(50) NOT NULL COMMENT 'Probationary, Regular, Fixed-Term, Project-Based',
            start_date DATE NOT NULL,
            end_date DATE NULL,
            status VARCHAR(20) DEFAULT 'Active' COMMENT 'Active, Expired, Terminated, Renewed',
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if (!$conn->query($sql)) {
             error_log("Create Table contract_history Failed: " . $conn->error);
        }
    } else {
        // Maintenance: Ensure columns exist if table exists
        $checkCol = $conn->query("SHOW COLUMNS FROM contract_history LIKE 'remarks'");
         if ($checkCol->num_rows == 0) {
            $conn->query("ALTER TABLE contract_history ADD COLUMN remarks TEXT NULL");
        }
        $checkCol2 = $conn->query("SHOW COLUMNS FROM contract_history LIKE 'end_date'");
        // Ensure end_date allows NULL in existing table
        $conn->query("ALTER TABLE contract_history MODIFY COLUMN end_date DATE NULL");

        $checkCol3 = $conn->query("SHOW COLUMNS FROM contract_history LIKE 'contract_file'");
        if ($checkCol3->num_rows == 0) {
            $conn->query("ALTER TABLE contract_history ADD COLUMN contract_file VARCHAR(255) NULL COMMENT 'Path to uploaded contract file'");
        }
    }
} catch (Exception $e) {
    $dbError = $e->getMessage();
}



// --- HANDLE SUBMISSIONS --- //
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save_contract') {
        // We are adding a NEW contract record which updates the current status
        $employee_id = $conn->real_escape_string($_POST['employee_id']);
        $contract_type = $conn->real_escape_string($_POST['contract_type']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = !empty($_POST['end_date']) ? "'" . $conn->real_escape_string($_POST['end_date']) . "'" : "NULL";
        $status = $conn->real_escape_string($_POST['status']);
        $remarks = $conn->real_escape_string($_POST['remarks']);

        if (empty($employee_id)) {
            $message = "Error: No employee selected.";
            $message_type = "error";
        } else {
            try {
                // --- FILE UPLOAD HANDLING ---
                $file_path_sql = "";
                $file_column_insert = "";
                $file_value_insert = "";
                
                if (isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] == 0) {
                    $upload_dir = '../uploads/contracts/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                    
                    $file_ext = strtolower(pathinfo($_FILES['contract_file']['name'], PATHINFO_EXTENSION));
                    $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                    
                    if (in_array($file_ext, $allowed)) {
                        $new_filename = "contract_" . $employee_id . "_" . time() . "." . $file_ext;
                        if (move_uploaded_file($_FILES['contract_file']['tmp_name'], $upload_dir . $new_filename)) {
                            // Save relative path for database
                            $db_file_path = $conn->real_escape_string("uploads/contracts/" . $new_filename);
                            
                            $file_path_sql = ", contract_file = '$db_file_path'";
                            $file_column_insert = ", contract_file";
                            $file_value_insert = ", '$db_file_path'";
                        } else {
                            throw new Exception("Failed to move uploaded file.");
                        }
                    } else {
                        throw new Exception("Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG");
                    }
                }

                if (!empty($history_id)) {
                    // --- UPDATE EXISTING RECORD ---
                    $sql = "UPDATE contract_history SET 
                            contract_type = '$contract_type',
                            start_date = '$start_date',
                            end_date = $end_date,
                            status = '$status',
                            remarks = '$remarks'
                            $file_path_sql
                            WHERE id = '$history_id'";
                     $action_msg = "Contract record updated";
                } else {
                    // --- INSERT NEW RECORD ---
                    $sql = "INSERT INTO contract_history (employee_id, contract_type, start_date, end_date, status, remarks $file_column_insert)
                            VALUES ('$employee_id', '$contract_type', '$start_date', $end_date, '$status', '$remarks' $file_value_insert)";
                    $action_msg = "New contract added";
                }
                
                if ($conn->query($sql)) {
                    $message = "$action_msg successfully.";
                    $message_type = "success";
                    
                    // Sync with Master: Update employees table to reflect the LATEST contract status
                    // We only sync if this is likely the "Current" status (e.g. Active).
                    // Or we just strictly sync whatever was last touched? 
                    // Better: Update master if this record's start_date is >= current master's start date? 
                    // For simplicity, we sync the contract type and status to the Master ID.
                    
                    $masterStatus = ($status === 'Terminated' || $status === 'Expired' || $status === 'Resigned') ? 'Inactive' : 'Active';
                    
                    $syncSql = "UPDATE employees SET contract = '$contract_type', status = '$masterStatus', date_regularized = " . ($contract_type === 'Regular' ? "'$start_date'" : "date_regularized") . " WHERE id = '$employee_id'";
                    $conn->query($syncSql);

                } else {
                   throw new Exception($conn->error);
                }
            } catch (Exception $e) {
                $message = "Database Error: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
}


// Fetch Employees for Dropdown
$employees = $conn->query("SELECT id, name, employee_id FROM employees ORDER BY name ASC");
$empList = [];
if ($employees->num_rows > 0) {
    while($row = $employees->fetch_assoc()) $empList[] = $row;
}

// Fetch Contracts (Filtered by Search if present)
$search_term = '';
$where_clause = '';
if (isset($_GET['search'])) {
    $search_term = $conn->real_escape_string($_GET['search']);
    $where_clause = "WHERE e.name LIKE '%$search_term%' OR e.employee_id LIKE '%$search_term%' OR c.contract_type LIKE '%$search_term%'";
}

$sql = "SELECT 
            e.id as emp_db_id,
            e.name,
            e.employee_id as emp_code,
            e.contract as master_contract,
            c.id as contract_id,
            c.contract_type as contract_record_type,
            c.start_date,
            c.end_date,
            c.status as contract_status,
            c.remarks
        FROM employees e 
        LEFT JOIN contract_history c ON c.id = (
            SELECT id FROM contract_history 
            WHERE employee_id = e.id 
            ORDER BY start_date DESC, id DESC 
            LIMIT 1
        )
        $where_clause
        ORDER BY e.name ASC";
$contracts = $conn->query($sql);

// Fetch Contract Types from DB
$empTypes = $conn->query("SELECT type_name FROM contract_types ORDER BY type_name ASC");
$typesList = [];
if ($empTypes) {
    while($t = $empTypes->fetch_assoc()) {
        $typesList[] = $t['type_name'];
    }
}
if (empty($typesList)) {
    $typesList = ['Probationary', 'Regular', 'Fixed-Term', 'Project-Based'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract Management | HR System</title>
    <!-- Use Bootstrap for consistency with Employment Info UI -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Copied Styles from report.php / employment_info.php -->
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
            padding: 0.375rem 0.75rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.875rem;
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
        .status-expired { background: #f8d7da; color: #721c24; }
        .status-renewed { background: #cce5ff; color: #004085; }
        .status-terminated { background: #5a5c69; color: #fff; }

        body.dark-mode .status-active { background: #22543d; color: #9ae6b4; }
        body.dark-mode .status-expired { background: #742a2a; color: #feb2b2; }

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
            background: transparent;
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
        body.dark-mode .form-control:focus {
            background-color: #2d3748;
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
                        <i class="fas fa-file-contract"></i>
                        Contract Management
                    </h1>
                    <p class="page-subtitle">Track and manage employee contracts and renewals</p>
                </div>
                <div>
                    <!-- Action via Table -->
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo ($message_type == 'success') ? 'success' : 'danger'; ?> alert-dismissible fade show" role="alert" style="margin-bottom: 1.5rem;">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Search (Simulated) -->
            <div class="filters-container">
                <div class="filters-header">
                    <h3 class="filters-title">
                        <i class="fas fa-search"></i> Search Contracts
                    </h3>
                </div>
                <form method="GET" action="" class="filters-form d-flex gap-2">
                    <input type="text" class="form-control" name="search" placeholder="Search by Name, Code or Type..." value="<?php echo htmlspecialchars($search_term); ?>" style="max-width: 400px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="?" class="btn btn-secondary" style="background: #6c757d; color: white; text-decoration: none;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </form>
            </div>

            <!-- Contract List (Left Join Employees) -->
            <div class="report-card">
                <div class="report-card-header">
                    <h3 class="report-card-title">
                        Employee Contracts Status
                        <small class="text-muted">(Latest Records)</small>
                    </h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Master Contract</th>
                                <th>Active Contract Type</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($contracts && $contracts->num_rows > 0): ?>
                                <?php while($row = $contracts->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['emp_code'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            // Master record
                                            $master = $row['master_contract'] ?? 'Regular';
                                            $badge_class = 'badge-secondary';
                                            if($master == 'Regular') $badge_class = 'badge-full-time';
                                            elseif($master == 'Probationary') $badge_class = 'badge-probation';
                                            elseif($master == 'Contract') $badge_class = 'badge-contract';
                                            elseif($master == 'Project-Based') $badge_class = 'badge-part-time';
                                            
                                            echo "<span class='employment-badge $badge_class'>$master</span>";
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($row['contract_record_type'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $start = $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : '<span class="text-muted">-</span>';
                                                echo $start;
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $end = $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : '<span class="text-muted text-success">Indefinite</span>';
                                                echo $end;
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                                if ($row['contract_id']) {
                                                    $s = $row['contract_status'];
                                                    $c = 'status-active';
                                                    if($s == 'Expired') $c = 'status-expired';
                                                    if($s == 'Terminated') $c = 'status-terminated';
                                                    if($s == 'Renewed') $c = 'status-renewed';
                                                    echo "<span class='status-badge $c'>$s</span>";
                                                } else {
                                                    echo "<span class='status-badge status-active'>Active</span>";
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                                $jsData = [
                                                    'id' => $row['contract_id'] ?? '',
                                                    'employee_id' => $row['emp_db_id'],
                                                    'contract_type' => $row['master_contract'], 
                                                    'start_date' => $row['start_date'] ?? date('Y-01-01'), 
                                                    'end_date' => $row['end_date'] ?? '',
                                                    'status' => $row['contract_status'] ?? 'Active',
                                                    'remarks' => $row['remarks'] ?? ''
                                                ];
                                            ?>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-sm btn-info text-white" onclick='editContract(<?php echo json_encode($jsData); ?>, "view")' title="View History">
                                                    <i class="fas fa-history"></i> View
                                                </button>
                                                <button type="button" class="btn btn-sm btn-primary" onclick='editContract(<?php echo json_encode($jsData); ?>, "update")' title="Update Contract">
                                                    <i class="fas fa-edit"></i> Update
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding: 2rem; color: #858796;">No contracts found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap Modal -->
    <div class="modal fade" id="contractModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-history me-2"></i>Contract History & Actions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Section 1: History Table -->
                    <div class="mb-4">
                        <h6 class="fw-bold text-secondary mb-2">Contract History for: <span id="historyEmployeeName" class="text-primary"></span></h6>
                        <div class="table-responsive border rounded" style="max-height: 200px; overflow-y: auto;">
                            <table class="table table-sm table-striped mb-0" style="font-size: 0.9em;">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th>Status</th>
                                        <th>Type</th>
                                        <th>Date Range</th>
                                        <th>File</th>
                                        <th>Remarks</th>
                                        <th style="width: 50px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="historyTableBody">
                                    <tr><td colspan="5" class="text-center text-muted">Loading history...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <hr>

                    <!-- Section 2: New Update Form -->
                    <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-pen me-2"></i>Update Contract Status</h6>
                    <form method="POST" action="" id="updateContractForm" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="save_contract">
                        <input type="hidden" name="history_id" id="history_id_input">
                        
                        <!-- We need to pass employee_id.  -->
                        <input type="hidden" name="employee_id" id="hidden_employee_id_input">

                        <!-- For 'Add New' (no pre-selected employee), show this select. For 'Update', hide/disable it and show name above. -->
                        <div class="mb-3" id="employeeSelectWrapper">
                            <label for="employee_id" class="form-label small">Select Employee</label>
                            <select class="form-select" name="employee_id_select" id="employee_id_select" onchange="document.getElementById('hidden_employee_id_input').value=this.value">
                                <option value="">Select Employee...</option>
                                <?php foreach($empList as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>" data-name="<?php echo htmlspecialchars($emp['name']); ?>">
                                        <?php echo htmlspecialchars($emp['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row g-2">
                             <div class="col-md-6 mb-3">
                                <label for="contract_type" class="form-label small">New Contract Type</label>
                                <select class="form-select" name="contract_type" id="contract_type" required>
                                    <option value="">Select Type</option>
                                    <?php foreach($typesList as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="status" class="form-label small">New Status</label>
                                <select class="form-select" name="status" id="status">
                                    <option value="Active">Active</option>
                                    <option value="Renewed">Renewed</option>
                                    <option value="Expired">Expired</option>
                                    <option value="Terminated">Terminated</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="start_date" class="form-label small">Start Date</label>
                                <input type="date" class="form-control" name="start_date" id="start_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="end_date" class="form-label small">End Date <small class="text-muted">(Optional)</small></label>
                                <input type="date" class="form-control" name="end_date" id="end_date">
                            </div>
                            <div class="col-12 mb-3">
                                <label for="contract_file" class="form-label small">Contract File (PDF/Docs/Image)</label>
                                <input type="file" class="form-control" name="contract_file" id="contract_file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                <div id="current_file_display" class="small mt-1 text-info"></div>
                            </div>
                            <div class="col-12 mb-3">
                                <label for="remarks" class="form-label small">Remarks</label>
                                <input type="text" class="form-control" name="remarks" id="remarks" placeholder="e.g. Promotion, Contract Renewal, etc.">
                            </div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary" id="formSubmitBtn"><i class="fas fa-save me-2"></i>Save & Update History</button>
                        </div>
                    </form>
                </div>
                <!-- Remove footer as form button is inside body -->
                <div class="modal-footer bg-light py-1 d-none"></div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const modalInstance = new bootstrap.Modal(document.getElementById('contractModal'));
        
        // --- 1. Dynamic End Date Logic ---
        const typeSelect = document.getElementById('contract_type');
        const endDateInput = document.getElementById('end_date');
        
        typeSelect.addEventListener('change', function() {
            toggleEndDate(this.value);
        });

        function toggleEndDate(type) {
            if (type === 'Regular') {
                endDateInput.value = '';
                endDateInput.disabled = true;
                endDateInput.placeholder = "Not applicable";
            } else {
                endDateInput.disabled = false;
                endDateInput.placeholder = "";
            }
        }

        // --- 2. Modal Functions ---
        function openModal() {
            // "Add New" Mode logic is mostly replaced by View History flow now
            // But if we ever need it:
            document.getElementById('modalTitle').innerText = 'Add / Update Contract';
            document.getElementById('historyEmployeeName').innerText = 'Select Employee below...';
            document.getElementById('historyTableBody').innerHTML = '<tr><td colspan="5" class="text-center text-muted">Please select an employee to view history.</td></tr>';
            
            document.getElementById('employeeSelectWrapper').style.display = 'block';
            resetForm();
            modalInstance.show();
        }

        function editContract(data, mode = 'view') {
            // VIEW HISTORY MODE (Triggered from Main Table)
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-history me-2"></i>Contract History & Actions';
            
            // UI Setup
            document.getElementById('employeeSelectWrapper').style.display = 'none';
            document.getElementById('historyEmployeeName').innerText = 'Loading...';
            
            // Set Hidden Employee ID for the "Add New Update" form
            document.getElementById('hidden_employee_id_input').value = data.employee_id;
            
            // Reset the "Update/Add" form to default state (New Entry)
            resetForm();
            
            // Pre-fill Contract Type for convenience
            if(data.contract_type) {
                 typeSelect.value = data.contract_type;
                 toggleEndDate(data.contract_type);
            }

            modalInstance.show();
            
            // Load History
            loadHistory(data.employee_id);
            
            // Handle Mode Actions
            if (mode === 'update') {
                // Focus on Form
                setTimeout(() => {
                    document.getElementById('updateContractForm').scrollIntoView({ behavior: 'smooth' });
                    document.getElementById('contract_type').focus();
                }, 500); // Small delay for modal animation
            } else {
                 // View Mode - Focus on history or just top
                 // Default behavior is fine
            }
        }
        
        function resetForm() {
            document.getElementById('updateContractForm').reset();
            document.getElementById('history_id_input').value = ''; // Clear history ID (Switch to INSERT mode)
            document.getElementById('formSubmitBtn').innerHTML = '<i class="fas fa-plus me-2"></i>Add Contract Update';
            
            // Re-apply disabled state check
            toggleEndDate(typeSelect.value);
        }

        // --- 3. History Actions ---

        function loadHistory(empId) {
             const tbody = document.getElementById('historyTableBody');
             tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted"><div class="spinner-border spinner-border-sm text-primary"></div> Loading...</td></tr>';
             
             fetch(`?action=get_history&employee_id=${empId}`)
                .then(r => r.json())
                .then(data => {
                    if(data.success) {
                        tbody.innerHTML = '';
                        if(data.history.length === 0) {
                             tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No contract history found.</td></tr>';
                        } else {
                            data.history.forEach(h => {
                                let badge = 'bg-secondary';
                                if(h.status === 'Active') badge = 'bg-success';
                                if(h.status === 'Expired') badge = 'bg-danger';
                                if(h.status === 'Renewed') badge = 'bg-info';
                                
                                // Prepare data for edit
                                const hJson = JSON.stringify(h).replace(/"/g, '&quot;');
                                
                                // File Link
                                let fileLink = '<span class="text-muted">-</span>';
                                if (h.contract_file) {
                                    fileLink = `<a href="../${h.contract_file}" target="_blank" class="text-primary text-decoration-none"><i class="fas fa-paperclip"></i> View</a>`;
                                }

                                const row = `
                                    <tr>
                                        <td><span class="badge ${badge}">${h.status}</span></td>
                                        <td>${h.contract_type}</td>
                                        <td class="small">
                                            ${h.start_date} <i class="fas fa-arrow-right mx-1 text-muted"></i> 
                                            ${h.end_date ? h.end_date : '<span class="text-success">Indefinite</span>'}
                                        </td>
                                        <td>${fileLink}</td>
                                        <td class="small text-muted text-truncate" style="max-width: 100px;">${h.remarks || '-'}</td>
                                        <td>
                                            <button type="button" class="btn btn-xs btn-outline-primary" onclick="editHistoryItem(${hJson})" title="Edit this record">
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                `;
                                tbody.innerHTML += row;
                            });
                        }
                    } else {
                        console.error("Server Error:", data.error);
                        tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Error: ${data.error}</td></tr>`;
                    }
                })
                .catch(e => {
                    console.error(e);
                    tbody.innerHTML = `<tr><td colspan="6" class="text-center text-danger">Failed to load history.</td></tr>`;
                });
        }
        
        function editHistoryItem(item) {
            // Populate Form
            document.getElementById('history_id_input').value = item.id; // Set ID -> UPDATE Mode
            document.getElementById('contract_type').value = item.contract_type;
            
            toggleEndDate(item.contract_type);
            
            document.getElementById('start_date').value = item.start_date;
            document.getElementById('end_date').value = item.end_date || '';
            document.getElementById('status').value = item.status;
            document.getElementById('remarks').value = item.remarks || '';
            
            // File Display
            const fileDiv = document.getElementById('current_file_display');
            if (item.contract_file) {
                fileDiv.innerHTML = `Current File: <a href="../${item.contract_file}" target="_blank" class="fw-bold">View Uploaded File</a>`;
            } else {
                fileDiv.innerHTML = '';
            }
            
            // Visual feedback
            document.getElementById('formSubmitBtn').innerHTML = '<i class="fas fa-save me-2"></i>Update History Record';
            
            // Scroll to form
            document.getElementById('updateContractForm').scrollIntoView({ behavior: 'smooth' });
        }
        
        // Listen for Employee Select Change in "Add" mode
        document.getElementById('employee_id_select').addEventListener('change', function() {
            const empId = this.value;
            if(empId) {
                const name = this.options[this.selectedIndex].getAttribute('data-name');
                document.getElementById('historyEmployeeName').innerText = name;
                document.getElementById('hidden_employee_id_input').value = empId;
                loadHistory(empId);
            } else {
                document.getElementById('historyEmployeeName').innerText = '...';
                document.getElementById('historyTableBody').innerHTML = '';
            }
        });
    </script>
</body>
</html>
