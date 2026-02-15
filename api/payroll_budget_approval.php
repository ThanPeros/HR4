<?php
// api/payroll_budget_approval.php
// Handles Payroll Budget Submission, Approval, Rejection & Details Retrieval
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET");
header("Access-Control-Allow-Headers: Content-Type");

require_once '../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// --- GET: Fetch Budget Details ---
if ($method === 'GET') {
    $periodId = $_GET['period_id'] ?? null;

    if (!$periodId) {
        // No ID provided — return list of recent budgets
        try {
            $stmt = $pdo->query("SELECT pb.*, pp.status as period_status 
                                 FROM payroll_budgets pb 
                                 LEFT JOIN payroll_periods pp ON pb.payroll_period_id = pp.id 
                                 ORDER BY pb.created_at DESC LIMIT 50");
            $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'budgets' => $budgets]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    try {
        // 1. Fetch Budget + Period Info (JOIN)
        $stmt = $pdo->prepare("SELECT b.*, p.name as period_name, p.status as period_status, p.period_code,
                                      p.start_date as period_start, p.end_date as period_end,
                                      p.bundle_type, p.bundle_filter, p.ta_batch_id,
                                      p.total_employees, p.total_gross, p.total_deductions, p.total_net
                               FROM payroll_budgets b
                               JOIN payroll_periods p ON b.payroll_period_id = p.id
                               WHERE b.payroll_period_id = ?");
        $stmt->execute([$periodId]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$budget) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Budget not found for this period']);
            exit;
        }

        // 2. Fetch T&A Batch Info
        $ta_batch = null;
        if (!empty($budget['ta_batch_id'])) {
            $taStmt = $pdo->prepare("SELECT * FROM ta_batches WHERE id = ?");
            $taStmt->execute([$budget['ta_batch_id']]);
            $ta_batch = $taStmt->fetch(PDO::FETCH_ASSOC);
        }

        // 3. Fetch Payroll Summary
        $sumStmt = $pdo->prepare("SELECT COUNT(*) as head_count, 
                                         SUM(gross_pay) as total_gross, 
                                         SUM(total_deductions) as total_deductions, 
                                         SUM(net_pay) as total_net 
                                  FROM payroll_records 
                                  WHERE payroll_period_id = ?");
        $sumStmt->execute([$periodId]);
        $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

        // 4. Fetch Individual Payroll Records (with calculation details)
        $recStmt = $pdo->prepare("SELECT id, employee_id, employee_name, department,
                                         basic_salary, overtime_pay as ot_pay, allowances,
                                         gross_pay, deduction_sss, deduction_philhealth, deduction_pagibig,
                                         deduction_tax, deduction_hmo, deduction_loans,
                                         total_deductions, net_pay, calculation_details
                                  FROM payroll_records 
                                  WHERE payroll_period_id = ? 
                                  ORDER BY employee_name ASC");
        $recStmt->execute([$periodId]);
        $records = $recStmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'budget' => [
                'id' => $budget['id'],
                'code' => $budget['budget_code'] ?? '',
                'name' => $budget['budget_name'] ?? $budget['period_name'],
                'period' => ($budget['date_range_start'] ?? $budget['period_start']) . ' to ' . ($budget['date_range_end'] ?? $budget['period_end']),
                'gross' => (float)($budget['total_gross_amount'] ?? $budget['total_gross'] ?? 0),
                'deductions' => (float)($budget['total_deductions_amount'] ?? $budget['total_deductions'] ?? 0),
                'net' => (float)($budget['total_net_amount'] ?? $budget['total_net'] ?? 0),
                'status' => $budget['approval_status'] ?? 'Unknown',
                'submitted_at' => $budget['submitted_at'] ?? $budget['created_at'] ?? null,
                'approved_by' => $budget['approved_by'] ?? null,
                'approved_at' => $budget['approved_at'] ?? null
            ],
            'attendance' => [
                'batch_id' => $ta_batch['id'] ?? 'N/A',
                'name' => $ta_batch['name'] ?? 'No T&A Batch Linked',
                'total_logs' => $ta_batch['total_logs'] ?? 0,
                'verified_status' => $ta_batch['status'] ?? 'Unknown'
            ],
            'payroll_summary' => [
                'employee_count' => (int)($summary['head_count'] ?? 0),
                'total_gross' => (float)($summary['total_gross'] ?? 0),
                'total_deductions' => (float)($summary['total_deductions'] ?? 0),
                'total_net' => (float)($summary['total_net'] ?? 0),
                'avg_net_pay' => ($summary['head_count'] > 0) ? round($summary['total_net'] / $summary['head_count'], 2) : 0
            ],
            'payroll_records' => $records
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// --- POST: Submit / Approve / Reject Budget ---
if ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) {
        $input = $_POST; // Fallback to standard POST
    }
    
    $action = $input['action'] ?? '';
    $periodId = $input['period_id'] ?? $input['payroll_period_id'] ?? null;
    $notes = $input['notes'] ?? '';
    $user = $input['user_name'] ?? $input['approved_by'] ?? 'Finance Admin';

    if (!$periodId || !$action) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action and Period ID are required']);
        exit;
    }

    try {
        // ---- ACTION: SUBMIT FOR APPROVAL ----
        if ($action === 'submit') {
            $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Waiting for Approval', submitted_at = NOW() WHERE payroll_period_id = ?")
                ->execute([$periodId]);
            
            $pdo->prepare("UPDATE payroll_periods SET status = 'Pending Approval' WHERE id = ?")
                ->execute([$periodId]);
            
            echo json_encode(['success' => true, 'message' => 'Budget submitted for Finance Approval.']);

        // ---- ACTION: APPROVE ----
        } elseif ($action === 'approve') {
            $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Approved', approved_at = NOW(), approved_by = ?, approver_notes = ? WHERE payroll_period_id = ?")
                ->execute([$user, $notes, $periodId]);
            
            $pdo->prepare("UPDATE payroll_periods SET status = 'Approved' WHERE id = ?")
                ->execute([$periodId]);
            
            echo json_encode(['success' => true, 'message' => 'Budget successfully approved. Payroll is finalized.']);

        // ---- ACTION: REJECT ----
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Rejected', approved_by = ?, approver_notes = ? WHERE payroll_period_id = ?")
                ->execute([$user, $notes, $periodId]);
            
            $pdo->prepare("UPDATE payroll_periods SET status = 'Rejected' WHERE id = ?")
                ->execute([$periodId]);

            echo json_encode(['success' => true, 'message' => 'Budget rejected.']);

        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action. Use: submit, approve, or reject.']);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
