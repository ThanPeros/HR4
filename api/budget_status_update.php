<?php
/**
 * HR4 Budget Status API — Bridge to HR1 Database
 * 
 * This file connects directly to the HR1 MySQL database so that
 * the HR4 domain can access payroll budget data without needing
 * HTTP access to the separate HR1 folder.
 */
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Connect directly to the HR1 database
try {
    $pdo = new PDO("mysql:host=localhost;dbname=hr1;charset=utf8", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed: " . $e->getMessage()]);
    exit;
}

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
        $status = $_GET['status'] ?? null;
        try {
            $sql = "SELECT * FROM payroll_budgets";
            if ($status) {
                $sql .= " WHERE approval_status = :status";
            }
            $sql .= " ORDER BY submitted_for_approval_at DESC";
            
            $stmt = $pdo->prepare($sql);
            if ($status) {
                $stmt->execute(['status' => $status]);
            } else {
                $stmt->execute();
            }
            $budgets = $stmt->fetchAll();
            jsonResponse(['status' => 'success', 'data' => $budgets]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($action === 'stats') {
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
            $budget_stats = $stats_stmt->fetch();
            jsonResponse(['status' => 'success', 'data' => $budget_stats]);
        } catch (PDOException $e) {
             jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) {
        $input = $_POST;
    }
    
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
