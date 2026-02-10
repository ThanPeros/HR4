<?php
// compensation/compensation-adjustment.php
session_start();
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// Initialize Theme
$currentTheme = $_SESSION['theme'] ?? 'light';

// Helper Function for Alerts
function showAlert($type, $message) {
    return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                <i class='fas fa-" . ($type == 'success' ? 'check-circle' : 'exclamation-triangle') . "'></i> {$message}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
}

// 1. Database Setup / Migration
// Adjustment Types Table
$pdo->exec("CREATE TABLE IF NOT EXISTS adjustment_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    requires_approval BOOLEAN DEFAULT 1
)");

// Salary Adjustments Table
$pdo->exec("CREATE TABLE IF NOT EXISTS salary_adjustments_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_name VARCHAR(100) NOT NULL,
    adjustment_type_id INT,
    current_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    new_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    adjustment_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    percentage_increase DECIMAL(5,2),
    effective_date DATE DEFAULT CURRENT_DATE,
    justification TEXT,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    requested_by VARCHAR(100),
    approved_by VARCHAR(100),
    FOREIGN KEY (adjustment_type_id) REFERENCES adjustment_types(id) ON DELETE SET NULL
)");

// Migration logic removed as we are using a new table version

// Seed Adjustment Types if empty
$checkTypes = $pdo->query("SELECT COUNT(*) FROM adjustment_types")->fetchColumn();
if ($checkTypes == 0) {
    $pdo->exec("INSERT INTO adjustment_types (type_name, description, requires_approval) VALUES 
        ('Merit Increase', 'Annual performance-based raise', 1),
        ('Promotion', 'Salary adjustment due to role change', 1),
        ('Market Adjustment', 'Correction based on salary benchmarking', 1),
        ('Cost of Living', 'Inflation-based adjustment', 0),
        ('Retention', 'Counter-offer or retention bonus', 1)
    ");
}

// Handle Form Submissions
$alertMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_adjustment'])) {
        $empName = $_POST['employee_name'];
        $typeId = $_POST['adjustment_type_id'];
        $currentSalary = $_POST['current_salary'];
        $newSalary = $_POST['new_salary'];
        $justification = $_POST['justification'];
        $requestedBy = $_SESSION['user'] ?? 'System';

        $diff = $newSalary - $currentSalary;
        $pct = ($currentSalary > 0) ? ($diff / $currentSalary) * 100 : 0;

        $stmt = $pdo->prepare("INSERT INTO salary_adjustments_v2 (employee_name, adjustment_type_id, current_salary, new_salary, adjustment_amount, percentage_increase, justification, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        if ($stmt->execute([$empName, $typeId, $currentSalary, $newSalary, $diff, $pct, $justification, $requestedBy])) {
            $alertMessage = showAlert('success', 'Adjustment request submitted successfully.');
        } else {
            $alertMessage = showAlert('danger', 'Failed to submit request.');
        }
    } elseif (isset($_POST['approve_adjustment'])) {
        $id = $_POST['adjustment_id'];
        $approver = $_SESSION['user'] ?? 'Admin';
        
        // Update status
        $stmt = $pdo->prepare("UPDATE salary_adjustments_v2 SET status = 'Approved', approved_by = ? WHERE id = ?");
        if ($stmt->execute([$approver, $id])) {
            // Update Employee Salary in Master Table
            // Fetch the adjustment details first
            $adj = $pdo->query("SELECT * FROM salary_adjustments_v2 WHERE id = $id")->fetch();
            if ($adj) {
                $upd = $pdo->prepare("UPDATE employees SET salary = ? WHERE name = ?");
                $upd->execute([$adj['new_salary'], $adj['employee_name']]);
            }
            $alertMessage = showAlert('success', 'Adjustment approved and salary updated.');
        }
    } elseif (isset($_POST['reject_adjustment'])) {
        $id = $_POST['adjustment_id'];
        $approver = $_SESSION['user'] ?? 'Admin';
        $stmt = $pdo->prepare("UPDATE salary_adjustments_v2 SET status = 'Rejected', approved_by = ? WHERE id = ?");
        $stmt->execute([$approver, $id]);
        $alertMessage = showAlert('warning', 'Adjustment request rejected.');
    }
}

// Fetch Data
$adjustments = $pdo->query("
    SELECT sa.*, at.type_name 
    FROM salary_adjustments_v2 sa 
    LEFT JOIN adjustment_types at ON sa.adjustment_type_id = at.id 
    ORDER BY sa.effective_date DESC
")->fetchAll();

$adjTypes = $pdo->query("SELECT * FROM adjustment_types")->fetchAll();

// Fetch Employees
try {
    $employees = $pdo->query("SELECT id, name, job_title, salary FROM employees WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    try {
        $employees = $pdo->query("SELECT id, name, job_title, salary FROM employees ORDER BY name ASC")->fetchAll();
    } catch (Exception $e2) {
        $employees = []; 
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compensation Review | HR Compensation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --success-color: #1cc88a;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --dark-bg: #2c3e50;
            --dark-card: #34495e;
            --border-radius: 0.35rem;
            --shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            --text-dark: #212529;
            --text-light: #f8f9fa;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
        }
        
        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
            --secondary-color: #2c3e50; /* For override consistency */
        }

        .main-content { padding: 2rem; margin-top: 60px; }
        
        .page-header {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        body.dark-mode .page-header { background: var(--dark-card); }
        
        .card-custom {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        body.dark-mode .card-custom { background: var(--dark-card); }

        .status-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .status-Pending { background-color: #fff3cd; color: #856404; }
        .status-Approved { background-color: #d4edda; color: #155724; }
        .status-Rejected { background-color: #f8d7da; color: #721c24; }

        .increase-tag {
            font-weight: bold;
            color: #1cc88a;
        }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="h3 mb-1"><i class="fas fa-chart-line"></i> Compensation Review & Adjustment</h1>
                <p class="text-muted mb-0">Manage merit increases, promotions, and retention adjustments</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal">
                <i class="fas fa-plus-circle"></i> New Adjustment Request
            </button>
        </div>

        <?php echo $alertMessage; ?>

        <div class="card card-custom">
            <div class="card-body">
                <h5 class="card-title mb-4">Adjustment History</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Employee</th>
                                <th>Type</th>
                                <th>Old Salary</th>
                                <th>New Salary</th>
                                <th>Increase</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($adjustments)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No adjustment records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($adjustments as $row): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['type_name']); ?></td>
                                    <td>₱<?php echo number_format($row['current_salary']); ?></td>
                                    <td class="fw-bold">₱<?php echo number_format($row['new_salary']); ?></td>
                                    <td>
                                        <span class="increase-tag">
                                            +₱<?php echo number_format($row['adjustment_amount']); ?> 
                                            (<?php echo number_format($row['percentage_increase'], 1); ?>%)
                                        </span>
                                    </td>
                                    <td><span class="status-badge status-<?php echo $row['status']; ?>"><?php echo $row['status']; ?></span></td>
                                    <td>
                                        <?php if ($row['status'] == 'Pending'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="adjustment_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="approve_adjustment" class="btn btn-sm btn-success" title="Approve"><i class="fas fa-check"></i></button>
                                            <button type="submit" name="reject_adjustment" class="btn btn-sm btn-danger" title="Reject"><i class="fas fa-times"></i></button>
                                        </form>
                                        <?php else: ?>
                                            <small class="text-muted">Closed by <?php echo htmlspecialchars($row['approved_by'] ?? 'System'); ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Request Adjustment -->
    <div class="modal fade" id="requestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Request Salary Adjustment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Employee</label>
                            <select class="form-select" name="employee_name" id="employeeSelect" required onchange="updateCurrentSalary()">
                                <option value="">Select Employee...</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp['name']); ?>" data-salary="<?php echo $emp['salary'] ?? 0; ?>">
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Adjustment Type</label>
                            <select class="form-select" name="adjustment_type_id" required>
                                <?php foreach ($adjTypes as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['type_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Current Salary</label>
                                <input type="number" class="form-control" name="current_salary" id="currentSalary" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">New Salary</label>
                                <input type="number" step="0.01" class="form-control" name="new_salary" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Justification / Notes</label>
                            <textarea class="form-control" name="justification" rows="3" placeholder="Reason for adjustment..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="request_adjustment" class="btn btn-primary">Submit for Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateCurrentSalary() {
            const select = document.getElementById('employeeSelect');
            const salaryInput = document.getElementById('currentSalary');
            const salary = select.options[select.selectedIndex].getAttribute('data-salary');
            salaryInput.value = salary || 0;
        }
    </script>
</body>
</html>
