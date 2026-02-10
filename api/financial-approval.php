<?php
// api/financial-approval.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");

// Database connection
if (!file_exists('../config/db.php')) {
    http_response_code(500);
    echo json_encode(["error" => "Database configuration file missing."]);
    exit;
}
require_once '../config/db.php';

// Helper function for response
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list';

if ($method === 'GET') {
    if ($action === 'list') {
        // List budgets (optional filter: pending)
        $status = $_GET['status'] ?? null;
        try {
            $sql = "SELECT * FROM payroll_budgets";
            if ($status) {
                $sql .= " WHERE approval_status = :status";
            }
            $sql .= " ORDER BY created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            if ($status) {
                $stmt->execute(['status' => $status]);
            } else {
                $stmt->execute();
            }
            $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['status' => 'success', 'data' => $budgets]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($action === 'stats') {
        // Get stats
        try {
            $stats_stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total_budgets,
                    COUNT(CASE WHEN approval_status = 'Waiting for Approval' THEN 1 END) as pending_approval,
                    COUNT(CASE WHEN approval_status = 'Approved' THEN 1 END) as approved,
                    COUNT(CASE WHEN approval_status = 'Rejected' THEN 1 END) as rejected,
                    SUM(total_net_pay) as total_budget_amount,
                    SUM(total_net_pay) as total_allocated
                FROM payroll_budgets
            ");
            $budget_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
            jsonResponse(['status' => 'success', 'data' => $budget_stats]);
        } catch (PDOException $e) {
             jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) {
        $input = $_POST; // Fallback to standard POST
    }
    
    // Actions: approve, reject
    if (isset($input['action'])) {
        $budgetId = $input['budget_id'] ?? null;
        $manager = $input['user_name'] ?? 'Finance Manager (API)';
        
        if (!$budgetId) {
            jsonResponse(['status' => 'error', 'message' => 'Budget ID is required'], 400);
        }

        try {
            if ($input['action'] === 'approve') {
                $sql = "UPDATE payroll_budgets SET 
                        approval_status = 'Approved', 
                        budget_status = 'Approved',
                        approved_by = ?, 
                        approved_at = NOW() 
                        WHERE id = ? AND approval_status = 'Waiting for Approval'";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$manager, $budgetId]);
                
                if ($stmt->rowCount() > 0) {
                     jsonResponse(['status' => 'success', 'message' => "Budget #$budgetId approved."]);
                } else {
                     jsonResponse(['status' => 'error', 'message' => "Budget #$budgetId not found or not pending."], 404);
                }

            } elseif ($input['action'] === 'reject') {
                $notes = $input['rejection_notes'] ?? '';
                $sql = "UPDATE payroll_budgets SET 
                        approval_status = 'Rejected', 
                        approved_by = ?, 
                        approved_at = NOW(),
                        approver_notes = ?
                        WHERE id = ? AND approval_status = 'Waiting for Approval'";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$manager, $notes, $budgetId]);
                
                if ($stmt->rowCount() > 0) {
                     jsonResponse(['status' => 'success', 'message' => "Budget #$budgetId rejected."]);
                } else {
                     jsonResponse(['status' => 'error', 'message' => "Budget #$budgetId not found or not pending."], 404);
                }
            } else {
                jsonResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
            }
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Action is required'], 400);
    }
}

jsonResponse(['status' => 'error', 'message' => 'Invalid request'], 400);
