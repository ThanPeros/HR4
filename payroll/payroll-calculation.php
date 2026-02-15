<?php
// payroll-calculation.php - Payroll Processing & Calculation
session_start();

require_once '../includes/sidebar.php';
// Database connection
if (!file_exists('../config/db.php')) {
    die('Error: Database configuration file not found!');
}
require_once '../config/db.php';

// Alternative DB connection if not set
if (!isset($pdo)) {
    try {
        $host = 'localhost';
        $dbname = 'dummyhr4';
        $username = 'root';
        $password = '';
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        // die("DB Connection failed: " . $e->getMessage());
    }
}

// -------------------------------------------------------------------------
// SCHEMA SETUP
// -------------------------------------------------------------------------
function checkAndCreateTables($pdo) {
    if (!$pdo) return;

    // T&A Batches (Imported from HR3)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ta_batches (
        id VARCHAR(50) PRIMARY KEY,
        name VARCHAR(100),
        start_date DATE,
        end_date DATE,
        total_logs INT,
        status ENUM('Pending Review', 'Verified', 'Processed') DEFAULT 'Pending Review',
        imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Payroll Periods (Acts as the 'Bundle' Container)
    // Enhanced with bundle info and expanded status
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        period_code VARCHAR(50) UNIQUE,
        name VARCHAR(100),
        start_date DATE,
        end_date DATE,
        pay_frequency VARCHAR(50) DEFAULT 'Monthly',
        
        -- Bundle Info
        bundle_type VARCHAR(50) DEFAULT 'ALL', -- ALL, DEPT, LOCATION
        bundle_filter VARCHAR(100) DEFAULT NULL, -- Specific Dept Name etc.
        ta_batch_id VARCHAR(50), 
        
        -- Flow Status
        status ENUM('Draft', 'Calculated', 'Budgeted', 'Pending Approval', 'Approved', 'Rejected', 'Released') DEFAULT 'Draft',
        
        total_employees INT DEFAULT 0,
        total_gross DECIMAL(15,2) DEFAULT 0.00,
        total_deductions DECIMAL(15,2) DEFAULT 0.00,
        total_net DECIMAL(15,2) DEFAULT 0.00,
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Payroll Records
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payroll_period_id INT,
        employee_id INT,
        employee_name VARCHAR(100),
        department VARCHAR(50),
        pay_period_start DATE,
        pay_period_end DATE,
        
        -- Earnings
        basic_salary DECIMAL(12,2) DEFAULT 0,
        overtime_pay DECIMAL(10,2) DEFAULT 0,
        holiday_pay DECIMAL(10,2) DEFAULT 0,
        night_diff DECIMAL(10,2) DEFAULT 0,
        allowances DECIMAL(10,2) DEFAULT 0,
        gross_pay DECIMAL(12,2) DEFAULT 0,
        
        -- Deductions
        deduction_sss DECIMAL(10,2) DEFAULT 0.00,
        deduction_philhealth DECIMAL(10,2) DEFAULT 0.00,
        deduction_pagibig DECIMAL(10,2) DEFAULT 0.00,
        deduction_tax DECIMAL(10,2) DEFAULT 0.00,
        deduction_hmo DECIMAL(10,2) DEFAULT 0.00,
        deduction_loans DECIMAL(10,2) DEFAULT 0.00,
        total_deductions DECIMAL(12,2) DEFAULT 0,
        
        -- Net
        net_pay DECIMAL(12,2) DEFAULT 0,
        
        status ENUM('Pending', 'Paid') DEFAULT 'Pending',
        calculation_details TEXT, -- JSON breakdown
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add columns if missing (Schema Evolution)
    try {
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deduction_hmo DECIMAL(10,2) DEFAULT 0.00 AFTER deduction_tax");
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deduction_loans DECIMAL(10,2) DEFAULT 0.00 AFTER deduction_hmo");
        $pdo->exec("ALTER TABLE payroll_periods ADD COLUMN IF NOT EXISTS bundle_type VARCHAR(50) DEFAULT 'ALL' AFTER pay_frequency");
        $pdo->exec("ALTER TABLE payroll_periods ADD COLUMN IF NOT EXISTS bundle_filter VARCHAR(100) DEFAULT NULL AFTER bundle_type");
        $pdo->exec("ALTER TABLE payroll_periods ADD COLUMN IF NOT EXISTS ta_batch_id VARCHAR(50) AFTER bundle_filter");
        $pdo->exec("ALTER TABLE payroll_periods MODIFY COLUMN status ENUM('Draft', 'Calculated', 'Budgeted', 'Pending Approval', 'Approved', 'Rejected', 'Released') DEFAULT 'Draft'");
        
        // Fix for missing total columns in existing tables
        $pdo->exec("ALTER TABLE payroll_periods ADD COLUMN IF NOT EXISTS total_gross DECIMAL(15,2) DEFAULT 0.00");
        $pdo->exec("ALTER TABLE payroll_periods ADD COLUMN IF NOT EXISTS total_deductions DECIMAL(15,2) DEFAULT 0.00");
        $pdo->exec("ALTER TABLE payroll_periods ADD COLUMN IF NOT EXISTS total_net DECIMAL(15,2) DEFAULT 0.00");
    } catch (Exception $e) {}
    
    // Payroll Budgets
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_budgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        budget_code VARCHAR(50),
        payroll_period_id INT,
        budget_name VARCHAR(255),
        date_range_start DATE,
        date_range_end DATE,
        
        total_gross_amount DECIMAL(15,2) DEFAULT 0.00,
        total_deductions_amount DECIMAL(15,2) DEFAULT 0.00,
        total_net_amount DECIMAL(15,2) DEFAULT 0.00, /* Budget = Net by default */
        total_employer_share DECIMAL(15,2) DEFAULT 0.00, /* Optional Cost */
        
        approval_status ENUM('Draft', 'Waiting for Approval', 'Approved', 'Rejected') DEFAULT 'Draft',
        submitted_at TIMESTAMP NULL,
        approved_at TIMESTAMP NULL,
        approved_by VARCHAR(100),
        approver_notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Ensure column exists for older tables
    try {
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS budget_code VARCHAR(50) AFTER id");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS date_range_start DATE AFTER budget_name");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS date_range_end DATE AFTER date_range_start");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS total_gross_amount DECIMAL(15,2) DEFAULT 0.00 AFTER date_range_end");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS total_deductions_amount DECIMAL(15,2) DEFAULT 0.00 AFTER total_gross_amount");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS total_net_amount DECIMAL(15,2) DEFAULT 0.00 AFTER total_deductions_amount");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS total_budget_amount DECIMAL(15,2) DEFAULT 0.00 AFTER total_net_amount");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS submitted_at TIMESTAMP NULL AFTER approval_status");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL AFTER submitted_at");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS approved_by VARCHAR(100) AFTER approved_at");
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS approver_notes TEXT AFTER approved_by");
    } catch (Exception $e) {}

    // Seed Initial Data if empty
    $checkTA = $pdo->query("SELECT COUNT(*) FROM ta_batches")->fetchColumn();
    if ($checkTA == 0) {
        $pdo->exec("INSERT INTO ta_batches (id, name, start_date, end_date, total_logs, status) VALUES 
            ('TA-2026-01-A', 'Period: Jan 1 - Jan 15, 2026', '2026-01-01', '2026-01-15', 1450, 'Verified'),
            ('TA-2026-01-B', 'Period: Jan 16 - Jan 31, 2026', '2026-01-16', '2026-01-31', 1520, 'Pending Review')
        ");
    }
}
checkAndCreateTables($pdo);

// -------------------------------------------------------------------------
// LOGIC / CONTROLLERS
// -------------------------------------------------------------------------

// Helper: Get Active Employees based on Bundle
function getEmployeesForBundle($type, $filter) {
    global $pdo;
    $sql = "SELECT id, name, department, salary FROM employees WHERE status = 'Active'";
    $params = [];
    
    if ($type === 'DEPT' && $filter) {
        $sql .= " AND department = ?";
        $params[] = $filter;
    }
    // Add other filters as needed
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ACTION HANDLERS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- STEP A: IMPORT ATTENDANCE LOGS (Simulated API) ---
    if (isset($_POST['import_hr3'])) {
        // Automatically determine the next available period (Simulated)
        // In a real scenario, this would call the API to see what's ready
        
        $start = '2026-02-01';
        $end = '2026-02-15';
        $batchId = 'TA-2026-02-A';
        $name = "Period: Feb 1 - Feb 15, 2026";
        
        // Check if already exists
        $check = $pdo->prepare("SELECT id FROM ta_batches WHERE id = ?");
        $check->execute([$batchId]);
        
        if ($check->rowCount() > 0) {
            $_SESSION['error_message'] = "No new time & attendance records found (Feb 1-15 is already imported).";
        } else {
            $pdo->prepare("INSERT INTO ta_batches (id, name, start_date, end_date, total_logs, status) VALUES (?, ?, ?, ?, ?, 'Verified')")
                ->execute([$batchId, $name, $start, $end, rand(1000, 2000)]);
            $_SESSION['success_message'] = "STEP A: Successfully imported available attendance logs (Feb 1 - Feb 15, 2026).";
        }
        header("Location: payroll-calculation.php"); exit;
    }

    // --- STEP B: BUNDLE EMPLOYEES ---
    if (isset($_POST['create_bundle'])) {
        $ta_id = $_POST['ta_batch_id'];
        $bundle_name = $_POST['bundle_name'];
        $filter_type = $_POST['filter_type']; // ALL, DEPT
        $filter_val = $_POST['filter_value'] ?? null;
        
        // Get dates from TA Batch
        $ta = $pdo->prepare("SELECT * FROM ta_batches WHERE id = ?");
        $ta->execute([$ta_id]);
        $batch = $ta->fetch();
        
        if ($batch) {
            $periodCode = 'PB-' . date('Ymd', strtotime($batch['start_date'])) . '-' . rand(100, 999);
            
            $stmt = $pdo->prepare("INSERT INTO payroll_periods 
                (period_code, name, start_date, end_date, bundle_type, bundle_filter, ta_batch_id, status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Draft', ?)");
                
            $stmt->execute([
                $periodCode, $bundle_name, $batch['start_date'], $batch['end_date'], 
                $filter_type, $filter_val, $ta_id, $_SESSION['user'] ?? 'System'
            ]);
            
            $newId = $pdo->lastInsertId();
            
            // Mark batch as processed
            $pdo->prepare("UPDATE ta_batches SET status = 'Processed' WHERE id = ?")->execute([$ta_id]);
            
            $_SESSION['success_message'] = "STEP B: Bundle Created. You can now proceed to calculation.";
            header("Location: payroll-calculation.php?view_id=$newId"); exit;
        }
    }

    // --- STEP C: AUTO PAYROLL CALCULATION ---
    if (isset($_POST['run_calculation'])) {
        $periodId = $_POST['period_id'];
        
        // Get Bundle Info
        $pStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
        $pStmt->execute([$periodId]);
        $period = $pStmt->fetch();
        
        if ($period) {
            // Get Employees in Scope
            $employees = getEmployeesForBundle($period['bundle_type'], $period['bundle_filter']);
            
            $totalGross = 0;
            $totalDed = 0;
            $totalNet = 0;
            $count = 0;
            
            // Clear existing records if re-running
            $pdo->prepare("DELETE FROM payroll_records WHERE payroll_period_id = ?")->execute([$periodId]);
            
            foreach ($employees as $emp) {
                // COMPUTATION LOGIC
                $monthlyRate = $emp['salary'];
                
                // Initialize Calculation Variables
                $otHours = rand(0, 5); 
                $loanDeduction = 0;
                $skipSSS = false;
                
                // --- SCENARIO INJECTION (CHAOS MONKEY) ---
                // We intentionally break data for the first 4 employees to demo the AI Audit
                if ($count === 0) {
                     // Scenario 1: Extreme Overtime (Flag: OT too high)
                     $otHours = 60; 
                } else if ($count === 1) {
                     // Scenario 2: Negative Net Pay due to huge loan deduction
                     $loanDeduction = $monthlyRate; 
                } else if ($count === 2) {
                     // Scenario 3: Salary Grade Mismatch (Calculated on wrong rate vs DB)
                     // We lower the rate used for calc, but DB still has original.
                     $monthlyRate = $monthlyRate * 0.8; 
                } else if ($count === 3) {
                     // Scenario 4: Missing SSS Deduction
                     $skipSSS = true;
                }

                // 1. Earnings
                $basic = $monthlyRate / 2;
                $hourlyRate = ($monthlyRate / 22) / 8;
                
                $otPay = $otHours * $hourlyRate * 1.25;
                $allowance = 1000;
                
                $gross = $basic + $otPay + $allowance;
                
                // 2. Deductions
                $sss = ($skipSSS) ? 0 : (($monthlyRate * 0.045) / 2);
                $ph = ($monthlyRate * 0.025) / 2;
                $pagibig = 100;
                $tax = ($gross - ($sss + $ph + $pagibig)) * 0.10; 
                $hmo = 250; 
                
                $subTotalDed = $sss + $ph + $pagibig + $tax + $hmo + $loanDeduction;
                
                // 3. Net Pay
                $net = $gross - $subTotalDed;
                
                // Save JSON Breakdown
                $breakdown = [
                    'basic' => $basic, 'ot' => $otPay, 'allowance' => $allowance,
                    'sss' => $sss, 'philhealth' => $ph, 'pagibig' => $pagibig,
                    'tax' => $tax, 'hmo' => $hmo, 'loans' => $loanDeduction
                ];
                
                // Save Record (Added deduction_loans to query)
                $ins = $pdo->prepare("INSERT INTO payroll_records 
                    (payroll_period_id, employee_id, employee_name, department, 
                     pay_period_start, pay_period_end, 
                     basic_salary, overtime_pay, allowances, gross_pay,
                     deduction_sss, deduction_philhealth, deduction_pagibig, deduction_tax, deduction_hmo, deduction_loans,
                     total_deductions, net_pay, calculation_details)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                $ins->execute([
                    $periodId, $emp['id'], $emp['name'], $emp['department'],
                    $period['start_date'], $period['end_date'],
                    $basic, $otPay, $allowance, $gross,
                    $sss, $ph, $pagibig, $tax, $hmo, $loanDeduction,
                    $subTotalDed, $net, json_encode($breakdown)
                ]);
                
                $totalGross += $gross;
                $totalDed += $subTotalDed;
                $totalNet += $net;
                $count++;
            }
            
            // Update Period Status
            $pdo->prepare("UPDATE payroll_periods SET status = 'Calculated', total_employees = ?, total_gross = ?, total_deductions = ?, total_net = ? WHERE id = ?")
                ->execute([$count, $totalGross, $totalDed, $totalNet, $periodId]);
                
            $_SESSION['success_message'] = "STEP C: Calculations Complete for $count employees.";
            header("Location: payroll-calculation.php?view_id=$periodId"); exit;
        }
    }

    // --- STEP D: AUTO CREATE PAYROLL BUDGET ---
    if (isset($_POST['create_budget'])) {
        $periodId = $_POST['period_id'];
        
        $pStmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
        $pStmt->execute([$periodId]);
        $period = $pStmt->fetch();
        
        if ($period) {
            $budgetName = $_POST['budget_name'] ?? ("Payroll Budget - " . $period['name']);
            $budgetCode = str_replace("PB-", "BUD-", $period['period_code']); // PB-2026... -> BUD-2026...
            
            // Generate Budget Record
            $ins = $pdo->prepare("INSERT INTO payroll_budgets 
                (budget_code, payroll_period_id, budget_name, date_range_start, date_range_end, 
                 total_gross_amount, total_deductions_amount, total_net_amount, approval_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Draft')");
                
            $ins->execute([
                $budgetCode, $periodId, $budgetName, $period['start_date'], $period['end_date'],
                $period['total_gross'], $period['total_deductions'], $period['total_net']
            ]);
            
            // Update Period Status
            $pdo->prepare("UPDATE payroll_periods SET status = 'Budgeted' WHERE id = ?")->execute([$periodId]);
            
            $_SESSION['success_message'] = "STEP D: Payroll Budget Created ($budgetName).";
            header("Location: payroll-calculation.php?view_id=$periodId"); exit;
        }
    }

    // --- STEP E: SUBMIT TO FINANCE ---
    if (isset($_POST['submit_approval'])) {
        $periodId = $_POST['period_id'];
        
        $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Waiting for Approval', submitted_at = NOW() WHERE payroll_period_id = ?")->execute([$periodId]);
        $pdo->prepare("UPDATE payroll_periods SET status = 'Pending Approval' WHERE id = ?")->execute([$periodId]);
         
        $_SESSION['success_message'] = "STEP E: Submitted to Finance for Approval.";
        header("Location: payroll-calculation.php?view_id=$periodId"); exit;
    }

    // --- STEP F: APPROVE BUDGET ---
    if (isset($_POST['approve_budget'])) {
        $periodId = $_POST['period_id'];
        
        $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Approved', approved_at = NOW(), approved_by = ? WHERE payroll_period_id = ?")->execute([$_SESSION['user'] ?? 'Finance', $periodId]);
        
        // Lock Period
        $pdo->prepare("UPDATE payroll_periods SET status = 'Approved' WHERE id = ?")->execute([$periodId]);
        
        $_SESSION['success_message'] = "STEP F: Budget Approved! Payroll is now locked and ready for release.";
        header("Location: payroll-calculation.php?view_id=$periodId"); exit;
    }
    // --- CLEANUP TOOL (USER REQUESTED) ---
    if (isset($_POST['cleanup_data'])) {
        // Delete Calculated + Empty Status + Duplicates
        // First clean child records
        $pdo->exec("DELETE FROM payroll_records WHERE payroll_period_id IN (SELECT id FROM payroll_periods WHERE status = 'Calculated' OR status IS NULL OR status = '')");
        $pdo->exec("DELETE FROM payroll_budgets WHERE payroll_period_id IN (SELECT id FROM payroll_periods WHERE status = 'Calculated' OR status IS NULL OR status = '')");
        // Then delete parent
        $pdo->exec("DELETE FROM payroll_periods WHERE status = 'Calculated' OR status IS NULL OR status = ''");
        
        // Remove Duplicates (Keep Max ID)
        $pdo->exec("DELETE t1 FROM payroll_periods t1
                    INNER JOIN payroll_periods t2 
                    WHERE t1.id < t2.id 
                    AND t1.name = t2.name 
                    AND t1.start_date = t2.start_date 
                    AND t1.end_date = t2.end_date");
                    
        $_SESSION['success_message'] = "Test Data Cleaned (Removed Calculated, Empty, Duplicates).";
        header("Location: payroll-calculation.php"); exit;
    }
}

// -------------------------------------------------------------------------
// DATA FETCHING
// -------------------------------------------------------------------------
$viewId = $_GET['view_id'] ?? null;
$viewPeriod = null;
$viewRecords = [];
$viewBudget = null;

if ($viewId) {
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$viewId]);
    $viewPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($viewPeriod) {
        $stmtRec = $pdo->prepare("SELECT * FROM payroll_records WHERE payroll_period_id = ?");
        $stmtRec->execute([$viewId]);
        $viewRecords = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtBud = $pdo->prepare("SELECT * FROM payroll_budgets WHERE payroll_period_id = ?");
        $stmtBud->execute([$viewId]);
        $viewBudget = $stmtBud->fetch(PDO::FETCH_ASSOC);
    }
}

$taBatches = $pdo->query("SELECT * FROM ta_batches WHERE status != 'Processed' AND id NOT IN (SELECT ta_batch_id FROM payroll_periods WHERE ta_batch_id IS NOT NULL) ORDER BY start_date ASC")->fetchAll(PDO::FETCH_ASSOC);
$allPeriods = $pdo->query("SELECT * FROM payroll_periods ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$currentUserRole = $_SESSION['role'] ?? 'admin';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Calculation | HR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4e73df; --secondary: #f8f9fc; --success: #1cc88a; --info: #36b9cc; --warning: #f6c23e; --danger: #e74a3b; }
        body { background-color: #f3f4f6; font-family: 'Segoe UI', system-ui, sans-serif; }
        .main-content { padding: 2rem; margin-top: 60px; min-height: 100vh; }
        .card-custom { border: none; border-radius: 0.5rem; box-shadow: 0 0.15rem 1.75rem rgba(0,0,0,0.1); background: white; margin-bottom: 1.5rem; }
        .card-header-custom { background: white; border-bottom: 1px solid #e3e6f0; padding: 1.25rem; font-weight: 600; color: var(--primary); display: flex; justify-content: space-between; align-items: center; }
        .badge-status { padding: 0.5em 1em; border-radius: 50rem; font-weight: 600; font-size: 0.75rem; }
        .step-indicator { display: flex; align-items: center; margin-bottom: 1rem; color: #858796; }
        .step-indicator .step { display: flex; align-items: center; gap: 0.5rem; opacity: 0.5; }
        .step-indicator .step.active { opacity: 1; color: var(--primary); font-weight: bold; }
        .step-indicator .divider { height: 2px; width: 30px; background: #e3e6f0; margin: 0 10px; }
    </style>
</head>
<body>

<div class="main-content">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-brain text-primary me-2"></i> Payroll Powered by AI</h1>
            <p class="text-muted small">Manage Attendance Imports, Bundles, Calculations, and Budgets</p>
        </div>
        <div class="d-flex gap-2">
            <form method="POST" onsubmit="return confirm('WARNING: This will delete ALL Calculated, Empty Status, and Duplicate periods. Continue?');">
                <button type="submit" name="cleanup_data" class="btn btn-outline-danger shadow-sm">
                    <i class="fas fa-trash-alt me-1"></i> Cleanup Test Data
                </button>
            </form>
            <?php if($viewId): ?>
                <a href="payroll-calculation.php" class="btn btn-secondary shadow-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if(isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if(isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$viewId): ?>
        <!-- ==================== DASHBOARD VIEW ==================== -->
        
        <div class="row">
            <!-- STEP A: IMPORT -->
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <span><i class="fas fa-cloud-download-alt"></i> Step A: Import Attendance Logs</span>
                    </div>
                    <div class="p-4">
                        <p class="small text-muted mb-3">One-click import from HR3 Time & Attendance API. Fetches available finalized logs.</p>
                        <form method="POST">
                            <button type="submit" name="import_hr3" class="btn btn-success w-100 py-3">
                                <i class="fas fa-sync fa-lg me-2"></i> Import Newest T&A Batch
                            </button>
                            <div class="mt-2 text-center">
                                <small class="text-muted opacity-75">Simulated API: Checks for Feb 2026 logs</small>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- STEP B: BUNDLE -->
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <span><i class="fas fa-box-open"></i> Step B: Create Payroll Bundle</span>
                    </div>
                    <div class="p-4">
                        <p class="small text-muted mb-3">Select a verified T&A batch to create a payroll scope.</p>
                        <?php if (empty($taBatches)): ?>
                            <div class="alert alert-light text-center">No pending verified batches. Import one first.</div>
                        <?php else: ?>
                            <div class="list-group">
                                <?php foreach($taBatches as $batch): ?>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo $batch['name']; ?></strong><br>
                                            <small class="text-muted"><?php echo $batch['total_logs']; ?> Logs | <?php echo $batch['status']; ?></small>
                                        </div>
                                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#bundleModal<?php echo $val_id = preg_replace('/[^A-Za-z0-9]/', '', $batch['id']); ?>">
                                            Bundle <i class="fas fa-arrow-right"></i>
                                        </button>
                                    </div>

                                    <!-- Bundle Modal -->
                                    <div class="modal fade" id="bundleModal<?php echo $val_id; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="POST">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Create Bundle Scope</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="ta_batch_id" value="<?php echo $batch['id']; ?>">
                                                        <div class="mb-3">
                                                            <label class="form-label">Bundle Name</label>
                                                            <input type="text" name="bundle_name" class="form-control" value="Payroll Bundle - <?php echo $batch['start_date']; ?> - ALL" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Scope / Filter</label>
                                                            <select name="filter_type" class="form-select" required>
                                                                <option value="ALL">ALL Employees</option>
                                                                <option value="DEPT">By Department</option>
                                                                <option value="LOC">By Location</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label">Filter Value (Optional)</label>
                                                            <input type="text" name="filter_value" class="form-control" placeholder="e.g. IT Department">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" name="create_bundle" class="btn btn-primary">Create Bundle & Proceed</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- RECENT PERIODS -->
        <h5 class="text-gray-800 mb-3">Active Payroll Runs</h5>
        <div class="card-custom">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>Bundle Name</th>
                        <th>Period</th>
                        <th>Scope</th>
                        <th>Status</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($allPeriods as $p): ?>
                        <tr>
                            <td><?php echo $p['name']; ?></td>
                            <td><?php echo $p['start_date'] . ' / ' . $p['end_date']; ?></td>
                            <td><span class="badge bg-secondary"><?php echo $p['bundle_type']; ?></span></td>
                            <td>
                                <span class="badge badge-status 
                                    <?php echo $p['status'] == 'Approved' ? 'bg-success' : 
                                        ($p['status'] == 'Pending Approval' ? 'bg-warning text-dark' : 
                                        ($p['status'] == 'Budgeted' ? 'bg-info' : 'bg-secondary')); ?>">
                                    <?php echo $p['status']; ?>
                                </span>
                            </td>
                            <td>₱<?php echo number_format($p['total_net'] ?? 0, 2); ?></td>
                            <td><a href="?view_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-info text-white">Manage</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        </div>

    <?php else: ?>
        <!-- ==================== PERIOD DETAIL VIEW ==================== -->
        
        <!-- Workflow Progress -->
        <div class="card-custom p-3">
            <div class="step-indicator justify-content-center">
                <div class="step <?php echo $viewPeriod['status'] != 'Draft' ? 'active' : 'active'; ?>">
                    <i class="fas fa-box"></i> Bundle
                </div>
                <div class="divider"></div>
                <div class="step <?php echo in_array($viewPeriod['status'], ['Calculated', 'Budgeted', 'Pending Approval', 'Approved']) ? 'active' : ''; ?>">
                    <i class="fas fa-calculator"></i> Calculate
                </div>
                <div class="divider"></div>
                <div class="step <?php echo in_array($viewPeriod['status'], ['Budgeted', 'Pending Approval', 'Approved']) ? 'active' : ''; ?>">
                    <i class="fas fa-file-invoice-dollar"></i> Budget
                </div>
                <div class="divider"></div>
                <div class="step <?php echo in_array($viewPeriod['status'], ['Pending Approval', 'Approved']) ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i> Approval
                </div>
                <div class="divider"></div>
                <div class="step <?php echo $viewPeriod['status'] == 'Approved' ? 'active' : ''; ?>">
                    <i class="fas fa-check-double"></i> Complete
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left: Info & Actions -->
            <div class="col-md-4">
                <div class="card-custom">
                    <div class="card-header-custom">Status: <?php echo $viewPeriod['status']; ?></div>
                    <div class="p-4">
                        <h4 class="mb-3"><?php echo $viewPeriod['name']; ?></h4>
                        <p><strong>Code:</strong> <?php echo $viewPeriod['period_code']; ?></p>
                        <p><strong>Scope:</strong> <?php echo $viewPeriod['bundle_type']; ?> <?php echo $viewPeriod['bundle_filter']; ?></p>
                        <hr>
                        
                        <?php if($viewPeriod['status'] == 'Draft'): ?>
                            <!-- STEP C ACTION -->
                            <div class="alert alert-info"><i class="fas fa-info-circle"></i> Bundle created. Ready to calculate.</div>
                            <form method="POST">
                                <input type="hidden" name="period_id" value="<?php echo $viewPeriod['id']; ?>">
                                <button type="submit" name="run_calculation" class="btn btn-primary w-100 py-2">
                                    <i class="fas fa-play"></i> STEP C: Run Auto-Calculation
                                </button>
                            </form>

                        <?php elseif($viewPeriod['status'] == 'Calculated'): ?>
                            <!-- STEP D ACTION -->
                            <div class="alert alert-success"><i class="fas fa-check"></i> Calculated. Review totals then create budget.</div>
                            
                            <button type="button" class="btn btn-outline-danger w-100 py-2 mb-3 shadow-sm" onclick="runAnomalyScan(<?php echo $viewPeriod['id']; ?>)">
                                <i class="fas fa-robot me-2"></i> Run AI Anomaly Scan
                            </button>

                            <form method="POST">
                                <input type="hidden" name="period_id" value="<?php echo $viewPeriod['id']; ?>">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold">Budget Name</label>
                                    <input type="text" name="budget_name" class="form-control" value="Payroll Budget - <?php echo $viewPeriod['name']; ?>" required>
                                </div>
                                <button type="submit" name="create_budget" class="btn btn-info text-white w-100 py-2">
                                    <i class="fas fa-file-invoice-dollar"></i> STEP D: Auto Create Budget
                                </button>
                            </form>

                        <?php elseif($viewPeriod['status'] == 'Budgeted'): ?>
                            <!-- STEP E ACTION -->
                            <div class="alert alert-warning"><i class="fas fa-info-circle"></i> Budget Drafted. Submit to Finance.</div>
                            <form method="POST">
                                <input type="hidden" name="period_id" value="<?php echo $viewPeriod['id']; ?>">
                                <button type="submit" name="submit_approval" class="btn btn-warning w-100 py-2">
                                    <i class="fas fa-paper-plane"></i> STEP E: Send to Finance
                                </button>
                            </form>

                        <?php elseif($viewPeriod['status'] == 'Pending Approval'): ?>
                            <!-- STEP F ACTION -->
                            <div class="alert alert-warning"><i class="fas fa-hourglass-half"></i> Waiting for Finance Approval.</div>
                            <?php if($currentUserRole == 'admin' || $currentUserRole == 'finance'): ?>
                                <hr>
                                <p class="small fw-bold">Finance Actions:</p>
                                <form method="POST">
                                    <input type="hidden" name="period_id" value="<?php echo $viewPeriod['id']; ?>">
                                    <button type="submit" name="approve_budget" class="btn btn-success w-100 mb-2">
                                        <i class="fas fa-check-double"></i> Approve Budget
                                    </button>
                                    <button class="btn btn-danger w-100" type="button">Reject</button>
                                </form>
                            <?php endif; ?>

                        <?php elseif($viewPeriod['status'] == 'Approved'): ?>
                            <div class="alert alert-success text-center">
                                <h3><i class="fas fa-check-circle"></i> Approved</h3>
                                <p>Payroll is locked and ready for release.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if($viewBudget): ?>
                <div class="card-custom">
                    <div class="card-header-custom">Budget Summary</div>
                    <div class="p-4">
                        <table class="table table-sm">
                            <tr><td>Gross Pay:</td><td class="text-end fw-bold">₱<?php echo number_format($viewBudget['total_gross_amount'], 2); ?></td></tr>
                            <tr><td>Total Deductions:</td><td class="text-end text-danger">- ₱<?php echo number_format($viewBudget['total_deductions_amount'], 2); ?></td></tr>
                            <tr class="table-primary border-top border-dark"><td><strong>Net Budget:</strong></td><td class="text-end fw-bold">₱<?php echo number_format($viewBudget['total_net_amount'], 2); ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right: Records -->
            <div class="col-md-8">
                <div class="card-custom">
                    <div class="card-header-custom">
                        Employee Payroll Lines
                        <span class="badge bg-secondary"><?php echo isset($viewRecords) ? count($viewRecords) : 0; ?> Employees</span>
                    </div>
                    <?php if(empty($viewRecords)): ?>
                        <div class="p-5 text-center text-muted">
                            <i class="fas fa-users fa-3x mb-3"></i><br>
                            Employees will appear here after calculation.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th class="text-end">Gross</th>
                                        <th class="text-end">Deductions</th>
                                        <th class="text-end">Net Pay</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($viewRecords as $rec): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo $rec['employee_name']; ?></div>
                                            <div class="small text-muted"><?php echo $rec['department']; ?></div>
                                        </td>
                                        <td class="text-end">₱<?php echo number_format($rec['gross_pay'], 2); ?></td>
                                        <td class="text-end text-danger">- ₱<?php echo number_format($rec['total_deductions'], 2); ?></td>
                                        <td class="text-end text-success fw-bold">₱<?php echo number_format($rec['net_pay'], 2); ?></td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary" onclick='viewDetails(<?php echo $rec["calculation_details"]; ?>)'>
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- JSON Breakdown Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Calculation Breakdown</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalBodyDetails"></div>
        </div>
    </div>
</div>

<!-- Anomaly Modal -->
<div class="modal fade" id="anomalyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>AI Anomaly Detection</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="anomalyLoading" class="text-center py-5">
                    <div class="spinner-border text-danger" role="status"></div>
                    <p class="mt-3 text-muted">Scanning payroll entries for irregularities...</p>
                </div>
                
                <div id="anomalyResults" style="display:none;">
                    <div class="alert alert-warning d-flex align-items-center mb-3">
                        <i class="fas fa-exclamation-circle fa-2x me-3"></i>
                        <div>
                            <strong>Potential Issues Found</strong><br>
                            AI has flagged <span id="flaggedCount" class="fw-bold">0</span> entries requiring review.
                        </div>
                    </div>
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th width="35%">Employee</th>
                                    <th>Detected Anomalies</th>
                                </tr>
                            </thead>
                            <tbody id="anomalyTableBody"></tbody>
                        </table>
                    </div>
                </div>

                <div id="anomalyClean" style="display:none;" class="text-center py-5">
                    <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
                    <h4 class="text-success">All Clear!</h4>
                    <p class="text-muted">No significant anomalies detected in this payroll run.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function viewDetails(json) {
        let html = '<table class="table table-sm table-striped">';
        for (const [key, value] of Object.entries(json)) {
            html += `<tr><td class="text-capitalize">${key.replace(/_/g, ' ')}:</td><td class="text-end fw-bold">₱${Number(value).toLocaleString(undefined, {minimumFractionDigits: 2})}</td></tr>`;
        }
        html += '</table>';
        document.getElementById('modalBodyDetails').innerHTML = html;
        new bootstrap.Modal(document.getElementById('detailsModal')).show();
    }

    function runAnomalyScan(periodId) {
        const modal = new bootstrap.Modal(document.getElementById('anomalyModal'));
        modal.show();
        
        document.getElementById('anomalyLoading').style.display = 'block';
        document.getElementById('anomalyResults').style.display = 'none';
        document.getElementById('anomalyClean').style.display = 'none';

        fetch(`../api/payroll_anomaly_scan.php?period_id=${periodId}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('anomalyLoading').style.display = 'none';
                
                if (data.success) {
                    if (data.anomalies.length > 0) {
                        document.getElementById('anomalyResults').style.display = 'block';
                        document.getElementById('flaggedCount').textContent = data.stats.flagged;
                        
                        const tbody = document.getElementById('anomalyTableBody');
                        tbody.innerHTML = '';
                        
                        data.anomalies.forEach(item => {
                            let issuesHtml = item.issues.map(i => 
                                `<div class="d-flex align-items-start mb-2"><i class="fas fa-times text-danger mt-1 me-2"></i><span>${i}</span></div>`
                            ).join('');

                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>
                                    <div class="fw-bold text-dark">${item.employee}</div>
                                    <small class="text-muted">ID: ${item.id}</small>
                                </td>
                                <td>${issuesHtml}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                    } else {
                        document.getElementById('anomalyClean').style.display = 'block';
                    }
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('anomalyLoading').style.display = 'none';
                alert('An error occurred during the scan.');
            });
    }
</script>
</body>
</html>