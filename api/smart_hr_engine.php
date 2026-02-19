<?php
// api/smart_hr_engine.php
require_once "../config/db.php";
require_once "../config/ai_config.php";

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$employee_id = $_GET['employee_id'] ?? null;

$response = [
    'success' => true,
    'data' => null,
    'message' => ''
];

try {
    switch ($action) {
        case 'detect_missing':
            $response['data'] = detectMissingDocuments($pdo, $employee_id);
            break;
        case 'analyze_profile':
            if (!$employee_id) throw new Exception("Employee ID is required for profile analysis.");
            $response['data'] = analyzeEmployeeProfile($pdo, $employee_id);
            break;
        case 'generate_insights':
            $response['data'] = generateWorkforceInsights($pdo);
            break;
        default:
            throw new Exception("Invalid action specified.");
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);

// --- Functions --- //

function detectMissingDocuments($pdo, $specific_emp_id = null) {
    $sql = "SELECT * FROM employees WHERE status = 'Active'";
    if ($specific_emp_id) {
        $sql .= " AND id = " . intval($specific_emp_id);
    }
    $stmt = $pdo->query($sql);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $issues = [];
    $stats = ['analyzed' => count($employees), 'issues_found' => 0];

    foreach ($employees as $emp) {
        $empIssues = [];
        
        // 1. Check Mandatory Fields
        $mandatory = ['sss_no', 'philhealth_no', 'pagibig_no', 'tin_no', 'birth_date', 'email', 'phone', 'address'];
        foreach ($mandatory as $field) {
            if (empty($emp[$field])) {
                $label = ucwords(str_replace('_', ' ', $field));
                $empIssues[] = ['type' => 'Empty Field', 'msg' => "Missing $label", 'severity' => 'medium'];
            }
        }

        // 2. Check Employment Contract
        if (empty($emp['contract']) && empty($emp['date_hired'])) {
             $empIssues[] = ['type' => 'Missing Contract', 'msg' => "No contract or hire date on record.", 'severity' => 'high'];
        }

        // 3. Check 201 File Completeness (Mock Logic based on uploaded docs)
        // Assume we need at least Resume, Diploma/Transcript, NBI/Police Clearance
        // Using `employee_documents` table
        $docsStmt = $pdo->prepare("SELECT document_type FROM employee_documents WHERE employee_id = ?");
        $docsStmt->execute([$emp['id']]);
        $docs = $docsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $required_docs = ['Resume', 'Diploma', 'NBI Clearance'];
        $missing_docs = array_diff($required_docs, $docs);
        
        if (!empty($missing_docs)) {
            foreach ($missing_docs as $doc) {
                $empIssues[] = ['type' => 'Incomplete 201 File', 'msg' => "Missing document: $doc", 'severity' => 'medium'];
            }
        }

        // 4. Check ID Expiry (Mock Logic)
        // Assume IDs uploaded more than 5 years ago might be expired or need refresh
        // For demonstration, randomly flag if date_hired is old and no recent uploads
        if (!empty($emp['date_hired']) && strtotime($emp['date_hired']) < strtotime('-5 years')) {
             $empIssues[] = ['type' => 'Expired ID Risk', 'msg' => "Employee ID or core documents may need renewal (5+ years tenure).", 'severity' => 'low'];
        }

        if (!empty($empIssues)) {
            $issues[] = [
                'id' => $emp['id'],
                'name' => $emp['name'],
                'department' => $emp['department'],
                'photo' => $emp['photo'],
                'findings' => $empIssues
            ];
            $stats['issues_found'] += count($empIssues);
        }
    }
    
    return ['issues' => $issues, 'stats' => $stats];
}

function analyzeEmployeeProfile($pdo, $emp_id) {
    // Fetch detailed info
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$emp_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$emp) throw new Exception("Employee not found.");

    // Fetch related data
    $skillsStmt = $pdo->prepare("SELECT skill_name FROM skills WHERE employee_id = ?");
    $skillsStmt->execute([$emp_id]);
    $skills = $skillsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Mock AI Analysis Logic
    $firstName = explode(' ', $emp['name'])[0];
    $tenure = !empty($emp['date_hired']) ? floor((time() - strtotime($emp['date_hired'])) / (365*60*60*24)) : 0;
    
    // Summary
    $summary = "$firstName is a {$emp['job_title']} in the {$emp['department']} department";
    $summary .= ($tenure > 0) ? " with over $tenure years of tenure." : ".";
    $summary .= " Current status is {$emp['status']}.";

    // Inconsistencies
    $inconsistencies = [];
    if (!empty($emp['birth_date']) && !empty($emp['age'])) {
        $dob = new DateTime($emp['birth_date']);
        $now = new DateTime();
        $calcAge = $now->diff($dob)->y;
        if (abs($calcAge - $emp['age']) > 1) {
            $inconsistencies[] = "Age record ({$emp['age']}) conflicts with calculated age from DOB ($calcAge).";
        }
    }

    // Training Suggestions
    $suggestions = [];
    if (empty($skills)) {
        $suggestions[] = "Basic skills assessment recommended.";
    }
    if (stripos($emp['job_title'], 'Manager') !== false && !in_array('Leadership', $skills)) {
        $suggestions[] = "Advanced Leadership & Management Training.";
    }
    if ($emp['department'] === 'IT' && !in_array('Cybersecurity', $skills)) {
         $suggestions[] = "Cybersecurity Awareness refresher.";
    }

    // Promotion Readiness
    $readiness = "Low";
    $readiness_reason = "Needs more tenure or skill development.";
    
    if ($tenure > 2 && count($skills) > 3) {
        $readiness = "High";
        $readiness_reason = "Strong tenure ($tenure yrs) and diverse skill set.";
    } elseif ($tenure > 1) {
        $readiness = "Medium";
        $readiness_reason = "Showing potential, recommend leadership shadowing.";
    }

    return [
        'summary' => $summary,
        'inconsistencies' => $inconsistencies,
        'training_suggestions' => $suggestions,
        'promotion_readiness' => [
            'level' => $readiness,
            'reason' => $readiness_reason
        ]
    ];
}

function generateWorkforceInsights($pdo) {
    // Aggregate Data
    $deptStmt = $pdo->query("SELECT department, COUNT(*) as count, AVG(salary) as avg_salary FROM employees GROUP BY department");
    $depts = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $tenureStmt = $pdo->query("SELECT date_hired FROM employees WHERE date_hired IS NOT NULL");
    $dates = $tenureStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $tenureDist = ['< 1 Year' => 0, '1-3 Years' => 0, '3-5 Years' => 0, '5+ Years' => 0];
    foreach ($dates as $date) {
        $years = (time() - strtotime($date)) / (365*60*60*24);
        if ($years < 1) $tenureDist['< 1 Year']++;
        elseif ($years < 3) $tenureDist['1-3 Years']++;
        elseif ($years < 5) $tenureDist['3-5 Years']++;
        else $tenureDist['5+ Years']++;
    }

    // AI-Generated Text Mock
    $summary = "The workforce is distributed across " . count($depts) . " departments. ";
    $largestDept = $depts[0] ?? null;
    foreach ($depts as $d) {
        if ($d['count'] > ($largestDept['count'] ?? 0)) $largestDept = $d;
    }
    if ($largestDept) {
        $summary .= "The largest department is {$largestDept['department']} with {$largestDept['count']} employees.";
    }

    $skillGaps = [
        "Digital Literacy across Operations department.",
        "Advanced Data Analysis in HR.",
        "Project Management consistency in Engineering."
    ];

    $riskAlerts = [];
    if ($tenureDist['< 1 Year'] > (array_sum($tenureDist) * 0.4)) {
        $riskAlerts[] = "High specific turnover risk: 40% of workforce is new (< 1 year).";
    }
    $riskAlerts[] = "Succession planning needed for 3 key senior roles retiring in 2026.";

    return [
        'workforce_summary' => $summary,
        'tenure_distribution' => $tenureDist,
        'skill_gaps' => $skillGaps,
        'risk_alerts' => $riskAlerts
    ];
}
?>
