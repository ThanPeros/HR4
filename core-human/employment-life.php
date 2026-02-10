<?php
// core-human/employment-life.php

include '../includes/sidebar.php';
// Database connection (assuming shared config)
include '../config/db.php';

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

// --- DB MIGRATION LOGIC (For Lifecycle Events) --- //
try {
    // 1. Ensure employees table columns exist (dependencies for our JOINs)
    $check = $conn->query("SHOW COLUMNS FROM employees LIKE 'employee_id'");
    if ($check->num_rows == 0) {
        $conn->query("ALTER TABLE employees ADD COLUMN employee_id VARCHAR(50) NULL AFTER id");
    }

    // 2. Check if employment_lifecycle table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'employment_lifecycle'");
    if ($checkTable->num_rows == 0) {
        // Create table - IMPORTANT: employee_id must match employees.id type (INT 6 UNSIGNED)
        $sql = "CREATE TABLE employment_lifecycle (
            id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id INT(6) UNSIGNED NOT NULL,
            event_type VARCHAR(50) NOT NULL COMMENT 'Hire, Regularization, Promotion, Transfer, Resignation, Termination',
            event_date DATE NOT NULL,
            reason TEXT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        if (!$conn->query($sql)) {
            throw new Exception("Create Table Failed: " . $conn->error);
        }
    }
} catch (Exception $e) {
    // If table creation failed, we can't really proceed with the page normally, but we catch to show error
    $dbError = $e->getMessage();
}

// --- HANDLE FORM SUBMISSIONS --- //
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_event') {
        $employee_id = $conn->real_escape_string($_POST['employee_id']);
        $event_type = $conn->real_escape_string($_POST['event_type']);
        $event_date = $conn->real_escape_string($_POST['event_date']);
        $reason = $conn->real_escape_string($_POST['reason']);
        
        // Optional: Update employee status based on event
        // e.g. If Termination/Resignation -> Set status = 'Inactive'
        
        $sql = "INSERT INTO employment_lifecycle (employee_id, event_type, event_date, reason) 
                VALUES ('$employee_id', '$event_type', '$event_date', '$reason')";
        
        if ($conn->query($sql)) {
            $message = "Lifecycle event recorded successfully.";
            $message_type = "success";
            
            // Logic to update main employee status if needed
            if ($event_type === 'Resignation' || $event_type === 'Termination') {
                $conn->query("UPDATE employees SET status = 'Inactive' WHERE id = '$employee_id'");
            }
             if ($event_type === 'Regularization') {
                $conn->query("UPDATE employees SET employment_status = 'Regular' WHERE id = '$employee_id'");
            }
        } else {
            $message = "Error recording event: " . $conn->error;
            $message_type = "error";
        }
    }
}

// Fetch Employees for Dropdown
$empSql = "SELECT id, name, employee_id FROM employees ORDER BY name ASC";
$empResult = $conn->query($empSql);
$employees = [];
if ($empResult->num_rows > 0) {
    while($row = $empResult->fetch_assoc()) {
        $employees[] = $row;
    }
}

// Fetch Lifecycle Events (Filtered by Search if present)
$search_term = '';
$where_clause = '';
if (isset($_GET['search'])) {
    $search_term = $conn->real_escape_string($_GET['search']);
    $where_clause = "WHERE e.name LIKE '%$search_term%' OR e.employee_id LIKE '%$search_term%' OR l.event_type LIKE '%$search_term%'";
}

$eventsSql = "SELECT l.*, e.name, e.employee_id as emp_code 
              FROM employment_lifecycle l 
              JOIN employees e ON l.employee_id = e.id 
              $where_clause
              ORDER BY l.event_date DESC, l.created_at DESC";

// Execute query with error handling
$eventsResult = false;
try {
    $eventsResult = $conn->query($eventsSql);
} catch (Exception $e) {
    $dbError = "Query Failed: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employment Lifecycle | HR System</title>
    <!-- Bootstrap 5 for consistency -->
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

        /* Event Chips */
        .event-chip {
            padding: 0.25rem 0.6rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }
        
        .chip-hire { background: #d4edda; color: #155724; }
        .chip-regularization { background: #cce5ff; color: #004085; }
        .chip-promotion { background: #d1ecf1; color: #0c5460; }
        .chip-transfer { background: #fff3cd; color: #856404; }
        .chip-resignation { background: #f8d7da; color: #721c24; }
        .chip-termination { background: #343a40; color: #fff; }

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
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-history"></i>
                        Employment Lifecycle
                    </h1>
                    <p class="page-subtitle">Track and manage employee history events</p>
                </div>
                <div>
                     <button class="btn btn-primary" onclick="openModal()">
                        <i class="fas fa-plus"></i> Log Event
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

            <!-- Search (Simulated) -->
            <div class="filters-container">
                <div class="filters-header">
                    <h3 class="filters-title">
                        <i class="fas fa-search"></i> Search Events
                    </h3>
                </div>
                <form method="GET" action="" class="filters-form d-flex gap-2">
                    <input type="text" class="form-control" name="search" placeholder="Search by Name, ID or Event Type..." value="<?php echo htmlspecialchars($search_term); ?>" style="max-width: 400px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="?" class="btn btn-secondary" style="background: #6c757d; color: white; text-decoration: none;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </form>
            </div>

            <!-- Events List -->
            <div class="report-card">
                <div class="report-card-header">
                    <h3 class="report-card-title">
                        Lifecycle History
                        <small class="text-muted">(<?php echo ($eventsResult) ? $eventsResult->num_rows : 0; ?> records)</small>
                    </h3>
                </div>
                
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Event Type</th>
                                <th>Reason / Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (isset($dbError)): ?>
                                 <tr>
                                    <td colspan="4" style="text-align:center; padding: 2rem; color: #e74a3b;">
                                        <i class="fas fa-exclamation-triangle"></i> Database Error: <?php echo htmlspecialchars($dbError); ?><br>
                                        <small>Please verify that the employees table exists and has compatible ID types.</small>
                                    </td>
                                </tr>
                            <?php elseif ($eventsResult && $eventsResult->num_rows > 0): ?>
                                <?php while($row = $eventsResult->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo date('M d, Y', strtotime($row['event_date'])); ?></strong></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($row['emp_code'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                                $type = $row['event_type'];
                                                $label = strtolower(str_replace(' ','-', $type)); // sanitize class name just in case
                                                
                                                // Map to existing chip classes
                                                $class = 'chip-' . $label;
                                                // Default fallback if not matched
                                                
                                                echo "<span class='event-chip $class'>$type</span>";
                                            ?>
                                        </td>
                                        <td><?php echo nl2br(htmlspecialchars($row['reason'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" style="text-align:center; padding: 2rem; color: #858796;">No lifecycle events recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal (Bootstrap 5) -->
    <div class="modal fade" id="lifecycleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Log Lifecycle Event</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_event">
                        
                        <div class="mb-3">
                            <label for="employee_id" class="form-label">Employee</label>
                            <select class="form-select" name="employee_id" id="employee_id" required>
                                <option value="">Select Employee</option>
                                <?php foreach($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>">
                                        <?php echo htmlspecialchars($emp['name']); ?> (<?php echo htmlspecialchars($emp['employee_id'] ?? 'No ID'); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="event_type" class="form-label">Event Type</label>
                            <select class="form-select" name="event_type" id="event_type" required>
                                <option value="">Select Event</option>
                                <option value="Hire">Hire</option>
                                <option value="Regularization">Regularization</option>
                                <option value="Promotion">Promotion</option>
                                <option value="Transfer">Transfer</option>
                                <option value="Resignation">Resignation</option>
                                <option value="Termination">Termination</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="event_date" class="form-label">Event Date</label>
                            <input type="date" class="form-control" name="event_date" id="event_date" required>
                        </div>

                        <div class="mb-3">
                            <label for="reason" class="form-label">Reason / Details</label>
                            <textarea class="form-control" name="reason" id="reason" rows="3" placeholder="Enter reason or additional details..." required></textarea>
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
        const modalInstance = new bootstrap.Modal(document.getElementById('lifecycleModal'));
        
        function openModal() {
            // Reset form
            document.getElementById('employee_id').value = '';
            document.getElementById('event_type').value = '';
            document.getElementById('event_date').value = '';
            document.getElementById('reason').value = '';
            
            modalInstance.show();
        }

        function closeModal() {
            modalInstance.hide();
        }
    </script>
</body>
</html>
