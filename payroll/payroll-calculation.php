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
        die("DB Connection failed: " . $e->getMessage());
    }
}

// Check/Create Tables (Schema Setup)
function checkAndCreateTables($pdo) {
    // Payroll Periods
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_periods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        period_code VARCHAR(50) UNIQUE,
        name VARCHAR(100),
        start_date DATE,
        end_date DATE,
        pay_frequency VARCHAR(50) DEFAULT 'Monthly',
        status ENUM('Draft', 'Calculated', 'Budgeted', 'Approved', 'Released') DEFAULT 'Draft',
        total_employees INT DEFAULT 0,
        total_amount DECIMAL(15,2) DEFAULT 0.00,
        created_by VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Payroll Records - Enhanced with Deduction Columns
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_records (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payroll_period_id INT,
        employee_id INT,
        employee_name VARCHAR(100),
        department VARCHAR(50),
        pay_period_start DATE,
        pay_period_end DATE,
        basic_salary DECIMAL(12,2),
        overtime_hours DECIMAL(5,2) DEFAULT 0,
        overtime_pay DECIMAL(10,2) DEFAULT 0,
        allowances DECIMAL(10,2) DEFAULT 0,
        gross_pay DECIMAL(12,2),
        deduction_sss DECIMAL(10,2) DEFAULT 0.00,
        deduction_philhealth DECIMAL(10,2) DEFAULT 0.00,
        deduction_pagibig DECIMAL(10,2) DEFAULT 0.00,
        deduction_tax DECIMAL(10,2) DEFAULT 0.00,
        total_statutory DECIMAL(10,2) DEFAULT 0.00,
        total_deductions DECIMAL(12,2),
        net_pay DECIMAL(12,2),
        status ENUM('Pending', 'Paid') DEFAULT 'Pending',
        calculation_details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add columns if they don't exist (for existing tables)
    try {
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deduction_sss DECIMAL(10,2) DEFAULT 0.00 AFTER gross_pay");
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deduction_philhealth DECIMAL(10,2) DEFAULT 0.00 AFTER deduction_sss");
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deduction_pagibig DECIMAL(10,2) DEFAULT 0.00 AFTER deduction_philhealth");
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS deduction_tax DECIMAL(10,2) DEFAULT 0.00 AFTER deduction_pagibig");
        $pdo->exec("ALTER TABLE payroll_records ADD COLUMN IF NOT EXISTS total_statutory DECIMAL(10,2) DEFAULT 0.00 AFTER deduction_tax");
    } catch (Exception $e) {}
    
    // Payroll Budgets
    $pdo->exec("CREATE TABLE IF NOT EXISTS payroll_budgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        payroll_period_id INT,
        budget_name VARCHAR(255),
        total_budget_amount DECIMAL(15,2) DEFAULT 0.00,
        approval_status ENUM('Draft', 'Waiting for Approval', 'Approved', 'Rejected') DEFAULT 'Draft',
        submitted_for_approval_at TIMESTAMP NULL,
        approved_at TIMESTAMP NULL,
        approved_by VARCHAR(100),
        approver_notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Ensure column exists for older tables
    try {
        $pdo->exec("ALTER TABLE payroll_budgets ADD COLUMN IF NOT EXISTS total_budget_amount DECIMAL(15,2) DEFAULT 0.00 AFTER budget_name");
    } catch (Exception $e) {}
    // T&A Batches (New Table for "Direct to Database" Request)
    $pdo->exec("CREATE TABLE IF NOT EXISTS ta_batches (
        id VARCHAR(50) PRIMARY KEY,
        name VARCHAR(100),
        start_date DATE,
        end_date DATE,
        total_logs INT,
        status VARCHAR(50)
    )");

    // Seed T&A Batches if empty
    $checkTA = $pdo->query("SELECT COUNT(*) FROM ta_batches")->fetchColumn();
    if ($checkTA == 0) {
        $pdo->exec("INSERT INTO ta_batches (id, name, start_date, end_date, total_logs, status) VALUES 
            ('TA-2026-001', 'Period: Jan 1 - Jan 15, 2026', '2026-01-01', '2026-01-15', 1450, 'Verified'),
            ('TA-2026-002', 'Period: Jan 16 - Jan 31, 2026', '2026-01-16', '2026-01-31', 1520, 'Pending Review'),
            ('TA-2026-003', 'Period: Feb 1 - Feb 15, 2026', '2026-02-01', '2026-02-15', 1480, 'Verified')
        ");
    }

    // Add March records (User Request)
    $checkMar = $pdo->query("SELECT COUNT(*) FROM ta_batches WHERE id = 'TA-2026-004'")->fetchColumn();
    if ($checkMar == 0) {
        $pdo->exec("INSERT INTO ta_batches (id, name, start_date, end_date, total_logs, status) VALUES 
            ('TA-2026-004', 'Period: Mar 1 - Mar 15, 2026', '2026-03-01', '2026-03-15', 1495, 'Pending Review'),
            ('TA-2026-005', 'Period: Mar 16 - Mar 31, 2026', '2026-03-16', '2026-03-31', 1505, 'Pending Review')
        ");
    }
    // Ensure 'Rejected' status is available in payroll_periods enum
    try {
        $pdo->exec("ALTER TABLE payroll_periods MODIFY COLUMN status ENUM('Draft', 'Calculated', 'Budgeted', 'Approved', 'Released', 'Rejected') DEFAULT 'Draft'");
    } catch (Exception $e) {}
}
checkAndCreateTables($pdo);

// -------------------------------------------------------------------------
// HELPER FUNCTIONS
// -------------------------------------------------------------------------

// Fetch "Received" T&A Batches from Database
function getIncomingTABatches() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT * FROM ta_batches ORDER BY start_date ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

function getAllActiveEmployees($pdo) {
    try {
        $stmt = $pdo->query("SELECT id, name, department, salary FROM employees WHERE status = 'Active'");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; } 
}

function calculatePayrollLogic($employee, $startDate, $endDate) {
    // 1. Determine Basic Pay
    $monthlyRate = $employee['salary'];
    $isSemiMonthly = (strtotime($endDate) - strtotime($startDate)) < 20 * 86400; 
    
    $basicPay = $isSemiMonthly ? ($monthlyRate / 2) : $monthlyRate;

    // 2. Earnings (Simulate OT/Allowances for now as placehoders)
    $overtimePay = 0; // Reset to 0 for cleaner default, or fetch from OT table if exists
    // Random OT for demo if no real data
    $overtimePay = rand(0, 5) * ($monthlyRate / 22 / 8 * 1.25); 
    
    $allowances = 1000; // Fixed allowance
    
    $grossIncome = $basicPay + $overtimePay + $allowances;

    // 3. Statutory Deductions (Based on Philippines Standard Models - 2024/2025 approx)
    
    // SSS (Social Security System)
    // 4.5% Employee Share of Monthly Salary Credit (MSC)
    // Capped at MSC of 30,000 (Max contribution ~1350)
    $sss_base = $monthlyRate; 
    if ($sss_base > 30000) $sss_base = 30000;
    $sss_full_month = $sss_base * 0.045; 
    // If semi-monthly, we usually deduct half, or deduct full on 2nd cutoff. 
    // For simplicity here, we split evenly.
    $sss = $isSemiMonthly ? ($sss_full_month / 2) : $sss_full_month;
    
    // PhilHealth
    // 5% Total Rate (Split 50/50), so 2.5% Employee Share
    // Income Floor 10k, Ceiling 100k
    $ph_base = $monthlyRate;
    if ($ph_base < 10000) $ph_base = 10000;
    if ($ph_base > 100000) $ph_base = 100000;
    $ph_full_month = $ph_base * 0.025; // 2.5% Employee Share
    $philhealth = $isSemiMonthly ? ($ph_full_month / 2) : $ph_full_month;
    
    // Pag-IBIG (HDMF)
    // 2% of Basic Salary
    // Max monthly compensation base is 5,000 (Max contribution = 100) - OLD RULE
    // NEW RULE Feb 2024: Max monthly compensation base is 10,000 (Max contribution = 200)
    $pi_base = $monthlyRate;
    if ($pi_base > 10000) $pi_base = 10000;
    $pi_full_month = $pi_base * 0.02;
    $pagibig = $isSemiMonthly ? ($pi_full_month / 2) : $pi_full_month;

    // Total Statutory
    $totalStatutory = $sss + $philhealth + $pagibig;
    
    // 4. Withholding Tax (TRAIN Law Annualized / 12 for monthly estim.)
    // Taxable Income = (Gross - Non-Taxable Allowances - Contributions)
    // Deep simplification for demo:
    $taxableIncome = ($grossIncome - $totalStatutory); 
    $tax = 0;
    
    // Monthly Tax Table (Simplified)
    // 20,833 and below: 0
    // 20,833 - 33,332: 20% > 20,833
    // 33,333 - 66,666: 2,500 + 25% > 33,333
    // 66,667 - 166,666: 10,833 + 30% > 66,667
    // 166,667 - 666,666: 40,833 + 32% > 166,667
    // Above 666,666: 200,833 + 35% > 666,666
    
    // Adjust thresholds for semi-monthly (divide by 2)
    $tier1 = 20833 / ($isSemiMonthly ? 2 : 1);
    $tier2 = 33333 / ($isSemiMonthly ? 2 : 1);
    $tier3 = 66667 / ($isSemiMonthly ? 2 : 1);
    // ... ignoring higher tiers for demo simplicity
    
    if ($taxableIncome > $tier1) {
        if ($taxableIncome < $tier2) {
            $tax = ($taxableIncome - $tier1) * 0.20;
        } elseif ($taxableIncome < $tier3) {
            $baseTax = 2500 / ($isSemiMonthly ? 2 : 1);
            $tax = $baseTax + (($taxableIncome - $tier2) * 0.25);
        } else {
            $baseTax = 10833 / ($isSemiMonthly ? 2 : 1);
            $tax = $baseTax + (($taxableIncome - $tier3) * 0.30);
        }
    }
    
    if ($tax < 0) $tax = 0;

    // 5. Net Pay
    $totalDeductions = $totalStatutory + $tax;
    $netPay = $grossIncome - $totalDeductions;

    return [
        'basic_salary' => $basicPay,
        'overtime_pay' => $overtimePay,
        'allowances' => $allowances,
        'gross_pay' => $grossIncome,
        'sss_deduction' => $sss,
        'philhealth_deduction' => $philhealth,
        'pagibig_deduction' => $pagibig,
        'tax_deduction' => $tax,
        'total_statutory' => $totalStatutory,
        'total_deductions' => $totalDeductions,
        'net_pay' => $netPay
    ];
}

// -------------------------------------------------------------------------
// ACTION HANDLERS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: VERIFY BATCH (User validates the T&A data)
    if (isset($_POST['verify_batch'])) {
        $batchId = $_POST['batch_id'];
        $pdo->prepare("UPDATE ta_batches SET status = 'Verified' WHERE id = ?")->execute([$batchId]);
        $_SESSION['success_message'] = "Batch $batchId verified successfully. Ready for calculation.";
        header("Location: payroll-calculation.php"); exit;
    }

    // ACTION: DENY BATCH (User rejects the T&A data)
    if (isset($_POST['deny_batch'])) {
        $batchId = $_POST['batch_id'];
        $pdo->prepare("UPDATE ta_batches SET status = 'Pending Review' WHERE id = ?")->execute([$batchId]);
        $_SESSION['success_message'] = "Batch $batchId status reset to Pending Review.";
        header("Location: payroll-calculation.php"); exit;
    }

    // ACTION: PROCESS T&A BATCH -> CREATES PERIOD & CALCULATES
    if (isset($_POST['process_ta_batch'])) {
        $ta_code = $_POST['ta_code'];
        $name = $_POST['batch_name'];
        $start = $_POST['start_date'];
        $end = $_POST['end_date'];
        
        // 1. Create Payroll Period
        $periodCode = 'PAY-' . date('Ymd', strtotime($start)) . '-' . rand(100,999);
        $stmt = $pdo->prepare("INSERT INTO payroll_periods (period_code, name, start_date, end_date, pay_frequency, status, created_by) VALUES (?, ?, ?, ?, 'Semi-Monthly', 'Calculated', 'System')");
        $stmt->execute([$periodCode, $name, $start, $end]);
        $periodId = $pdo->lastInsertId();
        
        // 2. Auto-Calculate for All Employees
        $employees = getAllActiveEmployees($pdo);
        $count = 0;
        $totalNet = 0;
        
        foreach ($employees as $emp) {
            $calc = calculatePayrollLogic($emp, $start, $end);
            
            // Insert with specific deduction columns
            $ins = $pdo->prepare("INSERT INTO payroll_records 
                (payroll_period_id, employee_id, employee_name, department, 
                 pay_period_start, pay_period_end, basic_salary, overtime_pay, 
                 allowances, gross_pay, 
                 deduction_sss, deduction_philhealth, deduction_pagibig, deduction_tax, total_statutory,
                 total_deductions, net_pay, calculation_details) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
            $ins->execute([
                $periodId, $emp['id'], $emp['name'], $emp['department'],
                $start, $end, $calc['basic_salary'], $calc['overtime_pay'],
                $calc['allowances'], $calc['gross_pay'],
                $calc['sss_deduction'], $calc['philhealth_deduction'], $calc['pagibig_deduction'], $calc['tax_deduction'], $calc['total_statutory'],
                $calc['total_deductions'], $calc['net_pay'], json_encode($calc)
            ]);
            
            $count++;
            $totalNet += $calc['net_pay'];
        }
        
        // 3. Update Period Totals
        $pdo->prepare("UPDATE payroll_periods SET total_employees = ?, total_amount = ? WHERE id = ?")->execute([$count, $totalNet, $periodId]);
        
        $_SESSION['success_message'] = "Processed T&A Batch. Payroll Calculated for $count employees.";
        header("Location: payroll-calculation.php?view_id=$periodId");
        exit;
    }

    // ACTION: CREATE BUDGET
    if (isset($_POST['create_budget'])) {
        $periodId = $_POST['period_id'];
        $periodName = $_POST['period_name'];
        
        $check = $pdo->prepare("SELECT id FROM payroll_budgets WHERE payroll_period_id = ?");
        $check->execute([$periodId]);
        if(!$check->fetch()) {
            $pdo->prepare("INSERT INTO payroll_budgets (payroll_period_id, budget_name, approval_status) VALUES (?, ?, 'Draft')")
                ->execute([$periodId, "Budget - " . $periodName]);
            
            $pdo->prepare("UPDATE payroll_periods SET status = 'Budgeted' WHERE id = ?")->execute([$periodId]);
            $_SESSION['success_message'] = "Budget created successfully.";
        }
        header("Location: payroll-calculation.php?view_id=$periodId");
        exit;
    }

    // ACTION: SUBMIT FOR APPROVAL
    if (isset($_POST['submit_approval'])) {
        $periodId = $_POST['period_id'];
        
        // Fetch total amount from period to lock in as budget amount
        $periodStmt = $pdo->prepare("SELECT total_amount FROM payroll_periods WHERE id = ?");
        $periodStmt->execute([$periodId]);
        $periodData = $periodStmt->fetch(PDO::FETCH_ASSOC);
        $totalBudget = $periodData['total_amount'] ?? 0;

        $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Waiting for Approval', submitted_for_approval_at = NOW(), total_budget_amount = ? WHERE payroll_period_id = ?")
            ->execute([$totalBudget, $periodId]);
        
        $_SESSION['success_message'] = "Budget of ₱" . number_format($totalBudget, 2) . " submitted to Finance for approval.";
        header("Location: payroll-calculation.php?view_id=$periodId");
        exit;
    }
    // ACTION: APPROVE BUDGET
    if (isset($_POST['approve_budget'])) {
        $userRole = $_SESSION['role'] ?? 'admin'; // Default to allow if not set
        $periodId = $_POST['period_id'];
        
        // Update Budget
        $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Approved', approved_at = NOW(), approved_by = ? WHERE payroll_period_id = ?")
            ->execute([$_SESSION['user'] ?? 'User', $periodId]);
            
        // Update Period Status -> APPROVED
        $pdo->prepare("UPDATE payroll_periods SET status = 'Approved' WHERE id = ?")->execute([$periodId]);
        
        $_SESSION['success_message'] = "Budget successfully approved. Payroll is now finalized.";
        header("Location: payroll-calculation.php?view_id=$periodId");
        exit;
    }

    // ACTION: REJECT BUDGET
    if (isset($_POST['reject_budget'])) {
        $periodId = $_POST['period_id'];
        
        $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Rejected', approved_by = ? WHERE payroll_period_id = ?")
            ->execute([$_SESSION['user'] ?? 'User', $periodId]);
            
        $pdo->prepare("UPDATE payroll_periods SET status = 'Rejected' WHERE id = ?")->execute([$periodId]);
        
        $_SESSION['success_message'] = "Budget rejected. Status updated.";
        header("Location: payroll-calculation.php?view_id=$periodId");
        exit;
    }

    // ACTION: RESET BUDGET (To Draft)
    if (isset($_POST['reset_budget'])) {
        $periodId = $_POST['period_id'];
        
        $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Draft' WHERE payroll_period_id = ?")->execute([$periodId]);
        $pdo->prepare("UPDATE payroll_periods SET status = 'Budgeted' WHERE id = ?")->execute([$periodId]);
        
        $_SESSION['success_message'] = "Budget status reset to Draft. You can now edit and resubmit.";
        header("Location: payroll-calculation.php?view_id=$periodId");
        exit;
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
    // Fetch Period
    $stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE id = ?");
    $stmt->execute([$viewId]);
    $viewPeriod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch Records
    if ($viewPeriod) {
        $stmtRec = $pdo->prepare("SELECT * FROM payroll_records WHERE payroll_period_id = ?");
        $stmtRec->execute([$viewId]);
        $viewRecords = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Budget
        $stmtBud = $pdo->prepare("SELECT * FROM payroll_budgets WHERE payroll_period_id = ?");
        $stmtBud->execute([$viewId]);
        $viewBudget = $stmtBud->fetch(PDO::FETCH_ASSOC);
    }
}

// Fetch All Periods with Budget Status
$allPeriods = $pdo->query("
    SELECT p.*, b.approval_status as budget_status 
    FROM payroll_periods p 
    LEFT JOIN payroll_budgets b ON p.id = b.payroll_period_id 
    ORDER BY p.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Incoming T&A
$incomingBatches = getIncomingTABatches();

// Theme
$currentTheme = $_SESSION['theme'] ?? 'light';
if (isset($_GET['toggle_theme'])) {
    $_SESSION['theme'] = ($currentTheme == 'light') ? 'dark' : 'light';
    header("Location: payroll-calculation.php"); exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll Calculation | HR System</title>
    <!-- Use Bootstrap for consistency with Employment Info UI -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Copied Styles from contract-employment.php -->
    <style>
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            transition: background 0.3s, color 0.3s;
            line-height: 1.4;
            overflow-x: hidden;
        }
        
        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
            --secondary-color: #2c3e50; /* Adjust for main content background */
        }

        /* Enhanced Filter/Action Styles */
        .filters-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: all 0.3s;
        }

        body.dark-mode .filters-container { background: var(--dark-card); }

        .filters-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;
        }

        .filters-title {
            font-size: 1.2rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0;
        }

        .btn {
            padding: 0.375rem 0.75rem; border: none; border-radius: var(--border-radius); cursor: pointer;
            font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; font-size: 0.875rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { background: #2e59d9; transform: translateY(-1px); }
        .btn-success { background: var(--success-color); color: white; }
        .btn-warning { background: var(--warning-color); color: #333; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-info { background: var(--info-color); color: white; }

        /* Report/Table Card Styles */
        .report-card {
            background: white; border-radius: var(--border-radius); padding: 1.5rem;
            box-shadow: var(--shadow); transition: all 0.3s; border-left: 4px solid var(--primary-color);
            margin-bottom: 1.5rem;
        }
        body.dark-mode .report-card { background: var(--dark-card); }

        .report-card-header {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;
            padding-bottom: 1rem; border-bottom: 1px solid #e3e6f0;
        }
        body.dark-mode .report-card-header { border-bottom: 1px solid #4a5568; }

        .report-card-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 0; }

        /* Enhanced Table Styles */
        .data-table { width: 100%; border-collapse: collapse; background: white; border-radius: var(--border-radius); overflow: hidden; }
        body.dark-mode .data-table { background: #2d3748; }

        .data-table th {
            background: #f8f9fc; padding: 0.75rem; text-align: left; font-weight: 600;
            color: #4e73df; border-bottom: 1px solid #e3e6f0;
        }
        body.dark-mode .data-table th { background: #2d3748; color: #63b3ed; border-bottom: 1px solid #4a5568; }

        .data-table td { padding: 0.75rem; border-bottom: 1px solid #e3e6f0; vertical-align: middle; }
        body.dark-mode .data-table td { border-bottom: 1px solid #4a5568; }

        .data-table tr:hover { background: #f8f9fc; transform: scale(1.002); }
        body.dark-mode .data-table tr:hover { background: #2d3748; }

        /* Status Badges */
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; color: white; }
        .bg-Draft { background: #858796; }
        .bg-Calculated { background: var(--info-color); }
        .bg-Budgeted { background: var(--primary-color); }
        .bg-Approved { background: var(--success-color); }
        .bg-Rejected { background: var(--danger-color); }

        /* Main Layout */
        .main-content {
            padding: 2rem; min-height: 100vh; background-color: var(--secondary-color);
            margin-top: 60px;
        }
        body.dark-mode .main-content { background-color: var(--dark-bg); }

        .page-header {
            padding: 1.5rem; margin-bottom: 1.5rem; border-radius: var(--border-radius);
            background: white; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: center;
        }
        body.dark-mode .page-header { background: var(--dark-card); }

        .page-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 1rem; }
        .page-subtitle { color: #6c757d; font-size: 0.9rem; margin-bottom: 0; }
        body.dark-mode .page-subtitle { color: #a0aec0; }
        
        .theme-toggle { position: fixed; top: 20px; right: 20px; z-index: 1000; }
    </style>
</head>

<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">


    <div class="main-content">
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-calculator"></i> Payroll Calculation</h1>
                <p class="page-subtitle">Import T&A, Calculate Payroll, and Manage Budgets</p>
            </div>
            
            <?php if (!$viewId): ?>
            <div>
                 <button onclick="document.getElementById('ta-section').style.display='block'" class="btn btn-primary">
                    <i class="fas fa-calculator"></i> Calculate Payroll
                </button>
                <a href="payslip-generator.php" class="btn btn-secondary ms-2">
                    <i class="fas fa-print"></i> Payslip Generator
                </a>
            </div>
            <?php else: ?>
             <div>
                <a href="payroll-calculation.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to List</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!$viewId): ?>
            <!-- DASHBOARD VIEW -->

            <!-- 1. PENDING T&A FILES (Hidden by default) -->
            <div id="ta-section" class="filters-container" style="display: none; border-left: 4px solid var(--info-color);">
                <div class="filters-header">
                    <h3 class="filters-title text-primary"><i class="fas fa-clock"></i> Select Available Time & Attendance Data</h3>
                    <button onclick="document.getElementById('ta-section').style.display='none'" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Close</button>
                </div>
                <div style="padding: 0.5rem;">
                    <p class="text-muted mb-3">Please select a calculated T&A batch to generate the payroll for that period:</p>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Batch ID</th>
                                <th>Period Name</th>
                                <th>Dates</th>
                                <th>Total Logs</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomingBatches as $batch): 
                                // Check if this period is already calculated
                                $isCalculated = false;
                                foreach($allPeriods as $p) {
                                    if($p['start_date'] == $batch['start_date'] && $p['end_date'] == $batch['end_date']) {
                                        $isCalculated = true;
                                        break;
                                    }
                                }
                            ?>
                            <tr>
                                <td><code><?php echo $batch['id']; ?></code></td>
                                <td><b><?php echo $batch['name']; ?></b></td>
                                <td><?php echo $batch['start_date'] . ' to ' . $batch['end_date']; ?></td>
                                <td><?php echo $batch['total_logs']; ?></td>
                                <td><span class="badge" style="background: <?php echo $batch['status']=='Verified'?'var(--success-color)':'var(--warning-color)'; ?>"><?php echo $batch['status']; ?></span></td>
                                <td>
                                    <?php if ($isCalculated): ?>
                                         <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-check-double"></i> Calculated</button>
                                    <?php elseif ($batch['status'] == 'Verified'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="ta_code" value="<?php echo $batch['id']; ?>">
                                        <input type="hidden" name="batch_name" value="<?php echo $batch['name']; ?>">
                                        <input type="hidden" name="start_date" value="<?php echo $batch['start_date']; ?>">
                                        <input type="hidden" name="end_date" value="<?php echo $batch['end_date']; ?>">
                                        <button type="submit" name="process_ta_batch" class="btn btn-primary btn-sm">
                                            <i class="fas fa-play-circle"></i> Calculate
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                        <button type="submit" name="deny_batch" class="btn btn-outline-danger btn-sm" title="Revert to Pending">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                            <button type="submit" name="verify_batch" class="btn btn-success btn-sm">
                                                <i class="fas fa-check"></i> Verify
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                            <button type="submit" name="deny_batch" class="btn btn-danger btn-sm">
                                                <i class="fas fa-ban"></i> Deny
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 2. PAYROLL HISTORY -->
            <div class="report-card">
                <div class="report-card-header">
                    <h3 class="report-card-title"><i class="fas fa-history"></i> Payroll Records History</h3>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Period Code</th>
                                <th>Name</th>
                                <th>Date Range</th>
                                <th>Payroll Status</th>
                                <th>Total Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allPeriods as $p): ?>
                            <tr>
                                <td><span class="text-muted"><?php echo $p['period_code']; ?></span></td>
                                <td><strong><?php echo $p['name']; ?></strong></td>
                                <td><?php echo date('M d', strtotime($p['start_date'])) . ' - ' . date('M d', strtotime($p['end_date'])); ?></td>
                                <td><span class="badge bg-<?php echo $p['status']; ?>"><?php echo $p['status']; ?></span></td>
                                <td>₱<?php echo number_format($p['total_amount'], 2); ?></td>
                                <td>
                                    <a href="?view_id=<?php echo $p['id']; ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> View</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($allPeriods)): ?>
                                <tr><td colspan="6" style="text-align:center; padding:2rem; color:#858796;">No processed payrolls yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <!-- DETAIL VIEW -->
            
            <!-- Stats Simulating Contract Info Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                        <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Status</h6>
                        <h3 class="text-primary"><?php echo $viewPeriod['status']; ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                        <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Total Employees</h6>
                        <h3><?php echo $viewPeriod['total_employees']; ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                        <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Total Net Pay</h6>
                        <h3 class="text-success">₱<?php echo number_format($viewPeriod['total_amount'], 2); ?></h3>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                        <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Budget Status</h6>
                        <h3><?php echo $viewBudget ? ($viewBudget['approval_status']) : 'Not Created'; ?></h3>
                    </div>
                </div>
            </div>

            <!-- Actions Card -->
            <div class="report-card mb-4" style="border-left: 5px solid var(--warning-color);">
                <div class="report-card-header">
                    <h5 class="report-card-title mb-0">Payroll Period Actions</h5>
                </div>
                <div class="p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="badge bg-secondary p-2 mb-2" style="font-size:0.9rem;">
                            <i class="fas fa-info-circle"></i> Status: 
                            <?php echo $viewPeriod['status']; ?> 
                            <?php if($viewBudget) echo ' | Budget: ' . $viewBudget['approval_status']; ?>
                        </span>
                        <p class="text-muted mb-0 small">Manage the lifecycle of this payroll period.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <!-- View T&A Logs Button -->
                        <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#taLogsModal">
                            <i class="fas fa-list-alt"></i> View T&A Logs
                        </button>

                        <?php if (!$viewBudget): ?>
                            <form method="POST">
                                <input type="hidden" name="period_id" value="<?php echo $viewPeriod['id']; ?>">
                                <input type="hidden" name="period_name" value="<?php echo $viewPeriod['name']; ?>">
                                <button type="submit" name="create_budget" class="btn btn-primary">
                                    <i class="fas fa-file-invoice-dollar"></i> Create Budget
                                </button>
                            </form>
                        <?php else: ?>
                            <?php if ($viewBudget['approval_status'] == 'Draft'): ?>
                                <form method="POST">
                                    <input type="hidden" name="period_id" value="<?php echo $viewPeriod['id']; ?>">
                                    <button type="submit" name="submit_approval" class="btn btn-warning text-white">
                                        <i class="fas fa-paper-plane"></i> Submit to Finance
                                    </button>
                                </form>
                            <?php elseif ($viewBudget['approval_status'] == 'Waiting for Approval'): ?>
                                <div class="alert alert-warning py-2 px-3 mb-0 d-inline-flex align-items-center">
                                    <i class="fas fa-hourglass-half me-2"></i> Waiting for Finance Approval
                                </div>
                            <?php elseif ($viewBudget['approval_status'] == 'Rejected'): ?>
                                <button class="btn btn-danger" disabled><i class="fas fa-ban"></i> Rejected by Finance</button>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="period_id" value="<?php echo $viewPeriod['id']; ?>">
                                    <button type="submit" name="reset_budget" class="btn btn-secondary">
                                        <i class="fas fa-undo"></i> Reset to Draft
                                    </button>
                                </form>
                            <?php elseif ($viewBudget['approval_status'] == 'Approved'): ?>
                                <button class="btn btn-success" disabled><i class="fas fa-check-double"></i> Approved by Finance</button>
                                
                                <!-- Printable Report for Finance -->
                                <a href="payroll-register.php?period_id=<?php echo $viewPeriod['id']; ?>" target="_blank" class="btn btn-dark">
                                    <i class="fas fa-print"></i> Print Register
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>



            <!-- Employee Table -->
            <div class="report-card">
                 <div class="report-card-header">
                    <h3 class="report-card-title">Employee Payroll Details</h3>
                </div>
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Basic</th>
                                <th>Overtime</th>
                                <th>Gross</th>
                                <th>Deductions</th>
                                <th>Net Pay</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($viewRecords as $rec): ?>
                            <tr>
                                <td>
                                    <span class="fw-bold"><?php echo $rec['employee_name']; ?></span><br>
                                    <small class="text-muted">ID: <?php echo $rec['employee_id']; ?></small>
                                </td>
                                <td><?php echo $rec['department']; ?></td>
                                <td>₱<?php echo number_format($rec['basic_salary'], 2); ?></td>
                                <td>₱<?php echo number_format($rec['overtime_pay'], 2); ?></td>
                                <td>₱<?php echo number_format($rec['gross_pay'], 2); ?></td>
                                <td class="text-danger"> - ₱<?php echo number_format($rec['total_deductions'], 2); ?></td>
                                <td class="text-success fw-bold">₱<?php echo number_format($rec['net_pay'], 2); ?></td>
                                <td>
                                    <button class="btn btn-sm btn-info text-white" onclick='viewCalculation(<?php echo $rec["calculation_details"] ?: "{}"; ?>, "<?php echo $rec["employee_name"]; ?>")' title="View Breakdown">
                                        <i class="fas fa-search-dollar"></i>
                                    </button>
                                    <a href="payslip-generator.php?id=<?php echo $rec['id']; ?>" target="_blank" class="btn btn-sm btn-secondary" title="Print Payslip">
                                        <i class="fas fa-file-invoice"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- T&A Logs Modal (Mockup for Verification) -->
            <div class="modal fade" id="taLogsModal" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-clock"></i> Time & Attendance Logs</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-info py-2"><small>Daily logs for period: <?php echo $viewPeriod['start_date'] . ' to ' . $viewPeriod['end_date']; ?></small></div>
                            <table class="table table-striped table-sm" style="font-size:0.9rem;">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Employee</th>
                                        <th>Time In</th>
                                        <th>Time Out</th>
                                        <th>Hours</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Log Simulation -->
                                    <?php 
                                    // Generate some dummy logs for display
                                    $simDates = [];
                                    $current = strtotime($viewPeriod['start_date']);
                                    $end = strtotime($viewPeriod['end_date']);
                                    while ($current <= $end) {
                                        $simDates[] = date('Y-m-d', $current);
                                        $current = strtotime('+1 day', $current);
                                        if(count($simDates) > 5) break; // Limit to 5 days for demo
                                    }
                                    
                                    foreach($simDates as $d):
                                        foreach($viewRecords as $idx => $r):
                                            if($idx > 2) break; // Limit to 3 employees for demo
                                    ?>
                                    <tr>
                                        <td><?php echo $d; ?></td>
                                        <td><?php echo $r['employee_name']; ?></td>
                                        <td>08:00 AM</td>
                                        <td>05:00 PM</td>
                                        <td>8.0</td>
                                        <td><span class="badge bg-success">Present</span></td>
                                    </tr>
                                    <?php endforeach; endforeach; ?>
                                </tbody>
                            </table>
                            <div class="text-center text-muted fst-italic mt-3"><small>... displaying sample of verified logs ...</small></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Calculation Modal -->
            <div class="modal fade" id="calcModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Salary Calculation: <span id="calcModalName"></span></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <table class="table table-bordered table-sm">
                                <tbody id="calcModalBody">
                                    <!-- Populated by JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            function viewCalculation(details, name) {
                const mapKey = {
                    'basic_salary': 'Basic Salary', 
                    'overtime_pay': 'Overtime Pay', 
                    'allowances': 'Allowances', 
                    'gross_pay': 'Gross Pay', 
                    'total_deductions': 'Total Deductions', 
                    'net_pay': 'Net Pay'
                };
                
                let html = '';
                for (const [key, value] of Object.entries(details)) {
                    let label = mapKey[key] || key.replace('_', ' ').toUpperCase();
                    html += `<tr><th>${label}</th><td class="text-end">₱${Number(value).toLocaleString(undefined, {minimumFractionDigits: 2})}</td></tr>`;
                }
                
                document.getElementById('calcModalName').innerText = name;
                document.getElementById('calcModalBody').innerHTML = html;
                new bootstrap.Modal(document.getElementById('calcModal')).show();
            }
            </script>
            
        <?php endif; ?>

    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>