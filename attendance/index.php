<?php
// index.php - Time and Attendance System

// Include database connection
require_once '../config/db.php';

// Start session for employee login
session_start();

// API mode: Check for GET parameters to return JSON data
if (isset($_GET['employee_id']) && isset($_GET['start_date']) && isset($_GET['end_date'])) {
    header('Content-Type: application/json');
    $employeeId = $_GET['employee_id'];
    $startDate = $_GET['start_date'];
    $endDate = $_GET['end_date'];
    $attendanceData = getAttendanceForEmployee($pdo, $employeeId, $startDate, $endDate);
    echo json_encode($attendanceData);
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['employee_login'])) {
        handleEmployeeLogin($pdo);
    } elseif (isset($_POST['clock_in'])) {
        handleClockIn($pdo);
    } elseif (isset($_POST['clock_out'])) {
        handleClockOut($pdo);
    } elseif (isset($_POST['view_my_attendance'])) {
        $myAttendanceData = getMyAttendance($pdo);
    } elseif (isset($_POST['logout'])) {
        session_destroy();
        header("Location: index.php");
        exit();
    } elseif (isset($_POST['export_my_csv'])) {
        exportMyAttendanceCSV($pdo);
    }
}

// Handle Employee Login
function handleEmployeeLogin($pdo)
{
    $employeeId = trim($_POST['employee_id'] ?? '');

    if (empty($employeeId)) {
        $_SESSION['error'] = "Please enter your Employee ID";
        return;
    }

    try {
        // DEBUG: Show what we're searching for
        // echo "Searching for employee ID: " . htmlspecialchars($employeeId) . "<br>";

        // Check if employee exists and is active
        // First, let's see what's in the database
        $testQuery = $pdo->query("SELECT id, employee_id, name, status FROM employees LIMIT 5");
        // Uncomment to see database structure:
        // echo "<pre>Sample employees: ";
        // print_r($testQuery->fetchAll());
        // echo "</pre>";

        // Try different search methods
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE (id = ? OR employee_id = ?) AND status = 'Active'");
        $stmt->execute([$employeeId, $employeeId]);
        $employee = $stmt->fetch();

        if (!$employee) {
            // Try just by ID
            $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$employeeId]);
            $employee = $stmt->fetch();

            if ($employee) {
                // Employee found but might not be active
                if ($employee['status'] != 'Active') {
                    $_SESSION['error'] = "Your account is not active. Status: " . $employee['status'];
                    return;
                }
            } else {
                $_SESSION['error'] = "Employee not found. Please check your ID.";
                return;
            }
        }

        // DEBUG: Show found employee
        // echo "<pre>Found employee: ";
        // print_r($employee);
        // echo "</pre>";

        // Store employee in session
        $_SESSION['employee_id'] = $employee['id'];
        $_SESSION['employee_data'] = $employee;
        $_SESSION['success'] = "Welcome, " . $employee['name'] . "!";
    } catch (PDOException $e) {
        $_SESSION['error'] = "Login error: " . $e->getMessage();
    }
}

// Handle Clock In
function handleClockIn($pdo)
{
    if (!isset($_SESSION['employee_id'])) {
        $_SESSION['error'] = "Please login first";
        return;
    }

    $employeeId = $_SESSION['employee_id'];
    $currentTime = date('H:i:s');
    $currentDate = date('Y-m-d');

    try {
        // Check if already clocked in today and not clocked out
        $checkStmt = $pdo->prepare("SELECT id, check_in_time FROM attendance_records WHERE employee_id = ? AND attendance_date = ? AND check_out_time IS NULL");
        $checkStmt->execute([$employeeId, $currentDate]);

        if ($checkStmt->rowCount() > 0) {
            $existingRecord = $checkStmt->fetch();
            $_SESSION['error'] = "You already clocked in at " . $existingRecord['check_in_time'] . ". Please clock out first.";
            return;
        }

        // Determine status (check if late)
        $expectedStartTime = '09:00:00'; // Company start time
        $status = ($currentTime > $expectedStartTime) ? 'Late' : 'Present';

        // Insert clock in record - using your existing table structure
        $insertStmt = $pdo->prepare("INSERT INTO attendance_records (employee_id, attendance_date, status, check_in_time, hours_worked, created_at) 
                                     VALUES (?, ?, ?, ?, 0, NOW())");
        $insertStmt->execute([$employeeId, $currentDate, $status, $currentTime]);

        $_SESSION['success'] = "✓ Clocked IN successfully at " . date('h:i A');
    } catch (PDOException $e) {
        $_SESSION['error'] = "Clock IN error: " . $e->getMessage();
    }
}

// Handle Clock Out
function handleClockOut($pdo)
{
    if (!isset($_SESSION['employee_id'])) {
        $_SESSION['error'] = "Please login first";
        return;
    }

    $employeeId = $_SESSION['employee_id'];
    $currentTime = date('H:i:s');
    $currentDate = date('Y-m-d');

    try {
        // Get clock in record
        $checkStmt = $pdo->prepare("SELECT id, check_in_time FROM attendance_records WHERE employee_id = ? AND attendance_date = ? AND check_out_time IS NULL");
        $checkStmt->execute([$employeeId, $currentDate]);

        if ($checkStmt->rowCount() == 0) {
            $_SESSION['error'] = "No active clock in record found. Please clock in first.";
            return;
        }

        $record = $checkStmt->fetch();
        $checkInTime = $record['check_in_time'];

        // Calculate hours worked
        $hoursWorked = (strtotime($currentTime) - strtotime($checkInTime)) / 3600;
        $hoursWorked = round($hoursWorked, 2);

        // Update clock out record
        $updateStmt = $pdo->prepare("UPDATE attendance_records 
                                    SET check_out_time = ?, hours_worked = ?
                                    WHERE id = ?");
        $updateStmt->execute([$currentTime, $hoursWorked, $record['id']]);

        $_SESSION['success'] = "✓ Clocked OUT successfully at " . date('h:i A') .
            " - Total Hours: " . $hoursWorked;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Clock OUT error: " . $e->getMessage();
    }
}

// Get my attendance records
function getMyAttendance($pdo)
{
    if (!isset($_SESSION['employee_id'])) {
        return [];
    }

    $employeeId = $_SESSION['employee_id'];
    $startDate = $_POST['start_date'] ?? date('Y-m-01');
    $endDate = $_POST['end_date'] ?? date('Y-m-d');

    try {
        $stmt = $pdo->prepare("
            SELECT 
                attendance_date,
                status,
                check_in_time,
                check_out_time,
                hours_worked,
                notes,
                created_at
            FROM attendance_records 
            WHERE employee_id = ? 
                AND attendance_date BETWEEN ? AND ?
            ORDER BY attendance_date DESC
        ");
        $stmt->execute([$employeeId, $startDate, $endDate]);

        return $stmt->fetchAll();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching attendance: " . $e->getMessage();
        return [];
    }
}

// Get attendance records for a specific employee between dates (for API)
function getAttendanceForEmployee($pdo, $employeeId, $startDate, $endDate)
{
    $records = [];
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    $interval = new DateInterval('P1D');

    while ($start <= $end) {
        $date = $start->format('Y-m-d');
        $dayOfWeek = $start->format('N'); // 1 (Monday) to 7 (Sunday)

        // Skip weekends
        if ($dayOfWeek >= 6) {
            $start->add($interval);
            continue;
        }

        // Fetch record for this date
        $stmt = $pdo->prepare("SELECT * FROM attendance_records WHERE employee_id = ? AND attendance_date = ?");
        $stmt->execute([$employeeId, $date]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            $status = $record['status'];
            $time_in = $record['check_in_time'];
            $time_out = $record['check_out_time'];
            $late_minutes = 0;
            $undertime_minutes = 0;

            // Calculate late minutes (assuming expected start at 09:00:00)
            if ($status === 'Late' || strtotime($time_in) > strtotime($date . ' 09:00:00')) {
                $expected = strtotime($date . ' 09:00:00');
                $actual = strtotime($date . ' ' . $time_in);
                $late_minutes = max(0, ($actual - $expected) / 60);
                if ($status === 'Present') $status = 'Late';
            }

            // Calculate undertime (assuming 8 hours expected)
            $expected_hours = 8;
            $hours_worked = $record['hours_worked'] ?? 0;
            if ($hours_worked < $expected_hours) {
                $undertime_minutes = ($expected_hours - $hours_worked) * 60;
                if ($status === 'Present' || $status === 'Late') $status = 'Undertime';
            }

            $records[] = [
                'date' => $date,
                'employee_id' => $employeeId,
                'status' => $status,
                'time_in' => $time_in,
                'time_out' => $time_out,
                'late_minutes' => $late_minutes,
                'undertime_minutes' => $undertime_minutes
            ];
        } else {
            // No record, assume absent
            $records[] = [
                'date' => $date,
                'employee_id' => $employeeId,
                'status' => 'Absent',
                'time_in' => null,
                'time_out' => null,
                'late_minutes' => 0,
                'undertime_minutes' => 0
            ];
        }

        $start->add($interval);
    }

    return ['attendance_records' => $records];
}

// Export my attendance to CSV
function exportMyAttendanceCSV($pdo)
{
    if (!isset($_SESSION['employee_id'])) {
        return;
    }

    $attendanceData = getMyAttendance($pdo);

    if (empty($attendanceData)) {
        return;
    }

    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=my_attendance_' . date('Y-m-d') . '.csv');

    // Create output stream
    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM for Excel compatibility
    fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

    // CSV Headers
    $headers = [
        'Date',
        'Day',
        'Status',
        'Clock In',
        'Clock Out',
        'Hours Worked',
        'Notes'
    ];
    fputcsv($output, $headers);

    // Add data rows
    foreach ($attendanceData as $row) {
        $dayName = date('l', strtotime($row['attendance_date']));
        $csvRow = [
            $row['attendance_date'] ?? '',
            $dayName,
            $row['status'] ?? '',
            $row['check_in_time'] ?? 'N/A',
            $row['check_out_time'] ?? 'N/A',
            $row['hours_worked'] ?? '0.00',
            $row['notes'] ?? ''
        ];
        fputcsv($output, $csvRow);
    }

    fclose($output);
    exit;
}

// Get today's attendance status for logged in employee
function getMyTodayStatus($pdo)
{
    if (!isset($_SESSION['employee_id'])) {
        return null;
    }

    $employeeId = $_SESSION['employee_id'];
    $today = date('Y-m-d');

    try {
        $stmt = $pdo->prepare("
            SELECT 
                status,
                check_in_time,
                check_out_time,
                hours_worked
            FROM attendance_records 
            WHERE employee_id = ? AND attendance_date = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$employeeId, $today]);

        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

// Get my monthly summary
function getMyMonthlySummary($pdo)
{
    if (!isset($_SESSION['employee_id'])) {
        return null;
    }

    $employeeId = $_SESSION['employee_id'];
    $currentMonth = date('Y-m');

    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN status = 'Half-day' THEN 1 ELSE 0 END) as half_days,
                AVG(hours_worked) as avg_hours,
                SUM(hours_worked) as total_hours
            FROM attendance_records 
            WHERE employee_id = ? 
                AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
        ");
        $stmt->execute([$employeeId, $currentMonth]);

        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

// Display session messages
function displayMessages()
{
    if (isset($_SESSION['success'])) {
        echo '<div class="alert success">' . $_SESSION['success'] . '</div>';
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert error">' . $_SESSION['error'] . '</div>';
        unset($_SESSION['error']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Time & Attendance System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            min-height: 90vh;
        }

        .header {
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }

        .header h1 {
            font-size: 2.2em;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1em;
            margin-bottom: 15px;
        }

        .current-time {
            font-size: 1.1em;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
            margin-top: 10px;
        }

        .content {
            padding: 30px;
        }

        /* Login Page Styles */
        .login-container {
            max-width: 400px;
            margin: 50px auto;
            text-align: center;
        }

        .login-box {
            background: #f8fafc;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }

        .login-box h2 {
            color: #4f46e5;
            margin-bottom: 30px;
            font-size: 1.8em;
        }

        .form-group {
            margin-bottom: 25px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 0.95em;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .login-btn {
            padding: 14px 25px;
            background: linear-gradient(90deg, #4f46e5, #7c3aed);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            width: 100%;
            margin-top: 10px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(79, 70, 229, 0.3);
        }

        /* Debug Info */
        .debug-info {
            background: #fef3c7;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            border-left: 5px solid #f59e0b;
            font-size: 0.9em;
        }

        .debug-info h4 {
            color: #92400e;
            margin-bottom: 10px;
        }

        .debug-list {
            list-style: none;
            padding: 0;
        }

        .debug-list li {
            padding: 5px 0;
            border-bottom: 1px solid #fde68a;
        }

        /* Dashboard Styles */
        .employee-info {
            background: linear-gradient(90deg, #f0f9ff, #e0f2fe);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid #4f46e5;
        }

        .employee-details h3 {
            color: #1e40af;
            font-size: 1.5em;
            margin-bottom: 5px;
        }

        .employee-details p {
            color: #6b7280;
            margin-bottom: 3px;
        }

        .logout-btn {
            padding: 10px 20px;
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }

        .logout-btn:hover {
            background: #dc2626;
        }

        .clock-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .clock-section {
                grid-template-columns: 1fr;
            }
        }

        .clock-card {
            background: #f8fafc;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 4px solid;
        }

        .clock-in-card {
            border-top-color: #10b981;
        }

        .clock-out-card {
            border-top-color: #ef4444;
        }

        .clock-card h3 {
            font-size: 1.4em;
            margin-bottom: 20px;
            color: #374151;
        }

        .clock-btn {
            padding: 15px 30px;
            font-size: 18px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }

        .clock-in-btn {
            background: linear-gradient(90deg, #10b981, #059669);
            color: white;
        }

        .clock-out-btn {
            background: linear-gradient(90deg, #ef4444, #dc2626);
            color: white;
        }

        .clock-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 7px 20px rgba(0, 0, 0, 0.15);
        }

        .clock-btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .today-status {
            background: #fef3c7;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 5px solid #f59e0b;
        }

        .today-status h4 {
            color: #92400e;
            margin-bottom: 10px;
            font-size: 1.2em;
        }

        .status-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .status-item {
            background: white;
            padding: 12px;
            border-radius: 6px;
            text-align: center;
        }

        .status-item .label {
            font-size: 0.9em;
            color: #6b7280;
            margin-bottom: 5px;
        }

        .status-item .value {
            font-size: 1.2em;
            font-weight: 600;
            color: #374151;
        }

        .section-title {
            font-size: 1.5em;
            margin: 30px 0 20px;
            color: #374151;
            padding-bottom: 10px;
            border-bottom: 2px solid #e5e7eb;
        }

        .report-form {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .view-btn {
            background: linear-gradient(90deg, #3b82f6, #1d4ed8);
        }

        .export-btn {
            background: linear-gradient(90deg, #059669, #047857);
        }

        .view-btn,
        .export-btn {
            padding: 12px 25px;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: transform 0.2s;
        }

        .view-btn:hover,
        .export-btn:hover {
            transform: translateY(-2px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        table th,
        table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }

        table tr:hover {
            background: #f8fafc;
        }

        .status-present {
            color: #10b981;
            font-weight: bold;
        }

        .status-late {
            color: #f59e0b;
            font-weight: bold;
        }

        .status-absent {
            color: #ef4444;
            font-weight: bold;
        }

        .status-half-day {
            color: #8b5cf6;
            font-weight: bold;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            font-weight: 500;
        }

        .success {
            background: #d1fae5;
            color: #065f46;
            border-left: 5px solid #10b981;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 5px solid #ef4444;
        }

        .info {
            background: #dbeafe;
            color: #1e40af;
            border-left: 5px solid #3b82f6;
        }

        .monthly-summary {
            background: linear-gradient(90deg, #ecfdf5, #d1fae5);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            border-left: 5px solid #10b981;
        }

        .monthly-summary h4 {
            color: #065f46;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .summary-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .summary-item .label {
            font-size: 0.9em;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .summary-item .value {
            font-size: 1.5em;
            font-weight: 600;
            color: #065f46;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
            font-size: 1.1em;
        }

        .no-data i {
            font-size: 3em;
            margin-bottom: 15px;
            color: #9ca3af;
        }
    </style>
</head>

<body>
    <div class="container">
        <?php if (!isset($_SESSION['employee_id'])): ?>
            <!-- Login Page -->
            <div class="header">
                <h1>👤 Employee Portal</h1>
                <p>Time & Attendance System</p>
                <div class="current-time" id="currentTime"></div>
            </div>

            <div class="content">
                <!-- Debug: Show sample employees -->
                <?php
                // Uncomment to see available employees:
                /*
                try {
                    $debugStmt = $pdo->query("SELECT id, employee_id, name, status FROM employees WHERE status = 'Active' LIMIT 10");
                    $debugEmployees = $debugStmt->fetchAll();
                    if ($debugEmployees) {
                        echo '<div class="debug-info">';
                        echo '<h4>Available Active Employees (for testing):</h4>';
                        echo '<ul class="debug-list">';
                        foreach ($debugEmployees as $emp) {
                            echo '<li>ID: ' . $emp['id'] . ' | Employee ID: ' . $emp['employee_id'] . ' | Name: ' . $emp['name'] . ' | Status: ' . $emp['status'] . '</li>';
                        }
                        echo '</ul>';
                        echo '<p><small>Enter either ID or Employee ID number</small></p>';
                        echo '</div>';
                    }
                } catch (Exception $e) {
                    // Ignore debug errors
                }
                */
                ?>

                <div class="login-container">
                    <div class="login-box">
                        <h2>Enter Your Employee ID</h2>
                        <?php displayMessages(); ?>
                        <form method="POST">
                            <div class="form-group">
                                <label for="employee_id">Employee ID:</label>
                                <input type="text" id="employee_id" name="employee_id" required
                                    placeholder="Enter your employee ID" autofocus>
                            </div>
                            <button type="submit" name="employee_login" class="login-btn">🔐 Login to Portal</button>
                        </form>
                        <div style="margin-top: 20px; color: #6b7280; font-size: 0.9em;">
                            <p>ℹ️ Enter your employee ID to access your attendance dashboard</p>
                            <p><small>Try using numbers like 1, 2, 3, etc. (based on your employee IDs)</small></p>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Employee Dashboard -->
            <?php
            $employee = $_SESSION['employee_data'];
            $todayStatus = getMyTodayStatus($pdo);
            $monthlySummary = getMyMonthlySummary($pdo);
            ?>

            <div class="header">
                <h1>👤 Employee Dashboard</h1>
                <p>Welcome to your attendance portal</p>
                <div class="current-time" id="currentTime"></div>
            </div>

            <div class="content">
                <!-- Employee Info -->
                <div class="employee-info">
                    <div class="employee-details">
                        <h3><?php echo htmlspecialchars($employee['name']); ?></h3>
                        <p><strong>ID:</strong> <?php echo htmlspecialchars($employee['employee_id'] ?? $employee['id']); ?></p>
                        <p><strong>Department:</strong> <?php echo htmlspecialchars($employee['department']); ?></p>
                        <p><strong>Position:</strong> <?php echo htmlspecialchars($employee['job_title']); ?></p>
                        <p><strong>Status:</strong> <?php echo htmlspecialchars($employee['status']); ?></p>
                    </div>
                    <form method="POST">
                        <button type="submit" name="logout" class="logout-btn">🚪 Logout</button>
                    </form>
                </div>

                <?php displayMessages(); ?>

                <!-- Today's Status -->
                <div class="today-status">
                    <h4>📅 Today's Attendance Status</h4>
                    <div class="status-details">
                        <div class="status-item">
                            <div class="label">Status</div>
                            <div class="value">
                                <?php if ($todayStatus): ?>
                                    <span class="status-<?php echo strtolower($todayStatus['status']); ?>">
                                        <?php echo $todayStatus['status']; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-absent">Not Clocked In</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="status-item">
                            <div class="label">Clock In</div>
                            <div class="value"><?php echo $todayStatus['check_in_time'] ?? '--:--:--'; ?></div>
                        </div>
                        <div class="status-item">
                            <div class="label">Clock Out</div>
                            <div class="value"><?php echo $todayStatus['check_out_time'] ?? '--:--:--'; ?></div>
                        </div>
                        <div class="status-item">
                            <div class="label">Hours Worked</div>
                            <div class="value"><?php echo $todayStatus['hours_worked'] ?? '0.00'; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Summary -->
                <?php if ($monthlySummary): ?>
                    <div class="monthly-summary">
                        <h4>📊 Monthly Summary (<?php echo date('F Y'); ?>)</h4>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="label">Present Days</div>
                                <div class="value"><?php echo $monthlySummary['present_days'] ?? 0; ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="label">Late Days</div>
                                <div class="value"><?php echo $monthlySummary['late_days'] ?? 0; ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="label">Absent Days</div>
                                <div class="value"><?php echo $monthlySummary['absent_days'] ?? 0; ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="label">Total Hours</div>
                                <div class="value"><?php echo number_format($monthlySummary['total_hours'] ?? 0, 2); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="label">Avg Hours/Day</div>
                                <div class="value"><?php echo number_format($monthlySummary['avg_hours'] ?? 0, 2); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Clock In/Out Section -->
                <div class="clock-section">
                    <div class="clock-card clock-in-card">
                        <h3>🟢 Clock IN</h3>
                        <p style="margin-bottom: 20px; color: #6b7280;">Click to record your arrival time</p>
                        <form method="POST">
                            <button type="submit" name="clock_in" class="clock-btn clock-in-btn"
                                <?php echo ($todayStatus && !$todayStatus['check_out_time']) ? 'disabled' : ''; ?>>
                                <?php echo ($todayStatus && !$todayStatus['check_out_time']) ? 'Already Clocked In' : '⏰ Clock IN Now'; ?>
                            </button>
                        </form>
                    </div>

                    <div class="clock-card clock-out-card">
                        <h3>🔴 Clock OUT</h3>
                        <p style="margin-bottom: 20px; color: #6b7280;">Click to record your departure time</p>
                        <form method="POST">
                            <button type="submit" name="clock_out" class="clock-btn clock-out-btn"
                                <?php echo (!$todayStatus || $todayStatus['check_out_time']) ? 'disabled' : ''; ?>>
                                <?php echo (!$todayStatus || $todayStatus['check_out_time']) ? 'Not Clocked In' : '⏰ Clock OUT Now'; ?>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- My Attendance Records -->
                <h2 class="section-title">📋 My Attendance Records</h2>
                <div class="report-form">
                    <form method="POST">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="start_date">From Date:</label>
                                <input type="date" id="start_date" name="start_date"
                                    value="<?php echo date('Y-m-01'); ?>">
                            </div>
                            <div class="form-group">
                                <label for="end_date">To Date:</label>
                                <input type="date" id="end_date" name="end_date"
                                    value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="action-buttons">
                            <button type="submit" name="view_my_attendance" class="view-btn">👁️ View My Attendance</button>
                            <button type="submit" name="export_my_csv" class="export-btn">📥 Export to CSV</button>
                        </div>
                    </form>
                </div>

                <?php if (isset($myAttendanceData)): ?>
                    <?php if (!empty($myAttendanceData)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Day</th>
                                    <th>Status</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Hours</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $totalHours = 0;
                                $totalDays = count($myAttendanceData);
                                ?>
                                <?php foreach ($myAttendanceData as $record): ?>
                                    <?php
                                    $dayName = date('l', strtotime($record['attendance_date']));
                                    $hours = $record['hours_worked'] ?? 0;
                                    $totalHours += $hours;
                                    ?>
                                    <tr>
                                        <td><?php echo $record['attendance_date']; ?></td>
                                        <td><?php echo $dayName; ?></td>
                                        <td>
                                            <span class="status-<?php echo strtolower($record['status']); ?>">
                                                <?php echo $record['status']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $record['check_in_time'] ?? '--:--:--'; ?></td>
                                        <td><?php echo $record['check_out_time'] ?? '--:--:--'; ?></td>
                                        <td><?php echo $hours; ?></td>
                                        <td><?php echo $record['notes'] ?? '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot style="background: #f9fafb; font-weight: bold;">
                                <tr>
                                    <td colspan="3">Total Days: <?php echo $totalDays; ?></td>
                                    <td colspan="2">Total Hours: <?php echo number_format($totalHours, 2); ?></td>
                                    <td colspan="2">Average Hours/Day: <?php echo $totalDays > 0 ? number_format($totalHours / $totalDays, 2) : '0.00'; ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            📭 No attendance records found for the selected period.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Update current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', {
                hour12: true,
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            const dateString = now.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            document.getElementById('currentTime').innerHTML =
                '🕐 ' + timeString + ' | ' + dateString;
        }

        // Update time immediately and every second
        updateTime();
        setInterval(updateTime, 1000);

        // Auto-focus on login field
        document.addEventListener('DOMContentLoaded', function() {
            const employeeIdField = document.getElementById('employee_id');
            if (employeeIdField) {
                employeeIdField.focus();
            }

            // Auto-refresh page every 60 seconds to update status
            setTimeout(() => {
                location.reload();
            }, 60000);
        });

        // Auto-submit clock in/out if disabled (for testing)
        document.addEventListener('DOMContentLoaded', function() {
            const clockInBtn = document.querySelector('.clock-in-btn');
            const clockOutBtn = document.querySelector('.clock-out-btn');

            // Debug logging
            console.log('Clock In button disabled:', clockInBtn?.disabled);
            console.log('Clock Out button disabled:', clockOutBtn?.disabled);
        });
    </script>
</body>

</html>