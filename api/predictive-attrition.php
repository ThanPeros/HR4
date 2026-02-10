<?php
// api/predictive-attrition.php - Predictive Attrition API Endpoint
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
        case 'predict_employee':
            if (isset($_GET['employee_id'])) {
                predictEmployeeAttrition($pdo, $_GET['employee_id']);
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Missing employee_id"]);
            }
            break;

        case 'risk_assessment':
            // Get all high risk employees
            getHighRiskEmployees($pdo);
            break;

        case 'attrition_stats':
            getAttritionStats($pdo);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "Invalid action. Available actions: predict_employee, risk_assessment, attrition_stats"
            ]);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed."]);
}

// =================================================================================
// HELPER FUNCTIONS
// =================================================================================

function predictEmployeeAttrition($pdo, $employee_id)
{
    try {
        // Fetch employee details including performance, salary, etc.
        $stmt = $pdo->prepare("
            SELECT e.*, 
                   COALESCE(e.performance_rating, 5) as performance_rating,
                   COALESCE(e.overtime_hours, 0) as overtime_hours
            FROM employees e
            WHERE e.id = ?
        ");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Employee not found"]);
            return;
        }

        // Calculate Risk Score (Heuristic Simulation)
        $risk_score = calculateRiskScore($employee);
        $risk_factors = identifyRiskFactors($employee);

        echo json_encode([
            "status" => "success",
            "data" => [
                "employee_id" => $employee['id'],
                "name" => $employee['name'],
                "department" => $employee['department'],
                "risk_score" => $risk_score,
                "risk_level" => getRiskLevel($risk_score),
                "risk_factors" => $risk_factors,
                "prediction_confidence" => rand(85, 98) / 100 // Simulated confidence
            ]
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function getHighRiskEmployees($pdo)
{
    try {
        // Get all active employees
        $stmt = $pdo->query("SELECT * FROM employees WHERE status = 'Active'");
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $high_risk_list = [];

        foreach ($employees as $employee) {
            $risk_score = calculateRiskScore($employee);
            if ($risk_score >= 60) { // Threshold for reporting
                $high_risk_list[] = [
                    "id" => $employee['id'],
                    "name" => $employee['name'],
                    "department" => $employee['department'],
                    "position" => $employee['job_title'], // Assuming job_title exists
                    "risk_score" => $risk_score,
                    "risk_level" => getRiskLevel($risk_score)
                ];
            }
        }

        // Sort by risk score desc
        usort($high_risk_list, function($a, $b) {
            return $b['risk_score'] <=> $a['risk_score'];
        });

        echo json_encode(["status" => "success", "count" => count($high_risk_list), "data" => array_slice($high_risk_list, 0, 20)]); // Return top 20
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function getAttritionStats($pdo)
{
    // Simulate some aggregate stats or pull from historical data if available
    // For now, let's look at actual vs predicated logic if we had a history table
    
    // Using current active employee scan for real-time overview
    try {
        $stmt = $pdo->query("SELECT * FROM employees WHERE status = 'Active'");
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stats = [
            'total_analyzed' => count($employees),
            'high_risk_count' => 0,
            'medium_risk_count' => 0,
            'low_risk_count' => 0,
            'avg_risk_score' => 0
        ];
        
        $total_score = 0;
        
        foreach ($employees as $employee) {
            $score = calculateRiskScore($employee);
            $total_score += $score;
            
            if ($score >= 70) $stats['high_risk_count']++;
            elseif ($score >= 40) $stats['medium_risk_count']++;
            else $stats['low_risk_count']++;
        }
        
        if ($stats['total_analyzed'] > 0) {
            $stats['avg_risk_score'] = round($total_score / $stats['total_analyzed'], 1);
        }
        
        echo json_encode(["status" => "success", "data" => $stats]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

// Logic to simulate AI prediction based on heuristics
function calculateRiskScore($employee)
{
    $score = 20; // Base baseline risk

    // 1. Tenure
    $hire_date = new DateTime($employee['hire_date']);
    $today = new DateTime();
    $tenure_months = $hire_date->diff($today)->m + ($hire_date->diff($today)->y * 12);

    if ($tenure_months < 12) $score += 15; // New hires higher risk
    if ($tenure_months > 36 && $tenure_months < 60) $score += 10; // 3-5 year itch

    // 2. Performance (Lower is riskier usually, or very high performer without promo)
    $rating = $employee['performance_rating'] ?? 5; // 1-10 scale
    if ($rating < 5) $score += 20; // Poor performance -> managed out risk
    if ($rating > 9) $score += 15; // High performer -> poaching risk

    // 3. Compensation (Lower salary relative to market/others is risk)
    // Simplified: if salary is low (dummy logic)
    if (($employee['salary'] ?? 0) < 25000) $score += 15;

    // 4. Overtime (Burnout risk)
    $overtime = $employee['overtime_hours'] ?? 0;
    if ($overtime > 20) $score += 20;
    if ($overtime > 40) $score += 15; // Extra burnout

    // 5. Random factor (Simulate external market conditions/personal reasons)
    $score += rand(-5, 10);

    return max(0, min(100, $score)); // Clamp 0-100
}

function getRiskLevel($score)
{
    if ($score >= 70) return 'High';
    if ($score >= 40) return 'Medium';
    return 'Low';
}

function identifyRiskFactors($employee)
{
    $factors = [];
    
    // Similar logic to score calculation, but returning strings
    $rating = $employee['performance_rating'] ?? 5;
    if ($rating < 5) $factors[] = "Low Performance Rating";
    if ($rating > 9) $factors[] = "High Performer (Flight Risk)";
    
    $overtime = $employee['overtime_hours'] ?? 0;
    if ($overtime > 20) $factors[] = "High Overtime Hours (Burnout Risk)";
    
    $hire_date = new DateTime($employee['hire_date']);
    $today = new DateTime();
    $tenure_months = $hire_date->diff($today)->m + ($hire_date->diff($today)->y * 12);
    
    if ($tenure_months < 12) $factors[] = "New Hire Assessment Period";
    if ($tenure_months > 60 && ($employee['salary'] ?? 0) < 40000) $factors[] = "Long Tenure with Stagnant Pay";

    if (empty($factors)) {
        $factors[] = "General Market Volatility";
    }
    
    return $factors;
}
