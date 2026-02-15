<?php
// payroll/payroll-reporting.php - Dedicated Payroll Reporting Module
session_start();
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// Logic: Fetch all finalized payroll calculations (Periods)
// Filter: Exclude status 'Draft' or maybe include all? User said "all breakdown inside the payroll calculation"
// User specified: "anything in the payroll managament or payroll folder not included the financial-approval.php"
// This means this reporting tool focuses on the Calculation (Gross, Net, Deductions) side, not the Budget Approval side.

$periods = $pdo->query("SELECT * FROM payroll_periods WHERE status != 'Draft' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$selected_period_id = $_GET['period_id'] ?? ($periods[0]['id'] ?? null);
$report_title = "Select a Period";
$records = [];
$totals = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'sss' => 0, 'philhealth' => 0, 'pagibig' => 0, 'tax' => 0];

if ($selected_period_id) {
    // Get Period Info
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$selected_period_id]);
    $period_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($period_info) {
        $report_title = $period_info['name'] . " (" . date('M d', strtotime($period_info['start_date'])) . " - " . date('M d', strtotime($period_info['end_date'])) . ")";
        
        // Fetch All Records
        $recStmt = $pdo->prepare("SELECT * FROM payroll_records WHERE payroll_period_id = ? ORDER BY department, employee_name");
        $recStmt->execute([$selected_period_id]);
        $records = $recStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate Totals
        foreach ($records as $r) {
            $totals['gross'] += $r['gross_pay'];
            $totals['deductions'] += $r['total_deductions'];
            $totals['net'] += $r['net_pay'];
            $totals['sss'] += $r['deduction_sss'];
            $totals['philhealth'] += $r['deduction_philhealth'];
            $totals['pagibig'] += $r['deduction_pagibig'];
            $totals['tax'] += $r['deduction_tax'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payroll Reports | HR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fc; font-family: 'Segoe UI', system-ui, sans-serif; }
        .main-content { padding: 2rem; margin-top: 60px; }
        .card { border: none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); border-radius: 0.5rem; }
        .table th { background-color: #4e73df; color: white; vertical-align: middle; white-space: nowrap; }
        .table td { vertical-align: middle; }
        @media print {
            .no-print, .dashboard-header, .sidebar, .theme-toggle-btn, .btn-secondary { display: none !important; }
            .card { box-shadow: none; border: 1px solid #000; }
            /* Fix the header to the top of every page */
            .d-print-block { 
                display: block !important; 
                position: fixed; 
                top: 0; 
                left: 0; 
                width: 100%; 
                background: white; 
                z-index: 1000;
                padding-bottom: 20px;
                border-bottom: 2px solid #000;
            }
            /* Add padding to prevent content overlap with fixed header ~150px height */
            .main-content { 
                margin: 0 !important; 
                padding: 0 !important; 
                padding-top: 150px !important; 
                width: 100% !important; 
            }
            /* Repeat table headers */
            thead { display: table-header-group; }
            tfoot { display: table-footer-group; }
            tr { page-break-inside: avoid; }
            
            body { background-color: white; -webkit-print-color-adjust: exact; }
            .table-responsive { overflow: visible !important; }
            .d-print-none { display: none !important; }
        }
    </style>
</head>
<body>

<div class="main-content">
    <!-- Print Header (Fixed on every page) -->
    <div id="print-header" class="d-none d-print-block text-center mb-4 bg-white">
        <h1 class="fw-bold text-uppercase display-6 text-dark mb-0" style="letter-spacing: 2px;">SLATE FREIGHT</h1>
        <p class="text-uppercase small text-muted mb-2">Human Resources Department</p>
        <h5 class="fw-bold text-uppercase mt-2 border-top pt-2 d-inline-block"><?php echo $report_title; ?></h5>
    </div>

    <!-- Screen Header (Hidden in Print) -->
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-file-invoice"></i> Payroll Reports</h1>
        <button class="btn btn-secondary no-print" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
    </div>

    <!-- Filter Selection -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row align-items-end">
                <div class="col-md-6">
                    <label class="form-label fw-bold">Select Payroll Period</label>
                    <select name="period_id" class="form-select">
                        <?php foreach($periods as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo $selected_period_id == $p['id'] ? 'selected' : ''; ?>>
                                <?php echo $p['name']; ?> [<?php echo $p['status']; ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Generate</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($selected_period_id && !empty($records)): ?>
        
        <!-- Report Header -->
        <div class="text-center mb-4">
            <h2 class="h4 fw-bold text-uppercase"><?php echo $report_title; ?></h2>
            <p class="text-muted">Generated on <?php echo date('F d, Y h:i A'); ?></p>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4 text-center">
            <div class="col-md-3">
                <div class="card border-left-primary h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Gross Pay</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">₱<?php echo number_format($totals['gross'], 2); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-danger h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Total Deductions</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">₱<?php echo number_format($totals['deductions'], 2); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-success h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Net Pay</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">₱<?php echo number_format($totals['net'], 2); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-left-info h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Headcount</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo count($records); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Table -->
        <div class="card">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">Detailed Breakdown</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Department</th>
                                <th class="text-end">Basic Salary</th>
                                <th class="text-end">Allowances</th>
                                <th class="text-end">Overtime</th>
                                <th class="text-end table-active">Gross Pay</th>
                                <th class="text-end text-danger">SSS</th>
                                <th class="text-end text-danger">PhilHealth</th>
                                <th class="text-end text-danger">Pag-IBIG</th>
                                <th class="text-end text-danger">Tax</th>
                                <th class="text-end table-active text-danger">Total Ded.</th>
                                <th class="text-end table-success text-dark fw-bold">Net Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                            <tr>
                                <td class="fw-bold"><?php echo $r['employee_name']; ?></td>
                                <td><?php echo $r['department']; ?></td>
                                <td class="text-end"><?php echo number_format($r['basic_salary'], 2); ?></td>
                                <td class="text-end"><?php echo number_format($r['allowances'], 2); ?></td>
                                <td class="text-end"><?php echo number_format($r['overtime_pay'], 2); ?></td>
                                <td class="text-end table-active fw-bold"><?php echo number_format($r['gross_pay'], 2); ?></td>
                                <td class="text-end text-danger small"><?php echo number_format($r['deduction_sss'], 2); ?></td>
                                <td class="text-end text-danger small"><?php echo number_format($r['deduction_philhealth'], 2); ?></td>
                                <td class="text-end text-danger small"><?php echo number_format($r['deduction_pagibig'], 2); ?></td>
                                <td class="text-end text-danger small"><?php echo number_format($r['deduction_tax'], 2); ?></td>
                                <td class="text-end table-active text-danger fw-bold"><?php echo number_format($r['total_deductions'], 2); ?></td>
                                <td class="text-end table-success fw-bold text-dark"><?php echo number_format($r['net_pay'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="2" class="text-center fw-bold">GRAND TOTALS</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end">-</td>
                                <td class="text-end fw-bold">₱<?php echo number_format($totals['gross'], 2); ?></td>
                                <td class="text-end">₱<?php echo number_format($totals['sss'], 2); ?></td>
                                <td class="text-end">₱<?php echo number_format($totals['philhealth'], 2); ?></td>
                                <td class="text-end">₱<?php echo number_format($totals['pagibig'], 2); ?></td>
                                <td class="text-end">₱<?php echo number_format($totals['tax'], 2); ?></td>
                                <td class="text-end fw-bold">₱<?php echo number_format($totals['deductions'], 2); ?></td>
                                <td class="text-end fw-bold">₱<?php echo number_format($totals['net'], 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

    <?php elseif($selected_period_id): ?>
        <div class="alert alert-warning text-center">No payroll records found for this period.</div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
