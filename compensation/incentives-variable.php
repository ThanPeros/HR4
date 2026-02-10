<?php
// compensation/incentives-variable.php
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
// Incentive Types (e.g., Performance Bonus, 13th Month, Sales Commission)
$pdo->exec("CREATE TABLE IF NOT EXISTS incentive_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL,
    description TEXT,
    eligibility_role ENUM('All', 'Admin', 'HR', 'Operations', 'Sales', 'IT') DEFAULT 'All',
    is_recurring BOOLEAN DEFAULT 0,
    amount_type ENUM('Fixed', 'Range') DEFAULT 'Fixed',
    default_amount DECIMAL(12,2) DEFAULT 0.00,
    min_amount DECIMAL(12,2) DEFAULT 0.00,
    max_amount DECIMAL(12,2) DEFAULT 0.00
)");

// Check for new columns and add if missing (Migration)
try {
    $pdo->exec("ALTER TABLE incentive_types ADD COLUMN IF NOT EXISTS amount_type ENUM('Fixed', 'Range') DEFAULT 'Fixed' AFTER is_recurring");
    $pdo->exec("ALTER TABLE incentive_types ADD COLUMN IF NOT EXISTS min_amount DECIMAL(12,2) DEFAULT 0.00 AFTER default_amount");
    $pdo->exec("ALTER TABLE incentive_types ADD COLUMN IF NOT EXISTS max_amount DECIMAL(12,2) DEFAULT 0.00 AFTER min_amount");
} catch (Exception $e) {}

// Employee Incentives (Linking employees to incentives)
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_incentives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_name VARCHAR(100) NOT NULL,
    incentive_type_id INT,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('Pending', 'Approved', 'Paid', 'Rejected') DEFAULT 'Pending',
    kpi_notes TEXT,
    date_granted DATE DEFAULT CURRENT_DATE,
    FOREIGN KEY (incentive_type_id) REFERENCES incentive_types(id) ON DELETE CASCADE
)");

// Seed Incentive Types if empty or update existing for demo
// Seed Incentive Types if empty or update existing for demo
$checkTypes = $pdo->query("SELECT COUNT(*) FROM incentive_types")->fetchColumn();
if ($checkTypes == 0) {
    $pdo->exec("INSERT INTO incentive_types (type_name, description, eligibility_role, is_recurring, amount_type, default_amount, min_amount, max_amount) VALUES 
        ('Performance Bonus', 'Annual performance-based reward', 'All', 0, 'Range', 0.00, 1000.00, 10000.00),
        ('13th Month Pay', 'Mandated year-end bonus', 'All', 1, 'Range', 0.00, 1000.00, 999999.00),
        ('Sales Commission', 'Revenue-based incentive', 'Sales', 1, 'Range', 0.00, 1000.00, 20000.00),
        ('Perfect Attendance', 'Reward for zero absences', 'All', 1, 'Fixed', 2000.00, 0.00, 0.00),
        ('Safety Award', 'Zero accident/incident reward', 'Operations', 1, 'Fixed', 3000.00, 0.00, 0.00)
    ");
} else {
    // Update ranges as per request
    $pdo->exec("UPDATE incentive_types SET min_amount = 1000.00, max_amount = 10000.00 WHERE type_name = 'Performance Bonus'");
    $pdo->exec("UPDATE incentive_types SET min_amount = 1000.00, max_amount = 20000.00 WHERE type_name = 'Sales Commission'");
    // Ensure 13th Month Pay has a high limit for salary calculations
    $pdo->exec("UPDATE incentive_types SET max_amount = 999999.00 WHERE type_name = '13th Month Pay'");
}

// Handle Form Submissions
$alertMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_incentive'])) {
        $empName = $_POST['employee_name'];
        $typeId = $_POST['incentive_type_id'];
        $amount = $_POST['amount'];
        $notes = $_POST['kpi_notes'];

        // Validate Amount Range
        $stmt = $pdo->prepare("SELECT amount_type, min_amount, max_amount, default_amount FROM incentive_types WHERE id = ?");
        $stmt->execute([$typeId]);
        $typeData = $stmt->fetch();

        $isValid = true;
        if ($typeData) {
            if ($typeData['amount_type'] === 'Fixed') {
                if (floatval($amount) != floatval($typeData['default_amount'])) {
                    $alertMessage = showAlert('warning', "Amount adjusted to Fixed Default: ₱" . number_format($typeData['default_amount'], 2));
                    $amount = $typeData['default_amount'];
                }
            } elseif ($typeData['amount_type'] === 'Range') {
                if ($amount < $typeData['min_amount'] || $amount > $typeData['max_amount']) {
                    $isValid = false;
                    $alertMessage = showAlert('danger', "Amount must be between ₱" . number_format($typeData['min_amount']) . " and ₱" . number_format($typeData['max_amount']));
                }
            }
        }

        if ($isValid) {
            $stmt = $pdo->prepare("INSERT INTO employee_incentives (employee_name, incentive_type_id, amount, kpi_notes) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$empName, $typeId, $amount, $notes])) {
                $alertMessage = showAlert('success', 'Incentive successfully added for approval.');
            } else {
                $alertMessage = showAlert('danger', 'Failed to add incentive.');
            }
        }
    } elseif (isset($_POST['update_status'])) {
        $incId = $_POST['incentive_id'];
        $newStatus = $_POST['status'];
        
        $stmt = $pdo->prepare("UPDATE employee_incentives SET status = ? WHERE id = ?");
        if ($stmt->execute([$newStatus, $incId])) {
             $alertMessage = showAlert('success', 'Incentive status updated.');
        }
    }
}

// Fetch Data
$types = $pdo->query("SELECT * FROM incentive_types")->fetchAll();

// Fetch Employees for Dropdown
try {
    $employees = $pdo->query("SELECT id, name, job_title, salary FROM employees WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    // Fallback in case status column doesn't exist or is different
    try {
        $employees = $pdo->query("SELECT id, name, job_title, salary FROM employees ORDER BY name ASC")->fetchAll();
    } catch (Exception $e2) {
        $employees = []; 
    }
}

$incentives = $pdo->query("
    SELECT ei.*, it.type_name, it.eligibility_role 
    FROM employee_incentives ei 
    JOIN incentive_types it ON ei.incentive_type_id = it.id 
    ORDER BY ei.date_granted DESC
")->fetchAll();

// Metrics
$totalDisbursed = 0;
$pendingCount = 0;
foreach ($incentives as $inc) {
    if ($inc['status'] === 'Paid') $totalDisbursed += $inc['amount'];
    if ($inc['status'] === 'Pending') $pendingCount++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incentives & Variable Pay | HR Compensation</title>
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
        
        .card-metric {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary-color);
            height: 100%;
            transition: transform 0.2s;
        }
        body.dark-mode .card-metric { background: var(--dark-card); }
        .card-metric:hover { transform: translateY(-5px); }

        .data-table {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            width: 100%;
            box-shadow: var(--shadow);
        }
        body.dark-mode .data-table { background: var(--dark-card); }
        
        .data-table th {
            background: #f8f9fc;
            padding: 1rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        body.dark-mode .data-table th { background: #2d3748; color: #63b3ed; }
        
        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid #e3e6f0;
            vertical-align: middle;
        }
        body.dark-mode .data-table td { border-bottom: 1px solid #4a5568; }

        .badge-status { padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .bg-pending { background-color: #ffeeba; color: #856404; }
        .bg-approved { background-color: #d4edda; color: #155724; }
        .bg-paid { background-color: #cce5ff; color: #004085; }
        .bg-rejected { background-color: #f8d7da; color: #721c24; }

    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        
        <div class="page-header">
            <div>
                <h1 class="h3 mb-1"><i class="fas fa-hand-holding-usd"></i> Incentives & Variable Pay</h1>
                <p class="text-muted mb-0">Performance-based rewards and KPI-linked bonuses</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addIncentiveModal">
                <i class="fas fa-plus-circle"></i> Grant Incentive
            </button>
        </div>

        <?php echo $alertMessage; ?>

        <!-- Key Metrics -->
        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card-metric" style="border-left-color: var(--success-color);">
                    <h6 class="text-muted text-uppercase mb-2">Total Disbursed</h6>
                    <h2 class="mb-0 fw-bold">₱<?php echo number_format($totalDisbursed, 2); ?></h2>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-metric" style="border-left-color: var(--warning-color);">
                    <h6 class="text-muted text-uppercase mb-2">Pending Approval</h6>
                    <h2 class="mb-0 fw-bold"><?php echo $pendingCount; ?> <small class="text-muted fs-6">Requests</small></h2>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-metric" style="border-left-color: var(--primary-color);">
                    <h6 class="text-muted text-uppercase mb-2">Active Programs</h6>
                    <h2 class="mb-0 fw-bold"><?php echo count($types); ?> <small class="text-muted fs-6">Types</small></h2>
                </div>
            </div>
        </div>

        <!-- Incentives Table -->
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-white d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Incentive History & Status</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="data-table mb-0">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Incentive Type</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>KPI / Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($incentives)): ?>
                                <tr><td colspan="6" class="text-center text-muted p-4">No incentives found. Start by granting one!</td></tr>
                            <?php else: ?>
                                <?php foreach ($incentives as $row): 
                                    $statusClass = match($row['status']) {
                                        'Pending' => 'bg-pending',
                                        'Approved' => 'bg-approved',
                                        'Paid' => 'bg-paid',
                                        'Rejected' => 'bg-rejected',
                                        default => 'bg-secondary'
                                    };
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($row['type_name']); ?>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($row['eligibility_role']); ?> Role</small>
                                    </td>
                                    <td class="fw-bold text-dark">₱<?php echo number_format($row['amount'], 2); ?></td>
                                    <td><span class="badge-status <?php echo $statusClass; ?>"><?php echo $row['status']; ?></span></td>
                                    <td><?php echo htmlspecialchars($row['kpi_notes']); ?></td>
                                    <td>
                                        <?php if ($row['status'] === 'Pending'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="incentive_id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" name="status" value="Approved" class="btn btn-sm btn-success" title="Approve">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="submit" name="status" value="Rejected" class="btn btn-sm btn-danger" title="Reject">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                        <?php elseif ($row['status'] === 'Approved'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="incentive_id" value="<?php echo $row['id']; ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <button type="submit" name="status" value="Paid" class="btn btn-sm btn-primary" title="Mark as Paid">
                                                <i class="fas fa-money-bill-wave"></i> Pay
                                            </button>
                                        </form>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-lock"></i></span>
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

    <!-- Add Incentive Modal -->
    <div class="modal fade" id="addIncentiveModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Grant New Incentive</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Employee</label>
                            <select class="form-select" name="employee_name" required>
                                <option value="">Select Employee...</option>
                                <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo htmlspecialchars($emp['name']); ?>" 
                                    data-salary="<?php echo $emp['salary'] ?? 0; ?>">
                                    <?php echo htmlspecialchars($emp['name']); ?> 
                                    (<?php echo htmlspecialchars($emp['job_title'] ?? 'N/A'); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Incentive Type</label>
                            <select class="form-select" id="incentiveTypeSelect" name="incentive_type_id" required onchange="calculateIncentive()">
                                <option value="">Select Type...</option>
                                <?php foreach ($types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" 
                                    data-name="<?php echo $type['type_name']; ?>"
                                    data-type="<?php echo $type['amount_type']; ?>"
                                    data-default="<?php echo $type['default_amount']; ?>"
                                    data-min="<?php echo $type['min_amount']; ?>"
                                    data-max="<?php echo $type['max_amount']; ?>">
                                    <?php echo $type['type_name']; ?> 
                                    (<?php echo $type['amount_type'] == 'Fixed' ? 'Fixed: ₱'.number_format($type['default_amount']) : 'Range: ₱'.number_format($type['min_amount']).' - ₱'.number_format($type['max_amount']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount (PHP)</label>
                            <input type="number" step="0.01" class="form-control" name="amount" id="amountInput" required 
                                oninput="if(this.value.length > 6) this.value = this.value.slice(0, 6);">
                            <div class="form-text" id="amountHelp">Select an incentive type and employee to see details.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">KPI Achievements / Notes</label>
                            <textarea class="form-control" name="kpi_notes" rows="3" placeholder="e.g. Exceeded sales target by 15%"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="add_incentive" class="btn btn-primary">Grant Incentive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Trigger calculation when either employee or type changes
        document.querySelector('select[name="employee_name"]').addEventListener('change', calculateIncentive);

        function calculateIncentive() {
            const empSelect = document.querySelector('select[name="employee_name"]');
            const typeSelect = document.getElementById('incentiveTypeSelect');
            const input = document.getElementById('amountInput');
            const help = document.getElementById('amountHelp');

            if (empSelect.selectedIndex <= 0 || typeSelect.selectedIndex <= 0) {
                input.value = '';
                input.readOnly = false;
                help.innerText = 'Select an employee and incentive type.';
                return;
            }

            const empOption = empSelect.options[empSelect.selectedIndex];
            const typeOption = typeSelect.options[typeSelect.selectedIndex];

            const salary = parseFloat(empOption.getAttribute('data-salary')) || 0;
            const typeName = typeOption.getAttribute('data-name');
            const typeAmt = typeOption.getAttribute('data-type');
            const def = typeOption.getAttribute('data-default');
            const min = typeOption.getAttribute('data-min');
            const max = typeOption.getAttribute('data-max');

            // 13th Month Pay Logic
            if (typeName === '13th Month Pay') {
                // Formula: Total Basic Salary / 12. Assuming 'salary' is monthly basic, then (salary * 12) / 12 = salary.
                // We use the current monthly salary as the baseline for the 13th month pay.
                // This assumes full year tenure. Editable for pro-rating.
                input.value = salary.toFixed(2);
                input.readOnly = false; // Allow editing for pro-rating
                help.innerHTML = `<span class="text-primary"><i class="fas fa-calculator"></i> Auto-calculated based on basic salary (₱${salary.toLocaleString()}). Adjust if pro-rated.</span>`;
                return;
            }

            if (typeAmt === 'Fixed') {
                input.value = def;
                input.readOnly = true;
                help.innerText = 'Fixed amount for this incentive type.';
                input.classList.add('bg-light');
            } else {
                input.value = '';
                input.readOnly = false;
                input.min = min;
                input.max = max;
                input.classList.remove('bg-light');
                help.innerText = `Enter amount between ₱${Number(min).toLocaleString()} and ₱${Number(max).toLocaleString()}`;
            }
        }
    </script>
</body>
</html>
