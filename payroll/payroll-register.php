<?php
// payroll/payroll-register.php - Printable Payroll Register for Finance
session_start();
require_once '../config/db.php';

$periodId = $_GET['period_id'] ?? null;
if (!$periodId) die("Period ID required.");

// Fetch Period
$stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
$stmt->execute([$periodId]);
$period = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$period) die("Period not found.");

// Fetch Records (Ordered by Department, Name)
$stmtRec = $pdo->prepare("SELECT * FROM payroll_records WHERE payroll_period_id = ? ORDER BY department, employee_name");
$stmtRec->execute([$periodId]);
$records = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

$grandTotal = [
    'basic' => 0, 'ot' => 0, 'allowance' => 0, 'gross' => 0,
    'sss' => 0, 'phil' => 0, 'pagibig' => 0, 'tax' => 0, 'other' => 0,
    'deductions' => 0, 'net' => 0
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Register - <?php echo $period['name']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-size: 12px; }
        @media print {
            .no-print { display: none; }
            @page { size: landscape; margin: 10mm; }
        }
        .header { text-align: center; margin-bottom: 20px; }
        .table-custom th, .table-custom td { padding: 4px; vertical-align: middle; }
        .dept-header { background: #f0f0f0; font-weight: bold; }
    </style>
</head>
<body class="p-4">

    <div class="no-print mb-3">
        <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print Register</button>
        <a href="payroll-calculation.php?view_id=<?php echo $periodId; ?>" class="btn btn-secondary">Back</a>
    </div>

    <div class="header">
        <h4>SLATE FREIGHT</h4>
        <h5>PAYROLL REGISTER</h5>
        <p>Period: <?php echo date('F d, Y', strtotime($period['start_date'])) . ' - ' . date('F d, Y', strtotime($period['end_date'])); ?> | Created: <?php echo date('Y-m-d H:i'); ?></p>
    </div>

    <table class="table table-bordered table-striped table-custom">
        <thead class="table-dark">
            <tr>
                <th rowspan="2">Employee Name</th>
                <th colspan="4" class="text-center">Earnings</th>
                <th colspan="5" class="text-center">Deductions</th>
                <th rowspan="2" class="text-end">NET PAY</th>
            </tr>
            <tr>
                <th class="text-end">Basic</th>
                <th class="text-end">Overtime</th>
                <th class="text-end">Allowances</th>
                <th class="text-end">GROSS</th>
                <th class="text-end">SSS</th>
                <th class="text-end">PhilHealth</th>
                <th class="text-end">Pag-IBIG</th>
                <th class="text-end">Tax</th>
                <th class="text-end">TOTAL DED.</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $currentDept = '';
            foreach ($records as $r): 
                if ($currentDept != $r['department']) {
                    $currentDept = $r['department'];
                    echo "<tr class='dept-header'><td colspan='11'>Department: $currentDept</td></tr>";
                }
                
                // Accumulate Totals
                $grandTotal['basic'] += $r['basic_salary'];
                $grandTotal['ot'] += $r['overtime_pay'];
                $grandTotal['allowance'] += $r['allowances'];
                $grandTotal['gross'] += $r['gross_pay'];
                $grandTotal['sss'] += $r['deduction_sss'] ?? 0;
                $grandTotal['phil'] += $r['deduction_philhealth'] ?? 0;
                $grandTotal['pagibig'] += $r['deduction_pagibig'] ?? 0;
                $grandTotal['tax'] += $r['deduction_tax'] ?? 0;
                $grandTotal['deductions'] += $r['total_deductions'];
                $grandTotal['net'] += $r['net_pay'];
            ?>
            <tr>
                <td><?php echo $r['employee_name']; ?></td>
                <td class="text-end"><?php echo number_format($r['basic_salary'], 2); ?></td>
                <td class="text-end"><?php echo number_format($r['overtime_pay'], 2); ?></td>
                <td class="text-end"><?php echo number_format($r['allowances'], 2); ?></td>
                <td class="text-end fw-bold"><?php echo number_format($r['gross_pay'], 2); ?></td>
                
                <td class="text-end"><?php echo number_format($r['deduction_sss'] ?? 0, 2); ?></td>
                <td class="text-end"><?php echo number_format($r['deduction_philhealth'] ?? 0, 2); ?></td>
                <td class="text-end"><?php echo number_format($r['deduction_pagibig'] ?? 0, 2); ?></td>
                <td class="text-end"><?php echo number_format($r['deduction_tax'] ?? 0, 2); ?></td>
                <td class="text-end fw-bold text-danger"><?php echo number_format($r['total_deductions'], 2); ?></td>
                
                <td class="text-end fw-bold text-success"><?php echo number_format($r['net_pay'], 2); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot class="table-dark">
            <tr>
                <th>GRAND TOTAL</th>
                <th class="text-end"><?php echo number_format($grandTotal['basic'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['ot'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['allowance'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['gross'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['sss'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['phil'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['pagibig'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['tax'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['deductions'], 2); ?></th>
                <th class="text-end"><?php echo number_format($grandTotal['net'], 2); ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="row mt-5">
        <div class="col-4 text-center">
            <p>Prepared By:</p>
            <br>
            <div style="border-bottom: 1px solid #000; width: 80%; margin: 0 auto;"></div>
            <p class="mt-2">HR Department</p>
        </div>
        <div class="col-4 text-center">
            <p>Certified Correct:</p>
            <br>
            <div style="border-bottom: 1px solid #000; width: 80%; margin: 0 auto;"></div>
            <p class="mt-2">Finance Manager</p>
        </div>
         <div class="col-4 text-center">
            <p>Approved By:</p>
            <br>
            <div style="border-bottom: 1px solid #000; width: 80%; margin: 0 auto;"></div>
            <p class="mt-2">General Manager</p>
        </div>
    </div>

</body>
</html>
