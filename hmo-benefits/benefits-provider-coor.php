<?php
// hmo-benefits/benefits-provider-coor.php - Benefits Renewal & Provider Coordination
session_start();

require_once '../includes/sidebar.php';

// Database connection
if (!file_exists('../config/db.php')) {
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
} else {
    require_once '../config/db.php';
}

// -------------------------------------------------------------------------
// SCHEMA SETUP
// -------------------------------------------------------------------------
function checkAndCreateTables($pdo) {
    // 1. Benefit Renewal Tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS benefit_renewals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        provider_id INT NOT NULL,
        current_period_start DATE,
        current_period_end DATE,
        renewal_deadline DATE,
        renewal_status ENUM('Active', 'Negotiating', 'Renewed', 'Expiring', 'Cancelled') DEFAULT 'Active',
        premium_change_percent DECIMAL(5,2) DEFAULT 0.00,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Provider Coordination Logs
    $pdo->exec("CREATE TABLE IF NOT EXISTS provider_coordination_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        provider_id INT NOT NULL,
        log_date DATE,
        subject VARCHAR(150),
        description TEXT,
        type ENUM('Meeting', 'Email', 'Phone Call', 'Portal Choice', 'Issue Report') DEFAULT 'Email',
        status ENUM('Open', 'Resolved', 'Pending', 'Closed') DEFAULT 'Open',
        logged_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 3. Plan Version History
    $pdo->exec("CREATE TABLE IF NOT EXISTS plan_versions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        version_name VARCHAR(100),
        effective_date DATE,
        changes_summary TEXT,
        previous_annual_limit DECIMAL(15,2),
        new_annual_limit DECIMAL(15,2),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed Renewal Data if empty
    $checkRenewals = $pdo->query("SELECT COUNT(*) FROM benefit_renewals")->fetchColumn();
    if ($checkRenewals == 0) {
        // Get existing plans to link
        $plans = $pdo->query("SELECT id, provider_id FROM hmo_plans LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        foreach($plans as $p) {
            $pdo->prepare("INSERT INTO benefit_renewals (plan_id, provider_id, current_period_start, current_period_end, renewal_deadline, renewal_status) 
                VALUES (?, ?, '2025-01-01', '2025-12-31', '2025-11-30', 'Active')")->execute([$p['id'], $p['provider_id']]);
        }
    }
}
checkAndCreateTables($pdo);

// -------------------------------------------------------------------------
// ACTION HANDLERS
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // LOG COORDINATION
    if (isset($_POST['log_coordination'])) {
        $prov_id = $_POST['provider_id'];
        $date = $_POST['log_date'];
        $subject = $_POST['subject'];
        $desc = $_POST['description'];
        $type = $_POST['type'];
        
        $stmt = $pdo->prepare("INSERT INTO provider_coordination_logs (provider_id, log_date, subject, description, type, logged_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$prov_id, $date, $subject, $desc, $type, $_SESSION['user_id'] ?? 0]);
        
        $_SESSION['success_message'] = "Coordination log added successfully.";
        header("Location: benefits-provider-coor.php"); 
        exit;
    }

    // UPDATE RENEWAL STATUS
    if (isset($_POST['update_renewal'])) {
        $id = $_POST['renewal_id'];
        $status = $_POST['renewal_status'];
        $notes = $_POST['notes'];
        
        $stmt = $pdo->prepare("UPDATE benefit_renewals SET renewal_status = ?, notes = ? WHERE id = ?");
        $stmt->execute([$status, $notes, $id]);
        
        $_SESSION['success_message'] = "Renewal status updated.";
        header("Location: benefits-provider-coor.php");
        exit;
    }
}

// -------------------------------------------------------------------------
// DATA FETCHING
// -------------------------------------------------------------------------

// Fetch Renewals
$renewalsQuery = "
    SELECT r.*, 
           p.plan_name, 
           pr.provider_name 
    FROM benefit_renewals r
    JOIN hmo_plans p ON r.plan_id = p.id
    JOIN hmo_providers pr ON r.provider_id = pr.id
    ORDER BY r.renewal_deadline ASC
";
try {
    $renewals = $pdo->query($renewalsQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $renewals = []; }

// Fetch Logs
$logsQuery = "
    SELECT l.*, 
           pr.provider_name 
    FROM provider_coordination_logs l
    JOIN hmo_providers pr ON l.provider_id = pr.id
    ORDER BY l.log_date DESC, l.id DESC
";
try {
    $logs = $pdo->query($logsQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $logs = []; }

// Fetch Providers for Dropdown
$providers = $pdo->query("SELECT id, provider_name FROM hmo_providers WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);

// Stats
$upcomingRenewals = 0;
$today = date('Y-m-d');
$next90days = date('Y-m-d', strtotime('+90 days'));

foreach($renewals as $r) {
    if($r['renewal_deadline'] >= $today && $r['renewal_deadline'] <= $next90days) {
        $upcomingRenewals++;
    }
}

$openIssues = 0;
foreach($logs as $l) {
    if($l['status'] == 'Open' || $l['status'] == 'Pending') {
        $openIssues++;
    }
}

$activeNegotiations = 0;
foreach($renewals as $r) {
    if($r['renewal_status'] == 'Negotiating') {
        $activeNegotiations++;
    }
}

$currentTheme = $_SESSION['theme'] ?? 'light';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renewal & Coordination | HR System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Shared Styles from leave-welfare.php -->
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
            --purple-color: #6f42c1;
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
            --secondary-color: #2c3e50;
        }

        .filters-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: all 0.3s;
        }
        body.dark-mode .filters-container { background: var(--dark-card); }

        .btn {
            padding: 0.375rem 0.75rem; border: none; border-radius: var(--border-radius); cursor: pointer;
            font-weight: 600; transition: all 0.3s; display: inline-flex; align-items: center; gap: 0.5rem;
            text-decoration: none; font-size: 0.875rem;
        }
        .btn-primary { background: var(--primary-color); color: white; }
        .btn-primary:hover { background: #2e59d9; transform: translateY(-1px); }
        .btn-purple { background: var(--purple-color); color: white; }
        .btn-purple:hover { background: #5a32a3; color: white; transform: translateY(-1px); }
        .btn-warning { background: var(--warning-color); color: white; }

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

        .badge { padding: 5px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; color: white; }
        .bg-Active { background: var(--success-color); }
        .bg-Expiring { background: var(--danger-color); }
        .bg-Negotiating { background: var(--warning-color); }
        .bg-Resolved { background: var(--success-color); }
        .bg-Open { background: var(--danger-color); }
        .bg-Pending { background: var(--warning-color); }
        .bg-Meeting { background: var(--info-color); }
        .bg-Email { background: var(--secondary-color); color: #333; border: 1px solid #ccc; }

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
        
        .form-label { font-weight: 600; font-size: 0.9rem; }
    </style>
</head>
<body class="<?php echo $currentTheme === 'dark' ? 'dark-mode' : ''; ?>">

    <div class="main-content">
        
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title"><i class="fas fa-handshake"></i> Renewal & Provider Coordination</h1>
                <p class="page-subtitle">Track contract renewals, communication logs, and plan versioning</p>
            </div>
            <div>
                 <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#logCoordinationModal">
                    <i class="fas fa-file-signature"></i> Log Coordination
                </button>
            </div>
        </div>

        <!-- Alerts -->
        <?php if(isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Row -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Renewals (Next 90 Days)</h6>
                    <h3 class="text-danger"><?php echo $upcomingRenewals; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Active Negotiations</h6>
                    <h3 class="text-warning"><?php echo $activeNegotiations; ?></h3>
                </div>
            </div>
            <div class="col-md-4">
                <div class="filters-container text-center h-100 d-flex flex-column justify-content-center">
                    <h6 class="text-uppercase text-muted" style="font-size: 0.8rem;">Open Issues/Tickets</h6>
                    <h3 class="text-primary"><?php echo $openIssues; ?></h3>
                </div>
            </div>
        </div>

        <!-- Content Row -->
        <div class="row">
            
            <!-- Renewal Tracking -->
            <div class="col-lg-8 mb-4">
                <div class="report-card">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-calendar-alt"></i> Plan Renewal Status</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Provider & Plan</th>
                                    <th>Renewal Deadline</th>
                                    <th>Period End</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($renewals as $rn): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($rn['provider_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($rn['plan_name']); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $deadline = strtotime($rn['renewal_deadline']);
                                        $isUrgent = ($deadline <= strtotime('+60 days') && $rn['renewal_status'] != 'Renewed');
                                        ?>
                                        <span class="<?php echo $isUrgent ? 'text-danger fw-bold' : ''; ?>">
                                            <?php echo date('M d, Y', $deadline); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($rn['current_period_end'])); ?></td>
                                    <td><span class="badge bg-<?php echo $rn['renewal_status']; ?>"><?php echo $rn['renewal_status']; ?></span></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="editRenewal('<?php echo $rn['id']; ?>', '<?php echo $rn['renewal_status']; ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

             <!-- Coordination Logs -->
             <div class="col-lg-4 mb-4">
                <div class="report-card" style="border-left-color: var(--info-color);">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-history"></i> Communication Logs</h3>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Subject</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><small><?php echo date('M d', strtotime($log['log_date'])); ?></small></td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($log['subject']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($log['provider_name']); ?></small>
                                    </td>
                                    <td><span class="badge bg-<?php echo $log['status']; ?>" style="font-size:0.6rem;"><?php echo $log['status']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Version History Placeholder -->
            <div class="col-lg-12">
                <div class="report-card" style="border-left-color: #6f42c1;">
                    <div class="report-card-header">
                        <h3 class="report-card-title"><i class="fas fa-code-branch"></i> Plan Version History</h3>
                    </div>
                    <p class="text-muted text-center p-3">No version history records found. Plan changes will appear here.</p>
                </div>
            </div>

        </div>
    </div>

    <!-- Modal: Log Coordination -->
    <div class="modal fade" id="logCoordinationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Log Provider Interaction</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Provider</label>
                            <select name="provider_id" class="form-select" required>
                                <?php foreach ($providers as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['provider_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select name="type" class="form-select">
                                <option>Meeting</option>
                                <option>Email</option>
                                <option>Phone Call</option>
                                <option>Issue Report</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date</label>
                            <input type="date" name="log_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <input type="text" name="subject" class="form-control" required placeholder="e.g. Renewal Discussion">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description / Notes</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="log_coordination" class="btn btn-primary">Save Log</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Update Renewal -->
    <div class="modal fade" id="updateRenewalModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="renewal_id" id="editRenewalId">
                    <div class="modal-header">
                        <h5 class="modal-title">Update Renewal Status</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="renewal_status" id="editRenewalStatus" class="form-select">
                                <option value="Active">Active</option>
                                <option value="Negotiating">Negotiating</option>
                                <option value="Expiring">Expiring</option>
                                <option value="Renewed">Renewed</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Update notes..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_renewal" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editRenewal(id, status) {
            document.getElementById('editRenewalId').value = id;
            document.getElementById('editRenewalStatus').value = status;
            new bootstrap.Modal(document.getElementById('updateRenewalModal')).show();
        }
    </script>
</body>
</html>
