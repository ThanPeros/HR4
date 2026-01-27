<?php
// salary-movements.php
session_start();
include '../config/db.php';
include '../includes/functions.php';
include '../includes/sidebar.php';

// Initialize tables and sample data
createCompensationTables($pdo);
insertSampleData($pdo);

// Get employee data for dropdowns
try {
    $employees = $pdo->query("SELECT id, employee_id, name, department, job_title, salary FROM employees WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
    error_log("Error fetching employees: " . $e->getMessage());
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_salary_movement'])) {
    addSalaryMovement($pdo, $_POST);
}

// Fetch data
try {
    $salary_movements = $pdo->query("SELECT * FROM salary_movements ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $salary_movements = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Movements | Compensation Planning</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            line-height: 1.4;
        }

        .container {
            padding: 1.5rem;
            margin-top: 60px;
        }

        .form-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .form-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e3e6f0;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
        }

        textarea.form-control {
            min-height: 100px;
            resize: vertical;
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
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2e59d9;
            transform: translateY(-1px);
        }

        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e3e6f0;
        }

        .table th {
            background: #f8f9fc;
            font-weight: 600;
            color: #6c757d;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .table tbody tr:hover {
            background: #f8f9fc;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            border-color: var(--success-color);
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border-color: var(--danger-color);
            color: #721c24;
        }

        .increase-details {
            padding: 0.75rem;
            background: #f8f9fc;
            border-radius: var(--border-radius);
            margin-top: 0.5rem;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Add Movement Form -->
        <div class="form-container">
            <div class="form-header">
                <h2 class="form-title">
                    <i class="fas fa-plus-circle"></i>
                    Request Salary Movement
                </h2>
            </div>
            <form method="POST" action="" id="salaryMovementForm">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Movement Type</label>
                        <select name="movement_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="Annual Review">Annual Review</option>
                            <option value="Merit Increase">Merit Increase</option>
                            <option value="Promotion">Promotion</option>
                            <option value="COLA">COLA Adjustment</option>
                            <option value="Step Movement">Step Movement</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employee</label>
                        <select name="employee_id" class="form-control" required id="employee-select">
                            <option value="">Select Employee</option>
                            <?php if (!empty($employees)): ?>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo $employee['id']; ?>"
                                        data-department="<?php echo htmlspecialchars($employee['department']); ?>"
                                        data-salary="<?php echo htmlspecialchars($employee['salary']); ?>"
                                        data-name="<?php echo htmlspecialchars($employee['name']); ?>">
                                        <?php echo htmlspecialchars($employee['name']); ?> (ID: <?php echo $employee['employee_id']; ?>) - <?php echo htmlspecialchars($employee['department']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" required id="department-input" readonly>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Previous Salary</label>
                        <input type="number" name="previous_salary" class="form-control" required step="0.01" id="previous-salary" placeholder="Current salary will auto-fill">
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Salary</label>
                        <input type="number" name="new_salary" class="form-control" required step="0.01" id="new-salary" placeholder="Enter proposed new salary">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Increase Details</label>
                        <div class="increase-details">
                            <div id="increase-amount">Increase: ₱0.00</div>
                            <div id="increase-percentage">Percentage: 0%</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Effective Date</label>
                        <input type="date" name="effective_date" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" required placeholder="Reason for salary movement..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Employee Name</label>
                    <input type="text" name="employee_name" class="form-control" required id="employee-name" readonly>
                </div>
                <button type="submit" name="add_salary_movement" class="btn btn-primary">
                    <i class="fas fa-paper-plane"></i> Submit Request
                </button>
            </form>
        </div>

        <!-- Movements Table -->
        <div class="form-container">
            <div class="form-header">
                <h2 class="form-title">
                    <i class="fas fa-table"></i>
                    Salary Movement History
                </h2>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Department</th>
                            <th>Previous Salary</th>
                            <th>New Salary</th>
                            <th>Increase</th>
                            <th>Status</th>
                            <th>Effective Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($salary_movements)): ?>
                            <?php foreach ($salary_movements as $movement): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($movement['employee_name']); ?></strong><br>
                                        <small>ID: <?php echo $movement['employee_id']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($movement['movement_type']); ?></td>
                                    <td><?php echo htmlspecialchars($movement['department']); ?></td>
                                    <td>₱<?php echo number_format($movement['previous_salary'], 2); ?></td>
                                    <td>₱<?php echo number_format($movement['new_salary'], 2); ?></td>
                                    <td>
                                        ₱<?php echo number_format($movement['increase_amount'], 2); ?><br>
                                        <small><?php echo $movement['increase_percentage']; ?>%</small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($movement['status']); ?>">
                                            <?php echo htmlspecialchars($movement['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($movement['effective_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 2rem; color: #6c757d;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem;"></i><br>
                                    No salary movements found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const employeeSelect = document.getElementById('employee-select');
            const departmentInput = document.getElementById('department-input');
            const previousSalaryInput = document.getElementById('previous-salary');
            const newSalaryInput = document.getElementById('new-salary');
            const employeeNameInput = document.getElementById('employee-name');
            const increaseAmountDiv = document.getElementById('increase-amount');
            const increasePercentageDiv = document.getElementById('increase-percentage');

            // Employee selection functionality
            if (employeeSelect && departmentInput && previousSalaryInput && employeeNameInput) {
                employeeSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        const department = selectedOption.getAttribute('data-department');
                        const salary = selectedOption.getAttribute('data-salary');
                        const name = selectedOption.getAttribute('data-name');

                        departmentInput.value = department || '';
                        previousSalaryInput.value = salary || '';
                        employeeNameInput.value = name || '';

                        // Trigger calculation if new salary is already entered
                        if (newSalaryInput.value) {
                            calculateIncrease();
                        }
                    } else {
                        departmentInput.value = '';
                        previousSalaryInput.value = '';
                        employeeNameInput.value = '';
                    }
                });
            }

            // Auto-calculate increase
            function calculateIncrease() {
                const prevSalary = parseFloat(previousSalaryInput.value) || 0;
                const newSalary = parseFloat(newSalaryInput.value) || 0;

                if (prevSalary > 0 && newSalary > 0) {
                    const increaseAmount = newSalary - prevSalary;
                    const increasePercentage = ((increaseAmount / prevSalary) * 100).toFixed(2);

                    increaseAmountDiv.textContent = `Increase: ₱${increaseAmount.toFixed(2)}`;
                    increasePercentageDiv.textContent = `Percentage: ${increasePercentage}%`;
                } else {
                    increaseAmountDiv.textContent = 'Increase: ₱0.00';
                    increasePercentageDiv.textContent = 'Percentage: 0%';
                }
            }

            if (previousSalaryInput && newSalaryInput) {
                previousSalaryInput.addEventListener('input', calculateIncrease);
                newSalaryInput.addEventListener('input', calculateIncrease);
            }
        });
    </script>
</body>

</html>
<?php ob_end_flush(); ?>