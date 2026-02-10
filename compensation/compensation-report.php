<?php
// compensation/compensation-report.php
session_start();
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// Initialize Theme
$currentTheme = $_SESSION['theme'] ?? 'light';

// Helper for total cost formatting
function formatMoney($amount) {
    return '₱' . number_format((float)$amount, 2);
}

// -------------------------------------------------------------------------
// 1. DATA AGGREGATION
// -------------------------------------------------------------------------

// A. Employees & Base Salaries
try {
    $employees = $pdo->query("SELECT id, name, job_title, department, salary FROM employees WHERE status = 'Active' ORDER BY name ASC")->fetchAll();
} catch (Exception $e) {
    $employees = [];
}

// B. Active Benefits Cost (Employer Share)
// Group by employee name
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
        WHERE ea.status = 'Active' AND at.type = 'Fixed' -- Only fixed monthly allowances are projected annually reliably
        GROUP BY ea.employee_name
    ");
    $allowancesData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* Table might not exist */ }

// D. Incentives (Received YTD - or simply all recorded for this report snapshot)
// We'll sum all granted incentives as "Variable Pay Distributed"
$incentivesData = [];
try {
    $stmt = $pdo->query("
        SELECT employee_name, SUM(amount) as total_incentives 
        FROM employee_incentives 
        GROUP BY employee_name
    ");
    $incentivesData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* Table might not exist */ }

// E. Recent Salary Adjustments (Audit)
$recentAdjustments = [];
try {
    // Check if table v2 exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'salary_adjustments_v2'")->rowCount() > 0;
    $adjTable = $tableExists ? 'salary_adjustments_v2' : 'salary_adjustments';
    
    $stmt = $pdo->query("
        SELECT sa.*, at.type_name 
        FROM $adjTable sa
        LEFT JOIN adjustment_types at ON sa.adjustment_type_id = at.id
        WHERE sa.status = 'Approved'
        ORDER BY sa.effective_date DESC LIMIT 10
    ");
    $recentAdjustments = $stmt->fetchAll();
} catch (Exception $e) { /* Ignore */ }

// -------------------------------------------------------------------------
// 2. CONSOLIDATE DATA FOR REPORT
// -------------------------------------------------------------------------
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compensation Analysis Report | HR 4</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #f8f9fc;
            --dark-bg: #2c3e50;
            --dark-card: #34495e;
            --text-dark: #212529;
            --text-light: #f8f9fa;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
        }
        
        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
            --secondary-color: #2c3e50;
        }

        .main-content { padding: 2rem; margin-top: 60px; }
        
        .card-custom {
            background: white;
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 1.5rem;
        }
        body.dark-mode .card-custom { background: var(--dark-card); }
        
        /* Print Styles */
        @media print {
            @page { size: landscape; margin: 0.5cm; }
            body, .main-content, .card-custom, table, th, td, .stat-card { 
                background-color: #ffffff !important; 
                color: #000000 !important; 
                box-shadow: none !important;
                border-color: #000000 !important;
            }
            .sidebar, .dashboard-header, .btn-print-group { display: none !important; }
            .main-content { margin: 0; padding: 0; width: 100%; overflow: visible; }
            .card-custom { border: none !important; margin-bottom: 20px; page-break-inside: auto; }
            .table-responsive { overflow: visible !important; }
            .table { font-size: 10pt; width: 100%; border-collapse: collapse !important; }
            .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
            tr { page-break-inside: avoid; }
            .badge { border: 1px solid #000; color: #000; }
            
            /* Hide URL printing in some browsers */
            a[href]:after { content: none !important; }
        }
        
        .stat-card {
            border-left: 4px solid var(--primary-color);
            padding: 1.5rem;
        }
        .stat-card.benefits { border-left-color: #1cc88a; }
        .stat-card.variable { border-left-color: #f6c23e; }
        
        .stat-label { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; color: #888; }
        .stat-value { font-size: 1.5rem; font-weight: 700; color: #5a5c69; }
        body.dark-mode .stat-value { color: #f8f9fa; }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        
        <!-- Print Header (Visible only in Print) -->
        <div class="d-none d-print-block text-center mb-4">
            <h2 class="fw-bold">SLATE FREIGHT</h2>
            <h4 class="text-uppercase">Compensation Planning Report</h4>
            <p class="text-muted">Generated on: <?php echo date('F d, Y'); ?></p>
            <hr>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4 btn-print-group">
            <div>
                <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-file-invoice-dollar"></i> Compensation Analysis Report</h1>
                <p class="text-muted">Consolidated view of all compensation modules</p>
            </div>
            <div>
                <button onclick="printAsPDF()" class="btn btn-secondary me-2"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card card-custom stat-card">
                    <div class="stat-label">Total Annual Payroll</div>
                    <div class="stat-value text-primary"><?php echo formatMoney($totals['salary']); ?></div>
                    <small>Base Salaries (Projected)</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom stat-card benefits">
                    <div class="stat-label">Total Benefits & Allowances</div>
                    <div class="stat-value text-success"><?php echo formatMoney($totals['benefits'] + $totals['allowances']); ?></div>
                    <small>Employer Share + Fixed Allowances</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom stat-card variable">
                    <div class="stat-label">Variable Pay Distributed</div>
                    <div class="stat-value text-warning"><?php echo formatMoney($totals['incentives']); ?></div>
                    <small>Total Incentives Granted</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-custom stat-card">
                    <div class="stat-label">Total Investment</div>
                    <div class="stat-value"><?php echo formatMoney($totals['grand_total']); ?></div>
                    <small>Grand Total Compensation</small>
                </div>
            </div>
        </div>

        <!-- Department Summary Section -->
        <div class="card card-custom mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-success">Departmental Cost Summary</h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm mb-0" id="summaryTable">
                        <thead class="table-light">
                            <tr>
                                <th>Department</th>
                                <th class="text-center">Headcount</th>
                                <th class="text-end">Base Salary (Annual)</th>
                                <th class="text-end">Benefits & Allowances</th>
                                <th class="text-end">Variable Pay</th>
                                <th class="text-end">Total Investment</th>
                                <th class="text-end">% of Total</th>
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
                                <td class="text-end fw-bold text-success"><?php echo formatMoney($data['total']); ?></td>
                                <td class="text-end"><?php echo number_format($pct, 1); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Detailed Breakdown -->
        <div class="card card-custom">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">Employee Compensation Breakdown (Annualized)</h6>
                <small class="text-muted">Generated on <?php echo date('F d, Y'); ?></small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="reportTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Role / Dept</th>
                                <th>Base Salary (Annual)</th>
                                <th>Benefits (Annual)</th>
                                <th>Allowances (Annual)</th>
                                <th>Incentives (Total)</th>
                                <th>Total Package</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($row['role']); ?><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($row['dept'] ?? '-'); ?></small>
                                </td>
                                <td><?php echo formatMoney($row['annual_salary']); ?></td>
                                <td><?php echo formatMoney($row['annual_benefits']); ?></td>
                                <td><?php echo formatMoney($row['annual_allowances']); ?></td>
                                <td><?php echo formatMoney($row['total_incentives']); ?></td>
                                <td class="fw-bold text-primary"><?php echo formatMoney($row['total_comp']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold bg-light">
                                <td colspan="2" class="text-end">TOTALS:</td>
                                <td><?php echo formatMoney($totals['salary']); ?></td>
                                <td><?php echo formatMoney($totals['benefits']); ?></td>
                                <td><?php echo formatMoney($totals['allowances']); ?></td>
                                <td><?php echo formatMoney($totals['incentives']); ?></td>
                                <td><?php echo formatMoney($totals['grand_total']); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Adjustments Section (Footer) -->
        <?php if (!empty($recentAdjustments)): ?>
        <div class="card card-custom mt-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-info">Recent Salary Adjustments Log</h6>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Employee</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Old Salary</th>
                            <th>New Salary</th>
                            <th>Increase</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAdjustments as $adj): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($adj['employee_name']); ?></td>
                            <td><?php echo htmlspecialchars($adj['type_name']); ?></td>
                            <td><?php echo htmlspecialchars($adj['effective_date']); ?></td>
                            <td><?php echo formatMoney($adj['current_salary']); ?></td>
                            <td><?php echo formatMoney($adj['new_salary']); ?></td>
                            <td class="text-success">+<?php echo formatMoney($adj['adjustment_amount']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function printAsPDF() {
            // Open the dedicated print page in a new window/tab
            window.open('print-compensation-report.php', '_blank');
        }

        function downloadPDF() {
            // Check if library is loaded
            if (typeof html2pdf === 'undefined') {
                alert('PDF library not loaded. Using browser print instead.');
                window.print();
                return;
            }

            const element = document.querySelector('.main-content');
            const buttons = element.querySelector('.btn-print-group');
            const printHeader = element.querySelector('.d-print-block');

            // 1. Prepare for PDF: Show header, hide buttons
            // Store original states
            const originalBtnDisplay = buttons ? buttons.style.display : '';
            
            // Hide buttons
            if (buttons) buttons.style.display = 'none';

            let headerWasHidden = false;
            // The header has 'd-none d-print-block'. We need to override 'd-none'.
            if (printHeader && printHeader.classList.contains('d-none')) {
                printHeader.classList.remove('d-none');
                headerWasHidden = true;
            }
            // Also ensure it's displayed (d-print-block might rely on media query, we want it now)
            if (printHeader) printHeader.style.display = 'block';

            const opt = {
                margin: 0.3,
                filename: 'Compensation_Report_<?php echo date("Y-m-d"); ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'in', format: 'landscape', orientation: 'landscape' }
            };

            // Generate PDF and then restore
            html2pdf().set(opt).from(element).save().then(() => {
                // Restore state
                if (buttons) buttons.style.display = originalBtnDisplay;
                if (printHeader) {
                    printHeader.style.display = ''; // Revert inline style
                    if (headerWasHidden) printHeader.classList.add('d-none');
                }
            }).catch(err => {
                console.error(err);
                alert('Error generating PDF. Please try the Print button.');
                // Restore state on error too
                if (buttons) buttons.style.display = originalBtnDisplay;
                if (printHeader) {
                    printHeader.style.display = '';
                    if (headerWasHidden) printHeader.classList.add('d-none');
                }
            });
        }

        function exportTableToCSV(filename) {
            var csv = [];
            
            // Helper to clean data for CSV
            function cleanData(text) {
                // Remove Peso sign and commas for clean numeric export
                var clean = text.replace(/(\r\n|\n|\r)/gm, "").trim();
                if (clean.includes('₱')) {
                    clean = clean.replace(/[₱,]/g, ''); // Remove currency symbols and commas
                }
                return '"' + clean + '"';
            }
            
            // 1. Add Report Metadata
            csv.push('"SLATE FREIGHT - COMPENSATION ANALYSIS REPORT"');
            csv.push('"Generated on: <?php echo date('F d, Y'); ?>"');
            csv.push([]); // Blank line

            // 2. Add Department Summary
            csv.push('"DEPARTMENTAL COST SUMMARY"');
            var summaryRows = document.querySelectorAll("table#summaryTable tr");
            for (var i = 0; i < summaryRows.length; i++) {
                var row = [], cols = summaryRows[i].querySelectorAll("td, th");
                for (var j = 0; j < cols.length; j++) 
                    row.push(cleanData(cols[j].innerText));
                csv.push(row.join(","));        
            }

            // 2b. Add Department Summary Totals (if footer exists) or just ensure it's captured
            // The current implementation captures all rows including headers.

            csv.push([]); // Blank line
            csv.push([]); // Blank line

            // 3. Add Detailed Breakdown
            csv.push('"EMPLOYEE COMPENSATION BREAKDOWN"');
            var detailRows = document.querySelectorAll("table#reportTable tr");
            for (var i = 0; i < detailRows.length; i++) {
                var row = [], cols = detailRows[i].querySelectorAll("td, th");
                for (var j = 0; j < cols.length; j++) 
                    row.push(cleanData(cols[j].innerText));
                csv.push(row.join(","));        
            }
            // Capture footer of detail table
            var detailFoot = document.querySelectorAll("table#reportTable tfoot tr");
            for (var i = 0; i < detailFoot.length; i++) {
                var row = [], cols = detailFoot[i].querySelectorAll("td, th");
                for (var j = 0; j < cols.length; j++) 
                    row.push(cleanData(cols[j].innerText));
                csv.push(row.join(","));  
            }

            downloadCSV(csv.join("\n"), filename);
        }

        function downloadCSV(csv, filename) {
            var csvFile;
            var downloadLink;

            // Add UTF-8 BOM to ensure Excel renders characters correctly
            csvFile = new Blob(["\uFEFF" + csv], {type: "text/csv;charset=utf-8;"});
            
            downloadLink = document.createElement("a");
            downloadLink.download = filename;
            downloadLink.href = window.URL.createObjectURL(csvFile);
            downloadLink.style.display = "none";
            document.body.appendChild(downloadLink);
            downloadLink.click();
        }
    </script>
</body>
</html>
