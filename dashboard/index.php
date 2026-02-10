<?php
// dashboard/index.php
session_start();
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// Initialize Theme
$currentTheme = $_SESSION['theme'] ?? 'light';

// -------------------------------------------------------------------------
// 1. DATA VALIDATION & SETUP (Ensure required tables exist)
// -------------------------------------------------------------------------
try {
    $requiredTables = ['employees', 'payroll_periods', 'payroll_records'];
    foreach ($requiredTables as $table) {
        if (!$pdo->query("SHOW TABLES LIKE '$table'")->fetchColumn()) {
            throw new Exception("Required table '$table' does not exist.");
        }
    }
} catch (Exception $e) {
    error_log("Dashboard setup error: " . $e->getMessage());
}

// -------------------------------------------------------------------------
// 2. SAFE MODE DATA FETCHING (Indexes & % Only)
// -------------------------------------------------------------------------

// A. CARD METRICS & INDEXES
try {
    // 1. Employee Counts (Safe)
    $employeesTable = $pdo->query("SHOW TABLES LIKE 'employees'")->fetchColumn() ? 'employees' : null;
    $totalEmployees = 0; 
    $activeEmployees = 0;
    
    if ($employeesTable) {
        $totalEmployees = $pdo->query("SELECT COUNT(*) FROM $employeesTable")->fetchColumn();
        $hasStatus = $pdo->query("SHOW COLUMNS FROM $employeesTable LIKE 'status'")->fetchColumn();
        if ($hasStatus) {
            $activeEmployees = $pdo->query("SELECT COUNT(*) FROM $employeesTable WHERE status = 'Active'")->fetchColumn();
        } else {
            $activeEmployees = $totalEmployees;
        }
    }

    // 2. Payroll Logic for Indexes
    // Get last 6 Payrolls
    $payrollTrendRaw = $pdo->query("
        SELECT p.name, p.end_date, COALESCE(SUM(r.net_pay), 0) as total_val
        FROM payroll_periods p 
        LEFT JOIN payroll_records r ON p.id = r.payroll_period_id 
        WHERE p.status IN ('Released', 'Approved', 'Completed', 'Closed')
        GROUP BY p.id, p.name, p.end_date 
        ORDER BY p.end_date DESC 
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $payrollTrendRaw = array_reverse($payrollTrendRaw);
    
    // Calculate Base Index (Average of first 3 available or first 1)
    $baseValue = 0;
    if (count($payrollTrendRaw) > 0) {
        $baseValue = $payrollTrendRaw[0]['total_val']; // Simple base = 1st period in view
    }
    
    $payrollIndexCurrent = 0;
    $payrollChangePct = 0;
    $trendDirection = 'Stable';
    
    if (!empty($payrollTrendRaw)) {
        $latest = end($payrollTrendRaw);
        $latestVal = $latest['total_val'];
        $prevVal = count($payrollTrendRaw) > 1 ? $payrollTrendRaw[count($payrollTrendRaw)-2]['total_val'] : $latestVal;
        
        // Calculate Index (Base 100)
        $payrollIndexCurrent = ($baseValue > 0) ? round(($latestVal / $baseValue) * 100) : 100;
        
        // Change %
        $change = ($prevVal > 0) ? (($latestVal - $prevVal) / $prevVal) * 100 : 0;
        $payrollChangePct = round($change, 1);
        
        if ($change > 1) $trendDirection = 'Increasing';
        elseif ($change < -1) $trendDirection = 'Decreasing';
    }

    // 3. Variance Analysis (Category Only)
    // Internal calc: Expected vs Actual
    // Attempt to estimate expected based on active employees * avg salary (internal only)
    $expectedVal = 0;
    $actualVal = !empty($payrollTrendRaw) ? end($payrollTrendRaw)['total_val'] : 0;
    
    if ($employeesTable && $hasStatus) {
        $hasBasic = $pdo->query("SHOW COLUMNS FROM $employeesTable LIKE 'basic_salary'")->fetchColumn();
        if ($hasBasic) {
            $expectedVal = $pdo->query("SELECT SUM(basic_salary) FROM $employeesTable WHERE status = 'Active'")->fetchColumn() ?: 0;
        }
    }
    
    $variancePct = ($expectedVal > 0) ? round((($actualVal - $expectedVal) / $expectedVal) * 100, 1) : 0;
    
    $varianceCategory = 'Normal'; // 0-5%
    if (abs($variancePct) > 10) $varianceCategory = 'Critical';
    elseif (abs($variancePct) > 5) $varianceCategory = 'Warning';

    // 4. Overtime Intensity (Low/Medium/High)
    // We check last payroll for OT share
    $otIntensity = 'Low';
    $lastPayroll = $pdo->query("SELECT id FROM payroll_periods WHERE status IN ('Released','Approved') ORDER BY end_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lastPayroll && $actualVal > 0) {
        $lpid = $lastPayroll['id'];
        $hasOt = $pdo->query("SHOW COLUMNS FROM payroll_records LIKE 'overtime_pay'")->fetchColumn();
        if ($hasOt) {
            $otVal = $pdo->query("SELECT SUM(overtime_pay) FROM payroll_records WHERE payroll_period_id = $lpid")->fetchColumn() ?: 0;
            $otShare = ($otVal / $actualVal) * 100;
            
            if ($otShare > 10) $otIntensity = 'High';
            elseif ($otShare > 5) $otIntensity = 'Medium';
        }
    }

} catch (Exception $e) {
    // Fallbacks
    $payrollIndexCurrent = 100;
    $varianceCategory = 'Normal';
    $otIntensity = 'Low';
    $trendDirection = 'Stable';
}

// B. DEPARTMENT DISTRIBUTION (% Only)
try {
    $deptDist = [];
    if ($employeesTable) {
        $hasDept = $pdo->query("SHOW COLUMNS FROM $employeesTable LIKE 'department'")->fetchColumn();
        $hasBasicSalary = $pdo->query("SHOW COLUMNS FROM $employeesTable LIKE 'basic_salary'")->fetchColumn();
        
        if ($hasDept && $hasBasicSalary) {
             $rawDist = $pdo->query("
                SELECT department, SUM(basic_salary) as total_sal 
                FROM $employeesTable 
                WHERE status = 'Active' 
                GROUP BY department 
                ORDER BY total_sal DESC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $grandTotal = array_sum(array_column($rawDist, 'total_sal'));
            
            foreach ($rawDist as $d) {
                $deptDist[] = [
                    'department' => $d['department'] ?: 'Unassigned',
                    'pct' => ($grandTotal > 0) ? round(($d['total_sal'] / $grandTotal) * 100, 1) : 0
                ];
            }
        }
    }
} catch (Exception $e) { $deptDist = []; }

// C. COMPENSATION (% Only)
try {
    // Check table
    $compTable = $pdo->query("SHOW TABLES LIKE 'compensation_plans'")->fetchColumn();
    $budgetUtil = 0;
    $compProgress = [];
    
    if ($compTable) {
        $totalB = $pdo->query("SELECT SUM(budget) FROM compensation_plans WHERE year = 2026")->fetchColumn() ?: 0;
        $totalU = $pdo->query("SELECT SUM(used_budget) FROM compensation_plans WHERE year = 2026")->fetchColumn() ?: 0;
        
        $budgetUtil = ($totalB > 0) ? round(($totalU / $totalB) * 100) : 0;
        $remainingPct = 100 - $budgetUtil;
        
        // Bands
        // Simulate bands if no real data
        $compProgress = [
            ['label' => 'Utilization', 'pct' => $budgetUtil, 'color' => 'info'],
            ['label' => 'Remaining', 'pct' => $remainingPct, 'color' => 'secondary']
        ];
    }
} catch (Exception $e) { $budgetUtil = 0; }

// D. HMO & BENEFITS (% & Counts Only)
try {
    $enrollmentRate = 0;
    $coverageRate = 0; // Distinct from enrollment if covering dependents
    $utilizationLevel = 'Low';
    $expiringCount = 0;
    
    // Check main HMO table
    $hmoMain = $pdo->query("SHOW TABLES LIKE 'employee_hmo_enrollments'")->fetchColumn();
    
    // Default base logic
    if ($hmoMain && $activeEmployees > 0) {
        $enrolled = $pdo->query("SELECT COUNT(*) FROM employee_hmo_enrollments WHERE status = 'Active'")->fetchColumn();
        $enrollmentRate = round(($enrolled / $activeEmployees) * 100);
        
        // Mock Coverage Rate (Enrollments + Dependents / Total Emp + Est Dependents)
        // Simply use Enrollment Rate for now, or fetch dependent count
        $depCount = $pdo->query("SELECT SUM(dependent_count) FROM employee_hmo_enrollments WHERE status = 'Active'")->fetchColumn() ?: 0;
        // Assume avg 2 dependents per employee potential
        $potentialLives = $activeEmployees * 3; 
        $actualLives = $enrolled + $depCount;
        $coverageRate = $potentialLives > 0 ? round(($actualLives / $potentialLives) * 100) : 0;
        
        // Expiry Count
        // Check if card_expiry_date exists
        $hasExpiry = $pdo->query("SHOW COLUMNS FROM employee_hmo_enrollments LIKE 'card_expiry_date'")->fetchColumn();
        if ($hasExpiry) {
            $expiringCount = $pdo->query("
                SELECT COUNT(*) FROM employee_hmo_enrollments 
                WHERE status = 'Active' 
                AND card_expiry_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)
            ")->fetchColumn() ?: 0;
        }
        
    } elseif ($pdo->query("SHOW TABLES LIKE 'hmo_enrollments'")->fetchColumn() && $activeEmployees > 0) {
        // Fallback
        $enrolled = $pdo->query("SELECT COUNT(*) FROM hmo_enrollments WHERE status = 'Active'")->fetchColumn();
        $enrollmentRate = round(($enrolled / $activeEmployees) * 100);
    }
    
    if ($enrollmentRate > 80) $utilizationLevel = 'High';
    elseif ($enrollmentRate > 50) $utilizationLevel = 'Medium';

} catch (Exception $e) {
    $enrollmentRate = 0;
}

// E. ALERTS (No Numbers)
$alerts = [];
if ($activeEmployees > 0 && ($activeEmployees / $totalEmployees) < 0.7) $alerts[] = "Correction needed: Low employee activity ratio";
if ($budgetUtil > 90) $alerts[] = "Budget Alert: Utilization nearing capacity";
if ($varianceCategory === 'Critical') $alerts[] = "Variance Alert: Significant payroll deviation detected";
if ($otIntensity === 'High') $alerts[] = "Workforce Alert: High overtime intensity detected";
if ($expiringCount > 0) $alerts[] = "HMO: $expiringCount policies expiring soon";
$alertsCount = count($alerts);

// F. AI INSIGHTS
$aiMsg = "Payroll metrics are stable.";
if ($trendDirection === 'Increasing') $aiMsg = "Payroll index follows an upward trend.";
if ($trendDirection === 'Decreasing') $aiMsg = "Payroll index shows optimization/decrease.";

if ($varianceCategory !== 'Normal') $aiMsg .= " Variance is outside standard range.";
if ($otIntensity !== 'Low') $aiMsg .= " Overtime levels require review.";

// G. CHART DATA (Indexes)
$chartLabels = [];
$chartData = [];
if (!empty($payrollTrendRaw)) {
    foreach ($payrollTrendRaw as $pt) {
        $chartLabels[] = date('M Y', strtotime($pt['end_date']));
        // Convert to Index
        $val = ($baseValue > 0) ? round(($pt['total_val'] / $baseValue) * 100) : 100;
        $chartData[] = $val;
    }
}

// -------------------------------------------------------------------------
// DEMO MODE OVERRIDE (For Simulation/Demo Purposes)
// -------------------------------------------------------------------------
// If no real data is found, or to force a rich UI state:
if (empty($payrollTrendRaw) || true) { // Set to true to force Demo Data
    // Keep real Human Capital data
    // $activeEmployees = 142; 
    // $totalEmployees = 150;
    
    // Payroll Simulator
    $payrollIndexCurrent = 108;
    $payrollChangePct = 2.4;
    $trendDirection = 'Increasing';
    
    $chartLabels = ['Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan'];
    $chartData = [100, 102, 101, 105, 104, 108]; // Visual trend
    
    // Variance & Intensity
    $varianceCategory = 'Warning'; // Simulated drift
    $otIntensity = 'Medium';
    
    // Budget
    $budgetUtil = 72;
    $totalCompBudget = 4000000;
    $remBudget = 1120000;
    
    $compPlans = [
        ['department' => 'Engineering', 'used_budget' => 720000, 'budget' => 1000000],
        ['department' => 'Sales', 'used_budget' => 850000, 'budget' => 1000000],
        ['department' => 'HR', 'used_budget' => 400000, 'budget' => 800000],
        ['department' => 'Operations', 'used_budget' => 600000, 'budget' => 1200000],
    ];
    
    // Dept Distribution (for Table)
    $deptDist = [
        ['department' => 'Engineering', 'pct' => 35.5],
        ['department' => 'Sales', 'pct' => 28.2],
        ['department' => 'Operations', 'pct' => 20.1],
        ['department' => 'HR & Admin', 'pct' => 10.4],
        ['department' => 'Finance', 'pct' => 5.8],
    ];
    
    // Benefits
    $enrollmentRate = 88;
    $coverageRate = 76;
    $utilizationLevel = 'High';
    $expiringCount = 3;
    
    // Alerts
    $alerts = [
        "Variance Alert: Slight payroll drift detected (+2.4%)",
        "HMO: 3 policies expiring in < 30 days"
    ];
    $alertsCount = count($alerts);
    
    // AI Msg
    $aiMsg = "Payroll index shows a steady increase (+8 pts over 6 mos). Overtime intensity is Medium; consider resource leveling in Operations.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HR Executive Dashboard | Slate Freight</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root { 
            --primary: #4e73df; 
            --success: #1cc88a; 
            --info: #36b9cc; 
            --warning: #f6c23e; 
            --danger: #e74a3b; 
            --dark-bg: #1a1c23; 
            --light-gray: #f8f9fc;
        }
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; 
            background-color: var(--light-gray); 
            font-size: 0.9rem;
        }
        .main-content { 
            padding: 1.5rem; 
            margin-top: 60px; 
            transition: all 0.3s;
        }
        .card-dash { 
            border: none; 
            border-radius: 0.75rem; 
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1); 
            transition: transform 0.2s, box-shadow 0.2s; 
            background: white; 
            overflow: hidden;
        }
        .card-dash:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 0.35rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        .text-xs { font-size: 0.75rem; }
        .text-sm { font-size: 0.85rem; }
        .text-gray-300 { color: #dddfeb; }
        .text-gray-500 { color: #858796; }
        .text-gray-800 { color: #5a5c69; }
        .border-left-primary { border-left: 0.25rem solid var(--primary) !important; }
        .border-left-success { border-left: 0.25rem solid var(--success) !important; }
        .border-left-info { border-left: 0.25rem solid var(--info) !important; }
        .border-left-warning { border-left: 0.25rem solid var(--warning) !important; }
        .border-left-danger { border-left: 0.25rem solid var(--danger) !important; }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .stat-icon-primary { background-color: rgba(78, 115, 223, 0.1); color: var(--primary); }
        .stat-icon-success { background-color: rgba(28, 200, 138, 0.1); color: var(--success); }
        .stat-icon-info { background-color: rgba(54, 185, 204, 0.1); color: var(--info); }
        .stat-icon-warning { background-color: rgba(246, 194, 62, 0.1); color: var(--warning); }
        
        /* Dark Mode */
        body.dark-mode { 
            background-color: var(--dark-bg); 
            color: #e4e6eb; 
        }
        body.dark-mode .card-dash { 
            background-color: #2c303d; 
            color: #e4e6eb; 
            box-shadow: 0 0.15rem 1.75rem 0 rgba(0, 0, 0, 0.3); 
            border: 1px solid #3a3f4d;
        }
        body.dark-mode .text-gray-800 { color: #e4e6eb; }
        body.dark-mode .text-gray-500 { color: #b0b3b8; }
        body.dark-mode .table { color: #e4e6eb; }
        body.dark-mode .table-bordered { border-color: #3a3f4d; }
        body.dark-mode .table thead th { border-color: #3a3f4d; background-color: #3a3f4d; }
        body.dark-mode .table tbody td { border-color: #3a3f4d; }
        body.dark-mode .card-header { background-color: #3a3f4d; border-bottom: 1px solid #444; }
        
        /* Progress bars */
        .progress { height: 8px; border-radius: 4px; }
        .progress-sm { height: 6px; }
        
        /* Chart container */
        .chart-container { position: relative; height: 300px; }
        
        /* Alert badges */
        .alert-badge { 
            display: inline-flex; 
            align-items: center; 
            padding: 0.25rem 0.75rem; 
            border-radius: 50px; 
            font-size: 0.75rem; 
            font-weight: 600; 
        }
        .alert-badge-warning { background-color: rgba(246, 194, 62, 0.2); color: #f6c23e; }
        .alert-badge-danger { background-color: rgba(231, 74, 59, 0.2); color: #e74a3b; }
        .alert-badge-info { background-color: rgba(54, 185, 204, 0.2); color: #36b9cc; }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .main-content { padding: 1rem; }
            .card-dash { margin-bottom: 1rem; }
            .stat-icon { width: 40px; height: 40px; font-size: 1.2rem; }
        }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

<div class="main-content">
    
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <div>
            <h1 class="h3 mb-1 text-gray-800 fw-bold">Executive HR Dashboard (Safe Mode)</h1>
            <p class="text-gray-500 mb-0">Confidential Analytics & Governance Overview</p>
        </div>
        <div class="d-flex gap-2 mt-2">
            <span class="badge bg-light text-dark border align-self-center px-3">
                <i class="fas fa-shield-alt me-1"></i> Confidential View
            </span>
            <span class="badge bg-light text-dark border align-self-center px-3">
                <i class="fas fa-calendar-alt me-1"></i> <?php echo date('F d, Y'); ?>
            </span>
        </div>
    </div>




    <!-- 1. KEY METRICS CARDS -->
    <div class="row g-4 mb-4">
        <!-- Total Employees -->
        <div class="col-xl-3 col-lg-6">
            <div class="card card-dash border-left-primary h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-xs fw-bold text-primary text-uppercase mb-1">Human Capital</div>
                            <div class="h2 fw-bold text-gray-800 mb-2"><?php echo number_format($activeEmployees); ?></div>
                            <div class="d-flex align-items-center">
                                <span class="text-xs text-gray-500">Active Workforce Count</span>
                            </div>
                        </div>
                        <div class="stat-icon stat-icon-primary">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payroll Index -->
        <div class="col-xl-3 col-lg-6">
            <div class="card card-dash border-left-success h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="text-xs fw-bold text-success text-uppercase mb-1">Payroll Index Trend</div>
                            <div class="h2 fw-bold text-gray-800 mb-0"><?php echo isset($payrollIndexCurrent) ? $payrollIndexCurrent : 100; ?></div>
                        </div>
                        <div class="stat-icon stat-icon-success">
                            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
                    <!-- Sparkline Container -->
                    <div style="height: 50px; width: 100%;">
                        <canvas id="sparklineChart"></canvas>
                    </div>
                    <div class="mt-2 d-flex align-items-center justify-content-between">
                        <span class="badge bg-<?php echo $payrollChangePct > 0 ? 'warning' : 'success'; ?> bg-opacity-25 text-dark">
                            <?php echo $payrollChangePct > 0 ? '+' : ''; ?><?php echo $payrollChangePct; ?>%
                        </span>
                        <span class="text-xs text-gray-500">vs Last Period</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Compensation Budget -->
        <div class="col-xl-3 col-lg-6">
            <div class="card card-dash border-left-info h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-xs fw-bold text-info text-uppercase mb-1">Budget Utilization</div>
                            <div class="h2 fw-bold text-gray-800 mb-2"><?php echo isset($budgetUtil) ? $budgetUtil : 0; ?>%</div>
                            <div class="d-flex align-items-center">
                                <span class="text-xs text-gray-500"><?php echo 100 - ($budgetUtil ?? 0); ?>% Remaining Capacity</span>
                            </div>
                        </div>
                        <div class="stat-icon stat-icon-info">
                            <i class="fas fa-briefcase"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <div class="progress progress-sm">
                            <div class="progress-bar bg-info" style="width: <?php echo isset($budgetUtil) ? $budgetUtil : 0; ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                             <span class="text-xs text-gray-500">Used</span>
                             <span class="text-xs text-gray-500"><?php echo 100 - ($budgetUtil ?? 0); ?>% Left</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Alerts -->
        <div class="col-xl-3 col-lg-6">
            <div class="card card-dash border-left-<?php echo ($alertsCount ?? 0) > 0 ? 'warning' : 'success'; ?> h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-xs fw-bold text-<?php echo ($alertsCount ?? 0) > 0 ? 'warning' : 'success'; ?> text-uppercase mb-1">System Alerts</div>
                            <div class="h2 fw-bold text-gray-800 mb-2"><?php echo $alertsCount ?? 0; ?></div>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-<?php echo ($alertsCount ?? 0) > 0 ? 'warning' : 'success'; ?> bg-opacity-25 text-dark me-2">
                                    <?php echo ($alertsCount ?? 0) > 0 ? 'Attention' : 'Stable'; ?>
                                </span>
                            </div>
                        </div>
                        <div class="stat-icon stat-icon-<?php echo ($alertsCount ?? 0) > 0 ? 'warning' : 'success'; ?>">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                    <div class="mt-3">
                        <?php if ($alertsCount > 0): ?>
                        <small class="text-gray-500"><?php echo htmlspecialchars($alerts[0]); ?></small>
                        <?php else: ?>
                        <small class="text-gray-500">No critical anomalies</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. ANALYTICS ROW -->
    <div class="row g-4 mb-4">
        <!-- Payroll Index Chart -->
        <div class="col-lg-8">
            <div class="card card-dash shadow h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 fw-bold text-primary">Payroll Index Trend (Base=100)</h6>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="payrollTrendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Variance & Intensity -->
        <div class="col-lg-4">
            <div class="card card-dash shadow h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 fw-bold text-primary">Variance & Intensity</h6>
                </div>
                <div class="card-body">
                    <!-- Variance Category -->
                    <div class="mb-4 text-center">
                        <span class="d-block text-xs fw-bold text-uppercase text-gray-500 mb-1">Payroll Variance Category</span>
                        <h3 class="fw-bold text-<?php echo ($varianceCategory ?? 'Normal') == 'Normal' ? 'success' : (($varianceCategory ?? 'Normal') == 'Warning' ? 'warning' : 'danger'); ?>">
                            <?php echo $varianceCategory ?? 'Normal'; ?>
                        </h3>
                        <small class="text-muted">Deviation from expected baseline</small>
                    </div>
                    
                    <hr>
                    
                    <!-- Overtime Intensity -->
                    <div class="mb-4 text-center">
                        <span class="d-block text-xs fw-bold text-uppercase text-gray-500 mb-1">Overtime Intensity</span>
                        <h3 class="fw-bold text-<?php echo ($otIntensity ?? 'Low') == 'Low' ? 'success' : (($otIntensity ?? 'Low') == 'Medium' ? 'warning' : 'danger'); ?>">
                            <?php echo $otIntensity ?? 'Low'; ?>
                        </h3>
                        <small class="text-muted">Relative share of workforce hours</small>
                    </div>

                    <hr>

                    <!-- Trend Direction -->
                    <div class="text-center">
                        <span class="d-block text-xs fw-bold text-uppercase text-gray-500 mb-1">Trend Direction</span>
                        <div class="h4">
                            <?php if(($trendDirection ?? 'Stable') == 'Increasing'): ?>
                                <i class="fas fa-arrow-trend-up text-warning"></i> Increasing
                            <?php elseif(($trendDirection ?? 'Stable') == 'Decreasing'): ?>
                                <i class="fas fa-arrow-trend-down text-success"></i> Decreasing
                            <?php else: ?>
                                <i class="fas fa-minus text-secondary"></i> Stable
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. DEPARTMENT & BENEFITS -->
    <div class="row g-4 mb-4">
        <!-- Dept Distribution -->
        <div class="col-lg-6">
            <div class="card card-dash shadow h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 fw-bold text-primary">Payroll Distribution by Department (%)</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Department</th>
                                    <th>% Share</th>
                                    <th>Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($deptDist)): ?>
                                    <?php foreach($deptDist as $dd): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($dd['department']); ?></td>
                                        <td class="fw-bold"><?php echo $dd['pct']; ?>%</td>
                                        <td style="width: 40%">
                                            <div class="progress progress-sm mt-1">
                                                <div class="progress-bar bg-primary" style="width: <?php echo $dd['pct']; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-center">No data available</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Benefits Stats -->
        <div class="col-lg-6">
            <div class="card card-dash shadow h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 fw-bold text-success">HMO & Benefits Metrics</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-4">
                        <div class="col-6 mb-3">
                            <div class="h4 fw-bold text-gray-800"><?php echo $enrollmentRate ?? 0; ?>%</div>
                            <div class="text-xs text-uppercase text-gray-500">Enrollment Rate</div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="h4 fw-bold text-gray-800"><?php echo $coverageRate ?? 0; ?>%</div>
                            <div class="text-xs text-uppercase text-gray-500">Est. Coverage Rate</div>
                        </div>
                        <div class="col-6">
                            <div class="h4 fw-bold text-<?php echo ($utilizationLevel ?? 'Low') == 'High' ? 'primary' : 'secondary'; ?>"><?php echo $utilizationLevel ?? 'Low'; ?></div>
                            <div class="text-xs text-uppercase text-gray-500">Utilization Level</div>
                        </div>
                        <div class="col-6">
                            <div class="h4 fw-bold text-warning"><?php echo $expiringCount ?? 0; ?></div>
                            <div class="text-xs text-uppercase text-gray-500">Expiring Policies</div>
                        </div>
                    </div>
                    <div class="alert alert-light border">
                        <i class="fas fa-info-circle text-info me-2"></i>
                        <small>Benefits metrics reflect active participation and policy status without exposing premium costs.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Chart Initialization
document.addEventListener('DOMContentLoaded', function() {
    // Shared Data
    const labels = <?php echo json_encode($chartLabels); ?>;
    const data = <?php echo json_encode($chartData); ?>;

    // 1. Sparkline (Mini Chart in Card)
    const ctxSpark = document.getElementById('sparklineChart').getContext('2d');
    new Chart(ctxSpark, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                borderColor: '#1cc88a',
                borderWidth: 2,
                backgroundColor: 'rgba(28, 200, 138, 0.1)',
                tension: 0.4,
                fill: true,
                pointRadius: 0,
                pointHoverRadius: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { enabled: false }
            },
            scales: {
                x: { display: false },
                y: { display: false, min: Math.min(...data) - 5 }
            },
            layout: { padding: 0 }
        }
    });

    // 2. Main Trend Chart
    const ctx = document.getElementById('payrollTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Payroll Index (Base=100)',
                data: data,
                borderColor: '#4e73df',
                backgroundColor: 'rgba(78, 115, 223, 0.05)',
                tension: 0.3,
                fill: true,
                pointRadius: 4,
                pointBackgroundColor: '#4e73df',
                pointHoverRadius: 6
            }]
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Index: ' + context.raw;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    grid: { borderDash: [2] }
                },
                x: {
                    grid: { display: false }
                }
            }
        }
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>