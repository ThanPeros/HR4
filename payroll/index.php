<?php
// payroll-management.php - Complete Payroll Management System with Budget Creation
session_start();

// Debug: Check if db.php exists
if (!file_exists('../config/db.php')) {
    die('Error: Database configuration file not found! Please check the file path.');
}

// Include database configuration
require_once '../config/db.php';

// Debug: Check if $pdo exists
if (!isset($pdo)) {
    // Try alternative approach if $pdo isn't set
    $host = 'localhost';
    $dbname = 'dummyhr4';
    $username = 'root';
    $password = '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}

// Debug: Test database connection
try {
    $pdo->query('SELECT 1');
} catch (PDOException $e) {
    die('Error: Database connection failed: ' . $e->getMessage());
}

// API URL Configuration
define('API_BASE_URL', 'http://localhost/Hr3/api');

require_once '../includes/sidebar.php';



// Initialize theme
if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light';
}

// Handle theme toggle
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($_SESSION['theme'] === 'light') ? 'dark' : 'light';
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

$currentTheme = isset($_SESSION['theme']) ? $_SESSION['theme'] : 'light';

// ============ CREATE REQUIRED TABLES IF NOT EXISTS ============
function createTables($pdo)
{
    // Check if payroll_records table exists, if not create it
    $check_table_sql = "SHOW TABLES LIKE 'payroll_records'";
    $stmt = $pdo->query($check_table_sql);
    $table_exists = $stmt->fetch();

    if (!$table_exists) {
        // Create payroll_records table
        $payroll_table_sql = "
        CREATE TABLE IF NOT EXISTS payroll_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            employee_name VARCHAR(100) NOT NULL,
            department VARCHAR(50) NOT NULL,
            pay_period_start DATE NOT NULL,
            pay_period_end DATE NOT NULL,
            basic_salary DECIMAL(12,2) NOT NULL,
            overtime_hours DECIMAL(5,2) DEFAULT 0,
            overtime_pay DECIMAL(10,2) DEFAULT 0,
            night_diff_hours DECIMAL(5,2) DEFAULT 0,
            night_diff_pay DECIMAL(10,2) DEFAULT 0,
            holiday_pay DECIMAL(10,2) DEFAULT 0,
            allowances DECIMAL(10,2) DEFAULT 0,
            bonuses DECIMAL(10,2) DEFAULT 0,
            gross_pay DECIMAL(12,2) NOT NULL,
            late_deduction DECIMAL(10,2) DEFAULT 0,
            absence_deduction DECIMAL(10,2) DEFAULT 0,
            undertime_deduction DECIMAL(10,2) DEFAULT 0,
            halfday_deduction DECIMAL(10,2) DEFAULT 0,
            total_deductions DECIMAL(12,2) NOT NULL,
            net_pay DECIMAL(12,2) NOT NULL,
            status ENUM('Pending', 'Paid', 'Cancelled', 'Processed', 'Released') DEFAULT 'Pending',
            payment_date DATE NULL,
            calculation_details TEXT NULL,
            attendance_data TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee_id (employee_id),
            INDEX idx_pay_period (pay_period_start, pay_period_end),
            INDEX idx_status (status)
        )";

        try {
            $pdo->exec($payroll_table_sql);
            error_log("Payroll records table created successfully.");
        } catch (PDOException $e) {
            error_log("Error creating payroll_records table: " . $e->getMessage());
        }
    } else {
        error_log("Payroll records table already exists.");
    }

    // Check if payroll_budgets table exists, if not create it
    $check_budget_sql = "SHOW TABLES LIKE 'payroll_budgets'";
    $stmt = $pdo->query($check_budget_sql);
    $budget_table_exists = $stmt->fetch();

    if (!$budget_table_exists) {
        // Create payroll_budgets table with approval fields
        $budget_table_sql = "
        CREATE TABLE IF NOT EXISTS payroll_budgets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            budget_name VARCHAR(255) NULL,
            budget_period_start DATE NOT NULL,
            budget_period_end DATE NOT NULL,
            total_employees INT NOT NULL,
            total_gross_pay DECIMAL(12,2) NOT NULL,
            total_deductions DECIMAL(12,2) NOT NULL,
            total_net_pay DECIMAL(12,2) NOT NULL,
            budget_status ENUM('Draft', 'Approved', 'Released') DEFAULT 'Draft',
            approval_status ENUM('Draft', 'Waiting for Approval', 'Approved', 'Rejected') DEFAULT 'Draft',
            submitted_for_approval_at TIMESTAMP NULL,
            approved_at TIMESTAMP NULL,
            approved_by VARCHAR(100) NULL,
            approver_notes TEXT NULL,
            created_by VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_budget_period (budget_period_start, budget_period_end),
            INDEX idx_budget_status (budget_status),
            INDEX idx_approval_status (approval_status)
        )";

        try {
            $pdo->exec($budget_table_sql);
            error_log("Payroll budgets table created successfully.");
        } catch (PDOException $e) {
            error_log("Error creating payroll_budgets table: " . $e->getMessage());
        }
    } else {
        error_log("Payroll budgets table already exists.");
    }

    // Check and add missing columns if table exists but columns are missing
    try {
        $check_columns_sql = "SHOW COLUMNS FROM payroll_budgets LIKE 'approval_status'";
        $stmt = $pdo->query($check_columns_sql);
        $column_exists = $stmt->fetch();

        if (!$column_exists) {
            // Add missing columns
            $add_columns_sql = "
            ALTER TABLE payroll_budgets 
            ADD COLUMN approval_status ENUM('Draft', 'Waiting for Approval', 'Approved', 'Rejected') DEFAULT 'Draft',
            ADD COLUMN submitted_for_approval_at TIMESTAMP NULL,
            ADD COLUMN approved_at TIMESTAMP NULL,
            ADD COLUMN approved_by VARCHAR(100) NULL,
            ADD COLUMN approver_notes TEXT NULL,
            ADD COLUMN budget_name VARCHAR(255) NULL";

            try {
                $pdo->exec($add_columns_sql);
                error_log("Missing columns added to payroll_budgets table.");
            } catch (PDOException $e) {
                error_log("Error adding columns to payroll_budgets table: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        error_log("Error checking/adding columns: " . $e->getMessage());
    }

    return true;
}

// Create tables if they don't exist - FIXED: Ensure $pdo exists
if (isset($pdo)) {
    createTables($pdo);
} else {
    error_log("Cannot create tables: Database connection not available");
    die("Database connection error. Please check your configuration.");
}

// ============ HANDLE FORM SUBMISSIONS ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_payroll'])) {
        addPayrollRecord($pdo, $_POST);
    } elseif (isset($_POST['update_payroll'])) {
        updatePayrollRecord($pdo, $_POST);
    } elseif (isset($_POST['delete_payroll'])) {
        deletePayrollRecord($pdo, $_POST['id']);
    } elseif (isset($_POST['calculate_auto'])) {
        calculateAutomaticPayroll($pdo, $_POST);
    } elseif (isset($_POST['view_details'])) {
        viewPayrollDetails($pdo, $_POST['id']);
    } elseif (isset($_POST['compute_all_budget'])) {
        computeAllPayrollsAndCreateBudget($pdo);
    } elseif (isset($_POST['send_for_approval'])) {
        sendBudgetForApproval($pdo, $_POST['budget_id']);
    } elseif (isset($_POST['release_salary'])) {
        releaseSalary($pdo, $_POST['payroll_id']);
    } elseif (isset($_POST['release_multiple'])) {
        $selectedIds = $_POST['selected_payrolls'] ?? [];
        if (!empty($selectedIds)) {
            releaseMultipleSalaries($pdo, $selectedIds);
        }
    } elseif (isset($_POST['generate_payslip'])) {
        $payslipData = generatePayslip($pdo, $_POST['payroll_id']);
        if ($payslipData) {
            $_SESSION['payslip_data'] = $payslipData;
            header('Location: ?view_payslip=' . $_POST['payroll_id']);
            exit;
        }
    } elseif (isset($_POST['generate_multiple_payslips'])) {
        $selectedIds = $_POST['selected_payrolls'] ?? [];
        if (!empty($selectedIds)) {
            $_SESSION['batch_payslips'] = generatePayslipsBatch($pdo, $selectedIds);
            header('Location: ?batch_payslips=true');
            exit;
        }
    } elseif (isset($_POST['mark_as_paid'])) {
        markAsPaid($pdo, $_POST['payroll_id']);
    } elseif (isset($_POST['mark_multiple_paid'])) {
        $selectedIds = $_POST['selected_payrolls'] ?? [];
        if (!empty($selectedIds)) {
            markMultipleAsPaid($pdo, $selectedIds);
        }
    }
}

// ============ HANDLE GET REQUESTS ============
if (isset($_GET['clear_calculation'])) {
    unset($_SESSION['payroll_calculation']);
    unset($_SESSION['payroll_record_id']);
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if (isset($_GET['clear_budget_summary'])) {
    unset($_SESSION['budget_summary']);
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if (isset($_GET['view_budget'])) {
    viewBudgetDetails($pdo, $_GET['view_budget']);
}

if (isset($_GET['send_for_approval'])) {
    sendBudgetForApproval($pdo, $_GET['send_for_approval']);
}

if (isset($_GET['release_salary'])) {
    releaseSalary($pdo, $_GET['release_salary']);
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

if (isset($_GET['view_payslip'])) {
    $payslipData = generatePayslip($pdo, $_GET['view_payslip']);
    if ($payslipData) {
        $_SESSION['payslip_data'] = $payslipData;
    }
}

if (isset($_GET['mark_paid'])) {
    markAsPaid($pdo, $_GET['mark_paid']);
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ============ API CONNECTION FOR ATTENDANCE DATA ============
function fetchAttendanceData($employeeId, $startDate, $endDate)
{
    // Make API call to get attendance data
    $apiUrl = API_BASE_URL . '/attendance/index.php?employee_id=' . urlencode($employeeId) .

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Content-Type: application/json',
                'timeout' => 30
            ]
        ]);

    $response = file_get_contents($apiUrl, false, $context);

    if ($response === false) {
        // Fallback to simulated data if API call fails
        return getSimulatedAttendanceData($employeeId, $startDate, $endDate);
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Fallback to simulated data if JSON parsing fails
        return getSimulatedAttendanceData($employeeId, $startDate, $endDate);
    }

    return $data;
}

// Fallback function for simulated attendance data
function getSimulatedAttendanceData($employeeId, $startDate, $endDate)
{
    $records = [];
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);

    while ($start <= $end) {
        $date = $start->format('Y-m-d');
        $dayOfWeek = $start->format('N');

        // Skip weekends
        if ($dayOfWeek >= 6) {
            $start->add(new DateInterval('P1D'));
            continue;
        }

        // Random attendance status with weights
        $statusOptions = ['Present', 'Late', 'Absent', 'Half Day', 'Undertime'];
        $weights = [70, 15, 5, 5, 5];
        $status = $statusOptions[getWeightedRandom($weights)];

        $record = [
            'date' => $date,
            'employee_id' => $employeeId,
            'status' => $status,
            'time_in' => '08:00:00',
            'time_out' => '17:00:00'
        ];

        if ($status === 'Late') {
            $record['late_minutes'] = rand(5, 120);
        } elseif ($status === 'Undertime') {
            $record['undertime_minutes'] = rand(15, 90);
        }

        $records[] = $record;
        $start->add(new DateInterval('P1D'));
    }

    return ['attendance_records' => $records];
}

function getWeightedRandom($weights)
{
    $total = array_sum($weights);
    $rand = mt_rand(1, $total);
    $current = 0;

    foreach ($weights as $i => $weight) {
        $current += $weight;
        if ($rand <= $current) {
            return $i;
        }
    }

    return 0;
}

// ============ ATTENDANCE DEDUCTION CALCULATOR ============
function calculateAttendanceDeductions($attendanceData, $dailyRate)
{
    $deductions = [
        'late_deduction' => 0,
        'absence_deduction' => 0,
        'undertime_deduction' => 0,
        'halfday_deduction' => 0,
        'total_attendance_deduction' => 0,
        'details' => [],
        'summary' => [
            'total_days' => 0,
            'present_days' => 0,
            'absent_days' => 0,
            'late_days' => 0,
            'halfday_days' => 0,
            'undertime_days' => 0,
            'total_late_minutes' => 0,
            'total_undertime_minutes' => 0
        ]
    ];

    if (!$attendanceData || !isset($attendanceData['attendance_records'])) {
        return $deductions;
    }

    $hourlyRate = $dailyRate / 8;
    $minuteRate = $hourlyRate / 60;

    foreach ($attendanceData['attendance_records'] as $record) {
        $date = $record['date'] ?? '';
        $status = $record['status'] ?? '';
        $late_minutes = $record['late_minutes'] ?? 0;
        $undertime_minutes = $record['undertime_minutes'] ?? 0;

        $deductions['summary']['total_days']++;

        switch ($status) {
            case 'Absent':
                $deductionAmount = $dailyRate;
                $deductions['absence_deduction'] += $deductionAmount;
                $deductions['summary']['absent_days']++;
                $deductions['details'][] = [
                    'date' => $date,
                    'type' => 'Absent',
                    'amount' => $deductionAmount,
                    'minutes' => 0
                ];
                break;

            case 'Late':
                $deductionAmount = $minuteRate * $late_minutes;
                $deductions['late_deduction'] += $deductionAmount;
                $deductions['summary']['late_days']++;
                $deductions['summary']['total_late_minutes'] += $late_minutes;
                $deductions['details'][] = [
                    'date' => $date,
                    'type' => 'Late (' . $late_minutes . ' mins)',
                    'amount' => $deductionAmount,
                    'minutes' => $late_minutes
                ];
                break;

            case 'Undertime':
                $deductionAmount = $minuteRate * $undertime_minutes;
                $deductions['undertime_deduction'] += $deductionAmount;
                $deductions['summary']['undertime_days']++;
                $deductions['summary']['total_undertime_minutes'] += $undertime_minutes;
                $deductions['details'][] = [
                    'date' => $date,
                    'type' => 'Undertime (' . $undertime_minutes . ' mins)',
                    'amount' => $deductionAmount,
                    'minutes' => $undertime_minutes
                ];
                break;

            case 'Half Day':
                $deductionAmount = $dailyRate / 2;
                $deductions['halfday_deduction'] += $deductionAmount;
                $deductions['summary']['halfday_days']++;
                $deductions['details'][] = [
                    'date' => $date,
                    'type' => 'Half Day',
                    'amount' => $deductionAmount,
                    'minutes' => 240
                ];
                break;

            case 'Present':
                $deductions['summary']['present_days']++;
                break;
        }
    }

    $deductions['total_attendance_deduction'] =
        $deductions['late_deduction'] +
        $deductions['absence_deduction'] +
        $deductions['undertime_deduction'] +
        $deductions['halfday_deduction'];

    return $deductions;
}

// ============ PAYROLL CALCULATION FUNCTIONS ============
function calculateEarnings($employeeData)
{
    $basicSalary = $employeeData['salary'];
    $overtimeHours = rand(0, 20);
    $overtimePay = $overtimeHours * ($basicSalary / 160) * 1.25;
    $nightDiffHours = rand(0, 10);
    $nightDiffPay = $nightDiffHours * ($basicSalary / 160) * 0.10;

    return [
        'basic_salary' => $basicSalary,
        'overtime_hours' => $overtimeHours,
        'overtime_pay' => $overtimePay,
        'night_diff_hours' => $nightDiffHours,
        'night_diff_pay' => $nightDiffPay,
        'holiday_pay' => 0,
        'allowances' => 0,
        'bonuses' => 0
    ];
}

function calculateSimplifiedPayroll($pdo, $employeeData, $attendanceData, $payPeriodStart, $payPeriodEnd)
{
    $payrollCalculation = [
        'employee_info' => $employeeData,
        'period' => [
            'start' => $payPeriodStart,
            'end' => $payPeriodEnd
        ],
        'earnings' => [],
        'deductions' => [
            'attendance' => []
        ],
        'attendance_summary' => [],
        'summary' => []
    ];

    // Calculate Earnings
    $payrollCalculation['earnings'] = calculateEarnings($employeeData);

    // Calculate Attendance Deductions
    $dailyRate = $employeeData['salary'] / 22;
    $attendanceDeductions = calculateAttendanceDeductions($attendanceData, $dailyRate);
    $payrollCalculation['deductions']['attendance'] = $attendanceDeductions;
    $payrollCalculation['attendance_summary'] = $attendanceDeductions['summary'];

    // Calculate Final Summary
    $earnings = $payrollCalculation['earnings'];
    $deductions = $payrollCalculation['deductions'];

    $grossPay = $earnings['basic_salary']
        + $earnings['overtime_pay']
        + $earnings['night_diff_pay']
        + $earnings['holiday_pay']
        + $earnings['allowances']
        + $earnings['bonuses'];

    $totalDeductions = $deductions['attendance']['total_attendance_deduction'];
    $netPay = $grossPay - $totalDeductions;

    $payrollCalculation['summary'] = [
        'gross_pay' => $grossPay,
        'total_deductions' => $totalDeductions,
        'net_pay' => $netPay,
        'breakdown' => [
            'earnings_total' => $grossPay,
            'attendance_deductions' => $deductions['attendance']['total_attendance_deduction']
        ]
    ];

    return $payrollCalculation;
}

// ============ EMPLOYEE DATA FUNCTIONS ============
function getEmployeeData($pdo, $employeeId)
{
    // FIXED: Using the correct table and column names from your SQL dump
    $sql = "SELECT id, name, department, job_title as position, employment_status, salary 
            FROM employees 
            WHERE id = ? AND status = 'Active'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$employeeId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getAllActiveEmployees($pdo)
{
    // FIXED: Using the correct table and column names from your SQL dump
    $sql = "SELECT id, name, department, job_title as position, employment_status, salary 
            FROM employees 
            WHERE status = 'Active' 
            ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ============ PAYROLL RECORD FUNCTIONS ============
function addPayrollRecord($pdo, $data)
{
    $employeeData = getEmployeeData($pdo, $data['employee_id']);

    if (!$employeeData) {
        $_SESSION['error_message'] = "Employee not found or not active!";
        return;
    }

    $payPeriodStart = $data['pay_period_start'];
    $payPeriodEnd = $data['pay_period_end'];

    // Fetch attendance data
    $attendanceData = fetchAttendanceData($employeeData['id'], $payPeriodStart, $payPeriodEnd);

    // Calculate payroll
    $payrollCalculation = calculateSimplifiedPayroll($pdo, $employeeData, $attendanceData, $payPeriodStart, $payPeriodEnd);

    // Calculate final totals
    $summary = $payrollCalculation['summary'];
    $deductions = $payrollCalculation['deductions']['attendance'];

    // Insert payroll record
    $sql = "
        INSERT INTO payroll_records (
            employee_id, employee_name, department, pay_period_start, pay_period_end, 
            basic_salary, overtime_hours, overtime_pay, night_diff_hours, night_diff_pay,
            holiday_pay, allowances, bonuses, gross_pay, 
            late_deduction, absence_deduction, undertime_deduction, halfday_deduction,
            total_deductions, net_pay, status, calculation_details, attendance_data
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            $employeeData['id'],
            $employeeData['name'],
            $employeeData['department'],
            $payPeriodStart,
            $payPeriodEnd,
            $payrollCalculation['earnings']['basic_salary'],
            $payrollCalculation['earnings']['overtime_hours'] ?? 0,
            $payrollCalculation['earnings']['overtime_pay'] ?? 0,
            $payrollCalculation['earnings']['night_diff_hours'] ?? 0,
            $payrollCalculation['earnings']['night_diff_pay'] ?? 0,
            $payrollCalculation['earnings']['holiday_pay'] ?? 0,
            $payrollCalculation['earnings']['allowances'] ?? 0,
            $payrollCalculation['earnings']['bonuses'] ?? 0,
            $summary['gross_pay'],
            $deductions['late_deduction'] ?? 0,
            $deductions['absence_deduction'] ?? 0,
            $deductions['undertime_deduction'] ?? 0,
            $deductions['halfday_deduction'] ?? 0,
            $summary['total_deductions'],
            $summary['net_pay'],
            'Pending',
            json_encode($payrollCalculation, JSON_PRETTY_PRINT),
            json_encode($attendanceData, JSON_PRETTY_PRINT)
        ]);

        if ($result) {
            $recordId = $pdo->lastInsertId();

            // Store calculation in session for display
            $_SESSION['payroll_calculation'] = $payrollCalculation;
            $_SESSION['payroll_record_id'] = $recordId;

            $_SESSION['success_message'] = "Payroll record added successfully with automatic attendance calculation!";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding payroll record: " . $e->getMessage();
        error_log("Error in addPayrollRecord: " . $e->getMessage());
    }

    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

function updatePayrollRecord($pdo, $data)
{
    $paymentDate = null;
    if (($data['status'] ?? '') === 'Paid' || ($data['status'] ?? '') === 'Released') {
        $paymentDate = date('Y-m-d');
    }

    try {
        $sql = "UPDATE payroll_records SET 
                employee_id = ?, 
                pay_period_start = ?, 
                pay_period_end = ?, 
                basic_salary = ?, 
                overtime_hours = ?, 
                overtime_pay = ?, 
                night_diff_hours = ?, 
                night_diff_pay = ?, 
                holiday_pay = ?, 
                allowances = ?, 
                bonuses = ?, 
                gross_pay = ?, 
                late_deduction = ?, 
                absence_deduction = ?, 
                undertime_deduction = ?, 
                halfday_deduction = ?, 
                total_deductions = ?, 
                net_pay = ?, 
                status = ?, 
                payment_date = ? 
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['employee_id'],
            $data['pay_period_start'],
            $data['pay_period_end'],
            $data['basic_salary'],
            $data['overtime_hours'] ?? 0,
            $data['overtime_pay'] ?? 0,
            $data['night_diff_hours'] ?? 0,
            $data['night_diff_pay'] ?? 0,
            $data['holiday_pay'] ?? 0,
            $data['allowances'] ?? 0,
            $data['bonuses'] ?? 0,
            $data['gross_pay'],
            $data['late_deduction'] ?? 0,
            $data['absence_deduction'] ?? 0,
            $data['undertime_deduction'] ?? 0,
            $data['halfday_deduction'] ?? 0,
            $data['total_deductions'],
            $data['net_pay'],
            $data['status'] ?? 'Pending',
            $paymentDate,
            $data['id']
        ]);

        $_SESSION['success_message'] = "Payroll record updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating payroll record: " . $e->getMessage();
        error_log("Error in updatePayrollRecord: " . $e->getMessage());
    }

    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

function deletePayrollRecord($pdo, $id)
{
    $sql = "DELETE FROM payroll_records WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $_SESSION['success_message'] = "Payroll record deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting payroll record: " . $e->getMessage();
        error_log("Error in deletePayrollRecord: " . $e->getMessage());
    }

    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

function viewPayrollDetails($pdo, $id)
{
    $sql = "SELECT * FROM payroll_records WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($record) {
        $_SESSION['view_record'] = $record;
        $_SESSION['view_calculation'] = json_decode($record['calculation_details'], true);
        $_SESSION['view_attendance'] = json_decode($record['attendance_data'], true);
    }

    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?') . '?view=' . $id);
    exit;
}

function calculateAutomaticPayroll($pdo, $data)
{
    $employeeId = $data['employee_id'] ?? null;
    $payPeriodStart = $data['pay_period_start'] ?? null;
    $payPeriodEnd = $data['pay_period_end'] ?? null;

    if (!$employeeId || !$payPeriodStart || !$payPeriodEnd) {
        $_SESSION['error_message'] = "Please select employee and enter pay period dates first.";
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }

    $employeeData = getEmployeeData($pdo, $employeeId);
    if (!$employeeData) {
        $_SESSION['error_message'] = "Employee not found or not active!";
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    }

    // Fetch attendance data
    $attendanceData = fetchAttendanceData($employeeId, $payPeriodStart, $payPeriodEnd);
    $payrollCalculation = calculateSimplifiedPayroll($pdo, $employeeData, $attendanceData, $payPeriodStart, $payPeriodEnd);

    // Store calculation in session
    $_SESSION['payroll_calculation'] = $payrollCalculation;
    $_SESSION['automatic_calculation'] = true;

    $_SESSION['success_message'] = "Payroll calculated automatically with attendance data!";
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ============ SALARY RELEASE FUNCTIONS ============
function releaseSalary($pdo, $payrollId)
{
    try {
        // Get payroll record
        $sql = "SELECT * FROM payroll_records WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$payrollId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $_SESSION['error_message'] = "Payroll record not found!";
            return false;
        }

        if ($record['status'] !== 'Paid') {
            $_SESSION['error_message'] = "Only 'Paid' payroll records can be released!";
            return false;
        }

        // Update status to Released
        $updateSql = "UPDATE payroll_records SET 
                      status = 'Released', 
                      payment_date = NOW(),
                      updated_at = NOW()
                      WHERE id = ?";

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$payrollId]);

        $_SESSION['success_message'] = "Salary released successfully for " . htmlspecialchars($record['employee_name']) . "!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error releasing salary: " . $e->getMessage();
        error_log("Error in releaseSalary: " . $e->getMessage());
        return false;
    }
}

function releaseMultipleSalaries($pdo, $payrollIds)
{
    try {
        $placeholders = str_repeat('?,', count($payrollIds) - 1) . '?';

        // Get records to release
        $sql = "SELECT * FROM payroll_records WHERE id IN ($placeholders) AND status = 'Paid'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($payrollIds);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            $_SESSION['error_message'] = "No valid 'Paid' payroll records found to release!";
            return false;
        }

        // Update status to Released
        $updateSql = "UPDATE payroll_records SET 
                      status = 'Released', 
                      payment_date = NOW(),
                      updated_at = NOW()
                      WHERE id IN ($placeholders) AND status = 'Paid'";

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($payrollIds);
        $affectedRows = $updateStmt->rowCount();

        $_SESSION['success_message'] = "Successfully released salaries for $affectedRows employees!";
        return true;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error releasing salaries: " . $e->getMessage();
        error_log("Error in releaseMultipleSalaries: " . $e->getMessage());
        return false;
    }
}

// ============ MARK AS PAID FUNCTIONS ============
function markAsPaid($pdo, $payrollId)
{
    try {
        $sql = "UPDATE payroll_records SET 
                status = 'Paid',
                payment_date = NOW(),
                updated_at = NOW()
                WHERE id = ? AND status IN ('Pending', 'Processed')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$payrollId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['success_message'] = "Payroll marked as Paid successfully!";
        } else {
            $_SESSION['error_message'] = "Payroll record not found or already paid/released!";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error marking as paid: " . $e->getMessage();
        error_log("Error in markAsPaid: " . $e->getMessage());
    }
}

function markMultipleAsPaid($pdo, $payrollIds)
{
    try {
        $placeholders = str_repeat('?,', count($payrollIds) - 1) . '?';

        $sql = "UPDATE payroll_records SET 
                status = 'Paid',
                payment_date = NOW(),
                updated_at = NOW()
                WHERE id IN ($placeholders) AND status IN ('Pending', 'Processed')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($payrollIds);
        $affectedRows = $stmt->rowCount();

        $_SESSION['success_message'] = "Successfully marked $affectedRows payroll records as Paid!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error marking as paid: " . $e->getMessage();
        error_log("Error in markMultipleAsPaid: " . $e->getMessage());
    }
}

function generatePayslip($pdo, $payrollId)
{
    try {
        // Get payroll record with calculation details
        // FIXED: Using only existing columns from your employees table
        $sql = "SELECT pr.*, e.email, e.employment_status 
                FROM payroll_records pr
                LEFT JOIN employees e ON pr.employee_id = e.id
                WHERE pr.id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$payrollId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $_SESSION['error_message'] = "Payroll record not found!";
            return false;
        }

        // Decode calculation details
        $calculation = json_decode($record['calculation_details'] ?? '{}', true);
        $attendance = json_decode($record['attendance_data'] ?? '{}', true);

        // Generate payslip HTML
        $payslip = generatePayslipHTML($record, $calculation, $attendance);

        return [
            'html' => $payslip,
            'employee_name' => $record['employee_name'],
            'pay_period' => $record['pay_period_start'] . ' to ' . $record['pay_period_end'],
            'net_pay' => $record['net_pay']
        ];
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error generating payslip: " . $e->getMessage();
        error_log("Error in generatePayslip: " . $e->getMessage());
        return false;
    }
}

function generatePayslipHTML($record, $calculation, $attendance)
{
    $payslipDate = date('F j, Y');
    $paymentDate = $record['payment_date'] ? date('F j, Y', strtotime($record['payment_date'])) : 'N/A';

    // Calculate days in period
    $start = new DateTime($record['pay_period_start']);
    $end = new DateTime($record['pay_period_end']);
    $workingDays = $start->diff($end)->days + 1;

    // Calculate attendance summary
    $attendanceSummary = [
        'working_days' => $workingDays,
        'present_days' => 0,
        'absent_days' => 0,
        'late_days' => 0,
        'halfday_days' => 0,
        'undertime_days' => 0,
        'total_late_minutes' => 0,
        'total_undertime_minutes' => 0
    ];

    if (isset($attendance['attendance_records'])) {
        foreach ($attendance['attendance_records'] as $attendanceRecord) {
            switch ($attendanceRecord['status']) {
                case 'Present':
                    $attendanceSummary['present_days']++;
                    break;
                case 'Absent':
                    $attendanceSummary['absent_days']++;
                    break;
                case 'Late':
                    $attendanceSummary['late_days']++;
                    break;
                case 'Half Day':
                    $attendanceSummary['halfday_days']++;
                    break;
                case 'Undertime':
                    $attendanceSummary['undertime_days']++;
                    break;
            }
            $attendanceSummary['total_late_minutes'] += $attendanceRecord['late_minutes'] ?? 0;
            $attendanceSummary['total_undertime_minutes'] += $attendanceRecord['undertime_minutes'] ?? 0;
        }
    }

    // Start building HTML - simplified without heredoc issues
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Payslip - ' . htmlspecialchars($record['employee_name']) . '</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                color: #333;
                background: #f5f5f5;
            }
            .payslip-container {
                max-width: 800px;
                margin: 0 auto;
                border: 2px solid #333;
                padding: 20px;
                background: white;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            .header {
                text-align: center;
                border-bottom: 2px solid #333;
                padding-bottom: 20px;
                margin-bottom: 20px;
            }
            .company-name {
                font-size: 24px;
                font-weight: bold;
                color: #2c3e50;
            }
            .company-address {
                font-size: 14px;
                color: #666;
                margin-bottom: 10px;
            }
            .payslip-title {
                font-size: 20px;
                font-weight: bold;
                margin-top: 10px;
                color: #2c3e50;
            }
            .section {
                margin-bottom: 20px;
            }
            .section-title {
                background: #f8f9fa;
                padding: 8px;
                font-weight: bold;
                border-left: 4px solid #4e73df;
                margin-bottom: 10px;
            }
            .info-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            .info-item {
                margin-bottom: 8px;
            }
            .info-label {
                font-weight: bold;
                color: #666;
                font-size: 14px;
            }
            .info-value {
                font-size: 14px;
            }
            .earnings-table, .deductions-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            .earnings-table th, .deductions-table th,
            .earnings-table td, .deductions-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            .earnings-table th, .deductions-table th {
                background: #f8f9fa;
                font-weight: bold;
            }
            .total-row {
                background: #e8f4fd !important;
                font-weight: bold;
            }
            .net-pay-row {
                background: #d4edda !important;
                font-weight: bold;
                font-size: 16px;
            }
            .footer {
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #ddd;
                text-align: center;
                font-size: 12px;
                color: #666;
            }
            .signature-area {
                margin-top: 40px;
                display: flex;
                justify-content: space-between;
            }
            .signature-box {
                text-align: center;
                width: 45%;
            }
            .signature-line {
                border-top: 1px solid #333;
                margin: 40px 0 5px 0;
            }
            @media print {
                body {
                    margin: 0;
                    background: white;
                }
                .no-print {
                    display: none !important;
                }
                .payslip-container {
                    border: none;
                    box-shadow: none;
                    padding: 10px;
                }
            }
        </style>
    </head>
    <body>
        <div class="payslip-container">
            <div class="header">
                <div class="company-name">SLATE FREIGHT</div>
                <div class="company-address">Company Address, City, Country | Phone: (123) 456-7890</div>
                <div class="payslip-title">PAYSLIP</div>
                <div style="font-size: 12px; color: #666; margin-top: 5px;">Pay Period: ' . $record['pay_period_start'] . ' to ' . $record['pay_period_end'] . '</div>
            </div>

            <div class="section">
                <div class="section-title">Employee Information</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Employee Name:</div>
                        <div class="info-value">' . htmlspecialchars($record['employee_name']) . '</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Employee ID:</div>
                        <div class="info-value">' . htmlspecialchars($record['employee_id']) . '</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Department:</div>
                        <div class="info-value">' . htmlspecialchars($record['department']) . '</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Employment Status:</div>
                        <div class="info-value">' . htmlspecialchars($record['employment_status']) . '</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Pay Date:</div>
                        <div class="info-value">' . $paymentDate . '</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Payslip Date:</div>
                        <div class="info-value">' . $payslipDate . '</div>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Attendance Summary</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Working Days:</div>
                        <div class="info-value">' . $attendanceSummary['working_days'] . ' days</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Present Days:</div>
                        <div class="info-value">' . $attendanceSummary['present_days'] . ' days</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Absent Days:</div>
                        <div class="info-value">' . $attendanceSummary['absent_days'] . ' days</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Late Days:</div>
                        <div class="info-value">' . $attendanceSummary['late_days'] . ' days (' . $attendanceSummary['total_late_minutes'] . ' mins)</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Half Days:</div>
                        <div class="info-value">' . $attendanceSummary['halfday_days'] . ' days</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Undertime Days:</div>
                        <div class="info-value">' . $attendanceSummary['undertime_days'] . ' days (' . $attendanceSummary['total_undertime_minutes'] . ' mins)</div>
                    </div>
                </div>
            </div>

            <div class="section">
                <div class="section-title">Earnings</div>
                <table class="earnings-table">
                    <tr>
                        <th>Description</th>
                        <th>Hours/Qty</th>
                        <th>Rate</th>
                        <th>Amount (₱)</th>
                    </tr>
                    <tr>
                        <td>Basic Salary</td>
                        <td></td>
                        <td></td>
                        <td>' . number_format($record['basic_salary'], 2) . '</td>
                    </tr>';

    if ($record['overtime_pay'] > 0) {
        $overtimeRate = $record['overtime_hours'] > 0 ? number_format(($record['overtime_pay'] / $record['overtime_hours']), 2) : '0.00';
        $html .= '<tr>
                        <td>Overtime Pay</td>
                        <td>' . $record['overtime_hours'] . ' hours</td>
                        <td>' . $overtimeRate . '/hr</td>
                        <td>' . number_format($record['overtime_pay'], 2) . '</td>
                    </tr>';
    }

    if ($record['night_diff_pay'] > 0) {
        $nightDiffRate = $record['night_diff_hours'] > 0 ? number_format(($record['night_diff_pay'] / $record['night_diff_hours']), 2) : '0.00';
        $html .= '<tr>
                        <td>Night Differential</td>
                        <td>' . $record['night_diff_hours'] . ' hours</td>
                        <td>' . $nightDiffRate . '/hr</td>
                        <td>' . number_format($record['night_diff_pay'], 2) . '</td>
                    </tr>';
    }

    if ($record['allowances'] > 0) {
        $html .= '<tr>
                        <td>Allowances</td>
                        <td></td>
                        <td></td>
                        <td>' . number_format($record['allowances'], 2) . '</td>
                    </tr>';
    }

    if ($record['bonuses'] > 0) {
        $html .= '<tr>
                        <td>Bonuses</td>
                        <td></td>
                        <td></td>
                        <td>' . number_format($record['bonuses'], 2) . '</td>
                    </tr>';
    }

    $html .= '<tr class="total-row">
                        <td colspan="3"><strong>TOTAL GROSS PAY</strong></td>
                        <td><strong>₱' . number_format($record['gross_pay'], 2) . '</strong></td>
                    </tr>
                </table>
            </div>

            <div class="section">
                <div class="section-title">Deductions</div>
                <table class="deductions-table">
                    <tr>
                        <th>Description</th>
                        <th>Details</th>
                        <th>Amount (₱)</th>
                    </tr>';

    if ($record['late_deduction'] > 0) {
        $html .= '<tr>
                        <td>Late Deduction</td>
                        <td>' . ($attendanceSummary['total_late_minutes'] ?? 0) . ' minutes</td>
                        <td>' . number_format($record['late_deduction'], 2) . '</td>
                    </tr>';
    }

    if ($record['absence_deduction'] > 0) {
        $html .= '<tr>
                        <td>Absence Deduction</td>
                        <td>' . $attendanceSummary['absent_days'] . ' days</td>
                        <td>' . number_format($record['absence_deduction'], 2) . '</td>
                    </tr>';
    }

    if ($record['undertime_deduction'] > 0) {
        $html .= '<tr>
                        <td>Undertime Deduction</td>
                        <td>' . ($attendanceSummary['total_undertime_minutes'] ?? 0) . ' minutes</td>
                        <td>' . number_format($record['undertime_deduction'], 2) . '</td>
                    </tr>';
    }

    if ($record['halfday_deduction'] > 0) {
        $html .= '<tr>
                        <td>Half Day Deduction</td>
                        <td>' . $attendanceSummary['halfday_days'] . ' days</td>
                        <td>' . number_format($record['halfday_deduction'], 2) . '</td>
                    </tr>';
    }

    $html .= '<tr class="total-row">
                        <td colspan="2"><strong>TOTAL DEDUCTIONS</strong></td>
                        <td><strong>₱' . number_format($record['total_deductions'], 2) . '</strong></td>
                    </tr>
                </table>
            </div>

            <div class="section">
                <table class="earnings-table">
                    <tr class="net-pay-row">
                        <td colspan="3"><strong>NET PAY (TAKE HOME PAY)</strong></td>
                        <td><strong>₱' . number_format($record['net_pay'], 2) . '</strong></td>
                    </tr>
                </table>
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div>Employee\'s Signature</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div>Authorized Signatory</div>
                </div>
            </div>

            <div class="footer">
                <div>This is a computer-generated payslip. No signature is required.</div>
                <div>For inquiries, please contact HR Department.</div>
                <div>Confidential - For Employee Use Only</div>
            </div>
        </div>
        
        <div class="no-print" style="text-align: center; margin-top: 20px;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #4e73df; color: white; border: none; cursor: pointer; border-radius: 4px;">
                Print Payslip
            </button>
            <button onclick="window.close()" style="padding: 10px 20px; background: #6c757d; color: white; border: none; cursor: pointer; margin-left: 10px; border-radius: 4px;">
                Close
            </button>
        </div>
        <script>
            window.onload = function() {
                // Auto-print option (optional)
                // window.print();
            }
        </script>
    </body>
    </html>';

    return $html;
}

function generatePayslipsBatch($pdo, $payrollIds)
{
    $results = [];
    foreach ($payrollIds as $id) {
        $result = generatePayslip($pdo, $id);
        if ($result) {
            $results[] = $result;
        }
    }
    return $results;
}

// ============ BUDGET FUNCTIONS ============
function computeAllPayrollsAndCreateBudget($pdo)
{
    try {
        // Get all pending payroll records
        $sql = "SELECT * FROM payroll_records WHERE status = 'Pending' ORDER BY pay_period_start, employee_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $payrollRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($payrollRecords)) {
            $_SESSION['error_message'] = "No pending payroll records found to compute!";
            return;
        }

        // Initialize totals
        $totalEmployees = 0;
        $totalGrossPay = 0;
        $totalDeductions = 0;
        $totalNetPay = 0;
        $processedEmployees = [];
        $budgetPeriodStart = null;
        $budgetPeriodEnd = null;

        // Calculate totals from all pending records
        foreach ($payrollRecords as $record) {
            $totalEmployees++;
            $totalGrossPay += $record['gross_pay'];
            $totalDeductions += $record['total_deductions'];
            $totalNetPay += $record['net_pay'];

            // Track processed employees
            if (!in_array($record['employee_id'], $processedEmployees)) {
                $processedEmployees[] = $record['employee_id'];
            }

            // Determine budget period (use the earliest start and latest end)
            if (!$budgetPeriodStart || $record['pay_period_start'] < $budgetPeriodStart) {
                $budgetPeriodStart = $record['pay_period_start'];
            }
            if (!$budgetPeriodEnd || $record['pay_period_end'] > $budgetPeriodEnd) {
                $budgetPeriodEnd = $record['pay_period_end'];
            }
        }

        // Generate budget name
        $budgetName = "Payroll Budget " . date('M Y', strtotime($budgetPeriodStart));

        // Create payroll budget record
        $budgetSql = "
            INSERT INTO payroll_budgets (
                budget_name,
                budget_period_start,
                budget_period_end,
                total_employees,
                total_gross_pay,
                total_deductions,
                total_net_pay,
                budget_status,
                approval_status,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $budgetStmt = $pdo->prepare($budgetSql);
        $budgetStmt->execute([
            $budgetName,
            $budgetPeriodStart,
            $budgetPeriodEnd,
            count($processedEmployees),
            $totalGrossPay,
            $totalDeductions,
            $totalNetPay,
            'Draft',
            'Draft',
            isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'System'
        ]);

        $budgetId = $pdo->lastInsertId();

        // Update payroll records status to 'Processed'
        $updateSql = "UPDATE payroll_records SET status = 'Processed' WHERE status = 'Pending'";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute();

        // Store budget summary in session for display
        $_SESSION['budget_summary'] = [
            'budget_id' => $budgetId,
            'budget_name' => $budgetName,
            'period_start' => $budgetPeriodStart,
            'period_end' => $budgetPeriodEnd,
            'total_employees' => count($processedEmployees),
            'total_gross_pay' => $totalGrossPay,
            'total_deductions' => $totalDeductions,
            'total_net_pay' => $totalNetPay,
            'processed_records' => count($payrollRecords),
            'approval_status' => 'Draft'
        ];

        $_SESSION['success_message'] = "Payroll budget created successfully! " .
            count($payrollRecords) . " records processed. " .
            "Total Net Pay: ₱" . number_format($totalNetPay, 2);
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error creating payroll budget: " . $e->getMessage();
        error_log("Error in computeAllPayrollsAndCreateBudget: " . $e->getMessage());
    }

    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ============ BUDGET APPROVAL FUNCTIONS ============
function sendBudgetForApproval($pdo, $budgetId)
{
    try {
        $sql = "UPDATE payroll_budgets SET 
                approval_status = 'Waiting for Approval',
                submitted_for_approval_at = NOW()
                WHERE id = ? AND (approval_status = 'Draft' OR approval_status IS NULL)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$budgetId]);

        if ($stmt->rowCount() > 0) {
            $_SESSION['success_message'] = "Budget #$budgetId has been sent for financial approval!";
        } else {
            $_SESSION['error_message'] = "Budget not found or already submitted for approval.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error sending budget for approval: " . $e->getMessage();
        error_log("Error in sendBudgetForApproval: " . $e->getMessage());
    }

    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

function viewBudgetDetails($pdo, $budgetId)
{
    $sql = "SELECT * FROM payroll_budgets WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$budgetId]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($budget) {
        // Ensure approval_status exists
        if (!isset($budget['approval_status'])) {
            $budget['approval_status'] = 'Draft';
        }

        // Get payroll records included in this budget period
        $recordsSql = "
            SELECT * FROM payroll_records 
            WHERE pay_period_start >= ? AND pay_period_end <= ? 
            ORDER BY employee_name
        ";
        $recordsStmt = $pdo->prepare($recordsSql);
        $recordsStmt->execute([$budget['budget_period_start'], $budget['budget_period_end']]);
        $includedRecords = $recordsStmt->fetchAll(PDO::FETCH_ASSOC);

        $_SESSION['view_budget'] = $budget;
        $_SESSION['view_budget_records'] = $includedRecords;
    }

    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?') . '?budget_view=' . $budgetId);
    exit;
}

function getBudgetStatistics($pdo)
{
    $stats = [
        'total_budgets' => 0,
        'total_amount' => 0,
        'pending_approval' => 0,
        'approved_budgets' => 0,
        'draft_budgets' => 0,
        'latest_budget' => null
    ];

    try {
        // Total number of budgets
        $sql = "SELECT 
                COUNT(*) as count, 
                SUM(total_net_pay) as total,
                COUNT(CASE WHEN approval_status = 'Waiting for Approval' THEN 1 END) as pending,
                COUNT(CASE WHEN approval_status = 'Approved' THEN 1 END) as approved,
                COUNT(CASE WHEN approval_status = 'Draft' OR approval_status IS NULL THEN 1 END) as draft
                FROM payroll_budgets";
        $stmt = $pdo->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $stats['total_budgets'] = $result['count'] ?? 0;
        $stats['total_amount'] = $result['total'] ?? 0;
        $stats['pending_approval'] = $result['pending'] ?? 0;
        $stats['approved_budgets'] = $result['approved'] ?? 0;
        $stats['draft_budgets'] = $result['draft'] ?? 0;

        // Latest budget
        $sql = "SELECT * FROM payroll_budgets ORDER BY created_at DESC LIMIT 1";
        $stmt = $pdo->query($sql);
        $latest = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($latest && !isset($latest['approval_status'])) {
            $latest['approval_status'] = 'Draft';
        }

        $stats['latest_budget'] = $latest;
    } catch (PDOException $e) {
        error_log("Error getting budget statistics: " . $e->getMessage());
    }

    return $stats;
}

function getAllBudgets($pdo)
{
    try {
        $sql = "SELECT * FROM payroll_budgets ORDER BY created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Ensure approval_status exists for all budgets
        foreach ($budgets as &$budget) {
            if (!isset($budget['approval_status'])) {
                $budget['approval_status'] = 'Draft'; // Default value
            }
        }

        return $budgets;
    } catch (PDOException $e) {
        error_log("Error fetching budgets: " . $e->getMessage());
        return [];
    }
}

// ============ GET DATA FOR DISPLAY ============
// Ensure $pdo exists before fetching data
if (!isset($pdo)) {
    die("Database connection not available. Cannot load data.");
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$department_filter = $_GET['department'] ?? '';
$status_filter = $_GET['status'] ?? '';
$pay_period = $_GET['pay_period'] ?? '';

// Get active employees
$employees = getAllActiveEmployees($pdo);

// Get payroll records with filters
$payroll_records = [];
$query = "SELECT * FROM payroll_records WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (employee_name LIKE ? OR employee_id LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($department_filter)) {
    $query .= " AND department = ?";
    $params[] = $department_filter;
}

if (!empty($status_filter)) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

if (!empty($pay_period)) {
    $query .= " AND pay_period_start = ?";
    $params[] = $pay_period;
}

$query .= " ORDER BY pay_period_start DESC, created_at DESC";

try {
    $payroll_records_stmt = $pdo->prepare($query);
    $payroll_records_stmt->execute($params);
    $payroll_records = $payroll_records_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching payroll records: " . $e->getMessage());
    $payroll_records = [];
}

// Get unique departments for filter
$departments = [];
try {
    $dept_stmt = $pdo->query("SELECT DISTINCT department FROM payroll_records WHERE department IS NOT NULL AND department != '' ORDER BY department");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching departments: " . $e->getMessage());
    $departments = [];
}

// Get budget statistics and data
$budgetStats = getBudgetStatistics($pdo);
$allBudgets = getAllBudgets($pdo);

// Get pending payroll count
$pendingCount = 0;
$paidCount = 0;
$releasedCount = 0;
try {
    $statsSql = "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'Pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'Paid' THEN 1 END) as paid,
                COUNT(CASE WHEN status = 'Released' THEN 1 END) as released
                FROM payroll_records";
    $statsStmt = $pdo->query($statsSql);
    $statsResult = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $pendingCount = $statsResult['pending'] ?? 0;
    $paidCount = $statsResult['paid'] ?? 0;
    $releasedCount = $statsResult['released'] ?? 0;
} catch (PDOException $e) {
    error_log("Error fetching payroll statistics: " . $e->getMessage());
    $pendingCount = 0;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Management | HR System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Theme Variables */
        :root {
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --dark-bg: #2c3e50;
            --dark-card: #34495e;
            --text-light: #f8f9fa;
            --text-dark: #212529;
            --border-radius: 0.35rem;
            --shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --success-color: #1cc88a;
            --info-color: #36b9cc;
            --purple-color: #6f42c1;
        }

        /* Base Styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            transition: background 0.3s, color 0.3s;
            line-height: 1.4;
        }

        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
        }

        /* Theme Toggle */
        .theme-toggle-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .theme-toggle-btn {
            background: #f8f9fc;
            border: 1px solid #e3e6f0;
            border-radius: 20px;
            padding: 10px 15px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            transition: all 0.3s;
            text-decoration: none;
            color: inherit;
            box-shadow: var(--shadow);
        }

        body.dark-mode .theme-toggle-btn {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }

        .theme-toggle-btn:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }

        body.dark-mode .theme-toggle-btn:hover {
            background: #4a5568;
        }

        /* Main Content */
        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            margin-top: 60px;
        }

        body.dark-mode .main-content {
            background-color: var(--dark-bg);
        }

        .content-area {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        body.dark-mode .content-area {
            background: var(--dark-card);
        }

        /* Header */
        .page-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        body.dark-mode .page-header {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 0.9rem;
        }

        body.dark-mode .page-subtitle {
            color: #a0aec0;
        }

        /* Navigation */
        .nav-container {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        body.dark-mode .nav-container {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
        }

        .nav-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .nav-link {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s;
        }

        .nav-link:hover {
            color: #2e59d9;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1rem 1.5rem;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            border-color: var(--success-color);
            color: #155724;
        }

        body.dark-mode .alert-success {
            background: #22543d;
            color: #9ae6b4;
        }

        .alert-error {
            background: #f8d7da;
            border-color: var(--danger-color);
            color: #721c24;
        }

        body.dark-mode .alert-error {
            background: #744210;
            color: #fbd38d;
        }

        /* Batch Actions Bar */
        .batch-actions-bar {
            background: #e8f4fd;
            padding: 15px;
            margin: 1.5rem;
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            gap: 15px;
            border-left: 4px solid var(--primary-color);
        }

        body.dark-mode .batch-actions-bar {
            background: #2d3748;
        }

        .select-all-container {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .batch-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Budget Summary */
        .budget-summary-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
            border-left: 4px solid var(--purple-color);
        }

        body.dark-mode .budget-summary-container {
            background: var(--dark-card);
        }

        .budget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .budget-actions {
            display: flex;
            gap: 0.5rem;
        }

        .budget-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .budget-stat-card {
            background: #f8f9fc;
            padding: 1rem;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
        }

        body.dark-mode .budget-stat-card {
            background: #2d3748;
        }

        .budget-stat-card.warning {
            border-left-color: var(--warning-color);
        }

        .budget-stat-card.success {
            border-left-color: var(--success-color);
        }

        .budget-stat-card.info {
            border-left-color: var(--info-color);
        }

        .budget-stat-card.purple {
            border-left-color: var(--purple-color);
        }

        .budget-stat-card.danger {
            border-left-color: var(--danger-color);
        }

        .budget-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .budget-stat-card.warning .budget-stat-value {
            color: var(--warning-color);
        }

        .budget-stat-card.success .budget-stat-value {
            color: var(--success-color);
        }

        .budget-stat-card.info .budget-stat-value {
            color: var(--info-color);
        }

        .budget-stat-card.purple .budget-stat-value {
            color: var(--purple-color);
        }

        .budget-stat-card.danger .budget-stat-value {
            color: var(--danger-color);
        }

        .budget-stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: 600;
        }

        body.dark-mode .budget-stat-label {
            color: #a0aec0;
        }

        /* Budget Table */
        .budget-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .budget-table th {
            background: #f8f9fc;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .budget-table th {
            background: #2d3748;
            border-bottom: 1px solid #4a5568;
        }

        .budget-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .budget-table td {
            border-bottom: 1px solid #4a5568;
        }

        /* Budget Status Badges */
        .budget-status-draft {
            background: #e9ecef;
            color: #495057;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .budget-status-waiting {
            background: #fff3cd;
            color: #856404;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-waiting {
            background: #744210;
            color: #fbd38d;
        }

        .budget-status-approved {
            background: #d4edda;
            color: #155724;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-approved {
            background: #22543d;
            color: #9ae6b4;
        }

        .budget-status-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-rejected {
            background: #744210;
            color: #fbd38d;
        }

        .budget-status-released {
            background: #cce5ff;
            color: #004085;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .budget-status-released {
            background: #2c5282;
            color: #bee3f8;
        }

        /* Forms */
        .form-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
        }

        body.dark-mode .form-container {
            background: var(--dark-card);
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .form-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e3e6f0;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: all 0.3s;
        }

        body.dark-mode .form-input,
        body.dark-mode .form-select,
        body.dark-mode .form-textarea {
            background: #2d3748;
            border-color: #4a5568;
            color: white;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2e59d9;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #17a673;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc3545;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background: #2c9faf;
            transform: translateY(-1px);
        }

        .btn-purple {
            background: var(--purple-color);
            color: white;
        }

        .btn-purple:hover {
            background: #5a3596;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        /* Search and Filter */
        .search-filter-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }

        body.dark-mode .search-filter-container {
            background: var(--dark-card);
        }

        .search-box {
            flex: 1;
            min-width: 250px;
        }

        .filter-box {
            min-width: 200px;
        }

        .search-actions {
            display: flex;
            gap: 0.5rem;
        }

        .search-form,
        .filter-form {
            display: flex;
            gap: 0.5rem;
        }

        /* Tables */
        .table-container {
            padding: 0 1.5rem 1.5rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        body.dark-mode .data-table {
            background: #2d3748;
        }

        .data-table th {
            background: #f8f9fc;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #4e73df;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .data-table th {
            background: #2d3748;
            color: #63b3ed;
            border-bottom: 1px solid #4a5568;
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
        }

        body.dark-mode .data-table td {
            border-bottom: 1px solid #4a5568;
        }

        .data-table tr:hover {
            background: #f8f9fc;
        }

        body.dark-mode .data-table tr:hover {
            background: #2d3748;
        }

        .amount {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-align: right;
        }

        /* Checkbox column */
        .checkbox-cell {
            width: 30px;
            text-align: center;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        body.dark-mode .status-pending {
            background: #744210;
            color: #fbd38d;
        }

        .status-paid {
            background: #d4edda;
            color: #155724;
        }

        body.dark-mode .status-paid {
            background: #22543d;
            color: #9ae6b4;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        body.dark-mode .status-cancelled {
            background: #744210;
            color: #fbd38d;
        }

        .status-processed {
            background: #cce5ff;
            color: #004085;
        }

        body.dark-mode .status-processed {
            background: #2c5282;
            color: #bee3f8;
        }

        .status-released {
            background: #c3e6cb;
            color: #155724;
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        body.dark-mode .status-released {
            background: #22543d;
            color: #9ae6b4;
        }

        /* Actions */
        .actions-cell {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Calculation Breakdown */
        .calculation-breakdown {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px;
            border: 2px solid #4e73df;
        }

        body.dark-mode .calculation-breakdown {
            background: #2d3748;
            border-color: #4e73df;
        }

        .calculation-breakdown .form-header {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        body.dark-mode .calculation-breakdown .form-header {
            background: #2c5282;
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 900px;
            max-height: 80vh;
            overflow-y: auto;
        }

        body.dark-mode .modal-content {
            background-color: var(--dark-card);
            color: var(--text-light);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }

        body.dark-mode .modal-header {
            border-bottom-color: #4a5568;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary-color);
        }

        body.dark-mode .modal-title {
            color: #63b3ed;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }

        body.dark-mode .close-btn {
            color: #a0aec0;
        }

        .close-btn:hover {
            color: #333;
        }

        body.dark-mode .close-btn:hover {
            color: white;
        }

        .view-section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }

        body.dark-mode .view-section {
            background: #2d3748;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: bold;
            margin-bottom: 10px;
            color: #2c3e50;
            border-bottom: 2px solid #4e73df;
            padding-bottom: 5px;
        }

        body.dark-mode .section-title {
            color: #f8f9fa;
            border-bottom-color: #63b3ed;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .info-item {
            margin-bottom: 10px;
        }

        .info-label {
            font-weight: bold;
            color: #666;
            font-size: 0.9rem;
        }

        body.dark-mode .info-label {
            color: #a0aec0;
        }

        .info-value {
            font-size: 1rem;
        }

        .budget-modal-content {
            max-width: 1000px;
            width: 90%;
        }

        .budget-breakdown-section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }

        body.dark-mode .budget-breakdown-section {
            background: #2d3748;
        }

        .budget-total-row {
            background: #e8f4fd !important;
            font-weight: bold;
        }

        body.dark-mode .budget-total-row {
            background: #2c5282 !important;
        }

        /* Attendance Table */
        .attendance-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .attendance-table th,
        .attendance-table td {
            padding: 8px;
            border: 1px solid #ddd;
            text-align: left;
        }

        body.dark-mode .attendance-table th,
        body.dark-mode .attendance-table td {
            border-color: #4a5568;
        }

        .attendance-table th {
            background-color: #f1f1f1;
            font-weight: bold;
        }

        body.dark-mode .attendance-table th {
            background-color: #2d3748;
        }

        .status-present {
            color: green;
            font-weight: bold;
        }

        .status-absent {
            color: red;
            font-weight: bold;
        }

        .status-late {
            color: orange;
            font-weight: bold;
        }

        .status-undertime {
            color: #ff9900;
            font-weight: bold;
        }

        .status-halfday {
            color: #800080;
            font-weight: bold;
        }

        .deduction-breakdown {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            border-left: 4px solid #e74a3b;
        }

        body.dark-mode .deduction-breakdown {
            background: #2d3748;
            border-left-color: #e74a3b;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }

            .actions-cell {
                flex-direction: column;
            }

            .search-filter-container {
                flex-direction: column;
                align-items: stretch;
            }

            .search-box,
            .filter-box {
                min-width: auto;
            }

            .budget-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .calculation-breakdown {
                margin: 10px;
                padding: 15px;
            }

            .budget-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .budget-actions {
                flex-direction: column;
                width: 100%;
            }

            .budget-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .batch-actions-bar {
                flex-direction: column;
                align-items: flex-start;
            }

            .batch-buttons {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.8rem;
            }

            .form-container {
                margin: 1rem;
                padding: 1rem;
            }

            .budget-stats-grid {
                grid-template-columns: 1fr;
            }

            .table-container {
                padding: 0 1rem 1rem;
            }

            .search-filter-container {
                margin: 1rem;
                padding: 1rem;
            }

            .calculation-breakdown {
                margin: 5px;
                padding: 10px;
            }
        }
    </style>
</head>



<div class="main-content">
    <div class="content-area">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-money-bill-wave"></i>
                Payroll Management System
            </h1>
            <p class="page-subtitle">Manage employee payroll calculations, release salaries, and generate payslips</p>
        </div>

        <!-- Navigation -->
        <div class="nav-container">
            <nav class="nav-breadcrumb">
                <a href="#" class="nav-link">
                    <i class="fas fa-home"></i>
                    Dashboard
                </a>
                <i class="fas fa-chevron-right"></i>
                <span>Payroll Management</span>
            </nav>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- ============ BUDGET SUMMARY SECTION ============ -->
        <div class="budget-summary-container">
            <div class="budget-header">
                <h2 class="form-title">
                    <i class="fas fa-chart-pie"></i>
                    Payroll Budget Summary
                </h2>
                <div class="budget-actions">
                    <form method="POST" action="" onsubmit="return confirm('This will compute ALL pending payroll records and create a budget. Continue?');">
                        <button type="submit" name="compute_all_budget" class="btn btn-purple">
                            <i class="fas fa-calculator"></i> Compute All & Create Budget
                        </button>
                    </form>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print"></i> Print Budget
                    </button>
                </div>
            </div>

            <!-- Budget Statistics -->
            <div class="budget-stats-grid">
                <div class="budget-stat-card">
                    <div class="budget-stat-value"><?php echo $budgetStats['total_budgets']; ?></div>
                    <div class="budget-stat-label">Total Budgets Created</div>
                </div>
                <div class="budget-stat-card warning">
                    <div class="budget-stat-value"><?php echo $budgetStats['pending_approval']; ?></div>
                    <div class="budget-stat-label">Pending Approval</div>
                </div>
                <div class="budget-stat-card success">
                    <div class="budget-stat-value"><?php echo $budgetStats['approved_budgets']; ?></div>
                    <div class="budget-stat-label">Approved Budgets</div>
                </div>
                <div class="budget-stat-card info">
                    <div class="budget-stat-value">
                        <?php if ($budgetStats['latest_budget']): ?>
                            ₱<?php echo number_format($budgetStats['latest_budget']['total_net_pay'], 2); ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </div>
                    <div class="budget-stat-label">Latest Budget Amount</div>
                </div>
            </div>

            <!-- Budget Summary Display -->
            <?php if (isset($_SESSION['budget_summary'])): ?>
                <div style="background: #e8f4fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                    <h4><i class="fas fa-file-invoice-dollar"></i> New Budget Created Successfully!</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Budget ID:</label>
                            <p><strong>#<?php echo $_SESSION['budget_summary']['budget_id']; ?></strong></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Budget Name:</label>
                            <p><?php echo htmlspecialchars($_SESSION['budget_summary']['budget_name']); ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Period:</label>
                            <p><?php echo date('M j, Y', strtotime($_SESSION['budget_summary']['period_start'])); ?>
                                to <?php echo date('M j, Y', strtotime($_SESSION['budget_summary']['period_end'])); ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Employees:</label>
                            <p><?php echo $_SESSION['budget_summary']['total_employees']; ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Gross Pay:</label>
                            <p>₱<?php echo number_format($_SESSION['budget_summary']['total_gross_pay'], 2); ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Deductions:</label>
                            <p>₱<?php echo number_format($_SESSION['budget_summary']['total_deductions'], 2); ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Total Net Pay (Budget):</label>
                            <p style="font-size: 1.2rem; font-weight: bold; color: #28a745;">
                                ₱<?php echo number_format($_SESSION['budget_summary']['total_net_pay'], 2); ?>
                            </p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Approval Status:</label>
                            <p>
                                <span class="budget-status-<?php echo strtolower(str_replace(' ', '', $_SESSION['budget_summary']['approval_status'])); ?>">
                                    <?php echo htmlspecialchars($_SESSION['budget_summary']['approval_status']); ?>
                                </span>
                            </p>
                        </div>
                    </div>
                    <div class="form-actions">
                        <a href="?view_budget=<?php echo $_SESSION['budget_summary']['budget_id']; ?>" class="btn btn-info">
                            <i class="fas fa-eye"></i> View Budget Details
                        </a>
                        <a href="?send_for_approval=<?php echo $_SESSION['budget_summary']['budget_id']; ?>" class="btn btn-warning" onclick="return confirm('Send this budget for financial approval?')">
                            <i class="fas fa-paper-plane"></i> Send for Approval
                        </a>
                        <a href="?clear_budget_summary=true" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Close
                        </a>
                    </div>
                </div>
                <?php unset($_SESSION['budget_summary']); ?>
            <?php endif; ?>

            <!-- Budgets List -->
            <h3 style="margin-bottom: 1rem; color: #2c3e50;">Recent Budgets</h3>
            <?php if (!empty($allBudgets)): ?>
                <table class="budget-table">
                    <thead>
                        <tr>
                            <th>Budget ID</th>
                            <th>Name</th>
                            <th>Period</th>
                            <th>Employees</th>
                            <th>Net Pay</th>
                            <th>Budget Status</th>
                            <th>Approval Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allBudgets as $budget): ?>
                            <tr>
                                <td>#<?php echo $budget['id']; ?></td>
                                <td><?php echo htmlspecialchars($budget['budget_name'] ?? 'Payroll Budget'); ?></td>
                                <td>
                                    <?php echo date('M j', strtotime($budget['budget_period_start'])); ?> -
                                    <?php echo date('M j, Y', strtotime($budget['budget_period_end'])); ?>
                                </td>
                                <td><?php echo $budget['total_employees']; ?></td>
                                <td class="amount"><strong>₱<?php echo number_format($budget['total_net_pay'], 2); ?></strong></td>
                                <td>
                                    <span class="budget-status-<?php echo strtolower($budget['budget_status'] ?? 'draft'); ?>">
                                        <?php echo htmlspecialchars($budget['budget_status'] ?? 'Draft'); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $approvalStatus = $budget['approval_status'] ?? 'Draft';
                                    $statusClass = strtolower(str_replace(' ', '', $approvalStatus));
                                    ?>
                                    <span class="budget-status-<?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($approvalStatus); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($budget['created_at'])); ?></td>
                                <td class="actions-cell">
                                    <a href="?view_budget=<?php echo $budget['id']; ?>" class="btn btn-info btn-sm">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php
                                    $currentApprovalStatus = $budget['approval_status'] ?? 'Draft';
                                    if ($currentApprovalStatus === 'Draft'): ?>
                                        <a href="?send_for_approval=<?php echo $budget['id']; ?>" class="btn btn-warning btn-sm" onclick="return confirm('Send this budget for financial approval?')">
                                            <i class="fas fa-paper-plane"></i> Send for Approval
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($currentApprovalStatus === 'Waiting for Approval'): ?>
                                        <a href="financial-approval.php" class="btn btn-purple btn-sm" target="_blank">
                                            <i class="fas fa-external-link-alt"></i> View Approval
                                        </a>
                                    <?php endif; ?>
                                    <button onclick="printBudget(<?php echo $budget['id']; ?>)" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-print"></i> Print
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; padding: 2rem; color: #6c757d;">
                    <i class="fas fa-chart-pie" style="font-size: 2rem; margin-bottom: 1rem;"></i><br>
                    No payroll budgets created yet. Click "Compute All & Create Budget" to create your first budget.
                </p>
            <?php endif; ?>
        </div>

        <!-- ============ BUDGET VIEW MODAL ============ -->
        <?php if (isset($_SESSION['view_budget'])): ?>
            <div id="budgetModal" class="modal" style="display: block;">
                <div class="modal-content budget-modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">
                            <i class="fas fa-file-invoice-dollar"></i>
                            Budget Details - #<?php echo $_SESSION['view_budget']['id']; ?>
                        </h2>
                        <button class="close-btn" onclick="closeBudgetModal()">&times;</button>
                    </div>

                    <?php
                    $budget = $_SESSION['view_budget'];
                    $records = $_SESSION['view_budget_records'];
                    ?>

                    <!-- Budget Summary -->
                    <div class="view-section">
                        <h3 class="section-title">Budget Summary</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Budget Name:</div>
                                <div class="info-value"><?php echo htmlspecialchars($budget['budget_name'] ?? 'Payroll Budget'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Budget Period:</div>
                                <div class="info-value">
                                    <?php echo date('F j, Y', strtotime($budget['budget_period_start'])); ?>
                                    to <?php echo date('F j, Y', strtotime($budget['budget_period_end'])); ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Employees:</div>
                                <div class="info-value"><?php echo $budget['total_employees']; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Created By:</div>
                                <div class="info-value"><?php echo htmlspecialchars($budget['created_by'] ?? 'System'); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Created Date:</div>
                                <div class="info-value"><?php echo date('F j, Y, g:i a', strtotime($budget['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Approval Information -->
                    <div class="view-section">
                        <h3 class="section-title">Approval Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Approval Status:</div>
                                <div class="info-value">
                                    <?php
                                    $approvalStatus = $budget['approval_status'] ?? 'Draft';
                                    $statusClass = strtolower(str_replace(' ', '', $approvalStatus));
                                    ?>
                                    <span class="budget-status-<?php echo $statusClass; ?>">
                                        <?php echo htmlspecialchars($approvalStatus); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Budget Status:</div>
                                <div class="info-value">
                                    <span class="budget-status-<?php echo strtolower($budget['budget_status'] ?? 'draft'); ?>">
                                        <?php echo htmlspecialchars($budget['budget_status'] ?? 'Draft'); ?>
                                    </span>
                                </div>
                            </div>
                            <?php if (isset($budget['submitted_for_approval_at']) && $budget['submitted_for_approval_at']): ?>
                                <div class="info-item">
                                    <div class="info-label">Submitted for Approval:</div>
                                    <div class="info-value"><?php echo date('F j, Y, g:i a', strtotime($budget['submitted_for_approval_at'])); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($budget['approved_at']) && $budget['approved_at']): ?>
                                <div class="info-item">
                                    <div class="info-label">Approved Date:</div>
                                    <div class="info-value"><?php echo date('F j, Y, g:i a', strtotime($budget['approved_at'])); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Approved By:</div>
                                    <div class="info-value"><?php echo htmlspecialchars($budget['approved_by'] ?? 'System'); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div class="view-section" style="background: #e8f4fd;">
                        <h3 class="section-title">Financial Summary</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Total Gross Pay:</div>
                                <div class="info-value">₱<?php echo number_format($budget['total_gross_pay'], 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Deductions:</div>
                                <div class="info-value">₱<?php echo number_format($budget['total_deductions'], 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Net Pay (Budget):</div>
                                <div class="info-value" style="font-size: 1.3rem; font-weight: bold; color: #28a745;">
                                    ₱<?php echo number_format($budget['total_net_pay'], 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Included Payroll Records -->
                    <?php if (!empty($records)): ?>
                        <div class="view-section">
                            <h3 class="section-title">Included Payroll Records (<?php echo count($records); ?>)</h3>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <table class="data-table" style="font-size: 0.9em;">
                                    <thead>
                                        <tr>
                                            <th>Employee</th>
                                            <th>Department</th>
                                            <th>Gross Pay</th>
                                            <th>Deductions</th>
                                            <th>Net Pay</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($records as $record): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($record['employee_name']); ?></td>
                                                <td><?php echo htmlspecialchars($record['department']); ?></td>
                                                <td class="amount">₱<?php echo number_format($record['gross_pay'], 2); ?></td>
                                                <td class="amount">₱<?php echo number_format($record['total_deductions'], 2); ?></td>
                                                <td class="amount">₱<?php echo number_format($record['net_pay'], 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($record['status'] ?? 'pending'); ?>">
                                                        <?php echo htmlspecialchars($record['status'] ?? 'Pending'); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <!-- Total Row -->
                                        <tr class="budget-total-row">
                                            <td colspan="2"><strong>TOTALS:</strong></td>
                                            <td class="amount"><strong>₱<?php echo number_format($budget['total_gross_pay'], 2); ?></strong></td>
                                            <td class="amount"><strong>₱<?php echo number_format($budget['total_deductions'], 2); ?></strong></td>
                                            <td class="amount"><strong>₱<?php echo number_format($budget['total_net_pay'], 2); ?></strong></td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions" style="margin-top: 20px;">
                        <?php
                        $currentApprovalStatus = $budget['approval_status'] ?? 'Draft';
                        if ($currentApprovalStatus === 'Draft'): ?>
                            <a href="?send_for_approval=<?php echo $budget['id']; ?>" class="btn btn-warning" onclick="return confirm('Send this budget for financial approval?')">
                                <i class="fas fa-paper-plane"></i> Send for Approval
                            </a>
                        <?php endif; ?>
                        <?php if ($currentApprovalStatus === 'Waiting for Approval'): ?>
                            <a href="financial-approval.php" class="btn btn-purple" target="_blank">
                                <i class="fas fa-external-link-alt"></i> View in Approval System
                            </a>
                        <?php endif; ?>
                        <button class="btn btn-primary" onclick="printBudget(<?php echo $budget['id']; ?>)">
                            <i class="fas fa-print"></i> Print Budget
                        </button>
                        <button class="btn btn-secondary" onclick="closeBudgetModal()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            </div>
            <?php
            unset($_SESSION['view_budget']);
            unset($_SESSION['view_budget_records']);
            ?>
        <?php endif; ?>

        <!-- ============ PAYSLIP VIEW ============ -->
        <?php if (isset($_SESSION['payslip_data'])): ?>
            <div id="payslipModal" class="modal" style="display: block;">
                <div class="modal-content" style="max-width: 800px;">
                    <div class="modal-header">
                        <h2 class="modal-title">
                            <i class="fas fa-file-invoice"></i>
                            Payslip - <?php echo htmlspecialchars($_SESSION['payslip_data']['employee_name']); ?>
                        </h2>
                        <button class="close-btn" onclick="closePayslipModal()">&times;</button>
                    </div>
                    <div style="max-height: 70vh; overflow-y: auto;">
                        <?php echo $_SESSION['payslip_data']['html']; ?>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['payslip_data']); ?>
        <?php endif; ?>

        <!-- ============ CALCULATION BREAKDOWN ============ -->
        <?php if (isset($_SESSION['payroll_calculation'])): ?>
            <div class="calculation-breakdown">
                <div class="form-header">
                    <h3 class="form-title">
                        <i class="fas fa-calculator"></i>
                        Payroll Calculation Breakdown
                    </h3>
                    <p>Record ID: #<?php echo $_SESSION['payroll_record_id'] ?? 'N/A'; ?></p>
                </div>

                <?php
                $calculation = $_SESSION['payroll_calculation'];
                $employee = $calculation['employee_info'];
                $summary = $calculation['summary'];
                ?>

                <!-- Employee Information -->
                <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4><i class="fas fa-user"></i> Employee Information</h4>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Name:</label>
                            <p><strong><?php echo htmlspecialchars($employee['name']); ?></strong></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Department:</label>
                            <p><?php echo htmlspecialchars($employee['department']); ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Position:</label>
                            <p><?php echo htmlspecialchars($employee['position']); ?></p>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Status:</label>
                            <p><?php echo htmlspecialchars($employee['employment_status']); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Earnings Breakdown -->
                <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4><i class="fas fa-money-bill-wave"></i> Earnings</h4>
                    <table class="data-table" style="width: 100%;">
                        <tr>
                            <th>Description</th>
                            <th>Hours/Qty</th>
                            <th class="amount">Amount (₱)</th>
                        </tr>
                        <tr>
                            <td>Basic Salary</td>
                            <td></td>
                            <td class="amount"><?php echo number_format($calculation['earnings']['basic_salary'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Overtime Pay</td>
                            <td><?php echo $calculation['earnings']['overtime_hours']; ?> hours</td>
                            <td class="amount"><?php echo number_format($calculation['earnings']['overtime_pay'], 2); ?></td>
                        </tr>
                        <tr>
                            <td>Night Differential</td>
                            <td><?php echo $calculation['earnings']['night_diff_hours']; ?> hours</td>
                            <td class="amount"><?php echo number_format($calculation['earnings']['night_diff_pay'], 2); ?></td>
                        </tr>
                        <tr style="background: #e8f4fd;">
                            <td colspan="2"><strong>TOTAL EARNINGS (Gross Pay)</strong></td>
                            <td class="amount"><strong>₱<?php echo number_format($summary['gross_pay'], 2); ?></strong></td>
                        </tr>
                    </table>
                </div>

                <!-- Deductions Breakdown -->
                <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <h4><i class="fas fa-minus-circle"></i> Attendance Deductions</h4>

                    <?php if (!empty($calculation['deductions']['attendance']['details'])): ?>
                        <table class="data-table" style="width: 100%; font-size: 0.9em;">
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Minutes</th>
                                <th class="amount">Amount (₱)</th>
                            </tr>
                            <?php foreach ($calculation['deductions']['attendance']['details'] as $detail): ?>
                                <tr>
                                    <td><?php echo $detail['date']; ?></td>
                                    <td><?php echo $detail['type']; ?></td>
                                    <td><?php echo $detail['minutes'] ?? 0; ?></td>
                                    <td class="amount">₱<?php echo number_format($detail['amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="background: #f8f9fa;">
                                <td colspan="3"><strong>Total Attendance Deductions</strong></td>
                                <td class="amount"><strong>₱<?php echo number_format($calculation['deductions']['attendance']['total_attendance_deduction'], 2); ?></strong></td>
                            </tr>
                        </table>
                    <?php else: ?>
                        <p>No attendance deductions for this period.</p>
                    <?php endif; ?>
                </div>

                <!-- Final Summary -->
                <div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 2px solid #28a745;">
                    <h4><i class="fas fa-file-invoice-dollar"></i> Final Payroll Summary</h4>
                    <table class="data-table" style="width: 100%;">
                        <tr style="background: #d4edda;">
                            <td colspan="2"><strong>GROSS PAY</strong></td>
                            <td class="amount"><strong>₱<?php echo number_format($summary['gross_pay'], 2); ?></strong></td>
                        </tr>
                        <tr>
                            <td colspan="3"><strong>TOTAL DEDUCTIONS:</strong></td>
                        </tr>
                        <tr>
                            <td style="padding-left: 30px;">Attendance Deductions</td>
                            <td></td>
                            <td class="amount">-₱<?php echo number_format($summary['breakdown']['attendance_deductions'], 2); ?></td>
                        </tr>
                        <tr style="background: #f8f9fa;">
                            <td colspan="2"><strong>SUB-TOTAL DEDUCTIONS</strong></td>
                            <td class="amount"><strong>-₱<?php echo number_format($summary['total_deductions'], 2); ?></strong></td>
                        </tr>
                        <tr style="background: #cce5ff; font-size: 1.2em;">
                            <td colspan="2"><strong>NET PAY (Take Home Pay)</strong></td>
                            <td class="amount"><strong>₱<?php echo number_format($summary['net_pay'], 2); ?></strong></td>
                        </tr>
                    </table>
                </div>

                <div class="form-actions">
                    <button onclick="window.print()" class="btn btn-primary">
                        <i class="fas fa-print"></i> Print Calculation
                    </button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="payroll_id" value="<?php echo $_SESSION['payroll_record_id'] ?? ''; ?>">
                        <button type="submit" name="mark_as_paid" class="btn btn-warning" onclick="return confirm('Mark this payroll as Paid?')">
                            <i class="fas fa-check-circle"></i> Mark as Paid
                        </button>
                    </form>
                    <a href="?clear_calculation=true" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Close
                    </a>
                </div>
            </div>
            <?php
            unset($_SESSION['payroll_calculation']);
            unset($_SESSION['payroll_record_id']);
            ?>
        <?php endif; ?>

        <!-- ============ PAYROLL VIEW MODAL ============ -->
        <?php if (isset($_SESSION['view_record'])): ?>
            <div id="viewModal" class="modal" style="display: block;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">
                            <i class="fas fa-eye"></i>
                            Payroll Record Details
                        </h2>
                        <button class="close-btn" onclick="closeModal()">&times;</button>
                    </div>

                    <?php
                    $record = $_SESSION['view_record'];
                    $calculation = $_SESSION['view_calculation'];
                    $attendance = $_SESSION['view_attendance'];
                    ?>

                    <!-- Employee Information -->
                    <div class="view-section">
                        <h3 class="section-title">Employee Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Employee Name:</div>
                                <div class="info-value"><?php echo htmlspecialchars($record['employee_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Employee ID:</div>
                                <div class="info-value"><?php echo htmlspecialchars($record['employee_id']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Department:</div>
                                <div class="info-value"><?php echo htmlspecialchars($record['department']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Pay Period:</div>
                                <div class="info-value">
                                    <?php echo date('M j, Y', strtotime($record['pay_period_start'])); ?>
                                    to <?php echo date('M j, Y', strtotime($record['pay_period_end'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Earnings Summary -->
                    <div class="view-section">
                        <h3 class="section-title">Earnings Summary</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Basic Salary:</div>
                                <div class="info-value">₱<?php echo number_format($record['basic_salary'], 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Overtime Pay:</div>
                                <div class="info-value">₱<?php echo number_format($record['overtime_pay'], 2); ?> (<?php echo $record['overtime_hours']; ?> hours)</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Night Differential:</div>
                                <div class="info-value">₱<?php echo number_format($record['night_diff_pay'], 2); ?> (<?php echo $record['night_diff_hours']; ?> hours)</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Gross Pay:</div>
                                <div class="info-value"><strong>₱<?php echo number_format($record['gross_pay'], 2); ?></strong></div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance Data -->
                    <?php if (!empty($attendance) && isset($attendance['attendance_records'])): ?>
                        <div class="view-section">
                            <h3 class="section-title">Attendance Records</h3>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <table class="attendance-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Status</th>
                                            <th>Time In</th>
                                            <th>Time Out</th>
                                            <th>Late Minutes</th>
                                            <th>Undertime Minutes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($attendance['attendance_records'] as $attendance_record): ?>
                                            <tr>
                                                <td><?php echo $attendance_record['date']; ?></td>
                                                <td class="status-<?php echo strtolower(str_replace(' ', '', $attendance_record['status'])); ?>">
                                                    <?php echo $attendance_record['status']; ?>
                                                </td>
                                                <td><?php echo $attendance_record['time_in']; ?></td>
                                                <td><?php echo $attendance_record['time_out']; ?></td>
                                                <td><?php echo $attendance_record['late_minutes'] ?? 0; ?></td>
                                                <td><?php echo $attendance_record['undertime_minutes'] ?? 0; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Deductions -->
                    <div class="view-section">
                        <h3 class="section-title">Deductions</h3>
                        <div class="deduction-breakdown">
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Late Deduction:</div>
                                    <div class="info-value">₱<?php echo number_format($record['late_deduction'], 2); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Absence Deduction:</div>
                                    <div class="info-value">₱<?php echo number_format($record['absence_deduction'], 2); ?></div>
                                </div>
                                <?php if (isset($record['undertime_deduction'])): ?>
                                    <div class="info-item">
                                        <div class="info-label">Undertime Deduction:</div>
                                        <div class="info-value">₱<?php echo number_format($record['undertime_deduction'], 2); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (isset($record['halfday_deduction'])): ?>
                                    <div class="info-item">
                                        <div class="info-label">Half Day Deduction:</div>
                                        <div class="info-value">₱<?php echo number_format($record['halfday_deduction'], 2); ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <div class="info-label">Total Deductions:</div>
                                    <div class="info-value"><strong>₱<?php echo number_format($record['total_deductions'], 2); ?></strong></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Final Summary -->
                    <div class="view-section" style="background: #e8f4fd; border-left: 4px solid #4e73df;">
                        <h3 class="section-title">Final Summary</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Gross Pay:</div>
                                <div class="info-value">₱<?php echo number_format($record['gross_pay'], 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Total Deductions:</div>
                                <div class="info-value">₱<?php echo number_format($record['total_deductions'], 2); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Net Pay:</div>
                                <div class="info-value" style="font-size: 1.2rem; font-weight: bold; color: #28a745;">
                                    ₱<?php echo number_format($record['net_pay'], 2); ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <span class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                        <?php echo htmlspecialchars($record['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions" style="margin-top: 20px;">
                        <?php if ($record['status'] === 'Pending' || $record['status'] === 'Processed'): ?>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                                <button type="submit" name="mark_as_paid" class="btn btn-warning" onclick="return confirm('Mark this payroll as Paid?')">
                                    <i class="fas fa-check-circle"></i> Mark as Paid
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($record['status'] === 'Paid'): ?>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                                <button type="submit" name="release_salary" class="btn btn-success" onclick="return confirm('Release salary for this employee?')">
                                    <i class="fas fa-paper-plane"></i> Release Salary
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                            <button type="submit" name="generate_payslip" class="btn btn-info">
                                <i class="fas fa-file-invoice"></i> Generate Payslip
                            </button>
                        </form>
                        <button class="btn btn-secondary" onclick="closeModal()">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            </div>
            <?php
            unset($_SESSION['view_record']);
            unset($_SESSION['view_calculation']);
            unset($_SESSION['view_attendance']);
            ?>
        <?php endif; ?>

        <!-- ============ PAYROLL FORM ============ -->
        <div class="form-container">
            <div class="form-header">
                <h2 class="form-title">
                    <i class="fas fa-plus-circle"></i>
                    <?php echo isset($_GET['edit']) ? 'Edit Payroll Record' : 'Add New Payroll Calculation'; ?>
                </h2>
            </div>

            <form method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Employee *</label>
                        <select name="employee_id" id="employee_select" class="form-select" required onchange="updateEmployeeDetails(this)">
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $employee):
                                $selected = '';
                                if (isset($_GET['edit'])) {
                                    foreach ($payroll_records as $record) {
                                        if ($record['id'] == $_GET['edit'] && $record['employee_id'] == $employee['id']) {
                                            $selected = 'selected';
                                            break;
                                        }
                                    }
                                }
                            ?>
                                <option value="<?php echo $employee['id']; ?>" data-name="<?php echo htmlspecialchars($employee['name']); ?>" data-department="<?php echo htmlspecialchars($employee['department']); ?>" data-status="<?php echo htmlspecialchars($employee['employment_status']); ?>" data-salary="<?php echo $employee['salary']; ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($employee['name']); ?> - <?php echo htmlspecialchars($employee['department']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pay Period Start *</label>
                        <input type="date" name="pay_period_start" id="pay_period_start" class="form-input"
                            value="<?php
                                    if (isset($_GET['edit'])) {
                                        foreach ($payroll_records as $record) {
                                            if ($record['id'] == $_GET['edit']) {
                                                echo htmlspecialchars($record['pay_period_start']);
                                                break;
                                            }
                                        }
                                    } else {
                                        echo date('Y-m-01');
                                    }
                                    ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Pay Period End *</label>
                        <input type="date" name="pay_period_end" id="pay_period_end" class="form-input"
                            value="<?php
                                    if (isset($_GET['edit'])) {
                                        foreach ($payroll_records as $record) {
                                            if ($record['id'] == $_GET['edit']) {
                                                echo htmlspecialchars($record['pay_period_end']);
                                                break;
                                            }
                                        }
                                    } else {
                                        echo date('Y-m-t');
                                    }
                                    ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Basic Salary (₱) *</label>
                        <input type="number" name="basic_salary" id="basic_salary" class="form-input" step="0.01" min="0"
                            value="<?php
                                    if (isset($_GET['edit'])) {
                                        foreach ($payroll_records as $record) {
                                            if ($record['id'] == $_GET['edit']) {
                                                echo htmlspecialchars($record['basic_salary']);
                                                break;
                                            }
                                        }
                                    }
                                    ?>" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" name="calculate_auto" class="btn btn-info" onclick="return validateCalculateAuto()">
                        <i class="fas fa-calculator"></i> Calculate Payroll
                    </button>

                    <?php if (isset($_GET['edit'])): ?>
                        <input type="hidden" name="id" value="<?php echo $_GET['edit']; ?>">
                        <button type="submit" name="update_payroll" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Record
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    <?php else: ?>
                        <button type="submit" name="add_payroll" class="btn btn-success">
                            <i class="fas fa-plus"></i> Save to Records
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ============ SEARCH AND FILTER ============ -->
        <div class="search-filter-container">
            <div class="search-box">
                <label class="form-label">Search Records</label>
                <form method="GET" action="" class="search-form">
                    <div style="display: flex; gap: 0.5rem;">
                        <input type="text" name="search" class="form-input" placeholder="Search by employee name or ID..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>
            <div class="filter-box">
                <label class="form-label">Filter by Department</label>
                <form method="GET" action="" class="filter-form">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                    <select name="department" class="form-select" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php echo $department_filter === $dept['department'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['department']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="filter-box">
                <label class="form-label">Filter by Status</label>
                <form method="GET" action="" class="filter-form">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                    <input type="hidden" name="department" value="<?php echo htmlspecialchars($department_filter); ?>">
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Paid" <?php echo $status_filter === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="Processed" <?php echo $status_filter === 'Processed' ? 'selected' : ''; ?>>Processed</option>
                        <option value="Released" <?php echo $status_filter === 'Released' ? 'selected' : ''; ?>>Released</option>
                        <option value="Cancelled" <?php echo $status_filter === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </form>
            </div>
            <div class="search-actions">
                <?php if (!empty($search) || !empty($department_filter) || !empty($status_filter) || !empty($pay_period)): ?>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ============ BATCH ACTIONS BAR ============ -->
        <form id="batchForm" method="POST" action="">
            <div class="batch-actions-bar">
                <div class="select-all-container">
                    <input type="checkbox" id="selectAll" onchange="selectAllCheckboxes(this)">
                    <label for="selectAll">Select All</label>
                    <span id="selectedCount" style="margin-left: 10px; font-weight: bold; color: var(--primary-color);">0 selected</span>
                </div>
                <div class="batch-buttons">
                    <button type="submit" name="mark_multiple_paid" class="btn btn-warning btn-sm" onclick="return validateBatchOperation('mark as paid')">
                        <i class="fas fa-check-circle"></i> Mark as Paid
                    </button>
                    <button type="submit" name="generate_multiple_payslips" class="btn btn-info btn-sm" onclick="return validateBatchOperation('generate payslips')">
                        <i class="fas fa-file-invoice"></i> Generate Payslips
                    </button>
                </div>
            </div>

            <!-- ============ PAYROLL STATISTICS ============ -->
            <?php
            // Calculate filtered statistics based on search/filter
            $filteredPendingCount = 0;
            $filteredPaidCount = 0;
            $filteredReleasedCount = 0;
            foreach ($payroll_records as $record) {
                switch ($record['status']) {
                    case 'Pending':
                        $filteredPendingCount++;
                        break;
                    case 'Paid':
                        $filteredPaidCount++;
                        break;
                    case 'Released':
                        $filteredReleasedCount++;
                        break;
                }
            }
            ?>
            <div class="budget-stats-grid" style="margin: 1.5rem;">
                <div class="budget-stat-card">
                    <div class="budget-stat-value"><?php echo count($payroll_records); ?></div>
                    <div class="budget-stat-label">Filtered Records</div>
                </div>
                <div class="budget-stat-card warning">
                    <div class="budget-stat-value"><?php echo $filteredPendingCount; ?></div>
                    <div class="budget-stat-label">Pending Payment</div>
                </div>
                <div class="budget-stat-card success">
                    <div class="budget-stat-value"><?php echo $filteredPaidCount; ?></div>
                    <div class="budget-stat-label">Paid</div>
                </div>
                <div class="budget-stat-card info">
                    <div class="budget-stat-value"><?php echo $filteredReleasedCount; ?></div>
                    <div class="budget-stat-label">Released</div>
                </div>
            </div>

            <!-- ============ PAYROLL RECORDS TABLE ============ -->
            <div class="table-container">
                <h3 style="padding: 0 1.5rem; margin-bottom: 1rem; color: #2c3e50;">Payroll Records</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="checkbox-cell">
                                <input type="checkbox" id="selectAllTable" onchange="selectAllCheckboxesTable(this)">
                            </th>
                            <th>Employee Name</th>
                            <th>Department</th>
                            <th>Pay Period</th>
                            <th>Gross Pay</th>
                            <th>Deductions</th>
                            <th>Net Pay</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($payroll_records)): ?>
                            <?php foreach ($payroll_records as $record): ?>
                                <tr>
                                    <td class="checkbox-cell">
                                        <input type="checkbox" name="selected_payrolls[]" value="<?php echo $record['id']; ?>" class="payroll-checkbox" onchange="updateSelectedCount()">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($record['employee_name']); ?></strong>
                                        <br><small>ID: <?php echo htmlspecialchars($record['employee_id']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($record['department']); ?></td>
                                    <td>
                                        <?php echo date('M j', strtotime($record['pay_period_start'])); ?> -
                                        <?php echo date('M j, Y', strtotime($record['pay_period_end'])); ?>
                                    </td>
                                    <td class="amount">₱<?php echo number_format($record['gross_pay'], 2); ?></td>
                                    <td class="amount">₱<?php echo number_format($record['total_deductions'], 2); ?></td>
                                    <td class="amount"><strong>₱<?php echo number_format($record['net_pay'], 2); ?></strong></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($record['status']); ?>">
                                            <?php echo htmlspecialchars($record['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($record['created_at'])); ?>
                                    </td>
                                    <td class="actions-cell">
                                        <!-- View Button -->
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" name="view_details" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </form>

                                        <!-- Update Button -->
                                        <a href="?edit=<?php echo $record['id']; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($department_filter) ? '&department=' . urlencode($department_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>" class="btn btn-warning btn-sm">
                                            <i class="fas fa-edit"></i> Update
                                        </a>

                                        <!-- Delete Button -->
                                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this payroll record?');">
                                            <input type="hidden" name="id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" name="delete_payroll" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>

                                        <!-- Quick Actions -->
                                        <?php if ($record['status'] === 'Pending' || $record['status'] === 'Processed'): ?>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                                                <button type="submit" name="mark_as_paid" class="btn btn-warning btn-sm" onclick="return confirm('Mark as Paid?')">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($record['status'] === 'Paid'): ?>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                                                <button type="submit" name="release_salary" class="btn btn-success btn-sm" onclick="return confirm('Release Salary?')">
                                                    <i class="fas fa-paper-plane"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Generate Payslip -->
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="payroll_id" value="<?php echo $record['id']; ?>">
                                            <button type="submit" name="generate_payslip" class="btn btn-info btn-sm">
                                                <i class="fas fa-file-invoice"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 2rem;">
                                    <i class="fas fa-inbox" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                    <p>No payroll records found.</p>
                                    <p class="page-subtitle">
                                        <?php if (!empty($search) || !empty($department_filter) || !empty($status_filter)): ?>
                                            Try adjusting your search or filter criteria.
                                        <?php else: ?>
                                            Calculate and add your first payroll record using the form above.
                                        <?php endif; ?>
                                    </p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<script>
    // ============ BATCH OPERATIONS FUNCTIONS ============
    function selectAllCheckboxes(source) {
        const checkboxes = document.querySelectorAll('input[name="selected_payrolls[]"]');
        const selectAllTable = document.getElementById('selectAllTable');

        checkboxes.forEach(checkbox => {
            checkbox.checked = source.checked;
        });

        if (selectAllTable) {
            selectAllTable.checked = source.checked;
        }

        updateSelectedCount();
    }

    function selectAllCheckboxesTable(source) {
        const checkboxes = document.querySelectorAll('input[name="selected_payrolls[]"]');
        const selectAll = document.getElementById('selectAll');

        checkboxes.forEach(checkbox => {
            checkbox.checked = source.checked;
        });

        if (selectAll) {
            selectAll.checked = source.checked;
        }

        updateSelectedCount();
    }

    function updateSelectedCount() {
        const checkboxes = document.querySelectorAll('input[name="selected_payrolls[]"]:checked');
        const countElement = document.getElementById('selectedCount');
        if (countElement) {
            countElement.textContent = checkboxes.length + ' selected';
        }
    }

    function validateBatchOperation(action) {
        const checkboxes = document.querySelectorAll('input[name="selected_payrolls[]"]:checked');
        if (checkboxes.length === 0) {
            alert('Please select at least one payroll record to ' + action + '.');
            return false;
        }
        return confirm('Are you sure you want to ' + action + ' for ' + checkboxes.length + ' selected employee(s)?');
    }

    // ============ MODAL FUNCTIONS ============
    function closeModal() {
        document.getElementById('viewModal').style.display = 'none';
        window.history.pushState({}, document.title, window.location.pathname + window.location.search.replace(/\?view=.*/, ''));
    }

    function closeBudgetModal() {
        document.getElementById('budgetModal').style.display = 'none';
        window.history.pushState({}, document.title, window.location.pathname + window.location.search.replace(/\?budget_view=.*/, ''));
    }

    function closePayslipModal() {
        document.getElementById('payslipModal').style.display = 'none';
        window.history.pushState({}, document.title, window.location.pathname + window.location.search.replace(/\?view_payslip=.*/, ''));
    }

    function printBudget(budgetId) {
        window.open('?print_budget=' + budgetId, '_blank');
    }

    // Close modals on escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const viewModal = document.getElementById('viewModal');
            const budgetModal = document.getElementById('budgetModal');
            const payslipModal = document.getElementById('payslipModal');

            if (viewModal && viewModal.style.display === 'block') {
                closeModal();
            }
            if (budgetModal && budgetModal.style.display === 'block') {
                closeBudgetModal();
            }
            if (payslipModal && payslipModal.style.display === 'block') {
                closePayslipModal();
            }
        }
    });

    // Close modals when clicking outside
    window.onclick = function(event) {
        const viewModal = document.getElementById('viewModal');
        const budgetModal = document.getElementById('budgetModal');
        const payslipModal = document.getElementById('payslipModal');

        if (event.target == viewModal) {
            closeModal();
        }
        if (event.target == budgetModal) {
            closeBudgetModal();
        }
        if (event.target == payslipModal) {
            closePayslipModal();
        }
    }

    // ============ FORM VALIDATION ============
    function validateCalculateAuto() {
        const employeeId = document.getElementById('employee_select').value;
        const startDate = document.getElementById('pay_period_start').value;
        const endDate = document.getElementById('pay_period_end').value;

        if (!employeeId) {
            alert('Please select an employee first.');
            return false;
        }

        if (!startDate || !endDate) {
            alert('Please enter both pay period start and end dates.');
            return false;
        }

        if (new Date(endDate) <= new Date(startDate)) {
            alert('Pay period end date must be after start date.');
            return false;
        }

        return true;
    }

    function updateEmployeeDetails(select) {
        const selectedOption = select.options[select.selectedIndex];
        const salary = selectedOption.getAttribute('data-salary');

        if (salary && document.getElementById('basic_salary')) {
            document.getElementById('basic_salary').value = salary;
        }
    }

    // Initialize employee details if editing
    document.addEventListener('DOMContentLoaded', function() {
        const employeeSelect = document.getElementById('employee_select');
        if (employeeSelect && employeeSelect.value) {
            updateEmployeeDetails(employeeSelect);
        }

        // Initialize selected count
        updateSelectedCount();

        // Add event listeners to all checkboxes
        const checkboxes = document.querySelectorAll('.payroll-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });
    });
</script>
</body>

</html>