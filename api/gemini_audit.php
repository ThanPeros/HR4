<?php
// api/gemini_audit.php
require_once "../config/db.php";
header('Content-Type: application/json');

$response = [
    'success' => true, 
    'issues' => [], 
    'stats' => ['analyzed' => 0, 'issues_found' => 0, 'completeness' => 100]
];

try {
    $stmt = $pdo->query("SELECT * FROM employees WHERE status = 'Active'");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $response['stats']['analyzed'] = count($employees);

    $total_fields = 0;
    $filled_fields = 0;

    foreach ($employees as $emp) {
        $empIssues = [];
        $emp_total_score = 0;

        // 1. Critical Identifiers (Government IDs)
        $gov_ids = ['sss_no' => 'SSS', 'philhealth_no' => 'PhilHealth', 'pagibig_no' => 'Pag-IBIG', 'tin_no' => 'TIN'];
        foreach ($gov_ids as $field => $label) {
            $total_fields++;
            if (empty($emp[$field])) {
                $empIssues[] = ['type' => 'missing', 'msg' => "Missing $label Number", 'severity' => 'high'];
            } else {
                $filled_fields++;
                // Simple format check (e.g. SSS usually 10-12 digits)
                if (strlen(preg_replace('/[^0-9]/', '', $emp[$field])) < 9) {
                    $empIssues[] = ['type' => 'invalid', 'msg' => "Invalid $label Format", 'severity' => 'medium'];
                }
            }
        }

        // 2. Personal Data Consistency
        $total_fields += 3;
        if (empty($emp['birth_date'])) {
            $empIssues[] = ['type' => 'missing', 'msg' => "Missing Birth Date", 'severity' => 'high'];
        } else {
            $filled_fields++;
            // Check Age Validity
            $dob = new DateTime($emp['birth_date']);
            $now = new DateTime();
            $calcAge = $now->diff($dob)->y;
            
            if (!empty($emp['age']) && abs($calcAge - $emp['age']) > 1) {
                $empIssues[] = ['type' => 'inconsistent', 'msg' => "Age Mismatch: Record says {$emp['age']}, DOB says $calcAge", 'severity' => 'medium'];
            }
        }

        if (empty($emp['email'])) {
             $empIssues[] = ['type' => 'missing', 'msg' => "Missing Email Address", 'severity' => 'medium'];
        } else {
            $filled_fields++;
        }

        if (empty($emp['phone'])) {
             $empIssues[] = ['type' => 'missing', 'msg' => "Missing Phone Number", 'severity' => 'medium'];
        } else {
            $filled_fields++;
        }

        // 3. Document Analysis (Simulated)
        // In a real Gemini integration, this would scan the file content.
        // Here we check if the file references exist and validity.
        if (empty($emp['contract'])) {
             $empIssues[] = ['type' => 'missing_doc', 'msg' => "Missing Employment Contract", 'severity' => 'high'];
        }

        // 4. Compensation Data
        if (empty($emp['basic_salary']) && empty($emp['salary'])) { // Handling potential column name diffs
             $empIssues[] = ['type' => 'missing', 'msg' => "No Salary Defined", 'severity' => 'critical'];
        }

        if (!empty($empIssues)) {
            $response['issues'][] = [
                'id' => $emp['id'],
                'name' => $emp['name'],
                'employee_id' => $emp['employee_id'] ?? 'N/A',
                'department' => $emp['department'] ?? 'Unassigned',
                'photo' => $emp['photo'] ?? '',
                'findings' => $empIssues
            ];
            $response['stats']['issues_found'] += count($empIssues);
        }
    }

    if ($total_fields > 0) {
        $response['stats']['completeness'] = round(($filled_fields / $total_fields) * 100);
    }

} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
