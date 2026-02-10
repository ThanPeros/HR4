<?php
// ai/ai_engine.php
header('Content-Type: application/json');
require_once '../config/db.php';

// ----------------------------------------------------------------------------------
// AI ANALYTICS ENGINE (Rule-Based Expert System)
// ----------------------------------------------------------------------------------
// This engine analyzes database records to detect anomalies, trends, and compliance risks.
// It returns structured insights for the dashboard.

$response = [
    'insights' => [],
    'score' => 100,
    'status' => 'success'
];

try {
    // 1. ANOMALY DETECTION: Payroll Spikes
    // Compare current month payroll vs average of last 3 months
    $currMonth = date('m');
    $currPayroll = $pdo->query("SELECT SUM(total_amount) FROM payroll_periods WHERE MONTH(end_date) = $currMonth AND status IN ('Approved','Released')")->fetchColumn() ?: 0;
    
    $avgPayroll = $pdo->query("SELECT AVG(total_amount) FROM payroll_periods WHERE MONTH(end_date) != $currMonth AND status IN ('Approved','Released') ORDER BY end_date DESC LIMIT 3")->fetchColumn() ?: 0;

    if ($avgPayroll > 0) {
        $diff = $currPayroll - $avgPayroll;
        $percent = ($diff / $avgPayroll) * 100;
        
        if ($percent > 15) {
            $response['insights'][] = [
                'type' => 'warning',
                'category' => 'Cost Anomaly',
                'icon' => 'fa-arrow-trend-up',
                'title' => 'Payroll Spike Detected',
                'message' => "Current payroll is " . round($percent, 1) . "% higher than the 3-month average. Check for excessive overtime or wide-scale salary adjustments.",
                'action_link' => '../payroll/payroll-calculation.php'
            ];
            $response['score'] -= 10;
        }
    }

    // 2. COMPLIANCE CHECK: Missing Statutory Numbers
    // Check active employees missing SSS/PhilHealth/TIN
    $missingStat = $pdo->query("
        SELECT COUNT(*) FROM employees 
        WHERE status = 'Active' 
        AND (sss_no IS NULL OR sss_no = '' OR philhealth_no IS NULL OR philhealth_no = '' OR tin_no IS NULL OR tin_no = '')
    ")->fetchColumn();

    if ($missingStat > 0) {
        $response['insights'][] = [
            'type' => 'danger',
            'category' => 'Compliance Risk',
            'icon' => 'fa-id-card',
            'title' => 'Missing Statutory IDs',
            'message' => "$missingStat active employees are missing government ID numbers (SSS/PHIC/TIN). This poses a compliance risk for remittances.",
            'action_link' => '../employees/employee-management.php' // Hypothetical link
        ];
        $response['score'] -= 15;
    }

    // 3. RETENTION RISK: High Overtime
    // Identify employees with OT > 30% of Basic Pay (Burnout Risk)
    // Needs detailed payroll records join
    $burnoutRisks = $pdo->query("
        SELECT e.name, p.end_date, (r.overtime_pay / r.basic_salary) * 100 as ot_ratio
        FROM payroll_records r
        JOIN employees e ON r.employee_id = e.id
        JOIN payroll_periods p ON r.payroll_period_id = p.id
        WHERE p.status IN ('Approved', 'Released') AND p.end_date > DATE_SUB(NOW(), INTERVAL 30 DAY)
        HAVING ot_ratio > 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($burnoutRisks) > 0) {
        $names = array_column($burnoutRisks, 'name');
        $count = count($names);
        $nameStr = implode(', ', array_slice($names, 0, 3));
        if ($count > 3) $nameStr .= " and " . ($count - 3) . " others";

        $response['insights'][] = [
            'type' => 'warning',
            'category' => 'Retention Risk',
            'icon' => 'fa-fire',
            'title' => 'Burnout Risk: Excessive Overtime',
            'message' => "$count employees ($nameStr) have overtime exceeding 30% of their base pay. Consider checking workload distribution.",
            'action_link' => '../time-attendance/attendance-tracking.php'
        ];
        $response['score'] -= 5;
    }

    // 4. BUDGET ALERT
    // Check if payroll exceeds a strict budget (e.g. 5M)
    $budgetLimit = 5000000;
    if ($currPayroll > $budgetLimit) {
         $response['insights'][] = [
            'type' => 'danger',
            'category' => 'Budget Overflow',
            'icon' => 'fa-coins',
            'title' => 'Budget Limit Exceeded',
            'message' => "Payroll for this month (₱" . number_format($currPayroll) . ") has exceeded the ₱5M threshold.",
            'action_link' => '../payroll/financial-approval.php'
        ];
        $response['score'] -= 10;
    }

    // 5. SMART PAYROLL FORECAST (Linear Projection)
    $forecast = 0;
    if ($avgPayroll > 0) {
        $growthRate = 0;
        if ($currPayroll > 0) {
            $growthRate = ($currPayroll - $avgPayroll) / $avgPayroll;
        }
        $forecast = $currPayroll > 0 ? $currPayroll * (1 + $growthRate) : $avgPayroll;
    }
    $response['forecast'] = round($forecast, 2);

    // 6. ACTIONABLE SUGGESTIONS (Daily/Monthly Tasks)
    $suggestions = [];
    $today = date('Y-m-d');
    
    // A. Probation Check (Employees hired 6 months ago)
    $probationDue = $pdo->query("SELECT COUNT(*) FROM employees WHERE DATEDIFF('$today', date_hired) BETWEEN 170 AND 180 AND status = 'Active'")->fetchColumn();
    if ($probationDue > 0) {
        $suggestions[] = [
            'icon' => 'fa-user-clock',
            'task' => "Review Probation: $probationDue employees are nearing 6 months tenure.",
            'priority' => 'High'
        ];
    }

    // B. Tax Deadline (If today is near 10th-15th)
    $dayOfMonth = date('j');
    if ($dayOfMonth >= 10 && $dayOfMonth <= 15) {
        $suggestions[] = [
            'icon' => 'fa-file-invoice-dollar',
            'task' => "Remit PHILHEALTH/SSS contributions (Due on 15th).",
            'priority' => 'Urgent'
        ];
    }

    // C. HMO Enrollment Gaps
    $uninsured = $pdo->query("
        SELECT COUNT(*) FROM employees e 
        LEFT JOIN employee_benefit_profiles b ON e.id = b.employee_id AND b.benefit_type = 'HMO Coverage' 
        WHERE e.status = 'Active' AND DATEDIFF('$today', e.date_hired) > 30 AND b.id IS NULL
    ")->fetchColumn();

    if ($uninsured > 0) {
        $suggestions[] = [
            'icon' => 'fa-heartbeat',
            'task' => "Enroll $uninsured eligible employees in HMO (Tenure > 30 days).",
            'priority' => 'Medium'
        ];
    }
    
    // D. Daily Filler
    if (empty($suggestions)) {
        $suggestions[] = [
            'icon' => 'fa-calendar-check',
            'task' => "No critical HR deadlines pending for today. Review data logs.",
            'priority' => 'Low'
        ];
    }

    $response['suggestions'] = $suggestions;

    // -------------------------------------------------------------------------
    // 7. AI EXECUTIVE REPORT GENERATION (Deep Dive Breakdown)
    // -------------------------------------------------------------------------
    $report = [];

    // A. PAYROLL DRIVERS (Breakdown of Cost)
    // Identify which department contributed most to the payroll
    $topDept = $pdo->query("SELECT department, SUM(basic_salary) as total FROM employees WHERE status = 'Active' GROUP BY department ORDER BY total DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $deptName = $topDept['department'] ?? 'None';
    $deptCost = $topDept['total'] ?? 0;
    $deptShare = ($currPayroll > 0 && $deptCost > 0) ? round(($deptCost / $currPayroll) * 100, 1) : 0;
    
    $report['payroll_analysis'] = [
        'title' => 'Cost Drivers Analysis',
        'summary' => "The <strong>$deptName</strong> department is the primary cost driver, accounting for <strong>$deptShare%</strong> of total basic salaries. Monitor staffing levels in this unit.",
        'metric' => "Top Dept: $deptName (₱" . number_format($deptCost) . ")"
    ];

    // B. OVERTIME BREAKDOWN (Hotspots)
    // Find department with highest OT
    $deptOT = $pdo->query("
        SELECT e.department, SUM(r.overtime_pay) as total_ot
        FROM payroll_records r
        JOIN employees e ON r.employee_id = e.id
        JOIN payroll_periods p ON r.payroll_period_id = p.id
        WHERE p.status IN ('Approved', 'Released') AND p.end_date > DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY e.department ORDER BY total_ot DESC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    $otDept = $deptOT['department'] ?? 'None';
    $otCost = $deptOT['total_ot'] ?? 0;

    $report['overtime_analysis'] = [
        'title' => 'Overtime Hotspots',
        'summary' => "<strong>$otDept</strong> incurred the highest overtime costs (₱" . number_format($otCost) . ") this period. Investigate workload balancing or shift scheduling.",
        'metric' => "Highest OT: $otDept"
    ];

    // C. BENEFITS UTILIZATION BREAKDOWN
    // Calculate ratio of employees with benefits vs total
    $totalActive = $pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();
    $withHMO = $pdo->query("SELECT COUNT(DISTINCT employee_id) FROM employee_benefit_profiles WHERE benefit_type = 'HMO Coverage' AND status = 'Active'")->fetchColumn();
    $hmoRate = ($totalActive > 0) ? round(($withHMO / $totalActive) * 100) : 0;

    $report['benefits_analysis'] = [
        'title' => 'Benefits Coverage',
        'summary' => "Current HMO utilization is at <strong>$hmoRate%</strong>. " . ($hmoRate < 100 ? "There is an opportunity to improve coverage for the remaining " . (100 - $hmoRate) . "% of staff." : "Full coverage achieved."),
        'metric' => "HMO Coverage: $hmoRate%"
    ];

    $response['report'] = $report;


    // 8. POSITIVE REINFORCEMENT (If no issues)
    if (empty($response['insights'])) {
        $response['insights'][] = [
            'type' => 'success',
            'category' => 'System Health',
            'icon' => 'fa-check-circle',
            'title' => 'Optimal Operations',
            'message' => "All systems checks passed. Payroll variants, compliance data, and workload levels are within normal parameters.",
            'action_link' => '#'
        ];
    }

    // -------------------------------------------------------------------------
    // 8. DYNAMIC FOCUS AREA (AI DECISION LOGIC)
    // -------------------------------------------------------------------------
    // The AI decides which analytic module is most critical right now.
    
    $scores = [
        'payroll' => 0,
        'benefits' => 0,
        'workforce' => 0
    ];

    // Score Payroll: Variance > 5% adds 50 points. Over budget adds 100.
    if (isset($percent) && abs($percent) > 5) $scores['payroll'] += 50;
    if ($currPayroll > 5000000) $scores['payroll'] += 100;

    // Score Benefits: Low coverage adds points.
    if ($hmoRate < 80) $scores['benefits'] += 60;
    if ($missingStat > 0) $scores['benefits'] += 40;

    // Score Workforce: High OT or Turnover risks.
    if (count($burnoutRisks) > 0) $scores['workforce'] += 70;
    if ($probationDue > 5) $scores['workforce'] += 30;

    // Determine Winner
    arsort($scores);
    $focusKey = array_key_first($scores);
    $maxScore = $scores[$focusKey];

    $focusData = [];
    
    if ($maxScore > 0) {
        switch ($focusKey) {
            case 'payroll':
                // Prepare Payroll Deep Dive Data
                $history = $pdo->query("SELECT end_date, total_amount FROM payroll_periods WHERE status='Released' ORDER BY end_date DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
                $focusData = [
                    'type' => 'chart_line',
                    'title' => 'CRITICAL FOCUS: Payroll Cost Variance',
                    'desc' => 'The AI has identified significant anomalies in payroll costs. This is the top priority for review.',
                    'labels' => array_column(array_reverse($history), 'end_date'),
                    'data' => array_column(array_reverse($history), 'total_amount'),
                    'color' => '#e74a3b' // Red for critical
                ];
                break;
                
            case 'benefits':
                // Prepare Benefits Deep Dive Data
                $focusData = [
                    'type' => 'chart_doughnut',
                    'title' => 'CRITICAL FOCUS: Benefits Compliance Gap',
                    'desc' => 'The AI highlights a risk in benefits coverage. A large portion of the workforce remains uninsured.',
                    'labels' => ['Covered (HMO)', 'Uninsured'],
                    'data' => [$withHMO, $totalActive - $withHMO],
                    'color' => ['#1cc88a', '#e74a3b']
                ];
                break;

            case 'workforce':
                // Prepare Overtime/Burnout Data
                $focusData = [
                    'type' => 'list_highlight', // A different view type
                    'title' => 'CRITICAL FOCUS: Workforce Burnout Risk',
                    'desc' => 'The AI has detected dangerous levels of overtime. Immediate intervention required for these employees.',
                    'items' => array_map(function($r) {
                        return ['name' => $r['name'], 'value' => number_format($r['ot_ratio'], 1) . '% OT Ratio'];
                    }, array_slice($burnoutRisks, 0, 5))
                ];
                break;
        }
    } else {
        // Fallback if everything is fine
        $history = $pdo->query("SELECT end_date, total_amount FROM payroll_periods WHERE status='Released' ORDER BY end_date DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        $focusData = [
            'type' => 'chart_line',
            'title' => 'System Status: Stable',
            'desc' => 'No critical anomalies detected. Displaying standard payroll trend.',
            'labels' => array_column(array_reverse($history), 'end_date'),
            'data' => array_column(array_reverse($history), 'total_amount'),
            'color' => '#4e73df' // Blue for stable
        ];
    }

    $response['dynamic_focus'] = $focusData;

} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
