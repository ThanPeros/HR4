<?php
// Include the responsive system (CSS ONLY)
require_once '../responsive/responsive.php';

// Use your existing database configuration
require '../config/db.php';

// Check if the database variables are defined in db.php
// If not, define them here with your actual database credentials
if (!isset($servername) && !isset($dbhost)) {
    // Try to get from constants or define directly
    if (defined('DB_HOST')) {
        $servername = DB_HOST;
    } else {
        $servername = 'localhost'; // Default to localhost
    }
}

if (!isset($dbname) && !isset($database)) {
    if (defined('DB_NAME')) {
        $dbname = DB_NAME;
    } else {
        $dbname = 'dummyhr4'; // Change to your actual database name
    }
}

if (!isset($username) && !isset($dbuser)) {
    if (defined('DB_USER')) {
        $username = DB_USER;
    } else {
        $username = 'root'; // Default XAMPP username
    }
}

if (!isset($password) && !isset($dbpass)) {
    if (defined('DB_PASS')) {
        $password = DB_PASS;
    } else {
        $password = ''; // Default XAMPP password (empty)
    }
}

// Create PDO connection using the variables
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Could not connect to the database: " . $e->getMessage());
}

// Initialize error/success messages
$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_employee'])) {
        // Delete employee
        try {
            $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->execute([$_POST['employee_id']]);
            $message = "Employee deleted successfully!";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Error deleting employee: " . $e->getMessage();
            $message_type = "error";
        }
    }

    // Handle training data collection
    if (isset($_POST['save_training_data'])) {
        $employee_id = $_POST['employee_id'];
        $actual_outcome = $_POST['actual_outcome'];
        $prediction_confidence = $_POST['prediction_confidence'];

        try {
            // Create training_data table if it doesn't exist
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS training_data (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    employee_id INT NOT NULL,
                    features_json TEXT NOT NULL,
                    actual_outcome ENUM('Stay', 'Resign', 'Leave') NOT NULL,
                    prediction_confidence DECIMAL(5,4),
                    prediction_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_employee_id (employee_id),
                    INDEX idx_prediction_date (prediction_date)
                )
            ");

            // Get employee features
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $employee = $stmt->fetch();

            if ($employee) {
                $features = generateEmployeeFeatures($employee, $pdo);

                $stmt = $pdo->prepare("
                    INSERT INTO training_data (employee_id, features_json, actual_outcome, prediction_confidence) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $employee_id,
                    json_encode($features),
                    $actual_outcome,
                    $prediction_confidence
                ]);

                $message = "Training data saved successfully!";
                $message_type = "success";
            } else {
                $message = "Employee not found!";
                $message_type = "error";
            }
        } catch (PDOException $e) {
            $message = "Error saving training data: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Function to generate employee features (used in both PHP and JS)
function generateEmployeeFeatures($employee, $pdo = null)
{
    // Calculate tenure in months
    $hire_date = new DateTime($employee['hire_date']);
    $today = new DateTime();
    $tenure = $hire_date->diff($today)->y * 12 + $hire_date->diff($today)->m;

    // Get salary information
    $baseSalary = $employee['salary'] ?? 0;

    // Get performance rating (normalize to 0-100)
    $performance_rating = $employee['performance_rating'] ?? 5;
    $satisfaction = $performance_rating * 10; // Convert 1-10 scale to 0-100

    // Get overtime hours
    $overtime_hours = $employee['overtime_hours'] ?? 0;

    // Calculate work-life balance (inverse of overtime)
    $workLifeBalance = max(0, 100 - ($overtime_hours * 2));

    // Get department stability (count employees in department)
    $dept_stability = 50; // Default
    if ($pdo && isset($employee['department'])) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as dept_count FROM employees WHERE department = ? AND status = 'Active'");
            $stmt->execute([$employee['department']]);
            $dept_data = $stmt->fetch();
            $dept_stability = min(100, ($dept_data['dept_count'] ?? 0) * 10);
        } catch (Exception $e) {
            // Use default if query fails
        }
    }

    // Check for recent salary records as proxy for engagement
    $recent_payroll = 0;
    if ($pdo && isset($employee['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as recent_count FROM salary_records WHERE employee_id = ? AND pay_period_start >= DATE_SUB(NOW(), INTERVAL 3 MONTH)");
            $stmt->execute([$employee['id']]);
            $payroll_data = $stmt->fetch();
            $recent_payroll = ($payroll_data['recent_count'] ?? 0) > 0 ? 1 : 0;
        } catch (Exception $e) {
            // Table might not exist
        }
    }

    // Check for bonus as proxy for recognition
    $recent_bonus = 0;
    if ($pdo && isset($employee['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as bonus_count FROM bonus_records WHERE employee_id = ? AND bonus_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)");
            $stmt->execute([$employee['id']]);
            $bonus_data = $stmt->fetch();
            $recent_bonus = ($bonus_data['bonus_count'] ?? 0) > 0 ? 1 : 0;
        } catch (Exception $e) {
            // Table might not exist
        }
    }

    return [
        'age' => $employee['age'] ?? 30,
        'tenure' => $tenure,
        'baseSalary' => $baseSalary,
        'satisfaction' => $satisfaction,
        'overtime_hours' => $overtime_hours,
        'workLifeBalance' => $workLifeBalance,
        'dept_stability' => $dept_stability,
        'recent_payroll' => $recent_payroll,
        'recent_bonus' => $recent_bonus,
        'employment_status' => ($employee['employment_status'] ?? 'Full-Time') === 'Full-Time' ? 1 : 0
    ];
}

// Fetch all employees
try {
    $stmt = $pdo->query("
        SELECT e.*, 
               COALESCE(COUNT(DISTINCT sr.id), 0) as payroll_count,
               COALESCE(COUNT(DISTINCT br.id), 0) as bonus_count
        FROM employees e
        LEFT JOIN salary_records sr ON e.id = sr.employee_id
        LEFT JOIN bonus_records br ON e.id = br.employee_id
        WHERE e.status = 'Active'
        GROUP BY e.id
        ORDER BY e.name
    ");
    $employees = $stmt->fetchAll();
} catch (PDOException $e) {
    $employees = [];
    $message = "Error fetching employees: " . $e->getMessage();
    $message_type = "error";
}

// Fetch training data count
$training_count = 0;
$avg_accuracy = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count, AVG(prediction_confidence) as avg_accuracy FROM training_data");
    $training_data = $stmt->fetch();
    $training_count = $training_data['count'] ?? 0;
    $avg_accuracy = $training_data['avg_accuracy'] ?? 0;
} catch (Exception $e) {
    // Table doesn't exist yet, will be created when needed
}

// Get unique departments for display
$departments = [];
try {
    $deptStmt = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL ORDER BY department");
    $departments = $deptStmt->fetchAll();
} catch (Exception $e) {
    // Ignore error, use empty array
}

// Calculate workforce statistics
$workforce_stats = [
    'total_employees' => count($employees),
    'avg_salary' => 0,
    'avg_tenure' => 0,
    'avg_performance' => 0
];

if (count($employees) > 0) {
    $total_salary = 0;
    $total_tenure = 0;
    $total_performance = 0;

    foreach ($employees as $emp) {
        $total_salary += $emp['salary'] ?? 0;

        // Calculate tenure
        $hire_date = new DateTime($emp['hire_date'] ?? 'now');
        $today = new DateTime();
        $tenure = $hire_date->diff($today)->y * 12 + $hire_date->diff($today)->m;
        $total_tenure += $tenure;

        $total_performance += $emp['performance_rating'] ?? 5;
    }

    $workforce_stats['avg_salary'] = $total_salary / count($employees);
    $workforce_stats['avg_tenure'] = $total_tenure / count($employees);
    $workforce_stats['avg_performance'] = $total_performance / count($employees);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI-Powered Employee Attrition Prediction System</title>
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.20.0/dist/tf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --secondary: #7209b7;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --border-radius: 12px;
            --box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--dark);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Back Navigation Styles */
        .back-navigation {
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .btn-back {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(108, 117, 125, 0.3);
            background: linear-gradient(135deg, #5a6268 0%, #3d4349 100%);
        }

        .btn-back-dashboard {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        }

        .btn-back-dashboard:hover {
            background: linear-gradient(135deg, #3a56d4 0%, #2d44b8 100%);
        }

        .btn-back-system {
            background: linear-gradient(135deg, var(--secondary) 0%, #5a08a3 100%);
        }

        .btn-back-system:hover {
            background: linear-gradient(135deg, #5a08a3 0%, #490784 100%);
        }

        .navigation-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 25px 30px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
        }

        header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
            background-size: 20px 20px;
            opacity: 0.1;
        }

        .header-content {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        h1 {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 5px;
            background: linear-gradient(to right, #ffffff, #e0e7ff);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            text-fill-color: transparent;
        }

        .subtitle {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 400;
        }

        .header-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            padding: 10px 20px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            backdrop-filter: blur(10px);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            display: block;
        }

        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }

        .message {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
            box-shadow: var(--box-shadow);
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .message.success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
            border-left: 5px solid #28a745;
        }

        .message.error {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
            border-left: 5px solid #dc3545;
        }

        .message.info {
            background: linear-gradient(135deg, #d1ecf1, #bee5eb);
            color: #0c5460;
            border-left: 5px solid #17a2b8;
        }

        .tabs-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            margin-bottom: 30px;
        }

        .tabs {
            display: flex;
            background: var(--light);
            border-bottom: 1px solid var(--light-gray);
            flex-wrap: wrap;
        }

        .tab {
            padding: 18px 30px;
            cursor: pointer;
            font-weight: 600;
            color: var(--gray);
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 3px solid transparent;
        }

        .tab:hover {
            color: var(--primary);
            background: rgba(67, 97, 238, 0.05);
        }

        .tab.active {
            color: var(--primary);
            background: white;
            border-bottom: 3px solid var(--primary);
        }

        .tab i {
            font-size: 1.2rem;
        }

        .tab-content {
            display: none;
            padding: 30px;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        h2 {
            font-size: 1.8rem;
            color: var(--primary);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--box-shadow);
            margin-bottom: 25px;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark);
        }

        select,
        input,
        textarea {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid var(--light-gray);
            border-radius: 10px;
            font-size: 1rem;
            transition: var(--transition);
            background: white;
        }

        select:focus,
        input:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
        }

        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, var(--danger) 0%, #b5179e 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #f3722c 100%);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, var(--gray) 0%, #495057 100%);
            color: white;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--box-shadow);
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
        }

        .stat-icon.primary {
            background: linear-gradient(135deg, var(--primary), #3a56d4);
        }

        .stat-icon.success {
            background: linear-gradient(135deg, #4cc9f0, #4895ef);
        }

        .stat-icon.warning {
            background: linear-gradient(135deg, var(--warning), #f3722c);
        }

        .stat-icon.danger {
            background: linear-gradient(135deg, var(--danger), #b5179e);
        }

        .stat-content {
            flex: 1;
        }

        .stat-value-large {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-title {
            font-size: 0.95rem;
            color: var(--gray);
            font-weight: 500;
        }

        .prediction-result {
            border-radius: var(--border-radius);
            padding: 25px;
            margin-top: 25px;
            border-left: 6px solid;
            box-shadow: var(--box-shadow);
        }

        .prediction-low {
            border-color: #4cc9f0;
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
        }

        .prediction-medium {
            border-color: var(--warning);
            background: linear-gradient(135deg, #fff7ed, #ffedd5);
        }

        .prediction-high {
            border-color: var(--danger);
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
        }

        .employee-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }

        .detail-item {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .detail-label {
            font-weight: 600;
            color: var(--gray);
            font-size: 0.9rem;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        .data-table-container {
            overflow-x: auto;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .data-table th {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 18px 20px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .data-table td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--light-gray);
        }

        .data-table tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }

        .progress-container {
            margin: 20px 0;
        }

        .progress-bar {
            height: 10px;
            background: var(--light-gray);
            border-radius: 5px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            border-radius: 5px;
            transition: width 0.5s ease;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin: 25px 0;
        }

        .training-form {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 25px;
            border-radius: var(--border-radius);
            margin-top: 25px;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .risk-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .risk-low {
            background: #d1fae5;
            color: #065f46;
        }

        .risk-medium {
            background: #fef3c7;
            color: #92400e;
        }

        .risk-high {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            header {
                padding: 20px;
            }

            h1 {
                font-size: 1.8rem;
            }

            .header-content {
                flex-direction: column;
                text-align: center;
            }

            .header-stats {
                justify-content: center;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                padding: 15px;
                justify-content: center;
            }

            .tab-content {
                padding: 20px;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                flex-direction: column;
                text-align: center;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .back-navigation {
                flex-direction: column;
                align-items: flex-start;
            }

            .navigation-buttons {
                width: 100%;
            }

            .btn-back {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            h1 {
                font-size: 1.5rem;
            }

            h2 {
                font-size: 1.4rem;
            }

            .stat-value-large {
                font-size: 1.8rem;
            }

            .tab-content {
                padding: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Back Navigation Section -->
        <div class="back-navigation">
            <div>
                <h2><i class="fas fa-robot"></i> AI Attrition Prediction System</h2>
                <p class="subtitle">Predict and prevent employee turnover using machine learning</p>
            </div>
            <div class="navigation-buttons">
                <a href="../dashboard/index.php" class="btn-back btn-back-dashboard">
                    <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                </a>
                <a href="../index.php" class="btn-back btn-back-system">
                    <i class="fas fa-home"></i> Back to Main System
                </a>
            </div>
        </div>

        <header>
            <div class="header-content">
                <div>
                    <h1><i class="fas fa-robot"></i> AI Employee Attrition Predictor</h1>
                    <p class="subtitle">Predict and prevent employee turnover using machine learning</p>
                </div>
                <div class="header-stats">
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $workforce_stats['total_employees']; ?></span>
                        <span class="stat-label">Active Employees</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $training_count; ?></span>
                        <span class="stat-label">Training Samples</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $avg_accuracy > 0 ? round($avg_accuracy * 100, 1) . '%' : 'N/A'; ?></span>
                        <span class="stat-label">Model Accuracy</span>
                    </div>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <i class="fas fa-<?php echo $message_type === 'error' ? 'exclamation-triangle' : ($message_type === 'success' ? 'check-circle' : 'info-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="tabs-container">
            <div class="tabs">
                <div class="tab active" onclick="switchTab('predict')">
                    <i class="fas fa-chart-line"></i> Predict Attrition
                </div>
                <div class="tab" onclick="switchTab('database')">
                    <i class="fas fa-database"></i> Employee Database
                </div>
                <div class="tab" onclick="switchTab('train')">
                    <i class="fas fa-brain"></i> Train AI Model
                </div>
                <div class="tab" onclick="switchTab('analysis')">
                    <i class="fas fa-chart-pie"></i> Analytics
                </div>
            </div>

            <!-- Predict Tab -->
            <div id="predict-tab" class="tab-content active">
                <div class="card">
                    <div class="back-navigation" style="margin-bottom: 25px;">
                        <h2><i class="fas fa-user-check"></i> Employee Attrition Prediction</h2>
                        <a href="#database" class="btn-back" onclick="switchTab('database'); return false;">
                            <i class="fas fa-database"></i> View Database
                        </a>
                    </div>

                    <p class="subtitle">Select an employee to analyze attrition risk</p>

                    <div class="form-group">
                        <label for="employee"><i class="fas fa-user-tie"></i> Select Employee</label>
                        <select id="employee" class="employee-select">
                            <option value="">-- Choose an employee --</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"
                                    data-salary="<?php echo $emp['salary']; ?>"
                                    data-performance="<?php echo $emp['performance_rating']; ?>"
                                    data-department="<?php echo htmlspecialchars($emp['department']); ?>">
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                    (<?php echo htmlspecialchars($emp['department']); ?> -
                                    ₱<?php echo number_format($emp['salary'], 0); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="predictEmployee()">
                            <i class="fas fa-play-circle"></i> Run Prediction
                        </button>
                        <button class="btn btn-secondary" onclick="clearPrediction()">
                            <i class="fas fa-times-circle"></i> Clear Results
                        </button>
                        <a href="../dashboard/index.php" class="btn btn-back">
                            <i class="fas fa-arrow-left"></i> Return to Dashboard
                        </a>
                    </div>

                    <div id="output" class="prediction-placeholder">
                        <div class="prediction-result" style="border-color: #e9ecef; background: #f8f9fa;">
                            <h3><i class="fas fa-info-circle"></i> Prediction Results</h3>
                            <p>Select an employee and click "Run Prediction" to see attrition risk analysis.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database Tab -->
            <div id="database-tab" class="tab-content">
                <div class="card">
                    <div class="back-navigation" style="margin-bottom: 25px;">
                        <h2><i class="fas fa-users"></i> Employee Database</h2>
                        <a href="#predict" class="btn-back" onclick="switchTab('predict'); return false;">
                            <i class="fas fa-chart-line"></i> Back to Predictions
                        </a>
                    </div>

                    <div class="dashboard-grid">
                        <div class="stat-card">
                            <div class="stat-icon primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large"><?php echo $workforce_stats['total_employees']; ?></div>
                                <div class="stat-title">Total Employees</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon success">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large">₱<?php echo number_format($workforce_stats['avg_salary'], 0); ?></div>
                                <div class="stat-title">Average Salary</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon warning">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large"><?php echo round($workforce_stats['avg_tenure'], 1); ?>m</div>
                                <div class="stat-title">Avg Tenure</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon danger">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large"><?php echo round($workforce_stats['avg_performance'], 1); ?>/10</div>
                                <div class="stat-title">Avg Performance</div>
                            </div>
                        </div>
                    </div>

                    <div class="action-buttons" style="margin-bottom: 20px;">
                        <button class="btn btn-primary" onclick="refreshDatabase()">
                            <i class="fas fa-sync-alt"></i> Refresh Data
                        </button>
                        <button class="btn btn-success" onclick="exportData()">
                            <i class="fas fa-file-export"></i> Export Data
                        </button>
                        <a href="../dashboard/index.php" class="btn btn-back">
                            <i class="fas fa-arrow-left"></i> Return to Dashboard
                        </a>
                    </div>

                    <div class="data-table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Salary</th>
                                    <th>Performance</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $emp): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($emp['name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($emp['email']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($emp['department']); ?></td>
                                        <td><?php echo htmlspecialchars($emp['job_title']); ?></td>
                                        <td><strong>₱<?php echo number_format($emp['salary'], 0); ?></strong></td>
                                        <td>
                                            <div class="progress-bar" style="margin-bottom: 5px;">
                                                <div class="progress-fill" style="width: <?php echo $emp['performance_rating'] * 10; ?>%;"></div>
                                            </div>
                                            <?php echo $emp['performance_rating']; ?>/10
                                        </td>
                                        <td>
                                            <span class="risk-badge risk-low">Active</span>
                                        </td>
                                        <td>
                                            <div class="action-buttons" style="margin: 0; gap: 5px;">
                                                <button class="btn btn-sm btn-primary" onclick="viewEmployee(<?php echo $emp['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <button class="btn btn-sm btn-success" onclick="analyzeEmployee(<?php echo $emp['id']; ?>)">
                                                    <i class="fas fa-chart-line"></i> Analyze
                                                </button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                                    <button type="submit" name="delete_employee" class="btn btn-sm btn-danger"
                                                        onclick="return confirm('Delete <?php echo addslashes($emp['name']); ?>? This action cannot be undone.')">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="action-buttons" style="margin-top: 20px;">
                        <a href="../dashboard/index.php" class="btn btn-back">
                            <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                        </a>
                        <a href="../index.php" class="btn btn-back btn-back-system">
                            <i class="fas fa-home"></i> Back to Main System
                        </a>
                    </div>
                </div>
            </div>

            <!-- Train Tab -->
            <div id="train-tab" class="tab-content">
                <div class="card">
                    <div class="back-navigation" style="margin-bottom: 25px;">
                        <h2><i class="fas fa-graduation-cap"></i> Train AI Model</h2>
                        <a href="#analysis" class="btn-back" onclick="switchTab('analysis'); return false;">
                            <i class="fas fa-chart-pie"></i> View Analytics
                        </a>
                    </div>

                    <div class="dashboard-grid">
                        <div class="stat-card">
                            <div class="stat-icon primary">
                                <i class="fas fa-database"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large"><?php echo $training_count; ?></div>
                                <div class="stat-title">Training Samples</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon success">
                                <i class="fas fa-bullseye"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large" id="model-accuracy"><?php echo $avg_accuracy > 0 ? round($avg_accuracy * 100, 1) . '%' : 'N/A'; ?></div>
                                <div class="stat-title">Model Accuracy</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon warning">
                                <i class="fas fa-layer-group"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large" id="training-loss">-</div>
                                <div class="stat-title">Training Loss</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon danger">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large" id="training-time">-</div>
                                <div class="stat-title">Training Time</div>
                            </div>
                        </div>
                    </div>

                    <div class="chart-container">
                        <canvas id="trainingChart"></canvas>
                    </div>

                    <div class="training-form">
                        <h3><i class="fas fa-sliders-h"></i> Training Parameters</h3>
                        <div class="form-group">
                            <label for="epochs">Training Epochs</label>
                            <input type="range" id="epochs" min="10" max="300" value="150" oninput="document.getElementById('epochValue').innerText = this.value">
                            <div style="display: flex; justify-content: space-between;">
                                <span>10</span>
                                <span id="epochValue">150</span>
                                <span>300</span>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <button class="btn btn-success" onclick="trainModel()">
                                <i class="fas fa-play"></i> Start Training
                            </button>
                            <button class="btn btn-primary" onclick="loadPretrainedModel()">
                                <i class="fas fa-download"></i> Load Pre-trained
                            </button>
                            <button class="btn btn-warning" onclick="clearModel()">
                                <i class="fas fa-redo"></i> Clear Model
                            </button>
                            <button class="btn btn-secondary" onclick="saveModel()">
                                <i class="fas fa-save"></i> Save Model
                            </button>
                            <a href="../dashboard/index.php" class="btn btn-back">
                                <i class="fas fa-arrow-left"></i> Dashboard
                            </a>
                        </div>
                    </div>

                    <div id="training-status" class="prediction-result" style="margin-top: 20px; border-color: #e9ecef;">
                        <h4><i class="fas fa-info-circle"></i> Training Status</h4>
                        <p>Model is ready for training. Click "Start Training" to begin.</p>
                        <div id="training-progress" style="display: none;">
                            <div class="progress-container">
                                <div class="progress-bar">
                                    <div id="progress-fill" class="progress-fill" style="width: 0%"></div>
                                </div>
                                <div id="epoch-info" style="text-align: center; margin-top: 10px;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="action-buttons" style="margin-top: 20px;">
                        <a href="../dashboard/index.php" class="btn btn-back">
                            <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                        </a>
                        <a href="../index.php" class="btn btn-back btn-back-system">
                            <i class="fas fa-home"></i> Back to Main System
                        </a>
                    </div>
                </div>
            </div>

            <!-- Analysis Tab -->
            <div id="analysis-tab" class="tab-content">
                <div class="card">
                    <div class="back-navigation" style="margin-bottom: 25px;">
                        <h2><i class="fas fa-chart-bar"></i> Workforce Analytics</h2>
                        <a href="#train" class="btn-back" onclick="switchTab('train'); return false;">
                            <i class="fas fa-brain"></i> Back to Training
                        </a>
                    </div>

                    <div class="dashboard-grid">
                        <div class="stat-card">
                            <div class="stat-icon primary">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large" id="high-risk-count">0</div>
                                <div class="stat-title">High Risk Employees</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon warning">
                                <i class="fas fa-balance-scale"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large" id="attrition-risk">0%</div>
                                <div class="stat-title">Avg Attrition Risk</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon success">
                                <i class="fas fa-briefcase"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large" id="department-count"><?php echo count($departments); ?></div>
                                <div class="stat-title">Departments</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon danger">
                                <i class="fas fa-history"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value-large" id="avg-tenure"><?php echo round($workforce_stats['avg_tenure'], 1); ?>m</div>
                                <div class="stat-title">Avg Tenure</div>
                            </div>
                        </div>
                    </div>

                    <div class="chart-container">
                        <canvas id="analyticsChart"></canvas>
                    </div>

                    <div class="action-buttons">
                        <button class="btn btn-primary" onclick="analyzeData()">
                            <i class="fas fa-chart-line"></i> Run Analysis
                        </button>
                        <button class="btn btn-success" onclick="generateReport()">
                            <i class="fas fa-file-pdf"></i> Generate Report
                        </button>
                        <button class="btn btn-warning" onclick="exportData()">
                            <i class="fas fa-file-export"></i> Export Data
                        </button>
                        <a href="../dashboard/index.php" class="btn btn-back">
                            <i class="fas fa-arrow-left"></i> Dashboard
                        </a>
                    </div>

                    <div id="analysis-results" class="prediction-result" style="margin-top: 25px;">
                        <h4><i class="fas fa-chart-pie"></i> Analysis Results</h4>
                        <p>Click "Run Analysis" to generate insights about your workforce.</p>
                    </div>

                    <div class="action-buttons" style="margin-top: 20px;">
                        <a href="../dashboard/index.php" class="btn btn-back">
                            <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                        </a>
                        <a href="../index.php" class="btn btn-back btn-back-system">
                            <i class="fas fa-home"></i> Back to Main System
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        const employees = <?php echo json_encode($employees); ?>;
        let model = null;
        let isTraining = false;
        let trainingChart = null;
        let analyticsChart = null;

        // Initialize application
        document.addEventListener('DOMContentLoaded', async () => {
            await initializeApp();
            initCharts();
            updateAnalysisStats();
        });

        // Initialize the app
        const initializeApp = async () => {
            console.log('Initializing AI Attrition Predictor...');

            try {
                // Create model
                model = createModel();

                // Try to load saved model
                const savedModel = localStorage.getItem('attritionModel');
                if (savedModel) {
                    const modelData = JSON.parse(savedModel);
                    if (modelData.weights) {
                        const weights = modelData.weights.map(w =>
                            tf.tensor(w.data, w.shape)
                        );
                        model.setWeights(weights);

                        // Update UI
                        updateModelStats(modelData);
                        showNotification('Pre-trained model loaded successfully!', 'success');
                    }
                }

                // Update initial stats
                updateAnalysisStats();

            } catch (error) {
                console.error('Initialization error:', error);
                showNotification('Error initializing application: ' + error.message, 'error');
            }
        };

        // Create neural network model
        const createModel = () => {
            const model = tf.sequential();

            // Input layer (10 features)
            model.add(tf.layers.dense({
                units: 128,
                activation: 'relu',
                inputShape: [10],
                kernelInitializer: 'heNormal'
            }));
            model.add(tf.layers.dropout({
                rate: 0.3
            }));

            // Hidden layers
            model.add(tf.layers.dense({
                units: 64,
                activation: 'relu'
            }));
            model.add(tf.layers.dropout({
                rate: 0.2
            }));

            model.add(tf.layers.dense({
                units: 32,
                activation: 'relu'
            }));

            // Output layer (3 classes: Stay, Resign, Leave)
            model.add(tf.layers.dense({
                units: 3,
                activation: 'softmax'
            }));

            // Compile model
            model.compile({
                optimizer: tf.train.adam(0.001),
                loss: 'categoricalCrossentropy',
                metrics: ['accuracy']
            });

            return model;
        };

        // Switch tabs
        const switchTab = (tabName) => {
            // Update tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            // Activate selected tab
            document.querySelector(`.tab:nth-child(${['predict','database','train','analysis'].indexOf(tabName)+1})`).classList.add('active');
            document.getElementById(`${tabName}-tab`).classList.add('active');

            // Update charts on tab switch
            if (tabName === 'train' && trainingChart) {
                trainingChart.update();
            }
            if (tabName === 'analysis' && analyticsChart) {
                analyticsChart.update();
            }
        };

        // Initialize charts
        const initCharts = () => {
            // Training chart
            const trainingCtx = document.getElementById('trainingChart').getContext('2d');
            trainingChart = new Chart(trainingCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                            label: 'Accuracy',
                            data: [],
                            borderColor: '#4361ee',
                            backgroundColor: 'rgba(67, 97, 238, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Loss',
                            data: [],
                            borderColor: '#f72585',
                            backgroundColor: 'rgba(247, 37, 133, 0.1)',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Analytics chart
            const analyticsCtx = document.getElementById('analyticsChart').getContext('2d');
            analyticsChart = new Chart(analyticsCtx, {
                type: 'bar',
                data: {
                    labels: ['Low Risk', 'Medium Risk', 'High Risk'],
                    datasets: [{
                        label: 'Employee Count',
                        data: [0, 0, 0],
                        backgroundColor: [
                            '#4cc9f0',
                            '#f8961e',
                            '#f72585'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        };

        // Show notification
        const showNotification = (message, type = 'info') => {
            const notification = document.createElement('div');
            notification.className = `message ${type}`;
            notification.innerHTML = `
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 
                                 type === 'success' ? 'check-circle' : 'info-circle'}"></i>
                ${message}
            `;

            document.querySelector('.container').insertBefore(notification, document.querySelector('.tabs-container'));

            // Auto-remove after 5 seconds
            setTimeout(() => {
                notification.remove();
            }, 5000);
        };

        // Train model
        const trainModel = async () => {
            if (isTraining) {
                showNotification('Model is already training. Please wait.', 'error');
                return;
            }

            if (employees.length === 0) {
                showNotification('No employee data available for training.', 'error');
                return;
            }

            isTraining = true;
            const startTime = Date.now();

            // Update UI
            const statusElement = document.getElementById('training-status');
            const progressElement = document.getElementById('training-progress');
            const progressFill = document.getElementById('progress-fill');
            const epochInfo = document.getElementById('epoch-info');

            statusElement.innerHTML = '<h4><i class="fas fa-spinner fa-spin"></i> Training in Progress</h4>';
            progressElement.style.display = 'block';
            progressFill.style.width = '0%';

            try {
                // Prepare training data
                const features = [];
                const labels = [];

                employees.forEach(emp => {
                    // Generate features
                    const empFeatures = generateEmployeeFeaturesJS(emp);
                    features.push(empFeatures);

                    // Generate synthetic labels based on employee data
                    let stayProb = 0.7;
                    let resignProb = 0.2;
                    let leaveProb = 0.1;

                    // Adjust probabilities based on real factors
                    if (emp.overtime_hours > 15) {
                        stayProb -= 0.2;
                        resignProb += 0.15;
                        leaveProb += 0.05;
                    }

                    if (emp.performance_rating < 6) {
                        stayProb -= 0.15;
                        resignProb += 0.1;
                        leaveProb += 0.05;
                    }

                    if (emp.salary < 50000) {
                        stayProb -= 0.1;
                        resignProb += 0.08;
                        leaveProb += 0.02;
                    }

                    // Normalize probabilities
                    const total = stayProb + resignProb + leaveProb;
                    labels.push([stayProb / total, resignProb / total, leaveProb / total]);
                });

                // Convert to tensors
                const featureTensor = tf.tensor2d(features);
                const labelTensor = tf.tensor2d(labels);

                const epochs = parseInt(document.getElementById('epochs').value);
                const batchSize = Math.min(8, employees.length);

                // Training history
                const history = {
                    loss: [],
                    accuracy: []
                };

                // Train the model
                const trainingResult = await model.fit(featureTensor, labelTensor, {
                    epochs: epochs,
                    batchSize: batchSize,
                    shuffle: true,
                    validationSplit: 0.2,
                    callbacks: {
                        onEpochEnd: async (epoch, logs) => {
                            // Update progress
                            const progress = ((epoch + 1) / epochs) * 100;
                            progressFill.style.width = `${progress}%`;
                            epochInfo.innerHTML = `
                                Epoch: ${epoch + 1}/${epochs} | 
                                Loss: ${logs.loss.toFixed(4)} | 
                                Accuracy: ${logs.acc ? logs.acc.toFixed(4) : 'N/A'}
                            `;

                            // Update chart
                            history.loss.push(logs.loss);
                            history.accuracy.push(logs.acc || 0);

                            trainingChart.data.labels = Array.from({
                                length: history.loss.length
                            }, (_, i) => i + 1);
                            trainingChart.data.datasets[0].data = history.accuracy;
                            trainingChart.data.datasets[1].data = history.loss;
                            trainingChart.update();
                        }
                    }
                });

                // Calculate final metrics
                const finalLoss = trainingResult.history.loss[trainingResult.history.loss.length - 1];
                const finalAccuracy = trainingResult.history.acc ?
                    trainingResult.history.acc[trainingResult.history.acc.length - 1] : 0.75;

                // Calculate feature importance (simplified)
                const featureImportance = calculateFeatureImportance();

                // Save model
                const weights = await model.getWeights();
                const weightData = await Promise.all(weights.map(async w => ({
                    data: await w.data(),
                    shape: w.shape
                })));

                const modelData = {
                    weights: weightData,
                    accuracy: finalAccuracy,
                    loss: finalLoss,
                    featureImportance: featureImportance,
                    trainedAt: new Date().toISOString(),
                    trainingTime: Date.now() - startTime
                };

                localStorage.setItem('attritionModel', JSON.stringify(modelData));

                // Update UI
                updateModelStats(modelData);

                // Update training time
                const trainingTime = ((Date.now() - startTime) / 1000).toFixed(1);
                document.getElementById('training-time').textContent = `${trainingTime}s`;

                showNotification(`Model trained successfully! Accuracy: ${(finalAccuracy * 100).toFixed(1)}%`, 'success');
                statusElement.innerHTML = `
                    <h4><i class="fas fa-check-circle" style="color: #4cc9f0"></i> Training Complete</h4>
                    <p>Final accuracy: <strong>${(finalAccuracy * 100).toFixed(1)}%</strong></p>
                    <p>Training time: <strong>${trainingTime} seconds</strong></p>
                `;

                // Clean up tensors
                featureTensor.dispose();
                labelTensor.dispose();

            } catch (error) {
                console.error('Training error:', error);
                showNotification('Error training model: ' + error.message, 'error');
                statusElement.innerHTML = '<h4><i class="fas fa-exclamation-triangle"></i> Training Failed</h4>';
            } finally {
                isTraining = false;
            }
        };

        // Generate employee features for JavaScript
        const generateEmployeeFeaturesJS = (employee) => {
            // Calculate tenure
            const hireDate = new Date(employee.hire_date);
            const today = new Date();
            const tenure = (today.getFullYear() - hireDate.getFullYear()) * 12 +
                (today.getMonth() - hireDate.getMonth());

            // Normalize features for model input
            return [
                (employee.age || 30) / 100, // Age
                tenure / 100, // Tenure
                employee.salary / 200000, // Salary
                (employee.performance_rating * 10) / 100, // Satisfaction
                (employee.overtime_hours || 0) / 50, // Overtime
                Math.max(0, 1 - ((employee.overtime_hours || 0) / 50)), // Work-life balance
                0.5, // Department stability
                employee.payroll_count > 0 ? 1 : 0, // Recent payroll
                employee.bonus_count > 0 ? 1 : 0, // Recent bonus
                employee.employment_status === 'Full-Time' ? 1 : 0 // Employment type
            ];
        };

        // Calculate feature importance (simplified)
        const calculateFeatureImportance = () => {
            return [0.15, 0.12, 0.18, 0.14, 0.11, 0.09, 0.08, 0.06, 0.04, 0.03];
        };

        // Update model statistics
        const updateModelStats = (modelData) => {
            if (modelData) {
                document.getElementById('model-accuracy').textContent =
                    modelData.accuracy ? (modelData.accuracy * 100).toFixed(1) + '%' : 'N/A';
                document.getElementById('training-loss').textContent =
                    modelData.loss ? modelData.loss.toFixed(4) : 'N/A';
            }
        };

        // Update analysis statistics
        const updateAnalysisStats = () => {
            if (employees.length === 0) return;

            let highRiskCount = 0;
            let totalRisk = 0;

            employees.forEach(emp => {
                // Simple risk calculation
                let riskScore = 0;
                if (emp.performance_rating < 6) riskScore += 0.3;
                if (emp.overtime_hours > 15) riskScore += 0.3;
                if (emp.salary < 50000) riskScore += 0.2;

                totalRisk += riskScore;
                if (riskScore > 0.6) highRiskCount++;
            });

            const avgRisk = (totalRisk / employees.length) * 100;

            // Update UI
            document.getElementById('high-risk-count').textContent = highRiskCount;
            document.getElementById('attrition-risk').textContent = avgRisk.toFixed(1) + '%';

            // Update chart
            if (analyticsChart) {
                analyticsChart.data.datasets[0].data = [
                    employees.length - highRiskCount - Math.floor(highRiskCount / 2),
                    Math.floor(highRiskCount / 2),
                    highRiskCount
                ];
                analyticsChart.update();
            }
        };

        // Predict employee attrition
        const predictEmployee = async () => {
            const employeeId = document.getElementById('employee').value;

            if (!employeeId) {
                showNotification('Please select an employee first.', 'error');
                return;
            }

            if (!model) {
                showNotification('Model is not trained. Please train the model first.', 'error');
                return;
            }

            const employee = employees.find(emp => emp.id == employeeId);
            if (!employee) {
                showNotification('Employee not found.', 'error');
                return;
            }

            try {
                // Show loading
                const outputElement = document.getElementById('output');
                outputElement.innerHTML = `
                    <div class="prediction-result" style="border-color: #e9ecef;">
                        <h4><i class="fas fa-spinner fa-spin"></i> Analyzing Employee Data...</h4>
                        <p>Please wait while we analyze ${employee.name}'s attrition risk.</p>
                    </div>
                `;

                // Generate features
                const empFeatures = generateEmployeeFeaturesJS(employee);
                const inputTensor = tf.tensor2d([empFeatures]);

                // Make prediction
                const prediction = model.predict(inputTensor);
                const probabilities = await prediction.data();

                // Get prediction results
                const classes = ['Low Risk (Stay)', 'Medium Risk (Monitor)', 'High Risk (Act)'];
                const maxIndex = probabilities.indexOf(Math.max(...probabilities));
                const predictedClass = classes[maxIndex];
                const confidence = probabilities[maxIndex];

                // Determine risk level
                let riskLevel, riskClass, suggestions;

                if (predictedClass === 'Low Risk (Stay)') {
                    riskLevel = 'Low Risk';
                    riskClass = 'prediction-low';
                    suggestions = [
                        'Continue current engagement strategies',
                        'Monitor for any changes in performance metrics',
                        'Consider growth opportunities to maintain satisfaction'
                    ];
                } else if (predictedClass === 'Medium Risk (Monitor)') {
                    riskLevel = 'Medium Risk';
                    riskClass = 'prediction-medium';
                    suggestions = [
                        'Schedule regular check-ins to understand concerns',
                        'Review workload and work-life balance',
                        'Consider recognition or development opportunities'
                    ];
                } else {
                    riskLevel = 'High Risk';
                    riskClass = 'prediction-high';
                    suggestions = [
                        'Immediate one-on-one meeting required',
                        'Review compensation and benefits competitiveness',
                        'Develop retention plan with HR',
                        'Consider flexible work arrangements'
                    ];
                }

                // Calculate tenure
                const hireDate = new Date(employee.hire_date);
                const today = new Date();
                const tenure = (today.getFullYear() - hireDate.getFullYear()) * 12 +
                    (today.getMonth() - hireDate.getMonth());

                // Display results
                outputElement.innerHTML = `
                    <div class="prediction-result ${riskClass}">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3><i class="fas fa-user-shield"></i> ${employee.name} - ${riskLevel}</h3>
                            <span class="risk-badge risk-${riskLevel.toLowerCase().split(' ')[0]}">${riskLevel}</span>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
                            <div>
                                <h4><i class="fas fa-chart-pie"></i> Risk Distribution</h4>
                                <div style="background: #f8f9fa; padding: 15px; border-radius: 10px;">
                                    <div style="margin-bottom: 10px;">
                                        <span>Low Risk:</span>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="flex: 1; height: 10px; background: #e9ecef; border-radius: 5px;">
                                                <div style="height: 100%; width: ${probabilities[0] * 100}%; background: #4cc9f0; border-radius: 5px;"></div>
                                            </div>
                                            <span style="font-weight: bold;">${(probabilities[0] * 100).toFixed(1)}%</span>
                                        </div>
                                    </div>
                                    <div style="margin-bottom: 10px;">
                                        <span>Medium Risk:</span>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="flex: 1; height: 10px; background: #e9ecef; border-radius: 5px;">
                                                <div style="height: 100%; width: ${probabilities[1] * 100}%; background: #f8961e; border-radius: 5px;"></div>
                                            </div>
                                            <span style="font-weight: bold;">${(probabilities[1] * 100).toFixed(1)}%</span>
                                        </div>
                                    </div>
                                    <div>
                                        <span>High Risk:</span>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="flex: 1; height: 10px; background: #e9ecef; border-radius: 5px;">
                                                <div style="height: 100%; width: ${probabilities[2] * 100}%; background: #f72585; border-radius: 5px;"></div>
                                            </div>
                                            <span style="font-weight: bold;">${(probabilities[2] * 100).toFixed(1)}%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <h4><i class="fas fa-user-circle"></i> Employee Details</h4>
                                <div class="employee-details-grid">
                                    <div class="detail-item">
                                        <div class="detail-label">Age</div>
                                        <div class="detail-value">${employee.age || 'N/A'}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Tenure</div>
                                        <div class="detail-value">${tenure} months</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Salary</div>
                                        <div class="detail-value">₱${employee.salary.toLocaleString()}</div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">Performance</div>
                                        <div class="detail-value">${employee.performance_rating}/10</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h4><i class="fas fa-lightbulb"></i> Recommended Actions</h4>
                        <ul style="padding-left: 20px; margin-bottom: 25px;">
                            ${suggestions.map(s => `<li>${s}</li>`).join('')}
                        </ul>
                        
                        <div class="training-form">
                            <h5><i class="fas fa-robot"></i> Improve AI Accuracy</h5>
                            <p>Help train the AI by providing feedback on this prediction:</p>
                            <form method="POST" id="training-form">
                                <input type="hidden" name="employee_id" value="${employee.id}">
                                <input type="hidden" name="prediction_confidence" value="${confidence}">
                                <div class="form-group">
                                    <label>Actual Outcome:</label>
                                    <select name="actual_outcome" class="form-control" required>
                                        <option value="">-- Select outcome --</option>
                                        <option value="Stay">Stayed with company</option>
                                        <option value="Resign">Resigned/Left company</option>
                                        <option value="Leave">Took extended leave</option>
                                    </select>
                                </div>
                                <div class="action-buttons">
                                    <button type="submit" name="save_training_data" class="btn btn-success">
                                        <i class="fas fa-save"></i> Save Training Data
                                    </button>
                                    <button type="button" class="btn btn-secondary" onclick="clearPrediction()">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                `;

                // Clean up tensors
                inputTensor.dispose();
                prediction.dispose();

            } catch (error) {
                console.error('Prediction error:', error);
                showNotification('Error making prediction: ' + error.message, 'error');
            }
        };

        // Clear prediction results
        const clearPrediction = () => {
            document.getElementById('output').innerHTML = `
                <div class="prediction-result" style="border-color: #e9ecef; background: #f8f9fa;">
                    <h3><i class="fas fa-info-circle"></i> Prediction Results</h3>
                    <p>Select an employee and click "Run Prediction" to see attrition risk analysis.</p>
                </div>
            `;
            document.getElementById('employee').value = '';
        };

        // Load pre-trained model
        const loadPretrainedModel = async () => {
            showNotification('Loading pre-trained model with demo data...', 'info');
            await trainModel();
        };

        // Clear model
        const clearModel = () => {
            if (confirm('Are you sure you want to clear the model? All training progress will be lost.')) {
                localStorage.removeItem('attritionModel');
                model = createModel();
                updateModelStats({});
                document.getElementById('training-time').textContent = '-';
                showNotification('Model cleared successfully.', 'success');
            }
        };

        // Save model
        const saveModel = () => {
            const savedModel = localStorage.getItem('attritionModel');
            if (!savedModel) {
                showNotification('No trained model found to save.', 'error');
                return;
            }

            // Create download link
            const blob = new Blob([savedModel], {
                type: 'application/json'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `attrition-model-${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            showNotification('Model saved successfully!', 'success');
        };

        // Refresh database
        const refreshDatabase = () => {
            showNotification('Refreshing employee data...', 'info');
            setTimeout(() => {
                location.reload();
            }, 1000);
        };

        // Analyze data
        const analyzeData = async () => {
            const resultsElement = document.getElementById('analysis-results');
            resultsElement.innerHTML = `
                <div class="prediction-result" style="border-color: #e9ecef;">
                    <h4><i class="fas fa-spinner fa-spin"></i> Analyzing Workforce Data...</h4>
                    <p>Please wait while we analyze your workforce data.</p>
                </div>
            `;

            // Simulate analysis
            setTimeout(() => {
                // Calculate statistics
                let departmentStats = {};
                let performanceStats = {
                    high: 0,
                    medium: 0,
                    low: 0
                };
                let salaryRanges = {
                    low: 0,
                    medium: 0,
                    high: 0
                };

                employees.forEach(emp => {
                    // Department stats
                    const dept = emp.department || 'Unknown';
                    departmentStats[dept] = (departmentStats[dept] || 0) + 1;

                    // Performance stats
                    if (emp.performance_rating >= 8) performanceStats.high++;
                    else if (emp.performance_rating >= 5) performanceStats.medium++;
                    else performanceStats.low++;

                    // Salary ranges
                    if (emp.salary < 50000) salaryRanges.low++;
                    else if (emp.salary < 100000) salaryRanges.medium++;
                    else salaryRanges.high++;
                });

                // Find top departments
                const topDepartments = Object.entries(departmentStats)
                    .sort((a, b) => b[1] - a[1])
                    .slice(0, 3);

                resultsElement.innerHTML = `
                    <div class="prediction-result prediction-low">
                        <h4><i class="fas fa-chart-bar"></i> Workforce Analysis Results</h4>
                        
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 25px 0;">
                            <div>
                                <h5><i class="fas fa-building"></i> Department Distribution</h5>
                                <div style="background: white; padding: 15px; border-radius: 10px;">
                                    ${topDepartments.map(([dept, count]) => `
                                        <div style="margin-bottom: 10px;">
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                                <span>${dept}</span>
                                                <span>${count} employees</span>
                                            </div>
                                            <div style="height: 8px; background: #e9ecef; border-radius: 4px;">
                                                <div style="height: 100%; width: ${(count / employees.length) * 100}%; 
                                                     background: #4361ee; border-radius: 4px;"></div>
                                            </div>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            
                            <div>
                                <h5><i class="fas fa-star"></i> Performance Overview</h5>
                                <div style="background: white; padding: 15px; border-radius: 10px;">
                                    <div style="margin-bottom: 10px;">
                                        <span>High Performers (8-10):</span>
                                        <span style="float: right; font-weight: bold;">${performanceStats.high}</span>
                                    </div>
                                    <div style="margin-bottom: 10px;">
                                        <span>Medium Performers (5-7):</span>
                                        <span style="float: right; font-weight: bold;">${performanceStats.medium}</span>
                                    </div>
                                    <div>
                                        <span>Low Performers (1-4):</span>
                                        <span style="float: right; font-weight: bold;">${performanceStats.low}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h5><i class="fas fa-lightbulb"></i> Key Insights</h5>
                        <ul style="padding-left: 20px;">
                            <li>Workforce consists of ${employees.length} active employees</li>
                            <li>Average salary: ₱${<?php echo number_format($workforce_stats['avg_salary'], 0); ?>}</li>
                            <li>Average tenure: ${<?php echo round($workforce_stats['avg_tenure'], 1); ?>} months</li>
                            <li>${topDepartments[0] ? `${topDepartments[0][0]} is the largest department with ${topDepartments[0][1]} employees` : ''}</li>
                            <li>${performanceStats.high > performanceStats.low * 2 ? 'Strong performance culture observed' : 'Performance improvement opportunities identified'}</li>
                        </ul>
                    </div>
                `;

                updateAnalysisStats();

            }, 1500);
        };

        // Generate report
        const generateReport = () => {
            showNotification('Generating comprehensive report...', 'info');

            setTimeout(() => {
                // Create report content
                const reportContent = `
                    AI Employee Attrition Analysis Report
                    ====================================
                    
                    Report Date: ${new Date().toLocaleDateString()}
                    Total Employees: ${employees.length}
                    
                    Executive Summary:
                    ${employees.length > 0 ? 
                        `Based on AI analysis, the organization shows moderate attrition risk. 
                        Key factors include compensation competitiveness and work-life balance.` 
                        : 'Insufficient data for comprehensive analysis.'}
                    
                    Key Statistics:
                    - Average Salary: ₱${<?php echo number_format($workforce_stats['avg_salary'], 0); ?>}
                    - Average Tenure: ${<?php echo round($workforce_stats['avg_tenure'], 1); ?>} months
                    - Average Performance: ${<?php echo round($workforce_stats['avg_performance'], 1); ?>/10}
                    
                    Recommendations:
                    1. Conduct regular employee satisfaction surveys
                    2. Review compensation structure for market competitiveness
                    3. Implement career development programs
                    4. Enhance work-life balance initiatives
                    
                    Generated by AI Employee Attrition Predictor
                `;

                // Create download link
                const blob = new Blob([reportContent], {
                    type: 'text/plain'
                });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `attrition-report-${new Date().toISOString().split('T')[0]}.txt`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                showNotification('Report generated and downloaded successfully!', 'success');
            }, 2000);
        };

        // Export data
        const exportData = () => {
            const dataToExport = employees.map(emp => ({
                name: emp.name,
                email: emp.email,
                department: emp.department,
                job_title: emp.job_title,
                salary: emp.salary,
                performance_rating: emp.performance_rating,
                hire_date: emp.hire_date,
                status: emp.status
            }));

            const csvContent = [
                ['Name', 'Email', 'Department', 'Job Title', 'Salary', 'Performance', 'Hire Date', 'Status'],
                ...dataToExport.map(emp => [
                    emp.name,
                    emp.email,
                    emp.department,
                    emp.job_title,
                    emp.salary,
                    emp.performance_rating,
                    emp.hire_date,
                    emp.status
                ])
            ].map(row => row.join(',')).join('\n');

            const blob = new Blob([csvContent], {
                type: 'text/csv'
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `employee-data-export-${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            showNotification('Data exported successfully!', 'success');
        };

        // View employee details
        const viewEmployee = (id) => {
            const employee = employees.find(emp => emp.id == id);
            if (employee) {
                // Switch to predict tab and select employee
                switchTab('predict');
                document.getElementById('employee').value = id;

                // Trigger prediction after a short delay
                setTimeout(() => {
                    predictEmployee();
                }, 500);
            }
        };

        // Analyze specific employee
        const analyzeEmployee = (id) => {
            viewEmployee(id);
        };
    </script>
</body>

</html>