<?php
// api/tax-statutory.php
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

if ($method === 'GET') {
    // Return all tables
    try {
        $sss = $pdo->query("SELECT * FROM sss_table ORDER BY min_salary ASC")->fetchAll(PDO::FETCH_ASSOC);
        $ph = $pdo->query("SELECT * FROM philhealth_table ORDER BY min_salary ASC")->fetchAll(PDO::FETCH_ASSOC);
        $pi = $pdo->query("SELECT * FROM pagibig_table ORDER BY min_salary ASC")->fetchAll(PDO::FETCH_ASSOC);
        $tax = $pdo->query("SELECT * FROM tax_table ORDER BY min_income ASC")->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse([
            'status' => 'success',
            'data' => [
                'sss' => $sss,
                'philhealth' => $ph,
                'pagibig' => $pi,
                'tax' => $tax
            ]
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    if(!$input) $input = $_POST;
    
    $type = $input['type'] ?? ''; // sss, tax, etc.
    
    if ($type === 'sss') {
         try {
            $stmt = $pdo->prepare("INSERT INTO sss_table (min_salary, max_salary, ee_share, er_share, ec_share) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $input['min_salary'], 
                $input['max_salary'], 
                $input['ee_share'], 
                $input['er_share'], 
                $input['ec_share']
            ]);
            jsonResponse(['status' => 'success', 'message' => 'SSS Bracket added']);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } elseif ($type === 'tax') {
         try {
            $stmt = $pdo->prepare("INSERT INTO tax_table (min_income, max_income, base_tax, excess_rate) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $input['min_income'], 
                $input['max_income'], 
                $input['base_tax'], 
                $input['excess_rate']
            ]);
            jsonResponse(['status' => 'success', 'message' => 'Tax Bracket added']);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
         jsonResponse(['status' => 'error', 'message' => 'Invalid or unsupported type for update'], 400);
    }
}
