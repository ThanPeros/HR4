<?php
// compensation/print-compensation-report.php
session_start();
require_once '../config/db.php';

// Helper for total cost formatting
function formatMoney($amount) {
    return '₱' . number_format((float)$amount, 2);
}

// 1. DATA AGGREGATION
// (Copied from compensation-report.php)

// A. Employees & Base Salaries
try {
    $employees = $pdo->query("SELECT id, name, job_title, department, salary FROM employees WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $employees = [];
}

// B. Active Benefits Cost (Employer Share)
$benefitsData = [];
try {
    $stmt = $pdo->query("
        SELECT eb.employee_name, 
               SUM(CASE 
                   WHEN bt.frequency = 'Monthly' THEN bt.employer_share * 12 
                   WHEN bt.frequency = 'Annual' THEN bt.employer_share 
                   ELSE 0 
               END) as annual_benefit_cost
        FROM employee_benefits eb
        JOIN benefit_types bt ON eb.benefit_id = bt.id
        WHERE eb.status = 'Active'
        GROUP BY eb.employee_name
    ");
    $benefitsData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* Table might not exist yet */ }

// C. Active Allowances Cost
$allowancesData = [];
try {
    $stmt = $pdo->query("
        SELECT ea.employee_name, 
               SUM(at.amount * 12) as annual_allowance_cost 
        FROM employee_allowances ea
        JOIN allowance_types at ON ea.allowance_id = at.id
        WHERE ea.status = 'Active' AND at.type = 'Fixed'
        GROUP BY ea.employee_name
    ");
    $allowancesData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* Table might not exist */ }

// D. Incentives
$incentivesData = [];
try {
    $stmt = $pdo->query("
        SELECT employee_name, SUM(amount) as total_incentives 
        FROM employee_incentives 
        GROUP BY employee_name
    ");
    $incentivesData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* Table might not exist */ }

// 2. CONSOLIDATE DATA
$reportData = [];
$totals = [
    'salary' => 0,
    'benefits' => 0,
    'allowances' => 0,
    'incentives' => 0,
    'grand_total' => 0
];

// Department Summary aggregation
$deptSummary = [];

foreach ($employees as $emp) {
    $name = $emp['name'];
    $dept = $emp['department'] ?: 'Unassigned';
    $annualSalary = ($emp['salary'] ?? 0) * 12; // Projecting Annual logic
    $ben = $benefitsData[$name] ?? 0;
    $all = $allowancesData[$name] ?? 0;
    $inc = $incentivesData[$name] ?? 0;
    
    $totalComp = $annualSalary + $ben + $all + $inc;
    
    // Detailed Data
    $reportData[] = [
        'name' => $name,
        'role' => $emp['job_title'],
        'dept' => $dept,
        'annual_salary' => $annualSalary,
        'annual_benefits' => $ben,
        'annual_allowances' => $all,
        'total_incentives' => $inc,
        'total_comp' => $totalComp
    ];
    
    // Global Totals
    $totals['salary'] += $annualSalary;
    $totals['benefits'] += $ben;
    $totals['allowances'] += $all;
    $totals['incentives'] += $inc;
    $totals['grand_total'] += $totalComp;

    // Department Totals
    if (!isset($deptSummary[$dept])) {
        $deptSummary[$dept] = [
            'headcount' => 0,
            'salary' => 0,
            'benefits_allowances' => 0,
            'variable' => 0,
            'total' => 0
        ];
    }
    $deptSummary[$dept]['headcount']++;
    $deptSummary[$dept]['salary'] += $annualSalary;
    $deptSummary[$dept]['benefits_allowances'] += ($ben + $all);
    $deptSummary[$dept]['variable'] += $inc;
    $deptSummary[$dept]['total'] += $totalComp;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Compensation Analysis Report - Print</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 12px; 
            color: #000;
            background: #fff;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #e74a3b;
            padding-bottom: 10px;
        }
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .report-title {
            font-size: 18px;
            font-weight: bold;
            margin-top: 10px;
        }
        .meta-info {
            font-size: 11px;
            color: #555;
            margin-top: 5px;
            font-style: italic;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 10px;
            color: #4e73df;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            margin-bottom: 20px;
        }
        th {
            background-color: #f0f0f0;
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        .text-end { text-align: right; }
        .text-center { text-align: center; }
        .fw-bold { font-weight: bold; }
        
        @media print {
            @page { margin: 10mm; size: landscape; }
            body { margin: 0; padding: 0; }
        }
    </style>
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
            window.onafterprint = function() {
                // window.close(); // Optional: close after print
            };
        }
    </script>
</head>
<body>
    <div class="header">
        <div class="company-name">Slate Freight</div>
        <div class="report-title">Compensation Analysis Report</div>
        <div class="meta-info">Generated: <?php echo date('F d, Y g:i A'); ?></div>
    </div>

    <!-- Summary Cards (Simplified for print as a small table or list) -->
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px; border: 1px solid #ddd; padding: 10px;">
        <div>
            <strong>Total Annual Payroll:</strong><br>
            <?php echo formatMoney($totals['salary']); ?>
        </div>
        <div>
            <strong>Benefits & Allowances:</strong><br>
            <?php echo formatMoney($totals['benefits'] + $totals['allowances']); ?>
        </div>
        <div>
            <strong>Variable Pay:</strong><br>
            <?php echo formatMoney($totals['incentives']); ?>
        </div>
        <div>
            <strong>Total Investment:</strong><br>
            <?php echo formatMoney($totals['grand_total']); ?>
        </div>
    </div>

    <!-- Department Summary -->
    <div class="section-title">Departmental Cost Summary</div>
    <table>
        <thead>
            <tr>
                <th>Department</th>
                <th class="text-center">Headcount</th>
                <th class="text-end">Base Salary</th>
                <th class="text-end">Benefits/Allowances</th>
                <th class="text-end">Variable Pay</th>
                <th class="text-end">Total Investment</th>
                <th class="text-end">%</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($deptSummary as $dept => $data): 
                $pct = ($totals['grand_total'] > 0) ? ($data['total'] / $totals['grand_total']) * 100 : 0;
            ?>
            <tr>
                <td class="fw-bold"><?php echo htmlspecialchars($dept); ?></td>
                <td class="text-center"><?php echo $data['headcount']; ?></td>
                <td class="text-end"><?php echo formatMoney($data['salary']); ?></td>
                <td class="text-end"><?php echo formatMoney($data['benefits_allowances']); ?></td>
                <td class="text-end"><?php echo formatMoney($data['variable']); ?></td>
                <td class="text-end fw-bold"><?php echo formatMoney($data['total']); ?></td>
                <td class="text-end"><?php echo number_format($pct, 1); ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Detailed Breakdown -->
    <div class="section-title">Employee Compensation Breakdown</div>
    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Role / Dept</th>
                <th class="text-end">Base Salary</th>
                <th class="text-end">Benefits</th>
                <th class="text-end">Incentives</th>
                <th class="text-end">Total Package</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reportData as $row): ?>
            <tr>
                <td class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                <td>
                    <?php echo htmlspecialchars($row['role']); ?> / <?php echo htmlspecialchars($row['dept'] ?? '-'); ?>
                </td>
                <td class="text-end"><?php echo formatMoney($row['annual_salary']); ?></td>
                <td class="text-end"><?php echo formatMoney($row['annual_benefits'] + $row['annual_allowances']); ?></td>
                <td class="text-end"><?php echo formatMoney($row['total_incentives']); ?></td>
                <td class="text-end fw-bold"><?php echo formatMoney($row['total_comp']); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="background-color: #f0f0f0; font-weight: bold;">
                <td colspan="2" class="text-end">TOTALS:</td>
                <td class="text-end"><?php echo formatMoney($totals['salary']); ?></td>
                <td class="text-end"><?php echo formatMoney($totals['benefits'] + $totals['allowances']); ?></td>
                <td class="text-end"><?php echo formatMoney($totals['incentives']); ?></td>
                <td class="text-end"><?php echo formatMoney($totals['grand_total']); ?></td>
            </tr>
        </tfoot>
    </table>

</body>
</html>
