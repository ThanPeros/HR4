<?php
// api/compensation-allowances.php
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
$action = $_GET['action'] ?? 'policies'; // policies, assignments

if ($method === 'GET') {
    if ($action === 'policies') {
        try {
            $stmt = $pdo->query("SELECT * FROM allowance_policies ORDER BY name ASC");
            $policies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['status' => 'success', 'data' => $policies]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($action === 'assignments') {
        try {
            $sql = "SELECT a.*, e.name as emp_name, p.name as policy_name, p.rate_type, p.rate_value 
                    FROM allowance_assignments a 
                    JOIN employees e ON a.employee_id = e.id 
                    JOIN allowance_policies p ON a.policy_id = p.id 
                    WHERE a.status = 'Active' 
                    ORDER BY a.created_at DESC";
            $stmt = $pdo->query($sql);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['status' => 'success', 'data' => $assignments]);
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
    
    if ($postAction === 'create_policy') {
        try {
            $stmt = $pdo->prepare("INSERT INTO allowance_policies (name, category, rate_type, rate_value, taxable, description) 
                                   VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['name'],
                $input['category'],
                $input['rate_type'],
                $input['rate_value'],
                $input['taxable'] ?? 1,
                $input['description']
            ]);
            jsonResponse(['status' => 'success', 'message' => 'Policy created', 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($postAction === 'assign_allowance') {
        try {
            $stmt = $pdo->prepare("INSERT INTO allowance_assignments (employee_id, policy_id, frequency, effective_date) 
                                   VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $input['employee_id'],
                $input['policy_id'],
                $input['frequency'],
                $input['effective_date']
            ]);
            jsonResponse(['status' => 'success', 'message' => 'Allowance assigned', 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid POST action'], 400);
    }
}
