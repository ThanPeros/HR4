<?php
// bonus-structure.php - Bonus Structure Management
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
    }
}

// Database table creation functions
function createBonusTables($pdo)
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
function insertSampleBonusData($pdo)
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

// Initialize tables
createBonusTables($pdo);
insertSampleBonusData($pdo);

// Fetch data
try {
    $bonus_structures = $pdo->query("SELECT * FROM bonus_structures ORDER BY bonus_type, bonus_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bonus_structures = [];
}

// Get statistics
try {
    $stats = $pdo->query("
        SELECT
            COUNT(*) as total_bonuses,
            (SELECT COUNT(*) FROM bonus_structures WHERE status = 'Active') as active_bonuses,
            (SELECT COUNT(*) FROM bonus_structures WHERE bonus_type = 'Performance') as performance_bonuses,
            (SELECT COUNT(*) FROM bonus_structures WHERE bonus_type = '13th Month') as monthly_bonuses
        FROM bonus_structures
    ")->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = ['total_bonuses' => 0, 'active_bonuses' => 0, 'performance_bonuses' => 0, 'monthly_bonuses' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bonus Structure | Compensation Planning</title>
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
            --success-color: #1cc88a;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --info-color: #36b9cc;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            line-height: 1.4;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            width: 100%;
            margin-top: 60px;
        }

        body.dark-mode .main-content {
            background-color: var(--dark-bg);
        }

        .content-area {
            width: 100%;
            min-height: 100%;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 2rem;
        }

        body.dark-mode .content-area {
            background: var(--dark-card);
            color: var(--text-light);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .page-header {
            border-bottom: 1px solid #4a5568;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-subtitle {
            font-size: 0.9rem;
            color: #6c757d;
        }

        body.dark-mode .page-subtitle {
            color: #adb5bd;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary-color);
        }

        body.dark-mode .stat-card {
            background: var(--dark-card);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }

        body.dark-mode .stat-label {
            color: #adb5bd;
        }

        .form-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
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

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
        }

        body.dark-mode .form-label {
            color: var(--text-light);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d3e2;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }

        body.dark-mode .form-control {
            background-color: #495057;
            border-color: #6c757d;
            color: var(--text-light);
        }

        body.dark-mode .form-control:focus {
            border-color: var(--primary-color);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
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
            background: #3a57c4;
            transform: translateY(-1px);
        }

        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .table th,
        .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e3e6f0;
        }

        .table th {
            background-color: #f8f9fc;
            font-weight: 600;
            color: var(--text-dark);
        }

        body.dark-mode .table th {
            background-color: #495057;
            color: var(--text-light);
        }

        body.dark-mode .table td {
            border-bottom: 1px solid #4a5568;
            color: var(--text-light);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-active {
            background-color: #1e3a2e;
            color: #d4edda;
        }

        body.dark-mode .status-inactive {
            background-color: #3a1e1e;
            color: #f8d7da;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
                margin-top: 60px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <!-- Main Content -->
    <div class="main-content">
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-gift"></i>
                        Bonus Structure Management
                    </h1>
                    <p class="page-subtitle">Configure and manage bonus structures and incentives</p>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                    <?php unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_bonuses']; ?></div>
                    <div class="stat-label">Total Bonus Structures</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['active_bonuses']; ?></div>
                    <div class="stat-label">Active Bonuses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['performance_bonuses']; ?></div>
                    <div class="stat-label">Performance Bonuses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['monthly_bonuses']; ?></div>
                    <div class="stat-label">Monthly Bonuses</div>
                </div>
            </div>

            <!-- Add Bonus Form -->
            <div class="form-container">
                <div class="form-header">
                    <h2 class="form-title">
                        <i class="fas fa-plus-circle"></i>
                        Add Bonus Structure
                    </h2>
                </div>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Bonus Type</label>
                            <select name="bonus_type" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="Performance">Performance Bonus</option>
                                <option value="Attendance">Attendance Incentive</option>
                                <option value="Productivity">Productivity Incentive</option>
                                <option value="13th Month">13th Month Pay</option>
                                <option value="Special">Special Bonus</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Bonus Name</label>
                            <input type="text" name="bonus_name" class="form-control" required placeholder="e.g., Q4 Performance Bonus">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Calculation Method</label>
                            <select name="calculation_method" class="form-control" required>
                                <option value="Fixed Amount">Fixed Amount</option>
                                <option value="Percentage">Percentage</option>
                                <option value="Tiered">Tiered</option>
                                <option value="Formula">Formula</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" class="form-control" step="0.01" placeholder="For fixed amount">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Percentage</label>
                            <input type="number" name="percentage" class="form-control" step="0.01" placeholder="For percentage calculation">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Payment Schedule</label>
                            <input type="text" name="payment_schedule" class="form-control" required placeholder="e.g., End of Year">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Eligibility Criteria</label>
                        <textarea name="eligibility_criteria" class="form-control" required placeholder="Who qualifies for this bonus..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Formula (if applicable)</label>
                        <textarea name="formula" class="form-control" placeholder="Calculation formula..."></textarea>
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
                                <th>Bonus Type</th>
                                <th>Bonus Name</th>
                                <th>Calculation Method</th>
                                <th>Amount/Percentage</th>
                                <th>Payment Schedule</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($bonus_structures)): ?>
                                <?php foreach ($bonus_structures as $bonus): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($bonus['bonus_type']); ?></td>
                                        <td><?php echo htmlspecialchars($bonus['bonus_name']); ?></td>
                                        <td><?php echo htmlspecialchars($bonus['calculation_method']); ?></td>
                                        <td>
                                            <?php
                                            if ($bonus['calculation_method'] === 'Percentage') {
                                                echo $bonus['percentage'] . '%';
                                            } else if ($bonus['calculation_method'] === 'Fixed Amount') {
                                                echo '₱' . number_format($bonus['amount'], 2);
                                            } else {
                                                echo 'Custom';
                                            }
                                            ?>
                                        </td>
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
    </div>

</body>

</html>
<?php ob_end_flush(); ?>