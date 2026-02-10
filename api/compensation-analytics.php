<?php
// api/compensation-analytics.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");

if (!file_exists('../config/db.php')) {
    http_response_code(500);
    echo json_encode(["error" => "Database configuration file missing."]);
    exit;
}
require_once '../config/db.php';

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? 'summary'; // summary, compliance, total_rewards

// Configuration
const MIN_WAGE_DAILY = 645.00;
const MIN_WAGE_MONTHY = (MIN_WAGE_DAILY * 261) / 12;

if ($action === 'compliance') {
    try {
        $issues = [];
        
        // 1. Min Wage
        $stmt = $pdo->query("SELECT id, name, department, job_title, salary FROM employees WHERE status='Active' AND salary < " . MIN_WAGE_MONTHY);
        $min_wage_violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Missing Hazard Pay for Drivers
        $hp_stmt = $pdo->query("SELECT id FROM allowance_policies WHERE name LIKE '%Hazard%' LIMIT 1");
        $hp_id = $hp_stmt->fetchColumn();
        
        $missing_allowances = [];
        if ($hp_id) {
            $sql = "SELECT e.id, e.name, e.job_title FROM employees e 
                    WHERE e.status='Active' 
                    AND (e.job_title LIKE '%Driver%' OR e.job_title LIKE '%Courier%') 
                    AND e.id NOT IN (SELECT employee_id FROM allowance_assignments WHERE policy_id = $hp_id AND status='Active')";
            $missing_allowances = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 3. Union Rate
        $union_threshold = 18000;
        $union_violations = $pdo->query("SELECT id, name, job_title, salary FROM employees WHERE status='Active' AND job_title LIKE '%Truck Driver%' AND salary < $union_threshold")->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse([
            'status' => 'success',
            'min_wage_monthly_threshold' => MIN_WAGE_MONTHY,
            'violations' => [
                'min_wage' => $min_wage_violations,
                'missing_allowance' => $missing_allowances,
                'union_rate' => $union_violations
            ],
            'total_issues' => count($min_wage_violations) + count($missing_allowances) + count($union_violations)
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }

} elseif ($action === 'summary') {
    try {
        $year = date('Y');
        
        // Payroll Est
        $salary = $pdo->query("SELECT SUM(salary) FROM employees WHERE status='Active'")->fetchColumn();
        $annual_payroll = $salary * 13;
        
        // Incentives YTD
        $incentives = $pdo->query("SELECT SUM(amount) FROM incentive_records WHERE YEAR(date_awarded) = '$year'")->fetchColumn();
        
        // Allowances Est (Approximated)
        $allowances_monthly = 0;
        $all_stmt = $pdo->query("SELECT a.frequency, p.rate_value FROM allowance_assignments a JOIN allowance_policies p ON a.policy_id = p.id WHERE a.status='Active' AND p.rate_type='Fixed Amount'");
        while($row = $all_stmt->fetch(PDO::FETCH_ASSOC)) {
            $val = ($row['frequency'] == 'Every Payroll') ? ($row['rate_value'] * 2) : $row['rate_value'];
            $allowances_monthly += $val;
        }
        $annual_allowances = $allowances_monthly * 12;
        
        // Merit Adjustments Count
        $merit_count = $pdo->query("SELECT COUNT(*) FROM salary_adjustments WHERE status='Approved' AND YEAR(effective_date) = '$year'")->fetchColumn();
        
        jsonResponse([
            'status' => 'success',
            'data' => [
                'annual_payroll_est' => $annual_payroll,
                'incentives_ytd' => $incentives ?: 0,
                'annual_allowances_est' => $annual_allowances,
                'merit_adjustments_count' => $merit_count
            ]
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }

} elseif ($action === 'total_rewards') {
    try {
        $stmt = $pdo->query("SELECT id, name, department, job_title, salary FROM employees WHERE status = 'Active' ORDER BY name ASC");
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $data = [];
        $year = date('Y');
        
        foreach ($employees as $emp) {
            $monthly = $emp['salary'];
            $annual_base = $monthly * 13;
            
            // Incentives
            $inc = $pdo->query("SELECT SUM(amount) FROM incentive_records WHERE employee_id = {$emp['id']} AND YEAR(date_awarded) = '$year'")->fetchColumn();
            
            // Total
            $total = $annual_base + ($inc ?: 0); // Simplified for API speed, omitting deep plan queries unless needed
            
            $data[] = [
                'name' => $emp['name'],
                'position' => $emp['job_title'],
                'department' => $emp['department'],
                'monthly_salary' => $monthly,
                'annual_base' => $annual_base,
                'incentives_ytd' => $inc ?: 0,
                'total_est' => $total
            ];
        }
        
        jsonResponse(['status' => 'success', 'data' => $data]);
        
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
} else {
    jsonResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
}
