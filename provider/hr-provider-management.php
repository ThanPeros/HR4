<?php
// hr-provider-management.php - HR Manager Provider Management (Enhanced)
ob_start();
session_start();

// Check if user is logged in as HR Manager or Admin
if (
    !isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true ||
    !in_array($_SESSION['user_role'], ['hr_manager', 'hr_staff', 'admin'])
) {
    header('Location: provider-login-portal.php');
    exit;
}

// Include database configuration
include '../config/db.php';

// Check if database connection is available
if (!isset($pdo) || !$pdo instanceof PDO) {
    die("Database connection not available. Please check your database configuration.");
}

// Get HR Manager details with null checks
$hr_manager_id = $_SESSION['hr_manager_id'] ?? null;
$hr_manager_name = $_SESSION['user_name'] ?? 'Unknown';
$hr_manager_email = $_SESSION['user_email'] ?? '';

// Fetch all providers
try {
    $providers_stmt = $pdo->query("SELECT * FROM providers ORDER BY provider_name");
    $providers = $providers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all plans with provider names
    $plans_stmt = $pdo->query("
        SELECT p.*, pr.provider_name 
        FROM plans p 
        LEFT JOIN providers pr ON p.provider_id = pr.id 
        ORDER BY pr.provider_name, p.plan_name
    ");
    $plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all documents
    $docs_stmt = $pdo->query("
        SELECT bd.*, pr.provider_name 
        FROM benefit_documents bd 
        LEFT JOIN providers pr ON bd.provider_id = pr.id 
        ORDER BY bd.document_type, bd.document_name
    ");
    $documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all enrollments
    $enrollments_stmt = $pdo->query("
        SELECT ee.*, e.name as employee_name, e.department, pr.provider_name, pl.plan_name 
        FROM employee_enrollments ee 
        LEFT JOIN employees e ON ee.employee_id = e.id 
        LEFT JOIN providers pr ON ee.provider_id = pr.id 
        LEFT JOIN plans pl ON ee.plan_id = pl.id 
        ORDER BY e.name
    ");
    $enrollments = $enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all provider portal users
    $portal_users_stmt = $pdo->query("
        SELECT pu.*, pr.provider_name 
        FROM provider_portal_users pu 
        LEFT JOIN providers pr ON pu.provider_id = pr.id 
        ORDER BY pr.provider_name, pu.username
    ");
    $portal_users = $portal_users_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics with null checks
    $stats = [
        'total_providers' => count($providers ?? []),
        'total_plans' => count($plans ?? []),
        'total_enrollments' => count($enrollments ?? []),
        'total_documents' => count($documents ?? []),
        'total_portal_users' => count($portal_users ?? []),
        'active_providers' => count(array_filter($providers ?? [], function ($p) {
            return isset($p['status']) && $p['status'] === 'Active';
        })),
        'inactive_providers' => count(array_filter($providers ?? [], function ($p) {
            return isset($p['status']) && $p['status'] === 'Inactive';
        })),
        'active_portal_users' => count(array_filter($portal_users ?? [], function ($u) {
            return isset($u['status']) && $u['status'] === 'active';
        })),
        'pending_portal_users' => count(array_filter($portal_users ?? [], function ($u) {
            return isset($u['status']) && $u['status'] === 'pending';
        }))
    ];
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: provider-login-portal.php');
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_provider'])) {
        addProvider($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['update_provider'])) {
        updateProvider($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['delete_provider'])) {
        deleteProvider($pdo, $_POST['id'], $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['toggle_provider_status'])) {
        toggleProviderStatus($pdo, $_POST['id'], $_POST['status'], $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['add_plan'])) {
        addPlan($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['update_plan'])) {
        updatePlan($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['delete_plan'])) {
        deletePlan($pdo, $_POST['id'], $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['add_document'])) {
        addDocument($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['delete_document'])) {
        deleteDocument($pdo, $_POST['id'], $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['add_portal_user'])) {
        addPortalUser($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['update_portal_user'])) {
        updatePortalUser($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['delete_portal_user'])) {
        deletePortalUser($pdo, $_POST['id'], $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['reset_portal_password'])) {
        resetPortalPassword($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['bulk_activate_providers'])) {
        bulkActivateProviders($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['bulk_deactivate_providers'])) {
        bulkDeactivateProviders($pdo, $_POST, $hr_manager_id, $hr_manager_name);
    } elseif (isset($_POST['import_providers'])) {
        importProviders($pdo, $_FILES, $hr_manager_id, $hr_manager_name);
    }
}

// ===================== AUDIT LOG FUNCTION =====================
function logAudit($pdo, $hr_manager_id, $hr_manager_name, $action, $details = null)
{
    $sql = "INSERT INTO audit_logs (user_id, user_name, action, timestamp, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, NOW(), ?, ?, NOW())";

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    if ($details) {
        $action .= " - " . json_encode($details);
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$hr_manager_id, $hr_manager_name, $action, $ip_address, $user_agent]);
    } catch (PDOException $e) {
        // Silently fail if audit logging fails
        error_log("Audit log error: " . $e->getMessage());
    }
}

// ===================== STATISTICS FUNCTIONS =====================
function calculateAveragePlansPerProviderPHP($plans, $providers)
{
    if (empty($providers)) {
        return '0.0';
    }

    $plansByProvider = [];
    foreach ($plans as $plan) {
        if (isset($plan['provider_id']) && $plan['provider_id']) {
            $providerId = $plan['provider_id'];
            if (!isset($plansByProvider[$providerId])) {
                $plansByProvider[$providerId] = 0;
            }
            $plansByProvider[$providerId]++;
        }
    }

    $totalPlans = array_sum($plansByProvider);
    return number_format($totalPlans / count($providers), 1);
}

// ===================== PROVIDER MANAGEMENT FUNCTIONS =====================
function addProvider($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    try {
        // Start transaction
        $pdo->beginTransaction();

        $sql = "INSERT INTO providers (provider_name, provider_type, contact_person, email, phone, address, city, state, zip_code, country, website, notes, status, portal_access, portal_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 'enabled', 'setup_pending')";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['provider_name'],
            $data['provider_type'],
            $data['contact_person'],
            $data['email'],
            $data['phone'],
            $data['address'] ?? '',
            $data['city'] ?? '',
            $data['state'] ?? '',
            $data['zip_code'] ?? '',
            $data['country'] ?? 'Philippines',
            $data['website'] ?? '',
            $data['notes'] ?? ''
        ]);

        $provider_id = $pdo->lastInsertId();

        // Automatically create portal user account
        $portal_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $data['provider_name']));
        $portal_email = $data['portal_email'] ?? $data['email'];
        $portal_password = password_hash('provider123', PASSWORD_DEFAULT);

        $portal_sql = "INSERT INTO provider_portal_users (provider_id, username, password, email, full_name, user_type, status, created_at) 
                      VALUES (?, ?, ?, ?, ?, 'provider', 'active', NOW())";

        $portal_stmt = $pdo->prepare($portal_sql);
        $portal_stmt->execute([
            $provider_id,
            $portal_username,
            $portal_password,
            $portal_email,
            $data['contact_person']
        ]);

        $pdo->commit();

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Added new provider", [
            'provider_id' => $provider_id,
            'provider_name' => $data['provider_name'],
            'portal_username' => $portal_username
        ]);

        $_SESSION['success_message'] = "Provider added successfully! Portal account created with username: $portal_username and default password: provider123";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Error adding provider: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function updateProvider($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    $sql = "UPDATE providers SET provider_name=?, provider_type=?, contact_person=?, email=?, phone=?, address=?, 
                           city=?, state=?, zip_code=?, country=?, website=?, notes=?, 
                           portal_access=?, portal_status=? WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['provider_name'],
            $data['provider_type'],
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
            $data['portal_access'],
            $data['portal_status'],
            $data['id']
        ]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Updated provider", [
            'provider_id' => $data['id'],
            'provider_name' => $data['provider_name']
        ]);

        $_SESSION['success_message'] = "Provider updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating provider: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function deleteProvider($pdo, $id, $hr_manager_id, $hr_manager_name)
{
    // First, get provider info for audit log
    $provider_stmt = $pdo->prepare("SELECT provider_name FROM providers WHERE id = ?");
    $provider_stmt->execute([$id]);
    $provider = $provider_stmt->fetch(PDO::FETCH_ASSOC);

    $sql = "DELETE FROM providers WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Deleted provider", [
            'provider_id' => $id,
            'provider_name' => $provider['provider_name'] ?? 'Unknown'
        ]);

        $_SESSION['success_message'] = "Provider deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting provider: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function toggleProviderStatus($pdo, $id, $status, $hr_manager_id, $hr_manager_name)
{
    $new_status = $status === 'Active' ? 'Inactive' : 'Active';
    $sql = "UPDATE providers SET status = ? WHERE id = ?";

    // Get provider info for audit log
    $provider_stmt = $pdo->prepare("SELECT provider_name FROM providers WHERE id = ?");
    $provider_stmt->execute([$id]);
    $provider = $provider_stmt->fetch(PDO::FETCH_ASSOC);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$new_status, $id]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Changed provider status", [
            'provider_id' => $id,
            'provider_name' => $provider['provider_name'] ?? 'Unknown',
            'old_status' => $status,
            'new_status' => $new_status
        ]);

        $_SESSION['success_message'] = "Provider status updated to " . $new_status . "!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating provider status: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ===================== PORTAL USER MANAGEMENT FUNCTIONS =====================
function addPortalUser($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);

    $sql = "INSERT INTO provider_portal_users (provider_id, username, password, email, full_name, user_type, phone, status, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['provider_id'],
            $data['username'],
            $password_hash,
            $data['email'],
            $data['full_name'],
            $data['user_type'] ?? 'provider',
            $data['phone'] ?? null
        ]);

        // Get provider name for audit log
        $provider_stmt = $pdo->prepare("SELECT provider_name FROM providers WHERE id = ?");
        $provider_stmt->execute([$data['provider_id']]);
        $provider = $provider_stmt->fetch(PDO::FETCH_ASSOC);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Added portal user", [
            'provider_id' => $data['provider_id'],
            'provider_name' => $provider['provider_name'] ?? 'Unknown',
            'username' => $data['username'],
            'email' => $data['email']
        ]);

        $_SESSION['success_message'] = "Portal user added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding portal user: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#portal-users');
    exit;
}

function updatePortalUser($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    $sql = "UPDATE provider_portal_users SET username=?, email=?, full_name=?, user_type=?, phone=?, status=? WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['username'],
            $data['email'],
            $data['full_name'],
            $data['user_type'],
            $data['phone'] ?? null,
            $data['status'],
            $data['id']
        ]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Updated portal user", [
            'user_id' => $data['id'],
            'username' => $data['username']
        ]);

        $_SESSION['success_message'] = "Portal user updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating portal user: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#portal-users');
    exit;
}

function deletePortalUser($pdo, $id, $hr_manager_id, $hr_manager_name)
{
    // Get user info for audit log
    $user_stmt = $pdo->prepare("SELECT username, provider_id FROM provider_portal_users WHERE id = ?");
    $user_stmt->execute([$id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    $sql = "DELETE FROM provider_portal_users WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Deleted portal user", [
            'user_id' => $id,
            'username' => $user['username'] ?? 'Unknown'
        ]);

        $_SESSION['success_message'] = "Portal user deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting portal user: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#portal-users');
    exit;
}

function resetPortalPassword($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    // Validate password
    if ($data['new_password'] !== $data['confirm_password']) {
        $_SESSION['error_message'] = "Passwords do not match!";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#portal-users');
        exit;
    }

    if (strlen($data['new_password']) < 8) {
        $_SESSION['error_message'] = "Password must be at least 8 characters long!";
        header('Location: ' . $_SERVER['PHP_SELF'] . '#portal-users');
        exit;
    }

    $password_hash = password_hash($data['new_password'], PASSWORD_DEFAULT);
    $sql = "UPDATE provider_portal_users SET password = ? WHERE id = ?";

    // Get user info for audit log
    $user_stmt = $pdo->prepare("SELECT username FROM provider_portal_users WHERE id = ?");
    $user_stmt->execute([$data['user_id']]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$password_hash, $data['user_id']]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Reset portal user password", [
            'user_id' => $data['user_id'],
            'username' => $user['username'] ?? 'Unknown'
        ]);

        $_SESSION['success_message'] = "Password reset successfully! New password has been set.";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error resetting password: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#portal-users');
    exit;
}

// ===================== BULK OPERATIONS FUNCTIONS =====================
function bulkActivateProviders($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    if (empty($data['provider_ids'])) {
        $_SESSION['error_message'] = "No providers selected!";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $ids = implode(',', array_map('intval', $data['provider_ids']));
    $sql = "UPDATE providers SET status = 'Active' WHERE id IN ($ids)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Bulk activated providers", [
            'provider_ids' => $data['provider_ids'],
            'count' => count($data['provider_ids'])
        ]);

        $_SESSION['success_message'] = count($data['provider_ids']) . " providers activated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error activating providers: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

function bulkDeactivateProviders($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    if (empty($data['provider_ids'])) {
        $_SESSION['error_message'] = "No providers selected!";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $ids = implode(',', array_map('intval', $data['provider_ids']));
    $sql = "UPDATE providers SET status = 'Inactive' WHERE id IN ($ids)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Bulk deactivated providers", [
            'provider_ids' => $data['provider_ids'],
            'count' => count($data['provider_ids'])
        ]);

        $_SESSION['success_message'] = count($data['provider_ids']) . " providers deactivated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deactivating providers: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ===================== IMPORT FUNCTION =====================
function importProviders($pdo, $files, $hr_manager_id, $hr_manager_name)
{
    if (!isset($files['import_file']) || $files['import_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error_message'] = "Please select a valid CSV file to import.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $file = $files['import_file']['tmp_name'];
    $handle = fopen($file, 'r');

    if (!$handle) {
        $_SESSION['error_message'] = "Cannot open uploaded file.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $pdo->beginTransaction();
    $success_count = 0;
    $error_count = 0;
    $row = 1; // Start from row 1 (header)

    // Skip header row
    fgetcsv($handle);

    try {
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) < 3) continue; // Skip invalid rows

            $provider_name = trim($data[0]);
            $provider_type = trim($data[1]);
            $email = trim($data[2]);
            $contact_person = trim($data[3] ?? '');

            if (empty($provider_name) || empty($provider_type) || empty($email)) {
                $error_count++;
                continue;
            }

            $sql = "INSERT INTO providers (provider_name, provider_type, contact_person, email, status, created_at) 
                    VALUES (?, ?, ?, ?, 'Active', NOW())";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$provider_name, $provider_type, $contact_person, $email]);

            $success_count++;
        }

        $pdo->commit();

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Imported providers from CSV", [
            'success_count' => $success_count,
            'error_count' => $error_count
        ]);

        $_SESSION['success_message'] = "Import completed! $success_count providers added successfully. $error_count rows skipped.";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "Import failed: " . $e->getMessage();
    }

    fclose($handle);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ===================== PLAN MANAGEMENT FUNCTIONS (with audit logging) =====================
function addPlan($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    $sql = "INSERT INTO plans (provider_id, plan_name, annual_limit, room_board_type, premium_employee, premium_dependent, 
                               coverage_outpatient, coverage_inpatient, coverage_emergency, coverage_dental, coverage_vision, 
                               addon_benefits, exclusions, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['provider_id'],
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

        $plan_id = $pdo->lastInsertId();

        // Get provider name for audit log
        $provider_stmt = $pdo->prepare("SELECT provider_name FROM providers WHERE id = ?");
        $provider_stmt->execute([$data['provider_id']]);
        $provider = $provider_stmt->fetch(PDO::FETCH_ASSOC);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Added new plan", [
            'plan_id' => $plan_id,
            'plan_name' => $data['plan_name'],
            'provider_id' => $data['provider_id'],
            'provider_name' => $provider['provider_name'] ?? 'Unknown'
        ]);

        $_SESSION['success_message'] = "Plan added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding plan: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#plans');
    exit;
}

function updatePlan($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    $sql = "UPDATE plans SET provider_id=?, plan_name=?, annual_limit=?, room_board_type=?, premium_employee=?, premium_dependent=?,
                           coverage_outpatient=?, coverage_inpatient=?, coverage_emergency=?, coverage_dental=?,
                           coverage_vision=?, addon_benefits=?, exclusions=? WHERE id=?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['provider_id'],
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

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Updated plan", [
            'plan_id' => $data['id'],
            'plan_name' => $data['plan_name']
        ]);

        $_SESSION['success_message'] = "Plan updated successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating plan: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#plans');
    exit;
}

function deletePlan($pdo, $id, $hr_manager_id, $hr_manager_name)
{
    // Get plan info for audit log
    $plan_stmt = $pdo->prepare("SELECT plan_name FROM plans WHERE id = ?");
    $plan_stmt->execute([$id]);
    $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

    $sql = "DELETE FROM plans WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Deleted plan", [
            'plan_id' => $id,
            'plan_name' => $plan['plan_name'] ?? 'Unknown'
        ]);

        $_SESSION['success_message'] = "Plan deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting plan: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#plans');
    exit;
}

// ===================== DOCUMENT MANAGEMENT FUNCTIONS (with audit logging) =====================
function addDocument($pdo, $data, $hr_manager_id, $hr_manager_name)
{
    $sql = "INSERT INTO benefit_documents (provider_id, document_name, document_type, description, uploaded_at) 
            VALUES (?, ?, ?, ?, NOW())";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['provider_id'],
            $data['document_name'],
            $data['document_type'],
            $data['description'] ?? ''
        ]);

        // Get provider name for audit log
        $provider_stmt = $pdo->prepare("SELECT provider_name FROM providers WHERE id = ?");
        $provider_stmt->execute([$data['provider_id']]);
        $provider = $provider_stmt->fetch(PDO::FETCH_ASSOC);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Added document", [
            'provider_id' => $data['provider_id'],
            'provider_name' => $provider['provider_name'] ?? 'Unknown',
            'document_name' => $data['document_name'],
            'document_type' => $data['document_type']
        ]);

        $_SESSION['success_message'] = "Document added successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error adding document: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#documents');
    exit;
}

function deleteDocument($pdo, $id, $hr_manager_id, $hr_manager_name)
{
    // Get document info for audit log
    $doc_stmt = $pdo->prepare("SELECT document_name FROM benefit_documents WHERE id = ?");
    $doc_stmt->execute([$id]);
    $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

    $sql = "DELETE FROM benefit_documents WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);

        // Log audit
        logAudit($pdo, $hr_manager_id, $hr_manager_name, "Deleted document", [
            'document_id' => $id,
            'document_name' => $document['document_name'] ?? 'Unknown'
        ]);

        $_SESSION['success_message'] = "Document deleted successfully!";
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error deleting document: " . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '#documents');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Provider Management | Benefits & HMO Portal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 250px;
            --header-height: 60px;
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
            --orange-color: #fd7e14;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            line-height: 1.4;
            overflow-x: hidden;
        }

        /* Dashboard Header */
        .dashboard-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #2e59d9 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-bottom: 3px solid var(--primary-color);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .hr-logo {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .header-title h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .header-title p {
            margin: 0.25rem 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
            font-weight: 400;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .user-info {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.25rem;
        }

        .user-email {
            font-size: 0.85rem;
            opacity: 0.8;
        }

        .back-to-admin,
        .logout-btn {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-to-admin {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .back-to-admin:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        .logout-btn {
            background: var(--danger-color);
            color: white;
            border: 1px solid var(--danger-color);
        }

        .logout-btn:hover {
            background: #d32f2f;
            transform: translateY(-1px);
        }

        .main-content {
            padding: 2rem;
            min-height: 100vh;
            background-color: var(--secondary-color);
            width: 100%;
        }

        .content-area {
            width: 100%;
            min-height: 100%;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .page-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* Forms */
        .form-container {
            padding: 1.5rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .form-title {
            font-size: 1.2rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e3e6f0;
            border-radius: var(--border-radius);
            font-size: 1rem;
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
            min-height: 80px;
        }

        .form-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
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
            font-size: 0.9rem;
        }

        /* Table */
        .table-container {
            padding: 0 1.5rem 1.5rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .data-table th {
            background: #f8f9fc;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #4e73df;
            border-bottom: 1px solid #e3e6f0;
        }

        .data-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e3e6f0;
        }

        .data-table tr:hover {
            background: #f8f9fc;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .actions-cell {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Messages */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin: 1rem 1.5rem;
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

        /* Tabs */
        .tabs-container {
            padding: 1.5rem;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid #e3e6f0;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .tab {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #6c757d;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab:hover {
            color: var(--primary-color);
        }

        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Statistics Tabs */
        .stats-tabs {
            display: flex;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin: 1.5rem;
            overflow: hidden;
            border: 1px solid #e3e6f0;
        }

        .stat-tab {
            flex: 1;
            padding: 1.5rem 1rem;
            text-align: center;
            position: relative;
            border-right: 1px solid #e3e6f0;
            background: #f8f9fc;
        }

        .stat-tab:last-child {
            border-right: none;
        }

        .stat-tab .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--primary-color);
        }

        .stat-tab .stat-label {
            color: #6c757d;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-tab .stat-icon {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            font-size: 1.2rem;
            opacity: 0.6;
            color: var(--primary-color);
        }

        /* Add these additional styles to your existing CSS */

        /* Bulk Actions Section */
        .bulk-actions {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .bulk-actions select {
            min-width: 200px;
        }

        /* Import Section */
        .import-section {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .import-instructions {
            font-size: 0.9rem;
            color: #1565c0;
            margin-top: 0.5rem;
        }

        /* Enhanced Stats Cards */
        .stat-card.trend-up {
            border-left-color: var(--success-color);
        }

        .stat-card.trend-down {
            border-left-color: var(--danger-color);
        }

        .stat-trend {
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        .trend-up .stat-trend {
            color: var(--success-color);
        }

        .trend-down .stat-trend {
            color: var(--danger-color);
        }

        /* Quick Stats */
        .quick-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .quick-stat-item {
            flex: 1;
            min-width: 200px;
            background: white;
            border-radius: var(--border-radius);
            padding: 1rem;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary-color);
        }

        /* Enhanced Form Styles */
        .password-strength {
            margin-top: 0.25rem;
            font-size: 0.8rem;
        }

        .strength-weak {
            color: var(--danger-color);
        }

        .strength-medium {
            color: var(--warning-color);
        }

        .strength-strong {
            color: var(--success-color);
        }

        /* Action Log */
        .action-log {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
            max-height: 300px;
            overflow-y: auto;
        }

        .log-entry {
            padding: 0.5rem;
            border-bottom: 1px solid #e3e6f0;
            font-size: 0.85rem;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        .log-time {
            color: #6c757d;
            font-size: 0.75rem;
        }

        /* Checkbox Column */
        .checkbox-cell {
            width: 50px;
            text-align: center;
        }

        /* Provider Health Indicator */
        .health-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }

        .health-excellent {
            background: var(--success-color);
        }

        .health-good {
            background: #4e73df;
        }

        .health-fair {
            background: var(--warning-color);
        }

        .health-poor {
            background: var(--danger-color);
        }

        /* Tab Badges */
        .tab-badge {
            background: var(--danger-color);
            color: white;
            border-radius: 10px;
            padding: 0.25rem 0.5rem;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }

        /* Responsive Tables */
        @media (max-width: 768px) {
            .table-container {
                font-size: 0.9rem;
            }

            .data-table th,
            .data-table td {
                padding: 0.5rem;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .quick-stats {
                flex-direction: column;
            }
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--success-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Tooltips */
        .tooltip {
            position: relative;
            display: inline-block;
            cursor: help;
        }

        .tooltip .tooltip-text {
            visibility: hidden;
            width: 200px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.85rem;
        }

        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .dashboard-header {
                padding: 1rem;
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .header-left,
            .header-right {
                justify-content: center;
            }

            .header-right {
                flex-direction: column;
                gap: 1rem;
            }

            .user-info {
                text-align: center;
            }

            .dashboard-main {
                padding: 1rem;
            }

            .welcome-section {
                padding: 1.5rem;
            }

            .stats-tabs {
                margin: 1rem;
                flex-direction: column;
            }

            .stat-tab {
                border-right: none !important;
                border-bottom: 1px solid #e3e6f0;
            }

            .stat-tab:last-child {
                border-bottom: none;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }

            .actions-cell {
                flex-direction: column;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                border-bottom: 1px solid #e3e6f0;
                border-left: 3px solid transparent;
            }

            .tab.active {
                border-left-color: var(--primary-color);
                border-bottom-color: #e3e6f0;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .quick-stats {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .dashboard-header {
                padding: 0.8rem;
            }

            .hr-logo {
                width: 40px;
                height: 40px;
                font-size: 1.2rem;
            }

            .header-title h1 {
                font-size: 1.4rem;
            }

            .header-title p {
                font-size: 0.8rem;
            }

            .user-name {
                font-size: 0.9rem;
            }

            .user-email {
                font-size: 0.8rem;
            }

            .back-to-admin,
            .logout-btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }

            .welcome-section {
                padding: 1rem;
            }

            .form-container {
                margin: 1rem 0;
                padding: 1rem;
            }

            .table-container {
                padding: 0 1rem 1rem;
            }

            .tabs-container {
                padding: 1rem 0;
            }
        }

        /* Hide print header on screen */
        .print-header {
            display: none;
        }

        /* Print Styles */
        @media print {

            /* Show print header */
            .print-header {
                display: block !important;
                text-align: center;
                border-bottom: 3px solid #333;
                padding-bottom: 1rem;
                margin-bottom: 2rem;
                page-break-after: avoid;
            }

            .print-header h1 {
                font-size: 20pt;
                font-weight: bold;
                margin: 0 0 0.5rem 0;
                color: black !important;
            }

            .print-header p {
                font-size: 11pt;
                margin: 0.25rem 0;
                color: #666 !important;
            }

            /* Hide non-essential elements */
            .dashboard-header,
            .tabs-container,
            .tab,
            .form-header .btn,
            .loading-overlay,
            .alert,
            .no-print {
                display: none !important;
            }

            /* Show only the reports tab content */
            .tab-content:not(#reports) {
                display: none !important;
            }

            #reports {
                display: block !important;
            }

            /* Page setup */
            body {
                background: white !important;
                color: black !important;
                font-size: 12pt;
                line-height: 1.4;
            }

            .dashboard-container {
                width: 100% !important;
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .dashboard-main {
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Form sections */
            .form-section {
                page-break-inside: avoid;
                margin-bottom: 2rem;
                border: 1px solid #ccc;
                padding: 1rem;
                background: white;
            }

            .form-title {
                color: black !important;
                border-bottom: 2px solid #333;
                padding-bottom: 0.5rem;
                margin-bottom: 1rem;
                font-size: 16pt;
                font-weight: bold;
            }

            /* Statistics */
            .stats-tabs {
                background: white !important;
                border: 1px solid #ccc;
                margin: 1rem 0;
                page-break-inside: avoid;
            }

            .stat-tab {
                background: white !important;
                border-right: 1px solid #ccc;
                color: black !important;
                padding: 1rem;
            }

            .stat-tab .stat-value {
                color: black !important;
                font-size: 18pt;
                font-weight: bold;
            }

            .stat-tab .stat-label {
                color: #333 !important;
                font-size: 10pt;
            }

            /* Tables */
            .table-container {
                page-break-inside: avoid;
                margin: 1rem 0;
            }

            .data-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 10pt;
            }

            .data-table th,
            .data-table td {
                border: 1px solid #ccc;
                padding: 0.5rem;
                text-align: left;
            }

            .data-table th {
                background: #f0f0f0 !important;
                color: black !important;
                font-weight: bold;
            }

            /* Activity log */
            .action-log {
                border: 1px solid #ccc;
                padding: 1rem;
                background: white;
            }

            .log-entry {
                border-bottom: 1px solid #eee;
                padding: 0.5rem 0;
                font-size: 10pt;
            }

            .log-entry:last-child {
                border-bottom: none;
            }

            .log-time {
                color: #666;
                font-size: 9pt;
            }

            /* Status badges */
            .status-badge {
                border: 1px solid #ccc !important;
                background: white !important;
                color: black !important;
                padding: 0.25rem 0.5rem;
                font-size: 9pt;
            }

            /* Page breaks */
            .form-section {
                page-break-after: always;
            }

            .form-section:last-child {
                page-break-after: avoid;
            }

            /* Print header */
            @page {
                margin: 0.5in;
                size: A4;
            }

            /* Ensure all text is black */
            * {
                color: black !important;
                background: white !important;
                box-shadow: none !important;
            }

            /* Hide icons in print */
            .fas,
            .far,
            .fab {
                display: none;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>

    <div class="dashboard-container">
        <!-- Header (Your existing header remains the same) -->
        <header class="dashboard-header">
            <div class="header-left">
                <div class="hr-logo">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="header-title">
                    <h1>HR Provider Management</h1>
                    <p>Full Administrator Access</p>
                </div>
            </div>

            <div class="header-right">
                <div class="user-info">
                    <div class="user-name">HR Manager (HR Manager)</div>
                    <div class="user-email">hr@company.com</div>
                </div>
                <a href="../index.php" class="back-to-admin">
                    <i class="fas fa-tachometer-alt"></i> Main Dashboard
                </a>
                <a href="?logout=true" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </header>

        <!-- Main Content -->
        <main class="dashboard-main">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h2><i class="fas fa-shield-alt"></i> Provider Management Dashboard</h2>
                <p>You have full administrator access to manage all HMO/benefits providers, plans, and documents.</p>
                <div class="quick-stats">
                    <div class="quick-stat-item">
                        <strong>Total Providers:</strong> <?php echo $stats['total_providers']; ?>
                    </div>
                    <div class="quick-stat-item">
                        <strong>Active Providers:</strong> <?php echo $stats['active_providers']; ?>
                    </div>
                    <div class="quick-stat-item">
                        <strong>Portal Users:</strong> <?php echo $stats['total_portal_users']; ?>
                    </div>
                    <div class="quick-stat-item">
                        <strong>Total Enrollments:</strong> <?php echo $stats['total_enrollments']; ?>
                    </div>
                </div>
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

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Tabs -->
            <div class="stats-tabs">
                <div class="stat-tab">
                    <div class="stat-value"><?php echo $stats['total_providers']; ?></div>
                    <div class="stat-label">Total Providers</div>
                    <i class="fas fa-building stat-icon"></i>
                </div>
                <div class="stat-tab">
                    <div class="stat-value"><?php echo $stats['active_providers']; ?></div>
                    <div class="stat-label">Active Providers</div>
                    <i class="fas fa-check-circle stat-icon"></i>
                </div>
                <div class="stat-tab">
                    <div class="stat-value"><?php echo $stats['inactive_providers']; ?></div>
                    <div class="stat-label">Inactive Providers</div>
                    <i class="fas fa-times-circle stat-icon"></i>
                </div>
                <div class="stat-tab">
                    <div class="stat-value"><?php echo $stats['total_portal_users']; ?></div>
                    <div class="stat-label">Portal Users</div>
                    <i class="fas fa-user-cog stat-icon"></i>
                </div>
                <div class="stat-tab">
                    <div class="stat-value"><?php echo $stats['active_portal_users']; ?></div>
                    <div class="stat-label">Active Users</div>
                    <i class="fas fa-user-check stat-icon"></i>
                </div>
                <div class="stat-tab">
                    <div class="stat-value"><?php echo $stats['pending_portal_users']; ?></div>
                    <div class="stat-label">Pending Users</div>
                    <i class="fas fa-user-clock stat-icon"></i>
                </div>
            </div>

            <!-- Tabs Container -->
            <div class="tabs-container">
                <div class="tabs-header">
                    <button class="tab active" data-tab="providers">
                        <i class="fas fa-building"></i> Providers
                        <?php if ($stats['inactive_providers'] > 0): ?>
                            <span class="tab-badge"><?php echo $stats['inactive_providers']; ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="tab" data-tab="portal-users" id="portalUsersTab">
                        <i class="fas fa-user-cog"></i> Portal Users
                        <?php if ($stats['pending_portal_users'] > 0): ?>
                            <span class="tab-badge"><?php echo $stats['pending_portal_users']; ?></span>
                        <?php endif; ?>
                    </button>
                    <button class="tab" data-tab="plans">
                        <i class="fas fa-file-medical"></i> Plans
                    </button>
                    <button class="tab" data-tab="documents">
                        <i class="fas fa-file-alt"></i> Documents
                    </button>
                    <button class="tab" data-tab="reports">
                        <i class="fas fa-chart-bar"></i> Reports
                    </button>
                </div>

                <!-- ===================== PROVIDERS TAB ===================== -->
                <div id="providers" class="tab-content active">
                    <!-- Bulk Actions Section -->
                    <div class="bulk-actions">
                        <form method="POST" action="" id="bulkActionsForm">
                            <strong>Bulk Actions:</strong>
                            <select name="bulk_action" class="form-select" style="min-width: 150px;">
                                <option value="">Select Action</option>
                                <option value="activate">Activate Selected</option>
                                <option value="deactivate">Deactivate Selected</option>
                            </select>
                            <button type="button" class="btn btn-primary btn-sm" onclick="applyBulkAction()">
                                <i class="fas fa-play"></i> Apply
                            </button>
                            <span style="color: #666; font-size: 0.9rem;">
                                <i class="fas fa-info-circle"></i> Select providers below first
                            </span>
                        </form>
                    </div>

                    <!-- Import Section -->
                    <div class="import-section">
                        <h3><i class="fas fa-file-import"></i> Import Providers from CSV</h3>
                        <form method="POST" action="" enctype="multipart/form-data">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">CSV File *</label>
                                    <input type="file" name="import_file" class="form-input" accept=".csv" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">&nbsp;</label>
                                    <button type="submit" name="import_providers" class="btn btn-info">
                                        <i class="fas fa-upload"></i> Import
                                    </button>
                                </div>
                            </div>
                            <div class="import-instructions">
                                <strong>CSV Format:</strong> provider_name,provider_type,email,contact_person,phone,address<br>
                                <strong>Example:</strong> Maxicare,HMO,contact@maxicare.com,Juan Dela Cruz,09171234567,123 Main St
                            </div>
                        </form>
                    </div>

                    <!-- Add/Edit Provider Form (Your existing form remains) -->
                    <div class="form-section">
                        <!-- Your existing provider form goes here -->
                        <!-- ... -->
                    </div>

                    <!-- Providers List with Checkboxes -->
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-list"></i> All Providers
                            <button type="button" class="btn btn-sm btn-info" onclick="selectAllProviders()" style="margin-left: 1rem;">
                                <i class="fas fa-check-square"></i> Select All
                            </button>
                            <button type="button" class="btn btn-sm btn-warning" onclick="deselectAllProviders()">
                                <i class="fas fa-times"></i> Deselect All
                            </button>
                        </h2>

                        <?php if (empty($providers)): ?>
                            <div style="text-align: center; padding: 3rem; color: #6c757d;">
                                <i class="fas fa-building" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                                <h3>No Providers Found</h3>
                                <p>Add your first provider using the form above.</p>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" id="providersForm">
                                <div class="table-container">
                                    <table class="data-table" id="providersTable">
                                        <thead>
                                            <tr>
                                                <th class="checkbox-cell">
                                                    <input type="checkbox" id="selectAll" onchange="toggleAllProviders(this)">
                                                </th>
                                                <th>Provider Name</th>
                                                <th>Type</th>
                                                <th>Contact Person</th>
                                                <th>Email</th>
                                                <th>Phone</th>
                                                <th>Portal Status</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($providers as $provider):
                                                $portal_status = $provider['portal_status'] ?? 'setup_pending';
                                                $portal_access = $provider['portal_access'] ?? 'enabled';
                                            ?>
                                                <tr>
                                                    <td class="checkbox-cell">
                                                        <input type="checkbox" name="provider_ids[]" value="<?php echo $provider['id']; ?>" class="provider-checkbox">
                                                    </td>
                                                    <td><strong><?php echo htmlspecialchars($provider['provider_name']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($provider['provider_type']); ?></td>
                                                    <td><?php echo htmlspecialchars($provider['contact_person'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($provider['email'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($provider['phone'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php if ($portal_status === 'active' && $portal_access === 'enabled'): ?>
                                                            <span class="status-badge status-active">
                                                                <i class="fas fa-check"></i> Active
                                                            </span>
                                                        <?php elseif ($portal_status === 'setup_pending'): ?>
                                                            <span class="status-badge status-pending">
                                                                <i class="fas fa-clock"></i> Setup
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-inactive">
                                                                <i class="fas fa-times"></i> Inactive
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge status-<?php echo strtolower($provider['status']); ?>">
                                                            <?php echo htmlspecialchars($provider['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="actions-cell">
                                                        <a href="?edit_provider=<?php echo $provider['id']; ?>" class="btn btn-warning btn-sm">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                        <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Toggle provider status?');">
                                                            <input type="hidden" name="id" value="<?php echo $provider['id']; ?>">
                                                            <input type="hidden" name="status" value="<?php echo $provider['status']; ?>">
                                                            <button type="submit" name="toggle_provider_status" class="btn btn-info btn-sm">
                                                                <i class="fas fa-toggle-on"></i> Status
                                                            </button>
                                                        </form>
                                                        <button type="button" class="btn btn-success btn-sm" onclick="showCreatePortalUser(<?php echo $provider['id']; ?>, '<?php echo htmlspecialchars($provider['provider_name']); ?>')">
                                                            <i class="fas fa-user-plus"></i> Add User
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ===================== PORTAL USERS TAB ===================== -->
                <div id="portal-users" class="tab-content">
                    <!-- Add Portal User Modal Form -->
                    <div class="form-section" id="addPortalUserForm" style="<?php echo !isset($_GET['add_portal_user']) ? 'display: none;' : ''; ?>">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-user-plus"></i> Add Portal User
                            </h2>
                            <button type="button" class="btn btn-warning" onclick="hidePortalUserForm()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                        <form method="POST" action="" id="portalUserForm">
                            <input type="hidden" name="provider_id" id="portalUserProviderId" value="">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Provider</label>
                                    <input type="text" class="form-input" id="portalUserProviderName" readonly>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Username *</label>
                                    <input type="text" name="username" class="form-input" required>
                                    <small class="text-muted">Used for login</small>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Email *</label>
                                    <input type="email" name="email" class="form-input" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Full Name *</label>
                                    <input type="text" name="full_name" class="form-input" required>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Password *</label>
                                    <input type="password" name="password" class="form-input" id="passwordInput" required
                                        onkeyup="checkPasswordStrength(this.value)">
                                    <div id="passwordStrength" class="password-strength"></div>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Confirm Password *</label>
                                    <input type="password" name="confirm_password" class="form-input" id="confirmPasswordInput" required>
                                    <div id="passwordMatch" style="font-size: 0.8rem; margin-top: 0.25rem;"></div>
                                </div>
                            </div>

                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">User Type</label>
                                    <select name="user_type" class="form-select">
                                        <option value="provider">Provider</option>
                                        <option value="provider_admin">Provider Admin</option>
                                        <option value="provider_staff">Provider Staff</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-input">
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="add_portal_user" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Add User
                                </button>
                                <button type="button" class="btn btn-warning" onclick="generatePassword()">
                                    <i class="fas fa-key"></i> Generate Password
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Reset Password Modal -->
                    <div class="form-section" id="resetPasswordForm" style="display: none;">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-key"></i> Reset Password
                            </h2>
                            <button type="button" class="btn btn-warning" onclick="hideResetPasswordForm()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                        <form method="POST" action="" id="resetPasswordFormElement">
                            <input type="hidden" name="user_id" id="resetUserId" value="">

                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <input type="text" class="form-input" id="resetUsername" readonly>
                            </div>

                            <div class="form-group">
                                <label class="form-label">New Password *</label>
                                <input type="password" name="new_password" class="form-input" id="newPasswordInput" required
                                    onkeyup="checkPasswordStrength(this.value, 'reset')">
                                <div id="resetPasswordStrength" class="password-strength"></div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Confirm Password *</label>
                                <input type="password" name="confirm_password" class="form-input" id="newConfirmPasswordInput" required>
                                <div id="resetPasswordMatch" style="font-size: 0.8rem; margin-top: 0.25rem;"></div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" name="reset_portal_password" class="btn btn-danger">
                                    <i class="fas fa-key"></i> Reset Password
                                </button>
                                <button type="button" class="btn btn-info" onclick="generatePassword('reset')">
                                    <i class="fas fa-random"></i> Generate
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Portal Users List -->
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-user-cog"></i> Portal Users
                            <button type="button" class="btn btn-success btn-sm" onclick="showAddPortalUserForm()" style="margin-left: 1rem;">
                                <i class="fas fa-plus"></i> Add New User
                            </button>
                        </h2>

                        <?php if (empty($portal_users)): ?>
                            <div style="text-align: center; padding: 3rem; color: #6c757d;">
                                <i class="fas fa-user-cog" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                                <h3>No Portal Users Found</h3>
                                <p>Add your first portal user using the button above.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Username</th>
                                            <th>Provider</th>
                                            <th>Full Name</th>
                                            <th>Email</th>
                                            <th>User Type</th>
                                            <th>Status</th>
                                            <th>Last Login</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($portal_users as $user): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($user['provider_name'] ?? 'Unknown'); ?></td>
                                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td>
                                                    <span class="status-badge status-active" style="background: #e3f2fd; color: #1565c0;">
                                                        <?php echo htmlspecialchars($user['user_type'] ?? 'provider'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($user['status'] ?? 'active'); ?>">
                                                        <?php echo htmlspecialchars($user['status'] ?? 'active'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($user['last_login']): ?>
                                                        <?php echo date('M j, Y H:i', strtotime($user['last_login'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Never</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="actions-cell">
                                                    <button type="button" class="btn btn-danger btn-sm"
                                                        onclick="showResetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">
                                                        <i class="fas fa-key"></i> Reset Password
                                                    </button>
                                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this portal user?');">
                                                        <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                                        <button type="submit" name="delete_portal_user" class="btn btn-danger btn-sm">
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

                <!-- Add Plan Modal Form -->
                <div class="form-section" id="addPlanForm" style="display: none;">
                    <div class="form-header">
                        <h2 class="form-title">
                            <i class="fas fa-plus"></i> Add New Plan
                        </h2>
                        <button type="button" class="btn btn-warning" onclick="hidePlanForm()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                    <form method="POST" action="">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Provider *</label>
                                <select name="provider_id" class="form-select" required>
                                    <option value="">Select Provider</option>
                                    <?php foreach ($providers as $provider): ?>
                                        <option value="<?php echo $provider['id']; ?>">
                                            <?php echo htmlspecialchars($provider['provider_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Plan Name *</label>
                                <input type="text" name="plan_name" class="form-input" required>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Annual Limit (₱)</label>
                                <input type="number" name="annual_limit" class="form-input" step="0.01" placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Employee Premium (₱)</label>
                                <input type="number" name="premium_employee" class="form-input" step="0.01" placeholder="0.00">
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Dependent Premium (₱)</label>
                                <input type="number" name="premium_dependent" class="form-input" step="0.01" placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="add_plan" class="btn btn-success">
                                <i class="fas fa-plus"></i> Add Plan
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Add Document Modal Form -->
                <div class="form-section" id="addDocumentForm" style="display: none;">
                    <div class="form-header">
                        <h2 class="form-title">
                            <i class="fas fa-plus"></i> Add New Document
                        </h2>
                        <button type="button" class="btn btn-warning" onclick="hideDocumentForm()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Provider *</label>
                                <select name="provider_id" class="form-select" required>
                                    <option value="">Select Provider</option>
                                    <?php foreach ($providers as $provider): ?>
                                        <option value="<?php echo $provider['id']; ?>">
                                            <?php echo htmlspecialchars($provider['provider_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Document Name *</label>
                                <input type="text" name="document_name" class="form-input" required>
                            </div>
                        </div>

                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Document Type *</label>
                                <select name="document_type" class="form-select" required>
                                    <option value="">Select Type</option>
                                    <option value="policy">Policy Document</option>
                                    <option value="brochure">Brochure</option>
                                    <option value="claim_form">Claim Form</option>
                                    <option value="certificate">Certificate</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Document File *</label>
                                <input type="file" name="document_file" class="form-input" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                                <small class="text-muted">Accepted formats: PDF, DOC, DOCX, JPG, PNG</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-textarea" rows="3" placeholder="Optional description of the document"></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="add_document" class="btn btn-success">
                                <i class="fas fa-upload"></i> Upload Document
                            </button>
                        </div>
                    </form>
                </div>

                <!-- ===================== PLANS TAB ===================== -->
                <div id="plans" class="tab-content">
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-file-contract"></i> Plan Management
                        </h2>

                        <!-- Add New Plan Button -->
                        <div class="form-actions" style="margin-bottom: 1.5rem;">
                            <button type="button" class="btn btn-primary" onclick="showAddPlanForm()">
                                <i class="fas fa-plus"></i> Add New Plan
                            </button>
                        </div>

                        <!-- Plans Table -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Plan Name</th>
                                        <th>Provider</th>
                                        <th>Annual Limit</th>
                                        <th>Employee Premium</th>
                                        <th>Dependent Premium</th>
                                        <th>Status</th>
                                        <th class="no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($plans)): ?>
                                        <?php foreach ($plans as $plan): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($plan['plan_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($plan['provider_name'] ?? 'Unknown'); ?></td>
                                                <td>₱<?php echo number_format($plan['annual_limit'] ?? 0, 2); ?></td>
                                                <td>₱<?php echo number_format($plan['premium_employee'] ?? 0, 2); ?></td>
                                                <td>₱<?php echo number_format($plan['premium_dependent'] ?? 0, 2); ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($plan['status'] ?? 'active'); ?>">
                                                        <?php echo htmlspecialchars($plan['status'] ?? 'Active'); ?>
                                                    </span>
                                                </td>
                                                <td class="actions-cell">
                                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this plan?');">
                                                        <input type="hidden" name="id" value="<?php echo $plan['id']; ?>">
                                                        <button type="submit" name="delete_plan" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; padding: 2rem;">
                                                <i class="fas fa-file-contract" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                                <p>No plans found. <a href="#" onclick="showAddPlanForm()">Add your first plan</a>.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ===================== DOCUMENTS TAB ===================== -->
                <div id="documents" class="tab-content">
                    <div class="form-section">
                        <h2 class="form-title">
                            <i class="fas fa-file-alt"></i> Document Management
                        </h2>

                        <!-- Add New Document Button -->
                        <div class="form-actions" style="margin-bottom: 1.5rem;">
                            <button type="button" class="btn btn-primary" onclick="showAddDocumentForm()">
                                <i class="fas fa-plus"></i> Add New Document
                            </button>
                        </div>

                        <!-- Documents Table -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Document Name</th>
                                        <th>Provider</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Upload Date</th>
                                        <th class="no-print">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($documents)): ?>
                                        <?php foreach ($documents as $document): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($document['document_name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($document['provider_name'] ?? 'Unknown'); ?></td>
                                                <td>
                                                    <span class="status-badge" style="background: #e3f2fd; color: #1565c0;">
                                                        <?php echo htmlspecialchars($document['document_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo htmlspecialchars($document['description'] ?? 'No description'); ?></td>
                                                <td><?php echo date('M j, Y', strtotime($document['uploaded_at'] ?? 'now')); ?></td>
                                                <td class="actions-cell">
                                                    <a href="#" class="btn btn-info btn-sm" onclick="alert('Document viewing not implemented yet')">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                    <a href="#" class="btn btn-success btn-sm" onclick="alert('Document download not implemented yet')">
                                                        <i class="fas fa-download"></i> Download
                                                    </a>
                                                    <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this document?');">
                                                        <input type="hidden" name="id" value="<?php echo $document['id']; ?>">
                                                        <button type="submit" name="delete_document" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 2rem;">
                                                <i class="fas fa-file-alt" style="font-size: 3rem; color: #6c757d; margin-bottom: 1rem;"></i>
                                                <p>No documents found. <a href="#" onclick="showAddDocumentForm()">Add your first document</a>.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ===================== REPORTS TAB (Enhanced) ===================== -->
                <div id="reports" class="tab-content">
                    <!-- Print Header (only visible when printing) -->
                    <div class="print-header">
                        <h1>HR Provider Management Report</h1>
                        <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
                        <p>Report prepared by: <?php echo htmlspecialchars($hr_manager_name ?? 'HR Manager'); ?></p>
                    </div>

                    <div class="form-section">
                        <div class="form-header">
                            <h2 class="form-title">
                                <i class="fas fa-chart-bar"></i> Advanced Analytics
                            </h2>
                            <button type="button" class="btn btn-info" onclick="window.print()">
                                <i class="fas fa-print"></i> Print Report
                            </button>
                        </div>

                        <!-- Performance Metrics -->
                        <div class="stats-grid">
                            <div class="stat-card trend-up">
                                <div class="stat-value"><?php echo $stats['total_enrollments']; ?></div>
                                <div class="stat-label">Total Enrollments</div>
                                <span class="stat-trend"><i class="fas fa-arrow-up"></i> 12%</span>
                            </div>
                            <div class="stat-card trend-up">
                                <div class="stat-value"><?php echo $stats['active_providers']; ?></div>
                                <div class="stat-label">Active Providers</div>
                                <span class="stat-trend"><i class="fas fa-arrow-up"></i> 5%</span>
                            </div>
                            <div class="stat-card trend-down">
                                <div class="stat-value"><?php echo $stats['inactive_providers']; ?></div>
                                <div class="stat-label">Inactive Providers</div>
                                <span class="stat-trend"><i class="fas fa-arrow-down"></i> 2%</span>
                            </div>
                            <div class="stat-card">
                                <div class="stat-value"><?php echo calculateAveragePlansPerProviderPHP($plans, $providers); ?></div>
                                <div class="stat-label">Avg Plans/Provider</div>
                            </div>
                        </div>

                        <!-- Recent Activity Log -->
                        <div class="form-section">
                            <h2 class="form-title">
                                <i class="fas fa-history"></i> Recent Activity Log
                            </h2>
                            <div class="action-log">
                                <?php
                                // Fetch recent audit logs
                                try {
                                    $audit_stmt = $pdo->prepare("
                                    SELECT * FROM audit_logs 
                                    WHERE user_id = ? 
                                    ORDER BY timestamp DESC 
                                    LIMIT 10
                                ");
                                    $audit_stmt->execute([$hr_manager_id ?? 0]);
                                    $audit_logs = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);

                                    if (empty($audit_logs)) {
                                        echo '<div class="log-entry">No recent activity found.</div>';
                                    } else {
                                        foreach ($audit_logs as $log) {
                                            echo '<div class="log-entry">';
                                            echo '<strong>' . htmlspecialchars($log['action']) . '</strong><br>';
                                            echo '<span class="log-time">' . date('M j, Y H:i:s', strtotime($log['timestamp'])) . '</span>';
                                            echo '</div>';
                                        }
                                    }
                                } catch (PDOException $e) {
                                    echo '<div class="log-entry">Unable to load activity log.</div>';
                                }
                                ?>
                            </div>
                        </div>

                        <!-- Provider Health Dashboard -->
                        <div class="form-section">
                            <h2 class="form-title">
                                <i class="fas fa-heartbeat"></i> Provider Health Dashboard
                            </h2>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Provider</th>
                                            <th>Status</th>
                                            <th>Portal Access</th>
                                            <th>Plans</th>
                                            <th>Enrollments</th>
                                            <th>Health</th>
                                            <th>Action Needed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($providers as $provider) {
                                            // Calculate provider metrics
                                            $provider_plans = array_filter($plans, function ($p) use ($provider) {
                                                return isset($p['provider_id']) && $p['provider_id'] == $provider['id'];
                                            });

                                            $provider_enrollments = array_filter($enrollments, function ($e) use ($provider) {
                                                return isset($e['provider_name']) && $e['provider_name'] == $provider['provider_name'];
                                            });

                                            $plan_count = count($provider_plans);
                                            $enrollment_count = count($provider_enrollments);

                                            // Determine health status
                                            if ($provider['status'] === 'Active' && $plan_count > 0 && $enrollment_count > 0) {
                                                $health = 'Excellent';
                                                $health_class = 'health-excellent';
                                            } elseif ($provider['status'] === 'Active' && $plan_count > 0) {
                                                $health = 'Good';
                                                $health_class = 'health-good';
                                            } elseif ($provider['status'] === 'Active') {
                                                $health = 'Fair';
                                                $health_class = 'health-fair';
                                            } else {
                                                $health = 'Poor';
                                                $health_class = 'health-poor';
                                            }

                                            // Determine if action is needed
                                            $action_needed = '';
                                            if ($provider['status'] === 'Active' && $plan_count === 0) {
                                                $action_needed = '<span class="text-warning"><i class="fas fa-exclamation-triangle"></i> Add Plans</span>';
                                            } elseif (($provider['portal_status'] ?? '') === 'setup_pending') {
                                                $action_needed = '<span class="text-info"><i class="fas fa-cog"></i> Setup Portal</span>';
                                            } elseif ($provider['status'] === 'Inactive') {
                                                $action_needed = '<span class="text-danger"><i class="fas fa-ban"></i> Reactivate</span>';
                                            }

                                            echo '<tr>';
                                            echo '<td><strong>' . htmlspecialchars($provider['provider_name']) . '</strong></td>';
                                            echo '<td><span class="status-badge status-' . strtolower($provider['status']) . '">' . $provider['status'] . '</span></td>';
                                            echo '<td>' . htmlspecialchars($provider['portal_access'] ?? 'N/A') . '</td>';
                                            echo '<td>' . $plan_count . '</td>';
                                            echo '<td>' . $enrollment_count . '</td>';
                                            echo '<td><span class="health-indicator ' . $health_class . '"></span> ' . $health . '</td>';
                                            echo '<td>' . $action_needed . '</td>';
                                            echo '</tr>';
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Other tabs (plans, documents, enrollments) remain the same as your original code -->
                <!-- ... -->

            </div>
        </main>
    </div>

    <script>
        // ===================== ENHANCED JAVASCRIPT FUNCTIONS =====================

        // Password strength checker
        function checkPasswordStrength(password, type = 'add') {
            const strengthDiv = type === 'add' ? 'passwordStrength' : 'resetPasswordStrength';
            let strength = 'Weak';
            let strengthClass = 'strength-weak';

            if (password.length >= 12 && /[A-Z]/.test(password) && /[0-9]/.test(password) && /[^A-Za-z0-9]/.test(password)) {
                strength = 'Strong';
                strengthClass = 'strength-strong';
            } else if (password.length >= 8 && /[A-Z]/.test(password) && /[0-9]/.test(password)) {
                strength = 'Medium';
                strengthClass = 'strength-medium';
            }

            document.getElementById(strengthDiv).innerHTML = `<span class="${strengthClass}">${strength}</span>`;

            // Check password match
            if (type === 'add') {
                checkPasswordMatch();
            } else {
                checkResetPasswordMatch();
            }
        }

        function checkPasswordMatch() {
            const password = document.getElementById('passwordInput').value;
            const confirm = document.getElementById('confirmPasswordInput').value;
            const matchDiv = document.getElementById('passwordMatch');

            if (confirm === '') {
                matchDiv.innerHTML = '';
            } else if (password === confirm) {
                matchDiv.innerHTML = '<span style="color: #1cc88a;"><i class="fas fa-check"></i> Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span style="color: #e74a3b;"><i class="fas fa-times"></i> Passwords do not match</span>';
            }
        }

        function checkResetPasswordMatch() {
            const password = document.getElementById('newPasswordInput').value;
            const confirm = document.getElementById('newConfirmPasswordInput').value;
            const matchDiv = document.getElementById('resetPasswordMatch');

            if (confirm === '') {
                matchDiv.innerHTML = '';
            } else if (password === confirm) {
                matchDiv.innerHTML = '<span style="color: #1cc88a;"><i class="fas fa-check"></i> Passwords match</span>';
            } else {
                matchDiv.innerHTML = '<span style="color: #e74a3b;"><i class="fas fa-times"></i> Passwords do not match</span>';
            }
        }

        // Generate random password
        function generatePassword(type = 'add') {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
            let password = '';

            for (let i = 0; i < 12; i++) {
                password += chars.charAt(Math.floor(Math.random() * chars.length));
            }

            if (type === 'add') {
                document.getElementById('passwordInput').value = password;
                document.getElementById('confirmPasswordInput').value = password;
                checkPasswordStrength(password);
                checkPasswordMatch();
            } else {
                document.getElementById('newPasswordInput').value = password;
                document.getElementById('newConfirmPasswordInput').value = password;
                checkPasswordStrength(password, 'reset');
                checkResetPasswordMatch();
            }
        }

        // Provider selection functions
        function toggleAllProviders(checkbox) {
            const providerCheckboxes = document.querySelectorAll('.provider-checkbox');
            providerCheckboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
        }

        function selectAllProviders() {
            const providerCheckboxes = document.querySelectorAll('.provider-checkbox');
            providerCheckboxes.forEach(cb => {
                cb.checked = true;
            });
            document.getElementById('selectAll').checked = true;
        }

        function deselectAllProviders() {
            const providerCheckboxes = document.querySelectorAll('.provider-checkbox');
            providerCheckboxes.forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('selectAll').checked = false;
        }

        // Bulk actions
        function applyBulkAction() {
            const form = document.getElementById('bulkActionsForm');
            const bulkAction = form.bulk_action.value;
            const selectedProviders = Array.from(document.querySelectorAll('.provider-checkbox:checked')).map(cb => cb.value);

            if (!bulkAction) {
                alert('Please select a bulk action.');
                return;
            }

            if (selectedProviders.length === 0) {
                alert('Please select at least one provider.');
                return;
            }

            // Create hidden form for bulk action
            const hiddenForm = document.createElement('form');
            hiddenForm.method = 'POST';
            hiddenForm.action = '';

            selectedProviders.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'provider_ids[]';
                input.value = id;
                hiddenForm.appendChild(input);
            });

            if (bulkAction === 'activate') {
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'bulk_activate_providers';
                actionInput.value = '1';
                hiddenForm.appendChild(actionInput);
            } else if (bulkAction === 'deactivate') {
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'bulk_deactivate_providers';
                actionInput.value = '1';
                hiddenForm.appendChild(actionInput);
            }

            document.body.appendChild(hiddenForm);

            if (confirm(`Are you sure you want to ${bulkAction} ${selectedProviders.length} provider(s)?`)) {
                showLoading();
                hiddenForm.submit();
            } else {
                document.body.removeChild(hiddenForm);
            }
        }

        // Portal user management
        function showAddPortalUserForm() {
            document.getElementById('addPortalUserForm').style.display = 'block';
            window.scrollTo({
                top: document.getElementById('addPortalUserForm').offsetTop - 100,
                behavior: 'smooth'
            });
        }

        function hidePortalUserForm() {
            document.getElementById('addPortalUserForm').style.display = 'none';
        }

        function showCreatePortalUser(providerId, providerName) {
            document.getElementById('portalUserProviderId').value = providerId;
            document.getElementById('portalUserProviderName').value = providerName;

            // Switch to portal users tab
            document.getElementById('portalUsersTab').click();

            // Show add form
            setTimeout(() => {
                showAddPortalUserForm();
            }, 300);
        }

        function showResetPassword(userId, username) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('resetUsername').value = username;

            document.getElementById('resetPasswordForm').style.display = 'block';
            window.scrollTo({
                top: document.getElementById('resetPasswordForm').offsetTop - 100,
                behavior: 'smooth'
            });
        }

        function hideResetPasswordForm() {
            document.getElementById('resetPasswordForm').style.display = 'none';
        }

        // Plan management
        function showAddPlanForm() {
            document.getElementById('addPlanForm').style.display = 'block';
            window.scrollTo({
                top: document.getElementById('addPlanForm').offsetTop - 100,
                behavior: 'smooth'
            });
        }

        function hidePlanForm() {
            document.getElementById('addPlanForm').style.display = 'none';
        }

        // Document management
        function showAddDocumentForm() {
            document.getElementById('addDocumentForm').style.display = 'block';
            window.scrollTo({
                top: document.getElementById('addDocumentForm').offsetTop - 100,
                behavior: 'smooth'
            });
        }

        function hideDocumentForm() {
            document.getElementById('addDocumentForm').style.display = 'none';
        }

        // Loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // Tab functionality (enhanced with URL hash)
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');

            // Check for hash in URL
            const hash = window.location.hash.substring(1);
            if (hash) {
                const targetTab = document.querySelector(`[data-tab="${hash}"]`);
                if (targetTab) {
                    switchTab(hash);
                }
            }

            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');

                    // Remove active class from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));

                    // Add active class to current tab and content
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');

                    // Update URL hash
                    window.history.pushState(null, null, `#${tabId}`);
                });
            });

            // Password match check on load
            document.getElementById('passwordInput')?.addEventListener('keyup', checkPasswordMatch);
            document.getElementById('confirmPasswordInput')?.addEventListener('keyup', checkPasswordMatch);
            document.getElementById('newPasswordInput')?.addEventListener('keyup', checkResetPasswordMatch);
            document.getElementById('newConfirmPasswordInput')?.addEventListener('keyup', checkResetPasswordMatch);
        });

        function switchTab(tabId) {
            const tab = document.querySelector(`[data-tab="${tabId}"]`);
            if (tab) {
                tab.click();
            }
        }

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    // Check for required fields
                    const requiredInputs = form.querySelectorAll('[required]');
                    let isValid = true;

                    requiredInputs.forEach(input => {
                        if (!input.value.trim()) {
                            isValid = false;
                            input.style.borderColor = '#e74a3b';
                        } else {
                            input.style.borderColor = '';
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    }
                });
            });
        });

        // Auto-refresh data every 5 minutes
        setInterval(() => {
            if (document.visibilityState === 'visible') {
                window.location.reload();
            }
        }, 300000); // 5 minutes
    </script>

    <script>
        // Helper function for calculations
        function calculateAveragePlansPerProvider(plans, providers) {
            if (providers.length === 0) return '0.0';

            const plansByProvider = {};
            plans.forEach(plan => {
                if (plan.provider_id) {
                    if (!plansByProvider[plan.provider_id]) {
                        plansByProvider[plan.provider_id] = 0;
                    }
                    plansByProvider[plan.provider_id]++;
                }
            });

            const totalPlans = Object.values(plansByProvider).reduce((a, b) => a + b, 0);
            return (totalPlans / providers.length).toFixed(1);
        }
    </script>
</body>

</html>
<?php ob_end_flush(); ?>