<?php
// payroll/payroll-reporting.php - Payroll Reporting Central Hub
session_start();

// Include database configuration
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// Check database connection
if (!isset($pdo)) {
    die("Database connection failed. Please checks config/db.php");
}

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

$currentTheme = $_SESSION['theme'];

// ============ DATA FETCHING LOGIC ============
$tab = $_GET['tab'] ?? 'disbursement';

// 1. Payroll Data (Disbursement)
// Fetch Pay Periods
$pay_periods = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT pay_period_start, pay_period_end FROM payroll_records ORDER BY pay_period_start DESC");
    $pay_periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* Ignore */ }

$selected_period = $_GET['period'] ?? ($pay_periods[0]['pay_period_start'] ?? '');

$report_data = [];
$total_net = 0;
$total_deductions = 0;
// Detailed statutory totals for the period
$total_sss = 0;
$total_philhealth = 0;
$total_pagibig = 0;
$total_tax = 0;

if ($selected_period) {
    try {
         $sql = "SELECT pr.*, e.department, e.job_title
                FROM payroll_records pr
                LEFT JOIN employees e ON pr.employee_id = e.id
                WHERE pr.pay_period_start = ?
                ORDER BY pr.employee_name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$selected_period]);
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach($report_data as $row) {
            $total_net += $row['net_pay'];
            $total_deductions += $row['total_deductions'];
            $total_sss += $row['sss_deduction'];
            $total_philhealth += $row['philhealth_deduction'];
            $total_pagibig += $row['pagibig_deduction'];
            $total_tax += $row['tax_deduction'];
        }
    } catch (PDOException $e) { }
}

// 2. Leave Data (Reporting)
$leave_data = [];
$leave_stats = ['total' => 0, 'approved' => 0, 'pending' => 0];
try {
    $sql_leave = "SELECT lr.*, e.name as employee_name, e.department 
                  FROM leave_requests lr
                  LEFT JOIN employees e ON lr.employee_id = e.id
                  ORDER BY lr.created_at DESC";
    $leave_data = $pdo->query($sql_leave)->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($leave_data as $l) {
        $leave_stats['total']++;
        if($l['status'] == 'Approved') $leave_stats['approved']++;
        if($l['status'] == 'Pending') $leave_stats['pending']++;
    }
} catch (PDOException $e) { /* Ignore */ }

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Reporting Hub | HR System</title>
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
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            transition: background 0.3s, color 0.3s;
            line-height: 1.4;
        }

        body.dark-mode { background-color: var(--dark-bg); color: var(--text-light); }

        /* Print Styling */
        @media print {
            @page {
                margin: 0;
                size: auto;
            }
            body {
                background: white !important;
                color: black !important;
                margin: 1cm !important;
            }
            /* Hide UI Elements */
            .sidebar, .dashboard-header, .theme-toggle-container, .form-container, .btn, .filter-container, .page-header, .page-title, .page-subtitle, .tab-nav {
                display: none !important;
            }
            
            .content-area, .stat-card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                background: white !important;
            }
            
             /* Report Header */
            .report-header-print {
                display: block !important;
                text-align: center;
                margin-bottom: 2rem;
                padding-bottom: 1rem;
                border-bottom: 2px solid #000;
            }
            .company-name {
                font-size: 24px;
                font-weight: bold;
                text-transform: uppercase;
                margin-bottom: 5px;
            }
            .report-title { font-size: 18px; margin-bottom: 5px; }
            .report-period { font-size: 14px; color: #666; }
            
            /* Ensure proper widths for print */
             .data-table, .stat-grid { width: 100% !important; }
             
             .main-content { margin: 0 !important; width: 100% !important; padding: 0 !important; }
        }
        
        .report-header-print { display: none; }

        /* Theme Toggle */
        .theme-toggle-container { position: fixed; top: 20px; right: 20px; z-index: 1000; }
        .theme-toggle-btn {
            background: #f8f9fc; border: 1px solid #e3e6f0; border-radius: 20px;
            padding: 10px 15px; cursor: pointer; display: flex; align-items: center;
            gap: 8px; font-size: 0.9rem; transition: all 0.3s; text-decoration: none;
            color: inherit; box-shadow: var(--shadow);
        }
        body.dark-mode .theme-toggle-btn { background: #2d3748; border-color: #4a5568; color: white; }
        .theme-toggle-btn:hover { background: #e9ecef; transform: translateY(-1px); }
        body.dark-mode .theme-toggle-btn:hover { background: #4a5568; }

        /* Main Content */
        .main-content {
            padding: 2rem; min-height: 100vh; background-color: var(--secondary-color);
            margin-top: 60px;
        }
        body.dark-mode .main-content { background-color: var(--dark-bg); }

        .content-area {
            background: white; border-radius: var(--border-radius);
            box-shadow: var(--shadow); overflow: hidden; margin-bottom: 1.5rem;
        }
        body.dark-mode .content-area { background: var(--dark-card); }

        /* Header */
        .page-header {
            padding: 1.5rem; border-bottom: 1px solid #e3e6f0; background: #f8f9fc;
        }
        body.dark-mode .page-header { background: #2d3748; border-bottom: 1px solid #4a5568; }

        .page-title {
            font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem;
            display: flex; align-items: center; gap: 1rem;
        }
        .page-subtitle { color: #6c757d; font-size: 0.9rem; }
        body.dark-mode .page-subtitle { color: #a0aec0; }
        
        /* Tab Navigation */
        .tab-nav {
            display: flex; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid #e3e6f0;
            padding-bottom: 1rem;
        }
        .tab-link {
            padding: 0.5rem 1rem; border-radius: var(--border-radius); text-decoration: none;
            color: var(--text-dark); font-weight: 600; transition: all 0.3s;
            background: white; border: 1px solid #e3e6f0;
        }
        body.dark-mode .tab-link { background: var(--dark-card); color: var(--text-light); border-color: #4a5568; }
        
        .tab-link.active {
            background: var(--primary-color); color: white; border-color: var(--primary-color);
        }

        /* Stats & Cards */
        .stat-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem; margin-bottom: 1.5rem;
        }
        .stat-card {
            background: #f8f9fc; padding: 1rem; border-radius: var(--border-radius);
            border-left: 4px solid var(--primary-color);
        }
        body.dark-mode .stat-card { background: #2d3748; }
        .stat-card.danger { border-left-color: var(--danger-color); }
        .stat-card.success { border-left-color: var(--success-color); }
        
        .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--primary-color); }
        .stat-card.danger .stat-value { color: var(--danger-color); }
        .stat-card.success .stat-value { color: var(--success-color); }
        .stat-label { font-size: 0.8rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
        body.dark-mode .stat-label { color: #a0aec0; }

        /* Tables */
        .data-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .data-table th {
            background: #f8f9fc; padding: 0.75rem; text-align: center;
            font-weight: 600; border-bottom: 1px solid #e3e6f0;
        }
        body.dark-mode .data-table th { background: #2d3748; border-bottom: 1px solid #4a5568; }
        .data-table td {
            padding: 0.75rem; border-bottom: 1px solid #e3e6f0; text-align: center;
        }
        body.dark-mode .data-table td { border-bottom: 1px solid #4a5568; }

        /* Helpers */
        .btn {
            padding: 0.75rem 1.5rem; border: none; border-radius: var(--border-radius);
            cursor: pointer; font-weight: 600; transition: all 0.3s;
            display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; font-size: 0.9rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-success { background: var(--success-color); color: white; }
        
        .form-select {
            padding: 0.75rem; border: 1px solid #e3e6f0;
            border-radius: var(--border-radius); font-size: 1rem; min-width: 250px;
        }
        body.dark-mode .form-select { background: #2d3748; border-color: #4a5568; color: white; }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <!-- Theme Toggle -->
    <div class="theme-toggle-container">
        <a href="?toggle_theme=1<?php echo isset($_GET['period']) ? '&period=' . urlencode($_GET['period']) : ''; ?>" class="theme-toggle-btn">
            <?php if ($currentTheme === 'light'): ?>
                <i class="fas fa-moon"></i> Dark Mode
            <?php else: ?>
                <i class="fas fa-sun"></i> Light Mode
            <?php endif; ?>
        </a>
    </div>

    <div class="main-content">
        
        <!-- Print Header -->
        <div class="report-header-print">
            <div class="company-name">SLATE FREIGHT</div>
            <div class="report-title">
                <?php 
                    if($tab == 'disbursement') echo 'Payroll Disbursement Report';
                    elseif($tab == 'leave') echo 'Leave Usage Summary';
                    elseif($tab == 'statutory') echo 'Statutory Contributions Report';
                ?>
            </div>
            <div class="report-period">
                Period: <?php echo $selected_period ? date('F d, Y', strtotime($selected_period)) : 'Current'; ?> 
            </div>
        </div>

        <!-- Page Header -->
        <div class="content-area">
            <div class="page-header">
                <h1 class="page-title">
                    <i class="fas fa-chart-line"></i> Payroll & HR Analytics
                </h1>
                <p class="page-subtitle">Centralized reporting for payroll, statutory deductions, and employee leave.</p>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <div class="tab-nav">
            <a href="?tab=disbursement" class="tab-link <?php echo $tab === 'disbursement' ? 'active' : ''; ?>">
                <i class="fas fa-file-invoice-dollar"></i> Disbursement
            </a>
            <a href="?tab=statutory" class="tab-link <?php echo $tab === 'statutory' ? 'active' : ''; ?>">
                <i class="fas fa-university"></i> Tax & Statutory
            </a>
            <a href="?tab=leave" class="tab-link <?php echo $tab === 'leave' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-alt"></i> Leave Reports
            </a>
        </div>

        <?php if ($tab === 'disbursement'): ?>
            <!-- Filter -->
            <div class="content-area" style="padding:1.5rem; display:flex; justify-content:space-between; align-items:center;">
                <form method="GET" style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="tab" value="disbursement">
                    <label style="font-weight:600;">Period:</label>
                    <select name="period" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Select Period --</option>
                        <?php foreach($pay_periods as $p): ?>
                            <option value="<?php echo $p['pay_period_start']; ?>" <?php echo ($selected_period == $p['pay_period_start']) ? 'selected' : ''; ?>>
                                <?php echo date('M d, Y', strtotime($p['pay_period_start'])) . ' - ' . date('M d, Y', strtotime($p['pay_period_end'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                
                 <?php if($selected_period): ?>
                <div class="filter-actions">
                     <button type="button" class="btn btn-success" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if($selected_period && !empty($report_data)): ?>
            <!-- Disbursement Stats -->
             <div class="content-area" style="padding:1.5rem; background:transparent; box-shadow:none;">
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Net Payable</div>
                        <div class="stat-value">₱<?php echo number_format($total_net, 2); ?></div>
                    </div>
                    <div class="stat-card danger">
                        <div class="stat-label">Total Deductions</div>
                        <div class="stat-value">₱<?php echo number_format($total_deductions, 2); ?></div>
                    </div>
                    <div class="stat-card success">
                        <div class="stat-label">Employees Processed</div>
                        <div class="stat-value"><?php echo count($report_data); ?></div>
                    </div>
                </div>
            </div>

            <!-- TABLE -->
            <div class="content-area" style="padding:1.5rem;">
                <h3 style="margin-bottom:1rem;">Payroll Register</h3>
                <table class="data-table">
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
                        <?php foreach($report_data as $row): ?>
                        <tr>
                            <td style="font-weight:600; text-align:left;"><?php echo htmlspecialchars($row['employee_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['department']); ?></td>
                            <td>₱<?php echo number_format($row['gross_pay'], 2); ?></td>
                            <td style="color:var(--danger-color);">-₱<?php echo number_format($row['total_deductions'], 2); ?></td>
                            <td style="color:var(--success-color); font-weight:bold;">₱<?php echo number_format($row['net_pay'], 2); ?></td>
                            <td><?php echo htmlspecialchars($row['status']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="content-area" style="padding:3rem; text-align:center;">
                     <p>Select a payroll period to view info.</p>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'statutory'): ?>
             <!-- Statutory Stats -->
            <?php if($selected_period && !empty($report_data)): ?>
             <div class="content-area" style="padding:1.5rem;">
                 <h3 style="margin-bottom:1.5rem;">Deductions Summary for <?php echo date('M d', strtotime($selected_period)); ?></h3>
                 <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-label">SSS Total</div>
                        <div class="stat-value">₱<?php echo number_format($total_sss, 2); ?></div>
                    </div>
                    <div class="stat-card info" style="border-left-color: var(--info-color);">
                        <div class="stat-label">PhilHealth Total</div>
                         <div class="stat-value" style="color:var(--info-color);">₱<?php echo number_format($total_philhealth, 2); ?></div>
                    </div>
                    <div class="stat-card warning" style="border-left-color: var(--warning-color);">
                        <div class="stat-label">Pag-IBIG Total</div>
                         <div class="stat-value" style="color:var(--warning-color);">₱<?php echo number_format($total_pagibig, 2); ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #666;">
                        <div class="stat-label">Withholding Tax</div>
                         <div class="stat-value" style="color:#666;">₱<?php echo number_format($total_tax, 2); ?></div>
                    </div>
                </div>
                
                 <div class="filter-actions" style="margin-top:1rem; text-align:right;">
                     <button type="button" class="btn btn-success" onclick="window.print()"><i class="fas fa-print"></i> Print Summary</button>
                </div>
             </div>
             <?php else: ?>
                <div class="content-area" style="padding:3rem; text-align:center;">
                     <p>Please go to Disbursement tab and select a period essentially to see statutory breakdowns.</p>
                     <a href="?tab=disbursement" class="btn btn-primary">Select Period</a>
                </div>
             <?php endif; ?>

        <?php elseif ($tab === 'leave'): ?>
            <div class="content-area" style="padding:1.5rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                    <h3>All Leave Requests</h3>
                    <button class="btn btn-success" onclick="window.print()"><i class="fas fa-print"></i> Print Log</button>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Type</th>
                            <th>Dates</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($leave_data as $l): ?>
                        <tr>
                            <td style="font-weight:600; text-align:left;"><?php echo htmlspecialchars($l['employee_name']); ?></td>
                            <td><?php echo htmlspecialchars($l['department']); ?></td>
                            <td><?php echo htmlspecialchars($l['leave_type']); ?></td>
                            <td><?php echo date('M d', strtotime($l['start_date'])) .' - '. date('d, Y', strtotime($l['end_date'])); ?></td>
                             <td><?php echo htmlspecialchars($l['status']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($leave_data)): ?>
                            <tr><td colspan="5">No leave records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>
