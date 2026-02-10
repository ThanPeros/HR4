<?php
// compensation/allowance-salary.php
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
// Allowance Types
$pdo->exec("CREATE TABLE IF NOT EXISTS allowance_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    type ENUM('Fixed', 'Conditional') DEFAULT 'Fixed',
    amount DECIMAL(12,2) DEFAULT 0.00,
    eligibility_criteria VARCHAR(255) DEFAULT 'All Roles'
)");

// Employee Allowances (Assignments)
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_allowances (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_name VARCHAR(100) NOT NULL,
    allowance_id INT,
    effective_date DATE DEFAULT CURRENT_DATE,
    status ENUM('Active', 'Inactive') DEFAULT 'Active',
    FOREIGN KEY (allowance_id) REFERENCES allowance_types(id) ON DELETE CASCADE
)");

// Seed Data
$checkAllowances = $pdo->query("SELECT COUNT(*) FROM allowance_types")->fetchColumn();
if ($checkAllowances == 0) {
    $pdo->exec("INSERT INTO allowance_types (name, description, type, amount, eligibility_criteria) VALUES 
        ('Transport Allowance', 'Daily commute subsidy for office-based staff', 'Fixed', 2000.00, 'All Office Roles'),
        ('Communication Allowance', 'Mobile data plan for field staff', 'Fixed', 1500.00, 'Sales & Field Ops'),
        ('Meal Allowance', 'Daily lunch subsidy', 'Fixed', 3000.00, 'All Roles'),
        ('Hazard Pay', 'Additional pay for dangerous assignments', 'Conditional', 5000.00, 'Freight Handlers / Warehouse'),
        ('Field Assignment Allowance', 'Per diem for out-of-town assignments', 'Conditional', 1000.00, 'Per Day of Assignment')
    ");
}

// Handle Form Submissions
$alertMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_allowance_type'])) {
        $name = $_POST['name'];
        $desc = $_POST['description'];
        $type = $_POST['type'];
        $amount = $_POST['amount'];
        $criteria = $_POST['criteria'];

        $stmt = $pdo->prepare("INSERT INTO allowance_types (name, description, type, amount, eligibility_criteria) VALUES (?, ?, ?, ?, ?)");
        if ($stmt->execute([$name, $desc, $type, $amount, $criteria])) {
            $alertMessage = showAlert('success', 'New allowance type created.');
        } else {
            $alertMessage = showAlert('danger', 'Failed to create allowance type.');
        }
    } elseif (isset($_POST['assign_allowance'])) {
        $empName = $_POST['employee_name'];
        $allowanceId = $_POST['allowance_id'];
        
        $stmt = $pdo->prepare("INSERT INTO employee_allowances (employee_name, allowance_id) VALUES (?, ?)");
        if ($stmt->execute([$empName, $allowanceId])) {
            $alertMessage = showAlert('success', 'Allowance assigned to employee.');
        } else {
            $alertMessage = showAlert('danger', 'Failed to assign allowance.');
        }
    }
}

// Fetch Data
$allowanceTypes = $pdo->query("SELECT * FROM allowance_types ORDER BY name ASC")->fetchAll();
$assignments = $pdo->query("
    SELECT ea.*, at.name as allowance_name, at.amount, at.type 
    FROM employee_allowances ea 
    JOIN allowance_types at ON ea.allowance_id = at.id 
    ORDER BY ea.effective_date DESC
")->fetchAll();

// Fetch Employees
try {
    $employees = $pdo->query("SELECT id, name, job_title FROM employees WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    try {
        $employees = $pdo->query("SELECT id, name, job_title FROM employees ORDER BY name ASC")->fetchAll();
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
    <title>Allowances & Non-Salary Pay | HR Compensation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --dark-bg: #2c3e50;
            --dark-card: #34495e;
            --text-light: #f8f9fa;
            --text-dark: #212529;
            --border-radius: 0.35rem;
            --shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            min-height: 100vh;
        }
        
        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
            --secondary-color: #2c3e50;
        }

        .main-content {
            padding: 2rem;
            margin-top: 60px;
        }
        
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
            height: 100%;
        }
        body.dark-mode .card-custom { background: var(--dark-card); }
        
        .card-header-custom {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: rgba(0,0,0,0.03);
            font-weight: 600;
            color: var(--primary-color);
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        body.dark-mode .card-header-custom { border-bottom: 1px solid #4a5568; background: rgba(255,255,255,0.05); }

        .allowance-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 12px;
            font-weight: 600;
        }
        .badge-fixed { background-color: #e3f2fd; color: #0d47a1; }
        .badge-conditional { background-color: #fff3e0; color: #e65100; }

        .table-custom th {
            font-weight: 600;
            background-color: #f8f9fc;
            color: var(--primary-color);
        }
        body.dark-mode .table-custom th { background-color: #2d3748; color: #63b3ed; }
        .table-custom td { vertical-align: middle; }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        
        <div class="page-header">
            <div>
                <h1 class="h3 mb-1"><i class="fas fa-wallet"></i> Allowances & Non-Salary Pay</h1>
                <p class="text-muted mb-0">Manage fixed and conditional role-based allowances</p>
            </div>
            <div>
                <button class="btn btn-outline-primary me-2" data-bs-toggle="modal" data-bs-target="#addTypeModal">
                    <i class="fas fa-plus"></i> New Allowance Type
                </button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
                    <i class="fas fa-user-tag"></i> Assign Allowance
                </button>
            </div>
        </div>

        <?php echo $alertMessage; ?>

        <div class="row">
            <!-- Left Column: Allowance Types -->
            <div class="col-lg-5">
                <div class="card card-custom">
                    <div class="card-header-custom">
                        <span><i class="fas fa-list me-2"></i> Allowance Types</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allowanceTypes as $type): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($type['name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($type['eligibility_criteria']); ?></small>
                                        </td>
                                        <td>
                                            <span class="allowance-badge <?php echo $type['type'] == 'Fixed' ? 'badge-fixed' : 'badge-conditional'; ?>">
                                                <?php echo $type['type']; ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold">₱<?php echo number_format($type['amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Assigned Allowances -->
            <div class="col-lg-7">
                <div class="card card-custom">
                    <div class="card-header-custom">
                        <span><i class="fas fa-users me-2"></i> Employee Assignments</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-custom table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Allowance</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($assignments)): ?>
                                        <tr><td colspan="4" class="text-center p-4 text-muted">No allowances assigned yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($assignments as $row): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($row['allowance_name']); ?>
                                                <small class="d-block text-muted"><?php echo $row['type']; ?></small>
                                            </td>
                                            <td class="text-primary fw-bold">₱<?php echo number_format($row['amount'], 2); ?></td>
                                            <td><span class="badge bg-success"><?php echo $row['status']; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Add Allowance Type -->
    <div class="modal fade" id="addTypeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Allowance Type</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Allowance Name</label>
                            <input type="text" class="form-control" name="name" placeholder="e.g. Relocation Allowance" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="type">
                                    <option value="Fixed">Fixed (Role-based)</option>
                                    <option value="Conditional">Conditional (Hazard, etc.)</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount (₱)</label>
                                <input type="number" step="0.01" class="form-control" name="amount" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Eligibility Criteria</label>
                            <input type="text" class="form-control" name="criteria" placeholder="e.g. Sales Team Only">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="add_allowance_type" class="btn btn-primary">Save Allowance Type</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Assign Allowance -->
    <div class="modal fade" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Assign Allowance to Employee</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Employee</label>
                            <select class="form-select" name="employee_name" required>
                                <option value="">Select Employee...</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp['name']); ?>">
                                    <?php echo htmlspecialchars($emp['name']); ?> (<?php echo htmlspecialchars($emp['job_title']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Allowance Type</label>
                            <select class="form-select" name="allowance_id" required>
                                <option value="">Select Allowance...</option>
                                <?php foreach ($allowanceTypes as $type): ?>
                                <option value="<?php echo $type['id']; ?>">
                                    <?php echo htmlspecialchars($type['name']); ?> 
                                    (₱<?php echo number_format($type['amount']); ?> - <?php echo $type['type']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="assign_allowance" class="btn btn-success">Assign Benefit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
