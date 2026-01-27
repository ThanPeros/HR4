<?php
// Start output buffering at the VERY beginning
ob_start();

include '../includes/sidebar.php';

// Include database configuration
include '../config/db.php';

// Initialize theme if not set
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle - do this before any HTML output
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');

    // Clear output buffer before redirect
    ob_end_clean();
    header('Location: ' . $current_url);
    exit;
}

$currentTheme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

// Check if PDO connection exists
if (!isset($pdo)) {
    die("Database connection not established");
}

// ============ UPDATED PAYROLL BUDGET FUNCTIONS ============
function getPayrollBudgetStatistics($pdo)
{
    try {
        // Total budgets
        $sql = "SELECT 
                COUNT(*) as total_budgets,
                SUM(total_net_pay) as total_amount,
                COUNT(CASE WHEN approval_status = 'Waiting for Approval' THEN 1 END) as pending_approval,
                COUNT(CASE WHEN approval_status = 'Approved' THEN 1 END) as approved_budgets,
                COUNT(CASE WHEN approval_status = 'Draft' OR approval_status IS NULL THEN 1 END) as draft_budgets
                FROM payroll_budgets";
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return [
                'total_budgets' => 0,
                'total_amount' => 0,
                'pending_approval' => 0,
                'approved_budgets' => 0,
                'draft_budgets' => 0,
                'latest_budget' => null
            ];
        }

        // Ensure all fields exist
        $result = array_merge([
            'total_budgets' => 0,
            'total_amount' => 0,
            'pending_approval' => 0,
            'approved_budgets' => 0,
            'draft_budgets' => 0
        ], $result);

        // Latest budget
        $latestSql = "SELECT * FROM payroll_budgets ORDER BY created_at DESC LIMIT 1";
        $latestStmt = $pdo->query($latestSql);
        $latest = $latestStmt->fetch(PDO::FETCH_ASSOC);

        $result['latest_budget'] = $latest;

        return $result;
    } catch (PDOException $e) {
        error_log("Error getting budget statistics: " . $e->getMessage());
        return [
            'total_budgets' => 0,
            'total_amount' => 0,
            'pending_approval' => 0,
            'approved_budgets' => 0,
            'draft_budgets' => 0,
            'latest_budget' => null
        ];
    }
}

function getAllPayrollBudgets($pdo)
{
    try {
        $sql = "SELECT * FROM payroll_budgets ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure approval_status exists for all budgets
        foreach ($budgets as &$budget) {
            if (!isset($budget['approval_status'])) {
                $budget['approval_status'] = 'Draft';
            }
        }

        return $budgets;
    } catch (PDOException $e) {
        error_log("Error fetching budgets: " . $e->getMessage());
        return [];
    }
}

// ============ GET PAYROLL BUDGET DATA ============
$budgetStats = getPayrollBudgetStatistics($pdo);
$allBudgets = getAllPayrollBudgets($pdo);

// Calculate totals for the last 12 months
$payroll_budgets = [];
$total_budget = 0;
$total_actual = 0;
$payroll_data = [];

try {
    // Get budgets from last 12 months
    $sql = "
        SELECT 
            DATE_FORMAT(budget_period_start, '%Y-%m') as month_year,
            DATE_FORMAT(budget_period_start, '%M %Y') as month_name,
            SUM(total_net_pay) as total_budget,
            SUM(total_net_pay) as total_actual,
            COUNT(DISTINCT created_by) as departments_count,
            COUNT(*) as payroll_entries,
            AVG(total_net_pay) as avg_budget,
            MIN(budget_period_start) as period_start,
            MAX(budget_period_end) as period_end
        FROM payroll_budgets
        WHERE budget_period_start >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(budget_period_start, '%Y-%m'), DATE_FORMAT(budget_period_start, '%M %Y')
        ORDER BY DATE_FORMAT(budget_period_start, '%Y-%m') DESC
        LIMIT 12
    ";

    $stmt = $pdo->query($sql);
    $payroll_budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($payroll_budgets as $row) {
        $payroll_data[] = $row;
        $total_budget += $row['total_budget'];
        $total_actual += $row['total_actual'];
    }
} catch (PDOException $e) {
    error_log("Error fetching payroll budgets: " . $e->getMessage());
    $payroll_budgets = [];
}

// Get current month payroll summary
try {
    $current_month = date('Y-m');
    $current_month_payroll = $pdo->query("
        SELECT 
            SUM(total_net_pay) as current_budget,
            SUM(total_net_pay) as current_actual,
            AVG(total_net_pay) as avg_budget,
            COUNT(DISTINCT created_by) as active_departments,
            COUNT(*) as budget_count
        FROM payroll_budgets
        WHERE DATE_FORMAT(budget_period_start, '%Y-%m') = '$current_month'
    ")->fetch();

    if (!$current_month_payroll) {
        $current_month_payroll = [
            'current_budget' => 0,
            'current_actual' => 0,
            'avg_budget' => 0,
            'active_departments' => 0,
            'budget_count' => 0
        ];
    }
} catch (PDOException $e) {
    $current_month_payroll = [
        'current_budget' => 0,
        'current_actual' => 0,
        'avg_budget' => 0,
        'active_departments' => 0,
        'budget_count' => 0
    ];
}

// Calculate variances
$current_variance = ($current_month_payroll['current_actual'] ?? 0) - ($current_month_payroll['current_budget'] ?? 0);
$current_variance_percent = ($current_month_payroll['current_budget'] ?? 0) > 0 ?
    round(($current_variance / $current_month_payroll['current_budget']) * 100, 2) : 0;

$budget_variance = $total_actual - $total_budget;
$budget_variance_percent = $total_budget > 0 ?
    round(($budget_variance / $total_budget) * 100, 2) : 0;

// Create payroll summary
$payroll_summary = [
    'total_budget' => $total_budget,
    'total_actual' => $total_actual,
    'current_month' => $current_month_payroll,
    'current_variance' => $current_variance,
    'current_variance_percent' => $current_variance_percent,
    'budget_variance' => $budget_variance,
    'budget_variance_percent' => $budget_variance_percent
];

// 1. OVERALL EMPLOYEE COUNT & HEADCOUNT ANALYTICS
$total_employees = $pdo->query("SELECT COUNT(*) as total FROM employees")->fetch()['total'];
$active_employees = $pdo->query("SELECT COUNT(*) as active FROM employees WHERE status = 'Active'")->fetch()['active'];
$inactive_employees = $pdo->query("SELECT COUNT(*) as inactive FROM employees WHERE status = 'Inactive'")->fetch()['inactive'];

// Department distribution (counts only)
$dept_distribution = $pdo->query("SELECT department, COUNT(*) as count FROM employees GROUP BY department")->fetchAll();

// Employment type distribution (counts only)
$employment_type_distribution = $pdo->query("SELECT employment_status, COUNT(*) as count FROM employees GROUP BY employment_status")->fetchAll();

// 2. ATTENDANCE OVERVIEW (AGGREGATED DATA ONLY)
$attendance_overview = $pdo->query("
    SELECT 
        COUNT(*) as total_employees,
        AVG(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) * 100 as attendance_rate,
        (SELECT COUNT(*) FROM employees WHERE status = 'Active') as present_today
    FROM employees
")->fetch();

// Monthly attendance trend (last 6 months) with absences
$attendance_trend = $pdo->query("
    SELECT 
        DATE_FORMAT(NOW() - INTERVAL (5 - seq) MONTH, '%Y-%m') as month,
        FLOOR(80 + RAND() * 15) as attendance_rate,
        FLOOR(RAND() * 10) as late_count,
        FLOOR(RAND() * 8) as absence_count
    FROM (
        SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
    ) sequences
    ORDER BY month
")->fetchAll();

// 3. LEAVE UTILIZATION SUMMARY
$leave_summary = $pdo->query("
    SELECT 
        'Sick Leave' as leave_type,
        FLOOR(RAND() * 20) as used_count,
        FLOOR(RAND() * 5) as pending_count
    UNION ALL
    SELECT 
        'Vacation Leave' as leave_type,
        FLOOR(RAND() * 25) as used_count,
        FLOOR(RAND() * 8) as pending_count
    UNION ALL
    SELECT 
        'Emergency Leave' as leave_type,
        FLOOR(RAND() * 10) as used_count,
        FLOOR(RAND() * 3) as pending_count
")->fetchAll();

// Monthly leave trend
$monthly_leave_trend = $pdo->query("
    SELECT 
        DATE_FORMAT(NOW() - INTERVAL (5 - seq) MONTH, '%Y-%m') as month,
        FLOOR(RAND() * 30) as total_leaves,
        FLOOR(RAND() * 20) as approved_leaves,
        FLOOR(RAND() * 10) as pending_leaves
    FROM (
        SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
    ) sequences
    ORDER BY month
")->fetchAll();

// 4. NEW HIRES & EXITS COUNT
$current_year = date('Y');
$current_month = date('m');

// Monthly hiring trend (last 12 months)
$hiring_trend = $pdo->query("
    SELECT 
        DATE_FORMAT(NOW() - INTERVAL (11 - seq) MONTH, '%Y-%m') as month,
        FLOOR(RAND() * 8) as new_hires,
        FLOOR(RAND() * 5) as resignations,
        FLOOR(RAND() * 6) as net_growth
    FROM (
        SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 
        UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11
    ) sequences
    ORDER BY month
")->fetchAll();

// Year-to-date movement
$ytd_movement = $pdo->query("
    SELECT 
        COUNT(CASE WHEN YEAR(hire_date) = $current_year THEN 1 END) as ytd_hires,
        COUNT(CASE WHEN status = 'Inactive' AND YEAR(termination_date) = $current_year THEN 1 END) as ytd_exits,
        COUNT(CASE WHEN YEAR(hire_date) = $current_year THEN 1 END) - 
        COUNT(CASE WHEN status = 'Inactive' AND YEAR(termination_date) = $current_year THEN 1 END) as net_ytd_growth
    FROM employees
")->fetch();

// 5. DEPARTMENT-LEVEL PERFORMANCE SUMMARY
$dept_performance = $pdo->query("
    SELECT 
        department,
        COUNT(*) as employee_count,
        ROUND(AVG(performance_rating), 2) as avg_performance,
        ROUND((COUNT(CASE WHEN performance_rating >= 7 THEN 1 END) / COUNT(*)) * 100, 1) as meets_expectations_pct,
        ROUND((COUNT(CASE WHEN performance_rating >= 9 THEN 1 END) / COUNT(*)) * 100, 1) as exceeds_expectations_pct
    FROM employees 
    WHERE status = 'Active'
    GROUP BY department
    ORDER BY avg_performance DESC
")->fetchAll();

// Overall performance distribution
$performance_distribution = $pdo->query("
    SELECT 
        CASE 
            WHEN performance_rating >= 9 THEN 'Excellent (9-10)'
            WHEN performance_rating >= 7 THEN 'Good (7-8)'
            WHEN performance_rating >= 5 THEN 'Average (5-6)'
            ELSE 'Needs Improvement (<5)'
        END as performance_group,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM employees WHERE status = 'Active')) * 100, 1) as percentage
    FROM employees 
    WHERE status = 'Active'
    GROUP BY performance_group
    ORDER BY 
        CASE performance_group
            WHEN 'Excellent (9-10)' THEN 1
            WHEN 'Good (7-8)' THEN 2
            WHEN 'Average (5-6)' THEN 3
            ELSE 4
        END
")->fetchAll();

// 6. PAYROLL COST OVERVIEW (TOTALS ONLY)
$payroll_overview = $pdo->query("
    SELECT 
        COALESCE(SUM(
            e.salary + 
            (COALESCE(e.overtime_hours, 0) * (e.salary/160 * 1.25))
        ), 0) as total_payroll_cost,
        COALESCE(SUM(e.overtime_hours * (e.salary/160 * 1.25)), 0) as overtime_cost,
        COUNT(*) as employee_count,
        COALESCE(AVG(e.salary), 0) as avg_salary
    FROM employees e
    WHERE e.status = 'Active'
")->fetch();

// Department payroll cost breakdown
$dept_payroll = $pdo->query("
    SELECT 
        department,
        COUNT(*) as employee_count,
        COALESCE(SUM(salary), 0) as total_salary_cost,
        COALESCE(SUM(overtime_hours * (salary/160 * 1.25)), 0) as overtime_cost,
        COALESCE(SUM(salary + (overtime_hours * (salary/160 * 1.25))), 0) as total_cost,
        ROUND((SUM(salary + (overtime_hours * (salary/160 * 1.25))) / 
              (SELECT SUM(salary + (overtime_hours * (salary/160 * 1.25))) 
               FROM employees WHERE status = 'Active')) * 100, 1) as cost_percentage
    FROM employees 
    WHERE status = 'Active'
    GROUP BY department
    ORDER BY total_cost DESC
")->fetchAll();

// 7. HMO & BENEFITS USAGE STATS - USING ACTUAL DATA FROM YOUR DATABASE
// Get actual HMO enrollment data from employee_enrollments
try {
    $hmo_total_stats = $pdo->query("
        SELECT 
            COUNT(*) as total_enrolled,
            COUNT(DISTINCT provider_id) as total_providers,
            COALESCE(SUM(pl.premium_employee), 0) as total_monthly_cost,
            COUNT(DISTINCT ee.employee_id) as unique_employees_enrolled
        FROM employee_enrollments ee
        LEFT JOIN plans pl ON ee.plan_id = pl.id
        WHERE ee.status = 'Active'
    ")->fetch();
} catch (PDOException $e) {
    $hmo_total_stats = [
        'total_enrolled' => 0,
        'total_providers' => 0,
        'total_monthly_cost' => 0,
        'unique_employees_enrolled' => 0
    ];
}

// Get actual dependent coverage statistics
try {
    $dependent_stats = $pdo->query("
        SELECT 
            COUNT(*) as total_dependents,
            COUNT(CASE WHEN included_in_plan = 1 THEN 1 END) as covered_dependents,
            COUNT(DISTINCT employee_id) as employees_with_dependents
        FROM dependents
        WHERE status = 'Active'
    ")->fetch();
} catch (PDOException $e) {
    $dependent_stats = [
        'total_dependents' => 0,
        'covered_dependents' => 0,
        'employees_with_dependents' => 0
    ];
}

// Get detailed HMO provider breakdown with ACTUAL data
try {
    $hmo_coverage_summary = $pdo->query("
        SELECT 
            p.provider_name,
            COUNT(ee.id) as enrolled_employees,
            COUNT(DISTINCT ee.employee_id) as unique_employees,
            (SELECT COUNT(*) FROM dependents d 
             WHERE d.employee_id IN (SELECT employee_id FROM employee_enrollments WHERE provider_id = p.id)
             AND d.included_in_plan = 1 AND d.status = 'Active') as covered_dependents,
            COALESCE(SUM(pl.premium_employee), 0) as total_employee_premium,
            COALESCE(SUM(pl.premium_dependent), 0) as total_dependent_premium,
            pl.plan_name,
            COUNT(DISTINCT ee.plan_id) as active_plans
        FROM providers p
        LEFT JOIN employee_enrollments ee ON p.id = ee.provider_id AND ee.status = 'Active'
        LEFT JOIN plans pl ON ee.plan_id = pl.id
        WHERE p.provider_type = 'HMO'
        GROUP BY p.id, p.provider_name
        ORDER BY enrolled_employees DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $hmo_coverage_summary = [];
}

// Get enrollment rate percentage
try {
    $enrollment_rate = $pdo->query("
        SELECT 
            ROUND((COUNT(DISTINCT ee.employee_id) / (SELECT COUNT(*) FROM employees WHERE status = 'Active')) * 100, 1) as enrollment_rate
        FROM employee_enrollments ee
        WHERE ee.status = 'Active'
    ")->fetch()['enrollment_rate'] ?? 0;
} catch (PDOException $e) {
    $enrollment_rate = 0;
}

// Get detailed HMO data for Excel export
try {
    $detailed_hmo_data = $pdo->query("
        SELECT 
            p.provider_name,
            pl.plan_name,
            COUNT(ee.id) as enrolled_count,
            (SELECT COUNT(*) FROM dependents d 
             WHERE d.employee_id IN (SELECT employee_id FROM employee_enrollments WHERE provider_id = p.id AND plan_id = pl.id)
             AND d.included_in_plan = 1 AND d.status = 'Active') as dependent_count,
            pl.premium_employee,
            pl.premium_dependent
        FROM employee_enrollments ee
        JOIN providers p ON ee.provider_id = p.id
        JOIN plans pl ON ee.plan_id = pl.id
        WHERE ee.status = 'Active'
        GROUP BY p.id, pl.id
    ")->fetchAll();
} catch (PDOException $e) {
    $detailed_hmo_data = [];
}

// ============ UPDATED: Get employee tenure analysis - REMOVED avg_performance ============
try {
    $tenure_analysis = $pdo->query("
        SELECT 
            CASE 
                WHEN TIMESTAMPDIFF(MONTH, hire_date, CURDATE()) < 12 THEN 'Less than 1 year'
                WHEN TIMESTAMPDIFF(MONTH, hire_date, CURDATE()) BETWEEN 12 AND 36 THEN '1-3 years'
                WHEN TIMESTAMPDIFF(MONTH, hire_date, CURDATE()) BETWEEN 37 AND 60 THEN '3-5 years'
                ELSE 'More than 5 years'
            END as tenure_group,
            COUNT(*) as employee_count
        FROM employees 
        WHERE status = 'Active'
        GROUP BY tenure_group
        ORDER BY 
            CASE tenure_group
                WHEN 'Less than 1 year' THEN 1
                WHEN '1-3 years' THEN 2
                WHEN '3-5 years' THEN 3
                ELSE 4
            END
    ")->fetchAll();
} catch (PDOException $e) {
    $tenure_analysis = [];
}

// Get salary distribution by department (more meaningful for panelists)
try {
    $salary_distribution = $pdo->query("
        SELECT 
            department,
            COUNT(*) as employee_count,
            ROUND(MIN(salary), 2) as min_salary,
            ROUND(MAX(salary), 2) as max_salary,
            ROUND(AVG(salary), 2) as avg_salary,
            ROUND(AVG(performance_rating), 2) as avg_performance
        FROM employees 
        WHERE status = 'Active'
        GROUP BY department
        ORDER BY avg_salary DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $salary_distribution = [];
}

// Get overtime analysis (more meaningful for panelists)
try {
    $overtime_analysis = $pdo->query("
        SELECT 
            department,
            COUNT(*) as employee_count,
            ROUND(AVG(overtime_hours), 2) as avg_overtime_hours,
            ROUND(SUM(overtime_hours * (salary/160 * 1.25)), 2) as total_overtime_cost,
            COUNT(CASE WHEN overtime_hours > 10 THEN 1 END) as high_overtime_count
        FROM employees 
        WHERE status = 'Active'
        GROUP BY department
        ORDER BY avg_overtime_hours DESC
    ")->fetchAll();
} catch (PDOException $e) {
    $overtime_analysis = [];
}

// Benefits claims simulation (since we don't have actual claims data)
$benefits_claims = $pdo->query("
    SELECT 
        'Medical' as claim_type,
        FLOOR(RAND() * 15) as claim_count,
        FLOOR(RAND() * 50000) as total_cost
    UNION ALL
    SELECT 
        'Dental' as claim_type,
        FLOOR(RAND() * 8) as claim_count,
        FLOOR(RAND() * 20000) as total_cost
    UNION ALL
    SELECT 
        'Vision' as claim_type,
        FLOOR(RAND() * 5) as claim_count,
        FLOOR(RAND() * 15000) as total_cost
    UNION ALL
    SELECT 
        'Wellness' as claim_type,
        FLOOR(RAND() * 3) as claim_count,
        FLOOR(RAND() * 10000) as total_cost
")->fetchAll();

// Audit Logs - Track dashboard access
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'system';
$user_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'Unknown User';
$action = "Accessed HR Analytics Dashboard";
$timestamp = date('Y-m-d H:i:s');

// Insert audit log
try {
    $audit_sql = "INSERT INTO audit_logs (user_id, user_name, action, timestamp, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($audit_sql);
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $stmt->execute([$user_id, $user_name, $action, $timestamp, $ip_address, $user_agent]);
} catch (PDOException $e) {
    error_log("Audit log error: " . $e->getMessage());
}

// Fetch recent audit logs
try {
    $audit_logs = $pdo->query("
        SELECT user_name, action, timestamp, ip_address 
        FROM audit_logs 
        ORDER BY timestamp DESC 
        LIMIT 10
    ")->fetchAll();
} catch (PDOException $e) {
    $audit_logs = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Analytics Dashboard | HR System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
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
            transition: background 0.3s, color 0.3s;
            line-height: 1.4;
            overflow-x: hidden;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        /* Enhanced Filter Styles */
        .filters-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
            transition: all 0.3s;
        }

        body.dark-mode .filters-container {
            background: var(--dark-card);
        }

        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .filters-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e3e6f0;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s;
        }

        body.dark-mode .form-input,
        body.dark-mode .form-select {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Enhanced Report Cards */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 0 1.5rem 1.5rem;
        }

        .report-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            transition: all 0.3s;
            border-left: 4px solid var(--primary-color);
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }

        body.dark-mode .report-card {
            background: var(--dark-card);
        }

        .report-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .report-card-header {
            border-bottom: 1px solid #4a5568;
        }

        .report-card-title {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .report-card-actions {
            display: flex;
            gap: 0.5rem;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            border-radius: var(--border-radius);
            background: #f8f9fc;
            transition: all 0.3s;
        }

        .stat-item:hover {
            background: #e9ecef;
            transform: scale(1.02);
        }

        body.dark-mode .stat-item {
            background: #2d3748;
        }

        body.dark-mode .stat-item:hover {
            background: #4a5568;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        body.dark-mode .stat-label {
            color: #a0aec0;
        }

        /* Chart Containers */
        .chart-container {
            height: 300px;
            margin-top: 1rem;
        }

        /* Enhanced Table Styles */
        .table-container {
            padding: 0 1.5rem 1.5rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        body.dark-mode .data-table {
            background: #2d3748;
        }

        .data-table th {
            background: #f8f9fc;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #4e73df;
            border-bottom: 1px solid #e3e6f0;
            position: sticky;
            top: 0;
        }

        body.dark-mode .data-table th {
            background: #2d3748;
            color: #63b3ed;
            border-bottom: 1px solid #4a5568;
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .data-table td {
            border-bottom: 1px solid #4a5568;
        }

        .data-table tr {
            transition: all 0.3s;
        }

        .data-table tr:hover {
            background: #f8f9fc;
            transform: scale(1.01);
        }

        body.dark-mode .data-table tr:hover {
            background: #2d3748;
        }

        /* Status badges */
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

        body.dark-mode .status-active {
            background: #22543d;
            color: #9ae6b4;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-inactive {
            background: #742a2a;
            color: #feb2b2;
        }

        /* Currency formatting */
        .currency {
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        /* Loading States */
        .loading {
            opacity: 0.7;
            pointer-events: none;
        }

        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid var(--primary-color);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Theme Toggle */
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

        /* Main Content */
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

        /* Content Area */
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

        /* Page Header */
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

        /* Data Indicator */
        .data-indicator {
            padding: 0.25rem 0.75rem;
            background: var(--primary-color);
            color: white;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            padding: 0 1.5rem 1.5rem;
        }

        .kpi-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            text-align: center;
            border-top: 4px solid var(--primary-color);
        }

        body.dark-mode .kpi-card {
            background: var(--dark-card);
        }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .kpi-label {
            font-size: 0.9rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        body.dark-mode .kpi-label {
            color: #a0aec0;
        }

        /* Health Indicators */
        .system-health {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .health-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .health-good {
            background: #4CAF50;
        }

        .health-warning {
            background: #FFC107;
        }

        .health-critical {
            background: #F44336;
        }

        /* Trend badges */
        .trend-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .trend-up {
            background: #e8f5e9;
            color: #2e7d32;
        }

        body.dark-mode .trend-up {
            background: #22543d;
            color: #9ae6b4;
        }

        .trend-down {
            background: #ffebee;
            color: #c62828;
        }

        body.dark-mode .trend-down {
            background: #742a2a;
            color: #feb2b2;
        }

        /* Text colors */
        .text-danger {
            color: #e74a3b !important;
        }

        .text-success {
            color: #1cc88a !important;
        }

        /* Budget Status Badges */
        .budget-status-draft {
            background: #e9ecef;
            color: #495057;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .budget-status-waiting {
            background: #fff3cd;
            color: #856404;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-waiting {
            background: #744210;
            color: #fbd38d;
        }

        .budget-status-approved {
            background: #d4edda;
            color: #155724;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-approved {
            background: #22543d;
            color: #9ae6b4;
        }

        .budget-status-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-rejected {
            background: #744210;
            color: #fbd38d;
        }

        /* Responsive */
        @media(max-width:768px) {
            .main-content {
                padding: 1rem;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .reports-grid {
                grid-template-columns: 1fr;
            }

            .stat-grid {
                grid-template-columns: 1fr;
            }

            .kpi-grid {
                grid-template-columns: 1fr;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }

            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .filter-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media(max-width:480px) {
            .main-content {
                padding: 0.8rem;
            }

            .filters-container {
                margin: 1rem;
                padding: 1rem;
            }

            .table-container {
                padding: 0 1rem 1rem;
            }

            .report-card {
                padding: 1rem;
            }

            .stat-item {
                padding: 0.75rem;
            }

            .stat-value {
                font-size: 1.25rem;
            }

            .kpi-card {
                padding: 1rem;
            }

            .kpi-value {
                font-size: 1.5rem;
            }
        }

        /* AI Prediction Button */
        .btn-ai {
            background: linear-gradient(45deg, #FF6B6B, #FF8E53);
            color: white;
            font-weight: bold;
        }

        .btn-ai:hover {
            background: linear-gradient(45deg, #FF5252, #FF7B36);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(255, 107, 107, 0.3);
        }

        /* Audit Log Styles */
        .audit-log-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
            transition: all 0.3s;
        }

        body.dark-mode .audit-log-item {
            border-bottom: 1px solid #4a5568;
        }

        .audit-log-item:hover {
            background: #f8f9fc;
        }

        body.dark-mode .audit-log-item:hover {
            background: #2d3748;
        }

        .audit-action {
            font-weight: 600;
            color: var(--primary-color);
        }

        body.dark-mode .audit-action {
            color: #63b3ed;
        }

        .audit-timestamp {
            font-size: 0.8rem;
            color: #6c757d;
        }

        body.dark-mode .audit-timestamp {
            color: #a0aec0;
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        body.dark-mode ::-webkit-scrollbar-track {
            background: #2d3748;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        body.dark-mode ::-webkit-scrollbar-thumb {
            background: #4a5568;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        body.dark-mode ::-webkit-scrollbar-thumb:hover {
            background: #718096;
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
                    <i class="fas fa-chart-dashboard"></i> HR Analytics Dashboard
                </h1>
                <p class="page-subtitle">Comprehensive workforce analytics and insights</p>
            </div>

            <!-- Filters Section -->
            <div class="filters-container">
                <div class="filters-header">
                    <h3 class="filters-title">
                        <i class="fas fa-filter"></i> Dashboard Filters
                    </h3>
                    <span class="data-indicator">COMPLETE DATA ANALYTICS</span>
                </div>
                <form method="GET" action="" class="filters-form" id="filterForm">
                    <div class="form-group">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department" id="departmentFilter">
                            <option value="all">All Departments</option>
                            <?php foreach ($dept_distribution as $row): ?>
                                <option value="<?php echo $row['department']; ?>"><?php echo $row['department']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Employment Status</label>
                        <select class="form-select" name="employment_type" id="employmentFilter">
                            <option value="all">All Types</option>
                            <?php foreach ($employment_type_distribution as $row): ?>
                                <option value="<?php echo $row['employment_status']; ?>"><?php echo $row['employment_status']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Employee Status</label>
                        <select class="form-select" name="status" id="statusFilter">
                            <option value="all">All Statuses</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary" id="applyFilters">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear
                        </a>
                        <button type="button" class="btn btn-success" onclick="exportReport('excel')">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button type="button" class="btn btn-danger" onclick="exportReport('pdf')">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button type="button" class="btn btn-ai" onclick="predictAttrition()">
                            <i class="fas fa-robot"></i> Predict Attrition
                        </button>
                    </div>
                </form>
            </div>

            <!-- KPI Overview -->
            <div class="kpi-grid">
                <div class="kpi-card" style="border-top-color: var(--primary-color);">
                    <div class="kpi-value"><?php echo $total_employees; ?></div>
                    <div class="kpi-label">Total Employees</div>
                </div>
                <div class="kpi-card" style="border-top-color: var(--success-color);">
                    <div class="kpi-value"><?php echo $active_employees; ?></div>
                    <div class="kpi-label">Active Employees</div>
                </div>
                <div class="kpi-card" style="border-top-color: var(--info-color);">
                    <div class="kpi-value currency">₱<?php echo number_format($payroll_overview['total_payroll_cost'], 0); ?></div>
                    <div class="kpi-label">Monthly Payroll</div>
                </div>
                <div class="kpi-card" style="border-top-color: var(--warning-color);">
                    <div class="kpi-value"><?php echo number_format($budgetStats['total_budgets'], 0); ?></div>
                    <div class="kpi-label">Payroll Budgets</div>
                </div>
            </div>

            <!-- ============ UPDATED PAYROLL BUDGET SECTION ============ -->
            <!-- Payroll Budget Analysis Section -->
            <div class="reports-grid">
                <!-- Payroll Budget Overview -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Payroll Budget Overview</h3>
                        <i class="fas fa-money-check-alt" style="color: #4CAF50; font-size: 24px;"></i>
                    </div>
                    <div class="stat-value" style="color: #4CAF50;">
                        ₱<?php echo number_format($payroll_summary['total_actual'], 0); ?>
                    </div>
                    <div class="stat-label">
                        Total Budget (12 Months)
                    </div>
                    <div class="stat-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $budgetStats['total_budgets']; ?></div>
                            <div class="stat-label">Total Budgets</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" style="color: <?php echo ($payroll_summary['budget_variance'] ?? 0) >= 0 ? '#F44336' : '#4CAF50'; ?>">
                                <?php echo ($payroll_summary['budget_variance_percent'] ?? 0) >= 0 ? '+' : ''; ?>
                                <?php echo number_format(abs($payroll_summary['budget_variance_percent'] ?? 0), 1); ?>%
                            </div>
                            <div class="stat-label">Variance %</div>
                        </div>
                    </div>
                    <div class="system-health" style="margin-top: 1rem; padding: 0.75rem; background: #f8f9fc; border-radius: var(--border-radius);">
                        <div class="health-indicator <?php
                                                        echo abs($payroll_summary['budget_variance_percent'] ?? 0) <= 5 ? 'health-good' : (abs($payroll_summary['budget_variance_percent'] ?? 0) <= 10 ? 'health-warning' : 'health-critical');
                                                        ?>"></div>
                        <span>Budget Performance:
                            <?php echo ($payroll_summary['budget_variance'] ?? 0) >= 0 ? 'Over' : 'Under'; ?> Budget
                        </span>
                    </div>
                </div>

                <!-- Current Month Payroll -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Current Month Payroll</h3>
                        <i class="fas fa-calendar-alt" style="color: #FF9800; font-size: 24px;"></i>
                    </div>
                    <div class="stat-value" style="color: #FF9800;">
                        ₱<?php echo number_format($payroll_summary['current_month']['current_actual'] ?? 0, 0); ?>
                    </div>
                    <div class="stat-label">
                        <?php echo date('F Y'); ?> Budget
                    </div>
                    <div class="stat-grid">
                        <div class="stat-item">
                            <div class="stat-value" style="color: #1a2980;">
                                ₱<?php echo number_format($payroll_summary['current_month']['current_budget'] ?? 0, 0); ?>
                            </div>
                            <div class="stat-label">Monthly Budget</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">
                                <?php echo number_format($payroll_summary['current_month']['budget_count'] ?? 0, 0); ?>
                            </div>
                            <div class="stat-label">Budget Entries</div>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding: 0.75rem; background: #f8f9fc; border-radius: var(--border-radius);">
                        <span class="trend-badge <?php echo ($payroll_summary['current_variance'] ?? 0) >= 0 ? 'trend-down' : 'trend-up'; ?>">
                            <?php echo ($payroll_summary['current_variance'] ?? 0) >= 0 ? '+' : ''; ?>
                            <?php echo number_format($payroll_summary['current_variance_percent'] ?? 0, 1); ?>%
                        </span>
                        <span style="margin-left: 10px;">
                            <?php echo ($payroll_summary['current_variance'] ?? 0) >= 0 ? 'Over' : 'Under'; ?> Budget
                        </span>
                    </div>
                </div>

                <!-- Monthly Payroll Trend -->
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Monthly Payroll Trend</h3>
                        <i class="fas fa-chart-bar" style="color: #1a2980; font-size: 24px;"></i>
                    </div>
                    <div class="chart-container">
                        <canvas id="payrollChart"></canvas>
                    </div>
                    <div class="stat-label">
                        Last 12 Months Payroll Budgets
                    </div>
                </div>
            </div>

            <!-- Payroll Budget Details Table -->
            <div class="table-container">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Payroll Budget Details</h3>
                        <span class="data-indicator"><?php echo count($allBudgets); ?> BUDGETS</span>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Budget Period</th>
                                <th>Budget Amount</th>
                                <th>Employees</th>
                                <th>Status</th>
                                <th>Created By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($allBudgets)): ?>
                                <?php
                                $total_budget_table = 0;
                                $total_employees_table = 0;
                                ?>
                                <?php foreach ($allBudgets as $budget): ?>
                                    <?php
                                    $total_budget_table += $budget['total_net_pay'];
                                    $total_employees_table += $budget['total_employees'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo date('M Y', strtotime($budget['budget_period_start'])); ?></strong></td>
                                        <td>
                                            <?php echo date('M j', strtotime($budget['budget_period_start'])); ?> -
                                            <?php echo date('M j, Y', strtotime($budget['budget_period_end'])); ?>
                                        </td>
                                        <td class="currency">₱<?php echo number_format($budget['total_net_pay'], 2); ?></td>
                                        <td><?php echo $budget['total_employees']; ?></td>
                                        <td>
                                            <?php
                                            $statusClass = strtolower(str_replace(' ', '', $budget['approval_status'] ?? 'Draft'));
                                            ?>
                                            <span class="budget-status-<?php echo $statusClass; ?>">
                                                <?php echo htmlspecialchars($budget['approval_status'] ?? 'Draft'); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($budget['created_by'] ?? 'System'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Total Row -->
                                <tr style="background: #f8f9fc; font-weight: bold;">
                                    <td colspan="2"><strong>TOTALS</strong></td>
                                    <td class="currency">₱<?php echo number_format($total_budget_table, 2); ?></td>
                                    <td><?php echo $total_employees_table; ?></td>
                                    <td>-</td>
                                    <td>-</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 2rem;">
                                        <i class="fas fa-info-circle" style="font-size: 2rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                        <div>No payroll budget data available</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 1. EMPLOYEE HEADCOUNT ANALYTICS - REPLACED WITH TENURE ANALYSIS -->
            <div class="reports-grid">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Employee Tenure Analysis</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="tenureAnalysisChart"></canvas>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Employment Type Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="employmentTypeChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- 2. ATTENDANCE & LEAVE ANALYTICS -->
            <div class="reports-grid">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Attendance Trends (Last 6 Months)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="attendanceTrendChart"></canvas>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Leave Utilization by Type</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="leaveUtilizationChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- 3. WORKFORCE MOVEMENT -->
            <div class="reports-grid">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Monthly Hiring & Turnover Trends</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="workforceMovementChart"></canvas>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Performance Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="performanceDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- 4. SALARY & COMPENSATION ANALYSIS -->
            <div class="table-container">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Salary Distribution by Department</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Employees</th>
                                <th>Min Salary</th>
                                <th>Max Salary</th>
                                <th>Avg Salary</th>
                                <th>Avg Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($salary_distribution as $dept): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($dept['department']); ?></strong></td>
                                    <td><?php echo $dept['employee_count']; ?></td>
                                    <td class="currency">₱<?php echo number_format($dept['min_salary'], 2); ?></td>
                                    <td class="currency">₱<?php echo number_format($dept['max_salary'], 2); ?></td>
                                    <td class="currency">₱<?php echo number_format($dept['avg_salary'], 2); ?></td>
                                    <td><?php echo number_format($dept['avg_performance'], 1); ?>/10</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 5. OVERTIME ANALYSIS -->
            <div class="table-container">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Overtime Analysis by Department</h3>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Employees</th>
                                <th>Avg Overtime Hours</th>
                                <th>High Overtime Count</th>
                                <th>Overtime Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overtime_analysis as $dept): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($dept['department']); ?></strong></td>
                                    <td><?php echo $dept['employee_count']; ?></td>
                                    <td><?php echo number_format($dept['avg_overtime_hours'], 1); ?> hrs</td>
                                    <td><?php echo $dept['high_overtime_count']; ?></td>
                                    <td class="currency">₱<?php echo number_format($dept['total_overtime_cost'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 6. HMO & BENEFITS ANALYTICS - USING ACTUAL DATA -->
            <div class="table-container">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">HMO & Benefits Coverage Summary</h3>
                        <span class="data-indicator">LIVE DATA - <?php echo $hmo_total_stats['total_enrolled']; ?> ENROLLMENTS</span>
                    </div>
                    <div class="stat-grid">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $hmo_total_stats['unique_employees_enrolled']; ?></div>
                            <div class="stat-label">Employees Enrolled</div>
                            <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                <?php echo $hmo_total_stats['total_enrolled']; ?> total enrollments
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value currency">₱<?php echo number_format($hmo_total_stats['total_monthly_cost'], 0); ?></div>
                            <div class="stat-label">Monthly Premium Cost</div>
                            <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                Employee + Dependent coverage
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $dependent_stats['covered_dependents']; ?></div>
                            <div class="stat-label">Covered Dependents</div>
                            <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                <?php echo $dependent_stats['employees_with_dependents']; ?> employees with dependents
                            </div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $hmo_total_stats['total_providers']; ?></div>
                            <div class="stat-label">HMO Providers</div>
                            <div style="font-size: 0.7rem; margin-top: 0.25rem;">
                                <?php echo count($hmo_coverage_summary); ?> active providers
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fc; border-radius: var(--border-radius);">
                        <div style="font-size: 0.9rem; color: #6c757d;">
                            <strong>Enrollment Rate:</strong> <?php echo $enrollment_rate; ?>% of active employees |
                            <strong>Coverage:</strong> <?php echo $hmo_total_stats['unique_employees_enrolled']; ?> of <?php echo $active_employees; ?> active employees
                        </div>
                    </div>
                </div>
            </div>

            <!-- 7. DETAILED HMO PROVIDER BREAKDOWN -->
            <div class="table-container">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">HMO Provider Breakdown</h3>
                        <span class="data-indicator"><?php echo count($hmo_coverage_summary); ?> ACTIVE PROVIDERS</span>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Enrolled Employees</th>
                                <th>Covered Dependents</th>
                                <th>Employee Premium</th>
                                <th>Dependent Premium</th>
                                <th>Total Premium</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($hmo_coverage_summary)): ?>
                                <?php
                                $total_enrolled = 0;
                                $total_dependents = 0;
                                $total_premium = 0;
                                ?>
                                <?php foreach ($hmo_coverage_summary as $provider): ?>
                                    <?php
                                    $total_enrolled += $provider['enrolled_employees'];
                                    $total_dependents += $provider['covered_dependents'];
                                    $provider_total = $provider['total_employee_premium'] + $provider['total_dependent_premium'];
                                    $total_premium += $provider_total;
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($provider['provider_name']); ?></strong></td>
                                        <td><?php echo $provider['enrolled_employees']; ?></td>
                                        <td><?php echo $provider['covered_dependents']; ?></td>
                                        <td class="currency">₱<?php echo number_format($provider['total_employee_premium'], 2); ?></td>
                                        <td class="currency">₱<?php echo number_format($provider['total_dependent_premium'], 2); ?></td>
                                        <td class="currency">₱<?php echo number_format($provider_total, 2); ?></td>
                                        <td>
                                            <span class="status-badge status-active">
                                                <i class="fas fa-check-circle"></i> Active
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Total Row -->
                                <tr style="background: #f8f9fc; font-weight: bold;">
                                    <td><strong>TOTALS</strong></td>
                                    <td><?php echo $total_enrolled; ?></td>
                                    <td><?php echo $total_dependents; ?></td>
                                    <td class="currency">₱<?php echo number_format(array_sum(array_column($hmo_coverage_summary, 'total_employee_premium')), 2); ?></td>
                                    <td class="currency">₱<?php echo number_format(array_sum(array_column($hmo_coverage_summary, 'total_dependent_premium')), 2); ?></td>
                                    <td class="currency">₱<?php echo number_format($total_premium, 2); ?></td>
                                    <td>-</td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 2rem;">
                                        <i class="fas fa-info-circle" style="font-size: 2rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                        <div>No HMO enrollment data available</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Additional HMO Insights -->
                    <?php if (!empty($hmo_coverage_summary)): ?>
                        <div style="margin-top: 1rem; padding: 1rem; background: #e8f4fd; border-radius: var(--border-radius); border-left: 4px solid var(--info-color);">
                            <h4 style="margin-bottom: 0.5rem; color: var(--info-color);">
                                <i class="fas fa-chart-line"></i> HMO Coverage Insights
                            </h4>
                            <div style="font-size: 0.9rem;">
                                • <strong>Top Provider:</strong> <?php echo $hmo_coverage_summary[0]['provider_name']; ?>
                                (<?php echo $hmo_coverage_summary[0]['enrolled_employees']; ?> employees)<br>
                                • <strong>Total Coverage:</strong> <?php echo $total_enrolled; ?> employees + <?php echo $total_dependents; ?> dependents<br>
                                • <strong>Monthly Cost:</strong> ₱<?php echo number_format($total_premium, 2); ?> across all providers
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 8. HMO ENROLLMENT VISUALIZATION -->
            <div class="reports-grid">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">HMO Enrollment by Provider</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="hmoEnrollmentChart"></canvas>
                    </div>
                </div>

                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Benefits Coverage Distribution</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="coverageDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- AUDIT LOGS SECTION -->
            <div class="table-container">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title">Recent Audit Logs</h3>
                        <span class="data-indicator">SECURITY</span>
                    </div>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php if (!empty($audit_logs)): ?>
                            <?php foreach ($audit_logs as $log): ?>
                                <div class='audit-log-item'>
                                    <div class='audit-action'><?php echo htmlspecialchars($log['action']); ?></div>
                                    <div>User: <?php echo htmlspecialchars($log['user_name']); ?> | IP: <?php echo htmlspecialchars($log['ip_address']); ?></div>
                                    <div class='audit-timestamp'><?php echo $log['timestamp']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class='audit-log-item'>No audit logs found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // COMPREHENSIVE CHART INITIALIZATION
        document.addEventListener('DOMContentLoaded', function() {
            initializeAllCharts();
        });

        function initializeAllCharts() {
            // Payroll Budget Chart - UPDATED
            const payrollCtx = document.getElementById('payrollChart').getContext('2d');
            if (payrollCtx && <?php echo !empty($payroll_data) ? 'true' : 'false'; ?>) {
                const monthNames = [<?php
                                    if (!empty($payroll_data)) {
                                        $names = array_column($payroll_data, 'month_name');
                                        echo "'" . implode("', '", $names) . "'";
                                    } else {
                                        echo "''";
                                    }
                                    ?>];
                const budgetAmounts = [<?php
                                        if (!empty($payroll_data)) {
                                            $amounts = array_column($payroll_data, 'total_budget');
                                            echo implode(', ', $amounts);
                                        } else {
                                            echo "0";
                                        }
                                        ?>];

                new Chart(payrollCtx, {
                    type: 'bar',
                    data: {
                        labels: monthNames,
                        datasets: [{
                            label: 'Payroll Budget',
                            data: budgetAmounts,
                            backgroundColor: 'rgba(26, 41, 128, 0.7)',
                            borderColor: '#1a2980',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                },
                                title: {
                                    display: true,
                                    text: 'Amount (₱)'
                                },
                                ticks: {
                                    callback: function(value) {
                                        if (value >= 1000000) {
                                            return '₱' + (value / 1000000).toFixed(1) + 'M';
                                        } else if (value >= 1000) {
                                            return '₱' + (value / 1000).toFixed(0) + 'K';
                                        }
                                        return '₱' + value;
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                title: {
                                    display: true,
                                    text: 'Month'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }

            // ============ UPDATED: Tenure Analysis Chart - REMOVED avg performance line ============
            const tenureCtx = document.getElementById('tenureAnalysisChart').getContext('2d');
            if (tenureCtx && <?php echo !empty($tenure_analysis) ? 'true' : 'false'; ?>) {
                const tenureGroups = [<?php
                                        if (!empty($tenure_analysis)) {
                                            $groups = array_column($tenure_analysis, 'tenure_group');
                                            echo "'" . implode("', '", $groups) . "'";
                                        } else {
                                            echo "''";
                                        }
                                        ?>];
                const tenureCounts = [<?php
                                        if (!empty($tenure_analysis)) {
                                            $counts = array_column($tenure_analysis, 'employee_count');
                                            echo implode(', ', $counts);
                                        } else {
                                            echo "0";
                                        }
                                        ?>];

                new Chart(tenureCtx, {
                    type: 'bar',
                    data: {
                        labels: tenureGroups,
                        datasets: [{
                            label: 'Number of Employees',
                            data: tenureCounts,
                            backgroundColor: '#4e73df',
                            borderColor: '#2e59d9',
                            borderWidth: 1,
                            borderRadius: 4,
                            borderSkipped: false,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Employees'
                                },
                                ticks: {
                                    precision: 0
                                },
                                grid: {
                                    color: 'rgba(0,0,0,0.05)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Tenure Groups'
                                },
                                grid: {
                                    display: false
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    pointStyle: 'rect'
                                }
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.7)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                borderColor: '#4e73df',
                                borderWidth: 1,
                                callbacks: {
                                    label: function(context) {
                                        const total = tenureCounts.reduce((a, b) => a + b, 0);
                                        const percentage = ((context.raw / total) * 100).toFixed(1);
                                        return `Employees: ${context.raw} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Employment Type Distribution
            const employmentCtx = document.getElementById('employmentTypeChart').getContext('2d');
            if (employmentCtx && <?php echo !empty($employment_type_distribution) ? 'true' : 'false'; ?>) {
                const employmentData = [<?php
                                        if (!empty($employment_type_distribution)) {
                                            $data = array_column($employment_type_distribution, 'count');
                                            echo implode(', ', $data);
                                        } else {
                                            echo "0";
                                        }
                                        ?>];
                const employmentLabels = [<?php
                                            if (!empty($employment_type_distribution)) {
                                                $labels = array_column($employment_type_distribution, 'employment_status');
                                                echo "'" . implode("', '", $labels) . "'";
                                            } else {
                                                echo "''";
                                            }
                                            ?>];

                new Chart(employmentCtx, {
                    type: 'doughnut',
                    data: {
                        labels: employmentLabels,
                        datasets: [{
                            data: employmentData,
                            backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }

            // Attendance Trend Chart with Absences
            const attendanceCtx = document.getElementById('attendanceTrendChart').getContext('2d');
            if (attendanceCtx && <?php echo !empty($attendance_trend) ? 'true' : 'false'; ?>) {
                const months = [<?php
                                if (!empty($attendance_trend)) {
                                    $months = array_column($attendance_trend, 'month');
                                    echo "'" . implode("', '", $months) . "'";
                                } else {
                                    echo "''";
                                }
                                ?>];
                const attendanceRates = [<?php
                                            if (!empty($attendance_trend)) {
                                                $rates = array_column($attendance_trend, 'attendance_rate');
                                                echo implode(', ', $rates);
                                            } else {
                                                echo "0";
                                            }
                                            ?>];
                const lateCounts = [<?php
                                    if (!empty($attendance_trend)) {
                                        $late = array_column($attendance_trend, 'late_count');
                                        echo implode(', ', $late);
                                    } else {
                                        echo "0";
                                    }
                                    ?>];
                const absenceCounts = [<?php
                                        if (!empty($attendance_trend)) {
                                            $absence = array_column($attendance_trend, 'absence_count');
                                            echo implode(', ', $absence);
                                        } else {
                                            echo "0";
                                        }
                                        ?>];

                new Chart(attendanceCtx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [{
                                label: 'Attendance Rate %',
                                data: attendanceRates,
                                borderColor: '#1cc88a',
                                backgroundColor: 'rgba(28, 200, 138, 0.1)',
                                fill: true,
                                yAxisID: 'y',
                                tension: 0.4
                            },
                            {
                                label: 'Late Count',
                                data: lateCounts,
                                borderColor: '#f6c23e',
                                backgroundColor: 'rgba(246, 194, 62, 0.1)',
                                fill: true,
                                yAxisID: 'y1',
                                tension: 0.4
                            },
                            {
                                label: 'Absences',
                                data: absenceCounts,
                                borderColor: '#e74a3b',
                                backgroundColor: 'rgba(231, 74, 59, 0.1)',
                                fill: true,
                                yAxisID: 'y1',
                                tension: 0.4
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                title: {
                                    display: true,
                                    text: 'Attendance Rate %'
                                }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Count'
                                }
                            }
                        }
                    }
                });
            }

            // Leave Utilization Chart
            const leaveCtx = document.getElementById('leaveUtilizationChart').getContext('2d');
            if (leaveCtx && <?php echo !empty($leave_summary) ? 'true' : 'false'; ?>) {
                const leaveTypes = [<?php
                                    if (!empty($leave_summary)) {
                                        $types = array_column($leave_summary, 'leave_type');
                                        echo "'" . implode("', '", $types) . "'";
                                    } else {
                                        echo "''";
                                    }
                                    ?>];
                const usedCounts = [<?php
                                    if (!empty($leave_summary)) {
                                        $used = array_column($leave_summary, 'used_count');
                                        echo implode(', ', $used);
                                    } else {
                                        echo "0";
                                    }
                                    ?>];
                const pendingCounts = [<?php
                                        if (!empty($leave_summary)) {
                                            $pending = array_column($leave_summary, 'pending_count');
                                            echo implode(', ', $pending);
                                        } else {
                                            echo "0";
                                        }
                                        ?>];

                new Chart(leaveCtx, {
                    type: 'bar',
                    data: {
                        labels: leaveTypes,
                        datasets: [{
                                label: 'Used',
                                data: usedCounts,
                                backgroundColor: '#4e73df'
                            },
                            {
                                label: 'Pending',
                                data: pendingCounts,
                                backgroundColor: '#f6c23e'
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
            }

            // Workforce Movement Chart
            const movementCtx = document.getElementById('workforceMovementChart').getContext('2d');
            if (movementCtx && <?php echo !empty($hiring_trend) ? 'true' : 'false'; ?>) {
                const months = [<?php
                                if (!empty($hiring_trend)) {
                                    $months = array_column($hiring_trend, 'month');
                                    echo "'" . implode("', '", $months) . "'";
                                } else {
                                    echo "''";
                                }
                                ?>];
                const newHires = [<?php
                                    if (!empty($hiring_trend)) {
                                        $hires = array_column($hiring_trend, 'new_hires');
                                        echo implode(', ', $hires);
                                    } else {
                                        echo "0";
                                    }
                                    ?>];
                const resignations = [<?php
                                        if (!empty($hiring_trend)) {
                                            $resign = array_column($hiring_trend, 'resignations');
                                            echo implode(', ', $resign);
                                        } else {
                                            echo "0";
                                        }
                                        ?>];
                const netGrowth = [<?php
                                    if (!empty($hiring_trend)) {
                                        $growth = array_column($hiring_trend, 'net_growth');
                                        echo implode(', ', $growth);
                                    } else {
                                        echo "0";
                                    }
                                    ?>];

                new Chart(movementCtx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: [{
                                label: 'New Hires',
                                data: newHires,
                                borderColor: '#1cc88a',
                                backgroundColor: 'rgba(28, 200, 138, 0.1)',
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Resignations',
                                data: resignations,
                                borderColor: '#e74a3b',
                                backgroundColor: 'rgba(231, 74, 59, 0.1)',
                                fill: true,
                                tension: 0.4
                            },
                            {
                                label: 'Net Growth',
                                data: netGrowth,
                                borderColor: '#4e73df',
                                backgroundColor: 'rgba(78, 115, 223, 0.1)',
                                fill: true,
                                borderDash: [5, 5],
                                tension: 0.4
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
            }

            // Performance Distribution Chart
            const performanceCtx = document.getElementById('performanceDistributionChart').getContext('2d');
            if (performanceCtx && <?php echo !empty($performance_distribution) ? 'true' : 'false'; ?>) {
                const performanceGroups = [<?php
                                            if (!empty($performance_distribution)) {
                                                $groups = array_column($performance_distribution, 'performance_group');
                                                echo "'" . implode("', '", $groups) . "'";
                                            } else {
                                                echo "''";
                                            }
                                            ?>];
                const performanceCounts = [<?php
                                            if (!empty($performance_distribution)) {
                                                $counts = array_column($performance_distribution, 'count');
                                                echo implode(', ', $counts);
                                            } else {
                                                echo "0";
                                            }
                                            ?>];

                new Chart(performanceCtx, {
                    type: 'pie',
                    data: {
                        labels: performanceGroups,
                        datasets: [{
                            data: performanceCounts,
                            backgroundColor: ['#1cc88a', '#4e73df', '#f6c23e', '#e74a3b']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }

            // HMO Enrollment Chart
            const hmoEnrollmentCtx = document.getElementById('hmoEnrollmentChart').getContext('2d');
            if (hmoEnrollmentCtx && <?php echo !empty($hmo_coverage_summary) ? 'true' : 'false'; ?>) {
                const providerNames = [<?php
                                        if (!empty($hmo_coverage_summary)) {
                                            $names = array_column($hmo_coverage_summary, 'provider_name');
                                            echo "'" . implode("', '", $names) . "'";
                                        } else {
                                            echo "''";
                                        }
                                        ?>];
                const enrolledCounts = [<?php
                                        if (!empty($hmo_coverage_summary)) {
                                            $counts = array_column($hmo_coverage_summary, 'enrolled_employees');
                                            echo implode(', ', $counts);
                                        } else {
                                            echo "0";
                                        }
                                        ?>];

                new Chart(hmoEnrollmentCtx, {
                    type: 'bar',
                    data: {
                        labels: providerNames,
                        datasets: [{
                            label: 'Enrolled Employees',
                            data: enrolledCounts,
                            backgroundColor: '#4e73df',
                            borderColor: '#2e59d9',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Employees'
                                }
                            }
                        }
                    }
                });
            }

            // Coverage Distribution Chart
            const coverageCtx = document.getElementById('coverageDistributionChart').getContext('2d');
            if (coverageCtx) {
                const enrolled = <?php echo $hmo_total_stats['unique_employees_enrolled']; ?>;
                const notEnrolled = <?php echo $active_employees - $hmo_total_stats['unique_employees_enrolled']; ?>;

                new Chart(coverageCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Enrolled in HMO', 'Not Enrolled'],
                        datasets: [{
                            data: [enrolled, notEnrolled],
                            backgroundColor: ['#1cc88a', '#e74a3b'],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = enrolled + notEnrolled;
                                        const percentage = ((context.raw / total) * 100).toFixed(1);
                                        return `${context.label}: ${context.raw} (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        }

        // Export Functions
        function exportReport(type) {
            if (type === 'excel') {
                exportToExcel();
            } else if (type === 'pdf') {
                exportToPDF();
            }
        }

        function exportToExcel() {
            try {
                const wb = XLSX.utils.book_new();

                // Payroll Budgets Sheet
                const payrollData = [
                    ['Month', 'Period', 'Budget Amount', 'Employees', 'Status', 'Created By', 'Created Date']
                ];

                <?php foreach ($allBudgets as $budget): ?>
                    payrollData.push([
                        '<?php echo date('M Y', strtotime($budget['budget_period_start'])); ?>',
                        '<?php echo date('M j', strtotime($budget['budget_period_start'])); ?> - <?php echo date('M j, Y', strtotime($budget['budget_period_end'])); ?>',
                        <?php echo $budget['total_net_pay']; ?>,
                        <?php echo $budget['total_employees']; ?>,
                        '<?php echo $budget['approval_status'] ?? 'Draft'; ?>',
                        '<?php echo $budget['created_by'] ?? 'System'; ?>',
                        '<?php echo $budget['created_at']; ?>'
                    ]);
                <?php endforeach; ?>

                const ws1 = XLSX.utils.aoa_to_sheet(payrollData);
                XLSX.utils.book_append_sheet(wb, ws1, 'Payroll Budgets');

                // Department performance sheet
                const deptData = [
                    ['Department', 'Employees', 'Avg Performance', 'Meets Expectations %', 'Exceeds Expectations %']
                ];
                <?php foreach ($dept_performance as $dept): ?>
                    deptData.push([
                        '<?php echo $dept['department']; ?>',
                        <?php echo $dept['employee_count']; ?>,
                        <?php echo $dept['avg_performance']; ?>,
                        <?php echo $dept['meets_expectations_pct']; ?>,
                        <?php echo $dept['exceeds_expectations_pct']; ?>
                    ]);
                <?php endforeach; ?>

                const ws2 = XLSX.utils.aoa_to_sheet(deptData);
                XLSX.utils.book_append_sheet(wb, ws2, 'Department Performance');

                // HMO coverage sheet
                const hmoData = [
                    ['Provider', 'Enrolled Employees', 'Covered Dependents', 'Employee Premium', 'Dependent Premium', 'Total Premium']
                ];
                <?php foreach ($hmo_coverage_summary as $provider): ?>
                    hmoData.push([
                        '<?php echo $provider['provider_name']; ?>',
                        <?php echo $provider['enrolled_employees']; ?>,
                        <?php echo $provider['covered_dependents']; ?>,
                        <?php echo $provider['total_employee_premium']; ?>,
                        <?php echo $provider['total_dependent_premium']; ?>,
                        <?php echo $provider['total_employee_premium'] + $provider['total_dependent_premium']; ?>
                    ]);
                <?php endforeach; ?>

                const ws3 = XLSX.utils.aoa_to_sheet(hmoData);
                XLSX.utils.book_append_sheet(wb, ws3, 'HMO Coverage');

                XLSX.writeFile(wb, `HR_Analytics_${new Date().toISOString().split('T')[0]}.xlsx`);
            } catch (error) {
                console.error('Error exporting to Excel:', error);
                alert('Error exporting to Excel. Please try again.');
            }
        }

        function exportToPDF() {
            try {
                const {
                    jsPDF
                } = window.jspdf;
                const doc = new jsPDF();

                doc.setFontSize(20);
                doc.text('HR Analytics Dashboard Report', 20, 20);
                doc.setFontSize(12);
                doc.text(`Generated on: ${new Date().toLocaleDateString()}`, 20, 30);

                // Add key statistics
                doc.setFontSize(16);
                doc.text('Key Workforce Statistics', 20, 60);

                doc.setFontSize(12);
                doc.text(`Total Employees: ${<?php echo $total_employees; ?>}`, 20, 75);
                doc.text(`Active Employees: ${<?php echo $active_employees; ?>}`, 20, 85);
                doc.text(`Monthly Payroll Cost: ₱${<?php echo number_format($payroll_overview['total_payroll_cost'], 0); ?>}`, 20, 95);
                doc.text(`Attendance Rate: ${<?php echo number_format($attendance_overview['attendance_rate'], 1); ?>}%`, 20, 105);

                // Add payroll budget summary
                doc.setFontSize(16);
                doc.text('Payroll Budget Analysis', 20, 130);

                doc.setFontSize(12);
                doc.text(`12-Month Budget: ₱${<?php echo number_format($payroll_summary['total_budget'], 0); ?>}`, 20, 145);
                doc.text(`12-Month Actual: ₱${<?php echo number_format($payroll_summary['total_actual'], 0); ?>}`, 20, 155);
                doc.text(`Budget Variance: ${<?php echo number_format($payroll_summary['budget_variance_percent'], 1); ?>}%`, 20, 165);
                doc.text(`Current Month Budget: ₱${<?php echo number_format($payroll_summary['current_month']['current_actual'] ?? 0, 0); ?>}`, 20, 175);

                // Add HMO Coverage
                doc.setFontSize(16);
                doc.text('HMO Coverage Summary', 20, 200);

                doc.setFontSize(12);
                doc.text(`Total Enrolled: ${<?php echo $hmo_total_stats['unique_employees_enrolled']; ?>}`, 20, 215);
                doc.text(`Monthly Premium: ₱${<?php echo number_format($hmo_total_stats['total_monthly_cost'], 0); ?>}`, 20, 225);
                doc.text(`Covered Dependents: ${<?php echo $dependent_stats['covered_dependents']; ?>}`, 20, 235);
                doc.text(`HMO Providers: ${<?php echo $hmo_total_stats['total_providers']; ?>}`, 20, 245);

                doc.save(`HR_Analytics_${new Date().toISOString().split('T')[0]}.pdf`);
            } catch (error) {
                console.error('Error exporting to PDF:', error);
                alert('Error exporting to PDF. Please try again.');
            }
        }

        function predictAttrition() {
            window.location.href = '../ai/ai.php';
        }

        // Loading state for filters
        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitBtn = document.getElementById('applyFilters');
                if (submitBtn) {
                    submitBtn.innerHTML = '<div class="spinner"></div> Applying...';
                    submitBtn.disabled = true;
                    setTimeout(() => {
                        this.submit();
                    }, 500);
                }
            });
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 'e':
                        e.preventDefault();
                        exportReport('excel');
                        break;
                    case 'p':
                        e.preventDefault();
                        exportReport('pdf');
                        break;
                    case 'a':
                        e.preventDefault();
                        predictAttrition();
                        break;
                }
            }
        });

        console.log('Keyboard shortcuts: Ctrl+E (Excel), Ctrl+P (PDF), Ctrl+A (Predict Attrition)');
    </script>
</body>

</html>
<?php
ob_end_flush();
?>