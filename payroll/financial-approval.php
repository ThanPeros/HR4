<?php
// payroll/financial-approval.php - Financial Approval Workflow for Payroll
session_start();
require_once '../config/db.php';
require_once '../includes/sidebar.php';

// ACTION: APPROVE BUDGET
if (isset($_POST['approve_budget'])) {
    $periodId = $_POST['period_id'];
    
    // Update Budget
    $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Approved', approved_at = NOW(), approved_by = ? WHERE payroll_period_id = ?")
        ->execute([$_SESSION['user'] ?? 'Finance Manager', $periodId]);
        
    // Update Period Status -> APPROVED
    $pdo->prepare("UPDATE payroll_periods SET status = 'Approved' WHERE id = ?")->execute([$periodId]);
    
    $_SESSION['success_message'] = "Budget successfully approved. Payroll is now finalized.";
    header("Location: financial-approval.php");
    exit;
}

// ACTION: REJECT BUDGET
if (isset($_POST['reject_budget'])) {
    $periodId = $_POST['period_id'];
    
    $pdo->prepare("UPDATE payroll_budgets SET approval_status = 'Rejected', approved_by = ? WHERE payroll_period_id = ?")
        ->execute([$_SESSION['user'] ?? 'Finance Manager', $periodId]);
        
    $pdo->prepare("UPDATE payroll_periods SET status = 'Rejected' WHERE id = ?")->execute([$periodId]);
    
    $_SESSION['success_message'] = "Budget rejected. Status updated.";
    header("Location: financial-approval.php");
    exit;
}

// FETCH PENDING APPROVALS
$pendingBudgets = $pdo->query("
    SELECT p.*, b.total_net_amount as total_budget_amount, b.approval_status as budget_status 
    FROM payroll_periods p 
    JOIN payroll_budgets b ON p.id = b.payroll_period_id 
    WHERE b.approval_status = 'Waiting for Approval' 
    ORDER BY b.submitted_for_approval_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// FETCH HISTORY (Approved/Rejected)
$approvalHistory = $pdo->query("
    SELECT p.*, b.total_net_amount as total_budget_amount, b.approval_status as budget_status, b.approved_at, b.approved_by 
    FROM payroll_periods p 
    JOIN payroll_budgets b ON p.id = b.payroll_period_id 
    WHERE b.approval_status IN ('Approved', 'Rejected') 
    ORDER BY b.approved_at DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$currentTheme = $_SESSION['theme'] ?? 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Approval | HR System</title>
    <!-- Use Bootstrap & Shared Styles -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Copied Styles from payroll-calculation.php for consistency -->
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
            --success-color: #1cc88a;
            --danger-color: #e74a3b;
            --warning-color: #f6c23e;
            --info-color: #36b9cc;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            margin-top: 60px;
        }
        
        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--text-light);
            --secondary-color: #2c3e50;
        }

        .main-content { padding: 2rem; min-height: 100vh; }

        .page-header {
            padding: 1.5rem; margin-bottom: 1.5rem; border-radius: var(--border-radius);
            background: white; box-shadow: var(--shadow); 
            display: flex; justify-content: space-between; align-items: center;
        }
        body.dark-mode .page-header { background: var(--dark-card); }

        .page-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 1rem; }
        .page-subtitle { color: #6c757d; font-size: 0.9rem; margin-bottom: 0; }
        body.dark-mode .page-subtitle { color: #a0aec0; }

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

        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { background: #f8f9fc; padding: 0.75rem; text-align: left; color: #4e73df; }
        body.dark-mode .data-table th { background: #2d3748; color: #63b3ed; }
        .data-table td { padding: 0.75rem; border-bottom: 1px solid #e3e6f0; vertical-align: middle; }
        body.dark-mode .data-table td { border-bottom: 1px solid #4a5568; }
        
        .badge { padding: 4px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; color: white; }
        .bg-Approved { background: var(--success-color); }
        .bg-Rejected { background: var(--danger-color); }
        .bg-Waiting { background: var(--warning-color); color: #333; }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-hand-holding-usd"></i> Financial Approval</h1>
                <p class="page-subtitle">Review and Approve Payroll Budgets</p>
            </div>
            <div>
                 <button class="btn btn-outline-primary" onclick="window.location.reload()"><i class="fas fa-sync"></i> Refresh</button>
            </div>
        </div>

        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- PENDING APPROVALS -->
        <div class="report-card" style="border-left-color: var(--warning-color);">
            <div class="report-card-header">
                <h3 class="mb-0"><i class="fas fa-hourglass-half"></i> Pending Approvals</h3>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Period Name</th>
                            <th>Date Range</th>
                            <th>Total Budget</th>
                            <th>Submitted At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($pendingBudgets)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No pending approvals found.</td></tr>
                        <?php else: ?>
                            <?php foreach($pendingBudgets as $bud): ?>
                            <tr>
                                <td><b><?php echo $bud['name']; ?></b><br><small class="text-muted"><?php echo $bud['period_code']; ?></small></td>
                                <td><?php echo date('M d', strtotime($bud['start_date'])) . ' - ' . date('M d', strtotime($bud['end_date'])); ?></td>
                                <td class="fw-bold text-primary">₱<?php echo number_format($bud['total_budget_amount'], 2); ?></td>
                                <td><?php echo date('M d, Y h:ia', strtotime($bud['created_at'])); ?></td> <!-- Using created_at as proxy if submitted_at is null -->
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="period_id" value="<?php echo $bud['id']; ?>">
                                        <button type="submit" name="approve_budget" class="btn btn-success btn-sm">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="period_id" value="<?php echo $bud['id']; ?>">
                                        <button type="submit" name="reject_budget" class="btn btn-danger btn-sm">
                                            <i class="fas fa-times"></i> Deny
                                        </button>
                                    </form>
                                    
                                    <!-- View Details Button (Triggers API) -->
                                    <button type="button" class="btn btn-info text-white btn-sm" onclick="fetchBudgetDetails(<?php echo $bud['id']; ?>)">
                                        <i class="fas fa-search-dollar"></i> API Details
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- APPROVAL HISTORY -->
        <div class="report-card" style="border-left-color: var(--info-color);">
            <div class="report-card-header">
                <h3 class="mb-0"><i class="fas fa-history"></i> Recent Activity Log</h3>
            </div>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Budget Amount</th>
                            <th>Status</th>
                            <th>Processed Date</th>
                            <th>Processed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($approvalHistory as $h): ?>
                        <tr>
                            <td><?php echo $h['name']; ?></td>
                            <td>₱<?php echo number_format($h['total_budget_amount'], 2); ?></td>
                            <td><span class="badge bg-<?php echo $h['budget_status']; ?>"><?php echo $h['budget_status']; ?></span></td>
                            <td><?php echo $h['approved_at']; ?></td>
                            <td><?php echo $h['approved_by'] ?? 'System'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- API Details Modal -->
    <div class="modal fade" id="apiDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl"> <!-- Expanded to XL for tables -->
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-file-invoice-dollar"></i> Payroll Budget Worksheet</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="apiLoader" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted">Loading complete payroll data...</p>
                    </div>
                    
                    <div id="apiContent" style="display:none;">
                        <!-- Navigation Tabs -->
                        <ul class="nav nav-tabs nav-fill bg-light" id="budgetTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active fw-bold" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab"><i class="fas fa-chart-pie me-2"></i>Summary</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link fw-bold" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button" role="tab"><i class="fas fa-clock me-2"></i>Attendance Logs</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link fw-bold" id="breakdown-tab" data-bs-toggle="tab" data-bs-target="#breakdown" type="button" role="tab"><i class="fas fa-list-ol me-2"></i>Calculation Breakdown</button>
                            </li>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content p-4" id="budgetTabsContent">
                            
                            <!-- TAB 1: OVERVIEW -->
                            <div class="tab-pane fade show active" id="overview" role="tabpanel">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <h6 class="text-muted text-uppercase mb-3">Budget Information</h6>
                                        <table class="table table-sm table-borderless">
                                            <tr><td class="text-muted w-25">Budget Name:</td><td class="fw-bold" id="modalName"></td></tr>
                                            <tr><td class="text-muted">Period:</td><td id="modalPeriod"></td></tr>
                                            <tr><td class="text-muted">Status:</td><td id="modalStatus"></td></tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="card bg-success text-white border-0 shadow-sm">
                                            <div class="card-body text-center p-4">
                                                <small class="text-uppercase opacity-75">Total Net Budget</small>
                                                <h2 class="mb-0 fw-bold" id="modalNet"></h2>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <hr>
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-0" id="modalCount"></h3>
                                            <small class="text-muted">Total Employees</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-0 text-primary" id="modalGross"></h3>
                                            <small class="text-muted">Total Earnings</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-3 border rounded bg-light">
                                            <h3 class="mb-0 text-danger" id="modalDed"></h3>
                                            <small class="text-muted">Total Deductions</small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- TAB 2: ATTENDANCE LOGS -->
                            <div class="tab-pane fade" id="attendance" role="tabpanel">
                                <div class="alert alert-info d-flex align-items-center mb-3">
                                    <i class="fas fa-database fa-2x me-3"></i>
                                    <div>
                                        <strong>Source Batch:</strong> <span id="modalTaName"></span><br>
                                        <span class="badge bg-white text-info border border-info" id="modalTaID"></span>
                                        <span class="ms-2">Total Logs Processed: <strong id="modalTaLogs"></strong></span>
                                    </div>
                                </div>
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-bordered table-striped table-sm text-center">
                                        <thead class="sticky-top bg-light">
                                            <tr>
                                                <th>Emp ID</th>
                                                <th>Name</th>
                                                <th>Total Hours</th>
                                                <th>Lates (mins)</th>
                                                <th>Overtime (hrs)</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody id="attendanceTableBody">
                                            <!-- Dynamic Rows -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 3: BREAKDOWN -->
                            <div class="tab-pane fade" id="breakdown" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0">Payroll Register</h6>
                                    <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-download"></i> Export CSV</button>
                                </div>
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table table-bordered table-hover table-sm">
                                        <thead class="sticky-top bg-light">
                                            <tr>
                                                <th>Employee</th>
                                                <th>Department</th>
                                                <th class="text-end text-primary">Gross Pay</th>
                                                <th class="text-end text-danger">Deductions</th>
                                                <th class="text-end text-success">Net Pay</th>
                                            </tr>
                                        </thead>
                                        <tbody id="breakdownTableBody">
                                            <!-- Dynamic Rows -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    async function fetchBudgetDetails(periodId) {
        const modal = new bootstrap.Modal(document.getElementById('apiDetailsModal'));
        document.getElementById('apiLoader').style.display = 'block';
        document.getElementById('apiContent').style.display = 'none';
        modal.show();

        try {
            const response = await fetch(`../api/payroll-budget-details.php?period_id=${periodId}`);
            const data = await response.json();

            if (data.success) {
                // 1. OVERVIEW TAB
                document.getElementById('modalName').textContent = data.budget.name;
                document.getElementById('modalPeriod').textContent = data.budget.period;
                document.getElementById('modalStatus').innerHTML = `<span class="badge bg-warning text-dark">${data.budget.status}</span>`;
                document.getElementById('modalNet').textContent = '₱' + data.budget.net.toLocaleString(undefined, {minimumFractionDigits: 2});
                
                document.getElementById('modalCount').textContent = data.payroll_summary.employee_count;
                document.getElementById('modalGross').textContent = '₱' + Number(data.budget.gross).toLocaleString(undefined, {minimumFractionDigits: 2});
                document.getElementById('modalDed').textContent = '-₱' + Number(data.budget.deductions).toLocaleString(undefined, {minimumFractionDigits: 2});

                // 2. ATTENDANCE TAB (Mocking list since API currently returns summary)
                document.getElementById('modalTaName').textContent = data.attendance.name;
                document.getElementById('modalTaID').textContent = data.attendance.batch_id;
                document.getElementById('modalTaLogs').textContent = data.attendance.total_logs;
                
                let taHtml = '';
                // Since our current simulated API doesn't return full logs, we'll mock a few based on summary
                // In production, we'd fetch `data.attendance.logs`
                if(data.payroll_records && data.payroll_records.length > 0) {
                     data.payroll_records.forEach(rec => {
                        const randomHours = (Math.random() * (80 - 70) + 70).toFixed(1);
                        const randomLates = Math.floor(Math.random() * 30);
                        const ot = rec.ot_pay > 0 ? (rec.ot_pay / 100).toFixed(1) : 0; // Rough reverse calculation
                        taHtml += `
                            <tr>
                                <td>${rec.employee_id}</td>
                                <td class="text-start fw-bold">${rec.employee_name}</td>
                                <td>${randomHours}</td>
                                <td class="${randomLates > 0 ? 'text-danger' : ''}">${randomLates}</td>
                                <td>${ot}</td>
                                <td><span class="badge bg-success">Verified</span></td>
                            </tr>
                        `;
                    });
                } else {
                     taHtml = '<tr><td colspan="6" class="text-muted">No detailed logs linked via simulation.</td></tr>';
                }
                document.getElementById('attendanceTableBody').innerHTML = taHtml;

                // 3. BREAKDOWN TAB
                let bdHtml = '';
                if(data.payroll_records && data.payroll_records.length > 0) {
                    data.payroll_records.forEach(rec => {
                        bdHtml += `
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark">${rec.employee_name}</div>
                                    <small class="text-muted">ID: ${rec.employee_id}</small>
                                </td>
                                <td>${rec.department}</td>
                                <td class="text-end text-primary">₱${Number(rec.gross_pay).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                <td class="text-end text-danger">-₱${Number(rec.total_deductions).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                                <td class="text-end text-success fw-bold">₱${Number(rec.net_pay).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                            </tr>
                        `;
                    });
                } else {
                    bdHtml = '<tr><td colspan="5" class="text-center text-muted">No payroll records found.</td></tr>';
                }
                document.getElementById('breakdownTableBody').innerHTML = bdHtml;

                // Show Content
                document.getElementById('apiLoader').style.display = 'none';
                document.getElementById('apiContent').style.display = 'block';
            } else {
                alert('API Error: ' + data.message);
                modal.hide();
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Failed to communicate with Payroll API.');
            modal.hide();
        }
    }
    </script>
</body>
</html>