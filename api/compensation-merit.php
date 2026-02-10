<?php
// api/compensation-merit.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");

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

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'pending'; // pending, history

if ($method === 'GET') {
    if ($action === 'pending') {
        try {
            $sql = "SELECT r.*, e.name, e.department, e.job_title 
                    FROM salary_adjustments r 
                    JOIN employees e ON r.employee_id = e.id 
                    WHERE r.status = 'Pending' 
                    ORDER BY r.created_at DESC";
            $stmt = $pdo->query($sql);
            jsonResponse(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($action === 'history') {
        try {
            $sql = "SELECT r.*, e.name 
                    FROM salary_adjustments r 
                    JOIN employees e ON r.employee_id = e.id 
                    WHERE r.status != 'Pending' 
                    ORDER BY r.effective_date DESC LIMIT 50";
            $stmt = $pdo->query($sql);
            jsonResponse(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) $input = $_POST;
    
    $postAction = $input['action'] ?? '';
    
    if ($postAction === 'create_request') {
        $emp_id = $input['employee_id'];
        $current = $input['current_salary'];
        $type = $input['adjustment_type'];
        $date = $input['effective_date'];
        $reason = $input['reason'];
        
        $increase = 0;
        $new_bal = 0;
        $pct = 0;

        if (isset($input['increase_type']) && $input['increase_type'] === 'percentage') {
            $pct_val = $input['increase_value'];
            $increase = $current * ($pct_val / 100);
            $new_bal = $current + $increase;
            $pct = $pct_val;
        } else {
            $increase = $input['increase_value'];
            $new_bal = $current + $increase;
            $pct = ($current > 0) ? ($increase / $current) * 100 : 0;
        }

        try {
            $sql = "INSERT INTO salary_adjustments (employee_id, current_salary, increase_amount, new_salary, percentage_increase, type, effective_date, reason) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$emp_id, $current, $increase, $new_bal, round($pct, 2), $type, $date, $reason]);
            jsonResponse(['status' => 'success', 'message' => 'Adjustment request created', 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($postAction === 'approve_request') {
        $req_id = $input['request_id'];
        
        try {
            $pdo->beginTransaction();
            
            // Get request details
            $stmt = $pdo->prepare("SELECT * FROM salary_adjustments WHERE id = ? AND status = 'Pending'");
            $stmt->execute([$req_id]);
            $req = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($req) {
                // Update Employee
                $upd = $pdo->prepare("UPDATE employees SET salary = ? WHERE id = ?");
                $upd->execute([$req['new_salary'], $req['employee_id']]);
                
                // Update Request Status
                $updReq = $pdo->prepare("UPDATE salary_adjustments SET status = 'Approved' WHERE id = ?");
                $updReq->execute([$req_id]);
                
                $pdo->commit();
                jsonResponse(['status' => 'success', 'message' => 'Request approved and salary updated.']);
            } else {
                $pdo->rollBack();
                jsonResponse(['status' => 'error', 'message' => 'Request not found or already processed.'], 404);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($postAction === 'reject_request') {
        try {
            $stmt = $pdo->prepare("UPDATE salary_adjustments SET status = 'Rejected' WHERE id = ?");
            $stmt->execute([$input['request_id']]);
            jsonResponse(['status' => 'success', 'message' => 'Request rejected.']);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid POST action'], 400);
    }
}
