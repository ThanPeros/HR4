<?php
// api/leave-time.php
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
$action = $_GET['action'] ?? 'list'; // list, balances, details

if ($method === 'GET') {
    if ($action === 'list') {
        // List requests
        try {
            $sql = "SELECT lr.*, e.name as employee_name, e.department, e.job_title 
                    FROM leave_requests lr
                    LEFT JOIN employees e ON lr.employee_id = e.id
                    ORDER BY lr.id DESC";
            $stmt = $pdo->query($sql);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate stats
            $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
            foreach($requests as $r) {
                $stats['total']++;
                $status = strtolower($r['status']);
                if(isset($stats[$status])) $stats[$status]++;
                else $stats['pending']++; // Default if unknown
            }
            
            jsonResponse(['status' => 'success', 'data' => $requests, 'stats' => $stats]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($action === 'balances') {
         try {
            $year = $_GET['year'] ?? date('Y');
            $employee_id = $_GET['employee_id'] ?? null;
            
            $sql = "SELECT lb.*, e.name, e.department, e.job_title 
                    FROM leave_balances lb 
                    JOIN employees e ON lb.employee_id = e.id 
                    WHERE lb.year = ?";
            
            $params = [$year];
            if ($employee_id) {
                $sql .= " AND lb.employee_id = ?";
                $params[] = $employee_id;
            }
            $sql .= " ORDER BY e.name";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            jsonResponse(['status' => 'success', 'data' => $balances]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) $input = $_POST;
    
    $postAction = $input['action'] ?? '';
    
    if ($postAction === 'create_request') {
        // Create Request
        $emp_id = $input['employee_id'] ?? null;
        $type = $input['leave_type'] ?? null;
        $start = $input['start_date'] ?? null;
        $end = $input['end_date'] ?? null;
        $reason = $input['reason'] ?? '';

        if (!$emp_id || !$type || !$start || !$end) {
            jsonResponse(['status' => 'error', 'message' => 'Missing required fields'], 400);
        }

        try {
            $diff = strtotime($end) - strtotime($start);
            $days = round($diff / (60 * 60 * 24)) + 1;
            if($days < 1) $days = 1;

            $stmt = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type, start_date, end_date, days_count, reason, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending')");
            $stmt->execute([$emp_id, $type, $start, $end, $days, $reason]);
            
            jsonResponse(['status' => 'success', 'message' => 'Leave request submitted', 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
             jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($postAction === 'approve' || $postAction === 'reject') {
        $requestId = $input['request_id'] ?? null;
        if (!$requestId) jsonResponse(['status' => 'error', 'message' => 'Request ID missing'], 400);

        try {
            if ($postAction === 'approve') {
                $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ?");
                $stmt->execute([$requestId]);
                $req = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($req && $req['status'] == 'Pending') {
                    // Start Transaction
                    $pdo->beginTransaction();

                    // Determine Credit Column
                    $col = '';
                    if (strpos($req['leave_type'], 'Vacation') !== false) $col = 'vl_credits';
                    elseif (strpos($req['leave_type'], 'Sick') !== false) $col = 'sl_credits';
                    elseif (strpos($req['leave_type'], 'Emergency') !== false) $col = 'el_credits';

                    // Update Balance
                    if ($col) {
                        $year = date('Y', strtotime($req['start_date']));
                        $deductSql = "UPDATE leave_balances SET $col = $col - ? WHERE employee_id = ? AND year = ?";
                        $pdo->prepare($deductSql)->execute([$req['days_count'], $req['employee_id'], $year]);
                    }

                    // Update Status
                    $upd = $pdo->prepare("UPDATE leave_requests SET status = 'Approved' WHERE id = ?");
                    $upd->execute([$requestId]);

                    $pdo->commit();
                    jsonResponse(['status' => 'success', 'message' => 'Request Approved']);
                } else {
                    jsonResponse(['status' => 'error', 'message' => 'Request not found or not pending'], 404);
                }
            } else { // Reject
                $stmt = $pdo->prepare("UPDATE leave_requests SET status = 'Rejected' WHERE id = ?");
                $stmt->execute([$requestId]);
                jsonResponse(['status' => 'success', 'message' => 'Request Rejected']);
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
         jsonResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
    }
}
