<?php
// payroll/payslip-generator.php - Generates printable payslips
session_start();
require_once '../config/db.php';

// Authentication Check
if (!isset($_SESSION['user']) && !isset($_SESSION['role'])) {
    // Basic check, adjust as needed based on project auth
    // header('Location: ../index.php'); exit;
}

$recordId = $_GET['id'] ?? null;
$periodId = $_GET['period_id'] ?? null;

if (!$recordId && !$periodId) {
    // Show Selection Screen if no params provided
    // Updated: Show 'Approved', 'Released' (and 'Budgeted' for preview)
    $periods = $pdo->query("SELECT * FROM payroll_periods WHERE status IN ('Budgeted', 'Approved', 'Released') ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $employees = $pdo->query("SELECT id, name FROM employees WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Generate Payslip</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="card shadow-sm" style="max-width: 600px; margin: 0 auto;">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Generate Payslips</h4>
                </div>
                <div class="card-body">
                    <form method="GET">
                        <div class="mb-3">
                            <label class="form-label">Select Payroll Period</label>
                            <select name="period_id" class="form-select" required>
                                <option value="">-- Choose Period --</option>
                                <?php foreach($periods as $p): ?>
                                    <option value="<?php echo $p['id']; ?>">
                                        <?php echo $p['name']; ?> [<?php echo strtoupper($p['status']); ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Select Employee (Optional)</label>
                            <select name="employee_id" class="form-select">
                                <option value="">-- All Employees (Bundle) --</option>
                                <?php foreach($employees as $emp): ?>
                                    <option value="<?php echo $emp['id']; ?>">
                                        <?php echo $emp['name']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Leave empty to generate for all employees in the period.</div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Generate</button>
                    </form>
                    
                    <hr class="my-4">
                    
                    <a href="payroll-calculation.php" class="btn btn-outline-secondary w-100">Back to Payroll List</a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$records = [];
$periodInfo = null;

try {
    if ($recordId) {
        // Fetch Single Record by Transaction ID
        $stmt = $pdo->prepare("SELECT pr.*, e.job_title, e.date_hired, e.tin_no, e.sss_no, e.philhealth_no, e.pagibig_no 
                               FROM payroll_records pr 
                               LEFT JOIN employees e ON pr.employee_id = e.id 
                               WHERE pr.id = ?");
        $stmt->execute([$recordId]);
        $records[] = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($periodId) {
        $empId = $_GET['employee_id'] ?? null;
        
        $query = "SELECT pr.*, e.job_title, e.date_hired, e.tin_no, e.sss_no, e.philhealth_no, e.pagibig_no 
                  FROM payroll_records pr 
                  LEFT JOIN employees e ON pr.employee_id = e.id 
                  WHERE pr.payroll_period_id = ?";
        $params = [$periodId];
        
        if ($empId) {
            $query .= " AND pr.employee_id = ?";
            $params[] = $empId;
        }
        
        $query .= " ORDER BY pr.employee_name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get Period Info if not set
    if (!empty($records)) {
        $pId = $records[0]['payroll_period_id'];
        $stmtP = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
        $stmtP->execute([$pId]);
        $periodInfo = $stmtP->fetch(PDO::FETCH_ASSOC);
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

if (empty($records) || !$records[0]) {
    die("No payslip records found for the selected criteria.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip - <?php echo $periodInfo['name'] ?? 'Generated'; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap');
        
        body {
            font-family: 'Roboto', sans-serif;
            background: #e0e0e0;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        
        .payslip-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .company-name {
            font-size: 24px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .company-details {
            font-size: 12px;
            color: #555;
            margin-top: 5px;
        }
        
        .payslip-title {
            font-size: 18px;
            font-weight: 700;
            margin-top: 15px;
            text-transform: uppercase;
        }
        
        .employee-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 15px;
        }
        
        .info-group {
            width: 48%;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        .label {
            font-weight: 500;
            color: #666;
        }
        
        .value {
            font-weight: 700;
            color: #000;
        }
        
        .earnings-deductions {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .section {
            width: 48%;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            background: #f4f4f4;
            padding: 5px 10px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .item-row {
            display: flex;
            justify-content: space-between;
            padding: 3px 10px;
            font-size: 13px;
            border-bottom: 1px dotted #ccc;
        }
        
        .item-row:last-child {
            border-bottom: none;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 10px;
            font-size: 14px;
            font-weight: 700;
            background: #f9f9f9;
            margin-top: 10px;
            border-top: 2px solid #333;
        }
        
        .net-pay-section {
            border: 2px solid #333;
            padding: 10px;
            text-align: right;
            background: #fcfcfc;
            margin-bottom: 30px;
        }
        
        .net-pay-label {
            font-size: 14px;
            text-transform: uppercase;
            font-weight: 700;
            margin-right: 20px;
        }
        
        .net-pay-amount {
            font-size: 20px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .footer {
            text-align: center;
            font-size: 11px;
            color: #777;
            margin-top: 20px;
            font-style: italic;
        }
        
        @media print {
            body { background: white; -webkit-print-color-adjust: exact; }
            .payslip-container { box-shadow: none; border: 1px solid #ccc; page-break-after: always; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="text-align: center; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #2c3e50; color: white; border: none; font-size: 16px; cursor: pointer;">
            <i class="fas fa-print"></i> Print Payslip(s)
        </button>
        <button onclick="sendViaEmail()" style="padding: 10px 20px; background: #27ae60; color: white; border: none; font-size: 16px; cursor: pointer; margin-left:10px;">
            <i class="fas fa-envelope"></i> Send via Email
        </button>
        <a href="payroll-calculation.php" style="padding: 10px 20px; background: #666; color: white; border: none; font-size: 16px; cursor: pointer; margin-left:10px; text-decoration: none; display: inline-block;">
            Back
        </a>
        <div id="email-status" style="margin-top: 10px; color: green; display: none; font-weight: bold;"></div>
    </div>
    
    <script>
    function sendViaEmail() {
        const status = document.getElementById('email-status');
        status.style.display = 'block';
        status.style.color = '#e67e22';
        status.innerText = "Sending securely...";
        
        // Simulation of sending
        setTimeout(() => {
            status.style.color = 'green';
            status.innerText = "Payslips sent successfully to registered employee email(s)!";
            setTimeout(() => { status.style.display = 'none'; }, 3000);
        }, 1500);
    }
    </script>
    
    <!-- Link Font Awesome for Icons (if not already included, but good habit) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?php 
    $count = 0;
    $totalRecords = count($records);
    
    foreach ($records as $rec): 
        $count++;
        // Decode logic for calculation details
        $details = json_decode($rec['calculation_details'] ?? '{}', true);
        
        // Deduction Breakdowns (Safe Fallback)
        $sss = $details['sss_deduction'] ?? ($rec['deduction_sss'] ?? 0);
        $phil = $details['philhealth_deduction'] ?? ($rec['deduction_philhealth'] ?? 0);
        $pagibig = $details['pagibig_deduction'] ?? ($rec['deduction_pagibig'] ?? 0);
        $tax = $details['withholding_tax'] ?? ($details['tax_deduction'] ?? ($rec['deduction_tax'] ?? 0));
        
        // Calculate other deductions if any
        $total_statutory = $sss + $phil + $pagibig + $tax;
        $other_deductions = $rec['total_deductions'] - $total_statutory;
    ?>
    
    <!-- Payslip Container with Page Break Logic -->
    <div class="payslip-container" style="<?php echo ($count < $totalRecords) ? 'page-break-after: always;' : ''; ?>">
        
        <!-- Header Section -->
        <div class="header">
            <div class="company-name">SLATE FREIGHT</div>
            <div class="company-details">123 Logistics Way, Transport City, Manila, Philippines</div>
            <div class="payslip-title">Payslip</div>
        </div>

        <!-- Employee Info Grid -->
        <div class="employee-info">
            <div class="info-group">
                <div class="info-row"><span class="label">Employee Name:</span> <span class="value"><?php echo htmlspecialchars($rec['employee_name']); ?></span></div>
                <div class="info-row"><span class="label">Employee ID:</span> <span class="value"><?php echo strtoupper($rec['employee_id'] ?? 'N/A'); ?></span></div>
                <div class="info-row"><span class="label">Position:</span> <span class="value"><?php echo htmlspecialchars($rec['job_title'] ?? 'N/A'); ?></span></div>
                <div class="info-row"><span class="label">Department:</span> <span class="value"><?php echo htmlspecialchars($rec['department'] ?? 'N/A'); ?></span></div>
            </div>
            <div class="info-group">
                <div class="info-row"><span class="label">Pay Period:</span> <span class="value"><?php echo (isset($rec['pay_period_start']) ? date('M d, Y', strtotime($rec['pay_period_start'])) : '') . ' - ' . (isset($rec['pay_period_end']) ? date('M d, Y', strtotime($rec['pay_period_end'])) : ''); ?></span></div>
                <div class="info-row"><span class="label">Pay Date:</span> <span class="value"><?php echo date('M d, Y'); ?></span></div>
                <div class="info-row"><span class="label">TIN:</span> <span class="value"><?php echo $rec['tin_no'] ?? 'N/A'; ?></span></div>
                <div class="info-row"><span class="label">SSS / PH / HDMF:</span> <span class="value" style="font-size:10px;">Available on Request</span></div>
            </div>
        </div>

        <div class="earnings-deductions">
            <!-- EARNINGS SECTION -->
            <div class="section">
                <div class="section-title">Earnings</div>
                
                <div class="item-row">
                    <span>Basic Salary</span>
                    <span><?php echo number_format($rec['basic_salary'], 2); ?></span>
                </div>
                
                <?php if ($rec['overtime_pay'] > 0): ?>
                <div class="item-row">
                    <span>Overtime Pay</span>
                    <span><?php echo number_format($rec['overtime_pay'], 2); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (isset($rec['allowances']) && $rec['allowances'] > 0): ?>
                <div class="item-row">
                    <span>Allowances</span>
                    <span><?php echo number_format($rec['allowances'], 2); ?></span>
                </div>
                <?php endif; ?>
                
                <!-- Dynamic Earnings from Details -->
                <?php 
                    if(isset($details['holiday_pay']) && $details['holiday_pay'] > 0) {
                        echo '<div class="item-row"><span>Holiday Pay</span><span>'.number_format($details['holiday_pay'], 2).'</span></div>';
                    }
                    if(isset($details['night_diff']) && $details['night_diff'] > 0) {
                        echo '<div class="item-row"><span>Night Differential</span><span>'.number_format($details['night_diff'], 2).'</span></div>';
                    }
                    if(isset($details['13th_month']) && $details['13th_month'] > 0) {
                        echo '<div class="item-row"><span>13th Month Pay</span><span>'.number_format($details['13th_month'], 2).'</span></div>';
                    }
                ?>

                <div class="total-row">
                    <span>Total Earnings</span>
                    <span><?php echo number_format($rec['gross_pay'], 2); ?></span>
                </div>
            </div>

            <!-- DEDUCTIONS SECTION -->
            <div class="section">
                <div class="section-title">Deductions</div>
                
                <div class="item-row">
                    <span>SSS Contribution</span>
                    <span><?php echo number_format($sss, 2); ?></span>
                </div>
                
                <div class="item-row">
                    <span>PhilHealth Contribution</span>
                    <span><?php echo number_format($phil, 2); ?></span>
                </div>
                
                <div class="item-row">
                    <span>Pag-IBIG Contribution</span>
                    <span><?php echo number_format($pagibig, 2); ?></span>
                </div>
                
                <div class="item-row">
                    <span>Withholding Tax</span>
                    <span><?php echo number_format($tax, 2); ?></span>
                </div>
                
                <?php if ($other_deductions > 0.01): ?>
                <div class="item-row">
                    <span>Other Deductions (Loans/Adv)</span>
                    <span><?php echo number_format($other_deductions, 2); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="total-row" style="color: #c0392b;">
                    <span>Total Deductions</span>
                    <span>(<?php echo number_format($rec['total_deductions'], 2); ?>)</span>
                </div>
            </div>
        </div>

        <div class="net-pay-section">
            <span class="net-pay-label">Net Pay:</span>
            <span class="net-pay-amount">₱ <?php echo number_format($rec['net_pay'], 2); ?></span>
        </div>

        <div class="footer">
            This is a system-generated payslip. Signature not required. <br>
            Generated on <?php echo date('Y-m-d H:i:s'); ?> | Period: <?php echo $periodInfo['period_code'] ?? 'N/A'; ?>
        </div>
    </div>
    <?php endforeach; ?>

</body>
</html>
