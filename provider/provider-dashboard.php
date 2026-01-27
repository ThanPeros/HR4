<?php
// provider-dashboard.php - Provider Admin Dashboard
session_start(); // Move session_start() to the very top

// Check if provider is logged in - FIXED SESSION CHECK
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    // Debug: Check what session variables exist
    error_log("Dashboard: User not logged in or session expired");
    header('Location: provider-login-portal.php');
    exit;
}

// Check if user has correct role
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'provider') {
    error_log("Dashboard: Wrong user role - " . ($_SESSION['user_role'] ?? 'No role'));
    header('Location: provider-login-portal.php');
    exit;
}

// Check if provider_id is set
if (!isset($_SESSION['provider_id'])) {
    error_log("Dashboard: No provider_id in session");
    header('Location: provider-login-portal.php');
    exit;
}

// Now start output buffering after session checks
ob_start();

// Include database configuration
$dbConfigPath = __DIR__ . '/../config/db.php';
if (file_exists($dbConfigPath)) {
    require_once $dbConfigPath;
} else {
    // If no config file, initialize $pdo as null
    $pdo = null;
    $error_message = "Database configuration not found. Please contact system administrator.";
}

// Get provider details from session
$provider_id = $_SESSION['provider_id'] ?? 0;
$provider_name = $_SESSION['provider_name'] ?? 'Provider';
$user_email = $_SESSION['user_email'] ?? '';

// Initialize variables
$error_message = $error_message ?? '';
$success_message = '';
$provider = null;
$plans = [];
$documents = [];
$enrollments = [];
$dependents = [];
$stats = [
    'total_plans' => 0,
    'total_enrollments' => 0,
    'total_dependents' => 0,
    'total_documents' => 0,
    'total_coverage' => 0
];

// Try to fetch data if database is available
if ($pdo instanceof PDO) {
    try {
        // First, ensure the provider is active
        $activate_stmt = $pdo->prepare("UPDATE providers SET status = 'Active', portal_access = 'enabled', portal_status = 'active' WHERE id = ?");
        $activate_stmt->execute([$provider_id]);

        // Fetch provider details
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
        $stmt->execute([$provider_id]);
        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$provider) {
            // Provider not found in database
            $error_message = "Provider account not found in database.";
            session_destroy();
            header('Location: provider-login-portal.php');
            exit;
        }

        // Check if provider is active
        if ($provider['status'] !== 'Active') {
            // Try to activate the provider
            $activate_provider = $pdo->prepare("UPDATE providers SET status = 'Active' WHERE id = ?");
            $activate_provider->execute([$provider_id]);

            // Fetch again
            $stmt->execute([$provider_id]);
            $provider = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Update provider name from database (in case it changed)
        $_SESSION['provider_name'] = $provider['provider_name'];

        // Update user email from database (in case it changed)
        $_SESSION['user_email'] = $provider['email'];

        // Try to fetch provider's plans
        try {
            $plans_stmt = $pdo->prepare("SELECT * FROM plans WHERE provider_id = ? AND status = 'Active' ORDER BY plan_name");
            $plans_stmt->execute([$provider_id]);
            $plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Table might not exist, that's OK
            error_log("Plans table error: " . $e->getMessage());
            $plans = [];
        }

        // Try to fetch provider's documents
        try {
            $docs_stmt = $pdo->prepare("SELECT * FROM benefit_documents WHERE provider_id = ? ORDER BY document_type, document_name");
            $docs_stmt->execute([$provider_id]);
            $documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Documents table error: " . $e->getMessage());
            $documents = [];
        }

        // Try to fetch enrolled employees
        try {
            // Check if employee_enrollments table exists
            $table_check = $pdo->prepare("SHOW TABLES LIKE 'employee_enrollments'");
            $table_check->execute();

            if ($table_check->rowCount() > 0) {
                $enrollments_stmt = $pdo->prepare("
                    SELECT ee.*, e.name as employee_name, e.department, e.email as employee_email, 
                           pl.plan_name, pl.annual_limit, pl.premium_employee, pl.premium_dependent
                    FROM employee_enrollments ee 
                    LEFT JOIN employees e ON ee.employee_id = e.id 
                    LEFT JOIN plans pl ON ee.plan_id = pl.id 
                    WHERE ee.provider_id = ? 
                    ORDER BY e.name
                ");
                $enrollments_stmt->execute([$provider_id]);
                $enrollments = $enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("Enrollments query error: " . $e->getMessage());
            $enrollments = [];
        }

        // Try to fetch dependents
        try {
            // Check if dependents table exists
            $table_check = $pdo->prepare("SHOW TABLES LIKE 'dependents'");
            $table_check->execute();

            if ($table_check->rowCount() > 0) {
                $dependents_stmt = $pdo->prepare("
                    SELECT d.*, e.name as employee_name, e.department
                    FROM dependents d 
                    JOIN employees e ON d.employee_id = e.id 
                    JOIN employee_enrollments ee ON d.employee_id = ee.employee_id 
                    WHERE ee.provider_id = ? AND d.status = 'Active'
                    ORDER BY e.name, d.name
                ");
                $dependents_stmt->execute([$provider_id]);
                $dependents = $dependents_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log("Dependents query error: " . $e->getMessage());
            $dependents = [];
        }

        // Calculate statistics
        $stats = [
            'total_plans' => count($plans),
            'total_enrollments' => count($enrollments),
            'total_dependents' => count($dependents),
            'total_documents' => count($documents),
            'total_coverage' => count($enrollments) + count($dependents)
        ];
    } catch (PDOException $e) {
        error_log("Dashboard database error: " . $e->getMessage());
        $error_message = "Database connection error. Please try again later.";
    }
} else {
    $error_message = "Database not available. Please contact system administrator.";
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: provider-login-portal.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_plan'])) {
        addPlan($pdo, $provider_id, $_POST);
    } elseif (isset($_POST['update_plan'])) {
        updatePlan($pdo, $_POST);
    } elseif (isset($_POST['delete_plan'])) {
        deletePlan($pdo, $_POST['id']);
    } elseif (isset($_POST['add_document'])) {
        addDocument($pdo, $provider_id, $_POST);
    } elseif (isset($_POST['delete_document'])) {
        deleteDocument($pdo, $_POST['id']);
    } elseif (isset($_POST['update_provider_info'])) {
        updateProviderInfo($pdo, $provider_id, $_POST);
    }
}

// Plan Management Functions
function addPlan($pdo, $provider_id, $data)
{
    if (!$pdo) {
        $_SESSION['error_message'] = "Database not available for adding plan.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $sql = "INSERT INTO plans (provider_id, plan_name, annual_limit, room_board_type, premium_employee, premium_dependent, 
                               coverage_outpatient, coverage_inpatient, coverage_emergency, coverage_dental, coverage_vision, 
                               addon_benefits, exclusions, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $provider_id,
            $data['plan_name'],
            $data['annual_limit'] ?? 0,
            $data['room_board_type'] ?? '',
            $data['premium_employee'] ?? 0,
            $data['premium_dependent'] ?? 0,
            $data['coverage_outpatient'] ?? '',
            $data['coverage_inpatient'] ?? '',
            $data['coverage_emergency'] ?? '',
            $data['coverage_dental'] ?? '',
            $data['coverage_vision'] ?? '',
            $data['addon_benefits'] ?? '',
            $data['exclusions'] ?? ''
        ]);

        $_SESSION['success_message'] = "Plan added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding plan: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function updatePlan($pdo, $data)
{
    if (!$pdo) {
        $_SESSION['error_message'] = "Database not available for updating plan.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $sql = "UPDATE plans SET plan_name=?, annual_limit=?, room_board_type=?, premium_employee=?, premium_dependent=?,
                           coverage_outpatient=?, coverage_inpatient=?, coverage_emergency=?, coverage_dental=?,
                           coverage_vision=?, addon_benefits=?, exclusions=? WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['plan_name'],
            $data['annual_limit'] ?? 0,
            $data['room_board_type'] ?? '',
            $data['premium_employee'] ?? 0,
            $data['premium_dependent'] ?? 0,
            $data['coverage_outpatient'] ?? '',
            $data['coverage_inpatient'] ?? '',
            $data['coverage_emergency'] ?? '',
            $data['coverage_dental'] ?? '',
            $data['coverage_vision'] ?? '',
            $data['addon_benefits'] ?? '',
            $data['exclusions'] ?? '',
            $data['id']
        ]);

        $_SESSION['success_message'] = "Plan updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating plan: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function deletePlan($pdo, $id)
{
    if (!$pdo) {
        $_SESSION['error_message'] = "Database not available for deleting plan.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Soft delete - set status to inactive
    $sql = "UPDATE plans SET status = 'Inactive' WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        $_SESSION['success_message'] = "Plan deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting plan: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Document Management Functions
function addDocument($pdo, $provider_id, $data)
{
    if (!$pdo) {
        $_SESSION['error_message'] = "Database not available for adding document.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $sql = "INSERT INTO benefit_documents (provider_id, document_name, document_type, description, uploaded_at) 
            VALUES (?, ?, ?, ?, NOW())";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $provider_id,
            $data['document_name'],
            $data['document_type'],
            $data['description'] ?? ''
        ]);

        $_SESSION['success_message'] = "Document added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding document: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function deleteDocument($pdo, $id)
{
    if (!$pdo) {
        $_SESSION['error_message'] = "Database not available for deleting document.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $sql = "DELETE FROM benefit_documents WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        $_SESSION['success_message'] = "Document deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting document: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Provider Info Update Function
function updateProviderInfo($pdo, $provider_id, $data)
{
    if (!$pdo) {
        $_SESSION['error_message'] = "Database not available for updating provider info.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $sql = "UPDATE providers SET contact_person=?, email=?, phone=?, address=?, 
                           city=?, state=?, zip_code=?, country=?, website=?, notes=? 
            WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['contact_person'],
            $data['email'],
            $data['phone'],
            $data['address'],
            $data['city'],
            $data['state'],
            $data['zip_code'],
            $data['country'],
            $data['website'],
            $data['notes'],
            $provider_id
        ]);

        // Update session info
        $_SESSION['user_email'] = $data['email'];
        $_SESSION['success_message'] = "Provider information updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating provider info: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Create provider portal accounts automatically if needed
if ($pdo instanceof PDO) {
    try {
        // Check if provider_portal_users table exists
        $table_check = $pdo->prepare("SHOW TABLES LIKE 'provider_portal_users'");
        $table_check->execute();

        if ($table_check->rowCount() > 0) {
            // Define provider usernames and emails
            $provider_credentials = [
                'Maxicare Healthcare Corporation' => [
                    'username' => 'maxicare',
                    'email' => 'maria.santos@maxicare.com.ph',
                    'full_name' => 'Maria Santos'
                ],
                'MediCard Philippines, Inc.' => [
                    'username' => 'medicard',
                    'email' => 'john.lim@medicardphils.com',
                    'full_name' => 'John Lim'
                ],
                'Intellicare' => [
                    'username' => 'intellicare',
                    'email' => 'ana.reyes@intellicare.com.ph',
                    'full_name' => 'Ana Reyes'
                ],
                'Philam Life' => [
                    'username' => 'philam',
                    'email' => 'robert.tan@philam.com',
                    'full_name' => 'Robert Tan'
                ],
                'Sun Life Grepa' => [
                    'username' => 'sunlife',
                    'email' => 'cynthia.gomez@sunlifegrepa.com',
                    'full_name' => 'Cynthia Gomez'
                ]
            ];

            foreach ($provider_credentials as $provider_name => $credential) {
                // Get provider ID
                $stmt = $pdo->prepare("SELECT id FROM providers WHERE provider_name = ?");
                $stmt->execute([$provider_name]);
                $provider = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($provider) {
                    $provider_id_db = $provider['id'];

                    // Check if account already exists
                    $check_stmt = $pdo->prepare("SELECT id FROM provider_portal_users WHERE provider_id = ? OR username = ? OR email = ?");
                    $check_stmt->execute([$provider_id_db, $credential['username'], $credential['email']]);

                    if ($check_stmt->rowCount() == 0) {
                        // Create new account with password "provider123"
                        $password_hash = password_hash('provider123', PASSWORD_DEFAULT);

                        $insert_stmt = $pdo->prepare("INSERT INTO provider_portal_users 
                            (provider_id, username, password, email, full_name, user_type, status, created_at) 
                            VALUES (?, ?, ?, ?, ?, 'provider', 'active', NOW())");

                        $insert_stmt->execute([
                            $provider_id_db,
                            $credential['username'],
                            $password_hash,
                            $credential['email'],
                            $credential['full_name']
                        ]);

                        error_log("Created portal account for: " . $provider_name);
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Error creating provider accounts: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Dashboard | <?php echo htmlspecialchars($provider_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4e73df;
            --success-color: #1cc88a;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --info-color: #36b9cc;
            --purple-color: #6f42c1;
            --dark-color: #5a5c69;
            --light-color: #f8f9fc;
            --border-radius: 0.35rem;
            --shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--light-color);
            color: var(--dark-color);
            line-height: 1.6;
        }

        .dashboard-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Header */
        .dashboard-header {
            background: white;
            box-shadow: var(--shadow);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .provider-logo {
            width: 40px;
            height: 40px;
            background: var(--primary-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
        }

        .header-title h1 {
            font-size: 1.5rem;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }

        .header-title p {
            font-size: 0.9rem;
            color: var(--dark-color);
            opacity: 0.8;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            color: var(--dark-color);
        }

        .user-email {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .user-status {
            font-size: 0.8rem;
            color: var(--success-color);
        }

        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: none;
            border-radius: var(--border-radius);
            padding: 0.5rem 1rem;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .logout-btn:hover {
            background: #dc3545;
            transform: translateY(-1px);
        }

        /* Main Content */
        .dashboard-main {
            flex: 1;
            padding: 2rem;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, var(--primary-color), #6f42c1);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .welcome-section h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-section p {
            opacity: 0.9;
            max-width: 800px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            border-left: 4px solid var(--primary-color);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2);
        }

        .stat-card.success {
            border-left-color: var(--success-color);
        }

        .stat-card.warning {
            border-left-color: var(--warning-color);
        }

        .stat-card.info {
            border-left-color: var(--info-color);
        }

        .stat-card.purple {
            border-left-color: var(--purple-color);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .stat-icon {
            float: right;
            font-size: 2rem;
            opacity: 0.2;
        }

        /* Tabs */
        .tabs-container {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .tabs-header {
            display: flex;
            border-bottom: 1px solid #e3e6f0;
            background: var(--light-color);
            flex-wrap: wrap;
        }

        .tab {
            padding: 1rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #6c757d;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab:hover {
            color: var(--primary-color);
            background: rgba(78, 115, 223, 0.05);
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: white;
        }

        .tab-content {
            display: none;
            padding: 2rem;
        }

        .tab-content.active {
            display: block;
        }

        /* Forms */
        .form-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e3e6f0;
        }

        .form-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark-color);
            font-size: 0.9rem;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e3e6f0;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e3e6f0;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2e59d9;
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #17a673;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #dc3545;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: var(--warning-color);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        .btn-info {
            background: var(--info-color);
            color: white;
        }

        .btn-info:hover {
            background: #2c9faf;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }

        /* Tables */
        .table-container {
            overflow-x: auto;
            margin: 1.5rem 0;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: 0 0 0 1px #e3e6f0;
        }

        .data-table th {
            background: var(--light-color);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--primary-color);
            border-bottom: 1px solid #e3e6f0;
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid #e3e6f0;
        }

        .data-table tr:hover {
            background: rgba(78, 115, 223, 0.05);
        }

        .amount {
            font-family: 'Courier New', monospace;
            font-weight: 600;
            text-align: right;
        }

        .actions-cell {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            border-color: var(--success-color);
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border-color: var(--danger-color);
            color: #721c24;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: var(--dark-color);
        }

        /* Debug Section */
        .debug-info {
            background: #f8f9fa;
            border: 1px solid #e3e6f0;
            border-radius: 0.35rem;
            padding: 1rem;
            margin: 1rem 0;
            font-family: monospace;
            font-size: 0.85rem;
            display: none;
        }

        .debug-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--info-color);
            color: white;
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            cursor: pointer;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow);
        }

        .debug-toggle:hover {
            background: #2c9faf;
            transform: scale(1.1);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 1rem;
                flex-direction: column;
                text-align: center;
            }

            .header-left,
            .header-right {
                width: 100%;
                justify-content: center;
            }

            .user-info {
                text-align: center;
            }

            .dashboard-main {
                padding: 1rem;
            }

            .tabs-header {
                flex-direction: column;
            }

            .tab {
                border-bottom: 1px solid #e3e6f0;
                border-left: 3px solid transparent;
            }

            .tab.active {
                border-left-color: var(--primary-color);
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .actions-cell {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .tab-content {
                padding: 1rem;
            }

            .welcome-section {
                padding: 1.5rem;
            }

            .welcome-section h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <!-- Debug toggle button -->
    <button class="debug-toggle" onclick="toggleDebug()">
        <i class="fas fa-bug"></i>
    </button>

    <!-- Debug info section -->
    <div class="debug-info" id="debugInfo">
        <h4>Session Debug Info:</h4>
        <pre><?php
                echo "Session ID: " . session_id() . "\n";
                echo "user_logged_in: " . ($_SESSION['user_logged_in'] ?? 'NOT SET') . "\n";
                echo "user_role: " . ($_SESSION['user_role'] ?? 'NOT SET') . "\n";
                echo "provider_id: " . ($_SESSION['provider_id'] ?? 'NOT SET') . "\n";
                echo "provider_name: " . ($_SESSION['provider_name'] ?? 'NOT SET') . "\n";
                echo "user_email: " . ($_SESSION['user_email'] ?? 'NOT SET') . "\n";
                ?></pre>
    </div>

    <div class="dashboard-container">
        <!-- Header -->
        <header class="dashboard-header">
            <div class="header-left">
                <div class="provider-logo">
                    <i class="fas fa-building"></i>
                </div>
                <div class="header-title">
                    <h1><?php echo htmlspecialchars($provider_name); ?> Portal</h1>
                    <p>Provider Administration Dashboard</p>
                </div>
            </div>

            <div class="header-right">
                <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($provider['contact_person'] ?? 'Provider Admin'); ?></div>
                    <div class="user-email"><?php echo htmlspecialchars($user_email); ?></div>
                    <div class="user-status">
                        <i class="fas fa-circle"></i> Online
                    </div>
                </div>
                <a href="?logout=true" class="logout-btn" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </header>

        <!-- Main Content -->
        <main class="dashboard-main">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h2><i class="fas fa-tachometer-alt"></i> Provider Dashboard</h2>
                <p>Welcome back! Manage your HMO/benefits plans, documents, and view enrollment statistics.</p>
                <?php if (!empty($error_message)): ?>
                    <p style="background: rgba(255,255,255,0.2); padding: 0.5rem; border-radius: 0.25rem; margin-top: 1rem;">
                        <i class="fas fa-info-circle"></i> <?php echo $error_message; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $stats['total_enrollments']; ?></div>
                    <div class="stat-label">Employees Enrolled</div>
                    <i class="fas fa-users stat-icon"></i>
                </div>
                <div class="stat-card success">
                    <div class="stat-value"><?php echo $stats['total_plans']; ?></div>
                    <div class="stat-label">Active Plans</div>
                    <i class="fas fa-file-medical stat-icon"></i>
                </div>
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo $stats['total_dependents']; ?></div>
                    <div class="stat-label">Dependents</div>
                    <i class="fas fa-user-friends stat-icon"></i>
                </div>
                <div class="stat-card info">
                    <div class="stat-value"><?php echo $stats['total_coverage']; ?></div>
                    <div class="stat-label">Total Coverage</div>
                    <i class="fas fa-heartbeat stat-icon"></i>
                </div>
            </div>

            <!-- Tabs Container -->
            <div class="tabs-container">
                <div class="tabs-header">
                    <button class="tab active" data-tab="plans">
                        <i class="fas fa-file-medical"></i> Plans
                    </button>
                    <button class="tab" data-tab="documents">
                        <i class="fas fa-file-alt"></i> Documents
                    </button>
                    <button class="tab" data-tab="enrollments">
                        <i class="fas fa-users"></i> Enrollments
                    </button>
                    <button class="tab" data-tab="provider-info">
                        <i class="fas fa-info-circle"></i> Provider Info
                    </button>
                    <button class="tab" data-tab="reports">
                        <i class="fas fa-chart-bar"></i> Reports
                    </button>
                </div>

                <!-- Plans Tab -->
                <div id="plans" class="tab-content active">
                    <!-- Add/Edit Plan Form -->
                    <div class="form-section">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-plus-circle"></i>
                                <?php echo isset($_GET['edit_plan']) ? 'Edit Plan' : 'Add New Plan'; ?>
                            </h2>
                        </div>
                        <form method="POST" action="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Plan Name *</label>
                                    <input type="text" name="plan_name" class="form-input" required
                                        value="<?php
                                                if (isset($_GET['edit_plan'])) {
                                                    foreach ($plans as $plan) {
                                                        if ($plan['id'] == $_GET['edit_plan']) {
                                                            echo htmlspecialchars($plan['plan_name']);
                                                            break;
                                                        }
                                                    }
                                                }
                                                ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Annual Limit (₱)</label>
                                    <input type="number" name="annual_limit" class="form-input" step="0.01" min="0"
                                        value="<?php
                                                if (isset($_GET['edit_plan'])) {
                                                    foreach ($plans as $plan) {
                                                        if ($plan['id'] == $_GET['edit_plan']) {
                                                            echo htmlspecialchars($plan['annual_limit'] ?? '0');
                                                            break;
                                                        }
                                                    }
                                                } else {
                                                    echo '0';
                                                }
                                                ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Room & Board Type</label>
                                    <select name="room_board_type" class="form-select">
                                        <option value="">Select Type</option>
                                        <option value="Private" <?php
                                                                if (isset($_GET['edit_plan'])) {
                                                                    foreach ($plans as $plan) {
                                                                        if ($plan['id'] == $_GET['edit_plan'] && $plan['room_board_type'] === 'Private') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                ?>>Private</option>
                                        <option value="Semi-Private" <?php
                                                                        if (isset($_GET['edit_plan'])) {
                                                                            foreach ($plans as $plan) {
                                                                                if ($plan['id'] == $_GET['edit_plan'] && $plan['room_board_type'] === 'Semi-Private') {
                                                                                    echo 'selected';
                                                                                    break;
                                                                                }
                                                                            }
                                                                        }
                                                                        ?>>Semi-Private</option>
                                        <option value="Ward" <?php
                                                                if (isset($_GET['edit_plan'])) {
                                                                    foreach ($plans as $plan) {
                                                                        if ($plan['id'] == $_GET['edit_plan'] && $plan['room_board_type'] === 'Ward') {
                                                                            echo 'selected';
                                                                            break;
                                                                        }
                                                                    }
                                                                }
                                                                ?>>Ward</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Employee Premium (₱)</label>
                                    <input type="number" name="premium_employee" class="form-input" step="0.01" min="0"
                                        value="<?php
                                                if (isset($_GET['edit_plan'])) {
                                                    foreach ($plans as $plan) {
                                                        if ($plan['id'] == $_GET['edit_plan']) {
                                                            echo htmlspecialchars($plan['premium_employee'] ?? '0');
                                                            break;
                                                        }
                                                    }
                                                } else {
                                                    echo '0';
                                                }
                                                ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Dependent Premium (₱)</label>
                                    <input type="number" name="premium_dependent" class="form-input" step="0.01" min="0"
                                        value="<?php
                                                if (isset($_GET['edit_plan'])) {
                                                    foreach ($plans as $plan) {
                                                        if ($plan['id'] == $_GET['edit_plan']) {
                                                            echo htmlspecialchars($plan['premium_dependent'] ?? '0');
                                                            break;
                                                        }
                                                    }
                                                } else {
                                                    echo '0';
                                                }
                                                ?>">
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Outpatient Coverage</label>
                                    <textarea name="coverage_outpatient" class="form-textarea" rows="3"><?php
                                                                                                        if (isset($_GET['edit_plan'])) {
                                                                                                            foreach ($plans as $plan) {
                                                                                                                if ($plan['id'] == $_GET['edit_plan']) {
                                                                                                                    echo htmlspecialchars($plan['coverage_outpatient'] ?? '');
                                                                                                                    break;
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                        ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Inpatient Coverage</label>
                                    <textarea name="coverage_inpatient" class="form-textarea" rows="3"><?php
                                                                                                        if (isset($_GET['edit_plan'])) {
                                                                                                            foreach ($plans as $plan) {
                                                                                                                if ($plan['id'] == $_GET['edit_plan']) {
                                                                                                                    echo htmlspecialchars($plan['coverage_inpatient'] ?? '');
                                                                                                                    break;
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                        ?></textarea>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Emergency Coverage</label>
                                    <textarea name="coverage_emergency" class="form-textarea" rows="2"><?php
                                                                                                        if (isset($_GET['edit_plan'])) {
                                                                                                            foreach ($plans as $plan) {
                                                                                                                if ($plan['id'] == $_GET['edit_plan']) {
                                                                                                                    echo htmlspecialchars($plan['coverage_emergency'] ?? '');
                                                                                                                    break;
                                                                                                                }
                                                                                                            }
                                                                                                        }
                                                                                                        ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Dental Coverage</label>
                                    <textarea name="coverage_dental" class="form-textarea" rows="2"><?php
                                                                                                    if (isset($_GET['edit_plan'])) {
                                                                                                        foreach ($plans as $plan) {
                                                                                                            if ($plan['id'] == $_GET['edit_plan']) {
                                                                                                                echo htmlspecialchars($plan['coverage_dental'] ?? '');
                                                                                                                break;
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                    ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Vision Coverage</label>
                                    <textarea name="coverage_vision" class="form-textarea" rows="2"><?php
                                                                                                    if (isset($_GET['edit_plan'])) {
                                                                                                        foreach ($plans as $plan) {
                                                                                                            if ($plan['id'] == $_GET['edit_plan']) {
                                                                                                                echo htmlspecialchars($plan['coverage_vision'] ?? '');
                                                                                                                break;
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                    ?></textarea>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Add-on Benefits</label>
                                    <textarea name="addon_benefits" class="form-textarea" rows="3"><?php
                                                                                                    if (isset($_GET['edit_plan'])) {
                                                                                                        foreach ($plans as $plan) {
                                                                                                            if ($plan['id'] == $_GET['edit_plan']) {
                                                                                                                echo htmlspecialchars($plan['addon_benefits'] ?? '');
                                                                                                                break;
                                                                                                            }
                                                                                                        }
                                                                                                    }
                                                                                                    ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Exclusions</label>
                                    <textarea name="exclusions" class="form-textarea" rows="3"><?php
                                                                                                if (isset($_GET['edit_plan'])) {
                                                                                                    foreach ($plans as $plan) {
                                                                                                        if ($plan['id'] == $_GET['edit_plan']) {
                                                                                                            echo htmlspecialchars($plan['exclusions'] ?? '');
                                                                                                            break;
                                                                                                        }
                                                                                                    }
                                                                                                }
                                                                                                ?></textarea>
                                </div>
                            </div>

                            <div class="form-actions">
                                <?php if (isset($_GET['edit_plan'])): ?>
                                    <input type="hidden" name="id" value="<?php echo $_GET['edit_plan']; ?>">
                                    <button type="submit" name="update_plan" class="btn btn-primary">
                                        <i class="fas fa-save"></i> Update Plan
                                    </button>
                                    <a href="provider-dashboard.php" class="btn btn-warning">
                                        <i class="fas fa-times"></i> Cancel
                                    </a>
                                <?php else: ?>
                                    <button type="submit" name="add_plan" class="btn btn-success">
                                        <i class="fas fa-plus"></i> Add Plan
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>

                    <!-- Plans List -->
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-list"></i> Current Plans
                        </h2>

                        <?php if (empty($plans)): ?>
                            <div class="empty-state">
                                <i class="fas fa-file-medical"></i>
                                <h3>No Plans Available</h3>
                                <p>Add your first plan using the form above.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Plan Name</th>
                                            <th>Annual Limit</th>
                                            <th>Employee Premium</th>
                                            <th>Dependent Premium</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($plans as $plan): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($plan['plan_name']); ?></strong></td>
                                                <td class="amount">₱<?php echo number_format($plan['annual_limit'], 2); ?></td>
                                                <td class="amount">₱<?php echo number_format($plan['premium_employee'], 2); ?></td>
                                                <td class="amount">₱<?php echo number_format($plan['premium_dependent'], 2); ?></td>
                                                <td class="actions-cell">
                                                    <a href="?edit_plan=<?php echo $plan['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this plan?');">
                                                        <input type="hidden" name="id" value="<?php echo $plan['id']; ?>">
                                                        <button type="submit" name="delete_plan" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Documents Tab -->
                <div id="documents" class="tab-content">
                    <!-- Add Document Form -->
                    <div class="form-section">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-plus-circle"></i> Add Document
                            </h2>
                        </div>
                        <form method="POST" action="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Document Name *</label>
                                    <input type="text" name="document_name" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Document Type *</label>
                                    <select name="document_type" class="form-select" required>
                                        <option value="">Select Type</option>
                                        <option value="Plan Booklet">Plan Booklet</option>
                                        <option value="Hospital List">Hospital List</option>
                                        <option value="Guidelines">Guidelines</option>
                                        <option value="Handbook">Handbook</option>
                                        <option value="Policy">Policy</option>
                                        <option value="Claim Form">Claim Form</option>
                                        <option value="FAQs">FAQs</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-textarea" rows="3" placeholder="Document description..."></textarea>
                            </div>
                            <div class="form-actions">
                                <button type="submit" name="add_document" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Add Document
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Documents List -->
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-file-alt"></i> Available Documents
                        </h2>

                        <?php if (empty($documents)): ?>
                            <div class="empty-state">
                                <i class="fas fa-file-alt"></i>
                                <h3>No Documents Available</h3>
                                <p>Add your first document using the form above.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Document Name</th>
                                            <th>Type</th>
                                            <th>Description</th>
                                            <th>Uploaded</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($documents as $doc): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($doc['document_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($doc['document_type']); ?></td>
                                                <td><?php echo htmlspecialchars($doc['description'] ?? 'N/A'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($doc['uploaded_at'])); ?></td>
                                                <td class="actions-cell">
                                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this document?');">
                                                        <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                                                        <button type="submit" name="delete_document" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Enrollments Tab -->
                <div id="enrollments" class="tab-content">
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-users"></i> Employee Enrollments
                        </h2>

                        <?php if (empty($enrollments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-users"></i>
                                <h3>No Enrollments Found</h3>
                                <p>Employees will appear here once they enroll in your plans.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Employee Name</th>
                                            <th>Department</th>
                                            <th>Email</th>
                                            <th>Plan</th>
                                            <th>Annual Limit</th>
                                            <th>Premium</th>
                                            <th>Enrollment Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($enrollments as $enrollment): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($enrollment['employee_name'] ?? 'Employee'); ?></strong></td>
                                                <td><?php echo htmlspecialchars($enrollment['department'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($enrollment['employee_email'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($enrollment['plan_name'] ?? 'N/A'); ?></td>
                                                <td class="amount">₱<?php echo number_format($enrollment['annual_limit'] ?? 0, 2); ?></td>
                                                <td class="amount">₱<?php echo number_format($enrollment['premium_employee'] ?? 0, 2); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($enrollment['enrollment_date'] ?? date('Y-m-d'))); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Dependents Section -->
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-user-friends"></i> Dependents Coverage
                        </h2>

                        <?php if (empty($dependents)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-friends"></i>
                                <h3>No Dependents Found</h3>
                                <p>Dependents will appear here once employees add them to their coverage.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Dependent Name</th>
                                            <th>Employee</th>
                                            <th>Relationship</th>
                                            <th>Age</th>
                                            <th>Included in Plan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dependents as $dependent): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($dependent['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($dependent['employee_name']); ?></td>
                                                <td><?php echo htmlspecialchars($dependent['relationship']); ?></td>
                                                <td><?php echo htmlspecialchars($dependent['age'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php if ($dependent['included_in_plan']): ?>
                                                        <span style="color: var(--success-color); font-weight: 600;">Yes</span>
                                                    <?php else: ?>
                                                        <span style="color: var(--danger-color); font-weight: 600;">No</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Provider Info Tab -->
                <div id="provider-info" class="tab-content">
                    <div class="form-section">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-info-circle"></i> Provider Information
                            </h2>
                        </div>
                        <form method="POST" action="">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Provider Name</label>
                                    <input type="text" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['provider_name'] ?? $provider_name); ?>" readonly>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Provider Type</label>
                                    <input type="text" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['provider_type'] ?? 'Healthcare Provider'); ?>" readonly>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Contact Person *</label>
                                    <input type="text" name="contact_person" class="form-input" required
                                        value="<?php echo htmlspecialchars($provider['contact_person'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-input" required
                                        value="<?php echo htmlspecialchars($provider['email'] ?? $user_email); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Address</label>
                                <input type="text" name="address" class="form-input"
                                    value="<?php echo htmlspecialchars($provider['address'] ?? ''); ?>">
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">City</label>
                                    <input type="text" name="city" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['city'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">State</label>
                                    <input type="text" name="state" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['state'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Zip Code</label>
                                    <input type="text" name="zip_code" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['zip_code'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Country</label>
                                    <input type="text" name="country" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['country'] ?? 'Philippines'); ?>">
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Website</label>
                                    <input type="url" name="website" class="form-input"
                                        value="<?php echo htmlspecialchars($provider['website'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-textarea" rows="4"><?php echo htmlspecialchars($provider['notes'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="update_provider_info" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Information
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Reports Tab -->
                <div id="reports" class="tab-content">
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-chart-bar"></i> Provider Reports
                        </h2>

                        <div class="stats-grid">
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $stats['total_enrollments']; ?></div>
                                <div class="stat-label">Total Enrollments</div>
                            </div>
                            <div class="stat-card success">
                                <div class="stat-value"><?php echo $stats['total_coverage']; ?></div>
                                <div class="stat-label">Total Lives Covered</div>
                            </div>
                            <div class="stat-card warning">
                                <div class="stat-value"><?php echo $stats['total_plans']; ?></div>
                                <div class="stat-label">Active Plans</div>
                            </div>
                            <div class="stat-card info">
                                <div class="stat-value"><?php echo $stats['total_documents']; ?></div>
                                <div class="stat-label">Documents</div>
                            </div>
                        </div>

                        <!-- Enrollment by Plan -->
                        <div class="form-section">
                            <h2 class="form-title">
                                <i class="fas fa-chart-pie"></i> Enrollment by Plan
                            </h2>

                            <?php
                            // Calculate enrollment by plan
                            $enrollment_by_plan = [];
                            foreach ($enrollments as $enrollment) {
                                $plan_name = $enrollment['plan_name'] ?? 'Unknown Plan';
                                if (!isset($enrollment_by_plan[$plan_name])) {
                                    $enrollment_by_plan[$plan_name] = 0;
                                }
                                $enrollment_by_plan[$plan_name]++;
                            }

                            // If no enrollments, show empty message
                            if (empty($enrollment_by_plan)) {
                                $enrollment_by_plan = [];
                            }
                            ?>

                            <?php if (empty($enrollment_by_plan)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-chart-pie"></i>
                                    <h3>No Enrollment Data</h3>
                                    <p>Enrollment statistics will appear here once employees enroll in your plans.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-container">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Plan Name</th>
                                                <th>Number of Enrollments</th>
                                                <th>Percentage</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $total_enrollments = array_sum($enrollment_by_plan);
                                            foreach ($enrollment_by_plan as $plan_name => $count):
                                                $percentage = $total_enrollments > 0 ? ($count / $total_enrollments) * 100 : 0;
                                            ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($plan_name); ?></strong></td>
                                                    <td><?php echo $count; ?></td>
                                                    <td><?php echo number_format($percentage, 1); ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');

            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');

                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));

                    // Add active class to current tab and content
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });

            // Auto-expand textareas
            const textareas = document.querySelectorAll('textarea');
            textareas.forEach(textarea => {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });

                // Trigger initial resize
                textarea.dispatchEvent(new Event('input'));
            });

            // Show debug info if URL has debug parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('debug')) {
                document.getElementById('debugInfo').style.display = 'block';
            }
        });

        function toggleDebug() {
            const debugInfo = document.getElementById('debugInfo');
            debugInfo.style.display = debugInfo.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>

</html>
<?php
ob_end_flush();
?>