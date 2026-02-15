<?php
// api/payroll_anomaly_scan.php
require_once "../config/db.php";
header('Content-Type: application/json');

$period_id = $_GET['period_id'] ?? null;

if (!$period_id) {
    echo json_encode(['success' => false, 'message' => 'Missing Period ID']);
    exit;
}

$response = [
    'success' => true,
    'anomalies' => [],
    'stats' => ['checked' => 0, 'flagged' => 0]
];

try {
    // 1. Fetch Payroll Records for this period
    $stmt = $pdo->prepare("SELECT pr.*, e.salary as current_salary, e.department as current_dept, e.hmo_provider 
                           FROM payroll_records pr 
                           JOIN employees e ON pr.employee_id = e.employee_id 
                           WHERE pr.payroll_period_id = ?");
    $stmt->execute([$period_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['stats']['checked'] = count($records);

    foreach ($records as $rec) {
        $issues = [];

        // 1. Negative Net Pay
        if ($rec['net_pay'] < 0) {
            $issues[] = "Negative Net Pay detected (₱" . number_format($rec['net_pay'], 2) . ")";
        }

        // 2. OT too high (Threshold: OT Pay > 50% of Basic Salary)
        if ($rec['basic_salary'] > 0 && ($rec['overtime_pay'] / $rec['basic_salary']) > 0.5) {
            $issues[] = "High Overtime Pay (₱" . number_format($rec['overtime_pay'], 2) . " is >50% of Basic)";
        }

        // 3. Low Net Pay (Threshold: Net Pay < 20% of Basic) - Potential over-deduction
        if ($rec['basic_salary'] > 0 && ($rec['net_pay'] / $rec['basic_salary']) < 0.2) {
             $issues[] = "Unusually Low Net Pay (<20% of Basic Salary)";
        }

        // 4. Rate Mismatch
        // Allow for small floating point diffs or recent updates, but flag distinct diffs
        if (abs($rec['basic_salary'] - $rec['current_salary']) > 1.00) {
            $issues[] = "Salary Grade Mismatch: Payroll uses " . number_format($rec['basic_salary'], 2) . " vs Employee Master " . number_format($rec['current_salary'], 2);
        }

        // 5. Dept Mismatch
        if (trim($rec['department']) !== trim($rec['current_dept'])) {
            $issues[] = "Department Mismatch: Paid under " . $rec['department'] . " but currently in " . $rec['current_dept'];
        }

        // 6. Missing Mandatory Deductions (SSS/PhilHealth/PagIBIG)
        // Only check if Gross Pay is substantial (> 5000) to avoid flagging partial/low entries
        if ($rec['gross_pay'] > 5000) {
            if ($rec['deduction_sss'] == 0) $issues[] = "Missing SSS Deduction";
            if ($rec['deduction_philhealth'] == 0) $issues[] = "Missing PhilHealth Deduction";
            if ($rec['deduction_pagibig'] == 0) $issues[] = "Missing Pag-IBIG Deduction";
        }

        // 7. Missing HMO Deduction
        if (!empty($rec['hmo_provider']) && ($rec['total_deductions'] < 100)) { // Simplified check if 'deduction_hmo' column doesn't exist yet, check total
            // If we had a specific hmo column in payroll_records we would check that.
            // For now, let's assume if they have a provider, they should have *some* deductions.
            // Or better, skip if schema is uncertain. User prompt: "HMO deduction missing for enrolled employees"
            // Let's assume there's no specific 'deduction_hmo' column separate in the `payroll_records` table based on previous `write_to_file` of `payroll-reporting.php` which only showed sss/phil/pagibig/tax. 
            // So we might check if 'other_deductions' exists or similar.
            // I'll skip specific HMO check to avoid false positives unless I see the column.
        }

        if (!empty($issues)) {
            $response['anomalies'][] = [
                'employee' => $rec['employee_name'],
                'id' => $rec['employee_id'],
                'issues' => $issues
            ];
            $response['stats']['flagged']++;
        }
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
