<?php
// api/payroll-calculate.php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");

if (!file_exists('../config/db.php')) {
    http_response_code(500);
    echo json_encode(["error" => "Database configuration file missing."]);
    exit;
}
require_once '../config/db.php';

// --- Functions Imported from payroll/index.php ---

function getWeightedRandom($weights)
{
    $total = array_sum($weights);
    $rand = mt_rand(1, $total);
    $current = 0;
    foreach ($weights as $i => $weight) {
        $current += $weight;
        if ($rand <= $current) return $i;
    }
    return 0;
}

function fetchAttendanceData($employeeId, $startDate, $endDate)
{
    $records = [];
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($start <= $end) {
        $date = $start->format('Y-m-d');
        if ($start->format('N') >= 6) {
            $start->add(new DateInterval('P1D'));
            continue;
        }

        $statusOptions = ['Present', 'Late', 'Absent', 'Half Day', 'Undertime'];
        $weights = [70, 15, 5, 5, 5];
        $status = $statusOptions[getWeightedRandom($weights)];

        $record = ['date' => $date, 'employee_id' => $employeeId, 'status' => $status, 'time_in' => '08:00:00', 'time_out' => '17:00:00'];
        if ($status === 'Late') $record['late_minutes'] = rand(5, 120);
        elseif ($status === 'Undertime') $record['undertime_minutes'] = rand(15, 90);

        $records[] = $record;
        $start->add(new DateInterval('P1D'));
    }
    return ['attendance_records' => $records];
}

function calculateAttendanceDeductions($attendanceData, $dailyRate)
{
    $deductions = [
        'late_deduction' => 0,
        'absence_deduction' => 0,
        'undertime_deduction' => 0,
        'halfday_deduction' => 0,
        'total_attendance_deduction' => 0,
        'details' => []
    ];
    if (!$attendanceData || !isset($attendanceData['attendance_records'])) return $deductions;

    $minuteRate = ($dailyRate / 8) / 60;
    foreach ($attendanceData['attendance_records'] as $record) {
        switch ($record['status']) {
            case 'Absent':
                $amt = $dailyRate;
                $deductions['absence_deduction'] += $amt;
                break;
            case 'Late':
                $amt = $minuteRate * $record['late_minutes'];
                $deductions['late_deduction'] += $amt;
                break;
            case 'Undertime':
                $amt = $minuteRate * $record['undertime_minutes'];
                $deductions['undertime_deduction'] += $amt;
                break;
            case 'Half Day':
                $amt = $dailyRate / 2;
                $deductions['halfday_deduction'] += $amt;
                break;
            default:
                $amt = 0;
        }
        if ($amt > 0) $deductions['details'][] = ['date' => $record['date'], 'type' => $record['status'], 'amount' => round($amt, 2)];
    }
    $deductions['total_attendance_deduction'] = array_sum([$deductions['late_deduction'], $deductions['absence_deduction'], $deductions['undertime_deduction'], $deductions['halfday_deduction']]);
    return $deductions;
}

// --- API Logic ---

$content = file_get_contents("php://input");
$data = json_decode($content);

if (isset($data->employee_id) && isset($data->start_date) && isset($data->end_date)) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, department, salary FROM employees WHERE id = ? AND status = 'Active'");
        $stmt->execute([$data->employee_id]);
        $employee = $stmt->fetch();

        if (!$employee) {
            http_response_code(404);
            echo json_encode(["message" => "Active employee ID " . $data->employee_id . " not found."]);
            exit;
        }

        // 1. Fetch simulated attendance
        $attendance = fetchAttendanceData($employee['id'], $data->start_date, $data->end_date);

        // 2. Calculate deductions
        $dailyRate = $employee['salary'] / 22;
        $deductions = calculateAttendanceDeductions($attendance, $dailyRate);

        // 3. Calculate Earnings (including random OT/Night Diff from index.php)
        $overtimePay = rand(0, 20) * ($employee['salary'] / 160) * 1.25;
        $nightDiffPay = rand(0, 10) * ($employee['salary'] / 160) * 0.10;

        $grossPay = $employee['salary'] + $overtimePay + $nightDiffPay;
        $netPay = $grossPay - $deductions['total_attendance_deduction'];

        echo json_encode([
            "status" => "success",
            "employee" => [
                "name" => $employee['name'],
                "department" => $employee['department']
            ],
            "period" => ["start" => $data->start_date, "end" => $data->end_date],
            "earnings" => [
                "basic_salary" => (float)$employee['salary'],
                "overtime_pay" => round($overtimePay, 2),
                "night_diff_pay" => round($nightDiffPay, 2),
                "total_gross" => round($grossPay, 2)
            ],
            "deductions" => [
                "attendance_total" => round($deductions['total_attendance_deduction'], 2),
                "breakdown" => $deductions['details']
            ],
            "net_pay" => round($netPay, 2)
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Database error: " . $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(["error" => "Required: employee_id, start_date, end_date"]);
}
