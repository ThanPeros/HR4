<?php
// bonus-and-movements.php - Bonus Structure and Salary Movements Management
include '../config/db.php';
include '../includes/sidebar.php';
include '../responsive/responsive.php';

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

$currentTheme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_bonus_structure'])) {
        addBonusStructure($pdo, $_POST);
    } elseif (isset($_POST['add_salary_movement'])) {
        addSalaryMovement($pdo, $_POST);
    }
}

// Database table creation functions
function createBonusAndMovementTables($pdo)
{
    $tables = [
        "bonus_structures" => "CREATE TABLE IF NOT EXISTS bonus_structures (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bonus_type ENUM('Performance','Attendance','Productivity','13th Month','Special','Other') NOT NULL,
            bonus_name VARCHAR(200) NOT NULL,
            calculation_method ENUM('Fixed Amount','Percentage','Tiered','Formula') NOT NULL,
            amount DECIMAL(10,2) DEFAULT NULL,
            percentage DECIMAL(5,2) DEFAULT NULL,
            formula TEXT,
            eligibility_criteria TEXT NOT NULL,
            payment_schedule VARCHAR(100) NOT NULL,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        "salary_movements" => "CREATE TABLE IF NOT EXISTS salary_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            movement_type ENUM('Annual Review','Merit Increase','Promotion','COLA','Step Movement','Other') NOT NULL,
            employee_id INT,
            employee_name VARCHAR(100),
            department VARCHAR(50),
            previous_salary DECIMAL(12,2),
            new_salary DECIMAL(12,2),
            increase_amount DECIMAL(12,2),
            increase_percentage DECIMAL(5,2),
            effective_date DATE NOT NULL,
            reason TEXT,
            approved_by VARCHAR(100),
            status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    ];

    foreach ($tables as $tableName => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Table might already exist
            error_log("Table creation error for $tableName: " . $e->getMessage());
        }
    }
}

// Initialize tables and insert sample data
function insertSampleBonusAndMovementData($pdo)
{
    // Insert sample bonus structures
    $sampleBonuses = [
        ['Performance', 'Q4 Performance Bonus', 'Percentage', null, 15.00, null, 'Active employees with rating >= 4.0', 'End of Q4'],
        ['Attendance', 'Perfect Attendance Incentive', 'Fixed Amount', 5000.00, null, null, 'No absences in the month', 'Monthly'],
        ['Productivity', 'Sales Target Achievement', 'Percentage', null, 5.00, null, 'Sales team exceeding targets', 'Monthly'],
        ['13th Month', '13th Month Pay', 'Percentage', null, 8.33, null, 'All regular employees', 'December'],
        ['Special', 'Company Anniversary Bonus', 'Fixed Amount', 3000.00, null, null, 'All active employees', 'Anniversary Date']
    ];

    foreach ($sampleBonuses as $bonus) {
        try {
            $checkSql = "SELECT COUNT(*) FROM bonus_structures WHERE bonus_name = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$bonus[1]]);
            $exists = $checkStmt->fetchColumn();

            if (!$exists) {
                $sql = "INSERT INTO bonus_structures (bonus_type, bonus_name, calculation_method, amount, percentage, formula, eligibility_criteria, payment_schedule) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bonus);
            }
        } catch (PDOException $e) {
            error_log("Error inserting sample bonus data: " . $e->getMessage());
        }
    }

    // Insert sample salary movements
    $movements = [
        ['Annual Review', 101, 'Juan Dela Cruz', 'IT', 45000.00, 48000.00, 3000.00, 6.67, '2024-01-15', 'Annual performance-based increase', 'Pending'],
        ['Promotion', 102, 'Maria Santos', 'HR', 38000.00, 45000.00, 7000.00, 18.42, '2024-02-01', 'Promotion to Senior HR Specialist', 'Approved'],
        ['Merit Increase', 103, 'Pedro Reyes', 'Finance', 52000.00, 55000.00, 3000.00, 5.77, '2024-01-20', 'Exceptional performance rating', 'Pending'],
        ['COLA', 104, 'Anna Lopez', 'Marketing', 42000.00, 43680.00, 1680.00, 4.00, '2024-03-01', 'Cost of Living Adjustment', 'Approved']
    ];

    foreach ($movements as $movement) {
        try {
            $checkSql = "SELECT COUNT(*) FROM salary_movements WHERE employee_id = ? AND movement_type = ? AND effective_date = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$movement[1], $movement[0], $movement[8]]);
            $exists = $checkStmt->fetchColumn();

            if (!$exists) {
                $sql = "INSERT INTO salary_movements (movement_type, employee_id, employee_name, department, previous_salary, new_salary, increase_amount, increase_percentage, effective_date, reason, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($movement);
            }
        } catch (PDOException $e) {
            error_log("Error inserting sample movement data: " . $e->getMessage());
        }
    }
}

function addBonusStructure($pdo, $data)
{
    try {
        $sql = "INSERT INTO bonus_structures (bonus_type, bonus_name, calculation_method, amount, percentage, formula, eligibility_criteria, payment_schedule, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['bonus_type'],
            $data['bonus_name'],
            $data['calculation_method'],
            $data['amount'] ?: null,
            $data['percentage'] ?: null,
            $data['formula'] ?: null,
            $data['eligibility_criteria'],
            $data['payment_schedule']
        ]);

        $_SESSION['success_message'] = "Bonus structure added successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error adding bonus structure: " . $e->getMessage();
    }
}

function addSalaryMovement($pdo, $data)
{
    try {
        $increase_amount = $data['new_salary'] - $data['previous_salary'];
        $increase_percentage = ($increase_amount / $data['previous_salary']) * 100;

        $sql = "INSERT INTO salary_movements (movement_type, employee_id, employee_name, department, previous_salary, new_salary, increase_amount, increase_percentage, effective_date, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['movement_type'],
            $data['employee_id'],
            $data['employee_name'],
            $data['department'],
            $data['previous_salary'],
            $data['new_salary'],
            $increase_amount,
            round($increase_percentage, 2),
            $data['effective_date'],
            $data['reason'],
            'Pending'
        ]);

        $_SESSION['success_message'] = "Salary movement request submitted successfully!";
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error submitting salary movement: " . $e->getMessage();
    }
}

// Initialize tables
createBonusAndMovementTables($pdo);
insertSampleBonusAndMovementData($pdo);

// Fetch data
try {
    $bonus_structures = $pdo->query("SELECT * FROM bonus_structures ORDER BY bonus_type, bonus_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bonus_structures = [];
}

try {
    $salary_movements = $pdo->query("SELECT * FROM salary_movements ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $salary_movements = [];
}

// Get employee data for dropdowns
try {
    $employees = $pdo->query("SELECT id, employee_id, name, department, job_title, salary FROM employees WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $employees = [];
    error_log("Error fetching employees: " . $e->getMessage());
}

// Get statistics
try {
    $stats = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM bonus_structures WHERE status = 'Active') as active_bonuses,
            (SELECT COUNT(*) FROM salary_movements WHERE status = 'Pending') as pending_movements,
            (SELECT COUNT(*) FROM salary_movements WHERE status = 'Approved') as approved_movements,
            (SELECT AVG(increase_percentage) FROM salary_movements WHERE status = 'Approved') as avg_increase
        FROM dual
    ")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['active_bonuses' => 0, 'pending_movements' => 0, 'approved_movements' => 0, 'avg_increase' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bonus & Salary Movements | Compensation Planning</title>
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
            --purple-color: #6f42c1;
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

        .main-content {
            margin-left: 250px;
            margin-top: 60px;
            padding: 1.5rem;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }

        .page-header {
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        body.dark-mode .page-title {
            color: var(--text-light);
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 1rem;
            margin-top: 0.5rem;
        }

        body.dark-mode .page-subtitle {
            color: #a0aec0;
        }

        .nav-container {
            margin-bottom: 2rem;
        }

        .nav-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .nav-link {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }

        .nav-link:hover {
            text-decoration: underline;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #d4edda;
            border-color: var(--success-color);
            color: #155724;
        }

        body.dark-mode .alert-success {
            background: #1a472a;
            color: #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            border-color: var(--danger-color);
            color: #721c24;
        }

        body.dark-mode .alert-error {
            background: #744210;
            color: #fbd38d;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary-color);
            transition: transform 0.3s;
        }

        body.dark-mode .stat-card {
            background: var(--dark-card);
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card.success {
            border-left-color: var(--success-color);
        }

        .stat-card.warning {
            border-left-color: var(--warning-color);
        }

        .stat-card.info {
            border-left-color: var(--info-color);
        }

        .stat-card.purple {
            border-left-color: var(--purple-color);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        body.dark-mode .stat-value {
            color: var(--text-light);
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        body.dark-mode .stat-label {
            color: #a0aec0;
        }

        /* Tabs */
        .tabs-container {
            padding: 0 1.5rem;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid #e3e6f0;
            background: white;
        }

        body.dark-mode .tabs {
            background: var(--dark-card);
            border-bottom-color: #4a5568;
        }

        .tab {
            padding: 1rem 1.5rem;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            font-weight: 600;
            color: #6c757d;
        }

        body.dark-mode .tab {
            color: #a0aec0;
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: #f8f9fc;
        }

        body.dark-mode .tab.active {
            background: #2d3748;
        }

        .tab:hover {
            background: #f8f9fc;
            color: var(--primary-color);
        }

        body.dark-mode .tab:hover {
            background: #2d3748;
        }

        .tab-content {
            display: none;
            padding: 1.5rem;
            background: white;
        }

        body.dark-mode .tab-content {
            background: var(--dark-card);
        }

        .tab-content.active {
            display: block;
        }

        /* Forms */
        .form-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        body.dark-mode .form-container {
            background: var(--dark-card);
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

        /* Tables */
        .table-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        body.dark-mode .table-container {
            background: var(--dark-card);
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

        body.dark-mode .table th,
        body.dark-mode .table td {
            border-bottom-color: #4a5568;
        }

        .table th {
            background: #f8f9fc;
            font-weight: 600;
            color: #6c757d;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        body.dark-mode .table th {
            background: #2d3748;
            color: #a0aec0;
        }

        .table tbody tr:hover {
            background: #f8f9fc;
        }

        body.dark-mode .table tbody tr:hover {
            background: #2d3748;
        }

        /* Form Elements */
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
            transition: border-color 0.3s;
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

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
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
            display: inline-flex;
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

        .btn-success:hover {
            background: #13855c;
            transform: translateY(-1px);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-active {
            background: #1a472a;
            color: #c3e6cb;
        }

        body.dark-mode .status-inactive {
            background: #744210;
            color: #f5c6cb;
        }

        body.dark-mode .status-pending {
            background: #5d4a1f;
            color: #ffeaa7;
        }

        body.dark-mode .status-approved {
            background: #1a3a40;
            color: #bee5eb;
        }

        body.dark-mode .status-rejected {
            background: #744210;
            color: #f5c6cb;
        }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-gift"></i>
                    Bonus & Salary Movements
                </h1>
                <p class="page-subtitle">Manage bonus structures and salary movement requests</p>
            </div>

            <!-- Navigation -->
            <div class="nav-container">
                <nav class="nav-breadcrumb">
                    <a href="../hr-dashboard/index.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Bonus & Movements</span>
                </nav>
            </div>

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

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['active_bonuses'] ?? 0; ?></div>
                    <div class="stat-label">Active Bonuses</div>
                    <i class="fas fa-gift" style="float: right; color: #4e73df; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo $stats['pending_movements'] ?? 0; ?></div>
                    <div class="stat-label">Pending Movements</div>
                    <i class="fas fa-clock" style="float: right; color: #f6c23e; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $stats['approved_movements'] ?? 0; ?></div>
                    <div class="stat-label">Approved Movements</div>
                    <i class="fas fa-check-circle" style="float: right; color: #1cc88a; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($stats['avg_increase'] ?? 0, 1); ?>%</div>
                    <div class="stat-label">Avg Increase</div>
                    <i class="fas fa-chart-line" style="float: right; color: #36b9cc; font-size: 1.5rem;"></i>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs-container">
                <div class="tabs">
                    <div class="tab active" data-tab="bonus-structure">Bonus Structure</div>
                    <div class="tab" data-tab="salary-movements">Salary Movements</div>
                </div>

                <!-- Bonus Structure Tab -->
                <div class="tab-content active" id="bonus-structure">
                    <!-- Add Bonus Structure Form -->
                    <div class="form-container">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-plus-circle"></i>
                                Add New Bonus Structure
                            </h2>
                        </div>
                        <form method="POST" action="">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Bonus Type</label>
                                    <select name="bonus_type" class="form-control" required>
                                        <option value="">Select Type</option>
                                        <option value="Performance">Performance</option>
                                        <option value="Attendance">Attendance</option>
                                        <option value="Productivity">Productivity</option>
                                        <option value="13th Month">13th Month</option>
                                        <option value="Special">Special</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Bonus Name</label>
                                    <input type="text" name="bonus_name" class="form-control" required placeholder="e.g., Q4 Performance Bonus">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Calculation Method</label>
                                    <select name="calculation_method" class="form-control" required id="calculation-method">
                                        <option value="">Select Method</option>
                                        <option value="Fixed Amount">Fixed Amount</option>
                                        <option value="Percentage">Percentage</option>
                                        <option value="Tiered">Tiered</option>
                                        <option value="Formula">Formula</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row" id="amount-fields">
                                <div class="form-group">
                                    <label class="form-label">Amount (₱)</label>
                                    <input type="number" name="amount" class="form-control" step="0.01" placeholder="0.00">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Percentage (%)</label>
                                    <input type="number" name="percentage" class="form-control" step="0.01" placeholder="0.00">
                                </div>
                            </div>
                            <div class="form-group" id="formula-field" style="display: none;">
                                <label class="form-label">Formula</label>
                                <textarea name="formula" class="form-control" placeholder="Enter calculation formula..."></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Eligibility Criteria</label>
                                    <textarea name="eligibility_criteria" class="form-control" required placeholder="Who qualifies for this bonus..."></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Payment Schedule</label>
                                    <input type="text" name="payment_schedule" class="form-control" required placeholder="e.g., Monthly, Quarterly, Annually">
                                </div>
                            </div>
                            <button type="submit" name="add_bonus_structure" class="btn btn-primary">
                                <i class="fas fa-save"></i> Add Bonus Structure
                            </button>
                        </form>
                    </div>

                    <!-- Bonus Structures Table -->
                    <div class="form-container">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-table"></i>
                                Bonus Structures
                            </h2>
                        </div>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Name</th>
                                        <th>Calculation</th>
                                        <th>Eligibility</th>
                                        <th>Schedule</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($bonus_structures)): ?>
                                        <?php foreach ($bonus_structures as $bonus): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($bonus['bonus_type']); ?></td>
                                                <td><?php echo htmlspecialchars($bonus['bonus_name']); ?></td>
                                                <td>
                                                    <?php
                                                    if ($bonus['calculation_method'] === 'Fixed Amount') {
                                                        echo '₱' . number_format($bonus['amount'], 2);
                                                    } elseif ($bonus['calculation_method'] === 'Percentage') {
                                                        echo $bonus['percentage'] . '%';
                                                    } elseif ($bonus['calculation_method'] === 'Formula') {
                                                        echo htmlspecialchars(substr($bonus['formula'], 0, 20)) . '...';
                                                    } else {
                                                        echo htmlspecialchars($bonus['calculation_method']);
                                                    }
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars(substr($bonus['eligibility_criteria'], 0, 30)) . '...'; ?></td>
                                                <td><?php echo htmlspecialchars($bonus['payment_schedule']); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($bonus['status']); ?>">
                                                        <?php echo htmlspecialchars($bonus['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 2rem; color: #6c757d;">
                                                <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem;"></i><br>
                                                No bonus structures found.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Salary Movements Tab -->
                <div class="tab-content" id="salary-movements">
                    <!-- Add Movement Form -->
                    <div class="form-container">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-plus-circle"></i>
                                Request Salary Movement
                            </h2>
                        </div>
                        <form method="POST" action="">
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
                                <div class="form-group">
                                    <label class="form-label">Department</label>
                                    <input type="text" name="department" class="form-control" required id="department-input" readonly>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Previous Salary</label>
                                    <input type="number" name="previous_salary" class="form-control" required step="0.01" id="previous-salary" placeholder="Current salary will auto-fill">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">New Salary</label>
                                    <input type="number" name="new_salary" class="form-control" required step="0.01" id="new-salary" placeholder="Enter proposed new salary">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Increase Details</label>
                                    <div style="padding: 0.75rem; background: #f8f9fc; border-radius: var(--border-radius);">
                                        <div id="increase-amount">Increase: ₱0.00</div>
                                        <div id="increase-percentage">Percentage: 0%</div>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reason</label>
                                <textarea name="reason" class="form-control" required placeholder="Reason for salary movement..."></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Effective Date</label>
                                <input type="date" name="effective_date" class="form-control" required>
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
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');

                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));

                    // Add active class to current tab and content
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });

            // Calculation method toggle
            const calculationMethod = document.getElementById('calculation-method');
            const amountFields = document.getElementById('amount-fields');
            const formulaField = document.getElementById('formula-field');

            if (calculationMethod) {
                calculationMethod.addEventListener('change', function() {
                    if (this.value === 'Formula') {
                        amountFields.style.display = 'none';
                        formulaField.style.display = 'block';
                    } else {
                        amountFields.style.display = 'grid';
                        formulaField.style.display = 'none';
                    }
                });
            }

            // Auto-calculate increase amount and percentage
            const prevSalaryInput = document.querySelector('input[name="previous_salary"]');
            const newSalaryInput = document.querySelector('input[name="new_salary"]');
            const increaseAmountDiv = document.getElementById('increase-amount');
            const increasePercentageDiv = document.getElementById('increase-percentage');

            function calculateIncrease() {
                const prevSalary = parseFloat(prevSalaryInput.value) || 0;
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

            if (prevSalaryInput && newSalaryInput) {
                prevSalaryInput.addEventListener('input', calculateIncrease);
                newSalaryInput.addEventListener('input', calculateIncrease);
            }

            // Employee selection functionality
            const employeeSelect = document.getElementById('employee-select');
            const departmentInput = document.getElementById('department-input');
            const previousSalaryInput = document.getElementById('previous-salary');

            if (employeeSelect && departmentInput && previousSalaryInput) {
                employeeSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if (selectedOption.value) {
                        const department = selectedOption.getAttribute('data-department');
                        const salary = selectedOption.getAttribute('data-salary');
                        const name = selectedOption.getAttribute('data-name');

                        // Set the department based on selected employee
                        departmentInput.value = department || '';

                        // Auto-fill the previous salary with current salary
                        previousSalaryInput.value = salary || '';

                        // Trigger calculation if new salary is already entered
                        if (newSalaryInput.value) {
                            calculateIncrease();
                        }
                    } else {
                        departmentInput.value = '';
                        previousSalaryInput.value = '';
                    }
                });
            }
        });
    </script>
</body>

</html>
<?php ob_end_flush(); ?>