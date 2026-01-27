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

// --- DB MIGRATION LOGIC (For Lifecycle Events) --- //
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

// Fetch All Lifecycle Events
$eventsSql = "SELECT l.*, e.name, e.employee_id as emp_code 
              FROM employment_lifecycle l 
              JOIN employees e ON l.employee_id = e.id 
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Shared Styles (Consistent with employment_info.php) -->
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

        /* Layout Wrapper */
        .main-wrapper {
            transition: margin-left 0.3s ease;
            width: 100%;
            margin-top: 60px;
        }

        @media (min-width: 769px) {
            body.sidebar-open .main-wrapper {
                margin-left: 250px;
                width: calc(100% - 250px);
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
        
        body.dark-mode .action-title { color: var(--text-light); }

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
        
        .btn-add:hover { background-color: #2e59d9; }

        /* Content */
        .content-container { padding: 2rem; }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        body.dark-mode .card { background: var(--dark-card); }

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

        /* Table */
        .table-responsive { overflow-x: auto; width: 100%; }
        table { width: 100%; border-collapse: collapse; }

        th, td {
            padding: 1rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode th, body.dark-mode td {
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
        
        tr:hover { background-color: #f8f9fc; }
        body.dark-mode tr:hover { background-color: rgba(255,255,255,0.05); }

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

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1100;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        
        body.dark-mode .modal-content { background: var(--dark-card); color: white; }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title { font-size: 1.25rem; font-weight: 700; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #aaa; }
        
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; margin-bottom: 0.5rem; font-weight: 600; font-size: 0.9rem; }
        .form-control {
            width: 100%;
            padding: 0.7rem;
            border: 1px solid #d1d3e2;
            border-radius: 0.35rem;
            font-size: 1rem;
        }
        
        body.dark-mode .form-control {
            background: #2d3748; border-color: #4a5568; color: white;
        }
        
        textarea.form-control { resize: vertical; min-height: 80px; }

        .btn-save {
            background: var(--primary-color);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 0.35rem;
            border: none;
            cursor: pointer;
            width: 100%;
            font-weight: 600;
            margin-top: 1rem;
        }
        .btn-save:hover { background: #224abe; }
        
        /* Alert */
        .alert {
            padding: 1rem;
            margin: 0 2rem 1rem 2rem;
            border-radius: var(--border-radius);
            display: flex; justify-content: space-between;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

    </style>
</head>
<body class="<?php echo ($currentTheme === 'dark') ? 'dark-mode' : ''; ?> <?php echo (isset($_SESSION['sidebar_state']) && $_SESSION['sidebar_state'] === 'open') ? 'sidebar-open' : ''; ?>">

<div class="main-wrapper">
    <!-- Action Header -->
    <div class="action-header">
        <h2 class="action-title">Employment Lifecycle Tracking</h2>
        <button class="btn-add" onclick="openModal()">
            <i class="fas fa-plus"></i> Log Event
        </button>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo ($message_type == 'success') ? 'success' : 'error'; ?>">
            <span><?php echo $message; ?></span>
            <span onclick="this.parentElement.style.display='none'" style="cursor:pointer">&times;</span>
        </div>
    <?php endif; ?>

    <div class="content-container">
        <!-- Events List -->
        <div class="card">
            <div class="card-header">Lifecycle History</div>
            <div class="table-responsive">
                <table>
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
                                    <td><?php echo date('M d, Y', strtotime($row['event_date'])); ?></td>
                                    <td>
                                        <div style="font-weight:600;"><?php echo htmlspecialchars($row['name']); ?></div>
                                        <div style="font-size:0.8rem; opacity:0.7;"><?php echo htmlspecialchars($row['emp_code'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <?php 
                                            $type = $row['event_type'];
                                            $class = 'chip-' . strtolower($type);
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

<!-- Modal -->
<div id="lifecycleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Log Lifecycle Event</h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="add_event">
            
            <div class="form-group">
                <label class="form-label">Employee</label>
                <select class="form-control" name="employee_id" required>
                    <option value="">Select Employee</option>
                    <?php foreach($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>">
                            <?php echo htmlspecialchars($emp['name']); ?> (<?php echo htmlspecialchars($emp['employee_id'] ?? 'No ID'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Event Type</label>
                <select class="form-control" name="event_type" required>
                    <option value="">Select Event</option>
                    <option value="Hire">Hire</option>
                    <option value="Regularization">Regularization</option>
                    <option value="Promotion">Promotion</option>
                    <option value="Transfer">Transfer</option>
                    <option value="Resignation">Resignation</option>
                    <option value="Termination">Termination</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Event Date</label>
                <input type="date" class="form-control" name="event_date" required>
            </div>

            <div class="form-group">
                <label class="form-label">Reason / Details</label>
                <textarea class="form-control" name="reason" placeholder="Enter reason or additional details..." required></textarea>
            </div>

            <button type="submit" class="btn-save">Save Record</button>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('lifecycleModal');
    
    function openModal() {
        modal.style.display = 'flex';
    }

    function closeModal() {
        modal.style.display = 'none';
    }

    window.onclick = function(event) {
        if (event.target == modal) {
            closeModal();
        }
    }
</script>

</body>
</html>
