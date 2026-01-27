<?php
// api/compensation.php - Compensation Planning API Endpoint
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../config/db.php';

// Check database connection
if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit;
}

$request_method = $_SERVER["REQUEST_METHOD"];
$action = $_GET['action'] ?? '';

// =================================================================================
// GET REQUESTS (Fetch Data)
// =================================================================================
if ($request_method === 'GET') {
    switch ($action) {
        case 'salary_grades':
            fetchData($pdo, "SELECT * FROM salary_grades ORDER BY grade_level");
            break;

        case 'allowances':
            fetchData($pdo, "SELECT * FROM allowance_matrix WHERE status = 'Active' ORDER BY allowance_type");
            break;

        case 'rules':
            fetchData($pdo, "SELECT * FROM compensation_rules WHERE status = 'Active'");
            break;

        case 'movements':
            // Optional filter by employee_id
            if (isset($_GET['employee_id'])) {
                $stmt = $pdo->prepare("SELECT * FROM salary_movements WHERE employee_id = ? ORDER BY effective_date DESC");
                $stmt->execute([$_GET['employee_id']]);
                echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } else {
                fetchData($pdo, "SELECT * FROM salary_movements ORDER BY created_at DESC");
            }
            break;

        case 'bonuses':
            fetchData($pdo, "SELECT * FROM bonus_structures WHERE status = 'Active'");
            break;

        case 'stats':
            getStats($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "Invalid action. Available actions: salary_grades, allowances, rules, movements, bonuses, stats"
            ]);
            break;
    }
}

// =================================================================================
// POST REQUESTS (Create Data)
// =================================================================================
elseif ($request_method === 'POST') {
    // Get raw posted data
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$action) {
        // Fallback if action is passed in body
        $action = $data['action'] ?? '';
    }

    switch ($action) {
        case 'add_salary_grade':
            addSalaryGrade($pdo, $data);
            break;

        case 'add_allowance':
            addAllowance($pdo, $data);
            break;

        case 'add_movement':
            addSalaryMovement($pdo, $data);
            break;

        default:
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Invalid POST action."]);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed."]);
}

// =================================================================================
// HELPER FUNCTIONS
// =================================================================================

function fetchData($pdo, $sql)
{
    try {
        $stmt = $pdo->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "success", "count" => count($data), "data" => $data]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function getStats($pdo)
{
    try {
        $stmt = $pdo->query("
            SELECT 
                (SELECT COUNT(*) FROM salary_grades WHERE status = 'Active') as active_grades,
                (SELECT COUNT(*) FROM allowance_matrix WHERE status = 'Active') as active_allowances,
                (SELECT COUNT(*) FROM compensation_rules WHERE status = 'Active') as active_rules,
                (SELECT COUNT(*) FROM salary_movements WHERE status = 'Pending') as pending_movements,
                (SELECT AVG(mid_salary) FROM salary_grades WHERE status = 'Active') as avg_mid_salary
        ");
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "success", "data" => $data]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function addSalaryGrade($pdo, $data)
{
    if (empty($data['grade_level']) || empty($data['min_salary'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing required fields"]);
        return;
    }

    try {
        $sql = "INSERT INTO salary_grades (grade_level, grade_name, min_salary, mid_salary, max_salary, step_count, description, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['grade_level'],
            $data['grade_name'],
            $data['min_salary'],
            $data['mid_salary'],
            $data['max_salary'],
            $data['step_count'] ?? 5,
            $data['description'] ?? ''
        ]);
        echo json_encode(["status" => "success", "message" => "Salary grade created", "id" => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function addAllowance($pdo, $data)
{
    try {
        $sql = "INSERT INTO allowance_matrix (allowance_type, allowance_name, amount, amount_type, eligibility_criteria, department, employment_type, effective_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['allowance_type'],
            $data['allowance_name'],
            $data['amount'],
            $data['amount_type'],
            $data['eligibility_criteria'],
            $data['department'] ?? 'All',
            $data['employment_type'] ?? 'All',
            $data['effective_date']
        ]);
        echo json_encode(["status" => "success", "message" => "Allowance created", "id" => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function addSalaryMovement($pdo, $data)
{
    try {
        $increase_amount = $data['new_salary'] - $data['previous_salary'];
        $increase_percentage = ($increase_amount / $data['previous_salary']) * 100;

        $sql = "INSERT INTO salary_movements (movement_type, employee_id, employee_name, department, previous_salary, new_salary, increase_amount, increase_percentage, effective_date, reason, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['movement_type'],
            $data['employee_id'],
            $data['employee_name'],
            $data['department'],
            $data['previous_salary'],
            $data['new_salary'],
            $increase_amount,
            round($increase_percentage, 2),
            $data['effective_date'],
            $data['reason']
        ]);
        echo json_encode(["status" => "success", "message" => "Salary movement requested", "id" => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}
