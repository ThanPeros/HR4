<?php
// core-human/contract-employment.php

include '../includes/sidebar.php';
include '../config/db.php';

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}
$currentTheme = $_SESSION['theme'];

// --- DB MIGRATION LOGIC --- //
// --- DB MIGRATION LOGIC --- //
try {
    // 1. Check if the "contracts" table exists (from previous system)
    $checkOld = $conn->query("SHOW TABLES LIKE 'contracts'");
    
    if ($checkOld->num_rows > 0) {
        // Table 'contracts' exists. Let's ensure it has the 'remarks' column.
        $checkCol = $conn->query("SHOW COLUMNS FROM contracts LIKE 'remarks'");
        if ($checkCol->num_rows == 0) {
            $conn->query("ALTER TABLE contracts ADD COLUMN remarks TEXT NULL");
        }
        // Also ensure it has contract_type if not (though it should)
        $checkCol = $conn->query("SHOW COLUMNS FROM contracts LIKE 'contract_type'");
        if ($checkCol->num_rows == 0) {
             $conn->query("ALTER TABLE contracts ADD COLUMN contract_type VARCHAR(50) NOT NULL");
        }
        
    } else {
        // 'contracts' table does NOT exist. Create it.
        $sql = "CREATE TABLE contracts (
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
             // Fallback: If creation fails, maybe generic error
             error_log("Create Table contracts Failed: " . $conn->error);
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
        $id = $_POST['contract_id'] ?? '';
        $employee_id = $conn->real_escape_string($_POST['employee_id']);
        $contract_type = $conn->real_escape_string($_POST['contract_type']);
        $start_date = $conn->real_escape_string($_POST['start_date']);
        $end_date = !empty($_POST['end_date']) ? "'" . $conn->real_escape_string($_POST['end_date']) . "'" : "NULL";
        $status = $conn->real_escape_string($_POST['status']);
        $remarks = $conn->real_escape_string($_POST['remarks']);

        if (!empty($id)) {
            // Update
            $sql = "UPDATE contracts SET 
                    contract_type = '$contract_type',
                    start_date = '$start_date',
                    end_date = $end_date,
                    status = '$status',
                    remarks = '$remarks'
                    WHERE id = '$id'";
            if ($conn->query($sql)) {
                $message = "Contract updated successfully.";
                $message_type = "success";
            } else {
                $message = "Error updating: " . $conn->error;
                $message_type = "error";
            }
        } else {
            // Insert
            $sql = "INSERT INTO contracts (employee_id, contract_type, start_date, end_date, status, remarks)
                    VALUES ('$employee_id', '$contract_type', '$start_date', $end_date, '$status', '$remarks')";
            if ($conn->query($sql)) {
                $message = "Contract added successfully.";
                $message_type = "success";
                
                // Sync with Employee Master Record
                // "Contract defines employee–company relationship... HR governs"
                
                // 1. Update Employment Type (e.g. Regular, Probationary) - NOW MAPPED TO 'contract' column
                $syncSql = "UPDATE employees SET contract = '$contract_type' WHERE id = '$employee_id'";
                $conn->query($syncSql);

                // 2. Update Status (Active/Inactive) based on Contract Status
                $newStatus = 'Active';
                if ($status === 'Terminated' || $status === 'Expired' || $status === 'Resigned') {
                    $newStatus = 'Inactive';
                }
                $syncSql2 = "UPDATE employees SET status = '$newStatus' WHERE id = '$employee_id'";
                $conn->query($syncSql2);

            } else {
                $message = "Error adding: " . $conn->error;
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

// Fetch Contracts - Modified to include ALL employees (LEFT JOIN)
// "Access the employees table to reflect all their current contract type"
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
        LEFT JOIN contracts c ON e.id = c.employee_id 
        ORDER BY e.name ASC, c.status ASC";
$contracts = $conn->query($sql);

// Fetch Contract Types from DB
$empTypes = $conn->query("SELECT type_name FROM contract_types ORDER BY type_name ASC");
$typesList = [];
if ($empTypes) {
    while($t = $empTypes->fetch_assoc()) {
        $typesList[] = $t['type_name'];
    }
}
// Fallback if empty (though should be seeded by employment_info.php)
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --dark-bg: #2c3e50;
            --dark-card: #34495e;
            --text-light: #f8f9fa;
            --text-dark: #212529;
            --border-radius: 0.35rem;
            --shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
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
        }

        .main-wrapper {
            transition: margin-left 0.3s ease;
            width: 100%;
            margin-top: 60px;
        }

        @media (min-width: 769px) {
            body.sidebar-open .main-wrapper {
                margin-left: var(--sidebar-width);
                width: calc(100% - var(--sidebar-width));
            }
        }

        .action-header {
            padding: 1.5rem 2rem;
            background: white;
            border-bottom: 1px solid #e3e6f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        
        body.dark-mode .action-header { background: var(--dark-card); border-bottom: 1px solid #4a5568; }
        .action-title { font-size: 1.5rem; font-weight: 700; color: inherit; }

        .btn-add {
            background: var(--primary-color);
            color: white; padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none; font-weight: 600;
            display: inline-flex; align-items: center; gap: 8px;
            border: none; cursor: pointer;
        }
        .btn-add:hover { background: #2e59d9; }

        .content-container { padding: 2rem; }
        .card {
            background: white; border-radius: var(--border-radius); box-shadow: var(--shadow);
            overflow: hidden; margin-bottom: 2rem;
        }
        body.dark-mode .card { background: var(--dark-card); }
        .card-header {
            padding: 1rem 1.5rem; border-bottom: 1px solid #e3e6f0;
            background: rgba(0,0,0,0.02); font-weight: 700; color: var(--primary-color);
        }
        body.dark-mode .card-header { border-bottom-color: #4a5568; background: rgba(255,255,255,0.05); }

        .table-responsive { overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1rem 1.5rem; text-align: left; border-bottom: 1px solid #e3e6f0; }
        body.dark-mode th, body.dark-mode td { border-bottom-color: #4a5568; color: var(--text-light); }
        th { background: #f8f9fc; font-weight: 600; text-transform: uppercase; font-size: 0.8rem; color: #858796; }
        body.dark-mode th { background: #2c3e50; color: #a0aec0; }
        tr:hover { background: #f8f9fc; }
        body.dark-mode tr:hover { background: rgba(255,255,255,0.05); }

        .badge { padding: 0.3em 0.6em; border-radius: 1rem; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .badge-active { background: #d4edda; color: #155724; }
        .badge-expired { background: #f8d7da; color: #721c24; }
        .badge-renewed { background: #cce5ff; color: #004085; }
        .badge-terminated { background: #343a40; color: #fff; }

        .action-icon {
            background: none; border: none; cursor: pointer;
            color: var(--primary-color); font-size: 1.1rem; padding: 5px;
        }
        .action-icon:hover { opacity: 0.8; }

        .alert {
            padding: 1rem; margin: 0 2rem 1rem 2rem;
            border-radius: var(--border-radius); display: flex; justify-content: space-between;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Modal */
        .modal {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 1100; justify-content: center; align-items: center;
        }
        .modal-content {
            background: white; padding: 2rem; border-radius: var(--border-radius);
            width: 90%; max-width: 550px; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        body.dark-mode .modal-content { background: var(--dark-card); color: white; }
        .modal-header { display: flex; justify-content: space-between; margin-bottom: 1.5rem; }
        .modal-title { font-size: 1.25rem; font-weight: 700; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #aaa; }
        
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; }
        .form-control { width: 100%; padding: 0.7rem; border: 1px solid #d1d3e2; border-radius: 0.35rem; font-size: 1rem; }
        body.dark-mode .form-control { background: #2d3748; border-color: #4a5568; color: white; }
        
        .btn-save {
            background: var(--primary-color); color: white; padding: 0.7rem; border-radius: 0.35rem;
            border: none; cursor: pointer; width: 100%; font-weight: 600; margin-top: 1rem;
        }
        .btn-save:hover { background: #224abe; }
    </style>
</head>
<body class="<?php echo ($currentTheme === 'dark') ? 'dark-mode' : ''; ?> <?php echo (isset($_SESSION['sidebar_state']) && $_SESSION['sidebar_state'] === 'open') ? 'sidebar-open' : ''; ?>">

<div class="main-wrapper">
    <div class="action-header">
        <h2 class="action-title">Contract & Employment Details</h2>
        <button class="btn-add" onclick="openModal()">
            <i class="fas fa-file-signature"></i> Update Contract
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo ($message_type == 'success') ? 'success' : 'error'; ?>">
            <span><?php echo $message; ?></span>
            <span onclick="this.parentElement.style.display='none'" style="cursor:pointer">&times;</span>
        </div>
    <?php endif; ?>

    <div class="content-container">
        <div class="card">
            <div class="card-header">Employment Contracts</div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Contract</th>
                            <th>Dates</th>
                            <th>Status.</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($dbError)): ?>
                             <tr><td colspan="6" style="text-align:center;color:red;">DB Error: <?php echo htmlspecialchars($dbError); ?></td></tr>
                        <?php elseif ($contracts && $contracts->num_rows > 0): ?>
                            <?php while($row = $contracts->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($row['name']); ?></div>
                                        <div style="font-size:0.8rem; opacity:0.7;"><?php echo htmlspecialchars($row['emp_code'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <!-- Contract (Master Status) -->
                                        <span class="badge" style="background:#e3e6f0; color:#5a5c69;">
                                            <?php echo htmlspecialchars($row['master_contract'] ?? 'Regular'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                            // Dummy Dates Logic "add some dummy dates"
                                            // If actual record exists, use it. If not, use dummy.
                                            $start = $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : 'Jan 01, 2024';
                                            $end = $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : 'Dec 31, 2024';
                                            
                                            echo "<div style='font-size:0.9rem;'>Start: $start</div>";
                                            echo "<div style='font-size:0.8rem; color:#888;'>End: $end</div>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            // If no real status, assume Active for dummy display or use N/A? 
                                            // User said "add some dummy dates", likely wants the table to look full.
                                            // But let's show actual status if exists.
                                            if ($row['contract_id']) {
                                                $s = $row['contract_status'];
                                                $c = 'badge-active';
                                                if($s=='Expired') $c='badge-expired';
                                                if($s=='Terminated') $c='badge-terminated';
                                                if($s=='Renewed') $c='badge-renewed';
                                                echo "<span class='badge $c'>$s</span>";
                                            } else {
                                                echo "<span class='badge badge-active'>Active</span>"; // Dummy status
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                            // Prepare data object for edit/add
                                            $jsData = [
                                                'id' => $row['contract_id'] ?? '',
                                                'employee_id' => $row['emp_db_id'],
                                                'contract_type' => $row['master_contract'], // Pre-fill with master
                                                'start_date' => $row['start_date'] ?? '2024-01-01', // Dummy for form? Or empty? let's stick to empty/dummy
                                                'end_date' => $row['end_date'] ?? '2024-12-31',
                                                'status' => $row['contract_status'] ?? 'Active',
                                                'remarks' => $row['remarks'] ?? ''
                                            ];
                                        ?>
                                        <button class="action-icon" onclick='editContract(<?php echo json_encode($jsData); ?>)'>
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" style="text-align:center; padding: 2rem;">No contracts found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="contractModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title" id="modalTitle">Add Contract</h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_contract">
            <input type="hidden" name="contract_id" id="contract_id">
            
            <div class="form-group">
                <label class="form-label">Employee</label>
                <select class="form-control" name="employee_id" id="employee_id" required>
                    <option value="">Select Employee</option>
                    <?php foreach($empList as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>">
                            <?php echo htmlspecialchars($emp['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Contract Type</label>
                <select class="form-control" name="contract_type" id="contract_type" required>
                    <option value="">Select Type</option>
                    <?php foreach($typesList as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display:flex; gap:1rem;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" id="start_date" required>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">End Date <small>(Optional)</small></label>
                    <input type="date" class="form-control" name="end_date" id="end_date">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Status</label>
                <select class="form-control" name="status" id="status">
                    <option value="Active">Active</option>
                    <option value="Renewed">Renewed</option>
                    <option value="Expired">Expired</option>
                    <option value="Terminated">Terminated</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Remarks / Notes</label>
                <textarea class="form-control" name="remarks" id="remarks" rows="3"></textarea>
            </div>

            <button type="submit" class="btn-save">Save Contract</button>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('contractModal');
    
    function openModal() {
        document.getElementById('modalTitle').innerText = 'Add Contract';
        document.getElementById('contract_id').value = '';
        document.getElementById('employee_id').value = ''; // Reset select
        document.getElementById('employee_id').value = ''; // Reset select
        document.getElementById('employee_id').disabled = false; // Enable select
        document.getElementById('contract_type').value = '';
        document.getElementById('start_date').value = '';
        document.getElementById('end_date').value = '';
        document.getElementById('status').value = 'Active';
        document.getElementById('remarks').value = '';
        modal.style.display = 'flex';
    }

    function editContract(data) {
        document.getElementById('modalTitle').innerText = 'Update Contract';
        document.getElementById('contract_id').value = data.id;
        document.getElementById('employee_id').value = data.employee_id;
        document.getElementById('employee_id').disabled = true; // Lock employee on edit
        document.getElementById('contract_type').value = data.contract_type;
        document.getElementById('start_date').value = data.start_date;
        document.getElementById('end_date').value = data.end_date || '';
        document.getElementById('status').value = data.status || 'Active';
        document.getElementById('remarks').value = data.remarks || '';
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target == modal) closeModal();
    }
</script>

</body>
</html>
