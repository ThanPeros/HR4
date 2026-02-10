<?php
// api/payroll-reporting.php
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

$action = $_GET['action'] ?? 'disbursement'; // disbursement, statutory, periods

if ($action === 'periods') {
     try {
        $stmt = $pdo->query("SELECT DISTINCT pay_period_start, pay_period_end FROM payroll_records ORDER BY pay_period_start DESC");
        $pay_periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        jsonResponse(['status' => 'success', 'data' => $pay_periods]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
} elseif ($action === 'disbursement') {
    $period = $_GET['period'] ?? null;
    if (!$period) {
        jsonResponse(['status' => 'error', 'message' => 'Period parameter is required'], 400);
    }

    try {
        $sql = "SELECT pr.*, e.department, e.job_title
                FROM payroll_records pr
                LEFT JOIN employees e ON pr.employee_id = e.id
                WHERE pr.pay_period_start = ?
                ORDER BY pr.employee_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$period]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = [
            'net_pay' => 0,
            'deductions' => 0,
            'gross' => 0
        ];
        
        foreach($report_data as $row) {
            $totals['net_pay'] += $row['net_pay'];
            $totals['deductions'] += $row['total_deductions'];
            $totals['gross'] += $row['gross_pay'];
        }

        jsonResponse([
            'status' => 'success', 
            'period' => $period, 
            'totals' => $totals, 
            'data' => $report_data
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
} elseif ($action === 'statutory') {
     $period = $_GET['period'] ?? null;
    if (!$period) {
        jsonResponse(['status' => 'error', 'message' => 'Period parameter is required'], 400);
    }

    try {
        $sql = "SELECT sss_deduction, philhealth_deduction, pagibig_deduction, tax_deduction 
                FROM payroll_records 
                WHERE pay_period_start = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$period]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = [
            'sss' => 0,
            'philhealth' => 0,
            'pagibig' => 0,
            'tax' => 0
        ];

        foreach($records as $r) {
            $stats['sss'] += $r['sss_deduction'];
            $stats['philhealth'] += $r['philhealth_deduction'];
            $stats['pagibig'] += $r['pagibig_deduction'];
            $stats['tax'] += $r['tax_deduction'];
        }

        jsonResponse([
            'status' => 'success', 
            'period' => $period,
            'data' => $stats
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
} else {
    jsonResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
}
