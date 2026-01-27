<?php
// allowance-matrix.php
session_start();
include '../config/db.php';
include '../includes/functions.php';
include '../includes/sidebar.php';

// Initialize tables and sample data
createCompensationTables($pdo);
insertSampleData($pdo);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_allowance'])) {
    addAllowance($pdo, $_POST);
}

// Fetch data
try {
    $allowances = $pdo->query("SELECT * FROM allowance_matrix ORDER BY allowance_type, allowance_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $allowances = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allowance Matrix | Compensation Planning</title>
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

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
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

        <!-- Add Allowance Form -->
        <div class="form-container">
            <div class="form-header">
                <h2 class="form-title">
                    <i class="fas fa-plus-circle"></i>
                    Add New Allowance
                </h2>
            </div>
            <form method="POST" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Allowance Type</label>
                        <select name="allowance_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="Transportation">Transportation</option>
                            <option value="Meal">Meal</option>
                            <option value="Communication">Communication</option>
                            <option value="Housing">Housing</option>
                            <option value="Position">Position</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Allowance Name</label>
                        <input type="text" name="allowance_name" class="form-control" required placeholder="e.g., Transportation Allowance">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Amount Type</label>
                        <select name="amount_type" class="form-control" required>
                            <option value="Fixed">Fixed Amount</option>
                            <option value="Percentage">Percentage</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Amount</label>
                        <input type="number" name="amount" class="form-control" required step="0.01" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" placeholder="Leave blank for all departments">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Employment Type</label>
                        <select name="employment_type" class="form-control">
                            <option value="All">All Types</option>
                            <option value="Regular">Regular</option>
                            <option value="Contract">Contract</option>
                            <option value="Probationary">Probationary</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Eligibility Criteria</label>
                    <textarea name="eligibility_criteria" class="form-control" placeholder="Specific criteria for this allowance..."></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Effective Date</label>
                    <input type="date" name="effective_date" class="form-control" required>
                </div>
                <button type="submit" name="add_allowance" class="btn btn-primary">
                    <i class="fas fa-save"></i> Add Allowance
                </button>
            </form>
        </div>

        <!-- Allowances Table -->
        <div class="form-container">
            <div class="form-header">
                <h2 class="form-title">
                    <i class="fas fa-table"></i>
                    Allowance Matrix
                </h2>
            </div>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Amount</th>
                            <th>Department</th>
                            <th>Employment Type</th>
                            <th>Effective Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($allowances)): ?>
                            <?php foreach ($allowances as $allowance): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($allowance['allowance_type']); ?></td>
                                    <td><?php echo htmlspecialchars($allowance['allowance_name']); ?></td>
                                    <td>
                                        <?php
                                        if ($allowance['amount_type'] === 'Percentage') {
                                            echo $allowance['amount'] . '%';
                                        } else {
                                            echo '₱' . number_format($allowance['amount'], 2);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo $allowance['department'] ? htmlspecialchars($allowance['department']) : 'All'; ?></td>
                                    <td><?php echo htmlspecialchars($allowance['employment_type']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($allowance['effective_date'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($allowance['status']); ?>">
                                            <?php echo htmlspecialchars($allowance['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 2rem; color: #6c757d;">
                                    <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem;"></i><br>
                                    No allowances found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>
<?php ob_end_flush(); ?>