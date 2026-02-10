<?php
// provider-dashboard.php - Provider Admin Dashboard
session_start();

// Check if provider is logged in
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header('Location: provider-login-portal.php');
    exit;
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: provider-login-portal.php");
    exit;
}

// Check if user has correct role
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'provider') {
    header('Location: provider-login-portal.php');
    exit;
}

// Start output buffering
ob_start();

// Include database configuration
$dbConfigPath = __DIR__ . '/../config/db.php';
if (file_exists($dbConfigPath)) {
    require_once $dbConfigPath;
} else {
    $pdo = null;
    $error_message = "Database configuration not found.";
}

// Get provider details from session
$provider_id = $_SESSION['provider_id'] ?? 0;
$provider_name = $_SESSION['provider_name'] ?? 'Provider';
$user_email = $_SESSION['user_email'] ?? '';

// Initialize variables
$error_message = $error_message ?? '';
$success_message = '';
$enrollments = [];
$dependents = [];
$hmo_provider_id = 0; // The ID in the HR system's hmo_providers table
$hmo_details = []; // Details from hmo_providers table
$plans = []; // Active plans
$portal_messages = [];

// Statistics
$stats = [
    'total_plans' => 0,
    'total_enrollments' => 0,
    'total_dependents' => 0,
    'total_documents' => 0,
    'new_messages' => 0
];

if ($pdo instanceof PDO) {
    try {
        // 1. Get Local Provider Details
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
        $stmt->execute([$provider_id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($provider) {
            // Update session data
            $_SESSION['provider_name'] = $provider['provider_name'];
            $provider_name = $provider['provider_name'];

            // 2. Identify/Sync with HR System (hmo_providers table)
            // We link by provider_name which is unique
            $hmoStmt = $pdo->prepare("SELECT * FROM hmo_providers WHERE provider_name = ?");
            $hmoStmt->execute([$provider_name]);
            $hmo_details = $hmoStmt->fetch(PDO::FETCH_ASSOC);

            if ($hmo_details) {
                $hmo_provider_id = $hmo_details['id'];
            } else {
                // Auto-create if missing in HR system
                $ins = $pdo->prepare("INSERT INTO hmo_providers (provider_name, contact_person, contact_email, status) VALUES (?, ?, ?, 'Active')");
                $ins->execute([$provider_name, $provider['contact_person'], $provider['email']]);
                $hmo_provider_id = $pdo->lastInsertId();
                // Fetch again
                $hmoStmt->execute([$provider_name]);
                $hmo_details = $hmoStmt->fetch(PDO::FETCH_ASSOC);
            }

            // 3. Fetch Plans (from plans table - local view)
            $plans_stmt = $pdo->prepare("SELECT * FROM plans WHERE provider_id = ? AND status = 'Active'");
            $plans_stmt->execute([$provider_id]);
            $plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);
            $stats['total_plans'] = count($plans);
            
            // 4. Fetch Documents (from benefit_documents - local view)
            $docs_stmt = $pdo->prepare("SELECT * FROM benefit_documents WHERE provider_id = ?");
            $docs_stmt->execute([$provider_id]);
            $documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
            $stats['total_documents'] = count($documents);

            // 5. Fetch Enrollments (REAL TIME from HR System: employee_hmo_enrollments)
            // We join hmo_plans to filter by provider_id matches our hmo_provider_id
            $enr_sql = "
                SELECT e.*, 
                       emp.name as employee_name, emp.department, 
                       p.plan_name, p.annual_limit, p.plan_category
                FROM employee_hmo_enrollments e
                JOIN employees emp ON e.employee_id = emp.id
                JOIN hmo_plans p ON e.plan_id = p.id
                WHERE p.provider_id = ?
                ORDER BY e.created_at DESC
            ";
            $enr_stmt = $pdo->prepare($enr_sql);
            $enr_stmt->execute([$hmo_provider_id]);
            $enrollments = $enr_stmt->fetchAll(PDO::FETCH_ASSOC);
            $stats['total_enrollments'] = count($enrollments);

            // 6. Fetch Dependents (Real Time: enrolled_dependents)
            $dep_sql = "
                SELECT d.*, 
                       emp.name as employee_name,
                       p.plan_name
                FROM enrolled_dependents d
                JOIN employee_hmo_enrollments e ON d.enrollment_id = e.id
                JOIN employees emp ON e.employee_id = emp.id
                JOIN hmo_plans p ON e.plan_id = p.id
                WHERE p.provider_id = ?
                ORDER BY emp.name
            ";
            $dep_stmt = $pdo->prepare($dep_sql);
            $dep_stmt->execute([$hmo_provider_id]);
            $dependents = $dep_stmt->fetchAll(PDO::FETCH_ASSOC);
            $stats['total_dependents'] = count($dependents);

             // 7. Messages (Portal Coordination)
             $msgs_stmt = $pdo->prepare("SELECT * FROM provider_coordination WHERE provider_name = ? AND coordination_type = 'Portal' ORDER BY concern_date DESC");
             $msgs_stmt->execute([$provider_name]);
             $portal_messages = $msgs_stmt->fetchAll(PDO::FETCH_ASSOC);
             $stats['new_messages'] = count($portal_messages);

        } else {
            session_destroy();
            header('Location: provider-login-portal.php');
            exit;
        }

    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Manage Enrollments (Approve/Decline/Suspend)
    if(isset($_POST['manage_enrollment'])) {
        try {
            $status = $_POST['status'];
            $remarks = $_POST['remarks'];
            $enrollment_id = $_POST['enrollment_id'];
            $card_number = $_POST['card_number'] ?? '';
            
            $sql = "UPDATE employee_hmo_enrollments SET status = ?, remarks = ?, card_number = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$status, $remarks, $card_number, $enrollment_id]);
            
            $_SESSION['success_message'] = "Enrollment status updated to $status.";
            header('Location: ' . $_SERVER['PHP_SELF'] . '#enrollments'); // Keep tab active
            exit;
        } catch(PDOException $e) {
            $_SESSION['error_message'] = "Error updating enrollment: " . $e->getMessage();
        }
    }
    
    // Update Provider Profile (Sync to HMO-Plan)
    if (isset($_POST['update_provider_info'])) {
        try {
            $pdo->beginTransaction();
            
            // 1. Update Local 'providers' table
            $localSql = "UPDATE providers SET contact_person=?, email=?, phone=?, website=?, address=? WHERE id=?";
            $stmt = $pdo->prepare($localSql);
            $stmt->execute([
                $_POST['contact_person'],
                $_POST['email'],
                $_POST['phone'],
                $_POST['website'],
                $_POST['address'],
                $provider_id
            ]);

            // 2. Update HR System 'hmo_providers' table
            // We match by provider_name (as it's the stable key) and ID if possible
            $hrSql = "UPDATE hmo_providers SET 
                        contact_person=?, contact_email=?, contact_number=?, website=?, address=?,
                        portal_url=?, client_services_email=?, claims_email=?, emergency_hotline=?,
                        coverage_areas=?, accreditation_date=?
                      WHERE id=?"; // Use hmo_provider_id derived earlier
            
            $stmtHR = $pdo->prepare($hrSql);
            $stmtHR->execute([
                $_POST['contact_person'],
                $_POST['email'],
                $_POST['phone'],
                $_POST['website'],
                $_POST['address'],
                $_POST['portal_url'],
                $_POST['client_services_email'],
                $_POST['claims_email'],
                $_POST['emergency_hotline'],
                $_POST['coverage_areas'],
                $_POST['accreditation_date'] ?: null,
                $hmo_provider_id
            ]);

            $pdo->commit();
            $_SESSION['success_message'] = "Profile updated and synchronized with HR System!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error updating profile: " . $e->getMessage();
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Add Plan (Existing Logic + Sync)
    if (isset($_POST['add_plan'])) {
        addPlan($pdo, $provider_id, $_POST);
    }
    
    // Delete Plan
    if (isset($_POST['delete_plan'])) {
        try {
            $pdo->prepare("UPDATE plans SET status = 'Inactive' WHERE id=?")->execute([$_POST['id']]);
            $_SESSION['success_message'] = "Plan deleted.";
        } catch(Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Helper Functions
function addPlan($pdo, $provider_id, $data) {
    try {
        $pdo->beginTransaction();

        // 1. Local Insert
        $sql = "INSERT INTO plans (provider_id, plan_name, annual_limit, premium_employee, status) VALUES (?, ?, ?, ?, 'Active')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$provider_id, $data['plan_name'], $data['annual_limit'], $data['premium_employee']]);
        
        // 2. Sync to HMO Plans
        // Get HMO Provider ID
        $prov = $pdo->prepare("SELECT provider_name FROM providers WHERE id=?");
        $prov->execute([$provider_id]);
        $pName = $prov->fetchColumn();
        
        $hmoProvStmt = $pdo->prepare("SELECT id FROM hmo_providers WHERE provider_name=?");
        $hmoProvStmt->execute([$pName]);
        $hmoID = $hmoProvStmt->fetchColumn();
        
        if($hmoID) {
            $ins = $pdo->prepare("INSERT INTO hmo_plans (provider_id, plan_name, annual_limit, total_premium, employee_share, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            $ins->execute([$hmoID, $data['plan_name'], $data['annual_limit'] ?? 0, $data['premium_employee'] ?? 0, $data['premium_employee'] ?? 0]);
        }
        
        $pdo->commit();
        $_SESSION['success_message'] = "Plan added and synced.";
    } catch(Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Dashboard | <?php echo htmlspecialchars($provider_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #4e73df; }
        body { background-color: #f8f9fc; font-family: 'Segoe UI', sans-serif; }
        .dashboard-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .card { border: none; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15); margin-bottom: 20px; }
        .nav-tabs .nav-link { color: #6c757d; font-weight: 600; }
        .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 3px solid var(--primary-color); background: transparent; }
        .stat-card { padding: 20px; border-radius: 10px; color: white; margin-bottom: 20px; }
        .bg-primary-gradient { background: linear-gradient(45deg, #4e73df, #224abe); }
        .bg-success-gradient { background: linear-gradient(45deg, #1cc88a, #13855c); }
        .bg-info-gradient { background: linear-gradient(45deg, #36b9cc, #258391); }
        .bg-warning-gradient { background: linear-gradient(45deg, #f6c23e, #dda20a); }
    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 bg-white p-4 rounded shadow-sm">
        <div>
            <h2 class="text-primary mb-0"><i class="fas fa-hospital-user"></i> Provider Portal</h2>
            <p class="text-muted mb-0">Managed by Slate Freight HR System</p>
        </div>
        <div class="text-end">
            <h5 class="mb-0"><?php echo htmlspecialchars($provider_name); ?></h5>
            <small class="text-success"><i class="fas fa-circle"></i> Active</small><br>
            <a href="?logout=true" class="btn btn-sm btn-danger mt-2">Logout</a>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row">
        <div class="col-md-3">
            <div class="stat-card bg-primary-gradient">
                <h3><?php echo $stats['total_plans']; ?></h3>
                <span>Active Plans</span>
                <i class="fas fa-file-medical float-end fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-success-gradient">
                <h3><?php echo $stats['total_enrollments']; ?></h3>
                <span>Total Enrolled</span>
                <i class="fas fa-users float-end fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-info-gradient">
                <h3><?php echo $stats['total_dependents']; ?></h3>
                <span>Dependents</span>
                <i class="fas fa-child float-end fa-2x opacity-50"></i>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card bg-warning-gradient">
                <h3><?php echo $stats['new_messages']; ?></h3>
                <span>New Messages</span>
                <i class="fas fa-envelope float-end fa-2x opacity-50"></i>
            </div>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="card">
        <div class="card-body">
            <ul class="nav nav-tabs mb-4" id="dashboardTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#plans">Manage Plans</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#enrollments">Enrollments & Employees</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#profile">Profile Settings</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#messages">Messages</button></li>
            </ul>

            <div class="tab-content" id="dashboardTabContent">
                
                <!-- Plans Tab -->
                <div class="tab-pane fade show active" id="plans">
                    <form method="POST" class="mb-4 p-3 bg-light rounded">
                        <input type="hidden" name="add_plan" value="1">
                        <h5 class="mb-3">Add New Plan</h5>
                        <div class="row g-3">
                            <div class="col-md-4"><input type="text" name="plan_name" class="form-control" placeholder="Plan Name (e.g. Gold Health Plus)" required></div>
                            <div class="col-md-3"><input type="number" name="annual_limit" class="form-control" placeholder="Annual Limit (PHP)"></div>
                            <div class="col-md-3"><input type="number" name="premium_employee" class="form-control" placeholder="Premium Amount"></div>
                            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Add Plan</button></div>
                        </div>
                    </form>
                    <table class="table table-hover">
                        <thead><tr><th>Plan Name</th><th>Limit</th><th>Premium</th><th>Status</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach($plans as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['plan_name']); ?></td>
                                    <td>₱<?php echo number_format($p['annual_limit'], 2); ?></td>
                                    <td>₱<?php echo number_format($p['premium_employee'], 2); ?></td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm('Delete plan?');">
                                            <input type="hidden" name="delete_plan" value="1">
                                            <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Enrollments Tab (Syncs with HR) -->
                <div class="tab-pane fade" id="enrollments">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Plan</th>
                                    <th>Eff. Date</th>
                                    <th>Card #</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($enrollments as $enr): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($enr['employee_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($enr['department'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($enr['plan_name']); ?></td>
                                    <td><?php echo $enr['effective_date'] ? date('M d, Y', strtotime($enr['effective_date'])) : 'Pending'; ?></td>
                                    <td><?php echo htmlspecialchars($enr['card_number'] ?? 'Not Issued'); ?></td>
                                    <td>
                                        <?php 
                                            $badge = 'bg-secondary';
                                            if($enr['status']=='Active') $badge = 'bg-success';
                                            if($enr['status']=='Pending') $badge = 'bg-warning';
                                            if($enr['status']=='Suspended') $badge = 'bg-danger';
                                        ?>
                                        <span class="badge <?php echo $badge; ?>"><?php echo $enr['status']; ?></span>
                                    </td>
                                    <td>
                                        <!-- Open Modal with unique ID -->
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#manageEnr<?php echo $enr['id']; ?>">
                                            Manage
                                        </button>
                                        
                                        <!-- Modal -->
                                        <div class="modal fade" id="manageEnr<?php echo $enr['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <form method="POST">
                                                        <input type="hidden" name="manage_enrollment" value="1">
                                                        <input type="hidden" name="enrollment_id" value="<?php echo $enr['id']; ?>">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title">Manage Coverage: <?php echo htmlspecialchars($enr['employee_name']); ?></h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <div class="mb-3">
                                                                <label class="form-label">Coverage Status</label>
                                                                <select name="status" class="form-select">
                                                                    <option value="Active" <?php if($enr['status']=='Active') echo 'selected'; ?>>Active</option>
                                                                    <option value="Pending" <?php if($enr['status']=='Pending') echo 'selected'; ?>>Pending Approval</option>
                                                                    <option value="Suspended" <?php if($enr['status']=='Suspended') echo 'selected'; ?>>Suspended</option>
                                                                    <option value="Cancelled" <?php if($enr['status']=='Cancelled') echo 'selected'; ?>>Cancelled</option>
                                                                </select>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Card Number</label>
                                                                <input type="text" name="card_number" class="form-control" value="<?php echo htmlspecialchars($enr['card_number'] ?? ''); ?>" placeholder="Enter Card #">
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Remarks</label>
                                                                <textarea name="remarks" class="form-control" placeholder="Add remarks..."><?php echo htmlspecialchars($enr['remarks'] ?? ''); ?></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="submit" class="btn btn-primary">Update Status</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Profile Tab -->
                <div class="tab-pane fade" id="profile">
                    <form method="POST">
                        <input type="hidden" name="update_provider_info" value="1">
                        <div class="row g-3">
                            <div class="col-12"><h5 class="border-bottom pb-2">Basic Info</h5></div>
                            <div class="col-md-6">
                                <label class="form-label">Contact Person</label>
                                <input type="text" name="contact_person" class="form-control" value="<?php echo htmlspecialchars($provider['contact_person'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($provider['email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone</label>
                                <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($provider['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Website</label>
                                <input type="text" name="website" class="form-control" value="<?php echo htmlspecialchars($provider['website'] ?? ''); ?>">
                            </div>
                             <div class="col-12">
                                <label class="form-label">Address</label>
                                <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($provider['address'] ?? ''); ?>">
                            </div>

                            <div class="col-12 mt-4"><h5 class="border-bottom pb-2">Portal Links & Contacts (Visible to HR)</h5></div>
                            <div class="col-md-6">
                                <label class="form-label">Client Portal URL</label>
                                <input type="text" name="portal_url" class="form-control" value="<?php echo htmlspecialchars($hmo_details['portal_url'] ?? ''); ?>" placeholder="https://portal.provider.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client Services Email</label>
                                <input type="email" name="client_services_email" class="form-control" value="<?php echo htmlspecialchars($hmo_details['client_services_email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Claims Submission Email</label>
                                <input type="email" name="claims_email" class="form-control" value="<?php echo htmlspecialchars($hmo_details['claims_email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Emergency Hotline</label>
                                <input type="text" name="emergency_hotline" class="form-control" value="<?php echo htmlspecialchars($hmo_details['emergency_hotline'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Accreditation Date</label>
                                <input type="date" name="accreditation_date" class="form-control" value="<?php echo htmlspecialchars($hmo_details['accreditation_date'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Coverage Areas</label>
                                <textarea name="coverage_areas" class="form-control"><?php echo htmlspecialchars($hmo_details['coverage_areas'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="col-12 mt-3">
                                <button class="btn btn-primary" type="submit">Update & Sync Info</button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Messages Tab -->
                <div class="tab-pane fade" id="messages">
                    <?php if(!empty($portal_messages)): ?>
                        <?php foreach($portal_messages as $msg): ?>
                            <div class="alert alert-info">
                                <strong><?php echo htmlspecialchars($msg['subject']); ?></strong>
                                <p><?php echo htmlspecialchars($msg['details']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No new messages.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>