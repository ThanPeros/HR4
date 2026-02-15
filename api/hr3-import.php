<?php
// api/hr3-import.php - Import T&A data from HR3 system
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/db.php';

// Auto-create tables if they don't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS employee_mapping (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hr4_employee_id INT NOT NULL,
    hr3_employee_id INT NOT NULL,
    hr3_employee_code VARCHAR(50),
    hr3_employee_name VARCHAR(100),
    match_method VARCHAR(20) DEFAULT 'auto',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_hr3 (hr3_employee_id),
    UNIQUE KEY unique_hr4 (hr4_employee_id)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS hr3_attendance_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id VARCHAR(50),
    hr3_employee_id INT,
    hr4_employee_id INT,
    attendance_date DATE,
    clock_in TIME NULL,
    clock_out TIME NULL,
    total_hours DECIMAL(5,2) DEFAULT 0,
    overtime_hours DECIMAL(5,2) DEFAULT 0,
    attendance_status VARCHAR(20),
    late_minutes INT DEFAULT 0,
    work_hours_type VARCHAR(50),
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch (batch_id),
    INDEX idx_hr4emp_date (hr4_employee_id, attendance_date)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS hr3_employee_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id VARCHAR(50),
    hr4_employee_id INT,
    hr3_employee_name VARCHAR(100),
    total_days_present INT DEFAULT 0,
    total_days_absent INT DEFAULT 0,
    total_days_late INT DEFAULT 0,
    total_days_half_day INT DEFAULT 0,
    total_days_on_leave INT DEFAULT 0,
    total_work_hours DECIMAL(8,2) DEFAULT 0,
    total_overtime_hours DECIMAL(8,2) DEFAULT 0,
    total_late_minutes INT DEFAULT 0,
    UNIQUE KEY unique_batch_emp (batch_id, hr4_employee_id)
)");

// Parse request
$data = json_decode(file_get_contents("php://input"), true);
$start_date = $data['start_date'] ?? null;
$end_date = $data['end_date'] ?? null;
$batch_id = $data['batch_id'] ?? null;

if (!$start_date || !$end_date || !$batch_id) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "start_date, end_date, and batch_id are required"]);
    exit;
}

// HR3 backend URL - change for production vs local
$hr3_base = 'https://backend-hr3.slatefreight-ph.com';
// For local dev, uncomment: $hr3_base = 'http://localhost:3000';

$hr3_url = $hr3_base . "/api/payroll-export?"
         . "start_date=" . urlencode($start_date)
         . "&end_date=" . urlencode($end_date);

// Call HR3 API
$ch = curl_init($hr3_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode([
        "success" => false,
        "message" => "Failed to reach HR3 API. HTTP $httpCode. Error: $curlError"
    ]);
    exit;
}

$hr3Data = json_decode($response, true);
if (!$hr3Data || !$hr3Data['success']) {
    echo json_encode([
        "success" => false,
        "message" => "HR3 returned error: " . ($hr3Data['message'] ?? 'unknown')
    ]);
    exit;
}

// Clear previous imports for this batch
$pdo->prepare("DELETE FROM hr3_attendance_cache WHERE batch_id = ?")->execute([$batch_id]);
$pdo->prepare("DELETE FROM hr3_employee_summary WHERE batch_id = ?")->execute([$batch_id]);

// Process each employee
$mapped = 0;
$unmapped = 0;
$unmapped_names = [];
$totalRecords = 0;

foreach ($hr3Data['employees'] as $hr3emp) {
    $hr4EmpId = findHR4Employee($pdo, $hr3emp);

    if (!$hr4EmpId) {
        $unmapped++;
        $unmapped_names[] = $hr3emp['employee_name'];
        continue;
    }
    $mapped++;

    // Insert employee summary
    $stmt = $pdo->prepare("INSERT INTO hr3_employee_summary
        (batch_id, hr4_employee_id, hr3_employee_name,
         total_days_present, total_days_absent, total_days_late,
         total_days_half_day, total_days_on_leave,
         total_work_hours, total_overtime_hours, total_late_minutes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
         hr3_employee_name=VALUES(hr3_employee_name),
         total_days_present=VALUES(total_days_present),
         total_days_absent=VALUES(total_days_absent),
         total_days_late=VALUES(total_days_late),
         total_days_half_day=VALUES(total_days_half_day),
         total_days_on_leave=VALUES(total_days_on_leave),
         total_work_hours=VALUES(total_work_hours),
         total_overtime_hours=VALUES(total_overtime_hours),
         total_late_minutes=VALUES(total_late_minutes)
    ");
    $stmt->execute([
        $batch_id, $hr4EmpId, $hr3emp['employee_name'],
        $hr3emp['total_days_present'] ?? 0, $hr3emp['total_days_absent'] ?? 0,
        $hr3emp['total_days_late'] ?? 0, $hr3emp['total_days_half_day'] ?? 0,
        $hr3emp['total_days_on_leave'] ?? 0, $hr3emp['total_work_hours'] ?? 0,
        $hr3emp['total_overtime_hours'] ?? 0, $hr3emp['total_late_minutes'] ?? 0
    ]);

    // Insert daily records
    foreach ($hr3emp['daily_records'] as $rec) {
        $stmt = $pdo->prepare("INSERT INTO hr3_attendance_cache
            (batch_id, hr3_employee_id, hr4_employee_id, attendance_date,
             clock_in, clock_out, total_hours, overtime_hours,
             attendance_status, late_minutes, work_hours_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $batch_id, $hr3emp['hr3_employee_id'], $hr4EmpId,
            $rec['date'], $rec['clock_in'], $rec['clock_out'],
            $rec['total_hours'] ?? 0, $rec['overtime_hours'] ?? 0,
            $rec['attendance_status'] ?? 'Present', $rec['late_minutes'] ?? 0,
            $rec['work_hours_type'] ?? 'Regular Hours'
        ]);
        $totalRecords++;
    }
}

// Create or update T&A batch entry
$stmt = $pdo->prepare("SELECT id FROM ta_batches WHERE id = ?");
$stmt->execute([$batch_id]);
if ($stmt->fetch()) {
    $pdo->prepare("UPDATE ta_batches SET total_logs = ?, status = 'Processing',
        name = ? WHERE id = ?")
        ->execute([$totalRecords, "Period: " . date('M j', strtotime($start_date)) . " - " . date('M j, Y', strtotime($end_date)), $batch_id]);
} else {
    $pdo->prepare("INSERT INTO ta_batches (id, name, start_date, end_date, total_logs, status)
        VALUES (?, ?, ?, ?, ?, 'Processing')")
        ->execute([
            $batch_id,
            "Period: " . date('M j', strtotime($start_date)) . " - " . date('M j, Y', strtotime($end_date)),
            $start_date, $end_date, $totalRecords
        ]);
}

echo json_encode([
    "success" => true,
    "message" => "Imported $mapped employees, $totalRecords records for period $start_date to $end_date",
    "batch_id" => $batch_id,
    "mapped" => $mapped,
    "unmapped" => $unmapped,
    "unmapped_names" => $unmapped_names,
    "total_records" => $totalRecords
]);

// ---- EMPLOYEE MAPPING FUNCTIONS ----

function findHR4Employee($pdo, $hr3emp) {
    // 1. Check existing mapping
    $stmt = $pdo->prepare("SELECT hr4_employee_id FROM employee_mapping WHERE hr3_employee_id = ?");
    $stmt->execute([$hr3emp['hr3_employee_id']]);
    $existing = $stmt->fetchColumn();
    if ($existing) return $existing;

    // 2. Try employee code match (EMP0001 format)
    if (!empty($hr3emp['employee_code'])) {
        $stmt = $pdo->prepare("SELECT id FROM employees WHERE employee_id = ? AND status = 'Active'");
        $stmt->execute([$hr3emp['employee_code']]);
        $match = $stmt->fetchColumn();
        if ($match) {
            saveMapping($pdo, $match, $hr3emp, 'employee_code');
            return $match;
        }
    }

    // 3. Try exact name match (case-insensitive)
    $stmt = $pdo->prepare("SELECT id FROM employees WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND status = 'Active'");
    $stmt->execute([$hr3emp['employee_name']]);
    $match = $stmt->fetchColumn();
    if ($match) {
        saveMapping($pdo, $match, $hr3emp, 'exact_name');
        return $match;
    }

    // 4. Try SOUNDEX + department match
    if (!empty($hr3emp['department'])) {
        $stmt = $pdo->prepare("SELECT id FROM employees
            WHERE SOUNDEX(name) = SOUNDEX(?) AND department = ? AND status = 'Active' LIMIT 1");
        $stmt->execute([$hr3emp['employee_name'], $hr3emp['department']]);
        $match = $stmt->fetchColumn();
        if ($match) {
            saveMapping($pdo, $match, $hr3emp, 'soundex_dept');
            return $match;
        }
    }

    return null;
}

function saveMapping($pdo, $hr4Id, $hr3emp, $method) {
    try {
        $pdo->prepare("INSERT IGNORE INTO employee_mapping
            (hr4_employee_id, hr3_employee_id, hr3_employee_code, hr3_employee_name, match_method)
            VALUES (?, ?, ?, ?, ?)")
        ->execute([$hr4Id, $hr3emp['hr3_employee_id'], $hr3emp['employee_code'] ?? '', $hr3emp['employee_name'], $method]);
    } catch (Exception $e) {
        // Ignore duplicate mapping errors
    }
}
