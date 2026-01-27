<?php
// core-human/employment_info.php

include '../includes/sidebar.php';
// include '../responsive/responsive.php'; // Optional: based on previous preference, likely sidebar handles responsive now
include '../config/db.php';

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}
$currentTheme = $_SESSION['theme'];

// --- DB MIGRATION & HELPER LOGIC --- //
try {
    // Ensure date_hired column exists
    $check = $conn->query("SHOW COLUMNS FROM employees LIKE 'date_hired'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN date_hired DATE NULL");
    }
    // Ensure employee_id column exists
    $check = $conn->query("SHOW COLUMNS FROM employees LIKE 'employee_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN employee_id VARCHAR(50) NULL AFTER id");
    }
    // Ensure status column exists (Active/Inactive)
    $check = $conn->query("SHOW COLUMNS FROM employees LIKE 'status'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN status VARCHAR(20) DEFAULT 'Active'");
    }
    // Ensure contract column exists (Renaming old employment_status if it exists)
    $check = $conn->query("SHOW COLUMNS FROM employees LIKE 'employment_status'");
    if ($check->num_rows > 0) {
        $conn->query("ALTER TABLE employees CHANGE COLUMN employment_status contract VARCHAR(50) DEFAULT 'Regular'");
    } else {
        // If old column doesn't exist, check if 'contract' exists
        $checkNew = $conn->query("SHOW COLUMNS FROM employees LIKE 'contract'");
        if ($checkNew->num_rows == 0) {
            $conn->query("ALTER TABLE employees ADD COLUMN contract VARCHAR(50) DEFAULT 'Regular'");
        }
    }
    // Ensure salary column exists
    $check = $conn->query("SHOW COLUMNS FROM employees LIKE 'salary'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN salary DECIMAL(10,2) DEFAULT 0.00");
    }

    // --- NEW: Contract Types Table ---
    // Create reference table for contract types directly in this file as requested
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
        $date_hired = $conn->real_escape_string($_POST['date_hired']);
        $status = $conn->real_escape_string($_POST['status']);
        $contract = $conn->real_escape_string($_POST['contract']);
        $department = $conn->real_escape_string($_POST['department']);
        $job_title = $conn->real_escape_string($_POST['job_title']);
        $salary = $conn->real_escape_string($_POST['salary']);

        if (!empty($id)) {
            // Update
            $sql = "UPDATE employees SET 
                    employee_id = '$employee_id',
                    name = '$name',
                    date_hired = '$date_hired',
                    status = '$status',
                    contract = '$contract',
                    department = '$department',
                    job_title = '$job_title',
                    salary = '$salary'
                    WHERE id = '$id'";
            if ($conn->query($sql)) {
                $message = "Employee updated successfully.";
                $message_type = "success";
            } else {
                $message = "Error updating: " . $conn->error;
                $message_type = "error";
            }
        } else {
            // Insert
            $sql = "INSERT INTO employees (employee_id, name, date_hired, status, contract, department, job_title, salary)
                    VALUES ('$employee_id', '$name', '$date_hired', '$status', '$contract', '$department', '$job_title', '$salary')";
            if ($conn->query($sql)) {
                $message = "Employee added successfully.";
                $message_type = "success";
            } else {
                $message = "Error adding: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}

// Fetch Employees (Filter logic can be added here if needed, keeping it simple for now)
// We select fields matching the "Employee Master Record" requirement
$sql = "SELECT id, employee_id, name, date_hired, status, contract, department, job_title, salary FROM employees ORDER BY name ASC";
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
    <!-- Use FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- ORIGINAL STYLES RESTORED -->
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

        /* Layout Wrapper to handle Sidebar push/content */
        .main-wrapper {
            transition: margin-left 0.3s ease;
            width: 100%;
            margin-top: 60px; /* Base offset for fixed header */
        }

        /* If sidebar is fitted in desktop, we respect it: */
        @media (min-width: 769px) {
            body.sidebar-open .main-wrapper {
                margin-left: 250px; /* Matching sidebar width */
                width: calc(100% - 250px); /* Adjust width to fit remaining space */
            }
        }

        /* Action Header */
        .action-header {
            padding: 1.5rem 2rem;
            background: white;
            border-bottom: 1px solid #e3e6f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            border-bottom: 1px solid #e3e6f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            /* margin-top: 60px; removed, handled by main-wrapper */
        }
        
        body.dark-mode .action-header {
            background: var(--dark-card);
            border-bottom: 1px solid #4a5568;
        }

        .action-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-dark);
        }
        
        body.dark-mode .action-title {
            color: var(--text-light);
        }

        .btn-add {
            background-color: var(--primary-color);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-add:hover {
            background-color: #2e59d9;
        }

        /* Container */
        .content-container {
            padding: 2rem;
        }

        /* Card Styles */
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        body.dark-mode .card {
            background: var(--dark-card);
        }

        .card-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: rgba(0,0,0,0.02);
            font-weight: 700;
            color: var(--primary-color);
        }

        body.dark-mode .card-header {
            border-bottom-color: #4a5568;
            background: rgba(255,255,255,0.05);
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 1rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode th, 
        body.dark-mode td {
            border-bottom-color: #4a5568;
            color: var(--text-light);
        }

        th {
            background-color: #f8f9fc;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            color: #858796;
        }

        body.dark-mode th {
            background-color: #2c3e50;
            color: #a0aec0;
        }

        tr:hover {
            background-color: #f8f9fc;
        }

        body.dark-mode tr:hover {
            background-color: rgba(255,255,255,0.05);
        }

        /* Status Pills */
        .badge {
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 700;
            border-radius: 0.25rem;
            text-transform: uppercase;
        }
        
        .badge-active {
            background-color: rgba(28, 200, 138, 0.1);
            color: #1cc88a;
        }
        
        .badge-inactive {
            background-color: rgba(231, 74, 59, 0.1);
            color: #e74a3b;
        }

        .badge-type {
            background-color: #eaecf4;
            color: #5a5c69;
            border-radius: 1rem;
            padding: 0.35em 0.8em;
        }
        
        body.dark-mode .badge-type {
            background-color: #4a5568;
            color: #e2e8f0;
        }
        
        .action-icon {
            color: var(--primary-color);
            cursor: pointer;
            padding: 5px;
            font-size: 1.1rem;
            border: 1px solid transparent;
            border-radius: 4px;
            background: none;
        }
        
        .action-icon:hover {
            background: rgba(78, 115, 223, 0.1);
            border-color: var(--primary-color);
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            margin: 0 2rem 1rem 2rem;
            border-radius: var(--border-radius);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        /* Modal Styles (Original Look) */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1100;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 650px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        body.dark-mode .modal-content {
            background: var(--dark-card); 
            color: white;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e3e6f0;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        body.dark-mode .modal-header { border-bottom-color: #4a5568; }

        .modal-title { font-size: 1.25rem; font-weight: 700; }

        .close-btn { 
            background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #aaa; 
        }
        .close-btn:hover { color: #555; }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        
        .form-group { margin-bottom: 1rem; }
        .full-width { grid-column: span 2; }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid #d1d3e2;
            border-radius: 0.35rem;
            font-size: 1rem;
            transition: border 0.3s;
        }
        
        body.dark-mode .form-control {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .modal-footer {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e3e6f0;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        body.dark-mode .modal-footer { border-top-color: #4a5568; }
        
        .btn-cancel {
            background: #858796;
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 0.35rem;
            border: none;
            cursor: pointer;
        }
        
        .btn-save {
            background: var(--primary-color);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 0.35rem;
            border: none;
            cursor: pointer;
        }
    </style>
</head>
<body class="<?php echo ($currentTheme === 'dark') ? 'dark-mode' : ''; ?> <?php echo (isset($_SESSION['sidebar_state']) && $_SESSION['sidebar_state'] === 'open') ? 'sidebar-open' : ''; ?>">

    <div class="main-wrapper">
        <!-- Action Header -->
        <div class="action-header">
            <h2 class="action-title">Employee Master Record</h2>
            <button class="btn-add" onclick="openModal()">
                <i class="fas fa-plus"></i> Add Employee
            </button>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo ($message_type == 'success') ? 'success' : 'error'; ?>">
                <span><?php echo $message; ?></span>
                <span onclick="this.parentElement.style.display='none'" style="cursor:pointer">&times;</span>
            </div>
        <?php endif; ?>

        <!-- Main Content -->
        <div class="content-container">
            <!-- Master List Card -->
            <div class="card">
                <div class="card-header">Master List</div>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Full Name</th>
                                <th>Date Hired</th>
                                <th>Status</th>
                                <th>Contract Type</th>
                                <th>Department</th>
                                <th>Position / Job Title</th>
                                <th>Salary</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($employees->num_rows > 0): ?>
                                <?php while($row = $employees->fetch_assoc()): ?>
                                    <tr>
                                        <td style="font-weight: 600; color: var(--primary-color);">
                                            <?php echo htmlspecialchars($row['employee_id'] ?? '-'); ?>
                                        </td>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <div style="width:30px; height:30px; background:var(--primary-color); border-radius:50%; color:white; display:flex; align-items:center; justify-content:center; font-size:0.8rem; font-weight:bold;">
                                                    <?php echo strtoupper(substr($row['name'], 0, 1)); ?>
                                                </div>
                                                <?php echo htmlspecialchars($row['name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                                // Dummy date logic if empty, for display purposes
                                                $displayDate = !empty($row['date_hired']) ? date('M d, Y', strtotime($row['date_hired'])) : 'Jan 01, 2024'; 
                                                echo $displayDate;
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $status = $row['status'] ?? 'Active';
                                            $badgeClass = ($status === 'Active') ? 'badge-active' : 'badge-inactive';
                                            echo "<span class='badge $badgeClass'>$status</span>";
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            // Employment_status in DB mapped to "Type" in UI
                                            $type = $row['employment_status'] ?? 'Regular';
                                            echo "<span class='badge-type'>$type</span>";
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['department']); ?></td>
                                        <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                                        <td><?php echo htmlspecialchars(number_format((float)($row['salary'] ?? 0), 2)); ?></td>
                                        <td>
                                            <button class="action-icon" onclick='viewEmployee(<?php echo json_encode($row); ?>)' title="View Record">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-icon" onclick='editEmployee(<?php echo json_encode($row); ?>)' title="Edit Record">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; padding: 2rem; color: #858796;">No records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="masterModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">Add Employee Record</h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="save_employee">
                <input type="hidden" name="db_id" id="db_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Employee ID <span style="color:#e74a3b">*</span></label>
                        <input type="text" class="form-control" name="employee_id" id="employee_id" required placeholder="EMP-001">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Full Name <span style="color:#e74a3b">*</span></label>
                        <input type="text" class="form-control" name="name" id="name" required placeholder="Last Name, First Name">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Date Hired <span style="color:#e74a3b">*</span></label>
                        <input type="date" class="form-control" name="date_hired" id="date_hired" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status" id="status">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Resigned">Resigned</option>
                            <option value="Terminated">Terminated</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Contract Type</label>
                        <select class="form-control" name="contract" id="contract">
                            <?php foreach($typesList as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control" name="department" id="department" placeholder="e.g. IT">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Position / Job Title</label>
                        <input type="text" class="form-control" name="job_title" id="job_title" placeholder="e.g. Developer">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Basic Salary</label>
                        <input type="number" step="0.01" class="form-control" name="salary" id="salary" placeholder="0.00">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Record</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('masterModal');
        
        function openModal() {
            clearForm();
            enableEdit(); // Ensure form is editable and ID is editable (for new)
            document.getElementById('modalTitle').innerText = 'Add Employee Record';
            modal.style.display = 'flex';
        }

        function closeModal() {
            modal.style.display = 'none';
        }

        // Close if clicked outside
        window.onclick = function(event) {
            if (event.target == modal) {
                closeModal();
            }
        }

        function clearForm() {
            document.getElementById('db_id').value = '';
            document.getElementById('employee_id').value = '';
            document.getElementById('name').value = '';
            document.getElementById('date_hired').value = '';
            document.getElementById('status').value = 'Active';
            document.getElementById('contract').value = 'Regular';
            document.getElementById('department').value = '';
            document.getElementById('job_title').value = '';
            document.getElementById('salary').value = '';
        }

        function enableEdit(isEdit = false) {
            // Re-enable all fields
            const inputs = modal.querySelectorAll('input, select');
            inputs.forEach(input => input.disabled = false);
            
            // Hide save button if it was hidden
            document.querySelector('.btn-save').style.display = 'inline-block';

            if (isEdit) {
                // If editing, disable Employee ID
                document.getElementById('employee_id').readOnly = true;
                document.getElementById('employee_id').style.backgroundColor = '#eaecf4';
            } else {
                // If adding, enable Employee ID
                document.getElementById('employee_id').readOnly = false;
                document.getElementById('employee_id').style.backgroundColor = '';
            }
        }

        function disableView() {
            // Disable all fields for view mode
            const inputs = modal.querySelectorAll('input, select');
            inputs.forEach(input => input.disabled = true);
            // Hide save button
            document.querySelector('.btn-save').style.display = 'none';
        }

        function editEmployee(data) {
            clearForm();
            enableEdit(true); // Set to edit mode
            
            document.getElementById('modalTitle').innerText = 'Edit Employee Record';
            document.getElementById('db_id').value = data.id;
            document.getElementById('employee_id').value = data.employee_id || '';
            document.getElementById('name').value = data.name;
            document.getElementById('date_hired').value = data.date_hired || '';
            document.getElementById('status').value = data.status || 'Active';
            document.getElementById('contract').value = data.contract || 'Regular';
            document.getElementById('department').value = data.department;
            document.getElementById('job_title').value = data.job_title;
            document.getElementById('salary').value = data.salary || '';
            
            modal.style.display = 'flex';
        }

        function viewEmployee(data) {
            clearForm();
            
            document.getElementById('modalTitle').innerText = 'View Employee Record';
            document.getElementById('db_id').value = data.id;
            document.getElementById('employee_id').value = data.employee_id || '';
            document.getElementById('name').value = data.name;
            document.getElementById('date_hired').value = data.date_hired || '';
            document.getElementById('status').value = data.status || 'Active';
            document.getElementById('contract').value = data.contract || 'Regular';
            document.getElementById('department').value = data.department;
            document.getElementById('job_title').value = data.job_title;
            document.getElementById('salary').value = data.salary || '';

            disableView(); // Set to view-only mode
            modal.style.display = 'flex';
        }
    </script>
</body>
</html>