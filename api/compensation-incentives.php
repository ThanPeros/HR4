<?php
// api/compensation-incentives.php
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
$action = $_GET['action'] ?? 'records'; // records, programs, stats

if ($method === 'GET') {
    if ($action === 'records') {
        try {
            $sql = "SELECT r.*, e.name as emp_name, p.name as prog_name, p.type 
                    FROM incentive_records r 
                    JOIN employees e ON r.employee_id = e.id 
                    JOIN incentive_programs p ON r.program_id = p.id 
                    ORDER BY r.date_awarded DESC LIMIT 100";
            $stmt = $pdo->query($sql);
            jsonResponse(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($action === 'programs') {
        try {
            $stmt = $pdo->query("SELECT * FROM incentive_programs ORDER BY name ASC");
            jsonResponse(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($action === 'stats') {
        try {
            $programs = $pdo->query("SELECT COUNT(*) FROM incentive_programs WHERE status='Active'")->fetchColumn();
            $awarded = $pdo->query("SELECT SUM(amount) FROM incentive_records WHERE YEAR(date_awarded) = YEAR(CURRENT_DATE)")->fetchColumn();
            jsonResponse([
                'status' => 'success', 
                'active_schemes' => $programs,
                'total_awarded_ytd' => $awarded ?: 0
            ]);
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
    
    if ($postAction === 'create_program') {
        try {
            $sql = "INSERT INTO incentive_programs (name, type, frequency, calculation_type, default_value, description) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['name'],
                $input['type'],
                $input['frequency'],
                $input['calculation_type'],
                $input['default_value'],
                $input['description']
            ]);
            jsonResponse(['status' => 'success', 'message' => 'Incentive program created', 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($postAction === 'award_incentive') {
        try {
            $sql = "INSERT INTO incentive_records (employee_id, program_id, date_awarded, amount, remarks) 
                    VALUES (?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $input['employee_id'],
                $input['program_id'],
                $input['date_awarded'],
                $input['amount'],
                $input['remarks']
            ]);
            jsonResponse(['status' => 'success', 'message' => 'Incentive awarded', 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid POST action'], 400);
    }
}
