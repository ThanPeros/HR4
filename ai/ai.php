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

// Function to generate employee features (MATCHING JS LOGIC EXACTLY)
function generateEmployeeFeatures($employee, $pdo = null)
{
    // Calculate tenure in months
    $hire_date = new DateTime($employee['hire_date']);
    $today = new DateTime();
    $tenure = $hire_date->diff($today)->y * 12 + $hire_date->diff($today)->m;

    // Get salary information
    $salary = $employee['salary'] ?? 0;

    // Get performance rating
    $performance_rating = $employee['performance_rating'] ?? 5;

    // Get overtime hours
    $overtime_hours = $employee['overtime_hours'] ?? 0;

    // Check for recent payroll/bonus (Engagement Proxies)
    $recent_payroll = 0;
    $recent_bonus = 0;
    
    if ($pdo && isset($employee['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as recent_count FROM salary_records WHERE employee_id = ? AND pay_period_start >= DATE_SUB(NOW(), INTERVAL 3 MONTH)");
            $stmt->execute([$employee['id']]);
            $pd = $stmt->fetch();
            $recent_payroll = ($pd['recent_count'] ?? 0) > 0 ? 1 : 0;
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as bonus_count FROM bonus_records WHERE employee_id = ? AND bonus_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)");
            $stmt->execute([$employee['id']]);
            $bd = $stmt->fetch();
            $recent_bonus = ($bd['bonus_count'] ?? 0) > 0 ? 1 : 0;
        } catch (Exception $e) { /* Ignore */ }
    }
    
    // Calculate Role Risk (Freight Specific)
    $roleRisk = 0;
    $job = strtolower($employee['job_title'] ?? '');
    if (strpos($job, 'driver') !== false || strpos($job, 'warehouse') !== false) {
         $roleRisk = 0.2; 
         if ($overtime_hours > 20) $roleRisk += 0.3;
    }

    // Return Normalized Array [10 features]
    // MATCHES JS: [Age, Tenure, Salary, Perf, OT, WorkLife, DeptStab, Payroll, Bonus, RoleRisk]
    $age = $employee['age'] ?? 30;
    
    return [
        $age / 100,                                 // Age
        $tenure / 120,                              // Tenure 
        min(1, $salary / 100000),                   // Salary
        $performance_rating / 10,                   // Performance
        min(1, $overtime_hours / 100),              // Overtime
        max(0, 1 - ($overtime_hours / 60)),         // Work-Life Balance
        0.5,                                        // Dept Stability (Placeholder)
        $recent_payroll,                            // Recent Payroll
        $recent_bonus,                              // Recent Bonus
        $roleRisk                                   // Role Risk
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

// Fetch historical training data
$training_records = [];
try {
    $stmt = $pdo->query("SELECT * FROM training_data ORDER BY created_at DESC");
    $training_records = $stmt->fetchAll();
} catch (Exception $e) {
    // Table assumes not created yet
}

// Fetch training metrics
$training_count = count($training_records);
$avg_accuracy = 0;
if ($training_count > 0) {
    $total_conf = 0;
    foreach($training_records as $r) $total_conf += $r['prediction_confidence'];
    $avg_accuracy = $total_conf / $training_count;
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
<?php
    $dashboardPath = '../dashboard/index.php';
    // Clean up workforce stats for display
    $avgSalary = isset($workforce_stats['avg_salary']) ? $workforce_stats['avg_salary'] : 0;
    $avgTenure = isset($workforce_stats['avg_tenure']) ? $workforce_stats['avg_tenure'] : 0;
    $avgPerf = isset($workforce_stats['avg_performance']) ? $workforce_stats['avg_performance'] : 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Predictive Attrition | Slate Freight</title>
    
    <!-- TensorFlow & Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@3.20.0/dist/tf.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    
    <!-- Bootstrap 5 & FontAwesome (Already included by sidebar, but good for standalone check or specific versions) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --light: #f8f9fc;
            --dark: #5a5c69;
        }

        /* Custom Styles for AI Module */
        .ai-card {
            border: none;
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            background: #fff;
            transition: transform 0.2s;
        }
        .ai-card:hover { transform: translateY(-3px); }

        .stat-icon-box {
            width: 50px; height: 50px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; color: white;
        }
        
        /* Tab Navigation */
        .nav-tabs .nav-link {
            color: #4e73df;
            font-weight: 600;
            border: none;
            border-bottom: 2px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #2e59d9;
            border-bottom: 2px solid #4e73df;
            background: transparent;
        }
        .nav-tabs .nav-link:hover { border-color: transparent; color: #224abe; }

        /* Training Visualization */
        .epoch-info { font-family: 'Courier New', monospace; font-size: 0.85rem; }
        .progress-bar-ai {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill-ai {
            height: 100%;
            background: linear-gradient(90deg, #4e73df, #36b9cc);
            transition: width 0.3s ease;
        }

        /* Prediction Result Cards */
        .prediction-result {
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border-left: 5px solid;
            background: #fff;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        .prediction-low { border-color: #1cc88a; background: #f0fdf4; }
        .prediction-medium { border-color: #f6c23e; background: #fffbeb; }
        .prediction-high { border-color: #e74a3b; background: #fef2f2; }

        .risk-badge { padding: 4px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8rem; text-transform: uppercase; }
        .risk-low { background: #d1fae5; color: #065f46; }
        .risk-medium { background: #fef3c7; color: #92400e; }
        .risk-high { background: #fee2e2; color: #991b1b; }

        .chart-area-ai { position: relative; height: 300px; width: 100%; }
        
        /* Floating Message */
        .message-toast {
            position: fixed; top: 80px; right: 20px; z-index: 9999;
            padding: 1rem 1.5rem; border-radius: 0.5rem; color: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex; align-items: center; gap: 10px;
            animation: slideInRight 0.3s ease-out;
        }
        .msg-success { background: #1cc88a; }
        .msg-error { background: #e74a3b; }
        .msg-info { background: #36b9cc; }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</head>

<body class="<?php echo isset($_SESSION['theme']) && $_SESSION['theme'] === 'dark' ? 'dark-mode' : ''; ?>">
    
    <!-- System Main Content Wrapper -->
    <div class="main-content">
        <div class="container-fluid">
            
            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 font-weight-bold text-gray-800"><i class="fas fa-robot text-primary me-2"></i>Predictive Attrition AI</h1>
                    <p class="text-muted mb-0">Machine Learning Powered Workforce Retention Analysis</p>
                </div>
                <div>
                    <a href="<?php echo $dashboardPath; ?>" class="btn btn-secondary btn-sm shadow-sm">
                        <i class="fas fa-arrow-left fa-sm text-white-50 me-1"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Top Stats -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="ai-card h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Active Employees</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $workforce_stats['total_employees']; ?></div>
                                </div>
                                <div class="col-auto">
                                    <div class="stat-icon-box bg-primary"><i class="fas fa-users"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="ai-card h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Training Samples</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo $training_count; ?></div>
                                </div>
                                <div class="col-auto">
                                    <div class="stat-icon-box bg-info"><i class="fas fa-database"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="ai-card h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Model Accuracy</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" id="model-accuracy-display">
                                        <?php echo $avg_accuracy > 0 ? round($avg_accuracy * 100, 1) . '%' : 'N/A'; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <div class="stat-icon-box bg-success"><i class="fas fa-check-circle"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                 <div class="col-xl-3 col-md-6 mb-4">
                    <div class="ai-card h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Avg Attrition Risk</div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800" id="attrition-risk">Calculating...</div>
                                </div>
                                <div class="col-auto">
                                    <div class="stat-icon-box bg-warning"><i class="fas fa-exclamation-triangle"></i></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Tabs -->
             <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <ul class="nav nav-tabs card-header-tabs" id="aiTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" id="predict-tab-btn" data-bs-toggle="tab" data-bs-target="#predict-tab" type="button" role="tab" onclick="switchTab('predict')">
                                <i class="fas fa-chart-line me-2"></i>Predict Attrition
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="analysis-tab-btn" data-bs-toggle="tab" data-bs-target="#analysis-tab" type="button" role="tab" onclick="switchTab('analysis')">
                                <i class="fas fa-chart-pie me-2"></i>Analysis
                            </button>
                        </li>
                         <li class="nav-item">
                            <button class="nav-link" id="database-tab-btn" data-bs-toggle="tab" data-bs-target="#database-tab" type="button" role="tab" onclick="switchTab('database')">
                                <i class="fas fa-users me-2"></i>Database
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="train-tab-btn" data-bs-toggle="tab" data-bs-target="#train-tab" type="button" role="tab" onclick="switchTab('train')">
                                <i class="fas fa-brain me-2"></i>Train Model
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="aiTabsContent">
                        
                        <!-- PREDICT TAB -->
                        <div class="tab-pane fade show active" id="predict-tab" role="tabpanel">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="bg-light p-4 rounded mb-4">
                                        <h5 class="font-weight-bold mb-3"><i class="fas fa-user-tie me-2"></i>Select Employee</h5>
                                        <div class="form-group mb-3">
                                            <select id="employee" class="form-select form-select-lg shadow-sm">
                                                <option value="">-- Choose an employee --</option>
                                                <?php foreach ($employees as $emp): ?>
                                                    <option value="<?php echo $emp['id']; ?>"
                                                        data-salary="<?php echo $emp['salary']; ?>"
                                                        data-performance="<?php echo $emp['performance_rating']; ?>"
                                                        data-department="<?php echo htmlspecialchars($emp['department']); ?>">
                                                        <?php echo htmlspecialchars($emp['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button class="btn btn-primary w-100 mb-2" onclick="predictEmployee()">
                                            <i class="fas fa-magic me-2"></i>Run AI Prediction
                                        </button>
                                        <button class="btn btn-outline-secondary w-100" onclick="clearPrediction()">
                                            Reset
                                        </button>
                                    </div>
                                    <div class="alert alert-info small">
                                        <i class="fas fa-info-circle me-1"></i> The AI analyzes tenure, compensation, OT hours, and performance history.
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div id="output">
                                        <div class="text-center py-5 text-muted bg-light rounded" style="border: 2px dashed #e3e6f0;">
                                            <i class="fas fa-robot fa-3x mb-3 text-gray-300"></i>
                                            <h5>Ready to Analyze</h5>
                                            <p>Select an employee from the left to view comprehensive attrition risk analysis.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ANALYSIS TAB -->
                        <div class="tab-pane fade" id="analysis-tab" role="tabpanel">
                            <div class="d-flex justify-content-between mb-3">
                                <h5 class="font-weight-bold">Workforce Risk Assessment</h5>
                                <div>
                                    <button class="btn btn-primary btn-sm" onclick="analyzeData()"><i class="fas fa-sync-alt me-1"></i> Refresh Analysis</button>
                                    <button class="btn btn-success btn-sm" onclick="generateReport()"><i class="fas fa-download me-1"></i> Download Report</button>
                                </div>
                            </div>
                            <div id="analysis-results">
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Initialize analysis by clicking "Refresh Analysis"...</p>
                                </div>
                            </div>
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card shadow-sm">
                                        <div class="card-header py-2 bg-white">
                                            <h6 class="m-0 font-weight-bold text-primary">Risk Distribution</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-area-ai">
                                                <canvas id="analyticsChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                     <div class="card shadow-sm h-100">
                                        <div class="card-header py-2 bg-white">
                                            <h6 class="m-0 font-weight-bold text-danger">High Risk Alert <span class="badge bg-danger ms-2" id="high-risk-count">0</span></h6>
                                        </div>
                                        <div class="card-body">
                                            <p class="small text-muted">Employees identified with >60% probability of attrition based on current model.</p>
                                            <div class="table-responsive" style="max-height: 250px;">
                                                 <!-- Populated by JS -->
                                                 <div id="high-risk-list-placeholder" class="text-center text-muted mt-5">Run analysis to view data</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- DATABASE TAB -->
                        <div class="tab-pane fade" id="database-tab" role="tabpanel">
                             <div class="d-flex justify-content-between mb-3">
                                <h5 class="font-weight-bold">Employee Data Records</h5>
                                <button class="btn btn-outline-primary btn-sm" onclick="exportData()"><i class="fas fa-file-csv me-1"></i> Export CSV</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="employeeTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Name</th>
                                            <th>Dept</th>
                                            <th>Role</th>
                                            <th>Tenure</th>
                                            <th>Salary</th>
                                            <th>Perf</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($employees, 0, 50) as $emp): 
                                            // Calc simple tenure
                                            $hDate = new DateTime($emp['hire_date']);
                                            $now = new DateTime();
                                            $diff = $now->diff($hDate);
                                            $tenureMonths = ($diff->y * 12) + $diff->m;
                                        ?>
                                        <tr>
                                            <td class="font-weight-bold"><?php echo htmlspecialchars($emp['name']); ?></td>
                                            <td><?php echo htmlspecialchars($emp['department']); ?></td>
                                            <td><small><?php echo htmlspecialchars($emp['job_title'] ?? 'N/A'); ?></small></td>
                                            <td><?php echo $tenureMonths; ?> mo</td>
                                            <td>₱<?php echo number_format($emp['salary']); ?></td>
                                            <td><span class="badge bg-<?php echo $emp['performance_rating'] >= 4 ? 'success' : 'warning'; ?>"><?php echo $emp['performance_rating']; ?>/5</span></td>
                                            <td>
                                                <button class="btn btn-xs btn-primary p-1" onclick="viewEmployee(<?php echo $emp['id']; ?>)">Analyze</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- TRAIN MODEL TAB -->
                        <div class="tab-pane fade" id="train-tab" role="tabpanel">
                            <div class="row">
                                <div class="col-md-5">
                                    <div class="bg-light p-4 rounded">
                                        <h5 class="font-weight-bold mb-3"><i class="fas fa-cogs me-2"></i>Training Config</h5>
                                        <div class="form-group mb-3">
                                            <label>Training Epochs</label>
                                            <input type="number" id="epochs" class="form-control" value="50" min="10" max="200">
                                        </div>
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-primary" id="train-btn" onclick="trainModel()">
                                                <i class="fas fa-play me-2"></i>Start Training
                                            </button>
                                            <button class="btn btn-outline-info" onclick="loadPretrainedModel()">
                                                <i class="fas fa-cloud-download-alt me-2"></i>Load Pre-trained
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="clearModel()">
                                                <i class="fas fa-trash me-2"></i>Reset Model
                                            </button>
                                        </div>
                                        <hr>
                                        <div class="small">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span>Model Accuracy:</span>
                                                <strong id="model-accuracy">N/A</strong>
                                            </div>
                                            <div class="d-flex justify-content-between">
                                                <span>Last Training Loss:</span>
                                                <strong id="training-loss">N/A</strong>
                                            </div>
                                            <div class="d-flex justify-content-between mt-1">
                                                <span>Training Time:</span>
                                                <strong id="training-time">-</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <div class="card border-left-info shadow h-100 py-2">
                                        <div class="card-body">
                                            <div class="row no-gutters align-items-center mb-3">
                                                <div class="col mr-2">
                                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Training Status</div>
                                                    <div id="training-status" class="h5 mb-0 font-weight-bold text-gray-800">Idle</div>
                                                </div>
                                                <div class="col-auto">
                                                    <i class="fas fa-microchip fa-2x text-gray-300"></i>
                                                </div>
                                            </div>
                                            <div id="training-progress" style="display:none;">
                                                <div class="small font-weight-bold mb-1">Progress <span class="float-end" id="epoch-info"></span></div>
                                                <div class="progress-bar-ai">
                                                    <div class="progress-fill-ai" id="progress-fill" style="width: 0%"></div>
                                                </div>
                                            </div>
                                            <div class="chart-area-ai mt-4">
                                                <canvas id="trainingChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- Scripts (Using TensorFlow.js logic) -->
    <!-- We must include the Bootstrap JS for tabs to work -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Global variables
        const employees = <?php echo json_encode($employees); ?>;
        const trainingData = <?php echo json_encode($training_records); ?>;
        let model = null;
        let isTraining = false;
        let trainingChart = null;
        let analyticsChart = null;

        // Initialize application
        document.addEventListener('DOMContentLoaded', async () => {
            await initializeApp();
            initCharts();
            updateAnalysisStats();
            
            // Fix tabs if they get stuck
            const triggerTabList = [].slice.call(document.querySelectorAll('#aiTabs button'))
            triggerTabList.forEach(function (triggerEl) {
              triggerEl.addEventListener('click', function (event) {
                event.preventDefault()
                var tab = new bootstrap.Tab(triggerEl)
                tab.show()
              })
            })
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

        // Switch tabs (Bootstrap 5 Compatible)
        const switchTab = (tabName) => {
            const tabEl = document.querySelector(`#${tabName}-tab-btn`);
            if(tabEl) {
                const tab = new bootstrap.Tab(tabEl);
                tab.show();
            }
            
            // Update charts on tab switch
            if (tabName === 'train' && trainingChart) {
                // Short delay to allow DOM update
                setTimeout(() => trainingChart.update(), 100);
            }
            if (tabName === 'analysis' && analyticsChart) {
                setTimeout(() => analyticsChart.update(), 100);
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

        // Update Model Stats UI
        const updateModelStats = (data) => {
            const acc = data.accuracy || 0;
            const loss = data.loss || 0;
            const time = data.trainingTime ? (data.trainingTime / 1000).toFixed(1) : '-';
            
            // Update individual elements exists check
            const accEl = document.getElementById('model-accuracy');
            if(accEl) accEl.textContent = (acc * 100).toFixed(1) + '%';
            
            const accDisplayEl = document.getElementById('model-accuracy-display');
            if(accDisplayEl) accDisplayEl.textContent = (acc * 100).toFixed(1) + '%';

            const lossEl = document.getElementById('training-loss');
            if(lossEl) lossEl.textContent = typeof loss === 'number' ? loss.toFixed(4) : '-';

            const timeEl = document.getElementById('training-time');
            if(timeEl) timeEl.textContent = time !== '-' ? time + 's' : '-';
            
            if (data.trainedAt) {
                 const statusEl = document.getElementById('training-status');
                 if(statusEl) statusEl.innerHTML = `<h4><i class="fas fa-check-circle text-success"></i> Model Ready</h4><div class="small text-muted">Last trained: ${new Date(data.trainedAt).toLocaleString()}</div>`;
            }
        };

        // Calculate Feature Importance (Dummy)
        const calculateFeatureImportance = () => {
            return {
                'Tenure': 0.3,
                'Salary': 0.2,
                'Overtime': 0.2,
                'Performance': 0.15,
                'Role': 0.15
            };
        };

        // Update Analysis Stats
        const updateAnalysisStats = async () => {
             // Calculate workforce risk
             let highRiskCount = 0;
             let totalRisk = 0;
             let lowRiskCount = 0;
             let mediumRiskCount = 0;
             let highRiskCountStat = 0;

             // Use simple heuristics if model not ready, or predictions if ready
             // For summary stats we'll use heuristics to be fast
             employees.forEach(emp => {
                 let riskScore = 0;
                 // Heuristics
                 if(emp.performance_rating < 3) riskScore += 0.3;
                 if(emp.overtime_hours > 15) riskScore += 0.3;
                 if(emp.salary < 30000) riskScore += 0.2;
                 
                 const job = (emp.job_title||'').toLowerCase();
                 if(job.includes('driver')) riskScore += 0.1;

                 totalRisk += riskScore;
                 
                 if(riskScore > 0.6) {
                     highRiskCount++;
                     highRiskCountStat++;
                 } else if (riskScore > 0.3) {
                     mediumRiskCount++;
                 } else {
                     lowRiskCount++;
                 }
             });

             const avgRisk = employees.length > 0 ? (totalRisk / employees.length) * 100 : 0;
             
             // Update DOM
             const riskEl = document.getElementById('attrition-risk');
             if(riskEl) riskEl.textContent = avgRisk.toFixed(1) + '%';
             
             const highRiskEl = document.getElementById('high-risk-count');
             if(highRiskEl) highRiskEl.textContent = highRiskCount;
             
             // Update Chart
             if(analyticsChart) {
                 analyticsChart.data.datasets[0].data = [lowRiskCount, mediumRiskCount, highRiskCountStat];
                 analyticsChart.update();
             }
        };

        // Train model
        const trainModel = async () => {
            if (isTraining) {
                showNotification('Model is already training. Please wait.', 'error');
                return;
            }

            if (employees.length === 0 && trainingData.length === 0) {
                showNotification('No data available for training.', 'error');
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

                // 1. Logic for Current Employees (Synthetic/Heuristic Data)
                employees.forEach(emp => {
                    // Generate features
                    const empFeatures = generateEmployeeFeaturesJS(emp);
                    features.push(empFeatures);

                    // Generate synthetic labels based on employee data (Enhanced Logic)
                    let stayProb = 0.70;
                    let resignProb = 0.20;
                    let leaveProb = 0.10;

                    const job = (emp.job_title || '').toLowerCase();
                    const salary = parseFloat(emp.salary) || 0;
                    const tenureMonths = empFeatures[1] * 120; // Reverse normalization
                    const performance = parseFloat(emp.performance_rating) || 5;

                    // RULE 1: High Turnover Roles
                    if (job.includes('driver') || job.includes('warehouse') || job.includes('labor')) {
                        stayProb -= 0.15; resignProb += 0.10; leaveProb += 0.05;
                    }

                    // RULE 2: Salary Disparity (Role based)
                    // If Manager but low salary
                    if (job.includes('manager') && salary < 60000) {
                         stayProb -= 0.20; // Flight risk
                         resignProb += 0.20;
                    } 
                    // If Driver but low salary
                    else if (job.includes('driver') && salary < 25000) {
                         stayProb -= 0.25;
                         resignProb += 0.25;
                    }
                    // General low salary
                    else if (salary < 20000) {
                         stayProb -= 0.10;
                         resignProb += 0.10;
                    }

                    // RULE 3: Overtime Burnout
                    if (emp.overtime_hours > 20) {
                        stayProb -= 0.25;
                        resignProb += 0.20; // Burnout resignation
                        leaveProb += 0.05; // Health/perf issues
                    } else if (emp.overtime_hours > 10) {
                        stayProb -= 0.10;
                        resignProb += 0.10;
                    }

                    // RULE 4: Performance vs Reward
                    // High performer but not high salary -> Risk
                    if (performance >= 8 && salary < 40000) {
                        stayProb -= 0.30; // Poached by competitors
                        resignProb += 0.30;
                    }
                    // Low performer -> Layoff risk
                    if (performance < 3) {
                         stayProb -= 0.20;
                         leaveProb += 0.20; // Involuntary termination
                    }

                    // RULE 5: Tenure "Itch"
                    // 1-2 years is common hopping time
                    if (tenureMonths > 12 && tenureMonths < 30) {
                        stayProb -= 0.05;
                        resignProb += 0.05;
                    }
                    // Very long tenure usually stays, unless low pay
                    if (tenureMonths > 120 && salary < 50000) {
                         stayProb -= 0.10;
                         resignProb += 0.10;
                    }

                    // Normalize probabilities
                    // Ensure no negatives
                    stayProb = Math.max(0.01, stayProb);
                    resignProb = Math.max(0.01, resignProb);
                    leaveProb = Math.max(0.01, leaveProb);

                    const total = stayProb + resignProb + leaveProb;
                    labels.push([stayProb / total, resignProb / total, leaveProb / total]);
                });

                // 2. Logic for Historical Training Data (Real Human Feedback)
                // Weights real data 5x more by adding it multiple times or relying on shuffle
                // Here we just add it once, but typically you'd weight these samples higher
                trainingData.forEach(record => {
                    try {
                        const recFeatures = JSON.parse(record.features_json);
                        // Convert dict to array if it was stored as dict in older versions, or use as is
                        // The PHP now returns array, so it should be array. 
                        // If it's old data (dict), this might break, so we check.
                        let featureArray = [];
                        if (Array.isArray(recFeatures)) {
                            featureArray = recFeatures;
                        } else {
                            // Map old associative array to new flat array order ? 
                            // For safety, assuming new data format or skipping
                            // If you have old data, you'd strictly map keys here using the same order as generateEmployeeFeatures
                            return; 
                        }

                        features.push(featureArray);

                        // One-hot encode label
                        let label = [0, 0, 0]; // Stay, Resign, Leave
                        if (record.actual_outcome === 'Stay') label = [1, 0, 0];
                        else if (record.actual_outcome === 'Resign') label = [0, 1, 0];
                        else if (record.actual_outcome === 'Leave') label = [0, 0, 1];
                        
                        labels.push(label);
                        
                        // Add Recplicas to weight this real data higher?
                        // features.push(featureArray); labels.push(label);
                    } catch(e) { console.error("Error parsing training record", e); }
                });

                // Convert to tensors
                const featureTensor = tf.tensor2d(features);
                const labelTensor = tf.tensor2d(labels);

                const epochs = parseInt(document.getElementById('epochs').value);
                const batchSize = 32;

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
                            epochInfo.innerHTML = `Epoch: ${epoch + 1}/${epochs} | Loss: ${logs.loss.toFixed(4)} | Acc: ${logs.acc ? logs.acc.toFixed(4) : 'N/A'}`;

                            // Update chart
                            trainingChart.data.labels.push(epoch + 1);
                            trainingChart.data.datasets[0].data.push(logs.acc);
                            trainingChart.data.datasets[1].data.push(logs.loss);
                            if(epoch % 5 === 0) trainingChart.update();
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
                statusElement.innerHTML = `<h4><i class="fas fa-check-circle text-success"></i> Training Complete</h4><p>Accuracy: ${(finalAccuracy*100).toFixed(1)}%</p>`;

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
            // Calculate tenure in months
            // Handle missing or invalid date by defaulting to today (0 tenure)
            const hireDateStr = employee.hire_date || new Date().toISOString();
            const hireDate = new Date(hireDateStr);
            const today = new Date();
            let tenure = 0;
            
            if (!isNaN(hireDate.getTime())) {
                 tenure = (today.getFullYear() - hireDate.getFullYear()) * 12 +
                    (today.getMonth() - hireDate.getMonth());
            }

            // Freight Specific: High risk for Drivers with high overtime
            let roleRisk = 0;
            const job = (employee.job_title || '').toLowerCase();
            if (job.includes('driver') || job.includes('warehouse')) {
                 roleRisk = 0.2; // Base risk for demanding roles
                 if ((employee.overtime_hours || 0) > 20) roleRisk += 0.3; // Burnout risk
            }
            
            // Stats sanitation
            const age = employee.age ? parseFloat(employee.age) : 30;
            const salary = employee.salary ? parseFloat(employee.salary) : 0;
            const perf = employee.performance_rating ? parseFloat(employee.performance_rating) : 5;
            const ot = employee.overtime_hours ? parseFloat(employee.overtime_hours) : 0;
            const payrollCount = employee.payroll_count ? parseInt(employee.payroll_count) : 0;
            const bonusCount = employee.bonus_count ? parseInt(employee.bonus_count) : 0;

            // Normalize features for model input (10 features)
            // Ensure no NaNs by checking inputs
            return [
                age / 100, // Age
                tenure / 120, // Tenure (normalized to ~10 years)
                Math.min(1, salary / 100000), // Salary
                perf / 10, // Performance
                Math.min(1, ot / 100), // Overtime
                Math.max(0, 1 - (ot / 60)), // Work-life balance
                0.5, // Department stability (placeholder)
                payrollCount > 0 ? 1 : 0, // Recent payroll
                bonusCount > 0 ? 1 : 0, // Recent bonus
                roleRisk // NEW: Freight Role Risk
            ];
        };

        // Predict employee attrition using Client-Side TF Model
        const predictEmployee = async () => {
             const employeeId = document.getElementById('employee').value;
             if (!employeeId) {
                 showNotification('Please select an employee first.', 'error');
                 return;
             }

             const employee = employees.find(e => e.id == employeeId);
             if (!employee) return;

             // Show loading
             const outputElement = document.getElementById('output');
             outputElement.innerHTML = `
                <div class="prediction-result" style="border-color: #e9ecef;">
                    <h4><i class="fas fa-spinner fa-spin"></i> Analyzing Employee Data...</h4>
                    <p class="text-muted">Running neural network inference...</p>
                </div>`;

             // Artificial delay for UX
             await new Promise(r => setTimeout(r, 1000));

             try {
                 if(!model) {
                      throw new Error("Model not trained yet. Please go to 'Train Model' tab.");
                 }

                 const features = generateEmployeeFeaturesJS(employee);
                 const inputTensor = tf.tensor2d([features]);
                 
                 const prediction = model.predict(inputTensor);
                 const values = await prediction.data(); // [stay, resign, leave]
                 
                 const stayProb = values[0];
                 const resignProb = values[1];
                 const leaveProb = values[2];
                 const attritionProb = resignProb + leaveProb; // Total attrition risk

                 // Determine Risk Level
                 let riskLevel = 'Low';
                 let riskClass = 'prediction-low';
                 let suggestions = [];
                 
                 if (attritionProb > 0.6) {
                     riskLevel = 'High';
                     riskClass = 'prediction-high';
                 } else if (attritionProb > 0.3) {
                     riskLevel = 'Medium';
                     riskClass = 'prediction-medium';
                 }

                 // Generate Explanations (Freight Context)
                 const riskFactors = [];
                 const job = (employee.job_title || '').toLowerCase();
                 const salary = parseFloat(employee.salary) || 0;
                 const perf = parseFloat(employee.performance_rating) || 0;
                 const tenureMonths = features[1] * 120;
                 const overtime = parseFloat(employee.overtime_hours) || 0;

                 if (overtime > 20) riskFactors.push("Critical Burnout Risk (>20h overtime)");
                 else if (overtime > 15) riskFactors.push("High Overtime Hours (>15h)");
                 
                 if (job.includes('driver')) {
                     if(salary < 25000) riskFactors.push("Driver Pay Below Market (<25k)");
                     riskFactors.push("High Turnover Role (Driver)");
                 }
                 
                 if (job.includes('manager') && salary < 60000) {
                      riskFactors.push("Manager Compensation Risk (<60k)");
                 }

                 if (perf >= 8 && salary < 40000) riskFactors.push("High Performer Flight Risk (Low Pay)");
                 if (perf < 3) riskFactors.push("Performance Improvement Needed");
                 
                 if (tenureMonths < 12) riskFactors.push("New Hire (< 1 year) - Early Turnover Risk");
                 if (tenureMonths > 12 && tenureMonths < 30) riskFactors.push("1-2 Year Tenure Itch");

                 // Suggestions
                 if(riskLevel === 'High') {
                     suggestions = ['Immediate Retention Interview', 'Review Overtime Load', 'Compensation Benchmarking'];
                 } else if (riskLevel === 'Medium') {
                     suggestions = ['Manager Check-in', 'Schedule Adjustments', 'Skills Training'];
                 } else {
                     suggestions = ['Maintain Engagement', 'Career Growth Planning'];
                 }

                  const score = Math.round(attritionProb * 100);
                  
                  // Render with Feedback Form
                  outputElement.innerHTML = `
                       <div class="prediction-result ${riskClass}">
                           <div class="d-flex justify-content-between">
                               <h3>${employee.name}</h3>
                               <span class="badge bg-${riskLevel === 'High' ? 'danger' : (riskLevel === 'Medium' ? 'warning' : 'success')}">${riskLevel} Risk (${score}%)</span>
                           </div>
                           <div class="py-3">
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" style="width: ${stayProb*100}%">Stay</div>
                                    <div class="progress-bar bg-warning" style="width: ${resignProb*100}%">Resign</div>
                                    <div class="progress-bar bg-danger" style="width: ${leaveProb*100}%">Layoff/Leave</div>
                                </div>
                                <div class="text-muted small mt-1">Probabilities: Stay ${(stayProb*100).toFixed(0)}% | Resign ${(resignProb*100).toFixed(0)}% | Leave ${(leaveProb*100).toFixed(0)}%</div>
                           </div>
                           
                           <div class="row mb-3">
                               <div class="col-md-12">
                                   <h6><i class="fas fa-clipboard-list me-2"></i>Risk Factors</h6>
                                   <ul class="list-unstyled">
                                       ${riskFactors.length > 0 ? riskFactors.map(f => `<li class="mb-1"><i class="fas fa-exclamation-circle text-danger me-2"></i> ${f}</li>`).join('') : '<li class="text-success"><i class="fas fa-check me-2"></i> No major risk factors detected.</li>'}
                                   </ul>
                               </div>
                           </div>
                           
                           <hr>
                           <h5><i class="fas fa-user-check"></i> Train the AI (Feedback)</h5>
                           <p class="small text-muted">Help improve accuracy by confirming the status.</p>
                           
                           <form method="POST" action="ai.php">
                                <input type="hidden" name="employee_id" value="${employee.id}">
                                <input type="hidden" name="prediction_confidence" value="${attritionProb}">
                                <div class="btn-group w-100">
                                    <button type="submit" name="save_training_data" value="1" class="btn btn-outline-success btn-sm" onclick="this.form.actual_outcome.value='Stay'">
                                        <i class="fas fa-check"></i> Confirm Stay
                                    </button>
                                    <button type="submit" name="save_training_data" value="1" class="btn btn-outline-warning btn-sm" onclick="this.form.actual_outcome.value='Resign'">
                                        <i class="fas fa-walking"></i> Actual: Resigned
                                    </button>
                                    <button type="submit" name="save_training_data" value="1" class="btn btn-outline-danger btn-sm" onclick="this.form.actual_outcome.value='Leave'">
                                        <i class="fas fa-door-open"></i> Actual: Terminated
                                    </button>
                                </div>
                                <input type="hidden" name="actual_outcome" id="actual_outcome" value="">
                           </form>
                       </div>
                  `;

                 inputTensor.dispose();

             } catch (e) {
                 showNotification(e.message, 'error');
                 outputElement.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
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

        // Analyze data using Client-Side Logic
        const analyzeData = async () => {
            const resultsElement = document.getElementById('analysis-results');
            resultsElement.innerHTML = `
                <div class="prediction-result" style="border-color: #e9ecef;">
                    <h4><i class="fas fa-spinner fa-spin"></i> Analyzing Workforce Data...</h4>
                    <p>Running batch analysis on all employees...</p>
                </div>
            `;

            // UX Delay
            await new Promise(r => setTimeout(r, 800));

            try {
                // Update stats and charts
                updateAnalysisStats();

                // Identify High Risk Employees for the list
                let highRiskEmployees = [];
                employees.forEach(emp => {
                     // Heuristic scoring for batch analysis
                     let riskScore = 0;
                     if (emp.performance_rating < 4) riskScore += 30; // Scale 1-5 usually in system, old code assumed 1-10? 
                     // Let's assume 5 is high. If rating < 3 (avg), risk adds up.
                     // Adjusted to DB data: rating is likely 1-5.
                     if ((emp.performance_rating || 3) < 3) riskScore += 25;

                     if ((emp.overtime_hours||0) > 15) riskScore += 30;
                     if (emp.salary < 30000) riskScore += 20;
                     if ((emp.job_title||'').toLowerCase().includes('driver')) riskScore += 15;
                     
                     // Cap at 99
                     riskScore = Math.min(99, riskScore);

                     if (riskScore > 50) {
                         highRiskEmployees.push({...emp, risk_score: riskScore});
                     }
                });

                highRiskEmployees.sort((a,b) => b.risk_score - a.risk_score);

                // Update the List UI
                const listHtml = highRiskEmployees.length > 0 ? 
                    `<table class="table table-sm table-hover">
                        <thead><tr><th>Name</th><th>Role</th><th>Risk Score</th><th>Action</th></tr></thead>
                        <tbody>
                            ${highRiskEmployees.slice(0, 10).map(emp => `
                                <tr>
                                    <td>${emp.name}</td>
                                    <td>${emp.job_title || emp.department}</td>
                                    <td><span class="badge bg-danger">${emp.risk_score}%</span></td>
                                    <td><button class="btn btn-xs btn-outline-primary" onclick="viewEmployee(${emp.id})">Analyze</button></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>` 
                    : '<div class="alert alert-success">No high risk employees detected.</div>';
                
                const listPlaceholder = document.getElementById('high-risk-list-placeholder');
                if(listPlaceholder) listPlaceholder.innerHTML = listHtml;

                resultsElement.innerHTML = `
                     <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i> Analysis Complete. ${highRiskEmployees.length} high-risk profiles identified.
                     </div>
                `;

            } catch (error) {
                console.error('Analysis error:', error);
                showNotification('Error performing analysis: ' + error.message, 'error');
                resultsElement.innerHTML = `<div class="message error">Analysis failed: ${error.message}</div>`;
            }
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

        // Expose functions to global window object for onclick handlers
        window.switchTab = switchTab;
        window.predictEmployee = predictEmployee;
        window.trainModel = trainModel;
        window.loadPretrainedModel = loadPretrainedModel;
        window.clearModel = clearModel;
        window.saveModel = saveModel;
        window.refreshDatabase = refreshDatabase;
        window.analyzeData = analyzeData;
        window.generateReport = generateReport;
        window.exportData = exportData;
        window.viewEmployee = viewEmployee;
        window.analyzeEmployee = analyzeEmployee;
        
    </script>
</body>

</html>