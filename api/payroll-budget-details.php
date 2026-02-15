<?php
// c:/xampp/htdocs/HR4/api/payroll-budget-details.php
header('Content-Type: application/json');
require_once '../config/db.php';

// Check connection
if (!isset($pdo)) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

$budget_id = $_GET['budget_id'] ?? null;
$period_id = $_GET['period_id'] ?? null;

if (!$budget_id && !$period_id) {
    echo json_encode(['success' => false, 'message' => 'Missing budget_id or period_id']);
    exit;
}

try {
    // 1. Fetch Budget Information
    $sql = "SELECT b.*, p.name as period_name, p.status as period_status, p.period_code, 
                   p.bundle_type, p.bundle_filter, p.ta_batch_id
            FROM payroll_budgets b
            JOIN payroll_periods p ON b.payroll_period_id = p.id
            WHERE " . ($budget_id ? "b.id = ?" : "b.payroll_period_id = ?");
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$budget_id ?: $period_id]);
    $budget = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$budget) {
        echo json_encode(['success' => false, 'message' => 'Budget not found']);
        exit;
    }

    // 2. Fetch T&A Summary (Simulated HR3 Link)
    $ta_stmt = $pdo->prepare("SELECT * FROM ta_batches WHERE id = ?");
    $ta_stmt->execute([$budget['ta_batch_id']]);
    $ta_batch = $ta_stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Fetch Payroll Breakdown (Top 5 for preview, or summary stats)
    $rec_stmt = $pdo->prepare("SELECT COUNT(*) as head_count, 
                                      SUM(gross_pay) as total_gross, 
                                      SUM(total_deductions) as total_deductions, 
                                      SUM(net_pay) as total_net 
                               FROM payroll_records 
                               WHERE payroll_period_id = ?");
    $rec_stmt->execute([$budget['payroll_period_id']]);
    $breakdown = $rec_stmt->fetch(PDO::FETCH_ASSOC);

    // Fetch Full Records for Detailed View
    $details_stmt = $pdo->prepare("SELECT id, employee_id, employee_name, department, 
                                          basic_salary, overtime_pay as ot_pay, allowances, 
                                          gross_pay, total_deductions, net_pay 
                                   FROM payroll_records 
                                   WHERE payroll_period_id = ? 
                                   ORDER BY employee_name ASC");
    $details_stmt->execute([$budget['payroll_period_id']]);
    $records = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'budget' => [
            'id' => $budget['id'],
            'code' => $budget['budget_code'],
            'name' => $budget['budget_name'],
            'period' => $budget['date_range_start'] . ' to ' . $budget['date_range_end'],
            'gross' => (float)$budget['total_gross_amount'],
            'deductions' => (float)$budget['total_deductions_amount'],
            'net' => (float)$budget['total_net_amount'],
            'status' => $budget['approval_status'],
            'submitted_at' => $budget['submitted_at']
        ],
        'attendance' => [
            'batch_id' => $ta_batch['id'] ?? 'N/A',
            'name' => $ta_batch['name'] ?? 'N/A',
            'total_logs' => $ta_batch['total_logs'] ?? 0,
            'verified_status' => $ta_batch['status'] ?? 'Unknown'
        ],
        'payroll_summary' => [
            'employee_count' => $breakdown['head_count'],
            'avg_net_pay' => $breakdown['head_count'] > 0 ? round($breakdown['total_net'] / $breakdown['head_count'], 2) : 0
        ],
        'payroll_records' => $records // Added detailed list
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
