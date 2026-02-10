<?php
// api/compensation-structure.php
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

// DOLE Constants
const DOLE_MIN_DAILY_WAGE_NCR = 645.00;
const WORK_DAYS_FACTOR = 261;

function calculateDailyRate($monthly) {
    return ($monthly * 12) / WORK_DAYS_FACTOR;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'list'; // list, details

if ($method === 'GET') {
    if ($action === 'list') {
        try {
            $stmt = $pdo->query("SELECT * FROM salary_grades ORDER BY CAST(SUBSTRING(grade_level, 4) AS UNSIGNED) ASC");
            $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Enrich with DOLE data
            foreach ($grades as &$grade) {
                $daily = calculateDailyRate($grade['min_salary']);
                $grade['daily_rate_est'] = round($daily, 2);
                $grade['is_compliant'] = $daily >= DOLE_MIN_DAILY_WAGE_NCR;
            }
            
            jsonResponse(['status' => 'success', 'data' => $grades]);
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
    
    if ($postAction === 'save_grade') {
        $id = $input['grade_id'] ?? null;
        
        $daily = calculateDailyRate($input['min_salary']);
        $warning = null;
        if ($daily < DOLE_MIN_DAILY_WAGE_NCR) {
            $warning = "Warning: The minimum salary provided results in a daily rate (₱".number_format($daily, 2).") below the NCR Minimum Wage (₱".DOLE_MIN_DAILY_WAGE_NCR.").";
        }

        try {
            if ($id) {
                // Update
                $sql = "UPDATE salary_grades SET 
                        grade_name=?, min_salary=?, mid_salary=?, max_salary=?, 
                        step_count=?, description=?, status=? 
                        WHERE id=?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $input['grade_name'],
                    $input['min_salary'],
                    $input['mid_salary'],
                    $input['max_salary'],
                    $input['step_count'],
                    $input['description'],
                    $input['status'],
                    $id
                ]);
                jsonResponse(['status' => 'success', 'message' => 'Salary Grade updated.', 'warning' => $warning]);
            } else {
                // Insert
                $sql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description, status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $input['grade_level'],
                    $input['grade_name'],
                    $input['min_salary'],
                    $input['mid_salary'],
                    $input['max_salary'],
                    $input['step_count'],
                    $input['description'],
                    $input['status']
                ]);
                jsonResponse(['status' => 'success', 'message' => 'Salary Grade added.', 'id' => $pdo->lastInsertId(), 'warning' => $warning]);
            }
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    } else {
        jsonResponse(['status' => 'error', 'message' => 'Invalid POST action'], 400);
    }
}
