<?php
/**
 * HR4 Employee Data API — Bridge to HR1 Database
 * 
 * This file connects directly to the HR1 MySQL database so that
 * the HR4 domain (hr4.slatefreight-ph.com) can serve employee data
 * without needing HTTP access to the separate HR1 folder.
 *
 * Usage:
 *   GET /api/employee_data.php              — All employees
 *   GET /api/employee_data.php?id=5         — Single employee by ID
 *   GET /api/employee_data.php?email=x@y    — Single employee by email
 *   GET /api/employee_data.php?status=Active — Filter by status
 */

if(function_exists('opcache_reset')) { opcache_reset(); }
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Prevent browsers from caching the API response
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Connect directly to the HR1 database
$host = 'localhost';
$dbname = 'hr1';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 201-file related tables
    $relatedTables = [
        'files'        => 'employee_files',
        'education'    => 'employee_education',
        'dependents'   => 'employee_dependents',
        'training'     => 'employee_training',
        'history'      => 'employee_work_history',
        'performance'  => 'employee_performance',
        'disciplinary' => 'employee_disciplinary',
        'attendance'   => 'employee_attendance_summary',
        'compensation' => 'employee_compensation_records'
    ];

    // Helper: normalize field names
    function normalizeEmployee(&$emp) {
        if (isset($emp['basic_pay']) && $emp['basic_pay'] !== null) {
            $emp['salary'] = $emp['basic_pay'];
        }
        if (isset($emp['date_hired']) && $emp['date_hired'] !== null) {
            $emp['hire_date'] = $emp['date_hired'];
        }
        if (!array_key_exists('status', $emp)) {
            $emp['status'] = 'Active';
        }
    }

    // Helper: attach related 201 file records
    function attachRelations($pdo, &$emp, $tables) {
        foreach ($tables as $key => $table) {
            try {
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE employee_id = ?");
                $stmt->execute([$emp['id']]);
                $emp[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Table might not exist yet — just skip
                $emp[$key] = [];
            }
        }
    }

    // ── Single employee lookup ──
    if (isset($_GET['id']) || isset($_GET['email'])) {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$_GET['id']]);
        } else {
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE email = ?");
            $stmt->execute([$_GET['email']]);
        }

        $employee = $stmt->fetch();

        if ($employee) {
            normalizeEmployee($employee);
            attachRelations($pdo, $employee, $relatedTables);
        }

        echo json_encode($employee, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
    // ── All employees ──
    else {
        $statusFilter = isset($_GET['status']) ? $_GET['status'] : null;

        if ($statusFilter) {
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE status = ? ORDER BY id DESC");
            $stmt->execute([$statusFilter]);
        } else {
            $stmt = $pdo->query("SELECT * FROM employees ORDER BY id DESC");
        }

        $data = $stmt->fetchAll();

        foreach ($data as &$emp) {
            normalizeEmployee($emp);
            attachRelations($pdo, $emp, $relatedTables);
        }
        unset($emp);

        $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            echo json_encode(['error' => 'JSON encode error: ' . json_last_error_msg()]);
        } else {
            echo $json;
        }
    }

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
