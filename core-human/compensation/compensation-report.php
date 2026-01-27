<?php
// compensation_report.php - Complete Compensation Planning Report
ob_start();
session_start();
include '../includes/sidebar.php';
// Include database configuration
require_once '../config/db.php';

// ================= FETCH DATA FUNCTIONS =================

function getCompensationSummary($pdo)
{
    // Basic Payroll Stats
    $sql = "SELECT 
            COUNT(*) as total_employees,
            SUM(basic_salary) as total_monthly_basic,
            AVG(basic_salary) as avg_basic,
            SUM(basic_salary) * 13 as est_annual_cost -- Assuming 13th month
            FROM employees 
            WHERE status = 'Active'";

    try {
        $stmt = $pdo->query($sql);
        $basics = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching basic stats: " . $e->getMessage());
        $basics = ['total_employees' => 0, 'total_monthly_basic' => 0, 'avg_basic' => 0, 'est_annual_cost' => 0];
    }

    // Allowances Estimation
    $allowanceTotal = 0;
    try {
        $sqlAllowances = "SELECT 
                            (CASE WHEN am.amount_type = 'Percentage' 
                                THEN (SELECT AVG(basic_salary) FROM employees WHERE status = 'Active') * (am.amount/100)
                                ELSE am.amount END) as allowance_amount
                          FROM allowance_matrix am
                          WHERE am.status = 'Active' 
                          AND am.allowance_type IN ('Transportation', 'Meal', 'Communication', 'Housing', 'Position', 'Other')";

        $stmtAlloc = $pdo->query($sqlAllowances);
        while ($row = $stmtAlloc->fetch(PDO::FETCH_ASSOC)) {
            $allowanceTotal += $row['allowance_amount'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching allowances: " . $e->getMessage());
    }

    return [
        'headcount' => $basics['total_employees'] ?? 0,
        'monthly_basic' => $basics['total_monthly_basic'] ?? 0,
        'monthly_allowances' => $allowanceTotal,
        'monthly_total' => ($basics['total_monthly_basic'] ?? 0) + $allowanceTotal,
        'annual_total' => (($basics['total_monthly_basic'] ?? 0) + $allowanceTotal) * 12 + ($basics['total_monthly_basic'] ?? 0)
    ];
}

function getDetailedRegister($pdo)
{
    $sql = "SELECT 
                e.id, e.name, e.department, e.job_title, e.employment_status, e.basic_salary,
                (
                    SELECT COALESCE(SUM(
                        CASE WHEN am.amount_type = 'Percentage' 
                            THEN e.basic_salary * (am.amount/100)
                            ELSE am.amount END
                    ), 0)
                    FROM allowance_matrix am
                    WHERE (am.department = 'All' OR am.department = e.department)
                    AND (am.employment_type = 'All' OR am.employment_type = e.employment_status)
                    AND am.status = 'Active'
                ) as total_allowances
            FROM employees e
            WHERE e.status = 'Active'
            ORDER BY e.department, e.name";

    try {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching detailed register: " . $e->getMessage());
        return [];
    }
}

function getGradeDistribution($pdo)
{
    // Define salary grades based on your data structure
    $salaryGrades = [
        ['grade_level' => 'SG-1', 'grade_name' => 'Entry Level', 'min_salary' => 15000, 'max_salary' => 22000],
        ['grade_level' => 'SG-2', 'grade_name' => 'Junior Associate', 'min_salary' => 18000, 'max_salary' => 27000],
        ['grade_level' => 'SG-3', 'grade_name' => 'Associate', 'min_salary' => 22000, 'max_salary' => 35000],
        ['grade_level' => 'SG-4', 'grade_name' => 'Senior Associate', 'min_salary' => 28000, 'max_salary' => 45000],
        ['grade_level' => 'SG-5', 'grade_name' => 'Team Lead', 'min_salary' => 35000, 'max_salary' => 60000],
        ['grade_level' => 'SG-6', 'grade_name' => 'Manager', 'min_salary' => 45000, 'max_salary' => 80000],
        ['grade_level' => 'SG-7', 'grade_name' => 'Senior Manager', 'min_salary' => 60000, 'max_salary' => 100000],
        ['grade_level' => 'SG-8', 'grade_name' => 'Director', 'min_salary' => 80000, 'max_salary' => 130000],
    ];

    $results = [];

    try {
        foreach ($salaryGrades as $grade) {
            $sql = "SELECT 
                        COUNT(*) as employee_count,
                        AVG(basic_salary) as avg_actual_salary
                    FROM employees 
                    WHERE status = 'Active' 
                    AND basic_salary BETWEEN :min_salary AND :max_salary";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':min_salary' => $grade['min_salary'],
                ':max_salary' => $grade['max_salary']
            ]);

            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            $results[] = [
                'grade_level' => $grade['grade_level'],
                'grade_name' => $grade['grade_name'],
                'min_salary' => $grade['min_salary'],
                'max_salary' => $grade['max_salary'],
                'employee_count' => $data['employee_count'] ?? 0,
                'avg_actual_salary' => $data['avg_actual_salary'] ?? 0
            ];
        }
    } catch (PDOException $e) {
        error_log("Error fetching grade distribution: " . $e->getMessage());
    }

    return $results;
}

// Fetch data for display
$summary = getCompensationSummary($pdo);
$details = getDetailedRegister($pdo);
$grades = getGradeDistribution($pdo);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compensation Planning Report</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Additional styles for centering */
        body.compensation-report {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: #f8f9fc;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .page-wrapper {
            flex: 1;
            display: flex;
            justify-content: center;
            padding: 80px 20px 40px;
            /* Added top padding for header space */
            width: 100%;
        }

        /* Main Container - Centered and Responsive */
        .container-fluid {
            width: 100%;
            max-width: 1400px;
            /* Increased max-width for better readability */
            margin: 0 auto;
            padding: 0 20px;
            box-sizing: border-box;
        }

        /* Report Header - Centered and Clean */
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 25px 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
            border-left: 5px solid #4e73df;
            flex-wrap: wrap;
            gap: 15px;
        }

        .report-header>div:first-child {
            flex: 1;
            min-width: 300px;
        }

        .report-header>div:last-child {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .report-header h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .report-header p {
            margin: 8px 0 0;
            color: #6c757d;
            font-size: 1rem;
            line-height: 1.5;
        }

        /* Stats Grid - Centered Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 5px solid #4e73df;
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            margin: 0 0 12px 0;
            color: #5a5c69;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .stat-card .value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #2e343a;
            line-height: 1.2;
        }

        /* Report Sections - Centered Content */
        .report-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 35px;
            overflow: hidden;
        }

        .report-section .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f1f1;
        }

        .report-section h2 {
            font-size: 1.3rem;
            margin: 0;
            color: #2c3e50;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Tables - Centered with proper spacing */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        thead {
            background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
        }

        th {
            padding: 16px 20px;
            text-align: left;
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            border-bottom: none;
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid #eef0f7;
            vertical-align: middle;
        }

        tbody tr {
            transition: background-color 0.2s ease;
        }

        tbody tr:hover {
            background-color: #f8fafd;
        }

        tbody tr:nth-child(even) {
            background-color: #fcfdfe;
        }

        tbody tr:nth-child(even):hover {
            background-color: #f8fafd;
        }

        /* Amount columns */
        .amount {
            text-align: right;
            font-family: 'SF Mono', 'Monaco', 'Roboto Mono', monospace;
            font-weight: 600;
            color: #2e343a;
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-regular {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-contract {
            background-color: #e0f2fe;
            color: #0c4a6e;
        }

        .status-probationary {
            background-color: #fef3c7;
            color: #92400e;
        }

        /* Print Report Button - Centered in container */
        .btn-print {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            min-width: 140px;
            background: linear-gradient(135deg, #36b9cc 0%, #2c9faf 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(54, 185, 204, 0.3);
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(54, 185, 204, 0.4);
            background: linear-gradient(135deg, #2c9faf 0%, #248895 100%);
        }

        .btn-print:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(54, 185, 204, 0.3);
        }

        /* Compa-Ratio colors */
        .compa-red {
            color: #e74a3b;
            font-weight: 700;
        }

        .compa-green {
            color: #1cc88a;
            font-weight: 700;
        }

        /* Print styles */
        @media print {
            body.compensation-report {
                background: white !important;
                padding: 0 !important;
            }

            .page-wrapper {
                padding: 0 !important;
                display: block;
            }

            .container-fluid {
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .report-header {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-after: avoid;
            }

            .report-section {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }

            .btn-print {
                display: none !important;
            }

            table {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }

            thead {
                background: #f8f9fc !important;
                color: #000 !important;
            }

            th {
                color: #000 !important;
                border-bottom: 2px solid #000 !important;
            }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .container-fluid {
                padding: 0 15px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-wrapper {
                padding: 60px 15px 30px;
            }

            .report-header {
                padding: 20px;
                flex-direction: column;
                text-align: center;
            }

            .report-header>div:first-child {
                min-width: auto;
            }

            .report-header>div:last-child {
                width: 100%;
                justify-content: center;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stat-card {
                padding: 20px;
            }

            .report-section {
                padding: 20px;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th,
            td {
                padding: 12px 15px;
            }

            .btn-print {
                min-width: 120px;
                padding: 10px 18px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .report-header h1 {
                font-size: 1.5rem;
            }

            .stat-card .value {
                font-size: 1.5rem;
            }

            .btn-print {
                min-width: 100%;
                margin-bottom: 10px;
            }

            .report-header>div:last-child {
                flex-direction: column;
            }
        }
    </style>
</head>

<body class="compensation-report">
    <div class="page-wrapper">
        <div class="container-fluid">

            <!-- Report Header -->
            <div class="report-header">
                <div>
                    <h1><i class="fas fa-chart-line"></i> Compensation Planning Report</h1>
                    <p>Comprehensive analysis of salary grades, allowances, and payroll costs. Generated on <?php echo date('F j, Y'); ?></p>
                </div>
                <div>
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>

            <!-- Executive Summary Stats -->
            <div class="stats-grid">
                <div class="stat-card" style="border-left-color: #4e73df;">
                    <h3>Total Headcount</h3>
                    <div class="value"><?php echo number_format($summary['headcount']); ?></div>
                </div>
                <div class="stat-card" style="border-left-color: #1cc88a;">
                    <h3>Monthly Basic Pay</h3>
                    <div class="value">₱<?php echo number_format($summary['monthly_basic'], 2); ?></div>
                </div>
                <div class="stat-card" style="border-left-color: #36b9cc;">
                    <h3>Monthly Allowances</h3>
                    <div class="value">₱<?php echo number_format($summary['monthly_allowances'], 2); ?></div>
                </div>
                <div class="stat-card" style="border-left-color: #f6c23e;">
                    <h3>Est. Annual Cost</h3>
                    <div class="value">₱<?php echo number_format($summary['annual_total'], 2); ?></div>
                </div>
            </div>

            <!-- Salary Grade Distribution -->
            <div class="report-section">
                <div class="section-header">
                    <h2><i class="fas fa-layer-group"></i> Salary Grade Distribution</h2>
                    <span style="color: #6c757d; font-size: 0.9rem;">
                        <?php echo count($grades); ?> Grade Levels Analyzed
                    </span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Grade Level</th>
                            <th>Grade Name</th>
                            <th>Salary Range</th>
                            <th style="text-align:center;">Employee Count</th>
                            <th class="amount">Avg. Actual Salary</th>
                            <th class="amount">Compa-Ratio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($grades)): ?>
                            <?php foreach ($grades as $grade):
                                $midpoint = ($grade['min_salary'] + $grade['max_salary']) / 2;
                                $compaRatio = ($midpoint > 0 && $grade['avg_actual_salary'] > 0) ?
                                    ($grade['avg_actual_salary'] / $midpoint) * 100 : 0;
                                $compaClass = ($compaRatio > 100) ? 'compa-red' : 'compa-green';
                            ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($grade['grade_level']); ?></td>
                                    <td><?php echo htmlspecialchars($grade['grade_name']); ?></td>
                                    <td>₱<?php echo number_format($grade['min_salary']) . ' - ₱' . number_format($grade['max_salary']); ?></td>
                                    <td style="text-align:center; font-weight: 600;">
                                        <?php echo $grade['employee_count']; ?>
                                    </td>
                                    <td class="amount">
                                        <?php echo ($grade['avg_actual_salary'] > 0) ? '₱' . number_format($grade['avg_actual_salary'], 2) : 'N/A'; ?>
                                    </td>
                                    <td class="amount <?php echo $compaClass; ?>">
                                        <?php echo number_format($compaRatio, 1); ?>%
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding: 40px; color: #6c757d;">
                                    <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 15px; display: block;"></i>
                                    No salary grade data available. Please set up salary grades in the system.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Detailed Compensation Register -->
            <div class="report-section">
                <div class="section-header">
                    <h2><i class="fas fa-list-alt"></i> Detailed Compensation Register</h2>
                    <span style="color: #6c757d; font-size: 0.9rem;">
                        <?php echo count($details); ?> Active Employees
                    </span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th class="amount">Basic Salary</th>
                            <th class="amount">Allowances</th>
                            <th class="amount">Total Monthly</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($details)): ?>
                            <?php foreach ($details as $row):
                                $totalMonthly = $row['basic_salary'] + $row['total_allowances'];
                                $statusClass = 'status-' . strtolower($row['employment_status']);
                            ?>
                                <tr>
                                    <td style="font-weight: 600;"><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($row['employment_status']); ?>
                                        </span>
                                    </td>
                                    <td class="amount">₱<?php echo number_format($row['basic_salary'], 2); ?></td>
                                    <td class="amount">₱<?php echo number_format($row['total_allowances'], 2); ?></td>
                                    <td class="amount" style="font-weight: 800; color: #2c3e50;">
                                        ₱<?php echo number_format($totalMonthly, 2); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align:center; padding: 40px; color: #6c757d;">
                                    <i class="fas fa-users-slash" style="font-size: 2rem; margin-bottom: 15px; display: block;"></i>
                                    No active employee records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Report Footer -->
            <div style="text-align: center; margin-top: 40px; padding: 20px; color: #6c757d; font-size: 0.9rem;">
                <p>This report is generated from the HR Compensation System. Data is current as of <?php echo date('F j, Y H:i'); ?>.</p>
                <p style="margin-top: 5px;">For questions about this report, contact the HR Compensation Department.</p>
            </div>

        </div>
    </div>

    <script>
        // Print Report functionality with confirmation
        document.querySelector('.btn-print').addEventListener('click', function(e) {
            e.preventDefault();

            // Show print preview
            if (confirm('Open print preview? Make sure your printer is ready.')) {
                window.print();
            }
        });

        // Auto-refresh data every 10 minutes
        setTimeout(function() {
            if (confirm('The report data may be outdated. Would you like to refresh the page?')) {
                window.location.reload();
            }
        }, 600000); // 10 minutes

        // Add keyboard shortcut for printing (Ctrl+P)
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                document.querySelector('.btn-print').click();
            }
        });
    </script>
</body>

</html>
<?php ob_end_flush(); ?>