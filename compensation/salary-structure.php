<?php
// compensation-planning.php - Compensation Planning System
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
    if (isset($_POST['add_salary_grade'])) {
        addSalaryGrade($pdo, $_POST);
    } elseif (isset($_POST['update_salary_grade'])) {
        updateSalaryGrade($pdo, $_POST);
    }
}

// Database table creation functions
function createCompensationTables($pdo)
{
    $tables = [
        "salary_grades" => "CREATE TABLE IF NOT EXISTS salary_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grade_level VARCHAR(10) NOT NULL UNIQUE,
            grade_name VARCHAR(100) NOT NULL,
            min_salary DECIMAL(12,2) NOT NULL,
            mid_salary DECIMAL(12,2) NOT NULL,
            max_salary DECIMAL(12,2) NOT NULL,
            step_count INT DEFAULT 5,
            description TEXT,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        "position_grade_mapping" => "CREATE TABLE IF NOT EXISTS position_grade_mapping (
            id INT AUTO_INCREMENT PRIMARY KEY,
            position_title VARCHAR(100) NOT NULL,
            department VARCHAR(50) NOT NULL,
            salary_grade VARCHAR(10) NOT NULL,
            step_level INT DEFAULT 1,
            effective_date DATE NOT NULL,
            status ENUM('Active','Inactive') DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    ];

    foreach ($tables as $tableName => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Table might already exist
        }
    }
}

// Initialize tables and insert sample data
createCompensationTables($pdo);
insertSampleData($pdo);

function insertSampleData($pdo)
{
    // Insert sample salary grades
    $salaryGrades = [
        ['SG-1', 'Entry Level', 15000.00, 18000.00, 22000.00, 5, 'Fresh graduates and entry-level positions with 0-2 years experience'],
        ['SG-2', 'Junior Associate', 18000.00, 22000.00, 27000.00, 5, 'Junior roles with 2-3 years of relevant experience'],
        ['SG-3', 'Associate', 22000.00, 28000.00, 35000.00, 5, 'Mid-level professionals with 3-5 years experience'],
        ['SG-4', 'Senior Associate', 28000.00, 35000.00, 45000.00, 5, 'Experienced professionals with 5-7 years in specialized roles'],
        ['SG-5', 'Team Lead', 35000.00, 45000.00, 60000.00, 5, 'Team leadership roles with supervisory responsibilities'],
        ['SG-6', 'Manager', 45000.00, 60000.00, 80000.00, 5, 'Management positions with department oversight'],
        ['SG-7', 'Senior Manager', 60000.00, 80000.00, 100000.00, 5, 'Senior management with multiple team oversight'],
        ['SG-8', 'Director', 80000.00, 100000.00, 130000.00, 5, 'Executive leadership with strategic planning responsibilities']
    ];

    foreach ($salaryGrades as $grade) {
        $checkSql = "SELECT COUNT(*) FROM salary_grades WHERE grade_level = ?";
        $stmt = $pdo->prepare($checkSql);
        $stmt->execute([$grade[0]]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            $sql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($grade);
        }
    }
}

// Function implementations
function addSalaryGrade($pdo, $data)
{
    try {
        $sql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['grade_level'],
            $data['grade_name'],
            $data['min_salary'],
            $data['mid_salary'],
            $data['max_salary'],
            $data['step_count'],
            $data['description'],
            'Active'
        ]);
        $_SESSION['success_message'] = "Salary grade added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding salary grade: " . $e->getMessage();
    }
}

function updateSalaryGrade($pdo, $data)
{
    try {
        $sql = "UPDATE salary_grades SET grade_name = ?, min_salary = ?, mid_salary = ?, max_salary = ?, step_count = ?, description = ?, status = ? WHERE grade_level = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['grade_name'],
            $data['min_salary'],
            $data['mid_salary'],
            $data['max_salary'],
            $data['step_count'],
            $data['description'],
            $data['status'],
            $data['grade_level']
        ]);
        $_SESSION['success_message'] = "Salary grade updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating salary grade: " . $e->getMessage();
    }
}

// Fetch data for display
try {
    $salary_grades = $pdo->query("SELECT * FROM salary_grades ORDER BY grade_level")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $salary_grades = [];
}



// Get statistics
try {
    $stats_stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM salary_grades WHERE status = 'Active') as active_grades,
            (SELECT AVG(mid_salary) FROM salary_grades WHERE status = 'Active') as avg_mid_salary
    ");
    $compensation_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $compensation_stats = [
        'active_grades' => 0,
        'avg_mid_salary' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Salary Structure | HR System</title>
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

        .theme-toggle-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .theme-toggle-btn {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 20px;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            box-shadow: var(--shadow);
        }

        body.dark-mode .theme-toggle-btn {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }

        .theme-toggle-btn:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }

        body.dark-mode .theme-toggle-btn:hover {
            background: #4a5568;
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
            overflow: hidden;
        }

        body.dark-mode .content-area {
            background: var(--dark-card);
        }

        .page-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        body.dark-mode .page-header {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
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
        }

        body.dark-mode .page-subtitle {
            color: #a0aec0;
        }

        .nav-container {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        body.dark-mode .nav-container {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
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
            transition: color 0.3s;
        }

        .nav-link:hover {
            color: #2e59d9;
        }

        /* Stats Cards */
        .stats-container {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }

        body.dark-mode .stat-card {
            background: var(--dark-card);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }

        .stat-card.warning {
            border-left-color: var(--warning-color);
        }

        .stat-card.success {
            border-left-color: var(--success-color);
        }

        .stat-card.info {
            border-left-color: var(--info-color);
        }

        .stat-card.purple {
            border-left-color: var(--purple-color);
        }

        .stat-card.danger {
            border-left-color: var(--danger-color);
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
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

        /* Buttons */
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
            font-size: 0.9rem;
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
            background: #17a673;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc3545;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background: #2c9faf;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1rem 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            border-color: var(--success-color);
            color: #155724;
        }

        body.dark-mode .alert-success {
            background: #22543d;
            color: #9ae6b4;
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

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        body.dark-mode .status-active {
            background: #22543d;
            color: #9ae6b4;
        }

        body.dark-mode .status-inactive {
            background: #744210;
            color: #fbd38d;
        }

        body.dark-mode .status-pending {
            background: #744210;
            color: #fbd38d;
        }

        @media(max-width:768px) {
            .main-content {
                padding: 1rem;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width:480px) {
            .main-content {
                padding: 0.8rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
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
                <h1 class="page-title">
                    <i class="fas fa-layer-group"></i>
                    Salary Structure
                </h1>
                <p class="page-subtitle">Manage salary grades and compensation structure</p>
            </div>

            <!-- Navigation -->
            <div class="nav-container">
                <nav class="nav-breadcrumb">
                    <a href="../hr-dashboard/index.php" class="nav-link">
                        <i class="fas fa-home"></i>
                        Dashboard
                    </a>
                    <i class="fas fa-chevron-right"></i>
                    <span>Compensation Planning</span>
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
                    <div class="stat-value"><?php echo $compensation_stats['active_grades'] ?? 0; ?></div>
                    <div class="stat-label">Salary Grades</div>
                    <i class="fas fa-layer-group" style="float: right; color: #4e73df; font-size: 1.5rem;"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value">₱<?php echo number_format($compensation_stats['avg_mid_salary'] ?? 0, 0); ?></div>
                    <div class="stat-label">Average Mid Salary</div>
                    <i class="fas fa-chart-line" style="float: right; color: #1cc88a; font-size: 1.5rem;"></i>
                </div>
            </div>

            <!-- Tabs -->


            <!-- Salary Structure Tab -->
            <div class="tab-content active" id="salary-structure">
                <!-- Add Salary Grade Form -->
                <div class="form-container">
                    <div class="form-header">
                        <h2 class="form-title">
                            <i class="fas fa-plus-circle"></i>
                            Add New Salary Grade
                        </h2>
                    </div>
                    <form method="POST" action="">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Grade Level</label>
                                <input type="text" name="grade_level" class="form-control" required placeholder="e.g., SG-1">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Grade Name</label>
                                <input type="text" name="grade_name" class="form-control" required placeholder="e.g., Entry Level">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Step Count</label>
                                <input type="number" name="step_count" class="form-control" value="5" min="1" max="10">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Minimum Salary</label>
                                <input type="number" name="min_salary" class="form-control" required step="0.01" placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Mid Salary</label>
                                <input type="number" name="mid_salary" class="form-control" required step="0.01" placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Maximum Salary</label>
                                <input type="number" name="max_salary" class="form-control" required step="0.01" placeholder="0.00">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" placeholder="Grade description and typical positions..."></textarea>
                        </div>
                        <button type="submit" name="add_salary_grade" class="btn btn-primary">
                            <i class="fas fa-save"></i> Add Salary Grade
                        </button>
                    </form>
                </div>

                <!-- Salary Grades Table -->
                <div class="form-container">
                    <div class="form-header">
                        <h2 class="form-title">
                            <i class="fas fa-table"></i>
                            Salary Grades Structure
                        </h2>
                    </div>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Grade Level</th>
                                    <th>Grade Name</th>
                                    <th>Min Salary</th>
                                    <th>Mid Salary</th>
                                    <th>Max Salary</th>
                                    <th>Steps</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($salary_grades)): ?>
                                    <?php foreach ($salary_grades as $grade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($grade['grade_level']); ?></td>
                                            <td><?php echo htmlspecialchars($grade['grade_name']); ?></td>
                                            <td>₱<?php echo number_format($grade['min_salary'], 2); ?></td>
                                            <td>₱<?php echo number_format($grade['mid_salary'], 2); ?></td>
                                            <td>₱<?php echo number_format($grade['max_salary'], 2); ?></td>
                                            <td><?php echo $grade['step_count']; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($grade['status']); ?>">
                                                    <?php echo htmlspecialchars($grade['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 2rem; color: #6c757d;">
                                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem;"></i><br>
                                            No salary grades found.
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
        // No additional JavaScript needed for salary structure only
    </script>
</body>

</html>
<?php ob_end_flush(); ?>